<?php if ($this->user->isCurrentUser($user['id']) && $this->whisper->allowed($user['id'])): ?>
<li><?= $this->url->link('Telegram Whisper', 'WhisperController', 'index', ['plugin' => 'Whisper']) ?></li>
<?php endif ?>
