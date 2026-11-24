import assert from 'node:assert/strict';
import fs from 'node:fs';
import vm from 'node:vm';

// Execute the production Chat bridge and marker binder with a small DOM. This
// checks actual scope/deletion behavior without loading unrelated Chat tools.
const source=fs.readFileSync(new URL('../chat.js',import.meta.url),'utf8');
function productionBlock(start,end){
  const a=source.indexOf(start),b=source.indexOf(end,a);
  assert.ok(a>=0&&b>a,'production Chat integration boundaries exist');
  return source.slice(a,b);
}
const binder=productionBlock('  function bindPhoneWorkspaceV1(', '  function bindProfileSpeechPa4a(');
const handler=productionBlock('  function handlePhoneWorkspaceEventV1(', "  window.addEventListener('vp3:phone-workspace-event',handlePhoneWorkspaceEventV1);");
const call='a'.repeat(32);
let checks=0;
function check(label,fn){fn();checks++;console.log('PASS '+label);}
function message(id,{cid=12,role='assistant',marker={kind:'voicemail',call_id:call,voicemail_id:'job-4'}}={}){
  const text={textContent:'Private saved voicemail transcript.'};
  const card={removed:false,remove(){this.removed=true;}};
  return {dataset:{chatMessageId:String(id),chatConversationId:String(cid),chatMessageRole:role,
    phoneWorkspaceMarker:typeof marker==='string'?marker:JSON.stringify(marker)},text,card,
    querySelector(selector){return selector==='.message-text'?text:null;},
    querySelectorAll(selector){assert.equal(selector,'[data-phone-workspace-card]');return card.removed?[]:[card];}};
}
function fixture(messages=[]){
  const syncs=[],bound=[],events=[];
  const state={cfg:{agentId:7},conversationId:12,contextReady:true,initialRestorePending:false,restoringConversation:0,historyParticipant:0,
    thread:{querySelectorAll(selector){assert.equal(selector,'[data-chat-message-id][data-phone-workspace-marker]');return messages;}},
    canonicalChatContinuity:{async syncConversation(id){syncs.push(id);}},
    window:{VP3PhoneWorkspaceV1:{bindMessage(element,marker){bound.push({element,marker});}},
      VP3ParticipantContinuity:{selectedContext(){return null;}},dispatchEvent(event){events.push(event);}},
    Event:class{constructor(type){this.type=type;}},
    CustomEvent:class{constructor(type,options){this.type=type;this.detail=options.detail;}}};
  vm.createContext(state);vm.runInContext(binder+'\n'+handler,state);
  return {state,syncs,bound,events,send(detail){state.handlePhoneWorkspaceEventV1({detail});}};
}
function deleted(ids,overrides={}){
  return {kind:'voicemail_deleted',conversation_id:12,agent_id:7,voicemail_id:'job-4',redacted_chat_message_ids:ids,...overrides};
}

