import fs from 'node:fs';
import vm from 'node:vm';
import assert from 'node:assert/strict';
import {Document,Element} from './fixtures/profile-attention-pa4b.mjs';

// Chat's canvas-media marker uses this standard DOM insertion primitive.
Element.prototype.after=function(node){const parent=this.parentElement;if(parent)parent.insertBefore(node,parent.children[parent.children.indexOf(this)+1]||null);};

// Execute the production send owner, Chat message renderer and local status
// presenter. DOM, transport, clocks and draft storage are fixture boundaries.
const source=fs.readFileSync('chat.js','utf8');
function section(start,end){const a=source.indexOf(start),b=source.indexOf(end,a);assert(a>=0&&b>a);return source.slice(a,b);}
function chatFunction(name){
 const start=source.search(new RegExp('^  (?:async )?function '+name+'\\(','m')),end=source.indexOf('\n  }',start);
 assert(start>=0&&end>start,'Production Chat helper exists: '+name);return source.slice(start,end+4)+'\n';
}
const presenter=section('  function recoveryStatus(', '  function leaveChat(');
const helpers=['bindStoredMessagePa3','initiativeIdentityMatchesV1','validInitiativeReplyV1','initiativeReplyForSendV1',
 'showParkedReplyAfterSend','bindFoodMonsterResultIcon','bindGmailResultTime','bindPrivateGmailDisplayV1','applyInitiativeFollowthroughV1',
 'bindCoordinationReplyV1','bindInitiativeActionV1','bindMeetingActionV1','messageElement','addMessage','addTyping',
 'catchUpSourceLinks','publicResearchSourceLinks','attachAudioTracking'].map(chatFunction).join('');
