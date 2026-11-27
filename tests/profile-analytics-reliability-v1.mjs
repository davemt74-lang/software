import assert from 'node:assert/strict';
import fs from 'node:fs';
import vm from 'node:vm';

const source=fs.readFileSync(new URL('../profile-agent-analytics.js',import.meta.url),'utf8');
let checks=0;
const check=(value,label)=>{assert.ok(value,label);checks++;console.log(`PASS ${label}`);};
const flush=async()=>{for(let i=0;i<12;i++)await new Promise(resolve=>setImmediate(resolve));};
const deferred=()=>{let resolve;const promise=new Promise(done=>{resolve=done;});return {promise,resolve};};
const response=(payload,ok=true)=>({ok,json:async()=>structuredClone(payload)});
const period={current:{views:2,intents:3,conversions:1,conversion_rate:33.3,revenue:[{currency:'usd',value_cents:1200}],booking_revenue:[],product_revenue:[]},change:{conversions:{delta:1},conversion_rate_points:2,revenue:[]}};
const intelligence={ready:true,coverage:'complete',periods:{today:period,'7d':period,'30d':period},revenue_all_time:[{currency:'usd',value_cents:1200}],booking_revenue_all_time:[],product_revenue_all_time:[],top_targets:[],sources:[],opportunities:[],insights:[],reporting_window:{from:'2026-09-19 12:00:00',to:'2026-11-18 12:00:00',zone:'UTC; UTC+00:00 at read'}};
function analytics(url){
  const property=Number(url.searchParams.get('property_id')),days=Number(url.searchParams.get('days'));
  return {ready:true,coverage:'complete',property_id:property,days,native_included:property===0,
    reporting_window:{from:days===7?'2026-11-11 12:00:00':'2026-10-19 12:00:00',to:'2026-11-18 12:00:00',zone:'UTC; UTC+00:00 at read'},
    properties:[{id:11,property_type:'external',label:'<img src=x onerror=alert(1)>',domain:'fixture.example'}],
    stats:{sessions:2,human_sessions:1,agent_sessions:1,unclassified_sessions:0,page_views:4,human_page_views:3,agent_page_views:1,native_visit_events:2,native_browser_contacts:1,conversions:1301,custom_events:property*100+days,high_risk_events:1300,webmcp_tool_calls:1250,webmcp_completed:1,webmcp_failed:1,webmcp_denied:1},
    timeline:[{day:'2026-11-18',page_views:4,native_visit_events:2,native_views:999999,agent_sessions:100}],
    property_stats:[{id:13,label:'VP3 Profile',property_type:'native',native_visit_events:2,native_browser_contacts:1,page_views:1,sessions:1,agent_sessions:1}],
    top_pages:[],referrers:[],devices:[{label:'Desktop',count:1304}],browsers:[{label:'Chrome',count:1304}],events:[]};
}
const listeners=new Map(),renders=[];
const host={
  _html:'',get innerHTML(){return this._html;},set innerHTML(value){this._html=value;renders.push(value);},
  querySelector(selector){
    const key=selector.slice(1,-1);if(!this._html.includes(key))return null;
    return {addEventListener(type,callback){listeners.set(`${key}:${type}`,callback);}};
  },
};
const notice={textContent:'',className:''};
let revenueResponse=response({ok:true,intelligence}),nextAnalytics=null;
const requests=[];
const context={
  URL,Date,Intl,console,AbortController,
  window:{PROFILE_AGENT_PORTAL:{analyticsEndpoint:'/api/vp3-analytics.php'},location:{origin:'https://fixture.example'},addEventListener(){}},
  document:{visibilityState:'visible',getElementById(id){return id==='profileAgentAnalytics'?host:id==='profileAgentNotice'?notice:null;}},
  setInterval(){return 1;},clearInterval(){},
  setTimeout,clearTimeout,
  async fetch(raw){
    const url=new URL(raw);requests.push(url);
    if(url.pathname==='/api/profile-revenue-intelligence.php')return revenueResponse;
    if(nextAnalytics){const take=nextAnalytics;nextAnalytics=null;return take(url);}
    return response({ok:true,analytics:analytics(url)});
  },
};
vm.createContext(context);
// Exercise the actual Refresh control; the renderer is not instrumented.
vm.runInContext(source,context);
const refresh=async()=>{listeners.get('data-analytics-refresh:click')();await flush();};
await flush();
check(host.innerHTML.includes('Radar sessions')&&host.innerHTML.includes('Page-view events')&&host.innerHTML.includes('Profile visits'),'dashboard distinguishes Radar sessions, page views and native visits');
check(host.innerHTML.includes('4 page-view events · 2 Profile-visit events')&&!host.innerHTML.includes('999999')&&!host.innerHTML.includes('999,999'),'timeline ignores historical native_views payloads and labels both event types');
check(host.innerHTML.includes('2 recorded Profile visits')&&host.innerHTML.includes('page-view events'),'property cards do not combine visits and page views');
check(host.innerHTML.includes('Collected browser events, not verified paid orders')&&host.innerHTML.includes('Share of collected browser events'),'conversion and device labels state what was actually measured');
check(host.innerHTML.includes('historical snapshots, not net revenue after refunds'),'revenue explains the stored conversion-value boundary');
check(host.innerHTML.includes('database timezone UTC; UTC+00:00 at read'),'reporting timestamps and timezone are visible');
check(!host.innerHTML.includes('<img src=x')&&host.innerHTML.includes('&lt;img src=x'),'property labels remain escaped untrusted data');

