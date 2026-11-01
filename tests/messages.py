#!/usr/bin/env python3
"""Atomic stable-message edits/deletions, retries, authorization and pagination."""
import concurrent.futures
import json
from pathlib import Path
import sys
import uuid
ROOT=Path(__file__).resolve().parent.parent
sys.path.insert(0,str(ROOT/'examples'))
from client import Client
api=Client('http://127.0.0.1:8091/jsonrpc.php','admin','admin')
f=json.loads((ROOT/'.runtime/test-fixture.json').read_text())
checks=0

def check(value,label):
    global checks
    if not value: raise AssertionError(label)
    checks+=1

def rejects(callback,label):
    try: callback()
    except RuntimeError: check(True,label); return
    raise AssertionError(label)

def main():
    task=api.call('createTask',project_id=f['project_id'],title='Message mutations '+uuid.uuid4().hex[:8])
    original=api.call('appendAgentEntry',task_id=task,kind='work_log',data={'body':'original'},request_id='create')
    entry=original['id']
    def race(i):
        args=dict(task_id=task,entry_id=entry,data={'body':str(i)},expected_revision=1,request_id='race'+str(i))
        try: return (args,api.call('updateAgentEntry',**args))
        except RuntimeError: return None
    with concurrent.futures.ThreadPoolExecutor(max_workers=4) as pool: results=list(pool.map(race,range(4)))
    winners=[r for r in results if r]
    check(len(winners)==1,'one edit wins a stale revision race')
    args,result=winners[0]
    check(result['id']==entry and result['revision']==2,'same ID and incremented revision')
    edited=api.call('updateAgentEntry',task_id=task,entry_id=entry,data={'body':'newest'},expected_revision=2,request_id='newest')
    check(api.call('updateAgentEntry',**args)==result,'old edit retry returns original response')
    current=api.call('getAgentMessages',task_id=task,query='newest')['entries']
    check(len(current)==1 and current[0]['revision']==3,'old retry does not revert latest edit')
    check(len(api.call('getAgentEntries',task_id=task)['entries'])==1,'edits do not create message rows')
    rejects(lambda:api.call('appendAgentEntry',task_id=task,kind='work_log',data={'body':'stale','action':'correction','supersedes_id':entry},request_id='legacy'),'legacy correction cannot bypass CAS')
    removed=api.call('removeAgentEntry',task_id=task,entry_id=entry,expected_revision=3,request_id='remove')
    check(removed['deleted_at'] is not None,'delete recorded')
    check(api.call('removeAgentEntry',task_id=task,entry_id=entry,expected_revision=3,request_id='remove')==removed,'delete retry idempotent')
    check(not api.call('getAgentMessages',task_id=task)['entries'],'deleted message hidden')
    check(api.call('appendAgentEntry',task_id=task,kind='work_log',data={'body':'original'},request_id='create')==original,'creation retry preserved')
    check(not api.call('getAgentMessages',task_id=task)['entries'],'creation retry does not resurrect')
    changes=api.call('getAgentEntryChanges',task_id=task,entry_id=entry,limit=2)
    check(changes['has_more'] and len(changes['changes'])==2,'audit paginates')
    last=api.call('getAgentEntryChanges',task_id=task,entry_id=entry,after_id=changes['next_after_id'])
    check(len(last['changes'])==1 and last['changes'][0]['operation']=='delete','audit cursor advances')
    check(changes['changes'][0]['before']['data']['body']=='original' and last['changes'][0]['before']['data']['body']=='newest','before/after history retained')
    rejects(lambda:api.call('updateAgentEntry',task_id=task,entry_id=entry,data={'body':'revive'},expected_revision=4,request_id='revive'),'deleted message cannot be edited')
    rejects(lambda:api.call('appendAgentEntry',task_id=task,kind='work_log',data={'body':'collision'},request_id='remove'),'delete key reserved for append')
    # Same-key concurrent edits must make one audit row, then edit/delete must race by revision.
    second=api.call('appendAgentEntry',task_id=task,kind='work_log',data={'body':'second'},request_id='second')['id']
    same=dict(task_id=task,entry_id=second,data={'body':'edited second'},expected_revision=1,request_id='duplicate')
    with concurrent.futures.ThreadPoolExecutor(max_workers=6) as pool: responses=list(pool.map(lambda _:api.call('updateAgentEntry',**same),range(6)))
    check(all(r==responses[0] for r in responses),'concurrent identical edits return one response')
    check(len(api.call('getAgentEntryChanges',task_id=task,entry_id=second)['changes'])==1,'one audit row for duplicate edits')
    def mixed(operation):
        try:
            if operation=='edit': return api.call('updateAgentEntry',task_id=task,entry_id=second,data={'body':'race edit'},expected_revision=2,request_id='mixed-edit')
            return api.call('removeAgentEntry',task_id=task,entry_id=second,expected_revision=2,request_id='mixed-delete')
        except RuntimeError: return None
    with concurrent.futures.ThreadPoolExecutor(max_workers=2) as pool: mixed_results=list(pool.map(mixed,['edit','delete']))
    check(sum(r is not None for r in mixed_results)==1,'edit/delete race has one winner')
    check(len(api.call('getAgentEntryChanges',task_id=task,entry_id=second)['changes'])==2,'edit/delete race has no missing audit')
    viewer=Client('http://127.0.0.1:8091/jsonrpc.php',f['viewer'],f['viewer_password'])
    rejects(lambda:viewer.call('removeAgentEntry',task_id=task,entry_id=second,expected_revision=3,request_id='viewer'),'viewer cannot delete')
    rejects(lambda:api.call('getAgentEntryChanges',task_id=f['role_task_id'],entry_id=entry),'audit scoped to task')
    print(f'PASS: {checks} message API, retry and concurrency checks')

if __name__=='__main__': main()
