<?php
declare(strict_types=1);
require dirname(__DIR__).'/includes/bootstrap.php';

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('X-Content-Type-Options: nosniff');

function vp3_cognitive_feed_api_json_v530(int $status,array $payload): never
{
    http_response_code($status);
    echo json_encode(['build'=>VP3_COGNITIVE_FEED_V530]+$payload,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
    exit;
}
function vp3_cognitive_feed_api_input_v530(): array
{
    $raw=(string)file_get_contents('php://input');
    if(strlen($raw)>16384)vp3_cognitive_feed_api_json_v530(413,['ok'=>false,'error'=>'payload_too_large']);
    $input=json_decode($raw,true);return is_array($input)?$input:$_POST;
}

$user=current_user();
if(!$user||!has_permission('chat.access',$user))vp3_cognitive_feed_api_json_v530(403,['ok'=>false,'error'=>'forbidden']);
$pdo=db();if(!$pdo)vp3_cognitive_feed_api_json_v530(503,['ok'=>false,'error'=>'database_unavailable']);
if(!vp3_cognitive_feed_schema_ready_v530($pdo))vp3_cognitive_feed_api_json_v530(503,['ok'=>false,'error'=>'feed_schema_not_ready']);

$method=strtoupper((string)($_SERVER['REQUEST_METHOD']??'GET'));
$input=$method==='POST'?vp3_cognitive_feed_api_input_v530():$_GET;
$agentId=max(0,(int)($input['agent_id']??0));

try{
    $namespace=vp3_cognitive_agent_namespace_v500($pdo,$user,$agentId);
    $action=trim((string)($input['action']??'state'));
    if($method==='GET'&&$action==='state'){
        vp3_cognitive_feed_api_json_v530(200,['ok'=>true,'feed'=>vp3_cognitive_feed_compose_v530($pdo,$user,$namespace,false)]);
    }
    if($method!=='POST')vp3_cognitive_feed_api_json_v530(405,['ok'=>false,'error'=>'method_not_allowed']);
    if(!hash_equals(csrf_token(),trim((string)($input['csrf_token']??''))))vp3_cognitive_feed_api_json_v530(419,['ok'=>false,'error'=>'csrf']);

    if(in_array($action,['activation_defer','activation_dismiss','activation_restore'],true)){
        $workflow=trim((string)($input['workflow']??''));
        $state=chat_onboarding_v241_state($pdo,$user);
        $item=(array)($state['activation']['items'][$workflow]??[]);
        if(!$item)vp3_cognitive_feed_api_json_v530(404,['ok'=>false,'error'=>'activation_item_unavailable']);
        if(in_array($action,['activation_defer','activation_dismiss'],true)&&($item['activation_status']??'')!=='pending'){
            vp3_cognitive_feed_api_json_v530(409,['ok'=>false,'error'=>'activation_item_changed']);
        }
        $domainAction=$action==='activation_defer'?'defer':($action==='activation_dismiss'?'dismiss':'restore');
        onboarding_intelligence_activation_action($pdo,$user,$workflow,$domainAction,(int)($input['defer_days']??3));
        vp3_cognitive_feed_api_json_v530(200,['ok'=>true,'feed'=>vp3_cognitive_feed_compose_v530($pdo,$user,$namespace,false)]);
    }
    if($action==='hide'){
        $key=mb_strimwidth(trim((string)($input['item_key']??'')),0,190,'');
        $fingerprint=strtolower(trim((string)($input['fingerprint']??'')));
        $candidate=$key!==''?vp3_cognitive_feed_find_candidate_v530($pdo,$user,$namespace,$key):null;
        if(!$candidate||!hash_equals((string)$candidate['fingerprint'],$fingerprint)){
            vp3_cognitive_feed_api_json_v530(409,['ok'=>false,'error'=>'feed_item_changed']);
        }
        vp3_cognitive_feed_hide_v530($pdo,$user,$namespace,$key,$fingerprint);
        if(function_exists('vp3_cognitive_learning_schema_ready_v540')&&vp3_cognitive_learning_schema_ready_v540($pdo)){
            vp3_cognitive_learning_observe_candidates_v540($pdo,$user,$namespace,[$candidate]);
            vp3_cognitive_learning_feedback_v540($pdo,$user,$namespace,$candidate,'hidden',['surface'=>'agent_chat_now'],'hide:'.$fingerprint);
        }
        vp3_cognitive_feed_api_json_v530(200,['ok'=>true,'feed'=>vp3_cognitive_feed_compose_v530($pdo,$user,$namespace,false)]);
    }
    if($action==='restore'){
        vp3_cognitive_feed_restore_v530($pdo,$user,$namespace,mb_strimwidth(trim((string)($input['item_key']??'')),0,190,''));
        vp3_cognitive_feed_api_json_v530(200,['ok'=>true,'feed'=>vp3_cognitive_feed_compose_v530($pdo,$user,$namespace,false)]);
    }
    if($action==='restore_all'){
        vp3_cognitive_feed_restore_v530($pdo,$user,$namespace,'');
        vp3_cognitive_feed_api_json_v530(200,['ok'=>true,'feed'=>vp3_cognitive_feed_compose_v530($pdo,$user,$namespace,false)]);
    }
    vp3_cognitive_feed_api_json_v530(422,['ok'=>false,'error'=>'unknown_action']);
}catch(DomainException|InvalidArgumentException $e){
    vp3_cognitive_feed_api_json_v530(422,['ok'=>false,'error'=>$e->getMessage()]);
}catch(Throwable $e){
    error_log('VP3 Cognitive Feed v5.30 API: '.$e->getMessage());
    vp3_cognitive_feed_api_json_v530(500,['ok'=>false,'error'=>'feed_unavailable']);
}
