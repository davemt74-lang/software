import fs from 'node:fs';
import vm from 'node:vm';
import assert from 'node:assert/strict';
import {Document} from './fixtures/profile-attention-pa4b.mjs';

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
 'showParkedReplyAfterSend','bindFoodMonsterResultIcon','bindGmailResultTime','applyInitiativeFollowthroughV1',
 'bindCoordinationReplyV1','bindInitiativeActionV1','bindMeetingActionV1','messageElement','addMessage','addTyping',
 'catchUpSourceLinks','publicResearchSourceLinks','attachAudioTracking'].map(chatFunction).join('');
const sendOwner=['gmailReviewActionLabel','sendMessage'].map(chatFunction).join('');
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
  cfg:{agentId:0},thread,
  busy:false,contextReady:true,initialRestorePending:false,restoringConversation:0,conversationId:42,chatRevision:0,pendingSend:null,lastLoadedMessageId:0,
  voiceMode:false,voiceSpeaking:false,
  setTimeout(fn,ms){const id=++sequence;timers.set(id,{fn,ms});return id;},clearTimeout:id=>timers.delete(id),
  keepDraft(text,id=42){if(text)drafts.set(id,text);else drafts.delete(id);},autoSize(){},showStop(){},setChatCanvasView(){},
  rememberConversation(){},refreshHistory:async()=>{},
  api:async(payload,signal)=>{calls++;return reply(payload,signal);},
 };
 vm.createContext(ctx);vm.runInContext(initiativeIdentity+initiativeState+presenter+helpers+sendOwner+'\nglobalThis.submit=sendMessage;',ctx);
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
console.log(`CHAT_SEND_RECOVERY=PASS (${checks} assertions; actual send owner; transport mocked)`);

// Run the production URL handoff cleanup, preserving unrelated agent/query state.
const remember=section('  function rememberConversation()', '  function initialConversation()');
for(const ready of [false,true]){let replaced='';const stored=new Map();const ctx={URL,Date,Number,contextReady:ready,conversationId:73,selectionKey:'active',location:{href:'https://example.test/sub/chat.php?conversation_id=73&agent=5&view=chat#chat'},history:{nodeType:1},window:{history:{replaceState:(_a,_b,url)=>replaced=url}},profileAnalyticsUrlMatchesV1:()=>false,marketingPublicationUrlMatchesV1:()=>false,localStorage:{setItem:(k,v)=>stored.set(k,v)}};vm.createContext(ctx);vm.runInContext(remember+';rememberConversation();',ctx);assert.equal(JSON.parse(stored.get('active')).id,73);assert.equal(replaced,ready?'/sub/chat.php?agent=5&view=chat#chat':'');}
console.log('CHAT_HANDOFF_CLEANUP=PASS (2 cases)');
