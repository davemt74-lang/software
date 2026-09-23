<?php
declare(strict_types=1);

/**
 * VP3 Cognitive Runtime v23.80 — Domain Integration I.
 *
 * Bridges owner-scoped operational domains into the canonical Agent event inbox
 * and Cognitive Runtime without creating a second ledger, Brain, CRM, calendar,
 * booking store, commerce store, notification system or execution authority.
 */
const VP3_COGNITIVE_DOMAIN_INTEGRATION_V2380='vp3-cognitive-domain-integration-v2380-20260922';

function vp3_cognitive_domain_event_types_v2380(): array
{
    return [
        'profile_agent'=>[
            'profile.visited','profile.return_visitor','profile.conversation_started',
            'profile.message_received','profile.message_sent','profile.booking_intent',
            'profile.product_intent','profile.booking_converted','profile.product_converted',
        ],
        'scheduling'=>['schedule.created','schedule.updated','availability.changed'],
        'booking_appointments'=>[
            'booking.created','booking.confirmed','booking.rescheduled','booking.cancelled',
            'booking.completed','booking.no_show','appointment.payment_changed','appointment.followup_due',
        ],
        'calendar'=>[
            'calendar.event_created','calendar.event_updated','calendar.event_cancelled',
            'calendar.event_starting','calendar.conflict_detected','calendar.commitment_overdue',
        ],
        'commerce'=>[
            'product.created','product.updated','product.published','product.unpublished',
            'order.created','order.payment_received','order.paid','order.fulfillment_started',
            'order.fulfilled','order.cancelled','order.expired','refund.requested',
            'refund.completed','refund.failed','customer.returned','sale.converted',
        ],
        'crm_relationships'=>[
            'contact.created','contact.updated','lead.created','lead.stage_changed',
            'followup.due','message.awaiting_reply','relationship.opportunity_changed','relationship.risk_changed',
        ],
        'notifications'=>['notification.created','notification.read','notification.dismissed'],
    ];
}

function vp3_cognitive_domain_event_allowed_v2380(string $source,string $eventType): bool
{
    $catalog=vp3_cognitive_domain_event_types_v2380();
    return isset($catalog[$source])&&in_array($eventType,$catalog[$source],true);
}

function vp3_cognitive_domain_ref_v2380(string $type,mixed $id,string $scope='personal'): array
{
    $type=preg_replace('/[^a-z0-9._:-]/','',strtolower(trim($type)))??'';
    $id=mb_strimwidth(trim((string)$id),0,190,'');
    if($type===''||$id==='')return [];
    if(function_exists('vp3_cognitive_object_ref_v500')){
        try{return vp3_cognitive_object_ref_v500($type,$id,$scope);}catch(Throwable $e){}
    }
    return ['type'=>$type,'id'=>$id,'scope'=>$scope];
}

function vp3_cognitive_domain_note_live_session_v2380(PDO $pdo,int $ownerUserId,string $eventType,array $objectRefs=[]): void
{
    if($ownerUserId<1||!function_exists('vp3_live_session_open_row_v2370')||!function_exists('vp3_live_session_schema_ready_v2370'))return;
    try{
        if(!vp3_live_session_schema_ready_v2370($pdo))return;
        $session=vp3_live_session_open_row_v2370($pdo,$ownerUserId);
        if(!$session)return;
        $actions=json_decode((string)($session['last_actions_json']??''),true);
        if(!is_array($actions))$actions=[];
        $primary='';
        if(is_array($objectRefs[0]??null))$primary=trim((string)($objectRefs[0]['type']??'')).':'.trim((string)($objectRefs[0]['id']??''));
        $summary=mb_strimwidth($eventType.($primary!==':'&&$primary!==''?' · '.$primary:''),0,500,'');
        $actions['external']=['event_type'=>$eventType,'summary'=>$summary,'at'=>gmdate('c')];
        $actions=array_intersect_key($actions,array_flip(['user','agent','tool','browser','external']));
        $pdo->prepare("UPDATE agent_live_sessions_v2370 SET last_external_event=?,last_actions_json=?,last_action_at=UTC_TIMESTAMP(),last_heartbeat_at=UTC_TIMESTAMP() WHERE id=? AND owner_user_id=?")
            ->execute([$summary,json_encode($actions,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE),(int)$session['id'],$ownerUserId]);
    }catch(Throwable $e){
        error_log('VP3 v23.80 live-session domain projection failed: '.$e->getMessage());
    }
}

