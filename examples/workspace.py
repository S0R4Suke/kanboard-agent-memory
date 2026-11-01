#!/usr/bin/env python3
"""Project-scoped JSON-RPC entry point; credentials stay in a private local file."""
import argparse
from contextlib import contextmanager
from decimal import Decimal, InvalidOperation
import hashlib
import json
import os
from pathlib import Path
import sys
from urllib.parse import urljoin

from client import Client

PROJECT_METHODS = {'getColumns', 'getAllSwimlanes', 'getAllTasks', 'searchTasks', 'createTask'}
TASK_METHODS = {
    'getTask', 'getAgentContext', 'getAgentMessages', 'getAgentEntries', 'getAgentEntryChanges',
    'appendAgentEntry', 'updateAgentContext', 'updateAgentEntry', 'removeAgentEntry',
    'updateTask', 'moveTaskPosition', 'openTask', 'closeTask',
}
WRITES = {'createTask', 'updateTask', 'moveTaskPosition', 'openTask', 'closeTask',
          'appendAgentEntry', 'updateAgentContext', 'updateAgentEntry', 'removeAgentEntry'}
CREATE_HASH = 'agent_workspace_create_sha256'
NUMERIC_ATTRIBUTES = {'project_id', 'column_id', 'swimlane_id', 'owner_id', 'creator_id',
                      'category_id', 'priority', 'score', 'time_estimated', 'time_spent',
                      'recurrence_status', 'recurrence_trigger', 'recurrence_factor',
                      'recurrence_timeframe', 'recurrence_basedate'}


def comparable(value):
    # Native JSON-RPC may return numeric attributes as strings.
    if isinstance(value, (int, float, str)) and not isinstance(value, bool):
        try:
            number = Decimal(str(value))
            if number.is_finite():
                return number
        except InvalidOperation:
            pass
    return value


def positive_id(value):
    if type(value) is not int or value <= 0:
        raise ValueError('IDs must be positive JSON integers')
    return value


