<?php
declare(strict_types=1);
require dirname(__DIR__) . '/includes/bootstrap.php';
require_once dirname(__DIR__) . '/includes/agent-workflow-runs-v1400.php';
require_once dirname(__DIR__) . '/includes/agent-job-engine-v1900.php';
require_once dirname(__DIR__) . '/includes/agent-work-control-v173.php';
require_once dirname(__DIR__) . '/includes/agent-worker-runtime-v1910.php';
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');

$user=current_user();$pdo=db();
if(!$user||!has_permission('account.access',$user)){http_response_code(403);echo json_encode(['ok'=>false,'error'=>'Agent Workflow access is not available for this account.']);exit;}
if(!$pdo||!agent_workflow_schema_ready_v1400($pdo)){http_response_code(503);echo json_encode(['ok'=>false,'error'=>'Agent Workflows are not ready. An administrator needs to run the database upgrade.']);exit;}

try{
    if($_SERVER['REQUEST_METHOD']==='GET'){
        $runId=max(0,(int)($_GET['id']??0));$durable=agent_job_engine_schema_ready_v1900($pdo);$control=$durable&&agent_work_control_schema_ready_v173($pdo);
        $workers=$durable?agent_worker_runtime_summary_v1910($pdo,$user):['build'=>'','workers'=>[]];
        if($runId>0){
            $row=agent_workflow_row_v1400($pdo,(int)$user['id'],$runId);
            if(!$row){http_response_code(404);echo json_encode(['ok'=>false,'error'=>'Workflow not found.']);exit;}
            $run=$control?agent_work_control_public_run_v173($pdo,$row,true):($durable?agent_job_public_run_v1900($pdo,$row,true):agent_workflow_public_run_v1400($pdo,$row,true));
            echo json_encode(['ok'=>true,'run'=>$run,'workers'=>$workers,'build'=>$control?VP3_AGENT_WORK_CONTROL_V173:($durable?VP3_AGENT_JOB_ENGINE_V1900:VP3_AGENT_WORKFLOW_RUNS_V1400)],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);exit;
        }
        $limit=max(1,min(50,(int)($_GET['limit']??20)));$runs=[];
        foreach(agent_workflow_recent_v1400($pdo,$user,$limit) as $row)$runs[]=$control?agent_work_control_public_run_v173($pdo,$row,false):($durable?agent_job_public_run_v1900($pdo,$row,false):agent_workflow_public_run_v1400($pdo,$row,false));
        echo json_encode(['ok'=>true,'runs'=>$runs,'workers'=>$workers,'build'=>$control?VP3_AGENT_WORK_CONTROL_V173:($durable?VP3_AGENT_JOB_ENGINE_V1900:VP3_AGENT_WORKFLOW_RUNS_V1400)],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);exit;
    }
    if($_SERVER['REQUEST_METHOD']!=='POST'){http_response_code(405);echo json_encode(['ok'=>false,'error'=>'Method not allowed.']);exit;}
    $input=json_decode((string)file_get_contents('php://input'),true);if(!is_array($input))$input=$_POST;
    $csrf=(string)($input['csrf_token']??'');if($csrf===''||!hash_equals(csrf_token(),$csrf)){http_response_code(419);echo json_encode(['ok'=>false,'error'=>'Session expired. Refresh the page and try again.']);exit;}
    $action=trim((string)($input['action']??''));$run=null;$durable=agent_job_engine_schema_ready_v1900($pdo);$control=$durable&&agent_work_control_schema_ready_v173($pdo);$runId=(int)($input['run_id']??0);

    if($action==='create_from_brain'){
        $key=trim((string)($input['priority_key']??''));$hash=trim((string)($input['suggestion_hash']??''));
        $priority=agent_workflow_find_brain_priority_v1400($user,$key,$hash);
        if(!$priority)throw new RuntimeException('That Agent Brain priority is no longer available. Refresh and try again.');
        $run=$durable?agent_job_enqueue_from_brain_v1900($pdo,$user,$priority):agent_workflow_create_from_priority_v1400($pdo,$user,$priority);
    }elseif($action==='approve'){
        $run=$control?agent_work_control_approve_v173($pdo,$user,$runId):agent_workflow_approve_v1400($pdo,$user,$runId);
        if(!$control&&$durable){$pdo->prepare("UPDATE agent_workflow_runs SET next_attempt_at=UTC_TIMESTAMP(),progress_message='Approved and ready' WHERE id=? AND owner_user_id=?")->execute([$runId,(int)$user['id']]);$row=agent_workflow_row_v1400($pdo,(int)$user['id'],$runId);if($row)$run=agent_job_public_run_v1900($pdo,$row,true);}
    }elseif($action==='cancel'){
        $run=$control?agent_work_control_cancel_v173($pdo,$user,$runId):($durable?agent_job_cancel_v1900($pdo,$user,$runId):agent_workflow_cancel_v1400($pdo,$user,$runId));
    }elseif($action==='retry'){
        $run=$control?agent_work_control_retry_v173($pdo,$user,$runId):($durable?agent_job_retry_v1900($pdo,$user,$runId):agent_workflow_retry_v1400($pdo,$user,$runId));
    }elseif(in_array($action,['pause','resume','reschedule','priority'],true)){
        if(!$control)throw new RuntimeException('Agent Work Control is not installed yet. An administrator needs to run the Phase 17.3 upgrade.');
        $run=match($action){
            'pause'=>agent_work_control_pause_v173($pdo,$user,$runId),
            'resume'=>agent_work_control_resume_v173($pdo,$user,$runId),
            'reschedule'=>agent_work_control_reschedule_v173($pdo,$user,$runId,(string)($input['when']??$input['value']??'')),
            'priority'=>agent_work_control_priority_v173($pdo,$user,$runId,$input['priority']??$input['value']??'normal'),
        };
    }else{
        throw new RuntimeException('Unknown workflow action.');
    }
    echo json_encode(['ok'=>true,'run'=>$run,'build'=>$control?VP3_AGENT_WORK_CONTROL_V173:($durable?VP3_AGENT_JOB_ENGINE_V1900:VP3_AGENT_WORKFLOW_RUNS_V1400)],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
}catch(Throwable $e){
    $safe=$e instanceof RuntimeException?$e->getMessage():'Workflow request failed.';
    http_response_code($e instanceof RuntimeException?400:500);
    echo json_encode(['ok'=>false,'error'=>$safe],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
}
