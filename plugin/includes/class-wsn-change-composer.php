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
            'order_status'    => 'שינוי סטטוס הזמנה',
            'order_cancelled' => 'ביטול הזמנה',
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
            default:                            return 'qty_changed';
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
     * הודעה אחת שמאגדת כמה שינויים. אם כל השינויים מאותו סוג, משתמשים
     * בתבנית של אותו סוג; אחרת בתבנית המאוחדת.
     */
    public static function compose(WC_Order $order, array $events): string
    {
        if (!$events) {
            return '';
        }
        $types = array_unique(array_map(
            static fn($e) => self::type_for_event($e['event_type']),
            $events
        ));
        $tpl_key = count($types) === 1 ? reset($types) : 'order_changes';

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
        $map = [
            implode("\n", array_map(static fn($l) => '• ' . $l, $lines)) => '{changes}',
            implode(', ', $lines)                                        => '{changes_list}',
            (string) $order->get_order_number()                          => '{order_number}',
            trim($order->get_billing_first_name() . ' ' . $order->get_billing_last_name()) => '{first_name} {last_name}',
            (string) $order->get_billing_first_name()                    => '{first_name}',
            (string) $order->get_billing_last_name()                     => '{last_name}',
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
        $key = array_key_exists($type, self::types()) ? $type : 'order_changes';
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
            // מפתח ייחודי לצירוף השינויים הזה — מונע כפילות אם נלחץ פעמיים
            'event_key' => 'chg-' . $order->get_id() . '-' . md5(implode(',', $ids) . '|' . $body),
        ]);
        if ($id) {
            WSN_Item_Events::mark_notified($ids);
        }
        return $id;
    }
}
