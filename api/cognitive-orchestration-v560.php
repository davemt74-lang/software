<?php
declare(strict_types=1);
require dirname(__DIR__).'/includes/bootstrap.php';

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('X-Content-Type-Options: nosniff');

function vp3_cognitive_orchestration_api_json_v560(int $status,array $payload): never
{
    http_response_code($status);
    echo json_encode(['build'=>VP3_COGNITIVE_ORCHESTRATION_V560]+$payload,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
    exit;
}

function vp3_cognitive_orchestration_api_input_v560(): array
{
    $raw=(string)file_get_contents('php://input');
    if(strlen($raw)>16384)vp3_cognitive_orchestration_api_json_v560(413,['ok'=>false,'error'=>'payload_too_large']);
    $input=json_decode($raw,true);
    return is_array($input)?$input:$_POST;
}

$user=current_user();
if(!$user||!has_permission('chat.access',$user))vp3_cognitive_orchestration_api_json_v560(403,['ok'=>false,'error'=>'forbidden']);
$pdo=db();if(!$pdo)vp3_cognitive_orchestration_api_json_v560(503,['ok'=>false,'error'=>'database_unavailable']);
if(!vp3_cognitive_orchestration_schema_ready_v560($pdo))vp3_cognitive_orchestration_api_json_v560(503,['ok'=>false,'error'=>'orchestration_schema_not_ready']);
if(strtoupper((string)($_SERVER['REQUEST_METHOD']??'GET'))!=='POST')vp3_cognitive_orchestration_api_json_v560(405,['ok'=>false,'error'=>'method_not_allowed']);

$input=vp3_cognitive_orchestration_api_input_v560();
if(!hash_equals(csrf_token(),trim((string)($input['csrf_token']??''))))vp3_cognitive_orchestration_api_json_v560(419,['ok'=>false,'error'=>'csrf']);

try{
    $namespace=vp3_cognitive_agent_namespace_v500($pdo,$user,max(0,(int)($input['agent_id']??0)));
    $action=trim((string)($input['action']??''));
    $runId=mb_strimwidth(trim((string)($input['run_id']??'')),0,190,'');
    if($runId==='')vp3_cognitive_orchestration_api_json_v560(422,['ok'=>false,'error'=>'run_id_required']);

    if($action==='reconcile'){
        $run=vp3_cognitive_orchestration_run_v560($pdo,$user,$namespace,$runId);
        if(!$run)vp3_cognitive_orchestration_api_json_v560(404,['ok'=>false,'error'=>'run_not_found']);
        $run=vp3_cognitive_orchestration_reconcile_run_v560($pdo,$user,$namespace,$run);
    }elseif(in_array($action,['handoff_requested','handoff_rejected'],true)){
        $toolId=vp3_cognitive_id_v500($input['tool_id']??'',120);
        if($toolId==='')vp3_cognitive_orchestration_api_json_v560(422,['ok'=>false,'error'=>'tool_id_required']);
        $run=vp3_cognitive_orchestration_handoff_v560(
            $pdo,$user,$namespace,$runId,$toolId,$action==='handoff_requested'
        );
    }else{
        vp3_cognitive_orchestration_api_json_v560(422,['ok'=>false,'error'=>'unknown_action']);
    }

    vp3_cognitive_orchestration_api_json_v560(200,[
        'ok'=>true,
        'run'=>[
            'id'=>(string)$run['public_id'],
            'status'=>(string)$run['status'],
            'verification_state'=>(string)$run['verification_state'],
            'current_step_key'=>(string)$run['current_step_key'],
            'completed_steps'=>(int)$run['completed_steps'],
            'total_steps'=>(int)$run['total_steps'],
            'authority'=>'orchestration_only',
        ],
        'feed'=>vp3_cognitive_feed_compose_v530($pdo,$user,$namespace,false),
    ]);
}catch(DomainException|InvalidArgumentException $e){
    vp3_cognitive_orchestration_api_json_v560(422,['ok'=>false,'error'=>$e->getMessage()]);
}catch(RuntimeException $e){
    vp3_cognitive_orchestration_api_json_v560(409,['ok'=>false,'error'=>$e->getMessage()]);
}catch(Throwable $e){
    error_log('VP3 Cognitive Orchestration v5.60 API: '.$e->getMessage());
    vp3_cognitive_orchestration_api_json_v560(500,['ok'=>false,'error'=>'orchestration_unavailable']);
}
