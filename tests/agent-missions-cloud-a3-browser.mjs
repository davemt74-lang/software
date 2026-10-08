import assert from 'node:assert/strict';
import fs from 'node:fs/promises';
import {chromium} from 'playwright';

const source=await fs.readFile(new URL('../chat-agent-teams-a3.js',import.meta.url),'utf8');
const css=await fs.readFile(new URL('../chat-agent-teams-a3.css',import.meta.url),'utf8');
const browser=await chromium.launch({headless:true,...(process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH?{executablePath:process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH}:{})});
const page=await browser.newPage({viewport:{width:1200,height:900}});
const errors=[];page.on('pageerror',error=>errors.push(error.message));
try {
  await page.setContent('<div class="chat-main"><div id="chatComposerShell" style="width:680px;max-width:100%"><form id="chatForm"><textarea id="chatInput"></textarea><button type="submit">Send</button></form></div></div>');
  await page.addStyleTag({content:css});
  await page.evaluate(()=>{
    Object.defineProperty(window,'crypto',{configurable:true,value:{randomUUID:()=> 'a3-browser-request-001'}});
    window.STONEFELLOW_CHAT={initialConversationId:27};
    window.VP3_AGENT_TEAMS_A3={endpoint:'/api/agent-missions-cloud-v1.php',csrf:'a3-test-csrf'};
    window.__calls=[];
    window.__mission={
      id:'11111111-1111-4111-8111-111111111111',
      objective:'<img src=x onerror="window.injected=true">',
      status:'planned',
      created_at:'2026-10-08 02:00:00',
      updated_at:'2026-10-08 02:00:00',
      read_only:true,
      verified:false,
      result:'',
      tasks:[{id:'22222222-2222-4222-8222-222222222222',title:'Compare providers',role:'research',status:'queued',attempt:0}],
      events:[{id:1,kind:'mission.planned',created_at:'2026-10-08 02:00:00'}]
    };
    window.fetch=async(_url,options)=>{
      const body=JSON.parse(options.body);__calls.push(body);
      if(body.csrf_token!=='a3-test-csrf')throw Error('Lost CSRF token');
      const mission=window.__mission;
      if(body.action==='create'){mission.objective=body.objective;mission.status='planned';}
      if(body.action==='start'||body.action==='resume'){mission.status='running';}
      if(body.action==='pause'){mission.status='waiting_review';}
      if(body.action==='retry'){mission.status='running';mission.tasks[0].status='running';}
      if(body.action==='cancel'){mission.status='cancelled';}
      if(body.action==='cognition.configure'){mission.cognition_auto_review=body.enabled;}
      if(body.action==='cognition.evaluate'){
        mission.cognition_review={id:'33333333-3333-4333-8333-333333333333',
          round:1,status:'proposed',decision:'extend',
          rationale:'<img src=x onerror="window.injected=true"> Further independent research is warranted.',
          tasks:[{title:'Independent review',role:'reviewer',objective:'Review the current claims'}],
          updated_at:'2026-10-08 04:00:00'};
      }
      if(body.action==='cognition.decide'){
        mission.cognition_review.status=body.approve?'applied':'declined';
        if(body.approve){mission.status='running';mission.tasks.push({
          id:'44444444-4444-4444-8444-444444444444',title:'Independent review',role:'reviewer',status:'queued',attempt:0});}
      }
      const data=body.action==='list'
        ? {ok:true,items:[{id:mission.id,objective:mission.objective,status:mission.status,
           updated_at:mission.updated_at,tasks:mission.tasks}]}
        : {ok:true,mission:JSON.parse(JSON.stringify(mission))};
      return {ok:true,json:async()=>data};
    };
    window.confirm=()=>true;
  });
  await page.addScriptTag({content:source});
  assert.equal(await page.locator('[data-agent-teams-a3]').count(),1,'one mission panel');
  assert.equal(await page.locator('#chatForm').count(),1,'original Chat composer retained');
  await page.locator('[data-agent-teams-a3]>summary').click();
  await page.waitForFunction(()=>document.querySelector('.vp3-teams-list').textContent.includes('Viewing')===false && document.querySelector('.vp3-teams-list').textContent.includes('View'));
  await page.locator('#vp3MissionObjective').fill('Compare three hosting providers');
  await page.locator('.vp3-teams-autoreview input[type=checkbox]').check();
  await page.locator('.vp3-teams-create button').click();
  await page.waitForFunction(()=>window.__calls.some(c=>c.action==='start'));
  await page.waitForFunction(()=>document.querySelector('.vp3-teams-detail h3')?.textContent==='Compare three hosting providers');
  assert.equal(await page.locator('[data-agent-teams-a3]').count(),1,'no duplicate panel');
  const createCall=await page.evaluate(()=>window.__calls.find(c=>c.action==='create'));
  assert.equal(createCall.request_id,'a3-browser-request-001');
  assert.equal(createCall.thread_id,27);
  const earlyCalls=await page.evaluate(()=>window.__calls.map(c=>c.action));
  assert.ok(earlyCalls.indexOf('create')<earlyCalls.indexOf('cognition.configure'));
  assert.ok(earlyCalls.indexOf('cognition.configure')<earlyCalls.indexOf('start'),
    'Automatic review must be configured before execution starts');
  assert.equal(await page.locator('#chatForm').count(),1);
  await page.getByRole('button',{name:'Pause'}).click();
  await page.waitForFunction(()=>window.__calls.some(c=>c.action==='pause'));
  await page.getByRole('button',{name:'Resume (rerun interrupted)'}).click();
  await page.waitForFunction(()=>window.__calls.some(c=>c.action==='resume'));
  assert.equal((await page.evaluate(()=>window.__calls.find(c=>c.action==='resume'))).allow_reexecution,true);
  await page.evaluate(()=>{
    __mission.status='failed';__mission.tasks[0].status='failed';__mission.tasks[0].error='Transient error';
  });
  await page.getByRole('button',{name:'Refresh'}).click();
  await page.getByRole('button',{name:'Retry worker'}).click();
  await page.waitForFunction(()=>window.__calls.some(c=>c.action==='retry'));
  assert.equal((await page.evaluate(()=>window.__calls.find(c=>c.action==='retry'))).task_id,'22222222-2222-4222-8222-222222222222');
  await page.evaluate(()=>{
    __mission.status='completed';__mission.tasks[0].status='completed';
    __mission.tasks[0].error='';__mission.cognition_review=null;
  });
  await page.getByRole('button',{name:'Refresh'}).click();
  await page.getByRole('button',{name:'Evaluate for more specialists'}).click();
  await page.waitForFunction(()=>window.__calls.some(c=>c.action==='cognition.evaluate'));
  assert.equal(await page.getByRole('button',{name:'Approve specialists'}).count(),1);
  assert.equal(await page.locator('.vp3-teams-cognition img').count(),0,'LLM rationale must not inject HTML');
  await page.getByRole('button',{name:'Approve specialists'}).click();
  await page.waitForFunction(()=>window.__calls.some(c=>c.action==='cognition.decide'));
  const decision=await page.evaluate(()=>window.__calls.find(c=>c.action==='cognition.decide'));
  assert.equal(decision.approve,true);
  assert.equal(decision.review_id,'33333333-3333-4333-8333-333333333333');
  await page.evaluate(()=>{__mission.status='planned';__mission.cognition_auto_review=false;});
  await page.getByRole('button',{name:'Refresh'}).click();
  await page.getByRole('button',{name:'Enable auto review'}).click();
  await page.waitForFunction(()=>window.__calls.some(c=>c.action==='cognition.configure'));
  assert.equal((await page.evaluate(()=>window.__calls.find(c=>c.action==='cognition.configure'))).enabled,true);

  await page.evaluate(()=>{
    const drawer=document.createElement('aside');drawer.id='chatNotificationDrawer';
    drawer.innerHTML='<button data-notification-tab="brain" class="active">Brain</button><div data-notification-drawer-body></div>';
    document.body.appendChild(drawer);
    window.VP3_AGENT_TEAMS_A3_BRAIN(drawer.querySelector('[data-notification-drawer-body]'));
  });
  assert.match(await page.locator('[data-agent-teams-brain-a3]').textContent(),/HomeServer Agent Teams/);
  assert.match(await page.locator('[data-agent-teams-brain-a3]').textContent(),/Compare three hosting providers/);
  await page.evaluate(()=>{__mission.objective='<img src=x onerror="window.injected=true">';});
  await page.getByRole('button',{name:'Refresh'}).click();
  assert.equal(await page.locator('.vp3-teams-detail img').count(),0,'model output cannot inject HTML');
  assert.equal(await page.evaluate(()=>Boolean(window.injected)),false);
  await page.setViewportSize({width:375,height:720});
  const overflow=await page.evaluate(()=>document.documentElement.scrollWidth>window.innerWidth);
  assert.equal(overflow,false,'mission UI must not force mobile page-wide scrolling');
  assert.deepEqual(errors,[],'no browser JavaScript errors');
  console.log('AGENT_TEAMS_A4_BROWSER PASS: live Chat controls, real DOM, Brain drawer, CSRF, retry approval, XSS and responsive layout');
} finally {await browser.close();}
