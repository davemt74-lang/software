<?php
declare(strict_types=1);

require_once dirname(__DIR__).'/includes/bootstrap.php';

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, private');

function homeserver_contacts_v241_json(array $payload,int $status=200): never
{
    http_response_code($status);
    echo json_encode($payload,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
    exit;
}

$user=current_user();
if(!$user||!has_permission('account.access',$user)||!has_permission('chat.access',$user)){
    homeserver_contacts_v241_json(['ok'=>false,'error'=>'Contacts continuity is unavailable for this account.'],403);
}
$userId=(int)($user['id']??0);
$method=strtoupper((string)($_SERVER['REQUEST_METHOD']??'GET'));

if($method==='GET'){
    $query=is_scalar($_GET['q']??null)?trim((string)$_GET['q']):'';
    $limit=max(1,min(500,(int)($_GET['limit']??250)));
    try{
        homeserver_contacts_v241_json([
          'ok'=>true,
          'contacts'=>homeserver_contacts_v241_unified($userId,$query,$limit),
        ]);
    }catch(Throwable $e){
        homeserver_contacts_v241_json(['ok'=>false,'error'=>'Contacts continuity could not be loaded.'],503);
    }
}

if($method!=='POST'){
    header('Allow: GET, POST');
    homeserver_contacts_v241_json(['ok'=>false,'error'=>'GET or POST required.'],405);
}
$input=json_decode((string)file_get_contents('php://input'),true);
if(!is_array($input))$input=$_POST;
$csrf=(string)($input['csrf_token']??'');
if($csrf===''||!hash_equals(csrf_token(),$csrf)){
    homeserver_contacts_v241_json(['ok'=>false,'error'=>'Session expired. Refresh and try again.'],419);
}
$action=strtolower(trim((string)($input['action']??'')));
$payload=is_array($input['contact']??null)?$input['contact']:[];
if(in_array($action,['update','delete'],true)&&isset($input['canonical_id'])){
    $payload['canonical_id']=(string)$input['canonical_id'];
}

try{
    $result=homeserver_contacts_v241_request_homeserver($userId,$action,$payload);
    homeserver_contacts_v241_json(['ok'=>true,'action'=>$action,'result'=>$result]);
}catch(Throwable $e){
    $message=trim($e->getMessage());
    if($message===''||preg_match('/(?:sql|database|decrypt|token|bearer|curl)/i',$message)){
        $message='HomeServer contact action could not be completed.';
    }
    homeserver_contacts_v241_json(['ok'=>false,'error'=>mb_strimwidth($message,0,500,'')],422);
}
