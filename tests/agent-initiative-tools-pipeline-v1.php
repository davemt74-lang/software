<?php
declare(strict_types=1);

// Reuse the validated, disposable named-Agent fixture and its production policy/lease setup.
require __DIR__.'/agent-initiative-scope-v1.php';
require_once dirname(__DIR__).'/includes/agent-brain-owner-context-v1.php';
require_once dirname(__DIR__).'/includes/agent-initiative-research-canvas-v1.php';

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

// Exercise the saved check-in, canonical public research worker and delayed delivery.
pipeline_reset($user);
$pdo->exec('DELETE FROM chat_messages WHERE conversation_id=170');
$pdo->exec('CREATE TABLE IF NOT EXISTS settings(setting_key TEXT PRIMARY KEY,setting_value TEXT)');
agent_connection_youtube_consent_schema_v1($pdo);
agent_connection_youtube_results_schema_v1($pdo);
agent_connection_youtube_set_consent_v1($pdo,1,true);
vp3_youtube_research_save_v1($pdo,1,'topic',true);
$topic='wood-fired oven heat management';
$key=hash('sha256',$topic);
$weakRef='youtube-interest-checkin:topic:'.$key;
$researchRef='topic-interest:'.$key;
$turn=$pdo->prepare('INSERT INTO chat_messages(conversation_id,user_id,role,message,created_at) VALUES(?,?,?,?,?)');
$searchedAt=gmdate('Y-m-d H:i:s',time()-180);
foreach([170,171] as $cid){
    $turn->execute([$cid,1,'user','Find a video about '.$topic.'.',$searchedAt]);
    $turn->execute([$cid,null,'assistant','YouTube results',$searchedAt]);
    agent_connection_youtube_results_save_v1($pdo,1,$cid,(int)$pdo->lastInsertId(),[
        'query'=>$topic,'expires_at'=>time()+3600,
        'videos'=>[['title'=>'Example oven tutorial','video_id'=>'abc_def-123','made_for_kids'=>false]],
    ]);
}
$checkIn=static function(string $prompt,string $protocol)use($weakRef): string {
    $input=json_decode($prompt,true,24,JSON_THROW_ON_ERROR);
    if($protocol==='vp3.initiative-plan.v1'){
        pipeline_check(in_array($weakRef,array_column($input['evidence'],'ref'),true)&&!isset($input['tools']['topic.research']),
            'two related same-Agent Chat signals admit a check-in before public research');
        return json_encode(['jobs'=>[['tool'=>'chat.check_in','primary'=>$weakRef,'refs'=>[$weakRef],
            'focus'=>'Ask about the purpose of the oven searches','report'=>true]]],JSON_THROW_ON_ERROR);
    }
    pipeline_check($protocol==='vp3.initiative-draft.v1'&&$input['response_kind']==='chat.check_in',
        'the worker uses the normal conversational check-in draft protocol');
    return '{"draft":"Are you looking into oven heat management for something you are cooking soon?"}';
};
$draft=pipeline_execute($user,'chat.check_in','Clarify an emerging YouTube interest',$checkIn);
pipeline_check($draft['payload']['primary']===$weakRef,'the exact check-in reaches ordinary Chat');

$reply='I am dialing in heat recovery for a private Friday rehearsal.';
$turn->execute([170,1,'user',$reply,gmdate('Y-m-d H:i:s')]);
$replyId=(int)$pdo->lastInsertId();
$preparedBefore=count(scope_runs(1,17,'initiative.prepare'));
$decision=vp3_initiative_followthrough_reply_v1($pdo,$user,scope_principal($user,17),170,$replyId,null,$reply,null,
    static function(string $prompt,string $protocol)use($reply):string {
        $input=json_decode($prompt,true,24,JSON_THROW_ON_ERROR);
        pipeline_check($protocol==='vp3.initiative-reply.v1'&&($input['owner_message']['text']??null)===$reply
            &&!empty($input['interest_check_in']), 'a natural adjacent owner reply reaches the exact check-in classifier');
        return '{"intent":"discuss","focus":"Understand heat recovery for Friday","interest_confirmed":true}';
    });
pipeline_check(($decision['initiative_followthrough']['state']??null)==='discussed'
    &&count(scope_runs(1,17,'initiative.prepare'))===$preparedBefore,
    'confirmation updates the conversation without an automatic continuation job');

