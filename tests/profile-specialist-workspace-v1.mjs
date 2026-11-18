import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import {webcrypto} from 'node:crypto';
import vm from 'node:vm';
import {workspaceDocument} from './fixtures/profile-workspace-dom-v1.mjs';

const source=readFileSync(new URL('../profile-specialist-workspace-v1.js',import.meta.url),'utf8');
let checks=0;
const check=(value,label)=>{assert.ok(value,label);checks++;};
const flush=async()=>{for(let i=0;i<12;i++)await new Promise(resolve=>setImmediate(resolve));};
const defer=()=>{let resolve;const promise=new Promise(done=>{resolve=done;});return {promise,resolve};};
const rev=letter=>letter.repeat(64);
const row=id=>({public_conversation_id:id,profile_agent_id:7,profile_agent_name:'Guide',visitor_label:'Visitor '+id,
  identity_disclosed:false,status:id===3?'resolved':'open',mode:'owner',latest_summary:'Visitor asks <img src=x onerror=attack()>',last_message_at:'2026-11-18 12:00:00',latest_message_id:id*10,needs_owner:true});
const view=(id=1,version=1,legacy=false)=>({public_conversation_id:id,
  conversation:{...row(id),source_revision:rev(String(version)),can_manual_reply:true},
  messages:{items:[{message_id:version,sender_type:'visitor',text:'Visitor <script>attack()</script> question '+version,created_at:'2026-11-18 12:00:00'}],has_more:false,next_before_message_id:null},
  current_turn:legacy?null:{turn_id:id*100+version,revision:version,can_claim:false,can_reply:true,can_delegate:true,can_resolve:true,responder:'owner',visitor_message_id:version},
  assistance:{can_prepare:!legacy,reason:legacy?'no_accepted_turn':''}});
const draft=(payload,text='A private answer')=>({draft_id:payload.prepare_request_id,prepare_request_id:payload.prepare_request_id,
  public_conversation_id:payload.public_conversation_id,source:{turn_id:payload.turn_id,revision:payload.revision,visitor_message_id:1,latest_message_id:1,source_revision:payload.source_revision},
  state:'ready',text,current:true,reason:'',can_regenerate:true,prepare:{...payload}});
function harness({selection={tab:'conversations',publicConversationId:1},override=null}={}){
  const document=workspaceDocument(),host=document.createElement('div');document.body.append(host);
  const calls=[],scope={owner:1,agent:7,cid:11,binding:'binding',hasChat:true};
  const state={views:new Map([[1,view(1)],[2,view(2)],[3,view(3,1,true)]]),drafts:new Map(),operations:new Map(),prepares:0,writes:0,version:1,override};
  const envelope=(action,data)=>({ok:true,contract:'vp3.profile-workspace.v1',action,scope:{owner_user_id:1,chat_agent_id:7,chat_conversation_id:11},working_attention_binding:'binding',observed_at:'2026-11-18T12:00:00Z',data});
  const defaultRead=payload=>{
    const id=Number(payload.public_conversation_id),current=state.views.get(id);
    switch(payload.action){
      case'conversation':return current;
      case'conversations':return {coverage:'ready',items:[row(1),row(2),row(3)],has_more:false,next_cursor:null};
      case'overview':return {status:'ready',profile:{is_public:false,enabled:false},coverage:'unconfigured',conversations:{coverage:'ready',items:[row(1)],has_more:false,next_cursor:null}};
      case'draft_status':return {public_conversation_id:id,draft:state.drafts.get(id)||null};
      case'draft_prepare':{
        let result=state.drafts.get(id);
        if(!result||result.prepare_request_id!==payload.prepare_request_id){state.prepares++;result=draft(payload);state.drafts.set(id,result);}
        return {public_conversation_id:id,draft:result};
      }
      case'conversation_action':{
        const key=payload.request_id;
        if(!state.operations.has(key)){state.writes++;state.operations.set(key,{...current,request_id:key,operation:payload.operation,duplicate:false,message_id:1000+state.writes});}
        return {...state.operations.get(key),duplicate:state.writes>0};
      }
      case'calls':return {calls:{coverage:'ready',calls:[],has_more:false}};
      default:throw new Error('Unexpected fixture action '+payload.action);
    }
  };
  const fetch=async(url,options)=>{
    const payload=options.method==='POST'?JSON.parse(options.body):Object.fromEntries(new URL(url).searchParams);
    calls.push({payload:{...payload},headers:options.headers,signal:options.signal});
    const data=state.override?await state.override(payload,defaultRead,options):defaultRead(payload);
    if(data?.httpStatus)return {ok:false,status:data.httpStatus,json:async()=>({ok:false,error:'Fixture permission changed.'})};
    return {ok:true,status:200,json:async()=>data?.envelope||envelope(payload.action,data)};
  };
  const window={VP3_PROFILE_WORKSPACE_V1:{endpoint:'/vp3/api/profile-specialist-workspace-v1.php',csrf:'csrf',profileUrl:'/vp3/profile-agent.php'},dispatchEvent(){}};
  const context=vm.createContext({window,document,crypto:webcrypto,location:{origin:'https://fixture.test',reload(){}},URL,AbortController,fetch,setTimeout,clearTimeout,Event,console});
  vm.runInContext(source,context);
  const options={scope:()=>({...scope}),selection,readReports:async()=>({report:{}}),renderReport(target){target.append(document.createElement('p'));}};
  let controller=window.VP3ProfileWorkspaceV1.mountWorkspace(host,options);
  return {document,host,window,state,scope,calls,options,get controller(){return controller;},mount(next=selection){controller=window.VP3ProfileWorkspaceV1.mountWorkspace(host,{...options,selection:next});return controller;},
    button(label){return host.querySelectorAll('button').find(item=>item.textContent===label);},
    fill(text){const input=host.querySelector('[data-profile-reply-body]');assert.ok(input,'reply input mounted');input.value=text;input.dispatchEvent({type:'input'});},
    submit(){host.querySelector('[data-profile-reply]').dispatchEvent({type:'submit'});}};
}

