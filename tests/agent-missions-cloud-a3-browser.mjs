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
    window.__workerBrowser=null;
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
      if(body.action==='create'){mission.objective=body.objective;mission.status='planned';mission.tasks[0].status='queued';mission.tasks[0].error='';}
      if(body.action==='start'||body.action==='resume'){mission.status='running';}
      if(body.action==='pause'){mission.status='waiting_review';}
      if(body.action==='retry'){mission.status='running';mission.tasks[0].status='running';}
      if(body.action==='cancel'){mission.status='cancelled';}
      if(body.action.startsWith('browser.')){
        if(body.action==='browser.grant')window.__workerBrowser={
          status:'approved',task_id:body.task_id,approved_origin:'https://example.com',
          current_url:body.url,visit_count:0,max_visits:5,image_base64:'',
          page_title:'',text_snapshot:''
        };
        if(body.action==='browser.capture'){
          window.__workerBrowser.visit_count++;
          window.__workerBrowser.page_title='Approved public page';
          window.__workerBrowser.text_snapshot='A bounded public page snapshot.';
          window.__workerBrowser.image_base64='/9j/'+'A'.repeat(160);
        }
        if(body.action==='browser.revoke'){
          window.__workerBrowser.status='closed';
          window.__workerBrowser.image_base64='';
          window.__workerBrowser.text_snapshot='';
        }
        return {ok:true,json:async()=>({ok:true,browser:window.__workerBrowser})};
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
  await page.locator('#vp3MissionProvider').selectOption('anthropic');
  await page.locator('.vp3-teams-create button').click();
  await page.waitForFunction(()=>window.__calls.some(c=>c.action==='start'));
  await page.waitForFunction(()=>document.querySelector('.vp3-teams-detail h3')?.textContent==='Compare three hosting providers');
  assert.equal(await page.locator('[data-agent-teams-a3]').count(),1,'no duplicate panel');
  const createCall=await page.evaluate(()=>window.__calls.find(c=>c.action==='create'));
  assert.equal(createCall.request_id,'a3-browser-request-001');
  assert.equal(createCall.thread_id,27);
  const a5Calls=await page.evaluate(()=>window.__calls.map(c=>c.action));
  assert.ok(a5Calls.indexOf('create')<a5Calls.indexOf('bind_provider'));
  assert.ok(a5Calls.indexOf('bind_provider')<a5Calls.indexOf('start'),
    'Worker providers must be bound before any worker starts');
  const a5Bind=await page.evaluate(()=>window.__calls.find(c=>c.action==='bind_provider'));
  assert.equal(a5Bind.provider_key,'anthropic');
  assert.equal(a5Bind.task_id,'22222222-2222-4222-8222-222222222222');

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
  // A5B: prepare a second mission, authorize a browser, capture and revoke.
  await page.locator('#vp3MissionObjective').fill('Inspect a public HTTPS report');
  await page.locator('.vp3-teams-browser-optin input[type=checkbox]').check();
  await page.locator('.vp3-teams-create button').click();
  await page.waitForFunction(()=>window.__mission.status==='planned');
  await page.getByText('Worker browser · supervised read-only').first().click();
  await page.locator('input[data-browser-task]').first().fill('https://example.com/reports');
  await page.getByRole('button',{name:'Approve URL'}).click();
  await page.waitForFunction(()=>window.__calls.some(c=>c.action==='browser.grant'));
  assert.equal((await page.evaluate(()=>window.__calls.find(c=>c.action==='browser.grant'))).url,
    'https://example.com/reports');
  assert.equal(await page.locator('.vp3-worker-browser[open]').count(),1,
    'Approving a browser must preserve its expanded controls');
  await page.getByRole('button',{name:'Capture page'}).click();
  await page.waitForFunction(()=>window.__calls.some(c=>c.action==='browser.capture'));
  assert.equal(await page.locator('.vp3-worker-browser-preview').count(),1);
  assert.match(await page.locator('.vp3-worker-browser').textContent(),/Approved public page/);
  await page.getByRole('button',{name:'Revoke'}).click();
  await page.waitForFunction(()=>window.__calls.some(c=>c.action==='browser.revoke'));
  assert.equal(await page.locator('.vp3-worker-browser-preview').count(),0,
    'Revoking browser erases the screenshot');
  const lastStart=await page.evaluate(()=>window.__calls.filter(c=>c.action==='start').length);
  assert.equal(lastStart,1,'Prepared mission must not execute without user Start');
  await page.setViewportSize({width:375,height:720});
  const overflow=await page.evaluate(()=>document.documentElement.scrollWidth>window.innerWidth);
  assert.equal(overflow,false,'mission UI must not force mobile page-wide scrolling');
  assert.deepEqual(errors,[],'no browser JavaScript errors');
  console.log('AGENT_TEAMS_A5B_BROWSER PASS: live Chat controls, real DOM, Brain drawer, CSRF, retry approval, XSS and responsive layout');
} finally {await browser.close();}
