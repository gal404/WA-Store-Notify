<?php
defined('ABSPATH') || exit;

class WSN_Settings
{
    public static function defaults(): array
    {
        return [
            'paused'                   => 0,
            // טרנזקציוני (הודעות סטטוס/פריטים/חופשיות)
            'trans_min_gap_s'          => 30,
            'trans_max_gap_s'          => 90,
            'trans_hourly_cap'         => 30,
            'trans_daily_cap'          => 150,
            'quiet_from'               => '21:00',
            'quiet_to'                 => '08:00',
            // השהיה אקראית בין הודעות *לאותו לקוח* (שניות) — 5–10 דק
            'cust_gap_min_s'           => 300,
            'cust_gap_max_s'           => 600,
            // קמפיינים
            'camp_min_gap_s'           => 90,
            'camp_max_gap_s'           => 240,
            'camp_hourly_cap'          => 15,
            'camp_daily_cap'           => 60,
            'camp_window_from'         => '10:00',
            'camp_window_to'           => '16:00',
            // warm-up למספר טרי
            'warmup_enabled'           => 0,
            'warmup_started'           => '',
            // שגיאות כתיב מכוונות: 1 מתוך X (0 = כבוי)
            'typo_ratio'               => 7,
            // משלוחים
            'tracking_meta_key'        => '',
            // מתי לבנות את טיוטת הודעת המעקב: '' = מיד כשמתקבל מספר; 'wc-…' = רק בסטטוס הזה
            'tracking_notify_status'   => '',
            // תבנית כתובת מעקב גורפת: {number} יוחלף במספר (או יצורף לסוף אם אין placeholder)
            'tracking_url_base'        => '',
            // הסרה
            'optout_keywords'          => "הסר\nהסירו אותי\nstop",
            // כללי
            'checkout_optin_enabled'   => 1,
            'checkout_optin_label'     => 'אשמח לקבל עדכונים ומבצעים בוואטסאפ',
            'expiry_hours'             => 48,
            'retention_days'           => 180,
            'test_phone'               => '',
            'alert_email'              => get_option('admin_email'),
            'delete_data_on_uninstall' => 0,
            // עדכון תוסף אוטומטי (GitHub פרטי דרך Plugin Update Checker)
            'github_token'             => '',
            // סיבות לתנועות פריטים — ניתנות לעריכה בלי שינוי קוד
            'item_reasons_removed'     => WSN_Item_Events::DEFAULT_REASONS_REMOVED,
            'item_reasons_added'       => WSN_Item_Events::DEFAULT_REASONS_ADDED,
            'item_reasons_price'       => WSN_Item_Events::DEFAULT_REASONS_PRICE,
            'item_reasons_cancel'      => WSN_Item_Events::DEFAULT_REASONS_CANCEL,
            // מצב שליחה לכל סוג שינוי: auto = נשלח לבד, manual = רק בלחיצה.
            // ברירת מחדל manual בכוונה — ההודעה נבנית לבד, השליחה באישור.
            'send_mode_item_added'      => 'manual',
            'send_mode_item_removed'    => 'manual',
            'send_mode_qty_changed'     => 'manual',
            'send_mode_order_status'    => 'auto',
            'send_mode_order_cancelled' => 'manual',
            'send_mode_customer_note'   => 'manual',
        ];
    }

    public static function all(): array
    {
        return array_merge(self::defaults(), (array) get_option('wsn_settings', []));
    }

    public static function get(string $key)
    {
        $all = self::all();
        return $all[$key] ?? null;
    }

    public static function update(array $changes): void
    {
        update_option('wsn_settings', array_merge(self::all(), $changes));
    }

    public static function optout_keywords(): array
    {
        $lines = preg_split('/\r\n|\r|\n/', (string) self::get('optout_keywords'));
        return array_values(array_filter(array_map('trim', $lines), fn($k) => $k !== ''));
    }
}
