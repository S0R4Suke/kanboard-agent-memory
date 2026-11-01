<?php

namespace Kanboard\Plugin\AgentMemory\Controller;

use Kanboard\Controller\BaseController;
use Kanboard\Core\Controller\AccessForbiddenException;
use Kanboard\Core\Controller\PageNotFoundException;
use Kanboard\Plugin\AgentMemory\Model\MemoryService;
use Kanboard\Plugin\AgentMemory\Model\DecisionTime;
use InvalidArgumentException;
use RuntimeException;

class MemoryController extends BaseController
{
    public function changes()
    {
        $task = $this->getTask();
        if (!$this->helper->user->hasProjectAccess('TaskViewController', 'show', $task['project_id'])) {
            throw new AccessForbiddenException();
        }
        $store = (new MemoryService($this->container))->store();
        $entryId = $this->request->getIntegerParam('entry_id');
        $afterId = $this->request->getIntegerParam('after_id');
        if ($entryId < 0 || $afterId < 0) { throw new PageNotFoundException(); }
        if ($entryId && !$store->message((int) $task['id'], $entryId, true)) {
            throw new PageNotFoundException();
        }
        $page = $entryId ? $store->changes((int) $task['id'], $entryId, $afterId, 20)
                        : $store->taskChanges((int) $task['id'], $afterId, 20);
        $this->response->html($this->helper->layout->task('AgentMemory:task/changes', array(
            'task' => $task, 'project' => $this->projectModel->getById($task['project_id']),
            'page' => $page, 'entryId' => $entryId, 'afterId' => $afterId,
        )));
    }

    private function editableTask()
    {
        $task = $this->getTask();
        if (!$this->helper->user->hasProjectAccess('TaskModificationController', 'edit', $task['project_id'])) {
            throw new AccessForbiddenException();
        }
        return $task;
    }

    public function edit()
    {
        $task = $this->editableTask();
        $entryId = $this->request->getIntegerParam('entry_id');
        if ($entryId < 1) {
            throw new PageNotFoundException();
        }
        $service = new MemoryService($this->container);
        $entry = $service->store()->message((int) $task['id'], $entryId);
        if (!$entry || !in_array($entry['kind'], array('work_log', 'decision'), true)) {
            throw new PageNotFoundException();
        }
        $values = $entry['data'];
        $values['kind'] = $entry['kind'];
        $values['entry_id'] = $entry['id'];
        $values['expected_revision'] = $entry['revision'];
        $values['request_id'] = bin2hex(random_bytes(16));
        $this->response->html($this->helper->layout->task('AgentMemory:task/edit', array('task' => $task, 'project' => $this->projectModel->getById($task['project_id']), 'values' => $values)));
    }

    private function retry(array $task, array $values, $message, $status)
    {
        $this->response->html($this->helper->layout->task('AgentMemory:task/retry', array('task' => $task, 'project' => $this->projectModel->getById($task['project_id']), 'values' => $values, 'error' => $message)), $status);
    }

    private function deletableMessage(array $task, $entryId)
    {
        if ($entryId < 1) { throw new PageNotFoundException(); }
        $service = new MemoryService($this->container);
        $entry = $service->store()->message((int) $task['id'], $entryId, true);
        if (!$entry) { throw new PageNotFoundException(); }
        return $entry;
    }

    public function confirmDelete()
    {
        $task = $this->editableTask();
        $entry = $this->deletableMessage($task, $this->request->getIntegerParam('entry_id'));
        $this->deleteForm($task, $entry);
    }

    private function deleteForm(array $task, array $entry, $error = '', $status = 200)
    {
        $this->response->html($this->helper->layout->task('AgentMemory:task/delete', array('task' => $task, 'project' => $this->projectModel->getById($task['project_id']), 'entry' => $entry, 'error' => $error)), $status);
    }

    public function delete()
    {
        if (!$this->request->isPost()) { throw new AccessForbiddenException(); }
        $task = $this->editableTask();
        $values = $this->request->getRawFormValues();
        foreach ($values as $value) { if (!is_string($value)) { throw new AccessForbiddenException(); } }
        $entry = $this->deletableMessage($task, ctype_digit($values['entry_id'] ?? '') ? (int) $values['entry_id'] : 0);
        if (!$this->token->validateCSRFToken($values['csrf_token'] ?? '')) {
            $this->deleteForm($task, $entry, '削除の確認に失敗しました。内容を確認して、もう一度操作してください。', 403);
            return;
        }
        try {
            $service = new MemoryService($this->container);
            $service->store()->change($task, (int) $entry['id'], 'delete', null, ctype_digit($values['expected_revision'] ?? '') ? (int) $values['expected_revision'] : 0, $values['request_id'] ?? '', $service->actor('human', ''));
            $this->flash->success('メッセージを削除しました。');
            $this->response->redirect($this->helper->url->to('TaskViewController', 'show', array('task_id' => $task['id'])), true);
        } catch (InvalidArgumentException | RuntimeException $error) {
            $this->deleteForm($task, $entry, $error->getMessage(), $error->getCode() === 409 ? 409 : 422);
        }
    }

