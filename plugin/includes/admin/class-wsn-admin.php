<?php
defined('ABSPATH') || exit;

class WSN_Admin
{
    // הרשאה לצפייה/שימוש במסכי התוסף. 'edit_posts' = כל הצוות (מנהל, מנהל חנות,
    // עורך, כותב) בלי תלות בתפקיד ספציפי — אך לא חשבונות לקוח (הגנה על פרטיות).
    const CAP = 'edit_posts';

    public static function init(): void
    {
        add_action('admin_menu', [__CLASS__, 'menu']);
        add_action('admin_enqueue_scripts', [__CLASS__, 'assets']);
        add_action('admin_post_wsn_save_settings', [__CLASS__, 'handle_save_settings']);
        add_action('admin_post_wsn_save_templates', [__CLASS__, 'handle_save_templates']);
        add_action('admin_post_wsn_api_key', [__CLASS__, 'handle_api_key']);
        add_action('admin_post_wsn_resume_breaker', [__CLASS__, 'handle_resume_breaker']);
        add_action('admin_post_wsn_toggle_pause', [__CLASS__, 'handle_toggle_pause']);
        add_action('admin_post_wsn_export_contacts', [__CLASS__, 'handle_export_contacts']);
        add_action('admin_post_wsn_campaign_create', [__CLASS__, 'handle_campaign_create']);
        add_action('admin_post_wsn_campaign_action', [__CLASS__, 'handle_campaign_action']);
        add_action('admin_post_wsn_cancel_messages', [__CLASS__, 'handle_cancel_messages']);
        add_action('admin_post_wsn_send_now_messages', [__CLASS__, 'handle_send_now_messages']);
        add_action('wp_ajax_wsn_audience_count', [__CLASS__, 'ajax_audience_count']);
        add_action('wp_ajax_wsn_send_test', [__CLASS__, 'ajax_send_test']);
        add_action('wp_ajax_wsn_test_status', [__CLASS__, 'ajax_test_status']);
        add_action('wp_ajax_wsn_send_now_single', [__CLASS__, 'ajax_send_now_single']);
        add_action('wp_ajax_wsn_draft_save', [__CLASS__, 'ajax_draft_save']);
        add_action('wp_ajax_wsn_draft_approve', [__CLASS__, 'ajax_draft_approve']);
        add_action('wp_ajax_wsn_draft_discard', [__CLASS__, 'ajax_draft_discard']);
    }

    public static function menu(): void
    {
        add_menu_page('וואטסאפ לחנות', 'וואטסאפ לחנות', self::CAP, 'wsn-status',
            [__CLASS__, 'page_status'], 'dashicons-whatsapp', 56);
        add_submenu_page('wsn-status', 'סטטוס', 'סטטוס', self::CAP, 'wsn-status', [__CLASS__, 'page_status']);
        $n = WSN_Outbox::draft_count();
        $badge = $n ? ' <span class="awaiting-mod">' . (int) $n . '</span>' : '';
        add_submenu_page('wsn-status', 'הודעות ממתינות', 'הודעות ממתינות' . $badge, self::CAP, 'wsn-pending', [__CLASS__, 'page_pending']);
        add_submenu_page('wsn-status', 'הגדרות', 'הגדרות', self::CAP, 'wsn-settings', [__CLASS__, 'page_settings']);
        add_submenu_page('wsn-status', 'תבניות הודעה', 'תבניות', self::CAP, 'wsn-templates', [__CLASS__, 'page_templates']);
        add_submenu_page('wsn-status', 'יומן הודעות', 'יומן', self::CAP, 'wsn-log', [__CLASS__, 'page_log']);
        add_submenu_page('wsn-status', 'מועדון לקוחות', 'מועדון', self::CAP, 'wsn-contacts', [__CLASS__, 'page_contacts']);
        add_submenu_page('wsn-status', 'קמפיינים', 'קמפיינים', self::CAP, 'wsn-campaigns', [__CLASS__, 'page_campaigns']);
    }

