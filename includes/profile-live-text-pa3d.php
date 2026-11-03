<?php
declare(strict_types=1);

/** Explicit text delivery choice over the existing durable PA3 thread. */
function vp3_profile_handoff_chat_mode_pa3d(mixed $mode): string
{
    if(!is_string($mode)||!in_array($mode,['auto','agent','live'],true))vp3_profile_handoff_error_pa3('HANDOFF_INVALID',422);
    return $mode;
}

/** A strict, uncached read. Never initialize settings or infer presence from Team Chat. */
function vp3_profile_handoff_live_allowed_pa3d(PDO $pdo,int $owner,int $profileAgentId=0,int $chatCid=0,int $chatAid=0,bool $currentRead=false): bool
{
    if($owner<1)return false;
    try{
        // Selecting sound_enabled also verifies the established form's ready schema.
        $current=$currentRead&&$pdo->inTransaction()?vp3_profile_handoff_lock_pa3($pdo):'';
        $q=$pdo->prepare('SELECT presence_mode,social_chat_enabled,sound_enabled FROM team_user_presence WHERE user_id=? LIMIT 2'.$current);$q->execute([$owner]);$settings=$q->fetchAll(PDO::FETCH_ASSOC);
        if(count($settings)>1)return false;
        if($settings&&($settings[0]['presence_mode']!=='online'||!in_array($settings[0]['social_chat_enabled'],[1,'1',true],true)))return false;
        $q=$pdo->prepare('SELECT p.is_public,p.profile_agent_enabled,p.profile_agent_id,u.* FROM user_profiles p INNER JOIN users u ON u.id=p.user_id WHERE p.user_id=?');$q->execute([$owner]);$user=$q->fetch(PDO::FETCH_ASSOC);
        if(!$user||!in_array($user['is_active']??null,[1,'1',true],true)||!in_array($user['is_public']??null,[1,'1',true],true)||!in_array($user['profile_agent_enabled']??null,[1,'1',true],true))return false;
        $selected=(int)($user['profile_agent_id']??0);if($selected<1||($profileAgentId>0&&$selected!==$profileAgentId))return false;
        if(!function_exists('has_permission')||!has_permission('chat.access',$user))return false;
        if(!function_exists('vp3_observation_personal_permission_v1'))require_once __DIR__.'/agent-observation-authority-v1.php';
        foreach(['profile_agent.access','profile_chat.access'] as $permission)if(!vp3_observation_personal_permission_v1($pdo,$user,$permission))return false;
        $q=$pdo->prepare('SELECT id FROM user_agents WHERE id=? AND owner_user_id=? AND is_active=1');$q->execute([$selected,$owner]);if(!$q->fetchColumn())return false;
        if(vp3_profile_handoff_availability_pa3($pdo,$owner,$currentRead)!=='available')return false;
        $now=vp3_profile_handoff_now_pa3();$params=[$owner,$now-VP3_PROFILE_HANDOFF_PRESENCE_MS_PA3,$now];$exact='';
        if($chatCid>0){$exact=' AND p.chat_conversation_id=? AND p.chat_agent_id=?';$params[]=$chatCid;$params[]=$chatAid;}
        // Lock only the presence rows on write gates, never joined Profile/user rows.
        $q=$pdo->prepare('SELECT p.chat_conversation_id,p.chat_agent_id FROM profile_agent_handoff_presence_pa3 p WHERE p.owner_user_id=? AND p.visible=1 AND p.last_seen_ms>=? AND p.last_seen_ms<=?'.$exact.' ORDER BY p.last_seen_ms DESC LIMIT 20'.$current);$q->execute($params);
        foreach($q->fetchAll(PDO::FETCH_ASSOC) as $presence){
            $aid=(int)$presence['chat_agent_id'];$chat=$pdo->prepare('SELECT user_id,user_agent_id FROM chat_conversations WHERE id=?');$chat->execute([(int)$presence['chat_conversation_id']]);$binding=$chat->fetch(PDO::FETCH_ASSOC);if(!$binding||(int)$binding['user_id']!==$owner||(int)($binding['user_agent_id']??0)!==$aid)continue;
            if($aid===0)return true;$a=$pdo->prepare('SELECT id FROM user_agents WHERE id=? AND owner_user_id=? AND is_active=1');$a->execute([$aid,$owner]);if($a->fetchColumn())return true;
        }
    }catch(Throwable $error){
        // A deadlock may have rolled back the entire caller transaction.
        if($currentRead&&!$pdo->inTransaction())throw $error;
        return false;
    }
    return false;
}


