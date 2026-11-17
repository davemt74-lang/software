<?php
declare(strict_types=1);
require_once __DIR__.'/studio-participants.php';
require_once __DIR__.'/participant-updates-v1.php';
require_once __DIR__.'/agent-chat-boundary-v380.php';
require_once __DIR__.'/agent-memory-scope-v410.php';
require_once __DIR__.'/agent-eyes-visits.php';

function vp3_eyes_conversation_context(PDO $pdo,array $user,int $conversationId): array
{
    $conversation=$pdo->prepare('SELECT user_agent_id FROM chat_conversations WHERE id=? AND user_id=? LIMIT 1');
    $conversation->execute([$conversationId,(int)$user['id']]);
    $row=$conversation->fetch(PDO::FETCH_ASSOC);
    if(!$row)throw new RuntimeException('Agent Eyes conversation is unavailable.');
    $agent=vp3_agent_chat_resolve_agent_v380($pdo,$user,(int)($row['user_agent_id']??0));
    $agentId=vp3_agent_chat_agent_id_v380($agent);
    return ['conversation_id'=>$conversationId,'agent_namespace'=>$agentId>0?'agent:'.$agentId:'system'];
}

/** Browser reports are conversational context, never identity or action authority. */
function vp3_eyes_event(PDO $pdo,array $user,array $input): array
{
    $allowed=['event_id','event_type','track_id','session_id','participant_id','display_name','identity_source','authentication_authority','conversation_id',
        'capture_id','capture_ended','observed_at','greetings_allowed'];
    if(array_diff(array_keys($input),$allowed))throw new RuntimeException('Unsupported Agent Eyes event fields.');
    $type=(string)($input['event_type']??'');
    if(!in_array($type,['arrival','departure','identity_changed','greeting','voice_turn','attribution_corrected','participant_updated','recognition_forgotten'],true))throw new RuntimeException('Unsupported Agent Eyes event.');
    $references=['event_id','track_id','session_id'];
    if(array_key_exists('capture_id',$input))$references[]='capture_id';
    foreach($references as $key)if(!vp3_eyes_uuid($input[$key]??null))throw new RuntimeException('Invalid Agent Eyes session reference.');
    if(($input['authentication_authority']??false)!==false)throw new RuntimeException('Agent Eyes cannot authenticate participants.');
    $chatContext=[];
    if(array_key_exists('conversation_id',$input)){
        $conversationId=filter_var($input['conversation_id'],FILTER_VALIDATE_INT);
        if($conversationId===false||$conversationId<1)throw new RuntimeException('Invalid Agent Eyes conversation.');
        $chatContext=vp3_eyes_conversation_context($pdo,$user,$conversationId);
    }
    $observation=[];
    if(array_key_exists('observed_at',$input)){
        $observedAt=vp3_eyes_observation_time($input['observed_at']);
        if(!$observedAt)throw new RuntimeException('Invalid Agent Eyes observation time.');
        $observation['observed_at']=$observedAt->format('Y-m-d\TH:i:s.v\Z');
    }
    if(isset($input['capture_id']))$observation['capture_id']=strtolower($input['capture_id']);
    foreach(['capture_ended','greetings_allowed'] as $key)if(array_key_exists($key,$input)){
        if(!is_bool($input[$key]))throw new RuntimeException('Invalid Agent Eyes observation settings.');
        $observation[$key]=$input[$key];
    }
    $id=filter_var($input['participant_id']??0,FILTER_VALIDATE_INT);
    if(($input['participant_id']??'')==='')$id=0;
    if($id===false||$id<0)throw new RuntimeException('Invalid participant reference.');
    $name='Unidentified participant';$source='unidentified';
    if($id>0){
        $profile=studio_participants_profile($pdo,$user,$id);
        $manual=($input['identity_source']??'')==='manual_correction';
        $introduced=($input['identity_source']??'')==='self_introduction'||$manual;
        if(empty($profile['is_active'])||(!$introduced&&empty($profile['recognition_consent'])))$id=0;
        else{$name=(string)$profile['display_name'];$source=$manual?'owner_reported_correction':($introduced?'browser_reported_self_introduction':(($input['identity_source']??'')==='local_voice_similarity'?'browser_reported_local_voice_similarity':'browser_reported_visual_similarity'));}
    }
    $labels=['arrival'=>'arrived','departure'=>'left view','identity_changed'=>'participant attribution changed','greeting'=>'received an automatic greeting','voice_turn'=>'spoke in Agent chat','attribution_corrected'=>'had a conversational match dismissed','participant_updated'=>'participant details updated','recognition_forgotten'=>'local recognition samples removed'];
    $summary=$name.' · '.$labels[$type].'. '.($id>0?($source==='owner_reported_correction'?'Owner-reported participant correction; ':($source==='browser_reported_self_introduction'?'Self-introduced name for this camera visit; ':($source==='browser_reported_local_voice_similarity'?'Provisional local voice match; ':'Provisional local visual match; '))):'').'Browser-reported context; no authentication authority.';
    if(isset($observation['capture_id']))$observation['visual_match_reported']=$id>0&&($input['identity_source']??'')==='local_visual_similarity';
    return ['event_id'=>strtolower($input['event_id']),'event_type'=>$type,'session_id'=>strtolower($input['session_id']),'track_id'=>strtolower($input['track_id']),
        'participant_id'=>$id,'display_name'=>$name,'identity_source'=>$source,'authentication_authority'=>false,'summary'=>$summary]+$chatContext+$observation;
}

