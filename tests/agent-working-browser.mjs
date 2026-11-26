import assert from 'node:assert/strict';
import fs from 'node:fs';
import http from 'node:http';
import path from 'node:path';
import {createRequire} from 'node:module';

const {chromium}=createRequire(import.meta.url)(process.env.VP3_PLAYWRIGHT_MODULE||'playwright');
const assets=new Set(['agent-eyes-core.js','agent-orb.js','agent-orb.css','agent-orb-particles.js','agent-orb-particles.css','chat-specialist-rail-v1.js','chat-specialist-rail-v1.css','chat-mobile-specialists-v1.js']);
let workMode='active';
const workRequests=[],pendingWork=[];
const server=http.createServer((req,res)=>{
  const url=new URL(req.url,'http://localhost'),file=path.basename(url.pathname);
  if(url.pathname==='/api/top-of-mind'){
    const scope=url.searchParams.get('scope'),cid=url.searchParams.get('conversation_id');
    workRequests.push({method:req.method,view:url.searchParams.get('view'),agent:url.searchParams.get('agent_id'),binding:req.headers['x-vp3-working-attention'],scope,cid});
    const mode=workMode;
    const reply=()=>{
      res.setHeader('Content-Type','application/json');
      if(mode==='forbidden'){res.statusCode=403;res.end('{"ok":false}');return;}
      if(mode==='unavailable'){res.statusCode=503;res.end('{"ok":false}');return;}
      const state={contract:'vp3.agent.work.v1',owner_user_id:1,agent_id:7,conversation_id:42,working_attention_binding:'private-session',
        observed_at:new Date(Date.now()-(mode==='stale'?60000:0)).toISOString(),
        health:{state:mode==='idle'?'waiting':'running',enabled:true,active_jobs:mode==='idle'?[]:[{id:'workflow:21',kind:'assessment',step:'Brainstorming useful opportunities',expiresAt:Date.now()+60000}]}};
      if(mode==='gmail'){
        state.health={state:'idle',enabled:false,active_jobs:[]};
        state.gmail_job={id:'gmail:inbox-updates',expiresAt:Date.now()+60000};
      }
      if(mode==='owner')state.owner_user_id=2;
      if(mode==='agent')state.agent_id=8;
      if(mode==='binding')state.working_attention_binding='other';
      if(scope==='chat'){
        state.conversation_id=mode==='specialists-mismatch'?Number(cid)+1:Number(cid);
        const working=mode==='specialists-working'||mode==='hold';
        if(mode.startsWith('specialists-'))state.health={state:working?'running':'idle',enabled:true,
          active_jobs:working?[{id:'workflow:21',kind:'preparation',expiresAt:Date.now()+60000}]:[]};
        state.specialists=[
          {id:'planning',state:working?'working':'waiting_specialist',detail:working?'Checking meeting availability.':'Waiting for research.',
            progress:working?40:null,lease_expires_at:new Date(Date.now()+60000).toISOString(),conversation_id:state.conversation_id},
          {id:'tasks',state:'paused',detail:'Task review is paused.'},
          {id:'gmail',state:'idle',detail:'Monitoring is scheduled.'},
          {id:'research',state:'ready',detail:'Research is ready.'}
        ];
        if(mode==='specialists-configured')state.specialists=state.specialists.filter(item=>item.id!=='research');
      }
      res.end(JSON.stringify({ok:true,state}));
    };
    if(mode==='hold')pendingWork.push(reply);else reply();
    return;
  }
  if(assets.has(file)){
    res.setHeader('Content-Type',file.endsWith('.css')?'text/css':'text/javascript');
    res.end(fs.readFileSync(file));return;
  }
  const chat=url.pathname==='/chat';
  res.setHeader('Content-Type','text/html');
  res.end(`<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
    <link rel="stylesheet" href="/agent-orb.css"><link rel="stylesheet" href="/agent-orb-particles.css"><link rel="stylesheet" href="/chat-specialist-rail-v1.css">
    <style>body{margin:0;background:#f8f8f8}main{padding:24px}#chatComposerShell{position:fixed;bottom:0;left:24px;right:24px;padding:12px;background:white}#chatForm{display:flex}#chatInput{flex:1;min-width:0}#sfOnlineRailV109{position:fixed;right:0;top:0}</style>
    <main><h1>${chat?'Agent Chat':'Workspace'}</h1>${chat?`<section id="chatThread" data-view="chat"></section><div id="chatComposerShell"><form id="chatForm"><textarea id="chatInput" aria-label="Message"></textarea><button id="chatVoiceButton" type="button">Listen</button><button id="sendChatButton">Send</button></form></div><aside id="sfOnlineRailV109"><div id="sfChatSettingsRailSlotV132"></div></aside>
      <button id="openChatSidebar" aria-label="Open menu" type="button">Menu</button><aside id="chatSidebar"><div class="vp3-chat-mobile-tabs"><button id="home-tab" data-chat-mobile-tab="home">Home</button><button id="specialists-tab" data-chat-mobile-tab="specialists">Specialists</button></div><div id="chatMobileHomePanel"></div><section data-chat-mobile-specialists><ul data-chat-specialist-mobile-list></ul><p data-chat-specialist-mobile-empty>No specialists</p></section><button id="closeChatSidebar" type="button">Close</button></aside>`:''}</main>
    <script>
      window.foreground='idle';window.listenCalls=0;window.stopCalls=0;window.sent=0;window.selected=null;window.hubOpened=[];window.specialistOpened=[];
      window.VP3ParticipantContinuity={selectedContext:()=>window.selected,snapshot:()=>null,clearSelection:()=>{},setSelection:value=>{window.selected=value;},updatePresence:()=>{}};
      window.VP3AgentActivityHub={open:options=>{window.hubOpened.push(options);return true;},
        openSpecialist:key=>{window.specialistOpened.push(key);return key!=='ob1';}};
      window.VP3_ORB_CONFIG={ownerKey:'1',agentId:7,workingAttentionBinding:'private-session',shape:'orbit',topOfMindEndpoint:${url.searchParams.has('work')?"'/api/top-of-mind'":"''"}};
      ${url.searchParams.has('configured')?`window.VP3_CHAT_SPECIALIST_PRESENCE={owner_user_id:${url.searchParams.get('configured')==='other'?2:1},agent_id:7,states:{media:'idle',research:'idle','image-generator':'idle',coding:'idle',development:'paused',mission:'idle'}};`:''}
      ${chat?`window.STONEFELLOW_CHAT={userId:1,agentId:7,workingAttentionBinding:'private-session'};
      window.conversation=42;window.restoring=false;
      window.STONEFELLOW_CHAT_CONTINUITY={isReady:()=>true,isRestoring:()=>window.restoring,conversationId:()=>window.conversation,agentId:()=>7};
      document.getElementById('openChatSidebar').addEventListener('click',()=>document.body.classList.add('chat-nav-open'));
      document.getElementById('closeChatSidebar').addEventListener('click',()=>document.body.classList.remove('chat-nav-open'));
      document.getElementById('chatForm').addEventListener('submit',event=>{event.preventDefault();window.sent++;});
      document.getElementById('chatVoiceButton').addEventListener('click',()=>window.VP3_ORB_ADAPTER.listen());`:''}
      window.VP3_ORB_ADAPTER={ownerKey:'1',chatUrl:'/chat',state:()=>({state:window.foreground}),
        conversationRef:()=>(${chat?'window.restoring?null:{id:window.conversation,agent:7}':'null'}),conversationUrl:ref=>'/chat?conversation_id='+ref.id,
        isMainChat:()=>${chat},focusChat:()=>document.getElementById('chatInput')?.focus(),busy:()=>false,
        listen:()=>{window.listenCalls++;window.foreground='listening';},stop:()=>{window.stopCalls++;window.foreground='idle';}};
    </script>
    <script src="/agent-eyes-core.js"></script><script src="/chat-specialist-rail-v1.js"></script><script src="/agent-orb.js"></script><script src="/agent-orb-particles.js"></script><script src="/chat-mobile-specialists-v1.js"></script>`);
});
await new Promise(resolve=>server.listen(0,'127.0.0.1',resolve));
const url=`http://127.0.0.1:${server.address().port}`;
const browser=await chromium.launch({headless:true,args:['--no-sandbox'],executablePath:process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH||undefined});
const errors=[];
let checks=0;
const check=async(name,run)=>{await run();checks++;console.log('PASS '+name);};
async function pageAt(route='/chat',options={}){
  const {clock=false,...browserOptions}=options;
  const page=await browser.newPage({viewport:{width:1280,height:850},...browserOptions});
  if(clock)await page.clock.install();
  page.on('pageerror',error=>{errors.push(error.message);if(process.env.VP3_TEST_DEBUG)console.error(error.message);});
  await page.goto(url+route);
  await page.waitForFunction(()=>!!window.VP3AgentOrb);
  return page;
}
const activeJob=()=>({id:'workflow:21',kind:'assessment',step:'Brainstorming useful opportunities',expiresAt:Date.now()+60000});
try{
  const main=await pageAt();
  await check('Working stays independent while the main Agent listens, replies and accepts text',async()=>{
    await main.locator('#chatInput').fill('Keep this draft');
    await main.locator('#chatInput').focus();
    await main.evaluate(job=>VP3AgentOrb.setJobs('initiative',[job]),activeJob());
    await main.locator('[data-working="true"] [data-orb-work-tracks="1"]').waitFor();
    assert.equal(await main.locator('[data-orb-voice-state]').textContent(),'Working');
    assert.equal(await main.locator('#chatInput').inputValue(),'Keep this draft');
    assert.equal(await main.locator('#chatInput').evaluate(node=>node===document.activeElement),true);
    assert.deepEqual(await main.evaluate(()=>[listenCalls,stopCalls,hubOpened.length]),[0,0,0]);
    await main.locator('#chatVoiceButton').click();
    await main.waitForFunction(()=>document.querySelector('.vp3-orb-root').dataset.state==='listening');
    assert.equal(await main.locator('[data-orb-voice-state]').textContent(),'Listening · Working');
    assert.equal(await main.evaluate(()=>VP3AgentOrb.state().working),true);
    const before=await main.locator('[data-orb-work-tracks="1"]').evaluate(node=>node.toDataURL());
    await main.waitForFunction(previous=>document.querySelector('[data-orb-work-tracks="1"]').toDataURL()!==previous,before);
    if(process.env.VP3_TEST_SCREENSHOT)await main.screenshot({path:process.env.VP3_TEST_SCREENSHOT});
    await main.locator('#sendChatButton').click();
    assert.equal(await main.evaluate(()=>sent),1);
    for(const primary of ['thinking','speaking','error','idle']){
      await main.evaluate(value=>window.foreground=value,primary);
      await main.waitForFunction(value=>document.querySelector('.vp3-orb-root').dataset.state===value,primary);
      assert.equal(await main.locator('.vp3-orb-root').getAttribute('data-working'),'true');
      assert.equal(await main.locator('[data-orb-work-tracks="1"]').count(),1);
    }
  });
  await check('A real assessment opens current work in the shared rail and closes when it stops',async()=>{
    const specialist=main.locator('[data-chat-specialist="ob1"]');
    const panel=main.getByRole('dialog',{name:'OB1'});
    await specialist.waitFor();
    assert.deepEqual(await main.evaluate(()=>VP3ChatSpecialists.snapshot().map(item=>[item.key,item.label,item.status,item.detail,item.canView])),[
      ['ob1','OB1','Working','Brainstorming useful opportunities',false]
    ]);
    assert.equal(await main.locator('.vp3-chat-specialist-panel').count(),0);
    assert.equal(await main.evaluate(()=>hubOpened.length),0);
    await specialist.click();
    await panel.waitFor();
    assert.equal(await specialist.getAttribute('aria-expanded'),'true');
    assert.match(await panel.textContent(),/Specialist coordination/);
    assert.match(await panel.textContent(),/Brainstorming useful opportunities/);
    assert.deepEqual(await main.evaluate(()=>hubOpened),[]);
    await main.evaluate(job=>VP3AgentOrb.setJobs('initiative',[job]),{
      ...activeJob(),step:'Reviewing the current plan'
    });
    await panel.getByText('Reviewing the current plan',{exact:true}).waitFor();
    assert.equal(await panel.getByText('Brainstorming useful opportunities',{exact:true}).count(),0);
    assert.equal(await panel.getByRole('button',{name:'Open Top of Mind'}).count(),0);
    assert.deepEqual(await main.evaluate(()=>specialistOpened),['ob1']);
    await panel.getByRole('button',{name:'Close',exact:true}).click();
    await specialist.click();
    await main.evaluate(()=>{
      VP3AgentOrb.setJobs('mission',[{id:'mission:live',expiresAt:Date.now()+10000}]);
      VP3AgentOrb.setJobs('initiative',[]);
    });
    await specialist.waitFor({state:'detached'});
    await panel.waitFor({state:'detached'});
    assert.equal(await main.evaluate(()=>VP3AgentOrb.isWorking()),true);
    await main.evaluate(()=>VP3AgentOrb.setJobs('mission',[]));
    assert.equal(await main.evaluate(()=>VP3AgentOrb.isWorking()),false);
    const listensBefore=await main.evaluate(()=>listenCalls);
    await main.locator('.vp3-orb-ball').dblclick();
    assert.deepEqual(await main.evaluate(()=>hubOpened),[undefined]);
    assert.equal(await main.evaluate(()=>listenCalls),listensBefore);
    assert.equal(await main.locator('#vp3AgentChatPopup').isVisible(),false);
  });
  await check('Creative specialist assessments do not impersonate OB1 or remove Brainstorming work',async()=>{
    await main.evaluate(()=>VP3ChatSpecialists.update('brainstorm',{
      label:'Brainstorming',status:'Working',detail:'Exploring a useful image idea.'
    }));
    for(const specialist of ['app-suggestions','research','brainstorm']){
      await main.evaluate(job=>VP3AgentOrb.setJobs('initiative',[job]),{...activeJob(),specialist});
      assert.equal(await main.evaluate(()=>VP3AgentOrb.isWorking()),true);
      assert.equal(await main.locator('[data-chat-specialist="ob1"]').count(),0);
      assert.equal(await main.locator('[data-chat-specialist="brainstorm"]').count(),1);
    }
    await main.evaluate(()=>{
      VP3ChatSpecialists.remove('brainstorm');
      VP3AgentOrb.setJobs('initiative',[]);
    });
  });
  await check('Terminal, expired, invalid and paused work cannot leave a Working dot behind',async()=>{
    await main.evaluate(()=>{
      window.foreground='listening';
      VP3AgentOrb.setJobs('initiative',[{id:'workflow:30',kind:'assessment',expiresAt:Date.now()+1000}]);
    });
    await main.locator('[data-working="true"]').waitFor();
    await main.waitForFunction(()=>document.querySelector('.vp3-orb-root').dataset.state==='listening');
    await main.waitForFunction(()=>!VP3AgentOrb.isWorking());
    assert.equal(await main.evaluate(()=>VP3AgentOrb.isWorking()),false);
    assert.equal(await main.locator('[data-orb-voice-state]').textContent(),'Listening');
    assert.equal(await main.locator('[data-chat-specialist="ob1"]').count(),0);
    await main.evaluate(()=>VP3AgentOrb.setJobs('initiative',[
      {id:'workflow:31',kind:'assessment',expiresAt:Date.now()-1},
      {id:'image:wrong-source',expiresAt:Date.now()+10000},
      {id:'workflow:32',expiresAt:'tomorrow'}
    ]));
    assert.equal(await main.locator('[data-orb-work-tracks]').count(),0);
    await main.evaluate(()=>VP3AgentOrb.setJobs('initiative',[{id:'workflow:33',kind:'assessment',expiresAt:Date.now()+1000}]));
    await main.locator('[data-working="true"]').waitFor();
    await main.evaluate(()=>VP3AgentOrb.setJobs('initiative',[]));
    assert.equal(await main.locator('.vp3-orb-root').getAttribute('data-working'),'false');
    assert.equal(await main.locator('[data-orb-voice-state]').textContent(),'Listening');
  });
  await check('Permission, account and Agent changes immediately remove private work',async()=>{
    for(const change of ['participant','binding','agent','owner']){
      const page=await pageAt();
      try{
        await page.evaluate(job=>VP3AgentOrb.setJobs('initiative',[job]),activeJob());
        await page.locator('[data-working="true"]').waitFor();
        await page.locator('[data-chat-specialist="ob1"]').click();
        const panel=page.getByRole('dialog',{name:'OB1'});
        await panel.waitFor();
        await page.evaluate(kind=>{
          if(kind==='participant'){window.selected={participant_id:8};dispatchEvent(new Event('vp3:participant-selection-changed'));}
          if(kind==='binding')STONEFELLOW_CHAT.workingAttentionBinding='new-session';
          if(kind==='agent')STONEFELLOW_CHAT.agentId=8;
          if(kind==='owner')VP3_ORB_CONFIG.ownerKey='2';
        },change);
        await page.waitForFunction(()=>!VP3AgentOrb.isWorking());
        await panel.waitFor({state:'detached'});
        await page.evaluate(job=>VP3AgentOrb.setJobs('initiative',[job]),activeJob());
        assert.equal(await page.evaluate(()=>VP3AgentOrb.isWorking()),false);
        assert.equal(await page.locator('[data-chat-specialist="ob1"]').count(),0);
      }finally{await page.close();}
    }
  });
  await main.close();
  await check('Embedded work propagates while closed and survives listening without accepting stale scope',async()=>{
    const page=await pageAt('/workspace');
    try{
      await page.evaluate(()=>VP3AgentOrb.open());
      const frame=await page.locator('iframe').elementHandle().then(node=>node.contentFrame());
      await frame.waitForFunction(()=>!!window.VP3AgentOrb);
      await frame.evaluate(job=>VP3AgentOrb.setJobs('initiative',[job]),activeJob());
      await page.locator('[data-working="true"]').waitFor();
      await frame.evaluate(()=>window.foreground='listening');
      await page.waitForFunction(()=>document.querySelector('.vp3-orb-root').dataset.state==='listening');
      assert.equal(await page.locator('[data-orb-voice-state]').textContent(),'Listening · Working');
      await page.getByRole('button',{name:'Close Agent Chat',exact:true}).click();
      assert.equal(await page.evaluate(()=>VP3AgentOrb.isWorking()),true);
      assert.equal(await page.evaluate(()=>VP3AgentOrb.activitySpecialists().some(item=>item.key==='ob1')),true);
      await frame.evaluate(()=>dispatchEvent(new Event('stonefellow:chat-context-changing')));
      await page.waitForFunction(()=>!VP3AgentOrb.isWorking());
      assert.equal(await page.evaluate(()=>VP3AgentOrb.activitySpecialists().some(item=>item.key==='ob1')),false);
      for(const patch of [{epoch:0},{agent_id:8},{ownerKey:'2'},{working_attention_binding:'other'},{participant:true}]){
        await frame.evaluate(patch=>parent.postMessage({channel:'vp3-orb',kind:'jobs',ownerKey:'1',source:'initiative',jobs:[{id:'workflow:90',kind:'assessment',expiresAt:Date.now()+30000}],activity_context:{ownerKey:'1',agent_id:7,conversation_id:42,working_attention_binding:'private-session',ready:true,participant:false,epoch:1,...patch}},location.origin),patch);
        await page.waitForTimeout(30);
        assert.equal(await page.evaluate(()=>VP3AgentOrb.isWorking()),false);
      }
      await frame.evaluate(job=>VP3AgentOrb.setJobs('initiative',[job]),activeJob());
      await page.locator('[data-working="true"]').waitFor();
      await frame.evaluate(()=>VP3AgentOrb.setJobs('initiative',[]));
      await page.waitForFunction(()=>!VP3AgentOrb.isWorking());
    }finally{await page.close();}
  });
  for(const shape of ['orbit','particle_face']){
    await check(`Mobile ${shape} keeps reduced-motion work dots static while listening`,async()=>{
      const page=await pageAt('/chat',{viewport:{width:390,height:844},reducedMotion:'reduce',hasTouch:true});
      try{
        await page.evaluate(({shape,job})=>{
          dispatchEvent(new CustomEvent('vp3:agent-orb-shape',{detail:{shape}}));
          window.foreground='listening';VP3AgentOrb.setJobs('initiative',[job]);
        },{shape,job:activeJob()});
        const tracks=page.locator('[data-orb-work-tracks="1"]');
        await tracks.waitFor();
        await page.waitForFunction(()=>document.querySelector('.vp3-orb-root').dataset.state==='listening');
        assert.equal(await page.locator('[data-vp3-orb-work]').getAttribute('data-orb-shape'),shape);
        const before=await tracks.evaluate(node=>node.toDataURL());
        await page.waitForTimeout(150);
        assert.equal(await tracks.evaluate(node=>node.toDataURL()),before);
        assert.equal(await page.locator('[data-orb-voice-state]').textContent(),'Listening · Working');
        const box=await tracks.boundingBox();
        assert.ok(box.x>=0&&box.y>=0&&box.x+box.width<=390&&box.y+box.height<=844);
        assert.equal(await page.locator('.vp3-chat-specialist-panel').count(),0);
      }finally{await page.close();}
    });
  }
  await check('Global work polls only the small verified projection and creates no Chat iframe',async()=>{
    workMode='active';
    const page=await pageAt('/workspace?work=1',{clock:true});
    try{
      await page.locator('[data-working="true"]').waitFor();
      assert.equal(await page.locator('iframe').count(),0);
      assert.equal(await page.locator('[data-orb-voice-state]').textContent(),'Working');
      await page.evaluate(()=>window.foreground='listening');
      await page.clock.fastForward(220);
      assert.equal(await page.locator('[data-orb-voice-state]').textContent(),'Listening · Working');
      workMode='idle';
      await page.clock.fastForward(2600);
      await page.waitForFunction(()=>!VP3AgentOrb.isWorking());
      assert.equal(await page.locator('[data-orb-voice-state]').textContent(),'Listening');
    }finally{await page.close();}
    const before=workRequests.length,chat=await pageAt('/chat?work=1',{clock:true});
    try{
      await chat.locator('[data-chat-specialist="planning"]').waitFor();
      assert.deepEqual(workRequests.slice(before).map(({scope,cid})=>[scope,cid]),[['chat','42']]);
    }finally{await chat.close();}
    assert.ok(workRequests.every(row=>row.method==='GET'&&row.view==='work'&&row.agent==='7'&&row.binding==='private-session'));
  });
  await check('Desktop specialist presence follows verified phases and opens its own activity',async()=>{
    workMode='specialists-working';
    const page=await pageAt('/chat?work=1',{clock:true});
    try{
      const specialist=id=>page.locator(`[data-chat-specialist="${id}"]`);
      await specialist('planning').waitFor();
      await specialist('tasks').waitFor();
      assert.equal(await specialist('planning').getAttribute('aria-label'),'Planning & Scheduling · Working');
      assert.equal(await specialist('tasks').getAttribute('aria-label'),'Tasks & Lists · Paused');
      assert.equal(await specialist('tasks').evaluate(node=>getComputedStyle(node,'::after').display),'none');
      assert.equal(await page.evaluate(()=>VP3ChatSpecialists.snapshot({includeResults:true}).find(item=>item.key==='planning').progress),40);
      assert.equal(await page.evaluate(()=>VP3AgentOrb.isWorking()),false,'specialist preparation stays on its own indicator when no generic assessment is active');
      await specialist('planning').click();
      assert.deepEqual(await page.evaluate(()=>[specialistOpened,hubOpened]),[['planning'],[]]);
      assert.equal(await page.locator('.vp3-chat-specialist-panel').count(),0);

      await page.evaluate(()=>{
        VP3ChatSpecialists.update('gmail-inbox-review',{label:'Gmail Inbox Review'});
        VP3ChatSpecialists.update('research:45',{label:'Research Specialist'});
      });
      await specialist('gmail').waitFor({state:'hidden'});
      await specialist('research').waitFor({state:'hidden'});
      await page.evaluate(()=>{VP3ChatSpecialists.remove('gmail-inbox-review');VP3ChatSpecialists.remove('research:45');});
      await specialist('gmail').waitFor();
      await specialist('research').waitFor();

      workMode='specialists-idle';
      await page.clock.fastForward(2600);
      await page.locator('[data-chat-specialist="planning"][data-chat-specialist-state="waiting_specialist"]').waitFor();
      assert.equal(await specialist('planning').evaluate(node=>getComputedStyle(node,'::after').display),'none');
      assert.equal(await page.evaluate(()=>VP3AgentOrb.isWorking()),false,'waiting specialists without an active lease cannot keep the shared indicator working');
    }finally{await page.close();}
  });
  await check('Mobile specialists stay in the menu, pulse only during work and open their own activity',async()=>{
    workMode='specialists-working';
    const page=await pageAt('/chat?work=1',{viewport:{width:390,height:844},clock:true});
    try{
      const dot=page.locator('[data-chat-specialist-dot]');
      await dot.waitFor();
      assert.equal(await page.locator('[data-chat-specialist-notice]').count(),0);
      await page.locator('#openChatSidebar').click();
      assert.equal(await dot.isVisible(),false);
      await page.locator('#specialists-tab').click();
      const tasks=page.locator('[data-chat-mobile-specialist="tasks"]');
      await tasks.getByText('Paused',{exact:true}).waitFor();
      await tasks.getByRole('button',{name:'Open activity'}).click();
      assert.deepEqual(await page.evaluate(()=>[specialistOpened,hubOpened]),[['tasks'],[]]);
      assert.equal(await page.evaluate(()=>document.body.classList.contains('chat-nav-open')),false);
      workMode='specialists-idle';
      await page.clock.fastForward(2600);
      await dot.waitFor({state:'hidden'});
      await page.locator('#openChatSidebar').click();
      await page.locator('[data-chat-mobile-specialist="planning"]').getByText('Waiting for another specialist',{exact:true}).waitFor();
    }finally{await page.close();}
  });
  await check('Configured browser specialists show idle or paused and give precedence to verified work',async()=>{
    workMode='specialists-configured';
    const page=await pageAt('/chat?work=1&configured=1');
    try{
      const specialist=id=>page.locator(`[data-chat-specialist="${id}"]`);
      for(const id of ['media','research','image-generator','coding','development:idle','mission']){
        await specialist(id).waitFor();
        assert.equal(await specialist(id).getAttribute('data-chat-specialist-state'),id==='development:idle'?'paused':'idle');
        assert.equal(await specialist(id).evaluate(node=>getComputedStyle(node,'::after').display),'none');
      }
      assert.equal(await page.evaluate(()=>VP3ChatSpecialists.snapshot().length),0);
      await specialist('media').click();
      await specialist('development:idle').click();
      assert.deepEqual(await page.evaluate(()=>[specialistOpened,hubOpened]),[['media','development:idle'],[]]);
      await page.evaluate(()=>VP3ChatSpecialists.update('development',{label:'Agent Development',status:'Working'}));
      await specialist('development:idle').waitFor({state:'hidden'});
      await specialist('development').waitFor();
      await page.evaluate(()=>VP3ChatSpecialists.remove('development'));
      await specialist('development:idle').waitFor();
    }finally{await page.close();}
    const other=await pageAt('/chat?work=1&configured=other');
    try{
      await other.locator('[data-chat-specialist="planning"]').waitFor();
      assert.equal(await other.locator('[data-chat-specialist="media"],[data-chat-specialist="coding"],[data-chat-specialist="image-generator"],[data-chat-specialist="mission"]').count(),0);
    }finally{await other.close();}
  });
  await check('Rail launchers survive loading, an unavailable refresh, and an expired work lease',async()=>{
    workMode='hold';
    const page=await pageAt('/chat?work=1&configured=1',{clock:true});
    try{
      const coding=page.locator('[data-chat-specialist="coding"]');
      await coding.waitFor();
      const node=await coding.elementHandle();
      assert.equal(await coding.getAttribute('aria-label'),'Coding Agent · Status unavailable');
      await coding.click();
      assert.deepEqual(await page.evaluate(()=>specialistOpened),['coding']);
      for(let attempt=0;!pendingWork.length;attempt++){
        if(attempt>100)throw Error('Chat specialist poll did not reach its controlled response.');
        await new Promise(resolve=>setTimeout(resolve,10));
      }
      assert.equal(pendingWork.length,1);
      pendingWork.shift()();
      await coding.waitFor();
      await page.locator('[data-chat-specialist="planning"][data-chat-specialist-state="working"]').waitFor();
      workMode='unavailable';
      await page.clock.fastForward(2700);
      await page.locator('[data-chat-specialist="planning"][data-chat-specialist-state="unavailable"]').waitFor();
      assert.equal(await page.evaluate(el=>el.isConnected,node),true);
      assert.equal(await page.evaluate(()=>VP3ChatSpecialists.snapshot().some(row=>row.key==='planning')),false);
      await page.evaluate(()=>{
        VP3ChatSpecialists.update('coding',{label:'Coding Agent',presence:true,keepLauncher:true,
          state:'working',status:'Working',detail:'Private work in progress',expiresAt:Date.now()+600});
      });
      await page.clock.fastForward(650);
      assert.equal(await page.evaluate(el=>el.isConnected,node),true);
      assert.deepEqual(await page.evaluate(()=>VP3ChatSpecialists.snapshot({includeResults:true})
        .filter(row=>row.key==='coding').map(({state,detail})=>[state,detail])),[['unavailable','']]);
      await page.evaluate(()=>{
        window.selected={participant_id:8};dispatchEvent(new Event('vp3:participant-selection-changed'));
      });
      await coding.waitFor({state:'detached'});
      assert.deepEqual(await page.evaluate(()=>VP3ChatSpecialists.snapshot({includeResults:true})),[]);
    }finally{while(pendingWork.length)pendingWork.shift()();await page.close();}
  });
  await check('Chat specialist presence rejects mismatched or late work and scopes a fresh Chat to zero',async()=>{
    workMode='hold';
    const page=await pageAt('/chat?work=1');
    try{
      await page.waitForFunction(()=>!!window.VP3ChatSpecialists);
      for(let attempt=0;!pendingWork.length;attempt++){
        if(attempt>100)throw Error('Chat specialist poll did not reach its controlled response.');
        await new Promise(resolve=>setTimeout(resolve,10));
      }
      await page.evaluate(()=>{
        window.restoring=true;
        dispatchEvent(new Event('stonefellow:chat-context-changing'));
        window.conversation=0;
      });
      pendingWork.shift()();
      assert.equal(await page.locator('[data-chat-specialist="planning"]').count(),0);
      workMode='specialists-idle';
      await page.evaluate(()=>{window.restoring=false;dispatchEvent(new Event('vp3:chat-context-ready'));});
      await page.locator('[data-chat-specialist="planning"]').waitFor();
      assert.deepEqual([workRequests.at(-1).scope,workRequests.at(-1).cid],['chat','0']);
      workMode='specialists-mismatch';
      const mismatched=page.waitForResponse(response=>new URL(response.url()).searchParams.get('conversation_id')==='17');
      await page.evaluate(()=>{
        dispatchEvent(new Event('stonefellow:chat-context-changing'));
        window.conversation=17;
        dispatchEvent(new Event('vp3:chat-context-ready'));
      });
      await mismatched;
      await page.locator('[data-chat-specialist="planning"][data-chat-specialist-state="unavailable"]').waitFor();
      assert.ok(await page.evaluate(()=>VP3ChatSpecialists.snapshot({includeResults:true})
        .every(row=>row.state==='unavailable'&&row.detail===''&&row.progress===null)));
    }finally{while(pendingWork.length)pendingWork.shift()();await page.close();}
  });
  await check('Hidden global pages stop polling and refresh verified work when visible again',async()=>{
    workMode='active';
    const page=await pageAt('/workspace?work=1',{clock:true});
    try{
      await page.locator('[data-working="true"]').waitFor();
      await page.evaluate(()=>{Object.defineProperty(document,'hidden',{configurable:true,value:true});dispatchEvent(new Event('visibilitychange'));document.dispatchEvent(new Event('visibilitychange'));});
      await page.waitForFunction(()=>!VP3AgentOrb.isWorking());
      const before=workRequests.length;
      await page.clock.fastForward(3000);
      assert.equal(workRequests.length,before);
      await page.evaluate(()=>{Object.defineProperty(document,'hidden',{configurable:true,value:false});document.dispatchEvent(new Event('visibilitychange'));});
      await page.locator('[data-working="true"]').waitFor();
    }finally{await page.close();}
  });
  await check('The global orb follows an independent Gmail lease and returns to its idle polling cadence',async()=>{
    workMode='gmail';
    const page=await pageAt('/workspace?work=1',{clock:true});
    try{
      const tracks=page.locator('[data-orb-job-ids="gmail:inbox-updates"]');
      await tracks.waitFor();
      assert.equal(await page.locator('iframe').count(),0);
      assert.equal(await page.evaluate(()=>VP3ChatSpecialists.snapshot().length),0);
      workMode='idle';
      await page.clock.fastForward(2600);
      await tracks.waitFor({state:'detached'});
      const before=workRequests.length;
      await page.clock.fastForward(2600);
      assert.equal(workRequests.length,before);
    }finally{await page.close();}
  });
  await check('Stale, mismatched and denied global work clears its dot and specialist',async()=>{
    for(const mode of ['stale','owner','agent','binding','forbidden']){
      workMode='active';
      const page=await pageAt('/workspace?work=1',{clock:true});
      try{
        await page.locator('[data-working="true"]').waitFor();
        workMode=mode;
        await page.clock.fastForward(2600);
        await page.waitForFunction(()=>!VP3AgentOrb.isWorking());
        assert.equal(await page.locator('[data-orb-work-tracks]').count(),0);
        assert.equal(await page.evaluate(()=>VP3ChatSpecialists.snapshot().some(item=>item.key==='ob1')),false);
        if(mode==='forbidden'){
          const before=workRequests.length;
          await page.clock.fastForward(8100);
          assert.equal(workRequests.length,before);
        }
      }finally{await page.close();}
    }
  });
  await check('A late global work response cannot restore work after private context changes',async()=>{
    workMode='hold';
    const page=await pageAt('/workspace?work=1');
    try{
      for(let n=0;!pendingWork.length;n++){
        if(n>100)throw Error('Global work poll did not reach its controlled response.');
        await new Promise(resolve=>setTimeout(resolve,10));
      }
      await page.evaluate(()=>{window.selected={participant_id:8};dispatchEvent(new Event('vp3:participant-selection-changed'));});
      pendingWork.shift()();
      await page.waitForTimeout(100);
      assert.equal(await page.evaluate(()=>VP3AgentOrb.isWorking()),false);
      assert.equal(await page.evaluate(()=>VP3ChatSpecialists.snapshot().length),0);
    }finally{while(pendingWork.length)pendingWork.shift()();await page.close();}
  });
  assert.deepEqual(errors,[]);
  console.log(`PASS ${checks} Working/browser checks`);
}finally{await browser.close();await new Promise(resolve=>server.close(resolve));}
