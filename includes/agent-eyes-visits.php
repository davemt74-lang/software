<?php
declare(strict_types=1);

const VP3_EYES_VISIT_FRESH_SECONDS=120;
const VP3_EYES_VISIT_EVENT_SOURCE='agent_eyes_visit';
const VP3_EYES_VISIT_EVENT_TYPE='agent_eyes.visit';

function vp3_eyes_uuid(mixed $value): bool
{
    return is_string($value)&&preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/Di',$value)===1;
}

function vp3_eyes_observation_time(mixed $value): ?DateTimeImmutable
{
    if(!is_string($value)||!preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{3})?Z$/D',$value))return null;
    $format='Y-m-d\TH:i:s.v\Z';
    $value=strlen($value)===20?substr($value,0,-1).'.000Z':$value;
    $date=DateTimeImmutable::createFromFormat('!'.$format,$value,new DateTimeZone('UTC'));
    return $date&&$date->format($format)===$value?$date:null;
}

function vp3_eyes_observation_fresh(string $value,int $now): bool
{
    $observedAt=vp3_eyes_observation_time($value)?->getTimestamp()??0;
    return $observedAt>=$now-VP3_EYES_VISIT_FRESH_SECONDS&&$observedAt<=$now+5;
}

function vp3_eyes_visit_observation(array $event,int $now): bool
{
    return isset($event['conversation_id'],$event['capture_id'],$event['observed_at'])
        &&in_array($event['event_type'],['arrival','identity_changed','departure'],true)
        &&vp3_eyes_observation_fresh($event['observed_at'],$now);
}

function vp3_eyes_visit_track_key(array $event): string
{
    return implode(':',[$event['conversation_id'],$event['agent_namespace'],$event['session_id'],$event['capture_id'],$event['track_id']]);
}

function vp3_eyes_visit_participant_key(array $event,int $participantId): string
{
    return implode(':',[$event['conversation_id'],$event['agent_namespace'],$participantId]);
}

function vp3_eyes_visit_payload(array $row): array
{
    $visit=json_decode((string)($row['payload_json']??''),true);
    return is_array($visit)?$visit:[];
}

function vp3_eyes_visit_receipt(string $uuid,array $visit): array
{
    return ['ref'=>'eyes-visit:'.$uuid]+array_intersect_key($visit,array_flip([
        'state','status','conversation_id','agent_namespace','participant_id','track_id','capture_id','occurred_at','greetings_allowed',
    ]));
}

function vp3_eyes_visit_state(PDO $pdo,int $owner,string $correlationId,string $consentRevision,string $arrivalAt): string
{
    $previous=$pdo->prepare("SELECT payload_json FROM agent_event_inbox WHERE owner_user_id=? AND source=? AND event_type=? AND correlation_id=? AND processing_status='processed' ORDER BY id DESC LIMIT 32");
    $previous->execute([$owner,VP3_EYES_VISIT_EVENT_SOURCE,VP3_EYES_VISIT_EVENT_TYPE,$correlationId]);
    $prior=null;
    $arrivalEpoch=vp3_eyes_observation_time($arrivalAt)->getTimestamp();
    while($row=$previous->fetch(PDO::FETCH_ASSOC)){
        $candidate=vp3_eyes_visit_payload($row);
        if(($candidate['consent_revision']??null)!==$consentRevision)continue;
        $prior??=$candidate;
        if($candidate['status']==='active'&&vp3_eyes_observation_fresh($candidate['last_observed_at'],$arrivalEpoch))return 'continuing';
    }
    if(!$prior)return 'first';
    return $prior['status']==='departed'&&$prior['last_observed_at']<$arrivalAt?'return':'continuing';
}