revenueResponse=response({ok:true,intelligence:{periods:[],revenue_all_time:[]}});
await refresh();
check(host.innerHTML.includes('Profile conversion intelligence is unavailable. No totals were established.')&&!host.innerHTML.includes('0 conversions in the last 30 days'),'legacy empty revenue success cannot render invented zero totals');
revenueResponse=response({ok:false,error:'Profile conversion intelligence could not be loaded.',coverage:'unavailable'},false);
await refresh();
check(host.innerHTML.includes('Profile conversion intelligence could not be loaded.')&&!host.innerHTML.includes('From Profile traffic to real business'),'an unavailable revenue response replaces previous revenue totals');
revenueResponse=response({ok:true,intelligence});
await refresh();
check(host.innerHTML.includes('From Profile traffic to real business'),'revenue recovers after a successful verified read');

nextAnalytics=async()=>response({ok:false,error:'Analytics source unavailable.'},false);
await refresh();
check(host.innerHTML.includes('Analytics source unavailable.')&&!host.innerHTML.includes('vp3-analytics-metric')&&host.innerHTML.includes('Retry analytics'),'traffic failure replaces stale totals with an explicit retry state');
await refresh();
check(host.innerHTML.includes('vp3-analytics-metric'),'traffic recovers from its retry state');

const pending=deferred();let oldRequest;const before=renders.length;
nextAnalytics=url=>{oldRequest=url;return pending.promise;};
listeners.get('data-analytics-days:change')({target:{value:'7'}});
await flush();
check(oldRequest.searchParams.get('days')==='7'&&oldRequest.searchParams.get('property_id')==='0','first request captures its original reporting selection');
listeners.get('data-analytics-property:change')({target:{value:'11'}});
const stale=analytics(oldRequest);stale.stats.page_views=987654;
pending.resolve(response({ok:true,analytics:stale}));
await flush();
check(!renders.slice(before).some(html=>html.includes('987,654')),'a deferred response for the old property never renders under the new selection');
check(requests.some(url=>url.pathname==='/api/vp3-analytics.php'&&url.searchParams.get('property_id')==='11'&&url.searchParams.get('days')==='7'),'a changed selection queues the latest property and period');
check(host.innerHTML.includes('value="11" selected')&&host.innerHTML.includes('value="7" selected')&&host.innerHTML.includes('1,107'),'rendered controls and returned totals belong to the same latest selection');
check(!/vp3-analytics-metric"><strong>[^<]*<\/strong><span>Profile visits<\/span>/.test(host.innerHTML),'an external property does not present unrelated native visit totals');

nextAnalytics=async url=>{const wrong=analytics(url);wrong.property_id=0;return response({ok:true,analytics:wrong});};
await refresh();
check(host.innerHTML.includes('The Analytics reporting selection changed.')&&!host.innerHTML.includes('vp3-analytics-metric'),'an unexpected server selection cannot be rendered as requested totals');
await refresh();
const pendingError=deferred();nextAnalytics=()=>pendingError.promise;
listeners.get('data-analytics-days:change')({target:{value:'30'}});
await flush();listeners.get('data-analytics-days:change')({target:{value:'7'}});
pendingError.resolve(response({ok:false,error:'Stale request failed.'},false));await flush();
check(!host.innerHTML.includes('Stale request failed.')&&host.innerHTML.includes('value="7" selected'),'a failed obsolete request cannot replace the latest selection with its error');
console.log(`PROFILE_ANALYTICS_RELIABILITY_UI_V1=PASS (${checks} cases; production renderer in Node VM)`);
