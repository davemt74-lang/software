import assert from 'node:assert/strict';
import vm from 'node:vm';
import {readFileSync} from 'node:fs';
import {webcrypto} from 'node:crypto';
import {startProfileWorkspaceServer} from './fixtures/profile-specialist-workspace-server.mjs';
import {workspaceDocument} from './fixtures/profile-workspace-dom-v1.mjs';
const server=await startProfileWorkspaceServer({marketing:true});let checks=0;
const check=(value,label)=>{assert.ok(value,label);checks++;};
try{
  const sessionResponse=await fetch(server.base+'/session'),session=await sessionResponse.json();
  const cookie=sessionResponse.headers.get('set-cookie').split(';')[0];
  const headers={Cookie:cookie,'X-VP3-WORKING-ATTENTION':session.binding};
  const seed=session.seed.followthrough,common={chat_agent_id:'7',chat_conversation_id:'11'};
  const request=async(action,fields={},options={})=>{
    const query=new URLSearchParams({action,...common,...fields});
    const response=await fetch(server.base+(options.base||'')+'/api/marketing-followthrough-v1.php?'+query+(options.extra||''),{headers:options.headers||headers,method:options.method||'GET'});
    return {response,body:await response.json()};
  };
  const read=async(action,fields={})=>{const result=await request(action,fields);assert.equal(result.response.status,200,JSON.stringify(result.body));return result.body;};
  const control=async action=>{const response=await fetch(server.base+'/fixture/control',{method:'POST',headers:{...headers,'Content-Type':'application/json'},body:JSON.stringify({action})});assert.equal(response.status,200,await response.text());};
  const fingerprint=async()=>await (await fetch(server.base+'/fixture/fingerprint',{headers})).json();
  const before=await fingerprint();
  const choices=await read('publications',{product_id:String(seed.ids.product)});
  check(choices.contract==='vp3.marketing-followthrough.v1'&&choices.scope.owner_user_id===1&&choices.scope.chat_conversation_id===11&&choices.working_attention_binding===session.binding,'actual endpoint echoes exact scope and page binding');
  check(choices.data.publications.publications.length===2&&choices.data.publications.publications.some(row=>row.publication_id===seed.publication_id)
    &&choices.data.publications.publications.some(row=>row.publication_id===seed.ids.current_publication),'explicit publication choices preserve both old and current publication');
  const value=(await read('report',{ref:seed.ref})).data.followthrough;
  check(value.ref===seed.ref&&value.contract==='vp3.profile-commerce-followthrough.v1'&&value.metrics.length===7&&value.coverage==='ready','actual fixed report returns all seven verified measures');
  const metric=key=>value.metrics.find(row=>row.key===key);
  check(metric('recorded_cta_clicks').current.value===2&&metric('conversation_attributed_clicks').current.value===1&&metric('promotion_linked_exchanges').current.value===1,'recorded clicks, linked clicks and proved exchanges are separate');
  check(metric('recorded_attributed_orders').previous.value===1&&metric('verified_checkout_openings').current.value===1&&metric('verified_paid_orders').current.value===1,'order recording, link recording and payment completion keep separate times');
  check(value.money.find(row=>row.key==='verified_payments'&&row.currency==='USD')?.current.value_minor==='2000'
    &&value.money.find(row=>row.key==='verified_refunds'&&row.currency==='USD')?.current.value_minor==='500','money remains exact ledger strings with separate payment and refund evidence');
  check(value.exact_creative.marker.creative_id===seed.ids.original_creative&&value.exact_creative.marker.draft_id===seed.ids.draft
    &&value.current_plan.url.includes('marketing_plan='+seed.ids.product),'original creative review remains separate from current plan navigation');
  check(value.publication.title==='Original T-shirt promotion'&&!JSON.stringify(value).includes('Current T-shirt promotion'),'fixed report uses the exact saved draft title');
  check(value.exchanges.items[0].public_conversation_id===seed.ids.public_conversation&&value.exchanges.items[0].open_selection.publicConversationId===seed.ids.public_conversation,'bounded exchange source retains exact public conversation');
  const threadResponse=await fetch(server.base+'/api/profile-specialist-workspace-v1.php?'+new URLSearchParams({action:'conversation',...common,public_conversation_id:String(seed.ids.public_conversation)}),{headers});
  const thread=await threadResponse.json();check(threadResponse.status===200&&thread.data.public_conversation_id===seed.ids.public_conversation,'existing Profile conversation reader opens the authorized linked public thread');
  const fixedQuery='agent=7&conversation_id=11&marketing_followthrough='+encodeURIComponent(seed.ref);
  async function pageConfig(raw,base=''){
    const response=await fetch(server.base+base+'/chat.php?'+raw,{headers});assert.equal(response.status,200);const html=await response.text();
    const script=/<script>\s*window\.fixtureSeed=[\s\S]*?<\/script>/.exec(html)?.[0];assert.ok(script);
    const context=vm.createContext({window:{}});vm.runInContext(script.replace(/^<script>/,'').replace(/<\/script>$/,''),context);return {config:context.window.STONEFELLOW_CHAT,globals:context.window,html};
  }
  const linked=await pageConfig(fixedQuery,'/nested/app');check(linked.config.marketingFollowthroughDestination?.ref===seed.ref
    &&linked.config.marketingFollowthroughDestination.href.startsWith('/nested/app/chat.php?')&&linked.html.includes('/nested/app/chat-marketing-followthrough-v1.js'),'actual production destination helper retains exact tuple before real Chat script restore');
  const legacyQuery=new URLSearchParams({agent:'7',conversation_id:'11',marketing_plan:String(seed.ids.product),marketing_publication:seed.publication_id,marketing_period:seed.period_ref}).toString();
  const legacyPage=await pageConfig(legacyQuery,'/nested/app');
  check(legacyPage.config.marketingFollowthroughDestination?.ref===seed.ref
    &&legacyPage.config.marketingFollowthroughDestination.href===linked.config.marketingFollowthroughDestination.href
    &&new URL(legacyPage.config.marketingFollowthroughDestination.legacyHref,server.base).searchParams.get('marketing_plan')===String(seed.ids.product),
    'released five-parameter link preserves exact original product, publication and period through actual SQL binding');
  check((await pageConfig(legacyQuery.replace('marketing_plan='+seed.ids.product,'marketing_plan='+(seed.ids.product+1)))).config.marketingFollowthroughDestination===null,
    'released link with a mismatched product cannot obtain a current navigation hint');
  const oldRef='marketing:product-outcome:'+seed.publication_id+':'+seed.period_ref.slice('profile:analytics:'.length);
  const oldRead=await read('report',{ref:oldRef});
  check(oldRead.data.followthrough.ref===seed.ref&&oldRead.data.followthrough.revision===value.revision,
    'explicit old-ref read reaches the one canonical collector without rolling the period');
  for(const raw of [fixedQuery+'&agent=7',fixedQuery.replace('agent=7','agent=99999'),fixedQuery.replace('conversation_id=11','conversation_id=99999'),fixedQuery+'&profile_analytics='+encodeURIComponent(seed.period_ref),fixedQuery.replace('agent=7','agent[]=7')])
    check((await pageConfig(raw)).config.marketingFollowthroughDestination===null,'duplicate, competing or fallback original route cannot become a follow-through destination');
  const sub=await request('report',{ref:seed.ref},{base:'/nested/app'});check(sub.response.status===200&&sub.body.data.followthrough.current_plan.url.startsWith('/nested/app/chat.php?'),'actual canonical report links respect a subdirectory deployment');
  check(JSON.stringify(await fingerprint())===JSON.stringify(before)&&server.readWriteFailure()==='','all report, choice, route and thread reads leave SQL and inference unchanged');
  for(const [action,fields,options,status] of [
    ['report',{ref:seed.ref},{method:'POST'},405],['report',{ref:seed.ref},{headers:{Cookie:cookie}},403],
    ['report',{ref:seed.ref,period:'7'},{},422],['report',{ref:seed.ref,publication_id:seed.publication_id},{},422],
    ['report',{ref:seed.ref},{extra:'&chat_agent_id=7'},422],['report',{ref:seed.ref},{extra:'&ref[]=x'},422],
    ['report',{ref:seed.ref,chat_conversation_id:'12'},{},404],['report',{ref:seed.ref,chat_agent_id:'9007199254740992'},{},422]]){
    const result=await request(action,fields,options);check(result.response.status===status&&!result.body.data,'invalid method, identity or target rejects without report facts');
  }
  const document=workspaceDocument(),host=document.createElement('div');document.body.append(host);
  const uiWindow={VP3_INITIATIVE_WORKBENCH:{followthroughEndpoint:'/api/marketing-followthrough-v1.php'},addEventListener(){},removeEventListener(){},dispatchEvent(){}};
  const uiContext=vm.createContext({window:uiWindow,document,URL,URLSearchParams,Intl,Date,AbortController,Event,crypto:webcrypto,Uint8Array,BigInt,atob,btoa,setTimeout,clearTimeout,
    location:{origin:server.base,href:server.base+'/chat.php'},fetch:(url,settings)=>fetch(url,{...settings,headers:{...settings.headers,Cookie:cookie}})});
  for(const file of ['profile-specialist-workspace-v1.js','chat-marketing-followthrough-v1.js'])vm.runInContext(readFileSync(new URL('../'+file,import.meta.url),'utf8'),uiContext);
  let invalidated=0;const mounted=uiWindow.VP3MarketingFollowthroughV1.mount(host,{selection:{ref:seed.ref},context:()=>({ownerUserId:1,chatAgentId:7,chatConversationId:11,workingAttentionBinding:session.binding}),onAuthorityChanged:()=>invalidated++});
  const waitFor=async predicate=>{for(let i=0;i<240;i++){if(predicate())return;await new Promise(resolve=>setTimeout(resolve,10));}throw new Error('Mounted actual report did not settle: '+host.textContent);};
  await waitFor(()=>host.querySelector('[data-marketing-fixed-ref]'));check(host.textContent.includes('USD 20.00')&&host.textContent.includes('USD 5.00')&&host.textContent.includes('Can I get this T-shirt in blue?'),'production renderer accepts actual collector and exact proof-backed exchange');
  await control('profile_off');check((await read('report',{ref:seed.ref})).data.followthrough.ref===seed.ref,'turning public visibility off preserves authorized historical evidence');
  await control('timezone_change');const preserved=(await read('report',{ref:seed.ref})).data.followthrough;
  check(preserved.ref===value.ref&&preserved.revision===value.revision&&preserved.window.timezone==='UTC','saved fixed period survives current timezone changes without proof drift');
  const latest=(await read('report',{publication_id:seed.publication_id,period:'7'})).data.followthrough;
  check(latest.window.period===7&&latest.window.timezone==='Asia/Tokyo'&&latest.ref!==seed.ref,'explicit latest resolution uses current owner timezone');
  await control('followthrough_legacy_click');const legacy=(await read('report',{ref:seed.ref})).data.followthrough;
  check(legacy.metrics.find(row=>row.key==='recorded_cta_clicks').current.value===2
    &&legacy.metrics.find(row=>row.key==='conversation_attributed_clicks').current.value===null
    &&legacy.metrics.find(row=>row.key==='conversation_attributed_clicks').current.coverage==='partial','legacy click association is partial without inventing or losing recorded click totals');
  await control('followthrough_clicks_off');await mounted.refresh();await waitFor(()=>host.querySelector('[data-marketing-fixed-ref]'));
  check(host.textContent.includes('Unavailable')&&host.querySelector('[data-marketing-metric="recorded_cta_clicks"]').textContent.includes('Unavailable'),'missing source storage removes prior totals rather than rendering zero');
  await control('followthrough_clicks_restore');await control('revoke');await mounted.refresh();await waitFor(()=>invalidated>0);
  check(!host.querySelector('[data-marketing-fixed-ref]')&&!host.textContent.includes('USD '),'late Profile permission loss clears the mounted report');
  check((await request('report',{ref:seed.ref})).response.status===403,'full conversation and commerce report requires current Profile permission');
  check((await read('publications',{product_id:String(seed.ids.product)})).data.publications.publications.length===2,'private publication choices remain available without Profile permission');
  const noProfilePage=await pageConfig('agent=7&conversation_id=11');
  check(!noProfilePage.globals.VP3_PROFILE_WORKSPACE_V1&&noProfilePage.html.includes('data-vp3-period-tools-only'),'fresh no-Profile-grant bootstrap loads pure tools without Profile configuration');
  const privateDocument=workspaceDocument(),privateHost=privateDocument.createElement('div');privateDocument.body.append(privateHost);
  privateDocument.currentScript={hasAttribute:name=>name==='data-vp3-period-tools-only'};
  const privateWindow={...noProfilePage.globals,addEventListener(){},removeEventListener(){},dispatchEvent(){}};
  const privateContext=vm.createContext({window:privateWindow,document:privateDocument,URL,URLSearchParams,Intl,Date,AbortController,Event,crypto:webcrypto,Uint8Array,BigInt,atob,btoa,setTimeout,clearTimeout,
    location:{origin:server.base,href:server.base+'/chat.php'},fetch:(url,settings)=>fetch(url,{...settings,headers:{...settings.headers,Cookie:cookie}})});
  vm.runInContext(readFileSync(new URL('../profile-specialist-workspace-v1.js',import.meta.url),'utf8'),privateContext);privateDocument.currentScript=null;
  vm.runInContext(readFileSync(new URL('../chat-marketing-followthrough-v1.js',import.meta.url),'utf8'),privateContext);
  let privateDenied=0;const privateMount=privateWindow.VP3MarketingFollowthroughV1.mount(privateHost,{selection:{productId:seed.ids.product},context:()=>({ownerUserId:1,chatAgentId:7,chatConversationId:11,workingAttentionBinding:session.binding}),onAuthorityChanged:()=>privateDenied++});
  await waitFor(()=>privateHost.querySelectorAll('[data-marketing-publication]').length===2);
  check(Boolean(privateMount)&&!privateWindow.VP3ProfileWorkspaceV1&&privateHost.textContent.includes('Original T-shirt promotion'),'actual private publication inventory renders without any Profile workspace global');
  privateHost.querySelectorAll('button').find(button=>button.textContent==='View follow-through').click();await waitFor(()=>privateDenied===1);
  check(!privateHost.querySelector('[data-marketing-fixed-ref]')&&!privateHost.textContent.includes('USD '),'pure tool availability cannot bypass actual Profile report permission');privateMount.dispose();
  const plansResponse=await fetch(server.base+'/api/initiative-workbench-v1.php',{method:'POST',headers:{Cookie:cookie,'Content-Type':'application/json'},body:JSON.stringify({action:'marketing_plans',tool:'marketing',owner_key:'1',csrf_token:session.csrf,agent_id:7,conversation_id:11})});
  const plans=await plansResponse.json();check(plansResponse.status===200&&plans.plans.some(row=>row.product_id===seed.ids.product),'existing private unfinished and active plan listing keeps its own authority');
  await control('restore');await control('followthrough_changed');check((await request('report',{ref:seed.ref})).response.status===409,'changed immutable source never falls back to current creative');
  await control('logout');check((await request('publications',{product_id:String(seed.ids.product)})).response.status===401,'actual logged-out session cannot read private publication choices');
  mounted.dispose();check(server.readWriteFailure()==='','all actual GET routes remain query-only');
}catch(error){console.error(server.logs());throw error;}finally{await server.close();}

