<?php defined('ABSPATH') || exit;
global $wpdb;
$t = WSN_Contacts::table();

$fstatus = sanitize_key($_GET['fstatus'] ?? '');
$min_orders = max(0, (int) ($_GET['min_orders'] ?? 0));
$paged = max(1, (int) ($_GET['paged'] ?? 1));
$per = 50;
$offset = ($paged - 1) * $per;

$where = ['1=1'];
$params = [];
if ($fstatus) {
    $where[] = 'status = %s';
    $params[] = $fstatus;
}
if ($min_orders) {
    $where[] = 'orders_count >= %d';
    $params[] = $min_orders;
}
$where_sql = implode(' AND ', $where);
$total = (int) ($params
    ? $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $t WHERE $where_sql", $params))
    : $wpdb->get_var("SELECT COUNT(*) FROM $t WHERE $where_sql"));
$rows = $wpdb->get_results($wpdb->prepare(
    "SELECT * FROM $t WHERE $where_sql ORDER BY last_order_at DESC LIMIT %d OFFSET %d",
    array_merge($params, [$per, $offset])
), ARRAY_A);

$status_labels = ['active' => 'פעיל', 'unsubscribed' => 'הוסר', 'invalid' => 'לא תקין'];
?>
<div class="wrap wsn" dir="rtl">
    <h1>מועדון לקוחות
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline">
            <?php wp_nonce_field('wsn_export_contacts'); ?>
            <input type="hidden" name="action" value="wsn_export_contacts">
            <button class="page-title-action">ייצוא CSV</button>
        </form>
    </h1>

    <form method="get">
        <input type="hidden" name="page" value="wsn-contacts">
        <select name="fstatus">
            <option value="">כל הסטטוסים</option>
            <?php foreach ($status_labels as $k => $l): ?>
                <option value="<?php echo esc_attr($k); ?>" <?php selected($fstatus, $k); ?>><?php echo esc_html($l); ?></option>
            <?php endforeach; ?>
        </select>
        מינימום הזמנות: <input type="number" name="min_orders" value="<?php echo esc_attr($min_orders); ?>" style="width:70px">
        <button class="button">סנן</button>
        <span class="description">סה"כ <?php echo (int) $total; ?> לקוחות</span>
    </form>

    <table class="wp-list-table widefat fixed striped">
        <thead><tr>
            <th>שם</th><th>טלפון</th><th>סטטוס</th><th>דיוור</th><th>הזמנות</th><th>סה"כ קניות</th><th>הזמנה אחרונה</th>
        </tr></thead>
        <tbody>
        <?php if (!$rows): ?>
            <tr><td colspan="7">אין לקוחות עדיין.</td></tr>
        <?php else: foreach ($rows as $r): ?>
            <tr>
                <td><?php echo esc_html(trim($r['first_name'] . ' ' . $r['last_name'])); ?></td>
                <td dir="ltr"><?php echo esc_html($r['phone_e164']); ?></td>
                <td><?php echo esc_html($status_labels[$r['status']] ?? $r['status']); ?></td>
                <td><?php echo $r['marketing_consent'] ? '✔' : '—'; ?></td>
                <td><?php echo (int) $r['orders_count']; ?></td>
                <td><?php echo esc_html(wc_price($r['total_spent'])); ?></td>
                <td><?php echo $r['last_order_at'] ? esc_html(mysql2date('d/m/y', $r['last_order_at'])) : '—'; ?></td>
            </tr>
        <?php endforeach; endif; ?>
        </tbody>
    </table>

    <?php
    $pages = (int) ceil($total / $per);
    if ($pages > 1) {
        echo '<div class="tablenav"><div class="tablenav-pages">';
        echo paginate_links(['base' => add_query_arg('paged', '%#%'), 'format' => '', 'current' => $paged, 'total' => $pages]);
        echo '</div></div>';
    }
    ?>
</div>