$ageReview=$pdo->prepare("UPDATE agent_memory_items SET last_seen_at=? WHERE user_id=1 AND user_agent_id=17 AND memory_type='initiative_sweep'");
$ageReview->execute([gmdate('Y-m-d H:i:s',time()-31)]);
$beforePlans=count(scope_runs(1,17,'initiative.plan'));
vp3_initiative_schedule_v1($pdo,$user,17);
vp3_initiative_schedule_v1($pdo,$user,17);
$plans=scope_runs(1,17,'initiative.plan');
pipeline_check(count($plans)===$beforePlans+1,'one saved check-in reply admits one reassessment without another search or viewing signal');
$researchPlan=vp3_initiative_execute_v1($pdo,$user,scope_claim($user,(int)end($plans)['id']),
    static function(string $prompt,string $protocol)use($researchRef):string {
        $input=json_decode($prompt,true,24,JSON_THROW_ON_ERROR);
        pipeline_check($protocol==='vp3.initiative-plan.v1'&&($input['tools']['topic.research']['source_refs']??null)===[$researchRef],
            'reassessment exposes the exact confirmed topic as a current research source');
        return json_encode(['jobs'=>[['tool'=>'topic.research','primary'=>$researchRef,'refs'=>[$researchRef],
            'focus'=>'Compare heat recovery for a private Friday rehearsal','report'=>true]]],JSON_THROW_ON_ERROR);
    });
$researchRuns=scope_runs(1,17,'initiative.prepare');
pipeline_check($researchPlan['ok']&&count($researchRuns)===$preparedBefore+1,'the actual planner queues one scoped topic research job');
$researchGoal=json_decode((string)end($researchRuns)['goal'],true,24,JSON_THROW_ON_ERROR);
pipeline_check($researchGoal['tool']==='topic.research'&&$researchGoal['primary']===$researchRef
    &&array_keys($researchGoal['sources'])===[$researchRef], 'research binds the one authorized topic source in the canonical queue');
$researchRunId=(int)end($researchRuns)['id'];
$publicUrl='https://oven.example/heat-recovery';
$leadUrl='https://baking.example/oven-guide';
$quote='A well insulated oven recovers heat faster after the door closes.';
$hits=['ok'=>true,'results'=>[['url'=>$publicUrl,'title'=>'Oven heat recovery'],['url'=>$leadUrl,'title'=>'Oven temperature guide']]];
$beforeReports=count(scope_messages());
$afterExpiryCalls=0;
$interrupted=vp3_initiative_execute_v1($pdo,$user,scope_claim($user,$researchRunId),
    static function()use(&$afterExpiryCalls):never{++$afterExpiryCalls;throw new LogicException('Unexpected synthesis.');},[
        'search'=>static function(string $query)use($pdo,$topic,$researchRunId,$hits):array{
            pipeline_check($query===$topic,'the web search receives only the public topic from viewing history');
            $pdo->prepare("UPDATE agent_workflow_runs SET lease_expires_at='2000-01-01 00:00:00' WHERE id=?")->execute([$researchRunId]);
            return $hits;
        },
        'request'=>static function()use(&$afterExpiryCalls):never{++$afterExpiryCalls;throw new LogicException('Unexpected page request.');},
    ]);
pipeline_check(($interrupted['reason']??null)==='execution_no_longer_active'&&$afterExpiryCalls===0&&count(scope_messages())===$beforeReports,
    'a lease lost during search stops the next provider step and cannot post results');
pipeline_check(agent_job_recover_expired_v1900($pdo,$user,1,$researchRunId)['recovered']===1,
    'the durable worker recovers the same research job after its lease expires');
