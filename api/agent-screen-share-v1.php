<?php
declare(strict_types=1);
require dirname(__DIR__).'/includes/bootstrap.php';
require_once dirname(__DIR__).'/includes/agent-screen-share-v1.php';
header('Content-Type: application/json; charset=UTF-8');header('Cache-Control: private, no-store');header('X-Content-Type-Options: nosniff');
$claim='';$uid=0;$status=200;$response=[];
try{
    if(($_SERVER['REQUEST_METHOD']??'')!=='POST')throw new RuntimeException('POST required.',405);
    $raw=file_get_contents('php://input',false,null,0,450001);if(strlen($raw)>450000)throw new RuntimeException('Screen frame exceeds the upload budget.',413);
    $input=json_decode($raw,true,12,JSON_THROW_ON_ERROR);if(!is_array($input)||array_is_list($input))throw new InvalidArgumentException('Use a screen request object.');
    $home=trim((string)($_SERVER['HTTP_X_HOMESERVER_DEVICE']??''))!=='';
    if($home){
        require_once dirname(__DIR__).'/includes/extension-device-auth-v2001.php';
        $auth=(string)($_SERVER['HTTP_AUTHORIZATION']??($_SERVER['REDIRECT_HTTP_AUTHORIZATION']??''));
        if(!preg_match('/^Bearer\s+(.+)$/i',$auth,$m))throw new RuntimeException('HomeServer authentication required.',401);
        $paired=homeserver_https_v1300_authenticate(trim($m[1]),trim((string)$_SERVER['HTTP_X_HOMESERVER_DEVICE']));
        $user=vp3_extension_user_for_permission_v2001(db(),(int)$paired['user_id']);
    }else{
        $user=current_user();if(!$user)throw new RuntimeException('Sign in before sharing a screen.',403);
        if(!hash_equals(csrf_token(),(string)($input['csrf_token']??'')))throw new RuntimeException('Refresh this page before sharing.',419);
        if((string)($input['owner_key']??'')!==(string)$user['id'])throw new RuntimeException('Screen account changed. Refresh this page.',409);
    }
    if(!$user||!has_permission('chat.access',$user))throw new RuntimeException('Agent Chat access required.',403);
    $uid=(int)$user['id'];$frame=screen_frame_v1($input);
    $claim=screen_budget_v1($uid);
    session_write_close(); // The provider must not lock Chat or other requests in this browser.
    $observation=screen_analyze_v1($frame,$user);
    $response=['ok'=>true,'observation'=>$observation,'raw_images_stored'=>false];
}catch(Throwable $e){
    $code=(int)$e->getCode();$status=in_array($code,[401,403,405,409,413,419,429,502,503],true)?$code:422;
    $response=['ok'=>false,'error'=>$e instanceof JsonException?'Invalid screen request.':$e->getMessage()];
}finally{
    if($claim!==''){
        try{screen_budget_v1($uid,$claim);}catch(Throwable $ignored){} // The bounded lease expires if release storage is unavailable.
    }
}

http_response_code($status);echo json_encode($response,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