function vp3_cognitive_domain_record_v2380(
    PDO $pdo,int $ownerUserId,string $source,string $eventType,array $objectRefs=[],
    array $payload=[],array $options=[]
): ?array {
    if($ownerUserId<1||!vp3_cognitive_domain_event_allowed_v2380($source,$eventType))return null;
    if(!function_exists('agent_event_ingest_v1920')||!function_exists('agent_event_schema_ready_v1920')||!agent_event_schema_ready_v1920($pdo))return null;
    $refs=[];
    foreach(array_slice($objectRefs,0,16) as $ref){
        if(!is_array($ref)||empty($ref['type'])||empty($ref['id']))continue;
        $refs[]=['type'=>(string)$ref['type'],'id'=>(string)$ref['id'],'scope'=>(string)($ref['scope']??'personal')];
    }
    $safePayload=[
        'object_refs'=>$refs,
        'domain_source'=>$source,
        'record_only'=>true,
        'brain_promotion_deferred'=>true,
    ]+$payload;
    $external=trim((string)($options['external_event_id']??''));
    if($external===''){
        $seed=$source.'|'.$eventType.'|'.json_encode($refs,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE).'|'.sprintf('%.6F',microtime(true));
        $external='vp3-v2380-'.substr(hash('sha256',$seed),0,48);
    }
    try{
        $ingest=agent_event_ingest_v1920($pdo,$ownerUserId,$source,$eventType,$safePayload,[
            'verification_status'=>'trusted',
            'external_event_id'=>mb_strimwidth($external,0,190,''),
            'occurred_at'=>$options['occurred_at']??gmdate('c'),
            'correlation_id'=>mb_strimwidth(trim((string)($options['correlation_id']??'')),0,120,''),
            'causation_id'=>mb_strimwidth(trim((string)($options['causation_id']??'')),0,120,''),
        ]);
        if(empty($ingest['duplicate'])&&is_array($ingest['event']??null)){
            $eventId=(int)($ingest['event']['id']??0);
            if($eventId>0)$pdo->prepare("UPDATE agent_event_inbox SET processing_status='processed',processed_at=UTC_TIMESTAMP(),last_error_code='',last_error_message='' WHERE id=? AND owner_user_id=?")
                ->execute([$eventId,$ownerUserId]);
        }
        vp3_cognitive_domain_note_live_session_v2380($pdo,$ownerUserId,$eventType,$refs);
        return $ingest;
    }catch(Throwable $e){
        error_log('VP3 v23.80 domain event record failed ['.$source.' '.$eventType.']: '.$e->getMessage());
        return null;
    }
}

function vp3_cognitive_profile_event_bridge_v2380(PDO $pdo,array $event,array $session,array $metadata=[]): void
{
    $owner=(int)($event['owner_user_id']??0);$eventId=(int)($event['id']??0);$type=(string)($event['event_type']??'');
    $map=[
        'conversation_started'=>'profile.conversation_started',
        'message_received'=>'profile.message_received',
        'message_sent'=>'profile.message_sent',
        'booking_intent'=>'profile.booking_intent',
        'product_intent'=>'profile.product_intent',
        'booking_converted'=>'profile.booking_converted',
        'product_converted'=>'profile.product_converted',
    ];
    if($type==='profile_view')$canonical=!empty($metadata['returning'])?'profile.return_visitor':'profile.visited';
    else $canonical=$map[$type]??'';
    if($owner<1||$eventId<1||$canonical==='')return;
    $refs=[vp3_cognitive_domain_ref_v2380('profile',$owner,'profile_agent')];
    $sessionId=(int)($session['id']??0);if($sessionId>0)$refs[]=vp3_cognitive_domain_ref_v2380('profile_visit',$sessionId,'profile_agent');
    $conversationId=(int)($metadata['conversation_id']??0);if($conversationId>0)$refs[]=vp3_cognitive_domain_ref_v2380('profile_conversation',$conversationId,'profile_agent');
    vp3_cognitive_domain_record_v2380($pdo,$owner,'profile_agent',$canonical,array_values(array_filter($refs)),[
        'profile_event_id'=>$eventId,
        'profile_agent_id'=>max(0,(int)($event['profile_agent_id']??0)),
        'priority'=>max(0,min(100,(int)($event['priority']??0))),
        'conversion_kind'=>in_array((string)($metadata['conversion_kind']??''),['booking','product'],true)?(string)$metadata['conversion_kind']:'',
        'outcome_status'=>mb_strimwidth(trim((string)($metadata['outcome_status']??'')),0,40,''),
        'value_cents'=>max(0,(int)($metadata['value_cents']??0)),
    ],['external_event_id'=>'profile-event:'.$eventId]);
}

