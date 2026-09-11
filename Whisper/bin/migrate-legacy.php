#!/usr/bin/env php
<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__, 3).'/app/common.php';
use Kanboard\Plugin\Whisper\Service\Settings;
$dir = DATA_DIR.'/whisper';
if (!is_dir($dir)) { mkdir($dir, 0755, true); }
$lock = fopen($dir.'/worker.lock', 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) { fwrite(STDERR, "Stop the Whisper worker before migration.\n"); exit(1); }
$userId = (int)($argv[1] ?? 0);
$user = $container['userModel']->getById($userId);
if (!$user || !$user['is_active'] || $user['role'] !== 'app-admin') { fwrite(STDERR, "Specify an active administrator ID.\n"); exit(1); }
$personal = Settings::read($container, $userId);
if ($personal['bot_token']) { fwrite(STDERR, "Personal connection already exists; no changes made.\n"); exit(1); }
$legacy = Settings::read($container);
if (!$legacy['bot_token']) { fwrite(STDERR, "No legacy connection.\n"); exit(1); }
$legacy['owner_id'] = $userId;
Settings::validate($legacy, $container);
if (!Settings::save($container, $userId, $legacy)) { exit(1); }
// Preserve the polling cursor, choices and previews. Run with the worker stopped.
$botId = explode(':', $legacy['bot_token'])[0];
$old = DATA_DIR.'/whisper/bot-'.$botId.'.json';
$new = DATA_DIR.'/whisper/user-'.$userId.'-bot-'.$botId.'.json';
if (is_file($old) && !is_file($new)) { copy($old, $new); chmod($new, 0600); }
$container['configModel']->save(['whisper_bot_token' => '', 'whisper_api_key' => '', 'whisper_enabled' => '0']);
echo "Legacy connection migrated to user ",$userId,".\n";
