<?php
declare(strict_types=1);

/**
 * Phase 18.1 hardening: connect Video Meetings to the existing HomeServer
 * compute/privacy policy without inventing a second pairing or capability
 * system. LiveKit remains the cloud media transport. This policy only governs
 * meeting AI/transcription processing and reports capabilities already exposed
 * by the paired HomeServer.
 */
const VP3_VIDEO_MEETINGS_HOMESERVER_V1801='video-meetings-homeserver-v1801-20260915';

/** Explicit server processing context is reconstructed, never a browser owner flag. */
function video_meeting_processing_scope_readonly_v1801(PDO $pdo,array $user,array $scope): array
{
    $owner=(int)($user['id']??0);$aid=$scope['agent_id']??null;$cid=$scope['conversation_id']??null;
    if($owner<1||!is_array($scope['user']??null)||(int)($scope['user']['id']??0)!==$owner
        ||!is_int($aid)||$aid<0||!is_int($cid)||$cid<1||!is_array($scope['principal']??null)
        ||(function_exists('vp3_participant_scope_v1')&&vp3_participant_scope_v1()!==null)){
        throw new RuntimeException('Meeting processing context is not authorized.',403);
    }
    $viewer=function_exists('current_user')?current_user():null;
    if((int)($viewer['id']??0)>0&&(int)$viewer['id']!==$owner)throw new RuntimeException('Meeting processing identity changed.',403);
    if(!function_exists('vp3_agent_chat_resolve_agent_v380'))require_once __DIR__.'/agent-chat-boundary-v380.php';
    if(!function_exists('user_agent_principal_v236'))require_once __DIR__.'/user-agent-system-v236.php';
    try{
        $q=$pdo->prepare('SELECT * FROM users WHERE id=?');$q->execute([$owner]);$live=$q->fetch(PDO::FETCH_ASSOC);
        if(!$live||empty($live['is_active']))throw new RuntimeException('Meeting processing account is unavailable.',403);
        if(function_exists('user_account_types_for_user_id'))$live['roles']=user_account_types_for_user_id($owner,(string)($live['role']??''));
        if(!has_permission('account.access',$live)||!has_permission('chat.access',$live))throw new RuntimeException('Meeting processing access changed.',403);
        $agent=vp3_agent_chat_resolve_agent_v380($pdo,$live,$aid);
        if(!vp3_agent_chat_conversation_v380($pdo,$cid,$owner,$agent))throw new RuntimeException('Meeting processing Chat changed.',403);
        $principal=vp3_agent_chat_principal_v380($live,$agent);
        foreach(['kind','agent_id','owner_user_id','viewer_user_id'] as $key){
            if(($scope['principal'][$key]??null)!==($principal[$key]??null))throw new RuntimeException('Meeting processing principal changed.',403);
        }
        return ['user'=>$live,'principal'=>$principal,'agent_id'=>$aid,'conversation_id'=>$cid];
    }catch(PDOException $error){throw new RuntimeException('Meeting processing context is unavailable.',503,$error);}
    catch(RuntimeException $error){
        if(in_array($error->getCode(),[403,503],true))throw $error;
        throw new RuntimeException('Meeting processing context is not authorized.',403,$error);
    }
}

/** Known legacy absence is read from storage, not inferred from swallowed errors. */
function video_meeting_homeserver_legacy_scope_v1801(PDO $pdo,int $owner): array
{
    if(!function_exists('homeserver_vp3_connection'))require_once __DIR__.'/homeserver-vp3.php';
    $row=homeserver_vp3_connection($owner,true,$pdo);
    if($row===null)return ['unpaired'=>true,'unsupported'=>false];
    if(($row['status']??null)==='unpaired'&&empty($row['homeserver_token_enc'])&&empty($row['relay_token_enc'])){
        return ['unpaired'=>true,'unsupported'=>false];
    }
    if(!function_exists('homeserver_capability_v024_raw'))require_once __DIR__.'/homeserver-capabilities-v024.php';
    $capabilities=homeserver_capability_v024_raw($owner,true,$pdo);
    if(!isset($capabilities['features'])||!is_array($capabilities['features']))throw new RuntimeException('HomeServer scope advertisement is unavailable.',503);
    $unsupported=!in_array('app.scopes.v1',$capabilities['features'],true);
    if($unsupported){
        if(!function_exists('homeserver_agent_v018_credentials'))require_once __DIR__.'/homeserver-agent-v018.php';
        if(homeserver_agent_v018_credentials($owner,true,$pdo)===null)throw new RuntimeException('HomeServer credentials are unavailable.',503);
    }
    return ['unpaired'=>false,'unsupported'=>$unsupported];
}

