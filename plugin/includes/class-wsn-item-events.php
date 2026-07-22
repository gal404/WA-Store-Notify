<?php
defined('ABSPATH') || exit;

/**
 * יומן תנועות פריטים בהזמנה: שינוי כמות, הוספה והסרה — עם הערך הקודם והחדש.
 * הסיבה נאספת מהמנהל אחרי הפעולה (מודאל), ולכן אירוע נשמר קודם עם reason_code
 * ריק ומסומן כ"ממתין לסיבה". הנתונים האלה הם הבסיס להודעות אוטומטיות על
 * שינויים בהזמנה.
 */
class WSN_Item_Events
{
    const TYPE_QTY     = 'qty_changed';
    const TYPE_ADDED   = 'item_added';
    const TYPE_REMOVED = 'item_removed';
    const TYPE_STATUS  = 'status_changed';
    const TYPE_NOTE    = 'note_changed';

    /** רק תנועות-פריטים דורשות בחירת סיבה מהמנהל (סטטוס/הערה — לא) */
    const REASONED_TYPES = [self::TYPE_QTY, self::TYPE_ADDED, self::TYPE_REMOVED];

    /** ברירות מחדל — משמשות עד שהמנהל עורך את הרשימות במסך ההגדרות */
    const DEFAULT_REASONS_REMOVED = "אזל מהמלאי\nלבקשת הלקוח\nללא סיבה";
    const DEFAULT_REASONS_ADDED   = "לבקשת הלקוח\nהחלפה למוצר אחר\nללא סיבה";

    /**
     * הסיבות האפשריות לסוג אירוע, נקראות מההגדרות כדי שאפשר יהיה להוסיף
     * ולשנות בלי לגעת בקוד. הקוד נגזר מהתווית (slug יציב), ותמיד נשמרת גם
     * התווית עצמה — כך שגם אם הרשימה תשתנה בעתיד, ההיסטוריה נשארת קריאה.
     * 'other' תמיד קיים ודורש טקסט חופשי.
     */
    public static function reasons(string $type): array
    {
        $setting = $type === self::TYPE_ADDED ? 'item_reasons_added' : 'item_reasons_removed';
        $raw = (string) WSN_Settings::get($setting);
        $out = [];
        foreach (preg_split('/\r\n|\r|\n/', $raw) as $line) {
            $label = trim($line);
            if ($label === '') {
                continue;
            }
            $code = self::code_for($label);
            $out[$code] = $label;
        }
        $out['other'] = 'אחר';
        return $out;
    }

    /** קוד יציב לתווית. sanitize_title מרוקן מחרוזות בעברית, ולכן fallback ל-hash */
    public static function code_for(string $label): string
    {
        $slug = sanitize_title($label);
        if ($slug === '') {
            $slug = 'r' . substr(md5($label), 0, 10);
        }
        return substr($slug, 0, 30);
    }

    public static function table(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'wsn_item_events';
    }

    public static function record(array $args): ?int
    {
        global $wpdb;
        $order_id = (int) ($args['order_id'] ?? 0);
        if (!$order_id) {
            return null;
        }
        $ok = $wpdb->insert(self::table(), [
            'order_id'      => $order_id,
            'order_item_id' => (int) ($args['order_item_id'] ?? 0) ?: null,
            'event_type'    => (string) $args['event_type'],
            'product_id'    => (int) ($args['product_id'] ?? 0) ?: null,
            'variation_id'  => (int) ($args['variation_id'] ?? 0) ?: null,
            'item_name'     => mb_substr((string) ($args['item_name'] ?? ''), 0, 255),
            'qty_before'    => array_key_exists('qty_before', $args) ? $args['qty_before'] : null,
            'qty_after'     => array_key_exists('qty_after', $args) ? $args['qty_after'] : null,
            'old_value'     => array_key_exists('old_value', $args) ? (string) $args['old_value'] : null,
            'new_value'     => array_key_exists('new_value', $args) ? (string) $args['new_value'] : null,
            'notified'      => array_key_exists('notified', $args) ? (int) $args['notified'] : 0,
            'user_id'       => get_current_user_id() ?: null,
            'created_at'    => current_time('mysql'),
        ]);
        return $ok ? (int) $wpdb->insert_id : null;
    }