const sendOwner=['gmailReviewActionLabel','activeImageConflictV1','recoverBlockedImageDraftV1','cancelEmailInstructionTurnV1','sendMessage'].map(chatFunction).join('');
const initiativeIdentity=section('  const initiativePage =', '  const thread =');
const initiativeState=section('  let initiativeReplyTarget =', '  const recoveryKey =');
function fixture(){
 const document=new Document(),drafts=new Map(),timers=new Map();let sequence=0,calls=0,reply;
 document.body.innerHTML='<section id="chatThread" data-view="chat"></section><div id="chatWelcome"></div><form id="chatForm"><div id="chatComposerShell"><textarea id="chatInput"></textarea><button id="sendChatButton"></button></div></form>';
 const thread=document.getElementById('chatThread');
 const messageElements=()=>thread.querySelectorAll('article.message.user,article.message.assistant').filter(element=>!element.classList.contains('typing'));
 const ctx={console,AbortController,DOMException,Event,URLSearchParams,location:new URL('https://app.test/chat.php'),CustomEvent:class{constructor(type,init={}){this.type=type;this.detail=init.detail;}},
  document,window:{dispatchEvent(){},addEventListener(){},setTimeout(){}},navigator:{onLine:true},input:document.getElementById('chatInput'),
  send:document.getElementById('sendChatButton'),form:document.getElementById('chatForm'),welcome:document.getElementById('chatWelcome'),
  cfg:{userId:1,agentId:0,workingAttentionBinding:'fixture-session',csrf:'fixture',endpoint:'/api/chat.php'},thread,
  busy:false,contextReady:true,initialRestorePending:false,restoringConversation:0,conversationId:42,chatRevision:0,pendingSend:null,emailInstructionTurn:null,lastLoadedMessageId:0,
  composerDraftRevision:0,composerDraftOrigin:null,historyAuthority:0,historyParticipant:0,
  voiceMode:false,voiceSpeaking:false,
  setTimeout(fn,ms){const id=++sequence;timers.set(id,{fn,ms});return id;},clearTimeout:id=>timers.delete(id),
  keepDraft(text,id=42){if(text)drafts.set(id,text);else drafts.delete(id);},autoSize(){},showStop(){},setChatCanvasView(){},
  rememberConversation(){},refreshHistory:async()=>{},
  api:async(payload,signal)=>{calls++;return reply(payload,signal);},
 };
 ctx.window.STONEFELLOW_CHAT=ctx.cfg;
 vm.createContext(ctx);vm.runInContext(initiativeIdentity+initiativeState+presenter+helpers+chatFunction('composerDraftValue')+sendOwner+'\nglobalThis.submit=sendMessage;',ctx);
 return {ctx,document,drafts,timers,get messageElements(){return messageElements();},
  get messages(){return messageElements().map(element=>({role:element.classList.contains('user')?'user':'assistant',text:element.querySelector('.message-text').textContent}));},
  get notice(){return thread.querySelector('[data-chat-turn-notice]');},get calls(){return calls;},reply:fn=>{reply=fn;}};
}
let checks=0;
for(const kind of ['server','network','timeout']){
 const f=fixture();
 f.reply(async()=>{
  if(kind==='server')throw Object.assign(new Error('Provider temporarily unavailable.'),{status:503});
  if(kind==='network')throw new TypeError('Failed to fetch');
  return new Promise(()=>{});
 });
 f.ctx.input.value='Retain this question';
 const pending=f.ctx.submit(f.ctx.input.value);
 if(kind==='timeout'){
  assert.equal(f.ctx.busy,true);
  checks++;
  [...f.timers.values()].find(timer=>timer.ms===120000).fn();
 }
 await pending;
 assert.equal(f.ctx.busy,false);assert.equal(f.ctx.pendingSend,null);
 assert.equal(f.ctx.send.disabled,false);assert.equal(f.ctx.input.disabled,false);
 assert.equal(f.ctx.input.value,'Retain this question');assert.equal(f.drafts.get(42),'Retain this question');
 const notice=f.notice,status=notice.querySelector('.message-text');
 assert.equal(notice.isConnected,true);assert.equal(status.getAttribute('role'),'status');
 assert.equal(notice.classList.contains('chat-turn-notice'),true);assert.equal(notice.classList.contains('assistant'),false);
 assert.equal(notice.dataset.chatMessageId,undefined,'A local status is not a saved assistant message');
 assert.equal(notice.querySelectorAll('button,a').length,0,'No history or dismiss controls are added');
 assert.equal(f.document.getElementById('chatRecoveryStatus'),null,'Transport failure is presented in Chat');
 assert.match(status.textContent,kind==='server'?/Provider temporarily unavailable/:kind==='timeout'?/timed out/:/Could not connect to Chat/);
 assert.doesNotMatch(status.textContent,/Check history|before listening|before sending/);
 assert.equal(f.calls,1,'A failure does not automatically retry the request');
 checks+=16;
 f.reply(async()=>({ok:true,conversation_id:42,user_message_id:21,assistant_message_id:22,answer:'A fresh answer.'}));
 f.ctx.input.value='A new question';await f.ctx.submit(f.ctx.input.value);
 assert.equal(f.calls,2);assert.equal(f.ctx.busy,false);assert.equal(f.ctx.input.value,'');
 assert.equal(notice.isConnected,false);assert.equal(f.notice,null);assert.equal(f.drafts.has(42),false);
 assert.deepEqual(f.messages.at(-1),{role:'assistant',text:'A fresh answer.'});checks+=7;
 const [savedUser,savedReply]=f.messageElements.slice(-2);
 assert.equal(savedUser.dataset.chatMessageId,'21');assert.equal(savedUser.dataset.chatMessageRole,'user');
 assert.equal(savedReply.dataset.chatMessageId,'22');assert.equal(savedReply.dataset.chatMessageRole,'assistant');
 assert.equal(savedUser.dataset.chatConversationId,'42');assert.equal(savedReply.dataset.chatConversationId,'42');checks+=6;
 assert.equal(f.ctx.lastLoadedMessageId,0,'A direct reply cannot skip unseen posts by advancing the history sync cursor');checks++;
}
{
 const f=fixture();
 const command='Auto reply to Gmail review item '+'a'.repeat(64);
 f.reply(async payload=>{
  assert.equal(payload.message,command,'The API receives the exact scoped Gmail action');
  throw new TypeError('Failed to fetch');
 });
 await f.ctx.submit(command);
 assert.deepEqual(f.messages,[{role:'user',text:'Auto reply to selected Gmail message'}]);
 assert.equal(f.ctx.input.value,'');
 assert.equal(f.drafts.has(42),false);
 assert.equal(f.calls,1,'A failed action does not silently retry an automatic send');
 checks+=5;
}
// A late reply must not modify another conversation, clear its draft, or
// replace the error/status of a newer request.
{
 const f=fixture();let release;f.reply(()=>new Promise(resolve=>{release=resolve;}));
 const old=f.ctx.submit('Old request');await Promise.resolve();
 f.ctx.chatRevision++;f.ctx.pendingSend=null;f.ctx.busy=false;f.ctx.conversationId=43;f.ctx.input.value='Current draft';
 f.ctx.keepDraft('Current draft',43);f.ctx.showChatTurnNotice('Current Chat status.');const currentNotice=f.notice;
 release({conversation_id:42,answer:'Stale answer.'});await old;
 assert.equal(f.ctx.conversationId,43);assert.equal(f.ctx.input.value,'Current draft');
 assert.equal(f.messages.some(message=>message.text==='Stale answer.'),false);
 assert.equal(f.drafts.get(43),'Current draft');assert.equal(f.notice,currentNotice);
 assert.equal(currentNotice.querySelector('.message-text').textContent,'Current Chat status.');checks+=6;
}
function imageConflictResponse(overrides={}) {
 return {ok:true,conversation_id:42,agent_id:0,user_message_id:31,assistant_message_id:32,
  answer:'An earlier image request is queued. Manage that request, then send your prompt again.',
  canvas_media:{asset_ids:[],generation_ids:[],image_conflict:{contract:'vp3.image-conflict.v1',
   generation_id:'11111111-2222-4333-8444-555555555555',job_id:19,status:'queued',origin:'chat',
   agent_id:7,conversation_id:91,user_message_id:28,provider_started:false,
   manage_url:'/agent-workflows.php?id=19',source_url:'/chat.php?agent=7&conversation_id=91&message_id=28'}},
  tool_receipt:{contract:'vp3.tool-receipt.v1',capability:'images.generate',operation:'queue',status:'blocked',
   reason:'active_request',record_id:0,count:0},...overrides};
}
// This is the production send owner with a structured, cross-Chat blocker. The
// image module's real consume/selection behavior is covered by the browser gate.
{
 const f=fixture(),prompt='Generate 2 images: Keep this exact composition.\nUse muted greens and warm paper.',
  reference={assetId:17,conversationId:42,selectionId:'selected-original'};
 f.ctx.window.VP3ImageComposer={requestContext:()=>reference,consume(){}};
 f.reply(async payload=>{assert.equal(payload.image_reference_asset_id,17);return imageConflictResponse();});
 await f.ctx.submit(prompt);
 assert.equal(f.ctx.input.value,prompt);assert.equal(f.drafts.get(42),prompt);
 assert.equal(f.ctx.composerDraftOrigin.text,prompt);assert.equal(f.calls,1);
 assert.match(f.document.getElementById('chatRecoveryStatus').textContent,/prompt is kept/);
 assert.equal(f.ctx.window.VP3ImageComposer.requestContext(),reference);checks+=7;
}
{
 const f=fixture(),prompt='Create my first image in this Chat.',events=[];
 f.ctx.conversationId=0;f.ctx.window.dispatchEvent=event=>events.push({event,cid:f.ctx.conversationId});
 const response=imageConflictResponse({conversation_id:73});
 Object.assign(response.canvas_media.image_conflict,{origin:'media_library',agent_id:0,conversation_id:0,user_message_id:null});
 f.reply(async payload=>{assert.equal(payload.conversation_id,0);return response;});
 await f.ctx.submit(prompt);
 assert.equal(f.ctx.conversationId,73);assert.equal(f.ctx.input.value,prompt);
 assert.equal(f.drafts.has(0),false);assert.equal(f.drafts.get(73),prompt);
 const bound=events.filter(item=>item.event.type==='vp3:chat-conversation-bound');assert.equal(bound.length,1);
 assert.equal(bound[0].cid,73);assert.equal(bound[0].event.detail.previous_conversation_id,0);
 assert.equal(bound[0].event.detail.conversation_id,73);checks+=9;
}
for(const newer of ['My newer draft','']){
 const f=fixture();let release;f.reply(()=>new Promise(resolve=>{release=resolve;}));
 const pending=f.ctx.submit('Blocked original prompt');await Promise.resolve();
 // The browser suite dispatches real input events, including replacement then
 // clearing while the response is held. Here the revision is the DOM boundary.
 f.ctx.composerDraftRevision+=2;f.ctx.input.value=newer;
 release(imageConflictResponse());await pending;
 assert.equal(f.ctx.input.value,newer);assert.equal(f.drafts.get(42),newer||undefined);
 assert.equal(f.calls,1);checks+=3;
}
for(const changed of ['removed','replaced']){
 const f=fixture();let release,reference={assetId:17,conversationId:42,selectionId:'original'};
 f.ctx.window.VP3ImageComposer={requestContext:()=>reference,consume(){}};
 f.reply(()=>new Promise(resolve=>{release=resolve;}));const pending=f.ctx.submit('Original image prompt');await Promise.resolve();
 reference=changed==='removed'?null:{assetId:18,conversationId:42,selectionId:'new-selection'};
 release(imageConflictResponse());await pending;
 assert.equal(f.ctx.input.value,'');assert.equal(f.drafts.has(42),false);assert.equal(f.calls,1);checks+=3;
}
for(const change of ['owner','agent','session','csrf','endpoint','participant','authority','response_agent','response_conversation']){
 const f=fixture();let release;f.reply(()=>new Promise(resolve=>{release=resolve;}));
 const pending=f.ctx.submit('Private original prompt');await Promise.resolve();
 const response=imageConflictResponse();
 if(change==='owner')f.ctx.cfg.userId=2;
 if(change==='agent')f.ctx.cfg.agentId=7;
 if(change==='session')f.ctx.cfg.workingAttentionBinding='changed-session';
 if(change==='csrf')f.ctx.cfg.csrf='changed-csrf';
 if(change==='endpoint')f.ctx.cfg.endpoint='/different-chat.php';
 if(change==='participant')f.ctx.window.VP3ParticipantContinuity={selectedContext:()=>({participant_id:22})};
 if(change==='authority')f.ctx.historyAuthority++;
 if(change==='response_agent')response.agent_id=7;
 if(change==='response_conversation')response.conversation_id=91;
 f.ctx.input.value='Destination draft';f.ctx.keepDraft('Destination draft',43);
 release(response);await pending;
 assert.equal(f.ctx.input.value,'Destination draft',change);assert.equal(f.drafts.get(43),'Destination draft',change);
 assert.equal(f.drafts.get(42),'Private original prompt',change);assert.equal(f.ctx.conversationId,42,change);
 assert.equal(f.messages.some(message=>message.role==='assistant'),false,change);
 assert.equal(f.ctx.thread.querySelector('.typing'),null,change);checks+=6;
}
for(const kind of ['status_receipt','different_reason','missing_job','invalid_contract','generation_present']){
 const f=fixture(),response=imageConflictResponse();
 if(kind==='status_receipt')response.tool_receipt.operation='status';
 if(kind==='different_reason')response.tool_receipt.reason='provider_unavailable';
 if(kind==='missing_job')delete response.canvas_media.image_conflict.job_id;
 if(kind==='invalid_contract')response.canvas_media.image_conflict.contract='unknown';
 if(kind==='generation_present')response.canvas_media.generation_ids=[response.canvas_media.image_conflict.generation_id];
 f.reply(async()=>response);await f.ctx.submit('This is not a verified blocked creation.');
 assert.equal(f.ctx.input.value,'',kind);assert.equal(f.drafts.has(42),false,kind);assert.equal(f.calls,1,kind);checks+=3;
}
{
 const f=fixture();f.ctx.window.STONEFELLOW_CHAT_CONTINUITY={pendingVoiceSubmit:()=>true};
 f.reply(async()=>imageConflictResponse());await f.ctx.submit('Submitted automatic voice utterance');
 assert.equal(f.ctx.input.value,'');assert.equal(f.drafts.has(42),false);
 assert.equal(f.document.getElementById('chatRecoveryStatus'),null);assert.equal(f.calls,1);checks+=4;
}
// Selected email instructions use the composer contract, never a second model
// turn. The main Chat owner preserves rejected text and isolates late results.
for(const outcome of ['success','rejected','changed','network']){
 const f=fixture();let handled=0;const match={kind:'revise',selection:{draft_id:'a'.repeat(32),edit_revision:'b'.repeat(64)}};
 f.ctx.input.value='Make this draft shorter';
 f.ctx.window.VP3EmailComposerV1={
  matchInstruction:(text,scope)=>{assert.equal(text,f.ctx.input.value);assert.equal(scope.owner_key,'1');assert.equal(scope.conversation_id,42);checks+=3;return match;},
  handleChatInstruction:async(text,scope,captured)=>{
   handled++;assert.equal(captured,match);assert.equal(f.ctx.busy,true);assert.equal(f.ctx.input.disabled,true);checks+=3;
   if(outcome==='network')throw new TypeError('Lost response');
   return outcome==='changed'?{handled:false}:{handled:true,ok:outcome==='success',message:outcome==='success'?'The draft revision is queued.':'Your draft changed. Your instruction is kept.'};
  }
 };
 await f.ctx.submit(f.ctx.input.value);
 assert.equal(handled,1);assert.equal(f.calls,0);assert.equal(f.ctx.busy,false);assert.equal(f.ctx.input.disabled,false);
 assert.equal(f.ctx.send.disabled,false);assert.equal(f.ctx.emailInstructionTurn,null);
 assert.equal(f.ctx.input.value,outcome==='success'?'':'Make this draft shorter');
 assert.equal(f.drafts.get(42),outcome==='success'?undefined:'Make this draft shorter');
 assert.equal(f.messages.length,0,'A local email command is not fabricated as saved Chat history');
 assert.match(f.notice.textContent,outcome==='success'?/revision is queued/:/kept/);checks+=10;
}
{
 const f=fixture();let release,handled=0;const selected={kind:'save'};
 f.ctx.input.value='Save this draft';
 f.ctx.window.VP3EmailComposerV1={matchInstruction:()=>selected,handleChatInstruction:()=>{handled++;return new Promise(resolve=>{release=resolve;});}};
 const first=f.ctx.submit(f.ctx.input.value);await Promise.resolve();
 await f.ctx.submit('Discard this draft');assert.equal(handled,1,'An in-flight email instruction cannot run twice');
 f.ctx.cancelEmailInstructionTurnV1();f.ctx.chatRevision++;f.ctx.conversationId=43;
 f.ctx.input.value='New Chat draft';f.ctx.keepDraft(f.ctx.input.value,43);f.ctx.showChatTurnNotice('Current status');
 f.ctx.busy=true;f.ctx.input.disabled=true;f.ctx.send.disabled=true; // A newer turn owns these controls.
 release({handled:true,ok:true,message:'The old draft is saved.'});await first;
 assert.equal(f.ctx.input.value,'New Chat draft');assert.equal(f.drafts.get(43),'New Chat draft');
 assert.equal(f.notice.querySelector('.message-text').textContent,'Current status');assert.equal(f.ctx.busy,true);assert.equal(f.ctx.input.disabled,true);
 assert.equal(f.calls,0);checks+=7;
}
{
 const f=fixture();let handled=0;
 f.ctx.input.value='Save this draft';f.ctx.navigator.onLine=false;
 f.ctx.window.VP3EmailComposerV1={matchInstruction:()=>({kind:'save'}),handleChatInstruction:async()=>{handled++;return {handled:true,ok:true};}};
 await f.ctx.submit(f.ctx.input.value);
 assert.equal(handled,0);assert.equal(f.calls,0);assert.equal(f.drafts.get(42),'Save this draft');
 assert.equal(f.ctx.busy,false);assert.match(f.notice.textContent,/offline/);checks+=5;
}
for(const unselected of ['ordinary','participant']){
 const f=fixture();let handled=0,matched=0;
 f.ctx.input.value='What is next?';
 f.ctx.window.VP3EmailComposerV1={matchInstruction:()=>{matched++;return null;},handleChatInstruction:async()=>{handled++;}};
 if(unselected==='participant')f.ctx.window.VP3ParticipantContinuity={selectedContext:()=>({participant_id:22})};
 f.reply(async()=>({ok:true,conversation_id:42,user_message_id:51,assistant_message_id:52,answer:'A normal Chat answer.'}));
 await f.ctx.submit(f.ctx.input.value);
 assert.equal(handled,0);assert.equal(matched,unselected==='participant'?0:1);assert.equal(f.calls,1);checks+=3;
}
for(const voiceFailure of ['offline','rejected','network']){
 const f=fixture();let handled=0;
 f.ctx.window.STONEFELLOW_CHAT_CONTINUITY={pendingVoiceSubmit:()=>true};
 f.ctx.window.VP3EmailComposerV1={matchInstruction:()=>({kind:'save'}),handleChatInstruction:async()=>{
  handled++;if(voiceFailure==='network')throw new TypeError('Lost response');
  return {handled:true,ok:false,message:'Your instruction is kept.'};
 }};
 if(voiceFailure==='offline')f.ctx.navigator.onLine=false;
 await f.ctx.submit('Save this draft');
 assert.equal(f.ctx.input.value,'','A failed spoken command must not become a typed Chat draft');
 assert.equal(f.drafts.has(42),false);assert.equal(f.calls,0);
 assert.equal(handled,voiceFailure==='offline'?0:1);
 assert.match(f.notice.querySelector('.message-text').textContent,/repeat the instruction|repeat your instruction/);
 assert.doesNotMatch(f.notice.querySelector('.message-text').textContent,/instruction is kept/);checks+=6;
}
{
 const f=fixture();let handled=0;f.ctx.input.value='Make this email shorter';
 vm.runInContext('initiativeReplyTarget = {message_id:123};',f.ctx);
 f.ctx.window.VP3EmailComposerV1={matchInstruction:()=>({kind:'revise'}),handleChatInstruction:async()=>{handled++;}};
 await f.ctx.submit(f.ctx.input.value);
 assert.equal(handled,0);assert.equal(f.calls,0);assert.equal(f.ctx.input.value,'Make this email shorter');
 assert.match(f.notice.querySelector('.message-text').textContent,/Agent reply is selected/);checks+=4;
}
{
 const f=fixture();f.ctx.input.value='A separate typed question';
 f.ctx.window.VP3EmailComposerV1={matchInstruction:()=>({kind:'queue'}),handleChatInstruction:async()=>({handled:true,ok:true,message:'Opened the email queue.'})};
 await f.ctx.submit('Open email drafts');
 assert.equal(f.ctx.input.value,'A separate typed question');assert.equal(f.drafts.get(42),'A separate typed question');
 assert.equal(f.calls,0);checks+=3;
}
for(const viewKind of ['queue','sent']){
 const f=fixture();f.ctx.conversationId=0;f.ctx.input.value=viewKind==='sent'?'Show my sent emails':'Show my email drafts';let creates=0,handled=0;
 f.ctx.initializingConversationPa3=null;f.ctx.window.clearTimeout=()=>{};
 vm.runInContext(chatFunction('ensureConversationPa3'),f.ctx);
 f.reply(async payload=>{assert.equal(payload.action,'new');creates++;return {ok:true,conversation_id:73};});
 f.ctx.window.VP3EmailComposerV1={matchInstruction:()=>({kind:viewKind}),handleChatInstruction:async(text,scope)=>{
  handled++;assert.equal(scope.conversation_id,73);assert.equal(f.ctx.busy,true);checks+=2;
  return {handled:true,ok:true,message:'Opened the Gmail view.'};
 }};
 const pending=f.ctx.submit(f.ctx.input.value);
 await f.ctx.submit(f.ctx.input.value);await pending;
 assert.equal(creates,1);assert.equal(handled,1);assert.equal(f.calls,1,'Only the private Chat creation reaches the Chat API');
 assert.equal(f.ctx.conversationId,73);assert.equal(f.ctx.input.value,'');assert.equal(f.ctx.busy,false);
 assert.equal(f.ctx.emailInstructionTurn,null);checks+=7;
}

