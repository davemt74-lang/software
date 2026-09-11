<?php
declare(strict_types=1);

const VP3_AGENT_COMMERCE_APPOINTMENT_ADAPTER_V800='agent-commerce-appointment-adapter-v800-20260911';

function agent_commerce_appointment_adapter_ready_v800(?PDO $pdo=null): bool
{
    $pdo??=db();return $pdo&&agent_commerce_schema_ready_v800($pdo)
        &&table_exists('agent_paid_bookings_v800')
        &&column_exists('agent_paid_bookings_v800','commerce_order_id');
}

function agent_commerce_adapter_booking_v800(PDO $pdo,array $paid): array
{
    if((int)($paid['team_booking_id']??0)>0){
        $stmt=$pdo->prepare('SELECT tb.*,p.name pool_name,p.id pool_id FROM agent_team_scheduling_bookings tb JOIN agent_team_scheduling_pools p ON p.id=tb.pool_id WHERE tb.id=? LIMIT 1');
        $stmt->execute([(int)$paid['team_booking_id']]);return $stmt->fetch()?:[];
    }
    return agent_appointment_lifecycle_booking_v700($pdo,(int)$paid['booking_id'])?:[];
}

function agent_commerce_sync_paid_appointment_v800(PDO $pdo,array $paid): ?array
{
    if(!$paid||empty($paid['id']))return null;agent_commerce_ensure_schema_v800($pdo);
    $booking=agent_commerce_adapter_booking_v800($pdo,$paid);if(!$booking)return null;
    $terms=json_decode((string)($paid['terms_snapshot_json']??''),true);if(!is_array($terms))$terms=[];
    $terms['platform_fee_cents']=(int)($paid['platform_fee_cents']??0);
    if((int)($paid['team_booking_id']??0)>0){$terms['pool_id']=(int)($terms['pool_id']??$booking['pool_id']??0);}
    else{$terms['event_type_id']=(int)($terms['event_type_id']??$booking['event_type_id']??0);}
    $order=agent_commerce_create_appointment_order_v800($pdo,$paid,$booking,$terms,(int)($paid['team_booking_id']??0)>0);
    $paid=agent_paid_appointments_paid_booking_v800($pdo,(int)$paid['id'])?:$paid;

    if((int)($paid['connection_id']??0)>0){$stmt=$pdo->prepare('SELECT * FROM agent_appointment_payment_connections_v800 WHERE id=? LIMIT 1');$stmt->execute([(int)$paid['connection_id']]);$legacy=$stmt->fetch();if($legacy)agent_commerce_sync_cloud_connection_v800($pdo,$legacy);}

    $attempts=$pdo->prepare('SELECT * FROM agent_paid_checkout_attempts_v800 WHERE paid_booking_id=? ORDER BY id');$attempts->execute([(int)$paid['id']]);$completed=null;
    foreach($attempts->fetchAll()?:[] as $attempt){agent_commerce_sync_checkout_v800($pdo,$paid,$attempt,'cloud');if((string)$attempt['status']==='completed')$completed=$attempt;}

    $status=(string)($paid['payment_status']??'');
    if(in_array($status,['paid','partially_refunded','refunded'],true)&&$completed){agent_commerce_mark_paid_v800($pdo,$paid,(string)$completed['provider'],(string)($completed['external_payment_id']??''),(int)$completed['amount_cents'],(string)$completed['currency'],'cloud');}
    elseif($status==='expired')agent_commerce_expire_order_v800($pdo,$paid);
    elseif(!empty($paid['cancelled_at']))agent_commerce_cancel_fulfillment_v800($pdo,$paid,'appointment_cancelled');

    $refunds=$pdo->prepare('SELECT * FROM agent_paid_refunds_v800 WHERE paid_booking_id=? ORDER BY id');$refunds->execute([(int)$paid['id']]);
    foreach($refunds->fetchAll()?:[] as $refund){if(trim((string)($refund['external_refund_id']??''))==='')continue;agent_commerce_record_refund_v800($pdo,$paid,(string)$refund['provider'],(string)$refund['external_refund_id'],(int)$refund['amount_cents'],(string)$refund['status'],(int)($refund['approved_by_user_id']??0)?:null,'cloud');}
    return agent_commerce_order_for_paid_v800($pdo,$paid)?:$order;
}