/** Called with the raw event in the same transaction. This stores observation state only. */
function vp3_eyes_advance_visit(PDO $pdo,array $user,array $event,bool $duplicate): ?array
{
    $owner=(int)$user['id'];
    $type=$event['event_type'];
    $observedAt=$event['observed_at'];
    $externalId=vp3_eyes_visit_track_key($event);
    $hash=agent_event_dedupe_hash_v1920($owner,VP3_EYES_VISIT_EVENT_SOURCE,VP3_EYES_VISIT_EVENT_TYPE,$externalId,[]);
    $find=$pdo->prepare("SELECT id,event_uuid,payload_json,correlation_id FROM agent_event_inbox WHERE owner_user_id=? AND dedupe_hash=? AND processing_status='processed'");
    $find->execute([$owner,$hash]);
    $row=$find->fetch(PDO::FETCH_ASSOC)?:[];
    $visit=vp3_eyes_visit_payload($row);
    if(!$visit&&($duplicate||$type!=='arrival'))return null;
    if($visit&&($duplicate||$type==='arrival'||$observedAt<$visit['last_observed_at']||in_array($visit['status'],['ended','departed'],true))){
        return vp3_eyes_visit_receipt($row['event_uuid'],$visit);
    }

    $profile=[];
    if(!empty($event['visual_match_reported'])){
        $current=$pdo->prepare('SELECT * FROM studio_participants WHERE id=? AND owner_user_id=?'.($pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?' FOR UPDATE':''));
        $current->execute([$event['participant_id'],$owner]);
        $profile=$current->fetch(PDO::FETCH_ASSOC)?:[];
        if(empty($profile['is_active'])||empty($profile['recognition_consent']))$profile=[];
    }
    $matchId=(int)($profile['id']??0);
    $consentRevision=(string)($profile['consent_updated_at']??'');
    $correlationId=(string)($row['correlation_id']??'');
    if(!$visit){
        $visit=array_intersect_key($event,array_flip(['conversation_id','agent_namespace','track_id','capture_id']));
        $visit+=['state'=>'unknown','status'=>'active','participant_id'=>0,'arrived_at'=>$observedAt,
            'occurred_at'=>$observedAt,'greetings_allowed'=>false,'consent_revision'=>''];
    }
    $visit['last_observed_at']=$observedAt;
    if($type==='departure'){
        $confirmed=empty($event['capture_ended'])&&$visit['status']==='active'&&$matchId>0
            &&$matchId===(int)$visit['participant_id']&&$consentRevision===$visit['consent_revision'];
        $visit['status']=$confirmed?'departed':'ended';
    }elseif((int)$visit['participant_id']>0){
        // A lost or conflicting attribution invalidates this track's proof. A
        // matching re-observation can resume the same visit without rearming it.
        $visit['status']=$matchId===(int)$visit['participant_id']&&$consentRevision===$visit['consent_revision']?'active':'uncertain';
    }elseif($matchId>0&&vp3_eyes_observation_fresh($visit['arrived_at'],vp3_eyes_observation_time($observedAt)->getTimestamp())){
        $correlationId=vp3_eyes_visit_participant_key($event,$matchId);
        $visit['state']=vp3_eyes_visit_state($pdo,$owner,$correlationId,$consentRevision,$visit['arrived_at']);
        $visit['participant_id']=$matchId;
        $visit['consent_revision']=$consentRevision;
        $visit['occurred_at']=$observedAt;
        $visit['greetings_allowed']=!empty($event['greetings_allowed']);
    }

    if(!$row){
        $created=agent_event_ingest_v1920($pdo,$owner,VP3_EYES_VISIT_EVENT_SOURCE,VP3_EYES_VISIT_EVENT_TYPE,$visit,[
            'external_event_id'=>$externalId,'correlation_id'=>$correlationId,'occurred_at'=>$visit['arrived_at'],
        ]);
        $row=(array)$created['event'];
        if(empty($row['id']))throw new RuntimeException('Agent Eyes visit could not be saved.');
    }
    $pdo->prepare("UPDATE agent_event_inbox SET payload_json=?,correlation_id=?,processing_status='processed',processed_at=UTC_TIMESTAMP() WHERE id=? AND owner_user_id=?")
        ->execute([agent_event_json_v1920($visit),$correlationId,$row['id'],$owner]);
    return vp3_eyes_visit_receipt($row['event_uuid'],$visit);
}

/** Resolve fresh, advisory evidence for the exact saved Chat and active Agent. */
function vp3_eyes_visit(PDO $pdo,array $user,string $ref,int $conversationId,string $agentNamespace,?int $now=null): ?array
{
    $uuid=substr($ref,strlen('eyes-visit:'));
    if(!str_starts_with($ref,'eyes-visit:')||!vp3_eyes_uuid($uuid)||strtolower($uuid)!==$uuid
        ||$conversationId<1||(int)($user['id']??0)<1)return null;
    $find=$pdo->prepare("SELECT payload_json FROM agent_event_inbox WHERE event_uuid=? AND owner_user_id=? AND source=? AND event_type=? AND processing_status='processed'");
    $find->execute([$uuid,(int)$user['id'],VP3_EYES_VISIT_EVENT_SOURCE,VP3_EYES_VISIT_EVENT_TYPE]);
    $visit=vp3_eyes_visit_payload($find->fetch(PDO::FETCH_ASSOC)?:[]);
    if(($visit['status']??'')!=='active'||($visit['conversation_id']??0)!==$conversationId||($visit['agent_namespace']??'')!==$agentNamespace
        ||(int)($visit['participant_id']??0)<1||!vp3_eyes_observation_fresh($visit['occurred_at']??'',$now??time()))return null;
    try{
        $scope=vp3_eyes_conversation_context($pdo,$user,$conversationId);
        $profile=studio_participants_profile($pdo,$user,(int)$visit['participant_id']);
    }catch(RuntimeException $e){return null;}
    if($scope['agent_namespace']!==$agentNamespace||empty($profile['is_active'])||empty($profile['recognition_consent'])
        ||(string)($profile['consent_updated_at']??'')!==$visit['consent_revision'])return null;
    return vp3_eyes_visit_receipt($uuid,$visit)+['display_name'=>(string)$profile['display_name'],'authentication_authority'=>false];
}
