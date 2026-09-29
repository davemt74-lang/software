<?php
declare(strict_types=1);

const VP3_PROFILE_WEBMCP_SCHEDULING_V150='profile-webmcp-scheduling-v150-20260928';
const VP3_PROFILE_WEBMCP_SCHEDULING_INTENT_TTL_V150=600;

function vp3_profile_webmcp_scheduling_secret_v150(string $surface,string $nativeProof='',?array $property=null): string
{
    if($surface==='native_profile'){
        $nativeProof=trim($nativeProof);
        if(!preg_match('/^[a-f0-9]{64}$/',$nativeProof))throw new RuntimeException('Native WebMCP scheduling authority is unavailable.');
        return hash('sha256','vp3-webmcp-scheduling-v150|native|'.$nativeProof,true);
    }
    if($surface==='external_site'&&$property){
        $verification=strtolower(trim((string)($property['verification_token']??'')));
        $publicKey=strtolower(trim((string)($property['public_key']??'')));
        if(!preg_match('/^[a-f0-9]{64}$/',$verification)||!preg_match('/^[a-f0-9]{40}$/',$publicKey)||empty($property['is_active'])){
            throw new RuntimeException('Connected-site scheduling authority is unavailable.');
        }
        return hash('sha256','vp3-webmcp-scheduling-v150|external|'.$publicKey.'|'.$verification,true);
    }
    throw new RuntimeException('WebMCP scheduling authority is unavailable.');
}

function vp3_profile_webmcp_scheduling_context_v150(
    array $profile,string $surface,array $telemetry,string $nativeProof='',?array $property=null,string $origin=''
): array {
    $owner=(int)($profile['user_id']??0);
    $username=(string)($profile['username']??'');
    $webmcpSession=vp3_profile_webmcp_transport_id_v130((string)($telemetry['webmcp_session_id']??''));
    if($owner<1||$username===''||$webmcpSession==='')throw new RuntimeException('WebMCP scheduling session is unavailable.');
    $propertyId=$surface==='external_site'?(int)($property['id']??0):0;
    if($surface==='external_site'&&($propertyId<1||$origin===''))throw new RuntimeException('Connected-site scheduling session is unavailable.');
    return [
        'owner_user_id'=>$owner,
        'profile_username'=>$username,
        'surface'=>$surface,
        'property_id'=>$propertyId,
        'session_hash'=>hash('sha256',$webmcpSession),
        'origin_hash'=>$surface==='external_site'?hash('sha256',$origin):'',
        'secret'=>vp3_profile_webmcp_scheduling_secret_v150($surface,$nativeProof,$property),
    ];
}

function vp3_profile_webmcp_scheduling_token_v150(array $action,array $context): string
{
    $payload=[
        'v'=>1,
        'owner_user_id'=>(int)$action['owner_user_id'],
        'profile_username'=>(string)$action['profile_username'],
        'surface'=>(string)$action['surface'],
        'property_id'=>(int)($action['property_id']??0),
        'session_hash'=>(string)$action['session_hash'],
        'origin_hash'=>(string)($context['origin_hash']??''),
        'intent_id'=>(string)$action['intent_id'],
        'operation'=>(string)$action['operation'],
        'payload_hash'=>(string)$action['payload_hash'],
        'exp'=>(int)$action['expires_at_unix'],
    ];
    $encoded=vp3_profile_webmcp_b64url_encode_v140(json_encode($payload,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR));
    $sig=hash_hmac('sha256',$encoded,(string)$context['secret'],true);
    return $encoded.'.'.vp3_profile_webmcp_b64url_encode_v140($sig);
}

