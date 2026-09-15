<?php
declare(strict_types=1);

/**
 * VP3 Phase 18.0 — Video Meetings + LiveKit foundation.
 *
 * VP3 owns durable meeting, invitation, calendar and intelligence state.
 * LiveKit owns only realtime room/media state. HomeServer remains optional.
 */
const VP3_VIDEO_MEETINGS_V1800='video-meetings-v1800-20260915';

function video_meeting_schema_ready_v1800(?PDO $pdo=null): bool
{
    $pdo??=db();
    if(!$pdo)return false;
    foreach(['video_meetings','video_meeting_participants','video_meeting_transcript_segments','video_meeting_artifacts'] as $table){
        if(!table_exists($table))return false;
    }
    return column_exists('video_meetings','public_id')
        && column_exists('video_meetings','room_name')
        && column_exists('video_meetings','agent_mode')
        && column_exists('video_meeting_participants','invite_token');
}

function video_meeting_ensure_schema_v1800(?PDO $pdo=null): void
{
    $pdo??=db();
    if(!$pdo)throw new RuntimeException('Database connection is unavailable.');

    $pdo->exec("CREATE TABLE IF NOT EXISTS video_meetings (
      id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      owner_user_id INT UNSIGNED NOT NULL,
      organizer_agent_id BIGINT UNSIGNED NULL,
      booking_id BIGINT UNSIGNED NULL,
      calendar_event_id BIGINT UNSIGNED NULL,
      public_id CHAR(32) NOT NULL,
      room_name VARCHAR(100) NOT NULL,
      title VARCHAR(190) NOT NULL,
      description TEXT NULL,
      start_at_utc DATETIME NOT NULL,
      end_at_utc DATETIME NOT NULL,
      timezone VARCHAR(80) NOT NULL DEFAULT 'UTC',
      status VARCHAR(24) NOT NULL DEFAULT 'scheduled',
      agent_mode VARCHAR(24) NOT NULL DEFAULT 'notes',
      transcription_enabled TINYINT(1) NOT NULL DEFAULT 1,
      recording_enabled TINYINT(1) NOT NULL DEFAULT 0,
      started_at DATETIME NULL,
      ended_at DATETIME NULL,
      processed_at DATETIME NULL,
      cancelled_at DATETIME NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      UNIQUE KEY uq_video_meeting_public_id (public_id),
      UNIQUE KEY uq_video_meeting_room_name (room_name),
      UNIQUE KEY uq_video_meeting_booking (booking_id),
      UNIQUE KEY uq_video_meeting_calendar_event (calendar_event_id),
      INDEX idx_video_meeting_owner_time (owner_user_id,status,start_at_utc,id),
      INDEX idx_video_meeting_agent (organizer_agent_id,status,start_at_utc,id),
      CONSTRAINT fk_video_meeting_owner FOREIGN KEY (owner_user_id) REFERENCES users(id) ON DELETE CASCADE,
      CONSTRAINT fk_video_meeting_agent FOREIGN KEY (organizer_agent_id) REFERENCES user_agents(id) ON DELETE SET NULL,
      CONSTRAINT fk_video_meeting_booking FOREIGN KEY (booking_id) REFERENCES agent_scheduling_bookings(id) ON DELETE SET NULL,
      CONSTRAINT fk_video_meeting_calendar FOREIGN KEY (calendar_event_id) REFERENCES user_calendar_events(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS video_meeting_participants (
      id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      meeting_id BIGINT UNSIGNED NOT NULL,
      user_id INT UNSIGNED NULL,
      crm_contact_id BIGINT UNSIGNED NULL,
      calendar_event_id BIGINT UNSIGNED NULL,
      display_name VARCHAR(190) NOT NULL DEFAULT '',
      email VARCHAR(190) NOT NULL DEFAULT '',
      role VARCHAR(24) NOT NULL DEFAULT 'attendee',
      invite_token CHAR(64) NOT NULL,
      invitation_status VARCHAR(24) NOT NULL DEFAULT 'invited',
      attendance_status VARCHAR(24) NOT NULL DEFAULT 'invited',
      invited_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      accepted_at DATETIME NULL,
      joined_at DATETIME NULL,
      left_at DATETIME NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      UNIQUE KEY uq_video_meeting_invite (invite_token),
      INDEX idx_video_meeting_participant_meeting (meeting_id,role,id),
      INDEX idx_video_meeting_participant_user (user_id,meeting_id,id),
      INDEX idx_video_meeting_participant_email (email,meeting_id,id),
      INDEX idx_video_meeting_participant_crm (crm_contact_id,meeting_id,id),
      CONSTRAINT fk_video_meeting_participant_meeting FOREIGN KEY (meeting_id) REFERENCES video_meetings(id) ON DELETE CASCADE,
      CONSTRAINT fk_video_meeting_participant_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
      CONSTRAINT fk_video_meeting_participant_crm FOREIGN KEY (crm_contact_id) REFERENCES crm_contacts(id) ON DELETE SET NULL,
      CONSTRAINT fk_video_meeting_participant_calendar FOREIGN KEY (calendar_event_id) REFERENCES user_calendar_events(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS video_meeting_transcript_segments (
      id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      meeting_id BIGINT UNSIGNED NOT NULL,
      participant_id BIGINT UNSIGNED NULL,
      speaker_key VARCHAR(100) NOT NULL DEFAULT '',
      speaker_name VARCHAR(190) NOT NULL DEFAULT '',
      start_ms BIGINT UNSIGNED NOT NULL DEFAULT 0,
      end_ms BIGINT UNSIGNED NOT NULL DEFAULT 0,
      transcript_text TEXT NOT NULL,
      confidence DECIMAL(6,5) NULL,
      source VARCHAR(40) NOT NULL DEFAULT 'livekit',
      is_final TINYINT(1) NOT NULL DEFAULT 1,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      INDEX idx_video_meeting_transcript (meeting_id,start_ms,id),
      INDEX idx_video_meeting_transcript_participant (participant_id,meeting_id,id),
      CONSTRAINT fk_video_meeting_transcript_meeting FOREIGN KEY (meeting_id) REFERENCES video_meetings(id) ON DELETE CASCADE,
      CONSTRAINT fk_video_meeting_transcript_participant FOREIGN KEY (participant_id) REFERENCES video_meeting_participants(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS video_meeting_artifacts (
      id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      meeting_id BIGINT UNSIGNED NOT NULL,
      app_id VARCHAR(60) NOT NULL,
      artifact_type VARCHAR(60) NOT NULL DEFAULT 'analysis',
      result_json LONGTEXT NOT NULL,
      source_hash CHAR(64) NOT NULL,
      generated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      UNIQUE KEY uq_video_meeting_artifact (meeting_id,app_id,source_hash),
      INDEX idx_video_meeting_artifact_meeting (meeting_id,generated_at,id),
      CONSTRAINT fk_video_meeting_artifact_meeting FOREIGN KEY (meeting_id) REFERENCES video_meetings(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

function video_meeting_livekit_config_v1800(): array
{
    global $config;
    $meeting=is_array($config['livekit']??null)?$config['livekit']:[];
    return [
        'url'=>trim((string)(getenv('VP3_LIVEKIT_URL')?:getenv('LIVEKIT_URL')?:($meeting['url']??''))),
        'api_key'=>trim((string)(getenv('VP3_LIVEKIT_API_KEY')?:getenv('LIVEKIT_API_KEY')?:($meeting['api_key']??''))),
        'api_secret'=>trim((string)(getenv('VP3_LIVEKIT_API_SECRET')?:getenv('LIVEKIT_API_SECRET')?:($meeting['api_secret']??''))),
        'agent_name'=>trim((string)(getenv('VP3_LIVEKIT_AGENT_NAME')?:($meeting['agent_name']??''))),
    ];
}

function video_meeting_livekit_ready_v1800(): bool
{
    $cfg=video_meeting_livekit_config_v1800();
    return preg_match('#^wss?://#i',(string)$cfg['url'])===1&&$cfg['api_key']!==''&&$cfg['api_secret']!=='';
}

function video_meeting_livekit_http_base_v1800(): string
{
    $url=(string)video_meeting_livekit_config_v1800()['url'];
    if(str_starts_with($url,'wss://'))return 'https://'.substr($url,6);
    if(str_starts_with($url,'ws://'))return 'http://'.substr($url,5);
    return rtrim($url,'/');
}

function video_meeting_b64url_v1800(string $raw): string
{
    return rtrim(strtr(base64_encode($raw),'+/','-_'),'=');
}

function video_meeting_livekit_jwt_v1800(array $claims): string
{
    $cfg=video_meeting_livekit_config_v1800();
    if(!video_meeting_livekit_ready_v1800())throw new RuntimeException('LiveKit is not configured on this VP3 deployment.');
    $header=video_meeting_b64url_v1800(json_encode(['alg'=>'HS256','typ'=>'JWT'],JSON_UNESCAPED_SLASHES)?:'{}');
    $payload=video_meeting_b64url_v1800(json_encode($claims,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)?:'{}');
    $signature=hash_hmac('sha256',$header.'.'.$payload,(string)$cfg['api_secret'],true);
    return $header.'.'.$payload.'.'.video_meeting_b64url_v1800($signature);
}

function video_meeting_livekit_participant_token_v1800(array $meeting,string $identity,string $displayName): string
{
    $cfg=video_meeting_livekit_config_v1800();
    $now=time();
    return video_meeting_livekit_jwt_v1800([
        'iss'=>(string)$cfg['api_key'],
        'sub'=>$identity,
        'name'=>mb_strimwidth(trim($displayName),0,190,''),
        'nbf'=>$now-5,
        'exp'=>$now+1800,
        'metadata'=>json_encode(['vp3_meeting'=>(string)$meeting['public_id']],JSON_UNESCAPED_SLASHES),
        'video'=>[
            'room'=>(string)$meeting['room_name'],
            'roomJoin'=>true,
            'canPublish'=>true,
            'canSubscribe'=>true,
            'canPublishData'=>true,
        ],
    ]);
}

function video_meeting_livekit_service_token_v1800(array $meeting,string $permission): string
{
    $cfg=video_meeting_livekit_config_v1800();$now=time();
    $grant=['room'=>(string)$meeting['room_name']];
    if($permission==='roomCreate')$grant=['roomCreate'=>true];
    else $grant[$permission]=true;
    return video_meeting_livekit_jwt_v1800([
        'iss'=>(string)$cfg['api_key'],'sub'=>'vp3-service-'.substr(hash('sha256',(string)$meeting['public_id']),0,20),
        'nbf'=>$now-5,'exp'=>$now+300,'video'=>$grant,
    ]);
}

function video_meeting_livekit_delete_room_v1800(array $meeting): bool
{
    if(!video_meeting_livekit_ready_v1800()||!function_exists('curl_init'))return false;
    $base=video_meeting_livekit_http_base_v1800();if($base==='')return false;
    $ch=curl_init(rtrim($base,'/').'/twirp/livekit.RoomService/DeleteRoom');
    curl_setopt_array($ch,[
        CURLOPT_RETURNTRANSFER=>true,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_CONNECTTIMEOUT=>6,CURLOPT_TIMEOUT=>12,CURLOPT_POST=>true,
        CURLOPT_HTTPHEADER=>['Authorization: Bearer '.video_meeting_livekit_service_token_v1800($meeting,'roomCreate'),'Content-Type: application/json'],
        CURLOPT_POSTFIELDS=>json_encode(['room'=>(string)$meeting['room_name']],JSON_UNESCAPED_SLASHES),
    ]);
    curl_exec($ch);$status=(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE);$errno=curl_errno($ch);curl_close($ch);
    return $errno===0&&$status>=200&&$status<300;
}

function video_meeting_absolute_url_v1800(string $path): string
{
    global $config;
    $base=rtrim(trim((string)($config['site']['base_url']??'')),'/');
    if($base===''){
        $host=preg_replace('/[^A-Za-z0-9.\-:\[\]]/','',(string)($_SERVER['HTTP_HOST']??''))??'';
        if($host==='')return url($path);
        $base=((!empty($_SERVER['HTTPS'])&&$_SERVER['HTTPS']!=='off')?'https':'http').'://'.$host;
    }
    return $base.url($path);
}

function video_meeting_owner_url_v1800(array $meeting): string
{
    return video_meeting_absolute_url_v1800('/meeting.php?meeting='.rawurlencode((string)$meeting['public_id']));
}

function video_meeting_invite_url_v1800(array $participant): string
{
    return video_meeting_absolute_url_v1800('/meeting.php?invite='.rawurlencode((string)$participant['invite_token']));
}

function video_meeting_ics_url_v1800(array $participant): string
{
    return video_meeting_absolute_url_v1800('/meeting-ics.php?invite='.rawurlencode((string)$participant['invite_token']));
}

function video_meeting_row_v1800(PDO $pdo,int $meetingId): ?array
{
    if($meetingId<1||!video_meeting_schema_ready_v1800($pdo))return null;
    $stmt=$pdo->prepare('SELECT * FROM video_meetings WHERE id=? LIMIT 1');$stmt->execute([$meetingId]);$row=$stmt->fetch();
    return is_array($row)?$row:null;
}

function video_meeting_by_public_id_v1800(PDO $pdo,string $publicId): ?array
{
    $publicId=strtolower(trim($publicId));
    if(!preg_match('/^[a-f0-9]{32}$/',$publicId)||!video_meeting_schema_ready_v1800($pdo))return null;
    $stmt=$pdo->prepare('SELECT * FROM video_meetings WHERE public_id=? LIMIT 1');$stmt->execute([$publicId]);$row=$stmt->fetch();
    return is_array($row)?$row:null;
}

function video_meeting_for_booking_v1800(PDO $pdo,int $bookingId): ?array
{
    if($bookingId<1||!video_meeting_schema_ready_v1800($pdo))return null;
    $stmt=$pdo->prepare('SELECT * FROM video_meetings WHERE booking_id=? LIMIT 1');$stmt->execute([$bookingId]);$row=$stmt->fetch();
    return is_array($row)?$row:null;
}

function video_meeting_for_calendar_event_v1800(PDO $pdo,int $eventId): ?array
{
    if($eventId<1||!video_meeting_schema_ready_v1800($pdo))return null;
    $stmt=$pdo->prepare('SELECT * FROM video_meetings WHERE calendar_event_id=? LIMIT 1');$stmt->execute([$eventId]);$row=$stmt->fetch();
    return is_array($row)?$row:null;
}

function video_meeting_participants_v1800(PDO $pdo,int $meetingId): array
{
    if($meetingId<1||!video_meeting_schema_ready_v1800($pdo))return [];
    $stmt=$pdo->prepare('SELECT * FROM video_meeting_participants WHERE meeting_id=? ORDER BY role=\'organizer\' DESC,id');$stmt->execute([$meetingId]);
    return $stmt->fetchAll()?:[];
}

function video_meeting_participant_by_invite_v1800(PDO $pdo,string $inviteToken): ?array
{
    $inviteToken=strtolower(trim($inviteToken));
    if(!preg_match('/^[a-f0-9]{64}$/',$inviteToken)||!video_meeting_schema_ready_v1800($pdo))return null;
    $stmt=$pdo->prepare('SELECT p.*,m.public_id,m.owner_user_id,m.status AS meeting_status FROM video_meeting_participants p JOIN video_meetings m ON m.id=p.meeting_id WHERE p.invite_token=? LIMIT 1');
    $stmt->execute([$inviteToken]);$row=$stmt->fetch();return is_array($row)?$row:null;
}

function video_meeting_member_by_email_v1800(PDO $pdo,string $email): ?array
{
    $email=strtolower(trim($email));if($email===''||!filter_var($email,FILTER_VALIDATE_EMAIL))return null;
    $stmt=$pdo->prepare('SELECT * FROM users WHERE LOWER(email)=? AND is_active=1 LIMIT 1');$stmt->execute([$email]);$row=$stmt->fetch();
    return is_array($row)?$row:null;
}

function video_meeting_crm_contact_v1800(PDO $pdo,string $email): ?array
{
    $email=strtolower(trim($email));if($email===''||!filter_var($email,FILTER_VALIDATE_EMAIL)||!table_exists('crm_contacts'))return null;
    try{$stmt=$pdo->prepare('SELECT * FROM crm_contacts WHERE email_normalized=? LIMIT 1');$stmt->execute([$email]);$row=$stmt->fetch();return is_array($row)?$row:null;}catch(Throwable $e){return null;}
}

function video_meeting_user_v1800(PDO $pdo,int $userId): ?array
{
    if($userId<1)return null;$stmt=$pdo->prepare('SELECT * FROM users WHERE id=? AND is_active=1 LIMIT 1');$stmt->execute([$userId]);$row=$stmt->fetch();
    return is_array($row)?$row:null;
}

function video_meeting_agent_name_v1800(PDO $pdo,array $meeting): string
{
    $ownerId=(int)($meeting['owner_user_id']??0);$agentId=(int)($meeting['organizer_agent_id']??0);
    if($ownerId>0&&$agentId>0&&function_exists('user_agent_get_v236')){
        $agent=user_agent_get_v236($pdo,$ownerId,$agentId);$name=trim((string)($agent['display_name']??''));if($name!=='')return $name;
    }
    return function_exists('system_agent_name')?system_agent_name():'VP3';
}

function video_meeting_normalize_times_v1800(string $startUtc,string $endUtc): array
{
    try{
        $start=(new DateTimeImmutable($startUtc,new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('UTC'));
        $end=(new DateTimeImmutable($endUtc,new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('UTC'));
    }catch(Throwable $e){throw new RuntimeException('Choose a valid meeting date and time.');}
    if($end<=$start)throw new RuntimeException('Meeting end must be after its start.');
    if($end->getTimestamp()-$start->getTimestamp()>86400)throw new RuntimeException('A VP3 video meeting cannot be longer than 24 hours.');
    return [$start->format('Y-m-d H:i:s'),$end->format('Y-m-d H:i:s')];
}

function video_meeting_create_v1800(PDO $pdo,array $owner,array $input): array
{
    if(!video_meeting_schema_ready_v1800($pdo))throw new RuntimeException('Video Meetings are not ready. Run the VP3 database upgrade.');
    $ownerId=(int)($owner['id']??0);if($ownerId<1)throw new RuntimeException('A signed-in account is required.');
    $title=trim(preg_replace('/\s+/u',' ',(string)($input['title']??''))??'');if($title==='')throw new RuntimeException('Enter a meeting title.');
    [$start,$end]=video_meeting_normalize_times_v1800((string)($input['start_at_utc']??''),(string)($input['end_at_utc']??''));
    $timezone=function_exists('user_calendar_timezone_v1300')?user_calendar_timezone_v1300((string)($input['timezone']??'UTC')):'UTC';
    $agentId=max(0,(int)($input['organizer_agent_id']??0))?:null;
    if($agentId&&function_exists('user_agent_get_v236')&&!user_agent_get_v236($pdo,$ownerId,$agentId))throw new RuntimeException('Choose one of your Agents.');
    $agentMode=strtolower(trim((string)($input['agent_mode']??'notes')));if(!in_array($agentMode,['off','notes','assistant','active','delegate'],true))$agentMode='notes';
    $bookingId=max(0,(int)($input['booking_id']??0))?:null;$calendarEventId=max(0,(int)($input['calendar_event_id']??0))?:null;
    if($bookingId&&($existing=video_meeting_for_booking_v1800($pdo,$bookingId)))return $existing;
    if($calendarEventId&&($existing=video_meeting_for_calendar_event_v1800($pdo,$calendarEventId)))return $existing;

    $publicId=bin2hex(random_bytes(16));$roomName='vp3-'.bin2hex(random_bytes(16));
    $stmt=$pdo->prepare("INSERT INTO video_meetings (owner_user_id,organizer_agent_id,booking_id,calendar_event_id,public_id,room_name,title,description,start_at_utc,end_at_utc,timezone,status,agent_mode,transcription_enabled,recording_enabled) VALUES (?,?,?,?,?,?,?,?,?,?,?,'scheduled',?,?,?)");
    $stmt->execute([
        $ownerId,$agentId,$bookingId,$calendarEventId,$publicId,$roomName,mb_strimwidth($title,0,190,''),trim((string)($input['description']??''))?:null,
        $start,$end,$timezone,$agentMode,!array_key_exists('transcription_enabled',$input)||!empty($input['transcription_enabled'])?1:0,!empty($input['recording_enabled'])?1:0,
    ]);
    $meeting=video_meeting_row_v1800($pdo,(int)$pdo->lastInsertId());if(!$meeting)throw new RuntimeException('Video meeting could not be created.');

    $ownerEmail=strtolower(trim((string)($owner['email']??'')));$ownerName=trim((string)($owner['display_name']??''))?:'Organizer';
    $participant=video_meeting_add_participant_v1800($pdo,$meeting,['user_id'=>$ownerId,'display_name'=>$ownerName,'email'=>$ownerEmail,'role'=>'organizer'],false);

    if(!$calendarEventId&&empty($input['skip_owner_calendar'])&&function_exists('user_calendar_schema_ready_v1300')&&user_calendar_schema_ready_v1300($pdo)){
        $calendar=user_calendar_create_event_v1300($pdo,$owner,[
            'title'=>$title,'description'=>trim((string)($input['description']??'')),'location'=>video_meeting_owner_url_v1800($meeting),
            'start_at_utc'=>$start,'end_at_utc'=>$end,'timezone'=>$timezone,'all_day'=>false,
        ],'automation',null,'video_meeting:'.$publicId);
        $pdo->prepare('UPDATE video_meetings SET calendar_event_id=? WHERE id=? AND owner_user_id=?')->execute([(int)$calendar['id'],(int)$meeting['id'],$ownerId]);
        $pdo->prepare('UPDATE video_meeting_participants SET calendar_event_id=? WHERE id=?')->execute([(int)$calendar['id'],(int)$participant['id']]);
        $meeting=video_meeting_row_v1800($pdo,(int)$meeting['id'])?:$meeting;
    }
    return $meeting;
}

function video_meeting_add_participant_v1800(PDO $pdo,array $meeting,array $input,bool $deliver=true): array
{
    $meetingId=(int)($meeting['id']??0);if($meetingId<1)throw new RuntimeException('Meeting is unavailable.');
    $email=strtolower(trim((string)($input['email']??'')));if($email!==''&&!filter_var($email,FILTER_VALIDATE_EMAIL))throw new RuntimeException('Enter a valid participant email.');
    $userId=max(0,(int)($input['user_id']??0));$member=$userId?video_meeting_user_v1800($pdo,$userId):($email!==''?video_meeting_member_by_email_v1800($pdo,$email):null);
    if($member){$userId=(int)$member['id'];if($email==='')$email=strtolower(trim((string)($member['email']??'')));}
    else $userId=0;
    $role=strtolower(trim((string)($input['role']??'attendee')));if(!in_array($role,['organizer','attendee'],true))$role='attendee';
    $displayName=trim(preg_replace('/\s+/u',' ',(string)($input['display_name']??''))??'');
    if($displayName===''&&$member)$displayName=trim((string)($member['display_name']??''));
    if($displayName==='')$displayName=$email!==''?strstr($email,'@',true):'Guest';
    $displayName=mb_strimwidth($displayName?:'Guest',0,190,'');

    if($userId>0){$find=$pdo->prepare('SELECT * FROM video_meeting_participants WHERE meeting_id=? AND user_id=? ORDER BY id LIMIT 1');$find->execute([$meetingId,$userId]);if($row=$find->fetch())return $row;}
    if($email!==''){$find=$pdo->prepare('SELECT * FROM video_meeting_participants WHERE meeting_id=? AND email=? ORDER BY id LIMIT 1');$find->execute([$meetingId,$email]);if($row=$find->fetch())return $row;}

    $crm=$email!==''?video_meeting_crm_contact_v1800($pdo,$email):null;$crmId=$crm?(int)$crm['id']:null;$invite=bin2hex(random_bytes(32));
    $stmt=$pdo->prepare("INSERT INTO video_meeting_participants (meeting_id,user_id,crm_contact_id,display_name,email,role,invite_token,invitation_status,attendance_status) VALUES (?,?,?,?,?,?,?,'invited','invited')");
    $stmt->execute([$meetingId,$userId?:null,$crmId,mb_strimwidth($displayName,0,190,''),mb_strimwidth($email,0,190,''),$role,$invite]);
    $id=(int)$pdo->lastInsertId();$stmt=$pdo->prepare('SELECT * FROM video_meeting_participants WHERE id=? LIMIT 1');$stmt->execute([$id]);$participant=$stmt->fetch();
    if(!$participant)throw new RuntimeException('Meeting participant could not be created.');

    if($role==='attendee'&&$userId>0&&$member&&function_exists('user_calendar_schema_ready_v1300')&&user_calendar_schema_ready_v1300($pdo)){
        try{
            $calendar=user_calendar_create_event_v1300($pdo,$member,[
                'title'=>(string)$meeting['title'],'description'=>(string)($meeting['description']??''),'location'=>video_meeting_owner_url_v1800($meeting),
                'start_at_utc'=>(string)$meeting['start_at_utc'],'end_at_utc'=>(string)$meeting['end_at_utc'],'timezone'=>(string)$meeting['timezone'],'all_day'=>false,
            ],'automation',null,'video_meeting:'.(string)$meeting['public_id'].':participant:'.$userId);
            $pdo->prepare('UPDATE video_meeting_participants SET calendar_event_id=? WHERE id=?')->execute([(int)$calendar['id'],$id]);$participant['calendar_event_id']=(int)$calendar['id'];
        }catch(Throwable $ignored){}
    }

    if($role==='attendee'&&$userId>0&&function_exists('create_notification')){
        create_notification($userId,'video_meeting_invitation','Video meeting invitation',(string)$meeting['title'].' · '.(string)$meeting['start_at_utc'].' UTC',url('/meeting.php?meeting='.(string)$meeting['public_id']),'video_meeting',$meetingId);
    }
    if($deliver&&$role==='attendee'&&$email!=='')video_meeting_email_invitation_v1800($pdo,$meeting,$participant);
    return $participant;
}

function video_meeting_email_invitation_v1800(PDO $pdo,array $meeting,array $participant,string $prefix='Video meeting invitation'): bool
{
    $email=strtolower(trim((string)($participant['email']??'')));if(!filter_var($email,FILTER_VALIDATE_EMAIL))return false;
    $owner=video_meeting_user_v1800($pdo,(int)$meeting['owner_user_id']);$ownerName=trim((string)($owner['display_name']??''))?:'VP3';
    $body=$ownerName." invited you to a VP3 video meeting.\n\n".(string)$meeting['title']."\n".(string)$meeting['start_at_utc']." UTC\n\nJoin meeting:\n".video_meeting_invite_url_v1800($participant)."\n\nAdd to calendar:\n".video_meeting_ics_url_v1800($participant)."\n\nYou do not need a VP3 account to use this invitation link.";
    if(function_exists('agent_appointment_lifecycle_email_v700'))return agent_appointment_lifecycle_email_v700($email,$prefix.': '.(string)$meeting['title'],$body);
    return false;
}

function video_meeting_update_calendar_events_v1800(PDO $pdo,array $meeting): void
{
    $location=video_meeting_owner_url_v1800($meeting);
    foreach(video_meeting_participants_v1800($pdo,(int)$meeting['id']) as $participant){
        $eventId=(int)($participant['calendar_event_id']??0);$userId=(int)($participant['user_id']??0);if($eventId<1||$userId<1)continue;
        try{$pdo->prepare("UPDATE user_calendar_events SET title=?,description=?,location=?,start_at_utc=?,end_at_utc=?,timezone=?,status=CASE WHEN ?='cancelled' THEN 'cancelled' ELSE status END,cancelled_at=CASE WHEN ?='cancelled' THEN COALESCE(cancelled_at,UTC_TIMESTAMP()) ELSE cancelled_at END WHERE id=? AND owner_user_id=?")
            ->execute([(string)$meeting['title'],(string)($meeting['description']??''),$location,(string)$meeting['start_at_utc'],(string)$meeting['end_at_utc'],(string)$meeting['timezone'],(string)$meeting['status'],(string)$meeting['status'],$eventId,$userId]);}catch(Throwable $ignored){}
    }
    $ownerEvent=(int)($meeting['calendar_event_id']??0);if($ownerEvent>0){
        try{$pdo->prepare("UPDATE user_calendar_events SET title=?,description=?,location=?,start_at_utc=?,end_at_utc=?,timezone=?,status=CASE WHEN ?='cancelled' THEN 'cancelled' ELSE status END,cancelled_at=CASE WHEN ?='cancelled' THEN COALESCE(cancelled_at,UTC_TIMESTAMP()) ELSE cancelled_at END WHERE id=? AND owner_user_id=?")
            ->execute([(string)$meeting['title'],(string)($meeting['description']??''),$location,(string)$meeting['start_at_utc'],(string)$meeting['end_at_utc'],(string)$meeting['timezone'],(string)$meeting['status'],(string)$meeting['status'],$ownerEvent,(int)$meeting['owner_user_id']]);}catch(Throwable $ignored){}
    }
}

function video_meeting_sync_booking_v1800(PDO $pdo,array $booking,string $reason='updated'): ?array
{
    if(!video_meeting_schema_ready_v1800($pdo)||(int)($booking['id']??0)<1)return null;
    if((string)($booking['location_type']??'')!=='vp3_video')return video_meeting_for_booking_v1800($pdo,(int)$booking['id']);
    $meeting=video_meeting_for_booking_v1800($pdo,(int)$booking['id']);$owner=video_meeting_user_v1800($pdo,(int)$booking['owner_user_id']);if(!$owner)return null;
    if(!$meeting){
        $meeting=video_meeting_create_v1800($pdo,$owner,[
            'booking_id'=>(int)$booking['id'],'skip_owner_calendar'=>true,'title'=>(string)$booking['event_title'],'description'=>(string)($booking['guest_notes']??''),
            'start_at_utc'=>(string)$booking['start_at_utc'],'end_at_utc'=>(string)$booking['end_at_utc'],'timezone'=>(string)$booking['organizer_timezone'],
            'organizer_agent_id'=>(int)($booking['agent_id']??$booking['created_by_agent_id']??0),'agent_mode'=>'notes','transcription_enabled'=>true,'recording_enabled'=>false,
        ]);
        if(trim((string)($booking['guest_email']??''))!==''||trim((string)($booking['guest_name']??''))!==''){
            video_meeting_add_participant_v1800($pdo,$meeting,['display_name'=>(string)$booking['guest_name'],'email'=>(string)$booking['guest_email'],'role'=>'attendee'],false);
        }
    }else{
        $status=in_array((string)($booking['status']??''),['cancelled','no_show'],true)?'cancelled':(string)$meeting['status'];
        $pdo->prepare('UPDATE video_meetings SET title=?,description=?,start_at_utc=?,end_at_utc=?,timezone=?,status=?,cancelled_at=CASE WHEN ?=\'cancelled\' THEN COALESCE(cancelled_at,UTC_TIMESTAMP()) ELSE cancelled_at END WHERE id=? AND owner_user_id=?')
            ->execute([(string)$booking['event_title'],(string)($booking['guest_notes']??''),(string)$booking['start_at_utc'],(string)$booking['end_at_utc'],(string)$booking['organizer_timezone'],$status,$status,(int)$meeting['id'],(int)$booking['owner_user_id']]);
        $meeting=video_meeting_row_v1800($pdo,(int)$meeting['id'])?:$meeting;
    }
    $ownerUrl=video_meeting_owner_url_v1800($meeting);
    $pdo->prepare("UPDATE agent_scheduling_bookings SET location_type='vp3_video',location_value=? WHERE id=? AND owner_user_id=?")->execute([mb_strimwidth($ownerUrl,0,500,''),(int)$booking['id'],(int)$booking['owner_user_id']]);
    video_meeting_update_calendar_events_v1800($pdo,$meeting);
    return $meeting;
}

function video_meeting_cancel_for_booking_v1800(PDO $pdo,array $booking): void
{
    $meeting=video_meeting_for_booking_v1800($pdo,(int)($booking['id']??0));if(!$meeting)return;
    if((string)$meeting['status']!=='cancelled')$pdo->prepare("UPDATE video_meetings SET status='cancelled',cancelled_at=COALESCE(cancelled_at,UTC_TIMESTAMP()) WHERE id=? AND owner_user_id=?")->execute([(int)$meeting['id'],(int)$meeting['owner_user_id']]);
    $meeting=video_meeting_row_v1800($pdo,(int)$meeting['id'])?:$meeting;video_meeting_update_calendar_events_v1800($pdo,$meeting);video_meeting_livekit_delete_room_v1800($meeting);
    foreach(video_meeting_participants_v1800($pdo,(int)$meeting['id']) as $participant){
        $uid=(int)($participant['user_id']??0);if($uid>0&&$uid!==(int)$meeting['owner_user_id']&&function_exists('create_notification'))create_notification($uid,'video_meeting_cancelled','Video meeting cancelled',(string)$meeting['title'],url('/meeting.php?meeting='.(string)$meeting['public_id']),'video_meeting',(int)$meeting['id']);
    }
}

function video_meeting_access_v1800(PDO $pdo,?array $user,string $publicId='',string $inviteToken=''): ?array
{
    $meeting=null;$participant=null;$userId=(int)($user['id']??0);
    if($inviteToken!==''){
        $participant=video_meeting_participant_by_invite_v1800($pdo,$inviteToken);if(!$participant)return null;$meeting=video_meeting_row_v1800($pdo,(int)$participant['meeting_id']);
    }elseif($publicId!=='')$meeting=video_meeting_by_public_id_v1800($pdo,$publicId);
    if(!$meeting)return null;
    if($userId>0){
        if($userId===(int)$meeting['owner_user_id']){
            $stmt=$pdo->prepare("SELECT * FROM video_meeting_participants WHERE meeting_id=? AND user_id=? AND role='organizer' LIMIT 1");$stmt->execute([(int)$meeting['id'],$userId]);$participant=$stmt->fetch()?:null;
        }elseif(!$participant){
            $stmt=$pdo->prepare('SELECT * FROM video_meeting_participants WHERE meeting_id=? AND user_id=? LIMIT 1');$stmt->execute([(int)$meeting['id'],$userId]);$participant=$stmt->fetch()?:null;
        }
    }
    if(!$participant)return null;
    return ['meeting'=>$meeting,'participant'=>$participant,'is_organizer'=>(string)$participant['role']==='organizer'||$userId===(int)$meeting['owner_user_id']];
}

function video_meeting_participant_identity_v1800(array $meeting,array $participant): string
{
    $seed=(int)($participant['user_id']??0)>0?'u:'.(int)$participant['user_id']:'i:'.(string)$participant['invite_token'];
    return 'vp3p-'.substr(hash('sha256',(string)$meeting['public_id'].'|'.$seed),0,28);
}

function video_meeting_mark_presence_v1800(PDO $pdo,array $access,string $action): array
{
    $meeting=$access['meeting'];$participant=$access['participant'];$meetingId=(int)$meeting['id'];$participantId=(int)$participant['id'];$isOrganizer=!empty($access['is_organizer']);
    if($action==='join'){
        $pdo->prepare("UPDATE video_meeting_participants SET attendance_status='joined',invitation_status=CASE WHEN invitation_status='invited' THEN 'accepted' ELSE invitation_status END,accepted_at=COALESCE(accepted_at,UTC_TIMESTAMP()),joined_at=COALESCE(joined_at,UTC_TIMESTAMP()),left_at=NULL WHERE id=? AND meeting_id=?")->execute([$participantId,$meetingId]);
        $pdo->prepare("UPDATE video_meetings SET status='live',started_at=COALESCE(started_at,UTC_TIMESTAMP()) WHERE id=? AND status IN ('scheduled','ready')")->execute([$meetingId]);
        if(!$isOrganizer&&function_exists('create_notification'))create_notification((int)$meeting['owner_user_id'],'video_meeting_participant_joined','Participant joined '.(string)$meeting['title'],(string)$participant['display_name'].' joined the meeting.',url('/meeting.php?meeting='.(string)$meeting['public_id']),'video_meeting_participant',$participantId);
    }elseif($action==='leave'){
        $pdo->prepare("UPDATE video_meeting_participants SET attendance_status='left',left_at=UTC_TIMESTAMP() WHERE id=? AND meeting_id=?")->execute([$participantId,$meetingId]);
    }elseif($action==='end'&&$isOrganizer){
        $pdo->prepare("UPDATE video_meetings SET status='ended',ended_at=COALESCE(ended_at,UTC_TIMESTAMP()) WHERE id=? AND owner_user_id=? AND status<>'cancelled'")->execute([$meetingId,(int)$meeting['owner_user_id']]);
        video_meeting_livekit_delete_room_v1800($meeting);
    }
    return video_meeting_row_v1800($pdo,$meetingId)?:$meeting;
}

function video_meeting_recent_for_user_v1800(PDO $pdo,int $userId,int $limit=50): array
{
    if($userId<1||!video_meeting_schema_ready_v1800($pdo))return [];$limit=max(1,min(100,$limit));
    $stmt=$pdo->prepare("SELECT DISTINCT m.* FROM video_meetings m LEFT JOIN video_meeting_participants p ON p.meeting_id=m.id WHERE m.owner_user_id=? OR p.user_id=? ORDER BY m.start_at_utc DESC,m.id DESC LIMIT {$limit}");
    $stmt->execute([$userId,$userId]);return $stmt->fetchAll()?:[];
}

function video_meeting_upcoming_for_user_v1800(PDO $pdo,int $userId,int $limit=20): array
{
    if($userId<1||!video_meeting_schema_ready_v1800($pdo))return [];$limit=max(1,min(50,$limit));
    $stmt=$pdo->prepare("SELECT DISTINCT m.* FROM video_meetings m LEFT JOIN video_meeting_participants p ON p.meeting_id=m.id WHERE (m.owner_user_id=? OR p.user_id=?) AND m.status IN ('scheduled','ready','live') AND m.end_at_utc>=UTC_TIMESTAMP() ORDER BY m.start_at_utc,m.id LIMIT {$limit}");
    $stmt->execute([$userId,$userId]);return $stmt->fetchAll()?:[];
}

function video_meeting_record_crm_attendance_v1800(PDO $pdo,array $meeting,array $participant,string $event): void
{
    $contactId=(int)($participant['crm_contact_id']??0);if($contactId<1||!function_exists('crm_v180_schema_ready')||!crm_v180_schema_ready($pdo)||!function_exists('crm_v180_activity'))return;
    try{
        $stmt=$pdo->prepare('SELECT l.id FROM crm_leads l WHERE l.contact_id=? AND (l.assigned_user_id=? OR l.assigned_user_id IS NULL) ORDER BY l.updated_at DESC LIMIT 1');$stmt->execute([$contactId,(int)$meeting['owner_user_id']]);$leadId=(int)$stmt->fetchColumn();
        if($leadId>0)crm_v180_activity($pdo,$leadId,'video_meeting_'.$event,mb_strimwidth((string)$meeting['title'].' · '.$event,0,500,'…'),(int)$meeting['owner_user_id'],['meeting_id'=>(int)$meeting['id'],'participant_id'=>(int)$participant['id'],'start_at_utc'=>(string)$meeting['start_at_utc']]);
    }catch(Throwable $ignored){}
}
