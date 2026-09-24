<?php
declare(strict_types=1);

const VP3_CAMPAIGNS_REWARDS_V118='vp3-campaigns-rewards-v118-20260923';

function campaigns_rewards_campaign_type_behavior_v118(PDO $pdo,int $merchantId,string $typeKey): array
{
    $definition=campaigns_rewards_campaign_type_definition_v118($typeKey)??[];
    $row=campaigns_rewards_campaign_type_v100($pdo,$merchantId,$typeKey);
    if($row){
        foreach([
            'field_schema_json'=>'field_schema',
            'eligibility_schema_json'=>'eligibility_schema',
            'trigger_schema_json'=>'trigger_schema',
            'reward_rules_schema_json'=>'reward_rules',
            'landing_schema_json'=>'landing_schema',
        ] as $column=>$key){
            $decoded=json_decode((string)($row[$column]??''),true);
            if(is_array($decoded))$definition[$key]=$decoded;
        }
        $definition['type_key']=$typeKey;
        $definition['name']=(string)($row['name']??($definition['name']??$typeKey));
        $definition['description']=(string)($row['description']??($definition['description']??''));
        $definition['handler']=(string)($row['base_handler_key']??($definition['handler']??$typeKey));
        $definition['public']=!empty($row['supports_public_signup']);
        $definition['cases']=!empty($row['supports_cases']);
        $definition['automation']=!empty($row['supports_automation']);
        $definition['agent']=!empty($row['supports_agent']);
    }
    $field=(array)($definition['field_schema']??[]);
    $reward=(array)($definition['reward_rules']??[]);
    $landing=(array)($definition['landing_schema']??[]);
    $definition['required_fields']=(array)($field['required_fields']??($definition['required_fields']??[]));
    $definition['public_action']=(string)($field['public_action']??$landing['public_action']??($definition['public_action']??'none'));
    $definition['marketing']=(string)($field['marketing']??($definition['marketing']??'optional'));
    $definition['reward_timing']=(string)($reward['timing']??($definition['reward_timing']??'manual'));
    $definition['requires_reward']=array_key_exists('requires_reward',$reward)?!empty($reward['requires_reward']):!empty($definition['requires_reward']);
    $definition['default_cta']=(string)($landing['cta']??($definition['default_cta']??'Continue'));
    return $definition;
}

