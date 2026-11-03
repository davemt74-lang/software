<?php
declare(strict_types=1);
require dirname(__DIR__) . '/includes/bootstrap.php';
require_once dirname(__DIR__) . '/includes/profile-agent-transcription-context.php';
require_once dirname(__DIR__) . '/includes/profile-agent-public-service-v110.php';
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');

function profile_agent_json(bool $ok,array $payload=[],int $status=200): never{http_response_code($status);echo json_encode(array_merge(['ok'=>$ok],$payload),JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);exit;}
function profile_agent_require_csrf(array $input): void{$token=$input['csrf_token']??null;if(!is_string($token)||$token===''||!hash_equals(csrf_token(),$token))profile_agent_json(false,['error'=>'Session expired. Refresh and try again.'],419);}
function profile_agent_method_required(string $allow): never{header('Allow: '.$allow);profile_agent_json(false,['error'=>'This action requires '.$allow.'.','code'=>'METHOD_NOT_ALLOWED'],405);}
function profile_agent_unexpected_error(Throwable $error): never{
    // Older profile/upload helpers use RuntimeException for validation. Only
    // these exact, reviewed messages may cross the public API boundary. SQL,
    // provider and unexpected runtime diagnostics remain on the server.
    $validation=[
        'Choose a username using 3–60 letters, numbers, dots, dashes or underscores.',
        'That username is already in use.','Profile links must be valid URLs.',
        'Profile links must use http or https.','Enter a valid profile contact email.',
        'Choose one of your agents before enabling the Profile Agent.',
        'Unknown data type.','Unknown audience.','Unknown attention action.',
        'Upload failed.','Uploaded file is too large.','Unsupported file extension.',
        'Unsupported file type.',
    ];
    if(get_class($error)===RuntimeException::class&&in_array($error->getMessage(),$validation,true)){
        profile_agent_json(false,['error'=>$error->getMessage(),'code'=>'VALIDATION_FAILED'],422);
    }
    if(get_class($error)===RuntimeException::class&&$error->getMessage()==='That agent is not available.'){
        profile_agent_json(false,['error'=>'That agent is not available.','code'=>'PROFILE_AGENT_UNAVAILABLE'],404);
    }
    error_log('Profile Agent API request failed ('.get_class($error).').');
    profile_agent_json(false,['error'=>'Profile Agent could not complete this request. Please try again.','code'=>'PROFILE_AGENT_REQUEST_FAILED'],500);
}
function profile_agent_owner_state_v242(PDO $pdo,array $user): array{
    personal_profile_migrate_legacy_artist_v242($pdo,$user);
    $state=profile_runtime_owner_state($pdo,$user);
    $state['capabilities']=[
      'profile_agent'=>personal_capability_has_v242('profile_agent.access',$user),
      'profile_chat'=>personal_capability_has_v242('profile_chat.access',$user),
      'personal_knowledge'=>personal_capability_has_v242('personal_knowledge.access',$user),
      'personal_knowledge_manage'=>personal_capability_has_v242('personal_knowledge.manage',$user),
      'voice_profile'=>personal_capability_has_v242('voice_profile.access',$user),
      'agent_brain'=>personal_capability_has_v242('agent_brain.access',$user),
    ];
    return $state;
}

$method=strtoupper((string)($_SERVER['REQUEST_METHOD']??'GET'));
if(!in_array($method,['GET','POST'],true))profile_agent_method_required('GET, POST');
$input=$method==='POST'?json_decode((string)file_get_contents('php://input'),true):$_GET;if(!is_array($input))$input=$_POST;
$action=$input['action']??'state';if(!is_string($action))profile_agent_json(false,['error'=>'Choose a Profile Agent action.','code'=>'VALIDATION_FAILED'],422);$action=trim($action);
$ownerActions=['owner_state','save_profile','save_profile_media','save_profile_agent','save_profile_access','attention_action','conversation_messages','conversation_status','owner_reply'];
$ownerReads=['owner_state','conversation_messages'];
// A URL, preload, HEAD request or cross-site navigation must never send an
// owner reply or change Profile settings, attention or conversation status.
if($method!=='POST'&&in_array($action,$ownerActions,true)&&!in_array($action,$ownerReads,true))profile_agent_method_required('POST');

