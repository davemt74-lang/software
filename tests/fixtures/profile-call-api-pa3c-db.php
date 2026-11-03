<?php
declare(strict_types=1);

/** Set up and inspect real SQL. HTTP requests use the production Profile adapters. */
$config = $argv[2] ?? throw new RuntimeException('Fixture configuration is required.');
putenv('VP3_PA3_CONFIG='.$config);
require __DIR__.'/profile-handoff-pa3.php';
function vp3_profile_call_now_pa3c(): int { return $GLOBALS['pa3Now']; }
require_once $pa3Root.'/includes/profile-call-pa3c.php';
require_once $pa3Root.'/includes/chat-settings-v237.php';
require_once $pa3Root.'/includes/profile-call-activity-pa3c.php';

switch ($argv[1] ?? '') {
    case 'init':
        pa3_reset();
        chat_settings_ensure_schema_v237();
        vp3_profile_call_ensure_schema_pa3c($pdo);
        vp3_profile_call_activity_ensure_schema_pa3c($pdo);
        $pdo->exec('CREATE TABLE user_blocks(blocker_user_id INTEGER NOT NULL,blocked_user_id INTEGER NOT NULL,PRIMARY KEY(blocker_user_id,blocked_user_id))');
        $pdo->exec('CREATE TABLE agent_compute_preferences(user_id INTEGER PRIMARY KEY,preference VARCHAR(24) NOT NULL)');
        $pdo->exec('CREATE TABLE agent_compute_overrides(user_id INTEGER NOT NULL,agent_id INTEGER NOT NULL,preference VARCHAR(24) NOT NULL,PRIMARY KEY(user_id,agent_id))');
        $pdo->exec("INSERT INTO agent_compute_preferences VALUES(1,'vp3_cloud')");
        $pdo->exec("INSERT INTO role_permissions VALUES('member','artist_listening.access')");
        // Use the canonical transcript layout. The fixture omits foreign keys to unrelated Studio tables.
        $auto = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql' ? 'BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT';
        $pdo->exec("CREATE TABLE artist_transcript_sessions_v172 (
            id $auto, owner_user_id INTEGER NOT NULL, created_by_user_id INTEGER NOT NULL,
            client_session_key CHAR(64) NOT NULL, title VARCHAR(190) NOT NULL, status VARCHAR(20) NOT NULL,
            language VARCHAR(20) NOT NULL, duration_ms BIGINT NOT NULL DEFAULT 0,
            knowledge_id INTEGER, agent_memory_id BIGINT, project_note_id BIGINT, metadata_json TEXT,
            started_at DATETIME NOT NULL, last_activity_at DATETIME NOT NULL, discarded_at DATETIME,
            UNIQUE(created_by_user_id,client_session_key))");
        $pdo->exec("CREATE TABLE artist_transcript_segments_v172 (
            id $auto, session_id BIGINT NOT NULL, client_segment_key CHAR(64) NOT NULL, segment_index INTEGER NOT NULL,
            segment_type VARCHAR(20) NOT NULL, speaker_label VARCHAR(80) NOT NULL, transcript_text TEXT NOT NULL,
            started_ms BIGINT NOT NULL, ended_ms BIGINT NOT NULL, confidence DECIMAL(5,4),
            UNIQUE(session_id,client_segment_key), UNIQUE(session_id,segment_index))");
        $pdo->exec("INSERT INTO team_user_presence(user_id,presence_mode,social_chat_enabled) VALUES(1,'online',1)");
        echo $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        break;
    case 'assist':
        $pdo->prepare('UPDATE team_user_presence SET agent_assisted_phone_chat_enabled=? WHERE user_id=1')
            ->execute([(int)$argv[3]]);
        break;
    case 'settings':
        $pdo->prepare('UPDATE team_user_presence SET presence_mode=?,social_chat_enabled=? WHERE user_id=1')
            ->execute([$argv[3], (int)$argv[4]]);
        break;
    case 'profile':
        $pdo->prepare('UPDATE user_profiles SET is_public=? WHERE user_id=1')->execute([(int)$argv[3]]);
        break;
    case 'block':
        if ((int)$argv[3] === 1) $pdo->exec('INSERT INTO user_blocks VALUES(1,2)');
        else $pdo->exec('DELETE FROM user_blocks WHERE blocker_user_id=1 AND blocked_user_id=2');
        break;
    case 'password':
        $pdo->prepare('UPDATE users SET password_hash=? WHERE id=2')->execute([$argv[3]]);
        break;
    case 'cleanup_rooms':
        $GLOBALS['pa3Now'] = (int)$argv[3];
        vp3_profile_call_cleanup_pa3c($pdo);
        break;
    default:
        throw new RuntimeException('Unsupported Profile call fixture command.');
}
