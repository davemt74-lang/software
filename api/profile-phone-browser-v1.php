<?php
declare(strict_types=1);
require dirname(__DIR__).'/includes/bootstrap.php';
require_once dirname(__DIR__).'/includes/profile-browser-phone-v1.php';
header('Content-Type: application/json; charset=UTF-8');header('Cache-Control: no-store, private');header('X-Content-Type-Options: nosniff');
try{
    $method=strtoupper((string)($_SERVER['REQUEST_METHOD']??''));if(!in_array($method,['GET','POST'],true)){header('Allow: GET, POST');vp3_profile_browser_phone_error_v1('METHOD_NOT_ALLOWED',405,'Use GET or POST.');}
    if($method==='POST'){
        if(!str_starts_with(strtolower((string)($_SERVER['CONTENT_TYPE']??'')),'application/json'))vp3_profile_browser_phone_error_v1('PROFILE_PHONE_INVALID',422,'Use a JSON call request.');
        $raw=file_get_contents('php://input',false,null,0,4097);if(!is_string($raw)||strlen($raw)>4096)vp3_profile_browser_phone_error_v1('PROFILE_PHONE_INVALID',413,'The call request is too large.');
        $input=json_decode($raw,true,8,JSON_THROW_ON_ERROR);
    }else $input=$_GET;
    if(!is_array($input)||array_is_list($input)||array_diff(array_keys($input),['action','username','profile_token','conversation_namespace','conversation_id','client_request_id','call_id']))vp3_profile_browser_phone_error_v1('PROFILE_PHONE_INVALID',422,'Choose a supported call action.');
    $action=$input['action']??null;if(!in_array($action,['prepare','state','end'],true))vp3_profile_browser_phone_error_v1('PROFILE_PHONE_INVALID',422,'Choose a supported call action.');
    if(($action==='state')!==($method==='GET')){header('Allow: '.($action==='state'?'GET':'POST'));vp3_profile_browser_phone_error_v1('METHOD_NOT_ALLOWED',405,'Use the required call request method.');}
    if(!is_string($input['username']??null)||!profile_username_valid($input['username']))vp3_profile_browser_phone_error_v1('PROFILE_PHONE_NOT_FOUND',404,'This Profile is unavailable.');
    $input['conversation_id']=vp3_profile_handoff_id_pa3($input['conversation_id']??0,true);vp3_profile_browser_phone_request_id_v1($input['client_request_id']??null);
    $pdo=db();if(!$pdo instanceof PDO)vp3_profile_browser_phone_error_v1('PROFILE_PHONE_NOT_READY',503,'Profile calling is unavailable.');
    $profile=profile_by_username($pdo,$input['username']);if(!$profile)vp3_profile_browser_phone_error_v1('PROFILE_PHONE_NOT_FOUND',404,'This Profile is unavailable.');
    // Calls use an existing native visitor session; reads never create a visit.
    $ctx=vp3_profile_agent_public_context_v110($pdo,$profile,current_user(),true);
    $namespace=$input['conversation_namespace']??null;$expected=hash('sha256','profile-widget-v1|'.$ctx['conversation_authority']['credential_hash']);
    if(!is_string($namespace)||!hash_equals($expected,$namespace))throw new VP3ProfileAgentPublicException('PROFILE_SESSION_CHANGED','Your visitor session changed. Refresh this profile before continuing.',409);
    if($method==='POST'&&(!is_string($input['profile_token']??null)||!profile_chat_token_valid((int)$ctx['owner_user_id'],$input['profile_token'])))throw new VP3ProfileAgentPublicException('PROFILE_SESSION_EXPIRED','Profile session expired. Refresh and try again.',419);
    $result=match($action){'prepare'=>vp3_profile_browser_phone_prepare_v1($pdo,$ctx,$input),'end'=>vp3_profile_browser_phone_end_v1($pdo,$ctx,$input),default=>vp3_profile_browser_phone_state_v1($pdo,$ctx,$input)};
    vp3_profile_agent_public_live_v1($pdo,$ctx);echo json_encode(['ok'=>true]+$result,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES);
}catch(Throwable $e){
    if($e instanceof VP3ProfileBrowserPhoneException||$e instanceof VP3ProfileHandoffException||$e instanceof VP3ProfileCallException||$e instanceof VP3ProfileAgentPublicException){$status=$e->httpStatus;$code=$e->publicCode;$message=$e->getMessage();}
    elseif($e instanceof JsonException){$status=422;$code='PROFILE_PHONE_INVALID';$message='Choose a valid call request.';}
    else{$status=503;$code='PROFILE_PHONE_UNAVAILABLE';$message='Profile calling could not be confirmed. Check the call status before trying again.';error_log('Profile browser call failed ('.get_class($e).').');}
    http_response_code($status);echo json_encode(['ok'=>false,'code'=>$code,'error'=>$message],JSON_UNESCAPED_SLASHES);
}
