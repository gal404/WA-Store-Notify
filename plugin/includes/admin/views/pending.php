<?php
defined('ABSPATH') || exit;
/** @var array $drafts */
?>
<div class="wrap wsn" dir="rtl">
    <h1>הודעות ממתינות לאישור</h1>
    <?php WSN_Admin::nav('wsn-pending'); ?>
    <p class="description">
        כל הודעה שהמערכת הכינה יושבת כאן כטיוטה — <b>אף הודעה לא נשלחת עד שתאשר</b>.
        אפשר לערוך את הטקסט, ואז "שלח" או "מחק". "שלח" מכניס לשליחה מיידית (בקצב אנושי) גם אם השליחה מושהית.
    </p>

    <?php if (!$drafts): ?>
        <div class="wsn-card" style="text-align:center;padding:34px">
            <p style="font-size:15px;margin:0 0 4px">אין הודעות שממתינות לאישור 🎉</p>
            <p class="description" style="margin:0">כשיתבצע שינוי בהזמנה (סטטוס, פריט, הערה) — ההודעה תיבנה ותופיע כאן.</p>
        </div>
    <?php else: ?>
        <div class="wsn-pending">
            <p class="wsn-pending-bar">
                <button type="button" class="button button-primary wsn-approve-all">שלח את כל ההודעות (<?php echo count($drafts); ?>)</button>
                <span class="wsn-pending-msg description"></span>
            </p>

            <?php foreach ($drafts as $d):
                $order = $d['order'] ?? null;
                $cust  = $d['customer'] ?? null;
                $name  = $order['name'] ?? ($cust['name'] ?? '');
                $waphone = preg_replace('/\D/', '', (string) ($d['phone_e164'] ?? ''));
                ?>
                <div class="wsn-draft wsn-card" data-id="<?php echo (int) $d['id']; ?>">
                    <div class="wsn-draft-head">
                        <?php if ($order): ?>
                            <a class="wsn-pill wsn-pill-order" href="<?php echo esc_url($order['edit']); ?>">#<?php echo esc_html($order['number']); ?></a>
                            <span class="wsn-pill wsn-pill-queued"><?php echo esc_html($order['label']); ?></span>
                        <?php endif; ?>
                        <?php if ($name !== ''): ?><b class="wsn-draft-name"><?php echo esc_html($name); ?></b><?php endif; ?>
                        <a class="wsn-wa-link" href="https://wa.me/<?php echo esc_attr($waphone); ?>" target="_blank" rel="noopener" dir="ltr"><?php echo esc_html($d['phone_display']); ?></a>
                        <?php if ($cust): ?>
                            <span class="description wsn-draft-club"><?php echo (int) $cust['orders_count']; ?> הזמנות<?php echo $cust['consent'] ? ' · דיוור ✓' : ''; ?></span>
                        <?php endif; ?>
                    </div>
                    <textarea class="wsn-draft-body" rows="5" dir="rtl"><?php echo esc_textarea((string) $d['body']); ?></textarea>
                    <div class="wsn-draft-actions">
                        <button type="button" class="button button-primary wsn-draft-send">שלח</button>
                        <button type="button" class="button wsn-draft-discard">מחק</button>
                        <span class="wsn-draft-msg description"></span>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>
