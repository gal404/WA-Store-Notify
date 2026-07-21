<?php
defined('ABSPATH') || exit;

class WSN_Install
{
    const DB_VERSION = '2'; // 2: נוספה טבלת wsn_item_events (יומן תנועות פריטים)

    public static function activate(): void
    {
        self::create_tables();
        self::add_capability();
        self::seed_defaults();
        update_option('wsn_db_version', self::DB_VERSION);
    }

    public static function maybe_upgrade(): void
    {
        if (get_option('wsn_db_version') !== self::DB_VERSION) {
            self::activate();
        }
    }

    private static function create_tables(): void
    {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $charset = $wpdb->get_charset_collate();
        $p = $wpdb->prefix;

        dbDelta("CREATE TABLE {$p}wsn_outbox (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            kind varchar(20) NOT NULL DEFAULT 'status',
            priority tinyint NOT NULL DEFAULT 0,
            order_id bigint(20) unsigned NULL,
            campaign_id bigint(20) unsigned NULL,
            contact_id bigint(20) unsigned NULL,
            phone_e164 varchar(20) NOT NULL,
            body text NOT NULL,
            status varchar(12) NOT NULL DEFAULT 'queued',
            event_key varchar(80) NULL,
            scheduled_at datetime NOT NULL,
            expires_at datetime NULL,
            claim_token char(36) NULL,
            claim_expires_at datetime NULL,
            attempts tinyint NOT NULL DEFAULT 0,
            max_attempts tinyint NOT NULL DEFAULT 3,
            last_error varchar(255) NULL,
            wa_msg_id varchar(64) NULL,
            sent_at datetime NULL,
            created_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY event_key (event_key),
            KEY claimable (status,priority,scheduled_at),
            KEY order_id (order_id),
            KEY campaign_id (campaign_id),
            KEY phone (phone_e164)
        ) $charset;");

        dbDelta("CREATE TABLE {$p}wsn_contacts (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            phone_e164 varchar(20) NOT NULL,
            first_name varchar(100) NULL,
            last_name varchar(100) NULL,
            wp_user_id bigint(20) unsigned NULL,
            status varchar(12) NOT NULL DEFAULT 'active',
            marketing_consent tinyint(1) NOT NULL DEFAULT 0,
            unsubscribed_at datetime NULL,
            unsubscribe_src varchar(20) NULL,
            orders_count int NOT NULL DEFAULT 0,
            total_spent decimal(12,2) NOT NULL DEFAULT 0,
            last_order_id bigint(20) unsigned NULL,
            last_order_at datetime NULL,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY phone (phone_e164),
            KEY status (status),
            KEY last_order_at (last_order_at)
        ) $charset;");

        // יומן תנועות פריטים בהזמנה — הבסיס להודעות אוטומטיות על שינויים
        dbDelta("CREATE TABLE {$p}wsn_item_events (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            order_id bigint(20) unsigned NOT NULL,
            order_item_id bigint(20) unsigned NULL,
            event_type varchar(20) NOT NULL,
            product_id bigint(20) unsigned NULL,
            variation_id bigint(20) unsigned NULL,
            item_name varchar(255) NOT NULL,
            qty_before int NULL,
            qty_after int NULL,
            reason_code varchar(30) NULL,
            reason_text varchar(255) NULL,
            notified tinyint(1) NOT NULL DEFAULT 0,
            user_id bigint(20) unsigned NULL,
            created_at datetime NOT NULL,
            PRIMARY KEY  (id),
            KEY order_id (order_id),
            KEY pending (order_id,reason_code)
        ) $charset;");

        dbDelta("CREATE TABLE {$p}wsn_campaigns (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            title varchar(200) NOT NULL,
            variants_json longtext NOT NULL,
            filter_json text NOT NULL,
            status varchar(12) NOT NULL DEFAULT 'draft',
            scheduled_at datetime NULL,
            total_recipients int NOT NULL DEFAULT 0,
            sent_count int NOT NULL DEFAULT 0,
            failed_count int NOT NULL DEFAULT 0,
            created_by bigint(20) unsigned NOT NULL,
            created_at datetime NOT NULL,
            started_at datetime NULL,
            finished_at datetime NULL,
            PRIMARY KEY  (id)
        ) $charset;");
    }

    private static function add_capability(): void
    {
        foreach (['administrator', 'shop_manager'] as $role_name) {
            $role = get_role($role_name);
            if ($role) {
                $role->add_cap('manage_wa_notify');
            }
        }
    }

    private static function seed_defaults(): void
    {
        if (get_option('wsn_settings') === false) {
            add_option('wsn_settings', WSN_Settings::defaults());
        }
        if (get_option('wsn_templates') === false) {
            add_option('wsn_templates', WSN_Templates::defaults());
        }
    }
}
