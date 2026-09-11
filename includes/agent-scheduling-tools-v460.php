<?php
declare(strict_types=1);

/**
 * VP3 Agent Scheduling Tools v4.60
 *
 * Conversational, owner-scoped scheduling skills for Agent Chat plus a
 * read-only public Profile Agent scheduling skill. State-changing owner tools
 * are always prepared first and require an explicit second-turn confirmation.
 */
const VP3_AGENT_SCHEDULING_TOOLS_V460 = 'agent-scheduling-tools-v460-20260911';

function agent_scheduling_tools_empty_v460(): array
{
    return ['handled'=>false,'answer'=>'','stem_media'=>[],'media'=>[],'actions'=>[],'sources'=>[]];
}

function agent_scheduling_tools_ready_v460(PDO $pdo): bool
{
    return agent_scheduling_schema_ready_v430($pdo)
        && table_exists('agent_scheduling_schedules')
        && table_exists('agent_scheduling_event_types')
        && table_exists('agent_scheduling_bookings');
}

function agent_scheduling_tools_conversation_agent_id_v460(PDO $pdo,array $user,int $conversationId): int
{
    $userId=(int)($user['id']??0);
    if($userId<1||$conversationId<1||!table_exists('chat_conversations'))return 0;
    try{
        $stmt=$pdo->prepare('SELECT user_agent_id FROM chat_conversations WHERE id=? AND user_id=? LIMIT 1');
        $stmt->execute([$conversationId,$userId]);
        $value=$stmt->fetchColumn();
        if($value===false||$value===null)return 0;
        $agentId=max(0,(int)$value);
        return $agentId>0&&user_agent_get_v236($pdo,$userId,$agentId)?$agentId:0;
    }catch(Throwable $e){return 0;}
}

function agent_scheduling_tools_schedule_v460(PDO $pdo,array $user): ?array
{
    $userId=(int)($user['id']??0);
    if($userId<1||!agent_scheduling_tools_ready_v460($pdo))return null;
    try{return agent_scheduling_default_schedule_v430($pdo,$user);}catch(Throwable $e){return null;}
}

function agent_scheduling_tools_events_v460(PDO $pdo,array $user,?array $schedule=null): array
{
    $schedule??=agent_scheduling_tools_schedule_v460($pdo,$user);
    if(!$schedule||(int)$schedule['owner_user_id']!==(int)($user['id']??0))return [];
    try{
        $stmt=$pdo->prepare('SELECT * FROM agent_scheduling_event_types WHERE schedule_id=? AND is_active=1 ORDER BY sort_order,title,id');
        $stmt->execute([(int)$schedule['id']]);
        return $stmt->fetchAll()?:[];
    }catch(Throwable $e){return [];}
}

function agent_scheduling_tools_event_v460(PDO $pdo,array $user,array $events,string $query): ?array
{
    if(!$events)return null;
    $q=mb_strtolower($query);
    $best=null;$score=0;
    foreach($events as $event){
        $title=mb_strtolower(trim((string)($event['title']??'')));
        $slug=mb_strtolower(trim((string)($event['slug']??'')));
        $candidate=0;
        if($title!==''&&str_contains($q,$title))$candidate+=120;
        if($slug!==''&&str_contains($q,str_replace('-',' ',$slug)))$candidate+=100;
        $duration=(int)($event['duration_minutes']??0);
        if($duration>0&&preg_match('/\b'.preg_quote((string)$duration,'/').'\s*(?:min|mins|minute|minutes)\b/i',$query))$candidate+=60;
        foreach(preg_split('/[^\pL\pN]+/u',$title)?:[] as $term){
            if(mb_strlen($term)>=4&&str_contains($q,$term))$candidate+=5;
        }
        if($candidate>$score){$score=$candidate;$best=$event;}
    }
    if($best)return $best;
    return count($events)===1?$events[0]:null;
}

function agent_scheduling_tools_date_v460(string $query,string $timezone,?string $fallbackDate=null): ?string
{
    $tz=new DateTimeZone(agent_scheduling_timezone_v430($timezone));
    $now=new DateTimeImmutable('now',$tz);
    if(preg_match('/\b(20\d{2}-\d{2}-\d{2})\b/',$query,$m)){
        $d=DateTimeImmutable::createFromFormat('!Y-m-d',$m[1],$tz);
        if($d&&$d->format('Y-m-d')===$m[1])return $m[1];
    }
    $q=mb_strtolower($query);
    if(str_contains($q,'day after tomorrow'))return $now->modify('+2 days')->format('Y-m-d');
    if(preg_match('/\btomorrow\b/i',$query))return $now->modify('+1 day')->format('Y-m-d');
    if(preg_match('/\btoday\b/i',$query))return $now->format('Y-m-d');

    if(preg_match('/\b(?:(next|this)\s+)?(monday|tuesday|wednesday|thursday|friday|saturday|sunday)\b/i',$query,$m)){
        $modifier=trim((string)($m[1]??''));
        $weekday=strtolower($m[2]);
        $phrase=($modifier!==''?$modifier.' ':'next ').$weekday;
        $ts=strtotime($phrase,$now->getTimestamp());
        if($ts!==false)return (new DateTimeImmutable('@'.$ts))->setTimezone($tz)->format('Y-m-d');
    }

    if(preg_match('/\b(january|february|march|april|may|june|july|august|september|october|november|december)\s+(\d{1,2})(?:st|nd|rd|th)?(?:,\s*(20\d{2}))?\b/i',$query,$m)){
        $phrase=$m[1].' '.$m[2].(!empty($m[3])?' '.$m[3]:' '.$now->format('Y'));
        $ts=strtotime($phrase,$now->getTimestamp());
        if($ts!==false){
            $d=(new DateTimeImmutable('@'.$ts))->setTimezone($tz);
            if($d<$now->setTime(0,0)&&empty($m[3]))$d=$d->modify('+1 year');
            return $d->format('Y-m-d');
        }
    }
    return $fallbackDate;
}