function agent_commerce_sync_paid_appointment_id_v800(PDO $pdo,int $paidBookingId): ?array
{
    $paid=agent_paid_appointments_paid_booking_v800($pdo,$paidBookingId);return $paid?agent_commerce_sync_paid_appointment_v800($pdo,$paid):null;
}

function agent_commerce_sync_booking_v800(PDO $pdo,int $bookingId): ?array
{
    $paid=agent_paid_appointments_paid_booking_for_booking_v800($pdo,$bookingId);return $paid?agent_commerce_sync_paid_appointment_v800($pdo,$paid):null;
}

function agent_commerce_sync_team_booking_v800(PDO $pdo,int $teamBookingId): ?array
{
    $paid=agent_paid_appointments_paid_booking_for_team_v800($pdo,$teamBookingId);return $paid?agent_commerce_sync_paid_appointment_v800($pdo,$paid):null;
}

function agent_commerce_sync_provider_event_v800(PDO $pdo,string $provider,string $payload): void
{
    $event=json_decode($payload,true);if(!is_array($event))return;$provider=strtolower(trim($provider));$external='';
    if($provider==='stripe'){$obj=$event['data']['object']??[];$external=(string)($obj['id']??$obj['payment_intent']??'');}
    elseif($provider==='square'){$payment=$event['data']['object']['payment']??[];$external=(string)($payment['order_id']??$payment['id']??'');}
    elseif($provider==='paypal'){$capture=$event['resource']??[];$external=(string)($capture['supplementary_data']['related_ids']['order_id']??$capture['id']??'');}
    if($external==='')return;$attempt=agent_paid_appointments_attempt_by_external_v800($pdo,$provider,$external);if($attempt)agent_commerce_sync_paid_appointment_id_v800($pdo,(int)$attempt['paid_booking_id']);
}

function agent_commerce_migrate_paid_appointments_v800(PDO $pdo,int $limit=10000): array
{
    agent_commerce_ensure_schema_v800($pdo);$connections=0;$orders=0;$errors=0;
    if(table_exists('agent_appointment_payment_connections_v800')){$stmt=$pdo->query('SELECT * FROM agent_appointment_payment_connections_v800 ORDER BY id LIMIT '.max(1,min(50000,$limit)));foreach($stmt->fetchAll()?:[] as $row){try{if(agent_commerce_sync_cloud_connection_v800($pdo,$row))$connections++;}catch(Throwable $e){$errors++;}}}
    if(table_exists('agent_paid_bookings_v800')){$stmt=$pdo->query('SELECT * FROM agent_paid_bookings_v800 ORDER BY id LIMIT '.max(1,min(50000,$limit)));foreach($stmt->fetchAll()?:[] as $row){try{if(agent_commerce_sync_paid_appointment_v800($pdo,$row))$orders++;}catch(Throwable $e){$errors++;}}}
    return ['connections'=>$connections,'orders'=>$orders,'errors'=>$errors];
}

function agent_commerce_appointment_adapter_ensure_v800(?PDO $pdo=null): void
{
    $pdo??=db();if(!$pdo)throw new RuntimeException('Commerce adapter database is unavailable.');
    agent_commerce_ensure_schema_v800($pdo);
    if(!table_exists('agent_paid_bookings_v800'))return;
    $result=agent_commerce_migrate_paid_appointments_v800($pdo);
    if((int)$result['errors']>0)throw new RuntimeException('Existing paid appointments could not be fully migrated into VP3 Commerce.');
}