function campaigns_rewards_campaign_reward_ids_v118(PDO $pdo,int $campaignId): array
{
    $stmt=$pdo->prepare("SELECT DISTINCT i.reward_product_id FROM campaign_reward_set_items i
      INNER JOIN campaign_reward_sets rs ON rs.id=i.reward_set_id
      WHERE rs.campaign_id=? ORDER BY i.priority,i.reward_product_id");
    $stmt->execute([$campaignId]);
    return array_values(array_map('intval',$stmt->fetchAll(PDO::FETCH_COLUMN)?:[]));
}

function campaigns_rewards_sync_campaign_rewards_v118(PDO $pdo,int $merchantId,int $campaignId,int $actorUserId,array $rewardIds,bool $snapshotActive=true): void
{
    campaigns_rewards_platform_assert_can_v100($pdo,$merchantId,$actorUserId,'campaigns.edit');
    $campaign=campaigns_rewards_campaign_platform_v100($pdo,$campaignId,true)?:throw new RuntimeException('Campaign not found.');
    if((int)$campaign['merchant_id']!==$merchantId)throw new RuntimeException('Campaign not found.');
    $selected=array_values(array_unique(array_filter(array_map('intval',$rewardIds),static fn(int $id):bool=>$id>0)));
    foreach($selected as $rewardId){
        $q=$pdo->prepare("SELECT id FROM reward_products WHERE id=? AND merchant_id=? AND is_active=1 LIMIT 1");
        $q->execute([$rewardId,$merchantId]);
        if(!(int)$q->fetchColumn())throw new RuntimeException('Choose only active Rewards owned by this Merchant.');
    }
    $wasActive=(string)$campaign['status']==='active';
    if($wasActive)campaigns_rewards_platform_assert_can_v100($pdo,$merchantId,$actorUserId,'campaigns.publish');

    $setStmt=$pdo->prepare("SELECT id FROM campaign_reward_sets WHERE campaign_id=? ORDER BY id LIMIT 1");
    $setStmt->execute([$campaignId]);$setId=(int)$setStmt->fetchColumn();
    if($setId<1&&$selected){
        $pdo->prepare("INSERT INTO campaign_reward_sets (campaign_id,name,selection_mode,min_choices,max_choices) VALUES (?,'Default rewards','fixed',1,1)")->execute([$campaignId]);
        $setId=(int)$pdo->lastInsertId();
    }
    if($setId>0){
        if($selected){
            $marks=implode(',',array_fill(0,count($selected),'?'));
            $pdo->prepare("DELETE FROM campaign_reward_set_items WHERE reward_set_id=? AND reward_product_id NOT IN ({$marks})")
                ->execute(array_merge([$setId],$selected));
            $insert=$pdo->prepare("INSERT INTO campaign_reward_set_items (reward_set_id,reward_product_id,variant_id,quantity,priority,conditions_json)
              VALUES (?,?,0,1,100,'{}') ON DUPLICATE KEY UPDATE quantity=VALUES(quantity)");
            foreach($selected as $rewardId)$insert->execute([$setId,$rewardId]);
        }else{
            $pdo->prepare("DELETE FROM campaign_reward_set_items WHERE reward_set_id=?")->execute([$setId]);
        }
    }
    campaigns_rewards_activity_event_v100($pdo,$merchantId,'campaign.rewards_updated',['campaign_id'=>$campaignId],[
        'summary'=>'Campaign Rewards updated','campaign_public_id'=>$campaign['public_id'],'reward_count'=>count($selected),
    ],(string)$campaign['environment'],$actorUserId);
    if($wasActive&&$snapshotActive)campaigns_rewards_snapshot_campaign_v100($pdo,$campaignId,$actorUserId,'published');
}

function campaigns_rewards_validate_campaign_activation_v118(PDO $pdo,array $campaign): void
{
    $behavior=campaigns_rewards_campaign_type_behavior_v118($pdo,(int)$campaign['merchant_id'],(string)$campaign['campaign_type_key']);
    if(!empty($behavior['requires_reward'])&&!campaigns_rewards_campaign_reward_ids_v118($pdo,(int)$campaign['id'])){
        throw new RuntimeException('Attach at least one active Reward before activating this Campaign Type.');
    }
    if(!empty($behavior['public'])&&!empty($behavior['required_fields'])&&(int)$campaign['current_version_no']<1){
        // Lifecycle activation will create the initial immutable snapshot immediately after this validation.
    }
}

function campaigns_rewards_public_field_value_v118(array $input,string $field): string
{
    return trim((string)($input[$field]??''));
}

function campaigns_rewards_public_validate_v118(array $behavior,array $input,array $campaign): void
{
    foreach((array)($behavior['required_fields']??[]) as $field){
        $value=campaigns_rewards_public_field_value_v118($input,(string)$field);
        if($field==='referral_ref'&&$value==='')$value=trim((string)($_GET['ref']??''));
        if($value==='')throw new RuntimeException(match($field){
            'birthday'=>'Enter your birthday.',
            'social_handle'=>'Enter your social handle.',
            'proof_url'=>'Add a proof or content link.',
            'referral_ref'=>'This Referral campaign requires a referral link or code.',
            default=>'Complete all required fields.',
        });
    }
    if((string)($behavior['marketing']??'optional')==='required'&&empty($input['marketing_consent'])){
        throw new RuntimeException('Marketing signup consent is required for this Campaign.');
    }
    if(!empty($input['email'])&&!filter_var((string)$input['email'],FILTER_VALIDATE_EMAIL))throw new RuntimeException('Enter a valid email address.');
    if(($behavior['public_action']??'')==='birthday_signup'){
        $birthday=campaigns_rewards_public_field_value_v118($input,'birthday');
        $ts=strtotime($birthday);
        if(!$ts)throw new RuntimeException('Enter a valid birthday.');
    }
}

function campaigns_rewards_journey_enqueue_latest_v118(PDO $pdo,int $campaignId,int $contactId,string $trigger,array $context=[],string $eventId=''): array
{
    if(function_exists('campaigns_rewards_journey_enqueue_v121'))return campaigns_rewards_journey_enqueue_v121($pdo,$campaignId,$contactId,$trigger,$context,$eventId);
    if(function_exists('campaigns_rewards_journey_enqueue_v120'))return campaigns_rewards_journey_enqueue_v120($pdo,$campaignId,$contactId,$trigger,$context,$eventId);
    return ['trigger'=>$trigger,'queued'=>0,'duplicate'=>0,'suppressed'=>1,'reason'=>'journey_runtime_unavailable'];
}

function campaigns_rewards_public_participate_v118(PDO $pdo,array $campaign,array $input,string $requestToken,?array $selectedReward=null): array
{
    $campaignId=(int)$campaign['id'];$merchantId=(int)$campaign['merchant_id'];
    $behavior=campaigns_rewards_campaign_type_behavior_v118($pdo,$merchantId,(string)$campaign['campaign_type_key']);
    if(empty($behavior['public']))throw new RuntimeException('This Campaign does not accept public participation.');
    campaigns_rewards_public_validate_v118($behavior,$input,$campaign);

    $email=strtolower(campaigns_rewards_public_field_value_v118($input,'email'));
    $name=campaigns_rewards_public_field_value_v118($input,'name');
    $phone=campaigns_rewards_public_field_value_v118($input,'phone');
    $vp3UserId=0;
    if($email!==''){
        $u=$pdo->prepare('SELECT id FROM users WHERE email=? AND is_active=1 LIMIT 1');$u->execute([$email]);$vp3UserId=(int)$u->fetchColumn();
    }
    $contact=campaigns_rewards_resolve_contact_v100($pdo,$merchantId,[
        'name'=>$name,'email'=>$email,'phone'=>$phone,'source'=>'campaigns_rewards','vp3_user_id'=>$vp3UserId,
    ]);
    $contactId=(int)$contact['id'];
    $marketingConsent=!empty($input['marketing_consent']);
    $marketingStatus=$marketingConsent?'subscribed':'unknown';

    $enrollment=campaigns_rewards_public_enroll_v100($pdo,$campaignId,$contactId,'public-v118:'.$campaign['public_id'].':'.$contactId);
    $referralRef=campaigns_rewards_public_field_value_v118($input,'referral_ref')?:trim((string)($_GET['ref']??''));
    $referrerContactId=((string)$campaign['campaign_type_key']==='referral'&&function_exists('campaigns_rewards_referral_referrer_v119'))
        ?campaigns_rewards_referral_referrer_v119($pdo,$merchantId,$referralRef):0;
    $metadata=[
        'campaign_type'=>$campaign['campaign_type_key'],
        'public_action'=>$behavior['public_action']??'signup',
        'marketing_consent'=>$marketingConsent,
        'birthday'=>campaigns_rewards_public_field_value_v118($input,'birthday'),
        'social_handle'=>campaigns_rewards_public_field_value_v118($input,'social_handle'),
        'proof_url'=>campaigns_rewards_public_field_value_v118($input,'proof_url'),
        'referral_ref'=>$referralRef,
        'referrer_contact_id'=>$referrerContactId?:null,
    ];
    $pdo->prepare("UPDATE campaign_enrollments SET source=?,metadata_json=?,updated_at=UTC_TIMESTAMP() WHERE id=?")
      ->execute([(string)($behavior['public_action']??'public_signup'),campaigns_rewards_json_v100($metadata),(int)$enrollment['id']]);

    campaigns_rewards_ensure_merchant_relationship_v100($pdo,$merchantId,$contactId,[
        'customer_status'=>'prospect',
        'acquisition_source'=>'campaign:'.(string)$campaign['campaign_type_key'],
        'marketing_status'=>$marketingStatus,
        'metadata'=>$metadata+['campaign_public_id'=>$campaign['public_id']],
    ]);

    $merchant=campaigns_rewards_platform_merchant_v100($pdo,$merchantId);
    if($merchant){
        $pdo->prepare("INSERT INTO crm_contact_events_v1 (owner_user_id,contact_id,event_type,summary,source_kind,source_id,actor_type,metadata_json)
          VALUES (?,?,?,?,?,?,'public',?)")
          ->execute([
              (int)$merchant['owner_user_id'],$contactId,'campaign.'.(string)($behavior['public_action']??'participated'),
              (string)($behavior['name']??'Campaign').' participation','campaign',(string)$campaign['public_id'],
              campaigns_rewards_json_v100($metadata+['campaign_public_id'=>$campaign['public_id']]),
          ]);
    }
    campaigns_rewards_activity_event_v100($pdo,$merchantId,'campaign.'.(string)($behavior['public_action']??'participated'),[
        'campaign_id'=>$campaignId,'contact_id'=>$contactId,'enrollment_id'=>(int)$enrollment['id'],
    ],[
        'summary'=>(string)($behavior['name']??'Campaign').' participation completed',
        'merchant_public_id'=>$campaign['merchant_public_id'],'campaign_public_id'=>$campaign['public_id'],
        'marketing_consent'=>$marketingConsent,'referral_ref'=>$metadata['referral_ref'],
    ],'production',null,'public');

    $timing=(string)($behavior['reward_timing']??'immediate');
    $shouldIssue=in_array($timing,['immediate','immediate_if_attached'],true);
    if($shouldIssue&&$selectedReward){
        $issued=campaigns_rewards_issue_reward_v100($pdo,$campaignId,(int)$selectedReward['id'],$contactId,0,[
            'actor_type'=>'public','source'=>'campaign:'.(string)($behavior['public_action']??'participation'),
            'campaign_enrollment_id'=>(int)$enrollment['id'],'recipient_user_id'=>$vp3UserId,
            'idempotency_key'=>'public-v118-reward:'.hash('sha256',$requestToken.'|'.$campaignId.'|'.(int)$selectedReward['id'].'|'.$contactId),
        ]);
        $pdo->prepare("UPDATE campaign_enrollments SET status='completed',completed_at=COALESCE(completed_at,UTC_TIMESTAMP()),updated_at=UTC_TIMESTAMP() WHERE id=?")
          ->execute([(int)$enrollment['id']]);
        campaigns_rewards_activity_event_v100($pdo,$merchantId,'campaign.enrollment_completed',[
            'campaign_id'=>$campaignId,'contact_id'=>$contactId,'enrollment_id'=>(int)$enrollment['id'],'reward_issuance_id'=>(int)$issued['id'],
        ],[
            'summary'=>'Public Campaign participation fulfilled with Reward',
            'merchant_public_id'=>$campaign['merchant_public_id'],'campaign_public_id'=>$campaign['public_id'],
        ],'production',null,'public');
        $enrollment['status']='completed';$enrollment['completed_at']=gmdate('Y-m-d H:i:s');
        if(function_exists('campaigns_rewards_journey_enqueue_v121')||function_exists('campaigns_rewards_journey_enqueue_v120')){
            $publicTrigger=(string)($behavior['public_action']??'');
            try{
                if(isset(campaigns_rewards_journey_triggers_v120()[$publicTrigger])){
                    campaigns_rewards_journey_enqueue_latest_v118($pdo,$campaignId,$contactId,$publicTrigger,[
                        'reward_issuance_id'=>(int)$issued['id'],'enrollment_id'=>(int)$enrollment['id'],
                        'expires_at'=>(string)($issued['expires_at']??''),'occurred_at'=>gmdate('Y-m-d H:i:s'),
                    ],'public:'.$publicTrigger.':'.(int)$enrollment['id']);
                }
                campaigns_rewards_journey_enqueue_latest_v118($pdo,$campaignId,$contactId,'reward_issued',[
                    'reward_issuance_id'=>(int)$issued['id'],'enrollment_id'=>(int)$enrollment['id'],
                    'expires_at'=>(string)($issued['expires_at']??''),'occurred_at'=>gmdate('Y-m-d H:i:s'),
                ],'reward-issued:'.(int)$issued['id']);
            }catch(Throwable $e){error_log('Campaign messaging journey bridge failed: '.$e->getMessage());}
        }
        return ['contact'=>$contact,'enrollment'=>$enrollment,'issued'=>$issued,'reward'=>$selectedReward,'behavior'=>$behavior,'message'=>'Reward issued.'];
    }
    if($shouldIssue&&!$selectedReward&&!empty($behavior['requires_reward']))throw new RuntimeException('This Campaign does not have an available Reward attached.');
    if(function_exists('campaigns_rewards_journey_enqueue_v121')||function_exists('campaigns_rewards_journey_enqueue_v120')){
        $publicTrigger=(string)($behavior['public_action']??'');
        if(isset(campaigns_rewards_journey_triggers_v120()[$publicTrigger])){
            try{campaigns_rewards_journey_enqueue_latest_v118($pdo,$campaignId,$contactId,$publicTrigger,[
                'enrollment_id'=>(int)$enrollment['id'],'occurred_at'=>gmdate('Y-m-d H:i:s'),
            ],'public:'.$publicTrigger.':'.(int)$enrollment['id']);}
            catch(Throwable $e){error_log('Campaign messaging journey bridge failed: '.$e->getMessage());}
        }
    }
    $message=match($timing){
        'after_verification'=>'Your participation was submitted. The Reward becomes available after verification.',
        'triggered'=>'You are enrolled. The Reward will be issued when the Campaign trigger is reached.',
        default=>'You are enrolled in this Campaign.',
    };
    return ['contact'=>$contact,'enrollment'=>$enrollment,'issued'=>null,'reward'=>null,'behavior'=>$behavior,'message'=>$message];
}

function campaigns_rewards_recent_enrollments_v118(PDO $pdo,int $merchantId,int $actorUserId,int $limit=40): array
{
    campaigns_rewards_platform_assert_can_v100($pdo,$merchantId,$actorUserId,'campaigns.view');
    $limit=max(1,min(100,$limit));
    $sql="SELECT e.*,c.name campaign_name,c.public_id campaign_public_id,c.status campaign_status,
      ct.type_key campaign_type_key,ct.name campaign_type_name,
      cc.name contact_name,cc.email contact_email
      FROM campaign_enrollments e
      INNER JOIN campaigns c ON c.id=e.campaign_id
      INNER JOIN campaign_types ct ON ct.id=c.campaign_type_id
      LEFT JOIN crm_contacts cc ON cc.id=e.contact_id
      WHERE c.merchant_id=?
      ORDER BY e.updated_at DESC,e.id DESC LIMIT {$limit}";
    $stmt=$pdo->prepare($sql);$stmt->execute([$merchantId]);
    return $stmt->fetchAll()?:[];
}

function campaigns_rewards_issue_enrollment_reward_v118(PDO $pdo,int $merchantId,int $campaignId,int $enrollmentId,int $rewardProductId,int $actorUserId): array
{
    campaigns_rewards_platform_assert_can_v100($pdo,$merchantId,$actorUserId,'campaigns.enrollment.manage');
    campaigns_rewards_platform_assert_can_v100($pdo,$merchantId,$actorUserId,'rewards.issue');
    if($campaignId<1||$enrollmentId<1||$rewardProductId<1)throw new RuntimeException('Choose a Campaign participant and attached Reward.');

    $owns=!$pdo->inTransaction();if($owns)$pdo->beginTransaction();
    try{
        $campaign=campaigns_rewards_campaign_platform_v100($pdo,$campaignId,true)?:throw new RuntimeException('Campaign not found.');
        if((int)$campaign['merchant_id']!==$merchantId)throw new RuntimeException('Campaign not found.');
        $q=$pdo->prepare("SELECT * FROM campaign_enrollments WHERE id=? AND campaign_id=? AND contact_id IS NOT NULL LIMIT 1 FOR UPDATE");
        $q->execute([$enrollmentId,$campaignId]);$enrollment=$q->fetch()?:throw new RuntimeException('Campaign participant not found.');
        if((string)$enrollment['status']==='completed')throw new RuntimeException('This Campaign participant has already been fulfilled.');
        if(!in_array($rewardProductId,campaigns_rewards_campaign_reward_ids_v118($pdo,$campaignId),true))throw new RuntimeException('That Reward is not attached to this Campaign.');
        $issuance=campaigns_rewards_issue_reward_v100($pdo,$campaignId,$rewardProductId,(int)$enrollment['contact_id'],$actorUserId,[
            'actor_type'=>'user','source'=>'campaign_fulfillment','campaign_enrollment_id'=>$enrollmentId,
            'idempotency_key'=>'campaign-fulfillment:'.$campaignId.':'.$enrollmentId.':'.$rewardProductId,
        ]);
        $pdo->prepare("UPDATE campaign_enrollments SET status='completed',completed_at=COALESCE(completed_at,UTC_TIMESTAMP()),updated_at=UTC_TIMESTAMP() WHERE id=?")
          ->execute([$enrollmentId]);
        campaigns_rewards_activity_event_v100($pdo,$merchantId,'campaign.enrollment_completed',[
            'campaign_id'=>$campaignId,'contact_id'=>(int)$enrollment['contact_id'],'enrollment_id'=>$enrollmentId,
            'reward_issuance_id'=>(int)$issuance['id'],
        ],[
            'summary'=>'Campaign participation fulfilled with Reward',
            'merchant_public_id'=>$campaign['merchant_public_id'],'campaign_public_id'=>$campaign['public_id'],
            'reward_product_id'=>$rewardProductId,
        ],(string)$campaign['environment'],$actorUserId);
        if($owns)$pdo->commit();
        if(function_exists('campaigns_rewards_journey_enqueue_v121')||function_exists('campaigns_rewards_journey_enqueue_v120')){
            try{campaigns_rewards_journey_enqueue_latest_v118($pdo,$campaignId,(int)$enrollment['contact_id'],'reward_issued',[
                'reward_issuance_id'=>(int)$issuance['id'],'enrollment_id'=>$enrollmentId,
                'expires_at'=>(string)($issuance['expires_at']??''),'occurred_at'=>gmdate('Y-m-d H:i:s'),
            ],'reward-issued:'.(int)$issuance['id']);}
            catch(Throwable $e){error_log('Campaign messaging fulfillment bridge failed: '.$e->getMessage());}
        }
        return ['campaign'=>$campaign,'enrollment'=>$enrollment,'issuance'=>$issuance];
    }catch(Throwable $e){if($owns&&$pdo->inTransaction())$pdo->rollBack();throw $e;}
}

