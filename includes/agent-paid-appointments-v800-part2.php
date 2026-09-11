<?php
declare(strict_types=1);

function agent_paid_appointments_public_by_token_v800(PDO $pdo,string $token): ?array
{
    $token=strtolower(trim($token));if(!preg_match('/^[a-f0-9]{64}$/',$token))return null;$stmt=$pdo->prepare("SELECT o.* FROM agent_commerce_orders_v800 o LEFT JOIN agent_team_scheduling_bookings tb ON tb.id=o.fulfillment_group_id AND o.fulfillment_group_type='team_booking' LEFT JOIN agent_scheduling_bookings b ON b.id=o.fulfillment_ref_id AND o.fulfillment_ref_type='appointment_booking' WHERE o.fulfillment_type='appointment' AND ((o.fulfillment_group_type='' AND b.cancel_token=?) OR (o.fulfillment_group_type='team_booking' AND tb.cancel_token=?)) LIMIT 1");$stmt->execute([$token,$token]);$order=$stmt->fetch()?:null;return $order?agent_paid_appointments_order_adapter_v800($order):null;
}
function agent_paid_appointments_manage_token_v800(PDO $pdo,array $paid): string
{
    if((int)($paid['team_booking_id']??0)>0){$stmt=$pdo->prepare('SELECT cancel_token FROM agent_team_scheduling_bookings WHERE id=? LIMIT 1');$stmt->execute([(int)$paid['team_booking_id']]);return (string)$stmt->fetchColumn();}$stmt=$pdo->prepare('SELECT cancel_token FROM agent_scheduling_bookings WHERE id=? LIMIT 1');$stmt->execute([(int)$paid['booking_id']]);return (string)$stmt->fetchColumn();
}
function agent_paid_appointments_payment_url_v800(PDO $pdo,array $paid): string
{
    $token=agent_paid_appointments_manage_token_v800($pdo,$paid);return preg_match('/^[a-f0-9]{64}$/',$token)?url('/appointment-payment.php?manage='.rawurlencode($token)):'';
}
function agent_commerce_fulfillment_appointment_paid_v800(PDO $pdo,array $order,array $item): void
{
    $paid=agent_paid_appointments_order_adapter_v800($order);if((int)$paid['team_booking_id']>0){$pdo->prepare("UPDATE agent_team_scheduling_bookings SET status='confirmed',updated_at=NOW() WHERE id=? AND status='pending'")->execute([(int)$paid['team_booking_id']]);$stmt=$pdo->prepare('SELECT canonical_booking_id FROM agent_team_scheduling_booking_members WHERE team_booking_id=? ORDER BY id');$stmt->execute([(int)$paid['team_booking_id']]);foreach($stmt->fetchAll()?:[] as $row){$b=agent_appointment_lifecycle_booking_v700($pdo,(int)$row['canonical_booking_id']);if(!$b)continue;if(agent_appointment_lifecycle_status_v700($b)==='pending')$b=agent_appointment_lifecycle_transition_v700($pdo,$b,'confirmed','system',null,null,['source'=>'commerce_v800','order_id'=>(int)$order['id']]);agent_appointment_lifecycle_queue_booking_v700($pdo,$b,true);}$pdo->prepare("UPDATE agent_commerce_order_items_v800 SET fulfillment_status='confirmed',updated_at=NOW() WHERE id=?")->execute([(int)$item['id']]);return;}$b=agent_appointment_lifecycle_booking_v700($pdo,(int)$paid['booking_id']);if($b){if(agent_appointment_lifecycle_status_v700($b)==='pending')$b=agent_appointment_lifecycle_transition_v700($pdo,$b,'confirmed','system',null,null,['source'=>'commerce_v800','order_id'=>(int)$order['id']]);agent_appointment_lifecycle_queue_booking_v700($pdo,$b,true);}$pdo->prepare("UPDATE agent_commerce_order_items_v800 SET fulfillment_status='confirmed',updated_at=NOW() WHERE id=?")->execute([(int)$item['id']]);if(function_exists('agent_appointment_lifecycle_housekeeping_v700'))agent_appointment_lifecycle_housekeeping_v700($pdo,120);
}
function agent_commerce_fulfillment_appointment_expired_v800(PDO $pdo,array $order,array $item): void
{
    $paid=agent_paid_appointments_order_adapter_v800($order);if((int)$paid['team_booking_id']>0){$stmt=$pdo->prepare('SELECT * FROM agent_team_scheduling_bookings WHERE id=? LIMIT 1');$stmt->execute([(int)$paid['team_booking_id']]);$team=$stmt->fetch();if($team&&in_array((string)$team['status'],['pending','confirmed'],true))agent_team_scheduling_cancel_booking_v600($pdo,$team);$m=$pdo->prepare('SELECT canonical_booking_id FROM agent_team_scheduling_booking_members WHERE team_booking_id=?');$m->execute([(int)$paid['team_booking_id']]);foreach($m->fetchAll()?:[] as $row){$b=agent_appointment_lifecycle_booking_v700($pdo,(int)$row['canonical_booking_id']);if($b&&agent_appointment_lifecycle_status_v700($b)==='pending'){try{agent_appointment_lifecycle_transition_v700($pdo,$b,'cancelled','system',null,null,['source'=>'commerce_hold_expired']);}catch(Throwable $e){}}}}else{$b=agent_appointment_lifecycle_booking_v700($pdo,(int)$paid['booking_id']);if($b&&agent_appointment_lifecycle_status_v700($b)==='pending')agent_appointment_lifecycle_transition_v700($pdo,$b,'cancelled','system',null,null,['source'=>'commerce_hold_expired']);}$pdo->prepare("UPDATE agent_commerce_order_items_v800 SET fulfillment_status='cancelled',updated_at=NOW() WHERE id=?")->execute([(int)$item['id']]);
}
function agent_paid_appointments_confirmation_after_payment_v800(PDO $pdo,array $paid): void
{
    $order=agent_commerce_order_v800($pdo,(int)$paid['id']);if($order)agent_commerce_dispatch_fulfillment_v800($pdo,$order,'paid');
}
function agent_paid_appointments_mark_paid_v800(PDO $pdo,int $paidBookingId,string $provider,string $externalSessionId,string $externalPaymentId,int $amountCents,string $currency): array
{
    return agent_paid_appointments_order_adapter_v800(agent_commerce_mark_paid_v800($pdo,$paidBookingId,$provider,$externalSessionId,$externalPaymentId,$amountCents,$currency));
}
function agent_paid_appointments_expire_one_v800(PDO $pdo,array $paid): void{agent_commerce_expire_one_v800($pdo,$paid);}
function agent_paid_appointments_expire_holds_v800(PDO $pdo,int $limit=100): int{return agent_commerce_expire_holds_v800($pdo,$limit);}
function agent_paid_appointments_housekeeping_maybe_v800(): void{agent_commerce_housekeeping_maybe_v800();}
function agent_paid_appointments_stripe_verify_v800(string $payload,string $signature): bool{return agent_commerce_stripe_verify_v800($payload,$signature);}
function agent_paid_appointments_square_verify_v800(string $payload,string $signature): bool{return agent_commerce_square_verify_v800($payload,$signature);}
function agent_paid_appointments_paypal_verify_v800(array $headers,array $event): bool{return agent_commerce_paypal_verify_v800($headers,$event);}
function agent_paid_appointments_webhook_claim_v800(PDO $pdo,string $provider,string $eventId,string $eventType,string $payload): bool{return agent_commerce_webhook_claim_v800($pdo,$provider,$eventId,$eventType,$payload);}
function agent_paid_appointments_webhook_finish_v800(PDO $pdo,string $provider,string $eventId,string $status,string $error=''): void{agent_commerce_webhook_finish_v800($pdo,$provider,$eventId,$status,$error);}
function agent_paid_appointments_process_webhook_v800(PDO $pdo,string $provider,string $payload,array $headers=[]): void{agent_commerce_process_webhook_v800($pdo,$provider,$payload,$headers);}
function agent_paid_appointments_return_verify_v800(PDO $pdo,array $paid,string $provider,array $query): array{return agent_paid_appointments_order_adapter_v800(agent_commerce_return_verify_v800($pdo,$paid,$provider,$query));}
function agent_paid_appointments_refundable_cents_v800(PDO $pdo,array $paid): int
{
    if(!in_array((string)$paid['payment_status'],['paid','partially_paid','partially_refunded'],true))return 0;$remaining=max(0,(int)$paid['amount_paid_cents']-(int)$paid['amount_refunded_cents']);if($remaining<1)return 0;$snapshot=json_decode((string)($paid['terms_snapshot_json']??''),true);if(!is_array($snapshot))$snapshot=[];$hours=max(0,(int)($snapshot['refund_before_hours']??24));$fee=max(0,(int)($snapshot['cancellation_fee_cents']??0));$stmt=$pdo->prepare('SELECT start_at_utc FROM agent_scheduling_bookings WHERE id=? LIMIT 1');$stmt->execute([(int)$paid['booking_id']]);$start=(string)$stmt->fetchColumn();if($start===''||strtotime($start)<time()+($hours*3600))return 0;return max(0,$remaining-min($fee,$remaining));
}
function agent_paid_appointments_refund_v800(PDO $pdo,array $paid,int $amountCents,string $reason,?array $actor=null,bool $approved=false,?int $agentId=null): array
{
    $max=agent_paid_appointments_refundable_cents_v800($pdo,$paid);if($amountCents<1||$amountCents>$max)throw new RuntimeException('Refund amount exceeds the amount allowed by this appointment cancellation policy.');return agent_paid_appointments_order_adapter_v800(agent_commerce_refund_v800($pdo,$paid,$amountCents,$reason,$actor,$approved,$agentId));
}
function agent_paid_appointments_balance_due_v800(array $paid): int{return agent_commerce_balance_due_v800($paid);}
function agent_paid_appointments_owner_rows_v800(PDO $pdo,int $ownerUserId,int $limit=100): array
{
    if($ownerUserId<1)return [];$limit=max(1,min(250,$limit));$stmt=$pdo->prepare("SELECT o.*,b.start_at_utc,b.end_at_utc,b.guest_name,b.guest_email,b.status booking_status,b.lifecycle_status,e.title event_title,tb.pool_id,tb.status team_status,p.name team_pool_name FROM agent_commerce_orders_v800 o JOIN agent_scheduling_bookings b ON b.id=o.fulfillment_ref_id AND o.fulfillment_ref_type='appointment_booking' LEFT JOIN agent_scheduling_event_types e ON e.id=b.event_type_id LEFT JOIN agent_team_scheduling_bookings tb ON tb.id=o.fulfillment_group_id AND o.fulfillment_group_type='team_booking' LEFT JOIN agent_team_scheduling_pools p ON p.id=tb.pool_id WHERE o.fulfillment_type='appointment' AND (o.owner_user_id=? OR o.workspace_owner_user_id=?) ORDER BY b.start_at_utc DESC,o.id DESC LIMIT {$limit}");$stmt->execute([$ownerUserId,$ownerUserId]);$rows=$stmt->fetchAll()?:[];return array_map('agent_paid_appointments_order_adapter_v800',$rows);
}
function agent_paid_appointments_refunds_v800(PDO $pdo,int $paidBookingId): array
{
    if($paidBookingId<1)return [];$stmt=$pdo->prepare('SELECT * FROM agent_commerce_refunds_v800 WHERE order_id=? ORDER BY id DESC');$stmt->execute([$paidBookingId]);return $stmt->fetchAll()?:[];
}
function agent_paid_appointments_record_cancellation_v800(PDO $pdo,array $paid,string $actorType='guest',?int $actorUserId=null,?int $actorAgentId=null,string $source='appointment_cancelled'): array
{
    $orderId=(int)($paid['id']??0);if($orderId<1)return $paid;$from=(string)($paid['payment_status']??'');if($from==='awaiting_payment'){$pdo->prepare("UPDATE agent_commerce_orders_v800 SET payment_status='cancelled',order_status='cancelled',cancelled_at=NOW(),hold_expires_at=NULL,updated_at=NOW() WHERE id=? AND payment_status='awaiting_payment'")->execute([$orderId]);$pdo->prepare("UPDATE agent_commerce_checkout_attempts_v800 SET status='cancelled',updated_at=NOW() WHERE order_id=? AND status='open'")->execute([$orderId]);$to='cancelled';}else{$pdo->prepare("UPDATE agent_commerce_orders_v800 SET order_status='cancelled',cancelled_at=COALESCE(cancelled_at,NOW()),updated_at=NOW() WHERE id=?")->execute([$orderId]);$to=$from;}$fresh=agent_paid_appointments_paid_booking_v800($pdo,$orderId)?:$paid;$refundable=agent_paid_appointments_refundable_cents_v800($pdo,$fresh);agent_commerce_audit_v800($pdo,$orderId,(int)$fresh['owner_user_id'],(int)($fresh['workspace_owner_user_id']??0)?:null,$actorType,$actorUserId,$actorAgentId,$source,$from,$to,$refundable,['refundable_cents'=>$refundable]);return $fresh;
}
function agent_paid_appointments_record_reschedule_v800(PDO $pdo,array $paid,string $actorType='guest',?int $actorUserId=null,?int $actorAgentId=null): void
{
    agent_commerce_audit_v800($pdo,(int)$paid['id'],(int)$paid['owner_user_id'],(int)($paid['workspace_owner_user_id']??0)?:null,$actorType,$actorUserId,$actorAgentId,'appointment_rescheduled',(string)$paid['payment_status'],(string)$paid['payment_status'],0,['payment_retained'=>true,'order_id'=>(int)$paid['id']]);
}
function agent_paid_appointments_display_terms_v800(array $terms): string
{
    $mode=(string)($terms['payment_mode']??'free');if($mode==='free')return 'Free';$price=agent_commerce_money_v800((int)($terms['price_cents']??0),(string)($terms['currency']??'usd'));if($mode==='deposit')return agent_commerce_money_v800((int)($terms['deposit_cents']??0),(string)($terms['currency']??'usd')).' deposit · '.$price.' total';return $price;
}
function agent_paid_appointments_reconcile_cancelled_v800(PDO $pdo,int $limit=100): int
{
    $limit=max(1,min(500,$limit));$stmt=$pdo->query("SELECT o.* FROM agent_commerce_orders_v800 o JOIN agent_scheduling_bookings b ON b.id=o.fulfillment_ref_id AND o.fulfillment_ref_type='appointment_booking' LEFT JOIN agent_team_scheduling_bookings tb ON tb.id=o.fulfillment_group_id AND o.fulfillment_group_type='team_booking' WHERE o.fulfillment_type='appointment' AND o.cancelled_at IS NULL AND (b.status='cancelled' OR tb.status='cancelled') ORDER BY o.id LIMIT {$limit}");$rows=$stmt->fetchAll()?:[];foreach($rows as $row){try{agent_paid_appointments_record_cancellation_v800($pdo,agent_paid_appointments_order_adapter_v800($row),'system',null,null,'cancel_reconciled');}catch(Throwable $e){error_log('VP3 appointment commerce cancellation reconciliation failed: '.$e->getMessage());}}return count($rows);
}
function agent_paid_appointments_housekeeping_v800(PDO $pdo,int $limit=100): array
{
    $generic=agent_commerce_housekeeping_v800($pdo,$limit);$generic['cancelled']=agent_paid_appointments_reconcile_cancelled_v800($pdo,$limit);return $generic;
}
