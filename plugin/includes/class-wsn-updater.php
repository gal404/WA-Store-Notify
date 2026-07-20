<?php
defined('ABSPATH') || exit;

// עדכון אוטומטי מ-GitHub פרטי (הריפו הוא מונוריפו — plugin/ + bridge/ — לכן
// חובה release asset ולא source zip רגיל, אחרת WP יתקין את כל הריפו).
class WSN_Updater
{
    const REPO_URL = 'https://github.com/gal404/WA-Store-Notify/';

    public static function init(): void
    {
        $lib = WSN_PLUGIN_DIR . 'vendor/plugin-update-checker/plugin-update-checker.php';
        if (!file_exists($lib)) {
            return;
        }
        require_once $lib;

        $token = (string) WSN_Settings::get('github_token');
        if ($token === '') {
            return; // בלי טוקן אין מה לבדוק — repo פרטי
        }

        $updateChecker = \YahnisElsts\PluginUpdateChecker\v5p7\PucFactory::buildUpdateChecker(
            self::REPO_URL,
            WSN_PLUGIN_FILE,
            'wa-store-notify'
        );
        $updateChecker->setAuthentication($token);
        $updateChecker->setBranch('main');
        $updateChecker->getVcsApi()->enableReleaseAssets();
    }
}
