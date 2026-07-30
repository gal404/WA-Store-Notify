<?php
defined('ABSPATH') || exit;

class WSN_Outbox
{
    /**
     * עדיפות שמסמנת "המנהל דחף את ההודעה הזו במפורש" — נקבעת רק בלחיצה ידנית
     * ("שלח מיידית" ביומן, "שלח לי לבדיקה" בתבניות). הודעות כאלה עוקפות השהיה
     * כללית, כי ההשהיה נועדה לעצור שליחה אוטומטית — לא פעולה שהמנהל ביקש עכשיו.
     */
    const PRIORITY_FORCED = -10;

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
        // 'draft' = ממתינה לאישור המנהל; הגשר תובע רק 'queued', לכן טיוטה לא נשלחת
        $status = ($args['status'] ?? 'queued') === 'draft' ? 'draft' : 'queued';
        $row = [
            'kind'         => $args['kind'] ?? 'status',
            'priority'     => (int) ($args['priority'] ?? 0),
            'order_id'     => $args['order_id'] ?? null,
            'campaign_id'  => $args['campaign_id'] ?? null,
            'contact_id'   => $args['contact_id'] ?? null,
            'phone_e164'   => $phone,
            'body'         => trim($args['body']),
            'status'       => $status,
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

        if (!$kinds || $max < 1) {
            return [];
        }

        // בהשהיה כללית עדיין משחררים הודעות שהמנהל דחף במפורש (PRIORITY_FORCED) —
        // אחרת "שלח מיידית" נראה כאילו עבד אבל ההודעה נתקעת בתור בלי הסבר.
        $forced_only = (int) WSN_Settings::get('paused')
            ? ' AND priority <= ' . (int) self::PRIORITY_FORCED
            : '';

        // 3. תביעה אטומית — UPDATE עם ORDER BY+LIMIT על טבלה אחת
        $token = wp_generate_uuid4();
        $kind_ph = implode(',', array_fill(0, count($kinds), '%s'));
        $params = array_merge([$token, $now], $kinds, [$now, min($max, 10)]);
        // סדר שליחה: קודם עדיפות (נדחף לפני רגיל), ואז לפי מתי נכנס לתור
        // (scheduled_at נחתם ברגע האישור) — כך "מה שאושר קודם נשלח קודם" (FIFO).
        $wpdb->query($wpdb->prepare(
            "UPDATE $t SET status='claimed', claim_token=%s, claim_expires_at=DATE_ADD(%s, INTERVAL 10 MINUTE)
             WHERE status='queued' AND kind IN ($kind_ph) AND scheduled_at <= %s $forced_only
             ORDER BY priority ASC, scheduled_at ASC, id ASC LIMIT %d", $params
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

    /** ביטול הודעות נבחרות (פעולת מנהל מיומן ההודעות, בודדת או קבוצתית) — רק אם עוד לא נשלחו */
    public static function cancel_by_ids(array $ids): int
    {
        $ids = array_values(array_filter(array_map('intval', $ids)));
        if (!$ids) {
            return 0;
        }
        global $wpdb;
        $ph = implode(',', array_fill(0, count($ids), '%d'));
        return (int) $wpdb->query($wpdb->prepare(
            "UPDATE " . self::table() . " SET status='cancelled', last_error='בוטל ידנית', claim_token=NULL, claim_expires_at=NULL
             WHERE status IN ('queued','claimed') AND id IN ($ph)", $ids
        ));
    }

    /** מכריח שליחה מיידית: מאפס תזמון ומעלה עדיפות, כדי שהגשר יתפוס בסבב הבא — עוקף המתנת backoff */
    public static function send_now_by_ids(array $ids): int
    {
        $ids = array_values(array_filter(array_map('intval', $ids)));
        if (!$ids) {
            return 0;
        }
        global $wpdb;
        $ph = implode(',', array_fill(0, count($ids), '%d'));
        return (int) $wpdb->query($wpdb->prepare(
            "UPDATE " . self::table() . " SET scheduled_at=%s, priority=" . (int) self::PRIORITY_FORCED . "
             WHERE status='queued' AND id IN ($ph)",
            array_merge([current_time('mysql')], $ids)
        ));
    }

    // ===== שכבת אישור (טיוטות): הודעה נבנית כ-draft, המנהל רואה/עורך/מאשר =====

    /** טיוטות שממתינות לאישור המנהל — עם גוף ההודעה להצגה ולעריכה */
    public static function list_drafts(int $limit = 100): array
    {
        global $wpdb;
        return (array) $wpdb->get_results($wpdb->prepare(
            "SELECT id, kind, phone_e164, body, order_id, created_at
             FROM " . self::table() . "
             WHERE status='draft'
             ORDER BY id ASC LIMIT %d", $limit
        ), ARRAY_A);
    }

    public static function get(int $id): ?array
    {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM " . self::table() . " WHERE id=%d", $id
        ), ARRAY_A);
        return $row ?: null;
    }

    /** טיוטות של הזמנה מסוימת — לאישור מתוך עמוד ההזמנה */
    public static function drafts_for_order(int $order_id): array
    {
        global $wpdb;
        return (array) $wpdb->get_results($wpdb->prepare(
            "SELECT id, kind, phone_e164, body, order_id, created_at
             FROM " . self::table() . "
             WHERE status='draft' AND order_id=%d ORDER BY id ASC", $order_id
        ), ARRAY_A);
    }

    /** הודעות שאושרו וממתינות לשליחה (בתור) להזמנה — לתצוגת "מושהות" עם טיימר */
    public static function scheduled_for_order(int $order_id): array
    {
        global $wpdb;
        return (array) $wpdb->get_results($wpdb->prepare(
            "SELECT id, body, status, scheduled_at, attempts
             FROM " . self::table() . "
             WHERE order_id=%d AND status IN ('queued','claimed')
             ORDER BY scheduled_at ASC, id ASC", $order_id
        ), ARRAY_A);
    }

    /** היסטוריית שליחה של הזמנה (נשלח/נכשל/בוטל) — עם תאריך ושעה */
    public static function history_for_order(int $order_id, int $limit = 20): array
    {
        global $wpdb;
        return (array) $wpdb->get_results($wpdb->prepare(
            "SELECT id, body, status, sent_at, created_at, last_error
             FROM " . self::table() . "
             WHERE order_id=%d AND status IN ('sent','failed','cancelled','expired')
             ORDER BY id DESC LIMIT %d", $order_id, $limit
        ), ARRAY_A);
    }

    /** עריכת גוף ההודעה — רק לטיוטה שעדיין לא אושרה */
    public static function update_body(int $id, string $body): bool
    {
        $body = trim($body);
        if ($body === '') {
            return false;
        }
        global $wpdb;
        $res = $wpdb->update(
            self::table(),
            ['body' => $body],
            ['id' => $id, 'status' => 'draft']
        );
        return $res !== false;
    }

    const CUST_GAP_MIN_DEFAULT = 300; // 5 דק
    const CUST_GAP_MAX_DEFAULT = 600; // 10 דק

    /**
     * אישור טיוטות → מכניס לתור (queued) בעדיפות "נדחף". השליחה מרווחת פר-לקוח:
     * כל הודעה מתוזמנת לפחות gap אקראי (5–10 דק) אחרי ההודעה הקודמת *לאותו טלפון*
     * (ממתינה או שנשלחה). הודעה ראשונה ללקוח — נשלחת מיד. מחזיר [id => scheduled_at].
     */
    public static function approve(array $ids): array
    {
        $ids = array_values(array_filter(array_map('intval', $ids)));
        if (!$ids) {
            return [];
        }
        global $wpdb;
        $t = self::table();
        $now = current_time('mysql');
        $now_ts = strtotime($now);

        $min = (int) WSN_Settings::get('cust_gap_min_s');
        $max = (int) WSN_Settings::get('cust_gap_max_s');
        if ($min <= 0) { $min = self::CUST_GAP_MIN_DEFAULT; }
        if ($max < $min) { $max = max($min, self::CUST_GAP_MAX_DEFAULT); }

        $out = [];
        foreach ($ids as $id) {
            $phone = $wpdb->get_var($wpdb->prepare(
                "SELECT phone_e164 FROM $t WHERE id=%d AND status='draft'", $id
            ));
            if ($phone === null) {
                continue;
            }
            // הזמן האחרון שבו כבר יש/הייתה הודעה לאותו לקוח (ממתינה בתור או שנשלחה)
            $last = $wpdb->get_var($wpdb->prepare(
                "SELECT MAX(t) FROM (
                    SELECT scheduled_at t FROM $t WHERE phone_e164=%s AND status IN ('queued','claimed')
                    UNION ALL
                    SELECT sent_at t FROM $t WHERE phone_e164=%s AND status='sent' AND sent_at IS NOT NULL
                 ) x",
                $phone, $phone
            ));
            $sched_ts = $now_ts;
            if ($last) {
                $gap = random_int($min, $max); // אקראי לשנייה — 5:00 עד 10:00
                $sched_ts = max($now_ts, strtotime($last) + $gap);
            }
            // gmdate עקבי עם שאר הקוד (enqueue משתמש באותו דפוס מול current_time)
            $sched = gmdate('Y-m-d H:i:s', $sched_ts);
            $wpdb->update($t,
                ['status' => 'queued', 'priority' => self::PRIORITY_FORCED, 'scheduled_at' => $sched],
                ['id' => $id, 'status' => 'draft']
            );
            $out[$id] = $sched;
        }
        return $out;
    }