let h=harness();await flush();
check(h.host.querySelectorAll('[role="tab"]').map(row=>row.textContent).join('|')==='Overview|Conversations|Calls|Analytics|Reports','all five mounted workspace tabs are present');
check(h.host.textContent.includes('<script>attack()</script>')&&!h.host.querySelector('script'),'public visitor text remains literal evidence');
check(h.calls.every(row=>row.headers['X-VP3-Working-Attention']==='binding'&&Number(row.payload.chat_agent_id)===7&&Number(row.payload.chat_conversation_id)===11),'all reads carry exact private scope and session binding');
h.fill('My exact visitor reply');h.controller.select({tab:'calls'});await flush();h.controller.select({tab:'conversations',publicConversationId:1});await flush();
check(h.host.querySelector('[data-profile-reply-body]').value==='My exact visitor reply','local reply text survives same-scope tab changes after fresh target read');
h.controller.dispose();h.mount();await flush();
check(h.host.querySelector('[data-profile-reply-body]').value==='My exact visitor reply','local reply text survives same-scope close/reopen');
h.controller.select({tab:'conversations',publicConversationId:2});await flush();
check(h.host.querySelector('[data-profile-reply-body]').value==='','another visitor never inherits the first visitor draft');
h.controller.select({tab:'conversations',publicConversationId:1});await flush();
h.state.views.set(1,view(1,2));await h.controller.refresh();await flush();
check(h.button('Send reply to visitor').disabled,'a new visitor source disables sending text bound to the previous turn');
h.submit();await flush();check(h.state.writes===0,'submit cannot silently retarget old text to a new visitor turn');
h.button('Use this text for the current reply').click();h.submit();await flush();
check(h.state.writes===1,'explicit owner review permits one reply to the current turn');
check(h.calls.find(row=>row.payload.action==='conversation_action').payload.turn_id===102,'review binds the send to the new exact turn');
h.controller.dispose();

// A dropped first preparation response may have committed: only same-ID recovery is allowed.
h=harness();await flush();let droppedPrepare=true;
h.state.override=(payload,normal)=>{const result=normal(payload);if(payload.action==='draft_prepare'&&droppedPrepare){droppedPrepare=false;throw new Error('Lost preparation response');}return result;};
h.button('Prepare private draft').click();await flush();
check(h.state.prepares===1,'first preparation dispatched once');
check(h.button('Prepare private draft').disabled,'uncertain first preparation without a saved response disables new preparation');
h.button('Prepare private draft').dispatchEvent({type:'click'});await flush();
check(h.state.prepares===1,'prepare handler itself cannot bypass the uncertain-attempt guard');
h.button('Retry saved preparation').click();await flush();
const preparations=h.calls.filter(row=>row.payload.action==='draft_prepare');
check(preparations.length===2&&JSON.stringify(preparations[0].payload)===JSON.stringify(preparations[1].payload),'preparation retry preserves exact request ID/body');
check(h.state.prepares===1&&h.host.querySelector('[data-profile-reply-body]').value==='A private answer','recovery reads the saved draft without a second dispatch');
h.controller.dispose();h.mount();await flush();
check(h.state.prepares===1&&h.host.querySelector('[data-profile-reply-body]').value==='A private answer','reopened workspace recovers saved generated text through read-only status');
h.fill('Edited private answer');h.state.views.set(1,view(1,2));h.state.drafts.get(1).state='stale';h.state.drafts.get(1).current=false;
await h.controller.refresh();await flush();
check(h.button('Send reply to visitor').disabled,'a stale generated/edited draft cannot silently become a manual send');
h.submit();await flush();check(h.state.writes===0,'stale reviewed draft remains unsent');
h.controller.dispose();

