<?php
declare(strict_types=1);

// Reuse the validated, disposable named-Agent fixture and its production policy/lease setup.
require __DIR__.'/agent-initiative-scope-v1.php';
require_once dirname(__DIR__).'/includes/agent-brain-owner-context-v1.php';

function pipeline_check(bool $ok,string $label): void {if(!$ok)throw new RuntimeException($label);echo "PASS $label\n";}
function pipeline_reset(array $user): void {
    scope_command($user,17,170,'off');scope_command($user,17,170,'on');
    db()->exec("UPDATE agent_activity_state SET context_key='chat:170',details_json='{\"visible\":true,\"conversation_id\":170}',last_heartbeat_at=NOW() WHERE user_id=1;
        UPDATE chat_messages SET created_at='2000-01-01 00:00:00' WHERE conversation_id=170");
}
function pipeline_execute(array $user,string $tool,string $title,callable $provider): array {
    $GLOBALS['scopeModel']=$provider;
    $plan=scope_make_plan($user,17,$title);
    $row=agent_workflow_row_v1400(db(),1,$plan);$goal=json_decode($row['goal'],true,16,JSON_THROW_ON_ERROR);
    vp3_initiative_in_scope_v1($user,17,static function()use($user,$goal,$tool):void {
        $live=vp3_initiative_access_v1(db(),$user,$goal['binding']);
        $evidence=vp3_initiative_current_sources_v1(db(),$live,$goal['sources'],false,$goal['canonical_sources']??[]);
        $catalog=vp3_initiative_tools_catalog_v1(db(),$live,$goal['binding'],$evidence);
        pipeline_check(!empty($catalog[$tool]['ready']),'canonical scoped readiness admits '.$tool.' before planning');
    });
    $planned=vp3_initiative_execute_v1(db(),$user,scope_claim($user,$plan));
    pipeline_check($planned['ok'],'actual governed named plan selects '.$tool);
    $prepare=array_slice(scope_runs(1,17,'initiative.prepare'),-1)[0];
    $payload=json_decode($prepare['goal'],true,16,JSON_THROW_ON_ERROR);
    pipeline_check($payload['tool']===$tool&&$payload['binding']['agent_id']===17&&$payload['binding']['conversation_id']===170,
        'planner choice is preserved in the canonical named preparation payload');
    if($tool==='brain.read')vp3_initiative_in_scope_v1($user,17,static function()use($user,$payload):void {
        $scope=vp3_initiative_tools_scope_v1(db(),$user,$payload['binding']);
        $records=vp3_initiative_tools_records_v1(db(),$scope,$payload['binding'],'brain.read',$payload['focus']);
        pipeline_check(count($records)===1&&$records[0]['source']==='brain:1002','the connected production read resolves its exact authorized task memory');
    });
    $completed=vp3_initiative_execute_v1(db(),$user,scope_claim($user,(int)$prepare['id']));
    pipeline_check($completed['ok'],'actual canonical preparation dispatch completes '.$tool);
    $q=db()->prepare('SELECT result_json FROM agent_workflow_actions WHERE run_id=?');$q->execute([$prepare['id']]);$action=(string)$q->fetchColumn();
    $q=db()->prepare('SELECT result_json FROM agent_workflow_receipts WHERE run_id=?');$q->execute([$prepare['id']]);$receipt=(string)$q->fetchColumn();
    pipeline_check($action===$receipt,'actual tool result is identical in canonical action and execution receipt');
    $result=json_decode($action,true,24,JSON_THROW_ON_ERROR);$toolResult=$result['initiative_tool_result']??[];
    $q=db()->prepare('SELECT message,context_json FROM chat_messages WHERE id=? AND conversation_id=170');
    $q->execute([$result['initiative_message_id']??0]);$message=$q->fetch();
    pipeline_check($result['initiative_delivery']==='delivered'&&$message&&$message['message']===$toolResult['draft'],
        'the verified useful result is saved once in the exact ordinary named Chat');
    return ['run_id'=>(int)$prepare['id'],'payload'=>$payload,'result'=>$result,'tool'=>$toolResult,
        'context'=>json_decode($message['context_json'],true,24,JSON_THROW_ON_ERROR)];
}

$user=['id'=>1,'is_active'=>1];
pipeline_reset($user);
$readCalls=0;
$brainProvider=static function(string $prompt,string $protocol)use(&$readCalls):string {
    $input=json_decode($prompt,true,24,JSON_THROW_ON_ERROR);
    if($protocol==='vp3.initiative-plan.v1'){
        pipeline_check(!empty($input['tools']['brain.read']['ready']),'the actual model plan sees current ready Brain capability');
        return json_encode(['jobs'=>[['tool'=>'brain.read','primary'=>'task:1002','refs'=>['task:1002'],
            'focus'=>'Only Agent A task','report'=>true]]],JSON_THROW_ON_ERROR);
    }
    ++$readCalls;$tool=$input['tool_result'];
    pipeline_check($protocol==='vp3.initiative-draft.v1'&&$tool['tool']==='brain.read'&&count($tool['records'])===1
        &&$tool['records'][0]['source']==='brain:1002'&&$tool['records'][0]['title']==='Only Agent A task',
        'report inference receives the actual authorized Brain record, not merely the original priority title');
    return json_encode(['draft'=>'I checked your saved task. '.str_repeat('The verified Agent A task still needs attention, and this context can guide the next step. ',8)],JSON_THROW_ON_ERROR);
};
$brain=pipeline_execute($user,'brain.read','Connect the task to saved working memory',$brainProvider);
pipeline_check($readCalls===1&&$brain['tool']['tool_receipt']['capability']==='brain.read'
    &&array_keys($brain['tool']['output']['source_revisions'])===['brain:1002']
    &&$brain['context']['tool_receipt']===$brain['tool']['tool_receipt']&&mb_strlen($brain['tool']['draft'])>490,
    'the complete core path retains substantive text, actual read receipt and exact retrieved source revision in Chat');
$messages=count(scope_messages());
pipeline_check(!vp3_initiative_deliver_pending_v1(db(),$user,17)&&count(scope_messages())===$messages,
    'replaying the canonical delivery sweep does not duplicate an already delivered real tool result');
vp3_initiative_in_scope_v1($user,17,static function()use($user,$brain):void {
    agent_job_brain_memory_v1900($user,agent_workflow_row_v1400(db(),1,$brain['run_id']),'completed');
    vp3_initiative_tools_result_current_v1(db(),$user,$brain['payload']['binding'],$brain['payload'],$brain['tool']);
    $ordinary=agent_brain_context($user,'Agent',30,true);
    pipeline_check((bool)array_filter($ordinary,static fn(array $row):bool=>$row['source']==='agent-memory:job_execution'),
        'ordinary explicit Brain recall still includes authorized workflow history by default');
    $filtered=agent_brain_context($user,'unmatched-unique-query',30,false,
        ['job_execution','initiative_policy','initiative_sweep','cognitive_state']);
    pipeline_check(array_column($filtered,'memory_id')===[1002],
        'background recall excludes its own bookkeeping before strict and fallback limits, so completing its job creates no new source evidence');
    $invalid=false;
    try{agent_brain_context($user,'Agent',6,true,['job_execution) OR 1=1']);}catch(InvalidArgumentException $error){$invalid=true;}
    pipeline_check($invalid,'the canonical reader accepts only known exclusion types and cannot interpolate a filter as SQL');
});

// Create only the existing canonical Mission schema; no alternate queue or executor.
require_once dirname(__DIR__).'/includes/cloud-missions-v1.php';
cloud_mission_schema_v1(db());
pipeline_reset($user);
$missionProvider=static function(string $prompt,string $protocol):string {
    $input=json_decode($prompt,true,24,JSON_THROW_ON_ERROR);
    if($protocol==='vp3.initiative-plan.v1'){
        pipeline_check(!empty($input['tools']['missions.prepare']['ready'])&&$input['tools']['missions.prepare']['mode']==='review',
            'the actual planner sees a governed specialist preparation rather than automatic research permission');
        return json_encode(['jobs'=>[['tool'=>'missions.prepare','primary'=>'task:1002','refs'=>['task:1002'],
            'focus'=>'Research a useful next step for the saved task','report'=>true]]],JSON_THROW_ON_ERROR);
    }
    pipeline_check($input['tool_result']['tool']==='missions.prepare'&&$input['tool_result']['status']==='approval_pending',
        'natural result wording is constrained to a prepared review, never completed research');
    return json_encode(['draft'=>'I prepared a specialist Mission for your review. Its assignments and read tools still need your approval before research starts.'],JSON_THROW_ON_ERROR);
};
$mission=pipeline_execute($user,'missions.prepare','Advance the saved task with a specialist review',$missionProvider);
$output=$mission['tool']['output'];$saved=cloud_mission_row_v1(db(),$user,$output['mission_id']);$run=cloud_mission_run_v1(db(),$user,$saved);
$config=json_decode($saved['config_json'],true,16,JSON_THROW_ON_ERROR);
pipeline_check($output['status']==='approval_pending'&&$run['status']==='approval_pending'
    &&(int)$saved['agent_id']===17&&$config['thread_id']===170&&$run['id']===$output['job_id']
    &&array_column($config['assignments'],'tools')===[[],[]]&&$mission['context']['initiative_output']===$output,
    'real specialist proposal, exact Chat artifact metadata, no implicit grants and pending approval survive the entire production path');
pipeline_check($GLOBALS['VP3_AGENT_MEMORY_SCOPE_V410']===$incoming,'read and specialist execution both restore the caller memory scope');

// The anchor remains current while a separately retrieved record is revoked during owner absence.
pipeline_reset($user);
db()->exec("UPDATE agent_activity_state SET details_json='{\"visible\":false,\"conversation_id\":170}',last_heartbeat_at=NOW() WHERE user_id=1");
$GLOBALS['scopeModel']=static function(string $prompt,string $protocol):string {
    if($protocol==='vp3.initiative-plan.v1')return json_encode(['jobs'=>[['tool'=>'brain.read','primary'=>'calendar:event:1',
        'refs'=>['calendar:event:1'],'focus'=>'Only Agent A task','report'=>true]]],JSON_THROW_ON_ERROR);
    $input=json_decode($prompt,true,24,JSON_THROW_ON_ERROR);
    pipeline_check($input['tool_result']['records'][0]['source']==='brain:1002','delayed tool read retrieves a record independent of the calendar anchor');
    return json_encode(['draft'=>'I reviewed your saved task against the current calendar situation. The private follow-up is prepared from that authorized task evidence.'],JSON_THROW_ON_ERROR);
};
$pendingPlan=scope_make_plan($user,17,'Calendar situation with a saved task follow-up');
pipeline_check(vp3_initiative_execute_v1(db(),$user,scope_claim($user,$pendingPlan))['ok'],'a delayed named tool assessment uses the real planner');
$pendingPrepare=array_slice(scope_runs(1,17,'initiative.prepare'),-1)[0];
$beforeMessages=count(scope_messages());
pipeline_check(vp3_initiative_execute_v1(db(),$user,scope_claim($user,(int)$pendingPrepare['id']))['ok'],
    'real private record tool completes while its owner is absent');
$q=db()->prepare('SELECT result_json FROM agent_workflow_actions WHERE run_id=?');$q->execute([$pendingPrepare['id']]);
$pendingResult=json_decode($q->fetchColumn(),true,24,JSON_THROW_ON_ERROR);
pipeline_check($pendingResult['initiative_delivery']==='pending'&&count(scope_messages())===$beforeMessages,
    'the actual tool result remains private until its owner returns');
$beforeInference=count($GLOBALS['scopeInference']);
$beforeStatus=vp3_initiative_foreground_status_v1(db(),$user,17,170);
pipeline_check($beforeStatus['state']==='ready','read-only foreground status can verify the still-current saved tool result');
db()->exec('UPDATE agent_memory_items SET is_active=0 WHERE id=1002');
$afterStatus=vp3_initiative_foreground_status_v1(db(),$user,17,170);
pipeline_check($afterStatus['state']!=='ready'&&count($GLOBALS['scopeInference'])===$beforeInference,
    'GET-status projection withdraws a revoked retrieved Brain record without another model call');
db()->exec("UPDATE agent_activity_state SET details_json='{\"visible\":true,\"conversation_id\":170}',last_heartbeat_at=NOW() WHERE user_id=1");
pipeline_check(!vp3_initiative_deliver_pending_v1(db(),$user,17)&&count(scope_messages())===$beforeMessages,
    'owner return cannot expose a saved private report whose retrieved record was revoked');
$q->execute([$pendingPrepare['id']]);$withheld=json_decode($q->fetchColumn(),true,24,JSON_THROW_ON_ERROR);
$receiptQuery=db()->prepare('SELECT result_json FROM agent_workflow_receipts WHERE run_id=?');$receiptQuery->execute([$pendingPrepare['id']]);
pipeline_check($withheld['initiative_delivery']==='withheld'&&json_decode($receiptQuery->fetchColumn(),true,24,JSON_THROW_ON_ERROR)===$withheld
    &&count($GLOBALS['scopeInference'])===$beforeInference,'withholding is atomic in the actual action and receipt, with no inference replay');
db()->exec('UPDATE agent_memory_items SET is_active=1 WHERE id=1002');
echo "AGENT_INITIATIVE_TOOLS_PIPELINE_V1=PASS\n";