    /** מסך עריכת הזמנה — גם קלאסי וגם HPOS */
    private static function is_order_screen(): bool
    {
        if (!function_exists('get_current_screen')) {
            return false;
        }
        $screen = get_current_screen();
        if (!$screen) {
            return false;
        }
        $order_screen = function_exists('wc_get_page_screen_id') ? wc_get_page_screen_id('shop-order') : 'shop_order';
        return in_array($screen->id, [$order_screen, 'shop_order'], true);
    }

    public static function assets($hook): void
    {
        // נטען גם במסך ההזמנה, כי שם רץ מודאל סיבות תנועות הפריטים
        if (strpos((string) $hook, 'wsn-') === false && !self::is_order_screen()) {
            return;
        }
        wp_enqueue_style('wsn-admin', WSN_PLUGIN_URL . 'assets/css/admin.css', [], WSN_VERSION);
        wp_enqueue_script('wsn-admin', WSN_PLUGIN_URL . 'assets/js/admin.js', [], WSN_VERSION, true);
        wp_localize_script('wsn-admin', 'WSN', [
            'ajax' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('wsn_admin'),
        ]);
    }

    private static function guard(): void
    {
        if (!current_user_can(self::CAP)) {
            wp_die('אין הרשאה');
        }
    }

    private static function redirect(string $page, string $msg = '', string $type = 'ok'): void
    {
        wp_safe_redirect(add_query_arg(
            ['page' => $page, 'wsn_msg' => rawurlencode($msg), 'wsn_type' => $type],
            admin_url('admin.php')
        ));
        exit;
    }

    public static function notice(): void
    {
        if (empty($_GET['wsn_msg'])) {
            return;
        }
        $type = ($_GET['wsn_type'] ?? 'ok') === 'err' ? 'error' : 'success';
        printf('<div class="notice notice-%s is-dismissible"><p>%s</p></div>',
            esc_attr($type), esc_html(rawurldecode(sanitize_text_field($_GET['wsn_msg']))));
    }

    /** ניווט בין מסכי התוסף — כדי לא לפתוח את תפריט וורדפרס בכל מעבר */
    public static function nav(string $current): void
    {
        $n = WSN_Outbox::draft_count();
        $tabs = [
            'wsn-status'    => 'סטטוס',
            'wsn-pending'   => 'הודעות ממתינות' . ($n ? ' (' . (int) $n . ')' : ''),
            'wsn-settings'  => 'הגדרות',
            'wsn-templates' => 'תבניות',
            'wsn-log'       => 'יומן',
            'wsn-contacts'  => 'מועדון',
            'wsn-campaigns' => 'קמפיינים',
        ];
        echo '<nav class="wsn-nav nav-tab-wrapper">';
        foreach ($tabs as $slug => $label) {
            printf(
                '<a href="%s" class="nav-tab%s">%s</a>',
                esc_url(admin_url('admin.php?page=' . $slug)),
                $slug === $current ? ' nav-tab-active' : '',
                esc_html($label)
            );
        }
        echo '</nav>';
    }

    private static function view(string $name, array $vars = []): void
    {
        extract($vars, EXTR_SKIP);
        include WSN_PLUGIN_DIR . 'includes/admin/views/' . $name . '.php';
    }

    // ---- עמודים ----
    public static function page_status(): void    { self::guard(); self::view('status'); }
    public static function page_settings(): void  { self::guard(); self::view('settings', ['s' => WSN_Settings::all()]); }
    public static function page_templates(): void { self::guard(); self::view('templates', ['templates' => WSN_Templates::all()]); }
    public static function page_log(): void       { self::guard(); self::view('log'); }
    public static function page_contacts(): void  { self::guard(); self::view('contacts'); }
    public static function page_campaigns(): void { self::guard(); self::view('campaigns', ['campaigns' => WSN_Campaigns::all()]); }
    public static function page_pending(): void
    {
        self::guard();
        $page = max(1, (int) ($_GET['paged'] ?? 1)); // phpcs:ignore WordPress.Security.NonceVerification -- קריאה בלבד
        $data = WSN_Outbox::drafts_page($page, 15);
        $data['items'] = self::enrich_drafts($data['items']);
        self::view('pending', ['data' => $data]);
    }

