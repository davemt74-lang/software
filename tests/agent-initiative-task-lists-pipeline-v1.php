<?php
declare(strict_types=1);

// Actual canonical account/source/planner/lease/worker/receipt/Chat/Brain
// foundation. Only model and host transport are controlled by that foundation.
require __DIR__.'/agent-initiative-capabilities-pipeline-v1.php';
require_once dirname(__DIR__).'/includes/agent-initiative-task-lists-v1.php';
vp3_task_lists_schema_v1(db());
$listPipelineStart=$GLOBALS['capabilityPipelineChecks'];$user=['id'=>1,'is_active'=>1];
$listId=60000;$ref='task-list:'.$listId;$sentinel='LIST_MEMBER_DETAIL_SENTINEL';
$listBody=str_repeat('Saved coordination context. ',12).$sentinel.'. Confirm the unresolved supplier decision before changing the launch plan.';
$insert=db()->prepare("INSERT INTO agent_memory_items(id,user_id,user_agent_id,memory_type,subject,memory_text,memory_hash,
    memory_scope_version,confidence,occurrence_count,first_seen_at,last_seen_at,is_active,metadata_json)
    VALUES(?,1,17,'task',?,?,?,410,.95,1,NOW(),NOW(),1,?)");
foreach([[58000,'Confirm the launch supplier',$listBody,'open'],[58001,'Review the launch image','Use the saved visual brief.','waiting'],
    [58002,'Verify the previous report','The existing report was reviewed.','completed']] as [$id,$title,$body,$status])
    $insert->execute([$id,$title,$body,'list-pipeline-'.$id,json_encode(['task_status'=>$status,'due_at'=>'2026-11-07','source_kind'=>'explicit_user'],JSON_THROW_ON_ERROR)]);
db()->prepare('INSERT INTO agent_task_lists_v1(id,owner_user_id,agent_scope_id,name,name_key,created_at) VALUES(?,1,17,?,?,?)')
    ->execute([$listId,'Coordinated launch',vp3_task_list_key_v1('Coordinated launch'),'2026-11-06T12:00:00Z']);
