<?php
declare(strict_types=1);

const VP3_CAMPAIGNS_REWARDS_V119='vp3-campaigns-rewards-v119-20260923';

function campaigns_rewards_automation_triggers_v119(): array
{
    return [
        'birthday_trigger'=>['label'=>'Birthday window','mode'=>'scheduled','description'=>'Evaluate CRM birthdays during the configured daily window.'],
        'crm_lapse'=>['label'=>'Win-back inactivity','mode'=>'scheduled','description'=>'Evaluate customers whose last purchase/activity is older than the configured threshold.'],
        'purchase_completed'=>['label'=>'Purchase completed','mode'=>'event','description'=>'Run from a verified VP3 Commerce paid-order event.'],
        'referral_qualified'=>['label'=>'Referral qualified','mode'=>'event','description'=>'Run when a referred contact reaches the configured qualifying outcome.'],
        'winner_selected'=>['label'=>'Contest winner selected','mode'=>'event','description'=>'Run only for a governed selected winner.'],
        'attendance_confirmed'=>['label'=>'Attendance confirmed','mode'=>'event','description'=>'Run after merchant/event attendance verification.'],
        'proof_approved'=>['label'=>'Proof approved','mode'=>'event','description'=>'Run after Social, UGC or Training proof is approved.'],
        'loyalty_milestone'=>['label'=>'Loyalty milestone','mode'=>'event','description'=>'Run when a canonical Loyalty balance/tier milestone is reached.'],
        'product_available'=>['label'=>'Product/offer available','mode'=>'event','description'=>'Run for previously captured pre-purchase/interest contacts.'],
        'allocation_approved'=>['label'=>'Community allocation approved','mode'=>'event','description'=>'Run after a governed community/donation allocation is approved.'],
        'agent_action'=>['label'=>'Approved Agent action','mode'=>'manual','description'=>'Run only after an authorized user or governed Agent action invokes it.'],
        'manual'=>['label'=>'Manual trigger','mode'=>'manual','description'=>'Run an explicitly selected contact through the configured rule.'],
    ];
}

function campaigns_rewards_automation_audiences_v119(): array
{
    return [
        'all_contacts'=>'All Merchant contacts',
        'prospects'=>'Prospects',
        'customers'=>'Customers',
        'inactive_customers'=>'Inactive customers',
        'vip'=>'VIP / loyalty contacts',
        'birthday_window'=>'Birthday window',
        'saved_segment'=>'Saved CRM segment',
        'unclaimed_reward'=>'Unclaimed Reward holders',
        'prior_participants'=>'Previous Campaign participants',
        'event_contact'=>'Contact from triggering event',
    ];
}

function campaigns_rewards_automation_rules_v119(PDO $pdo,int $merchantId,int $campaignId=0): array
{
    $sql="SELECT r.*,c.name campaign_name,c.status campaign_status,ct.type_key campaign_type_key
      FROM campaign_automation_rules r
      LEFT JOIN campaigns c ON c.id=r.campaign_id
      LEFT JOIN campaign_types ct ON ct.id=c.campaign_type_id
      WHERE r.merchant_id=?";
    $params=[$merchantId];
    if($campaignId>0){$sql.=" AND r.campaign_id=?";$params[]=$campaignId;}
    $sql.=" ORDER BY r.updated_at DESC,r.id DESC";
    $stmt=$pdo->prepare($sql);$stmt->execute($params);
    $rows=$stmt->fetchAll()?:[];
    foreach($rows as &$row){
        $row['conditions']=json_decode((string)($row['conditions_json']??''),true)?:[];
        $row['actions']=json_decode((string)($row['actions_json']??''),true)?:[];
    }unset($row);
    return $rows;
}

