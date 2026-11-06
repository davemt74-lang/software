<?php
declare(strict_types=1);

/** Adapters over the existing reminder and personal-calendar ledgers, not a second scheduler. */
function vp3_initiative_schedule_action_scope_v1(PDO $pdo,array $user,array $principal,array $binding): array
{
    if(!function_exists('db')||db()!==$pdo)throw new RuntimeException('Schedule canonical connection changed.',503);
    if(!function_exists('vp3_initiative_action_scope_v1'))throw new RuntimeException('Action authority is unavailable.',503);
    return vp3_initiative_action_scope_v1($pdo,$user,$principal,$binding);
}

function vp3_initiative_schedule_action_load_v1(PDO $pdo,string $family): void
{
    if(!function_exists('vp3_initiative_office_load_v1'))require_once __DIR__.'/agent-initiative-office-records-v1.php';
    vp3_initiative_office_load_v1($pdo,$family);
    if($family==='reminder'&&!reminder_ready_v1($pdo))throw new RuntimeException('Reminder storage is unavailable.',503);
    if($family==='calendar'&&!user_calendar_schema_ready_v1300($pdo))throw new RuntimeException('Calendar storage is unavailable.',503);
}

function vp3_initiative_schedule_action_catalog_v1(PDO $pdo,array $user,array $principal,array $binding): array
{
    $scope=vp3_initiative_schedule_action_scope_v1($pdo,$user,$principal,$binding);$out=[];
    $text=static fn(int $max):array=>['type'=>'string','minLength'=>1,'maxLength'=>$max];
    $schema=static fn(array $properties,array $required):array=>['type'=>'object','additionalProperties'=>false,'properties'=>$properties,'required'=>$required];
    $schedule=$schema(['title'=>$text(240),'timezone'=>$text(80),'start_local'=>$text(19),
        'recurrence'=>['type'=>'string','enum'=>['none','daily','weekdays','weekly']],
        'delivery'=>['type'=>'string','enum'=>['chat','notification','both']]],['title','timezone','start_local']);
    $event=$schema(['title'=>$text(190),'description'=>['type'=>'string','maxLength'=>10000],'location'=>['type'=>'string','maxLength'=>500],
        'timezone'=>$text(80),'date'=>$text(10),'start_time'=>$text(5),'end_time'=>$text(5),'end_date'=>$text(10),
        'all_day'=>['type'=>'boolean'],'recurrence'=>['type'=>'string','enum'=>['none','weekly']],
        'agent_notifications'=>['type'=>'boolean']],['title','timezone','date']);
    foreach(['reminder'=>['create','update','complete','snooze','dismiss','cancel','delete'],'calendar'=>['create','update','cancel']] as $family=>$operations){
        try{vp3_initiative_schedule_action_load_v1($pdo,$family);}catch(RuntimeException $e){if($e instanceof PDOException)throw $e;continue;}
        foreach($operations as $operation){
            $id=$family.'.'.$operation;$properties=[];$required=[];
            if($operation!=='create'){$properties['target_ref']=$text(100);$required[]='target_ref';}
            if(in_array($operation,['create','update'],true)){$key=$family==='reminder'?'schedule':'event';$properties[$key]=$family==='reminder'?$schedule:$event;$required[]=$key;}
            if($operation==='snooze'){$properties['minutes']=['type'=>'integer','minimum'=>1,'maximum'=>10080];$required[]='minutes';}
            $out[]=['adapter'=>$id,'capability'=>$family==='reminder'?'reminders.manage':'calendar.manage','operation'=>$operation,
                'effect_class'=>'private_record','review_required'=>true,
                'description'=>$family==='calendar'?'Review an exact personal calendar event or entire recurring series. No bookings, calls, invitations or linked video meetings.':'Review an exact personal reminder change. Complete and dismiss affect the current occurrence; cancel stops its series.',
                'arguments_schema'=>$schema($properties,$required)];
        }
    }
    return $out;
}

