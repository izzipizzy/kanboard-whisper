<?php
namespace Kanboard\Plugin\Whisper\Service;

class Bot
{
    private $container;
    private $http;
    private $settings;
    private $state;
    private $path;
    public function __construct($container, Http $http, array $settings, $path)
    {
        $this->container = $container; $this->http = $http; $this->settings = $settings; $this->path = $path;
        $this->settings['connection_identity'] = $settings['connection_identity'] ?? Settings::ensure($container, (int)$settings['owner_id'])['identity'];
        I18n::activate($container, (int)($settings['owner_id'] ?? 0));
        $this->state = is_file($path) ? json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR) : [];
        if (!is_array($this->state)) { throw new \RuntimeException(I18n::text('Bot state is corrupted.')); }
        $this->state += ['offset' => 0, 'users' => []];
        foreach ($this->state['users'] as $id => $user) {
            if (!empty($user['draft']) && ($user['draft']['created_at'] ?? 0) < time() - 86400) { $this->state['users'][$id]['draft'] = null; }
        }
    }
    public function save()
    {
        $temp = $this->path.'.tmp';
        if (file_put_contents($temp, json_encode($this->state, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), LOCK_EX) === false || !rename($temp, $this->path)) {
            throw new \RuntimeException(I18n::text('Could not save bot state.'));
        }
    }
    public function telegram($method, array $payload)
    {
        $response = $this->http->json('https://api.telegram.org/bot'.$this->settings['bot_token'].'/'.$method, $payload, [], 65);
        if (empty($response['ok'])) { throw new \RuntimeException(I18n::text('Telegram rejected the request.'), (int)($response['error_code'] ?? 0)); }
        return $response['result'];
    }
    private function send($chat, $text, array $buttons = [])
    {
        $payload = ['chat_id' => $chat, 'text' => mb_substr($text, 0, 3900), 'link_preview_options' => ['is_disabled' => true]];
        if ($buttons) { $payload['reply_markup'] = ['inline_keyboard' => $buttons]; }
        return $this->telegram('sendMessage', $payload);
    }
    public function syncCommands()
    {
        I18n::activate($this->container, (int)$this->settings['owner_id']);
        $commands = [
            ['command' => 'projects', 'description' => I18n::text('Choose a project for tasks')],
            ['command' => 'swimlanes', 'description' => I18n::text('Choose a swimlane in the project')],
            ['command' => 'type', 'description' => I18n::text('Choose a task category')],
            ['command' => 'status', 'description' => I18n::text('Show the task destination')],
            ['command' => 'cancel', 'description' => I18n::text('Cancel the current draft')],
            ['command' => 'help', 'description' => I18n::text('How to use the bot')],
        ];
        $version = hash('sha256', json_encode($commands));
        if (($this->state['commands_version'] ?? '') === $version) { return; }
        $this->telegram('setMyCommands', ['commands' => $commands]);
        $this->telegram('setChatMenuButton', ['menu_button' => ['type' => 'commands']]);
        $this->state['commands_version'] = $version;
        $this->save();
    }
    public function poll($timeout = 25)
    {
        $this->syncCommands();
        $updates = $this->telegram('getUpdates', ['offset' => $this->state['offset'], 'timeout' => $timeout, 'limit' => 10, 'allowed_updates' => ['message', 'callback_query']]);
        foreach ($updates as $update) {
            $fresh = Settings::read($this->container, (int)$this->settings['owner_id']);
            if ($fresh['enabled'] !== '1' || $fresh['bot_token'] !== $this->settings['bot_token'] || ($fresh['connection_identity'] ?? '') !== $this->settings['connection_identity']) { break; }
            $fresh['connection_identity'] = $this->settings['connection_identity'];
            $this->settings = $fresh;
            $failure = $this->state['retry'] ?? [];
            if (($failure['update_id'] ?? null) === $update['update_id'] && ($failure['after'] ?? 0) > time()) { break; }
            try { $this->process($update); }
            catch (\Throwable $e) {
                $attempt = ($failure['update_id'] ?? null) === $update['update_id'] ? $failure['attempt'] + 1 : 1;
                $permanent = in_array($e->getCode(), [400, 401, 403, 404], true);
                if (!$permanent && $attempt < 3) {
                    $this->state['retry'] = ['update_id' => $update['update_id'], 'attempt' => $attempt, 'after' => time() + 5 * $attempt];
                    $this->save(); break;
                }
                error_log('Whisper: skipped undeliverable update after bounded retries (no message content logged).');
            }
            unset($this->state['retry']);
            $this->state['offset'] = $update['update_id'] + 1;
            $this->save();
        }
    }
    public function process(array $update)
    {
        I18n::activate($this->container, (int)($this->settings['owner_id'] ?? 0));
        if (!Access::allowed($this->container, (int)($this->settings['owner_id'] ?? 0)) || (Settings::connection($this->container, $this->settings['owner_id'])['identity'] ?? '') !== $this->settings['connection_identity']) { return; }
        $callback = $update['callback_query'] ?? null;
        $message = $callback['message'] ?? $update['message'] ?? [];
        $sender = $callback['from']['id'] ?? $message['from']['id'] ?? 0;
        $chat = $message['chat']['id'] ?? 0;
        $allowed = preg_split('/[\s,]+/', trim($this->settings['allowed_users']), -1, PREG_SPLIT_NO_EMPTY);
        if (($message['chat']['type'] ?? '') !== 'private' || !in_array((string)$sender, $allowed, true) || (string)$sender !== (string)$chat) { return; }
        $this->state['users'][$sender] = ($this->state['users'][$sender] ?? []) + ['category' => null, 'draft' => null];
        $user =& $this->state['users'][$sender];
        $user['touched'] = time();
        try {
            if ($user['draft'] && ($user['draft']['created_at'] ?? 0) < time() - 86400) { $user['draft'] = null; }
            if ($callback) {
                try { $this->telegram('answerCallbackQuery', ['callback_query_id' => $callback['id']]); } catch (\Throwable $ignored) {}
                $data = $callback['data'] ?? '';
                if (preg_match('/^project:(\d+)$/D', $data, $match)) {
                    $next = $user;
                    $next['selected_project'] = (int)$match[1];
                    $next['project'] = (int)$match[1];
                    $next['category'] = null;
                    $next['swimlane'] = 0;
                    $this->destination($next); // Validate before changing a persisted preference.
                    $user = $next;
                    $this->selectionChanged($chat, $user);
                    return;
                }
                if (preg_match('/^(type|lane):(\d+):(\d+)$/D', $data, $match)) {
                    if ((int)$match[2] !== $this->projectId($user)) { throw new \RuntimeException(I18n::text('The project has changed. Open the list again.')); }
                    $next = $user;
                    if ($match[1] === 'type') { $next['category'] = (int)$match[3]; $next['project'] = (int)$match[2]; }
                    else { $next['swimlane'] = (int)$match[3]; }
                    $this->destination($next);
                    $user = $next;
                    $this->selectionChanged($chat, $user);
                    return;
                }
                if (!preg_match('/^(create|cancel|rewrite):([a-f0-9]+)$/D', $data, $match) || !$user['draft'] || $user['draft']['nonce'] !== $match[2]) {
                    $this->send($chat, I18n::text('This draft has already been processed or replaced. Send a new message.')); return;
                }
                if ($match[1] === 'cancel') { $user['draft'] = null; $this->save(); $this->send($chat, '✖️ '.I18n::text('Draft cancelled.')); return; }
                if ($match[1] === 'rewrite') { $this->rewrite($chat, $user); return; }
                $this->create($chat, $user); return;
            }
            $text = trim($message['text'] ?? '');
            if (in_array($text, ['/start', '/help'], true)) {
                $this->send($chat, I18n::text('Send a voice note or text to prepare a task.
/projects — choose a project
/swimlanes — choose a swimlane
/type — choose a category
/status — show destination
/cancel — cancel the draft
A new message replaces the current draft.')."\n\n".'📣 '.I18n::text('Author’s Telegram channel:').' https://t.me/izzypizzy_seo'); return;
            }
            if ($text === '/cancel') { $user['draft'] = null; $this->save(); $this->send($chat, '✖️ '.I18n::text('Draft cancelled.')); return; }
            if (in_array($text, ['/projects', '/project'], true)) {
                $rows = [];
                foreach (Access::projects($this->container, (int)$this->settings['owner_id']) as $item) {
                    $rows[] = [['text' => ($this->projectId($user) === (int)$item['id'] ? '✓ ' : '').mb_substr($item['name'], 0, 60), 'callback_data' => 'project:'.$item['id']]];
                }
                $this->sendChoices($chat, '📁 '.I18n::text('Choose a project for tasks:'), $rows);
                return;
            }
            if (in_array($text, ['/swimlanes', '/lanes', '/swimlane'], true)) {
                $project = $this->activeProject($this->projectId($user));
                $rows = [];
                foreach ($this->container['swimlaneModel']->getAllByStatus($project['id']) as $item) {
                    $rows[] = [['text' => mb_substr($item['name'], 0, 60), 'callback_data' => 'lane:'.$project['id'].':'.$item['id']]];
                }
                $this->sendChoices($chat, '📋 '.I18n::text('Choose a swimlane. Project: ').$project['name'], $rows);
                return;
            }
            if ($text === '/type') {
                $project = $this->activeProject($this->projectId($user));
                $rows = [[['text' => I18n::text('No category'), 'callback_data' => 'type:'.$project['id'].':0']]];
                foreach ($this->container['categoryModel']->getAll($project['id']) as $item) {
                    $rows[] = [['text' => mb_substr($item['name'], 0, 60), 'callback_data' => 'type:'.$project['id'].':'.$item['id']]];
                }
                $this->sendChoices($chat, '🏷 '.I18n::text('Choose a category:'), $rows);
                return;
            }
            $target = $this->destination($user);
            if ($text === '/status') { $this->send($chat, '📍 '.I18n::text('Next task:
').$this->destinationLabel($target).I18n::text('
Normalization: ').$this->settings['provider']); return; }
            // Re-delivery of an already prepared message only retries its preview.
            if (($user['draft']['source_message_id'] ?? null) === ($message['message_id'] ?? null) && $user['draft']) {
                $this->preview($chat, $user['draft']); return;
            }
            if (isset($message['voice'])) { $text = $this->transcribe($message['voice']); }
            elseif (isset($message['audio'])) { $text = $this->transcribe($message['audio']); }
            elseif ($text === '' || substr($text, 0, 1) === '/') { $this->send($chat, I18n::text('Send text or a voice note. Help: /help')); return; }
            if ($text === '') { throw new \RuntimeException(I18n::text('No speech was recognized. Try recording another voice note.')); }
            if (mb_strlen($text) > 16000) { throw new \RuntimeException(I18n::text('The text is too long. Split it into several tasks.')); }
            $task = Normalizer::plain($text);
            $user['draft'] = $task + $target + [
                'original_text' => $text, 'rewritten' => false, 'source' => isset($message['voice']) || isset($message['audio']) ? 'voice' : 'text',
                'source_message_id' => $message['message_id'], 'reference' => 'tg:'.bin2hex(random_bytes(24)), 'nonce' => bin2hex(random_bytes(8)), 'sender' => $sender, 'created_at' => time()];
            $this->save();
            $this->preview($chat, $user['draft']);
        } catch (\Throwable $e) {
            // Expected operational failures are reported, then this update is acknowledged.
            // A failed notification propagates so the update will be retried.
            $messageText = $e instanceof \RuntimeException && !($e instanceof \PDOException) ? $e->getMessage() : I18n::text('Processing failed. Check the settings and send your message again.');
            $this->send($chat, $messageText);
        }
    }
    private function projectId(array $user)
    {
        return (int)($user['selected_project'] ?? $this->settings['project_id']);
    }
    private function activeProject($id)
    {
        $project = $this->container['db']->table('projects')->eq('id', $id)->eq('is_active', 1)->findOne();
        if (!$project || !Access::canCreate($this->container, (int)$this->settings['owner_id'], $id)) { throw new \RuntimeException(I18n::text('The project is unavailable. Choose another with /projects.')); }
        return $project;
    }
    private function destination(array $user)
    {
        $project = $this->projectId($user);
        $isDefault = $project === (int)$this->settings['project_id'];
        $category = ($user['project'] ?? 0) === $project && $user['category'] !== null ? (int)$user['category'] : ($isDefault ? (int)$this->settings['category_id'] : 0);
        $target = ['project_id' => $project, 'category_id' => $category,
            'column_id' => $isDefault ? (int)$this->settings['column_id'] : 0,
            'swimlane_id' => (int)($user['swimlane'] ?? 0)];
        return $this->validateTarget($target);
    }
    private function validateTarget(array $target)
    {
        if ((Settings::connection($this->container, $this->settings['owner_id'])['identity'] ?? '') !== $this->settings['connection_identity']) { throw new \RuntimeException(I18n::text('Your administrator has not granted you access to this plugin.')); }
        $project = (int)$target['project_id'];
        $this->activeProject($project);
        if (empty($target['column_id'])) { $target['column_id'] = Access::firstColumn($this->container, (int)$this->settings['owner_id'], $project); }
        if (empty($target['swimlane_id'])) { $target['swimlane_id'] = $this->container['swimlaneModel']->getFirstActiveSwimlaneId($project); }
        if (!$target['column_id'] || !$target['swimlane_id']) { throw new \RuntimeException(I18n::text('The project has no active swimlane or column. Choose another with /projects.')); }
        foreach (['category_id' => 'project_has_categories', 'column_id' => 'columns', 'swimlane_id' => 'swimlanes'] as $key => $table) {
            if (empty($target[$key]) && $key === 'category_id') { continue; }
            $query = $this->container['db']->table($table)->eq('id', $target[$key])->eq('project_id', $project);
            if ($key === 'swimlane_id') { $query->eq('is_active', 1); }
            if (!$query->exists()) { throw new \RuntimeException(I18n::text('The category, column or swimlane is unavailable. Check /type, /swimlanes or /projects.')); }
        }
        if (!Access::canCreate($this->container, (int)$this->settings['owner_id'], $project, $target['column_id'])) { throw new \RuntimeException(I18n::text('You cannot create tasks in this column. Choose another project or change the column in settings.')); }
        return $target;
    }
    private function destinationLabel(array $target)
    {
        $lines = [];
        foreach (['project_id' => ['projects', I18n::text('Project')], 'swimlane_id' => ['swimlanes', I18n::text('Swimlane')], 'column_id' => ['columns', I18n::text('Column')], 'category_id' => ['project_has_categories', I18n::text('Category')]] as $key => $field) {
            $id = (int)($target[$key] ?? 0);
            $name = $id ? $this->container['db']->table($field[0])->eq('id', $id)->findOneColumn($key === 'column_id' ? 'title' : 'name') : I18n::text('No category');
            $lines[] = $field[1].': '.mb_substr($name ?: '#'.$id, 0, 120);
        }
        return implode("\n", $lines);
    }
    private function sendChoices($chat, $title, array $rows)
    {
        if (!$rows) { $this->send($chat, I18n::text('No options are available. Check the project settings in Kanboard.')); return; }
        foreach (array_chunk($rows, 40) as $buttons) { $this->send($chat, $title, $buttons); }
    }
    private function preview($chat, array $draft, $notice = '')
    {
        $rewritten = !empty($draft['rewritten']);
        $heading = $rewritten ? '✨ '.I18n::text('Rewritten with DeepSeek') : (($draft['source'] ?? '') === 'voice' ? '🎙 '.I18n::text('Whisper transcript') : '📝 '.I18n::text('Your text'));
        $body = $rewritten ? $draft['title']."\n\n".($draft['preview_description'] ?? $draft['description']) : ($draft['original_text'] ?? $draft['description']);
        if (mb_strlen($body) > 2500) { $body = mb_substr($body, 0, 2500).'…'; }
        $this->send($chat, $notice.$heading."\n\n".$body."\n\n".'📍 '.I18n::text('Save to:
').$this->destinationLabel($draft), [[
            ['text' => '✨ '.I18n::text('Rewrite with DeepSeek'), 'callback_data' => 'rewrite:'.$draft['nonce']],
        ], [
            ['text' => '✖️ '.I18n::text('Cancel'), 'callback_data' => 'cancel:'.$draft['nonce']],
            ['text' => '✅ '.I18n::text('Save'), 'callback_data' => 'create:'.$draft['nonce']],
        ]]);
    }
    private function rewrite($chat, array &$user)
    {
        $this->validateTarget($user['draft']);
        if ($this->settings['provider'] === 'none' || !$this->settings['api_key']) {
            $this->preview($chat, $user['draft'], I18n::text('To rewrite, configure DeepSeek or OpenRouter and an API key in your Kanboard profile. You can save the original text now.')."\n\n");
            return;
        }
        if (($user['draft']['rewrite_attempts'] ?? 0) >= 5) {
            $this->preview($chat, $user['draft'], I18n::text('Rewrite limit reached for this draft. Save it or send a new message.')."\n\n"); return;
        }
        $this->charge('rewrite', 30);
        $user['draft']['rewrite_attempts'] = ($user['draft']['rewrite_attempts'] ?? 0) + 1;
        $this->save();
        // Always rewrite the original dictation, not a previous AI response.
        $original = $user['draft']['original_text'] ?? $user['draft']['description'];
        $progress = $this->send($chat, '⏳ '.I18n::text('Rewriting with DeepSeek… Please wait.'));
        try { $this->telegram('sendChatAction', ['chat_id' => $chat, 'action' => 'typing']); } catch (\Throwable $ignored) {}
        try { $task = Normalizer::normalize($original, $this->settings, $this->http); }
        catch (\Throwable $e) {
            $this->finishProgress($chat, $progress, '⚠️ '.I18n::text('Could not rewrite. Your draft is unchanged.'));
            $this->preview($chat, $user['draft'], I18n::text('DeepSeek is unavailable. Your draft is unchanged; save it or try rewriting again.')."\n\n");
            return;
        }
        $user['draft'] = array_replace($user['draft'], $task, [
            'original_text' => $original, 'rewritten' => true, 'nonce' => bin2hex(random_bytes(8)),
        ]);
        $this->save();
        $this->finishProgress($chat, $progress, '✅ '.I18n::text('Rewriting complete.'));
        $this->preview($chat, $user['draft']);
    }
    private function charge($kind, $limit)
    {
        $quota = $this->state['quota'][$kind] ?? ['until' => 0, 'used' => 0];
        if ($quota['until'] <= time()) { $quota = ['until' => time() + 3600, 'used' => 0]; }
        if ($quota['used'] >= $limit) { throw new \RuntimeException(I18n::text('Hourly processing limit reached. Please try again later.')); }
        $quota['used']++;
        $this->state['quota'][$kind] = $quota;
        $this->save();
    }
    private function finishProgress($chat, $message, $text)
    {
        if (!is_array($message) || empty($message['message_id'])) { return; }
        try {
            $this->telegram('editMessageText', ['chat_id' => $chat, 'message_id' => $message['message_id'], 'text' => $text]);
        } catch (\Throwable $ignored) { /* The following preview still reports the result. */ }
    }
    private function selectionChanged($chat, array &$user)
    {
        $target = $this->destination($user);
        if ($user['draft']) {
            $user['draft'] = array_replace($user['draft'], $target, ['nonce' => bin2hex(random_bytes(8))]);
        }
        $this->save();
        if ($user['draft']) { $this->preview($chat, $user['draft'], I18n::text('The draft destination has changed.

')); }
        else { $this->send($chat, '📍 '.I18n::text('Selected for future tasks:
').$this->destinationLabel($target)); }
    }
    private function create($chat, array &$user)
    {
        $draft = $user['draft'];
        $draft = $this->validateTarget($draft);
        // The worker holds a filesystem lock. A deterministic reference handles a crash
        // between task insertion and state persistence, including retried callbacks.
        $id = $this->container['db']->table('tasks')->eq('reference', $draft['reference'])->eq('creator_id', (int)$this->settings['owner_id'])->findOneColumn('id');
        if (!$id) {
            $values = array_intersect_key($draft, array_flip(['title', 'description', 'project_id', 'category_id', 'column_id', 'swimlane_id', 'reference']));
            $values['creator_id'] = (int)$this->settings['owner_id'];
            $values['description'] .= I18n::text('

Source: Telegram, user ').$draft['sender'].'.';
            $previousUser = session_get('user');
            try {
                $this->container['userSession']->initialize($this->container['userModel']->getById((int)$this->settings['owner_id']));
                $id = $this->container['taskCreationModel']->create($values);
            } finally { session_set('user', $previousUser); }
            if (!$id) { throw new \RuntimeException(I18n::text('Could not create the task. Try pressing Save again.')); }
        }
        $url = $this->container['configModel']->getOption('application_url');
        $actual = $this->container['db']->table('tasks')->eq('id', $id)->findOne();
        $this->send($chat, '✅ '.I18n::text('Created task #').$id.': '.$draft['title']."\n\n".$this->destinationLabel($actual ?: $draft).($url ? "\n".rtrim($url, '/').'/index.php?controller=TaskViewController&action=show&task_id='.$id : ''));
        $user['draft'] = null;
        $this->save();
    }
    private function transcribe(array $voice)
    {
        $this->charge('audio', 12);
        if (($voice['file_size'] ?? 0) > 20971520 || ($voice['duration'] ?? 0) > 600) { throw new \RuntimeException(I18n::text('Limit: 20 MB and 10 minutes. Split the recording.')); }
        $file = $this->telegram('getFile', ['file_id' => $voice['file_id']]);
        $path = $file['file_path'] ?? '';
        if (!$path || !preg_match('~^[A-Za-z0-9_./-]+$~D', $path) || strpos($path, '..') !== false || ($file['file_size'] ?? 0) > 20971520) {
            throw new \RuntimeException(I18n::text('Telegram did not provide a suitable audio file.'));
        }
        $audio = $this->http->request('https://api.telegram.org/file/bot'.$this->settings['bot_token'].'/'.$path);
        $temp = tempnam(sys_get_temp_dir(), 'whisper-');
        try {
            if (file_put_contents($temp, $audio) === false) { throw new \RuntimeException(I18n::text('Not enough storage for audio.')); }
            $body = $this->http->request(rtrim(getenv('WHISPER_URL') ?: 'http://whisper:8000', '/').'/transcribe', [
                'file' => new \CURLFile($temp, 'application/octet-stream', 'voice.ogg'), 'language' => $this->settings['language'],
            ], [], 900, 1048576);
            $result = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
            return trim($result['text'] ?? '');
        } finally { if ($temp && is_file($temp)) { unlink($temp); } }
    }
}