function vp3_eyes_record(PDO $pdo,array $user,array $input,?int $now=null): array
{
    $event=vp3_eyes_event($pdo,$user,$input);
    $now??=time();
    $visitEligible=vp3_eyes_visit_observation($event,$now);
    $ownTransaction=$visitEligible&&!$pdo->inTransaction();
    if($ownTransaction)$pdo->beginTransaction();
    try{
        if($visitEligible&&$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'){
            // Serialize overlapping capture transitions for this account.
            $lock=$pdo->prepare('SELECT id FROM users WHERE id=? FOR UPDATE');
            $lock->execute([(int)$user['id']]);
        }
        $result=agent_event_ingest_v1920($pdo,(int)$user['id'],'agent_eyes','agent_eyes.'.$event['event_type'],$event,[
            'external_event_id'=>$event['session_id'].':'.$event['event_id'],
            'correlation_id'=>$event['session_id'],'verification_status'=>'trusted',
            'occurred_at'=>$event['observed_at']??null,
        ]);
        // Trusted means the signed-in reporting session, not a verified physical identity.
        $row=(array)($result['event']??[]);$id=(int)($row['id']??0);
        $duplicate=!empty($result['duplicate']);
        if($duplicate&&is_array($row['payload']??null))$event=$row['payload'];
        if($id<1)throw new RuntimeException('Agent Eyes history could not be saved.');
        $pdo->prepare("UPDATE agent_event_inbox SET processing_status='processed',processed_at=UTC_TIMESTAMP() WHERE id=? AND owner_user_id=?")->execute([$id,(int)$user['id']]);
        $visit=$visitEligible&&vp3_eyes_visit_observation($event,$now)?vp3_eyes_advance_visit($pdo,$user,$event,$duplicate):null;
        if($ownTransaction)$pdo->commit();
    }catch(Throwable $e){
        if($ownTransaction&&$pdo->inTransaction())$pdo->rollBack();
        throw $e;
    }
    if(function_exists('agent_brain_v122_upsert_system_memory')){
        $previousScope=vp3_agent_memory_scope_current_context_v410();
        $agentId=preg_match('/^agent:([1-9][0-9]*)$/D',(string)($event['agent_namespace']??''),$match)?(int)$match[1]:0;
        vp3_agent_memory_scope_set_current_v410((int)$user['id'],$agentId);
        try{agent_brain_v122_upsert_system_memory($user,'agent_eyes','eyes-event:'.(string)$row['event_uuid'],$event['summary'],[
            'event_id'=>$id,'source'=>'agent_eyes','session_id'=>$event['session_id'],'track_id'=>$event['track_id'],
            'participant_id'=>$event['participant_id'],'identity_source'=>$event['identity_source'],
            'authentication_authority'=>false,'occurred_at'=>(string)($row['occurred_at']??$row['received_at']??''),
        ]+array_intersect_key($event,array_flip(['conversation_id','agent_namespace'])),0.5);}
        finally{vp3_agent_memory_scope_set_current_v410((int)($previousScope['user_id']??0),(int)($previousScope['user_agent_id']??0));}
    }
    if(!$duplicate&&(int)$event['participant_id']>0&&in_array($event['event_type'],['arrival','voice_turn','identity_changed'],true)
        &&(!isset($event['observed_at'])||vp3_eyes_observation_fresh($event['observed_at'],$now))){
        try{vp3_participant_observed_v1($pdo,$user,(int)$event['participant_id'],$event['event_type']==='voice_turn'?'heard':'seen');}catch(Throwable $e){error_log('Participant presence update unavailable.');}
    }
    return ['event'=>$event,'stored_id'=>$id,'duplicate'=>$duplicate,'visit'=>$visit];
}

function vp3_eyes_recent(PDO $pdo,array $user,int $limit=30): array
{
    if(!agent_event_schema_ready_v1920($pdo))return [];
    $limit=max(1,min(100,$limit));
    $q=$pdo->prepare("SELECT * FROM agent_event_inbox WHERE owner_user_id=? AND source='agent_eyes' ORDER BY id DESC LIMIT ".$limit);
    $q->execute([(int)($user['id']??0)]);
    return array_map('agent_event_public_v1920',$q->fetchAll()?:[]);
}