function vp3_cognitive_scheduling_event_v2380(PDO $pdo,int $owner,string $eventType,int $scheduleId,?int $eventTypeId=null,array $payload=[]): void
{
    $refs=[vp3_cognitive_domain_ref_v2380('schedule',$scheduleId)];
    if(($eventTypeId??0)>0)$refs[]=vp3_cognitive_domain_ref_v2380('scheduling_type',(int)$eventTypeId);
    vp3_cognitive_domain_record_v2380($pdo,$owner,'scheduling',$eventType,array_values(array_filter($refs)),$payload);
}

function vp3_cognitive_booking_event_v2380(PDO $pdo,array $booking,string $eventType,array $payload=[],string $externalId=''): void
{
    $owner=(int)($booking['owner_user_id']??0);$bookingId=(int)($booking['id']??0);if($owner<1||$bookingId<1)return;
    $refs=[
        vp3_cognitive_domain_ref_v2380('booking',$bookingId),
        vp3_cognitive_domain_ref_v2380('appointment',$bookingId),
        vp3_cognitive_domain_ref_v2380('schedule',(int)($booking['schedule_id']??0)),
    ];
    vp3_cognitive_domain_record_v2380($pdo,$owner,'booking_appointments',$eventType,array_values(array_filter($refs)),[
        'status'=>(string)($booking['status']??''),
        'lifecycle_status'=>(string)($booking['lifecycle_status']??''),
        'start_at_utc'=>(string)($booking['start_at_utc']??''),
        'end_at_utc'=>(string)($booking['end_at_utc']??''),
        'source'=>(string)($booking['source']??''),
    ]+$payload,$externalId!==''?['external_event_id'=>$externalId]:[]);
}

function vp3_cognitive_appointment_lifecycle_bridge_v2380(PDO $pdo,array $booking,string $eventType,string $from,string $to,array $details=[]): void
{
    $canonical='';
    if($eventType==='rescheduled')$canonical='booking.rescheduled';
    elseif($eventType==='status_changed')$canonical=match($to){
        'confirmed'=>'booking.confirmed','cancelled'=>'booking.cancelled','completed'=>'booking.completed','no_show'=>'booking.no_show',default=>''
    };
    elseif($eventType==='followup_due')$canonical='appointment.followup_due';
    elseif($eventType==='payment_changed')$canonical='appointment.payment_changed';
    if($canonical==='')return;
    vp3_cognitive_booking_event_v2380($pdo,$booking,$canonical,[
        'from_status'=>$from,'to_status'=>$to,
        'details'=>array_intersect_key($details,array_flip(['reason','source','payment_status','message_status'])),
    ],'appointment-lifecycle:'.(int)$booking['id'].':'.$eventType.':'.$to.':'.substr(hash('sha256',json_encode($details)),0,16));
}

