<?php
declare(strict_types=1);

/**
 * Campaigns & Rewards V1.21 — Journey Orchestration & Delivery Providers.
 *
 * V1.21 remains an additive runtime over the canonical V1.20 campaign_messages,
 * campaign_deliveries and campaign_idempotency_keys authorities. Orchestration
 * nodes are versioned campaign_messages; durable node instances are
 * campaign_deliveries. No second queue, scheduler, CRM, Wallet or event ledger.
 */
const VP3_CAMPAIGNS_REWARDS_V121='vp3-campaigns-rewards-v121-20260923';

function campaigns_rewards_journey_node_types_v121(): array
{
    return [
        'message'=>'Message',
        'decision'=>'Decision / branch',
        'wait_until'=>'Wait until',
        'exit'=>'Exit journey',
    ];
}

function campaigns_rewards_journey_condition_fields_v121(): array
{
    return [
        'contact.marketing_status'=>'Contact marketing status',
        'contact.customer_status'=>'Merchant customer status',
        'contact.loyalty_status'=>'Merchant loyalty status',
        'contact.preferred_channel'=>'Preferred channel',
        'context.balance'=>'Trigger loyalty balance',
        'context.amount_paid_cents'=>'Trigger purchase amount',
        'context.referrer_contact_id'=>'Trigger referrer contact',
        'reward.status'=>'Reward status',
        'reward.remaining_quantity'=>'Reward remaining quantity',
        'delivery.converted'=>'Journey conversion recorded',
        'campaign.status'=>'Campaign status',
    ];
}

function campaigns_rewards_journey_condition_operators_v121(): array
{
    return [
        'equals'=>'Equals',
        'not_equals'=>'Does not equal',
        'gte'=>'Greater than or equal',
        'lte'=>'Less than or equal',
        'contains'=>'Contains',
        'truthy'=>'Is true / present',
        'falsy'=>'Is false / empty',
    ];
}

function campaigns_rewards_journey_wait_modes_v121(): array
{
    return [
        'delay'=>'Relative delay',
        'utc'=>'Fixed UTC date/time',
        'local_time'=>'Local clock time',
    ];
}

function campaigns_rewards_message_key_v121(string $journeyKey,string $stepKey,string $variantKey='default'): string
{
    $journey=campaigns_rewards_slug_v100($journeyKey,30)?:'journey';
    $step=campaigns_rewards_slug_v100($stepKey,30)?:'step';
    $variant=campaigns_rewards_slug_v100($variantKey,16)?:'default';
    return substr($journey.'--'.$step.'--'.$variant,0,80);
}