function vp3_profile_webmcp_scheduling_token_verify_v150(string $token,array $context,string $operation,array $intent): array
{
    $parts=explode('.',$token);
    if(count($parts)!==2)throw new RuntimeException('Scheduling confirmation token is invalid.');
    [$encoded,$sigEncoded]=$parts;
    $sig=vp3_profile_webmcp_b64url_decode_v140($sigEncoded);
    $expected=hash_hmac('sha256',$encoded,(string)$context['secret'],true);
    if($sig===''||!hash_equals($expected,$sig))throw new RuntimeException('Scheduling confirmation token is invalid.');
    $json=vp3_profile_webmcp_b64url_decode_v140($encoded);
    $payload=$json!==''?json_decode($json,true):null;
    if(!is_array($payload)||($payload['v']??null)!==1)throw new RuntimeException('Scheduling confirmation token is invalid.');
    if((int)($payload['exp']??0)<time())throw new RuntimeException('Scheduling confirmation expired. Prepare the action again.');
    $checks=[
        (int)($payload['owner_user_id']??0)===(int)$context['owner_user_id'],
        hash_equals((string)($payload['profile_username']??''),(string)$context['profile_username']),
        hash_equals((string)($payload['surface']??''),(string)$context['surface']),
        (int)($payload['property_id']??0)===(int)($context['property_id']??0),
        hash_equals((string)($payload['session_hash']??''),(string)$context['session_hash']),
        hash_equals((string)($payload['origin_hash']??''),(string)($context['origin_hash']??'')),
        hash_equals((string)($payload['operation']??''),$operation),
        hash_equals((string)($payload['payload_hash']??''),vp3_profile_webmcp_payload_hash_v150($intent)),
    ];
    if(in_array(false,$checks,true))throw new RuntimeException('Scheduling confirmation does not match this session or action.');
    if(!preg_match('/^[a-f0-9]{32}$/',(string)($payload['intent_id']??'')))throw new RuntimeException('Scheduling confirmation token is invalid.');
    return $payload;
}

function vp3_profile_webmcp_scheduling_event_v150(PDO $pdo,array $profile,array $input): array
{
    $owner=(int)$profile['user_id'];
    $eventId=max(0,(int)($input['event_type_id']??0));
    $slug=agent_scheduling_slug_v430((string)($input['event_slug']??''));
    $event=$eventId>0?agent_scheduling_public_event_for_owner_v450($pdo,$owner,$eventId):($slug!==''?agent_scheduling_public_event_by_slug_v450($pdo,$owner,$slug):null);
    if(!$event)throw new RuntimeException('This appointment type is not available for public booking.');
    return $event;
}

function vp3_profile_webmcp_scheduling_event_projection_v150(PDO $pdo,array $profile,array $event): array
{
    $paymentRequired=false;$amount=0;$currency='';
    if(function_exists('agent_paid_appointments_schema_ready_v800')&&agent_paid_appointments_schema_ready_v800($pdo)){
        try{
            $terms=agent_paid_appointments_event_terms_v800($pdo,(int)$event['id']);
            $amount=agent_paid_appointments_amount_due_v800($terms);
            $paymentRequired=$amount>0;
            $currency=(string)($terms['currency']??'');
        }catch(Throwable $e){$paymentRequired=false;$amount=0;$currency='';}
    }
    return [
        'event_type_id'=>(int)$event['id'],
        'slug'=>(string)$event['slug'],
        'title'=>(string)$event['title'],
        'description'=>trim((string)($event['description']??'')),
        'duration_minutes'=>(int)$event['duration_minutes'],
        'timezone'=>(string)$event['schedule_timezone'],
        'location_type'=>(string)($event['location_type']??'virtual'),
        'payment_required'=>$paymentRequired,
        'amount_due_cents'=>$paymentRequired?$amount:0,
        'currency'=>$paymentRequired?$currency:'',
        'booking_url'=>agent_scheduling_public_booking_url_v450((string)$profile['username'],(string)$event['slug']),
    ];
}

function vp3_profile_webmcp_scheduling_options_v150(PDO $pdo,array $profile): array
{
    $schedule=agent_scheduling_public_schedule_v450($pdo,(int)$profile['user_id']);
    if(!$schedule)return [];
    $out=[];
    foreach(agent_scheduling_public_events_v450($pdo,(int)$schedule['id']) as $event){
        $public=agent_scheduling_public_event_for_owner_v450($pdo,(int)$profile['user_id'],(int)$event['id']);
        if($public)$out[]=vp3_profile_webmcp_scheduling_event_projection_v150($pdo,$profile,$public);
    }
    return $out;
}

