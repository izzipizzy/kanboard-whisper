<?php
// Run only inside a disposable Kanboard container; never uses the user's database.
define('DATA_DIR', sys_get_temp_dir().'/whisper-test-'.bin2hex(random_bytes(6)));
mkdir(DATA_DIR, 0700, true);
define('DB_FILENAME', DATA_DIR.'/db.sqlite');
require '/var/www/app/app/common.php';
$container['dispatcher']->dispatch(new \Symfony\Contracts\EventDispatcher\Event(), 'app.bootstrap');
use Kanboard\Plugin\Whisper\Service\{Bot, Http, Normalizer, Settings};
$container['userModel']->update(['id' => 1, 'language' => 'ru_RU']);
\Kanboard\Plugin\Whisper\Service\I18n::activate($container, 1);

function check($value, $message) { if (!$value) { throw new RuntimeException('FAIL: '.$message); } echo 'PASS: '.$message."\n"; }
class FakeHttp extends Http
{
    public $calls = [];
    public $failNormalization = false;
    public $failSend = 0;
    public $normalized = ['title' => 'Позвонить клиенту', 'description' => 'Обсудить договор.'];
    public function json($url, array $payload, array $headers = [], $timeout = 60)
    {
        $this->calls[] = [$url, $payload, $headers];
        if (strpos($url, 'chat/completions') !== false) {
            if ($this->failNormalization) { throw new RuntimeException('Provider unavailable'); }
            return ['choices' => [['message' => ['content' => json_encode($this->normalized)]]]];
        }
        if (strpos($url, '/sendMessage') !== false && $this->failSend > 0) { $this->failSend--; throw new RuntimeException('Send unavailable'); }
        return ['ok' => true, 'result' => strpos($url, '/sendMessage') !== false ? ['message_id' => count($this->calls)] : true];
    }
}
function msg($id, $text, $sender = 42, $type = 'private') {
    return ['update_id' => $id, 'message' => ['message_id' => $id, 'from' => ['id' => $sender], 'chat' => ['id' => $sender, 'type' => $type], 'text' => $text]];
}
function callback($id, $data, $sender = 42) {
    return ['update_id' => $id, 'callback_query' => ['id' => (string)$id, 'data' => $data, 'from' => ['id' => $sender], 'message' => ['chat' => ['id' => $sender, 'type' => 'private']]]];
}
function countTasks($c) { return $c['db']->table('tasks')->count(); }
$project = $container['projectModel']->create(['name' => 'Test project']);
$other = $container['projectModel']->create(['name' => 'Other project']);
$category = $container['categoryModel']->create(['name' => 'Идея', 'project_id' => $project]);
$alienCategory = $container['categoryModel']->create(['name' => 'Чужая', 'project_id' => $other]);
check($project && $other && $category, 'real Kanboard database initialized');
check(isset($container['pluginLoader']->getPlugins()['Whisper']), 'plugin registered');
check(!$container['applicationAuthorization']->isAllowed('WhisperController', 'permissions', 'app-user'), 'permission management requires administrator');
$settings = Settings::DEFAULTS;
$settings = array_replace($settings, ['owner_id' => 1, 'enabled' => '1', 'bot_token' => '123:TEST', 'allowed_users' => '42, 43', 'project_id' => (string)$project, 'category_id' => (string)$category]);
Settings::validate($settings, $container);
try { Settings::validate(array_replace($settings, ['category_id' => (string)$alienCategory]), $container); throw new LogicException('Validation missing'); }
catch (RuntimeException $e) { check(true, 'cross-project category rejected'); }
$http = new FakeHttp(); $path = DATA_DIR.'/state.json'; $bot = new Bot($container, $http, $settings, $path);
$bot->process(msg(1, 'Чужой', 99)); $bot->process(msg(2, 'Группа', 42, 'group'));
check(!$http->calls && countTasks($container) === 0, 'unauthorized users and groups ignored without replies');
$bot->process(msg(3, 'Позвонить клиенту завтра'));
$state = json_decode(file_get_contents($path), true); $draft = $state['users'][42]['draft'];
check(countTasks($container) === 0 && $draft['category_id'] == $category, 'preview persists with selected category before task creation');
$bot->process(callback(4, 'create:'.$draft['nonce'], 43));
check(countTasks($container) === 0, 'another allowed user cannot confirm someone else’s draft');
$bot->process(callback(5, 'create:'.$draft['nonce']));
$bot->process(callback(6, 'create:'.$draft['nonce']));
check(countTasks($container) === 1, 'confirm creates exactly one task, duplicate callback is harmless');
$task = $container['db']->table('tasks')->findOne();
check($task['project_id'] == $project && $task['category_id'] == $category && strpos($task['description'], 'Позвонить клиенту завтра') !== false, 'real task fields and transcript preserved');
// Simulate crash after task creation but before clearing the stored draft.
file_put_contents($path, json_encode($state));
$bot = new Bot($container, $http, $settings, $path); $bot->process(callback(7, 'create:'.$draft['nonce']));
check(countTasks($container) === 1, 'restart after insertion does not duplicate task');
$bot->process(msg(8, 'Новый черновик')); $s = json_decode(file_get_contents($path), true); $old = $s['users'][42]['draft']['nonce'];
$bot->process(msg(9, 'Другой черновик')); $bot->process(callback(10, 'create:'.$old));
check(countTasks($container) === 1, 'stale preview cannot create replacement draft');
$bot->process(msg(11, '/cancel'));
check(json_decode(file_get_contents($path), true)['users'][42]['draft'] === null, 'cancel removes persisted draft');
$bot->process(callback(12, 'type:'.$project.':'.$alienCategory));
$bot->process(msg(13, 'Категория прежняя'));
check(json_decode(file_get_contents($path), true)['users'][42]['draft']['category_id'] == $category, 'forged category callback cannot cross project boundary');
$http->failNormalization = true;
$normalizedSettings = array_replace($settings, ['provider' => 'deepseek', 'api_key' => 'fake-secret']);
$bot = new Bot($container, $http, $normalizedSettings, $path); $bot->process(msg(14, 'Не потерять исходный текст'));
$rawDraft = json_decode(file_get_contents($path), true)['users'][42]['draft'];
check($rawDraft['title'] === 'Не потерять исходный текст', 'initial preview is the original transcript');
$bot->process(callback(140, 'rewrite:'.$rawDraft['nonce']));
check(json_decode(file_get_contents($path), true)['users'][42]['draft'] === array_replace($rawDraft, ['rewrite_attempts' => 1]), 'provider failure preserves draft and original text');
check(count(array_filter($http->calls, function ($call) { return strpos($call[0], '/editMessageText') !== false && ($call[1]['text'] ?? '') === '⚠️ Не удалось переписать. Черновик не изменён.'; })) === 1, 'failed rewrite updates progress message');
$http->failNormalization = false;
foreach (['deepseek', 'openrouter'] as $provider) {
    $result = Normalizer::normalize('Исходная диктовка', array_replace($normalizedSettings, ['provider' => $provider]), $http);
    $call = end($http->calls);
    check(strpos($call[0], $provider === 'deepseek' ? 'api.deepseek.com' : 'openrouter.ai') !== false && strpos($result['description'], 'Исходная диктовка') !== false, $provider.' endpoint and source preservation');
}
$auto = new Bot($container, $http, array_replace($settings, ['confirm' => '0']), DATA_DIR.'/auto.json');
$auto->process(msg(15, 'Задача после сохранения'));
check(countTasks($container) === 1, 'legacy automatic mode still requires explicit Save');
$autoDraft = json_decode(file_get_contents(DATA_DIR.'/auto.json'), true)['users'][42]['draft'];
$auto->process(callback(150, 'create:'.$autoDraft['nonce']));
$auto->process(callback(151, 'create:'.$autoDraft['nonce']));
check(countTasks($container) === 2, 'Save remains idempotent');
// Exercise Telegram file retrieval, transcription request, temporary-file cleanup and silence.
class VoiceHttp extends FakeHttp
{
    public $tempPath;
    public $speechText = 'Голосовая задача';
    public function json($url, array $payload, array $headers = [], $timeout = 60)
    {
        if (strpos($url, '/getFile') !== false) { return ['ok' => true, 'result' => ['file_path' => 'voice/file.oga', 'file_size' => 10]]; }
        return parent::json($url, $payload, $headers, $timeout);
    }
    public function request($url, $payload = null, array $headers = [], $timeout = 60, $maxBytes = 20971520)
    {
        if (strpos($url, 'api.telegram.org/file/') !== false) { return 'fake audio'; }
        check($payload['file'] instanceof CURLFile && $payload['language'] === 'ru', 'voice uses multipart upload and configured language');
        $this->tempPath = $payload['file']->getFilename();
        check(file_get_contents($this->tempPath) === 'fake audio', 'downloaded voice passed to speech service');
        return json_encode(['text' => $this->speechText]);
    }
}
$voiceHttp = new VoiceHttp();
$voiceBot = new Bot($container, $voiceHttp, $settings, DATA_DIR.'/voice.json');
$voiceUpdate = msg(17, ''); $voiceUpdate['message']['voice'] = ['file_id' => 'test-file', 'duration' => 3, 'file_size' => 10];
$voiceBot->process($voiceUpdate);
check(json_decode(file_get_contents(DATA_DIR.'/voice.json'), true)['users'][42]['draft']['title'] === 'Голосовая задача' && !is_file($voiceHttp->tempPath), 'voice creates preview and removes temporary audio');
$voiceHttp->speechText = '';
$voiceBot->process($voiceUpdate);
check(countTasks($container) === 2, 'empty transcription creates no task');
// Expired previews are rejected even if the user has been active recently.
$expiryPath = DATA_DIR.'/expiry.json';
$expired = $state; $expired['users'][42]['draft']['created_at'] = time() - 86401;
file_put_contents($expiryPath, json_encode($expired));
$expiryBot = new Bot($container, $http, $settings, $expiryPath);
$expiryBot->process(callback(18, 'create:'.$draft['nonce']));
check(countTasks($container) === 2, '24-hour draft expiry enforced at confirmation');
// Project/lane selection persists per Telegram sender and updates an existing preview.
$selectPath = DATA_DIR.'/selection.json';
$selectHttp = new FakeHttp();
$select = new Bot($container, $selectHttp, $settings, $selectPath);
$lane = $container['swimlaneModel']->create($other, 'В дороге');
$select->process(msg(30, '/projects'));
$last = end($selectHttp->calls)[1];
check(isset($last['reply_markup']['inline_keyboard']), 'project list has inline buttons');
$select->process(msg(31, 'Задача с выбором места'));
$prior = json_decode(file_get_contents($selectPath), true)['users'][42]['draft'];
$select->process(callback(32, 'project:'.$other));
$select->process(callback(33, 'lane:'.$other.':'.$lane));
$current = json_decode(file_get_contents($selectPath), true)['users'][42]['draft'];
check($current['project_id'] == $other && $current['swimlane_id'] == $lane && $current['category_id'] === 0, 'project switch resets category and reroutes preview');
$before = countTasks($container);
$select->process(callback(34, 'create:'.$prior['nonce']));
check(countTasks($container) === $before, 'stale confirmation after changing destination rejected');
$select = new Bot($container, $selectHttp, $settings, $selectPath);
$select->process(callback(35, 'create:'.$current['nonce']));
$created = $container['db']->table('tasks')->eq('reference', $current['reference'])->findOne();
check($created['project_id'] == $other && $created['swimlane_id'] == $lane && $created['creator_id'] == 1, 'chosen project, lane and Kanboard creator reach actual task');
$last = end($selectHttp->calls)[1]['text'];
check(strpos($last, 'Other project') !== false && strpos($last, 'В дороге') !== false && strpos($last, 'Колонка:') !== false, 'creation response shows actual destination');
$select->process(msg(36, '/status', 43));
check(strpos(end($selectHttp->calls)[1]['text'], 'Test project') !== false, 'destination does not leak between Telegram senders');
$select->process(callback(37, 'lane:'.$other.':'.$container['swimlaneModel']->getFirstActiveSwimlaneId($project)));
check(json_decode(file_get_contents($selectPath), true)['users'][42]['swimlane'] == $lane, 'foreign lane rejected');
$container['swimlaneModel']->disable($other, $lane);
$select->process(msg(38, '/swimlanes'));
check(strpos(json_encode(end($selectHttp->calls)[1], JSON_UNESCAPED_UNICODE), 'В дороге') === false, 'inactive lanes excluded');
$select->syncCommands(); $calls = count($selectHttp->calls); $select->syncCommands();
check(count($selectHttp->calls) === $calls, 'Telegram command menu registered once per version');
// Personal Kanboard accounts: separate configuration, grants, project permissions.
$member = $container['userModel']->create(['username' => 'member', 'password' => 'testpass', 'role' => 'app-user', 'language' => 'ru_RU']);
$outsider = $container['userModel']->create(['username' => 'outsider', 'password' => 'testpass', 'role' => 'app-user', 'language' => 'ru_RU']);
$container['projectUserRoleModel']->addUser($project, $member, 'project-member');
$container['projectUserRoleModel']->addUser($other, $member, 'project-viewer');
\Kanboard\Plugin\Whisper\Service\Access::grant($container, [$member]);
$memberSettings = array_replace($settings, ['owner_id' => $member, 'bot_token' => '456:MEMBER', 'api_key' => 'member-key']);
Settings::save($container, $member, $memberSettings);
Settings::save($container, 1, $settings);
check(Settings::read($container, $member)['api_key'] === 'member-key' && Settings::read($container, 1)['api_key'] === '', 'credentials stored separately per account');
$personalHttp = new FakeHttp(); $personalPath = DATA_DIR.'/personal.json';
$personal = new Bot($container, $personalHttp, $memberSettings, $personalPath);
$personal->process(msg(40, '/projects'));
$menu = json_encode(end($personalHttp->calls)[1]);
check(strpos($menu, 'Test project') !== false && strpos($menu, 'Other project') === false, 'viewer and inaccessible projects excluded');
$personal->process(callback(41, 'project:'.$other));
$personal->process(msg(42, 'Персональная задача'));
$pending = json_decode(file_get_contents($personalPath), true)['users'][42]['draft'];
check($pending['project_id'] == $project, 'forged project callback cannot bypass Kanboard permissions');
$before = countTasks($container);
\Kanboard\Plugin\Whisper\Service\Access::grant($container, []);
$personal->process(callback(43, 'create:'.$pending['nonce']));
check(countTasks($container) === $before, 'revoked plugin access blocks pending confirmation');
\Kanboard\Plugin\Whisper\Service\Access::grant($container, [$member]);
$container['projectUserRoleModel']->removeUser($project, $member);
$personal->process(callback(44, 'create:'.$pending['nonce']));
check(countTasks($container) === $before, 'revoked project membership blocks pending confirmation');
$container['projectUserRoleModel']->addUser($project, $member, 'project-member');
$personal->process(callback(45, 'create:'.$pending['nonce']));
check($container['db']->table('tasks')->eq('reference', $pending['reference'])->findOneColumn('creator_id') == $member, 'task attributed to connection owner');
try { Settings::validate(array_replace($memberSettings, ['bot_token' => $settings['bot_token']]), $container); throw new LogicException('Duplicate bot accepted'); }
catch (RuntimeException $e) { check(true, 'same bot cannot be attached to two accounts'); }
$customProject = $container['projectModel']->create(['name' => 'Restricted project']);
$roleId = $container['projectRoleModel']->create($customProject, 'Limited');
$container['projectUserRoleModel']->addUser($customProject, $member, 'Limited');
$container['projectRoleRestrictionModel']->create($customProject, $roleId, 'task_creation');
check(!\Kanboard\Plugin\Whisper\Service\Access::canCreate($container, $member, $customProject), 'custom project creation restriction enforced');
$cols = $container['columnModel']->getAll($customProject);
$container['columnRestrictionModel']->create($customProject, $roleId, $cols[1]['id'], 'allow.task_creation');
check(\Kanboard\Plugin\Whisper\Service\Access::firstColumn($container, $member, $customProject) == $cols[1]['id'], 'first permitted column honors custom column override');
check(!\Kanboard\Plugin\Whisper\Service\Access::canCreate($container, $member, $customProject, $cols[0]['id']), 'blocked custom column remains inaccessible');
$groupProject = $container['projectModel']->create(['name' => 'Group project']);
$group = $container['groupModel']->create('Test group');
$container['groupMemberModel']->addUser($group, $member);
$container['projectGroupRoleModel']->addGroup($groupProject, $group, 'project-member');
check(\Kanboard\Plugin\Whisper\Service\Access::canCreate($container, $member, $groupProject), 'group membership grants project access');
$container['userModel']->disable($member);
check(!\Kanboard\Plugin\Whisper\Service\Access::canCreate($container, $member, $groupProject), 'disabled account cannot create tasks');
$before = countTasks($container);
$closed = $container['projectModel']->disable($project);
$auto->process(msg(16, 'Закрытый проект'));
check(countTasks($container) === $before, 'archived project cannot receive tasks');
// Template smoke test: render the full configuration page without a web session.
$html = $container['template']->render('Whisper:config/index', ['values' => $settings, 'projects' => [], 'categories' => [], 'columns' => [], 'worker' => 0]);
check(strpos($html, 'Telegram Whisper') !== false && strpos($html, '123:TEST') === false, 'settings template renders without exposing saved token');
// Locale switching must not leak between personal bot connections.
$english = $container['userModel']->create(['username' => 'english', 'role' => 'app-admin', 'language' => 'en_US']);
$enSettings = array_replace($settings, ['owner_id' => $english, 'project_id' => (string)$other, 'category_id' => '0', 'bot_token' => '789:ENGLISH']);
$englishHttp = new FakeHttp();
$englishBot = new Bot($container, $englishHttp, $enSettings, DATA_DIR.'/english.json');
$englishBot->process(msg(80, '/help'));
check(strpos(end($englishHttp->calls)[1]['text'], 'Send a voice note') !== false, 'English profile receives English bot replies');
$bot->process(msg(81, '/help'));
check(strpos(end($http->calls)[1]['text'], 'Отправьте голосовое') !== false, 'Russian profile restored after English connection');
$englishBot->syncCommands();
$commandCalls = array_filter($englishHttp->calls, function ($call) { return strpos($call[0], 'setMyCommands') !== false; });
check(reset($commandCalls)[1]['commands'][0]['description'] === 'Choose a project for tasks', 'Telegram command descriptions follow profile language');
$englishBot->process(msg(82, 'Keep this text in English'));
$enDraft = json_decode(file_get_contents(DATA_DIR.'/english.json'), true)['users'][42]['draft'];
$last = end($englishHttp->calls)[1];
check(strpos($last['text'], 'Save to:') !== false && array_map(function ($row) { return array_column($row, 'text'); }, $last['reply_markup']['inline_keyboard']) === [['✨ Rewrite with DeepSeek'], ['✖️ Cancel', '✅ Save']], 'English preview and action buttons translated');
$englishBot->process(callback(83, 'create:'.$enDraft['nonce']));
check(strpos(end($englishHttp->calls)[1]['text'], 'Created task #') !== false, 'English task confirmation translated');
// The LLM is opt-in per draft, including when a provider and key are configured.
$flowHttp = new FakeHttp();
$flowSettings = array_replace($enSettings, ['provider' => 'openrouter', 'api_key' => 'flow-test-key']);
$flowPath = DATA_DIR.'/flow.json'; $flow = new Bot($container, $flowHttp, $flowSettings, $flowPath);
$countLLM = function () use ($flowHttp) { return count(array_filter($flowHttp->calls, function ($call) { return strpos($call[0], '/chat/completions') !== false; })); };
$flow->process(msg(90, 'Call the client tomorrow'));
$original = json_decode(file_get_contents($flowPath), true)['users'][42]['draft'];
check($countLLM() === 0 && !$original['rewritten'] && $original['original_text'] === 'Call the client tomorrow', 'no LLM request before Rewrite button');
$before = countTasks($container);
$flow->process(callback(91, 'rewrite:'.$original['nonce'], 43));
check($countLLM() === 0, 'another sender cannot trigger a paid rewrite');
$flow->process(callback(92, 'rewrite:'.$original['nonce']));
$rewritten = json_decode(file_get_contents($flowPath), true)['users'][42]['draft'];
$progressIndex = null; $requestIndex = null; $finished = false;
foreach ($flowHttp->calls as $index => $call) {
    if (($call[1]['text'] ?? '') === '⏳ Rewriting with DeepSeek… Please wait.') { $progressIndex = $index; }
    if (strpos($call[0], '/chat/completions') !== false) { $requestIndex = $index; }
    if (strpos($call[0], '/editMessageText') !== false && ($call[1]['text'] ?? '') === '✅ Rewriting complete.') { $finished = true; }
}
check($progressIndex !== null && $progressIndex < $requestIndex && $finished, 'progress appears before LLM request and is updated on completion');
check($countLLM() === 1 && $rewritten['rewritten'] && $rewritten['original_text'] === $original['original_text'] && countTasks($container) === $before, 'rewrite updates preview without creating a task');
$flow->process(callback(93, 'rewrite:'.$original['nonce']));
$flow->process(callback(94, 'create:'.$original['nonce']));
check($countLLM() === 1 && countTasks($container) === $before, 'stale buttons cannot repeat rewrite or save an obsolete preview');
$flow = new Bot($container, $flowHttp, $flowSettings, $flowPath);
$flow->process(callback(95, 'rewrite:'.$rewritten['nonce']));
$requests = array_values(array_filter($flowHttp->calls, function ($call) { return strpos($call[0], '/chat/completions') !== false; }));
check(end($requests)[1]['messages'][1]['content'] === 'Call the client tomorrow', 'repeat rewrite after restart uses original dictation');
$rewritten = json_decode(file_get_contents($flowPath), true)['users'][42]['draft'];
$flow->process(callback(96, 'create:'.$rewritten['nonce']));
$saved = $container['db']->table('tasks')->eq('reference', $rewritten['reference'])->findOne();
check($saved && strpos($saved['description'], 'Call the client tomorrow') !== false, 'Save persists rewritten task with original transcript');
$flow->process(msg(97, 'Save this without AI'));
$rawSave = json_decode(file_get_contents($flowPath), true)['users'][42]['draft']; $llmBefore = $countLLM();
$flow->process(callback(98, 'create:'.$rawSave['nonce']));
check($countLLM() === $llmBefore && $container['db']->table('tasks')->eq('reference', $rawSave['reference'])->findOneColumn('title') === 'Save this without AI', 'raw Save never calls the LLM');
// Model catalogs are fetched dynamically, isolated by provider and refreshable.
class CatalogHttp extends Http
{
    public $calls = []; public $revision = 1; public $fail = false;
    public function request($url, $payload = null, array $headers = [], $timeout = 60, $maxBytes = 20971520)
    {
        $this->calls[] = [$url, $headers];
        if ($this->fail) { throw new RuntimeException('offline'); }
        if (strpos($url, 'openrouter') !== false) {
            return json_encode(['data' => [['id' => 'deepseek/model-'.$this->revision, 'name' => 'DeepSeek model'], ['id' => 'other/model', 'name' => 'Other']]]);
        }
        return json_encode(['data' => [['id' => 'deepseek-direct-'.$this->revision]]]);
    }
}
$catalogHttp = new CatalogHttp();
$catalogSettings = array_replace($settings, ['provider' => 'openrouter', 'api_key' => 'catalog-secret']);
$first = \Kanboard\Plugin\Whisper\Service\Models::cached($catalogSettings, 1, $catalogHttp);
check(isset($first['models']['deepseek/model-1']) && !isset($first['models']['other/model']) && !$catalogHttp->calls[0][1], 'OpenRouter catalog filters DeepSeek models without sending credentials');
$catalogHttp->revision = 2;
$second = \Kanboard\Plugin\Whisper\Service\Models::cached($catalogSettings, 1, $catalogHttp);
check($second['models'] === $first['models'] && count($catalogHttp->calls) === 1, 'catalog cached until refresh');
\Kanboard\Plugin\Whisper\Service\Models::clearCache(1);
$refreshed = \Kanboard\Plugin\Whisper\Service\Models::cached($catalogSettings, 1, $catalogHttp);
check(isset($refreshed['models']['deepseek/model-2']) && !isset($refreshed['models']['deepseek/model-1']), 'refresh retrieves changed provider model list');
$catalogSettings['provider'] = 'deepseek';
$direct = \Kanboard\Plugin\Whisper\Service\Models::cached($catalogSettings, 1, $catalogHttp);
check(isset($direct['models']['deepseek-direct-2']) && end($catalogHttp->calls)[1] === ['Authorization: Bearer catalog-secret'], 'provider change loads authenticated direct DeepSeek catalog');
$catalogHttp->fail = true; \Kanboard\Plugin\Whisper\Service\Models::clearCache(1);
$failed = \Kanboard\Plugin\Whisper\Service\Models::cached($catalogSettings, 1, $catalogHttp);
check($failed['error'] !== '' && strpos($failed['error'], 'catalog-secret') === false, 'catalog failure returns safe manual-entry guidance');
echo "All integration tests passed.\n";

