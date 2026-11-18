import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import vm from 'node:vm';
import {workspaceDocument,WorkspaceElement} from './fixtures/profile-workspace-dom-v1.mjs';
import {publication,publicationId,publicationUrl,periodRef,review,plan} from './fixtures/product-marketing-ui-v1.mjs';

const report=readFileSync(new URL('../product-marketing-report-v1.js',import.meta.url),'utf8');
const media=readFileSync(new URL('../chat-media-commands-v1.js',import.meta.url),'utf8');
const chat=readFileSync(new URL('../chat.js',import.meta.url),'utf8');
const prelude=chat.slice(chat.indexOf('\n')+1,chat.indexOf("  const thread = document.getElementById('chatThread');"));
const identity=chat.slice(chat.indexOf('  function initiativeIdentityMatchesV1()'),chat.indexOf('  function validInitiativeReplyV1('));
const remember=chat.slice(chat.indexOf('  function rememberConversation()'),chat.indexOf('  function initialConversation()'));
const getter=chat.split('\n').find(line=>line.trimStart().startsWith('mediaCommandIntent:'));
let checks=0;const check=(value,label)=>{assert.ok(value,label);checks++;};
const tick=()=>new Promise(resolve=>setImmediate(resolve));
WorkspaceElement.prototype.showModal=function(){this.open=true;};
WorkspaceElement.prototype.hasAttribute=function(name){return this.attributes.has(name);};
WorkspaceElement.prototype.getClientRects=function(){return this.isConnected?[{}]:[];};
const setAttribute=WorkspaceElement.prototype.setAttribute;
WorkspaceElement.prototype.setAttribute=function(name,value){setAttribute.call(this,name,value);if(name.startsWith('data-'))this.dataset[name.slice(5).replace(/-([a-z])/g,(_,letter)=>letter.toUpperCase())]=String(value);};
function fixture({query=publicationUrl().slice('/chat.php'.length),ready=true,bridge=false}={}){
  const document=workspaceDocument(),events=new Map(),requests=[],launches=[],ticks=new Set();
  const state={owner:1,agent:7,cid:11,ready,revision:1,valid:true};
  const location={origin:'https://example.test',href:'https://example.test/chat.php'+query,search:query};
  const window={STONEFELLOW_CHAT:{userId:1,agentId:7,workingAttentionBinding:'binding',csrf:'csrf',endpoint:'/api/chat-v236.php'},
    VP3_INITIATIVE_WORKBENCH:{ownerKey:'1',agentId:7,csrf:'csrf',endpoint:'/api/initiative-workbench-v1.php',mediaEndpoint:'/api/media-library-v86.php'},
    VP3InitiativeWorkbench:{openProductLaunch:marker=>{launches.push(marker);return true;}},
    addEventListener(name,fn){if(!events.has(name))events.set(name,new Set());events.get(name).add(fn);},
    dispatchEvent(event){for(const fn of [...(events.get(event.type)||[])])fn(event);},
    setInterval(fn){ticks.add(fn);return fn;},history:{replaceState(_s,_t,href){const u=new URL(href,location.origin);location.href=u.href;location.search=u.search;}}};
  const continuity={mediaCommandContext:()=>{
    if(!state.ready||!state.valid)return null;
    const bound={ownerKey:String(state.owner),agentId:state.agent,conversationId:state.cid,revision:state.revision};
    bound.isCurrent=()=>state.ready&&state.valid&&state.owner===Number(bound.ownerKey)&&state.agent===bound.agentId&&state.cid===bound.conversationId&&state.revision===bound.revision;return bound;
  }};window.STONEFELLOW_CHAT_CONTINUITY_V87=continuity;
  const context={window,document,location,URL,URLSearchParams,Date,Intl,atob,btoa,AbortController,Event,setTimeout,clearTimeout,clearInterval:fn=>ticks.delete(fn),
    history:document.createElement('div'),contextReady:true,conversationId:11,selectionKey:'selected',localStorage:{setItem(){}},
    fetch:async(_url,options)=>{const body=JSON.parse(options.body);let resolve;const response=new Promise(r=>{resolve=r;});requests.push({body,signal:options.signal,resolve});return response;}};
  vm.createContext(context);vm.runInContext(report,context);
  if(bridge){vm.runInContext(prelude+identity+remember+'\nwindow.remember=rememberConversation;window.mediaIntent=({'+getter+'}).mediaCommandIntent;',context);continuity.mediaCommandIntent=window.mediaIntent;}
  const load=()=>vm.runInContext(media,context);
  const respond=async(index=0,{status=200,value=publication()}={})=>{const request=requests[index];assert.ok(request,'request exists');request.resolve({ok:status===200,status,json:async()=>status===200?{ok:true,owner_key:'1',agent_id:7,conversation_id:11,tool:'marketing',outcome:value,resume:value.resume}:{ok:false,error:'Source unavailable'}});await tick();};
  return {window,document,context,location,state,requests,launches,load,respond,ticks,renderer:window.VP3ProductMarketingV1,event:(type,detail)=>window.dispatchEvent({type,detail}),host:()=>document.body.querySelector('[data-media-command="publication"]')};
}
{
  const f=fixture(),api=f.renderer,options={chatUrl:'/chat.php',ownerId:1};
  check(api.outcomeModel(publication(),options),'complete fixed publication DTO accepted');
  check(api.planModel(plan(),options)&&api.reviewModel(review(),options),'existing plan and Product Marketing projection accepted');
  for(const days of [1,7,30])check(api.outcomeModel(publication(days),options),'completed period '+days+' preserves prior and sibling bounds');
  for(const change of [r=>r.product_id++,r=>r.publication_id='b'.repeat(32),r=>r.original_scope.conversation_id=991002,r=>r.resume.owner_user_id=2,
    r=>r.window.previous.to_utc_ms++,r=>r.window.from_local='2026-11-16T00:00:00+01:00',r=>r.period_options[1].ref=periodRef(1),
    r=>r.metrics[0].comparison.delta=99,r=>r.metrics.push(r.metrics[0]),r=>r.metrics[0].key='inferred_buyers',r=>r.money[0].scale=0,
    r=>r.money[0].comparison.delta_minor='999',r=>r.actions[0].href=publicationUrl().replace('conversation_id=11','conversation_id=991002'),
    r=>r.actions[3].href='/chat.php?agent=7&conversation_id=11&message_id=7',r=>r.creative_id='2'.repeat(32),
    r=>r.associations.items[0].url='/chat.php?agent=7&conversation_id=991002',r=>r.associations.items[0].public_conversation_id=11,
    r=>r.associations.items[0].at_utc_ms=r.window.from_utc_ms-1,r=>r.associations.items[0].reply_at_utc_ms=r.window.to_utc_ms,
    r=>r.associations.coverage='unavailable',r=>r.associations.items.push(r.associations.items[0])]){
    const value=publication();change(value);check(api.outcomeModel(value,options)===null,'mismatched scope/ref/metric/money/action rejected');
  }
  for(const url of [publicationUrl()+'&agent=7',publicationUrl()+'&view=marketing',publicationUrl().replace('marketing_publication=','publication='),
    publicationUrl().replace('VVRD','VVRD%3D'),'https://outside.test'+publicationUrl(),'//outside.test/chat.php',publicationUrl()+'#other'])
    check(api.destination(url,'/chat.php')===null,'ambiguous or external destination rejected');
  check(api.destination('/nested'+publicationUrl(),'/nested/chat.php')?.conversationId===11,'nested deployment preserves exact original private Chat');
  check(api.source({ref:publication().ref,url:publicationUrl(),product_id:992101,publication_id:'b'.repeat(32)},'/chat.php')===null,'related source cannot substitute another publication id');
  for(const [zone,stamp,valid] of [['America/Havana','2026-03-08T05:00:00Z',true],['America/Havana','2026-11-01T04:00:00Z',true],['America/Havana','2026-11-01T05:00:00Z',false]]){
    const ref='profile:analytics:v1:1:'+Date.parse(stamp)/1000+':e:'+btoa(zone).replaceAll('+','-').replaceAll('/','_').replaceAll('=','');
    check(Boolean(api.periodRef(ref))===valid,'canonical skipped/repeated local midnight');
  }
  const host=f.document.createElement('div'),value=publication();
  value.metrics[0].current={value:null,observed_value:1,coverage:'partial',reason:'source_proof_incomplete'};value.metrics[0].comparison={delta:null,percent:null};value.coverage='limited';
  value.money[0].current.value_minor='900719925474099312345';value.money[0].comparison.delta_minor='900719925474099311345';
  api.renderOutcome(host,value,options);
  check(host.textContent.includes('Observed subset')&&host.textContent.includes('USD 9,007,199,254,740,993,123.45'),'partial evidence and exact decimal string amount render');
  check(host.querySelector('[data-product-marketing-metric="checkout_started_orders"]').textContent.includes('Orders with checkout links ready'),
    'checkout measure describes usable link availability without claiming the buyer opened the provider');
  check(host.querySelectorAll('img').length===0&&host.textContent.includes('<img onerror=alert(1)>'),'untrusted product title stays text');
  check(host.querySelectorAll('script').length===0&&host.textContent.includes('Does this vase come in blue? <script>alert(1)</script>'),'literal visitor question is bounded text, never executable content or inferred intent');
  check(host.querySelector('[data-product-marketing-exchange="991201"]').querySelector('a').href==='/profile-agent.php?tab=inbox&conversation=991002','visitor exchange opens its exact public conversation, separate from original private Chat');
  value.metrics[0].current={value:null,observed_value:null,coverage:'unavailable',reason:'storage_unavailable'};api.renderOutcome(host,value,options);
  check(host.querySelector('[data-product-marketing-metric="accepted_promotion_clicks"]').textContent.includes('Unavailable'),'missing evidence is unavailable rather than a zero');
  api.renderReview(host,{...review(),coverage:'unavailable'},options);check(!host.textContent.includes('Existing product plan')&&!host.textContent.includes('USD'),'unavailable report removes previously rendered facts');
}
{
  const f=fixture({ready:false,bridge:true});f.window.remember();f.load();
  check(f.requests.length===0&&new URL(f.location.href).searchParams.get('marketing_publication')===publicationId,'original complete tuple survives Chat normalization while restoration is pending');
  f.event('stonefellow:chat-context-changing',{initialRestore:true});f.state.ready=true;f.event('vp3:chat-context-ready');await f.respond();
  check(f.requests.length===1&&f.requests[0].body.period_ref===periodRef()&&f.host()?.textContent.includes('USD 20.00'),'exact initial publication opens after restored original Chat');
  check(f.launches.length===0,'automatic destination reads never open creative, recover or publish');
  f.event('vp3:chat-context-ready');f.host().querySelector('[data-media-command-close]').click();f.event('vp3:chat-context-ready');
  check(f.requests.length===1&&!f.host(),'readiness repeats and closing never reopen the consumed selector');
}
{
  const f=fixture({ready:false,bridge:true});f.load();f.event('stonefellow:chat-context-changing',{initialRestore:false});f.state.ready=true;f.event('vp3:chat-context-ready');
  check(f.requests.length===0&&!new URL(f.location.href).searchParams.has('marketing_publication'),'true Chat change retires pending publication intent and URL');
}
for(const mismatch of [f=>f.state.cid=12,f=>{f.state.agent=8;f.window.STONEFELLOW_CHAT.agentId=8;},f=>{f.state.owner=2;f.window.STONEFELLOW_CHAT.userId=2;},f=>f.window.STONEFELLOW_CHAT.workingAttentionBinding='other']){
  const f=fixture({ready:false,bridge:true});f.load();mismatch(f);f.state.ready=true;f.event('vp3:chat-context-ready');check(f.requests.length===0,'restored Chat/Agent/owner/binding mismatch cannot read selector');
}
{
  const f=fixture();f.load();f.state.cid=12;f.event('stonefellow:chat-context-changing');await f.respond();
  check(!f.host()&&f.requests[0].signal.aborted&&f.launches.length===0,'late response after scope change cannot restore private evidence');
}
for(const status of [401,403,419]){
  const f=fixture();f.load();await f.respond();f.host().querySelector('[data-media-command-publication-refresh]').click();await f.respond(1,{status});
  check(!f.host()&&f.launches.length===0,'authority '+status+' clears the whole publication view');
}
{
  const f=fixture();f.load();await f.respond();f.host().querySelector('[data-media-command-publication-refresh]').click();await f.respond(1,{status:404});
  check(!f.host().textContent.includes('USD 20.00')&&f.host().textContent.includes('Source unavailable'),'deleted original source removes previous publication facts');
  f.host().querySelector('[data-media-command-retry]').click();await f.respond(2);check(f.host().textContent.includes('USD 20.00'),'same exact read can retry after temporary source failure');
}
{
  const f=fixture();f.load();await f.respond();f.host().querySelector('[data-product-marketing-review]').click();
  check(f.requests.length===2&&f.launches.length===0,'explicit creative review first rechecks the exact publication');
  await f.respond(1);check(f.launches.length===1&&f.launches[0].publication_id===publicationId&&!f.host(),'fresh review hands the immutable saved tuple to the existing reviewed workbench');
}
{
  const f=fixture();f.load();await f.respond();const controls=f.host().querySelector('[aria-label="Publication reporting period"]').querySelectorAll('button');controls[1].click();await f.respond(1,{value:publication(7)});
  check(f.requests[1].body.period_ref===periodRef(7)&&f.host().textContent.includes('Completed 7 days'),'explicit period change reads the same publication with a canonical sibling ref');
}
check(!media.includes("action: 'creative_publish'")&&!report.includes('fetch('),'report renderer and automatic destination contain no publication mutation dispatch');
console.log('PRODUCT_MARKETING_REPORT=PASS ('+checks+' checks; DTO, rendering, exact original Chat restore, scope and authority races)');
