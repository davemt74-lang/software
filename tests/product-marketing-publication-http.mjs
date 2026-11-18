import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import vm from 'node:vm';
import {startProfileWorkspaceServer} from './fixtures/profile-specialist-workspace-server.mjs';
import {workspaceDocument} from './fixtures/profile-workspace-dom-v1.mjs';

const server=await startProfileWorkspaceServer({marketing:true});
let checks=0;const check=(value,label)=>{assert.ok(value,label);checks++;};
try{
  const login=await fetch(server.base+'/session'),session=await login.json(),cookie=login.headers.get('set-cookie').split(';')[0];
  const headers={Cookie:cookie,'Content-Type':'application/json','X-VP3-Working-Attention':session.binding};
  const selection={action:'marketing_publication',tool:'marketing',owner_key:'1',csrf_token:session.csrf,agent_id:7,conversation_id:11,
    product_id:session.seed.marketing.product_ids[0],publication_id:session.seed.marketing.publications[0],period_ref:session.seed.marketing.ref};
  const request=async changes=>{const response=await fetch(server.base+'/api/initiative-workbench-v1.php',{method:'POST',headers,body:JSON.stringify({...selection,...changes})});
    return {status:response.status,body:await response.json()};};
  const control=async action=>{const response=await fetch(server.base+'/fixture/control',{method:'POST',headers,body:JSON.stringify({action})});assert.equal(response.status,200,await response.text());};
  const first=await request();assert.equal(first.status,200,JSON.stringify(first.body)+'\n'+server.logs());
  const outcome=first.body.outcome;
  check(outcome.publication_id===selection.publication_id&&outcome.period_ref===selection.period_ref,'real selector preserves immutable publication and fixed period');
  check(outcome.original_scope.agent_id===7&&outcome.original_scope.conversation_id===11,'public analytics conversation is not original private Chat authority');
  check(outcome.metrics.find(row=>row.key==='accepted_promotion_clicks').current.value===2,'real accepted click records are aggregated in the selected period');
  check(outcome.metrics.find(row=>row.key==='verified_paid_orders').current.value===1&&outcome.money[0].current.value_minor==='2000','publication subset keeps exact verified payment proof');
  check(first.body.resume.publication_id===selection.publication_id&&first.body.resume.creative_id===outcome.creative_id,'response carries the exact historical creative marker for explicit review');
  check(!JSON.stringify(first.body).includes('review_token'),'read-only publication does not issue an approval or mutation token');
  check((await (await fetch(server.base+'/session',{headers})).json()).has_review_key===false,'query-only request does not create a private workbench review key');
  const document=workspaceDocument(),window={},context={window,document,location:{origin:server.base},URL,Date,Intl,atob,btoa};
  vm.createContext(context);vm.runInContext(readFileSync(new URL('../product-marketing-report-v1.js',import.meta.url),'utf8'),context);
  const options={chatUrl:'/chat.php',ownerId:1},host=document.createElement('section');
  check(window.VP3ProductMarketingV1.renderOutcome(host,outcome,options)&&host.textContent.includes('USD 20.00'),'real response is accepted by the production renderer');
  const seven=await request({period_ref:selection.period_ref.replace(':v1:1:',':v1:7:')});assert.equal(seven.status,200,JSON.stringify(seven.body));
  check(seven.body.outcome.associations.items.length===1&&outcome.associations.items.length===0,'literal associated question appears only inside its selected completed period');
  check(window.VP3ProductMarketingV1.renderOutcome(host,seven.body.outcome,options)&&host.querySelector('[data-product-marketing-exchange]')?.querySelector('a').href==='/profile-agent.php?tab=inbox&conversation=991002','real visitor excerpt links its exact public conversation');
  const hubResponse=await fetch(server.base+'/api/agent-activity-hub-v1.php?agent_id=7&conversation_id=11&view=marketing',{headers}),hub=await hubResponse.json();
  assert.equal(hubResponse.status,200,JSON.stringify(hub));
  check(window.VP3ProductMarketingV1.reviewModel(hub.state.product_marketing,options),'real Hub exposes a complete separately validated Product Marketing review');
  check(hub.state.product_marketing.plans.length===2&&hub.state.product_marketing.publications.length===2,'existing plans and publications appear without creating another plan');
  check(hub.state.marketing?.coverage==='unconfigured'||hub.state.marketing?.coverage==='unavailable','unavailable Campaign plugin does not hide ordinary Product Marketing');
  for(const [changes,status] of [[{product_id:992102},404],[{conversation_id:991002},403],[{publication_id:'A'.repeat(32)},422],
    [{period_ref:selection.period_ref.replace(':1:',':2:')},422],[{period:7},422],[{confirmed:true},422],[{csrf_token:'bad'},419],[{owner_key:'2'},409]]){
    const response=await request(changes);check(response.status===status&&!response.body.outcome,'invalid scope, tuple or mutation fields fail closed: '+JSON.stringify(changes));
  }
  await control('marketing_rotate');const historical=await request();assert.equal(historical.status,200,JSON.stringify(historical.body));
  check(historical.body.outcome.publication_status==='historical'&&historical.body.outcome.publication_id===selection.publication_id,'current creative republication cannot replace the original selected publication');
  check(historical.body.outcome.metrics.find(row=>row.key==='accepted_promotion_clicks').current.value===2,'historical publication retains its own fixed counts after rotation');
  check(historical.body.outcome.actions.some(action=>action.kind==='chat'&&action.message_id===session.seed.marketing.native_message_id),'recorded native outcome stays a related exact message action');
  await control('marketing_attribution_gap');const partial=await request();assert.equal(partial.status,200,JSON.stringify(partial.body));
  const payments=partial.body.outcome.metrics.find(row=>row.key==='verified_paid_orders').current;
  check(payments.value===null&&payments.coverage==='partial','missing expected attribution is partial rather than an invented zero');
  check(window.VP3ProductMarketingV1.renderOutcome(host,partial.body.outcome,options)&&host.textContent.includes('Observed subset'),'real partial attribution payload remains reviewable and labeled');
  await control('marketing_profile_revoke');const privateOnly=await request({period_ref:selection.period_ref.replace(':v1:1:',':v1:7:')});assert.equal(privateOnly.status,200,JSON.stringify(privateOnly.body));
  check(privateOnly.body.outcome.associations.coverage==='unavailable'&&privateOnly.body.outcome.associations.items.length===0,'Profile permission loss withholds all visitor excerpts while retaining ordinary Commerce read access');
  check(window.VP3ProductMarketingV1.renderOutcome(host,privateOnly.body.outcome,options)&&host.querySelector('[data-product-marketing-exchange]')===null,'fresh unavailable association removes already rendered visitor text');
  await control('marketing_remove_product');const deleted=await request();check([404,409].includes(deleted.status)&&!deleted.body.outcome,'deleted owned product removes archived publication access');
  await control('marketing_revoke');const revoked=await request();check(revoked.status===403&&!revoked.body.outcome,'current Chat permission revocation removes all publication evidence');
  check(server.readWriteFailure()==='','all production report GETs and exact publication POST reads leave SQL and inference unchanged');
  console.log('PRODUCT_MARKETING_PUBLICATION_HTTP=PASS ('+checks+' checks; real API/session/SQL, canonical DTO, historical rotation and query-only reads)');
}finally{await server.close();}