function vp3_profile_webmcp_scheduling_availability_v150(PDO $pdo,array $profile,array $input): array
{
    $event=vp3_profile_webmcp_scheduling_event_v150($pdo,$profile,$input);
    $date=trim((string)($input['date']??''));
    if(!preg_match('/^\d{4}-\d{2}-\d{2}$/',$date))throw new RuntimeException('Choose a valid availability date.');
    $guestTimezone=agent_scheduling_timezone_v430((string)($input['timezone']??$event['schedule_timezone']),(string)$event['schedule_timezone']);
    $slots=[];
    foreach(array_slice(agent_scheduling_slots_for_date_v430($pdo,(int)$event['id'],$date,true),0,96) as $slot){
        $start=new DateTimeImmutable((string)$slot['start_at_utc'],new DateTimeZone('UTC'));
        $end=new DateTimeImmutable((string)$slot['end_at_utc'],new DateTimeZone('UTC'));
        $tz=new DateTimeZone($guestTimezone);
        $slots[]=[
            'start_at_utc'=>$start->format('Y-m-d H:i:s'),
            'end_at_utc'=>$end->format('Y-m-d H:i:s'),
            'start_local'=>$start->setTimezone($tz)->format('Y-m-d H:i:s'),
            'end_local'=>$end->setTimezone($tz)->format('Y-m-d H:i:s'),
            'timezone'=>$guestTimezone,
        ];
    }
    return ['event'=>vp3_profile_webmcp_scheduling_event_projection_v150($pdo,$profile,$event),'date'=>$date,'timezone'=>$guestTimezone,'slots'=>$slots];
}

function vp3_profile_webmcp_booking_projection_v150(array $booking): array
{
    return [
        'booking_id'=>(int)$booking['id'],
        'event_type_id'=>(int)($booking['event_type_id']??0),
        'event_title'=>(string)$booking['event_title'],
        'start_at_utc'=>(string)$booking['start_at_utc'],
        'end_at_utc'=>(string)$booking['end_at_utc'],
        'organizer_timezone'=>(string)$booking['organizer_timezone'],
        'guest_timezone'=>(string)$booking['guest_timezone'],
        'guest_name'=>(string)$booking['guest_name'],
        'guest_email'=>(string)$booking['guest_email'],
        'status'=>(string)$booking['status'],
        'location_type'=>(string)($booking['location_type']??'virtual'),
        'public_token'=>(string)$booking['public_token'],
        'calendar_url'=>agent_scheduling_public_calendar_url_v450((string)$booking['public_token']),
    ];
}

function vp3_profile_webmcp_booking_response_v150(PDO $pdo,array $profile,array $booking,bool $includeManage=true,?array $snapshot=null): array
{
    $current=vp3_profile_webmcp_booking_projection_v150($booking);
    $projection=$snapshot?array_merge($current,$snapshot):$current;
    $paid=null;$paymentUrl='';
    if(function_exists('agent_paid_appointments_schema_ready_v800')&&agent_paid_appointments_schema_ready_v800($pdo)){
        $paid=agent_paid_appointments_paid_booking_for_booking_v800($pdo,(int)$booking['id']);
        if($paid)$paymentUrl=agent_paid_appointments_payment_url_v800($pdo,$paid);
    }
    return [
        'booking'=>$projection,
        'manage_token'=>$includeManage?(string)$booking['cancel_token']:'',
        'manage_url'=>$includeManage?agent_scheduling_public_manage_url_v450((string)$profile['username'],(string)$booking['cancel_token']):'',
        'payment_required'=>(bool)$paid,
        'payment_status'=>$paid?(string)($paid['payment_status']??'awaiting_payment'):'',
        'payment_url'=>$paid?$paymentUrl:'',
    ];
}