function agent_scheduling_tools_time_request_v460(string $query): ?array
{
    if(preg_match('/\b(?:at|for|around)\s+(\d{1,2})(?::([0-5]\d))?\s*(a\.?m\.?|p\.?m\.?)\b/i',$query,$m)
        || preg_match('/\b(\d{1,2}):([0-5]\d)\s*(a\.?m\.?|p\.?m\.?)\b/i',$query,$m)){
        $hour=(int)$m[1];$minute=(int)($m[2]??0);
        if($hour<1||$hour>12)return null;
        $meridiem=str_starts_with(strtolower(str_replace('.','',(string)$m[3])),'p')?'pm':'am';
        return ['hour'=>$hour%12+($meridiem==='pm'?12:0),'minute'=>$minute,'meridiem'=>$meridiem,'ambiguous'=>false];
    }
    if(preg_match('/\b(?:at|around)\s+([01]?\d|2[0-3]):([0-5]\d)\b/i',$query,$m)){
        return ['hour'=>(int)$m[1],'minute'=>(int)$m[2],'meridiem'=>'24h','ambiguous'=>false];
    }
    if(preg_match('/\b(?:at|around)\s+(\d{1,2})(?:\s*o\'?clock)?\b/i',$query,$m)){
        $hour=(int)$m[1];
        if($hour<1||$hour>12)return null;
        return ['hour'=>$hour,'minute'=>0,'meridiem'=>'','ambiguous'=>true];
    }
    return null;
}

function agent_scheduling_tools_slot_label_v460(string $utc,string $timezone,string $format='D, M j · g:i A T'): string
{
    try{
        return (new DateTimeImmutable($utc,new DateTimeZone('UTC')))
            ->setTimezone(new DateTimeZone(agent_scheduling_timezone_v430($timezone)))
            ->format($format);
    }catch(Throwable $e){return $utc.' UTC';}
}

function agent_scheduling_tools_slots_excluding_v460(PDO $pdo,array $event,string $date,int $excludeBookingId=0): array
{
    $timezone=agent_scheduling_timezone_v430((string)$event['schedule_timezone']);
    $tz=new DateTimeZone($timezone);
    $day=DateTimeImmutable::createFromFormat('!Y-m-d',$date,$tz);
    if(!$day||$day->format('Y-m-d')!==$date)return [];
    $duration=max(5,(int)$event['duration_minutes']);
    $interval=max(5,(int)$event['slot_interval_minutes']);
    $slots=[];
    foreach(agent_scheduling_windows_for_date_v430($pdo,$event,$date) as [$windowStart,$windowEnd]){
        for($minute=$windowStart;$minute+$duration<=$windowEnd;$minute+=$interval){
            $local=$day->setTime(intdiv($minute,60),$minute%60);
            $utc=$local->setTimezone(new DateTimeZone('UTC'));
            try{agent_scheduling_validate_start_v430($pdo,$event,$utc,$excludeBookingId);}catch(RuntimeException $e){continue;}
            $slots[]=[
                'start_at_utc'=>$utc->format('Y-m-d H:i:s'),
                'end_at_utc'=>$utc->modify('+'.$duration.' minutes')->format('Y-m-d H:i:s'),
                'start_local'=>$local->format('Y-m-d H:i:s'),
                'timezone'=>$timezone,
            ];
        }
    }
    return $slots;
}

function agent_scheduling_tools_pick_slot_v460(array $slots,?array $time): array
{
    if(!$slots)return ['slot'=>null,'ambiguous'=>false];
    if(!$time)return ['slot'=>null,'ambiguous'=>false];
    $matches=[];
    foreach($slots as $slot){
        try{
            $local=new DateTimeImmutable((string)$slot['start_local'],new DateTimeZone((string)$slot['timezone']));
        }catch(Throwable $e){continue;}
        $h=(int)$local->format('G');$m=(int)$local->format('i');
        if(!empty($time['ambiguous'])){
            if(($h%12)===((int)$time['hour']%12)&&$m===(int)$time['minute'])$matches[]=$slot;
        }elseif($h===(int)$time['hour']&&$m===(int)$time['minute']){
            $matches[]=$slot;
        }
    }
    return ['slot'=>count($matches)===1?$matches[0]:null,'ambiguous'=>count($matches)>1,'matches'=>$matches];
}

function agent_scheduling_tools_email_v460(string $query): string
{
    if(preg_match('/\b[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}\b/i',$query,$m))return strtolower($m[0]);
    return '';
}

