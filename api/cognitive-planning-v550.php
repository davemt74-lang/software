<?php
declare(strict_types=1);
require dirname(__DIR__).'/includes/bootstrap.php';

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('X-Content-Type-Options: nosniff');

function vp3_cognitive_planning_api_json_v550(int $status,array $payload): never
{
    http_response_code($status);
    echo json_encode(['build'=>VP3_COGNITIVE_PLANNING_V550]+$payload,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
    exit;
}
function vp3_cognitive_planning_api_input_v550(): array
{
    $raw=(string)file_get_contents('php://input');
    if(strlen($raw)>16384)vp3_cognitive_planning_api_json_v550(413,['ok'=>false,'error'=>'payload_too_large']);
    $input=json_decode($raw,true);
    return is_array($input)?$input:$_POST;
}

$user=current_user();
if(!$user||!has_permission('chat.access',$user))vp3_cognitive_planning_api_json_v550(403,['ok'=>false,'error'=>'forbidden']);
$pdo=db();if(!$pdo)vp3_cognitive_planning_api_json_v550(503,['ok'=>false,'error'=>'database_unavailable']);
if(!vp3_cognitive_planning_schema_ready_v550($pdo))vp3_cognitive_planning_api_json_v550(503,['ok'=>false,'error'=>'planning_schema_not_ready']);

$input=vp3_cognitive_planning_api_input_v550();
if(strtoupper((string)($_SERVER['REQUEST_METHOD']??'GET'))!=='POST')vp3_cognitive_planning_api_json_v550(405,['ok'=>false,'error'=>'method_not_allowed']);
if(!hash_equals(csrf_token(),trim((string)($input['csrf_token']??''))))vp3_cognitive_planning_api_json_v550(419,['ok'=>false,'error'=>'csrf']);

try{
    $namespace=vp3_cognitive_agent_namespace_v500($pdo,$user,max(0,(int)($input['agent_id']??0)));
    $action=trim((string)($input['action']??''));
    if(!in_array($action,['accept','dismiss'],true))vp3_cognitive_planning_api_json_v550(422,['ok'=>false,'error'=>'unknown_action']);
    $planId=mb_strimwidth(trim((string)($input['plan_id']??'')),0,190,'');
    if($planId==='')vp3_cognitive_planning_api_json_v550(422,['ok'=>false,'error'=>'plan_id_required']);

    $plan=vp3_cognitive_planning_decide_v550($pdo,$user,$namespace,$planId,$action);
    if(function_exists('vp3_cognitive_learning_schema_ready_v540')&&vp3_cognitive_learning_schema_ready_v540($pdo)){
        $source=vp3_cognitive_feed_find_candidate_v530($pdo,$user,$namespace,(string)$plan['source_item_key']);
        if(is_array($source)&&hash_equals((string)$source['fingerprint'],(string)$plan['source_fingerprint'])){
            vp3_cognitive_learning_observe_candidates_v540($pdo,$user,$namespace,[$source]);
            vp3_cognitive_learning_feedback_v540(
                $pdo,$user,$namespace,$source,$action==='accept'?'acted':'engaged',
                ['action_type'=>'plan_'.$action,'surface'=>'agent_chat_now'],
                'plan:'.(string)$plan['public_id'].':'.$action
            );
        }
    }
    vp3_cognitive_planning_api_json_v550(200,[
        'ok'=>true,
        'plan'=>[
            'id'=>(string)$plan['public_id'],
            'status'=>(string)$plan['status'],
            'authority'=>'proposal_only',
        ],
        'feed'=>vp3_cognitive_feed_compose_v530($pdo,$user,$namespace,false),
    ]);
}catch(DomainException|InvalidArgumentException $e){
    vp3_cognitive_planning_api_json_v550(422,['ok'=>false,'error'=>$e->getMessage()]);
}catch(RuntimeException $e){
    vp3_cognitive_planning_api_json_v550(409,['ok'=>false,'error'=>$e->getMessage()]);
}catch(Throwable $e){
    error_log('VP3 Cognitive Planning v5.50 API: '.$e->getMessage());
    vp3_cognitive_planning_api_json_v550(500,['ok'=>false,'error'=>'planning_unavailable']);
}
