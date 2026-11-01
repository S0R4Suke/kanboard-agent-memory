#!/usr/bin/env python3
"""Safety boundaries of the project-scoped agent client; no live service needed."""
import sys
from pathlib import Path
import unittest

sys.path.insert(0, str(Path(__file__).resolve().parent.parent/'examples'))
from workspace import Workspace


class API:
    def __init__(self):
        self.url = 'http://127.0.0.1:8088/jsonrpc.php'
        self.calls = []
        self.tasks = {2: {'id': 2, 'project_id': 7, 'title': 'one', 'description': '', 'reference': 'same'},
                      99: {'id': 99, 'project_id': 8}}
        self.name = 'Expected'
        self.metadata = {}
        self.subtasks = []
        self.fail_readback = False
        self.written = False

    def call(self, method, **params):
        self.calls.append((method, params))
        if method == 'getProjectById':
            return {'name': self.name, 'is_active': 1}
        if method == 'getTask':
            if self.fail_readback and self.written:
                raise RuntimeError('Connection failed after saving')
            return self.tasks.get(params['task_id'])
        if method == 'getTaskByReference':
            return next((t for t in self.tasks.values() if t.get('reference') == params['reference']), False)
        if method == 'getTaskMetadata':
            return self.metadata.get(params['task_id'], {})
        if method == 'saveTaskMetadata':
            self.metadata.setdefault(params['task_id'], {}).update(params['values'])
            return True
        if method == 'getColumns':
            return [{'id': 3}]
        if method == 'getAllSwimlanes':
            return [{'id': 4, 'is_active': 1}, {'id': 6, 'is_active': 0}]
        if method == 'getAllCategories':
            return [{'id': 5}]
        if method == 'getAllSubtasks':
            return self.subtasks
        if method == 'createTask':
            self.written = True
            self.tasks[3] = dict(params, id=3)
            return 3
        if method in {'updateTask', 'moveTaskPosition', 'closeTask', 'openTask'}:
            self.written = True
            return True
        if method == 'appendAgentEntry':
            self.written = True
            return {'id': 11}
        if method == 'getAgentContext':
            return {'revision': 0, 'context': {}}
        if method in {'getAgentEntries', 'getAgentMessages'}:
            return {'entries': [], 'has_more': False, 'next_after_id': 0}
        if method == 'searchTasks':
            return []
        raise AssertionError(method)


class WorkspaceSafety(unittest.TestCase):
    def setUp(self):
        self.api = API()
        self.workspace = Workspace(self.api, 7, 'Expected')

    def refused(self, method, params):
        with self.assertRaises(ValueError):
            self.workspace.execute(method, params)
        self.assertFalse(self.api.written)

    def test_project_identity_changed(self):
        self.api.name = 'Other'
        self.refused('openTask', {'task_id': 2})

    def test_foreign_task_all_supported_write_forms(self):
        for method in ['updateTask', 'openTask', 'closeTask', 'moveTaskPosition', 'appendAgentEntry', 'updateAgentContext', 'updateAgentEntry', 'removeAgentEntry']:
            self.refused(method, {'id' if method == 'updateTask' else 'task_id': 99})

    def test_foreign_project_cannot_be_injected(self):
        self.refused('searchTasks', {'project_id': 8, 'query': ''})
        self.refused('moveTaskPosition', {'project_id': 8, 'task_id': 2})

    def test_foreign_column_lane_and_category(self):
        for key in ['column_id', 'swimlane_id', 'category_id']:
            self.refused('updateTask', {'id': 2, key: 100})

    def test_inactive_lane(self):
        self.refused('updateTask', {'id': 2, 'swimlane_id': 6})

    def test_unscoped_and_destructive_methods_rejected(self):
        for method in ['createProject', 'removeProject', 'removeTask', 'moveTaskToProject', 'getAllProjects']:
            self.refused(method, {})

    def test_boolean_and_string_ids_rejected(self):
        for task_id in [True, '2', 0, -1]:
            self.refused('openTask', {'task_id': task_id})

    def test_reference_required_for_creation(self):
        self.refused('createTask', {'title': 'New'})

    def test_creation_retry_reuses_same_task(self):
        params = {'title': 'New', 'description': 'Body', 'reference': 'stable-new', 'column_id': 3, 'swimlane_id': 4}
        first = self.workspace.execute('createTask', params)
        second = self.workspace.execute('createTask', params)
        self.assertEqual(first['result'], second['result'])
        self.assertTrue(second['reused'])
        self.assertEqual(sum(m == 'createTask' for m, _ in self.api.calls), 1)

    def test_reference_collision_is_not_adopted(self):
        self.refused('createTask', {'title': 'Different work', 'reference': 'same'})

    def test_retried_creation_with_changed_attributes_is_rejected(self):
        params = {'title': 'New', 'description': 'Body', 'reference': 'new-attrs', 'column_id': 3}
        self.workspace.execute('createTask', params)
        self.api.written = False
        for key, value in [('color_id', 'red'), ('owner_id', 45), ('priority', 7)]:
            self.refused('createTask', {**params, key: value})
        self.assertEqual(sum(m == 'createTask' for m, _ in self.api.calls), 1)

    def test_creation_recovery_does_not_adopt_foreign_attributes(self):
        self.api.tasks[2]['color_id'] = 'blue'
        self.refused('createTask', {'title': 'one', 'description': '', 'reference': 'same', 'color_id': 'red'})

    def test_numeric_looking_text_is_not_normalized_during_recovery(self):
        for key in ['title', 'description']:
            self.api.tasks[2][key] = '001'
            params = {'title': self.api.tasks[2]['title'], 'description': self.api.tasks[2]['description'], 'reference': 'same'}
            params[key] = '1'
            self.refused('createTask', params)

    def test_unfinished_subtasks_prevent_implicit_closure(self):
        self.api.subtasks = [{'status': 0}]
        self.refused('closeTask', {'task_id': 2})

    def test_write_readback_failure_is_not_reported_as_success(self):
        self.api.fail_readback = True
        with self.assertRaisesRegex(RuntimeError, 'readback failed'):
            self.workspace.execute('openTask', {'task_id': 2})
        self.assertTrue(self.api.written)

    def test_resume_contains_memory_and_paging_state(self):
        result = self.workspace.execute('resume', {'task_id': 2})
        self.assertEqual(result['task']['id'], 2)
        self.assertEqual(result['context']['revision'], 0)
        self.assertEqual(result['canonical_task_url'], 'http://127.0.0.1:8088/?controller=TaskViewController&action=show&task_id=2')
        self.assertFalse(result['history']['has_more'])
        self.assertFalse(self.api.written)

    def test_search_is_bound_to_configured_project(self):
        self.workspace.execute('searchTasks', {'query': 'status:all'})
        self.assertEqual(self.api.calls[-1][1]['project_id'], 7)

    def test_canonical_url_preserves_installation_subdirectory(self):
        self.api.url = 'https://example.test/kanboard/jsonrpc.php'
        result = self.workspace.execute('resume', {'task_id': 2})
        self.assertTrue(result['canonical_task_url'].startswith('https://example.test/kanboard/?'))


if __name__ == '__main__':
    unittest.main()