function agent_scheduling_tools_guest_name_v460(string $query): string
{
    $patterns=[
        '/\b(?:meeting|appointment|call|session)\s+with\s+([\pL][\pL .\'\-]{0,70}?)(?=\s+(?:on|at|tomorrow|today|next|this|for)\b|[,;.!?]|$)/iu',
        '/\b(?:book|schedule)\s+(?:a\s+)?(?:meeting|appointment|call|session)?\s*(?:with|for)\s+([\pL][\pL .\'\-]{0,70}?)(?=\s+(?:on|at|tomorrow|today|next|this|for)\b|[,;.!?]|$)/iu',
        '/\bwith\s+([\pL][\pL .\'\-]{0,70}?)(?=\s+(?:on|at|tomorrow|today|next|this|for)\b|[,;.!?]|$)/iu',
    ];
    foreach($patterns as $pattern){
        if(preg_match($pattern,$query,$m)){
            $name=trim(preg_replace('/\s+/u',' ',$m[1])??'');
            if($name!==''&&!preg_match('/^(?:me|myself|you|the agent|a client)$/i',$name))return mb_strimwidth($name,0,190,'');
        }
    }
    return '';
}

function agent_scheduling_tools_booking_rows_v460(PDO $pdo,array $user,int $limit=30,bool $upcomingOnly=true): array
{
    $userId=(int)($user['id']??0);
    if($userId<1)return [];
    $where=$upcomingOnly?"AND b.status IN ('pending','confirmed') AND b.end_at_utc>=UTC_TIMESTAMP()":'';
    try{
        $stmt=$pdo->prepare(
            "SELECT b.*,e.slug AS event_slug,s.name AS schedule_name
             FROM agent_scheduling_bookings b
             LEFT JOIN agent_scheduling_event_types e ON e.id=b.event_type_id
             LEFT JOIN agent_scheduling_schedules s ON s.id=b.schedule_id
             WHERE b.owner_user_id=? {$where}
             ORDER BY b.start_at_utc ".($upcomingOnly?'ASC':'DESC').",b.id ".($upcomingOnly?'ASC':'DESC')."
             LIMIT ".max(1,min(100,$limit))
        );
        $stmt->execute([$userId]);
        return $stmt->fetchAll()?:[];
    }catch(Throwable $e){return [];}
}

function agent_scheduling_tools_find_booking_v460(PDO $pdo,array $user,string $query): array
{
    $rows=agent_scheduling_tools_booking_rows_v460($pdo,$user,60,true);
    if(!$rows)return ['booking'=>null,'matches'=>[]];
    if(preg_match('/(?:booking|appointment)\s*#?\s*(\d{1,10})\b/i',$query,$m)){
        $id=(int)$m[1];
        foreach($rows as $row)if((int)$row['id']===$id)return ['booking'=>$row,'matches'=>[$row]];
    }
    $q=mb_strtolower($query);$dateHint='';
    foreach($rows as $row){
        $tz=agent_scheduling_timezone_v430((string)$row['organizer_timezone']);
        $candidate=agent_scheduling_tools_date_v460($query,$tz);
        if($candidate!==null){$dateHint=$candidate;break;}
    }
    $scored=[];
    foreach($rows as $row){
        $score=0;
        foreach(['guest_name'=>30,'guest_email'=>45,'event_title'=>18] as $field=>$weight){
            $value=mb_strtolower(trim((string)($row[$field]??'')));
            if($value!==''&&str_contains($q,$value))$score+=$weight;
            foreach(preg_split('/[^\pL\pN@._-]+/u',$value)?:[] as $term){
                if(mb_strlen($term)>=3&&str_contains($q,$term))$score+=4;
            }
        }
        if($dateHint!==''){
            $local=agent_scheduling_tools_slot_label_v460((string)$row['start_at_utc'],(string)$row['organizer_timezone'],'Y-m-d');
            if($local===$dateHint)$score+=35;else $score-=5;
        }
        if($score>0)$scored[]=['score'=>$score,'row'=>$row];
    }
    usort($scored,static fn(array $a,array $b):int=>$b['score']<=>$a['score']);
    if(!$scored&&count($rows)===1)return ['booking'=>$rows[0],'matches'=>$rows];
    if(!$scored)return ['booking'=>null,'matches'=>array_slice($rows,0,5)];
    $top=(int)$scored[0]['score'];
    $matches=array_values(array_map(static fn(array $x):array=>$x['row'],array_filter($scored,static fn(array $x):bool=>(int)$x['score']===$top)));
    return ['booking'=>count($matches)===1?$matches[0]:null,'matches'=>array_slice($matches,0,5)];
}

function agent_scheduling_tools_pending_key_v460(int $userId,int $conversationId): string
{
    return 'vp3_scheduling_tool_pending_'.max(0,$userId).'_'.max(0,$conversationId);
}

function agent_scheduling_tools_pending_v460(array $user,int $conversationId): ?array
{
    $key=agent_scheduling_tools_pending_key_v460((int)($user['id']??0),$conversationId);
    $pending=$_SESSION[$key]??null;
    if(!is_array($pending))return null;
    if((int)($pending['expires_at']??0)<time()){unset($_SESSION[$key]);return null;}
    if((int)($pending['user_id']??0)!==(int)($user['id']??0)){unset($_SESSION[$key]);return null;}
    return $pending;
}

function agent_scheduling_tools_clear_pending_v460(array $user,int $conversationId): void
{
    unset($_SESSION[agent_scheduling_tools_pending_key_v460((int)($user['id']??0),$conversationId)]);
}

