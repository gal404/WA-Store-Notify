<?php defined('ABSPATH') || exit;
/** @var array $campaigns */
$post = admin_url('admin-post.php');
$status_labels = ['draft' => 'טיוטה', 'scheduled' => 'מתוזמן', 'sending' => 'בשליחה',
    'paused' => 'מושהה', 'done' => 'הושלם', 'cancelled' => 'בוטל'];
?>
<div class="wrap wsn" dir="rtl">
    <h1>קמפיינים (דיוור)</h1>
    <div class="notice notice-warning inline"><p>
        דיוור יזום הוא הפעילות עם סיכון החסימה הגבוה ביותר. נשלח רק ללקוחות שנתנו הסכמת דיוור,
        בקצב איטי, בחלון הצהריים בלבד. שקול היטב תדירות ותוכן.
    </p></div>

    <h2>קמפיין חדש</h2>
    <form method="post" action="<?php echo esc_url($post); ?>" class="wsn-card">
        <?php wp_nonce_field('wsn_campaign_create'); ?>
        <input type="hidden" name="action" value="wsn_campaign_create">
        <p><label>כותרת (פנימית):<br><input type="text" name="title" class="wsn-w-md" required></label></p>
        <p><label>נוסחי הודעה (2+ מומלץ, placeholders: <code dir="ltr">{first_name}</code>):</label></p>
        <div class="wsn-variants">
            <textarea name="variants[]" rows="2" dir="rtl" placeholder="נוסח 1"></textarea>
            <textarea name="variants[]" rows="2" dir="rtl" placeholder="נוסח 2"></textarea>
        </div>
        <p><button type="button" class="button wsn-add-variant">+ נוסח נוסף</button></p>
        <hr>
        <p><b>קהל יעד</b> (תמיד: פעילים עם הסכמת דיוור בלבד)</p>
        <p>
            מינימום הזמנות: <input type="number" name="min_orders" id="wsn-min-orders" value="0" class="wsn-w-num">
            הזמנה אחרונה עד לפני: <input type="number" name="last_order_days" id="wsn-last-days" value="0" class="wsn-w-num"> ימים (0 = ללא הגבלה)
        </p>
        <p>
            <button type="button" class="button" id="wsn-count-btn">חשב נמענים ומשך</button>
            <span id="wsn-count-result" class="description"></span>
        </p>
        <?php submit_button('שמור כטיוטה'); ?>
    </form>

    <h2>קמפיינים קיימים</h2>
    <table class="wp-list-table widefat striped">
        <thead><tr><th class="column-primary">כותרת</th><th>סטטוס</th><th>נמענים</th><th>נשלחו</th><th>נכשלו</th><th>נוצר</th><th>פעולות</th></tr></thead>
        <tbody>
        <?php if (!$campaigns): ?>
            <tr class="no-items"><td colspan="7">אין קמפיינים.</td></tr>
        <?php else: foreach ($campaigns as $c): ?>
            <tr>
                <td class="column-primary" data-colname="כותרת">
                    <?php echo esc_html($c['title']); ?>
                    <button type="button" class="toggle-row"><span class="screen-reader-text">הצג פרטים</span></button>
                </td>
                <td data-colname="סטטוס"><span class="wsn-pill wsn-pill-<?php echo esc_attr($c['status']); ?>"><?php echo esc_html($status_labels[$c['status']] ?? $c['status']); ?></span></td>
                <td data-colname="נמענים" class="wsn-num"><?php echo (int) $c['total_recipients']; ?></td>
                <td data-colname="נשלחו" class="wsn-num"><?php echo (int) $c['sent_count']; ?></td>
                <td data-colname="נכשלו" class="wsn-num"><?php echo (int) $c['failed_count']; ?></td>
                <td data-colname="נוצר"><?php echo esc_html(mysql2date('d/m/y', $c['created_at'])); ?></td>
                <td data-colname="פעולות">
                    <?php if (in_array($c['status'], ['draft', 'scheduled'], true)): ?>
                        <form method="post" action="<?php echo esc_url($post); ?>" style="display:inline"
                              onsubmit="return confirm('לשגר את הקמפיין? ההודעות ייכנסו לתור וישלחו בקצב האיטי.');">
                            <?php wp_nonce_field('wsn_campaign_action'); ?>
                            <input type="hidden" name="action" value="wsn_campaign_action">
                            <input type="hidden" name="act" value="launch">
                            <input type="hidden" name="campaign_id" value="<?php echo (int) $c['id']; ?>">
                            <button class="button button-primary">שגר</button>
                        </form>
                    <?php endif; ?>
                    <?php if (in_array($c['status'], ['draft', 'scheduled', 'sending', 'paused'], true)): ?>
                        <form method="post" action="<?php echo esc_url($post); ?>" style="display:inline"
                              onsubmit="return confirm('לבטל את הקמפיין? הודעות שטרם נשלחו יבוטלו.');">
                            <?php wp_nonce_field('wsn_campaign_action'); ?>
                            <input type="hidden" name="action" value="wsn_campaign_action">
                            <input type="hidden" name="act" value="cancel">
                            <input type="hidden" name="campaign_id" value="<?php echo (int) $c['id']; ?>">
                            <button class="button">בטל</button>
                        </form>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; endif; ?>
        </tbody>
    </table>
</div>