$pdo->prepare("UPDATE agent_workflow_runs SET next_attempt_at='2000-01-01 00:00:00' WHERE id=?")->execute([$researchRunId]);
$pdo->prepare("UPDATE agent_workflow_actions SET available_at='2000-01-01 00:00:00' WHERE run_id=?")->execute([$researchRunId]);
$pdo->exec("UPDATE agent_activity_state SET details_json='{\"visible\":false,\"conversation_id\":170}',last_heartbeat_at=NOW() WHERE user_id=1");
$completed=vp3_initiative_execute_v1($pdo,$user,scope_claim($user,$researchRunId),
    static function(string $prompt,string $protocol)use($topic,$publicUrl,$quote):string{
        $input=json_decode($prompt,true,24,JSON_THROW_ON_ERROR);
        $source='topic-web:'.hash('sha256',$publicUrl);
        pipeline_check($protocol==='vp3.topic-research.v1'&&($input['topic']??null)===$topic
            &&array_column($input['sources'],'source')===[$source]&&!str_contains($prompt,'private Friday'),
            'synthesis receives the public excerpt and topic without the private check-in reply or focus');
        return json_encode(['findings'=>[['source'=>$source,'quote'=>$quote]]],JSON_THROW_ON_ERROR);
    },[
        'search'=>static function(string $query)use($topic,$hits):array{
            pipeline_check($query===$topic,'the retried canonical job still searches the exact authorized topic');
            return $hits;
        },
        'request'=>static fn(string $url):array=>$url===$publicUrl
            ?['status'=>200,'content_type'=>'text/plain','body'=>$quote.' Opening a hot oven door exchanges warm air for cooler air.']
            :['status'=>503,'content_type'=>'text/plain','body'=>'Temporarily unavailable'],
    ]);
$q=$pdo->prepare("SELECT result_json FROM agent_workflow_actions WHERE run_id=? AND status='completed'");
$q->execute([$researchRunId]);$savedResearch=json_decode((string)$q->fetchColumn(),true,24,JSON_THROW_ON_ERROR);
pipeline_check(($completed['ok']??false)&&$savedResearch['initiative_delivery']==='pending'
    &&count(scope_messages())===$beforeReports,'completed research saves a verified receipt while its owner is away');
$turn->execute([170,null,'assistant','We can work from your oven heat recovery question.',gmdate('Y-m-d H:i:s')]);
$pdo->exec("UPDATE agent_activity_state SET details_json='{\"visible\":true,\"conversation_id\":170}',last_heartbeat_at=NOW() WHERE user_id=1");
pipeline_check(vp3_initiative_deliver_pending_v1($pdo,$user,17),'the owner returning to the exact Chat receives the saved report');
$q->execute([$researchRunId]);$deliveredResearch=json_decode((string)$q->fetchColumn(),true,24,JSON_THROW_ON_ERROR);
$messageQuery=$pdo->prepare("SELECT id,role,message,context_json FROM chat_messages WHERE id=? AND conversation_id=170 AND role='assistant'");
$messageQuery->execute([$deliveredResearch['initiative_message_id']]);
$researchMessage=$messageQuery->fetch();$researchContext=json_decode($researchMessage['context_json'],true,24,JSON_THROW_ON_ERROR);
pipeline_check($deliveredResearch['initiative_delivery']==='delivered'&&str_contains($researchMessage['message'],$quote)
    &&$researchContext['initiative_run_id']===$researchRunId
    &&array_column(array_filter($researchContext['sources'],static fn(array $source):bool=>isset($source['coverage'])),'coverage')===['page_excerpt','search_match'],
    'the report retains the exact research run, verified quotation and honest source coverage');
$recall=vp3_initiative_status_v1($pdo,$user,$researchGoal['binding'],[],true);
$citations=array_values(array_filter($recall['sources'],static fn(array $source):bool=>isset($source['coverage'])));
pipeline_check(str_contains($recall['answer'],$quote)&&$citations===[
    ['source'=>'topic-web:'.hash('sha256',$publicUrl),'title'=>'Oven heat recovery','url'=>$publicUrl,'coverage'=>'page_excerpt'],
    ['source'=>'topic-web:'.hash('sha256',$leadUrl),'title'=>'Oven temperature guide','url'=>$leadUrl,'coverage'=>'search_match'],
], 'Chat recall reads the saved findings and verified public citations without running another search');
$projected=vp3_initiative_research_canvas_history_v1($pdo,1,17,170,[$researchMessage]);
$canvas=json_decode($projected[0]['context_json'],true,24,JSON_THROW_ON_ERROR)['research_canvas_v1']??[];
pipeline_check(($canvas['kind']??null)==='topic'&&($canvas['title']??null)===$topic&&($canvas['run_id']??null)===$researchRunId
    &&array_column($canvas['sources'],'url')===[$publicUrl,$leadUrl]
    &&array_column($canvas['sources'],'coverage')===['page_excerpt','search_match'],
    'the existing authorized Chat history projects a sourced Canvas from the completed specialist report');
