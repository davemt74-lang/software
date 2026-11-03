import assert from 'node:assert/strict';
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import net from 'node:net';
import {spawn} from 'node:child_process';

const tmp=fs.mkdtempSync(path.join(os.tmpdir(),'vp3-release-profile-chat-'));
fs.cpSync('includes',path.join(tmp,'includes'),{recursive:true});fs.mkdirSync(path.join(tmp,'api'));
for(const name of ['chat-v236.php','chat-stream-v121.php','chat.php'])fs.copyFileSync('api/'+name,path.join(tmp,'api',name));
for(const name of ['agent-runtime-routing-v420.php','ai-settings.php'])fs.writeFileSync(path.join(tmp,'includes',name),'<?php // Controlled infrastructure supplied by fixture bootstrap.');
fs.copyFileSync('tests/fixtures/capability-chat-bootstrap.php',path.join(tmp,'includes/bootstrap.php'));
for(const name of ['phone-agent-schema-v1.php','workflow-agent-schema-v1.php','record-agent-schema-v1.php','media-agent-schema-v1.php','app-agent-remote-v1.php','system-app-agent-schema-v1.php','app-operation-schema-v1.php','release-agent-v1.php','profile-webmcp-agent-v1.php','profile-webmcp-outcomes-v1.php'])fs.copyFileSync('tests/fixtures/'+name,path.join(tmp,'includes',name));
fs.copyFileSync('tests/fixtures/release-profile-api-state-v1.php',path.join(tmp,'s11-state.php'));
const reserve=net.createServer();await new Promise(r=>reserve.listen(0,'127.0.0.1',r));const port=reserve.address().port;await new Promise(r=>reserve.close(r));
fs.mkdirSync(path.join(tmp,'sessions'));
const child=spawn(process.env.VP3_PHP_BINARY||'php',['-d','session.save_path='+path.join(tmp,'sessions'),'-S',`127.0.0.1:${port}`,'-t',tmp],{stdio:['ignore','ignore','pipe']});let logs='',cookie='';child.stderr.on('data',x=>logs+=x);
const base=`http://127.0.0.1:${port}`;let checks=0;
const endpoints=['chat-v236.php?agent=7','chat-stream-v121.php','chat.php'];
const named={agent_context:{user_agent_id:7}};
const releaseHeaders={'X-Fixture-Release-Ready':'1'};
const routed=(plan,headers=releaseHeaders)=>({...headers,'X-Fixture-Participant-Owner-Plan':JSON.stringify(plan)});
const readPlan=(view='list',extra={})=>({operation:'release_read',ids:['music.releases.read'],view,...extra});
const continuePlan={operation:'continue',ids:[]};
function retainCookie(r){const raw=r.headers.get('set-cookie');if(raw)cookie=raw.split(';')[0];}
function check(ok,why){assert.ok(ok,why);checks++;console.log('PASS '+why.split(': {')[0]);}
async function post(endpoint,message,extra={},headers={}){
 const r=await fetch(base+'/api/'+endpoint,{method:'POST',headers:{'Content-Type':'application/json',...(cookie?{Cookie:cookie}:{}),...headers},body:JSON.stringify({action:'send',message,csrf_token:'fixture',knowledge_scope:'off',...named,...extra})});retainCookie(r);
 const body=await r.text();let data;
 try{const events=body.trim().split('\n').map(x=>JSON.parse(x));data=endpoint.includes('stream')?(events.find(x=>x.type==='done')?.data??events.find(x=>x.type==='error')):events[0];}catch{throw Error(body+'\n'+logs.slice(-3000));}
 return {r,data,body};
}
async function state(action='',headers={}){const r=await fetch(base+'/s11-state.php'+(action?'?action='+action:''),{headers:{...(cookie?{Cookie:cookie}:{}),...headers}});retainCookie(r);const body=await r.text();try{return JSON.parse(body);}catch{throw Error(body+'\n'+logs.slice(-3000));}}
try{
 for(let i=0;i<60;i++){try{await state();break;}catch{await new Promise(r=>setTimeout(r,50));}}
 for(const [index,endpoint] of (process.env.VP3_S11_PROFILE_ONLY?[]:endpoints).entries()){
  await state('reset_releases');
  const initial=await state('',releaseHeaders);
  let r=await post(endpoint,'Show my release plans',{},routed(readPlan()));const cid=r.data.conversation_id;
  check(r.data?.ok===true&&r.data.agent_id===7&&r.data.answer.includes('Fixture Autumn Single')&&!r.body.includes('SECRET'),endpoint+' reads exact owned release plans: '+r.body);
  check(r.data.tool_receipt?.capability==='music.releases.read'&&r.data.tool_receipt.status==='read_complete'&&!r.data.tool_receipt.changes_applied,endpoint+' release read retains an honest server receipt');
  check((await state('',releaseHeaders)).fingerprint===initial.fingerprint,endpoint+' release read performs no record or schema mutation');
  for(const [view,extra,text] of [['detail',{release_id:101},'Fixture Autumn Single'],['items',{release_id:101},'Fixture artwork review'],['deadlines',{release_id:101,filter:'overdue'},'Fixture distribution checklist']]){
   r=await post(endpoint,'Show release '+view,{conversation_id:cid},routed(readPlan(view,extra)));
   check(r.data?.tool_receipt?.status==='read_complete'&&r.data.answer.includes(text)&&!r.body.includes('SECRET'),endpoint+' '+view+' reads use exact scoped saved evidence: '+r.body);
  }
  r=await post(endpoint,'Show my next release',{conversation_id:cid},routed(readPlan('list',{filter:'next'})));
  check(r.data.answer.includes('Fixture Autumn Single')&&!r.data.answer.includes('Fixture Archive Launch')&&!r.data.answer.includes('Fixture Paused Campaign'),endpoint+' next release excludes archived and paused records');
  r=await post(endpoint,'Show release #101',{conversation_id:cid},routed(continuePlan));
  check(r.data.tool_receipt?.capability==='music.releases.read'&&r.data.answer.includes('Fixture Autumn Single'),endpoint+' legacy exact release number reaches the same adapter: '+r.body);
  r=await post(endpoint,'Open release #101',{conversation_id:cid},routed(continuePlan));
  check(r.data.actions?.[0]?.url?.includes('&release=101'),endpoint+' explicit release navigation keeps the exact authorized target: '+r.body);
  r=await post(endpoint,'Open release #999',{conversation_id:cid},routed(continuePlan));
  check(r.data.tool_receipt?.status==='clarification_required'&&r.data.actions.length===0,endpoint+' unknown explicit release cannot navigate a generic workspace');
  r=await post(endpoint,'Show release "Fixture Autumn Single" or "Fixture Archive Launch"',{conversation_id:cid},routed(continuePlan));
  check(r.data.tool_receipt?.status==='clarification_required'&&r.data.actions.length===0,endpoint+' multiple literal release selectors require one exact choice');
  r=await post(endpoint,'Cancel release #101',{conversation_id:cid},routed(continuePlan));
  check(r.data.tool_receipt?.capability!=='workflows.manage'&&!r.data.tool_receipt?.changes_applied,endpoint+' release cancellation cannot select a workflow with the same number');
  r=await post(endpoint,'Show release #201',{conversation_id:cid},routed(readPlan('detail',{release_id:201})));
  check(r.data.tool_receipt?.status==='clarification_required'&&!r.body.includes('SECRET'),endpoint+' foreign release number never broadens into another workspace');
  r=await post(endpoint,'Show releases in foreign workspace',{conversation_id:cid},routed(readPlan('list',{workspace_id:21})));
  check(r.data.tool_receipt?.status==='blocked'&&!r.body.includes('SECRET'),endpoint+' workspace manager authority is checked even with general Chat access');
  r=await post(endpoint,'Can you create release plans?',{conversation_id:cid},routed({operation:'inspect',ids:['music.releases.read','music.releases.create']}));
  check(r.data.tool_receipt?.capability==='capabilities.inspect'&&r.data.answer.includes('internal release plan'),endpoint+' ability question reports the capability without creating a record');
  check((await state('',releaseHeaders)).fingerprint===initial.fingerprint,endpoint+' read and ability turns preserve the full release store');
  const title='Transport Release '+index,plan={operation:'release_create',ids:['music.releases.create'],title,target_date:'2030-02-03',workspace_id:11};
  r=await post(endpoint,`Create release "${title}" on 2030-02-03 in workspace #11`,{conversation_id:cid,agent_context:{user_agent_id:7,chat_user_message_id:1}},routed(plan));
  check(r.data.tool_receipt?.status==='completed'&&r.data.tool_receipt.changes_applied&&r.data.answer.includes(title)&&r.data.answer.includes('2030-02-03'),endpoint+' exact current request creates one canonical planning record: '+r.body);
  let saved=await state('',releaseHeaders),created=saved.created.find(x=>x.title===title);
  check(saved.created.length===1&&created?.workspace_id===11&&created?.target_date==='2030-02-03 12:00:00',endpoint+' canonical database stores the actual title and date');
  const turn=saved.turns.map(x=>({...x,context:JSON.parse(x.context_json)})).find(x=>x.context.release_creation_v1?.release_id===created.id);
  check(turn&&turn.id!==1&&turn.context.release_creation_v1.user_message_id===turn.id&&turn.context.release_creation_v1.agent_id===7,endpoint+' durable creation binding uses the real server turn, not supplied UI identity');
  r=await post(endpoint,'Did that work?',{conversation_id:cid},routed({operation:'diagnose',ids:[]}));
  check(r.data.answer.includes('internal release plan')&&!r.data.answer.includes('the requested change completed'),endpoint+' follow-up diagnosis preserves internal planning semantics');
  for(const message of ['Can you create release plans?',`If I approve later, create release "${title}" on 2030-02-03 in workspace #11`,`Create release "Wrong title" on 2030-02-03 in workspace #11`,`Create release "${title}" on 2030-02-04 in workspace #11`]){
   r=await post(endpoint,message,{conversation_id:cid},routed(plan));
   check(!r.data.tool_receipt?.changes_applied&&['failed','clarification_required'].includes(r.data.tool_receipt?.status),endpoint+' a model write cannot override the exact explicit saved request: '+r.body);
  }
  check((await state('',releaseHeaders)).created.length===1,endpoint+' rejected or mismatched model plans save no releases');
  const legacyTitle='Legacy Release '+index;
  r=await post(endpoint,`Create release "${legacyTitle}" on 2030-04-05 in workspace #11`,{conversation_id:cid},routed(continuePlan));
  check(r.data.tool_receipt?.status==='completed'&&r.data.answer.includes(legacyTitle),endpoint+' legacy creation receives the trusted server context: '+r.body);
  check((await state('',releaseHeaders)).created.length===2,endpoint+' deliberate new user turn creates its own distinct planning record');
  for(const invalidPlan of [readPlan('list',{owner_user_id:2}),readPlan('list',{workspace:'Fixture Music Workspace',workspace_id:11}),readPlan('detail',{release_id:'101'}),{...plan,target_date:'2030-02-31'},{...plan,status:'released'}]){
   r=await post(endpoint,'Show my release plans',{conversation_id:cid},routed(invalidPlan));
   check(r.data.tool_receipt?.capability==='capabilities.inspect'&&r.data.tool_receipt.reason==='invalid_plan',endpoint+' invalid release shape fails before reaching storage');
  }
  await state('bad_release');r=await post(endpoint,'Show my release plans',{conversation_id:cid},routed(readPlan()));
  check(r.data.tool_receipt?.status==='failed'&&!r.body.includes('SECRET'),endpoint+' malformed persisted release cannot produce partial success');
  await state('reset_releases');r=await post(endpoint,'Show my release plans',{},routed(readPlan(),{...releaseHeaders,'X-Fixture-Release-Mode':'empty'}));
  check(r.data.tool_receipt?.status==='no_matches'&&!r.data.tool_receipt.changes_applied,endpoint+' verified empty release storage remains distinct from failure');
  await state('reset_releases');r=await post(endpoint,'Show my release plans',{},routed(readPlan(),{...releaseHeaders,'X-Fixture-Release-Mode':'multi_workspace'}));
  check(r.data.tool_receipt?.status==='clarification_required'&&!r.data.answer.includes('Fixture Autumn Single'),endpoint+' multiple manageable workspaces require an explicit choice');
  await state('reset_releases');r=await post(endpoint,'Show my release plans',{},routed(readPlan(),{...releaseHeaders,'X-Fixture-Release-Mode':'disabled'}));
  check(r.data.tool_receipt?.status==='blocked'||r.data.tool_receipt?.status==='clarification_required',endpoint+' disabled owner workspace cannot return saved release data');
  check(!r.data.answer.includes('Fixture Autumn Single')&&!r.body.includes('SECRET'),endpoint+' disabled workspace hides record contents');
  await state('reset_releases');r=await post(endpoint,'Can you read release plans?',{},routed({operation:'inspect',ids:['music.releases.read']},{...releaseHeaders,'X-Fixture-Release-Mode':'missing_schema'}));
  check(r.data.answer.includes('database upgrade is required')&&!r.data.answer.includes('not permitted'),endpoint+' missing release schema reports the storage blocker without inventing permission denial');
 }
 for(const endpoint of endpoints){
  await state('reset_releases');await state('reset_profile');
  const profileHeaders={'X-Fixture-Profile-Ready':'1'},profilePlan={operation:'profile_webmcp_read',ids:['profile.webmcp.read']};
  const initial=await state('',profileHeaders);
  let r=await post(endpoint,'Inspect my public Profile WebMCP capabilities',{},routed(profilePlan,profileHeaders));const cid=r.data.conversation_id;
  check(r.data?.ok===true&&r.data.tool_receipt?.capability==='profile.webmcp.read'&&r.data.tool_receipt.status==='read_complete'&&r.data.answer.includes('booking'),endpoint+' Profile inspection reads canonical available domains: '+r.body);
  check(r.data.actions.length===0&&!r.body.includes('SECRET')&&!r.body.includes('resume_token')&&!r.data.tool_receipt.changes_applied,endpoint+' inspection contains no private text, handoff or execution claim');
  let current=await state('',profileHeaders);
  check(current.profile_fingerprint===initial.profile_fingerprint&&current.resume_count===0&&current.context_count===0,endpoint+' Profile inspection creates no ledger, token or schema records');
  r=await post(endpoint,'Can you use my public Profile?',{conversation_id:cid},routed({operation:'inspect',ids:['profile.webmcp.read','profile.webmcp.handoff','profile.webmcp.return.read']},profileHeaders));
  check(r.data.tool_receipt?.capability==='capabilities.inspect'&&!r.body.includes('resume_token'),endpoint+' Profile ability question only inspects the inventory');
  const goal='Open my public Profile to book an appointment',handoff={operation:'profile_webmcp_handoff',ids:['profile.webmcp.handoff'],query:goal};
  r=await post(endpoint,'Can you use my public Profile?',{conversation_id:cid},routed(handoff,profileHeaders));
  check(r.data.tool_receipt?.status==='clarification_required'&&r.data.actions.length===0&&(await state('',profileHeaders)).resume_count===0,endpoint+' model handoff cannot override an ability-only original request');
  r=await post(endpoint,goal,{conversation_id:cid},routed(handoff,profileHeaders));
  check(r.data.tool_receipt?.capability==='profile.webmcp.handoff'&&r.data.tool_receipt.status==='clarification_required'&&r.data.tool_receipt.reason==='confirmation_required'&&!r.data.tool_receipt.changes_applied,endpoint+' explicit Profile request prepares only the existing confirmation handoff: '+r.body);
  check(r.data.actions.length===1&&r.data.actions[0].auto===false&&r.data.actions[0].url.includes('webmcp_resume=')&&r.data.profile_webmcp_plan?.execution_allowed===false,endpoint+' full authorizer preserves only the current bound review link: '+r.body);
  check(r.data.profile_webmcp_plan.handoff.return_path.includes('agent=7&conversation_id='+cid),endpoint+' Profile return path keeps the actual named Agent and conversation');
  current=await state('',profileHeaders);
  check(current.resume_count===1&&current.context_count===1&&current.profile_fingerprint===initial.profile_fingerprint,endpoint+' handoff creates one session context without a transactional record');
  let returned=await state('browser_return',profileHeaders);
  check(returned.returned?.outcome?.verification==='unverified',endpoint+' forged browser completion remains unverified on actual return consumption');
  const returnPlan={operation:'profile_webmcp_return_read',ids:['profile.webmcp.return.read']};
  r=await post(endpoint,'Check the returned Profile action status',{conversation_id:cid},routed(returnPlan,profileHeaders));
  check(r.data.tool_receipt?.status==='failed'&&r.data.tool_receipt.reason==='outcome_unverified'&&r.data.tool_receipt.profile_webmcp_outcome?.verification==='unverified'&&r.data.answer.includes('could not be verified'),endpoint+' browser success text cannot become a Chat success receipt: '+r.body);
  check(r.data.actions.length===0&&!r.body.includes('SECRET')&&!r.body.includes('return_token')&&!r.body.includes('session_hash'),endpoint+' unverified return strips private proof and action fields');
  for(const phase of ['prepared','recorded']){
   r=await post(endpoint,goal,{conversation_id:cid},routed(handoff,profileHeaders));
   check(r.data.tool_receipt?.reason==='confirmation_required',endpoint+' new deliberate handoff is separately bound');
   returned=await state(phase+'_return',profileHeaders);
   check(returned.returned?.outcome?.verification==='verified'&&returned.returned.outcome.state===phase,endpoint+' canonical '+phase+' evidence survives return consumption');
   r=await post(endpoint,'Check the returned Profile action status',{conversation_id:cid},routed(returnPlan,profileHeaders));
   check(r.data.tool_receipt?.status==='read_complete'&&!r.data.tool_receipt.changes_applied&&r.data.tool_receipt.profile_webmcp_outcome?.state===phase,endpoint+' '+phase+' outcome is a verified read, not another transaction: '+r.body);
   check(!r.body.includes('SECRET')&&!r.body.includes('session_hash')&&!r.body.includes('confirmation_token')&&!r.data.answer.includes('The Profile action completed'),endpoint+' '+phase+' result exposes only bounded phase facts');
   r=await post(endpoint,'Did that work?',{conversation_id:cid},routed({operation:'diagnose',ids:[]},profileHeaders));
   check(r.data.answer.includes(phase==='prepared'?'awaits confirmation':'booking action was recorded')&&!r.data.answer.includes('the requested change completed'),endpoint+' '+phase+' Profile outcome survives history and follow-up diagnosis: '+r.body);
  }
  r=await post(endpoint,'Continue',{conversation_id:cid},routed(continuePlan,profileHeaders));
  check(r.data.tool_receipt?.capability!=='profile.webmcp.return.read',endpoint+' unrelated generic continuation cannot revive an earlier Profile action');
  r=await post(endpoint,'What happened on my Profile?',{conversation_id:cid},routed(continuePlan,profileHeaders));
  check(r.data.tool_receipt?.capability==='profile.webmcp.return.read'&&r.data.tool_receipt.profile_webmcp_outcome?.state==='recorded',endpoint+' explicit legacy return query uses fresh canonical proof: '+r.body);
  r=await post(endpoint,'Check the returned Profile action status',{},routed(returnPlan,profileHeaders));
  check(r.data.tool_receipt?.status==='blocked'&&!r.data.tool_receipt?.profile_webmcp_outcome,endpoint+' another conversation cannot borrow the old Profile result');
  r=await post(endpoint,'Check the returned Profile action status',{agent_context:{user_agent_id:8}},routed(returnPlan,profileHeaders));
  check(r.data.tool_receipt?.status==='blocked'&&!r.data.tool_receipt?.profile_webmcp_outcome,endpoint+' another Agent cannot borrow the old Profile result');
  await state('damage_profile_receipt',profileHeaders);
  r=await post(endpoint,'Check the returned Profile action status',{conversation_id:cid},routed(returnPlan,profileHeaders));
  check(r.data.tool_receipt?.status==='failed'&&r.data.tool_receipt.reason==='outcome_unverified'&&!r.body.includes('SECRET'),endpoint+' current damaged ledger invalidates the prior recorded claim');
  r=await post(endpoint,'Inspect my public Profile capabilities',{conversation_id:cid},routed(profilePlan,{...profileHeaders,'X-Fixture-Observation-Denied':'1'}));
  check(r.data.tool_receipt?.status==='blocked'&&r.data.actions.length===0&&!r.body.includes('resume_token'),endpoint+' changed Profile permission removes answer, links and execution evidence');
  for(const invalidPlan of [{...profilePlan,owner_user_id:2},{...profilePlan,url:'/foreign-profile'},{...returnPlan,context_id:'forged'},{...handoff,return_token:'forged'},{...handoff,query:[]}]){
   r=await post(endpoint,'Inspect my public Profile capabilities',{conversation_id:cid},routed(invalidPlan,profileHeaders));
   check(r.data.tool_receipt?.capability==='capabilities.inspect'&&r.data.tool_receipt.reason==='invalid_plan',endpoint+' arbitrary Profile identities, proof or fields are rejected before execution');
  }
  await state('reset_profile');
  r=await post(endpoint,'Inspect my public Profile capabilities',{},routed(profilePlan,{...profileHeaders,'X-Fixture-Profile-Mode':'profile_private'}));
  check(r.data.tool_receipt?.status==='no_matches'&&r.data.answer.includes('not currently public')&&r.data.actions.length===0,endpoint+' private Profile has explicit unavailable capability state');
  check((await state('',profileHeaders)).resume_count===0,endpoint+' private Profile inspection creates no continuation');
 }
 console.log(`RELEASE_PROFILE_CHAT_API=PASS (${checks} cases; production typed/voice/stream/legacy endpoints)`);
}catch(e){console.error(logs.slice(-5000));throw e;}
finally{child.kill();fs.rmSync(tmp,{recursive:true,force:true});}
