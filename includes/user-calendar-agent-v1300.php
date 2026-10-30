<?php
declare(strict_types=1);
require_once __DIR__.'/agent-tool-receipts-v1.php';

/** Owner-scoped conversational Calendar tool. Voice and text share this path. */
const VP3_USER_CALENDAR_AGENT_V1300 = 'user-calendar-agent-v1300-20260912';

function user_calendar_agent_empty_v1300(): array
{
    return ['handled'=>false,'answer'=>'','stem_media'=>[],'media'=>[],'actions'=>[],'sources'=>[]];
}

function user_calendar_agent_pending_key_v1300(array $user,int $conversationId): string
{
    return (int)($user['id']??0).':'.max(0,$conversationId);
}

function user_calendar_agent_pending_v1300(array $user,int $conversationId): ?array
{
    $key=user_calendar_agent_pending_key_v1300($user,$conversationId);
    $row=$_SESSION['user_calendar_agent_v1300_pending'][$key]??null;
    if(!is_array($row))return null;
    if((int)($row['expires_at']??0)<time()){
        unset($_SESSION['user_calendar_agent_v1300_pending'][$key]);
        return null;
    }
    return $row;
}

function user_calendar_agent_set_pending_v1300(array $user,int $conversationId,array $pending): void
{
    if(!isset($_SESSION['user_calendar_agent_v1300_pending'])||!is_array($_SESSION['user_calendar_agent_v1300_pending']))$_SESSION['user_calendar_agent_v1300_pending']=[];
    // One conversational calendar/scheduling change at a time. A later plain
    // confirmation must never execute an older event change instead of booking.
    user_calendar_agent_reset_confirmations_v1340($user,$conversationId);
    $pending['mutation_id']??=bin2hex(random_bytes(16));
    $pending['expires_at']=time()+900;
    $_SESSION['user_calendar_agent_v1300_pending'][user_calendar_agent_pending_key_v1300($user,$conversationId)]=$pending;
}

function user_calendar_agent_clear_pending_v1300(array $user,int $conversationId): void
{
    unset($_SESSION['user_calendar_agent_v1300_pending'][user_calendar_agent_pending_key_v1300($user,$conversationId)]);
}

function user_calendar_agent_reset_confirmations_v1340(array $user,int $conversationId): void
{
    user_calendar_agent_clear_pending_v1300($user,$conversationId);
    if(function_exists('agent_scheduling_tools_clear_pending_v460'))agent_scheduling_tools_clear_pending_v460($user,$conversationId);
    if(function_exists('agent_team_scheduling_tools_clear_pending_v610'))agent_team_scheduling_tools_clear_pending_v610($user,$conversationId);
}

function user_calendar_agent_explicit_intent_v1300(string $query): bool
{
    $q=mb_strtolower($query);
    if(!preg_match('/\bcalendar\b/u',$q))return false;
    return (bool)preg_match('/\b(?:add|create|put|schedule|block|event|agenda|show|list|what|what\'s|whats|have|remove|delete|cancel|move|reschedule|change|update|enable|disable|mark)\b/u',$q);
}

function user_calendar_agent_create_intent_v1300(string $query): bool
{
    return (bool)preg_match('/\b(?:add|create|put|schedule|block)\b.*\bcalendar\b|\bcalendar\b.*\b(?:add|create|put|schedule|block|event)\b/iu',$query);
}

function user_calendar_agent_list_intent_v1300(string $query): bool
{
    return (bool)preg_match('/\b(?:show|list|what|what\'s|whats|agenda|have|on)\b.*\bcalendar\b|\bcalendar\b.*\b(?:show|list|what|agenda|today|tomorrow|week|month)\b/iu',$query);
}

function user_calendar_agent_confirmation_v1300(string $query): ?bool
{
    $q=mb_strtolower(trim($query));
    if(preg_match('/^(?:yes[,.! ]*|yep[,.! ]*|confirm(?: it)?[.! ]*|do it[.! ]*|add it[.! ]*|create it[.! ]*)$/u',$q))return true;
    if(preg_match('/^(?:no[,.! ]*|nope[,.! ]*|never mind[.! ]*|nevermind[.! ]*|cancel that[.! ]*|don\'t[.! ]*|do not[.! ]*)$/u',$q))return false;
    return null;
}

