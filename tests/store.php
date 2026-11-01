<?php
// Standalone persistence tests: php tests/store.php (pdo_sqlite required).
$pluginRoot = getenv('AGENT_MEMORY_PLUGIN_DIR') ?: __DIR__.'/../AgentMemory';
require $pluginRoot.'/Model/Store.php';
require $pluginRoot.'/Schema/Sqlite.php';

use Kanboard\Plugin\AgentMemory\Model\Store;

$checks = 0;
function check($condition, $message) {
    global $checks;
    if (!$condition) { throw new RuntimeException($message); }
    $checks++;
}
function rejects($callback, $message) {
    try { $callback(); } catch (InvalidArgumentException | RuntimeException $e) { check(true, $message); return; }
    throw new RuntimeException('Expected rejection: '.$message);
}
$pdo = new PDO('sqlite::memory:', null, null, array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION));
$pdo->exec('PRAGMA foreign_keys=ON');
$pdo->exec('CREATE TABLE tasks (id INTEGER PRIMARY KEY, project_id INTEGER, title TEXT)');
$pdo->exec("INSERT INTO tasks VALUES (1,1,'Task'), (2,1,'Other')");
\Kanboard\Plugin\AgentMemory\Schema\version_1($pdo);
\Kanboard\Plugin\AgentMemory\Schema\version_2($pdo);
\Kanboard\Plugin\AgentMemory\Schema\version_3($pdo);
$store = new Store($pdo);
$task = array('id' => 1, 'project_id' => 1, 'title' => 'Task');
$actor = array('id' => 1, 'name' => 'human', 'type' => 'ai', 'agent' => 'test');
$append = function ($kind, $data, $key, $revision = null) use ($store, $task, $actor) { return $store->append($task, $kind, $data, $key, $actor, $revision); };
check($store->getContext(1)['revision'] === 0, 'empty context');
$first = $append('work_log', array('body' => '調査結果 % _ <script>alert(1)</script>'), 'once');
check($append('work_log', array('body' => '調査結果 % _ <script>alert(1)</script>'), 'once') === $first, 'retry returns original');
rejects(fn() => $append('work_log', array('body' => 'different'), 'once'), 'key conflict');
$correction = $store->change($task, $first['id'], 'edit', array('body' => '修正後の内容'), 1, 'log-correction', $actor);
check($store->entry(1, $first['id']) === $first, 'work log original preserved');
check($correction['id'] === $first['id'], 'work log edit retains ID');
check(count($store->messages(1, 'work_log')['entries']) === 1, 'edit uses one message');
check($store->message(1, $first['id'])['data']['body'] === '修正後の内容', 'current body comes from latest correction');
$edited = $store->change($task, $first['id'], 'edit', array('body' => '編集した内容'), 2, 'edit1', $actor);
check($edited['id'] === $first['id'] && $edited['revision'] === 3, 'edit keeps stable message ID');
check(count($store->messages(1, 'work_log')['entries']) === 1, 'edit creates no visible row');
check($store->entry(1, $first['id']) === $first, 'original append remains immutable after edit');
check($store->change($task, $first['id'], 'edit', array('body' => '編集した内容'), 2, 'edit1', $actor) === $edited, 'edit retry is idempotent');
rejects(fn() => $store->change($task, $first['id'], 'edit', array('body' => '競合'), 2, 'stale-edit', $actor), 'stale edit rejected');
rejects(fn() => $store->change($task, $first['id'], 'edit', array('body' => '衝突'), 3, 'once', $actor), 'append key cannot be reused for edit');
$deleted = $store->change($task, $first['id'], 'delete', null, 3, 'delete1', $actor);
check($deleted['deleted_at'] !== null && !$store->messages(1, 'work_log')['entries'], 'delete removes visible message');
check($store->change($task, $first['id'], 'delete', null, 3, 'delete1', $actor) === $deleted, 'delete retry is idempotent');
check($store->message(1, $first['id']) === null, 'deleted message unavailable to normal reader');
check(count($store->changes(1, $first['id'])['changes']) === 3, 'edits and deletion audit snapshots retained');
check(count($store->taskChanges(1)['changes']) === 3 && !$store->taskChanges(2)['changes'], 'task audit includes deletion and isolates tasks');
$auditPage = $store->taskChanges(1, 0, 2);
$auditNext = $store->taskChanges(1, $auditPage['next_after_id'], 2);
check($auditPage['has_more'] && count($auditNext['changes']) === 1 && $auditNext['changes'][0]['operation'] === 'delete', 'task audit cursor has no gaps or duplicates');
check($store->changes(1, $first['id'])['changes'][1]['before']['data']['body'] === '修正後の内容', 'pre-edit content retained');
rejects(fn() => $append('work_log', array('body' => 'stale correction', 'action' => 'correction', 'supersedes_id' => $first['id']), 'bypass-cas'), 'append cannot bypass edit revision checks');
rejects(fn() => $append('work_log', array('body' => '別の記録'), 'delete1'), 'delete key cannot be reused for append');
rejects(fn() => $store->change($task, $first['id'], 'edit', array('body' => '復活'), 4, 'resurrect', $actor), 'editing deleted message rejected');
check($store->entry(2, $first['id']) === null, 'correction form read scoped to task');
rejects(fn() => $append('work_log', array('body' => 'invalid', 'action' => 'correction'), 'log-no-reference'), 'work log correction requires target');
$a = $append('context', array('status' => 'investigating', 'unresolved' => 'A'), 'ctx1', 0);
$b = $append('context', array('status' => 'verified'), 'ctx2', 1);
check($store->getContext(1)['revision'] === 2, 'revision increments');
check($append('context', array('status' => 'investigating', 'unresolved' => 'A'), 'ctx1', 0) === $a, 'old context retry');
rejects(fn() => $append('context', array('status' => 'stale'), 'ctx3', 0), 'stale context');
check(count($store->entries(1, 'context')['entries']) === 2, 'all context snapshots retained');
$decision = $append('decision', array('decision' => 'SQLite', 'judge' => 'user'), 'decision1');
rejects(fn() => $append('work_log', array('body' => 'invalid', 'action' => 'correction', 'supersedes_id' => $decision['id']), 'cross-kind'), 'work log correction rejects decision reference');
$withdrawal = $append('decision', array('decision' => 'Retract', 'judge' => 'user', 'action' => 'withdrawal', 'supersedes_id' => $decision['id']), 'decision2');
check(count($store->entries(1, 'decision')['entries']) === 2, 'withdrawal appends');
rejects(fn() => $store->append(array('id' => 2, 'project_id' => 1, 'title' => 'Other'), 'decision', array('decision' => 'wrong task', 'judge' => 'user', 'supersedes_id' => $decision['id']), 'cross', $actor), 'cross-task reference');
foreach (array(array('body' => ''), array('body' => 'x', 'unknown' => 'x'), array('body' => str_repeat('x', 65537)), array('body' => "\xFF"), array('body' => "a\0b")) as $i => $data) {
    rejects(fn() => $append('history', $data, 'invalid'.$i), 'input validation');
}
rejects(fn() => $append('decision', array('decision' => 'x', 'judge' => 'u', 'action' => 'correction'), 'badcorrection'), 'correction reference required');
rejects(fn() => $append('decision', array('decision' => 'x', 'judge' => 'u', 'decided_at' => '2026-02-30T00:00:00Z'), 'baddate'), 'date validation');
rejects(fn() => $store->entries(1, '', 0, 101), 'bounded pages');
rejects(fn() => $store->entries(1, '', '0'), 'typed cursor');
check(count($store->entries(1, '', 0, 100, '% _')['entries']) === 1, 'literal search');
check(count($store->entries(1, '', 0, 100, "' OR 1=1 --")['entries']) === 0, 'bound search');
check(count($store->entries(2)['entries']) === 0, 'task isolation');
$page1 = $store->entries(1, '', 0, 2);
$page2 = $store->entries(1, '', $page1['next_after_id'], 2);
check($page1['has_more'] && $page2['entries'][0]['id'] > $page1['next_after_id'], 'cursor pagination');
$before = $pdo->query('SELECT count(*) FROM agent_memory_entries')->fetchColumn();
$pdo->exec("CREATE TRIGGER fail_context BEFORE INSERT ON agent_memory_contexts BEGIN SELECT RAISE(ABORT, 'injected failure'); END");
rejects(fn() => $append('context', array('status' => 'rollback'), 'fail', 2), 'transaction rollback');
check($pdo->query('SELECT count(*) FROM agent_memory_entries')->fetchColumn() === $before && $store->getContext(1)['revision'] === 2, 'no partial snapshot or context');
$pdo->exec('DROP TRIGGER fail_context');
$pdo->exec('DELETE FROM tasks WHERE id=1');
check($pdo->query('SELECT count(*) FROM agent_memory_entries')->fetchColumn() === $before, 'task deletion retains entries');
check($pdo->query('SELECT count(*) FROM agent_memory_entries WHERE task_id IS NULL')->fetchColumn() === $before, 'task deletion detaches entries');
$pdo->exec("INSERT INTO tasks VALUES (1,2,'Reused ID')");
check(!$store->entries(1)['entries'] && $store->getContext(1)['revision'] === 0, 'reused task id isolated');
check(!$store->taskChanges(1)['changes'], 'task audit does not expose records from a deleted task with a reused ID');
rejects(fn() => $append('history', array('body' => 'moved'), 'move'), 'task move race');
$pdo->exec('UPDATE tasks SET project_id=1 WHERE id=1');
rejects(fn() => $append('work_log', array('body' => '調査結果 % _ <script>alert(1)</script>'), 'once'), 'deleted task retry rejected even in same project');
check(!$store->entries(1)['entries'], 'old retry never inserted into reused task');
echo "PASS: $checks persistence checks\n";
