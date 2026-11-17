<?php
declare(strict_types=1);

// Real account/Agent, database, scheduler, leases, worker, receipts and
// private Activity/Chat. Only the physical HomeServer transport is a fixture.
// Canvas has its own complete gate; reuse its database setup without provider stubs.
define('VP3_EMAIL_CANVAS_PIPELINE_FIXTURE', true);
require __DIR__.'/fixtures/initiative-ai-settings-v1.php';
require __DIR__.'/agent-initiative-canvas-pipeline-v1.php';
require_once dirname(__DIR__).'/includes/agent-activity-hub-v1.php';
require_once dirname(__DIR__).'/includes/agent-initiative-office-entry-work-v1.php';
require_once dirname(__DIR__).'/includes/agent-top-of-mind-v1.php';
require_once dirname(__DIR__).'/includes/agent-coordination-rules-v1.php';
require_once dirname(__DIR__).'/includes/agent-initiative-tools-v1.php';
require_once dirname(__DIR__).'/includes/agent-initiative-office-entry-source-v1.php';

$GLOBALS['pipelineInference']=[];
$GLOBALS['pipelineRuntimePlan']=['route'=>'homeserver','blocked'=>false,'effective_preference'=>'homeserver_only',
    'try_homeserver'=>true,'home'=>['supported'=>true],'cloud'=>['ready'=>false]];
function homeserver_execution_v220_registry(int $owner):array
{
    return ['available'=>true,'capabilities'=>['unified_execution'=>[
        'profile_safe_local_inference'=>['operation'=>'agent.infer.local','stateless'=>true,
            'local_only'=>true,'caller_supplied_context_only'=>true,'tools_enabled'=>false],
    ]]];
}
function homeserver_execution_v230_execute(int $owner,string $operation,array $payload,?callable $fallback=null,bool $transientContext=false):array
{
    $protocol=VP3_INITIATIVE_OFFICE_REPORT_PROTOCOL_V1;
    if($owner!==1||$operation!=='agent.infer.local'||array_keys($payload)!==['messages']
        ||($payload['messages'][0]['content']??null)!==vp3_tool_json_contract_v1($protocol)){
        throw new LogicException('Expected the canonical stateless Office review transport.');
    }
    $GLOBALS['pipelineInference'][]=['protocol'=>$protocol,'context'=>ai_v100_service_context()];
    return ['result'=>['reply'=>($GLOBALS['pipelineModel'])($payload['messages'][1]['content'],$protocol)],
        'execution'=>['status'=>'completed']];
}

