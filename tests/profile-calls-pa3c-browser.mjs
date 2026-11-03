import assert from 'node:assert/strict';
import fs from 'node:fs';
import http from 'node:http';
import {createRequire} from 'node:module';
import {contract,callCard,copy,installFakeMedia} from './fixtures/profile-calls-pa3c.mjs';
import {mainChatMarkup} from './fixtures/profile-visitor-doorbell-pa3.mjs';
import {chatChoice} from './fixtures/profile-chat-choice-pa3d.mjs';

const {chromium}=createRequire(import.meta.url)(process.env.VP3_PLAYWRIGHT_MODULE||'playwright');
const read=file=>fs.readFileSync(new URL('../'+file,import.meta.url),'utf8');
const files={'/calls.js':read('profile-calls-pa3c.js'),'/calls.css':read('profile-calls-pa3c.css'),'/chat.js':read('chat.js'),'/chat.css':read('chat.css'),'/profile.css':read('profile.css'),'/profile-agent.js':read('profile-agent.js'),'/lease.js':read('voice-lease-v122.js')};
const namespace='a'.repeat(64),storageKey='stonefellow-profile-agent:v1:fixture-owner:'+namespace;
const chatStyle='html,body{margin:0;height:100%;overflow:hidden}.chat-app{height:100dvh}.chat-sidebar{display:none}.chat-main{height:100dvh;display:flex;flex-direction:column;min-width:0}.chat-thread{overflow:auto;flex:1;min-height:0;padding:12px}.chat-thread .message{max-width:100%;box-sizing:border-box}.message-body{min-width:0;max-width:100%}#chatComposerShell{flex-shrink:0;padding:8px}#chatForm{display:flex;gap:8px}#chatInput{min-width:0;flex:1}';
const publicChoiceControls='<div data-profile-agent-disclosure class="profile-agent-disclosure">AI representative</div><div data-profile-live-choice class="profile-agent-live-choice"><label><input type="checkbox" data-profile-live-chat disabled><span>Request live text chat with <strong data-profile-owner-name>Human Owner</strong></span></label><small data-profile-live-availability role="status">Checking availability</small><button type="button" data-profile-end-live hidden>Return to Agent</button></div>';
const publicMarkup=`<!doctype html><html><head><meta name="viewport" content="width=device-width,initial-scale=1"><link rel="stylesheet" href="/profile.css"><link rel="stylesheet" href="/calls.css"><style>html,body{margin:0}.profile-agent-widget{position:static;width:100%;max-width:520px;margin:auto;max-height:100dvh;box-sizing:border-box}.profile-agent-thread{min-height:160px}textarea{font-size:16px}#privateFixture{display:none}</style></head><body><section id="profileAgentShell" class="profile-agent-widget"><button id="closeWidget" type="button">Close call view</button>${publicChoiceControls}<div class="profile-agent-thread" data-profile-agent-thread></div><form class="profile-agent-compose"><textarea aria-label="Message Profile Agent"></textarea><button type="submit">Send</button><div data-profile-agent-status role="status"></div></form></section><textarea id="privateFixture">PRIVATE_TEXT_NEVER_FORWARD</textarea><script>window.STONEFELLOW_PROFILE_AGENT={username:'fixture-owner',endpoint:'/api/profile-agent.php',callEndpoint:'/api/profile-calls-pa3c.php',callOwnerName:'Human Owner',conversationNamespace:'${namespace}',profileToken:'FIXTURE_PROFILE_TOKEN'};document.getElementById('closeWidget').onclick=()=>{document.getElementById('profileAgentShell').hidden=true;window.dispatchEvent(new CustomEvent('vp3:profile-agent-visibility',{detail:{open:false}}));};</script><script data-voice-capture-scope="fixture-user" src="/lease.js"></script><script src="/calls.js"></script><script src="/profile-agent.js"></script></body></html>`;
const server=http.createServer((request,response)=>{
  const path=new URL(request.url,'http://localhost').pathname;
  if(files[path]){response.writeHead(200,{'Content-Type':path.endsWith('.css')?'text/css':'text/javascript'});response.end(files[path]);return;}
  response.writeHead(200,{'Content-Type':'text/html'});
  if(path==='/visitor'){response.end(publicMarkup);return;}
  response.end(mainChatMarkup.replace('</head>','<link rel="stylesheet" href="/chat.css"><link rel="stylesheet" href="/calls.css"><style>'+chatStyle+'</style></head>').replace('</body>',`<script>window.STONEFELLOW_CHAT={userId:1,agentId:7,endpoint:'/api/chat-v236.php',csrf:'FIXTURE_CSRF',initialView:'chat',initialConversationId:42};window.VP3_PROFILE_CALLS_PA3C={role:'owner',endpoint:'/api/profile-calls-pa3c.php',csrf:'FIXTURE_CSRF',userId:1};</script><script src="/chat.js"></script><script data-voice-capture-scope="fixture-user" src="/lease.js"></script><script src="/calls.js"></script></body>`));
});
await new Promise(resolve=>server.listen(0,'127.0.0.1',resolve));const base='http://127.0.0.1:'+server.address().port;
let browser,checks=0;function check(ok,label){assert.ok(ok,label);checks++;console.log('PASS '+label);}
async function fixture({role='owner',initial=callCard(role),cid=31,viewport={width:390,height:844}}={}){
  const context=await browser.newContext({viewport}),requests=[],errors=[],holds=[],calls=new Map(initial?[[initial.call_id,copy(initial)]]:[]),chatMessages=new Map([[42,[]],[55,[]]]),publicMessages=[],clients=new Map();
  let currentCid=cid,nextMessage=700,ownerMode=Boolean(initial&&['preparing','ready','active'].includes(initial.state));
  await context.addInitScript({content:'('+installFakeMedia.toString()+')(window);HTMLMediaElement.prototype.play=function(){return Promise.resolve();};'});
  if(role==='visitor'&&cid)await context.addInitScript(({storageKey,cid})=>{if(!localStorage.getItem(storageKey))localStorage.setItem(storageKey,String(cid));},{storageKey,cid});
  const projected=(card,input={})=>({...copy(card),role,server_now_ms:Date.now(),...(role==='owner'?{chat_conversation_id:Number(input.chat_conversation_id||42),chat_agent_id:Number(input.chat_agent_id||7)}:{})});
  const profileChat=()=>chatChoice({owner_name:'Human Owner',mode:ownerMode?'auto':'agent',media_call_active:[...calls.values()].some(c=>['preparing','ready','active'].includes(c.state))});
  const state=(card,value)=>{if(['preparing','ready','active'].includes(value))ownerMode=true;return Object.assign(card,{state:value,revision:card.revision+1,can_accept:false,can_decline:false,can_cancel:false,can_end:['preparing','ready','active'].includes(value),can_join:['ready','active'].includes(value),cleanup_pending:value==='ending',dispatch_due:value==='preparing',can_return_to_agent:value==='ending'||value==='ended'});};
  await context.route('**/api/**',async route=>{
    const request=route.request(),url=new URL(request.url()),input=request.method()==='GET'?Object.fromEntries(url.searchParams):request.postDataJSON()||{};
    for(const key of ['call_id','revision','conversation_id','chat_conversation_id','chat_agent_id'])if(input[key]!==undefined)input[key]=Number(input[key]);requests.push({path:url.pathname,method:request.method(),...copy(input)});
    let result={ok:true},status=200;
    if(url.pathname==='/api/chat-v236.php'){
      if(input.action==='load')Object.assign(result,{conversation:{id:input.conversation_id},messages:copy(chatMessages.get(input.conversation_id)||[])});
      else if(input.action==='messages_after')Object.assign(result,{conversation_id:input.conversation_id,messages:copy((chatMessages.get(input.conversation_id)||[]).filter(m=>m.id>input.after_id))});
      else if(input.action==='send'){const cid=input.conversation_id||42,id=++nextMessage;chatMessages.get(cid).push({id,role:'assistant',message:'Private Chat response.',context_json:'{}'});Object.assign(result,{conversation_id:cid,user_message_id:id-1,assistant_message_id:id,answer:'Private Chat response.'});}
      else Object.assign(result,{conversations:[...chatMessages.keys()].map(id=>({id,title:'Private Chat '+id,updated_at:'2026-11-04 12:00:00'})),items:[],updates:[],tracks:[]});
    }else if(url.pathname==='/api/profile-agent.php'){
      if(input.action==='state'||input.action==='poll')Object.assign(result,{conversation:currentCid?{conversation_id:currentCid,status:ownerMode?'owner_joined':'open'}:null,agent:{greeting:''},chat:profileChat(),messages:publicMessages.filter(m=>m.id>Number(input.after_id||0))});
      else if(input.action==='message'){currentCid=currentCid||31;if(!profileChat().media_call_active&&input.chat_mode==='agent')ownerMode=false;const message={id:++nextMessage,sender_type:'visitor',message:input.message};publicMessages.push(message);Object.assign(result,{conversation_id:currentCid,visitor_message_id:message.id,client_request_id:input.client_request_id,conversation:{conversation_id:currentCid,status:ownerMode?'owner_joined':'open'},handoff:null,chat:profileChat()});}
      else throw new Error('Unexpected native widget action '+input.action);
    }else if(url.pathname==='/api/profile-calls-pa3c.php'){
      const op=input.action.replace(/^(owner|visitor)_/,'');
      if(!['state','heartbeat','delivery'].includes(op)&&! /^[a-f0-9]{32}$/.test(input.request_id||'')){result={ok:false,code:'PROFILE_CALL_INVALID'};status=422;}
      else if(op==='state')Object.assign(result,{contract,calls:role==='visitor'&&input.conversation_id===0?[]:[...calls.values()].map(c=>projected(c,input)),can_offer:calls.size===0&&(role!=='owner'||input.conversation_id>0),reason:'',server_now_ms:Date.now(),conversation_id:input.conversation_id||0});
      else{
        let call=calls.get(input.call_id);
        if(op==='offer'){currentCid=input.conversation_id||31;call=callCard(role,{conversation_id:currentCid,mode:input.mode,offered_by:role,can_accept:false,can_decline:false,can_cancel:true,offer_expires_at_ms:Date.now()+60000});calls.set(call.call_id,call);}
        if(!call)throw new Error('Unexpected missing fixture call');
        if(op==='accept')state(call,'preparing');else if(op==='dispatch'&&call.state==='preparing')state(call,'ready');else if(op==='end')state(call,'ending');else if(op==='return_to_agent'){call.can_return_to_agent=false;call.revision++;ownerMode=false;}
        if(op==='join'&&clients.has(role)&&clients.get(role)!==input.client_instance_id){status=409;result={ok:false,code:'PROFILE_CALL_IN_USE'};}
        else{
          if(op==='join'){clients.set(role,input.client_instance_id);call.authority_valid_until_ms=Date.now()+30000;}else if(op==='heartbeat')call.authority_valid_until_ms=Date.now()+30000;
          Object.assign(result,{contract,call:projected(call,input),call_id:call.call_id,revision:call.revision,conversation_id:call.conversation_id,request_id:input.request_id||'',duplicate:false});
          if(op==='join')Object.assign(result,{role,client_instance_id:input.client_instance_id,join_intent_id:'b'.repeat(32),server_url:'wss://fixture.livekit.cloud',participant_token:'FIXTURE_'+role.toUpperCase()+'_TOKEN_'+'x'.repeat(40),participant_identity:role+'-41',peer_identity:(role==='owner'?'visitor':'owner')+'-41',expires_at_ms:result.call.server_now_ms+60000,microphone:true,camera:input.camera});
          if(op==='delivery'){
            const cid=input.chat_conversation_id,messages=chatMessages.get(cid)||[],saved=messages.find(m=>JSON.parse(m.context_json||'{}').profile_call?.call_id===call.call_id);let id=saved?.id;
            if(!id){id=++nextMessage;messages.push({id,role:'assistant',message:'Profile visitor call.',context_json:JSON.stringify({profile_call:{contract,call_id:call.call_id,revision:call.revision,conversation_id:call.conversation_id}})});chatMessages.set(cid,messages);}
            Object.assign(result,{delivery_id:'d'.repeat(32),chat_message_id:id,message_id:id,chat_conversation_id:cid,chat_agent_id:input.chat_agent_id});
          }
        }
      }
    }else Object.assign(result,{items:[],updates:[],route:'chat',tracks:[]});
    const index=holds.findIndex(h=>h.action===input.action);const send=(payload=result,httpStatus=status)=>route.fulfill({status:httpStatus,contentType:'application/json',body:JSON.stringify(payload)});
    if(index>=0){const hold=holds.splice(index,1)[0];hold.arrived({input,result,release:overrides=>send({...result,...overrides})});}else await send();
  });
  const newPage=async()=>{const page=await context.newPage();page.setDefaultTimeout(15000);page.on('pageerror',error=>errors.push(error.message));await page.goto(base+(role==='visitor'?'/visitor':'/owner'));return page;};
  const page=await newPage();
  const f={context,page,requests,calls,clients,chatMessages,errors,newPage,card:p=>(p||page).locator('.profile-call-card'),hold:action=>new Promise(arrived=>holds.push({action,arrived})),
    async refresh(p=page){if(role==='owner')await p.evaluate(()=>window.VP3ProfileCallsPa3c.refresh());else await p.evaluate(()=>window.dispatchEvent(new CustomEvent('vp3:profile-agent-visibility',{detail:{open:true}})));},
    async ready(){state(calls.get(41),'ready');await this.refresh();await this.card().locator('[data-call-action=join]').waitFor({state:'visible'});},
    async consent(camera=false,p=page){await this.card(p).locator('input[type=checkbox]').nth(0).check();if(camera)await this.card(p).locator('input[type=checkbox]').nth(1).check();},
    async join(p=page){await this.card(p).locator('[data-call-action=join]').click();},
    async close(){check(errors.length===0,'production Chat/Profile scripts have no browser errors: '+errors.join('; '));await context.close();}};
  if(initial)await f.card().waitFor();else await page.locator('[data-call-offer=audio]:enabled').waitFor();return f;
}
try{
  browser=await chromium.launch({headless:true,...(process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH?{executablePath:process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH}:{})});
  {
    const f=await fixture();check(await f.page.evaluate(()=>__callMediaFixture.microphones===0&&__callMediaFixture.rooms.length===0),'restored main Chat call card does not capture or join');
    const marker=f.chatMessages.get(42)[0];check(JSON.parse(marker.context_json).profile_call.conversation_id===31&&!JSON.parse(marker.context_json).profile_handoff,'call-only delivery stores a distinct actual Chat marker without inventing a visitor text turn');
    await f.card().locator('[data-call-action=accept]').click();await f.card().locator('[data-call-action=join]').waitFor({state:'visible'});
    check(await f.card().locator('[data-call-action=join]').isDisabled()&&await f.page.evaluate(()=>__callMediaFixture.microphones===0),'accepted browser call still requires explicit local microphone consent');
    check(!(await f.card().locator('input[type=checkbox]').nth(1).isChecked()),'camera starts off in the mobile consent card');
    await f.consent();await f.join();await f.page.waitForFunction(()=>__callMediaFixture.microphones===1);
    check(await f.page.evaluate(()=>__callMediaFixture.cameras===0),'video mode with unchecked camera acquires only microphone');
    await f.page.evaluate(()=>__callMediaFixture.rooms[0].addPeer('visitor-41'));
    await f.card().locator('audio').waitFor({state:'attached'});check((await f.card().innerText()).includes('speaking directly'),'actual remote human audio mounts in the main Chat call card');
    await f.page.locator('#chatInput').fill('Private question while calling');await f.page.locator('#chatForm').evaluate(form=>form.requestSubmit());await f.page.getByText('Private Chat response.',{exact:true}).waitFor();
    check(f.requests.some(r=>r.path==='/api/chat-v236.php'&&r.message==='Private question while calling')&&!f.requests.some(r=>r.path==='/api/profile-calls-pa3c.php'&&JSON.stringify(r).includes('Private question')),'regular Chat composer stays private during the human call');
    const held=f.hold('owner_end');await f.card().locator('[data-call-action=end]').click();const ending=await held;
    check(await f.page.evaluate(()=>__callMediaFixture.tracks.filter(t=>t.local).every(t=>t.stopped)),'browser End stops local tracks before the held server response');await ending.release();await f.page.waitForFunction(()=>document.querySelector('.profile-call-status')?.textContent.includes('cleanup is pending'));
    await f.card().locator('[data-call-action=return_to_agent]').click();await f.page.getByText('The Profile Agent can respond to your next message.',{exact:true}).waitFor();
    check(f.requests.some(r=>r.action==='owner_return_to_agent'&&r.conversation_id===31&&r.chat_conversation_id===42),'Return to Agent is an explicit action for the same visitor and private Chat');
    await f.page.reload();await f.card().waitFor();check(await f.card().count()===1&&await f.page.locator('[data-chat-message-id="'+marker.id+'"]').count()===1,'real main Chat saved history and current delivery restore one exact call card');
    check(await f.page.evaluate(()=>__callMediaFixture.microphones===0&&__callMediaFixture.rooms.length===0),'reloading a saved call never automatically reconnects or captures');
    check(await f.page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1),'call card fits the mobile viewport without horizontal overflow');
    check(await f.card().evaluate(card=>[card,...card.querySelectorAll('*')].every(el=>!['auto','scroll'].includes(getComputedStyle(el).overflowY))),'call card adds no nested scrolling region inside the main Chat canvas');await f.close();
  }
  {
    const f=await fixture({role:'visitor',initial:null,cid:0});await f.page.locator('[data-call-offer=audio]').click();await f.card().waitFor();
    const offer=f.requests.find(r=>r.action==='visitor_offer');check(offer.conversation_id===0&&offer.profile_token==='FIXTURE_PROFILE_TOKEN'&&offer.conversation_namespace===namespace&&offer.path==='/api/profile-calls-pa3c.php','real public call-only request carries the captured native token and namespace');
    check(await f.page.evaluate(key=>localStorage.getItem(key),storageKey)==='31'&&!f.requests.some(r=>r.action==='message'),'call-only response adopts canonical public CID without sending a fake message');
    await f.ready();await f.consent();await f.join();await f.page.waitForFunction(()=>__callMediaFixture.microphones===1);
    await f.page.waitForFunction(()=>document.querySelector('[data-profile-live-chat]')?.disabled&&document.querySelector('[data-profile-agent-status]')?.textContent.includes('paused for your direct call'));
    check(await f.page.locator('[data-profile-end-live]').isHidden(),'browser live-text controls cannot end the accepted direct call');
    await f.page.getByRole('textbox',{name:'Message Profile Agent'}).fill('Public product question');await f.page.locator('.profile-agent-compose').evaluate(form=>form.requestSubmit());await f.page.waitForFunction(()=>document.querySelector('[data-profile-agent-thread]')?.textContent.includes('Public product question'));
    check(f.requests.some(r=>r.action==='message'&&r.conversation_id===31&&r.chat_mode==='auto'&&!Object.hasOwn(r,'continue_live'))&&await f.page.evaluate(()=>!__callMediaFixture.tracks[0].stopped),'native visitor text stays owner-directed in the same thread during a live call');
    await f.page.locator('#closeWidget').click();check(await f.page.evaluate(()=>__callMediaFixture.tracks.every(t=>t.stopped)),'closing the actual public call view stops media immediately');
    await f.page.waitForFunction(()=>document.querySelector('[data-profile-live-chat]')?.checked===true);
    await f.page.evaluate(()=>{document.getElementById('profileAgentShell').hidden=false;window.dispatchEvent(new CustomEvent('vp3:profile-agent-visibility',{detail:{open:true}}));window.dispatchEvent(new CustomEvent('vp3:profile-agent-open'));});
    await f.page.getByRole('textbox',{name:'Message Profile Agent'}).fill('Post-call message for the owner');await f.page.locator('.profile-agent-compose').evaluate(form=>form.requestSubmit());await f.page.waitForFunction(()=>!document.querySelector('.profile-agent-compose textarea')?.disabled&&[...document.querySelectorAll('.profile-agent-message.visitor')].some(n=>Number(n.dataset.messageId)>0&&n.textContent.includes('Post-call message for the owner')));
    check(f.requests.filter(r=>r.action==='message').at(-1).chat_mode==='auto','browser Hangup preserves the owner text target until an explicit switch');
    await f.page.locator('[data-profile-live-chat]').uncheck();await f.page.getByRole('textbox',{name:'Message Profile Agent'}).fill('Explicitly switch to Agent');await f.page.locator('.profile-agent-compose').evaluate(form=>form.requestSubmit());await f.page.waitForFunction(()=>!document.querySelector('.profile-agent-compose textarea')?.disabled&&[...document.querySelectorAll('.profile-agent-message.visitor')].some(n=>Number(n.dataset.messageId)>0&&n.textContent.includes('Explicitly switch to Agent')));
    check(f.requests.filter(r=>r.action==='message').at(-1).chat_mode==='agent','browser explicit unchecked choice routes the next message to Agent');
    check(!JSON.stringify(f.requests).includes('PRIVATE_TEXT_NEVER_FORWARD'),'public call and text adapters never include private Chat content');await f.close();
  }
  {
    const f=await fixture();await f.ready();await f.consent();await f.join();await f.page.waitForFunction(()=>__callMediaFixture.microphones===1);
    const second=await f.newPage();await f.card(second).waitFor();await f.consent(false,second);await f.join(second);await second.getByText('This call is already open in another tab. Keep using that tab.',{exact:true}).waitFor();
    check(await second.evaluate(()=>__callMediaFixture.rooms.length===0)&&!f.requests.some(r=>r.action==='owner_end'),'duplicate-tab Join is rejected without media capture or End');
    check(await f.page.evaluate(()=>__callMediaFixture.tracks.every(t=>!t.stopped)),'duplicate-tab rejection leaves the legitimate first call intact');await f.close();
  }
  {
    const f=await fixture();await f.ready();await f.consent(true);await f.page.evaluate(()=>{window.__lateCamera=__callMediaFixture.hold('camera');});await f.join();await f.page.waitForFunction(()=>Boolean(__lateCamera.pending));
    await f.card().locator('[data-call-action=end]').click();await f.page.evaluate(()=>__lateCamera.pending.reject());await f.page.waitForFunction(()=>__callMediaFixture.tracks.filter(t=>t.local).every(t=>t.stopped));
    check(await f.page.evaluate(()=>__callMediaFixture.tracks.some(t=>t.kind==='video')&&__callMediaFixture.tracks.every(t=>t.stopped)),'late rejected browser camera permission after End leaves no active track');await f.close();
  }
  console.log('PROFILE_CALLS_PA3C_BROWSER=PASS ('+checks+' assertions)');
}finally{await browser?.close();await new Promise(resolve=>server.close(resolve));}
