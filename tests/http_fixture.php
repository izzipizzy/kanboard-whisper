<?php
require '/var/www/app/app/common.php';
$container['userModel']->update(['id' => 1, 'language' => 'ru_RU']);
$member = $container['userModel']->create(['username' => 'member', 'password' => 'testpass', 'role' => 'app-user', 'language' => 'ru_RU']);
$container['userModel']->create(['username' => 'outsider', 'password' => 'testpass', 'role' => 'app-user', 'language' => 'en_US']);
$project = $container['projectModel']->create(['name' => 'Member project']);
$container['projectUserRoleModel']->addUser($project, $member, 'project-member');
$container['projectModel']->create(['name' => 'Admin-only project']);
