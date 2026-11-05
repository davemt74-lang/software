<?php
declare(strict_types=1);

require_once __DIR__.'/agent-catch-up-missions-v1.php';
require_once __DIR__.'/agent-catch-up-profile-calls-v1.php';
require_once __DIR__.'/agent-catch-up-meeting-prep-v1.php';

function vp3_catch_up_page_binding_v1(array $user): string
{
    return hash_hmac('sha256','working-attention|'.(int)$user['id'].'|'.session_id(),csrf_token());
}

/** Read-only projections of current owner records. No notification or delivery state changes. */
function vp3_catch_up_item_v1(string $id,string $title,string $reason,string $sourceLabel,string $path,int $priority,int $time): array
{
    return [
        'id'=>$id,
        'title'=>mb_strimwidth(trim(preg_replace('/\s+/u',' ',$title)??''),0,170,'…'),
        'reason'=>$reason,
        'source_label'=>$sourceLabel,
        'url'=>url($path),
        'priority'=>$priority,
        'time'=>$time,
    ];
}

function vp3_catch_up_timestamp_v1(mixed $value,DateTimeZone $timezone): ?int
{
    if(!is_string($value)||!preg_match('/^\d{4}-\d{2}-\d{2}(?:[T ]\d{2}:\d{2}(?::\d{2})?(?:Z|[+-]\d{2}:\d{2})?)?$/D',$value))return null;
    try{
        $date=new DateTimeImmutable($value,$timezone);
        $errors=DateTimeImmutable::getLastErrors();
        return $errors===false?$date->getTimestamp():null;
    }catch(Exception $e){return null;}
}

function vp3_catch_up_calendar_v1(PDO $pdo,array $user,DateTimeImmutable $now): array
{
    if(!function_exists('calendar_schedule_awareness_snapshot_v1330'))return ['unavailable',[]];
    $snapshot=calendar_schedule_awareness_snapshot_v1330($pdo,$user,$now,1,includeHomeServer:false);
    if(empty($snapshot['available']))return ['unavailable',[]];

    $timezone=new DateTimeZone((string)$snapshot['timezone']);
    $nowTimestamp=$now->getTimestamp();
    $events=[];
    foreach($snapshot['upcoming'] as $event)$events[$event['ref']]=$event;
    if(is_array($snapshot['current']))$events[$snapshot['current']['ref']]=$snapshot['current'];
    $items=[];$covered=[];
    foreach($snapshot['conflicts'] as $conflict){
        $left=$events[$conflict['left_ref']]??null;
        $right=$events[$conflict['right_ref']]??null;
        if(!$left||!$right)continue;
        $leftStart=vp3_catch_up_timestamp_v1($left['start_local'],$timezone);
        $rightStart=vp3_catch_up_timestamp_v1($right['start_local'],$timezone);
        if($leftStart===null||$rightStart===null)continue;
        $starts=max($leftStart,$rightStart);
        if($starts>$nowTimestamp+86400)continue;
        $refs=[$left['ref'],$right['ref']];sort($refs,SORT_STRING);
        $id='calendar-conflict:'.hash('sha256',implode('|',$refs));
        $local=(new DateTimeImmutable('@'.$starts))->setTimezone($timezone);
        $items[]=vp3_catch_up_item_v1($id,$left['title'].' overlaps '.$right['title'],
            'Your calendar shows a '.$conflict['overlap_minutes'].'-minute overlap '.$local->format('D, M j \a\t g:i A T').'.',
            'Cloud Calendar','/calendar.php?month='.$local->format('Y-m'),$starts<=$nowTimestamp+1800?100:84,$starts);
        foreach($refs as $ref)$covered[$ref]=true;
    }
    $next=$snapshot['next'];
    if(!empty($snapshot['starts_soon'])&&is_array($next)&&!isset($covered[$next['ref']])){
        $start=vp3_catch_up_timestamp_v1($next['start_local'],$timezone);
        if($start!==null){
            $local=(new DateTimeImmutable('@'.$start))->setTimezone($timezone);
            $items[]=vp3_catch_up_item_v1('calendar:'.$next['ref'],$next['title'],
                'Starts at '.$local->format('g:i A T').'.','Cloud Calendar','/calendar.php?month='.$local->format('Y-m'),$start<=$nowTimestamp+900?95:85,$start)
                +['_calendar_event_id'=>$next['kind']==='event'?(int)$next['id']:0];
        }
    }
    return ['ready',$items];
}

