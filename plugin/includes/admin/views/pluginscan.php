<?php
defined('ABSPATH') || exit;
/** @var string $term */
/** @var array $result */
$by_cat = static function (array $hits, string $cat): array {
    return array_values(array_filter($hits, static fn($h) => $h['cat'] === $cat));
};
?>
<div class="wrap wsn" dir="rtl">
    <h1>סריקת תוסף שליחויות</h1>
    <?php WSN_Admin::nav('wsn-pluginscan'); ?>
    <p class="description">
        מאתר את תוסף השליחויות לפי מונח, וקורא מקבציו את השורות החשובות: איפה נכתב <code dir="ltr">cslfw_shipping</code>
        ואילו הוקים (<code dir="ltr">do_action</code>) הוא מפעיל — כדי לחווט התראה מדויקת ברגע יצירת מספר המעקב.
        <b>צלם ושלח לי את התוצאה.</b>
    </p>

    <form method="get" action="<?php echo esc_url(admin_url('admin.php')); ?>" style="margin:14px 0">
        <input type="hidden" name="page" value="wsn-pluginscan">
        <input type="text" name="term" value="<?php echo esc_attr($term); ?>" class="wsn-w-md" dir="ltr" placeholder="cslfw,cargo" style="width:260px">
        <button type="submit" class="button button-primary">סרוק</button>
        <span class="description">מונחים מופרדים בפסיק (שם תיקייה או תוכן)</span>
    </form>

    <p class="description">תיקיית תוספים: <code dir="ltr"><?php echo esc_html($result['dir']); ?></code></p>

    <?php if (empty($result['plugins'])): ?>
        <div class="notice notice-warning"><p>לא נמצא תוסף שתואם ל: <code dir="ltr"><?php echo esc_html(implode(', ', $result['terms'])); ?></code>. נסה מונח אחר — למשל חלק משם התוסף כפי שמופיע בעמוד "תוספים".</p></div>
    <?php else: foreach ($result['plugins'] as $p): ?>
        <div class="wsn-card">
            <h3>📦 <?php echo esc_html($p['slug']); ?> <span class="description">(<?php echo (int) $p['files']; ?> קבצי php)</span></h3>
            <?php
            $sections = [
                'cslfw_shipping' => 'שימוש ב-cslfw_shipping (איפה נכתב / נקרא)',
                'do_action'      => 'הוקים שמופעלים (do_action) — כאן נחפש את רגע יצירת המעקב',
                'apply_filters'  => 'פילטרים (apply_filters)',
                'meta_write'     => 'כתיבת מטא רלוונטית',
            ];
            $any = false;
            foreach ($sections as $cat => $label):
                $rows = $by_cat($p['hits'], $cat);
                if (!$rows) {
                    continue;
                }
                $any = true; ?>
                <h4 style="margin:14px 0 6px"><?php echo esc_html($label); ?> (<?php echo count($rows); ?>)</h4>
                <div style="overflow-x:auto">
                    <table class="widefat striped"><tbody>
                        <?php foreach ($rows as $h): ?>
                            <tr>
                                <td style="width:36%;vertical-align:top"><code dir="ltr"><?php echo esc_html($h['file']); ?>:<?php echo (int) $h['line']; ?></code></td>
                                <td><code dir="ltr" style="white-space:pre-wrap;word-break:break-word"><?php echo esc_html(mb_substr($h['code'], 0, 300)); ?></code></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody></table>
                </div>
            <?php endforeach; ?>
            <?php if (!$any): ?><p class="description">התוסף אותר, אך לא נמצאו שורות תואמות לתבניות שחיפשנו.</p><?php endif; ?>
        </div>
    <?php endforeach; endif; ?>
</div>
