<?php
declare(strict_types=1);

$fixtureFile=tempnam(sys_get_temp_dir(),'vp3-initiative-runtime-');
putenv('VP3_IMAGE_TEST_DSN=sqlite:'.$fixtureFile);
require __DIR__.'/fixtures/image-generation-v1.php';
require_once dirname(__DIR__).'/includes/agent-memory-scope-v410.php';
require_once dirname(__DIR__).'/includes/agent-brain-v122.php';
require_once dirname(__DIR__).'/includes/agent-initiative-runtime-v1.php';

function agent_brain_schema_ready():bool{return true;}
function agent_brain_normalize(string $text):string{return strtolower(trim($text));}
function agent_brain_sentences(string $text):array{return [$text];}
function vp3_agent_runtime_plan_v420(PDO $pdo,array $user,int $agentId,string $surface='chat'):array
{
    $GLOBALS['runtimeRoutes'][]=[$user['id'],$agentId];
    return $GLOBALS['runtimePlan']??['route'=>'vp3_cloud','blocked'=>false,'cloud'=>['ready'=>true]];
}
function vp3_initiative_inference_health_v1(PDO $pdo,array $user,int $agentId):array
{
    $plan=vp3_agent_runtime_plan_v420($pdo,$user,$agentId);
    return $GLOBALS['runtimeInference']??['ready'=>($plan['route']??'')==='vp3_cloud'&&empty($plan['blocked']),
        'route'=>$plan['route']??'', 'reason'=>($plan['cloud']['reason']??'')==='cloud_balance_exhausted'?'quota_exhausted':'route_unavailable'];
}
function calendar_schedule_awareness_snapshot_v1330(PDO $pdo,array $user,DateTimeImmutable $now,int $days,bool $includeHomeServer=true):array
{
    if($includeHomeServer)throw new LogicException('Background preparation cannot discover a paired calendar.');
    $row=['ref'=>'event:42','kind'=>'event','id'=>42,'title'=>$GLOBALS['runtimeCalendarTitle']??'Launch review',
        'start_local'=>$GLOBALS['runtimeCalendarStart']??=gmdate('Y-m-d H:i:s',time()+600)];
    return ['available'=>true,'timezone'=>'UTC','upcoming'=>[$row],'current'=>null,'conflicts'=>[],'next'=>$row,'starts_soon'=>true];
}
class InitiativeRuntimePDO extends ImageFixturePDO
{
    public function prepare(string $sql,array $options=[]):PDOStatement|false
    {
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
    return vp3_initiative_policy_v1($user,$agentId);
}
register_shutdown_function(static fn()=>@unlink($fixtureFile));
$user=['id'=>1,'is_active'=>1];
runtime_check(vp3_initiative_worker_health_v1($pdo,$user,0,42)['state']==='starting','schema readiness awaits the accepted Chat lifecycle without activating from a read-only request');
$policy=runtime_enable($user,0,42);
$before=$pdo->query('SELECT COUNT(*) FROM agent_workflow_runs')->fetchColumn();
$health=vp3_initiative_worker_health_v1($pdo,$user,0,42);
runtime_check($health['configuration_ready']&&$health['execution_ready']&&$health['pump_available']
    &&$health['last_checked_at']===''&&$health['active_jobs']===[]&&$pdo->query('SELECT COUNT(*) FROM agent_workflow_runs')->fetchColumn()===$before,
    'read-only health distinguishes available execution from an actual source review');
$calls=0;$duplicate=null;
$model=static function(string $prompt,string $protocol)use(&$calls,&$duplicate,$pdo,$user,$policy):string{
    if($pdo->inTransaction())throw new RuntimeException('Inference must not hold a database transaction.');
    $lease=$pdo->query("SELECT id,lease_expires_at FROM agent_workflow_runs WHERE source_kind='agent_initiative' AND status='executing' ORDER BY id DESC LIMIT 1")->fetch();
    $expiresAt=strtotime($lease['lease_expires_at'].' UTC');
    if($expiresAt<time()+235)throw new RuntimeException('Inference requires the canonical refreshed lease.');
    runtime_check(vp3_initiative_worker_health_v1($pdo,$user,0,42)['active_jobs']===
        [['id'=>'workflow:'.$lease['id'],'expiresAt'=>$expiresAt*1000,
            'kind'=>$protocol==='vp3.initiative-plan.v1'?'assessment':'preparation',
            'step'=>$protocol==='vp3.initiative-plan.v1'?'Brainstorming useful opportunities':'Preparing a private next step']],
        'read-only status projects the exact active workflow lease and safe actual work kind');
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
$receipt=$pdo->query('SELECT id,result_json FROM agent_workflow_receipts ORDER BY id DESC LIMIT 1')->fetch();
$modifyReceipt=$pdo->prepare('UPDATE agent_workflow_receipts SET result_json=? WHERE id=?');
$modifyReceipt->execute(['{"effect_state":"idle","jobs":0}',$receipt['id']]);
runtime_check(vp3_initiative_worker_health_v1($pdo,$user,0,42)['last_assessment']===null,
    'a mismatched receipt cannot falsely report an idle assessment');
$modifyReceipt->execute([$receipt['result_json'],$receipt['id']]);
$prepared=vp3_initiative_pump_v1($pdo,$user,0,42,$policy['revision'],$model);
runtime_check($prepared['ok']&&$calls===2&&(int)$pdo->query('SELECT COUNT(*) FROM agent_workflow_receipts')->fetchColumn()===2,
    'the next bounded pump executes the queued preparation through canonical receipts');
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
$GLOBALS['runtimePlan']=['route'=>'blocked','blocked'=>true,'cloud'=>['ready'=>false,'reason'=>'cloud_balance_exhausted']];
$quota=vp3_initiative_pump_v1($pdo,$user,0,42,$policy['revision'],$model);
runtime_check(!$quota['ok']&&$quota['health']['reason']==='quota_exhausted'&&$calls===2,'exhausted quota blocks foreground preparation before a model call');
$GLOBALS['runtimePlan']=['route'=>'homeserver_local','blocked'=>false,'effective_preference'=>'homeserver_only'];
runtime_check(vp3_initiative_worker_health_v1($pdo,$user,0,42)['reason']==='route_unavailable','HomeServer-only policy is reported without silently routing work to Cloud');
$GLOBALS['runtimeInference']=['ready'=>false,'route'=>'none','reason'=>'route_unavailable','policy_reason'=>'service_model_forbidden'];
$health=vp3_initiative_worker_health_v1($pdo,$user,0,42);
runtime_check(!$health['execution_ready']&&$health['policy_reason']==='service_model_forbidden',
    'owner health names the Initiative service policy blocker separately from the worker');
$GLOBALS['runtimeInference']=['ready'=>true,'route'=>'homeserver_local','reason'=>'ready'];
runtime_check(vp3_initiative_worker_health_v1($pdo,$user,0,42)['pump_available'],
    'an authorized canonical HomeServer inference adapter is allowed without requiring Cloud quota');
unset($GLOBALS['runtimeInference']);
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
    throw vp3_initiative_provider_failure_v1(['ok'=>false,'status'=>401]);
});
runtime_check(!$providerFailure['ok']&&$providerFailure['reason']==='model_unavailable'
    &&$providerFailure['health']['reason']==='model_unavailable'
    &&$providerFailure['health']['execution_ready']&&$providerFailure['health']['state']==='failed',
    'provider authentication failure remains explicit and terminal even when the configured route is otherwise ready');

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
runtime_check(!$invalid['ok']&&$invalid['reason']==='assessment_unverified'&&$invalid['health']['state']==='failed'
    &&$invalid['health']['reason']==='assessment_unverified'&&$invalid['health']['retry_due_at']===''
    &&($assessmentError['code']??null)==='invalid_json'&&($assessmentError['output_bytes']??null)===strlen($badPlan)
    &&strtotime($assessmentError['at']??'')>0&&$badPlanCalls===1&&$prepareCount()===$preparationsBeforeInvalid
    &&!str_contains(json_encode($invalid),$badPlan),
    'malformed planner output exposes a safe verified diagnostic without retry, dispatch or model text');