/** Only exact proposal sources become writable targets; discovery never expands their IDs. */
function vp3_initiative_schedule_action_resources_v1(PDO $pdo,array $user,array $principal,array $binding,array $sourceRefs): array
{
    $scope=vp3_initiative_schedule_action_scope_v1($pdo,$user,$principal,$binding);$out=['reminders'=>[],'calendar_events'=>[]];
    if(count($sourceRefs)>160)throw new InvalidArgumentException('Too many action sources.');
    foreach($sourceRefs as $ref)if(!is_string($ref))throw new InvalidArgumentException('Invalid action source.');
    foreach(array_unique($sourceRefs) as $ref){
        if(!preg_match('/\A(?:reminder:[1-9][0-9]{0,17}|calendar:event:[1-9][0-9]{0,17}(?:@[0-9]{8}T[0-9]{6}Z)?)\z/D',$ref))continue;
        if(!function_exists('vp3_initiative_office_ref_v1'))require_once __DIR__.'/agent-initiative-office-records-v1.php';
        $selector=vp3_initiative_office_ref_v1($ref);if(!$selector)throw new InvalidArgumentException('Invalid schedule source.');
        vp3_initiative_schedule_action_load_v1($pdo,$selector['family']);
        $state=vp3_initiative_office_state_v1($pdo,$scope,$selector);if(!$state)throw new RuntimeException('Schedule source changed.',409);
        if($selector['family']==='calendar'){
            try{vp3_initiative_schedule_action_personal_event_v1($pdo,$selector['id']);}
            catch(RuntimeException $e){if($e->getCode()===409)continue;throw $e;}
        }
        $entry=vp3_initiative_office_entry_v1($ref,$selector,$state);
        $row=vp3_initiative_schedule_action_row_v1($pdo,$scope,$selector['family'],$selector['id']);
        if(!$row)throw new RuntimeException('Schedule source changed.',409);
        $out[$selector['family']==='reminder'?'reminders':'calendar_events'][]=[
            'id'=>$selector['id'],'ref'=>$ref,'revision'=>$entry['revision'],'title'=>$state['title'],
            'record_revision'=>$selector['family']==='reminder'?(string)$row['revision']:section12_revision($row),
            'occurrence_id'=>$selector['occurrence']??null];
    }
    return $out;
}

function vp3_initiative_schedule_action_row_v1(PDO $pdo,array $scope,string $family,int $id): ?array
{
    $owner=(int)$scope['user']['id'];
    if($family==='calendar')return user_calendar_event_v1300($pdo,$owner,$id);
    $q=$pdo->prepare('SELECT * FROM agent_reminders_v1 WHERE id=? AND owner_user_id=?');$q->execute([$id,$owner]);
    return $q->fetch(PDO::FETCH_ASSOC)?:null;
}

/** A partial video schema fails closed; personal-event writes never bypass meeting lifecycle. */
function vp3_initiative_schedule_action_personal_event_v1(PDO $pdo,int $id): void
{
    if(!table_exists('video_meetings'))return;
    if(!column_exists('video_meetings','calendar_event_id'))throw new RuntimeException('Meeting linkage verification is unavailable.',503);
    $q=$pdo->prepare('SELECT id FROM video_meetings WHERE calendar_event_id=? LIMIT 1');$q->execute([$id]);
    if($q->fetchColumn())throw new RuntimeException('Use the linked video meeting editor for this event.',409);
}

function vp3_initiative_schedule_action_fields_v1(array $value,array $allowed): void
{
    if(array_is_list($value)||array_diff(array_keys($value),$allowed))throw new InvalidArgumentException('Unsupported schedule action field.');
}

