<?php
defined('ABSPATH') || exit;

/**
 * שינויי פריטים בהזמנה — שליחה מפורשת בלבד.
 * עריכת הזמנה היא רצף שמירות AJAX חלקיות, לכן אין שליחה אוטומטית על diff:
 * השמירה רק מדליקה דגל, וההודעה נשלחת מה-metabox בלחיצת מנהל.
 */
class WSN_Order_Items
{
    /** מפתח ה-meta ששומר את מצב הפריטים האחרון הידוע (בסיס להשוואה) */
    const STATE_META = '_wsn_items_state';

    public static function init(): void
    {
        add_action('woocommerce_saved_order_items', [__CLASS__, 'flag_dirty'], 10, 2);
        add_action('add_meta_boxes', [__CLASS__, 'add_metabox']);
        add_action('wp_ajax_wsn_items_notify', [__CLASS__, 'ajax_send']);

        // מעקב תנועות פריטים (רק בעריכה בניהול, לא ביצירת ההזמנה מהחנות)
        add_action('woocommerce_new_order_item', [__CLASS__, 'on_new_item'], 10, 3);
        add_action('woocommerce_before_delete_order_item', [__CLASS__, 'on_before_delete_item'], 10, 1);
        add_action('woocommerce_saved_order_items', [__CLASS__, 'on_saved_items'], 20, 2);

        add_action('wp_ajax_wsn_pending_item_events', [__CLASS__, 'ajax_pending_events']);
        add_action('wp_ajax_wsn_save_item_reasons', [__CLASS__, 'ajax_save_reasons']);
        add_action('wp_ajax_wsn_compose_changes', [__CLASS__, 'ajax_compose_changes']);
        add_action('wp_ajax_wsn_queue_changes', [__CLASS__, 'ajax_queue_changes']);
    }

    /** מצב הפריטים הנוכחי כפי שהוא בהזמנה: item_id => פרטים */
    private static function snapshot_state(WC_Order $order): array
    {
        $state = [];
        foreach ($order->get_items() as $item_id => $item) {
            $state[(string) $item_id] = [
                'name' => $item->get_name(),
                'qty'  => (int) $item->get_quantity(),
                'pid'  => (int) $item->get_product_id(),
                'vid'  => (int) $item->get_variation_id(),
            ];
        }
        return $state;
    }

    private static function get_state(WC_Order $order): ?array
    {
        $raw = $order->get_meta(self::STATE_META);
        if ($raw === '' || $raw === null) {
            return null; // מעקב עוד לא אותחל להזמנה הזו
        }
        $decoded = is_array($raw) ? $raw : json_decode((string) $raw, true);
        return is_array($decoded) ? $decoded : null;
    }

    private static function save_state(WC_Order $order, array $state): void
    {
        $order->update_meta_data(self::STATE_META, wp_json_encode($state, JSON_UNESCAPED_UNICODE));
        $order->save();
    }

    /**
     * מאתחל מעקב אם עוד לא אותחל, בלי לרשום תנועות — כדי שהפריטים הקיימים
     * של הזמנה ותיקה לא ייחשבו כ"נוספו עכשיו".
     */
    public static function ensure_state(WC_Order $order): void
    {
        if (self::get_state($order) === null) {
            self::save_state($order, self::snapshot_state($order));
        }
    }

    /** רושמים תנועות רק בעריכה מהניהול, ורק אחרי שהמעקב אותחל להזמנה */
    private static function should_track(WC_Order $order): bool
    {
        return is_admin() && !wp_doing_cron() && self::get_state($order) !== null;
    }

    public static function on_new_item($item_id, $item, $order_id): void
    {
        if (!$item instanceof WC_Order_Item_Product) {
            return; // משלוח/עמלה — לא פריט מוצר
        }
        $order = wc_get_order($order_id);
        if (!$order instanceof WC_Order || !self::should_track($order)) {
            return;
        }
        $state = self::get_state($order) ?: [];
        if (isset($state[(string) $item_id])) {
            return; // כבר מוכר — לא הוספה חדשה
        }
        WSN_Item_Events::record([
            'order_id'      => $order->get_id(),
            'order_item_id' => $item_id,
            'event_type'    => WSN_Item_Events::TYPE_ADDED,
            'product_id'    => $item->get_product_id(),
            'variation_id'  => $item->get_variation_id(),
            'item_name'     => $item->get_name(),
            'qty_after'     => (int) $item->get_quantity(),
        ]);
        $state[(string) $item_id] = [
            'name' => $item->get_name(),
            'qty'  => (int) $item->get_quantity(),
            'pid'  => (int) $item->get_product_id(),
            'vid'  => (int) $item->get_variation_id(),
        ];
        self::save_state($order, $state);
    }

