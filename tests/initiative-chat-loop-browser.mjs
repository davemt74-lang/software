import assert from 'node:assert/strict';
import fs from 'node:fs/promises';
import {chromium} from 'playwright';

// Real shipped client scripts; HTTP, provider output and lifecycle signals are
// controlled fixtures. Canonical claim/authority tests exercise the PHP worker.
const files=['chat.js','chat-voice.js','chat-cognitive-presentation-v510.js','chat-message-unread-v1.js'];
const sources=Object.fromEntries(await Promise.all(files.map(async name=>[name,await fs.readFile(new URL('../'+name,import.meta.url),'utf8')])));
const browser=await chromium.launch({headless:true,executablePath:process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH||undefined});
const origin='https://initiative-chat-loop.test';
const revision='a'.repeat(32);
function gate(){
  let arrive,release;
  return {arrived:new Promise(resolve=>{arrive=resolve;}),released:new Promise(resolve=>{release=resolve;}),arrive:()=>arrive(),release:()=>release()};
}

async function fixture(agentId,{enabled=false,twoTabs=false,initialConversationId=42}={}){
  const context=await browser.newContext();
  const pages=[],errors=[],reads=[],ticks=[],settings=[];
  const histories=new Map([[42,[{id:1,role:'assistant',message:'Restored private conversation'}]],
    [43,[{id:10,role:'assistant',message:'Another private conversation'}]]]);
  let policy=enabled,phase=0,failTick=false,holdTick=null,claimActive=false,realWork=0,blockedReason='';
  const health=(cid=42)=>({state:policy?(cid!==42?'linked_elsewhere':blockedReason?'blocked':'waiting'):'off',
    reason:policy?(cid!==42?'linked_elsewhere':blockedReason||'worker_missing'):'initiative_off',enabled:policy&&cid===42,
    agent_id:agentId,conversation_id:cid,policy_revision:policy&&cid===42?revision:'',configuration_ready:true,execution_ready:policy&&!blockedReason&&cid===42,
    knowledge_scope:{mode:'folder',folder_id:12},runner_state:'never_seen'});
  await context.addInitScript(()=>{
    window.speechCalls=0;window.announcementCalls=0;
    window.SpeechRecognition=undefined;window.webkitSpeechRecognition=undefined;
    Object.defineProperty(window,'speechSynthesis',{value:{cancel(){},speak(){window.speechCalls++;}}});
    window.SpeechSynthesisUtterance=class {constructor(text){this.text=text;}};
    window.STONEFELLOW_NOTIFICATION_CENTER={cancelSpeech(){},announceWhen(){window.announcementCalls++;return false;}};
    window.StonefellowPremiumVoiceV122=()=>({warm:async()=>false,stop(){},createStream(){}});
    window.StonefellowKnowledgeScopeV162={value:()=>({mode:'folder',folder_id:12})};
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
        '<form id="chatForm"><textarea id="chatInput"></textarea><button id="sendChatButton">Send</button><button id="chatVoiceButton" type="button">Voice</button></form><div id="chatVoiceStatus"></div>',
        '<script>window.STONEFELLOW_CHAT='+JSON.stringify(chat)+';window.VP3_COGNITIVE_PRESENTATION_V510='+JSON.stringify(config)+';',
        'window.VP3_CHAT_MESSAGE_UNREAD_V1={endpoint:"/api/agent-chat-read-v1.php"};</script>',
        ...files.map(name=>'<script src="/'+name+'"></script>')
      ].join('')});
    }
    if(sources[url.pathname.slice(1)])return route.fulfill({contentType:'text/javascript',body:sources[url.pathname.slice(1)]});
    const body=request.method()==='POST'?request.postDataJSON():null;
    if(url.pathname==='/api/cognitive-presentation-v510.php'){
      assert.equal(request.headers()['x-vp3-working-attention'],'owner-a');
      if(!body){
        const id=Number(url.searchParams.get('conversation_id'));
        reads.push({id,runtime:url.searchParams.get('include_initiative_runtime')});
        const state={brief:{agent_name:agentId?'Longfellow':'VP3 Agent',active:true,status_label:'Active'}};
        if(url.searchParams.get('include_live_context')==='1'){
          assert.equal(url.searchParams.get('include_initiative_runtime'),'1');
          state.live_context={state:'quiet',attention:null,initiative:{state:policy?'waiting_for_review':'off',runtime:health(id)}};
        }
        return route.fulfill({json:{ok:true,state}});
      }
      assert.equal(body.agent_id,agentId);
      assert.equal(body.csrf_token,'fixture');
      if(body.action==='initiative_setting'){
        settings.push(body);policy=body.enabled;
        return route.fulfill({json:{ok:true,health:health()}});
      }
      if(body.action==='initiative_tick'){
        ticks.push(body);assert.equal(body.conversation_id,42);assert.equal(body.policy_revision,revision);
        if(failTick)return route.abort('internetdisconnected');
        if(claimActive)return route.fulfill({json:{ok:true,reason:'already_running',health:health()}});
        const wait=holdTick;holdTick=null;
        if(wait){claimActive=true;wait.arrive();await wait.released;claimActive=false;}
        if(!policy)return route.fulfill({json:{ok:true,reason:'off',health:health()}}).catch(()=>{});
        if((twoTabs||phase++===1)&&realWork===0){
          realWork++;
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
  const open=async()=>{
    const page=await context.newPage();pages.push(page);page.setDefaultTimeout(8000);
    page.on('pageerror',e=>errors.push(e.message));
    await page.clock.install();
    await page.goto(origin,{waitUntil:'domcontentloaded'});
    await page.locator('[data-agent-initiative-status]').waitFor();
    assert.equal(await page.locator('#chatAgentBriefPopover').isHidden(),true);
    return page;
  };
  return {context,pages,errors,reads,ticks,settings,health,open,
    fail(value){failTick=value;},block(value){blockedReason=value;},
    hold(){holdTick=gate();return holdTick;},get realWork(){return realWork;}};
}

try{
  for(const agentId of [0,17]){
    const f=await fixture(agentId);
    try{
      const page=await f.open(),status=page.locator('[data-agent-initiative-status]');
      await status.getByText('Background preparation is off for this Agent.',{exact:true}).waitFor();
      assert.equal(f.ticks.length,0);
      const firstTick=page.waitForRequest(request=>request.method()==='POST'&&request.url().includes('/api/cognitive-presentation-v510.php')&&request.postDataJSON().action==='initiative_tick');
      await status.getByRole('button',{name:'Enable',exact:true}).click();
      await firstTick;
      await page.waitForFunction(()=>document.querySelector('[data-agent-initiative-setting="off"]'));
      assert.equal(f.settings.length,1);
      assert.deepEqual(f.settings[0].knowledge_scope,{mode:'folder',folder_id:12});
      assert.equal(f.settings[0].conversation_id,42);
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

      // Typed work and speech have priority over another foreground tick.
      await page.locator('#chatInput').fill('My unfinished thought');
      let count=f.ticks.length;
      await page.clock.fastForward(16000);
      await page.evaluate(()=>window.VP3_COGNITIVE_PRESENTATION.refresh());
      assert.equal(f.ticks.length,count);
      await page.locator('#chatInput').fill('');
      await page.evaluate(()=>document.body.dataset.stonefellowAgentState='speaking');
      await page.clock.fastForward(16000);
      await page.evaluate(()=>window.VP3_COGNITIVE_PRESENTATION.refresh());
      assert.equal(f.ticks.length,count);
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
      await status.getByText('Background preparation is off for this Agent.',{exact:true}).waitFor();
      pausing.release();
      count=f.ticks.length;
      await page.clock.fastForward(16000);
      assert.equal(f.ticks.length,count);
      await status.getByRole('button',{name:'Enable',exact:true}).click();
      await status.getByRole('button',{name:'Pause',exact:true}).waitFor();
      await page.waitForFunction(()=>!document.querySelector('[data-agent-initiative-status] button')?.disabled);

      // A late old-Chat response cannot change the newly selected Chat.
      const held=f.hold();
      await page.clock.fastForward(16000);await held.arrived;
      await page.evaluate(()=>window.STONEFELLOW_CHAT_CONTINUITY_V87.openConversation(43));
      held.release();
      await page.waitForFunction(()=>window.STONEFELLOW_CHAT_CONTINUITY_V87.conversationId()===43);
      assert.equal(await page.locator('[data-chat-message-id="2"]').count(),0);
      assert.deepEqual(f.errors,[]);
      console.log('PASS foreground Initiative in '+(agentId?'named':'System')+' Chat: explicit opt-in/scope, canonical saved New result, silent text, busy guards, blocked quota, network retry, pause during inference and conversation fence');
    }finally{await f.context.close();}
  }

  const blank=await fixture(17,{initialConversationId:0});
  try{
    const page=await blank.open();
    await page.getByText('Background preparation can be enabled after this Chat has been saved.',{exact:true}).waitFor();
    assert.equal(await page.locator('[data-agent-initiative-setting]').count(),0);
    assert.equal(blank.ticks.length,0);assert.equal(blank.settings.length,0);
    assert.equal(blank.reads.some(read=>read.runtime==='1'),false);
    console.log('PASS a new unsaved Chat explains activation without creating a conversation, model work or an inherited opt-in');
  }finally{await blank.context.close();}

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
}finally{await browser.close();}
