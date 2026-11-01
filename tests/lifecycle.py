#!/usr/bin/env python3
"""Disable/reinstall/package and retention checks, isolated test stack only."""
import json
import re
from pathlib import Path
import subprocess
import sys
import time
import urllib.request
from zipfile import ZipFile

ROOT = Path(__file__).resolve().parent.parent
sys.path.insert(0, str(ROOT / 'examples'))
from client import Client

fixture = json.loads((ROOT / '.runtime/test-fixture.json').read_text())
client = Client('http://127.0.0.1:8091/jsonrpc.php', 'admin', 'admin')
command = ['docker', 'compose', '-p', 'agentmemory-verification', '-f', 'tests/compose.yaml']


def compose(override=None):
    # Renew only anonymous test volumes so removed plugin mounts leave no stale
    # directories in the image's plugins/ volume. Named data is retained.
    args = command + (['-f', override] if override else []) + ['up', '-d', '--force-recreate', '--renew-anon-volumes']
    subprocess.run(args, cwd=ROOT, check=True, stdout=subprocess.DEVNULL)
    for _ in range(30):
        try:
            if client.call('getVersion') == 'v1.2.55':
                return
        except (RuntimeError, OSError):
            time.sleep(1)
    raise RuntimeError('Test stack did not recover')


def sql_check():
    php = '''require "/var/www/app/app/common.php";
    $pdo=$container['db']->getConnection();
    $id=(int)$argv[1];
    $row=$pdo->query("SELECT task_id, original_task_id FROM agent_memory_entries WHERE id=".$id)->fetch(PDO::FETCH_ASSOC);
    if (!$row || $row['task_id'] !== null) {throw new RuntimeException('Deleted task retention failed');}
    if ((int)$pdo->query("SELECT version FROM plugin_schema_versions WHERE plugin='agentmemory'")->fetchColumn() !== 3) {throw new RuntimeException('Migration failed');}
    echo "retained and migrated";'''
    subprocess.run(command + ['exec', '-T', 'kanboard', 'php', '-r', php, str(fixture['deleted_entry'])], cwd=ROOT, check=True)


def main():
    before = client.call('getAgentEntries', task_id=fixture['task_id'], limit=100)
    context = client.call('getAgentContext', task_id=fixture['task_id'])
    current = client.call('getAgentMessages', task_id=fixture['task_id'], limit=100)
    message_ids = [e['id'] for e in before['entries'] if e['kind'] in ('work_log', 'decision')]
    audits = {entry_id: client.call('getAgentEntryChanges', task_id=fixture['task_id'], entry_id=entry_id, limit=100) for entry_id in message_ids}

    def assert_messages():
        assert client.call('getAgentMessages', task_id=fixture['task_id'], limit=100) == current
        for entry_id, audit in audits.items():
            assert client.call('getAgentEntryChanges', task_id=fixture['task_id'], entry_id=entry_id, limit=100) == audit
    sql_check()
    try:
        compose('tests/disabled.yaml')
        assert client.call('getTask', task_id=fixture['task_id'])
        assert client.call('getAllComments', task_id=fixture['task_id'])
        try:
            client.call('getAgentContext', task_id=fixture['task_id'])
        except RuntimeError as error:
            assert '-32601' in str(error)
        else:
            raise AssertionError('Plugin still loaded')
        compose()
        assert client.call('getAgentEntries', task_id=fixture['task_id'], limit=100) == before
        assert client.call('getAgentContext', task_id=fixture['task_id']) == context
        assert_messages()
        exporter = ROOT / 'scripts/export-public.py'
        subprocess.run([sys.executable, str(exporter) if exporter.exists() else 'scripts/package.py'], cwd=ROOT, check=True)
        version = re.search(r"function getPluginVersion\(\).*?return '([^']+)';", (ROOT / 'AgentMemory/Plugin.php').read_text(), re.S).group(1)
        package = ROOT / ('dist/public' if exporter.exists() else 'dist') / f'AgentMemory-{version}.zip'
        with ZipFile(package) as archive:
            assert not any('.runtime' in name or 'local-login' in name for name in archive.namelist())
            assert not any(part in name.split('/') for name in archive.namelist() for part in ('TaskDetailUI', 'KanboardTheme', 'RichEditor'))
            archive.extractall(ROOT / '.runtime/package')
        compose('tests/packaged.yaml')
        assert_messages()
        assert client.call('getAgentEntries', task_id=fixture['task_id'], limit=100) == before
        sql_check()
        fresh_command = ['docker', 'compose', '-p', 'agentmemory-package-test', '-f', 'tests/fresh-package.yaml']
        try:
            subprocess.run(fresh_command + ['up', '-d'], cwd=ROOT, check=True, stdout=subprocess.DEVNULL)
            fresh = Client('http://127.0.0.1:8092/jsonrpc.php', 'admin', 'admin')
            for _ in range(30):
                try:
                    fresh.call('getVersion')
                    break
                except (RuntimeError, OSError):
                    time.sleep(1)
            project = fresh.call('createProject', name='Packaged fresh install')
            task = fresh.call('createTask', project_id=project, title='Fresh')
            result = fresh.call('appendAgentEntry', task_id=task, kind='history', data={'body':'fresh package'}, request_id='fresh')
            assert fresh.call('getAgentEntries', task_id=task)['entries'][0]['id'] == result['id']
            with urllib.request.urlopen('http://127.0.0.1:8092/plugins/AgentMemory/Assets/memory.css') as response:
                assert b'.am-layout' not in response.read()
            import manual
            previous_origin = manual.ORIGIN
            try:
                manual.ORIGIN = 'http://127.0.0.1:8092'
                session = manual.Session()
                status, html = session.get('/?controller=TaskViewController&action=show&task_id='+str(task))
                assert status == 200 and 'id="task-summary"' in html and 'id="am-work_log"' in html
                assert 'am-cards-template' not in html and 'kre-editor' not in html
            finally:
                manual.ORIGIN = previous_origin
        finally:
            subprocess.run(fresh_command + ['down', '-v'], cwd=ROOT, check=True, stdout=subprocess.DEVNULL)
        print('PASS: disabled core API, reinstallation, deletion retention, current messages and audits, migration, packaged reinstall and fresh install')
    finally:
        compose()


if __name__ == '__main__':
    main()
