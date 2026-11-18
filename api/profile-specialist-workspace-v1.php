<?php
declare(strict_types=1);

require dirname(__DIR__).'/includes/bootstrap.php';
require_once dirname(__DIR__).'/includes/profile-specialist-workspace-v1.php';

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('X-Content-Type-Options: nosniff');

try{
    $method=strtoupper((string)($_SERVER['REQUEST_METHOD']??''));
    if(!in_array($method,['GET','POST'],true))throw new RuntimeException('Use GET or POST for Profile workspace actions.',405);
    if($method==='POST'){
        if((int)($_SERVER['CONTENT_LENGTH']??0)>24576)throw new RuntimeException('Profile request is too large.',413);
        $raw=(string)file_get_contents('php://input',false,null,0,24577);
        if(strlen($raw)>24576)throw new RuntimeException('Profile request is too large.',413);
        try{$object=json_decode($raw,false,12,JSON_THROW_ON_ERROR);}catch(Throwable){throw new RuntimeException('Choose a valid Profile action.',422);}
        if(!$object instanceof stdClass)throw new RuntimeException('Choose a valid Profile action.',422);
        $input=get_object_vars($object);
        if(!is_string($input['csrf_token']??null)||$input['csrf_token']===''||!hash_equals(csrf_token(),$input['csrf_token']))throw new RuntimeException('Your Profile session expired.',419);
    }else $input=$_GET;
    $user=current_user();if(!$user)throw new RuntimeException('Sign in to open the Profile workspace.',401);
    $pdo=db();if(!$pdo)throw new RuntimeException('Profile storage is unavailable.',503);
    $binding=(string)($_SERVER['HTTP_X_VP3_WORKING_ATTENTION']??'');
    $sessionId=session_id();$owner=(int)$user['id'];
    if($sessionId==='')throw new RuntimeException('Your Profile session changed.',403);
    if(session_status()===PHP_SESSION_ACTIVE)session_write_close();
    // Every long-I/O boundary reopens the actual browser session. A stale
    // principal must never restore a deleted session or replace a newer cookie.
    $guard=static function()use($sessionId,$owner,$binding):array{
        if(session_status()===PHP_SESSION_ACTIVE)session_write_close();
        $_SESSION=[];session_id($sessionId);
        $opened=session_start(['read_and_close'=>true,'use_cookies'=>false,'use_trans_sid'=>false,'use_strict_mode'=>true,'cache_limiter'=>'']);
        if(!$opened||!hash_equals($sessionId,session_id())){$_SESSION=[];reset_current_user_cache();throw new RuntimeException('Your Profile session changed.',403);}
        reset_current_user_cache();$fresh=current_user();
        if(!$fresh||(int)$fresh['id']!==$owner||$binding===''||!hash_equals(vp3_catch_up_page_binding_v1($fresh),$binding))throw new RuntimeException('Your Profile session changed.',403);
        return $fresh;
    };
    $state=vp3_profile_workspace_request_v1($pdo,$user,$method,$input,$binding,$guard);
    echo json_encode(['ok'=>true]+$state,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
}catch(Throwable $error){
    $status=property_exists($error,'httpStatus')?(int)$error->httpStatus:(int)$error->getCode();
    if(!in_array($status,[401,403,404,405,409,413,419,422,503],true))$status=503;
    if($status===405)header('Allow: GET, POST');
    http_response_code($status);
    $message=match($status){
        401,403=>'Your Profile session or access changed. Refresh before continuing.',
        404=>'This Profile conversation or record is unavailable.',
        405=>'Use the correct method for this Profile action.',
        409=>'This Profile request or visitor conversation changed. Check the current conversation before continuing.',
        413=>'The Profile request is too large.',419=>'Your Profile session expired. Refresh before continuing.',
        422=>'Choose a valid Profile action and exact conversation.',
        default=>'Profile workspace could not be refreshed. Check its current status before trying again.',
    };
    echo json_encode(['ok'=>false,'error'=>$message],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
}