function user_calendar_agent_title_v1300(string $query): string
{
    if(preg_match('/["“]([^"”]{1,190})["”]/u',$query,$m))return trim($m[1]);
    $title=trim($query);
    $title=preg_replace('/^\s*(?:please\s+)?(?:add|create|put|schedule|block)\s+(?:an?\s+)?(?:event\s+)?/iu','',$title)??$title;
    $title=preg_replace('/\s+(?:to|on|in)\s+(?:my\s+|the\s+)?calendar\b.*$/iu','',$title)??$title;
    $title=preg_replace('/\s+\b(?:today|tomorrow|next\s+(?:monday|tuesday|wednesday|thursday|friday|saturday|sunday)|this\s+(?:monday|tuesday|wednesday|thursday|friday|saturday|sunday))\b.*$/iu','',$title)??$title;
    $title=preg_replace('/\s+\bon\s+(?:20\d{2}-\d{2}-\d{2}|january|february|march|april|may|june|july|august|september|october|november|december)\b.*$/iu','',$title)??$title;
    $title=preg_replace('/\s+\bat\s+\d{1,2}(?::[0-5]\d)?\s*(?:a\.?m\.?|p\.?m\.?)?.*$/iu','',$title)??$title;
    $title=trim(preg_replace('/\s+/u',' ',$title)??$title," \t\n\r\0\x0B,.-");
    if($title===''||mb_strtolower($title)==='calendar')$title='Calendar event';
    return mb_substr($title,0,190);
}

function user_calendar_agent_duration_v1300(string $query): int
{
    if(preg_match('/\bfor\s+(\d{1,3})\s*(?:minutes?|mins?)\b/i',$query,$m))return max(5,min(1440,(int)$m[1]));
    if(preg_match('/\bfor\s+(\d{1,2}(?:\.5)?)\s*(?:hours?|hrs?)\b/i',$query,$m))return max(5,min(1440,(int)round((float)$m[1]*60)));
    return 60;
}

function user_calendar_agent_label_v1300(array $event,string $timezone): string
{
    try{
        $start=(new DateTimeImmutable((string)$event['start_at_utc'],new DateTimeZone('UTC')))->setTimezone(new DateTimeZone($timezone));
        $end=(new DateTimeImmutable((string)$event['end_at_utc'],new DateTimeZone('UTC')))->setTimezone(new DateTimeZone($timezone));
        return $start->format('D, M j · g:i A').'–'.$end->format('g:i A T');
    }catch(Throwable $e){return (string)($event['start_at_utc']??'');}
}

/** Only explicit calendar changes enter this path; bookings use scheduling tools. */
function user_calendar_agent_options_v1340(string $query,array $existing=[]): array
{
    $options=user_calendar_options_v1340([], $existing);
    if(preg_match('/\b(?:weekly|every week|every (?:monday|tuesday|wednesday|thursday|friday|saturday|sunday))\b/i',$query))$options['recurrence']='weekly';
    if(preg_match('/\b(?:one[ -]time|does not repeat|stop repeating)\b/i',$query))$options['recurrence']='none';
    if(preg_match('/\b(?:include|enable|turn on) agent notifications\b|\b(?:make|mark)(?: it| this)?(?: an?)? priority\b/i',$query))$options['agent_notifications']=1;
    if(preg_match('/\b(?:exclude|disable|turn off|no) agent notifications\b/i',$query))$options['agent_notifications']=0;
    return $options;
}

