<?php defined('ABSPATH') || exit;
/** @var array $templates */
// כל סטטוסי ההזמנה הקיימים (כולל מותאמים אישית) + התבניות המיוחדות
$statuses = wc_get_order_statuses(); // ['wc-processing' => 'בתהליך', ...]
$special = [
    'item_removed'   => 'פריט הוסר (אזל מהמלאי)',
    'item_replaced'  => 'פריט הוחלף',
    'optout_confirm' => 'אישור הסרה מרשימת תפוצה',
];
$post = admin_url('admin-post.php');

$render_card = function (string $key, string $label, array $templates) {
    $tpl = $templates[$key] ?? ['enabled' => 0, 'variants' => []];
    $variants = $tpl['variants'] ?: [''];
    ?>
    <div class="wsn-card wsn-tpl" data-key="<?php echo esc_attr($key); ?>">
        <h3>
            <label><input type="checkbox" name="tpl[<?php echo esc_attr($key); ?>][enabled]" value="1" <?php checked(!empty($tpl['enabled'])); ?>>
                <?php echo esc_html($label); ?></label>
            <code style="direction:ltr;font-weight:normal"><?php echo esc_html($key); ?></code>
        </h3>
        <div class="wsn-variants">
            <?php foreach ($variants as $v): ?>
                <textarea name="tpl[<?php echo esc_attr($key); ?>][variants][]" rows="2" dir="rtl"><?php echo esc_textarea($v); ?></textarea>
            <?php endforeach; ?>
        </div>
        <p>
            <button type="button" class="button wsn-add-variant">+ נוסח נוסף</button>
            <button type="button" class="button wsn-send-test" data-tpl-key="<?php echo esc_attr($key); ?>">שלח לי לבדיקה</button>
            <span class="wsn-test-status description"></span>
            <?php if (count($variants) < 2): ?><span class="description">מומלץ 2+ נוסחים — טקסטים זהים מעלים סיכון חסימה.</span><?php endif; ?>
        </p>
    </div>
    <?php
};
?>
<div class="wrap wsn" dir="rtl">
    <h1>תבניות הודעה</h1>
    <p class="description">
        placeholders זמינים: <code dir="ltr">{first_name} {last_name} {order_number} {order_total} {items} {status_name} {tracking_number} {tracking_url} {store_name}</code>.
        שורה עם <code dir="ltr">{tracking_number}</code> ריק — נמחקת אוטומטית.
        "שלח לי לבדיקה" מדלג בתור לפני הכול ומראה כאן בזמן אמת מתי היא נשלחה בפועל — דרך טובה לוודא שהכול עובד מקצה לקצה.
    </p>

    <form method="post" action="<?php echo esc_url($post); ?>">
        <?php wp_nonce_field('wsn_save_templates'); ?>
        <input type="hidden" name="action" value="wsn_save_templates">

        <h2>לפי סטטוס הזמנה</h2>
        <?php foreach ($statuses as $key => $label) {
            $render_card($key, $label, $templates);
        } ?>

        <h2>הודעות מיוחדות</h2>
        <?php foreach ($special as $key => $label) {
            $render_card($key, $label, $templates);
        } ?>

        <?php submit_button('שמור תבניות'); ?>
    </form>
</div>
