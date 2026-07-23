<?php
defined('ABSPATH') || exit;

class WSN_Tracking
{
    public static function init(): void
    {
        add_action('add_meta_boxes', [__CLASS__, 'add_metabox']);
        // priority 5 — לפני שינוי הסטטוס באותה שמירה, כדי ש"הדבק מעקב + סמן כנשלח + עדכן" יעבוד בקליק אחד
        add_action('woocommerce_process_shop_order_meta', [__CLASS__, 'save_metabox'], 5, 1);
        // עדכון מספר מעקב → טיוטת הודעה ללקוח (עובד גם לשדה הידני וגם למפתח מוגדר)
        add_action('woocommerce_after_order_object_save', [__CLASS__, 'maybe_notify_tracking'], 20, 1);
    }

    /**
     * מזהה מספר מעקב (חדש או שהשתנה) ובונה טיוטת הודעה לאישור. אין שליחה
     * אוטומטית. מניעת כפילות דרך event_key ייחודי לפי מספר המעקב — לכן אין
     * צורך לשמור דגל בהזמנה (וגם אין recursion של $order->save()).
     */
    public static function maybe_notify_tracking($order): void
    {
        if (!$order instanceof WC_Order) {
            return;
        }
        $num = trim((string) (self::get($order)['number'] ?? ''));
        if ($num === '') {
            return;
        }
        // הגדרת "מתי לבנות": ריק = מיד; אחרת רק כשההזמנה בסטטוס שנבחר. ההוק רץ
        // גם בשינויי סטטוס, ולכן נתפסים שני הכיוונים (מעקב לפני/אחרי הסטטוס).
        $required = (string) WSN_Settings::get('tracking_notify_status');
        if ($required !== '' && 'wc-' . $order->get_status() !== $required) {
            return;
        }
        $tpl = WSN_Templates::get('tracking_update');
        if (!$tpl) {
            return;
        }
        $phone = WSN_Phone::to_e164($order->get_billing_phone());
        if (!$phone || WSN_Contacts::is_unsubscribed($phone)) {
            return;
        }
        $body = WSN_Templates::render(WSN_Templates::pick_variant($tpl), $order);
        $id = WSN_Outbox::enqueue([
            'kind'      => 'status',
            'order_id'  => $order->get_id(),
            'phone'     => $phone,
            'body'      => $body,
            'status'    => 'draft',
            // ייחודי למספר המעקב — מספר זהה לא ייצור טיוטה כפולה; מספר חדש כן
            'event_key' => 'order-' . $order->get_id() . '-track-' . md5($num),
        ]);
        if ($id) {
            $order->add_order_note('וואטסאפ: הוכנה טיוטת עדכון מספר מעקב (' . $num . ') — ממתינה לאישור');
        }
    }

    /** מחזיר ['number' => ..., 'url' => ...] — ריקים אם אין */
    public static function get(WC_Order $order): array
    {
        // 1. השדה הידני שלנו
        $manual = trim((string) $order->get_meta('_wsn_tracking_number'));
        if ($manual !== '') {
            return ['number' => $manual, 'url' => trim((string) $order->get_meta('_wsn_tracking_url'))];
        }

        // 2. CSLFW / קרגו — המבנה הוא [ מספר_מעקב => [driver_name, status, ...] ],
        // כלומר מספר המעקב הוא *המפתח* של הרשומה. מזוהה אוטומטית, בלי הגדרה.
        $cslfw = self::extract_cslfw($order->get_meta('cslfw_shipping'));
        if ($cslfw !== '') {
            return ['number' => $cslfw, 'url' => ''];
        }

        // 3. meta key מוגדר בהגדרות
        $key = trim((string) WSN_Settings::get('tracking_meta_key'));
        if ($key === '') {
            return ['number' => '', 'url' => ''];
        }
        $val = $order->get_meta($key);

        // אם המפתח המוגדר מצביע גם הוא על מבנה מסוג CSLFW
        if ($key === 'cslfw_shipping') {
            return ['number' => self::extract_cslfw($val), 'url' => ''];
        }
        // תמיכה מיוחדת ב-WooCommerce Shipment Tracking (מערך רשומות)
        if ($key === '_wc_shipment_tracking_items' && is_array($val) && $val) {
            $last = end($val);
            return [
                'number' => (string) ($last['tracking_number'] ?? ''),
                'url'    => (string) ($last['custom_tracking_link'] ?? ''),
            ];
        }
        if (is_scalar($val) && trim((string) $val) !== '') {
            return ['number' => trim((string) $val), 'url' => ''];
        }
        return ['number' => '', 'url' => ''];
    }

