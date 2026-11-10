<?php
declare(strict_types=1);

require_once __DIR__.'/agent-calendar-prep-v1.php';
require_once __DIR__.'/user-calendar-v1300.php';
require_once __DIR__.'/video-meetings-v1800.php';
require_once __DIR__.'/video-meetings-manual-v1830.php';
require_once __DIR__.'/video-meetings-agenda-v18140.php';

/**
 * Private owner Chat adapter for existing meeting controls. The caller owns the
 * saved-turn review, confirmation and durable execution receipt. This adapter
 * does not confer approval, depend on Initiative being enabled, send notices,
 * project to provider calendars, or execute model-supplied SQL.
 */
function vp3_meeting_management_fields_v1(array $input,array $allowed): void
{
    if (array_is_list($input)||array_diff(array_keys($input),$allowed)) {
        throw new InvalidArgumentException('Unsupported meeting action field. Invitations and change notices must use the meeting editor.');
    }
}

function vp3_meeting_management_text_v1(mixed $input,int $limit,string $label): string
{
    if (!is_string($input)||str_contains($input,"\0")) throw new InvalidArgumentException('Enter '.$label.'.');
    $text=trim(preg_replace('/\s+/u',' ',$input)??'');
    if ($text===''||mb_strlen($text)>$limit) throw new InvalidArgumentException('Enter '.$label.' up to '.$limit.' characters.');
    return $text;
}

