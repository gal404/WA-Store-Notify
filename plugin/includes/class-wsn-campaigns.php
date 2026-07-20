<?php
defined('ABSPATH') || exit;

class WSN_Campaigns
{
    public static function table(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'wsn_campaigns';
    }

    public static function get(int $id): ?array
    {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM " . self::table() . " WHERE id=%d", $id), ARRAY_A);
        return $row ?: null;
    }

    public static function all(): array
    {
        global $wpdb;
        return (array) $wpdb->get_results("SELECT * FROM " . self::table() . " ORDER BY id DESC LIMIT 100", ARRAY_A);
    }

    public static function create(string $title, array $variants, array $filter): int
    {
        global $wpdb;
        $wpdb->insert(self::table(), [
            'title' => $title,
            'variants_json' => wp_json_encode(array_values($variants), JSON_UNESCAPED_UNICODE),
            'filter_json' => wp_json_encode($filter, JSON_UNESCAPED_UNICODE),
            'status' => 'draft',
            'created_by' => get_current_user_id(),
            'created_at' => current_time('mysql'),
        ]);
        return (int) $wpdb->insert_id;
    }

    /**
     * שיגור: פריסת הקמפיין לשורות outbox. ה-scheduled_at נפרס מראש על פני ימים
     * לפי ה-cap היומי לקמפיינים, כך שהשרת והגשר מסכימים על הקצב.
     */
    public static function launch(int $id): array
    {
        global $wpdb;
        $c = self::get($id);
        if (!$c || !in_array($c['status'], ['draft', 'scheduled'], true)) {
            return ['ok' => false, 'error' => 'הקמפיין לא נמצא או שכבר שוגר'];
        }
        $variants = array_values(array_filter((array) json_decode($c['variants_json'], true), 'is_string'));
        if (!$variants) {
            return ['ok' => false, 'error' => 'אין נוסחי הודעה'];
        }
        $recipients = WSN_Contacts::audience((array) json_decode($c['filter_json'], true));
        if (!$recipients) {
            return ['ok' => false, 'error' => 'אין נמענים תואמים (נדרשת הסכמת דיוור)'];
        }

        $daily_cap = max(1, (int) WSN_Settings::get('camp_daily_cap'));
        $start_ts = $c['scheduled_at'] ? strtotime($c['scheduled_at']) : strtotime(current_time('mysql'));
        $queued = 0;

        foreach ($recipients as $i => $r) {
            $day_offset = intdiv($i, $daily_cap);
            $body = WSN_Templates::render(
                $variants[array_rand($variants)],
                null,
                ['first_name' => $r['first_name'] ?: 'לקוח/ה', 'last_name' => (string) $r['last_name']]
            );
            $ok = WSN_Outbox::enqueue([
                'kind'         => 'campaign',
                'priority'     => 1,
                'campaign_id'  => $id,
                'contact_id'   => $r['id'],
                'phone'        => $r['phone_e164'],
                'body'         => $body,
                'event_key'    => 'camp-' . $id . '-' . $r['id'],
                'scheduled_at' => gmdate('Y-m-d H:i:s', $start_ts + $day_offset * DAY_IN_SECONDS),
                'expiry_hours' => 0, // קמפיין לא פג — נשלט ע"י pause/cancel
            ]);
            if ($ok) {
                $queued++;
            }
        }

        $wpdb->update(self::table(), [
            'status' => 'sending',
            'total_recipients' => $queued,
            'started_at' => current_time('mysql'),
        ], ['id' => $id]);
        return ['ok' => true, 'queued' => $queued];
    }

    public static function cancel(int $id): void
    {
        global $wpdb;
        $wpdb->query($wpdb->prepare(
            "UPDATE " . WSN_Outbox::table() . " SET status='cancelled', last_error='campaign cancelled'
             WHERE campaign_id=%d AND status IN ('queued','claimed')", $id
        ));
        $wpdb->update(self::table(), ['status' => 'cancelled', 'finished_at' => current_time('mysql')], ['id' => $id]);
    }

    public static function bump(int $id, string $col): void
    {
        global $wpdb;
        if (!in_array($col, ['sent_count', 'failed_count'], true)) {
            return;
        }
        $wpdb->query($wpdb->prepare("UPDATE " . self::table() . " SET $col = $col + 1 WHERE id=%d", $id));
        // סימון סיום כשכל הנמענים טופלו
        $wpdb->query($wpdb->prepare(
            "UPDATE " . self::table() . " SET status='done', finished_at=%s
             WHERE id=%d AND status='sending' AND sent_count + failed_count >= total_recipients",
            current_time('mysql'), $id
        ));
    }

    /** הערכת משך: כמה ימים ייקח קמפיין של $n נמענים */
    public static function estimate(int $n): string
    {
        $daily = max(1, (int) WSN_Settings::get('camp_daily_cap'));
        $avg_gap = ((int) WSN_Settings::get('camp_min_gap_s') + (int) WSN_Settings::get('camp_max_gap_s')) / 2;
        $days = (int) ceil($n / $daily);
        $per_day_minutes = (int) round(min($n, $daily) * $avg_gap / 60);
        if ($days <= 1) {
            return "כ-$per_day_minutes דקות בתוך חלון הקמפיינים";
        }
        return "כ-$days ימים (עד $daily ביום, כ-$per_day_minutes דקות ביום)";
    }
}