    /** מחלץ מספר מעקב ממבנה CSLFW: המפתח של הרשומה האחרונה (המספר שנוצר לאחרונה) */
    private static function extract_cslfw($val): string
    {
        if (!is_array($val) || !$val) {
            return '';
        }
        $keys = array_keys($val);
        $num = end($keys);
        return $num !== false ? trim((string) $num) : '';
    }

    /** האם ההזמנה באיסוף עצמי (לפי method_id של שיטת המשלוח) */
    public static function is_pickup(WC_Order $order): bool
    {
        foreach ($order->get_shipping_methods() as $sm) {
            if ($sm->get_method_id() === 'local_pickup') {
                return true;
            }
        }
        return false;
    }

    /** תווית סוג משלוח לשימוש בתבניות: "איסוף עצמי" / "משלוח" */
    public static function shipping_type_label(WC_Order $order): string
    {
        return self::is_pickup($order) ? 'איסוף עצמי' : 'משלוח';
    }

    public static function add_metabox(): void
    {
        $screen = function_exists('wc_get_page_screen_id') ? wc_get_page_screen_id('shop-order') : 'shop_order';
        add_meta_box('wsn_tracking', 'וואטסאפ — מספר מעקב למשלוח', [__CLASS__, 'render_metabox'], $screen, 'side', 'default');
    }

    public static function render_metabox($post_or_order): void
    {
        $order = $post_or_order instanceof WC_Order ? $post_or_order : wc_get_order($post_or_order->ID ?? 0);
        if (!$order) {
            return;
        }
        wp_nonce_field('wsn_tracking_save', 'wsn_tracking_nonce');
        $num = (string) $order->get_meta('_wsn_tracking_number');
        $url = (string) $order->get_meta('_wsn_tracking_url');
        ?>
        <div dir="rtl">
            <p><label>מספר מעקב:<br>
                <input type="text" name="wsn_tracking_number" value="<?php echo esc_attr($num); ?>" style="width:100%" dir="ltr">
            </label></p>
            <p><label>קישור מעקב (לא חובה):<br>
                <input type="url" name="wsn_tracking_url" value="<?php echo esc_attr($url); ?>" style="width:100%" dir="ltr">
            </label></p>
            <p class="description">נשמר לפני החלת שינוי סטטוס — אפשר להדביק מעקב, לבחור סטטוס "נשלח" וללחוץ עדכון בבת אחת. זמין בתבניות כ-{tracking_number} ו-{tracking_url}.</p>
        </div>
        <?php
    }

    public static function save_metabox($order_id): void
    {
        if (!isset($_POST['wsn_tracking_nonce'])
            || !wp_verify_nonce(sanitize_key($_POST['wsn_tracking_nonce']), 'wsn_tracking_save')
            || !current_user_can('edit_shop_orders')) {
            return;
        }
        $order = wc_get_order($order_id);
        if (!$order) {
            return;
        }
        $order->update_meta_data('_wsn_tracking_number', sanitize_text_field(wp_unslash($_POST['wsn_tracking_number'] ?? '')));
        $order->update_meta_data('_wsn_tracking_url', esc_url_raw(wp_unslash($_POST['wsn_tracking_url'] ?? '')));
        $order->save();
    }
}
