import assert from 'node:assert/strict';
import fs from 'node:fs/promises';
import {chromium} from 'playwright';
const script=await fs.readFile('chat-agent-teams-a3.js','utf8');const browser=await chromium.launch({headless:true,args:['--no-sandbox']});const page=await browser.newPage({viewport:{width:390,height:844}}),calls=[],errors=[];page.on('pageerror',e=>errors.push(e.message));let mission=null,revision=0,configured=false,ticks=0;
const mid='11111111-1111-4111-8111-111111111111',tids=['22222222-2222-4222-8222-222222222222','33333333-3333-4333-8333-333333333333'];const draft={max_parallel:1,assignments:tids.map(task_id=>({task_id,tools:[],max_calls:0,actions:[],max_actions:0,output:'analysis',browser_url:''}))};
const tools=()=>({revision,configured,active:configured,capabilities:['knowledge.search','brain.recall'],action_capabilities:[],max_calls_per_worker:3,max_actions_per_worker:0,max_parallel_limit:1,browser_requirements:[],assignments:draft});
await page.route('https://cloud-mission.test/',r=>r.fulfill({contentType:'text/html',body:'<!doctype html><meta name="viewport" content="width=device-width,initial-scale=1"><div id="chatComposerShell"><form id="chatForm"><textarea id="chatInput"></textarea><button type="submit">Send</button></form></div><div id="chatMessages"></div>'}));
await page.route('**/api/**',async r=>{const b=r.request().postDataJSON();calls.push(b);const ok=value=>r.fulfill({json:{ok:true,contract:'vp3.agent-missions.cloud.v1',...value}});
 if(b.action==='task.route')return ok({route:'chat'});
 if(b.action==='create'||b.action==='task.prepare'){mission={id:mid,objective:b.objective,status:'planned',execution_target:'cloud',authority_current:true,tools_enabled:true,tools_configured:false,created_at:'2026-11-01 12:00:00',tasks:tids.map((id,i)=>({id,title:i?'Reviewer':'Evidence analyst',role:i?'reviewer':'investigator',status:'queued'})),chat_task:{draft,provider_key:'fixture',model:'fixture',prepared_at:'2026-11-01 12:00:00'}};return ok({mission});}
 if(b.action==='list')return ok({missions:mission?[mission]:[]});
 if(b.action==='progress.feed')return ok({items:mission?[mission]:[],notifications:{items:[],unread:0},runtimes:{cloud:true,homeserver:false}});
 if(b.action==='schedule.list')return ok({schedules:[]});
 if(b.action==='get')return ok({mission});
 if(b.action==='tools.get')return ok({tools:tools()});
 if(b.action==='tools.configure'){revision++;configured=true;mission.tools_configured=true;return ok({tools:tools(),mission});}
 if(b.action==='tools.start'){mission.status='running';return ok({mission});}
 if(b.action==='cloud.tick'){ticks++;mission.tasks[ticks===1?0:1].status='completed';mission.tasks[ticks===1?0:1].result='Draft evidence <img src=x> safely displayed';if(ticks===2){mission.status='completed';mission.result='Final comparison <img src=x> safely displayed';}return ok({mission});}
 if(b.action==='actions.list')return ok({actions:[]});
 if(b.action==='decisions')return ok({decisions:[]});
 return ok({});
});let checks=0;const check=async(name,fn)=>{await fn();checks++;console.log('PASS '+name);};
try{
 await page.goto('https://cloud-mission.test/');await page.addStyleTag({content:await fs.readFile('chat-agent-teams-a3.css','utf8')});await page.evaluate(()=>{window.VP3_AGENT_TEAMS_A3={endpoint:'/api/agent-missions-cloud-v1.php',csrf:'fixture'};window.STONEFELLOW_CHAT_CONTINUITY={conversationId:()=>11};window.STONEFELLOW_CHAT={agentId:7};window.ordinary=0;document.querySelector('#chatForm').addEventListener('submit',e=>{e.preventDefault();ordinary++;});window.confirm=()=>true;});await page.clock.install();await page.addScriptTag({content:script});
 await check('Cloud execution is explicitly selected and uses the configured provider',async()=>{assert.equal(await page.locator('#vp3MissionRuntime').inputValue(),'cloud');assert.equal(await page.locator('#vp3MissionProvider').isDisabled(),true);await page.locator('#chatInput').fill('Hello');await page.getByRole('button',{name:'Send',exact:true}).click();await page.waitForFunction(()=>ordinary===1);assert.equal(calls.find(c=>c.action==='task.route').executor,'cloud');});
 await page.locator('[data-agent-teams-a3] > summary').click();await page.getByRole('textbox',{name:'Mission objective'}).fill('Compare our saved research');await page.getByRole('button',{name:'Prepare Mission',exact:true}).click();await page.locator('.vp3-teams-detail h3').waitFor();
 await check('prepare opens assignment review without starting inference',async()=>{assert.equal(calls.find(c=>c.action==='create').executor,'cloud');assert.equal(calls.some(c=>c.action==='cloud.tick'||c.action==='tools.start'||c.action==='start'),false);assert.equal(await page.locator('.vp3-worker-browser:visible').count(),0);});
 await page.getByRole('button',{name:'Approve assignments',exact:true}).click();await page.waitForFunction(()=>document.querySelector('.vp3-teams-detail')?.textContent.includes('Review revision 1'));await page.getByRole('button',{name:'Start',exact:true}).click();await page.waitForFunction(()=>document.querySelector('.vp3-teams-detail')?.textContent.includes('Working'));
 await check('start sends exact reviewed configuration and keeps Cloud execution visible',async()=>{const c=calls.find(c=>c.action==='tools.configure');assert.equal(c.assignments.max_parallel,1);assert.equal(c.confirmed,true);assert.equal(calls.find(c=>c.action==='tools.start').expected_revision,1);assert.match(await page.locator('.vp3-teams-detail').innerText(),/VP3 Cloud analysis/);});
 await page.clock.runFor(5500);await page.waitForFunction(()=>document.querySelector('.vp3-teams-detail')?.textContent.includes('Draft evidence <img'));await page.clock.runFor(5500);await page.waitForFunction(()=>document.querySelector('.vp3-teams-detail')?.textContent.includes('Research completed'));
 await check('Cloud job ticks finish the workers and show safe receipt-backed output',async()=>{assert.equal(ticks,2);assert.equal(await page.locator('.vp3-teams-detail img').count(),0);assert.match(await page.locator('.vp3-teams-detail').textContent(),/Final comparison <img/);});
 await page.clock.runFor(10000);
 await check('completed Missions do not replay and HomeServer selection remains available',async()=>{assert.equal(ticks,2);await page.locator('#vp3MissionRuntime').selectOption('homeserver');assert.equal(await page.locator('#vp3MissionProvider').isDisabled(),false);assert.deepEqual(errors,[]);assert.ok(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1));});
 console.log(`CLOUD_MISSIONS_BROWSER=PASS (${checks} cases)`);
}finally{await browser.close();}
