<?php
defined('ABSPATH') || exit;

class WSN_Rest
{
    const NS = 'wa-notify/v1';
    const LOCKOUT_MAX = 10;          // נסיונות מפתח שגוי לפני נעילה
    const LOCKOUT_WINDOW = 600;      // שניות

    public static function init(): void
    {
        add_action('rest_api_init', [__CLASS__, 'register_routes']);
    }

    public static function register_routes(): void
    {
        $perm = [__CLASS__, 'check_auth'];
        register_rest_route(self::NS, '/ping', [
            'methods' => 'GET', 'callback' => [__CLASS__, 'ping'], 'permission_callback' => $perm,
        ]);
        register_rest_route(self::NS, '/claim', [
            'methods' => 'POST', 'callback' => [__CLASS__, 'claim'], 'permission_callback' => $perm,
        ]);
        register_rest_route(self::NS, '/report', [
            'methods' => 'POST', 'callback' => [__CLASS__, 'report'], 'permission_callback' => $perm,
        ]);
        register_rest_route(self::NS, '/optout', [
            'methods' => 'POST', 'callback' => [__CLASS__, 'optout'], 'permission_callback' => $perm,
        ]);
        register_rest_route(self::NS, '/heartbeat', [
            'methods' => 'POST', 'callback' => [__CLASS__, 'heartbeat'], 'permission_callback' => $perm,
        ]);
        register_rest_route(self::NS, '/queue', [
            'methods' => 'GET', 'callback' => [__CLASS__, 'queue'], 'permission_callback' => $perm,
        ]);
        register_rest_route(self::NS, '/history', [
            'methods' => 'GET', 'callback' => [__CLASS__, 'history'], 'permission_callback' => $perm,
        ]);
    }

    public static function check_auth(WP_REST_Request $req)
    {
        // HTTPS חובה בפרודקשן (WSN_ALLOW_HTTP לפיתוח מקומי בלבד)
        if (!is_ssl() && !(defined('WSN_ALLOW_HTTP') && WSN_ALLOW_HTTP)) {
            return new WP_Error('wsn_https_required', 'HTTPS required', ['status' => 403]);
        }

        $ip = sanitize_text_field($_SERVER['REMOTE_ADDR'] ?? 'unknown');
        $lock_key = 'wsn_lock_' . md5($ip);
        if ((int) get_transient($lock_key) >= self::LOCKOUT_MAX) {
            return new WP_Error('wsn_locked', 'Too many failed attempts', ['status' => 429]);
        }

        $key = $req->get_header('X-WSN-Api-Key');
        if (!WSN_Api_Key::verify($key)) {
            $fails = (int) get_transient($lock_key) + 1;
            set_transient($lock_key, $fails, self::LOCKOUT_WINDOW);
            return new WP_Error('wsn_unauthorized', 'Invalid API key', ['status' => 401]);
        }
        delete_transient($lock_key);
        return true;
    }

    public static function ping(): WP_REST_Response
    {
        return new WP_REST_Response([
            'ok' => true,
            'store_name' => get_bloginfo('name'),
            'plugin_version' => WSN_VERSION,
            'time' => current_time('mysql'),
        ]);
    }

    // קריאה בלבד — לתצוגה בדשבורד המקומי של הגשר, לא תובעת ולא משנה שום שורה
    public static function queue(WP_REST_Request $req): WP_REST_Response
    {
        $limit = min(100, max(1, (int) ($req->get_param('limit') ?? 20)));
        return new WP_REST_Response([
            'ok' => true,
            'server_time' => current_time('mysql'),
            'items' => WSN_Outbox::list_pending($limit),
            'counts' => WSN_Outbox::counts(),
        ]);
    }

    // קריאה בלבד — היסטוריית הודעות מדפדפת לדשבורד המקומי של הגשר
    public static function history(WP_REST_Request $req): WP_REST_Response
    {
        $page = (int) ($req->get_param('page') ?? 1);
        $per  = (int) ($req->get_param('per') ?? 10);
        return new WP_REST_Response(
            ['ok' => true, 'server_time' => current_time('mysql')]
            + WSN_Outbox::list_history($page, $per)
        );
    }

    public static function claim(WP_REST_Request $req): WP_REST_Response
    {
        $worker = sanitize_text_field($req->get_param('worker_id') ?? 'bridge');
        $max = min(10, max(1, (int) ($req->get_param('max') ?? 3)));
        $kinds = array_values(array_intersect(
            (array) ($req->get_param('kinds') ?? []),
            ['status', 'item_change', 'campaign', 'free', 'test']
        ));
        $messages = WSN_Outbox::claim($worker, $max, $kinds);
        return new WP_REST_Response([
            'ok' => true,
            'server_time' => current_time('mysql'),
            'messages' => $messages,
        ]);
    }

