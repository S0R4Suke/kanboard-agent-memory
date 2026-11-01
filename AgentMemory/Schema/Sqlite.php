<?php

namespace Kanboard\Plugin\AgentMemory\Schema;

const VERSION = 3;

function version_1($pdo)
{
    $pdo->exec('CREATE TABLE agent_memory_entries (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        task_id INTEGER REFERENCES tasks(id) ON DELETE SET NULL,
        original_task_id INTEGER NOT NULL,
        project_id INTEGER NOT NULL,
        task_title TEXT NOT NULL,
        kind TEXT NOT NULL,
        created_at INTEGER NOT NULL,
        recorder_id INTEGER NOT NULL,
        recorder_name TEXT NOT NULL,
        actor_type TEXT NOT NULL,
        agent TEXT NOT NULL,
        request_id TEXT NOT NULL,
        fingerprint TEXT NOT NULL,
        payload TEXT NOT NULL,
        UNIQUE(task_id, request_id)
    )');
    $pdo->exec('CREATE INDEX agent_memory_entries_timeline ON agent_memory_entries(task_id, kind, id)');
    $pdo->exec('CREATE TABLE agent_memory_contexts (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        task_id INTEGER UNIQUE REFERENCES tasks(id) ON DELETE SET NULL,
        revision INTEGER NOT NULL,
        updated_at INTEGER NOT NULL,
        entry_id INTEGER NOT NULL REFERENCES agent_memory_entries(id),
        payload TEXT NOT NULL
    )');
}

function version_2($pdo)
{
    // Keep retry keys reserved even after task deletion and numeric ID reuse.
    $pdo->exec('CREATE UNIQUE INDEX agent_memory_entries_retry ON agent_memory_entries(original_task_id, request_id)');
}

function version_3($pdo)
{
    $pdo->exec('ALTER TABLE agent_memory_entries ADD COLUMN message_id INTEGER');
    $pdo->exec('CREATE INDEX agent_memory_entries_message ON agent_memory_entries(message_id, id)');
    $pdo->exec('CREATE TABLE agent_memory_messages (
        entry_id INTEGER PRIMARY KEY REFERENCES agent_memory_entries(id),
        task_id INTEGER REFERENCES tasks(id) ON DELETE SET NULL,
        revision INTEGER NOT NULL,
        updated_at INTEGER NOT NULL,
        deleted_at INTEGER,
        editor_name TEXT NOT NULL,
        payload TEXT NOT NULL
    )');
    $pdo->exec('CREATE INDEX agent_memory_messages_task ON agent_memory_messages(task_id, entry_id)');
    $pdo->exec('CREATE TABLE agent_memory_changes (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        task_id INTEGER REFERENCES tasks(id) ON DELETE SET NULL,
        original_task_id INTEGER NOT NULL,
        message_id INTEGER NOT NULL REFERENCES agent_memory_entries(id),
        operation TEXT NOT NULL,
        created_at INTEGER NOT NULL,
        recorder_id INTEGER NOT NULL,
        recorder_name TEXT NOT NULL,
        actor_type TEXT NOT NULL,
        agent TEXT NOT NULL,
        request_id TEXT NOT NULL,
        fingerprint TEXT NOT NULL,
        before_json TEXT NOT NULL,
        after_json TEXT NOT NULL,
        UNIQUE(original_task_id, request_id)
    )');
    $pdo->exec('CREATE INDEX agent_memory_changes_message ON agent_memory_changes(message_id, id)');
    // Fold already-recorded corrections into one visible message; keep all source rows.
    $roots = array();
    $rows = $pdo->query("SELECT * FROM agent_memory_entries WHERE kind IN ('work_log','decision') ORDER BY id")->fetchAll(\PDO::FETCH_ASSOC);
    foreach ($rows as $row) {
        $data = json_decode($row['payload'], true, 512, JSON_THROW_ON_ERROR);
        $parent = $roots[$data['supersedes_id'] ?? 0] ?? null;
        $root = ($data['action'] ?? '') === 'correction' && $parent && $parent['task_id'] === $row['task_id'] && $parent['kind'] === $row['kind'] ? $parent['root'] : $row['id'];
        $roots[$row['id']] = array('root' => $root, 'task_id' => $row['task_id'], 'kind' => $row['kind']);
        $statement = $pdo->prepare('UPDATE agent_memory_entries SET message_id=? WHERE id=?');
        $statement->execute(array($root, $row['id']));
        $statement = $pdo->prepare('INSERT INTO agent_memory_messages (entry_id,task_id,revision,updated_at,editor_name,payload) VALUES (?,?,1,?,?,?) ON CONFLICT(entry_id) DO UPDATE SET revision=revision+1, updated_at=excluded.updated_at, editor_name=excluded.editor_name, payload=excluded.payload');
        $statement->execute(array($root, $row['task_id'], $row['created_at'], $row['recorder_name'], $row['payload']));
    }
}
