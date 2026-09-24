<?php
declare(strict_types=1);

/**
 * Campaigns & Rewards V1.20 — Campaign Messaging & Journeys.
 *
 * This layer reuses the V1 canonical campaign_messages, campaign_deliveries and
 * campaign_idempotency_keys authorities. It does not introduce a second CRM,
 * scheduler, human-message store, Reward authority, or Agent execution path.
 */
const VP3_CAMPAIGNS_REWARDS_V120='vp3-campaigns-rewards-v120-20260923';

function campaigns_rewards_message_channels_v120(): array
{
    return [
        'email'=>'Email',
        'sms'=>'SMS',
        'agent'=>'VP3 / Agent notification',
    ];
}

function campaigns_rewards_message_purposes_v120(): array
{
    return [
        'marketing'=>'Marketing',
        'transactional'=>'Transactional / service',
    ];
}

function campaigns_rewards_journey_triggers_v120(): array
{
    return [
        'newsletter_signup'=>'Newsletter signup',
        'birthday_trigger'=>'Birthday / VIP',
        'crm_lapse'=>'Win-back inactivity',
        'purchase_completed'=>'Post-purchase',
        'referral_qualified'=>'Referral qualified',
        'winner_selected'=>'Contest winner',
        'attendance_confirmed'=>'Attendance confirmed',
        'proof_approved'=>'Proof approved',
        'loyalty_milestone'=>'Loyalty milestone',
        'product_available'=>'Product / offer available',
        'allocation_approved'=>'Community allocation',
        'agent_action'=>'Approved Agent action',
        'reward_issued'=>'Reward issued',
        'reward_expiring'=>'Reward expiring',
        'manual'=>'Manual journey',
    ];
}

