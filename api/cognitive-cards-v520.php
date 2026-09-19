<?php
declare(strict_types=1);
require dirname(__DIR__).'/includes/bootstrap.php';

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('X-Content-Type-Options: nosniff');

function vp3_cognitive_cards_api_json_v520(int $status,array $payload): never
{
    http_response_code($status);
    echo json_encode(['build'=>VP3_COGNITIVE_CARDS_V520]+$payload,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
    exit;
}
function vp3_cognitive_cards_api_input_v520(): array
{
    $raw=(string)file_get_contents('php://input');
    if(strlen($raw)>65536)vp3_cognitive_cards_api_json_v520(413,['ok'=>false,'error'=>'payload_too_large']);
    $input=json_decode($raw,true);return is_array($input)?$input:$_POST;
}

$user=current_user();
if(!$user||!has_permission('chat.access',$user))vp3_cognitive_cards_api_json_v520(403,['ok'=>false,'error'=>'forbidden']);
$pdo=db();if(!$pdo)vp3_cognitive_cards_api_json_v520(503,['ok'=>false,'error'=>'database_unavailable']);
if(strtoupper((string)($_SERVER['REQUEST_METHOD']??'GET'))!=='POST')vp3_cognitive_cards_api_json_v520(405,['ok'=>false,'error'=>'method_not_allowed']);
$input=vp3_cognitive_cards_api_input_v520();
if(!hash_equals(csrf_token(),trim((string)($input['csrf_token']??''))))vp3_cognitive_cards_api_json_v520(419,['ok'=>false,'error'=>'csrf']);

try{
    $agentId=max(0,(int)($input['agent_id']??0));$namespace=vp3_cognitive_agent_namespace_v500($pdo,$user,$agentId);
    $requests=is_array($input['cards']??null)?array_values($input['cards']):[];
    if(!$requests||count($requests)>VP3_COGNITIVE_CARDS_BATCH_MAX_V520)vp3_cognitive_cards_api_json_v520(422,['ok'=>false,'error'=>'invalid_card_batch']);
    $items=[];
    foreach($requests as $index=>$request){
        if(!is_array($request)){$items[]=['index'=>$index,'available'=>false,'error'=>'invalid_request'];continue;}
        try{
            $items[]=['index'=>$index,'available'=>true,'card'=>vp3_cognitive_render_card_v500($pdo,$user,$namespace,$request)];
        }catch(Throwable $e){
            $items[]=['index'=>$index,'available'=>false,'error'=>'unavailable'];
        }
    }
    vp3_cognitive_cards_api_json_v520(200,['ok'=>true,'items'=>$items]);
}catch(Throwable $e){
    error_log('VP3 Universal Display Cards v5.20 API: '.$e->getMessage());
    vp3_cognitive_cards_api_json_v520(500,['ok'=>false,'error'=>'cards_unavailable']);
}