    /** מוסיף לכל טיוטה פרטי הזמנה ולקוח, לתצוגה במסך "הודעות ממתינות" */
    private static function enrich_drafts(array $drafts): array
    {
        $contacts = [];
        foreach ($drafts as &$d) {
            $d['phone_display'] = WSN_Phone::to_display($d['phone_e164'] ?? '');
            $d['order'] = null;
            $d['customer'] = null;
            try {
                $oid = (int) ($d['order_id'] ?? 0);
                if ($oid && function_exists('wc_get_order')) {
                    $o = wc_get_order($oid);
                    if ($o instanceof WC_Order) {
                        $d['order'] = [
                            'number' => $o->get_order_number(),
                            'status' => $o->get_status(),
                            'label'  => wc_get_order_status_name($o->get_status()),
                            'name'   => trim($o->get_billing_first_name() . ' ' . $o->get_billing_last_name()),
                            'edit'   => admin_url('post.php?post=' . $oid . '&action=edit'),
                        ];
                    }
                }
                $phone = (string) ($d['phone_e164'] ?? '');
                if ($phone !== '') {
                    if (!array_key_exists($phone, $contacts)) {
                        $contacts[$phone] = WSN_Contacts::get_by_phone($phone);
                    }
                    $c = $contacts[$phone];
                    if ($c) {
                        $d['customer'] = [
                            'name'         => trim(($c['first_name'] ?? '') . ' ' . ($c['last_name'] ?? '')),
                            'orders_count' => (int) ($c['orders_count'] ?? 0),
                            'consent'      => (int) ($c['marketing_consent'] ?? 0),
                        ];
                    }
                }
            } catch (\Throwable $e) {
                // העשרה בלבד — לא מפילים את המסך בגללה
            }
        }
        unset($d);
        return $drafts;
    }

    public static function ajax_draft_save(): void
    {
        check_ajax_referer('wsn_admin', 'nonce');
        if (!current_user_can(self::CAP)) {
            wp_send_json_error('אין הרשאה', 403);
        }
        $id = (int) ($_POST['id'] ?? 0);
        $body = sanitize_textarea_field(wp_unslash($_POST['body'] ?? ''));
        if (!$id || $body === '') {
            wp_send_json_error('חסר תוכן');
        }
        if (!WSN_Outbox::update_body($id, $body)) {
            wp_send_json_error('השמירה נכשלה (ייתכן שהטיוטה כבר אושרה)');
        }
        wp_send_json_success(['saved' => $id]);
    }

    public static function ajax_draft_approve(): void
    {
        check_ajax_referer('wsn_admin', 'nonce');
        if (!current_user_can(self::CAP)) {
            wp_send_json_error('אין הרשאה', 403);
        }
        $ids = array_map('intval', (array) ($_POST['ids'] ?? []));
        // שמירת עריכה אחרונה לפני אישור (טיוטה יחידה)
        if (count($ids) === 1 && isset($_POST['body'])) {
            $body = sanitize_textarea_field(wp_unslash($_POST['body']));
            if ($body !== '') {
                WSN_Outbox::update_body($ids[0], $body);
            }
        }
        $sched = WSN_Outbox::approve($ids); // [id => scheduled_at]
        $n = count($sched);
        if (!$n) {
            wp_send_json_error('שום טיוטה לא אושרה');
        }
        // כמה שניות עד השליחה בפועל לכל הודעה (0 = מיד) — לטיימר בצד הלקוח
        $now_ts = strtotime(current_time('mysql'));
        $delays = [];
        foreach ($sched as $id => $at) {
            $delays[(string) $id] = max(0, strtotime($at) - $now_ts);
        }
        wp_send_json_success(['approved' => $n, 'delays' => $delays, 'draft_count' => WSN_Outbox::draft_count()]);
    }

    public static function ajax_draft_discard(): void
    {
        check_ajax_referer('wsn_admin', 'nonce');
        if (!current_user_can(self::CAP)) {
            wp_send_json_error('אין הרשאה', 403);
        }
        $ids = array_map('intval', (array) ($_POST['ids'] ?? []));
        $n = WSN_Outbox::discard($ids);
        if (!$n) {
            wp_send_json_error('שום טיוטה לא נמחקה');
        }
        wp_send_json_success(['discarded' => $n, 'draft_count' => WSN_Outbox::draft_count()]);
    }