// Response loss after a real mutation preserves the immutable operation through reopen.
h=harness();await flush();let droppedReply=true;
h.state.override=(payload,normal)=>{const result=normal(payload);if(payload.action==='conversation_action'&&droppedReply){droppedReply=false;throw new Error('Lost committed reply');}return result;};
h.fill('Send exactly once');h.submit();await flush();check(h.state.writes===1,'fixture committed the reply before losing its response');
check(h.button('Send reply to visitor').disabled&&h.button('Retry previous action'),'uncertain reply has a specific recovery action');
h.controller.dispose();h.mount();await flush();h.button('Retry previous action').click();await flush();
const sends=h.calls.filter(row=>row.payload.action==='conversation_action');
check(sends.length===2&&JSON.stringify(sends[0].payload)===JSON.stringify(sends[1].payload),'retry preserves original text, turn, revision and request ID');
check(h.state.writes===1&&h.host.textContent.includes('Your reply was sent'),'one receipt resolves the uncertain operation');
h.controller.dispose();

// A POST disposed during flight must wake the newly mounted controls when it settles.
let gate=defer();h=harness();await flush();h.fill('Pending close');
h.state.override=async(payload,normal)=>{const result=normal(payload);if(payload.action==='conversation_action')await gate.promise;return result;};
h.submit();await flush();h.controller.dispose();h.mount();await flush();
check(h.button('Send reply to visitor').disabled,'reopened same-scope UI knows the original operation is still pending');
gate.resolve();await flush();
check(h.button('Retry previous action')&&!h.button('Retry previous action').disabled,'settled disposed POST updates the reopened UI without an extra refresh');
h.controller.dispose();

// Pagination must render every explicitly loaded page, including beyond fifty rows.
h=harness({selection:{tab:'conversations'}});h.state.override=(payload,normal)=>{
  if(payload.action!=='conversations')return normal(payload);
  const page=Number(payload.cursor||0);return {coverage:'ready',items:Array.from({length:20},(_,i)=>row(page*20+i+1)),has_more:page<2,next_cursor:page<2?String(page+1):null};
};await h.controller.refresh();await flush();
h.button('Load more conversations').click();await flush();h.button('Load more conversations').click();await flush();
check(h.host.querySelectorAll('[data-profile-conversation]').length===60,'third loaded page is visible beyond the first fifty conversations');
check(h.calls.filter(row=>row.payload.action==='conversations').every(row=>row.payload.filter==='all'),'conversation inventory explicitly includes resolved/history');h.controller.dispose();

// Unconfigured Profile still gives a meaningful setup view; denied authority never retains facts.
h=harness({selection:{tab:'overview'},override:(payload,normal)=>({...normal(payload),profile:null})});await flush();
check(h.host.textContent.includes('Profile is not configured'),'missing public Profile setup is not a malformed-source error');h.controller.dispose();
h=harness({selection:{tab:'conversations',publicConversationId:3},override:(payload,normal)=>payload.action==='draft_status'?{httpStatus:503}:normal(payload)});await flush();
h.fill('Historical reply');h.submit();await flush();check(h.state.writes===1,'historical manual replies remain usable when private draft storage is unavailable');h.controller.dispose();
h=harness();await flush();h.state.override=()=>({httpStatus:403});await h.controller.refresh();await flush();
check(!h.host.querySelector('[data-profile-reply-body]')&&!h.host.textContent.includes('Visitor <script>'),'permission failure removes previous conversation facts');h.controller.dispose();
for(const [responder,label] of [['waiting','Waiting for an owner response.'],['none','No current responder.']]){
  h=harness();h.state.views.get(1).current_turn.responder=responder;await h.controller.refresh();await flush();
  check(h.host.textContent.includes(label)&&!h.host.textContent.includes('The Profile Agent is responding.'),responder+' is not mislabeled as an active Agent response');h.controller.dispose();
}

// Delayed reads cannot paint another visitor or a changed private scope.
gate=defer();h=harness();await flush();h.state.override=async(payload,normal)=>{if(payload.action==='conversation'&&Number(payload.public_conversation_id)===1)await gate.promise;return normal(payload);};
const pending=h.controller.refresh();await flush();h.controller.select({tab:'conversations',publicConversationId:2});await flush();gate.resolve();await pending;await flush();
check(h.host.textContent.includes('Visitor 2')&&!h.host.textContent.includes('Visitor 1'),'late exact-thread response cannot replace a new selection');
gate=defer();h.state.override=async(payload,normal)=>{await gate.promise;return normal(payload);};const changed=h.controller.refresh();await flush();h.scope.agent=8;gate.resolve();await changed;await flush();
check(!h.host.querySelector('[data-profile-reply-body]'),'private Agent change rejects a delayed thread response');h.controller.dispose();

new vm.Script(readFileSync(new URL('../agent-activity-hub-v1.js',import.meta.url),'utf8'));
new vm.Script(readFileSync(new URL('../profile-activity-chat.js',import.meta.url),'utf8'));
console.log(`PROFILE_SPECIALIST_WORKSPACE_V1=PASS (${checks} checks)`);
