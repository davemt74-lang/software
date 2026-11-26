<?php
declare(strict_types=1);

require __DIR__.'/fixtures/initiative-ai-settings-v1.php';
$fixtureFile=tempnam(sys_get_temp_dir(),'vp3-initiative-runtime-');
putenv('VP3_IMAGE_TEST_DSN=sqlite:'.$fixtureFile);
require __DIR__.'/fixtures/image-generation-v1.php';
require_once dirname(__DIR__).'/includes/agent-memory-scope-v410.php';
require_once dirname(__DIR__).'/includes/agent-brain-v122.php';
require_once dirname(__DIR__).'/includes/agent-initiative-runtime-v1.php';

function agent_brain_schema_ready():bool{return true;}
function agent_brain_normalize(string $text):string{return strtolower(trim($text));}
function agent_brain_sentences(string $text):array{return [$text];}
function vp3_agent_runtime_plan_v420(PDO $pdo,array $user,int $agentId,string $surface='chat',array $context=[],bool $observeOnly=false):array
{
    $GLOBALS['runtimeRoutes'][]=[$user['id'],$agentId];
    return $GLOBALS['runtimePlan']??['route'=>'vp3_cloud','effective_preference'=>'vp3_cloud','blocked'=>false,'cloud'=>['ready'=>true]];
}
function homeserver_execution_v220_registry(int $owner):array
{
    return ['available'=>true,'capabilities'=>['unified_execution'=>[
        'profile_safe_local_inference'=>['operation'=>'agent.infer.local','stateless'=>true,
            'local_only'=>true,'caller_supplied_context_only'=>true,'tools_enabled'=>false],
    ]]];
}
function homeserver_execution_v230_execute(int $owner,string $operation,array $payload,?callable $fallback=null,bool $transientContext=false):array
{
    throw new LogicException('Readiness checks must not execute HomeServer inference.');
}
function calendar_schedule_awareness_snapshot_v1330(PDO $pdo,array $user,DateTimeImmutable $now,int $days,bool $includeHomeServer=true):array
{
    if($includeHomeServer)throw new LogicException('Background preparation cannot discover a paired calendar.');
    if(!empty($GLOBALS['runtimeCalendarUnavailable']))return ['available'=>false];
    $row=['ref'=>'event:42','kind'=>'event','id'=>42,'title'=>$GLOBALS['runtimeCalendarTitle']??'Launch review',
        'start_local'=>$GLOBALS['runtimeCalendarStart']??=gmdate('Y-m-d H:i:s',time()+600)];
    return ['available'=>true,'timezone'=>'UTC','upcoming'=>[$row],'current'=>null,'conflicts'=>[],'next'=>$row,'starts_soon'=>true];
}
class InitiativeRuntimePDO extends ImageFixturePDO
{
    public bool $failSpecialistScan=false;
    public bool $failAssessmentRead=false;

