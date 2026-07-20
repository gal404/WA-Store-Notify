<?php
defined('ABSPATH') || exit;

class WSN_Outbox
{
    public static function table(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'wsn_outbox';
    }

    /**
     * הוספה לתור. אידמפוטנטי לפי event_key (hook כפול לא מכפיל הודעה).
     * מחזיר את מזהה השורה, או null אם דולג (כפילות/טלפון חסר).
     */
    public static function enqueue(array $args): ?int
    {
        global $wpdb;
        $phone = WSN_Phone::to_e164($args['phone'] ?? '');
        if (!$phone || trim($args['body'] ?? '') === '') {
            return null;
        }

        $expiry_hours = $args['expiry_hours'] ?? (int) WSN_Settings::get('expiry_hours');
        $row = [
            'kind'         => $args['kind'] ?? 'status',
            'priority'     => (int) ($args['priority'] ?? 0),
            'order_id'     => $args['order_id'] ?? null,
            'campaign_id'  => $args['campaign_id'] ?? null,
            'contact_id'   => $args['contact_id'] ?? null,
            'phone_e164'   => $phone,
            'body'         => trim($args['body']),
            'status'       => 'queued',
            'event_key'    => $args['event_key'] ?? null,
            'scheduled_at' => $args['scheduled_at'] ?? current_time('mysql'),
            'expires_at'   => $expiry_hours > 0
                ? gmdate('Y-m-d H:i:s', strtotime(current_time('mysql')) + $expiry_hours * HOUR_IN_SECONDS)
                : null,
            'max_attempts' => (int) ($args['max_attempts'] ?? 3),
            'created_at'   => current_time('mysql'),
        ];

        // עמודות NULL מושמטות לגמרי (ברירת המחדל שלהן NULL) — כדי ש-prepare
        // לא ימיר null למחרוזת ריקה ויפגע בעמודות bigint/datetime.
        $row = array_filter($row, fn($v) => $v !== null);

        // INSERT IGNORE — ה-UNIQUE על event_key בולע כפילויות בשקט
        $cols = implode(',', array_keys($row));
        $ph   = implode(',', array_fill(0, count($row), '%s'));
        $sql  = $wpdb->prepare("INSERT IGNORE INTO " . self::table() . " ($cols) VALUES ($ph)", array_values($row));
        $wpdb->query($sql);
        return $wpdb->insert_id ?: null;
    }

