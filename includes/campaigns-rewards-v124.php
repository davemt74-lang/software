<?php
declare(strict_types=1);

const VP3_CAMPAIGNS_REWARDS_V124='vp3-campaigns-rewards-v124-20260923';

function campaigns_rewards_journey_instance_v124(PDO $pdo,int $instanceId): ?array
{
    if($instanceId<1)return null;
    $q=$pdo->prepare("SELECT i.*,j.journey_key,j.name journey_name,c.merchant_id,c.public_id campaign_public_id,c.name campaign_name,c.environment,
      cc.name contact_name,cc.email contact_email
      FROM campaign_journey_instances i
      INNER JOIN campaign_journeys j ON j.id=i.journey_id
      INNER JOIN campaigns c ON c.id=i.campaign_id
      LEFT JOIN crm_contacts cc ON cc.id=i.contact_id
      WHERE i.id=? LIMIT 1");
    $q->execute([$instanceId]);$row=$q->fetch();if(!$row)return null;
    $row['metadata']=json_decode((string)($row['metadata_json']??''),true)?:[];return $row;
}

function campaigns_rewards_journey_instance_by_key_v124(PDO $pdo,string $instanceKey): ?array
{
    $q=$pdo->prepare("SELECT id FROM campaign_journey_instances WHERE instance_key=? LIMIT 1");$q->execute([$instanceKey]);$id=(int)$q->fetchColumn();
    return $id>0?campaigns_rewards_journey_instance_v124($pdo,$id):null;
}

function campaigns_rewards_journey_instance_start_v124(PDO $pdo,array $campaign,array $journey,array $version,int $contactId,string $instanceKey,string $trigger,string $triggerEventId,array $context=[]): int
{
    $meta=['context'=>campaigns_rewards_journey_context_v121($context),'journey_version_no'=>(int)$version['version_no']];
    $pdo->prepare("INSERT INTO campaign_journey_instances
      (public_id,campaign_id,journey_id,journey_version_id,contact_id,instance_key,trigger_event,trigger_event_id,status,metadata_json,started_at,last_activity_at)
      VALUES (?,?,?,?,?,?,?,?, 'active',?,UTC_TIMESTAMP(),UTC_TIMESTAMP())
      ON DUPLICATE KEY UPDATE last_activity_at=UTC_TIMESTAMP()")
      ->execute([campaigns_rewards_uuid_v100(),(int)$campaign['id'],(int)$journey['id'],(int)$version['id'],$contactId,$instanceKey,$trigger,$triggerEventId,campaigns_rewards_json_v100($meta)]);
    $instance=campaigns_rewards_journey_instance_by_key_v124($pdo,$instanceKey);
    return (int)($instance['id']??0);
}

function campaigns_rewards_journey_enqueue_v124(PDO $pdo,int $campaignId,int $contactId,string $trigger,array $context=[],string $triggerEventId=''): array
{
    return campaigns_rewards_journey_enqueue_v123($pdo,$campaignId,$contactId,$trigger,$context,$triggerEventId);
}

function campaigns_rewards_instance_touch_delivery_v124(PDO $pdo,array $delivery,?array $result=null): void
{
    $instanceId=max(0,(int)($delivery['metadata']['journey_instance_id']??0));
    if($instanceId<1&&($delivery['metadata']['journey_instance_key']??'')!==''){
        $instance=campaigns_rewards_journey_instance_by_key_v124($pdo,(string)$delivery['metadata']['journey_instance_key']);$instanceId=(int)($instance['id']??0);
    }
    if($instanceId<1)return;
    $status=(string)($delivery['status']??'');$node=(string)($delivery['metadata']['node_type']??$delivery['template']['node_type']??'message');
    $instanceStatus='active';$completed=null;
    if($status==='dead_letter')$instanceStatus='needs_attention';
    elseif($status==='suppressed'&&(!empty($delivery['metadata']['release_exit_reason'])||!empty($delivery['metadata']['instance_cancelled_at'])))$instanceStatus='cancelled';
    elseif(($status==='suppressed'&&!empty($delivery['metadata']['exit_reason']))||($node==='exit'&&in_array($status,['delivered','viewed','sent'],true))){$instanceStatus='completed';$completed=gmdate('Y-m-d H:i:s');}
    $pdo->prepare("UPDATE campaign_journey_instances
      SET status=IF(status IN ('paused','cancelled'),status,?),current_step_key=?,last_activity_at=UTC_TIMESTAMP(),completed_at=COALESCE(completed_at,?),updated_at=UTC_TIMESTAMP()
      WHERE id=?")->execute([$instanceStatus,(string)($delivery['metadata']['step_key']??''),$completed,$instanceId]);
}

function campaigns_rewards_dispatch_delivery_v124(PDO $pdo,int $deliveryId): array
{
    $delivery=campaigns_rewards_delivery_v120($pdo,$deliveryId)?:throw new RuntimeException('Campaign delivery not found.');
    if(($delivery['metadata']['runtime']??'')!=='v1.23')return campaigns_rewards_dispatch_delivery_v123($pdo,$deliveryId);
    $instance=null;$instanceId=max(0,(int)($delivery['metadata']['journey_instance_id']??0));
    if($instanceId>0)$instance=campaigns_rewards_journey_instance_v124($pdo,$instanceId);
    elseif(!empty($delivery['metadata']['journey_instance_key']))$instance=campaigns_rewards_journey_instance_by_key_v124($pdo,(string)$delivery['metadata']['journey_instance_key']);
    if($instance&&in_array((string)$instance['status'],['paused','cancelled'],true))
        return ['skipped'=>true,'reason'=>'instance_'.$instance['status'],'delivery'=>$delivery];

    $result=campaigns_rewards_dispatch_delivery_v123($pdo,$deliveryId);
    $fresh=$result['delivery']??campaigns_rewards_delivery_v120($pdo,$deliveryId);
    if(is_array($fresh))campaigns_rewards_instance_touch_delivery_v124($pdo,$fresh,$result);
    return $result;
}

function campaigns_rewards_dispatch_due_v124(PDO $pdo,int $merchantId=0,int $limit=200): array
{
    $limit=max(1,min(1000,$limit));$sql="SELECT d.id,d.metadata_json FROM campaign_deliveries d INNER JOIN campaigns c ON c.id=d.campaign_id WHERE d.status IN ('pending','retry_wait')";$params=[];
    if($merchantId>0){$sql.=" AND c.merchant_id=?";$params[]=$merchantId;}$sql.=" ORDER BY d.created_at,d.id LIMIT ".($limit*8);
    $q=$pdo->prepare($sql);$q->execute($params);$summary=['checked'=>0,'due'=>0,'sent'=>0,'delivered'=>0,'viewed'=>0,'retry_wait'=>0,'dead_letter'=>0,'suppressed'=>0,'failed'=>0,'paused'=>0,'cancelled'=>0,'frequency_deferred'=>0,'optimized_deferred'=>0,'quiet_hours_deferred'=>0,'skipped'=>0];
    foreach($q->fetchAll()?:[] as $row){
        if($summary['due']>=$limit)break;$summary['checked']++;$meta=json_decode((string)($row['metadata_json']??''),true)?:[];$scheduled=(string)($meta['scheduled_for']??'');
        if($scheduled!==''&&strtotime($scheduled)!==false&&strtotime($scheduled)>time()){$summary['skipped']++;continue;}$summary['due']++;
        try{$r=campaigns_rewards_dispatch_delivery_v124($pdo,(int)$row['id']);}catch(Throwable $e){$summary['failed']++;error_log('Campaign V1.24 dispatch failed: '.$e->getMessage());continue;}
        if(!empty($r['skipped'])){$reason=(string)($r['reason']??'');if($reason==='instance_paused')$summary['paused']++;elseif($reason==='instance_cancelled')$summary['cancelled']++;elseif($reason==='frequency_control')$summary['frequency_deferred']++;elseif($reason==='send_time_optimization')$summary['optimized_deferred']++;elseif($reason==='quiet_hours')$summary['quiet_hours_deferred']++;else $summary['skipped']++;continue;}
        $status=(string)($r['delivery']['status']??'failed');if(isset($summary[$status]))$summary[$status]++;else $summary['failed']++;
    }
    return $summary;
}

function campaigns_rewards_journey_instances_v124(PDO $pdo,int $merchantId,int $journeyId=0,int $limit=200): array
{
    $limit=max(1,min(1000,$limit));$sql="SELECT i.id FROM campaign_journey_instances i INNER JOIN campaigns c ON c.id=i.campaign_id WHERE c.merchant_id=?";$params=[$merchantId];
    if($journeyId>0){$sql.=" AND i.journey_id=?";$params[]=$journeyId;}$sql.=" ORDER BY i.last_activity_at DESC,i.id DESC LIMIT {$limit}";
    $q=$pdo->prepare($sql);$q->execute($params);$out=[];foreach($q->fetchAll()?:[] as $row){$v=campaigns_rewards_journey_instance_v124($pdo,(int)$row['id']);if($v)$out[]=$v;}return $out;
}

function campaigns_rewards_instance_pending_deliveries_v124(PDO $pdo,int $instanceId): array
{
    $instance=campaigns_rewards_journey_instance_v124($pdo,$instanceId);if(!$instance)return [];
    $q=$pdo->prepare("SELECT d.id FROM campaign_deliveries d WHERE d.campaign_id=? AND d.contact_id=? AND d.status IN ('pending','retry_wait','dead_letter') ORDER BY d.id");
    $q->execute([(int)$instance['campaign_id'],(int)$instance['contact_id']]);$out=[];
    foreach($q->fetchAll()?:[] as $row){
        $d=campaigns_rewards_delivery_v120($pdo,(int)$row['id']);if(!$d)continue;
        $sameId=(int)($d['metadata']['journey_instance_id']??0)===$instanceId;
        $sameKey=(string)($d['metadata']['journey_instance_key']??'')===(string)$instance['instance_key'];
        if($sameId||$sameKey)$out[]=$d;
    }
    return $out;
}

function campaigns_rewards_delivery_belongs_to_instance_v124(array $delivery,array $instance): bool
{
    return (int)($delivery['metadata']['journey_instance_id']??0)===(int)$instance['id']
      ||((string)($delivery['metadata']['journey_instance_key']??'')!==''&&(string)($delivery['metadata']['journey_instance_key']??'')===(string)$instance['instance_key']);
}

function campaigns_rewards_instance_control_v124(PDO $pdo,int $instanceId,int $actorUserId,string $action): array
{
    $instance=campaigns_rewards_journey_instance_v124($pdo,$instanceId)?:throw new RuntimeException('Journey instance not found.');
    campaigns_rewards_platform_assert_can_v100($pdo,(int)$instance['merchant_id'],$actorUserId,'campaigns.publish');
    if(!in_array($action,['pause','resume','cancel'],true))throw new RuntimeException('Choose pause, resume or cancel.');
    if($action==='pause'){
        if(!in_array((string)$instance['status'],['completed','cancelled'],true))$pdo->prepare("UPDATE campaign_journey_instances SET status='paused',paused_at=UTC_TIMESTAMP(),updated_at=UTC_TIMESTAMP() WHERE id=?")->execute([$instanceId]);
    }elseif($action==='resume'){
        if((string)$instance['status']==='paused')$pdo->prepare("UPDATE campaign_journey_instances SET status='active',paused_at=NULL,updated_at=UTC_TIMESTAMP() WHERE id=?")->execute([$instanceId]);
    }else{
        $pdo->prepare("UPDATE campaign_journey_instances SET status='cancelled',cancelled_at=UTC_TIMESTAMP(),updated_at=UTC_TIMESTAMP() WHERE id=? AND status<>'completed'")->execute([$instanceId]);
        foreach(campaigns_rewards_instance_pending_deliveries_v124($pdo,$instanceId) as $d)if(in_array((string)$d['status'],['pending','retry_wait'],true)){
            $m=$d['metadata'];$m['instance_cancelled_at']=gmdate('Y-m-d H:i:s');
            $pdo->prepare("UPDATE campaign_deliveries SET status='suppressed',metadata_json=? WHERE id=?")->execute([campaigns_rewards_json_v100($m),(int)$d['id']]);
        }
    }
    campaigns_rewards_activity_event_v100($pdo,(int)$instance['merchant_id'],'campaign.journey_instance_'.$action,['campaign_id'=>(int)$instance['campaign_id'],'contact_id'=>(int)$instance['contact_id']],[
      'summary'=>'Campaign journey instance '.match($action){'pause'=>'paused','resume'=>'resumed','cancel'=>'cancelled'},'campaign_public_id'=>$instance['campaign_public_id'],'journey_instance_id'=>$instanceId,'journey_id'=>(int)$instance['journey_id'],'journey_version_id'=>(int)$instance['journey_version_id']
    ],(string)$instance['environment'],$actorUserId);
    return campaigns_rewards_journey_instance_v124($pdo,$instanceId)?:$instance;
}

function campaigns_rewards_instance_retry_delivery_v124(PDO $pdo,int $instanceId,int $deliveryId,int $actorUserId): array
{
    $instance=campaigns_rewards_journey_instance_v124($pdo,$instanceId)?:throw new RuntimeException('Journey instance not found.');
    campaigns_rewards_platform_assert_can_v100($pdo,(int)$instance['merchant_id'],$actorUserId,'campaigns.publish');
    $delivery=campaigns_rewards_delivery_v120($pdo,$deliveryId)?:throw new RuntimeException('Delivery not found.');
    if(!campaigns_rewards_delivery_belongs_to_instance_v124($delivery,$instance)||!in_array((string)$delivery['status'],['dead_letter','failed'],true))throw new RuntimeException('Choose a failed delivery from this journey instance.');
    $meta=$delivery['metadata'];$meta['attempts']=0;$meta['scheduled_for']=gmdate('Y-m-d H:i:s');unset($meta['dead_lettered_at'],$meta['last_error'],$meta['next_attempt_at']);
    $pdo->prepare("UPDATE campaign_deliveries SET status='pending',failed_at=NULL,metadata_json=? WHERE id=?")->execute([campaigns_rewards_json_v100($meta),$deliveryId]);
    $pdo->prepare("UPDATE campaign_journey_instances SET status='active',last_activity_at=UTC_TIMESTAMP(),updated_at=UTC_TIMESTAMP() WHERE id=?")->execute([$instanceId]);
    return campaigns_rewards_delivery_v120($pdo,$deliveryId)?:$delivery;
}

function campaigns_rewards_instance_skip_delivery_v124(PDO $pdo,int $instanceId,int $deliveryId,int $actorUserId): array
{
    $instance=campaigns_rewards_journey_instance_v124($pdo,$instanceId)?:throw new RuntimeException('Journey instance not found.');
    campaigns_rewards_platform_assert_can_v100($pdo,(int)$instance['merchant_id'],$actorUserId,'campaigns.publish');
    $delivery=campaigns_rewards_delivery_v120($pdo,$deliveryId)?:throw new RuntimeException('Delivery not found.');
    if(!campaigns_rewards_delivery_belongs_to_instance_v124($delivery,$instance)||!in_array((string)$delivery['status'],['pending','retry_wait'],true))throw new RuntimeException('Choose a pending delivery from this journey instance.');
    $t=(array)$delivery['template'];$next=(string)($t['next_step_key']??'');if($next==='')throw new RuntimeException('This node has no unambiguous next step to skip to.');
    $meta=$delivery['metadata'];$meta['operator_skipped_at']=gmdate('Y-m-d H:i:s');
    $pdo->prepare("UPDATE campaign_deliveries SET status='suppressed',metadata_json=? WHERE id=?")->execute([campaigns_rewards_json_v100($meta),$deliveryId]);
    campaigns_rewards_enqueue_next_step_v123($pdo,$delivery,$next);
    $pdo->prepare("UPDATE campaign_journey_instances SET current_step_key=?,last_activity_at=UTC_TIMESTAMP(),updated_at=UTC_TIMESTAMP() WHERE id=?")->execute([$next,$instanceId]);
    campaigns_rewards_activity_event_v100($pdo,(int)$instance['merchant_id'],'campaign.journey_instance_node_skipped',['campaign_id'=>(int)$instance['campaign_id'],'contact_id'=>(int)$instance['contact_id']],[
      'summary'=>'Campaign journey pending node skipped by operator','campaign_public_id'=>$instance['campaign_public_id'],'journey_instance_id'=>$instanceId,'delivery_id'=>$deliveryId,'next_step_key'=>$next
    ],(string)$instance['environment'],$actorUserId);
    return campaigns_rewards_journey_instance_v124($pdo,$instanceId)?:$instance;
}

function campaigns_rewards_instance_move_step_v124(PDO $pdo,int $instanceId,string $targetStep,int $actorUserId): array
{
    $instance=campaigns_rewards_journey_instance_v124($pdo,$instanceId)?:throw new RuntimeException('Journey instance not found.');
    campaigns_rewards_platform_assert_can_v100($pdo,(int)$instance['merchant_id'],$actorUserId,'campaigns.publish');
    if(!in_array((string)$instance['status'],['active','paused','needs_attention'],true))throw new RuntimeException('Only an active, paused or attention-required instance can move steps.');
    $targetStep=campaigns_rewards_slug_v100($targetStep,50);if($targetStep==='')throw new RuntimeException('Choose a target step.');
    $version=campaigns_rewards_journey_version_v123($pdo,(int)$instance['journey_version_id'])?:throw new RuntimeException('Pinned Journey Version is unavailable.');
    $journey=campaigns_rewards_journey_v123($pdo,(int)$instance['journey_id'])?:throw new RuntimeException('Journey is unavailable.');
    $groups=campaigns_rewards_graph_groups_v123($version['graph']);$descriptors=$groups[$targetStep]??[];
    if(!$descriptors)throw new RuntimeException('Target step does not exist in the pinned Journey Version.');
    foreach(campaigns_rewards_instance_pending_deliveries_v124($pdo,$instanceId) as $delivery){
        if(!in_array((string)$delivery['status'],['pending','retry_wait','dead_letter'],true))continue;
        $meta=$delivery['metadata'];$meta['operator_replaced_at']=gmdate('Y-m-d H:i:s');$meta['operator_replaced_by_step']=$targetStep;
        $pdo->prepare("UPDATE campaign_deliveries SET status='suppressed',metadata_json=? WHERE id=?")->execute([campaigns_rewards_json_v100($meta),(int)$delivery['id']]);
    }
    $variants=array_map('campaigns_rewards_graph_hydrate_node_v123',$descriptors);
    $node=campaigns_rewards_select_variant_v121($variants,(int)$instance['campaign_id'],(int)$instance['contact_id'],(string)$instance['instance_key'],$targetStep);
    if(!$node)throw new RuntimeException('Target step could not select a deterministic variant.');
    $campaign=campaigns_rewards_campaign_platform_v100($pdo,(int)$instance['campaign_id'])?:throw new RuntimeException('Campaign unavailable.');
    $moveContext=(array)($instance['metadata']['context']??[]);
    $moveContext['_operation_key']='move:'.$instanceId.':'.$targetStep.':'.bin2hex(random_bytes(8));
    $result=campaigns_rewards_enqueue_node_v123($pdo,$campaign,$journey,$version,$node,(int)$instance['contact_id'],(string)$instance['instance_key'],(string)$instance['trigger_event_id'],$moveContext);
    if(!empty($result['suppressed']))throw new RuntimeException('Target step is currently suppressed: '.(string)($result['reason']??'policy'));
    $pdo->prepare("UPDATE campaign_journey_instances SET status='active',current_step_key=?,paused_at=NULL,last_activity_at=UTC_TIMESTAMP(),updated_at=UTC_TIMESTAMP() WHERE id=?")
      ->execute([$targetStep,$instanceId]);
    campaigns_rewards_activity_event_v100($pdo,(int)$instance['merchant_id'],'campaign.journey_instance_step_moved',['campaign_id'=>(int)$instance['campaign_id'],'contact_id'=>(int)$instance['contact_id']],[
      'summary'=>'Campaign journey instance moved to a compatible step by operator','campaign_public_id'=>$instance['campaign_public_id'],
      'journey_instance_id'=>$instanceId,'journey_version_id'=>(int)$instance['journey_version_id'],'target_step_key'=>$targetStep
    ],(string)$instance['environment'],$actorUserId);
    return campaigns_rewards_journey_instance_v124($pdo,$instanceId)?:$instance;
}

function campaigns_rewards_journey_emergency_stop_v124(PDO $pdo,int $journeyId,int $actorUserId,string $inflightPolicy='pause'): array
{
    $journey=campaigns_rewards_journey_v123($pdo,$journeyId)?:throw new RuntimeException('Journey not found.');
    campaigns_rewards_platform_assert_can_v100($pdo,(int)$journey['merchant_id'],$actorUserId,'campaigns.publish');
    if(!in_array($inflightPolicy,['continue','pause','cancel'],true))throw new RuntimeException('Choose continue, pause or cancel for in-flight instances.');
    $pdo->prepare("UPDATE campaign_journeys SET enrollment_status='paused',updated_at=UTC_TIMESTAMP() WHERE id=?")->execute([$journeyId]);
    $affected=0;
    if($inflightPolicy!=='continue'){
        $instances=campaigns_rewards_journey_instances_v124($pdo,(int)$journey['merchant_id'],$journeyId,1000);
        foreach($instances as $instance){
            if(in_array((string)$instance['status'],['completed','cancelled'],true))continue;
            campaigns_rewards_instance_control_v124($pdo,(int)$instance['id'],$actorUserId,$inflightPolicy==='cancel'?'cancel':'pause');$affected++;
        }
    }
    campaigns_rewards_activity_event_v100($pdo,(int)$journey['merchant_id'],'campaign.journey_emergency_stopped',['campaign_id'=>(int)$journey['campaign_id']],[
      'summary'=>'Campaign journey emergency stop applied','campaign_public_id'=>$journey['campaign_public_id'],'journey_id'=>$journeyId,
      'new_enrollment'=>'paused','inflight_policy'=>$inflightPolicy,'instances_affected'=>$affected
    ],(string)$journey['environment'],$actorUserId);
    return ['journey'=>campaigns_rewards_journey_v123($pdo,$journeyId)?:$journey,'instances_affected'=>$affected,'inflight_policy'=>$inflightPolicy];
}

function campaigns_rewards_journey_instance_timeline_v124(PDO $pdo,int $instanceId,int $actorUserId): array
{
    $instance=campaigns_rewards_journey_instance_v124($pdo,$instanceId)?:throw new RuntimeException('Journey instance not found.');
    campaigns_rewards_platform_assert_can_v100($pdo,(int)$instance['merchant_id'],$actorUserId,'campaigns.view');
    $q=$pdo->prepare("SELECT d.id FROM campaign_deliveries d WHERE d.campaign_id=? AND d.contact_id=? ORDER BY d.id");
    $q->execute([(int)$instance['campaign_id'],(int)$instance['contact_id']]);$items=[];
    foreach($q->fetchAll()?:[] as $row){
        $d=campaigns_rewards_delivery_v120($pdo,(int)$row['id']);if(!$d||!campaigns_rewards_delivery_belongs_to_instance_v124($d,$instance))continue;
        $items[]=[
          'delivery_id'=>(int)$d['id'],'step_key'=>(string)($d['metadata']['step_key']??$d['message_key']),
          'node_type'=>(string)($d['metadata']['node_type']??$d['template']['node_type']??'message'),'channel'=>(string)$d['channel'],
          'status'=>(string)$d['status'],'scheduled_for'=>(string)($d['metadata']['scheduled_for']??''),
          'sent_at'=>(string)($d['sent_at']??''),'delivered_at'=>(string)($d['delivered_at']??''),'viewed_at'=>(string)($d['viewed_at']??''),
          'failed_at'=>(string)($d['failed_at']??''),'created_at'=>(string)$d['created_at'],
          'branch'=>(string)($d['metadata']['node_outcome']??''),'error'=>(string)($d['metadata']['last_error']??''),
          'operator_skipped_at'=>(string)($d['metadata']['operator_skipped_at']??''),
        ];
    }
    return ['instance'=>$instance,'items'=>$items];
}

function campaigns_rewards_journey_entry_version_v124(PDO $pdo,array $journey,int $contactId,string $eventId): ?array
{
    $currentId=max(0,(int)($journey['current_published_version_id']??0));if($currentId<1)return null;
    $current=campaigns_rewards_journey_version_v123($pdo,$currentId);if(!$current)return null;
    $q=$pdo->prepare("SELECT previous_journey_version_id,metadata_json FROM campaign_journey_publications
      WHERE journey_id=? AND journey_version_id=? AND action IN ('publish','scheduled_publish','rollback') ORDER BY id DESC LIMIT 1");
    $q->execute([(int)$journey['id'],$currentId]);$publication=$q->fetch()?:[];$meta=json_decode((string)($publication['metadata_json']??''),true)?:[];
    $percent=max(1,min(100,(int)($meta['rollout_percent']??100)));$previousId=max(0,(int)($publication['previous_journey_version_id']??0));
    if($percent>=100||$previousId<1)return $current;
    $bucket=(hexdec(substr(hash('sha256',(int)$journey['id'].'|'.$contactId.'|'.$eventId),0,8))%100)+1;
    if($bucket<=$percent)return $current;
    $previous=campaigns_rewards_journey_version_v123($pdo,$previousId);
    return $previous&&(int)$previous['journey_id']===(int)$journey['id']?$previous:$current;
}

function campaigns_rewards_journey_incidents_v124(PDO $pdo,int $merchantId,int $journeyId=0): array
{
    $instances=campaigns_rewards_journey_instances_v124($pdo,$merchantId,$journeyId,1000);$out=[];
    foreach($instances as $i){
        if((string)$i['status']==='needs_attention')$out[]=['severity'=>'high','type'=>'failed_instance','instance_id'=>(int)$i['id'],'message'=>'Journey instance needs attention after a dead-letter delivery.'];
        $age=time()-(strtotime((string)$i['last_activity_at'])?:time());
        if((string)$i['status']==='active'&&$age>86400)$out[]=['severity'=>'medium','type'=>'stale_instance','instance_id'=>(int)$i['id'],'message'=>'Active journey instance has had no activity for more than 24 hours.'];
        if((string)$i['status']==='paused'&&$age>259200)$out[]=['severity'=>'low','type'=>'long_pause','instance_id'=>(int)$i['id'],'message'=>'Journey instance has remained paused for more than 72 hours.'];
        foreach(campaigns_rewards_instance_pending_deliveries_v124($pdo,(int)$i['id']) as $d){
            $scheduled=strtotime((string)($d['metadata']['scheduled_for']??$d['created_at']))?:time();
            if((string)$d['status']==='pending'&&$scheduled<time()-7200)$out[]=['severity'=>'medium','type'=>'queue_age','instance_id'=>(int)$i['id'],'delivery_id'=>(int)$d['id'],'message'=>'A due Journey delivery has remained pending for more than 2 hours.'];
            if((string)$d['status']==='retry_wait'&&$scheduled<time()-21600)$out[]=['severity'=>'high','type'=>'retry_age','instance_id'=>(int)$i['id'],'delivery_id'=>(int)$d['id'],'message'=>'A Journey retry has remained unresolved for more than 6 hours.'];
        }
    }
    $q=$pdo->prepare("SELECT COUNT(*) FROM campaign_deliveries d INNER JOIN campaigns c ON c.id=d.campaign_id WHERE c.merchant_id=? AND d.status='dead_letter' AND d.failed_at>=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 24 HOUR)");
    $q->execute([$merchantId]);$dead=(int)$q->fetchColumn();if($dead>=5)$out[]=['severity'=>'high','type'=>'dead_letter_spike','instance_id'=>0,'message'=>"{$dead} Campaign deliveries reached dead letter in the last 24 hours."];
    return $out;
}

function campaigns_rewards_refresh_operations_recommendations_v124(PDO $pdo,int $merchantId): array
{
    $incidents=campaigns_rewards_journey_incidents_v124($pdo,$merchantId);$created=0;
    $ownerQ=$pdo->prepare("SELECT owner_user_id FROM merchant_accounts WHERE id=? LIMIT 1");$ownerQ->execute([$merchantId]);$owner=(int)$ownerQ->fetchColumn();
    if($owner<1)return ['incidents'=>count($incidents),'recommendations_created'=>0];
    foreach($incidents as $incident){
        $type='journey.ops.'.campaigns_rewards_slug_v100((string)$incident['type'],50);$instanceId=max(0,(int)($incident['instance_id']??0));
        $summary=campaigns_rewards_text_v100((string)$incident['message'],1000);
        $q=$pdo->prepare("SELECT id FROM campaign_agent_recommendations WHERE merchant_id=? AND recommendation_type=? AND status='proposed'
          AND created_at>=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 24 HOUR) ORDER BY id DESC LIMIT 1");
        $q->execute([$merchantId,$type]);if($q->fetchColumn())continue;
        $campaignId=0;$environment='production';
        if($instanceId>0){$instance=campaigns_rewards_journey_instance_v124($pdo,$instanceId);$campaignId=(int)($instance['campaign_id']??0);$environment=(string)($instance['environment']??'production');}
        if($campaignId<1){$q=$pdo->prepare("SELECT id,environment FROM campaigns WHERE merchant_id=? AND status='active' ORDER BY id LIMIT 1");$q->execute([$merchantId]);$campaignRow=$q->fetch()?:[];$campaignId=(int)($campaignRow['id']??0);$environment=(string)($campaignRow['environment']??'production');}
        if($campaignId<1)continue;
        $pdo->prepare("INSERT INTO campaign_agent_recommendations
          (public_id,merchant_id,campaign_id,recommendation_type,summary,status,evidence_refs_json,impact_preview_json,created_for_user_id)
          VALUES (?,?,?,?,?,'proposed',?,?,?)")->execute([
            campaigns_rewards_uuid_v100(),$merchantId,$campaignId,$type,$summary,
            campaigns_rewards_json_v100(['source'=>'v124_operations','incident'=>$incident]),
            campaigns_rewards_json_v100(['requires_human_decision'=>true,'auto_apply'=>false,'allowed_actions'=>['inspect','pause','resume','cancel','retry','skip','move_step']]),$owner
        ]);
        campaigns_rewards_activity_event_v100($pdo,$merchantId,'campaign.journey_incident_proposed',['campaign_id'=>$campaignId],[
          'summary'=>'Journey operations incident proposed for human review','incident_type'=>$incident['type'],'severity'=>$incident['severity'],'journey_instance_id'=>$instanceId,'auto_apply'=>false
        ],$environment,null,'agent');
        $created++;
    }
    return ['incidents'=>count($incidents),'recommendations_created'=>$created];
}

function campaigns_rewards_journey_version_comparison_v124(PDO $pdo,int $journeyId): array
{
    $q=$pdo->prepare("SELECT journey_version_id,status,COUNT(*) total,SUM(completed_at IS NOT NULL) completed,SUM(cancelled_at IS NOT NULL) cancelled
      FROM campaign_journey_instances WHERE journey_id=? GROUP BY journey_version_id,status");$q->execute([$journeyId]);$out=[];
    foreach($q->fetchAll()?:[] as $r){$v=(int)$r['journey_version_id'];$out[$v]['total']=($out[$v]['total']??0)+(int)$r['total'];$out[$v][$r['status']]=(int)$r['total'];}
    return $out;
}

function campaigns_rewards_run_due_v124(PDO $pdo,int $merchantId=0): array
{
    $publishing=campaigns_rewards_publish_due_v123($pdo,$merchantId);
    $automation=function_exists('campaigns_rewards_automation_run_due_v119')?campaigns_rewards_automation_run_due_v119($pdo,$merchantId):[];
    $expiration=campaigns_rewards_queue_expiration_reminders_v123($pdo,$merchantId);
    $delivery=campaigns_rewards_dispatch_due_v124($pdo,$merchantId);
    $recommendations=campaigns_rewards_refresh_optimization_recommendations_v122($pdo,$merchantId);
    $incidents=$merchantId>0?campaigns_rewards_journey_incidents_v124($pdo,$merchantId):[];
    $operationsRecommendations=$merchantId>0?campaigns_rewards_refresh_operations_recommendations_v124($pdo,$merchantId):['incidents'=>0,'recommendations_created'=>0];
    return ['scheduled_publishing'=>$publishing,'automation'=>$automation,'expiration_reminders'=>$expiration,'deliveries'=>$delivery,'optimization_recommendations'=>$recommendations,'operations_incidents'=>$incidents,'operations_recommendations'=>$operationsRecommendations];
}
