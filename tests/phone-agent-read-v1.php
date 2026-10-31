<?php
declare(strict_types=1);
require __DIR__.'/agent-capability-chat-v1.php';
require __DIR__.'/fixtures/phone-agent-schema-v1.php';
function url($p){return $p;}
function subscription_has_entitlement($user,$key){return ($GLOBALS['phoneSubscription']??true)&&(int)$user['id']===1;}
function subscription_entitlement_limit(...$args){return 3;}
function vp3_transcript_analyze_v1(){}
function vp3_transcript_reviewed_action_v1(){}
function vp3_transcript_chat_v1(){}
$before=$checks;$permissions['account.access']=true;$permissions['artist_listening.access']=true;phone_agent_fixture_v1($pdo);
$u=['id'=>1,'is_active'=>1];$p=['kind'=>'user_agent','owner_user_id'=>1,'agent_id'=>7];
function phonePlan($id,int $thread=0,?callable $change=null){return function($prompt)use($id,$thread,$change){if($change)$change();return ['ok'=>true,'answer'=>json_encode(['operation'=>'phone_read','ids'=>[$id],'thread_id'=>$thread])];};}
function phoneChat($model){return vp3_capability_chat_v1($GLOBALS['pdo'],$GLOBALS['u'],$GLOBALS['p'],'Explicit phone read',[],null,[],$model);}
$s=vp3_capability_snapshot_v1($pdo,$u,$p);check(row($s,'phone.sms.read')['ready']&&!row($s,'phone.sms.send')['ready'],'SMS reads are ready while sending requires the existing approval screen');
check(!str_contains(json_encode($s),'pizza pickup')&&!str_contains(json_encode($s),'155500'),'capability inspection contains no phone records, message content or carrier credentials');
$r=phoneChat(phonePlan('phone.numbers.read'));check(str_contains($r['answer'],'15550001001')&&!str_contains($r['answer'],'15550001002')&&!str_contains($r['answer'],'15550001003'),'number lookup is scoped to the active named Agent');
$r=phoneChat(phonePlan('phone.sms.read'));check(str_contains($r['answer'],'Thread #11')&&!str_contains($r['answer'],'Thread #12')&&!str_contains($r['answer'],'Thread #14')&&!str_contains($r['answer'],'Thread #15'),'SMS listing rejects another Agent, reassigned numbers and inactive Agents before rendering');
$r=phoneChat(phonePlan('phone.sms.read',11));check(str_contains($r['answer'],'pizza pickup at six')&&str_contains($r['answer'],'2026-10-30')&&$r['tool_receipt']['status']==='read_complete'&&!$r['tool_receipt']['changes_applied'],'thread reading returns actual saved text, timestamp and a read-only receipt');
foreach([12,13,14,15,999] as $id){$r=phoneChat(phonePlan('phone.sms.read',$id));check($r['tool_receipt']['status']==='failed'&&!str_contains($r['answer'],'SECRET'),'unavailable or unauthorized thread '.$id.' fails closed');}
$r=vp3_phone_agent_read_v1($pdo,$u,['kind'=>'system'],'phone.numbers.read');check(str_contains($r['answer'],'15550001002')&&!str_contains($r['answer'],'15550001003'),'owner system Chat reads its own active assignments without crossing accounts');
$r=phoneChat(phonePlan('phone.sms.read',11,fn()=>$pdo->exec('UPDATE phone_cloud_numbers_v100 SET agent_id=8 WHERE id=1')));check($r['tool_receipt']['status']==='failed','number reassignment during interpretation revokes the pending read');$pdo->exec('UPDATE phone_cloud_numbers_v100 SET agent_id=7 WHERE id=1');
$r=phoneChat(phonePlan('phone.sms.read',11,function(){$GLOBALS['phoneSubscription']=false;}));check($r['tool_receipt']['status']==='blocked'&&$r['tool_receipt']['reason']==='subscription_required'&&!str_contains($r['answer'],'pizza pickup'),'subscription revocation during interpretation is rechecked before reading');$GLOBALS['phoneSubscription']=true;
$r=phoneChat(phonePlan('phone.sms.read',11,function(){$GLOBALS['permissions']['account.access']=false;}));check($r['tool_receipt']['status']==='failed'&&$r['tool_receipt']['reason']==='permission_denied','account permission revocation during interpretation prevents the read');$permissions['account.access']=true;
$r=phoneChat(modelplan('inspect',['phone.sms.send']));check(str_contains($r['answer'],'approval screen')&&$r['tool_receipt']['capability']==='capabilities.inspect','send requests can explain the real approval surface without invoking the carrier');
rejected(fn()=>vp3_capability_plan_v1('{"operation":"phone_read","ids":["phone.sms.send"],"thread_id":11}',$s),'read planner cannot select a phone send executor');
rejected(fn()=>vp3_capability_plan_v1('{"operation":"continue","ids":[],"thread_id":11}',$s),'phone parameters cannot be smuggled into ordinary routing');
rejected(fn()=>vp3_capability_plan_v1('{"operation":"phone_read","ids":["phone.sms.read"],"thread_id":"11"}',$s),'model thread IDs are strictly typed');
rejected(fn()=>vp3_tool_receipt_v1('phone.sms.read','read','completed'),'an SMS read cannot issue a successful mutation receipt');
$q=$pdo->prepare('INSERT INTO phone_sms_threads_v110 VALUES(?,1,2,8,?,0,?)');for($i=200;$i<330;$i++)$q->execute([$i,'+15550003001','2030-01-01 00:00:00']);$r=phoneChat(phonePlan('phone.sms.read'));check(str_contains($r['answer'],'Thread #11'),'Agent scope is applied before the thread list limit');
$q=$pdo->prepare('INSERT INTO phone_sms_messages_v110 VALUES(?,11,1,?,?,?,?)');for($i=400;$i<415;$i++)$q->execute([$i,'inbound',str_repeat('é',1600),'received','2026-10-30 17:00:00']);$r=phoneChat(phonePlan('phone.sms.read',11));check($r['tool_receipt']['count']===10&&str_contains($r['answer'],'latest 10')&&str_contains($r['answer'],'[excerpt]')&&mb_strlen($r['answer'])<12000,'long SMS threads have explicit bounded message excerpts');
check((int)$pdo->query('SELECT unread_count FROM phone_sms_threads_v110 WHERE id=11')->fetchColumn()===7&&(int)$pdo->query('SELECT COUNT(*) FROM phone_sms_outbox_v120')->fetchColumn()===0,'all reads and inspection leave unread counts and outbound storage unchanged');
$pdo->exec('CREATE TABLE artist_transcript_master_analysis_v237(id INTEGER);CREATE TABLE artist_transcript_page_analysis_v237(id INTEGER)');$s=vp3_capability_snapshot_v1($pdo,$u,$p);check(row($s,'transcripts.analyze')['reason']==='upgrade_required','analysis tables alone do not falsely establish transcript source readiness');
$pdo->exec('CREATE TABLE artist_transcript_sessions_v172(id INTEGER);CREATE TABLE artist_transcript_segments_v172(id INTEGER);DROP TABLE artist_transcript_master_analysis_v237');$s=vp3_capability_snapshot_v1($pdo,$u,$p);check(row($s,'transcripts.highlight')['ready']&&!row($s,'transcripts.analyze')['ready'],'raw highlights use transcript and Brain readiness independently of saved analysis');
echo 'PHONE_AGENT_READ=PASS ('.($checks-$before)." cases; real SQLite records, production shared broker and canonical SMS reader)\n";
