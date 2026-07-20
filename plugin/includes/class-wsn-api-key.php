<?php
defined('ABSPATH') || exit;

class WSN_Api_Key
{
    /**
     * יוצר מפתח חדש (מבטל את הקודם), מחזיר את הטקסט הגלוי — מוצג פעם אחת בלבד.
     * ב-DB נשמר hash בלבד.
     */
    public static function generate(): string
    {
        $key = 'wsn_' . bin2hex(random_bytes(24));
        update_option('wsn_api_key_hash', hash('sha256', $key), false);
        return $key;
    }

    public static function exists(): bool
    {
        return (bool) get_option('wsn_api_key_hash');
    }

    public static function verify(?string $key): bool
    {
        $stored = get_option('wsn_api_key_hash');
        if (!$stored || !$key) {
            return false;
        }
        return hash_equals($stored, hash('sha256', $key));
    }
}
