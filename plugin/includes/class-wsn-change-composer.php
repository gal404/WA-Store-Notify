<?php
defined('ABSPATH') || exit;

/**
 * מרכיב הודעות מתוך תנועות הפריטים שנרשמו. "אוטומטי" כאן = ההודעה נבנית
 * לבד; אם היא גם *נשלחת* לבד נקבע בהגדרות, לכל סוג שינוי בנפרד.
 */
class WSN_Change_Composer
{
    /** סוגי השינויים שאפשר לשלוט בהם בנפרד */
    public static function types(): array
    {
        return [
            'item_added'      => 'הוספת מוצר',
            'item_removed'    => 'הסרת מוצר',
            'qty_changed'     => 'שינוי כמות',
            'price_changed'   => 'שינוי מחיר',
            'order_status'    => 'שינוי סטטוס הזמנה',
            'order_cancelled' => 'ביטול הזמנה',
            'customer_note'   => 'שינוי הערת לקוח',
        ];
    }

    /** האם סוג מסוים נשלח אוטומטית, או רק בלחיצת המנהל */
    public static function is_auto(string $type): bool
    {
        return WSN_Settings::get('send_mode_' . $type) === 'auto';
    }

    /** ממפה סוג אירוע ביומן התנועות לסוג ההודעה */
    public static function type_for_event(string $event_type): string
    {
        switch ($event_type) {
            case WSN_Item_Events::TYPE_ADDED:   return 'item_added';
            case WSN_Item_Events::TYPE_REMOVED: return 'item_removed';
            case WSN_Item_Events::TYPE_PRICE:     return 'price_changed';
            case WSN_Item_Events::TYPE_CANCELLED: return 'order_cancelled';
            case WSN_Item_Events::TYPE_STATUS:    return 'order_status';
            case WSN_Item_Events::TYPE_NOTE:      return 'customer_note';
            default:                              return 'qty_changed';
        }
    }

    /** שורת תיאור אחת לשינוי, כולל הסיבה אם נבחרה */
    public static function line(array $event): string
    {
        $name = $event['item_name'];
        switch ($event['event_type']) {
            case WSN_Item_Events::TYPE_ADDED:
                $txt = sprintf('נוסף: %s (כמות %d)', $name, (int) $event['qty_after']);
                break;
            case WSN_Item_Events::TYPE_REMOVED:
                $txt = sprintf('הוסר: %s', $name);
                break;
            case WSN_Item_Events::TYPE_PRICE:
                $txt = sprintf('מחיר %s שונה מ-%s ל-%s', $name, (string) ($event['old_value'] ?? ''), (string) ($event['new_value'] ?? ''));
                break;
            case WSN_Item_Events::TYPE_CANCELLED:
                $txt = 'ההזמנה בוטלה';
                break;
            case WSN_Item_Events::TYPE_STATUS:
                // סטטוס/הערה — אין להם "סיבה", מחזירים ישירות
                return sprintf('סטטוס ההזמנה עודכן ל-%s', (string) ($event['new_value'] ?? ''));
            case WSN_Item_Events::TYPE_NOTE:
                $nv = trim((string) ($event['new_value'] ?? ''));
                return $nv !== '' ? sprintf('הערה להזמנה: %s', $nv) : 'הערת ההזמנה עודכנה';
            default:
                $txt = sprintf('%s: כמות שונתה מ-%d ל-%d', $name, (int) $event['qty_before'], (int) $event['qty_after']);
        }
        $reason = WSN_Item_Events::reason_label($event);
        // "ללא סיבה" הוא ציון פנימי — אין טעם להטריח בו את הלקוח
        if ($reason !== '' && $reason !== 'ללא סיבה') {
            $txt .= ' (' . $reason . ')';
        }
        return $txt;
    }

    /**
     * בוחר את מפתח התבנית לצירוף השינויים: תבנית של הסוג היחיד, ולהסרה מרובה
     * תבנית ייעודית (item_removed_multi). ערבוב סוגים → התבנית המאוחדת.
     */
    public static function template_key_for(array $events): string
    {
        $types = array_unique(array_map(
            static fn($e) => self::type_for_event($e['event_type']),
            $events
        ));
        if (count($types) !== 1) {
            return 'order_changes';
        }
        $key = reset($types);
        if ($key === 'item_removed' && count(self::removed_events($events)) > 1) {
            return 'item_removed_multi';
        }
        return $key;
    }

    /** רשימת אירועי ההסרה מתוך קבוצת האירועים */
    private static function removed_events(array $events): array
    {
        return array_values(array_filter(
            $events,
            static fn($e) => $e['event_type'] === WSN_Item_Events::TYPE_REMOVED
        ));
    }

    /** רשימה ממוספרת של שמות הפריטים שהוסרו: "1. ...\n2. ..." */
    public static function removed_list(array $events): string
    {
        $out = [];
        foreach (self::removed_events($events) as $i => $e) {
            $out[] = ($i + 1) . '. ' . (string) $e['item_name'];
        }
        return implode("\n", $out);
    }

