<?php
declare(strict_types=1);

function agent_paid_appointments_decimal_to_minor_v800(string $value): int
{
    $value=trim($value);
    if($value==='')return 0;
    if(!preg_match('/^(\d{1,9})(?:\.(\d{1,2}))?$/',$value,$m))throw new RuntimeException('Enter a valid money amount with no more than two decimal places.');
    $whole=(int)$m[1];$fraction=str_pad((string)($m[2]??''),2,'0');
    if($whole>21474836)throw new RuntimeException('Money amount is too large.');
    return ($whole*100)+(int)$fraction;
}

function agent_paid_appointments_balance_due_v800(array $paid): int
{
    return max(0,(int)($paid['amount_total_cents']??0)-(int)($paid['amount_paid_cents']??0));
}

function agent_paid_appointments_payment_url_v800(PDO $pdo,array $paid): string
{
    $token=agent_paid_appointments_manage_token_v800($pdo,$paid);
    return preg_match('/^[a-f0-9]{64}$/',$token)?url('/appointment-payment.php?manage='.rawurlencode($token)):'';
}

function agent_paid_appointments_owner_rows_v800(PDO $pdo,int $ownerUserId,int $limit=100): array
{
    if($ownerUserId<1)return [];
    $limit=max(1,min(250,$limit));
    $stmt=$pdo->prepare("SELECT pb.*,b.start_at_utc,b.end_at_utc,b.guest_name,b.guest_email,b.status booking_status,b.lifecycle_status,e.title event_title,tb.pool_id,tb.status team_status,p.name team_pool_name
      FROM agent_paid_bookings_v800 pb
      JOIN agent_scheduling_bookings b ON b.id=pb.booking_id
      LEFT JOIN agent_scheduling_event_types e ON e.id=b.event_type_id
      LEFT JOIN agent_team_scheduling_bookings tb ON tb.id=pb.team_booking_id
      LEFT JOIN agent_team_scheduling_pools p ON p.id=tb.pool_id
      WHERE pb.owner_user_id=? OR pb.workspace_owner_user_id=?
      ORDER BY b.start_at_utc DESC,pb.id DESC LIMIT {$limit}");
    $stmt->execute([$ownerUserId,$ownerUserId]);
    return $stmt->fetchAll()?:[];
}

function agent_paid_appointments_refunds_v800(PDO $pdo,int $paidBookingId): array
{
    if($paidBookingId<1)return [];
    $stmt=$pdo->prepare('SELECT * FROM agent_paid_refunds_v800 WHERE paid_booking_id=? ORDER BY id DESC');
    $stmt->execute([$paidBookingId]);
    return $stmt->fetchAll()?:[];
}

function agent_paid_appointments_record_cancellation_v800(PDO $pdo,array $paid,string $actorType='guest',?int $actorUserId=null,?int $actorAgentId=null,string $source='appointment_cancelled'): array
{
    $paidId=(int)($paid['id']??0);if($paidId<1)return $paid;
    $from=(string)($paid['payment_status']??'');
    if($from==='awaiting_payment'){
        $pdo->prepare("UPDATE agent_paid_bookings_v800 SET payment_status='cancelled',cancelled_at=NOW(),hold_expires_at=NULL,updated_at=NOW() WHERE id=? AND payment_status='awaiting_payment'")->execute([$paidId]);
        $pdo->prepare("UPDATE agent_paid_checkout_attempts_v800 SET status='cancelled',updated_at=NOW() WHERE paid_booking_id=? AND status='open'")->execute([$paidId]);
        $to='cancelled';
    }else{
        $pdo->prepare('UPDATE agent_paid_bookings_v800 SET cancelled_at=COALESCE(cancelled_at,NOW()),updated_at=NOW() WHERE id=?')->execute([$paidId]);
        $to=$from;
    }
    $fresh=agent_paid_appointments_paid_booking_v800($pdo,$paidId)?:$paid;
    $refundable=agent_paid_appointments_refundable_cents_v800($pdo,$fresh);
    agent_paid_appointments_audit_v800($pdo,$paidId,(int)$fresh['owner_user_id'],(int)($fresh['workspace_owner_user_id']??0)?:null,$actorType,$actorUserId,$actorAgentId,$source,$from,$to,$refundable,['refundable_cents'=>$refundable]);
    return $fresh;
}

function agent_paid_appointments_record_reschedule_v800(PDO $pdo,array $paid,string $actorType='guest',?int $actorUserId=null,?int $actorAgentId=null): void
{
    agent_paid_appointments_audit_v800($pdo,(int)$paid['id'],(int)$paid['owner_user_id'],(int)($paid['workspace_owner_user_id']??0)?:null,$actorType,$actorUserId,$actorAgentId,'appointment_rescheduled',(string)$paid['payment_status'],(string)$paid['payment_status'],0,['payment_retained'=>true]);
}

function agent_paid_appointments_display_terms_v800(array $terms): string
{
    $mode=(string)($terms['payment_mode']??'free');
    if($mode==='free')return 'Free';
    $price=agent_paid_appointments_money_v800((int)($terms['price_cents']??0),(string)($terms['currency']??'usd'));
    if($mode==='deposit'){
        $deposit=agent_paid_appointments_money_v800((int)($terms['deposit_cents']??0),(string)($terms['currency']??'usd'));
        return $deposit.' deposit · '.$price.' total';
    }
    return $price;
}

function agent_paid_appointments_reconcile_cancelled_v800(PDO $pdo,int $limit=100): int
{
    $limit=max(1,min(500,$limit));
    $stmt=$pdo->query("SELECT pb.* FROM agent_paid_bookings_v800 pb JOIN agent_scheduling_bookings b ON b.id=pb.booking_id LEFT JOIN agent_team_scheduling_bookings tb ON tb.id=pb.team_booking_id WHERE pb.cancelled_at IS NULL AND (b.status='cancelled' OR tb.status='cancelled') ORDER BY pb.id LIMIT {$limit}");
    $rows=$stmt->fetchAll()?:[];
    foreach($rows as $row){
        try{agent_paid_appointments_record_cancellation_v800($pdo,$row,'system',null,null,'cancel_reconciled');}
        catch(Throwable $e){error_log('VP3 paid appointment cancellation reconciliation failed: '.$e->getMessage());}
    }
    return count($rows);
}

function agent_paid_appointments_housekeeping_v800(PDO $pdo,int $limit=100): array
{
    return [
        'expired'=>agent_paid_appointments_expire_holds_v800($pdo,$limit),
        'cancelled'=>agent_paid_appointments_reconcile_cancelled_v800($pdo,$limit),
    ];
}
