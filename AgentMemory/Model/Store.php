<?php

namespace Kanboard\Plugin\AgentMemory\Model;

use InvalidArgumentException;
use PDO;
use RuntimeException;
use Throwable;

/** SQLite persistence; all public writes are atomic and idempotent. */
final class Store
{
    private $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
        $pdo->exec('PRAGMA busy_timeout=5000');
    }

    private function query($sql, array $params = array())
    {
        $statement = $this->pdo->prepare($sql);
        $statement->execute($params);
        return $statement;
    }

    public static function integer($value, $name, $minimum = 0, $maximum = PHP_INT_MAX)
    {
        if (!is_int($value) || $value < $minimum || $value > $maximum) {
            throw new InvalidArgumentException($name.' must be an integer in range');
        }
        return $value;
    }

    public static function text($value, $name, $required = false, $max = 65536)
    {
        if (!is_string($value) || strlen($value) > $max || !preg_match('//u', $value) || strpos($value, "\0") !== false || ($required && trim($value) === '')) {
            throw new InvalidArgumentException($name.' must be valid UTF-8 text (max '.$max.' bytes)');
        }
        return $value;
    }

    private function validate($kind, array $data)
    {
        $schemas = array(
            'work_log' => array('body', 'title', 'action', 'supersedes_id'),
            'history' => array('body', 'title'),
            'decision' => array('decision', 'reason', 'options', 'tradeoffs', 'failure_conditions', 'judge', 'action', 'supersedes_id', 'decided_at'),
            'context' => array('status', 'confirmed', 'unresolved', 'next_actions', 'constraints'),
        );
        if (!isset($schemas[$kind]) || array_diff(array_keys($data), $schemas[$kind])) {
            throw new InvalidArgumentException('Unknown kind or fields');
        }
        $required = $kind === 'decision' ? array('decision', 'judge') : ($kind === 'context' ? array('status') : array('body'));
        foreach ($required as $field) {
            self::text($data[$field] ?? null, $field, true);
        }
        foreach ($data as $key => $value) {
            if ($key === 'supersedes_id') {
                self::integer($value, $key, 1);
            } else {
                self::text($value, $key, false, $key === 'title' || $key === 'judge' ? 200 : ($kind === 'context' ? 16384 : 65536));
            }
        }
        if ($kind === 'decision') {
            $action = $data['action'] ?? 'decision';
            if (!in_array($action, array('decision', 'correction', 'withdrawal'), true) || ($action !== 'decision' && empty($data['supersedes_id']))) {
                throw new InvalidArgumentException('Corrections and withdrawals require supersedes_id');
            }
            if (isset($data['decided_at']) && (!preg_match('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ$/D', $data['decided_at']) || gmdate('Y-m-d\TH:i:s\Z', strtotime($data['decided_at'])) !== $data['decided_at'])) {
                throw new InvalidArgumentException('decided_at must be a UTC RFC3339 timestamp');
            }
            $data['action'] = $action;
        }
        if ($kind === 'work_log' && (isset($data['action']) || isset($data['supersedes_id']))) {
            if (($data['action'] ?? '') !== 'correction' || empty($data['supersedes_id'])) {
                throw new InvalidArgumentException('Work log corrections require action=correction and supersedes_id');
            }
        }
        ksort($data);
        return $data;
    }

    /** expectedRevision is only accepted for context writes. */
    public function append(array $task, $kind, array $data, $requestId, array $actor, $expectedRevision = null)
    {
        self::text($requestId, 'request_id', true, 128);
        if (!preg_match('/^[a-zA-Z0-9_.:-]+$/D', $requestId)) {
            throw new InvalidArgumentException('Invalid request_id');
        }
        if (!in_array($actor['type'], array('human', 'ai'), true)) {
            throw new InvalidArgumentException('actor_type must be human or ai');
        }
        self::text($actor['agent'], 'agent', false, 200);
        $data = $this->validate($kind, $data);
        if ($kind === 'context') {
            self::integer($expectedRevision, 'expected_revision');
        } elseif ($expectedRevision !== null) {
            throw new InvalidArgumentException('Unexpected revision');
        }
        $payload = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        if (strlen($payload) > 131072) {
            throw new InvalidArgumentException('Payload exceeds 128 KiB');
        }
        $fingerprint = hash('sha256', json_encode(array($kind, $data, $actor['id'], $actor['type'], $actor['agent'], $expectedRevision), JSON_THROW_ON_ERROR));
        $this->pdo->exec('BEGIN IMMEDIATE');
        try {
            // A task can disappear between authorization and acquiring the write lock.
            $live = $this->query('SELECT id, project_id FROM tasks WHERE id=?', array($task['id']))->fetch(PDO::FETCH_ASSOC);
            if (!$live || (int) $live['project_id'] !== (int) $task['project_id']) {
                throw new RuntimeException('Task removed or moved; reload and retry', 409);
            }
            if ($this->query('SELECT id FROM agent_memory_changes WHERE original_task_id=? AND request_id=?', array($task['id'], $requestId))->fetchColumn()) {
                throw new RuntimeException('request_id already used for an edit or deletion', 409);
            }
            $existing = $this->query('SELECT * FROM agent_memory_entries WHERE original_task_id=? AND request_id=?', array($task['id'], $requestId))->fetch(PDO::FETCH_ASSOC);
            if ($existing) {
                if ($existing['task_id'] === null) {
                    throw new RuntimeException('request_id belongs to a deleted task; confirm the task identity', 409);
                }
                if (!hash_equals($existing['fingerprint'], $fingerprint)) {
                    throw new RuntimeException('request_id already used with different content or actor', 409);
                }
                $this->pdo->exec('COMMIT');
                return $this->decode($existing);
            }
            if (($data['action'] ?? '') === 'correction') {
                throw new InvalidArgumentException('Use updateAgentEntry with expected_revision to edit a message');
            }
            if (isset($data['supersedes_id'])) {
                $target = $this->query('SELECT id FROM agent_memory_entries WHERE id=? AND task_id=? AND kind=?', array($data['supersedes_id'], $task['id'], $kind))->fetchColumn();
                if (!$target) {
                    throw new InvalidArgumentException('supersedes_id must reference the same record kind in this task');
                }
            }
            $now = time();
            if ($kind === 'context') {
                $current = $this->getContext((int) $task['id']);
                if ($current['revision'] !== $expectedRevision) {
                    throw new RuntimeException('Context revision conflict; fetch and merge before retrying', 409);
                }
                $payload = json_encode(array('revision' => $expectedRevision + 1, 'context' => $data), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            }
            $this->query('INSERT INTO agent_memory_entries (task_id, original_task_id, project_id, task_title, kind, created_at, recorder_id, recorder_name, actor_type, agent, request_id, fingerprint, payload) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)', array(
                $task['id'], $task['id'], $task['project_id'], $task['title'], $kind, $now, $actor['id'], $actor['name'], $actor['type'], $actor['agent'], $requestId, $fingerprint, $payload,
            ));
            $id = (int) $this->pdo->lastInsertId();
            if (in_array($kind, array('work_log', 'decision'), true)) {
                $root = $id;
                $this->query('UPDATE agent_memory_entries SET message_id=? WHERE id=?', array($root, $id));
                $this->query('INSERT INTO agent_memory_messages (entry_id,task_id,revision,updated_at,editor_name,payload) VALUES (?,?,1,?,?,?) ON CONFLICT(entry_id) DO UPDATE SET revision=revision+1, updated_at=excluded.updated_at, editor_name=excluded.editor_name, payload=excluded.payload', array($root, $task['id'], $now, $actor['name'], $payload));
            }
            if ($kind === 'context') {
                $this->query('INSERT INTO agent_memory_contexts (task_id,revision,updated_at,entry_id,payload) VALUES (?,?,?,?,?) ON CONFLICT(task_id) DO UPDATE SET revision=excluded.revision, updated_at=excluded.updated_at, entry_id=excluded.entry_id, payload=excluded.payload', array($task['id'], $expectedRevision + 1, $now, $id, $payload));
            }
            $entry = $this->query('SELECT * FROM agent_memory_entries WHERE id=?', array($id))->fetch(PDO::FETCH_ASSOC);
            $this->pdo->exec('COMMIT');
            return $this->decode($entry);
        } catch (Throwable $error) {
            $this->pdo->exec('ROLLBACK');
            throw $error;
        }
    }

    public function getContext($taskId)
    {
        $row = $this->query('SELECT * FROM agent_memory_contexts WHERE task_id=?', array($taskId))->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return array('revision' => 0, 'context' => new \stdClass(), 'updated_at' => null, 'entry_id' => null);
        }
        return array_merge(json_decode($row['payload'], true, 512, JSON_THROW_ON_ERROR), array('updated_at' => (int) $row['updated_at'], 'entry_id' => (int) $row['entry_id']));
    }

    public function entry($taskId, $entryId)
    {
        self::integer($entryId, 'entry_id', 1);
        $row = $this->query('SELECT * FROM agent_memory_entries WHERE task_id=? AND id=?', array($taskId, $entryId))->fetch(PDO::FETCH_ASSOC);
        return $row ? $this->decode($row) : null;
    }

    private function currentRow(array $row)
    {
        $row['payload'] = $row['current_payload'];
        unset($row['current_payload']);
        return $this->decode($row);
    }

    public function message($taskId, $entryId, $includeDeleted = false)
    {
        self::integer($entryId, 'entry_id', 1);
        $sql = 'SELECT e.*,m.payload AS current_payload,m.revision,m.updated_at,m.deleted_at,m.editor_name FROM agent_memory_entries a JOIN agent_memory_messages m ON m.entry_id=a.message_id JOIN agent_memory_entries e ON e.id=m.entry_id WHERE a.id=? AND a.task_id=? AND m.task_id=?';
        if (!$includeDeleted) { $sql .= ' AND m.deleted_at IS NULL'; }
        $row = $this->query($sql, array($entryId, $taskId, $taskId))->fetch(PDO::FETCH_ASSOC);
        return $row ? $this->currentRow($row) : null;
    }

    public function messages($taskId, $kind = '', $afterId = 0, $limit = 50, $query = '', $beforeId = 0, $descending = false)
    {
        self::integer($afterId, 'after_id');
        self::integer($beforeId, 'before_id');
        self::integer($limit, 'limit', 1, 100);
        self::text($query, 'query', false, 500);
        if (!in_array($kind, array('', 'work_log', 'decision'), true)) { throw new InvalidArgumentException('Unknown message kind'); }
        $sql = 'SELECT e.*,m.payload AS current_payload,m.revision,m.updated_at,m.deleted_at,m.editor_name FROM agent_memory_messages m JOIN agent_memory_entries e ON e.id=m.entry_id WHERE m.task_id=? AND m.deleted_at IS NULL AND e.id>?';
        $params = array($taskId, $afterId);
        if ($kind !== '') { $sql .= ' AND e.kind=?'; $params[] = $kind; }
        if ($query !== '') { $sql .= ' AND instr(lower(m.payload),lower(?))>0'; $params[] = $query; }
        if ($beforeId) { $sql .= ' AND e.id<?'; $params[] = $beforeId; }
        $sql .= ' ORDER BY e.id '.($descending ? 'DESC' : 'ASC').' LIMIT '.($limit + 1);
        $rows = $this->query($sql, $params)->fetchAll(PDO::FETCH_ASSOC);
        $hasMore = count($rows) > $limit;
        $rows = array_slice($rows, 0, $limit);
        return array('entries' => array_map(array($this, 'currentRow'), $rows), 'has_more' => $hasMore, 'next_after_id' => $rows ? (int) end($rows)['id'] : $afterId);
    }

    /** Edit/delete a stable visible message while retaining immutable before/after snapshots. */
    public function change(array $task, $entryId, $operation, $data, $expectedRevision, $requestId, array $actor)
    {
        self::integer($entryId, 'entry_id', 1);
        self::integer($expectedRevision, 'expected_revision', 1);
        self::text($requestId, 'request_id', true, 128);
        if (!preg_match('/^[a-zA-Z0-9_.:-]+$/D', $requestId) || !in_array($operation, array('edit', 'delete'), true) || !in_array($actor['type'], array('human', 'ai'), true)) {
            throw new InvalidArgumentException('Invalid change parameters');
        }
        self::text($actor['agent'], 'agent', false, 200);
        if ($operation === 'edit' && !is_array($data)) { throw new InvalidArgumentException('data must be an object'); }
        if ($operation === 'delete' && $data !== null) { throw new InvalidArgumentException('Delete does not accept data'); }
        $this->pdo->exec('BEGIN IMMEDIATE');
        try {
            $live = $this->query('SELECT project_id FROM tasks WHERE id=?', array($task['id']))->fetchColumn();
            if ($live === false || (int) $live !== (int) $task['project_id']) { throw new RuntimeException('Task removed or moved; reload and retry', 409); }
            $before = $this->message((int) $task['id'], $entryId, true);
            if (!$before) { throw new InvalidArgumentException('Message not found in this task'); }
            if ($operation === 'edit') {
                $data = $this->validate($before['kind'], $data);
                if (strlen(json_encode($data, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)) > 131072) { throw new InvalidArgumentException('Payload exceeds 128 KiB'); }
            }
            $fingerprint = hash('sha256', json_encode(array($entryId, $operation, $data, $expectedRevision, $actor['id'], $actor['type'], $actor['agent']), JSON_THROW_ON_ERROR));
            $existing = $this->query('SELECT * FROM agent_memory_changes WHERE original_task_id=? AND request_id=?', array($task['id'], $requestId))->fetch(PDO::FETCH_ASSOC);
            if ($existing) {
                if ($existing['task_id'] === null || !hash_equals($existing['fingerprint'], $fingerprint)) { throw new RuntimeException('request_id already used', 409); }
                $this->pdo->exec('COMMIT');
                return json_decode($existing['after_json'], true, 512, JSON_THROW_ON_ERROR);
            }
            if ($this->query('SELECT id FROM agent_memory_entries WHERE original_task_id=? AND request_id=?', array($task['id'], $requestId))->fetchColumn()) { throw new RuntimeException('request_id already used for an append', 409); }
            if ($before['deleted_at'] !== null || $before['revision'] !== $expectedRevision) { throw new RuntimeException('Message changed or deleted; reload before saving', 409); }
            if ($operation === 'edit' && isset($data['supersedes_id'])) {
                $target = $this->entry((int) $task['id'], $data['supersedes_id']);
                if (!$target || $target['kind'] !== $before['kind']) { throw new InvalidArgumentException('Invalid reference'); }
            }
            $now = time();
            $payload = json_encode($operation === 'edit' ? $data : $before['data'], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            $this->query('UPDATE agent_memory_messages SET payload=?,revision=revision+1,updated_at=?,deleted_at=?,editor_name=? WHERE entry_id=?', array($payload, $now, $operation === 'delete' ? $now : null, $actor['name'], $before['id']));
            $after = $this->message((int) $task['id'], $before['id'], true);
            $this->query('INSERT INTO agent_memory_changes (task_id,original_task_id,message_id,operation,created_at,recorder_id,recorder_name,actor_type,agent,request_id,fingerprint,before_json,after_json) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)', array($task['id'], $task['id'], $before['id'], $operation, $now, $actor['id'], $actor['name'], $actor['type'], $actor['agent'], $requestId, $fingerprint, json_encode($before, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), json_encode($after, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)));
            $this->pdo->exec('COMMIT');
            return $after;
        } catch (Throwable $error) {
            $this->pdo->exec('ROLLBACK');
            throw $error;
        }
    }

    public function changes($taskId, $entryId, $afterId = 0, $limit = 50)
    {
        self::integer($afterId, 'after_id'); self::integer($limit, 'limit', 1, 100);
        $message = $this->message($taskId, $entryId, true);
        if (!$message) { throw new InvalidArgumentException('Message not found in this task'); }
        return $this->readChanges($taskId, $message['id'], $afterId, $limit);
    }

    public function taskChanges($taskId, $afterId = 0, $limit = 50)
    {
        self::integer($taskId, 'task_id', 1);
        self::integer($afterId, 'after_id'); self::integer($limit, 'limit', 1, 100);
        return $this->readChanges($taskId, null, $afterId, $limit);
    }

    private function readChanges($taskId, $messageId, $afterId, $limit)
    {
        $sql = 'SELECT * FROM agent_memory_changes WHERE task_id=? AND id>?';
        $params = array($taskId, $afterId);
        if ($messageId !== null) { $sql .= ' AND message_id=?'; $params[] = $messageId; }
        $rows = $this->query($sql.' ORDER BY id LIMIT '.($limit + 1), $params)->fetchAll(PDO::FETCH_ASSOC);
        $hasMore = count($rows) > $limit; $rows = array_slice($rows, 0, $limit);
        foreach ($rows as &$row) {
            foreach (array('id', 'task_id', 'original_task_id', 'message_id', 'created_at', 'recorder_id') as $key) { $row[$key] = (int) $row[$key]; }
            $row['before'] = json_decode($row['before_json'], true, 512, JSON_THROW_ON_ERROR);
            $row['after'] = json_decode($row['after_json'], true, 512, JSON_THROW_ON_ERROR);
            unset($row['before_json'], $row['after_json'], $row['fingerprint']);
        }
        unset($row);
        return array('changes' => $rows, 'has_more' => $hasMore, 'next_after_id' => $rows ? (int) end($rows)['id'] : $afterId);
    }

    public function entries($taskId, $kind = '', $afterId = 0, $limit = 50, $query = '', $beforeId = 0, $descending = false)
    {
        self::integer($afterId, 'after_id');
        self::integer($beforeId, 'before_id');
        self::integer($limit, 'limit', 1, 100);
        self::text($query, 'query', false, 500);
        if (!in_array($kind, array('', 'work_log', 'decision', 'history', 'context'), true)) {
            throw new InvalidArgumentException('Unknown kind');
        }
        $sql = 'SELECT * FROM agent_memory_entries WHERE task_id=? AND id>?';
        $params = array($taskId, $afterId);
        if ($kind !== '') {
            $sql .= ' AND kind=?';
            $params[] = $kind;
        }
        if ($query !== '') {
            $sql .= ' AND instr(lower(payload),lower(?))>0';
            $params[] = $query;
        }
        if ($beforeId > 0) {
            $sql .= ' AND id<?';
            $params[] = $beforeId;
        }
        $sql .= ' ORDER BY id '.($descending ? 'DESC' : 'ASC').' LIMIT '.($limit + 1);
        $rows = $this->query($sql, $params)->fetchAll(PDO::FETCH_ASSOC);
        $hasMore = count($rows) > $limit;
        $rows = array_slice($rows, 0, $limit);
        return array('entries' => array_map(array($this, 'decode'), $rows), 'has_more' => $hasMore, 'next_after_id' => $rows ? (int) end($rows)['id'] : $afterId);
    }

    private function decode(array $row)
    {
        foreach (array('id', 'task_id', 'original_task_id', 'project_id', 'created_at', 'recorder_id', 'message_id', 'revision', 'updated_at', 'deleted_at') as $key) {
            if (array_key_exists($key, $row)) { $row[$key] = $row[$key] === null ? null : (int) $row[$key]; }
        }
        $row['data'] = json_decode($row['payload'], true, 512, JSON_THROW_ON_ERROR);
        unset($row['payload'], $row['fingerprint']);
        return $row;
    }
}
