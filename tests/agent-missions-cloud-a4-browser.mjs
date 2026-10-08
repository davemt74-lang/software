import assert from 'node:assert/strict';
import fs from 'node:fs/promises';
import {chromium} from 'playwright';

const js=await fs.readFile(new URL('../chat-agent-teams-a3.js',import.meta.url),'utf8');
const css=await fs.readFile(new URL('../chat-agent-teams-a3.css',import.meta.url),'utf8');
const browser=await chromium.launch({headless:true,...(process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH?{executablePath:process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH}:{})});
const page=await browser.newPage({viewport:{width:1000,height:800}});
const errors=[];page.on('pageerror',e=>errors.push(e.message));
try {
 await page.setContent('<main class="chat-main"><div id="chatComposerShell" style="max-width:100%"><form id="chatForm"><textarea id="chatInput"></textarea></form></div></main>');
 await page.addStyleTag({content:css});
 await page.evaluate(()=>{
   Object.defineProperty(window,'crypto',{configurable:true,value:{randomUUID:()=> 'a4-supervisor-20261007'}});
   window.STONEFELLOW_CHAT={initialConversationId:47};
   window.VP3_AGENT_TEAMS_A3={endpoint:'/api/agent-missions-cloud-v1.php',csrf:'csrf-fixture'};
   window.calls=[];
   window.mission={id:'11111111-1111-4111-8111-111111111111',objective:'Evaluate marketing channels',
      status:'completed',created_at:'2026-10-07 19:00:00',updated_at:'2026-10-07 19:10:00',
      tasks:[{id:'22222222-2222-4222-8222-222222222222',title:'Original analyst',role:'analyst',status:'completed',result:'First pass'}]};
   window.proposal={id:'33333333-3333-4333-8333-333333333333',status:'proposed',decision:'staff',
      reason:'<img src=x onerror="window.__xss=true"> Additional independent review recommended',
      confidence:84,created_at:'2026-10-07 19:11:00',requires_approval:true,
      tasks:[{title:'Risk reviewer',role:'critic',objective:'Check for overlooked assumptions',
         depends_on:['22222222-2222-4222-8222-222222222222']}]
   };
   window.pending=[];
   window.fetch=async(_url,opts)=>{
     const request=JSON.parse(opts.body);
     calls.push(request);
     if(request.csrf_token!=='csrf-fixture')throw Error('CSRF token missing');
     if(request.action==='evaluate'){pending=[proposal];return {ok:true,json:async()=>({ok:true,supervision:proposal})};}
     if(request.action==='decisions')return {ok:true,json:async()=>({ok:true,items:pending})};
     if(request.action==='approve'){
       if(!request.confirmed||request.decision_id!==proposal.id)throw Error('Unconfirmed approval');
       proposal.status='approved';pending=[proposal];mission.status='running';
       mission.tasks.push({id:'44444444-4444-4444-8444-444444444444',title:'Risk reviewer',role:'critic',status:'queued'});
       return {ok:true,json:async()=>({ok:true,supervision:proposal})};
     }
     if(request.action==='reject'){
       if(!request.confirmed)throw Error('Unconfirmed rejection');
       proposal.status='rejected';pending=[proposal];
       return {ok:true,json:async()=>({ok:true,supervision:proposal})};
     }
     if(request.action==='list'){
       return {ok:true,json:async()=>({ok:true,items:[{id:mission.id,objective:mission.objective,
         status:mission.status,updated_at:mission.updated_at,tasks:mission.tasks}]})};
     }
     return {ok:true,json:async()=>({ok:true,mission:JSON.parse(JSON.stringify(mission))})};
   };
   window.confirm=()=>true;
 });
 await page.addScriptTag({content:js});
 await page.locator('[data-agent-teams-a3]>summary').click();
 await page.getByRole('button',{name:'View',exact:true}).click();
 await page.waitForFunction(()=>document.querySelector('[data-agent-teams-supervisor]')!==null);
 await page.getByRole('button',{name:'Evaluate need for specialists'}).click();
 await page.waitForFunction(()=>window.calls.some(c=>c.action==='evaluate'));
 await page.getByRole('button',{name:'Approve new workers'}).waitFor();
 assert.equal(await page.locator('.vp3-teams-supervisor-candidates li').count(),1);
 assert.equal(await page.locator('[data-agent-teams-supervisor] img').count(),0,'supervisor content must not be HTML interpreted');
 assert.equal(await page.evaluate(()=>Boolean(window.__xss)),false);
 assert.equal(await page.locator('.vp3-teams-supervisor-reason').textContent(),
  '<img src=x onerror="window.__xss=true"> Additional independent review recommended');
 await page.evaluate(()=>{
   const drawer=document.createElement('aside');
   drawer.id='chatNotificationDrawer';
   drawer.innerHTML='<button data-notification-tab="brain" class="active">Brain</button><div data-notification-drawer-body></div>';
   document.body.appendChild(drawer);
   VP3_AGENT_TEAMS_A3_BRAIN(drawer.querySelector('[data-notification-drawer-body]'));
 });
 assert.match(await page.locator('[data-agent-teams-brain-a3]').textContent(),/Staffing approval needed/);
 await page.getByRole('button',{name:'Approve new workers'}).click();
 await page.waitForFunction(()=>window.calls.some(c=>c.action==='approve'));
 const approval=await page.evaluate(()=>calls.find(c=>c.action==='approve'));
 assert.equal(approval.confirmed,true);
 assert.equal(approval.decision_id,'33333333-3333-4333-8333-333333333333');
 assert.equal(await page.locator('.vp3-teams-workers li').count(),2,'approved worker rendered');
 const calls=await page.evaluate(()=>window.calls);
 assert.equal(calls.filter(x=>x.action==='approve').length,1,'one approval click must not create duplicate teams');
 await page.setViewportSize({width:375,height:680});
 assert.equal(await page.evaluate(()=>document.documentElement.scrollWidth>window.innerWidth),false);
 assert.deepEqual(errors,[],'A4 must not introduce browser exceptions');
 console.log('MISSION_A4_BROWSER PASS: supervisor rationale XSS-safe, Brain approval notice, reviewed expansion, no duplicate dispatch, mobile layout');
} finally {await browser.close();}
