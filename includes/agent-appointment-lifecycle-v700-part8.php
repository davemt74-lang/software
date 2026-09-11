<?php
declare(strict_types=1);

function agent_appointment_lifecycle_housekeeping_v700(PDO $pdo,int $limit=100): array
{
    if(!agent_appointment_lifecycle_schema_ready_v700($pdo))return ['queued'=>0,'processed'=>0,'reconciled'=>0];
    $reconciled=agent_appointment_lifecycle_reconcile_statuses_v700($pdo,$limit);
    $stmt=$pdo->query("SELECT b.*,e.confirmation_enabled,e.reminder_24h_enabled,e.reminder_soon_minutes,e.agent_prep_minutes
                       FROM agent_scheduling_bookings b LEFT JOIN agent_scheduling_event_types e ON e.id=b.event_type_id
                       WHERE b.status IN ('pending','confirmed') AND b.end_at_utc>=UTC_TIMESTAMP() AND b.start_at_utc<=DATE_ADD(UTC_TIMESTAMP(),INTERVAL 60 DAY)
                       ORDER BY b.start_at_utc LIMIT ".max(1,min(500,$limit)));
    $queued=0;
    foreach($stmt->fetchAll()?:[] as $booking){
        $recent=(strtotime((string)$booking['created_at'])?:0)>time()-600;
        agent_appointment_lifecycle_queue_booking_v700($pdo,$booking,$recent);
        $queued++;
    }
    $deliver=$pdo->prepare("SELECT * FROM agent_scheduling_automation_deliveries WHERE status='pending' AND due_at<=UTC_TIMESTAMP() AND attempts<3 ORDER BY due_at,id LIMIT 60");
    $deliver->execute();$rows=$deliver->fetchAll()?:[];$processed=0;
    foreach($rows as $row){try{agent_appointment_lifecycle_process_delivery_v700($pdo,$row);}catch(Throwable $e){$pdo->prepare("UPDATE agent_scheduling_automation_deliveries SET attempts=attempts+1,last_error=?,status=CASE WHEN attempts+1>=3 THEN 'failed' ELSE 'pending' END,updated_at=NOW() WHERE id=?")->execute([mb_strimwidth($e->getMessage(),0,1000,'…'),(int)$row['id']]);}$processed++;}
    return ['queued'=>$queued,'processed'=>$processed,'reconciled'=>$reconciled];
}

function agent_appointment_lifecycle_housekeeping_maybe_v700(): void
{
    static $ran=false;if($ran)return;$ran=true;$pdo=db();if(!$pdo||!agent_appointment_lifecycle_schema_ready_v700($pdo))return;
    if(strtoupper((string)($_SERVER['REQUEST_METHOD']??'GET'))==='POST'){
        register_shutdown_function(static function():void{
            try{$db=db();if($db&&!$db->inTransaction()&&agent_appointment_lifecycle_schema_ready_v700($db)){agent_appointment_lifecycle_reconcile_statuses_v700($db,120);$due=$db->query("SELECT * FROM agent_scheduling_automation_deliveries WHERE status='pending' AND due_at<=UTC_TIMESTAMP() AND attempts<3 ORDER BY due_at,id LIMIT 30")->fetchAll()?:[];foreach($due as $row)agent_appointment_lifecycle_process_delivery_v700($db,$row);}}catch(Throwable $ignored){}
        });
    }
    $last=(int)($_SESSION['vp3_appointment_lifecycle_housekeeping_at']??0);if($last>time()-120)return;$_SESSION['vp3_appointment_lifecycle_housekeeping_at']=time();
    try{agent_appointment_lifecycle_housekeeping_v700($pdo,160);}catch(Throwable $ignored){}
}