function vp3_profile_webmcp_booking_by_id_v150(PDO $pdo,int $ownerUserId,int $bookingId): ?array
{
    if($ownerUserId<1||$bookingId<1)return null;
    $stmt=$pdo->prepare('SELECT * FROM agent_scheduling_bookings WHERE id=? AND owner_user_id=? LIMIT 1');
    $stmt->execute([$bookingId,$ownerUserId]);
    return $stmt->fetch()?:null;
}

function vp3_profile_webmcp_booking_state_hash_v150(array $booking): string
{
    return hash('sha256',implode('|',[
        (int)$booking['id'],(string)$booking['status'],(string)$booking['start_at_utc'],
        (string)$booking['end_at_utc'],(string)($booking['updated_at']??''),(string)$booking['cancel_token']
    ]));
}

function vp3_profile_webmcp_event_state_hash_v150(array $event): string
{
    return hash('sha256',implode('|',[
        (int)$event['id'],(int)$event['schedule_id'],(string)$event['slug'],(string)$event['title'],
        (int)$event['duration_minutes'],(int)$event['minimum_notice_minutes'],(int)$event['booking_window_days'],
        (int)$event['is_active'],(int)$event['schedule_active'],(int)$event['public_enabled'],(string)($event['updated_at']??'')
    ]));
}

