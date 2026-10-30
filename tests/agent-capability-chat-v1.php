<?php
declare(strict_types=1);
require __DIR__.'/agent-capability-awareness-v1.php';
require dirname(__DIR__).'/includes/agent-capability-chat-v1.php';
$permissions=['chat.access'=>true,'knowledge'=>true,'brain'=>true];
$pdo->exec('CREATE TABLE users(id INTEGER PRIMARY KEY,is_active INTEGER)');$pdo->exec('INSERT INTO users VALUES(1,1),(2,1)');
function vp3_agent_runtime_plan_v420($pdo,$user,$id,$surface){return $GLOBALS['routeFixture']??['route'=>'homeserver','cloud'=>['ready'=>true]];}
function modelplan($operation,$ids=[]){return fn($prompt)=>['ok'=>true,'answer'=>json_encode(['operation'=>$operation,'ids'=>$ids])];}
function discover($query,$model,$context=[],$scope=null,$authority=null){return vp3_capability_chat_v1($GLOBALS['pdo'],['id'=>1,'is_active'=>1],['kind'=>'system'],$query,$context,$scope,[],$model,$authority);}
function rejected($fn,$why){try{$fn();}catch(Throwable $e){check(true,$why);return;}throw new RuntimeException($why);}
$before=$checks;
$r=discover('Can you create reminders?',modelplan('inspect',['reminders.manage']));
check($r['handled']&&str_contains($r['answer'],'Personal reminders: ready')&&$r['actions']===[],'LLM ability question produces discovery without an action');
check(!$r['capability_receipt']['changes_applied']&&!$r['capability_receipt']['records_queried'],'discovery receipt cannot claim a mutation or record lookup');
$r=discover('Can you remind me to stretch in two minutes?',modelplan('continue'));
check(!$r['handled'],'concrete action request continues to its existing authorized executor');
$r=discover('What is on my calendar?',modelplan('continue'));check(!$r['handled'],'actual calendar retrieval is not replaced by capability information');
$r=discover('Hello',modelplan('continue'));check(!$r['handled'],'ordinary conversation continues unchanged');
$r=discover('What can you do here?',modelplan('inspect'));check(substr_count($r['answer'],'(')===count(vp3_capability_snapshot_v1($pdo,['id'=>1,'is_active'=>1],['kind'=>'system'])['capabilities']),'general inventory covers each assessed capability');
$r=discover('Can you access my restaurant menu?',modelplan('unassessed'));check(str_contains($r['answer'],'outside the capabilities checked')&&!str_contains($r['answer'],'no access'),'unassessed integration is not falsely denied');
$r=discover('Can you access Knowledge?',modelplan('inspect',['knowledge.search']),[],'off');check(str_contains($r['answer'],'Knowledge is off'),'conversation opt-out is explained without expanding scope');
$r=discover('Why can you not search this folder?',modelplan('inspect',['knowledge.search']),[],['mode'=>'folder','folder_id'=>6]);check(str_contains($r['answer'],'folder is unavailable'),'foreign folder remains unavailable after model selection');
$r=discover('Can you see my screen?',modelplan('inspect',['screen.context']));check(str_contains($r['answer'],'fresh shared-screen observation is required'),'screen capability does not imply active or hidden capture');
$r=discover('Can you create reminders and create one now?',modelplan('inspect',['reminders.manage']));check($r['actions']===[]&&!$r['capability_receipt']['changes_applied'],'mixed question does not accidentally execute a change');
foreach(['{"operation":"delete","id":1}','{"operation":"inspect","ids":["root.shell"]}','{"operation":"inspect","ids":[["reminders.manage"]]}','{"operation":"continue","ids":["reminders.manage"]}','[]','broken','{"operation":"inspect","ids":[],"code":"unlink"}'] as $bad){
    $r=discover('Inspect tools',fn($p)=>['ok'=>true,'answer'=>$bad]);check($r['handled']&&str_contains($r['answer'],'No action was taken')&&$r['actions']===[],'invalid model output fails closed');
}
$r=discover('Inspect tools',fn($p)=>['ok'=>false,'error'=>'provider credential secret']);check(!str_contains($r['answer'],'secret')&&str_contains($r['answer'],'No action was taken'),'provider failure is explicit and sanitized');
$r=discover('Can you search Knowledge?',function($p)use(&$permissions){$permissions['knowledge']=false;return ['ok'=>true,'answer'=>'{"operation":"inspect","ids":["knowledge.search"]}'];});check(str_contains($r['answer'],'not permitted'),'permissions revoked during inference replace initial readiness');$permissions['knowledge']=true;
rejected(fn()=>discover('Inspect',modelplan('inspect'),[],null,fn()=>throw new RuntimeException('Stop')),'Stop or authority revocation aborts discovery after inference');
rejected(fn()=>discover('Inspect',function($p)use($pdo){$pdo->exec('UPDATE users SET is_active=0 WHERE id=1');return ['ok'=>true,'answer'=>'{"operation":"inspect","ids":[]}'];}),'disabled account cannot receive a stale capability report');$pdo->exec('UPDATE users SET is_active=1 WHERE id=1');
$GLOBALS['homeFixture']=['available'=>false,'reason'=>'offline','token'=>'secret'];
$r=discover('Is HomeServer connected?',modelplan('inspect',['homeserver.status']));check(str_contains($r['answer'],'HomeServer is offline')&&!str_contains($r['answer'],'secret'),'authenticated offline HomeServer status is reported without secrets');
$GLOBALS['homeFixture']=['available'=>true,'app'=>['permissions'=>['agent.chat','knowledge.search']],'operations'=>['agent.chat','knowledge.search','memory.read','tools.list','shell.secret'],'path'=>'C:/secret'];
$r=discover('What can my HomeServer do?',modelplan('inspect',['homeserver.status']));check(str_contains($r['answer'],'agent.chat, knowledge.search')&&!str_contains($r['answer'],'memory.read')&&!str_contains($r['answer'],'shell.secret')&&!str_contains($r['answer'],'C:/secret'),'HomeServer operations intersect authenticated permissions and known allowlist');
$r=vp3_capability_chat_v1($pdo,$user,['kind'=>'user_agent','owner_user_id'=>2],'Inspect',[],null,[],fn()=>throw new RuntimeException('Must not invoke model'));check(!$r['handled'],'third-party principal cannot invoke discovery inference');
$GLOBALS['routeFixture']=['route'=>'homeserver','cloud'=>['ready'=>true]];$r=vp3_capability_chat_v1($pdo,$user,$principal,'Hello');check(!$r['handled'],'HomeServer-routed turns do not make an extra Cloud model call');
$GLOBALS['routeFixture']=['route'=>'blocked','cloud'=>['ready'=>false]];$r=vp3_capability_chat_v1($pdo,$user,$principal,'Hello');check(!$r['handled'],'blocked compute does not bypass the canonical runtime policy');
check((int)$pdo->query('SELECT COUNT(*) FROM reminders')->fetchColumn()===0,'discovery never writes reminder records');
echo 'CAPABILITY_CHAT=PASS ('.($checks-$before)." cases)\n";