    public static function on_before_delete_item($item_id): void
    {
        $item = WC_Order_Factory::get_order_item($item_id);
        if (!$item instanceof WC_Order_Item_Product) {
            return;
        }
        $order = wc_get_order($item->get_order_id());
        if (!$order instanceof WC_Order || !self::should_track($order)) {
            return;
        }
        // הפרטים נלקחים לפני המחיקה — אחריה הם כבר לא יהיו זמינים
        WSN_Item_Events::record([
            'order_id'      => $order->get_id(),
            'order_item_id' => $item_id,
            'event_type'    => WSN_Item_Events::TYPE_REMOVED,
            'product_id'    => $item->get_product_id(),
            'variation_id'  => $item->get_variation_id(),
            'item_name'     => $item->get_name(),
            'qty_before'    => (int) $item->get_quantity(),
        ]);
        $state = self::get_state($order) ?: [];
        unset($state[(string) $item_id]);
        self::save_state($order, $state);
    }

    /** שינויי כמות: משווים את המצב הנוכחי למצב האחרון הידוע */
    public static function on_saved_items($order_id, $items): void
    {
        $order = wc_get_order($order_id);
        if (!$order instanceof WC_Order || !self::should_track($order)) {
            return;
        }
        $prev = self::get_state($order) ?: [];
        $now  = self::snapshot_state($order);

        foreach ($now as $item_id => $cur) {
            $before = $prev[$item_id]['qty'] ?? null;
            if ($before !== null && (int) $before !== (int) $cur['qty']) {
                WSN_Item_Events::record([
                    'order_id'      => $order->get_id(),
                    'order_item_id' => (int) $item_id,
                    'event_type'    => WSN_Item_Events::TYPE_QTY,
                    'product_id'    => $cur['pid'],
                    'variation_id'  => $cur['vid'],
                    'item_name'     => $cur['name'],
                    'qty_before'    => (int) $before,
                    'qty_after'     => (int) $cur['qty'],
                ]);
            }
        }
        self::save_state($order, $now);
    }

    public static function ajax_pending_events(): void
    {
        $order_id = (int) ($_POST['order_id'] ?? 0);
        check_ajax_referer('wsn_item_events_' . $order_id);
        if (!current_user_can('manage_wa_notify')) {
            wp_send_json_error('אין הרשאה', 403);
        }
        $pending = WSN_Item_Events::pending($order_id);
        $out = [];
        foreach ($pending as $e) {
            $out[] = [
                'id'          => (int) $e['id'],
                'type'        => $e['event_type'],
                'description' => WSN_Item_Events::describe($e),
                'reasons'     => WSN_Item_Events::reasons($e['event_type']),
            ];
        }
        wp_send_json_success(['events' => $out]);
    }

    /** קורא אירועים לפי מזהים, ורק כאלה ששייכים להזמנה הזו */
    private static function events_for(int $order_id, array $ids): array
    {
        $out = [];
        foreach (array_map('intval', $ids) as $id) {
            $e = WSN_Item_Events::get($id);
            if ($e && (int) $e['order_id'] === $order_id) {
                $out[] = $e;
            }
        }
        return $out;
    }