$container['projectModel']->enable($project);
// Deleting an account must cascade its credentials/grant, even when SQLite reuses its ID.
$deleted = $container['userModel']->create(['username' => 'deleted-owner', 'password' => 'pass', 'role' => 'app-user']);
\Kanboard\Plugin\Whisper\Service\Access::grant($container, [$deleted]);
Settings::save($container, $deleted, array_replace($settings, ['bot_token' => '777:SECRET', 'api_key' => 'private-key']));
$identity = Settings::connection($container, $deleted)['identity'];
check($container['userModel']->remove($deleted), 'old account deleted');
$replacement = $container['userModel']->create(['username' => 'replacement-owner', 'password' => 'pass', 'role' => 'app-user']);
check($replacement == $deleted && !Settings::connection($container, $replacement) && !\Kanboard\Plugin\Whisper\Service\Access::allowed($container, $replacement) && Settings::read($container, $replacement)['bot_token'] === '', 'reused SQLite ID inherits neither credentials nor grant');
check(Settings::ensure($container, $replacement)['identity'] !== $identity, 'replacement has independent state identity');

// The custom prompt is personal, passed only as system instructions, with an enforced response contract.
$promptSettings = array_replace($normalizedSettings, ['normalization_prompt' => 'Use concise bullet points.']);
$promptHttp = new FakeHttp(); Normalizer::normalize('Original source', $promptSettings, $promptHttp);
$messages = $promptHttp->calls[0][1]['messages'];
check(strpos($messages[0]['content'], 'Use concise bullet points.') === 0 && strpos($messages[0]['content'], 'Return JSON') !== false && $messages[1]['content'] === 'Original source', 'custom prompt preserves separate transcript and JSON contract');
Settings::save($container, 1, array_replace($settings, ['normalization_prompt' => '<script>alert(1)</script>']));
check(Settings::read($container, 1)['normalization_prompt'] === '<script>alert(1)</script>' && Settings::read($container, $replacement)['normalization_prompt'] === Normalizer::DEFAULT_PROMPT, 'prompt stored per user');

