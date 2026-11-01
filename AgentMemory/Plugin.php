<?php

namespace Kanboard\Plugin\AgentMemory;

use Kanboard\Core\Plugin\Base;
use Kanboard\Core\Security\Role;
use Kanboard\Plugin\AgentMemory\Model\MemoryService;

class Plugin extends Base
{
    public function initialize()
    {
        if (DB_DRIVER !== 'sqlite') {
            $this->logger->error('AgentMemory requires SQLite; plugin features disabled');
            return;
        }
        $service = new MemoryService($this->container);
        $handler = $this->api->getProcedureHandler();
        $handler->withCallback('appendAgentEntry', function ($task_id, $kind, $data, $request_id, $actor_type = 'ai', $agent = '') use ($service) {
            if (!in_array($kind, array('work_log', 'decision', 'history'), true)) {
                throw new \InvalidArgumentException('Use updateAgentContext for context writes');
            }
            return $service->append($task_id, $kind, $data, $request_id, $actor_type, $agent);
        });
        $handler->withCallback('getAgentEntries', function ($task_id, $kind = '', $after_id = 0, $limit = 50, $query = '') use ($service) {
            $service->authorize($task_id);
            return $service->store()->entries($task_id, $kind, $after_id, $limit, $query);
        });
        $handler->withCallback('getAgentContext', function ($task_id) use ($service) {
            $service->authorize($task_id);
            return $service->store()->getContext($task_id);
        });
        $handler->withCallback('updateAgentContext', function ($task_id, $context, $expected_revision, $request_id, $actor_type = 'ai', $agent = '') use ($service) {
            return $service->append($task_id, 'context', $context, $request_id, $actor_type, $agent, $expected_revision);
        });
        $handler->withCallback('getAgentMessages', function ($task_id, $kind = '', $after_id = 0, $limit = 50, $query = '') use ($service) {
            $service->authorize($task_id);
            return $service->store()->messages($task_id, $kind, $after_id, $limit, $query);
        });
        $handler->withCallback('updateAgentEntry', function ($task_id, $entry_id, $data, $expected_revision, $request_id, $actor_type = 'ai', $agent = '') use ($service) {
            $task = $service->authorize($task_id, true);
            return $service->store()->change($task, $entry_id, 'edit', $data, $expected_revision, $request_id, $service->actor($actor_type, $agent));
        });
        $handler->withCallback('removeAgentEntry', function ($task_id, $entry_id, $expected_revision, $request_id, $actor_type = 'ai', $agent = '') use ($service) {
            $task = $service->authorize($task_id, true);
            return $service->store()->change($task, $entry_id, 'delete', null, $expected_revision, $request_id, $service->actor($actor_type, $agent));
        });
        $handler->withCallback('getAgentEntryChanges', function ($task_id, $entry_id, $after_id = 0, $limit = 50) use ($service) {
            $service->authorize($task_id);
            return $service->store()->changes($task_id, $entry_id, $after_id, $limit);
        });
        $this->projectAccessMap->add('MemoryController', array('save', 'edit', 'confirmDelete', 'delete'), Role::PROJECT_MEMBER);
        $this->projectAccessMap->add('MemoryController', array('changes'), Role::PROJECT_VIEWER);
        $this->template->hook->attachCallable('template:task:show:before-subtasks', 'AgentMemory:task/memory', function () use ($service) {
            return array('store' => $service->store(), 'logBefore' => max(0, $this->request->getIntegerParam('am_work_log_before')), 'decisionBefore' => max(0, $this->request->getIntegerParam('am_decision_before')));
        });
        $this->hook->on('template:layout:css', array('template' => 'plugins/AgentMemory/Assets/memory.css'));
    }

    public function getPluginName() { return 'AgentMemory'; }
    public function getPluginDescription() { return 'Append-only work logs, human decisions and portable agent context.'; }
    public function getPluginAuthor() { return 'S0R4Suke'; }
    public function getPluginHomepage() { return 'https://github.com/S0R4Suke/kanboard-agent-memory'; }
    public function getPluginVersion() { return '0.1.0-rc.1'; }
    public function getCompatibleVersion() { return '>=1.2.55'; }
}
