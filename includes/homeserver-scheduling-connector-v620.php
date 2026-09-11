<?php
declare(strict_types=1);

const VP3_HOMESERVER_SCHEDULING_CONNECTOR_V620='homeserver-scheduling-connector-v620-20260911';

function homeserver_scheduling_v620_ensure_schema(PDO $pdo): void
{
    $pdo->exec("CREATE TABLE IF NOT EXISTS homeserver_scheduling_tokens (
        user_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
        token_hash CHAR(64) NOT NULL UNIQUE,
        token_enc TEXT NOT NULL,
        token_prefix VARCHAR(16) NOT NULL DEFAULT '',
        status ENUM('active','revoked') NOT NULL DEFAULT 'active',
        provisioned_at DATETIME NULL,
        last_used_at DATETIME NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        CONSTRAINT fk_hs_sched_token_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $pdo->exec("CREATE TABLE IF NOT EXISTS homeserver_scheduling_idempotency (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        user_id BIGINT UNSIGNED NOT NULL,
        operation VARCHAR(64) NOT NULL,
        idempotency_key VARCHAR(160) NOT NULL,
        response_json LONGTEXT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uq_hs_sched_idempotency (user_id,operation,idempotency_key),
        KEY idx_hs_sched_idem_created (created_at),
        CONSTRAINT fk_hs_sched_idem_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

function homeserver_scheduling_v620_issue_token(int $userId,bool $rotate=false): array
{
    $pdo=db();
    if(!$pdo||$userId<1)throw new RuntimeException('Scheduling connector is unavailable.');
    homeserver_scheduling_v620_ensure_schema($pdo);
    if(!$rotate){
        $stmt=$pdo->prepare("SELECT token_enc,token_prefix,provisioned_at FROM homeserver_scheduling_tokens WHERE user_id=? AND status='active' LIMIT 1");
        $stmt->execute([$userId]);
        $row=$stmt->fetch();
        if($row){
            $token=homeserver_vp3_decrypt((string)$row['token_enc']);
            if($token!=='')return ['token'=>$token,'prefix'=>(string)$row['token_prefix'],'provisioned_at'=>$row['provisioned_at']??null,'rotated'=>false];
        }
    }
    $token='vps_'.rtrim(strtr(base64_encode(random_bytes(36)),'+/','-_'),'=');
    $hash=hash('sha256',$token);
    $prefix=substr($token,0,12);
    $stmt=$pdo->prepare("INSERT INTO homeserver_scheduling_tokens(user_id,token_hash,token_enc,token_prefix,status,provisioned_at)
        VALUES(?,?,?,?, 'active',NULL)
        ON DUPLICATE KEY UPDATE token_hash=VALUES(token_hash),token_enc=VALUES(token_enc),token_prefix=VALUES(token_prefix),status='active',provisioned_at=NULL,last_used_at=NULL,updated_at=NOW()");
    $stmt->execute([$userId,$hash,homeserver_vp3_encrypt($token),$prefix]);
    return ['token'=>$token,'prefix'=>$prefix,'provisioned_at'=>null,'rotated'=>true];
}

function homeserver_scheduling_v620_revoke(int $userId): void
{
    $pdo=db();
    if(!$pdo||$userId<1)return;
    homeserver_scheduling_v620_ensure_schema($pdo);
    $pdo->prepare("UPDATE homeserver_scheduling_tokens SET status='revoked',provisioned_at=NULL,updated_at=NOW() WHERE user_id=?")
        ->execute([$userId]);
}

function homeserver_scheduling_v620_authenticate(string $rawToken): ?int
{
    $rawToken=trim($rawToken);
    if($rawToken===''||strlen($rawToken)>256)return null;
    $pdo=db();
    if(!$pdo)return null;
    homeserver_scheduling_v620_ensure_schema($pdo);
    $stmt=$pdo->prepare("SELECT user_id FROM homeserver_scheduling_tokens WHERE token_hash=? AND status='active' LIMIT 1");
    $stmt->execute([hash('sha256',$rawToken)]);
    $id=(int)($stmt->fetchColumn()?:0);
    if($id>0)$pdo->prepare('UPDATE homeserver_scheduling_tokens SET last_used_at=NOW() WHERE user_id=?')->execute([$id]);
    return $id>0?$id:null;
}

function homeserver_scheduling_v620_connector_status(int $userId): array
{
    $pdo=db();
    if(!$pdo||$userId<1)return ['configured'=>false,'provisioned'=>false,'version'=>'v6.20'];
    homeserver_scheduling_v620_ensure_schema($pdo);
    $stmt=$pdo->prepare('SELECT token_prefix,status,provisioned_at,last_used_at,updated_at FROM homeserver_scheduling_tokens WHERE user_id=? LIMIT 1');
    $stmt->execute([$userId]);
    $row=$stmt->fetch();
    return [
        'configured'=>is_array($row)&&($row['status']??'')==='active',
        'provisioned'=>is_array($row)&&($row['status']??'')==='active'&&!empty($row['provisioned_at']),
        'token_prefix'=>(string)($row['token_prefix']??''),
        'last_used_at'=>$row['last_used_at']??null,
        'updated_at'=>$row['updated_at']??null,
        'version'=>'v6.20',
    ];
}

function homeserver_scheduling_v620_provision(int $userId,bool $rotate=false): array
{
    $status=homeserver_vp3_status($userId,false);
    if(empty($status['connected'])||empty($status['paired']))throw new RuntimeException('HomeServer must be online and paired first.');
    $row=homeserver_vp3_connection($userId);
    if(!$row)throw new RuntimeException('HomeServer connection was not found.');
    $relay=homeserver_vp3_decrypt((string)($row['relay_token_enc']??''));
    $hsToken=homeserver_vp3_decrypt((string)($row['homeserver_token_enc']??''));
    if($relay===''||$hsToken==='')throw new RuntimeException('HomeServer pairing credentials are unavailable.');

    $issued=homeserver_scheduling_v620_issue_token($userId,$rotate);
    $endpoint=url('/api/homeserver-scheduling-v620.php');
    if(!preg_match('#^https://#i',$endpoint)&&!preg_match('#^http://(?:127\.0\.0\.1|localhost)(?::\d+)?/#i',$endpoint)){
        throw new RuntimeException('Scheduling connector requires HTTPS.');
    }
    try{
        $response=homeserver_vp3_remote_operation($relay,'vp3.connector.configure',[
            'endpoint'=>$endpoint,
            'token'=>(string)$issued['token'],
            'version'=>'v6.20',
            'capabilities'=>['overview','availability','booking.create','booking.reschedule','booking.cancel','team.availability','team.booking.create','team.booking.cancel'],
        ],$hsToken);
    }catch(Throwable $e){
        homeserver_scheduling_v620_revoke($userId);
        throw $e;
    }
    $accepted=$response;
    if(isset($response['payload'])&&is_array($response['payload']))$accepted=$response['payload'];
    if(empty($accepted['configured'])){
        homeserver_scheduling_v620_revoke($userId);
        throw new RuntimeException('HomeServer did not accept the scheduling connector.');
    }
    $pdo=db();
    homeserver_scheduling_v620_ensure_schema($pdo);
    $pdo->prepare('UPDATE homeserver_scheduling_tokens SET status=\'active\',provisioned_at=NOW(),updated_at=NOW() WHERE user_id=?')->execute([$userId]);
    return homeserver_scheduling_v620_connector_status($userId);
}

function homeserver_scheduling_v620_maybe_provision(int $userId,array $status): array
{
    $connector=homeserver_scheduling_v620_connector_status($userId);
    if(!empty($status['connected'])&&!empty($status['paired'])&&empty($connector['provisioned'])){
        try{$connector=homeserver_scheduling_v620_provision($userId,false);}
        catch(Throwable $e){$connector['error']='Scheduling connector is awaiting a compatible paired HomeServer.';}
    }
    return $connector;
}

function homeserver_scheduling_v620_safe_booking(array $row,string $kind='personal'): array
{
    return [
        'kind'=>$kind,
        'booking_id'=>(int)($row['id']??0),
        'status'=>(string)($row['status']??''),
        'title'=>(string)($row['event_title']??$row['pool_name']??''),
        'guest_name'=>(string)($row['guest_name']??''),
        'guest_email'=>(string)($row['guest_email']??''),
        'start_at_utc'=>(string)($row['start_at_utc']??''),
        'end_at_utc'=>(string)($row['end_at_utc']??''),
        'timezone'=>(string)($row['organizer_timezone']??$row['pool_timezone']??$row['timezone']??'UTC'),
        'target_id'=>(int)($row['event_type_id']??$row['pool_id']??0),
        'assigned_name'=>(string)($row['assigned_name']??''),
    ];
}

function homeserver_scheduling_v620_safe_slot(array $slot,string $timezone='UTC'): array
{
    return [
        'start_at_utc'=>(string)($slot['start_at_utc']??''),
        'end_at_utc'=>(string)($slot['end_at_utc']??''),
        'start_local'=>(string)($slot['start_local']??''),
        'timezone'=>(string)($slot['timezone']??$timezone),
    ];
}

function homeserver_scheduling_v620_event(PDO $pdo,int $userId,int $eventTypeId): array
{
    $stmt=$pdo->prepare('SELECT e.*,s.owner_user_id,s.timezone AS schedule_timezone FROM agent_scheduling_event_types e JOIN agent_scheduling_schedules s ON s.id=e.schedule_id WHERE e.id=? AND s.owner_user_id=? AND e.is_active=1 AND s.is_active=1 LIMIT 1');
    $stmt->execute([$eventTypeId,$userId]);
    $row=$stmt->fetch();
    if(!$row)throw new RuntimeException('Appointment type is not available.');
    return $row;
}

function homeserver_scheduling_v620_personal_overview(PDO $pdo,int $userId): array
{
    $stmt=$pdo->prepare('SELECT id,name,timezone,is_default FROM agent_scheduling_schedules WHERE owner_user_id=? AND is_active=1 ORDER BY is_default DESC,id');
    $stmt->execute([$userId]);
    $schedules=$stmt->fetchAll()?:[];
    $events=[];
    foreach($schedules as $s){
        $q=$pdo->prepare('SELECT id,title,duration_minutes,location_type,location_value FROM agent_scheduling_event_types WHERE schedule_id=? AND is_active=1 ORDER BY sort_order,title,id');
        $q->execute([(int)$s['id']]);
        foreach($q->fetchAll()?:[] as $e){$e['schedule_id']=(int)$s['id'];$events[]=$e;}
    }
    $q=$pdo->prepare("SELECT * FROM agent_scheduling_bookings WHERE owner_user_id=? AND status IN ('pending','confirmed') AND end_at_utc>=UTC_TIMESTAMP() ORDER BY start_at_utc,id LIMIT 50");
    $q->execute([$userId]);
    $bookings=array_map(fn($r)=>homeserver_scheduling_v620_safe_booking($r,'personal'),$q->fetchAll()?:[]);
    return ['schedules'=>$schedules,'event_types'=>$events,'upcoming'=>$bookings];
}

function homeserver_scheduling_v620_dispatch(int $userId,string $operation,array $args): array
{
    $pdo=db();
    if(!$pdo)throw new RuntimeException('Scheduling database is unavailable.');
    $operation=trim($operation);
    if($operation==='capabilities'){
        return ['version'=>'v6.20','operations'=>['overview','availability','booking.create','booking.reschedule','booking.cancel','team.availability','team.booking.create','team.booking.cancel'],'personal'=>true,'team'=>true,'provider_secrets_exposed'=>false];
    }
    if($operation==='overview'){
        $personal=homeserver_scheduling_v620_personal_overview($pdo,$userId);
        $pools=function_exists('agent_team_scheduling_pool_list_v600')?agent_team_scheduling_pool_list_v600($pdo,$userId,true):[];
        $team=[];
        foreach($pools as $p)$team[]=['pool_id'=>(int)$p['id'],'name'=>(string)$p['name'],'mode'=>(string)$p['mode'],'timezone'=>(string)$p['timezone']];
        $teamUpcoming=[];
        if(function_exists('agent_team_scheduling_upcoming_v600')){
            foreach(agent_team_scheduling_upcoming_v600($pdo,$userId,0,50) as $r)$teamUpcoming[]=homeserver_scheduling_v620_safe_booking($r,'team');
        }
        return ['personal'=>$personal,'teams'=>$team,'team_upcoming'=>$teamUpcoming];
    }
    if($operation==='availability'){
        $eventId=max(1,(int)($args['event_type_id']??$args['target_id']??0));
        $date=(string)($args['date']??'');
        $event=homeserver_scheduling_v620_event($pdo,$userId,$eventId);
        if(!preg_match('/^20\d{2}-\d{2}-\d{2}$/',$date))throw new InvalidArgumentException('date must be YYYY-MM-DD.');
        $slots=agent_scheduling_slots_for_date_v430($pdo,$eventId,$date,false);
        return ['kind'=>'personal','event_type_id'=>$eventId,'date'=>$date,'slots'=>array_slice(array_map(fn($s)=>homeserver_scheduling_v620_safe_slot($s,(string)$event['schedule_timezone']),$slots),0,100)];
    }
    if($operation==='booking.create'){
        $eventId=(int)($args['event_type_id']??$args['target_id']??0);
        $event=homeserver_scheduling_v620_event($pdo,$userId,$eventId);
        $booking=agent_scheduling_create_booking_v430($pdo,[
            'event_type_id'=>$eventId,
            'start_at_utc'=>(string)($args['start_at_utc']??''),
            'guest_timezone'=>(string)($args['guest_timezone']??$event['schedule_timezone']),
            'guest_name'=>(string)($args['guest_name']??''),
            'guest_email'=>(string)($args['guest_email']??''),
            'guest_phone'=>'','guest_notes'=>'','created_by_user_id'=>$userId,'created_by_agent_id'=>0,'source'=>'homeserver',
        ]);
        return ['booking'=>homeserver_scheduling_v620_safe_booking($booking,'personal')];
    }
    if($operation==='booking.reschedule'){
        $id=(int)($args['booking_id']??0);
        $q=$pdo->prepare('SELECT * FROM agent_scheduling_bookings WHERE id=? AND owner_user_id=? LIMIT 1');
        $q->execute([$id,$userId]);
        $old=$q->fetch();
        if(!$old)throw new RuntimeException('Booking was not found.');
        $new=agent_scheduling_tools_reschedule_owner_v460($pdo,['id'=>$userId],$old,(string)($args['start_at_utc']??''),0);
        return ['booking'=>homeserver_scheduling_v620_safe_booking($new,'personal'),'rescheduled_from_id'=>$id];
    }
    if($operation==='booking.cancel'){
        $id=(int)($args['booking_id']??0);
        $q=$pdo->prepare('SELECT * FROM agent_scheduling_bookings WHERE id=? AND owner_user_id=? LIMIT 1');
        $q->execute([$id,$userId]);
        $row=$q->fetch();
        if(!$row||!in_array((string)($row['status']??''),['pending','confirmed'],true))throw new RuntimeException('Booking was not found or is no longer active.');
        if(!agent_scheduling_cancel_booking_v430($pdo,$id,$userId))throw new RuntimeException('Booking could not be cancelled.');
        $row['status']='cancelled';
        return ['booking'=>homeserver_scheduling_v620_safe_booking($row,'personal')];
    }
    if(str_starts_with($operation,'team.')){
        if(!function_exists('agent_team_scheduling_pool_v600'))throw new RuntimeException('Team Scheduling is unavailable.');
        $poolId=(int)($args['pool_id']??$args['target_id']??0);
        $pool=agent_team_scheduling_pool_v600($pdo,$userId,$poolId);
        if(!$pool||empty($pool['is_active']))throw new RuntimeException('Scheduling team was not found.');
        if($operation==='team.availability'){
            $date=(string)($args['date']??'');
            if(!preg_match('/^20\d{2}-\d{2}-\d{2}$/',$date))throw new InvalidArgumentException('date must be YYYY-MM-DD.');
            $slots=agent_team_scheduling_slots_v600($pdo,$pool,$date,(string)($args['routing_answer']??''));
            return ['kind'=>'team','pool_id'=>$poolId,'date'=>$date,'slots'=>array_slice(array_map(fn($s)=>homeserver_scheduling_v620_safe_slot($s,(string)$pool['timezone']),$slots),0,100)];
        }
        if($operation==='team.booking.create'){
            $booking=agent_team_scheduling_create_booking_v600($pdo,$pool,[
                'start_at_utc'=>(string)($args['start_at_utc']??''),
                'guest_timezone'=>(string)($args['guest_timezone']??$pool['timezone']),
                'guest_name'=>(string)($args['guest_name']??''),
                'guest_email'=>(string)($args['guest_email']??''),
                'routing_answer'=>(string)($args['routing_answer']??''),
                'source'=>'homeserver',
            ]);
            return ['booking'=>homeserver_scheduling_v620_safe_booking($booking,'team')];
        }
        if($operation==='team.booking.cancel'){
            $id=(int)($args['booking_id']??0);
            $booking=agent_team_scheduling_booking_v600($pdo,$userId,$id);
            if(!$booking||(int)($booking['pool_id']??0)!==$poolId)throw new RuntimeException('Team booking was not found.');
            if(!agent_team_scheduling_cancel_booking_v600($pdo,$booking))throw new RuntimeException('Team booking could not be cancelled.');
            $booking['status']='cancelled';
            return ['booking'=>homeserver_scheduling_v620_safe_booking($booking,'team')];
        }
    }
    throw new InvalidArgumentException('Unsupported scheduling operation.');
}

function homeserver_scheduling_v620_execute(int $userId,string $operation,array $args,string $idempotencyKey=''): array
{
    $writes=['booking.create','booking.reschedule','booking.cancel','team.booking.create','team.booking.cancel'];
    if(!in_array($operation,$writes,true))return homeserver_scheduling_v620_dispatch($userId,$operation,$args);
    $key=trim($idempotencyKey);
    if($key===''||strlen($key)>160)throw new InvalidArgumentException('A valid idempotency_key is required for scheduling mutations.');
    $pdo=db();
    homeserver_scheduling_v620_ensure_schema($pdo);
    $lockName='vp3_hs_sched_'.substr(hash('sha256',$userId.'|'.$operation.'|'.$key),0,48);
    $lock=$pdo->prepare('SELECT GET_LOCK(?,5)');
    $lock->execute([$lockName]);
    if((int)$lock->fetchColumn()!==1)throw new RuntimeException('Scheduling action is already in progress.');
    try{
        $q=$pdo->prepare('SELECT response_json FROM homeserver_scheduling_idempotency WHERE user_id=? AND operation=? AND idempotency_key=? LIMIT 1');
        $q->execute([$userId,$operation,$key]);
        $existing=$q->fetchColumn();
        if(is_string($existing)&&$existing!==''){
            $decoded=json_decode($existing,true);
            if(is_array($decoded))return $decoded;
        }
        $result=homeserver_scheduling_v620_dispatch($userId,$operation,$args);
        $json=json_encode($result,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
        if($json===false)throw new RuntimeException('Scheduling response could not be stored.');
        $stmt=$pdo->prepare('INSERT INTO homeserver_scheduling_idempotency(user_id,operation,idempotency_key,response_json) VALUES(?,?,?,?) ON DUPLICATE KEY UPDATE response_json=COALESCE(response_json,VALUES(response_json)),updated_at=NOW()');
        $stmt->execute([$userId,$operation,$key,$json]);
        return $result;
    }finally{
        try{$r=$pdo->prepare('SELECT RELEASE_LOCK(?)');$r->execute([$lockName]);}catch(Throwable $ignored){}
    }
}
