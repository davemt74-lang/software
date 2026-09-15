<?php
declare(strict_types=1);

/**
 * VP3 Video Meetings external-calendar bridge v18.1.
 *
 * VP3's existing calendar connection/token/provider stack remains canonical.
 * This layer only persists the provider event IDs needed to project a VP3
 * meeting onto a member attendee's own connected Google/Microsoft calendar.
 */
const VP3_VIDEO_MEETINGS_CALENDAR_V1801='video-meetings-calendar-v1801-20260915';

require_once __DIR__.'/agent-calendar-sync-v500.php';

function video_meeting_external_calendar_schema_ready_v1801(?PDO $pdo=null): bool
{
    $pdo??=db();
    return $pdo
        && table_exists('video_meeting_external_calendar_links')
        && column_exists('video_meeting_external_calendar_links','participant_id')
        && column_exists('video_meeting_external_calendar_links','connection_id')
        && column_exists('video_meeting_external_calendar_links','external_event_id');
}

function video_meeting_external_calendar_ensure_schema_v1801(?PDO $pdo=null): void
{
    $pdo??=db();
    if(!$pdo)throw new RuntimeException('Database connection is unavailable.');
    if(!video_meeting_schema_ready_v1800($pdo))video_meeting_ensure_schema_v1800($pdo);
    if(!agent_calendar_sync_schema_ready_v500($pdo))agent_calendar_sync_ensure_schema_v500($pdo);
    $pdo->exec("CREATE TABLE IF NOT EXISTS video_meeting_external_calendar_links (
      id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      participant_id BIGINT UNSIGNED NOT NULL,
      connection_id BIGINT UNSIGNED NOT NULL,
      external_event_id VARCHAR(500) NOT NULL DEFAULT '',
      external_calendar_id VARCHAR(500) NOT NULL DEFAULT '',
      web_url VARCHAR(1000) NOT NULL DEFAULT '',
      sync_status VARCHAR(24) NOT NULL DEFAULT 'pending',
      last_synced_at DATETIME NULL,
      last_error VARCHAR(1000) NOT NULL DEFAULT '',
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      UNIQUE KEY uq_video_meeting_external_calendar (participant_id,connection_id),
      INDEX idx_video_meeting_external_event (connection_id,external_event_id(190)),
      CONSTRAINT fk_video_meeting_external_participant FOREIGN KEY (participant_id) REFERENCES video_meeting_participants(id) ON DELETE CASCADE,
      CONSTRAINT fk_video_meeting_external_connection FOREIGN KEY (connection_id) REFERENCES agent_calendar_connections(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

function video_meeting_member_join_url_v1801(array $meeting): string
{
    $path='/meeting.php?meeting='.rawurlencode((string)$meeting['public_id']);
    if(function_exists('video_meeting_secure_external_url_v1801'))return video_meeting_secure_external_url_v1801($path);
    return video_meeting_absolute_url_v1800($path);
}

function video_meeting_external_calendar_payload_v1801(array $meeting): array
{
    return [
        'summary'=>mb_strimwidth(trim((string)$meeting['title']),0,190,''),
        'description'=>mb_strimwidth(trim((string)($meeting['description']??'')),0,6000,'…'),
        'start_at_utc'=>(string)$meeting['start_at_utc'],
        'end_at_utc'=>(string)$meeting['end_at_utc'],
        'location'=>video_meeting_member_join_url_v1801($meeting),
    ];
}

function video_meeting_external_calendar_google_v1801(PDO $pdo,array $connection,array $meeting,?string $externalId=null): array
{
    $p=video_meeting_external_calendar_payload_v1801($meeting);
    $calendarId=trim((string)($connection['calendar_id']??'primary'))?:'primary';
    $body=[
        'summary'=>$p['summary'],
        'description'=>$p['description'],
        'location'=>$p['location'],
        'start'=>['dateTime'=>(new DateTimeImmutable($p['start_at_utc'],new DateTimeZone('UTC')))->format(DATE_ATOM),'timeZone'=>'UTC'],
        'end'=>['dateTime'=>(new DateTimeImmutable($p['end_at_utc'],new DateTimeZone('UTC')))->format(DATE_ATOM),'timeZone'=>'UTC'],
        'reminders'=>['useDefault'=>true],
    ];
    $base='https://www.googleapis.com/calendar/v3/calendars/'.rawurlencode($calendarId).'/events';
    $url=$externalId?$base.'/'.rawurlencode($externalId):$base;
    $url.='?sendUpdates=none';
    $json=agent_calendar_sync_api_v500($pdo,$connection,$externalId?'PUT':'POST',$url,$body)['json'];
    return [
        'id'=>(string)($json['id']??$externalId??''),
        'calendar_id'=>$calendarId,
        'web_url'=>(string)($json['htmlLink']??''),
    ];
}

function video_meeting_external_calendar_microsoft_v1801(PDO $pdo,array $connection,array $meeting,?string $externalId=null): array
{
    $p=video_meeting_external_calendar_payload_v1801($meeting);
    $body=[
        'subject'=>$p['summary'],
        'body'=>['contentType'=>'text','content'=>$p['description']],
        'start'=>['dateTime'=>str_replace(' ','T',$p['start_at_utc']),'timeZone'=>'UTC'],
        'end'=>['dateTime'=>str_replace(' ','T',$p['end_at_utc']),'timeZone'=>'UTC'],
        'location'=>['displayName'=>$p['location']],
        'isReminderOn'=>true,
    ];
    $url='https://graph.microsoft.com/v1.0/me/events'.($externalId?'/'.rawurlencode($externalId):'');
    $json=agent_calendar_sync_api_v500($pdo,$connection,$externalId?'PATCH':'POST',$url,$body)['json'];
    return [
        'id'=>(string)($json['id']??$externalId??''),
        'calendar_id'=>(string)($connection['calendar_id']??''),
        'web_url'=>(string)($json['webLink']??''),
    ];
}

function video_meeting_external_calendar_link_v1801(PDO $pdo,int $participantId,int $connectionId): ?array
{
    $stmt=$pdo->prepare('SELECT * FROM video_meeting_external_calendar_links WHERE participant_id=? AND connection_id=? LIMIT 1');
    $stmt->execute([$participantId,$connectionId]);$row=$stmt->fetch();
    return is_array($row)?$row:null;
}

function video_meeting_external_calendar_record_v1801(PDO $pdo,int $participantId,int $connectionId,array $result,string $status='synced',string $error=''): void
{
    $pdo->prepare("INSERT INTO video_meeting_external_calendar_links (participant_id,connection_id,external_event_id,external_calendar_id,web_url,sync_status,last_synced_at,last_error)
      VALUES (?,?,?,?,?,?,NOW(),?)
      ON DUPLICATE KEY UPDATE external_event_id=VALUES(external_event_id),external_calendar_id=VALUES(external_calendar_id),web_url=VALUES(web_url),sync_status=VALUES(sync_status),last_synced_at=NOW(),last_error=VALUES(last_error)")
      ->execute([
          $participantId,$connectionId,
          mb_strimwidth((string)($result['id']??''),0,500,''),
          mb_strimwidth((string)($result['calendar_id']??''),0,500,''),
          mb_strimwidth((string)($result['web_url']??''),0,1000,''),
          mb_strimwidth($status,0,24,''),mb_strimwidth($error,0,1000,'…'),
      ]);
}

function video_meeting_external_calendar_cancel_v1801(PDO $pdo,array $connection,array $participant): void
{
    $participantId=(int)($participant['id']??0);$connectionId=(int)($connection['id']??0);
    if($participantId<1||$connectionId<1)return;
    $link=video_meeting_external_calendar_link_v1801($pdo,$participantId,$connectionId);
    if(!$link||(string)$link['sync_status']==='cancelled')return;
    $externalId=trim((string)($link['external_event_id']??''));
    if($externalId!==''){
        if((string)$connection['provider']==='google'){
            $calendarId=trim((string)($link['external_calendar_id']??$connection['calendar_id']??'primary'))?:'primary';
            agent_calendar_sync_api_v500($pdo,$connection,'DELETE','https://www.googleapis.com/calendar/v3/calendars/'.rawurlencode($calendarId).'/events/'.rawurlencode($externalId).'?sendUpdates=none');
        }elseif((string)$connection['provider']==='microsoft'){
            agent_calendar_sync_api_v500($pdo,$connection,'DELETE','https://graph.microsoft.com/v1.0/me/events/'.rawurlencode($externalId));
        }
    }
    $pdo->prepare("UPDATE video_meeting_external_calendar_links SET sync_status='cancelled',last_synced_at=NOW(),last_error='' WHERE participant_id=? AND connection_id=?")
        ->execute([$participantId,$connectionId]);
}

function video_meeting_external_calendar_sync_participant_v1801(PDO $pdo,array $meeting,array $participant): array
{
    $participantId=(int)($participant['id']??0);$userId=(int)($participant['user_id']??0);
    if($participantId<1||$userId<1||(string)($participant['role']??'')!=='attendee')return ['synced'=>0,'skipped'=>'not_member_attendee'];
    if(!agent_calendar_sync_schema_ready_v500($pdo)||!video_meeting_external_calendar_schema_ready_v1801($pdo))return ['synced'=>0,'skipped'=>'calendar_sync_not_ready'];

    $synced=0;$errors=0;
    foreach(agent_calendar_sync_connections_v500($pdo,$userId) as $connection){
        if(!in_array((string)($connection['provider']??''),['google','microsoft'],true))continue;
        if((string)($connection['status']??'')==='disconnected'||empty($connection['write_enabled']))continue;
        $connectionId=(int)$connection['id'];
        try{
            if((string)($meeting['status']??'')==='cancelled'){
                video_meeting_external_calendar_cancel_v1801($pdo,$connection,$participant);$synced++;continue;
            }
            $link=video_meeting_external_calendar_link_v1801($pdo,$participantId,$connectionId);
            $externalId=trim((string)($link['external_event_id']??''))?:null;
            $result=(string)$connection['provider']==='google'
                ?video_meeting_external_calendar_google_v1801($pdo,$connection,$meeting,$externalId)
                :video_meeting_external_calendar_microsoft_v1801($pdo,$connection,$meeting,$externalId);
            if(trim((string)($result['id']??''))==='')throw new RuntimeException('Calendar provider did not return an event id.');
            video_meeting_external_calendar_record_v1801($pdo,$participantId,$connectionId,$result,'synced','');$synced++;
        }catch(Throwable $e){
            $errors++;
            $link=video_meeting_external_calendar_link_v1801($pdo,$participantId,$connectionId);
            video_meeting_external_calendar_record_v1801($pdo,$participantId,$connectionId,[
                'id'=>(string)($link['external_event_id']??''),
                'calendar_id'=>(string)($link['external_calendar_id']??$connection['calendar_id']??''),
                'web_url'=>(string)($link['web_url']??''),
            ],'error',$e->getMessage());
            if(function_exists('agent_calendar_sync_error_v500'))agent_calendar_sync_error_v500($pdo,$connection,$e);
        }
    }
    return ['synced'=>$synced,'errors'=>$errors];
}

function video_meeting_external_calendar_sync_members_v1801(PDO $pdo,array $meeting): array
{
    $synced=0;$errors=0;
    foreach(video_meeting_participants_v1800($pdo,(int)$meeting['id']) as $participant){
        $result=video_meeting_external_calendar_sync_participant_v1801($pdo,$meeting,$participant);
        $synced+=(int)($result['synced']??0);$errors+=(int)($result['errors']??0);
    }
    return ['synced'=>$synced,'errors'=>$errors];
}
