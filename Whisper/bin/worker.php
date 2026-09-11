#!/usr/bin/env php
<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
umask(0077);
require dirname(__DIR__, 3).'/app/common.php';
$container['dispatcher']->dispatch(new \Symfony\Contracts\EventDispatcher\Event(), 'app.bootstrap');
$dir = DATA_DIR.'/whisper';
if (!is_dir($dir) && !mkdir($dir, 0700, true)) { throw new RuntimeException('Cannot create worker state directory'); }
chmod($dir, 0755);
$lock = fopen($dir.'/worker.lock', 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) { fwrite(STDERR, "Another Whisper worker is running.\n"); exit(1); }
fwrite(STDOUT, "Whisper worker ready; waiting for enabled configuration.\n");
while (true) {
    file_put_contents($dir.'/heartbeat', (string)time());
    chmod($dir.'/heartbeat', 0644);
    $container['memoryCache']->flush();
    $connections = [];
    $counts = [];
    foreach ($container['db']->table('users')->eq('is_active', 1)->findAll() as $user) {
        if (!\Kanboard\Plugin\Whisper\Service\Access::allowed($container, $user['id'])) { continue; }
        $settings = \Kanboard\Plugin\Whisper\Service\Settings::read($container, $user['id']);
        if ($settings['enabled'] !== '1' || !$settings['bot_token']) { continue; }
        $botId = explode(':', $settings['bot_token'])[0];
        $counts[$botId] = ($counts[$botId] ?? 0) + 1;
        $connections[] = $settings;
    }
    $identities = array_column($container['db']->table('whisper_connections')->findAll(), 'identity');
    foreach (glob($dir.'/connection-*.json') ?: [] as $file) {
        if (preg_match('/^connection-([a-f0-9]{32})-bot-[0-9]+\.json$/D', basename($file), $match) && !in_array($match[1], $identities, true)) { unlink($file); }
    }
    foreach (glob($dir.'/user-*-bot-*.json') ?: [] as $file) {
        if (preg_match('/^user-([0-9]+)-bot-[0-9]+\.json$/D', basename($file), $match)) {
            $legacyConnection = \Kanboard\Plugin\Whisper\Service\Settings::connection($container, (int)$match[1]);
            if (!$legacyConnection || !$legacyConnection['legacy_state']) { unlink($file); }
        }
    }
    if (!$connections) { sleep(5); continue; }
    foreach ($connections as $settings) {
        try {
            $container['memoryCache']->flush();
            file_put_contents($dir.'/heartbeat', (string)time());
            $botId = explode(':', $settings['bot_token'])[0];
            // Never consume updates for a bot claimed by more than one account.
            if ($counts[$botId] !== 1) { continue; }
            $connection = \Kanboard\Plugin\Whisper\Service\Settings::connection($container, $settings['owner_id']);
            $statePath = $dir.'/connection-'.$connection['identity'].'-bot-'.$botId.'.json';
            if ($connection['legacy_state']) {
                $old = $dir.'/user-'.$settings['owner_id'].'-bot-'.$botId.'.json';
                if (is_file($old) && !is_file($statePath) && !rename($old, $statePath)) { throw new RuntimeException('Could not migrate bot state'); }
                $container['db']->table('whisper_connections')->eq('user_id', $settings['owner_id'])->update(['legacy_state' => 0]);
            }
            $bot = new \Kanboard\Plugin\Whisper\Service\Bot($container, new \Kanboard\Plugin\Whisper\Service\Http(), $settings, $statePath);
            $bot->poll(count($connections) === 1 ? 25 : 1);
        } catch (Throwable $e) {
            fwrite(STDERR, 'Whisper connection '.$settings['owner_id'].' retry: '.get_class($e).' HTTP/status '.(int)$e->getCode().". Check configuration/connectivity.\n");
            sleep(2);
        }
    }
    usleep(200000);
}