/** Stable pre-PA3d columns detect durable ownership during a rolling schema upgrade. */
function vp3_profile_handoff_exists_pa3d(PDO $pdo,int $owner,int $cid): bool
{
    try{$q=$pdo->prepare('SELECT id FROM profile_agent_handoff_turns_pa3 WHERE owner_user_id=? AND conversation_id=? LIMIT 1');$q->execute([$owner,$cid]);return(bool)$q->fetchColumn();}
    catch(PDOException $error){
        $state=(string)$error->getCode();$message=$error->getMessage();
        if($state==='42S02'||($pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite'&&str_contains($message,'no such table: profile_agent_handoff_turns_pa3')))return false;
        throw $error;
    }
}

/** Public data only; safe even before PA3 has been installed or a thread exists. */
function vp3_profile_handoff_chat_pa3d(PDO $pdo,int $owner,int $conversationId=0): array
{
    $name='';$mode='auto';$status='none';$canEnd=false;
    try{$q=$pdo->prepare('SELECT u.display_name FROM users u INNER JOIN user_profiles p ON p.user_id=u.id WHERE u.id=? AND u.is_active=1 AND p.is_public=1');$q->execute([$owner]);$value=$q->fetchColumn();if(is_string($value))$name=mb_substr(trim($value),0,100);}catch(Throwable $error){}
    $ready=vp3_profile_handoff_schema_ready_pa3($pdo);
    if($conversationId>0&&$ready){
        $thread=vp3_profile_handoff_thread_pa3($pdo,$owner,$conversationId);
        if($thread){$mode=in_array($thread['requested_mode']??null,['auto','agent','live'],true)?$thread['requested_mode']:'auto';$status=in_array($thread['live_status']??null,['none','waiting','accepted','declined','expired','unavailable','ended'],true)?$thread['live_status']:'unavailable';$canEnd=$mode==='live'&&in_array($thread['mode'],['pending','owner'],true)&&in_array($status,['waiting','accepted'],true);}
    }
    return ['contract'=>'vp3.profile-chat-choice.v1','owner_name'=>$name,'mode'=>$mode,'live_status'=>$status,'live_text_available'=>$ready&&vp3_profile_handoff_live_allowed_pa3d($pdo,$owner),'can_end_live_chat'=>$canEnd];
}

/** End only the exact visitor's latest observed live turn; a retry cannot end a new request. */
function vp3_profile_agent_public_end_live_chat_pa3d(PDO $pdo,array $ctx,int $cid,int $turnId,int $revision): array
{
    vp3_profile_handoff_id_pa3($cid);vp3_profile_handoff_id_pa3($turnId);vp3_profile_handoff_id_pa3($revision);vp3_profile_handoff_require_pa3($pdo);
    $ctx=vp3_profile_agent_public_live_v1($pdo,$ctx);
    if(($ctx['conversation_authority']['transport']??null)!=='native')vp3_profile_agent_public_unavailable_v1();
    vp3_profile_handoff_atomic_pa3($pdo,function()use($pdo,$ctx,$cid,$turnId,$revision){
        $ctx=vp3_profile_agent_public_live_v1($pdo,$ctx,true);vp3_profile_agent_public_bound_conversation_v1($pdo,$ctx,$cid,true);$owner=(int)$ctx['owner_user_id'];
        $turn=vp3_profile_handoff_row_pa3($pdo,$turnId,true);$thread=vp3_profile_handoff_thread_pa3($pdo,$owner,$cid,true);
        if(!$turn||!$thread||(int)$turn['owner_user_id']!==$owner||(int)$turn['conversation_id']!==$cid||(int)$turn['profile_agent_id']!==(int)$ctx['agent_id']||(int)$turn['profile_session_id']!==(int)$ctx['session_id']||(int)$turn['visitor_user_id']!==(int)$ctx['conversation_authority']['visitor_user_id']||$turn['transport']!==$ctx['conversation_authority']['transport']||!hash_equals((string)$turn['credential_hash'],(string)$ctx['conversation_authority']['credential_hash']))vp3_profile_agent_public_unavailable_v1();
        $q=$pdo->prepare('SELECT id FROM profile_agent_handoff_turns_pa3 WHERE owner_user_id=? AND conversation_id=? ORDER BY visitor_message_id DESC,id DESC LIMIT 1');$q->execute([$owner,$cid]);if((int)$q->fetchColumn()!==$turnId)vp3_profile_handoff_error_pa3();
        $endHash=hash('sha256',json_encode(['visitor_end_live',$owner,$cid,$turnId,$revision,(int)$ctx['session_id'],(int)$ctx['conversation_authority']['visitor_user_id'],(string)$ctx['conversation_authority']['transport'],(string)$ctx['conversation_authority']['credential_hash']],JSON_THROW_ON_ERROR));$requestId=substr($endHash,0,32);
        $q=$pdo->prepare('SELECT request_hash,operation,turn_id FROM profile_agent_handoff_operations_pa3 WHERE owner_user_id=? AND request_id=?');$q->execute([$owner,$requestId]);$prior=$q->fetch(PDO::FETCH_ASSOC);
        if($prior&&$prior['operation']==='end_live'&&(int)$prior['turn_id']===$turnId&&hash_equals((string)$prior['request_hash'],$endHash)&&$thread['requested_mode']==='agent'&&$thread['mode']==='agent'&&$thread['live_status']==='ended')return;
        if($prior)vp3_profile_handoff_error_pa3();
        if((int)$turn['version']!==$revision||$thread['requested_mode']!=='live'||!in_array($thread['mode'],['pending','owner'],true)||!in_array($thread['live_status'],['waiting','accepted'],true))vp3_profile_handoff_error_pa3();
        vp3_profile_agent_response_token_v1($pdo,$cid,true);vp3_profile_handoff_mode_pa3($pdo,$owner,$cid,'agent','ended','agent');
        if(in_array($turn['state'],['replied','blocked','cancelled'],true))$pdo->prepare('UPDATE profile_agent_handoff_turns_pa3 SET version=version+1,updated_at=? WHERE id=? AND owner_user_id=?')->execute([vp3_profile_handoff_date_pa3(),$turnId,$owner]);
        $pdo->prepare('INSERT INTO profile_agent_handoff_operations_pa3(owner_user_id,request_id,turn_id,operation,request_hash,message_id,created_at_ms) VALUES(?,?,?,?,?,NULL,?)')->execute([$owner,$requestId,$turnId,'end_live',$endHash,vp3_profile_handoff_now_pa3()]);
        vp3_profile_agent_public_live_v1($pdo,$ctx,true);
    });
    return ['conversation_id'=>$cid,'live_chat_ended'=>true]+vp3_profile_agent_public_state_service_v110($pdo,$ctx,$cid);
}
