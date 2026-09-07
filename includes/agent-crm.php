<?php
declare(strict_types=1);

/**
 * User-facing Agent CRM projection.
 *
 * vp3_agent_contacts remains the canonical Agent Radar identity record. This
 * adapter enriches those owner-scoped contacts for My Contacts without copying
 * them into the human profile visitor CRM or the admin sales CRM.
 */
function vp3_agent_crm_metadata(string $json): array
{
    $decoded=json_decode($json,true);
    return is_array($decoded)?$decoded:[];
}

function vp3_agent_crm_watch_from_metadata(array $metadata): bool
{
    $watch=$metadata['crm_watch']??false;
    if(is_array($watch))return !empty($watch['enabled']);
    return (bool)$watch;
}

function vp3_agent_crm_contact(PDO $pdo,int $ownerUserId,int $contactId): ?array
{
    if($ownerUserId<1||$contactId<1)return null;
    $stmt=$pdo->prepare('SELECT * FROM vp3_agent_contacts WHERE id=? AND owner_user_id=? LIMIT 1');
    $stmt->execute([$contactId,$ownerUserId]);
    return $stmt->fetch()?:null;
}

function vp3_agent_crm_watch_enabled(PDO $pdo,int $ownerUserId,int $contactId): bool
{
    $contact=vp3_agent_crm_contact($pdo,$ownerUserId,$contactId);
    return $contact?vp3_agent_crm_watch_from_metadata(vp3_agent_crm_metadata((string)($contact['metadata_json']??''))):false;
}

function vp3_agent_crm_primary_property(PDO $pdo,int $ownerUserId): ?array
{
    if($ownerUserId<1)return null;
    $stmt=$pdo->prepare("SELECT id,owner_user_id,property_type,label,domain FROM vp3_radar_properties WHERE owner_user_id=? ORDER BY property_type='native' DESC,is_active DESC,id LIMIT 1");
    $stmt->execute([$ownerUserId]);
    return $stmt->fetch()?:null;
}

