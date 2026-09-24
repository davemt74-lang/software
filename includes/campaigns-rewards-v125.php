<?php
declare(strict_types=1);

const VP3_CAMPAIGNS_REWARDS_V125='vp3-campaigns-rewards-v125-20260923';

function campaigns_rewards_decision_fields_v125(): array
{
    return [
      'contact.marketing_status'=>'Contact marketing status',
      'contact.customer_status'=>'Merchant customer status',
      'contact.lifecycle_stage'=>'CRM lifecycle stage',
      'contact.loyalty_status'=>'Merchant loyalty status',
      'contact.preferred_channel'=>'Preferred channel',
      'contact.days_since_purchase'=>'Days since last purchase',
      'contact.tags'=>'CRM tags',
      'contact.segments'=>'CRM segments',
      'loyalty.tier'=>'Loyalty tier',
      'loyalty.points'=>'Loyalty points balance',
      'rewards.active_count'=>'Active Rewards',
      'rewards.claimed_count'=>'Claimed Rewards',
      'campaign.prior_participation_count'=>'Prior Campaign participation',
      'journey.prior_completed_count'=>'Prior completed Journey instances',
      'context.amount_paid_cents'=>'Trigger purchase amount',
      'context.balance'=>'Trigger loyalty balance',
      'context.location_id'=>'Trigger location',
      'context.referrer_contact_id'=>'Trigger referrer contact',
      'delivery.converted'=>'Journey conversion recorded',
      'campaign.status'=>'Campaign status',
    ];
}

function campaigns_rewards_decision_operators_v125(): array
{
    return [
      'equals'=>'Equals','not_equals'=>'Does not equal','gt'=>'Greater than','gte'=>'Greater than or equal',
      'lt'=>'Less than','lte'=>'Less than or equal','contains'=>'Contains','not_contains'=>'Does not contain',
      'in'=>'Is one of','not_in'=>'Is not one of','truthy'=>'Is true / present','falsy'=>'Is false / empty',
    ];
}

function campaigns_rewards_decision_settings_v125(array $input,array $previous=[]): array
{
    $field=(string)($input['decision_field']??$previous['decision_field']??'');
    if($field!==''&&!isset(campaigns_rewards_decision_fields_v125()[$field]))$field='';
    $operator=(string)($input['decision_operator']??$previous['decision_operator']??'equals');
    if(!isset(campaigns_rewards_decision_operators_v125()[$operator]))$operator='equals';
    $offerMode=(string)($input['offer_mode']??$previous['offer_mode']??'none');
    if(!in_array($offerMode,['none','attached_best','specific'],true))$offerMode='none';
    $offerAction=(string)($input['offer_action']??$previous['offer_action']??'select');
    if(!in_array($offerAction,['select','issue'],true))$offerAction='select';
    return [
      'decision_field'=>$field,
      'decision_operator'=>$operator,
      'decision_value'=>campaigns_rewards_text_v100($input['decision_value']??$previous['decision_value']??'',500),
      'holdout_percent'=>max(0,min(90,(int)($input['holdout_percent']??$previous['holdout_percent']??0))),
      'conflict_group'=>campaigns_rewards_slug_v100((string)($input['conflict_group']??$previous['conflict_group']??''),80),
      'conflict_window_hours'=>max(0,min(8760,(int)($input['conflict_window_hours']??$previous['conflict_window_hours']??0))),
      'decision_priority'=>max(0,min(10000,(int)($input['decision_priority']??$previous['decision_priority']??100))),
      'offer_mode'=>$offerMode,
      'offer_action'=>$offerAction,
      'offer_reward_product_id'=>max(0,(int)($input['offer_reward_product_id']??$previous['offer_reward_product_id']??0)),
      'personalization_enabled'=>!empty($input['personalization_enabled'])||(!array_key_exists('personalization_enabled',$input)&&!empty($previous['personalization_enabled'])),
    ];
}

