<?php
declare(strict_types=1);
require dirname(__DIR__).'/includes/bootstrap.php';

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('X-Content-Type-Options: nosniff');

function reward_tray_json_v110(int $status,array $payload): never
{
    http_response_code($status);
    echo json_encode(['build'=>VP3_CAMPAIGNS_REWARDS_V110]+$payload,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
    exit;
}

function reward_tray_input_v110(): array
{
    $raw=(string)file_get_contents('php://input');
    if(strlen($raw)>32768)reward_tray_json_v110(413,['ok'=>false,'error'=>'payload_too_large']);
    $input=json_decode($raw,true);
    return is_array($input)?$input:$_POST;
}

$user=current_user();
if(!$user||!has_permission('chat.access',$user))reward_tray_json_v110(403,['ok'=>false,'error'=>'forbidden']);
$pdo=db();if(!$pdo)reward_tray_json_v110(503,['ok'=>false,'error'=>'database_unavailable']);
if(!function_exists('campaigns_rewards_v110_schema_ready')||!campaigns_rewards_v110_schema_ready($pdo)){
    reward_tray_json_v110(503,['ok'=>false,'error'=>'rewards_upgrade_required']);
}

$userId=(int)$user['id'];
$method=strtoupper((string)($_SERVER['REQUEST_METHOD']??'GET'));
$input=$method==='POST'?reward_tray_input_v110():$_GET;
$action=trim((string)($input['action']??'state'));

try{
    if($method==='GET'&&$action==='state'){
        reward_tray_json_v110(200,[
            'ok'=>true,
            'tray'=>campaigns_rewards_reward_tray_v110($pdo,$userId),
            'contacts'=>campaigns_rewards_send_contacts_v110($pdo,$userId),
        ]);
    }
    if($method!=='POST')reward_tray_json_v110(405,['ok'=>false,'error'=>'method_not_allowed']);
    if(!hash_equals(csrf_token(),trim((string)($input['csrf_token']??''))))reward_tray_json_v110(419,['ok'=>false,'error'=>'csrf']);

    if($action==='send'){
        $result=campaigns_rewards_transfer_reward_v110(
            $pdo,
            max(0,(int)($input['issuance_id']??0)),
            $userId,
            max(0,(int)($input['contact_id']??0)),
            (string)($input['note']??''),
            (string)($input['idempotency_key']??'')
        );
        reward_tray_json_v110(200,[
            'ok'=>true,'transfer'=>$result,
            'tray'=>campaigns_rewards_reward_tray_v110($pdo,$userId),
            'contacts'=>campaigns_rewards_send_contacts_v110($pdo,$userId),
        ]);
    }

    if($action==='prepare_claim'){
        $claim=campaigns_rewards_prepare_claim_v110($pdo,max(0,(int)($input['issuance_id']??0)),$userId);
        reward_tray_json_v110(200,['ok'=>true,'claim'=>$claim]);
    }

    if($action==='claim'){
        $claim=campaigns_rewards_claim_from_tray_v110(
            $pdo,
            max(0,(int)($input['issuance_id']??0)),
            $userId,
            trim((string)($input['merchant_claim_code']??'')),
            [
                'location_id'=>max(0,(int)($input['location_id']??0)),
                'order_ref'=>(string)($input['order_ref']??''),
                'request_fingerprint'=>(string)($_SERVER['REMOTE_ADDR']??'').'|'.(string)($_SERVER['HTTP_USER_AGENT']??''),
            ]
        );
        reward_tray_json_v110(200,[
            'ok'=>true,'claim'=>$claim,
            'tray'=>campaigns_rewards_reward_tray_v110($pdo,$userId),
        ]);
    }

    reward_tray_json_v110(422,['ok'=>false,'error'=>'unknown_action']);
}catch(DomainException|InvalidArgumentException|RuntimeException $e){
    reward_tray_json_v110(422,['ok'=>false,'error'=>$e->getMessage()]);
}catch(Throwable $e){
    error_log('VP3 Reward Tray V1.10 API: '.$e->getMessage());
    reward_tray_json_v110(500,['ok'=>false,'error'=>'reward_tray_unavailable']);
}
