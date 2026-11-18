import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import vm from 'node:vm';
import {workspaceDocument} from './fixtures/profile-workspace-dom-v1.mjs';
import {periodRef} from './fixtures/profile-analytics-period-ui-v1.mjs';

const chat=readFileSync(new URL('../chat.js',import.meta.url),'utf8');
const workspace=readFileSync(new URL('../profile-specialist-workspace-v1.js',import.meta.url),'utf8');
const php=readFileSync(new URL('../chat.php',import.meta.url),'utf8');
const prelude=chat.slice(chat.indexOf('\n')+1,chat.indexOf("  const thread = document.getElementById('chatThread');"));
const identity=chat.slice(chat.indexOf('  function initiativeIdentityMatchesV1()'),chat.indexOf('  function validInitiativeReplyV1('));
const remember=chat.slice(chat.indexOf('  function rememberConversation()'),chat.indexOf('  function initialConversation()'));
const analyticsGetter=chat.split('\n').find(line=>line.trimStart().startsWith('profileAnalyticsDestination:'));
const mediaGetter=chat.split('\n').find(line=>line.trimStart().startsWith('mediaCommandIntent:'));
assert.ok(prelude&&identity&&remember&&analyticsGetter&&mediaGetter);
let checks=0;
const check=(value,label)=>{assert.ok(value,label);checks++;};
function fixture({agent=7,cid=11,base='/sub',ready=false,restoring=true,hasHub=true,valid=true,query=null}={}){
  const ref=periodRef(),url=new URL('https://example.test'+base+'/chat.php');
  url.searchParams.set('agent',agent===0?'system':String(agent));url.searchParams.set('conversation_id',String(cid));url.searchParams.set('profile_analytics',ref);
  if(query!==null)url.search=query;
  const location={origin:url.origin,href:url.href,search:url.search};
  const calls=[],historyCalls=[],events=new Map(),state={agent,cid,ready,restoring};
  const raw={ownerUserId:1,chatAgentId:agent,chatConversationId:cid,workingAttentionBinding:'binding',ref,href:url.pathname+url.search};
  const config={userId:1,agentId:agent,workingAttentionBinding:'binding',csrf:'fixture',endpoint:base+'/api/chat-v236.php',profileAnalyticsDestination:valid?raw:null};
  const document=workspaceDocument();
  const hub={openProfile(selection){calls.push(selection);return true;}};
  const window={STONEFELLOW_CHAT:config,
    VP3_PROFILE_WORKSPACE_V1:{ownerKey:'1',agentId:agent,workingAttentionBinding:'binding',csrf:'fixture',endpoint:base+'/api/profile-specialist-workspace-v1.php',chatUrl:base+'/chat.php'},
    history:{replaceState(_a,_b,href){historyCalls.push(href);const next=new URL(href,location.origin);location.href=next.href;location.search=next.search;}},
    addEventListener(name,fn){if(!events.has(name))events.set(name,new Set());events.get(name).add(fn);},
    removeEventListener:(name,fn)=>events.get(name)?.delete(fn),dispatchEvent(event){for(const fn of [...(events.get(event.type)||[])])fn(event);}};
  if(hasHub)window.VP3AgentActivityHub=hub;
  const context={window,document,location,URL,URLSearchParams,Intl,Date,Number,Object,Array,Map,Set,JSON,Math,Promise,Uint8Array,AbortController,Event,atob,btoa,
    history:document.createElement('div'),contextReady:true,conversationId:cid,selectionKey:'selected',localStorage:{setItem(){}},setTimeout,clearTimeout};
  vm.createContext(context);
  vm.runInContext(prelude+identity+remember+'\nwindow.remember=rememberConversation;window.analyticsIntent=({'+analyticsGetter+'}).profileAnalyticsDestination;window.mediaIntent=({'+mediaGetter+'}).mediaCommandIntent;',context);
  window.STONEFELLOW_CHAT_CONTINUITY={
    profileAnalyticsDestination:window.analyticsIntent,agentId:()=>state.agent,conversationId:()=>state.cid,isReady:()=>state.ready,isRestoring:()=>state.restoring
  };
  vm.runInContext(workspace,context);
  return {window,context,config,state,raw,calls,hub,historyCalls,location,
    event:(name,detail)=>window.dispatchEvent({type:name,detail}),
    ready(){state.ready=true;state.restoring=false;window.dispatchEvent({type:'vp3:chat-context-ready'});}};
}
{
  const f=fixture();check(f.calls.length===0,'waits while production continuity is restoring');
  f.event('stonefellow:chat-context-changing',{initialRestore:true});f.ready();
  check(f.calls.length===1&&f.calls[0].analyticsRef===periodRef(),'initial restore preserves exact pending destination');
  f.event('vp3:agent-activity-ready');f.event('vp3:chat-context-ready');
  check(f.calls.length===1,'repeated readiness cannot reopen consumed destination');
  f.window.VP3ProfileWorkspaceV1.cancelPendingAnalyticsDestination();f.event('vp3:chat-context-ready');
  check(f.calls.length===1,'closing consumed workspace never reopens');
  f.window.remember();check(f.historyCalls.length===0&&new URL(f.location.href).searchParams.get('conversation_id')==='11','exact fixed tuple survives normal history cleanup for reload');
}
{
  const f=fixture({hasHub:false});f.ready();check(f.calls.length===0,'waits for actual Hub availability');
  f.window.VP3AgentActivityHub=f.hub;f.event('vp3:agent-activity-ready');
  check(f.calls.length===1,'late Hub opens the already-restored exact scope once');
}
{
  const f=fixture();f.window.VP3ProfileWorkspaceV1.cancelPendingAnalyticsDestination();f.ready();
  check(f.calls.length===0,'explicit close before readiness cancels pending open');
}
for(const mutate of [
  f=>{f.state.cid=12;f.context.conversationId=12;},
  f=>{f.state.agent=8;f.config.agentId=8;},
  f=>{f.config.userId=2;},
  f=>{f.config.workingAttentionBinding='another-session';},
  f=>{f.window.VP3ParticipantContinuity={selectedContext:()=>({id:4})};}
]){
  const f=fixture();mutate(f);f.ready();f.event('vp3:agent-activity-ready');
  check(f.calls.length===0,'restored fallback/Agent/owner/binding/participant mismatch denied');
}
{
  const f=fixture();f.event('stonefellow:chat-context-changing',{initialRestore:false});f.ready();
  check(f.calls.length===0,'real scope switch cancels pending intent');
  check(!new URL(f.location.href).searchParams.has('profile_analytics')&&!new URL(f.location.href).searchParams.has('conversation_id'),'scope switch clears fixed destination tuple');
}
for(const options of [{agent:0,base:'/nested/app'},{agent:7,base:''}]){
  const f=fixture({...options,ready:true,restoring:false});
  check(f.calls.length===1,'system/named Agent and nested/root deployment auto-open exact destination');
}
{
  const f=fixture({valid:false,query:'?agent=7&agent=8&conversation_id=11&profile_analytics='+encodeURIComponent(periodRef())});
  f.ready();f.window.remember();
  const url=new URL(f.location.href);
  check(f.calls.length===0&&!url.searchParams.has('profile_analytics')&&!url.searchParams.has('conversation_id')&&url.searchParams.getAll('agent').length===1,'invalid repeated tuple cannot survive fallback URL normalization');
}
{
  const f=fixture({valid:false,query:'?agent=7&conversation_id=11&view=chat'});
  f.window.remember();
  check(f.historyCalls[0]==='/sub/chat.php?agent=7&view=chat','normal cleanup calls actual window.history despite DOM history shadow');
}
{
  const f=fixture({valid:false,query:'?agent=7&conversation_id=11&marketing_plan=87'});
  const media=f.window.mediaIntent();f.window.remember();
  check(media?.productId===87&&media.conversationId===11&&media.agentId===7,'original media intent still captured before normalization');
  check(new URL(f.location.href).searchParams.get('marketing_plan')==='87','fixed-period cleanup leaves marketing hint to its existing owner');
}
check(php.indexOf('$profileAnalyticsOriginalQuery')<php.indexOf("require __DIR__ . '/chat-legacy-v108.php'"),'production page captures original raw query before legacy selection');
check(php.includes("require_once __DIR__.'/includes/profile-analytics-destination-v1.php'")&&php.includes('vp3_profile_analytics_destination_request_v1($profileAnalyticsOriginalQuery)'),'production page uses tested request helper');
check(php.includes('$agentInitialConversationId,$workingAttentionBinding')&&php.includes('cfg.profileAnalyticsDestination='),'production bootstrap binds the actual selected private Chat before chat.js');
console.log('PROFILE_ANALYTICS_ROUTE=PASS ('+checks+' checks; production Chat prelude/history and workspace bootstrap)');
