<?php
declare(strict_types=1);

/**
 * Campaigns & Rewards V1.22 — Journey Intelligence & Optimization.
 *
 * V1.22 derives intelligence from canonical V1.21 journey definitions,
 * campaign_deliveries, Reward Claim attribution and CRM preferences. It adds no
 * analytics shadow ledger, autonomous learner, or Agent activation authority.
 */
const VP3_CAMPAIGNS_REWARDS_V122='vp3-campaigns-rewards-v122-20260923';

function campaigns_rewards_journey_template_catalog_v122(): array
{
    return [
        'signup_welcome'=>[
            'name'=>'Signup welcome',
            'description'=>'Immediate welcome, short wait, then a Reward reminder if the contact has not converted.',
            'trigger'=>'newsletter_signup',
            'nodes'=>[
                ['node_type'=>'message','step_key'=>'welcome','step_order'=>10,'entry_node'=>1,'channel'=>'email','purpose'=>'marketing','subject'=>'Welcome to {{merchant_name}}','body'=>"Hi {{name}},\n\nThanks for signing up for {{merchant_name}}. Your Campaign is {{campaign_name}}.\n\n{{campaign_url}}",'next_step_key'=>'wait-reminder','exit_on_conversion'=>1],
                ['node_type'=>'wait_until','step_key'=>'wait-reminder','step_order'=>20,'wait_mode'=>'delay','delay_minutes'=>1440,'next_step_key'=>'reward-reminder','exit_on_conversion'=>1],
                ['node_type'=>'message','step_key'=>'reward-reminder','step_order'=>30,'channel'=>'email','purpose'=>'marketing','subject'=>'Your reward is waiting','body'=>"Hi {{name}},\n\nYour {{reward_name}} is still available.\n\n{{reward_wallet_url}}",'next_step_key'=>'done','stop_on_claim'=>1,'stop_on_expiration'=>1,'exit_on_conversion'=>1],
                ['node_type'=>'exit','step_key'=>'done','step_order'=>40],
            ],
        ],
        'post_purchase'=>[
            'name'=>'Post-purchase follow-up',
            'description'=>'Transactional thank-you followed by a delayed marketing follow-up.',
            'trigger'=>'purchase_completed',
            'nodes'=>[
                ['node_type'=>'message','step_key'=>'thank-you','step_order'=>10,'entry_node'=>1,'channel'=>'email','purpose'=>'transactional','subject'=>'Thanks for your purchase','body'=>"Thanks, {{name}}. We appreciate your purchase from {{merchant_name}}.",'next_step_key'=>'wait-followup'],
                ['node_type'=>'wait_until','step_key'=>'wait-followup','step_order'=>20,'wait_mode'=>'delay','delay_minutes'=>2880,'next_step_key'=>'followup'],
                ['node_type'=>'message','step_key'=>'followup','step_order'=>30,'channel'=>'email','purpose'=>'marketing','subject'=>'A follow-up from {{merchant_name}}','body'=>"Hi {{name}},\n\nHere is what is next with {{campaign_name}}.\n\n{{campaign_url}}",'next_step_key'=>'done','exit_on_conversion'=>1],
                ['node_type'=>'exit','step_key'=>'done','step_order'=>40],
            ],
        ],
        'win_back'=>[
            'name'=>'Win-back',
            'description'=>'A two-touch re-engagement journey with conversion exit and frequency protection.',
            'trigger'=>'crm_lapse',
            'nodes'=>[
                ['node_type'=>'message','step_key'=>'winback-a','step_order'=>10,'entry_node'=>1,'channel'=>'email','purpose'=>'marketing','subject'=>'We would love to see you again','body'=>"Hi {{name}},\n\nThere is something waiting for you at {{merchant_name}}.\n\n{{campaign_url}}",'next_step_key'=>'wait-second','exit_on_conversion'=>1,'frequency_cap_7d'=>3],
                ['node_type'=>'wait_until','step_key'=>'wait-second','step_order'=>20,'wait_mode'=>'delay','delay_minutes'=>4320,'next_step_key'=>'winback-b','exit_on_conversion'=>1],
                ['node_type'=>'message','step_key'=>'winback-b','step_order'=>30,'channel'=>'email','purpose'=>'marketing','subject'=>'One more reminder from {{merchant_name}}','body'=>"Hi {{name}},\n\nA quick reminder about {{campaign_name}}.\n\n{{campaign_url}}",'next_step_key'=>'done','exit_on_conversion'=>1,'frequency_cap_7d'=>3],
                ['node_type'=>'exit','step_key'=>'done','step_order'=>40],
            ],
        ],
        'reward_expiration'=>[
            'name'=>'Reward expiration',
            'description'=>'Reminder before a Reward expires, with automatic stop after claim/expiration.',
            'trigger'=>'reward_expiring',
            'nodes'=>[
                ['node_type'=>'message','step_key'=>'expiration-reminder','step_order'=>10,'entry_node'=>1,'channel'=>'email','purpose'=>'transactional','subject'=>'Your reward expires soon','body'=>"Hi {{name}},\n\nYour {{reward_name}} expires {{reward_expiration}}.\n\n{{reward_wallet_url}}",'expiration_lead_days'=>3,'stop_on_claim'=>1,'stop_on_expiration'=>1,'exit_on_conversion'=>1,'next_step_key'=>'done'],
                ['node_type'=>'exit','step_key'=>'done','step_order'=>20],
            ],
        ],
        'referral_followup'=>[
            'name'=>'Referral follow-up',
            'description'=>'Thank the referrer and remind them after a short delay.',
            'trigger'=>'referral_qualified',
            'nodes'=>[
                ['node_type'=>'message','step_key'=>'referral-thanks','step_order'=>10,'entry_node'=>1,'channel'=>'email','purpose'=>'transactional','subject'=>'Thanks for the referral','body'=>"Thanks, {{name}}. Your referral activity for {{campaign_name}} has been recorded.",'next_step_key'=>'wait-referral'],
                ['node_type'=>'wait_until','step_key'=>'wait-referral','step_order'=>20,'wait_mode'=>'delay','delay_minutes'=>1440,'next_step_key'=>'referral-reminder'],
                ['node_type'=>'message','step_key'=>'referral-reminder','step_order'=>30,'channel'=>'email','purpose'=>'marketing','subject'=>'Keep sharing {{campaign_name}}','body'=>"Hi {{name}},\n\nYou can keep sharing {{campaign_name}} here:\n{{campaign_url}}",'next_step_key'=>'done','frequency_cap_7d'=>3],
                ['node_type'=>'exit','step_key'=>'done','step_order'=>40],
            ],
        ],
    ];
}