function campaigns_rewards_decision_context_v125(PDO $pdo,int $merchantId,int $campaignId,int $contactId,array $triggerContext=[]): array
{
    $contact=[];$relationship=[];$preferences=[];
    $q=$pdo->prepare("SELECT * FROM crm_contacts WHERE id=? LIMIT 1");$q->execute([$contactId]);$contact=$q->fetch()?:[];
    $q=$pdo->prepare("SELECT * FROM crm_merchant_relationships WHERE merchant_id=? AND contact_id=? LIMIT 1");$q->execute([$merchantId,$contactId]);$relationship=$q->fetch()?:[];
    if(table_exists('crm_contact_preferences')){$q=$pdo->prepare("SELECT * FROM crm_contact_preferences WHERE contact_id=? LIMIT 1");$q->execute([$contactId]);$preferences=$q->fetch()?:[];}

    $tags=[];if(table_exists('crm_contact_tags')&&table_exists('crm_tags')){
        $q=$pdo->prepare("SELECT t.slug FROM crm_contact_tags ct INNER JOIN crm_tags t ON t.id=ct.tag_id WHERE ct.contact_id=? ORDER BY t.slug");$q->execute([$contactId]);$tags=$q->fetchAll(PDO::FETCH_COLUMN)?:[];
    }
    $segments=[];if(table_exists('crm_segment_members')&&table_exists('crm_segments')){
        $q=$pdo->prepare("SELECT s.slug FROM crm_segment_members sm INNER JOIN crm_segments s ON s.id=sm.segment_id WHERE sm.contact_id=? AND s.status='active' ORDER BY s.slug");$q->execute([$contactId]);$segments=$q->fetchAll(PDO::FETCH_COLUMN)?:[];
    }

    $loyalty=['tier'=>'','points'=>0];
    if(table_exists('loyalty_accounts')&&table_exists('loyalty_programs')){
        $q=$pdo->prepare("SELECT la.id,lt.tier_key FROM loyalty_accounts la
          INNER JOIN loyalty_programs lp ON lp.id=la.program_id
          LEFT JOIN loyalty_tiers lt ON lt.id=la.current_tier_id
          WHERE lp.merchant_id=? AND la.contact_id=? AND la.status='active' ORDER BY la.id LIMIT 1");
        $q->execute([$merchantId,$contactId]);$account=$q->fetch()?:[];
        if($account){$loyalty['tier']=(string)($account['tier_key']??'');if(table_exists('loyalty_ledger')){$p=$pdo->prepare("SELECT COALESCE(SUM(points_delta),0) FROM loyalty_ledger WHERE loyalty_account_id=?");$p->execute([(int)$account['id']]);$loyalty['points']=(int)$p->fetchColumn();}}
    }

    $activeRewards=0;$claimedRewards=0;
    if(table_exists('reward_issuances')){
        $q=$pdo->prepare("SELECT
          SUM(status IN ('issued','sent','viewed') AND remaining_quantity>0 AND (expires_at IS NULL OR expires_at>UTC_TIMESTAMP())) active_count,
          SUM(status='claimed') claimed_count
          FROM reward_issuances WHERE merchant_id=? AND recipient_contact_id=?");
        $q->execute([$merchantId,$contactId]);$r=$q->fetch()?:[];$activeRewards=(int)($r['active_count']??0);$claimedRewards=(int)($r['claimed_count']??0);
    }
    $priorCampaign=0;if(table_exists('campaign_enrollments')){$q=$pdo->prepare("SELECT COUNT(*) FROM campaign_enrollments WHERE campaign_id=? AND contact_id=?");$q->execute([$campaignId,$contactId]);$priorCampaign=(int)$q->fetchColumn();}
    $priorJourney=0;if(table_exists('campaign_journey_instances')){$q=$pdo->prepare("SELECT COUNT(*) FROM campaign_journey_instances WHERE campaign_id=? AND contact_id=? AND status='completed'");$q->execute([$campaignId,$contactId]);$priorJourney=(int)$q->fetchColumn();}

    $lastPurchase=(string)($relationship['last_purchase_at']??'');$daysSince=null;
    if($lastPurchase!==''){$ts=strtotime($lastPurchase);if($ts!==false)$daysSince=max(0,(int)floor((time()-$ts)/86400));}
    $contactMeta=json_decode((string)($contact['metadata_json']??''),true);if(!is_array($contactMeta))$contactMeta=[];
    $relationshipMeta=json_decode((string)($relationship['metadata_json']??''),true);if(!is_array($relationshipMeta))$relationshipMeta=[];

    return [
      'contact'=>[
        'id'=>$contactId,'name'=>(string)($contact['name']??''),'email'=>(string)($contact['email']??''),
        'lifecycle_stage'=>(string)($contact['lifecycle_stage']??''),'marketing_status'=>(string)($relationship['marketing_status']??$contact['marketing_status']??''),
        'customer_status'=>(string)($relationship['customer_status']??''),'loyalty_status'=>(string)($relationship['loyalty_status']??''),
        'preferred_channel'=>(string)($preferences['preferred_channel']??''),'days_since_purchase'=>$daysSince,
        'tags'=>$tags,'segments'=>$segments,'metadata'=>$contactMeta,'relationship_metadata'=>$relationshipMeta,
      ],
      'loyalty'=>$loyalty,
      'rewards'=>['active_count'=>$activeRewards,'claimed_count'=>$claimedRewards],
      'campaign'=>['id'=>$campaignId,'prior_participation_count'=>$priorCampaign],
      'journey'=>['prior_completed_count'=>$priorJourney],
      'context'=>campaigns_rewards_journey_context_v121($triggerContext),
    ];
}

function campaigns_rewards_decision_value_v125(array $context,string $field): mixed
{
    $parts=explode('.',$field);$value=$context;
    foreach($parts as $part){if(!is_array($value)||!array_key_exists($part,$value))return null;$value=$value[$part];}
    return $value;
}

function campaigns_rewards_decision_compare_v125(mixed $actual,string $operator,string $expected): bool
{
    $expectedList=array_values(array_filter(array_map('trim',explode(',',$expected)),static fn($v)=>$v!==''));
    $haystack=is_array($actual)?array_map('strval',$actual):[(string)$actual];
    return match($operator){
      'equals'=>(string)$actual===$expected,
      'not_equals'=>(string)$actual!==$expected,
      'gt'=>is_numeric($actual)&&is_numeric($expected)&&(float)$actual>(float)$expected,
      'gte'=>is_numeric($actual)&&is_numeric($expected)&&(float)$actual>=(float)$expected,
      'lt'=>is_numeric($actual)&&is_numeric($expected)&&(float)$actual<(float)$expected,
      'lte'=>is_numeric($actual)&&is_numeric($expected)&&(float)$actual<=(float)$expected,
      'contains'=>is_array($actual)?in_array($expected,$haystack,true):str_contains(mb_strtolower((string)$actual),mb_strtolower($expected)),
      'not_contains'=>is_array($actual)?!in_array($expected,$haystack,true):!str_contains(mb_strtolower((string)$actual),mb_strtolower($expected)),
      'in'=>count(array_intersect($haystack,$expectedList))>0,
      'not_in'=>count(array_intersect($haystack,$expectedList))===0,
      'truthy'=>!empty($actual),
      'falsy'=>empty($actual),
      default=>false,
    };
}

function campaigns_rewards_record_decision_v125(PDO $pdo,array $base,string $type,string $decisionKey,array $context,array $rules,array $outcome,bool $holdout=false,string $status='decided'): array
{
    $merchantId=(int)$base['merchant_id'];$hash=hash('sha256',campaigns_rewards_json_v100($context));$key=campaigns_rewards_text_v100($decisionKey,190);
    $existing=$pdo->prepare("SELECT * FROM campaign_decisions WHERE merchant_id=? AND decision_key=? LIMIT 1");$existing->execute([$merchantId,$key]);$row=$existing->fetch();
    if($row){$row['context']=json_decode((string)$row['context_json'],true)?:[];$row['rules']=json_decode((string)$row['rules_json'],true)?:[];$row['outcome']=json_decode((string)$row['outcome_json'],true)?:[];return $row;}
    $pdo->prepare("INSERT INTO campaign_decisions
      (public_id,merchant_id,campaign_id,journey_id,journey_version_id,journey_instance_id,delivery_id,contact_id,step_key,decision_type,decision_key,context_hash,context_json,rules_json,outcome_json,is_holdout,status,created_at)
      VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,UTC_TIMESTAMP())")->execute([
        campaigns_rewards_uuid_v100(),$merchantId,(int)$base['campaign_id'],max(0,(int)($base['journey_id']??0))?:null,max(0,(int)($base['journey_version_id']??0))?:null,
        max(0,(int)($base['journey_instance_id']??0))?:null,max(0,(int)($base['delivery_id']??0))?:null,(int)$base['contact_id'],
        campaigns_rewards_slug_v100((string)($base['step_key']??''),80),$type,$key,$hash,campaigns_rewards_json_v100($context),campaigns_rewards_json_v100($rules),campaigns_rewards_json_v100($outcome),$holdout?1:0,$status
    ]);
    $id=(int)$pdo->lastInsertId();$q=$pdo->prepare("SELECT * FROM campaign_decisions WHERE id=?");$q->execute([$id]);$row=$q->fetch()?:[];
    $campaign=campaigns_rewards_campaign_platform_v100($pdo,(int)$base['campaign_id']);
    if($campaign)campaigns_rewards_activity_event_v100($pdo,$merchantId,'campaign.decision_recorded',['campaign_id'=>(int)$base['campaign_id'],'contact_id'=>(int)$base['contact_id']],[
      'summary'=>'Campaign decision recorded','campaign_public_id'=>$campaign['public_id'],'decision_id'=>$id,'decision_type'=>$type,
      'journey_id'=>max(0,(int)($base['journey_id']??0)),'journey_version_id'=>max(0,(int)($base['journey_version_id']??0)),
      'journey_instance_id'=>max(0,(int)($base['journey_instance_id']??0)),'step_key'=>(string)($base['step_key']??''),
      'is_holdout'=>$holdout,'reason'=>(string)($outcome['reason']??'decided'),
    ],(string)$campaign['environment'],null,'automation');
    $row['context']=$context;$row['rules']=$rules;$row['outcome']=$outcome;return $row;
}

