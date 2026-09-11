<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
$pdo=db();
if(!$pdo||!agent_team_scheduling_schema_ready_v600($pdo)){http_response_code(503);exit('Team scheduling is not available.');}
$paidReady=function_exists('agent_paid_appointments_schema_ready_v800')&&agent_paid_appointments_schema_ready_v800($pdo)
    &&function_exists('agent_appointment_lifecycle_schema_ready_v700')&&agent_appointment_lifecycle_schema_ready_v700($pdo);

function team_book_display_time(string $utc,string $timezone,string $format='D, M j · g:i A T'): string
{
    try{return(new DateTimeImmutable($utc,new DateTimeZone('UTC')))->setTimezone(new DateTimeZone(agent_scheduling_timezone_v430($timezone)))->format($format);}catch(Throwable $e){return '';}
}
function team_book_phase7_canonical_v700(PDO $pdo,int $teamBookingId): array
{
    if($teamBookingId<1||!function_exists('agent_appointment_lifecycle_schema_ready_v700')||!agent_appointment_lifecycle_schema_ready_v700($pdo))return [];
    $stmt=$pdo->prepare('SELECT canonical_booking_id FROM agent_team_scheduling_booking_members WHERE team_booking_id=? ORDER BY id');$stmt->execute([$teamBookingId]);$out=[];
    foreach($stmt->fetchAll()?:[] as $row){$booking=agent_appointment_lifecycle_booking_v700($pdo,(int)$row['canonical_booking_id']);if($booking)$out[]=$booking;}
    return $out;
}

$error='';$success='';$manageToken=strtolower(trim((string)($_GET['manage']??$_POST['manage_token']??'')));$managed=null;$pool=null;
if($manageToken!==''){$managed=agent_team_scheduling_booking_by_cancel_token_v600($pdo,$manageToken);if($managed){$pool=agent_team_scheduling_pool_v600($pdo,(int)$managed['workspace_owner_user_id'],(int)$managed['pool_id']);}}
if(!$pool){$publicKey=strtolower(trim((string)($_GET['p']??$_POST['public_key']??'')));$pool=agent_team_scheduling_public_pool_v600($pdo,$publicKey);}
if(!$pool){http_response_code(404);exit('This Team booking page is not available.');}
$teamPaymentTerms=$paidReady?agent_paid_appointments_team_terms_v800($pdo,(int)$pool['id']):null;
$timezone=agent_scheduling_timezone_v430((string)$pool['timezone']);$tz=new DateTimeZone($timezone);$today=(new DateTimeImmutable('today',$tz))->format('Y-m-d');
$date=trim((string)($_GET['date']??$_POST['date']??$today));$parsed=DateTimeImmutable::createFromFormat('!Y-m-d',$date,$tz);if(!$parsed||$parsed->format('Y-m-d')!==$date||$date<$today)$date=$today;
$routingAnswer=mb_strimwidth(trim((string)($_GET['routing_answer']??$_POST['routing_answer']??'')),0,500,'');