const systemServer=await startProfileWorkspaceServer({marketing:true,marketingAgent:0});
try{
  const response=await fetch(systemServer.base+'/session'),session=await response.json(),cookie=response.headers.get('set-cookie').split(';')[0];
  const headers={Cookie:cookie,'X-VP3-WORKING-ATTENTION':session.binding},seed=session.seed.followthrough;
  const before=await (await fetch(systemServer.base+'/fixture/fingerprint',{headers})).json();
  const result=await fetch(systemServer.base+'/nested/app/api/marketing-followthrough-v1.php?'+new URLSearchParams({action:'report',chat_agent_id:'0',chat_conversation_id:String(session.seed.system_chat),ref:seed.ref}),{headers});
  const envelope=await result.json();assert.equal(result.status,200,JSON.stringify(envelope));
  check(envelope.scope.chat_agent_id===0&&envelope.scope.chat_conversation_id===session.seed.system_chat,'actual system-Agent report retains exact original private scope');
  check(envelope.data.followthrough.publication.chat_agent_id===0&&envelope.data.followthrough.publication.chat_conversation_id===session.seed.system_chat
    &&envelope.data.followthrough.exchanges.items.length===1,'system private scope retains independently verified native public exchange evidence');
  check(envelope.data.followthrough.current_plan.url.startsWith('/nested/app/chat.php?agent=system&conversation_id='+session.seed.system_chat+'&'),'system current-plan link retains base and original scope');
  const page=await fetch(systemServer.base+'/nested/app/chat.php?'+new URLSearchParams({agent:'system',conversation_id:String(session.seed.system_chat),marketing_followthrough:seed.ref}),{headers});
  const html=await page.text(),script=/<script>\s*window\.fixtureSeed=[\s\S]*?<\/script>/.exec(html)?.[0];assert.ok(script);
  const context=vm.createContext({window:{}});vm.runInContext(script.replace(/^<script>/,'').replace(/<\/script>$/,''),context);
  check(context.window.STONEFELLOW_CHAT.marketingFollowthroughDestination?.chatAgentId===0
    &&context.window.STONEFELLOW_CHAT.marketingFollowthroughDestination?.chatConversationId===session.seed.system_chat,'production original-query helper binds successful system destination before Chat restore');
  check(JSON.stringify(await (await fetch(systemServer.base+'/fixture/fingerprint',{headers})).json())===JSON.stringify(before)&&systemServer.readWriteFailure()==='','system source and original-route reads remain query-only');
}catch(error){console.error(systemServer.logs());throw error;}finally{await systemServer.close();}
console.log('MARKETING_FOLLOWTHROUGH_HTTP=PASS ('+checks+' checks; production API/session/SQL/helper/renderer)');
