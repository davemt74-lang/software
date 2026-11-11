<?php
declare(strict_types=1);

$dsn=(string)(getenv('VP3_TEST_MYSQL_DSN')?:getenv('VP3_MYSQL_TEST_DSN'));
if (!str_starts_with($dsn,'mysql:')) throw new RuntimeException('Run with an explicit disposable MySQL test DSN.');
$login=(string)(getenv('VP3_TEST_MYSQL_USER')?:getenv('VP3_MYSQL_TEST_USER')?:'root');
$password=(string)(getenv('VP3_TEST_MYSQL_PASS')?:getenv('VP3_MYSQL_TEST_PASSWORD'));
$options=[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false];
$admin=new PDO($dsn,$login,$password,$options);$database='meeting_workflow_'.bin2hex(random_bytes(6));
$admin->exec('CREATE DATABASE `'.$database.'` CHARACTER SET utf8mb4');
$pdo=$GLOBALS['pdo']=new PDO(preg_replace('/;dbname=[^;]*/','',$dsn).';dbname='.$database,$login,$password,$options);
function db(): PDO { return $GLOBALS['pdo']; }
function url(string $path=''): string { return $path; }
function setting(string $key,mixed $fallback=null): mixed { return $fallback; }
function has_permission(string $permission,array $user): bool { return true; }
function table_exists(string $table): bool
{
    $q=db()->prepare('SELECT 1 FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?');$q->execute([$table]);return (bool)$q->fetchColumn();
}
function column_exists(string $table,string $column): bool
{
    $q=db()->prepare('SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name=? AND column_name=?');$q->execute([$table,$column]);return (bool)$q->fetchColumn();
}
require_once __DIR__.'/../includes/agent-work-control-v173.php';
require_once __DIR__.'/../includes/agent-worker-runtime-v1910.php';

