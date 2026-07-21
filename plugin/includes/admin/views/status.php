<?php defined('ABSPATH') || exit;
$st = get_option('wsn_bridge_status', []);
$counts = WSN_Outbox::counts();
$paused = (int) WSN_Settings::get('paused');
$seen = $st['seen_at'] ?? '';
$fresh = $seen && (strtotime(current_time('mysql')) - strtotime($seen) < 180);
$state = $st['state'] ?? '';
$me = $st['me'] ?? [];
$breaker_open = !empty($st['breaker']['open']);
?>
<div class="wrap wsn" dir="rtl">
    <h1>וואטסאפ לחנות — סטטוס</h1>

    <?php if ($breaker_open): ?>
        <div class="notice notice-error"><p>
            <b>מפסק הזרם פתוח</b> — השליחה נעצרה אחרי כשלונות רצופים.
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline">
                <?php wp_nonce_field('wsn_resume_breaker'); ?>
                <input type="hidden" name="action" value="wsn_resume_breaker">
                <button class="button">חדש שליחה</button>
            </form>
        </p></div>
    <?php endif; ?>

    <?php if (!empty($st['deps']['update_available'])): ?>
        <div class="notice notice-warning"><p>
            עדכון זמין ל-whatsapp-web.js בגשר: <b><?php echo esc_html($st['deps']['wwebjs_installed']); ?></b> ←
            <b><?php echo esc_html($st['deps']['wwebjs_latest']); ?></b>.
            הבדיקה אוטומטית בלבד; העדכון עצמו ידני — <code>npm install whatsapp-web.js@latest</code> בתיקיית <code>bridge</code> ואז הפעלה מחדש של השירות.
        </p></div>
    <?php endif; ?>
    <?php if (isset($st['deps']['puppeteer_ok']) && !$st['deps']['puppeteer_ok']): ?>
        <div class="notice notice-error"><p>
            <b>בעיה בהתקנת Puppeteer/Chromium בגשר</b> — קובץ ה-Chromium לא נמצא. הרץ מחדש <code>npm install</code> בתיקיית <code>bridge</code>.
        </p></div>
    <?php endif; ?>

    <div class="wsn-cards">
        <div class="wsn-card">
            <h2>חיבור הגשר</h2>
            <?php if (!$seen): ?>
                <p class="wsn-bad">הגשר עדיין לא דיווח. ודא שהוא רץ על המחשב (pm2) ושהוגדרו WP_URL ו-API_KEY.</p>
            <?php elseif (!$fresh): ?>
                <p class="wsn-bad">אין דיווח עדכני (נראה לאחרונה: <?php echo esc_html($seen); ?>). ייתכן שהגשר כבוי.</p>
            <?php elseif ($state === 'ready'): ?>
                <p class="wsn-good">✔ מחובר<?php echo $me ? ' בתור ' . esc_html(($me['name'] ?? '') . ' ' . ($me['number'] ?? '')) : ''; ?></p>
            <?php elseif ($state === 'qr' && !empty($st['qr'])): ?>
                <p>סרוק את הקוד מהוואטסאפ של החנות (מכשירים מקושרים ▸ קישור מכשיר):</p>
                <img src="<?php echo esc_attr($st['qr']); ?>" alt="QR" style="width:240px;max-width:100%">
            <?php else: ?>
                <p>מצב: <?php echo esc_html($state ?: 'לא ידוע'); ?></p>
            <?php endif; ?>
            <?php if (!empty($st['counters'])): ?>
                <p class="description">נשלחו היום: <?php echo (int) ($st['counters']['sent_today'] ?? 0); ?>
                    (בשעה האחרונה: <?php echo (int) ($st['counters']['sent_hour'] ?? 0); ?>)</p>
            <?php endif; ?>
        </div>

        <div class="wsn-card">
            <h2>התור</h2>
            <?php
            // אריחים במקום רשימה: המספר הוא מה שסורקים, אז הוא מקבל את המשקל,
            // והצבע מקודד מצב (ממתין/נכשל) כדי שמה שדורש תשומת לב יזדקר.
            $tiles = [
                ['label' => 'ממתינות', 'value' => $counts['queued'],  'tone' => 'warn'],
                ['label' => 'בטיפול',  'value' => $counts['claimed'], 'tone' => ''],
                ['label' => 'נשלחו',   'value' => $counts['sent'],    'tone' => 'ok'],
                ['label' => 'נכשלו',   'value' => $counts['failed'],  'tone' => 'err'],
                ['label' => 'פגו',     'value' => $counts['expired'], 'tone' => ''],
            ];
            ?>
            <ul class="wsn-tiles">
                <?php foreach ($tiles as $t): ?>
                    <li class="wsn-tile <?php echo $t['tone'] ? 'wsn-tile-' . esc_attr($t['tone']) : ''; ?>">
                        <span class="wsn-tile-num"><?php echo (int) $t['value']; ?></span>
                        <span class="wsn-tile-label"><?php echo esc_html($t['label']); ?></span>
                    </li>
                <?php endforeach; ?>
            </ul>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <?php wp_nonce_field('wsn_toggle_pause'); ?>
                <input type="hidden" name="action" value="wsn_toggle_pause">
                <button class="button <?php echo $paused ? 'button-primary' : ''; ?>">
                    <?php echo $paused ? 'חדש שליחה' : 'השהה שליחה'; ?>
                </button>
                <?php if ($paused): ?><span class="wsn-bad"> השליחה מושהית</span><?php endif; ?>
            </form>
        </div>
    </div>
</div>