function vp3_catch_up_reminders_v1(PDO $pdo,array $user,array $principal,DateTimeImmutable $now): array
{
    if(!function_exists('reminder_ready_v1')||!reminder_ready_v1($pdo))return ['unavailable',[]];
    $timezone=user_calendar_default_timezone_v1300($pdo,$user);
    $zone=new DateTimeZone(reminder_zone_v1($timezone));
    $rows=reminder_list_rows_v1($pdo,(int)$user['id'],'open',$zone->getName(),$now);
    $items=[];
    foreach($rows as $row){
        $id=(int)$row['id'];
        if(!vp3_participant_resource_v1($pdo,$user,$principal,'reminder',$id))continue;
        $at=vp3_catch_up_timestamp_v1($row['list_at']??null,new DateTimeZone('UTC'));
        if($at===null||$at>$now->getTimestamp())continue;
        $local=(new DateTimeImmutable('@'.$at))->setTimezone($zone);
        $agentId=(int)($principal['agent_id']??0);
        $path='/chat.php?agent='.($agentId>0?$agentId:'system');
        $conversation=(int)($row['conversation_id']??0);
        if($conversation>0&&vp3_agent_chat_conversation_v380($pdo,$conversation,(int)$user['id'],$agentId>0?['id'=>$agentId]:null)){
            $path.='&conversation_id='.$conversation;
        }
        $items[]=vp3_catch_up_item_v1('reminder:'.$id,(string)$row['title'],
            'This reminder was due '.$local->format('D, M j \a\t g:i A T').'.',
            'Reminders',$path,90,$at);
    }
    return [count($rows)>=100?'limited':'ready',$items];
}

function vp3_catch_up_tasks_v1(PDO $pdo,array $user,array $principal,DateTimeImmutable $now): array
{
    if(!personal_capability_has_v242('agent_brain.access',$user))return ['forbidden',[]];
    if(!vp3_agent_memory_scope_schema_ready_v410($pdo))return ['unavailable',[]];
    vp3_task_list_authorize_v1($pdo,$user,$principal,0);
    [$where,$params]=vp3_agent_memory_scope_sql_v410((int)$principal['agent_id']);
    $q=$pdo->prepare("SELECT id,subject,metadata_json FROM agent_memory_items WHERE user_id=? AND {$where} AND is_active=1 AND memory_type IN ('task','commitment') ORDER BY last_seen_at DESC,id DESC LIMIT 100");
    $q->execute([(int)$user['id'],...$params]);
    $rows=$q->fetchAll(PDO::FETCH_ASSOC);$items=[];
    $timezone=new DateTimeZone(reminder_zone_v1(user_calendar_default_timezone_v1300($pdo,$user)));
    $end=$now->setTimezone($timezone)->setTime(23,59,59)->getTimestamp();
    foreach($rows as $row){
        $meta=json_decode((string)$row['metadata_json'],true);
        if(!is_array($meta)||!in_array($meta['task_status']??null,['open','in_progress'],true))continue;
        $meeting=($meta['source_kind']??'')==='video_meeting_intelligence'&&($meta['review_state']??'')==='approved';
        $assigned=(int)($meta['assigned_user_id']??0);
        if($assigned>0&&$assigned!==(int)$user['id'])continue;
        $due=$meta['due_at']??($meeting?($meta['due_date']??null):null);
        $at=vp3_catch_up_timestamp_v1($due,$timezone);
        if($at===null||$at>$end||!vp3_task_list_task_allowed_v1($pdo,$user,$principal,(int)$row['id']))continue;
        $local=(new DateTimeImmutable('@'.$at))->setTimezone($timezone);
        $dayOnly=is_string($due)&&strlen($due)===10;
        $path='/account.php#agent-brain';
        if($meeting&&function_exists('video_meeting_row_v1800')){
            $record=video_meeting_row_v1800($pdo,(int)($meta['video_meeting_id']??0));
            if($record&&(int)$record['owner_user_id']===(int)$user['id']){
                $path='/meeting.php?meeting='.rawurlencode((string)$record['public_id']).'&review=1';
            }
        }
        $items[]=vp3_catch_up_item_v1('task:'.(int)$row['id'],(string)$row['subject'],
            ($meeting?'An approved meeting follow-up is due ':'This open task is due ').$local->format($dayOnly?'D, M j':'D, M j \a\t g:i A T').'.',
            $meeting?'Meeting follow-up':'Agent Brain tasks',$path,70,$at);
    }
    return [count($rows)>=100?'limited':'ready',$items];
}

