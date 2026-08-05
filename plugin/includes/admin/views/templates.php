<?php
defined('ABSPATH') || exit;
/** @var array $templates */
$statuses = wc_get_order_statuses(); // ['wc-processing' => 'בתהליך', ...]
$post = admin_url('admin-post.php');

$is_on = static function (string $key) use ($templates): bool {
    $tpl = $templates[$key] ?? null;
    if (!$tpl || empty($tpl['enabled'])) {
        return false;
    }
    return (bool) array_filter((array) ($tpl['variants'] ?? []), static fn($v) => trim((string) $v) !== '');
};

// שורה מכווצת שנפתחת לעריכה בלחיצה
$render_row = static function (string $key, string $label) use ($templates, $is_on): void {
    $tpl = $templates[$key] ?? ['enabled' => 0, 'variants' => []];
    $variants = !empty($tpl['variants']) ? $tpl['variants'] : [''];
    $on = $is_on($key);
    ?>
    <div class="wsn-tpl-row" data-key="<?php echo esc_attr($key); ?>">
        <button type="button" class="wsn-tpl-toggle" aria-expanded="false">
            <span class="wsn-tpl-caret" aria-hidden="true">▸</span>
            <span class="wsn-tpl-state <?php echo $on ? 'is-on' : 'is-off'; ?>"><?php echo $on ? '✅' : '⚪'; ?></span>
            <span class="wsn-tpl-title"><?php echo esc_html($label); ?></span>
            <code dir="ltr"><?php echo esc_html($key); ?></code>
        </button>
        <div class="wsn-tpl-body" hidden>
            <label class="wsn-tpl-enable"><input type="checkbox" name="tpl[<?php echo esc_attr($key); ?>][enabled]" value="1" <?php checked(!empty($tpl['enabled'])); ?>> ההודעה מופעלת (תיבנה ותופיע ל<b>אישור</b>)</label>
            <div class="wsn-variants">
                <?php foreach ($variants as $v): ?>
                    <textarea name="tpl[<?php echo esc_attr($key); ?>][variants][]" rows="3" dir="rtl"><?php echo esc_textarea($v); ?></textarea>
                <?php endforeach; ?>
            </div>
            <p>
                <button type="button" class="button wsn-add-variant">+ נוסח נוסף</button>
                <button type="button" class="button wsn-send-test" data-tpl-key="<?php echo esc_attr($key); ?>">שלח לי לבדיקה</button>
                <span class="wsn-test-status description"></span>
                <?php if (count($variants) < 2): ?><span class="description">מומלץ 2+ נוסחים — טקסטים זהים מעלים סיכון חסימה.</span><?php endif; ?>
            </p>
        </div>
    </div>
    <?php
};

// --- קבוצות ---
$change_group = [
    'tracking_update'    => 'עדכון מספר מעקב למשלוח',
    'item_removed'       => 'פריט אחד אזל מהמלאי',
    'item_removed_multi' => 'כמה פריטים אזלו מהמלאי (רשימה)',
    'item_added'         => 'נוסף פריט להזמנה',
    'qty_changed'        => 'שינוי כמות',
    'price_changed'      => 'שינוי מחיר',
    'order_cancelled'    => 'ביטול הזמנה',
    'item_replaced'      => 'פריט הוחלף באחר',
    'order_changes'      => 'כמה שינויים יחד',
    'customer_note'      => 'שינוי הערת לקוח',
];
$system_group = [
    'optout_confirm' => 'אישור הסרה מרשימת תפוצה',
];

// סטטוסים: מציגים את סטטוסי הליבה ואת המופעלים; השאר (מותאמים/ריקים) מוסתר
$core = ['wc-pending', 'wc-processing', 'wc-on-hold', 'wc-completed', 'wc-cancelled', 'wc-refunded'];
$shown_status = $hidden_status = [];
foreach ($statuses as $key => $label) {
    if (in_array($key, $core, true) || $is_on($key)) {
        $shown_status[$key] = $label;
    } else {
        $hidden_status[$key] = $label;
    }
}
?>
<div class="wrap wsn" dir="rtl">
    <h1>תבניות הודעה</h1>
    <?php WSN_Admin::nav('wsn-templates'); ?>
    <p class="description">
        כל שורה היא נוסח. לחיצה על שורה פותחת אותה לעריכה. הודעה "מופעלת" נבנית אוטומטית ומופיעה
        <a href="<?php echo esc_url(admin_url('admin.php?page=wsn-pending')); ?>"><b>לאישור</b></a> — לא נשלחת מעצמה.<br>
        placeholders: <code dir="ltr">{first_name} {last_name} {order_number} {order_total} {items} {status_name} {store_name} {shipping_method} {shipping_type} {tracking_number} {tracking_url}</code>,
        ובהודעות שינויים גם <code dir="ltr">{removed_item} {removed_list} {new_item} {changes}</code>
        (<span dir="rtl">{removed_list} = רשימה ממוספרת של הפריטים שאזלו</span>).
    </p>

    <form method="post" action="<?php echo esc_url($post); ?>">
        <?php wp_nonce_field('wsn_save_templates'); ?>
        <input type="hidden" name="action" value="wsn_save_templates">

        <h2>שינויים בהזמנה</h2>
        <div class="wsn-tpl-group">
            <?php foreach ($change_group as $k => $l) { $render_row($k, $l); } ?>
        </div>

        <h2>לפי סטטוס הזמנה</h2>
        <div class="wsn-tpl-group">
            <?php foreach ($shown_status as $k => $l) { $render_row($k, $l); } ?>
        </div>
        <?php if ($hidden_status): ?>
            <p><button type="button" class="button-link wsn-show-hidden">▸ הצג עוד <?php echo count($hidden_status); ?> סטטוסים שאינם בשימוש</button></p>
            <div class="wsn-tpl-group wsn-hidden-status" hidden>
                <?php foreach ($hidden_status as $k => $l) { $render_row($k, $l); } ?>
            </div>
        <?php endif; ?>

        <h2>מערכת</h2>
        <div class="wsn-tpl-group">
            <?php foreach ($system_group as $k => $l) { $render_row($k, $l); } ?>
        </div>

        <?php submit_button('שמור תבניות'); ?>
    </form>
</div>