    /** בונה את נוסח ההודעה (או כמה נוסחים) בלי לשלוח — לתצוגה מקדימה */
    public static function ajax_compose_changes(): void
    {
        $order_id = (int) ($_POST['order_id'] ?? 0);
        check_ajax_referer('wsn_item_events_' . $order_id);
        if (!current_user_can('manage_wa_notify')) {
            wp_send_json_error('אין הרשאה', 403);
        }
        $order = wc_get_order($order_id);
        if (!$order instanceof WC_Order) {
            wp_send_json_error('הזמנה לא נמצאה');
        }
        $events = self::events_for($order_id, (array) ($_POST['event_ids'] ?? []));
        if (!$events) {
            wp_send_json_error('לא נבחרו שינויים');
        }
        $separate = ($_POST['mode'] ?? 'combined') === 'separate';

        $out = [];
        if ($separate) {
            foreach ($events as $e) {
                $body = WSN_Change_Composer::compose($order, [$e]);
                if (trim($body) !== '') {
                    $out[] = ['ids' => [(int) $e['id']], 'body' => $body];
                }
            }
        } else {
            $body = WSN_Change_Composer::compose($order, $events);
            if (trim($body) !== '') {
                $out[] = ['ids' => array_map(static fn($e) => (int) $e['id'], $events), 'body' => $body];
            }
        }
        if (!$out) {
            wp_send_json_error('לא נוצר נוסח — בדוק שהתבניות המתאימות מופעלות במסך התבניות');
        }
        wp_send_json_success(['messages' => $out]);
    }

    /** מכניס לתור את הנוסחים (אחרי שהמנהל אולי ערך אותם) */
    public static function ajax_queue_changes(): void
    {
        $order_id = (int) ($_POST['order_id'] ?? 0);
        check_ajax_referer('wsn_item_events_' . $order_id);
        if (!current_user_can('manage_wa_notify')) {
            wp_send_json_error('אין הרשאה', 403);
        }
        $order = wc_get_order($order_id);
        if (!$order instanceof WC_Order) {
            wp_send_json_error('הזמנה לא נמצאה');
        }
        $messages = (array) ($_POST['messages'] ?? []);
        $queued = 0;
        foreach ($messages as $m) {
            $ids  = array_map('intval', (array) ($m['ids'] ?? []));
            $body = sanitize_textarea_field(wp_unslash($m['body'] ?? ''));
            $events = self::events_for($order_id, $ids);
            if (!$events || trim($body) === '') {
                continue;
            }
            if (WSN_Change_Composer::queue($order, $events, $body)) {
                $queued++;
            }
            // שמירת הנוסח כתבנית קבועה לסוג הזה (רק כשכל השינויים מאותו סוג —
            // אחרת לא ברור לאיזה סוג הנוסח שייך)
            if (!empty($m['save_template'])) {
                $types = array_unique(array_map(
                    static fn($e) => WSN_Change_Composer::type_for_event($e['event_type']),
                    $events
                ));
                $target = count($types) === 1 ? reset($types) : 'order_changes';
                WSN_Change_Composer::save_template(
                    $target,
                    WSN_Change_Composer::templatize($body, $order, $events)
                );
            }
        }
        if (!$queued) {
            wp_send_json_error('שום הודעה לא נוספה לתור (ייתכן שהלקוח הוסר מרשימת התפוצה או שאין טלפון תקין)');
        }
        $order->add_order_note(sprintf('וואטסאפ: %d הודעות על שינויים נוספו לתור', $queued));
        wp_send_json_success(['queued' => $queued]);
    }

    public static function ajax_save_reasons(): void
    {
        $order_id = (int) ($_POST['order_id'] ?? 0);
        check_ajax_referer('wsn_item_events_' . $order_id);
        if (!current_user_can('manage_wa_notify')) {
            wp_send_json_error('אין הרשאה', 403);
        }
        $reasons = (array) ($_POST['reasons'] ?? []);
        $saved = 0;
        $ids = [];
        foreach ($reasons as $event_id => $r) {
            $code = sanitize_key($r['code'] ?? '');
            $text = sanitize_text_field(wp_unslash($r['text'] ?? ''));
            if ($code !== '' && WSN_Item_Events::set_reason((int) $event_id, $code, $text)) {
                $saved++;
                $ids[] = (int) $event_id;
            }
        }

        // שליחה אוטומטית — רק לסוגים שסומנו כאוטומטיים בהגדרות, ורק עכשיו
        // (אחרי בחירת הסיבה) כדי שההודעה תוכל לכלול אותה.
        $auto_queued = 0;
        $order = wc_get_order($order_id);
        if ($order instanceof WC_Order && $ids) {
            $auto = [];
            foreach (self::events_for($order_id, $ids) as $e) {
                if ((int) $e['notified'] === 0
                    && WSN_Change_Composer::is_auto(WSN_Change_Composer::type_for_event($e['event_type']))) {
                    $auto[] = $e;
                }
            }
            if ($auto && WSN_Change_Composer::queue($order, $auto)) {
                $auto_queued = count($auto);
                $order->add_order_note('וואטסאפ: הודעה על שינויים נוספה לתור אוטומטית');
            }
        }
        wp_send_json_success(['saved' => $saved, 'auto_queued' => $auto_queued]);
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
        add_meta_box('wsn_item_events', 'וואטסאפ — תנועות פריטים', [__CLASS__, 'render_events_metabox'], $screen, 'normal', 'default');
    }

