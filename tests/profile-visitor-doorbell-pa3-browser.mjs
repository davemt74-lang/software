import assert from 'node:assert/strict';
import fs from 'node:fs';
import http from 'node:http';
import {createRequire} from 'node:module';
import {copy,contract,ownerCard,chatChoice,mainChatMarkup} from './fixtures/profile-visitor-doorbell-pa3.mjs';

const {chromium}=createRequire(import.meta.url)(process.env.VP3_PLAYWRIGHT_MODULE||'playwright');
const read=name=>fs.readFileSync(new URL('../'+name,import.meta.url),'utf8');
const files={'/chat.js':read('chat.js'),'/doorbell.js':read('profile-visitor-doorbell-pa3.js'),'/chat.css':read('chat.css'),'/doorbell.css':read('profile-visitor-doorbell-pa3.css')};
const fixtureStyle='html,body{margin:0;height:100%;overflow:hidden}.chat-app{height:100dvh}.chat-sidebar{display:none}.chat-main{height:100dvh;display:flex;flex-direction:column;min-width:0}.chat-topbar-actions{display:flex;padding:10px;flex-shrink:0}.chat-thread{overflow:auto;flex:1;min-height:0;padding:12px}.chat-thread .message{max-width:100%;box-sizing:border-box}.message-body{min-width:0;max-width:100%}#chatComposerShell{flex-shrink:0;padding:8px}#chatForm{display:flex;gap:8px}#chatInput{min-width:0;flex:1}';
const server=http.createServer((req,res)=>{
 const path=new URL(req.url,'http://localhost').pathname;
 if(files[path]){res.writeHead(200,{'Content-Type':path.endsWith('.css')?'text/css':'text/javascript'});res.end(files[path]);return;}
 res.writeHead(200,{'Content-Type':'text/html'});
 res.end(mainChatMarkup.replace('</head>','<link rel="stylesheet" href="/chat.css"><link rel="stylesheet" href="/doorbell.css"><style>'+fixtureStyle+'</style></head>').replace('</body>',`<script>window.STONEFELLOW_CHAT={userId:1,agentId:7,endpoint:'/api/chat-v236.php',csrf:'fixture',initialView:'chat',initialConversationId:42};window.VP3_PROFILE_DOORBELL_PA3={endpoint:'/api/chat-notifications-brain-v240.php',ownerEndpoint:'/api/profile-agent.php',csrf:'fixture',userId:1};</script><script src="/chat.js"></script><script src="/doorbell.js"></script></body>`));
});
await new Promise(resolve=>server.listen(0,'127.0.0.1',resolve));
const base=`http://127.0.0.1:${server.address().port}`;
let browser,checks=0;const check=(value,label)=>{assert.ok(value,label);checks++;console.log('PASS '+label);};
async function fixture(viewport={width:1200,height:1000}){
 const page=await browser.newPage({viewport}),errors=[],calls=[],holds=[];
 page.on('pageerror',error=>errors.push(error.message));page.setDefaultTimeout(12000);
 let clock=Date.now(),availability='available',nextMessage=700,blankId=99;
 const turns=new Map([[101,ownerCard(101,{server_now_ms:clock})]]),messages=new Map([[42,[]],[55,[]]]),deliveries=new Map(),tabs=new Map(),operations=new Map();
 const putMessage=(cid,id,text,context={})=>{if(!messages.has(cid))messages.set(cid,[]);messages.get(cid).push({id,role:'assistant',message:text,context_json:JSON.stringify(context)});};
 await page.route('**/api/**',async route=>{
  const request=route.request(),input=request.postDataJSON()||{},path=new URL(request.url()).pathname;calls.push({path,...input});let payload={ok:true};
  if(path==='/api/chat-v236.php'){
   if(input.action==='load')Object.assign(payload,{conversation:{id:input.conversation_id},messages:copy(messages.get(input.conversation_id)||[])});
   else if(input.action==='messages_after')Object.assign(payload,{conversation_id:input.conversation_id,messages:copy((messages.get(input.conversation_id)||[]).filter(m=>m.id>input.after_id))});
   else if(input.action==='new'){messages.set(blankId,[]);payload.conversation_id=blankId++;}
   else if(input.action==='send'){
    const id=input.conversation_id||42,userId=++nextMessage,assistantId=++nextMessage;putMessage(id,assistantId,'Private Chat response.');
    Object.assign(payload,{conversation_id:id,user_message_id:userId,assistant_message_id:assistantId,answer:'Private Chat response.'});
   }else Object.assign(payload,{conversations:[...messages.keys()].map(id=>({id,title:'Private Chat '+id,updated_at:'2026-11-03 12:00:00'})),updates:[],items:[],tracks:[]});
  }else if(input.action==='doorbell_list')Object.assign(payload,{contract,availability,reachable:true,server_now_ms:clock,turns:input.chat_conversation_id>0?[...turns.values()].filter(t=>!['replied','blocked','cancelled'].includes(t.state)||deliveries.has(t.turn_id+':'+input.chat_conversation_id)).map(t=>({...copy(t),server_now_ms:clock,chat_conversation_id:input.chat_conversation_id,chat_agent_id:input.chat_agent_id,chat_message_id:0})):[]});
  else if(input.action==='doorbell_presence'){
   const prior=tabs.get(input.tab_id),accepted=!prior||prior.presence_sequence<input.presence_sequence;
   if(accepted){tabs.set(input.tab_id,copy(input));if(input.availability)availability=input.availability;}
   Object.assign(payload,{contract,accepted,availability,reachable:availability==='available'&&[...tabs.values()].some(t=>t.visible),server_now_ms:clock});
  }else if(input.action==='doorbell_delivery'){
   const key=input.turn_id+':'+input.chat_conversation_id;let delivery=deliveries.get(key);
   if(!delivery){delivery={delivery_id:(++nextMessage).toString(16).padStart(32,'0'),chat_message_id:nextMessage};deliveries.set(key,delivery);putMessage(input.chat_conversation_id,nextMessage,'Profile visitor conversation.',{profile_handoff:{contract,turn_id:input.turn_id,revision:input.revision,conversation_id:turns.get(input.turn_id).conversation_id}});}
   Object.assign(payload,{contract,...delivery,message_id:delivery.chat_message_id,chat_conversation_id:input.chat_conversation_id,chat_agent_id:input.chat_agent_id,turn:{...copy(turns.get(input.turn_id)),server_now_ms:clock,chat_conversation_id:input.chat_conversation_id,chat_agent_id:input.chat_agent_id,chat_message_id:delivery.chat_message_id}});
  }else if(input.action==='doorbell_ack'){
   const turn=turns.get(input.turn_id);if(turn.state==='awaiting_delivery'){turn.state='awaiting_owner';turn.revision++;turn.acknowledged_at_ms=clock;turn.pickup_deadline_ms=clock+15000;}
   Object.assign(payload,{contract,duplicate:false,turn:{...copy(turn),server_now_ms:clock}});
  }else if(['doorbell_claim','doorbell_reply','doorbell_delegate','doorbell_resolve'].includes(input.action)){
   if(operations.has(input.request_id))payload=copy(operations.get(input.request_id));
   else{
    const turn=turns.get(input.turn_id);turn.revision++;turn.state=({doorbell_claim:'owner_claimed',doorbell_reply:'replied',doorbell_delegate:'agent_ready',doorbell_resolve:'cancelled'})[input.action];turn.responder=input.action==='doorbell_delegate'?'agent':input.action==='doorbell_resolve'?'none':'owner';
    if(input.action==='doorbell_reply')turn.history.push({id:++nextMessage,sender_type:'owner',message:input.message,created_at:'2026-11-03 12:00:01'});
    if(turn.chat){
     const accepted=turn.chat.live_status==='accepted';
     if(['doorbell_claim','doorbell_reply'].includes(input.action)&&turn.chat.mode==='live'){turn.chat.live_status='accepted';turn.chat.can_end_live_chat=true;turn.can_claim=false;}
     else if(input.action==='doorbell_delegate'){turn.chat.mode='agent';turn.chat.live_status=accepted?'ended':'declined';turn.chat.can_end_live_chat=false;}
     else if(input.action==='doorbell_resolve'){turn.chat.live_status='ended';turn.chat.can_end_live_chat=false;}
    }
    Object.assign(payload,{turn_id:input.turn_id,conversation_id:input.conversation_id,request_id:input.request_id,turn:{...copy(turn),server_now_ms:clock}});operations.set(input.request_id,copy(payload));
   }
  }else Object.assign(payload,{conversations:[],items:[],tracks:[],updates:[],route:'chat'});
  const index=holds.findIndex(h=>h.action===input.action);
  if(index>=0){const hold=holds.splice(index,1)[0];hold.arrived({input,payload,async release(overrides={},status=200){const finished=page.waitForEvent('requestfinished',{predicate:r=>r===request});await route.fulfill({status,contentType:'application/json',body:JSON.stringify({...payload,...overrides})});await finished;await page.evaluate(()=>new Promise(resolve=>requestAnimationFrame(resolve)));}});}
  else await route.fulfill({contentType:'application/json',body:JSON.stringify(payload)});
 });
 const f={page,errors,calls,turns,messages,deliveries,operations,tabs,get clock(){return clock;},advance(ms){clock+=ms;},hold:action=>new Promise(arrived=>holds.push({action,arrived})),card:id=>page.locator('[data-profile-doorbell-turn="'+id+'"]'),
  refresh:()=>page.evaluate(()=>window.VP3_PROFILE_DOORBELL_PA3_RUNTIME.refresh()),
  async boot(){await page.goto(base+'/');await this.card(101).waitFor();},
  async close(){check(errors.length===0,'actual Chat and doorbell have no browser errors: '+errors.join('; '));await page.close();}};
 return f;
}
try{
 browser=await chromium.launch({headless:true,...(process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH?{executablePath:process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH}:{})});
 {
  const f=await fixture(),ackHold=f.hold('doorbell_ack');await f.boot();const ack=await ackHold;
  check(ack.input.chat_message_id===701&&ack.input.chat_conversation_id===42&&ack.input.chat_agent_id===7&&ack.input.turn_id===101&&ack.input.revision===1,'real mounted card ACK binds persisted Chat message and current Agent');
  check(await f.card(101).locator('.profile-doorbell-countdown').isHidden(),'a held server ACK does not invent a deadline');
  await ack.release();await f.card(101).locator('.profile-doorbell-countdown').waitFor();
  check(/1[45]s to pick up/.test(await f.card(101).innerText()),'confirmed server deadline appears in the actual canvas');
  await f.page.locator('#chatInput').fill('Private unsent text');await f.card(101).locator('textarea').fill('Visitor draft');
  await f.refresh();check(await f.page.locator('#chatInput').inputValue()==='Private unsent text'&&await f.card(101).locator('textarea').inputValue()==='Visitor draft','polling preserves private and visitor drafts separately');
  f.turns.set(202,ownerCard(202,{server_now_ms:f.clock}));await f.refresh();await f.card(202).waitFor();
  await f.card(202).locator('textarea').fill('Reply only to visitor202');const hold=f.hold('doorbell_reply');await f.card(202).locator('button[type=submit]').click();const reply=await hold;
  check(reply.input.turn_id===202&&reply.input.conversation_id===1202&&reply.input.chat_conversation_id===42&&reply.input.message==='Reply only to visitor202','real card form sends only its exact visitor draft');
  check(await f.card(202).locator('button:enabled').count()===0,'all controls on pending card are disabled');
  await f.card(202).locator('form').evaluate(form=>form.dispatchEvent(new Event('submit',{cancelable:true,bubbles:true})));
  check(f.calls.filter(c=>c.action==='doorbell_reply').length===1,'pending double submit cannot send a second reply');
  await reply.release();await f.page.waitForFunction(()=>document.querySelector('[data-profile-doorbell-turn="202"] textarea')?.value==='');
  check(await f.card(101).locator('textarea').inputValue()==='Visitor draft'&&await f.page.locator('#chatInput').inputValue()==='Private unsent text','confirmed targeted reply leaves other drafts untouched');
  check(!JSON.stringify(f.calls.filter(c=>c.path==='/api/profile-agent.php')).includes('Private unsent text'),'private composer text is absent from all owner reply requests');
  const old=f.hold('doorbell_claim');await f.card(101).locator('[data-doorbell-action=claim]').click();const claim=await old;
  await f.page.evaluate(()=>window.STONEFELLOW_CHAT_CONTINUITY.openConversation(55));await f.card(101).waitFor();await f.card(101).locator('textarea').fill('Draft in current Chat55');await claim.release();
  check(await f.page.evaluate(()=>window.STONEFELLOW_CHAT_CONTINUITY.conversationId())===55&&await f.card(101).locator('textarea').inputValue()==='Draft in current Chat55'&&!(await f.card(101).innerText()).includes('You picked up this visitor.'),'late old-context mutation cannot overwrite or navigate the current Chat');
  const before=f.calls.filter(c=>c.action==='doorbell_ack').length;await f.page.reload();await f.card(101).waitFor();
  check(await f.card(101).locator('textarea').inputValue()==='Draft in current Chat55','reload restores current visitor draft from the persisted marker');
  check(f.calls.filter(c=>c.action==='doorbell_ack').length===before,'reload does not start another pickup window');
  check(await f.page.locator('[data-profile-doorbell-turn="101"]').count()===1,'real saved-message restore and current projection create exactly one card');
  await f.page.locator('#chatInput').fill('A normal private question');await f.page.locator('#chatForm').evaluate(form=>form.requestSubmit());await f.page.getByText('Private Chat response.',{exact:true}).waitFor();
  check(f.calls.some(c=>c.path==='/api/chat-v236.php'&&c.action==='send'&&c.message==='A normal private question')&&!f.calls.some(c=>c.action==='doorbell_reply'&&c.message==='A normal private question'),'regular composer continues through the private Chat endpoint');
  await f.page.locator('#profileDoorbellAvailability').selectOption('dnd');await f.page.waitForFunction(()=>document.querySelector('#profileDoorbellAvailability')?.value==='dnd');
  check(f.calls.some(c=>c.action==='doorbell_presence'&&c.availability==='dnd'),'explicit Do not disturb preference reaches the presence service');
  await f.close();
 }
 {
  const f=await fixture();f.turns.set(101,ownerCard(101,{server_now_ms:f.clock,chat:chatChoice()}));f.turns.set(202,ownerCard(202,{server_now_ms:f.clock,chat:chatChoice({mode:'agent',live_status:'none'})}));await f.boot();
  const card=f.card(101);await card.locator('.profile-doorbell-countdown').waitFor();await f.card(202).waitFor();
  check(await card.locator('[data-doorbell-action=claim]').innerText()==='Accept live chat'&&await card.locator('[data-doorbell-action=delegate]').innerText()==='Decline'&&(await card.innerText()).includes('requested live text chat with you'),'actual main Chat live request offers explicit Accept and Decline');
  await f.page.locator('#chatInput').fill('Private live-chat draft');await f.card(202).locator('textarea').fill('Other visitor live-chat draft');
  const hold=f.hold('doorbell_claim');await card.locator('[data-doorbell-action=claim]').click();const acceptance=await hold;
  check(acceptance.input.turn_id===101&&acceptance.input.revision===2&&acceptance.input.conversation_id===1101&&acceptance.input.chat_conversation_id===42&&acceptance.input.chat_agent_id===7,'actual live acceptance preserves exact turn, revision and conversation bindings');
  check(!(await card.innerText()).includes('Live chat accepted.')&&await card.locator('[data-doorbell-action=delegate]').innerText()==='Decline','pending browser acceptance does not announce active chat');
  await acceptance.release();await f.page.waitForFunction(()=>document.querySelector('[data-profile-doorbell-turn="101"]')?.dataset.liveStatus==='accepted');
  check((await card.innerText()).includes('Live text chat with this visitor is active.')&&await card.locator('[data-doorbell-action=delegate]').innerText()==='End live chat','confirmed direct live text status and End action appear in the real card');
  const ending=f.hold('doorbell_delegate');await card.locator('[data-doorbell-action=delegate]').click();const end=await ending;check(!(await card.innerText()).includes('Live chat ended.'),'browser End action waits for server confirmation');await end.release();
  await f.page.waitForFunction(()=>document.querySelector('[data-profile-doorbell-turn="101"]')?.dataset.liveStatus==='ended');
  check((await card.innerText()).includes('Live chat has ended.')&&await f.card(202).locator('textarea').inputValue()==='Other visitor live-chat draft'&&await f.page.locator('#chatInput').inputValue()==='Private live-chat draft','confirmed End hands back to Agent without mixing another visitor or private draft');
  f.turns.set(303,ownerCard(303,{server_now_ms:f.clock,chat:chatChoice()}));await f.refresh();const decline=f.card(303);await decline.waitFor();const declining=f.hold('doorbell_delegate');await decline.locator('[data-doorbell-action=delegate]').click();const declined=await declining;
  check(declined.input.turn_id===303&&!(await decline.innerText()).includes('Live chat declined.'),'actual Decline targets its own visitor and has no premature success');await declined.release();
  await f.page.waitForFunction(()=>document.querySelector('[data-profile-doorbell-turn="303"]')?.dataset.liveStatus==='declined');check((await decline.innerText()).includes('Live chat was declined.'),'actual declined card identifies the confirmed live outcome');await f.close();
 }
 {
  const f=await fixture();f.turns.set(101,ownerCard(101,{server_now_ms:f.clock,chat:chatChoice()}));await f.boot();await f.card(101).locator('.profile-doorbell-countdown').waitFor();await f.card(101).locator('textarea').fill('Draft retained when owner goes offline');
  const oldList=f.hold('doorbell_list'),refresh=f.refresh(),old=await oldList,nextList=f.hold('doorbell_list');f.turns.get(101).chat.live_text_available=false;
  await f.page.evaluate(()=>window.dispatchEvent(new CustomEvent('stonefellow:chat-settings-updated',{detail:{presence_mode:'offline',social_chat_enabled:false}})));
  check(await f.card(101).locator('[data-doorbell-action=claim]').isDisabled()&&(await f.card(101).innerText()).includes('Checking your updated Chat Settings'),'existing settings event immediately freezes browser live acceptance');
  await old.release();await refresh;const next=await nextList;check(await f.card(101).locator('[data-doorbell-action=claim]').isDisabled()&&(await f.card(101).innerText()).includes('Checking your updated Chat Settings'),'pre-settings response cannot re-enable browser acceptance');await next.release();
  await f.page.waitForFunction(()=>document.querySelector('[data-profile-doorbell-turn="101"] .profile-doorbell-status')?.textContent.includes('current Chat Settings or availability prevent acceptance'));
  check(await f.card(101).locator('[data-doorbell-action=claim]').isDisabled()&&await f.card(101).locator('[data-doorbell-action=reply]').isDisabled()&&await f.card(101).locator('[data-doorbell-action=delegate]').isEnabled()&&await f.card(101).locator('textarea').inputValue()==='Draft retained when owner goes offline','server refresh keeps truthful availability, decline and the retained visitor draft');
  check(f.calls.filter(c=>c.action==='doorbell_presence').every(c=>!Object.hasOwn(c,'availability')),'browser settings refresh never writes an availability preference');await f.close();
 }
 {
  const f=await fixture();f.turns.set(101,ownerCard(101,{server_now_ms:f.clock,chat:chatChoice()}));await f.boot();await f.card(101).locator('.profile-doorbell-countdown').waitFor();
  const held=f.hold('doorbell_claim');await f.card(101).locator('[data-doorbell-action=claim]').click();const acceptance=await held;await f.page.evaluate(()=>window.STONEFELLOW_CHAT_CONTINUITY.openConversation(55));await f.card(101).waitFor();await f.card(101).locator('textarea').fill('Current context draft after live request');await acceptance.release();
  check(await f.page.evaluate(()=>window.STONEFELLOW_CHAT_CONTINUITY.conversationId())===55&&await f.card(101).locator('textarea').inputValue()==='Current context draft after live request'&&!(await f.card(101).innerText()).includes('Live chat accepted.'),'stale live acceptance response cannot announce success, clear a draft or navigate the new private Chat');await f.close();
 }
 {
  const f=await fixture({width:390,height:844});f.turns.set(101,ownerCard(101,{server_now_ms:f.clock,chat:chatChoice(),visitor_label:'Visitor <img src=x onerror=alert(1)>',visitor_message:'Hello <script>window.__visitorExecuted=true</script>'}));await f.boot();
  check(await f.card(101).locator('script,img').count()===0&&await f.page.evaluate(()=>window.__visitorExecuted)!==true,'visitor markup remains literal text in the real browser');
  check(await f.page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1),'visitor card and availability fit the mobile viewport without horizontal scrolling');
  await f.card(101).locator('textarea').scrollIntoViewIfNeeded();await f.card(101).locator('textarea').fill('Mobile visitor reply');
  const hold=f.hold('doorbell_reply');await f.card(101).locator('button[type=submit]').click();const reply=await hold;
  check(reply.input.message==='Mobile visitor reply'&&reply.input.conversation_id===1101,'mobile card reply reaches the correct visitor thread');await reply.release();
  await f.page.waitForFunction(()=>document.querySelector('[data-profile-doorbell-turn="101"]')?.dataset.liveStatus==='accepted');
  check(await f.page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1)&&await f.card(101).locator('[data-doorbell-action=delegate]').innerText()==='End live chat','accepted live text status and End control also fit the mobile viewport');
  check(await f.page.locator('#chatInput').isEnabled(),'mobile private composer remains usable after visitor reply');await f.close();
 }
 {
  const first=await fixture();await first.boot();
  const inherited=await first.page.evaluate(()=>Object.fromEntries(Object.entries(sessionStorage)));
  const second=await fixture();await second.page.addInitScript(values=>{for(const [key,value] of Object.entries(values))sessionStorage.setItem(key,value);},inherited);await second.boot();
  const firstTab=first.calls.find(c=>c.action==='doorbell_presence').tab_id,secondTab=second.calls.find(c=>c.action==='doorbell_presence').tab_id;
  check(firstTab!==secondTab,'duplicated browser storage cannot merge two documents into one presence authority');
  await first.close();await second.close();
 }
 console.log(`PROFILE_VISITOR_DOORBELL_PA3_BROWSER=PASS (${checks} checks; actual Chat renderer, persisted history and mobile)`);
}finally{if(browser)await browser.close();await new Promise(resolve=>server.close(resolve));}
