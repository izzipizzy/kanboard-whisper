<?php $e = function ($value) { return $this->text->e((string)$value); }; ?>
<div class="page-header"><h2>Telegram Whisper</h2></div>
<?php if (!empty($form_error)): ?>
<div class="alert alert-error" role="alert"><strong><?= $e(\Kanboard\Plugin\Whisper\Service\I18n::text('Settings have not been saved:')) ?></strong> <?= $e($form_error) ?><br>
<?= $e(\Kanboard\Plugin\Whisper\Service\I18n::text('Your input is kept in a draft for 30 minutes. Correct the error and save again. You do not need to re-enter the token or API key.')) ?></div>
<?php endif ?>
<p><?= $e(\Kanboard\Plugin\Whisper\Service\I18n::text('Your personal connection: Telegram voice or text → transcript → a task created by your Kanboard account.')) ?></p>
<?php if (isset($managed_users)): ?>
<details><summary><strong><?= $e(\Kanboard\Plugin\Whisper\Service\I18n::text('Administrator: user access to the plugin')) ?></strong></summary>
<form method="post" action="<?= $this->url->href('WhisperController', 'permissions', ['plugin' => 'Whisper']) ?>">
<?= $this->form->csrf() ?><input type="hidden" name="save_permissions" value="1">
<?php foreach ($managed_users as $member): ?>
<label><input type="checkbox" name="users[]" value="<?= $e($member['id']) ?>" <?= in_array((int)$member['id'], $granted_users, true) ? 'checked' : '' ?>> <?= $e($member['name'] ?: $member['username']) ?></label>
<?php endforeach ?>
<p><?= $e(\Kanboard\Plugin\Whisper\Service\I18n::text('Administrators have access automatically. Other users configure their bot, Telegram ID and API key under My profile → Telegram Whisper. Kanboard controls project permissions.')) ?></p>
<button class="btn btn-blue" type="submit"><?= $e(\Kanboard\Plugin\Whisper\Service\I18n::text('Save access')) ?></button></form></details>
<?php endif ?>
<p><strong><?= $e(\Kanboard\Plugin\Whisper\Service\I18n::text('Background worker:')) ?></strong> <?= $worker && time() - $worker < 1200 ? \Kanboard\Plugin\Whisper\Service\I18n::text('running') : \Kanboard\Plugin\Whisper\Service\I18n::text('not running or has not responded recently — see instructions below') ?>.</p>
<form method="post" action="<?= $this->url->href('WhisperController', 'save', ['plugin' => 'Whisper']) ?>" autocomplete="off">
<?= $this->form->csrf() ?>
<fieldset><legend>Telegram</legend>
<label><input type="checkbox" name="enabled" value="1" <?= $values['enabled'] === '1' ? 'checked' : '' ?>> <?= $e(\Kanboard\Plugin\Whisper\Service\I18n::text('Enable bot')) ?></label>
<label for="bot_token"><?= $e(\Kanboard\Plugin\Whisper\Service\I18n::text('Bot token from @BotFather')) ?> <?= $values['bot_token'] ? (!empty($has_draft) ? \Kanboard\Plugin\Whisper\Service\I18n::text('(in draft)') : \Kanboard\Plugin\Whisper\Service\I18n::text('(saved)')) : '' ?></label>
<input type="password" id="bot_token" name="bot_token" autocomplete="new-password" placeholder="<?= $e(\Kanboard\Plugin\Whisper\Service\I18n::text('Leave blank to keep the entered or saved value')) ?>" class="input-large">
<label><input type="checkbox" name="clear_bot_token" value="1"> <?= $e(\Kanboard\Plugin\Whisper\Service\I18n::text('Delete saved token')) ?></label>
<label for="allowed_users"><?= $e(\Kanboard\Plugin\Whisper\Service\I18n::text('Your Telegram ID / Chat ID (the same in a private chat)')) ?></label>
<input id="allowed_users" name="allowed_users" value="<?= $e($values['allowed_users']) ?>" class="input-large" placeholder="123456789">
<p class="form-help"><?= $e(\Kanboard\Plugin\Whisper\Service\I18n::text('Only private messages from these IDs are accepted. Separate multiple IDs with commas: each can act as your Kanboard account. An empty list denies access to everyone.')) ?></p>
</fieldset>
<fieldset><legend><?= $e(\Kanboard\Plugin\Whisper\Service\I18n::text('Task destination')) ?></legend>
<label for="project_id"><?= $e(\Kanboard\Plugin\Whisper\Service\I18n::text('Default project')) ?></label><select id="project_id" name="project_id"><option value="0"><?= $e(\Kanboard\Plugin\Whisper\Service\I18n::text('Choose a project')) ?></option>
<?php foreach ($projects as $project): ?><option value="<?= $e($project['id']) ?>" <?= $values['project_id'] == $project['id'] ? 'selected' : '' ?>><?= $e($project['name']) ?></option><?php endforeach ?>
</select>
<?php foreach (['category_id' => [\Kanboard\Plugin\Whisper\Service\I18n::text('Category / task type'), $categories], 'column_id' => [\Kanboard\Plugin\Whisper\Service\I18n::text('Column'), $columns]] as $key => $field): ?>
<label for="<?= $key ?>"><?= $field[0] ?></label><select id="<?= $key ?>" name="<?= $key ?>"><option value="0"><?= $key === 'category_id' ? \Kanboard\Plugin\Whisper\Service\I18n::text('No category') : \Kanboard\Plugin\Whisper\Service\I18n::text('First permitted column in the project') ?></option>
<?php foreach ($field[1] as $item): $name = ''; foreach ($projects as $p) { if ($p['id'] == $item['project_id']) { $name = $p['name']; } } if (!$name) { continue; } ?>
<option value="<?= $e($item['id']) ?>" <?= $values[$key] == $item['id'] ? 'selected' : '' ?>><?= $e($name.' / '.($item['name'] ?? $item['title'])) ?></option>
<?php endforeach ?></select><?php endforeach ?>
<p class="form-help"><?= $e(\Kanboard\Plugin\Whisper\Service\I18n::text('The category and column must belong to the selected project. Select them again when changing projects. Manage categories in project settings. In Telegram, /projects selects a project, /swimlanes a swimlane, and /type a category. Changing the destination updates the current draft.')) ?></p>
</fieldset>
<fieldset><legend><?= $e(\Kanboard\Plugin\Whisper\Service\I18n::text('Speech recognition and normalization')) ?></legend>
<p class="form-help"><?= $e(\Kanboard\Plugin\Whisper\Service\I18n::text('The bot first shows the original transcript. DeepSeek is called only when you press Rewrite with DeepSeek. A task is created only after Save.')) ?></p>
<label for="language"><?= $e(\Kanboard\Plugin\Whisper\Service\I18n::text('Speech language')) ?></label><select id="language" name="language"><?php foreach (['ru' => \Kanboard\Plugin\Whisper\Service\I18n::text('Russian'), 'en' => 'English', 'auto' => \Kanboard\Plugin\Whisper\Service\I18n::text('Detect automatically')] as $id => $label): ?><option value="<?= $id ?>" <?= $values['language'] === $id ? 'selected' : '' ?>><?= $label ?></option><?php endforeach ?></select>
<label for="provider"><?= $e(\Kanboard\Plugin\Whisper\Service\I18n::text('Text processing')) ?></label><select id="provider" name="provider"><?php foreach (['none' => \Kanboard\Plugin\Whisper\Service\I18n::text('Disabled — Whisper only'), 'deepseek' => \Kanboard\Plugin\Whisper\Service\I18n::text('Direct DeepSeek'), 'openrouter' => \Kanboard\Plugin\Whisper\Service\I18n::text('DeepSeek via OpenRouter')] as $id => $label): ?><option value="<?= $id ?>" <?= $values['provider'] === $id ? 'selected' : '' ?>><?= $label ?></option><?php endforeach ?></select>
<label for="api_key"><?= $e(\Kanboard\Plugin\Whisper\Service\I18n::text('API key')) ?> <?= $values['api_key'] ? (!empty($has_draft) ? \Kanboard\Plugin\Whisper\Service\I18n::text('(in draft)') : \Kanboard\Plugin\Whisper\Service\I18n::text('(saved)')) : '' ?></label><input type="password" id="api_key" name="api_key" autocomplete="new-password" placeholder="<?= $e(\Kanboard\Plugin\Whisper\Service\I18n::text('Leave blank to keep the entered or saved value')) ?>" class="input-large">
<label><input type="checkbox" name="clear_api_key" value="1"> <?= $e(\Kanboard\Plugin\Whisper\Service\I18n::text('Delete saved API key')) ?></label>
<label for="normalization_prompt"><?= $e(\Kanboard\Plugin\Whisper\Service\I18n::text('DeepSeek / OpenRouter prompt')) ?></label>
<textarea id="normalization_prompt" name="normalization_prompt" rows="6" maxlength="8000" class="input-large"><?= $e($values['normalization_prompt'] ?? \Kanboard\Plugin\Whisper\Service\Normalizer::DEFAULT_PROMPT) ?></textarea>
<p class="form-help"><?= $e(\Kanboard\Plugin\Whisper\Service\I18n::text('Your personal rewriting instructions. The bot adds the required JSON format (title and description) and sends the transcript separately. Leave blank to use the default.')) ?></p>
<button type="submit" name="reset_prompt" value="1" class="btn"><?= $e(\Kanboard\Plugin\Whisper\Service\I18n::text('Save with default prompt')) ?></button>
<label for="model"><?= $e(\Kanboard\Plugin\Whisper\Service\I18n::text('Normalization model')) ?></label>
<select id="model" name="model" class="input-large">
<option value="" <?= $values['model'] === '' ? 'selected' : '' ?>><?= $e(\Kanboard\Plugin\Whisper\Service\I18n::text('Default model')) ?> — <?= $values['provider'] === 'deepseek' ? 'deepseek-chat' : 'deepseek/deepseek-chat' ?></option>
<?php $model_options = $model_options ?? []; if ($values['model'] !== '' && !isset($model_options[$values['model']])) { $model_options[$values['model']] = $values['model']; } ?>
<?php foreach ($model_options as $model_id => $model_name): ?>
<option value="<?= $e($model_id) ?>" <?= $values['model'] === $model_id ? 'selected' : '' ?>><?= $e($model_name) ?></option>
<?php endforeach ?>
<option value="__custom__"><?= $e(\Kanboard\Plugin\Whisper\Service\I18n::text('Custom model ID')) ?></option>
</select>
<button type="submit" name="refresh_models" value="1" class="btn"><?= $e(\Kanboard\Plugin\Whisper\Service\I18n::text('Save and refresh models')) ?></button>
<?php if (!empty($model_error)): ?><p class="alert alert-warning"><?= $e($model_error) ?></p><?php endif ?>
<details><summary><?= $e(\Kanboard\Plugin\Whisper\Service\I18n::text('Enter a model manually')) ?></summary>
<label for="custom_model"><?= $e(\Kanboard\Plugin\Whisper\Service\I18n::text('Model ID (used only when Custom model ID is selected)')) ?></label>
<input id="custom_model" name="custom_model" class="input-large" placeholder="deepseek/deepseek-chat">
</details>
<p class="form-help"><?= $e(\Kanboard\Plugin\Whisper\Service\I18n::text('Choose a model from the provider list and save. OpenRouter lists DeepSeek models here. The interface and bot language follow your Kanboard profile; speech language is configured separately.')) ?></p>
<p class="form-help"><?= $e(\Kanboard\Plugin\Whisper\Service\I18n::text('Defaults: deepseek-chat directly; deepseek/deepseek-chat via OpenRouter. The provider receives the transcript only when you press Rewrite with DeepSeek. Audio is transcribed locally. If the API fails, the draft is unchanged.')) ?></p>
</fieldset>
<div class="form-actions"><button type="submit" class="btn btn-blue"><?= $e(\Kanboard\Plugin\Whisper\Service\I18n::text('Save')) ?></button></div>
</form>
<details open><summary><strong><?= $e(\Kanboard\Plugin\Whisper\Service\I18n::text('Setup instructions')) ?></strong></summary>
<ol>
<li><?= $e(\Kanboard\Plugin\Whisper\Service\I18n::text('In Telegram, open')) ?> <a href="https://t.me/BotFather" rel="noreferrer">@BotFather</a><?= $e(\Kanboard\Plugin\Whisper\Service\I18n::text(', send')) ?> <code>/newbot</code><?= $e(\Kanboard\Plugin\Whisper\Service\I18n::text(', choose a name and username, then copy the token into the field above.')) ?></li>
<li><?= $e(\Kanboard\Plugin\Whisper\Service\I18n::text('Enter your numeric Telegram ID. You can find it using')) ?> <code>@userinfobot</code><?= $e(\Kanboard\Plugin\Whisper\Service\I18n::text('. A username such as @name will not work.')) ?></li>
<li><?= $e(\Kanboard\Plugin\Whisper\Service\I18n::text('Choose a project, category and column. These Telegram IDs can create tasks as your account only in projects you can access. Each Kanboard account needs a separate bot.')) ?></li>
<li><?= $e(\Kanboard\Plugin\Whisper\Service\I18n::text('For Docker/OrbStack, run this command from the repository')) ?> <code>./scripts/install-local.sh YOUR_KANBOARD_CONTAINER</code><?= $e(\Kanboard\Plugin\Whisper\Service\I18n::text('. The script installs the plugin and starts Whisper and the bot. Libraries and the model download automatically; the first start needs internet access and may take several minutes. The default model is')) ?> <code>medium</code><?= $e(\Kanboard\Plugin\Whisper\Service\I18n::text(', running on CPU without a paid speech API.')) ?></li>
<li><?= $e(\Kanboard\Plugin\Whisper\Service\I18n::text('Enable the bot, save the settings and send it')) ?> <code>/start</code><?= $e(\Kanboard\Plugin\Whisper\Service\I18n::text('. Then send a voice note (up to 10 minutes / 20 MB) or text. Review the original transcript, optionally rewrite it with DeepSeek, and press Save. The reply includes the task ID and a link if the application URL is configured in Kanboard.')) ?></li>
<li><?= $e(\Kanboard\Plugin\Whisper\Service\I18n::text('Optionally select DeepSeek or OpenRouter and add a key for that service. It will prepare a title and description while keeping the original transcript in the task.')) ?></li>
</ol>
<p><?= $e(\Kanboard\Plugin\Whisper\Service\I18n::text('Commands:')) ?> <code>/projects</code>, <code>/swimlanes</code>, <code>/start</code>, <code>/help</code>, <code>/status</code>, <code>/type</code>, <code>/cancel</code><?= $e(\Kanboard\Plugin\Whisper\Service\I18n::text('. One current draft per Telegram user, retained for 24 hours. Project and swimlane choices survive restarts. A new message replaces the previous draft.')) ?></p>
<p><?= $e(\Kanboard\Plugin\Whisper\Service\I18n::text('The bot uses long polling: public HTTPS and webhooks are not required. If this token previously used a webhook, remove it in the previous integration. Do not run two consumers for one token. Keys are stored in the Kanboard database and never returned in HTML; protect database backups.')) ?></p>
<p><?= $e(\Kanboard\Plugin\Whisper\Service\I18n::text('Stop:')) ?> <code>docker compose --env-file .env.local down</code> <?= $e(\Kanboard\Plugin\Whisper\Service\I18n::text('in the repository. Logs:')) ?> <code>docker compose --env-file .env.local logs --tail=50</code><?= $e(\Kanboard\Plugin\Whisper\Service\I18n::text('. The model is stored in a persistent Docker volume.')) ?></p>
<p><?= $e(\Kanboard\Plugin\Whisper\Service\I18n::text('Documentation:')) ?> <a href="https://core.telegram.org/bots/api#getupdates">Telegram</a>, <a href="https://github.com/SYSTRAN/faster-whisper">faster-whisper</a>, <a href="https://api-docs.deepseek.com/">DeepSeek</a>, <a href="https://openrouter.ai/docs/api/reference/overview">OpenRouter</a>.</p>
<p class="color-grey">📣 <?= $e(\Kanboard\Plugin\Whisper\Service\I18n::text('Author’s Telegram channel:')) ?> <a href="https://t.me/izzypizzy_seo" rel="noreferrer">@izzypizzy_seo</a></p>
</details>