function agent_scheduling_tools_prepare_v460(array $user,int $conversationId,int $agentId,string $operation,array $payload,string $summary,string $requestText): array
{
    $risk=function_exists('agent_action_v124_risk')
        ? agent_action_v124_risk(['source'=>'scheduling','title'=>$operation.' booking','prompt'=>$summary])
        : ['level'=>'medium','external_side_effect'=>true,'destructive'=>$operation==='cancel','requires_approval'=>true];
    $pending=[
        'version'=>'v4.60','user_id'=>(int)$user['id'],'conversation_id'=>$conversationId,'agent_id'=>$agentId,
        'operation'=>$operation,'payload'=>$payload,'summary'=>$summary,'request_text'=>mb_strimwidth($requestText,0,4000,''),
        'risk'=>$risk,'created_at'=>time(),'expires_at'=>time()+900,
        'nonce'=>bin2hex(random_bytes(16)),
    ];
    $_SESSION[agent_scheduling_tools_pending_key_v460((int)$user['id'],$conversationId)]=$pending;
    return $pending;
}

function agent_scheduling_tools_confirmation_v460(string $query): bool
{
    $q=trim(mb_strtolower($query));
    return (bool)preg_match('/^(?:yes[,!. ]*)?(?:confirm|confirmed|approve|approved|go ahead|do it|yes do it|yes confirm)(?:\s+(?:the\s+)?(?:booking|appointment|reschedule|cancellation|cancel))?[.! ]*$/u',$q);
}

function agent_scheduling_tools_reject_confirmation_v460(string $query): bool
{
    return (bool)preg_match('/^(?:no|nope|stop|never mind|nevermind|do not|don\'t|cancel that|forget it)[.! ]*$/iu',trim($query));
}

function agent_scheduling_tools_reschedule_owner_v460(PDO $pdo,array $user,array $booking,string $startAtUtc,int $agentId): array
{
    $userId=(int)($user['id']??0);
    if($userId<1||(int)($booking['owner_user_id']??0)!==$userId)throw new RuntimeException('That appointment is not available to this account.');
    if(!in_array((string)($booking['status']??''),['pending','confirmed'],true))throw new RuntimeException('Only an active appointment can be rescheduled.');
    $event=agent_scheduling_event_type_v430($pdo,(int)$booking['event_type_id']);
    if(!$event||(int)$event['owner_user_id']!==$userId)throw new RuntimeException('That appointment type is not available to this account.');
    $scheduleId=(int)$booking['schedule_id'];
    $lockName='vp3_schedule_'.$scheduleId;
    $lock=$pdo->prepare('SELECT GET_LOCK(?,5)');$lock->execute([$lockName]);
    if((int)$lock->fetchColumn()!==1)throw new RuntimeException('That schedule is busy. Try the reschedule again.');
    $started=!$pdo->inTransaction();
    if($started)$pdo->beginTransaction();
    try{
        $cancel=$pdo->prepare("UPDATE agent_scheduling_bookings SET status='cancelled',cancelled_at=NOW(),updated_at=NOW() WHERE id=? AND owner_user_id=? AND status IN ('pending','confirmed')");
        $cancel->execute([(int)$booking['id'],$userId]);
        if($cancel->rowCount()!==1)throw new RuntimeException('That appointment changed before it could be rescheduled.');
        $new=agent_scheduling_create_booking_v430($pdo,[
            'event_type_id'=>(int)$booking['event_type_id'],
            'start_at_utc'=>$startAtUtc,
            'guest_timezone'=>(string)($booking['guest_timezone']?:$booking['organizer_timezone']),
            'guest_name'=>(string)$booking['guest_name'],
            'guest_email'=>(string)$booking['guest_email'],
            'guest_phone'=>(string)$booking['guest_phone'],
            'guest_notes'=>(string)($booking['guest_notes']??''),
            'internal_notes'=>(string)($booking['internal_notes']??''),
            'created_by_user_id'=>$userId,
            'created_by_agent_id'=>$agentId,
            'source'=>'agent_reschedule',
        ]);
        $pdo->prepare('UPDATE agent_scheduling_bookings SET rescheduled_from_id=? WHERE id=? AND owner_user_id=?')->execute([(int)$booking['id'],(int)$new['id'],$userId]);
        if($started)$pdo->commit();
        return $new;
    }catch(Throwable $e){
        if($started&&$pdo->inTransaction())$pdo->rollBack();
        throw $e;
    }finally{
        try{$release=$pdo->prepare('SELECT RELEASE_LOCK(?)');$release->execute([$lockName]);}catch(Throwable $ignored){}
    }
}

