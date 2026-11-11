<?php
declare(strict_types=1);

// The existing disposable fixture runs the full canonical Canvas pipeline and
// supports native MySQL through VP3_TEST_MYSQL_DSN; no production function is replaced.
require __DIR__.'/agent-initiative-canvas-pipeline-v1.php';
$brainstormStart=$checks;
function brainstorm_pipeline_evidence(): array
{
    return vp3_initiative_in_scope_v1($GLOBALS['owner'],7,static fn()=>vp3_initiative_evidence_v1(db(),$GLOBALS['owner'],new DateTimeImmutable('now',new DateTimeZone('UTC')),false,[],agentId:7));
}
function brainstorm_pipeline_payload(array $evidence,?int $slot=null): array
{
    return vp3_initiative_in_scope_v1($GLOBALS['owner'],7,static function()use($evidence,$slot):array{
        $binding=vp3_initiative_policy_v1($GLOBALS['owner'],7);
        $review=agent_brain_v122_memory($GLOBALS['owner'],'initiative_sweep','last-review')['metadata']??[];
        $payload=['binding'=>$binding,'sources'=>array_column($evidence,'revision','ref'),
            'canonical_sources'=>array_map(static fn(array $row):string=>$row['canonical_source_revision']??$row['revision'],$evidence),
            'capability_sources'=>vp3_initiative_capability_source_map_v1($evidence,array_keys($evidence)),
            'owner_availability'=>vp3_initiative_availability_v1(vp3_initiative_owner_context_v1(db(),$GLOBALS['owner'],$binding,false)),
            'attention_revision'=>$review['attention_revision']??str_repeat('a',32),
            'reassessment_slot'=>$slot??vp3_initiative_brainstorm_slot_v1()];
        $engagement=vp3_initiative_engagement_revision_v1(db(),$GLOBALS['owner'],$binding);
        if($engagement!=='')$payload['engagement_revision']=$engagement;
        return $payload;
    });
}
function brainstorm_pipeline_enqueue(array $payload): int
{
    $before=count(canvas_pipeline_runs('initiative.plan'));
    canvas_pipeline_check(vp3_initiative_enqueue_v1(db(),$GLOBALS['owner'],'initiative.plan',$payload,hash('sha256',json_encode($payload,JSON_THROW_ON_ERROR))), 'a real source-bound Brainstorm workflow is admitted');
    $rows=canvas_pipeline_runs('initiative.plan');
    canvas_pipeline_check(count($rows)===$before+1,'assessment uses the existing canonical queue rather than a parallel store');
    return (int)end($rows)['id'];
}
function brainstorm_pipeline_model(?callable $during=null): Closure
{
    return static function(string $prompt,string $protocol)use($during):string{
        $packet=json_decode($prompt,true,32,JSON_THROW_ON_ERROR);
        canvas_pipeline_check($protocol==='vp3.initiative-plan.v1'&&!db()->inTransaction()
            &&$packet['budget']['max_opportunities']===8&&$packet['budget']['max_jobs']===3,
            'the real governed model receives independent thought and execution bounds outside database locks');
        $active=db()->query("SELECT progress_message FROM agent_workflow_runs WHERE capability_key='initiative.plan' AND status='executing' ORDER BY id DESC LIMIT 1")->fetchColumn();
        canvas_pipeline_check($active==='Brainstorming useful opportunities.','the truthful Brainstorm progress exists only on the actually leased assessment');
        if($during)$during();
        return json_encode(['jobs'=>[['tool'=>'work.prepare_private','primary'=>'task:20001','refs'=>['task:20001'],
            'focus'=>'Prepare options for the recurring review','report'=>false]],'opportunities'=>[
                ['tool'=>'work.prepare_private','primary'=>'task:20001','refs'=>['task:20001'],
                    'objective'=>'Prepare options for the recurring review','rationale'=>'The saved recurring preparation task could benefit from a more focused next step.'],
                ['tool'=>'canvas.prepare','primary'=>'task:20001','refs'=>['task:20001'],
                    'objective'=>'Consider an interactive review checklist','rationale'=>'A reusable checklist could reduce repeated setup; its value still needs discussion.'],
            ]],JSON_THROW_ON_ERROR);
    };
}

