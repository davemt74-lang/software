<?php
declare(strict_types=1);

/** Controlled meeting records shared by the local PHP, HTTP and worker fixtures. */
function vp3_test_meeting_prep_seed_v1(PDO $pdo,DateTimeImmutable $now,int $agentId=0): array
{
    $pdo->exec("CREATE TABLE IF NOT EXISTS user_calendar_events(id INTEGER PRIMARY KEY,owner_user_id INTEGER,
        title TEXT,description TEXT,location TEXT,start_at_utc TEXT,end_at_utc TEXT,timezone TEXT,all_day INTEGER,
        recurrence TEXT,agent_notifications INTEGER,source TEXT,source_reference TEXT,status TEXT,created_by_agent_id INTEGER);
    CREATE TABLE IF NOT EXISTS video_meetings(id INTEGER PRIMARY KEY,owner_user_id INTEGER,organizer_agent_id INTEGER,
        public_id TEXT,room_name TEXT,agent_mode TEXT,booking_id INTEGER,calendar_event_id INTEGER,status TEXT,title TEXT,
        start_at_utc TEXT,end_at_utc TEXT);
    CREATE TABLE IF NOT EXISTS video_meeting_participants(id INTEGER PRIMARY KEY,meeting_id INTEGER,user_id INTEGER,role TEXT,
        invitation_status TEXT,invite_token TEXT);
    CREATE TABLE IF NOT EXISTS video_meeting_transcript_segments(id INTEGER PRIMARY KEY);
    CREATE TABLE IF NOT EXISTS video_meeting_artifacts(id INTEGER PRIMARY KEY);
    CREATE TABLE IF NOT EXISTS video_meeting_agenda_items(id INTEGER PRIMARY KEY,meeting_id INTEGER,owner_user_id INTEGER,item_text TEXT,
        item_type TEXT,source_kind TEXT,source_meeting_id INTEGER,source_hash TEXT,source_index_hash TEXT,source_review_path TEXT,
        status TEXT,priority TEXT,action_kind TEXT,approval_state TEXT,sort_order INTEGER,fingerprint TEXT,created_at TEXT,updated_at TEXT);
    CREATE TABLE IF NOT EXISTS video_meeting_continuity_links(id INTEGER PRIMARY KEY,owner_user_id INTEGER,source_meeting_id INTEGER,
        source_agenda_item_id INTEGER,target_meeting_id INTEGER,target_agenda_item_id INTEGER,status TEXT,lineage_hash TEXT,updated_at TEXT);
    CREATE TABLE IF NOT EXISTS video_meeting_continuity_events(id INTEGER PRIMARY KEY);
    CREATE TABLE IF NOT EXISTS video_meeting_action_executions(id INTEGER PRIMARY KEY,agenda_item_id INTEGER,meeting_id INTEGER,owner_user_id INTEGER,
        action_kind TEXT,status TEXT,draft_json TEXT,draft_hash TEXT,idempotency_key TEXT,workflow_run_id INTEGER,calendar_event_id INTEGER,
        crm_activity_id INTEGER,crm_lead_id INTEGER,email_recipient TEXT,error_class TEXT,result_json TEXT,executed_at TEXT,updated_at TEXT);
    CREATE TABLE IF NOT EXISTS video_meeting_action_events(id INTEGER PRIMARY KEY);
    CREATE TABLE IF NOT EXISTS video_meeting_followthrough_closures(id INTEGER PRIMARY KEY,owner_user_id INTEGER,execution_id INTEGER,
        status TEXT,verified_at TEXT);
    CREATE TABLE IF NOT EXISTS video_meeting_transcription_links(meeting_id INTEGER PRIMARY KEY,transcript_session_id INTEGER);
    CREATE TABLE IF NOT EXISTS artist_transcript_sessions_v172(id INTEGER PRIMARY KEY,created_by_user_id INTEGER,conversation_id INTEGER,
        title TEXT,status TEXT,started_at TEXT,last_activity_at TEXT,metadata_json TEXT,agent_memory_id INTEGER);
    CREATE TABLE IF NOT EXISTS artist_transcript_segments_v172(id INTEGER PRIMARY KEY,session_id INTEGER,client_segment_key TEXT,
        segment_index INTEGER,segment_type TEXT,speaker_label TEXT,transcript_text TEXT,started_ms INTEGER,ended_ms INTEGER,
        confidence REAL,created_at TEXT,updated_at TEXT);
    CREATE TABLE IF NOT EXISTS user_data_policies(owner_user_id INTEGER,resource_type TEXT,resource_id TEXT,audience_scope TEXT,
        owner_agents_allowed INTEGER,profile_agent_allowed INTEGER,stonefellow_shared INTEGER);
    CREATE TABLE IF NOT EXISTS user_agent_data_rules(agent_id INTEGER,resource_type TEXT,resource_id TEXT,access_mode TEXT);");
    $utc=$now->setTimezone(new DateTimeZone('UTC'));
    $at=static fn(string $offset):string=>$utc->modify($offset)->format('Y-m-d H:i:s');
    $priorPublic='11111111111111111111111111111111';$nextPublic='22222222222222222222222222222222';
    $calendar=$pdo->prepare("INSERT INTO user_calendar_events(id,owner_user_id,title,description,location,start_at_utc,end_at_utc,
        timezone,all_day,recurrence,agent_notifications,source,source_reference,status,created_by_agent_id)
        VALUES(7901,1,'Delivery review','','',?,?,'UTC',0,'none',1,'automation','meeting-prep-fixture','active',?)");
    $calendar->execute([$at('+20 minutes'),$at('+50 minutes'),$agentId?:null]);
    $meeting=$pdo->prepare("INSERT INTO video_meetings(id,owner_user_id,organizer_agent_id,public_id,room_name,agent_mode,calendar_event_id,status,
        title,start_at_utc,end_at_utc) VALUES(?,1,?,?,'fixture-room','notes',?,?,'Delivery review',?,?)");
    $meeting->execute([7911,$agentId?:null,$priorPublic,null,'ended',$at('-1 day'),$at('-23 hours')]);
    $meeting->execute([7912,$agentId?:null,$nextPublic,7901,'scheduled',$at('+20 minutes'),$at('+50 minutes')]);
    $meeting->execute([7913,$agentId?:null,'33333333333333333333333333333333',null,'ended',$at('-3 hours'),$at('-2 hours')]);
    $pdo->exec("INSERT INTO video_meeting_participants(meeting_id,user_id,role,invitation_status,invite_token)
        VALUES(7911,1,'organizer','accepted','fixture-private-invite'),
        (7912,1,'organizer','accepted','fixture-private-invite'),(7913,1,'organizer','accepted','fixture-private-invite')");
    $agenda=$pdo->prepare("INSERT INTO video_meeting_agenda_items(id,meeting_id,owner_user_id,item_text,item_type,source_kind,
        source_meeting_id,source_hash,source_index_hash,source_review_path,status,priority,action_kind,approval_state,sort_order,fingerprint,created_at,updated_at)
        VALUES(?,?,1,?,?,?,?,?,'','','open','high','','none',?,'',?,?)");
    $update=$at('-30 minutes');
    $agenda->execute([7941,7911,'Assign an owner for the revised delivery plan','follow_up','manual',null,'',1,$update,$update]);
    $agenda->execute([7942,7912,'Assign an owner for the revised delivery plan','follow_up','continuity',7911,hash('sha256','continuity-source|1|7941'),1,$update,$update]);
    $agenda->execute([7943,7912,'Review the revised dates','discussion','manual',null,'',2,$update,$update]);
    $linkHash=hash('sha256','meeting-continuity-v18210|1|7941|7912');
    $pdo->prepare("INSERT INTO video_meeting_continuity_links VALUES(7951,1,7911,7941,7912,7942,'carried_forward',?,?)")
        ->execute([$linkHash,$update]);
    $pdo->exec('INSERT INTO video_meeting_transcription_links VALUES(7911,7921),(7913,7922)');
    $transcript=$pdo->prepare("INSERT INTO artist_transcript_sessions_v172(id,created_by_user_id,conversation_id,title,status,started_at,
        last_activity_at,metadata_json) VALUES(?,1,NULL,'Delivery review','draft',?,?,?)");
    foreach ([[7921,7911,$priorPublic],[7922,7913,'33333333333333333333333333333333']] as [$session,$id,$public]) {
        $transcript->execute([$session,$at('-1 day'),$update,json_encode(['capture_mode'=>'vp3_video_meeting',
            'video_meeting_id'=>$id,'video_meeting_public_id'=>$public],JSON_THROW_ON_ERROR)]);
    }
    $pdo->exec("INSERT INTO artist_transcript_segments_v172(id,session_id,client_segment_key,segment_index,segment_type,speaker_label,
        transcript_text,started_ms,ended_ms) VALUES
        (7931,7921,'fixture-review-quote',1,'transcript','Alex','The revised delivery plan needs an owner by Friday.',12000,15000),
        (7932,7922,'fixture-unlinked-quote',1,'transcript','Other','Unlinked same-title speech.',1000,2000),
        (7933,7921,'fixture-deleted-quote',2,'deleted','Alex','Removed private speech.',16000,18000)");
    return ['calendar_id'=>7901,'prior_meeting_id'=>7911,'meeting_id'=>7912,'transcript_id'=>7921,'segment_id'=>7931,
        'target_agenda_id'=>7942,'link_id'=>7951,'prior_public_id'=>$priorPublic,'meeting_public_id'=>$nextPublic];
}
