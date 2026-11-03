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
function agent_commerce_appointment_members_pa2(array $order,array $team,array $ids): void
{
    $metadata=agent_commerce_recovery_json_pa2((string)($order['metadata_json']??''),1048576);$expected=$metadata['participant_booking_ids']??null;
    if(!is_array($expected)||!array_is_list($expected)||!$expected||count($expected)>100)throw new RuntimeException('Appointment participant lineage requires review.',409);
    foreach($expected as $id)if(!is_int($id)||$id<1)throw new RuntimeException('Appointment participant lineage requires review.',409);
    if(count($expected)!==count(array_unique($expected))||!in_array((int)$order['fulfillment_ref_id'],$expected,true)
        ||(int)($team['workspace_owner_user_id']??0)!==((int)($order['workspace_owner_user_id']??0)?:((int)$order['owner_user_id']))
        ||(int)($metadata['team_scheduling_pool_id']??0)!==(int)($team['pool_id']??0))throw new RuntimeException('Appointment participant lineage requires review.',409);
    sort($expected,SORT_NUMERIC);sort($ids,SORT_NUMERIC);
    if($expected!==$ids)throw new RuntimeException('Appointment participant lineage requires review.',409);
}
function agent_commerce_fulfillment_appointment_paid_v800(PDO $pdo,array $order,array $item): void
{
    if((string)($order['order_status']??'')==='payment_review'||!in_array((string)($order['payment_status']??''),['paid','partially_paid'],true))throw new RuntimeException('Appointment payment requires review.',409);
    agent_commerce_atomic_pa2($pdo,function()use($pdo,$order,$item):void{
        $paid=agent_paid_appointments_order_adapter_v800($order);$bookings=[];$team=null;
        if((int)$paid['team_booking_id']>0){
            $t=$pdo->prepare('SELECT * FROM agent_team_scheduling_bookings WHERE id=?'.agent_commerce_lock_pa2($pdo));$t->execute([(int)$paid['team_booking_id']]);$team=$t->fetch(PDO::FETCH_ASSOC);
            if(!$team||!in_array((string)$team['status'],['pending','confirmed'],true))throw new RuntimeException('Appointment fulfillment requires review.',409);
            $m=$pdo->prepare('SELECT canonical_booking_id FROM agent_team_scheduling_booking_members WHERE team_booking_id=? ORDER BY id');$m->execute([(int)$paid['team_booking_id']]);
            foreach($m->fetchAll(PDO::FETCH_ASSOC)?:[] as $row)$bookings[]=(int)$row['canonical_booking_id'];
            if(!$bookings||count($bookings)!==count(array_unique($bookings)))throw new RuntimeException('Appointment fulfillment requires review.',409);
            agent_commerce_appointment_members_pa2($order,$team,$bookings);
        }else $bookings[]=(int)$paid['booking_id'];
        $verified=[];
        foreach($bookings as $id){
            $b=agent_appointment_lifecycle_booking_v700($pdo,$id);
            if(!$b||!in_array((string)($b['status']??''),['pending','confirmed'],true)||!in_array(agent_appointment_lifecycle_status_v700($b),['pending','confirmed'],true))throw new RuntimeException('Appointment fulfillment requires review.',409);
            if(agent_appointment_lifecycle_status_v700($b)==='pending'){
                if((string)$b['status']==='confirmed'){
                    // Canonical payment holds retain the reserved booking's
                    // status and set only its lifecycle to pending.
                    $u=$pdo->prepare("UPDATE agent_scheduling_bookings SET lifecycle_status='confirmed',last_lifecycle_event_at=?,updated_at=? WHERE id=? AND owner_user_id=? AND status='confirmed' AND lifecycle_status='pending'");
                    $u->execute([agent_commerce_time_pa2(),agent_commerce_time_pa2(),$id,(int)$b['owner_user_id']]);
                    if($u->rowCount()!==1)throw new RuntimeException('Appointment payment hold changed before confirmation.',409);
                    $b=agent_appointment_lifecycle_booking_v700($pdo,$id)?:throw new RuntimeException('Appointment confirmation is unavailable.',409);
                    agent_appointment_lifecycle_event_v700($pdo,$b,'status_changed','pending','confirmed','system',null,null,['source'=>'commerce_v800','order_id'=>(int)$order['id']]);
                }else $b=agent_appointment_lifecycle_transition_v700($pdo,$b,'confirmed','system',null,null,['source'=>'commerce_v800','order_id'=>(int)$order['id']]);
            }
            if((int)($b['id']??0)!==$id||agent_appointment_lifecycle_status_v700($b)!=='confirmed'||(string)($b['status']??'')!=='confirmed')throw new RuntimeException('Appointment confirmation was not established.',409);
            $verified[]=$b;
        }
        if($team)$pdo->prepare("UPDATE agent_team_scheduling_bookings SET status='confirmed',updated_at=? WHERE id=? AND status='pending'")->execute([agent_commerce_time_pa2(),(int)$team['id']]);
        foreach($verified as $booking)agent_appointment_lifecycle_queue_booking_v700($pdo,$booking,true);
        $pdo->prepare("UPDATE agent_commerce_order_items_v800 SET fulfillment_status='confirmed',updated_at=? WHERE id=? AND order_id=?")->execute([agent_commerce_time_pa2(),(int)$item['id'],(int)$order['id']]);
    });
}
function agent_commerce_fulfillment_appointment_expired_v800(PDO $pdo,array $order,array $item): void
{
    agent_commerce_atomic_pa2($pdo,function()use($pdo,$order,$item):void{
        $paid=agent_paid_appointments_order_adapter_v800($order);$bookings=[];
        if((int)$paid['team_booking_id']>0){
            $s=$pdo->prepare('SELECT * FROM agent_team_scheduling_bookings WHERE id=?'.agent_commerce_lock_pa2($pdo));$s->execute([(int)$paid['team_booking_id']]);$team=$s->fetch(PDO::FETCH_ASSOC);
            if($team&&(string)$team['status']==='confirmed')throw new RuntimeException('Confirmed appointment expiry requires review.',409);
            if($team&&(string)$team['status']==='pending')agent_team_scheduling_cancel_booking_v600($pdo,$team);
            $m=$pdo->prepare('SELECT canonical_booking_id FROM agent_team_scheduling_booking_members WHERE team_booking_id=?');$m->execute([(int)$paid['team_booking_id']]);foreach($m->fetchAll(PDO::FETCH_ASSOC)?:[] as $row)$bookings[]=(int)$row['canonical_booking_id'];
            if($team)agent_commerce_appointment_members_pa2($order,$team,$bookings);
        }else $bookings[]=(int)$paid['booking_id'];
        foreach($bookings as $id){
            $b=agent_appointment_lifecycle_booking_v700($pdo,$id);if(!$b)continue;
            if(agent_appointment_lifecycle_status_v700($b)==='confirmed')throw new RuntimeException('Confirmed appointment expiry requires review.',409);
            if(agent_appointment_lifecycle_status_v700($b)==='pending')$b=agent_appointment_lifecycle_transition_v700($pdo,$b,'cancelled','system',null,null,['source'=>'commerce_hold_expired']);
            if(!in_array(agent_appointment_lifecycle_status_v700($b),['cancelled','expired'],true))throw new RuntimeException('Appointment expiry result requires review.',409);
        }
        $pdo->prepare("UPDATE agent_commerce_order_items_v800 SET fulfillment_status='cancelled',updated_at=? WHERE id=? AND order_id=?")->execute([agent_commerce_time_pa2(),(int)$item['id'],(int)$order['id']]);
    });
}
function agent_paid_appointments_confirmation_after_payment_v800(PDO $pdo,array $paid): void
{
    $order=agent_commerce_order_v800($pdo,(int)$paid['id']);if($order)agent_commerce_dispatch_fulfillment_v800($pdo,$order,'paid');
}
function agent_paid_appointments_mark_paid_v800(PDO $pdo,int $paidBookingId,string $provider,string $externalSessionId,string $externalPaymentId,int $amountCents,string $currency): array
{
    $order=agent_commerce_order_v800($pdo,$paidBookingId)?:throw new RuntimeException('Commerce order not found.',409);
    $q=$pdo->prepare('SELECT * FROM agent_commerce_checkout_attempts_v800 WHERE order_id=? AND provider=? AND external_session_id=?');$q->execute([$paidBookingId,$provider,$externalSessionId]);$attempt=$q->fetch(PDO::FETCH_ASSOC);
    if(!$attempt||!hash_equals((string)$attempt['external_session_id'],$externalSessionId))throw new RuntimeException('Payment checkout lineage is unavailable.',409);
    $attempt['expected_payment_id']=$externalPaymentId;$proof=agent_commerce_read_attempt_pa2($pdo,$order,$attempt);
    return agent_paid_appointments_order_adapter_v800(agent_commerce_mark_paid_v800($pdo,$paidBookingId,$provider,$externalSessionId,$externalPaymentId,$amountCents,$currency,$proof));
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