function video_meeting_homeserver_policy_v1801(PDO $pdo,array $meeting,bool $forceRefresh=false,bool $readonlyPolicy=false,?array $processingScope=null): array
{
    static $cache=[];
    $meetingId=(int)($meeting['id']??0);$ownerId=(int)($meeting['owner_user_id']??0);$agentId=(int)($meeting['organizer_agent_id']??0);
    $trustedScope=null;
    if($readonlyPolicy&&$processingScope!==null){
        $trustedScope=video_meeting_processing_scope_readonly_v1801($pdo,['id'=>$ownerId],$processingScope);
        $freshMeeting=video_meeting_row_v1800($pdo,$meetingId);
        if(!$freshMeeting||(int)$freshMeeting['owner_user_id']!==$ownerId)throw new RuntimeException('Meeting processing source changed.',403);
        $meeting=$freshMeeting;$agentId=(int)($meeting['organizer_agent_id']??0);
    }
    $viewer=function_exists('current_user')?current_user():null;
    $ownerContext=$trustedScope!==null||((int)($viewer['id']??0)>0&&(int)($viewer['id']??0)===$ownerId);
    $scopeKey=$trustedScope===null?'session':hash('sha256',json_encode([$ownerId,$trustedScope['agent_id'],$trustedScope['conversation_id'],$trustedScope['principal']],JSON_THROW_ON_ERROR));
    $cacheKey=$meetingId.'|'.($ownerContext?'owner':'nonowner').'|'.($forceRefresh?'1':'0').'|'.($readonlyPolicy?'readonly':'default').'|'.$scopeKey;
    if(!$forceRefresh&&isset($cache[$cacheKey]))return $cache[$cacheKey];

    $state=[
        'version'=>'v18.1',
        'paired'=>false,
        'available'=>false,
        'policy_resolved'=>false,
        'owner_context'=>$ownerContext,
        'requested_compute'=>'auto',
        // null means the caller is not authorized to probe the owner's live
        // HomeServer scope and therefore must not claim a processing route.
        'cloud_processing_allowed'=>null,
        'cloud_block_reason'=>'',
        'scope_checked'=>false,
        'knowledge_searchable'=>false,
        'local_compute_available'=>false,
        'local_transcription_advertised'=>false,
        'local_transcription_operation'=>'',
        'reason'=>'owner_policy_not_resolved',
    ];
    if($ownerId<1)return $cache[$cacheKey]=$state;

    try{
        if($readonlyPolicy||function_exists('vp3_agent_runtime_preference_v420')){
            if($readonlyPolicy&&!function_exists('vp3_agent_runtime_preference_readonly_v420'))require_once __DIR__.'/agent-runtime-routing-v420.php';
            $preference=$readonlyPolicy
                ?vp3_agent_runtime_preference_readonly_v420($pdo,$ownerId,max(0,$agentId))
                :vp3_agent_runtime_preference_v420($pdo,$ownerId,max(0,$agentId));
            $requested=(string)($preference['effective_preference']??'auto');
            if(in_array($requested,['auto','homeserver_only','vp3_cloud'],true))$state['requested_compute']=$requested;
        }
    }catch(Throwable $ignored){
        if($readonlyPolicy){
            $state['cloud_processing_allowed']=false;$state['cloud_block_reason']='compute_policy_unavailable';
            $state['policy_resolved']=true;$state['reason']='compute_policy_unavailable';
            return $cache[$cacheKey]=$state;
        }
    }

    // A stored explicit preference is enough to resolve these two cases without
    // contacting HomeServer. This is safe for guest rendering because no relay
    // request or capability discovery is triggered by an invitation bearer.
    if($state['requested_compute']==='vp3_cloud'){
        $state['cloud_processing_allowed']=true;
        $state['policy_resolved']=true;
        $state['reason']='explicit_vp3_cloud';
        return $cache[$cacheKey]=$state;
    }
    if($state['requested_compute']==='homeserver_only'){
        $state['cloud_processing_allowed']=false;
        $state['cloud_block_reason']='homeserver_only';
        $state['policy_resolved']=true;
        $state['reason']='homeserver_only';
        // Continue only in owner context so the organizer can see whether a
        // compatible local capability is actually available.
        if($readonlyPolicy||!$ownerContext)return $cache[$cacheKey]=$state;
    }

    // Never let an attendee/guest request use the owner's stored relay tokens or
    // cause VP3 to probe the owner's HomeServer. The organizer's authenticated
    // join request resolves the live scope immediately before Agent dispatch.
    if(!$ownerContext)return $cache[$cacheKey]=$state;

    $legacy=null;
    if($readonlyPolicy){
        try{
            $legacy=video_meeting_homeserver_legacy_scope_v1801($pdo,$ownerId);
            $state['paired']=!$legacy['unpaired'];
            if($legacy['unpaired']||$legacy['unsupported']){
                $state['cloud_processing_allowed']=$state['requested_compute']==='auto';
                $state['policy_resolved']=true;$state['scope_checked']=true;
                $state['reason']=$legacy['unpaired']?'homeserver_not_paired':'legacy_scope_unsupported';
                return $cache[$cacheKey]=$state;
            }
        }catch(Throwable $ignored){
            $state['cloud_processing_allowed']=false;$state['cloud_block_reason']='homeserver_scope_unavailable';
            $state['policy_resolved']=true;$state['reason']='homeserver_scope_unavailable';
            return $cache[$cacheKey]=$state;
        }
    }elseif(function_exists('homeserver_agent_v018_credentials')){
        try{$state['paired']=homeserver_agent_v018_credentials($ownerId)!==null;}catch(Throwable $ignored){}
    }

    if(function_exists('homeserver_scope_v026_fetch')&&function_exists('homeserver_scope_v026_blocks_cloud')){
        try{
            $scope=$readonlyPolicy?homeserver_scope_v026_fetch($ownerId,$forceRefresh,true,$pdo):homeserver_scope_v026_fetch($ownerId,$forceRefresh);
            $state['scope_checked']=true;
            $ready=($scope['supported']??null)===true&&($scope['available']??null)===true
                &&is_array($scope['scope']??null)&&is_bool($scope['scope']['cloud_allowed']??null)
                &&function_exists('homeserver_scope_v026_normalize')
                &&homeserver_scope_v026_normalize($scope['scope'])===$scope['scope'];
            $knownLegacy=false;
            if(!$ready&&empty($scope['last_known_cloud_blocked'])){
                $legacy??=video_meeting_homeserver_legacy_scope_v1801($pdo,$ownerId);
                $knownLegacy=($legacy['unpaired']&&(($scope['reason']??'')==='homeserver_not_paired'
                        ||(($scope['supported']??null)===false&&($scope['reason']??'')==='scope_not_supported')))
                    ||($legacy['unsupported']&&($scope['supported']??null)===false&&($scope['reason']??'')==='scope_not_supported');
            }
            if(!$ready&&!$knownLegacy)throw new RuntimeException('HomeServer privacy scope is unavailable.',503);
            $state['cloud_processing_allowed']=$state['requested_compute']!=='homeserver_only'
                &&($knownLegacy||!homeserver_scope_v026_blocks_cloud($scope));
            $state['policy_resolved']=true;
            if(empty($state['cloud_processing_allowed']))$state['cloud_block_reason']=$state['requested_compute']==='homeserver_only'?'homeserver_only':'homeserver_scope';
        }catch(Throwable $ignored){
            // Privacy uncertainty is not permission. Automatic routing fails
            // closed for live speech until the owner scope can be resolved.
            $state['cloud_processing_allowed']=false;
            $state['cloud_block_reason']=$state['requested_compute']==='homeserver_only'?'homeserver_only':'homeserver_scope_unavailable';
            $state['policy_resolved']=true;
        }
    }else{
        // An absent helper is not evidence that a paired server has no policy.
        // Only a successful stored-state read can establish legacy absence.
        try{
            $legacy??=video_meeting_homeserver_legacy_scope_v1801($pdo,$ownerId);
            $state['cloud_processing_allowed']=$state['requested_compute']==='auto'&&($legacy['unpaired']||$legacy['unsupported']);
            if(empty($state['cloud_processing_allowed']))$state['cloud_block_reason']=$state['requested_compute']==='homeserver_only'?'homeserver_only':'homeserver_scope_unavailable';
        }catch(Throwable $ignored){
            $state['cloud_processing_allowed']=false;
            $state['cloud_block_reason']=$state['requested_compute']==='homeserver_only'?'homeserver_only':'homeserver_scope_unavailable';
        }
        $state['policy_resolved']=true;
    }

    // Processing permission does not require the optional local inventory. Its
    // legacy status refresh can provision storage and is kept out of reads.
    if($readonlyPolicy){
        $state['reason']=empty($state['cloud_processing_allowed'])?(string)$state['cloud_block_reason']:'homeserver_scope_ready';
        return $cache[$cacheKey]=$state;
    }

    // v0.33 is a sanitized capability view. It never exposes relay/bearer
    // credentials. Query it only in the authenticated owner context.
    if($state['paired']&&function_exists('homeserver_capability_v033_registry')){
        try{
            $registry=homeserver_capability_v033_registry($ownerId,$forceRefresh);
            $state['available']=!empty($registry['available']);
            $state['knowledge_searchable']=!empty($registry['knowledge']['available'])&&!empty($registry['knowledge']['searchable']);
            $state['local_compute_available']=!empty($registry['compute']['available']);
            $operations=is_array($registry['operations']??null)?$registry['operations']:[];
            foreach(['meeting.transcription.stream','transcription.stream','transcription.start'] as $operation){
                if(in_array($operation,$operations,true)){
                    $state['local_transcription_advertised']=true;
                    $state['local_transcription_operation']=$operation;
                    break;
                }
            }
            $state['reason']=$state['available']?'homeserver_ready':(string)($registry['reason']??'homeserver_unavailable');
        }catch(Throwable $ignored){
            $state['reason']='homeserver_unavailable';
        }
    }elseif($state['paired']){
        $state['reason']='homeserver_paired';
    }elseif($state['requested_compute']==='homeserver_only'){
        $state['reason']='homeserver_required_unpaired';
    }else{
        $state['reason']='homeserver_not_paired';
    }

    return $cache[$cacheKey]=$state;
}

