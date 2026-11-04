import assert from 'node:assert/strict';
import {execFileSync} from 'node:child_process';
import {createHmac} from 'node:crypto';
import {copyFileSync, mkdirSync, mkdtempSync, rmSync, writeFileSync} from 'node:fs';
import {tmpdir} from 'node:os';
import {join} from 'node:path';

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
  writeFileSync(runner,`<?php
$fixture=json_decode($argv[1],true);
$trace=[];
$_SERVER['REQUEST_METHOD']=$fixture['method'];
if(isset($fixture['binding']))$_SERVER['HTTP_X_VP3_WORKING_ATTENTION']=$fixture['binding'];
$_GET=$fixture['query'];
$_POST=$fixture['body'];
session_id($fixture['session']);
register_shutdown_function(static function(){global $trace;echo "\\n".json_encode(['status'=>http_response_code(),'trace'=>$trace]);});
require __DIR__.'/api/cognitive-presentation-v510.php';`);
  writeFileSync(join(temp,'includes','bootstrap.php'),`<?php
const VP3_COGNITIVE_PRESENTATION_V510='fixture';
function current_user(){global $fixture;return ['id'=>$fixture['owner']];}
function has_permission($capability,$user){return $capability==='chat.access';}
function csrf_token(){return 'fixture-csrf';}
function vp3_catch_up_page_binding_v1($user){return hash_hmac('sha256','working-attention|'.$user['id'].'|'.session_id(),csrf_token());}
function db(){global $trace;$trace[]='db';return new stdClass();}
function vp3_cognitive_presentation_schema_ready_v510($pdo){return true;}
function vp3_cognitive_agent_namespace_v500($pdo,$user,$agent){return $agent?'agent:'.$agent:'system';}
function vp3_cognitive_presentation_state_v510($pdo,$user,$namespace,$agent,$working,$preview){
    global $trace;$trace[]='state';
    return ['owner'=>$user['id'],'namespace'=>$namespace,'working'=>$working,'preview'=>$preview];
}
function vp3_cognitive_presentation_touch_v510($pdo,$user,$namespace){global $trace;$trace[]='interaction';}
function vp3_cognitive_presentation_digest_ack_v510($pdo,$user,$namespace,$id,$status){global $trace;$trace[]='digest_ack';}
function vp3_cognitive_presentation_voice_delivered_v510($pdo,$user,$namespace,$id){global $trace;$trace[]='voice_delivered';}
function vp3_cognitive_presentation_voice_suppressed_v510($pdo,$user,$namespace,$id){global $trace;$trace[]='voice_suppressed';}`);

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
  console.log('PASS cognitive presentation requires the rendered owner/session on state and every mutation, with opt-in projections');
} finally {
  rmSync(temp,{recursive:true,force:true});
}
