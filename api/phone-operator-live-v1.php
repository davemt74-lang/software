<?php
declare(strict_types=1);
require dirname(__DIR__).'/includes/bootstrap.php';
require_once dirname(__DIR__).'/includes/agent-operator-live-v1.php';
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, private');
header('X-Content-Type-Options: nosniff');
try{
    if(($_SERVER['REQUEST_METHOD']??'')!=='POST')vp3_phone_workspace_error_v1('method_not_allowed',405,'Live captions require POST.');
    if(!str_starts_with(strtolower((string)($_SERVER['CONTENT_TYPE']??'')),'application/json'))
        vp3_phone_workspace_error_v1('unsupported_media_type',415,'Live captions require JSON.');
    if((int)($_SERVER['CONTENT_LENGTH']??0)>350000)vp3_phone_workspace_error_v1('request_too_large',413,'Caption chunk is too large.');
    $body=file_get_contents('php://input',false,null,0,350001);
    if($body===false||strlen($body)>350000)vp3_phone_workspace_error_v1('request_too_large',413,'Caption chunk is too large.');
    try{$input=json_decode($body,true,16,JSON_THROW_ON_ERROR);}catch(JsonException){$input=null;}
    if(!is_array($input)||array_is_list($input))vp3_phone_workspace_error_v1('operator_live_request',422,'Invalid caption request.');
    $pdo=db();$user=current_user();$csrf=(string)($_SESSION['csrf_token']??'');
    // Do not hold the PHP session lock across Cloud speech; Stop must be able
    // to revoke permission while a request is pending.
    if(session_status()===PHP_SESSION_ACTIVE)session_write_close();
    if(!$pdo)vp3_phone_workspace_error_v1('phone_unavailable',503,'Phone is temporarily unavailable.');
    $out=vp3_operator_live_request_v1($pdo,$user,'POST',$input,$csrf);
    echo json_encode(['ok'=>true]+$out,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES);
}catch(VP3PhoneWorkspaceException $error){
    http_response_code($error->httpStatus);echo json_encode(['ok'=>false,'error'=>$error->publicCode,'message'=>$error->getMessage()]);
}catch(Throwable){
    http_response_code(503);echo '{"ok":false,"error":"operator_live_unavailable","message":"Live captions could not be verified."}';
}
