// Public candidate only; never target the user's workspace.
const {chromium}=require('playwright');
const fs=require('node:fs');const assert=require('node:assert/strict');
const f=JSON.parse(fs.readFileSync('.runtime/test-fixture.json'));
const origin='http://127.0.0.1:8091';let checks=0;
function check(value,label){assert.ok(value,label);checks++;}
(async()=>{const browser=await chromium.launch();try{
 const context=await browser.newContext({viewport:{width:1440,height:1000}});const page=await context.newPage();const errors=[];page.on('pageerror',e=>errors.push(e.message));
 async function login(p,user,password){await p.goto(origin);await p.locator('[name=username]').fill(user);await p.locator('[name=password]').fill(password);await Promise.all([p.waitForURL(u=>!u.toString().includes('login')),p.locator('button[type=submit]').click()]);}
 const taskUrl=origin+'/?controller=TaskViewController&action=show&task_id='+f.task_id;
 await login(page,'admin','admin');await page.goto(taskUrl);
 check(await page.locator('#task-summary h2').innerText()==='調査と実装・検証','native task summary remains in place');
 check(await page.locator('.am-layout,.am-left,.am-cards-template,.kre-editor').count()===0,'public candidate contains no replacement layout or editor');
 check(await page.locator('link[href*="KanboardTheme"],script[src*="TaskDetailUI"],script[src*="RichEditor"]').count()===0,'public candidate loads no private assets');
 check(await page.locator('#task-summary .task-summary-column').count()>0&&await page.locator('#task-summary [data-test-hook=field]').count()===1,'native attributes and third-party fields remain available');
 check((await page.locator('body').innerText()).includes('Standard subtask')&&(await page.locator('body').innerText()).includes('regression.txt'),'native subtasks and attachments remain available');
 check(await page.locator('#am-work_log').isVisible()&&await page.locator('#am-decision').isVisible(),'human work logs and decisions are available');
 await page.locator('#am-work_log .am-add>summary').click();await page.locator('#am-work_log input[name=title]').fill('Public candidate usage');await page.locator('#am-work_log textarea[name=body]').fill('Keep <script>inert</script> and Japanese 日本語');
 await page.locator('#am-work_log button[type=submit]').click();await page.waitForURL(u=>u.pathname.includes('/task/')||u.searchParams.get('controller')==='TaskViewController');
 check((await page.locator('#am-work_log').innerText()).includes('Public candidate usage')&&await page.locator('.am-timeline script').count()===0,'native-style manual log saves without HTML injection');
 await page.getByRole('link',{name:'Edit the task',exact:true}).click();await page.locator('#modal-box input[name=title]').waitFor();
 check(await page.locator('#modal-box textarea[name=description]').isVisible()&&await page.locator('#modal-box .kre-editor').count()===0,'task editing uses the native Markdown editor');await page.keyboard.press('Escape');
 fs.mkdirSync('test-results/public',{recursive:true});
 for(const width of [1440,768,375]){await page.setViewportSize({width,height:1000});check(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),'native layout fits '+width);await page.screenshot({path:'test-results/public/task-'+width+'.png',fullPage:true});}
 const nojs=await browser.newContext({javaScriptEnabled:false,storageState:await context.storageState()});const n=await nojs.newPage();await n.goto(taskUrl);
 check(await n.locator('#task-summary h2').count()===1&&await n.locator('#am-work_log form').count()===1,'native summary and memory input work without JavaScript');await nojs.close();
 const viewer=await browser.newContext();const v=await viewer.newPage();await login(v,f.viewer,f.viewer_password);await v.goto(taskUrl);
 check(await v.locator('#am-work_log').isVisible()&&await v.locator('.am-add,.am-entry-actions').count()===0,'viewer can read but cannot edit records');await viewer.close();
 check(errors.length===0,'no browser exceptions');console.log(`PASS: ${checks} public-candidate native layout/editor/records/permissions/responsive checks`);
}finally{await browser.close();}})().catch(e=>{console.error(e);process.exit(1)});
