import assert from 'node:assert/strict';
import {startProfileSpecialistReportServer} from './fixtures/profile-specialist-report-server.mjs';

const server=await startProfileSpecialistReportServer();
let checks=0;
const check=(truth,label)=>{assert.ok(truth,label);checks++;};
try{
  const sessionResponse=await fetch(server.base+'/session');
  assert.equal(sessionResponse.status,200,await sessionResponse.clone().text());
  const session=await sessionResponse.json();
  const cookie=sessionResponse.headers.get('set-cookie').split(';')[0];
  const headers={Cookie:cookie,'X-VP3-Working-Attention':session.binding};
  const path='/api/agent-activity-hub-v1.php?view=profile&agent_id=7&conversation_id=11';
  const response=await fetch(server.base+path,{headers});
  assert.equal(response.status,200,await response.clone().text());
  const {state}=await response.json();
  check(state.owner_user_id===1&&state.agent_id===7&&state.conversation_id===11,'actual GET binds the private owner, Agent and Chat');
  check(state.working_attention_binding===session.binding,'actual GET retains the browser session binding');
  check(response.headers.get('cache-control').includes('no-store'),'private Profile report is never cached');
  check(state.profile_report?.contract==='vp3.profile-report.v1'&&state.profile_report.coverage==='ready','actual adapter produces a ready current Profile report');
  check(state.sections.length===0,'Profile report bypasses unrelated workspace readers');
  check(state.specialists?.length===1&&state.specialists[0].id==='profile'&&state.specialists[0].source_coverage==='ready','Profile status reflects the same source read');
  const report=state.profile_report;
  check(report.findings.length>0&&report.findings.every(row=>/^profile:(conversation|event|call|followup):[1-9][0-9]*$/.test(row.ref)
    &&/^[a-f0-9]{64}$/.test(row.revision)),'report carries exact current source references and revisions');
  for(const field of ['key_findings','actions_followups','open_questions','questions_for_you']){
    check(Array.isArray(report[field])&&report[field].length<=6,field+' follows the shared source-backed section contract');
  }
  check(typeof report.next_step==='string'&&report.next_step.length>0,'report includes a current next step');
  check(!JSON.stringify(state).includes('credential_hash')&&!JSON.stringify(state).includes('buyer_ciphertext'),'private authority secrets are absent');
  for(const coverage of ['limited','unavailable','unconfigured']){
    const result=await fetch(server.base+path,{headers:{...headers,'X-VP3-Test-Profile-State':coverage}});
    assert.equal(result.status,200,await result.clone().text());
    const current=(await result.json()).state;
    check(current.profile_report.coverage===coverage&&current.coverage.profile_report===coverage,coverage+' source state reaches the report unchanged');
    if(coverage!=='limited')check(current.profile_report.findings.length===0&&current.profile_report.key_findings.length===0
      &&current.profile_report.next_step==='',coverage+' never renders stale facts or a false all-clear');
  }
  const unsaved=await fetch(server.base+path.replace('conversation_id=11','conversation_id=0'),{headers});
  check((await unsaved.json()).state.profile_report.coverage==='unconfigured','unsaved Chat does not scan owner Profile evidence');
  for(const permission of ['profile_agent_access','profile_chat_access']){
    for(const timing of ['missing','late']){
      const denied=await fetch(server.base+path,{headers:{...headers,'X-VP3-Test-Profile-State':timing+'_'+permission}});
      const body=await denied.json();
      check(denied.status===403,timing+' '+permission+' denies the saved-Chat report through the production GET');
      check(body.ok===false&&!body.state&&!body.profile_report,timing+' '+permission+' exposes no earlier report or source facts');
      if(timing==='late')check(server.readLateRevocation()===permission.replace('_access','.access'),
        permission+' was revoked only after the real source transaction completed');
    }
  }
  for(const [url,options,expected,label] of [
    [path,{headers:{...headers,'X-VP3-Working-Attention':'wrong'}},403,'wrong session'],
    [path.replace('agent_id=7','agent_id=999'),{headers},403,'unowned Agent'],
    [path.replace('conversation_id=11','conversation_id=999'),{headers},403,'unowned Chat'],
    [path,{headers,method:'POST'},405,'read-only method'],
    [path+'&collection=tasks',{headers},422,'unrelated collection']
  ])check((await fetch(server.base+url,options)).status===expected,label+' is rejected by production dispatch');
  check(server.readWriteFailure()==='','report GETs neither mutate fixture storage nor call inference');
  console.log(`AGENT_PROFILE_SPECIALIST_REPORT_HTTP=PASS (${checks} checks)`);
}catch(error){console.error(server.logs());throw error;}
finally{await server.close();}
