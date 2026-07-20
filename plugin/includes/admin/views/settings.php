<?php defined('ABSPATH') || exit;
/** @var array $s */
$new_key = get_transient('wsn_new_api_key');
delete_transient('wsn_new_api_key');
$post = admin_url('admin-post.php');
?>
<div class="wrap wsn" dir="rtl">
    <h1>הגדרות</h1>

    <form method="post" action="<?php echo esc_url($post); ?>">
        <?php wp_nonce_field('wsn_save_settings'); ?>
        <input type="hidden" name="action" value="wsn_save_settings">

        <h2>קצב שליחה — הודעות טרנזקציוניות</h2>
        <table class="form-table"><tbody>
            <tr><th>מרווח בין הודעות (שניות)</th><td>
                מ־<input type="number" name="trans_min_gap_s" value="<?php echo esc_attr($s['trans_min_gap_s']); ?>" min="5" style="width:80px">
                עד <input type="number" name="trans_max_gap_s" value="<?php echo esc_attr($s['trans_max_gap_s']); ?>" min="5" style="width:80px">
                <p class="description">מרווח אקראי בטווח הזה בין כל הודעה — מרכיב מרכזי במניעת חסימה.</p>
            </td></tr>
            <tr><th>מגבלת שליחה</th><td>
                <input type="number" name="trans_hourly_cap" value="<?php echo esc_attr($s['trans_hourly_cap']); ?>" style="width:80px"> לשעה,
                <input type="number" name="trans_daily_cap" value="<?php echo esc_attr($s['trans_daily_cap']); ?>" style="width:80px"> ליום
            </td></tr>
            <tr><th>שעות שקט (לא שולחים)</th><td>
                מ־<input type="text" name="quiet_from" value="<?php echo esc_attr($s['quiet_from']); ?>" style="width:70px" dir="ltr">
                עד <input type="text" name="quiet_to" value="<?php echo esc_attr($s['quiet_to']); ?>" style="width:70px" dir="ltr">
            </td></tr>
        </tbody></table>

        <h2>קצב שליחה — קמפיינים (דיוור)</h2>
        <table class="form-table"><tbody>
            <tr><th>מרווח בין הודעות (שניות)</th><td>
                מ־<input type="number" name="camp_min_gap_s" value="<?php echo esc_attr($s['camp_min_gap_s']); ?>" min="30" style="width:80px">
                עד <input type="number" name="camp_max_gap_s" value="<?php echo esc_attr($s['camp_max_gap_s']); ?>" min="30" style="width:80px">
            </td></tr>
            <tr><th>מגבלת שליחה</th><td>
                <input type="number" name="camp_hourly_cap" value="<?php echo esc_attr($s['camp_hourly_cap']); ?>" style="width:80px"> לשעה,
                <input type="number" name="camp_daily_cap" value="<?php echo esc_attr($s['camp_daily_cap']); ?>" style="width:80px"> ליום
            </td></tr>
            <tr><th>חלון שליחת קמפיינים</th><td>
                מ־<input type="text" name="camp_window_from" value="<?php echo esc_attr($s['camp_window_from']); ?>" style="width:70px" dir="ltr">
                עד <input type="text" name="camp_window_to" value="<?php echo esc_attr($s['camp_window_to']); ?>" style="width:70px" dir="ltr">
                <p class="description">דיוור נשלח רק בחלון הזה (רצוי אמצע היום), בקצב איטי בהרבה מהודעות סטטוס.</p>
            </td></tr>
        </tbody></table>

        <h2>מניעת חסימה מתקדם</h2>
        <table class="form-table"><tbody>
            <tr><th>שגיאות כתיב מכוונות</th><td>
                אחת לכל <input type="number" name="typo_ratio" value="<?php echo esc_attr($s['typo_ratio']); ?>" min="0" style="width:70px"> הודעות
                <p class="description">הגשר "יטעה" במילה וישלח תיקון תוך שניות (התנהגות אנושית). 0 = כבוי. לעולם לא בתוך מספר מעקב/הזמנה/קישור.</p>
            </td></tr>
            <tr><th>מצב חימום (מספר טרי)</th><td>
                <label><input type="checkbox" name="warmup_enabled" value="1" <?php checked($s['warmup_enabled']); ?>> הפעל</label>
                החל מתאריך <input type="text" name="warmup_started" value="<?php echo esc_attr($s['warmup_started']); ?>" placeholder="2026-07-20" style="width:120px" dir="ltr">
                <p class="description">מגביל שליחה הדרגתית (10→25→50→100 ליום) בימים הראשונים של מספר חדש.</p>
            </td></tr>
        </tbody></table>

        <h2>משלוחים והצטרפות</h2>
        <table class="form-table"><tbody>
            <tr><th>שדה meta למספר מעקב</th><td>
                <input type="text" name="tracking_meta_key" value="<?php echo esc_attr($s['tracking_meta_key']); ?>" style="width:320px" dir="ltr" list="wsn-meta-presets">
                <datalist id="wsn-meta-presets">
                    <option value="_wc_shipment_tracking_items"><option value="ywot_tracking_code">
                </datalist>
                <p class="description">מאיפה למשוך מספר מעקב אם לא הוזן ידנית בהזמנה. ריק = רק השדה הידני.</p>
            </td></tr>
            <tr><th>הסכמת דיוור ב-checkout</th><td>
                <label><input type="checkbox" name="checkout_optin_enabled" value="1" <?php checked($s['checkout_optin_enabled']); ?>> הצג צ'קבוקס</label><br>
                <input type="text" name="checkout_optin_label" value="<?php echo esc_attr($s['checkout_optin_label']); ?>" style="width:420px">
            </td></tr>
            <tr><th>מילות הסרה</th><td>
                <textarea name="optout_keywords" rows="3" style="width:320px" dir="rtl"><?php echo esc_textarea($s['optout_keywords']); ?></textarea>
                <p class="description">מילה בכל שורה. לקוח ששולח אחת מהן — מוסר לצמיתות.</p>
            </td></tr>
        </tbody></table>

        <h2>כללי</h2>
        <table class="form-table"><tbody>
            <tr><th>מספר לבדיקות</th><td>
                <input type="text" name="test_phone" value="<?php echo esc_attr($s['test_phone']); ?>" style="width:200px" dir="ltr" placeholder="0501234567">
                <p class="description">כפתורי "שלח לי לבדיקה" בעמוד התבניות שולחים לכאן.</p>
            </td></tr>
            <tr><th>מייל להתראות</th><td>
                <input type="email" name="alert_email" value="<?php echo esc_attr($s['alert_email']); ?>" style="width:260px" dir="ltr">
            </td></tr>
            <tr><th>תפוגת הודעת סטטוס (שעות)</th><td>
                <input type="number" name="expiry_hours" value="<?php echo esc_attr($s['expiry_hours']); ?>" style="width:80px">
                <p class="description">הודעה שלא נשלחה בזמן הזה (גשר כבוי) פגה — כי "נשלח" באיחור של ימים גרוע מכלום.</p>
            </td></tr>
            <tr><th>שמירת יומן (ימים)</th><td>
                <input type="number" name="retention_days" value="<?php echo esc_attr($s['retention_days']); ?>" style="width:80px">
            </td></tr>
            <tr><th>מחיקה בהסרת התוסף</th><td>
                <label><input type="checkbox" name="delete_data_on_uninstall" value="1" <?php checked($s['delete_data_on_uninstall']); ?>>
                    מחק את כל הנתונים (מועדון, יומן) בעת הסרת התוסף</label>
                <p class="description">כבוי כברירת מחדל — המועדון הוא נכס עסקי.</p>
            </td></tr>
            <tr><th>עדכון תוסף אוטומטי</th><td>
                <input type="password" name="github_token" value="" autocomplete="off" style="width:420px" dir="ltr" placeholder="<?php echo $s['github_token'] ? '•••••••• (מוגדר — השאר ריק כדי לא לשנות)' : 'ghp_... או github_pat_...'; ?>">
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