class RetryHttp extends FakeHttp {
    public $updates = []; public $permanent = false;
    public function json($url, array $payload, array $headers = [], $timeout = 60) {
        if (strpos($url, '/getUpdates') !== false) { return ['ok' => true, 'result' => array_values(array_filter($this->updates, function ($u) use ($payload) { return $u['update_id'] >= $payload['offset']; }))]; }
        if (strpos($url, '/sendMessage') !== false) { throw new RuntimeException('Delivery failed', $this->permanent ? 403 : 0); }
        return parent::json($url, $payload, $headers, $timeout);
    }
}
Settings::save($container, 1, $settings);
$retryHttp = new RetryHttp(); $retryHttp->updates = [msg(501, 'Retry without looping forever')];
$retryPath = DATA_DIR.'/retry.json';
for ($i = 0; $i < 3; $i++) {
    $retryBot = new Bot($container, $retryHttp, $settings, $retryPath); $retryBot->poll(0);
    $retryState = json_decode(file_get_contents($retryPath), true);
    if (isset($retryState['retry'])) { $retryState['retry']['after'] = 0; file_put_contents($retryPath, json_encode($retryState)); }
}
check($retryState['offset'] === 502 && !isset($retryState['retry']), 'undeliverable update acknowledged after three attempts');
$retryHttp->permanent = true; $retryHttp->updates = [msg(502, 'Blocked bot')];
$retryBot = new Bot($container, $retryHttp, $settings, $retryPath); $retryBot->poll(0);
check(json_decode(file_get_contents($retryPath), true)['offset'] === 503, 'permanent Telegram refusal acknowledged immediately');

