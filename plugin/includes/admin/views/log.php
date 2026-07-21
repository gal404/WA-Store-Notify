<?php defined('ABSPATH') || exit;
global $wpdb;
$t = WSN_Outbox::table();

$filter_status = sanitize_key($_GET['fstatus'] ?? '');
$search = sanitize_text_field($_GET['s'] ?? '');
$paged = max(1, (int) ($_GET['paged'] ?? 1));
$per = 50;
$offset = ($paged - 1) * $per;

$where = ['1=1'];
$params = [];
if ($filter_status) {
    $where[] = 'status = %s';
    $params[] = $filter_status;
}
if ($search !== '') {
    $where[] = '(phone_e164 LIKE %s OR order_id = %d)';
    $params[] = '%' . $wpdb->esc_like($search) . '%';
    $params[] = (int) $search;
}
$where_sql = implode(' AND ', $where);

$count_sql = "SELECT COUNT(*) FROM $t WHERE $where_sql";
$total = (int) ($params ? $wpdb->get_var($wpdb->prepare($count_sql, $params)) : $wpdb->get_var($count_sql));

$rows_sql = "SELECT * FROM $t WHERE $where_sql ORDER BY id DESC LIMIT %d OFFSET %d";
$rows = $wpdb->get_results($wpdb->prepare($rows_sql, array_merge($params, [$per, $offset])), ARRAY_A);

$labels = ['queued' => 'ממתין', 'claimed' => 'בטיפול', 'sent' => 'נשלח',
    'failed' => 'נכשל', 'cancelled' => 'בוטל', 'expired' => 'פג'];
$cancellable = ['queued', 'claimed'];
?>
<div class="wrap wsn" dir="rtl">
    <h1>יומן הודעות</h1>
    <form method="get" class="wsn-filters">
        <input type="hidden" name="page" value="wsn-log">
        <select name="fstatus">
            <option value="">כל הסטטוסים</option>
            <?php foreach ($labels as $k => $l): ?>
                <option value="<?php echo esc_attr($k); ?>" <?php selected($filter_status, $k); ?>><?php echo esc_html($l); ?></option>
            <?php endforeach; ?>
        </select>
        <input type="search" name="s" value="<?php echo esc_attr($search); ?>" placeholder="טלפון או מס' הזמנה">
        <button class="button">סנן</button>
        <span class="description">סה"כ <?php echo (int) $total; ?> רשומות</span>
    </form>

    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
        <?php wp_nonce_field('wsn_bulk_message_action'); ?>

        <div class="tablenav top">
            <button type="submit" name="action" value="wsn_send_now_messages" class="button button-primary"
                    onclick="return confirm('לשלוח את ההודעות המסומנות עכשיו, בלי להמתין לתזמון הרגיל?');">שלח עכשיו</button>
            <button type="submit" name="action" value="wsn_cancel_messages" class="button"
                    onclick="return confirm('לבטל את ההודעות המסומנות? הן לא יישלחו.');">בטל את המסומנות</button>
            <span class="description">"שלח עכשיו" פועל רק על הודעות "ממתין" — מדלג בתור כדי שהגשר יתפוס בסבב הבא שלו.</span>
        </div>

        <?php // column-primary + data-colname = התצוגה המוערמת של וורדפרס במובייל
              // (העמודה הראשית נשארת גלויה, השאר נפתח בכפתור החץ; core מטפל בקליק) ?>
        <table class="wp-list-table widefat striped">
            <thead><tr>
                <td class="check-column"><input type="checkbox" onclick="document.querySelectorAll('.wsn-msg-check').forEach(c => c.checked = this.checked)"></td>
                <th class="column-primary">טלפון</th><th>מתי</th><th>סוג</th><th>תוכן</th><th>סטטוס</th><th>הזמנה</th><th>פעולות</th>
            </tr></thead>
            <tbody>
            <?php if (!$rows): ?>
                <tr class="no-items"><td colspan="8">אין רשומות.</td></tr>
            <?php else: foreach ($rows as $r): ?>
                <tr>
                    <?php // th (ולא td) בכוונה — כך עושה core, ורק על th חל הכלל
                          // ‎.wp-list-table tr th.check-column { display: table-cell }‎ במובייל.
                          // עם td התא מתמוטט ל-block, כל השורה מצטמצמת לעמודה צרה אחת
                          // והתוכן נדחס לקצה במקום למלא את הרוחב. ?>
                    <th scope="row" class="check-column">
                        <?php if (in_array($r['status'], $cancellable, true)): ?>
                            <input type="checkbox" class="wsn-msg-check" name="msg_ids[]" value="<?php echo (int) $r['id']; ?>">
                        <?php endif; ?>
                    </th>
                    <td class="column-primary" data-colname="טלפון">
                        <span dir="ltr"><?php echo esc_html($r['phone_e164']); ?></span>
                        <button type="button" class="toggle-row"><span class="screen-reader-text">הצג פרטים</span></button>
                    </td>
                    <td data-colname="מתי"><?php echo esc_html(mysql2date('d/m H:i', $r['created_at'])); ?></td>
                    <td data-colname="סוג"><?php echo esc_html($r['kind']); ?></td>
                    <td data-colname="תוכן"><?php echo esc_html(mb_strimwidth($r['body'], 0, 60, '…')); ?></td>
                    <td data-colname="סטטוס">
                        <span class="wsn-pill wsn-pill-<?php echo esc_attr($r['status']); ?>"><?php echo esc_html($labels[$r['status']] ?? $r['status']); ?></span>
                        <?php if ($r['last_error']): ?><br><small class="wsn-bad" title="<?php echo esc_attr($r['last_error']); ?>"><?php echo esc_html(mb_strimwidth($r['last_error'], 0, 40, '…')); ?></small><?php endif; ?>
                    </td>
                    <td data-colname="הזמנה"><?php echo $r['order_id'] ? '<a href="' . esc_url(admin_url('post.php?post=' . (int) $r['order_id'] . '&action=edit')) . '">#' . (int) $r['order_id'] . '</a>' : '—'; ?></td>
                    <td data-colname="פעולות">
                        <?php if ($r['status'] === 'queued'): ?>
                            <button type="button" class="button wsn-send-now-row" data-id="<?php echo (int) $r['id']; ?>">שלח מיידית</button>
                            <span class="wsn-send-now-status description"></span>
                        <?php else: ?>—<?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
    </form>

    <?php
    $pages = (int) ceil($total / $per);
    if ($pages > 1) {
        echo '<div class="tablenav"><div class="tablenav-pages">';
        echo paginate_links([
            'base' => add_query_arg('paged', '%#%'),
            'format' => '', 'current' => $paged, 'total' => $pages,
        ]);
        echo '</div></div>';
    }
    ?>
</div>
