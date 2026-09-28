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

function vp3_profile_agent_public_context_v110(PDO $pdo,array $profile,?array $visitor): array
{
    $owner=(int)($profile['user_id']??0);
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
    return [
        'profile'=>$profile,
        'owner_user_id'=>$owner,
        'owner_user'=>$ownerUser,
        'visitor'=>$visitor,
        'agent'=>$agent,
        'agent_id'=>(int)$agent['id'],
        'session'=>$session,
        'session_id'=>(int)$session['id'],
    ];
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
    $messages=[];$conversationState=null;
    if($conversationId>0){
        $conversation=vp3_profile_agent_public_conversation_v390(
            $pdo,$conversationId,(int)$ctx['owner_user_id'],(int)$ctx['agent_id'],(int)$ctx['session_id']
        );
        if(!$conversation){
            throw new VP3ProfileAgentPublicException('CONVERSATION_NOT_FOUND','Conversation not found for this Profile Agent.',404);
        }
        $messages=profile_agent_messages($pdo,$conversationId);
        $conversationState=vp3_profile_agent_public_state_v390($conversation);
    }
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
    $profile=$ctx['profile'];$owner=(int)$ctx['owner_user_id'];$ownerUser=$ctx['owner_user'];
    $visitor=$ctx['visitor'];$agent=$ctx['agent'];$agentId=(int)$ctx['agent_id'];
    $session=$ctx['session'];$sessionId=(int)$ctx['session_id'];$cid=max(0,$conversationId);

    if($cid>0&&!vp3_profile_agent_public_conversation_v390($pdo,$cid,$owner,$agentId,$sessionId)){
        throw new VP3ProfileAgentPublicException('CONVERSATION_NOT_FOUND','Conversation not found for this Profile Agent.',404);
    }
    if($cid<1){
        $conversation=profile_agent_conversation_create($pdo,$profile,$agent,$session);
        $cid=(int)$conversation['id'];
    }

    $pdo->beginTransaction();
    try{
        $conversation=vp3_profile_agent_public_conversation_v390($pdo,$cid,$owner,$agentId,$sessionId,true);
        if(!$conversation)throw new RuntimeException('Conversation not found for this Profile Agent.');
        $conversation=vp3_profile_agent_prepare_visitor_turn_v390($pdo,$conversation);
        profile_agent_rate_check($pdo,$cid);
        $pdo->prepare("INSERT INTO profile_agent_messages (conversation_id,sender_type,sender_user_id,message) VALUES (?,'visitor',?,?)")
            ->execute([$cid,(int)($visitor['id']??0)?:null,$query]);
        $pdo->prepare('UPDATE profile_visit_sessions SET last_message_at=NOW(),last_seen_at=NOW() WHERE id=? AND owner_user_id=?')
            ->execute([$sessionId,$owner]);
        $pdo->prepare('UPDATE profile_agent_conversations SET last_summary=?,last_message_at=NOW(),updated_at=NOW() WHERE id=? AND owner_user_id=? AND profile_agent_id=? AND profile_session_id=?')
            ->execute([mb_strimwidth($query,0,900,'…'),$cid,$owner,$agentId,$sessionId]);
        $pdo->commit();
    }catch(Throwable $e){
        if($pdo->inTransaction())$pdo->rollBack();
        throw $e;
    }

    if(!vp3_profile_agent_agent_may_reply_v390($conversation)){
        return [
            'conversation_id'=>$cid,'answer'=>'','awaiting_owner'=>true,
            'conversation'=>vp3_profile_agent_public_state_v390($conversation),
            'agent'=>['name'=>(string)$agent['display_name'],'system_name'=>system_agent_name()],
        ];
    }

    $history=[];
    foreach(profile_agent_messages($pdo,$cid,14) as $m){
        if($m['sender_type']==='visitor')$history[]=['role'=>'user','message'=>(string)$m['message']];
        elseif(in_array($m['sender_type'],['agent','owner'],true))$history[]=['role'=>'assistant','message'=>(string)$m['message']];
    }
    $context=profile_agent_context($pdo,$profile,$agent,$visitor,$query);
    if(function_exists('profile_agent_transcript_brain_context_v255')){
        foreach(profile_agent_transcript_brain_context_v255($pdo,$ownerUser,$agent,$visitor,$query,$cid) as $item)$context[]=$item;
    }
    if(count($context)>24)$context=array_slice($context,0,24);

    $substantive=array_values(array_filter(
        $context,
        static fn(array $c):bool=>!in_array((string)$c['source'],['profile:identity','profile:rules'],true)
    ));
    $greeting=(bool)preg_match('/^(?:hi|hello|hey|good\s+(?:morning|afternoon|evening))[!.\s]*$/i',$query);
    $profileHome=['attempted'=>false,'success'=>false,'execution'=>null,'failure_class'=>'none'];

    if(!$substantive&&!$greeting){
        profile_agent_needs_owner($pdo,$profile,$agent,$session,$conversation,$query);
        $answer='I don’t have approved information to answer that accurately yet. I’ve asked '.(string)$profile['display_name'].' for input rather than guessing.';
    }elseif($greeting){
        $answer=trim((string)($profile['profile_agent_greeting']??''))
            ?: 'Hi — I’m '.(string)$agent['display_name'].', '.(string)$profile['display_name'].'’s AI representative. What would you like to know?';
    }else{
        $homeAnswer=function_exists('homeserver_profile_v235_answer')
            ?homeserver_profile_v235_answer($owner,$query,$history,$context)
            :null;
        $profileHome=function_exists('homeserver_profile_v235_last')?homeserver_profile_v235_last():$profileHome;
        if(is_array($homeAnswer)&&trim((string)($homeAnswer['answer']??''))!==''){
            $answer=(string)$homeAnswer['answer'];
        }else{
            $answer=chat_remote_answer($query,$history,$context,$ownerUser);
            if($answer===null)$answer=chat_local_answer($query,$context);
        }
        if(trim((string)$answer)===''){
            profile_agent_needs_owner($pdo,$profile,$agent,$session,$conversation,$query);
            $answer='I don’t have enough approved information to answer that accurately.';
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
        $current=vp3_profile_agent_public_conversation_v390($pdo,$cid,$owner,$agentId,$sessionId,true);
        if(!$current)throw new RuntimeException('Conversation not found for this Profile Agent.');
        if(!vp3_profile_agent_agent_may_reply_v390($current)){
            $pdo->commit();
            return [
                'conversation_id'=>$cid,'answer'=>'',
                'awaiting_owner'=>vp3_profile_agent_status_v390($current)==='owner_joined',
                'conversation'=>vp3_profile_agent_public_state_v390($current),
                'agent'=>['name'=>(string)$agent['display_name'],'system_name'=>system_agent_name()],
            ];
        }
        $messageContext=['sources'=>$sources];
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
        $pdo->commit();
    }catch(Throwable $e){
        if($pdo->inTransaction())$pdo->rollBack();
        throw $e;
    }

    return [
        'conversation_id'=>$cid,'answer'=>$answer,'sources'=>$sources,
        'conversation'=>vp3_profile_agent_public_state_v390($current),
        'agent'=>['name'=>(string)$agent['display_name'],'system_name'=>system_agent_name()],
    ];
}

function vp3_profile_agent_public_poll_service_v110(PDO $pdo,array $ctx,int $conversationId,int $afterId=0): array
{
    if($conversationId<1){
        throw new VP3ProfileAgentPublicException('VALIDATION_FAILED','conversation_id is required.',422);
    }
    $conversation=vp3_profile_agent_public_conversation_v390(
        $pdo,$conversationId,(int)$ctx['owner_user_id'],(int)$ctx['agent_id'],(int)$ctx['session_id']
    );
    if(!$conversation){
        throw new VP3ProfileAgentPublicException('CONVERSATION_NOT_FOUND','Conversation not found for this Profile Agent.',404);
    }
    $s=$pdo->prepare('SELECT id,sender_type,message,created_at FROM profile_agent_messages WHERE conversation_id=? AND id>? ORDER BY id ASC LIMIT 50');
    $s->execute([$conversationId,max(0,$afterId)]);
    return ['messages'=>$s->fetchAll()?:[],'conversation'=>vp3_profile_agent_public_state_v390($conversation)];
}

function vp3_profile_agent_public_request_owner_v110(PDO $pdo,array $ctx,int $conversationId,string $reason=''): array
{
    if($conversationId<1){
        throw new VP3ProfileAgentPublicException('VALIDATION_FAILED','conversation_id is required.',422);
    }
    $conversation=vp3_profile_agent_public_conversation_v390(
        $pdo,$conversationId,(int)$ctx['owner_user_id'],(int)$ctx['agent_id'],(int)$ctx['session_id']
    );
    if(!$conversation){
        throw new VP3ProfileAgentPublicException('CONVERSATION_NOT_FOUND','Conversation not found for this Profile Agent.',404);
    }
    $reason=trim($reason);
    if(mb_strlen($reason)>1000){
        throw new VP3ProfileAgentPublicException('VALIDATION_FAILED','Owner assistance reason is too long.',422);
    }
    $question=$reason!==''?$reason:'Visitor requested owner assistance.';
    profile_agent_needs_owner($pdo,$ctx['profile'],$ctx['agent'],$ctx['session'],$conversation,$question);
    return [
        'requested'=>true,
        'conversation'=>vp3_profile_agent_public_state_v390($conversation),
        'agent'=>['name'=>(string)$ctx['agent']['display_name'],'system_name'=>system_agent_name()],
    ];
}