function agent_scheduling_tools_execute_pending_v460(PDO $pdo,array $user,int $conversationId,array $pending): array
{
    $operation=(string)$pending['operation'];$payload=(array)($pending['payload']??[]);
    $agentId=max(0,(int)($pending['agent_id']??0));
    if($agentId>0&&!user_agent_get_v236($pdo,(int)$user['id'],$agentId))throw new RuntimeException('The Agent that prepared this action is no longer available.');
    $result=agent_scheduling_tools_empty_v460();$result['handled']=true;
    if($operation==='create'){
        $event=agent_scheduling_event_type_v430($pdo,(int)($payload['event_type_id']??0));
        if(!$event||(int)$event['owner_user_id']!==(int)$user['id'])throw new RuntimeException('That appointment type is no longer available to this account.');
        $booking=agent_scheduling_create_booking_v430($pdo,[
            'event_type_id'=>(int)$event['id'],'start_at_utc'=>(string)$payload['start_at_utc'],
            'guest_timezone'=>(string)$event['schedule_timezone'],'guest_name'=>(string)$payload['guest_name'],
            'guest_email'=>(string)($payload['guest_email']??''),'guest_phone'=>'','guest_notes'=>'',
            'created_by_user_id'=>(int)$user['id'],'created_by_agent_id'=>$agentId,'source'=>'agent',
        ]);
        $result['answer']='Booked. '.(string)$booking['event_title'].' with '.(string)$booking['guest_name'].' is set for '.agent_scheduling_tools_slot_label_v460((string)$booking['start_at_utc'],(string)$booking['organizer_timezone']).'.';
        $result['actions'][]=['type'=>'open_url','label'=>'Open Scheduling','url'=>url('/scheduling.php?open='.(int)$booking['id'].'#bookings')];
        agent_tool_log($user,'scheduling.book',(string)$pending['request_text'],'success',['booking_id'=>(int)$booking['id'],'event_type_id'=>(int)$booking['event_type_id'],'start_at_utc'=>(string)$booking['start_at_utc'],'agent_id'=>$agentId],$conversationId);
        return $result;
    }
    if($operation==='reschedule'){
        $stmt=$pdo->prepare('SELECT * FROM agent_scheduling_bookings WHERE id=? AND owner_user_id=? LIMIT 1');
        $stmt->execute([(int)($payload['booking_id']??0),(int)$user['id']]);$booking=$stmt->fetch();
        if(!$booking)throw new RuntimeException('That appointment is no longer available.');
        $new=agent_scheduling_tools_reschedule_owner_v460($pdo,$user,$booking,(string)$payload['start_at_utc'],$agentId);
        $result['answer']='Rescheduled. '.(string)$new['event_title'].' with '.(string)$new['guest_name'].' is now '.agent_scheduling_tools_slot_label_v460((string)$new['start_at_utc'],(string)$new['organizer_timezone']).'.';
        $result['actions'][]=['type'=>'open_url','label'=>'Open Scheduling','url'=>url('/scheduling.php?open='.(int)$new['id'].'#bookings')];
        agent_tool_log($user,'scheduling.reschedule',(string)$pending['request_text'],'success',['booking_id'=>(int)$new['id'],'rescheduled_from_id'=>(int)$booking['id'],'start_at_utc'=>(string)$new['start_at_utc'],'agent_id'=>$agentId],$conversationId);
        return $result;
    }
    if($operation==='cancel'){
        $bookingId=(int)($payload['booking_id']??0);
        $stmt=$pdo->prepare('SELECT * FROM agent_scheduling_bookings WHERE id=? AND owner_user_id=? LIMIT 1');
        $stmt->execute([$bookingId,(int)$user['id']]);$booking=$stmt->fetch();
        if(!$booking||!in_array((string)$booking['status'],['pending','confirmed'],true))throw new RuntimeException('That appointment is no longer active.');
        if(!agent_scheduling_cancel_booking_v430($pdo,$bookingId,(int)$user['id']))throw new RuntimeException('That appointment could not be cancelled.');
        $result['answer']='Cancelled. '.(string)$booking['event_title'].' with '.(string)$booking['guest_name'].' is no longer on your schedule.';
        $result['actions'][]=['type'=>'open_url','label'=>'Open Scheduling','url'=>url('/scheduling.php#bookings')];
        agent_tool_log($user,'scheduling.cancel',(string)$pending['request_text'],'success',['booking_id'=>$bookingId,'agent_id'=>$agentId],$conversationId);
        return $result;
    }
    throw new RuntimeException('The pending scheduling action is no longer supported.');
}

function agent_scheduling_tools_booking_choices_v460(array $rows,string $timezone): string
{
    if(!$rows)return '';
    $lines=[];
    foreach(array_slice($rows,0,5) as $row){
        $lines[]='• #'.(int)$row['id'].' · '.(string)$row['guest_name'].' · '.(string)$row['event_title'].' · '.agent_scheduling_tools_slot_label_v460((string)$row['start_at_utc'],$timezone);
    }
    return implode("\n",$lines);
}