function video_meeting_cloud_transcription_allowed_v1801(PDO $pdo,array $meeting): bool
{
    if(empty($meeting['transcription_enabled']))return false;
    $state=video_meeting_homeserver_policy_v1801($pdo,$meeting);
    // Live speech is sensitive. An unresolved owner policy is never treated as
    // permission to dispatch cloud STT.
    return $state['cloud_processing_allowed']===true;
}

function video_meeting_homeserver_public_policy_v1801(PDO $pdo,array $meeting): array
{
    $state=video_meeting_homeserver_policy_v1801($pdo,$meeting);
    return [
        'version'=>(string)$state['version'],
        'paired'=>!empty($state['paired']),
        'available'=>!empty($state['available']),
        'policy_resolved'=>!empty($state['policy_resolved']),
        'requested_compute'=>(string)$state['requested_compute'],
        'cloud_processing_allowed'=>$state['cloud_processing_allowed']===null?null:($state['cloud_processing_allowed']===true),
        'cloud_block_reason'=>(string)$state['cloud_block_reason'],
        'knowledge_searchable'=>!empty($state['knowledge_searchable']),
        'local_compute_available'=>!empty($state['local_compute_available']),
        'local_transcription_advertised'=>!empty($state['local_transcription_advertised']),
        'local_transcription_operation'=>(string)$state['local_transcription_operation'],
        'reason'=>(string)$state['reason'],
    ];
}