function campaigns_rewards_message_v120(PDO $pdo,int $messageId): ?array
{
    if($messageId<1)return null;
    $q=$pdo->prepare("SELECT cm.*,c.merchant_id,c.public_id campaign_public_id,c.name campaign_name,c.slug campaign_slug,
      c.status campaign_status,c.environment,c.current_version_no,m.public_id merchant_public_id,m.status merchant_status
      FROM campaign_messages cm
      INNER JOIN campaigns c ON c.id=cm.campaign_id
      INNER JOIN merchant_accounts m ON m.id=c.merchant_id
      WHERE cm.id=? LIMIT 1");
    $q->execute([$messageId]);$row=$q->fetch();if(!$row)return null;
    $row['template']=json_decode((string)($row['template_json']??''),true)?:[];
    return $row;
}

function campaigns_rewards_messages_v120(PDO $pdo,int $merchantId,int $campaignId=0): array
{
    $sql="SELECT cm.*,c.merchant_id,c.name campaign_name,c.status campaign_status,c.environment
      FROM campaign_messages cm
      INNER JOIN campaigns c ON c.id=cm.campaign_id
      INNER JOIN (
        SELECT campaign_id,message_key,MAX(version_no) latest_version
        FROM campaign_messages GROUP BY campaign_id,message_key
      ) latest ON latest.campaign_id=cm.campaign_id AND latest.message_key=cm.message_key AND latest.latest_version=cm.version_no
      WHERE c.merchant_id=?";
    $params=[$merchantId];
    if($campaignId>0){$sql.=" AND cm.campaign_id=?";$params[]=$campaignId;}
    $sql.=" ORDER BY cm.campaign_id,COALESCE(CAST(JSON_UNQUOTE(JSON_EXTRACT(cm.template_json,'$.step_order')) AS UNSIGNED),9999),cm.message_key,cm.id";
    $q=$pdo->prepare($sql);$q->execute($params);$rows=$q->fetchAll()?:[];
    foreach($rows as &$row)$row['template']=json_decode((string)($row['template_json']??''),true)?:[];
    unset($row);return $rows;
}

function campaigns_rewards_message_key_v120(string $journeyKey,string $stepKey): string
{
    $journey=campaigns_rewards_slug_v100($journeyKey,36)?:'journey';
    $step=campaigns_rewards_slug_v100($stepKey,36)?:'step';
    return substr($journey.'--'.$step,0,80);
}

function campaigns_rewards_message_save_v120(PDO $pdo,int $merchantId,int $campaignId,int $actorUserId,array $input,int $messageId=0): array
{
    campaigns_rewards_platform_assert_can_v100($pdo,$merchantId,$actorUserId,'campaigns.edit');
    $campaign=campaigns_rewards_campaign_platform_v100($pdo,$campaignId)?:throw new RuntimeException('Campaign not found.');
    if((int)$campaign['merchant_id']!==$merchantId)throw new RuntimeException('Campaign not found.');

    $channel=trim((string)($input['channel']??'email'));
    if(!isset(campaigns_rewards_message_channels_v120()[$channel]))throw new RuntimeException('Choose a supported message channel.');
    $trigger=trim((string)($input['trigger_event']??'manual'));
    if(!isset(campaigns_rewards_journey_triggers_v120()[$trigger]))throw new RuntimeException('Choose a supported journey trigger.');
    $purpose=trim((string)($input['purpose']??'marketing'));
    if(!isset(campaigns_rewards_message_purposes_v120()[$purpose]))throw new RuntimeException('Choose a valid message purpose.');

    $journeyKey=campaigns_rewards_slug_v100((string)($input['journey_key']??'default'),50)?:'default';
    $stepKey=campaigns_rewards_slug_v100((string)($input['step_key']??'step'),50)?:'step';
    $messageKey=campaigns_rewards_message_key_v120($journeyKey,$stepKey);
    $subject=campaigns_rewards_text_v100($input['subject']??'',255);
    $body=trim((string)($input['body']??''));
    if($body==='')throw new RuntimeException('Message body is required.');
    if(mb_strlen($body)>20000)throw new RuntimeException('Message body is too long.');

    $status=(string)($input['status']??'draft');
    if(!in_array($status,['draft','active','paused'],true))$status='draft';
    if($status==='active'){
        campaigns_rewards_platform_assert_can_v100($pdo,$merchantId,$actorUserId,'campaigns.publish');
        if((string)$campaign['status']!=='active'||(int)$campaign['current_version_no']<1)
            throw new RuntimeException('Activate and publish the Campaign before activating a journey step.');
    }

    $template=[
        'kind'=>'journey_step',
        'journey_key'=>$journeyKey,
        'step_key'=>$stepKey,
        'step_order'=>max(1,min(999,(int)($input['step_order']??1))),
        'trigger_event'=>$trigger,
        'delay_minutes'=>max(0,min(5256000,(int)($input['delay_minutes']??0))),
        'expiration_lead_days'=>max(0,min(3650,(int)($input['expiration_lead_days']??7))),
        'purpose'=>$purpose,
        'stop_on_claim'=>!empty($input['stop_on_claim']),
        'stop_on_expiration'=>!empty($input['stop_on_expiration']),
    ];

    $previous=null;$version=1;
    if($messageId>0){
        $previous=campaigns_rewards_message_v120($pdo,$messageId)?:throw new RuntimeException('Journey step not found.');
        if((int)$previous['merchant_id']!==$merchantId||(int)$previous['campaign_id']!==$campaignId)
            throw new RuntimeException('Journey step not found.');
        $messageKey=(string)$previous['message_key'];
        $journeyKey=(string)($previous['template']['journey_key']??$journeyKey);
        $stepKey=(string)($previous['template']['step_key']??$stepKey);
        $template['journey_key']=$journeyKey;$template['step_key']=$stepKey;
        $q=$pdo->prepare("SELECT COALESCE(MAX(version_no),0) FROM campaign_messages WHERE campaign_id=? AND message_key=?");
        $q->execute([$campaignId,$messageKey]);$version=(int)$q->fetchColumn()+1;
    }else{
        $q=$pdo->prepare("SELECT COALESCE(MAX(version_no),0) FROM campaign_messages WHERE campaign_id=? AND message_key=?");
        $q->execute([$campaignId,$messageKey]);$version=(int)$q->fetchColumn()+1;
    }

    $owns=!$pdo->inTransaction();if($owns)$pdo->beginTransaction();
    try{
        if($previous){
            $pdo->prepare("UPDATE campaign_messages SET status='superseded',updated_at=UTC_TIMESTAMP() WHERE id=? AND status<>'superseded'")
                ->execute([(int)$previous['id']]);
        }
        $pdo->prepare("INSERT INTO campaign_messages
          (campaign_id,message_key,channel,subject,body,template_json,status,version_no,created_at,updated_at)
          VALUES (?,?,?,?,?,?,?, ?,UTC_TIMESTAMP(),UTC_TIMESTAMP())")
          ->execute([$campaignId,$messageKey,$channel,$subject,$body,campaigns_rewards_json_v100($template),$status,$version]);
        $newId=(int)$pdo->lastInsertId();
        if($owns)$pdo->commit();
    }catch(Throwable $e){if($owns&&$pdo->inTransaction())$pdo->rollBack();throw $e;}

    campaigns_rewards_activity_event_v100($pdo,$merchantId,'campaign.message_saved',['campaign_id'=>$campaignId],[
        'summary'=>'Campaign journey step saved','merchant_public_id'=>$campaign['merchant_public_id'],
        'campaign_public_id'=>$campaign['public_id'],'message_id'=>$newId,'message_key'=>$messageKey,
        'version_no'=>$version,'channel'=>$channel,'trigger_event'=>$trigger,'status'=>$status,
    ],(string)$campaign['environment'],$actorUserId);
    return campaigns_rewards_message_v120($pdo,$newId)?:throw new RuntimeException('Journey step could not be loaded.');
}

function campaigns_rewards_message_set_status_v120(PDO $pdo,int $messageId,int $actorUserId,string $status): array
{
    if(!in_array($status,['draft','active','paused'],true))throw new RuntimeException('Choose a valid message status.');
    $row=campaigns_rewards_message_v120($pdo,$messageId)?:throw new RuntimeException('Journey step not found.');
    campaigns_rewards_platform_assert_can_v100($pdo,(int)$row['merchant_id'],$actorUserId,'campaigns.edit');
    if($status==='active'){
        campaigns_rewards_platform_assert_can_v100($pdo,(int)$row['merchant_id'],$actorUserId,'campaigns.publish');
        if((string)$row['campaign_status']!=='active'||(int)$row['current_version_no']<1)
            throw new RuntimeException('Activate and publish the Campaign before activating a journey step.');
    }
    $pdo->prepare("UPDATE campaign_messages SET status=?,updated_at=UTC_TIMESTAMP() WHERE id=?")->execute([$status,$messageId]);
    campaigns_rewards_activity_event_v100($pdo,(int)$row['merchant_id'],'campaign.message_saved',['campaign_id'=>(int)$row['campaign_id']],[
        'summary'=>'Campaign journey step status changed','merchant_public_id'=>$row['merchant_public_id'],
        'campaign_public_id'=>$row['campaign_public_id'],'message_id'=>$messageId,'status'=>$status,
    ],(string)$row['environment'],$actorUserId);
    return campaigns_rewards_message_v120($pdo,$messageId)?:throw new RuntimeException('Journey step unavailable.');
}

function campaigns_rewards_journey_steps_v120(PDO $pdo,int $campaignId,string $trigger=''): array
{
    $sql="SELECT cm.* FROM campaign_messages cm
      INNER JOIN (
        SELECT campaign_id,message_key,MAX(version_no) latest_version
        FROM campaign_messages WHERE campaign_id=? GROUP BY campaign_id,message_key
      ) latest ON latest.campaign_id=cm.campaign_id AND latest.message_key=cm.message_key AND latest.latest_version=cm.version_no
      WHERE cm.campaign_id=? AND cm.status='active'";
    $params=[$campaignId,$campaignId];$sql.=" ORDER BY cm.id";
    $q=$pdo->prepare($sql);$q->execute($params);$out=[];
    foreach($q->fetchAll()?:[] as $row){
        $row['template']=json_decode((string)($row['template_json']??''),true)?:[];
        if(($row['template']['kind']??'')!=='journey_step')continue;
        if($trigger!==''&&(string)($row['template']['trigger_event']??'manual')!==$trigger)continue;
        $out[]=$row;
    }
    usort($out,static fn(array $a,array $b):int=>[(int)($a['template']['step_order']??999),(int)$a['id']]<=>[(int)($b['template']['step_order']??999),(int)$b['id']]);
    return $out;
}

function campaigns_rewards_message_contact_v120(PDO $pdo,int $merchantId,int $contactId): ?array
{
    $q=$pdo->prepare("SELECT c.*,r.marketing_status merchant_marketing_status,
      p.marketing_email,p.marketing_sms,p.marketing_push,p.transactional_allowed,p.preferred_channel,p.quiet_hours_json,p.consent_json
      FROM crm_contacts c
      INNER JOIN crm_merchant_relationships r ON r.contact_id=c.id AND r.merchant_id=?
      LEFT JOIN crm_contact_preferences p ON p.contact_id=c.id
      WHERE c.id=? LIMIT 1");
    $q->execute([$merchantId,$contactId]);$row=$q->fetch();return $row?:null;
}

function campaigns_rewards_message_consent_v120(array $contact,string $channel,string $purpose): array
{
    if((string)($contact['status']??'active')!=='active')return ['allowed'=>false,'reason'=>'contact_inactive'];
    if($purpose==='transactional'){
        if(array_key_exists('transactional_allowed',$contact)&&$contact['transactional_allowed']!==null&&!(int)$contact['transactional_allowed'])
            return ['allowed'=>false,'reason'=>'transactional_opt_out'];
        return ['allowed'=>true,'reason'=>'transactional'];
    }
    $statuses=[
        strtolower((string)($contact['marketing_status']??'')),
        strtolower((string)($contact['merchant_marketing_status']??'')),
    ];
    $subscribed=(bool)array_intersect($statuses,['subscribed','opted_in','consented','active']);
    if(!$subscribed)return ['allowed'=>false,'reason'=>'marketing_not_subscribed'];

    if($channel==='email'&&$contact['marketing_email']!==null&&!(int)$contact['marketing_email'])
        return ['allowed'=>false,'reason'=>'email_opt_out'];
    if($channel==='sms'){
        if($contact['marketing_sms']===null||!(int)$contact['marketing_sms'])return ['allowed'=>false,'reason'=>'sms_opt_out'];
    }
    if($channel==='agent'&&$contact['marketing_push']!==null&&!(int)$contact['marketing_push'])
        return ['allowed'=>false,'reason'=>'agent_notification_opt_out'];
    return ['allowed'=>true,'reason'=>'marketing_consent'];
}

function campaigns_rewards_message_context_v120(PDO $pdo,array $delivery,array $contact,array $message): array
{
    $campaignId=(int)$delivery['campaign_id'];$issuanceId=max(0,(int)($delivery['reward_issuance_id']??0));
    $q=$pdo->prepare("SELECT c.name campaign_name,c.slug campaign_slug,c.public_id campaign_public_id,m.name merchant_name,m.public_id merchant_public_id
      FROM campaigns c INNER JOIN merchant_accounts m ON m.id=c.merchant_id WHERE c.id=? LIMIT 1");
    $q->execute([$campaignId]);$campaign=$q->fetch()?:[];
    $issuance=[];$reward=[];
    if($issuanceId>0){
        $q=$pdo->prepare("SELECT ri.*,rp.name reward_name,rp.public_id reward_product_public_id FROM reward_issuances ri
          INNER JOIN reward_products rp ON rp.id=ri.reward_product_id WHERE ri.id=? AND ri.campaign_id=? LIMIT 1");
        $q->execute([$issuanceId,$campaignId]);$issuance=$q->fetch()?:[];
        if($issuance)$reward=['name'=>(string)($issuance['reward_name']??''),'expires_at'=>(string)($issuance['expires_at']??'')];
    }
    $base=rtrim((string)site_config('base_url',''),'/');
    $campaignUrl=$base!==''?$base.url('/campaign/'.rawurlencode((string)($campaign['campaign_slug']??''))):url('/campaign/'.rawurlencode((string)($campaign['campaign_slug']??'')));
    $walletUrl=$base!==''?$base.url('/reward-inbox.php'):url('/reward-inbox.php');
    return [
        'contact'=>$contact,'campaign'=>$campaign,'issuance'=>$issuance,'reward'=>$reward,'message'=>$message,
        'tokens'=>[
            '{{name}}'=>(string)($contact['name']??''),
            '{{email}}'=>(string)($contact['email']??''),
            '{{campaign_name}}'=>(string)($campaign['campaign_name']??''),
            '{{merchant_name}}'=>(string)($campaign['merchant_name']??''),
            '{{reward_name}}'=>(string)($reward['name']??''),
            '{{reward_expiration}}'=>!empty($reward['expires_at'])?gmdate('M j, Y',strtotime((string)$reward['expires_at'])):'',
            '{{campaign_url}}'=>$campaignUrl,
            '{{reward_wallet_url}}'=>$walletUrl,
        ],
    ];
}

function campaigns_rewards_render_message_v120(string $text,array $tokens): string
{
    return strtr($text,$tokens);
}

function campaigns_rewards_register_message_sender_v120(string $channel,callable $sender): void
{
    if(!isset(campaigns_rewards_message_channels_v120()[$channel]))throw new InvalidArgumentException('Unsupported Campaign messaging channel.');
    $GLOBALS['vp3_campaign_message_senders_v120']??=[];
    $GLOBALS['vp3_campaign_message_senders_v120'][$channel]=$sender;
}

function campaigns_rewards_email_sender_v120(array $ctx): array
{
    if(!(bool)site_config('send_campaign_email',false))return ['status'=>'provider_unconfigured','source_type'=>'php_mail'];
    $to=trim((string)($ctx['contact']['email']??''));
    if(!filter_var($to,FILTER_VALIDATE_EMAIL))return ['status'=>'failed','reason'=>'invalid_email','source_type'=>'php_mail'];
    $from=trim((string)site_config('campaign_email_from',(string)site_config('email','')));
    if(!filter_var($from,FILTER_VALIDATE_EMAIL))return ['status'=>'provider_unconfigured','reason'=>'invalid_from','source_type'=>'php_mail'];
    $subject=preg_replace('/[\r\n]+/',' ',(string)($ctx['subject']??''))??'';
    $headers=['From: '.$from,'Content-Type: text/plain; charset=UTF-8'];
    $ok=@mail($to,$subject,(string)($ctx['body']??''),implode("\r\n",$headers));
    return $ok?['status'=>'sent','source_type'=>'php_mail']:['status'=>'failed','reason'=>'mail_failed','source_type'=>'php_mail'];
}

function campaigns_rewards_agent_sender_v120(array $ctx): array
{
    $userId=max(0,(int)($ctx['contact']['vp3_user_id']??0));
    if($userId<1)return ['status'=>'failed','reason'=>'no_vp3_user','source_type'=>'vp3_notification'];
    if(!function_exists('create_notification')||!table_exists('notifications'))
        return ['status'=>'provider_unconfigured','reason'=>'notification_runtime_unavailable','source_type'=>'vp3_notification'];
    create_notification(
        $userId,'campaign_message',
        campaigns_rewards_text_v100($ctx['subject']!==''?$ctx['subject']:($ctx['campaign']['campaign_name']??'Campaign update'),190),
        campaigns_rewards_text_v100($ctx['body']??'',500),
        !empty($ctx['issuance'])?url('/reward-inbox.php'):url('/campaign/'.rawurlencode((string)($ctx['campaign']['campaign_slug']??''))),
        'campaign_delivery',(int)($ctx['delivery_id']??0)
    );
    return ['status'=>'delivered','source_type'=>'vp3_notification','source_id'=>'notification'];
}

function campaigns_rewards_send_v120(string $channel,array $ctx): array
{
    $custom=$GLOBALS['vp3_campaign_message_senders_v120'][$channel]??null;
    if(is_callable($custom)){
        $result=$custom($ctx);
        if(!is_array($result))throw new RuntimeException('Campaign message sender must return an array result.');
        return $result;
    }
    return match($channel){
        'email'=>campaigns_rewards_email_sender_v120($ctx),
        'agent'=>campaigns_rewards_agent_sender_v120($ctx),
        'sms'=>['status'=>'provider_unconfigured','reason'=>'sms_adapter_required','source_type'=>'sms_adapter'],
        default=>['status'=>'failed','reason'=>'unsupported_channel','source_type'=>''],
    };
}

function campaigns_rewards_delivery_v120(PDO $pdo,int $deliveryId): ?array
{
    $q=$pdo->prepare("SELECT d.*,cm.message_key,cm.subject,cm.body,cm.template_json,cm.status message_status,cm.version_no,
      c.merchant_id,c.name campaign_name,c.slug campaign_slug,c.public_id campaign_public_id,c.status campaign_status,c.environment,
      m.public_id merchant_public_id,m.status merchant_status
      FROM campaign_deliveries d
      LEFT JOIN campaign_messages cm ON cm.id=d.message_id
      INNER JOIN campaigns c ON c.id=d.campaign_id
      INNER JOIN merchant_accounts m ON m.id=c.merchant_id
      WHERE d.id=? LIMIT 1");
    $q->execute([$deliveryId]);$row=$q->fetch();if(!$row)return null;
    $row['metadata']=json_decode((string)($row['metadata_json']??''),true)?:[];
    $row['template']=json_decode((string)($row['template_json']??''),true)?:[];
    return $row;
}

function campaigns_rewards_enqueue_delivery_v120(PDO $pdo,array $campaign,array $message,int $contactId,string $triggerEventId,array $context=[]): array
{
    $merchantId=(int)$campaign['merchant_id'];$campaignId=(int)$campaign['id'];
    $messageId=(int)$message['id'];$template=(array)($message['template']??[]);
    $trigger=(string)($template['trigger_event']??'manual');
    $baseTs=time();
    if(!empty($context['occurred_at'])){$ts=strtotime((string)$context['occurred_at']);if($ts!==false)$baseTs=$ts;}
    $scheduledTs=$baseTs+max(0,(int)($template['delay_minutes']??0))*60;
    if($trigger==='reward_expiring'&&!empty($context['expires_at'])){
        $expiresTs=strtotime((string)$context['expires_at']);
        if($expiresTs!==false)$scheduledTs=$expiresTs-(max(0,(int)($template['expiration_lead_days']??7))*86400)+(max(0,(int)($template['delay_minutes']??0))*60);
    }
    $scheduled=gmdate('Y-m-d H:i:s',$scheduledTs);
    $idempotency='v120:'.hash('sha256',implode('|',[
        $campaignId,$contactId,$messageId,(int)($message['version_no']??1),$trigger,$triggerEventId,
        (int)($context['reward_issuance_id']??0),
    ]));
    $request=[
        'campaign_id'=>$campaignId,'contact_id'=>$contactId,'message_id'=>$messageId,
        'trigger'=>$trigger,'trigger_event_id'=>$triggerEventId,'scheduled_for'=>$scheduled,
        'reward_issuance_id'=>max(0,(int)($context['reward_issuance_id']??0)),
    ];
    $idem=campaigns_rewards_idempotency_begin_v100($pdo,$merchantId,'journey.enqueue',$idempotency,$request);
    if(empty($idem['new'])&&($idem['status']??'')==='completed'&&($idem['result_ref_type']??'')==='campaign_delivery'){
        $existing=campaigns_rewards_delivery_v120($pdo,(int)$idem['result_ref_id']);
        if($existing)return ['duplicate'=>true,'delivery'=>$existing];
    }
    $meta=[
        'journey_key'=>(string)($template['journey_key']??'default'),
        'step_key'=>(string)($template['step_key']??$message['message_key']),
        'step_order'=>(int)($template['step_order']??1),
        'trigger_event'=>$trigger,'trigger_event_id'=>$triggerEventId,
        'scheduled_for'=>$scheduled,'purpose'=>(string)($template['purpose']??'marketing'),
        'stop_on_claim'=>!empty($template['stop_on_claim']),'stop_on_expiration'=>!empty($template['stop_on_expiration']),
        'idempotency_key'=>$idempotency,'attempts'=>0,
        'automation_rule_id'=>max(0,(int)($context['automation_rule_id']??0))?:null,
    ];
    $pdo->prepare("INSERT INTO campaign_deliveries
      (campaign_id,enrollment_id,contact_id,reward_issuance_id,message_id,channel,status,metadata_json,created_at)
      VALUES (?,?,?,?,?,?,'pending',?,UTC_TIMESTAMP())")->execute([
        $campaignId,max(0,(int)($context['enrollment_id']??0))?:null,$contactId,
        max(0,(int)($context['reward_issuance_id']??0))?:null,$messageId,(string)$message['channel'],
        campaigns_rewards_json_v100($meta),
    ]);
    $deliveryId=(int)$pdo->lastInsertId();
    campaigns_rewards_idempotency_complete_v100($pdo,(int)$idem['id'],'campaign_delivery',$deliveryId);
    campaigns_rewards_activity_event_v100($pdo,$merchantId,'campaign.journey_queued',[
        'campaign_id'=>$campaignId,'contact_id'=>$contactId,
        'enrollment_id'=>max(0,(int)($context['enrollment_id']??0)),
        'reward_issuance_id'=>max(0,(int)($context['reward_issuance_id']??0)),
    ],[
        'summary'=>'Campaign journey message queued','merchant_public_id'=>$campaign['merchant_public_id'],
        'campaign_public_id'=>$campaign['public_id'],'delivery_id'=>$deliveryId,'message_id'=>$messageId,
        'journey_key'=>$meta['journey_key'],'step_key'=>$meta['step_key'],'channel'=>$message['channel'],
        'scheduled_for'=>$scheduled,'trigger_event'=>$trigger,
    ],(string)$campaign['environment'],null,'automation');
    return ['duplicate'=>false,'delivery'=>campaigns_rewards_delivery_v120($pdo,$deliveryId)];
}

function campaigns_rewards_journey_enqueue_v120(PDO $pdo,int $campaignId,int $contactId,string $trigger,array $context=[],string $triggerEventId=''): array
{
    if(!isset(campaigns_rewards_journey_triggers_v120()[$trigger]))throw new RuntimeException('Unknown Campaign journey trigger.');
    $campaign=campaigns_rewards_campaign_platform_v100($pdo,$campaignId)?:throw new RuntimeException('Campaign not found.');
    if((string)$campaign['merchant_status']!=='active'||(string)$campaign['status']!=='active'||(string)$campaign['environment']!=='production')
        return ['trigger'=>$trigger,'queued'=>0,'duplicate'=>0,'suppressed'=>1,'reason'=>'campaign_inactive'];
    if((int)$campaign['current_version_no']<1)return ['trigger'=>$trigger,'queued'=>0,'duplicate'=>0,'suppressed'=>1,'reason'=>'campaign_unpublished'];
    $contact=campaigns_rewards_message_contact_v120($pdo,(int)$campaign['merchant_id'],$contactId);
    if(!$contact)return ['trigger'=>$trigger,'queued'=>0,'duplicate'=>0,'suppressed'=>1,'reason'=>'contact_unavailable'];
    $steps=campaigns_rewards_journey_steps_v120($pdo,$campaignId,$trigger);
    $summary=['trigger'=>$trigger,'steps'=>count($steps),'queued'=>0,'duplicate'=>0,'suppressed'=>0];
    $eventId=$triggerEventId!==''?$triggerEventId:$trigger.':'.$campaignId.':'.$contactId.':'.gmdate('YmdHi');
    foreach($steps as $message){
        $consent=campaigns_rewards_message_consent_v120($contact,(string)$message['channel'],(string)($message['template']['purpose']??'marketing'));
        if(empty($consent['allowed'])){$summary['suppressed']++;continue;}
        $result=campaigns_rewards_enqueue_delivery_v120($pdo,$campaign,$message,$contactId,$eventId,$context);
        if(!empty($result['duplicate']))$summary['duplicate']++;else $summary['queued']++;
    }
    return $summary;
}

function campaigns_rewards_delivery_suppression_v120(PDO $pdo,array $delivery,array $contact): ?string
{
    if((string)$delivery['merchant_status']!=='active'||(string)$delivery['campaign_status']!=='active'||(string)$delivery['environment']!=='production')return 'campaign_inactive';
    if((string)($delivery['message_status']??'')!=='active')return 'message_inactive';
    $consent=campaigns_rewards_message_consent_v120($contact,(string)$delivery['channel'],(string)($delivery['metadata']['purpose']??'marketing'));
    if(empty($consent['allowed']))return (string)$consent['reason'];
    $issuanceId=max(0,(int)($delivery['reward_issuance_id']??0));
    if($issuanceId>0&&(!empty($delivery['metadata']['stop_on_claim'])||!empty($delivery['metadata']['stop_on_expiration']))){
        $q=$pdo->prepare("SELECT status,expires_at,remaining_quantity FROM reward_issuances WHERE id=? AND campaign_id=? LIMIT 1");
        $q->execute([$issuanceId,(int)$delivery['campaign_id']]);$issuance=$q->fetch();
        if(!$issuance)return 'reward_unavailable';
        if(!empty($delivery['metadata']['stop_on_claim'])&&((string)$issuance['status']==='claimed'||(int)$issuance['remaining_quantity']<1))return 'reward_claimed';
        if(!empty($delivery['metadata']['stop_on_expiration'])&&!empty($issuance['expires_at'])&&strtotime((string)$issuance['expires_at'])<=time())return 'reward_expired';
        if(in_array((string)$issuance['status'],['voided','expired'],true))return 'reward_'.(string)$issuance['status'];
    }
    return null;
}

function campaigns_rewards_mark_delivery_v120(PDO $pdo,int $deliveryId,string $status,array $result=[]): array
{
    $allowed=['pending','sent','delivered','viewed','failed','suppressed','provider_unconfigured'];
    if(!in_array($status,$allowed,true))throw new RuntimeException('Invalid Campaign delivery status.');
    $delivery=campaigns_rewards_delivery_v120($pdo,$deliveryId)?:throw new RuntimeException('Campaign delivery not found.');
    $meta=$delivery['metadata'];$meta['attempts']=max(0,(int)($meta['attempts']??0))+($status==='pending'?0:1);
    if(!empty($result['reason']))$meta['last_error']=campaigns_rewards_text_v100($result['reason'],500);
    if(!empty($result['provider_message_id']))$meta['provider_message_id']=campaigns_rewards_text_v100($result['provider_message_id'],190);
    $sourceType=campaigns_rewards_text_v100($result['source_type']??$delivery['external_source_type'],60);
    $sourceId=campaigns_rewards_text_v100($result['source_id']??$result['provider_message_id']??$delivery['external_source_id'],190);
    $sentAt=in_array($status,['sent','delivered','viewed'],true)?'COALESCE(sent_at,UTC_TIMESTAMP())':'sent_at';
    $deliveredAt=in_array($status,['delivered','viewed'],true)?'COALESCE(delivered_at,UTC_TIMESTAMP())':'delivered_at';
    $viewedAt=$status==='viewed'?'COALESCE(viewed_at,UTC_TIMESTAMP())':'viewed_at';
    $failedAt=in_array($status,['failed','provider_unconfigured'],true)?'COALESCE(failed_at,UTC_TIMESTAMP())':'failed_at';
    $sql="UPDATE campaign_deliveries SET status=?,external_source_type=?,external_source_id=?,metadata_json=?,
      sent_at={$sentAt},delivered_at={$deliveredAt},viewed_at={$viewedAt},failed_at={$failedAt} WHERE id=?";
    $pdo->prepare($sql)->execute([$status,$sourceType,$sourceId,campaigns_rewards_json_v100($meta),$deliveryId]);

    $event=match($status){
        'sent'=>'campaign.message_sent','delivered'=>'campaign.message_delivered','viewed'=>'campaign.message_viewed',
        'failed','provider_unconfigured'=>'campaign.message_failed','suppressed'=>'campaign.message_suppressed',default=>'',
    };
    if($event!==''){
        campaigns_rewards_activity_event_v100($pdo,(int)$delivery['merchant_id'],$event,[
            'campaign_id'=>(int)$delivery['campaign_id'],'contact_id'=>(int)$delivery['contact_id'],
            'enrollment_id'=>max(0,(int)($delivery['enrollment_id']??0)),
            'reward_issuance_id'=>max(0,(int)($delivery['reward_issuance_id']??0)),
        ],[
            'summary'=>'Campaign message '.str_replace('_',' ',$status),'merchant_public_id'=>$delivery['merchant_public_id'],
            'campaign_public_id'=>$delivery['campaign_public_id'],'delivery_id'=>$deliveryId,
            'message_id'=>(int)$delivery['message_id'],'channel'=>$delivery['channel'],'status'=>$status,
            'reason'=>$result['reason']??null,
        ],(string)$delivery['environment'],null,'automation');
    }
    return campaigns_rewards_delivery_v120($pdo,$deliveryId)?:throw new RuntimeException('Campaign delivery unavailable.');
}

function campaigns_rewards_dispatch_delivery_v120(PDO $pdo,int $deliveryId): array
{
    $delivery=campaigns_rewards_delivery_v120($pdo,$deliveryId)?:throw new RuntimeException('Campaign delivery not found.');
    if((string)$delivery['status']!=='pending')return ['skipped'=>true,'reason'=>'not_pending','delivery'=>$delivery];
    $scheduled=(string)($delivery['metadata']['scheduled_for']??$delivery['created_at']);
    if(strtotime($scheduled)!==false&&strtotime($scheduled)>time())return ['skipped'=>true,'reason'=>'not_due','delivery'=>$delivery];

    $contact=campaigns_rewards_message_contact_v120($pdo,(int)$delivery['merchant_id'],(int)$delivery['contact_id']);
    if(!$contact)return ['skipped'=>false,'delivery'=>campaigns_rewards_mark_delivery_v120($pdo,$deliveryId,'suppressed',['reason'=>'contact_unavailable'])];
    $suppress=campaigns_rewards_delivery_suppression_v120($pdo,$delivery,$contact);
    if($suppress!==null)return ['skipped'=>false,'delivery'=>campaigns_rewards_mark_delivery_v120($pdo,$deliveryId,'suppressed',['reason'=>$suppress])];

    $message=['id'=>(int)$delivery['message_id'],'message_key'=>$delivery['message_key'],'subject'=>$delivery['subject'],'body'=>$delivery['body'],'template'=>$delivery['template']];
    $ctx=campaigns_rewards_message_context_v120($pdo,$delivery,$contact,$message);
    $subject=campaigns_rewards_render_message_v120((string)$delivery['subject'],$ctx['tokens']);
    $body=campaigns_rewards_render_message_v120((string)$delivery['body'],$ctx['tokens']);
    $sendCtx=$ctx+['subject'=>$subject,'body'=>$body,'delivery_id'=>$deliveryId];
    try{$result=campaigns_rewards_send_v120((string)$delivery['channel'],$sendCtx);}
    catch(Throwable $e){$result=['status'=>'failed','reason'=>$e->getMessage(),'source_type'=>'adapter'];}
    $status=(string)($result['status']??'failed');
    if(!in_array($status,['sent','delivered','viewed','failed','provider_unconfigured'],true))$status='failed';
    return ['skipped'=>false,'result'=>$result,'delivery'=>campaigns_rewards_mark_delivery_v120($pdo,$deliveryId,$status,$result)];
}

function campaigns_rewards_dispatch_due_v120(PDO $pdo,int $merchantId=0,int $limit=200): array
{
    $limit=max(1,min(1000,$limit));
    $sql="SELECT d.id,d.metadata_json FROM campaign_deliveries d
      INNER JOIN campaigns c ON c.id=d.campaign_id
      INNER JOIN merchant_accounts m ON m.id=c.merchant_id
      WHERE d.status='pending'";
    $params=[];if($merchantId>0){$sql.=" AND c.merchant_id=?";$params[]=$merchantId;}
    $sql.=" ORDER BY d.created_at,d.id LIMIT ".($limit*5);
    $q=$pdo->prepare($sql);$q->execute($params);
    $summary=['checked'=>0,'due'=>0,'sent'=>0,'delivered'=>0,'viewed'=>0,'failed'=>0,'provider_unconfigured'=>0,'suppressed'=>0,'skipped'=>0];
    foreach($q->fetchAll()?:[] as $row){
        if($summary['due']>=$limit)break;
        $summary['checked']++;$meta=json_decode((string)($row['metadata_json']??''),true)?:[];
        $scheduled=(string)($meta['scheduled_for']??'');
        if($scheduled!==''&&strtotime($scheduled)!==false&&strtotime($scheduled)>time()){$summary['skipped']++;continue;}
        $summary['due']++;
        try{$result=campaigns_rewards_dispatch_delivery_v120($pdo,(int)$row['id']);}
        catch(Throwable $e){$summary['failed']++;error_log('Campaign messaging V1.20 dispatch failed: '.$e->getMessage());continue;}
        if(!empty($result['skipped'])){$summary['skipped']++;continue;}
        $status=(string)($result['delivery']['status']??'failed');
        if(isset($summary[$status]))$summary[$status]++;else $summary['failed']++;
    }
    return $summary;
}

function campaigns_rewards_queue_expiration_reminders_v120(PDO $pdo,int $merchantId=0,int $lookAheadDays=30,int $limit=500): array
{
    $lookAheadDays=max(1,min(365,$lookAheadDays));$limit=max(1,min(2000,$limit));
    $sql="SELECT ri.id reward_issuance_id,ri.campaign_id,ri.recipient_contact_id contact_id,ri.expires_at,ri.campaign_enrollment_id enrollment_id,
      c.merchant_id FROM reward_issuances ri
      INNER JOIN campaigns c ON c.id=ri.campaign_id
      INNER JOIN merchant_accounts m ON m.id=c.merchant_id
      WHERE ri.environment='production' AND ri.status IN ('issued','sent','viewed') AND ri.remaining_quantity>0
        AND ri.expires_at IS NOT NULL AND ri.expires_at>UTC_TIMESTAMP()
        AND ri.expires_at<=DATE_ADD(UTC_TIMESTAMP(),INTERVAL {$lookAheadDays} DAY)
        AND c.status='active' AND m.status='active'";
    $params=[];if($merchantId>0){$sql.=" AND c.merchant_id=?";$params[]=$merchantId;}
    $sql.=" ORDER BY ri.expires_at,ri.id LIMIT {$limit}";
    $q=$pdo->prepare($sql);$q->execute($params);
    $summary=['issuances'=>0,'queued'=>0,'duplicate'=>0,'suppressed'=>0];
    foreach($q->fetchAll()?:[] as $row){
        $summary['issuances']++;
        $r=campaigns_rewards_journey_enqueue_v120($pdo,(int)$row['campaign_id'],(int)$row['contact_id'],'reward_expiring',[
            'reward_issuance_id'=>(int)$row['reward_issuance_id'],'enrollment_id'=>(int)($row['enrollment_id']??0),
            'expires_at'=>(string)$row['expires_at'],
        ],'reward-expiring:'.(int)$row['reward_issuance_id']);
        foreach(['queued','duplicate','suppressed'] as $k)$summary[$k]+=(int)($r[$k]??0);
    }
    return $summary;
}

function campaigns_rewards_message_record_event_v120(PDO $pdo,int $deliveryId,string $event,array $metadata=[]): array
{
    $map=['delivered'=>'delivered','viewed'=>'viewed'];
    if(!isset($map[$event]))throw new RuntimeException('Unsupported Campaign message event.');
    $delivery=campaigns_rewards_delivery_v120($pdo,$deliveryId)?:throw new RuntimeException('Campaign delivery not found.');
    if(!in_array((string)$delivery['status'],['sent','delivered','viewed'],true))throw new RuntimeException('Campaign delivery has not been sent.');
    return campaigns_rewards_mark_delivery_v120($pdo,$deliveryId,$map[$event],$metadata);
}

function campaigns_rewards_attribute_claim_v120(PDO $pdo,int $campaignId,int $contactId,int $rewardIssuanceId,int $claimId): ?array
{
    if($campaignId<1||$contactId<1||$claimId<1)return null;
    $q=$pdo->prepare("SELECT d.* FROM campaign_deliveries d
      WHERE d.campaign_id=? AND d.contact_id=? AND d.status IN ('sent','delivered','viewed')
        AND (d.reward_issuance_id IS NULL OR d.reward_issuance_id=?)
      ORDER BY COALESCE(d.viewed_at,d.delivered_at,d.sent_at,d.created_at) DESC,d.id DESC LIMIT 1");
    $q->execute([$campaignId,$contactId,$rewardIssuanceId]);$delivery=$q->fetch();if(!$delivery)return null;
    $meta=json_decode((string)($delivery['metadata_json']??''),true)?:[];
    $meta['attributed_claim_id']=$claimId;$meta['converted_at']=gmdate('Y-m-d H:i:s');
    $pdo->prepare("UPDATE campaign_deliveries SET metadata_json=? WHERE id=?")->execute([campaigns_rewards_json_v100($meta),(int)$delivery['id']]);
    $campaign=campaigns_rewards_campaign_platform_v100($pdo,$campaignId);
    if($campaign)campaigns_rewards_activity_event_v100($pdo,(int)$campaign['merchant_id'],'campaign.message_converted',[
        'campaign_id'=>$campaignId,'contact_id'=>$contactId,'reward_issuance_id'=>$rewardIssuanceId,'claim_id'=>$claimId,
    ],[
        'summary'=>'Campaign message attributed to Reward Claim','merchant_public_id'=>$campaign['merchant_public_id'],
        'campaign_public_id'=>$campaign['public_id'],'delivery_id'=>(int)$delivery['id'],'claim_id'=>$claimId,
    ],(string)$campaign['environment'],null,'system');
    return $delivery+['attributed_claim_id'=>$claimId];
}

function campaigns_rewards_message_performance_v120(PDO $pdo,int $campaignId,int $actorUserId): array
{
    $campaign=campaigns_rewards_campaign_platform_v100($pdo,$campaignId)?:throw new RuntimeException('Campaign not found.');
    campaigns_rewards_platform_assert_can_v100($pdo,(int)$campaign['merchant_id'],$actorUserId,'analytics.view');
    $q=$pdo->prepare("SELECT channel,status,COUNT(*) n FROM campaign_deliveries WHERE campaign_id=? GROUP BY channel,status");
    $q->execute([$campaignId]);
    $totals=['queued'=>0,'sent'=>0,'delivered'=>0,'viewed'=>0,'failed'=>0,'suppressed'=>0,'provider_unconfigured'=>0,'converted'=>0];
    $channels=[];
    foreach($q->fetchAll()?:[] as $row){
        $channel=(string)$row['channel'];$status=(string)$row['status'];$n=(int)$row['n'];
        $channels[$channel][$status]=$n;$totals['queued']+=$n;
        if(isset($totals[$status]))$totals[$status]+=$n;
    }
    $q=$pdo->prepare("SELECT COUNT(*) FROM campaign_deliveries WHERE campaign_id=? AND JSON_EXTRACT(metadata_json,'$.attributed_claim_id') IS NOT NULL");
    $q->execute([$campaignId]);$totals['converted']=(int)$q->fetchColumn();
    $sentBase=$totals['sent']+$totals['delivered']+$totals['viewed'];
    $deliveryBase=$totals['delivered']+$totals['viewed'];
    return [
        'totals'=>$totals,'channels'=>$channels,
        'delivery_rate'=>$sentBase>0?round($deliveryBase*100/$sentBase,1):0.0,
        'view_rate'=>$sentBase>0?round($totals['viewed']*100/$sentBase,1):0.0,
        'conversion_rate'=>$sentBase>0?round($totals['converted']*100/$sentBase,1):0.0,
    ];
}

function campaigns_rewards_run_due_v120(PDO $pdo,int $merchantId=0): array
{
    $automation=function_exists('campaigns_rewards_automation_run_due_v119')?campaigns_rewards_automation_run_due_v119($pdo,$merchantId):[];
    $expiration=campaigns_rewards_queue_expiration_reminders_v120($pdo,$merchantId);
    $delivery=campaigns_rewards_dispatch_due_v120($pdo,$merchantId);
    return ['automation'=>$automation,'expiration_reminders'=>$expiration,'deliveries'=>$delivery];
}