$failedReceipt=$pdo->query("SELECT id,action_id,result_json FROM agent_workflow_receipts
    WHERE owner_user_id=1 AND error_class='assessment_unverified' ORDER BY id DESC LIMIT 1")->fetch();
$modifyAction=$pdo->prepare('UPDATE agent_workflow_actions SET result_json=? WHERE id=?');
$mismatched=str_replace('invalid_json','invalid_job',$failedReceipt['result_json']);
$modifyReceipt->execute([$mismatched,$failedReceipt['id']]);
runtime_check(vp3_initiative_worker_health_v1($pdo,$user,0,42)['assessment_error']===null,
    'a mismatched failure receipt cannot publish an assessment diagnostic');
$forged=str_replace('invalid_json','PRIVATE_UNKNOWN_REASON',$failedReceipt['result_json']);
$modifyReceipt->execute([$forged,$failedReceipt['id']]);
$modifyAction->execute([$forged,$failedReceipt['action_id']]);
$forgedHealth=vp3_initiative_worker_health_v1($pdo,$user,0,42);
runtime_check($forgedHealth['assessment_error']===null&&!str_contains(json_encode($forgedHealth),'PRIVATE_UNKNOWN_REASON'),
    'a matching failed action and receipt still cannot publish an unknown diagnostic');
$modifyReceipt->execute([$failedReceipt['result_json'],$failedReceipt['id']]);
$modifyAction->execute([$failedReceipt['result_json'],$failedReceipt['action_id']]);
runtime_check(vp3_initiative_worker_health_v1($pdo,$user,0,42)['assessment_error']===$assessmentError
    &&vp3_initiative_worker_health_v1($pdo,['id'=>2,'is_active'=>1],0,42)['assessment_error']===null
    &&vp3_initiative_worker_health_v1($pdo,$user,7,42)['assessment_error']===null,
    'assessment diagnostics stay within the exact account, Agent and Chat');

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
    &&$receipt===[['status'=>'retry_scheduled','attempt_no'=>1],['status'=>'completed','attempt_no'=>2]]&&$reports()===1,
    'the same action succeeds once after the due time and delivers exactly one verified Chat report');
vp3_initiative_pump_v1($pdo,$user,0,42,$policy['revision'],$retryModel);
runtime_check($retryCalls===2&&$reports()===1,'a completed recovered action never repeats inference or delivery');

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
    &&$exhausted['health']['state']==='failed'&&$reports()===1,'transient failures stop after three actual canonical attempts');

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
echo "AGENT_INITIATIVE_RUNTIME_V1=PASS ($checks cases)\n";