function campaigns_rewards_journey_enqueue_v125(PDO $pdo,int $campaignId,int $contactId,string $trigger,array $context=[],string $triggerEventId=''): array
{
    return campaigns_rewards_journey_enqueue_v123($pdo,$campaignId,$contactId,$trigger,$context,$triggerEventId);
}

function campaigns_rewards_entry_template_v125(array $version,string $trigger): ?array
{
    foreach(campaigns_rewards_graph_nodes_v123($version['graph']) as $node){$t=(array)$node['template'];if(!empty($t['entry_node'])&&(string)($t['trigger_event']??'manual')===$trigger)return $t;}return null;
}

function campaigns_rewards_entry_decision_v125(PDO $pdo,array $campaign,array $journey,array $version,int $contactId,string $trigger,string $eventId,array $triggerContext=[]): array
{
    if(!campaigns_rewards_decision_schema_ready_v125($pdo))return ['allowed'=>true,'reason'=>'decision_schema_unavailable'];
    $t=campaigns_rewards_entry_template_v125($version,$trigger)??[];$ctx=campaigns_rewards_decision_context_v125($pdo,(int)$campaign['merchant_id'],(int)$campaign['id'],$contactId,$triggerContext);
    $base=['merchant_id'=>(int)$campaign['merchant_id'],'campaign_id'=>(int)$campaign['id'],'journey_id'=>(int)$journey['id'],'journey_version_id'=>(int)$version['id'],'contact_id'=>$contactId,'step_key'=>(string)($t['step_key']??'')];
    $rules=['holdout_percent'=>(int)($t['holdout_percent']??0),'conflict_group'=>(string)($t['conflict_group']??''),'conflict_window_hours'=>(int)($t['conflict_window_hours']??0),'decision_priority'=>(int)($t['decision_priority']??100)];
    $key='entry:'.hash('sha256',(int)$journey['id'].'|'.(int)$version['id'].'|'.$contactId.'|'.$eventId);
    $existing=$pdo->prepare("SELECT outcome_json,is_holdout,status FROM campaign_decisions WHERE merchant_id=? AND decision_key=? LIMIT 1");$existing->execute([(int)$campaign['merchant_id'],$key]);$old=$existing->fetch();
    if($old){$o=json_decode((string)$old['outcome_json'],true)?:[];return ['allowed'=>!empty($o['allowed']),'reason'=>(string)($o['reason']??'existing'),'holdout'=>!empty($old['is_holdout'])];}

    $holdout=max(0,min(90,(int)$rules['holdout_percent']));
    if($holdout>0){
        $bucket=(hexdec(substr(hash('sha256',(int)$campaign['id'].'|'.(int)$version['id'].'|'.$contactId),0,8))%100)+1;
        if($bucket<=$holdout){
            $outcome=['allowed'=>false,'reason'=>'holdout','bucket'=>$bucket,'holdout_percent'=>$holdout];
            campaigns_rewards_record_decision_v125($pdo,$base,'entry',$key,$ctx,$rules,$outcome,true);
            return ['allowed'=>false,'reason'=>'holdout','holdout'=>true];
        }
    }

    $group=(string)$rules['conflict_group'];$window=max(0,(int)$rules['conflict_window_hours']);
    if($group!==''&&$window>0){
        $cutoff=gmdate('Y-m-d H:i:s',time()-$window*3600);
        $q=$pdo->prepare("SELECT outcome_json,campaign_id FROM campaign_decisions WHERE merchant_id=? AND contact_id=? AND decision_type='entry' AND status='decided' AND is_holdout=0 AND created_at>=? ORDER BY id DESC LIMIT 100");
        $q->execute([(int)$campaign['merchant_id'],$contactId,$cutoff]);
        foreach($q->fetchAll()?:[] as $r){$o=json_decode((string)$r['outcome_json'],true)?:[];if((string)($o['conflict_group']??'')===$group&&!empty($o['allowed'])&&(int)$r['campaign_id']!==(int)$campaign['id']){
            $outcome=['allowed'=>false,'reason'=>'campaign_conflict','conflict_group'=>$group,'conflicting_campaign_id'=>(int)$r['campaign_id'],'priority'=>(int)$rules['decision_priority']];
            campaigns_rewards_record_decision_v125($pdo,$base,'entry',$key,$ctx,$rules,$outcome,false);
            return ['allowed'=>false,'reason'=>'campaign_conflict','holdout'=>false];
        }}
    }
    $outcome=['allowed'=>true,'reason'=>'eligible','conflict_group'=>$group,'priority'=>(int)$rules['decision_priority']];
    campaigns_rewards_record_decision_v125($pdo,$base,'entry',$key,$ctx,$rules,$outcome,false);
    return ['allowed'=>true,'reason'=>'eligible','holdout'=>false];
}

