<?php
defined('ABSPATH') || exit;

class WSN_Contacts
{
    public static function table(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'wsn_contacts';
    }

    public static function get_by_phone(string $phone_e164): ?array
    {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM " . self::table() . " WHERE phone_e164=%s", $phone_e164
        ), ARRAY_A);
        return $row ?: null;
    }

    public static function is_unsubscribed(string $phone_e164): bool
    {
        $c = self::get_by_phone($phone_e164);
        return $c && $c['status'] === 'unsubscribed';
    }

    /** upsert מתוך הזמנה. לעולם לא מחזיר לקוח שהוסר למצב פעיל. */
    public static function upsert_from_order(WC_Order $order): ?int
    {
        global $wpdb;
        $phone = WSN_Phone::to_e164($order->get_billing_phone());
        if (!$phone) {
            return null;
        }
        $t = self::table();
        $now = current_time('mysql');
        $consent = (int) ($order->get_meta('_wsn_marketing_optin') === 'yes');

        $existing = self::get_by_phone($phone);
        if ($existing) {
            $update = [
                'first_name' => $order->get_billing_first_name() ?: $existing['first_name'],
                'last_name'  => $order->get_billing_last_name() ?: $existing['last_name'],
                'wp_user_id' => $order->get_customer_id() ?: $existing['wp_user_id'],
                'updated_at' => $now,
            ];
            // הסכמת דיוור רק נדלקת מהזמנה, לא נכבית (כיבוי — רק ב"הסר" או ידנית)
            if ($consent && !(int) $existing['marketing_consent'] && $existing['status'] !== 'unsubscribed') {
                $update['marketing_consent'] = 1;
            }
            $wpdb->update($t, $update, ['id' => $existing['id']]);
            return (int) $existing['id'];
        }

        $wpdb->insert($t, [
            'phone_e164'        => $phone,
            'first_name'        => $order->get_billing_first_name(),
            'last_name'         => $order->get_billing_last_name(),
            'wp_user_id'        => $order->get_customer_id() ?: null,
            'status'            => 'active',
            'marketing_consent' => $consent,
            'created_at'        => $now,
            'updated_at'        => $now,
        ]);
        return (int) $wpdb->insert_id;
    }

    /** עדכון סטטיסטיקות קנייה — נקרא כשהזמנה עוברת לסטטוס בתשלום, פעם אחת להזמנה */
    public static function record_paid_order(WC_Order $order): void
    {
        global $wpdb;
        if ($order->get_meta('_wsn_stats_recorded') === 'yes') {
            return;
        }
        $phone = WSN_Phone::to_e164($order->get_billing_phone());
        if (!$phone) {
            return;
        }
        $contact_id = self::upsert_from_order($order);
        if (!$contact_id) {
            return;
        }
        $wpdb->query($wpdb->prepare(
            "UPDATE " . self::table() . "
             SET orders_count = orders_count + 1,
                 total_spent  = total_spent + %f,
                 last_order_id = %d,
                 last_order_at = %s,
                 updated_at = %s
             WHERE id = %d",
            (float) $order->get_total(), $order->get_id(),
            current_time('mysql'), current_time('mysql'), $contact_id
        ));
        $order->update_meta_data('_wsn_stats_recorded', 'yes');
        $order->save();
    }

    public static function unsubscribe(string $phone_e164, string $src = 'wa_message'): void
    {
        global $wpdb;
        $now = current_time('mysql');
        $existing = self::get_by_phone($phone_e164);
        if ($existing) {
            $wpdb->update(self::table(), [
                'status' => 'unsubscribed', 'marketing_consent' => 0,
                'unsubscribed_at' => $now, 'unsubscribe_src' => $src, 'updated_at' => $now,
            ], ['id' => $existing['id']]);
        } else {
            $wpdb->insert(self::table(), [
                'phone_e164' => $phone_e164, 'status' => 'unsubscribed',
                'unsubscribed_at' => $now, 'unsubscribe_src' => $src,
                'created_at' => $now, 'updated_at' => $now,
            ]);
        }
        WSN_Outbox::cancel_for_phone($phone_e164);
    }

    public static function mark_invalid(string $phone_e164): void
    {
        global $wpdb;
        $wpdb->update(self::table(),
            ['status' => 'invalid', 'updated_at' => current_time('mysql')],
            ['phone_e164' => $phone_e164]
        );
    }

    /**
     * שאילתת קהל לקמפיין. $filter: min_orders, last_order_days.
     * תמיד: פעילים עם הסכמת דיוור בלבד.
     */
    public static function audience(array $filter, bool $count_only = false)
    {
        global $wpdb;
        $where = ["status='active'", "marketing_consent=1"];
        $params = [];
        if (!empty($filter['min_orders'])) {
            $where[] = 'orders_count >= %d';
            $params[] = (int) $filter['min_orders'];
        }
        if (!empty($filter['last_order_days'])) {
            $where[] = 'last_order_at >= DATE_SUB(%s, INTERVAL %d DAY)';
            $params[] = current_time('mysql');
            $params[] = (int) $filter['last_order_days'];
        }
        $sql = ($count_only ? "SELECT COUNT(*)" : "SELECT *")
             . " FROM " . self::table() . " WHERE " . implode(' AND ', $where);
        if ($params) {
            $sql = $wpdb->prepare($sql, $params);
        }
        return $count_only ? (int) $wpdb->get_var($sql) : (array) $wpdb->get_results($sql, ARRAY_A);
    }
}
