<?php
declare(strict_types=1);

const VP3_PROFILE_AGENT_PUBLIC_SERVICE_V110='vp3-profile-agent-public-service-v110-20260928';

final class VP3ProfileAgentPublicException extends RuntimeException
{
    public function __construct(
        public readonly string $publicCode,
        string $message,
        public readonly int $httpStatus=400
    ){ parent::__construct($message); }
}

function vp3_profile_agent_public_unavailable_v1(): never
{
    throw new VP3ProfileAgentPublicException('PROFILE_AGENT_UNAVAILABLE','This Profile Agent chat is not available.',404);
}

/** Re-read authority. A CRM contact row never authenticates the current visitor. */
function vp3_profile_agent_public_live_v1(PDO $pdo,array $ctx,bool $lock=false): array
{
    $owner=(int)($ctx['owner_user_id']??0);$agentId=(int)($ctx['agent_id']??0);
    $authority=$ctx['conversation_authority']??null;
    if(!vp3_profile_agent_conversation_authority_valid_v1(is_array($authority)?$authority:null,$owner))vp3_profile_agent_public_unavailable_v1();
    $suffix=$lock&&$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)!=='sqlite'?' FOR UPDATE':'';
    $q=$pdo->prepare('SELECT p.*,u.display_name,u.role,u.is_active,u.avatar_path FROM user_profiles p INNER JOIN users u ON u.id=p.user_id WHERE p.user_id=? LIMIT 1'.$suffix);
    $q->execute([$owner]);$profile=$q->fetch(PDO::FETCH_ASSOC);
    $q=$pdo->prepare('SELECT * FROM user_agents WHERE id=? AND owner_user_id=? LIMIT 1'.$suffix);
    $q->execute([$agentId,$owner]);$agent=$q->fetch(PDO::FETCH_ASSOC);
    $ownerUser=profile_user_row($pdo,$owner);
    if(!$profile||!$ownerUser||empty($ownerUser['is_active'])||empty($profile['is_active'])||empty($profile['is_public'])
       ||empty($profile['profile_agent_enabled'])||(int)($profile['profile_agent_id']??0)!==$agentId
       ||!$agent||empty($agent['is_active']))vp3_profile_agent_public_unavailable_v1();
    foreach(['username','display_name','bio','profile_agent_instructions','profile_agent_greeting'] as $field){
        if((string)($profile[$field]??'')!==(string)($ctx['profile'][$field]??''))vp3_profile_agent_public_unavailable_v1();
    }
    if(!function_exists('vp3_observation_personal_permission_v1'))require_once __DIR__.'/agent-observation-authority-v1.php';
    if(!vp3_observation_personal_permission_v1($pdo,$ownerUser,'profile_agent.access')
       ||!vp3_observation_personal_permission_v1($pdo,$ownerUser,'profile_chat.access'))vp3_profile_agent_public_unavailable_v1();
    $visitorId=(int)($ctx['visitor']['id']??0);
    if($visitorId!==$authority['visitor_user_id']||$visitorId===$owner)vp3_profile_agent_public_unavailable_v1();
    if($authority['transport']==='native'){
        if(function_exists('reset_current_user_cache'))reset_current_user_cache();
        $current=function_exists('current_user')?current_user():null;
        if((int)($current['id']??0)!==$visitorId)vp3_profile_agent_public_unavailable_v1();
        $freshAuthority=profile_conversation_native_authority_v1($owner,$visitorId);
        if(!hash_equals($authority['credential_hash'],$freshAuthority['credential_hash']))vp3_profile_agent_public_unavailable_v1();
        if($visitorId>0){
            $q=$pdo->prepare('SELECT id,is_active FROM users WHERE id=? LIMIT 1'.$suffix);$q->execute([$visitorId]);$member=$q->fetch(PDO::FETCH_ASSOC);
            if(!$member||empty($member['is_active']))vp3_profile_agent_public_unavailable_v1();
        }
    }elseif(!function_exists('vp3_profile_webmcp_external_chat_authorized_v1')||!vp3_profile_webmcp_external_chat_authorized_v1($pdo,$ctx,$lock)){
        vp3_profile_agent_public_unavailable_v1();
    }
    $q=$pdo->prepare('SELECT * FROM profile_visit_sessions WHERE id=? AND owner_user_id=? LIMIT 1'.$suffix);
    $q->execute([(int)($ctx['session_id']??0),$owner]);$session=$q->fetch(PDO::FETCH_ASSOC);
    if(!$session||!is_string($ctx['session']['session_key']??null)
       ||!hash_equals((string)$session['session_key'],$ctx['session']['session_key']))vp3_profile_agent_public_unavailable_v1();
    $ctx['profile']=$profile;$ctx['agent']=$agent;$ctx['owner_user']=$ownerUser;$ctx['session']=$session;
    return $ctx;
}