check('manual Post synchronizes saved messages only in the current Agent conversation',()=>{
  const f=fixture();f.send({kind:'voicemail',conversation_id:12,agent_id:7});
  assert.deepEqual(f.syncs,[12]);assert.equal(f.events.length,0);assert.equal(f.bound.length,0);
});
check('existing incoming and outgoing Phone synchronization remains available',()=>{
  const f=fixture();for(const kind of ['incoming','outgoing'])f.send({kind,conversation_id:12,agent_id:7});
  assert.deepEqual(f.syncs,[12,12]);
});
check('an event from another conversation or Agent cannot switch or synchronize Chat',()=>{
  const f=fixture();
  for(const [conversation_id,agent_id] of [[13,7],[12,9],[0,7],['12',7],[12,'7'],[12,null]])
    f.send({kind:'voicemail',conversation_id,agent_id});
  assert.deepEqual(f.syncs,[]);
});
for(const field of ['initialRestorePending','restoringConversation','historyParticipant']){
  check(field+' blocks stale posting and deletion events',()=>{
    const row=message(101),f=fixture([row]);f.state[field]=1;
    f.send({kind:'voicemail',conversation_id:12,agent_id:7});f.send(deleted([101]));
    assert.deepEqual(f.syncs,[]);assert.equal(row.text.textContent,'Private saved voicemail transcript.');assert.equal(row.card.removed,false);
  });
}
check('an unready context or selected participant cannot receive private Phone updates',()=>{
  for(const selected of [false,true]){
    const row=message(101),f=fixture([row]);
    if(selected)f.state.window.VP3ParticipantContinuity.selectedContext=()=>({participant_id:6});else f.state.contextReady=false;
    f.send({kind:'voicemail',conversation_id:12,agent_id:7});f.send(deleted([101]));
    assert.deepEqual(f.syncs,[]);assert.equal(row.card.removed,false);
  }
});
check('confirmed deletion clears listed text, controls and saved transcript references',()=>{
  const row=message(101,{marker:{kind:'voicemail',call_id:call,voicemail_id:'job-4',report:{text:'Private transcript'},recording_refs:[{session_id:2,key:'secret'}],transcript_session_id:2}});
  const f=fixture([row]);f.send(deleted([101]));
  assert.equal(row.text.textContent,'Voice message deleted.');assert.equal(row.card.removed,true);
  assert.deepEqual(JSON.parse(row.dataset.phoneWorkspaceMarker),{kind:'voicemail',call_id:call,voicemail_id:'job-4',status:'deleted'});
  assert.deepEqual(f.syncs,[]);assert.equal(f.bound.length,0);
  assert.deepEqual(f.events.map(event=>event.type),['vp3:chat-history-updated']);
});
check('server-returned IDs redact legacy aliases after canonical voicemail migration',()=>{
  const row=message(102,{marker:{kind:'voicemail',call_id:'legacy-5',voicemail_id:'legacy-8'}}),f=fixture([row]);
  f.send(deleted([102]));assert.equal(row.card.removed,true);assert.equal(JSON.parse(row.dataset.phoneWorkspaceMarker).voicemail_id,'legacy-8');
});
check('deletion does not touch another Chat, user message, call card or an unlisted post',()=>{
  const rows=[message(103,{cid:13}),message(104,{role:'user'}),message(105,{marker:{kind:'call',call_id:call}}),message(106)];
  const f=fixture(rows);f.send(deleted([103,104,105]));
  for(const row of rows){assert.equal(row.card.removed,false);assert.equal(row.text.textContent,'Private saved voicemail transcript.');}
  assert.equal(f.events.length,0);
});
check('malformed stored markers cannot authorize DOM redaction',()=>{
  const rows=[message(107,{marker:'{invalid'}),message(108,{marker:{kind:'voicemail',call_id:'foreign-url',voicemail_id:'job-4'}}),
    message(109,{marker:{kind:'voicemail',call_id:call,voicemail_id:'4x'}})];
  const f=fixture(rows);f.send(deleted([107,108,109]));
  for(const row of rows)assert.equal(row.card.removed,false);
});
check('invalid or oversized deletion receipts are rejected before modifying messages',()=>{
  for(const ids of [[0],[-1],['101'],[Number.MAX_SAFE_INTEGER+1],null,{},Array.from({length:101},()=>101)]){
    const row=message(101),f=fixture([row]);f.send(deleted(ids));assert.equal(row.card.removed,false);
  }
  const row=message(101),f=fixture([row]);f.send(deleted([101],{voicemail_id:'invalid'}));assert.equal(row.card.removed,false);
});
check('deletion events cannot mutate matching IDs after the current Chat changes',()=>{
  for(const overrides of [{conversation_id:13},{agent_id:9}]){
    const row=message(101),f=fixture([row]);f.send(deleted([101],overrides));assert.equal(row.card.removed,false);
  }
});
check('duplicate and empty deletion receipts have no duplicate card or speech side effect',()=>{
  const row=message(101),f=fixture([row]);f.send(deleted([101,101]));f.send(deleted([101]));f.send(deleted([]));
  assert.equal(row.text.textContent,'Voice message deleted.');assert.equal(row.card.removed,true);assert.equal(f.bound.length,0);assert.deepEqual(f.syncs,[]);
});
check('restoring a deleted saved message never mounts its old media controls',()=>{
  const row=message(101),f=fixture([row]);
  f.state.bindPhoneWorkspaceV1(row,{kind:'voicemail',call_id:call,voicemail_id:'job-4',status:'deleted',report:{text:'old transcript'}});
  assert.equal(row.text.textContent,'Voice message deleted.');assert.equal(row.card.removed,true);assert.equal(f.bound.length,0);assert.equal(f.events.length,0);
});
check('ordinary valid voicemail cards still bind through the existing Phone renderer',()=>{
  const row=message(101),f=fixture([row]);f.state.bindPhoneWorkspaceV1(row,{kind:'voicemail',call_id:call,voicemail_id:'job-4'});
  assert.equal(f.bound.length,1);assert.equal(row.card.removed,false);assert.equal(f.events[0].type,'vp3:phone-call-message');
});
console.log(`PHONE_VOICEMAIL_CHAT_V1=PASS (${checks} scoped posting/deletion checks; production Chat helpers)`);
