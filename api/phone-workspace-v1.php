<?php
declare(strict_types=1);
require dirname(__DIR__).'/includes/bootstrap.php';
require_once dirname(__DIR__).'/includes/phone-workspace-v1.php';
require_once dirname(__DIR__).'/includes/phone-browser-v1.php';
require_once dirname(__DIR__).'/includes/phone-recording-pipeline-v1.php';
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, private');
header('X-Content-Type-Options: nosniff');
try{
    $method=(string)($_SERVER['REQUEST_METHOD']??'');$input=$_GET;
    if($method==='POST'){
        if(!str_starts_with(strtolower((string)($_SERVER['CONTENT_TYPE']??'')),'application/json'))vp3_phone_workspace_error_v1('unsupported_media_type',415,'Phone actions require JSON.');
        // Reviewed contact/schedule notes allow 2,000 Unicode characters, including JSON escapes.
        if((int)($_SERVER['CONTENT_LENGTH']??0)>32768)vp3_phone_workspace_error_v1('request_too_large',413,'Phone request is too large.');
        $body=file_get_contents('php://input',false,null,0,32769);if($body===false||strlen($body)>32768)vp3_phone_workspace_error_v1('request_too_large',413,'Phone request is too large.');
        try{$decoded=json_decode($body,false,32,JSON_THROW_ON_ERROR);}catch(JsonException){vp3_phone_workspace_error_v1('invalid_phone_request',422,'Phone requires a JSON object.');}
        if(!is_object($decoded))vp3_phone_workspace_error_v1('invalid_phone_request',422,'Phone requires a JSON object.');
        $input=json_decode($body,true,32,JSON_THROW_ON_ERROR);
    }
    $pdo=db();if(!$pdo)vp3_phone_workspace_error_v1('phone_unavailable',503,'Phone is temporarily unavailable.');
    $result=vp3_phone_workspace_request_v1($pdo,current_user(),$method,$input,(string)($_SESSION['csrf_token']??''));
    echo json_encode(['ok'=>true]+$result,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES);
}catch(VP3PhoneWorkspaceException $error){http_response_code($error->httpStatus);echo json_encode(['ok'=>false,'error'=>$error->publicCode,'message'=>$error->getMessage()]);}
catch(InvalidArgumentException $error){http_response_code(422);echo json_encode(['ok'=>false,'error'=>'invalid_phone_request','message'=>$error->getMessage()]);}
catch(RuntimeException $error){$status=in_array($error->getCode(),[403,404,409,422],true)?$error->getCode():503;http_response_code($status);echo json_encode(['ok'=>false,'error'=>'phone_unavailable','message'=>$status===503?'Phone is temporarily unavailable.':$error->getMessage()]);}
catch(Throwable){http_response_code(503);echo '{"ok":false,"error":"phone_unavailable","message":"Phone is temporarily unavailable."}';}