/** Current organizer authority and a canonical, one-time Calendar meeting. */
function vp3_meeting_management_target_v1(PDO $pdo,array $user,int $agentId,int $conversationId,int $meetingId,
    ?callable $authority=null,?DateTimeImmutable $now=null): array
{
    [$live,$principal]=vp3_calendar_prep_scope_v1($pdo,$user,$agentId,$conversationId,$authority);
    if (!video_meeting_schema_ready_v1800($pdo)||!video_meeting_manual_schema_ready_v1830($pdo)) {
        throw new RuntimeException('Meeting controls need the current database upgrade.',503);
    }
    // The owner lock is also used by the native Calendar editor. A separate
    // meeting lock serializes presence/room lifecycle changes during apply.
    if ($pdo->inTransaction()) {
        section12_lock_owner($pdo,(int)$live['id']);
        $lock=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?' FOR UPDATE':'';
        $q=$pdo->prepare('SELECT id FROM video_meetings WHERE id=?'.$lock);$q->execute([$meetingId]);
    }
    $meeting=video_meeting_row_v1800($pdo,$meetingId);
    if (!$meeting||(int)$meeting['owner_user_id']!==(int)$live['id']
        ||(int)($meeting['organizer_agent_id']??0)!==$agentId) {
        throw new RuntimeException('This meeting is unavailable to the current Agent.',403);
    }
    if ((int)($meeting['booking_id']??0)>0) {
        throw new RuntimeException('Manage this appointment through Scheduling so its booking lifecycle stays consistent.',409);
    }
    if (!in_array($meeting['status'],['scheduled','ready'],true)) {
        throw new RuntimeException('Only an upcoming meeting can be changed here.',409);
    }
    $event=user_calendar_event_v1300($pdo,(int)$live['id'],(int)($meeting['calendar_event_id']??0));
    if (!$event||$event['status']!=='active'||($event['recurrence']??'none')!=='none'||!empty($event['all_day'])
        ||$meeting['start_at_utc']!==$event['start_at_utc']||$meeting['end_at_utc']!==$event['end_at_utc']
        ||(string)($meeting['timezone']??'UTC')!==(string)$event['timezone']) {
        throw new RuntimeException('Use the meeting editor to repair or change this Calendar link.',409);
    }
    $now=($now??new DateTimeImmutable('now',new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('UTC'));
    if (user_calendar_validate_utc_v1300((string)$meeting['start_at_utc'])<=$now) {
        throw new RuntimeException('This meeting has already started. Use its meeting controls.',409);
    }
    if ($pdo->inTransaction()) {
        $lock=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?' FOR UPDATE':'';
        $q=$pdo->prepare('SELECT id FROM video_meeting_participants WHERE meeting_id=? ORDER BY id'.$lock);$q->execute([$meetingId]);
    }
    $participants=video_meeting_participants_v1800($pdo,$meetingId);$organizer=false;$identities=[];
    foreach ($participants as $participant) {
        $identities[]=[(int)$participant['id'],(int)($participant['user_id']??0),(string)$participant['role'],
            (string)$participant['invitation_status'],(int)($participant['calendar_event_id']??0)];
        if ((int)($participant['user_id']??0)===(int)$live['id']&&$participant['role']==='organizer'
            &&!in_array($participant['invitation_status'],['revoked','declined','cancelled'],true)) $organizer=true;
    }
    if (!$organizer) throw new RuntimeException('Current organizer access is required.',403);
    return ['user'=>$live,'principal'=>$principal,'meeting'=>$meeting,'event'=>$event,'participants'=>$participants,
        'revision'=>section12_revision([$meeting,$event,$identities])];
}

/** Normalize concrete values before they are displayed in the Chat review. */
function vp3_meeting_management_prepare_v1(PDO $pdo,array $user,int $agentId,int $conversationId,array $input,
    ?callable $authority=null,?DateTimeImmutable $now=null): array
{
    $action=$input['action']??null;
    if (!is_string($action)||!in_array($action,['agenda_add','reschedule','cancel'],true)) {
        throw new InvalidArgumentException('Choose an agenda addition, meeting time change or cancellation.');
    }
    $allowed=match($action) {
        'agenda_add'=>['action','meeting_id','item_text','item_type','priority'],
        'reschedule'=>['action','meeting_id','date','start_time','end_date','end_time','timezone'],
        default=>['action','meeting_id'],
    };
    vp3_meeting_management_fields_v1($input,$allowed);
    if (!is_int($input['meeting_id']??null)||$input['meeting_id']<1) throw new InvalidArgumentException('Choose an exact meeting.');
    $target=vp3_meeting_management_target_v1($pdo,$user,$agentId,$conversationId,$input['meeting_id'],$authority,$now);
    $meeting=$target['meeting'];$event=$target['event'];$parameters=[];
    $review=['meeting_id'=>(int)$meeting['id'],'calendar_event_id'=>(int)$event['id'],'meeting_title'=>(string)$meeting['title'],
        'current_start_at_utc'=>(string)$meeting['start_at_utc'],'current_end_at_utc'=>(string)$meeting['end_at_utc'],
        'timezone'=>(string)$event['timezone'],'outbound_notices'=>false,
        'notice'=>'Updates the VP3 meeting and linked VP3 calendars. No email or external-calendar notice will be sent.'];
    if ($action==='agenda_add') {
        if (!video_meeting_agenda_schema_ready_v18140($pdo)) throw new RuntimeException('Meeting Agenda storage is unavailable.',503);
        $text=vp3_meeting_management_text_v1($input['item_text']??null,1500,'an agenda item');
        // The native kernel measures display width; reject instead of silently
        // truncating text that the owner has reviewed.
        if (video_meeting_agenda_text_v18140($text)!==$text) throw new InvalidArgumentException('The agenda item is too long.');
        $type=$input['item_type']??'discussion';$priority=$input['priority']??'normal';
        if (!is_string($type)||!in_array($type,['discussion','question','decision','follow_up'],true)
            ||!is_string($priority)||!in_array($priority,['low','normal','high'],true)) throw new InvalidArgumentException('Choose a valid agenda type and priority.');
        $parameters=['item_text'=>$text,'item_type'=>$type,'priority'=>$priority];
        $review+=['title'=>'Add agenda item','item_text'=>$text,'item_type'=>$type,'priority'=>$priority];
        $review['notice']='Adds the reviewed text to this meeting agenda. No task, reminder, email or external action will run.';
        $target['revision']=section12_revision([$target['revision'],video_meeting_agenda_items_v18140($pdo,$meeting,(int)$target['user']['id'])]);
    } elseif ($action==='reschedule') {
        foreach (['date','start_time','end_date','end_time','timezone'] as $field) {
            if (!is_string($input[$field]??null)||trim($input[$field])==='') throw new InvalidArgumentException('Confirm the meeting date, start, end and timezone.');
            $parameters[$field]=trim($input[$field]);
        }
        try { new DateTimeZone($parameters['timezone']); }
        catch (Throwable $e) { throw new InvalidArgumentException('Choose a valid meeting timezone.'); }
        $range=user_calendar_local_range_v1300($parameters,$parameters['timezone']);
        video_meeting_normalize_times_v1800($range['start_at_utc'],$range['end_at_utc']);
        // A repeated local time at the autumn DST change has two possible UTC
        // instants. Do not select one silently from a conversational request.
        foreach (['start','end'] as $part) {
            $date=$parameters[$part==='start'?'date':'end_date'];$time=$parameters[$part.'_time'];
            vp3_meeting_management_unambiguous_time_v1($date,$time,$parameters['timezone']);
        }
        $clock=($now??new DateTimeImmutable('now',new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('UTC'));
        if (user_calendar_validate_utc_v1300($range['start_at_utc'])<=$clock) throw new InvalidArgumentException('Choose a future meeting time.');
        $parameters+=['start_at_utc'=>$range['start_at_utc'],'end_at_utc'=>$range['end_at_utc']];
        $review+=['title'=>'Change meeting time','new_start_at_utc'=>$range['start_at_utc'],'new_end_at_utc'=>$range['end_at_utc'],
            'new_timezone'=>$range['timezone'],'local_start'=>$parameters['date'].' '.$parameters['start_time'],
            'local_end'=>$parameters['end_date'].' '.$parameters['end_time']];
    } else $review+=['title'=>'Cancel meeting'];
    $plan=['version'=>1,'owner_user_id'=>(int)$target['user']['id'],'agent_id'=>$agentId,'conversation_id'=>$conversationId,
        'action'=>$action,'meeting_id'=>(int)$meeting['id'],'calendar_event_id'=>(int)$event['id'],
        'target_revision'=>$target['revision'],'parameters'=>$parameters,'review'=>$review];
    $details=[['label'=>'Meeting','value'=>(string)$meeting['title'].' (#'.(int)$meeting['id'].')']];
    if ($action==='agenda_add') {
        $details[]=['label'=>'Agenda item','value'=>$parameters['item_text']];
        $details[]=['label'=>'Type / priority','value'=>$parameters['item_type'].' / '.$parameters['priority']];
    } elseif ($action==='reschedule') {
        $details[]=['label'=>'From (UTC)','value'=>$meeting['start_at_utc'].' – '.$meeting['end_at_utc']];
        $details[]=['label'=>'To','value'=>$review['local_start'].' – '.$review['local_end'].' '.$parameters['timezone']];
        $details[]=['label'=>'To (UTC)','value'=>$parameters['start_at_utc'].' – '.$parameters['end_at_utc']];
    } else $details[]=['label'=>'Scheduled time (UTC)','value'=>$meeting['start_at_utc'].' – '.$meeting['end_at_utc']];
    $details[]=['label'=>'Effect','value'=>$review['notice']];
    $plan+=['label'=>$review['title'],'summary'=>$review['notice'],'details'=>$details];
    $plan['plan_hash']=section12_revision($plan);
    return $plan;
}

function vp3_meeting_management_unambiguous_time_v1(string $date,string $time,string $timezone): void
{
    $local=DateTimeImmutable::createFromFormat('!Y-m-d H:i',$date.' '.$time,new DateTimeZone('UTC'));
    if (!$local) throw new InvalidArgumentException('Choose a valid local meeting time.');
    $wall=$local->getTimestamp();$zone=new DateTimeZone($timezone);$matches=[];
    foreach ($zone->getTransitions($wall-172800,$wall+172800)?:[] as $transition) {
        $candidate=$wall-(int)$transition['offset'];
        if ((new DateTimeImmutable('@'.$candidate))->setTimezone($zone)->format('Y-m-d H:i')===$date.' '.$time) $matches[$candidate]=true;
    }
    if (count($matches)>1) throw new InvalidArgumentException('That local time occurs twice during the clock change. Choose an unambiguous time in the meeting editor.');
}

/**
 * Apply only within the caller's durable review/receipt transaction. All local
 * projections and readback succeed before commit; no after-commit sender exists.
 */
function vp3_meeting_management_apply_v1(PDO $pdo,array $user,int $agentId,int $conversationId,array $plan,
    ?callable $authority=null,?DateTimeImmutable $now=null): array
{
    if (!$pdo->inTransaction()) throw new LogicException('Meeting actions require the reviewed execution transaction.');
    if (($plan['version']??null)!==1||($plan['owner_user_id']??null)!==(int)($user['id']??0)
        ||($plan['agent_id']??null)!==$agentId||($plan['conversation_id']??null)!==$conversationId
        ||!is_array($plan['parameters']??null)||!is_string($plan['plan_hash']??null)) throw new RuntimeException('Meeting action scope changed.',409);
    $hash=$plan['plan_hash'];$unsigned=$plan;unset($unsigned['plan_hash']);
    if (!hash_equals(section12_revision($unsigned),$hash)) throw new RuntimeException('The reviewed meeting action changed.',409);
    $input=['action'=>$plan['action'],'meeting_id'=>$plan['meeting_id']]+array_diff_key($plan['parameters'],array_flip(['start_at_utc','end_at_utc']));
    $fresh=vp3_meeting_management_prepare_v1($pdo,$user,$agentId,$conversationId,$input,$authority,$now);
    if (!hash_equals($fresh['plan_hash'],$hash)) throw new RuntimeException('The meeting changed after review. Review it again before applying.',409);
    $target=vp3_meeting_management_target_v1($pdo,$user,$agentId,$conversationId,(int)$plan['meeting_id'],$authority,$now);
    $revision=$target['revision'];
    if ($plan['action']==='agenda_add') $revision=section12_revision([$revision,video_meeting_agenda_items_v18140($pdo,$target['meeting'],(int)$target['user']['id'])]);
    if (!hash_equals($plan['target_revision'],$revision)) throw new RuntimeException('Meeting access or content changed before execution.',409);
    $meeting=$target['meeting'];$event=$target['event'];$owner=(int)$target['user']['id'];$parameters=$plan['parameters'];
    $receipt=['verified'=>true,'action'=>$plan['action'],'meeting_id'=>(int)$meeting['id'],'calendar_event_id'=>(int)$event['id'],
        'meeting_title'=>(string)$meeting['title'],'outbound_notices_sent'=>false,'after_commit'=>null];
    if ($plan['action']==='agenda_add') {
        $fingerprint=video_meeting_agenda_fingerprint_v18140((int)$meeting['id'],$parameters['item_text'],'manual',0,'');
        $items=video_meeting_agenda_items_v18140($pdo,$meeting,$owner);$existing=null;
        foreach ($items as $item) if (video_meeting_agenda_fingerprint_v18140((int)$meeting['id'],(string)$item['item_text'],
            (string)$item['source_kind'],(int)($item['source_meeting_id']??0),(string)($item['source_hash']??''))===$fingerprint) $existing=$item;
        if ($existing&&($existing['item_type']!==$parameters['item_type']||$existing['priority']!==$parameters['priority'])) {
            throw new RuntimeException('This agenda text already exists with different settings. Edit that item in the meeting.',409);
        }
        if (!$existing) $items=video_meeting_agenda_insert_v18140($pdo,$meeting,$owner,$parameters['item_text'],$parameters['item_type'],$parameters['priority']);
        $saved=null;
        foreach ($items as $item) if ($item['item_text']===$parameters['item_text']&&$item['item_type']===$parameters['item_type']
            &&$item['priority']===$parameters['priority']) $saved=$item;
        if (!$saved) throw new RuntimeException('The agenda addition could not be verified.',409);
        $receipt+=['agenda_item_id'=>(int)$saved['id'],'item_text'=>(string)$saved['item_text'],'item_type'=>(string)$saved['item_type'],
            'priority'=>(string)$saved['priority'],'already_present'=>$existing!==null,'summary'=>$existing?'The agenda item is already present.':'Added the item to the meeting agenda.'];
    } elseif ($plan['action']==='reschedule') {
        $update=$parameters+['title'=>$event['title'],'description'=>$event['description']??'','location'=>$event['location']??'',
            'all_day'=>false,'recurrence'=>'none','agent_notifications'=>(int)($event['agent_notifications']??0),
            'expected_revision'=>section12_revision($event)];
        $saved=user_calendar_update_event_v1300($pdo,$target['user'],(int)$event['id'],$update);
        $result=video_meeting_manual_sync_event_v1830($pdo,$target['user'],$saved,[],video_meeting_guest_access_mode_v1830($pdo,$meeting));
        $meeting=$result['meeting'];
        if ($meeting['start_at_utc']!==$parameters['start_at_utc']||$meeting['end_at_utc']!==$parameters['end_at_utc']
            ||$meeting['timezone']!==$parameters['timezone']) throw new RuntimeException('The meeting time change could not be verified.',409);
        vp3_meeting_management_verify_calendars_v1($pdo,$meeting);
        $receipt+=['summary'=>'Changed the meeting time in VP3. No outbound notices were sent.'];
    } else {
        $cancelled=video_meeting_manual_cancel_event_v1830($pdo,$target['user'],$event);
        if (!$cancelled||!user_calendar_cancel_event_v1300($pdo,$target['user'],(int)$event['id'],section12_revision($event))) {
            throw new RuntimeException('The meeting cancellation could not be saved.',409);
        }
        $meeting=video_meeting_row_v1800($pdo,(int)$meeting['id']);
        // Native after_cancel also deletes a provider room and sends notices.
        // Only its canonical local Calendar projection belongs in this action.
        video_meeting_update_calendar_events_v1800($pdo,$meeting);
        vp3_meeting_management_verify_calendars_v1($pdo,$meeting);
        if ($meeting['status']!=='cancelled') throw new RuntimeException('The meeting cancellation could not be verified.',409);
        $receipt+=['summary'=>'Cancelled the meeting in VP3. No outbound notices were sent.'];
    }
    $receipt+=['status'=>(string)$meeting['status'],'start_at_utc'=>(string)$meeting['start_at_utc'],
        'end_at_utc'=>(string)$meeting['end_at_utc'],'timezone'=>(string)$meeting['timezone'],
        'review_url'=>url('/meeting.php?meeting='.(string)$meeting['public_id'])];
    return $receipt;
}

/** Current, authorized outcome projection. A receipt is evidence of a past
 * execution, never authority to recreate a deleted agenda or cancelled event. */
function vp3_meeting_management_inspect_v1(PDO $pdo,array $user,int $agentId,int $conversationId,array $plan,array $result): array
{
    [$live]=vp3_calendar_prep_scope_v1($pdo,$user,$agentId,$conversationId,null);
    $unsigned=$plan;unset($unsigned['plan_hash']);
    if (($plan['owner_user_id']??null)!==(int)$live['id']||($plan['agent_id']??null)!==$agentId
        ||($plan['conversation_id']??null)!==$conversationId||!is_string($plan['plan_hash']??null)
        ||!hash_equals(section12_revision($unsigned),$plan['plan_hash'])||($result['verified']??false)!==true
        ||($result['action']??null)!==($plan['action']??null)||($result['meeting_id']??null)!==($plan['meeting_id']??null)
        ||($result['calendar_event_id']??null)!==($plan['calendar_event_id']??null)) throw new RuntimeException('The saved meeting action scope changed.',409);
    $meeting=video_meeting_row_v1800($pdo,(int)$plan['meeting_id']);
    if (!$meeting||(int)$meeting['owner_user_id']!==(int)$live['id']||(int)($meeting['organizer_agent_id']??0)!==$agentId
        ||(int)($meeting['calendar_event_id']??0)!==$plan['calendar_event_id']||(int)($meeting['booking_id']??0)>0) {
        throw new RuntimeException('The saved meeting action is unavailable.',403);
    }
    $organizer=false;
    foreach (video_meeting_participants_v1800($pdo,(int)$meeting['id']) as $participant) {
        if ((int)($participant['user_id']??0)===(int)$live['id']&&$participant['role']==='organizer'
            &&!in_array($participant['invitation_status'],['revoked','declined','cancelled'],true)) $organizer=true;
    }
    if (!$organizer) throw new RuntimeException('Meeting organizer access changed.',403);
    $parameters=$plan['parameters'];
    if ($plan['action']==='agenda_add') {
        $found=false;
        foreach (video_meeting_agenda_items_v18140($pdo,$meeting,(int)$live['id']) as $item) {
            if ((int)$item['id']===($result['agenda_item_id']??0)&&$item['item_text']===$parameters['item_text']
                &&$item['item_type']===$parameters['item_type']&&$item['priority']===$parameters['priority']) $found=true;
        }
        if (!$found) throw new RuntimeException('The saved agenda item changed or was removed.',409);
    } else {
        if ($plan['action']==='cancel'&&$meeting['status']!=='cancelled') throw new RuntimeException('The saved cancellation changed.',409);
        if ($plan['action']==='reschedule'&&($meeting['status']==='cancelled'||$meeting['start_at_utc']!==$parameters['start_at_utc']
            ||$meeting['end_at_utc']!==$parameters['end_at_utc']||$meeting['timezone']!==$parameters['timezone'])) throw new RuntimeException('The saved meeting time changed.',409);
        vp3_meeting_management_verify_calendars_v1($pdo,$meeting);
    }
    return $result;
}

/** The native projection deliberately tolerates missing legacy rows; Chat must
 * prove every actual linked member Calendar row instead of claiming success. */
function vp3_meeting_management_verify_calendars_v1(PDO $pdo,array $meeting): void
{
    $links=[(int)$meeting['owner_user_id'].':'.(int)$meeting['calendar_event_id']=>[(int)$meeting['owner_user_id'],(int)$meeting['calendar_event_id']]];
    foreach (video_meeting_participants_v1800($pdo,(int)$meeting['id']) as $participant) {
        $uid=(int)($participant['user_id']??0);$eventId=(int)($participant['calendar_event_id']??0);
        if ($uid>0&&$eventId>0) $links[$uid.':'.$eventId]=[$uid,$eventId];
    }
    foreach ($links as [$uid,$eventId]) {
        $event=user_calendar_event_v1300($pdo,$uid,$eventId);
        if (!$event) throw new RuntimeException('A linked participant Calendar event is unavailable. No meeting change was committed.',409);
        foreach (['title','start_at_utc','end_at_utc','timezone'] as $field) {
            if ((string)$event[$field]!==(string)$meeting[$field]) throw new RuntimeException('The linked VP3 calendars could not be synchronized.',409);
        }
        if ($meeting['status']==='cancelled'&&$event['status']!=='cancelled') throw new RuntimeException('The linked VP3 calendar cancellation could not be verified.',409);
    }
}
