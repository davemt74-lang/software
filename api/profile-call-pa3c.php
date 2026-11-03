<?php
declare(strict_types=1);

require dirname(__DIR__).'/includes/bootstrap.php';
require_once dirname(__DIR__).'/includes/profile-call-pa3c.php';
if(is_file(dirname(__DIR__).'/includes/profile-call-activity-pa3c.php'))require_once dirname(__DIR__).'/includes/profile-call-activity-pa3c.php';

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: private, no-store');
$send=static function(bool $ok,array $body,int $status=200): never{
    http_response_code($status);
    echo json_encode(['ok'=>$ok]+$body,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
};
if(($_SERVER['REQUEST_METHOD']??'GET')!=='POST'){
    header('Allow: POST');
    $send(false,['error'=>'Profile voice calls require POST.','code'=>'METHOD_NOT_ALLOWED'],405);
}
try{
    $raw=file_get_contents('php://input',false,null,0,8193);
    $input=is_string($raw)&&strlen($raw)<=8192?json_decode($raw,true):null;
    if(!is_array($input))vp3_profile_call_error_pa3c('CALL_INVALID',422);
    $side=$input['side']??null;
    $action=$input['action']??null;
    if(!in_array($side,['owner','visitor'],true)||!in_array($action,['state','offer','accept','decline','token','heartbeat','end'],true))vp3_profile_call_error_pa3c('CALL_INVALID',422);
    $cid=vp3_profile_handoff_id_pa3($input['conversation_id']??0,true);
    if($action!=='state'&&$cid<1)vp3_profile_call_error_pa3c('CALL_INVALID',422);
    $pdo=db();
    if(!$pdo||!profile_agent_schema_ready($pdo)||!personal_capability_schema_ready_v242($pdo))vp3_profile_call_error_pa3c('CALL_NOT_READY',503);
    $user=current_user();
    $ctx=null;
    if($side==='owner'){
        if(!$user||!has_permission('account.access',$user))$send(false,['error'=>'Sign in to manage Profile voice calls.','code'=>'AUTH_REQUIRED'],401);
        $csrf=$input['csrf_token']??null;
        if(!is_string($csrf)||$csrf===''||!hash_equals(csrf_token(),$csrf))$send(false,['error'=>'Session expired. Refresh and try again.','code'=>'CSRF_FAILED'],419);
        $owner=(int)vp3_profile_handoff_owner_pa3($pdo,$user)['id'];
    }else{
        $username=$input['username']??null;
        if(!is_string($username)||strlen($username)>100)vp3_profile_call_error_pa3c('CALL_INVALID',422);
        $profile=profile_by_username($pdo,$username);
        if(!$profile||empty($profile['is_active'])||empty($profile['is_public']))vp3_profile_call_error_pa3c('CALL_NOT_FOUND',404);
        $owner=(int)$profile['user_id'];
        if($owner===(int)($user['id']??0))vp3_profile_call_error_pa3c('CALL_NOT_FOUND',404);
        $token=$input['profile_token']??null;
        if(!is_string($token)||!profile_chat_token_valid($owner,$token))$send(false,['error'=>'Profile session expired. Refresh and try again.','code'=>'PROFILE_TOKEN_FAILED'],419);
        $namespace=$input['conversation_namespace']??null;
        $expected=profile_conversation_browser_namespace_v1($owner,(int)($user['id']??0));
        if(!is_string($namespace)||!hash_equals($expected,$namespace))$send(false,['error'=>'Your visitor session changed. Refresh the Profile page.','code'=>'PROFILE_SESSION_CHANGED'],409);
        if($cid>0)$ctx=vp3_profile_agent_public_context_v110($pdo,$profile,$user,true);
        elseif(!profile_active_agent($pdo,$profile))vp3_profile_call_error_pa3c('CALL_NOT_FOUND',404);
    }
    // All cookie authority has already been resolved. Do not hold the PHP
    // session lock while the provider disconnects or dispatches a room.
    if(session_status()===PHP_SESSION_ACTIVE)session_write_close();
    $response=vp3_profile_call_action_pa3c($pdo,$owner,$cid,$side,$action,$input,$ctx);
    if(!empty($response['call_changed']))$send(false,['error'=>'This voice call changed. Refresh its status and try again.','code'=>'CALL_CHANGED']+$response,409);
    $send(true,$response);
}catch(VP3ProfileAgentPublicException|VP3ProfileHandoffException $e){
    $send(false,['error'=>$e->getMessage(),'code'=>$e->publicCode],$e->httpStatus);
}catch(Throwable $e){
    error_log('Profile voice call request failed ('.get_class($e).').');
    $send(false,['error'=>'The Profile voice call could not be completed.','code'=>'CALL_FAILED'],500);
}
