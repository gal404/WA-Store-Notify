<?php
/**
 * Plugin Name: WA Store Notify — התראות וואטסאפ לחנות
 * Description: עדכוני וואטסאפ ללקוחות על מצב ההזמנה (WooCommerce), מועדון לקוחות ודיוור — דרך גשר מקומי במודל משיכה.
 * Version: 0.2.13
 * Author: Pinookim
 * Requires Plugins: woocommerce
 * Requires PHP: 7.4
 * Text Domain: wa-store-notify
 */

defined('ABSPATH') || exit;

define('WSN_VERSION', '0.2.13');
define('WSN_PLUGIN_FILE', __FILE__);
define('WSN_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('WSN_PLUGIN_URL', plugin_dir_url(__FILE__));

require_once WSN_PLUGIN_DIR . 'includes/class-wsn-install.php';
require_once WSN_PLUGIN_DIR . 'includes/class-wsn-updater.php';
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