function vp3_profile_webmcp_scheduling_normalize_create_v150(PDO $pdo,array $profile,array $input): array
{
    $event=vp3_profile_webmcp_scheduling_event_v150($pdo,$profile,$input);
    $start=trim((string)($input['start_at_utc']??''));
    try{$startObj=(new DateTimeImmutable($start,new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('UTC'));}catch(Throwable $e){throw new RuntimeException('Choose a valid appointment time.');}
    agent_scheduling_validate_start_v430($pdo,$event,$startObj);
    $guestName=trim(preg_replace('/\s+/u',' ',(string)($input['guest_name']??''))??'');
    $guestEmail=strtolower(trim((string)($input['guest_email']??'')));
    if($guestName==='')throw new RuntimeException('Enter the attendee name.');
    if($guestEmail===''||!filter_var($guestEmail,FILTER_VALIDATE_EMAIL))throw new RuntimeException('Enter a valid attendee email.');
    $guestTimezone=agent_scheduling_timezone_v430((string)($input['guest_timezone']??$event['schedule_timezone']),(string)$event['schedule_timezone']);
    $intake=is_array($input['intake']??null)?$input['intake']:[];
    if(function_exists('agent_appointment_lifecycle_schema_ready_v700')&&agent_appointment_lifecycle_schema_ready_v700($pdo)
       && function_exists('agent_appointment_lifecycle_validate_intake_v700')){
        agent_appointment_lifecycle_validate_intake_v700($pdo,(int)$event['id'],$intake);
    }
    return [
        'event_type_id'=>(int)$event['id'],
        'event_state_hash'=>vp3_profile_webmcp_event_state_hash_v150($event),
        'start_at_utc'=>$startObj->format('Y-m-d H:i:s'),
        'guest_timezone'=>$guestTimezone,
        'guest_name'=>mb_strimwidth($guestName,0,190,''),
        'guest_email'=>mb_strimwidth($guestEmail,0,190,''),
        'guest_phone'=>mb_strimwidth(trim((string)($input['guest_phone']??'')),0,80,''),
        'guest_notes'=>mb_strimwidth(trim((string)($input['guest_notes']??'')),0,2000,''),
        'intake'=>$intake,
    ];
}

function vp3_profile_webmcp_scheduling_managed_booking_v150(PDO $pdo,array $profile,array $input): array
{
    $token=strtolower(trim((string)($input['manage_token']??'')));
    if(!preg_match('/^[a-f0-9]{64}$/',$token))throw new RuntimeException('A valid booking manage token is required.');
    $booking=agent_scheduling_booking_by_cancel_token_v450($pdo,$token);
    if(!$booking||(int)$booking['owner_user_id']!==(int)$profile['user_id'])throw new RuntimeException('Booking not found for this profile.');
    return $booking;
}

function vp3_profile_webmcp_scheduling_normalize_reschedule_v150(PDO $pdo,array $profile,array $input): array
{
    $booking=vp3_profile_webmcp_scheduling_managed_booking_v150($pdo,$profile,$input);
    if(!in_array((string)$booking['status'],['pending','confirmed'],true))throw new RuntimeException('Only an active booking can be rescheduled.');
    $event=agent_scheduling_public_event_for_owner_v450($pdo,(int)$profile['user_id'],(int)$booking['event_type_id']);
    if(!$event)throw new RuntimeException('This appointment type is no longer open for public booking.');
    $start=trim((string)($input['start_at_utc']??''));
    try{$startObj=(new DateTimeImmutable($start,new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('UTC'));}catch(Throwable $e){throw new RuntimeException('Choose a valid new appointment time.');}
    $guestTimezone=agent_scheduling_timezone_v430((string)($input['guest_timezone']??$booking['guest_timezone']),(string)$booking['guest_timezone']);
    if(function_exists('agent_appointment_lifecycle_schema_ready_v700')&&agent_appointment_lifecycle_schema_ready_v700($pdo)
       && function_exists('agent_appointment_lifecycle_validate_reschedule_v700')){
        agent_appointment_lifecycle_validate_reschedule_v700($pdo,$event,$booking,$startObj);
    }
    return [
        'booking_id'=>(int)$booking['id'],
        'booking_state_hash'=>vp3_profile_webmcp_booking_state_hash_v150($booking),
        'manage_token'=>(string)$booking['cancel_token'],
        'start_at_utc'=>$startObj->format('Y-m-d H:i:s'),
        'guest_timezone'=>$guestTimezone,
    ];
}

function vp3_profile_webmcp_scheduling_normalize_cancel_v150(PDO $pdo,array $profile,array $input): array
{
    $booking=vp3_profile_webmcp_scheduling_managed_booking_v150($pdo,$profile,$input);
    if(!in_array((string)$booking['status'],['pending','confirmed'],true))throw new RuntimeException('Only an active booking can be cancelled.');
    return [
        'booking_id'=>(int)$booking['id'],
        'booking_state_hash'=>vp3_profile_webmcp_booking_state_hash_v150($booking),
        'manage_token'=>(string)$booking['cancel_token'],
    ];
}

function vp3_profile_webmcp_scheduling_prepare_v150(PDO $pdo,array $profile,array $context,string $operation,array $input): array
{
    $intent=match($operation){
        'booking.create'=>vp3_profile_webmcp_scheduling_normalize_create_v150($pdo,$profile,$input),
        'booking.reschedule'=>vp3_profile_webmcp_scheduling_normalize_reschedule_v150($pdo,$profile,$input),
        'booking.cancel'=>vp3_profile_webmcp_scheduling_normalize_cancel_v150($pdo,$profile,$input),
        default=>throw new RuntimeException('Unsupported scheduling action.'),
    };
    $action=vp3_profile_webmcp_action_prepare_v150($pdo,$context,$operation,$intent,VP3_PROFILE_WEBMCP_SCHEDULING_INTENT_TTL_V150);
    $token=vp3_profile_webmcp_scheduling_token_v150($action,$context);
    $preview=['operation'=>$operation];
    if($operation==='booking.create'){
        $event=agent_scheduling_public_event_for_owner_v450($pdo,(int)$profile['user_id'],(int)$intent['event_type_id']);
        $preview+=['event'=>vp3_profile_webmcp_scheduling_event_projection_v150($pdo,$profile,$event),'start_at_utc'=>$intent['start_at_utc'],'guest_timezone'=>$intent['guest_timezone'],'guest_name'=>$intent['guest_name'],'guest_email'=>$intent['guest_email']];
    }else{
        $booking=vp3_profile_webmcp_booking_by_id_v150($pdo,(int)$profile['user_id'],(int)$intent['booking_id']);
        $preview+=['booking'=>vp3_profile_webmcp_booking_projection_v150($booking)];
        if($operation==='booking.reschedule')$preview+=['new_start_at_utc'=>$intent['start_at_utc'],'guest_timezone'=>$intent['guest_timezone']];
    }
    return [
        'intent_id'=>$action['intent_id'],
        'confirmation_token'=>$token,
        'expires_at_unix'=>$action['expires_at_unix'],
        'intent'=>$intent,
        'preview'=>$preview,
        'confirmation_required'=>true,
    ];
}

function vp3_profile_webmcp_scheduling_replay_v150(PDO $pdo,array $profile,array $action): array
{
    $booking=vp3_profile_webmcp_booking_by_id_v150($pdo,(int)$profile['user_id'],(int)($action['result_id']??0));
    if(!$booking)throw new RuntimeException('The committed booking result is no longer available.');
    $stored=json_decode((string)($action['result_json']??''),true);
    $snapshot=is_array($stored['booking']??null)?$stored['booking']:null;
    $response=vp3_profile_webmcp_booking_response_v150($pdo,$profile,$booking,true,$snapshot);
    $response['idempotent_replay']=true;
    return $response;
}

function vp3_profile_webmcp_scheduling_create_commit_v150(PDO $pdo,array $profile,array $intent): array
{
    $event=agent_scheduling_public_event_for_owner_v450($pdo,(int)$profile['user_id'],(int)$intent['event_type_id']);
    if(!$event||!hash_equals((string)$intent['event_state_hash'],vp3_profile_webmcp_event_state_hash_v150($event)))throw new RuntimeException('This appointment type changed. Prepare the booking again.');
    $startObj=new DateTimeImmutable((string)$intent['start_at_utc'],new DateTimeZone('UTC'));
    agent_scheduling_validate_start_v430($pdo,$event,$startObj);

    $booking=agent_scheduling_create_booking_v430($pdo,[
        'event_type_id'=>(int)$event['id'],
        'start_at_utc'=>(string)$intent['start_at_utc'],
        'guest_timezone'=>(string)$intent['guest_timezone'],
        'guest_name'=>(string)$intent['guest_name'],
        'guest_email'=>(string)$intent['guest_email'],
        'guest_phone'=>(string)$intent['guest_phone'],
        'guest_notes'=>(string)$intent['guest_notes'],
        'source'=>'webmcp',
    ]);

    $lifecycleReady=function_exists('agent_appointment_lifecycle_schema_ready_v700')&&agent_appointment_lifecycle_schema_ready_v700($pdo);
    $paidReady=$lifecycleReady&&function_exists('agent_paid_appointments_schema_ready_v800')&&agent_paid_appointments_schema_ready_v800($pdo);
    $paid=null;
    if($lifecycleReady){
        $booking=agent_appointment_lifecycle_booking_v700($pdo,(int)$booking['id'],(int)$profile['user_id'])?:$booking;
        try{
            if(!empty($intent['intake'])&&function_exists('agent_appointment_lifecycle_capture_intake_v700'))agent_appointment_lifecycle_capture_intake_v700($pdo,$booking,(array)$intent['intake']);
            $paid=$paidReady?agent_paid_appointments_create_personal_v800($pdo,$booking):null;
        }catch(Throwable $e){
            throw $e;
        }
        if(!$paid){
            agent_appointment_lifecycle_event_v700($pdo,$booking,'confirmed','','confirmed','guest',null,null,['source'=>'webmcp']);
            agent_appointment_lifecycle_queue_booking_v700($pdo,$booking,true);
        }
    }
    if(function_exists('profile_conversion_booking_confirmed_v179')&&!$paid){
        try{profile_conversion_booking_confirmed_v179($pdo,$profile,$booking,$event);}catch(Throwable $ignored){}
    }
    return vp3_profile_webmcp_booking_response_v150($pdo,$profile,$booking,true);
}

function vp3_profile_webmcp_scheduling_reschedule_commit_v150(PDO $pdo,array $profile,array $intent): array
{
    $booking=vp3_profile_webmcp_booking_by_id_v150($pdo,(int)$profile['user_id'],(int)$intent['booking_id']);
    if(!$booking||!hash_equals((string)$intent['manage_token'],(string)$booking['cancel_token']))throw new RuntimeException('Booking management authority changed. Prepare the action again.');
    if(!hash_equals((string)$intent['booking_state_hash'],vp3_profile_webmcp_booking_state_hash_v150($booking)))throw new RuntimeException('This booking changed. Prepare the reschedule again.');
    $paid=(function_exists('agent_paid_appointments_schema_ready_v800')&&agent_paid_appointments_schema_ready_v800($pdo))?agent_paid_appointments_paid_booking_for_booking_v800($pdo,(int)$booking['id']):null;
    if($paid&&(string)$paid['payment_status']==='awaiting_payment')throw new RuntimeException('Complete or cancel the pending appointment payment before rescheduling.');
    if(function_exists('agent_appointment_lifecycle_schema_ready_v700')&&agent_appointment_lifecycle_schema_ready_v700($pdo)){
        $booking=agent_appointment_lifecycle_booking_v700($pdo,(int)$booking['id'],(int)$profile['user_id'])?:$booking;
        $new=agent_appointment_lifecycle_reschedule_v700($pdo,$booking,(string)$intent['start_at_utc'],(string)$intent['guest_timezone'],'guest');
        if($paid)agent_paid_appointments_record_reschedule_v800($pdo,$paid,'guest');
    }else{
        $new=agent_scheduling_public_reschedule_v450($pdo,$booking,(string)$intent['manage_token'],(string)$intent['start_at_utc'],(string)$intent['guest_timezone']);
    }
    return vp3_profile_webmcp_booking_response_v150($pdo,$profile,$new,true);
}

function vp3_profile_webmcp_scheduling_cancel_commit_v150(PDO $pdo,array $profile,array $intent): array
{
    $booking=vp3_profile_webmcp_booking_by_id_v150($pdo,(int)$profile['user_id'],(int)$intent['booking_id']);
    if(!$booking||!hash_equals((string)$intent['manage_token'],(string)$booking['cancel_token']))throw new RuntimeException('Booking management authority changed. Prepare the action again.');
    if(!hash_equals((string)$intent['booking_state_hash'],vp3_profile_webmcp_booking_state_hash_v150($booking)))throw new RuntimeException('This booking changed. Prepare the cancellation again.');
    $paid=(function_exists('agent_paid_appointments_schema_ready_v800')&&agent_paid_appointments_schema_ready_v800($pdo))?agent_paid_appointments_paid_booking_for_booking_v800($pdo,(int)$booking['id']):null;
    if(function_exists('agent_appointment_lifecycle_schema_ready_v700')&&agent_appointment_lifecycle_schema_ready_v700($pdo)){
        $life=agent_appointment_lifecycle_booking_v700($pdo,(int)$booking['id'],(int)$profile['user_id'])?:$booking;
        $cancelled=agent_appointment_lifecycle_transition_v700($pdo,$life,'cancelled','guest',null,null,['source'=>'webmcp']);
        if($paid)agent_paid_appointments_record_cancellation_v800($pdo,$paid,'guest',null,null,'webmcp_cancelled');
    }else{
        if(!agent_scheduling_cancel_booking_v430($pdo,(int)$booking['id'],null,(string)$intent['manage_token']))throw new RuntimeException('This appointment could not be cancelled.');
        $cancelled=vp3_profile_webmcp_booking_by_id_v150($pdo,(int)$profile['user_id'],(int)$booking['id'])?:$booking;
    }
    return vp3_profile_webmcp_booking_response_v150($pdo,$profile,$cancelled,true);
}

function vp3_profile_webmcp_scheduling_confirm_v150(
    PDO $pdo,array $profile,array $context,string $operation,array $intent,string $confirmationToken,string $idempotencyKey
): array {
    $verified=vp3_profile_webmcp_scheduling_token_verify_v150($confirmationToken,$context,$operation,$intent);
    $owner=(int)$profile['user_id'];
    $intentId=(string)$verified['intent_id'];
    $idem=vp3_profile_webmcp_idempotency_hash_v150($owner,$operation,$idempotencyKey);

    $started=!$pdo->inTransaction();
    try{
        if($started)$pdo->beginTransaction();
        $action=vp3_profile_webmcp_action_row_v150($pdo,$owner,$intentId,true);
        if(!$action)throw new RuntimeException('Prepared scheduling action was not found.');
        if(!hash_equals((string)$action['payload_hash'],vp3_profile_webmcp_payload_hash_v150($intent)))throw new RuntimeException('Prepared scheduling payload changed.');
        if(!hash_equals((string)$action['session_hash'],(string)$context['session_hash'])||(string)$action['surface']!==(string)$context['surface']||(int)($action['property_id']??0)!==(int)($context['property_id']??0)){
            throw new RuntimeException('Prepared scheduling action belongs to a different WebMCP session.');
        }
        if((string)$action['state']==='committed'){
            if(!hash_equals((string)$action['idempotency_hash'],$idem))throw new RuntimeException('This scheduling intent was already confirmed with a different idempotency key.');
            $result=vp3_profile_webmcp_scheduling_replay_v150($pdo,$profile,$action);
            if($started)$pdo->commit();
            return $result;
        }
        if((string)$action['state']!=='prepared')throw new RuntimeException('Prepared scheduling action is no longer executable.');
        if(strtotime((string)$action['expires_at'])<time())throw new RuntimeException('Scheduling confirmation expired. Prepare the action again.');

        $existing=vp3_profile_webmcp_action_by_idempotency_v150($pdo,$owner,$operation,$idem,true);
        if($existing&&(int)$existing['id']!==(int)$action['id']){
            if(!hash_equals((string)$existing['payload_hash'],(string)$action['payload_hash']))throw new RuntimeException('Idempotency key was already used for a different scheduling action.');
            if((string)$existing['state']!=='committed')throw new RuntimeException('That idempotent scheduling action is still in progress.');
            $result=vp3_profile_webmcp_scheduling_replay_v150($pdo,$profile,$existing);
            if($started)$pdo->commit();
            return $result;
        }

        $claim=$pdo->prepare('UPDATE profile_webmcp_actions SET idempotency_hash=?,updated_at=UTC_TIMESTAMP() WHERE id=? AND state=\'prepared\' AND idempotency_hash IS NULL');
        $claim->execute([$idem,(int)$action['id']]);
        if($claim->rowCount()!==1&& !hash_equals((string)($action['idempotency_hash']??''),$idem))throw new RuntimeException('Scheduling idempotency claim changed before confirmation.');

        $result=match($operation){
            'booking.create'=>vp3_profile_webmcp_scheduling_create_commit_v150($pdo,$profile,$intent),
            'booking.reschedule'=>vp3_profile_webmcp_scheduling_reschedule_commit_v150($pdo,$profile,$intent),
            'booking.cancel'=>vp3_profile_webmcp_scheduling_cancel_commit_v150($pdo,$profile,$intent),
            default=>throw new RuntimeException('Unsupported scheduling action.'),
        };
        $bookingId=(int)($result['booking']['booking_id']??0);
        vp3_profile_webmcp_action_commit_v150($pdo,(int)$action['id'],$idem,'booking',$bookingId,$result);
        if($started)$pdo->commit();
        return $result;
    }catch(Throwable $e){
        if($started&&$pdo->inTransaction())$pdo->rollBack();
        throw $e;
    }
}

function vp3_profile_webmcp_scheduling_get_v150(PDO $pdo,array $profile,array $input): array
{
    $token=strtolower(trim((string)($input['public_token']??'')));
    if(!preg_match('/^[a-f0-9]{64}$/',$token))throw new RuntimeException('A valid booking public token is required.');
    $booking=agent_scheduling_booking_by_public_token_v450($pdo,$token);
    if(!$booking||(int)$booking['owner_user_id']!==(int)$profile['user_id'])throw new RuntimeException('Booking not found for this profile.');
    return vp3_profile_webmcp_booking_response_v150($pdo,$profile,$booking,false);
}