// A task planted by another author cannot suppress creation or expose its destination.
$forgedHttp = new FakeHttp(); $forgedPath = DATA_DIR.'/forged.json';
$forgedBot = new Bot($container, $forgedHttp, $settings, $forgedPath); $forgedBot->process(msg(510, 'Private collision test'));
$forgedDraft = json_decode(file_get_contents($forgedPath), true)['users'][42]['draft'];
$planted = $container['taskCreationModel']->create(['title' => 'Planted', 'project_id' => $other, 'creator_id' => $replacement, 'reference' => $forgedDraft['reference']]);
$before = countTasks($container); $forgedBot->process(callback(511, 'create:'.$forgedDraft['nonce']));
check(countTasks($container) === $before + 1 && strpos(end($forgedHttp->calls)[1]['text'], 'Other project') === false, 'foreign reference collision neither suppresses nor leaks');
check($container['db']->table('project_activities')->count() > 0 && !$container['userSession']->isLogged(), 'CLI creation records project activity and restores session');
echo "Security regression tests passed.\n";

// Legacy schema migration preserves current accounts, drops orphan credentials and binds grants by FK.
$migration = new PDO('sqlite::memory:'); $migration->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$migration->exec('PRAGMA foreign_keys=ON; CREATE TABLE users (id INTEGER PRIMARY KEY); INSERT INTO users VALUES (1); CREATE TABLE settings (option TEXT PRIMARY KEY, value TEXT)');
$seed = $migration->prepare('INSERT INTO settings VALUES (?, ?)');
foreach (['whisper_user_1_bot_token' => '123:FAKE_MIGRATION', 'whisper_user_99_api_key' => 'orphan-test-key', 'whisper_granted_users' => '[1,99]'] as $key => $value) { $seed->execute([$key, $value]); }
\Kanboard\Plugin\Whisper\Schema\version_1($migration);
$migrated = $migration->query('SELECT * FROM whisper_connections')->fetch(PDO::FETCH_ASSOC);
check(json_decode($migrated['settings'], true)['bot_token'] === '123:FAKE_MIGRATION' && $migrated['granted'] == 1 && $migration->query('SELECT COUNT(*) FROM settings')->fetchColumn() == 0, 'schema migration preserves current connection and removes orphan secrets');
$migration->exec('DELETE FROM users WHERE id=1');
check($migration->query('SELECT COUNT(*) FROM whisper_connections')->fetchColumn() == 0, 'migrated credentials cascade on deletion');

