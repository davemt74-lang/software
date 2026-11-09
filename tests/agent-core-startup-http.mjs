import assert from 'node:assert/strict';
import {spawn} from 'node:child_process';
import {copyFileSync,existsSync,mkdirSync,mkdtempSync,rmSync,writeFileSync} from 'node:fs';
import net from 'node:net';
import {tmpdir} from 'node:os';
import {join} from 'node:path';
import {fileURLToPath} from 'node:url';

// Two real PHP workers share one real PHP session. Only the optional heavy
// projection is held at a fixture gate; both HTTP controllers, automatic
// adoption, policy storage and read-only health remain production.
const source=fileURLToPath(new URL('../',import.meta.url));
const temp=mkdtempSync(join(tmpdir(),'vp3-core-startup-'));
const probe=net.createServer();
await new Promise(resolve=>probe.listen(0,'127.0.0.1',resolve));
const port=probe.address().port;
await new Promise(resolve=>probe.close(resolve));
const base='http://127.0.0.1:'+port,gate=join(temp,'projection');
mkdirSync(join(temp,'api'));mkdirSync(join(temp,'includes'));
for(const name of ['cognitive-presentation-v510.php','agent-activity-v94.php'])copyFileSync(join(source,'api',name),join(temp,'api',name));
writeFileSync(join(temp,'includes','bootstrap.php'),'<?php session_start(); require '+JSON.stringify(join(source,'tests/fixtures/initiative-chat-loop-http.php'))+';');
for(const name of ['agent-initiative-runtime-v1.php','agent-initiative-lifecycle-v1.php','agent-connection-gmail-github-notice-v1.php']){
  writeFileSync(join(temp,'includes',name),'<?php require_once '+JSON.stringify(join(source,'includes',name))+';');
}
const router=join(temp,'router.php');
writeFileSync(router,[
  '<?php $path=parse_url($_SERVER["REQUEST_URI"],PHP_URL_PATH);',
  'if($path==="/health"){echo "ready";return;}',
  'if(in_array($path,["/api/cognitive-presentation-v510.php","/api/agent-activity-v94.php"],true)){require __DIR__.$path;return;}',
  'require __DIR__."/includes/bootstrap.php";header("Content-Type:application/json");',
  'if($path==="/session"){echo json_encode(["csrf"=>csrf_token(),"binding"=>vp3_catch_up_page_binding_v1(current_user())]);return;}',
  'if($path==="/fixture-state")initiative_http_state_v1();',
  'if($path==="/malformed-policy"){$body=json_decode(file_get_contents("php://input"),true);if($_SERVER["REQUEST_METHOD"]!=="POST"||($body["csrf_token"]??"")!==csrf_token()){http_response_code(419);exit;}$q=db()->prepare("UPDATE agent_memory_items SET metadata_json=? WHERE user_id=1 AND user_agent_id=7 AND memory_type=\'initiative_policy\' AND subject=\'owner-opt-in\'");$q->execute(["{broken"]);echo json_encode(["ok"=>true]);return;}',
  'if($path==="/raw-policy"){echo json_encode(["metadata"=>db()->query("SELECT metadata_json FROM agent_memory_items WHERE user_id=1 AND user_agent_id=7 AND memory_type=\'initiative_policy\' AND subject=\'owner-opt-in\'")->fetchColumn()]);return;}',
  'http_response_code(404);echo "{}";'
].join('\n'));
let logs='',held;
const server=spawn(process.env.VP3_PHP_BINARY||'php',['-S','127.0.0.1:'+port,'-t',temp,router],{
  env:{...process.env,PHP_CLI_SERVER_WORKERS:'2',VP3_IMAGE_TEST_DSN:'sqlite:'+join(temp,'working.sqlite'),VP3_INITIATIVE_FIXTURE_GATE:gate},
  detached:true,stdio:['ignore','pipe','pipe']
});
server.stdout.on('data',value=>{logs+=value;});server.stderr.on('data',value=>{logs+=value;});server.on('error',error=>{logs+=error.message;});
try{
  for(let attempt=0;;attempt++){
    try{if((await fetch(base+'/health')).ok)break;}catch{}
    if(attempt>50)throw new Error('PHP workers did not start: '+logs);
    await new Promise(resolve=>setTimeout(resolve,50));
  }
  const sessionResponse=await fetch(base+'/session'),session=await sessionResponse.json();
  const cookie=sessionResponse.headers.get('set-cookie').split(';')[0];
  const headers={'Content-Type':'application/json','Cookie':cookie,'X-VP3-Working-Attention':session.binding};
  held=fetch(base+'/api/cognitive-presentation-v510.php?action=state&agent_id=7&conversation_id=11&include_working_attention=0&include_live_context=1&include_initiative_runtime=1',{
    headers:{...headers,'X-Fixture-Hold-Projection':'1'}
  });
  for(let attempt=0;!existsSync(gate+'.entered');attempt++){
    if(attempt>100)throw new Error('The actual GET did not reach its held projection.');
    await new Promise(resolve=>setTimeout(resolve,20));
  }
  const heartbeat=sequence=>fetch(base+'/api/agent-activity-v94.php',{method:'POST',headers,body:JSON.stringify({
    action:'heartbeat',csrf_token:session.csrf,surface:'chat',state:'working',reason:'startup-regression',
    context:{conversation_id:11,visible:true,focused:true,chat_focused:true,chat_busy:false,
      activity_tab:'f'.repeat(32),activity_sequence:sequence,activity_observed_at:Date.now(),path:'/chat.php',task_kind:'chat'}
  })});
  const started=Date.now();
  const response=await heartbeat(1).then(async response=>({status:response.status,body:await response.json()}));
  assert.ok(Date.now()-started<2000,'the same-session heartbeat must complete before the held projection can time out');
  assert.equal(response.status,200,JSON.stringify(response));
  assert.ok(!existsSync(gate+'.release'),'the heartbeat completed while the same-session GET was still blocked');
  const core=response.body.agent_core_context;
  assert.ok(core,'the actual accepted helper must return bound runtime health');
  assert.equal(core.owner_user_id,1);assert.equal(core.agent_id,7);assert.equal(core.conversation_id,11);
  assert.equal(core.working_attention_binding,session.binding);assert.equal(core.health.enabled,true);
  writeFileSync(gate+'.release','1');
  const projection=await held;held=null;
  assert.equal(projection.status,200);
  assert.equal((await projection.json()).state.live_context.initiative.runtime.enabled,true);
  const state=await fetch(base+'/fixture-state',{headers}).then(response=>response.json());
  assert.equal(state.model_calls.length,0);assert.equal(state.runs.length,0);
  console.log('PASS two actual PHP workers: a held heavy GET releases the shared session, accepted heartbeat attaches exact named-Agent core before projection release, and both paths perform zero inference');

  assert.equal((await fetch(base+'/malformed-policy',{method:'POST',headers,body:JSON.stringify({csrf_token:session.csrf})})).status,200);
  const rejected=await heartbeat(2).then(response=>response.json());
  assert.equal(rejected.agent_core_context,undefined);
  assert.ok(rejected.agent_core_context_error,'a malformed stored preference must return a bound failure rather than leave first-health pending forever');
  assert.equal(rejected.agent_core_context_error.owner_user_id,1);
  assert.equal(rejected.agent_core_context_error.conversation_id,11);
  assert.equal(rejected.agent_core_context_error.working_attention_binding,session.binding);
  assert.equal((await fetch(base+'/raw-policy',{headers}).then(response=>response.json())).metadata,'{broken');
  console.log('PASS actual lifecycle refuses a malformed stored preference, preserves it, and returns explicit bound failure metadata without manufacturing active health');
}catch(error){
  console.error(logs.slice(-8000));throw error;
}finally{
  writeFileSync(gate+'.release','1');
  if(held)await held.catch(()=>{});
  if(server.pid&&server.exitCode===null&&server.signalCode===null){
    try{process.kill(-server.pid,'SIGTERM');}catch{}
    await new Promise(resolve=>server.once('exit',resolve));
  }
  rmSync(temp,{recursive:true,force:true});
}
