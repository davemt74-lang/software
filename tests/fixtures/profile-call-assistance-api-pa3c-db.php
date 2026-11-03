<?php
declare(strict_types=1);

$action = $argv[1] ?? '';
$config = $argv[2] ?? throw new RuntimeException('Fixture configuration required.');
if ($action === 'cleanup') {
    // Reuse the native call fixture's isolated MySQL database cleanup.
    require __DIR__.'/profile-calls-api-pa3c-db.php';
    exit;
}

putenv('VP3_PA3_CONFIG='.$config);
require __DIR__.'/profile-calls-pa3c.php';
require_once dirname(__DIR__, 2).'/includes/chat-settings-v237.php';
require_once dirname(__DIR__, 2).'/includes/profile-call-activity-pa3c.php';

switch ($action) {
    case 'init':
        pa3c_reset();
        chat_settings_ensure_schema_v237($pdo);
        vp3_profile_call_activity_ensure_schema_pa3c($pdo);
        $pdo->exec('CREATE TABLE agent_compute_preferences(user_id INTEGER PRIMARY KEY,preference VARCHAR(24) NOT NULL)');
        $pdo->exec('CREATE TABLE agent_compute_overrides(user_id INTEGER NOT NULL,agent_id INTEGER NOT NULL,preference VARCHAR(24) NOT NULL,PRIMARY KEY(user_id,agent_id))');
        $pdo->exec("INSERT INTO agent_compute_preferences VALUES(1,'vp3_cloud')");
        $pdo->exec("INSERT INTO role_permissions VALUES('member','artist_listening.access')");
        $pdo->exec("INSERT INTO team_user_presence(user_id,presence_mode,social_chat_enabled) VALUES(1,'online',1)");

        // The existing transcript schema is required by the actual endpoints.
        // Studio-only foreign keys are intentionally absent from this fixture.
        $auto = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql'
            ? 'BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY'
            : 'INTEGER PRIMARY KEY AUTOINCREMENT';
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
        echo $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        break;
    case 'assist':
        $pdo->prepare('UPDATE team_user_presence SET agent_assisted_phone_chat_enabled=? WHERE user_id=1')
            ->execute([(int)$argv[3]]);
        break;
    case 'state':
        $tables = [
            'profile_calls_pa3c', 'profile_call_slots_pa3c', 'profile_call_assistance_pa3c',
            'profile_call_research_pa3c', 'artist_transcript_sessions_v172', 'artist_transcript_segments_v172',
        ];
        $state = [];
        foreach ($tables as $table) $state[$table] = $pdo->query('SELECT * FROM '.$table)->fetchAll();
        echo json_encode($state, JSON_THROW_ON_ERROR);
        break;
    default:
        throw new RuntimeException('Unsupported assistance fixture command.');
}