function vp3_catch_up_orders_v1(PDO $pdo,array $user): array
{
    if(!agent_commerce_schema_ready_v800($pdo))return ['unavailable',[]];
    require_once __DIR__.'/profile-commerce-lifecycle-v1100.php';
    $owner=(int)$user['id'];
    $rows=agent_commerce_orders_for_owner_v800($pdo,$owner,100);$items=[];
    foreach($rows as $order){
        if((int)$order['owner_user_id']!==$owner||!profile_commerce_order_is_profile_v900($order))continue;
        $payment=(string)$order['payment_status'];
        if(!in_array($payment,['paid','partially_paid','partially_refunded'],true))continue;
        $refund=profile_commerce_customer_refund_request_v1100($order);
        $pendingRefund=($refund['status']??null)==='pending'&&(int)$order['amount_paid_cents']>(int)$order['amount_refunded_cents'];
        $review=$payment!=='partially_refunded'&&profile_commerce_customer_review_required_v1100($order);
        if(!$pendingRefund&&!$review)continue;
        $time=vp3_catch_up_timestamp_v1($pendingRefund?$refund['requested_at']:$order['updated_at'],new DateTimeZone('UTC'))??PHP_INT_MAX;
        $items[]=vp3_catch_up_item_v1('order:'.(int)$order['id'],'Order '.(string)$order['order_number'],
            $pendingRefund?'A customer refund request is waiting for your review.':'Verified payment is waiting for a seller review before fulfillment.',
            'Profile Commerce',$pendingRefund?'/profile-commerce-refund-requests.php':'/profile-commerce-products.php#orders',$pendingRefund?88:85,$time);
    }
    return [count($rows)>=100?'limited':'ready',$items];
}

