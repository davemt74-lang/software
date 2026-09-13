<?php
declare(strict_types=1);

/**
 * VP3 Calendar Schedule Awareness v13.30
 *
 * Read-only intelligence projected from the canonical User Calendar. This layer
 * never owns booking lifecycle state, never writes calendar rows, and never
 * substitutes for scheduling availability/conflict authority.
 */
const VP3_CALENDAR_SCHEDULE_AWARENESS_V1330 = 'calendar-schedule-awareness-v1330-20260912';
const VP3_CALENDAR_SCHEDULE_AWARENESS_MAX_HORIZON_DAYS_V1330 = 14;
const VP3_CALENDAR_SCHEDULE_AWARENESS_MAX_EVENTS_V1330 = 160;

function calendar_schedule_awareness_empty_v1330(int $horizonDays = 7): array
{
    return [
        'build'=>VP3_CALENDAR_SCHEDULE_AWARENESS_V1330,
        'available'=>false,
        'read_only'=>true,
        'availability_authority'=>false,
        'timezone'=>'UTC',
        'generated_at'=>gmdate('c'),
        'horizon_days'=>max(1,min(VP3_CALENDAR_SCHEDULE_AWARENESS_MAX_HORIZON_DAYS_V1330,$horizonDays)),
        'current'=>null,
        'next'=>null,
        'starts_soon'=>false,
        'today'=>['date'=>'','count'=>0,'remaining_count'=>0],
        'upcoming'=>[],
        'conflicts'=>[],
        'open_gaps_today'=>[],
    ];
}

function calendar_schedule_awareness_event_v1330(array $event, DateTimeImmutable $nowUtc, DateTimeZone $timezone): array
{
    try {
        $start=(new DateTimeImmutable((string)($event['start_at_utc']??''),new DateTimeZone('UTC')))->setTimezone($timezone);
        $end=(new DateTimeImmutable((string)($event['end_at_utc']??''),new DateTimeZone('UTC')))->setTimezone($timezone);
    } catch (Throwable $e) {
        return [];
    }
    $nowLocal=$nowUtc->setTimezone($timezone);
    $startsIn=(int)floor(($start->getTimestamp()-$nowLocal->getTimestamp())/60);
    $kind=(string)($event['kind']??'event');
    $id=max(0,(int)($event['id']??0));
    return [
        'ref'=>$kind.':'.$id,
        'kind'=>$kind,
        'id'=>$id,
        'title'=>mb_strimwidth(trim((string)($event['title']??'Scheduled item')),0,190,'…'),
        'source'=>mb_strimwidth(trim((string)($event['source']??$kind)),0,48,'…'),
        'status'=>mb_strimwidth(trim((string)($event['status']??'active')),0,32,'…'),
        'all_day'=>!empty($event['all_day']),
        'start_local'=>$start->format('Y-m-d\TH:i:sP'),
        'end_local'=>$end->format('Y-m-d\TH:i:sP'),
        'starts_in_minutes'=>$startsIn,
        'duration_minutes'=>max(0,(int)ceil(($end->getTimestamp()-$start->getTimestamp())/60)),
    ];
}

function calendar_schedule_awareness_open_gaps_v1330(array $events, DateTimeImmutable $nowLocal): array
{
    $dayEnd=$nowLocal->setTime(23,59,59);
    if($nowLocal >= $dayEnd)return [];
    $cursor=$nowLocal;
    $gaps=[];
    foreach($events as $event){
        try {
            $start=new DateTimeImmutable((string)$event['start_local']);
            $end=new DateTimeImmutable((string)$event['end_local']);
        } catch (Throwable $e) { continue; }
        if($end <= $cursor || $start > $dayEnd)continue;
        if($start > $cursor){
            $minutes=(int)floor(($start->getTimestamp()-$cursor->getTimestamp())/60);
            if($minutes>=30)$gaps[]=['start_local'=>$cursor->format('Y-m-d\TH:i:sP'),'end_local'=>$start->format('Y-m-d\TH:i:sP'),'minutes'=>$minutes,'planning_only'=>true];
        }
        if($end > $cursor)$cursor=$end;
        if($cursor >= $dayEnd)break;
    }
    if($cursor < $dayEnd){
        $minutes=(int)floor(($dayEnd->getTimestamp()-$cursor->getTimestamp())/60);
        if($minutes>=30)$gaps[]=['start_local'=>$cursor->format('Y-m-d\TH:i:sP'),'end_local'=>$dayEnd->format('Y-m-d\TH:i:sP'),'minutes'=>$minutes,'planning_only'=>true];
    }
    return array_slice($gaps,0,6);
}

