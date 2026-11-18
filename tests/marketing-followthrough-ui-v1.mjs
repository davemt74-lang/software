import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import vm from 'node:vm';
import {webcrypto} from 'node:crypto';
import {workspaceDocument} from './fixtures/profile-workspace-dom-v1.mjs';
import {followthroughValue,marketingRef,publicationsValue,publicationId,promotionId} from './fixtures/marketing-followthrough-ui-v1.mjs';
import {cutoff} from './fixtures/profile-analytics-period-ui-v1.mjs';
const workspace=readFileSync(new URL('../profile-specialist-workspace-v1.js',import.meta.url),'utf8');
const production=readFileSync(new URL('../chat-marketing-followthrough-v1.js',import.meta.url),'utf8');
const source=production.replace('  window.VP3MarketingFollowthroughV1=Object.freeze({','  window.__marketingModels={reportModel,publicationsModel};\n  window.VP3MarketingFollowthroughV1=Object.freeze({');
assert.notEqual(source,production,'production model seam found');
let checks=0;const check=(value,label)=>{assert.ok(value,label);checks++;};
const flush=async()=>{for(let i=0;i<14;i++)await new Promise(done=>setImmediate(done));};
const deferred=()=>{let resolve;const promise=new Promise(done=>resolve=done);return {promise,resolve};};
function harness({selection={ref:marketingRef()},override=null,pureOnly=false}={}){
  const document=workspaceDocument(),host=document.createElement('div');document.body.append(host);
  const scope={ownerUserId:1,chatAgentId:7,chatConversationId:11,workingAttentionBinding:'binding'},requests=[],events=new Map(),actions=[],dispatched=[];
  const state={override,latest:followthroughValue(marketingRef(1,cutoff-9*3600,'Asia/Tokyo','e')),authority:0};
  const window={VP3_INITIATIVE_WORKBENCH:{followthroughEndpoint:'/sub/api/marketing-followthrough-v1.php',ownerKey:'1',agentId:7},
    addEventListener:(name,fn)=>{if(!events.has(name))events.set(name,new Set());events.get(name).add(fn);},removeEventListener:(name,fn)=>events.get(name)?.delete(fn),
    dispatchEvent:event=>{dispatched.push(event.type);for(const fn of events.get(event.type)||[])fn(event);}};
  const context=vm.createContext({window,document,URL,URLSearchParams,Intl,Date,Number,Object,Array,Map,Set,JSON,Math,Promise,Uint8Array,BigInt,crypto:webcrypto,AbortController,Event,
    atob,btoa,location:{origin:'https://example.test',href:'https://example.test/sub/chat.php'},setTimeout,clearTimeout,structuredClone,
    fetch:async(url,settings)=>{const input=Object.fromEntries(new URL(url).searchParams);requests.push({input,settings});
      let result=state.override?await state.override(input,settings):null;
      result??={status:200,data:input.action==='publications'?{publications:publicationsValue()}:{followthrough:input.ref?followthroughValue(input.ref):structuredClone(state.latest)}};
      if(result.error)throw result.error;
      return {ok:result.status<400,status:result.status,json:async()=>result.envelope||{ok:result.status<400,contract:'vp3.marketing-followthrough.v1',action:input.action,
        scope:{owner_user_id:1,chat_agent_id:7,chat_conversation_id:11},working_attention_binding:'binding',data:result.data}};
    }});
  if(pureOnly)document.currentScript={hasAttribute:name=>name==='data-vp3-period-tools-only'};
  vm.runInContext(workspace,context);document.currentScript=null;vm.runInContext(source,context);
  const api=window.VP3MarketingFollowthroughV1.mount(host,{selection,context:()=>scope,onBack:()=>actions.push(['back']),onAuthorityChanged:()=>state.authority++,
    onCreative:marker=>{actions.push(['creative',structuredClone(marker)]);return true;},onCurrentPlan:id=>actions.push(['plan',id]),onConversation:value=>{actions.push(['conversation',structuredClone(value)]);return true;}});
  return {host,window,scope,requests,state,actions,api,dispatched,pure:window.__marketingModels,endpoint:new URL('https://example.test/sub/api/marketing-followthrough-v1.php'),
    click:label=>{const button=host.querySelectorAll('button').find(row=>row.textContent===label);assert.ok(button,'button '+label);button.click();}};
}
{
  const f=harness();await flush();
  check(f.host.querySelector('[data-marketing-fixed-ref]')?.dataset.marketingFixedRef===marketingRef(),'actual mounted report accepts exact fixed source: '+f.host.textContent+' requests='+JSON.stringify(f.requests.map(r=>r.input)));
  check(f.requests.length===1&&f.requests[0].settings.method==='GET'&&f.requests[0].settings.headers['X-VP3-WORKING-ATTENTION']==='binding'
    &&f.requests[0].input.chat_agent_id==='7'&&f.requests[0].input.chat_conversation_id==='11','query-only request carries separate exact original private scope');
  check(f.host.textContent.includes('Completed day · 17 Nov 2026')&&f.host.textContent.includes('Previous period: 16 Nov 2026')&&f.host.textContent.includes('Period timezone: UTC'),'compact heading uses saved local days');
  const details=f.host.querySelector('[data-marketing-period-details]');check(details&&!details.open&&details.textContent.includes('not lifetime totals')&&details.textContent.includes('2026-11-18T00:00:00+00:00'),'collapsed native details retain exact bounds and count semantics');
  const link=f.host.querySelector('[data-marketing-fixed-link]');check(link.href.startsWith('/sub/chat.php?')&&new URL(link.href,'https://example.test').searchParams.get('marketing_followthrough')===marketingRef()&&!link.href.includes('binding'),'fixed source link preserves base and no credentials');
  check(f.host.textContent.includes('USD 90,071,992,547,409,931.23'),'ledger display preserves large integer strings');
  check(f.host.textContent.includes('<script>bad()</script>')&&!f.host.querySelector('script')&&!f.host.querySelector('img'),'saved public and creative text stays escaped');
  check(f.actions.length===0,'reading does not open a creative, plan or visitor conversation');
  f.click('Review original creative');f.click('Open current plan');f.click('Open visitor conversation');
  check(f.actions[0][0]==='creative'&&f.actions[0][1].creative_id===promotionId&&f.actions[0][1].draft_id==='c'.repeat(64),'explicit original review keeps its exact creative and draft');
  check(JSON.stringify(f.actions.slice(1))===JSON.stringify([['plan',87],['conversation',{tab:'conversations',publicConversationId:51}]]),'current plan and visitor selection are separate explicit actions');
  f.click('Refresh this period');await flush();check(f.requests.at(-1).input.ref===marketingRef(),'refresh keeps fixed reference');
  f.click('7 days');await flush();check(f.requests.at(-1).input.ref===marketingRef(7),'sibling uses same saved cutoff and zone');
  f.click('Latest completed periods');await flush();check(!f.requests.at(-1).input.ref&&f.requests.at(-1).input.publication_id===publicationId&&f.host.textContent.includes('Period timezone: Asia/Tokyo'),'latest explicitly checks current setting once');
  f.click('Refresh this period');await flush();check(f.requests.at(-1).input.ref===f.state.latest.ref,'latest resolution becomes a fixed ref');
  f.click('Back to plans');check(f.actions.at(-1)[0]==='back','back returns through parent existing dialog');
  const api=f.window.VP3MarketingFollowthroughV1,canonical=marketingRef(),alias=canonical.replace(/^marketing:/,'profile:');
  check(api.parseRef(alias)?.ref===canonical&&api.parseRef(canonical)?.period===1,'Profile alias resolves to the same canonical marketing reference');
  check(!api.parseRef(canonical+'=')&&!api.parseRef(marketingRef(1,cutoff+1)),'malformed and nonboundary refs rejected');
  const href='/sub/chat.php?agent=7&conversation_id=11&marketing_followthrough='+encodeURIComponent(canonical);
  check(api.source({ref:alias,url:href},'/sub/chat.php')?.chatConversationId===11,'alias source accepts exact canonical original Chat link');
  for(const bad of [href+'&agent=7',href+'&x=1',href+'#bad',href.replace('/sub/','/wrong/'),href.replace('conversation_id=11','conversation_id=9007199254740992'),href.replace(encodeURIComponent(canonical),encodeURIComponent(marketingRef(7)))])
    check(!api.source({ref:alias,url:bad},'/sub/chat.php'),'ambiguous or different source destination rejected');
  const valid=followthroughValue();check(f.pure.reportModel(valid,f.scope,f.endpoint,canonical)!==null,'complete closed report model accepted');
  for(const mutate of [v=>v.publication.chat_conversation_id=12,v=>v.window.from_utc_ms--,v=>v.period_options[1].ref=marketingRef(7,cutoff-86400),
    v=>v.metrics[0].current={value:0,observed_value:null,coverage:'unavailable',reason:'storage_unavailable'},v=>v.metrics[0].comparison={delta:7,percent:700},
    v=>v.money[0].scale=0,v=>v.money[0].current.value_minor=12,v=>v.exchanges.items[0].open_selection.publicConversationId=52,
    v=>v.exact_creative.marker.creative_id='d'.repeat(32),v=>v.current_plan.url='/sub/chat.php?agent=7&conversation_id=12&marketing_plan=87']){
    const bad=structuredClone(valid);mutate(bad);check(f.pure.reportModel(bad,f.scope,f.endpoint,canonical)===null,'malformed target, period, comparison or evidence rejected');}
  f.api.dispose();
}
{
  const f=harness({selection:{productId:87},override:input=>{
    if(input.action==='publications'){const value=publicationsValue(input.cursor?['d'.repeat(32)]:[publicationId,'e'.repeat(32)]);if(!input.cursor){value.has_more=true;value.next_cursor='next-page';}return {status:200,data:{publications:value}};}return null;
  }});await flush();check(f.requests[0].input.action==='publications'&&f.host.querySelectorAll('[data-marketing-publication]').length===2,'plan opens explicit publication choices');
  check(f.host.textContent.includes('Saved shirt promotion <img src=x onerror=alert(1)>')&&!f.host.querySelector('img'),'publication choices identify the exact saved title while escaping public-looking text');
  check(!f.host.querySelector('[data-marketing-fixed-ref]'),'publication listing does not silently select a current creative');
  f.click('Load more publications');await flush();check(f.requests.at(-1).input.cursor==='next-page'&&f.host.querySelectorAll('[data-marketing-publication]').length===3,'publication pagination appends checked choices');
  f.click('View follow-through');await flush();check(f.requests.at(-1).input.ref===marketingRef(),'selected publication opens exact report');f.api.dispose();
}
{
  const f=harness();await flush();f.state.override=()=>({status:200,data:{followthrough:(()=>{const value=followthroughValue();value.coverage='limited';value.metrics[0].current={value:null,observed_value:2,coverage:'partial',reason:'legacy_conversation_attribution'};value.metrics[6].current={value:null,observed_value:null,coverage:'unavailable',reason:'storage_unavailable'};return value;})()}});
  await f.api.refresh();await flush();check(f.host.textContent.includes('Observed 2 · partial')&&f.host.textContent.includes('Unavailable')&&f.host.textContent.includes('not counted as zero'),'partial and unavailable evidence remain distinct from zero');
  f.state.override=()=>({status:403});await f.api.refresh();await flush();check(!f.host.querySelector('[data-marketing-fixed-ref]')&&!f.host.textContent.includes('USD ')&&f.state.authority===1,'authority failure clears saved facts and reports parent invalidation');f.api.dispose();
}
{
  const gate=deferred(),f=harness({override:()=>gate.promise});await flush();f.scope.chatConversationId=12;gate.resolve({status:200,data:{followthrough:followthroughValue()}});await flush();
  check(!f.host.querySelector('[data-marketing-fixed-ref]')&&!f.host.textContent.includes('USD '),'late prior-Chat result cannot render after a real scope change');f.api.dispose();
}
{
  const f=harness({override:()=>({status:503})});await flush();check(f.host.textContent.includes('Retry this view'),'read failure keeps explicit exact retry');f.state.override=null;f.click('Retry this view');await flush();check(f.requests.at(-1).input.ref===marketingRef()&&f.host.querySelector('[data-marketing-fixed-ref]'),'retry keeps original publication and period');f.api.dispose();
}
{
  const f=harness({override:()=>({error:Object.assign(new Error('aborted'),{name:'AbortError'})})});await flush();check(f.host.textContent.includes('timed out')&&f.host.textContent.includes('Retry this view'),'bounded request timeout produces recoverable honest error');f.api.dispose();
}
{
  const f=harness({pureOnly:true,selection:{productId:87},override:input=>input.action==='report'?{status:403}:null});await flush();
  check(Boolean(f.window.VP3FixedPeriodToolsV1)&&!f.window.VP3ProfileWorkspaceV1&&!f.dispatched.includes('vp3:profile-workspace-ready'),'pure-only asset exports no Profile workspace or ready event');
  check(f.host.querySelectorAll('[data-marketing-publication]').length===1&&f.requests[0].input.action==='publications','account/Chat publication inventory works without a Profile global');
  f.click('View follow-through');await flush();check(f.state.authority===1&&!f.host.querySelector('[data-marketing-fixed-ref]'),'inventory availability cannot grant a forbidden full Profile report');f.api.dispose();
}

