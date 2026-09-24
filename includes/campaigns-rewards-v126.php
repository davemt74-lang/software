<?php
declare(strict_types=1);

/**
 * Campaigns & Rewards V1.26 — Adaptive Campaign Optimization & Lifecycle Intelligence.
 *
 * V1.26 is an evidence and recommendation layer over the canonical V1.25 Decision
 * ledger and the existing CRM/Journey/Delivery/Reward authorities. It never mutates
 * a live Campaign, Journey release, CRM lifecycle state, Reward rule, or scheduler.
 */
const VP3_CAMPAIGNS_REWARDS_V126='vp3-campaigns-rewards-v126-20260923';

function campaigns_rewards_v126_rate(int $numerator,int $denominator): float
{
    return $denominator>0?round($numerator*100/$denominator,1):0.0;
}

function campaigns_rewards_outcome_types_v126(): array
{
    return ['viewed','converted','reward_claimed','journey_completed'];
}

function campaigns_rewards_record_outcome_v126(PDO $pdo,array $decision,string $type,string $sourceType,string $sourceId,array $metadata=[],?string $observedAt=null,?int $valueMinor=null,?int $costMinor=null,string $currency='USD'): array
{
    if(!in_array($type,campaigns_rewards_outcome_types_v126(),true))throw new InvalidArgumentException('Unsupported Campaign Decision outcome.');
    $merchantId=(int)$decision['merchant_id'];$sourceId=campaigns_rewards_text_v100($sourceId,190);
    $key='v126:'.hash('sha256',implode('|',[$merchantId,(int)$decision['id'],$type,$sourceType,$sourceId]));
    $q=$pdo->prepare("SELECT * FROM campaign_decision_outcomes WHERE merchant_id=? AND outcome_key=? LIMIT 1");$q->execute([$merchantId,$key]);$row=$q->fetch();
    if($row){$row['metadata']=json_decode((string)$row['metadata_json'],true)?:[];return $row;}
    $observedAt=$observedAt&&strtotime($observedAt)!==false?gmdate('Y-m-d H:i:s',strtotime($observedAt)):gmdate('Y-m-d H:i:s');
    $currency=strtoupper(substr(preg_replace('/[^A-Za-z]/','',$currency)?:'USD',0,3));
    $pdo->prepare("INSERT INTO campaign_decision_outcomes
      (public_id,merchant_id,campaign_id,decision_id,contact_id,journey_id,journey_instance_id,delivery_id,reward_issuance_id,outcome_type,outcome_key,source_type,source_id,value_minor,cost_minor,currency,metadata_json,observed_at,created_at)
      VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,UTC_TIMESTAMP())")->execute([
        campaigns_rewards_uuid_v100(),$merchantId,(int)$decision['campaign_id'],(int)$decision['id'],(int)$decision['contact_id'],
        max(0,(int)($decision['journey_id']??0))?:null,max(0,(int)($metadata['journey_instance_id']??$decision['journey_instance_id']??0))?:null,
        max(0,(int)($metadata['delivery_id']??$decision['delivery_id']??0))?:null,max(0,(int)($metadata['reward_issuance_id']??0))?:null,
        $type,$key,campaigns_rewards_text_v100($sourceType,50),$sourceId,$valueMinor,$costMinor,$currency,campaigns_rewards_json_v100($metadata),$observedAt
    ]);
    $id=(int)$pdo->lastInsertId();$q=$pdo->prepare("SELECT * FROM campaign_decision_outcomes WHERE id=?");$q->execute([$id]);$row=$q->fetch()?:[];
    $row['metadata']=$metadata;
    $campaign=campaigns_rewards_campaign_platform_v100($pdo,(int)$decision['campaign_id']);
    if($campaign)campaigns_rewards_activity_event_v100($pdo,$merchantId,'campaign.decision_outcome_recorded',['campaign_id'=>(int)$decision['campaign_id'],'contact_id'=>(int)$decision['contact_id']],[
      'summary'=>'Verified Campaign decision outcome recorded','campaign_public_id'=>$campaign['public_id'],'decision_id'=>(int)$decision['id'],
      'outcome_id'=>$id,'outcome_type'=>$type,'source_type'=>$sourceType,'source_id'=>$sourceId,
    ],(string)$campaign['environment'],null,'system');
    return $row;
}

