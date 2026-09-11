<?php
namespace Kanboard\Plugin\Whisper\Service;

use Kanboard\Core\Translator;

class I18n
{
    public static function language($container, $userId)
    {
        $user = $container['userModel']->getById($userId);
        $language = ($user['language'] ?? '') ?: $container['configModel']->getOption('application_language', 'en_US');
        return strpos($language, 'ru') === 0 ? 'ru_RU' : 'en_US';
    }
    public static function activate($container, $userId)
    {
        $language = self::language($container, $userId);
        // The worker serves multiple accounts in one process; never carry a locale
        // from a Russian connection into an English one (or vice versa).
        Translator::unload();
        Translator::load($language);
        Translator::load($language, dirname(__DIR__).'/Locale');
        return $language;
    }
    public static function text($message)
    {
        // Telegram expects plain text, not HTML entities. Web templates escape output.
        return Translator::getInstance()->translateNoEscaping($message);
    }
}
