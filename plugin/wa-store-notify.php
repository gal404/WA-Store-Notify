<?php
/**
 * Plugin Name: WA Store Notify — התראות וואטסאפ לחנות
 * Description: עדכוני וואטסאפ ללקוחות על מצב ההזמנה (WooCommerce), מועדון לקוחות ודיוור — דרך גשר מקומי במודל משיכה.
 * Version: 1.3.1
 * Author: Pinookim
 * Requires Plugins: woocommerce
 * Requires PHP: 7.4
 * Text Domain: wa-store-notify
 */

defined('ABSPATH') || exit;

define('WSN_VERSION', '1.3.1');
define('WSN_PLUGIN_FILE', __FILE__);
define('WSN_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('WSN_PLUGIN_URL', plugin_dir_url(__FILE__));

require_once WSN_PLUGIN_DIR . 'includes/class-wsn-install.php';
require_once WSN_PLUGIN_DIR . 'includes/class-wsn-updater.php';
require_once WSN_PLUGIN_DIR . 'includes/class-wsn-item-events.php';
require_once WSN_PLUGIN_DIR . 'includes/class-wsn-change-composer.php';
require_once WSN_PLUGIN_DIR . 'includes/class-wsn-settings.php';
require_once WSN_PLUGIN_DIR . 'includes/class-wsn-phone.php';
require_once WSN_PLUGIN_DIR . 'includes/class-wsn-api-key.php';
require_once WSN_PLUGIN_DIR . 'includes/class-wsn-templates.php';
require_once WSN_PLUGIN_DIR . 'includes/class-wsn-outbox.php';
require_once WSN_PLUGIN_DIR . 'includes/class-wsn-contacts.php';
require_once WSN_PLUGIN_DIR . 'includes/class-wsn-campaigns.php';
require_once WSN_PLUGIN_DIR . 'includes/class-wsn-rest.php';

register_activation_hook(__FILE__, ['WSN_Install', 'activate']);

// תאימות HPOS (טבלאות הזמנות חדשות של WooCommerce)
add_action('before_woocommerce_init', function () {
    if (class_exists('\Automattic\WooCommerce\Utilities\FeaturesUtil')) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true);
    }
});

add_action('plugins_loaded', function () {
    WSN_Install::maybe_upgrade();
    WSN_Rest::init();
    WSN_Updater::init();

    if (!class_exists('WooCommerce')) {
        add_action('admin_notices', function () {
            echo '<div class="notice notice-error"><p>WA Store Notify דורש WooCommerce פעיל.</p></div>';
        });
        return;
    }

    require_once WSN_PLUGIN_DIR . 'includes/class-wsn-order-hooks.php';
    require_once WSN_PLUGIN_DIR . 'includes/class-wsn-order-items.php';
    require_once WSN_PLUGIN_DIR . 'includes/class-wsn-tracking.php';
    WSN_Order_Hooks::init();
    WSN_Order_Items::init();
    WSN_Tracking::init();

    if (is_admin()) {
        require_once WSN_PLUGIN_DIR . 'includes/admin/class-wsn-admin.php';
        WSN_Admin::init();
    }
});

// ניקוי יומן ישן — תחזוקה בלבד, לא קריטי למסירה (מודל המשיכה לא תלוי ב-WP-Cron)
add_action('wsn_daily_cleanup', function () {
    $days = (int) WSN_Settings::get('retention_days');
    if ($days > 0) {
        WSN_Outbox::purge_older_than($days);
    }
});
if (!wp_next_scheduled('wsn_daily_cleanup')) {
    wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', 'wsn_daily_cleanup');
}

/**
 * שומר-סף לגשר: אם הגשר הפסיק לדווח (המחשב כבוי/התהליך נפל) — שולח מייל
 * התראה פעם אחת, ומודיע שוב כשהוא חוזר. בלי זה, גשר שנפל מתגלה רק כשלקוח
 * מתלונן שלא קיבל הודעה.
 */
add_action('wsn_bridge_watch', function () {
    $email = (string) WSN_Settings::get('alert_email');
    if (!$email) {
        return;
    }
    $st = (array) get_option('wsn_bridge_status', []);
    $seen = (string) ($st['seen_at'] ?? '');
    $stale_after = 15 * MINUTE_IN_SECONDS;
    $is_down = !$seen || (strtotime(current_time('mysql')) - strtotime($seen)) > $stale_after;
    $alerted = (bool) get_option('wsn_bridge_down_alerted', false);
    $status_url = admin_url('admin.php?page=wsn-status');

    if ($is_down && !$alerted) {
        update_option('wsn_bridge_down_alerted', 1, false);
        wp_mail(
            $email,
            'WA Store Notify — הגשר לא מדווח',
            "שרת הוואטסאפ (הגשר) הפסיק לדווח.\n"
            . "דיווח אחרון: " . ($seen ?: 'מעולם לא') . "\n\n"
            . "המשמעות: הודעות מאושרות ממתינות בתור ולא נשלחות.\n"
            . "בדוק שהמחשב דולק ושהגשר רץ (pm2 list), ואז: pm2 start ecosystem.config.js\n"
            . "עמוד הסטטוס: " . $status_url
        );
    } elseif (!$is_down && $alerted) {
        delete_option('wsn_bridge_down_alerted');
        wp_mail($email, 'WA Store Notify — הגשר חזר לפעול',
            "הגשר מדווח שוב (דיווח אחרון: $seen). השליחה חזרה לפעולה.\n" . $status_url);
    }
});
if (!wp_next_scheduled('wsn_bridge_watch')) {
    wp_schedule_event(time() + 5 * MINUTE_IN_SECONDS, 'hourly', 'wsn_bridge_watch');
}
