#!/usr/bin/env python3
"""HTTP form integration tests against the isolated 8091 stack; no extra dependencies."""
from html.parser import HTMLParser
from http.cookiejar import CookieJar
import json
from pathlib import Path
import sys
from urllib.error import HTTPError
from urllib.parse import urlencode, urljoin
from urllib.request import build_opener, HTTPCookieProcessor
import uuid

ROOT = Path(__file__).resolve().parent.parent
sys.path.insert(0, str(ROOT / 'examples'))
from client import Client

ORIGIN = 'http://127.0.0.1:8091'
api = Client(ORIGIN + '/jsonrpc.php', 'admin', 'admin')
checks = 0


def check(condition, label):
    global checks
    if not condition:
        raise AssertionError(label)
    checks += 1


class Forms(HTMLParser):
    def __init__(self, html):
        super().__init__(convert_charrefs=True)
        self.forms, self.form, self.textarea = [], None, None
        self.feed(html)

    def handle_starttag(self, tag, attrs):
        attrs = dict(attrs)
        if tag == 'form':
            self.form = {'action': attrs.get('action', ''), 'values': {}}
            self.forms.append(self.form)
        if self.form is not None and attrs.get('name') and tag in ('input', 'textarea'):
            self.form['values'][attrs['name']] = attrs.get('value', '')
            if tag == 'textarea':
                self.textarea = attrs['name']

    def handle_data(self, data):
        if self.form is not None and self.textarea:
            self.form['values'][self.textarea] += data

    def handle_endtag(self, tag):
        if tag == 'textarea':
            self.textarea = None
        if tag == 'form':
            self.form = None


class Session:
    def __init__(self, username='admin', password='admin'):
        self.opener = build_opener(HTTPCookieProcessor(CookieJar()))
        _, html = self.get('/')
        form = Forms(html).forms[0]
        self.post(form, username=username, password=password)

    def get(self, url):
        try:
            with self.opener.open(urljoin(ORIGIN, url), timeout=20) as response:
                return response.status, response.read().decode()
        except HTTPError as error:
            return error.code, error.read().decode()

    def post(self, form, **values):
        body = {**form['values'], **values}
        try:
            with self.opener.open(urljoin(ORIGIN, form['action']), urlencode(body).encode(), timeout=20) as response:
                return response.status, response.read().decode()
        except HTTPError as error:
            return error.code, error.read().decode()


def route(task, action='changes', **params):
    return '/?' + urlencode(dict(controller='MemoryController', plugin='AgentMemory', action=action, task_id=task, **params))


