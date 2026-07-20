<?php
defined('ABSPATH') || exit;

/**
 * שינויי פריטים בהזמנה — שליחה מפורשת בלבד.
 * עריכת הזמנה היא רצף שמירות AJAX חלקיות, לכן אין שליחה אוטומטית על diff:
 * השמירה רק מדליקה דגל, וההודעה נשלחת מה-metabox בלחיצת מנהל.
 */
class WSN_Order_Items
{
    public static function init(): void
    {
        add_action('woocommerce_saved_order_items', [__CLASS__, 'flag_dirty'], 10, 2);
        add_action('add_meta_boxes', [__CLASS__, 'add_metabox']);
        add_action('wp_ajax_wsn_items_notify', [__CLASS__, 'ajax_send']);
    }

    public static function flag_dirty(int $order_id, $items): void
    {
        $order = wc_get_order($order_id);
        if ($order && self::diff($order)) {
            $order->update_meta_data('_wsn_items_dirty', 'yes');
            $order->save();
        }
    }

    /** diff מול ה-snapshot: [removed => [...], added => [...]] או null אם אין שינוי */
    public static function diff(WC_Order $order): ?array
    {
        $snapshot = json_decode((string) $order->get_meta('_wsn_items_snapshot'), true);
        if (!is_array($snapshot) || !$snapshot) {
            return null;
        }
        $current = [];
        foreach ($order->get_items() as $item) {
            $key = $item->get_product_id() . ':' . $item->get_variation_id();
            $current[$key] = ['name' => $item->get_name(), 'qty' => $item->get_quantity()];
        }
        $removed = $added = [];
        $snap_keys = [];
        foreach ($snapshot as $s) {
            $key = $s['product_id'] . ':' . $s['variation_id'];
            $snap_keys[$key] = $s;
            if (!isset($current[$key])) {
                $removed[] = $s['name'];
            } elseif ($current[$key]['qty'] < $s['qty']) {
                $removed[] = $s['name'] . ' (הכמות ירדה מ-' . $s['qty'] . ' ל-' . $current[$key]['qty'] . ')';
            }
        }
        foreach ($current as $key => $c) {
            if (!isset($snap_keys[$key])) {
                $added[] = $c['name'];
            }
        }
        if (!$removed && !$added) {
            return null;
        }
        return ['removed' => $removed, 'added' => $added];
    }

    public static function add_metabox(): void
    {
        $screen = function_exists('wc_get_page_screen_id') ? wc_get_page_screen_id('shop-order') : 'shop_order';
        add_meta_box('wsn_items_notify', 'וואטסאפ — עדכון פריטים ללקוח', [__CLASS__, 'render_metabox'], $screen, 'normal', 'default');
    }

    public static function render_metabox($post_or_order): void
    {
        $order = $post_or_order instanceof WC_Order ? $post_or_order : wc_get_order($post_or_order->ID ?? 0);
        if (!$order || !current_user_can('manage_wa_notify')) {
            echo '<p>אין הרשאה.</p>';
            return;
        }
        $phone = WSN_Phone::to_e164($order->get_billing_phone());
        if (!$phone) {
            echo '<p>להזמנה אין מספר טלפון תקין — אי אפשר לשלוח.</p>';
            return;
        }

        $diff = self::diff($order);
        $suggested = '';
        if ($diff) {
            $removed = implode(', ', $diff['removed']);
            $added   = implode(', ', $diff['added']);
            if ($diff['removed'] && $diff['added']) {
                $tpl = WSN_Templates::get('item_replaced');
                $suggested = $tpl ? WSN_Templates::render(WSN_Templates::pick_variant($tpl), $order,
                    ['removed_item' => $removed, 'new_item' => $added]) : '';
            } elseif ($diff['removed']) {
                $tpl = WSN_Templates::get('item_removed');
                $suggested = $tpl ? WSN_Templates::render(WSN_Templates::pick_variant($tpl), $order,
                    ['removed_item' => $removed]) : '';
            }
        }
        ?>
        <div dir="rtl">
            <?php if ($diff): ?>
                <p><b>זוהה שינוי מול ההזמנה המקורית:</b><br>
                <?php if ($diff['removed']): ?>הוסרו/פחתו: <?php echo esc_html(implode(', ', $diff['removed'])); ?><br><?php endif; ?>
                <?php if ($diff['added']): ?>נוספו: <?php echo esc_html(implode(', ', $diff['added'])); ?><?php endif; ?></p>
            <?php else: ?>
                <p>אין שינוי מזוהה מול ההזמנה המקורית. אפשר לשלוח הודעה חופשית ללקוח:</p>
            <?php endif; ?>
            <textarea id="wsn-items-msg" rows="4" style="width:100%"><?php echo esc_textarea($suggested); ?></textarea>
            <p>
                <button type="button" class="button button-primary" id="wsn-items-send">שלח ללקוח בוואטסאפ</button>
                <span id="wsn-items-result"></span>
            </p>
            <script>
            document.getElementById('wsn-items-send').addEventListener('click', function () {
                var btn = this, out = document.getElementById('wsn-items-result');
                var msg = document.getElementById('wsn-items-msg').value.trim();
                if (!msg) { out.textContent = 'אין טקסט הודעה'; return; }
                btn.disabled = true; out.textContent = 'שולח לתור…';
                var body = new URLSearchParams({
                    action: 'wsn_items_notify',
                    _wpnonce: '<?php echo esc_js(wp_create_nonce('wsn_items_notify_' . $order->get_id())); ?>',
                    order_id: '<?php echo (int) $order->get_id(); ?>',
                    message: msg
                });
                fetch(ajaxurl, { method: 'POST', body: body })
                    .then(r => r.json())
                    .then(d => { out.textContent = d.success ? 'נוסף לתור ✔ יישלח בקצב אנושי' : ('שגיאה: ' + (d.data || '')); btn.disabled = false; })
                    .catch(e => { out.textContent = 'שגיאה: ' + e.message; btn.disabled = false; });
            });
            </script>
        </div>
        <?php
    }

    public static function ajax_send(): void
    {
        $order_id = (int) ($_POST['order_id'] ?? 0);
        check_ajax_referer('wsn_items_notify_' . $order_id);
        if (!current_user_can('manage_wa_notify')) {
            wp_send_json_error('אין הרשאה', 403);
        }
        $order = wc_get_order($order_id);
        $message = sanitize_textarea_field(wp_unslash($_POST['message'] ?? ''));
        if (!$order || $message === '') {
            wp_send_json_error('נתונים חסרים');
        }
        $phone = WSN_Phone::to_e164($order->get_billing_phone());
        if (!$phone) {
            wp_send_json_error('אין מספר טלפון תקין להזמנה');
        }
        if (WSN_Contacts::is_unsubscribed($phone)) {
            wp_send_json_error('הלקוח ביקש להסיר אותו מרשימת התפוצה');
        }
        $id = WSN_Outbox::enqueue([
            'kind'      => 'item_change',
            'order_id'  => $order_id,
            'phone'     => $phone,
            'body'      => $message,
            'event_key' => 'order-' . $order_id . '-items-' . md5($message),
        ]);
        if (!$id) {
            wp_send_json_error('ההודעה כבר בתור (טקסט זהה)');
        }
        $order->delete_meta_data('_wsn_items_dirty');
        $order->add_order_note('וואטסאפ: נוספה לתור הודעת עדכון פריטים');
        $order->save();
        wp_send_json_success();
    }
}
