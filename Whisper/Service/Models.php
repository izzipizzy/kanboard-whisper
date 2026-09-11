<?php
namespace Kanboard\Plugin\Whisper\Service;

class Models
{
    public static function fetch(array $settings, Http $http)
    {
        if ($settings['provider'] === 'none') { return []; }
        $direct = $settings['provider'] === 'deepseek';
        $response = $http->request(
            $direct ? 'https://api.deepseek.com/models' : 'https://openrouter.ai/api/v1/models', null,
            $direct ? ['Authorization: Bearer '.$settings['api_key']] : [], 20, 10485760
        );
        $data = json_decode($response, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($data['data'] ?? null)) { throw new \RuntimeException(I18n::text('Invalid model list.')); }
        $models = [];
        foreach ($data['data'] as $model) {
            $id = $model['id'] ?? '';
            if (!is_string($id) || $id === '' || strlen($id) > 200) { continue; }
            if (!$direct && strpos($id, 'deepseek/') !== 0) { continue; }
            $name = is_string($model['name'] ?? null) ? $model['name'] : $id;
            $models[$id] = $name === $id ? $id : $name.' — '.$id;
        }
        asort($models);
        if (!$models) { throw new \RuntimeException(I18n::text('No DeepSeek models were returned by the provider.')); }
        return $models;
    }
    public static function clearCache($userId)
    {
        session_set('whisper_model_catalog_'.(int)$userId, null);
    }
    public static function cached(array $settings, $userId, ?Http $http = null)
    {
        if ($settings['provider'] === 'none' || !$settings['api_key']) { return ['models' => [], 'error' => '']; }
        $key = 'whisper_model_catalog_'.(int)$userId;
        $identity = hash('sha256', $settings['provider'].':'.$settings['api_key']);
        $cached = session_get($key);
        if (!is_array($cached) || ($cached['identity'] ?? '') !== $identity || ($cached['expires'] ?? 0) < time()) {
            try {
                $cached = ['models' => self::fetch($settings, $http ?: new Http()), 'error' => '', 'expires' => time() + 3600];
            } catch (\Throwable $e) {
                $cached = ['models' => [], 'error' => 'unavailable', 'expires' => time() + 60];
            }
            $cached['identity'] = $identity;
            session_set($key, $cached);
        }
        if ($cached['error']) { $cached['error'] = I18n::text('Could not load models. Refresh the list or enter a model ID manually.'); }
        return $cached;
    }
}