function meeting_boundary_check(bool $ok,string $label): void
{
    if (!$ok) throw new RuntimeException($label);
    $GLOBALS['checks']=($GLOBALS['checks']??0)+1;echo 'PASS '.$label.PHP_EOL;
}
function meeting_boundary_reject(callable $call,string $label): void
{
    try { $call(); } catch (RuntimeException $e) {
        if ($e instanceof PDOException||$e->getCode()!==409)throw $e;
        meeting_boundary_check(true,$label);return;
    }
    throw new RuntimeException('Expected owner Chat reservation: '.$label);
}
function meeting_boundary_seed(string $source,string $capability,string $actionCapability='meeting.chat_action'): array
{
    $pdo=db();$key=hash('sha256',random_bytes(16));
    $pdo->prepare("INSERT INTO agent_workflow_runs(owner_user_id,workflow_type,origin,source_kind,dedupe_key,title,goal,status,requires_approval,approval_status,capability_key)
        VALUES(1,'meeting_action','chat',?,?,'Review meeting action','private source and reviewed agenda text','approval_pending',1,'pending',?)")->execute([$source,$key,$capability]);
    $id=(int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO agent_workflow_actions(run_id,owner_user_id,sequence_no,action_key,action_type,label,status,requires_approval,capability_key,result_json)
        VALUES(?,1,1,?,'execute','Review meeting action','approval_pending',1,?,?)")->execute([$id,$key,$actionCapability,'{"message_id":44,"private_meeting":"Private agenda text"}']);
    return [$id,(int)$pdo->lastInsertId()];
}

try {
    $pdo->exec('CREATE TABLE users(id INT UNSIGNED PRIMARY KEY,is_active TINYINT);INSERT INTO users VALUES(1,1);
        CREATE TABLE user_agents(id BIGINT UNSIGNED PRIMARY KEY);');
    agent_work_control_ensure_schema_v173($pdo);agent_work_dependencies_ensure_schema_v174($pdo);
    $user=['id'=>1,'is_active'=>1];[$id,$action]=meeting_boundary_seed('meeting_chat_action','meeting.chat_action');
    $row=agent_workflow_row_v1400($pdo,1,$id);
    $public=agent_job_public_run_v1900($pdo,$row,true);
    meeting_boundary_check($public['goal']===''&&$public['actions'][0]['result']===[]
        &&!str_contains(json_encode($public,JSON_THROW_ON_ERROR),'private source')&&!str_contains(json_encode($public,JSON_THROW_ON_ERROR),'Private agenda text'),
        'generic workflow and durable history redact private meeting goals and action results');
    $controls=agent_work_control_allowed_v173($row);unset($controls['inspect']);
    meeting_boundary_check(!in_array(true,$controls,true),'generic work controls show the meeting review as unavailable for mutation');
    meeting_boundary_reject(static fn()=>agent_workflow_approve_v1400($pdo,$user,$id),'generic approval cannot bypass saved owner Chat confirmation');
    meeting_boundary_reject(static fn()=>agent_workflow_cancel_v1400($pdo,$user,$id),'generic cancellation cannot change a private Chat review');
    meeting_boundary_reject(static fn()=>agent_work_control_priority_v173($pdo,$user,$id,'high'),'generic priority control cannot change private Chat review state');
    meeting_boundary_reject(static fn()=>agent_work_delegate_v174($pdo,$user,$id,'homeserver'),'generic delegation cannot move the private Chat action to a worker');
    meeting_boundary_check(agent_workflow_row_v1400($pdo,1,$id)['status']==='approval_pending'&&!$pdo->inTransaction(),
        'rejected generic transitions leave the review unchanged and release transactions');
    $pdo->prepare("UPDATE agent_workflow_runs SET status='approved',approval_status='approved' WHERE id=?")->execute([$id]);
    $pdo->prepare("UPDATE agent_workflow_actions SET status='queued' WHERE id=?")->execute([$action]);
    meeting_boundary_check(agent_workflow_claim_next_action_v1400($pdo,$user,$id)===null,'legacy executor cannot claim a meeting Chat action');
    meeting_boundary_check(agent_job_claim_run_v1900($pdo,$user,$id,'cloud','fixture-worker')===null,'durable executor cannot claim a meeting Chat action');
    $pdo->prepare("UPDATE agent_workflow_runs SET status='failed' WHERE id=?")->execute([$id]);
    meeting_boundary_reject(static fn()=>agent_workflow_retry_v1400($pdo,$user,$id),'generic retry cannot recycle a private reviewed action');
    $lease=str_repeat('a',64);
    $pdo->prepare("UPDATE agent_workflow_runs SET status='executing',current_action_id=?,lease_owner='fixture-worker',lease_token=?,lease_expires_at=DATE_ADD(UTC_TIMESTAMP(),INTERVAL 5 MINUTE) WHERE id=?")->execute([$action,$lease,$id]);
    $pdo->prepare("UPDATE agent_workflow_actions SET status='executing' WHERE id=?")->execute([$action]);
    meeting_boundary_reject(static fn()=>agent_workflow_record_action_result_v1400($pdo,$user,$id,$action,true,'Forged result'),
        'legacy result hook cannot forge a meeting action completion');
    meeting_boundary_reject(static fn()=>agent_job_record_result_v1900($pdo,$user,$id,$action,$lease,'fake-receipt',true,'Forged result'),
        'durable result hook cannot forge a meeting action receipt');
    $authorized=agent_worker_runtime_authorize_claim_v1910($pdo,$user,['ready'=>true,'worker_id'=>'fixture-worker','executor'=>'cloud'],
        ['run_id'=>$id,'action'=>['id'=>$action],'lease_token'=>$lease]);
    meeting_boundary_check($authorized===['authorized'=>false,'reason'=>'owner_chat_action'],
        'worker authorization denies the reserved action even with a matching injected lease');
    meeting_boundary_check((int)$pdo->query('SELECT COUNT(*) FROM agent_workflow_receipts')->fetchColumn()===0,
        'denied generic execution creates no misleading receipt');
    [$sourceOnly,$sourceAction]=meeting_boundary_seed('meeting_chat_action','agent.next_action','agent.next_action');
    meeting_boundary_reject(static fn()=>agent_workflow_approve_v1400($pdo,$user,$sourceOnly),'source reservation survives a mismatched run capability');
    [$actionOnly,$reservedAction]=meeting_boundary_seed('ordinary','agent.next_action');
    $pdo->prepare("UPDATE agent_workflow_runs SET status='approved',approval_status='approved' WHERE id=?")->execute([$actionOnly]);
    $pdo->prepare("UPDATE agent_workflow_actions SET status='queued' WHERE id=?")->execute([$reservedAction]);
    meeting_boundary_check(agent_workflow_claim_next_action_v1400($pdo,$user,$actionOnly)===null
        &&agent_job_claim_run_v1900($pdo,$user,$actionOnly,'cloud','fixture-worker')===null,
        'action reservation survives a mismatched run source and capability');
    [$normal,$normalAction]=meeting_boundary_seed('ordinary','agent.next_action','agent.next_action');
    $approved=agent_workflow_approve_v1400($pdo,$user,$normal);
    $claim=agent_job_claim_run_v1900($pdo,$user,$normal,'cloud','fixture-worker');
    meeting_boundary_check($approved['status']==='approved'&&$claim['action']['id']===$normalAction,
        'ordinary reviewed workflows continue through the existing approval and durable claimant');
    echo 'MEETING_WORKFLOW_BOUNDARY_MYSQL=PASS ('.$GLOBALS['checks'].' native boundary checks)'.PHP_EOL;
} finally {
    if ($pdo->inTransaction())$pdo->rollBack();
    $admin->exec('DROP DATABASE IF EXISTS `'.$database.'`');
}