def main():
    suffix = uuid.uuid4().hex[:10]
    project = api.call('createProject', name='Manual memory ' + suffix)
    task = api.call('createTask', project_id=project, title='Manual timestamp and audit')
    other = api.call('createTask', project_id=project, title='Other task')
    session = Session()
    task_url = '/?' + urlencode(dict(controller='TaskViewController', action='show', task_id=task))

    def decision_form(entry=None):
        _, html = session.get(route(task, 'edit', entry_id=entry) if entry else task_url)
        return next(form for form in Forms(html).forms if form['values'].get('kind') == 'decision')

    def message():
        return api.call('getAgentMessages', task_id=task, kind='decision')['entries'][0]

    # The form timezone is explicit, independent of the browser's timezone.
    form = decision_form()
    check('decided_at_local' in form['values'] and bool(form['values']['decided_at_timezone']), 'manual timestamp and timezone fields exist')
    status, _ = session.post(form, decision='日時入力', judge='Human', decided_at_local='2026-10-30T14:15:37', decided_at_timezone='Asia/Tokyo')
    entry = message()
    check(status == 200 and entry['data']['decided_at'] == '2026-10-30T05:15:37Z', 'manual local timestamp saved as UTC with seconds')
    entry_id = entry['id']
    form = decision_form(entry_id)
    check(bool(form['values']['decided_at_local']), 'existing API timestamp prefilled')
    status, _ = session.post(form, reason='時刻を変えず理由を補足')
    check(status == 200 and message()['data']['decided_at'] == entry['data']['decided_at'], 'unchanged timestamp survives edit round trip')
    # Bad dates, timezone names and DST gaps reject the write and retain text/request keys.
    for value, zone in [('2026-02-30T10:00', 'UTC'), ('2026-10-30T10:00', 'invalid/zone'), ('2026-03-08T02:30', 'America/New_York')]:
        form = decision_form(entry_id)
        before = message()
        status, html = session.post(form, decision='入力保持', decided_at_local=value, decided_at_timezone=zone)
        retained = next(f for f in Forms(html).forms if f['values'].get('kind') == 'decision')
        check(status == 422 and retained['values']['decided_at_local'] == value and retained['values']['decision'] == '入力保持', 'invalid datetime retained: ' + value + '/' + zone)
        check(retained['values']['request_id'] == form['values']['request_id'] and message() == before, 'invalid datetime does not write or lose retry key')
    form = decision_form(entry_id)
    status, _ = session.post(form, decided_at_local='2026-10-31T09:00', decided_at_timezone='Asia/Tokyo')
    check(status == 200 and message()['data']['decided_at'] == '2026-10-31T00:00:00Z' and message()['id'] == entry_id, 'timestamp can be corrected without a duplicate message')
    status, _ = session.post(decision_form(entry_id), decided_at_local='')
    check(status == 200 and 'decided_at' not in message()['data'], 'optional timestamp can be cleared')
    # API-provided second occurrence of a DST overlap retains its exact UTC instant.
    current = message()
    api.call('updateAgentEntry', task_id=task, entry_id=entry_id, data={**current['data'], 'decided_at': '2026-11-01T06:30:45Z'}, expected_revision=current['revision'], request_id='dst-' + suffix)
    form = decision_form(entry_id)
    status, _ = session.post(form, decided_at_local='2026-11-01T01:30:45', decided_at_timezone='America/New_York')
    check(status == 200 and message()['data']['decided_at'] == '2026-11-01T06:30:45Z', 'unchanged DST overlap timestamp retains exact instant')
    form = decision_form(entry_id)
    status, html = session.post(form, decided_at_local='2026-11-01T01:30:46', decided_at_timezone='America/New_York')
    check(status == 422 and 'UTCオフセット' in html and message()['data']['decided_at'] == '2026-11-01T06:30:45Z', 'changing an overlapping time requires an explicit offset')
    form = next(f for f in Forms(html).forms if f['values'].get('kind') == 'decision')
    status, _ = session.post(form, decided_at_choice='2026-11-01T06:30:46Z')
    check(status == 200 and message()['data']['decided_at'] == '2026-11-01T06:30:46Z', 'explicit overlap choice preserves the intended offset')
    _, html = session.get(route(task, entry_id=entry_id))
    check('2026-11-01T06:30:45Z' in html and '2026-11-01T06:30:46Z' in html, 'audit distinguishes seconds and offsets using exact UTC timestamps')
    # An already-open old form must preserve the timestamp rather than erase it.
    form = decision_form(entry_id)
    form['values'].pop('decided_at_local')
    form['values'].pop('decided_at_timezone')
    session.post(form, reason='旧フォームからの更新')
    check(message()['data']['decided_at'] == '2026-11-01T06:30:46Z', 'old form retains stored decision timestamp')
    # A failed CSRF token retains date and other text, but cannot write.
    form = decision_form(entry_id)
    before = message()
    status, html = session.post(form, csrf_token='0' * 64, decided_at_local='2026-10-30T12:00')
    check(status == 403 and '2026-10-30T12:00' in html and message() == before, 'CSRF failure retains input without a write')
    # Audit rendering escapes both the before and after payloads.
    current = message()
    api.call('updateAgentEntry', task_id=task, entry_id=entry_id, data={'decision': '<script>alert(1)</script>', 'judge': '<b>Human</b>'}, expected_revision=current['revision'], request_id='xss-' + suffix)
    status, html = session.get(route(task, entry_id=entry_id))
    check(status == 200 and '日時入力' in html and '&lt;script&gt;alert(1)&lt;/script&gt;' in html and '<script>alert(1)</script>' not in html, 'audit shows before and after content with XSS escaping')
    status, html = session.get(route(other, entry_id=entry_id))
    check('alert alert-error' in html and 'am-change-versions' not in html and '日時入力' not in html, 'cross-task message lookup uses the native not-found page without leaking history')
    deleted = message()
    api.call('removeAgentEntry', task_id=task, entry_id=entry_id, expected_revision=deleted['revision'], request_id='delete-' + suffix)
    status, html = session.get(route(task))
    check(status == 200 and '削除済み' in html and '&lt;script&gt;' in html, 'task audit retains a route to deleted messages')
    # Enough edits to exercise both task-wide and per-message cursor pages.
    log = api.call('appendAgentEntry', task_id=task, kind='work_log', data={'body': 'Original log'}, request_id='log-' + suffix)
    log = api.call('getAgentMessages', task_id=task, kind='work_log')['entries'][0]
    for index in range(23):
        log = api.call('updateAgentEntry', task_id=task, entry_id=log['id'], data={'body': 'Revision ' + str(index)}, expected_revision=log['revision'], request_id='edit-' + suffix + '-' + str(index))
    for params in [{}, {'entry_id': log['id']}]:
        status, html = session.get(route(task, **params))
        parser = Links(html)
        seen = parser.ids
        check(status == 200 and len(seen) == 20 and parser.next_url, 'audit first page contains 20 changes')
        _, html = session.get(parser.next_url)
        second = Links(html)
        check(bool(second.ids) and not set(seen) & set(second.ids), 'audit next page has no repeated change IDs')
    # Viewer access matches native task reading; unrelated users cannot read the audit.
    username, password = 'audit-' + suffix, uuid.uuid4().hex
    user = api.call('createUser', username=username, password=password)
    api.call('addProjectUser', project_id=project, user_id=user, role='project-viewer')
    viewer = Session(username, password)
    status, html = viewer.get(task_url)
    check(status == 200 and '変更履歴（編集・削除）' in html and 'class="am-form"' not in html, 'viewer can discover audit without write controls')
    check(viewer.get(route(task))[0] == 200 and viewer.get(route(task, entry_id=entry_id))[0] == 200, 'viewer can read task and deleted-message audits')
    check(viewer.get(route(task, 'edit', entry_id=log['id']))[0] == 403, 'viewer still cannot edit')
    private = api.call('createProject', name='Private audit ' + suffix, is_private=1)
    private_task = api.call('createTask', project_id=private, title='Private')
    check(viewer.get(route(private_task))[0] == 403, 'unrelated project audit forbidden')
    anonymous = Session.__new__(Session)
    anonymous.opener = build_opener(HTTPCookieProcessor(CookieJar()))
    _, html = anonymous.get(route(task))
    check('am-change-versions' not in html and 'name="password"' in html, 'anonymous request cannot read audit')
    print(f'PASS: {checks} manual timestamp, audit rendering, pagination and authorization checks')


class Links(HTMLParser):
    def __init__(self, html):
        super().__init__()
        self.ids, self.next_url = [], None
        self.feed(html)

    def handle_starttag(self, tag, attrs):
        attrs = dict(attrs)
        if tag == 'article' and attrs.get('id', '').startswith('am-change-'):
            self.ids.append(int(attrs['id'][10:]))
        if tag == 'a' and 'after_id=' in attrs.get('href', ''):
            self.next_url = attrs['href']


if __name__ == '__main__':
    main()
