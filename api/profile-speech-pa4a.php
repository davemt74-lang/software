<?php
declare(strict_types=1);
require dirname(__DIR__).'/includes/bootstrap.php';
require_once dirname(__DIR__).'/includes/profile-visitor-speech-pa4a.php';
if(!function_exists('vp3_profile_speech_config_pa4a'))require_once dirname(__DIR__).'/includes/profile-speech-elevenlabs-pa4a.php';

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, private');
header('X-Content-Type-Options: nosniff');

function profile_speech_json_pa4a(bool $ok,array $payload=[],int $status=200): never
{http_response_code($status);echo json_encode(['ok'=>$ok,'contract'=>'vp3.profile-speech.v1']+$payload,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);exit;}
function profile_speech_method_pa4a(string $allowed): never
{header('Allow: '.$allowed);profile_speech_json_pa4a(false,['code'=>'METHOD_NOT_ALLOWED','error'=>'This action requires '.$allowed.'.'],405);}
function profile_speech_owner_pa4a(?int $expected=null): array
{
    $user=current_user();if(!$user||($expected!==null&&(int)$user['id']!==$expected)||!has_permission('account.access',$user))profile_speech_json_pa4a(false,['code'=>'SIGN_IN_REQUIRED','error'=>'Sign in to manage your Profile speech.'],401);return $user;
}
function profile_speech_csrf_pa4a(array $input): void
{if(!is_string($input['csrf_token']??null)||$input['csrf_token']===''||!hash_equals(csrf_token(),$input['csrf_token']))profile_speech_json_pa4a(false,['code'=>'SESSION_EXPIRED','error'=>'Session expired. Refresh and try again.'],419);}
function profile_speech_namespace_pa4a(array $input,array $context): void
{
    $given=$input['conversation_namespace']??null;$expected=hash('sha256','profile-widget-v1|'.(string)$context['conversation_authority']['credential_hash']);
    if(!is_string($given)||!preg_match('/^[a-f0-9]{64}$/D',$given)||!hash_equals($expected,$given))throw new VP3ProfileAgentPublicException('PROFILE_SESSION_CHANGED','Your visitor session changed. Refresh this profile before continuing.',409);
}
function profile_speech_unlock_session_pa4a(): void
{if(session_status()===PHP_SESSION_ACTIVE)session_write_close();}
/** A delayed old request must never overwrite a newer browser login cookie. */
function profile_speech_refresh_session_pa4a(): void
{
    if(session_status()!==PHP_SESSION_ACTIVE&&session_id()!==''){
        $expected=session_id();$_SESSION=[];$opened=session_start(['read_and_close'=>true,'use_cookies'=>false,'use_trans_sid'=>false,'use_strict_mode'=>true,'cache_limiter'=>'']);
        if(!$opened||!hash_equals($expected,session_id())){$_SESSION=[];if(function_exists('reset_current_user_cache'))reset_current_user_cache();vp3_profile_speech_error_pa4a('PROFILE_SPEECH_UNAVAILABLE',403);}
    }
    if(function_exists('reset_current_user_cache'))reset_current_user_cache();
}
function profile_speech_unknown_result_pa4a(array $reserved): array
{
    return ['status'=>'outcome_unverified','reason'=>'provider_outcome_unverified','provider_attempted'=>true,'attempt_id'=>$reserved['attempt_id'],'config_fingerprint'=>$reserved['config_fingerprint'],'input_characters'=>$reserved['input_characters'],'provider_character_cost'=>null,'provider_request_id'=>null,'provider_trace_id'=>null,'audio_key'=>null,'audio_bytes'=>0,'audio_sha256'=>null,'audio_expires_at_ms'=>null,'content_type'=>'audio/mpeg'];
}