/**
 * Recording-library edits may rename capture_mode while retaining the meeting
 * binding. The read-only processing boundary follows that binding, validates
 * every supplied identifier and never lets a fallback hide a conflicting ID.
 */
function video_meeting_transcription_readonly_binding_v1801(PDO $pdo,array $user,array $session): ?array
{
    $raw=$session['metadata_json']??'';
    if($raw===null||$raw==='')return null;
    if(!is_string($raw))throw new RuntimeException('Meeting source policy is unavailable.',503);
    if(trim($raw)==='')return null;
    try{$metadata=json_decode($raw,true,512,JSON_THROW_ON_ERROR);}
    catch(JsonException $error){throw new RuntimeException('Meeting source policy is unavailable.',503,$error);}
    if(!is_array($metadata)||($metadata!==[]&&array_is_list($metadata))){
        throw new RuntimeException('Meeting source policy is unavailable.',503);
    }
    $hasId=array_key_exists('video_meeting_id',$metadata)&&$metadata['video_meeting_id']!==null&&$metadata['video_meeting_id']!=='';
    $hasPublic=array_key_exists('video_meeting_public_id',$metadata)&&$metadata['video_meeting_public_id']!==null&&$metadata['video_meeting_public_id']!=='';
    if(!$hasId&&!$hasPublic&&($metadata['capture_mode']??null)!=='vp3_video_meeting')return null;
    if(!$hasId&&!$hasPublic)throw new RuntimeException('Meeting source binding is invalid.',403);
    $owner=(int)($user['id']??0);
    if($owner<1||(int)($session['created_by_user_id']??0)!==$owner)throw new RuntimeException('Meeting source owner changed.',403);
    $id=0;$public='';
    if($hasId){
        $value=$metadata['video_meeting_id'];
        if(is_int($value)&&$value>0)$id=$value;
        elseif(is_string($value)&&preg_match('/\A[1-9][0-9]{0,17}\z/D',$value)&&(string)(int)$value===$value)$id=(int)$value;
        else throw new RuntimeException('Meeting source binding is invalid.',403);
    }
    if($hasPublic){
        if(!is_string($metadata['video_meeting_public_id'])||!preg_match('/\A[a-f0-9]{32}\z/Di',$metadata['video_meeting_public_id'])){
            throw new RuntimeException('Meeting source binding is invalid.',403);
        }
        $public=strtolower($metadata['video_meeting_public_id']);
    }
    try{
        if(!function_exists('video_meeting_schema_ready_v1800')||!video_meeting_schema_ready_v1800($pdo)){
            throw new RuntimeException('Meeting source storage is unavailable.',503);
        }
        $byId=$hasId?video_meeting_row_v1800($pdo,$id):null;
        $byPublic=$hasPublic?video_meeting_by_public_id_v1800($pdo,$public):null;
    }catch(PDOException $error){throw new RuntimeException('Meeting source storage is unavailable.',503,$error);}
    foreach([$hasId?$byId:true,$hasPublic?$byPublic:true] as $meeting){
        if($meeting===true)continue;
        if(!$meeting||(int)($meeting['owner_user_id']??0)!==$owner)throw new RuntimeException('Meeting source binding is invalid.',403);
    }
    if($hasId&&$hasPublic){
        if((int)$byId['id']!==(int)$byPublic['id'])throw new RuntimeException('Meeting source bindings do not match.',403);
        if($byId!==$byPublic)throw new RuntimeException('Meeting source changed during validation.',409);
    }
    return $byId??$byPublic;
}

