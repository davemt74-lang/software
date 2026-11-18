import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import {webcrypto} from 'node:crypto';
import vm from 'node:vm';
import {startProfileWorkspaceServer} from './fixtures/profile-specialist-workspace-server.mjs';
import {workspaceDocument} from './fixtures/profile-workspace-dom-v1.mjs';

const server=await startProfileWorkspaceServer();
let checks=0;
const check=(truth,label)=>{assert.ok(truth,label);checks++;};
try{
  const login=await fetch(server.base+'/session'),session=await login.json();
  const headers={Cookie:login.headers.get('set-cookie').split(';')[0],'X-VP3-Working-Attention':session.binding};
  const scope={chat_agent_id:7,chat_conversation_id:11};
  const request=async(action,input={},method='GET',extraHeaders={})=>{
    const payload={action,...scope,...input};const url=new URL(server.base+'/api/profile-specialist-workspace-v1.php');
    const options={method,headers:{...headers,...extraHeaders}};
    if(method==='POST'){options.headers['Content-Type']='application/json';options.body=JSON.stringify({...payload,csrf_token:session.csrf});}
    else for(const [key,value]of Object.entries(payload))url.searchParams.set(key,String(value));
    const response=await fetch(url,options);const body=await response.json();return {response,body};
  };
  const read=async(action,input={})=>{const result=await request(action,input);assert.equal(result.response.status,200,JSON.stringify(result.body));return result.body;};
  const mutate=async(action,input)=>{const result=await request(action,input,'POST');assert.equal(result.response.status,200,JSON.stringify(result.body));return result.body;};
  const stored=async()=>(await fetch(server.base+'/fixture/state',{headers})).json();
  const control=async action=>{const result=await fetch(server.base+'/fixture/control',{method:'POST',headers:{...headers,'Content-Type':'application/json'},body:JSON.stringify({action})});assert.equal(result.status,200,await result.text());};
  const before=await stored();
  const overview=await read('overview');
  check(overview.contract==='vp3.profile-workspace.v1'&&overview.action==='overview','production API returns the workspace contract and exact action');
  check(overview.scope.owner_user_id===1&&overview.scope.chat_agent_id===7&&overview.scope.chat_conversation_id===11
    &&overview.working_attention_binding===session.binding,'envelope binds owner, private Agent, private Chat and browser session');
  check(overview.data.status==='ready'&&overview.data.report.contract==='vp3.profile-report.v1','overview reuses the canonical Profile report projection');
  check((await request('overview')).response.headers.get('cache-control').includes('no-store'),'private reads are not cached');
  const conversations=await read('conversations',{filter:'all'});
  check(conversations.data.items.some(row=>row.public_conversation_id===session.seed.primary)
    &&conversations.data.items.some(row=>row.public_conversation_id===session.seed.secondary)
    &&conversations.data.items.some(row=>row.public_conversation_id===session.seed.legacy),'inventory includes live and historical conversations independently of report findings');
  const thread=await read('conversation',{public_conversation_id:session.seed.primary});
  check(thread.data.public_conversation_id===session.seed.primary&&thread.data.current_turn.turn_id===session.seed.primary_turn,'exact public conversation has its canonical current turn');
  check(/^[a-f0-9]{64}$/.test(thread.data.conversation.source_revision)&&thread.data.messages.items.length>0,'thread has current source revision and saved messages');
  check(thread.data.current_turn.can_claim===true&&thread.data.assistance.can_prepare===false,'UI receives canonical takeover and assistance eligibility');
  check((await read('current_turn',{public_conversation_id:session.seed.primary})).data.current_turn.turn_id===session.seed.primary_turn,'current-turn read retains the exact target');
  const legacy=await read('conversation',{public_conversation_id:session.seed.legacy});
  check(legacy.data.current_turn===null&&legacy.data.conversation.can_manual_reply===true&&legacy.data.assistance.can_prepare===false,'historical manual reply stays available without fabricating a turn');
  check((await read('draft_status',{public_conversation_id:session.seed.primary,latest:1})).data.draft===null,'opening a thread does not create a draft');
  const calls=await read('calls',{call_id:session.seed.call_id,public_conversation_id:session.seed.call_conversation_id});
  check(calls.data.calls.calls.some(row=>row.call_id===session.seed.call_id),'Calls reads the seeded canonical delivered call');
  check(!JSON.stringify(calls).match(/provider_token|room_token|credential_hash|join_token/),'workspace call projection excludes provider credentials');
  const outcomeTarget={call_id:session.seed.outcomes.assisted.call_id,public_conversation_id:session.seed.outcomes.assisted.public_conversation_id},ordinaryTarget=session.seed.outcomes.ordinary;
  const history=(await read('calls',{filter:'history',call_id:outcomeTarget.call_id})).data.calls;
  check(history.filter==='history'&&history.calls.some(row=>row.call_id===outcomeTarget.call_id)&&history.calls.every(row=>row.detail_kind==='outcome'),
    'history includes terminal calls even without a previously delivered Chat card');
  check(calls.data.calls.calls.every(row=>row.detail_kind==='current')&&!calls.data.calls.calls.some(row=>row.call_id===outcomeTarget.call_id),
    'current calls and completed history remain distinct');
  const outcome=(await read('call_outcome',outcomeTarget)).data.call_outcome;
  check(outcome.contract==='vp3.profile-call-outcome.v1'&&outcome.call_id===outcomeTarget.call_id&&/^[a-f0-9]{64}$/.test(outcome.context_revision),
    'saved outcome has exact call identity and current context proof');
  check(outcome.sections.transcript.items.length===50&&outcome.sections.research.items.length===6&&outcome.sections.followups.items.length===20,
    'initial saved sections obey actual transcript, research and reviewed-task page bounds');
  check(outcome.sections.analysis.fresh===true&&outcome.sections.analysis.items.length===3,'only current source-backed saved analysis is exposed');
  for(const [key,total]of[['transcript',55],['research',7],['followups',23]]){
    const next=(await read('call_outcome',{call_id:outcomeTarget.call_id,public_conversation_id:outcomeTarget.public_conversation_id,section:key,cursor:outcome.sections[key].next_cursor})).data.call_outcome;
    check(Object.keys(next.sections).join('|')===key&&next.sections[key].source_revision===outcome.sections[key].source_revision
      &&next.sections[key].items.length+outcome.sections[key].items.length===total&&!next.sections[key].has_more,key+' exact continuation returns the remaining saved records');
  }
  check(outcome.sections.followups.items.some(row=>row.task_status==='completed')&&outcome.sections.followups.items.some(row=>row.task_status==='cancelled'),
    'reviewed follow-up history retains authorized completed and cancelled task states');
  check(Object.values((await read('call_outcome',ordinaryTarget)).data.call_outcome.sections).every(section=>section.availability==='not_applicable'),
    'a direct terminal call has explicit non-applicable recording sections');
  check((await request('call_outcome',{call_id:outcomeTarget.call_id,public_conversation_id:ordinaryTarget.public_conversation_id})).response.status===404,
    'an outcome cannot be retargeted to another public thread');
  check((await request('call_outcome',{call_id:outcomeTarget.call_id,section:'research',cursor:outcome.sections.transcript.next_cursor})).response.status===422,
    'section continuation cursor cannot cross source sections');
  const analytics=await read('analytics',{property_id:0,days:30});
  check(analytics.data.analytics.ready===true&&analytics.data.analytics.coverage==='complete','Analytics tab reads complete authorized aggregates');
  check((await read('revenue')).data.revenue.ready===true,'conversion intelligence reads through the same private scope');
  const fixed=(await read('analytics_period',{ref:session.seed.analytics.ref})).data.analytics_period;
  check(fixed.contract==='vp3.profile-analytics-period.v1'&&fixed.ref===session.seed.analytics.ref&&fixed.metrics.length===21
    &&fixed.image_usage.contract==='vp3.profile-image-analytics.v1',
    'fixed period API returns the complete closed metric contract');
  check(fixed.window.as_of_utc_ms===session.seed.analytics.cutoff*1000&&fixed.window.timezone==='UTC'
    &&fixed.period_options.every(option=>option.ref.split(':').slice(4).join(':')===fixed.ref.split(':').slice(4).join(':')),
    'server sibling refs preserve fixed cutoff, zone and provenance');
  check(fixed.metrics.find(metric=>metric.key==='visit_records').current.value===3
    &&fixed.metrics.find(metric=>metric.key==='recognized_browser_contacts').current.value===1,
    'timed visit records and verified recognized browser contacts remain distinct measures');
  check(fixed.money.every(row=>row.scale===2&&row.amount_unit==='commerce_cent'
    &&row.scale_source==='vp3.commerce.v800'&&(row.current.value_minor===null||typeof row.current.value_minor==='string')),
    'commerce ledger amounts retain explicit scale and integer strings');
  check((await request('analytics_period',{ref:fixed.ref,period:7})).response.status===422,'fixed reference cannot be silently combined with another period selector');
  const fixedQuery='agent=7&conversation_id=11&profile_analytics='+encodeURIComponent(fixed.ref);
  const pageConfig=async(path,query)=>{
    const response=await fetch(server.base+path+'?'+query,{headers});assert.equal(response.status,200);
    const html=await response.text(),script=/<script>\s*window\.fixtureSeed=[\s\S]*?<\/script>/.exec(html)?.[0];
    assert.ok(script,'real Chat route fixture bootstrap is present');
    const context=vm.createContext({window:{}});vm.runInContext(script.replace(/^<script>/,'').replace(/<\/script>$/,''),context);
    return {html,config:context.window.STONEFELLOW_CHAT};
  };
  const linked=await pageConfig('/nested/app/chat.php',fixedQuery);
  check(linked.config.profileAnalyticsDestination?.ref===fixed.ref&&linked.config.profileAnalyticsDestination.href.startsWith('/nested/app/chat.php?')
    &&linked.html.includes('src="/nested/app/chat.js"'),'production query helper and authority bind the real-chat fixture before loading core Chat');
  check((await pageConfig('/chat.php',fixedQuery+'&agent=7')).config.profileAnalyticsDestination===null,'repeated original query keys cannot inherit fixture fallback scope');
  check((await pageConfig('/chat.php',fixedQuery.replace('conversation_id=11','conversation_id=999999'))).config.profileAnalyticsDestination===null,
    'unowned original Chat cannot inherit the authorized fallback destination');
  check((await pageConfig('/chat.php','agent=system&conversation_id='+session.seed.system_chat+'&profile_analytics='+encodeURIComponent(fixed.ref))).config.profileAnalyticsDestination?.chatAgentId===0,
    'system private Chat is authorized before exact destination binding');
  const afterReads=await stored();
  check(JSON.stringify(before)===JSON.stringify(afterReads)&&server.readWriteFailure()==='','opening all read surfaces changes no SQL rows and dispatches no inference');
  check((await read('overview',{chat_conversation_id:0})).data.status==='save_chat_required','unsaved Chat gets explicit setup state');
  for(const [action,input,method,extra,status,label]of[
    ['overview',{},'GET',{'X-VP3-Working-Attention':'wrong'},403,'wrong session'],
    ['conversation',{public_conversation_id:999999},'GET',{},404,'unknown public conversation'],
    ['overview',{chat_agent_id:8},'GET',{},403,'mismatched private Agent'],
    ['overview',{chat_conversation_id:999999},'GET',{},403,'unowned private Chat'],
    ['conversations',{},'POST',{},405,'write method on a read action'],
    ['conversation_action',{public_conversation_id:1},'GET',{},405,'read method on a mutation'],
    ['overview',{public_conversation_id:1},'GET',{},422,'unexpected target on overview']
  ])check((await request(action,input,method,extra)).response.status===status,label+' is rejected by production dispatch');

  const claimPayload={public_conversation_id:session.seed.primary,turn_id:thread.data.current_turn.turn_id,
    revision:thread.data.current_turn.revision,operation:'claim',request_id:'a'.repeat(32)};
  const claimed=(await mutate('conversation_action',claimPayload)).data;
  check(claimed.current_turn.responder==='owner'&&claimed.assistance.can_prepare===true,'takeover returns current source and enables private preparation');
  const prepare={public_conversation_id:session.seed.primary,turn_id:claimed.current_turn.turn_id,revision:claimed.current_turn.revision,
    source_revision:claimed.conversation.source_revision,prepare_request_id:'b'.repeat(32),instruction:'Answer the sizing question privately.'};
  const prepared=(await mutate('draft_prepare',prepare)).data.draft;
  check(prepared.state==='ready'&&prepared.current===true&&prepared.text.includes('private draft'),'bounded provider result is saved as a private reviewed draft');
  check(prepared.prepare.prepare_request_id===prepare.prepare_request_id&&prepared.prepare.instruction===prepare.instruction,'saved draft carries immutable preparation inputs for explicit recovery');
  const afterPrepare=await stored();
  check(afterPrepare.provider_dispatches.length===1,'one authorized preparation creates one provider dispatch');
  check(afterPrepare.profile_agent_messages.filter(row=>row.sender_type==='owner'&&Number(row.conversation_id)===session.seed.primary).length===0,'preparation sends no public owner message');
  check((await read('draft_status',{public_conversation_id:session.seed.primary,latest:1})).data.draft.prepare_request_id===prepare.prepare_request_id,'later GET recovers the same durable draft');
  check((await mutate('draft_prepare',prepare)).data.draft.prepare_request_id===prepare.prepare_request_id&&(await stored()).provider_dispatches.length===1,'same preparation ID recovers without a second inference');
  check((await request('draft_prepare',{...prepare,instruction:'Changed body with the same ID'},'POST')).response.status===409,'reusing preparation ID for another payload is rejected');

  const reply={public_conversation_id:session.seed.primary,turn_id:claimed.current_turn.turn_id,revision:claimed.current_turn.revision,
    operation:'reply',request_id:'c'.repeat(32),message:'An owner reviewed and edited answer.',prepare_request_id:prepare.prepare_request_id};
  const sent=(await mutate('conversation_action',reply)).data;
  const recovered=(await mutate('conversation_action',reply)).data;
  check(sent.message_id>0&&sent.message_id===recovered.message_id&&recovered.duplicate===true,'same exact reply operation recovers its canonical receipt');
  const afterSend=await stored();
  check(afterSend.profile_agent_messages.filter(row=>row.sender_type==='owner'&&Number(row.conversation_id)===session.seed.primary).length===1,'response-loss retry cannot duplicate the public owner reply');
  check((await read('draft_status',{public_conversation_id:session.seed.primary,prepare_request_id:prepare.prepare_request_id})).data.draft.state==='sent','reviewed draft records the resulting public send receipt');
  check((await mutate('conversation_action',{public_conversation_id:session.seed.legacy,turn_id:0,revision:0,operation:'reply',request_id:'d'.repeat(32),message:'Historical manual reply.'})).data.message_id>0,'no-turn historical reply uses the existing stable owner path');
  await control('transcript_change');
  check((await request('call_outcome',{call_id:outcomeTarget.call_id,section:'transcript',cursor:outcome.sections.transcript.next_cursor})).response.status===409,
    'a changed canonical transcript invalidates its old page cursor');
  const changedOutcome=(await read('call_outcome',{call_id:outcomeTarget.call_id})).data.call_outcome;
  check(changedOutcome.sections.analysis.fresh===false&&changedOutcome.sections.analysis.items.length===0,
    'saved analysis with outdated transcript proof is withheld');
  await control('recording_revoke');
  const deniedOutcome=(await read('call_outcome',{call_id:outcomeTarget.call_id})).data.call_outcome;
  check(deniedOutcome.call.call_id===outcomeTarget.call_id&&Object.values(deniedOutcome.sections).every(section=>section.availability==='forbidden'&&!section.items.length&&!section.url&&!section.session_id),
    'recording revocation preserves safe call metadata and removes all subordinate records and links');
  await control('recording_restore');
  await control('tasks_complete');
  check((await read('overview')).data.report.findings.filter(row=>row.profile_event_type==='profile_view').length===2,
    'bounded checked-view report retains both exact visit sources when active reviewed work leaves the source cap');
  await control('profile_off');
  check((await read('overview')).data.profile.enabled===false&&(await read('conversations',{filter:'all'})).data.items.length>0,'public Agent disabled does not hide owned historical inventory');
  check((await read('analytics_period',{ref:fixed.ref})).data.analytics_period.ref===fixed.ref,'public Profile visibility does not change fixed private analytics authority');
  await control('revoke');
  check((await request('analytics_period',{ref:fixed.ref})).response.status===403,'revoked Profile authority denies fixed-period evidence');
  for(const input of [{public_conversation_id:session.seed.primary},{chat_conversation_id:0}]){
    const denied=await request(input.public_conversation_id?'conversation':'overview',input);
    check(denied.response.status===403&&!denied.body.data,'revoked Profile grant denies both saved-thread and unsaved setup reads');
  }
  await control('restore');
  await control('profile_missing');
  check((await read('overview')).data.profile===null,'missing Profile setup is returned honestly without request-time creation');
  check(server.readWriteFailure()==='','all workspace GETs remain read-only through failures and status recovery');

  // Mount the production UI over those same real APIs, with only the DOM shimmed.
  const reset=await fetch(server.base+'/fixture/reset',{method:'POST',headers});const freshSeed=await reset.json();
  const document=workspaceDocument(),host=document.createElement('section');document.body.append(host);
  const uiWindow={VP3_PROFILE_WORKSPACE_V1:{endpoint:'/api/profile-specialist-workspace-v1.php',csrf:session.csrf,profileUrl:'/profile-agent.php'},dispatchEvent(){}};
  const trafficRead={result:null,error:null};
  uiWindow.VP3ProfileAnalytics={mountWorkspace(target,transport){
    Promise.all([transport.readAnalytics({propertyId:0,days:30}),transport.readIntelligence({})]).then(([analytics,revenue])=>{
      trafficRead.result={analytics,revenue};target.textContent='Retained traffic loaded through the production API.';
    },error=>{trafficRead.error=error;});
    return {dispose(){}};
  }};
  const uiContext=vm.createContext({window:uiWindow,document,crypto:webcrypto,URL,Intl,atob,btoa,AbortController,Event,setTimeout,clearTimeout,
    location:{origin:server.base,reload(){}},fetch:(url,options)=>fetch(url,{...options,headers:{...options.headers,Cookie:headers.Cookie}})});
  vm.runInContext(readFileSync(new URL('../profile-specialist-workspace-v1.js',import.meta.url),'utf8'),uiContext);
  const mounted=uiWindow.VP3ProfileWorkspaceV1.mountWorkspace(host,{scope:()=>({owner:1,agent:7,cid:11,binding:session.binding,hasChat:true}),selection:{tab:'conversations',publicConversationId:freshSeed.primary}});
  const waitFor=async predicate=>{for(let i=0;i<200;i++){if(predicate())return;await new Promise(resolve=>setTimeout(resolve,10));}throw new Error('Mounted real-API UI did not settle: '+host.textContent);};
  const uiButton=label=>host.querySelectorAll('button').find(row=>row.textContent===label);
  await waitFor(()=>uiButton('Take over')&&!uiButton('Take over').disabled);uiButton('Take over').click();
  await waitFor(()=>uiButton('Prepare private draft')&&!uiButton('Prepare private draft').disabled);
  check(host.textContent.includes('You are now responding'),'mounted production UI accepts canonical takeover projection');
  uiButton('Prepare private draft').click();
  await waitFor(()=>host.querySelector('[data-profile-reply-body]')?.value==='Here is a private draft for this exact visitor.');
  check(host.textContent.includes('Private draft ready to review'),'mounted UI validates actual durable draft wire fields');
  const replyInput=host.querySelector('[data-profile-reply-body]');replyInput.value='Edited through the mounted production UI.';replyInput.dispatchEvent({type:'input'});
  host.querySelector('[data-profile-reply]').dispatchEvent({type:'submit'});
  await waitFor(()=>host.textContent.includes('Your reply was sent to this visitor.'));
  check((await stored()).profile_agent_messages.filter(row=>row.sender_type==='owner'&&Number(row.conversation_id)===freshSeed.primary&&row.message==='Edited through the mounted production UI.').length===1,
    'mounted UI sends one explicit reviewed reply through real canonical authority');
  await mounted.refresh();await waitFor(()=>uiButton('Prepare private draft')&&!uiButton('Prepare private draft').disabled);
  uiButton('Prepare private draft').click();
  await waitFor(()=>host.querySelector('[data-profile-reply-body]')?.value==='Here is a private draft for this exact visitor.');
  const followup=await stored();
  check(followup.profile_owner_drafts_v1.length===2&&followup.profile_owner_drafts_v1.some(row=>row.status==='sent')&&followup.provider_dispatches.length===2,
    'a new explicit preparation after a sent reply preserves its earlier receipt');
  // Remove canonical permission evidence explicitly. This real API 503 must
  // not hide the independently authorized legacy traffic view.
  await control('analytics_period_off');
  mounted.select({tab:'analytics'});
  await waitFor(()=>uiButton('Retry completed period'));
  check(host.textContent.includes('Profile workspace could not be refreshed')&&uiButton('Traffic details')?.isConnected,
    'a real completed-period failure preserves the Analytics view selector');
  uiButton('Traffic details').click();await waitFor(()=>trafficRead.result||trafficRead.error);
  assert.equal(trafficRead.error,null);
  check(trafficRead.result.analytics.ready===true&&trafficRead.result.revenue.ready===true
    &&host.textContent.includes('Retained traffic loaded through the production API.'),
    'the retained traffic renderer receives its real authorized data after the period failure');
  await control('analytics_period_restore');
  mounted.select({tab:'calls',callView:'history',callId:freshSeed.outcomes.assisted.call_id,publicConversationId:freshSeed.outcomes.assisted.public_conversation_id});
  await waitFor(()=>host.querySelectorAll('[data-profile-transcript-segment]').length===50);
  check(host.textContent.includes('The saved call discussed public opening hours'),'mounted UI accepts the actual canonical saved analysis and section wire fields');
  uiButton('Load more transcript').click();await waitFor(()=>host.querySelectorAll('[data-profile-transcript-segment]').length===55);
  uiButton('Load more research').click();await waitFor(()=>host.querySelectorAll('[data-profile-research]').length===7);
  uiButton('Load more follow-ups').click();await waitFor(()=>host.querySelectorAll('[data-profile-followup]').length===23);
  check(host.querySelectorAll('[data-profile-transcript-segment]').length===55&&host.querySelectorAll('[data-profile-followup]').length===23,
    'mounted UI appends every page through the unchanged production API');
  await control('recording_revoke');await mounted.refresh();await waitFor(()=>host.textContent.includes('Your current access does not include'));
  check(host.textContent.includes('Call ended')&&!host.querySelector('[data-profile-transcript-segment]')&&!host.querySelector('[data-profile-followup]'),
    'mounted recording revocation clears old saved facts without losing authorized terminal call state');
  mounted.select({tab:'analytics',analyticsView:'periods',analyticsRef:freshSeed.analytics.ref});
  await waitFor(()=>host.querySelector('[data-profile-analytics-period]'));
  check(host.querySelector('[data-profile-analytics-period]').dataset.profileAnalyticsPeriod===freshSeed.analytics.ref
    &&host.textContent.includes('Period timezone: UTC'),'mounted fixed renderer accepts actual production aggregate wire fields');
  const originalPeriod=(await read('analytics_period',{ref:freshSeed.analytics.ref})).data.analytics_period;
  await control('timezone_change');await mounted.refresh();await waitFor(()=>host.querySelector('[data-profile-analytics-period]'));
  const preservedPeriod=(await read('analytics_period',{ref:freshSeed.analytics.ref})).data.analytics_period;
  check(preservedPeriod.ref===originalPeriod.ref&&preservedPeriod.window.timezone==='UTC'&&preservedPeriod.revision===originalPeriod.revision,
    'saved period and semantic proof stay fixed when only the current timezone setting changes');
  uiButton('Latest completed periods').click();await waitFor(()=>host.textContent.includes('Period timezone: Asia/Tokyo'));
  check(host.textContent.includes('Timezone source in this link: account choice'),'explicit latest action resolves current timezone settings');
  check(server.readWriteFailure()==='','mounted reads and completed-period failure perform no storage write or inference');
  mounted.dispose();
  console.log(`PROFILE_SPECIALIST_WORKSPACE_HTTP=PASS (${checks} checks)`);
}catch(error){console.error(server.logs());throw error;}
finally{await server.close();}
