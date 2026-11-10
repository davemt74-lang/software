import assert from 'node:assert/strict';
import {spawn,execFileSync} from 'node:child_process';
import {copyFileSync,mkdirSync,mkdtempSync,rmSync,writeFileSync} from 'node:fs';
import net from 'node:net';
import {tmpdir} from 'node:os';
import {join} from 'node:path';
import {fileURLToPath} from 'node:url';

// The production controller and canonical lease/health readers run against the
// existing signed-in SQLite fixture. A fixture POST creates one real claim;
// every production work GET is also exercised with SQLite query_only enabled.
const source=fileURLToPath(new URL('../',import.meta.url)),temp=mkdtempSync(join(tmpdir(),'vp3-work-status-'));
const php=process.env.VP3_PHP_BINARY||'php';
const probe=net.createServer();await new Promise(resolve=>probe.listen(0,'127.0.0.1',resolve));
const port=probe.address().port;await new Promise(resolve=>probe.close(resolve));
const base='http://127.0.0.1:'+port;
mkdirSync(join(temp,'api'));mkdirSync(join(temp,'includes'));
copyFileSync(join(source,'api/agent-top-of-mind-v1.php'),join(temp,'api/agent-top-of-mind-v1.php'));
writeFileSync(join(temp,'includes/agent-top-of-mind-v1.php'),'<?php require_once '+JSON.stringify(join(source,'includes/agent-top-of-mind-v1.php'))+';');
writeFileSync(join(temp,'includes/bootstrap.php'),[
  '<?php session_start();require '+JSON.stringify(join(source,'tests/fixtures/initiative-chat-loop-http.php'))+';',
  'if(isset($_SERVER["HTTP_X_FIXTURE_PARTICIPANT"]))$GLOBALS["VP3_PARTICIPANT_RESOURCE_SCOPE_V1"]=["owner"=>1,"agent"=>7,"participant"=>2,"revision"=>1];',
  'if(isset($_SERVER["HTTP_X_FIXTURE_READ_ONLY"]))db()->exec("PRAGMA query_only=ON");'
].join('\n'));
writeFileSync(join(temp,'router.php'),[
  '<?php $path=parse_url($_SERVER["REQUEST_URI"],PHP_URL_PATH);',
  'if($path==="/health"){echo "ready";return;}',
  'if($path==="/api/agent-top-of-mind-v1.php"){require __DIR__.$path;return;}',
  'require __DIR__."/includes/bootstrap.php";header("Content-Type:application/json");',
  'if($path==="/session"){echo json_encode(["binding"=>vp3_catch_up_page_binding_v1(current_user())]);return;}',
  'if($path==="/counts"){echo json_encode(["models"=>(int)db()->query("SELECT COUNT(*) FROM fixture_model_calls")->fetchColumn(),"runs"=>(int)db()->query("SELECT COUNT(*) FROM agent_workflow_runs")->fetchColumn()]);return;}',
  'if($_SERVER["REQUEST_METHOD"]!=="POST"||($_SERVER["HTTP_X_FIXTURE_CSRF"]??"")!==csrf_token()){http_response_code(403);echo "{}";return;}',
  '$u=current_user();$a=vp3_agent_chat_resolve_agent_v380(db(),$u,7);$principal=vp3_agent_chat_principal_v380($u,$a);',
  'if($path==="/seed"){',
  'vp3_initiative_chat_v1(db(),$u,$principal,["conversation_id"=>11],"on",null);$policy=vp3_initiative_policy_v1($u,7);$coverage=[];',
  '$e=vp3_initiative_evidence_v1(db(),$u,new DateTimeImmutable("now",new DateTimeZone("UTC")),false,[],$coverage,["task:100"],7);$row=$e["task:100"];',
  '$goal=["binding"=>$policy,"sources"=>["task:100"=>$row["revision"]],"canonical_sources"=>["task:100"=>$row["canonical_source_revision"]??$row["revision"]],"tool"=>"work.prepare_private","primary"=>"task:100","focus"=>"fixture-owner-private-focus","report"=>true];',
  'vp3_initiative_enqueue_v1(db(),$u,"initiative.prepare",$goal,hash("sha256","work-status-http"));$id=(int)db()->query("SELECT MAX(id) FROM agent_workflow_runs")->fetchColumn();',
  '$claim=agent_job_claim_run_v1900(db(),$u,$id,"cloud",agent_worker_cloud_worker_id_v1910(1),180);',
  'if(!$claim)throw new RuntimeException("The real worker did not claim fixture work.");',
  'agent_job_heartbeat_v1900(db(),$u,$id,(int)$claim["action"]["id"],$claim["lease_token"],42,"fixture-owner-private-progress",240);',
  'echo json_encode(["run_id"=>$id]);return;}',
  'if($path==="/mutate"){',
  '$mode=$_GET["mode"]??"";',
  'if($mode==="action_failed")db()->exec("UPDATE agent_workflow_actions SET status=\'failed\'");',
  'if($mode==="action_restore")db()->exec("UPDATE agent_workflow_actions SET status=\'executing\'");',
  'if($mode==="expired")db()->exec("UPDATE agent_workflow_runs SET lease_expires_at=\'2000-01-01 00:00:00\'");',
  'if($mode==="terminal")db()->exec("UPDATE agent_workflow_runs SET status=\'completed\'");',
  'if($mode==="paused")vp3_initiative_chat_v1(db(),$u,$principal,["conversation_id"=>11],"off",null);',
  'echo "{}";return;}',
  'http_response_code(404);echo "{}";'
].join('\n'));
assert.match(execFileSync(php,['-l',join(temp,'router.php')],{encoding:'utf8'}),/No syntax errors/);
let logs='';
const server=spawn(php,['-d','session.save_path='+temp,'-S','127.0.0.1:'+port,'-t',temp,join(temp,'router.php')],{
  env:{...process.env,VP3_IMAGE_TEST_DSN:'sqlite:'+join(temp,'working.sqlite')},stdio:['ignore','pipe','pipe']
});
server.stdout.on('data',value=>{logs+=value;});server.stderr.on('data',value=>{logs+=value;});
try{
  for(let n=0;;n++){
    try{if((await fetch(base+'/health')).ok)break;}catch{}
    if(n>60)throw Error('PHP work fixture did not start: '+logs);
    await new Promise(resolve=>setTimeout(resolve,50));
  }
  const sessionResponse=await fetch(base+'/session'),session=await sessionResponse.json();
  const cookie=sessionResponse.headers.get('set-cookie').split(';')[0];
  const headers={Cookie:cookie,'X-VP3-Working-Attention':session.binding,'X-Fixture-Read-Only':'1'};
  const postHeaders={Cookie:cookie,'X-Fixture-Csrf':'fixture'};
  const endpoint=base+'/api/agent-top-of-mind-v1.php?view=work&agent_id=7';
  const read=async(extra='')=>{
    const response=await fetch(endpoint+extra,{headers});
    assert.equal(response.status,200,await response.clone().text());
    assert.match(response.headers.get('cache-control'),/no-store/);
    return (await response.json()).state;
  };
  const cold=await read();
  assert.equal(cold.contract,'vp3.agent.work.v1');assert.equal(cold.conversation_id,0);
  assert.deepEqual(cold.health,{state:'idle',enabled:false,active_jobs:[]});
  assert.deepEqual(await fetch(base+'/counts',{headers}).then(response=>response.json()),{models:0,runs:0});
  const seed=await fetch(base+'/seed',{method:'POST',headers:postHeaders});
  assert.equal(seed.status,200,await seed.clone().text());const {run_id}=await seed.json();
  const state=await read();
  assert.deepEqual(Object.keys(state).sort(),['agent_id','contract','conversation_id','health','observed_at','owner_user_id','working_attention_binding']);
  assert.equal(state.owner_user_id,1);assert.equal(state.agent_id,7);assert.equal(state.conversation_id,11);
  assert.equal(state.working_attention_binding,session.binding);assert.equal(state.health.enabled,true);
  assert.equal(state.health.active_jobs.length,1);assert.equal(state.health.active_jobs[0].id,'workflow:'+run_id);
  assert.equal(state.health.active_jobs[0].kind,'preparation');
  assert.ok(state.health.active_jobs[0].expiresAt>Date.now());
  assert.equal(JSON.stringify(state).includes('fixture-owner-private'),false);
  await read();await read('&conversation_id=11');
  assert.equal((await fetch(endpoint+'&conversation_id=12',{headers})).status,403);
  assert.equal((await fetch(endpoint,{method:'POST',headers})).status,405);
  assert.equal((await fetch(endpoint,{headers:{...headers,'X-VP3-Working-Attention':'wrong'}})).status,403);
  assert.equal((await fetch(endpoint,{headers:{...headers,'X-Fixture-Anonymous':'1'}})).status,403);
  assert.equal((await fetch(endpoint,{headers:{...headers,'X-Fixture-Denied':'1'}})).status,403);
  assert.equal((await fetch(endpoint,{headers:{...headers,'X-Fixture-Participant':'1'}})).status,403);
  assert.equal((await fetch(endpoint.replace('agent_id=7','agent_id=-1'),{headers})).status,422);
  assert.equal((await fetch(endpoint.replace('view=work','view=other'),{headers})).status,422);
  const other=await fetch(endpoint.replace('agent_id=7','agent_id=8'),{headers}).then(response=>response.json());
  assert.equal(other.ok,true);assert.equal(other.state.agent_id,8);assert.equal(other.state.conversation_id,0);
  assert.deepEqual(other.state.health.active_jobs,[]);
  const foreign=await fetch(base+'/session',{headers:{Cookie:cookie,'X-Fixture-User':'2'}}).then(response=>response.json());
  assert.equal((await fetch(endpoint,{headers:{Cookie:cookie,'X-Fixture-User':'2','X-VP3-Working-Attention':foreign.binding}})).status,403);
  for(const mode of ['action_failed','action_restore','expired','terminal','paused']){
    const changed=await fetch(base+'/mutate?mode='+mode,{method:'POST',headers:postHeaders});
    assert.equal(changed.status,200,await changed.clone().text());
    const work=await read();
    assert.equal(work.health.active_jobs.length,mode==='action_restore'?1:0,mode);
  }
  assert.deepEqual(await fetch(base+'/counts',{headers}).then(response=>response.json()),{models:0,runs:1});
  console.log('PASS real work-only GET resolves saved-policy Chat and reports only a matching live claim; queued/cold, failed action, expired, terminal and paused work remain inactive');
  console.log('PASS work-only GET enforces method, owner, exact Agent/Chat, session, participant and permission boundaries without exposing source/progress text');
  console.log('PASS repeated query_only GETs invoke zero models, create zero work and leave the full Top of Mind feed unopened');
}catch(error){console.error(logs.slice(-7000));throw error;}
finally{server.kill('SIGTERM');if(server.exitCode===null)await new Promise(resolve=>server.once('exit',resolve));rmSync(temp,{recursive:true,force:true});}
