<?php
defined('WP_UNINSTALL_PLUGIN') || exit;

// תמיד: הסרת היכולת מכל התפקידים + מחיקת state ארעי
foreach (['administrator', 'shop_manager'] as $role_name) {
    $role = get_role($role_name);
    if ($role) {
        $role->remove_cap('manage_wa_notify');
    }
}
delete_option('wsn_bridge_status');
delete_option('wsn_api_key_hash');
wp_clear_scheduled_hook('wsn_daily_cleanup');

// מחיקת נתונים עסקיים — רק אם המשתמש ביקש במפורש בהגדרות
$settings = (array) get_option('wsn_settings', []);
if (!empty($settings['delete_data_on_uninstall'])) {
    global $wpdb;
    foreach (['wsn_outbox', 'wsn_contacts', 'wsn_campaigns'] as $table) {
        $wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}{$table}");
    }
    delete_option('wsn_settings');
    delete_option('wsn_templates');
    delete_option('wsn_license_key');
    delete_option('wsn_db_version');
}