$method=strtoupper((string)($_SERVER['REQUEST_METHOD']??'GET'));
if(!in_array($method,['GET','POST'],true))profile_speech_method_pa4a('GET, POST');
try{
    if($method==='POST'){
        if((int)($_SERVER['CONTENT_LENGTH']??0)>8192)profile_speech_json_pa4a(false,['code'=>'REQUEST_TOO_LARGE','error'=>'The speech request is too large.'],413);
        $raw=(string)file_get_contents('php://input',false,null,0,8193);if(strlen($raw)>8192)profile_speech_json_pa4a(false,['code'=>'REQUEST_TOO_LARGE','error'=>'The speech request is too large.'],413);
        try{$object=json_decode($raw,false,12,JSON_THROW_ON_ERROR);}catch(Throwable){vp3_profile_speech_error_pa4a('PROFILE_SPEECH_INVALID',422);}
        if(!$object instanceof stdClass)vp3_profile_speech_error_pa4a('PROFILE_SPEECH_INVALID',422);$input=get_object_vars($object);
    }else $input=$_GET;
    $action=$input['action']??null;$reads=['owner_state','visitor_state'];$writes=['owner_delivery','owner_claim','owner_audio','owner_play','owner_report','owner_retry','visitor_preference'];
    if(!is_string($action)||(!in_array($action,$reads,true)&&!in_array($action,$writes,true)))profile_speech_json_pa4a(false,['code'=>'PROFILE_SPEECH_INVALID','error'=>'Choose a valid visitor speech action.'],422);
    if(in_array($action,$reads,true)){if($method!=='GET')profile_speech_method_pa4a('GET');}elseif($method!=='POST')profile_speech_method_pa4a('POST');
    $visitor=str_starts_with($action,'visitor_');
    $allowed=$visitor?['action','username','conversation_namespace','conversation_id','profile_token','style','preset_key','revision','request_id']
        :['action','csrf_token','item_id','revision','attempt_id','chat_conversation_id','chat_agent_id','chat_message_id','delivery_id','tab_id','client_instance_id','request_id','mode','ticket','retry_of','retry_confirmed','play_token','play_request_id','outcome','status'];
    if($action==='owner_state')$allowed[]='public_conversation_id';
    if($action==='owner_report')$allowed[]='report_sequence';
    if(array_diff(array_keys($input),$allowed))vp3_profile_speech_error_pa4a('PROFILE_SPEECH_INVALID',422);
    $pdo=db();if(!$pdo)vp3_profile_speech_error_pa4a('PROFILE_SPEECH_NOT_READY',503);
    if($visitor){
        if(!is_string($input['username']??null)||strlen($input['username'])>60)vp3_profile_speech_error_pa4a('PROFILE_SPEECH_INVALID',422);
        $profile=profile_by_username($pdo,profile_username_normalize($input['username']));if(!$profile||empty($profile['is_active'])||empty($profile['is_public']))vp3_profile_agent_public_unavailable_v1();
        $context=vp3_profile_agent_public_context_v110($pdo,$profile,current_user(),true);profile_speech_namespace_pa4a($input,$context);
        $cid=vp3_profile_speech_id_pa4a($input['conversation_id']??0,true);if($cid>0)vp3_profile_agent_public_bound_conversation_v1($pdo,$context,$cid);
        if($method==='POST'&&(!is_string($input['profile_token']??null)||!profile_chat_token_valid((int)$context['owner_user_id'],$input['profile_token'])))profile_speech_json_pa4a(false,['code'=>'PROFILE_SESSION_EXPIRED','error'=>'Profile session expired. Refresh and try again.'],419);
        $recheckVisitor=static function()use($pdo,$context,$input):void{profile_speech_refresh_session_pa4a();vp3_profile_agent_public_live_v1($pdo,$context);profile_speech_namespace_pa4a($input,$context);profile_speech_unlock_session_pa4a();};
        profile_speech_unlock_session_pa4a();
        $result=$action==='visitor_state'?vp3_profile_speech_visitor_state_pa4a($pdo,$context,$recheckVisitor):vp3_profile_speech_preference_pa4a($pdo,$context,$input,$recheckVisitor);
        $recheckVisitor();profile_speech_json_pa4a(true,$result);
    }
    $user=profile_speech_owner_pa4a();if($method==='POST')profile_speech_csrf_pa4a($input);
    $recheckOwner=static function()use($user):void{profile_speech_refresh_session_pa4a();$live=current_user();if(!$live||(int)$live['id']!==(int)$user['id']||!has_permission('account.access',$live))vp3_profile_speech_error_pa4a('PROFILE_SPEECH_UNAVAILABLE',403);profile_speech_unlock_session_pa4a();};
    if($action==='owner_state')profile_speech_json_pa4a(true,vp3_profile_speech_state_pa4a($pdo,$user,$input));
    if($action==='owner_delivery')profile_speech_json_pa4a(true,vp3_profile_speech_delivery_pa4a($pdo,$user,$input));
    if(in_array($action,['owner_claim','owner_retry','owner_play','owner_report'],true)){
        profile_speech_unlock_session_pa4a();
        $result=match($action){'owner_claim','owner_retry'=>vp3_profile_speech_claim_pa4a($pdo,$user,$input,$action==='owner_retry',$recheckOwner),'owner_play'=>vp3_profile_speech_play_pa4a($pdo,$user,$input,$recheckOwner),default=>vp3_profile_speech_report_pa4a($pdo,$user,$input)};
        $recheckOwner();profile_speech_json_pa4a(true,$result);
    }
    if($action==='owner_audio'){
        profile_speech_unlock_session_pa4a();
        $reserved=vp3_profile_speech_attempt_begin_pa4a($pdo,$user,$input,$recheckOwner);
        if(($reserved['provider_required']??false)===true){
            profile_speech_unlock_session_pa4a();ignore_user_abort(true);@set_time_limit(90);
            try{$proof=vp3_profile_speech_synthesize_pa4a($reserved,null,static function()use($pdo,$input,$reserved,$recheckOwner):void{
                $recheckOwner();$live=current_user();
                vp3_profile_speech_attempt_recheck_pa4a($pdo,$live,$input,$reserved['attempt']);profile_speech_unlock_session_pa4a();
            });}catch(Throwable){$proof=profile_speech_unknown_result_pa4a($reserved);}
            // Paid-I/O evidence is retained even if the browser or source changed.
            vp3_profile_speech_attempt_finish_pa4a($pdo,$reserved,$proof);$recheckOwner();$user=current_user();
        }elseif(($reserved['attempt']['provider_state']??null)==='sending'){
            header('Retry-After: 2');profile_speech_json_pa4a(false,['code'=>'PROFILE_SPEECH_PENDING','error'=>'Speech generation is still being checked. No additional request was sent.','attempt_id'=>(int)$reserved['attempt_id']],202);
        }
        $attempt=vp3_profile_speech_audio_authorize_pa4a($pdo,$user,$input);$audio=vp3_profile_speech_audio_read_pa4a($attempt);
        $fresh=vp3_profile_speech_audio_authorize_pa4a($pdo,$user,$input);
        if((int)$fresh['id']!==(int)$attempt['id']||($fresh['audio_key']??null)!==($attempt['audio_key']??null)||($fresh['audio_sha256']??null)!==($attempt['audio_sha256']??null))vp3_profile_speech_error_pa4a('PROFILE_SPEECH_CHANGED',409);
        $recheckOwner();
        header('Content-Type: audio/mpeg');header('Content-Length: '.strlen($audio));header('Content-Disposition: inline; filename="profile-speech.mp3"');header('Accept-Ranges: none');
        header('X-Profile-Speech-Attempt: '.(int)$attempt['id']);header('X-Profile-Speech-Item: '.(int)$attempt['item_id']);header('X-Profile-Speech-Revision: '.(int)$attempt['item_revision']);
        echo $audio;exit;
    }
    vp3_profile_speech_error_pa4a('PROFILE_SPEECH_INVALID',422);
}catch(VP3ProfileSpeechException $error){
    if($error->retryAfterSeconds>0)header('Retry-After: '.$error->retryAfterSeconds);profile_speech_json_pa4a(false,['code'=>$error->publicCode,'error'=>$error->getMessage()],$error->httpStatus);
}catch(VP3ProfileSpeechProviderException|VP3ProfileAgentPublicException|VP3ProfileHandoffException|VP3ProfileCallException $error){
    profile_speech_json_pa4a(false,['code'=>$error->publicCode,'error'=>$error->getMessage()],$error->httpStatus);
}catch(Throwable $error){
    error_log('Profile speech API request failed ('.get_class($error).').');profile_speech_json_pa4a(false,['code'=>'PROFILE_SPEECH_REQUEST_FAILED','error'=>'The speech request could not be verified. Refresh its current state before retrying.'],500);
}