// The queue's cross-Chat handoff may navigate only within the live owner's
// private agent. A late identity change cannot validate the destination.
const emailNavigation=section('    openEmailReviewConversation:async target => {','    openConversation:async');
for(const boundary of ['valid','owner','agent','participant','busy','late_owner','load_failure','late_load_failure']){
 const f=fixture();let loads=0;
 f.ctx.input.value='Original Chat draft';f.ctx.thread.dataset.view='email';
 f.ctx.setChatCanvasView=view=>{f.ctx.thread.dataset.view=view;};
 f.ctx.loadConversation=async id=>{
  loads++;
  if(boundary.endsWith('load_failure')){
   f.ctx.chatRevision++;
   if(boundary==='late_load_failure'){f.ctx.conversationId=99;f.ctx.input.value='New destination draft';}
   throw new Error('History load timed out');
  }
  f.ctx.conversationId=id;if(boundary==='late_owner')f.ctx.cfg.userId=2;return true;
 };
 vm.runInContext('globalThis.navigateEmail = '+emailNavigation.replace(/^\s*openEmailReviewConversation:/,'').trim().replace(/,$/,'')+';',f.ctx);
 const target={owner_key:'1',agent_id:0,conversation_id:43,draft_id:'a'.repeat(32)};
 if(boundary==='owner')target.owner_key='2';
 if(boundary==='agent')target.agent_id=7;
 if(boundary==='participant')f.ctx.window.VP3ParticipantContinuity={selectedContext:()=>({participant_id:22})};
 if(boundary==='busy')f.ctx.busy=true;
 assert.equal(await f.ctx.navigateEmail(target),boundary==='valid');
 assert.equal(loads,['valid','late_owner','load_failure','late_load_failure'].includes(boundary)?1:0);checks+=2;
 if(boundary==='load_failure'){
  assert.equal(f.ctx.thread.dataset.view,'chat');assert.equal(f.ctx.input.value,'Original Chat draft');
  assert.match(f.notice.querySelector('.message-text').textContent,/current Chat and typed draft are kept/);checks+=3;
 }
 if(boundary==='late_load_failure'){
  assert.equal(f.ctx.thread.dataset.view,'email');assert.equal(f.ctx.input.value,'New destination draft');assert.equal(f.notice,null);checks+=3;
 }
}
console.log(`CHAT_SEND_RECOVERY=PASS (${checks} assertions; actual send owner; transport mocked)`);

// Run the production URL handoff cleanup, preserving unrelated agent/query state.
const remember=section('  function rememberConversation()', '  function initialConversation()');
for(const ready of [false,true]){let replaced='';const stored=new Map();const ctx={URL,Date,Number,contextReady:ready,conversationId:73,selectionKey:'active',location:{href:'https://example.test/sub/chat.php?conversation_id=73&agent=5&view=chat#chat'},history:{nodeType:1},window:{history:{replaceState:(_a,_b,url)=>replaced=url}},profileAnalyticsUrlMatchesV1:()=>false,marketingFollowthroughUrlMatchesV1:()=>false,localStorage:{setItem:(k,v)=>stored.set(k,v)}};vm.createContext(ctx);vm.runInContext(remember+';rememberConversation();',ctx);assert.equal(JSON.parse(stored.get('active')).id,73);assert.equal(replaced,ready?'/sub/chat.php?agent=5&view=chat#chat':'');}
console.log('CHAT_HANDOFF_CLEANUP=PASS (2 cases)');