    /**
     * הודעה אחת שמאגדת כמה שינויים. אם כל השינויים מאותו סוג, משתמשים
     * בתבנית של אותו סוג; אחרת בתבנית המאוחדת.
     */
    public static function compose(WC_Order $order, array $events): string
    {
        if (!$events) {
            return '';
        }
        $tpl_key = self::template_key_for($events);
        $tpl = WSN_Templates::get($tpl_key) ?: WSN_Templates::get('order_changes');
        if (!$tpl) {
            return '';
        }

        $lines = array_map([__CLASS__, 'line'], $events);
        return WSN_Templates::render(
            WSN_Templates::pick_variant($tpl),
            $order,
            [
                'changes'      => implode("\n", array_map(static fn($l) => '• ' . $l, $lines)),
                'changes_list' => implode(', ', $lines),
                'removed_item' => self::first_name_of($events, WSN_Item_Events::TYPE_REMOVED),
                'removed_list' => self::removed_list($events),
                'removed_count' => (string) count(self::removed_events($events)),
                'new_item'     => self::first_name_of($events, WSN_Item_Events::TYPE_ADDED),
            ]
        );
    }

    /**
     * הופך נוסח שנערך ידנית (עם ערכים אמיתיים) חזרה לתבנית עם placeholders,
     * כדי שאפשר יהיה לשמור אותו כנוסח קבוע בלי לקבע את שם הלקוח ומספר ההזמנה
     * של הזמנה אחת. מחליף מהארוך לקצר כדי שערך קצר לא יבלע ערך ארוך שמכיל אותו.
     */
    public static function templatize(string $body, WC_Order $order, array $events): string
    {
        $lines = array_map([__CLASS__, 'line'], $events);
        // מהארוך לקצר: רשימת ההסרה (בלוק רב-שורתי) קודם, אחר כך בלוקים ושמות
        $map = [
            self::removed_list($events)                                  => '{removed_list}',
            implode("\n", array_map(static fn($l) => '• ' . $l, $lines)) => '{changes}',
            implode(', ', $lines)                                        => '{changes_list}',
            (string) $order->get_order_number()                          => '{order_number}',
            trim($order->get_billing_first_name() . ' ' . $order->get_billing_last_name()) => '{first_name} {last_name}',
            (string) $order->get_billing_first_name()                    => '{first_name}',
            (string) $order->get_billing_last_name()                     => '{last_name}',
            self::first_name_of($events, WSN_Item_Events::TYPE_REMOVED)   => '{removed_item}',
            self::first_name_of($events, WSN_Item_Events::TYPE_ADDED)     => '{new_item}',
            (string) get_bloginfo('name')                                => '{store_name}',
        ];
        foreach ($map as $value => $placeholder) {
            $value = trim((string) $value);
            if ($value !== '') {
                $body = str_replace($value, $placeholder, $body);
            }
        }
        return $body;
    }

    /** שומר נוסח כתבנית הקבועה של סוג השינוי (יוצר אם עוד לא קיימת) */
    public static function save_template(string $type, string $template_body): bool
    {
        $valid = array_merge(array_keys(self::types()), WSN_Templates::SPECIAL_KEYS);
        $key = in_array($type, $valid, true) ? $type : 'order_changes';
        if (trim($template_body) === '') {
            return false;
        }
        $all = WSN_Templates::all();
        $all[$key] = [
            'enabled'  => 1,
            'variants' => [$template_body],
        ];
        WSN_Templates::save($all);
        return true;
    }

    private static function first_name_of(array $events, string $type): string
    {
        foreach ($events as $e) {
            if ($e['event_type'] === $type) {
                return (string) $e['item_name'];
            }
        }
        return '';
    }

    /**
     * מכניס לתור הודעה על השינויים שנבחרו ומסמן אותם כ"דווחו", כדי שלא
     * יישלחו פעמיים. מחזיר את מזהה ההודעה בתור, או null אם לא נשלח דבר.
     */
    public static function queue(WC_Order $order, array $events, string $body = ''): ?int
    {
        $body = $body !== '' ? $body : self::compose($order, $events);
        if (trim($body) === '') {
            return null;
        }
        $phone = WSN_Phone::to_e164($order->get_billing_phone());
        if (!$phone || WSN_Contacts::is_unsubscribed($phone)) {
            return null;
        }
        $ids = array_map(static fn($e) => (int) $e['id'], $events);
        sort($ids);
        $id = WSN_Outbox::enqueue([
            'kind'      => 'item_change',
            'order_id'  => $order->get_id(),
            'phone'     => $phone,
            'body'      => $body,
            'status'    => 'draft', // טיוטה לאישור — לא נשלח מעצמו
            // מפתח ייחודי לצירוף השינויים הזה — מונע כפילות אם נלחץ פעמיים
            'event_key' => 'chg-' . $order->get_id() . '-' . md5(implode(',', $ids) . '|' . $body),
        ]);
        if ($id) {
            WSN_Item_Events::mark_notified($ids);
        }
        return $id;
    }
}
