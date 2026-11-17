<?php
declare(strict_types=1);

// Disposable fixture media and records. Chat, worker receipts, media actions,
// player endpoints and the audio renderer are the real production code.
function get_tracks(): array
{
    return db()->query('SELECT * FROM tracks WHERE is_published=1 ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
}

function get_track_by_id(int $id): ?array
{
    $q=db()->prepare('SELECT * FROM tracks WHERE id=? AND is_published=1');
    $q->execute([$id]);
    return $q->fetch(PDO::FETCH_ASSOC)?:null;
}

function can_view_track(array $track,?array $user=null): bool
{
    return $track['visibility']==='public'||(int)$track['owner_user_id']===(int)(($user??current_user())['id']??0);
}

function can_manage_track_production(array $track,?array $user=null): bool
{
    return (int)$track['owner_user_id']===(int)(($user??current_user())['id']??0);
}

function initiative_media_http_install_v1(PDO $pdo): void
{
    if(table_exists('tracks'))return;
    $pdo->exec("ALTER TABLE users ADD COLUMN display_name TEXT;
        UPDATE users SET display_name='Fixture Artist' WHERE id=1;
        CREATE TABLE tracks (id INTEGER PRIMARY KEY,owner_user_id INTEGER,workspace_id INTEGER,title TEXT,album TEXT,genre TEXT,
            mood TEXT,audio_path TEXT,is_published INTEGER,visibility TEXT,created_at TEXT,updated_at TEXT);
        INSERT INTO tracks VALUES
            (41,1,14,'Native Midnight','Night Walk','Ambient','calm','native.wav',1,'public',NOW(),NOW()),
            (42,1,14,'First Light','Night Walk','Ambient','calm','native.wav',1,'public',NOW(),NOW()),
            (43,2,15,'PRIVATE FOREIGN TRACK','Other','Ambient','calm','native.wav',0,'private',NOW(),NOW());
        CREATE TABLE artist_workspaces_v181(id INTEGER PRIMARY KEY,artist_user_id INTEGER);
        INSERT INTO artist_workspaces_v181 VALUES(14,1),(15,2);
        CREATE TABLE user_profiles(user_id INTEGER PRIMARY KEY,username TEXT,is_public INTEGER);
        INSERT INTO user_profiles VALUES(1,'fixture-artist',1),(2,'foreign-artist',0);
        CREATE TABLE track_play_sessions (id INTEGER PRIMARY KEY,session_token TEXT,track_id INTEGER,user_id INTEGER,listener_hash TEXT,
            device_type TEXT,referrer_host TEXT,source_context TEXT,duration_seconds REAL,last_position_seconds REAL,max_position_seconds REAL,
            listener_city TEXT,listener_region TEXT,listener_country TEXT,listener_latitude REAL,listener_longitude REAL,
            started_at TEXT DEFAULT CURRENT_TIMESTAMP,last_event_at TEXT DEFAULT CURRENT_TIMESTAMP,ended_at TEXT,
            listened_seconds REAL DEFAULT 0,completion_percent REAL DEFAULT 0,qualified_play INTEGER DEFAULT 0,completed INTEGER DEFAULT 0);
        CREATE TABLE track_play_events (id INTEGER PRIMARY KEY,session_id INTEGER,event_type TEXT,
            position_seconds REAL,listened_delta_seconds REAL);
        INSERT INTO chat_conversations(id,user_id,user_agent_id,title,created_at,updated_at)
            VALUES(14,1,7,'Native Media',NOW(),NOW()),(15,1,7,'Sibling Media Chat',NOW(),NOW());
        INSERT INTO chat_messages(conversation_id,user_id,role,message) VALUES
            (14,1,'user','I am back.'),(14,NULL,'assistant','Ready for native music.');
        UPDATE agent_memory_items SET is_active=0 WHERE id=100");
}

function initiative_media_http_arm_resume_v1(PDO $pdo): void
{
    require_once dirname(__DIR__,2).'/includes/agent-media-entertainment-v1.php';
    vp3_youtube_picks_save_v1($pdo,1,'ambient',false);
    vp3_media_offers_save_v1($pdo,1,true);
    $pdo->exec("DELETE FROM settings WHERE setting_key='media_next_offer_owner_1';
        INSERT INTO user_agent_data_rules(agent_id,resource_type,resource_id,access_mode)
            VALUES(7,'knowledge','playback-history','allow')");
    $q=$pdo->prepare("INSERT INTO track_play_sessions
        (track_id,user_id,source_context,duration_seconds,last_position_seconds,max_position_seconds,
            started_at,last_event_at,ended_at,listened_seconds,qualified_play,completed)
        VALUES (?,?,'player',60,?,?,?, ?,?, ?,1,?)");
    $now=time();
    $q->execute([41,1,24,24,gmdate('Y-m-d H:i:s',$now-300),gmdate('Y-m-d H:i:s',$now-120),
        gmdate('Y-m-d H:i:s',$now-120),24,0]);
    $q->execute([43,2,42,42,gmdate('Y-m-d H:i:s',$now-250),gmdate('Y-m-d H:i:s',$now-80),
        gmdate('Y-m-d H:i:s',$now-80),42,0]);
}

function initiative_media_http_arm_cold_catalog_v1(PDO $pdo): void
{
    require_once dirname(__DIR__,2).'/includes/agent-media-entertainment-v1.php';
    vp3_media_offers_save_v1($pdo,1,true,'calm');
    $pdo->exec("DELETE FROM track_play_events WHERE session_id IN (SELECT id FROM track_play_sessions WHERE user_id=1);
        DELETE FROM track_play_sessions WHERE user_id=1;
        DELETE FROM settings WHERE setting_key='media_next_offer_owner_1';
        UPDATE tracks SET mood='energetic',genre='Electronic' WHERE id=41;
        INSERT INTO chat_conversations(id,user_id,user_agent_id,title,created_at,updated_at)
            VALUES(16,1,7,'First Catalog Pick',NOW(),NOW());
        INSERT INTO chat_messages(conversation_id,user_id,role,message) VALUES
            (16,1,'user','I am here.'),(16,NULL,'assistant','Ready when you are.')");
}

function initiative_media_http_arm_casual_v1(PDO $pdo): void
{
    require_once dirname(__DIR__,2).'/includes/agent-media-entertainment-v1.php';
    vp3_media_offers_save_v1($pdo,1,false,'calm');
    vp3_youtube_picks_save_v1($pdo,1,'ambient',false);
    agent_connection_youtube_set_consent_v1($pdo,1,false);
    $pdo->exec("DELETE FROM track_play_events WHERE session_id IN (SELECT id FROM track_play_sessions WHERE user_id=1);
        DELETE FROM track_play_sessions WHERE user_id=1;
        DELETE FROM settings WHERE setting_key='media_next_offer_owner_1';
        UPDATE agent_memory_items SET is_active=0 WHERE user_id=1 AND subject='coordination-scan';
        UPDATE tracks SET mood='energetic',genre='Electronic' WHERE id=41;
        INSERT INTO chat_conversations(id,user_id,user_agent_id,title,created_at,updated_at)
            VALUES(17,1,7,'Quiet Media Chat',NOW(),NOW()),(18,1,7,'Another Quiet Chat',NOW(),NOW()),
                (19,1,7,'Quiet Email Chat',NOW(),NOW());
        INSERT INTO chat_messages(conversation_id,user_id,role,message) VALUES
            (17,1,'user','Hello again.'),(17,NULL,'assistant','I am here.'),
            (18,1,'user','I opened another Chat.'),(18,NULL,'assistant','I am here too.'),
            (19,1,'user','Hello.'),(19,NULL,'assistant','Ready when you are.')");
}

function initiative_media_http_mailbox_v1(PDO $pdo): void
{
    require_once dirname(__DIR__,2).'/includes/agent-connection-email-chat-v1.php';
    agent_connection_email_draft_schema_v1($pdo);
    agent_connection_email_followup_install_v1($pdo);
    $pdo->exec('CREATE TABLE agent_connections_v1(id INTEGER PRIMARY KEY,owner_user_id INTEGER,provider TEXT,status TEXT,
        read_enabled INTEGER,send_enabled INTEGER,followups_enabled INTEGER,authorization_key TEXT,client_fingerprint TEXT,account_email TEXT);
        CREATE TABLE agent_connection_sends_v1(operation_key TEXT)');
    $pdo->prepare("INSERT INTO agent_connections_v1 VALUES(71,1,'gmail','connected',0,1,0,?,?,'owner@example.test')")
        ->execute([str_repeat('a',64),agent_connections_client_fingerprint_v1()]);
}
