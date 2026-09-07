<?php
declare(strict_types=1);

/**
 * User-facing Agent CRM projection.
 *
 * vp3_agent_contacts remains the canonical Agent Radar identity record. This
 * adapter enriches those owner-scoped contacts for My Contacts without copying
 * them into the human profile visitor CRM or the admin sales CRM.
 */
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
            $contactId=(int)($request['agent_contact_id']??0);
            if($contactId>0&&!isset($requests[$contactId]))$requests[$contactId]=$request;
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
            $contactId=(int)$row['agent_contact_id'];
            if(count($recent[$contactId]??[])<8)$recent[$contactId][]=$row;
        }
    }

    foreach($rows as &$row){
        $id=(int)$row['id'];
        $metadata=json_decode((string)($row['metadata_json']??''),true);if(!is_array($metadata))$metadata=[];
        $intel=is_array($metadata['relationship_intelligence']??null)?$metadata['relationship_intelligence']:[];
        $policy=$policyMap[$id]??null;
        $request=$requests[$id]??null;
        $row['crm_contact_type']='agent';
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
    $stats=['total'=>count($contacts),'high_risk'=>0,'opportunities'=>0,'messaging'=>0,'referrals'=>0,'conversions'=>0];
    foreach($contacts as $contact){
        if((int)($contact['risk_score']??0)>=70)$stats['high_risk']++;
        if((int)($contact['opportunity_score']??0)>=VP3_AGENT_OPPORTUNITY_THRESHOLD&&(int)($contact['risk_score']??0)<40)$stats['opportunities']++;
        if(in_array((string)($contact['messaging_access_status']??''),['pending','approved_once','approved'],true))$stats['messaging']++;
        $stats['referrals']+=(int)($contact['referral_count']??0);
        $stats['conversions']+=(int)($contact['conversion_count']??0);
    }
    return $stats;
}
