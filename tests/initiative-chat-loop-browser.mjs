import assert from 'node:assert/strict';
import fs from 'node:fs/promises';
import {chromium} from 'playwright';

// Real shipped client scripts; HTTP, provider output and lifecycle signals are
// controlled fixtures. Canonical claim/authority tests exercise the PHP worker.
const files=['chat.js','chat-voice.js','agent-activity-v94.js','agent-eyes-core.js','agent-orb.js','chat-cognitive-presentation-v510.js','chat-message-unread-v1.js','chat-notifications-drawer-v240.js'];
const sources=Object.fromEntries(await Promise.all(files.map(async name=>[name,await fs.readFile(new URL('../'+name,import.meta.url),'utf8')])));
const browser=await chromium.launch({headless:true,executablePath:process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH||undefined});
const origin='https://initiative-chat-loop.test';
const revision='a'.repeat(32);
function gate(){
  let arrive,release;
  return {arrived:new Promise(resolve=>{arrive=resolve;}),released:new Promise(resolve=>{release=resolve;}),arrive:()=>arrive(),release:()=>release()};
}

async function expandDebugReport(panel){
  await panel.waitFor();
  const technical=panel.locator('[data-initiative-debug-technical]');
  if(!await technical.evaluate(node=>node.open))await technical.locator('summary').click();
}

async function debugShortcut(page){
  await page.evaluate(()=>{
    window.debugShortcutTime=0;
    // Keep the key window independent of runner delays between real key events.
    Object.defineProperty(performance,'now',{configurable:true,value:()=>window.debugShortcutTime});
  });
  return {
    press:async()=>{ for(let i=0;i<3;i++) await page.keyboard.press('z'); },
    advance:ms=>page.evaluate(ms=>{window.debugShortcutTime+=ms;},ms)
  };
}

async function fixture(agentId,{enabled=false,paused=false,twoTabs=false,initialConversationId=42,presentationUnavailable=false,initialDraft=false,runtimeUnavailable=false,omitRuntime=false,failedTickReason='',activeJobs=[],returnDigest=null,noBriefButton=false,debugHealth={},debugEngagement=null,debugGmail=null,deliveryOnly=false}={}){
  const context=await browser.newContext();
  const pages=[],errors=[],reads=[],diagnosticReads=[],ticks=[],settings=[],activities=[],digestActions=[],githubClaims=[],creditClaims=[];
  let githubVoice=null;
  let creditNotices=[];
  const histories=new Map([[42,[{id:1,role:'assistant',message:'Restored private conversation',created_at:'2026-10-30 14:00:00'}]],
    [43,[{id:10,role:'assistant',message:'Another private conversation',created_at:'2026-10-30 14:00:30'}]]]);
  let policy=enabled,exists=enabled||paused,policyRevision=exists?revision:'',policyCid=42,phase=0,failTick=false,holdTick=null,holdRead=null,holdActivity=null,claimActive=false,realWork=0,ownerBusy=false,pendingReport=deliveryOnly;
  let blockedReason=deliveryOnly?'quota_exhausted':'';
  let reportedAssessmentRun=null;
  let completedFailedTick=null;
  const health=(cid=42)=>({state:runtimeUnavailable?'unavailable':!exists?'starting':policy?(cid!==policyCid?'linked_elsewhere':blockedReason?'blocked':'waiting'):'paused',
    reason:runtimeUnavailable?'context_unavailable':!exists?'automatic_startup':policy?(cid!==policyCid?'linked_elsewhere':blockedReason||'worker_missing'):'initiative_paused',enabled:!runtimeUnavailable&&policy&&cid===policyCid,
    paused:exists&&!policy,policy_exists:exists,
    agent_id:agentId,conversation_id:cid,policy_revision:runtimeUnavailable?'':policyRevision,configuration_ready:!runtimeUnavailable,execution_ready:!runtimeUnavailable&&policy&&!blockedReason&&cid===policyCid,
    knowledge_scope:{mode:'off',folder_id:0},runner_state:'never_seen',
    ...(deliveryOnly?{pump_available:!runtimeUnavailable&&policy&&cid===policyCid}:{}),
    active_jobs:!runtimeUnavailable&&policy&&cid===policyCid?activeJobs:[],
    // A completed worker outcome remains visible to every read of its bound
    // policy. Before completion, and after a rebind, no failure is invented.
    ...(!runtimeUnavailable&&policy&&!blockedReason&&cid===policyCid
      &&completedFailedTick?.conversation_id===cid&&completedFailedTick.policy_revision===policyRevision
      ?{state:completedFailedTick.state,reason:completedFailedTick.reason}:{}),
    ...debugHealth});
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
      document.querySelector('[data-agent-worker-details]').open=true;
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
        (noBriefButton?'':'<button id="chatAgentBriefButton">Agent Brief</button>')+'<aside id="chatAgentBriefPopover" hidden><div data-agent-brief-content></div><footer></footer></aside>',
        '<section id="chatThread" data-view="chat"><div id="chatWelcome" hidden></div><section id="chatLiveContext" hidden></section></section>',
        '<form id="chatForm"><textarea id="chatInput">'+(initialDraft?'My unfinished thought':'')+'</textarea><button id="sendChatButton">Send</button><button id="chatVoiceButton" type="button">Voice</button></form><div id="chatVoiceStatus"></div>',
        '<script>window.STONEFELLOW_CHAT='+JSON.stringify(chat)+';window.VP3_COGNITIVE_PRESENTATION_V510='+JSON.stringify(config)+';',
        'window.VP3_CHAT_MESSAGE_UNREAD_V1={endpoint:"/api/agent-chat-read-v1.php"};',
        'window.STONEFELLOW_ACTIVITY={endpoint:"/api/agent-activity-v94.php",csrf:"fixture",surface:"chat",conversationId:'+initialConversationId+'};',
        (initialDraft?'sessionStorage.setItem("vp3:chat:drafts:1:'+agentId+'",JSON.stringify({"'+initialConversationId+'":{text:"My unfinished thought",at:Date.now()}}));':''),
        '</script>',
        ...files.filter(name=>name!=='chat-notifications-drawer-v240.js').map(name=>'<script src="/'+name+'"></script>')
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
        const digest=returnDigest;
        const readWait=holdRead;holdRead=null;if(readWait){readWait.arrive();await readWait.released;}
        if(presentationUnavailable)return route.fulfill({status:503,json:{ok:false,error:'projection_unavailable'}});
        const id=Number(url.searchParams.get('conversation_id'));
        if(url.searchParams.get('action')==='initiative_diagnostics'){
          diagnosticReads.push(id);
          return route.fulfill({json:{ok:true,diagnostics:{agent_id:agentId,conversation_id:id,release:'abc123456def',
            observed_at:'2026-11-11T19:30:30+00:00',initiative:health(id),engagement:debugEngagement,gmail:debugGmail??{
              stages:[{label:'Inbox scan',state:'complete',detail:'Checked 12 messages.'},
                {label:'Content analysis',state:'blocked',detail:'The selected route cannot analyze Gmail content.'}],
              next_step:'Review the Gmail analysis model settings.',private_email:'PRIVATE_EMAIL'
            }}}}).catch(()=>{});
        }
        reads.push({id,runtime:url.searchParams.get('include_initiative_runtime'),digest:url.searchParams.get('include_return_digest')});
        const state={brief:{agent_name:agentId?'Longfellow':'VP3 Agent',active:true,status_label:'Active'},digest};
        if(url.searchParams.get('include_live_context')==='1'){
          assert.equal(url.searchParams.get('include_initiative_runtime'),'1');
          state.live_context={state:'quiet',attention:null,initiative:{state:policy?'waiting_for_review':'off',...(omitRuntime?{}:{runtime:health(id)})}};
          if(githubVoice)state.github_voice={generation:githubVoice.generation,through_id:githubVoice.through_id};
          if(creditNotices.length)state.credit_notices=creditNotices;
        }
        return route.fulfill({json:{ok:true,state}});
      }
      assert.equal(body.agent_id,agentId);
      assert.equal(body.csrf_token,'fixture');
      if(body.action==='github_voice_claim'){
        githubClaims.push(body);
        const claim=githubVoice;
        githubVoice=null;
        return route.fulfill({json:{ok:true,claim}});
      }
      if(body.action==='credit_voice_claim'){
        creditClaims.push(body);
        const notice=creditNotices.find(item=>item.notification_id===body.notification_id&&item.voice_pending);
        if(notice)notice.voice_pending=false;
        return route.fulfill({json:{ok:true,claim:notice??null}});
      }
      if(body.action==='digest_ack'){
        assert.equal(body.digest_id,returnDigest?.id);
        digestActions.push(body.status);
        returnDigest=null;
        return route.fulfill({json:{ok:true}});
      }
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
        if(failedTickReason){
          const state=failedTickReason==='assessment_sources_changed'?'waiting':'failed';
          completedFailedTick={conversation_id:body.conversation_id,policy_revision:body.policy_revision,state,reason:failedTickReason};
          return route.fulfill({json:{ok:false,reason:failedTickReason,health:health(body.conversation_id)}});
        }
        const assessment=debugHealth.latest_assessment_run;
        if(assessment){
          const completed=assessment.status==='completed'&&assessment.run_id!==reportedAssessmentRun;
          if(completed)reportedAssessmentRun=assessment.run_id;
          return route.fulfill({json:{ok:true,reason:completed?'completed':'no_work',health:health(),
            ...(completed?{step:'assessment',run_id:assessment.run_id}:{})}}).catch(()=>{});
        }
        if(!blockedReason&&(twoTabs||phase++===1)&&realWork===0){
          realWork++;
          pendingReport=true;
        }
        if(blockedReason&&!pendingReport){
          return route.fulfill({json:{ok:false,reason:'blocked',health:health()}}).catch(()=>{});
        }
        // Saved text is authorized by the bound Chat policy; speech has its own gate.
        if(pendingReport){
          pendingReport=false;
          histories.get(42).push({id:2,role:'assistant',message:'I reviewed the transcript follow-ups and prepared the next steps.',
            context_json:JSON.stringify({source:'agent_initiative',initiative_run_id:91})});
        }
        return route.fulfill({json:{ok:true,reason:'completed',step:'preparation',health:health()}}).catch(()=>{});
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
  return {context,pages,errors,reads,diagnosticReads,ticks,settings,activities,digestActions,githubClaims,creditClaims,health,open,
    addMessage(conversation,message){histories.get(conversation).push(message);},
    setDigest(digest){returnDigest=digest;},
    setGitHubVoice(voice){githubVoice=voice;},
    setCreditNotices(notices){creditNotices=notices;},
    setDiagnosticHealth(value){debugHealth=value;},
    fail(value){failTick=value;},block(value){blockedReason=value;},
    unavailable(value){runtimeUnavailable=value;},presentationFails(value){presentationUnavailable=value;},
    holdRead(){holdRead=gate();return holdRead;},holdActivity(eligibleOnly=false){holdActivity=gate();holdActivity.eligibleOnly=eligibleOnly;return holdActivity;},
    hold(){holdTick=gate();return holdTick;},get realWork(){return realWork;}};
}

