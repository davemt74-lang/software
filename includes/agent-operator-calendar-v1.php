<?php
declare(strict_types=1);

// A planned phone appointment is an ordinary owner Calendar event.
// It is never an executable call intent, carrier job, or consent receipt.
require_once __DIR__.'/agent-operator-auto-call-v1.php';

const VP3_OPERATOR_CALENDAR_PREFIX_V1='operator-call-v1:';
const VP3_OPERATOR_CALENDAR_HORIZON_V1=604800;

function vp3_operator_calendar_ready_v1(PDO $pdo): bool
{
    require_once __DIR__.'/user-calendar-v1300.php';
    return user_calendar_schema_ready_v1300($pdo)&&user_calendar_recurrence_schema_ready_v1340($pdo);
}

function vp3_operator_calendar_policy_v1(PDO $pdo,array $scope,array $input,bool $lock=false): array
{
    if($scope['conversation_id']<1||(function_exists('vp3_participant_scope_v1')&&vp3_participant_scope_v1()!==null))
        vp3_phone_workspace_error_v1('operator_private_chat_required',403,'Open your saved private Agent Chat.');
    if(!vp3_operator_auto_call_schema_ready_v1($pdo)||!vp3_operator_calendar_ready_v1($pdo))
        vp3_phone_workspace_error_v1('operator_calendar_upgrade_required',503,'Install the Cloud Phone and Calendar upgrades.');
    $contactId=vp3_phone_workspace_id_v1($input['contact_id']??null);
    $lineId=vp3_phone_workspace_id_v1($input['number_id']??null);
    $revision=vp3_phone_workspace_id_v1($input['expected_revision']??null);
    $contact=vp3_operator_auto_call_contact_v1($pdo,$scope,$contactId,$lock);
    $line=vp3_phone_workspace_number_v1($pdo,$scope,$lineId,false,$lock);
    $agentId=(int)$line['agent_id'];
    $row=vp3_operator_auto_call_row_v1($pdo,$scope,$contactId,$agentId,$lock);
    $block=vp3_operator_auto_call_prerequisites_v1($pdo,$scope,$line);
    if(!$row||!(bool)$row['enabled']||(int)$row['revision']!==$revision
        ||!vp3_operator_auto_call_binding_v1($row,$contact,$line)||$block!=='')
        vp3_phone_workspace_error_v1('operator_policy_changed',409,'Review the current contact authorization and recording prerequisites before planning a call.');
    $settings=[];
    foreach(vp3_operator_auto_call_defaults_v1() as $key=>$default)
        $settings[$key]=is_int($default)?(int)$row[$key]:(string)$row[$key];
    $settings=vp3_operator_auto_call_settings_v1($settings);
    return ['contact'=>$contact,'line'=>$line,'policy'=>$row,'settings'=>$settings,'revision'=>$revision];
}

function vp3_operator_calendar_source_v1(array $bound,int $start,int $duration): string
{
    $contact=$bound['contact'];$line=$bound['line'];$policy=$bound['policy'];
    return VP3_OPERATOR_CALENDAR_PREFIX_V1.(int)$contact['id'].':'.(int)$line['agent_id'].':'
        .hash('sha256',json_encode([(string)$contact['public_id'],(int)$line['id'],(int)$policy['revision'],$start,$duration],JSON_THROW_ON_ERROR));
}

function vp3_operator_calendar_slot_v1(array $settings,int $start,int $duration,int $now): ?array
{
    if($duration<60||$duration>(int)$settings['max_duration_seconds']||$duration%60!==0
        ||$start%900!==0||$start<$now+300||$start>$now+VP3_OPERATOR_CALENDAR_HORIZON_V1-$duration) return null;
    $zone=new DateTimeZone($settings['timezone']);
    $a=(new DateTimeImmutable('@'.$start))->setTimezone($zone);
    $b=(new DateTimeImmutable('@'.($start+$duration)))->setTimezone($zone);
    if($a->format('Y-m-d')!==$b->format('Y-m-d')||$a->getOffset()!==$b->getOffset()
        ||strcmp($a->format('H:i'),$settings['window_start'])<0||strcmp($b->format('H:i'),$settings['window_end'])>0)
        return null;
    return ['start_epoch'=>$start,'duration_seconds'=>$duration,'start_at_utc'=>gmdate('Y-m-d H:i:s',$start),
        'end_at_utc'=>gmdate('Y-m-d H:i:s',$start+$duration),'timezone'=>$zone->getName(),
        'label'=>$a->format('D, M j, g:i A').' – '.$b->format('g:i A T')];
}

