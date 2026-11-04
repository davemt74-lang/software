<?php
declare(strict_types=1);
require dirname(__DIR__).'/includes/bootstrap.php';
require_once dirname(__DIR__).'/includes/profile-agent-public-service-v110.php';
require_once dirname(__DIR__).'/includes/profile-conversation-checkout-pa5.php';

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, private');
header('Referrer-Policy: no-referrer');
header('X-Content-Type-Options: nosniff');

function profile_checkout_json_pa5(bool $ok, array $data, int $status=200): never
{
    http_response_code($status);
    echo json_encode(['ok'=>$ok]+$data, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
    exit;
}

function profile_checkout_session_pa5(PDO $pdo, array $profile, array $input): array
{
    $token=$input['profile_token']??null;
    $owner=(int)$profile['user_id'];
    if(!is_string($token)||!preg_match('/^[a-f0-9]{48}$/D',$token)
        ||empty($_SESSION['profile_chat_tokens'][(string)$owner])||!profile_chat_token_valid($owner,$token)){
        throw new VP3ProfileAgentPublicException('PROFILE_SESSION_EXPIRED','Profile session expired. Refresh and try again.',419);
    }
    $context=vp3_profile_agent_public_context_v110($pdo,$profile,current_user(),true);
    $namespace=$input['conversation_namespace']??null;
    $expected=hash('sha256','profile-widget-v1|'.$context['conversation_authority']['credential_hash']);
    if(!is_string($namespace)||!preg_match('/^[a-f0-9]{64}$/D',$namespace)||!hash_equals($expected,$namespace)){
        throw new VP3ProfileAgentPublicException('PROFILE_SESSION_CHANGED','Your visitor session changed. Refresh this profile before continuing.',409);
    }
    return $context;
}

function profile_checkout_refresh_session_pa5(string $id): void
{
    // A delayed provider response must not restore a deleted session or set
    // an old cookie after the visitor signs in or out in another tab.
    $_SESSION=[];
    $opened=session_start(['read_and_close'=>true,'use_cookies'=>false,'use_trans_sid'=>false,'use_strict_mode'=>true,'cache_limiter'=>'']);
    if(!$opened||!hash_equals($id,session_id())){
        throw new VP3ProfileAgentPublicException('PROFILE_SESSION_CHANGED','Your visitor session changed. Refresh this profile before continuing.',409);
    }
    reset_current_user_cache();
}

if(strtoupper((string)($_SERVER['REQUEST_METHOD']??''))!=='POST'){
    header('Allow: POST');
    profile_checkout_json_pa5(false,['code'=>'METHOD_NOT_ALLOWED','error'=>'This action requires POST.'],405);
}

try{
    $contentType=strtolower(trim(explode(';',(string)($_SERVER['CONTENT_TYPE']??''),2)[0]));
    if($contentType!=='application/json'){
        profile_checkout_json_pa5(false,['code'=>'PROFILE_CHECKOUT_INVALID','error'=>'Send the checkout request as JSON.'],422);
    }
    $raw=(string)file_get_contents('php://input',false,null,0,8193);
    if(strlen($raw)>8192||(int)($_SERVER['CONTENT_LENGTH']??0)>8192){
        profile_checkout_json_pa5(false,['code'=>'REQUEST_TOO_LARGE','error'=>'The checkout request is too large.'],413);
    }
    try{$object=json_decode($raw,false,8,JSON_THROW_ON_ERROR);}
    catch(JsonException){$object=null;}
    $input=$object instanceof stdClass?get_object_vars($object):[];
    $action=$input['action']??null;
    $username=$input['username']??null;
    $cid=$input['conversation_id']??null;
    if(!in_array($action,['catalog','prepare','confirm','status'],true)||!is_string($username)
        ||!profile_username_valid($username)||!is_int($cid)||$cid<0||$cid>9007199254740991
        ||($cid===0&&in_array($action,['confirm','status'],true))){
        profile_checkout_json_pa5(false,['code'=>'PROFILE_CHECKOUT_INVALID','error'=>'Choose a valid Profile checkout action and conversation.'],422);
    }
    $pdo=db();
    if(!$pdo)profile_checkout_json_pa5(false,['code'=>'PROFILE_CHECKOUT_UNAVAILABLE','error'=>'Profile checkout is temporarily unavailable.'],503);
    $profile=profile_by_username($pdo,$username);
    if(!$profile||empty($profile['is_active'])||empty($profile['is_public']))vp3_profile_agent_public_unavailable_v1();
    $context=profile_checkout_session_pa5($pdo,$profile,$input);
    if($cid>0)vp3_profile_agent_public_bound_conversation_v1($pdo,$context,$cid);
    $unlocked=$action==='confirm'&&session_status()===PHP_SESSION_ACTIVE;
    if($unlocked){
        $sessionId=session_id();
        session_write_close();
    }
    try{
        $result=match($action){
            'catalog'=>vp3_profile_checkout_catalog_pa5($pdo,$context['profile']),
            'prepare'=>vp3_profile_checkout_prepare_pa5($pdo,$context['profile'],$context,$cid,$input),
            'confirm'=>vp3_profile_checkout_confirm_pa5($pdo,$context['profile'],$context,$cid,$input),
            'status'=>vp3_profile_checkout_state_pa5($pdo,$context['profile'],$context,$cid),
        };
    }finally{
        if($unlocked){
            profile_checkout_refresh_session_pa5($sessionId);
            $context=profile_checkout_session_pa5($pdo,$profile,$input);
            vp3_profile_agent_public_bound_conversation_v1($pdo,$context,$cid);
        }
    }
    profile_checkout_json_pa5(true,['contract'=>'vp3.profile-conversation-checkout.v1']+$result+['conversation_id'=>$cid]);
}catch(VP3ProfileCheckoutException|VP3ProfileAgentPublicException $e){
    profile_checkout_json_pa5(false,['code'=>$e->publicCode,'error'=>$e->getMessage()],$e->httpStatus);
}catch(Throwable $e){
    error_log('Profile conversation checkout failed ('.get_class($e).').');
    profile_checkout_json_pa5(false,['code'=>'PROFILE_CHECKOUT_FAILED','error'=>'Profile checkout could not complete this request. Try again.'],500);
}
