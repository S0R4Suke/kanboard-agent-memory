// Focused browser regression for manual corrections and safe form-token recovery.
const {chromium, request} = require('playwright');
const fs = require('fs');
const assert = require('node:assert/strict');
const fixture = JSON.parse(fs.readFileSync(__dirname + '/../.runtime/test-fixture.json'));
const origin = 'http://127.0.0.1:8091';
const taskUrl = `${origin}/?controller=TaskViewController&action=show&task_id=${fixture.task_id}`;
let checks = 0;
function check(value, label) { assert.ok(value, label); checks++; }
(async () => {
    const api = await request.newContext({baseURL:origin, extraHTTPHeaders:{Authorization:'Basic ' + Buffer.from('admin:admin').toString('base64')}});
    async function rpc(method, params) {
        const response = await api.post('/jsonrpc.php', {data:{jsonrpc:'2.0', id:1, method, params}});
        const payload = await response.json();
        assert.ok(!payload.error, JSON.stringify(payload.error));
        return payload.result;
    }
    const browser = await chromium.launch();
    try {
        const context = await browser.newContext();
        const page = await context.newPage();
        await page.goto(origin);
        await page.locator('[name=username]').fill('admin');
        await page.locator('[name=password]').fill('admin');
        await Promise.all([page.waitForURL(url => !url.search.includes('login')), page.locator('button[type=submit]').click()]);
        async function submit(expectedStatus) {
            const responsePromise = page.waitForResponse(response => response.request().method() === 'POST' && response.url().includes('MemoryController'));
            await page.locator('.am-form button[type=submit]').filter({visible:true}).click();
            check((await responsePromise).status() === expectedStatus, 'form response ' + expectedStatus);
            await page.waitForLoadState('load');
        }
        // A blank optional title must work, then its original body is loaded by the correction link.
        const unique = 'Correction browser fixture ' + Date.now();
        await page.goto(taskUrl);
        await page.locator('#am-work_log .am-add > summary').click();
        await page.locator('#am-work_log textarea[name=body]').fill(unique);
        const saveResponse = page.waitForResponse(response => response.request().method() === 'POST' && response.url().includes('MemoryController'));
        await page.locator('#am-work_log button[type=submit]').click();
        check((await saveResponse).status() === 302, 'work log saves without title');
        await page.waitForLoadState('load');
        const original = (await rpc('getAgentEntries', {task_id:fixture.task_id,kind:'work_log',query:unique})).entries[0];
        await page.locator(`#am-entry-${original.id} .am-entry-actions a`).filter({hasText:'編集'}).click();
        check(await page.locator('textarea[name=body]').inputValue() === unique, 'work log correction prefilled');
        await page.locator('textarea[name=body]').fill(unique + ' corrected');
        await submit(302);
        const workLogs = (await rpc('getAgentMessages', {task_id:fixture.task_id,kind:'work_log',query:unique})).entries;
        check(workLogs.length === 1 && workLogs[0].id === original.id && workLogs[0].data.body === unique + ' corrected', 'work log edit changes same message without duplicate');
        check((await rpc('getAgentEntryChanges', {task_id:fixture.task_id,entry_id:original.id})).changes[0].before.data.body === unique, 'pre-edit content retained in audit');
        const decisionsBefore = (await rpc('getAgentMessages', {task_id:fixture.task_id,kind:'decision'})).entries;
        const decision = decisionsBefore[0];
        await page.locator(`#am-entry-${decision.id} .am-entry-actions a`).filter({hasText:'編集'}).click();
        check(await page.locator('textarea[name=decision]').inputValue() === decision.data.decision, 'decision correction prefilled');
        await page.locator('textarea[name=decision]').fill('Corrected decision ' + unique);
        await submit(302);
        const decisions = (await rpc('getAgentMessages', {task_id:fixture.task_id,kind:'decision'})).entries;
        check(decisions.length === decisionsBefore.length && decisions.find(entry => entry.id === decision.id).data.decision === 'Corrected decision ' + unique, 'decision edit changes same message without duplicate');
        // Native date control supports seconds and round trips through the user timezone.
        await page.locator(`#am-entry-${decision.id} .am-entry-actions a`).filter({hasText:'編集'}).click();
        check(await page.locator('[name=decided_at_local]').inputValue() === '', 'unknown decision date remains empty');
        await page.locator('[name=decided_at_local]').fill('2026-10-30T14:15:37');
        const formZone = await page.locator('[name=decided_at_timezone]').inputValue();
        check((await page.locator('#am-decision-time-help').count()) === 1 && formZone.length > 0, 'decision date timezone is explained');
        await submit(302);
        const dated = (await rpc('getAgentMessages', {task_id:fixture.task_id,kind:'decision'})).entries.find(entry => entry.id === decision.id);
        check(dated.data.decided_at.endsWith('Z') && dated.data.decided_at.includes(':37Z'), 'date saves UTC seconds');
        await page.locator(`#am-entry-${decision.id} .am-entry-actions a`).filter({hasText:'編集'}).click();
        check(await page.locator('[name=decided_at_local]').inputValue() === '2026-10-30T14:15:37', 'decision date prefilled in the same timezone');
        await page.locator('[name=decided_at_local]').fill('');
        await submit(302);
        check(!(await rpc('getAgentMessages', {task_id:fixture.task_id,kind:'decision'})).entries.find(entry => entry.id === decision.id).data.decided_at, 'date can be cleared in the browser');
        await page.locator(`#am-entry-${decision.id} .am-audit-link a`).click();
        check(await page.locator('.am-change-versions').count() > 0 && (await page.locator('.am-changes').innerText()).includes(dated.data.decided_at), 'audit screen exposes exact previous date');
        fs.mkdirSync(__dirname + '/../test-results/manual', {recursive:true});
        await page.screenshot({path:__dirname + '/../test-results/manual/audit-desktop.png',fullPage:true});
        await page.setViewportSize({width:375,height:900});
        check(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), 'audit screen fits mobile width');
        await page.screenshot({path:__dirname + '/../test-results/manual/audit-mobile.png',fullPage:true});
        await page.setViewportSize({width:1280,height:720});
        await page.goto(taskUrl);
        // Upgrade an already-open append-only correction form without duplicating a message.
        await page.locator(`#am-entry-${original.id} .am-entry-actions a`).filter({hasText:'編集'}).click();
        const latestBody = unique + ' corrected from an old form';
        await page.locator('textarea[name=body]').fill(latestBody);
        await page.locator('.am-form').evaluate((form, id) => {
            form.querySelector('[name=entry_id]').remove();
            form.querySelector('[name=expected_revision]').remove();
            for (const [name,value] of [['action','correction'],['supersedes_id',String(id)]]) {
                const input=document.createElement('input'); input.type='hidden'; input.name=name; input.value=value; form.appendChild(input);
            }
        }, original.id);
        await submit(409);
        check((await rpc('getAgentMessages', {task_id:fixture.task_id,kind:'work_log',query:unique})).entries[0].data.body === unique + ' corrected', 'old form does not blindly edit');
        check(await page.locator('textarea[name=body]').inputValue() === latestBody, 'old form text retained for confirmation');
        await submit(302);
        check((await rpc('getAgentMessages', {task_id:fixture.task_id,kind:'work_log',query:unique})).entries.length === 1, 'old form confirmation edits one message');
        // An invalid/stale form token never writes, and preserves text for an explicit resubmit.
        const recovery = unique + ' token recovery';
        await page.locator('#am-work_log .am-add > summary').click();
        await page.locator('#am-work_log textarea[name=body]').fill(recovery);
        await page.locator('#am-work_log input[name=csrf_token]').evaluate(node => {node.value = '0'.repeat(64);});
        const failedResponse = page.waitForResponse(response => response.request().method() === 'POST' && response.url().includes('MemoryController'));
        await page.locator('#am-work_log button[type=submit]').click();
        check((await failedResponse).status() === 403, 'invalid token rejected');
        await page.waitForLoadState('load');
        check(await page.locator('textarea[name=body]').inputValue() === recovery && (await page.locator('[role=alert]').innerText()).includes('もう一度保存'), 'token failure retains text with recovery instructions');
        check((await rpc('getAgentEntries', {task_id:fixture.task_id,query:recovery})).entries.length === 0, 'invalid token created no record');
        check(await page.locator('input[name=csrf_token]').inputValue() !== '0'.repeat(64), 'new form token issued');
        await submit(302);
        check((await rpc('getAgentEntries', {task_id:fixture.task_id,query:recovery})).entries.length === 1, 'explicit resubmit creates exactly one record');
        // Deletion is confirmed, rejects invalid tokens/GET, and hides the message without losing its audit.
        await page.locator(`#am-entry-${original.id} .am-entry-actions a`).filter({hasText:'削除'}).click();
        check((await page.locator('blockquote').innerText()).includes('corrected'), 'delete confirmation shows current content');
        const deleteAction = await page.locator('.am-delete-form').getAttribute('action');
        check((await context.request.get(new URL(deleteAction, origin).href)).status() === 403, 'GET cannot delete');
        await page.locator('.am-delete-form input[name=csrf_token]').evaluate(node => {node.value = '0'.repeat(64);});
        const rejectedDelete = page.waitForResponse(response => response.request().method() === 'POST' && response.url().includes('MemoryController'));
        await page.locator('.am-delete-form button').click();
        check((await rejectedDelete).status() === 403, 'delete requires valid token');
        await page.waitForLoadState('load');
        check((await rpc('getAgentMessages', {task_id:fixture.task_id,kind:'work_log',query:unique})).entries.some(entry => entry.id === original.id), 'rejected delete retains message');
        const removed = page.waitForResponse(response => response.request().method() === 'POST' && response.url().includes('MemoryController'));
        await page.locator('.am-delete-form button').click();
        check((await removed).status() === 302, 'confirmed delete succeeds');
        await page.waitForLoadState('load');
        check(await page.locator(`#am-entry-${original.id}`).count() === 0, 'deleted message removed from timeline');
        const audit = await rpc('getAgentEntryChanges', {task_id:fixture.task_id,entry_id:original.id});
        check(audit.changes.at(-1).operation === 'delete' && audit.changes.at(-1).before.data.body === latestBody, 'deleted content retained in audit');
        await page.getByRole('link', {name:'変更履歴（編集・削除）',exact:true}).click();
        check(await page.locator('.am-changes').count() === 1, 'deleted-message audit has a discoverable task-wide entry point');
        const auditUrl = new URL(page.url());auditUrl.searchParams.set('entry_id',original.id);
        await page.goto(auditUrl.href);
        check((await page.locator('.am-changes').innerText()).includes(latestBody) && (await page.locator('.am-changes').innerText()).includes('削除済み'), 'deleted content can be inspected through the audit screen');
        // Direct edit URL must also enforce project membership; hiding a link is insufficient.
        const viewerContext = await browser.newContext();
        const viewer = await viewerContext.newPage();
        await viewer.goto(origin);
        await viewer.locator('[name=username]').fill(fixture.viewer);
        await viewer.locator('[name=password]').fill(fixture.viewer_password);
        await Promise.all([viewer.waitForURL(url => !url.search.includes('login')), viewer.locator('button[type=submit]').click()]);
        await viewer.goto(taskUrl);
        check(await viewer.locator('.am-entry-actions').count() === 0, 'viewer has no correction controls');
        const forbidden = await viewerContext.request.get(`${origin}/?controller=MemoryController&action=edit&plugin=AgentMemory&task_id=${fixture.task_id}&entry_id=${original.id}`);
        check(forbidden.status() === 403, 'viewer direct correction URL forbidden');
        const crossTask = await context.request.get(`${origin}/?controller=MemoryController&action=edit&plugin=AgentMemory&task_id=${fixture.role_task_id}&entry_id=${original.id}`);
        check((await crossTask.text()).includes('not found'), 'another task cannot load the record');
        await context.close();
        await viewerContext.close();
        console.log(`PASS: ${checks} message editing, deletion and token recovery checks`);
    } finally {
        await browser.close();
        await api.dispose();
    }
})().catch(error => { console.error(error); process.exit(1); });
