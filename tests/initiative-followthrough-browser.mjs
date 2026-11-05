import assert from 'node:assert/strict';
import fs from 'node:fs/promises';
import {chromium} from 'playwright';

// The shipped Chat/voice clients and reply CSS are real. HTTP persistence,
// intent/provider output, CAS and source changes are controlled transport
// fixtures; actual authority/execution tests live at the PHP boundary.
const files=['chat.js','chat-voice.js'];
const sources=Object.fromEntries(await Promise.all(files.map(async name=>[name,await fs.readFile(new URL('../'+name,import.meta.url),'utf8')])));
const css=await fs.readFile(new URL('../chat.css',import.meta.url),'utf8');
const preVoice=(await fs.readFile(new URL('../chat.php',import.meta.url),'utf8')).match(/\$voiceBootstrap = '([^']+)';/)[1];
const browser=await chromium.launch({headless:true,executablePath:process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH||undefined});
const origin='https://initiative-followthrough.test';
const reference=id=>(id===10?'a':id===11?'b':'c').repeat(64);
function gate(){let arrive,release;return {arrived:new Promise(r=>{arrive=r;}),released:new Promise(r=>{release=r;}),arrive:()=>arrive(),release:()=>release()};}

async function fixture(agent=7,{mobile=false,voice=false}={}){
  const context=await browser.newContext({viewport:{width:mobile?390:1280,height:850}}),sends=[],errors=[];
  const posts=new Map([10,11,20].map(id=>[id,{message_id:id,owner_user_id:1,agent_id:agent,
    conversation_id:id===20?43:42,run_id:90+id,reply_ref:reference(id),revision:1,state:'open'}]));
  const histories=new Map([[42,[10,11]],[43,[20]]]);
  const saved=new Map([[42,[]],[43,[]]]);
  let nextId=30,hold=null,activityHold=null,implicitId=0,responseScope=null,responseFields=null,fail=false,failInitiative=false,loseResponse=false,queued=0,nextState='queued';
  const stale=new Set(),unavailable=new Set();
  await context.addInitScript(({voice})=>{
    window.recognizers=[];window.speechCalls=0;
    window.SpeechSynthesisUtterance=class{constructor(text){this.text=text;}};
    Object.defineProperty(window,'speechSynthesis',{value:{cancel(){},speak(u){window.speechCalls++;queueMicrotask(()=>{u.onstart?.();u.onend?.();});}}});
    if(voice)window.SpeechRecognition=class{
      constructor(){window.recognizers.push(this);}start(){this.active=true;queueMicrotask(()=>this.onstart?.());}
      stop(){this.active=false;queueMicrotask(()=>this.onend?.());}abort(){this.stop();}
    };
    else window.SpeechRecognition=undefined;
    window.StonefellowPremiumVoiceV122=()=>({warm:async()=>false,stop(){},createStream(){}});
  },{voice});
  const messages=cid=>[
    ...histories.get(cid).map(id=>{const p=posts.get(id);return {id,role:'assistant',message:id===10?'I can investigate the launch research.':id===11?'I can prepare the product follow-up.':'Another Chat has its own proposal.',
      context_json:JSON.stringify({source:'agent_initiative',initiative_work:{owner_user_id:p.owner_user_id,agent_id:p.agent_id,
        conversation_id:p.conversation_id,run_id:p.run_id,review_actions:[],followthrough:{reply_ref:p.reply_ref,revision:p.revision,state:p.state}}})};}),
    ...(cid===42?[{id:12,role:'assistant',message:'Foreign owner metadata must not have a Reply action.',context_json:JSON.stringify({initiative_work:{owner_user_id:2,agent_id:agent,conversation_id:42,run_id:102,
      review_actions:[],followthrough:{reply_ref:'d'.repeat(64),revision:1,state:'open'}}})},{id:13,role:'assistant',message:'An ordinary response.'},
      {id:14,role:'assistant',message:'An ordinary response cannot manufacture an Initiative reply.',context_json:JSON.stringify({source:'ordinary',initiative_work:{owner_user_id:1,agent_id:agent,conversation_id:42,run_id:104,review_actions:[],followthrough:{reply_ref:'e'.repeat(64),revision:1,state:'open'}}})}]:[]),
    ...saved.get(cid)
  ];
  await context.route(origin+'/**',async route=>{
    const request=route.request(),url=new URL(request.url());
    if(url.pathname==='/')return route.fulfill({contentType:'text/html',body:`<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
      <style>[hidden]{display:none!important}*{box-sizing:border-box}body{margin:0;padding:12px}#chatThread{height:520px;overflow:auto}.message{padding:8px}.chat-agent-actions{display:flex;gap:8px;flex-wrap:wrap}.chat-initiative-reply-cue{max-width:790px}#chatForm{display:flex;gap:8px;max-width:790px;margin:auto}textarea{min-width:0;flex:1}${css.slice(css.indexOf('.chat-initiative-reply-cue'))}</style>
      <button id="newChatButton">New Chat</button><div id="chatHistory"></div><section id="chatThread" data-view="chat"><div id="chatWelcome" hidden></div></section>
      <div id="chatComposerShell"><form id="chatForm"><textarea id="chatInput"></textarea><button id="sendChatButton">Send</button><button type="button" id="chatVoiceButton">Voice</button></form><div id="chatVoiceStatus"></div></div>
      <script>window.STONEFELLOW_CHAT={userId:1,agentId:${agent},initialConversationId:42,initialView:'chat',endpoint:'/api/chat-v236.php',csrf:'fixture',workingAttentionBinding:'owner-a-session'};</script>
      ${preVoice}<script src="/chat.js"></script><script src="/chat-voice.js"></script>`});
    if(sources[url.pathname.slice(1)])return route.fulfill({contentType:'text/javascript',body:sources[url.pathname.slice(1)]});
    if(!url.pathname.startsWith('/api/chat'))return route.fulfill({status:404});
    const body=request.postDataJSON(),cid=Number(body.conversation_id||42);
    if(body.action==='send'){
      assert.equal(request.headers()['x-vp3-working-attention'],'owner-a-session');assert.equal(body.csrf_token,'fixture');
      sends.push(body);
      if(fail){fail=false;return route.abort('internetdisconnected');}
      if(failInitiative){failInitiative=false;return route.fulfill({status:503,json:{ok:false,error:'initiative_reply_unavailable'}});}
      const wait=hold;hold=null;if(wait){wait.arrive();await wait.released;}
      const selected=body.initiative_reply,p=posts.get(selected?.message_id||implicitId);
      if(selected&&(!p||p.conversation_id!==cid||p.reply_ref!==selected.reply_ref||p.revision!==selected.revision||!['open','discussed','deferred','queued','stale'].includes(p.state)||stale.has(p.message_id))){
        if(url.pathname.includes('stream'))return route.fulfill({contentType:'application/x-ndjson',body:JSON.stringify({type:'error',error:'initiative_reply_stale',status:409})+'\n'});
        return route.fulfill({status:409,json:{ok:false,error:'initiative_reply_stale'}});
      }
      const userId=nextId++,assistantId=nextId++;
      let outcome;
      if(p){p.revision++;p.state=nextState;if(nextState==='queued')queued++;
        p.reply_message_id=userId;p.continuation_run_id=nextState==='queued'?800+queued:0;outcome={...p};}
      const answer=p?'The selected Agent update is now '+nextState+'.':'Which Agent update do you mean?';
      saved.get(cid).push({id:userId,role:'user',message:body.message},{id:assistantId,role:'assistant',message:answer,
        context_json:JSON.stringify({initiative_followthrough:outcome})});
      const data={ok:true,conversation_id:cid,user_message_id:userId,assistant_message_id:assistantId,answer,initiative_followthrough:outcome&&responseScope?{...outcome,...responseScope}:outcome,...responseFields};
      if(loseResponse){loseResponse=false;return route.abort('internetdisconnected');}
      if(url.pathname.includes('stream'))return route.fulfill({contentType:'application/x-ndjson',body:JSON.stringify({type:'start',conversation_id:cid})+'\n'+JSON.stringify({type:'done',data})+'\n'});
      return route.fulfill({json:data});
    }
    const list=messages(cid);
    if(body.action==='messages_after')return route.fulfill({json:{ok:true,messages:list.filter(item=>item.id>body.after_id)}});
    if(body.action==='activity'){
      const json={ok:true,conversation_id:cid,latest_message_id:list.at(-1)?.id||0,latest_id:0,updates:[],unread_count:0,
      initiative_updates:(body.initiative_message_ids||[]).map(id=>{const p=posts.get(id);return !p||p.conversation_id!==cid||unavailable.has(id)?{message_id:id,available:false}
        :{message_id:id,available:true,recoverable:p.recoverable===true||p.state==='stale',child_execution_state:p.child_execution_state||'',initiative_followthrough:{reply_message_id:0,continuation_run_id:0,...p}};})};
      const wait=activityHold;activityHold=null;if(wait){wait.arrive();await wait.released;}
      return route.fulfill({json});
    }
    return route.fulfill({json:{ok:true,messages:body.action==='load'?list:[],conversations:[{id:42,title:'Main Chat'},{id:43,title:'Other Chat'}]}});
  });
  const open=async()=>{const page=await context.newPage();page.setDefaultTimeout(8000);page.on('pageerror',e=>errors.push(e.message));await page.clock.install();await page.goto(origin,{waitUntil:'domcontentloaded'});
    await page.waitForFunction(()=>window.STONEFELLOW_CHAT_CONTINUITY_V87?.isReady()&&!window.STONEFELLOW_CHAT_CONTINUITY_V87.isRestoring());return page;};
  return {context,open,sends,errors,posts,get queued(){return queued;},implicit(id){implicitId=id;},unavailable(){failInitiative=true;},responseScope(value){responseScope=value;},responseFields(value){responseFields=value;},hold(){hold=gate();return hold;},holdActivity(){activityHold=gate();return activityHold;},fail(){fail=true;},loseResponse(){loseResponse=true;},stale(id){stale.add(id);},projection(id,available){available?unavailable.delete(id):unavailable.add(id);},state(state){nextState=state;},
    outcome(id,changes={}){const p=posts.get(id),outcome={...p,reply_message_id:30,continuation_run_id:801,...changes};
      saved.get(p.conversation_id).push({id:nextId++,role:'assistant',message:'Saved status update.',context_json:JSON.stringify({initiative_followthrough:outcome})});}};
}
const choose=(page,id)=>page.locator(`[data-initiative-reply="${id}"]`).click();
const send=async(page,text)=>{await page.locator('#chatInput').fill(text);await page.locator('#chatInput').press('Enter');};
const settled=page=>page.waitForFunction(()=>!window.STONEFELLOW_CHAT_CONTINUITY_V87.isBusy());