if($_SERVER['REQUEST_METHOD']==='POST'){
    if(!verify_csrf())$error='Session expired. Please refresh and try again.';
    else try{
        $action=(string)($_POST['action']??'');agent_scheduling_public_rate_limit_v450('team-'.$action,12,60);
        if($action==='book'){
            $publicPool=agent_team_scheduling_public_pool_v600($pdo,(string)($_POST['public_key']??''));
            if(!$publicPool||(int)$publicPool['id']!==(int)$pool['id'])throw new RuntimeException('This Team booking page is no longer available.');
            if(trim((string)$pool['routing_question'])!==''&&$routingAnswer==='')throw new RuntimeException('Please answer the routing question before choosing a time.');
            $guestEmail=strtolower(trim((string)($_POST['guest_email']??'')));
            if($teamPaymentTerms&&agent_paid_appointments_amount_due_v800($teamPaymentTerms)>0&&($guestEmail===''||!filter_var($guestEmail,FILTER_VALIDATE_EMAIL)))throw new RuntimeException('Enter a valid email address for this paid Team appointment.');
            $booking=agent_team_scheduling_create_booking_v600($pdo,$publicPool,[
                'start_at_utc'=>(string)($_POST['start_at_utc']??''),'guest_timezone'=>(string)($_POST['guest_timezone']??$timezone),
                'guest_name'=>(string)($_POST['guest_name']??''),'guest_email'=>$guestEmail,
                'guest_phone'=>(string)($_POST['guest_phone']??''),'routing_answer'=>$routingAnswer,'source'=>'team_public'
            ]);
            try{$paid=$paidReady?agent_paid_appointments_create_team_v800($pdo,$booking):null;}
            catch(Throwable $commercialError){
                $existingPaid=$paidReady?agent_paid_appointments_paid_booking_for_team_v800($pdo,(int)$booking['id']):null;
                try{agent_team_scheduling_cancel_booking_v600($pdo,$booking);}catch(Throwable $ignored){}
                if($existingPaid){try{agent_paid_appointments_record_cancellation_v800($pdo,$existingPaid,'system',null,null,'team_commercial_booking_rollback');}catch(Throwable $ignored){}}
                throw $commercialError;
            }
            if($paid){
                $paymentUrl=agent_paid_appointments_payment_url_v800($pdo,$paid);if($paymentUrl==='')throw new RuntimeException('Team appointment payment link could not be created.');
                agent_paid_appointments_housekeeping_v800($pdo,50);redirect($paymentUrl);
            }
            foreach(team_book_phase7_canonical_v700($pdo,(int)$booking['id']) as $canonical){
                agent_appointment_lifecycle_event_v700($pdo,$canonical,'confirmed','','confirmed','guest',null,null,['source'=>'team_public','team_booking_id'=>(int)$booking['id']]);
                agent_appointment_lifecycle_queue_booking_v700($pdo,$canonical,true);
            }
            if(function_exists('agent_appointment_lifecycle_housekeeping_v700')&&agent_appointment_lifecycle_schema_ready_v700($pdo))agent_appointment_lifecycle_housekeeping_v700($pdo,120);
            redirect(url('/team-book.php?manage='.rawurlencode((string)$booking['cancel_token']).'&booked=1'));
        }
        if($action==='cancel'){
            $booking=agent_team_scheduling_booking_by_cancel_token_v600($pdo,$manageToken);if(!$booking)throw new RuntimeException('This booking-management link is not valid.');
            $paid=$paidReady?agent_paid_appointments_paid_booking_for_team_v800($pdo,(int)$booking['id']):null;
            $canonicals=team_book_phase7_canonical_v700($pdo,(int)$booking['id']);
            if(!agent_team_scheduling_cancel_booking_v600($pdo,$booking))throw new RuntimeException('This Team booking is no longer active.');
            foreach($canonicals as $canonical){
                if(function_exists('agent_appointment_lifecycle_schema_ready_v700')&&agent_appointment_lifecycle_schema_ready_v700($pdo)){
                    $from=agent_appointment_lifecycle_status_v700($canonical);
                    $pdo->prepare("UPDATE agent_scheduling_bookings SET lifecycle_status='cancelled',last_lifecycle_event_at=NOW() WHERE id=?")->execute([(int)$canonical['id']]);
                    $fresh=agent_appointment_lifecycle_booking_v700($pdo,(int)$canonical['id'])?:$canonical;
                    agent_appointment_lifecycle_event_v700($pdo,$fresh,'status_changed',$from,'cancelled','guest',null,null,['source'=>'team_private_manage','team_booking_id'=>(int)$booking['id']]);
                    agent_appointment_lifecycle_queue_notice_v700($pdo,$fresh,'cancelled');
                }
            }
            if($paid)agent_paid_appointments_record_cancellation_v800($pdo,$paid,'guest',null,null,'team_guest_cancelled');
            if(function_exists('agent_appointment_lifecycle_housekeeping_v700')&&agent_appointment_lifecycle_schema_ready_v700($pdo))agent_appointment_lifecycle_housekeeping_v700($pdo,120);
            $managed=agent_team_scheduling_booking_by_cancel_token_v600($pdo,$manageToken);$success='Team booking cancelled across all participant calendars.';
        }
    }catch(Throwable $e){$error=$e->getMessage();}
}

$managedPaid=$paidReady&&$managed?agent_paid_appointments_paid_booking_for_team_v800($pdo,(int)$managed['id']):null;
$slots=[];if(!$managed&&empty($error))$slots=agent_team_scheduling_slots_v600($pdo,$pool,$date,$routingAnswer);$booked=!empty($_GET['booked']);
