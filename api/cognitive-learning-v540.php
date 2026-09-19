<?php
declare(strict_types=1);
require dirname(__DIR__).'/includes/bootstrap.php';

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('X-Content-Type-Options: nosniff');

function vp3_cognitive_learning_api_json_v540(int $status,array $payload): never
{
    http_response_code($status);
    echo json_encode(['build'=>VP3_COGNITIVE_LEARNING_V540]+$payload,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
    exit;
}
function vp3_cognitive_learning_api_input_v540(): array
{
    $raw=(string)file_get_contents('php://input');
    if(strlen($raw)>16384)vp3_cognitive_learning_api_json_v540(413,['ok'=>false,'error'=>'payload_too_large']);
    $input=json_decode($raw,true);
    return is_array($input)?$input:$_POST;
}

$user=current_user();
if(!$user||!has_permission('chat.access',$user))vp3_cognitive_learning_api_json_v540(403,['ok'=>false,'error'=>'forbidden']);
$pdo=db();if(!$pdo)vp3_cognitive_learning_api_json_v540(503,['ok'=>false,'error'=>'database_unavailable']);
if(!vp3_cognitive_learning_schema_ready_v540($pdo))vp3_cognitive_learning_api_json_v540(503,['ok'=>false,'error'=>'learning_schema_not_ready']);

$method=strtoupper((string)($_SERVER['REQUEST_METHOD']??'GET'));
$input=$method==='POST'?vp3_cognitive_learning_api_input_v540():$_GET;
$agentId=max(0,(int)($input['agent_id']??0));

try{
    $namespace=vp3_cognitive_agent_namespace_v500($pdo,$user,$agentId);
    $action=trim((string)($input['action']??''));

    if($method==='GET'&&$action==='explain'){
        $key=mb_strimwidth(trim((string)($input['item_key']??'')),0,190,'');
        $candidate=$key!==''?vp3_cognitive_feed_find_candidate_v530($pdo,$user,$namespace,$key):null;
        if(!$candidate)vp3_cognitive_learning_api_json_v540(404,['ok'=>false,'error'=>'item_unavailable']);
        vp3_cognitive_learning_observe_candidates_v540($pdo,$user,$namespace,[$candidate]);
        vp3_cognitive_learning_api_json_v540(200,[
            'ok'=>true,
            'explanation'=>vp3_cognitive_learning_explain_v540($pdo,$user,$namespace,$candidate),
        ]);
    }

    if($method!=='POST')vp3_cognitive_learning_api_json_v540(405,['ok'=>false,'error'=>'method_not_allowed']);
    if(!hash_equals(csrf_token(),trim((string)($input['csrf_token']??''))))vp3_cognitive_learning_api_json_v540(419,['ok'=>false,'error'=>'csrf']);

    if($action==='feedback'){
        $key=mb_strimwidth(trim((string)($input['item_key']??'')),0,190,'');
        $fingerprint=strtolower(trim((string)($input['fingerprint']??'')));
        $event=trim((string)($input['event_type']??''));
        $candidate=$key!==''?vp3_cognitive_feed_find_candidate_v530($pdo,$user,$namespace,$key):null;
        if(!$candidate||!hash_equals((string)$candidate['fingerprint'],$fingerprint)){
            vp3_cognitive_learning_api_json_v540(409,['ok'=>false,'error'=>'feed_item_changed']);
        }
        vp3_cognitive_learning_observe_candidates_v540($pdo,$user,$namespace,[$candidate]);
        $recorded=vp3_cognitive_learning_feedback_v540(
            $pdo,$user,$namespace,$candidate,$event,
            ['action_type'=>$input['action_type']??'','section'=>$candidate['section']??'','surface'=>'agent_chat_now'],
            mb_strimwidth(trim((string)($input['dedupe']??'')),0,120,'')
        );
        vp3_cognitive_learning_api_json_v540(200,['ok'=>true,'recorded'=>$recorded]);
    }

    vp3_cognitive_learning_api_json_v540(422,['ok'=>false,'error'=>'unknown_action']);
}catch(DomainException|InvalidArgumentException $e){
    vp3_cognitive_learning_api_json_v540(422,['ok'=>false,'error'=>$e->getMessage()]);
}catch(Throwable $e){
    error_log('VP3 Cognitive Learning v5.40 API: '.$e->getMessage());
    vp3_cognitive_learning_api_json_v540(500,['ok'=>false,'error'=>'learning_unavailable']);
}
