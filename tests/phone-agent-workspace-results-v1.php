<?php
declare(strict_types=1);

// Seed through the canonical policy, job claimant, completion receipt and Chat delivery.
// Only the fixture's explicit seed inference is deterministic; all source reads are production.
require __DIR__.'/fixtures/initiative-ai-settings-v1.php';
require __DIR__.'/fixtures/initiative-scope-v1.php';
require __DIR__.'/fixtures/initiative-followthrough-v1.php';
require_once dirname(__DIR__).'/includes/phone-agent-workspace-sources-v1.php';

$phoneResultChecks=0;
function par_check(bool $ok,string $label): void
{if(!$ok)throw new RuntimeException($label);$GLOBALS['phoneResultChecks']++;echo 'PASS '.$label.PHP_EOL;}
function par_reject(int $code,callable $work,string $label): void
{try{$work();}catch(RuntimeException $error){if($error instanceof PDOException||$error->getCode()!==$code)throw $error;par_check(true,$label);return;}throw new RuntimeException('Expected denial: '.$label);}
function par_state(): array
{$out=[];foreach(['chat_messages','agent_workflow_runs','agent_workflow_actions','agent_workflow_receipts','agent_workflow_events'] as $table)$out[$table]=db()->query('SELECT * FROM '.$table.' ORDER BY id')->fetchAll();return $out;}

$pdo->exec("INSERT INTO agent_activity_state(user_id,surface,context_key,activity_state,last_heartbeat_at,details_json)
    VALUES(1,'chat','chat:170','paused',NOW(),'{\"visible\":true,\"conversation_id\":170,\"chat_focused\":true,\"chat_busy\":false}')");
$root=followthrough_seed_v1('Verified phone result');$id=(string)$root['message']['id'];
$now=time();$before=par_state();
$read=static fn():?array=>vp3_phone_agent_source_resolve_v1($pdo,$one,17,'agent_result',$id,time());
$item=$read();
par_check($item!==null&&$item['source_kind']==='agent_result'&&$item['state']==='ready'
    &&$item['target']===['kind'=>'chat_message','agent_id'=>17,'conversation_id'=>170,'message_id'=>(int)$id],
    'a real delivered completed result becomes an exact selected-Agent private Chat item');
$detail=vp3_phone_agent_source_detail_v1($pdo,$one,17,'agent_result',$id,$now,$item['source_revision']);
par_check($detail['kind']==='agent_result'&&$detail['text']===$root['message']['message']&&!$detail['truncated'],
    'result detail reuses the current native message and verified completion receipt');
par_check($read()['source_revision']===$item['source_revision']&&par_state()===$before,
    'result projection remains stable and starts no workflow, message, provider inference or acknowledgment');
$feed=vp3_phone_agent_sources_discover_v1($pdo,$one,17,time());
par_check(count(array_filter($feed['items'],static fn(array $candidate):bool=>$candidate['source_kind']==='agent_result'&&$candidate['source_id']===$id))===1,
    'bounded discovery finds the actual saved result through its native private Chat index');
par_check(vp3_phone_agent_source_resolve_v1($pdo,$one,18,'agent_result',$id,time())===null
    &&vp3_phone_agent_source_resolve_v1($pdo,$two,19,'agent_result',$id,time())===null,
    'sibling Agent and another account cannot borrow the result receipt');
$GLOBALS['VP3_PARTICIPANT_RESOURCE_SCOPE_V1']=['participant_id'=>9];
par_reject(403,$read,'participant scope cannot open private owner results');
unset($GLOBALS['VP3_PARTICIPANT_RESOURCE_SCOPE_V1']);
$q=$pdo->prepare('UPDATE chat_messages SET message=? WHERE id=?');$q->execute(['Forged text not matching receipt',$id]);
par_check($read()===null,'changed Chat text without matching native receipt cannot be called an Agent result');
$q->execute([$root['message']['message'],$id]);
$pdo->prepare("UPDATE agent_workflow_receipts SET result_json='{}' WHERE id=?")->execute([$root['action']['receipt_id']]);
par_check($read()===null,'a missing matching completion receipt invalidates the item');
$pdo->prepare('UPDATE agent_workflow_receipts SET result_json=? WHERE id=?')->execute([$root['action']['receipt_result'],$root['action']['receipt_id']]);
$pdo->prepare("INSERT INTO chat_messages(conversation_id,user_id,role,message,context_json) VALUES(170,NULL,'assistant','Unverified announcement',?)")
    ->execute([json_encode(['source'=>'agent_initiative','initiative_run_id'=>(int)$root['run']['id']])]);
$forged=(string)$pdo->lastInsertId();
par_check(vp3_phone_agent_source_resolve_v1($pdo,$one,17,'agent_result',$forged,time())===null,
    'an assistant announcement with a copied run ID is not completion proof');
$pdo->prepare('UPDATE initiative_scope_calendar SET title=? WHERE owner_id=1')->execute(['A newer source revision']);
par_check($read()===null,'the current source revision is revalidated before returning a saved result');
par_reject(409,fn()=>vp3_phone_agent_source_detail_v1($pdo,$one,17,'agent_result',$id,time(),$item['source_revision']),
    'stale native evidence cannot open a previously issued Phone detail reference');
echo 'PASS Phone Agent native results: '.$phoneResultChecks." checks.\n";
