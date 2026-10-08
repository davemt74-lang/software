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
    window.__liveBrowser=null;
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
      if(body.action.startsWith('browser.owner.')){
        if(body.action==='browser.owner.takeover')window.__liveBrowser.owner_takeover={
          mode:'owner',actions_used:0,max_actions:8,expires_at:'2026-10-08 07:00:00',
          forms:[{index:0,fingerprint:'bbbbbbbbbbbbbbbbbbbbbbbb',label:'Search',action:'https://example.com/search',method:'GET'}],
          pending_form:{}};
        if(body.action==='browser.owner.control'){
          window.__liveBrowser.owner_takeover.actions_used++;
          window.__liveBrowser.page_title='Owner searched safely';
        }
        if(body.action==='browser.owner.search.review')
          window.__liveBrowser.owner_takeover.pending_form={
            id:'77777777-7777-4777-8777-777777777777',
            index:0,fingerprint:'bbbbbbbbbbbbbbbbbbbbbbbb',method:'GET',
            action:'https://example.com/search'};
        if(body.action==='browser.owner.search.submit'){
          window.__liveBrowser.owner_takeover.actions_used++;
          window.__liveBrowser.owner_takeover.pending_form={};
          window.__liveBrowser.current_url='https://example.com/search?q=approved';
        }
        if(body.action==='browser.owner.release')
          window.__liveBrowser.owner_takeover.mode='agent';
        return {ok:true,json:async()=>({ok:true,live_browser:structuredClone(window.__liveBrowser)})};
      }
      if(body.action.startsWith('browser.action.')){
        if(body.action==='browser.action.propose'){
          window.__liveBrowser.proposed_action={
            id:'66666666-6666-4666-8666-666666666666',revision:window.__liveBrowser.revision,
            kind:'fill',index:0,fingerprint:'aabbcc112233445566778899',
            label:'Search topic <img src=x onerror="window.injected=true">',
            reason:'Enter a short user-provided topic.',options:[]
          };
        }
        if(body.action==='browser.action.approve'){
          window.__liveBrowser.proposed_action={};
          window.__liveBrowser.actions_used++;
          window.__liveBrowser.revision++;
        }
        return {ok:true,json:async()=>({ok:true,live_browser:structuredClone(window.__liveBrowser)})};
      }
      if(body.action.startsWith('browser.live.')){
        if(body.action==='browser.live.start')window.__liveBrowser={
          task_id:body.task_id,status:'live',revision:1,mode:'live_read_only',
          session_active:true,visit_count:1,max_visits:5,
          current_url:'https://example.com/reports',page_title:'Research report',
          image_base64:'/9j/'+'A'.repeat(160),page_text:'First page evidence.',
          proposed_link:{},proposed_action:{},actions_used:0,max_actions:6,
          controls:[{index:0,fingerprint:'aaaaaaaaaaaaaaaaaaaaaaaa',label:'Search',kind:'fill'}],
          owner_takeover:{mode:'agent',actions_used:0,max_actions:8,forms:[],pending_form:{}}
        };
        if(body.action==='browser.live.refresh'){
          window.__liveBrowser.image_base64='/9j/'+'B'.repeat(160);
        }
        if(body.action==='browser.live.propose'){
          window.__liveBrowser.proposed_link={
            id:'55555555-5555-4555-8555-555555555555',
            title:'Second report',url:'https://example.com/report2',
            reason:'The agent recommends a related read-only page.',revision:1
          };
        }
        if(body.action==='browser.live.approve'){
          window.__liveBrowser.revision++;
          window.__liveBrowser.visit_count++;
          window.__liveBrowser.current_url=window.__liveBrowser.proposed_link.url;
          window.__liveBrowser.page_title='Second report';
          window.__liveBrowser.proposed_link={};
        }
        if(body.action==='browser.live.stop'){
          window.__liveBrowser.status='stopped';
          window.__liveBrowser.session_active=false;
        }
        return {ok:true,json:async()=>({ok:true,live_browser:structuredClone(window.__liveBrowser)})};
      }
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
  await page.getByRole('button',{name:'Start live browser'}).click();
  await page.waitForFunction(()=>window.__calls.some(c=>c.action==='browser.live.start'));
  assert.equal(await page.locator('.vp3-worker-live-preview').count(),1,
    'Live browser must be visible in existing Chat canvas');
  await page.getByRole('button',{name:'Ask agent for next link'}).click();
  await page.waitForFunction(()=>window.__calls.some(c=>c.action==='browser.live.propose'));
  assert.match(await page.locator('.vp3-worker-live').textContent(),/Second report/);
  assert.equal((await page.evaluate(()=>window.__calls.filter(c=>c.action==='browser.live.approve').length)),0,
    'Model suggestion must not auto-navigate');
  await page.getByRole('button',{name:'Approve suggested navigation'}).click();
  await page.waitForFunction(()=>window.__calls.some(c=>c.action==='browser.live.approve'));
  const liveDecision=await page.evaluate(()=>window.__calls.find(c=>c.action==='browser.live.approve'));
  assert.equal(liveDecision.proposal_id,'55555555-5555-4555-8555-555555555555');
  assert.equal(liveDecision.confirmed,true);
  assert.match(await page.locator('.vp3-worker-live').textContent(),/https:\/\/example.com\/report2/);
  await page.getByRole('button',{name:'Ask agent about page controls'}).click();
  await page.waitForFunction(()=>window.__calls.some(c=>c.action==='browser.action.propose'));
  assert.equal(await page.locator('.vp3-dom-approval img').count(),0,'Model label must not inject HTML');
  assert.equal(await page.evaluate(()=>window.__calls.filter(c=>c.action==='browser.action.approve').length),0,
    'Agent choice must not trigger browser action');
  await page.locator('.vp3-dom-approval-value').fill('User-entered research topic');
  await page.getByRole('button',{name:'Approve safe control'}).click();
  await page.waitForFunction(()=>window.__calls.some(c=>c.action==='browser.action.approve'));
  const approvedAction=await page.evaluate(()=>window.__calls.find(c=>c.action==='browser.action.approve'));
  assert.equal(approvedAction.proposal_id,'66666666-6666-4666-8666-666666666666');
  assert.equal(approvedAction.value,'User-entered research topic');
  assert.equal(approvedAction.confirmed,true);
  assert.equal(await page.locator('.vp3-dom-approval').count(),0);
  await page.getByRole('button',{name:'Take control'}).click();
  await page.waitForFunction(()=>window.__calls.some(c=>c.action==='browser.owner.takeover'));
  assert.match(await page.locator('.vp3-owner-takeover').textContent(),/Owner controlling browser/);
  assert.equal(await page.getByRole('button',{name:'Ask agent for next link'}).count(),0,
    'Agent navigation must pause during takeover');
  await page.locator('[data-owner-value-task]').fill('safe study');
  await page.getByRole('button',{name:'Apply owner control'}).click();
  await page.waitForFunction(()=>window.__calls.some(c=>c.action==='browser.owner.control'));
  const control=await page.evaluate(()=>window.__calls.find(c=>c.action==='browser.owner.control'));
  assert.equal(control.value,'safe study');
  assert.equal(control.confirmed,true);
  await page.getByRole('button',{name:'Review GET search'}).click();
  await page.waitForFunction(()=>window.__calls.some(c=>c.action==='browser.owner.search.review'));
  assert.match(await page.locator('.vp3-owner-takeover').textContent(),/Search destination/);
  await page.getByRole('button',{name:'Confirm search submission'}).click();
  await page.waitForFunction(()=>window.__calls.some(c=>c.action==='browser.owner.search.submit'));
  const search=await page.evaluate(()=>window.__calls.find(c=>c.action==='browser.owner.search.submit'));
  assert.equal(search.proposal_id,'77777777-7777-4777-8777-777777777777');
  assert.equal(search.confirmed,true);
  assert.match(await page.locator('.vp3-worker-live').textContent(),/search\?q=approved/);
  const lastBrowserText=await page.locator('.vp3-owner-takeover').textContent();
  assert.match(lastBrowserText,/Owner controlling/);
  await page.getByRole('button',{name:'Return control to agent'}).click();
  await page.waitForFunction(()=>window.__calls.some(c=>c.action==='browser.owner.release'));
  assert.equal(await page.getByRole('button',{name:'Ask agent for next link'}).count(),1,
    'Agent must regain safe controls only after owner releases');
  await page.getByRole('button',{name:'Stop live browser'}).click();
  await page.waitForFunction(()=>window.__calls.some(c=>c.action==='browser.live.stop'));
  assert.equal(await page.getByRole('button',{name:'Stop live browser'}).count(),0);
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
  console.log('AGENT_TEAMS_A5B4_BROWSER PASS: live Chat controls, real DOM, Brain drawer, CSRF, retry approval, XSS and responsive layout');
} finally {await browser.close();}