    /** אירועים שעדיין ממתינים לסיבה מהמנהל — רק תנועות-פריטים (סטטוס/הערה אין להם סיבה) */
    public static function pending(int $order_id): array
    {
        global $wpdb;
        $types = self::REASONED_TYPES;
        $ph = implode(',', array_fill(0, count($types), '%s'));
        return (array) $wpdb->get_results($wpdb->prepare(
            "SELECT id, event_type, item_name, qty_before, qty_after
             FROM " . self::table() . "
             WHERE order_id = %d AND reason_code IS NULL AND event_type IN ($ph)
             ORDER BY id ASC",
            array_merge([$order_id], $types)
        ), ARRAY_A);
    }

    /** תנועות שטרם נשלחה עליהן הודעה ללקוח */
    public static function unnotified(int $order_id): array
    {
        global $wpdb;
        return (array) $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM " . self::table() . "
             WHERE order_id = %d AND notified = 0
             ORDER BY id ASC",
            $order_id
        ), ARRAY_A);
    }

    /** סימון תנועות כ"דווחו ללקוח", כדי שלא יישלחו שוב */
    public static function mark_notified(array $ids): int
    {
        $ids = array_values(array_filter(array_map('intval', $ids)));
        if (!$ids) {
            return 0;
        }
        global $wpdb;
        $ph = implode(',', array_fill(0, count($ids), '%d'));
        return (int) $wpdb->query($wpdb->prepare(
            "UPDATE " . self::table() . " SET notified = 1 WHERE id IN ($ph)",
            $ids
        ));
    }

    public static function get(int $id): ?array
    {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM " . self::table() . " WHERE id = %d",
            $id
        ), ARRAY_A);
        return $row ?: null;
    }

    /** כל התנועות של ההזמנה, לתצוגה בעמוד ההזמנה */
    public static function for_order(int $order_id): array
    {
        global $wpdb;
        return (array) $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM " . self::table() . " WHERE order_id = %d ORDER BY id DESC",
            $order_id
        ), ARRAY_A);
    }

    /**
     * שמירת סיבה לאירוע. מאמת שהקוד שייך לסוג האירוע, כדי שלא יישמר קוד
     * שאינו קיים ברשימה (למשל 'replacement' על הסרה).
     */
    public static function set_reason(int $event_id, string $code, string $text = ''): bool
    {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT event_type FROM " . self::table() . " WHERE id = %d",
            $event_id
        ), ARRAY_A);
        if (!$row) {
            return false;
        }
        $allowed = self::reasons($row['event_type']);
        if (!isset($allowed[$code])) {
            return false;
        }
        // שומרים גם את התווית: אם הרשימה בהגדרות תשתנה, ההיסטוריה תישאר קריאה
        $label = $code === 'other' ? mb_substr($text, 0, 255) : $allowed[$code];
        $res = $wpdb->update(
            self::table(),
            ['reason_code' => $code, 'reason_text' => $label],
            ['id' => $event_id]
        );
        // update מחזיר 0 גם כשהשורה כבר מכילה את אותו ערך (שמירה חוזרת) —
        // רק false הוא שגיאת SQL אמיתית, ולכן זה מבחן ההצלחה הנכון.
        return $res !== false;
    }

    /** התווית להצגה: מה שנשמר בעת הבחירה, ואם חסר — מהרשימה הנוכחית */
    public static function reason_label(array $e): string
    {
        if (!empty($e['reason_text'])) {
            return (string) $e['reason_text'];
        }
        if (empty($e['reason_code'])) {
            return '';
        }
        $all = self::reasons($e['event_type']);
        return $all[$e['reason_code']] ?? (string) $e['reason_code'];
    }

    /** תיאור קריא של התנועה, לשימוש בממשק ובהודעות */
    public static function describe(array $e): string
    {
        $name = $e['item_name'];
        switch ($e['event_type']) {
            case self::TYPE_ADDED:
                return sprintf('נוסף %s (כמות %d)', $name, (int) $e['qty_after']);
            case self::TYPE_REMOVED:
                return sprintf('הוסר %s (כמות %d)', $name, (int) $e['qty_before']);
            case self::TYPE_STATUS:
                return sprintf('סטטוס הזמנה: %s ← %s', (string) ($e['old_value'] ?? ''), (string) ($e['new_value'] ?? ''));
            case self::TYPE_NOTE:
                return 'הערת הלקוח עודכנה';
            default:
                return sprintf('%s: כמות %d ← %d', $name, (int) $e['qty_before'], (int) $e['qty_after']);
        }
    }
}