    public static function report(WP_REST_Request $req): WP_REST_Response
    {
        $accepted = [];
        foreach ((array) $req->get_param('results') as $result) {
            if (is_array($result)) {
                $accepted[] = ['id' => (int) ($result['id'] ?? 0), 'accepted' => WSN_Outbox::report($result)];
            }
        }
        return new WP_REST_Response(['ok' => true, 'accepted' => $accepted]);
    }

    public static function optout(WP_REST_Request $req): WP_REST_Response
    {
        $done = 0;
        foreach ((array) $req->get_param('events') as $event) {
            $phone = WSN_Phone::to_e164((string) ($event['phone'] ?? ''));
            if ($phone) {
                WSN_Contacts::unsubscribe($phone, 'wa_message');
                $done++;
            }
        }
        return new WP_REST_Response(['ok' => true, 'processed' => $done]);
    }

    public static function heartbeat(WP_REST_Request $req): WP_REST_Response
    {
        // שמירת מצב הגשר לתצוגה בעמוד הסטטוס
        $status = [
            'state'      => sanitize_text_field($req->get_param('state') ?? ''),
            'me'         => array_map('sanitize_text_field', (array) ($req->get_param('me') ?? [])),
            'counters'   => array_map('intval', (array) ($req->get_param('counters') ?? [])),
            'breaker'    => (array) ($req->get_param('breaker') ?? []),
            'warmup_day' => (int) ($req->get_param('warmup_day') ?? 0),
            'version'    => sanitize_text_field($req->get_param('version') ?? ''),
            'qr'         => self::sanitize_qr($req->get_param('qr_data_url')),
            'deps'       => self::sanitize_deps($req->get_param('deps')),
            'seen_at'    => current_time('mysql'),
        ];
        update_option('wsn_bridge_status', $status, false);

        // התראה על מפסק זרם פתוח
        if (!empty($status['breaker']['open']) && !get_transient('wsn_breaker_alerted')) {
            set_transient('wsn_breaker_alerted', 1, 6 * HOUR_IN_SECONDS);
            $email = (string) WSN_Settings::get('alert_email');
            if ($email) {
                wp_mail($email, 'WA Store Notify — שליחת ההודעות נעצרה',
                    "מפסק הזרם נפתח אחרי כשלונות שליחה רצופים.\nבדוק את עמוד הסטטוס: " . admin_url('admin.php?page=wsn-status'));
            }
        }

        $s = WSN_Settings::all();
        $resume = (bool) get_transient('wsn_cmd_resume_breaker');
        if ($resume) {
            delete_transient('wsn_cmd_resume_breaker');
            delete_transient('wsn_breaker_alerted');
        }

        return new WP_REST_Response([
            'ok' => true,
            'commands' => [
                'pause' => (bool) $s['paused'],
                'resume_breaker' => $resume,
            ],
            'config' => [
                'tz' => wp_timezone_string(),
                'transactional' => [
                    'min_gap_s' => (int) $s['trans_min_gap_s'], 'max_gap_s' => (int) $s['trans_max_gap_s'],
                    'hourly_cap' => (int) $s['trans_hourly_cap'], 'daily_cap' => (int) $s['trans_daily_cap'],
                    'quiet_from' => $s['quiet_from'], 'quiet_to' => $s['quiet_to'],
                ],
                'campaign' => [
                    'min_gap_s' => (int) $s['camp_min_gap_s'], 'max_gap_s' => (int) $s['camp_max_gap_s'],
                    'hourly_cap' => (int) $s['camp_hourly_cap'], 'daily_cap' => (int) $s['camp_daily_cap'],
                    'window_from' => $s['camp_window_from'], 'window_to' => $s['camp_window_to'],
                ],
                'warmup' => [
                    'enabled' => (bool) $s['warmup_enabled'],
                    'started' => (string) $s['warmup_started'],
                    'ramp' => [10, 25, 50, 100],
                ],
                'typo_ratio' => (int) $s['typo_ratio'],
                'optout_keywords' => WSN_Settings::optout_keywords(),
                'optout_confirm' => (string) (WSN_Templates::get('optout_confirm')['variants'][0] ?? ''),
            ],
        ]);
    }

    private static function sanitize_qr($qr): ?string
    {
        if (!is_string($qr) || $qr === '') {
            return null;
        }
        return preg_match('#^data:image/(png|jpeg);base64,[A-Za-z0-9+/=]+$#', $qr) ? $qr : null;
    }

    // בדיקת תלויות יומית מהגשר (whatsapp-web.js מול npm, זמינות puppeteer) — read-only, לתצוגה בלבד
    private static function sanitize_deps($deps): array
    {
        $deps = is_array($deps) ? $deps : [];
        return [
            'wwebjs_installed' => sanitize_text_field($deps['wwebjs_installed'] ?? ''),
            'wwebjs_latest'    => sanitize_text_field($deps['wwebjs_latest'] ?? ''),
            'update_available' => !empty($deps['update_available']),
            'puppeteer_ok'     => array_key_exists('puppeteer_ok', $deps) ? (bool) $deps['puppeteer_ok'] : true,
        ];
    }
}