    public function prepare(string $sql,array $options=[]):PDOStatement|false
    {
        if($this->failAssessmentRead&&str_contains($sql,'p.result_json AS receipt_json')
            &&!str_contains($sql,'a.completed_at<=UTC_TIMESTAMP()')){
            $error=new PDOException('PRIVATE assessment query text');
            $error->errorInfo=['HY000',1525,'PRIVATE datetime error'];
            throw $error;
        }
        if($this->failSpecialistScan&&str_starts_with($sql,'SELECT 1 FROM agent_workflow_runs r WHERE')
            &&str_contains($sql,"r.source_key LIKE 'coordination:%'")){
            $error=new PDOException('PRIVATE SQL error text');
            $error->errorInfo=['42S22',1054,'PRIVATE column'];
            throw $error;
        }
        if(str_contains($sql,'INSERT INTO agent_memory_items')&&str_contains($sql,'ON DUPLICATE KEY UPDATE')){
            [$sql]=explode('ON DUPLICATE KEY UPDATE',$sql,2);
            $sql.='ON CONFLICT(user_id,memory_hash) DO UPDATE SET memory_text=excluded.memory_text,confidence=excluded.confidence,
                last_seen_at=NOW(),is_active=1,metadata_json=excluded.metadata_json,memory_scope_version=410';
        }
        return parent::prepare($sql,$options);
    }
}
$pdo=new InitiativeRuntimePDO('sqlite:'.$fixtureFile,null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
foreach(['UTC_TIMESTAMP','NOW'] as $name)$pdo->sqliteCreateFunction($name,static fn()=>gmdate('Y-m-d H:i:s'));
$pdo->sqliteCreateFunction('IF',static fn($condition,$yes,$no)=>$condition?$yes:$no);
$pdo->exec("ALTER TABLE chat_conversations ADD COLUMN updated_at TEXT;
    INSERT INTO chat_conversations VALUES(42,1,NULL,NULL),(43,2,NULL,NULL);
    CREATE TABLE settings(setting_key TEXT PRIMARY KEY,setting_value TEXT);
    CREATE TABLE agent_memory_items(id INTEGER PRIMARY KEY,user_id INTEGER,user_agent_id INTEGER,memory_type TEXT,subject TEXT,
      memory_text TEXT,memory_hash TEXT,memory_scope_version INTEGER,source_archive_id INTEGER,confidence REAL,occurrence_count INTEGER,
      first_seen_at TEXT,last_seen_at TEXT,is_active INTEGER,metadata_json TEXT,UNIQUE(user_id,memory_hash));
    CREATE TRIGGER runtime_system_agent AFTER INSERT ON agent_workflow_runs WHEN NEW.source_kind='agent_initiative' AND NEW.agent_id=0
      BEGIN UPDATE agent_workflow_runs SET agent_id=NULL WHERE id=NEW.id;END;
    DELETE FROM agent_workflow_runs;
    INSERT INTO chat_messages(conversation_id,user_id,role,message) VALUES(42,1,'user','Continue'),(42,NULL,'assistant','Ready')");
$GLOBALS['initiativeBrainAccess']=true;
$checks=0;
function runtime_check(bool $condition,string $message):void
{
    if(!$condition)throw new RuntimeException($message);
    $GLOBALS['checks']++;echo "PASS $message\n";
}
function runtime_enable(array $user,int $agentId,int $cid):array
{
    $agent=vp3_agent_chat_resolve_agent_v380(db(),$user,$agentId);
    $reply=vp3_initiative_chat_v1(db(),$user,vp3_agent_chat_principal_v380($user,$agent),['conversation_id'=>$cid],'on',null);
    runtime_check(str_contains($reply['answer'],'Initiative is on'),'canonical explicit opt-in succeeds for Agent '.$agentId);
    // This suite owns the generic assessment queue; specialist routing has its own gate.
    require_once dirname(__DIR__).'/includes/agent-coordination-rules-v1.php';
    $rules=vp3_coordination_rule_settings_v1(db(),$user,$agentId,$cid);
    $watch=array_map(static fn(array $triggers):array=>array_fill_keys(array_keys($triggers),'watch'),$rules['rules']);
    vp3_coordination_rule_settings_v1(db(),$user,$agentId,$cid,$rules['revision'],$watch);
    initiative_fixture_morning_brief_reviewed($user,$agentId);
    return vp3_initiative_policy_v1($user,$agentId);
}
register_shutdown_function(static fn()=>@unlink($fixtureFile));
$user=['id'=>1,'is_active'=>1];
runtime_check(vp3_initiative_worker_health_v1($pdo,$user,0,42)['state']==='starting','schema readiness awaits the accepted Chat lifecycle without activating from a read-only request');
$policy=runtime_enable($user,0,42);
// This owner already received onboarding. Keep its actual saved messages and
// canonical delivery history so independent starter offers do not enter these
// generic assessment/repair scenarios.
require_once dirname(__DIR__).'/includes/agent-casual-checkin-v1.php';
$priorStarter=$pdo->prepare("INSERT INTO chat_messages(conversation_id,user_id,role,message,created_at)
    VALUES(42,NULL,'assistant',?,'2000-01-01 00:00:00')");
$pdo->beginTransaction();
foreach(VP3_SPECIALIST_STARTERS_V1 as $kind=>$starter){
    $priorStarter->execute([$starter['question']]);
    vp3_casual_checkin_record_v1($pdo,1,$kind,42,(int)$pdo->lastInsertId(),['agent_id'=>0]);
}
$pdo->commit();
$before=$pdo->query('SELECT COUNT(*) FROM agent_workflow_runs')->fetchColumn();
$pdo->exec('ALTER TABLE agent_workflow_runs RENAME COLUMN paused_at TO fixture_paused_at');
$health=vp3_initiative_worker_health_v1($pdo,$user,0,42);
runtime_check($health['assessment_read']==='complete'&&$health['enabled']&&$health['pump_available']
    &&$health['latest_assessment_run']===null,'the job schema can read assessments without optional workflow pause controls');
$pdo->exec('ALTER TABLE agent_workflow_runs RENAME COLUMN fixture_paused_at TO paused_at');
runtime_check($health['configuration_ready']&&$health['execution_ready']&&$health['pump_available']
    &&$health['last_checked_at']===''&&$health['active_jobs']===[]&&$pdo->query('SELECT COUNT(*) FROM agent_workflow_runs')->fetchColumn()===$before,
    'read-only health distinguishes available execution from an actual source review');
$pdo->failSpecialistScan=true;
try{
    $failedScan=vp3_initiative_pump_v1($pdo,$user,0,42,$policy['revision'],static fn():string=>'{"jobs":[]}');
}finally{
    $pdo->failSpecialistScan=false;
}
$scanCheck=vp3_initiative_foreground_tick_v1($user,$policy)['coordination']??null;
runtime_check(($failedScan['coordination']??null)==='failed'
    &&($scanCheck['failure']??null)===['phase'=>'queue_check','code'=>'storage_schema','sqlstate'=>'42S22','database_code'=>1054]
    &&!str_contains(json_encode($failedScan),'PRIVATE SQL error text'),
    'a failed specialist queue query keeps its bounded error when the same tick completes another step');
$pdo->exec("DELETE FROM agent_memory_items WHERE memory_type='initiative_sweep'");
initiative_fixture_morning_brief_reviewed($user,0);
$pdo->exec('DELETE FROM agent_workflow_runs');
$pdo->exec('DELETE FROM agent_workflow_receipts');
$calls=0;$duplicate=null;
$model=static function(string $prompt,string $protocol)use(&$calls,&$duplicate,$pdo,$user,$policy):string{
    if($pdo->inTransaction())throw new RuntimeException('Inference must not hold a database transaction.');
    $lease=$pdo->query("SELECT id,lease_expires_at FROM agent_workflow_runs WHERE source_kind='agent_initiative' AND status='executing' ORDER BY id DESC LIMIT 1")->fetch();
    $expiresAt=strtotime($lease['lease_expires_at'].' UTC');
    if($expiresAt<time()+235)throw new RuntimeException('Inference requires the canonical refreshed lease.');
    $executing=vp3_initiative_worker_health_v1($pdo,$user,0,42);
    $activeJobs=array_map(static fn(array $job):array=>array_intersect_key($job,array_flip(
        ['id','expiresAt','kind','step','coordination_phase'])),$executing['active_jobs']);
    runtime_check($activeJobs===
        [['id'=>'workflow:'.$lease['id'],'expiresAt'=>$expiresAt*1000,
            'kind'=>$protocol==='vp3.initiative-plan.v1'?'assessment':'preparation',
            'step'=>$protocol==='vp3.initiative-plan.v1'?'Coordinating specialist findings':'Preparing a private next step',
            'coordination_phase'=>$protocol==='vp3.initiative-plan.v1'?'planning':'acting']],
        'read-only status projects the exact active workflow lease and safe actual work kind');
    runtime_check(($executing['active_jobs'][0]['phase']??null)===($protocol==='vp3.initiative-plan.v1'?'coordinating':'preparing')
        &&strtotime($executing['active_jobs'][0]['at']??'')>0
        &&array_keys($executing['active_jobs'][0])===['id','expiresAt','kind','step','coordination_phase','phase','at','specialist']
        &&($protocol!=='vp3.initiative-plan.v1'||$executing['active_jobs'][0]['specialist']==='ob1'),
        'the active-work projection includes its current phase and real timestamp');
    if($protocol==='vp3.initiative-plan.v1'){
        runtime_check($executing['latest_assessment_run']['run_id']===(int)$lease['id']
            &&$executing['latest_assessment_run']['status']==='running',
            'the exact active Assessment lease appears as current Assessment progress');
    }
    $calls++;
    if($protocol==='vp3.initiative-plan.v1'){
        $duplicate=vp3_initiative_pump_v1($pdo,$user,0,42,$policy['revision'],static function(){throw new RuntimeException('Duplicate model call');});
        $input=json_decode($prompt,true,16,JSON_THROW_ON_ERROR);
        if(!is_array($input['evidence']??null)||!is_array($input['tools']??null)||!is_array($input['budget']??null)){
            throw new RuntimeException('The canonical planner envelope is required.');
        }
        $rows=$input['evidence'];$ref=$rows[0]['ref'];
        return json_encode(['jobs'=>[['tool'=>'work.prepare_private','primary'=>$ref,'refs'=>[$ref],'focus'=>'Prepare launch review questions','report'=>true]]],JSON_THROW_ON_ERROR);
    }
    return '{"draft":"I prepared launch review questions from the current private source."}';
};
$planned=vp3_initiative_pump_v1($pdo,$user,0,42,$policy['revision'],$model);
runtime_check($planned['ok']&&$planned['reason']==='completed'&&$calls===1&&$duplicate['reason']==='already_running',
    'overlapping foreground pumps share the canonical lease and run one assessment');
$assessment=$planned['health']['last_assessment'];
runtime_check($assessment['outcome']==='assignments_queued'&&$assessment['queued']===1&&strtotime($assessment['at'])>0,
    'owner health distinguishes a verified completed assessment from an observed worker');
require_once dirname(__DIR__).'/includes/agent-initiative-engagement-status-v1.php';
$engagement=static fn()=>vp3_initiative_engagement_status_v1($pdo,$user,0,42,
    vp3_initiative_worker_health_v1($pdo,$user,0,42));
$queued=$engagement();
runtime_check($queued['tick_checked']&&($queued['latest_tick']['reason']??null)==='completed'
    &&($queued['latest_tick']['step']??null)==='assessment'&&($queued['latest_tick']['run_id']??0)>0
    &&($queued['latest_preparation']['status']??null)==='approved'&&$queued['last_post']===null,
    'debug identifies an actual foreground check and a queued assignment without pretending Chat received it');
$assignment=$queued['latest_preparation'];
$heartbeat=gmdate('Y-m-d H:i:s',time()-2);
$expires=gmdate('Y-m-d H:i:s',time()+120);
$wallClock=gmdate('Y-m-d H:i:s',time()-21600);
$pdo->prepare("UPDATE agent_workflow_runs SET status='executing',current_action_id=?,heartbeat_at=?,lease_expires_at=? WHERE id=?")
    ->execute([$assignment['action_id'],$heartbeat,$expires,$assignment['run_id']]);
$pdo->prepare("UPDATE agent_workflow_actions SET status='executing',heartbeat_at=?,updated_at=? WHERE id=?")
    ->execute([$heartbeat,$wallClock,$assignment['action_id']]);
$working=$engagement()['latest_preparation'];
runtime_check($working['status']==='executing'&&$working['at']===str_replace(' ','T',$heartbeat).'+00:00'
    &&$working['heartbeat_at']===$working['at']&&$working['lease_expires_at']===str_replace(' ','T',$expires).'+00:00',
    'a fresh workflow lease uses the UTC heartbeat when the database wall clock appears six hours older');
$pdo->prepare('UPDATE agent_workflow_runs SET lease_expires_at=? WHERE id=?')
    ->execute([$heartbeat,$assignment['run_id']]);
$interruptedHealth=vp3_initiative_worker_health_v1($pdo,$user,0,42);
$interrupted=$engagement()['latest_preparation'];
runtime_check($interrupted['status']==='interrupted'&&$interrupted['lease_expires_at']===$working['at']
    &&$interruptedHealth['reason']==='worker_interrupted'&&$interruptedHealth['active_jobs']===[],
    'an expired workflow lease is recorded as interrupted and never advertised as active');
$pdo->prepare("UPDATE agent_workflow_runs SET status='approved',current_action_id=NULL,heartbeat_at=NULL,lease_expires_at=NULL WHERE id=?")
    ->execute([$assignment['run_id']]);
$pdo->prepare("UPDATE agent_workflow_actions SET status='queued',heartbeat_at=NULL WHERE id=?")->execute([$assignment['action_id']]);
$receipt=$pdo->query('SELECT id,result_json FROM agent_workflow_receipts ORDER BY id DESC LIMIT 1')->fetch();
$modifyReceipt=$pdo->prepare('UPDATE agent_workflow_receipts SET result_json=? WHERE id=?');
$modifyReceipt->execute(['{"effect_state":"idle","jobs":0}',$receipt['id']]);
runtime_check(vp3_initiative_worker_health_v1($pdo,$user,0,42)['last_assessment']===null,
    'a mismatched receipt cannot falsely report an idle assessment');
$modifyReceipt->execute([$receipt['result_json'],$receipt['id']]);
$pdo->failAssessmentRead=true;
try{
    $degraded=vp3_initiative_worker_health_v1($pdo,$user,0,42);
    $degradedEngagement=$engagement();
    runtime_check($degraded['enabled']&&$degraded['pump_available']&&$degraded['execution_ready']
        &&$degraded['assessment_read']==='unavailable'
        &&$degraded['assessment_diagnostic']===['code'=>'storage_error','sqlstate'=>'HY000','database_code'=>1525]
        &&$degradedEngagement['tick_checked']&&$degradedEngagement['latest_tick']===$queued['latest_tick']
        &&$degradedEngagement['latest_preparation']['run_id']===$assignment['run_id']
        &&!str_contains(json_encode($degraded,JSON_THROW_ON_ERROR),'PRIVATE'),
        'an assessment read error preserves verified settings, execution readiness and Chat engagement with safe diagnostics');
    $prepared=vp3_initiative_pump_v1($pdo,$user,0,42,$policy['revision'],$model);
}finally{
    $pdo->failAssessmentRead=false;
}
runtime_check($prepared['ok']&&$calls===2&&(int)$pdo->query('SELECT COUNT(*) FROM agent_workflow_receipts')->fetchColumn()===2,
    'the bounded pump executes the queued preparation through canonical receipts even while assessment history is unavailable');
$recovered=vp3_initiative_worker_health_v1($pdo,$user,0,42);
runtime_check($recovered['assessment_read']==='complete'&&$recovered['last_assessment']===$assessment
    &&!isset($recovered['assessment_diagnostic']),'the next successful read restores the verified assessment without changing Initiative settings');
$finished=$engagement();
runtime_check(($finished['latest_preparation']['status']??null)==='completed'
    &&in_array($finished['latest_preparation']['delivery']??null,['pending','delivered'],true)
    &&($finished['latest_preparation']['delivery']==='delivered')===($finished['last_post']!==null)
    &&!str_contains(json_encode($finished,JSON_THROW_ON_ERROR),'launch review'),
    'debug distinguishes a saved preparation from a posted result and omits private text');
$quiet=vp3_initiative_pump_v1($pdo,$user,0,42,$policy['revision'],$model);
runtime_check($quiet['reason']==='no_work'&&$quiet['health']['active_jobs']===[]&&$calls===2,
    'completed leases disappear and repeated active Chat polls do not repeat inference for unchanged sources');
$pdo->exec("INSERT INTO agent_workflow_runs(owner_user_id,agent_id,source_kind,execution_target,capability_key,status)
    VALUES(1,NULL,'image_generation','cloud','images.generate','approved')");
$imageRun=(int)$pdo->lastInsertId();
runtime_check(vp3_initiative_pump_v1($pdo,$user,0,42,$policy['revision'],$model)['reason']==='no_work'
    &&$pdo->query('SELECT status FROM agent_workflow_runs WHERE id='.$imageRun)->fetchColumn()==='approved'&&$calls===2,
    'the browser pump cannot claim unrelated paid image or public conversation work');
try{vp3_initiative_pump_v1($pdo,$user,0,42,str_repeat('a',32),$model);throw new LogicException('Stale revision accepted');}
catch(RuntimeException $e){runtime_check($e->getCode()===409&&$calls===2,'a stale tab cannot start work under a replaced policy revision');}
$otherOwner=vp3_initiative_worker_health_v1($pdo,['id'=>2,'is_active'=>1],0,42);
$otherAgent=vp3_initiative_worker_health_v1($pdo,$user,7,42);
runtime_check($otherOwner['state']==='unavailable'&&$otherOwner['active_jobs']===[]
    &&$otherAgent['state']==='unavailable'&&$otherAgent['active_jobs']===[],'account and Agent switches cannot read another exact conversation health');
$GLOBALS['VP3_PARTICIPANT_RESOURCE_SCOPE_V1']=['participant_id'=>8];
runtime_check(!vp3_initiative_worker_health_v1($pdo,$user,0,42)['configuration_ready'],'participant access cannot become owner background authority');
unset($GLOBALS['VP3_PARTICIPANT_RESOURCE_SCOPE_V1']);
$GLOBALS['runtimePlan']=['route'=>'blocked','effective_preference'=>'vp3_cloud','blocked'=>true,'cloud'=>['ready'=>false,'reason'=>'cloud_balance_exhausted']];
$quota=vp3_initiative_pump_v1($pdo,$user,0,42,$policy['revision'],$model);
runtime_check(!$quota['ok']&&$quota['health']['reason']==='quota_exhausted'&&$calls===2,'exhausted quota blocks foreground preparation before a model call');
$GLOBALS['runtimePlan']=['route'=>'homeserver_local','blocked'=>false,'effective_preference'=>'homeserver_only'];
runtime_check(vp3_initiative_worker_health_v1($pdo,$user,0,42)['reason']==='route_unavailable','HomeServer-only policy is reported without silently routing work to Cloud');
vp3_ai_service_save_rule_v1($pdo,'site',0,'initiative',['allowed_models'=>[]]);
$health=vp3_initiative_worker_health_v1($pdo,$user,0,42);
runtime_check(!$health['execution_ready']&&$health['policy_reason']==='service_model_forbidden',
    'owner health names the Initiative service policy blocker separately from the worker');
vp3_ai_service_save_rule_v1($pdo,'site',0,'initiative',[]);
$GLOBALS['runtimePlan']=['route'=>'homeserver','blocked'=>false,'effective_preference'=>'homeserver_only',
    'try_homeserver'=>true,'home'=>['supported'=>true],'cloud'=>['ready'=>false,'reason'=>'cloud_balance_exhausted']];
runtime_check(vp3_initiative_worker_health_v1($pdo,$user,0,42)['pump_available'],
    'an authorized canonical HomeServer inference adapter is allowed without requiring Cloud quota');
unset($GLOBALS['runtimePlan']);

$GLOBALS['runtimeCalendarTitle']='Preparation interrupted by a disconnect';
$pdo->exec("UPDATE agent_memory_items SET last_seen_at='2000-01-01 00:00:00' WHERE memory_type='initiative_sweep'");
vp3_initiative_schedule_v1($pdo,$user,0);
$pending=vp3_initiative_runtime_runs_v1($pdo,1,0,$policy,true);
$lost=agent_job_claim_run_v1900($pdo,$user,(int)$pending[0]['id'],'cloud',agent_worker_cloud_worker_id_v1910(1));
$pdo->prepare("UPDATE agent_workflow_runs SET lease_expires_at='2000-01-01 00:00:00' WHERE id=?")->execute([$lost['run_id']]);
$pdo->prepare("UPDATE agent_workflow_runs SET status='executing',lease_expires_at=? WHERE id=?")
    ->execute([gmdate('Y-m-d H:i:s',time()+240),$imageRun]);
runtime_check(vp3_initiative_worker_health_v1($pdo,$user,0,42)['active_jobs']===[],
    'status omits both an expired Initiative lease and a live unrelated image lease');
$pdo->prepare("UPDATE agent_workflow_runs SET status='executing',lease_expires_at='2000-01-01 00:00:00' WHERE id=?")->execute([$imageRun]);
$recovered=vp3_initiative_pump_v1($pdo,$user,0,42,$policy['revision'],$model);
runtime_check($recovered['health']['state']==='waiting'&&$recovered['health']['reason']==='retry_scheduled'
    &&$recovered['health']['retry_due_at']!==''&&$calls===2
    &&$pdo->query('SELECT status FROM agent_workflow_runs WHERE id='.$imageRun)->fetchColumn()==='executing',
    'a disconnected expired exact lease is recovered without mutating another job family');

$GLOBALS['runtimeCalendarTitle']='Preparation whose permission changes';
$pdo->exec("UPDATE agent_memory_items SET last_seen_at='2000-01-01 00:00:00' WHERE memory_type='initiative_sweep'");
$revoked=vp3_initiative_pump_v1($pdo,$user,0,42,$policy['revision'],static function()use($pdo,$user):string{
    vp3_initiative_chat_v1($pdo,$user,vp3_agent_chat_principal_v380($user,null),['conversation_id'=>42],'off',null);
    return '{"jobs":[]}';
});
runtime_check(!$revoked['ok']&&$revoked['reason']==='initiative_unconfirmed'&&$revoked['health']['state']==='paused'
    &&$revoked['health']['active_jobs']===[],
    'opt-out during inference prevents a late result from advancing or entering Chat');
runtime_check(preg_match('/^[a-f0-9]{32}$/D',$revoked['health']['policy_revision'])===1
    &&$revoked['health']['policy_revision']===vp3_initiative_policy_v1($user,0)['revision'],
    'an authenticated off policy exposes its current revision for safe settings comparison');
$policy=runtime_enable($user,0,42);
$stale=vp3_initiative_pump_v1($pdo,$user,0,42,$policy['revision'],static function():string{
    $GLOBALS['runtimeCalendarTitle']='Changed while the model was generating';
    return '{"jobs":[]}';
});
runtime_check(!$stale['ok']&&$stale['reason']==='assessment_sources_changed'
    &&$stale['health']['state']==='waiting'&&$stale['health']['reason']==='assessment_sources_changed'
    &&$stale['health']['retry_due_at']==='',
    'source changes withdraw the stale assessment and await a fresh scheduled review');
$pdo->exec("UPDATE agent_memory_items SET last_seen_at='2000-01-01 00:00:00' WHERE memory_type='initiative_sweep'");
$fresh=vp3_initiative_pump_v1($pdo,$user,0,42,$policy['revision'],static function(string $prompt):string{
    if(!str_contains($prompt,'Changed while the model was generating'))throw new LogicException('The review read the obsolete source.');
    return '{"jobs":[]}';
});
runtime_check($fresh['ok']&&$fresh['reason']==='completed','the next due review assesses the new source without replaying the stale run');

$GLOBALS['runtimeCalendarTitle']='Review with a provider outage';
$pdo->exec("UPDATE agent_memory_items SET last_seen_at='2000-01-01 00:00:00' WHERE memory_type='initiative_sweep'");
$providerFailure=vp3_initiative_pump_v1($pdo,$user,0,42,$policy['revision'],static function():string{
    throw vp3_initiative_provider_failure_v1(['ok'=>false,'status'=>401,'error'=>'PRIVATE_PROVIDER_MESSAGE']);
});
$providerDetail=$providerFailure['health']['failure_detail']??[];
runtime_check(!$providerFailure['ok']&&$providerFailure['reason']==='model_unavailable'
    &&$providerFailure['health']['reason']==='model_unavailable'
    &&$providerFailure['health']['execution_ready']&&$providerFailure['health']['state']==='failed'
    &&($providerDetail['model_diagnostic']??null)==='provider_authentication'
    &&($providerDetail['model_http_status']??null)===401&&($providerDetail['evidence']??null)==='receipt'
    &&!str_contains(json_encode($providerFailure,JSON_THROW_ON_ERROR),'PRIVATE_PROVIDER_MESSAGE'),
    'provider authentication failure remains explicit and terminal even when the configured route is otherwise ready');

$GLOBALS['runtimeCalendarTitle']='Legacy assessment with no stored provider reason';
$pdo->exec("UPDATE agent_memory_items SET last_seen_at='2000-01-01 00:00:00' WHERE memory_type='initiative_sweep'");
$legacyFailure=vp3_initiative_pump_v1($pdo,$user,0,42,$policy['revision'],
    static fn():never=>throw new RuntimeException('model_unavailable',503));
$legacyDetail=$legacyFailure['health']['failure_detail'];
$legacyRun=$legacyDetail['run_id'];
runtime_check($legacyDetail['stage']==='Assessment'&&!isset($legacyDetail['model_usage_state'])
    &&str_contains($legacyDetail['reason'],'only a general model error'),
    'a legacy Assessment receipt without provider evidence remains undiagnosed');
$pdo->exec("CREATE TABLE ai_execution_ledger(id INTEGER PRIMARY KEY,user_id INTEGER,agent_id INTEGER,conversation_id INTEGER,
    run_id INTEGER,provider TEXT)");
require_once dirname(__DIR__).'/includes/ai-service-usage-v1.php';
foreach(vp3_ai_service_usage_columns_v1() as $name=>$ddl)$pdo->exec('ALTER TABLE ai_execution_ledger ADD COLUMN '.$name.' '.$ddl);
$legacyMeter=$pdo->prepare("INSERT INTO ai_execution_ledger(user_id,agent_id,conversation_id,run_id,provider,service_key,
    service_stage,service_attempt_key,service_job_key,service_status,is_background)
    VALUES(?,NULL,?,?,'anthropic','initiative','vp3.initiative-plan.v1',?,?,?,1)");
$legacyKey=hash('sha256','run:'.$legacyRun);
$legacyMeter->execute([1,43,$legacyRun,hash('sha256','foreign-chat'),$legacyKey,'rejected']);
$legacyMeter->execute([2,42,$legacyRun,hash('sha256','foreign-owner'),$legacyKey,'rejected']);
$getFailure=static fn():array=>vp3_initiative_worker_health_v1($pdo,$user,0,42)['failure_detail'];
runtime_check($getFailure()===$legacyDetail,'another owner or Chat cannot supply provider evidence for a legacy Assessment');
$legacyMeter->execute([1,42,$legacyRun,hash('sha256','exact-rejection'),$legacyKey,'rejected']);
$rejected=$getFailure();
runtime_check(($rejected['model_provider']??null)==='anthropic'&&($rejected['model_usage_state']??null)==='rejected'
    &&str_contains($rejected['reason'],'provider rejected')&&!isset($rejected['model_http_status']),
    'exact legacy usage proves a provider rejection without inventing a status or billing cause');
$legacyMeter->execute([1,42,$legacyRun,hash('sha256','exact-unconfirmed'),$legacyKey,'unknown']);
$unconfirmed=$getFailure();
runtime_check(($unconfirmed['model_usage_state']??null)==='unconfirmed'
    &&str_contains($unconfirmed['reason'],'unconfirmed provider attempt'),
    'unknown usage prevents an old Assessment from being reported as a confirmed rejection');

$GLOBALS['runtimeCalendarTitle']='Assessment with malformed output';
$pdo->exec("UPDATE agent_memory_items SET last_seen_at='2000-01-01 00:00:00' WHERE memory_type='initiative_sweep'");
$badPlan='PRIVATE_BAD_PLAN';
$badPlanCalls=0;
$prepareCount=static fn():int=>(int)$pdo->query("SELECT COUNT(*) FROM agent_workflow_runs WHERE capability_key='initiative.prepare'")->fetchColumn();
$preparationsBeforeInvalid=$prepareCount();
$invalid=vp3_initiative_pump_v1($pdo,$user,0,42,$policy['revision'],static function()use($badPlan,&$badPlanCalls):string{
    $badPlanCalls++;
    return $badPlan;
});
$assessmentError=$invalid['health']['assessment_error']??[];
$failure=$invalid['health']['failure_detail']??[];
runtime_check(!$invalid['ok']&&$invalid['reason']==='assessment_unverified'&&$invalid['health']['state']==='failed'
    &&$invalid['health']['reason']==='assessment_unverified'&&$invalid['health']['retry_due_at']===''
    &&($assessmentError['code']??null)==='invalid_json'&&($assessmentError['output_bytes']??null)===strlen($badPlan)
    &&strtotime($assessmentError['at']??'')>0&&$assessmentError['is_historical']===false
    &&$assessmentError['run_id']===$failure['run_id']&&$badPlanCalls===1&&$prepareCount()===$preparationsBeforeInvalid
    &&!str_contains(json_encode($invalid),$badPlan),
    'malformed planner output exposes a safe verified diagnostic without retry, dispatch or model text');
runtime_check($invalid['health']['latest_assessment_run']['status']==='failed'
    &&$invalid['health']['latest_assessment_run']['run_id']===$failure['run_id']
    &&$invalid['health']['latest_assessment_run']['reason_code']==='assessment_unverified'
    &&$invalid['health']['last_assessment']['run_id']<$failure['run_id'],
    'a new failed Assessment remains current separately from the preceding verified success');
runtime_check(($failure['status']??null)==='failed'&&($failure['action_status']??null)==='failed'
    &&($failure['stage']??null)==='Assessment'&&($failure['phase']??null)==='Assessing and validating the plan'
    &&($failure['reason_code']??null)==='assessment_unverified'&&($failure['executor']??null)==='cloud'
    &&($failure['evidence']??null)==='receipt'&&($failure['is_latest_run']??null)===true
    &&($failure['attempt']??null)===1&&($failure['max_attempts']??null)===3&&strtotime($failure['at']??'')>0
    &&($invalid['health']['last_assessment']['outcome']??null)==='idle',
    'the exact failure identifies its action, stage, executor, and attempt independently of the prior verified plan');
$pdo->prepare('UPDATE agent_workflow_receipts SET created_at=? WHERE run_id=? AND action_id=?')
    ->execute([$wallClock,$failure['run_id'],$failure['action_id']]);
runtime_check(vp3_initiative_worker_health_v1($pdo,$user,0,42)['failure_detail']['at']===$assessmentError['at'],
    'a terminal failure displays verified UTC completion even when the receipt wall clock is offset');

$failedReceipt=$pdo->query("SELECT id,run_id,action_id,result_json FROM agent_workflow_receipts
    WHERE owner_user_id=1 AND error_class='assessment_unverified' ORDER BY id DESC LIMIT 1")->fetch();
$modifyAction=$pdo->prepare('UPDATE agent_workflow_actions SET result_json=? WHERE id=?');
$mismatched=str_replace('invalid_json','invalid_job',$failedReceipt['result_json']);
$modifyReceipt->execute([$mismatched,$failedReceipt['id']]);
runtime_check(vp3_initiative_worker_health_v1($pdo,$user,0,42)['assessment_error']===null,
    'a mismatched failure receipt cannot publish an assessment diagnostic');
$actionOnly=vp3_initiative_worker_health_v1($pdo,$user,0,42)['failure_detail'];
runtime_check($failure['run_id']===(int)$failedReceipt['run_id']&&$failure['action_id']===(int)$failedReceipt['action_id']
    &&$actionOnly['evidence']==='action'&&$actionOnly['phase']===null&&$actionOnly['executor']===null,
    'a receipt mismatch preserves the observed failed action without asserting an executor or phase');
$forged=str_replace(['invalid_json','"failure_phase":"assessment"','"failure_cause":"unconfirmed"'],
    ['PRIVATE_UNKNOWN_REASON','"failure_phase":"PRIVATE_UNKNOWN_PHASE"','"failure_cause":"PRIVATE_UNKNOWN_CAUSE"'],$failedReceipt['result_json']);
$modifyReceipt->execute([$forged,$failedReceipt['id']]);
$modifyAction->execute([$forged,$failedReceipt['action_id']]);
$forgedHealth=vp3_initiative_worker_health_v1($pdo,$user,0,42);
runtime_check($forgedHealth['assessment_error']===null&&$forgedHealth['failure_detail']['phase']===null
    &&!str_contains(json_encode($forgedHealth),'PRIVATE_UNKNOWN_'),
    'a matching failed action and receipt still cannot publish an unknown diagnostic');
$modifyReceipt->execute([$failedReceipt['result_json'],$failedReceipt['id']]);
$modifyAction->execute([$failedReceipt['result_json'],$failedReceipt['action_id']]);
runtime_check(vp3_initiative_worker_health_v1($pdo,$user,0,42)['assessment_error']===$assessmentError
    &&vp3_initiative_worker_health_v1($pdo,['id'=>2,'is_active'=>1],0,42)['assessment_error']===null
    &&vp3_initiative_worker_health_v1($pdo,$user,7,42)['assessment_error']===null,
    'assessment diagnostics stay within the exact account, Agent and Chat');
runtime_check(vp3_initiative_worker_health_v1($pdo,$user,0,42)['failure_detail']===$failure
    &&vp3_initiative_worker_health_v1($pdo,['id'=>2,'is_active'=>1],0,42)['failure_detail']===null
    &&vp3_initiative_worker_health_v1($pdo,$user,7,42)['failure_detail']===null
    &&vp3_initiative_worker_health_v1($pdo,$user,0,43)['failure_detail']===null,
    'failure details remain scoped to the exact owner, Agent, and saved Chat');

$GLOBALS['runtimeCalendarTitle']='Assessment with an invented source';
$pdo->exec("UPDATE agent_memory_items SET last_seen_at='2000-01-01 00:00:00' WHERE memory_type='initiative_sweep'");
$inventedSourcePlan='{"jobs":[{"tool":"work.prepare_private","primary":"calendar:event:42","refs":["calendar:event:42","PRIVATE_UNKNOWN_SOURCE"],"focus":"Prepare","report":true}]}';
$sourceRepairPrompts=[];$sourceRepairBudgets=[];
$badSource=vp3_initiative_pump_v1($pdo,$user,0,42,$policy['revision'],
    static function(string $prompt)use($inventedSourcePlan,&$sourceRepairPrompts,&$sourceRepairBudgets):string{
        $sourceRepairPrompts[]=json_decode($prompt,true,16,JSON_THROW_ON_ERROR);
        $sourceRepairBudgets[]=vp3_ai_budget_remaining_v1();
        return $inventedSourcePlan;
    });
runtime_check(!$badSource['ok']&&$badSource['reason']==='assessment_unverified'
    &&($badSource['health']['assessment_error']['code']??null)==='invalid_job_sources'
    &&($badSource['health']['assessment_error']['output_bytes']??null)===strlen($inventedSourcePlan)
    &&$prepareCount()===$preparationsBeforeInvalid&&count($sourceRepairPrompts)===2
    &&isset($sourceRepairPrompts[1]['source_repair'])&&!isset($sourceRepairPrompts[0]['source_repair'])
    &&array_diff_key($sourceRepairPrompts[1],['source_repair'=>true])===$sourceRepairPrompts[0]
    &&$sourceRepairBudgets[0]>0&&$sourceRepairBudgets[1]>0&&$sourceRepairBudgets[1]<=$sourceRepairBudgets[0]
    &&!str_contains(json_encode([$badSource,$sourceRepairPrompts]),'PRIVATE_UNKNOWN_SOURCE'),
    'invalid sources get one fresh assessment within the same budget, with no copied model text or queued work');
$GLOBALS['runtimeCalendarTitle']='Corrected source after a prior rejected assessment';
$pdo->exec("UPDATE agent_memory_items SET last_seen_at='2000-01-01 00:00:00' WHERE memory_type='initiative_sweep'");
$verifiedAfterRejection=vp3_initiative_pump_v1($pdo,$user,0,42,$policy['revision'],static fn():string=>'{"jobs":[]}');
runtime_check($verifiedAfterRejection['ok']&&$verifiedAfterRejection['health']['last_assessment']['outcome']==='idle'
    &&($verifiedAfterRejection['health']['assessment_error']['is_historical']??null)===true
    &&$verifiedAfterRejection['health']['assessment_error']['run_id']===$badSource['health']['assessment_error']['run_id'],
    'a verified later assessment preserves and labels the earlier rejection without making it current');
$verifiedRun=$verifiedAfterRejection['health']['last_assessment'];
runtime_check($verifiedAfterRejection['health']['latest_assessment_run']===
    ['run_id'=>$verifiedRun['run_id'],'status'=>'completed','at'=>$verifiedRun['at']],
    'a newer verified Assessment is current while its prior rejection remains historical');

$namedPolicy=runtime_enable($user,7,11);
$named=vp3_initiative_worker_health_v1($pdo,$user,7,11);
runtime_check($named['enabled']&&$named['policy_revision']===$namedPolicy['revision']&&$named['agent_id']===7
    &&$named['assessment_error']===null&&end($GLOBALS['runtimeRoutes'])===[1,7],
    'named Agent health uses its exact persisted opt-in and compute route without System diagnostics');
$temp=sys_get_temp_dir().'/vp3-runner-health-'.bin2hex(random_bytes(6));mkdir($temp,0700);
try{
    runtime_check(agent_worker_health_read_v1($temp,2000)['state']==='never_seen','a configured schema does not invent a runner heartbeat');
    runtime_check(agent_worker_health_observe_v1(true,$temp,1000)&&agent_worker_health_read_v1($temp,1100)['state']==='fresh',
        'a successful actual sweep records fresh runner evidence');
    runtime_check(agent_worker_health_read_v1($temp,1400)['state']==='stale','old process evidence becomes stale');
    agent_worker_health_observe_v1(false,$temp,1450);$failed=agent_worker_health_read_v1($temp,1500);
    runtime_check($failed['state']==='failed'&&strtotime($failed['last_success_at'])===1000,'failed sweeps preserve the last actual success without appearing healthy');
}finally{@unlink(agent_worker_health_path_v1($temp));@rmdir($temp);}

// A real canonical receipt/backoff/claim sequence. Only provider HTTP results
// and the passage of due timestamps are controlled; no model spend or sleeps.
$reportBaseline=(int)$pdo->query("SELECT COUNT(*) FROM chat_messages WHERE role='assistant' AND JSON_EXTRACT(CASE WHEN JSON_VALID(context_json) THEN context_json ELSE '{}' END,'$.source')='agent_initiative'")->fetchColumn();
$pdo->exec("CREATE TABLE agent_activity_state(user_id INTEGER PRIMARY KEY,surface TEXT,context_key TEXT,activity_state TEXT,
    last_heartbeat_at TEXT,idle_since_at TEXT,details_json TEXT);
    CREATE TABLE agent_activity_events(id INTEGER PRIMARY KEY);
    INSERT INTO agent_activity_state VALUES(1,'chat','chat:42','paused',NOW(),NULL,
    '{\"visible\":true,\"conversation_id\":42,\"chat_focused\":true,\"chat_busy\":false}')");
$GLOBALS['runtimeCalendarTitle']='Useful preparation after a transient provider failure';
$pdo->exec("UPDATE agent_memory_items SET last_seen_at='2000-01-01 00:00:00' WHERE memory_type='initiative_sweep'");
$plannedRetry=vp3_initiative_pump_v1($pdo,$user,0,42,$policy['revision'],static function(string $prompt):string{
    $input=json_decode($prompt,true,16,JSON_THROW_ON_ERROR);$ref=$input['evidence'][0]['ref'];
    return json_encode(['jobs'=>[['tool'=>'work.prepare_private','primary'=>$ref,'refs'=>[$ref],
        'focus'=>'Prepare a useful launch next step','report'=>true]]],JSON_THROW_ON_ERROR);
});
runtime_check($plannedRetry['ok'],'retry scenario is planned through the production queue');
$prepareRuns=array_filter(vp3_initiative_runtime_runs_v1($pdo,1,0,$policy,true),static fn(array $row):bool=>$row['capability_key']==='initiative.prepare');
$retryRun=(int)array_values($prepareRuns)[0]['id'];
$actionId=(int)$pdo->query('SELECT id FROM agent_workflow_actions WHERE run_id='.$retryRun)->fetchColumn();
$retryCalls=0;
$retryModel=static function()use(&$retryCalls):string{
    if(++$retryCalls===1)throw vp3_initiative_provider_failure_v1(['ok'=>false,'status'=>503]);
    return '{"draft":"I prepared the current launch questions after the temporary provider failure cleared."}';
};
$transient=vp3_initiative_pump_v1($pdo,$user,0,42,$policy['revision'],$retryModel);
$retryDetail=$transient['health']['failure_detail'];
runtime_check($retryDetail['run_id']===$retryRun&&$retryDetail['action_id']===$actionId&&$retryDetail['status']==='approved'
    &&$retryDetail['action_status']==='queued'&&$retryDetail['evidence']==='receipt'&&$retryDetail['reason_code']==='model_unavailable'
    &&$retryDetail['retry_at']===$transient['health']['retry_due_at']&&$retryDetail['attempt']===1
    &&($retryDetail['model_diagnostic']??null)==='provider_interruption'&&($retryDetail['model_http_status']??null)===503,
    'a scheduled retry identifies the exact failed attempt and its next eligible time');
$run=$pdo->query('SELECT * FROM agent_workflow_runs WHERE id='.$retryRun)->fetch();
$receipt=$pdo->query('SELECT status,attempt_no FROM agent_workflow_receipts WHERE run_id='.$retryRun.' ORDER BY id')->fetchAll();
runtime_check(!$transient['ok']&&$transient['reason']==='model_unavailable'&&$run['status']==='approved'
    &&$run['attempt_count']===1&&$run['max_attempts']===3&&$receipt===[['status'=>'retry_scheduled','attempt_no'=>1]]
    &&(strtotime($run['next_attempt_at'])?:0)>=time()+58&&$transient['health']['reason']==='retry_scheduled',
    'a real 503 creates a canonical retry receipt and 60 second backoff on the same job');
$beforeDue=vp3_initiative_pump_v1($pdo,$user,0,42,$policy['revision'],$retryModel);
runtime_check($beforeDue['reason']==='retry_scheduled'&&$retryCalls===1,
    'another browser tab cannot claim or infer during the canonical backoff');
$pdo->prepare("UPDATE agent_workflow_runs SET next_attempt_at='2000-01-01 00:00:00' WHERE id=?")->execute([$retryRun]);
$pdo->prepare("UPDATE agent_workflow_actions SET available_at='2000-01-01 00:00:00' WHERE id=?")->execute([$actionId]);
$recovered=vp3_initiative_pump_v1($pdo,$user,0,42,$policy['revision'],$retryModel);
$row=$pdo->query('SELECT id,status,attempt_count FROM agent_workflow_actions WHERE run_id='.$retryRun)->fetch();
$receipt=$pdo->query('SELECT status,attempt_no FROM agent_workflow_receipts WHERE run_id='.$retryRun.' ORDER BY id')->fetchAll();
$reports=static fn():int=>(int)$pdo->query("SELECT COUNT(*) FROM chat_messages WHERE role='assistant' AND JSON_EXTRACT(CASE WHEN JSON_VALID(context_json) THEN context_json ELSE '{}' END,'$.source')='agent_initiative'")->fetchColumn();
runtime_check($recovered['ok']&&$retryCalls===2&&$row===['id'=>$actionId,'status'=>'completed','attempt_count'=>2]
    &&$receipt===[['status'=>'retry_scheduled','attempt_no'=>1],['status'=>'completed','attempt_no'=>2]]&&$reports()===$reportBaseline+1,
    'the same action succeeds once after the due time and delivers exactly one verified Chat report');
vp3_initiative_pump_v1($pdo,$user,0,42,$policy['revision'],$retryModel);
runtime_check($retryCalls===2&&$reports()===$reportBaseline+1,'a completed recovered action never repeats inference or delivery');

$GLOBALS['runtimeCalendarTitle']='A repeatedly unavailable provider';
$pdo->exec("UPDATE agent_memory_items SET last_seen_at='2000-01-01 00:00:00' WHERE memory_type='initiative_sweep'");
$exhaustedCalls=0;
$alwaysUnavailable=static function()use(&$exhaustedCalls):string{
    $exhaustedCalls++;throw vp3_initiative_provider_failure_v1(['ok'=>false,'status'=>503]);
};
for($attempt=1;$attempt<=3;$attempt++){
    vp3_initiative_pump_v1($pdo,$user,0,42,$policy['revision'],$alwaysUnavailable);
    $lastRun=(int)$pdo->query("SELECT MAX(id) FROM agent_workflow_runs WHERE source_kind='agent_initiative' AND agent_id IS NULL")->fetchColumn();
    $pdo->prepare("UPDATE agent_workflow_runs SET next_attempt_at='2000-01-01 00:00:00' WHERE id=? AND status='approved'")->execute([$lastRun]);
    $pdo->prepare("UPDATE agent_workflow_actions SET available_at='2000-01-01 00:00:00' WHERE run_id=? AND status='queued'")->execute([$lastRun]);
}
$exhausted=vp3_initiative_pump_v1($pdo,$user,0,42,$policy['revision'],$alwaysUnavailable);
$statuses=$pdo->query('SELECT status FROM agent_workflow_receipts WHERE run_id='.$lastRun.' ORDER BY id')->fetchAll(PDO::FETCH_COLUMN);
runtime_check($exhaustedCalls===3&&$statuses===['retry_scheduled','retry_scheduled','failed']
    &&$exhausted['health']['state']==='failed'&&$reports()===$reportBaseline+1,'transient failures stop after three actual canonical attempts');

$GLOBALS['runtimeCalendarTitle']='A provider quota refusal is terminal';
$pdo->exec("UPDATE agent_memory_items SET last_seen_at='2000-01-01 00:00:00' WHERE memory_type='initiative_sweep'");
$quotaFailure=vp3_initiative_pump_v1($pdo,$user,0,42,$policy['revision'],static function():string{
    throw vp3_initiative_provider_failure_v1(['ok'=>false,'status'=>503,'quota_exhausted'=>true]);
});
runtime_check(!$quotaFailure['ok']&&$quotaFailure['reason']==='quota_exhausted'
    &&$quotaFailure['health']['state']==='failed'&&$quotaFailure['health']['retry_due_at']==='',
    'a quota refusal never becomes an automatic retry even when its HTTP status is transient');

$GLOBALS['runtimeCalendarTitle']='Provider interruption concurrent with owner opt-out';
$pdo->exec("UPDATE agent_memory_items SET last_seen_at='2000-01-01 00:00:00' WHERE memory_type='initiative_sweep'");
$revokedRetry=vp3_initiative_pump_v1($pdo,$user,0,42,$policy['revision'],static function()use($pdo,$user):string{
    vp3_initiative_chat_v1($pdo,$user,vp3_agent_chat_principal_v380($user,null),['conversation_id'=>42],'off',null);
    throw vp3_initiative_provider_failure_v1(['ok'=>false,'status'=>503]);
});
$lastReceipt=$pdo->query('SELECT status FROM agent_workflow_receipts ORDER BY id DESC LIMIT 1')->fetchColumn();
runtime_check(!$revokedRetry['ok']&&$revokedRetry['reason']==='initiative_unconfirmed'&&$lastReceipt==='failed'
    &&$revokedRetry['health']['state']==='paused','a concurrent owner opt-out prevents even a genuine 503 from scheduling another attempt');

$policy=runtime_enable($user,0,42);
$GLOBALS['runtimeCalendarTitle']='Source checked before assessment repair';
$pdo->exec("UPDATE agent_memory_items SET last_seen_at='2000-01-01 00:00:00' WHERE memory_type='initiative_sweep'");
$beforeRepair=$prepareCount();$staleRepairCalls=0;
$staleRepair=vp3_initiative_pump_v1($pdo,$user,0,42,$policy['revision'],
    static function()use($inventedSourcePlan,&$staleRepairCalls):string{
        $staleRepairCalls++;
        $GLOBALS['runtimeCalendarTitle']='Updated preparation after a source change';
        return $inventedSourcePlan;
    });
runtime_check(!$staleRepair['ok']&&$staleRepair['reason']==='assessment_sources_changed'
    &&$staleRepairCalls===1&&$prepareCount()===$beforeRepair,
    'a changed source withdraws the stale plan before a second model call');

$pdo->exec("UPDATE agent_memory_items SET last_seen_at='2000-01-01 00:00:00' WHERE memory_type='initiative_sweep'");
$repairCalls=0;
$repaired=vp3_initiative_pump_v1($pdo,$user,0,42,$policy['revision'],
    static function(string $prompt)use($inventedSourcePlan,&$repairCalls):string{
        $repairCalls++;
        if($repairCalls===1)return $inventedSourcePlan;
        if(!str_contains($prompt,'Updated preparation after a source change'))throw new LogicException('Missing current evidence.');
        return '{"jobs":[{"tool":"work.prepare_private","primary":"calendar:event:42","refs":["calendar:event:42"],"focus":"Review this calendar event","report":false}]}';
    });
$repairedGoal=json_decode($pdo->query("SELECT goal FROM agent_workflow_runs WHERE capability_key='initiative.prepare' ORDER BY id DESC LIMIT 1")->fetchColumn(),true);
runtime_check($repaired['ok']&&$repaired['reason']==='completed'&&$repaired['health']['assessment_error']===null
    &&$repairCalls===2&&$prepareCount()===$beforeRepair+1&&$repairedGoal['primary']==='calendar:event:42'
    &&array_keys($repairedGoal['sources'])===['calendar:event:42'],
    'a valid second assessment clears the diagnostic and queues exactly one preparation for the current source');
$privatePreparation=vp3_initiative_pump_v1($pdo,$user,0,42,$policy['revision'],static function(string $prompt,string $protocol):string{
    if($protocol!=='vp3.initiative-draft.v1')throw new LogicException('Expected the queued preparation.');
    return '{"draft":"I reviewed the saved calendar event and prepared the next steps privately."}';
});
runtime_check($privatePreparation['ok']&&$privatePreparation['reason']==='completed'&&$reports()===$reportBaseline+1,
    'the corrected assignment runs once and stays private as planned');

$GLOBALS['runtimeCalendarTitle']='Preparation with an invalid private draft';
$pdo->exec("UPDATE agent_memory_items SET last_seen_at='2000-01-01 00:00:00' WHERE memory_type='initiative_sweep'");
$planWithDraft=vp3_initiative_pump_v1($pdo,$user,0,42,$policy['revision'],static fn():string=>
    '{"jobs":[{"tool":"work.prepare_private","primary":"calendar:event:42","refs":["calendar:event:42"],"focus":"Review this calendar event","report":true}]}');
$badDraft=vp3_initiative_pump_v1($pdo,$user,0,42,$policy['revision'],static fn():string=>'{"draft":"<PRIVATE_BAD_DRAFT>"}');
$draftDetail=$badDraft['health']['failure_detail'];
runtime_check($planWithDraft['ok']&&!$badDraft['ok']&&$badDraft['health']['reason']==='review_failed'
    &&$draftDetail['stage']==='Private preparation'&&$draftDetail['phase']==='Validating the result'
    &&$draftDetail['reason_code']==='invalid_data'&&$draftDetail['evidence']==='receipt'
    &&$badDraft['health']['last_assessment']['outcome']==='assignments_queued'
    &&!str_contains(json_encode($badDraft['health']),'PRIVATE_BAD_DRAFT')&&$reports()===$reportBaseline+1,
    'a verified assessment can queue work that later fails draft validation without exposing the draft');

$GLOBALS['runtimeCalendarTitle']='A saved report waiting during a model outage';
$pdo->exec("UPDATE agent_memory_items SET last_seen_at='2000-01-01 00:00:00' WHERE memory_type='initiative_sweep'");
$pdo->exec("UPDATE agent_activity_state SET details_json=JSON_SET(details_json,'$.chat_busy',JSON('true')) WHERE user_id=1");
$countBefore=$reports();
$savedPlan=vp3_initiative_pump_v1($pdo,$user,0,42,$policy['revision'],static fn():string=>
    '{"jobs":[{"tool":"work.prepare_private","primary":"calendar:event:42","refs":["calendar:event:42"],"focus":"Prepare the verified outage handoff","report":true}]}');
$saved=vp3_initiative_pump_v1($pdo,$user,0,42,$policy['revision'],static fn():string=>
    '{"draft":"I prepared the verified calendar handoff for this Chat."}',allowDelivery:false);
runtime_check($savedPlan['ok']&&$saved['ok']&&count(vp3_initiative_pending_results_v1($pdo,1,null))===1
    &&$reports()===$countBefore,
    'the real worker saves a pending completed preparation when delivery is explicitly deferred');
$pdo->exec("INSERT INTO chat_messages(conversation_id,user_id,role,message,created_at)
    VALUES(42,1,'user','A direct Chat turn was interrupted',CURRENT_TIMESTAMP)");
$interruptedId=(int)$pdo->lastInsertId();
$pdo->exec("UPDATE agent_activity_state SET last_heartbeat_at=CURRENT_TIMESTAMP WHERE user_id=1");
$GLOBALS['runtimePlan']=['route'=>'blocked','blocked'=>true,'effective_preference'=>'vp3_cloud',
    'cloud'=>['ready'=>false,'reason'=>'cloud_balance_exhausted']];
$outageCalls=0;
$noInference=static function()use(&$outageCalls): never{
    ++$outageCalls;
    throw new LogicException('Saved handoff must never invoke a model.');
};
$deferred=vp3_initiative_pump_v1($pdo,$user,0,42,$policy['revision'],$noInference,allowDelivery:false);
runtime_check(!$deferred['ok']&&$deferred['health']['pump_available']&&!$deferred['health']['execution_ready']
    &&$deferred['health']['reason']==='quota_exhausted'&&$reports()===$countBefore
    &&count(vp3_initiative_pending_results_v1($pdo,1,null))===1&&$outageCalls===0,
    'explicitly deferred delivery retains the saved receipt while canonical model readiness reports the outage');
$timezone=date_default_timezone_get();
date_default_timezone_set('America/Phoenix');
try{
    $delivery=vp3_initiative_pump_v1($pdo,$user,0,42,$policy['revision'],$noInference);
    $again=vp3_initiative_pump_v1($pdo,$user,0,42,$policy['revision'],$noInference);
    $pdo->prepare("UPDATE chat_messages SET created_at='2000-01-01 00:00:00' WHERE id=?")->execute([$interruptedId]);
    $pdo->exec("UPDATE agent_activity_state SET details_json=JSON_SET(details_json,'$.chat_busy',JSON('false')) WHERE user_id=1");
    $quietAgain=vp3_initiative_pump_v1($pdo,$user,0,42,$policy['revision'],$noInference);
    $outageHealth=vp3_initiative_worker_health_v1($pdo,$user,0,42);
}finally{date_default_timezone_set($timezone);unset($GLOBALS['runtimePlan']);}
runtime_check($delivery['ok']&&$delivery['reason']==='completed'&&$delivery['step']==='saved_delivery'
    &&$outageHealth['reason']==='quota_exhausted'&&!$outageHealth['execution_ready']
    &&!$again['ok']&&!$quietAgain['ok']&&$outageCalls===0&&$reports()===$countBefore+1
    &&vp3_initiative_pending_results_v1($pdo,1,null)===[],
    'saved timestamped text delivers exactly once during busy Chat and a recent direct turn without invoking the unavailable model');
$debugPost=$engagement()['last_post'];
runtime_check($debugPost['proof']==='receipt'&&$debugPost['source']==='initiative'&&$debugPost['result']==='report'
    &&$debugPost['specialist']===null&&!$debugPost['includes_gmail']&&$debugPost['message_id']>0&&$debugPost['run_id']>0,
    'debug distinguishes a verified non-Gmail report with an exact receipt from an unspecified Chat message');
$postReceipt=$pdo->prepare('SELECT id,result_json FROM agent_workflow_receipts WHERE run_id=? ORDER BY id DESC LIMIT 1');
$postReceipt->execute([$debugPost['run_id']]);
$savedReceipt=$postReceipt->fetch();
$modifyReceipt->execute(['{}',$savedReceipt['id']]);
$unverifiedPost=$engagement()['last_post'];
$modifyReceipt->execute([$savedReceipt['result_json'],$savedReceipt['id']]);
runtime_check($unverifiedPost['message_id']===$debugPost['message_id']&&$unverifiedPost['proof']==='saved_message'
    &&$unverifiedPost['run_id']===null&&$unverifiedPost['specialist']===null&&$unverifiedPost['result']===null
    &&$unverifiedPost['includes_gmail']===null,
    'debug shows the saved Chat message without attributing it to a specialist if its worker receipt differs');

$GLOBALS['runtimeCalendarTitle']='Calendar evidence temporarily unavailable';
$calendarRef='calendar:event:42';
vp3_initiative_in_scope_v1($user,0,static fn()=>vp3_initiative_schedule_scoped_v1($pdo,$user,[$calendarRef]));
$sourceRun=(int)$pdo->query("SELECT MAX(id) FROM agent_workflow_runs WHERE owner_user_id=1
    AND agent_id IS NULL AND capability_key='initiative.plan'")->fetchColumn();
$sourceState=$pdo->prepare('SELECT status,attempt_count FROM agent_workflow_runs WHERE id=?');
$readSourceState=static function(int $runId)use($sourceState): array{
    $sourceState->execute([$runId]);
    return $sourceState->fetch(PDO::FETCH_ASSOC);
};
$sourceClaim=static fn(int $runId):array=>agent_job_claim_run_v1900($pdo,$user,$runId,'cloud',agent_worker_cloud_worker_id_v1910(1),180)
    ??throw new RuntimeException('Expected to claim the exact source review.');
$GLOBALS['runtimeCalendarUnavailable']=true;
$sourceAttempts=0;
$sourceModel=static function()use(&$sourceAttempts): string{$sourceAttempts++;return '{"jobs":[]}';};
$proofFailure=vp3_initiative_execute_v1($pdo,$user,$sourceClaim($sourceRun),$sourceModel);
$sourceHealth=vp3_initiative_worker_health_v1($pdo,$user,0,42);
runtime_check(!$proofFailure['ok']&&$proofFailure['reason']==='source_unavailable'
    &&$readSourceState($sourceRun)===['status'=>'approved','attempt_count'=>1]&&$sourceAttempts===0
    &&$sourceHealth['reason']==='retry_scheduled'&&($sourceHealth['failure_detail']['phase']??null)==='Checking source records'
    &&($sourceHealth['failure_detail']['reason_code']??null)==='source_unavailable'
    &&($sourceHealth['failure_detail']['source_family']??null)==='calendar'
    &&($sourceHealth['failure_detail']['source_coverage']??null)==='unavailable'
    &&!str_contains(json_encode($sourceHealth['failure_detail'],JSON_THROW_ON_ERROR),$calendarRef),
    'an unavailable calendar proof schedules the same assessment and identifies the source family without source IDs');
runtime_check($sourceHealth['latest_assessment_run']['run_id']===$sourceRun
    &&$sourceHealth['latest_assessment_run']['status']==='retry_scheduled'
    &&$sourceHealth['latest_assessment_run']['reason_code']==='source_unavailable'
    &&$sourceHealth['latest_assessment_run']['retry_at']===$sourceHealth['retry_due_at'],
    'current Assessment shows the exact retry time and reason independently of its prior verified result');
unset($GLOBALS['runtimeCalendarUnavailable']);
$pdo->prepare("UPDATE agent_workflow_runs SET next_attempt_at='2000-01-01 00:00:00' WHERE id=?")->execute([$sourceRun]);
$pdo->prepare("UPDATE agent_workflow_actions SET available_at='2000-01-01 00:00:00' WHERE run_id=?")->execute([$sourceRun]);
$proofRestored=vp3_initiative_execute_v1($pdo,$user,$sourceClaim($sourceRun),$sourceModel);
runtime_check($proofRestored['ok']&&$readSourceState($sourceRun)===['status'=>'completed','attempt_count'=>2]&&$sourceAttempts===1,
    'a restored source proof completes the original run with exactly one model call');

$GLOBALS['runtimeCalendarTitle']='Calendar proof unavailable after inference';
vp3_initiative_in_scope_v1($user,0,static fn()=>vp3_initiative_schedule_scoped_v1($pdo,$user,[$calendarRef]));
$postInferenceRun=(int)$pdo->query("SELECT MAX(id) FROM agent_workflow_runs WHERE owner_user_id=1
    AND agent_id IS NULL AND capability_key='initiative.plan'")->fetchColumn();
$postInferenceFailure=vp3_initiative_execute_v1($pdo,$user,$sourceClaim($postInferenceRun),static function(): string{
    $GLOBALS['runtimeCalendarUnavailable']=true;
    return '{"jobs":[]}';
});
unset($GLOBALS['runtimeCalendarUnavailable']);
runtime_check(!$postInferenceFailure['ok']&&$postInferenceFailure['reason']==='source_unavailable'
    &&$readSourceState($postInferenceRun)===['status'=>'failed','attempt_count'=>1],
    'lost verification after completed inference withholds the result without rescheduling paid model work');
$sourceReceipt=$pdo->prepare('SELECT id,result_json FROM agent_workflow_receipts WHERE run_id=? ORDER BY id DESC LIMIT 1');
$sourceReceipt->execute([$postInferenceRun]);
$terminalReceipt=$sourceReceipt->fetch();
$terminalProof=json_decode($terminalReceipt['result_json'],true,16,JSON_THROW_ON_ERROR);
$modifyReceipt->execute([json_encode(array_replace($terminalProof,['source_family'=>'forged_source_family']),JSON_THROW_ON_ERROR),$terminalReceipt['id']]);
$mismatchedProof=vp3_initiative_worker_health_v1($pdo,$user,0,42)['failure_detail'];
runtime_check(!isset($mismatchedProof['source_family'],$mismatchedProof['source_coverage'])&&$mismatchedProof['evidence']==='action',
    'a conflicting receipt cannot supply either source proof diagnostic');
$modifyAction=$pdo->prepare('UPDATE agent_workflow_actions SET result_json=? WHERE run_id=?');
foreach([array_diff_key($terminalProof,array_flip(['source_family','source_coverage'])),
    array_replace($terminalProof,['source_family'=>$calendarRef,'source_coverage'=>'raw value']),
] as $oldProof){
    $data=json_encode($oldProof,JSON_THROW_ON_ERROR);
    $modifyReceipt->execute([$data,$terminalReceipt['id']]);
    $modifyAction->execute([$data,$postInferenceRun]);
    $historicalProof=vp3_initiative_worker_health_v1($pdo,$user,0,42)['failure_detail'];
    runtime_check(($historicalProof['reason_code']??null)==='source_unavailable'&&$historicalProof['evidence']==='receipt'
        &&!isset($historicalProof['source_family'],$historicalProof['source_coverage']),
        'legacy or unrecognized source diagnostic fields leave the original failure intact without inferring a source');
}
$modifyReceipt->execute([$terminalReceipt['result_json'],$terminalReceipt['id']]);
$modifyAction->execute([$terminalReceipt['result_json'],$postInferenceRun]);

$GLOBALS['runtimeCalendarTitle']='Unverified internal 503';
vp3_initiative_in_scope_v1($user,0,static fn()=>vp3_initiative_schedule_scoped_v1($pdo,$user,[$calendarRef]));
$genericRun=(int)$pdo->query("SELECT MAX(id) FROM agent_workflow_runs WHERE owner_user_id=1
    AND agent_id IS NULL AND capability_key='initiative.plan'")->fetchColumn();
$genericFailure=vp3_initiative_execute_v1($pdo,$user,$sourceClaim($genericRun),
    static fn(): never=>throw new RuntimeException('PRIVATE_UNTYPED_INTERNAL_FAILURE',503));
runtime_check(!$genericFailure['ok']&&$genericFailure['reason']==='initiative_unconfirmed'
    &&$readSourceState($genericRun)===['status'=>'failed','attempt_count'=>1],
    'an arbitrary 503 is terminal and does not impersonate the verified source retry');

$pdo->exec("CREATE TABLE notifications(id INTEGER PRIMARY KEY,user_id INTEGER,type TEXT,title TEXT,body TEXT,
    target_url TEXT,source_type TEXT,source_id INTEGER,is_read INTEGER,created_at TEXT);
    CREATE TABLE agent_chat_archive(id INTEGER PRIMARY KEY,user_id INTEGER,conversation_id INTEGER,
        source_message_id INTEGER,role TEXT,created_at TEXT);
    CREATE TABLE agent_event_inbox(id INTEGER PRIMARY KEY,owner_user_id INTEGER,source TEXT,event_type TEXT,
        verification_status TEXT,payload_json TEXT,occurred_at TEXT,received_at TEXT)");
$insertActivity=$pdo->prepare("INSERT INTO notifications VALUES(?,1,'account_update',?,'PRIVATE_ACTIVITY_BODY','',
    'account',1,0,?)");
$insertActivity->execute([1,'PRIVATE_DISPLACED_RECENT_ACTIVITY',gmdate('Y-m-d H:i:s',time()-600)]);
$activityNow=new DateTimeImmutable('now',new DateTimeZone('UTC'));
$coverage=[];
$activity=vp3_initiative_evidence_v1($pdo,$user,$activityNow,coverage:$coverage,agentId:0);
$activityRefs=array_keys(array_filter($activity,static fn(array $row):bool=>$row['kind']==='activity_situation'));
$activityRef=$activityRefs[0]??'';
runtime_check(count($activityRefs)===1&&$coverage['activity_situations']==='ready',
    'the actual source reader initially verifies the saved Recent activity item');
$queueMixedAssessment=static function(string $calendarTitle,string $ref)use($pdo,$user,$calendarRef):int{
    $GLOBALS['runtimeCalendarTitle']=$calendarTitle;
    vp3_initiative_in_scope_v1($user,0,static fn()=>vp3_initiative_schedule_scoped_v1($pdo,$user,[$calendarRef,$ref]));
    $q=$pdo->query("SELECT id,goal FROM agent_workflow_runs WHERE owner_user_id=1
        AND agent_id IS NULL AND capability_key='initiative.plan' ORDER BY id DESC LIMIT 1");
    $run=$q->fetch();
    $goal=json_decode($run['goal'],true,16,JSON_THROW_ON_ERROR);
    runtime_check(count($goal['sources'])===2&&isset($goal['sources'][$calendarRef],$goal['sources'][$ref]),
        'the automatic Assessment saves both the Calendar source and the authorized Recent activity revision');
    return (int)$run['id'];
};
$displacedRun=$queueMixedAssessment('Calendar proof survives bounded Recent activity',$activityRef);
for($id=2;$id<=33;$id++)$insertActivity->execute([$id,'An unrelated newer activity item',gmdate('Y-m-d H:i:s',time()-60)]);
$coverage=[];
$displaced=vp3_initiative_evidence_v1($pdo,$user,$activityNow,coverage:$coverage,selectedRefs:[$activityRef,$calendarRef],agentId:0);
runtime_check(!isset($displaced[$activityRef])&&isset($displaced[$calendarRef])&&$coverage['activity_situations']==='limited',
    'unrelated activity moves the saved item out of the bounded feed');
$mixedCalls=0;
$mixed=vp3_initiative_execute_v1($pdo,$user,$sourceClaim($displacedRun),
    static function(string $prompt,string $protocol)use(&$mixedCalls,$calendarRef):string{
        $mixedCalls++;
        $input=json_decode($prompt,true,32,JSON_THROW_ON_ERROR);
        runtime_check($protocol==='vp3.initiative-plan.v1'&&array_column($input['evidence'],'ref')===[$calendarRef]
            &&!str_contains($prompt,'PRIVATE_DISPLACED_RECENT_ACTIVITY')&&!str_contains($prompt,'An unrelated newer activity item'),
            'the model sees the exact remaining Calendar proof and no displaced or substituted Recent activity');
        return json_encode(['jobs'=>[['tool'=>'work.prepare_private','primary'=>$calendarRef,'refs'=>[$calendarRef],
            'focus'=>'Prepare a Calendar review checklist','report'=>false]],'opportunities'=>[[
                'tool'=>'work.prepare_private','primary'=>$calendarRef,'refs'=>[$calendarRef],
                'objective'=>'Prepare a Calendar review checklist','rationale'=>'The verified Calendar event still needs preparation.',
            ]]],JSON_THROW_ON_ERROR);
    });
$sourceReceipt->execute([$displacedRun]);
$mixedReceipt=$sourceReceipt->fetch();
$mixedProof=json_decode($mixedReceipt['result_json']??'{}',true,16,JSON_THROW_ON_ERROR);
$effectiveGoal=json_decode(agent_workflow_row_v1400($pdo,1,$displacedRun)['goal'],true,16,JSON_THROW_ON_ERROR);
$mixedHealth=vp3_initiative_worker_health_v1($pdo,$user,0,42);
require_once dirname(__DIR__).'/includes/agent-initiative-opportunity-view-v1.php';
$visibleOpportunities=vp3_initiative_opportunities_v1($pdo,$user,0);
runtime_check($mixed['ok']&&$mixedCalls===1&&$readSourceState($displacedRun)===['status'=>'completed','attempt_count'=>1]
    &&array_keys($effectiveGoal['sources'])===[$calendarRef]
    &&(int)($mixedProof['activity_sources_omitted']??0)===1
    &&($mixedHealth['last_assessment']['activity_sources_omitted']??null)===1,
    'the original Assessment completes with a narrowed source goal and a verified omitted-activity count');
runtime_check(count($visibleOpportunities)===1&&$visibleOpportunities[0]['run_id']===$displacedRun
    &&$visibleOpportunities[0]['objective']==='Prepare a Calendar review checklist'
    &&$visibleOpportunities[0]['state']==='queued',
    'the saved Calendar opportunity and assignment survive strict reload after omitting unverified activity');

$pdo->exec('DELETE FROM notifications WHERE id>1');
$outageRun=$queueMixedAssessment('Calendar evidence alongside unavailable Recent activity',$activityRef);
$pdo->exec('ALTER TABLE notifications RENAME TO unavailable_notifications');
$outage=vp3_initiative_execute_v1($pdo,$user,$sourceClaim($outageRun),$sourceModel);
$outageHealth=vp3_initiative_worker_health_v1($pdo,$user,0,42)['failure_detail'];
runtime_check(!$outage['ok']&&$outage['reason']==='source_unavailable'
    &&$readSourceState($outageRun)===['status'=>'approved','attempt_count'=>1]&&$sourceAttempts===1
    &&($outageHealth['source_family']??null)==='activity_situations'
    &&($outageHealth['source_coverage']??null)==='unavailable',
    'a genuine Recent activity storage outage keeps a bounded retry and never sends an incomplete snapshot');
$pdo->exec('ALTER TABLE unavailable_notifications RENAME TO notifications');
$pdo->prepare("UPDATE agent_workflow_runs SET next_attempt_at='2000-01-01 00:00:00' WHERE id=?")->execute([$outageRun]);
$pdo->prepare("UPDATE agent_workflow_actions SET available_at='2000-01-01 00:00:00' WHERE run_id=?")->execute([$outageRun]);
$restoredActivity=vp3_initiative_execute_v1($pdo,$user,$sourceClaim($outageRun),$sourceModel);
runtime_check($restoredActivity['ok']&&$sourceAttempts===2
    &&$readSourceState($outageRun)===['status'=>'completed','attempt_count'=>2],
    'restoring Recent activity verifies both original sources and completes the same retry');

$lateRun=$queueMixedAssessment('Recent activity displaced after model response',$activityRef);
$lateCalls=0;
$late=vp3_initiative_execute_v1($pdo,$user,$sourceClaim($lateRun),
    static function(string $prompt)use(&$lateCalls,$activityRef,$calendarRef,$insertActivity):string{
        $lateCalls++;
        $input=json_decode($prompt,true,32,JSON_THROW_ON_ERROR);
        runtime_check(count($input['evidence'])===2
            &&array_diff([$activityRef,$calendarRef],array_column($input['evidence'],'ref'))===[],
            'a launch with both sources verified may use the current Recent activity and Calendar');
        for($id=2;$id<=33;$id++)$insertActivity->execute([$id,'A later unrelated activity item',gmdate('Y-m-d H:i:s',time()-60)]);
        return '{"jobs":[]}';
    });
$lateHealth=vp3_initiative_worker_health_v1($pdo,$user,0,42);
$lateGoal=json_decode(agent_workflow_row_v1400($pdo,1,$lateRun)['goal'],true,16,JSON_THROW_ON_ERROR);
runtime_check(!$late['ok']&&$late['reason']==='assessment_sources_changed'&&$lateCalls===1
    &&$readSourceState($lateRun)===['status'=>'failed','attempt_count'=>1]
    &&isset($lateGoal['sources'][$activityRef])&&$lateHealth['retry_due_at']==='',
    'Recent activity displaced after dispatch supersedes the Assessment without publishing or rerunning the paid decision');

$pdo->exec('DELETE FROM notifications WHERE id>1');
vp3_initiative_in_scope_v1($user,0,static fn()=>vp3_initiative_schedule_scoped_v1($pdo,$user,[$activityRef]));
$activityOnlyRun=(int)$pdo->query("SELECT MAX(id) FROM agent_workflow_runs WHERE owner_user_id=1
    AND agent_id IS NULL AND capability_key='initiative.plan'")->fetchColumn();
for($id=2;$id<=33;$id++)$insertActivity->execute([$id,'An activity item outside the saved scope',gmdate('Y-m-d H:i:s',time()-60)]);
$activityOnly=vp3_initiative_execute_v1($pdo,$user,$sourceClaim($activityOnlyRun),$sourceModel);
runtime_check($activityOnlyRun>$lateRun&&!$activityOnly['ok']&&$activityOnly['reason']==='assessment_sources_changed'
    &&$readSourceState($activityOnlyRun)===['status'=>'failed','attempt_count'=>1]&&$sourceAttempts===2,
    'an entirely displaced activity-only Assessment ends without dispatching an empty or substituted model request');

$timestamp=time();
$pdo->exec("UPDATE agent_workflow_runs SET created_at='2000-01-01 00:00:00'
    WHERE owner_user_id=1 AND agent_id IS NULL AND capability_key='initiative.plan'");
$markRun=$pdo->prepare("UPDATE agent_workflow_runs SET completed_at=? WHERE id=?");
$markAction=$pdo->prepare("UPDATE agent_workflow_actions SET completed_at=? WHERE run_id=?");
$pdo->prepare("UPDATE agent_workflow_actions SET completed_at=? WHERE owner_user_id=1 AND capability_key='initiative.plan'
    AND run_id IN (SELECT id FROM agent_workflow_runs WHERE agent_id IS NULL) AND status IN ('completed','failed')")
    ->execute([gmdate('Y-m-d H:i:s',$timestamp-600)]);
foreach([[$displacedRun,-10],[$outageRun,-60],[$lateRun,-30],[$activityOnlyRun,-20]] as [$runId,$offset]){
    $when=gmdate('Y-m-d H:i:s',$timestamp+$offset);
    $markRun->execute([$when,$runId]);
    $markAction->execute([$when,$runId]);
}
$reordered=vp3_initiative_worker_health_v1($pdo,$user,0,42);
runtime_check($reordered['last_assessment']['run_id']===$displacedRun
    &&$reordered['latest_assessment_run']['run_id']===$displacedRun
    &&$reordered['latest_assessment_run']['status']==='completed',
    'the most recent verified Assessment completion wins over assessments with higher run IDs and earlier timestamps');
$sourceReceipt->execute([$displacedRun]);
$recentReceipt=$sourceReceipt->fetch();
$modifyReceipt->execute(['{}',$recentReceipt['id']]);
$alternate=vp3_initiative_worker_health_v1($pdo,$user,0,42);
runtime_check($alternate['last_assessment']['run_id']===$outageRun
    &&$alternate['latest_assessment_run']['run_id']===$displacedRun&&$alternate['latest_assessment_run']['status']==='unverified',
    'a newer completed row with a mismatched receipt cannot displace another verified Assessment outcome');
$modifyReceipt->execute([$recentReceipt['result_json'],$recentReceipt['id']]);
$newFailureTime=gmdate('Y-m-d H:i:s',$timestamp-5);
$markAction->execute([$newFailureTime,$activityOnlyRun]);
$laterFailure=vp3_initiative_worker_health_v1($pdo,$user,0,42);
runtime_check($laterFailure['last_assessment']['run_id']===$displacedRun
    &&$laterFailure['latest_assessment_run']['run_id']===$activityOnlyRun&&$laterFailure['latest_assessment_run']['status']==='failed'
    &&$laterFailure['latest_assessment_run']['reason_code']==='assessment_sources_changed',
    'a failure that actually occurred after the verified Assessment stays current in the Assessment lane');

// The worker health suite reuses this fixture and starts from a fresh policy.
vp3_initiative_chat_v1($pdo,$user,vp3_agent_chat_principal_v380($user,null),['conversation_id'=>42],'off',null);

require_once dirname(__DIR__).'/includes/cognitive-runtime-v500.php';
require_once dirname(__DIR__).'/includes/cognitive-continuity-v2440.php';
require_once dirname(__DIR__).'/includes/cognitive-supervision-v2460.php';
$secret='PRIVATE_INTERNAL_BINDING';
$raw=json_encode(['binding'=>['revision'=>$secret],'sources'=>['open-task:1'=>str_repeat('a',64)]],JSON_THROW_ON_ERROR);
$workflow=$pdo->prepare('INSERT INTO agent_workflow_runs(owner_user_id,agent_id,source_kind,capability_key,title,goal,decision_summary,status,updated_at)
    VALUES(1,NULL,?,?,?,?,?,?,?)');
$workflow->execute(['owner_request','workflow.execute','User-requested task','Keep the user task summary.',null,'failed','2026-01-01 00:00:00']);
$ownerFailure='workflow:'.$pdo->lastInsertId();
$workflow->execute(['agent_initiative','initiative.prepare','Private preparation',$raw,null,'failed','2026-01-01 00:00:00']);
$preparation='workflow:'.$pdo->lastInsertId();
$workflow->execute(['agent_initiative_action','initiative.action','Proposed Agent action',$raw,$raw,'approval_pending','2026-01-01 00:00:00']);
$approval='workflow:'.$pdo->lastInsertId();
$workflow->execute(['agent_initiative','initiative.plan','Assess useful next steps',$raw,null,'executing','2026-01-01 00:00:00']);
$active='workflow:'.$pdo->lastInsertId();
$attempts=[];
for($i=0;$i<16;$i++){
    $workflow->execute(['agent_initiative','initiative.plan','Assess useful next steps',$raw,null,'failed','2026-01-02 00:00:00']);
    $attempts[]='workflow:'.$pdo->lastInsertId();
}
$pdo->exec('PRAGMA query_only=ON');
$work=array_column(vp3_cognitive_continuity_workflows_v2440($pdo,$user,'system'),null,'ref');
$issues=array_column(vp3_cognitive_supervision_assess_items_v2460($pdo,$user,'system',array_values($work)),null,'continuity_ref');
$pdo->exec('PRAGMA query_only=OFF');
runtime_check(!array_intersect($attempts,array_keys($work))&&!array_intersect($attempts,array_keys($issues))
    &&isset($work[$active])&&(int)$pdo->query("SELECT COUNT(*) FROM agent_workflow_runs WHERE updated_at='2026-01-02 00:00:00'")->fetchColumn()===16,
    'past automatic assessments remain recorded while Open Work retains current assessment activity without repeated repair cards');
runtime_check($work[$ownerFailure]['summary']==='Keep the user task summary.'&&$issues[$ownerFailure]['health_state']==='terminal_failure'
    &&$work[$preparation]['state']==='repair_needed'&&$issues[$preparation]['health_state']==='terminal_failure'
    &&$work[$approval]['state']==='needs_approval'&&!str_contains(json_encode([$work,$issues]),$secret),
    'Open Work preserves genuine failures and approvals while never exposing Initiative binding JSON');
require_once dirname(__DIR__).'/includes/agent-coordination-schedule-v1.php';
$operatorRef='operator:followup:'.str_repeat('a',32);
$operatorItem=['ref'=>$operatorRef,'revision'=>str_repeat('b',64),'specialist'=>'operator','trigger'=>'followup_review','priority'=>'normal','history_only'=>true];
runtime_check(vp3_coordination_source_ref_valid_v1($operatorRef)&&!vp3_coordination_source_ref_valid_v1('operator:followup:invalid'),
    'Operator registers only exact saved recording references');
runtime_check(vp3_coordination_item_route_v1(['rules'=>['operator'=>['followup_review'=>'ask']]],$operatorItem)['action']==='watch'
    &&vp3_coordination_scan_items_v1(['items'=>[$operatorRef=>$operatorItem]])===[],
    'Operator review sources cannot enter automatic Chat or model dispatch even with a forged ask choice');
echo "AGENT_INITIATIVE_RUNTIME_V1=PASS ($checks cases)\n";
