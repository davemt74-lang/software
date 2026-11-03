<?php
declare(strict_types=1);
require dirname(__DIR__).'/includes/bootstrap.php';
require_once dirname(__DIR__).'/includes/profile-calls-pa3c.php';

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, private');
header('X-Content-Type-Options: nosniff');

function profile_calls_json_pa3c(bool $ok,array $value=[],int $status=200): never
{http_response_code($status);echo json_encode(['ok'=>$ok]+$value,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);exit;}
function profile_calls_method_pa3c(string $allowed): never
{header('Allow: '.$allowed);profile_calls_json_pa3c(false,['code'=>'METHOD_NOT_ALLOWED','error'=>'This action requires '.$allowed.'.'],405);}
function profile_calls_csrf_pa3c(array $input): void
{if(!is_string($input['csrf_token']??null)||$input['csrf_token']===''||!hash_equals(csrf_token(),$input['csrf_token']))profile_calls_json_pa3c(false,['code'=>'SESSION_EXPIRED','error'=>'Session expired. Refresh and try again.'],419);}
function profile_calls_namespace_pa3c(array $input,array $context): void
{
    $namespace=$input['conversation_namespace']??null;$expected=hash('sha256','profile-widget-v1|'.(string)$context['conversation_authority']['credential_hash']);
    if(!is_string($namespace)||!preg_match('/^[a-f0-9]{64}$/D',$namespace)||!hash_equals($expected,$namespace))throw new VP3ProfileAgentPublicException('PROFILE_SESSION_CHANGED','Your visitor session changed. Refresh this profile before continuing.',409);
}
/** Slow media operations do not hold the browser PHP session lock. */
function profile_calls_unlock_session_pa3c(): void
{if(session_status()===PHP_SESSION_ACTIVE)session_write_close();}
/** Re-open the real browser session after I/O; never restore a saved principal. */
function profile_calls_refresh_session_pa3c(): void
{
    if(session_status()!==PHP_SESSION_ACTIVE&&session_id()!==''){
        $expected=session_id();$_SESSION=[];
        // A login/logout in another request can delete this captured session.
        // Read it strictly without setting a cookie: a delayed response must
        // never replace the browser's newer login with a regenerated old one.
        $opened=session_start(['read_and_close'=>true,'use_cookies'=>false,'use_trans_sid'=>false,'use_strict_mode'=>true,'cache_limiter'=>'']);
        if(!$opened||!hash_equals($expected,session_id())){$_SESSION=[];if(function_exists('reset_current_user_cache'))reset_current_user_cache();vp3_profile_call_error_pa3c('PROFILE_CALL_UNAVAILABLE',403);}
    }
    if(function_exists('reset_current_user_cache'))reset_current_user_cache();
}
function profile_calls_recheck_pa3c(PDO $pdo,string $role,array $auth,array $input,array $row): void
{
    if($role==='owner'){$user=current_user();if(!$user||(int)$user['id']!==(int)$auth['id']||!has_permission('account.access',$user))vp3_profile_call_error_pa3c('PROFILE_CALL_UNAVAILABLE',403);$auth=$user;}
    vp3_profile_call_authorize_pa3c($pdo,$role,$auth,(int)$row['conversation_id'],$input,$row);
    if($role==='owner')vp3_profile_call_delivery_check_pa3c($pdo,$row,$input);
    if($role==='visitor')profile_calls_namespace_pa3c($input,$auth);
}