/**
 * Resolve the processing policy for a canonical transcription session that was
 * created by Video Meetings. Non-meeting transcripts return null and keep their
 * existing transcription-intelligence behavior unchanged. Status reads may use
 * cached HomeServer scope; a cloud-AI execution guard refreshes it immediately
 * before sending any private meeting material to a cloud provider.
 */
function video_meeting_transcription_ai_policy_v1801(PDO $pdo,array $user,array $session,bool $forceRefresh=false,bool $readonlyPolicy=false,?array $processingScope=null): ?array
{
    if($readonlyPolicy){
        if($processingScope!==null){
            $processingScope=video_meeting_processing_scope_readonly_v1801($pdo,$user,$processingScope);
            if(!function_exists('vp3_transcript_session_v1'))require_once __DIR__.'/transcript-agent-v1.php';
            $session=vp3_transcript_session_v1($pdo,$processingScope['user'],$processingScope['principal'],(int)($session['id']??0));
            $user=$processingScope['user'];
        }
        $meeting=video_meeting_transcription_readonly_binding_v1801($pdo,$user,$session);
        if($meeting===null)return null;
    }else{
        $metadata=json_decode((string)($session['metadata_json']??''),true);
        if(!is_array($metadata))return null;
        if((string)($metadata['capture_mode']??'')!=='vp3_video_meeting'){
            $retained=false;
            foreach(['video_meeting_id','video_meeting_public_id'] as $key){
                if(array_key_exists($key,$metadata)&&$metadata[$key]!==null&&$metadata[$key]!=='')$retained=true;
            }
            if(!$retained)return null;
            // Audio-library edits rename the capture mode but cannot remove
            // the processing restrictions of the retained meeting binding.
            $meeting=video_meeting_transcription_readonly_binding_v1801($pdo,$user,$session);
        }else{
            $sessionOwner=(int)($session['created_by_user_id']??0);$userId=(int)($user['id']??0);
            if($sessionOwner<1||$userId<1||$sessionOwner!==$userId){
                return ['is_meeting'=>true,'cloud_ai_allowed'=>false,'policy_resolved'=>true,'reason'=>'meeting_transcript_owner_mismatch','requested_compute'=>'unknown','local_compute_available'=>false];
            }

            $meetingId=max(0,(int)($metadata['video_meeting_id']??0));$publicId=strtolower(trim((string)($metadata['video_meeting_public_id']??'')));
            $meeting=$meetingId>0&&function_exists('video_meeting_row_v1800')?video_meeting_row_v1800($pdo,$meetingId):null;
            if(!$meeting&&$publicId!==''&&function_exists('video_meeting_by_public_id_v1800'))$meeting=video_meeting_by_public_id_v1800($pdo,$publicId);
            if(!$meeting||(int)($meeting['owner_user_id']??0)!==$sessionOwner){
                return ['is_meeting'=>true,'cloud_ai_allowed'=>false,'policy_resolved'=>true,'reason'=>'meeting_binding_unavailable','requested_compute'=>'unknown','local_compute_available'=>false];
            }
        }
    }

    $state=video_meeting_homeserver_policy_v1801($pdo,$meeting,$forceRefresh,$readonlyPolicy,$processingScope);
    return [
        'is_meeting'=>true,
        'meeting_id'=>(int)$meeting['id'],
        'cloud_ai_allowed'=>$state['cloud_processing_allowed']===true,
        'policy_resolved'=>!empty($state['policy_resolved']),
        'requested_compute'=>(string)$state['requested_compute'],
        'local_compute_available'=>!empty($state['local_compute_available']),
        'reason'=>(string)$state['reason'],
        'cloud_block_reason'=>(string)$state['cloud_block_reason'],
    ];
}

