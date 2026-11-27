import assert from 'node:assert/strict';
import fs from 'node:fs';
import vm from 'node:vm';

const source=fs.readFileSync(new URL('../profile-agent-analytics.js',import.meta.url),'utf8');
let checks=0;
const check=(value,label)=>{assert.ok(value,label);checks++;console.log(`PASS ${label}`);};
const flush=async()=>{for(let n=0;n<10;n++)await new Promise(resolve=>setImmediate(resolve));};
const pending=()=>{let resolve,reject;const promise=new Promise((yes,no)=>{resolve=yes;reject=no;});return {promise,resolve,reject};};
const revenue={ready:true,coverage:'complete',periods:Object.fromEntries(['today','7d','30d'].map(k=>[k,{current:{views:2,intents:1,conversions:0},change:{}}]))};
const analytics=(propertyId=0,days=30,views=9)=>({ready:true,coverage:'complete',property_id:propertyId,days,
  properties:[{id:11,property_type:'external',label:'Workshop',domain:'workshop.example'}],stats:{page_views:views},timeline:[],events:[],property_stats:[],top_pages:[],referrers:[],devices:[],browsers:[]});
function host(){
  const listeners=new Map(),renders=[];
  return {listeners,renders,_html:'',get innerHTML(){return this._html;},set innerHTML(v){this._html=v;renders.push(v);},
    querySelector(selector){const key=selector.slice(1,-1);return this._html.includes(key)?{addEventListener:(type,fn)=>listeners.set(`${key}:${type}`,fn)}:null;}};
}
const portalHost=host(),portalNotice={},urls=[],timers=[];
const context={URL,Date,Intl,AbortController,console,
  window:{location:{origin:'https://example.test'},addEventListener(){},removeEventListener(){}},
  document:{visibilityState:'visible',getElementById(){return null;}},
  setInterval(fn){timers.push(fn);return timers.length;},clearInterval(){},
  setTimeout,clearTimeout,
  fetch(){throw new Error('A mounted workspace must use its injected private readers.');},
};
vm.createContext(context);vm.runInContext(source,context);
const mount=context.window.VP3ProfileAnalytics.mountWorkspace;
check(typeof mount==='function'&&timers.length===0,'the reusable renderer is available without portal configuration or an autonomous poller');

let readArgs=[],notices=[];
const first=host();const view=mount(first,{readAnalytics:async args=>{readArgs.push(args);return analytics(args.propertyId,args.days);},readIntelligence:async()=>revenue,onNotice:(...args)=>notices.push(args)});
await flush();
check(first.innerHTML.includes('Traffic across your VP3 presence')&&first.innerHTML.includes('From Profile traffic to real business'),'one mounted view renders the existing traffic and conversion sections');
check(readArgs[0].propertyId===0&&readArgs[0].days===30&&readArgs[0].signal instanceof AbortSignal,'injected reads receive an exact selection and abort signal');
check(timers.length===0,'workspace reads introduce no automatic polling timer');
await view.refresh();check(readArgs.length===2&&notices.length===1,'explicit refresh uses the same scoped readers');
view.dispose();view.dispose();const oldCalls=readArgs.length;await view.refresh();
check(first.innerHTML===''&&readArgs.length===oldCalls,'dispose clears visible metrics and subsequent refresh is harmless');

const lateTraffic=pending(),second=host();let lateSignal,lateRevenue=0;
const secondView=mount(second,{readAnalytics:args=>{lateSignal=args.signal;return lateTraffic.promise;},readIntelligence:async()=>{lateRevenue++;return revenue;},onNotice:(...args)=>notices.push(args)});
await flush();secondView.refresh();secondView.dispose();const noticeCount=notices.length;
lateTraffic.resolve(analytics(0,30,765432));await flush();
check(lateSignal.aborted&&second.innerHTML===''&&!second.renders.some(s=>s.includes('765,432')),'closing a view aborts traffic and fences a transport that still returns late');
check(lateRevenue===0&&notices.length===noticeCount,'a closed traffic request cannot start revenue, a queued refresh, or a stale notice');