function campaigns_rewards_v122_bool(array $input,string $key,bool $default=false): bool
{
    return array_key_exists($key,$input)?!empty($input[$key]):$default;
}

function campaigns_rewards_journey_optimization_settings_v122(array $input,array $previous=[]): array
{
    return [
        'optimize_send_time'=>campaigns_rewards_v122_bool($input,'optimize_send_time',!empty($previous['optimize_send_time'])),
        'optimization_min_samples'=>max(5,min(500,(int)($input['optimization_min_samples']??$previous['optimization_min_samples']??20))),
        'frequency_cap_24h'=>max(0,min(100,(int)($input['frequency_cap_24h']??$previous['frequency_cap_24h']??0))),
        'frequency_cap_7d'=>max(0,min(500,(int)($input['frequency_cap_7d']??$previous['frequency_cap_7d']??0))),
        'fatigue_window_days'=>max(1,min(90,(int)($input['fatigue_window_days']??$previous['fatigue_window_days']??7))),
        'fatigue_max_messages'=>max(0,min(500,(int)($input['fatigue_max_messages']??$previous['fatigue_max_messages']??0))),
    ];
}

function campaigns_rewards_journey_node_save_v122(PDO $pdo,int $merchantId,int $campaignId,int $actorUserId,array $input,int $messageId=0): array
{
    if($messageId<1){
        $journey=campaigns_rewards_slug_v100((string)($input['journey_key']??'default'),50)?:'default';
        $step=campaigns_rewards_slug_v100((string)($input['step_key']??'step'),50)?:'step';
        $variant=campaigns_rewards_slug_v100((string)($input['variant_key']??'default'),30)?:'default';
        $messageKey=campaigns_rewards_message_key_v121($journey,$step,$variant);
        $q=$pdo->prepare("SELECT id FROM campaign_messages WHERE campaign_id=? AND message_key=? ORDER BY version_no DESC LIMIT 1");
        $q->execute([$campaignId,$messageKey]);
        if($q->fetchColumn())throw new RuntimeException('That journey step and variant already exists. Edit the existing node instead.');
    }
    $previous=[];
    if($messageId>0){$old=campaigns_rewards_message_v120($pdo,$messageId);$previous=(array)($old['template']??[]);}
    $row=campaigns_rewards_journey_node_save_v121($pdo,$merchantId,$campaignId,$actorUserId,$input,$messageId);
    $template=(array)($row['template']??[]);
    $template=array_merge($template,campaigns_rewards_journey_optimization_settings_v122($input,$previous));
    $pdo->prepare("UPDATE campaign_messages SET template_json=?,updated_at=UTC_TIMESTAMP() WHERE id=?")
        ->execute([campaigns_rewards_json_v100($template),(int)$row['id']]);
    campaigns_rewards_activity_event_v100($pdo,$merchantId,'campaign.journey_optimization_updated',['campaign_id'=>$campaignId],[
        'summary'=>'Campaign journey optimization settings saved','campaign_public_id'=>$row['campaign_public_id'],
        'message_id'=>(int)$row['id'],'journey_key'=>$template['journey_key']??'','step_key'=>$template['step_key']??'',
        'optimize_send_time'=>!empty($template['optimize_send_time']),
        'frequency_cap_24h'=>(int)($template['frequency_cap_24h']??0),'frequency_cap_7d'=>(int)($template['frequency_cap_7d']??0),
    ],(string)$row['environment'],$actorUserId);
    return campaigns_rewards_message_v120($pdo,(int)$row['id'])?:$row;
}

function campaigns_rewards_unique_template_journey_key_v122(PDO $pdo,int $campaignId,string $base): string
{
    $base=campaigns_rewards_slug_v100($base,42)?:'journey';$candidate=$base;$n=1;
    while(true){
        $prefix=$candidate.'--%';$q=$pdo->prepare("SELECT 1 FROM campaign_messages WHERE campaign_id=? AND message_key LIKE ? LIMIT 1");$q->execute([$campaignId,$prefix]);
        if(!$q->fetchColumn())return $candidate;$n++;$candidate=substr($base,0,42-strlen((string)$n)-1).'-'.$n;
    }
}

