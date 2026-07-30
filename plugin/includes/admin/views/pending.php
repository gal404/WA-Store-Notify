<?php
defined('ABSPATH') || exit;
/** @var array $data */  // items, total, page, per, pages
$drafts = $data['items'];
?>
<div class="wrap wsn" dir="rtl">
    <h1>הודעות ממתינות לאישור</h1>
    <?php WSN_Admin::nav('wsn-pending'); ?>
    <p class="description">
        כל הודעה שהמערכת הכינה יושבת כאן כטיוטה — <b>אף הודעה לא נשלחת עד שתאשר</b>.
        אפשר לערוך את הטקסט, ואז "שלח" או "מחק". שליחה מרווחת אוטומטית בין הודעות לאותו לקוח.
    </p>

    <?php if (!$data['total']): ?>
        <div class="wsn-card" style="text-align:center;padding:34px">
            <p style="font-size:15px;margin:0 0 4px">אין הודעות שממתינות לאישור 🎉</p>
            <p class="description" style="margin:0">כשיתבצע שינוי בהזמנה (סטטוס, פריט, הערה) — ההודעה תיבנה ותופיע כאן.</p>
        </div>
    <?php else: ?>
        <div class="wsn-pending">
            <div class="wsn-pending-bar">
                <label class="wsn-check-all-lbl"><input type="checkbox" class="wsn-check-all"> בחר הכל</label>
                <button type="button" class="button button-primary wsn-send-selected">שלח נבחרות</button>
                <button type="button" class="button wsn-discard-selected">מחק נבחרות</button>
                <span class="wsn-pending-msg description"></span>
                <span class="description" style="margin-inline-start:auto">מציג <?php echo count($drafts); ?> מתוך <?php echo (int) $data['total']; ?></span>
            </div>

            <?php foreach ($drafts as $d):
                $order = $d['order'] ?? null;
                $cust  = $d['customer'] ?? null;
                $name  = $order['name'] ?? ($cust['name'] ?? '');
                $waphone = preg_replace('/\D/', '', (string) ($d['phone_e164'] ?? ''));
                ?>
                <div class="wsn-draft wsn-card" data-id="<?php echo (int) $d['id']; ?>">
                    <div class="wsn-draft-head">
                        <label class="wsn-draft-select"><input type="checkbox" class="wsn-draft-check" value="<?php echo (int) $d['id']; ?>"></label>
                        <?php if ($order): ?>
                            <a class="wsn-pill wsn-pill-order" href="<?php echo esc_url($order['edit']); ?>">#<?php echo esc_html($order['number']); ?></a>
                            <span class="wsn-pill wsn-pill-queued"><?php echo esc_html($order['label']); ?></span>
                        <?php endif; ?>
                        <?php if ($name !== ''): ?><b class="wsn-draft-name"><?php echo esc_html($name); ?></b><?php endif; ?>
                        <a class="wsn-wa-link" href="https://wa.me/<?php echo esc_attr($waphone); ?>" target="_blank" rel="noopener" dir="ltr"><?php echo esc_html($d['phone_display']); ?></a>
                        <?php if ($cust): ?>
                            <span class="description wsn-draft-club"><?php echo (int) $cust['orders_count']; ?> הזמנות<?php echo $cust['consent'] ? ' · דיוור ✓' : ''; ?></span>
                        <?php endif; ?>
                        <span class="description wsn-draft-date">🕐 <?php echo esc_html(mysql2date('d/m/Y H:i', $d['created_at'])); ?></span>
                    </div>
                    <textarea class="wsn-draft-body" rows="5" dir="rtl"><?php echo esc_textarea((string) $d['body']); ?></textarea>
                    <div class="wsn-draft-actions">
                        <button type="button" class="button button-primary wsn-draft-send">שלח</button>
                        <button type="button" class="button wsn-draft-discard">מחק</button>
                        <span class="wsn-draft-msg description"></span>
                    </div>
                </div>
            <?php endforeach; ?>

            <?php if ($data['pages'] > 1): ?>
                <div class="wsn-pagenav">
                    <?php echo paginate_links([
                        'base'      => add_query_arg('paged', '%#%'),
                        'format'    => '',
                        'current'   => (int) $data['page'],
                        'total'     => (int) $data['pages'],
                        'prev_text' => '‹ הקודם',
                        'next_text' => 'הבא ›',
                    ]); ?>
                </div>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</div>