function agent_scheduling_tools_intent_v460(string $query,array $events=[]): string
{
    $q=mb_strtolower(trim($query));
    if($q==='')return '';
    $music=(bool)preg_match('/\b(?:venue|venues|gig|gigs|tour|touring|listener density|music booking|show booking|booking opportunit|live opportunit)\b/u',$q);
    if($music)return '';
    $scheduling=(bool)preg_match('/\b(?:calendar|calender|appointment|appointments|meeting|meetings|availability|available|free time|open time|open slot|time slot|schedule|scheduling|reschedule|re-schedule)\b/u',$q);
    if(!$scheduling&&preg_match('/\bbook\b/u',$q)){
        $scheduling=(bool)preg_match('/\b(?:today|tomorrow|monday|tuesday|wednesday|thursday|friday|saturday|sunday|at\s+\d|with\s+[\pL]|for\s+\d+\s*(?:min|hour))\b/u',$q);
    }
    if(!$scheduling){
        foreach($events as $event){
            $title=mb_strtolower(trim((string)($event['title']??'')));
            if($title!==''&&str_contains($q,$title)){$scheduling=true;break;}
        }
    }
    if(!$scheduling)return '';
    if(preg_match('/\b(?:cancel|delete|remove)\b.*\b(?:appointment|meeting|booking)\b|\b(?:appointment|meeting|booking)\b.*\b(?:cancel|delete|remove)\b/u',$q))return 'cancel';
    if(preg_match('/\b(?:reschedule|re-schedule|move|change)\b.*\b(?:appointment|meeting|booking|call)\b|\b(?:appointment|meeting|booking|call)\b.*\b(?:reschedule|move|change)\b/u',$q))return 'reschedule';
    if(preg_match('/\b(?:book|schedule|set up|make)\b.*\b(?:appointment|meeting|call|session|time)\b|\bbook\s+[\pL]/u',$q))return 'create';
    if(preg_match('/\b(?:what|show|list|do i have|what\'s|whats)\b.*\b(?:calendar|appointment|appointments|meeting|meetings|schedule)\b/u',$q))return 'list';
    if(preg_match('/\b(?:available|availability|free|open|slot|slots|when can|what times|what time)\b/u',$q))return 'availability';
    if(preg_match('/\b(?:calendar|appointments|meetings)\b/u',$q))return 'list';
    return '';
}