    /**
     * תביעת אצווה ע"י הגשר: שחרור תביעות שפגו, פקיעת ישנות, ותביעה אטומית.
     */
    public static function claim(string $worker_id, int $max, array $kinds): array
    {
        global $wpdb;
        $t = self::table();
        $now = current_time('mysql');

        // 1. שחרור תביעות שפג תוקפן (גשר שקרס אחרי claim)
        $wpdb->query($wpdb->prepare(
            "UPDATE $t SET status='queued', claim_token=NULL, claim_expires_at=NULL
             WHERE status='claimed' AND claim_expires_at < %s", $now
        ));

        // 2. פקיעת הודעות ישנות מדי
        $wpdb->query($wpdb->prepare(
            "UPDATE $t SET status='expired', last_error='expired before send'
             WHERE status='queued' AND expires_at IS NOT NULL AND expires_at < %s", $now
        ));

        if ((int) WSN_Settings::get('paused') || !$kinds || $max < 1) {
            return [];
        }

        // 3. תביעה אטומית — UPDATE עם ORDER BY+LIMIT על טבלה אחת
        $token = wp_generate_uuid4();
        $kind_ph = implode(',', array_fill(0, count($kinds), '%s'));
        $params = array_merge([$token, $now], $kinds, [$now, min($max, 10)]);
        $wpdb->query($wpdb->prepare(
            "UPDATE $t SET status='claimed', claim_token=%s, claim_expires_at=DATE_ADD(%s, INTERVAL 10 MINUTE)
             WHERE status='queued' AND kind IN ($kind_ph) AND scheduled_at <= %s
             ORDER BY priority ASC, id ASC LIMIT %d", $params
        ));

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT id, claim_token, kind, phone_e164 AS phone, body, order_id, campaign_id
             FROM $t WHERE claim_token = %s", $token
        ), ARRAY_A);
        return $rows ?: [];
    }

    /**
     * קליטת דיווח תוצאה מהגשר. אידמפוטנטי — token שלא תואם שורה claimed נבלע.
     */
    public static function report(array $result): bool
    {
        global $wpdb;
        $t = self::table();
        $id = (int) ($result['id'] ?? 0);
        $token = (string) ($result['claim_token'] ?? '');
        $status = (string) ($result['status'] ?? '');

        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $t WHERE id=%d AND claim_token=%s AND status='claimed'", $id, $token
        ), ARRAY_A);
        if (!$row) {
            return false;
        }

        $now = current_time('mysql');
        if ($status === 'sent') {
            $wpdb->update($t, [
                'status' => 'sent', 'sent_at' => $now,
                'wa_msg_id' => substr((string) ($result['wa_msg_id'] ?? ''), 0, 64),
                'claim_token' => null, 'claim_expires_at' => null,
            ], ['id' => $id]);
            if ($row['campaign_id']) {
                WSN_Campaigns::bump($row['campaign_id'], 'sent_count');
            }
            return true;
        }

        if ($status === 'invalid_number') {
            $wpdb->update($t, [
                'status' => 'failed', 'last_error' => 'המספר אינו רשום בוואטסאפ',
                'claim_token' => null, 'claim_expires_at' => null,
            ], ['id' => $id]);
            WSN_Contacts::mark_invalid($row['phone_e164']);
            if ($row['campaign_id']) {
                WSN_Campaigns::bump($row['campaign_id'], 'failed_count');
            }
            return true;
        }

        if ($status === 'skipped_optout') {
            $wpdb->update($t, [
                'status' => 'cancelled', 'last_error' => 'optout',
                'claim_token' => null, 'claim_expires_at' => null,
            ], ['id' => $id]);
            return true;
        }

        // failed — נסיון חוזר עם backoff או כשל סופי
        $attempts = (int) $row['attempts'] + 1;
        $error = substr((string) ($result['error'] ?? 'שגיאה לא ידועה'), 0, 255);
        if ($attempts >= (int) $row['max_attempts']) {
            $wpdb->update($t, [
                'status' => 'failed', 'attempts' => $attempts, 'last_error' => $error,
                'claim_token' => null, 'claim_expires_at' => null,
            ], ['id' => $id]);
            if ($row['campaign_id']) {
                WSN_Campaigns::bump($row['campaign_id'], 'failed_count');
            }
        } else {
            $backoff_min = $attempts === 1 ? 10 : 60;
            $wpdb->update($t, [
                'status' => 'queued', 'attempts' => $attempts, 'last_error' => $error,
                'scheduled_at' => gmdate('Y-m-d H:i:s', strtotime($now) + $backoff_min * MINUTE_IN_SECONDS),
                'claim_token' => null, 'claim_expires_at' => null,
            ], ['id' => $id]);
        }
        return true;
    }

    /** ביטול הודעה בודדת (פעולת מנהל מיומן ההודעות) — רק אם עוד לא נשלחה */
    public static function cancel_by_id(int $id): bool
    {
        global $wpdb;
        $n = $wpdb->query($wpdb->prepare(
            "UPDATE " . self::table() . " SET status='cancelled', last_error='בוטל ידנית', claim_token=NULL, claim_expires_at=NULL
             WHERE id=%d AND status IN ('queued','claimed')", $id
        ));
        return (bool) $n;
    }

    /** ביטול כל ההודעות הממתינות למספר (אחרי "הסר") */
    public static function cancel_for_phone(string $phone_e164): int
    {
        global $wpdb;
        return (int) $wpdb->query($wpdb->prepare(
            "UPDATE " . self::table() . " SET status='cancelled', last_error='optout'
             WHERE phone_e164=%s AND status IN ('queued','claimed')", $phone_e164
        ));
    }

    public static function counts(): array
    {
        global $wpdb;
        $rows = $wpdb->get_results("SELECT status, COUNT(*) c FROM " . self::table() . " GROUP BY status", ARRAY_A);
        $out = ['queued' => 0, 'claimed' => 0, 'sent' => 0, 'failed' => 0, 'cancelled' => 0, 'expired' => 0];
        foreach ((array) $rows as $r) {
            $out[$r['status']] = (int) $r['c'];
        }
        return $out;
    }

    public static function purge_older_than(int $days): void
    {
        global $wpdb;
        $wpdb->query($wpdb->prepare(
            "DELETE FROM " . self::table() . "
             WHERE status IN ('sent','failed','cancelled','expired')
               AND created_at < DATE_SUB(%s, INTERVAL %d DAY)",
            current_time('mysql'), $days
        ));
    }
}