pipeline_check(!vp3_initiative_deliver_pending_v1($pdo,$user,17),'the delivery sweep does not post the research twice');
$ageReview->execute([gmdate('Y-m-d H:i:s',time()-301)]);
vp3_initiative_schedule_v1($pdo,$user,17);
pipeline_check(count(scope_runs(1,17,'initiative.plan'))===$beforePlans+1,
    'a later sweep of the same decision and evidence does not queue the thought again');

$association=array_intersect_key($decision['initiative_followthrough'],['message_id'=>true,'reply_ref'=>true,'revision'=>true]);
$declinedAt=time();
$decline='Thanks, please leave that topic alone.';
$turn->execute([170,1,'user',$decline,gmdate('Y-m-d H:i:s',$declinedAt)]);
$declined=vp3_initiative_followthrough_reply_v1($pdo,$user,scope_principal($user,17),170,(int)$pdo->lastInsertId(),$association,$decline,null,
    static fn():string=>'{"intent":"decline","focus":""}');
pipeline_check(($declined['initiative_followthrough']['state']??null)==='declined','the original delivered check-in records a verified later decline');
$evaluate=static function()use($pdo,$user,$declinedAt,$weakRef,$researchRef):array {
    $coverage=[];
    return vp3_initiative_research_evidence_v1($pdo,['user'=>$user,'agent_id'=>17],new DateTimeImmutable('@'.($declinedAt+90)),[$weakRef,$researchRef],$coverage);
};
$afterDecline=$evaluate();
pipeline_check(!isset($afterDecline[$researchRef])&&$afterDecline[$weakRef]['revision']===$draft['payload']['sources'][$weakRef],
    'decline suppresses old research while its exact conversational source still verifies');
foreach(['wood-fired oven heat management techniques','wood-fired oven heat control'] as $i=>$query){
    $turn->execute([171,null,'assistant','New YouTube results',gmdate('Y-m-d H:i:s',$declinedAt+5+$i)]);
    agent_connection_youtube_results_save_v1($pdo,1,171,(int)$pdo->lastInsertId(),[
        'query'=>$query,'expires_at'=>time()+3600,
        'videos'=>[['title'=>'Example oven tutorial','video_id'=>'abc_def-123','made_for_kids'=>false]],
    ]);
    $latest=$evaluate();
    if($i===0)pipeline_check(!isset($latest[$researchRef]),'one new associated search does not reopen a declined interest');
}
pipeline_check($latest[$researchRef]['title']===$topic&&$latest[$researchRef]['interest']['search_count']===2
    &&$latest[$researchRef]['revision']!==$researchGoal['sources'][$researchRef]
    &&$latest[$weakRef]['revision']===$draft['payload']['sources'][$weakRef],
    'a fresh independent post-decline pattern can reopen research with the original topic and a new revision');