try{
  const github=await fixture(17,{paused:true});
  try{
    const page=await github.open();
    await page.waitForFunction(()=>document.querySelector('[data-agent-brief-content]').getAttribute('aria-busy')==='false');
    await page.evaluate(()=>{window.STONEFELLOW_NOTIFICATION_CENTER.announceWhen=(text,canSpeak,options)=>{
      window.githubSpeech={text,allowed:canSpeak(),autoReply:options.autoReply};return true;
    };});
    const composer=page.locator('#chatInput');
    await composer.fill('An unfinished draft');
    github.setGitHubVoice({generation:42,through_id:8,message:'GitHub Actions reports 1 run failed and 2 runs succeeded.'});
    await page.evaluate(()=>window.VP3_COGNITIVE_PRESENTATION.refresh());
    assert.equal(github.githubClaims.length,0);
    await composer.fill('');
    await page.evaluate(()=>window.dispatchEvent(new CustomEvent('stonefellow:agent-voice',{detail:{enabled:false}})));
    await page.evaluate(()=>window.VP3_COGNITIVE_PRESENTATION.refresh());
    assert.equal(github.githubClaims.length,0);
    await page.evaluate(()=>window.dispatchEvent(new CustomEvent('stonefellow:agent-voice',{detail:{enabled:true}})));
    await page.waitForFunction(()=>window.githubSpeech);
    assert.deepEqual(await page.evaluate(()=>window.githubSpeech),{
      text:'GitHub Actions reports 1 run failed and 2 runs succeeded.',allowed:true,autoReply:false
    });
    assert.equal(github.githubClaims.length,1);
    assert.equal(github.githubClaims[0].conversation_id,42);
    await page.evaluate(()=>window.VP3_COGNITIVE_PRESENTATION.refresh());
    assert.equal(github.githubClaims.length,1);
    console.log('PASS GitHub Actions speech defers during a draft or disabled Agent Voice and uses one canonical announcement');
  }finally{await github.context.close();}

  const credits=await fixture(17,{paused:true});
  try{
    const page=await credits.open();
    await page.waitForFunction(()=>document.querySelector('[data-agent-brief-content]').getAttribute('aria-busy')==='false');
    await page.evaluate(()=>{
      window.creditSpeech=[];
      window.creditBellRefresh=0;
      window.STONEFELLOW_NOTIFICATION_CENTER.refresh=()=>{window.creditBellRefresh++;};
      window.STONEFELLOW_NOTIFICATION_CENTER.announceWhen=(text,allowed,options)=>{
        window.creditSpeech.push({text,allowed:allowed(),browserOnly:options.browserOnly,autoReply:options.autoReply});
        return true;
      };
    });
    const composer=page.locator('#chatInput');
    await composer.fill('An unfinished thought');
    credits.setCreditNotices([{notification_id:41,voice_pending:true,message:'Your VP3 AI token balance is low.'}]);
    await page.evaluate(()=>window.VP3_COGNITIVE_PRESENTATION.refresh());
    await page.waitForFunction(()=>window.creditBellRefresh===1);
    assert.equal(credits.creditClaims.length,0);
    await composer.fill('');
    await page.evaluate(()=>window.dispatchEvent(new CustomEvent('stonefellow:agent-voice',{detail:{enabled:false}})));
    await page.evaluate(()=>window.VP3_COGNITIVE_PRESENTATION.refresh());
    assert.equal(credits.creditClaims.length,0);
    await page.evaluate(()=>window.dispatchEvent(new CustomEvent('stonefellow:agent-voice',{detail:{enabled:true}})));
    await page.waitForFunction(()=>window.creditSpeech.length===1);
    assert.deepEqual(await page.evaluate(()=>window.creditSpeech),[
      {text:'Your VP3 AI token balance is low.',allowed:true,browserOnly:true,autoReply:false}
    ]);
    assert.deepEqual(credits.creditClaims.map(({notification_id,conversation_id})=>[notification_id,conversation_id]),[[41,42]]);
    await page.evaluate(()=>window.VP3_COGNITIVE_PRESENTATION.refresh());
    assert.equal(credits.creditClaims.length,1);
    credits.setCreditNotices([{notification_id:42,voice_pending:true,message:'Anthropic API credits are too low.'}]);
    await page.evaluate(()=>{
      window.STONEFELLOW_NOTIFICATION_DRAWER={endpoint:'/api/unused',csrf:'fixture',orbOnly:true,agentId:17,userId:1};
      window.premiumCreditCalls=0;
      window.StonefellowPremiumVoiceV122=()=>{window.premiumCreditCalls++;return {speak(){throw new Error('Unexpected premium speech');}};};
      window.speechSynthesis.speak=utterance=>{
        window.creditSpeech.push({text:utterance.text});
        utterance.onstart();
        utterance.onend();
      };
    });
    await page.addScriptTag({url:origin+'/chat-notifications-drawer-v240.js'});
    await page.evaluate(()=>window.VP3_COGNITIVE_PRESENTATION.refresh());
    await page.waitForFunction(()=>window.creditSpeech.length===2);
    assert.deepEqual(await page.evaluate(()=>[window.creditSpeech[1].text,window.premiumCreditCalls]),
      ['Anthropic API credits are too low.',0]);
    assert.equal(credits.creditClaims.length,2);
    assert.deepEqual(credits.errors,[]);
  }finally{await credits.context.close();}
  console.log('PASS account credit notices refresh the bell, respect active drafts and Agent Voice, and request one browser-only spoken warning per notice');

  const watchItem={title:'Gmail reply received',target_url:'/chat.php?conversation_id=42'};
  const digest={id:'away-report-1',created_at:'2026-10-30 14:01:00',summary:'Two updates came in.',items:[
    {title:'Gmail inbox review',body:'Reviewed 3 new Gmail messages.',target_url:'/connections.php?app=gmail#gmail-inbox-review'},watchItem
  ]};
  const away=await fixture(17,{paused:true,returnDigest:digest});
  try{
    const page=await away.open();
    const report=page.locator('[data-vp3-return-digest],#vp3CognitiveFeed');
    const sequence=()=>page.locator('#chatThread > [data-chat-message-id]')
      .evaluateAll(rows=>rows.map(row=>row.dataset.chatMessageId));
    assert.equal(await report.count(),0,'A supplied legacy digest must not mount a duplicate Chat brief.');
    assert.deepEqual(await sequence(),['1']);
    away.addMessage(42,{id:21,role:'user',message:'An earlier saved message',created_at:'2026-10-30 14:00:50'});
    away.addMessage(42,{id:22,role:'assistant',message:'A later saved reply',created_at:'2026-10-30 14:02:00'});
    await page.evaluate(()=>window.STONEFELLOW_CHAT_CONTINUITY_V87.syncConversation(42));
    assert.deepEqual(await sequence(),['1','21','22']);
    await page.reload({waitUntil:'domcontentloaded'});
    await page.waitForFunction(()=>document.querySelector('[data-agent-brief-content]').getAttribute('aria-busy')==='false');
    assert.deepEqual(await sequence(),['1','21','22']);
    assert.equal(await report.count(),0);
    assert.equal(await page.evaluate(()=>window.STONEFELLOW_CHAT_CONTINUITY_V87.openConversation(43)),true);
    assert.deepEqual(await sequence(),['10']);
    await page.evaluate(()=>window.VP3_COGNITIVE_PRESENTATION.refresh());
    assert.equal(await report.count(),0,'Switching or refreshing Chat must not reintroduce either legacy brief.');
    assert.deepEqual(away.digestActions,[],'Removing a duplicate surface must never acknowledge unread updates.');
    assert.ok(away.reads.every(read=>read.digest==='0'));
    assert.deepEqual(away.errors,[]);
    console.log('PASS Chat retains real conversation through sync, reload and switch while legacy Now and away briefs remain absent, with no silent acknowledgments');
  }finally{await away.context.close();}

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

  for(const [reason,label] of [
    ['assessment_sources_changed','Sources changed during the assessment. The Agent will check again in its next review.'],
    ['assessment_unverified','The last assessment did not produce a verified plan.'],
  ]){
    const review=await fixture(17,{enabled:true,failedTickReason:reason});
    const failedTick=review.hold();
    let resumedTick=null;
    try{
      assert.equal(review.health().reason,'worker_missing','A configured failure is not a completed worker outcome.');
      const page=await review.open(),status=page.locator('[data-agent-initiative-status]');
      await failedTick.arrived;
      const read=async activity=>{
        const response=page.waitForResponse(response=>{
          const url=new URL(response.url());
          if(activity){
            if(url.pathname!=='/api/agent-activity-v94.php'||response.request().method()!=='POST')return false;
            const context=response.request().postDataJSON().context;
            return context.conversation_id===42&&context.visible===true&&context.focused===true&&context.chat_focused===true;
          }
          return url.pathname==='/api/cognitive-presentation-v510.php'&&response.request().method()==='GET'
            &&url.searchParams.get('include_live_context')==='1'&&url.searchParams.get('conversation_id')==='42';
        });
        if(activity)await page.evaluate(()=>window.StonefellowAgentActivity.refreshCoreContext());
        else{
          await page.waitForFunction(()=>document.querySelector('[data-agent-brief-content]').getAttribute('aria-busy')==='false');
          await page.evaluate(()=>window.VP3_COGNITIVE_PRESENTATION.refresh());
        }
        const body=await (await response).json();
        return activity?body.agent_core_context.health:body.state.live_context.initiative.runtime;
      };
      for(const activity of [true,false])assert.equal((await read(activity)).reason,'worker_missing','Reads cannot report an unfinished failed tick.');
      assert.equal(await status.getByText(label,{exact:true}).count(),0);
      failedTick.release();
      await status.getByText(label,{exact:true}).waitFor();
      assert.ok(review.ticks.length>0);
      for(const activity of [true,false]){
        assert.equal((await read(activity)).reason,reason,'Later health reads retain the completed failed outcome.');
        if(activity)await page.waitForFunction(reason=>window.StonefellowAgentActivity.coreContext()?.health.reason===reason,reason);
        await status.getByText(label,{exact:true}).waitFor();
      }
      assert.equal(review.health(43).reason,'linked_elsewhere','A failed outcome cannot follow another conversation.');
      const failedRevision=review.health().policy_revision;
      await status.getByRole('button',{name:'Pause',exact:true}).click();
      await status.getByText('Agent background work is paused.',{exact:true}).waitFor();
      assert.notEqual(review.health().policy_revision,failedRevision);
      assert.equal(review.health().reason,'initiative_paused','A policy change supersedes the completed outcome.');
      resumedTick=review.hold();
      await status.getByRole('button',{name:'Resume',exact:true}).click();
      await resumedTick.arrived;
      assert.equal(review.health().enabled,true);
      assert.notEqual(review.health().policy_revision,failedRevision);
      for(const activity of [true,false])assert.equal((await read(activity)).reason,'worker_missing','An enabled new revision cannot inherit the earlier failed outcome.');
      assert.equal(await status.getByText(label,{exact:true}).count(),0);
      resumedTick.release();
      await status.getByText(label,{exact:true}).waitFor();
      assert.doesNotMatch(await status.textContent(),/connection was interrupted/);
      assert.deepEqual(review.errors,[]);
    }finally{failedTick.release();resumedTick?.release();await review.context.close();}
  }
  console.log('PASS an authenticated HTTP 200 failed assessment retains its bound status across later activity and presentation reads, without an invented early failure');

  const taskRetry=await fixture(17,{enabled:true,failedTickReason:'model_unavailable',debugHealth:{
    state:'waiting',reason:'retry_scheduled',retry_due_at:'2026-11-11T19:31:30+00:00',
    failure_detail:{run_id:322,action_id:6,stage:'Task review',reason:'The AI provider returned a rate-limit response.',
      reason_code:'model_unavailable',status:'approved',action_status:'queued',executor:'cloud',evidence:'receipt',is_latest_run:true,
      model_diagnostic:'provider_rate_limit',model_http_status:429,retry_at:'2026-11-11T19:31:30+00:00'}
  }});
  try{
    const page=await taskRetry.open(),status=page.locator('[data-agent-initiative-status]');
    await status.getByText('Task review. The AI provider returned a rate-limit response.',{exact:true}).waitFor();
    await status.getByText(/Next attempt is eligible at/).waitFor();
    assert.doesNotMatch(await status.textContent(),/could not be verified|No server worker check-in/);
    await page.getByRole('button',{name:'Open diagnostics',exact:true}).click();
    const panel=page.getByRole('dialog',{name:'Initiative Debug'});
    await expandDebugReport(panel);
    await panel.getByText(/AI diagnostic: provider_rate_limit/).waitFor();
    assert.match(await panel.locator('[data-initiative-debug-report]').textContent(),/Provider HTTP status: 429/);
    assert.deepEqual(taskRetry.errors,[]);
  }finally{await taskRetry.context.close();}
  console.log('PASS Task review shows the provider cause and next eligible retry in the Orb');

  const priorAssessment=await fixture(17,{enabled:true,failedTickReason:'model_unavailable',debugHealth:{
    state:'failed',reason:'model_unavailable',
    failure_detail:{run_id:323,action_id:7,stage:'Assessment',
      reason:'The usage record shows that the AI provider rejected this assessment request. The provider’s reason was not recorded.',
      reason_code:'model_unavailable',status:'failed',action_status:'failed',executor:'cloud',evidence:'receipt',
      is_latest_run:true,model_provider:'anthropic',model_usage_state:'rejected'}
  }});
  try{
    const page=await priorAssessment.open(),status=page.locator('[data-agent-initiative-status]');
    await status.getByText(/Assessment\. The usage record shows that the AI provider rejected/).waitFor();
    await page.getByRole('button',{name:'Open diagnostics',exact:true}).click();
    const panel=page.getByRole('dialog',{name:'Initiative Debug'});
    await expandDebugReport(panel);
    await panel.getByText(/Recorded AI provider: Anthropic/).waitFor();
    const report=await panel.locator('[data-initiative-debug-report]').textContent();
    assert.match(report,/Provider attempt evidence: rejected/);
    assert.doesNotMatch(report,/Provider HTTP status/);
    assert.deepEqual(priorAssessment.errors,[]);
  }finally{await priorAssessment.context.close();}
  console.log('PASS Assessment diagnostics show recorded provider rejection without inventing a provider HTTP status');

  const sourceAssessment=await fixture(17,{enabled:true,failedTickReason:'source_unavailable',debugHealth:{
    state:'failed',reason:'source_unavailable',
    failure_detail:{run_id:393,action_id:396,stage:'Assessment',phase:'Checking source records',
      reason:'The current source records could not be verified.',reason_code:'source_unavailable',status:'failed',
      action_status:'failed',executor:'cloud',evidence:'receipt',is_latest_run:true,source_family:'office_calendar',
      source_coverage:'limited',source_ref:'PRIVATE_CALENDAR_SOURCE'}
  }});
  try{
    const page=await sourceAssessment.open(),status=page.locator('[data-agent-initiative-status]');
    await status.getByText(/Source check: Office calendar \(limited\)/).waitFor();
    await page.getByRole('button',{name:'Open diagnostics',exact:true}).click();
    const panel=page.getByRole('dialog',{name:'Initiative Debug'});
    await expandDebugReport(panel);
    await panel.getByText(/Source family: Office calendar/).waitFor();
    const report=await panel.locator('[data-initiative-debug-report]').textContent();
    assert.match(report,/Source family: Office calendar\nSource coverage: limited/);
    assert.doesNotMatch(report,/PRIVATE_CALENDAR_SOURCE|Provider HTTP status/);
    assert.deepEqual(sourceAssessment.errors,[]);
  }finally{await sourceAssessment.context.close();}
  console.log('PASS Assessment diagnostics identify the failed source family and coverage without exporting private evidence');

  const debug=await fixture(17,{enabled:true,failedTickReason:'assessment_unverified',debugEngagement:{
    presence:'busy',gate:'quiet_chat_required',busy_reason:'voice_turn',presence_age_seconds:4,
    specialist_scan:{at:'2026-11-11T19:30:20+00:00',outcome:'queued_chat_handoff',specialists:{
      gmail:{coverage:'limited',found:20,chat_matches:10,queued:1,source:'PRIVATE_GMAIL_FINDING'},
      tasks:{coverage:'ready',found:0},planning:{coverage:'unavailable',found:0,failure:'storage_error'},
      media:{coverage:'unconfigured',failure:'PRIVATE_MEDIA_FAILURE'}
    }},
    latest_tick:{at:'2026-11-11T19:30:27+00:00',reason:'completed',step:'assessment',run_id:321,
      coordination:{outcome:'failed',at:'2026-11-11T19:30:26+00:00',failure:{phase:'checkpoint',
        code:'storage_schema',sqlstate:'42S22',database_code:1054,file:'agent-initiative-v1.php',line:477,text:'PRIVATE_QUERY'}},
      detail:'PRIVATE_TICK'},
    latest_preparation:{run_id:320,action_id:9,step:'coordination.review',specialist:'gmail',status:'completed',
      attempt:1,at:'2026-11-11T19:30:27+00:00',heartbeat_at:'2026-11-11T19:30:25+00:00',
      lease_expires_at:'2026-11-11T19:34:25+00:00',delivery:'withheld',delivery_reason:'source_changed',summary:'PRIVATE_DRAFT'},
    last_post:{run_id:300,message_id:54,at:'2026-11-11T18:51:02+00:00',source:'initiative',specialist:'gmail',
      result:'question',message:'PRIVATE_MESSAGE'}
  },debugHealth:{
    state:'failed',reason:'assessment_unverified',
    route:'vp3_cloud',runner_state:'fresh',runner_last_success_at:'2026-11-11T19:30:21+00:00',
    last_checked_at:'2026-11-11T19:30:26+00:00',
    latest_assessment_run:{run_id:321,status:'failed',at:'2026-11-11T19:30:27+00:00'},
    last_assessment:{run_id:317,outcome:'assignments_queued',queued:1,opportunities_discarded:2,activity_sources_omitted:1,
      at:'2026-11-11T19:25:00+00:00',source:'PRIVATE_SOURCE'},
    assessment_error:{run_id:316,code:'invalid_json',at:'2026-11-11T19:20:28+00:00',output_bytes:801,is_historical:true,
      answer:'PRIVATE_MODEL_ANSWER'},
    failure_detail:{run_id:321,action_id:5,stage:'Plan assessment',reason:'The model did not return valid JSON.',
      reason_code:'assessment_unverified',status:'failed',action_status:'failed',executor:'cloud',evidence:'receipt',is_latest_run:true},
    private_config:'PRIVATE_CONFIG'
  }});
  try{
    const page=await debug.open();
    const panel=page.getByRole('dialog',{name:'Initiative Debug'});
    const shortcut=await debugShortcut(page);
    await page.setViewportSize({width:360,height:640});
    await page.addStyleTag({content:await fs.readFile(new URL('../chat-cognitive-presentation-v510.css',import.meta.url),'utf8')});
    await page.locator('[data-agent-initiative-status]').getByText('Plan assessment. The model did not return valid JSON.',{exact:true}).waitFor();
    assert.equal(await panel.count(),0);
    const composer=page.locator('#chatInput');
    await composer.focus();
    await shortcut.press();
    assert.equal(await composer.inputValue(),'zzz');
    assert.equal(await panel.count(),0);
    const chatButton=page.locator('#newChatButton');
    await chatButton.focus();
    await page.keyboard.down('z');
    await page.keyboard.down('z');
    await page.keyboard.down('z');
    await page.keyboard.up('z');
    assert.equal(await panel.count(),0);
    await page.keyboard.press('z');
    await page.keyboard.press('z');
    await shortcut.advance(751);
    await page.keyboard.press('z');
    assert.equal(await panel.count(),0);
    await page.keyboard.press('Control+z');
    await chatButton.focus();
    await shortcut.press();
    assert.equal(await panel.count(),1,'the three-key shortcut opens Initiative Debug');
    const bounds=await panel.boundingBox();
    assert.ok(bounds&&bounds.x>=0&&bounds.x+bounds.width<=360&&bounds.y+bounds.height<=640);
    await expandDebugReport(panel);
    await panel.getByText(/Gmail pipeline/).waitFor();
    const report=await panel.locator('[data-initiative-debug-report]').textContent();
    assert.match(report,/Deployed build: abc123456def/);
    assert.match(report,/Current Initiative\nLatest assessment: Failed\nAssessment run: 321/);
    assert.match(report,/Current Chat engagement[\s\S]*Chat presence: The browser reported Chat was busy/);
    assert.match(report,/Chat delivery: Waiting for quiet, focused Chat/);
    assert.match(report,/Reported busy reason: Waiting for a voice turn\nChat activity recorded: 4 seconds ago/);
    assert.match(report,/Last open-Chat check: (?!None recorded|—).+/);
    assert.match(report,/Open-Chat check result: Completed a step/);
    assert.match(report,/Open-Chat step: Assessment\nOpen-Chat step run: 321/);
    assert.match(report,/Specialist scheduling: The specialist scan failed\nLast specialist scheduling check: (?!—)/);
    assert.match(report,/Specialist scan failed while: Saving the scan checkpoint\nSpecialist scan code: storage_schema\nDatabase error code: 42S22 \/ 1054/);
    assert.match(report,/Specialist scan location: agent-initiative-v1\.php:477/);
    assert.match(report,/Last completed specialist scan: (?!—).+\nGmail: Partially checked · 20 current findings · 10 matched Chat rules · 1 queued/);
    assert.match(report,/Tasks & Lists: Checked · 0 current findings\nPlanning: Could not check · storage_error\nMedia & Entertainment: Source inactive for this Chat/);
    assert.match(report,/Latest assignment: Gmail · Review specialist findings\nAssignment run: 320\nAssignment action: 9/);
    assert.match(report,/Assignment status: Completed/);
    assert.match(report,/Assignment heartbeat: (?!—).+\nAssignment claim expires: (?!—)/);
    assert.match(report,/Assignment delivery: Withheld during verification\nDelivery reason: Source records changed before delivery/);
    assert.match(report,/Last proactive Chat post: .+ · Message 54\nLast proactive post run: 300/);
    assert.match(report,/Last proactive post source: Gmail\nLast proactive post type: Question/);
    assert.ok(report.indexOf('Current Initiative') < report.indexOf('Current Chat engagement'));
    assert.match(report,/Current failure\n/);
    assert.match(report,/Last successful assessment: Run 317/);
    assert.match(report,/Run: 321/);
    assert.match(report,/Recorded reason: The model did not return valid JSON/);
    assert.match(report,/Content analysis \[blocked\]: The selected route cannot analyze Gmail content/);
    assert.match(report,/Reason: assessment_unverified/);
    assert.match(report,/Route: vp3_cloud/);
    assert.match(report,/Server worker: fresh/);
    assert.match(report,/Sources last checked: (?!—)/);
    assert.doesNotMatch(report,/PRIVATE_|owner-a|fixture|a{32}|Earlier assessment rejection/);
    const history=panel.locator('[data-initiative-debug-history]');
    assert.equal(await history.evaluate(node=>node.open),false);
    const historyReport=history.locator('[data-initiative-debug-history-report]');
    await historyReport.waitFor({state:'hidden'});
    assert.match(await historyReport.textContent(),/Earlier assessment rejection: invalid_json\nPlan rejected at: .+\nPlanner response bytes: 801/);
    assert.doesNotMatch(await historyReport.textContent(),/PRIVATE_/);
    await page.evaluate(()=>Object.defineProperty(navigator,'clipboard',{configurable:true,value:{writeText:async text=>{ window.copiedDebug=text; }}}));
    await panel.getByRole('button',{name:'Copy report',exact:true}).click();
    await panel.getByRole('status').getByText('Copied.').waitFor();
    assert.equal(await page.evaluate(()=>window.copiedDebug),'Initiative Debug\n'+report+'\n\nHistory\n'+await historyReport.textContent());
    await shortcut.press();
    assert.equal(await panel.count(),0);
    await page.getByRole('button',{name:'Open diagnostics',exact:true}).click();
    await expandDebugReport(panel);
    await panel.getByText(/Gmail pipeline/).waitFor();
    const diagnosticRead=page.waitForResponse(response=>response.url().includes('action=initiative_diagnostics'));
    await panel.getByRole('button',{name:'Refresh',exact:true}).click();
    assert.equal((await diagnosticRead).ok(),true);
    await panel.getByRole('button',{name:'Close',exact:true}).click();
    assert.equal(await panel.count(),0);
    await shortcut.press();
    assert.equal(await page.evaluate(()=>window.STONEFELLOW_CHAT_CONTINUITY_V87.openConversation(43)),true);
    await page.waitForFunction(()=>window.STONEFELLOW_CHAT_CONTINUITY_V87.conversationId()===43);
    assert.equal(await panel.count(),0);
    await page.evaluate(()=>window.dispatchEvent(new CustomEvent('vp3:participant-task-context',{detail:{participant_id:9}})));
    await shortcut.press();
    assert.equal(await panel.count(),0);
    assert.deepEqual(debug.errors,[]);
    console.log('PASS Initiative Debug toggles with zzz, preserves text editing, copies only scoped diagnostics, and clears on Chat and participant changes');
  }finally{await debug.context.close();}

  const verifiedGmail={stages:[{label:'Chat delivery',state:'complete',detail:'A current Gmail handoff was verified in this Chat.',
    code:'gmail_handoff_delivered'}]};
  const unavailableRuntime=await fixture(17,{enabled:true,runtimeUnavailable:true,debugHealth:{reason:'runtime_unavailable',
    runtime_diagnostic:{code:'storage_schema',sqlstate:'42S22',database_code:1054,detail:'PRIVATE_RUNTIME_QUERY'}},
    debugEngagement:{presence:'unavailable',gate:'unavailable',tick_checked:false,preparation_checked:false,post_checked:false},
    debugGmail:verifiedGmail});
  try{
    const page=await unavailableRuntime.open();
    const panel=page.getByRole('dialog',{name:'Initiative Debug'});
    await page.getByRole('button',{name:'Open diagnostics',exact:true}).click();
    const report=panel.locator('[data-initiative-debug-report]');
    await expandDebugReport(panel);
    await report.getByText(/gmail_handoff_delivered/).waitFor();
    const current=await report.textContent();
    assert.match(current,/Latest assessment: Could not verify/);
    assert.match(current,/Last open-Chat check: Could not verify/);
    assert.match(current,/Initiative: Could not verify/);
    assert.match(current,/Execution ready: Could not verify/);
    assert.match(current,/Server worker: Could not verify/);
    assert.match(current,/Runtime status check: storage_schema\nRuntime database code: 42S22 \/ 1054/);
    assert.doesNotMatch(current,/Initiative: Off|never_seen|PRIVATE_/);
    assert.deepEqual(unavailableRuntime.ticks,[]);
    assert.deepEqual(unavailableRuntime.errors,[]);
  }finally{await unavailableRuntime.context.close();}

  const partial=await fixture(17,{enabled:true,debugHealth:{assessment_read:'unavailable',
    assessment_diagnostic:{code:'storage_schema',sqlstate:'42S22',database_code:1054,detail:'PRIVATE_ASSESSMENT_QUERY'}},
    debugEngagement:{presence:'quiet',gate:'ready',tick_checked:true,preparation_checked:true,post_checked:true,
      last_post:{run_id:404,message_id:1242,at:'2026-11-11T19:30:27+00:00',source:'initiative',specialist:'gmail',result:'question'}},
    debugGmail:verifiedGmail});
  try{
    const page=await partial.open();
    const panel=page.getByRole('dialog',{name:'Initiative Debug'});
    await page.getByRole('button',{name:'Open diagnostics',exact:true}).click();
    const report=panel.locator('[data-initiative-debug-report]');
    await expandDebugReport(panel);
    await report.getByText(/Assessment database code: 42S22 \/ 1054/).waitFor();
    const current=await report.textContent();
    assert.match(current,/Latest assessment: Could not verify\nAssessment status check: storage_schema/);
    assert.match(current,/Last open-Chat check: None recorded/);
    assert.match(current,/Last proactive Chat post: .+ · Message 1242/);
    assert.match(current,/Initiative: On/);
    assert.match(current,/Execution ready: Yes/);
    assert.match(current,/Server worker: never_seen/);
    assert.doesNotMatch(current,/PRIVATE_/);
    const nextTick=page.waitForResponse(response=>response.url().includes('/api/cognitive-presentation-v510.php')
      &&response.request().method()==='POST'&&response.request().postDataJSON().action==='initiative_tick');
    await page.clock.fastForward(31000);
    assert.equal((await nextTick).ok(),true);
    assert.deepEqual(partial.errors,[]);
  }finally{await partial.context.close();}
  console.log('PASS Initiative Debug separates unavailable runtime and assessment reads from real policy, Chat activity and verified Gmail delivery');

  const priorFailure={run_id:393,action_id:396,stage:'Assessment',phase:'Checking source records',
    reason:'The current source records could not be verified.',reason_code:'source_unavailable',status:'failed',
    action_status:'failed',executor:'cloud',evidence:'receipt',is_latest_run:true,at:'2026-11-11T19:28:00+00:00'};
  const runningAssessment={run_id:411,status:'running',at:'2026-11-11T19:30:00+00:00'};
  const superseded=await fixture(17,{enabled:true,debugHealth:{
    latest_assessment_run:{...runningAssessment,status:'failed',reason_code:'assessment_sources_changed'},
    failure_detail:{...priorFailure,run_id:411,reason_code:'assessment_sources_changed',reason:'The source records changed during assessment.'},
  }});
  try {
    const page=await superseded.open();
    const panel=page.getByRole('dialog',{name:'Initiative Debug'});
    await page.getByRole('button',{name:'Open diagnostics',exact:true}).click();
    await expandDebugReport(panel);
    await panel.getByText(/Latest assessment: Sources changed/).waitFor();
    await expandDebugReport(panel);
    await panel.getByText('History',{exact:true}).click();
    const history=await panel.locator('[data-initiative-debug-history-report]').textContent();
    assert.match(history,/Recorded assessment: Superseded\nRun: 411/);
    assert.doesNotMatch(history,/Recorded failure: Earlier run/);
  } finally { await superseded.context.close(); }
  const recovering=await fixture(17,{enabled:true,debugHealth:{
    latest_assessment_run:runningAssessment,failure_detail:{...priorFailure,is_latest_run:false},
  }});
  const assessment=recovering.hold();
  try{
    const page=await recovering.open();
    await assessment.arrived;
    const panel=page.getByRole('dialog',{name:'Initiative Debug'});
    const current=panel.locator('[data-initiative-debug-report]');
    const history=panel.locator('[data-initiative-debug-history]');
    const earlier=history.locator('[data-initiative-debug-history-report]');
    await page.getByRole('button',{name:'Open diagnostics',exact:true}).click();
    await expandDebugReport(panel);
    await panel.getByText(/Gmail pipeline/).waitFor();
    assert.match(await current.textContent(),/Latest assessment: Working\nAssessment run: 411/);
    assert.equal(await history.evaluate(node=>node.open),false);
    await earlier.waitFor({state:'hidden'});
    await page.clock.fastForward(10000);
    assert.deepEqual(recovering.diagnosticReads,[42],'diagnostic snapshots are not fetched continuously while work is running');
    const workingSnapshot=page.waitForResponse(response=>response.url().includes('action=initiative_diagnostics'));
    await page.clock.fastForward(3000);
    assert.equal((await workingSnapshot).ok(),true);
    assert.deepEqual(recovering.diagnosticReads,[42,42],'the open dialog refreshes its read-only snapshot while the worker owns its claim');
    assert.equal(recovering.realWork,0,'reading diagnostics cannot execute the held assessment');

    recovering.setDiagnosticHealth({
      state:'checked',reason:'worker_missing',
      latest_assessment_run:{...runningAssessment,status:'completed',at:'2026-11-11T19:30:10+00:00'},
      last_assessment:{run_id:411,outcome:'assignments_queued',queued:1,opportunities_discarded:2,activity_sources_omitted:1,
        at:'2026-11-11T19:30:10+00:00',source:'PRIVATE_SOURCE'},
      failure_detail:{...priorFailure,is_latest_run:false},
      assessment_error:{run_id:393,code:'invalid_opportunities',at:priorFailure.at,output_bytes:801,is_historical:true,
        answer:'PRIVATE_MODEL_ANSWER'},
    });
    const refreshed=page.waitForResponse(response=>response.url().includes('action=initiative_diagnostics'));
    assessment.release();
    await page.clock.fastForward(13000);
    assert.equal((await refreshed).ok(),true);
    await expandDebugReport(panel);
    await panel.getByText(/Latest assessment: Succeeded/).waitFor();
    const report=await current.textContent();
    assert.match(report,/Latest assessment: Succeeded\nAssessment run: 411/);
    assert.match(report,/Plan verified at: (?!—).+/);
    assert.match(report,/Jobs queued by this assessment: 1/);
    assert.match(report,/Invalid optional ideas discarded: 2/);
    assert.match(report,/Outdated Recent activity items skipped: 1/);
    assert.doesNotMatch(report,/Run: 393|Current failure|Assessment rejection|The current source records could not be verified|PRIVATE_/);
    assert.deepEqual(recovering.diagnosticReads,[42,42,42]);
    assert.equal(await history.evaluate(node=>node.open),false);
    await earlier.waitFor({state:'hidden'});

    await page.evaluate(()=>Object.defineProperty(navigator,'clipboard',{configurable:true,value:{writeText:async text=>{window.copiedDebug=text;}}}));
    await panel.getByRole('button',{name:'Copy report',exact:true}).click();
    await panel.getByRole('status').getByText('Copied.').waitFor();
    assert.equal(await page.evaluate(()=>window.copiedDebug),'Initiative Debug\n'+report+'\n\nHistory\n'+await earlier.textContent());
    assert.doesNotMatch(await page.evaluate(()=>window.copiedDebug),/PRIVATE_/);
    await history.getByText('History',{exact:true}).click();
    await earlier.waitFor();
    assert.match(await earlier.textContent(),/Recorded failure: Earlier run\nRun: 393/);
    assert.match(await earlier.textContent(),/Earlier assessment rejection: invalid_opportunities/);
    assert.match(await earlier.textContent(),/Recorded reason: The current source records could not be verified/);
    await panel.getByRole('button',{name:'Close',exact:true}).click();
    const nextTick=page.waitForResponse(response=>response.url().includes('/api/cognitive-presentation-v510.php')
      &&response.request().method()==='POST'&&response.request().postDataJSON().action==='initiative_tick');
    await page.clock.fastForward(31000);
    assert.equal((await nextTick).ok(),true);
    assert.deepEqual(recovering.diagnosticReads,[42,42,42],'closing the dialog stops diagnostic polling');
    assert.deepEqual(recovering.errors,[]);
    console.log('PASS open Initiative Debug refreshes after a successful assessment and keeps the earlier failure in collapsed History');
  }finally{assessment.release();await recovering.context.close();}

  const expiresAt=Date.now()+240000;
  const running=await fixture(17,{enabled:true,noBriefButton:true,activeJobs:[
    {id:'workflow:71',expiresAt,kind:'assessment',step:'Considering current opportunities',private_detail:'Never send this to the orb'},
    {id:'workflow:72',expiresAt}, {id:'workflow:73',expiresAt:1}, {id:'bad:74',expiresAt}
  ]});
  try{
    const page=await running.open();
    const status=page.locator('[data-agent-initiative-status]');
    assert.equal(await page.locator('#chatAgentBriefButton,[data-orb-chat]').count(),0,'Chat has one orb entry point and no duplicate composer buttons.');
    await page.waitForFunction(()=>window.initiativeOrbJobs?.length===2);
    assert.deepEqual(await page.evaluate(()=>window.initiativeOrbJobs),[
      {id:'workflow:71',expiresAt,kind:'assessment',step:'Considering current opportunities'},{id:'workflow:72',expiresAt}
    ]);
    assert.ok(running.reads.some(read=>read.id===42&&read.runtime==='1'),'Removing the Brief button keeps live source/context reads active.');
    await page.waitForFunction(()=>Boolean(window.initiativeOrbJobs?.length));
    assert.ok(running.ticks.length>0,'Removing the Brief button keeps permitted Initiative work advancing.');
    await page.evaluate(()=>window.VP3_COGNITIVE_PRESENTATION.openDetails());
    await page.locator('#chatAgentBriefPopover').waitFor({state:'visible'});
    await page.evaluate(()=>window.VP3_COGNITIVE_PRESENTATION.closeBrief());
    await page.waitForFunction(()=>document.querySelector('[data-agent-brief-content]').getAttribute('aria-busy')==='false');
    running.presentationFails(true);
    await page.evaluate(()=>window.VP3_COGNITIVE_PRESENTATION.refresh());
    await page.waitForFunction(()=>window.initiativeOrbJobs?.length===0);
    running.presentationFails(false);
    await page.evaluate(()=>window.VP3_COGNITIVE_PRESENTATION.refresh());
    await page.waitForFunction(()=>window.initiativeOrbJobs?.length===2);
    await page.evaluate(()=>{
      const host=document.createElement('div');host.id='specialist-initiative-settings';document.body.appendChild(host);
      window.cleanupInitiativeSettings=window.VP3_COGNITIVE_PRESENTATION.mountInitiativeSettings(host);
    });
    const settings=page.locator('#specialist-initiative-settings');
    await settings.getByRole('heading',{name:'OB1 coordination'}).waitFor();
    await settings.getByRole('button',{name:'Pause Initiative',exact:true}).click();
    await status.getByRole('button',{name:'Resume',exact:true}).waitFor();
    assert.deepEqual(await page.evaluate(()=>window.initiativeOrbJobs),[]);
    await settings.getByRole('button',{name:'Resume Initiative',exact:true}).click();
    await page.waitForFunction(()=>window.initiativeOrbJobs?.length===2);
    assert.deepEqual(running.settings.map(({enabled,conversation_id})=>[enabled,conversation_id]),[[false,42],[true,42]]);
    assert.deepEqual(await page.evaluate(()=>{
      window.dispatchEvent(new Event('stonefellow:chat-context-changing'));
      return window.initiativeOrbJobs;
    }),[]);
    assert.equal(await settings.getByRole('button',{name:'Pause Initiative',exact:true}).count(),0);
    await page.evaluate(()=>window.cleanupInitiativeSettings());
    assert.equal(await settings.getByRole('heading').count(),0);
    console.log('PASS shared settings control the same Chat-scoped Initiative and keep live activity consistent with the orb');
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
    const retryRead=denied.holdRead(),retryActivity=denied.holdActivity();
    await status.getByRole('button',{name:'Retry',exact:true}).click();
    await retryRead.arrived;await retryActivity.arrived;
    denied.unavailable(false);retryRead.release();retryActivity.release();
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
      await page.waitForFunction(()=>!document.querySelector('[data-agent-worker-details] .vp3-orb-initiative-actions button')?.disabled);
      assert.equal(f.ticks.length,1);
      await page.clock.fastForward(15001);
      await page.getByText('I reviewed the transcript follow-ups and prepared the next steps.',{exact:true}).waitFor();
      const report=page.locator('[data-chat-message-id="2"]');
      await report.locator('[data-chat-message-new]').waitFor();
      assert.equal(await report.count(),1);
      assert.deepEqual(await page.evaluate(()=>({speech:window.speechCalls,announcements:window.announcementCalls})),{speech:0,announcements:0});
      assert.equal(await page.locator('#chatAgentBriefPopover').isHidden(),true);
      assert.equal(f.realWork,1);

      // Private work keeps advancing while typing or speaking. Saved text
      // retains its canonical Chat binding and never requests speech.
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
      await Promise.all([
        status.getByText('The background connection was interrupted. The next check will retry.',{exact:true}).waitFor(),
        (async()=>{
          await page.evaluate(()=>window.VP3_COGNITIVE_PRESENTATION.refresh());
          // If a refresh was already in flight, let the scheduled cycle see the restored route.
          await page.clock.fastForward(31000);
        })()
      ]);
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
      await page.waitForFunction(()=>!document.querySelector('[data-agent-worker-details] .vp3-orb-initiative-actions button')?.disabled);

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
    await page.locator('[data-chat-message-id="2"] [data-chat-message-new]').waitFor();
    assert.equal(await page.locator('#chatInput').inputValue(),'My unfinished thought','a saved text report preserves the owner draft');
    assert.equal(await page.locator('[data-chat-message-id="2"]').count(),1);
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
    assert.equal(await page.locator('[data-chat-message-id="2"]').count(),1);
    await page.evaluate(()=>document.body.dataset.stonefellowAgentState='speaking');
    const speakingTick=page.waitForResponse(response=>response.request().method()==='POST'
      &&response.url().includes('/api/cognitive-presentation-v510.php')&&response.request().postDataJSON().action==='initiative_tick');
    await page.clock.fastForward(31000);await speakingTick;
    await checked.waitFor();
    assert.ok(busyOwner.ticks.length>count);
    assert.equal(await page.locator('[data-chat-message-id="2"]').count(),1);
    assert.deepEqual(await page.evaluate(()=>({speech:window.speechCalls,announcements:window.announcementCalls})),{speech:0,announcements:0});
    await page.evaluate(()=>document.body.dataset.stonefellowAgentState='idle');
    await page.clock.fastForward(31000);
    await page.locator('[data-chat-message-id="2"] [data-chat-message-new]').waitFor();
    assert.equal(busyOwner.realWork,1);
    console.log('PASS private preparation and saved text use the bound Chat through composer drafts; later processing and speech cannot duplicate the report or synthesize speech');
  }finally{await busyOwner.context.close();}

  const savedOnly=await fixture(17,{enabled:true,deliveryOnly:true,initialDraft:true});
  try{
    const page=await savedOnly.open(),status=page.locator('[data-agent-initiative-status]');
    await status.getByText('Background preparation is paused because the AI allowance is exhausted.',{exact:true}).waitFor();
    await page.clock.fastForward(31000);
    assert.ok(savedOnly.ticks.length>0,'the bound Chat can receive saved text while its composer has a draft');
    assert.equal(await page.locator('#chatInput').inputValue(),'My unfinished thought');
    await page.locator('[data-chat-message-id="2"] [data-chat-message-new]').waitFor();
    assert.equal(await page.getByText('I reviewed the transcript follow-ups and prepared the next steps.',{exact:true}).count(),1);
    assert.equal(savedOnly.realWork,0,'saved delivery does not run inference');
    const count=savedOnly.ticks.length;
    await page.clock.fastForward(16000);
    assert.equal(savedOnly.ticks.length,count,'delivery-only checks are rate limited while inference is unavailable');
    await page.locator('#chatInput').fill('');
    await page.clock.fastForward(31000);
    assert.equal(await page.locator('[data-chat-message-id="2"]').count(),1);
    assert.deepEqual(await page.evaluate(()=>({speech:window.speechCalls,announcements:window.announcementCalls})),{speech:0,announcements:0});
    assert.deepEqual(savedOnly.errors,[]);
    console.log('PASS saved verified result reaches bound Chat once without inference, draft replacement or speech even while AI allowance is exhausted');
  }finally{await savedOnly.context.close();}

  const newAfterSaved=await fixture(17,{enabled:true});
  try{
    const page=await newAfterSaved.open();
    const debugPanel=page.getByRole('dialog',{name:'Initiative Debug'});
    const shortcut=await debugShortcut(page);
    const chatButton=page.locator('#newChatButton');
    await chatButton.focus();
    await shortcut.press();
    await expandDebugReport(debugPanel);
    await debugPanel.getByText('Server worker:',{exact:false}).waitFor();
    await page.waitForFunction(()=>!document.querySelector('[data-agent-worker-details] .vp3-orb-initiative-actions button')?.disabled);
    const before=newAfterSaved.ticks.length;
    await debugPanel.getByRole('button',{name:'Close',exact:true}).click();
    await page.getByRole('button',{name:'New Chat',exact:true}).click();
    await page.waitForFunction(()=>window.STONEFELLOW_CHAT_CONTINUITY_V87.conversationId()===0);
    assert.equal(await debugPanel.count(),0);
    await chatButton.focus();
    await shortcut.press();
    const unsavedReport=await debugPanel.locator('[data-initiative-debug-report]').textContent();
    assert.ok(unsavedReport.startsWith('Send a message to save this Chat and start background work.'));
    assert.doesNotMatch(unsavedReport,/Assessment run:|PRIVATE_/,'a new Chat cannot reuse the saved Chat diagnostic snapshot');
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
    await second.waitForFunction(()=>!document.querySelector('[data-agent-worker-details] .vp3-orb-initiative-actions button')?.disabled);
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