const lateRevenueRead=pending(),third=host();let revenueSignal;
const thirdView=mount(third,{readAnalytics:async()=>analytics(),readIntelligence:args=>{revenueSignal=args.signal;return lateRevenueRead.promise;},onNotice:()=>{throw new Error('A disposed mount must not notify.');}});
await flush();thirdView.dispose();lateRevenueRead.resolve(revenue);await flush();
check(revenueSignal.aborted&&third.innerHTML==='','closing during revenue aborts the second read and prevents late rendering');

const delayed=pending(),fourth=host();let selectionCalls=[],delayNext=false,delayedSignal;
const fourthView=mount(fourth,{readAnalytics:args=>{selectionCalls.push([args.propertyId,args.days]);if(delayNext){delayNext=false;delayedSignal=args.signal;return delayed.promise;}return Promise.resolve(analytics(args.propertyId,args.days));},readIntelligence:async()=>revenue});
await flush();delayNext=true;fourth.listeners.get('data-analytics-days:change')({target:{value:'7'}});await flush();
check(!fourth.innerHTML.includes('vp3-analytics-metric')&&fourth.innerHTML.includes('Loading the selected property'),'changing a filter immediately removes the previous selection totals');
fourth.listeners.get('data-analytics-property:change')({target:{value:'11'}});delayed.resolve(analytics(0,7,654321));await flush();
check(delayedSignal.aborted&&!fourth.renders.some(s=>s.includes('654,321')),'a later filter change aborts and rejects obsolete totals');
check(JSON.stringify(selectionCalls.at(-1))==='[11,7]'&&fourth.innerHTML.includes('value="11" selected')&&fourth.innerHTML.includes('value="7" selected'),'the latest property and period remain paired after a late response');
fourthView.dispose();

const failed=host();let failRevenue=false;
const failedView=mount(failed,{readAnalytics:async()=>analytics(),readIntelligence:async()=>{if(failRevenue)throw new Error('Revenue source unavailable.');return revenue;}});
await flush();failRevenue=true;await failedView.refresh();
check(failed.innerHTML.includes('Revenue source unavailable.')&&!failed.innerHTML.includes('From Profile traffic to real business'),'a failed read removes prior conversion totals without substituting zero');
failedView.dispose();
const denied=host(),deniedView=mount(denied,{readAnalytics:async()=>analytics(0,30,456789),readIntelligence:async()=>{throw Object.assign(new Error('Profile access changed.'),{status:403});}});await flush();
check(denied.innerHTML.includes('Profile access changed.')&&!denied.innerHTML.includes('vp3-analytics-metric')&&!denied.innerHTML.includes('456,789'),'permission loss on the second read withholds the earlier traffic snapshot too');deniedView.dispose();
const incomplete=host(),incompleteView=mount(incomplete,{readAnalytics:async()=>({...analytics(),coverage:'unavailable'}),readIntelligence:async()=>revenue});await flush();
check(incomplete.innerHTML.includes('No totals were established.')&&!incomplete.innerHTML.includes('vp3-analytics-metric'),'a nominal ready response with unavailable coverage cannot render totals');incompleteView.dispose();
const noNative=host(),noNativeView=mount(noNative,{readAnalytics:async()=>({...analytics(),native_profile_coverage:'unconfigured'}),readIntelligence:async()=>revenue});await flush();
check(noNative.innerHTML.includes('Profile visit totals are not available yet.'),'an uninitialized native property has an explicit coverage notice');noNativeView.dispose();

// A real transport can remain pending indefinitely or resolve after cancellation.
// Fire only the production deadline; no wall-clock wait or renderer hook is used.
const deadlineJobs=new Map();let deadlineId=0;
const timed={...context,window:{...context.window},
  setTimeout(fn,ms){const id=++deadlineId;deadlineJobs.set(id,{fn,ms});return id;},clearTimeout(id){deadlineJobs.delete(id);}};