try{
  for(const [agent,mobile] of [[0,false],[7,true]]){
    const f=await fixture(agent,{mobile});
    try{
      const page=await f.open();assert.equal(await page.locator('[data-initiative-reply]').count(),2);
      await choose(page,10);await page.locator('#chatInitiativeReplyCue').getByText('I can investigate the launch research.',{exact:true}).waitFor();
      assert.equal(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),true);
      await send(page,'Please advance this private work.');await settled(page);
      assert.deepEqual(f.sends[0].initiative_reply,{message_id:10,reply_ref:reference(10),revision:1});
      assert.equal(await page.locator('[data-initiative-reply="10"]').count(),1);
      await page.locator('[data-initiative-followthrough-state="10"]').getByText('Private work queued',{exact:true}).waitFor();
      assert.equal(await page.locator('[data-initiative-reply="11"]').count(),1);assert.equal(await page.locator('#chatInitiativeReplyCue').count(),0);
      assert.equal(f.queued,1);assert.equal(await page.evaluate(()=>window.speechCalls),0);
      await page.reload({waitUntil:'domcontentloaded'});await page.waitForFunction(()=>window.STONEFELLOW_CHAT_CONTINUITY_V87?.isReady()&&!window.STONEFELLOW_CHAT_CONTINUITY_V87.isRestoring());
      assert.equal(await page.locator('[data-initiative-reply="10"]').count(),1);assert.equal(await page.locator('[data-initiative-reply="11"]').count(),1);
      assert.deepEqual(f.errors,[]);console.log(`PASS ${mobile?'named mobile':'System desktop'} exact first-post Reply, canonical outcome reconciliation/reload, isolated second post, no startup speech or new greeting widget`);
    }finally{await f.context.close();}
  }

  const recovery=await fixture();
  try{
    const page=await recovery.open();await choose(page,10);await page.locator('#chatInput').fill('Keep this exact proposal context.');
    await page.reload({waitUntil:'domcontentloaded'});await page.locator('#chatInitiativeReplyCue').waitFor();
    assert.equal(await page.locator('#chatInput').inputValue(),'Keep this exact proposal context.');
    await page.evaluate(()=>window.STONEFELLOW_CHAT_CONTINUITY_V87.openConversation(43));assert.equal(await page.locator('#chatInitiativeReplyCue').count(),0);
    await page.evaluate(()=>window.STONEFELLOW_CHAT_CONTINUITY_V87.openConversation(42));await page.locator('#chatInitiativeReplyCue').waitFor();
    recovery.fail();await page.locator('#sendChatButton').click();await page.getByText('Could not connect to Chat. Please try again.',{exact:true}).waitFor();
    await page.locator('#chatInitiativeReplyCue').waitFor();await page.locator('#sendChatButton').click();await settled(page);
    assert.deepEqual(recovery.sends.map(row=>row.initiative_reply),[{message_id:10,reply_ref:reference(10),revision:1},{message_id:10,reply_ref:reference(10),revision:1}]);
    assert.equal(recovery.queued,1);console.log('PASS recovered and failed-send drafts retain exact reply context across reload and conversation switches without targeting another post');
  }finally{await recovery.context.close();}

  const offline=await fixture();
  try{
    const page=await offline.open();await choose(page,10);await page.locator('#chatInput').fill('Keep this proposal while offline.');
    await page.evaluate(()=>Object.defineProperty(navigator,'onLine',{configurable:true,value:false}));
    await page.locator('#sendChatButton').click();assert.equal(offline.sends.length,0);await page.locator('#chatInitiativeReplyCue').waitFor();
    await page.evaluate(()=>{Object.defineProperty(navigator,'onLine',{configurable:true,value:true});window.dispatchEvent(new Event('online'));});
    assert.equal(offline.sends.length,0);await page.locator('#sendChatButton').click();await settled(page);
    assert.deepEqual(offline.sends[0].initiative_reply,{message_id:10,reply_ref:reference(10),revision:1});
    console.log('PASS offline preserves the exact draft/cue and reconnect never replays it without an owner send');
  }finally{await offline.context.close();}

  const lost=await fixture();
  try{
    const page=await lost.open();await choose(page,10);lost.loseResponse();await send(page,'Advance this proposal once.');
    await page.getByText('Could not connect to Chat. Please try again.',{exact:true}).waitFor();assert.equal(lost.queued,1);
    await page.locator('#sendChatButton').click();await page.getByText('This Agent update changed',{exact:true}).waitFor();assert.equal(lost.queued,1);
    await page.evaluate(()=>window.STONEFELLOW_CHAT_CONTINUITY_V87.syncConversation(42));
    await page.locator('[data-initiative-followthrough-state="10"]').getByText('Private work queued',{exact:true}).waitFor();
    assert.equal(lost.sends.length,2);assert.equal(await page.locator('[data-initiative-reply="10"]').count(),1);
    console.log('PASS a lost acknowledgement followed by retry cannot queue duplicate work; the saved outcome reconciles the original post');
  }finally{await lost.context.close();}

  const stale=await fixture();
  try{
    const page=await stale.open();await choose(page,10);const held=stale.hold();await send(page,'Advance this proposal.');await held.arrived;stale.stale(10);held.release();
    await page.getByText('This Agent update changed',{exact:true}).waitFor();assert.equal(stale.queued,0);
    const count=stale.sends.length;await page.locator('#sendChatButton').click();assert.equal(stale.sends.length,count);
    await page.getByRole('button',{name:'Remove Agent reply context'}).click();assert.equal(await page.locator('#chatInput').inputValue(),'Advance this proposal.');
    console.log('PASS a source change during a delayed associated reply keeps the text, marks its cue stale and blocks accidental unbound retry');
  }finally{await stale.context.close();}

  const restoredStale=await fixture();
  try{
    const page=await restoredStale.open();await choose(page,10);await page.locator('#chatInput').fill('Yes, advance that work.');
    Object.assign(restoredStale.posts.get(10),{revision:2,state:'queued'});
    await page.reload({waitUntil:'domcontentloaded'});await page.getByText('This Agent update changed',{exact:true}).waitFor();
    await page.locator('#sendChatButton').click();assert.equal(restoredStale.sends.length,0);
    assert.equal(await page.locator('#chatInput').inputValue(),'Yes, advance that work.');
    await choose(page,11);await page.locator('#sendChatButton').click();await settled(page);
    assert.equal(restoredStale.sends[0].initiative_reply.message_id,11);
    console.log('PASS a restored draft with an obsolete proposal revision is visibly blocked until the owner explicitly selects a current post');
  }finally{await restoredStale.context.close();}

  const tabs=await fixture();
  try{
    const first=await tabs.open(),second=await tabs.open();await choose(first,10);await choose(second,10);
    await send(first,'Advance the selected work.');await settled(first);await send(second,'Advance the selected work.');await settled(second);
    assert.equal(tabs.queued,1);await second.getByText('This Agent update changed',{exact:true}).waitFor();
    await second.evaluate(()=>window.STONEFELLOW_CHAT_CONTINUITY_V87.syncConversation(42));
    await second.locator('[data-initiative-followthrough-state="10"]').getByText('Private work queued',{exact:true}).waitFor();
    assert.equal(await second.locator('[data-initiative-reply="10"]').count(),1);
    console.log('PASS two tabs cannot dispatch one proposal twice, and a canonical saved outcome updates the older post while message synchronization skips its original ID');
  }finally{await tabs.context.close();}

  const ordering=await fixture();
  try{
    const page=await ordering.open();await choose(page,10);await send(page,'Advance this exact update.');await settled(page);
    ordering.outcome(10,{reply_ref:'f'.repeat(64),revision:99,state:'open'});
    ordering.outcome(10,{owner_user_id:2,revision:99,state:'open'});
    ordering.outcome(10,{run_id:999,revision:99,state:'open'});
    ordering.outcome(10,{revision:1,state:'open'});
    ordering.outcome(10,{revision:2,state:'completed'});
    await page.evaluate(()=>window.STONEFELLOW_CHAT_CONTINUITY_V87.syncConversation(42));
    await page.locator('[data-initiative-followthrough-state="10"]').getByText('Private work completed',{exact:true}).waitFor();
    assert.equal(await page.locator('[data-initiative-reply="10"]').count(),0);assert.equal(ordering.queued,1);
    console.log('PASS mismatched or older outcomes cannot reopen or poison an exact post; a later verified completion still reconciles');
  }finally{await ordering.context.close();}

  const loadedQueued=await fixture();
  try{
    Object.assign(loadedQueued.posts.get(10),{revision:2,state:'queued',reply_message_id:30,continuation_run_id:801});
    const page=await loadedQueued.open();
    await page.locator('[data-initiative-followthrough-state="10"]').getByText('Private work queued',{exact:true}).waitFor();
    Object.assign(loadedQueued.posts.get(10),{state:'completed'});
    await page.clock.fastForward(3500);
    await page.locator('[data-initiative-followthrough-state="10"]').getByText('Private work completed',{exact:true}).waitFor();
    assert.equal(await page.locator('[data-initiative-reply="10"]').count(),0);
    console.log('PASS the first authoritative completed projection reconciles a reloaded queued root at the same revision when its acknowledgement was outside restored history');
  }finally{await loadedQueued.context.close();}

  const terminal=await fixture();
  try{
    Object.assign(terminal.posts.get(10),{revision:2,state:'stale'});
    const page=await terminal.open();
    await page.locator('[data-initiative-followthrough-state="10"]').getByText('Follow-through needs review',{exact:true}).waitFor();
    assert.equal(terminal.sends.length,0);await choose(page,10);await send(page,'Please retry the private preparation.');await settled(page);
    assert.equal(terminal.sends[0].initiative_reply.revision,2);assert.equal(terminal.queued,1);
    terminal.state('owner_resolved');await choose(page,11);await send(page,'I have already dealt with that.');await settled(page);
    await page.locator('[data-initiative-followthrough-state="11"]').getByText('Marked resolved by you',{exact:true}).waitFor();
    assert.equal(terminal.queued,1);assert.equal(await page.locator('[data-initiative-reply="11"]').count(),0);
    console.log('PASS a terminal private-work state offers an explicit bound reply without auto-retry; an owner resolution is labelled as an owner claim rather than verified work');
  }finally{await terminal.context.close();}

  const live=await fixture();
  try{
    const page=await live.open();await choose(page,10);await page.locator('#chatInput').fill('Keep this exact draft.');
    live.projection(10,false);await page.clock.fastForward(3500);
    await page.getByText('This Agent update could not be verified',{exact:true}).waitFor();
    await page.locator('#sendChatButton').click();assert.equal(live.sends.length,0);
    assert.equal(await page.locator('#chatInput').inputValue(),'Keep this exact draft.');
    live.projection(10,true);await page.clock.fastForward(3500);
    await page.getByText('Replying to Agent update',{exact:true}).waitFor();
    await page.locator('#sendChatButton').click();await settled(page);assert.equal(live.queued,1);
    live.posts.get(10).recoverable=true;await page.clock.fastForward(3500);
    await page.locator('[data-initiative-followthrough-state="10"]').getByText('Follow-through needs review',{exact:true}).waitFor();
    assert.equal(live.posts.get(10).state,'queued');assert.equal(live.sends.length,1);
    live.posts.get(10).child_execution_state='failed';await page.clock.fastForward(3500);
    await page.locator('[data-initiative-followthrough-state="10"]').getByText('Private work interrupted. Reply to review or retry.',{exact:true}).waitFor();
    Object.assign(live.posts.get(10),{state:'stale',revision:3});
    await page.clock.fastForward(3500);
    await page.locator('[data-initiative-followthrough-state="10"]').getByText('Private work interrupted. Reply to review or retry.',{exact:true}).waitFor();
    await choose(page,10);await send(page,'Retry that failed private preparation.');await settled(page);
    assert.equal(live.sends.at(-1).initiative_reply.revision,3);assert.equal(live.queued,2);
    console.log('PASS existing activity reads reconcile an old post without a new message; unverifiable status preserves the draft and recovers without inventing a stale decision');
  }finally{await live.context.close();}

  const activitySwitch=await fixture();
  try{
    const page=await activitySwitch.open();await choose(page,10);await page.locator('#chatInput').fill('Keep this same Chat draft.');
    const held=activitySwitch.holdActivity();activitySwitch.projection(10,false);await page.clock.fastForward(3500);await held.arrived;
    activitySwitch.projection(10,true);
    await page.evaluate(()=>window.STONEFELLOW_CHAT_CONTINUITY_V87.openConversation(43));
    await page.evaluate(()=>window.STONEFELLOW_CHAT_CONTINUITY_V87.openConversation(42));
    held.release();await page.clock.fastForward(500);
    await page.getByText('Replying to Agent update',{exact:true}).waitFor();
    assert.equal(await page.getByText('Status could not be verified.',{exact:true}).count(),0);
    await page.locator('#sendChatButton').click();await settled(page);assert.equal(activitySwitch.queued,1);
    console.log('PASS a delayed unavailable projection cannot clobber the same Chat after switching away and restoring its current exact reply context');
  }finally{await activitySwitch.context.close();}

  for(const change of [{agentId:8},{workingAttentionBinding:'rotated-session'}]){
    const f=await fixture();
    try{const page=await f.open();await choose(page,10);await page.locator('#chatInput').fill('Do this selected update.');
      await page.evaluate(change=>{window.STONEFELLOW_CHAT={...window.STONEFELLOW_CHAT,...change};},change);
      await page.clock.fastForward(300);assert.equal(await page.locator('#chatInitiativeReplyCue').count(),0);
      await page.locator('#sendChatButton').click();assert.equal(f.sends.length,0);
    }finally{await f.context.close();}
  }
  console.log('PASS changing only the Agent or session invalidates the cue and fences the old proposal before sending');

  const account=await fixture();
  try{
    const page=await account.open();await choose(page,10);const held=account.hold();await send(page,'Discuss this exact update.');await held.arrived;
    await page.evaluate(()=>window.STONEFELLOW_CHAT={...window.STONEFELLOW_CHAT,userId:2,workingAttentionBinding:'owner-b-session'});held.release();
    await page.clock.fastForward(300);await settled(page);
    assert.equal(await page.locator('#chatInitiativeReplyCue').count(),0);assert.equal(await page.getByText('The selected Agent update is now queued.',{exact:true}).count(),0);
    const count=account.sends.length;await send(page,'Do it');assert.equal(account.sends.length,count);
    console.log('PASS account/session replacement clears the selected cue, rejects a late result and cannot send the old proposal to the new account');
  }finally{await account.context.close();}

  const implicitAccount=await fixture();
  try{
    implicitAccount.implicit(10);const page=await implicitAccount.open(),held=implicitAccount.hold();
    await send(page,'yes');await held.arrived;
    assert.equal(Object.hasOwn(implicitAccount.sends[0],'initiative_reply'),false);
    await page.evaluate(()=>window.STONEFELLOW_CHAT={...window.STONEFELLOW_CHAT,userId:2,workingAttentionBinding:'owner-b-session'});held.release();
    await settled(page);
    assert.equal(await page.getByText('The selected Agent update is now queued.',{exact:true}).count(),0);
    assert.equal(await page.evaluate(()=>window.speechCalls),0);
    console.log('PASS an implicitly associated ordinary-payload response cannot paint or speak private acknowledgement after the account/session changes');
  }finally{await implicitAccount.context.close();}

  const implicitFailure=await fixture();
  try{
    const page=await implicitFailure.open();implicitFailure.implicit(10);implicitFailure.unavailable();await send(page,'yes');await settled(page);
    await page.getByText('Follow-through is temporarily unavailable. Your message is kept. Retry the same message when ready.',{exact:true}).waitFor();
    assert.equal(await page.locator('#chatInput').inputValue(),'yes');assert.equal(await page.locator('#chatInitiativeReplyCue').count(),0);
    assert.equal(await page.getByText('initiative_reply_unavailable',{exact:true}).count(),0);
    await page.locator('#sendChatButton').click();await settled(page);assert.equal(implicitFailure.queued,1);
    assert.equal(implicitFailure.sends.every(body=>!Object.hasOwn(body,'initiative_reply')),true);
    console.log('PASS a recognized implicit followthrough failure keeps ordinary draft/retry semantics with useful recovery copy and no invented Reply cue or machine-code message');
  }finally{await implicitFailure.context.close();}

  for(const wrong of [{owner_user_id:2},{agent_id:8},{conversation_id:43},{message_id:11},{reply_ref:'f'.repeat(64)},{reply_message_id:999},{badAssistantId:true}]){
    const f=await fixture();
    try{
      const page=await f.open();await choose(page,10);if(wrong.badAssistantId)f.responseFields({assistant_message_id:0});else f.responseScope(wrong);await send(page,'Discuss this exact proposal.');await settled(page);
      await page.getByText('The Agent reply could not be matched to this Chat. Your message is kept.',{exact:true}).waitFor();
      assert.equal(await page.getByText('The selected Agent update is now queued.',{exact:true}).count(),0);
      assert.equal(await page.locator('#chatInput').inputValue(),'Discuss this exact proposal.');assert.equal(await page.evaluate(()=>window.speechCalls),0);
    }finally{await f.context.close();}
  }
  console.log('PASS a wrong owner/Agent/Chat/root/reference in returned followthrough metadata cannot paint or speak its answer before metadata reconciliation');

  const voice=await fixture(7,{voice:true,mobile:true});
  try{
    const page=await voice.open();voice.state('discussed');await choose(page,10);
    await page.evaluate(()=>window.VP3ConversationControls.listen());
    await page.waitForFunction(()=>window.recognizers.some(r=>r.active));
    await page.evaluate(()=>{const r=window.recognizers.find(r=>r.active);r.onresult({resultIndex:0,results:[Object.assign([{transcript:'Discuss this first research idea'}],{isFinal:true})]});});
    await page.clock.fastForward(2200);await page.getByText('The selected Agent update is now discussed.',{exact:true}).waitFor();await settled(page);
    assert.equal(voice.sends[0].input_mode,'voice');assert.deepEqual(voice.sends[0].initiative_reply,{message_id:10,reply_ref:reference(10),revision:1});
    await page.clock.fastForward(5000);await choose(page,11);voice.stale(11);
    await page.evaluate(()=>window.VP3ConversationControls.listen());await page.waitForFunction(()=>window.recognizers.some(r=>r.active));
    await page.evaluate(()=>{const r=window.recognizers.find(r=>r.active);r.onresult({resultIndex:0,results:[Object.assign([{transcript:'Research the second product proposal'}],{isFinal:true})]});});
    await page.clock.fastForward(2200);await page.getByText('This Agent update changed',{exact:true}).waitFor();
    assert.equal(voice.sends[1].input_mode,'voice');assert.equal(voice.sends[1].initiative_reply.message_id,11);
    assert.equal(await page.evaluate(()=>window.STONEFELLOW_CHAT_VOICE.fastStreamTurns),2);assert.equal(voice.queued,0);
    await page.evaluate(()=>window.VP3ConversationControls.stop());assert.deepEqual(voice.errors,[]);
    console.log('PASS actual voice recognition/form/stream transport preserves the exact reply binding and SSE409 marks the same stale cue without another tool action');
  }finally{await voice.context.close();}
}finally{await browser.close();}
