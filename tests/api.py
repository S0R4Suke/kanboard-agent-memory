#!/usr/bin/env python3
"""Integration/regression suite. Run only against the isolated tests/compose.yaml."""
import base64
import concurrent.futures
import importlib.util
import json
import os
from pathlib import Path
import subprocess
import sys
import uuid

ROOT = Path(__file__).resolve().parent.parent
sys.path.insert(0, str(ROOT / 'examples'))
from client import Client

URL = os.environ.get('TEST_KANBOARD_URL', 'http://127.0.0.1:8091/jsonrpc.php')
if URL != 'http://127.0.0.1:8091/jsonrpc.php':
    raise RuntimeError('Integration tests only target the isolated 8091 stack; refusing another endpoint')
admin = Client(URL, 'admin', 'admin')
checks = 0


def check(condition, label):
    global checks
    if not condition:
        raise AssertionError(label)
    checks += 1


def denied(callback, label):
    try:
        callback()
    except RuntimeError:
        check(True, label)
        return
    raise AssertionError('Expected rejection: ' + label)


def main():
    check(admin.call('getVersion') == 'v1.2.55', 'fixed version')
    suffix = uuid.uuid4().hex[:10]
    project = admin.call('createProject', name='AgentMemory integration ' + suffix)
    category = admin.call('createCategory', project_id=project, name='機能開発', color_id='blue')
    task = admin.call('createTask', project_id=project, title='調査と実装', description='Standard **description**', category_id=category, color_id='blue', priority=2, owner_id=1, creator_id=1, reference='REF-42', score=3, time_estimated=8, time_spent=2, date_due='2026-11-30', date_started='2026-10-24', tags=['生成AI', 'バックエンド'])
    check(isinstance(task, int) and task > 0, 'standard create')
    check(admin.call('searchTasks', project_id=project, query='調査')[0]['id'] == task, 'standard search')
    check(admin.call('updateTask', id=task, title='調査と実装・検証'), 'standard update')
    check(admin.call('getTask', task_id=task)['title'] == '調査と実装・検証', 'standard get')
    base = dict(task_id=task, kind='work_log', data={'title': '調査完了', 'body': '公式の拡張機構で実装できることを確認。'}, request_id='log1', agent='session-A')
    entry = admin.call('appendAgentEntry', **base)
    check(entry['recorder_name'] == 'admin' and entry['actor_type'] == 'ai', 'server attribution')
    check(admin.call('appendAgentEntry', **base) == entry, 'HTTP retry')
    denied(lambda: admin.call('appendAgentEntry', **{**base, 'data': {'body': 'changed'}}), 'conflicting key')
    with concurrent.futures.ThreadPoolExecutor(max_workers=8) as pool:
        duplicates = list(pool.map(lambda _: admin.call('appendAgentEntry', **{**base, 'request_id': 'concurrent'}), range(8)))
    check(len({e['id'] for e in duplicates}) == 1, 'concurrent duplicate prevention')
    with concurrent.futures.ThreadPoolExecutor(max_workers=8) as pool:
        unique = list(pool.map(lambda i: admin.call('appendAgentEntry', **{**base, 'request_id': 'parallel' + str(i)}), range(16)))
    check(len({e['id'] for e in unique}) == 16, 'concurrent writes retained')
    ctx = {'status': '実装済み', 'confirmed': '本体変更なし', 'unresolved': 'UIレビュー', 'next_actions': '回帰テスト', 'constraints': '公開は承認後'}
    admin.call('updateAgentContext', task_id=task, context=ctx, expected_revision=0, request_id='ctx1')
    check(admin.call('getAgentContext', task_id=task)['context'] == ctx, 'full context')
    def race(i):
        try:
            return admin.call('updateAgentContext', task_id=task, context={'status': str(i)}, expected_revision=1, request_id='race' + str(i))
        except RuntimeError:
            return None
    with concurrent.futures.ThreadPoolExecutor(max_workers=4) as pool:
        races = list(pool.map(race, range(4)))
    check(sum(r is not None for r in races) == 1, 'one context race winner')
    check(admin.call('getAgentContext', task_id=task)['revision'] == 2, 'race revision')
    check(len(admin.call('getAgentEntries', task_id=task, kind='context')['entries']) == 2, 'context history intact')
    decision = admin.call('appendAgentEntry', task_id=task, kind='decision', data={'decision': 'SQLiteを採用', 'judge': 'ユーザー', 'reason': 'Dockerの標準構成', 'options': 'SQLite / MySQL', 'tradeoffs': '複数DBの対応を後回し', 'failure_conditions': '同時書込みに欠落がある場合'}, request_id='decision')
    edited_decision = admin.call('updateAgentEntry', task_id=task, entry_id=decision['id'], data={'decision': 'SQLiteを採用', 'judge': 'ユーザー', 'reason': '採用理由を補足'}, expected_revision=1, request_id='correct')
    check(edited_decision['id'] == decision['id'] and len(admin.call('getAgentMessages', task_id=task, kind='decision')['entries']) == 1, 'decision edited without extra message')
    check(admin.call('getAgentEntryChanges', task_id=task, entry_id=decision['id'])['changes'][0]['before']['data']['decision'] == 'SQLiteを採用', 'decision before snapshot retained')
    denied(lambda: admin.call('appendAgentEntry', task_id=task, kind='decision', data={'decision': 'stale', 'judge': 'user', 'action': 'correction', 'supersedes_id': decision['id']}, request_id='legacy-cas-bypass'), 'legacy correction cannot bypass revision')
    admin.call('appendAgentEntry', task_id=task, kind='history', data={'body': '検証結果: 失敗した試行を訂正。再実行で成功。'}, request_id='detail')
    check(len(admin.call('getAgentEntries', task_id=task, kind='history', query='失敗')['entries']) == 1, 'detail search')
    check(not admin.call('getAgentEntries', task_id=task, query="' OR 1=1 --")['entries'], 'search injection')
    for invalid in [dict(limit=101), dict(after_id='0'), dict(task_id='1'), dict(kind='invalid')]:
        denied(lambda invalid=invalid: admin.call('getAgentEntries', **{**dict(task_id=task), **invalid}), 'invalid query')
    for invalid in [dict(data={'body': ''}), dict(data={'body': 'x', 'unrecognized': 'x'}), dict(data='invalid'), dict(data=[]), dict(actor_type='robot'), dict(request_id='bad key'), dict(kind='context')]:
        denied(lambda invalid=invalid: admin.call('appendAgentEntry', **{**base, 'request_id': uuid.uuid4().hex, **invalid}), 'invalid append')
    ids, cursor = [], 0
    while True:
        page = admin.call('getAgentEntries', task_id=task, after_id=cursor, limit=3)
        ids.extend(e['id'] for e in page['entries'])
        if not page['has_more']:
            break
        cursor = page['next_after_id']
    check(len(ids) == len(set(ids)) and len(ids) == 22, 'paginated complete history')
    # Standard comment, subtask, attachment, status and board APIs remain usable.
    comment = admin.call('createComment', task_id=task, user_id=1, content='Standard comment')
    subtask = admin.call('createSubtask', task_id=task, title='Standard subtask')
    file_id = admin.call('createTaskFile', project_id=project, task_id=task, filename='regression.txt', blob=base64.b64encode(b'attachment').decode())
    check(comment > 0 and len(admin.call('getAllComments', task_id=task)) == 1, 'comments')
    check(subtask > 0 and len(admin.call('getAllSubtasks', task_id=task)) == 1, 'subtasks')
    check(file_id > 0 and admin.call('downloadTaskFile', file_id=file_id) == base64.b64encode(b'attachment').decode(), 'attachments')
    check(admin.call('closeTask', task_id=task) and admin.call('openTask', task_id=task), 'close/reopen')
    check(admin.call('getBoard', project_id=project), 'board')
    # A second user token reads persisted state, and viewer writes are forbidden.
    username = 'viewer' + suffix
    password = uuid.uuid4().hex
    user = admin.call('createUser', username=username, password=password)
    check(admin.call('addProjectUser', project_id=project, user_id=user, role='project-viewer'), 'viewer membership')
    viewer = Client(URL, username, password)
    check(viewer.call('getAgentContext', task_id=task)['revision'] == 2, 'independent user session restore')
    denied(lambda: viewer.call('appendAgentEntry', **{**base, 'request_id': 'viewerwrite'}), 'viewer append forbidden')
    denied(lambda: viewer.call('updateAgentContext', task_id=task, context={'status': 'bad'}, expected_revision=2, request_id='viewercontext'), 'viewer context forbidden')
    denied(lambda: viewer.call('updateAgentEntry', task_id=task, entry_id=decision['id'], data={'decision':'bad','judge':'viewer'}, expected_revision=2, request_id='viewer-edit'), 'viewer edit forbidden')
    denied(lambda: viewer.call('removeAgentEntry', task_id=task, entry_id=decision['id'], expected_revision=2, request_id='viewer-delete'), 'viewer delete forbidden')
    other = admin.call('createProject', name='Private ' + suffix, is_private=1)
    hidden = admin.call('createTask', project_id=other, title='Inaccessible')
    denied(lambda: viewer.call('getAgentContext', task_id=hidden), 'other project forbidden')
    denied(lambda: viewer.call('getAgentEntries', task_id=hidden), 'other history forbidden')
    denied(lambda: admin.call('getAgentContext', task_id=99999999), 'missing task forbidden')
    # Explicit separate process, with no in-memory client state.
    env = {**os.environ, 'KANBOARD_URL': URL, 'KANBOARD_USER': username, 'KANBOARD_TOKEN': password}
    restored = json.loads(subprocess.check_output([sys.executable, str(ROOT / 'examples/client.py'), 'getAgentContext', json.dumps({'task_id': task})], env=env))
    check(restored['revision'] == 2, 'new agent process restores context')
    roles = json.loads(subprocess.check_output(['docker', 'compose', '-p', 'agentmemory-verification', '-f', 'tests/compose.yaml', 'exec', '-T', 'kanboard', 'php', '/tests/setup-roles.php', str(user)], cwd=ROOT))
    token_client = Client(URL, username, roles['viewer_token'])
    check(token_client.call('getAgentContext', task_id=task)['revision'] == 2, 'user API token')
    denied(lambda: Client(URL, 'jsonrpc', roles['app_token']).call('getAgentContext', task_id=task), 'application token rejected')
    denied(lambda: Client(URL, 'invalid-user', 'invalid').call('getAgentEntries', task_id=task), 'authentication rejected')
    # Keep one populated task for UI tests; delete a separate task to verify orphan retention later.
    disposable = admin.call('createTask', project_id=project, title='Deletion check')
    deleted_entry = admin.call('appendAgentEntry', **{**base, 'task_id': disposable, 'request_id': 'delete'})
    check(admin.call('removeTask', task_id=disposable), 'standard task deletion')
    denied(lambda: admin.call('getAgentEntries', task_id=disposable), 'deleted history not exposed')
    reused = admin.call('createTask', project_id=project, title='Reused numeric task ID')
    check(reused == disposable, 'real Kanboard task ID reuse')
    denied(lambda: admin.call('appendAgentEntry', **{**base, 'task_id': reused, 'request_id': 'delete'}), 'deleted retry key reserved')
    check(not admin.call('getAgentEntries', task_id=reused)['entries'], 'no old task record mixed in')
    closed_task = admin.call('createTask', project_id=project, title='Closed UI state')
    check(admin.call('closeTask', task_id=closed_task), 'closed UI fixture')
    # Core subtask changes recalculate the parent totals; restore nonzero values for UI coverage.
    check(admin.call('updateTask', id=task, time_estimated=8, time_spent=2), 'time fields UI fixture')
    output = ROOT / '.runtime/test-fixture.json'
    output.parent.mkdir(exist_ok=True)
    output.write_text(json.dumps({'task_id': task, 'project_id': project, 'viewer': username, 'viewer_password': password, 'deleted_entry': deleted_entry['id'], 'role_task_id': roles['role_task_id'], 'closed_task_id': closed_task}))
    output.chmod(0o600)
    print(f'PASS: {checks} API, concurrency and standard regression checks; UI fixture task #{task}')


if __name__ == '__main__':
    main()
