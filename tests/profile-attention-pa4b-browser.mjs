import assert from 'node:assert/strict';
import fs from 'node:fs';
import http from 'node:http';
import {createRequire} from 'node:module';
import {attentionItem,contract} from './fixtures/profile-attention-pa4b.mjs';
import {mainChatVoiceMarkup} from './fixtures/profile-visitor-doorbell-pa3.mjs';
const {chromium}=createRequire(import.meta.url)(process.env.VP3_PLAYWRIGHT_MODULE||'playwright');
const read=name=>fs.readFileSync(new URL('../'+name,import.meta.url),'utf8'),copy=value=>structuredClone(value);
const names=['chat.js','chat.css','chat-voice.js','voice-lease-v122.js','profile-attention-pa4b.js','profile-attention-pa4b.css','chat-notifications-drawer-v240.js','chat-notifications-drawer-v240.css','profile-activity-chat.js','profile-activity-chat.css','profile-agent-portal.js','profile-agent-portal.css'];
const files=Object.fromEntries(names.map(name=>['/'+name,read(name)]));
const style='html,body{margin:0;height:100%;overflow:hidden}.chat-app{height:100dvh}.chat-sidebar{display:none}.chat-main{height:100dvh;display:flex;flex-direction:column;min-width:0}.chat-thread{overflow:auto;flex:1;min-height:0;padding:12px}.message{max-width:100%;box-sizing:border-box}.message-body{min-width:0;max-width:100%}#chatComposerShell{flex-shrink:0;padding:8px}#chatForm{display:flex;gap:8px}#chatInput{min-width:0;flex:1;font-size:16px}';
const config=`window.PROFILE_ATTENTION_PA4B_CONFIG={endpoint:'/api/chat-notifications-brain-v240.php',csrfToken:'FIXTURE_CSRF'};`;
const owner=mainChatVoiceMarkup.replace('<head>','<head><meta charset="utf-8">').replace('</head>','<link rel="stylesheet" href="/chat.css"><link rel="stylesheet" href="/chat-notifications-drawer-v240.css"><link rel="stylesheet" href="/profile-attention-pa4b.css"><link rel="stylesheet" href="/profile-activity-chat.css"><style>'+style+'</style></head>')
  .replace('<span id="chatVoiceStatus" hidden></span>','<span id="chatVoiceStatus" hidden></span><div id="chatNotificationMenu"><button id="chatNotificationButton" type="button">Notifications</button><div id="chatNotificationDropdown"></div></div><div id="chatProfileMenu"></div>')
  .replace('</body>',`<script>
window.STONEFELLOW_CHAT={userId:1,agentId:7,endpoint:'/api/chat-v236.php',csrf:'FIXTURE_CSRF',initialView:'chat',initialConversationId:42};
window.STONEFELLOW_CHAT_VOICE_BOOT={button:document.getElementById('chatVoiceButtonLegacyDormant'),intro:null,legacyDormant:true};
window.STONEFELLOW_AGENT_IDENTITY_V236={agentId:7,agentVoiceEnabled:true,profileAgentEndpoint:'/api/profile-agent.php',csrf:'FIXTURE_CSRF'};
window.STONEFELLOW_NOTIFICATION_DRAWER={endpoint:'/api/chat-notifications-brain-v240.php',csrf:'FIXTURE_CSRF',agentId:7};
window.VP3_COGNITIVE_PRESENTATION_V510={ownsAttention:true,ownsBrainPresentation:true};${config}
</script><script src="/chat.js"></script><script data-voice-capture-scope="fixture-user" src="/voice-lease-v122.js"></script><script src="/chat-voice.js"></script><script src="/profile-activity-chat.js"></script><script src="/profile-attention-pa4b.js"></script><script src="/chat-notifications-drawer-v240.js"></script></body>`);
const portal=`<!doctype html><html><head><meta name="viewport" content="width=device-width,initial-scale=1"><link rel="stylesheet" href="/profile-agent-portal.css"><link rel="stylesheet" href="/profile-attention-pa4b.css"><style>html,body{margin:0;height:100%}.profile-agent-app{display:flex;flex-direction:column}.profile-agent-portal{flex:1;height:auto;width:100%;box-sizing:border-box;min-height:0}</style></head><body><main class="profile-agent-app"><div id="profileAgentNotice"></div><div id="profileAgentServiceStatus"></div><div id="profileAgentMetrics"></div><button id="profileAgentRefresh">Refresh</button><div class="profile-agent-portal" id="profileAgentPortal">${['inbox','visitors','radar','agent','knowledge','profile','analytics'].map(name=>`<button data-pa-tab="${name}">${name}</button>`).join('')}${Object.entries({inbox:['profileAgentAttention','profileAgentConversations','profileAgentThread'],visitors:['profileAgentVisitors'],radar:['profileAgentRadar'],agent:['profileAgentSettings'],knowledge:['profileAgentKnowledge'],profile:['profileAgentProfileSettings'],analytics:['profileAgentAnalytics']}).map(([name,ids])=>`<section class="profile-agent-view" data-pa-view="${name}">${ids.map(id=>`<div id="${id}"></div>`).join('')}</section>`).join('')}</div></main><script>${config}window.PROFILE_AGENT_PORTAL={endpoint:'/api/profile-agent.php',csrf:'FIXTURE_CSRF'};</script><script src="/profile-attention-pa4b.js"></script><script src="/profile-agent-portal.js"></script></body></html>`;
const server=http.createServer((request,response)=>{const path=new URL(request.url,'http://localhost').pathname;if(files[path]){response.writeHead(200,{'Content-Type':(path.endsWith('.css')?'text/css':'text/javascript')+'; charset=utf-8'});response.end(files[path]);}else{response.writeHead(200,{'Content-Type':'text/html; charset=utf-8'});response.end(path==='/portal'?portal:owner);}});
await new Promise(resolve=>server.listen(0,'127.0.0.1',resolve));const base='http://127.0.0.1:'+server.address().port;
let browser,checks=0;const check=(ok,label)=>{assert.ok(ok,label);checks++;console.log('PASS '+label);};
const orderNote={id:701,source_type:'profile_commerce_order',source_id:7,type:'profile_commerce_order_paid',title:'New Profile order',body:'Payment verified for PA5-7.',target_url:'/profile-commerce-products.php#orders',is_read:0};
const orderAnnouncement='New Profile order PA5-7. Payment of USD 25.00 is verified.';
async function fixture({isPortal=false,items=[attentionItem()],stored=[]}={}){
  const context=await browser.newContext({viewport:{width:390,height:844}}),requests=[],errors=[],holds=[],failures=new Map(),rows=new Map(items.map(item=>[item.attention_id,copy(item)])),messages=copy(stored),operations=new Map();let stateOverride=null,selectedCid=42;
  let sellerOrder=false,orderRead=false;
  const envelope=()=>({ok:true,complete:true,contract,server_now_ms:Date.now(),voice_allowed:false});
  const projection=(raw,input)=>{const item={...copy(raw),delivery_location:raw.delivery_id?(raw.chat_conversation_id===input.conversation_id&&raw.chat_agent_id===input.agent_id?'current':'elsewhere'):'none'},due=item.disposition==='snoozed'&&item.snoozed_until_ms<=Date.now();if(item.read&&!due){item.eligible=false;item.reason||='read';}else if(item.presented&&!due){item.eligible=false;item.reason||='presented';}return item;};
  const ownerState=()=>({profile:{username:'owner',profile_agent_id:7,profile_agent_enabled:1,profile_agent_greeting:'Saved greeting'},profile_url:'/owner',agents:[],policies:{},analytics:{},notifications:{unread:1},public_agent_status:{reason:'live',agent_name:'Public helper'},conversations:[101,202].map(id=>({id,visitor_label:'Visitor '+id,status:'open',last_summary:'Visitor '+id})),activity:[],visits:[]});
  await context.addInitScript(()=>{
    window.__speechCalls=0;window.SpeechRecognition=class{start(){}stop(){}abort(){}};
    window.speechSynthesis.speak=()=>{window.__speechCalls++;};
    Object.defineProperty(navigator.mediaDevices,'getUserMedia',{value:async()=>{throw new Error('Physical media is unavailable in this fixture.');}});
    window.__mediaCalls=0;HTMLMediaElement.prototype.play=async function(){window.__mediaCalls++;};
  });
  await context.route('**/api/**',async route=>{
    const request=route.request(),url=new URL(request.url()),input=request.method()==='GET'?Object.fromEntries(url.searchParams):request.postDataJSON()||{};
    for(const key of ['conversation_id','agent_id','after_id','delivery_id','page'])if(input[key]!==undefined)input[key]=Number(input[key]);requests.push({path:url.pathname,...copy(input)});
    let result={ok:true},status=200;
    if(failures.has(input.action)){status=failures.get(input.action);result={...envelope(),ok:false,complete:false,counts:null,code:'PROFILE_ATTENTION_UNAVAILABLE'};}
    else if(url.pathname==='/api/chat-v236.php'){
      if(input.action==='load'){selectedCid=Number(input.conversation_id||42);Object.assign(result,{conversation:{id:selectedCid},messages:copy(messages.filter(row=>!row.conversation_id||row.conversation_id===selectedCid))});}
      else if(input.action==='messages_after')Object.assign(result,{conversation_id:input.conversation_id,messages:copy(messages.filter(row=>row.id>Number(input.after_id||0)&&(!row.conversation_id||row.conversation_id===input.conversation_id)))});
      else if(input.action==='send')Object.assign(result,{conversation_id:input.conversation_id||42,user_message_id:1901,assistant_message_id:1902,answer:'Private answer'});
      else Object.assign(result,{conversations:[{id:42,title:'Private 42'},{id:55,title:'Private 55'}],items:[],updates:[],tracks:[]});
    }else if(url.pathname==='/api/profile-agent.php'){
      if(input.action==='owner_state')result.state=ownerState();
      else if(input.action==='conversation_messages')Object.assign(result,{conversation:{id:input.conversation_id,visitor_label:'Visitor '+input.conversation_id,status:'open'},messages:[{sender_type:'visitor',message:'Public question '+input.conversation_id}]});
      else if(['owner_reply','conversation_status'].includes(input.action))Object.assign(result,{conversation_id:input.conversation_id,state:ownerState()});
      else if(input.action==='save_profile_agent')result.state=ownerState();
      else throw Error('Unexpected Profile endpoint action '+input.action);
    }else if(url.pathname==='/api/chat-notifications-brain-v240.php'){
      if(input.action==='state'||input.action==='mark_all_read'||input.action==='mark_read'){
        if(sellerOrder&&(input.action==='mark_all_read'||Number(input.notification_id)===orderNote.id))orderRead=true;
        const notifications=[...rows.values()].map(item=>({id:item.notification_id,source_type:'profile_event',title:item.title,body:item.display_text,is_read:item.read?1:0}));
        if(sellerOrder)notifications.push({...orderNote,is_read:Number(orderRead)});
        Object.assign(result,{notifications:{unread:notifications.filter(item=>!item.is_read).length,items:notifications},brain:{},history:{items:[]}});
      }else if(input.action==='attention'){
        const ready=sellerOrder&&input.scope==='profile_orders'&&input.after_id<orderNote.id;
        Object.assign(result,{items:ready?[{id:orderNote.id,type:orderNote.type}]:[],latest_id:ready?orderNote.id:input.after_id,complete:true});
      }else if(input.action==='present_attention'&&sellerOrder&&Number(input.notification_id)===orderNote.id){
        const prior=messages.find(message=>message.id===801);
        if(!prior)messages.push({id:801,conversation_id:input.conversation_id,role:'assistant',message:orderAnnouncement,
          context_json:JSON.stringify({actions:[{type:'open_url',label:'View Profile orders',url:orderNote.target_url}],attention:{required:false,notification_id:orderNote.id}})});
        const current=!prior||prior.conversation_id===input.conversation_id;
        Object.assign(result,{handled:current,preserve_unread:true,voice_allowed:false,notification_id:orderNote.id,
          ...(current?{conversation_id:input.conversation_id,message_id:801,duplicate:Boolean(prior),message:orderAnnouncement}:{})});
      }
      else if(input.action==='profile_attention_state'){
        const rank=item=>item.disposition==='snoozed'&&item.snoozed_until_ms<=Date.now()?0:['handled','ignored'].includes(item.disposition)?3:!item.read&&!item.presented?1:2;
        const all=[...rows.values()].map(row=>projection(row,input)).sort((a,b)=>rank(a)-rank(b)||a.notification_id-b.notification_id),page=input.page||0,items=all.slice(page*25,(page+1)*25);
        Object.assign(result,envelope(),{items,latest_id:items.length?Math.max(...items.map(item=>item.notification_id)):0,counts:{unread:all.filter(item=>!item.read).length,attention:all.filter(item=>['pending','snoozed'].includes(item.disposition)).length},page,page_size:25,has_more:all.length>(page+1)*25,errors:[]},stateOverride||{});
      }else if(input.action==='profile_attention_item'){
        const row=[...rows.values()].find(item=>item.delivery_id===input.delivery_id);if(!row||row.chat_conversation_id!==input.conversation_id||row.chat_agent_id!==input.agent_id){status=403;Object.assign(result,envelope(),{ok:false,complete:false});}else Object.assign(result,envelope(),{item:projection(row,input)});
      }else if(['profile_attention_present','profile_attention_ack','profile_attention_update'].includes(input.action)){
        const row=rows.get(input.attention_id),prior=operations.get(input.request_id);Object.assign(result,envelope());
        if(!row){status=403;Object.assign(result,{ok:false,complete:false});}
        else if(prior){assert.deepEqual(input,prior);Object.assign(result,{item:projection(row,input),duplicate:true});}
        else if(row.state_revision!==input.state_revision||row.wake_generation!==input.wake_generation){status=409;Object.assign(result,{ok:false,complete:false,code:'PROFILE_ATTENTION_CHANGED'});}
        else{
          if(input.action==='profile_attention_present'){
            if(row.disposition==='snoozed'&&row.snoozed_until_ms<=Date.now()){row.disposition='pending';row.read=false;row.presented=false;row.wake_generation++;row.state_revision++;row.snoozed_until_ms=null;row.delivery_id=0;}
            if(!row.delivery_id){row.delivery_id=row.attention_id+400;row.chat_message_id=row.attention_id+600;row.chat_conversation_id=input.conversation_id;row.chat_agent_id=input.agent_id;messages.push({id:row.chat_message_id,conversation_id:input.conversation_id,role:'assistant',message:'Profile attention update.',context_json:JSON.stringify({profile_attention:{contract,delivery_id:row.delivery_id,attention_id:row.attention_id,notification_id:row.notification_id,wake_generation:row.wake_generation}})});}
            Object.assign(result,{message:'Profile attention update.',context_json:{profile_attention:{contract,delivery_id:row.delivery_id,attention_id:row.attention_id,notification_id:row.notification_id,wake_generation:row.wake_generation}}});
          }else if(input.action==='profile_attention_ack'){row.presented=true;row.state_revision++;}
          else{if(input.operation==='read'){row.read=true;row.can_read=false;}else if(input.operation==='snooze'){row.disposition='snoozed';row.snoozed_until_ms=Date.now()+86400000;row.eligible=false;}else{row.disposition=input.operation;row.eligible=false;row.can_snooze=row.can_handle=row.can_ignore=false;}row.state_revision++;}
          operations.set(input.request_id,copy(input));Object.assign(result,{item:projection(row,input),duplicate:false});
        }
      }else throw Error('Unexpected attention action '+input.action);
    }else Object.assign(result,{items:[],updates:[],tracks:[]});
    const send=()=>route.fulfill({status,contentType:'application/json',body:JSON.stringify(result)});
    const index=holds.findIndex(hold=>hold.action===input.action);if(index>=0){const hold=holds.splice(index,1)[0];hold.arrived({input,result,release:send});}else await send();
  });
  const page=await context.newPage();page.setDefaultTimeout(15000);page.on('pageerror',error=>errors.push(error.message));await page.goto(base+(isPortal?'/portal':'/owner'));
  if(!isPortal)await page.waitForFunction(()=>window.STONEFELLOW_CHAT_CONTINUITY_V87?.isReady?.()&&!window.STONEFELLOW_CHAT_CONTINUITY_V87?.isRestoring?.());
  const f={context,page,requests,errors,rows,messages,failures,hold:action=>new Promise(arrived=>holds.push({action,arrived})),setState:value=>stateOverride=value,
    poll:()=>page.evaluate(()=>STONEFELLOW_NOTIFICATION_CENTER.pollProfileAttention()),close:()=>context.close(),
    publishSellerOrder(){sellerOrder=true;},
    issued:action=>requests.filter(input=>input.action===action)};
  return f;
}
try{
  browser=await chromium.launch({headless:true,...(process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH?{executablePath:process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH}:{})});
  const f=await fixture();await f.page.waitForSelector('#chatThread [data-profile-attention-card]');await f.page.waitForFunction(()=>document.querySelector('#chatThread [data-profile-attention-card]')?.textContent.includes('Presented'));
  check(await f.page.evaluate(()=>STONEFELLOW_CHAT_CONTINUITY!==STONEFELLOW_CHAT_CONTINUITY_V87&&typeof STONEFELLOW_CHAT_CONTINUITY.pendingVoiceSubmit==='function'),'actual Chat voice IIFE overwrites primary continuity before attention loads');
  check(f.issued('profile_attention_present').every(input=>input.conversation_id===42&&input.agent_id===7),'real drawer uses exact canonical CID42 and Agent7');
  check(f.issued('profile_attention_ack').length===1&&!f.issued('profile_attention_update').length,'actual visible mobile card ACK does not read or dispose the notification');
  const lifecycle=await f.page.locator('#chatThread [data-profile-attention-card] .profile-attention-state').innerText();
  check(await f.page.evaluate(()=>document.characterSet==='UTF-8')&&lifecycle==='Unread · Presented · Needs attention','rendered lifecycle distinguishes unread from presented under production UTF-8: '+JSON.stringify(lifecycle));
  check(await f.page.evaluate(()=>__speechCalls===0&&__mediaCalls===0),'generic Profile attention performs no speech or media playback');
  check(await f.page.evaluate(()=>{const card=document.querySelector('#chatThread [data-profile-attention-card]'),r=card.getBoundingClientRect();return r.left>=0&&r.right<=innerWidth+1&&getComputedStyle(card).overflowY!=='auto'&&getComputedStyle(card.querySelector('article')).overflowY!=='auto';}),'mobile card fits the main Chat canvas without an inner scrollbar');
  await f.page.locator('#chatInput').fill('Private draft must stay private');await f.page.locator('#chatInput').focus();await f.poll();
  check(await f.page.locator('#chatInput').inputValue()==='Private draft must stay private'&&await f.page.evaluate(()=>document.activeElement.id==='chatInput'),'poll preserves private draft and keyboard focus');
  await f.page.locator('#chatThread [data-profile-attention-action="open"]').click();await f.page.waitForSelector('[data-profile-reply][data-conversation-id="101"]');
  check(await f.page.evaluate(()=>STONEFELLOW_CHAT_CONTINUITY_V87.conversationId()===42)&&await f.page.locator('#chatInput').inputValue()==='Private draft must stay private','explicit Open selects the public visitor thread while retaining private Chat/draft');
  await f.page.locator('[data-profile-reply] input').fill('Public draft for visitor 101');await f.poll();
  check(await f.page.locator('[data-profile-reply] input').inputValue()==='Public draft for visitor 101','attention refresh preserves the separately targeted public reply draft');
  await f.page.locator('[data-profile-activity-close]').click();await f.page.waitForFunction(()=>document.getElementById('profileActivityCanvas').hidden);
  await f.page.locator('#chatNotificationButton').click();await f.page.waitForSelector('#chatNotificationDrawer.open [data-profile-attention-row="11"]');
  await f.page.locator('#chatNotificationDrawer [data-profile-attention-action="read"]').click();await f.page.waitForFunction(()=>document.querySelector('#chatNotificationDrawer [data-profile-attention-row="11"]')?.textContent.includes('Read · Presented · Needs attention'));
  check(f.rows.get(11).read&&f.rows.get(11).disposition==='pending','drawer Read changes only the read state');
  f.setState({complete:false,items:[],counts:null,errors:['source_unavailable']});await f.poll();
  check((await f.page.locator('#chatNotificationDrawer').innerText()).includes('incomplete')&&await f.page.locator('#chatNotificationDrawer [data-profile-attention-row="11"]').count()===1,'partial browser read preserves last list/count and exposes retry');
  check(!await f.page.locator('#chatNotificationDrawer').innerText().then(text=>text.includes('caught up')),'incomplete browser response cannot claim caught up');
  f.setState(null);await f.page.locator('#chatNotificationDrawer [data-profile-attention-action="refresh"]').click();await f.page.waitForFunction(()=>document.querySelector('#chatNotificationDrawer [data-profile-attention-action="handled"]')?.disabled===false);
  await f.page.locator('#chatNotificationDrawer [data-profile-attention-action="handled"]').click();await f.page.waitForFunction(()=>document.querySelector('#chatNotificationDrawer [data-profile-attention-row="11"]')?.textContent.includes('Handled'));
  check(!await f.page.locator('#chatNotificationDrawer [data-profile-attention-action="snooze"]').count(),'handled item cannot be revived through snooze controls');
  await f.page.locator('[data-notification-drawer-close]').click();await f.page.waitForFunction(()=>document.getElementById('chatNotificationDrawer').hidden);
  await f.page.reload();await f.page.waitForSelector('#chatThread [data-profile-attention-card]');
  check((await f.page.locator('#chatThread [data-profile-attention-card]').innerText()).includes('Handled')&&f.messages.length===1,'saved exact marker restores read/handled card without duplicate insertion');
  check(f.errors.length===0,'actual Chat/voice/drawer/attention integration produces no page errors');
  await f.close();

  const other=await fixture({items:[attentionItem({delivery_id:411,chat_message_id:611,chat_conversation_id:99,chat_agent_id:7,delivery_location:'elsewhere'})]});await other.page.locator('#chatNotificationButton').click();await other.page.waitForSelector('#chatNotificationDrawer [data-profile-attention-row="11"]');
  check((await other.page.locator('#chatNotificationDrawer').innerText()).includes('another Chat')&&other.issued('profile_attention_present').length===0,'elsewhere replay is explained without creating a second delivery');
  check(await other.page.evaluate(()=>STONEFELLOW_CHAT_CONTINUITY_V87.conversationId()===42),'elsewhere replay never changes selected private Chat');await other.close();

  const race=await fixture({items:[]});await race.page.locator('#chatInput').fill('');race.rows.set(11,attentionItem());const hold=race.hold('profile_attention_present'),poll=race.poll();const held=await hold;
  await race.page.locator('#chatInput').fill('Private text typed during presentation');await held.release();await poll;
  check(await race.page.locator('#chatThread [data-profile-attention-card]').count()===0&&!race.issued('profile_attention_ack').length,'late mobile presentation cannot mount or ACK after private typing');
  check(await race.page.locator('#chatInput').inputValue()==='Private text typed during presentation','late callback does not clear or relay private draft');
  await race.page.locator('#chatInput').fill('');await race.poll();await race.page.waitForSelector('#chatThread [data-profile-attention-card]');
  const oldItem=race.hold('profile_attention_item');race.setState({items:[],counts:{unread:1,attention:1}});const restoring=race.poll();const pending=await oldItem;
  Object.assign(race.rows.get(11),{source_status:'unavailable',source_label:'Source unavailable',display_text:'',eligible:false,reason:'source_authority_revoked',can_read:false,can_snooze:false,can_handle:false,can_ignore:false});race.setState(null);await race.poll();await pending.release();await restoring;
  check(!(await race.page.locator('#chatThread [data-profile-attention-card]').innerText()).includes('A public question.'),'new source revoke overrides an older same-revision saved-item response');
  check(race.errors.length===0,'late draft/source races produce no browser errors');await race.close();

  const wake=await fixture({items:[attentionItem({disposition:'snoozed',snoozed_until_ms:Date.now()-1000,read:true,eligible:true})]});await wake.page.waitForSelector('#chatThread [data-profile-attention-card]');await wake.page.waitForFunction(()=>document.querySelector('#chatThread [data-profile-attention-card]')?.textContent.includes('Presented'));
  check(wake.rows.get(11).wake_generation===2&&wake.issued('profile_attention_ack')[0].wake_generation===2,'expired snooze visibly wakes with a new exact generation in Chromium');await wake.close();

  const p=await fixture({isPortal:true});await p.page.waitForSelector('#profileAgentAttention [data-profile-attention-row="11"]');
  check(p.issued('profile_attention_state').every(input=>input.conversation_id===0&&input.agent_id===0)&&!p.issued('profile_attention_present').length,'portal owns a read-only CID0 list without a private Chat delivery');
  await p.page.locator('#profileAgentAttention [data-profile-attention-action="open"]').click();await p.page.waitForSelector('#paReplyForm[data-conversation-id="101"]');await p.page.locator('#paReplyForm input').fill('Portal visitor draft');
  await p.page.locator('[data-pa-tab="agent"]').click();const greeting=p.page.locator('#paAgentForm [name="profile_agent_greeting"]');await greeting.fill('Unsaved portal settings');await p.page.locator('[data-pa-tab="inbox"]').click();await p.page.locator('#paReplyForm input').focus();await p.page.locator('#profileAgentAttention [data-profile-attention-action="refresh"]').click();
  check(await p.page.locator('#paReplyForm input').inputValue()==='Portal visitor draft'&&await greeting.inputValue()==='Unsaved portal settings','portal attention refresh preserves reply and dirty settings forms');
  await p.page.locator('#profileAgentAttention [data-profile-attention-action="ignored"]').click();await p.page.waitForFunction(()=>document.querySelector('#profileAgentAttention [data-profile-attention-row="11"]')?.textContent.includes('Ignored'));
  check(await p.page.locator('#profileAgentAttention [data-profile-attention-action="read"]').count()===1&&!await p.page.locator('#profileAgentAttention [data-profile-attention-action="snooze"]').count(),'ignored unread portal item permits Read but not revival');
  p.failures.set('profile_attention_state',503);await p.page.locator('#profileAgentAttention [data-profile-attention-action="refresh"]').click();await p.page.waitForFunction(()=>document.getElementById('profileAgentAttention').textContent.includes('could not be verified'));
  check(await p.page.locator('#profileAgentAttention [data-profile-attention-row="11"]').count()===1&&await p.page.locator('#paReplyForm input').inputValue()==='Portal visitor draft','portal failed read retains prior list and draft with visible retry');
  check(p.errors.length===0,'actual portal/controller integration produces no browser errors');await p.close();

  const paging=await fixture({items:[]});await paging.page.locator('#chatInput').fill('Private draft retained across notification pages');
  for(let index=0;index<65;index++)paging.rows.set(index+1,attentionItem({attention_id:index+1,notification_id:index+101,profile_event_id:index+201,public_conversation_id:index+301,public_message_id:index+401,
    ...(index>=55?{disposition:'handled',read:true,eligible:false,can_read:false,can_snooze:false,can_handle:false,can_ignore:false}:{})}));
  await paging.page.locator('#chatNotificationButton').click();await paging.page.waitForFunction(()=>document.querySelectorAll('#chatNotificationDrawer [data-profile-attention-row]').length===25);
  const pager=paging.page.locator('#chatNotificationDrawer .profile-attention-pages'),ids=()=>paging.page.locator('#chatNotificationDrawer [data-profile-attention-row]').evaluateAll(nodes=>nodes.map(node=>Number(node.dataset.profileAttentionRow))),seen=new Set(await ids());
  check((await pager.innerText()).includes('Page 1')&&await pager.locator('[data-profile-attention-action="previous"]').isDisabled(),'mobile drawer exposes a bounded first page and explicit navigation');
  await pager.locator('[data-profile-attention-action="next"]').click();await paging.page.waitForFunction(()=>document.querySelector('#chatNotificationDrawer .profile-attention-pages')?.textContent.includes('Page 2'));for(const id of await ids())seen.add(id);
  await pager.locator('[data-profile-attention-action="next"]').click();await paging.page.waitForFunction(()=>document.querySelector('#chatNotificationDrawer .profile-attention-pages')?.textContent.includes('Page 3'));for(const id of await ids())seen.add(id);
  check(seen.size===65&&(await ids()).length===15&&await pager.locator('[data-profile-attention-action="next"]').isDisabled(),'all65 owned Profile items including handled/read history are reachable in Chromium');
  check(paging.issued('profile_attention_present').length===0&&paging.issued('profile_attention_ack').length===0&&paging.issued('profile_attention_update').length===0&&await paging.page.locator('#chatInput').inputValue()==='Private draft retained across notification pages'&&await paging.page.evaluate(()=>STONEFELLOW_CHAT_CONTINUITY_V87.conversationId()===42),'page navigation preserves selected Chat/draft and performs only reads');
  check(await pager.evaluate(node=>[...node.querySelectorAll('button')].every(button=>{const r=button.getBoundingClientRect();return r.left>=0&&r.right<=innerWidth+1&&r.height>=44;})),'pagination controls fit the mobile drawer with usable touch targets');
  await pager.locator('[data-profile-attention-action="previous"]').click();await paging.page.waitForFunction(()=>document.querySelector('#chatNotificationDrawer .profile-attention-pages')?.textContent.includes('Page 2'));
  check((await ids())[0]===26,'Previous reloads the exact preceding current page');
  const priorPageResponse=paging.hold('profile_attention_state');await paging.page.locator('#chatNotificationDrawer [data-profile-attention-action="refresh"]').click();const pendingPage=await priorPageResponse;
  Object.assign(paging.rows.get(26),{source_status:'unavailable',source_label:'Source unavailable',display_text:'',eligible:false,reason:'source_authority_revoked',can_read:false,can_snooze:false,can_handle:false,can_ignore:false});await paging.poll();await pendingPage.release();
  await paging.page.waitForFunction(()=>document.querySelector('#chatNotificationDrawer [data-profile-attention-row="26"]')?.textContent.includes('Source unavailable'));
  check(!(await paging.page.locator('#chatNotificationDrawer [data-profile-attention-row="26"]').innerText()).includes('A public question.'),'a fresh paged source revocation dominates an older page response with unchanged lifecycle revision');
  paging.failures.set('profile_attention_state',503);await pager.locator('[data-profile-attention-action="next"]').click();await paging.page.waitForFunction(()=>document.querySelector('#chatNotificationDrawer .profile-attention-section')?.textContent.includes('could not be verified'));
  check((await ids()).length===25&&(await pager.innerText()).includes('Page 2'),'failed Next visibly retains the last confirmed page instead of showing an empty list');
  paging.failures.delete('profile_attention_state');await paging.page.locator('#chatNotificationDrawer [data-profile-attention-action="refresh"]').click();await paging.page.waitForFunction(()=>document.querySelector('#chatNotificationDrawer .profile-attention-pages')?.textContent.includes('Page 3'));
  check((await ids()).length===15&&paging.errors.length===0,'Retry reaches the requested next page with no browser errors');await paging.close();

  const seller=await fixture({items:[]});
  await seller.page.waitForFunction(()=>document.getElementById('chatNotificationButton').getAttribute('aria-label')==='Activity Center');
  seller.publishSellerOrder();
  await seller.page.evaluate(()=>STONEFELLOW_NOTIFICATION_CENTER.pollAttention(false,'profile_orders'));
  const orderCanvas=seller.page.locator('#chatThread [data-chat-message-id="801"]');
  await orderCanvas.getByText(orderAnnouncement,{exact:true}).waitFor();
  check(seller.issued('attention').some(input=>input.scope==='profile_orders')&&seller.issued('present_attention').some(input=>input.conversation_id===42&&input.agent_id===7),'seller order appears in the current private Chat while Cognitive owns generic attention');
  const ordersLink=orderCanvas.getByRole('link',{name:'View Profile orders',exact:true});
  check(await ordersLink.getAttribute('href')===orderNote.target_url,'canvas announcement opens the seller orders surface');
  await seller.page.locator('#chatNotificationButton').click();
  await seller.page.locator('[data-activity-details="notifications"]>summary').click();
  const orderBell=seller.page.locator('#chatNotificationDrawer [data-notification-id="701"].unread');
  await orderBell.getByText(orderNote.title,{exact:true}).waitFor();
  check(await seller.page.locator('#chatNotificationButton').getAttribute('aria-label')==='Activity Center, 1 unread notification'&&!seller.issued('mark_read').length,'canvas delivery leaves the order unread in the bell until the seller acts');
  check(await seller.page.evaluate(()=>__speechCalls===0&&__mediaCalls===0),'verified order announcement does not narrate or play audio');
  await seller.page.locator('[data-notification-drawer-close]').click();
  await seller.page.evaluate(()=>STONEFELLOW_CHAT_CONTINUITY_V87.openConversation(55));
  const replay=seller.page.waitForResponse(response=>response.url().endsWith('/api/chat-notifications-brain-v240.php')&&response.request().postDataJSON()?.action==='present_attention'&&response.request().postDataJSON()?.conversation_id===55);
  await seller.page.reload();
  await replay;
  check(await seller.page.evaluate(()=>STONEFELLOW_CHAT_CONTINUITY_V87.conversationId()===55)&&await orderCanvas.count()===0&&seller.messages.filter(message=>message.id===801).length===1,'replaying the order in another Chat neither switches threads nor duplicates the announcement');
  check(!seller.issued('mark_read').length&&seller.errors.length===0,'seller notification replay stays unread and browser error-free');
  await seller.close();
  console.log(`PROFILE_ATTENTION_PA4B_BROWSER=PASS (${checks} assertions)`);
}finally{await browser?.close();await new Promise(resolve=>server.close(resolve));}
