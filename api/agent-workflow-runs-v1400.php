<?php
declare(strict_types=1);
require dirname(__DIR__) . '/includes/bootstrap.php';
require_once dirname(__DIR__) . '/includes/agent-workflow-runs-v1400.php';
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');

$user=current_user();$pdo=db();
if(!$user||!has_permission('account.access',$user)){http_response_code(403);echo json_encode(['ok'=>false,'error'=>'Agent Workflow access is not available for this account.']);exit;}
if(!$pdo||!agent_workflow_schema_ready_v1400($pdo)){http_response_code(503);echo json_encode(['ok'=>false,'error'=>'Agent Workflows are not ready. An administrator needs to run the database upgrade.']);exit;}

try{
    if($_SERVER['REQUEST_METHOD']==='GET'){
        $runId=max(0,(int)($_GET['id']??0));
        if($runId>0){
            $row=agent_workflow_row_v1400($pdo,(int)$user['id'],$runId);
            if(!$row){http_response_code(404);echo json_encode(['ok'=>false,'error'=>'Workflow not found.']);exit;}
            echo json_encode(['ok'=>true,'run'=>agent_workflow_public_run_v1400($pdo,$row,true),'build'=>VP3_AGENT_WORKFLOW_RUNS_V1400],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);exit;
        }
        $limit=max(1,min(50,(int)($_GET['limit']??20)));$runs=[];
        foreach(agent_workflow_recent_v1400($pdo,$user,$limit) as $row)$runs[]=agent_workflow_public_run_v1400($pdo,$row,false);
        echo json_encode(['ok'=>true,'runs'=>$runs,'build'=>VP3_AGENT_WORKFLOW_RUNS_V1400],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);exit;
    }
    if($_SERVER['REQUEST_METHOD']!=='POST'){http_response_code(405);echo json_encode(['ok'=>false,'error'=>'Method not allowed.']);exit;}
    $input=json_decode((string)file_get_contents('php://input'),true);if(!is_array($input))$input=$_POST;
    $csrf=(string)($input['csrf_token']??'');if($csrf===''||!hash_equals(csrf_token(),$csrf)){http_response_code(419);echo json_encode(['ok'=>false,'error'=>'Session expired. Refresh the page and try again.']);exit;}
    $action=trim((string)($input['action']??''));$run=null;

    if($action==='create_from_brain'){
        $key=trim((string)($input['priority_key']??''));$hash=trim((string)($input['suggestion_hash']??''));
        $priority=agent_workflow_find_brain_priority_v1400($user,$key,$hash);
        if(!$priority)throw new RuntimeException('That Agent Brain priority is no longer available. Refresh and try again.');
        $run=agent_workflow_create_from_priority_v1400($pdo,$user,$priority);
    }elseif($action==='approve'){
        $run=agent_workflow_approve_v1400($pdo,$user,(int)($input['run_id']??0));
    }elseif($action==='cancel'){
        $run=agent_workflow_cancel_v1400($pdo,$user,(int)($input['run_id']??0));
    }elseif($action==='retry'){
        $run=agent_workflow_retry_v1400($pdo,$user,(int)($input['run_id']??0));
    }else{
        throw new RuntimeException('Unknown workflow action.');
    }
    echo json_encode(['ok'=>true,'run'=>$run,'build'=>VP3_AGENT_WORKFLOW_RUNS_V1400],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
}catch(Throwable $e){
    $safe=$e instanceof RuntimeException?$e->getMessage():'Workflow request failed.';
    http_response_code($e instanceof RuntimeException?400:500);
    echo json_encode(['ok'=>false,'error'=>$safe],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
}
