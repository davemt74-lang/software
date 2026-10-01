<?php
declare(strict_types=1);
require __DIR__.'/../includes/bootstrap.php';
require_once __DIR__.'/../includes/homeserver-device-code-v1.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private, max-age=0');
header('X-Content-Type-Options: nosniff');
if(($_SERVER['REQUEST_METHOD']??'GET')!=='POST'){http_response_code(405);echo json_encode(['ok'=>false,'error'=>'POST required.']);exit;}
if((int)($_SERVER['CONTENT_LENGTH']??0)>4096){http_response_code(413);echo json_encode(['ok'=>false,'error'=>'Request too large.']);exit;}
$input=json_decode((string)file_get_contents('php://input'),true);
if(!is_array($input)){http_response_code(400);echo json_encode(['ok'=>false,'error'=>'Invalid request.']);exit;}
$action=(string)($input['action']??'');
try{
    if($action==='claim'){
        $user=current_user();
        if(!$user||!has_permission('account.access',$user)){http_response_code(401);echo json_encode(['ok'=>false,'error'=>'Sign in to VP3 Cloud first.']);exit;}
        $csrf=(string)($input['csrf_token']??'');
        if(!$csrf||!hash_equals(csrf_token(),$csrf)){http_response_code(419);echo json_encode(['ok'=>false,'error'=>'Session expired. Refresh the page.']);exit;}
        $result=hs_device_v1_claim((int)$user['id'],(string)($input['code']??''));
    }elseif($action==='start'){
        $result=hs_device_v1_start((string)($input['code']??''),(string)($input['verifier']??''),(string)($input['device_id']??''),(string)($_SERVER['REMOTE_ADDR']??'unknown'));
    }elseif($action==='poll'){
        $result=hs_device_v1_poll((string)($input['code']??''),(string)($input['verifier']??''),(string)($input['device_id']??''));
    }elseif($action==='complete'){
        $result=hs_device_v1_complete((string)($input['code']??''),(string)($input['verifier']??''),(string)($input['device_id']??''));
    }else{http_response_code(422);echo json_encode(['ok'=>false,'error'=>'Unsupported device pairing action.']);exit;}
    echo json_encode(['ok'=>true]+$result,JSON_UNESCAPED_SLASHES);
}catch(Throwable $e){
    $msg=$e->getMessage();
    $expected=['Use the 12-character','Device authorization is invalid','HomeServer device identity is invalid','Too many new pairing requests','That pairing code is unavailable','That code has expired','A HomeServer connection already exists','Could not complete device authorization'];
    $safe='Device pairing could not be completed. Try again.';
    foreach($expected as $prefix){if(str_starts_with($msg,$prefix)){$safe=$msg;break;}}
    http_response_code(422);echo json_encode(['ok'=>false,'error'=>$safe]);
}