/** Uses native Calendar recurrence and pending/confirmed bookings. Cloud only. */
function vp3_operator_calendar_conflicts_v1(PDO $pdo,array $scope,int $from,int $to): array
{
    if(!vp3_operator_calendar_ready_v1($pdo))
        vp3_phone_workspace_error_v1('operator_calendar_unavailable',503,'Cloud Calendar availability could not be verified.');
    $events=user_calendar_events_v1300($pdo,$scope['user'],gmdate('Y-m-d H:i:s',$from),gmdate('Y-m-d H:i:s',$to),false);
    $ranges=[];
    foreach($events as $event) {
        if(!is_array($event)||!is_string($event['start_at_utc']??null)||!is_string($event['end_at_utc']??null))
            vp3_phone_workspace_error_v1('operator_calendar_unavailable',503,'A Cloud Calendar time could not be verified.');
        $start=user_calendar_validate_utc_v1300($event['start_at_utc'])->getTimestamp();
        $end=user_calendar_validate_utc_v1300($event['end_at_utc'])->getTimestamp();
        if($end<=$start)vp3_phone_workspace_error_v1('operator_calendar_unavailable',503,'A Cloud Calendar time could not be verified.');
        $ranges[]=[$start,$end];
    }
    return $ranges;
}

function vp3_operator_calendar_slot_free_v1(int $start,int $duration,array $ranges): bool
{
    foreach($ranges as [$from,$to])if($start<$to&&$start+$duration>$from)return false;
    return true;
}

function vp3_operator_calendar_event_view_v1(array $event): array
{
    $start=user_calendar_validate_utc_v1300((string)$event['start_at_utc']);
    $end=user_calendar_validate_utc_v1300((string)$event['end_at_utc']);
    $zone=new DateTimeZone((string)$event['timezone']);
    $path=parse_url(url('/calendar-event.php'),PHP_URL_PATH);
    if(!is_string($path)||!str_starts_with($path,'/')||str_starts_with($path,'//'))throw new RuntimeException('Calendar event link unavailable.');
    return ['event_id'=>(int)$event['id'],'title'=>mb_substr((string)$event['title'],0,190),
        'occurrence_id'=>(string)($event['occurrence_id']??('event:'.(int)$event['id'])),
        'recurrence'=>(string)($event['recurrence']??'none'),
        'start_at'=>$start->format(DATE_ATOM),'end_at'=>$end->format(DATE_ATOM),
        'label'=>$start->setTimezone($zone)->format('D, M j, g:i A T'),
        'url'=>$path.'?id='.(int)$event['id'],
        'execution_enabled'=>false];
}