$quotaHttp = new FakeHttp(); $quotaPath = DATA_DIR.'/quota.json';
$quotaBot = new Bot($container, $quotaHttp, $flowSettings, $quotaPath);
$quotaBot->process(msg(600, 'Quota draft'));
for ($i = 0; $i < 6; $i++) {
    $nonce = json_decode(file_get_contents($quotaPath), true)['users'][42]['draft']['nonce'];
    $quotaBot->process(callback(601 + $i, 'rewrite:'.$nonce));
}
$quotaCalls = array_filter($quotaHttp->calls, function ($call) { return strpos($call[0], '/chat/completions') !== false; });
check(count($quotaCalls) === 5, 'sixth rewrite is refused before spending provider credits');
$quotaState = json_decode(file_get_contents($quotaPath), true);
$quotaState['quota']['rewrite'] = ['until' => time() + 3600, 'used' => 30];
file_put_contents($quotaPath, json_encode($quotaState));
$quotaBot = new Bot($container, $quotaHttp, $flowSettings, $quotaPath);
$quotaBot->process(msg(620, 'Another draft cannot bypass hourly limit'));
$nonce = json_decode(file_get_contents($quotaPath), true)['users'][42]['draft']['nonce'];
$quotaBot->process(callback(621, 'rewrite:'.$nonce));
check(count(array_filter($quotaHttp->calls, function ($call) { return strpos($call[0], '/chat/completions') !== false; })) === 5, 'new draft cannot bypass connection hourly limit');