$member=db()->prepare('INSERT INTO agent_task_list_items_v1(list_id,task_id,item_key,created_at) VALUES(?,?,?,?)');
foreach([58000,58001,58002] as $id)$member->execute([$listId,$id,'pipeline-member-'.$id,'2026-11-06T12:00:00Z']);
function list_pipeline_business_state(): array
{
    return ['list'=>db()->query('SELECT * FROM agent_task_lists_v1 WHERE id=60000')->fetch(PDO::FETCH_ASSOC),
        'members'=>db()->query('SELECT * FROM agent_task_list_items_v1 WHERE list_id=60000 ORDER BY task_id')->fetchAll(PDO::FETCH_ASSOC),
        'tasks'=>db()->query('SELECT * FROM agent_memory_items WHERE id BETWEEN 58000 AND 58002 ORDER BY id')->fetchAll(PDO::FETCH_ASSOC)];
}
function list_pipeline_presence(): void
{
    db()->exec("UPDATE agent_activity_state SET details_json='{\"visible\":true,\"conversation_id\":170,\"chat_focused\":true,\"chat_busy\":false}',
        context_key='chat:170',last_heartbeat_at=NOW() WHERE user_id=1");
}
function list_pipeline_withheld(array $run,int $messages,int $models,string $label): void
{
    list_pipeline_presence();
    capability_pipeline_check(!vp3_initiative_deliver_pending_v1(db(),['id'=>1,'is_active'=>1],17)
        &&count(scope_messages())===$messages&&count($GLOBALS['scopeInference'])===$models,$label);
    capability_pipeline_check((capability_pipeline_stored($run['id'])['initiative_delivery']??'')==='withheld',
        'the actual list action and execution receipt both record known stale delivery as withheld');
}
$business=list_pipeline_business_state();
$success=capability_pipeline_prepare($user,17,170,'tasks.lists.read',$ref,$sentinel);
capability_pipeline_check(list_pipeline_business_state()===$business,'the real list model/worker/receipt/Chat path changes no task, list or membership record');
$receipt=$success['result']['initiative_tool_result']['tool_receipt'];
capability_pipeline_check($receipt['capability']==='tasks.lists'&&$receipt['operation']==='show'&&$receipt['status']==='read_complete'
    &&$receipt['changes_applied']===false,'the readonly child retains its actual canonical list parent and show receipt operation');

$proposal=vp3_initiative_followthrough_load_v1(db(),$user,scope_principal($user,17),170,(int)$success['result']['initiative_message_id']);
$ownerQuery='Develop this private list plan. '.str_repeat('Keep the named list and current member evidence bound to this reply. ',8).'LIST_OWNER_REPLY_SENTINEL';
db()->prepare("INSERT INTO chat_messages(conversation_id,user_id,role,message) VALUES(170,1,'user',?)")->execute([$ownerQuery]);
$ownerMessage=(int)db()->lastInsertId();$replyRef=['message_id'=>(int)$proposal['message']['id'],
    'reply_ref'=>$proposal['state']['reply_ref'],'revision'=>$proposal['state']['revision']];
$advanced=vp3_initiative_followthrough_reply_v1(db(),$user,$proposal['principal'],170,$ownerMessage,$replyRef,$ownerQuery,null,
    static fn():string=>json_encode(['intent'=>'advance','focus'=>'Develop the verified private named-list plan.'],JSON_THROW_ON_ERROR));
$outcome=$advanced['initiative_followthrough'];$child=(int)$outcome['continuation_run_id'];
$childGoal=json_decode(agent_workflow_row_v1400(db(),1,$child)['goal'],true,32,JSON_THROW_ON_ERROR);
capability_pipeline_check($outcome['state']==='queued'&&$childGoal['tool']==='tasks.lists.read'&&$childGoal['sources']===$success['goal']['sources'],
    'an owner reply advances the same original readonly list leaf with its exact source binding');
vp3_initiative_followthrough_current_v1(db(),$user,$proposal['principal'],170,$outcome);
db()->prepare("INSERT INTO chat_messages(conversation_id,user_id,role,message,context_json) VALUES(170,NULL,'assistant','I will develop the private list plan from the current saved members.',?)")
    ->execute([json_encode(['initiative_followthrough'=>$outcome],JSON_THROW_ON_ERROR)]);
db()->exec("UPDATE chat_messages SET created_at='2000-01-01 00:00:00' WHERE conversation_id=170");
$GLOBALS['scopeModel']=static function(string $prompt,string $protocol)use($ownerQuery,$sentinel):string{
    $input=json_decode($prompt,true,32,JSON_THROW_ON_ERROR);
    capability_pipeline_check($protocol==='vp3.initiative-draft.v1'&&!db()->inTransaction()
        &&($input['tool_result']['tool']??'')==='tasks.lists.read'
        &&str_contains(json_encode($input['tool_result']['records'],JSON_THROW_ON_ERROR),$sentinel)
        &&($input['owner_continuation']['owner_reply']??null)===$ownerQuery,
        'the actual list continuation model receives current raw members and the entire bound owner request outside a transaction');
    return json_encode(['draft'=>'The supplier decision remains open and the image review is waiting. A useful private next step is to compare those dependencies before changing the launch plan.'],JSON_THROW_ON_ERROR);
};
$continued=vp3_initiative_execute_v1(db(),$user,scope_claim($user,$child));$childResult=capability_pipeline_stored($child);
$finished=vp3_initiative_followthrough_load_v1(db(),$user,$proposal['principal'],170,(int)$proposal['message']['id']);
capability_pipeline_check(!empty($continued['ok'])&&$finished['state']['state']==='completed'&&$childResult['initiative_delivery']==='delivered'
    &&list_pipeline_business_state()===$business,'the list continuation verifies, reports to the same Chat and completes without mutating the list');

$racing=capability_pipeline_prepare($user,17,170,'tasks.lists.read',$ref,$sentinel,true,
    static fn()=>db()->exec('DELETE FROM agent_task_list_items_v1 WHERE list_id=60000 AND task_id=58001'));
capability_pipeline_check(empty($racing['completed']['ok']),'a real member removal during model preparation cannot complete a list draft');
$member->execute([$listId,58001,'pipeline-member-58001','2026-11-06T12:00:00Z']);

$pending=capability_pipeline_prepare($user,17,170,'tasks.lists.read',$ref,$sentinel,false);
$messages=count(scope_messages());$models=count($GLOBALS['scopeInference']);$saved=capability_pipeline_stored($pending['id']);
db()->exec('ALTER TABLE agent_task_list_items_v1 RENAME TO unavailable_list_members');list_pipeline_presence();
capability_pipeline_check(!vp3_initiative_deliver_pending_v1(db(),$user,17)&&count(scope_messages())===$messages
    &&count($GLOBALS['scopeInference'])===$models&&capability_pipeline_stored($pending['id'])===$saved,
    'unavailable list membership verification keeps the actual saved result pending without inference or disclosure');
db()->exec('ALTER TABLE unavailable_list_members RENAME TO agent_task_list_items_v1');
capability_pipeline_check(vp3_initiative_deliver_pending_v1(db(),$user,17)&&count(scope_messages())===$messages+1
    &&count($GLOBALS['scopeInference'])===$models,'the same pending list result recovers once after storage returns without model replay');

$pending=capability_pipeline_prepare($user,17,170,'tasks.lists.read',$ref,$sentinel,false);
$messages=count(scope_messages());$models=count($GLOBALS['scopeInference']);
db()->exec("UPDATE agent_memory_items SET memory_text=memory_text||' later correction' WHERE id=58000");
list_pipeline_withheld($pending,$messages,$models,'a changed full member body blocks delayed list delivery without generating another answer');
db()->prepare('UPDATE agent_memory_items SET memory_text=? WHERE id=58000')->execute([$listBody]);

$pending=capability_pipeline_prepare($user,17,170,'tasks.lists.read',$ref,$sentinel,false);
$messages=count(scope_messages());$models=count($GLOBALS['scopeInference']);
db()->exec('DELETE FROM agent_task_list_items_v1 WHERE list_id=60000 AND task_id=58001');
list_pipeline_withheld($pending,$messages,$models,'membership removal after preparation cannot expose the saved stale list report');
$member->execute([$listId,58001,'pipeline-member-58001','2026-11-06T12:00:00Z']);

$pending=capability_pipeline_prepare($user,17,170,'tasks.lists.read',$ref,$sentinel,false);
$messages=count(scope_messages());$models=count($GLOBALS['scopeInference']);
db()->exec("INSERT INTO user_agent_data_rules VALUES(17,'knowledge','brain:58000','deny')");
list_pipeline_withheld($pending,$messages,$models,'revoked member access prevents delayed disclosure of the private list output');
db()->exec("DELETE FROM user_agent_data_rules WHERE agent_id=17 AND resource_id='brain:58000'");

$pending=capability_pipeline_prepare($user,17,170,'tasks.lists.read',$ref,$sentinel,false);
$messages=count(scope_messages());$models=count($GLOBALS['scopeInference']);db()->exec('DELETE FROM agent_task_lists_v1 WHERE id=60000');
list_pipeline_withheld($pending,$messages,$models,'deleting the canonical list prevents delayed delivery through the real main Chat path');

// A historical alias remains readable, but cannot create competing new work.
capability_pipeline_reset($user,17,170,true);
db()->prepare('UPDATE agent_memory_items SET metadata_json=? WHERE id=58000')
    ->execute([json_encode(['task_status'=>'open','source_kind'=>'explicit_user','conversation_id'=>170],JSON_THROW_ON_ERROR)]);
$coverage=[];$fresh=vp3_initiative_evidence_v1(db(),$user,new DateTimeImmutable('now',new DateTimeZone('UTC')),false,[],$coverage,null,17);
capability_pipeline_check(isset($fresh['task:58000'])&&!isset($fresh['open-task:58000']),
    'fresh runtime discovery presents one canonical identity for an undated task when its detailed read is admitted');
$selected=vp3_initiative_evidence_v1(db(),$user,new DateTimeImmutable('now',new DateTimeZone('UTC')),false,[],$coverage,
    ['open-task:58000','task:58000'],17);
capability_pipeline_check(isset($selected['open-task:58000'],$selected['task:58000']),
    'selected historical task aliases remain independently readable after discovery deduplication');
$sources=array_map(static fn(array $row):string=>$row['revision'],$selected);
$verified=vp3_initiative_current_sources_v1(db(),$user,$sources,false,[],17,vp3_initiative_capability_source_map_v1($selected,array_keys($selected)));
capability_pipeline_check(count($verified)===2,'actual source verification still accepts current canonical and historical task evidence');
$jobs=[['tool'=>'work.prepare_private','primary'=>'open-task:58000','refs'=>['open-task:58000'],'focus'=>'Prepare one private next step.','report'=>true],
    ['tool'=>'tasks.read','primary'=>'task:58000','refs'=>['task:58000'],'focus'=>'Read the same saved task.','report'=>true]];
capability_pipeline_reject(static fn()=>vp3_initiative_plan_v1(json_encode(['jobs'=>$jobs],JSON_THROW_ON_ERROR),$selected,
    ['work.prepare_private','tasks.read']),0,'the actual plan validator rejects mixed aliases for one canonical task');
$beforeJobs=count(scope_runs(1,17,'initiative.prepare'));$messages=count(scope_messages());$duplicateCalls=0;
$GLOBALS['scopeModel']=static function(string $prompt,string $protocol)use(&$duplicateCalls):string{
    $duplicateCalls++;$input=json_decode($prompt,true,32,JSON_THROW_ON_ERROR);
    capability_pipeline_check($protocol==='vp3.initiative-plan.v1'&&!db()->inTransaction()
        &&in_array('task:58000',array_column($input['evidence'],'ref'),true)
        &&!in_array('open-task:58000',array_column($input['evidence'],'ref'),true),
        'the real claimed planner receives the canonical task once without an undated alias');
    return json_encode(['jobs'=>[
        ['tool'=>'work.prepare_private','primary'=>'task:58000','refs'=>['task:58000'],'focus'=>'Prepare one private next step.','report'=>true],
        ['tool'=>'tasks.read','primary'=>'task:58000','refs'=>['task:58000'],'focus'=>'Read that same task again.','report'=>true],
    ]],JSON_THROW_ON_ERROR);
};
$plan=scope_make_plan($user,17,'Reject duplicate canonical task assignments');
$duplicate=vp3_initiative_execute_v1(db(),$user,scope_claim($user,$plan));
capability_pipeline_check(empty($duplicate['ok'])&&$duplicateCalls===1&&count(scope_runs(1,17,'initiative.prepare'))===$beforeJobs
    &&count(scope_messages())===$messages,'different tools targeting the same canonical task cannot queue duplicate private jobs or report twice');
echo 'AGENT_INITIATIVE_TASK_LISTS_PIPELINE_V1=PASS ('.($GLOBALS['capabilityPipelineChecks']-$listPipelineStart)
    ." focused cases; actual source/model/queue/action/receipt/Chat/Brain)\n";