class Workspace:
    def __init__(self, api, project_id, project_name):
        self.api = api
        self.project_id = positive_id(project_id)
        if not project_name:
            raise ValueError('Expected project name is required')
        self.project_name = project_name

    def validate_project(self):
        project = self.api.call('getProjectById', project_id=self.project_id)
        if not project or project['name'] != self.project_name or not int(project['is_active']):
            raise ValueError('Configured project identity changed; stop and verify the destination')

    def task(self, task_id):
        task = self.api.call('getTask', task_id=positive_id(task_id))
        if not task or int(task['project_id']) != self.project_id:
            raise ValueError('Task does not belong to the configured project')
        return task

    def task_url(self, task_id):
        return urljoin(self.api.url, f'./?controller=TaskViewController&action=show&task_id={task_id}')

    def validate_location(self, params):
        if params.get('column_id'):
            column = positive_id(params['column_id'])
            if column not in {int(c['id']) for c in self.api.call('getColumns', project_id=self.project_id)}:
                raise ValueError('Column belongs to another project')
        if params.get('swimlane_id'):
            lane = positive_id(params['swimlane_id'])
            if lane not in {int(s['id']) for s in self.api.call('getAllSwimlanes', project_id=self.project_id) if int(s['is_active'])}:
                raise ValueError('Swimlane is not active in this project')
        if params.get('category_id'):
            category = positive_id(params['category_id'])
            if category not in {int(c['id']) for c in self.api.call('getAllCategories', project_id=self.project_id)}:
                raise ValueError('Category belongs to another project')

    def execute(self, method, params):
        if not isinstance(params, dict):
            raise ValueError('Parameters must be a JSON object')
        if method not in PROJECT_METHODS | TASK_METHODS | {'resume'}:
            raise ValueError('Method is not supported by the scoped client')
        self.validate_project()
        params = dict(params)
        if 'project_id' in params and positive_id(params['project_id']) != self.project_id:
            raise ValueError('Cross-project operation refused')
        if method == 'resume':
            if set(params) != {'task_id'}:
                raise ValueError('resume requires only task_id')
            task = self.task(params['task_id'])
            task_id = int(task['id'])
            return {'task': task, 'canonical_task_url': self.task_url(task_id),
                    'context': self.api.call('getAgentContext', task_id=task_id),
                    'messages': self.api.call('getAgentMessages', task_id=task_id, limit=100),
                    'history': self.api.call('getAgentEntries', task_id=task_id, kind='history', limit=100),
                    'columns': self.api.call('getColumns', project_id=self.project_id),
                    'swimlanes': self.api.call('getAllSwimlanes', project_id=self.project_id)}
        if method in PROJECT_METHODS or method == 'moveTaskPosition':
            params['project_id'] = self.project_id
        task_id = None
        if method in TASK_METHODS:
            task_id = positive_id(params['id' if method == 'updateTask' else 'task_id'])
            self.task(task_id)
        if method in {'createTask', 'updateTask', 'moveTaskPosition'}:
            self.validate_location(params)
        if method == 'createTask':
            reference = params.get('reference')
            if not isinstance(reference, str) or not reference.strip():
                raise ValueError('createTask requires a stable unique reference; reuse it after a timeout')
            creation_hash = hashlib.sha256(json.dumps(params, sort_keys=True, ensure_ascii=False, allow_nan=False).encode()).hexdigest()
            existing = self.api.call('getTaskByReference', project_id=self.project_id, reference=reference)
            if existing:
                self.task(int(existing['id']))
                previous_hash = self.api.call('getTaskMetadata', task_id=int(existing['id'])).get(CREATE_HASH)
                if previous_hash and previous_hash != creation_hash:
                    raise ValueError('Reference already exists with a different creation request; inspect it')
                if not previous_hash:
                    # Recover a create that succeeded before its fingerprint could be saved.
                    # If native date/tag representations cannot be proved equal, fail closed.
                    for key in set(params) | {'title', 'description'}:
                        left, right = existing.get(key, ''), params.get(key, '')
                        if key in NUMERIC_ATTRIBUTES:
                            left, right = comparable(left), comparable(right)
                        if left != right:
                            raise ValueError('Reference already exists with different or unverified attributes; inspect it')
                    if not self.api.call('saveTaskMetadata', task_id=int(existing['id']), values={CREATE_HASH: creation_hash}):
                        raise RuntimeError('Could not verify the recovered creation fingerprint')
                return {'result': int(existing['id']), 'reused': True,
                        'canonical_task_url': self.task_url(existing['id']), 'verified_task': self.task(int(existing['id']))}
        if method == 'closeTask':
            subtasks = self.api.call('getAllSubtasks', task_id=task_id)
            if any(int(s['status']) != 2 for s in subtasks):
                raise ValueError('Finish or explicitly review unfinished subtasks before closing')
        try:
            result = self.api.call(method, **params)
        except (RuntimeError, OSError):
            if method in WRITES:
                raise RuntimeError('Write result is uncertain. Read back first; reuse the same reference/request_id and payload. Do not claim it was saved.') from None
            raise
        if method not in WRITES:
            return result
        if result is False or result is None:
            raise RuntimeError('Write was refused; no success reported')
        try:
            if method == 'createTask':
                task_id = int(result)
                if not self.api.call('saveTaskMetadata', task_id=task_id, values={CREATE_HASH: creation_hash}):
                    raise RuntimeError('Creation fingerprint was not saved')
            verified = self.task(task_id)
            response = {'result': result, 'canonical_task_url': self.task_url(task_id), 'verified_task': verified}
            if method == 'updateAgentContext':
                response['verified_context'] = self.api.call('getAgentContext', task_id=task_id)
            elif method == 'appendAgentEntry':
                reader = 'getAgentEntries' if params['kind'] == 'history' else 'getAgentMessages'
                response['verified_entry'] = self.api.call(reader, task_id=task_id, after_id=int(result['id'])-1, limit=1)
            elif method in {'updateAgentEntry', 'removeAgentEntry'}:
                response['verified_changes'] = self.api.call('getAgentEntryChanges', task_id=task_id, entry_id=params['entry_id'], limit=100)
            return response
        except (RuntimeError, OSError, ValueError, KeyError):
            raise RuntimeError('Write returned success but readback failed. Preserve the request key, resume/read back, and report verification as incomplete.') from None


def load_workspace(path):
    config = json.loads(path.read_text())
    credential_path = Path(config['credentials_file']).expanduser()
    if not credential_path.is_absolute():
        credential_path = path.parent / credential_path
    if os.name == 'posix' and credential_path.stat().st_mode & 0o077:
        raise ValueError('Credential file must be private (chmod 600)')
    credentials = json.loads(credential_path.read_text())
    api = Client(config['url'], credentials['username'], credentials['api_token'])
    return Workspace(api, config['project_id'], config['project_name'])


@contextmanager
def creation_lock(config_path):
    # Serializes reference lookup + creation for agents using this local config.
    # Core JSON-RPC does not enforce reference uniqueness across other clients/hosts.
    try:
        import fcntl
    except ImportError:
        raise ValueError('The local workspace helper requires POSIX file locking') from None
    fd = os.open(config_path.with_suffix('.lock'), os.O_WRONLY | os.O_CREAT, 0o600)
    with os.fdopen(fd, 'w') as lock:
        fcntl.flock(lock, fcntl.LOCK_EX)
        yield


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--config', type=Path, default=Path(__file__).resolve().parent.parent/'.runtime/workspace.json')
    parser.add_argument('method')
    parser.add_argument('params', nargs='?', default='{}', help='JSON object or @file; write keys must survive retries')
    args = parser.parse_args()
    raw = Path(args.params[1:]).read_text() if args.params.startswith('@') else args.params
    config_path = args.config.resolve()
    workspace = load_workspace(config_path)
    params = json.loads(raw)
    if args.method == 'createTask':
        with creation_lock(config_path):
            result = workspace.execute(args.method, params)
    else:
        result = workspace.execute(args.method, params)
    print(json.dumps(result, ensure_ascii=False, indent=2))


if __name__ == '__main__':
    try:
        main()
    except (RuntimeError, ValueError, KeyError, OSError) as error:
        print(str(error), file=sys.stderr)
        sys.exit(1)