function vp3_agent_crm_audit_event(PDO $pdo,int $ownerUserId,int $contactId,string $eventType,string $summary,array $details=[]): int
{
    $contact=vp3_agent_crm_contact($pdo,$ownerUserId,$contactId);$property=vp3_agent_crm_primary_property($pdo,$ownerUserId);
    if(!$contact||!$property)return 0;
    $stmt=$pdo->prepare("INSERT INTO vp3_radar_events
      (owner_user_id,property_id,session_id,agent_contact_id,event_type,severity,path,method,status_code,significance_score,risk_score,summary,details_json,occurred_at)
      VALUES (?,?,NULL,?,?,'low','/contacts.php','SYSTEM',NULL,65,?,?,?,NOW())");
    $stmt->execute([
        $ownerUserId,(int)$property['id'],$contactId,mb_strimwidth($eventType,0,80,''),
        max(0,min(100,(int)($contact['risk_score']??0))),mb_strimwidth($summary,0,500,'…'),
        json_encode($details,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE),
    ]);
    return (int)$pdo->lastInsertId();
}

function vp3_agent_crm_set_watch(PDO $pdo,array $user,int $contactId,bool $enabled): array
{
    $owner=(int)($user['id']??0);$contact=vp3_agent_crm_contact($pdo,$owner,$contactId);
    if(!$contact)throw new RuntimeException('Agent contact not found.');
    $metadata=vp3_agent_crm_metadata((string)($contact['metadata_json']??''));
    $before=vp3_agent_crm_watch_from_metadata($metadata);
    $metadata['crm_watch']=['enabled'=>$enabled,'updated_at'=>gmdate('c')];
    $pdo->prepare('UPDATE vp3_agent_contacts SET metadata_json=?,updated_at=NOW() WHERE id=? AND owner_user_id=?')->execute([
        json_encode($metadata,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE),$contactId,$owner,
    ]);
    if($before!==$enabled){
        $name=(trim((string)$contact['operator_name'])!==''?trim((string)$contact['operator_name']).' · ':'').trim((string)$contact['display_name']);
        vp3_agent_crm_audit_event($pdo,$owner,$contactId,'agent_watch_changed',$name.' was '.($enabled?'added to':'removed from').' the Agent CRM watchlist.',['watch_enabled'=>$enabled]);
    }
    return ['contact_id'=>$contactId,'watch_enabled'=>$enabled,'display_name'=>(string)$contact['display_name']];
}

function vp3_agent_crm_watch_notify(PDO $pdo,array $property,array $contact,array $event): bool
{
    $owner=(int)($property['owner_user_id']??0);$contactId=(int)($contact['id']??0);
    if($owner<1||$contactId<1||!vp3_agent_crm_watch_enabled($pdo,$owner,$contactId))return false;
    $name=(trim((string)($contact['operator_name']??''))!==''?trim((string)$contact['operator_name']).' · ':'').(trim((string)($contact['display_name']??''))?:'Automated agent');
    $site=trim((string)($property['label']??''))?:trim((string)($property['domain']??''));if($site==='')$site='your VP3 profile';
    $path=trim((string)($event['path']??''));
    $body=$name.' returned to '.$site.($path!==''?' Latest path: '.$path.'.':'').' Risk '.(int)($event['risk_score']??0).'/100.';
    create_notification($owner,'radar_agent_watchlist','Agent watchlist · '.trim((string)($contact['display_name']??'Agent')),$body,url('/contacts.php#agent-contact-'.$contactId),'radar_event',(int)($event['id']??0));
    return true;
}

function vp3_agent_crm_contacts(PDO $pdo,array $user,int $limit=250): array
{
    $owner=(int)($user['id']??0);
    if($owner<1||!vp3_radar_schema_ready($pdo))return [];
    $limit=max(1,min(250,$limit));

    if(function_exists('vp3_agent_relationship_refresh_owner')){
        try{vp3_agent_relationship_refresh_owner($pdo,$user,$limit,false);}catch(Throwable $e){error_log('Agent CRM relationship refresh failed: '.$e->getMessage());}
    }

    $stmt=$pdo->prepare("SELECT c.*,r.slug AS registry_slug,r.purpose AS registry_purpose,r.verification_method
      FROM vp3_agent_contacts c
      LEFT JOIN vp3_agent_registry r ON r.id=c.agent_registry_id
      WHERE c.owner_user_id=?
      ORDER BY c.last_seen_at DESC,c.id DESC
      LIMIT {$limit}");
    $stmt->execute([$owner]);$rows=$stmt->fetchAll()?:[];
    if(!$rows)return [];

    $ids=array_map(static fn(array $row):int=>(int)$row['id'],$rows);
    $policyMap=vp3_radar_gateway_contact_policy_map($pdo,$owner,$ids);
    $requests=[];
    if(function_exists('vp3_agent_access_owner_list')){
        foreach(vp3_agent_access_owner_list($pdo,$owner,100) as $request){
            $requestContactId=(int)($request['agent_contact_id']??0);
            if($requestContactId>0&&!isset($requests[$requestContactId]))$requests[$requestContactId]=$request;
        }
    }

    $recent=[];$placeholders=implode(',',array_fill(0,count($ids),'?'));
    if($placeholders!==''){
        $eventSql="SELECT e.id,e.agent_contact_id,e.event_type,e.severity,e.path,e.summary,e.occurred_at,p.label AS property_label
          FROM vp3_radar_events e
          LEFT JOIN vp3_radar_properties p ON p.id=e.property_id
          WHERE e.owner_user_id=? AND e.agent_contact_id IN ({$placeholders})
          ORDER BY e.occurred_at DESC,e.id DESC LIMIT 1000";
        $event=$pdo->prepare($eventSql);$event->execute(array_merge([$owner],$ids));
        foreach($event->fetchAll()?:[] as $row){
            $eventContactId=(int)$row['agent_contact_id'];
            if(count($recent[$eventContactId]??[])<8)$recent[$eventContactId][]=$row;
        }
    }

    foreach($rows as &$row){
        $id=(int)$row['id'];
        $metadata=vp3_agent_crm_metadata((string)($row['metadata_json']??''));
        $intel=is_array($metadata['relationship_intelligence']??null)?$metadata['relationship_intelligence']:[];
        $policy=$policyMap[$id]??null;
        $request=$requests[$id]??null;
        $row['crm_contact_type']='agent';
        $row['watch_enabled']=vp3_agent_crm_watch_from_metadata($metadata);
        $row['gateway_policy']=$policy;
        $row['gateway_action']=$policy?(string)($policy['action']??'monitor'):'profile_default';
        $row['gateway_limit_30m']=$policy&&((string)($policy['action']??'')==='limit')?(int)($policy['metadata']['requests_per_30m']??VP3_RADAR_GATEWAY_DEFAULT_LIMIT_30M):null;
        $row['messaging_access_status']=$request?(string)($request['status']??''):'none';
        $row['messaging_request_id']=$request?(int)($request['id']??0):0;
        $row['messaging_purpose']=$request?trim((string)($request['purpose']??'')):'';
        $row['opportunity_score']=(int)($intel['opportunity_score']??0);
        $row['recommendation']=trim((string)($intel['recommendation']??''));
        $row['relationship_metrics']=$intel;
        $row['recent_activity']=$recent[$id]??[];
        unset($row['metadata_json']);
    }unset($row);
    return $rows;
}

function vp3_agent_crm_stats(array $contacts): array
{
    $stats=['total'=>count($contacts),'high_risk'=>0,'opportunities'=>0,'messaging'=>0,'watched'=>0,'referrals'=>0,'conversions'=>0];
    foreach($contacts as $contact){
        if((int)($contact['risk_score']??0)>=70)$stats['high_risk']++;
        if((int)($contact['opportunity_score']??0)>=VP3_AGENT_OPPORTUNITY_THRESHOLD&&(int)($contact['risk_score']??0)<40)$stats['opportunities']++;
        if(in_array((string)($contact['messaging_access_status']??''),['pending','approved_once','approved'],true))$stats['messaging']++;
        if(!empty($contact['watch_enabled']))$stats['watched']++;
        $stats['referrals']+=(int)($contact['referral_count']??0);
        $stats['conversions']+=(int)($contact['conversion_count']??0);
    }
    return $stats;
}
