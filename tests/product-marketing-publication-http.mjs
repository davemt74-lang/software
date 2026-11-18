import assert from 'node:assert/strict';
import {startProfileWorkspaceServer} from './fixtures/profile-specialist-workspace-server.mjs';

// Production Hub and canonical report endpoints; only their physical fixture storage is isolated.
let checks=0;const check=(truth,label)=>{assert.ok(truth,label);checks++;};
for(const agent of [7,0]){
  const server=await startProfileWorkspaceServer({marketing:true,marketingAgent:agent});
  try{
    let session,cookie;
    const login=async()=>{const response=await fetch(server.base+'/session');session=await response.json();cookie=response.headers.get('set-cookie')?.split(';')[0]||cookie;};
    await login();const chat=agent===0?session.seed.system_chat:11,seed=session.seed.followthrough;
    const headers=()=>({Cookie:cookie,'X-VP3-WORKING-ATTENTION':session.binding});
    const get=async(path,options={})=>{const response=await fetch(server.base+path,{headers:headers(),...options});return {response,body:await response.json()};};
    const hub=async(base='',changes={})=>get(base+'/api/agent-activity-hub-v1.php?'+new URLSearchParams({view:'marketing',agent_id:String(agent),conversation_id:String(chat),...changes}));
    const control=async action=>{const result=await get('/fixture/control',{method:'POST',headers:{...headers(),'Content-Type':'application/json'},body:JSON.stringify({action})});assert.equal(result.response.status,200,JSON.stringify(result.body));};
    const before=(await get('/fixture/fingerprint')).body;
    const read=await hub();check(read.response.status===200&&read.body.ok===true,'authorized original private Chat reads the Marketing Hub');
    const state=read.body.state,summary=state.product_marketing;
    check(summary.contract==='vp3.product-marketing-summary.v1'&&summary.coverage===state.coverage.product_marketing
      &&['ready','limited'].includes(summary.coverage),'Hub reports honest canonical summary coverage');
    check(state.marketing&&Array.isArray(state.marketing.campaigns),'existing Campaign review remains alongside Product Marketing');
    check(summary.items.some(row=>row.ref==='marketing:product-plan:'+seed.ids.private_plan&&row.status==='listing_review'),'native private listing review is visible without creating another plan');
    const publication=summary.items.find(row=>row.kind==='followthrough');check(Boolean(publication),'Hub exposes an exact completed publication period');
    const canonical=(await get('/fixture/marketing-sources')).body;
    for(const row of summary.items)check(canonical.items[row.ref]?.revision===row.revision&&canonical.items[row.ref]?.url===row.url,'Hub reuses the canonical source revision and original Chat destination');
    const link=new URL(publication.url,server.base);check(link.searchParams.get('agent')===(agent===0?'system':String(agent))
      &&link.searchParams.get('conversation_id')===String(chat)&&link.searchParams.get('marketing_followthrough')===publication.ref,'Hub source retains exact original private scope and fixed reference');
    const report=await get('/api/marketing-followthrough-v1.php?'+new URLSearchParams({action:'report',chat_agent_id:String(agent),chat_conversation_id:String(chat),ref:publication.ref}));
    check(report.response.status===200&&report.body.data.followthrough.ref===publication.ref,'canonical detail accepts the exact Hub source without resolving a different period');
    check(report.body.data.followthrough.metrics.length===7&&report.body.data.followthrough.current_publication.availability==='ready','one detail contract retains all seven metrics and separately checked current state');
    if(agent===7){
      const profile=await get('/api/agent-activity-hub-v1.php?'+new URLSearchParams({view:'profile',agent_id:'7',conversation_id:String(chat)}));
      const finding=profile.body.state?.profile_report?.findings?.find(row=>row.ref==='profile:conversation:'+seed.ids.public_conversation);
      check(profile.response.status===200&&finding?.related_product_marketing_coverage==='ready'&&finding.related_product_marketing.length===1,'actual Profile report preserves its canonical receipt-associated marketing source');
      const related=finding.related_product_marketing[0],target=new URL(related.url,server.base);
      check(related.public_conversation_id===seed.ids.public_conversation&&target.searchParams.get('conversation_id')===String(chat)
        &&target.searchParams.get('marketing_followthrough')===related.ref,'public conversation association retains its separate exact original private Chat route');
      const relatedRead=await get('/api/marketing-followthrough-v1.php?'+new URLSearchParams({action:'report',chat_agent_id:'7',chat_conversation_id:String(chat),ref:related.ref}));
      check(relatedRead.response.status===200&&relatedRead.body.data.followthrough.ref===related.ref
        &&relatedRead.body.data.followthrough.exchanges.items.some(row=>row.turn_id===related.turn_id&&row.visitor_message_id===related.visitor_message_id
          &&row.answer_message_id===related.answer_message_id),'related source opens the identical fixed selection and saved exchange receipt');
    }
    const nested=await hub('/nested/app');check(nested.response.status===200&&nested.body.state.product_marketing.items.every(row=>row.url.startsWith('/nested/app/chat.php?')),'Hub source links respect a subdirectory deployment');
    check(JSON.stringify((await get('/fixture/fingerprint')).body)===JSON.stringify(before)&&server.readWriteFailure()==='','Hub and exact detail reads write no SQL and dispatch no provider');
    const bad=await hub('',{conversation_id:agent===0?'11':'12'});check(bad.response.status===403&&!bad.body.state,'another Agent or private Chat cannot inherit the summary');
    await control('revoke');const privateOnly=await hub();
    check(privateOnly.response.status===200&&privateOnly.body.state.product_marketing.items.length>0
      &&privateOnly.body.state.product_marketing.items.every(row=>row.kind==='plan'),'private plan summaries remain available without granting Profile period facts');
    const forbidden=await get('/api/marketing-followthrough-v1.php?'+new URLSearchParams({action:'report',chat_agent_id:String(agent),chat_conversation_id:String(chat),ref:publication.ref}));
    check(forbidden.response.status===403&&!forbidden.body.data,'private summary availability cannot grant the full Profile report');
    await control('restore');await control('marketing_profile_revoke_after_read');const permissionRace=await hub();
    check(permissionRace.response.status===403&&!permissionRace.body.state,'Profile revocation after source collection is caught by the final period-summary authority fence');
    const afterRevocation=await hub();check(afterRevocation.response.status===200&&afterRevocation.body.state.product_marketing.items.every(row=>row.kind==='plan'),'next fresh read returns only still-authorized private plans after the late grant change');
    await control('restore');await control('marketing_logout_after_read');const revoked=await hub();
    check(revoked.response.status===403&&!revoked.body.state,'logout after source collection is caught by the actual Hub session/page-binding fence');
    const loggedOut=await hub();check(loggedOut.response.status===401&&!loggedOut.body.state,'destroyed actual session cannot reread the Hub');
    await login();const recovered=await hub();check(recovered.response.status===200,'a fresh authorized session can check the same canonical sources');
    check(server.readWriteFailure()==='','final session fencing does not mutate report storage');
  }catch(error){console.error(server.logs());throw error;}finally{await server.close();}
}
console.log('PRODUCT_MARKETING_PUBLICATION_HTTP=PASS ('+checks+' checks; production Hub, canonical detail, actual session and SQL)');
