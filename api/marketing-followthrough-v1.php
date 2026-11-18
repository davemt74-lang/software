<?php
declare(strict_types=1);

require dirname(__DIR__).'/includes/bootstrap.php';
require_once dirname(__DIR__).'/includes/marketing-followthrough-v1.php';

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: private, no-store, no-cache, must-revalidate, max-age=0');
header('X-Content-Type-Options: nosniff');
try{
    if(strtoupper((string)($_SERVER['REQUEST_METHOD']??''))!=='GET')throw new RuntimeException('Use GET for marketing reports.',405);
    $input=vp3_marketing_followthrough_query_v1((string)($_SERVER['QUERY_STRING']??''));
    $user=current_user();if(!$user)throw new RuntimeException('Sign in to read marketing reports.',401);
    $pdo=db();if(!$pdo)throw new RuntimeException('Marketing storage is unavailable.',503);
    $binding=(string)($_SERVER['HTTP_X_VP3_WORKING_ATTENTION']??'');
    $sessionId=session_id();$owner=(int)$user['id'];
    if($sessionId==='')throw new RuntimeException('Your Chat session changed.',403);
    if(session_status()===PHP_SESSION_ACTIVE)session_write_close();
    $guard=static function()use($sessionId,$owner,$binding):array{
        if(session_status()===PHP_SESSION_ACTIVE)session_write_close();
        $_SESSION=[];session_id($sessionId);
        $opened=session_start(['read_and_close'=>true,'use_cookies'=>false,'use_trans_sid'=>false,'use_strict_mode'=>true,'cache_limiter'=>'']);
        if(!$opened||!hash_equals($sessionId,session_id())){$_SESSION=[];reset_current_user_cache();throw new RuntimeException('Your Chat session changed.',403);}
        reset_current_user_cache();$fresh=current_user();
        if(!$fresh||(int)$fresh['id']!==$owner||$binding===''||!hash_equals(vp3_catch_up_page_binding_v1($fresh),$binding))
            throw new RuntimeException('Your Chat session changed.',403);
        return $fresh;
    };
    echo json_encode(['ok'=>true]+vp3_marketing_followthrough_request_v1($pdo,$user,$input,$binding,$guard),JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
}catch(Throwable $error){
    $status=(int)$error->getCode();if(!in_array($status,[401,403,404,405,409,422,503],true)||$error instanceof PDOException)$status=503;
    if($status===405)header('Allow: GET');
    http_response_code($status);
    $message=match($status){
        401,403=>'Your Chat session or access changed. Reopen the original Chat before continuing.',
        404=>'This publication or its original private Chat is unavailable.',
        405=>'Use GET to read marketing follow-through.',
        409=>'The publication evidence changed. Refresh this exact report before continuing.',
        422=>'Choose a valid publication and its exact original Chat.',
        default=>'Marketing follow-through could not be verified. Try this exact report again.',
    };
    echo json_encode(['ok'=>false,'error'=>$message],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
}
