import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import vm from 'node:vm';
import {webcrypto} from 'node:crypto';
import {startProfileWorkspaceServer} from './fixtures/profile-specialist-workspace-server.mjs';
import {workspaceDocument} from './fixtures/profile-workspace-dom-v1.mjs';

// Real workspace endpoint, session and SQL; only the browser DOM is replaced.
const server=await startProfileWorkspaceServer({analytics:true});
const source=readFileSync(new URL('../profile-specialist-workspace-v1.js',import.meta.url),'utf8');
let checks=0;
const check=(value,label)=>{assert.ok(value,label);checks++;};
try{
  const login=await fetch(server.base+'/session'),session=await login.json();
  const cookie=login.headers.get('set-cookie').split(';')[0];
  const headers={Cookie:cookie,'X-VP3-Working-Attention':session.binding};
  const request=async input=>{
    const url=new URL('/api/profile-specialist-workspace-v1.php',server.base);
    for(const [key,value] of Object.entries({action:'analytics_period',chat_agent_id:7,chat_conversation_id:11,...input}))url.searchParams.set(key,String(value));
    const response=await fetch(url,{headers});return {status:response.status,body:await response.json()};
  };
  const first=await request({ref:session.seed.analytics.ref});
  assert.equal(first.status,200,JSON.stringify(first.body));
  const result=first.body.data.analytics_period;
  check(result.ref===session.seed.analytics.ref&&result.window.to_utc_ms===session.seed.analytics.cutoff*1000,'real endpoint preserves the exact historical period');
  check(result.metrics.find(row=>row.key==='visit_records').current.value===3,'real timed visits aggregate all three saved records');
  check(result.metrics.find(row=>row.key==='verified_paid_orders').current.value===2,'verified payment completions remain separate from collected conversion events');
  check(result.money.some(row=>row.currency==='USD')&&result.money.some(row=>row.currency==='EUR'),'real ledger keeps currencies separate');
  check(result.backlog.metrics.some(row=>row.key==='current_unanswered_visitor_turns'),'current unanswered requests have a separate backlog measure');
  const document=workspaceDocument(),host=document.createElement('div');document.body.append(host);
  const scope={owner:1,agent:7,cid:11,binding:session.binding,hasChat:true};
  const window={VP3_PROFILE_WORKSPACE_V1:{endpoint:'/api/profile-specialist-workspace-v1.php',csrf:session.csrf,chatUrl:'/chat.php'},dispatchEvent(){},addEventListener(){},removeEventListener(){}};
  const context={window,document,location:{origin:server.base},URL,Date,Intl,AbortController,Event,crypto:webcrypto,atob,btoa,setTimeout,clearTimeout,
    fetch:(url,options)=>fetch(url,{...options,headers:{...options.headers,Cookie:cookie}})};
  vm.createContext(context);vm.runInContext(source,context);
  const view=window.VP3ProfileWorkspaceV1.mountWorkspace(host,{scope:()=>scope,selection:{tab:'analytics',analyticsRef:result.ref}});
  const until=async predicate=>{for(let attempt=0;!predicate();attempt++){if(attempt>150)throw new Error('Mounted real response did not settle: '+host.textContent);await new Promise(resolve=>setTimeout(resolve,20));}};
  await until(()=>Boolean(host.querySelector('[data-profile-analytics-period]')));
  check(host.querySelector('[data-profile-analytics-period]').dataset.profileAnalyticsPeriod===result.ref,'production renderer accepts the production partial-coverage response');
  check(host.textContent.includes('USD 20.00')&&host.textContent.includes('EUR 15.00'),'real ledger amounts render with saved scale');
  check(host.textContent.includes('Current unanswered visitor requests'),'production backlog measure is visible');
  const control=async action=>{const response=await fetch(server.base+'/fixture/control',{method:'POST',headers:{...headers,'Content-Type':'application/json'},body:JSON.stringify({action})});assert.equal(response.status,200,await response.text());};
  await control('profile_off');await view.refresh();
  check(host.querySelector('[data-profile-analytics-period]')!==null,'private owner analytics survives public Profile disablement');
  await control('analytics_events_off');await view.refresh();
  const visits=host.querySelector('[data-profile-analytics-metric="visit_records"]');
  check(visits?.textContent.includes('Unavailable')&&!visits.textContent.includes('3'),'missing event storage removes prior totals instead of rendering a false zero');
  check(host.textContent.includes('USD 20.00'),'independent verified commerce evidence survives an event outage');
  await control('analytics_events_restore');
  check((await request({ref:result.ref,period:7})).status===422,'ambiguous fixed-ref and period inputs rejected');
  check((await request({ref:result.ref,chat_conversation_id:12})).status===403,'fixed source cannot cross private Agent and Chat authority');
  await control('analytics_revoke');await view.refresh();
  check(!host.textContent.includes('USD 20.00')&&host.textContent.includes('Reload Chat'),'current authority loss clears already rendered measurements');
  check(server.readWriteFailure()==='','every production GET keeps storage unchanged and starts no provider');
  view.dispose();
  console.log('PROFILE_ANALYTICS_PERIOD_HTTP=PASS ('+checks+' checks; production API, session, SQL and mounted renderer)');
}finally{await server.close();}
