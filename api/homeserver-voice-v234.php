<?php
declare(strict_types=1);

require_once dirname(__DIR__).'/includes/bootstrap.php';

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, private');

$user=current_user();
if(!$user||!has_permission('chat.access',$user)){
    http_response_code(403);
    echo json_encode(['ok'=>false,'error'=>'Agent voice access unavailable.']);
    exit;
}
$userId=(int)$user['id'];

function homeserver_voice_v234_api(array $payload,int $status=200): never
{
    http_response_code($status);
    echo json_encode($payload,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
    exit;
}

$method=strtoupper((string)($_SERVER['REQUEST_METHOD']??'GET'));
if($method==='GET'){
    $status=homeserver_voice_v234_status($userId,true);
    homeserver_voice_v234_api(['ok'=>true,'status'=>$status]);
}
if($method!=='POST'){
    header('Allow: GET, POST');
    homeserver_voice_v234_api(['ok'=>false,'error'=>'GET or POST required.'],405);
}

$input=json_decode((string)file_get_contents('php://input'),true);
if(!is_array($input))$input=$_POST;
if(!hash_equals(csrf_token(),(string)($input['csrf_token']??''))){
    homeserver_voice_v234_api(['ok'=>false,'error'=>'Session expired.'],419);
}
$action=strtolower(trim((string)($input['action']??'transcribe')));
if($action!=='transcribe')homeserver_voice_v234_api(['ok'=>false,'error'=>'Unsupported HomeServer voice action.'],422);

$encoded=trim((string)($input['audio_base64']??''));
if($encoded===''||strlen($encoded)>300000){
    homeserver_voice_v234_api(['ok'=>false,'error'=>'Recorded audio exceeds the HomeServer relay limit.'],413);
}
$audio=base64_decode($encoded,true);
if(!is_string($audio)){
    homeserver_voice_v234_api(['ok'=>false,'error'=>'Recorded audio is not valid base64 WAV data.'],422);
}
try{
    $result=homeserver_voice_v234_transcribe($userId,$audio);
    homeserver_voice_v234_api([
      'ok'=>true,
      'text'=>(string)$result['text'],
      'provider'=>(string)$result['provider'],
      'model'=>(string)$result['model'],
      'execution'=>$result['execution']??null,
    ]);
}catch(Throwable $e){
    homeserver_voice_v234_api(['ok'=>false,'error'=>'HomeServer local transcription is temporarily unavailable.'],503);
}