// Model a completed prior five-minute slot using the same real source snapshot
// that the due scheduler will inspect now. Time itself is not a source change.
$pdo->exec("UPDATE agent_memory_items SET is_active=0 WHERE memory_type='task';
    UPDATE agent_memory_items SET last_seen_at='2000-01-01 00:00:00' WHERE memory_type='initiative_sweep';
    UPDATE agent_activity_state SET last_heartbeat_at=CURRENT_TIMESTAMP;
    UPDATE chat_messages SET created_at='2000-01-01 00:00:00' WHERE conversation_id=11");
$pdo->prepare("INSERT INTO agent_memory_items(id,user_id,user_agent_id,memory_type,subject,memory_text,memory_hash,memory_scope_version,
    confidence,occurrence_count,first_seen_at,last_seen_at,is_active,metadata_json)
    VALUES(20001,1,7,'task','Recurring review','Prepare the same recurring review each week','brainstorm-recurring-review',410,.95,1,CURRENT_TIMESTAMP,CURRENT_TIMESTAMP,1,?)")
    ->execute([json_encode(['task_status'=>'open','due_at'=>gmdate('Y-m-d H:i:s',time()-60)],JSON_THROW_ON_ERROR)]);
$evidence=brainstorm_pipeline_evidence();
$older=brainstorm_pipeline_payload($evidence,vp3_initiative_brainstorm_slot_v1()-1);
$oldRun=brainstorm_pipeline_enqueue($older);
$pendingBefore=count(canvas_pipeline_runs('initiative.plan'));
vp3_initiative_schedule_v1($pdo,$owner,7);
canvas_pipeline_check(count(canvas_pipeline_runs('initiative.plan'))===$pendingBefore,
    'an offline worker does not accumulate another identical assessment in the next slot');
foreach([1,2,3] as $offset){
    $later=$older;$later['reassessment_slot']+=$offset;
    canvas_pipeline_check(vp3_initiative_brainstorm_pending_v1($pdo,$owner,$later),
        'the same pending canonical assessment remains reusable after slot advance '.$offset);
}
$changed=$older;$changed['engagement_revision']=hash('sha256','a new owner decision');
canvas_pipeline_check(!vp3_initiative_brainstorm_pending_v1($pdo,$owner,$changed),
    'a changed owner decision can admit a genuinely different assessment while a worker is unavailable');
$GLOBALS['pipelineModel']=brainstorm_pipeline_model();
$beforeJobs=count(canvas_pipeline_runs('initiative.prepare'));
$beforeMessages=canvas_pipeline_count('chat_messages');
canvas_pipeline_check(vp3_initiative_execute_v1($pdo,$owner,canvas_pipeline_claim($oldRun))['ok'],'a real prior-slot model assessment commits its opportunities');
$first=canvas_pipeline_result($oldRun);$firstOutput=$first['initiative_tool_result']['output'];
canvas_pipeline_check($firstOutput['type']==='initiative_brainstorm'&&array_column($firstOutput['opportunities'],'status')===['queued','considered'],
    'the private canonical receipt distinguishes actually queued work from an unexecuted idea');
canvas_pipeline_check(count(canvas_pipeline_runs('initiative.prepare'))===$beforeJobs+1&&canvas_pipeline_count('chat_messages')===$beforeMessages,
    'brainstorming dispatches one selected job without turning every thought into a Chat message');
$q=$pdo->prepare('SELECT result_json FROM agent_workflow_actions WHERE run_id=?');$q->execute([$oldRun]);
canvas_pipeline_check(json_decode((string)$q->fetchColumn(),true,32,JSON_THROW_ON_ERROR)===$first,'action and receipt persist the identical typed private assessment');
canvas_pipeline_check(!str_contains(json_encode(agent_job_public_run_v1900($pdo,agent_workflow_row_v1400($pdo,1,$oldRun),true),JSON_THROW_ON_ERROR),'reusable checklist'),
    'ordinary workflow details do not expose private opportunity explanations');
$beforePlans=count(canvas_pipeline_runs('initiative.plan'));
$pdo->exec("UPDATE agent_memory_items SET last_seen_at='2000-01-01 00:00:00' WHERE memory_type='initiative_sweep'");
vp3_initiative_schedule_v1($pdo,$owner,7);$plans=canvas_pipeline_runs('initiative.plan');$newRun=(int)end($plans)['id'];
canvas_pipeline_check(count($plans)===$beforePlans+1,'the next real five-minute slot reassesses stable sources rather than deduping forever');
$newGoal=json_decode(end($plans)['goal'],true,32,JSON_THROW_ON_ERROR);
canvas_pipeline_check($newGoal['sources']===$older['sources']&&$newGoal['binding']===$older['binding']
    &&$newGoal['reassessment_slot']===$older['reassessment_slot']+1,'only the bounded plan slot advances while source authority and Agent binding stay exact');
canvas_pipeline_check(vp3_initiative_execute_v1($pdo,$owner,canvas_pipeline_claim($newRun))['ok'],'the due real reassessment completes through its existing governed workflow');
$second=canvas_pipeline_result($newRun)['initiative_tool_result']['output'];
canvas_pipeline_check(array_column($second['opportunities'],'id')===array_column($firstOutput['opportunities'],'id')
    &&array_column($second['opportunities'],'status')===['existing','considered']&&count(canvas_pipeline_runs('initiative.prepare'))===$beforeJobs+1,
    'time does not manufacture new ideas or repeat the same prepared work');
$pdo->exec("UPDATE agent_memory_items SET last_seen_at='2000-01-01 00:00:00' WHERE memory_type='initiative_sweep'");
vp3_initiative_schedule_v1($pdo,$owner,7);
canvas_pipeline_check(count(canvas_pipeline_runs('initiative.plan'))===count($plans),'repeated due polling in one slot does not spend another assessment');

$single=['task:20001'=>brainstorm_pipeline_evidence()['task:20001']];
$payload=brainstorm_pipeline_payload($single);$payload['attention_revision']=bin2hex(random_bytes(16));
$stale=brainstorm_pipeline_enqueue($payload);
$GLOBALS['pipelineModel']=brainstorm_pipeline_model(static fn()=>db()->exec('UPDATE agent_memory_items SET is_active=0 WHERE id=20001'));
canvas_pipeline_check(!vp3_initiative_execute_v1($pdo,$owner,canvas_pipeline_claim($stale))['ok'],'a source changed during inference cannot commit stale brainstorm text');
$q=$pdo->prepare('SELECT result_json FROM agent_workflow_receipts WHERE run_id=?');$q->execute([$stale]);
canvas_pipeline_check(!str_contains((string)$q->fetchColumn(),'initiative_brainstorm')&&count(canvas_pipeline_runs('initiative.prepare'))===$beforeJobs+1,
    'failed source validation saves no opportunity payload and admits no child work');
$pdo->exec('UPDATE agent_memory_items SET is_active=1 WHERE id=20001');

$payload=brainstorm_pipeline_payload($single);$payload['attention_revision']=bin2hex(random_bytes(16));
$atomic=brainstorm_pipeline_enqueue($payload);$GLOBALS['pipelineModel']=brainstorm_pipeline_model();
// Give this plan one genuinely new child assignment so rollback proves both
// artifact and work atomicity rather than relying on an already-deduped child.
$GLOBALS['pipelineModel']=static function(string $prompt,string $protocol): string{
    $packet=json_decode((brainstorm_pipeline_model())($prompt,$protocol),true,32,JSON_THROW_ON_ERROR);
    $packet['jobs'][0]['tool']='canvas.prepare';
    return json_encode($packet,JSON_THROW_ON_ERROR);
};
$pdo->beforeReceipt=static function(array $params)use($atomic):void{
    if((int)$params[0]===$atomic){$GLOBALS['pdo']->beforeReceipt=null;throw new RuntimeException('Synthetic final receipt interruption.');}
};
// This Canvas assignment has not been queued before; the interrupted receipt
// must roll it back together with the private thought it would have recorded.
canvas_pipeline_check(!vp3_initiative_execute_v1($pdo,$owner,canvas_pipeline_claim($atomic))['ok'],'a final receipt failure cannot publish a successful assessment');
$q=$pdo->prepare('SELECT result_json FROM agent_workflow_receipts WHERE run_id=?');$q->execute([$atomic]);
canvas_pipeline_check(!str_contains((string)$q->fetchColumn(),'initiative_brainstorm')&&count(canvas_pipeline_runs('initiative.prepare'))===$beforeJobs+1,
    'receipt rollback retains no private thought or extra child assignment');

$payload=brainstorm_pipeline_payload($single);$payload['attention_revision']=bin2hex(random_bytes(16));
$revoked=brainstorm_pipeline_enqueue($payload);
$GLOBALS['pipelineModel']=brainstorm_pipeline_model(static function():void{$GLOBALS['imageDenied']=true;});
canvas_pipeline_check(!vp3_initiative_execute_v1($pdo,$owner,canvas_pipeline_claim($revoked))['ok'],'owner access revoked during assessment prevents final persistence');
$GLOBALS['imageDenied']=false;
$q=$pdo->prepare('SELECT result_json FROM agent_workflow_receipts WHERE run_id=?');$q->execute([$revoked]);
canvas_pipeline_check(!str_contains((string)$q->fetchColumn(),'initiative_brainstorm'),'revoked owner access cannot leave source-derived opportunities in a failed receipt');

$payload=brainstorm_pipeline_payload($single);$payload['attention_revision']=bin2hex(random_bytes(16));
$paused=brainstorm_pipeline_enqueue($payload);
$GLOBALS['pipelineModel']=brainstorm_pipeline_model(static function()use($pdo,$owner,$principal):void{
    vp3_initiative_chat_v1($pdo,$owner,$principal,['conversation_id'=>11],'off',null);
});
canvas_pipeline_check(!vp3_initiative_execute_v1($pdo,$owner,canvas_pipeline_claim($paused))['ok'],'pausing Initiative during inference prevents its old binding from committing');
$q=$pdo->prepare('SELECT result_json FROM agent_workflow_receipts WHERE run_id=?');$q->execute([$paused]);
canvas_pipeline_check(!str_contains((string)$q->fetchColumn(),'initiative_brainstorm'),'a paused stale policy cannot save an assessment opportunity');

require_once dirname(__DIR__).'/includes/agent-initiative-lifecycle-v1.php';
require_once dirname(__DIR__).'/includes/agent-scheduling-tools-v460.php';
require_once dirname(__DIR__).'/includes/profile-agent.php';
$pdo->exec("ALTER TABLE users ADD COLUMN display_name VARCHAR(100); ALTER TABLE users ADD COLUMN role VARCHAR(20);
    ALTER TABLE users ADD COLUMN avatar_path VARCHAR(100); UPDATE users SET display_name='Fixture owner' WHERE id=1;
    CREATE TABLE user_profiles(user_id INTEGER PRIMARY KEY,username VARCHAR(80),bio TEXT,is_public INTEGER);
    INSERT INTO user_profiles VALUES(1,'fixture','Collects neighborhood photographs',0);
    CREATE TABLE user_agent_preferences(user_id INTEGER PRIMARY KEY,onboarding_dismissed INTEGER,voice_preference VARCHAR(20),
        onboarding_step VARCHAR(40),onboarding_draft_json TEXT,feature_interest_json TEXT,last_trial_notice_threshold INTEGER,last_trial_notice_at DATETIME);
    INSERT INTO user_agent_preferences VALUES(1,1,NULL,'complete',NULL,'{\"workflow.booking\":true}',NULL,NULL);
    CREATE TABLE agent_scheduling_schedules(id INTEGER PRIMARY KEY,owner_user_id INTEGER,agent_id INTEGER,is_active INTEGER,is_default INTEGER);
    CREATE TABLE agent_scheduling_event_types(id INTEGER PRIMARY KEY,schedule_id INTEGER,is_active INTEGER,sort_order INTEGER,title VARCHAR(100),minimum_notice_minutes INTEGER);
    INSERT INTO agent_scheduling_schedules VALUES(501,1,8,1,1);
    INSERT INTO agent_scheduling_event_types VALUES(601,501,1,0,'Consultation',0);
    CREATE TABLE agent_scheduling_availability(id INTEGER PRIMARY KEY,schedule_id INTEGER,event_type_id INTEGER,weekday INTEGER,start_minute INTEGER,end_minute INTEGER,is_active INTEGER);
    CREATE TABLE agent_scheduling_overrides(id INTEGER PRIMARY KEY);
    CREATE TABLE agent_scheduling_bookings(id INTEGER PRIMARY KEY,start_at_utc DATETIME,public_token VARCHAR(32),buffer_before_minutes INTEGER,buffer_after_minutes INTEGER)");
$quiet=['surface'=>'chat','conversation_id'=>12,'visible'=>true,'focused'=>true,'chat_focused'=>true,'chat_busy'=>false,
    'activity_tab'=>str_repeat('b',32),'activity_observed_at'=>(int)floor(microtime(true)*1000),'activity_sequence'=>1];
$pdo->prepare("UPDATE agent_activity_state SET context_key='chat:12',last_heartbeat_at=CURRENT_TIMESTAMP,details_json=? WHERE user_id=1")
    ->execute([json_encode($quiet,JSON_THROW_ON_ERROR)]);
$adopted=vp3_initiative_adopt_activity_v1($pdo,$owner,$quiet);
canvas_pipeline_check(!empty($adopted['accepted'])&&$adopted['agent_id']===8&&canvas_pipeline_count('chat_messages','conversation_id=12')===0,
    'normal activity opens Initiative for a fresh quiet private Chat without a forced greeting');
vp3_initiative_schedule_v1($pdo,$owner,8);
$quietPlans=canvas_pipeline_runs('initiative.plan');$quietPlan=end($quietPlans);
$quietGoal=json_decode($quietPlan['goal'],true,32,JSON_THROW_ON_ERROR);
canvas_pipeline_check((int)$quietPlan['agent_id']===8&&isset($quietGoal['sources']['account-readiness:1'],$quietGoal['sources']['scheduling-readiness:1:8']),
    'the existing scheduler admits one assessment with the authorized profile and missing weekly appointment hours');
$GLOBALS['pipelineModel']=static function(string $prompt,string $protocol): string{
    $evidence=array_column(json_decode($prompt,true,32,JSON_THROW_ON_ERROR)['evidence'],null,'ref');
    canvas_pipeline_check($evidence['account-readiness:1']['readiness']['bio']==='Collects neighborhood photographs'
        &&$evidence['scheduling-readiness:1:8']['readiness']['active_event_types']===true
        &&$evidence['scheduling-readiness:1:8']['readiness']['missing_fields']===['weekly_hours'],
        'the verified weekly-hours gap reaches both the planner and check-in with the same active appointment evidence');
    return $protocol==='vp3.initiative-plan.v1'
        ?json_encode(['jobs'=>[['tool'=>'chat.check_in','primary'=>'account-readiness:1','refs'=>['account-readiness:1','scheduling-readiness:1:8'],
            'focus'=>'Ask how the owner wants to use their photo collection','report'=>true]]],JSON_THROW_ON_ERROR)
        :json_encode(['draft'=>'Would you like help organizing or sharing your neighborhood photographs?','delivery'=>'text'],JSON_THROW_ON_ERROR);
};
canvas_pipeline_check(vp3_initiative_execute_v1($pdo,$owner,canvas_pipeline_claim((int)$quietPlan['id']))['ok'],
    'the existing planner selects a source-backed conversation check-in');
$nextSlot=array_replace($quietGoal,['reassessment_slot'=>$quietGoal['reassessment_slot']+1]);
$changedSource=$nextSlot;
$changedSource['sources']['account-readiness:1']=str_repeat('a',64);
canvas_pipeline_check(vp3_initiative_brainstorm_pending_v1($pdo,$owner,$nextSlot,true)
    &&!vp3_initiative_brainstorm_pending_v1($pdo,$owner,$nextSlot)
    &&!vp3_initiative_brainstorm_pending_v1($pdo,$owner,$changedSource,true),
    'unchanged completed setup avoids another paid assessment while changed evidence and time-sensitive work can be reconsidered');
$quietChecks=canvas_pipeline_runs('initiative.prepare');$quietCheck=end($quietChecks);
$setChatBusy=static function(bool $busy)use($pdo,$quiet):void{
    $pdo->prepare('UPDATE agent_activity_state SET details_json=?,last_heartbeat_at=CURRENT_TIMESTAMP WHERE user_id=1')
        ->execute([json_encode(array_replace($quiet,['chat_busy'=>$busy]),JSON_THROW_ON_ERROR)]);
};
$setChatBusy(true);
canvas_pipeline_check(vp3_initiative_execute_v1($pdo,$owner,canvas_pipeline_claim((int)$quietCheck['id']))['ok']
    &&canvas_pipeline_result((int)$quietCheck['id'])['initiative_delivery']==='pending'
    &&canvas_pipeline_count('chat_messages','conversation_id=12')===0,
    'the existing check-in can complete without interrupting the owner');
$quietPrincipal=vp3_agent_chat_principal_v380($owner,vp3_agent_chat_resolve_agent_v380($pdo,$owner,8));
$beforeRead=count($GLOBALS['pipelineInference']);
$findingsQuery='What did you find in the background?';
$statusReadContext=json_encode(['initiative_status_read_v1'=>true,'skip_brain_archive'=>true],JSON_THROW_ON_ERROR);
$pdo->prepare("INSERT INTO chat_messages (conversation_id,user_id,role,message,context_json) VALUES (12,1,'user',?,?)")
    ->execute([$findingsQuery,$statusReadContext]);
$answer=vp3_capability_chat_v1($pdo,$owner,$quietPrincipal,$findingsQuery,['conversation_id'=>12,'user_agent_id'=>8]);
canvas_pipeline_check(!empty($answer['handled'])&&str_contains($answer['answer'],'Would you like help organizing or sharing your neighborhood photographs?')
    &&count($GLOBALS['pipelineInference'])===$beforeRead&&canvas_pipeline_count('chat_messages','conversation_id=12')===1,
    'ordinary Chat can read the same verified pending background result without starting another inference or delivery');
$pdo->prepare("INSERT INTO chat_messages (conversation_id,user_id,role,message,context_json) VALUES (12,NULL,'assistant',?,?)")
    ->execute(['Verified background findings were shown for this turn.',$statusReadContext]);
$setChatBusy(false);
canvas_pipeline_check(vp3_initiative_deliver_pending_v1($pdo,$owner,8)
    &&canvas_pipeline_result((int)$quietCheck['id'])['initiative_delivery']==='delivered'
    &&canvas_pipeline_count('chat_messages',"conversation_id=12 AND role='assistant' AND JSON_EXTRACT(context_json,'$.initiative_run_id') IS NOT NULL")===1,
    'the canonical check-in delivers one useful message to the exact saved Chat');
echo 'INITIATIVE_BRAINSTORM_PIPELINE=PASS ('.($checks-$brainstormStart).' assertions, '.($mysql?'mysql':'sqlite').')'.PHP_EOL;