function agent_scheduling_tools_query_v460(string $query,array $user,int $conversationId=0): array
{
    $empty=agent_scheduling_tools_empty_v460();$pdo=db();
    if(!$pdo||!agent_scheduling_tools_ready_v460($pdo)||(int)($user['id']??0)<1)return $empty;
    if(!has_permission('account.access',$user)&&!has_permission('chat.access',$user))return $empty;

    $pending=agent_scheduling_tools_pending_v460($user,$conversationId);
    if($pending&&agent_scheduling_tools_reject_confirmation_v460($query)){
        agent_scheduling_tools_clear_pending_v460($user,$conversationId);
        $result=$empty;$result['handled']=true;$result['answer']='Okay — I did not make that scheduling change.';
        agent_tool_log($user,'scheduling.approval',$query,'denied',['operation'=>(string)$pending['operation']],$conversationId);
        return $result;
    }
    if($pending&&agent_scheduling_tools_confirmation_v460($query)){
        try{
            $result=agent_scheduling_tools_execute_pending_v460($pdo,$user,$conversationId,$pending);
            agent_scheduling_tools_clear_pending_v460($user,$conversationId);
            $result['approval']=['required'=>true,'approved'=>true,'risk'=>$pending['risk']??[],'version'=>'v4.60'];
            return $result;
        }catch(Throwable $e){
            agent_scheduling_tools_clear_pending_v460($user,$conversationId);
            $result=$empty;$result['handled']=true;$result['answer']='I could not complete that scheduling change: '.$e->getMessage();
            agent_tool_log($user,'scheduling.'.(string)$pending['operation'],$query,'failed',['error'=>$e->getMessage()],$conversationId);
            return $result;
        }
    }

    $schedule=agent_scheduling_tools_schedule_v460($pdo,$user);
    if(!$schedule)return $empty;
    $events=agent_scheduling_tools_events_v460($pdo,$user,$schedule);
    $intent=agent_scheduling_tools_intent_v460($query,$events);
    if($intent==='')return $empty;
    $agentId=agent_scheduling_tools_conversation_agent_id_v460($pdo,$user,$conversationId);
    $timezone=agent_scheduling_timezone_v430((string)$schedule['timezone']);
    $result=$empty;$result['handled']=true;
    $result['actions'][]=['type'=>'open_url','label'=>'Open Scheduling','url'=>url('/scheduling.php')];

    if($intent==='list'){
        $rows=agent_scheduling_tools_booking_rows_v460($pdo,$user,12,true);
        if(!$rows){$result['answer']='Your calendar is clear — there are no upcoming confirmed or pending appointments.';}
        else{
            $lines=[];foreach($rows as $row)$lines[]='• #'.(int)$row['id'].' · '.agent_scheduling_tools_slot_label_v460((string)$row['start_at_utc'],$timezone).' · '.(string)$row['event_title'].' with '.(string)$row['guest_name'];
            $result['answer']="Here are your upcoming appointments in {$timezone}:\n".implode("\n",$lines);
        }
        agent_tool_log($user,'scheduling.list',$query,'success',['count'=>count($rows),'agent_id'=>$agentId],$conversationId);
        return $result;
    }

    if($intent==='availability'){
        $event=agent_scheduling_tools_event_v460($pdo,$user,$events,$query);
        if(!$event){
            $choices=array_map(static fn(array $e):string=>'• '.(string)$e['title'].' · '.(int)$e['duration_minutes'].' minutes',$events);
            $result['answer']=$choices?"Which appointment type should I check?\n".implode("\n",$choices):'You do not have an active appointment type yet. Open Scheduling to create one.';
            return $result;
        }
        $date=agent_scheduling_tools_date_v460($query,$timezone);
        $days=[];$start=$date!==null?new DateTimeImmutable($date,new DateTimeZone($timezone)):new DateTimeImmutable('today',new DateTimeZone($timezone));
        $scanDays=$date!==null?1:min(14,max(1,(int)$event['booking_window_days']));
        for($i=0;$i<$scanDays&&count($days)<5;$i++){
            $d=$start->modify('+'.$i.' days')->format('Y-m-d');
            $slots=agent_scheduling_slots_for_date_v430($pdo,(int)$event['id'],$d,false);
            if($slots)$days[$d]=array_slice($slots,0,8);
        }
        if(!$days){$result['answer']=$date!==null?'I do not see an open '.(string)$event['title'].' slot on '.$date.'.':'I do not see an open '.(string)$event['title'].' slot in the next two weeks.';}
        else{
            $lines=[];foreach($days as $d=>$slots){$times=array_map(fn(array $s):string=>agent_scheduling_tools_slot_label_v460((string)$s['start_at_utc'],$timezone,'g:i A'),$slots);$lines[]='• '.(new DateTimeImmutable($d))->format('D, M j').' — '.implode(', ',$times);}
            $result['answer']='Open '.(string)$event['title']." times in {$timezone}:\n".implode("\n",$lines);
        }
        agent_tool_log($user,'scheduling.availability',$query,'success',['event_type_id'=>(int)$event['id'],'days'=>array_keys($days),'agent_id'=>$agentId],$conversationId);
        return $result;
    }

    if($intent==='create'){
        $event=agent_scheduling_tools_event_v460($pdo,$user,$events,$query);
        if(!$event){
            $choices=array_map(static fn(array $e):string=>'• '.(string)$e['title'].' · '.(int)$e['duration_minutes'].' minutes',$events);
            $result['answer']=$choices?"Which appointment type should I book?\n".implode("\n",$choices):'Create an appointment type in Scheduling before I can book it.';
            return $result;
        }
        $date=agent_scheduling_tools_date_v460($query,$timezone);
        if($date===null){$result['answer']='What date should I book the '.(string)$event['title'].'?';return $result;}
        $time=agent_scheduling_tools_time_request_v460($query);
        if(!$time){$result['answer']='What time should I book it? I’ll check that against your live availability.';return $result;}
        $slots=agent_scheduling_slots_for_date_v430($pdo,(int)$event['id'],$date,false);
        $picked=agent_scheduling_tools_pick_slot_v460($slots,$time);
        if(!empty($picked['ambiguous'])){$result['answer']='I found more than one matching time. Please say AM or PM.';return $result;}
        if(empty($picked['slot'])){
            $times=array_map(fn(array $s):string=>agent_scheduling_tools_slot_label_v460((string)$s['start_at_utc'],$timezone,'g:i A'),array_slice($slots,0,8));
            $result['answer']=$times?'That time is not open. Available times are: '.implode(', ',$times).'.':'There are no open slots for that appointment type on '.$date.'.';
            return $result;
        }
        $guest=agent_scheduling_tools_guest_name_v460($query);
        if($guest===''){$result['answer']='Who is this appointment with?';return $result;}
        $email=agent_scheduling_tools_email_v460($query);
        $slot=$picked['slot'];
        $summary=(string)$event['title'].' with '.$guest.' on '.agent_scheduling_tools_slot_label_v460((string)$slot['start_at_utc'],$timezone).($email!==''?' · '.$email:'');
        $prepared=agent_scheduling_tools_prepare_v460($user,$conversationId,$agentId,'create',[
            'event_type_id'=>(int)$event['id'],'start_at_utc'=>(string)$slot['start_at_utc'],'guest_name'=>$guest,'guest_email'=>$email,
        ],$summary,$query);
        $result['answer']="I’m ready to create this appointment:\n• {$summary}\n\nThis changes your calendar. Reply **Confirm booking** to approve it, or **Never mind** to stop.";
        $result['approval']=['required'=>true,'approved'=>false,'expires_at'=>(int)$prepared['expires_at'],'risk'=>$prepared['risk'],'version'=>'v4.60'];
        agent_tool_log($user,'scheduling.book',$query,'approval_required',['event_type_id'=>(int)$event['id'],'start_at_utc'=>(string)$slot['start_at_utc'],'agent_id'=>$agentId],$conversationId);
        return $result;
    }

    $found=agent_scheduling_tools_find_booking_v460($pdo,$user,$query);
    $booking=$found['booking'];
    if(!$booking){
        $choices=agent_scheduling_tools_booking_choices_v460((array)$found['matches'],$timezone);
        $result['answer']=$choices?"I found more than one possible appointment. Tell me the booking number or the guest name:\n{$choices}":'I could not find a matching active appointment on your calendar.';
        return $result;
    }

    if($intent==='cancel'){
        $summary=(string)$booking['event_title'].' with '.(string)$booking['guest_name'].' on '.agent_scheduling_tools_slot_label_v460((string)$booking['start_at_utc'],$timezone);
        $prepared=agent_scheduling_tools_prepare_v460($user,$conversationId,$agentId,'cancel',['booking_id'=>(int)$booking['id']],$summary,$query);
        $result['answer']="I’m ready to cancel:\n• {$summary}\n\nCancellation releases the time. Reply **Confirm cancellation** to approve it, or **Never mind** to keep it.";
        $result['approval']=['required'=>true,'approved'=>false,'expires_at'=>(int)$prepared['expires_at'],'risk'=>$prepared['risk'],'version'=>'v4.60'];
        agent_tool_log($user,'scheduling.cancel',$query,'approval_required',['booking_id'=>(int)$booking['id'],'agent_id'=>$agentId],$conversationId);
        return $result;
    }

    if($intent==='reschedule'){
        $event=agent_scheduling_event_type_v430($pdo,(int)$booking['event_type_id']);
        if(!$event||(int)$event['owner_user_id']!==(int)$user['id']){$result['answer']='That appointment type is no longer available.';return $result;}
        $originalDate=agent_scheduling_tools_slot_label_v460((string)$booking['start_at_utc'],$timezone,'Y-m-d');
        $date=agent_scheduling_tools_date_v460($query,$timezone,$originalDate);
        $time=agent_scheduling_tools_time_request_v460($query);
        if(!$time){$result['answer']='What new time should I move the appointment to?';return $result;}
        $slots=agent_scheduling_tools_slots_excluding_v460($pdo,$event,(string)$date,(int)$booking['id']);
        $picked=agent_scheduling_tools_pick_slot_v460($slots,$time);
        if(!empty($picked['ambiguous'])){$result['answer']='I found both AM and PM possibilities. Which one do you mean?';return $result;}
        if(empty($picked['slot'])){
            $times=array_map(fn(array $s):string=>agent_scheduling_tools_slot_label_v460((string)$s['start_at_utc'],$timezone,'g:i A'),array_slice($slots,0,8));
            $result['answer']=$times?'That new time is not open. Available times are: '.implode(', ',$times).'.':'There are no open times on '.(string)$date.'.';
            return $result;
        }
        $slot=$picked['slot'];
        $before=agent_scheduling_tools_slot_label_v460((string)$booking['start_at_utc'],$timezone);
        $after=agent_scheduling_tools_slot_label_v460((string)$slot['start_at_utc'],$timezone);
        $summary=(string)$booking['event_title'].' with '.(string)$booking['guest_name'].' · '.$before.' → '.$after;
        $prepared=agent_scheduling_tools_prepare_v460($user,$conversationId,$agentId,'reschedule',['booking_id'=>(int)$booking['id'],'start_at_utc'=>(string)$slot['start_at_utc']],$summary,$query);
        $result['answer']="I’m ready to reschedule:\n• {$summary}\n\nReply **Confirm reschedule** to approve it, or **Never mind** to stop.";
        $result['approval']=['required'=>true,'approved'=>false,'expires_at'=>(int)$prepared['expires_at'],'risk'=>$prepared['risk'],'version'=>'v4.60'];
        agent_tool_log($user,'scheduling.reschedule',$query,'approval_required',['booking_id'=>(int)$booking['id'],'start_at_utc'=>(string)$slot['start_at_utc'],'agent_id'=>$agentId],$conversationId);
        return $result;
    }

    return $empty;
}

