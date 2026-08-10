<?php defined('ABSPATH') || exit;
global $wpdb;
$t = WSN_Contacts::table();

$fstatus = sanitize_key($_GET['fstatus'] ?? '');
$min_orders = max(0, (int) ($_GET['min_orders'] ?? 0));
$paged = max(1, (int) ($_GET['paged'] ?? 1));
$per = 50;

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
// הצמדת העמוד לטווח הקיים: כתובת ישנה עם paged גבוה (למשל אחרי ניקוי) הציגה
// טבלה ריקה לצמיתות, בלי שום רמז לסיבה.
$pages_total = $total > 0 ? (int) ceil($total / $per) : 1;
$paged = min($paged, $pages_total);
$offset = ($paged - 1) * $per;
$rows = $wpdb->get_results($wpdb->prepare(
    "SELECT * FROM $t WHERE $where_sql ORDER BY last_order_at DESC LIMIT %d OFFSET %d",
    array_merge($params, [$per, $offset])
), ARRAY_A);

$status_labels = ['active' => 'פעיל', 'unsubscribed' => 'הוסר', 'invalid' => 'לא תקין'];

// ההזמנות האחרונות של הלקוחות בעמוד. wc_get_order לכל מזהה בנפרד ולא
// שאילתה מרוכזת עם 'include' — ארגומנט שלא נתמך מתעלם בשקט, ואז limit=-1
// מושך את כל ההזמנות בחנות ומפיל את העמוד. כאן חסום לגודל העמוד.
$last_order_ids = array_values(array_unique(array_filter(array_map(
    static fn($r) => (int) ($r['last_order_id'] ?? 0),
    (array) $rows
))));
$last_orders = [];
// העשרה בלבד — כשל כאן לא אמור להפיל את כל המסך
try {
    if (function_exists('wc_get_order')) {
        foreach ($last_order_ids as $oid) {
            $o = wc_get_order($oid);
            if ($o instanceof WC_Order) {
                $last_orders[$oid] = [
                    'number' => $o->get_order_number(),
                    'status' => $o->get_status(),
                    'label'  => wc_get_order_status_name($o->get_status()),
                ];
            }
        }
    }
} catch (\Throwable $e) {
    $last_orders = [];
}
?>
<div class="wrap wsn" dir="rtl">
    <h1>מועדון לקוחות
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline">
            <?php wp_nonce_field('wsn_export_contacts'); ?>
            <input type="hidden" name="action" value="wsn_export_contacts">
            <button class="page-title-action">ייצוא CSV</button>
        </form>
    </h1>
    <?php WSN_Admin::nav('wsn-contacts'); ?>

    <form method="get" class="wsn-filters">
        <input type="hidden" name="page" value="wsn-contacts">
        <select name="fstatus">
            <option value="">כל הסטטוסים</option>
            <?php foreach ($status_labels as $k => $l): ?>
                <option value="<?php echo esc_attr($k); ?>" <?php selected($fstatus, $k); ?>><?php echo esc_html($l); ?></option>
            <?php endforeach; ?>
        </select>
        <label>מינימום הזמנות: <input type="number" name="min_orders" value="<?php echo esc_attr($min_orders); ?>" class="wsn-w-num"></label>
        <button class="button">סנן</button>
        <span class="description">סה"כ <?php echo (int) $total; ?> לקוחות</span>
    </form>

    <table class="wp-list-table widefat striped">
        <thead><tr>
            <th class="column-primary">שם</th><th>טלפון</th><th>סטטוס</th><th>דיוור</th><th>הזמנות</th><th>סה"כ קניות</th><th>הזמנה אחרונה</th>
        </tr></thead>
        <tbody>
        <?php if (!$rows): ?>
            <tr class="no-items"><td colspan="7">אין לקוחות עדיין.</td></tr>
        <?php else: foreach ($rows as $r): ?>
            <tr>
                <td class="column-primary" data-colname="שם">
                    <?php echo esc_html(trim($r['first_name'] . ' ' . $r['last_name']) ?: '—'); ?>
                    <button type="button" class="toggle-row"><span class="screen-reader-text">הצג פרטים</span></button>
                </td>
                <?php // dir="ltr" על ה-span הפנימי ולא על התא: על התא הוא היה מיישר
                      // את כל התוכן שמאלה, ובתצוגה המוערמת במובייל המספר היה קופץ
                      // לצד שמאל במקום לשבת ליד התווית שלו. ?>
                <td data-colname="טלפון">
                    <?php // במועדון אין תוכן הודעה, אז הקישור רק פותח את השיחה ?>
                    <a class="wsn-phone wsn-wa-link" dir="ltr"
                       href="<?php echo esc_url('https://wa.me/' . rawurlencode($r['phone_e164'])); ?>"
                       target="_blank" rel="noopener noreferrer"
                       title="פתיחת שיחה בוואטסאפ">
                        <?php echo esc_html(WSN_Phone::to_display($r['phone_e164'])); ?>
                    </a>
                </td>
                <td data-colname="סטטוס"><span class="wsn-pill wsn-pill-<?php echo esc_attr($r['status']); ?>"><?php echo esc_html($status_labels[$r['status']] ?? $r['status']); ?></span></td>
                <td data-colname="דיוור"><?php echo $r['marketing_consent'] ? '<span class="wsn-yes">✔</span>' : '<span class="wsn-no">—</span>'; ?></td>
                <td data-colname="הזמנות" class="wsn-num"><?php echo (int) $r['orders_count']; ?></td>
                <?php // wc_price מחזיר HTML (span/bdi + סמל מטבע) — esc_html היה מדפיס את התגיות כטקסט ?>
                <td data-colname="סה&quot;כ קניות" class="wsn-num"><?php echo wp_kses_post(wc_price($r['total_spent'])); ?></td>
                <td data-colname="הזמנה אחרונה">
                    <?php $lo = $last_orders[(int) $r['last_order_id']] ?? null; ?>
                    <?php if ($lo): ?>
                        <a class="wsn-order-link" href="<?php echo esc_url(admin_url('post.php?post=' . (int) $r['last_order_id'] . '&action=edit')); ?>">#<?php echo esc_html($lo['number']); ?></a>
                        <span class="wsn-pill wsn-pill-order"><?php echo esc_html($lo['label']); ?></span>
                        <?php if ($r['last_order_at']): ?><span class="wsn-when"><?php echo esc_html(mysql2date('d/m/y', $r['last_order_at'])); ?></span><?php endif; ?>
                    <?php elseif ($r['last_order_at']): ?>
                        <span class="wsn-when"><?php echo esc_html(mysql2date('d/m/y', $r['last_order_at'])); ?></span>
                    <?php else: ?>—<?php endif; ?>
                </td>
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
