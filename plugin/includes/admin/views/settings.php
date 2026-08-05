<?php defined('ABSPATH') || exit;
/** @var array $s */
$new_key = get_transient('wsn_new_api_key');
delete_transient('wsn_new_api_key');
$post = admin_url('admin-post.php');
?>
<div class="wrap wsn" dir="rtl">
    <h1>הגדרות</h1>
    <?php WSN_Admin::nav('wsn-settings'); ?>

    <form method="post" action="<?php echo esc_url($post); ?>">
        <?php wp_nonce_field('wsn_save_settings'); ?>
        <input type="hidden" name="action" value="wsn_save_settings">

        <h2>קצב שליחה — הודעות טרנזקציוניות</h2>
        <table class="form-table"><tbody>
            <tr><th>מרווח בין הודעות (שניות)</th><td>
                מ־<input type="number" name="trans_min_gap_s" value="<?php echo esc_attr($s['trans_min_gap_s']); ?>" min="5" class="wsn-w-num">
                עד <input type="number" name="trans_max_gap_s" value="<?php echo esc_attr($s['trans_max_gap_s']); ?>" min="5" class="wsn-w-num">
                <p class="description">מרווח אקראי בטווח הזה בין כל הודעה — מרכיב מרכזי במניעת חסימה.</p>
            </td></tr>
            <tr><th>השהיה בין הודעות לאותו לקוח (שניות)</th><td>
                מ־<input type="number" name="cust_gap_min_s" value="<?php echo esc_attr($s['cust_gap_min_s']); ?>" min="0" class="wsn-w-num">
                עד <input type="number" name="cust_gap_max_s" value="<?php echo esc_attr($s['cust_gap_max_s']); ?>" min="0" class="wsn-w-num">
                <p class="description">כשנשלחות כמה הודעות לאותו מספר, כל אחת מרווחת מהקודמת בזמן אקראי בטווח הזה (ברירת מחדל 300–600 = 5–10 דק). הודעה ראשונה ללקוח נשלחת מיד.</p>
            </td></tr>
            <tr><th>מגבלת שליחה</th><td>
                <input type="number" name="trans_hourly_cap" value="<?php echo esc_attr($s['trans_hourly_cap']); ?>" class="wsn-w-num"> לשעה,
                <input type="number" name="trans_daily_cap" value="<?php echo esc_attr($s['trans_daily_cap']); ?>" class="wsn-w-num"> ליום
            </td></tr>
            <tr><th>שעות שקט (לא שולחים)</th><td>
                מ־<input type="text" name="quiet_from" value="<?php echo esc_attr($s['quiet_from']); ?>" class="wsn-w-num" dir="ltr">
                עד <input type="text" name="quiet_to" value="<?php echo esc_attr($s['quiet_to']); ?>" class="wsn-w-num" dir="ltr">
            </td></tr>
        </tbody></table>

        <h2>קצב שליחה — קמפיינים (דיוור)</h2>
        <table class="form-table"><tbody>
            <tr><th>מרווח בין הודעות (שניות)</th><td>
                מ־<input type="number" name="camp_min_gap_s" value="<?php echo esc_attr($s['camp_min_gap_s']); ?>" min="30" class="wsn-w-num">
                עד <input type="number" name="camp_max_gap_s" value="<?php echo esc_attr($s['camp_max_gap_s']); ?>" min="30" class="wsn-w-num">
            </td></tr>
            <tr><th>מגבלת שליחה</th><td>
                <input type="number" name="camp_hourly_cap" value="<?php echo esc_attr($s['camp_hourly_cap']); ?>" class="wsn-w-num"> לשעה,
                <input type="number" name="camp_daily_cap" value="<?php echo esc_attr($s['camp_daily_cap']); ?>" class="wsn-w-num"> ליום
            </td></tr>
            <tr><th>חלון שליחת קמפיינים</th><td>
                מ־<input type="text" name="camp_window_from" value="<?php echo esc_attr($s['camp_window_from']); ?>" class="wsn-w-num" dir="ltr">
                עד <input type="text" name="camp_window_to" value="<?php echo esc_attr($s['camp_window_to']); ?>" class="wsn-w-num" dir="ltr">
                <p class="description">דיוור נשלח רק בחלון הזה (רצוי אמצע היום), בקצב איטי בהרבה מהודעות סטטוס.</p>
            </td></tr>
        </tbody></table>

        <h2>מניעת חסימה מתקדם</h2>
        <table class="form-table"><tbody>
            <tr><th>שגיאות כתיב מכוונות</th><td>
                אחת לכל <input type="number" name="typo_ratio" value="<?php echo esc_attr($s['typo_ratio']); ?>" min="0" class="wsn-w-num"> הודעות
                <p class="description">הגשר "יטעה" במילה וישלח תיקון תוך שניות (התנהגות אנושית). 0 = כבוי. לעולם לא בתוך מספר מעקב/הזמנה/קישור.</p>
            </td></tr>
            <tr><th>מצב חימום (מספר טרי)</th><td>
                <label><input type="checkbox" name="warmup_enabled" value="1" <?php checked($s['warmup_enabled']); ?>> הפעל</label>
                החל מתאריך <input type="text" name="warmup_started" value="<?php echo esc_attr($s['warmup_started']); ?>" placeholder="2026-07-20" class="wsn-w-date" dir="ltr">
                <p class="description">מגביל שליחה הדרגתית (10→25→50→100 ליום) בימים הראשונים של מספר חדש.</p>
            </td></tr>
        </tbody></table>

        <h2>משלוחים והצטרפות</h2>
        <table class="form-table"><tbody>
            <tr><th>שדה meta למספר מעקב</th><td>
                <input type="text" name="tracking_meta_key" value="<?php echo esc_attr($s['tracking_meta_key']); ?>" class="wsn-w-md" dir="ltr" list="wsn-meta-presets">
                <datalist id="wsn-meta-presets">
                    <option value="_wc_shipment_tracking_items"><option value="ywot_tracking_code">
                </datalist>
                <p class="description">מאיפה למשוך מספר מעקב אם לא הוזן ידנית בהזמנה. ריק = רק השדה הידני. (משלוחי CARGO מזוהים אוטומטית גם בלי הגדרה.)</p>
            </td></tr>
            <tr><th>כתובת מעקב גורפת</th><td>
                <input type="text" name="tracking_url_base" value="<?php echo esc_attr($s['tracking_url_base']); ?>" class="wsn-w-lg" dir="ltr" placeholder="https://track.example.com/{number}">
                <p class="description">כתובת מעקב אחת לכל ההזמנות. <code dir="ltr">{number}</code> יוחלף במספר המעקב (ואם אין <code dir="ltr">{number}</code> — המספר יצורף לסוף הכתובת). כך <code dir="ltr">{tracking_url}</code> בתבניות יתמלא אוטומטית לכל הזמנה, גם ל-CARGO. השאר ריק אם אתה מזין קישור ידני פר-הזמנה.</p>
            </td></tr>
            <tr><th>מתי לבנות את הודעת המעקב</th><td>
                <select name="tracking_notify_status">
                    <option value="" <?php selected($s['tracking_notify_status'] ?? '', ''); ?>>מיד כשמתקבל מספר מעקב</option>
                    <?php if (function_exists('wc_get_order_statuses')): ?>
                        <?php foreach (wc_get_order_statuses() as $st_key => $st_label): ?>
                            <option value="<?php echo esc_attr($st_key); ?>" <?php selected($s['tracking_notify_status'] ?? '', $st_key); ?>>רק כשההזמנה בסטטוס: <?php echo esc_html($st_label); ?></option>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </select>
                <p class="description">ההודעה תמיד רק <b>נבנית</b> וממתינה לאישורך ב"הודעות ממתינות". "רק בסטטוס" = הטיוטה תיווצר כשההזמנה עם מספר המעקב נמצאת בסטטוס שנבחר — תופס את שני הכיוונים (מעקב שהתקבל לפני הסטטוס או אחריו).</p>
            </td></tr>
            <tr><th>הסכמת דיוור ב-checkout</th><td>
                <label><input type="checkbox" name="checkout_optin_enabled" value="1" <?php checked($s['checkout_optin_enabled']); ?>> הצג צ'קבוקס</label><br>
                <input type="text" name="checkout_optin_label" value="<?php echo esc_attr($s['checkout_optin_label']); ?>" class="wsn-w-lg">
            </td></tr>
            <tr><th>שליחת הודעות</th><td>
                <p class="description" style="margin-top:0">כל ההודעות (סטטוס ושינויים בהזמנה) נבנות אוטומטית אך <b>אינן נשלחות מעצמן</b> — הן ממתינות לאישורך במסך <a href="<?php echo esc_url(admin_url('admin.php?page=wsn-pending')); ?>"><b>הודעות ממתינות</b></a> וגם בעמוד ההזמנה. שם רואים את הטקסט המלא, עורכים, ולוחצים "שלח" או "מחק".</p>
            </td></tr>
            <tr><th>סיבות להסרת פריט / שינוי כמות</th><td>
                <textarea name="item_reasons_removed" rows="4" class="wsn-w-md" dir="rtl"><?php echo esc_textarea($s['item_reasons_removed']); ?></textarea>
                <p class="description">סיבה בכל שורה. מוצגות במודאל כשמסירים פריט או משנים כמות. "אחר" (הזנה ידנית) נוסף תמיד אוטומטית.</p>
            </td></tr>
            <tr><th>סיבות להוספת פריט</th><td>
                <textarea name="item_reasons_added" rows="4" class="wsn-w-md" dir="rtl"><?php echo esc_textarea($s['item_reasons_added']); ?></textarea>
                <p class="description">סיבה בכל שורה. מוצגות במודאל כשמוסיפים פריט להזמנה. "אחר" נוסף תמיד אוטומטית.</p>
            </td></tr>
            <tr><th>סיבות לשינוי מחיר</th><td>
                <textarea name="item_reasons_price" rows="4" class="wsn-w-md" dir="rtl"><?php echo esc_textarea($s['item_reasons_price']); ?></textarea>
                <p class="description">סיבה בכל שורה. מוצגות במודאל כשמשנים מחיר של פריט בהזמנה. "אחר" נוסף תמיד אוטומטית.</p>
            </td></tr>
            <tr><th>סיבות לביטול הזמנה</th><td>
                <textarea name="item_reasons_cancel" rows="4" class="wsn-w-md" dir="rtl"><?php echo esc_textarea($s['item_reasons_cancel']); ?></textarea>
                <p class="description">סיבה בכל שורה. מוצגות במודאל כשמעבירים הזמנה לסטטוס "בוטל", וההודעה ללקוח מורכבת מהסיבה שנבחרה. "אחר" נוסף תמיד אוטומטית.</p>
            </td></tr>
            <tr><th>מילות הסרה</th><td>
                <textarea name="optout_keywords" rows="3" class="wsn-w-md" dir="rtl"><?php echo esc_textarea($s['optout_keywords']); ?></textarea>
                <p class="description">מילה בכל שורה. לקוח ששולח אחת מהן — מוסר לצמיתות.</p>
            </td></tr>
        </tbody></table>

        <h2>כללי</h2>
        <table class="form-table"><tbody>
            <tr><th>מספר לבדיקות</th><td>
                <input type="text" name="test_phone" value="<?php echo esc_attr($s['test_phone']); ?>" class="wsn-w-sm" dir="ltr" placeholder="0501234567">
                <p class="description">כפתורי "שלח לי לבדיקה" בעמוד התבניות שולחים לכאן.</p>
            </td></tr>
            <tr><th>מייל להתראות</th><td>
                <input type="email" name="alert_email" value="<?php echo esc_attr($s['alert_email']); ?>" class="wsn-w-sm" dir="ltr">
            </td></tr>
            <tr><th>תפוגת הודעת סטטוס (שעות)</th><td>
                <input type="number" name="expiry_hours" value="<?php echo esc_attr($s['expiry_hours']); ?>" class="wsn-w-num">
                <p class="description">הודעה שלא נשלחה בזמן הזה (גשר כבוי) פגה — כי "נשלח" באיחור של ימים גרוע מכלום.</p>
            </td></tr>
            <tr><th>שמירת יומן (ימים)</th><td>
                <input type="number" name="retention_days" value="<?php echo esc_attr($s['retention_days']); ?>" class="wsn-w-num">
            </td></tr>
            <tr><th>מחיקה בהסרת התוסף</th><td>
                <label><input type="checkbox" name="delete_data_on_uninstall" value="1" <?php checked($s['delete_data_on_uninstall']); ?>>
                    מחק את כל הנתונים (מועדון, יומן) בעת הסרת התוסף</label>
                <p class="description">כבוי כברירת מחדל — המועדון הוא נכס עסקי.</p>
            </td></tr>
            <tr><th>עדכון תוסף אוטומטי</th><td>
                <input type="password" name="github_token" value="" autocomplete="off" class="wsn-w-lg" dir="ltr" placeholder="<?php echo $s['github_token'] ? '•••••••• (מוגדר — השאר ריק כדי לא לשנות)' : 'ghp_... או github_pat_...'; ?>">
                <p class="description">GitHub token עם גישה ל-repo הפרטי wa-store-notify. משמש לבדיקת עדכונים והורדתם דרך עמוד התוספים הרגיל של WP.
                    סטטוס: <?php echo $s['github_token'] ? '<span class="wsn-good">מוגדר ✔</span>' : '<span class="wsn-bad">לא מוגדר</span>'; ?></p>
            </td></tr>
        </tbody></table>

        <?php submit_button('שמור הגדרות'); ?>
    </form>

    <hr>
    <h2>חיבור הגשר (API)</h2>
    <?php if ($new_key): ?>
        <div class="notice notice-warning"><p>
            <b>המפתח החדש (מוצג פעם אחת בלבד — העתק עכשיו):</b><br>
            <code style="font-size:14px;direction:ltr;display:inline-block;margin-top:6px"><?php echo esc_html($new_key); ?></code>
        </p></div>
    <?php endif; ?>
    <p>סטטוס מפתח: <?php echo WSN_Api_Key::exists() ? '<span class="wsn-good">מוגדר ✔</span>' : '<span class="wsn-bad">לא נוצר עדיין</span>'; ?></p>
    <p class="description">הכנס ב-<code>bridge/.env</code>: <code>WP_URL</code> = כתובת האתר, <code>API_KEY</code> = המפתח הזה.</p>
    <form method="post" action="<?php echo esc_url($post); ?>" onsubmit="return confirm('ליצור מפתח חדש? המפתח הקודם יפסיק לעבוד מיד.');">
        <?php wp_nonce_field('wsn_api_key'); ?>
        <input type="hidden" name="action" value="wsn_api_key">
        <button class="button"><?php echo WSN_Api_Key::exists() ? 'צור מפתח חדש (רוטציה)' : 'צור מפתח'; ?></button>
    </form>
</div>