/** Current policy for only the sources actually supplied to inference; no provider calls. */
function vp3_profile_agent_context_authorized_v1(PDO $pdo,array $profile,array $agent,?array $visitor,array $context): bool
{
    $owner=(int)($profile['user_id']??0);
    if($owner<1||count($context)>24||(int)($agent['owner_user_id']??0)!==$owner)return false;
    $principal=user_agent_principal_v236($visitor,$agent,true);$catalog=null;$booking=null;$commerce=null;
    foreach($context as $item){
        if(!is_array($item)||!is_string($item['source']??null))return false;
        $source=$item['source'];
        if(in_array($source,['profile:identity','profile:rules'],true))continue;
        if(preg_match('/^knowledge:([1-9][0-9]*)$/D',$source,$m)){
            $q=$pdo->prepare('SELECT created_by_user_id,visibility,is_published FROM knowledge_items WHERE id=? LIMIT 1');$q->execute([(int)$m[1]]);$row=$q->fetch(PDO::FETCH_ASSOC);
            if(!$row||(int)$row['created_by_user_id']!==$owner||!user_data_policy_can_use_v236($pdo,$principal,$owner,'knowledge',$m[1],!empty($row['is_published'])&&$row['visibility']==='public'))return false;
        }elseif(preg_match('/^profile:(track|album|show|photo|post|merch):([1-9][0-9]*)$/D',$source,$m)){
            if($catalog===null)$catalog=profile_public_catalog($pdo,$profile,$visitor);
            $kinds=['track'=>'tracks','album'=>'albums','show'=>'shows','photo'=>'photos','post'=>'posts','merch'=>'merch'];$found=null;
            foreach($catalog[$kinds[$m[1]]]??[] as $row)if((int)($row['id']??0)===(int)$m[2]){$found=$row;break;}
            if(!$found||!user_data_policy_can_use_v236($pdo,$principal,$owner,$m[1],$m[2],$m[1]==='show'||($found['visibility']??'public')==='public'))return false;
        }elseif(preg_match('/^homeserver:(memory|knowledge|contacts|tasks|calendar|files|notifications):/D',$source,$m)){
            if(!user_data_policy_can_use_v236($pdo,$principal,$owner,'homeserver_'.$m[1],'*',false))return false;
        }elseif(preg_match('/^agent-brain:transcript-analysis:([1-9][0-9]*)$/D',$source,$m)){
            if(!function_exists('vp3_observation_personal_permission_v1'))require_once __DIR__.'/agent-observation-authority-v1.php';
            $ownerUser=profile_user_row($pdo,$owner);
            if(!$ownerUser||!vp3_observation_personal_permission_v1($pdo,$ownerUser,'agent_brain.access'))return false;
            $q=$pdo->prepare('SELECT user_id,is_active,memory_type FROM agent_memory_items WHERE id=? LIMIT 1');$q->execute([(int)$m[1]]);$row=$q->fetch(PDO::FETCH_ASSOC);
            if(!$row||(int)$row['user_id']!==$owner||empty($row['is_active'])||!in_array($row['memory_type'],['transcript_analysis','transcript_highlight','transcript_intelligence','transcript_note'],true)
               ||!user_data_policy_can_use_v236($pdo,$principal,$owner,'knowledge','brain:'.$m[1],false))return false;
        }elseif(preg_match('/^profile:booking:([1-9][0-9]*)$/D',$source,$m)){
            if(!function_exists('agent_scheduling_public_schedule_v450')||!function_exists('agent_scheduling_public_events_v450'))return false;
            if($booking===null){$schedule=agent_scheduling_public_schedule_v450($pdo,$owner);$booking=$schedule?agent_scheduling_public_events_v450($pdo,(int)$schedule['id']):[];}
            if(!in_array((int)$m[1],array_map('intval',array_column($booking,'id')),true))return false;
        }elseif(preg_match('/^profile:commerce:([1-9][0-9]*)$/D',$source,$m)){
            if(!function_exists('profile_commerce_products_for_profile_v900'))return false;
            if($commerce===null)$commerce=profile_commerce_products_for_profile_v900($pdo,$profile,true,200);
            if(!in_array((int)$m[1],array_map('intval',array_column($commerce,'id')),true))return false;
        }elseif($source==='profile:webmcp-capabilities'){
            if(!function_exists('vp3_profile_webmcp_agent_capability_context_v190'))return false;
            $current=vp3_profile_webmcp_agent_capability_context_v190($pdo,$profile,$visitor);
            foreach((array)($item['capabilities']??[]) as $capability)if(!in_array($capability,(array)($current['capabilities']??[]),true))return false;
        }else return false;
    }
    return true;
}