function vp3_cognitive_calendar_event_v2380(PDO $pdo,int $owner,string $eventType,array $event,array $payload=[]): void
{
    $id=(int)($event['id']??0);if($owner<1||$id<1)return;
    vp3_cognitive_domain_record_v2380($pdo,$owner,'calendar',$eventType,[vp3_cognitive_domain_ref_v2380('calendar_event',$id)],[
        'title'=>mb_strimwidth(trim((string)($event['title']??'')),0,190,''),
        'start_at_utc'=>(string)($event['start_at_utc']??''),
        'end_at_utc'=>(string)($event['end_at_utc']??''),
        'status'=>(string)($event['status']??''),
        'source'=>(string)($event['source']??''),
    ]+$payload,['external_event_id'=>'calendar:'.$id.':'.$eventType.':'.substr(hash('sha256',(string)($event['updated_at']??$event['cancelled_at']??$event['created_at']??microtime(true))),0,16)]);
}

function vp3_cognitive_commerce_audit_bridge_v2380(
    PDO $pdo,?int $orderId,int $owner,string $auditType,string $from,string $to,int $amountCents,array $metadata=[],int $auditId=0
): void {
    $map=[
        'order_created'=>'order.created',
        'deposit_completed'=>'order.payment_received',
        'payment_completed'=>'order.paid',
        'payment_hold_expired'=>'order.expired',
        'refund_requested'=>'refund.requested',
        'refund_completed'=>'refund.completed',
        'refund_reconciled'=>'refund.completed',
        'refund_failed'=>'refund.failed',
    ];
    $eventType=$map[$auditType]??'';if($eventType===''||$owner<1)return;
    $refs=[];if(($orderId??0)>0)$refs[]=vp3_cognitive_domain_ref_v2380('commerce_order',(int)$orderId);
    if((int)($metadata['product_id']??0)>0)$refs[]=vp3_cognitive_domain_ref_v2380('product',(int)$metadata['product_id']);
    vp3_cognitive_domain_record_v2380($pdo,$owner,'commerce',$eventType,array_values(array_filter($refs)),[
        'audit_type'=>$auditType,'from_status'=>$from,'to_status'=>$to,'amount_cents'=>max(0,$amountCents),
        'balance_due_cents'=>max(0,(int)($metadata['balance_due_cents']??0)),
    ],$auditId>0?['external_event_id'=>'commerce-audit:'.$auditId]:[]);
}

function vp3_cognitive_crm_relationship_bridge_v2380(PDO $pdo,array $contact,array $before,array $after): void
{
    $owner=(int)($contact['owner_user_id']??0);$id=(int)($contact['id']??0);if($owner<1||$id<1)return;
    $refs=[vp3_cognitive_domain_ref_v2380('contact',$id),vp3_cognitive_domain_ref_v2380('relationship',$id)];
    $beforeOpportunity=(int)($before['opportunity_score']??0);$afterOpportunity=(int)($after['opportunity_score']??0);
    if($beforeOpportunity!==$afterOpportunity){
        vp3_cognitive_domain_record_v2380($pdo,$owner,'crm_relationships','relationship.opportunity_changed',array_values(array_filter($refs)),[
            'from_score'=>$beforeOpportunity,'to_score'=>$afterOpportunity,
            'intent'=>(string)($after['intent']??''),
            'relationship_status'=>(string)($after['relationship_status']??''),
        ],['external_event_id'=>'relationship-opportunity:'.$id.':'.$afterOpportunity.':'.substr(hash('sha256',json_encode($after)),0,12)]);
    }
    $beforeRisk=(int)($before['risk_score']??($contact['risk_score']??0));$afterRisk=(int)($after['risk_score']??($contact['risk_score']??0));
    if($beforeRisk!==$afterRisk){
        vp3_cognitive_domain_record_v2380($pdo,$owner,'crm_relationships','relationship.risk_changed',array_values(array_filter($refs)),[
            'from_score'=>$beforeRisk,'to_score'=>$afterRisk,
        ]);
    }
}

function vp3_cognitive_notification_event_v2380(PDO $pdo,int $owner,string $eventType,int $notificationId,array $payload=[]): void
{
    if($owner<1||$notificationId<1)return;
    vp3_cognitive_domain_record_v2380($pdo,$owner,'notifications',$eventType,[vp3_cognitive_domain_ref_v2380('notification',$notificationId)],$payload,[
        'external_event_id'=>'notification:'.$notificationId.':'.$eventType,
    ]);
}

