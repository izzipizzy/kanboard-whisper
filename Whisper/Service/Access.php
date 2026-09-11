<?php
namespace Kanboard\Plugin\Whisper\Service;

use Kanboard\Core\Security\Role;
use Kanboard\Model\ColumnRestrictionModel;
use Kanboard\Model\ProjectRoleRestrictionModel;

class Access
{
    public static function grantedIds($c)
    {
        return array_map('intval', array_column($c['db']->table('whisper_connections')->eq('granted', 1)->findAll(), 'user_id'));
    }
    public static function grant($c, array $ids)
    {
        $old = self::grantedIds($c);
        $c['db']->table('whisper_connections')->update(['granted' => 0]);
        foreach ($ids as $id) {
            Settings::ensure($c, $id);
            $c['db']->table('whisper_connections')->eq('user_id', $id)->update(['granted' => 1]);
        }
        foreach (array_diff($old, $ids) as $id) {
            // Revocation disables the connection; restoring a grant does not restart it.
            $values = Settings::read($c, $id); $values['enabled'] = '0';
            Settings::save($c, $id, $values);
        }
    }
    public static function allowed($c, $userId)
    {
        $user = $c['userModel']->getById($userId);
        return $user && $user['is_active'] && ($user['role'] === Role::APP_ADMIN || in_array((int)$userId, self::grantedIds($c), true));
    }
    public static function canCreate($c, $userId, $projectId, $columnId = null)
    {
        if (!self::allowed($c, $userId)) { return false; }
        if (!$c['db']->table('projects')->eq('id', $projectId)->eq('is_active', 1)->exists()) { return false; }
        $user = $c['userModel']->getById($userId);
        if ($user['role'] === Role::APP_ADMIN) { return true; }
        if (!$c['applicationAuthorization']->isAllowed('TaskCreationController', 'save', $user['role'])) { return false; }
        $role = $c['projectUserRoleModel']->getUserRole($projectId, $userId);
        if (!$role) { return false; }
        $custom = $c['role']->isCustomProjectRole($role);
        if (!$c['projectAuthorization']->isAllowed('TaskCreationController', 'save', $custom ? Role::PROJECT_MEMBER : $role)) { return false; }
        if (!$custom) { return true; }
        if ($columnId === null) {
            foreach ($c['columnModel']->getAll($projectId) as $column) {
                if (self::canCreate($c, $userId, $projectId, $column['id'])) { return true; }
            }
            return false;
        }
        foreach ($c['columnRestrictionModel']->getAllByRole($projectId, $role) as $restriction) {
            if ($restriction['column_id'] == $columnId) {
                if ($restriction['rule'] === ColumnRestrictionModel::RULE_ALLOW_TASK_CREATION) { return true; }
                if ($restriction['rule'] === ColumnRestrictionModel::RULE_BLOCK_TASK_CREATION) { return false; }
            }
        }
        foreach ($c['projectRoleRestrictionModel']->getAllByRole($projectId, $role) as $restriction) {
            if ($restriction['rule'] === ProjectRoleRestrictionModel::RULE_TASK_CREATION) { return false; }
        }
        return true;
    }
    public static function projects($c, $userId)
    {
        return array_values(array_filter($c['db']->table('projects')->eq('is_active', 1)->asc('name')->findAll(), function ($project) use ($c, $userId) {
            return self::canCreate($c, $userId, $project['id']);
        }));
    }
    public static function firstColumn($c, $userId, $projectId)
    {
        foreach ($c['columnModel']->getAll($projectId) as $column) {
            if (self::canCreate($c, $userId, $projectId, $column['id'])) { return (int)$column['id']; }
        }
        return 0;
    }
}
