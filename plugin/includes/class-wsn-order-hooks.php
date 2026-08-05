<?php
defined('ABSPATH') || exit;

class WSN_Order_Hooks
{
    public static function init(): void
    {
        add_action('woocommerce_order_status_changed', [__CLASS__, 'on_status_changed'], 20, 4);
        add_action('woocommerce_checkout_create_order', [__CLASS__, 'on_checkout_create_order'], 10, 2);
        add_filter('woocommerce_checkout_fields', [__CLASS__, 'add_optin_field']);
        add_action('woocommerce_after_order_object_save', [__CLASS__, 'on_order_saved']);
    }

    public static function on_status_changed(int $order_id, string $old, string $new, $order): void
    {
        if (!$order instanceof WC_Order) {
            $order = wc_get_order($order_id);
            if (!$order) {
                return;
            }
        }

        // מועדון: upsert תמיד, סטטיסטיקות בסטטוס בתשלום
        WSN_Contacts::upsert_from_order($order);
        if (in_array($new, wc_get_is_paid_statuses(), true)) {
            WSN_Contacts::record_paid_order($order);
        }

        // ביטול הזמנה — לוכדים סיבה מהמנהל (מודאל), וההודעה מורכבת מהסיבה דרך
        // תיבת "בנה הודעה" בעמוד ההזמנה (ולא מתבנית סטטוס). reasoned + notified=0.
        if ($new === 'cancelled') {
            WSN_Item_Events::record([
                'order_id'   => $order->get_id(),
                'event_type' => WSN_Item_Events::TYPE_CANCELLED,
                'item_name'  => 'ביטול הזמנה',
                'old_value'  => wc_get_order_status_name($old),
                'new_value'  => wc_get_order_status_name($new),
                'notified'   => 0,
            ]);
            return;
        }

        // הודעת סטטוס נוצרת כ*טיוטה לאישור* — לא נשלחת מעצמה. תנאי: תבנית מופעלת
        // לסטטוס, טלפון תקין, ולקוח שלא הוסר מהתפוצה. המנהל מאשר במסך "הודעות ממתינות".
        $tpl = WSN_Templates::get('wc-' . $new);
        $phone = WSN_Phone::to_e164($order->get_billing_phone());
        if ($tpl && $phone && !WSN_Contacts::is_unsubscribed($phone)) {
            $body = WSN_Templates::render(WSN_Templates::pick_variant($tpl), $order);
            $id = WSN_Outbox::enqueue([
                'kind'      => 'status',
                'order_id'  => $order->get_id(),
                'phone'     => $phone,
                'body'      => $body,
                'status'    => 'draft',
                'event_key' => 'order-' . $order->get_id() . '-status-' . $new,
            ]);
            if ($id) {
                $order->add_order_note('וואטסאפ: הוכנה טיוטת הודעת סטטוס "' . wc_get_order_status_name($new) . '" — ממתינה לאישור');
            }
        }

        // תיעוד ביומן "תנועות הזמנה" (notified=1 — ההודעה כבר קיימת כטיוטה)
        WSN_Item_Events::record([
            'order_id'   => $order->get_id(),
            'event_type' => WSN_Item_Events::TYPE_STATUS,
            'item_name'  => 'סטטוס הזמנה',
            'old_value'  => wc_get_order_status_name($old),
            'new_value'  => wc_get_order_status_name($new),
            'notified'   => 1,
        ]);
    }

    // תיקון מספר טלפון בהזמנה (למשל טעות הקלדה) אחרי שהודעה כבר נכנסה לתור —
    // בלי זה, הודעה שממתינה ל-retry ממשיכה לנסות את המספר הישן שנשמר בעת ההוספה לתור
    public static function on_order_saved($order): void
    {
        if (!$order instanceof WC_Order) {
            return;
        }
        $phone = WSN_Phone::to_e164($order->get_billing_phone());
        if (!$phone) {
            return;
        }
        WSN_Outbox::update_phone_for_order($order->get_id(), $phone);
    }

    public static function on_checkout_create_order($order, $data): void
    {
        // snapshot הפריטים המקוריים — הבסיס ל-diff של אזל/הוחלף
        $snapshot = [];
        foreach ($order->get_items() as $item_id => $item) {
            $snapshot[] = [
                'product_id'   => $item->get_product_id(),
                'variation_id' => $item->get_variation_id(),
                'name'         => $item->get_name(),
                'qty'          => $item->get_quantity(),
            ];
        }
        $order->update_meta_data('_wsn_items_snapshot', wp_json_encode($snapshot, JSON_UNESCAPED_UNICODE));

        if ((int) WSN_Settings::get('checkout_optin_enabled')
            && !empty($_POST['wsn_marketing_optin'])) { // phpcs:ignore WordPress.Security.NonceVerification
            $order->update_meta_data('_wsn_marketing_optin', 'yes');
        }
    }

    public static function add_optin_field(array $fields): array
    {
        if ((int) WSN_Settings::get('checkout_optin_enabled')) {
            $fields['billing']['wsn_marketing_optin'] = [
                'type'     => 'checkbox',
                'label'    => (string) WSN_Settings::get('checkout_optin_label'),
                'required' => false,
                'default'  => 0, // חוק הספאם: ברירת מחדל לא מסומן
                'priority' => 200,
            ];
        }
        return $fields;
    }
}