function vp3_initiative_schedule_action_event_v1(PDO $pdo,array $input): array
{
    vp3_initiative_schedule_action_fields_v1($input,['title','description','location','timezone','date','start_time','end_time','end_date','all_day','recurrence','agent_notifications']);
    $out=[];
    foreach(['title'=>190,'description'=>10000,'location'=>500] as $field=>$limit){
        $value=$input[$field]??'';
        if(!is_string($value)||mb_strlen($value)>$limit||preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/',$value))throw new InvalidArgumentException('Invalid calendar '.$field.'.');
        $out[$field]=trim($value);
    }
    if($out['title']==='')throw new InvalidArgumentException('Choose a calendar event title.');
    $out['timezone']=reminder_zone_v1($input['timezone']??null);
    foreach(['date','start_time','end_time','end_date'] as $field){
        if(isset($input[$field])&&!is_string($input[$field]))throw new InvalidArgumentException('Invalid calendar date or time.');
        if(isset($input[$field]))$out[$field]=$input[$field];
    }
    if(isset($input['all_day'])&&!is_bool($input['all_day']))throw new InvalidArgumentException('Calendar all-day must be true or false.');
    $out['all_day']=$input['all_day']??false;
    if(!$out['all_day']&&(!isset($out['start_time'])||!isset($out['end_time'])))throw new InvalidArgumentException('Choose an exact calendar start and end time.');
    if($out['all_day']&&(isset($out['start_time'])||isset($out['end_time'])))throw new InvalidArgumentException('All-day events use dates instead of clock times.');
    $range=user_calendar_local_range_v1300($out,$out['timezone']);$options=user_calendar_options_v1340($input);
    user_calendar_validate_options_v1340($pdo,$options,$range);
    $parts=user_calendar_event_local_parts_v1300([...$range,...$options]);
    $out=array_merge(['title'=>$out['title'],'description'=>$out['description'],'location'=>$out['location']],$parts,
        ['agent_notifications'=>(bool)$options['agent_notifications']]);
    if($out['all_day'])unset($out['start_time'],$out['end_time']);
    return $out;
}

function vp3_initiative_schedule_action_target_v1(PDO $pdo,array $scope,string $family,mixed $ref,array $allowedResources): array
{
    if(!is_string($ref))throw new InvalidArgumentException('Choose an exact saved schedule source.');
    $selector=vp3_initiative_office_ref_v1($ref);
    if(!$selector||$selector['family']!==$family)throw new InvalidArgumentException('Choose an exact saved schedule source.');
    $resource=null;
    foreach($allowedResources[$family==='reminder'?'reminders':'calendar_events']??[] as $row){
        if(is_array($row)&&($row['ref']??null)===$ref)$resource=$row;
    }
    if(!$resource||($resource['id']??null)!==$selector['id']||!is_string($resource['revision']??null)||!preg_match('/\A[0-9a-f]{64}\z/D',$resource['revision']))throw new RuntimeException('Schedule target is outside this proposal.',403);
    $state=vp3_initiative_office_state_v1($pdo,$scope,$selector);if(!$state)throw new RuntimeException('Schedule source changed.',409);
    $entry=vp3_initiative_office_entry_v1($ref,$selector,$state);
    if(!hash_equals($entry['revision'],$resource['revision']))throw new RuntimeException('Schedule source changed.',409);
    $row=vp3_initiative_schedule_action_row_v1($pdo,$scope,$family,$selector['id']);if(!$row)throw new RuntimeException('Schedule source changed.',409);
    if($family==='calendar')vp3_initiative_schedule_action_personal_event_v1($pdo,$selector['id']);
    return ['ref'=>$ref,'id'=>$selector['id'],'title'=>$state['title'],'revision'=>$entry['revision'],
        'record_revision'=>$family==='reminder'?(string)$row['revision']:section12_revision($row),
        'occurrence_id'=>$selector['occurrence']??null];
}

function vp3_initiative_schedule_action_normalize_v1(PDO $pdo,array $user,array $principal,array $binding,array $raw,array $allowedResources): array
{
    $scope=vp3_initiative_schedule_action_scope_v1($pdo,$user,$principal,$binding);
    vp3_initiative_schedule_action_fields_v1($raw,['adapter','arguments']);$adapter=$raw['adapter']??null;$args=$raw['arguments']??null;
    if(!is_string($adapter)||!is_array($args)||!preg_match('/\A(reminder|calendar)\.(create|update|complete|snooze|dismiss|cancel|delete)\z/D',$adapter,$m))throw new InvalidArgumentException('Invalid schedule action.');
    [$unused,$family,$op]=$m;
    if($family==='calendar'&&!in_array($op,['create','update','cancel'],true))throw new InvalidArgumentException('Invalid calendar action.');
    vp3_initiative_schedule_action_load_v1($pdo,$family);
    // Calendar validation reuses the reminder's strict IANA timezone validator.
    if($family==='calendar'&&!function_exists('reminder_zone_v1'))require_once __DIR__.'/agent-reminders-v1.php';
    $fields=$op==='create'?[$family==='reminder'?'schedule':'event']:['target_ref'];
    if($op==='update')$fields[]=$family==='reminder'?'schedule':'event';if($op==='snooze')$fields[]='minutes';
    vp3_initiative_schedule_action_fields_v1($args,$fields);$normalized=[];$expected=[];
    if($op!=='create'){
        $expected=vp3_initiative_schedule_action_target_v1($pdo,$scope,$family,$args['target_ref']??null,$allowedResources);
        $normalized['target_ref']=$expected['ref'];
        if($family==='reminder'&&in_array($op,['complete','dismiss','snooze'],true)){
            $q=$pdo->prepare("SELECT id,wake_at,scheduled_at FROM agent_reminder_occurrences_v1 WHERE reminder_id=? AND owner_user_id=? AND status='pending' ORDER BY scheduled_at DESC,id DESC LIMIT 2");$q->execute([$expected['id'],(int)$scope['user']['id']]);$rows=$q->fetchAll(PDO::FETCH_ASSOC);
            if(count($rows)!==1)throw new RuntimeException('Choose one current reminder occurrence before changing it.',409);
            $expected['pending_occurrence']=['id'=>(int)$rows[0]['id'],'wake_at'=>$rows[0]['wake_at'],'scheduled_at'=>$rows[0]['scheduled_at']];
        }
    }
    if(in_array($op,['create','update'],true)){
        $key=$family==='reminder'?'schedule':'event';if(!is_array($args[$key]??null))throw new InvalidArgumentException('Complete schedule details are required.');
        if($family==='reminder'){$s=reminder_schedule_v1($args[$key],reminder_now_v1());unset($s['next_at']);$normalized[$key]=$s;}
        else $normalized[$key]=vp3_initiative_schedule_action_event_v1($pdo,$args[$key]);
    }
    if($op==='snooze'){
        $minutes=$args['minutes']??null;if(!is_int($minutes)||$minutes<1||$minutes>10080)throw new InvalidArgumentException('Snooze for 1 minute to 7 days.');$normalized['minutes']=$minutes;
    }
    return ['adapter'=>$adapter,'arguments'=>$normalized,'capability'=>$family==='reminder'?'reminders.manage':'calendar.manage','operation'=>$op,
        'effect_class'=>'private_record','review_required'=>true,'target_refs'=>$expected?[$expected['ref']]:[],'expected'=>$expected];
}

/** Called only by the canonical broker after its final source/approval guard and owner lock. */
function vp3_initiative_schedule_action_apply_v1(PDO $pdo,array $user,array $principal,array $binding,array $plan,string $actionId): array
{
    if(!$pdo->inTransaction())throw new RuntimeException('Schedule action requires the broker transaction.',409);
    if(!preg_match('/\A[A-Za-z0-9:_-]{1,100}\z/D',$actionId))throw new InvalidArgumentException('Invalid action identity.');
    $scope=vp3_initiative_schedule_action_scope_v1($pdo,$user,$principal,$binding);$owner=(int)$scope['user']['id'];
    $adapter=$plan['adapter']??'';if(!is_string($adapter)||!preg_match('/\A(reminder|calendar)\.(create|update|complete|snooze|dismiss|cancel|delete)\z/D',$adapter,$m))throw new InvalidArgumentException('Invalid schedule action.');
    [$unused,$family,$op]=$m;$args=$plan['arguments']??null;$expected=$plan['expected']??null;
    if(!is_array($args)||!is_array($expected)||($plan['review_required']??null)!==true||($plan['capability']??null)!==($family==='reminder'?'reminders.manage':'calendar.manage')||($plan['operation']??null)!==$op)throw new InvalidArgumentException('Invalid reviewed schedule action.');
    vp3_initiative_schedule_action_load_v1($pdo,$family);
    $resources=['reminders'=>[],'calendar_events'=>[]];
    if($op!=='create')$resources[$family==='reminder'?'reminders':'calendar_events'][]=$expected;
    $checked=vp3_initiative_schedule_action_normalize_v1($pdo,$scope['user'],$scope['principal'],$binding,['adapter'=>$adapter,'arguments'=>$args],$resources);
    if($checked!==$plan)throw new RuntimeException('Reviewed schedule action changed.',409);
    $row=$op==='create'?null:vp3_initiative_schedule_action_row_v1($pdo,$scope,$family,$expected['id']);
    $key='initiative_'.hash('sha256',$actionId);$now=reminder_now_v1();
    if($family==='reminder'){
        $domain=['operation'=>$op];if($op!=='create')$domain['id']=$expected['id'];
        if(isset($args['schedule']))$domain['schedule']=$args['schedule'];if(isset($args['minutes']))$domain['minutes']=$args['minutes'];
        // This is an idempotency payload, never a fabricated conversational command or confirmation.
        $payload=json_encode(['action_id'=>$actionId,'adapter'=>$adapter,'arguments'=>$args],JSON_THROW_ON_ERROR);
        $result=reminder_execute_locked_v1($pdo,$owner,'initiative:'.$scope['agent_id'].':'.$scope['conversation_id'],$key,$payload,$domain,$row?[$row]:[],$scope['conversation_id'],$now);
        $id=(int)$result['tool_receipt']['record_id'];
    }else{
        if($op==='create'){
            $event=$args['event'];$range=user_calendar_local_range_v1300($event,$event['timezone']);
            $saved=user_calendar_create_event_locked_v1300($pdo,$scope['user'],array_merge($event,$range),'agent',$scope['agent_id'],'initiative:'.hash('sha256',$actionId));$id=(int)$saved['id'];
        }elseif($op==='update'){
            $saved=user_calendar_update_event_locked_v1300($pdo,$scope['user'],$expected['id'],array_merge($args['event'],['expected_revision'=>$expected['record_revision']]));$id=(int)$saved['id'];
        }else{
            if(!user_calendar_cancel_event_locked_v1300($pdo,$scope['user'],$expected['id'],$expected['record_revision']))throw new RuntimeException('Calendar cancellation was not saved.',409);$id=$expected['id'];
        }
        $result=['handled'=>true,'answer'=>($op==='create'?'Created':($op==='update'?'Updated':'Cancelled')).' personal calendar event #'.$id.'.','actions'=>[],'sources'=>[],
            'tool_receipt'=>vp3_tool_receipt_v1('calendar.manage',$op,'completed','',$id)];
    }
    $result['action_id']=$actionId;$result['record_id']=$id;$result['applied_at']=reminder_utc_v1($now);
    $result['effect']=vp3_initiative_schedule_action_effect_v1($pdo,$scope,$family,$id,$expected);
    $result['effect_hash']=hash('sha256',json_encode($result['effect'],JSON_THROW_ON_ERROR));
    return vp3_initiative_schedule_action_inspect_v1($pdo,$scope['user'],$scope['principal'],$binding,$plan,$result);
}

/** Excludes presentation/lease telemetry; includes the actual changed occurrence and record. */
function vp3_initiative_schedule_action_effect_v1(PDO $pdo,array $scope,string $family,int $id,array $expected): array
{
    $row=vp3_initiative_schedule_action_row_v1($pdo,$scope,$family,$id);
    if($family==='calendar')return ['record'=>$row];
    if($row)foreach(['created_at','updated_at'] as $field)unset($row[$field]);
    $occurrence=null;$oid=$expected['pending_occurrence']['id']??null;
    if(is_int($oid)){
        $q=$pdo->prepare('SELECT id,reminder_id,owner_user_id,scheduled_at,wake_at,status FROM agent_reminder_occurrences_v1 WHERE id=? AND owner_user_id=? AND reminder_id=?');$q->execute([$oid,(int)$scope['user']['id'],$id]);$occurrence=$q->fetch(PDO::FETCH_ASSOC)?:null;
    }
    return ['record'=>$row,'occurrence'=>$occurrence];
}

function vp3_initiative_schedule_action_inspect_v1(PDO $pdo,array $user,array $principal,array $binding,array $plan,array $result): array
{
    $scope=vp3_initiative_schedule_action_scope_v1($pdo,$user,$principal,$binding);
    $family=str_starts_with((string)($plan['adapter']??''),'reminder.')?'reminder':'calendar';$op=$plan['operation']??'';
    vp3_initiative_schedule_action_load_v1($pdo,$family);$id=$result['record_id']??null;$expected=$plan['expected']??[];
    $receipt=vp3_tool_receipt_normalize_v1($result['tool_receipt']??null);
    if(!is_int($id)||$id<1||!$receipt||$receipt['capability']!==($family==='reminder'?'reminders.manage':'calendar.manage')||$receipt['operation']!==$op||$receipt['status']!=='completed'||$receipt['record_id']!==$id||($op!=='create'&&$id!==($expected['id']??null)))throw new RuntimeException('Schedule receipt could not be verified.',409);
    $effect=vp3_initiative_schedule_action_effect_v1($pdo,$scope,$family,$id,$expected);
    $hash=hash('sha256',json_encode($effect,JSON_THROW_ON_ERROR));
    if(!is_array($result['effect']??null)||$result['effect']!==$effect||!is_string($result['effect_hash']??null)||!hash_equals($hash,$result['effect_hash']))throw new RuntimeException('Saved schedule effect changed before verification.',409);
    $row=$effect['record'];
    if($op==='delete'){if($row!==null)throw new RuntimeException('Reminder deletion could not be verified.',409);}
    elseif(!$row)throw new RuntimeException('Saved schedule record is unavailable.',409);
    elseif($op==='cancel'&&($row['status']??'')!=='cancelled')throw new RuntimeException('Schedule cancellation could not be verified.',409);
    elseif(in_array($op,['create','update'],true)){
        $args=$plan['arguments'];
        if($family==='reminder'){
            foreach($args['schedule'] as $field=>$value)if((string)($row[$field]??'')!==(string)$value)throw new RuntimeException('Saved reminder differs from its reviewed schedule.',409);
            if($row['status']!=='active'||($op==='create'&&(int)$row['conversation_id']!==$scope['conversation_id']))throw new RuntimeException('Saved reminder scope could not be verified.',409);
        }else{
            $event=$args['event'];$range=user_calendar_local_range_v1300($event,$event['timezone']);
            foreach(array_merge($event,$range) as $field=>$value){if(in_array($field,['date','start_time','end_time','end_date'],true))continue;
                if(is_bool($value)?(bool)($row[$field]??false)!==$value:(string)($row[$field]??'')!==(string)$value)throw new RuntimeException('Saved calendar event differs from its reviewed event.',409);}
            if($row['status']!=='active')throw new RuntimeException('Saved calendar event is unavailable.',409);
            if($op==='create'&&(($row['source']??null)!=='agent'||(int)($row['created_by_agent_id']??0)!==$scope['agent_id']
                ||($row['source_reference']??null)!=='initiative:'.hash('sha256',(string)($result['action_id']??''))))throw new RuntimeException('Saved calendar creation scope could not be verified.',409);
        }
    }elseif(in_array($op,['complete','dismiss'],true)&&($effect['occurrence']['status']??null)!==($op==='complete'?'done':'dismissed'))throw new RuntimeException('Reminder occurrence result could not be verified.',409);
    if($family==='reminder'&&$op==='snooze'){
        if(!is_string($result['applied_at']??null)||!preg_match('/\A[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}Z\z/D',$result['applied_at']))throw new RuntimeException('Snooze time could not be verified.',409);
        try{$at=new DateTimeImmutable((string)($result['applied_at']??''),new DateTimeZone('UTC'));}catch(Throwable $e){throw new RuntimeException('Snooze time could not be verified.',409,$e);}
        $wake=reminder_utc_v1($at->modify('+'.$plan['arguments']['minutes'].' minutes'));
        if(($effect['occurrence']['status']??null)!=='pending'||($effect['occurrence']['wake_at']??null)!==$wake)throw new RuntimeException('Snoozed reminder time could not be verified.',409);
    }
    if($family==='reminder'&&$op==='cancel'){
        $q=$pdo->prepare("SELECT COUNT(*) FROM agent_reminder_occurrences_v1 WHERE reminder_id=? AND owner_user_id=? AND status='pending'");$q->execute([$id,(int)$scope['user']['id']]);
        if($row['next_at']!==null||(int)$q->fetchColumn()!==0)throw new RuntimeException('Reminder series cancellation could not be verified.',409);
    }
    $ref=$family==='reminder'?'reminder:'.$id:'calendar:event:'.$id;
    if($family==='calendar'&&($row['recurrence']??'none')==='weekly')$ref.='@'.user_calendar_validate_utc_v1300($row['start_at_utc'])->format('Ymd\THis\Z');
    $result['verified']=true;$result['output_refs']=[['ref'=>$ref,'record_id'=>$id,'revision'=>$hash]];$result['source_after']=[];
    foreach($plan['target_refs']??[] as $target){
        $selector=vp3_initiative_office_ref_v1($target);$state=$selector?vp3_initiative_office_state_v1($pdo,$scope,$selector):null;
        $result['source_after'][$target]=$state?vp3_initiative_office_entry_v1($target,$selector,$state)['revision']:null;
    }
    return $result;
}