    /** יומן התנועות של ההזמנה + עוגן למודאל הסיבות */
    public static function render_events_metabox($post_or_order): void
    {
        $order = $post_or_order instanceof WC_Order ? $post_or_order : wc_get_order($post_or_order->ID ?? 0);
        if (!$order instanceof WC_Order || !current_user_can('manage_wa_notify')) {
            return;
        }
        // אתחול שקט: פריטים קיימים לא נרשמים כ"נוספו עכשיו"
        self::ensure_state($order);

        $order_id = $order->get_id();
        $events = WSN_Item_Events::for_order($order_id);
        $unnotified = WSN_Item_Events::unnotified($order_id);
        ?>
        <div class="wsn wsn-events" dir="rtl"
             data-order-id="<?php echo (int) $order_id; ?>"
             data-nonce="<?php echo esc_attr(wp_create_nonce('wsn_item_events_' . $order_id)); ?>">

            <?php if ($unnotified): ?>
                <div class="wsn-compose">
                    <b>שינויים שטרם דווחו ללקוח</b>
                    <ul class="wsn-compose-list">
                        <?php foreach ($unnotified as $e): ?>
                            <li>
                                <label>
                                    <input type="checkbox" class="wsn-ev-check" value="<?php echo (int) $e['id']; ?>" checked>
                                    <?php echo esc_html(WSN_Change_Composer::line($e)); ?>
                                </label>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                    <p>
                        <label><input type="radio" name="wsn_compose_mode" value="combined" checked> הודעה אחת מאוחדת</label>
                        <label><input type="radio" name="wsn_compose_mode" value="separate"> הודעה נפרדת לכל שינוי</label>
                    </p>
                    <p>
                        <button type="button" class="button wsn-compose-build">בנה הודעה</button>
                        <span class="wsn-compose-msg description"></span>
                    </p>
                    <div class="wsn-compose-preview" hidden>
                        <p class="description">אפשר לערוך את הנוסח לפני השליחה:</p>
                        <div class="wsn-compose-bodies"></div>
                        <p><label><input type="checkbox" class="wsn-save-template"> שמור את הנוסח הזה כנוסח הקבוע לסוג השינוי</label>
                        <span class="description">שם הלקוח ומספר ההזמנה יוחלפו אוטומטית בשדות, כדי שהנוסח יתאים לכל הזמנה.</span></p>
                        <button type="button" class="button button-primary wsn-compose-send">הוסף לתור ושלח ללקוח</button>
                    </div>
                </div>
            <?php endif; ?>

            <?php if (!$events): ?>
                <p class="description">אין עדיין תנועות. שינוי כמות, הוספה או הסרה של פריט יירשמו כאן אוטומטית.</p>
            <?php else: ?>
                <table class="widefat striped">
                    <thead><tr><th>מתי</th><th>תנועה</th><th>סיבה</th><th>מי</th></tr></thead>
                    <tbody>
                    <?php foreach ($events as $e):
                        $user = $e['user_id'] ? get_userdata((int) $e['user_id']) : null;
                        $reason = WSN_Item_Events::reason_label($e);
                        ?>
                        <tr>
                            <td><?php echo esc_html(mysql2date('d/m H:i', $e['created_at'])); ?></td>
                            <td><?php echo esc_html(WSN_Item_Events::describe($e)); ?></td>
                            <td>
                                <?php if ($reason !== ''): ?>
                                    <span class="wsn-pill wsn-pill-order"><?php echo esc_html($reason); ?></span>
                                <?php else: ?>
                                    <span class="wsn-pill wsn-pill-queued">ממתין לסיבה</span>
                                <?php endif; ?>
                            </td>
                            <td><?php echo esc_html($user ? $user->display_name : '—'); ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
        <?php
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