function video_meeting_transcription_assert_cloud_ai_v1801(PDO $pdo,array $user,array $session,bool $readonlyPolicy=false,?array $processingScope=null): ?array
{
    // This is a processing gate, including for legacy foreground callers.
    // UI/status callers can still use the policy's default display behavior.
    $policy=video_meeting_transcription_ai_policy_v1801($pdo,$user,$session,true,true,$processingScope);
    if($policy===null)return null;
    if(!empty($policy['cloud_ai_allowed']))return $policy;

    $message='This meeting transcript is not authorized for VP3 Cloud AI processing.';
    if((string)($policy['requested_compute']??'')==='homeserver_only')$message='This meeting uses HomeServer-only processing. VP3 Cloud AI Summary is disabled for this transcript.';
    elseif(!empty($policy['local_compute_available']))$message='This meeting is restricted to private HomeServer processing. VP3 Cloud AI Summary is disabled for this transcript.';
    $reasons=[(string)($policy['reason']??''),(string)($policy['cloud_block_reason']??'')];
    $unavailable=['compute_policy_unavailable','homeserver_scope_unavailable','owner_policy_not_resolved'];
    $denied=['homeserver_only','homeserver_scope','meeting_transcript_owner_mismatch','meeting_binding_unavailable'];
    if(array_intersect($reasons,$unavailable)||!array_intersect($reasons,$denied)){
        throw new RuntimeException('Meeting processing policy is temporarily unavailable.',503);
    }
    throw new RuntimeException($message,403);
}