pipeline_reset($user);
pipeline_check(vp3_initiative_youtube_playlist_ready_v1($pdo),'the normal YouTube result schema installs the pending playlist cache');
vp3_youtube_playback_install_v1($pdo);
vp3_youtube_research_save_v1($pdo,1,'artist',true);
vp3_youtube_history_change_v1($pdo,1,'enable_history');
vp3_youtube_research_save_v1($pdo,1,'watch',true);
putenv('VP3_YOUTUBE_API_KEY='.str_repeat('f',20));
$artist='Fixture Band';
$artistRef='artist-interest:'.hash('sha256',strtolower($artist));
$videoIds=['video_00001','video_00002','video_00003'];
$youtubeRequests=0;
$youtube=static function(string $url)use($videoIds,$artist,&$youtubeRequests):array{
    ++$youtubeRequests;
    pipeline_check(str_starts_with($url,VP3_YOUTUBE_STATUS_ENDPOINT_V1.'?'),'playlist verification reads the exact YouTube video endpoint');
    parse_str((string)parse_url($url,PHP_URL_QUERY),$query);
    $items=[];
    foreach(explode(',',(string)($query['id']??'')) as $id){
        if(!in_array($id,$videoIds,true))throw new LogicException('Unexpected video identity.');
        $items[]=['id'=>$id,'snippet'=>['title'=>$artist.' – '.$id,'channelTitle'=>$artist.' - Topic','categoryId'=>'10'],
            'status'=>['privacyStatus'=>'public','embeddable'=>$id!==$videoIds[2],'madeForKids'=>false]];
    }
    return ['status'=>200,'json'=>['kind'=>'youtube#videoListResponse','items'=>$items]];
};
foreach([170=>['Fixture Band live',1200],171=>['Fixture Band songs',200]] as $cid=>[$query,$age]){
    $when=time()-$age;
    $turn->execute([$cid,null,'assistant','YouTube results',gmdate('Y-m-d H:i:s',$when)]);
    $searchId=(int)$pdo->lastInsertId();
    $verified=agent_connection_youtube_video_status_v1([['video_id'=>$videoIds[0],'title'=>$artist.' – performance',
        'channel_title'=>$artist.' - Topic']],$youtube);
    agent_connection_youtube_results_save_v1($pdo,1,$cid,$searchId,['query'=>$query,'expires_at'=>time()+3600,'videos'=>$verified]);
    $playId=sprintf('10000000-0000-4000-8000-%012d',$cid);
    $capture=vp3_youtube_capture_key_v1($pdo,1);
    pipeline_check(vp3_youtube_history_record_v1($pdo,1,$searchId,$videoIds[0],$playId,$capture),
        'a verified video from an ordinary search records in that exact Chat');
    $pdo->prepare('UPDATE vp3_youtube_playback_v1 SET played_at=? WHERE play_id=?')->execute([$when,$playId]);
    pipeline_check(vp3_youtube_watch_record_v1($pdo,1,$searchId,$videoIds[0],$playId,$capture,65),
        'meaningful viewing updates the exact recorded play');
    $pdo->prepare('UPDATE vp3_youtube_watch_v1 SET last_watched_at=? WHERE play_id=?')->execute([$when+65,$playId]);
}
$GLOBALS['scopeModel']=static function(string $prompt,string $protocol)use($artistRef,$artist):string{
    $input=json_decode($prompt,true,24,JSON_THROW_ON_ERROR);
    $evidence=array_column($input['evidence'],'title','ref');
    pipeline_check($protocol==='vp3.initiative-plan.v1'&&in_array($artistRef,$input['tools']['artist.research']['source_refs']??[],true)
        &&($evidence[$artistRef]??null)===$artist,'ordinary artist searches and separated plays expose one exact named artist to the planner');
    return json_encode(['jobs'=>[['tool'=>'artist.research','primary'=>$artistRef,'refs'=>[$artistRef],'focus'=>'overview','report'=>true]]],JSON_THROW_ON_ERROR);
};
$planId=scope_make_plan($user,17,'Research the artist from regular viewing');
pipeline_check(vp3_initiative_execute_v1($pdo,$user,scope_claim($user,$planId))['ok'],
    'the governed planner schedules the scoped artist research job');
$artistRuns=scope_runs(1,17,'initiative.prepare');$artistRun=(int)end($artistRuns)['id'];
$topics=[];
$beforePlaylistChecks=$youtubeRequests;
$pdo->exec("UPDATE agent_activity_state SET details_json='{\"visible\":false,\"conversation_id\":170}',last_heartbeat_at=NOW() WHERE user_id=1");
$artistWork=vp3_initiative_execute_v1($pdo,$user,scope_claim($user,$artistRun),
    static function(string $prompt,string $protocol):string{
        if($protocol!=='vp3.artist-research.v1')throw new LogicException('Unexpected research protocol.');
        return '{"findings":[]}';
    },[
        'search'=>static function(string $query)use(&$topics,$artist,$videoIds):array{
            pipeline_check(str_starts_with($query,'"'.$artist.'"'),'each background search uses the exact owner-entered artist');
            $focus=substr($query,strlen($artist)+3);$topics[]=$focus;
            $hits=$focus==='official live performances music videos'
                ?array_map(static fn(string $id):array=>['url'=>'https://www.youtube.com/watch?v='.$id,'title'=>'Verified candidate '.$id],$videoIds)
                :[['url'=>'https://research.example/'.str_replace(' ','-',$focus),'title'=>'Artist source '.$focus]];
            return ['ok'=>true,'results'=>$hits];
        },
        'request'=>static fn():array=>['status'=>503,'content_type'=>'text/plain','body'=>'Temporarily unavailable'],
        'youtube_http'=>$youtube,
    ]);