function campaigns_rewards_condition_source_v125(PDO $pdo,array $delivery,array $contact,string $field): mixed
{
    if(!isset(campaigns_rewards_decision_fields_v125()[$field]))return campaigns_rewards_condition_source_v121($pdo,$delivery,$contact,$field);
    $ctx=campaigns_rewards_decision_context_v125($pdo,(int)$delivery['merchant_id'],(int)$delivery['campaign_id'],(int)$delivery['contact_id'],(array)($delivery['metadata']['context']??[]));
    if($field==='delivery.converted')return campaigns_rewards_journey_instance_converted_v121($pdo,$delivery);
    if($field==='campaign.status')return $delivery['campaign_status']??'';
    return campaigns_rewards_decision_value_v125($ctx,$field);
}

function campaigns_rewards_record_branch_v125(PDO $pdo,array $delivery,string $field,string $operator,string $expected,mixed $actual,bool $matched,string $next): void
{
    if(!campaigns_rewards_decision_schema_ready_v125($pdo))return;
    $instanceId=max(0,(int)($delivery['metadata']['journey_instance_id']??0));
    $ctx=campaigns_rewards_decision_context_v125($pdo,(int)$delivery['merchant_id'],(int)$delivery['campaign_id'],(int)$delivery['contact_id'],(array)($delivery['metadata']['context']??[]));
    $base=['merchant_id'=>(int)$delivery['merchant_id'],'campaign_id'=>(int)$delivery['campaign_id'],'journey_id'=>max(0,(int)($delivery['metadata']['journey_id']??0)),'journey_version_id'=>max(0,(int)($delivery['metadata']['journey_version_id']??0)),'journey_instance_id'=>$instanceId,'delivery_id'=>(int)$delivery['id'],'contact_id'=>(int)$delivery['contact_id'],'step_key'=>(string)($delivery['metadata']['step_key']??'')];
    $key='branch:'.hash('sha256',(string)($delivery['metadata']['journey_instance_key']??'').'|'.(int)$delivery['id']);
    campaigns_rewards_record_decision_v125($pdo,$base,'branch',$key,$ctx,['field'=>$field,'operator'=>$operator,'expected'=>$expected],['actual'=>$actual,'matched'=>$matched,'next_step_key'=>$next]);
}

