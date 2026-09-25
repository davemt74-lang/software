<?php
declare(strict_types=1);

require_once dirname(__DIR__).'/includes/bootstrap.php';

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, private');

function homeserver_knowledge_v242_json(array $payload,int $status=200): never
{
    http_response_code($status);
    echo json_encode($payload,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
    exit;
}

$user=current_user();
if(!$user||!has_permission('account.access',$user)||!has_permission('chat.access',$user)){
    homeserver_knowledge_v242_json(['ok'=>false,'error'=>'Knowledge continuity is unavailable for this account.'],403);
}
$userId=(int)($user['id']??0);
$method=strtoupper((string)($_SERVER['REQUEST_METHOD']??'GET'));

if($method==='GET'){
    $query=is_scalar($_GET['q']??null)?trim((string)$_GET['q']):'';
    $limit=max(1,min(300,(int)($_GET['limit']??150)));
    try{
        homeserver_knowledge_v242_json([
          'ok'=>true,
          'knowledge'=>homeserver_knowledge_v242_unified($userId,$query,$limit),
        ]);
    }catch(Throwable $e){
        homeserver_knowledge_v242_json(['ok'=>false,'error'=>'Knowledge continuity could not be loaded.'],503);
    }
}

if($method!=='POST'){
    header('Allow: GET, POST');
    homeserver_knowledge_v242_json(['ok'=>false,'error'=>'GET or POST required.'],405);
}
$input=json_decode((string)file_get_contents('php://input'),true);
if(!is_array($input))$input=$_POST;
$csrf=(string)($input['csrf_token']??'');
if($csrf===''||!hash_equals(csrf_token(),$csrf)){
    homeserver_knowledge_v242_json(['ok'=>false,'error'=>'Session expired. Refresh and try again.'],419);
}
$action=strtolower(trim((string)($input['action']??'')));
$payload=is_array($input['knowledge']??null)?$input['knowledge']:[];
foreach(['canonical_id','mutation_id','expected_revision','title','content','kind','collection_key'] as $key){
    if(array_key_exists($key,$input)&&!array_key_exists($key,$payload))$payload[$key]=$input[$key];
}

try{
    $result=homeserver_knowledge_v242_request_homeserver($userId,$action,$payload);
    homeserver_knowledge_v242_json(['ok'=>true,'action'=>$action,'result'=>$result]);
}catch(Throwable $e){
    $message=trim($e->getMessage());
    $status=preg_match('/(?:changed after this|mutation_id was already used|not a writable|source-managed|already (?:executed|denied|failed|expired))/i',$message)?409:422;
    if($message===''||preg_match('/(?:sql|database|decrypt|token|bearer|curl)/i',$message)){
        $message='Knowledge action could not be completed.';
        $status=503;
    }
    homeserver_knowledge_v242_json(['ok'=>false,'error'=>mb_strimwidth($message,0,500,'')],$status);
}