$method=strtoupper((string)($_SERVER['REQUEST_METHOD']??'GET'));
if(!in_array($method,['GET','POST'],true))profile_calls_method_pa3c('GET, POST');
try{
    if($method==='POST'){
        if((int)($_SERVER['CONTENT_LENGTH']??0)>16384)profile_calls_json_pa3c(false,['code'=>'REQUEST_TOO_LARGE','error'=>'The call request is too large.'],413);
        $raw=(string)file_get_contents('php://input',false,null,0,16385);
        if(strlen($raw)>16384)profile_calls_json_pa3c(false,['code'=>'REQUEST_TOO_LARGE','error'=>'The call request is too large.'],413);
        try{$object=json_decode($raw,false,12,JSON_THROW_ON_ERROR);}catch(Throwable){profile_calls_json_pa3c(false,['code'=>'PROFILE_CALL_INVALID','error'=>'Choose a valid call operation.'],422);}
        if(!$object instanceof stdClass)profile_calls_json_pa3c(false,['code'=>'PROFILE_CALL_INVALID','error'=>'Choose a valid call operation.'],422);
        $input=get_object_vars($object);
    }else $input=$_GET;
    $action=$input['action']??null;
    $reads=['visitor_state','owner_state'];$operations=['offer','accept','decline','cancel','end','join','dispatch','heartbeat','return_to_agent'];
    if(!is_string($action)||!preg_match('/^(visitor|owner)_([a-z_]+)$/D',$action,$match))profile_calls_json_pa3c(false,['code'=>'PROFILE_CALL_INVALID','error'=>'Choose a valid call operation.'],422);
    $role=$match[1];$operation=$match[2];
    if(!in_array($action,$reads,true)&&!in_array($operation,$operations,true)&&$action!=='owner_delivery')profile_calls_json_pa3c(false,['code'=>'PROFILE_CALL_INVALID','error'=>'Unknown call operation.'],404);
    if(in_array($action,$reads,true)){if($method!=='GET')profile_calls_method_pa3c('GET');}elseif($method!=='POST')profile_calls_method_pa3c('POST');
    $pdo=db();if(!$pdo)vp3_profile_call_error_pa3c('PROFILE_CALL_NOT_READY',503);
    $user=current_user();$input['operation']=$operation; // The server chooses the role and action.
    unset($input['legacy'],$input['provider'],$input['provider_snapshot'],$input['room_name'],$input['participant_identity'],$input['server_url']);
    if($role==='owner'){
        if(!$user||!has_permission('account.access',$user))profile_calls_json_pa3c(false,['code'=>'SIGN_IN_REQUIRED','error'=>'Sign in to manage your Profile calls.'],401);
        if($method==='POST')profile_calls_csrf_pa3c($input);
        $auth=$user;
        if($operation==='state')profile_calls_json_pa3c(true,vp3_profile_call_owner_list_pa3c($pdo,$user,$input));
        if($operation==='delivery')profile_calls_json_pa3c(true,vp3_profile_call_owner_delivery_pa3c($pdo,$user,$input));
    }else{
        if(!is_string($input['username']??null)||strlen($input['username'])>60)vp3_profile_call_error_pa3c('PROFILE_CALL_INVALID',422);
        $profile=profile_by_username($pdo,profile_username_normalize($input['username']));
        if(!$profile||empty($profile['is_active'])||empty($profile['is_public']))vp3_profile_agent_public_unavailable_v1();
        // The Profile page establishes the native session. Call requests only
        // read it, so denied/replayed requests cannot change the CRM identity.
        $auth=vp3_profile_agent_public_context_v110($pdo,$profile,$user,true);profile_calls_namespace_pa3c($input,$auth);
        if($method==='POST'&&(!is_string($input['profile_token']??null)||!profile_chat_token_valid((int)$auth['owner_user_id'],$input['profile_token'])))profile_calls_json_pa3c(false,['code'=>'PROFILE_SESSION_EXPIRED','error'=>'Profile session expired. Refresh and try again.'],419);
        $cid=vp3_profile_call_id_pa3c($input['conversation_id']??0,true);
        if($operation==='state'){
            if($cid>0)profile_calls_json_pa3c(true,vp3_profile_call_visitor_state_pa3c($pdo,$auth,$cid));
            vp3_profile_call_require_pa3c($pdo);$ready=vp3_profile_call_provider_ready_pa3c();$owner=(int)$auth['owner_user_id'];$busy=vp3_profile_call_owner_busy_pa3c($pdo,$owner);$reachable=vp3_profile_handoff_reachable_pa3($pdo,$owner);
            $result=['contract'=>VP3_PROFILE_CALL_PA3C,'calls'=>[],'conversation_id'=>0,'server_now_ms'=>vp3_profile_call_now_pa3c(),'can_offer'=>($ready['ready']??false)===true&&!$busy&&$reachable,'reason'=>$busy?'owner_busy':(!$reachable?'owner_unavailable':(($ready['ready']??false)?'':(string)($ready['reason']??'not_configured')))];
            vp3_profile_agent_public_live_v1($pdo,$auth);profile_calls_json_pa3c(true,$result);
        }
        if($operation==='offer'&&$cid===0){
            // Canonical conversation binding and the offer commit together; no
            // fake visitor text or Agent turn is created for a call-only visit.
            $result=vp3_profile_handoff_atomic_pa3($pdo,function()use($pdo,$auth,$input){
                vp3_profile_call_require_pa3c($pdo);$request=vp3_profile_call_token_pa3c($input['request_id']??null);$owner=(int)$auth['owner_user_id'];
                if(!in_array($input['mode']??null,['audio','video'],true))vp3_profile_call_error_pa3c('PROFILE_CALL_INVALID',422);
                // Preserve the original cid even if another tab opened a newer
                // native thread after this offer's HTTP response was lost.
                vp3_profile_call_slot_pa3c($pdo,$owner,true);
                $actor=hash('sha256','visitor|'.$owner.'|'.$auth['conversation_authority']['credential_hash']);
                $q=$pdo->prepare('SELECT call_id,operation FROM profile_call_operations_pa3c WHERE owner_user_id=? AND actor_key=? AND request_id=?');$q->execute([$owner,$actor,$request]);$prior=$q->fetch(PDO::FETCH_ASSOC);
                if($prior){
                    if($prior['operation']!=='offer')vp3_profile_call_error_pa3c();
                    $row=vp3_profile_call_row_pa3c($pdo,(int)$prior['call_id']);if(!$row||(int)$row['owner_user_id']!==$owner)vp3_profile_call_error_pa3c();
                    vp3_profile_call_authorize_pa3c($pdo,'visitor',$auth,(int)$row['conversation_id'],$input,$row,true);$input['conversation_id']=(int)$row['conversation_id'];
                }else{
                    vp3_profile_call_ready_pa3c();$started=vp3_profile_agent_public_start_v1($pdo,$auth);$input['conversation_id']=(int)$started['conversation']['conversation_id'];
                }
                return vp3_profile_call_visitor_action_pa3c($pdo,$auth,$input);
            });profile_calls_json_pa3c(true,$result);
        }
    }
    if($operation==='join'){
        profile_calls_unlock_session_pa3c();$result=vp3_profile_call_join_pa3c($pdo,$role,$auth,$input);profile_calls_refresh_session_pa3c();
        $row=vp3_profile_call_row_pa3c($pdo,vp3_profile_call_id_pa3c($input['call_id']??null));if(!$row)vp3_profile_call_error_pa3c('PROFILE_CALL_NOT_FOUND',404);
        profile_calls_recheck_pa3c($pdo,$role,$auth,$input,$row);vp3_profile_call_media_current_pa3c($pdo,$row);
        if(!in_array($row['state'],['ready','active'],true)||(int)$row['revision']!==(int)($result['revision']??0)
            ||!is_string($input['client_instance_id']??null)||!hash_equals((string)$row[$role.'_client_hash'],hash('sha256',$input['client_instance_id'])))vp3_profile_call_error_pa3c();
        profile_calls_json_pa3c(true,$result);
    }
    if($operation==='dispatch'){
        vp3_profile_call_require_pa3c($pdo);$cid=vp3_profile_call_id_pa3c($input['conversation_id']??null);$id=vp3_profile_call_id_pa3c($input['call_id']??null);$revision=vp3_profile_call_id_pa3c($input['revision']??null);$request=vp3_profile_call_token_pa3c($input['request_id']??null);
        $row=vp3_profile_call_row_pa3c($pdo,$id);if(!$row||(int)$row['conversation_id']!==$cid)vp3_profile_call_error_pa3c('PROFILE_CALL_NOT_FOUND',404);
        profile_calls_recheck_pa3c($pdo,$role,$auth,$input,$row);if((int)$row['revision']!==$revision)vp3_profile_call_error_pa3c();
        profile_calls_unlock_session_pa3c();vp3_profile_call_work_pa3c($pdo,$id);profile_calls_refresh_session_pa3c();
        $row=vp3_profile_call_row_pa3c($pdo,$id);if(!$row)vp3_profile_call_error_pa3c('PROFILE_CALL_NOT_FOUND',404);profile_calls_recheck_pa3c($pdo,$role,$auth,$input,$row);
        profile_calls_json_pa3c(true,vp3_profile_call_reply_pa3c($pdo,$row,$role,$input,$request));
    }
    $result=$role==='owner'?vp3_profile_call_owner_action_pa3c($pdo,$auth,$input):vp3_profile_call_visitor_action_pa3c($pdo,$auth,$input);
    profile_calls_json_pa3c(true,$result);
}catch(VP3ProfileCallException|VP3ProfileCallProviderException|VP3ProfileAgentPublicException|VP3ProfileHandoffException $e){
    if($e->httpStatus===429)header('Retry-After: 300');
    profile_calls_json_pa3c(false,['code'=>$e->publicCode,'error'=>$e->getMessage()],$e->httpStatus);
}catch(Throwable $e){
    error_log('Profile calls API request failed ('.get_class($e).').');
    profile_calls_json_pa3c(false,['code'=>'PROFILE_CALL_REQUEST_FAILED','error'=>'The call request could not be completed. Refresh its current state and try again.'],500);
}