    /** מחיקת טיוטות שלא יישלחו (נשמר כ-cancelled לתיעוד) */
    public static function discard(array $ids): int
    {
        $ids = array_values(array_filter(array_map('intval', $ids)));
        if (!$ids) {
            return 0;
        }
        global $wpdb;
        $ph = implode(',', array_fill(0, count($ids), '%d'));
        return (int) $wpdb->query($wpdb->prepare(
            "UPDATE " . self::table() . " SET status='cancelled', last_error='נמחק לפני שליחה'
             WHERE status='draft' AND id IN ($ph)", $ids
        ));
    }

    public static function draft_count(): int
    {
        global $wpdb;
        return (int) $wpdb->get_var("SELECT COUNT(*) FROM " . self::table() . " WHERE status='draft'");
    }

    /** סטטוסים "סגורים" — הודעות שכבר טופלו ולא ימתינו יותר (היסטוריה) */
    const DONE_STATUSES = ['sent', 'failed', 'cancelled', 'expired'];

    /**
     * היסטוריית הודעות שכבר טופלו, מדפדפת — לתצוגה בדשבורד הגשר.
     * מחזיר גם total/pages כדי שהדשבורד יוכל לצייר ניווט עמודים בלי קריאה נוספת.
     */
    public static function list_history(int $page = 1, int $per = 10): array
    {
        global $wpdb;
        $per = min(50, max(1, $per));
        $page = max(1, $page);
        $t = self::table();
        // רשימה קבועה בקוד (לא קלט משתמש) — בטוח לשלב ישירות בשאילתה
        $done = "'" . implode("','", self::DONE_STATUSES) . "'";

        $total = (int) $wpdb->get_var("SELECT COUNT(*) FROM $t WHERE status IN ($done)");
        $pages = $total > 0 ? (int) ceil($total / $per) : 1;
        // עמוד מעבר לסוף (למשל אחרי ניקוי יומן) — מחזירים את האחרון במקום רשימה ריקה
        $page = min($page, $pages);
        $offset = ($page - 1) * $per;

        $items = (array) $wpdb->get_results($wpdb->prepare(
            "SELECT id, kind, phone_e164, status, attempts, order_id, last_error, sent_at, created_at
             FROM $t WHERE status IN ($done)
             ORDER BY id DESC LIMIT %d OFFSET %d",
            $per, $offset
        ), ARRAY_A);

        return ['items' => $items, 'total' => $total, 'page' => $page, 'per' => $per, 'pages' => $pages];
    }

    /** רשימת הודעות ממתינות/בטיפול לתצוגה בדשבורד הגשר — קריאה בלבד, לא תובעת */
    public static function list_pending(int $limit = 20): array
    {
        global $wpdb;
        return (array) $wpdb->get_results($wpdb->prepare(
            "SELECT id, kind, priority, phone_e164, body, status, attempts, order_id, scheduled_at, created_at
             FROM " . self::table() . "
             WHERE status IN ('queued','claimed')
             ORDER BY priority ASC, scheduled_at ASC, id ASC
             LIMIT %d", $limit
        ), ARRAY_A);
    }

    /** מסנכרן מספר טלפון בהודעות שעדיין בתור — למשל אחרי תיקון טעות הקלדה בהזמנה */
    public static function update_phone_for_order(int $order_id, string $phone_e164): int
    {
        global $wpdb;
        return (int) $wpdb->update(
            self::table(),
            ['phone_e164' => $phone_e164],
            ['order_id' => $order_id, 'status' => 'queued']
        );
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
        $out = ['draft' => 0, 'queued' => 0, 'claimed' => 0, 'sent' => 0, 'failed' => 0, 'cancelled' => 0, 'expired' => 0];
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
