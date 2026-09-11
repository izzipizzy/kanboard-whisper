<?php
namespace Kanboard\Plugin\Whisper\Service;

class Settings
{
    const DEFAULTS = [
        'enabled' => '0', 'bot_token' => '', 'allowed_users' => '',
        'project_id' => '0', 'category_id' => '0', 'column_id' => '0',
        'provider' => 'none', 'api_key' => '', 'model' => '', 'confirm' => '1',
        'language' => 'ru', 'normalization_prompt' => Normalizer::DEFAULT_PROMPT,
    ];
    public static function connection($c, $userId)
    {
        return $c['db']->table('whisper_connections')->eq('user_id', (int)$userId)->findOne();
    }
    public static function ensure($c, $userId)
    {
        $row = self::connection($c, $userId);
        if (!$row) {
            $c['db']->table('whisper_connections')->insert(['user_id' => (int)$userId, 'identity' => bin2hex(random_bytes(16)), 'settings' => '{}']);
            $row = self::connection($c, $userId);
        }
        return $row;
    }
    public static function read($container, $userId = 0)
    {
        if (!$userId) {
            $all = $container['configModel']->getAll(); $values = self::DEFAULTS;
            foreach ($values as $key => $default) { $values[$key] = $all['whisper_'.$key] ?? $default; }
        } else {
            $row = self::connection($container, $userId);
            $values = array_replace(self::DEFAULTS, $row ? json_decode($row['settings'], true, 512, JSON_THROW_ON_ERROR) : []);
        }
        $values['owner_id'] = (int)$userId;
        if ($userId && $row) { $values['connection_identity'] = $row['identity']; }
        return $values;
    }
    public static function save($container, $userId, array $values)
    {
        self::ensure($container, $userId);
        $save = array_intersect_key(array_replace(self::DEFAULTS, $values), self::DEFAULTS);
        return $container['db']->table('whisper_connections')->eq('user_id', (int)$userId)->update(['settings' => json_encode($save, JSON_THROW_ON_ERROR)]);
    }
    public static function validate(array $values, $container)
    {
        if (mb_strlen($values['normalization_prompt'] ?? '') > 8000) { throw new \RuntimeException(I18n::text('The prompt must be at most 8000 characters.')); }
        if (!in_array($values['provider'], ['none', 'deepseek', 'openrouter'], true)) {
            throw new \RuntimeException(I18n::text('Unknown provider.'));
        }
        if (!in_array($values['language'], ['ru', 'en', 'auto'], true)) {
            throw new \RuntimeException(I18n::text('Unknown language.'));
        }
        if ($values['bot_token'] !== '' && !preg_match('/^\d+:[A-Za-z0-9_-]+$/D', $values['bot_token'])) {
            throw new \RuntimeException(I18n::text('Invalid Telegram token format.'));
        }
        if ($values['allowed_users'] !== '' && !preg_match('/^\d+(?:[\s,]+\d+)*$/D', $values['allowed_users'])) {
            throw new \RuntimeException(I18n::text('Enter numeric Telegram IDs separated by commas.'));
        }
        if ($values['enabled'] === '1') {
            if (!$values['bot_token']) { throw new \RuntimeException(I18n::text('Enter the bot token from @BotFather.')); }
            if (!$values['allowed_users']) { throw new \RuntimeException(I18n::text('Enter an allowed Telegram ID.')); }
            if (!(int)$values['project_id']) { throw new \RuntimeException(I18n::text('Choose a project for tasks.')); }
        }
        $project = (int)$values['project_id'];
        if ($project && !$container['db']->table('projects')->eq('id', $project)->eq('is_active', 1)->exists()) {
            throw new \RuntimeException(I18n::text('Choose an active project.'));
        }
        foreach (['category_id' => 'project_has_categories', 'column_id' => 'columns'] as $key => $table) {
            if ((int)$values[$key] && !$container['db']->table($table)->eq('id', (int)$values[$key])->eq('project_id', $project)->exists()) {
                throw new \RuntimeException(I18n::text('The category or column does not belong to the selected project.'));
            }
        }
        $owner = (int)($values['owner_id'] ?? 0);
        if ($owner) {
            if (!Access::allowed($container, $owner)) { throw new \RuntimeException(I18n::text('Your administrator has not granted you access to this plugin.')); }
            if ($project && !Access::canCreate($container, $owner, $project, (int)$values['column_id'] ?: null)) {
                throw new \RuntimeException(I18n::text('You cannot create tasks in this project or column.'));
            }
            if ($values['bot_token']) {
                $botId = explode(':', $values['bot_token'])[0];
                foreach ($container['db']->table('users')->findAll() as $user) {
                    if ((int)$user['id'] === $owner) { continue; }
                    $other = self::read($container, $user['id']);
                    if ($other['bot_token'] && explode(':', $other['bot_token'])[0] === $botId) {
                        throw new \RuntimeException(I18n::text('This bot is already connected to another account. Create a separate bot.'));
                    }
                }
            }
        }
        if ($values['provider'] !== 'none' && !$values['api_key']) {
            throw new \RuntimeException(I18n::text('Add an API key or disable normalization.'));
        }
    }
}