function campaigns_rewards_outcome_reward_economics_v126(PDO $pdo,int $issuanceId): array
{
    if($issuanceId<1)return ['value_minor'=>null,'cost_minor'=>null,'currency'=>'USD'];
    $q=$pdo->prepare("SELECT face_value_minor,currency,quantity,terms_snapshot_json FROM reward_issuances WHERE id=? LIMIT 1");$q->execute([$issuanceId]);$ri=$q->fetch();
    if(!$ri)return ['value_minor'=>null,'cost_minor'=>null,'currency'=>'USD'];
    $terms=json_decode((string)($ri['terms_snapshot_json']??''),true)?:[];$quantity=max(1,(int)($ri['quantity']??1));
    $unitCost=array_key_exists('internal_cost_minor',$terms)&&$terms['internal_cost_minor']!==null?(int)$terms['internal_cost_minor']:null;
    return [
      'value_minor'=>$ri['face_value_minor']===null?null:(int)$ri['face_value_minor']*$quantity,
      'cost_minor'=>$unitCost===null?null:$unitCost*$quantity,
      'currency'=>(string)($ri['currency']??$terms['currency']??'USD'),
    ];
}

function campaigns_rewards_decision_instance_v126(PDO $pdo,array $decision): ?array
{
    $instanceId=max(0,(int)($decision['journey_instance_id']??0));
    if($instanceId>0)return campaigns_rewards_journey_instance_v124($pdo,$instanceId);
    if((string)$decision['decision_type']!=='entry'||empty($decision['outcome']['allowed'])||max(0,(int)($decision['journey_id']??0))<1)return null;
    $q=$pdo->prepare("SELECT id FROM campaign_journey_instances
      WHERE campaign_id=? AND journey_id=? AND journey_version_id=? AND contact_id=?
        AND started_at>=? AND started_at<=DATE_ADD(?,INTERVAL 10 MINUTE)
      ORDER BY started_at,id LIMIT 1");
    $q->execute([(int)$decision['campaign_id'],(int)$decision['journey_id'],max(0,(int)($decision['journey_version_id']??0)),(int)$decision['contact_id'],(string)$decision['created_at'],(string)$decision['created_at']]);
    $id=(int)$q->fetchColumn();return $id>0?campaigns_rewards_journey_instance_v124($pdo,$id):null;
}

function campaigns_rewards_decision_deliveries_v126(PDO $pdo,array $decision,?array $instance): array
{
    $ids=[];$direct=max(0,(int)($decision['delivery_id']??0));if($direct>0)$ids[$direct]=true;
    if($instance){
        $q=$pdo->prepare("SELECT id,metadata_json FROM campaign_deliveries WHERE campaign_id=? AND contact_id=? AND created_at>=? ORDER BY id");
        $q->execute([(int)$instance['campaign_id'],(int)$instance['contact_id'],(string)$instance['started_at']]);
        foreach($q->fetchAll()?:[] as $row){
            $meta=json_decode((string)($row['metadata_json']??''),true)?:[];
            if((int)($meta['journey_instance_id']??0)===(int)$instance['id']||(string)($meta['journey_instance_key']??'')===(string)$instance['instance_key'])$ids[(int)$row['id']]=true;
        }
    }
    $out=[];foreach(array_keys($ids) as $id){$d=campaigns_rewards_delivery_v120($pdo,$id);if($d)$out[]=$d;}return $out;
}

function campaigns_rewards_collect_decision_outcomes_v126(PDO $pdo,int $merchantId=0,int $windowDays=90,int $limit=5000): array
{
    if(!campaigns_rewards_optimization_schema_ready_v126($pdo))throw new RuntimeException('Campaign optimization schema is unavailable.');
    $windowDays=max(1,min(365,$windowDays));$limit=max(1,min(20000,$limit));
    $sql="SELECT d.* FROM campaign_decisions d INNER JOIN campaigns c ON c.id=d.campaign_id
      WHERE d.created_at>=DATE_SUB(UTC_TIMESTAMP(),INTERVAL {$windowDays} DAY)";
    $params=[];if($merchantId>0){$sql.=" AND d.merchant_id=?";$params[]=$merchantId;}$sql.=" ORDER BY d.id DESC LIMIT {$limit}";
    $q=$pdo->prepare($sql);$q->execute($params);$decisions=$q->fetchAll()?:[];
    $summary=['decisions_scanned'=>0,'outcomes_created'=>0,'viewed'=>0,'converted'=>0,'reward_claimed'=>0,'journey_completed'=>0];
    foreach($decisions as $decision){
        $summary['decisions_scanned']++;$decision['context']=json_decode((string)$decision['context_json'],true)?:[];$decision['rules']=json_decode((string)$decision['rules_json'],true)?:[];$decision['outcome']=json_decode((string)$decision['outcome_json'],true)?:[];
        $instance=campaigns_rewards_decision_instance_v126($pdo,$decision);$deliveries=campaigns_rewards_decision_deliveries_v126($pdo,$decision,$instance);
        foreach($deliveries as $delivery){
            $deliveryId=(int)$delivery['id'];$meta=(array)$delivery['metadata'];
            if(!empty($delivery['viewed_at'])){
                $before=(int)$pdo->query("SELECT COUNT(*) FROM campaign_decision_outcomes WHERE decision_id=".(int)$decision['id']." AND outcome_type='viewed' AND source_type='campaign_delivery' AND source_id='".$deliveryId."'")->fetchColumn();
                campaigns_rewards_record_outcome_v126($pdo,$decision,'viewed','campaign_delivery',(string)$deliveryId,['delivery_id'=>$deliveryId,'journey_instance_id'=>(int)($instance['id']??0),'channel'=>(string)$delivery['channel']],(string)$delivery['viewed_at']);
                if(!$before){$summary['outcomes_created']++;$summary['viewed']++;}
            }
            $claimId=max(0,(int)($meta['attributed_claim_id']??0));
            if($claimId>0){
                $issuanceId=max(0,(int)($delivery['reward_issuance_id']??0));$econ=campaigns_rewards_outcome_reward_economics_v126($pdo,$issuanceId);
                $observed=(string)($meta['converted_at']??$delivery['viewed_at']??$delivery['delivered_at']??$delivery['sent_at']??$delivery['created_at']);
                $before=(int)$pdo->query("SELECT COUNT(*) FROM campaign_decision_outcomes WHERE decision_id=".(int)$decision['id']." AND outcome_type='converted' AND source_type='reward_claim' AND source_id='".$claimId."'")->fetchColumn();
                campaigns_rewards_record_outcome_v126($pdo,$decision,'converted','reward_claim',(string)$claimId,['delivery_id'=>$deliveryId,'journey_instance_id'=>(int)($instance['id']??0),'reward_issuance_id'=>$issuanceId,'claim_id'=>$claimId,'attribution'=>'canonical_campaign_delivery'], $observed,$econ['value_minor'],$econ['cost_minor'],$econ['currency']);
                if(!$before){$summary['outcomes_created']++;$summary['converted']++;}
                $before=(int)$pdo->query("SELECT COUNT(*) FROM campaign_decision_outcomes WHERE decision_id=".(int)$decision['id']." AND outcome_type='reward_claimed' AND source_type='reward_claim' AND source_id='".$claimId."'")->fetchColumn();
                campaigns_rewards_record_outcome_v126($pdo,$decision,'reward_claimed','reward_claim',(string)$claimId,['delivery_id'=>$deliveryId,'journey_instance_id'=>(int)($instance['id']??0),'reward_issuance_id'=>$issuanceId,'claim_id'=>$claimId],$observed,$econ['value_minor'],$econ['cost_minor'],$econ['currency']);
                if(!$before){$summary['outcomes_created']++;$summary['reward_claimed']++;}
            }
        }
        if($instance&&!empty($instance['completed_at'])){
            $before=(int)$pdo->query("SELECT COUNT(*) FROM campaign_decision_outcomes WHERE decision_id=".(int)$decision['id']." AND outcome_type='journey_completed' AND source_type='campaign_journey_instance' AND source_id='".(int)$instance['id']."'")->fetchColumn();
            campaigns_rewards_record_outcome_v126($pdo,$decision,'journey_completed','campaign_journey_instance',(string)$instance['id'],['journey_instance_id'=>(int)$instance['id'],'attribution'=>(int)($decision['journey_instance_id']??0)>0?'direct':'nearest_post_decision_instance'],(string)$instance['completed_at']);
            if(!$before){$summary['outcomes_created']++;$summary['journey_completed']++;}
        }
    }
    return $summary;
}

function campaigns_rewards_fatigue_signal_v126(PDO $pdo,int $campaignId,int $windowDays=30): array
{
    $windowDays=max(7,min(90,$windowDays));
    $q=$pdo->prepare("SELECT contact_id,
      SUM(sent_at IS NOT NULL) sends,
      SUM(viewed_at IS NOT NULL) views,
      SUM(JSON_EXTRACT(metadata_json,'$.attributed_claim_id') IS NOT NULL) conversions,
      MAX(sent_at) last_sent_at
      FROM campaign_deliveries WHERE campaign_id=? AND created_at>=DATE_SUB(UTC_TIMESTAMP(),INTERVAL {$windowDays} DAY)
      GROUP BY contact_id");
    $q->execute([$campaignId]);$contacts=[];$high=0;$moderate=0;$totalSends=0;$totalViews=0;$totalConversions=0;
    foreach($q->fetchAll()?:[] as $row){
        $s=(int)$row['sends'];$v=(int)$row['views'];$c=(int)$row['conversions'];$totalSends+=$s;$totalViews+=$v;$totalConversions+=$c;
        $level='none';if($s>=6&&$v===0&&$c===0){$level='high';$high++;}elseif($s>=4&&$c===0&&$v<=1){$level='moderate';$moderate++;}
        if($level!=='none')$contacts[]=['contact_id'=>(int)$row['contact_id'],'sends'=>$s,'views'=>$v,'conversions'=>$c,'level'=>$level,'last_sent_at'=>(string)$row['last_sent_at']];
    }
    usort($contacts,static fn($a,$b)=>[$b['level']==='high'?1:0,$b['sends']]<=>[$a['level']==='high'?1:0,$a['sends']]);
    return ['window_days'=>$windowDays,'sent'=>$totalSends,'viewed'=>$totalViews,'converted'=>$totalConversions,'view_rate'=>campaigns_rewards_v126_rate($totalViews,$totalSends),'conversion_rate'=>campaigns_rewards_v126_rate($totalConversions,$totalSends),'high_fatigue_contacts'=>$high,'moderate_fatigue_contacts'=>$moderate,'contacts'=>array_slice($contacts,0,100),'diagnostic_only'=>true];
}

function campaigns_rewards_lifecycle_performance_v126(PDO $pdo,int $campaignId,int $windowDays=30): array
{
    $windowDays=max(7,min(365,$windowDays));$q=$pdo->prepare("SELECT * FROM campaign_decisions WHERE campaign_id=? AND created_at>=DATE_SUB(UTC_TIMESTAMP(),INTERVAL {$windowDays} DAY) ORDER BY id");
    $q->execute([$campaignId]);$stages=[];
    foreach($q->fetchAll()?:[] as $d){
        $ctx=json_decode((string)$d['context_json'],true)?:[];$stage=trim((string)($ctx['contact']['lifecycle_stage']??''));if($stage==='')$stage='unknown';
        $stages[$stage]??=['decisions'=>0,'holdouts'=>0,'views'=>0,'conversions'=>0,'claims'=>0,'completed'=>0];
        $stages[$stage]['decisions']++;if(!empty($d['is_holdout']))$stages[$stage]['holdouts']++;
        $o=$pdo->prepare("SELECT outcome_type,COUNT(*) n FROM campaign_decision_outcomes WHERE decision_id=? GROUP BY outcome_type");$o->execute([(int)$d['id']]);
        foreach($o->fetchAll()?:[] as $row){$n=(int)$row['n'];if($row['outcome_type']==='viewed')$stages[$stage]['views']+=$n;elseif($row['outcome_type']==='converted')$stages[$stage]['conversions']+=$n;elseif($row['outcome_type']==='reward_claimed')$stages[$stage]['claims']+=$n;elseif($row['outcome_type']==='journey_completed')$stages[$stage]['completed']+=$n;}
    }
    foreach($stages as &$s){$s['observed_conversion_rate']=campaigns_rewards_v126_rate($s['conversions'],$s['decisions']);$s['observed_completion_rate']=campaigns_rewards_v126_rate($s['completed'],$s['decisions']);}unset($s);
    ksort($stages);return ['window_days'=>$windowDays,'stages'=>$stages,'source'=>'decision_time_context','mutates_crm'=>false];
}

function campaigns_rewards_offer_performance_v126(PDO $pdo,int $campaignId,int $windowDays=30): array
{
    $windowDays=max(7,min(365,$windowDays));$q=$pdo->prepare("SELECT * FROM campaign_decisions WHERE campaign_id=? AND decision_type='offer' AND created_at>=DATE_SUB(UTC_TIMESTAMP(),INTERVAL {$windowDays} DAY) ORDER BY id");
    $q->execute([$campaignId]);$rewards=[];$noOffer=0;
    foreach($q->fetchAll()?:[] as $d){
        $out=json_decode((string)$d['outcome_json'],true)?:[];if(empty($out['selected'])){$noOffer++;continue;}
        $rewardId=max(0,(int)($out['reward_product_id']??0));$key=(string)$rewardId;$rewards[$key]??=['reward_product_id'=>$rewardId,'name'=>(string)($out['name']??''),'selections'=>0,'views'=>0,'conversions'=>0,'claims'=>0,'claimed_value_minor'=>0,'claimed_cost_minor'=>0,'currency'=>(string)($out['currency']??'USD')];$rewards[$key]['selections']++;
        $o=$pdo->prepare("SELECT outcome_type,COUNT(*) n,COALESCE(SUM(value_minor),0) value_minor,COALESCE(SUM(cost_minor),0) cost_minor FROM campaign_decision_outcomes WHERE decision_id=? GROUP BY outcome_type");$o->execute([(int)$d['id']]);
        foreach($o->fetchAll()?:[] as $row){$n=(int)$row['n'];if($row['outcome_type']==='viewed')$rewards[$key]['views']+=$n;elseif($row['outcome_type']==='converted')$rewards[$key]['conversions']+=$n;elseif($row['outcome_type']==='reward_claimed'){$rewards[$key]['claims']+=$n;$rewards[$key]['claimed_value_minor']+=(int)$row['value_minor'];$rewards[$key]['claimed_cost_minor']+=(int)$row['cost_minor'];}}
    }
    foreach($rewards as &$r){$r['observed_conversion_rate']=campaigns_rewards_v126_rate($r['conversions'],$r['selections']);$r['observed_claim_rate']=campaigns_rewards_v126_rate($r['claims'],$r['selections']);}unset($r);
    return ['window_days'=>$windowDays,'no_eligible_offer_decisions'=>$noOffer,'rewards'=>array_values($rewards)];
}

function campaigns_rewards_campaign_optimization_v126(PDO $pdo,int $campaignId,int $windowDays=30): array
{
    $campaign=campaigns_rewards_campaign_platform_v100($pdo,$campaignId)?:throw new RuntimeException('Campaign not found.');$windowDays=max(7,min(365,$windowDays));
    $q=$pdo->prepare("SELECT decision_type,is_holdout,outcome_json,COUNT(*) n FROM campaign_decisions WHERE campaign_id=? AND created_at>=DATE_SUB(UTC_TIMESTAMP(),INTERVAL {$windowDays} DAY) GROUP BY decision_type,is_holdout,outcome_json");$q->execute([$campaignId]);
    $metrics=['decisions'=>0,'entry'=>0,'treatment_entries'=>0,'holdout_entries'=>0,'conflict_suppressed'=>0,'offers'=>0,'offers_selected'=>0,'offers_unavailable'=>0];
    foreach($q->fetchAll()?:[] as $row){$n=(int)$row['n'];$metrics['decisions']+=$n;$o=json_decode((string)$row['outcome_json'],true)?:[];
        if($row['decision_type']==='entry'){$metrics['entry']+=$n;if(!empty($row['is_holdout']))$metrics['holdout_entries']+=$n;elseif(($o['reason']??'')==='campaign_conflict')$metrics['conflict_suppressed']+=$n;elseif(!empty($o['allowed']))$metrics['treatment_entries']+=$n;}
        if($row['decision_type']==='offer'){$metrics['offers']+=$n;if(!empty($o['selected']))$metrics['offers_selected']+=$n;else $metrics['offers_unavailable']+=$n;}
    }
    $oq=$pdo->prepare("SELECT outcome_type,
      COUNT(DISTINCT CONCAT(source_type,':',source_id)) n,
      COALESCE(SUM(CASE WHEN outcome_type='reward_claimed' THEN value_minor ELSE 0 END),0) value_minor,
      COALESCE(SUM(CASE WHEN outcome_type='reward_claimed' THEN cost_minor ELSE 0 END),0) cost_minor
      FROM (
        SELECT campaign_id,outcome_type,source_type,source_id,MAX(value_minor) value_minor,MAX(cost_minor) cost_minor,MAX(observed_at) observed_at
        FROM campaign_decision_outcomes
        WHERE campaign_id=? AND observed_at>=DATE_SUB(UTC_TIMESTAMP(),INTERVAL {$windowDays} DAY)
        GROUP BY campaign_id,outcome_type,source_type,source_id
      ) unique_outcomes GROUP BY outcome_type");$oq->execute([$campaignId]);
    $metrics['outcomes']=['viewed'=>0,'converted'=>0,'reward_claimed'=>0,'journey_completed'=>0];$metrics['claimed_value_minor']=0;$metrics['claimed_cost_minor']=0;
    foreach($oq->fetchAll()?:[] as $row){$type=(string)$row['outcome_type'];if(isset($metrics['outcomes'][$type]))$metrics['outcomes'][$type]=(int)$row['n'];if($type==='reward_claimed'){$metrics['claimed_value_minor']=(int)$row['value_minor'];$metrics['claimed_cost_minor']=(int)$row['cost_minor'];}}
    $metrics['observed_treatment_conversion_rate']=campaigns_rewards_v126_rate((int)$metrics['outcomes']['converted'],(int)$metrics['treatment_entries']);
    $metrics['holdout_measurement_scope']='Campaign-attributed execution outcomes are not a causal holdout lift measure because holdouts receive no Campaign execution.';
    $fatigue=campaigns_rewards_fatigue_signal_v126($pdo,$campaignId,$windowDays);$lifecycle=campaigns_rewards_lifecycle_performance_v126($pdo,$campaignId,$windowDays);$offers=campaigns_rewards_offer_performance_v126($pdo,$campaignId,$windowDays);
    $recommendations=[];
    if($metrics['offers']>=10&&$metrics['offers_unavailable']/$metrics['offers']>=0.25)$recommendations[]=['key'=>'offer-eligibility','summary'=>'A material share of dynamic offer decisions found no eligible Reward. Review attached Reward eligibility, inventory, limits, and conditions.','evidence'=>['offers'=>$metrics['offers'],'unavailable'=>$metrics['offers_unavailable']]];
    if($fatigue['high_fatigue_contacts']>=5)$recommendations[]=['key'=>'fatigue','summary'=>'Multiple contacts show repeated Campaign sends without observed view or conversion signals. Review cadence and frequency controls.','evidence'=>['high_fatigue_contacts'=>$fatigue['high_fatigue_contacts'],'window_days'=>$windowDays]];
    if($metrics['treatment_entries']>=20&&$metrics['observed_treatment_conversion_rate']<2.0)$recommendations[]=['key'=>'low-observed-conversion','summary'=>'Observed Campaign-attributed conversion is low across recent admitted entries. Review Journey copy, targeting, offer eligibility and branch paths before changing live rules.','evidence'=>['treatment_entries'=>$metrics['treatment_entries'],'observed_conversion_rate'=>$metrics['observed_treatment_conversion_rate']]];
    if($metrics['conflict_suppressed']>=10)$recommendations[]=['key'=>'conflict-volume','summary'=>'Campaign conflict controls suppressed a material number of entries. Review portfolio conflict groups and windows before changing priorities.','evidence'=>['conflict_suppressed'=>$metrics['conflict_suppressed']]];
    return ['campaign_id'=>$campaignId,'merchant_id'=>(int)$campaign['merchant_id'],'window_days'=>$windowDays,'window_end'=>gmdate('Y-m-d H:i:s'),'window_start'=>gmdate('Y-m-d H:i:s',time()-$windowDays*86400),'metrics'=>$metrics,'fatigue'=>$fatigue,'lifecycle'=>$lifecycle,'offers'=>$offers,'recommendations'=>$recommendations,'authority'=>'observational_and_advisory_only'];
}

function campaigns_rewards_create_optimization_snapshot_v126(PDO $pdo,array $analysis): array
{
    $payload=['metrics'=>$analysis['metrics'],'fatigue'=>$analysis['fatigue'],'lifecycle'=>$analysis['lifecycle'],'offers'=>$analysis['offers'],'recommendations'=>$analysis['recommendations']];
    $hash=hash('sha256',campaigns_rewards_json_v100($payload));$campaignId=(int)$analysis['campaign_id'];
    $q=$pdo->prepare("SELECT * FROM campaign_optimization_snapshots WHERE campaign_id=? AND evidence_hash=? AND created_at>=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 1 DAY) ORDER BY id DESC LIMIT 1");$q->execute([$campaignId,$hash]);$row=$q->fetch();
    if($row){$row['metrics']=json_decode((string)$row['metrics_json'],true)?:[];$row['lifecycle']=json_decode((string)$row['lifecycle_json'],true)?:[];$row['recommendations']=json_decode((string)$row['recommendations_json'],true)?:[];$row['duplicate']=true;return $row;}
    $journeyId=null;$sample=(int)($analysis['metrics']['decisions']??0);
    $pdo->prepare("INSERT INTO campaign_optimization_snapshots
      (public_id,merchant_id,campaign_id,journey_id,window_days,window_start,window_end,sample_count,metrics_json,lifecycle_json,recommendations_json,evidence_hash,status,created_at)
      VALUES (?,?,?,?,?,?,?,?,?,?,?,?,'observed',UTC_TIMESTAMP())")->execute([
        campaigns_rewards_uuid_v100(),(int)$analysis['merchant_id'],$campaignId,$journeyId,(int)$analysis['window_days'],(string)$analysis['window_start'],(string)$analysis['window_end'],$sample,
        campaigns_rewards_json_v100(['campaign'=>$analysis['metrics'],'fatigue'=>$analysis['fatigue'],'offers'=>$analysis['offers']]),
        campaigns_rewards_json_v100($analysis['lifecycle']),campaigns_rewards_json_v100($analysis['recommendations']),$hash
    ]);
    $id=(int)$pdo->lastInsertId();$q=$pdo->prepare("SELECT * FROM campaign_optimization_snapshots WHERE id=?");$q->execute([$id]);$row=$q->fetch()?:[];$row['duplicate']=false;
    $campaign=campaigns_rewards_campaign_platform_v100($pdo,$campaignId);
    if($campaign)campaigns_rewards_activity_event_v100($pdo,(int)$analysis['merchant_id'],'campaign.optimization_snapshot_recorded',['campaign_id'=>$campaignId],['summary'=>'Campaign optimization evidence snapshot recorded','campaign_public_id'=>$campaign['public_id'],'snapshot_id'=>$id,'sample_count'=>$sample,'evidence_hash'=>$hash],(string)$campaign['environment'],null,'system');
    return $row;
}

function campaigns_rewards_create_v126_recommendation(PDO $pdo,array $campaign,array $snapshot,array $recommendation): bool
{
    $owner=(int)$campaign['merchant_owner_user_id'];if($owner<1)return false;$type='optimization.'.campaigns_rewards_slug_v100((string)$recommendation['key'],60);
    $q=$pdo->prepare("SELECT id FROM campaign_agent_recommendations WHERE merchant_id=? AND campaign_id=? AND recommendation_type=? AND status='proposed' AND created_at>=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 7 DAY) LIMIT 1");
    $q->execute([(int)$campaign['merchant_id'],(int)$campaign['id'],$type]);if($q->fetchColumn())return false;
    $pdo->prepare("INSERT INTO campaign_agent_recommendations
      (public_id,merchant_id,campaign_id,recommendation_type,summary,status,evidence_refs_json,impact_preview_json,created_for_user_id)
      VALUES (?,?,?,?,?,'proposed',?,?,?)")->execute([
        campaigns_rewards_uuid_v100(),(int)$campaign['merchant_id'],(int)$campaign['id'],$type,campaigns_rewards_text_v100((string)$recommendation['summary'],1000),
        campaigns_rewards_json_v100(['source'=>'v126_optimization_snapshot','snapshot_id'=>(int)$snapshot['id'],'evidence_hash'=>(string)$snapshot['evidence_hash'],'evidence'=>$recommendation['evidence']??[]]),
        campaigns_rewards_json_v100(['requires_human_decision'=>true,'auto_apply'=>false,'allowed_effect'=>'review_only','live_campaign_mutation'=>false]),$owner
    ]);
    campaigns_rewards_activity_event_v100($pdo,(int)$campaign['merchant_id'],'campaign.optimization_recommendation_proposed',['campaign_id'=>(int)$campaign['id']],['summary'=>'Campaign optimization recommendation proposed for human review','campaign_public_id'=>$campaign['public_id'],'recommendation_type'=>$type,'snapshot_id'=>(int)$snapshot['id'],'auto_apply'=>false],(string)$campaign['environment'],null,'agent');
    return true;
}

function campaigns_rewards_refresh_optimization_v126(PDO $pdo,int $merchantId=0,int $windowDays=30): array
{
    $sql="SELECT c.id FROM campaigns c INNER JOIN merchant_accounts m ON m.id=c.merchant_id WHERE c.status='active' AND c.environment='production' AND m.status='active'";$params=[];
    if($merchantId>0){$sql.=" AND c.merchant_id=?";$params[]=$merchantId;}$sql.=" ORDER BY c.id";$q=$pdo->prepare($sql);$q->execute($params);
    $summary=['campaigns_reviewed'=>0,'snapshots_created'=>0,'snapshots_reused'=>0,'recommendations_created'=>0];
    foreach($q->fetchAll()?:[] as $row){$campaign=campaigns_rewards_campaign_platform_v100($pdo,(int)$row['id']);if(!$campaign)continue;$summary['campaigns_reviewed']++;
        $analysis=campaigns_rewards_campaign_optimization_v126($pdo,(int)$campaign['id'],$windowDays);$snapshot=campaigns_rewards_create_optimization_snapshot_v126($pdo,$analysis);
        if(!empty($snapshot['duplicate']))$summary['snapshots_reused']++;else $summary['snapshots_created']++;
        foreach($analysis['recommendations'] as $recommendation)if(campaigns_rewards_create_v126_recommendation($pdo,$campaign,$snapshot,$recommendation))$summary['recommendations_created']++;
    }
    return $summary;
}

function campaigns_rewards_optimization_snapshots_v126(PDO $pdo,int $merchantId,int $campaignId=0,int $limit=50): array
{
    $limit=max(1,min(200,$limit));$sql="SELECT s.*,c.name campaign_name FROM campaign_optimization_snapshots s INNER JOIN campaigns c ON c.id=s.campaign_id WHERE s.merchant_id=?";$params=[$merchantId];
    if($campaignId>0){$sql.=" AND s.campaign_id=?";$params[]=$campaignId;}$sql.=" ORDER BY s.id DESC LIMIT {$limit}";$q=$pdo->prepare($sql);$q->execute($params);$rows=$q->fetchAll()?:[];
    foreach($rows as &$row){$row['metrics']=json_decode((string)$row['metrics_json'],true)?:[];$row['lifecycle']=json_decode((string)$row['lifecycle_json'],true)?:[];$row['recommendations']=json_decode((string)$row['recommendations_json'],true)?:[];}unset($row);return $rows;
}

function campaigns_rewards_dispatch_due_v126(PDO $pdo,int $merchantId=0,int $limit=200): array
{
    return campaigns_rewards_dispatch_due_v125($pdo,$merchantId,$limit);
}

function campaigns_rewards_run_due_v126(PDO $pdo,int $merchantId=0): array
{
    $base=campaigns_rewards_run_due_v125($pdo,$merchantId);
    $base['decision_outcomes']=campaigns_rewards_collect_decision_outcomes_v126($pdo,$merchantId,90,5000);
    $base['adaptive_optimization']=campaigns_rewards_refresh_optimization_v126($pdo,$merchantId,30);
    return $base;
}
