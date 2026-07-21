<?php
defined('ABSPATH') || exit;

class WSN_Templates
{
    /** מפתחות תבניות שאינן סטטוס הזמנה */
    const SPECIAL_KEYS = ['item_added', 'item_removed', 'qty_changed', 'order_changes', 'item_replaced', 'optout_confirm'];

    public static function defaults(): array
    {
        return [
            'wc-processing' => [
                'enabled'  => 1,
                'variants' => [
                    "היי {first_name}, ההזמנה שלך #{order_number} התקבלה בהצלחה 🎉 נעדכן אותך ברגע שתהיה מוכנה.",
                    "שלום {first_name}! קיבלנו את הזמנה #{order_number} ואנחנו כבר עובדים עליה. נעדכן בהמשך 😊",
                ],
            ],
            'wc-completed' => [
                'enabled'  => 1,
                'variants' => [
                    "היי {first_name}, הזמנה #{order_number} טופלה ונסגרה. תודה שקנית אצלנו! 🙏",
                    "{first_name} היקר/ה, סיימנו לטפל בהזמנה #{order_number}. נשמח לראותך שוב!",
                ],
            ],
            'item_added' => [
                'enabled'  => 1,
                'variants' => [
                    "היי {first_name}, עודכנה ההזמנה שלך #{order_number} — נוספו הפריטים הבאים:
{changes}
נשמח לעזור אם יש שאלות.",
                ],
            ],
            'qty_changed' => [
                'enabled'  => 1,
                'variants' => [
                    "היי {first_name}, עודכנו הכמויות בהזמנה #{order_number}:
{changes}
אם משהו לא מתאים — השב לנו כאן.",
                ],
            ],
            'order_changes' => [
                'enabled'  => 1,
                'variants' => [
                    "היי {first_name}, בוצעו עדכונים בהזמנה שלך #{order_number}:
{changes}
אם משהו לא מתאים — פשוט השב לנו כאן.",
                ],
            ],
            'item_removed' => [
                'enabled'  => 1,
                'variants' => [
                    "היי {first_name}, לצערנו הפריט {removed_item} מהזמנה #{order_number} אזל מהמלאי. ההזמנה עודכנה בהתאם ונשמח לעזור אם יש שאלות.",
                ],
            ],
            'item_replaced' => [
                'enabled'  => 1,
                'variants' => [
                    "היי {first_name}, בהזמנה #{order_number} הפריט {removed_item} אזל מהמלאי והוחלף ב-{new_item}. אם ההחלפה לא מתאימה — פשוט השב לנו כאן.",
                ],
            ],
            'optout_confirm' => [
                'enabled'  => 1,
                'variants' => [
                    "הוסרת מרשימת התפוצה שלנו ולא תקבל מאיתנו הודעות נוספות. אם זו הייתה טעות — כתוב לנו כאן.",
                ],
            ],
        ];
    }

    public static function all(): array
    {
        return array_replace(self::defaults(), (array) get_option('wsn_templates', []));
    }

    public static function get(string $key): ?array
    {
        $tpl = self::all()[$key] ?? null;
        if (!$tpl || empty($tpl['enabled']) || empty($tpl['variants'])) {
            return null;
        }
        $tpl['variants'] = array_values(array_filter(array_map('trim', $tpl['variants']), fn($v) => $v !== ''));
        return $tpl['variants'] ? $tpl : null;
    }

    public static function save(array $templates): void
    {
        update_option('wsn_templates', $templates);
    }

    public static function pick_variant(array $tpl): string
    {
        return $tpl['variants'][array_rand($tpl['variants'])];
    }

    /**
     * רינדור placeholders מתוך הזמנה. $extra דורס/מוסיף (removed_item, new_item וכו').
     * שורות שמכילות placeholder של מעקב שנשאר ריק — נמחקות מהטקסט.
     */
    public static function render(string $text, ?WC_Order $order, array $extra = []): string
    {
        $vals = [
            'store_name' => get_bloginfo('name'),
        ];
        if ($order) {
            $items = [];
            foreach ($order->get_items() as $item) {
                $items[] = $item->get_name() . ' × ' . $item->get_quantity();
            }
            $status = $order->get_status();
            $vals += [
                'first_name'      => $order->get_billing_first_name() ?: 'לקוח/ה',
                'last_name'       => $order->get_billing_last_name(),
                'order_number'    => $order->get_order_number(),
                'order_total'     => html_entity_decode(wp_strip_all_tags(wc_price($order->get_total()))),
                'items'           => implode(', ', $items),
                'status_name'     => wc_get_order_status_name($status),
                'tracking_number' => '',
                'tracking_url'    => '',
            ];
            if (class_exists('WSN_Tracking')) {
                $t = WSN_Tracking::get($order);
                $vals['tracking_number'] = $t['number'];
                $vals['tracking_url']    = $t['url'];
            }
        }
        $vals = array_merge($vals, $extra);

        foreach ($vals as $k => $v) {
            $text = str_replace('{' . $k . '}', (string) $v, $text);
        }

        // שורות עם placeholder מעקב שלא מולא — להסיר, לא לשלוח "מספר מעקב: "
        if (($vals['tracking_number'] ?? '') === '') {
            $lines = preg_split('/\r\n|\r|\n/', $text);
            $lines = array_filter($lines, fn($l) => strpos($l, '{tracking') === false);
            $text = implode("\n", $lines);
        }
        // placeholders שלא מולאו בכלל — לנקות
        $text = preg_replace('/\{[a-z_]+\}/', '', $text);
        return trim(preg_replace('/\n{3,}/', "\n\n", $text));
    }
}
