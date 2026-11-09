import assert from 'node:assert/strict';
import fs from 'node:fs/promises';
import {chromium} from 'playwright';

// Real shipped client scripts; HTTP, provider output and lifecycle signals are
// controlled fixtures. Canonical claim/authority tests exercise the PHP worker.
const files=['chat.js','chat-voice.js','agent-activity-v94.js','agent-eyes-core.js','agent-orb.js','chat-cognitive-presentation-v510.js','chat-message-unread-v1.js'];
const sources=Object.fromEntries(await Promise.all(files.map(async name=>[name,await fs.readFile(new URL('../'+name,import.meta.url),'utf8')])));
const browser=await chromium.launch({headless:true,executablePath:process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH||undefined});
const origin='https://initiative-chat-loop.test';
const revision='a'.repeat(32);
function gate(){
  let arrive,release;
  return {arrived:new Promise(resolve=>{arrive=resolve;}),released:new Promise(resolve=>{release=resolve;}),arrive:()=>arrive(),release:()=>release()};
}

async function fixture(agentId,{enabled=false,paused=false,twoTabs=false,initialConversationId=42,presentationUnavailable=false,initialDraft=false,runtimeUnavailable=false,omitRuntime=false,activeJobs=[]}={}){
  const context=await browser.newContext();
  const pages=[],errors=[],reads=[],ticks=[],settings=[],activities=[];
  const histories=new Map([[42,[{id:1,role:'assistant',message:'Restored private conversation'}]],
    [43,[{id:10,role:'assistant',message:'Another private conversation'}]]]);
  let policy=enabled,exists=enabled||paused,policyRevision=exists?revision:'',policyCid=42,phase=0,failTick=false,holdTick=null,holdRead=null,holdActivity=null,claimActive=false,realWork=0,blockedReason='',ownerBusy=false,pendingReport=false;
  const health=(cid=42)=>({state:runtimeUnavailable?'unavailable':!exists?'starting':policy?(cid!==policyCid?'linked_elsewhere':blockedReason?'blocked':'waiting'):'paused',
    reason:runtimeUnavailable?'context_unavailable':!exists?'automatic_startup':policy?(cid!==policyCid?'linked_elsewhere':blockedReason||'worker_missing'):'initiative_paused',enabled:!runtimeUnavailable&&policy&&cid===policyCid,
    paused:exists&&!policy,policy_exists:exists,
    agent_id:agentId,conversation_id:cid,policy_revision:runtimeUnavailable?'':policyRevision,configuration_ready:!runtimeUnavailable,execution_ready:!runtimeUnavailable&&policy&&!blockedReason&&cid===policyCid,
    knowledge_scope:{mode:'off',folder_id:0},runner_state:'never_seen',
    active_jobs:!runtimeUnavailable&&policy&&cid===policyCid?activeJobs:[]});
  await context.addInitScript(()=>{
    window.speechCalls=0;window.announcementCalls=0;
    window.SpeechRecognition=undefined;window.webkitSpeechRecognition=undefined;
    Object.defineProperty(window,'speechSynthesis',{value:{cancel(){},speak(){window.speechCalls++;}}});
    window.SpeechSynthesisUtterance=class {constructor(text){this.text=text;}};
    window.STONEFELLOW_NOTIFICATION_CENTER={cancelSpeech(){},announceWhen(){window.announcementCalls++;return false;}};
    window.StonefellowPremiumVoiceV122=()=>({warm:async()=>false,stop(){},createStream(){}});
    window.StonefellowKnowledgeScopeV162={value:()=>({mode:'folder',folder_id:12})};
    window.VP3_ORB_ADAPTER={ownerKey:'1',chatUrl:'/chat.php',isMainChat:()=>true,state:()=>({state:document.body.dataset.stonefellowAgentState||'idle'})};
    window.addEventListener('vp3:agent-orb-ready',()=>{
      const orb=window.VP3AgentOrb;
      const setJobs=orb.setJobs;
      orb.setJobs=(source,jobs)=>{
        if(source==='initiative')window.initiativeOrbJobs=jobs;
        setJobs?.call(orb,source,jobs);
      };
    },{once:true});
  });
  await context.route(origin+'/**',async route=>{
    const request=route.request(),url=new URL(request.url());
    if(url.pathname==='/'){
      const chat={userId:1,agentId,initialConversationId,initialView:'chat',
        endpoint:'/api/chat-v236.php'+(agentId?'?agent='+agentId:''),csrf:'fixture',workingAttentionBinding:'owner-a'};
      const config={agentId,csrf:'fixture',workingAttentionBinding:'owner-a',liveContext:true,initiativeRuntime:true};
      return route.fulfill({contentType:'text/html',body:[
        '<!doctype html><meta charset="utf-8"><style>[hidden]{display:none!important}#chatThread{height:500px;overflow:auto}.message{min-height:40px}</style>',
        '<button id="newChatButton">New Chat</button><div id="chatHistory"></div>',
        '<button id="chatAgentBriefButton">Agent Brief</button><aside id="chatAgentBriefPopover" hidden><div data-agent-brief-content></div><footer></footer></aside>',
        '<section id="chatThread" data-view="chat"><div id="chatWelcome" hidden></div><section id="chatLiveContext" hidden></section></section>',
        '<form id="chatForm"><textarea id="chatInput">'+(initialDraft?'My unfinished thought':'')+'</textarea><button id="sendChatButton">Send</button><button id="chatVoiceButton" type="button">Voice</button></form><div id="chatVoiceStatus"></div>',
        '<script>window.STONEFELLOW_CHAT='+JSON.stringify(chat)+';window.VP3_COGNITIVE_PRESENTATION_V510='+JSON.stringify(config)+';',
        'window.VP3_CHAT_MESSAGE_UNREAD_V1={endpoint:"/api/agent-chat-read-v1.php"};',
        'window.STONEFELLOW_ACTIVITY={endpoint:"/api/agent-activity-v94.php",csrf:"fixture",surface:"chat",conversationId:'+initialConversationId+'};',
        (initialDraft?'sessionStorage.setItem("vp3:chat:drafts:1:'+agentId+'",JSON.stringify({"'+initialConversationId+'":{text:"My unfinished thought",at:Date.now()}}));':''),
        '</script>',
        ...files.map(name=>'<script src="/'+name+'"></script>')
      ].join('')});
    }
    if(sources[url.pathname.slice(1)])return route.fulfill({contentType:'text/javascript',body:sources[url.pathname.slice(1)]});
    const body=request.method()==='POST'?request.postDataJSON():null;
    if(url.pathname==='/api/agent-activity-v94.php'){
      activities.push(body);
      const cid=Number(body.context.conversation_id),eligible=body.context.visible===true&&body.context.focused===true
        &&body.context.chat_focused===true&&cid>0;
      const activityWait=holdActivity;
      if(activityWait&&(!activityWait.eligibleOnly||eligible)){holdActivity=null;activityWait.arrive();await activityWait.released;}
      if(eligible)ownerBusy=body.context.chat_busy===true;
      if(eligible&&!exists){exists=true;policy=true;policyRevision=revision;policyCid=cid;}
      else if(eligible&&policy&&policyCid!==cid){policyCid=cid;policyRevision=policyRevision==='c'.repeat(32)?'d'.repeat(32):'c'.repeat(32);}
      return route.fulfill({json:{ok:true,activity:{state:body.state},owner_user_id:1,server_time_ms:Date.now(),
        ...(eligible?{agent_core_context:{owner_user_id:1,agent_id:agentId,conversation_id:cid,working_attention_binding:'owner-a',health:health(cid)}}:{})}}).catch(()=>{});
    }
    if(url.pathname==='/api/cognitive-presentation-v510.php'){
      assert.equal(request.headers()['x-vp3-working-attention'],'owner-a');
      if(!body){
        const readWait=holdRead;holdRead=null;if(readWait){readWait.arrive();await readWait.released;}
        if(presentationUnavailable)return route.fulfill({status:503,json:{ok:false,error:'projection_unavailable'}});
        const id=Number(url.searchParams.get('conversation_id'));
        reads.push({id,runtime:url.searchParams.get('include_initiative_runtime')});
        const state={brief:{agent_name:agentId?'Longfellow':'VP3 Agent',active:true,status_label:'Active'}};
        if(url.searchParams.get('include_live_context')==='1'){
          assert.equal(url.searchParams.get('include_initiative_runtime'),'1');
          state.live_context={state:'quiet',attention:null,initiative:{state:policy?'waiting_for_review':'off',...(omitRuntime?{}:{runtime:health(id)})}};
        }
        return route.fulfill({json:{ok:true,state}});
      }
      assert.equal(body.agent_id,agentId);
      assert.equal(body.csrf_token,'fixture');
      if(body.action==='initiative_setting'){
        settings.push(body);policy=body.enabled;exists=true;policyRevision=policyRevision==='b'.repeat(32)?'e'.repeat(32):'b'.repeat(32);policyCid=body.conversation_id;
        return route.fulfill({json:{ok:true,health:health(policyCid)}});
      }
      if(body.action==='initiative_tick'){
        ticks.push(body);assert.equal(body.conversation_id,policyCid);assert.equal(body.policy_revision,policyRevision);
        if(failTick)return route.abort('internetdisconnected');
        if(claimActive)return route.fulfill({json:{ok:true,reason:'already_running',health:health()}});
        const wait=holdTick;holdTick=null;
        if(wait){claimActive=true;wait.arrive();await wait.released;claimActive=false;}
        if(!policy)return route.fulfill({json:{ok:true,reason:'off',health:health()}}).catch(()=>{});
        if((twoTabs||phase++===1)&&realWork===0){
          realWork++;
          pendingReport=true;
        }
        if(pendingReport&&!ownerBusy){
          pendingReport=false;
          histories.get(42).push({id:2,role:'assistant',message:'I reviewed the transcript follow-ups and prepared the next steps.',
            context_json:JSON.stringify({source:'agent_initiative',initiative_run_id:91})});
        }
        return route.fulfill({json:{ok:true,reason:'completed',health:health()}}).catch(()=>{});
      }
      return route.fulfill({json:{ok:true}});
    }
    if(url.pathname==='/api/agent-chat-read-v1.php'){
      const id=body?body.conversation_id:Number(url.searchParams.get('conversation_id'));
      const ids=body?body.message_ids:url.searchParams.get('message_ids').split(',').map(Number).filter(Boolean);
      return route.fulfill({json:{ok:true,agent_id:agentId,conversation_id:id,message_ids:ids,
        unread_message_ids:body?[]:ids.filter(id=>id===2)}});
    }
    if(url.pathname==='/api/chat-v236.php'){
      const id=Number(body.conversation_id||42),messages=histories.get(id);
      if(body.action==='messages_after')return route.fulfill({json:{ok:true,messages:messages.filter(m=>m.id>body.after_id)}});
      if(body.action==='activity')return route.fulfill({json:{ok:true,conversation_id:id,latest_message_id:messages.at(-1).id,updates:[],latest_id:0,unread_count:0}});
      return route.fulfill({json:{ok:true,messages:body.action==='load'?messages:[],
        conversations:[{id:42,title:'Current Chat'},{id:43,title:'Other Chat'}]}});
    }
    return route.fulfill({status:404});
  });
  const open=async({waitForHealth=true}={})=>{
    const page=await context.newPage();pages.push(page);page.setDefaultTimeout(8000);
    page.on('pageerror',e=>errors.push(e.message));
    await page.clock.install();
    await page.goto(origin,{waitUntil:'domcontentloaded'});
    await page.locator('[data-agent-initiative-status]').waitFor();
    if(waitForHealth)await page.waitForFunction(()=>!document.querySelector('[data-agent-initiative-status]')?.textContent.includes('Waiting for the Agent’s first status check.'));
    assert.equal(await page.locator('#chatAgentBriefPopover').isHidden(),true);
    return page;
  };
  return {context,pages,errors,reads,ticks,settings,activities,health,open,
    fail(value){failTick=value;},block(value){blockedReason=value;},
    unavailable(value){runtimeUnavailable=value;},presentationFails(value){presentationUnavailable=value;},
    holdRead(){holdRead=gate();return holdRead;},holdActivity(eligibleOnly=false){holdActivity=gate();holdActivity.eligibleOnly=eligibleOnly;return holdActivity;},
    hold(){holdTick=gate();return holdTick;},get realWork(){return realWork;}};
}