// Production Chat prelude/history and destination bridge, with controlled restore readiness.
const chatSource=readFileSync(new URL('../chat.js',import.meta.url),'utf8');
const prelude=chatSource.slice(chatSource.indexOf('\n')+1,chatSource.indexOf("  const thread = document.getElementById('chatThread');"));
const identity=chatSource.slice(chatSource.indexOf('  function initiativeIdentityMatchesV1()'),chatSource.indexOf('  function validInitiativeReplyV1('));
const remember=chatSource.slice(chatSource.indexOf('  function rememberConversation()'),chatSource.indexOf('  function initialConversation()'));
const marketingGetter=chatSource.split('\n').find(line=>line.trimStart().startsWith('marketingFollowthroughDestination:'));
const mediaGetter=chatSource.split('\n').find(line=>line.trimStart().startsWith('mediaCommandIntent:'));
function routeFixture({agent=7,cid=11,base='/sub',ready=false,restoring=true,hasMedia=true,valid=true,query=null}={}){
  const ref=marketingRef(),url=new URL('https://example.test'+base+'/chat.php');url.searchParams.set('agent',agent===0?'system':String(agent));url.searchParams.set('conversation_id',String(cid));url.searchParams.set('marketing_followthrough',ref);if(query!==null)url.search=query;
  const location={origin:url.origin,href:url.href,search:url.search},events=new Map(),calls=[],historyCalls=[],state={agent,cid,ready,restoring};
  const raw={ownerUserId:1,chatAgentId:agent,chatConversationId:cid,workingAttentionBinding:'binding',ref,href:url.pathname+url.search};
  const config={userId:1,agentId:agent,workingAttentionBinding:'binding',csrf:'fixture',endpoint:base+'/api/chat-v236.php',marketingFollowthroughDestination:valid?raw:null};
  const document=workspaceDocument(),media={openFollowthrough(selection,bound){calls.push({selection,bound});return true;}};
  const window={STONEFELLOW_CHAT:config,VP3_INITIATIVE_WORKBENCH:{ownerKey:'1',agentId:agent,followthroughEndpoint:base+'/api/marketing-followthrough-v1.php'},
    history:{replaceState(_a,_b,href){historyCalls.push(href);const next=new URL(href,location.origin);location.href=next.href;location.search=next.search;}},
    addEventListener(name,fn){if(!events.has(name))events.set(name,new Set());events.get(name).add(fn);},removeEventListener:(name,fn)=>events.get(name)?.delete(fn),dispatchEvent(event){for(const fn of [...(events.get(event.type)||[])])fn(event);}};
  if(hasMedia)window.VP3MediaCommands=media;
  const context=vm.createContext({window,document,location,URL,URLSearchParams,Intl,Date,Number,Object,Array,Map,Set,JSON,Math,Promise,Uint8Array,BigInt,AbortController,Event,atob,btoa,
    history:document.createElement('div'),contextReady:true,conversationId:cid,selectionKey:'selected',localStorage:{setItem(){}},setTimeout,clearTimeout});
  vm.runInContext(prelude+identity+remember+'\nwindow.remember=rememberConversation;window.marketingIntent=({'+marketingGetter+'}).marketingFollowthroughDestination;window.mediaIntent=({'+mediaGetter+'}).mediaCommandIntent;',context);
  window.STONEFELLOW_CHAT_CONTINUITY=window.STONEFELLOW_CHAT_CONTINUITY_V87={marketingFollowthroughDestination:window.marketingIntent,agentId:()=>state.agent,conversationId:()=>state.cid,isReady:()=>state.ready,isRestoring:()=>state.restoring,
    mediaCommandContext:()=>state.ready&&!state.restoring?{ownerKey:'1',agentId:state.agent,conversationId:state.cid,isCurrent:()=>state.ready&&!state.restoring}:null};
  vm.runInContext(workspace,context);vm.runInContext(production,context);
  return {window,context,config,state,raw,calls,media,historyCalls,location,event:(name,detail)=>window.dispatchEvent({type:name,detail}),ready(){state.ready=true;state.restoring=false;window.dispatchEvent({type:'vp3:chat-context-ready'});}};
}
{
  const f=routeFixture();check(f.calls.length===0,'fixed Marketing route waits for actual continuity');f.event('stonefellow:chat-context-changing',{initialRestore:true});f.ready();
  check(f.calls.length===1&&f.calls[0].selection.ref===marketingRef(),'real initialRestore opens exact publication once');
  f.event('vp3:media-commands-ready');f.event('vp3:chat-context-ready');check(f.calls.length===1,'repeated readiness cannot reopen consumed dialog');
  f.window.remember();check(f.historyCalls.length===0&&new URL(f.location.href).searchParams.get('conversation_id')==='11','fixed Marketing tuple survives real history normalization');
}
{
  const f=routeFixture({hasMedia:false});f.ready();check(f.calls.length===0,'restored route waits for actual Marketing dialog API');f.window.VP3MediaCommands=f.media;f.event('vp3:media-commands-ready');check(f.calls.length===1,'deferred Marketing runtime receives exact one-shot route');
}
{
  const f=routeFixture();f.window.VP3MarketingFollowthroughV1.cancelPendingDestination();f.ready();check(f.calls.length===0,'close before readiness cancels pending Marketing route');
}
for(const mutate of [f=>{f.state.cid=12;f.context.conversationId=12;},f=>{f.state.agent=8;f.config.agentId=8;},f=>{f.config.userId=2;},f=>{f.config.workingAttentionBinding='other';},f=>{f.window.VP3ParticipantContinuity={selectedContext:()=>({id:4})};}]){
  const f=routeFixture();mutate(f);f.ready();f.event('vp3:media-commands-ready');check(f.calls.length===0,'fallback scope, owner, binding or participant mismatch cannot open saved Marketing route');
}
{
  const f=routeFixture();f.event('stonefellow:chat-context-changing',{initialRestore:false});f.ready();check(f.calls.length===0&&!new URL(f.location.href).searchParams.has('marketing_followthrough'),'true Chat switch cancels and removes fixed Marketing hint');
}
for(const options of [{agent:0,base:'/nested/app'},{agent:7,base:''}]){const f=routeFixture({...options,ready:true,restoring:false});check(f.calls.length===1,'system/named original Agent with nested/root base supported');}
{
  const f=routeFixture({valid:false,query:'?agent=7&agent=8&conversation_id=11&marketing_followthrough='+encodeURIComponent(marketingRef())});f.ready();f.window.remember();const url=new URL(f.location.href);
  check(!f.calls.length&&!url.searchParams.has('marketing_followthrough')&&!url.searchParams.has('conversation_id')&&url.searchParams.getAll('agent').length===1,'invalid repeated tuple cannot survive fallback normalization');
}
{
  const f=routeFixture({valid:false,query:'?agent=7&conversation_id=11&marketing_plan=87'});const intent=f.window.mediaIntent();f.window.remember();
  check(intent?.productId===87&&intent.conversationId===11&&new URL(f.location.href).searchParams.get('marketing_plan')==='87','existing /marketing initial intent remains owned by its existing command flow');
}

console.log('MARKETING_FOLLOWTHROUGH_UI=PASS ('+checks+' checks; production renderer/model, mocked transport)');
