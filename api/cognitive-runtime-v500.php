<?php
declare(strict_types=1);

require dirname(__DIR__).'/includes/bootstrap.php';

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('X-Content-Type-Options: nosniff');

function vp3_cognitive_api_json_v500(int $status,array $payload): never
{
    http_response_code($status);
    echo json_encode(['build'=>VP3_COGNITIVE_RUNTIME_V500,'contract'=>VP3_COGNITIVE_CONTRACT_V500]+$payload,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
    exit;
}
function vp3_cognitive_api_input_v500(): array
{
    $raw=(string)file_get_contents('php://input');
    if(strlen($raw)>32768)vp3_cognitive_api_json_v500(413,['ok'=>false,'error'=>'payload_too_large']);
    $input=json_decode($raw,true);
    return is_array($input)?$input:$_POST;
}

$user=current_user();
if(!$user||!has_permission('chat.access',$user))vp3_cognitive_api_json_v500(403,['ok'=>false,'error'=>'forbidden']);
$pdo=db();
if(!$pdo)vp3_cognitive_api_json_v500(503,['ok'=>false,'error'=>'database_unavailable']);
$method=strtoupper((string)($_SERVER['REQUEST_METHOD']??'GET'));
$action=trim((string)($_GET['action']??'state'));

try{
    $agentId=max(0,(int)($_GET['agent_id']??0));
    $namespace=vp3_cognitive_agent_namespace_v500($pdo,$user,$agentId);

    if($method==='GET'&&$action==='state'){
        vp3_cognitive_api_json_v500(200,['ok'=>true,'state'=>vp3_cognitive_state_v500($pdo,$user,$namespace)]);
    }
    if($method==='GET'&&$action==='observations'){
        if(!vp3_cognitive_schema_ready_v500($pdo))vp3_cognitive_api_json_v500(503,['ok'=>false,'error'=>'cognitive_schema_not_ready']);
        vp3_cognitive_api_json_v500(200,['ok'=>true,'agent_namespace'=>$namespace,'items'=>vp3_cognitive_recent_observations_v500($pdo,$user,$namespace,max(1,min(100,(int)($_GET['limit']??20))))]);
    }
    if($method!=='POST')vp3_cognitive_api_json_v500(405,['ok'=>false,'error'=>'method_not_allowed']);
    $input=vp3_cognitive_api_input_v500();
    if(!hash_equals(csrf_token(),trim((string)($input['csrf_token']??''))))vp3_cognitive_api_json_v500(419,['ok'=>false,'error'=>'csrf']);
    $agentId=max(0,(int)($input['agent_id']??$agentId));
    $namespace=vp3_cognitive_agent_namespace_v500($pdo,$user,$agentId);
    $action=trim((string)($input['action']??$action));

    if($action==='render_card'){
        $card=is_array($input['card']??null)?$input['card']:[];
        vp3_cognitive_api_json_v500(200,['ok'=>true,'card'=>vp3_cognitive_render_card_v500($pdo,$user,$namespace,$card)]);
    }
    if($action==='context'){
        $ref=is_array($input['object_ref']??null)?$input['object_ref']:[];
        vp3_cognitive_api_json_v500(200,[
            'ok'=>true,
            'context'=>vp3_cognitive_context_for_ref_v500($pdo,$user,$namespace,$ref),
            'relationships'=>vp3_cognitive_relationships_for_ref_v500($pdo,$user,$namespace,$ref),
        ]);
    }
    vp3_cognitive_api_json_v500(422,['ok'=>false,'error'=>'unknown_action']);
}catch(InvalidArgumentException $e){
    vp3_cognitive_api_json_v500(422,['ok'=>false,'error'=>$e->getMessage()]);
}catch(RuntimeException $e){
    vp3_cognitive_api_json_v500(403,['ok'=>false,'error'=>$e->getMessage()]);
}catch(Throwable $e){
    error_log('VP3 Cognitive Runtime v5.00 API: '.$e->getMessage());
    vp3_cognitive_api_json_v500(500,['ok'=>false,'error'=>'cognitive_runtime_unavailable']);
}
