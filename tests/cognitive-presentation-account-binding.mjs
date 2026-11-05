import assert from 'node:assert/strict';
import {execFileSync} from 'node:child_process';
import {createHmac} from 'node:crypto';
import {copyFileSync, mkdirSync, mkdtempSync, rmSync, writeFileSync} from 'node:fs';
import {tmpdir} from 'node:os';
import {join} from 'node:path';
import {fileURLToPath} from 'node:url';

const temp = mkdtempSync(join(tmpdir(), 'vp3-cognitive-binding-'));
const runner = join(temp, 'run.php');
const php = process.env.VP3_PHP_BINARY || 'php';
const binding = (owner, session) => createHmac('sha256','fixture-csrf').update(`working-attention|${owner}|${session}`).digest('hex');
const ownerA = binding(1,'owner-a');
const ownerB = binding(2,'owner-b');

try {
  mkdirSync(join(temp,'api'));
  mkdirSync(join(temp,'includes'));
  copyFileSync(new URL('../api/cognitive-presentation-v510.php',import.meta.url),join(temp,'api','cognitive-presentation-v510.php'));
  copyFileSync(new URL('../includes/agent-chat-boundary-v380.php',import.meta.url),join(temp,'includes','agent-chat-boundary-v380.php'));
  writeFileSync(runner,`<?php
$fixture=json_decode($argv[1],true);
$trace=[];
$_SERVER['REQUEST_METHOD']=$fixture['method'];
if(isset($fixture['binding']))$_SERVER['HTTP_X_VP3_WORKING_ATTENTION']=$fixture['binding'];
$_GET=$fixture['query'];
$_POST=$fixture['body'];
session_id($fixture['session']);
if(!empty($fixture['startSession']))session_start();
register_shutdown_function(static function(){global $trace;echo "\\n".json_encode(['status'=>http_response_code(),'trace'=>$trace]);});
require __DIR__.'/api/cognitive-presentation-v510.php';`);
  writeFileSync(join(temp,'includes','bootstrap.php'),`<?php
require __DIR__.'/agent-chat-boundary-v380.php';
const VP3_COGNITIVE_PRESENTATION_V510='fixture';
function current_user(){global $fixture;return ['id'=>$fixture['owner']];}
function has_permission($capability,$user){return $capability==='chat.access';}
function csrf_token(){return 'fixture-csrf';}
function vp3_catch_up_page_binding_v1($user){return hash_hmac('sha256','working-attention|'.$user['id'].'|'.session_id(),csrf_token());}
function db(){
    global $trace;$trace[]='db';$pdo=new PDO('sqlite::memory:');
    $pdo->exec('CREATE TABLE chat_conversations (id INTEGER,user_id INTEGER,user_agent_id INTEGER);
      INSERT INTO chat_conversations VALUES (42,1,NULL),(43,1,7),(44,2,NULL),(45,1,8)');
    return $pdo;
}
function user_agent_get_v236($pdo,$owner,$agent){return $owner===1&&in_array($agent,[7,8],true)?['id'=>$agent,'is_active'=>$agent===7]:null;}
function user_agent_principal_v236($user,$agent){return ['kind'=>$agent?'user_agent':'system','owner_user_id'=>$user['id'],'agent_id'=>(int)($agent['id']??0)];}
function vp3_cognitive_presentation_schema_ready_v510($pdo){return true;}
function vp3_cognitive_agent_namespace_v500($pdo,$user,$agent){return $agent?'agent:'.$agent:'system';}
function vp3_cognitive_presentation_state_v510($pdo,$user,$namespace,$agent,$working,$preview,$liveConversation=null){
    global $trace;$trace[]='state';
    $state=['owner'=>$user['id'],'namespace'=>$namespace,'working'=>$working,'preview'=>$preview];
    return $liveConversation===null?$state:$state+['live_conversation'=>$liveConversation];
}
function vp3_cognitive_presentation_touch_v510($pdo,$user,$namespace){global $trace;$trace[]='interaction';}
function vp3_cognitive_presentation_digest_ack_v510($pdo,$user,$namespace,$id,$status){global $trace;$trace[]='digest_ack';}
function vp3_cognitive_presentation_voice_delivered_v510($pdo,$user,$namespace,$id){global $trace;$trace[]='voice_delivered';}
function vp3_cognitive_presentation_voice_suppressed_v510($pdo,$user,$namespace,$id){global $trace;$trace[]='voice_suppressed';}`);
  writeFileSync(join(temp,'includes','agent-initiative-runtime-v1.php'),`<?php
function vp3_initiative_policy_v1($user,$agent){global $initiativePolicy,$fixture;return $initiativePolicy??($fixture['policy']??[]);}
function vp3_initiative_worker_health_v1($pdo,$user,$agent,$cid){
    global $trace;$trace[]='initiative_health';$policy=vp3_initiative_policy_v1($user,$agent);
    return ['state'=>!empty($policy['enabled'])?'waiting':'off','enabled'=>!empty($policy['enabled']),
        'agent_id'=>$agent,'conversation_id'=>$cid,'policy_revision'=>$policy['revision']??''];
}
function vp3_initiative_chat_v1($pdo,$user,$principal,$context,$command,$authority){
    global $trace,$initiativePolicy,$fixture;
    $trace[]=['initiative_setting',$principal,$context,$command];
    if(!empty($fixture['settingDenied']))return ['ok'=>false];
    $initiativePolicy=['enabled'=>$command==='on','conversation_id'=>$context['conversation_id'],
        'knowledge_scope'=>$context['knowledge_scope'],'revision'=>str_repeat('a',32)];
    return ['ok'=>true];
}
function vp3_initiative_pump_v1($pdo,$user,$agent,$cid,$revision){
    global $trace,$fixture;$trace[]=['initiative_tick',$user['id'],$agent,$cid,$revision,session_status()];
    if(!empty($fixture['pumpCode']))throw new RuntimeException('Private internal detail.',(int)$fixture['pumpCode']);
    return ['ok'=>true,'reason'=>'no_work','health'=>['state'=>'checked']];
}`);

  const request = options => {
    const input = {method:'GET',owner:1,session:'owner-a',binding:ownerA,query:{},body:{},...options};
    const [response,meta] = execFileSync(php,[runner,JSON.stringify(input)],{encoding:'utf8'}).trim().split('\n').map(JSON.parse);
    return {...meta,response};
  };
  const assertRejected = result => {
    assert.equal(result.status,403);
    assert.equal(result.response.error,'account_changed');
    assert.equal('state' in result.response,false);
    assert.deepEqual(result.trace,[]);
  };

  assertRejected(request({binding:null,query:{include_working_attention:'0'}}));
  assertRejected(request({binding:'',query:{include_working_attention:'1'}}));
  assertRejected(request({owner:2,session:'owner-b',query:{include_working_attention:'0'}}));
  assertRejected(request({owner:2,session:'owner-b',query:{include_working_attention:'1',greeting_preview:'1'}}));
  assertRejected(request({session:'owner-a-rotated',query:{include_working_attention:'1'}}));
  assert.deepEqual(request({query:{include_working_attention:'0',greeting_preview:'1'}}).response.state,
    {owner:1,namespace:'system',working:false,preview:false});
  assert.deepEqual(request({query:{agent_id:'7',include_working_attention:'1',greeting_preview:'1'}}).response.state,
    {owner:1,namespace:'agent:7',working:true,preview:true});
  assert.deepEqual(request({owner:2,session:'owner-b',binding:ownerB}).response.state,
    {owner:2,namespace:'system',working:true,preview:false});
  assert.equal(request({query:{include_working_attention:['1']}}).status,422);

  const live = {include_working_attention:'0',include_live_context:'1',conversation_id:'42'};
  assertRejected(request({binding:null,query:live}));
  assertRejected(request({owner:2,session:'owner-b',query:live}));
  assert.deepEqual(request({query:live}).response.state,
    {owner:1,namespace:'system',working:false,preview:false,live_conversation:42});
  assert.deepEqual(request({query:{...live,agent_id:'7',conversation_id:'43'}}).response.state,
    {owner:1,namespace:'agent:7',working:false,preview:false,live_conversation:43});
  for (const query of [{...live,agent_id:'7'},{...live,conversation_id:'43'},
    {...live,agent_id:'8',conversation_id:'45'},{...live,agent_id:'99'},{...live,conversation_id:'999'}]) {
    const denied=request({query});
    assert.equal(denied.status,404);
    assert.equal(denied.response.error,'conversation_not_found');
    assert.deepEqual(denied.trace,['db']);
  }
  const foreign=request({owner:2,session:'owner-b',binding:ownerB,query:live});
  assert.equal(foreign.status,404);
  assert.deepEqual(foreign.trace,['db']);
  for (const query of [{...live,include_live_context:['1']},{...live,conversation_id:undefined},
    {...live,conversation_id:'0'},{...live,conversation_id:['42']},{...live,conversation_id:'42x'},
    {...live,agent_id:'7x'},{...live,agent_id:['7']}]) {
    const malformed=request({query});
    assert.equal(malformed.status,422);
    assert.equal(malformed.response.error,'invalid_live_context');
    assert.deepEqual(malformed.trace,['db']);
  }

  for (const action of ['interaction','digest_ack','voice_delivered','voice_suppressed']) {
    const body = {action,csrf_token:'fixture-csrf',digest_id:'digest',through_id:5};
    const mutation = {method:'POST',body};
    assertRejected(request({...mutation,binding:null}));
    assertRejected(request({...mutation,owner:2,session:'owner-b'}));
    assertRejected(request({...mutation,session:'owner-a-rotated'}));
    assert.equal(request({...mutation,body:{...body,csrf_token:'wrong'}}).status,419);
    const accepted = request(mutation);
    assert.equal(accepted.status,200);
    assert.deepEqual(accepted.trace,['db',action]);
  }

  // The new normal-Chat foreground loop keeps GET read-only and executes only
  // exact, CSRF-protected owner+Agent+conversation POSTs.
  const runtimeQuery={...live,agent_id:'7',conversation_id:'43',include_initiative_runtime:'1'};
  const runtimeRead=request({query:runtimeQuery});
  assert.equal(runtimeRead.status,200);
  assert.deepEqual(runtimeRead.trace,['db','state','initiative_health']);
  assert.equal(runtimeRead.response.state.live_context.initiative.runtime.agent_id,7);
  for(const query of [{include_initiative_runtime:'1'},{...runtimeQuery,include_initiative_runtime:['1']}]) {
    assert.equal(request({query}).status,422);
  }
  for(const action of ['initiative_tick','initiative_setting']) {
    assert.equal(request({query:{action}}).status,405);
  }
  const tick={action:'initiative_tick',csrf_token:'fixture-csrf',agent_id:7,conversation_id:43,policy_revision:'a'.repeat(32)};
  const tickRequest={method:'POST',body:tick};
  assertRejected(request({...tickRequest,binding:null}));
  assertRejected(request({...tickRequest,owner:2,session:'owner-b'}));
  assert.equal(request({...tickRequest,body:{...tick,csrf_token:'wrong'}}).status,419);
  for(const body of [{...tick,agent_id:0},{...tick,agent_id:8,conversation_id:45},{...tick,conversation_id:44}]) {
    const denied=request({...tickRequest,body});assert.equal(denied.status,404);assert.deepEqual(denied.trace,['db']);
  }
  for(const body of [{...tick,conversation_id:0},{...tick,agent_id:['7']},{...tick,agent_id:-1},
    {...tick,policy_revision:'old'},{...tick,policy_revision:undefined}]) {
    const malformed=request({...tickRequest,body});assert.equal(malformed.status,422);assert.deepEqual(malformed.trace,['db']);
  }
  const ticked=request({...tickRequest,startSession:true});
  assert.equal(ticked.status,200);
  assert.deepEqual(ticked.trace,['db',['initiative_tick',1,7,43,'a'.repeat(32),1]]);
  for(const code of [403,409,503]) {
    const blocked=request({...tickRequest,pumpCode:code});assert.equal(blocked.status,code);
    assert.equal(JSON.stringify(blocked.response).includes('Private internal detail'),false);
  }
  const setting={action:'initiative_setting',csrf_token:'fixture-csrf',agent_id:7,conversation_id:43,
    enabled:true,policy_revision:'',knowledge_scope:{mode:'folder',folder_id:12}};
  const configured=request({method:'POST',body:setting});
  assert.equal(configured.status,200);
  assert.deepEqual(configured.trace,['db',['initiative_setting',
    {kind:'user_agent',owner_user_id:1,agent_id:7},
    {conversation_id:43,knowledge_scope:{mode:'folder',folder_id:12},initiative_expected_revision:''},'on'],'initiative_health']);
  assert.equal(configured.response.health.enabled,true);
  const noKnowledge=request({method:'POST',body:{...setting,knowledge_scope:undefined}});
  assert.deepEqual(noKnowledge.trace[1][2].knowledge_scope,{mode:'off',folder_id:0});
  assert.equal(request({method:'POST',body:{...setting,enabled:'true'}}).status,422);
  assert.equal(request({method:'POST',body:{...setting,knowledge_scope:'all'}}).status,422);
  assert.equal(request({method:'POST',body:setting,settingDenied:true,policy:{enabled:true,conversation_id:43}}).status,409);
  assert.equal(execFileSync(php,['-r',
    `require ${JSON.stringify(fileURLToPath(new URL('../includes/cognitive-presentation-v510.php',import.meta.url)))}; echo (int)function_exists('vp3_agent_chat_conversation_v380');`
  ],{encoding:'utf8'}),'1');
  console.log('PASS cognitive presentation requires the rendered owner/session and exact Chat Agent on foreground reads');
  console.log('PASS foreground Initiative is read-only on GET, exact-bound/CSRF-protected on POST, explicit in Knowledge scope, and releases the session lock before work');
} finally {
  rmSync(temp,{recursive:true,force:true});
}