function agent_scheduling_tools_profile_query_v460(PDO $pdo,string $query,array $profile,array $agent): ?string
{
    $ownerId=(int)($profile['user_id']??0);
    if($ownerId<1||!agent_scheduling_tools_ready_v460($pdo))return null;
    $schedule=agent_scheduling_public_schedule_v450($pdo,$ownerId);
    if(!$schedule)return null;
    $events=agent_scheduling_public_events_v450($pdo,(int)$schedule['id']);
    $intent=agent_scheduling_tools_intent_v460($query,$events);
    if(!in_array($intent,['availability','create','reschedule','cancel','list'],true))return null;
    $username=profile_username_normalize((string)($profile['username']??''));
    if($username==='')return null;
    $agentName=trim((string)($agent['display_name']??''))?:'the Profile Agent';
    $event=agent_scheduling_tools_event_v460($pdo,['id'=>$ownerId],$events,$query);
    $bookingUrl=agent_scheduling_public_booking_url_v450($username,$event?(string)$event['slug']:null);

    if($intent==='availability'&&$event){
        $timezone=agent_scheduling_timezone_v430((string)$schedule['timezone']);
        $date=agent_scheduling_tools_date_v460($query,$timezone);
        $start=$date!==null?new DateTimeImmutable($date,new DateTimeZone($timezone)):new DateTimeImmutable('today',new DateTimeZone($timezone));
        $lines=[];$days=$date!==null?1:7;
        for($i=0;$i<$days&&count($lines)<3;$i++){
            $d=$start->modify('+'.$i.' days')->format('Y-m-d');
            $slots=agent_scheduling_slots_for_date_v430($pdo,(int)$event['id'],$d,true);
            if(!$slots)continue;
            $times=array_map(fn(array $s):string=>agent_scheduling_tools_slot_label_v460((string)$s['start_at_utc'],$timezone,'g:i A'),array_slice($slots,0,5));
            $lines[]=(new DateTimeImmutable($d))->format('D, M j').' — '.implode(', ',$times);
        }
        if($lines)return $agentName." can check live availability. Here are the next openings in {$timezone}:\n• ".implode("\n• ",$lines)."\n\nUse Book a time on this profile to reserve one securely.";
        return 'I do not see an open time matching that request right now. Use Book a time on this profile to browse other dates.';
    }

    if($intent==='create'){
        return 'I can help you schedule with '.trim((string)($profile['display_name']??$username)).'. Use **Book a time** on this profile to choose a live slot and confirm your contact details securely.';
    }
    if(in_array($intent,['reschedule','cancel'],true)){
        return 'Use the private appointment-management link from your booking confirmation to '.($intent==='cancel'?'cancel':'reschedule').' securely. I won’t expose or guess another visitor’s booking from public chat.';
    }
    return 'Use **Book a time** on this profile to view the published appointment types and live availability.';
}
