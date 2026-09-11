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

function agent_commerce_sync_legacy_refund_v800(PDO $pdo,array $paid,array $refund): void
{
    $external=trim((string)($refund['external_refund_id']??''));if($external==='')return;
    $order=agent_commerce_order_for_paid_v800($pdo,$paid);if(!$order)return;
    $provider=(string)$refund['provider'];$authority='cloud';
    $paymentStmt=$pdo->prepare('SELECT id FROM agent_commerce_payments_v800 WHERE order_id=? AND provider=? AND authority=? ORDER BY id DESC LIMIT 1');
    $paymentStmt->execute([(int)$order['id'],$provider,$authority]);$paymentId=(int)($paymentStmt->fetchColumn()?:0)?:null;
    $status=(string)($refund['status']??'pending');$completed=in_array($status,['completed','succeeded'],true)?($refund['completed_at']??gmdate('Y-m-d H:i:s')):null;
    $pdo->prepare("INSERT INTO agent_commerce_refunds_v800 (order_id,payment_id,provider,authority,external_refund_id,amount_cents,status,reason,approved_by_user_id,completed_at) VALUES (?,?,?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE payment_id=VALUES(payment_id),amount_cents=VALUES(amount_cents),status=VALUES(status),reason=VALUES(reason),approved_by_user_id=COALESCE(VALUES(approved_by_user_id),approved_by_user_id),completed_at=COALESCE(VALUES(completed_at),completed_at),updated_at=NOW()")
        ->execute([(int)$order['id'],$paymentId,$provider,$authority,$external,max(0,(int)$refund['amount_cents']),$status,(string)($refund['reason']??''),(int)($refund['approved_by_user_id']??0)?:null,$completed]);
    $sum=$pdo->prepare("SELECT COALESCE(SUM(amount_cents),0) FROM agent_commerce_refunds_v800 WHERE order_id=? AND status IN ('completed','succeeded')");$sum->execute([(int)$order['id']]);$refunded=min((int)$order['amount_paid_cents'],max(0,(int)$sum->fetchColumn()));
    $paymentStatus=$refunded>0?($refunded>=(int)$order['amount_paid_cents']?'refunded':'partially_refunded'):(string)$order['payment_status'];
    $pdo->prepare("UPDATE agent_commerce_orders_v800 SET amount_refunded_cents=?,payment_status=?,refunded_at=IF(?='refunded',COALESCE(refunded_at,NOW()),refunded_at),updated_at=NOW() WHERE id=?")
        ->execute([$refunded,$paymentStatus,$paymentStatus,(int)$order['id']]);
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
    foreach($refunds->fetchAll()?:[] as $refund)agent_commerce_sync_legacy_refund_v800($pdo,$paid,$refund);
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