function calendar_schedule_awareness_conflicts_v1330(array $events): array
{
    $conflicts=[];
    $count=count($events);
    for($i=0;$i<$count;$i++){
        $left=$events[$i];
        try {$leftEnd=new DateTimeImmutable((string)$left['end_local']);} catch (Throwable $e) { continue; }
        for($j=$i+1;$j<$count;$j++){
            $right=$events[$j];
            try {
                $rightStart=new DateTimeImmutable((string)$right['start_local']);
                $rightEnd=new DateTimeImmutable((string)$right['end_local']);
            } catch (Throwable $e) { continue; }
            if($rightStart >= $leftEnd)break;
            $overlapEnd=$leftEnd < $rightEnd ? $leftEnd : $rightEnd;
            $minutes=(int)floor(($overlapEnd->getTimestamp()-$rightStart->getTimestamp())/60);
            if($minutes<=0)continue;
            $conflicts[]=[
                'left_ref'=>(string)$left['ref'],'left_title'=>(string)$left['title'],
                'right_ref'=>(string)$right['ref'],'right_title'=>(string)$right['title'],
                'overlap_minutes'=>$minutes,
            ];
            if(count($conflicts)>=6)return $conflicts;
        }
    }
    return $conflicts;
}

function calendar_schedule_awareness_snapshot_v1330(PDO $pdo, array $user, ?DateTimeImmutable $now = null, int $horizonDays = 7): array
{
    $horizonDays=max(1,min(VP3_CALENDAR_SCHEDULE_AWARENESS_MAX_HORIZON_DAYS_V1330,$horizonDays));
    $empty=calendar_schedule_awareness_empty_v1330($horizonDays);
    $ownerUserId=(int)($user['id']??0);
    if($ownerUserId<1 || !function_exists('user_calendar_events_v1300') || !user_calendar_schema_ready_v1300($pdo))return $empty;

    $timezoneName=user_calendar_default_timezone_v1300($pdo,$user);
    $timezone=new DateTimeZone(user_calendar_timezone_v1300($timezoneName,'UTC'));
    $utc=new DateTimeZone('UTC');
    $nowUtc=($now??new DateTimeImmutable('now',$utc))->setTimezone($utc);
    $nowLocal=$nowUtc->setTimezone($timezone);
    $todayStart=$nowLocal->setTime(0,0,0);
    $rangeStart=$todayStart->setTimezone($utc);
    $rangeEnd=$nowLocal->modify('+'.$horizonDays.' days')->setTime(23,59,59)->setTimezone($utc);

    try {
        $canonical=user_calendar_events_v1300($pdo,$user,$rangeStart->format('Y-m-d H:i:s'),$rangeEnd->format('Y-m-d H:i:s'));
    } catch (Throwable $e) {
        return $empty;
    }
    $canonical=array_slice($canonical,0,VP3_CALENDAR_SCHEDULE_AWARENESS_MAX_EVENTS_V1330);
    $events=[];
    foreach($canonical as $event){
        if(!is_array($event))continue;
        $safe=calendar_schedule_awareness_event_v1330($event,$nowUtc,$timezone);
        if($safe)$events[]=$safe;
    }
    usort($events,static fn(array $a,array $b):int=>strcmp((string)$a['start_local'],(string)$b['start_local']));

    $current=null;$next=null;$today=[];$remainingToday=[];$upcoming=[];
    $todayDate=$nowLocal->format('Y-m-d');
    foreach($events as $event){
        $start=new DateTimeImmutable((string)$event['start_local']);
        $end=new DateTimeImmutable((string)$event['end_local']);
        $isToday=$start->format('Y-m-d')===$todayDate || ($start < $todayStart && $end > $todayStart);
        if($isToday){
            $today[]=$event;
            if($end > $nowLocal)$remainingToday[]=$event;
        }
        if($start <= $nowLocal && $end > $nowLocal && $current===null)$current=$event;
        if($start > $nowLocal && $next===null)$next=$event;
        if($end > $nowLocal && count($upcoming)<10)$upcoming[]=$event;
    }

    $conflictPool=array_values(array_filter($events,static function(array $event)use($nowLocal):bool{
        try { return new DateTimeImmutable((string)$event['end_local']) > $nowLocal; } catch (Throwable $e) { return false; }
    }));
    $todayFuture=array_values(array_filter($today,static function(array $event)use($nowLocal):bool{
        try { return new DateTimeImmutable((string)$event['end_local']) > $nowLocal; } catch (Throwable $e) { return false; }
    }));
    $startsSoon=$next!==null && !$next['all_day'] && (int)$next['starts_in_minutes']>=0 && (int)$next['starts_in_minutes']<=30;

    return [
        'build'=>VP3_CALENDAR_SCHEDULE_AWARENESS_V1330,
        'available'=>true,
        'read_only'=>true,
        'availability_authority'=>false,
        'timezone'=>$timezone->getName(),
        'generated_at'=>$nowUtc->format('c'),
        'horizon_days'=>$horizonDays,
        'current'=>$current,
        'next'=>$next,
        'starts_soon'=>$startsSoon,
        'today'=>['date'=>$todayDate,'count'=>count($today),'remaining_count'=>count($remainingToday)],
        'upcoming'=>$upcoming,
        'conflicts'=>calendar_schedule_awareness_conflicts_v1330($conflictPool),
        'open_gaps_today'=>calendar_schedule_awareness_open_gaps_v1330($todayFuture,$nowLocal),
    ];
}