vm.createContext(timed);vm.runInContext(source,timed);
const expire=async()=>{const entry=[...deadlineJobs.values()][0];assert.equal(entry?.ms,15000);entry.fn();await flush();};
const stuckTraffic=pending(),timeoutTrafficHost=host();let trafficRecovered=false,trafficAbort;
const timeoutTraffic=timed.window.VP3ProfileAnalytics.mountWorkspace(timeoutTrafficHost,{
  readAnalytics:args=>{trafficAbort=args.signal;return trafficRecovered?Promise.resolve(analytics(0,30,17)):stuckTraffic.promise;},
  readIntelligence:async()=>revenue,
});
await flush();const originalSignal=trafficAbort;await expire();
check(originalSignal.aborted&&timeoutTrafficHost.innerHTML.includes('Profile Analytics took too long')&&timeoutTrafficHost.innerHTML.includes('Retry analytics'),'stalled traffic has a bounded deadline and an actionable retry state');
trafficRecovered=true;await timeoutTraffic.refresh();
check(timeoutTrafficHost.innerHTML.includes('vp3-analytics-metric')&&!timeoutTrafficHost.innerHTML.includes('took too long'),'timed-out traffic releases its busy state and an explicit retry recovers');
stuckTraffic.resolve(analytics(0,30,987654));await flush();
check(!timeoutTrafficHost.innerHTML.includes('987,654'),'a transport that resolves after its deadline cannot replace recovered analytics');timeoutTraffic.dispose();
const stuckConversion=pending(),timeoutRevenueHost=host();let revenueRecovered=false,revenueAbort;
const timeoutRevenue=timed.window.VP3ProfileAnalytics.mountWorkspace(timeoutRevenueHost,{
  readAnalytics:async()=>analytics(),
  readIntelligence:args=>{revenueAbort=args.signal;return revenueRecovered?Promise.resolve(revenue):stuckConversion.promise;},
});
await flush();const originalRevenueSignal=revenueAbort;await expire();
check(originalRevenueSignal.aborted&&timeoutRevenueHost.innerHTML.includes('vp3-analytics-metric')&&timeoutRevenueHost.innerHTML.includes('Profile conversion intelligence took too long'),'stalled conversion intelligence does not hide verified traffic indefinitely');
check(!timeoutRevenueHost.innerHTML.includes('From Profile traffic to real business'),'a conversion timeout never fabricates revenue or carries previous totals');
revenueRecovered=true;await timeoutRevenue.refresh();stuckConversion.resolve(revenue);await flush();
check(timeoutRevenueHost.innerHTML.includes('From Profile traffic to real business')&&!timeoutRevenueHost.innerHTML.includes('took too long'),'conversion intelligence recovers through the same explicit refresh');timeoutRevenue.dispose();
check(deadlineJobs.size===0,'completed and disposed reads clear every request deadline');

// The portal remains an auto-mount of the same renderer. Its configured app
// base is retained for both requests, including legacy configuration fallback.
for(const configured of [true,false]){
  const settings={analyticsEndpoint:'/vp3/api/vp3-analytics.php',...(configured?{revenueEndpoint:'/vp3/api/profile-revenue-intelligence.php'}:{})};
  const portal={...context,window:{...context.window,PROFILE_AGENT_PORTAL:settings},document:{...context.document,getElementById:id=>id==='profileAgentAnalytics'?portalHost:id==='profileAgentNotice'?portalNotice:null},
    fetch:async raw=>{const url=new URL(raw);urls.push(url.pathname);return {ok:true,json:async()=>url.pathname.endsWith('vp3-analytics.php')?{ok:true,analytics:analytics()}:{ok:true,intelligence:revenue}};}};
  vm.createContext(portal);vm.runInContext(source,portal);await flush();
  check(urls.at(-1)==='/vp3/api/profile-revenue-intelligence.php'&&urls.at(-2)==='/vp3/api/vp3-analytics.php',`${configured?'configured':'legacy'} portal endpoints retain the application subdirectory`);
}
console.log(`PROFILE_SPECIALIST_ANALYTICS_UI_V1=PASS (${checks} checks)`);