function campaigns_rewards_journey_node_template_v121(array $input,array $previous=[]): array
{
    $nodeType=(string)($input['node_type']??$previous['node_type']??'message');
    if(!isset(campaigns_rewards_journey_node_types_v121()[$nodeType]))$nodeType='message';

    $trigger=(string)($input['trigger_event']??$previous['trigger_event']??'manual');
    if(!isset(campaigns_rewards_journey_triggers_v120()[$trigger]))$trigger='manual';

    $purpose=(string)($input['purpose']??$previous['purpose']??'marketing');
    if(!isset(campaigns_rewards_message_purposes_v120()[$purpose]))$purpose='marketing';

    $waitMode=(string)($input['wait_mode']??$previous['wait_mode']??'delay');
    if(!isset(campaigns_rewards_journey_wait_modes_v121()[$waitMode]))$waitMode='delay';

    $operator=(string)($input['condition_operator']??$previous['condition_operator']??'equals');
    if(!isset(campaigns_rewards_journey_condition_operators_v121()[$operator]))$operator='equals';

    $conditionField=(string)($input['condition_field']??$previous['condition_field']??'contact.marketing_status');
    if(!isset(campaigns_rewards_journey_condition_fields_v121()[$conditionField]))$conditionField='contact.marketing_status';

    $localSend=trim((string)($input['local_send_time']??$previous['local_send_time']??''));
    if($localSend!==''&&!preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/',$localSend))$localSend='';
    $waitLocal=trim((string)($input['wait_local_time']??$previous['wait_local_time']??''));
    if($waitLocal!==''&&!preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/',$waitLocal))$waitLocal='';

    return [
        'kind'=>'journey_node',
        'node_type'=>$nodeType,
        'journey_key'=>campaigns_rewards_slug_v100((string)($input['journey_key']??$previous['journey_key']??'default'),50)?:'default',
        'step_key'=>campaigns_rewards_slug_v100((string)($input['step_key']??$previous['step_key']??'step'),50)?:'step',
        'variant_key'=>campaigns_rewards_slug_v100((string)($input['variant_key']??$previous['variant_key']??'default'),30)?:'default',
        'variant_weight'=>max(1,min(10000,(int)($input['variant_weight']??$previous['variant_weight']??100))),
        'step_order'=>max(1,min(999,(int)($input['step_order']??$previous['step_order']??1))),
        'trigger_event'=>$trigger,
        'entry_node'=>!empty($input['entry_node'])||(!array_key_exists('entry_node',$input)&&!empty($previous['entry_node'])),
        'next_step_key'=>campaigns_rewards_slug_v100((string)($input['next_step_key']??$previous['next_step_key']??''),50),
        'true_next_step_key'=>campaigns_rewards_slug_v100((string)($input['true_next_step_key']??$previous['true_next_step_key']??''),50),
        'false_next_step_key'=>campaigns_rewards_slug_v100((string)($input['false_next_step_key']??$previous['false_next_step_key']??''),50),
        'condition_field'=>$conditionField,
        'condition_operator'=>$operator,
        'condition_value'=>campaigns_rewards_text_v100($input['condition_value']??$previous['condition_value']??'',190),
        'delay_minutes'=>max(0,min(5256000,(int)($input['delay_minutes']??$previous['delay_minutes']??0))),
        'wait_mode'=>$waitMode,
        'wait_until'=>campaigns_rewards_datetime_v100((string)($input['wait_until']??$previous['wait_until']??'')),
        'wait_local_time'=>$waitLocal,
        'wait_days'=>max(0,min(3650,(int)($input['wait_days']??$previous['wait_days']??0))),
        'local_send_time'=>$localSend,
        'expiration_lead_days'=>max(0,min(3650,(int)($input['expiration_lead_days']??$previous['expiration_lead_days']??7))),
        'purpose'=>$purpose,
        'respect_quiet_hours'=>!array_key_exists('respect_quiet_hours',$input)?($previous['respect_quiet_hours']??true):!empty($input['respect_quiet_hours']),
        'exit_on_conversion'=>!empty($input['exit_on_conversion'])||(!array_key_exists('exit_on_conversion',$input)&&!empty($previous['exit_on_conversion'])),
        'stop_on_claim'=>!empty($input['stop_on_claim'])||(!array_key_exists('stop_on_claim',$input)&&!empty($previous['stop_on_claim'])),
        'stop_on_expiration'=>!empty($input['stop_on_expiration'])||(!array_key_exists('stop_on_expiration',$input)&&!empty($previous['stop_on_expiration'])),
        'retry_max_attempts'=>max(1,min(10,(int)($input['retry_max_attempts']??$previous['retry_max_attempts']??3))),
        'retry_backoff_minutes'=>max(1,min(1440,(int)($input['retry_backoff_minutes']??$previous['retry_backoff_minutes']??5))),
    ];
}

function campaigns_rewards_journey_node_save_v121(PDO $pdo,int $merchantId,int $campaignId,int $actorUserId,array $input,int $messageId=0): array
{
    campaigns_rewards_platform_assert_can_v100($pdo,$merchantId,$actorUserId,'campaigns.edit');
    $campaign=campaigns_rewards_campaign_platform_v100($pdo,$campaignId)?:throw new RuntimeException('Campaign not found.');
    if((int)$campaign['merchant_id']!==$merchantId)throw new RuntimeException('Campaign not found.');

    $previous=null;$previousTemplate=[];
    if($messageId>0){
        $previous=campaigns_rewards_message_v120($pdo,$messageId)?:throw new RuntimeException('Journey node not found.');
        if((int)$previous['merchant_id']!==$merchantId||(int)$previous['campaign_id']!==$campaignId)throw new RuntimeException('Journey node not found.');
        $previousTemplate=(array)($previous['template']??[]);
    }
    $template=campaigns_rewards_journey_node_template_v121($input,$previousTemplate);
    if($previous){
        foreach(['journey_key','step_key','variant_key'] as $locked){
            if(!empty($previousTemplate[$locked]))$template[$locked]=(string)$previousTemplate[$locked];
        }
    }

    $nodeType=(string)$template['node_type'];
    $channel=$nodeType==='message'?(string)($input['channel']??$previous['channel']??'email'):'orchestration';
    if($nodeType==='message'&&!isset(campaigns_rewards_message_channels_v120()[$channel]))throw new RuntimeException('Choose a supported message channel.');
    $subject=$nodeType==='message'?campaigns_rewards_text_v100($input['subject']??$previous['subject']??'',255):campaigns_rewards_text_v100($input['subject']??$previous['subject']??ucwords(str_replace('_',' ',$nodeType)),255);
    $body=$nodeType==='message'?trim((string)($input['body']??$previous['body']??'')):trim((string)($input['body']??$previous['body']??''));
    if($nodeType==='message'&&$body==='')throw new RuntimeException('Message body is required.');
    if(mb_strlen($body)>20000)throw new RuntimeException('Journey node body is too long.');

    if($nodeType==='decision'){
        if($template['true_next_step_key']===''&&$template['false_next_step_key']==='')throw new RuntimeException('Decision nodes require at least one branch target.');
    }
    if($nodeType==='wait_until'&&$template['next_step_key']==='')throw new RuntimeException('Wait-until nodes require a next step.');
    if($nodeType==='message'&&$template['variant_key']!=='default'&&$template['next_step_key']==='')
        throw new RuntimeException('A/B message variants require an explicit next step so all variants converge.');

    $status=(string)($input['status']??$previous['status']??'draft');
    if(!in_array($status,['draft','active','paused'],true))$status='draft';
    if($status==='active'){
        campaigns_rewards_platform_assert_can_v100($pdo,$merchantId,$actorUserId,'campaigns.publish');
        if((string)$campaign['status']!=='active'||(int)$campaign['current_version_no']<1)
            throw new RuntimeException('Activate and publish the Campaign before activating a journey node.');
    }

    $messageKey=$previous?(string)$previous['message_key']:campaigns_rewards_message_key_v121((string)$template['journey_key'],(string)$template['step_key'],(string)$template['variant_key']);
    $q=$pdo->prepare("SELECT COALESCE(MAX(version_no),0) FROM campaign_messages WHERE campaign_id=? AND message_key=?");
    $q->execute([$campaignId,$messageKey]);$version=(int)$q->fetchColumn()+1;

    $owns=!$pdo->inTransaction();if($owns)$pdo->beginTransaction();
    try{
        if($previous)$pdo->prepare("UPDATE campaign_messages SET status='superseded',updated_at=UTC_TIMESTAMP() WHERE id=? AND status<>'superseded'")->execute([(int)$previous['id']]);
        $pdo->prepare("INSERT INTO campaign_messages
          (campaign_id,message_key,channel,subject,body,template_json,status,version_no,created_at,updated_at)
          VALUES (?,?,?,?,?,?,?, ?,UTC_TIMESTAMP(),UTC_TIMESTAMP())")
          ->execute([$campaignId,$messageKey,$channel,$subject,$body,campaigns_rewards_json_v100($template),$status,$version]);
        $newId=(int)$pdo->lastInsertId();
        if($owns)$pdo->commit();
    }catch(Throwable $e){if($owns&&$pdo->inTransaction())$pdo->rollBack();throw $e;}

    campaigns_rewards_activity_event_v100($pdo,$merchantId,'campaign.journey_node_saved',['campaign_id'=>$campaignId],[
        'summary'=>'Campaign journey node saved','merchant_public_id'=>$campaign['merchant_public_id'],
        'campaign_public_id'=>$campaign['public_id'],'message_id'=>$newId,'message_key'=>$messageKey,
        'journey_key'=>$template['journey_key'],'step_key'=>$template['step_key'],'variant_key'=>$template['variant_key'],
        'node_type'=>$nodeType,'version_no'=>$version,'status'=>$status,
    ],(string)$campaign['environment'],$actorUserId);
    return campaigns_rewards_message_v120($pdo,$newId)?:throw new RuntimeException('Journey node could not be loaded.');
}

function campaigns_rewards_journey_nodes_v121(PDO $pdo,int $campaignId,string $journeyKey='',string $trigger=''): array
{
    $q=$pdo->prepare("SELECT cm.* FROM campaign_messages cm
      INNER JOIN (
        SELECT campaign_id,message_key,MAX(version_no) latest_version
        FROM campaign_messages WHERE campaign_id=? GROUP BY campaign_id,message_key
      ) latest ON latest.campaign_id=cm.campaign_id AND latest.message_key=cm.message_key AND latest.latest_version=cm.version_no
      WHERE cm.campaign_id=? AND cm.status='active' ORDER BY cm.id");
    $q->execute([$campaignId,$campaignId]);$out=[];
    foreach($q->fetchAll()?:[] as $row){
        $row['template']=json_decode((string)($row['template_json']??''),true)?:[];
        $t=(array)$row['template'];
        if(($t['kind']??'')!=='journey_node')continue;
        if($journeyKey!==''&&(string)($t['journey_key']??'')!==$journeyKey)continue;
        if($trigger!==''&&(string)($t['trigger_event']??'manual')!==$trigger)continue;
        $out[]=$row;
    }
    usort($out,static fn(array $a,array $b):int=>[
        (int)($a['template']['step_order']??999),(string)($a['template']['step_key']??''),(string)($a['template']['variant_key']??'default'),(int)$a['id']
    ]<=>[
        (int)($b['template']['step_order']??999),(string)($b['template']['step_key']??''),(string)($b['template']['variant_key']??'default'),(int)$b['id']
    ]);
    return $out;
}

function campaigns_rewards_journey_group_nodes_v121(array $nodes): array
{
    $out=[];
    foreach($nodes as $node){
        $t=(array)($node['template']??[]);
        $journey=(string)($t['journey_key']??'default');$step=(string)($t['step_key']??'step');
        $out[$journey][$step][]=$node;
    }
    return $out;
}

function campaigns_rewards_select_variant_v121(array $variants,int $campaignId,int $contactId,string $instanceKey,string $stepKey): ?array
{
    if(!$variants)return null;
    usort($variants,static fn(array $a,array $b):int=>strcmp((string)($a['template']['variant_key']??'default'),(string)($b['template']['variant_key']??'default')));
    $total=0;foreach($variants as $v)$total+=max(1,(int)($v['template']['variant_weight']??100));
    $bucket=hexdec(substr(hash('sha256',$campaignId.'|'.$contactId.'|'.$instanceKey.'|'.$stepKey),0,8))%max(1,$total);
    $cursor=0;
    foreach($variants as $v){
        $cursor+=max(1,(int)($v['template']['variant_weight']??100));
        if($bucket<$cursor)return $v;
    }
    return $variants[array_key_last($variants)];
}

function campaigns_rewards_valid_timezone_v121(string $timezone,string $fallback='UTC'): string
{
    $timezone=trim($timezone);if($timezone==='')$timezone=$fallback;
    try{new DateTimeZone($timezone);return $timezone;}catch(Throwable $e){}
    try{new DateTimeZone($fallback);return $fallback;}catch(Throwable $e){return 'UTC';}
}

function campaigns_rewards_contact_timezone_v121(PDO $pdo,int $merchantId,array $contact): string
{
    $quiet=json_decode((string)($contact['quiet_hours_json']??''),true);if(!is_array($quiet))$quiet=[];
    $consent=json_decode((string)($contact['consent_json']??''),true);if(!is_array($consent))$consent=[];
    $candidate=(string)($quiet['timezone']??$consent['timezone']??'');
    if($candidate!=='')return campaigns_rewards_valid_timezone_v121($candidate);
    $q=$pdo->prepare("SELECT timezone FROM merchant_accounts WHERE id=? LIMIT 1");$q->execute([$merchantId]);
    return campaigns_rewards_valid_timezone_v121((string)($q->fetchColumn()?:'UTC'));
}

function campaigns_rewards_local_clock_utc_v121(int $baseUtc,string $timezone,string $clock,int $days=0): int
{
    if(!preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/',$clock))return $baseUtc;
    $tz=new DateTimeZone(campaigns_rewards_valid_timezone_v121($timezone));
    $local=(new DateTimeImmutable('@'.$baseUtc))->setTimezone($tz);
    $target=$local->setTime((int)substr($clock,0,2),(int)substr($clock,3,2),0)->modify('+'.max(0,$days).' days');
    if($days===0&&$target->getTimestamp()<$baseUtc)$target=$target->modify('+1 day');
    return $target->setTimezone(new DateTimeZone('UTC'))->getTimestamp();
}

function campaigns_rewards_next_allowed_send_v121(int $utcTs,string $timezone,array $quiet): int
{
    if(empty($quiet['enabled'])&&empty($quiet['start'])&&empty($quiet['end']))return $utcTs;
    $start=(string)($quiet['start']??'');$end=(string)($quiet['end']??'');
    if(!preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/',$start)||!preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/',$end)||$start===$end)return $utcTs;
    $tz=new DateTimeZone(campaigns_rewards_valid_timezone_v121((string)($quiet['timezone']??$timezone),$timezone));
    $local=(new DateTimeImmutable('@'.$utcTs))->setTimezone($tz);
    $time=$local->format('H:i');$inside=false;$endDate=$local;
    if($start<$end){
        $inside=$time>=$start&&$time<$end;
        if($inside)$endDate=$local->setTime((int)substr($end,0,2),(int)substr($end,3,2),0);
    }else{
        $inside=$time>=$start||$time<$end;
        if($inside){
            $endDate=$local->setTime((int)substr($end,0,2),(int)substr($end,3,2),0);
            if($time>=$start)$endDate=$endDate->modify('+1 day');
        }
    }
    return $inside?$endDate->setTimezone(new DateTimeZone('UTC'))->getTimestamp():$utcTs;
}

function campaigns_rewards_journey_context_v121(array $context): array
{
    $allowed=['reward_issuance_id','enrollment_id','automation_rule_id','balance','amount_paid_cents','referrer_contact_id','expires_at','occurred_at'];
    $out=[];foreach($allowed as $key)if(array_key_exists($key,$context))$out[$key]=$context[$key];
    return $out;
}

function campaigns_rewards_node_schedule_v121(PDO $pdo,array $campaign,array $node,array $contact,array $context,int $baseTs=0): int
{
    $t=(array)($node['template']??[]);$baseTs=$baseTs>0?$baseTs:time();
    if(!empty($context['occurred_at'])){$parsed=strtotime((string)$context['occurred_at']);if($parsed!==false)$baseTs=$parsed;}
    $scheduled=$baseTs+max(0,(int)($t['delay_minutes']??0))*60;
    if((string)($t['trigger_event']??'')==='reward_expiring'&&!empty($context['expires_at'])){
        $expires=strtotime((string)$context['expires_at']);
        if($expires!==false)$scheduled=$expires-(max(0,(int)($t['expiration_lead_days']??7))*86400)+max(0,(int)($t['delay_minutes']??0))*60;
    }
    $timezone=campaigns_rewards_contact_timezone_v121($pdo,(int)$campaign['merchant_id'],$contact);
    $waitMode=(string)($t['wait_mode']??'delay');
    if($waitMode==='utc'&&!empty($t['wait_until'])){
        $fixed=strtotime((string)$t['wait_until']);if($fixed!==false)$scheduled=max($scheduled,$fixed);
    }elseif($waitMode==='local_time'&&!empty($t['wait_local_time'])){
        $scheduled=campaigns_rewards_local_clock_utc_v121($scheduled,$timezone,(string)$t['wait_local_time'],(int)($t['wait_days']??0));
    }
    if((string)($t['node_type']??'message')==='message'&&!empty($t['local_send_time'])){
        $scheduled=campaigns_rewards_local_clock_utc_v121($scheduled,$timezone,(string)$t['local_send_time'],0);
    }
    return max(time()-60,$scheduled);
}

function campaigns_rewards_enqueue_node_v121(PDO $pdo,array $campaign,array $node,int $contactId,string $instanceKey,string $triggerEventId,array $context=[]): array
{
    $contact=campaigns_rewards_message_contact_v120($pdo,(int)$campaign['merchant_id'],$contactId);
    if(!$contact)return ['suppressed'=>true,'reason'=>'contact_unavailable'];
    $t=(array)($node['template']??[]);$nodeType=(string)($t['node_type']??'message');
    if($nodeType==='message'){
        $consent=campaigns_rewards_message_consent_v120($contact,(string)$node['channel'],(string)($t['purpose']??'marketing'));
        if(empty($consent['allowed']))return ['suppressed'=>true,'reason'=>(string)$consent['reason']];
    }
    $safeContext=campaigns_rewards_journey_context_v121($context);
    $scheduled=gmdate('Y-m-d H:i:s',campaigns_rewards_node_schedule_v121($pdo,$campaign,$node,$contact,$safeContext));
    $idempotency='v121:'.hash('sha256',implode('|',[
        (int)$campaign['id'],$contactId,$instanceKey,(string)($t['step_key']??''),(string)($t['variant_key']??'default'),(int)$node['id'],(int)$node['version_no']
    ]));
    $request=['campaign_id'=>(int)$campaign['id'],'contact_id'=>$contactId,'message_id'=>(int)$node['id'],'instance_key'=>$instanceKey,'scheduled_for'=>$scheduled];
    $idem=campaigns_rewards_idempotency_begin_v100($pdo,(int)$campaign['merchant_id'],'journey.node.enqueue',$idempotency,$request);
    if(empty($idem['new'])&&($idem['status']??'')==='completed'&&($idem['result_ref_type']??'')==='campaign_delivery'){
        $existing=campaigns_rewards_delivery_v120($pdo,(int)$idem['result_ref_id']);
        if($existing)return ['duplicate'=>true,'delivery'=>$existing];
    }

    $meta=[
        'runtime'=>'v1.21','journey_instance_key'=>$instanceKey,
        'journey_key'=>(string)($t['journey_key']??'default'),'step_key'=>(string)($t['step_key']??$node['message_key']),
        'variant_key'=>(string)($t['variant_key']??'default'),'variant_weight'=>(int)($t['variant_weight']??100),
        'node_type'=>$nodeType,'step_order'=>(int)($t['step_order']??1),
        'trigger_event'=>(string)($t['trigger_event']??'manual'),'trigger_event_id'=>$triggerEventId,
        'scheduled_for'=>$scheduled,'purpose'=>(string)($t['purpose']??'marketing'),
        'respect_quiet_hours'=>!empty($t['respect_quiet_hours']),'exit_on_conversion'=>!empty($t['exit_on_conversion']),
        'stop_on_claim'=>!empty($t['stop_on_claim']),'stop_on_expiration'=>!empty($t['stop_on_expiration']),
        'retry_max_attempts'=>(int)($t['retry_max_attempts']??3),'retry_backoff_minutes'=>(int)($t['retry_backoff_minutes']??5),
        'context'=>$safeContext,'attempts'=>0,'idempotency_key'=>$idempotency,
    ];
    $pdo->prepare("INSERT INTO campaign_deliveries
      (campaign_id,enrollment_id,contact_id,reward_issuance_id,message_id,channel,status,metadata_json,created_at)
      VALUES (?,?,?,?,?,?,'pending',?,UTC_TIMESTAMP())")->execute([
        (int)$campaign['id'],max(0,(int)($safeContext['enrollment_id']??0))?:null,$contactId,
        max(0,(int)($safeContext['reward_issuance_id']??0))?:null,(int)$node['id'],(string)$node['channel'],campaigns_rewards_json_v100($meta),
    ]);
    $deliveryId=(int)$pdo->lastInsertId();campaigns_rewards_idempotency_complete_v100($pdo,(int)$idem['id'],'campaign_delivery',$deliveryId);
    campaigns_rewards_activity_event_v100($pdo,(int)$campaign['merchant_id'],'campaign.journey_node_queued',[
        'campaign_id'=>(int)$campaign['id'],'contact_id'=>$contactId,'reward_issuance_id'=>max(0,(int)($safeContext['reward_issuance_id']??0))
    ],[
        'summary'=>'Campaign journey node queued','campaign_public_id'=>$campaign['public_id'],'delivery_id'=>$deliveryId,
        'journey_key'=>$meta['journey_key'],'step_key'=>$meta['step_key'],'variant_key'=>$meta['variant_key'],'node_type'=>$nodeType,'scheduled_for'=>$scheduled,
    ],(string)$campaign['environment'],null,'automation');
    return ['duplicate'=>false,'delivery'=>campaigns_rewards_delivery_v120($pdo,$deliveryId)];
}

function campaigns_rewards_journey_enqueue_v121(PDO $pdo,int $campaignId,int $contactId,string $trigger,array $context=[],string $triggerEventId=''): array
{
    if(!isset(campaigns_rewards_journey_triggers_v120()[$trigger]))throw new RuntimeException('Unknown Campaign journey trigger.');
    $nodes=campaigns_rewards_journey_nodes_v121($pdo,$campaignId,'',$trigger);
    if(!$nodes)return campaigns_rewards_journey_enqueue_v120($pdo,$campaignId,$contactId,$trigger,$context,$triggerEventId);

    $campaign=campaigns_rewards_campaign_platform_v100($pdo,$campaignId)?:throw new RuntimeException('Campaign not found.');
    if((string)$campaign['merchant_status']!=='active'||(string)$campaign['status']!=='active'||(string)$campaign['environment']!=='production')
        return ['trigger'=>$trigger,'queued'=>0,'duplicate'=>0,'suppressed'=>1,'reason'=>'campaign_inactive'];
    if((int)$campaign['current_version_no']<1)return ['trigger'=>$trigger,'queued'=>0,'duplicate'=>0,'suppressed'=>1,'reason'=>'campaign_unpublished'];

    $groups=campaigns_rewards_journey_group_nodes_v121($nodes);$eventId=$triggerEventId!==''?$triggerEventId:$trigger.':'.$campaignId.':'.$contactId.':'.gmdate('YmdHi');
    $summary=['trigger'=>$trigger,'journeys'=>0,'queued'=>0,'duplicate'=>0,'suppressed'=>0];
    foreach($groups as $journeyKey=>$steps){
        $entries=[];
        foreach($steps as $stepKey=>$variants){
            foreach($variants as $node)if(!empty($node['template']['entry_node'])){$entries[$stepKey]=$variants;break;}
        }
        if(!$entries){
            $first=null;$order=PHP_INT_MAX;
            foreach($steps as $stepKey=>$variants){$o=(int)($variants[0]['template']['step_order']??999);if($o<$order){$order=$o;$first=$stepKey;}}
            if($first!==null)$entries[$first]=$steps[$first];
        }
        if(!$entries)continue;$summary['journeys']++;
        $instance='v121:'.hash('sha256',$campaignId.'|'.$contactId.'|'.$journeyKey.'|'.$eventId);
        foreach($entries as $stepKey=>$variants){
            $node=campaigns_rewards_select_variant_v121($variants,$campaignId,$contactId,$instance,$stepKey);if(!$node)continue;
            $r=campaigns_rewards_enqueue_node_v121($pdo,$campaign,$node,$contactId,$instance,$eventId,$context);
            if(!empty($r['suppressed']))$summary['suppressed']++;elseif(!empty($r['duplicate']))$summary['duplicate']++;else $summary['queued']++;
        }
        campaigns_rewards_activity_event_v100($pdo,(int)$campaign['merchant_id'],'campaign.journey_started',['campaign_id'=>$campaignId,'contact_id'=>$contactId],[
            'summary'=>'Campaign journey started','campaign_public_id'=>$campaign['public_id'],'journey_key'=>$journeyKey,'journey_instance_key'=>$instance,'trigger'=>$trigger
        ],(string)$campaign['environment'],null,'automation');
    }
    return $summary;
}

function campaigns_rewards_journey_instance_converted_v121(PDO $pdo,array $delivery): bool
{
    $instance=(string)($delivery['metadata']['journey_instance_key']??'');if($instance==='')return false;
    $q=$pdo->prepare("SELECT metadata_json FROM campaign_deliveries WHERE campaign_id=? AND contact_id=? ORDER BY id DESC LIMIT 250");
    $q->execute([(int)$delivery['campaign_id'],(int)$delivery['contact_id']]);
    foreach($q->fetchAll()?:[] as $row){
        $m=json_decode((string)($row['metadata_json']??''),true);if(!is_array($m))continue;
        if((string)($m['journey_instance_key']??'')===$instance&&!empty($m['attributed_claim_id']))return true;
    }
    return false;
}

function campaigns_rewards_journey_exit_reason_v121(PDO $pdo,array $delivery): ?string
{
    if(!empty($delivery['metadata']['exit_on_conversion'])&&campaigns_rewards_journey_instance_converted_v121($pdo,$delivery))return 'conversion_recorded';
    $issuanceId=max(0,(int)($delivery['reward_issuance_id']??0));
    if($issuanceId>0&&(!empty($delivery['metadata']['stop_on_claim'])||!empty($delivery['metadata']['stop_on_expiration']))){
        $q=$pdo->prepare("SELECT status,remaining_quantity,expires_at FROM reward_issuances WHERE id=? AND campaign_id=? LIMIT 1");
        $q->execute([$issuanceId,(int)$delivery['campaign_id']]);$ri=$q->fetch();
        if(!$ri)return 'reward_unavailable';
        if(!empty($delivery['metadata']['stop_on_claim'])&&((string)$ri['status']==='claimed'||(int)$ri['remaining_quantity']<1))return 'reward_claimed';
        if(!empty($delivery['metadata']['stop_on_expiration'])&&(in_array((string)$ri['status'],['expired','voided'],true)||(!empty($ri['expires_at'])&&strtotime((string)$ri['expires_at'])<=time())))return 'reward_expired';
    }
    return null;
}

function campaigns_rewards_condition_source_v121(PDO $pdo,array $delivery,array $contact,string $field): mixed
{
    $ctx=(array)($delivery['metadata']['context']??[]);
    if($field==='contact.marketing_status')return $contact['merchant_marketing_status']??$contact['marketing_status']??'';
    if($field==='contact.customer_status'||$field==='contact.loyalty_status'){
        $q=$pdo->prepare("SELECT customer_status,loyalty_status FROM crm_merchant_relationships WHERE merchant_id=? AND contact_id=? LIMIT 1");
        $q->execute([(int)$delivery['merchant_id'],(int)$delivery['contact_id']]);$r=$q->fetch()?:[];
        return $field==='contact.customer_status'?($r['customer_status']??''):($r['loyalty_status']??'');
    }
    if($field==='contact.preferred_channel')return $contact['preferred_channel']??'';
    if($field==='context.balance')return $ctx['balance']??0;
    if($field==='context.amount_paid_cents')return $ctx['amount_paid_cents']??0;
    if($field==='context.referrer_contact_id')return $ctx['referrer_contact_id']??0;
    if($field==='reward.status'||$field==='reward.remaining_quantity'){
        $issuanceId=max(0,(int)($delivery['reward_issuance_id']??0));if($issuanceId<1)return '';
        $q=$pdo->prepare("SELECT status,remaining_quantity FROM reward_issuances WHERE id=? LIMIT 1");$q->execute([$issuanceId]);$ri=$q->fetch()?:[];
        return $field==='reward.status'?($ri['status']??''):($ri['remaining_quantity']??0);
    }
    if($field==='delivery.converted')return campaigns_rewards_journey_instance_converted_v121($pdo,$delivery);
    if($field==='campaign.status')return $delivery['campaign_status']??'';
    return '';
}

function campaigns_rewards_condition_compare_v121(mixed $actual,string $operator,string $expected): bool
{
    return match($operator){
        'equals'=>(string)$actual===$expected,
        'not_equals'=>(string)$actual!==$expected,
        'gte'=>is_numeric($actual)&&is_numeric($expected)&&(float)$actual>=(float)$expected,
        'lte'=>is_numeric($actual)&&is_numeric($expected)&&(float)$actual<=(float)$expected,
        'contains'=>str_contains(mb_strtolower((string)$actual),mb_strtolower($expected)),
        'truthy'=>!empty($actual),
        'falsy'=>empty($actual),
        default=>false,
    };
}

function campaigns_rewards_enqueue_next_step_v121(PDO $pdo,array $delivery,string $nextStep): ?array
{
    $nextStep=campaigns_rewards_slug_v100($nextStep,50);if($nextStep==='')return null;
    $journey=(string)($delivery['metadata']['journey_key']??'default');$nodes=campaigns_rewards_journey_nodes_v121($pdo,(int)$delivery['campaign_id'],$journey,'');
    $groups=campaigns_rewards_journey_group_nodes_v121($nodes);$variants=$groups[$journey][$nextStep]??[];
    if(!$variants)return null;
    $instance=(string)($delivery['metadata']['journey_instance_key']??'');
    $node=campaigns_rewards_select_variant_v121($variants,(int)$delivery['campaign_id'],(int)$delivery['contact_id'],$instance,$nextStep);if(!$node)return null;
    $campaign=campaigns_rewards_campaign_platform_v100($pdo,(int)$delivery['campaign_id']);if(!$campaign)return null;
    return campaigns_rewards_enqueue_node_v121($pdo,$campaign,$node,(int)$delivery['contact_id'],$instance,(string)($delivery['metadata']['trigger_event_id']??''),(array)($delivery['metadata']['context']??[]));
}

function campaigns_rewards_complete_orchestration_node_v121(PDO $pdo,array $delivery,string $outcome,array $extra=[]): array
{
    $meta=$delivery['metadata'];$meta['node_outcome']=$outcome;$meta['completed_at']=gmdate('Y-m-d H:i:s');$meta=array_merge($meta,$extra);
    $pdo->prepare("UPDATE campaign_deliveries SET status='delivered',delivered_at=COALESCE(delivered_at,UTC_TIMESTAMP()),external_source_type='journey_orchestrator',metadata_json=? WHERE id=?")
        ->execute([campaigns_rewards_json_v100($meta),(int)$delivery['id']]);
    campaigns_rewards_activity_event_v100($pdo,(int)$delivery['merchant_id'],'campaign.journey_node_completed',[
        'campaign_id'=>(int)$delivery['campaign_id'],'contact_id'=>(int)$delivery['contact_id']
    ],[
        'summary'=>'Campaign journey node completed','campaign_public_id'=>$delivery['campaign_public_id'],'delivery_id'=>(int)$delivery['id'],
        'journey_key'=>$meta['journey_key']??'','step_key'=>$meta['step_key']??'','node_type'=>$meta['node_type']??'','outcome'=>$outcome,
    ],(string)$delivery['environment'],null,'automation');
    return campaigns_rewards_delivery_v120($pdo,(int)$delivery['id'])?:$delivery;
}

function campaigns_rewards_http_v121(string $method,string $url,array $headers=[],string $body='',string $basicUser='',string $basicPass=''): array
{
    if(!function_exists('curl_init'))return ['ok'=>false,'status'=>0,'headers'=>[],'body'=>'','error'=>'curl_unavailable'];
    $responseHeaders=[];
    $ch=curl_init($url);curl_setopt_array($ch,[
        CURLOPT_CUSTOMREQUEST=>$method,CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>20,CURLOPT_CONNECTTIMEOUT=>8,
        CURLOPT_HTTPHEADER=>$headers,CURLOPT_POSTFIELDS=>$body,CURLOPT_HEADERFUNCTION=>static function($ch,$line)use(&$responseHeaders){
            $len=strlen($line);$parts=explode(':',$line,2);if(count($parts)===2)$responseHeaders[strtolower(trim($parts[0]))]=trim($parts[1]);return $len;
        },
    ]);
    if($basicUser!=='')curl_setopt($ch,CURLOPT_USERPWD,$basicUser.':'.$basicPass);
    $raw=curl_exec($ch);$error=$raw===false?curl_error($ch):'';$status=(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE);curl_close($ch);
    return ['ok'=>$raw!==false&&$status>=200&&$status<300,'status'=>$status,'headers'=>$responseHeaders,'body'=>$raw===false?'':(string)$raw,'error'=>$error];
}

function campaigns_rewards_sendgrid_sender_v121(array $ctx): array
{
    $apiKey=trim((string)site_config('campaign_sendgrid_api_key',''));
    $from=trim((string)site_config('campaign_sendgrid_from_email',(string)site_config('campaign_email_from','')));
    $fromName=trim((string)site_config('campaign_sendgrid_from_name',(string)site_config('name','VP3')));
    $to=trim((string)($ctx['contact']['email']??''));
    if($apiKey===''||!filter_var($from,FILTER_VALIDATE_EMAIL))return ['status'=>'provider_unconfigured','reason'=>'sendgrid_configuration_missing','source_type'=>'sendgrid','retryable'=>true];
    if(!filter_var($to,FILTER_VALIDATE_EMAIL))return ['status'=>'failed','reason'=>'invalid_email','source_type'=>'sendgrid','retryable'=>false];
    $base=rtrim((string)site_config('campaign_sendgrid_api_base','https://api.sendgrid.com'),'/');
    $payload=[
        'personalizations'=>[['to'=>[['email'=>$to]],'custom_args'=>['vp3_delivery_id'=>(string)($ctx['delivery_id']??0)]]],
        'from'=>array_filter(['email'=>$from,'name'=>$fromName],static fn($v)=>$v!==''),
        'subject'=>(string)($ctx['subject']??''),
        'content'=>[['type'=>'text/plain','value'=>(string)($ctx['body']??'')]],
    ];
    $res=campaigns_rewards_http_v121('POST',$base.'/v3/mail/send',['Authorization: Bearer '.$apiKey,'Content-Type: application/json'],campaigns_rewards_json_v100($payload));
    if($res['ok'])return ['status'=>'sent','source_type'=>'sendgrid','provider_message_id'=>(string)($res['headers']['x-message-id']??''),'retryable'=>false];
    $retryable=$res['status']===0||$res['status']===429||$res['status']>=500;
    return ['status'=>'failed','reason'=>'sendgrid_http_'.$res['status'].($res['error']!==''?':'.$res['error']:''),'source_type'=>'sendgrid','retryable'=>$retryable];
}

function campaigns_rewards_twilio_sender_v121(array $ctx): array
{
    $sid=trim((string)site_config('campaign_twilio_account_sid',''));$token=trim((string)site_config('campaign_twilio_auth_token',''));
    $from=trim((string)site_config('campaign_twilio_from_number',''));$service=trim((string)site_config('campaign_twilio_messaging_service_sid',''));
    $to=trim((string)($ctx['contact']['phone']??''));
    if($sid===''||$token===''||($from===''&&$service===''))return ['status'=>'provider_unconfigured','reason'=>'twilio_configuration_missing','source_type'=>'twilio','retryable'=>true];
    if($to==='')return ['status'=>'failed','reason'=>'missing_phone','source_type'=>'twilio','retryable'=>false];
    $fields=['To'=>$to,'Body'=>(string)($ctx['body']??'')];if($service!=='')$fields['MessagingServiceSid']=$service;else $fields['From']=$from;
    $baseUrl=rtrim((string)site_config('base_url',''),'/');
    if($baseUrl!=='')$fields['StatusCallback']=$baseUrl.url('/api/campaign-delivery-webhook-v121.php?provider=twilio&delivery_id='.(int)($ctx['delivery_id']??0));
    $url='https://api.twilio.com/2010-04-01/Accounts/'.rawurlencode($sid).'/Messages.json';
    $res=campaigns_rewards_http_v121('POST',$url,['Content-Type: application/x-www-form-urlencoded'],http_build_query($fields,'','&',PHP_QUERY_RFC3986),$sid,$token);
    $data=json_decode((string)$res['body'],true);if(!is_array($data))$data=[];
    if($res['ok'])return ['status'=>'sent','source_type'=>'twilio','provider_message_id'=>(string)($data['sid']??''),'provider_status'=>(string)($data['status']??'queued'),'retryable'=>false];
    $retryable=$res['status']===0||$res['status']===429||$res['status']>=500;
    return ['status'=>'failed','reason'=>'twilio_http_'.$res['status'].':'.campaigns_rewards_text_v100($data['message']??$res['error'],240),'source_type'=>'twilio','retryable'=>$retryable];
}

function campaigns_rewards_send_v121(string $channel,array $ctx): array
{
    $custom=$GLOBALS['vp3_campaign_message_senders_v120'][$channel]??null;
    if(is_callable($custom))return campaigns_rewards_send_v120($channel,$ctx);
    if($channel==='email'){
        $provider=strtolower(trim((string)site_config('campaign_email_provider','php_mail')));
        return $provider==='sendgrid'?campaigns_rewards_sendgrid_sender_v121($ctx):campaigns_rewards_email_sender_v120($ctx);
    }
    if($channel==='sms'){
        $provider=strtolower(trim((string)site_config('campaign_sms_provider','')));
        return $provider==='twilio'?campaigns_rewards_twilio_sender_v121($ctx):['status'=>'provider_unconfigured','reason'=>'sms_provider_required','source_type'=>'sms_adapter','retryable'=>true];
    }
    if($channel==='agent')return campaigns_rewards_agent_sender_v120($ctx);
    return ['status'=>'failed','reason'=>'unsupported_channel','source_type'=>'','retryable'=>false];
}

function campaigns_rewards_retry_delivery_v121(PDO $pdo,array $delivery,array $result): array
{
    $meta=$delivery['metadata'];$attempt=max(0,(int)($meta['attempts']??0))+1;$max=max(1,(int)($meta['retry_max_attempts']??3));
    $retryable=array_key_exists('retryable',$result)?!empty($result['retryable']):true;
    $reason=campaigns_rewards_text_v100($result['reason']??'delivery_failed',500);$meta['attempts']=$attempt;$meta['last_error']=$reason;
    $sourceType=campaigns_rewards_text_v100($result['source_type']??$delivery['external_source_type'],60);
    $sourceId=campaigns_rewards_text_v100($result['provider_message_id']??$delivery['external_source_id'],190);
    if($retryable&&$attempt<$max){
        $base=max(1,(int)($meta['retry_backoff_minutes']??5));$minutes=min(10080,$base*(2**max(0,$attempt-1)));
        $meta['next_attempt_at']=gmdate('Y-m-d H:i:s',time()+$minutes*60);$meta['scheduled_for']=$meta['next_attempt_at'];
        $pdo->prepare("UPDATE campaign_deliveries SET status='retry_wait',external_source_type=?,external_source_id=?,metadata_json=? WHERE id=?")
            ->execute([$sourceType,$sourceId,campaigns_rewards_json_v100($meta),(int)$delivery['id']]);
        $status='retry_wait';$event='campaign.message_retry_scheduled';$summary='Campaign message retry scheduled';
    }else{
        $meta['dead_lettered_at']=gmdate('Y-m-d H:i:s');
        $pdo->prepare("UPDATE campaign_deliveries SET status='dead_letter',external_source_type=?,external_source_id=?,failed_at=COALESCE(failed_at,UTC_TIMESTAMP()),metadata_json=? WHERE id=?")
            ->execute([$sourceType,$sourceId,campaigns_rewards_json_v100($meta),(int)$delivery['id']]);
        $status='dead_letter';$event='campaign.message_dead_lettered';$summary='Campaign message moved to dead letter';
    }
    campaigns_rewards_activity_event_v100($pdo,(int)$delivery['merchant_id'],$event,['campaign_id'=>(int)$delivery['campaign_id'],'contact_id'=>(int)$delivery['contact_id']],[
        'summary'=>$summary,'campaign_public_id'=>$delivery['campaign_public_id'],'delivery_id'=>(int)$delivery['id'],'attempt'=>$attempt,'max_attempts'=>$max,'reason'=>$reason
    ],(string)$delivery['environment'],null,'automation');
    return campaigns_rewards_delivery_v120($pdo,(int)$delivery['id'])?:($delivery+['status'=>$status]);
}

function campaigns_rewards_dead_letter_v121(PDO $pdo,array $delivery,string $reason,array $extra=[]): array
{
    $meta=$delivery['metadata'];$meta['dead_lettered_at']=gmdate('Y-m-d H:i:s');$meta['last_error']=campaigns_rewards_text_v100($reason,500);
    foreach($extra as $k=>$v)$meta[$k]=$v;
    $pdo->prepare("UPDATE campaign_deliveries SET status='dead_letter',failed_at=COALESCE(failed_at,UTC_TIMESTAMP()),metadata_json=? WHERE id=?")
        ->execute([campaigns_rewards_json_v100($meta),(int)$delivery['id']]);
    campaigns_rewards_activity_event_v100($pdo,(int)$delivery['merchant_id'],'campaign.message_dead_lettered',['campaign_id'=>(int)$delivery['campaign_id'],'contact_id'=>(int)$delivery['contact_id']],[
        'summary'=>'Campaign message moved to dead letter','campaign_public_id'=>$delivery['campaign_public_id'],'delivery_id'=>(int)$delivery['id'],'reason'=>$reason
    ],(string)$delivery['environment'],null,'system');
    return campaigns_rewards_delivery_v120($pdo,(int)$delivery['id'])?:$delivery;
}

function campaigns_rewards_retry_dead_letters_v121(PDO $pdo,int $merchantId,int $actorUserId,int $campaignId=0,int $limit=100): int
{
    campaigns_rewards_platform_assert_can_v100($pdo,$merchantId,$actorUserId,'campaigns.publish');$limit=max(1,min(500,$limit));
    $sql="SELECT d.id,d.metadata_json FROM campaign_deliveries d INNER JOIN campaigns c ON c.id=d.campaign_id WHERE c.merchant_id=? AND d.status='dead_letter'";
    $params=[$merchantId];if($campaignId>0){$sql.=" AND d.campaign_id=?";$params[]=$campaignId;}$sql.=" ORDER BY d.id LIMIT {$limit}";
    $q=$pdo->prepare($sql);$q->execute($params);$count=0;
    foreach($q->fetchAll()?:[] as $row){
        $meta=json_decode((string)($row['metadata_json']??''),true);if(!is_array($meta))$meta=[];
        $meta['attempts']=0;$meta['scheduled_for']=gmdate('Y-m-d H:i:s');unset($meta['next_attempt_at'],$meta['dead_lettered_at'],$meta['last_error']);
        $pdo->prepare("UPDATE campaign_deliveries SET status='pending',failed_at=NULL,metadata_json=? WHERE id=?")->execute([campaigns_rewards_json_v100($meta),(int)$row['id']]);$count++;
    }
    return $count;
}

function campaigns_rewards_dispatch_delivery_v121(PDO $pdo,int $deliveryId): array
{
    $delivery=campaigns_rewards_delivery_v120($pdo,$deliveryId)?:throw new RuntimeException('Campaign delivery not found.');
    if(!in_array((string)$delivery['status'],['pending','retry_wait'],true))return ['skipped'=>true,'reason'=>'not_dispatchable','delivery'=>$delivery];
    $scheduled=(string)($delivery['metadata']['scheduled_for']??$delivery['created_at']);
    if($scheduled!==''&&strtotime($scheduled)!==false&&strtotime($scheduled)>time())return ['skipped'=>true,'reason'=>'not_due','delivery'=>$delivery];
    if(($delivery['metadata']['runtime']??'')!=='v1.21')return campaigns_rewards_dispatch_delivery_v120($pdo,$deliveryId);

    $contact=campaigns_rewards_message_contact_v120($pdo,(int)$delivery['merchant_id'],(int)$delivery['contact_id']);
    if(!$contact)return ['skipped'=>false,'delivery'=>campaigns_rewards_mark_delivery_v120($pdo,$deliveryId,'suppressed',['reason'=>'contact_unavailable'])];

    $exit=campaigns_rewards_journey_exit_reason_v121($pdo,$delivery);
    if($exit!==null){
        $meta=$delivery['metadata'];$meta['exit_reason']=$exit;$meta['completed_at']=gmdate('Y-m-d H:i:s');
        $pdo->prepare("UPDATE campaign_deliveries SET status='suppressed',metadata_json=? WHERE id=?")
            ->execute([campaigns_rewards_json_v100($meta),$deliveryId]);
        $d=campaigns_rewards_delivery_v120($pdo,$deliveryId)?:$delivery;
        campaigns_rewards_activity_event_v100($pdo,(int)$delivery['merchant_id'],'campaign.journey_exited',['campaign_id'=>(int)$delivery['campaign_id'],'contact_id'=>(int)$delivery['contact_id']],[
            'summary'=>'Campaign journey exited','campaign_public_id'=>$delivery['campaign_public_id'],'journey_key'=>$delivery['metadata']['journey_key']??'','reason'=>$exit
        ],(string)$delivery['environment'],null,'automation');
        return ['skipped'=>false,'delivery'=>$d,'exit_reason'=>$exit];
    }

    $nodeType=(string)($delivery['metadata']['node_type']??$delivery['template']['node_type']??'message');
    $template=(array)($delivery['template']??[]);
    if($nodeType==='decision'){
        $actual=campaigns_rewards_condition_source_v121($pdo,$delivery,$contact,(string)($template['condition_field']??'contact.marketing_status'));
        $matched=campaigns_rewards_condition_compare_v121($actual,(string)($template['condition_operator']??'equals'),(string)($template['condition_value']??''));
        $next=$matched?(string)($template['true_next_step_key']??''):(string)($template['false_next_step_key']??'');
        $d=campaigns_rewards_complete_orchestration_node_v121($pdo,$delivery,$matched?'true':'false',['condition_actual'=>is_scalar($actual)?$actual:null,'selected_next_step'=>$next]);
        campaigns_rewards_activity_event_v100($pdo,(int)$delivery['merchant_id'],'campaign.journey_branch_selected',['campaign_id'=>(int)$delivery['campaign_id'],'contact_id'=>(int)$delivery['contact_id']],[
            'summary'=>'Campaign journey branch selected','campaign_public_id'=>$delivery['campaign_public_id'],'delivery_id'=>$deliveryId,'matched'=>$matched,'next_step_key'=>$next
        ],(string)$delivery['environment'],null,'automation');
        campaigns_rewards_enqueue_next_step_v121($pdo,$d,$next);return ['skipped'=>false,'delivery'=>$d,'branch'=>$matched];
    }
    if($nodeType==='wait_until'){
        $d=campaigns_rewards_complete_orchestration_node_v121($pdo,$delivery,'wait_complete');campaigns_rewards_enqueue_next_step_v121($pdo,$d,(string)($template['next_step_key']??''));
        return ['skipped'=>false,'delivery'=>$d];
    }
    if($nodeType==='exit'){
        $d=campaigns_rewards_complete_orchestration_node_v121($pdo,$delivery,'explicit_exit');
        campaigns_rewards_activity_event_v100($pdo,(int)$delivery['merchant_id'],'campaign.journey_exited',['campaign_id'=>(int)$delivery['campaign_id'],'contact_id'=>(int)$delivery['contact_id']],[
            'summary'=>'Campaign journey reached exit node','campaign_public_id'=>$delivery['campaign_public_id'],'journey_key'=>$delivery['metadata']['journey_key']??'','reason'=>'explicit_exit'
        ],(string)$delivery['environment'],null,'automation');
        return ['skipped'=>false,'delivery'=>$d];
    }

    $suppress=campaigns_rewards_delivery_suppression_v120($pdo,$delivery,$contact);
    if($suppress!==null)return ['skipped'=>false,'delivery'=>campaigns_rewards_mark_delivery_v120($pdo,$deliveryId,'suppressed',['reason'=>$suppress])];

    if(!empty($delivery['metadata']['respect_quiet_hours'])){
        $quiet=json_decode((string)($contact['quiet_hours_json']??''),true);if(!is_array($quiet))$quiet=[];
        $timezone=campaigns_rewards_contact_timezone_v121($pdo,(int)$delivery['merchant_id'],$contact);
        $allowed=campaigns_rewards_next_allowed_send_v121(time(),$timezone,$quiet);
        if($allowed>time()+30){
            $meta=$delivery['metadata'];$meta['scheduled_for']=gmdate('Y-m-d H:i:s',$allowed);$meta['quiet_hours_deferred']=true;
            $pdo->prepare("UPDATE campaign_deliveries SET status='pending',metadata_json=? WHERE id=?")->execute([campaigns_rewards_json_v100($meta),$deliveryId]);
            return ['skipped'=>true,'reason'=>'quiet_hours','delivery'=>campaigns_rewards_delivery_v120($pdo,$deliveryId)];
        }
    }

    $message=['id'=>(int)$delivery['message_id'],'message_key'=>$delivery['message_key'],'subject'=>$delivery['subject'],'body'=>$delivery['body'],'template'=>$template];
    $ctx=campaigns_rewards_message_context_v120($pdo,$delivery,$contact,$message);
    $subject=campaigns_rewards_render_message_v120((string)$delivery['subject'],$ctx['tokens']);
    $body=campaigns_rewards_render_message_v120((string)$delivery['body'],$ctx['tokens']);
    try{$result=campaigns_rewards_send_v121((string)$delivery['channel'],$ctx+['subject'=>$subject,'body'=>$body,'delivery_id'=>$deliveryId]);}
    catch(Throwable $e){$result=['status'=>'failed','reason'=>$e->getMessage(),'source_type'=>'adapter','retryable'=>true];}
    $status=(string)($result['status']??'failed');
    if(in_array($status,['sent','delivered','viewed'],true)){
        $d=campaigns_rewards_mark_delivery_v120($pdo,$deliveryId,$status,$result);campaigns_rewards_enqueue_next_step_v121($pdo,$d,(string)($template['next_step_key']??''));
        return ['skipped'=>false,'result'=>$result,'delivery'=>$d];
    }
    return ['skipped'=>false,'result'=>$result,'delivery'=>campaigns_rewards_retry_delivery_v121($pdo,$delivery,$result)];
}

function campaigns_rewards_dispatch_due_v121(PDO $pdo,int $merchantId=0,int $limit=200): array
{
    $limit=max(1,min(1000,$limit));$sql="SELECT d.id,d.metadata_json,d.status FROM campaign_deliveries d INNER JOIN campaigns c ON c.id=d.campaign_id
      WHERE d.status IN ('pending','retry_wait')";$params=[];
    if($merchantId>0){$sql.=" AND c.merchant_id=?";$params[]=$merchantId;}$sql.=" ORDER BY d.created_at,d.id LIMIT ".($limit*6);
    $q=$pdo->prepare($sql);$q->execute($params);
    $summary=['checked'=>0,'due'=>0,'sent'=>0,'delivered'=>0,'viewed'=>0,'retry_wait'=>0,'dead_letter'=>0,'suppressed'=>0,'provider_unconfigured'=>0,'failed'=>0,'skipped'=>0];
    foreach($q->fetchAll()?:[] as $row){
        if($summary['due']>=$limit)break;$summary['checked']++;$meta=json_decode((string)($row['metadata_json']??''),true);if(!is_array($meta))$meta=[];
        $scheduled=(string)($meta['scheduled_for']??'');if($scheduled!==''&&strtotime($scheduled)!==false&&strtotime($scheduled)>time()){$summary['skipped']++;continue;}
        $summary['due']++;
        try{$r=campaigns_rewards_dispatch_delivery_v121($pdo,(int)$row['id']);}
        catch(Throwable $e){$summary['failed']++;error_log('Campaign messaging V1.21 dispatch failed: '.$e->getMessage());continue;}
        if(!empty($r['skipped'])){$summary['skipped']++;continue;}
        $status=(string)($r['delivery']['status']??'failed');if(isset($summary[$status]))$summary[$status]++;else $summary['failed']++;
    }
    return $summary;
}

function campaigns_rewards_queue_expiration_reminders_v121(PDO $pdo,int $merchantId=0,int $lookAheadDays=30,int $limit=500): array
{
    $lookAheadDays=max(1,min(365,$lookAheadDays));$limit=max(1,min(2000,$limit));
    $sql="SELECT ri.id reward_issuance_id,ri.campaign_id,ri.recipient_contact_id contact_id,ri.expires_at,ri.campaign_enrollment_id enrollment_id
      FROM reward_issuances ri INNER JOIN campaigns c ON c.id=ri.campaign_id INNER JOIN merchant_accounts m ON m.id=c.merchant_id
      WHERE ri.environment='production' AND ri.status IN ('issued','sent','viewed') AND ri.remaining_quantity>0
        AND ri.expires_at IS NOT NULL AND ri.expires_at>UTC_TIMESTAMP()
        AND ri.expires_at<=DATE_ADD(UTC_TIMESTAMP(),INTERVAL {$lookAheadDays} DAY) AND c.status='active' AND m.status='active'";
    $params=[];if($merchantId>0){$sql.=" AND c.merchant_id=?";$params[]=$merchantId;}$sql.=" ORDER BY ri.expires_at,ri.id LIMIT {$limit}";
    $q=$pdo->prepare($sql);$q->execute($params);$summary=['issuances'=>0,'queued'=>0,'duplicate'=>0,'suppressed'=>0];
    foreach($q->fetchAll()?:[] as $row){
        $summary['issuances']++;$r=campaigns_rewards_journey_enqueue_v121($pdo,(int)$row['campaign_id'],(int)$row['contact_id'],'reward_expiring',[
            'reward_issuance_id'=>(int)$row['reward_issuance_id'],'enrollment_id'=>(int)($row['enrollment_id']??0),'expires_at'=>(string)$row['expires_at']
        ],'reward-expiring:'.(int)$row['reward_issuance_id']);
        foreach(['queued','duplicate','suppressed'] as $k)$summary[$k]+=(int)($r[$k]??0);
    }
    return $summary;
}

function campaigns_rewards_twilio_signature_v121(string $url,array $params,string $authToken): string
{
    ksort($params,SORT_STRING);$data=$url;
    foreach($params as $key=>$value){
        if(is_array($value)){sort($value,SORT_STRING);foreach($value as $v)$data.=$key.(string)$v;}
        else $data.=$key.(string)$value;
    }
    return base64_encode(hash_hmac('sha1',$data,$authToken,true));
}

function campaigns_rewards_verify_twilio_webhook_v121(string $url,array $params,string $signature): bool
{
    $token=(string)site_config('campaign_twilio_auth_token','');if($token===''||$signature==='')return false;
    return hash_equals(campaigns_rewards_twilio_signature_v121($url,$params,$token),$signature);
}

function campaigns_rewards_verify_sendgrid_webhook_v121(string $rawBody,string $timestamp,string $signature): bool
{
    $pem=trim((string)site_config('campaign_sendgrid_webhook_public_key_pem',''));
    if($pem===''||$timestamp===''||$signature===''||!function_exists('openssl_verify'))return false;
    $decoded=base64_decode($signature,true);if($decoded===false)return false;
    return openssl_verify($timestamp.$rawBody,$decoded,$pem,OPENSSL_ALGO_SHA256)===1;
}

function campaigns_rewards_provider_event_v121(PDO $pdo,int $deliveryId,string $provider,string $event,array $payload=[]): array
{
    $delivery=campaigns_rewards_delivery_v120($pdo,$deliveryId)?:throw new RuntimeException('Campaign delivery not found.');
    $providerId=campaigns_rewards_text_v100($payload['provider_message_id']??'',190);
    if($provider==='twilio'&&$providerId!==''&&!empty($delivery['external_source_id'])&&!hash_equals((string)$delivery['external_source_id'],$providerId))
        throw new RuntimeException('Provider message does not match Campaign delivery.');

    $normalized=strtolower($event);$terminalFailure=false;$mapped='';
    if($provider==='twilio'){
        $mapped=match($normalized){'delivered'=>'delivered','read'=>'viewed','sent'=>'sent',default=>''};
        $terminalFailure=in_array($normalized,['failed','undelivered'],true);
    }elseif($provider==='sendgrid'){
        $mapped=match($normalized){'delivered'=>'delivered','open','click'=>'viewed','processed'=>'sent',default=>''};
        $terminalFailure=in_array($normalized,['bounce','dropped'],true);
    }
    if($terminalFailure)$updated=campaigns_rewards_dead_letter_v121($pdo,$delivery,$provider.'_'.$normalized,['provider_event'=>$normalized]);
    elseif($mapped!=='')$updated=campaigns_rewards_mark_delivery_v120($pdo,$deliveryId,$mapped,['source_type'=>$provider,'provider_message_id'=>$providerId,'provider_event'=>$normalized]);
    else $updated=$delivery;

    campaigns_rewards_activity_event_v100($pdo,(int)$delivery['merchant_id'],'campaign.provider_event_received',['campaign_id'=>(int)$delivery['campaign_id'],'contact_id'=>(int)$delivery['contact_id']],[
        'summary'=>'Campaign delivery provider event received','campaign_public_id'=>$delivery['campaign_public_id'],'delivery_id'=>$deliveryId,'provider'=>$provider,'provider_event'=>$normalized
    ],(string)$delivery['environment'],null,'system');
    return $updated;
}

function campaigns_rewards_provider_webhook_v121(PDO $pdo,string $provider,string $rawBody,array $headers,array $params,string $requestUrl): array
{
    $provider=strtolower(trim($provider));
    if($provider==='twilio'){
        $signature=(string)($headers['x-twilio-signature']??'');
        if(!campaigns_rewards_verify_twilio_webhook_v121($requestUrl,$params,$signature))throw new RuntimeException('Invalid Twilio webhook signature.');
        $deliveryId=max(0,(int)($_GET['delivery_id']??0));if($deliveryId<1)throw new RuntimeException('Campaign delivery is required.');
        $event=(string)($params['MessageStatus']??$params['SmsStatus']??'');
        return [campaigns_rewards_provider_event_v121($pdo,$deliveryId,'twilio',$event,['provider_message_id'=>(string)($params['MessageSid']??'')])];
    }
    if($provider==='sendgrid'){
        $timestamp=(string)($headers['x-twilio-email-event-webhook-timestamp']??'');$signature=(string)($headers['x-twilio-email-event-webhook-signature']??'');
        if(!campaigns_rewards_verify_sendgrid_webhook_v121($rawBody,$timestamp,$signature))throw new RuntimeException('Invalid SendGrid webhook signature.');
        $events=json_decode($rawBody,true);if(!is_array($events))throw new RuntimeException('Invalid SendGrid webhook payload.');$out=[];
        foreach($events as $event){
            if(!is_array($event))continue;$custom=(array)($event['custom_args']??$event['unique_args']??[]);
            $deliveryId=max(0,(int)($custom['vp3_delivery_id']??0));if($deliveryId<1)continue;
            $messageId=(string)($event['sg_message_id']??'');if(str_contains($messageId,'.'))$messageId=explode('.',$messageId,2)[0];
            $out[]=campaigns_rewards_provider_event_v121($pdo,$deliveryId,'sendgrid',(string)($event['event']??''),['provider_message_id'=>$messageId]);
        }
        return $out;
    }
    throw new RuntimeException('Unsupported Campaign delivery provider.');
}

function campaigns_rewards_journey_performance_v121(PDO $pdo,int $campaignId,int $actorUserId): array
{
    $base=campaigns_rewards_message_performance_v120($pdo,$campaignId,$actorUserId);
    $q=$pdo->prepare("SELECT status,metadata_json FROM campaign_deliveries WHERE campaign_id=? ORDER BY id");$q->execute([$campaignId]);
    $variants=[];$journeys=[];$dead=0;$retry=0;$nodes=0;
    foreach($q->fetchAll()?:[] as $row){
        $m=json_decode((string)($row['metadata_json']??''),true);if(!is_array($m)||($m['runtime']??'')!=='v1.21')continue;$nodes++;
        $journey=(string)($m['journey_key']??'default');$variant=(string)($m['variant_key']??'default');$status=(string)$row['status'];
        $journeys[$journey][$status]=($journeys[$journey][$status]??0)+1;
        if($variant!=='default')$variants[$journey][$variant][$status]=($variants[$journey][$variant][$status]??0)+1;
        if($status==='dead_letter')$dead++;if($status==='retry_wait')$retry++;
    }
    return $base+['orchestration_nodes'=>$nodes,'retry_wait'=>$retry,'dead_letter'=>$dead,'journeys'=>$journeys,'variants'=>$variants];
}

function campaigns_rewards_run_due_v121(PDO $pdo,int $merchantId=0): array
{
    $automation=function_exists('campaigns_rewards_automation_run_due_v119')?campaigns_rewards_automation_run_due_v119($pdo,$merchantId):[];
    $expiration=campaigns_rewards_queue_expiration_reminders_v121($pdo,$merchantId);
    $delivery=campaigns_rewards_dispatch_due_v121($pdo,$merchantId);
    return ['automation'=>$automation,'expiration_reminders'=>$expiration,'deliveries'=>$delivery];
}
