<?php
defined('ABSPATH') || exit;
/** @var int $oid */
/** @var array|null $data */

$render_val = static function ($v): string {
    if (is_scalar($v) || $v === null) {
        $s = trim((string) $v);
        return $s === '' ? '<span class="description">(ריק)</span>' : '<span dir="ltr">' . esc_html($s) . '</span>';
    }
    return '<pre style="margin:0;white-space:pre-wrap;direction:ltr;max-height:220px;overflow:auto">' . esc_html(print_r($v, true)) . '</pre>';
};
$is_tracky = static function (string $k): bool {
    return (bool) preg_match('/track|מעקב|shipment|waybill|awb|parcel|barcode/i', $k);
};
?>
<div class="wrap wsn" dir="rtl">
    <h1>בדיקת הזמנה</h1>
    <?php WSN_Admin::nav('wsn-inspect'); ?>
    <p class="description">מזינים מספר הזמנה ורואים את כל המטא, הפריטים ושיטת המשלוח. כך מוצאים באיזה שדה חברת השליחויות שומרת את <b>מספר המעקב</b> — ואז מדביקים את שם המפתח ב<a href="<?php echo esc_url(admin_url('admin.php?page=wsn-settings')); ?>">הגדרות ← "מפתח מטא למספר מעקב"</a>.</p>

    <form method="get" action="<?php echo esc_url(admin_url('admin.php')); ?>" style="margin:14px 0">
        <input type="hidden" name="page" value="wsn-inspect">
        <input type="number" name="oid" value="<?php echo $oid ?: ''; ?>" placeholder="מספר הזמנה" class="wsn-w-sm" dir="ltr" style="width:160px">
        <button type="submit" class="button button-primary">הצג</button>
    </form>

    <?php if (!$data): ?>
        <p class="description">הזן מספר הזמנה למעלה.</p>
    <?php elseif (!empty($data['error'])): ?>
        <div class="notice notice-error"><p><?php echo esc_html($data['error']); ?></p></div>
    <?php else: ?>

        <div class="wsn-card">
            <h3>פרטי הזמנה</h3>
            <table class="widefat striped"><tbody>
                <?php foreach ($data['core'] as $k => $v): ?>
                    <tr><th style="width:160px"><?php echo esc_html($k); ?></th><td><?php echo $render_val($v); ?></td></tr>
                <?php endforeach; ?>
            </tbody></table>
        </div>

        <div class="wsn-card">
            <h3>שיטת משלוח</h3>
            <?php if (!$data['shipping']): ?>
                <p class="description">אין שורות משלוח בהזמנה.</p>
            <?php else: foreach ($data['shipping'] as $sm): ?>
                <table class="widefat striped" style="margin-bottom:10px"><tbody>
                    <tr><th style="width:160px">שם השיטה</th><td><b><?php echo esc_html($sm['title']); ?></b></td></tr>
                    <tr><th>מזהה שיטה</th><td><code dir="ltr"><?php echo esc_html($sm['method_id']); ?></code>
                        <?php if ($sm['method_id'] === 'local_pickup'): ?><span class="wsn-pill wsn-pill-order">איסוף עצמי</span>
                        <?php else: ?><span class="wsn-pill wsn-pill-queued">משלוח</span><?php endif; ?></td></tr>
                    <?php foreach ($sm['meta'] as $mk => $mv): ?>
                        <tr><th><code dir="ltr"><?php echo esc_html($mk); ?></code></th><td><?php echo $render_val($mv); ?></td></tr>
                    <?php endforeach; ?>
                </tbody></table>
            <?php endforeach; endif; ?>
        </div>

        <div class="wsn-card">
            <h3>כל המטא של ההזמנה <span class="description">(מסומן = נראה כמו מספר מעקב)</span></h3>
            <div style="overflow-x:auto">
                <table class="widefat striped"><thead><tr><th style="width:40%">מפתח (key)</th><th>ערך</th></tr></thead><tbody>
                    <?php foreach ($data['meta'] as $k => $v):
                        $hot = $is_tracky($k); ?>
                        <tr<?php echo $hot ? ' style="background:#fff6e5"' : ''; ?>>
                            <th><code dir="ltr"><?php echo esc_html($k); ?></code><?php echo $hot ? ' 🔎' : ''; ?></th>
                            <td><?php echo $render_val($v); ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$data['meta']): ?><tr><td colspan="2" class="description">אין מטא.</td></tr><?php endif; ?>
                </tbody></table>
            </div>
        </div>

        <div class="wsn-card">
            <h3>פריטים (<?php echo count($data['items']); ?>)</h3>
            <?php foreach ($data['items'] as $it): ?>
                <table class="widefat striped" style="margin-bottom:10px"><tbody>
                    <tr><th style="width:160px">פריט</th><td><b><?php echo esc_html($it['name']); ?></b> × <?php echo (int) $it['qty']; ?></td></tr>
                    <tr><th>מק"ט / מזהה מוצר</th><td dir="ltr"><?php echo esc_html($it['sku'] ?: '—'); ?> / <?php echo (int) $it['product_id']; ?></td></tr>
                    <?php foreach ($it['meta'] as $mk => $mv): ?>
                        <tr><th><code dir="ltr"><?php echo esc_html($mk); ?></code></th><td><?php echo $render_val($mv); ?></td></tr>
                    <?php endforeach; ?>
                </tbody></table>
            <?php endforeach; ?>
        </div>

    <?php endif; ?>
</div>
