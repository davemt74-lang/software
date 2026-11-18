import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import {webcrypto} from 'node:crypto';
import vm from 'node:vm';
import {workspaceDocument} from './fixtures/profile-workspace-dom-v1.mjs';
import {periodRef,periodValue,cutoff} from './fixtures/profile-analytics-period-ui-v1.mjs';

const source=readFileSync(new URL('../profile-specialist-workspace-v1.js',import.meta.url),'utf8');
const instrumented=source.replace('  window.VP3ProfileWorkspaceV1=Object.freeze({mountWorkspace,cancelPendingAnalyticsDestination});',
  '  window.__period={analyticsRef,analyticsSource,analyticsPeriodModel,analyticsLedgerAmount,analyticsCalendarRange};\n  window.VP3ProfileWorkspaceV1=Object.freeze({mountWorkspace,cancelPendingAnalyticsDestination});');
assert.notEqual(instrumented,source,'production model test seam found');
let checks=0;
const check=(value,label)=>{assert.ok(value,label);checks++;};
const flush=async()=>{for(let i=0;i<12;i++)await new Promise(resolve=>setImmediate(resolve));};
const deferred=()=>{let resolve;const promise=new Promise(done=>resolve=done);return {promise,resolve};};
function harness({selected=periodRef(),override=null}={}){
  const document=workspaceDocument(),host=document.createElement('div');document.body.append(host);
  const scope={owner:1,agent:7,cid:11,binding:'binding',hasChat:true},events=new Map(),requests=[];
  const state={override,latest:periodValue(periodRef(1,cutoff-9*3600,'Asia/Tokyo','e')),traffic:0,disposed:0};
  const window={VP3_PROFILE_WORKSPACE_V1:{endpoint:'/sub/api/profile-specialist-workspace-v1.php',csrf:'fixture',chatUrl:'/sub/chat.php'},
    addEventListener:(name,fn)=>{if(!events.has(name))events.set(name,new Set());events.get(name).add(fn);},
    removeEventListener:(name,fn)=>events.get(name)?.delete(fn),dispatchEvent:event=>{for(const fn of events.get(event.type)||[])fn(event);},
    VP3ProfileAnalytics:{mountWorkspace(target){state.traffic++;target.textContent='Retained rolling traffic';return {dispose(){state.disposed++;},refresh(){}};}}};
  const context={window,document,URL,Intl,Date,Number,Object,Array,Map,Set,JSON,Math,Promise,Uint8Array,crypto:webcrypto,AbortController,Event,
    atob,btoa,location:{origin:'https://example.test',href:'https://example.test/sub/chat.php'},setTimeout,clearTimeout,structuredClone,
    fetch:async(url,settings)=>{
      const input=Object.fromEntries(new URL(url).searchParams);requests.push(input);
      let value=state.override?await state.override(input,settings):null;
      if(!value)value={status:200,data:{analytics_period:input.ref?periodValue(input.ref):structuredClone(state.latest)}};
      if(value.error)throw value.error;
      return {ok:value.status<400,status:value.status,json:async()=>value.envelope||{ok:value.status<400,contract:'vp3.profile-workspace.v1',action:input.action,
        scope:{owner_user_id:1,chat_agent_id:7,chat_conversation_id:11},working_attention_binding:'binding',observed_at:'2026-11-18T12:00:00Z',data:value.data,error:value.message}};
    }};
  vm.createContext(context);vm.runInContext(instrumented,context);
  const api=window.VP3ProfileWorkspaceV1.mountWorkspace(host,{scope:()=>scope,selection:{tab:'analytics',analyticsRef:selected},readReports:async()=>({}),renderReport:(target,_value,options)=>{state.onSelect=options.onSelect;target.textContent='Fixture Reports';}});
  return {document,host,window,scope,requests,state,api,pure:window.__period,
    click:text=>{const element=host.querySelectorAll('button').find(button=>button.textContent===text);assert.ok(element,'button '+text);element.click();}};
}
{
  const f=harness();await flush();
  check(f.host.textContent.includes('Completed day · 17 Nov 2026')&&f.host.textContent.includes('Previous period: 16 Nov 2026')
    &&f.host.textContent.includes('Period timezone: UTC'),'included local calendar dates and saved zone render concisely');
  const details=f.host.querySelector('[data-profile-period-details]'),wire=periodValue();
  check(details&&!details.open&&details.getAttribute('open')===null&&details.querySelector('summary').textContent==='Period details','exact period metadata starts inside a native collapsed disclosure');
  check(details.textContent.includes(wire.window.from_local+' to '+wire.window.to_local)
    &&details.textContent.includes(wire.window.previous.from_local+' to '+wire.window.previous.to_local)
    &&details.textContent.includes('The start is included; the end is excluded.')
    &&details.textContent.includes('Timezone source in this link: UTC fallback.')
    &&details.textContent.includes('Fixed cutoff:')&&details.textContent.includes('Evidence checked:'),'collapsed details preserve exact bounds, provenance, cutoff and evidence check');
  const calendarRange=f.pure.analyticsCalendarRange;
  check(calendarRange(Date.parse('2026-11-16T15:00:00Z'),Date.parse('2026-11-17T15:00:00Z'),'Asia/Tokyo')==='17 Nov 2026','display dates follow the saved zone rather than the browser zone');
  check(calendarRange(Date.parse('2026-03-08T05:00:00Z'),Date.parse('2026-03-09T04:00:00Z'),'America/Havana')==='8 Mar 2026'
    &&calendarRange(Date.parse('2026-11-01T04:00:00Z'),Date.parse('2026-11-02T05:00:00Z'),'America/Havana')==='1 Nov 2026','gap and repeated-midnight periods retain their included local date');
  check(calendarRange(Date.parse('2024-02-28T00:00:00Z'),Date.parse('2024-03-01T00:00:00Z'),'UTC').replace(/\s/g,'')==='28–29Feb2024','inclusive display range retains leap day and excludes the cutoff date');
  check(f.requests[0].action==='analytics_period'&&f.requests[0].ref===periodRef(),'exact selected ref requested');
  check(f.requests[0].chat_agent_id==='7'&&f.requests[0].chat_conversation_id==='11','private scope sent separately');
  check(f.host.textContent.includes('USD 90,071,992,547,409,931.23'),'large ledger amount preserves every digit');
  check(f.host.textContent.includes('USD +0.01'),'money comparison uses saved scale');
  check(f.host.textContent.includes('Current unresolved conversations')&&f.host.textContent.includes('separate from the completed periods'),'current backlog separately labeled');
  check(f.host.querySelector('[data-profile-period-link]').href.includes('conversation_id=11'),'exact current Chat source link');
  await f.api.refresh();await flush();check(f.requests.at(-1).ref===periodRef(),'refresh retains fixed reference');
  f.click('7 days');await flush();check(f.requests.at(-1).ref===periodRef(7),'sibling uses same cutoff and zone');
  f.click('Traffic details');await flush();check(f.state.traffic===1&&f.host.textContent.includes('Retained rolling traffic'),'retained rolling renderer selected explicitly');
  f.click('Completed periods');await flush();check(f.state.disposed===1&&f.requests.at(-1).ref===periodRef(7),'mode switch disposes rolling view and retains fixed reference');
  f.click('Latest completed periods');await flush();check(!Object.hasOwn(f.requests.at(-1),'ref')&&f.host.textContent.includes('Period timezone: Asia/Tokyo'),'latest explicitly resolves current setting');
  check(!f.host.textContent.includes('your current account timezone'),'saved source provenance does not claim historical settings');
  const resolved=f.state.latest.ref;await f.api.refresh();await flush();check(f.requests.at(-1).ref===resolved,'latest resolves once then becomes fixed');
  const ref=f.pure.analyticsRef;
  check(ref(periodRef())?.period===1,'canonical fixed ref parses');
  check(!ref(periodRef()+'=')&&!ref(periodRef().replace(':u:',':x:')),'padding and unknown provenance rejected');
  check(!ref(periodRef(1,cutoff+1)),'nonmidnight cutoff rejected');
  check(!ref(periodRef(1,cutoff,'America/New_York','u')),'UTC fallback cannot claim another zone');
  for(const [stamp,zone] of [['2026-03-08T05:00:00Z','America/Havana'],['2026-09-06T04:00:00Z','America/Santiago'],['2026-11-01T04:00:00Z','America/Havana']]){
    check(ref(periodRef(1,Date.parse(stamp)/1000,zone,'e'))!==null,'canonical local day boundary accepts skipped or first repeated midnight in '+zone);
  }
  check(ref(periodRef(1,Date.parse('2026-11-01T05:00:00Z')/1000,'America/Havana','e'))===null,'second repeated midnight is not a different report cutoff');
  check(!ref(periodRef(1,Date.parse('2026-03-08T05:00:01Z')/1000,'America/Havana','e')),'a nonboundary second after skipped midnight is rejected');
  const href='/sub/chat.php?agent=system&conversation_id=11&profile_analytics='+encodeURIComponent(periodRef());
  check(f.pure.analyticsSource({ref:periodRef(),url:href},'/sub/chat.php')?.chatAgentId===0,'system Agent and base path supported');
  for(const bad of [href+'&agent=system',href+'&extra=1',href.replace('/sub/','/other/'),href+'#fragment','//attacker.test'+href,href.replace('conversation_id=11','conversation_id=9007199254740992')]){
    check(!f.pure.analyticsSource({ref:periodRef(),url:bad},'/sub/chat.php'),'unsafe or ambiguous source link rejected');
  }
  const model=f.pure.analyticsPeriodModel,valid=periodValue();
  check(model(valid,periodRef())!==null,'full closed response accepted');
  for(const mutate of [
    value=>value.window.timezone='Asia/Tokyo',
    value=>value.window.previous.to_utc_ms--,
    value=>value.window.from_local='2026-11-16T00:00:00+00:00',
    value=>value.window.previous.from_utc_ms-=3600000,
    value=>value.period_options[1].ref=periodRef(7,cutoff-86400),
    value=>value.metrics.pop(),
    value=>value.metrics[0].current={value:0,observed_value:null,coverage:'unavailable',reason:'storage_unavailable'},
    value=>{value.metrics[0].previous.value=0;value.metrics[0].comparison.percent=100;},
    value=>value.money[0].scale=0,
    value=>value.money[0].amount_unit='currency_minor',
    value=>value.money[0].current.value_minor=9007199254740992,
    value=>{value.metrics[0].current={value:null,observed_value:2,coverage:'partial',reason:'source_proof_incomplete'};value.metrics[0].comparison={delta:1,percent:50};}
  ]){const broken=structuredClone(valid);mutate(broken);check(model(broken,periodRef())===null,'mismatched or misleading period response rejected');}
  check(model(valid,periodRef(7))===null,'another valid period cannot satisfy exact request');
  const corruptBacklog=structuredClone(valid);corruptBacklog.backlog.metrics[0].coverage='unavailable';
  check(model(corruptBacklog,periodRef())===null,'unavailable current backlog cannot retain an earlier numeric total');
  const corruptDelta=structuredClone(valid);corruptDelta.money[0].comparison.delta_minor='2';
  check(model(corruptDelta,periodRef())===null,'ledger delta must match exact decimal-string arithmetic');
  const empty=periodValue();const emptyDelay=empty.metrics.find(row=>row.key==='median_first_reply_seconds');emptyDelay.current={value:null,observed_value:null,coverage:'complete',reason:'no_observations'};emptyDelay.comparison=null;
  check(model(empty,periodRef())!==null,'empty verified delay cohort can be complete with no numeric median');
  f.api.dispose();
}
{
  const partial=periodValue();partial.coverage='limited';partial.groups.events={coverage:'unavailable',reason:'storage_unconfigured'};
  for(const metric of partial.metrics.filter(row=>row.group==='events')){metric.current={value:null,observed_value:null,coverage:'unavailable',reason:'storage_unconfigured'};metric.previous={...metric.current};metric.comparison={delta:null,percent:null};}
  const delay=partial.metrics.find(row=>row.key==='median_first_reply_seconds');
  delay.current={value:null,observed_value:12.5,coverage:'partial',reason:'source_proof_incomplete'};delay.comparison={delta:null,percent:null};
  const f=harness({override:async()=>({status:200,data:{analytics_period:partial}})});await flush();
  check(f.host.querySelector('[data-profile-analytics-metric="visit_records"]').textContent.includes('Unavailable'),'event outage is not displayed as zero');
  check(f.host.querySelector('[data-profile-analytics-metric="calls_offered"]').textContent.includes('3'),'verified native metric remains visible during event outage');
  check(f.host.querySelector('[data-profile-analytics-metric="median_first_reply_seconds"]').textContent.includes('Observed subset'),'partial median labeled as subset');
  f.api.dispose();
}
{
  const gate=deferred();let first=true;
  const f=harness({override:async()=>{if(first){first=false;return gate.promise;}return null;}});
  await flush();f.api.select({tab:'analytics',analyticsRef:periodRef(7)});await flush();
  gate.resolve({status:200,data:{analytics_period:periodValue()}});await flush();
  check(f.host.querySelector('[data-profile-analytics-period]').dataset.profileAnalyticsPeriod===periodRef(7),'late older period cannot overwrite selected sibling');
  f.api.dispose();
}
{
  const gate=deferred();const f=harness({override:async()=>gate.promise});await flush();f.scope.cid=12;
  gate.resolve({status:200,data:{analytics_period:periodValue()}});await flush();
  check(!f.host.textContent.includes('USD 90,071'),'late response withheld after private Chat change');
  f.api.dispose();
}
{
  const f=harness();await flush();
  f.state.override=async()=>({status:503,data:{},message:'Completed-period storage is temporarily unavailable.'});
  await f.api.refresh();await flush();
  check(!f.host.querySelector('[data-profile-analytics-period]')&&f.host.textContent.includes('Retry completed period')
    &&f.host.querySelector('[data-profile-analytics-view="traffic"]')?.isConnected,
    'period outage clears prior facts while keeping the independent traffic view reachable');
  f.click('Traffic details');await flush();
  check(f.state.traffic===1&&f.host.textContent.includes('Retained rolling traffic'),'traffic view opens after completed-period failure');
  f.click('Completed periods');await flush();f.state.override=null;f.click('Retry completed period');await flush();
  check(f.host.querySelector('[data-profile-analytics-period]')?.dataset.profileAnalyticsPeriod===periodRef()
    &&f.requests.at(-1).ref===periodRef(),'explicit period retry restores the same fixed source');
  f.api.dispose();
}
{
  const gate=deferred();const f=harness({override:async()=>gate.promise});await flush();
  f.click('Traffic details');await flush();
  gate.resolve({status:503,data:{},message:'Obsolete period failure.'});await flush();
  check(f.state.traffic===1&&f.host.textContent.includes('Retained rolling traffic')&&!f.host.textContent.includes('Obsolete period failure.'),
    'late failed period request cannot replace the newly selected traffic view');
  f.api.dispose();
}
{
  const f=harness();await flush();f.state.override=async()=>({status:403,data:{},message:'Profile access changed.'});
  await f.api.refresh();await flush();
  check(!f.host.textContent.includes('USD 90,071')&&f.host.textContent.includes('Reload Chat'),'authority loss clears prior period facts');
  check(!f.host.querySelector('[data-profile-analytics-view="traffic"]'),'authority loss removes both analytics mode controls');
  f.api.dispose();
}

{
  const f=harness();await flush();f.api.select({tab:'reports'});await flush();
  const ref=periodRef(7),href='/sub/chat.php?agent=7&conversation_id=11&profile_analytics='+encodeURIComponent(ref);
  check(f.state.onSelect({ref,href})===true,'same-scope fixed report source selects inline');
  await flush();check(f.requests.at(-1).ref===ref,'inline report source retains exact encoded period');
  f.api.select({tab:'reports'});await flush();const before=f.requests.length;
  check(f.state.onSelect({ref,href:href.replace('conversation_id=11','conversation_id=12')})===false,'other-scope source leaves original link navigation to the browser');
  check(f.requests.length===before,'other-scope source never retargets a current-Chat read');
  f.api.dispose();
}

console.log('PROFILE_ANALYTICS_PERIOD_UI=PASS ('+checks+' checks; production mounted renderer and model; transport mocked)');
