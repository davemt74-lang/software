<?php
declare(strict_types=1);

function video_meeting_action_schema_ready_v18150(?PDO $pdo=null): bool
{
    $pdo??=db();
    if(!$pdo)return false;
    foreach(['video_meeting_action_executions','video_meeting_action_events'] as $table){
        if(!table_exists($table))return false;
    }
    foreach(['agenda_item_id','action_kind','status','draft_json','draft_hash','idempotency_key','workflow_run_id','calendar_event_id','crm_activity_id','crm_lead_id','email_recipient','error_class'] as $column){
        if(!column_exists('video_meeting_action_executions',$column))return false;
    }
    return true;
}

function video_meeting_action_ensure_schema_v18150(?PDO $pdo=null): void
{
    $pdo??=db();
    if(!$pdo)throw new RuntimeException('Database connection is unavailable.');
    if(!video_meeting_agenda_schema_ready_v18140($pdo))video_meeting_agenda_ensure_schema_v18140($pdo);
    $pdo->exec("CREATE TABLE IF NOT EXISTS video_meeting_action_executions (
      id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      agenda_item_id BIGINT UNSIGNED NOT NULL,
      meeting_id BIGINT UNSIGNED NOT NULL,
      owner_user_id INT UNSIGNED NOT NULL,
      action_kind VARCHAR(24) NOT NULL,
      status VARCHAR(24) NOT NULL DEFAULT 'needs_review',
      draft_json LONGTEXT NULL,
      draft_hash CHAR(64) NOT NULL DEFAULT '',
      idempotency_key CHAR(64) NOT NULL,
      workflow_run_id BIGINT UNSIGNED NULL,
      calendar_event_id BIGINT UNSIGNED NULL,
      crm_activity_id BIGINT UNSIGNED NULL,
      crm_lead_id BIGINT UNSIGNED NULL,
      email_recipient VARCHAR(190) NOT NULL DEFAULT '',
      result_summary VARCHAR(1000) NOT NULL DEFAULT '',
      result_json LONGTEXT NULL,
      error_class VARCHAR(80) NOT NULL DEFAULT '',
      last_error VARCHAR(1000) NOT NULL DEFAULT '',
      approved_at DATETIME NULL,
      executing_at DATETIME NULL,
      executed_at DATETIME NULL,
      completed_at DATETIME NULL,
      failed_at DATETIME NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      UNIQUE KEY uq_video_meeting_action_item (agenda_item_id),
      UNIQUE KEY uq_video_meeting_action_idempotency (owner_user_id,idempotency_key),
      INDEX idx_video_meeting_action_meeting (meeting_id,status,id),
      INDEX idx_video_meeting_action_owner (owner_user_id,status,updated_at,id),
      CONSTRAINT fk_video_meeting_action_item FOREIGN KEY (agenda_item_id) REFERENCES video_meeting_agenda_items(id) ON DELETE RESTRICT,
      CONSTRAINT fk_video_meeting_action_meeting FOREIGN KEY (meeting_id) REFERENCES video_meetings(id) ON DELETE CASCADE,
      CONSTRAINT fk_video_meeting_action_owner FOREIGN KEY (owner_user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS video_meeting_action_events (
      id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      execution_id BIGINT UNSIGNED NOT NULL,
      meeting_id BIGINT UNSIGNED NOT NULL,
      owner_user_id INT UNSIGNED NOT NULL,
      event_type VARCHAR(60) NOT NULL,
      from_status VARCHAR(24) NOT NULL DEFAULT '',
      to_status VARCHAR(24) NOT NULL DEFAULT '',
      actor_kind VARCHAR(24) NOT NULL DEFAULT 'user',
      summary VARCHAR(500) NOT NULL DEFAULT '',
      metadata_json TEXT NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      INDEX idx_video_meeting_action_event_execution (execution_id,id),
      INDEX idx_video_meeting_action_event_owner (owner_user_id,created_at,id),
      CONSTRAINT fk_video_meeting_action_event_execution FOREIGN KEY (execution_id) REFERENCES video_meeting_action_executions(id) ON DELETE CASCADE,
      CONSTRAINT fk_video_meeting_action_event_meeting FOREIGN KEY (meeting_id) REFERENCES video_meetings(id) ON DELETE CASCADE,
      CONSTRAINT fk_video_meeting_action_event_owner FOREIGN KEY (owner_user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

function video_meeting_action_text_v18150(mixed $value,int $limit=1500): string
{
    $text=trim(preg_replace('/\s+/u',' ',(string)$value)??(string)$value);
    return mb_strimwidth($text,0,$limit,'…');
}

function video_meeting_action_multiline_v18150(mixed $value,int $limit=12000): string
{
    $text=str_replace(["\r\n","\r"],"\n",trim((string)$value));
    return mb_strimwidth($text,0,$limit,'…');
}

function video_meeting_action_json_v18150(array $value): string
{
    $json=json_encode($value,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE);
    return is_string($json)?$json:'{}';
}

function video_meeting_action_decode_v18150(mixed $value): array
{
    if(is_array($value))return $value;
    $decoded=json_decode((string)$value,true);
    return is_array($decoded)?$decoded:[];
}

function video_meeting_action_idempotency_v18150(array $meeting,array $item): string
{
    return hash('sha256','meeting-action-v18150|'.(int)$meeting['id'].'|'.(int)$item['id'].'|'.(int)$meeting['owner_user_id']);
}

function video_meeting_action_event_v18150(PDO $pdo,array $execution,string $eventType,string $from,string $to,string $summary,array $metadata=[]): void
{
    $stmt=$pdo->prepare('INSERT INTO video_meeting_action_events (execution_id,meeting_id,owner_user_id,event_type,from_status,to_status,actor_kind,summary,metadata_json) VALUES (?,?,?,?,?,?,?,?,?)');
    $stmt->execute([
        (int)$execution['id'],(int)$execution['meeting_id'],(int)$execution['owner_user_id'],
        video_meeting_action_text_v18150($eventType,60),video_meeting_action_text_v18150($from,24),video_meeting_action_text_v18150($to,24),
        'user',video_meeting_action_text_v18150($summary,500),$metadata?video_meeting_action_json_v18150($metadata):null,
    ]);
}

function video_meeting_action_item_v18150(PDO $pdo,array $meeting,int $ownerUserId,int $itemId,bool $forUpdate=false): array
{
    video_meeting_agenda_owner_guard_v18140($meeting,$ownerUserId);
    if($itemId<1)throw new RuntimeException('Agenda item not found.');
    $stmt=$pdo->prepare('SELECT * FROM video_meeting_agenda_items WHERE id=? AND meeting_id=? AND owner_user_id=? LIMIT 1'.($forUpdate?' FOR UPDATE':''));
    $stmt->execute([$itemId,(int)$meeting['id'],$ownerUserId]);
    $row=$stmt->fetch();
    if(!is_array($row))throw new RuntimeException('Agenda item not found.');
    return $row;
}

function video_meeting_action_require_eligible_v18150(array $item): string
{
    $kind=video_meeting_agenda_normalize_action_v18140((string)($item['action_kind']??''));
    if($kind==='')throw new RuntimeException('Choose Task, Calendar, CRM, or Email in the agenda first.');
    if((string)($item['approval_state']??'')!=='approved_for_agent_review')throw new RuntimeException('Approve this agenda item for Agent review before preparing execution.');
    if((string)($item['status']??'')!=='follow_up')throw new RuntimeException('Only follow-up agenda items can become meeting actions.');
    return $kind;
}

function video_meeting_action_row_v18150(PDO $pdo,int $ownerUserId,int $executionId,bool $forUpdate=false): ?array
{
    if($ownerUserId<1||$executionId<1||!video_meeting_action_schema_ready_v18150($pdo))return null;
    $stmt=$pdo->prepare('SELECT * FROM video_meeting_action_executions WHERE id=? AND owner_user_id=? LIMIT 1'.($forUpdate?' FOR UPDATE':''));
    $stmt->execute([$executionId,$ownerUserId]);
    $row=$stmt->fetch();
    return is_array($row)?$row:null;
}

function video_meeting_action_row_for_item_v18150(PDO $pdo,int $ownerUserId,int $itemId,bool $forUpdate=false): ?array
{
    if($ownerUserId<1||$itemId<1||!video_meeting_action_schema_ready_v18150($pdo))return null;
    $stmt=$pdo->prepare('SELECT * FROM video_meeting_action_executions WHERE agenda_item_id=? AND owner_user_id=? LIMIT 1'.($forUpdate?' FOR UPDATE':''));
    $stmt->execute([$itemId,$ownerUserId]);
    $row=$stmt->fetch();
    return is_array($row)?$row:null;
}

function video_meeting_action_guard_agenda_mutation_v18150(PDO $pdo,int $ownerUserId,int $itemId,string $agendaAction,array $input=[]): void
{
    if($ownerUserId<1||$itemId<1||!table_exists('video_meeting_action_executions'))return;
    $stmt=$pdo->prepare('SELECT action_kind,status FROM video_meeting_action_executions WHERE agenda_item_id=? AND owner_user_id=? LIMIT 1');
    $stmt->execute([$itemId,$ownerUserId]);$execution=$stmt->fetch();if(!is_array($execution))return;
    $status=(string)($execution['status']??'');$kind=(string)($execution['action_kind']??'');
    if($agendaAction==='action_state'){
        $requestedKind=video_meeting_agenda_normalize_action_v18140((string)($input['action_kind']??''));
        $requestedState=strtolower(trim((string)($input['approval_state']??'')));
        if($requestedKind===$kind&&$requestedState==='approved_for_agent_review')return;
    }
    if(in_array($status,['needs_review','failed'],true))throw new RuntimeException('Discard the Meeting Action draft in the Actions tab before changing this agenda item.');
    throw new RuntimeException('This agenda item has Meeting Action execution history and is locked to preserve its audit trail.');
}

function video_meeting_action_email_candidates_v18150(PDO $pdo,array $meeting): array
{
    $stmt=$pdo->prepare("SELECT id,display_name,email FROM video_meeting_participants WHERE meeting_id=? AND role='attendee' AND email<>'' ORDER BY id LIMIT 20");
    $stmt->execute([(int)$meeting['id']]);$rows=[];$seen=[];
    foreach($stmt->fetchAll()?:[] as $row){
        $email=strtolower(trim((string)($row['email']??'')));
        if(!filter_var($email,FILTER_VALIDATE_EMAIL)||isset($seen[$email]))continue;
        $seen[$email]=true;
        $rows[]=['participant_id'=>(int)$row['id'],'display_name'=>video_meeting_action_text_v18150($row['display_name']??'',190),'email'=>$email];
    }
    return $rows;
}

function video_meeting_action_crm_candidates_v18150(PDO $pdo,array $meeting,array $user): array
{
    if(!crm_v180_can_manage($user)||!crm_v180_schema_ready($pdo))return [];
    $stmt=$pdo->prepare("SELECT DISTINCT l.id AS lead_id,c.name,c.company,l.stage,l.priority
      FROM video_meeting_participants p
      JOIN crm_contacts c ON c.id=p.crm_contact_id
      JOIN crm_leads l ON l.contact_id=c.id
      WHERE p.meeting_id=? AND l.stage NOT IN ('won','lost','archived')
      ORDER BY l.updated_at DESC,l.id DESC LIMIT 20");
    $stmt->execute([(int)$meeting['id']]);$out=[];
    foreach($stmt->fetchAll()?:[] as $row)$out[]=[
        'lead_id'=>(int)$row['lead_id'],'name'=>video_meeting_action_text_v18150($row['name']??'',120),
        'company'=>video_meeting_action_text_v18150($row['company']??'',190),'stage'=>(string)$row['stage'],'priority'=>(string)$row['priority'],
    ];
    return $out;
}

function video_meeting_action_default_draft_v18150(PDO $pdo,array $meeting,array $item,array $user): array
{
    $kind=video_meeting_action_require_eligible_v18150($item);
    $text=video_meeting_action_text_v18150($item['item_text']??'',1500);
    $title=video_meeting_action_text_v18150($text,190);
    if($kind==='task'){
        return ['title'=>$title,'description'=>$text,'priority'=>video_meeting_agenda_normalize_priority_v18140((string)($item['priority']??'normal'))];
    }
    if($kind==='calendar'){
        return [
            'title'=>$title,'date'=>'','start_time'=>'','duration_minutes'=>60,
            'timezone'=>user_calendar_default_timezone_v1300($pdo,$user),'description'=>$text,'location'=>'',
        ];
    }
    if($kind==='crm'){
        $candidates=video_meeting_action_crm_candidates_v18150($pdo,$meeting,$user);
        return ['lead_id'=>count($candidates)===1?(int)$candidates[0]['lead_id']:0,'activity_type'=>'meeting_follow_up','summary'=>$text];
    }
    $candidates=video_meeting_action_email_candidates_v18150($pdo,$meeting);
    $recipient=count($candidates)===1?(string)$candidates[0]['email']:'';
    $name=count($candidates)===1?trim((string)$candidates[0]['display_name']):'';
    $host=trim((string)($user['display_name']??''))?:'VP3';
    $body=($name!==''?'Hi '.$name.',':'Hello,')."\n\nFollowing up on ".trim((string)($meeting['title']??'our meeting')).":\n\n".$text."\n\nBest,\n".$host;
    return ['recipient_email'=>$recipient,'subject'=>'Follow-up: '.video_meeting_action_text_v18150($meeting['title']??'Meeting',170),'body'=>$body];
}

function video_meeting_action_options_v18150(PDO $pdo,array $meeting,string $kind,array $user): array
{
    return match($kind){
        'email'=>['participants'=>video_meeting_action_email_candidates_v18150($pdo,$meeting)],
        'crm'=>['leads'=>video_meeting_action_crm_candidates_v18150($pdo,$meeting,$user),'crm_manage_allowed'=>crm_v180_can_manage($user)],
        'calendar'=>['timezone'=>user_calendar_default_timezone_v1300($pdo,$user)],
        default=>[],
    };
}

function video_meeting_action_sanitize_draft_v18150(string $kind,array $draft): array
{
    if($kind==='task'){
        $priority=video_meeting_agenda_normalize_priority_v18140((string)($draft['priority']??'normal'));
        return [
            'title'=>video_meeting_action_text_v18150($draft['title']??'',190),
            'description'=>video_meeting_action_multiline_v18150($draft['description']??'',5000),
            'priority'=>$priority,
        ];
    }
    if($kind==='calendar'){
        $date=trim((string)($draft['date']??''));if($date!==''&&!preg_match('/^\d{4}-\d{2}-\d{2}$/',$date))$date='';
        $time=trim((string)($draft['start_time']??''));if($time!==''&&!preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/',$time))$time='';
        $timezone=trim((string)($draft['timezone']??'UTC'));
        try{new DateTimeZone($timezone);}catch(Throwable $e){$timezone='UTC';}
        return [
            'title'=>video_meeting_action_text_v18150($draft['title']??'',190),'date'=>$date,'start_time'=>$time,
            'duration_minutes'=>max(5,min(1440,(int)($draft['duration_minutes']??60))),'timezone'=>$timezone,
            'description'=>video_meeting_action_multiline_v18150($draft['description']??'',10000),
            'location'=>video_meeting_action_text_v18150($draft['location']??'',500),
        ];
    }
    if($kind==='crm'){
        $activity=video_meeting_action_text_v18150($draft['activity_type']??'meeting_follow_up',50);
        if(!preg_match('/^[a-z0-9_]+$/',$activity))$activity='meeting_follow_up';
        return ['lead_id'=>max(0,(int)($draft['lead_id']??0)),'activity_type'=>$activity,'summary'=>video_meeting_action_text_v18150($draft['summary']??'',500)];
    }
    return [
        'recipient_email'=>strtolower(trim((string)($draft['recipient_email']??''))),
        'subject'=>video_meeting_action_text_v18150($draft['subject']??'',190),
        'body'=>video_meeting_action_multiline_v18150($draft['body']??'',12000),
    ];
}

function video_meeting_action_validate_draft_v18150(PDO $pdo,array $meeting,string $kind,array $draft,array $user): void
{
    if($kind==='task'){
        if(trim((string)($draft['title']??''))==='')throw new RuntimeException('Enter a Task title.');
        return;
    }
    if($kind==='calendar'){
        if(trim((string)($draft['title']??''))==='')throw new RuntimeException('Enter a Calendar title.');
        if(!preg_match('/^\d{4}-\d{2}-\d{2}$/',(string)($draft['date']??'')))throw new RuntimeException('Choose a Calendar date.');
        if(!preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/',(string)($draft['start_time']??'')))throw new RuntimeException('Choose a Calendar start time.');
        $timezone=(string)($draft['timezone']??'');try{new DateTimeZone($timezone);}catch(Throwable $e){throw new RuntimeException('Choose a valid Calendar timezone.');}
        user_calendar_local_range_v1300($draft,$timezone);
        return;
    }
    if($kind==='crm'){
        if(!crm_v180_can_manage($user))throw new RuntimeException('CRM execution is not available to this account.');
        $leadId=max(0,(int)($draft['lead_id']??0));if($leadId<1)throw new RuntimeException('Choose a CRM lead.');
        $stmt=$pdo->prepare('SELECT id FROM crm_leads WHERE id=? LIMIT 1');$stmt->execute([$leadId]);if(!(int)$stmt->fetchColumn())throw new RuntimeException('CRM lead not found.');
        if(trim((string)($draft['summary']??''))==='')throw new RuntimeException('Enter the CRM update summary.');
        return;
    }
    $email=strtolower(trim((string)($draft['recipient_email']??'')));
    if(!filter_var($email,FILTER_VALIDATE_EMAIL))throw new RuntimeException('Choose a valid meeting participant email.');
    $participant=$pdo->prepare("SELECT id FROM video_meeting_participants WHERE meeting_id=? AND role='attendee' AND LOWER(email)=? LIMIT 1");
    $participant->execute([(int)$meeting['id'],$email]);if(!(int)$participant->fetchColumn())throw new RuntimeException('Email actions can only be sent to an attendee on this meeting.');
    if(trim((string)($draft['subject']??''))===''||trim((string)($draft['body']??''))==='')throw new RuntimeException('Email needs both a subject and message.');
}