pipeline_check(($artistWork['ok']??false)&&$topics===['latest music release news interview','upcoming concerts tour dates',
    'official merchandise vinyl store','official live performances music videos']&&$youtubeRequests===$beforePlaylistChecks+1,
    'the leased artist job checks releases, tours, merch and videos and verifies the playlist with one provider request');
$q->execute([$artistRun]);$artistReceipt=(string)$q->fetchColumn();$artistResult=json_decode($artistReceipt,true,24,JSON_THROW_ON_ERROR);
pipeline_check($artistResult['initiative_delivery']==='pending'
    &&$artistResult['initiative_tool_result']['output']['playlist_run_id']===$artistRun
    &&!str_contains($artistReceipt,'channel_title')
    &&vp3_initiative_youtube_playlist_load_v1($pdo,1,$artistRun)!==null
    &&vp3_initiative_youtube_playlist_load_v1($pdo,2,$artistRun)===null,
    'while the owner is away, verified provider metadata stays in the expiring owner cache and the receipt retains only its reference');
$pdo->exec("UPDATE agent_activity_state SET details_json='{\"visible\":true,\"conversation_id\":170}',last_heartbeat_at=NOW() WHERE user_id=1");
pipeline_check(vp3_initiative_deliver_pending_v1($pdo,$user,17),'the owner can receive the playlist and research together on return');
$q->execute([$artistRun]);$artistResult=json_decode((string)$q->fetchColumn(),true,24,JSON_THROW_ON_ERROR);
$artistMessage=(int)($artistResult['initiative_message_id']??0);
$messageQuery->execute([$artistMessage]);
$projected=agent_connection_youtube_history_v1($pdo,1,17,170,[$messageQuery->fetch()]);
$playlist=json_decode($projected[0]['context_json'],true,24,JSON_THROW_ON_ERROR)['youtube_results']??[];
pipeline_check($artistResult['initiative_delivery']==='delivered'&&($playlist['suggestion_kind']??null)==='playlist'
    &&array_column($playlist['videos'],'video_id')===array_slice($videoIds,0,2)
    &&vp3_initiative_youtube_subject_v1($playlist)===''
    &&vp3_initiative_youtube_playlist_load_v1($pdo,1,$artistRun)===null,
    'Chat reload restores the playable playlist without feeding the agent-generated suggestion back as an owner search');
pipeline_check(agent_connection_youtube_result_records_v1($pdo,2,17,null,1,[$artistMessage])===[]
    &&agent_connection_youtube_result_records_v1($pdo,1,18,null,1,[$artistMessage])===[]
    &&!vp3_initiative_deliver_pending_v1($pdo,$user,17),
    'the saved playlist is private to the exact owner and Agent and does not post twice');
agent_connection_youtube_quota_schema_v1($pdo);
$topicPlaylist=vp3_initiative_youtube_playlist_prepare_v1($pdo,1,$topic,'topic','playlist',[],static function():void{},
    static function(string $url)use($topic,$videoIds,$youtube):array{
        if(str_starts_with($url,VP3_YOUTUBE_STATUS_ENDPOINT_V1.'?'))return $youtube($url);
        parse_str((string)parse_url($url,PHP_URL_QUERY),$query);
        pipeline_check(($query['q']??null)===$topic,'a non-music playlist searches its exact owner-entered subject');
        $items=[];
        foreach($videoIds as $id)$items[]=['id'=>['kind'=>'youtube#video','videoId'=>$id],
            'snippet'=>['title'=>'Oven tutorial '.$id,'channelTitle'=>'Cooking']];
        return ['status'=>200,'json'=>['kind'=>'youtube#searchListResponse','items'=>$items]];
    });
pipeline_check(($topicPlaylist['music_suggestion']??null)===false&&array_column($topicPlaylist['videos'],'video_id')===array_slice($videoIds,0,2),
    'a deliberate non-music playlist stays on topic and excludes an unplayable result');
putenv('VP3_YOUTUBE_API_KEY');
echo "AGENT_INITIATIVE_TOOLS_PIPELINE_V1=PASS\n";