    public function save()
    {
        if (!$this->request->isPost()) {
            throw new AccessForbiddenException();
        }
        $task = $this->editableTask();
        $values = $this->request->getRawFormValues();
        foreach ($values as $value) {
            if (!is_string($value)) {
                throw new AccessForbiddenException();
            }
        }
        $kind = $values['kind'] ?? '';
        if (!in_array($kind, array('work_log', 'decision'), true)) {
            throw new AccessForbiddenException();
        }
        if (!$this->token->validateCSRFToken($values['csrf_token'] ?? '')) {
            $this->logger->warning('AgentMemory save rejected: invalid form token; task_id='.(int) $task['id'].' user_id='.(int) $this->userSession->getId());
            unset($values['csrf_token']);
            $this->retry($task, $values, '保存の確認に失敗しました。入力内容を確認して、もう一度保存してください。', 403);
            return;
        }
        unset($values['csrf_token']);
        // An already-open form from the append-only UI has no revision. Never turn
        // its submission into a blind edit or another message; ask for confirmation.
        if (empty($values['entry_id']) && ($values['action'] ?? '') === 'correction' && ctype_digit($values['supersedes_id'] ?? '')) {
            $legacy = $this->deletableMessage($task, (int) $values['supersedes_id']);
            if ($legacy['kind'] !== $kind || $legacy['deleted_at'] !== null) { throw new PageNotFoundException(); }
            $values['entry_id'] = $legacy['id'];
            $values['expected_revision'] = $legacy['revision'];
            $values['request_id'] = bin2hex(random_bytes(16));
            $this->retry($task, $values, '編集方式が変わりました。内容を確認して「更新する」を押してください。', 409);
            return;
        }
        $data = array();
        $fields = $kind === 'work_log' ? array('title', 'body') : array('decision', 'reason', 'options', 'tradeoffs', 'failure_conditions', 'judge');
        $entryId = $values['entry_id'] ?? '';
        $entry = null;
        if ($entryId !== '') {
            $entry = $this->deletableMessage($task, ctype_digit($entryId) ? (int) $entryId : 0);
            if ($entry['kind'] !== $kind) { throw new AccessForbiddenException(); }
            $data = $entry['data'];
            foreach ($fields as $field) { unset($data[$field]); }
        } else {
            $fields = array_merge($fields, array('action', 'supersedes_id'));
        }
        foreach ($fields as $field) {
            if (isset($values[$field]) && $values[$field] !== '') {
                $data[$field] = $field === 'supersedes_id' && ctype_digit($values[$field]) ? (int) $values[$field] : $values[$field];
            }
        }
        try {
            if ($kind === 'decision' && array_key_exists('decided_at_local', $values)) {
                $previous = $data['decided_at'] ?? '';
                unset($data['decided_at']);
                if ($values['decided_at_local'] !== '') {
                    $data['decided_at'] = DecisionTime::toUtc($values['decided_at_local'], $values['decided_at_timezone'] ?? date_default_timezone_get(), $previous, $values['decided_at_choice'] ?? '');
                }
            }
            $service = new MemoryService($this->container);
            if ($entry) {
                $service->store()->change($task, (int) $entry['id'], 'edit', $data, ctype_digit($values['expected_revision'] ?? '') ? (int) $values['expected_revision'] : 0, $values['request_id'] ?? '', $service->actor('human', ''));
            } else {
                $service->store()->append($task, $kind, $data, $values['request_id'] ?? '', $service->actor('human', ''));
            }
            $this->flash->success($entry ? 'メッセージを更新しました。' : '履歴を追加しました。');
            $this->response->redirect($this->helper->url->to('TaskViewController', 'show', array('task_id' => $task['id'])), true);
        } catch (InvalidArgumentException | RuntimeException $error) {
            // Keep the user's text and idempotency key available for correction/retry.
            $this->retry($task, $values, $error->getMessage(), $error->getCode() === 409 ? 409 : 422);
        }
    }
}