function campaigns_rewards_automation_rule_v119(PDO $pdo,int $ruleId,bool $forUpdate=false): ?array
{
    if($ruleId<1)return null;
    $stmt=$pdo->prepare("SELECT r.*,c.status campaign_status,c.environment,c.starts_at,c.ends_at,c.current_version_no,
      c.public_id campaign_public_id,m.owner_user_id,m.public_id merchant_public_id,m.status merchant_status
      FROM campaign_automation_rules r
      INNER JOIN campaigns c ON c.id=r.campaign_id
      INNER JOIN merchant_accounts m ON m.id=r.merchant_id
      WHERE r.id=? LIMIT 1".($forUpdate?' FOR UPDATE':''));
    $stmt->execute([$ruleId]);$row=$stmt->fetch();if(!$row)return null;
    $row['conditions']=json_decode((string)($row['conditions_json']??''),true)?:[];
    $row['actions']=json_decode((string)($row['actions_json']??''),true)?:[];
    return $row;
}

function campaigns_rewards_automation_default_trigger_v119(string $campaignType): string
{
    return match($campaignType){
        'birthday_vip'=>'birthday_trigger',
        'win_back'=>'crm_lapse',
        'post_purchase'=>'purchase_completed',
        'pre_purchase'=>'product_available',
        'referral'=>'referral_qualified',
        'contest_giveaway'=>'winner_selected',
        'local_event'=>'attendance_confirmed',
        'social_engagement','ugc_story','training_education'=>'proof_approved',
        'loyalty'=>'loyalty_milestone',
        'public_donation'=>'allocation_approved',
        'agent_offer'=>'agent_action',
        default=>'manual',
    };
}

function campaigns_rewards_automation_default_audience_v119(string $campaignType): string
{
    return match($campaignType){
        'birthday_vip'=>'birthday_window',
        'win_back'=>'inactive_customers',
        'loyalty'=>'vip',
        'post_purchase'=>'event_contact',
        'referral','contest_giveaway','local_event','social_engagement','ugc_story','training_education','public_donation'=>'event_contact',
        'pre_purchase'=>'prior_participants',
        default=>'all_contacts',
    };
}

function campaigns_rewards_automation_save_rule_v119(PDO $pdo,int $merchantId,int $campaignId,int $actorUserId,array $input,int $ruleId=0): array
{
    campaigns_rewards_platform_assert_can_v100($pdo,$merchantId,$actorUserId,'campaigns.edit');
    $campaign=campaigns_rewards_campaign_platform_v100($pdo,$campaignId)?:throw new RuntimeException('Campaign not found.');
    if((int)$campaign['merchant_id']!==$merchantId)throw new RuntimeException('Campaign not found.');
    $trigger=trim((string)($input['trigger_event']??campaigns_rewards_automation_default_trigger_v119((string)$campaign['campaign_type_key'])));
    if(!isset(campaigns_rewards_automation_triggers_v119()[$trigger]))throw new RuntimeException('Choose a supported automation trigger.');
    $audience=trim((string)($input['audience_mode']??campaigns_rewards_automation_default_audience_v119((string)$campaign['campaign_type_key'])));
    if(!isset(campaigns_rewards_automation_audiences_v119()[$audience]))throw new RuntimeException('Choose a supported CRM audience.');
    $rewardId=max(0,(int)($input['reward_product_id']??0));
    if($rewardId<1||!in_array($rewardId,campaigns_rewards_campaign_reward_ids_v118($pdo,$campaignId),true))throw new RuntimeException('Choose a Reward attached to this Campaign.');
    $segmentId=max(0,(int)($input['crm_segment_id']??0));
    if($audience==='saved_segment'){
        $merchant=campaigns_rewards_platform_merchant_v100($pdo,$merchantId)?:throw new RuntimeException('Merchant not found.');
        $q=$pdo->prepare("SELECT id FROM crm_segments WHERE id=? AND owner_user_id=? AND status='active' LIMIT 1");$q->execute([$segmentId,(int)$merchant['owner_user_id']]);
        if(!(int)$q->fetchColumn())throw new RuntimeException('Choose an active CRM segment owned by this Merchant workspace.');
    }else $segmentId=0;
    $status=in_array((string)($input['status']??'draft'),['draft','active','paused'],true)?(string)$input['status']:'draft';
    if($status==='active'){
        campaigns_rewards_platform_assert_can_v100($pdo,$merchantId,$actorUserId,'campaigns.publish');
        campaigns_rewards_platform_assert_can_v100($pdo,$merchantId,$actorUserId,'rewards.issue');
        if((string)$campaign['status']!=='active')throw new RuntimeException('Activate the Campaign before activating its automation.');
    }
    $name=campaigns_rewards_text_v100($input['name']??'Campaign automation',190);if($name==='')$name='Campaign automation';
    $conditions=[
        'audience_mode'=>$audience,
        'crm_segment_id'=>$segmentId?:null,
        'inactive_days'=>max(1,min(3650,(int)($input['inactive_days']??30))),
        'birthday_window_days'=>max(0,min(31,(int)($input['birthday_window_days']??0))),
        'cooldown_days'=>max(0,min(3650,(int)($input['cooldown_days']??90))),
        'marketing_only'=>!empty($input['marketing_only']),
        'max_actions_per_run'=>max(1,min(1000,(int)($input['max_actions_per_run']??100))),
        'minimum_purchase_minor'=>max(0,(int)($input['minimum_purchase_minor']??0)),
        'minimum_points'=>max(0,(int)($input['minimum_points']??0)),
    ];
    $recipientMode=in_array((string)($input['recipient_mode']??'event_contact'),['event_contact','referrer','both'],true)?(string)$input['recipient_mode']:'event_contact';
    $actions=['action'=>'issue_reward','reward_product_id'=>$rewardId,'complete_enrollment'=>true,'recipient_mode'=>$recipientMode];
    if($ruleId>0){
        $row=campaigns_rewards_automation_rule_v119($pdo,$ruleId,true)?:throw new RuntimeException('Automation rule not found.');
        if((int)$row['merchant_id']!==$merchantId||(int)$row['campaign_id']!==$campaignId)throw new RuntimeException('Automation rule not found.');
        $pdo->prepare("UPDATE campaign_automation_rules SET name=?,trigger_event=?,conditions_json=?,actions_json=?,status=?,updated_at=UTC_TIMESTAMP() WHERE id=?")
          ->execute([$name,$trigger,campaigns_rewards_json_v100($conditions),campaigns_rewards_json_v100($actions),$status,$ruleId]);
    }else{
        $pdo->prepare("INSERT INTO campaign_automation_rules (merchant_id,campaign_id,name,trigger_event,conditions_json,actions_json,status,created_by_user_id)
          VALUES (?,?,?,?,?,?,?,?)")->execute([$merchantId,$campaignId,$name,$trigger,campaigns_rewards_json_v100($conditions),campaigns_rewards_json_v100($actions),$status,$actorUserId]);
        $ruleId=(int)$pdo->lastInsertId();
    }
    campaigns_rewards_activity_event_v100($pdo,$merchantId,'campaign.automation_saved',['campaign_id'=>$campaignId],[
        'summary'=>'Campaign automation saved','campaign_public_id'=>$campaign['public_id'],'automation_rule_id'=>$ruleId,'trigger'=>$trigger,'status'=>$status,
    ],(string)$campaign['environment'],$actorUserId);
    return campaigns_rewards_automation_rule_v119($pdo,$ruleId)?:throw new RuntimeException('Automation rule could not be loaded.');
}

function campaigns_rewards_automation_set_status_v119(PDO $pdo,int $ruleId,int $actorUserId,string $status): array
{
    if(!in_array($status,['draft','active','paused'],true))throw new RuntimeException('Choose a valid automation status.');
    $row=campaigns_rewards_automation_rule_v119($pdo,$ruleId,true)?:throw new RuntimeException('Automation rule not found.');
    campaigns_rewards_platform_assert_can_v100($pdo,(int)$row['merchant_id'],$actorUserId,'campaigns.edit');
    if($status==='active'){
        campaigns_rewards_platform_assert_can_v100($pdo,(int)$row['merchant_id'],$actorUserId,'campaigns.publish');
        campaigns_rewards_platform_assert_can_v100($pdo,(int)$row['merchant_id'],$actorUserId,'rewards.issue');
        if((string)$row['campaign_status']!=='active')throw new RuntimeException('Activate the Campaign before activating its automation.');
    }
    $pdo->prepare("UPDATE campaign_automation_rules SET status=?,updated_at=UTC_TIMESTAMP() WHERE id=?")->execute([$status,$ruleId]);
    return campaigns_rewards_automation_rule_v119($pdo,$ruleId)?:throw new RuntimeException('Automation rule unavailable.');
}

function campaigns_rewards_automation_crm_segments_v119(PDO $pdo,int $merchantId): array
{
    $merchant=campaigns_rewards_platform_merchant_v100($pdo,$merchantId);if(!$merchant)return [];
    $stmt=$pdo->prepare("SELECT s.*,COUNT(sm.contact_id) member_count FROM crm_segments s
      LEFT JOIN crm_segment_members sm ON sm.segment_id=s.id
      WHERE s.owner_user_id=? AND s.status='active' GROUP BY s.id ORDER BY s.name,s.id");
    $stmt->execute([(int)$merchant['owner_user_id']]);return $stmt->fetchAll()?:[];
}

function campaigns_rewards_referral_referrer_v119(PDO $pdo,int $merchantId,string $reference): int
{
    $reference=trim($reference);if($reference==='')return 0;
    $merchant=campaigns_rewards_platform_merchant_v100($pdo,$merchantId);if(!$merchant)return 0;
    $q=$pdo->prepare("SELECT c.id FROM crm_contacts c INNER JOIN crm_merchant_relationships r ON r.contact_id=c.id AND r.merchant_id=?
      WHERE c.owner_user_id=? AND (c.public_id=? OR c.email_normalized=?) LIMIT 1");
    $q->execute([$merchantId,(int)$merchant['owner_user_id'],$reference,strtolower($reference)]);
    return (int)$q->fetchColumn();
}

function campaigns_rewards_automation_event_contact_v119(PDO $pdo,int $merchantId,array $payload): int
{
    $contactId=max(0,(int)($payload['contact_id']??0));
    if($contactId>0){
        $q=$pdo->prepare("SELECT contact_id FROM crm_merchant_relationships WHERE merchant_id=? AND contact_id=? LIMIT 1");$q->execute([$merchantId,$contactId]);
        return (int)$q->fetchColumn();
    }
    $email=strtolower(trim((string)($payload['email']??$payload['payer_email']??'')));if($email==='')return 0;
    $merchant=campaigns_rewards_platform_merchant_v100($pdo,$merchantId);if(!$merchant)return 0;
    $q=$pdo->prepare("SELECT c.id FROM crm_contacts c INNER JOIN crm_merchant_relationships r ON r.contact_id=c.id AND r.merchant_id=?
      WHERE c.owner_user_id=? AND c.email_normalized=? LIMIT 1");
    $q->execute([$merchantId,(int)$merchant['owner_user_id'],$email]);return (int)$q->fetchColumn();
}

function campaigns_rewards_automation_contact_matches_v119(PDO $pdo,int $merchantId,int $contactId,array $conditions): bool
{
    if($contactId<1)return false;
    $q=$pdo->prepare("SELECT r.*,c.status contact_status,c.metadata_json contact_metadata FROM crm_merchant_relationships r
      INNER JOIN crm_contacts c ON c.id=r.contact_id WHERE r.merchant_id=? AND r.contact_id=? LIMIT 1");
    $q->execute([$merchantId,$contactId]);$row=$q->fetch();if(!$row)return false;
    if(!empty($conditions['marketing_only'])&&(string)$row['marketing_status']!=='subscribed')return false;
    $mode=(string)($conditions['audience_mode']??'all_contacts');
    if($mode==='prospects'&&(string)$row['customer_status']!=='prospect')return false;
    if($mode==='customers'&&(string)$row['customer_status']!=='customer')return false;
    if($mode==='vip'&&(string)$row['loyalty_status']==='')return false;
    if($mode==='inactive_customers'){
        if((string)$row['customer_status']!=='customer')return false;
        $days=max(1,(int)($conditions['inactive_days']??30));$last=strtotime((string)($row['last_purchase_at']??''))?:0;
        if($last>0&&$last>time()-($days*86400))return false;
    }
    if($mode==='saved_segment'){
        $segmentId=max(0,(int)($conditions['crm_segment_id']??0));if($segmentId<1)return false;
        $s=$pdo->prepare("SELECT 1 FROM crm_segment_members WHERE segment_id=? AND contact_id=? LIMIT 1");$s->execute([$segmentId,$contactId]);if(!$s->fetchColumn())return false;
    }
    if($mode==='unclaimed_reward'){
        $s=$pdo->prepare("SELECT 1 FROM reward_issuances WHERE merchant_id=? AND recipient_contact_id=? AND status IN ('issued','sent','viewed') LIMIT 1");$s->execute([$merchantId,$contactId]);if(!$s->fetchColumn())return false;
    }
    if($mode==='prior_participants'){
        $s=$pdo->prepare("SELECT 1 FROM campaign_enrollments e INNER JOIN campaigns c ON c.id=e.campaign_id WHERE c.merchant_id=? AND e.contact_id=? LIMIT 1");$s->execute([$merchantId,$contactId]);if(!$s->fetchColumn())return false;
    }
    if($mode==='birthday_window'){
        $meta=json_decode((string)($row['metadata_json']??''),true);if(!is_array($meta))$meta=[];
        $birthday=trim((string)($meta['birthday']??''));$ts=$birthday!==''?strtotime($birthday):false;if(!$ts)return false;
        $window=max(0,min(31,(int)($conditions['birthday_window_days']??0)));$today=new DateTimeImmutable('today',new DateTimeZone('UTC'));
        $year=(int)$today->format('Y');$month=(int)gmdate('n',$ts);$day=(int)gmdate('j',$ts);
        try{$candidate=new DateTimeImmutable(sprintf('%04d-%02d-%02d',$year,$month,$day),new DateTimeZone('UTC'));}catch(Throwable $e){return false;}
        $diff=(int)$today->diff($candidate)->format('%r%a');
        if(abs($diff)>$window)return false;
    }
    return true;
}

function campaigns_rewards_automation_audience_contacts_v119(PDO $pdo,int $merchantId,array $conditions,array $payload=[],int $limit=250): array
{
    $limit=max(1,min(1000,$limit));$mode=(string)($conditions['audience_mode']??'all_contacts');
    if($mode==='event_contact'){
        $id=campaigns_rewards_automation_event_contact_v119($pdo,$merchantId,$payload);
        return $id>0&&campaigns_rewards_automation_contact_matches_v119($pdo,$merchantId,$id,$conditions)?[$id]:[];
    }
    $stmt=$pdo->prepare("SELECT contact_id FROM crm_merchant_relationships WHERE merchant_id=? ORDER BY updated_at DESC,contact_id DESC LIMIT {$limit}");
    $stmt->execute([$merchantId]);$ids=[];
    foreach($stmt->fetchAll(PDO::FETCH_COLUMN)?:[] as $id)if(campaigns_rewards_automation_contact_matches_v119($pdo,$merchantId,(int)$id,$conditions))$ids[]=(int)$id;
    return $ids;
}

function campaigns_rewards_automation_payload_matches_v119(array $rule,array $payload): bool
{
    $conditions=(array)($rule['conditions']??[]);
    $minPurchase=max(0,(int)($conditions['minimum_purchase_minor']??0));
    if($minPurchase>0&&max(0,(int)($payload['amount_paid_cents']??$payload['amount_minor']??0))<$minPurchase)return false;
    $minPoints=max(0,(int)($conditions['minimum_points']??0));
    if($minPoints>0&&max(0,(int)($payload['balance']??$payload['points']??0))<$minPoints)return false;
    return true;
}

function campaigns_rewards_automation_recipients_v119(PDO $pdo,array $rule,array $payload,int $limit): array
{
    if(!campaigns_rewards_automation_payload_matches_v119($rule,$payload))return [];
    $merchantId=(int)$rule['merchant_id'];$conditions=(array)$rule['conditions'];$mode=(string)($rule['actions']['recipient_mode']??'event_contact');
    $trigger=(string)$rule['trigger_event'];$triggerMeta=campaigns_rewards_automation_triggers_v119()[$trigger]??[];
    $eventContact=campaigns_rewards_automation_event_contact_v119($pdo,$merchantId,$payload);
    if(($triggerMeta['mode']??'')==='event'&&$eventContact>0){
        $base=campaigns_rewards_automation_contact_matches_v119($pdo,$merchantId,$eventContact,$conditions)?[$eventContact]:[];
    }else{
        $base=campaigns_rewards_automation_audience_contacts_v119($pdo,$merchantId,$conditions,$payload,$limit);
    }
    if($trigger!=='referral_qualified'||$mode==='event_contact')return $base;
    $referrer=max(0,(int)($payload['referrer_contact_id']??0));
    if($referrer<1)return $mode==='referrer'?[]:$base;
    if(!campaigns_rewards_automation_contact_matches_v119($pdo,$merchantId,$referrer,$conditions))return $mode==='referrer'?[]:$base;
    if($mode==='referrer')return [$referrer];
    return array_values(array_unique(array_merge($base,[$referrer])));
}

function campaigns_rewards_automation_validate_issue_v119(PDO $pdo,int $campaignId,int $rewardProductId,int $contactId,int $ruleId): array
{
    $rule=campaigns_rewards_automation_rule_v119($pdo,$ruleId,true)?:throw new RuntimeException('Automation rule not found.');
    if((int)$rule['campaign_id']!==$campaignId||(int)$rule['merchant_id']<1||(string)$rule['status']!=='active')throw new RuntimeException('Automation rule is not active for this Campaign.');
    if((string)$rule['merchant_status']!=='active'||(string)$rule['campaign_status']!=='active'||(string)$rule['environment']!=='production')throw new RuntimeException('Campaign automation is not executable in the current lifecycle state.');
    if(!empty($rule['starts_at'])&&strtotime((string)$rule['starts_at'])>time())throw new RuntimeException('Campaign automation has not started yet.');
    if(!empty($rule['ends_at'])&&strtotime((string)$rule['ends_at'])<=time())throw new RuntimeException('Campaign automation has ended.');
    if(!in_array($rewardProductId,campaigns_rewards_campaign_reward_ids_v118($pdo,$campaignId),true))throw new RuntimeException('Automation Reward is not attached to the Campaign.');
    if(!campaigns_rewards_automation_contact_matches_v119($pdo,(int)$rule['merchant_id'],$contactId,(array)$rule['conditions']))throw new RuntimeException('Contact is outside the configured Campaign audience.');
    return $rule;
}

function campaigns_rewards_automation_execution_v119(PDO $pdo,int $ruleId,int $contactId,string $key): ?array
{
    $q=$pdo->prepare("SELECT * FROM campaign_rule_executions WHERE rule_id=? AND contact_id=? AND idempotency_key=? LIMIT 1");$q->execute([$ruleId,$contactId,$key]);$row=$q->fetch();return $row?:null;
}

function campaigns_rewards_automation_cooldown_blocked_v119(PDO $pdo,array $rule,int $contactId): bool
{
    $days=max(0,(int)($rule['conditions']['cooldown_days']??0));if($days<1)return false;
    $q=$pdo->prepare("SELECT 1 FROM campaign_rule_executions WHERE rule_id=? AND contact_id=? AND status='completed' AND executed_at>DATE_SUB(UTC_TIMESTAMP(),INTERVAL ? DAY) LIMIT 1");
    $q->execute([(int)$rule['id'],$contactId,$days]);return (bool)$q->fetchColumn();
}

function campaigns_rewards_automation_execute_contact_v119(PDO $pdo,array $rule,int $contactId,string $triggerEventId,array $payload=[]): array
{
    $ruleId=(int)$rule['id'];$campaignId=(int)$rule['campaign_id'];$merchantId=(int)$rule['merchant_id'];
    $rewardId=max(0,(int)($rule['actions']['reward_product_id']??0));if($rewardId<1)throw new RuntimeException('Automation Reward is not configured.');
    $key=hash('sha256',$ruleId.'|'.$contactId.'|'.$triggerEventId);
    if($existing=campaigns_rewards_automation_execution_v119($pdo,$ruleId,$contactId,$key))return ['duplicate'=>true,'execution'=>$existing];
    if(campaigns_rewards_automation_cooldown_blocked_v119($pdo,$rule,$contactId))return ['duplicate'=>false,'suppressed'=>true,'reason'=>'cooldown'];
    if(!campaigns_rewards_automation_contact_matches_v119($pdo,$merchantId,$contactId,(array)$rule['conditions']))return ['duplicate'=>false,'suppressed'=>true,'reason'=>'audience'];
    $pdo->prepare("INSERT INTO campaign_rule_executions (rule_id,trigger_event_id,contact_id,campaign_id,idempotency_key,status,environment,result_json)
      VALUES (?,?,?,?,?,'running','production','{}')")->execute([$ruleId,$triggerEventId,$contactId,$campaignId,$key]);
    $executionId=(int)$pdo->lastInsertId();
    try{
        $issuance=campaigns_rewards_issue_reward_v100($pdo,$campaignId,$rewardId,$contactId,0,[
            'actor_type'=>'automation','automation_rule_id'=>$ruleId,'source'=>'campaign_automation',
            'idempotency_key'=>'automation:'.$key,
        ]);
        $result=['reward_issuance_id'=>(int)$issuance['id'],'reward_product_id'=>$rewardId,'trigger_event_id'=>$triggerEventId];
        $pdo->prepare("UPDATE campaign_rule_executions SET status='completed',result_json=? WHERE id=?")
          ->execute([campaigns_rewards_json_v100($result),$executionId]);
        campaigns_rewards_activity_event_v100($pdo,$merchantId,'campaign.automation_executed',[
            'campaign_id'=>$campaignId,'contact_id'=>$contactId,'reward_issuance_id'=>(int)$issuance['id'],
        ],[
            'summary'=>'Campaign automation issued Reward','campaign_public_id'=>$rule['campaign_public_id'],'automation_rule_id'=>$ruleId,'trigger'=>$rule['trigger_event'],
        ],'production',null,'automation');
        return ['duplicate'=>false,'suppressed'=>false,'execution_id'=>$executionId,'issuance'=>$issuance];
    }catch(Throwable $e){
        $pdo->prepare("UPDATE campaign_rule_executions SET status='failed',result_json=? WHERE id=?")
          ->execute([campaigns_rewards_json_v100(['error'=>mb_strimwidth($e->getMessage(),0,500,'…')]),$executionId]);
        throw $e;
    }
}

function campaigns_rewards_automation_run_trigger_v119(PDO $pdo,string $trigger,array $payload=[],string $triggerEventId='',int $merchantId=0): array
{
    if(!isset(campaigns_rewards_automation_triggers_v119()[$trigger]))throw new RuntimeException('Unknown Campaign automation trigger.');
    $sql="SELECT r.id FROM campaign_automation_rules r INNER JOIN campaigns c ON c.id=r.campaign_id INNER JOIN merchant_accounts m ON m.id=r.merchant_id
      WHERE r.status='active' AND r.trigger_event=? AND c.status='active' AND c.environment='production' AND m.status='active'
        AND (c.starts_at IS NULL OR c.starts_at<=UTC_TIMESTAMP()) AND (c.ends_at IS NULL OR c.ends_at>UTC_TIMESTAMP())";
    $params=[$trigger];if($merchantId>0){$sql.=" AND r.merchant_id=?";$params[]=$merchantId;}
    $ownerUserId=max(0,(int)($payload['owner_user_id']??0));if($ownerUserId>0){$sql.=" AND m.owner_user_id=?";$params[]=$ownerUserId;}
    $sql.=" ORDER BY r.id";
    $q=$pdo->prepare($sql);$q->execute($params);$ruleIds=array_map('intval',$q->fetchAll(PDO::FETCH_COLUMN)?:[]);
    $summary=['trigger'=>$trigger,'rules'=>0,'contacts'=>0,'executed'=>0,'suppressed'=>0,'failed'=>0];
    foreach($ruleIds as $ruleId){
        $rule=campaigns_rewards_automation_rule_v119($pdo,$ruleId);if(!$rule)continue;$summary['rules']++;
        $limit=max(1,min(1000,(int)($rule['conditions']['max_actions_per_run']??100)));
        $contacts=campaigns_rewards_automation_recipients_v119($pdo,$rule,$payload,$limit);
        $summary['contacts']+=count($contacts);
        $eventId=$triggerEventId!==''?$triggerEventId:($trigger.':'.gmdate('Y-m-d'));
        foreach($contacts as $contactId){
            try{$res=campaigns_rewards_automation_execute_contact_v119($pdo,$rule,$contactId,$eventId,$payload);if(!empty($res['suppressed'])||!empty($res['duplicate']))$summary['suppressed']++;else $summary['executed']++;}
            catch(Throwable $e){$summary['failed']++;error_log('Campaign automation V1.19 failed: '.$e->getMessage());}
        }
    }
    return $summary;
}

function campaigns_rewards_automation_run_due_v119(PDO $pdo,int $merchantId=0): array
{
    $out=['birthday_trigger'=>campaigns_rewards_automation_run_trigger_v119($pdo,'birthday_trigger',[],'birthday:'.gmdate('Y-m-d'),$merchantId),
      'crm_lapse'=>campaigns_rewards_automation_run_trigger_v119($pdo,'crm_lapse',[],'winback:'.gmdate('Y-m-d'),$merchantId)];
    return $out;
}

function campaigns_rewards_automation_external_event_v119(PDO $pdo,string $trigger,array $payload,string $eventId): array
{
    return campaigns_rewards_automation_run_trigger_v119($pdo,$trigger,$payload,$eventId);
}

function campaigns_rewards_campaign_funnel_v119(PDO $pdo,int $campaignId,int $actorUserId): array
{
    $campaign=campaigns_rewards_campaign_platform_v100($pdo,$campaignId)?:throw new RuntimeException('Campaign not found.');
    campaigns_rewards_platform_assert_can_v100($pdo,(int)$campaign['merchant_id'],$actorUserId,'analytics.view');
    $scalar=static function(PDO $pdo,string $sql,array $params): int{$q=$pdo->prepare($sql);$q->execute($params);return (int)$q->fetchColumn();};
    $views=$scalar($pdo,"SELECT COUNT(*) FROM campaign_public_events WHERE campaign_id=? AND event_type='landing_view'",[$campaignId]);
    $participated=$scalar($pdo,"SELECT COUNT(*) FROM campaign_enrollments WHERE campaign_id=?",[$campaignId]);
    $qualified=$scalar($pdo,"SELECT COUNT(*) FROM campaign_enrollments WHERE campaign_id=? AND status IN ('qualified','enrolled','completed')",[$campaignId]);
    $issued=$scalar($pdo,"SELECT COUNT(*) FROM reward_issuances WHERE campaign_id=? AND status<>'voided'",[$campaignId]);
    $rewardViewed=$scalar($pdo,"SELECT COUNT(*) FROM reward_issuances WHERE campaign_id=? AND status IN ('viewed','claimed')",[$campaignId]);
    $sent=$scalar($pdo,"SELECT COUNT(*) FROM reward_transfers t INNER JOIN reward_issuances i ON i.id=t.reward_issuance_id WHERE i.campaign_id=?",[$campaignId]);
    $claimed=$scalar($pdo,"SELECT COUNT(*) FROM reward_claims WHERE campaign_id=? AND status='claimed'",[$campaignId]);
    $q=$pdo->prepare("SELECT COALESCE(SUM(COALESCE(face_value_minor,0)*remaining_quantity),0) FROM reward_issuances WHERE campaign_id=? AND status IN ('issued','sent','viewed')");$q->execute([$campaignId]);$liability=(int)$q->fetchColumn();
    $q=$pdo->prepare("SELECT COALESCE(SUM(b.on_hand-b.reserved),0) FROM reward_inventory_balances b WHERE b.reward_product_id IN (
      SELECT i.reward_product_id FROM campaign_reward_set_items i INNER JOIN campaign_reward_sets rs ON rs.id=i.reward_set_id WHERE rs.campaign_id=?)");$q->execute([$campaignId]);$inventory=(int)$q->fetchColumn();
    return [
        'views'=>$views,'participated'=>$participated,'qualified'=>$qualified,'issued'=>$issued,'reward_viewed'=>$rewardViewed,'sent'=>$sent,'claimed'=>$claimed,
        'participation_rate'=>$views>0?round($participated*100/$views,1):0.0,'issue_rate'=>$participated>0?round($issued*100/$participated,1):0.0,
        'claim_rate'=>$issued>0?round($claimed*100/$issued,1):0.0,'outstanding_liability_minor'=>$liability,'inventory_remaining'=>$inventory,
    ];
}

function campaigns_rewards_lifecycle_insights_v119(PDO $pdo,int $campaignId,int $actorUserId): array
{
    $campaign=campaigns_rewards_campaign_platform_v100($pdo,$campaignId)?:throw new RuntimeException('Campaign not found.');
    $f=campaigns_rewards_campaign_funnel_v119($pdo,$campaignId,$actorUserId);$out=[];
    if($f['views']>=20&&$f['participation_rate']<5)$out[]=['kind'=>'conversion','title'=>'Landing conversion is low','detail'=>'Review the Campaign offer, CTA or signup friction before increasing traffic.'];
    if($f['issued']>=10&&$f['claim_rate']<15)$out[]=['kind'=>'redemption','title'=>'Reward claim rate is low','detail'=>'Consider a reminder or a clearer redemption window.'];
    if($f['inventory_remaining']>0&&$f['inventory_remaining']<=5)$out[]=['kind'=>'inventory','title'=>'Reward inventory is running low','detail'=>'Review inventory before allowing more automated issuance.'];
    if($f['outstanding_liability_minor']>0)$out[]=['kind'=>'liability','title'=>'Outstanding Reward liability','detail'=>'Unclaimed issued Rewards still represent Merchant liability.'];
    $rules=campaigns_rewards_automation_rules_v119($pdo,(int)$campaign['merchant_id'],$campaignId);
    if(!$rules)$out[]=['kind'=>'automation','title'=>'No automation configured','detail'=>'Add a governed trigger if this Campaign Type should act on CRM or lifecycle events.'];
    return $out;
}