try{
$pdo=db();if(!$pdo||!profile_agent_schema_ready($pdo)||!personal_capability_schema_ready_v242($pdo))profile_agent_json(false,['error'=>'Profile Agent is not ready. Run /upgrade.php.'],503);
$user=current_user();
if(in_array($action,$ownerActions,true)){
    if(!$user||!has_permission('account.access',$user))profile_agent_json(false,['error'=>'Sign in to manage your Profile Agent.'],401);
    if(!personal_capability_has_v242('profile_agent.access',$user))profile_agent_json(false,['error'=>'Profile Agent access is unavailable for this account.'],403);
    $uid=(int)$user['id'];if($method==='POST')profile_agent_require_csrf($input);
    if($action==='owner_state')profile_agent_json(true,['state'=>profile_agent_owner_state_v242($pdo,$user)]);
    if($action==='save_profile'){personal_profile_save_v242($pdo,$user,$input);profile_agent_json(true,['state'=>profile_agent_owner_state_v242($pdo,$user)]);}
    if($action==='save_profile_media'){
        $mediaType=(string)($input['media_type']??'');if(!in_array($mediaType,['avatar','cover'],true))throw new VP3ProfileAgentPublicException('VALIDATION_FAILED','Choose a profile image or cover image.',422);
        global $config;$maxBytes=(int)($config['uploads']['max_image_bytes']??5242880);$subdir=$mediaType==='avatar'?'avatars':'profile-covers';
        $newPath=upload_file($_FILES['media_file']??[],['jpg','jpeg','png','webp'],['image/jpeg','image/png','image/webp'],$maxBytes,$subdir);if(!$newPath)throw new VP3ProfileAgentPublicException('VALIDATION_FAILED','Choose a JPG, PNG or WEBP image to upload.',422);
        $profileRow=profile_for_user($pdo,$uid,true)?:throw new RuntimeException('Profile could not be loaded.');$oldPath=$mediaType==='avatar'?(string)($user['avatar_path']??''):(string)($profileRow['cover_path']??'');
        try{if($mediaType==='avatar'){$pdo->prepare('UPDATE users SET avatar_path=? WHERE id=?')->execute([$newPath,$uid]);reset_current_user_cache();$user=current_user()?:$user;}else{$pdo->prepare('UPDATE user_profiles SET cover_path=? WHERE user_id=?')->execute([$newPath,$uid]);}}catch(Throwable $e){delete_local_upload($newPath);throw $e;}
        if($oldPath!==''&&$oldPath!==$newPath)delete_local_upload($oldPath);profile_agent_json(true,['state'=>profile_agent_owner_state_v242($pdo,$user)]);
    }
    if($action==='save_profile_agent'){
        if(!personal_capability_has_v242('profile_chat.access',$user)&&!empty($input['profile_agent_enabled']))$input['profile_agent_enabled']=0;
        profile_configure_agent($pdo,$user,$input);profile_agent_json(true,['state'=>profile_agent_owner_state_v242($pdo,$user)]);
    }
    if($action==='save_profile_access'){
        $type=(string)($input['resource_type']??'');if(!isset(user_agent_resource_catalog_v236()[$type]))throw new VP3ProfileAgentPublicException('VALIDATION_FAILED','Unknown profile data type.',422);
        if($type==='knowledge'&&!personal_capability_has_v242('personal_knowledge.access',$user))throw new VP3ProfileAgentPublicException('KNOWLEDGE_UNAVAILABLE','Personal Knowledge access is unavailable for this account.',403);
        $current=user_data_policy_get_v236($pdo,$uid,$type);
        user_data_policy_save_v236($pdo,$user,['resource_type'=>$type,'resource_id'=>'*','audience_scope'=>(string)($input['audience_scope']??$current['audience_scope']),'owner_agents_allowed'=>!empty($current['owner_agents_allowed']),'profile_agent_allowed'=>!empty($input['profile_agent_allowed']),'stonefellow_shared'=>!empty($current['stonefellow_shared'])]);
        profile_agent_json(true,['state'=>profile_agent_owner_state_v242($pdo,$user)]);
    }
    if($action==='attention_action'){profile_attention_update($pdo,$uid,(int)($input['attention_id']??0),(string)($input['attention_action']??''));profile_agent_json(true,['state'=>profile_agent_owner_state_v242($pdo,$user)]);}
    if($action==='conversation_messages'){
        $cid=max(0,(int)($input['conversation_id']??0));
        if(!vp3_profile_agent_owner_conversation_v390($pdo,$cid,$uid))profile_agent_json(false,['error'=>'Conversation not found.'],404);
        $conversation=profile_runtime_conversation_owner($pdo,$cid,$uid)?:throw new RuntimeException('Conversation not found.');
        profile_agent_json(true,['conversation'=>$conversation,'messages'=>profile_agent_messages($pdo,$cid)]);
    }
    if($action==='conversation_status'){
        $cid=max(0,(int)($input['conversation_id']??0));$status=(string)($input['status']??'');if(!in_array($status,['open','owner_joined','resolved'],true))throw new VP3ProfileAgentPublicException('VALIDATION_FAILED','Unknown conversation status.',422);
        $pdo->beginTransaction();
        try{
            $conversation=vp3_profile_agent_owner_conversation_v390($pdo,$cid,$uid,true);if(!$conversation)throw new VP3ProfileAgentPublicException('CONVERSATION_NOT_FOUND','Conversation not found.',404);
            $pdo->prepare('UPDATE profile_agent_conversations SET status=?,updated_at=NOW() WHERE id=? AND owner_user_id=?')->execute([$status,$cid,$uid]);
            if($status==='resolved')$pdo->prepare("UPDATE agent_attention_items SET status='handled',snoozed_until=NULL WHERE owner_user_id=? AND source_conversation_id=? AND status IN ('pending','seen','snoozed')")->execute([$uid,$cid]);
            $pdo->commit();
        }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
        profile_agent_json(true,['conversation_id'=>$cid,'state'=>profile_agent_owner_state_v242($pdo,$user)]);
    }
    if($action==='owner_reply'){
        $cid=max(0,(int)($input['conversation_id']??0));$reply=trim((string)($input['message']??''));if($reply===''||mb_strlen($reply)>4000)throw new VP3ProfileAgentPublicException('VALIDATION_FAILED','Enter a reply up to 4,000 characters.',422);
        $pdo->beginTransaction();
        try{
            $conversation=vp3_profile_agent_owner_conversation_v390($pdo,$cid,$uid,true);if(!$conversation)throw new VP3ProfileAgentPublicException('CONVERSATION_NOT_FOUND','Conversation not found.',404);
            $pdo->prepare("INSERT INTO profile_agent_messages (conversation_id,sender_type,sender_user_id,message) VALUES (?,'owner',?,?)")->execute([$cid,$uid,$reply]);
            $pdo->prepare("UPDATE profile_agent_conversations SET status='owner_joined',last_summary=?,last_message_at=NOW(),updated_at=NOW() WHERE id=? AND owner_user_id=?")->execute([mb_strimwidth($reply,0,900,'…'),$cid,$uid]);
            $pdo->prepare("UPDATE agent_attention_items SET status='handled',snoozed_until=NULL WHERE owner_user_id=? AND source_conversation_id=? AND status IN ('pending','seen','snoozed')")->execute([$uid,$cid]);
            $pdo->commit();
        }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
        profile_agent_json(true,['conversation_id'=>$cid,'state'=>profile_agent_owner_state_v242($pdo,$user),'messages'=>profile_agent_messages($pdo,$cid)]);
    }
}

$username=profile_username_normalize((string)($input['username']??''));$profile=profile_by_username($pdo,$username);
if(!$profile||empty($profile['is_active'])||empty($profile['is_public']))profile_agent_json(false,['error'=>'That profile is not available.'],404);
$publicCtx=vp3_profile_agent_public_context_v110($pdo,$profile,$user);$owner=(int)$publicCtx['owner_user_id'];
if($method==='POST'&&!profile_chat_token_valid($owner,(string)($input['profile_token']??'')))profile_agent_json(false,['error'=>'Profile session expired. Refresh and try again.'],419);

if($action==='state'){
    $cid=max(0,(int)($input['conversation_id']??0));
    profile_agent_json(true,vp3_profile_agent_public_state_service_v110($pdo,$publicCtx,$cid));
}
if($method!=='POST')profile_agent_method_required('POST');
if($action==='message'){
    $cid=max(0,(int)($input['conversation_id']??0));
    profile_agent_json(true,vp3_profile_agent_public_message_service_v110($pdo,$publicCtx,(string)($input['message']??''),$cid));
}
if($action==='poll'){
    $cid=max(0,(int)($input['conversation_id']??0));$after=max(0,(int)($input['after_id']??0));
    profile_agent_json(true,vp3_profile_agent_public_poll_service_v110($pdo,$publicCtx,$cid,$after));
}
profile_agent_json(false,['error'=>'Unknown Profile Agent action.'],404);
}catch(VP3ProfileAgentPublicException $e){profile_agent_json(false,['error'=>$e->getMessage(),'code'=>$e->publicCode],$e->httpStatus);}catch(Throwable $e){profile_agent_unexpected_error($e);}