function campaigns_rewards_apply_journey_template_v122(PDO $pdo,int $merchantId,int $campaignId,int $actorUserId,string $templateKey,string $journeyKey=''): array
{
    campaigns_rewards_platform_assert_can_v100($pdo,$merchantId,$actorUserId,'campaigns.edit');
    $catalog=campaigns_rewards_journey_template_catalog_v122();$definition=$catalog[$templateKey]??null;
    if(!$definition)throw new RuntimeException('Choose a valid journey template.');
    $campaign=campaigns_rewards_campaign_platform_v100($pdo,$campaignId)?:throw new RuntimeException('Campaign not found.');
    if((int)$campaign['merchant_id']!==$merchantId)throw new RuntimeException('Campaign not found.');
    $base=$journeyKey!==''?$journeyKey:$templateKey;$journey=campaigns_rewards_unique_template_journey_key_v122($pdo,$campaignId,$base);
    $created=[];
    foreach((array)$definition['nodes'] as $node){
        $input=$node+[
            'journey_key'=>$journey,'trigger_event'=>(string)$definition['trigger'],'status'=>'draft',
            'variant_key'=>'default','variant_weight'=>100,'respect_quiet_hours'=>1,
            'retry_max_attempts'=>3,'retry_backoff_minutes'=>5,
        ];
        $created[]=campaigns_rewards_journey_node_save_v122($pdo,$merchantId,$campaignId,$actorUserId,$input,0);
    }
    campaigns_rewards_activity_event_v100($pdo,$merchantId,'campaign.journey_template_applied',['campaign_id'=>$campaignId],[
        'summary'=>'Campaign journey template added as draft nodes','campaign_public_id'=>$campaign['public_id'],
        'template_key'=>$templateKey,'journey_key'=>$journey,'nodes_created'=>count($created),
    ],(string)$campaign['environment'],$actorUserId);
    return ['template'=>$templateKey,'journey_key'=>$journey,'nodes'=>$created];
}