    // ---- handlers ----
    public static function handle_save_settings(): void
    {
        self::guard();
        check_admin_referer('wsn_save_settings');
        $in = wp_unslash($_POST);
        $changes = [];
        $ints = ['trans_min_gap_s', 'trans_max_gap_s', 'trans_hourly_cap', 'trans_daily_cap',
            'camp_min_gap_s', 'camp_max_gap_s', 'camp_hourly_cap', 'camp_daily_cap',
            'cust_gap_min_s', 'cust_gap_max_s',
            'typo_ratio', 'expiry_hours', 'retention_days', 'warmup_enabled', 'checkout_optin_enabled'];
        foreach ($ints as $k) {
            $changes[$k] = max(0, (int) ($in[$k] ?? 0));
        }
        foreach (['quiet_from', 'quiet_to', 'camp_window_from', 'camp_window_to'] as $k) {
            $changes[$k] = preg_match('/^\d{1,2}:\d{2}$/', $in[$k] ?? '') ? $in[$k] : WSN_Settings::get($k);
        }
        $changes['warmup_started'] = preg_match('/^\d{4}-\d{2}-\d{2}$/', $in['warmup_started'] ?? '') ? $in['warmup_started'] : '';
        $changes['tracking_meta_key'] = sanitize_text_field($in['tracking_meta_key'] ?? '');
        $tns = sanitize_text_field($in['tracking_notify_status'] ?? '');
        $valid_statuses = function_exists('wc_get_order_statuses') ? array_keys(wc_get_order_statuses()) : [];
        $changes['tracking_notify_status'] = in_array($tns, $valid_statuses, true) ? $tns : '';
        $changes['optout_keywords'] = sanitize_textarea_field($in['optout_keywords'] ?? '');
        $changes['item_reasons_removed'] = sanitize_textarea_field($in['item_reasons_removed'] ?? '');
        $changes['item_reasons_added'] = sanitize_textarea_field($in['item_reasons_added'] ?? '');
        $changes['checkout_optin_label'] = sanitize_text_field($in['checkout_optin_label'] ?? '');
        $changes['test_phone'] = sanitize_text_field($in['test_phone'] ?? '');
        $changes['alert_email'] = sanitize_email($in['alert_email'] ?? '');
        $changes['delete_data_on_uninstall'] = (int) !empty($in['delete_data_on_uninstall']);
        // שדה סוד — שדה ריק בשמירה = משאיר את הטוקן הקיים (לא מוחק בטעות)
        $gh_token = sanitize_text_field($in['github_token'] ?? '');
        $changes['github_token'] = $gh_token !== '' ? $gh_token : WSN_Settings::get('github_token');
        WSN_Settings::update($changes);
        self::redirect('wsn-settings', 'ההגדרות נשמרו');
    }

    public static function handle_save_templates(): void
    {
        self::guard();
        check_admin_referer('wsn_save_templates');
        $raw = (array) ($_POST['tpl'] ?? []);
        $out = [];
        foreach ($raw as $key => $data) {
            $key = sanitize_key($key);
            $variants = array_values(array_filter(
                array_map('sanitize_textarea_field', (array) wp_unslash($data['variants'] ?? [])),
                fn($v) => trim($v) !== ''
            ));
            $out[$key] = ['enabled' => (int) !empty($data['enabled']), 'variants' => $variants];
        }
        WSN_Templates::save($out);
        self::redirect('wsn-templates', 'התבניות נשמרו');
    }

    public static function handle_api_key(): void
    {
        self::guard();
        check_admin_referer('wsn_api_key');
        $key = WSN_Api_Key::generate();
        set_transient('wsn_new_api_key', $key, 120); // מוצג פעם אחת
        self::redirect('wsn-settings', 'נוצר מפתח API חדש — העתק אותו עכשיו');
    }