function vp3_cognitive_domain_row_v2380(PDO $pdo,array $user,array $ref): ?array
{
    $uid=(int)($user['id']??0);$type=(string)($ref['type']??'');$id=(string)($ref['id']??'');if($uid<1||$id==='')return null;
    if($type==='profile'){
        if((string)$uid!==$id)return null;
        if(function_exists('profile_for_user'))return profile_for_user($pdo,$uid,false)?:null;
        return ['user_id'=>$uid];
    }
    if($type==='profile_visit'&&ctype_digit($id)){
        $stmt=$pdo->prepare('SELECT id,owner_user_id,identity_disclosed,view_count,first_seen_at,last_seen_at,last_message_at FROM profile_visit_sessions WHERE id=? AND owner_user_id=? LIMIT 1');$stmt->execute([(int)$id,$uid]);return $stmt->fetch()?:null;
    }
    if($type==='profile_conversation'&&ctype_digit($id)){
        $stmt=$pdo->prepare('SELECT id,owner_user_id,profile_agent_id,profile_session_id,status,last_summary,started_at,last_message_at FROM profile_agent_conversations WHERE id=? AND owner_user_id=? LIMIT 1');$stmt->execute([(int)$id,$uid]);return $stmt->fetch()?:null;
    }
    if($type==='schedule'&&ctype_digit($id)&&function_exists('agent_scheduling_schedule_v430'))return agent_scheduling_schedule_v430($pdo,$uid,(int)$id);
    if($type==='scheduling_type'&&ctype_digit($id)){
        $stmt=$pdo->prepare('SELECT e.*,s.owner_user_id,s.name AS schedule_name,s.timezone AS schedule_timezone FROM agent_scheduling_event_types e INNER JOIN agent_scheduling_schedules s ON s.id=e.schedule_id WHERE e.id=? AND s.owner_user_id=? LIMIT 1');$stmt->execute([(int)$id,$uid]);return $stmt->fetch()?:null;
    }
    if(in_array($type,['booking','appointment'],true)&&ctype_digit($id)){
        $stmt=$pdo->prepare('SELECT id,owner_user_id,schedule_id,event_type_id,agent_id,event_title,duration_minutes,start_at_utc,end_at_utc,organizer_timezone,guest_timezone,status,lifecycle_status,location_type,source,completed_at,no_show_at,cancelled_at,rescheduled_at,created_at,updated_at FROM agent_scheduling_bookings WHERE id=? AND owner_user_id=? LIMIT 1');$stmt->execute([(int)$id,$uid]);return $stmt->fetch()?:null;
    }
    if($type==='calendar_event'&&ctype_digit($id)&&function_exists('user_calendar_event_v1300'))return user_calendar_event_v1300($pdo,$uid,(int)$id);
    if($type==='product'&&ctype_digit($id)){
        $stmt=$pdo->prepare('SELECT id,owner_user_id,workspace_owner_user_id,product_key,title,description,product_type,fulfillment_type,payment_mode,price_cents,deposit_cents,currency,provider_mode,is_active,created_at,updated_at FROM agent_commerce_products_v800 WHERE id=? AND owner_user_id=? LIMIT 1');$stmt->execute([(int)$id,$uid]);return $stmt->fetch()?:null;
    }
    if(in_array($type,['commerce_order','sale'],true)&&ctype_digit($id)){
        $stmt=$pdo->prepare('SELECT id,order_number,owner_user_id,workspace_owner_user_id,order_status,payment_status,payment_mode,currency,subtotal_cents,total_cents,amount_paid_cents,amount_due_cents,amount_refunded_cents,fulfillment_type,fulfillment_ref_type,fulfillment_ref_id,hold_expires_at,paid_at,refunded_at,cancelled_at,created_at,updated_at FROM agent_commerce_orders_v800 WHERE id=? AND owner_user_id=? LIMIT 1');$stmt->execute([(int)$id,$uid]);return $stmt->fetch()?:null;
    }
    if($type==='refund'&&ctype_digit($id)){
        $stmt=$pdo->prepare('SELECT r.id,r.order_id,r.provider,r.amount_cents,r.status,r.reason,r.created_at,r.completed_at,r.updated_at,o.owner_user_id FROM agent_commerce_refunds_v800 r INNER JOIN agent_commerce_orders_v800 o ON o.id=r.order_id WHERE r.id=? AND o.owner_user_id=? LIMIT 1');$stmt->execute([(int)$id,$uid]);return $stmt->fetch()?:null;
    }
    if(in_array($type,['contact','relationship'],true)&&ctype_digit($id)&&function_exists('vp3_agent_crm_contact'))return vp3_agent_crm_contact($pdo,$uid,(int)$id);
    if($type==='notification'&&ctype_digit($id)){
        $stmt=$pdo->prepare('SELECT id,user_id,type,title,body,target_url,source_type,source_id,is_read,read_at,created_at FROM notifications WHERE id=? AND user_id=? LIMIT 1');$stmt->execute([(int)$id,$uid]);return $stmt->fetch()?:null;
    }
    return null;
}