function user_calendar_agent_manage_v1340(PDO $pdo,array $user,string $query,int $conversationId): ?array
{
    $operation=preg_match('/\b(?:remove|delete|cancel)\b/i',$query)?'cancel':(preg_match('/\b(?:move|reschedule|change|update|enable|disable|mark)\b/i',$query)?'update':'');
    if($operation==='')return null;
    $result=user_calendar_agent_empty_v1300();$result['handled']=true;$rows=[];
    if(preg_match('/\b(?:event|calendar)\s*#(\d+)\b/i',$query,$m)){
        $row=user_calendar_event_v1300($pdo,(int)$user['id'],(int)$m[1]);if($row&&$row['status']==='active')$rows[]=$row;
    }elseif(preg_match('/["“]([^"”]{1,190})["”]/u',$query,$m)){
        $s=$pdo->prepare("SELECT * FROM user_calendar_events WHERE owner_user_id=? AND status='active' AND title=? ORDER BY id");$s->execute([(int)$user['id'],trim($m[1])]);$rows=$s->fetchAll()?:[];
    }else{
        $result['answer']='Which calendar event? Use its event number or exact title in quotes, such as cancel calendar event #12.';return $result;
    }
    if(count($rows)!==1){$result['answer']=$rows?'More than one calendar event has that title. Use the event number to choose one.':'I could not find that active event in your calendar.';return $result;}
    $event=$rows[0];$id=(int)$event['id'];
    if(function_exists('video_meeting_schema_ready_v1800')&&video_meeting_schema_ready_v1800($pdo)&&video_meeting_for_calendar_event_v1800($pdo,$id)){
        $result['answer']='This event has a linked video meeting. Open its editor to keep the meeting and invitations synchronized.';
        $result['actions'][]=['type'=>'open_url','label'=>'Edit meeting event','url'=>url('/calendar-event.php?id='.$id)];return $result;
    }
    $input=array_merge($event,user_calendar_event_local_parts_v1300($event));
    $input['expected_revision']=section12_revision($event);
    if($operation==='update'){
        $date=agent_scheduling_tools_date_v460($query,(string)$event['timezone']);
        $time=agent_scheduling_tools_time_request_v460($query);
        if($time&&!empty($time['ambiguous'])){$result['answer']='Please include AM or PM for the new event time.';return $result;}
        if($date||$time){
            $zone=new DateTimeZone((string)$event['timezone']);
            $oldStart=user_calendar_validate_utc_v1300((string)$event['start_at_utc'])->setTimezone($zone);
            $oldEnd=user_calendar_validate_utc_v1300((string)$event['end_at_utc'])->setTimezone($zone);
            $startTime=$time?sprintf('%02d:%02d',$time['hour'],$time['minute']):$input['start_time'];
            $newStart=DateTimeImmutable::createFromFormat('!Y-m-d H:i',($date?:$input['date']).' '.$startTime,$zone);
            if(!$newStart||$newStart->format('Y-m-d H:i')!==($date?:$input['date']).' '.$startTime){$result['answer']='That local event time does not exist. Choose another time.';return $result;}
            $newEnd=$newStart->add($oldStart->diff($oldEnd));
            $input['date']=$newStart->format('Y-m-d');$input['start_time']=$startTime;
            $input['end_date']=(!empty($event['all_day'])?$newEnd->modify('-1 day'):$newEnd)->format('Y-m-d');$input['end_time']=$newEnd->format('H:i');
        }
        $input=array_merge($input,user_calendar_agent_options_v1340($query,$event));
        if(!$date&&!$time&&$input['recurrence']===($event['recurrence']??'none')&&(int)$input['agent_notifications']===(int)($event['agent_notifications']??0)){
            $result['answer']='What should change? Give a new date/time, One time or Every week, or enable/disable agent notifications.';return $result;
        }
        user_calendar_validate_options_v1340($pdo,user_calendar_options_v1340($input),user_calendar_local_range_v1300($input,(string)$event['timezone']));
    }
    $result['tool_receipt']=vp3_tool_receipt_v1('calendar.manage','prepare','clarification_required','confirmation_required');
    $pending=$input+['operation'=>$operation,'event_id'=>$id];
    user_calendar_agent_set_pending_v1300($user,$conversationId,$pending);
    $result['answer']=($operation==='cancel'?'Remove':'Update').' calendar event #'.$id.' “'.(string)$event['title'].'”'.(($event['recurrence']??'none')==='weekly'?' and its entire weekly series':'').'?'.($operation==='update'?' New schedule: '.$input['date'].' '.$input['start_time'].' · '.$input['timezone'].' · '.($input['recurrence']==='weekly'?'Every week':'One time').' · agent notifications '.(!empty($input['agent_notifications'])?'on':'off').'.':'').' Confirm?';
    return $result;
}

