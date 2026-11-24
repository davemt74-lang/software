import assert from 'node:assert/strict';
import fs from 'node:fs/promises';
import http from 'node:http';
import path from 'node:path';
import {createRequire} from 'node:module';
import {createHash} from 'node:crypto';
const {chromium}=createRequire(import.meta.url)(process.env.PLAYWRIGHT_MODULE||'playwright');
const root=process.cwd(),source=await fs.readFile(path.join(root,'phone-workspace-v1.js'),'utf8'),css=await fs.readFile(path.join(root,'phone-workspace-v1.css'),'utf8');
const captureSource=await fs.readFile(path.join(root,'phone-operator-capture-v1.js'),'utf8');
const shellStyles=new Map(await Promise.all(['chat.css','chat-header-ui.css','stonefellow-ui.css'].map(async file=>['/'+file,await fs.readFile(path.join(root,file),'utf8')])));
const fixtureHead='<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width"><style>*,*::before,*::after{box-sizing:border-box}body{margin:0}</style><link rel="stylesheet" href="/chat.css"><link rel="stylesheet" href="/chat-header-ui.css"><link rel="stylesheet" href="/phone-workspace-v1.css">';
const headerShell=button=>'<header class="chat-topbar member-header"><div class="chat-topbar-title"><strong>Agent Chat</strong></div><div class="chat-topbar-actions member-header-actions"><div class="chat-top-menu">'+button+'</div></div></header>';
const actualState=process.env.PHONE_WORKSPACE_STATE_FIXTURE?JSON.parse(await fs.readFile(process.env.PHONE_WORKSPACE_STATE_FIXTURE,'utf8')):null;
const actualUi=process.env.PHONE_WORKSPACE_UI_FIXTURE?JSON.parse(await fs.readFile(process.env.PHONE_WORKSPACE_UI_FIXTURE,'utf8')):null;
const incomingId='a'.repeat(32),outgoingId='b'.repeat(32);
const fixture=()=>({numbers:[{id:1,phone_e164:'+16025550111',agent_id:2,agent_name:'Fixture agent',voice_enabled:true,browser_enabled:true,recording_enabled:true}],selected_number_id:1,readiness:{ready:true,reason:''},contacts:[{id:7,name:'Fixture contact <img src=x onerror=alert(1)>',phone_e164:'+16025550222',favorite:false}],favorites:[],calls:[],voicemails:[{id:'legacy-41',call_id:'legacy_41',peer_e164:'+16025550222',audio_url:'/api/fixture-audio',transcript:'Fixture transcript <script>unsafe()</script>',report_url:'/fixture-report',read:false,created_at:'2026-11-23T12:00:00Z'},{id:'job-42',call_id:'legacy_42',peer_e164:'+16025550333',audio_url:'javascript:unsafe()',transcript:'Other fixture voicemail',transcript_url:null,report_url:'https://untrusted.invalid/private',read:true,created_at:'2026-11-23T11:00:00Z'}],active_call:null,post_call_policy:{enabled:false,retain_audio:false,transcribe:false,analyze:false,model_route:'account_cloud',modules:['basic','qa','actions','decisions','followup']}});
const sdk=`(()=>{
 const m=window.phoneFixture={devices:[],calls:[],connects:[],accepts:0,rejects:0,stops:0,claims:0,releases:0,updates:0,denied:false,holdRegister:false,holdConnect:false};
 class Call{
 constructor(id,from='+16025550444',incoming=false){this.events={};this.customParameters=new Map([['phone_call_id',id]]);this.parameters={From:from};this.state=incoming?'pending':'open';this.muted=false;const track={readyState:'live',stop(){if(this.readyState!=='ended'){this.readyState='ended';m.stops++;}}};this.stream={getTracks:()=>[track]};m.calls.push(this);}
 on(n,f){(this.events[n]??=[]).push(f);return this;}emit(n,...args){for(const f of this.events[n]||[])f(...args);}status(){return this.state;}getLocalStream(){return this.stream;}isMuted(){return this.muted;}mute(v){this.muted=v;}
 accept(){m.accepts++;this.state='open';this.emit('accept',this);}reject(){m.rejects++;this.state='closed';this.emit('reject');}disconnect(){this.state='closed';this.emit('disconnect');}
 }
 class Device{
 constructor(token,options){this.events={};this.token=token;this.options=options;this.destroyed=false;m.devices.push(this);}
 on(n,f){(this.events[n]??=[]).push(f);return this;}emit(n,...args){for(const f of this.events[n]||[])f(...args);}
 async register(){if(m.holdRegister)await new Promise(resolve=>m.releaseRegister=resolve);this.emit('registered');}
 async connect(options){m.connects.push(options);const call=new Call('${outgoingId}');if(m.holdConnect)await new Promise(resolve=>m.releaseConnect=()=>resolve());return call;}
 updateToken(token){this.token=token;m.updates++;}disconnectAll(){}destroy(){this.destroyed=true;}
 }
 window.Twilio={Device};m.incoming=()=>{const call=new Call('${incomingId}','+16025550444',true);m.devices.at(-1).emit('incoming',call);return call;};
 window.StonefellowVoiceLeaseV122={acquireCapture(){if(m.denied)return null;m.claims++;let live=true;const streams=new Set();return {isCurrent:()=>live,ownStream:s=>{if(!live){s.getTracks().forEach(t=>t.stop());return false;}streams.add(s);return true;},release(){if(!live)return;live=false;m.releases++;for(const s of streams)s.getTracks().forEach(t=>t.stop());}};}};
})();`;
const integrity='sha384-'+createHash('sha384').update(sdk).digest('base64');
const actualSdk=await fs.readFile(path.join(root,'assets/twilio-voice-2.18.5.min.js'));
const actualIntegrity='sha384-LUG7DmzDPWNKRYoqyGpV4d/IByMLiya/oUhMSc/4/6hi0wbzO/8PffM4lzeFEYS8';
assert.equal('sha384-'+createHash('sha384').update(actualSdk).digest('base64'),actualIntegrity,'The actual shipped SDK bytes match their pinned integrity');
const emptyOwnerPhone=()=>({phone_e164:'',phone_revision:0,revision:0,updates_requested:false,hello_requested:false,last_test:null,execution_enabled:false});
let ownerPhone=emptyOwnerPhone();
let contactDetail=null,contactSchedules=[];
let state=fixture(),authorized=true,apiCalls=[],apiMethods=[],stateFailure=null,tokenGate=null,tokenStarted=false,contactGate=null,contactStarted=false,scriptLoads=0,captionCalls=[];
const wav=Buffer.alloc(44+16000*2*4);wav.write('RIFF',0);wav.writeUInt32LE(wav.length-8,4);wav.write('WAVEfmt ',8);wav.writeUInt32LE(16,16);wav.writeUInt16LE(1,20);wav.writeUInt16LE(1,22);wav.writeUInt32LE(16000,24);wav.writeUInt32LE(32000,28);wav.writeUInt16LE(2,32);wav.writeUInt16LE(16,34);wav.write('data',36);wav.writeUInt32LE(wav.length-44,40);
const server=http.createServer((req,res)=>{
 const url=new URL(req.url,'http://fixture');
 if(url.pathname==='/phone-operator-capture-v1.js'){res.setHeader('Content-Type','text/javascript');return res.end(captureSource);}
 if(url.pathname==='/phone-workspace-v1.js'){res.setHeader('Content-Type','text/javascript');return res.end(source);}
 if(url.pathname==='/phone-workspace-v1.css'){res.setHeader('Content-Type','text/css');return res.end(css);}
 if(shellStyles.has(url.pathname)){res.setHeader('Content-Type','text/css');return res.end(shellStyles.get(url.pathname));}
 if(url.pathname==='/assets/twilio-voice-2.18.5.min.js'){res.setHeader('Content-Type','text/javascript');if(url.searchParams.has('actual'))return res.end(actualSdk);scriptLoads++;return res.end(sdk);}
 if(url.pathname==='/sdk-smoke'){res.setHeader('Content-Type','text/html');return res.end('<!doctype html><meta charset="utf-8"><script src="/assets/twilio-voice-2.18.5.min.js?actual=1" integrity="'+actualIntegrity+'" crossorigin="anonymous"></script>');}
 if(url.pathname==='/api/fixture-audio'){res.setHeader('Content-Type','audio/wav');return res.end(wav);}
 if(url.pathname==='/api/phone-operator-live-v1.php'){
  let body='';req.on('data',chunk=>body+=chunk);req.on('end',()=>{
   const input=JSON.parse(body);res.setHeader('Content-Type','application/json');
   assert.equal(req.method,'POST');assert.equal(input.csrf_token,'fixture-csrf');assert.equal(input.call_id,outgoingId);
   assert.equal(input.agent_id,2);assert.equal(input.conversation_id,10);
   captionCalls.push({action:input.action,seq:input.seq,consent_attested:input.consent_attested,bytes:input.pcm?Buffer.from(input.pcm,'base64').length:0});
   const token='f'.repeat(64);let result={};
   if(input.action==='start'){assert.equal(input.consent_attested,true);state.operator_live.state='authorized';result={call_id:outgoingId,token,expires_epoch:Math.floor(Date.now()/1000)+120,chunk_seconds:4,execution:'captions_only'};}
   else if(input.action==='chunk'){assert.equal(input.token,token);assert.equal(Buffer.from(input.pcm,'base64').length,128000);result={call_id:outgoingId,seq:input.seq,speaker:'remote_audio',text:'Fixture caller <script>words</script>'};}
   else if(input.action==='stop'){assert.equal(input.token,token);state.operator_live.state='closed';state.operator_live.eligible=false;result={call_id:outgoingId,stopped:true};}
   else{res.statusCode=422;return res.end(JSON.stringify({ok:false}));}
   res.end(JSON.stringify({ok:true,...result}));
  });return;
 }
 if(url.pathname==='/api/phone-workspace-v1.php'){
  let body='';req.on('data',c=>body+=c);req.on('end',async()=>{
   const values=req.method==='POST'?JSON.parse(body):Object.fromEntries(url.searchParams);apiCalls.push(values);apiMethods.push({action:values.action,method:req.method});res.setHeader('Content-Type','application/json');
   if(!authorized){res.statusCode=403;return res.end(JSON.stringify({ok:false,error:'Phone access unavailable'}));}
   if(req.method==='POST'&&values.csrf_token!=='fixture-csrf'){res.statusCode=419;return res.end(JSON.stringify({ok:false}));}
   let result={};
   if(values.action==='state'&&stateFailure){res.statusCode=stateFailure.status;return res.end(JSON.stringify({ok:false,error:stateFailure.code,message:stateFailure.message}));}
   if(values.action==='state')result={state:structuredClone(state)};
   if(values.action==='managed_review'){
     assert.equal(req.method,'GET');assert.equal(values.call_id,'6'.repeat(32));
     assert.equal(Number(values.conversation_id),10);assert.equal(Number(values.agent_id),2);
     result={review:{call_id:values.call_id,coverage:'ready',execution_enabled:false,outcome:'completed',duration_seconds:35,
       transcript_url:'/artist-listening.php?session=772',
       report:{text:'Key findings\nFixture report <img src=x onerror=alert(1)>\nActions & follow-ups\nConfirm timing.',review_state:'unreviewed',historical:true},
       followups:[{text:'Confirm timing.',next_step:'Review the source.',review_state:'unreviewed',source_url:'/artist-listening-evidence.php?session=772&plugin=actions&section=items&item=fixture'},
         {text:'Untrusted link',review_state:'accepted',source_url:'https://untrusted.invalid/secret'}],
       continuity:{state:'permission_required',calls:[]}}};
   }
   if(values.action==='owner_phone_settings')result={owner_phone:structuredClone(ownerPhone)};
   if(values.action==='save_owner_phone'){
     assert.equal(Number(values.expected_revision),ownerPhone.revision);
     ownerPhone={...emptyOwnerPhone(),phone_e164:values.phone_e164,phone_revision:ownerPhone.phone_revision+1,revision:ownerPhone.revision+1};
     result={owner_phone:structuredClone(ownerPhone)};
   }
   if(values.action==='save_owner_call_preferences'){
     assert.equal(Number(values.expected_revision),ownerPhone.revision);
     Object.assign(ownerPhone,{updates_requested:values.updates_requested,hello_requested:values.hello_requested,revision:ownerPhone.revision+1});
     result={owner_phone:structuredClone(ownerPhone)};
   }
   if(values.action==='prepare_owner_test'){
     assert.equal(values.confirmed,true);assert.equal(values.phone_revision,ownerPhone.phone_revision);assert.equal(Object.hasOwn(values,'to_number'),false);assert.equal(Object.hasOwn(values,'recording_enabled'),false);
     ownerPhone.last_test={call_id:outgoingId,state:'prepared',received:false,can_confirm:false};
     result={call:{id:9,public_id:outgoingId,owner_test:true,peer_e164:ownerPhone.phone_e164,conversation_id:Number(values.conversation_id),agent_id:Number(values.agent_id),state:'prepared'},connect_params:{call_intent:'c'.repeat(64)}};
   }
   if(values.action==='prepare_connected_call'){
     assert.equal(values.confirmed,true);assert.equal(values.device_id,'4'.repeat(32));assert.equal(values.profile_id,'5'.repeat(32));
     assert.equal(values.profile_revision,3);assert.equal(values.phone_revision,1);assert.equal(Number(values.conversation_id),10);
     assert.equal(Object.hasOwn(values,'to_number'),false);assert.equal(Object.hasOwn(values,'recording_enabled'),false);
     result={call:{id:9,public_id:outgoingId,connected_phone:true,peer_e164:'',peer_masked:'Ending in 0135',conversation_id:10,agent_id:2,state:'prepared'},connect_params:{call_intent:'c'.repeat(64)}};
   }
   if(values.action==='confirm_owner_test'){
     assert.equal(values.call_id,outgoingId);assert.equal(values.received,true);ownerPhone.last_test.received=true;ownerPhone.last_test.can_confirm=false;result={owner_phone:structuredClone(ownerPhone)};
   }
   if(values.action==='token'){tokenStarted=true;if(tokenGate)await tokenGate;result={token:'fixture-token-'+String(apiCalls.length).padStart(20,'0'),identity:'fixture-number-1',expires_at:Math.floor(Date.now()/1000)+3600};}
   if(values.action==='prepare_call')result={call:{id:9,public_id:outgoingId,peer_e164:values.to_number,conversation_id:Number(values.conversation_id),agent_id:Number(values.agent_id),state:'preparing'},connect_params:{call_intent:'c'.repeat(64)}};
   if(values.action==='bind_call')result={call:{id:8,public_id:values.call_id,peer_e164:'+16025550444',conversation_id:Number(values.conversation_id),agent_id:Number(values.agent_id),state:'ringing'}};
   if(values.action==='favorite'){state.contacts[0].favorite=values.favorite;state.favorites=values.favorite?[state.contacts[0]]:[];}
   if(values.action==='save_recording_policy'){state.post_call_policy={enabled:values.enabled,retain_audio:values.retain_audio,transcribe:values.transcribe,analyze:values.analyze,model_route:'account_cloud',modules:values.modules};result={policy:state.post_call_policy};}
   if(values.action==='operator_calendar_preview'){
    const p=state.auto_call?.policies.find(item=>item.contact_id===Number(values.contact_id)&&item.number_id===Number(values.number_id));
    if(!p||!p.enabled||p.revision!==Number(values.expected_revision)){res.statusCode=409;return res.end(JSON.stringify({ok:false,error:'operator_policy_changed',message:'Review current permission.'}));}
    const start=Math.ceil((Date.now()/1000+3600)/900)*900;
    result={plan:{contact_id:p.contact_id,number_id:p.number_id,revision:p.revision,coverage:'cloud_calendar_only',execution_enabled:false,existing:null,slots:[
      {start_epoch:start,duration_seconds:600,timezone:p.settings.timezone,label:'Tomorrow at 10 AM'},
      {start_epoch:start+86400,duration_seconds:600,timezone:p.settings.timezone,label:'Following day at 10 AM'}]}};
   }
   if(values.action==='save_operator_calendar'){
    result={created:true,appointment:{event_id:81,title:'Planned phone call — Fixture contact',label:'Following day at 10 AM',
      start_at:new Date(values.start_epoch*1000).toISOString(),end_at:new Date((values.start_epoch+values.duration_seconds)*1000).toISOString(),
      url:'/calendar-event.php?id=81',execution_enabled:false}};
   }
   if(values.action==='save_auto_call_policy'){
    const p=state.auto_call?.policies.find(item=>item.contact_id===values.contact_id&&item.number_id===values.number_id);
    if(!p||p.revision!==values.expected_revision||values.expected_destination!==p.destination_e164||values.expected_line!==p.line_e164){res.statusCode=409;return res.end(JSON.stringify({ok:false,error:'operator_policy_changed',message:'Review the current authorization.'}));}
    Object.assign(p,{enabled:values.enabled,revision:p.revision+1,state:values.enabled?'saved':'off',settings:values.settings,
      authorized_line_e164:values.enabled?p.line_e164:'',authorized_destination_e164:values.enabled?p.destination_e164:''});
    result={policy:structuredClone(p)};
   }
   if(values.action==='mark_voicemail_read')state.voicemails.find(v=>v.id===String(values.voicemail_id)).read=true;
   if(values.action==='save_caller_contact'){
    contactStarted=true;if(contactGate)await contactGate;
    const call=state.calls.find(call=>(call.public_id||call.call_id)===values.call_id);
    if(!call){res.statusCode=404;return res.end(JSON.stringify({ok:false}));}
    const contact=state.contacts.find(contact=>contact.phone_e164===call.peer_e164)||{id:8,phone_e164:call.peer_e164,favorite:false};contact.name=values.name;
    if(!state.contacts.includes(contact))state.contacts.push(contact);
    for(const row of [...state.calls,...state.voicemails,...(state.active_call?[state.active_call]:[])])if(row.peer_e164===contact.phone_e164){row.contact_id=contact.id;row.contact_name=contact.name;}
    result={contact};
   }
   if(values.action==='phone_contact_detail'){
    assert.equal(req.method,'GET');assert.equal(Number(values.contact_id),contactDetail.id);
    result={contact:structuredClone(contactDetail),editable:true,schedules:{available:true,items:structuredClone(contactSchedules),execution_enabled:false}};
   }
   if(values.action==='save_phone_contact'){
    contactDetail={id:values.contact_id||18,name:values.name,phone_e164:values.phone,relationship:values.relationship,notes:values.notes,revision:'b'.repeat(64)};
    state.contacts=[{id:contactDetail.id,name:contactDetail.name,phone_e164:contactDetail.phone_e164,relationship:contactDetail.relationship,favorite:false}];
    result={contact:structuredClone(contactDetail),created:values.contact_id===0};
   }
   if(values.action==='save_phone_contact_schedule'){
    assert.equal(values.contact_id,contactDetail.id);assert.equal(values.contact_revision,contactDetail.revision);assert.equal(values.confirmed,true);
    const schedule={event_id:values.event_id||91,revision:'c'.repeat(64),date:values.date,time:values.time,timezone:values.timezone,recurrence:values.recurrence,duration_minutes:values.duration_minutes,editable:true,objectives:values.objectives,next_label:'Thu, Dec 3, 5:15 PM UTC',url:'/calendar-event.php?id=91',execution_enabled:false};
    contactSchedules=[schedule];result={schedule,created:values.event_id===0,execution_enabled:false};
   }
   if(values.action==='cancel_phone_contact_schedule'){
    assert.equal(values.contact_id,contactDetail.id);assert.equal(values.event_id,91);assert.equal(values.expected_revision,'c'.repeat(64));assert.equal(values.confirmed,true);
    contactSchedules=[];result={event_id:91,cancelled:true,execution_enabled:false};
   }
   res.end(JSON.stringify({ok:true,...result}));
  });return;
 }
 const cid=url.searchParams.get('new')==='1'?0:10;
 res.setHeader('Content-Type','text/html');res.end(`${fixtureHead}${headerShell('<button class="chat-icon-button vp3-phone-header-button" data-phone-workspace-toggle aria-label="Phone"><svg width="18" height="18" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M7 3H4a1 1 0 0 0-1 1c0 9.4 7.6 17 17 17a1 1 0 0 0 1-1v-3l-5-2-2 2a13 13 0 0 1-7-7l2-2-2-5Z"/></svg></button>')}<div id="chatThread" class="chat-thread"></div><script>window.VP3_PHONE_WORKSPACE_V1=${JSON.stringify({endpoint:'/api/phone-workspace-v1.php',operator_live_endpoint:'/api/phone-operator-live-v1.php',operator_capture_url:'/phone-operator-capture-v1.js',greeting_endpoint:'/api/phone-voicemail-greeting-v1.php',greeting_capture_url:'/phone-voicemail-capture-v1.js',urlbase:'/',csrf:'fixture-csrf',owner_id:1,agent_id:2,conversation_id:cid,sdk_url:'/assets/twilio-voice-2.18.5.min.js',sdk_integrity:integrity})};window.currentConversation=${cid};window.STONEFELLOW_CHAT_CONTINUITY={conversationId:()=>window.currentConversation,isReady:()=>true,isRestoring:()=>false,isBusy:()=>false,ensureConversation:async()=>{window.currentConversation=12;window.VP3PhoneWorkspaceV1.contextChanged({conversation_id:12,agent_id:2,ready:true});return 12;}};</script><script src="/phone-workspace-v1.js"></script>`);
});
await new Promise(resolve=>server.listen(0,'127.0.0.1',resolve));
const base='http://127.0.0.1:'+server.address().port,browser=await chromium.launch({headless:true,...(process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH?{executablePath:process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH}:{})});
let checks=0;const errors=[];let page,succeeded=false;
async function fresh(suffix='',options={}){
 state=fixture();ownerPhone=emptyOwnerPhone();contactDetail=null;contactSchedules=[];authorized=true;tokenGate=null;tokenStarted=false;contactGate=null;contactStarted=false;apiCalls=[];apiMethods=[];stateFailure=options.stateFailure||null;
 if(page)await page.close();page=await browser.newPage({viewport:options.viewport||{width:1360,height:900}});page.on('pageerror',e=>errors.push(e.message));await page.goto(base+'/'+suffix);await page.waitForFunction(()=>window.VP3PhoneWorkspaceV1);await page.getByRole('button',{name:'Phone',exact:true}).click();await page.getByRole(options.stateFailure?'button':'combobox',{name:options.stateFailure?'Retry':'Selected phone number',exact:true}).waitFor();if(options.stateFailure)await page.waitForFunction(()=>[...document.querySelectorAll('button')].some(button=>button.textContent==='Retry'&&!button.disabled&&!button.closest('[hidden]')));return page;
}
async function check(name,run){await run();checks++;console.log('PASS '+name);}
async function enable(){await page.getByRole('button',{name:'Enable calls in this browser',exact:true}).click();await page.waitForFunction(()=>window.phoneFixture?.devices.length&&document.querySelector('.vp3-phone-notice')?.textContent==='Ready for incoming calls.');}
async function showDrawer(){if(!await page.locator('.vp3-phone-panel').isVisible())await page.getByRole('button',{name:'Phone',exact:true}).click();await page.getByRole('tab',{name:'Connections',exact:true}).click();}
async function closeDrawer(){if(await page.locator('.vp3-phone-panel').isVisible())await page.locator('.vp3-phone-header').getByRole('button',{name:'Close phone workspace',exact:true}).click();}
async function call(){await page.getByLabel('Phone number to call',{exact:true}).fill('+16025550555');await page.locator('.vp3-phone-dial button').click();await page.getByRole('button',{name:'Hang up',exact:true}).waitFor();}
async function canonicalCall(id,callId){
 await page.evaluate(({id,callId})=>{
  const card=document.createElement('article');card.className='message assistant';card.id=id;
  card.innerHTML='<div class="message-avatar">S</div><div class="message-body"><div class="message-role">Agent</div><div class="message-text">Phone call.</div></div>';
  document.getElementById('chatThread').append(card);VP3PhoneWorkspaceV1.bindMessage(card,{kind:'call',call_id:callId});
 },{id,callId});return page.locator('#'+id);
}
const historyCall=(id,extra={})=>({id:90,public_id:id,call_id:id,direction:'inbound',peer_e164:'+16025550222',phone_e164:'+16025550111',number_id:1,agent_id:2,state:'completed',duration_seconds:125,created_at:'2026-11-23T14:00:00Z',source_kind:'browser',browser_control:true,recording_enabled:false,...extra});
async function waitForContactRequest(){for(let i=0;!contactStarted;i++){if(i>100)throw Error('Contact save was not requested');await new Promise(resolve=>setTimeout(resolve,10));}}
try{
 await check('the actual bundled Twilio SDK loads with pinned integrity and no device registration or capture',async()=>{
  page=await browser.newPage();page.on('pageerror',e=>errors.push(e.message));await page.addInitScript(()=>{window.sdkSmokeCapture=0;const get=navigator.mediaDevices?.getUserMedia?.bind(navigator.mediaDevices);if(get)navigator.mediaDevices.getUserMedia=(...args)=>{window.sdkSmokeCapture++;return get(...args);};});
  await page.goto(base+'/sdk-smoke');await page.waitForFunction(()=>typeof window.Twilio?.Device==='function');assert.equal(await page.evaluate(()=>window.sdkSmokeCapture),0);assert.equal(apiCalls.length,0);
 });
 await check('failed Phone loading has actionable recovery on desktop and mobile without enabling calls',async()=>{
  for(const scenario of [
   {viewport:{width:1360,height:900},code:'phone_upgrade_required'},
   {viewport:{width:320,height:740},code:'phone_unavailable'},
  ]){
   await fresh('',{viewport:scenario.viewport,stateFailure:{status:503,code:scenario.code,message:'SQLSTATE private service <img src=x onerror=alert(1)> https://private.invalid/token'}});
   const panel=page.locator('.vp3-phone-panel'),retry=panel.getByRole('button',{name:'Retry',exact:true});
   assert.equal(await retry.count(),1);assert.equal(await retry.isEnabled(),true);
   const text=await panel.innerText();assert.doesNotMatch(text,/Loading assigned phone connections|Loading contacts|Loading recent calls|Loading voicemail/);assert.doesNotMatch(text,/SQLSTATE|private service|private\.invalid/);
   if(scenario.code==='phone_upgrade_required'){assert.match(text,/Cloud database upgrade/);assert.match(text,/upgrade\.php/);}
   assert.equal(await panel.locator('img,script,a[href^="https://private.invalid"]').count(),0);
   const layout=await panel.evaluate(node=>({width:node.clientWidth,scroll:node.scrollWidth}));assert.ok(layout.scroll<=layout.width+1,'Recovery fits the '+scenario.viewport.width+'px drawer');
   const hit=await retry.evaluate(button=>{const box=button.getBoundingClientRect(),at=document.elementFromPoint(box.x+box.width/2,box.y+box.height/2);return (at===button||button.contains(at))&&box.height>=38&&box.width>60;});assert.equal(hit,true,'Retry is visible and receives pointer input');
   if(scenario.viewport.width===320){await fs.mkdir(path.join(root,'tests/artifacts'),{recursive:true});await page.screenshot({path:path.join(root,'tests/artifacts/phone-workspace-recovery-mobile.png')});}
   const originalUrl=page.url(),requestCount=apiCalls.length;stateFailure=null;await retry.click();await page.getByLabel('Selected phone number',{exact:true}).waitFor();
   assert.equal(page.url(),originalUrl);assert.equal(await page.getByLabel('Selected phone number',{exact:true}).inputValue(),'1');assert.equal(await retry.isVisible(),false);
   assert.ok(apiCalls.length>requestCount);assert.ok(apiCalls.every(call=>call.action==='state'));assert.ok(apiMethods.every(call=>call.method==='GET'));
   assert.equal(await page.evaluate(()=>window.phoneFixture?.connects.length||0),0);assert.equal(scriptLoads,0,'Loading and retrying never load the voice SDK');
  }
 });
 await check('a temporary refresh failure labels retained data and disables calling until Retry succeeds',async()=>{
  await fresh();await page.evaluate(()=>VP3PhoneWorkspaceV1.refresh());
  stateFailure={status:503,code:'phone_unavailable'};await page.evaluate(()=>VP3PhoneWorkspaceV1.refresh());
  const panel=page.locator('.vp3-phone-panel');assert.match(await panel.innerText(),/cached|previously loaded|last (?:confirmed|loaded|saved)|may be out of date/i);
  assert.equal(await page.getByLabel('Selected phone number',{exact:true}).inputValue(),'1');assert.equal(await page.locator('.vp3-phone-dial button').isEnabled(),false);assert.equal(await page.getByRole('button',{name:'Enable calls in this browser',exact:true}).isEnabled(),false);
  stateFailure=null;await panel.getByRole('button',{name:'Retry',exact:true}).click();await page.waitForFunction(()=>!document.querySelector('.vp3-phone-dial button').disabled);
  assert.equal(await page.getByRole('button',{name:'Enable calls in this browser',exact:true}).isEnabled(),true);assert.ok(apiMethods.every(call=>call.action==='state'&&call.method==='GET'));
 });
 await fresh();
 await check('assigned workspace has no token or SDK activity until the owner enables calls',async()=>{assert.equal(scriptLoads,0);assert.equal(apiCalls.some(c=>c.action==='token'),false);assert.equal(await page.locator('.vp3-phone-panel').isVisible(),true);assert.equal(await page.getByText(/purchase|subscribe|sign up/i).count(),0);});
 await check('Phone covers the production header and Close remains clickable on desktop and mobile',async()=>{
  for(const viewport of [{width:1360,height:900},{width:390,height:844},{width:320,height:740}]){
   await page.setViewportSize(viewport);
   await page.waitForFunction(()=>document.querySelector('.vp3-phone-panel').getAnimations().every(animation=>animation.playState==='finished'));
   const hits=await page.evaluate(()=>{
    const close=document.querySelector('.vp3-phone-close'),box=close.getBoundingClientRect(),header=document.querySelector('.chat-topbar').getBoundingClientRect();
    const closeHit=document.elementFromPoint(box.x+box.width/2,box.y+box.height/2);
    const covered=[header.x+20,header.right-20].every(x=>document.elementFromPoint(x,header.y+header.height/2)?.closest('.vp3-phone-workspace'));
    return {close:closeHit===close||close.contains(closeHit),covered};
   });
   assert.equal(hits.close,true,'The Phone Close button receives pointer input at '+viewport.width+'px');
   assert.equal(hits.covered,true,'The Phone backdrop/panel covers both sides of the application header at '+viewport.width+'px');
   await page.locator('.vp3-phone-close').click();
   assert.equal(await page.locator('.vp3-phone-panel').isVisible(),false);
   assert.equal(await page.getByRole('button',{name:'Phone',exact:true}).evaluate(node=>node===document.activeElement),true);
   await showDrawer();
   if(viewport.width>500){
    await page.locator('.vp3-phone-backdrop').click({position:{x:20,y:20}});
    assert.equal(await page.locator('.vp3-phone-panel').isVisible(),false,'Clicking the backdrop above the application header closes Phone');
    assert.equal(await page.getByRole('button',{name:'Phone',exact:true}).evaluate(node=>node===document.activeElement),true);
    await showDrawer();
   }
  }
  await page.setViewportSize({width:1360,height:900});
 });
 await check('favorites and contact text are safe, scoped and saved through CSRF actions',async()=>{await page.getByRole('tab',{name:'Contacts',exact:true}).click();assert.equal(await page.locator('.vp3-phone-panel img').count(),0);await page.getByRole('button',{name:/^Favorite Fixture contact/}).click();await page.getByRole('button',{name:/^Remove favorite Fixture contact/}).waitFor();const saved=apiCalls.find(c=>c.action==='favorite');assert.equal(saved.contact_id,7);assert.equal(saved.conversation_id,10);assert.equal(saved.agent_id,2);assert.equal(saved.csrf_token,'fixture-csrf');});
 await check('contact Auto Call permission remains default-off, explicit and revocable without dialing',async()=>{
  state.auto_call={available:true,execution_enabled:false,policies:[{contact_id:7,agent_id:2,number_id:1,line_e164:'+16025550111',destination_e164:'+16025550222',revision:0,enabled:false,state:'off',can_enable:true,reason:'',authorized_line_e164:'',authorized_destination_e164:'',recording_required:true,transcription_required:true,execution_enabled:false,settings:{timezone:'UTC',window_start:'09:00',window_end:'17:00',max_calls_per_day:1,min_gap_minutes:1440,max_duration_seconds:600}}]};
  await page.evaluate(()=>VP3PhoneWorkspaceV1.refresh());
  const launcher=page.getByRole('button',{name:/^Agent Auto Call for Fixture contact/});
  assert.match(await launcher.innerText(),/Off/);
  await launcher.click();
  const editor=page.locator('.vp3-phone-auto-call-form');
  assert.equal(await editor.getByLabel('Allow Agent Auto Call',{exact:true}).isChecked(),false);
  assert.match(await editor.innerText(),/Autonomous dialing is not available/);
  await editor.getByLabel('Allow Agent Auto Call',{exact:true}).check();
  await editor.getByLabel('Calling time zone',{exact:true}).fill('America/Phoenix');
  await editor.getByLabel('Maximum calls per day',{exact:true}).fill('2');
  await page.evaluate(()=>VP3PhoneWorkspaceV1.refresh());
  assert.equal(await editor.getByLabel('Maximum calls per day',{exact:true}).inputValue(),'2');
  assert.equal(await editor.getByLabel('Allow Agent Auto Call',{exact:true}).isChecked(),true);
  assert.equal(apiCalls.filter(c=>c.action==='save_auto_call_policy').length,0,'Drafting does not save permission');
  await page.setViewportSize({width:320,height:700});
  assert.equal(await editor.evaluate(n=>n.scrollWidth<=n.clientWidth+1),true,'Compact policy editor fits the drawer');
  await editor.getByRole('button',{name:'Save Auto Call permissions',exact:true}).click();
  await editor.waitFor({state:'detached'});
  assert.match(await launcher.innerText(),/Permission saved/);
  const saved=apiCalls.find(c=>c.action==='save_auto_call_policy');
  assert.equal(saved.contact_id,7);assert.equal(saved.number_id,1);assert.equal(saved.expected_revision,0);assert.equal(saved.expected_destination,'+16025550222');assert.equal(saved.expected_line,'+16025550111');
  assert.equal(saved.settings.timezone,'America/Phoenix');assert.equal(saved.settings.max_calls_per_day,2);assert.equal(saved.csrf_token,'fixture-csrf');
  const planButton=page.getByRole('button',{name:/^Plan a call with Fixture contact/});
  await planButton.click();
  const planEditor=page.getByRole('region',{name:'Plan a phone call'});
  await planEditor.getByRole('radio',{name:'Following day at 10 AM',exact:true}).check();
  await page.evaluate(()=>VP3PhoneWorkspaceV1.refresh());
  assert.equal(await planEditor.getByRole('radio',{name:'Following day at 10 AM',exact:true}).isChecked(),true,'Polling preserves reviewed slot selection');
  assert.equal(apiCalls.filter(c=>c.action==='save_operator_calendar').length,0,'Reading suggested times never creates an event');
  await planEditor.getByRole('button',{name:'Save Calendar appointment',exact:true}).click();
  await planEditor.getByRole('link',{name:'Open Calendar appointment',exact:true}).waitFor();
  assert.match(await planEditor.getByRole('link',{name:'Open Calendar appointment',exact:true}).getAttribute('href'),/\/calendar-event\.php\?id=81$/);
  const appointment=apiCalls.find(c=>c.action==='save_operator_calendar');
  assert.equal(appointment.contact_id,7);assert.equal(appointment.expected_revision,1);assert.equal(appointment.duration_seconds,600);
  assert.equal(appointment.csrf_token,'fixture-csrf');
  await planEditor.getByRole('button',{name:'Close plan',exact:true}).click();
  state.auto_call.policies[0].can_enable=false;state.auto_call.policies[0].state='blocked';state.auto_call.policies[0].reason='Recording approval changed.';
  await page.evaluate(()=>VP3PhoneWorkspaceV1.refresh());
  await launcher.click();await editor.getByLabel('Allow Agent Auto Call',{exact:true}).uncheck();
  await editor.getByRole('button',{name:'Save Auto Call permissions',exact:true}).click();
  await editor.waitFor({state:'detached'});
  assert.match(await launcher.innerText(),/Off/);
  assert.equal(apiCalls.filter(c=>c.action==='save_auto_call_policy').at(-1).enabled,false);
  assert.equal(apiCalls.some(c=>['prepare_call','token'].includes(c.action)),false,'Contact authorization never starts a carrier session');
  await page.setViewportSize({width:1360,height:900});
 });
 await check('saved voicemail uses authenticated audio and safe transcript/report links',async()=>{await page.getByRole('tab',{name:'Voicemail',exact:true}).click();assert.equal(await page.locator('.vp3-phone-voicemail audio').count(),1);assert.equal(await page.locator('.vp3-phone-voicemail a').count(),1);assert.equal(await page.locator('.vp3-phone-voicemail script').count(),0);await page.locator('.vp3-phone-voicemail summary').first().click();assert.match(await page.locator('.vp3-phone-voicemail').first().innerText(),/<script>unsafe/);await page.locator('audio').evaluate(async audio=>{audio.muted=true;audio.loop=true;window.savedVoicemailAudio=audio;await audio.play();});await page.evaluate(()=>window.VP3PhoneWorkspaceV1.refresh());assert.equal(await page.locator('audio').evaluate(audio=>audio===window.savedVoicemailAudio&&!audio.paused),true,'Polling preserves active playback');});
 await check('absent, null and blank media/transcript/report URLs never become same-page links',async()=>{await page.locator('audio').evaluateAll(nodes=>nodes.forEach(audio=>audio.pause()));const previous=structuredClone(state.voicemails);state.voicemails=[undefined,null,'','   '].map((missing,index)=>({id:'job-'+(70+index),call_id:'fixture_call_'+index,peer_e164:'+16025550222',transcript:'Fixture without media URL',audio_url:missing,transcript_url:missing,report_url:missing}));await page.evaluate(()=>VP3PhoneWorkspaceV1.refresh());assert.equal(await page.locator('.vp3-phone-voicemail audio').count(),0);assert.equal(await page.locator('.vp3-phone-voicemail a').count(),0);state.voicemails=previous;await page.evaluate(()=>VP3PhoneWorkspaceV1.refresh());});
 await check('drawer focus and Escape restore the header button',async()=>{await page.keyboard.press('Escape');assert.equal(await page.locator('.vp3-phone-panel').isVisible(),false);assert.equal(await page.getByRole('button',{name:'Phone',exact:true}).evaluate(n=>n===document.activeElement),true);await page.getByRole('button',{name:'Phone',exact:true}).click();await page.getByRole('tab',{name:'Connections',exact:true}).click();});
 await check('after-call settings are explicit, persisted and keep unsaved edits across polling',async()=>{assert.equal(await page.getByLabel('Enable Cloud transcription and analysis for recorded calls',{exact:true}).isChecked(),false);await page.getByLabel('Enable Cloud transcription and analysis for recorded calls',{exact:true}).check();await page.getByLabel('Create transcripts',{exact:true}).check();await page.getByLabel('Create a call report',{exact:true}).check();await page.evaluate(()=>VP3PhoneWorkspaceV1.refresh());assert.equal(await page.getByLabel('Enable Cloud transcription and analysis for recorded calls',{exact:true}).isChecked(),true);await page.getByRole('button',{name:'Save after-call settings',exact:true}).click();await page.waitForFunction(()=>document.querySelector('.vp3-phone-notice').textContent==='After-call settings saved.');const saved=apiCalls.find(c=>c.action==='save_recording_policy');assert.equal(saved.number_id,1);assert.equal(saved.enabled,true);assert.deepEqual(saved.modules,['basic','qa','actions','decisions','followup']);});
 await check('polling preserves focused dial text and caret',async()=>{const field=page.getByLabel('Phone number to call',{exact:true});await field.fill('+16025550555');await field.evaluate(n=>n.setSelectionRange(4,4));await page.evaluate(()=>VP3PhoneWorkspaceV1.refresh());assert.equal(await field.evaluate(n=>n===document.activeElement&&n.selectionStart===4),true);assert.equal(await field.inputValue(),'+16025550555');});
 await check('browser registration loads the pinned client without taking the microphone',async()=>{await enable();assert.equal(scriptLoads,1);assert.equal(await page.evaluate(()=>phoneFixture.claims),0);assert.equal(await page.locator('script[src*="twilio-voice-2.18.5"]').getAttribute('integrity'),integrity);});
 await check('incoming calls announce in Chat; Decline takes no microphone or bind action',async()=>{await closeDrawer();await page.evaluate(()=>phoneFixture.incoming());await page.locator('#chatThread').getByRole('button',{name:'Accept',exact:true}).waitFor();await page.locator('#chatThread').getByRole('button',{name:'Decline',exact:true}).click();assert.equal(await page.evaluate(()=>phoneFixture.rejects),1);assert.equal(await page.evaluate(()=>phoneFixture.claims),0);assert.equal(apiCalls.some(c=>c.action==='bind_call'),false);});
 await check('Accept binds the exact current conversation and releases microphone on remote end',async()=>{await closeDrawer();await page.evaluate(()=>phoneFixture.incoming());await page.locator('#chatThread').getByRole('button',{name:'Accept',exact:true}).click();await page.locator('#chatThread').getByRole('button',{name:'Hang up',exact:true}).waitFor();assert.equal(await page.evaluate(()=>phoneFixture.accepts),1);assert.equal(apiCalls.find(c=>c.action==='bind_call').call_id,incomingId);await page.evaluate(()=>phoneFixture.calls.at(-1).emit('disconnect'));await page.waitForFunction(()=>phoneFixture.releases===1);assert.equal(await page.evaluate(()=>phoneFixture.calls.at(-1).stream.getTracks().every(t=>t.readyState==='ended')),true);});
 await check('outgoing calls send only an opaque server intent to the SDK and explicit recording choice',async()=>{await showDrawer();await page.getByLabel('Record this call',{exact:true}).check();await call();assert.equal(apiCalls.filter(c=>c.action==='prepare_call').at(-1).recording_enabled,true);assert.deepEqual(await page.evaluate(()=>phoneFixture.connects.at(-1)),{params:{call_intent:'c'.repeat(64)}});await page.locator('.vp3-phone-section:not([hidden])').getByRole('button',{name:'Hang up',exact:true}).click();assert.equal(await page.evaluate(()=>phoneFixture.claims===phoneFixture.releases),true);});
 await check('microphone ownership conflict prevents call preparation',async()=>{const before=apiCalls.filter(c=>c.action==='prepare_call').length;await page.evaluate(()=>phoneFixture.denied=true);await page.getByLabel('Phone number to call',{exact:true}).fill('+16025550555');await page.locator('.vp3-phone-dial button').click();await page.waitForFunction(()=>document.querySelector('.vp3-phone-notice').textContent.includes('Another browser surface'));assert.equal(apiCalls.filter(c=>c.action==='prepare_call').length,before);assert.match(await page.locator('.vp3-phone-notice').innerText(),/Another browser surface/);await page.evaluate(()=>phoneFixture.denied=false);});
 await fresh();await enable();
 await check('late outgoing connection cannot survive a Chat context change',async()=>{await page.evaluate(()=>phoneFixture.holdConnect=true);await call();await page.evaluate(()=>VP3PhoneWorkspaceV1.contextChanged({conversation_id:11,agent_id:2,ready:true}));await page.evaluate(()=>phoneFixture.releaseConnect());await page.waitForFunction(()=>phoneFixture.calls.at(-1).stream.getTracks().every(t=>t.readyState==='ended'));assert.equal(await page.evaluate(()=>phoneFixture.claims===phoneFixture.releases),true);assert.equal(await page.locator('.vp3-phone-panel').getByRole('button',{name:'Hang up',exact:true}).count(),0);});
 await fresh();await enable();
 await check('late token refresh cannot reauthorize a disposed browser connection',async()=>{let release;tokenGate=new Promise(r=>release=r);tokenStarted=false;await page.evaluate(()=>phoneFixture.devices.at(-1).emit('tokenWillExpire'));for(let i=0;!tokenStarted;i++){if(i>100)throw Error('Token refresh not requested');await new Promise(r=>setTimeout(r,10));}await page.evaluate(()=>VP3PhoneWorkspaceV1.contextChanged({conversation_id:11,agent_id:2,ready:true}));release();await page.waitForTimeout(60);assert.equal(await page.evaluate(()=>phoneFixture.updates),0);tokenGate=null;});
 await fresh();await enable();await call();
 await check('assignment loss destroys the SDK, ends owned media and clears private cards',async()=>{authorized=false;await page.evaluate(()=>VP3PhoneWorkspaceV1.refresh());assert.equal(await page.getByRole('button',{name:'Phone',exact:true}).isVisible(),false);assert.equal(await page.evaluate(()=>phoneFixture.devices.every(d=>d.destroyed)),true);assert.equal(await page.evaluate(()=>phoneFixture.calls.every(c=>c.stream.getTracks().every(t=>t.readyState==='ended'))),true);assert.equal(await page.locator('.vp3-phone-section').allTextContents().then(x=>x.join('')),'');});
 await fresh('?new=1');
 await check('a new Chat initializes through its canonical conversation before phone credentials',async()=>{await enable();assert.equal(apiCalls.find(c=>c.action==='token').conversation_id,12);});
 await check('mobile drawer has one body scroll and unclipped controls',async()=>{await page.setViewportSize({width:320,height:740});const bounds=await page.locator('.vp3-phone-panel').boundingBox();assert.ok(bounds.x>=0&&bounds.x+bounds.width<=321);const layout=await page.locator('.vp3-phone-panel').evaluate(panel=>({width:panel.clientWidth,scroll:panel.scrollWidth,body:getComputedStyle(panel.querySelector('.vp3-phone-body')).overflowY}));assert.ok(layout.scroll<=layout.width+1);assert.equal(layout.body,'auto');const labels=await page.locator('.vp3-phone-tabs button').evaluateAll(buttons=>buttons.map(button=>{const range=document.createRange();range.selectNodeContents(button);return {width:range.getBoundingClientRect().width,available:button.clientWidth-4,lines:range.getClientRects().length,height:button.clientHeight};}));assert.ok(labels.every(label=>label.lines===1&&label.width<=label.available+1&&label.height>=44),'Mobile tab labels fit on one line with touch-sized buttons');await fs.mkdir(path.join(root,'tests/artifacts'),{recursive:true});await page.screenshot({path:path.join(root,'tests/artifacts/phone-workspace-mobile.png')});await page.setViewportSize({width:1360,height:900});await page.screenshot({path:path.join(root,'tests/artifacts/phone-workspace-desktop.png')});});
 await fresh();await enable();
 await check('Recent calls combines browser and legacy history; legacy Chat callback starts a new authorized call',async()=>{
  state.calls=[{id:90,public_id:'d'.repeat(32),call_id:'d'.repeat(32),direction:'outbound',peer_e164:'+16025550666',phone_e164:'+16025550111',state:'completed',source_kind:'browser',browser_control:true,created_at:'2026-11-23T12:00:00Z'},{id:91,public_id:'legacy-91',call_id:'legacy-91',direction:'inbound',peer_e164:'+16025550777',phone_e164:'+16025550111',state:'voicemail_ready',source_kind:'legacy',browser_control:false,created_at:'2026-11-23T11:00:00Z'}];
  await page.evaluate(()=>VP3PhoneWorkspaceV1.refresh());await page.getByRole('tab',{name:'Recent calls',exact:true}).click();assert.equal(await page.locator('.vp3-phone-section:not([hidden]) .vp3-phone-row').count(),2);
  await closeDrawer();await page.evaluate(()=>{const card=document.createElement('div');card.id='legacyFixtureCard';document.getElementById('chatThread').append(card);VP3PhoneWorkspaceV1.bindMessage(card,{kind:'call',call_id:'legacy-91'});});
  const legacy=page.locator('#legacyFixtureCard');assert.match(await legacy.innerText(),/voicemail ready/);assert.equal(await legacy.getByRole('button',{name:/Accept|Decline|Hang up/}).count(),0);
  await legacy.getByRole('button',{name:'Call back',exact:true}).click();await page.waitForFunction(()=>phoneFixture.connects.length===1);assert.equal(apiCalls.filter(c=>c.action==='prepare_call').at(-1).to_number,'+16025550777');assert.equal(apiCalls.some(c=>c.action==='bind_call'),false);assert.deepEqual(await page.evaluate(()=>phoneFixture.connects[0]),{params:{call_intent:'c'.repeat(64)}});
  await showDrawer();await page.locator('.vp3-phone-section:not([hidden])').getByRole('button',{name:'Hang up',exact:true}).click();
 });
 await fresh();
 await check('canonical Chat call cards use the production content column, readable metadata and touch-sized controls',async()=>{
  const id='e'.repeat(32),name=state.contacts[0].name;state.calls=[historyCall(id)];
  await page.evaluate(()=>VP3PhoneWorkspaceV1.refresh());await closeDrawer();const message=await canonicalCall('canonicalCallCard',id),card=message.locator('.vp3-phone-call');
  assert.equal(await message.locator(':scope > .message-body > .vp3-phone-chat-card').count(),1,'The call card belongs inside the message content column');
  assert.equal(await message.evaluate(article=>article.children.length),2,'The avatar/content grid has no third child in its avatar column');
  for(const value of ['Incoming phone call',name,'+16025550222','Saved contact','Call duration','2:05','Call time','Your line','+16025550111','Agent','Fixture agent'])assert.ok((await card.innerText()).includes(value),'Call metadata includes '+value);
  assert.equal(await card.locator('img,script').count(),0,'Contact names are rendered as literal text');
  assert.ok(await card.locator('.vp3-phone-call-details > div').filter({has:page.locator('dt',{hasText:'Call time'})}).locator('dd').innerText(),'Verified call time has a readable value');
  assert.equal(await card.locator('audio,a').count(),0,'Unrelated voicemail never becomes this call’s recording or report');
  for(const viewport of [{width:1360,height:900},{width:320,height:740}]){
   await page.setViewportSize(viewport);const bounds=await card.boundingBox();
   assert.ok(bounds.width>=(viewport.width===320?200:400),'A canonical call card retains a useful width at '+viewport.width+'px');
   assert.ok(bounds.x>=0&&bounds.x+bounds.width<=viewport.width+1,'The call card fits the viewport');
   const controls=await card.getByRole('button').evaluateAll(buttons=>buttons.map(button=>{const box=button.getBoundingClientRect();return {width:box.width,height:box.height};}));
   assert.ok(controls.length>0&&controls.every(box=>box.width>=80&&box.height>=40),'Call actions retain readable touch targets at '+viewport.width+'px');
   assert.equal(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1),true,'The Chat call does not create horizontal page overflow');
   await fs.mkdir(path.join(root,'tests/artifacts'),{recursive:true});await page.screenshot({path:path.join(root,'tests/artifacts/phone-chat-call-'+(viewport.width===320?'mobile':'desktop')+'.png')});
  }
  await page.setViewportSize({width:1360,height:900});state.contacts=[];Object.assign(state.calls[0],{contact_id:7,contact_name:name});
  await page.evaluate(()=>VP3PhoneWorkspaceV1.refresh());assert.ok((await card.innerText()).includes(name),'Projected contacts remain named beyond the bounded Contacts list');
  assert.equal(await card.getByRole('button',{name:'Add to contacts',exact:true}).count(),0);
  state.voicemails=[{id:'job-43',call_id:id,peer_e164:'+16025550222',audio_url:'/api/fixture-audio',transcript:'Verified call transcript <script>unsafe()</script>',report_url:'/fixture-report',read:true}];
  await page.evaluate(()=>VP3PhoneWorkspaceV1.refresh());assert.equal(await card.locator('audio').count(),1);assert.equal(await card.getByRole('link',{name:'Open call report',exact:true}).count(),1);assert.equal(await card.locator('script').count(),0);
  assert.ok((await card.innerText()).includes('Recording'));assert.ok((await card.innerText()).includes('Available'));
  state.calls[0].agent_id=99;await page.evaluate(()=>VP3PhoneWorkspaceV1.refresh());assert.equal(await card.locator('dt').allTextContents().then(labels=>labels.includes('Agent')),false,'A historical call is not attributed to the line’s newly assigned Agent');
  state.calls=[historyCall('legacy-90',{source_kind:'legacy',browser_control:false,duration_seconds:12})];state.voicemails=[];await page.evaluate(()=>VP3PhoneWorkspaceV1.refresh());
  await message.evaluate(article=>VP3PhoneWorkspaceV1.bindMessage(article,{kind:'call',call_id:'legacy-90'}));
  assert.ok((await card.innerText()).includes('Recording duration'));assert.ok((await card.innerText()).includes('0:12'));assert.equal(await card.locator('dt').allTextContents().then(labels=>labels.includes('Call duration')),false,'Historical voicemail duration is not presented as a certified full-call duration');
 });
 await fresh();await enable();
 await check('active calls share safe caller details and one contact draft across Chat and the Phone drawer',async()=>{
  const peer='+16025550444',name='Mint caller <script>safe</script>';
  state.calls=[historyCall(incomingId,{peer_e164:peer,state:'ringing',duration_seconds:0}),historyCall('f'.repeat(32),{id:89,peer_e164:peer,created_at:'2026-11-23T13:00:00Z'})];state.active_call=state.calls[0];
  await page.evaluate(()=>VP3PhoneWorkspaceV1.refresh());await closeDrawer();await page.evaluate(()=>phoneFixture.incoming());const message=await canonicalCall('activeCallerCard',incomingId),card=message.locator('.vp3-phone-call');
  assert.ok((await card.innerText()).includes('Seen in recent calls'));assert.equal(await card.locator('.vp3-phone-peer-name').innerText(),peer,'Prior calls do not invent a person’s name');
  await card.getByRole('button',{name:'Accept',exact:true}).click();await card.getByRole('button',{name:'Hang up',exact:true}).waitFor();
  assert.equal(await card.locator('dt').allTextContents().then(labels=>labels.includes('Call duration')),false,'A ringing or browser-connected call does not claim a completed duration');
  await card.getByRole('button',{name:'Add to contacts',exact:true}).click();const field=card.getByLabel('Contact name',{exact:true});
  assert.equal(await field.getAttribute('maxlength'),'120');await field.fill(name);await field.evaluate(input=>input.setSelectionRange(4,4));
  const phone=card.getByLabel('Caller phone number',{exact:true});assert.equal(await phone.inputValue(),peer);assert.equal(await phone.evaluate(input=>input.readOnly),true);
  const duplicate=await canonicalCall('activeCallerDuplicate',incomingId);await page.evaluate(()=>VP3PhoneWorkspaceV1.refresh());
  assert.equal(await field.inputValue(),name);assert.equal(await field.evaluate(input=>input===document.activeElement&&input.selectionStart===4&&input.selectionEnd===4),true,'Polling and another persisted post preserve the caller draft and caret');
  assert.equal(await duplicate.getByLabel('Contact name',{exact:true}).inputValue(),name);
  await showDrawer();const drawer=page.locator('#vp3PhoneSection-connections .vp3-phone-call');
  for(const value of ['Incoming phone call',peer,'Seen in recent calls','Call time','Your line','+16025550111','Fixture agent'])assert.ok((await drawer.innerText()).includes(value),'The active drawer includes '+value);
  assert.equal(await drawer.getByLabel('Contact name',{exact:true}).inputValue(),name);await drawer.getByLabel('Contact name',{exact:true}).evaluate(input=>{input.focus();input.setSelectionRange(5,5);});
  await page.evaluate(()=>VP3PhoneWorkspaceV1.refresh());assert.equal(await drawer.getByLabel('Contact name',{exact:true}).evaluate(input=>input===document.activeElement&&input.selectionStart===5),true,'The active drawer also preserves contact input focus');
  await closeDrawer();let release;contactStarted=false;contactGate=new Promise(resolve=>release=resolve);
  try{
   await card.getByRole('button',{name:'Save contact',exact:true}).click();await waitForContactRequest();
   assert.equal(await card.locator('button[type="submit"]').isDisabled(),true);assert.equal(await duplicate.locator('button[type="submit"]').isDisabled(),true,'Duplicate posts share the in-flight save guard');
   await duplicate.locator('.vp3-phone-contact-form').evaluate(form=>form.dispatchEvent(new Event('submit',{bubbles:true,cancelable:true})));await page.waitForTimeout(40);
   const saves=apiCalls.filter(row=>row.action==='save_caller_contact');assert.equal(saves.length,1,'Submitting another view cannot duplicate the contact save');
   assert.equal(saves[0].call_id,incomingId);assert.equal(saves[0].name,name);assert.equal(saves[0].csrf_token,'fixture-csrf');assert.equal(saves[0].conversation_id,10);assert.equal(saves[0].agent_id,2);
   for(const key of ['phone','phone_e164','phone_number','to_number'])assert.equal(Object.hasOwn(saves[0],key),false,'The server derives the caller phone rather than trusting '+key);
   release();contactGate=null;await page.waitForFunction(()=>document.querySelector('#activeCallerCard .vp3-phone-peer-name')?.textContent==='Mint caller <script>safe</script>');
  }finally{release?.();contactGate=null;}
  assert.equal(await card.locator('.vp3-phone-contact-form,script').count(),0);assert.equal(await card.locator('.vp3-phone-peer-number').innerText(),peer);assert.equal(await duplicate.locator('.vp3-phone-peer-name').innerText(),name);
  await showDrawer();assert.equal(await drawer.locator('.vp3-phone-peer-name').innerText(),name);assert.equal(await drawer.locator('.vp3-phone-peer-number').innerText(),peer);assert.equal(await drawer.getByRole('button',{name:'Add to contacts',exact:true}).count(),0);
  await drawer.getByRole('button',{name:'Mute',exact:true}).click();assert.equal(await page.evaluate(()=>phoneFixture.calls.at(-1).isMuted()),true);await drawer.getByRole('button',{name:'Unmute',exact:true}).waitFor();
  await drawer.getByRole('button',{name:'Hang up',exact:true}).click();assert.equal(await page.evaluate(()=>phoneFixture.claims===phoneFixture.releases&&phoneFixture.calls.at(-1).stream.getTracks().every(track=>track.readyState==='ended')),true,'Saving a caller preserves the active SDK controls and capture cleanup');
  await page.getByRole('tab',{name:'Contacts',exact:true}).click();assert.ok((await page.locator('#vp3PhoneSection-contacts').innerText()).includes(name));assert.ok((await page.locator('#vp3PhoneSection-contacts').innerText()).includes(peer));
  await page.getByRole('tab',{name:'Recent calls',exact:true}).click();assert.ok((await page.locator('#vp3PhoneSection-calls').innerText()).includes(name),'The saved caller name updates call history');
 });
 await fresh();
 await check('saving an unknown caller updates a playing recorded call without replacing its audio element',async()=>{
  const id='c'.repeat(32),peer='+16025550999',name='Recorded caller';state.calls=[historyCall(id,{peer_e164:peer})];
  state.voicemails=[{id:'job-44',call_id:id,peer_e164:peer,audio_url:'/api/fixture-audio',transcript:'Verified recorded call',read:true}];
  await page.evaluate(()=>VP3PhoneWorkspaceV1.refresh());await closeDrawer();const message=await canonicalCall('playingCallerCard',id),card=message.locator('.vp3-phone-call');
  await card.locator('audio').evaluate(async audio=>{audio.muted=true;audio.loop=true;window.savedPlayingCallerRecording=audio;await audio.play();});
  await card.getByRole('button',{name:'Add to contacts',exact:true}).click();
  assert.equal(await card.locator('audio').evaluate(audio=>audio===window.savedPlayingCallerRecording&&!audio.paused),true,'Opening the contact form preserves the exact playing recording');
  await card.getByLabel('Contact name',{exact:true}).fill(name);await card.getByRole('button',{name:'Save contact',exact:true}).click();
  await page.waitForFunction(()=>document.querySelector('#playingCallerCard .vp3-phone-peer-name')?.textContent==='Recorded caller');await page.evaluate(()=>VP3PhoneWorkspaceV1.refresh());
  assert.equal(await card.locator('.vp3-phone-peer-number').innerText(),peer);assert.equal(await card.locator('.vp3-phone-contact-form').count(),0);
  assert.equal(await card.locator('audio').evaluate(audio=>audio===window.savedPlayingCallerRecording&&!audio.paused),true,'Saving and refreshing caller metadata preserve the same playing audio node');
  await page.evaluate(()=>VP3PhoneWorkspaceV1.contextChanged({conversation_id:11,agent_id:2,ready:true}));
  assert.equal(await page.evaluate(()=>window.savedPlayingCallerRecording.paused&&window.savedPlayingCallerRecording.getAttribute('src')===null),true,'Context teardown stops and clears the retained private recording');
  assert.equal(await message.locator('audio').count(),0);
 });
 await fresh();
 await check('a late caller-contact save cannot restore a form or private post from an earlier Chat context',async()=>{
  const id='9'.repeat(32);state.calls=[historyCall(id,{peer_e164:'+16025550888'})];state.contact_write_ready=false;await page.evaluate(()=>VP3PhoneWorkspaceV1.refresh());await closeDrawer();const message=await canonicalCall('staleCallerCard',id);
  assert.equal(await message.getByRole('button',{name:'Add to contacts',exact:true}).isDisabled(),true);assert.ok((await message.innerText()).includes('An administrator needs to update Contacts'));assert.equal(await message.locator('.vp3-phone-contact-form').count(),0);
  state.contact_write_ready=true;await page.evaluate(()=>VP3PhoneWorkspaceV1.refresh());
  await message.getByRole('button',{name:'Add to contacts',exact:true}).click();await message.getByLabel('Contact name',{exact:true}).fill('Previous context caller');let release;contactStarted=false;contactGate=new Promise(resolve=>release=resolve);
  try{
   await message.getByRole('button',{name:'Save contact',exact:true}).click();await waitForContactRequest();
   await page.evaluate(()=>VP3PhoneWorkspaceV1.contextChanged({conversation_id:11,agent_id:2,ready:true}));await page.evaluate(()=>VP3PhoneWorkspaceV1.refresh());
   release();contactGate=null;await page.waitForTimeout(80);
   assert.equal(await message.locator('.vp3-phone-contact-form,.vp3-phone-call').count(),0);assert.equal(await message.getByRole('button',{name:'Open current Phone workspace',exact:true}).count(),1);
   assert.equal((await message.innerText()).includes('Previous context caller'),false,'A stale save response does not rewrite an earlier private message');
   assert.equal((await page.locator('.vp3-phone-notice').innerText()).includes('Contact saved.'),false,'A stale response cannot announce a save in the new context');
  }finally{release?.();contactGate=null;}
 });
 await fresh();await enable();
 await check('live caller captions require explicit consent, use the existing remote stream and stop without ending the human call',async()=>{
  captionCalls=[];await call();await page.waitForFunction(()=>phoneFixture.calls.at(-1)?.status()==='open');
  await page.evaluate(()=>{
   const remoteAudio=new AudioContext({sampleRate:48000}),destination=remoteAudio.createMediaStreamDestination(),tone=remoteAudio.createOscillator();
   tone.frequency.value=440;const gain=remoteAudio.createGain();gain.gain.value=.05;tone.connect(gain);gain.connect(destination);tone.start();
   phoneFixture.remoteAudio=remoteAudio;phoneFixture.remoteTone=tone;phoneFixture.remoteTrack=destination.stream.getAudioTracks()[0];
   phoneFixture.calls.at(-1).getRemoteStream=()=>destination.stream;
   void remoteAudio.resume();
  });
  state.operator_live={available:true,eligible:true,call_id:outgoingId,state:'off',reason:''};
  await page.evaluate(()=>VP3PhoneWorkspaceV1.refresh());
  const drawer=page.locator('.vp3-phone-panel'),caption=drawer.locator('.vp3-phone-live-caption');
  await caption.getByRole('button',{name:'Enable live caller captions',exact:true}).click();
  assert.equal(await caption.getByRole('button',{name:'Start live captions',exact:true}).isDisabled(),true);
  assert.equal(captionCalls.length,0,'Opening consent UI must not authorize capture');
  await caption.getByLabel('Everyone on this call has agreed to live Cloud AI transcription.',{exact:true}).check();
  await caption.getByRole('button',{name:'Start live captions',exact:true}).click();
  await caption.getByText('Live captions on · human call remains in control',{exact:true}).waitFor({timeout:15000});
  await caption.getByText('Fixture caller <script>words</script>',{exact:true}).waitFor({timeout:20000});
  const firstChunk=captionCalls.find(row=>row.action==='chunk');assert.equal(firstChunk.bytes,128000);assert.equal(firstChunk.seq,1);
  assert.equal(captionCalls[0].consent_attested,true);
  assert.equal(await caption.locator('script').count(),0,'Caption content is inert text');
  const chatCard=await canonicalCall('liveCaptionCard',outgoingId);
  assert.equal(await chatCard.getByText('Fixture caller <script>words</script>',{exact:true}).count(),1);
  const claims=await page.evaluate(()=>phoneFixture.claims);
  await caption.getByRole('button',{name:'Stop live captions',exact:true}).click();
  await caption.getByText('Live captions stopped.',{exact:true}).waitFor();
  assert.equal(await drawer.getByRole('button',{name:'Hang up',exact:true}).count(),1);
  assert.equal(await page.evaluate(()=>phoneFixture.calls.at(-1).status()),'open');
  assert.equal(await page.evaluate(()=>phoneFixture.remoteTrack.readyState),'live','Stop must not end the underlying carrier track');
  assert.equal(await page.evaluate(()=>phoneFixture.claims),claims,'Caption capture cannot acquire another microphone lease');
  assert.equal(await chatCard.getByText('Fixture caller <script>words</script>',{exact:true}).count(),0);
  await drawer.getByRole('button',{name:'Hang up',exact:true}).click();
  await page.evaluate(()=>{phoneFixture.remoteTone.stop();phoneFixture.remoteTrack.stop();void phoneFixture.remoteAudio.close();});
  assert.ok(captionCalls.some(row=>row.action==='stop'));
 });
 await check('private Chat phone setup saves settings and only explicitly starts a bounded owner test',async()=>{
   await fresh();await page.getByRole('button',{name:'My phone in Chat',exact:true}).click();
   const card=page.locator('[data-owner-phone-setup]');await card.getByLabel('Your personal phone number',{exact:true}).waitFor();
   assert.equal(apiCalls.some(c=>['token','prepare_owner_test'].includes(c.action)),false);
   await card.getByLabel('Your personal phone number',{exact:true}).fill('+16025550100');await card.getByRole('button',{name:'Save number',exact:true}).click();
   await card.getByText('Private phone settings saved.',{exact:true}).waitFor();
   await card.getByLabel('Call me with Chat Canvas updates',{exact:true}).check();
   await card.getByRole('button',{name:'Save calling preferences',exact:true}).click();
   await page.waitForFunction(()=>document.querySelector('[data-owner-phone-setup]')?.innerText.includes('Private phone settings saved.'));
   assert.equal(ownerPhone.updates_requested,true);assert.equal(ownerPhone.execution_enabled,false);
   const test=card.getByRole('button',{name:'Test call',exact:true});
   assert.equal(await test.isDisabled(),true);
   await card.getByLabel('This is my phone number. I want one test call (voice charges may apply).',{exact:true}).check();
   await test.evaluate(el=>el.click());assert.equal(apiCalls.some(c=>c.action==='prepare_owner_test'),false);
   // The real Chat page installs the microphone lease before the Twilio SDK.
   // With no lease, the new shortcut must fail closed without requesting a call.
   await test.click();
   await page.locator('.vp3-phone-panel .vp3-phone-notice').getByText('Microphone ownership is unavailable.',{exact:true}).waitFor();
   assert.equal(apiCalls.some(c=>['token','prepare_owner_test'].includes(c.action)),false);
   await closeDrawer();
   await page.evaluate(()=>{
     window.StonefellowVoiceLeaseV122={acquireCapture(){
       let live=true;const streams=new Set();
       return {isCurrent:()=>live,ownStream:s=>{if(!live){s.getTracks().forEach(t=>t.stop());return false;}streams.add(s);return true;},
         release(){if(!live)return;live=false;for(const s of streams)s.getTracks().forEach(t=>t.stop());}};
     }};
   });
   await card.getByLabel('This is my phone number. I want one test call (voice charges may apply).',{exact:true}).check();
   await test.click();
   try{await page.waitForFunction(()=>window.phoneFixture?.connects.length===1);}
   catch(e){console.error('Owner test diagnostic:',JSON.stringify({actions:apiCalls.map(c=>c.action),notice:await page.locator('.vp3-phone-panel .vp3-phone-notice').innerText(),errors}));throw e;}
   assert.equal(apiCalls.filter(c=>c.action==='prepare_owner_test').length,1);
   assert.equal(apiCalls.some(c=>c.action==='prepare_call'),false);
   await page.getByRole('button',{name:'Hang up',exact:true}).click();await closeDrawer();
   ownerPhone.last_test={call_id:outgoingId,state:'completed',received:false,can_confirm:true};
   await card.getByRole('button',{name:'Refresh test status',exact:true}).click();
   await card.getByRole('button',{name:'I received it',exact:true}).click();
   await card.getByText('You confirmed receiving the test call.',{exact:true}).waitFor();
   await page.evaluate(()=>VP3PhoneWorkspaceV1.contextChanged({conversation_id:11,agent_id:2,ready:true}));
   assert.equal(await card.count(),0,'context change removes private form');
 });
 await check('connected profile opens the same Phone drawer and calls only after explicit confirmation',async()=>{
   await fresh();await enable();await closeDrawer();
   await page.evaluate(()=>{
     const map=document.createElement('div');map.id='phone-workspace';document.body.append(map);
     const device={id:'4'.repeat(32),name:'Field phone',status:'approved',agent_chat:{conversation_id:10,agent_id:2},
       communication:{call_permission_saved:true,profile_id:'5'.repeat(32),revision:3,phone_revision:1,phone_masked:'Ending in 0135'}};
     if(!VP3PhoneWorkspaceV1.openConnectedPhone(device))throw Error('Connected phone drawer unavailable');
   });
   const setup=page.locator('[data-connected-phone-call]');
   await setup.getByText('Call Field phone',{exact:true}).waitFor();
   assert.equal(apiCalls.some(c=>c.action==='prepare_connected_call'),false);
   assert.equal(await setup.getByRole('button',{name:'Call connected phone',exact:true}).isDisabled(),true);
   await setup.getByLabel('Place one call to this connected phone',{exact:true}).check();
   await setup.getByRole('button',{name:'Call connected phone',exact:true}).evaluate(el=>el.click());
   assert.equal(apiCalls.some(c=>c.action==='prepare_connected_call'),false);
   await setup.getByRole('button',{name:'Call connected phone',exact:true}).click();
   await page.waitForFunction(()=>window.phoneFixture?.connects.length===1);
   assert.equal(apiCalls.filter(c=>c.action==='prepare_connected_call').length,1);
   assert.equal(apiCalls.some(c=>c.action==='prepare_call'),false);
   await page.getByRole('button',{name:'Hang up',exact:true}).click();
   assert.equal(await setup.getByRole('button',{name:'Call connected phone',exact:true}).isDisabled(),true);
   await setup.getByRole('button',{name:'Close connected phone setup',exact:true}).click();
   assert.equal(await setup.count(),0);
 });
 await check('managed call review is read-only, literal text, source-linked and cleared on Chat switch',async()=>{
   await fresh();
   const id='6'.repeat(32);
   state.calls=[historyCall(id,{transport_kind:'managed_callback',direction:'outbound',connected_phone:true,peer_e164:'',peer_masked:'Ending in 0156',conversation_id:10,browser_control:false})];
   await page.evaluate(()=>VP3PhoneWorkspaceV1.refresh());
   await page.getByRole('tab',{name:'Recent calls',exact:true}).click();
   const row=page.locator('#vp3PhoneSection-calls .vp3-phone-call');
   await row.getByRole('button',{name:'Review conversation',exact:true}).click();
   const review=row.locator('[data-managed-conversation-review]');
   await review.getByText('Conversation intelligence',{exact:true}).waitFor();
   await review.getByText('Confirm timing.',{exact:true}).waitFor();
   assert.ok((await review.innerText()).includes('Fixture report <img src=x onerror=alert(1)>'));
   assert.equal(await review.locator('img,script').count(),0);
   assert.equal(await review.getByRole('link',{name:'Review original finding',exact:true}).count(),1);
   assert.equal(await review.locator('a[href^="https://untrusted.invalid"]').count(),0);
   assert.equal(apiCalls.filter(c=>c.action==='managed_review').length,1);
   assert.equal(apiCalls.some(c=>['prepare_call','managed_dispatch','save_auto_call_policy'].includes(c.action)),false);
   await page.evaluate(()=>VP3PhoneWorkspaceV1.contextChanged({conversation_id:12,agent_id:2,ready:true}));
   assert.equal(await page.locator('[data-managed-conversation-review]').count(),0);
 });
 await check('contact editor and daily call reminders fit desktop and phone widths and preserve private details',async()=>{
  for(const viewport of [{width:1360,height:900},{width:390,height:844},{width:320,height:740}]){
   await fresh('',{viewport});state.contact_details_ready=true;await page.evaluate(()=>VP3PhoneWorkspaceV1.refresh());
   await page.getByRole('tab',{name:'Contacts',exact:true}).click();await page.getByRole('button',{name:'+ Contact',exact:true}).click();
   const editor=page.getByRole('form',{name:'Add phone contact',exact:true});
   await editor.getByLabel('Name',{exact:true}).fill('Ada <img src=x>');await editor.getByLabel('Phone number',{exact:true}).fill('+16025550812');
   await editor.getByLabel('Relationship',{exact:true}).fill('Friend');await editor.getByLabel('Private notes',{exact:true}).fill('Private project note <script>\nDiscuss next steps.');
   await page.evaluate(()=>VP3PhoneWorkspaceV1.refresh());assert.equal(await editor.getByLabel('Private notes',{exact:true}).inputValue(),'Private project note <script>\nDiscuss next steps.');
   const layout=await page.locator('.vp3-phone-panel').evaluate(node=>({width:node.clientWidth,scroll:node.scrollWidth,background:getComputedStyle(node).backgroundColor}));assert.ok(layout.scroll<=layout.width+1);assert.equal(layout.background,'rgb(255, 255, 255)');
   await editor.getByRole('button',{name:'Save contact',exact:true}).click();await page.getByRole('button',{name:'+ Scheduled call',exact:true}).waitFor();
   assert.equal(apiCalls.filter(call=>call.action==='save_phone_contact').length,1);assert.equal(apiCalls.find(call=>call.action==='save_phone_contact').relationship,'Friend');
   await page.getByRole('button',{name:'+ Scheduled call',exact:true}).click();const schedule=page.getByRole('form',{name:'Scheduled call',exact:true});
   await schedule.getByLabel('First call date',{exact:true}).fill('2026-12-03');await schedule.getByLabel('Call time',{exact:true}).fill('17:15');await schedule.getByLabel('Time zone',{exact:true}).fill('UTC');
   await schedule.getByLabel('Repeat',{exact:true}).selectOption('daily');await schedule.getByLabel('Call notes and objectives',{exact:true}).fill('Review progress\nAgree the next step.');
   await page.evaluate(()=>VP3PhoneWorkspaceV1.refresh());assert.equal(await schedule.getByLabel('Repeat',{exact:true}).inputValue(),'daily');
   assert.match(await page.locator('.vp3-phone-panel').innerText(),/Automatic dialing is not enabled/);assert.equal(apiCalls.some(call=>call.action==='save_phone_contact_schedule'),false);
   await schedule.getByLabel('Repeat',{exact:true}).scrollIntoViewIfNeeded();await fs.mkdir(path.join(root,'tests/artifacts'),{recursive:true});await page.screenshot({path:path.join(root,'tests/artifacts/phone-workspace-contact-schedule-'+viewport.width+'.png')});
   assert.equal(await page.locator('.vp3-phone-panel').evaluate(node=>node.scrollWidth<=node.clientWidth+1),true);
   await schedule.getByRole('button',{name:'Save scheduled call',exact:true}).click();await page.getByText('Every day',{exact:true}).waitFor();
   const saved=apiCalls.find(call=>call.action==='save_phone_contact_schedule');assert.equal(saved.time,'17:15');assert.equal(saved.recurrence,'daily');assert.equal(saved.confirmed,true);assert.equal(saved.contact_revision,'b'.repeat(64));
   assert.equal(await page.locator('.vp3-phone-panel img,.vp3-phone-panel script').count(),0);assert.equal(await page.locator('#chatThread').innerText(),'');
   await page.getByRole('button',{name:'Cancel schedule',exact:true}).click();assert.equal(apiCalls.some(call=>call.action==='cancel_phone_contact_schedule'),false);await page.getByRole('button',{name:'Confirm cancellation',exact:true}).click();await page.getByText('No scheduled calls.',{exact:true}).waitFor();
   await page.getByRole('button',{name:'Back to contacts',exact:true}).click();assert.doesNotMatch(await page.locator('.vp3-phone-panel').innerText(),/Private project note/);assert.ok(apiCalls.every(call=>!['token','prepare_call','save_auto_call_policy'].includes(call.action)));
  }
 });
 await check('voicemail settings and trusted greeting capture fit desktop and narrow mobile drawers',async()=>{
  for(const viewport of [{width:1360,height:900},{width:320,height:740}]){
   await fresh('',{viewport});state.voicemail_settings={available:true,number_id:1,revision:0,rings:4,ring_seconds:24,transcribe:false,announce:true,greeting:{present:false,duration_seconds:0,preview_url:''}};
   await page.evaluate(()=>VP3PhoneWorkspaceV1.refresh());await page.getByRole('tab',{name:'Voicemail',exact:true}).click();
   const settings=page.locator('.vp3-phone-voicemail-settings');await settings.getByRole('combobox',{name:'Approximate rings before voicemail',exact:true}).waitFor();
   assert.match(await settings.innerText(),/Rings are approximate/);assert.match(await settings.innerText(),/waiting for the server worker/);
   await page.evaluate(()=>{
    const stats=window.vmCapture={claims:0,releases:0,microphones:0,closed:0,tracks:[],node:null};
    window.StonefellowVoiceLeaseV122={acquireCapture(){stats.claims++;let live=true;return {isCurrent:()=>live,ownStream:()=>live,release(){if(live){live=false;stats.releases++;}}};}};
    window.AudioContext=class{constructor(){this.destination={};this.audioWorklet={addModule:async()=>{}};}resume(){return Promise.resolve();}close(){stats.closed++;return Promise.resolve();}createMediaStreamSource(){return {connect(){},disconnect(){}};}};
    window.AudioWorkletNode=class{constructor(){this.port={onmessage:null};stats.node=this;}connect(){}disconnect(){}};
    Object.defineProperty(navigator,'mediaDevices',{configurable:true,value:{getUserMedia:async()=>{stats.microphones++;const track={readyState:'live',stop(){this.readyState='ended';}};stats.tracks.push(track);return {getTracks:()=>[track]};}}});
   });
   const record=settings.getByRole('button',{name:'Record greeting',exact:true});await record.evaluate(button=>button.click());assert.equal(await page.evaluate(()=>vmCapture.claims),0,'Scripted clicks cannot start the microphone');
   await record.click();await settings.getByRole('button',{name:'Stop recording greeting',exact:true}).waitFor();await page.waitForFunction(()=>window.vmCapture.node?.port.onmessage&&window.vmCapture.microphones===1);
   await page.evaluate(()=>{for(let i=0;i<10;i++)vmCapture.node.port.onmessage({data:new ArrayBuffer(3200)});});
   await settings.getByRole('button',{name:'Stop recording greeting',exact:true}).click();await settings.getByRole('button',{name:'Save greeting',exact:true}).waitFor();
   assert.deepEqual(await page.evaluate(()=>({claims:vmCapture.claims,releases:vmCapture.releases,microphones:vmCapture.microphones,closed:vmCapture.closed,ended:vmCapture.tracks.every(track=>track.readyState==='ended')})),{claims:1,releases:1,microphones:1,closed:1,ended:true});
   assert.ok((await settings.locator('audio').getAttribute('src')).startsWith('blob:'));
   let uploads=0;await page.route('**/api/phone-voicemail-greeting-v1.php',async route=>{
    uploads++;assert.equal(route.request().method(),'POST');assert.match(route.request().headers()['content-type'],/^multipart\/form-data; boundary=/);assert.ok(route.request().postDataBuffer().includes(Buffer.from('RIFF')));
    state.voicemail_settings={...state.voicemail_settings,revision:1,greeting:{present:true,duration_seconds:1,preview_url:'/api/fixture-audio'}};
    return route.fulfill({json:{ok:true,voicemail_settings:state.voicemail_settings}});
   });
   assert.equal(uploads,0);await settings.getByRole('button',{name:'Save greeting',exact:true}).click();await settings.getByText('Your recorded greeting is active.',{exact:true}).waitFor();assert.equal(uploads,1);
   await settings.getByRole('button',{name:'Record greeting',exact:true}).click();await settings.getByRole('button',{name:'Cancel greeting',exact:true}).click();
   assert.equal(await page.evaluate(()=>vmCapture.claims===vmCapture.releases&&vmCapture.tracks.every(track=>track.readyState==='ended')),true,'Cancel releases the current recording');
   const bounds=await page.locator('.vp3-phone-panel').evaluate(node=>({width:node.clientWidth,scroll:node.scrollWidth}));assert.ok(bounds.scroll<=bounds.width+1,'Voicemail controls fit '+viewport.width+'px');
   const buttons=await settings.locator('button').evaluateAll(nodes=>nodes.map(node=>({width:node.getBoundingClientRect().width,height:node.getBoundingClientRect().height})));assert.ok(buttons.every(button=>button.height>=44&&button.width<=viewport.width),'Greeting actions retain mobile touch targets');
   await settings.scrollIntoViewIfNeeded();await fs.mkdir(path.join(root,'tests/artifacts'),{recursive:true});await page.screenshot({path:path.join(root,'tests/artifacts/phone-workspace-voicemail-'+(viewport.width===320?'mobile':'desktop')+'.png')});
   assert.equal(apiCalls.some(call=>['prepare_call','save_recording_policy','transcribe_voicemail'].includes(call.action)),false,'Greeting capture cannot create calls or paid transcripts');
  }
 });
 if(actualState)await check('the unmodified PHP projection renders both voicemail namespaces and saved processing policy',async()=>{
  assert.ok(actualUi?.button&&actualUi?.config,'The actual PHP header and runtime configuration are mandatory with the real projection');
  assert.ok(actualState.numbers.length>0);assert.ok(actualState.voicemails.some(row=>/^job-/.test(row.id)));assert.ok(actualState.voicemails.some(row=>/^legacy-/.test(row.id)));
  await page.close();page=await browser.newPage({viewport:{width:1360,height:900}});page.on('pageerror',e=>errors.push(e.message));
  const origin=new URL(actualUi.config.endpoint).origin;
  await page.route(origin+'/**',async route=>{
   const url=new URL(route.request().url());
   if(url.pathname==='/phone-workspace-v1.js')return route.fulfill({contentType:'text/javascript',body:source});
   if(url.pathname==='/phone-workspace-v1.css')return route.fulfill({contentType:'text/css',body:css});
   if(shellStyles.has(url.pathname))return route.fulfill({contentType:'text/css',body:shellStyles.get(url.pathname)});
   if(url.pathname===new URL(actualUi.config.endpoint).pathname){assert.equal(route.request().method(),'GET');return route.fulfill({json:{ok:true,state:actualState}});}
   return route.fulfill({contentType:'text/html',body:fixtureHead+headerShell(actualUi.button)+'<section id="chatThread" class="chat-thread"></section><script>window.VP3_PHONE_WORKSPACE_V1='+JSON.stringify(actualUi.config)+';</script><script src="/phone-workspace-v1.js"></script>'});
  });
  await page.goto(origin+'/phone-fixture');await page.getByRole('button',{name:'Phone',exact:true}).click();await page.getByLabel('Selected phone number',{exact:true}).waitFor();
  const actualIcon=page.locator('[data-phone-workspace-toggle] svg');assert.equal(await actualIcon.getAttribute('width'),'18');assert.equal(await actualIcon.getAttribute('height'),'18');assert.equal(await page.locator('[data-phone-workspace-toggle]').getAttribute('aria-controls'),await page.locator('.vp3-phone-workspace').getAttribute('id'));
  assert.equal(await page.getByLabel('Selected phone number',{exact:true}).inputValue(),String(actualState.selected_number_id));
  assert.ok(actualState.calls.some(row=>/^legacy-/.test(row.public_id||row.call_id)),'The actual ledger includes historical calls');await page.getByRole('tab',{name:'Recent calls',exact:true}).click();assert.equal(await page.locator('.vp3-phone-section:not([hidden]) .vp3-phone-row').count(),actualState.calls.length);await page.getByRole('tab',{name:'Connections',exact:true}).click();
  assert.equal(await page.getByLabel('Enable Cloud transcription and analysis for recorded calls',{exact:true}).isChecked(),actualState.post_call_policy.enabled);
  for(const voicemail of actualState.voicemails)await page.evaluate(row=>{const host=document.createElement('article');host.className='message assistant';host.innerHTML='<div class="message-avatar">S</div><div class="message-body"><div class="message-role">Agent</div><div class="message-text">Saved voicemail.</div></div>';document.getElementById('chatThread').append(host);window.VP3PhoneWorkspaceV1.bindMessage(host,{kind:'voicemail',call_id:row.call_id,voicemail_id:row.id});},voicemail);
  await page.getByRole('tab',{name:'Voicemail',exact:true}).click();
  assert.equal(await page.locator('.vp3-phone-section:not([hidden]) .vp3-phone-voicemail').count(),actualState.voicemails.length);
  assert.equal(await page.locator('#chatThread .vp3-phone-voicemail').count(),actualState.voicemails.length);
  const expectedAudio=actualState.voicemails.filter(row=>{try{return new URL(row.audio_url,origin).origin===origin&&Boolean(row.audio_url);}catch(_){return false;}});
  assert.equal(await page.locator('#chatThread .vp3-phone-voicemail audio').count(),expectedAudio.length,'Actual authenticated audio URLs are preserved as usable same-origin sources');
  assert.deepEqual((await page.locator('#chatThread .vp3-phone-voicemail audio').evaluateAll(nodes=>nodes.map(n=>n.src))).sort(),expectedAudio.map(row=>new URL(row.audio_url,origin).href).sort());
  for(const row of actualState.voicemails){if(row.transcript_url)assert.ok((await page.locator('#chatThread .vp3-phone-report').evaluateAll(nodes=>nodes.map(n=>n.href))).includes(row.transcript_url));}
  for(const row of actualState.voicemails){if(row.transcript)assert.ok((await page.locator('#chatThread').innerText()).includes(row.transcript));}
  assert.equal(await page.locator('.vp3-phone-voicemail a[href^="javascript:"],.vp3-phone-voicemail audio[src^="javascript:"]').count(),0);
  await page.screenshot({path:path.join(root,'tests/artifacts/phone-workspace-real-projection.png')});
 });
 assert.deepEqual(errors,[]);succeeded=true;console.log('PHONE_WORKSPACE_BROWSER=PASS ('+checks+' cases)');
}finally{if(!succeeded&&page&&!page.isClosed()){await fs.mkdir(path.join(root,'tests/artifacts'),{recursive:true});await page.screenshot({path:path.join(root,'tests/artifacts/phone-workspace-failure.png'),fullPage:true,timeout:5000}).catch(()=>{});}await browser.close();await new Promise(resolve=>server.close(resolve));}
