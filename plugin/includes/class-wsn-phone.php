<?php
defined('ABSPATH') || exit;

class WSN_Phone
{
    /**
     * נרמול מספר טלפון ל-E.164 (ספרות בלבד, בלי +). ישראל: 05x → 9725x.
     * מחזיר null אם לא ניתן לנרמל בבטחה.
     */
    public static function to_e164(?string $raw): ?string
    {
        if ($raw === null) {
            return null;
        }
        $raw = trim($raw);
        if ($raw === '') {
            return null;
        }
        $has_plus = strpos($raw, '+') === 0;
        $digits = preg_replace('/\D/', '', $raw);
        if ($digits === '') {
            return null;
        }
        if (strpos($digits, '00') === 0) {
            $digits = substr($digits, 2);
            $has_plus = true;
        }

        if (strpos($digits, '972') === 0) {
            // כבר בינלאומי ישראלי; לטפל ב-9720x שנוצר מ"+972 05x"
            if (strpos($digits, '9720') === 0) {
                $digits = '972' . substr($digits, 4);
            }
        } elseif (strpos($digits, '0') === 0 && (strlen($digits) === 9 || strlen($digits) === 10)) {
            $digits = '972' . substr($digits, 1);
        }

        if (preg_match('/^9725\d{8}$/', $digits)) {
            return $digits; // נייד ישראלי תקין
        }
        if (preg_match('/^972[23489]\d{7}$/', $digits)) {
            return $digits; // קווי ישראלי — לרוב לא וואטסאפ, הגשר יאמת לפני שליחה
        }
        if ($has_plus && preg_match('/^\d{10,15}$/', $digits) && strpos($digits, '972') !== 0) {
            return $digits; // מספר זר שסופק במפורש עם קידומת בינלאומית
        }
        return null;
    }

    /**
     * תצוגה מקומית קריאה: 972501234567 → 050-1234567, 97221234567 → 02-1234567.
     * מספר שאינו ישראלי מוצג כמו שהוא עם + מקדים. תצוגה בלבד — הערך שנשמר
     * ונשלח לוואטסאפ נשאר תמיד E.164.
     */
    public static function to_display(?string $e164): string
    {
        $digits = preg_replace('/\D/', '', (string) $e164);
        if ($digits === '') {
            return '';
        }
        if (strpos($digits, '972') === 0) {
            $local = '0' . substr($digits, 3);
            // נייד: 05X-XXXXXXX (10 ספרות) | קווי: 0X-XXXXXXX (9 ספרות)
            if (preg_match('/^(05\d)(\d{7})$/', $local, $m)) {
                return $m[1] . '-' . $m[2];
            }
            if (preg_match('/^(0\d)(\d{7})$/', $local, $m)) {
                return $m[1] . '-' . $m[2];
            }
            return $local;
        }
        return '+' . $digits;
    }
}
