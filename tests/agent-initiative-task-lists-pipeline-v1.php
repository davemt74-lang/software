<?php
declare(strict_types=1);

// Actual canonical account/source/planner/lease/worker/receipt/Chat/Brain
// foundation. Only model and host transport are controlled by that foundation.
require __DIR__.'/agent-initiative-capabilities-pipeline-v1.php';
require_once dirname(__DIR__).'/includes/agent-initiative-task-lists-v1.php';
require_once dirname(__DIR__).'/includes/agent-activity-hub-v1.php';
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
    capability_pipeline_reject(static fn()=>vp3_initiative_work_sources_v1(db(),['id'=>1,'is_active'=>1],
        $run['goal']['binding'],$run['goal']),409,'the exact saved list revision is no longer current');
    capability_pipeline_check(!vp3_initiative_deliver_pending_v1(db(),['id'=>1,'is_active'=>1],17)
        &&count(scope_messages())===$messages&&count($GLOBALS['scopeInference'])===$models,$label);
    capability_pipeline_check((capability_pipeline_stored($run['id'])['initiative_delivery']??'')==='private',
        'the actual list action and receipt retain the original private delivery boundary');
}
$business=list_pipeline_business_state();
$success=capability_pipeline_prepare($user,17,170,'tasks.lists.read',$ref,$sentinel);
capability_pipeline_check(list_pipeline_business_state()===$business,'the real list model/worker/receipt/Chat path changes no task, list or membership record');
$success['result']=capability_pipeline_legacy_post($user,$success);
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
    capability_pipeline_check($protocol===VP3_INITIATIVE_OFFICE_REPORT_PROTOCOL_V1&&!db()->inTransaction()
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
capability_pipeline_reject(static fn()=>vp3_initiative_work_sources_v1(db(),$user,$pending['goal']['binding'],$pending['goal']),
    503,'unavailable list membership cannot verify the exact private source');
capability_pipeline_check(!vp3_initiative_deliver_pending_v1(db(),$user,17)&&count(scope_messages())===$messages
    &&count($GLOBALS['scopeInference'])===$models&&capability_pipeline_stored($pending['id'])===$saved,
    'unavailable list membership keeps the actual saved result private without inference or disclosure');
db()->exec('ALTER TABLE unavailable_list_members RENAME TO agent_task_list_items_v1');
$recovered=vp3_initiative_work_sources_v1(db(),$user,$pending['goal']['binding'],$pending['goal']);
capability_pipeline_check(isset($recovered[$ref])&&!vp3_initiative_deliver_pending_v1(db(),$user,17)&&count(scope_messages())===$messages
    &&count($GLOBALS['scopeInference'])===$models,'the exact private list result is current again after storage returns without model replay');

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
capability_pipeline_check(empty($duplicate['ok'])&&$duplicate['reason']==='assessment_unverified'
    &&$duplicateCalls===2&&count(scope_runs(1,17,'initiative.prepare'))===$beforeJobs
    &&count(scope_messages())===$messages,'duplicate canonical task assignments stay rejected after the single source repair');

capability_pipeline_reset($user,17,170,true);
db()->exec('DELETE FROM agent_task_list_items_v1 WHERE list_id=60000');
db()->prepare('INSERT INTO agent_task_lists_v1(id,owner_user_id,agent_scope_id,name,name_key,created_at) VALUES(?,1,17,?,?,?)')
    ->execute([$listId,'Coordinated launch',vp3_task_list_key_v1('Coordinated launch'),'2026-11-06T12:00:00Z']);
foreach([58000,58001,58002] as $id)$member->execute([$listId,$id,'pipeline-member-'.$id,'2026-11-06T12:00:00Z']);
db()->prepare('UPDATE agent_memory_items SET metadata_json=? WHERE id=58001')->execute([json_encode(
    ['task_status'=>'waiting','due_at'=>gmdate('Y-m-d',time()-172800),'source_kind'=>'explicit_user'],JSON_THROW_ON_ERROR)]);
$beforeWork=list_pipeline_business_state();$messages=count(scope_messages());
$attentionPlan=capability_pipeline_plan($user,17,'tasks.lists.read',$ref,'Assess task-list deadline',
    static function(array $input)use($ref):void{
        $list=array_column($input['evidence'],null,'ref')[$ref];
        $items=array_column($list['attention_items'],null,'task_ref');
        capability_pipeline_check(($items['task:58001']['deadline']??null)==='overdue'
            &&$items['task:58001']['waiting']===true&&$items['task:58001']['position']===2,
            'the real assessment model receives the exact waiting list member and observed overdue deadline');
    });
$attentionDraft='The launch image review is waiting and past its due date. What is holding up the review?';
$GLOBALS['scopeModel']=static function(string $prompt,string $protocol)use($ref,$attentionDraft):string{
    $input=json_decode($prompt,true,32,JSON_THROW_ON_ERROR);
    $list=array_column($input['evidence'],null,'ref')[$ref];
    capability_pipeline_check($protocol===VP3_INITIATIVE_OFFICE_REPORT_PROTOCOL_V1
        &&$list['attention_count']>0&&str_contains($input['tool_result']['effect'],'If the blocker or dependency is unclear, ask about it')
        &&str_contains($input['tool_result']['records'][0]['text'],'Review the launch image'),
        'verified report preparation pairs saved list content with the task attention guidance');
    return json_encode(['draft'=>$attentionDraft],JSON_THROW_ON_ERROR);
};
$attentionOutcome=vp3_initiative_execute_v1(db(),$user,scope_claim($user,$attentionPlan['id']));
$attentionResult=capability_pipeline_stored($attentionPlan['id']);
capability_pipeline_check(!empty($attentionOutcome['ok'])&&$attentionResult['initiative_delivery']==='private'
    &&$attentionResult['initiative_tool_result']['draft']===$attentionDraft&&count(scope_messages())===$messages
    &&list_pipeline_business_state()===$beforeWork,'the specialist retains the proactive list finding without independently posting to Chat or changing owner records');
$beforeJobs=count(scope_runs(1,17,'initiative.prepare'));
$GLOBALS['scopeModel']=static function(string $prompt,string $protocol)use($ref,$attentionDraft):string{
    if($protocol!=='vp3.initiative-plan.v1')throw new LogicException('An unchanged assessment should not prepare another report.');
    $input=json_decode($prompt,true,32,JSON_THROW_ON_ERROR);
    $taskReports=array_values(array_filter($input['specialist_reports']??[],static fn(array $row):bool=>($row['specialist']??null)==='tasks'));
    capability_pipeline_check(($taskReports[0]['summary']??null)===$attentionDraft
        &&($taskReports[0]['state']??null)==='ready'&&($taskReports[0]['record_count']??null)===1
        &&!isset($taskReports[0]['source_ref']), 'coordinator receives the verified task result as advisory context without a new dispatch ref');
    return json_encode(['jobs'=>[['tool'=>'tasks.lists.read','primary'=>$ref,'refs'=>[$ref],
        'focus'=>'Reconsider the same waiting item.','report'=>true]]],JSON_THROW_ON_ERROR);
};
$samePlan=scope_make_plan($user,17,'Reassess the same task-list deadline');
$sameOutcome=vp3_initiative_execute_v1(db(),$user,scope_claim($user,$samePlan));
capability_pipeline_check(!empty($sameOutcome['ok'])&&count(scope_runs(1,17,'initiative.prepare'))===$beforeJobs
    &&count(scope_messages())===$messages,'an unchanged canonical task-list report is deduplicated across assessment passes');
$reportAuthority=[];$binding=$attentionPlan['goal']['binding'];
vp3_initiative_specialist_reports_v1(db(),$user,$binding,[],$reportAuthority);
capability_pipeline_check(isset($reportAuthority['tasks'][$attentionPlan['id']]),'the coordinator keeps an exact private proof for the task report');
$reportOnlyAuthority=[];
vp3_initiative_specialist_reports_v1(db(),$user,$binding,[],$reportOnlyAuthority,true);
capability_pipeline_check($reportOnlyAuthority['tasks']===[],'an existing task report cannot wake the coordinator on its own');
db()->beginTransaction();
db()->prepare("UPDATE agent_workflow_receipts SET result_json='{}' WHERE run_id=?")->execute([$attentionPlan['id']]);
capability_pipeline_reject(static fn()=>vp3_initiative_specialist_reports_current_v1(db(),$user,$binding,$reportAuthority),
    409,'an action and receipt mismatch invalidates the advisory before inference');
db()->rollBack();
db()->beginTransaction();
db()->exec("UPDATE agent_memory_items SET memory_text=memory_text||' new blocker' WHERE id=58001");
capability_pipeline_reject(static fn()=>vp3_initiative_specialist_reports_current_v1(db(),$user,$binding,$reportAuthority),
    409,'changed task evidence invalidates the exact advisory before inference');
db()->rollBack();

$attentionResult=capability_pipeline_legacy_post($user,$attentionPlan);
$pageBinding=vp3_catch_up_page_binding_v1($user);
$workspace=static function(int $conversation=170)use($user,$pageBinding):array{
    $state=vp3_agent_activity_hub_request_v1(db(),$user,'GET','17',(string)$conversation,$pageBinding,'tasks');
    return array_column($state['sections'],null,'id');
};
$models=count($GLOBALS['scopeInference']);
$beforeRead=(int)db()->query('SELECT total_changes()')->fetchColumn();
db()->exec('PRAGMA query_only=ON');
$sections=$workspace();
db()->exec('PRAGMA query_only=OFF');
$review=$sections['office_reviews']['items'][0]??null;
$messageId=(int)$attentionResult['initiative_message_id'];
capability_pipeline_check(array_keys($sections)===['office_reviews','reminders','tasks','lists']
    &&($review['id']??null)==='office-review:'.$attentionPlan['id']&&$review['title']==='List review: Coordinated launch'
    &&$review['detail']===$attentionDraft&&$review['actions'][0]['message_id']===$messageId
    &&$review['actions'][0]['conversation_id']===170&&str_contains($review['actions'][0]['href'],'&message_id='.$messageId)
    &&(int)db()->query('SELECT total_changes()')->fetchColumn()===$beforeRead&&count($GLOBALS['scopeInference'])===$models,
    'the targeted readonly Tasks workspace shows the verified analysis and its exact existing Chat message');
capability_pipeline_check($workspace(171)['office_reviews']['items']===[],'a different saved Chat cannot see the review');
$hasListReview=static fn():bool=>in_array('office-review:'.$attentionPlan['id'],array_column($workspace()['office_reviews']['items'],'id'),true);

db()->beginTransaction();
$pendingReport=agent_workflow_json_v1400(array_replace($attentionResult,['initiative_delivery'=>'pending']));
foreach(['agent_workflow_actions','agent_workflow_receipts'] as $table){
    db()->prepare('UPDATE '.$table.' SET result_json=? WHERE run_id=?')->execute([$pendingReport,$attentionPlan['id']]);
}
capability_pipeline_check(!$hasListReview(),'a saved private draft is withheld until its delivery is verified');
db()->rollBack();
db()->beginTransaction();
db()->prepare("UPDATE chat_messages SET message='A changed assistant message' WHERE id=?")->execute([$messageId]);
capability_pipeline_check(!$hasListReview(),'a changed persisted Chat message cannot prove the workspace report');
db()->rollBack();
db()->beginTransaction();
db()->prepare("UPDATE agent_workflow_receipts SET result_json='{}' WHERE run_id=?")->execute([$attentionPlan['id']]);
capability_pipeline_check(!$hasListReview(),'a mismatched receipt cannot prove the workspace report');
db()->rollBack();
db()->beginTransaction();
db()->exec("UPDATE agent_memory_items SET memory_text=memory_text||' changed workspace evidence' WHERE id=58001");
capability_pipeline_check(!$hasListReview(),'changed private list evidence withholds the workspace review');
db()->rollBack();
scope_command($user,17,170,'off');
capability_pipeline_check(($workspace()['office_reviews']['items'][0]['actions'][0]['message_id']??null)===$messageId,
    'pausing Initiative preserves the exact delivered review while its sources remain current');

reminder_schema_v1(db());
capability_pipeline_reset($user,17,170,true);
db()->exec("INSERT INTO chat_messages(conversation_id,user_id,role,message) VALUES(170,NULL,'assistant','Ready to review the saved reminder.')");
$now=new DateTimeImmutable('now',new DateTimeZone('UTC'));
$next=$now->modify('+3 hours');
db()->prepare("INSERT INTO agent_reminders_v1(id,owner_user_id,title,timezone,start_local,recurrence,delivery,next_at,conversation_id,created_at,updated_at)
    VALUES(61000,1,'Renew the launch domain','UTC',?,'none','chat',?,170,?,?)")
    ->execute([$next->format('Y-m-d\TH:i:s'),reminder_utc_v1($next),reminder_utc_v1($now),reminder_utc_v1($now)]);
$reminderPlan=capability_pipeline_plan($user,17,'reminders.read','reminder:61000','Assess the saved reminder');
$reminderDraft='The launch domain reminder is set for today.';
$nextStep='Check the existing renewal confirmation.';
$question='Is the renewal already taken care of?';
$GLOBALS['scopeModel']=static fn():string=>json_encode(['draft'=>$reminderDraft,'next_step'=>$nextStep,'followup_question'=>$question],JSON_THROW_ON_ERROR);
$reminderOutcome=vp3_initiative_execute_v1(db(),$user,scope_claim($user,$reminderPlan['id']));
$reminderResult=capability_pipeline_stored($reminderPlan['id']);
capability_pipeline_check(!empty($reminderOutcome['ok'])&&$reminderResult['initiative_delivery']==='private'
    &&$reminderResult['initiative_tool_result']['output']['followup_question']===$question,
    'the specialist saves the current reminder question for shared routing');
$reminderResult=capability_pipeline_legacy_post($user,$reminderPlan);
$review=$workspace()['office_reviews']['items'][0]??null;
$advisory=[];
$reports=vp3_initiative_specialist_reports_v1(db(),$user,$reminderPlan['goal']['binding'],[],$advisory);
capability_pipeline_check(!empty($reminderOutcome['ok'])&&($reminderResult['initiative_delivery']??null)==='delivered'
    &&($review['title']??null)==='Reminder review: Renew the launch domain'&&$review['detail']===$reminderDraft
    &&$review['actions'][0]['message_id']===(int)$reminderResult['initiative_message_id']
    &&($review['next_step']??null)===$nextStep&&($review['followup_question']??null)===$question
    &&($reminderResult['initiative_draft']??null)===$reminderDraft."\n\nNext step: $nextStep\n\nQuestion for you: $question"
    &&in_array('reminder',array_column($reports,'source_kind'),true)
    &&(int)db()->query('SELECT COUNT(*) FROM agent_reminder_occurrences_v1 WHERE reminder_id=61000')->fetchColumn()===0,
    'a canonical reminder review is shared with the planner, workspace and exact Chat post without firing the reminder');

$principal=scope_principal($user,17);
$ownerAnswer='Friday at two.';
$replyToOfficeQuestion=static function(int $messageId,string $source,string $question)use($user,$principal,$ownerAnswer):void{
    $request=vp3_initiative_followthrough_request_v1(db(),$user,$principal,170,$ownerAnswer);
    capability_pipeline_check(vp3_initiative_followthrough_possible_v1(db(),1,17,170,$ownerAnswer)
        &&($request['initiative_reply']['message_id']??null)===$messageId&&$request['explicit']===false,
        'a natural composer answer selects the current exact Office question for '.$source);
    db()->prepare("INSERT INTO chat_messages(conversation_id,user_id,role,message) VALUES(170,1,'user',?)")->execute([$ownerAnswer]);
    $mid=(int)db()->lastInsertId();
    $decision=vp3_initiative_followthrough_reply_v1(db(),$user,$principal,170,$mid,null,$ownerAnswer,null,
        static function(string $prompt,string $protocol)use($messageId,$source,$question,$ownerAnswer):string{
            $input=json_decode($prompt,true,32,JSON_THROW_ON_ERROR);
            capability_pipeline_check($protocol==='vp3.initiative-reply.v1'&&$input['proposal']['message_id']===$messageId
                &&str_contains($input['proposal']['text'],'Question for you: '.$question)
                &&mb_strlen($input['proposal']['text'])<=2400
                &&array_column($input['proposal']['source_summaries'],'ref')===[$source]
                &&$input['owner_message']['text']===$ownerAnswer,
                'the existing reply interpreter receives the exact verified Office question and source');
            return '{"intent":"discuss","focus":"The owner can confirm on Friday at two."}';
        });
    capability_pipeline_check($decision['initiative_followthrough']['message_id']===$messageId
        &&$decision['initiative_followthrough']['state']==='discussed'&&$decision['initiative_followthrough']['continuation_run_id']===0
        &&str_contains($decision['advisory_context']['proposal_text'],'Question for you: '.$question),
        'answering the Office question records discussion without a new job or domain action');
};
$replyToOfficeQuestion((int)$reminderResult['initiative_message_id'],'reminder:61000',$question);

$prepareOfficeQuestion=static function(string $tool,string $source,string $title,?string $question)use($user):int{
    capability_pipeline_reset($user,17,170,true);
    db()->exec("INSERT INTO chat_messages(conversation_id,user_id,role,message) VALUES(170,NULL,'assistant','Ready to review the current records.')");
    $run=capability_pipeline_plan($user,17,$tool,$source,$title);
    $GLOBALS['scopeModel']=static fn():string=>json_encode(['draft'=>str_repeat('The saved source is ready to discuss. ',80)]
        +($question===null?[]:['followup_question'=>$question]),JSON_THROW_ON_ERROR);
    $completed=vp3_initiative_execute_v1(db(),$user,scope_claim($user,$run['id']));
    $result=capability_pipeline_stored($run['id']);
    capability_pipeline_check(!empty($completed['ok'])&&$result['initiative_delivery']==='private',
        'the canonical specialist worker saves the exact Office reply fixture privately');
    $result=capability_pipeline_legacy_post($user,$run);
    return (int)$result['initiative_message_id'];
};
$officeQuestion='When will you confirm the details?';
foreach(['tasks.read'=>'task:58000','tasks.lists.read'=>$ref] as $tool=>$source){
    $messageId=$prepareOfficeQuestion($tool,$source,'Question from '.$tool,$officeQuestion);
    $replyToOfficeQuestion($messageId,$source,$officeQuestion);
}
$prepareOfficeQuestion('tasks.read','task:58000','A review without a structured question',null);
capability_pipeline_check(!vp3_initiative_followthrough_possible_v1(db(),1,17,170,$ownerAnswer)
    &&vp3_initiative_followthrough_request_v1(db(),$user,$principal,170,$ownerAnswer)===null,
    'an Office review without a structured question leaves a natural composer message unassociated');
$messageId=$prepareOfficeQuestion('tasks.read','task:58000','An Office question outside the reply window',$officeQuestion);
db()->prepare('UPDATE chat_messages SET created_at=? WHERE id=?')->execute([gmdate('Y-m-d H:i:s',time()-86401),$messageId]);
capability_pipeline_check(!vp3_initiative_followthrough_possible_v1(db(),1,17,170,$ownerAnswer)
    &&vp3_initiative_followthrough_request_v1(db(),$user,$principal,170,$ownerAnswer)===null,
    'an Office question past the 24-hour fence cannot select a natural reply');
$prepareOfficeQuestion('tasks.read','task:58000','An Office question whose source changed',$officeQuestion);
db()->exec("UPDATE agent_memory_items SET memory_text='Changed task contents after the report' WHERE id=58000");
capability_pipeline_check(vp3_initiative_followthrough_possible_v1(db(),1,17,170,$ownerAnswer)
    &&vp3_initiative_followthrough_request_v1(db(),$user,$principal,170,$ownerAnswer)===null,
    'the verified candidate refuses a changed Office source even when its cheap marker exists');

echo 'AGENT_INITIATIVE_TASK_LISTS_PIPELINE_V1=PASS ('.($GLOBALS['capabilityPipelineChecks']-$listPipelineStart)
    ." focused cases; actual source/model/queue/action/receipt/Chat/Brain)\n";
