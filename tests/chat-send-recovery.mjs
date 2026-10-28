import fs from 'node:fs';
import vm from 'node:vm';
import assert from 'node:assert/strict';

// Execute the production send owner and inline error presenter. Transport and
// unrelated media rendering are fixtures; request ownership is unchanged.
const source=fs.readFileSync('chat.js','utf8');
function section(start,end){const a=source.indexOf(start),b=source.indexOf(end,a);assert(a>=0&&b>a);return source.slice(a,b);}
const presenter=section('  function recoveryStatus(', '  function leaveChat(');
const sendOwner=section('  async function sendMessage(', "  thread.addEventListener('click'");
function fixture(){
 const elements=new Map(),messages=[],drafts=new Map(),timers=new Map();let sequence=0,calls=0,reply;
 const node=()=>({hidden:false,textContent:'',children:[],setAttribute(){},appendChild(child){this.children.push(child);if(child.id)elements.set(child.id,child);},focus(){},disabled:false,value:''});
 elements.set('chatComposerShell',node());
 const ctx={console,AbortController,DOMException,Event,CustomEvent:class{constructor(type,init={}){this.type=type;this.detail=init.detail;}},
  document:{getElementById:id=>elements.get(id)||null,createElement:node,dispatchEvent(){}},
  window:{dispatchEvent(){},setTimeout(){}},navigator:{onLine:true},input:node(),send:node(),form:{},
  busy:false,contextReady:true,initialRestorePending:false,restoringConversation:0,conversationId:42,chatRevision:0,pendingSend:null,lastLoadedMessageId:0,
  voiceMode:false,voiceSpeaking:false,
  setTimeout(fn,ms){const id=++sequence;timers.set(id,{fn,ms});return id;},clearTimeout:id=>timers.delete(id),
  keepDraft(text,id=42){if(text)drafts.set(id,text);else drafts.delete(id);},autoSize(){},showStop(){},setChatCanvasView(){},
  addMessage(role,text){messages.push({role,text});},addTyping(){return {remove(){}};},rememberConversation(){},refreshHistory:async()=>{},
  api:async(payload,signal)=>{calls++;return reply(payload,signal);},
 };
 vm.createContext(ctx);vm.runInContext(presenter+sendOwner+'\nglobalThis.submit=sendMessage;',ctx);
 return {ctx,elements,messages,drafts,timers,get calls(){return calls;},reply:fn=>{reply=fn;}};
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
 const status=f.elements.get('chatRecoveryStatus');
 assert.equal(status.hidden,false);assert.equal(status.children.length,0,'No history or dismiss buttons are added');
 assert.match(status.textContent,kind==='server'?/Provider temporarily unavailable/:kind==='timeout'?/timed out/:/Could not connect to Chat/);
 assert.doesNotMatch(status.textContent,/Check history|before listening|before sending/);
 assert.equal(f.calls,1,'A failure does not automatically retry the request');
 checks+=11;
 f.reply(async()=>({ok:true,conversation_id:42,user_message_id:21,assistant_message_id:22,answer:'A fresh answer.'}));
 f.ctx.input.value='A new question';await f.ctx.submit(f.ctx.input.value);
 assert.equal(f.calls,2);assert.equal(f.ctx.busy,false);assert.equal(f.ctx.input.value,'');
 assert.equal(status.hidden,true);assert.equal(f.drafts.has(42),false);
 assert.deepEqual(f.messages.at(-1),{role:'assistant',text:'A fresh answer.'});checks+=6;
}
// A late reply must not modify another conversation, clear its draft, or
// replace the error/status of a newer request.
{
 const f=fixture();let release;f.reply(()=>new Promise(resolve=>{release=resolve;}));
 const old=f.ctx.submit('Old request');await Promise.resolve();
 f.ctx.chatRevision++;f.ctx.pendingSend=null;f.ctx.busy=false;f.ctx.conversationId=43;f.ctx.input.value='Current draft';
 release({conversation_id:42,answer:'Stale answer.'});await old;
 assert.equal(f.ctx.conversationId,43);assert.equal(f.ctx.input.value,'Current draft');
 assert.equal(f.messages.some(message=>message.text==='Stale answer.'),false);checks+=3;
}
console.log(`CHAT_SEND_RECOVERY=PASS (${checks} assertions; actual send owner; transport mocked)`);

// Run the production URL handoff cleanup, preserving unrelated agent/query state.
const remember=section('  function rememberConversation()', '  function initialConversation()');
for(const ready of [false,true]){let replaced='';const stored=new Map();const ctx={URL,Date,Number,contextReady:ready,conversationId:73,selectionKey:'active',location:{href:'https://example.test/sub/chat.php?conversation_id=73&agent=5&view=chat#chat'},history:{replaceState:(_a,_b,url)=>replaced=url},localStorage:{setItem:(k,v)=>stored.set(k,v)}};vm.createContext(ctx);vm.runInContext(remember+';rememberConversation();',ctx);assert.equal(JSON.parse(stored.get('active')).id,73);assert.equal(replaced,ready?'/sub/chat.php?agent=5&view=chat#chat':'');}
console.log('CHAT_HANDOFF_CLEANUP=PASS (2 cases)');