function user_calendar_agent_query_v1300(string $query,array $user,int $conversationId=0): array
{
    $empty=user_calendar_agent_empty_v1300();
    $pdo=db();
    if(!$pdo||!user_calendar_schema_ready_v1300($pdo)||(int)($user['id']??0)<1)return $empty;

    $pending=user_calendar_agent_pending_v1300($user,$conversationId);
    $confirmation=user_calendar_agent_confirmation_v1300($query);
    if($pending&&$confirmation!==null){
        $bookingPending=function_exists('agent_scheduling_tools_pending_v460')?agent_scheduling_tools_pending_v460($user,$conversationId):null;
        $teamPending=function_exists('agent_team_scheduling_tools_pending_v610')?agent_team_scheduling_tools_pending_v610($user,$conversationId):null;
        if($bookingPending||$teamPending){user_calendar_agent_clear_pending_v1300($user,$conversationId);return $empty;}
        $result=$empty;$result['handled']=true;
        if($confirmation===false){
            user_calendar_agent_clear_pending_v1300($user,$conversationId);
            $result['answer']='Okay — I did not change your calendar.';
            agent_tool_log($user,'calendar.event.create',$query,'cancelled',['pending_title'=>(string)($pending['title']??'')],$conversationId);
            return $result;
        }
        try{
            $operation=(string)($pending['operation']??'create');
            if($operation!=='create'){
                $id=(int)($pending['event_id']??0);
                $event=section12_owner_transaction($pdo,(int)$user['id'],static function()use($pdo,$user,$id,$pending,$operation):array{
                    if(function_exists('video_meeting_schema_ready_v1800')&&video_meeting_schema_ready_v1800($pdo)&&video_meeting_for_calendar_event_v1800($pdo,$id))throw new RuntimeException('Open this linked video meeting in its event editor.');
                    if($operation==='cancel'){
                        if(!user_calendar_cancel_event_v1300($pdo,$user,$id,(string)$pending['expected_revision']))throw new RuntimeException('Calendar event could not be removed.');return $pending;
                    }
                    return user_calendar_update_event_v1300($pdo,$user,$id,$pending);
                });
                user_calendar_agent_clear_pending_v1300($user,$conversationId);
                $result['answer']=($operation==='cancel'?'Removed':'Updated').' calendar event #'.$id.' “'.(string)$event['title'].'”.';
                $result['actions'][]=['type'=>'open_url','label'=>'Open Calendar','url'=>url('/calendar.php')];
                $result['tool_receipt']=vp3_tool_receipt_v1('calendar.manage',$operation,'completed','',$id);
                agent_tool_log($user,'calendar.event.'.$operation,$query,'success',['event_id'=>$id],$conversationId);return $result;
            }
            $agentId=max(0,(int)($pending['agent_id']??0));
            $source='agent';
            $event=user_calendar_create_local_event_v1300($pdo,$user,$pending,$source,$agentId>0?$agentId:null,!empty($pending['mutation_id'])?'chat:'.(string)$pending['mutation_id']:'');
            user_calendar_agent_clear_pending_v1300($user,$conversationId);
            $timezone=user_calendar_timezone_v1300((string)$event['timezone']);
            $result['answer']='Added “'.(string)$event['title'].'” to your calendar for '.user_calendar_agent_label_v1300($event,$timezone).'.';
            $result['actions'][]=['type'=>'open_url','label'=>'Open Calendar','url'=>url('/calendar.php')];
            $result['sources'][]=['source'=>'calendar:event:'.(int)$event['id'],'title'=>(string)$event['title']];
            $result['tool_receipt']=vp3_tool_receipt_v1('calendar.manage','create','completed','',(int)$event['id']);
            agent_tool_log($user,'calendar.event.create',$query,'success',['event_id'=>(int)$event['id'],'source'=>$source,'agent_id'=>$agentId],$conversationId);
            return $result;
        }catch(Throwable $e){
            user_calendar_agent_clear_pending_v1300($user,$conversationId);
            $result['answer']='I could not add that event: '.$e->getMessage();
            $result['tool_receipt']=vp3_tool_receipt_v1('calendar.manage',(string)($operation??'create'),'failed','execution_failed');
            agent_tool_log($user,'calendar.event.create',$query,'error',['error'=>$e->getMessage()],$conversationId);
            return $result;
        }
    }

    if(!user_calendar_agent_explicit_intent_v1300($query))return $empty;
    if(preg_match('/\b(?:add|create|put|schedule|block|remove|delete|cancel|move|reschedule|change|update|enable|disable|mark)\b/iu',$query))user_calendar_agent_reset_confirmations_v1340($user,$conversationId);
    $result=$empty;$result['handled']=true;
    $timezone=user_calendar_default_timezone_v1300($pdo,$user);
    try{$management=user_calendar_agent_manage_v1340($pdo,$user,$query,$conversationId);if($management!==null)return $management;}
    catch(Throwable $e){$result['answer']='I could not prepare that calendar change: '.$e->getMessage();return $result;}

    if(user_calendar_agent_list_intent_v1300($query)&&!user_calendar_agent_create_intent_v1300($query)){
        $date=agent_scheduling_tools_date_v460($query,$timezone,null);
        $tz=new DateTimeZone($timezone);
        $start=$date?DateTimeImmutable::createFromFormat('!Y-m-d',$date,$tz):new DateTimeImmutable('today',$tz);
        if(!$start)$start=new DateTimeImmutable('today',$tz);
        $days=$date?1:7;
        if(preg_match('/\bmonth\b/i',$query))$days=31;
        elseif(preg_match('/\bweek\b/i',$query))$days=7;
        $fromUtc=$start->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        $toUtc=$start->modify('+'.$days.' days')->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        $events=user_calendar_events_v1300($pdo,$user,$fromUtc,$toUtc);
        if(!$events){
            $result['answer']=$date?'Your calendar is open on '.$start->format('l, F j').'.':'You have no calendar events in the next seven days.';
        }else{
            $lines=[];
            foreach(array_slice($events,0,12) as $event){
                $label=user_calendar_agent_label_v1300($event,$timezone);
                $kind=(string)$event['kind']==='booking'?'Booking':'Event';
                $lines[]='• '.$label.' — '.(string)$event['title'].' ('.$kind.((string)$event['kind']==='event'?' #'.(int)$event['id']:'').(($event['recurrence']??'none')==='weekly'?' · weekly':'').(!empty($event['agent_notifications'])?' · agent priority':'').')';
            }
            $result['answer']=($date?'Here’s your calendar for '.$start->format('l, F j').':':'Here’s your upcoming calendar:')."\n".implode("\n",$lines);
        }
        $result['actions'][]=['type'=>'open_url','label'=>'Open Calendar','url'=>url('/calendar.php')];
        $result['tool_receipt']=vp3_tool_receipt_v1('calendar.read','list',$events?'read_complete':'no_matches','',0,count($events));
        agent_tool_log($user,'calendar.event.list',$query,'success',['count'=>count($events),'days'=>$days],$conversationId);
        return $result;
    }

    if(user_calendar_agent_create_intent_v1300($query)){
        $date=agent_scheduling_tools_date_v460($query,$timezone,null);
        $time=agent_scheduling_tools_time_request_v460($query);
        if(!$date){
            $result['answer']='What date should I put this event on? You can say something like “tomorrow,” “next Tuesday,” or “2026-09-18.”';
            return $result;
        }
        if(!$time||!empty($time['ambiguous'])){
            $result['answer']='What time should the event start? Please include AM or PM, such as “2 PM.”';
            return $result;
        }
        $title=user_calendar_agent_title_v1300($query);
        $startTime=sprintf('%02d:%02d',(int)$time['hour'],(int)$time['minute']);
        $duration=user_calendar_agent_duration_v1300($query);
        $agentId=agent_scheduling_tools_conversation_agent_id_v460($pdo,$user,$conversationId);
        $pending=['title'=>$title,'date'=>$date,'start_time'=>$startTime,'duration_minutes'=>$duration,'timezone'=>$timezone,'all_day'=>false,'location'=>'','description'=>'','agent_id'=>$agentId]+user_calendar_agent_options_v1340($query);
        user_calendar_agent_set_pending_v1300($user,$conversationId,$pending);
        $start=DateTimeImmutable::createFromFormat('!Y-m-d H:i',$date.' '.$startTime,new DateTimeZone($timezone));
        $when=$start?$start->format('D, M j · g:i A T'):$date.' '.$startTime;
        $result['answer']='I can add “'.$title.'” to your calendar for '.$when.' ('.$duration.' minutes).'.($pending['recurrence']==='weekly'?' Repeats every week.':'').(!empty($pending['agent_notifications'])?' Agent notifications on.':'').' Confirm?';
        $result['tool_receipt']=vp3_tool_receipt_v1('calendar.manage','prepare','clarification_required','confirmation_required');
        agent_tool_log($user,'calendar.event.prepare',$query,'pending',['title'=>$title,'date'=>$date,'start_time'=>$startTime,'duration_minutes'=>$duration,'agent_id'=>$agentId],$conversationId);
        return $result;
    }

    $result['answer']='I can show your calendar or add a dated event. Try “add dentist to my calendar next Tuesday at 2 PM.”';
    return $result;
}
