import assert from 'node:assert/strict';
import fs from 'node:fs/promises';
import {chromium} from 'playwright';
const browser=await chromium.launch({headless:true,args:['--no-sandbox']}),page=await browser.newPage({viewport:{width:390,height:844}}),calls=[],errors=[];
page.on('pageerror',e=>errors.push(e.message));const mid='11111111-1111-4111-8111-111111111111',tid='22222222-2222-4222-8222-222222222222',sid='33333333-3333-4333-8333-333333333333';
const draft={max_parallel:1,assignments:[{task_id:tid,tools:['knowledge.search'],max_calls:1,actions:[],max_actions:0,output:'analysis',browser_url:''}]};
const mission={id:mid,objective:'Monitor dough research',status:'planned',execution_target:'cloud',authority_current:true,tools_enabled:true,tools_configured:true,tasks:[{id:tid,title:'Evidence analyst',status:'queued'}],chat_task:{draft,prepared_at:'2026-11-03 00:00:00'}};
let schedules=[],lost=true,background=false;const listing=()=>({schedules,knowledge_folders:[{id:32,folder_name:'Existing research <img src=x>'}],cloud_scheduler_health:{state:background?'healthy':'browser_assisted',background_ready:background,checked_at:'2026-11-03 00:00:00',background_at:background?'2026-11-03 00:00:00':''}});
await page.route('https://recurring.test/',r=>r.fulfill({contentType:'text/html',body:'<!doctype html><meta name="viewport" content="width=device-width,initial-scale=1"><div id="chatComposerShell"><form id="chatForm"><textarea id="chatInput"></textarea><button type="submit">Send</button></form></div><div id="chatMessages"></div>'}));
await page.route('**/api/**',async r=>{const b=r.request().postDataJSON();calls.push(b);const ok=data=>r.fulfill({json:{ok:true,...data}});
 if(b.action==='list')return ok({items:[mission],runtimes:{cloud:true,homeserver:false}});
 if(b.action==='progress.feed')return ok({items:[mission],notifications:{items:[],unread:0}});
 if(b.action==='get')return ok({mission});
 if(b.action==='tools.get')return ok({tools:{configured:true,active:true,revision:1,execution_target:'cloud',assignments:draft,capabilities:['knowledge.search'],action_capabilities:[],max_parallel_limit:1}});
 if(b.action==='schedule.list')return ok(listing());
 if(b.action==='schedule.create'){
  assert.equal(b.confirmed,true);assert.equal(b.folder_id,32);assert.equal(b.save_reports,true);assert.equal(b.expected_revision,1);
  if(!schedules.length)schedules=[{id:sid,execution_target:'cloud',objective:mission.objective,status:'active',revision:1,folder_id:32,folder_name:'Existing research',...b.timing,next_run_at:'2026-11-04 16:00:00',runs:[{mission_id:mid,status:'completed',report_state:'saved',knowledge_id:8,evidence_changed:-1,due_at:'2026-11-03 16:00:00'}]}];
  if(lost){lost=false;return r.fulfill({status:503,json:{ok:false,error:'Lost schedule response'}});}return ok(listing());
 }
 if(['schedule.pause','schedule.resume','schedule.cancel'].includes(b.action)){assert.equal(b.expected_revision,schedules[0].revision);schedules[0]={...schedules[0],status:{'schedule.pause':'paused','schedule.resume':'active','schedule.cancel':'cancelled'}[b.action],revision:schedules[0].revision+1};return ok(listing());}
 if(b.action==='cloud.schedule.tick')return ok({created:0,skipped:0});
 if(b.action==='actions.list')return ok({actions:[]});if(b.action==='decisions')return ok({items:[]});return ok({});
});let checks=0;const check=async(name,fn)=>{await fn();checks++;console.log('PASS '+name);};
try{
 await page.goto('https://recurring.test/');await page.addStyleTag({content:await fs.readFile('chat-agent-teams-a3.css','utf8')});await page.evaluate(()=>{window.VP3_AGENT_TEAMS_A3={endpoint:'/api/agent-missions-cloud-v1.php',csrf:'fixture'};window.confirm=()=>false;});await page.clock.install();await page.addScriptTag({content:await fs.readFile('chat-agent-teams-a3.js','utf8')});
 await page.locator('[data-agent-teams-a3]>summary').click();await page.getByRole('button',{name:'View',exact:true}).click();await page.getByLabel('Mission report folder',{exact:true}).waitFor();
 await check('Cloud schedule uses existing Knowledge folders and shows honest worker readiness',async()=>{assert.equal(await page.locator('[data-cloud-scheduler-state=browser_assisted]').count(),1);assert.equal(await page.getByLabel('Mission report folder',{exact:true}).locator('option').count(),2);assert.equal(await page.locator('.vp3-schedule-create img').count(),0);assert.equal(calls.some(c=>c.action==='cloud.schedule.tick'),false);});
 await page.getByLabel('Mission report folder',{exact:true}).selectOption('32');await page.getByRole('button',{name:'Create schedule',exact:true}).click();
 await check('declining recurrence creates no jobs or writes',async()=>{assert.equal(calls.some(c=>c.action==='schedule.create'||c.action==='cloud.tick'),false);});
 await page.evaluate(()=>window.confirm=()=>true);await page.getByRole('button',{name:'Create schedule',exact:true}).click();await page.waitForFunction(()=>document.querySelector('.vp3-teams-status')?.textContent.includes('Lost schedule response'));await page.getByRole('button',{name:'Create schedule',exact:true}).click();await page.getByRole('button',{name:'Pause schedule',exact:true}).waitFor();
 await check('lost response retry keeps the same operation and shows saved reports',async()=>{const c=calls.filter(c=>c.action==='schedule.create');assert.equal(c.length,2);assert.equal(c[0].request_id,c[1].request_id);assert.match(await page.locator('.vp3-specialist-schedules').innerText(),/Report saved in My Knowledge/);assert.match(await page.locator('.vp3-specialist-schedules a').getAttribute('href'),/folder=32/);assert.equal(calls.some(c=>c.action==='tools.start'||c.action==='start'),false);});
 await page.getByRole('button',{name:'Pause schedule',exact:true}).click();await page.getByRole('button',{name:'Resume schedule',exact:true}).click();
 await page.clock.runFor(31000);await page.waitForFunction(()=>document.querySelector('[data-cloud-scheduler-state]'));
 await check('browser-assisted ticks are explicit and controls retain history',async()=>{assert.equal(calls.filter(c=>c.action==='cloud.schedule.tick').length,1);assert.ok(calls.find(c=>c.action==='cloud.schedule.tick').csrf_token);await page.getByRole('button',{name:'Cancel future runs',exact:true}).click();await page.waitForFunction(()=>document.querySelector('.vp3-specialist-schedules')?.textContent.includes('cancelled'));assert.match(await page.locator('.vp3-specialist-schedules').innerText(),/Report saved/);});
 await check('mobile schedule interface is safe and fits the viewport',async()=>{assert.deepEqual(errors,[]);assert.equal(await page.locator('.vp3-specialist-schedules img').count(),0);assert.ok(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1));});
 console.log(`CLOUD_MISSION_SCHEDULES_BROWSER=PASS (${checks} cases)`);
}finally{await browser.close();}
