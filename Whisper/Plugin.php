<?php
namespace Kanboard\Plugin\Whisper;

use Kanboard\Plugin\Whisper\Service\I18n;
use Kanboard\Core\Plugin\Base;
use Kanboard\Core\Security\Role;

class Plugin extends Base
{
    public function initialize()
    {
        $this->helper->register('whisper', '\\Kanboard\\Plugin\\Whisper\\Helper\\WhisperHelper');
        $this->template->hook->attach('template:config:sidebar', 'Whisper:config/sidebar');
        $this->applicationAccessMap->add('WhisperController', '*', Role::APP_USER);
        $this->applicationAccessMap->add('WhisperController', 'permissions', Role::APP_ADMIN);
        $this->template->hook->attach('template:user:sidebar:actions', 'Whisper:config/user_sidebar');
    }
    public function onStartup()
    {
        \Kanboard\Core\Translator::load($this->languageModel->getCurrentLanguage(), __DIR__.'/Locale');
    }
    public function getPluginName() { return 'Whisper'; }
    public function getPluginDescription() { return I18n::text('Telegram → local Whisper → Kanboard tasks. Optional DeepSeek and OpenRouter.'); }
    public function getPluginAuthor() { return 'izzipizzy'; }
    public function getPluginHomepage() { return 'https://github.com/izzipizzy/kanboard-whisper'; }
    public function getPluginVersion() { return '0.1.0'; }
    public function getCompatibleVersion() { return '>=1.2.54'; }
}