/** Only one upcoming owner-reviewed appointment per contact, across assigned Agents. */
function vp3_operator_calendar_existing_v1(PDO $pdo,array $scope,int $contactId,int $now): ?array
{
    $q=$pdo->prepare("SELECT * FROM user_calendar_events WHERE owner_user_id=? AND source='user'
        AND source_reference LIKE ? AND status='active' AND (end_at_utc>? OR recurrence IN ('daily','weekly'))
        ORDER BY start_at_utc,id LIMIT 1");
    $q->execute([$scope['owner_user_id'],VP3_OPERATOR_CALENDAR_PREFIX_V1.$contactId.':%',gmdate('Y-m-d H:i:s',$now)]);
    $row=$q->fetch(PDO::FETCH_ASSOC);
    if($row&&in_array($row['recurrence']??'none',['daily','weekly'],true)){
        $occurrences=user_calendar_occurrences_v1340($row,gmdate('Y-m-d H:i:s',$now),gmdate('Y-m-d H:i:s',$now+8*86400));
        if($occurrences)return $occurrences[0];
    }
    return $row?:null;
}

function vp3_operator_calendar_preview_v1(PDO $pdo,array $scope,array $input,int $now): array
{
    if(array_diff(array_keys($input),['action','agent_id','conversation_id','contact_id','number_id','expected_revision']))
        vp3_phone_workspace_error_v1('operator_invalid_plan',422,'Choose a saved contact and assigned line.');
    $bound=vp3_operator_calendar_policy_v1($pdo,$scope,$input);
    $duration=min(600,$bound['settings']['max_duration_seconds']);
    $result=['contact_id'=>(int)$bound['contact']['id'],'number_id'=>(int)$bound['line']['id'],
        'revision'=>$bound['revision'],'coverage'=>'cloud_calendar_only','execution_enabled'=>false,'slots'=>[],'existing'=>null];
    $existing=vp3_operator_calendar_existing_v1($pdo,$scope,$result['contact_id'],$now);
    if($existing){$result['existing']=vp3_operator_calendar_event_view_v1($existing);return $result;}
    $ranges=vp3_operator_calendar_conflicts_v1($pdo,$scope,$now,$now+VP3_OPERATOR_CALENDAR_HORIZON_V1);
    $zone=new DateTimeZone($bound['settings']['timezone']);$days=[];
    for($start=intdiv($now+300+899,900)*900;$start<=$now+VP3_OPERATOR_CALENDAR_HORIZON_V1-$duration;$start+=900) {
        $slot=vp3_operator_calendar_slot_v1($bound['settings'],$start,$duration,$now);
        if(!$slot||!vp3_operator_calendar_slot_free_v1($start,$duration,$ranges))continue;
        $day=(new DateTimeImmutable('@'.$start))->setTimezone($zone)->format('Y-m-d');
        if(isset($days[$day]))continue;
        $days[$day]=true;$result['slots'][]=$slot;
        if(count($result['slots'])===3)break;
    }
    return $result;
}

/** Explicit owner save. Recheck policy and calendar inside the canonical owner transaction. */
function vp3_operator_calendar_save_v1(PDO $pdo,array $scope,array $input,int $now): array
{
    if(array_diff(array_keys($input),['action','csrf_token','agent_id','conversation_id','contact_id','number_id','expected_revision','start_epoch','duration_seconds'])
        ||!is_int($input['start_epoch']??null)||$input['start_epoch']<1||$input['start_epoch']>4102444800
        ||!is_int($input['duration_seconds']??null))
        vp3_phone_workspace_error_v1('operator_invalid_plan',422,'Choose a reviewed calling time.');
    require_once __DIR__.'/user-calendar-v1300.php';
    return section12_owner_transaction($pdo,$scope['owner_user_id'],static function()use($pdo,$scope,$input,$now):array {
        $scope=vp3_phone_workspace_scope_v1($pdo,$scope['user'],$input);
        $bound=vp3_operator_calendar_policy_v1($pdo,$scope,$input,true);
        $start=$input['start_epoch'];$duration=$input['duration_seconds'];
        if($duration!==min(600,$bound['settings']['max_duration_seconds']))
            vp3_phone_workspace_error_v1('operator_invalid_plan',422,'The reviewed appointment duration changed.');
        $ref=vp3_operator_calendar_source_v1($bound,$start,$duration);
        $q=$pdo->prepare("SELECT * FROM user_calendar_events WHERE owner_user_id=? AND source='user' AND source_reference=? ORDER BY id DESC LIMIT 1");
        $q->execute([$scope['owner_user_id'],$ref]);$old=$q->fetch(PDO::FETCH_ASSOC);$q->closeCursor();
        if($old){
            if($old['status']!=='active'||$old['start_at_utc']!==gmdate('Y-m-d H:i:s',$start)
                ||$old['end_at_utc']!==gmdate('Y-m-d H:i:s',$start+$duration)||$old['recurrence']!=='none')
                vp3_phone_workspace_error_v1('operator_calendar_changed',409,'The existing appointment was edited or canceled. Review it in Calendar.');
            return ['appointment'=>vp3_operator_calendar_event_view_v1($old),'created'=>false];
        }
        $slot=vp3_operator_calendar_slot_v1($bound['settings'],$start,$duration,$now);
        if(!$slot)vp3_phone_workspace_error_v1('operator_calendar_changed',409,'That time is outside the current calling window or planning horizon.');
        if(vp3_operator_calendar_existing_v1($pdo,$scope,(int)$bound['contact']['id'],$now))
            vp3_phone_workspace_error_v1('operator_calendar_changed',409,'This contact already has a planned phone appointment. Review it in Calendar.');
        if(!vp3_operator_calendar_slot_free_v1($start,$duration,vp3_operator_calendar_conflicts_v1($pdo,$scope,$start,$start+$duration)))
            vp3_phone_workspace_error_v1('operator_calendar_conflict',409,'Cloud Calendar now has a conflict. Choose another time.');
        $q=$pdo->prepare('SELECT name FROM crm_contacts WHERE owner_user_id=? AND id=?');
        $q->execute([$scope['owner_user_id'],(int)$bound['contact']['id']]);$name=$q->fetchColumn();$q->closeCursor();
        $name=is_string($name)?trim(preg_replace('/[\p{Cc}\p{Cf}]|\s+/u',' ',strip_tags($name))??''):'';
        $event=user_calendar_create_event_locked_v1300($pdo,$scope['user'],[
            'title'=>'Planned phone call — '.($name!==''?mb_substr($name,0,120):'Saved contact'),
            'description'=>'Owner-reviewed phone appointment. This is a reminder only, not an automatic call or recipient booking. Verify the contact and consent before manually calling.',
            'start_at_utc'=>$slot['start_at_utc'],'end_at_utc'=>$slot['end_at_utc'],
            'timezone'=>$slot['timezone'],'all_day'=>false,'recurrence'=>'none','agent_notifications'=>true
        ],'user',null,$ref);
        return ['appointment'=>vp3_operator_calendar_event_view_v1($event),'created'=>true];
    });
}

/** Operator Brain projects native reminders, never schedules a call job. */
function vp3_operator_calendar_upcoming_v1(PDO $pdo,array $user,int $agentId,int $cid,int $now): array
{
    $out=['coverage'=>'unavailable','appointments'=>[],'execution_enabled'=>false];
    if($cid<1||(function_exists('vp3_participant_scope_v1')&&vp3_participant_scope_v1()!==null))return $out;
    try {
        $scope=vp3_phone_workspace_scope_v1($pdo,$user,['agent_id'=>$agentId,'conversation_id'=>$cid]);
        if(!vp3_phone_package_v100($pdo,$scope['owner_user_id'])['voice']||!vp3_operator_calendar_ready_v1($pdo))return $out;
        $q=$pdo->prepare("SELECT * FROM user_calendar_events WHERE owner_user_id=? AND source='user' AND source_reference LIKE ?
            AND status='active' AND (end_at_utc>? OR recurrence IN ('daily','weekly')) AND start_at_utc<? ORDER BY start_at_utc,id LIMIT 51");
        $q->execute([$scope['owner_user_id'],VP3_OPERATOR_CALENDAR_PREFIX_V1.'%',gmdate('Y-m-d H:i:s',$now),gmdate('Y-m-d H:i:s',$now+VP3_OPERATOR_CALENDAR_HORIZON_V1)]);
        $rows=$q->fetchAll(PDO::FETCH_ASSOC);$out['coverage']=count($rows)>50?'limited':'ready';$appointments=[];
        foreach(array_slice($rows,0,50) as $row){
            if(!preg_match('/^operator-call-v1:([1-9][0-9]{0,15}):([1-9][0-9]{0,15}):[a-f0-9]{64}$/D',(string)$row['source_reference'],$match))continue;
            if($agentId>0&&(int)$match[2]!==$agentId)continue;
            foreach(user_calendar_occurrences_v1340($row,gmdate('Y-m-d H:i:s',$now),gmdate('Y-m-d H:i:s',$now+VP3_OPERATOR_CALENDAR_HORIZON_V1)) as $event)
                $appointments[]=vp3_operator_calendar_event_view_v1($event);
        }
        usort($appointments,static fn(array $a,array $b):int=>strcmp($a['start_at'],$b['start_at'])?:strcmp($a['occurrence_id'],$b['occurrence_id']));
        if(count($appointments)>10)$out['coverage']='limited';
        $out['appointments']=array_slice($appointments,0,10);
        return $out;
    }catch(Throwable $error){return ['coverage'=>'unavailable','appointments'=>[],'execution_enabled'=>false];}
}