try{
  const startup=await fixture(17);
  try{
    const read=startup.holdRead(),activity=startup.holdActivity();
    const page=await startup.open({waitForHealth:false}),status=page.locator('[data-agent-initiative-status]');
    await read.arrived;await activity.arrived;
    await status.getByText('Waiting for the Agent’s first status check.',{exact:true}).waitFor();
    assert.equal(await status.getByRole('button',{name:'Retry',exact:true}).count(),0);
    assert.equal(startup.ticks.length,0);
    activity.release();
    await status.getByRole('button',{name:'Pause',exact:true}).waitFor();
    read.release();
    await page.waitForFunction(()=>document.querySelector('[data-agent-brief-content]').getAttribute('aria-busy')==='false');
    await status.getByRole('button',{name:'Pause',exact:true}).waitFor();
    console.log('PASS first health is pending rather than unavailable; accepted activity repairs it and an older presentation read cannot replace it');
  }finally{await startup.context.close();}

  const expiresAt=Date.now()+240000;
  const running=await fixture(17,{enabled:true,activeJobs:[
    {id:'workflow:71',expiresAt,private_detail:'Never send this to the orb'},
    {id:'workflow:72',expiresAt}, {id:'workflow:73',expiresAt:1}, {id:'bad:74',expiresAt}
  ]});
  try{
    const page=await running.open();
    const status=page.locator('[data-agent-initiative-status]');
    await page.waitForFunction(()=>window.initiativeOrbJobs?.length===2);
    assert.deepEqual(await page.evaluate(()=>window.initiativeOrbJobs),[
      {id:'workflow:71',expiresAt},{id:'workflow:72',expiresAt}
    ]);
    await page.waitForFunction(()=>document.querySelector('[data-agent-brief-content]').getAttribute('aria-busy')==='false');
    running.presentationFails(true);
    await page.evaluate(()=>window.VP3_COGNITIVE_PRESENTATION.refresh());
    await page.waitForFunction(()=>window.initiativeOrbJobs?.length===0);
    running.presentationFails(false);
    await page.evaluate(()=>window.VP3_COGNITIVE_PRESENTATION.refresh());
    await page.waitForFunction(()=>window.initiativeOrbJobs?.length===2);
    await status.getByRole('button',{name:'Pause',exact:true}).click();
    await status.getByRole('button',{name:'Resume',exact:true}).waitFor();
    assert.deepEqual(await page.evaluate(()=>window.initiativeOrbJobs),[]);
    await status.getByRole('button',{name:'Resume',exact:true}).click();
    await page.waitForFunction(()=>window.initiativeOrbJobs?.length===2);
    assert.deepEqual(await page.evaluate(()=>{
      window.dispatchEvent(new Event('stonefellow:chat-context-changing'));
      return window.initiativeOrbJobs;
    }),[]);
    console.log('PASS only live, scoped Initiative leases reach the orb; failed reads, pauses and Chat changes clear them');
  }finally{await running.context.close();}

  const denied=await fixture(17,{runtimeUnavailable:true});
  try{
    const page=await denied.open(),status=page.locator('[data-agent-initiative-status]');
    await status.getByText('The Agent could not confirm access to this saved Chat.',{exact:true}).waitFor();
    assert.equal(denied.ticks.length,0);
    await page.waitForFunction(()=>document.querySelector('[data-agent-brief-content]').getAttribute('aria-busy')==='false');
    const read=denied.holdRead(),activity=denied.holdActivity();
    await status.getByRole('button',{name:'Retry',exact:true}).click();
    await read.arrived;await activity.arrived;
    await status.getByText('The Agent could not confirm access to this saved Chat.',{exact:true}).waitFor();
    assert.equal(await status.getByText('Waiting for the Agent’s first status check.',{exact:true}).count(),0);
    denied.unavailable(false);read.release();activity.release();
    await status.getByRole('button',{name:'Pause',exact:true}).waitFor();
    await page.waitForFunction(()=>document.querySelector('[data-agent-brief-content]').getAttribute('aria-busy')==='false');
    denied.unavailable(true);
    await page.evaluate(()=>window.StonefellowAgentActivity.refreshCoreContext());
    await status.getByText('The Agent could not confirm access to this saved Chat.',{exact:true}).waitFor();
    denied.unavailable(false);
    await status.getByRole('button',{name:'Retry',exact:true}).click();
    await status.getByRole('button',{name:'Pause',exact:true}).waitFor();
    await page.waitForFunction(()=>document.querySelector('[data-agent-brief-content]').getAttribute('aria-busy')==='false');
    denied.presentationFails(true);
    await page.evaluate(()=>window.VP3_COGNITIVE_PRESENTATION.refresh());
    await status.getByRole('button',{name:'Pause',exact:true}).waitFor();
    assert.equal(await status.getByRole('button',{name:'Retry',exact:true}).count(),0,'an optional heavy projection failure does not discard verified runtime health');
    console.log('PASS known unavailable access stays an honest failure through Retry; recovered accepted health remains valid when the heavy presentation GET subsequently fails');
  }finally{await denied.context.close();}

  const missing=await fixture(17,{omitRuntime:true});
  try{
    const activity=missing.holdActivity(),page=await missing.open();
    await page.getByText('The Agent did not return its status for this Chat.',{exact:true}).waitFor();
    assert.equal(missing.ticks.length,0);
    activity.release();
    console.log('PASS a missing runtime response is a protocol failure rather than an invented first-health or reused current-health success');
  }finally{await missing.context.close();}

  const stalled=await fixture(17);
  try{
    const old=stalled.holdActivity(true),page=await stalled.open(),status=page.locator('[data-agent-initiative-status]');
    await old.arrived;
    await page.clock.fastForward(16000);
    await status.getByText('The Agent’s connection timed out before its status could be confirmed.',{exact:true}).waitFor();
    assert.equal(stalled.ticks.length,0);
    await status.getByRole('button',{name:'Retry',exact:true}).click();
    await status.getByRole('button',{name:'Pause',exact:true}).waitFor();
    old.release();
    await page.waitForFunction(()=>document.querySelector('[data-agent-brief-content]').getAttribute('aria-busy')==='false');
    assert.ok(stalled.ticks.length>=1);assert.equal(stalled.settings.length,0);
    await status.getByRole('button',{name:'Pause',exact:true}).waitFor();
    console.log('PASS the actual activity-client deadline reports a stalled connection; Retry obtains accepted bound health, starts the real tick transport and rejects the old aborted response');
  }finally{await stalled.context.close();}

  for(const agentId of [0,17]){
    const f=await fixture(agentId);
    try{
      const page=await f.open(),status=page.locator('[data-agent-initiative-status]');
      assert.equal(await page.locator('#chatThread [data-agent-initiative-status]').count(),0);
      assert.equal(await page.locator('.vp3-orb-root [data-agent-initiative-status]').count(),1);
      assert.equal(await status.getByRole('button',{name:'Enable',exact:true}).count(),0);
      await page.clock.fastForward(300);
      await page.waitForFunction(()=>document.querySelector('[data-agent-initiative-setting="off"]'));
      assert.equal(f.settings.length,0);
      assert.equal(f.activities.at(-1).context.conversation_id,42);
      assert.deepEqual(f.health().knowledge_scope,{mode:'off',folder_id:0});
      await page.waitForFunction(()=>!document.querySelector('[data-agent-initiative-status] button')?.disabled);
      assert.equal(f.ticks.length,1);
      await page.clock.fastForward(15001);
      await page.getByText('I reviewed the transcript follow-ups and prepared the next steps.',{exact:true}).waitFor();
      const report=page.locator('[data-chat-message-id="2"]');
      await report.locator('[data-chat-message-new]').waitFor();
      assert.equal(await report.count(),1);
      assert.deepEqual(await page.evaluate(()=>({speech:window.speechCalls,announcements:window.announcementCalls})),{speech:0,announcements:0});
      assert.equal(await page.locator('#chatAgentBriefPopover').isHidden(),true);
      assert.equal(f.realWork,1);

      // Private work keeps advancing while typing or speaking; its delivery
      // remains governed separately by current canonical owner availability.
      await page.locator('#chatInput').fill('My unfinished thought');
      let count=f.ticks.length;
      await page.clock.fastForward(16000);
      await page.evaluate(()=>window.VP3_COGNITIVE_PRESENTATION.refresh());
      assert.ok(f.ticks.length>count);count=f.ticks.length;
      await page.locator('#chatInput').fill('');
      await page.evaluate(()=>document.body.dataset.stonefellowAgentState='speaking');
      await page.clock.fastForward(16000);
      await page.evaluate(()=>window.VP3_COGNITIVE_PRESENTATION.refresh());
      assert.ok(f.ticks.length>count);count=f.ticks.length;
      await page.evaluate(()=>document.body.dataset.stonefellowAgentState='idle');

      f.block('quota_exhausted');
      await page.evaluate(()=>window.VP3_COGNITIVE_PRESENTATION.refresh());
      await status.getByText('Background preparation is paused because the AI allowance is exhausted.',{exact:true}).waitFor();
      await page.clock.fastForward(16000);
      assert.equal(f.ticks.length,count);
      f.block('');f.fail(true);
      await page.evaluate(()=>window.VP3_COGNITIVE_PRESENTATION.refresh());
      await status.getByText('The background connection was interrupted. The next check will retry.',{exact:true}).waitFor();
      f.fail(false);
      await page.clock.fastForward(16000);
      await status.getByText('No background worker has been observed. This open Chat can advance permitted work.',{exact:true}).waitFor();

      const pausing=f.hold();
      await page.clock.fastForward(16000);await pausing.arrived;
      await status.getByRole('button',{name:'Pause',exact:true}).click();
      await status.getByText('Agent background work is paused.',{exact:true}).waitFor();
      pausing.release();
      count=f.ticks.length;
      await page.clock.fastForward(16000);
      assert.equal(f.ticks.length,count);
      assert.equal(Object.hasOwn(f.settings.at(-1),'knowledge_scope'),false,'Pause does not change Knowledge permission');
      // A delayed pre-pause heartbeat must not restore the active UI or work.
      await page.evaluate(({agentId,revision})=>window.dispatchEvent(new CustomEvent('vp3:agent-core-context',{detail:{
        owner_user_id:1,agent_id:agentId,conversation_id:42,working_attention_binding:'owner-a',
        health:{enabled:true,agent_id:agentId,conversation_id:42,policy_revision:revision,state:'waiting',reason:'worker_missing',
          configuration_ready:true,execution_ready:true,knowledge_scope:{mode:'off',folder_id:0}}
      }})),{agentId,revision});
      await status.getByRole('button',{name:'Resume',exact:true}).waitFor();
      assert.equal(f.ticks.length,count);
      await status.getByRole('button',{name:'Resume',exact:true}).click();
      await status.getByRole('button',{name:'Pause',exact:true}).waitFor();
      assert.equal(Object.hasOwn(f.settings.at(-1),'knowledge_scope'),false,'Resume does not grant the foreground Knowledge selection');
      await page.waitForFunction(()=>!document.querySelector('[data-agent-initiative-status] button')?.disabled);

      // A late old-Chat response cannot change the newly selected Chat.
      const held=f.hold();
      await page.clock.fastForward(16000);await held.arrived;
      await page.evaluate(()=>window.STONEFELLOW_CHAT_CONTINUITY_V87.openConversation(43));
      held.release();
      await page.waitForFunction(()=>window.STONEFELLOW_CHAT_CONTINUITY_V87.conversationId()===43);
      assert.equal(await page.locator('[data-chat-message-id="2"]').count(),0);
      assert.deepEqual(f.errors,[]);
      console.log('PASS automatic core in '+(agentId?'named':'System')+' Chat: actual activity client, no Enable prerequisite or Knowledge inheritance, canonical New result, silent text, busy guards, quota/network recovery, Pause/Resume and stale-heartbeat/conversation fences');
    }finally{await f.context.close();}
  }

  const blank=await fixture(17,{initialConversationId:0});
  try{
    const page=await blank.open();
    await page.getByText('The Agent can continue useful work after your first message saves this Chat.',{exact:true}).waitFor();
    await page.clock.fastForward(35000);
    assert.equal(await page.locator('[data-agent-initiative-setting]').count(),0);
    assert.equal(blank.ticks.length,0);assert.equal(blank.settings.length,0);
    assert.equal(blank.reads.some(read=>read.runtime==='1'),false);
    console.log('PASS a new unsaved Chat does not create a conversation, activate work or spend model calls');
  }finally{await blank.context.close();}

  const busyOwner=await fixture(17,{initialDraft:true});
  try{
    const page=await busyOwner.open();
    await page.clock.fastForward(16000);
    await page.waitForFunction(()=>document.querySelector('[data-agent-initiative-setting="off"]'));
    assert.equal(busyOwner.realWork,1,'private preparation finishes while the owner has a draft');
    assert.equal(await page.locator('[data-chat-message-id="2"]').count(),0);
    const checked=page.locator('[data-agent-initiative-status]').getByText('No background worker has been observed. This open Chat can advance permitted work.',{exact:true});
    await checked.waitFor();
    let count=busyOwner.ticks.length;
    await page.evaluate(()=>document.body.dataset.stonefellowAgentState='processing');
    await page.locator('#chatInput').fill('');
    const processingTick=page.waitForResponse(response=>response.request().method()==='POST'
      &&response.url().includes('/api/cognitive-presentation-v510.php')&&response.request().postDataJSON().action==='initiative_tick');
    await page.clock.fastForward(31000);await processingTick;
    await checked.waitFor();
    assert.ok(busyOwner.ticks.length>count);count=busyOwner.ticks.length;
    assert.equal(await page.locator('[data-chat-message-id="2"]').count(),0);
    await page.evaluate(()=>document.body.dataset.stonefellowAgentState='speaking');
    const speakingTick=page.waitForResponse(response=>response.request().method()==='POST'
      &&response.url().includes('/api/cognitive-presentation-v510.php')&&response.request().postDataJSON().action==='initiative_tick');
    await page.clock.fastForward(31000);await speakingTick;
    await checked.waitFor();
    assert.ok(busyOwner.ticks.length>count);
    assert.equal(await page.locator('[data-chat-message-id="2"]').count(),0);
    assert.deepEqual(await page.evaluate(()=>({speech:window.speechCalls,announcements:window.announcementCalls})),{speech:0,announcements:0});
    await page.evaluate(()=>document.body.dataset.stonefellowAgentState='idle');
    await page.clock.fastForward(31000);
    await page.locator('[data-chat-message-id="2"] [data-chat-message-new]').waitFor();
    assert.equal(busyOwner.realWork,1);
    console.log('PASS private preparation advances through composer drafts, main Agent processing and speech; canonical report appears only after quiet, without synthesized speech');
  }finally{await busyOwner.context.close();}

  const newAfterSaved=await fixture(17,{enabled:true});
  try{
    const page=await newAfterSaved.open();
    await page.waitForFunction(()=>!document.querySelector('[data-agent-initiative-status] button')?.disabled);
    const before=newAfterSaved.ticks.length;
    await page.getByRole('button',{name:'New Chat',exact:true}).click();
    await page.waitForFunction(()=>window.STONEFELLOW_CHAT_CONTINUITY_V87.conversationId()===0);
    await page.clock.fastForward(35000);
    assert.equal(newAfterSaved.activities.at(-1).context.conversation_id,0,'New Chat must not reuse the rendered old saved conversation');
    assert.equal(newAfterSaved.ticks.length,before);
    assert.equal(await page.locator('[data-agent-initiative-setting]').count(),0);
    console.log('PASS opening New Chat after a saved Chat does not adopt or pump the old conversation');
  }finally{await newAfterSaved.context.close();}

  const f=await fixture(17,{enabled:true,twoTabs:true});
  try{
    const held=f.hold(),first=await f.open();
    await held.arrived;
    const second=await f.open();
    await second.waitForFunction(()=>!document.querySelector('[data-agent-initiative-status] button')?.disabled);
    assert.equal(f.ticks.length,2);
    held.release();
    await first.getByText('I reviewed the transcript follow-ups and prepared the next steps.',{exact:true}).waitFor();
    await second.clock.fastForward(16000);
    await second.getByText('I reviewed the transcript follow-ups and prepared the next steps.',{exact:true}).waitFor();
    assert.equal(f.realWork,1);
    assert.equal(await first.locator('[data-chat-message-id="2"]').count(),1);
    assert.equal(await second.locator('[data-chat-message-id="2"]').count(),1);
    await first.evaluate(()=>{
      Object.defineProperty(document,'hidden',{configurable:true,value:true});
      document.dispatchEvent(new Event('visibilitychange'));
    });
    const pending=f.hold();
    await second.clock.fastForward(16000);await pending.arrived;
    await second.evaluate(()=>{
      window.STONEFELLOW_CHAT={...window.STONEFELLOW_CHAT,userId:2,workingAttentionBinding:'owner-b'};
    });
    pending.release();
    await second.locator('#chatLiveContext').waitFor({state:'hidden'});
    const count=f.ticks.length;
    await second.clock.fastForward(32000);
    assert.equal(f.ticks.length,count);
    assert.deepEqual(f.errors,[]);
    console.log('PASS two Chat tabs tolerate a canonical in-flight claim and render one saved result each; account replacement fences late responses and stops further ticks');
  }finally{await f.context.close();}

  const paused=await fixture(17,{paused:true});
  try{
    const page=await paused.open(),status=page.locator('[data-agent-initiative-status]');
    await page.clock.fastForward(35000);
    await status.getByRole('button',{name:'Resume',exact:true}).waitFor();
    await page.evaluate(()=>window.STONEFELLOW_CHAT_CONTINUITY_V87.openConversation(43));
    await page.waitForFunction(()=>window.STONEFELLOW_CHAT_CONTINUITY_V87.conversationId()===43);
    await page.clock.fastForward(35000);
    await status.getByRole('button',{name:'Resume',exact:true}).waitFor();
    await page.reload({waitUntil:'domcontentloaded'});
    await page.clock.fastForward(35000);
    await status.getByRole('button',{name:'Resume',exact:true}).waitFor();
    assert.equal(paused.ticks.length,0);assert.equal(paused.settings.length,0);
    assert.ok(paused.activities.length>1);
    console.log('PASS explicit Pause survives automatic lifecycle heartbeats and reload without an Enable gate');
  }finally{await paused.context.close();}

  const unavailable=await fixture(17,{presentationUnavailable:true});
  try{
    const page=await unavailable.context.newPage();page.setDefaultTimeout(8000);
    await page.clock.install();await page.goto(origin,{waitUntil:'domcontentloaded'});
    await page.clock.fastForward(300);
    await page.locator('[data-agent-initiative-setting="off"]').waitFor();
    assert.equal(unavailable.ticks.length,1);assert.equal(unavailable.settings.length,0);
    await page.evaluate(()=>{
      const core=window.StonefellowAgentActivity.coreContext();
      for(const change of [{owner_user_id:2},{agent_id:99},{conversation_id:99},{working_attention_binding:'other-session'}]){
        window.dispatchEvent(new CustomEvent('vp3:agent-core-context',{detail:{...core,...change,
          health:{...core.health,enabled:false,state:'paused',paused:true,reason:'initiative_paused',policy_revision:'f'.repeat(32)}}}));
      }
      window.dispatchEvent(new CustomEvent('vp3:agent-core-context',{detail:{...core,health:{...core.health,policy_revision:'invalid'}}}));
    });
    await page.locator('[data-agent-initiative-setting="off"]').waitFor();
    assert.equal(unavailable.ticks.length,1);
    assert.equal(await page.locator('#chatAgentBriefPopover').isHidden(),true);
    console.log('PASS accepted activity starts the core loop despite Brief/status projection failure and rejects mismatched owner, Agent, conversation and session events');
  }finally{await unavailable.context.close();}
}finally{await browser.close();}
