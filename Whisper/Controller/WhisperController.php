<?php
namespace Kanboard\Plugin\Whisper\Controller;

use Kanboard\Controller\BaseController;
use Kanboard\Plugin\Whisper\Service\I18n;
use Kanboard\Core\Controller\AccessForbiddenException;
use Kanboard\Plugin\Whisper\Service\Settings;
use Kanboard\Plugin\Whisper\Service\Access;

class WhisperController extends BaseController
{
    private function draft()
    {
        $draft = session_get('whisper_settings_draft_'.$this->userSession->getId());
        if (!is_array($draft) || ($draft['expires'] ?? 0) < time()) {
            session_set('whisper_settings_draft_'.$this->userSession->getId(), null);
            return null;
        }
        return $draft;
    }
    public function index()
    {
        // Keep Kanboard and other plugins in the current web locale.
        if (!Access::allowed($this->container, $this->userSession->getId())) { throw new AccessForbiddenException(); }
        $draft = $this->draft();
        $values = $draft ? $draft['values'] : Settings::read($this->container, $this->userSession->getId());
        $projects = Access::projects($this->container, $this->userSession->getId());
        $projectIds = array_column($projects, 'id') ?: [-1];
        $catalog = \Kanboard\Plugin\Whisper\Service\Models::cached($values, $this->userSession->getId());
        $this->response->html($this->helper->layout->user('Whisper:config/index', [
            'user' => $this->userModel->getById($this->userSession->getId()),
            'managed_users' => $this->userSession->isAdmin() ? $this->db->table('users')->eq('is_active', 1)->neq('role', 'app-admin')->findAll() : null,
            'granted_users' => Access::grantedIds($this->container),
            'title' => 'Telegram Whisper', 'values' => $values,
            'model_options' => $catalog['models'], 'model_error' => $catalog['error'],
            'form_error' => $draft['error'] ?? '', 'has_draft' => $draft !== null,
            'projects' => $projects,
            'categories' => $this->db->table('project_has_categories')->in('project_id', $projectIds)->asc('name')->findAll(),
            'columns' => $this->db->table('columns')->in('project_id', $projectIds)->asc('position')->findAll(),
            'worker' => is_file(DATA_DIR.'/whisper/heartbeat') ? (int)file_get_contents(DATA_DIR.'/whisper/heartbeat') : 0,
        ]));
    }
    public function save()
    {
        // Keep Kanboard and other plugins in the current web locale.
        if (!Access::allowed($this->container, $this->userSession->getId()) || !$this->request->isPost()) { throw new AccessForbiddenException(); }
        $input = $this->request->getValues();
        // getValues() validates CSRF and removes the token; an invalid form is empty.
        if (!$input) { throw new AccessForbiddenException(); }
        $draft = $this->draft();
        $values = $draft ? $draft['values'] : Settings::read($this->container, $this->userSession->getId());
        foreach (Settings::DEFAULTS as $key => $default) {
            if (in_array($key, ['bot_token', 'api_key'], true) && empty($input[$key])) {
                if (!empty($input['clear_'.$key])) { $values[$key] = ''; }
                continue;
            }
            $values[$key] = is_scalar($input[$key] ?? '') ? trim((string)($input[$key] ?? '')) : '';
        }
        foreach (['enabled'] as $key) { $values[$key] = empty($input[$key]) ? '0' : '1'; }
        $values['confirm'] = '1'; // Transcript-first flow always requires explicit Save.
        foreach (['project_id', 'category_id', 'column_id'] as $key) { $values[$key] = (string)(int)$values[$key]; }
        if ($values['model'] === '__custom__') { $values['model'] = is_string($input['custom_model'] ?? null) ? trim($input['custom_model']) : ''; }
        if (!empty($input['reset_prompt']) || trim($values['normalization_prompt']) === '') { $values['normalization_prompt'] = \Kanboard\Plugin\Whisper\Service\Normalizer::DEFAULT_PROMPT; }
        try {
            Settings::validate($values, $this->container);
            if (!Settings::save($this->container, $this->userSession->getId(), $values)) { throw new \RuntimeException(I18n::text('Could not save settings.')); }
            session_set('whisper_settings_draft_'.$this->userSession->getId(), null);
            if (!empty($input['refresh_models'])) { \Kanboard\Plugin\Whisper\Service\Models::clearCache($this->userSession->getId()); }
            $this->flash->success(I18n::text('Settings saved. The bot will apply changes within a minute.'));
        } catch (\RuntimeException $e) {
            // Retain credentials only in this administrator's server-side session.
            // They never appear in password input values or in a redirect URL.
            session_set('whisper_settings_draft_'.$this->userSession->getId(), [
                'values' => $values, 'expires' => time() + 1800, 'error' => $e->getMessage(),
            ]);
        }
        $this->response->redirect($this->helper->url->to('WhisperController', 'index', ['plugin' => 'Whisper']));
    }
    public function permissions()
    {
        // Keep Kanboard and other plugins in the current web locale.
        if (!$this->userSession->isAdmin() || !$this->request->isPost()) { throw new AccessForbiddenException(); }
        $input = $this->request->getValues();
        if (!$input) { throw new AccessForbiddenException(); }
        $ids = array_map('intval', is_array($input['users'] ?? null) ? $input['users'] : []);
        $valid = $this->db->table('users')->eq('is_active', 1)->findAll();
        $ids = array_values(array_intersect($ids, array_map('intval', array_column($valid, 'id'))));
        Access::grant($this->container, $ids);
        $this->flash->success(I18n::text('Plugin access updated. Users can configure connections in their profiles.'));
        $this->response->redirect($this->helper->url->to('WhisperController', 'index', ['plugin' => 'Whisper']));
    }

}
