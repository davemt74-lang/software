<?php
declare(strict_types=1);
require_once __DIR__.'/studio-participants.php';

/** Browser reports are conversational context, never identity or action authority. */
function vp3_eyes_event(PDO $pdo,array $user,array $input): array
{
    $allowed=['event_id','event_type','track_id','session_id','participant_id','display_name','identity_source','authentication_authority'];
    if(array_diff(array_keys($input),$allowed))throw new RuntimeException('Unsupported Agent Eyes event fields.');
    $type=(string)($input['event_type']??'');
    if(!in_array($type,['arrival','departure','identity_changed','greeting','voice_turn'],true))throw new RuntimeException('Unsupported Agent Eyes event.');
    foreach(['event_id','track_id','session_id'] as $key){
        if(!is_string($input[$key]??null)||!preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/Di',$input[$key]))throw new RuntimeException('Invalid Agent Eyes session reference.');
    }
    if(($input['authentication_authority']??false)!==false)throw new RuntimeException('Agent Eyes cannot authenticate participants.');
    $id=filter_var($input['participant_id']??0,FILTER_VALIDATE_INT);
    if(($input['participant_id']??'')==='')$id=0;
    if($id===false||$id<0)throw new RuntimeException('Invalid participant reference.');
    $name='Unidentified participant';$source='unidentified';
    if($id>0){
        $profile=studio_participants_profile($pdo,$user,$id);
        $introduced=($input['identity_source']??'')==='self_introduction';
        if(empty($profile['is_active'])||(!$introduced&&empty($profile['recognition_consent'])))$id=0;
        else{$name=(string)$profile['display_name'];$source=$introduced?'browser_reported_self_introduction':(($input['identity_source']??'')==='local_voice_similarity'?'browser_reported_local_voice_similarity':'browser_reported_visual_similarity');}
    }
    $labels=['arrival'=>'arrived','departure'=>'left view','identity_changed'=>'participant attribution changed','greeting'=>'received an automatic greeting','voice_turn'=>'spoke in Agent chat'];
    $summary=$name.' · '.$labels[$type].'. '.($id>0?($source==='browser_reported_self_introduction'?'Self-introduced name for this camera visit; ':($source==='browser_reported_local_voice_similarity'?'Provisional local voice match; ':'Provisional local visual match; ')):'').'Browser-reported context; no authentication authority.';
    return ['event_id'=>strtolower($input['event_id']),'event_type'=>$type,'session_id'=>strtolower($input['session_id']),'track_id'=>strtolower($input['track_id']),
        'participant_id'=>$id,'display_name'=>$name,'identity_source'=>$source,'authentication_authority'=>false,'summary'=>$summary];
}

function vp3_eyes_record(PDO $pdo,array $user,array $input): array
{
    $event=vp3_eyes_event($pdo,$user,$input);
    $result=agent_event_ingest_v1920($pdo,(int)$user['id'],'agent_eyes','agent_eyes.'.$event['event_type'],$event,[
        'external_event_id'=>$event['session_id'].':'.$event['event_id'],
        'correlation_id'=>$event['session_id'],'verification_status'=>'trusted',
    ]);
    // Trusted means the signed-in reporting session, not a verified physical identity.
    $row=(array)($result['event']??[]);$id=(int)($row['id']??0);
    if(!empty($result['duplicate'])&&is_array($row['payload']??null))$event=$row['payload'];
    if($id<1)throw new RuntimeException('Agent Eyes history could not be saved.');
    $pdo->prepare("UPDATE agent_event_inbox SET processing_status='processed',processed_at=UTC_TIMESTAMP() WHERE id=? AND owner_user_id=?")->execute([$id,(int)$user['id']]);
    if(function_exists('agent_brain_v122_upsert_system_memory')){
        agent_brain_v122_upsert_system_memory($user,'agent_eyes','eyes-event:'.(string)$row['event_uuid'],$event['summary'],[
            'event_id'=>$id,'source'=>'agent_eyes','session_id'=>$event['session_id'],'track_id'=>$event['track_id'],
            'participant_id'=>$event['participant_id'],'identity_source'=>$event['identity_source'],
            'authentication_authority'=>false,'occurred_at'=>(string)($row['occurred_at']??$row['received_at']??''),
        ],0.5);
    }
    return ['event'=>$event,'stored_id'=>$id,'duplicate'=>!empty($result['duplicate'])];
}

function vp3_eyes_recent(PDO $pdo,array $user,int $limit=30): array
{
    if(!agent_event_schema_ready_v1920($pdo))return [];
    $limit=max(1,min(100,$limit));
    $q=$pdo->prepare("SELECT * FROM agent_event_inbox WHERE owner_user_id=? AND source='agent_eyes' ORDER BY id DESC LIMIT ".$limit);
    $q->execute([(int)($user['id']??0)]);
    return array_map('agent_event_public_v1920',$q->fetchAll()?:[]);
}
