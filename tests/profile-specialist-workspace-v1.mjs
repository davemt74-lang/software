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
function harness({selection={tab:'conversations',publicConversationId:1},override=null,storage=new Map()}={}){
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
      case'calls':return {calls:{coverage:'ready',calls:[],has_more:false,next_cursor:null,filter:payload.filter||'current',selected:null}};
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
  const window={sessionStorage:{getItem:key=>storage.get(key)||null,setItem:(key,value)=>storage.set(key,value)},VP3_PROFILE_WORKSPACE_V1:{endpoint:'/vp3/api/profile-specialist-workspace-v1.php',csrf:'csrf',profileUrl:'/vp3/profile-agent.php'},dispatchEvent(){}};
  const context=vm.createContext({window,document,crypto:webcrypto,location:{origin:'https://fixture.test',reload(){}},URL,AbortController,fetch,setTimeout,clearTimeout,Event,console});
  vm.runInContext(source,context);
  const options={scope:()=>({...scope}),selection,readReports:async()=>({report:{}}),renderReport(target){target.append(document.createElement('p'));}};
  let controller=window.VP3ProfileWorkspaceV1.mountWorkspace(host,options);
  return {document,host,window,state,scope,calls,storage,options,get controller(){return controller;},mount(next=selection){controller=window.VP3ProfileWorkspaceV1.mountWorkspace(host,{...options,selection:next});return controller;},
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
const freshInput=h.host.querySelector('[data-profile-reply-body]');freshInput.focus();h.fill('');
check(h.button('Use this text for the current reply').parentNode.hidden&&!h.host.querySelector('.vp3-profile-feedback'),'clearing all stale text removes its obsolete review warnings');
freshInput.value='A fresh reply to the current visitor';freshInput.setSelectionRange(8,8);freshInput.dispatchEvent({type:'input'});
check(!h.button('Send reply to visitor').disabled,'new text after clearing a stale draft enables Send without an extra refresh');
check(freshInput===h.host.querySelector('[data-profile-reply-body]')&&h.document.activeElement===freshInput&&freshInput.selectionStart===8&&freshInput.selectionEnd===8,'input control refresh preserves the same textarea, focus and caret');
h.submit();await flush();
const freshSend=h.calls.find(row=>row.payload.action==='conversation_action')?.payload;
check(h.state.writes===1&&freshSend?.turn_id===102&&freshSend.revision===2&&freshSend.message==='A fresh reply to the current visitor'&&!freshSend.prepare_request_id,'fresh manual reply binds to the current turn without reusing the stale generated draft');
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

// Exact terminal outcomes are static, scoped and independently pageable.
const call=(id=91,state='ended')=>({call_id:id,public_conversation_id:id+100,revision:3,mode:'audio',state,state_label:state==='ending'?'Call cleanup pending':'Call '+state,
  detail_kind:['ended','declined','cancelled','expired','failed'].includes(state)?'outcome':'current',visitor_label:'Visitor for call '+id,agent_name:'Guide',assisted:true,activity_available:true,
  created_at_ms:1795000000000,accepted_at_ms:1795000001000,ended_at_ms:state==='ended'?1795000010000:null,chat_message_id:500+id});
const savedSection=(key,items,more=false)=>({availability:items.length?'ready':'empty',coverage:more?'limited':'ready',source_revision:rev('d'),reason:'',items,has_more:more,next_cursor:more?'next-'+key:null});
const segment=id=>({id,speaker:'Visitor',text:'Saved transcript '+id+' <img src=x>',start_ms:id*1000,text_truncated:false});
const task=id=>({id,canonical_ref:'office:task:'+id,title:'Reviewed task '+id,task_status:'completed',source_url:'/vp3/artist-listening.php?session=77'});
const research=id=>({id:'research-'+id,query:'Saved research '+id,stored_status:'running',display_status:'incomplete',summary:'Stored result '+id,
  sources:[{title:'Source',url:'https://example.test/research'}],started_at_ms:1795000001000});
function savedOutcome(target,section='all',cursor=''){
  const sections={analysis:savedSection('analysis',[{kind:'summary',text:'Saved call summary '+target.call_id,reviewed:true}]),
    transcript:savedSection('transcript',cursor?[segment(3)]:[segment(1),segment(2)],!cursor),
    research:savedSection('research',cursor?[research(2)]:[research(1)],!cursor),
    followups:savedSection('followups',cursor?[task(2)]:[task(1)],!cursor)};
  return {contract:'vp3.profile-call-outcome.v1',call_id:target.call_id,public_conversation_id:target.public_conversation_id,context_revision:rev('c'),call:target,
    sections:section==='all'?sections:{[section]:sections[section]}};
}
function callReader(payload,normal){
  const target=call(Number(payload.call_id)||91);
  if(payload.action==='calls')return {calls:{coverage:'ready',calls:[call(99)],selected:payload.call_id?target:null,filter:payload.filter,has_more:false,next_cursor:null}};
  if(payload.action==='call_outcome')return {call_outcome:savedOutcome(target,payload.section,payload.cursor)};
  return normal(payload);
}
h=harness({selection:{tab:'calls',callView:'history',callId:91,publicConversationId:191},override:callReader});
let activityMounts=0;h.window.VP3ProfileCallActivityPa3c={mount(){activityMounts++;return {select(){},dispose(){}};}};await flush();
check(h.host.textContent.includes('Saved call summary 91')&&h.host.querySelectorAll('[data-profile-outcome-section]').length===4,'exact terminal selection outside the list page renders four static saved sections');
check(activityMounts===0&&!h.calls.some(row=>row.payload.action==='call_activity'),'terminal outcome never mounts or requests live activity');
check(h.host.textContent.includes('Saved transcript 1 <img src=x>')&&!h.host.querySelector('img'),'saved transcript text cannot create markup');
check(h.host.textContent.includes('Incomplete when the call closed'),'interrupted research is not shown as still running');
h.button('Load more transcript').click();await flush();
check(h.host.querySelectorAll('[data-profile-transcript-segment]').length===3,'next transcript page appends without losing earlier segments');
check(h.calls.at(-1).payload.call_id==='91'&&h.calls.at(-1).payload.public_conversation_id==='191'&&h.calls.at(-1).payload.cursor==='next-transcript','section page requests retain exact call and public conversation');
h.button('Load more research').click();h.button('Load more follow-ups').click();await flush();
check(h.host.querySelectorAll('[data-profile-research]').length===2&&h.host.querySelectorAll('[data-profile-followup]').length===2,'research and reviewed task pages retain all accepted items');
check([...h.storage.values()].every(value=>!value.includes('Saved transcript')&&!value.includes('Saved call summary')),'reload navigation stores only call selection IDs and filter');
const navigation=h.storage;h.controller.dispose();
h=harness({selection:{tab:'calls'},storage:navigation,override:callReader});await flush();
check(h.calls[0].payload.call_id==='91'&&h.calls[0].payload.filter==='history'&&h.host.textContent.includes('Saved call summary 91'),'reload restores exact scoped history selection through a fresh read');
h.controller.dispose();
h=harness({selection:{tab:'overview'},storage:navigation,override:callReader});await flush();
check(!h.calls.some(row=>row.payload.action==='calls'),'normal Profile entry still starts Overview despite saved call selection');h.controller.dispose();

h=harness({selection:{tab:'calls',callView:'history',callId:91,publicConversationId:191},override:callReader});await flush();
h.state.override=(payload,normal)=>payload.action==='call_outcome'&&payload.cursor?{httpStatus:409}:callReader(payload,normal);
h.button('Load more transcript').click();await flush();
check(!h.host.textContent.includes('Saved transcript 1')&&!h.host.textContent.includes('Saved call summary 91')&&h.button('Reload outcome'),'source 409 clears all accumulated outcome facts before explicit reload');
h.state.override=callReader;h.button('Reload outcome').click();await flush();
check(h.host.textContent.includes('Saved call summary 91'),'explicit reload obtains the current exact outcome again');
h.state.override=(payload,normal)=>{const result=callReader(payload,normal);if(payload.action==='call_outcome'&&payload.section==='followups')result.call_outcome.sections.followups={availability:'forbidden',coverage:'ready',source_revision:null,reason:'task_access',items:[],has_more:false,next_cursor:null};return result;};
h.button('Load more follow-ups').click();await flush();
check(!h.host.textContent.includes('Reviewed task 1')&&h.host.textContent.includes('Your current access does not include')&&h.host.textContent.includes('Saved call summary 91'),'a denied section clears its saved rows while preserving independently verified sections');
h.state.override=(payload,normal)=>{const result=callReader(payload,normal);if(payload.action==='call_outcome'&&payload.cursor)result.call_outcome.context_revision=rev('e');return result;};
h.button('Load more research').click();await flush();
check(!h.host.textContent.includes('Stored result 1')&&h.button('Reload outcome'),'changed call context withholds every earlier saved section');h.controller.dispose();

h=harness({selection:{tab:'calls',callView:'history',callId:91,publicConversationId:191},override:(payload,normal)=>{
  const result=callReader(payload,normal);if(payload.action==='call_outcome')result.call_outcome.sections.analysis={...savedSection('analysis',[
    {kind:'summary',text:'Generated from accepted source findings.',reviewed:false,based_on_reviewed_sources:true}]),coverage:'limited',reason:'partial_analysis'};return result;
}});await flush();
check(h.host.textContent.includes('based on reviewed findings')&&!h.host.textContent.includes('Summary · reviewed'),'derived summary provenance is not mislabeled as owner review');
check(h.host.textContent.includes('covers part of the verified transcript'),'partial saved analysis cannot imply whole-call coverage');
h.state.override=(payload,normal)=>{const result=callReader(payload,normal);if(payload.action==='call_outcome')result.call_outcome.sections.analysis.items[0].based_on_reviewed_sources=true;return result;};
await h.controller.refresh();await flush();check(!h.host.textContent.includes('Saved call summary')&&h.button('Reload outcome'),'inconsistent generated-versus-reviewed provenance is withheld');h.controller.dispose();

// Separate section completions must merge with the newest accepted pages.
const transcriptGate=defer(),researchGate=defer();let holdTranscript=true,holdResearch=true;
h=harness({selection:{tab:'calls',callView:'history',callId:91,publicConversationId:191},override:async(payload,normal)=>{
  const result=callReader(payload,normal);
  if(payload.action==='call_outcome'&&payload.section==='transcript'&&payload.cursor&&holdTranscript){holdTranscript=false;await transcriptGate.promise;}
  if(payload.action==='call_outcome'&&payload.section==='research'&&payload.cursor){
    if(payload.cursor==='next-research'){
      result.call_outcome.sections.research={...savedSection('research',[research(2)],true),next_cursor:'last-research'};
      if(holdResearch){holdResearch=false;await researchGate.promise;}
    }else result.call_outcome.sections.research=savedSection('research',[research(3)]);
  }
  return result;
}});await flush();
h.button('Load more transcript').click();h.button('Load more research').click();await flush();researchGate.resolve();await flush();transcriptGate.resolve();await flush();
check(h.host.querySelectorAll('[data-profile-transcript-segment]').length===3&&h.host.querySelectorAll('[data-profile-research]').length===2,
  'out-of-order Transcript and Research pages both remain visible');
h.button('Load more research').click();await flush();
check(h.host.querySelectorAll('[data-profile-research]').length===3&&!h.button('Load more research'),
  'later third Research page keeps the second page after another section completed');h.controller.dispose();

h=harness({selection:{tab:'calls',callView:'history',callId:91,publicConversationId:191},override:(payload,normal)=>{
  const result=callReader(payload,normal);if(payload.action==='call_outcome')result.call_outcome.sections.transcript={...savedSection('transcript',[segment(1)]),coverage:'limited',source_revision:null,reason:'source_limit',has_more:true,next_cursor:null};return result;
}});await flush();
check(h.host.textContent.includes('Saved transcript 1')&&!h.button('Load more transcript')&&h.host.textContent.includes('Further records could not be established'),'bounded source preview is explicit and cannot claim an unverified continuation');h.controller.dispose();
h=harness({selection:{tab:'calls',callView:'history',callId:91,publicConversationId:191},override:(payload,normal)=>{const result=callReader(payload,normal);if(payload.action==='call_outcome')result.call_outcome.sections.followups.items[0].source_url='/other/artist-listening.php?session=77';return result;}});await flush();
check(!h.host.textContent.includes('Reviewed task 1')&&h.button('Reload outcome'),'outcome rejects a source outside the configured application base');h.controller.dispose();

let mounted=0,disposed=0;
h=harness({selection:{tab:'calls',callView:'current',callId:91,publicConversationId:191},override:(payload,normal)=>payload.action==='calls'?{calls:{coverage:payload.cursor?'ready':'limited',calls:[call(payload.cursor?92:91,'active')],selected:call(91,'active'),filter:'current',has_more:!payload.cursor,next_cursor:payload.cursor?null:'more-calls'}}:normal(payload)});
h.window.VP3ProfileCallActivityPa3c={mount(){mounted++;return {select(){},dispose(){disposed++;}};}};await flush();
h.button('Load more calls').click();await flush();
check(h.host.querySelectorAll('[data-profile-workspace-call]').length===2&&mounted===1&&disposed===0,'list pagination keeps the same current activity controller mounted');h.controller.dispose();
h=harness({selection:{tab:'calls',callView:'history',callId:91,publicConversationId:191},override:(payload,normal)=>payload.action==='calls'?{calls:{coverage:'ready',calls:[],selected:call(91,'ending'),filter:'history',has_more:false,next_cursor:null}}:normal(payload)});await flush();
check(h.host.textContent.includes('still current')&&!h.calls.some(row=>row.payload.action==='call_outcome'),'history navigation hint cannot turn canonical ending into a completed outcome');h.controller.dispose();

gate=defer();h=harness({selection:{tab:'calls',callView:'history',callId:91,publicConversationId:191},override:async(payload,normal)=>{if(payload.action==='call_outcome'&&Number(payload.call_id)===91)await gate.promise;return callReader(payload,normal);}});await flush();
h.controller.select({tab:'calls',callView:'history',callId:92,publicConversationId:192});await flush();gate.resolve();await flush();
check(h.host.textContent.includes('Saved call summary 92')&&!h.host.textContent.includes('Saved call summary 91'),'late outcome cannot replace another exact call selection');
h.state.override=()=>({httpStatus:403});await h.controller.refresh();await flush();
check(!h.host.textContent.includes('Saved call summary')&&!h.host.querySelector('[data-profile-outcome-section]'),'private permission revocation clears the full outcome surface');h.controller.dispose();

gate=defer();h=harness({selection:{tab:'calls',callView:'history',callId:91,publicConversationId:191},override:async(payload,normal)=>{if(payload.action==='call_outcome')await gate.promise;return callReader(payload,normal);}});await flush();
h.scope.cid=12;gate.resolve();await flush();
check(!h.host.textContent.includes('Saved call summary')&&!h.host.querySelector('[data-profile-transcript-segment]'),'private Chat switch cannot receive a delayed saved call outcome');h.controller.dispose();

const imageCredit={contract:'vp3.profile-image-credit.v1',owner_name:'\uFEFFSaved\u00A0<owner>',catalog_name:'Original collection',references:[{title:'Selected <img src=x onerror=attack()> reference'},{title:'\u2028Second saved reference\u202F'}],price_cents:0};
h=harness({override:(payload,normal)=>{
  const result=normal(payload);if(payload.action!=='conversation')return result;
  return {...result,messages:{...result.messages,items:[
    {message_id:101,sender_type:'visitor',text:'Visitor photo',created_at:'2026-11-18 12:00:00',image_edit:{kind:'input',url:'/vp3/api/profile-agent.php?action=owner_image&conversation_id=1&message_id=101&preview=1',download_url:'/vp3/api/profile-agent.php?action=owner_image&conversation_id=1&message_id=101',source_credit:imageCredit}},
    {message_id:102,sender_type:'agent',text:'Edited photo',created_at:'2026-11-18 12:00:01',image_edit:{kind:'result',url:'/vp3/api/profile-agent.php?action=owner_image&conversation_id=1&message_id=102&preview=1',download_url:'/vp3/api/profile-agent.php?action=owner_image&conversation_id=1&message_id=102',source_credit:imageCredit}},
    {message_id:103,sender_type:'agent',text:'Older edited photo',created_at:'2026-11-18 12:00:02',image_edit:{kind:'result',url:'/vp3/api/profile-agent.php?action=owner_image&conversation_id=1&message_id=103&preview=1',download_url:'/vp3/api/profile-agent.php?action=owner_image&conversation_id=1&message_id=103',source_credit:{...imageCredit,price_cents:100}}}
  ]}};
}});await flush();
const ownerResult=h.host.querySelector('[data-message-id="102"]'),ownerCredit=ownerResult.querySelector('[data-profile-image-source-credit]');
check(ownerCredit.textContent.includes('Profile owner: Saved <owner>')&&ownerCredit.textContent.includes('Collection: Original collection')&&ownerCredit.querySelectorAll('li').map(item=>item.textContent).join('|')==='Selected <img src=x onerror=attack()> reference|Second saved reference'&&ownerCredit.textContent.includes('Free use · $0.00'),'owner result shows the frozen exact source credit and zero price');
check(ownerResult.querySelectorAll('img').length===1&&!ownerCredit.querySelector('img')&&!h.host.querySelector('[data-message-id="101"] [data-profile-image-source-credit]'),'owner source titles stay literal and visitor inputs do not receive generated-result credit');
const legacyResult=h.host.querySelector('[data-message-id="103"]');
check(legacyResult.textContent.includes('Reference details are unavailable for this saved edit.')&&!legacyResult.textContent.includes('Free use')&&legacyResult.querySelectorAll('a').some(link=>link.textContent==='Download edited photo'),'owner can still view and download a historical result with unavailable or invalid credit');h.controller.dispose();

new vm.Script(readFileSync(new URL('../agent-activity-hub-v1.js',import.meta.url),'utf8'));
new vm.Script(readFileSync(new URL('../profile-activity-chat.js',import.meta.url),'utf8'));
console.log(`PROFILE_SPECIALIST_WORKSPACE_V1=PASS (${checks} checks)`);