function vp3_catch_up_campaigns_v1(PDO $pdo,array $user): array
{
    if(!function_exists('campaigns_rewards_journey_operations_schema_ready_v124')||!campaigns_rewards_journey_operations_schema_ready_v124($pdo))return ['unavailable',[]];
    $uid=(int)$user['id'];$items=[];$limited=false;
    $merchants=campaigns_rewards_accessible_merchants_v100($pdo,$user);
    $limited=count($merchants)>10;
    foreach(array_slice($merchants,0,10) as $merchant){
        $id=(int)$merchant['id'];
        if($merchant['status']!=='active'||!empty($merchant['sandbox_mode'])||!campaigns_rewards_platform_can_v100($pdo,$id,$uid,'campaigns.view'))continue;
        $q=$pdo->prepare("SELECT c.id,c.name,i.journey_id,COUNT(*) AS affected,MIN(i.last_activity_at) AS since_at
            FROM campaign_journey_instances i JOIN campaigns c ON c.id=i.campaign_id
            JOIN campaign_journeys j ON j.id=i.journey_id AND j.campaign_id=c.id
            WHERE c.merchant_id=? AND c.environment='production' AND c.status='active' AND i.status='needs_attention'
            GROUP BY c.id,c.name,i.journey_id ORDER BY since_at,c.id,i.journey_id LIMIT 5");
        $q->execute([$id]);$rows=$q->fetchAll(PDO::FETCH_ASSOC);
        $limited=$limited||count($rows)>=5;
        foreach($rows as $row){
            $count=(int)$row['affected'];$journey=(int)$row['journey_id'];
            $items[]=vp3_catch_up_item_v1('campaign-journey:'.$journey,(string)$row['name'],
                $count===1?'A campaign journey instance needs review.':$count.' campaign journey instances need review.',
                'Campaigns','/campaigns.php?merchant='.$id.'&journey='.$journey,80,
                vp3_catch_up_timestamp_v1($row['since_at'],new DateTimeZone('UTC'))??PHP_INT_MAX);
        }
    }
    return [$limited?'limited':'ready',$items];
}

function vp3_catch_up_rewards_v1(PDO $pdo,array $user,DateTimeImmutable $now): array
{
    if(!function_exists('campaigns_rewards_v110_schema_ready')||!campaigns_rewards_v110_schema_ready($pdo))return ['unavailable',[]];
    // Keep the Inbox holder and open-issuance checks and narrow to live merchant rewards.
    $q=$pdo->prepare("SELECT ri.id,ri.expires_at,rp.name,m.owner_user_id AS merchant_owner_id FROM reward_issuances ri
        JOIN reward_products rp ON rp.id=ri.reward_product_id
        JOIN campaigns c ON c.id=ri.campaign_id AND c.merchant_id=ri.merchant_id
        JOIN merchant_accounts m ON m.id=ri.merchant_id
        JOIN crm_contacts cc ON cc.id=ri.recipient_contact_id
        WHERE ri.recipient_user_id=? AND ri.status IN ('issued','sent','viewed') AND ri.remaining_quantity>0
        AND c.environment='production' AND c.status IN ('active','completed') AND m.sandbox_mode=0 AND m.status='active'
        AND ri.expires_at>? AND ri.expires_at<=? ORDER BY ri.expires_at,ri.id LIMIT 20");
    $q->execute([(int)$user['id'],$now->format('Y-m-d H:i:s'),$now->modify('+72 hours')->format('Y-m-d H:i:s')]);
    $rows=$q->fetchAll(PDO::FETCH_ASSOC);$items=[];$enabled=[];
    $zone=new DateTimeZone(reminder_zone_v1(user_calendar_default_timezone_v1300($pdo,$user)));
    foreach($rows as $row){
        $merchantOwner=(int)$row['merchant_owner_id'];
        $enabled[$merchantOwner]??=campaigns_rewards_owner_plugin_enabled_v100($pdo,$merchantOwner);
        if(!$enabled[$merchantOwner])continue;
        $at=vp3_catch_up_timestamp_v1($row['expires_at'],new DateTimeZone('UTC'));
        if($at===null)continue;
        $local=(new DateTimeImmutable('@'.$at))->setTimezone($zone);
        $items[]=vp3_catch_up_item_v1('reward:'.(int)$row['id'],(string)$row['name'],
            'This reward in your Inbox expires '.$local->format('D, M j \a\t g:i A T').'.','Rewards Inbox','/reward-inbox.php',60,$at);
    }
    return [count($rows)>=20?'limited':'ready',$items];
}

function vp3_agent_catch_up_v1(PDO $pdo,array $user,string $scope='all',int $agentId=0,bool $includeWorkingSources=true): array
{
    if(!in_array($scope,['all','office','commerce'],true)||$agentId<0)throw new InvalidArgumentException('Invalid catch-up scope.');
    if((int)($user['id']??0)<1||!has_permission('chat.access',$user)||!has_permission('account.access',$user)
        ||(function_exists('vp3_participant_scope_v1')&&vp3_participant_scope_v1()!==null)){
        throw new RuntimeException('Catch-up access is unavailable.',403);
    }
    $agent=vp3_agent_chat_resolve_agent_v380($pdo,$user,$agentId);
    $principal=vp3_agent_chat_principal_v380($user,$agent);
    $user=vp3_capability_live_user_v1($pdo,$user,$principal,[],null);
    if(!has_permission('account.access',$user))throw new RuntimeException('Catch-up access is unavailable.',403);
    $now=new DateTimeImmutable('now',new DateTimeZone('UTC'));
    $office=[
        'calendar'=>fn()=>vp3_catch_up_calendar_v1($pdo,$user,$now),
        'reminders'=>fn()=>vp3_catch_up_reminders_v1($pdo,$user,$principal,$now),
        'tasks'=>fn()=>vp3_catch_up_tasks_v1($pdo,$user,$principal,$now),
    ];
    if($includeWorkingSources){
        $office['missions']=fn()=>vp3_catch_up_missions_v1($pdo,$user,$principal,$now);
        $office['profile_calls']=fn()=>vp3_catch_up_profile_calls_v1($pdo,$user,$principal,$now);
        $office['meeting_prep']=fn()=>vp3_catch_up_meeting_prep_v1($pdo,$user,$principal,$now);
    }
    $commerce=[
        'orders'=>fn()=>vp3_catch_up_orders_v1($pdo,$user),
        'campaigns'=>fn()=>vp3_catch_up_campaigns_v1($pdo,$user),
        'rewards'=>fn()=>vp3_catch_up_rewards_v1($pdo,$user,$now),
    ];
    $sources=match($scope){'office'=>$office,'commerce'=>$commerce,default=>$office+$commerce};
    $coverage=[];$items=[];$preparedStarts=[];
    foreach($sources as $key=>$read){
        if($agentId>0&&isset($commerce[$key])){$coverage[$key]='forbidden';continue;}
        try{
            [$coverage[$key],$found]=$read();$items=[...$items,...$found];
            if($key==='meeting_prep'){
                foreach($found as $item)$preparedStarts[$item['_calendar_event_id']][$item['time']]=true;
            }
        }
        catch(Throwable $e){$coverage[$key]=$e->getCode()===403?'forbidden':'unavailable';}
    }
    usort($items,static fn(array $a,array $b):int=>($b['priority']<=>$a['priority'])?:($a['time']<=>$b['time'])?:strcmp($a['id'],$b['id']));
    $selected=[];
    foreach($items as $item){
        if(isset($selected[$item['id']])||(str_starts_with($item['id'],'calendar:')
            &&isset($preparedStarts[$item['_calendar_event_id']][$item['time']])))continue;
        unset($item['priority'],$item['time'],$item['_calendar_event_id']);
        $selected[$item['id']]=$item;
        if(count($selected)===3)break;
    }
    return ['items'=>array_values($selected),'as_of'=>$now->format(DATE_ATOM),'coverage'=>$coverage];
}
