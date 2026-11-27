(() => {
'use strict';
const esc=v=>String(v??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[c]));
function mountWorkspace(host,options={}){
if(!host||typeof options.readAnalytics!=='function'||typeof options.readIntelligence!=='function')throw new TypeError('Analytics needs its current scoped readers.');
let state=null,analyticsError='',intelligence=null,intelligenceError='',intelligenceLoadedAt=0,propertyId=0,days=30,busy=false,pendingLoad=false,pendingForce=false,timer=null;
let disposed=false,selectionGeneration=0,selectionLoading=false,analyticsController=null,intelligenceController=null;
function setNotice(message='',error=false){if(!disposed)options.onNotice?.(message,error);}
async function readBounded(reader,input,controller,label){
  let timer,onAbort;
  const aborted=()=>Object.assign(new Error('The Analytics request was cancelled.'),{name:'AbortError'});
  try{
    return await Promise.race([
      Promise.resolve().then(()=>{if(controller.signal.aborted)throw aborted();return reader({...input,signal:controller.signal});}),
      new Promise((_,reject)=>{
        onAbort=()=>reject(aborted());controller.signal.addEventListener('abort',onAbort,{once:true});
        timer=setTimeout(()=>{reject(Object.assign(new Error(`${label} took too long to respond. Choose Refresh to try again.`),{code:'PROFILE_ANALYTICS_TIMEOUT'}));controller.abort();},15000);
      }),
    ]);
  }finally{clearTimeout(timer);if(onAbort)controller.signal.removeEventListener('abort',onAbort);}
}
function parseDate(value){if(!value)return null;const d=new Date(String(value).replace(' ','T'));return Number.isNaN(d.getTime())?null:d;}
function relative(value){const d=parseDate(value);if(!d)return String(value||'');const s=Math.round((Date.now()-d.getTime())/1000);if(s<60)return 'just now';if(s<3600)return `${Math.max(1,Math.round(s/60))}m ago`;if(s<86400)return `${Math.round(s/3600)}h ago`;if(s<604800)return `${Math.round(s/86400)}d ago`;return d.toLocaleDateString();}
function moneyMinor(cents,currency){const amount=Number(cents||0),code=String(currency||'').toUpperCase();if(amount<1||!/^[A-Z]{3}$/.test(code))return '';try{return new Intl.NumberFormat(undefined,{style:'currency',currency:code}).format(amount/100);}catch(e){return `${(amount/100).toFixed(2)} ${code}`;}}
function moneyRows(rows){const list=Array.isArray(rows)?rows:[];if(!list.length)return '—';return list.map(r=>moneyMinor(r.value_cents,r.currency)).filter(Boolean).join(' · ')||'—';}
function moneySigned(cents,currency){const n=Number(cents||0);if(!n)return 'no change';const formatted=moneyMinor(Math.abs(n),currency);return formatted?`${n>0?'+':'−'}${formatted}`:'—';}
function moneyDeltaRows(rows){const list=Array.isArray(rows)?rows:[];if(!list.length)return 'no revenue change';return list.map(r=>`${moneySigned(r.delta_cents,r.currency)} vs prior`).join(' · ');}
function rate(value){return value===null||value===undefined?'—':`${Number(value).toFixed(1)}%`;}
function signed(value,suffix=''){const n=Number(value||0);return `${n>0?'+':''}${n.toLocaleString()}${suffix}`;}
function safeLink(url,label='Open'){const raw=String(url||'').trim();if(!/^https?:\/\//i.test(raw))return '';return `<a href="${esc(raw)}" target="_blank" rel="noopener">${esc(label)} ↗</a>`;}
async function loadIntelligence(force=false){
  if(disposed)return;
  if(!force&&intelligence&&Date.now()-intelligenceLoadedAt<120000)return;
  const controller=new AbortController();intelligenceController=controller;
  try{
    const data=await readBounded(options.readIntelligence,{},controller,'Profile conversion intelligence');
    if(disposed||controller.signal.aborted)return;
    if(data?.ready!==true||data?.coverage!=='complete'||!['today','7d','30d'].every(k=>data.periods?.[k]?.current))throw new Error('Profile conversion intelligence is unavailable. No totals were established.');
    intelligence=data;intelligenceLoadedAt=Date.now();intelligenceError='';
  }catch(err){if(!disposed&&(!controller.signal.aborted||err?.code==='PROFILE_ANALYTICS_TIMEOUT')){intelligence=null;if([401,403,419].includes(err?.status))throw err;intelligenceError=String(err?.message||'Profile conversion intelligence is unavailable.');}}
  finally{if(intelligenceController===controller)intelligenceController=null;}
}
async function load(silent=true){
  if(disposed)return;
  if(busy){pendingLoad=true;pendingForce=pendingForce||!silent;return;}
  busy=true;const selection={propertyId,days,generation:selectionGeneration},changed=()=>selection.generation!==selectionGeneration;
  const controller=new AbortController();analyticsController=controller;
  try{
    const data=await readBounded(options.readAnalytics,{propertyId:selection.propertyId,days:selection.days},controller,'Profile Analytics');
    if(disposed)return;
    if(changed()){pendingLoad=true;return;}
    if(data?.ready!==true||data?.coverage!=='complete')throw new Error(data?.error||'VP3 Analytics is unavailable. No totals were established.');
    if(Number(data.property_id)!==selection.propertyId||Number(data.days)!==selection.days)throw new Error('The Analytics reporting selection changed. Refresh to load this property and period.');
    await loadIntelligence(!silent);
    if(disposed)return;
    if(changed()){pendingLoad=true;return;}
    state=data;analyticsError='';selectionLoading=false;render();
    if(!silent)setNotice(intelligenceError?'VP3 Analytics refreshed; conversion intelligence is temporarily unavailable.':'VP3 Analytics and conversion intelligence refreshed.',!!intelligenceError);
  }catch(err){
    if(disposed)return;
    if(changed())pendingLoad=true;
    else{state=null;intelligence=null;selectionLoading=false;analyticsError=String(err?.message||'VP3 Analytics is unavailable.');render();setNotice(analyticsError,true);}
  }finally{
    if(analyticsController===controller)analyticsController=null;
    busy=false;
    if(!disposed&&pendingLoad){const force=pendingForce;pendingLoad=false;pendingForce=false;await load(!force);}
  }
}
function reportingAsOf(window){return window?.to&&window?.zone?`As of ${window.to}; database timezone ${window.zone}. Period comparisons use the windows shown below.`:'';}
function reportingWindow(window){return window?.from&&window?.to&&window?.zone?`Reporting window: ${window.from} through ${window.to}; database timezone ${window.zone}.`:'';}
function propertyOptions(){const rows=Array.isArray(state?.properties)?state.properties:[];return `<option value="0"${propertyId===0?' selected':''}>All properties</option>`+rows.map(p=>`<option value="${Number(p.id)}"${Number(p.id)===propertyId?' selected':''}>${esc(p.property_type==='native'?'VP3 Profile':p.label||p.domain)}</option>`).join('');}
function metric(label,value,sub=''){return `<article class="vp3-analytics-metric"><strong>${Number(value||0).toLocaleString()}</strong><span>${esc(label)}</span>${sub?`<small>${esc(sub)}</small>`:''}</article>`;}
function timeline(){
  const rows=(Array.isArray(state?.timeline)?state.timeline:[]).filter(r=>Number(r.page_views||0)+Number(r.native_visit_events||0)>0);
  if(!rows.length)return '<div class="vp3-analytics-empty">No page-view or Profile-visit events in this period.</div>';
  const max=Math.max(1,...rows.map(r=>Number(r.page_views||0)+Number(r.native_visit_events||0)));
  return `<div class="vp3-analytics-timeline">${rows.map(r=>{const pages=Number(r.page_views||0),visits=Number(r.native_visit_events||0),height=Math.max(4,Math.round((pages+visits)/max*100));return `<div class="vp3-analytics-day" title="${esc(r.day)} · ${pages} page-view events · ${visits} Profile-visit events"><div class="vp3-analytics-bar" style="height:${height}%"><span></span></div><small>${esc(String(r.day||'').slice(5))}</small></div>`;}).join('')}</div>`;
}
function listRows(rows,labelKey,valueKey,empty){return rows?.length?rows.map((r,i)=>`<div class="vp3-analytics-rank"><span>${i+1}</span><strong>${esc(r[labelKey]||'Direct')}</strong><b>${Number(r[valueKey]||0).toLocaleString()}</b></div>`).join(''):`<div class="vp3-analytics-empty">${esc(empty)}</div>`;}
function propertyCards(){
  const rows=Array.isArray(state?.property_stats)?state.property_stats:[];
  return rows.length?rows.map(p=>`<article class="vp3-analytics-property"><div><span>${p.property_type==='native'?'VP3 Profile':'Connected site'}</span><strong>${esc(p.label||p.domain||'Property')}</strong><small>${esc(p.domain||'vp3.me profile')}</small>${p.property_type==='native'?`<small>${p.native_visit_events===null?'Profile visits unavailable':Number(p.native_visit_events||0).toLocaleString()+' recorded Profile visits'}</small>`:''}</div><div><b>${Number(p.page_views||0).toLocaleString()}</b><span>page-view events</span></div><div><b>${Number(p.sessions||0).toLocaleString()}</b><span>Radar sessions</span></div><div><b>${Number(p.agent_sessions||0).toLocaleString()}</b><span>automated sessions</span></div></article>`).join(''):'<div class="vp3-analytics-empty">No properties yet.</div>';
}
function distribution(rows){if(!rows?.length)return '<div class="vp3-analytics-empty">No browser analytics yet.</div>';const total=Math.max(1,rows.reduce((n,r)=>n+Number(r.count||0),0));return rows.map(r=>`<div class="vp3-analytics-distribution"><div><strong>${esc(r.label)}</strong><span>${Math.round(Number(r.count||0)/total*100)}%</span></div><div><i style="width:${Math.max(2,Math.round(Number(r.count||0)/total*100))}%"></i></div><b>${Number(r.count||0).toLocaleString()}</b></div>`).join('');}
function activity(){const rows=Array.isArray(state?.events)?state.events:[];if(!rows.length)return '<div class="vp3-analytics-empty">No recent tracked activity.</div>';return rows.slice(0,60).map(e=>{const webmcp=String(e.event_type||'').startsWith('webmcp_'),agent=Number(e.agent_contact_id||0)>0,name=agent?`${e.operator_name?e.operator_name+' · ':''}${e.display_name||'Automated agent'}`:webmcp?'WebMCP agent':'Anonymous visitor',event=webmcp?(e.tool||String(e.event_type||'webmcp').replaceAll('_',' ')):(e.event_name||String(e.event_type||'activity').replaceAll('_',' ')),suffix=webmcp?`${e.webmcp_status?' · '+e.webmcp_status:''}${Number(e.duration_ms||0)>0?' · '+Number(e.duration_ms)+'ms':''}`:`${agent?' · risk '+Number(e.risk_score||0)+'/100':''}`;return `<article class="vp3-analytics-event ${webmcp||agent?'agent':'human'} ${Number(e.risk_score||0)>=70?'high':''}"><span></span><div><strong>${esc(name)}</strong><p>${esc(event)} · ${esc(e.path||'/')}</p><small>${esc(e.property_label||'VP3')} · ${esc(relative(e.occurred_at))}${esc(suffix)}</small></div><b>${webmcp?'WebMCP':agent?'Agent':'Human'}</b></article>`;}).join('');}
function funnelCard(key,label){const period=intelligence?.periods?.[key],c=period?.current||{},change=period?.change||{};if(!period)return '';return `<article class="profile-revenue-period"><header><strong>${esc(label)}</strong><span>${esc(key==='today'?'vs yesterday':`vs previous ${label.toLowerCase()}`)}</span></header><div class="profile-revenue-period-metrics"><div><b>${Number(c.views||0).toLocaleString()}</b><span>Profile visits</span></div><div><b>${Number(c.intents||0).toLocaleString()}</b><span>destination visits</span></div><div><b>${Number(c.conversions||0).toLocaleString()}</b><span>conversions</span></div><div><b>${esc(rate(c.conversion_rate))}</b><span>outcomes / destination visits</span></div></div><footer><span>Revenue ${esc(moneyRows(c.revenue))} · ${esc(moneyDeltaRows(change.revenue))}</span><span>Booking ${esc(moneyRows(c.booking_revenue))} · Products ${esc(moneyRows(c.product_revenue))}</span><span>${esc(signed(change.conversions?.delta||0))} conversions · ${esc(signed(change.conversion_rate_points||0,' pts'))}</span></footer></article>`;}
function targetRows(){const rows=Array.isArray(intelligence?.top_targets)?intelligence.top_targets:[];if(!rows.length)return '<div class="vp3-analytics-empty">No Profile product or booking conversion data yet.</div>';return rows.map((r,i)=>`<article class="profile-revenue-target"><span class="profile-revenue-rank">${i+1}</span><div><strong>${esc(r.target_title||r.kind||'Offer')}</strong><small>${esc(r.kind==='booking'?'Booking':'Product')} · ${Number(r.intents||0).toLocaleString()} intents · ${Number(r.conversions||0).toLocaleString()} conversions</small></div><div><b>${esc(rate(r.conversion_rate))}</b><small>${esc(moneyRows(r.revenue))}</small></div>${safeLink(r.target_url,'Open')}</article>`).join('');}
function sourceRows(){const rows=Array.isArray(intelligence?.sources)?intelligence.sources:[];if(!rows.length)return '<div class="vp3-analytics-empty">No attributable Profile conversion sources yet.</div>';return rows.map((r,i)=>`<article class="profile-revenue-source"><span>${i+1}</span><div><strong>${esc(r.label||'Direct')}</strong><small>${Number(r.intents||0).toLocaleString()} intents · ${Number(r.conversions||0).toLocaleString()} conversions</small></div><b>${esc(rate(r.conversion_rate))}</b><small>${esc(moneyRows(r.revenue))}</small></article>`).join('');}
function opportunityRows(){const rows=Array.isArray(intelligence?.opportunities)?intelligence.opportunities:[];if(!rows.length)return '<div class="vp3-analytics-empty">No conversion opportunity has crossed a reliable threshold yet.</div>';return rows.map(r=>`<article class="profile-revenue-opportunity ${esc(r.priority||'medium')}"><span>${esc(r.priority==='positive'?'Winner':r.priority==='high'?'Priority':'Watch')}</span><div><strong>${esc(r.headline||'Conversion opportunity')}</strong><p>${esc(r.summary||'')}</p><small>${esc(r.action_label||'Review this offer')}</small></div>${safeLink(r.target_url,'Review')}</article>`).join('');}
function insightRows(){const rows=Array.isArray(intelligence?.insights)?intelligence.insights:[];return rows.length?rows.map(r=>`<div class="profile-revenue-insight"><span>${esc(r.type||'insight')}</span><p>${esc(r.message||'')}</p></div>`).join(''):'<div class="vp3-analytics-empty">VP3 is still learning from Profile intent and conversion activity.</div>';}
function revenueIntelligence(){if(intelligenceError)return `<section class="profile-revenue-shell"><div class="vp3-analytics-empty">${esc(intelligenceError)}</div></section>`;if(!intelligence)return '<section class="profile-revenue-shell"><div class="vp3-analytics-empty">Loading Profile conversion intelligence…</div></section>';const p30=intelligence.periods?.['30d']?.current||{};return `<section class="profile-revenue-shell"><header class="profile-revenue-head"><div><span>Profile Revenue Intelligence</span><h2>From Profile traffic to real business</h2><p>Recorded Profile visits, product and booking destination visits, confirmed outcomes and their recorded values. Sources use stored first-party attribution and values stay separated by currency.</p><small>${esc(reportingAsOf(intelligence.reporting_window))}</small></div><div class="profile-revenue-total"><small>Tracked revenue · all time</small><strong>${esc(moneyRows(intelligence.revenue_all_time))}</strong><span>Booking ${esc(moneyRows(intelligence.booking_revenue_all_time))}</span><span>Products ${esc(moneyRows(intelligence.product_revenue_all_time))}</span><span>${Number(p30.conversions||0).toLocaleString()} conversions in the last 30 days</span></div></header><div class="profile-revenue-periods">${funnelCard('today','Today')}${funnelCard('7d','7 days')}${funnelCard('30d','30 days')}</div><div class="profile-revenue-grid"><section><div class="vp3-analytics-section-head"><span>Offers</span><h3>Top converting products + bookings</h3></div>${targetRows()}</section><section><div class="vp3-analytics-section-head"><span>Acquisition</span><h3>Conversion sources</h3></div>${sourceRows()}</section></div><div class="profile-revenue-grid"><section><div class="vp3-analytics-section-head"><span>Agent Intelligence</span><h3>Opportunities</h3></div>${opportunityRows()}</section><section><div class="vp3-analytics-section-head"><span>What VP3 sees</span><h3>Conversion insights</h3></div>${insightRows()}</section></div><footer class="vp3-analytics-privacy">Profile conversion intelligence uses the existing first-party Profile event ledger. Recorded conversion values are historical snapshots, not net revenue after refunds. Profile visits are deduplicated around a 30-minute boundary; destination visits do not establish purchase intent. Conversion rates compare outcome events with destination events inside the same period; they are not cross-site person-level cohorts. Suggested actions are advisory until you explicitly choose to act.</footer></section>`;}
function render(){if(disposed)return;if(selectionLoading){host.innerHTML=`<section class="vp3-analytics-shell"><div class="vp3-analytics-controls"><select aria-label="Analytics property" data-analytics-property>${propertyOptions()}</select><select aria-label="Reporting period" data-analytics-days>${[7,30,90].map(value=>`<option value="${value}"${days===value?' selected':''}>${value} days</option>`).join('')}</select></div><div class="vp3-analytics-empty" role="status">Loading the selected property and period…</div></section>`;bind();return;}if(!state){host.innerHTML=analyticsError?`<div class="vp3-analytics-empty" role="status">${esc(analyticsError)}</div><button type="button" data-analytics-refresh>Retry analytics</button>`:'<div class="vp3-analytics-empty">Loading VP3 Analytics…</div>';bind();return;}const s=state.stats||{};host.innerHTML=`${revenueIntelligence()}<section class="vp3-analytics-shell"><header class="vp3-analytics-head"><div><span>VP3 Analytics</span><h2>Traffic across your VP3 presence</h2><p>Your VP3 profile and connected websites share one private analytics view. Radar sessions, page-view events and Profile visit events are reported separately. Sessions and recognized browsers are not unique people.</p><small>${esc(reportingWindow(state.reporting_window))}</small></div><div class="vp3-analytics-controls"><select aria-label="Analytics property" data-analytics-property>${propertyOptions()}</select><select aria-label="Reporting period" data-analytics-days><option value="7"${days===7?' selected':''}>7 days</option><option value="30"${days===30?' selected':''}>30 days</option><option value="90"${days===90?' selected':''}>90 days</option></select><button type="button" data-analytics-refresh>Refresh</button></div></header>${state.native_profile_coverage==='unconfigured'?'<div class="vp3-analytics-empty" role="status">Profile visit totals are not available yet. Existing website metrics are shown below.</div>':''}<div class="vp3-analytics-metrics">${metric('Radar sessions',s.sessions,`${Number(s.human_sessions||0)} classified human · ${Number(s.agent_sessions||0)} automated · ${Number(s.unclassified_sessions||0)} unclassified; last active in this window`)}${metric('Page-view events',s.page_views,`${Number(s.human_page_views||0)} browser · ${Number(s.agent_page_views||0)} automated`)}${state.native_included?metric('Profile visits',s.native_visit_events,`${Number(s.native_browser_contacts||0)} recognized browser contacts; visits deduplicated around 30 minutes`):''}${metric('Conversion events',s.conversions,'Collected browser events, not verified paid orders')}${metric('WebMCP calls',s.webmcp_tool_calls,`${Number(s.webmcp_completed||0)} complete · ${Number(s.webmcp_failed||0)} failed · ${Number(s.webmcp_denied||0)} denied`)}${metric('Custom events',s.custom_events)}${metric('High-risk events',s.high_risk_events)}</div><div class="vp3-analytics-grid wide"><section><div class="vp3-analytics-section-head"><span>Traffic</span><h3>Recorded traffic events over time</h3></div>${timeline()}</section><section><div class="vp3-analytics-section-head"><span>Properties</span><h3>Performance by property</h3></div><div class="vp3-analytics-properties">${propertyCards()}</div></section></div><div class="vp3-analytics-grid"><section><div class="vp3-analytics-section-head"><span>Content</span><h3>Top pages</h3><small>Page-view events only; Profile visits are shown separately</small></div>${listRows(state.top_pages,'path','events','No page activity yet.')}</section><section><div class="vp3-analytics-section-head"><span>Acquisition</span><h3>Referrers</h3><small>Stored Radar sessions last active in this window</small></div>${listRows(state.referrers,'referrer_host','sessions','No external referrers yet.')}</section><section><div class="vp3-analytics-section-head"><span>Devices</span><h3>Device mix</h3><small>Share of collected browser events</small></div>${distribution(state.devices)}</section><section><div class="vp3-analytics-section-head"><span>Browsers</span><h3>Browser mix</h3><small>Share of collected browser events</small></div>${distribution(state.browsers)}</section></div><section class="vp3-analytics-activity"><div class="vp3-analytics-section-head"><span>Recent</span><h3>Human + Agent + WebMCP activity</h3></div>${activity()}</section><footer class="vp3-analytics-privacy">Strict privacy mode: VP3 Analytics stores no raw visitor IP address, no fingerprint, and no persistent cross-site human identity. Native period page-view totals cannot be recovered from lifetime browser-contact counters; recorded Profile visits are shown instead. Geography is intentionally unavailable until a connected site or trusted edge provides coarse location without exposing raw IP data.</footer></section>`;bind();}
function changeSelection(){
  if(disposed)return;
  selectionGeneration++;selectionLoading=true;analyticsController?.abort();intelligenceController?.abort();render();load(true);
}
function bind(){host.querySelector('[data-analytics-property]')?.addEventListener('change',e=>{propertyId=Math.max(0,Number(e.target.value||0));changeSelection();});host.querySelector('[data-analytics-days]')?.addEventListener('change',e=>{days=[7,30,90].includes(Number(e.target.value))?Number(e.target.value):30;changeSelection();});host.querySelector('[data-analytics-refresh]')?.addEventListener('click',()=>load(false));}
function dispose(){
  if(disposed)return;
  disposed=true;selectionGeneration++;pendingLoad=false;pendingForce=false;
  analyticsController?.abort();intelligenceController?.abort();clearInterval(timer);
  if(options.poll===true)window.removeEventListener?.('beforeunload',dispose);
  state=null;intelligence=null;host.innerHTML='';
}
render();load(true);
if(options.poll===true){timer=setInterval(()=>{if(!disposed&&document.visibilityState==='visible')load(true);},30000);window.addEventListener('beforeunload',dispose);}
return {refresh:()=>load(false),dispose};
}
window.VP3ProfileAnalytics=Object.freeze({mountWorkspace});
const cfg=window.PROFILE_AGENT_PORTAL,host=document.getElementById('profileAgentAnalytics');
if(cfg?.analyticsEndpoint&&host){
  const notice=document.getElementById('profileAgentNotice');
  const analyticsEndpoint=new URL(cfg.analyticsEndpoint,window.location.origin);
  const revenueEndpoint=new URL(cfg.revenueEndpoint||'profile-revenue-intelligence.php',analyticsEndpoint);
  async function read(url,key,signal){
    const response=await fetch(url,{credentials:'same-origin',cache:'no-store',signal});
    const data=await response.json().catch(()=>null);
    if(!response.ok||data?.ok!==true)throw Object.assign(new Error(data?.error||'Profile Analytics is unavailable.'),{status:response.status});
    return data[key];
  }
  mountWorkspace(host,{
    poll:true,
    readAnalytics:({propertyId,days,signal})=>{const url=new URL(analyticsEndpoint);url.searchParams.set('property_id',String(propertyId));url.searchParams.set('days',String(days));return read(url,'analytics',signal);},
    readIntelligence:({signal})=>read(revenueEndpoint,'intelligence',signal),
    onNotice:(message,error)=>{if(notice){notice.textContent=message;notice.className=`profile-agent-notice${error?' error':''}`;}},
  });
}
})();