    public static function handle_resume_breaker(): void
    {
        self::guard();
        check_admin_referer('wsn_resume_breaker');
        set_transient('wsn_cmd_resume_breaker', 1, DAY_IN_SECONDS);
        self::redirect('wsn-status', 'בקשת חידוש שליחה נשלחה לגשר');
    }

    public static function handle_toggle_pause(): void
    {
        self::guard();
        check_admin_referer('wsn_toggle_pause');
        WSN_Settings::update(['paused' => (int) !WSN_Settings::get('paused')]);
        self::redirect('wsn-status', WSN_Settings::get('paused') ? 'השליחה הושהתה' : 'השליחה חודשה');
    }

    // עדיפות -10 (< 0 הרגיל) כדי שהגשר יתפוס את הודעת הבדיקה ראשונה מתוך התור,
    // ו-ajax_test_status מאפשר לעמוד להראות בזמן אמת מתי היא באמת נשלחה — כדי
    // ש"שלח לי לבדיקה" יהיה אימות מיידי לכל הצינור, לא רק "נוסף לתור" ותקווה.
    public static function ajax_send_test(): void
    {
        check_ajax_referer('wsn_admin', 'nonce');
        if (!current_user_can(self::CAP)) {
            wp_send_json_error('אין הרשאה', 403);
        }
        $phone = WSN_Phone::to_e164(WSN_Settings::get('test_phone'));
        if (!$phone) {
            wp_send_json_error('הגדר מספר בדיקה תקין בהגדרות קודם');
        }
        $key = sanitize_key($_POST['tpl_key'] ?? '');
        $tpl = WSN_Templates::get($key);
        $body = $tpl
            ? WSN_Templates::render(WSN_Templates::pick_variant($tpl), null,
                ['first_name' => 'ישראל', 'order_number' => '1234', 'removed_item' => 'מוצר לדוגמה',
                 'new_item' => 'מוצר חלופי', 'store_name' => get_bloginfo('name')])
            : 'הודעת בדיקה מ-WA Store Notify ✔';
        $id = WSN_Outbox::enqueue([
            'kind' => 'test', 'priority' => WSN_Outbox::PRIORITY_FORCED, 'phone' => $phone, 'body' => $body,
            'event_key' => 'test-' . time() . '-' . wp_rand(1000, 9999),
        ]);
        if (!$id) {
            wp_send_json_error('לא ניתן היה להוסיף לתור');
        }
        wp_send_json_success(['id' => $id]);
    }

