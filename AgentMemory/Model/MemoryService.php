<?php

namespace Kanboard\Plugin\AgentMemory\Model;

use Kanboard\Core\Base;
use Kanboard\Api\Authorization\TaskAuthorization;
use JsonRPC\Exception\AccessDeniedException;

final class MemoryService extends Base
{
    public function store()
    {
        return new Store($this->db->getConnection());
    }

    public function authorize($taskId, $write = false)
    {
        Store::integer($taskId, 'task_id', 1);
        if (!$this->userSession->isLogged()) {
            throw new AccessDeniedException('Use a user API token; application tokens are not supported');
        }
        TaskAuthorization::getInstance($this->container)->check('TaskProcedure', $write ? 'updateTask' : 'getTask', $taskId);
        $task = $this->taskFinderModel->getById($taskId);
        if (!$task) {
            throw new AccessDeniedException('Task not found or inaccessible');
        }
        return $task;
    }

    public function actor($type, $agent)
    {
        return array('id' => $this->userSession->getId(), 'name' => $this->userSession->getUsername(), 'type' => $type, 'agent' => $agent);
    }

    public function append($taskId, $kind, $data, $requestId, $type, $agent, $revision = null)
    {
        $task = $this->authorize($taskId, true);
        if (!is_array($data)) {
            throw new \InvalidArgumentException('data/context must be an object');
        }
        return $this->store()->append($task, $kind, $data, $requestId, $this->actor($type, $agent), $revision);
    }
}