$officeStart=$checks;
$officeAgent=7;
$officeChat=11;
$taskId=63001;
$listId=63002;
$reminderId=63003;
$taskRef='task:'.$taskId;
$listRef='task-list:'.$listId;
$reminderRef='reminder:'.$reminderId;
$taskBody='OFFICE_ENTRY_TASK_FIXTURE. Check which contractor can confirm the launch schedule.';
$privateDraft='The contractor has not confirmed the launch schedule.';
$privatePlan=['Compare the current contractor options.','Outline a contingency if the contractor is unavailable.'];
$agentTaskTitle='Prepare a launch scheduling contingency';
$childDraft='The contingency can preserve the agreed launch dates by preparing two contractor options for private review.';
$ownerDraft='The new schedule still depends on a decision by the owner.';
$ownerQuestion='Which contractor should we ask to confirm the launch?';
$pdo->exec("UPDATE agent_memory_items SET is_active=0 WHERE memory_type IN ('task','commitment')");
$pdo->exec("UPDATE agent_workflow_runs SET status='cancelled' WHERE owner_user_id=1 AND source_key LIKE 'office-entry:%'
    AND status IN ('queued','approved','executing')");
$pdo->prepare("INSERT INTO chat_messages(conversation_id,role,message) VALUES(?,'assistant','I am ready to review the current records.')")
    ->execute([$officeChat]);
vp3_task_lists_schema_v1($pdo);
reminder_schema_v1($pdo);
vp3_initiative_chat_v1($pdo,$owner,$principal,['conversation_id'=>$officeChat],'off',null);
vp3_initiative_chat_v1($pdo,$owner,$principal,['conversation_id'=>$officeChat],'on',null);
initiative_fixture_morning_brief_reviewed($owner,$officeAgent);
$officeRuleSettings=vp3_coordination_rule_settings_v1($pdo,$owner,$officeAgent,$officeChat);
vp3_coordination_rule_settings_v1($pdo,$owner,$officeAgent,$officeChat,$officeRuleSettings['revision'],[
    'tasks'=>['overdue'=>'watch'],
]);

function office_entry_fixture_presence(int $cid,string $state): void
{
    $details=['visible'=>$state!=='away','conversation_id'=>$cid,'chat_focused'=>$state!=='away','chat_busy'=>$state==='busy'];
    db()->prepare("UPDATE agent_activity_state SET surface='chat',context_key=?,activity_state='idle',
        last_heartbeat_at=CURRENT_TIMESTAMP,idle_since_at=CURRENT_TIMESTAMP,details_json=? WHERE user_id=1")
        ->execute(['chat:'.$cid,json_encode($details,JSON_THROW_ON_ERROR)]);
    db()->prepare("UPDATE chat_messages SET created_at='2000-01-01 00:00:00' WHERE conversation_id=?")->execute([$cid]);
}

function office_entry_fixture_due(): void
{
    // The worker sweep owns discovery. Expiring the fixture review timestamp
    // tests two real due sweeps without waiting for wall-clock time.
    db()->exec("UPDATE agent_memory_items SET last_seen_at='2000-01-01 00:00:00'
        WHERE user_id=1 AND user_agent_id=7 AND memory_type='initiative_sweep'");
    $query=db()->query("SELECT id,metadata_json FROM agent_memory_items WHERE user_id=1 AND user_agent_id=7
        AND memory_type='initiative_sweep' AND subject='office-entry-scan'");
    foreach($query->fetchAll(PDO::FETCH_ASSOC) as $row){
        $checkpoint=json_decode($row['metadata_json'],true,32,JSON_THROW_ON_ERROR);
        $checkpoint['scanned_at']=time()-60;
        db()->prepare('UPDATE agent_memory_items SET metadata_json=? WHERE id=?')
            ->execute([json_encode($checkpoint,JSON_THROW_ON_ERROR),$row['id']]);
    }
    vp3_initiative_sweep_v1(db(),25);
}

function office_entry_fixture_wait_for_run(string $ref,int $count): array
{
    for($attempt=0;$attempt<6&&count(office_entry_fixture_runs($ref))<$count;$attempt++)office_entry_fixture_due();
    return office_entry_fixture_latest($ref);
}

function office_entry_fixture_runs(string $ref,bool $child=false): array
{
    $matches=[];
    foreach(canvas_pipeline_runs('initiative.prepare') as $row){
        $goal=json_decode((string)$row['goal'],true,32,JSON_THROW_ON_ERROR);
        if(($goal['primary']??null)!==$ref||($goal['office_entry']??null)!==true
            ||is_array($goal['office_agent_task']??null)!==$child)continue;
        $matches[]=['id'=>(int)$row['id'],'goal'=>$goal,'status'=>$row['status']];
    }
    return $matches;
}

function office_entry_fixture_latest(string $ref,bool $child=false): array
{
    $runs=office_entry_fixture_runs($ref,$child);
    if(!$runs)throw new RuntimeException('No canonical Office run was queued for '.$ref);
    return $runs[count($runs)-1];
}

function office_entry_fixture_result(int $id): array
{
    $result=canvas_pipeline_result($id);
    $action=db()->prepare('SELECT result_json FROM agent_workflow_actions WHERE run_id=? ORDER BY id LIMIT 1');
    $action->execute([$id]);
    canvas_pipeline_check(json_decode((string)$action->fetchColumn(),true,32,JSON_THROW_ON_ERROR)===$result,
        'the completed Office action and canonical execution receipt agree');
    return $result;
}

function office_entry_fixture_business_state(): array
{
    $states=[];
    foreach(['agent_task_lists_v1'=>'id','agent_task_list_items_v1'=>'list_id,task_id',
        'agent_reminders_v1'=>'id','agent_reminder_occurrences_v1'=>'id'] as $table=>$order){
        $states[$table]=db()->query('SELECT * FROM '.$table.' ORDER BY '.$order)->fetchAll(PDO::FETCH_ASSOC);
    }
    $states['tasks']=db()->query("SELECT * FROM agent_memory_items WHERE memory_type IN ('task','commitment') ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
    return $states;
}

function office_entry_fixture_card(array $owner,int $agent,int $cid,string $collection,string $id): ?array
{
    $state=vp3_agent_activity_hub_request_v1(db(),$owner,'GET',(string)$agent,(string)$cid,
        vp3_catch_up_page_binding_v1($owner),'tasks',$collection);
    foreach($state['sections'] as $section)foreach($section['items'] as $card)if($card['id']===$id)return $card;
    return null;
}

function office_entry_fixture_execute(array $user,array $run): array
{
    return vp3_initiative_execute_v1(db(),$user,canvas_pipeline_claim($run['id']));
}

$seed=$pdo->prepare("INSERT INTO agent_memory_items(id,user_id,user_agent_id,memory_type,subject,memory_text,memory_hash,
    memory_scope_version,confidence,occurrence_count,first_seen_at,last_seen_at,is_active,metadata_json)
    VALUES(?,1,?,'task',?,?,?,410,.95,1,CURRENT_TIMESTAMP,CURRENT_TIMESTAMP,1,?)");
$taskMetadata=['task_status'=>'open','due_at'=>gmdate('Y-m-d',time()-86400),'source_kind'=>'explicit_user'];
$seed->execute([$taskId,$officeAgent,'Review the contractor schedule',$taskBody,'office-entry-'.$taskId,
    json_encode($taskMetadata,JSON_THROW_ON_ERROR)]);
$seed->execute([63004,8,'Foreign Agent source','OFFICE_ENTRY_OTHER_AGENT_FIXTURE','office-entry-63004',
    json_encode($taskMetadata,JSON_THROW_ON_ERROR)]);

$sourceBinding=vp3_initiative_policy_v1($owner,$officeAgent);
$sourceScope=vp3_initiative_tools_scope_v1($pdo,$owner,$sourceBinding);
$standalone=vp3_initiative_office_entry_source_v1($pdo,$sourceScope,$sourceBinding,$taskRef);
$contextList=$pdo->prepare('INSERT INTO agent_task_lists_v1(id,owner_user_id,agent_scope_id,name,name_key,created_at) VALUES(?,?,?,?,?,?)');
$contextMember=$pdo->prepare('INSERT INTO agent_task_list_items_v1(list_id,task_id,item_key,created_at) VALUES(?,?,?,?)');
$contextAt=gmdate('Y-m-d H:i:s');
foreach([[63101,1,$officeAgent,'Launch shopping list'],[63102,1,8,'FOREIGN_AGENT_LIST'],[63103,2,$officeAgent,'FOREIGN_OWNER_LIST']]
    as [$contextId,$contextOwner,$contextAgent,$contextName]){
    $contextList->execute([$contextId,$contextOwner,$contextAgent,$contextName,vp3_task_list_key_v1($contextName),$contextAt]);
    $contextMember->execute([$contextId,$taskId,'office-entry-context',$contextAt]);
}
$contextual=vp3_initiative_office_entry_source_v1($pdo,$sourceScope,$sourceBinding,$taskRef);
$contextEvidence=[$taskRef=>vp3_initiative_office_entry_source_evidence_v1($contextual)];
$contextJob=['primary'=>$taskRef,'refs'=>[$taskRef],'tool'=>'tasks.read'];
$contextRead=vp3_initiative_office_entry_read_v1($pdo,$sourceScope,$sourceBinding,$contextJob,$contextEvidence);
$promptRecord=json_decode($contextRead['records'][0]['text'],true,32,JSON_THROW_ON_ERROR);
canvas_pipeline_check($promptRecord['parent_lists']===['Launch shopping list']&&!isset($standalone['record']['parent_lists'])
    &&$contextual['revision']!==$standalone['revision'],
    'the exact model read adds only authorized list names without list identifiers');
$pdo->prepare('UPDATE agent_task_lists_v1 SET name=?,name_key=? WHERE id=63101')
    ->execute(['Contractor supply list',vp3_task_list_key_v1('Contractor supply list')]);
$revisedContext=vp3_initiative_office_entry_source_v1($pdo,$sourceScope,$sourceBinding,$taskRef);
$contextWasStale=false;
try{vp3_initiative_office_entry_read_v1($pdo,$sourceScope,$sourceBinding,$contextJob,$contextEvidence);}
catch(Vp3InitiativeSourcesChangedV1 $error){$contextWasStale=true;}
canvas_pipeline_check($contextWasStale&&$revisedContext['revision']!==$contextual['revision']
    &&$revisedContext['record']['parent_lists']===['Contractor supply list'],
    'renaming a parent list changes the exact revision and invalidates the old review evidence');
$pdo->exec('DELETE FROM agent_task_list_items_v1 WHERE list_id IN (63101,63102,63103)');
$pdo->exec('DELETE FROM agent_task_lists_v1 WHERE id IN (63101,63102,63103)');
canvas_pipeline_check(vp3_initiative_office_entry_source_v1($pdo,$sourceScope,$sourceBinding,$taskRef)===$standalone,
    'removing membership restores the standalone task revision and context');

office_entry_fixture_presence($officeChat,'away');
$business=office_entry_fixture_business_state();
$messages=canvas_pipeline_count('chat_messages');
$models=count($GLOBALS['pipelineInference']);
$first=office_entry_fixture_wait_for_run($taskRef,1);
canvas_pipeline_check(($first['goal']['binding']['conversation_id']??null)===$officeChat
    &&array_keys($first['goal']['sources'])===[$taskRef]&&($first['goal']['tool']??null)==='tasks.read'
    &&office_entry_fixture_runs('task:63004')===[]&&count($GLOBALS['pipelineInference'])===$models,
    'a normal due sweep queues a source-exact private Office report without inference or sibling records');
office_entry_fixture_due();
canvas_pipeline_check(count(office_entry_fixture_runs($taskRef))===1,
    'a repeated due sweep cannot add another active report for the same entry revision');

$GLOBALS['pipelineModel']=static function(string $prompt,string $protocol)use($taskBody,$privateDraft,$privatePlan,$agentTaskTitle):string{
    $input=json_decode($prompt,true,32,JSON_THROW_ON_ERROR);
    canvas_pipeline_check($protocol===VP3_INITIATIVE_OFFICE_REPORT_PROTOCOL_V1&&($input['response_kind']??null)==='office_entry_review'
        &&!db()->inTransaction()&&str_contains($prompt,$taskBody)&&!str_contains($prompt,'OFFICE_ENTRY_OTHER_AGENT_FIXTURE'),
        'the real report worker passes exact saved task context through the readonly Office review model route');
    return json_encode(['office_disposition'=>'review','draft'=>$privateDraft,'next_step'=>'Compare the current contractor options.',
        'followup_question'=>'Would the alternate contractor be acceptable if the first choice is unavailable?',
        'action_plan'=>$privatePlan,'agent_task_title'=>$agentTaskTitle,'owner_attention'=>false],JSON_THROW_ON_ERROR);
};
canvas_pipeline_check(!empty(office_entry_fixture_execute($owner,$first)['ok']),
    'the claimed Office preparation completes through the canonical worker');
$report=office_entry_fixture_result($first['id']);
canvas_pipeline_check(($report['effect_state']??null)==='private_draft_ready'&&($report['initiative_delivery']??null)==='private'
    &&str_starts_with((string)($report['initiative_tool_result']['draft']??''),$privateDraft)
    &&($report['initiative_tool_result']['output']['action_plan']??null)===$privatePlan
    &&canvas_pipeline_count('chat_messages')===$messages&&office_entry_fixture_business_state()===$business,
    'the private report and its action plan persist without Chat or changes to owner records');
$card=office_entry_fixture_card($owner,$officeAgent,$officeChat,'tasks',$taskRef);
canvas_pipeline_check(($card['office_report']['summary']??null)===$privateDraft
    &&($card['office_report']['action_plan']??null)===$privatePlan
    &&($card['office_report']['agent_task']['state']??null)==='queued'
    &&!isset($card['office_report']['chat_action'])
    &&!str_contains(json_encode($card,JSON_THROW_ON_ERROR),$taskBody),
    'the verified report is visible on its exact inventory card while the raw source body remains private');
$compact=office_entry_fixture_card($owner,$officeAgent,$officeChat,'reviews','office-review:'.$first['id']);
canvas_pipeline_check(($compact['detail']??null)===$privateDraft
    &&($compact['next_step']??null)==='Compare the current contractor options.'
    &&($compact['followup_question']??null)==='Would the alternate contractor be acceptable if the first choice is unavailable?'
    &&($compact['actions']??null)===[['kind'=>'url','label'=>'Review task','href'=>url('/account.php#agent-brain')]],
    'the compact specialist shows the current private review and its real task source without a fabricated Chat link');
$siblingChat=63005;
$pdo->prepare('INSERT INTO chat_conversations(id,user_id,user_agent_id,title) VALUES(?,1,?,?)')
    ->execute([$siblingChat,$officeAgent,'Sibling Office fixture']);
canvas_pipeline_check(office_entry_fixture_card($owner,$officeAgent,$siblingChat,'reviews','office-review:'.$first['id'])===null,
    'the same Agent in a sibling Chat does not expose the original private compact review');
$child=office_entry_fixture_latest($taskRef,true);
canvas_pipeline_check(count(office_entry_fixture_runs($taskRef,true))===1
    &&($child['goal']['office_agent_task']['report_run_id']??null)===$first['id']
    &&($child['goal']['office_agent_task']['title']??null)===$agentTaskTitle
    &&($child['goal']['sources']??null)===$first['goal']['sources'],
    'the worker queues one linked Agent task using the report and the exact same source');
$inference=count($GLOBALS['pipelineInference']);
office_entry_fixture_due();
canvas_pipeline_check(count(office_entry_fixture_runs($taskRef))===1&&count(office_entry_fixture_runs($taskRef,true))===1
    &&count($GLOBALS['pipelineInference'])===$inference&&canvas_pipeline_count('chat_messages')===$messages,
    'a completed private report and its queued child stay deduplicated on another due sweep');

$GLOBALS['pipelineModel']=static function(string $prompt,string $protocol)use($taskBody,$childDraft):string{
    $input=json_decode($prompt,true,32,JSON_THROW_ON_ERROR);
    canvas_pipeline_check($protocol===VP3_INITIATIVE_OFFICE_REPORT_PROTOCOL_V1&&($input['response_kind']??null)==='office_agent_task'
        &&!db()->inTransaction()&&str_contains($prompt,$taskBody),
        'the linked Agent task uses its own private preparation mode with the current source');
    return json_encode(['draft'=>$childDraft],JSON_THROW_ON_ERROR);
};
canvas_pipeline_check(!empty(office_entry_fixture_execute($owner,$child)['ok']),
    'the linked Agent task advances through an actual leased canonical preparation');
$prepared=office_entry_fixture_result($child['id']);
canvas_pipeline_check(($prepared['initiative_tool_result']['draft']??null)===$childDraft
    &&($prepared['initiative_delivery']??null)==='private'
    &&canvas_pipeline_count('chat_messages')===$messages&&office_entry_fixture_business_state()===$business
    &&count(office_entry_fixture_runs($taskRef,true))===1,
    'the private child result creates no additional workflow, Chat message, or domain action');
$card=office_entry_fixture_card($owner,$officeAgent,$officeChat,'tasks',$taskRef);
canvas_pipeline_check(($card['office_report']['agent_task']??null)===['title'=>$agentTaskTitle,'state'=>'completed','result'=>$childDraft],
    'the source card reads the completed linked Agent task result from its verified child receipt');

// A meaningful source change invalidates the finished private report and
// allows exactly one replacement. Top of Mind owns the Chat decision.
$changedBody='OFFICE_ENTRY_CHANGED_FIXTURE. The initial contractor is unavailable and only the owner can choose the alternate.';
$updateBody=$pdo->prepare('UPDATE agent_memory_items SET memory_text=? WHERE id=?');
$updateBody->execute([$changedBody,$taskId]);
$changedCard=office_entry_fixture_card($owner,$officeAgent,$officeChat,'tasks',$taskRef);
canvas_pipeline_check(!str_contains(json_encode($changedCard,JSON_THROW_ON_ERROR),$privateDraft),
    'changing the exact source withdraws the previous completed report from the private inventory');
canvas_pipeline_check(office_entry_fixture_card($owner,$officeAgent,$officeChat,'reviews','office-review:'.$first['id'])===null,
    'changing the exact source also withdraws the private compact review');
$replacement=office_entry_fixture_wait_for_run($taskRef,2);
canvas_pipeline_check(count(office_entry_fixture_runs($taskRef))===2&&$replacement['id']!==$first['id']
    &&$replacement['goal']['sources']!==$first['goal']['sources'],
    'the real sweep admits one replacement against the new source revision');
$GLOBALS['pipelineModel']=static function(string $prompt,string $protocol)use($changedBody,$ownerDraft,$ownerQuestion):string{
    $input=json_decode($prompt,true,32,JSON_THROW_ON_ERROR);
    canvas_pipeline_check($protocol===VP3_INITIATIVE_OFFICE_REPORT_PROTOCOL_V1&&$input['response_kind']==='office_entry_review'
        &&str_contains($prompt,$changedBody),'the replacement reads the new private source under its own revision');
    return json_encode(['office_disposition'=>'review','draft'=>$ownerDraft,'followup_question'=>$ownerQuestion,
        'action_plan'=>['Outline what information would make the contractor decision easier.'],
        'agent_task_title'=>'Prepare private contractor comparisons','owner_attention'=>true],JSON_THROW_ON_ERROR);
};
$business=office_entry_fixture_business_state();
canvas_pipeline_check(!empty(office_entry_fixture_execute($owner,$replacement)['ok']),
    'the revised report prepares a question while the owner is away');
$attention=office_entry_fixture_result($replacement['id']);
canvas_pipeline_check(($attention['initiative_delivery']??null)==='private'
    &&($attention['initiative_tool_result']['output']['owner_attention']??null)===true
    &&canvas_pipeline_count('chat_messages')===$messages
    &&office_entry_fixture_business_state()===$business,
    'the specialist saves an owner-attention finding privately without a direct Chat handoff');
$coordinationRef='office:'.$taskRef;
$policy=vp3_initiative_policy_v1($owner,$officeAgent);
$pdo->exec("DELETE FROM agent_memory_items WHERE user_id=1 AND user_agent_id=7 AND memory_type='initiative_sweep' AND subject='coordination-scan'");
vp3_initiative_schedule_coordination_v1($pdo,$owner,$policy,false);
$coordination=array_values(array_filter(vp3_coordination_runs_v1($pdo,$owner,$policy),static function(array $run)use($coordinationRef):bool{
    return (json_decode((string)$run['goal'],true)['primary']??null)===$coordinationRef;
}));
$coordinationRun=(int)($coordination[0]['id']??0);
$coordinationGoal=json_decode((string)($coordination[0]['goal']??'{}'),true,32,JSON_THROW_ON_ERROR);
$currentCoordination=vp3_coordination_sources_v1($pdo,$owner,$policy,[$coordinationRef]);
if(($coordinationGoal['sources'][$coordinationRef]??null)!==($currentCoordination['items'][$coordinationRef]['revision']??null)){
    $stale=vp3_initiative_execute_v1($pdo,$owner,canvas_pipeline_claim($coordinationRun),
        static fn()=>throw new LogicException('A superseded saved finding must not call a model.'));
    canvas_pipeline_check(empty($stale['ok'])&&canvas_pipeline_count('chat_messages')===$messages,
        'the worker rejects a queued finding from the previous source revision without posting it');
    $pdo->exec("DELETE FROM agent_memory_items WHERE user_id=1 AND user_agent_id=7 AND memory_type='initiative_sweep' AND subject='coordination-scan'");
    vp3_initiative_schedule_coordination_v1($pdo,$owner,$policy,false);
    $coordination=array_values(array_filter(vp3_coordination_runs_v1($pdo,$owner,$policy),static function(array $run)use($coordinationRef):bool{
        return (json_decode((string)$run['goal'],true)['primary']??null)===$coordinationRef
            &&in_array($run['status'],['queued','approved'],true);
    }));
    $coordinationRun=(int)($coordination[0]['id']??0);
    $coordinationGoal=json_decode((string)($coordination[0]['goal']??'{}'),true,32,JSON_THROW_ON_ERROR);
}
canvas_pipeline_check(count($coordination)===1&&($coordinationGoal['coordination_saved']??null)===true
    &&($coordinationGoal['coordination_route']??null)===['specialist'=>'tasks','trigger'=>'waiting_owner','action'=>'ask'],
    'Top of Mind matches the specialist finding to one Ask in Chat rule');
office_entry_fixture_presence($officeChat,'busy');
canvas_pipeline_check(!empty(vp3_initiative_execute_v1($pdo,$owner,canvas_pipeline_claim($coordinationRun),
        static fn()=>throw new LogicException('Saved owner attention needs no new model call.'))['ok'])
    &&office_entry_fixture_result($coordinationRun)['initiative_delivery']==='delivered'
    &&!vp3_initiative_deliver_pending_v1($pdo,$owner,$officeAgent)
    &&canvas_pipeline_count('chat_messages')===$messages+1,
    'the saved Chat receives the verified timestamped question while the owner is busy');
office_entry_fixture_presence($officeChat,'quiet');
$inference=count($GLOBALS['pipelineInference']);
canvas_pipeline_check(!vp3_initiative_deliver_pending_v1($pdo,$owner,$officeAgent)
    &&canvas_pipeline_count('chat_messages')===$messages+1&&count($GLOBALS['pipelineInference'])===$inference,
    'returning to quiet Chat does not repeat the owner-attention report or invoke another model');
$delivered=office_entry_fixture_result($coordinationRun);
canvas_pipeline_check(($delivered['initiative_delivery']??null)==='delivered'
    &&str_contains((string)($delivered['initiative_draft']??''),$ownerQuestion)
    &&!vp3_initiative_deliver_pending_v1($pdo,$owner,$officeAgent)
    &&canvas_pipeline_count('chat_messages')===$messages+1,
    'the verified question is delivered once in the bound Chat');
$questionMessage=(int)$delivered['initiative_message_id'];
$ownerAnswer='Use the alternate contractor.';
$loaded=vp3_initiative_followthrough_load_v1($pdo,$owner,$principal,$officeChat,$questionMessage);
$replyRequest=vp3_initiative_followthrough_request_v1($pdo,$owner,$principal,$officeChat,$ownerAnswer);
canvas_pipeline_check(($loaded['run']['id']??null)===$coordinationRun
    &&($loaded['goal']['sources']??null)===$coordinationGoal['sources']
    &&($replyRequest['initiative_reply']['message_id']??null)===$questionMessage&&($replyRequest['explicit']??null)===false,
    'a plain owner answer selects the current exact Office question in the bound Chat');

$updateBody->execute([$taskBody,$taskId]);
canvas_pipeline_check(array_filter(vp3_coordination_visible_v1($pdo,$owner,$officeAgent,$officeChat),
    static fn(array $finding):bool=>($finding['message_id']??null)===$questionMessage)===[],
    'Top of Mind drops the exact Chat handoff while its Office source has changed');
$modelsBeforeRevert=count($GLOBALS['pipelineInference']);
$revertedCard=office_entry_fixture_card($owner,$officeAgent,$officeChat,'tasks',$taskRef);
canvas_pipeline_check(($revertedCard['office_report']['summary']??null)===$privateDraft
    &&($revertedCard['office_report']['agent_task']['result']??null)===$childDraft
    &&!isset($revertedCard['office_report']['chat_action']),
    'reverting an edit restores the verified private report and child for that exact older source revision');
for($attempt=0;$attempt<4;$attempt++)office_entry_fixture_due();
canvas_pipeline_check(count(office_entry_fixture_runs($taskRef))===2&&count(office_entry_fixture_runs($taskRef,true))===2
    &&count($GLOBALS['pipelineInference'])===$modelsBeforeRevert,
    'sweeps reuse the restored source revision without another report or child');

$updateBody->execute([$changedBody,$taskId]);
$chatCard=office_entry_fixture_card($owner,$officeAgent,$officeChat,'tasks',$taskRef);
$shared=array_column(vp3_coordination_visible_v1($pdo,$owner,$officeAgent,$officeChat),'message_id','id');
canvas_pipeline_check(($chatCard['office_report']['summary']??null)===$ownerDraft
    &&!isset($chatCard['office_report']['chat_action'])
    &&($shared[$coordinationGoal['coordination_id']]??null)===$questionMessage,
    'restoring the newer reviewed revision restores the private report and exact shared Chat link');
$pdo->prepare("INSERT INTO chat_messages(conversation_id,user_id,role,message) VALUES(?,1,'user',?)")
    ->execute([$officeChat,$ownerAnswer]);
$answerMessage=(int)$pdo->lastInsertId();
$messages++;
$reply=vp3_initiative_followthrough_reply_v1($pdo,$owner,$principal,$officeChat,$answerMessage,null,$ownerAnswer,null,
    static function(string $prompt,string $protocol)use($questionMessage,$ownerAnswer,$ownerQuestion,$coordinationRef):string{
        $input=json_decode($prompt,true,32,JSON_THROW_ON_ERROR);
        canvas_pipeline_check($protocol==='vp3.initiative-reply.v1'&&($input['proposal']['message_id']??null)===$questionMessage
            &&str_contains($input['proposal']['text']??'','Question for you: '.$ownerQuestion)
            &&array_column($input['proposal']['source_summaries']??[],'ref')===[$coordinationRef]
            &&($input['owner_message']['text']??null)===$ownerAnswer,
            'the real reply interpreter sees the exact current Office question, bound source and owner answer');
        return '{"intent":"discuss","focus":"The owner chose the alternate contractor."}';
    });
canvas_pipeline_check(($reply['initiative_followthrough']['state']??null)==='discussed'
    &&($reply['initiative_followthrough']['message_id']??null)===$questionMessage
    &&($reply['initiative_followthrough']['continuation_run_id']??null)===0&&office_entry_fixture_business_state()===$business,
    'a plain reply records discussion against the Office report without another job or owner-record change');
$other=['id'=>2,'is_active'=>1];
canvas_pipeline_check(office_entry_fixture_card($owner,8,12,'tasks',$taskRef)===null
    &&office_entry_fixture_card($other,9,13,'tasks',$taskRef)===null,
    'another owner and sibling Agent cannot load the Office source or its report');
$replacementChild=office_entry_fixture_latest($taskRef,true);
$receiptQuery=$pdo->prepare("SELECT id,result_json FROM agent_workflow_receipts WHERE run_id=? AND status='completed' ORDER BY id DESC LIMIT 1");
$receiptQuery->execute([$replacement['id']]);
$parentReceipt=$receiptQuery->fetch(PDO::FETCH_ASSOC);
$changeReceipt=$pdo->prepare('UPDATE agent_workflow_receipts SET result_json=? WHERE id=?');
$changeReceipt->execute(['{}',$parentReceipt['id']]);
$GLOBALS['pipelineModel']=static fn()=>throw new LogicException('A child with an invalid parent cannot call the model.');
$inference=count($GLOBALS['pipelineInference']);
$badParent=office_entry_fixture_execute($owner,$replacementChild);
canvas_pipeline_check(empty($badParent['ok'])&&count($GLOBALS['pipelineInference'])===$inference
    &&office_entry_fixture_business_state()===$business,
    'a mismatched parent action and receipt stops the child before inference or changes');
$changeReceipt->execute([$parentReceipt['result_json'],$parentReceipt['id']]);
office_entry_fixture_due();
canvas_pipeline_check(count(office_entry_fixture_runs($taskRef))===2&&count(office_entry_fixture_runs($taskRef,true))===2,
    'retrying the same source after a failed child does not create another report or child');

// Close the canonical source in the fixture only. This lifecycle view has
// no new model or child work, and still obeys the record's permissions.
$taskMetadata['task_status']='completed';
$updateMetadata=$pdo->prepare('UPDATE agent_memory_items SET metadata_json=? WHERE id=?');
$updateMetadata->execute([json_encode($taskMetadata,JSON_THROW_ON_ERROR),$taskId]);
$business=office_entry_fixture_business_state();
$closed=office_entry_fixture_wait_for_run($taskRef,3);
canvas_pipeline_check(count(office_entry_fixture_runs($taskRef))===3
    &&!empty(office_entry_fixture_execute($owner,$closed)['ok']),
    'the due sweep queues a canonical lifecycle summary for a closed entry');
$closedCard=office_entry_fixture_card($owner,$officeAgent,$officeChat,'tasks',$taskRef);
canvas_pipeline_check(is_array($closedCard['office_report']??null)
    &&($closedCard['office_report']['state']??null)==='ready'
    &&preg_match('/complet/i',json_encode($closedCard['office_report'],JSON_THROW_ON_ERROR))===1
    &&!str_contains(json_encode($closedCard,JSON_THROW_ON_ERROR),$ownerDraft)
    &&count($GLOBALS['pipelineInference'])===$inference&&count(office_entry_fixture_runs($taskRef,true))===2
    &&canvas_pipeline_count('chat_messages')===$messages+1&&office_entry_fixture_business_state()===$business,
    'the completed source has a current lifecycle summary with no child, inference, or new Chat post');
$pdo->prepare("INSERT INTO user_agent_data_rules VALUES(?,'knowledge',?,'deny')")->execute([$officeAgent,'brain:'.$taskId]);
canvas_pipeline_check(office_entry_fixture_card($owner,$officeAgent,$officeChat,'tasks',$taskRef)===null,
    'revoked source access removes the entry and its private lifecycle summary');

// The same scheduled reader covers lists and reminders independently of
// task status. The fixture changes a list member during its claimed child.
$stamp=gmdate('Y-m-d H:i:s');
$listMember=64005;
$seed->execute([$listMember,$officeAgent,'Closed item on the launch checklist','OFFICE_ENTRY_LIST_MEMBER_FIXTURE',
    'office-entry-'.$listMember,json_encode(['task_status'=>'completed','source_kind'=>'explicit_user'],JSON_THROW_ON_ERROR)]);
$seed->execute([64006,$officeAgent,'Open item on the launch checklist','A handoff still needs a private next step.',
    'office-entry-64006',json_encode(['task_status'=>'open','source_kind'=>'explicit_user'],JSON_THROW_ON_ERROR)]);
$pdo->prepare('INSERT INTO agent_task_lists_v1(id,owner_user_id,agent_scope_id,name,name_key,created_at) VALUES(?,1,?,?,?,?)')
    ->execute([$listId,$officeAgent,'Launch checklist',vp3_task_list_key_v1('Launch checklist'),$stamp]);
$member=$pdo->prepare('INSERT INTO agent_task_list_items_v1(list_id,task_id,item_key,created_at) VALUES(?,?,?,?)');
$member->execute([$listId,$listMember,'office-entry-list-member',$stamp]);
$member->execute([$listId,64006,'office-entry-open-member',$stamp]);
$list=office_entry_fixture_wait_for_run($listRef,1);
$GLOBALS['pipelineModel']=static function(string $prompt,string $protocol):string{
    $input=json_decode($prompt,true,32,JSON_THROW_ON_ERROR);
    canvas_pipeline_check($protocol===VP3_INITIATIVE_OFFICE_REPORT_PROTOCOL_V1&&$input['response_kind']==='office_entry_review'
        &&str_contains($prompt,'OFFICE_ENTRY_LIST_MEMBER_FIXTURE'),'a list review reads the exact saved member, including completed context');
    return json_encode(['office_disposition'=>'review','draft'=>'The launch checklist records one completed coordination item.',
        'action_plan'=>['Prepare a private list of remaining questions.'],'agent_task_title'=>'Prepare remaining launch questions',
        'owner_attention'=>false],JSON_THROW_ON_ERROR);
};
$business=office_entry_fixture_business_state();
canvas_pipeline_check(!empty(office_entry_fixture_execute($owner,$list)['ok'])&&office_entry_fixture_business_state()===$business,
    'the real list report leaves its canonical list, task, and membership unchanged');
$listChild=office_entry_fixture_latest($listRef,true);
$orbWork=vp3_top_of_mind_work_v1($pdo,$owner,$officeAgent,$officeChat,true);
$orbTasks=array_column($orbWork['specialists'],null,'id')['tasks'];
canvas_pipeline_check($orbTasks['run_id']===$listChild['id']&&$orbTasks['state']==='queued'
    &&$orbTasks['detail']==='Agent Task queued · Prepare remaining launch questions',
    'Top of Mind shows the native Office Agent Task and its current queue state');
$updateBody->execute(['OFFICE_ENTRY_LIST_CHANGED_FIXTURE',$listMember]);
$orbWork=vp3_top_of_mind_work_v1($pdo,$owner,$officeAgent,$officeChat,true);
canvas_pipeline_check((array_column($orbWork['specialists'],null,'id')['tasks']['run_id']??null)!==$listChild['id'],
    'Top of Mind drops the previous Agent Task after its list source changes');
$business=office_entry_fixture_business_state();
$GLOBALS['pipelineModel']=static fn()=>throw new LogicException('A child with a stale source cannot call the model.');
$inference=count($GLOBALS['pipelineInference']);
$badSource=office_entry_fixture_execute($owner,$listChild);
canvas_pipeline_check(empty($badSource['ok'])&&count($GLOBALS['pipelineInference'])===$inference
    &&office_entry_fixture_business_state()===$business,
    'changing a list member stops its linked Agent task before inference or source changes');
$pdo->prepare('DELETE FROM agent_task_lists_v1 WHERE id=?')->execute([$listId]);
$pdo->exec('UPDATE agent_memory_items SET is_active=0 WHERE id IN (64005,64006)');
$now=new DateTimeImmutable('now',new DateTimeZone('UTC'));
$next=$now->modify('+2 hours');
$pdo->prepare("INSERT INTO agent_reminders_v1(id,owner_user_id,title,timezone,start_local,recurrence,delivery,next_at,conversation_id,created_at,updated_at)
    VALUES(?,1,'Check the renewal confirmation','UTC',?,'none','chat',?,?,?,?)")
    ->execute([$reminderId,$next->format('Y-m-d\TH:i:s'),reminder_utc_v1($next),$officeChat,reminder_utc_v1($now),reminder_utc_v1($now)]);
$reminder=office_entry_fixture_wait_for_run($reminderRef,1);
$orbWork=vp3_top_of_mind_v1($pdo,$owner,$officeAgent,$officeChat,true);
$orbReminder=array_column($orbWork['items'],null,'run_id')[$reminder['id']]??[];
canvas_pipeline_check(($orbReminder['specialist_id']??null)==='tasks'&&($orbReminder['state']??null)==='queued',
    'the native reminder review is visible in Tasks and Lists inside Top of Mind');
$GLOBALS['pipelineModel']=static function(string $prompt,string $protocol):string{
    $input=json_decode($prompt,true,32,JSON_THROW_ON_ERROR);
    canvas_pipeline_check($protocol===VP3_INITIATIVE_OFFICE_REPORT_PROTOCOL_V1&&$input['response_kind']==='office_entry_review'
        &&str_contains($prompt,'Check the renewal confirmation'),'a scheduled reminder review receives the exact saved schedule');
    return '{"office_disposition":"review","draft":"The renewal reminder is set for later today.","action_plan":[],"owner_attention":false}';
};
$business=office_entry_fixture_business_state();
canvas_pipeline_check(!empty(office_entry_fixture_execute($owner,$reminder)['ok'])&&office_entry_fixture_business_state()===$business
    &&canvas_pipeline_count('chat_messages')===$messages+1&&office_entry_fixture_runs($reminderRef,true)===[],
    'reviewing an active reminder neither delivers it nor invents a child assignment');

$recoveryId=65001;
$recoveryRef='task:'.$recoveryId;
$seed->execute([$recoveryId,$officeAgent,'Confirm the venue booking','Check whether the venue has sent the booking confirmation.',
    'office-entry-'.$recoveryId,json_encode(['task_status'=>'open','source_kind'=>'explicit_user'],JSON_THROW_ON_ERROR)]);
$recovery=office_entry_fixture_wait_for_run($recoveryRef,1);
$runId=$recovery['id'];
// Old builds recorded only the broad reason. Leave the old action and its receipt intact.
$GLOBALS['pipelineModel']=static fn()=>throw new RuntimeException('model_unavailable',503);
$failed=office_entry_fixture_execute($owner,$recovery);
$oldReceipt=(int)$failed['receipt']['receipt_id'];
canvas_pipeline_check($failed['reason']==='model_unavailable'&&office_entry_fixture_latest($recoveryRef)['status']==='failed',
    'the legacy provider error fails the current source-exact Task review');
$pdo->prepare('UPDATE agent_workflow_actions SET completed_at=? WHERE run_id=?')
    ->execute([gmdate('Y-m-d H:i:s',time()-600),$runId]);
for($attempt=0;$attempt<4;$attempt++)office_entry_fixture_due();
canvas_pipeline_check(office_entry_fixture_latest($recoveryRef)['status']==='failed',
    'missing meter proof never reopens a legacy failure');
$ledger=$pdo->prepare("INSERT INTO ai_execution_ledger(user_id,agent_id,conversation_id,run_id,service_key,service_stage,
    service_attempt_key,service_job_key,service_status,is_background,total_tokens,cloud_tokens_charged,cost_rate_source)
    VALUES(1,?,?,?,'initiative','vp3.initiative-draft.v1',?,?,?,1,0,0,?)");
$jobKey=hash('sha256','run:'.$runId);
$rejectedKey=hash('sha256','office-rejected');
$unknownKey=hash('sha256','office-unknown');
$ledger->execute([$officeAgent,$officeChat,$runId,$rejectedKey,$jobKey,'rejected','provider_rejected']);
$ledger->execute([$officeAgent,$officeChat,$runId,$unknownKey,$jobKey,'unknown','unknown_usage']);
for($attempt=0;$attempt<4;$attempt++)office_entry_fixture_due();
canvas_pipeline_check(office_entry_fixture_latest($recoveryRef)['status']==='failed',
    'one rejected request does not clear a second provider attempt with unknown usage');
$pdo->prepare("DELETE FROM ai_execution_ledger WHERE service_attempt_key=?")->execute([$unknownKey]);
for($attempt=0;$attempt<4&&office_entry_fixture_latest($recoveryRef)['status']!=='approved';$attempt++)office_entry_fixture_due();
canvas_pipeline_check(office_entry_fixture_latest($recoveryRef)['status']==='approved'
    &&count(office_entry_fixture_runs($recoveryRef))===1
    &&canvas_pipeline_count('agent_workflow_receipts',"id=$oldReceipt AND status='failed' AND attempt_no=1")===1,
    'a normal sweep reopens the original run only after positive no-charge proof, preserving the first receipt');

$GLOBALS['pipelineModel']=static fn()=>'{"draft":"The venue needs confirmation.","title":"OFFICE_EXTRA_FIELD_FIXTURE"}';
$badReview=office_entry_fixture_execute($owner,$recovery);
$run=agent_workflow_row_v1400($pdo,1,$runId);
$diagnostic=vp3_initiative_runtime_failure_v1($pdo,1,$run,true);
canvas_pipeline_check($badReview['reason']==='model_unavailable'&&$run['status']==='approved'
    &&($diagnostic['model_diagnostic']??null)==='invalid_response'
    &&($diagnostic['office_report_error']??null)==='unexpected_fields'
    &&($diagnostic['reason']??null)==='The model added fields outside the report format.'
    &&!str_contains(json_encode($diagnostic,JSON_THROW_ON_ERROR),'OFFICE_EXTRA_FIELD_FIXTURE')
    &&($diagnostic['attempt']??null)===2
    &&agent_job_claim_run_v1900($pdo,$owner,$runId,'cloud',agent_worker_cloud_worker_id_v1910(1),180)===null,
    'malformed model output schedules a bounded same-run retry and Worker Details records the concrete cause');
$pdo->prepare("UPDATE agent_workflow_runs SET next_attempt_at='2000-01-01 00:00:00' WHERE id=?")->execute([$runId]);
$pdo->prepare("UPDATE agent_workflow_actions SET available_at='2000-01-01 00:00:00' WHERE run_id=?")->execute([$runId]);
$business=office_entry_fixture_business_state();
$beforeRetryMessages=canvas_pipeline_count('chat_messages');
$GLOBALS['pipelineModel']=static function(string $prompt,string $protocol):string{
    $request=json_decode($prompt,true,32,JSON_THROW_ON_ERROR);
    canvas_pipeline_check($protocol===VP3_INITIATIVE_OFFICE_REPORT_PROTOCOL_V1&&isset($request['format_repair'])
        &&!str_contains($prompt,'OFFICE_EXTRA_FIELD_FIXTURE'),
        'the retry applies a tighter Office format without sending rejected model text back to the provider');
    return '{"office_disposition":"review","draft":"The venue booking still needs confirmation.","owner_attention":false}';
};
canvas_pipeline_check(!empty(office_entry_fixture_execute($owner,$recovery)['ok'])
    &&office_entry_fixture_business_state()===$business&&canvas_pipeline_count('chat_messages')===$beforeRetryMessages,
    'the next due attempt produces the real private report without changing the task or interrupting Chat');
$recoveredCard=office_entry_fixture_card($owner,$officeAgent,$officeChat,'tasks',$recoveryRef);
office_entry_fixture_due();
canvas_pipeline_check(($recoveredCard['office_report']['summary']??null)==='The venue booking still needs confirmation.'
    &&count(office_entry_fixture_runs($recoveryRef))===1
    &&canvas_pipeline_count('agent_workflow_receipts',"run_id=$runId AND status='completed' AND attempt_no=3")===1,
    'the Tasks specialist shows the recovered report and the next sweep keeps a single run');

$legacyId=66001;
$legacyRef='task:'.$legacyId;
$seed->execute([$legacyId,$officeAgent,'Check the launch checklist','The checklist still needs a delivery owner.',
    'office-entry-'.$legacyId,json_encode(['task_status'=>'open','source_kind'=>'explicit_user'],JSON_THROW_ON_ERROR)]);
$legacy=office_entry_fixture_wait_for_run($legacyRef,1);
$legacyRun=$legacy['id'];
$legacyGoal=$legacy['goal'];
unset($legacyGoal['office_review_version']);
$pdo->prepare('UPDATE agent_workflow_runs SET goal=? WHERE id=?')->execute([json_encode($legacyGoal,JSON_THROW_ON_ERROR),$legacyRun]);
$legacyDiagnostic=['failure_phase'=>'tool','failure_cause'=>'unconfirmed','model_diagnostic'=>'invalid_response'];
for($attempt=1;$attempt<=3;$attempt++){
    $pdo->prepare("UPDATE agent_workflow_runs SET next_attempt_at='2000-01-01 00:00:00' WHERE id=?")->execute([$legacyRun]);
    $pdo->prepare("UPDATE agent_workflow_actions SET available_at='2000-01-01 00:00:00' WHERE run_id=?")->execute([$legacyRun]);
    agent_worker_cloud_result_v1910($pdo,$owner,canvas_pipeline_claim($legacyRun),false,'Legacy Task review response rejected.',
        $legacyDiagnostic,'model_unavailable',true);
}
$oldTime=gmdate('Y-m-d H:i:s',time()-600);
$pdo->prepare('UPDATE agent_workflow_actions SET completed_at=? WHERE run_id=?')->execute([$oldTime,$legacyRun]);
$legacyLedger=$pdo->prepare("INSERT INTO ai_execution_ledger(user_id,agent_id,conversation_id,run_id,service_key,service_stage,
    service_attempt_key,service_job_key,service_status,is_background,usage_known,credential_source,output_tokens,
    total_tokens,cloud_tokens_charged,cost_rate_source,created_at)
    VALUES(1,?,?,?,'initiative','vp3.initiative-draft.v1',?,?,?,1,?,'own_key',12,50,0,?,?)");
$legacyJobKey=hash('sha256','run:'.$legacyRun);
$legacyKnownKey=hash('sha256','legacy-office-settled');
$legacyUnknownKey=hash('sha256','legacy-office-unknown');
$legacyLedger->execute([$officeAgent,$officeChat,$legacyRun,$legacyKnownKey,$legacyJobKey,'completed',1,'configured',$oldTime]);
$legacyLedger->execute([$officeAgent,$officeChat,$legacyRun,$legacyUnknownKey,$legacyJobKey,'unknown',0,'unknown_usage',$oldTime]);
for($attempt=0;$attempt<4;$attempt++)office_entry_fixture_due();
canvas_pipeline_check(office_entry_fixture_latest($legacyRef)['status']==='failed',
    'one settled provider response does not permit reanalysis while a previous attempt has unknown usage');
$pdo->prepare("DELETE FROM ai_execution_ledger WHERE service_attempt_key=?")->execute([$legacyUnknownKey]);
$pdo->prepare("UPDATE ai_execution_ledger SET service_stage=? WHERE service_attempt_key=?")
    ->execute([VP3_INITIATIVE_OFFICE_REPORT_PROTOCOL_V1,$legacyKnownKey]);
for($attempt=0;$attempt<4;$attempt++)office_entry_fixture_due();
canvas_pipeline_check(office_entry_fixture_latest($legacyRef)['status']==='failed',
    'a failed new-format review never qualifies for the legacy repair');
$pdo->prepare("UPDATE ai_execution_ledger SET service_stage='vp3.initiative-draft.v1' WHERE service_attempt_key=?")
    ->execute([$legacyKnownKey]);
for($attempt=0;$attempt<4&&office_entry_fixture_latest($legacyRef)['status']!=='approved';$attempt++)office_entry_fixture_due();
$legacyRow=agent_workflow_row_v1400($pdo,1,$legacyRun);
canvas_pipeline_check($legacyRow['status']==='approved'&&(int)$legacyRow['attempt_count']===3&&(int)$legacyRow['max_attempts']===4
    &&count(office_entry_fixture_runs($legacyRef))===1&&canvas_pipeline_count('agent_workflow_receipts',"run_id=$legacyRun")===3,
    'the same source and proven legacy response admit one more attempt without replacing old receipts');
$GLOBALS['pipelineModel']=static function(string $prompt,string $protocol):string{
    canvas_pipeline_check($protocol===VP3_INITIATIVE_OFFICE_REPORT_PROTOCOL_V1&&str_contains($prompt,'delivery owner'),
        'the repaired legacy run uses the updated report contract and exact authorized task');
    return '{"office_disposition":"review","draft":"The launch checklist needs a delivery owner.","owner_attention":false}';
};
$legacySuccess=office_entry_fixture_execute($owner,$legacy);
office_entry_fixture_due();
$legacyCard=office_entry_fixture_card($owner,$officeAgent,$officeChat,'tasks',$legacyRef);
canvas_pipeline_check($legacySuccess['ok']&&($legacyCard['office_report']['summary']??null)==='The launch checklist needs a delivery owner.'
    &&count(office_entry_fixture_runs($legacyRef))===1
    &&canvas_pipeline_count('agent_workflow_receipts',"run_id=$legacyRun AND status='completed' AND attempt_no=4")===1
    &&canvas_pipeline_count('agent_workflow_events',"run_id=$legacyRun AND event_type='office_report_reanalyzed'")===1,
    'a repaired report appears on the original private Task card and later scans cannot requeue it');

$noiseRef='task:67001';
$noteRef='task:67002';
$noiseText='NOISE_FIXTURE they dont need to go uh and the other stuff';
$quietMetadata=json_encode(['task_status'=>'open','source_kind'=>'transcript','due_at'=>gmdate('Y-m-d',time()-86400)],JSON_THROW_ON_ERROR);
$seed->execute([67001,$officeAgent,'Incomplete recording fragment',$noiseText,'office-entry-67001',$quietMetadata]);
$noise=office_entry_fixture_wait_for_run($noiseRef,1);
$quietBusiness=office_entry_fixture_business_state();
$quietChat=canvas_pipeline_count('chat_messages');
$GLOBALS['pipelineModel']=static function(string $prompt,string $protocol)use($noiseText):string{
    $contract=vp3_tool_json_contract_v1($protocol);
    canvas_pipeline_check(str_contains($prompt,$noiseText)&&str_contains($contract,'stray conversation')
        &&str_contains($contract,'due date, or open status alone cannot supply a missing purpose'),
        'the entry review evaluates the saved fragment and its context before suggesting work');
    return '{"office_disposition":"ignore","draft":"Discard this invented suggestion.","followup_question":"An invented question?","action_plan":["Invent work."],"agent_task_title":"Invent a task","owner_attention":true}';
};
canvas_pipeline_check(office_entry_fixture_execute($owner,$noise)['ok'],'an explicit ignore completes as a private verified decision');
$noiseResult=office_entry_fixture_result($noise['id'])['initiative_tool_result'];
$ignoredCard=office_entry_fixture_card($owner,$officeAgent,$officeChat,'tasks',$noiseRef);
$binding=vp3_initiative_policy_v1($owner,$officeAgent);
$ignoredSource=vp3_coordination_sources_v1($pdo,$owner,$binding,['office:'.$noiseRef]);
canvas_pipeline_check(is_array($ignoredCard)&&!isset($ignoredCard['office_report'])
    &&office_entry_fixture_card($owner,$officeAgent,$officeChat,'reviews','office-review:'.$noise['id'])===null
    &&($noiseResult['output']['office_disposition']??null)==='ignore'
    &&!str_contains(json_encode($noiseResult,JSON_THROW_ON_ERROR),'Invent')
    &&!isset($ignoredSource['items']['office:'.$noiseRef])
    &&!isset(array_column(vp3_top_of_mind_v1($pdo,$owner,$officeAgent,$officeChat,true)['items'],null,'run_id')[$noise['id']])
    &&office_entry_fixture_runs($noiseRef,true)===[],
    'an ignored fragment retains its inventory entry and never supplies a report, Agent Task, or Top of Mind candidate');
for($attempt=0;$attempt<3;$attempt++)office_entry_fixture_due();
canvas_pipeline_check(count(office_entry_fixture_runs($noiseRef))===1
    &&$quietBusiness===office_entry_fixture_business_state()&&$quietChat===canvas_pipeline_count('chat_messages'),
    'a subsequent sweep does not reprocess a quiet decision or change any owner record');

$seed->execute([67002,$officeAgent,'Keep the delivery window on file','Vendor delivery window is 8 to 10 AM for reference.',
    'office-entry-67002',$quietMetadata]);
$note=office_entry_fixture_wait_for_run($noteRef,1);
$GLOBALS['pipelineModel']=static fn()=>'{"office_disposition":"note","draft":"The vendor window is saved for reference.","next_step":"Unnecessary work.","followup_question":"Unnecessary question?","action_plan":["Unnecessary work."],"agent_task_title":"Unnecessary checklist","owner_attention":true}';
canvas_pipeline_check(office_entry_fixture_execute($owner,$note)['ok'],'a useful observation can finish as a quiet note');
$noteCard=office_entry_fixture_card($owner,$officeAgent,$officeChat,'tasks',$noteRef)['office_report']??[];
canvas_pipeline_check(($noteCard['summary']??null)==='The vendor window is saved for reference.'
    &&array_intersect_key($noteCard,array_fill_keys(['next_step','followup_question','action_plan','agent_task','owner_attention'],true))===[]
    &&office_entry_fixture_runs($noteRef,true)===[]
    &&!isset(vp3_coordination_sources_v1($pdo,$owner,$binding,['office:'.$noteRef])['items']['office:'.$noteRef])
    &&!isset(array_column(vp3_top_of_mind_v1($pdo,$owner,$officeAgent,$officeChat,true)['items'],null,'run_id')[$note['id']]),
    'the quiet note appears on the saved task without action fields or any Top of Mind handoff');

$oldNoiseReceipt=office_entry_fixture_result($noise['id']);
$oldNoiseReceipt['initiative_draft']=$oldNoiseReceipt['initiative_tool_result']['draft']='Legacy noise analysis.';
unset($oldNoiseReceipt['initiative_tool_result']['output']['office_disposition']);
$oldNoiseReceipt['initiative_tool_result']['output']['owner_attention']=true;
$encoded=json_encode($oldNoiseReceipt,JSON_THROW_ON_ERROR);
$oldNoiseGoal=$noise['goal'];
unset($oldNoiseGoal['office_review_version']);
$pdo->prepare('UPDATE agent_workflow_runs SET goal=? WHERE id=?')->execute([json_encode($oldNoiseGoal,JSON_THROW_ON_ERROR),$noise['id']]);
$pdo->prepare('UPDATE agent_workflow_actions SET result_json=? WHERE run_id=?')->execute([$encoded,$noise['id']]);
$pdo->prepare('UPDATE agent_workflow_receipts SET result_json=? WHERE run_id=?')->execute([$encoded,$noise['id']]);
$replacement=office_entry_fixture_wait_for_run($noiseRef,2);
canvas_pipeline_check($replacement['id']!==$noise['id']&&($replacement['goal']['office_reassessment']??null)===true
    &&($replacement['goal']['office_review_version']??null)===VP3_INITIATIVE_OFFICE_REVIEW_VERSION_V1,
    'the regular sweep queues one classified reassessment of a verified legacy report');
$GLOBALS['pipelineModel']=static fn()=>'{"office_disposition":"ignore"}';
canvas_pipeline_check(office_entry_fixture_execute($owner,$replacement)['ok'],'the legacy fragment is classified without requiring a draft');
for($attempt=0;$attempt<3;$attempt++)office_entry_fixture_due();
$legacyOutput=$pdo->prepare('SELECT result_json FROM agent_workflow_receipts WHERE run_id=?');
$legacyOutput->execute([$noise['id']]);
canvas_pipeline_check(count(office_entry_fixture_runs($noiseRef))===2&&$legacyOutput->fetchColumn()===$encoded
    &&office_entry_fixture_card($owner,$officeAgent,$officeChat,'reviews','office-review:'.$noise['id'])===null
    &&!isset(office_entry_fixture_card($owner,$officeAgent,$officeChat,'tasks',$noiseRef)['office_report'])
    &&!isset(vp3_coordination_sources_v1($pdo,$owner,$binding,['office:'.$noiseRef])['items']['office:'.$noiseRef]),
    'the new decision supersedes older noisy analysis without rewriting history or repeatedly reviewing the fragment');

$productRef='task:67003';
$seed->execute([67003,$officeAgent,'ESP32','ESP32','office-entry-67003',
    json_encode(['task_status'=>'open','source_kind'=>'explicit_user'],JSON_THROW_ON_ERROR)]);
$contextList->execute([67004,1,$officeAgent,'Electronics shopping list',vp3_task_list_key_v1('Electronics shopping list'),$contextAt]);
$contextMember->execute([67004,67003,'office-entry-product-context',$contextAt]);
// Keep the inventory scan on the source being exercised, before it queues a whole-list review.
$pdo->exec("DELETE FROM agent_memory_items WHERE user_id=1 AND user_agent_id=7
    AND memory_type='initiative_sweep' AND subject='office-entry-scan'");
$product=office_entry_fixture_wait_for_run($productRef,1);
$GLOBALS['pipelineModel']=static function(string $prompt,string $protocol):string{
    $input=json_decode($prompt,true,32,JSON_THROW_ON_ERROR);
    $record=json_decode($input['tool_result']['records'][0]['text'],true,32,JSON_THROW_ON_ERROR);
    canvas_pipeline_check($protocol===VP3_INITIATIVE_OFFICE_REPORT_PROTOCOL_V1&&$record['title']==='ESP32'
        &&($record['parent_lists']??null)===['Electronics shopping list'],
        'a short product entry receives real list context through the worker');
    return '{"office_disposition":"review","draft":"ESP32 is an open item on the electronics shopping list.","next_step":"Check which board fits the project.","followup_question":"Do you need a particular ESP32 board?","owner_attention":true}';
};
canvas_pipeline_check(office_entry_fixture_execute($owner,$product)['ok'],'a short contextual entry can earn a full review');
$productReport=office_entry_fixture_card($owner,$officeAgent,$officeChat,'tasks',$productRef)['office_report']??[];
$productSource=vp3_coordination_sources_v1($pdo,$owner,$binding,['office:'.$productRef])['items']['office:'.$productRef]??[];
canvas_pipeline_check(($productReport['followup_question']??null)==='Do you need a particular ESP32 board?'
    &&($productSource['question']??null)===$productReport['followup_question']
    &&($productSource['next_step']??null)===$productReport['next_step'],
    'a meaningful contextual question reaches the same shared handoff used by other specialists');

// Admit an existing Ask while a second saved task still needs its own first
// review. A busy Chat still accepts timestamped text from both independent paths.
// Earlier cases deliberately leave unrelated queued handoffs. Retire that
// fixture work so this scenario starts with only the exact product question.
$pdo->exec("UPDATE agent_workflow_runs SET status='cancelled' WHERE owner_user_id=1 AND agent_id=7
    AND source_kind='agent_initiative' AND source_key LIKE 'coordination:%'
    AND status IN ('queued','approved','executing','paused')");
office_entry_fixture_presence($officeChat,'busy');
$pdo->exec("DELETE FROM agent_memory_items WHERE user_id=1 AND user_agent_id=7
    AND memory_type='initiative_sweep' AND subject IN ('coordination-scan','office-entry-scan')");
$scanOutcome=null;
vp3_initiative_schedule_coordination_v1($pdo,$owner,$binding,false,$scanOutcome);
$productAsks=array_values(array_filter(vp3_coordination_runs_v1($pdo,$owner,$binding),static function(array $run)use($productRef):bool{
    $goal=json_decode((string)$run['goal'],true,32,JSON_THROW_ON_ERROR);
    return $run['status']==='approved'&&($goal['primary']??null)==='office:'.$productRef
        &&($goal['coordination_saved']??null)===true;
}));
canvas_pipeline_check($scanOutcome==='queued_chat_handoff'&&count($productAsks)===1,
    'the fixture has the exact saved product question ready for a canonical claim');
$independentRef='task:68001';
$seed->execute([68001,$officeAgent,'Confirm tomorrow’s site visit','Verify that the site contact has tomorrow’s arrival time.',
    'office-entry-68001',json_encode(['task_status'=>'open','source_kind'=>'explicit_user'],JSON_THROW_ON_ERROR)]);
$noModel=static fn():never=>throw new LogicException('A saved handoff and a Task scan must not call the provider.');
$business=office_entry_fixture_business_state();
$messagesBeforeTick=canvas_pipeline_count('chat_messages');
$inferenceBeforeTick=count($GLOBALS['pipelineInference']);
$openChatTick=vp3_initiative_pump_v1($pdo,$owner,$officeAgent,$officeChat,$binding['revision'],$noModel);
$independent=office_entry_fixture_runs($independentRef);
$completedAsk=isset($openChatTick['run_id'])?canvas_pipeline_result($openChatTick['run_id']):[];
canvas_pipeline_check($openChatTick['ok']&&($openChatTick['step']??null)==='preparation'
    &&($openChatTick['run_id']??null)===(int)$productAsks[0]['id']
    &&($completedAsk['initiative_delivery']??null)==='delivered'
    &&canvas_pipeline_count('chat_messages')===$messagesBeforeTick+1
    &&count($GLOBALS['pipelineInference'])===$inferenceBeforeTick
    &&count($independent)===1&&$independent[0]['status']==='approved'
    &&office_entry_fixture_business_state()===$business,
    'one Open Chat tick processes a saved handoff and also queues the independent native Task review without inference');

echo 'INITIATIVE_OFFICE_ENTRY_PIPELINE=PASS ('.($checks-$officeStart).' assertions, '.($mysql?'mysql':'sqlite').")\n";