function calendar_schedule_awareness_candidates_v1330(array $snapshot): array
{
    if(empty($snapshot['available']))return [];
    $candidates=[];
    foreach(array_slice((array)($snapshot['conflicts']??[]),0,3) as $conflict){
        if(!is_array($conflict))continue;
        $left=trim((string)($conflict['left_title']??'Scheduled item'));
        $right=trim((string)($conflict['right_title']??'Scheduled item'));
        $minutes=max(1,(int)($conflict['overlap_minutes']??0));
        $key='calendar-conflict:'.sha1((string)($conflict['left_ref']??'').'|'.(string)($conflict['right_ref']??''));
        $candidates[]=[
            'hash'=>sha1('calendar|'.$key),'key'=>$key,'title'=>'Upcoming calendar conflict: '.$left.' + '.$right,
            'prompt'=>'Review this calendar overlap and help me decide whether either commitment should change.',
            'reason'=>'Upcoming calendar conflict: '.$left.' overlaps '.$right.' by about '.$minutes.' minutes.',
            'priority'=>190,'score'=>0.95,'source'=>'calendar_conflict','url'=>'/calendar.php','created_at'=>(string)($snapshot['generated_at']??gmdate('c')),
        ];
    }
    $next=is_array($snapshot['next']??null)?$snapshot['next']:null;
    if(!empty($snapshot['starts_soon']) && $next){
        $minutes=max(0,(int)($next['starts_in_minutes']??0));
        $title=trim((string)($next['title']??'Upcoming commitment'));
        $key='calendar-starting:'.(string)($next['ref']??sha1($title));
        $candidates[]=[
            'hash'=>sha1('calendar|'.$key),'key'=>$key,'title'=>'Upcoming commitment starting soon: '.$title,
            'prompt'=>'Help me prepare for my next scheduled commitment.',
            'reason'=>'Upcoming commitment '.$title.' starts in about '.$minutes.' minute'.($minutes===1?'':'s').'.',
            'priority'=>168,'score'=>0.84,'source'=>'calendar_upcoming','url'=>'/calendar.php','created_at'=>(string)($snapshot['generated_at']??gmdate('c')),
        ];
    }
    return array_slice($candidates,0,4);
}