    public static function ajax_test_status(): void
    {
        check_ajax_referer('wsn_admin', 'nonce');
        if (!current_user_can(self::CAP)) {
            wp_send_json_error('אין הרשאה', 403);
        }
        $id = (int) ($_POST['id'] ?? 0);
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT status, last_error FROM " . WSN_Outbox::table() . " WHERE id=%d", $id
        ), ARRAY_A);
        if (!$row) {
            wp_send_json_error('לא נמצא');
        }
        wp_send_json_success($row);
    }

    public static function handle_cancel_messages(): void
    {
        self::guard();
        check_admin_referer('wsn_bulk_message_action');
        $ids = array_map('intval', (array) ($_POST['msg_ids'] ?? []));
        $n = WSN_Outbox::cancel_by_ids($ids);
        $back = wp_get_referer() ?: admin_url('admin.php?page=wsn-log');
        wp_safe_redirect(add_query_arg(
            ['wsn_msg' => rawurlencode($n > 0 ? "בוטלו $n הודעות" : 'לא נבחרו הודעות שניתן לבטל'), 'wsn_type' => $n > 0 ? 'ok' : 'err'],
            $back
        ));
        exit;
    }

    public static function handle_send_now_messages(): void
    {
        self::guard();
        check_admin_referer('wsn_bulk_message_action');
        $ids = array_map('intval', (array) ($_POST['msg_ids'] ?? []));
        $n = WSN_Outbox::send_now_by_ids($ids);
        $back = wp_get_referer() ?: admin_url('admin.php?page=wsn-log');
        wp_safe_redirect(add_query_arg(
            ['wsn_msg' => rawurlencode($n > 0 ? "$n הודעות יישלחו בסבב הבא של הגשר" : 'לא נבחרו הודעות ממתינות'), 'wsn_type' => $n > 0 ? 'ok' : 'err'],
            $back
        ));
        exit;
    }

    // כפתור "שלח מיידית" בודד לכל שורה ביומן — בלי checkbox/submit, בלי טעינת עמוד מחדש
    public static function ajax_send_now_single(): void
    {
        check_ajax_referer('wsn_admin', 'nonce');
        if (!current_user_can(self::CAP)) {
            wp_send_json_error('אין הרשאה', 403);
        }
        $id = (int) ($_POST['id'] ?? 0);
        $n = $id > 0 ? WSN_Outbox::send_now_by_ids([$id]) : 0;
        if ($n > 0) {
            wp_send_json_success();
        }
        wp_send_json_error('ההודעה כבר לא ממתינה (נשלחה/בוטלה/נכשלה סופית)');
    }

    public static function handle_export_contacts(): void
    {
        self::guard();
        check_admin_referer('wsn_export_contacts');
        global $wpdb;
        $rows = $wpdb->get_results("SELECT phone_e164,first_name,last_name,status,marketing_consent,orders_count,total_spent,last_order_at FROM " . WSN_Contacts::table() . " ORDER BY id DESC", ARRAY_A);
        nocache_headers();
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=wsn-contacts-' . gmdate('Ymd') . '.csv');
        echo "\xEF\xBB\xBF"; // BOM לאקסל עברית
        $out = fopen('php://output', 'w');
        fputcsv($out, ['טלפון', 'שם פרטי', 'שם משפחה', 'סטטוס', 'הסכמת דיוור', 'מספר הזמנות', 'סה"כ קניות', 'הזמנה אחרונה']);
        foreach ((array) $rows as $r) {
            fputcsv($out, $r);
        }
        fclose($out);
        exit;
    }

    public static function handle_campaign_create(): void
    {
        self::guard();
        check_admin_referer('wsn_campaign_create');
        $in = wp_unslash($_POST);
        $title = sanitize_text_field($in['title'] ?? '');
        $variants = array_values(array_filter(
            array_map('sanitize_textarea_field', (array) ($in['variants'] ?? [])),
            fn($v) => trim($v) !== ''
        ));
        $filter = [
            'min_orders' => max(0, (int) ($in['min_orders'] ?? 0)),
            'last_order_days' => max(0, (int) ($in['last_order_days'] ?? 0)),
        ];
        if ($title === '' || !$variants) {
            self::redirect('wsn-campaigns', 'צריך כותרת ולפחות נוסח אחד', 'err');
        }
        WSN_Campaigns::create($title, $variants, $filter);
        self::redirect('wsn-campaigns', 'הקמפיין נשמר כטיוטה');
    }

    public static function handle_campaign_action(): void
    {
        self::guard();
        check_admin_referer('wsn_campaign_action');
        $id = (int) ($_POST['campaign_id'] ?? 0);
        $act = sanitize_key($_POST['act'] ?? '');
        if ($act === 'launch') {
            $res = WSN_Campaigns::launch($id);
            self::redirect('wsn-campaigns', $res['ok'] ? "שוגר — {$res['queued']} נמענים נכנסו לתור" : $res['error'], $res['ok'] ? 'ok' : 'err');
        } elseif ($act === 'cancel') {
            WSN_Campaigns::cancel($id);
            self::redirect('wsn-campaigns', 'הקמפיין בוטל');
        }
        self::redirect('wsn-campaigns', 'פעולה לא מוכרת', 'err');
    }

    public static function ajax_audience_count(): void
    {
        check_ajax_referer('wsn_admin', 'nonce');
        if (!current_user_can(self::CAP)) {
            wp_send_json_error('אין הרשאה', 403);
        }
        $count = WSN_Contacts::audience([
            'min_orders' => max(0, (int) ($_POST['min_orders'] ?? 0)),
            'last_order_days' => max(0, (int) ($_POST['last_order_days'] ?? 0)),
        ], true);
        wp_send_json_success(['count' => $count, 'estimate' => WSN_Campaigns::estimate($count)]);
    }
}

add_action('admin_notices', ['WSN_Admin', 'notice']);