function vp3_cognitive_domain_permission_v2380(PDO $pdo,array $user,string $agentNamespace,array $ref,string $operation='read'): bool
{
    if($operation!=='read')return false;
    try{return vp3_cognitive_domain_row_v2380($pdo,$user,$ref)!==null;}catch(Throwable $e){return false;}
}

function vp3_cognitive_domain_context_v2380(PDO $pdo,array $user,string $agentNamespace,array $ref,array $options=[]): array
{
    $row=vp3_cognitive_domain_row_v2380($pdo,$user,$ref);if(!$row)throw new RuntimeException('Cognitive domain object is unavailable.');
    foreach(['guest_email','guest_phone','cancel_token','public_token','access_token_ciphertext','refresh_token_ciphertext','metadata_json','terms_snapshot_json'] as $key)unset($row[$key]);
    return ['record'=>$row,'authority'=>'domain_record','build'=>VP3_COGNITIVE_DOMAIN_INTEGRATION_V2380];
}

function vp3_cognitive_register_domain_module_v2380(string $module,array $objects,array $events,array $freshness=[]): void
{
    if(!function_exists('vp3_cognitive_register_module_v500'))return;
    $registry=vp3_cognitive_registry_storage_v500();if(isset($registry['modules'][$module]))return;
    vp3_cognitive_register_module_v500([
        'module'=>$module,'version'=>'domain-integration-v2380','objects'=>$objects,'events'=>$events,
        'permission_resolver'=>'vp3_cognitive_domain_permission_v2380','context_provider'=>'vp3_cognitive_domain_context_v2380',
        'relationship_provider'=>null,'cards'=>[],'tools'=>[],
        'freshness_policy'=>$freshness?:['default_seconds'=>60],
        'sensitivity_policy'=>['owner_scoped'=>true,'minimal_context'=>true,'raw_event_payloads_exposed'=>false],
        'surfaces'=>['memory','brief','away_digest','notification','ask_user','chat_response'],'voice_safe'=>false,
    ]);
}

function vp3_cognitive_register_domains_v2380(): void
{
    $events=vp3_cognitive_domain_event_types_v2380();
    vp3_cognitive_register_domain_module_v2380('profile_agent',['profile','profile_visit','profile_conversation'],$events['profile_agent'],['default_seconds'=>30]);
    vp3_cognitive_register_domain_module_v2380('scheduling',['schedule','scheduling_type'],$events['scheduling'],['default_seconds'=>60]);
    vp3_cognitive_register_domain_module_v2380('booking_appointments',['booking','appointment'],$events['booking_appointments'],['default_seconds'=>30]);
    vp3_cognitive_register_domain_module_v2380('calendar',['calendar_event'],$events['calendar'],['default_seconds'=>30]);
    vp3_cognitive_register_domain_module_v2380('commerce',['product','commerce_order','sale','refund'],$events['commerce'],['default_seconds'=>30]);
    vp3_cognitive_register_domain_module_v2380('crm_relationships',['contact','relationship'],$events['crm_relationships'],['default_seconds'=>60]);
    vp3_cognitive_register_domain_module_v2380('notifications',['notification'],$events['notifications'],['default_seconds'=>15]);
}

vp3_cognitive_register_domains_v2380();