/** Must be called with the owner/session row locked, before allocating a new thread. */
function vp3_profile_agent_public_admit_v1(PDO $pdo,array $ctx): void
{
    if(!$pdo->inTransaction())throw new RuntimeException('Message admission requires a transaction.');
    $sqlite=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite';
    $minute=$sqlite?"datetime('now','-1 minute')":'DATE_SUB(NOW(),INTERVAL 1 MINUTE)';
    $seconds=$sqlite?"datetime('now','-2 seconds')":'DATE_SUB(NOW(),INTERVAL 2 SECOND)';
    $q=$pdo->prepare("SELECT COUNT(*) total,MAX(CASE WHEN m.created_at>$seconds THEN 1 ELSE 0 END) too_fast
      FROM profile_agent_messages m INNER JOIN profile_agent_conversations c ON c.id=m.conversation_id
      WHERE c.owner_user_id=? AND c.profile_session_id=? AND m.sender_type='visitor' AND m.created_at>=$minute");
    $q->execute([(int)$ctx['owner_user_id'],(int)$ctx['session_id']]);$row=$q->fetch(PDO::FETCH_ASSOC);
    if((int)($row['total']??0)>=8||(int)($row['too_fast']??0)>0){
        throw new VP3ProfileAgentPublicException('RATE_LIMITED','Please wait a moment before sending another message.',429);
    }
}

function vp3_profile_agent_public_bound_conversation_v1(PDO $pdo,array $ctx,int $id,bool $lock=false): array
{
    $conversation=vp3_profile_agent_public_conversation_v390($pdo,$id,(int)$ctx['owner_user_id'],(int)$ctx['agent_id'],(int)$ctx['session_id'],$lock,$ctx['conversation_authority']??null);
    if(!$conversation)throw new VP3ProfileAgentPublicException('CONVERSATION_NOT_FOUND','Conversation not found for this Profile Agent.',404);
    return $conversation;
}

function vp3_profile_agent_public_create_bound_v1(PDO $pdo,array $ctx): array
{
    $session=vp3_profile_agent_public_event_session_v1($ctx);
    $conversation=profile_agent_conversation_create($pdo,$ctx['profile'],$ctx['agent'],$session);
    vp3_profile_agent_conversation_bind_v1($pdo,$conversation,$ctx['conversation_authority']);
    return $conversation;
}

function vp3_profile_agent_public_event_session_v1(array $ctx): array
{
    $session=$ctx['session'];$visitorId=(int)($ctx['visitor']['id']??0);
    if($visitorId<1||(int)($session['visitor_user_id']??0)!==$visitorId)$session['identity_disclosed']=0;
    $session['visitor_user_id']=$visitorId?:null;
    return $session;
}

/** Reusing a past Agent answer also requires its recorded sources to remain shared. */
function vp3_profile_agent_public_history_v1(PDO $pdo,array $ctx,int $conversationId): array
{
    $q=$pdo->prepare("SELECT sender_type,message,SUBSTR(context_json,1,65537) context_json FROM profile_agent_messages WHERE conversation_id=? AND sender_type IN ('visitor','agent','owner') ORDER BY id DESC LIMIT 14");
    $q->execute([$conversationId]);$history=[];$sourceSets=[];
    foreach(array_reverse($q->fetchAll(PDO::FETCH_ASSOC)) as $row){
        if($row['sender_type']==='agent'){
            if(strlen((string)$row['context_json'])>65536)continue;
            $metadata=json_decode((string)$row['context_json'],true);$sources=$metadata['source_dependencies']??$metadata['sources']??null;
            if(!is_array($sources)||!array_is_list($sources)||!vp3_profile_agent_public_sources_current_v1($pdo,$ctx,[],[$sources]))continue;
            $sourceSets[]=$sources;
        }
        $history[]=['role'=>$row['sender_type']==='visitor'?'user':'assistant','message'=>(string)$row['message']];
    }
    return ['messages'=>$history,'source_sets'=>$sourceSets];
}

function vp3_profile_agent_public_sources_current_v1(PDO $pdo,array $ctx,array $context,array $historySources): bool
{
    foreach([...$historySources,$context] as $sources){
        if(count($sources)>128)return false;
        foreach(array_chunk($sources,24) as $batch){
            if(!vp3_profile_agent_context_authorized_v1($pdo,$ctx['profile'],$ctx['agent'],$ctx['visitor'],$batch))return false;
        }
    }
    return true;
}

function vp3_profile_agent_public_source_dependencies_v1(array $context,array $historySources): array
{
    $dependencies=[];
    foreach([...$historySources,$context] as $sources){
        foreach($sources as $item){
            $source=(string)($item['source']??'');
            if(in_array($source,['profile:identity','profile:rules'],true))continue;
            $entry=['source'=>$source];
            if($source==='profile:webmcp-capabilities')$entry['capabilities']=array_values(array_unique([...(array)($dependencies[$source]['capabilities']??[]),...(array)($item['capabilities']??[])]));
            $dependencies[$source]=$entry;
            if(count($dependencies)>128)vp3_profile_agent_public_unavailable_v1();
        }
    }
    return array_values($dependencies);
}

function vp3_profile_agent_public_start_v1(PDO $pdo,array $ctx): array
{
    $pdo->beginTransaction();
    try{
        $ctx=vp3_profile_agent_public_live_v1($pdo,$ctx,true);
        $q=$pdo->prepare("SELECT id FROM profile_agent_conversations WHERE owner_user_id=? AND profile_agent_id=? AND profile_session_id=? AND status IN ('open','owner_joined','resolved') ORDER BY id DESC LIMIT 20");
        $q->execute([(int)$ctx['owner_user_id'],(int)$ctx['agent_id'],(int)$ctx['session_id']]);$ids=$q->fetchAll(PDO::FETCH_COLUMN);$conversation=null;
        foreach($ids as $id){
            $conversation=vp3_profile_agent_public_conversation_v390($pdo,(int)$id,(int)$ctx['owner_user_id'],(int)$ctx['agent_id'],(int)$ctx['session_id'],true,$ctx['conversation_authority']);
            if($conversation)break;
        }
        if(!$conversation){vp3_profile_agent_public_admit_v1($pdo,$ctx);$conversation=vp3_profile_agent_public_create_bound_v1($pdo,$ctx);}
        vp3_profile_agent_public_live_v1($pdo,$ctx,true);$pdo->commit();
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
    return vp3_profile_agent_public_state_service_v110($pdo,$ctx,(int)$conversation['id']);
}

function vp3_profile_agent_public_context_v110(PDO $pdo,array $profile,?array $visitor): array
{
    $owner=(int)($profile['user_id']??0);
    $fresh=$owner>0?profile_for_user($pdo,$owner,false):null;
    if(!$fresh||!hash_equals((string)($profile['username']??''),(string)($fresh['username']??'')))vp3_profile_agent_public_unavailable_v1();
    $profile=$fresh;
    $ownerUser=$owner>0?profile_user_row($pdo,$owner):null;
    if(!$ownerUser
       || !personal_capability_has_v242('profile_agent.access',$ownerUser)
       || !personal_capability_has_v242('profile_chat.access',$ownerUser)){
        throw new VP3ProfileAgentPublicException('PROFILE_AGENT_UNAVAILABLE','This Profile Agent chat is not available.',404);
    }
    if((int)($visitor['id']??0)===$owner){
        throw new VP3ProfileAgentPublicException('OWNER_VISITOR_PREVIEW_REQUIRED','Use visitor preview from your Profile Agent dashboard.',403);
    }
    $agent=profile_active_agent($pdo,$profile);
    if(!$agent){
        throw new VP3ProfileAgentPublicException('PROFILE_AGENT_UNAVAILABLE','This Profile Agent is not available.',404);
    }
    $session=profile_runtime_session($pdo,$owner,$visitor,false);
    if(!is_array($session)||(int)($session['id']??0)<1){
        throw new VP3ProfileAgentPublicException('PROFILE_SESSION_UNAVAILABLE','The visitor session could not be established.',503);
    }
    $ctx=[
        'profile'=>$profile,
        'owner_user_id'=>$owner,
        'owner_user'=>$ownerUser,
        'visitor'=>$visitor,
        'agent'=>$agent,
        'agent_id'=>(int)$agent['id'],
        'session'=>$session,
        'session_id'=>(int)$session['id'],
        'conversation_authority'=>profile_conversation_native_authority_v1($owner,(int)($visitor['id']??0)),
    ];
    return vp3_profile_agent_public_live_v1($pdo,$ctx);
}

function vp3_profile_agent_public_agent_projection_v110(array $ctx): array
{
    return [
        'id'=>(int)$ctx['agent_id'],
        'name'=>(string)($ctx['agent']['display_name']??''),
        'system_name'=>system_agent_name(),
        'greeting'=>trim((string)($ctx['profile']['profile_agent_greeting']??'')),
    ];
}

function vp3_profile_agent_public_state_service_v110(PDO $pdo,array $ctx,int $conversationId=0): array
{
    $ctx=vp3_profile_agent_public_live_v1($pdo,$ctx);
    $messages=[];$conversationState=null;
    if($conversationId>0){
        $conversation=vp3_profile_agent_public_bound_conversation_v1($pdo,$ctx,$conversationId);
        $messages=profile_agent_messages($pdo,$conversationId);
        $conversation=vp3_profile_agent_public_bound_conversation_v1($pdo,$ctx,$conversationId);
        $conversationState=vp3_profile_agent_public_state_v390($conversation);
    }
    vp3_profile_agent_public_live_v1($pdo,$ctx);
    return [
        'agent'=>vp3_profile_agent_public_agent_projection_v110($ctx),
        'conversation'=>$conversationState,
        'messages'=>$messages,
    ];
}

function vp3_profile_agent_public_message_service_v110(PDO $pdo,array $ctx,string $query,int $conversationId=0): array
{
    $query=trim($query);
    if($query===''||mb_strlen($query)>2000){
        throw new VP3ProfileAgentPublicException('VALIDATION_FAILED','Enter a message up to 2,000 characters.',422);
    }
    $ctx=vp3_profile_agent_public_live_v1($pdo,$ctx);
    $profile=$ctx['profile'];$owner=(int)$ctx['owner_user_id'];$ownerUser=$ctx['owner_user'];
    $visitor=$ctx['visitor'];$agent=$ctx['agent'];$agentId=(int)$ctx['agent_id'];
    $session=vp3_profile_agent_public_event_session_v1($ctx);$sessionId=(int)$ctx['session_id'];$cid=max(0,$conversationId);

    $pdo->beginTransaction();
    try{
        $ctx=vp3_profile_agent_public_live_v1($pdo,$ctx,true);
        if($cid>0)$conversation=vp3_profile_agent_public_bound_conversation_v1($pdo,$ctx,$cid,true);
        vp3_profile_agent_public_admit_v1($pdo,$ctx);
        if($cid<1){$conversation=vp3_profile_agent_public_create_bound_v1($pdo,$ctx);$cid=(int)$conversation['id'];}
        $conversation=vp3_profile_agent_prepare_visitor_turn_v390($pdo,$conversation);
        $pdo->prepare("INSERT INTO profile_agent_messages (conversation_id,sender_type,sender_user_id,message) VALUES (?,'visitor',?,?)")
            ->execute([$cid,(int)($visitor['id']??0)?:null,$query]);
        $visitorMessageId=(int)$pdo->lastInsertId();
        $pdo->prepare('UPDATE profile_visit_sessions SET last_message_at=NOW(),last_seen_at=NOW() WHERE id=? AND owner_user_id=?')
            ->execute([$sessionId,$owner]);
        $pdo->prepare('UPDATE profile_agent_conversations SET last_summary=?,last_message_at=NOW(),updated_at=NOW() WHERE id=? AND owner_user_id=? AND profile_agent_id=? AND profile_session_id=?')
            ->execute([mb_strimwidth($query,0,900,'…'),$cid,$owner,$agentId,$sessionId]);
        profile_agent_message_received_v1341($pdo,$profile,$agent,$session,$conversation,$visitorMessageId,$query);
        vp3_profile_agent_public_live_v1($pdo,$ctx,true);
        $pdo->commit();
    }catch(Throwable $e){
        if($pdo->inTransaction())$pdo->rollBack();
        throw $e;
    }

    if(!vp3_profile_agent_agent_may_reply_v390($conversation)){
        vp3_profile_agent_public_live_v1($pdo,$ctx);
        return [
            'conversation_id'=>$cid,'answer'=>'','awaiting_owner'=>true,
            'conversation'=>vp3_profile_agent_public_state_v390($conversation),
            'agent'=>['name'=>(string)$agent['display_name'],'system_name'=>system_agent_name()],
        ];
    }

    $historical=vp3_profile_agent_public_history_v1($pdo,$ctx,$cid);
    $history=$historical['messages'];$historySources=$historical['source_sets'];
    $context=profile_agent_context($pdo,$profile,$agent,$visitor,$query);
    if(function_exists('profile_agent_transcript_brain_context_v255')){
        foreach(profile_agent_transcript_brain_context_v255($pdo,$ownerUser,$agent,$visitor,$query,$cid) as $item)$context[]=$item;
    }
    if(function_exists('vp3_profile_webmcp_agent_capability_context_v190')){
        $capabilityContext=vp3_profile_webmcp_agent_capability_context_v190($pdo,$profile,$visitor);
        if(!empty($capabilityContext['capabilities']))$context[]=$capabilityContext;
    }
    if(count($context)>24)$context=array_slice($context,0,24);
    $sourceDependencies=vp3_profile_agent_public_source_dependencies_v1($context,$historySources);
    if(!vp3_profile_agent_public_sources_current_v1($pdo,$ctx,$context,$historySources))vp3_profile_agent_public_unavailable_v1();
    vp3_profile_agent_public_live_v1($pdo,$ctx);

    $greeting=(bool)preg_match('/^(?:hi|hello|hey|good\s+(?:morning|afternoon|evening))[!.\s]*$/i',$query);
    $profileHome=['attempted'=>false,'success'=>false,'execution'=>null,'failure_class'=>'none'];
    $answerMode='model';
    $needsOwner=false;
    if($greeting&&trim((string)($profile['profile_agent_greeting']??''))!==''){
        $answer=(string)$profile['profile_agent_greeting'];$answerMode='configured_greeting';
    }else{
        // Approved knowledge constrains facts, not the ability to converse.
        // Reuse the owner's stateless HomeServer route and Cloud quota engine.
        $homeAnswer=function_exists('homeserver_profile_v235_answer')
            ?homeserver_profile_v235_answer($owner,$query,$history,$context):null;
        $profileHome=function_exists('homeserver_profile_v235_last')?homeserver_profile_v235_last():$profileHome;
        if(is_array($homeAnswer)&&trim((string)($homeAnswer['answer']??''))!==''){
            $answer=(string)$homeAnswer['answer'];$answerMode='homeserver_model';
        }else{
            if(!vp3_profile_agent_public_sources_current_v1($pdo,$ctx,$context,$historySources))vp3_profile_agent_public_unavailable_v1();
            vp3_profile_agent_public_live_v1($pdo,$ctx);
            $control=[
                'surface'=>'profile_agent','profile_owner_user_id'=>$owner,
                'profile_agent_id'=>$agentId,'profile_agent_name'=>(string)$agent['display_name'],
                'profile_owner_name'=>(string)($profile['display_name']??$ownerUser['display_name']??''),
                'profile_instructions'=>(string)($profile['profile_agent_instructions']??''),
            ];
            $generated=ai_generate_chat_response($query,$history,$context,$ownerUser,'profile_agent',$control);
            $answer=!empty($generated['ok'])?trim((string)($generated['answer']??'')):'';
            if($answer===''){
                $needsOwner=true;
                $answerMode=!empty($generated['quota_exhausted'])?'quota_handoff':'owner_handoff';
                $answer=($answerMode==='quota_handoff'?'This Profile Agent has reached its AI usage allowance. ':'I could not complete that reply right now. ')
                    .'I’ve asked '.(string)($profile['display_name']??'the profile owner').' for input rather than guessing.';
            }
        }
    }

    $sources=[];
    foreach($context as $c){
        if(!in_array((string)$c['source'],['profile:identity','profile:rules'],true)){
            $sources[]=['source'=>(string)$c['source'],'title'=>(string)$c['title']];
        }
    }

    $pdo->beginTransaction();
    try{
        vp3_profile_agent_public_live_v1($pdo,$ctx,true);
        $current=vp3_profile_agent_public_bound_conversation_v1($pdo,$ctx,$cid,true);
        if(!vp3_profile_agent_public_sources_current_v1($pdo,$ctx,$context,$historySources))vp3_profile_agent_public_unavailable_v1();
        vp3_profile_agent_public_live_v1($pdo,$ctx,true);
        $current=vp3_profile_agent_public_bound_conversation_v1($pdo,$ctx,$cid,true);
        if(!vp3_profile_agent_agent_may_reply_v390($current)){
            $pdo->commit();
            return [
                'conversation_id'=>$cid,'answer'=>'',
                'awaiting_owner'=>vp3_profile_agent_status_v390($current)==='owner_joined',
                'conversation'=>vp3_profile_agent_public_state_v390($current),
                'agent'=>['name'=>(string)$agent['display_name'],'system_name'=>system_agent_name()],
            ];
        }
        if($needsOwner)profile_agent_needs_owner($pdo,$profile,$agent,$session,$conversation,$query);
        $messageContext=['sources'=>$sources,'source_dependencies'=>$sourceDependencies,'answer_mode'=>$answerMode];
        if(!empty($profileHome['attempted'])){
            $messageContext['homeserver_compute']=[
                'success'=>!empty($profileHome['success']),
                'failure_class'=>mb_strimwidth(trim((string)($profileHome['failure_class']??'none')),0,80,''),
                'provider'=>mb_strimwidth(trim((string)($profileHome['provider']??'')),0,80,''),
                'model'=>mb_strimwidth(trim((string)($profileHome['model']??'')),0,160,''),
                'execution'=>is_array($profileHome['execution']??null)?$profileHome['execution']:null,
            ];
        }
        $pdo->prepare("INSERT INTO profile_agent_messages (conversation_id,sender_type,sender_user_id,message,context_json) VALUES (?,'agent',NULL,?,?)")
            ->execute([$cid,$answer,json_encode($messageContext,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)]);
        $pdo->prepare('UPDATE profile_agent_conversations SET last_message_at=NOW(),updated_at=NOW() WHERE id=? AND owner_user_id=? AND profile_agent_id=? AND profile_session_id=?')
            ->execute([$cid,$owner,$agentId,$sessionId]);
        if(!vp3_profile_agent_public_sources_current_v1($pdo,$ctx,$context,$historySources))vp3_profile_agent_public_unavailable_v1();
        vp3_profile_agent_public_live_v1($pdo,$ctx,true);
        $pdo->commit();
    }catch(Throwable $e){
        if($pdo->inTransaction())$pdo->rollBack();
        throw $e;
    }

    return [
        'conversation_id'=>$cid,'answer'=>$answer,'sources'=>$sources,'answer_mode'=>$answerMode,
        'conversation'=>vp3_profile_agent_public_state_v390($current),
        'agent'=>['name'=>(string)$agent['display_name'],'system_name'=>system_agent_name()],
    ];
}

function vp3_profile_agent_public_poll_service_v110(PDO $pdo,array $ctx,int $conversationId,int $afterId=0): array
{
    if($conversationId<1){
        throw new VP3ProfileAgentPublicException('VALIDATION_FAILED','conversation_id is required.',422);
    }
    $ctx=vp3_profile_agent_public_live_v1($pdo,$ctx);
    $conversation=vp3_profile_agent_public_bound_conversation_v1($pdo,$ctx,$conversationId);
    $s=$pdo->prepare("SELECT id,sender_type,message,created_at FROM profile_agent_messages WHERE conversation_id=? AND id>? AND sender_type IN ('visitor','agent','owner') ORDER BY id ASC LIMIT 50");
    $s->execute([$conversationId,max(0,$afterId)]);
    $messages=$s->fetchAll()?:[];
    $conversation=vp3_profile_agent_public_bound_conversation_v1($pdo,$ctx,$conversationId);
    vp3_profile_agent_public_live_v1($pdo,$ctx);
    return ['messages'=>$messages,'conversation'=>vp3_profile_agent_public_state_v390($conversation)];
}

function vp3_profile_agent_public_request_owner_v110(PDO $pdo,array $ctx,int $conversationId,string $reason=''): array
{
    if($conversationId<1){
        throw new VP3ProfileAgentPublicException('VALIDATION_FAILED','conversation_id is required.',422);
    }
    $reason=trim($reason);
    if(mb_strlen($reason)>1000){
        throw new VP3ProfileAgentPublicException('VALIDATION_FAILED','Owner assistance reason is too long.',422);
    }
    $question=$reason!==''?$reason:'Visitor requested owner assistance.';
    $pdo->beginTransaction();
    try{
        $ctx=vp3_profile_agent_public_live_v1($pdo,$ctx,true);
        $conversation=vp3_profile_agent_public_bound_conversation_v1($pdo,$ctx,$conversationId,true);
        profile_agent_needs_owner($pdo,$ctx['profile'],$ctx['agent'],vp3_profile_agent_public_event_session_v1($ctx),$conversation,$question);
        vp3_profile_agent_public_live_v1($pdo,$ctx,true);$pdo->commit();
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
    return [
        'requested'=>true,
        'conversation'=>vp3_profile_agent_public_state_v390($conversation),
        'agent'=>['name'=>(string)$ctx['agent']['display_name'],'system_name'=>system_agent_name()],
    ];
}
