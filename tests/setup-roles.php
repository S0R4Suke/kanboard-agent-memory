<?php
// Executed only inside the isolated test container. Output is captured, never logged.
require '/var/www/app/app/common.php';
$viewerId = (int) $argv[1];
$project = $container['projectModel']->create(array('name' => 'UI permission regressions'));
$role = $container['projectRoleModel']->create($project, 'Memory custom role');
$container['projectUserRoleModel']->addUser($project, $viewerId, 'Memory custom role');
$task = $container['taskCreationModel']->create(array('project_id' => $project, 'title' => 'Admin without membership and custom role'));
$token = bin2hex(random_bytes(24));
$container['userModel']->update(array('id' => $viewerId, 'api_access_token' => $token));
echo json_encode(array('role_task_id' => $task, 'role_project_id' => $project, 'viewer_token' => $token, 'app_token' => $container['configModel']->get('api_token')));