function campaigns_rewards_reward_candidates_v125(PDO $pdo,int $campaignId): array
{
    $q=$pdo->prepare("SELECT i.reward_product_id,i.variant_id,i.quantity,i.priority,i.conditions_json,rp.name,rp.claim_limit,rp.inventory_mode,rp.retail_value_minor,rp.internal_cost_minor,rp.currency,rp.is_active
      FROM campaign_reward_set_items i INNER JOIN campaign_reward_sets rs ON rs.id=i.reward_set_id
      INNER JOIN reward_products rp ON rp.id=i.reward_product_id
      WHERE rs.campaign_id=? AND rp.is_active=1 ORDER BY i.priority,i.reward_product_id,i.variant_id");
    $q->execute([$campaignId]);return $q->fetchAll()?:[];
}

function campaigns_rewards_reward_candidate_matches_v125(array $candidate,array $context): bool
{
    $conditions=json_decode((string)($candidate['conditions_json']??''),true);if(!is_array($conditions)||!$conditions)return true;
    $rules=isset($conditions['all'])&&is_array($conditions['all'])?$conditions['all']:[$conditions];
    foreach($rules as $rule){if(!is_array($rule))continue;$field=(string)($rule['field']??'');if($field==='')continue;$actual=campaigns_rewards_decision_value_v125($context,$field);if(!campaigns_rewards_decision_compare_v125($actual,(string)($rule['operator']??'equals'),(string)($rule['value']??'')))return false;}
    return true;
}

function campaigns_rewards_select_offer_v125(PDO $pdo,array $delivery,array $template,array $context): array
{
    $mode=(string)($template['offer_mode']??'none');if($mode==='none')return ['selected'=>false,'reason'=>'disabled'];
    $specific=max(0,(int)($template['offer_reward_product_id']??0));$eligible=[];
    foreach(campaigns_rewards_reward_candidates_v125($pdo,(int)$delivery['campaign_id']) as $candidate){
        $rewardId=(int)$candidate['reward_product_id'];if($mode==='specific'&&$rewardId!==$specific)continue;
        if(!campaigns_rewards_reward_candidate_matches_v125($candidate,$context))continue;
        $q=$pdo->prepare("SELECT COUNT(*) FROM reward_issuances WHERE reward_product_id=? AND recipient_contact_id=? AND status NOT IN ('voided','expired')");$q->execute([$rewardId,(int)$delivery['contact_id']]);
        if((int)$q->fetchColumn()>=max(1,(int)$candidate['claim_limit']))continue;
        if((string)$candidate['inventory_mode']==='tracked'){$q=$pdo->prepare("SELECT COALESCE(SUM(on_hand-reserved),0) FROM reward_inventory_balances WHERE reward_product_id=?");$q->execute([$rewardId]);if((int)$q->fetchColumn()<(int)$candidate['quantity'])continue;}
        $eligible[]=$candidate;
    }
    if(!$eligible)return ['selected'=>false,'reason'=>'no_eligible_offer'];
    $bestPriority=(int)$eligible[0]['priority'];$pool=array_values(array_filter($eligible,static fn($r)=>(int)$r['priority']===$bestPriority));
    $seed=(string)($delivery['metadata']['journey_instance_key']??'')."|".(string)($delivery['metadata']['step_key']??'')."|".(int)$delivery['contact_id'];
    $index=hexdec(substr(hash('sha256',$seed),0,8))%count($pool);$selected=$pool[$index];
    return ['selected'=>true,'reason'=>'eligible','reward_product_id'=>(int)$selected['reward_product_id'],'variant_id'=>(int)$selected['variant_id'],'quantity'=>(int)$selected['quantity'],'name'=>(string)$selected['name'],'retail_value_minor'=>$selected['retail_value_minor']===null?null:(int)$selected['retail_value_minor'],'currency'=>(string)$selected['currency'],'candidate_count'=>count($eligible)];
}

function campaigns_rewards_decision_validate_issue_v125(PDO $pdo,int $campaignId,int $rewardProductId,int $contactId,int $decisionId): array
{
    if($decisionId<1)throw new RuntimeException('A governed Decision record is required for dynamic Reward issuance.');
    $q=$pdo->prepare("SELECT * FROM campaign_decisions WHERE id=? AND campaign_id=? AND contact_id=? AND decision_type='offer' AND status='decided' AND is_holdout=0 LIMIT 1");
    $q->execute([$decisionId,$campaignId,$contactId]);$row=$q->fetch()?:throw new RuntimeException('Dynamic Reward Decision is unavailable.');
    $outcome=json_decode((string)$row['outcome_json'],true)?:[];
    if(empty($outcome['selected'])||(int)($outcome['reward_product_id']??0)!==$rewardProductId)throw new RuntimeException('Dynamic Reward Decision does not authorize this Reward.');
    if(!in_array($rewardProductId,campaigns_rewards_campaign_reward_ids_v118($pdo,$campaignId),true))throw new RuntimeException('Decision Reward is not attached to the Campaign.');
    return $row;
}

function campaigns_rewards_offer_decision_v125(PDO $pdo,array $delivery,array $template,array $contact,array $message): array
{
    $context=campaigns_rewards_decision_context_v125($pdo,(int)$delivery['merchant_id'],(int)$delivery['campaign_id'],(int)$delivery['contact_id'],(array)($delivery['metadata']['context']??[]));
    $instanceId=max(0,(int)($delivery['metadata']['journey_instance_id']??0));$base=['merchant_id'=>(int)$delivery['merchant_id'],'campaign_id'=>(int)$delivery['campaign_id'],'journey_id'=>max(0,(int)($delivery['metadata']['journey_id']??0)),'journey_version_id'=>max(0,(int)($delivery['metadata']['journey_version_id']??0)),'journey_instance_id'=>$instanceId,'delivery_id'=>(int)$delivery['id'],'contact_id'=>(int)$delivery['contact_id'],'step_key'=>(string)($delivery['metadata']['step_key']??'')];
    $key='offer:'.hash('sha256',(string)($delivery['metadata']['journey_instance_key']??'').'|'.(int)$delivery['id']);
    $q=$pdo->prepare("SELECT * FROM campaign_decisions WHERE merchant_id=? AND decision_key=? LIMIT 1");$q->execute([(int)$delivery['merchant_id'],$key]);$existing=$q->fetch();
    if($existing){$offer=json_decode((string)$existing['outcome_json'],true)?:[];$decision=$existing;}
    else{$offer=campaigns_rewards_select_offer_v125($pdo,$delivery,$template,$context);
      $decision=campaigns_rewards_record_decision_v125($pdo,$base,'offer',$key,$context,['offer_mode'=>(string)($template['offer_mode']??'none'),'offer_action'=>(string)($template['offer_action']??'select'),'specific_reward_id'=>max(0,(int)($template['offer_reward_product_id']??0))],$offer,false);
    }
    if(!empty($offer['selected'])&&(string)($template['offer_action']??'select')==='issue'&&empty($offer['reward_issuance_id'])){
        $issuance=campaigns_rewards_issue_reward_v100($pdo,(int)$delivery['campaign_id'],(int)$offer['reward_product_id'],(int)$delivery['contact_id'],0,[
          'actor_type'=>'decision','decision_id'=>(int)$decision['id'],'source'=>'journey_decision',
          'reward_variant_id'=>max(0,(int)($offer['variant_id']??0)),'quantity'=>max(1,(int)($offer['quantity']??1)),
          'idempotency_key'=>'decision-offer:'.(int)$decision['id'],
        ]);
        $offer['reward_issuance_id']=(int)$issuance['id'];
        $pdo->prepare("UPDATE campaign_deliveries SET reward_issuance_id=?,metadata_json=? WHERE id=?")->execute([
          (int)$issuance['id'],
          campaigns_rewards_json_v100(array_merge($delivery['metadata'],['decision_id'=>(int)$decision['id'],'selected_reward_product_id'=>(int)$offer['reward_product_id'],'reward_issuance_id'=>(int)$issuance['id']])),
          (int)$delivery['id']
        ]);
    }
    return ['decision'=>$decision,'outcome'=>$offer,'context'=>$context];
}

function campaigns_rewards_flatten_tokens_v125(array $value,string $prefix='',array &$out=[]): array
{
    foreach($value as $key=>$item){$name=$prefix===''?(string)$key:$prefix.'.'.$key;if(is_array($item)){if(array_is_list($item))$out['{{'.$name.'}}']=implode(', ',array_map('strval',$item));else campaigns_rewards_flatten_tokens_v125($item,$name,$out);}elseif(is_scalar($item)||$item===null)$out['{{'.$name.'}}']=(string)($item??'');}
    return $out;
}

function campaigns_rewards_render_personalized_v125(string $text,array $tokens,array $context): string
{
    $text=preg_replace_callback('/\{\{#if\s+([a-zA-Z0-9_.-]+)\}\}(.*?)\{\{\/if\}\}/s',static function($m)use($context){return !empty(campaigns_rewards_decision_value_v125($context,$m[1]))?$m[2]:'';},$text)??$text;
    $text=preg_replace_callback('/\{\{#unless\s+([a-zA-Z0-9_.-]+)\}\}(.*?)\{\{\/unless\}\}/s',static function($m)use($context){return empty(campaigns_rewards_decision_value_v125($context,$m[1]))?$m[2]:'';},$text)??$text;
    return strtr($text,$tokens);
}

function campaigns_rewards_prepare_message_v125(PDO $pdo,array $delivery,array $contact,array $message): array
{
    $template=(array)($message['template']??[]);$decision=null;$offer=[];$context=campaigns_rewards_decision_context_v125($pdo,(int)$delivery['merchant_id'],(int)$delivery['campaign_id'],(int)$delivery['contact_id'],(array)($delivery['metadata']['context']??[]));
    if(campaigns_rewards_decision_schema_ready_v125($pdo)&&(string)($template['offer_mode']??'none')!=='none'){$od=campaigns_rewards_offer_decision_v125($pdo,$delivery,$template,$contact,$message);$decision=$od['decision'];$offer=$od['outcome'];$context=$od['context'];}
    $ctx=campaigns_rewards_message_context_v120($pdo,$delivery,$contact,$message);
    if(!empty($offer['reward_issuance_id'])){$delivery=campaigns_rewards_delivery_v120($pdo,(int)$delivery['id'])?:$delivery;$ctx=campaigns_rewards_message_context_v120($pdo,$delivery,$contact,$message);}
    $context['offer']=$offer;$tokens=campaigns_rewards_flatten_tokens_v125($context);
    $tokens+=['{{offer_name}}'=>(string)($offer['name']??''),'{{offer_value_minor}}'=>(string)($offer['retail_value_minor']??''),'{{offer_currency}}'=>(string)($offer['currency']??'')];
    $ctx['tokens']=array_merge($ctx['tokens'],$tokens);$ctx['decision_context']=$context;$ctx['offer']=$offer;$ctx['decision']=$decision;
    return ['delivery'=>$delivery,'ctx'=>$ctx,'subject'=>campaigns_rewards_render_personalized_v125((string)$delivery['subject'],$ctx['tokens'],$context),'body'=>campaigns_rewards_render_personalized_v125((string)$delivery['body'],$ctx['tokens'],$context)];
}

function campaigns_rewards_decisions_v125(PDO $pdo,int $merchantId,int $campaignId=0,int $journeyId=0,int $limit=100): array
{
    $limit=max(1,min(500,$limit));$sql="SELECT d.*,c.name campaign_name,j.name journey_name,cc.name contact_name,cc.email contact_email
      FROM campaign_decisions d INNER JOIN campaigns c ON c.id=d.campaign_id
      LEFT JOIN campaign_journeys j ON j.id=d.journey_id LEFT JOIN crm_contacts cc ON cc.id=d.contact_id
      WHERE d.merchant_id=?";$params=[$merchantId];
    if($campaignId>0){$sql.=" AND d.campaign_id=?";$params[]=$campaignId;}if($journeyId>0){$sql.=" AND d.journey_id=?";$params[]=$journeyId;}
    $sql.=" ORDER BY d.id DESC LIMIT {$limit}";$q=$pdo->prepare($sql);$q->execute($params);$rows=$q->fetchAll()?:[];
    foreach($rows as &$row){$row['context']=json_decode((string)$row['context_json'],true)?:[];$row['rules']=json_decode((string)$row['rules_json'],true)?:[];$row['outcome']=json_decode((string)$row['outcome_json'],true)?:[];}unset($row);return $rows;
}

function campaigns_rewards_validate_decision_graph_v125(PDO $pdo,int $journeyId,array $graph): array
{
    $journey=campaigns_rewards_journey_v123($pdo,$journeyId);$errors=[];$warnings=[];
    if(!$journey)return ['errors'=>[['code'=>'decision_journey_missing','message'=>'Journey is unavailable for decision validation.']],'warnings'=>[]];
    $attached=campaigns_rewards_campaign_reward_ids_v118($pdo,(int)$journey['campaign_id']);
    foreach(campaigns_rewards_graph_nodes_v123($graph) as $node){
        $t=(array)$node['template'];$step=(string)($t['step_key']??'step');$type=(string)($t['node_type']??'message');
        $field=(string)($t['decision_field']??'');$offer=(string)($t['offer_mode']??'none');$offerAction=(string)($t['offer_action']??'select');
        if($field!==''&&!isset(campaigns_rewards_decision_fields_v125()[$field]))$errors[]=['code'=>'invalid_decision_field','message'=>"{$step} uses an unsupported V1.25 decision field."];
        if($field!==''&&$type!=='decision')$warnings[]=['code'=>'decision_field_non_branch','message'=>"{$step} has a decision field but is not a Decision node; that branch rule will not execute."];
        if($offer!=='none'&&$type!=='message')$errors[]=['code'=>'offer_non_message','message'=>"{$step} configures an offer but is not a Message node."];
        if($offer==='specific'){
            $rewardId=max(0,(int)($t['offer_reward_product_id']??0));
            if($rewardId<1)$errors[]=['code'=>'specific_offer_missing','message'=>"{$step} requires a specific attached Reward."];
            elseif(!in_array($rewardId,$attached,true))$errors[]=['code'=>'specific_offer_not_attached','message'=>"{$step} references Reward #{$rewardId}, which is not attached to this Campaign."];
        }
        if($offerAction==='issue'&&$offer==='none')$errors[]=['code'=>'offer_issue_without_selection','message'=>"{$step} cannot issue a dynamic Reward when offer selection is disabled."];
        if(max(0,(int)($t['holdout_percent']??0))>0&&empty($t['entry_node']))$warnings[]=['code'=>'holdout_non_entry','message'=>"{$step} has a holdout percentage, but holdout only applies to entry nodes."];
        $group=(string)($t['conflict_group']??'');$window=max(0,(int)($t['conflict_window_hours']??0));
        if($group!==''&&$window<1)$warnings[]=['code'=>'conflict_window_missing','message'=>"{$step} has a conflict group with no conflict window, so no conflict suppression will occur."];
        if($group===''&&$window>0)$warnings[]=['code'=>'conflict_group_missing','message'=>"{$step} has a conflict window but no conflict group."];
        if(!empty($t['personalization_enabled'])&&$type!=='message')$warnings[]=['code'=>'personalization_non_message','message'=>"{$step} enables message personalization on a non-message node."];
    }
    return ['errors'=>$errors,'warnings'=>$warnings];
}

function campaigns_rewards_preview_decision_v125(PDO $pdo,int $journeyId,int $contactId,int $actorUserId,array $triggerContext=[]): array
{
    $journey=campaigns_rewards_journey_v123($pdo,$journeyId)?:throw new RuntimeException('Journey not found.');campaigns_rewards_platform_assert_can_v100($pdo,(int)$journey['merchant_id'],$actorUserId,'campaigns.view');
    $version=(int)$journey['current_draft_version_id']>0?campaigns_rewards_journey_version_v123($pdo,(int)$journey['current_draft_version_id']):campaigns_rewards_journey_version_v123($pdo,(int)$journey['current_published_version_id']);
    if(!$version)throw new RuntimeException('Journey has no release to preview.');
    $ctx=campaigns_rewards_decision_context_v125($pdo,(int)$journey['merchant_id'],(int)$journey['campaign_id'],$contactId,$triggerContext);$nodes=[];
    foreach(campaigns_rewards_graph_nodes_v123($version['graph']) as $node){$t=(array)$node['template'];$item=['step_key'=>(string)($t['step_key']??''),'node_type'=>(string)($t['node_type']??'message')];
      $field=(string)($t['decision_field']??'');if($field!==''){$actual=campaigns_rewards_decision_value_v125($ctx,$field);$item['decision']=['field'=>$field,'operator'=>(string)($t['decision_operator']??'equals'),'expected'=>(string)($t['decision_value']??''),'actual'=>$actual,'matched'=>campaigns_rewards_decision_compare_v125($actual,(string)($t['decision_operator']??'equals'),(string)($t['decision_value']??''))];}
      if((string)($t['offer_mode']??'none')!=='none'){$fake=['campaign_id'=>(int)$journey['campaign_id'],'contact_id'=>$contactId,'metadata'=>['journey_instance_key'=>'preview:'.$journeyId.':'.$contactId,'step_key'=>(string)($t['step_key']??'')]];$item['offer']=campaigns_rewards_select_offer_v125($pdo,$fake,$t,$ctx);}
      $nodes[]=$item;
    }
    return ['dry_run'=>true,'journey_id'=>$journeyId,'journey_version_id'=>(int)$version['id'],'version_no'=>(int)$version['version_no'],'contact_id'=>$contactId,'context'=>$ctx,'nodes'=>$nodes];
}

function campaigns_rewards_refresh_decision_recommendations_v125(PDO $pdo,int $merchantId=0): array
{
    $sql="SELECT c.id,c.merchant_id,m.owner_user_id,c.public_id FROM campaigns c INNER JOIN merchant_accounts m ON m.id=c.merchant_id WHERE c.status='active' AND c.environment='production'";
    $params=[];if($merchantId>0){$sql.=" AND c.merchant_id=?";$params[]=$merchantId;}$q=$pdo->prepare($sql);$q->execute($params);$created=0;$reviewed=0;
    foreach($q->fetchAll()?:[] as $campaign){$reviewed++;$cid=(int)$campaign['id'];$owner=(int)$campaign['owner_user_id'];if($owner<1)continue;
      $stats=$pdo->prepare("SELECT decision_type,is_holdout,outcome_json FROM campaign_decisions WHERE campaign_id=? AND created_at>=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 30 DAY) ORDER BY id DESC LIMIT 1000");$stats->execute([$cid]);
      $noOffer=0;$conflicts=0;$holdouts=0;$totalEntry=0;foreach($stats->fetchAll()?:[] as $d){$o=json_decode((string)$d['outcome_json'],true)?:[];if($d['decision_type']==='offer'&&empty($o['selected']))$noOffer++;if($d['decision_type']==='entry'){$totalEntry++;if(!empty($d['is_holdout']))$holdouts++;if(($o['reason']??'')==='campaign_conflict')$conflicts++;}}
      $recommendations=[];if($noOffer>=10)$recommendations[]=['type'=>'decision.offer_eligibility','summary'=>"{$noOffer} recent offer decisions found no eligible Reward. Review Reward eligibility, inventory, limits, or attached Reward conditions."];
      if($conflicts>=10)$recommendations[]=['type'=>'decision.conflict_policy','summary'=>"{$conflicts} recent contacts were suppressed by Campaign conflict rules. Review conflict groups and priority strategy."];
      if($totalEntry>=20&&$holdouts/$totalEntry>0.5)$recommendations[]=['type'=>'decision.holdout','summary'=>'More than half of recent eligible contacts are entering holdout. Review whether the configured control-group percentage matches the measurement plan.'];
      foreach($recommendations as $rec){$e=$pdo->prepare("SELECT id FROM campaign_agent_recommendations WHERE merchant_id=? AND campaign_id=? AND recommendation_type=? AND status='proposed' AND created_at>=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 7 DAY) LIMIT 1");$e->execute([(int)$campaign['merchant_id'],$cid,$rec['type']]);if($e->fetchColumn())continue;
        $pdo->prepare("INSERT INTO campaign_agent_recommendations (public_id,merchant_id,campaign_id,recommendation_type,summary,status,evidence_refs_json,impact_preview_json,created_for_user_id)
          VALUES (?,?,?,?,?,'proposed',?,?,?)")->execute([campaigns_rewards_uuid_v100(),(int)$campaign['merchant_id'],$cid,$rec['type'],$rec['summary'],campaigns_rewards_json_v100(['source'=>'v125_decision_ledger','window_days'=>30]),campaigns_rewards_json_v100(['requires_human_decision'=>true,'auto_apply'=>false]),$owner]);$created++;
      }
    }
    return ['campaigns_reviewed'=>$reviewed,'recommendations_created'=>$created];
}

function campaigns_rewards_dispatch_due_v125(PDO $pdo,int $merchantId=0,int $limit=200): array
{
    return campaigns_rewards_dispatch_due_v124($pdo,$merchantId,$limit);
}

function campaigns_rewards_run_due_v125(PDO $pdo,int $merchantId=0): array
{
    $base=campaigns_rewards_run_due_v124($pdo,$merchantId);$base['decision_recommendations']=campaigns_rewards_refresh_decision_recommendations_v125($pdo,$merchantId);return $base;
}