function campaigns_rewards_analytics_rows_v122(PDO $pdo,int $campaignId): array
{
    $q=$pdo->prepare("SELECT d.*,cm.template_json
      FROM campaign_deliveries d LEFT JOIN campaign_messages cm ON cm.id=d.message_id
      WHERE d.campaign_id=? ORDER BY d.id");
    $q->execute([$campaignId]);return $q->fetchAll()?:[];
}

function campaigns_rewards_journey_path_analytics_v122(PDO $pdo,int $campaignId,int $actorUserId): array
{
    $campaign=campaigns_rewards_campaign_platform_v100($pdo,$campaignId)?:throw new RuntimeException('Campaign not found.');
    campaigns_rewards_platform_assert_can_v100($pdo,(int)$campaign['merchant_id'],$actorUserId,'analytics.view');
    $steps=[];$variants=[];$instances=[];$transitions=[];
    foreach(campaigns_rewards_analytics_rows_v122($pdo,$campaignId) as $row){
        $m=json_decode((string)($row['metadata_json']??''),true);if(!is_array($m)||($m['runtime']??'')!=='v1.21')continue;
        $t=json_decode((string)($row['template_json']??''),true);if(!is_array($t))$t=[];
        $journey=(string)($m['journey_key']??'default');$step=(string)($m['step_key']??'step');$variant=(string)($m['variant_key']??'default');
        $instance=(string)($m['journey_instance_key']??'delivery:'.$row['id']);$status=(string)$row['status'];$key=$journey.'|'.$step;
        if(!isset($steps[$key]))$steps[$key]=['journey_key'=>$journey,'step_key'=>$step,'node_type'=>(string)($m['node_type']??$t['node_type']??'message'),'entered'=>0,'sent'=>0,'delivered'=>0,'viewed'=>0,'converted'=>0,'suppressed'=>0,'failed'=>0,'retry_wait'=>0,'dead_letter'=>0,'continued'=>0,'dropoff'=>0,'dropoff_rate'=>0.0];
        $steps[$key]['entered']++;
        if(in_array($status,['sent','delivered','viewed'],true))$steps[$key]['sent']++;
        if(in_array($status,['delivered','viewed'],true))$steps[$key]['delivered']++;
        if($status==='viewed')$steps[$key]['viewed']++;
        if(!empty($m['attributed_claim_id']))$steps[$key]['converted']++;
        if(isset($steps[$key][$status]))$steps[$key][$status]++;
        $instances[$journey][$step][$instance]=true;

        $vkey=$key.'|'.$variant;
        if(!isset($variants[$vkey]))$variants[$vkey]=['journey_key'=>$journey,'step_key'=>$step,'variant_key'=>$variant,'entered'=>0,'sent'=>0,'delivered'=>0,'viewed'=>0,'converted'=>0,'conversion_rate'=>0.0,'view_rate'=>0.0];
        $variants[$vkey]['entered']++;
        if(in_array($status,['sent','delivered','viewed'],true))$variants[$vkey]['sent']++;
        if(in_array($status,['delivered','viewed'],true))$variants[$vkey]['delivered']++;
        if($status==='viewed')$variants[$vkey]['viewed']++;
        if(!empty($m['attributed_claim_id']))$variants[$vkey]['converted']++;

        $next=(string)($m['selected_next_step']??$t['next_step_key']??'');
        if($next!=='')$transitions[$journey][$step][$next]=true;
    }
    foreach($steps as $key=>&$step){
        $journey=$step['journey_key'];$from=$step['step_key'];$source=array_keys($instances[$journey][$from]??[]);$continued=[];
        foreach(array_keys($transitions[$journey][$from]??[]) as $next){
            foreach(array_keys($instances[$journey][$next]??[]) as $instance)$continued[$instance]=true;
        }
        if($transitions[$journey][$from]??false){
            $step['continued']=count(array_intersect($source,array_keys($continued)));
            $step['dropoff']=max(0,$step['entered']-$step['continued']);
            $step['dropoff_rate']=$step['entered']>0?round($step['dropoff']*100/$step['entered'],1):0.0;
        }
    }
    unset($step);
    foreach($variants as &$v){
        $v['conversion_rate']=$v['sent']>0?round($v['converted']*100/$v['sent'],1):0.0;
        $v['view_rate']=$v['sent']>0?round($v['viewed']*100/$v['sent'],1):0.0;
    }
    unset($v);
    return ['campaign_id'=>$campaignId,'steps'=>array_values($steps),'variants'=>array_values($variants),'transitions'=>$transitions];
}

function campaigns_rewards_ab_comparison_v122(PDO $pdo,int $campaignId,int $actorUserId): array
{
    $analytics=campaigns_rewards_journey_path_analytics_v122($pdo,$campaignId,$actorUserId);$groups=[];
    foreach($analytics['variants'] as $v)$groups[$v['journey_key'].'|'.$v['step_key']][]=$v;
    $out=[];
    foreach($groups as $variants){
        if(count($variants)<2)continue;$total=array_sum(array_column($variants,'sent'));
        $out[]=['journey_key'=>$variants[0]['journey_key'],'step_key'=>$variants[0]['step_key'],'sent'=>$total,'variants'=>$variants];
    }
    return $out;
}

function campaigns_rewards_send_time_signal_v122(PDO $pdo,int $campaignId,string $channel='email',int $minSamples=20): array
{
    $campaign=campaigns_rewards_campaign_platform_v100($pdo,$campaignId);if(!$campaign)return ['eligible'=>false,'samples'=>0,'reason'=>'campaign_unavailable'];
    $tzq=$pdo->prepare("SELECT timezone FROM merchant_accounts WHERE id=? LIMIT 1");$tzq->execute([(int)$campaign['merchant_id']]);
    $timezone=campaigns_rewards_valid_timezone_v121((string)($tzq->fetchColumn()?:'UTC'));
    $q=$pdo->prepare("SELECT sent_at,delivered_at,viewed_at,metadata_json,status FROM campaign_deliveries
      WHERE campaign_id=? AND channel=? AND status IN ('sent','delivered','viewed') AND sent_at IS NOT NULL ORDER BY id DESC LIMIT 1000");
    $q->execute([$campaignId,$channel]);$hours=array_fill(0,24,['sent'=>0,'viewed'=>0,'converted'=>0,'score'=>0.0]);
    $samples=0;
    foreach($q->fetchAll()?:[] as $row){
        $ts=strtotime((string)$row['sent_at']);if($ts===false)continue;$samples++;
        $hour=(int)(new DateTimeImmutable('@'.$ts))->setTimezone(new DateTimeZone($timezone))->format('G');
        $m=json_decode((string)($row['metadata_json']??''),true);if(!is_array($m))$m=[];
        $hours[$hour]['sent']++;if(!empty($row['viewed_at'])||(string)$row['status']==='viewed')$hours[$hour]['viewed']++;
        if(!empty($m['attributed_claim_id']))$hours[$hour]['converted']++;
    }
    foreach($hours as $hour=>&$h)$h['score']=$h['sent']>0?round((($h['viewed']+($h['converted']*3))/$h['sent']),4):0.0;
    unset($h);
    $eligible=$samples>=max(5,$minSamples);$bestHour=null;$bestScore=-1.0;
    if($eligible)foreach($hours as $hour=>$h)if($h['sent']>=3&&$h['score']>$bestScore){$bestScore=$h['score'];$bestHour=(int)$hour;}
    if($bestHour===null)$eligible=false;
    return ['eligible'=>$eligible,'samples'=>$samples,'timezone'=>$timezone,'recommended_hour'=>$bestHour,'score'=>$bestScore,'hours'=>$hours,'reason'=>$eligible?'verified_outcomes':'insufficient_verified_outcomes'];
}

function campaigns_rewards_frequency_gate_v122(PDO $pdo,array $delivery,array $template): array
{
    $cap24=max(0,(int)($template['frequency_cap_24h']??0));$cap7=max(0,(int)($template['frequency_cap_7d']??0));
    $fatigueDays=max(1,(int)($template['fatigue_window_days']??7));$fatigueMax=max(0,(int)($template['fatigue_max_messages']??0));
    if($cap24===0&&$cap7===0&&$fatigueMax===0)return ['allowed'=>true,'reason'=>'no_caps'];
    $merchant=(int)$delivery['merchant_id'];$contact=(int)$delivery['contact_id'];$channel=(string)$delivery['channel'];
    $look=max(7,min(90,$fatigueDays));
    $q=$pdo->prepare("SELECT d.sent_at FROM campaign_deliveries d INNER JOIN campaigns c ON c.id=d.campaign_id
      WHERE c.merchant_id=? AND d.contact_id=? AND d.channel=? AND d.status IN ('sent','delivered','viewed')
        AND d.sent_at>=DATE_SUB(UTC_TIMESTAMP(),INTERVAL {$look} DAY) ORDER BY d.sent_at");
    $q->execute([$merchant,$contact,$channel]);$times=array_values(array_filter(array_map(static fn($r)=>strtotime((string)$r['sent_at'])?:null,$q->fetchAll()?:[])));
    $now=time();$last24=array_values(array_filter($times,static fn($ts)=>$ts>=$now-86400));$last7=array_values(array_filter($times,static fn($ts)=>$ts>=$now-604800));$fatigue=array_values(array_filter($times,static fn($ts)=>$ts>=$now-($fatigueDays*86400)));
    $defer=0;$reasons=[];
    if($cap24>0&&count($last24)>=$cap24){$defer=max($defer,min($last24)+86400);$reasons[]='frequency_cap_24h';}
    if($cap7>0&&count($last7)>=$cap7){$defer=max($defer,min($last7)+604800);$reasons[]='frequency_cap_7d';}
    if($fatigueMax>0&&count($fatigue)>=$fatigueMax){$defer=max($defer,min($fatigue)+($fatigueDays*86400));$reasons[]='fatigue_window';}
    return ['allowed'=>$defer<=0,'reason'=>$reasons?implode('+',$reasons):'within_caps','next_allowed_at'=>$defer>0?gmdate('Y-m-d H:i:s',$defer):null,'counts'=>['24h'=>count($last24),'7d'=>count($last7),'fatigue'=>count($fatigue)]];
}

function campaigns_rewards_apply_send_time_optimization_v122(PDO $pdo,array $delivery,array $contact,array $template): ?array
{
    if(empty($template['optimize_send_time'])||!empty($delivery['metadata']['send_time_optimization_applied']))return null;
    $min=max(5,(int)($template['optimization_min_samples']??20));$signal=campaigns_rewards_send_time_signal_v122($pdo,(int)$delivery['campaign_id'],(string)$delivery['channel'],$min);
    if(empty($signal['eligible'])||$signal['recommended_hour']===null)return null;
    $timezone=campaigns_rewards_contact_timezone_v121($pdo,(int)$delivery['merchant_id'],$contact);
    $clock=str_pad((string)$signal['recommended_hour'],2,'0',STR_PAD_LEFT).':00';
    $target=campaigns_rewards_local_clock_utc_v121(time(),$timezone,$clock,0);
    $meta=$delivery['metadata'];$meta['send_time_optimization_applied']=true;$meta['send_time_recommended_hour']=$signal['recommended_hour'];
    $meta['send_time_samples']=$signal['samples'];$meta['send_time_signal_score']=$signal['score'];
    if($target>time()+300)$meta['scheduled_for']=gmdate('Y-m-d H:i:s',$target);
    $pdo->prepare("UPDATE campaign_deliveries SET metadata_json=? WHERE id=?")->execute([campaigns_rewards_json_v100($meta),(int)$delivery['id']]);
    campaigns_rewards_activity_event_v100($pdo,(int)$delivery['merchant_id'],'campaign.journey_send_time_optimized',['campaign_id'=>(int)$delivery['campaign_id'],'contact_id'=>(int)$delivery['contact_id']],[
        'summary'=>'Campaign journey send time optimized from verified outcomes','campaign_public_id'=>$delivery['campaign_public_id'],
        'delivery_id'=>(int)$delivery['id'],'recommended_hour'=>$signal['recommended_hour'],'samples'=>$signal['samples'],'timezone'=>$timezone,
    ],(string)$delivery['environment'],null,'automation');
    return ['deferred'=>$target>time()+300,'scheduled_for'=>$meta['scheduled_for']??null,'signal'=>$signal];
}

function campaigns_rewards_dispatch_delivery_v122(PDO $pdo,int $deliveryId): array
{
    $delivery=campaigns_rewards_delivery_v120($pdo,$deliveryId)?:throw new RuntimeException('Campaign delivery not found.');
    if(($delivery['metadata']['runtime']??'')!=='v1.21')return campaigns_rewards_dispatch_delivery_v121($pdo,$deliveryId);
    $nodeType=(string)($delivery['metadata']['node_type']??$delivery['template']['node_type']??'message');
    if($nodeType!=='message')return campaigns_rewards_dispatch_delivery_v121($pdo,$deliveryId);
    if(!in_array((string)$delivery['status'],['pending','retry_wait'],true))return ['skipped'=>true,'reason'=>'not_dispatchable','delivery'=>$delivery];
    $scheduled=(string)($delivery['metadata']['scheduled_for']??$delivery['created_at']);
    if($scheduled!==''&&strtotime($scheduled)!==false&&strtotime($scheduled)>time())return ['skipped'=>true,'reason'=>'not_due','delivery'=>$delivery];

    $contact=campaigns_rewards_message_contact_v120($pdo,(int)$delivery['merchant_id'],(int)$delivery['contact_id']);
    if(!$contact)return campaigns_rewards_dispatch_delivery_v121($pdo,$deliveryId);
    $template=(array)($delivery['template']??[]);

    $gate=campaigns_rewards_frequency_gate_v122($pdo,$delivery,$template);
    if(empty($gate['allowed'])){
        $meta=$delivery['metadata'];$meta['scheduled_for']=(string)$gate['next_allowed_at'];$meta['frequency_deferred']=true;$meta['frequency_reason']=$gate['reason'];$meta['frequency_counts']=$gate['counts'];
        $pdo->prepare("UPDATE campaign_deliveries SET status='pending',metadata_json=? WHERE id=?")->execute([campaigns_rewards_json_v100($meta),$deliveryId]);
        campaigns_rewards_activity_event_v100($pdo,(int)$delivery['merchant_id'],'campaign.journey_frequency_deferred',['campaign_id'=>(int)$delivery['campaign_id'],'contact_id'=>(int)$delivery['contact_id']],[
            'summary'=>'Campaign journey message deferred by frequency or fatigue control','campaign_public_id'=>$delivery['campaign_public_id'],
            'delivery_id'=>$deliveryId,'reason'=>$gate['reason'],'next_allowed_at'=>$gate['next_allowed_at'],
        ],(string)$delivery['environment'],null,'automation');
        return ['skipped'=>true,'reason'=>'frequency_control','delivery'=>campaigns_rewards_delivery_v120($pdo,$deliveryId)];
    }

    $optimized=campaigns_rewards_apply_send_time_optimization_v122($pdo,$delivery,$contact,$template);
    if($optimized&&!empty($optimized['deferred']))return ['skipped'=>true,'reason'=>'send_time_optimization','delivery'=>campaigns_rewards_delivery_v120($pdo,$deliveryId)];
    return campaigns_rewards_dispatch_delivery_v121($pdo,$deliveryId);
}

function campaigns_rewards_dispatch_due_v122(PDO $pdo,int $merchantId=0,int $limit=200): array
{
    $limit=max(1,min(1000,$limit));$sql="SELECT d.id,d.metadata_json FROM campaign_deliveries d INNER JOIN campaigns c ON c.id=d.campaign_id
      WHERE d.status IN ('pending','retry_wait')";$params=[];
    if($merchantId>0){$sql.=" AND c.merchant_id=?";$params[]=$merchantId;}$sql.=" ORDER BY d.created_at,d.id LIMIT ".($limit*8);
    $q=$pdo->prepare($sql);$q->execute($params);
    $summary=['checked'=>0,'due'=>0,'sent'=>0,'delivered'=>0,'viewed'=>0,'retry_wait'=>0,'dead_letter'=>0,'suppressed'=>0,'failed'=>0,'frequency_deferred'=>0,'optimized_deferred'=>0,'skipped'=>0];
    foreach($q->fetchAll()?:[] as $row){
        if($summary['due']>=$limit)break;$summary['checked']++;$meta=json_decode((string)($row['metadata_json']??''),true);if(!is_array($meta))$meta=[];
        $scheduled=(string)($meta['scheduled_for']??'');if($scheduled!==''&&strtotime($scheduled)!==false&&strtotime($scheduled)>time()){$summary['skipped']++;continue;}
        $summary['due']++;
        try{$r=campaigns_rewards_dispatch_delivery_v122($pdo,(int)$row['id']);}
        catch(Throwable $e){$summary['failed']++;error_log('Campaign messaging V1.22 dispatch failed: '.$e->getMessage());continue;}
        if(!empty($r['skipped'])){
            if(($r['reason']??'')==='frequency_control')$summary['frequency_deferred']++;
            elseif(($r['reason']??'')==='send_time_optimization')$summary['optimized_deferred']++;
            else $summary['skipped']++;
            continue;
        }
        $status=(string)($r['delivery']['status']??'failed');if(isset($summary[$status]))$summary[$status]++;else $summary['failed']++;
    }
    return $summary;
}

function campaigns_rewards_simulation_nodes_v122(PDO $pdo,int $campaignId,string $trigger): array
{
    $q=$pdo->prepare("SELECT cm.* FROM campaign_messages cm INNER JOIN (
        SELECT campaign_id,message_key,MAX(version_no) latest_version FROM campaign_messages WHERE campaign_id=? GROUP BY campaign_id,message_key
      ) latest ON latest.campaign_id=cm.campaign_id AND latest.message_key=cm.message_key AND latest.latest_version=cm.version_no
      WHERE cm.campaign_id=? AND cm.status IN ('draft','active','paused') ORDER BY cm.id");
    $q->execute([$campaignId,$campaignId]);$out=[];
    foreach($q->fetchAll()?:[] as $row){
        $row['template']=json_decode((string)($row['template_json']??''),true)?:[];$t=$row['template'];
        if(($t['kind']??'')!=='journey_node'||(string)($t['trigger_event']??'manual')!==$trigger)continue;$out[]=$row;
    }
    return $out;
}

function campaigns_rewards_simulate_journey_v122(PDO $pdo,int $campaignId,int $contactId,string $trigger,array $context=[],int $actorUserId=0): array
{
    $campaign=campaigns_rewards_campaign_platform_v100($pdo,$campaignId)?:throw new RuntimeException('Campaign not found.');
    campaigns_rewards_platform_assert_can_v100($pdo,(int)$campaign['merchant_id'],$actorUserId,'campaigns.view');
    if(!isset(campaigns_rewards_journey_triggers_v120()[$trigger]))throw new RuntimeException('Choose a valid journey trigger.');
    $contact=campaigns_rewards_message_contact_v120($pdo,(int)$campaign['merchant_id'],$contactId)?:throw new RuntimeException('Choose a CRM contact available to this Merchant.');
    $nodes=campaigns_rewards_simulation_nodes_v122($pdo,$campaignId,$trigger);$groups=campaigns_rewards_journey_group_nodes_v121($nodes);
    $results=[];$instance='simulation:'.hash('sha256',$campaignId.'|'.$contactId.'|'.$trigger);
    foreach($groups as $journey=>$steps){
        $entry='';$min=PHP_INT_MAX;
        foreach($steps as $step=>$variants){foreach($variants as $n)if(!empty($n['template']['entry_node'])){$entry=$step;break 2;}$o=(int)($variants[0]['template']['step_order']??999);if($o<$min){$min=$o;$entry=$step;}}
        if($entry==='')continue;$current=$entry;$visited=[];$path=[];
        for($i=0;$i<50&&$current!=='';$i++){
            if(isset($visited[$current])){$path[]=['step_key'=>$current,'outcome'=>'loop_detected'];break;}$visited[$current]=true;
            $variants=$steps[$current]??[];if(!$variants){$path[]=['step_key'=>$current,'outcome'=>'missing_target'];break;}
            $node=campaigns_rewards_select_variant_v121($variants,$campaignId,$contactId,$instance.'|'.$journey,$current);if(!$node)break;
            $t=(array)$node['template'];$type=(string)($t['node_type']??'message');$record=['step_key'=>$current,'node_type'=>$type,'variant_key'=>(string)($t['variant_key']??'default'),'status'=>(string)$node['status']];
            if($type==='decision'){
                $fake=['metadata'=>['context'=>campaigns_rewards_journey_context_v121($context),'journey_instance_key'=>$instance],'merchant_id'=>(int)$campaign['merchant_id'],'contact_id'=>$contactId,'campaign_id'=>$campaignId,'campaign_status'=>$campaign['status'],'reward_issuance_id'=>max(0,(int)($context['reward_issuance_id']??0))];
                $actual=campaigns_rewards_condition_source_v121($pdo,$fake,$contact,(string)($t['condition_field']??'contact.marketing_status'));
                $match=campaigns_rewards_condition_compare_v121($actual,(string)($t['condition_operator']??'equals'),(string)($t['condition_value']??''));
                $record['condition_result']=$match;$record['condition_actual']=is_scalar($actual)?$actual:null;$current=$match?(string)($t['true_next_step_key']??''):(string)($t['false_next_step_key']??'');
            }elseif($type==='exit'){$record['outcome']='exit';$current='';}
            else{
                if($type==='message'){
                    $consent=campaigns_rewards_message_consent_v120($contact,(string)$node['channel'],(string)($t['purpose']??'marketing'));$record['consent']=$consent;
                    $fakeDelivery=['merchant_id'=>(int)$campaign['merchant_id'],'contact_id'=>$contactId,'channel'=>$node['channel']];
                    $record['frequency_gate']=campaigns_rewards_frequency_gate_v122($pdo,$fakeDelivery,$t);
                }
                $record['scheduled_for']=gmdate('c',campaigns_rewards_node_schedule_v121($pdo,$campaign,$node,$contact,$context));
                $current=(string)($t['next_step_key']??'');
            }
            $path[]=$record;
        }
        $results[]=['journey_key'=>$journey,'path'=>$path];
    }
    campaigns_rewards_activity_event_v100($pdo,(int)$campaign['merchant_id'],'campaign.journey_simulated',['campaign_id'=>$campaignId,'contact_id'=>$contactId],[
        'summary'=>'Campaign journey simulated without delivery','campaign_public_id'=>$campaign['public_id'],'trigger'=>$trigger,'journeys'=>count($results),
    ],(string)$campaign['environment'],$actorUserId);
    return ['dry_run'=>true,'campaign_id'=>$campaignId,'contact_id'=>$contactId,'trigger'=>$trigger,'journeys'=>$results];
}

function campaigns_rewards_create_optimization_recommendation_v122(PDO $pdo,array $campaign,string $type,string $summary,array $evidence,array $impact): bool
{
    $merchantId=(int)$campaign['merchant_id'];$campaignId=(int)$campaign['id'];$owner=(int)$campaign['merchant_owner_user_id'];if($owner<1)return false;
    $summary=campaigns_rewards_text_v100($summary,1000);$type=substr('journey.'.campaigns_rewards_slug_v100($type,70),0,80);
    $q=$pdo->prepare("SELECT id,status,created_at FROM campaign_agent_recommendations
      WHERE merchant_id=? AND campaign_id=? AND recommendation_type=? ORDER BY id DESC LIMIT 1");
    $q->execute([$merchantId,$campaignId,$type]);$latest=$q->fetch();
    if($latest&&((string)$latest['status']==='proposed'||strtotime((string)$latest['created_at'])>=time()-604800))return false;
    $pdo->prepare("INSERT INTO campaign_agent_recommendations
      (public_id,merchant_id,campaign_id,recommendation_type,summary,status,evidence_refs_json,impact_preview_json,created_for_user_id)
      VALUES (?,?,?,?,?,'proposed',?,?,?)")->execute([
        campaigns_rewards_uuid_v100(),$merchantId,$campaignId,$type,$summary,
        campaigns_rewards_json_v100($evidence+['source'=>'v122_verified_journey_outcomes']),
        campaigns_rewards_json_v100($impact+['requires_human_decision'=>true,'auto_apply'=>false]),$owner,
    ]);
    campaigns_rewards_activity_event_v100($pdo,$merchantId,'campaign.journey_recommendation_proposed',['campaign_id'=>$campaignId],[
        'summary'=>'Campaign journey optimization recommendation proposed','campaign_public_id'=>$campaign['public_id'],'recommendation_type'=>$type,
    ],(string)$campaign['environment'],null,'agent');
    return true;
}

function campaigns_rewards_refresh_optimization_recommendations_v122(PDO $pdo,int $merchantId=0): array
{
    $sql="SELECT c.id FROM campaigns c INNER JOIN merchant_accounts m ON m.id=c.merchant_id WHERE c.status='active' AND c.environment='production' AND m.status='active'";
    $params=[];if($merchantId>0){$sql.=" AND c.merchant_id=?";$params[]=$merchantId;}$sql.=" ORDER BY c.id";
    $q=$pdo->prepare($sql);$q->execute($params);$reviewed=0;$created=0;
    foreach($q->fetchAll()?:[] as $row){
        $campaign=campaigns_rewards_campaign_platform_v100($pdo,(int)$row['id']);if(!$campaign)continue;$owner=(int)$campaign['merchant_owner_user_id'];if($owner<1)continue;$reviewed++;
        try{$analytics=campaigns_rewards_journey_path_analytics_v122($pdo,(int)$campaign['id'],$owner);}catch(Throwable $e){continue;}
        foreach($analytics['steps'] as $step){
            if((int)$step['entered']>=10&&(float)$step['dropoff_rate']>=40.0&&(int)$step['dropoff']>=5){
                if(campaigns_rewards_create_optimization_recommendation_v122($pdo,$campaign,'dropoff-'.(string)$step['journey_key'].'-'.(string)$step['step_key'],
                    'Review '.$step['journey_key'].' / '.$step['step_key'].': '.$step['dropoff_rate'].'% of entered journey instances did not continue to the configured next step.',
                    ['journey_key'=>$step['journey_key'],'step_key'=>$step['step_key'],'entered'=>$step['entered'],'dropoff'=>$step['dropoff'],'dropoff_rate'=>$step['dropoff_rate']],
                    ['suggested_action'=>'Review copy, branch condition, wait length, consent or next-step configuration.']))$created++;
            }
        }
        foreach(campaigns_rewards_ab_comparison_v122($pdo,(int)$campaign['id'],$owner) as $ab){
            if((int)$ab['sent']<20)continue;$rates=array_map(static fn($v)=>(float)$v['conversion_rate'],$ab['variants']);$spread=max($rates)-min($rates);
            if($spread>=5.0&&campaigns_rewards_create_optimization_recommendation_v122($pdo,$campaign,'ab-review-'.(string)$ab['journey_key'].'-'.(string)$ab['step_key'],
                'Review A/B variants for '.$ab['journey_key'].' / '.$ab['step_key'].': observed conversion rates differ by '.round($spread,1).' percentage points across '.$ab['sent'].' sent messages.',
                ['journey_key'=>$ab['journey_key'],'step_key'=>$ab['step_key'],'variants'=>$ab['variants']],
                ['suggested_action'=>'Review the observed variant outcomes before changing weights or copy.']))$created++;
        }
        foreach(['email','sms'] as $channel){
            $signal=campaigns_rewards_send_time_signal_v122($pdo,(int)$campaign['id'],$channel,20);
            if(!empty($signal['eligible'])&&campaigns_rewards_create_optimization_recommendation_v122($pdo,$campaign,'send-time-'.$channel,
                'Consider testing '.$channel.' delivery near '.str_pad((string)$signal['recommended_hour'],2,'0',STR_PAD_LEFT).':00 '.$signal['timezone'].'; this hour has the strongest verified view/conversion signal across '.$signal['samples'].' historical sends.',
                ['channel'=>$channel,'samples'=>$signal['samples'],'recommended_hour'=>$signal['recommended_hour'],'timezone'=>$signal['timezone'],'score'=>$signal['score']],
                ['suggested_action'=>'Enable send-time optimization on selected message nodes after human review.']))$created++;
        }
    }
    return ['campaigns_reviewed'=>$reviewed,'recommendations_created'=>$created];
}

function campaigns_rewards_optimization_recommendations_v122(PDO $pdo,int $merchantId,int $campaignId,int $actorUserId): array
{
    campaigns_rewards_platform_assert_can_v100($pdo,$merchantId,$actorUserId,'campaigns.view');
    $q=$pdo->prepare("SELECT * FROM campaign_agent_recommendations WHERE merchant_id=? AND campaign_id=? AND recommendation_type LIKE 'journey.%' ORDER BY (status='proposed') DESC,created_at DESC,id DESC LIMIT 100");
    $q->execute([$merchantId,$campaignId]);$out=[];
    foreach($q->fetchAll()?:[] as $row){
        $row['evidence']=json_decode((string)$row['evidence_refs_json'],true)?:[];$row['impact']=json_decode((string)($row['impact_preview_json']??''),true)?:[];$out[]=$row;
    }
    return $out;
}

function campaigns_rewards_review_recommendation_v122(PDO $pdo,int $merchantId,int $campaignId,int $recommendationId,int $actorUserId,string $decision): array
{
    campaigns_rewards_platform_assert_can_v100($pdo,$merchantId,$actorUserId,'campaigns.edit');
    if(!in_array($decision,['accepted','dismissed'],true))throw new RuntimeException('Choose accept or dismiss.');
    $q=$pdo->prepare("SELECT * FROM campaign_agent_recommendations WHERE id=? AND merchant_id=? AND campaign_id=? AND recommendation_type LIKE 'journey.%' LIMIT 1");
    $q->execute([$recommendationId,$merchantId,$campaignId]);$row=$q->fetch()?:throw new RuntimeException('Journey recommendation not found.');
    if((string)$row['status']!=='proposed')return $row;
    $pdo->prepare("UPDATE campaign_agent_recommendations SET status=?,resolved_at=UTC_TIMESTAMP() WHERE id=?")->execute([$decision,$recommendationId]);
    $campaign=campaigns_rewards_campaign_platform_v100($pdo,$campaignId);
    if($campaign)campaigns_rewards_activity_event_v100($pdo,$merchantId,'campaign.journey_recommendation_reviewed',['campaign_id'=>$campaignId],[
        'summary'=>'Campaign journey recommendation reviewed','campaign_public_id'=>$campaign['public_id'],'recommendation_id'=>$recommendationId,'decision'=>$decision,
        'auto_applied'=>false,
    ],(string)$campaign['environment'],$actorUserId);
    $q=$pdo->prepare("SELECT * FROM campaign_agent_recommendations WHERE id=?");$q->execute([$recommendationId]);return $q->fetch()?:$row;
}

function campaigns_rewards_journey_intelligence_v122(PDO $pdo,int $campaignId,int $actorUserId): array
{
    $path=campaigns_rewards_journey_path_analytics_v122($pdo,$campaignId,$actorUserId);
    $ab=campaigns_rewards_ab_comparison_v122($pdo,$campaignId,$actorUserId);
    $signals=[];foreach(['email','sms'] as $channel)$signals[$channel]=campaigns_rewards_send_time_signal_v122($pdo,$campaignId,$channel,20);
    return ['path'=>$path,'ab'=>$ab,'send_time'=>$signals];
}

function campaigns_rewards_run_due_v122(PDO $pdo,int $merchantId=0): array
{
    $automation=function_exists('campaigns_rewards_automation_run_due_v119')?campaigns_rewards_automation_run_due_v119($pdo,$merchantId):[];
    $expiration=campaigns_rewards_queue_expiration_reminders_v121($pdo,$merchantId);
    $delivery=campaigns_rewards_dispatch_due_v122($pdo,$merchantId);
    $recommendations=campaigns_rewards_refresh_optimization_recommendations_v122($pdo,$merchantId);
    return ['automation'=>$automation,'expiration_reminders'=>$expiration,'deliveries'=>$delivery,'optimization_recommendations'=>$recommendations];
}
