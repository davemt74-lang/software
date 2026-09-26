<?php
declare(strict_types=1);

require_once dirname(__DIR__).'/includes/bootstrap.php';

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, private');

function homeserver_agent_brain_memory_v245_json(array $payload,int $status=200): never
{
    http_response_code($status);
    echo json_encode($payload,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
    exit;
}

$user=current_user();
if(!$user||!has_permission('account.access',$user)||!has_permission('chat.access',$user)){
    homeserver_agent_brain_memory_v245_json(['ok'=>false,'error'=>'Agent Brain memory continuity is unavailable for this account.'],403);
}
$uid=(int)($user['id']??0);$method=strtoupper((string)($_SERVER['REQUEST_METHOD']??'GET'));

if($method==='GET'){
    $query=is_scalar($_GET['q']??null)?trim((string)$_GET['q']):'';
    $limit=max(1,min(250,(int)($_GET['limit']??120)));
    homeserver_agent_brain_memory_v245_json([
      'ok'=>true,
      'memory'=>homeserver_agent_brain_memory_v245_unified($user,$query,$limit),
    ]);
}
if($method!=='POST'){
    header('Allow: GET, POST');
    homeserver_agent_brain_memory_v245_json(['ok'=>false,'error'=>'GET or POST required.'],405);
}
$input=json_decode((string)file_get_contents('php://input'),true);if(!is_array($input))$input=$_POST;
$csrf=(string)($input['csrf_token']??'');
if($csrf===''||!hash_equals(csrf_token(),$csrf)){
    homeserver_agent_brain_memory_v245_json(['ok'=>false,'error'=>'Session expired. Refresh and try again.'],419);
}
$action=strtolower(trim((string)($input['action']??'')));
$payload=is_array($input['memory']??null)?$input['memory']:[];
foreach(['canonical_id','mutation_id','expected_revision','content','memory_key','memory_type','entity_type','entity_key','importance','confidence'] as $key){
    if(array_key_exists($key,$input)&&!array_key_exists($key,$payload))$payload[$key]=$input[$key];
}
try{
    $result=homeserver_agent_brain_memory_v245_request_homeserver($uid,$action,$payload);
    homeserver_agent_brain_memory_v245_json(['ok'=>true,'action'=>$action,'result'=>$result]);
}catch(Throwable $e){
    $message=trim($e->getMessage());
    $status=preg_match('/(?:changed after|mutation_id|writable HomeServer|expected_revision|already)/i',$message)?409:422;
    if($message===''||preg_match('/(?:sql|database|decrypt|bearer|curl|token)/i',$message)){
        $message='Memory action could not be completed.';$status=503;
    }
    homeserver_agent_brain_memory_v245_json(['ok'=>false,'error'=>mb_strimwidth($message,0,500,'')],$status);
}
