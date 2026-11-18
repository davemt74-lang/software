<?php
declare(strict_types=1);

require_once __DIR__.'/profile-specialist-sources-v1.php';
require_once dirname(__DIR__,2).'/includes/profile-workspace-calls-v1.php';
require_once dirname(__DIR__,2).'/includes/agent-compute-v020.php';
require_once dirname(__DIR__,2).'/includes/agent-compute-v023.php';
require_once dirname(__DIR__,2).'/includes/chat-settings-v237.php';

// Physical service configuration only; source, private scope and call actions are real.
function vp3_profile_call_livekit_assistance_configured_pa3c(): bool{return true;}
function ai_active_provider(): string{return 'local';}

function pwc_reset(): void
{
    pfs_reset();$pdo=db();
    if(table_exists('artist_transcript_segments_v172'))$pdo->exec('DELETE FROM artist_transcript_segments_v172');
    $auto=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?'BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY':'INTEGER PRIMARY KEY AUTOINCREMENT';
    $pdo->exec("CREATE TABLE IF NOT EXISTS artist_transcript_segments_v172(id $auto,session_id BIGINT,client_segment_key CHAR(64),segment_type VARCHAR(20),speaker_label VARCHAR(80),transcript_text TEXT,started_ms BIGINT)");
    foreach(['client_session_key'=>'CHAR(64)','knowledge_id'=>'INTEGER','agent_memory_id'=>'BIGINT','project_note_id'=>'BIGINT'] as $column=>$definition)
        profile_specialist_fixture_column_v1($pdo,'artist_transcript_sessions_v172',$column,$definition.' NULL');
}

function pwc_snapshot(): array
{
    $out=pa4b_snapshot()+pp_no_external_snapshot();
    foreach(['profile_calls_pa3c','profile_call_deliveries_pa3c','profile_call_assistance_pa3c','profile_call_research_pa3c',
        'artist_transcript_sessions_v172','artist_transcript_segments_v172'] as $table)
        $out[$table]=db()->query('SELECT * FROM '.$table)->fetchAll(PDO::FETCH_ASSOC);
    return $out;
}

function pwc_activity_seed(int $agent=8): array
{
    $record=pfs_followup(84001,$agent);
    db()->prepare('INSERT INTO artist_transcript_segments_v172(id,session_id,client_segment_key,segment_type,speaker_label,transcript_text,started_ms) VALUES(?,?,?,\'transcript\',\'Visitor\',\'EXACT_CALL_TRANSCRIPT\',1200)')
        ->execute([84003,$record['session'],hash('sha256','workspace-segment')]);
    db()->prepare("INSERT INTO profile_call_research_pa3c(call_id,request_id,capture_revision,query,status,started_at_ms,result_json) VALUES(?, ?,1,'Public opening hours','complete',?,?)")
        ->execute([$record['public_id'],str_repeat('r',32),$GLOBALS['pa3Now'],json_encode(['summary'=>'EXACT_CALL_RESEARCH','sources'=>[['title'=>'Public source','url'=>'https://example.com/']]],JSON_THROW_ON_ERROR)]);
    return $record;
}

function pwc_live_assisted(): array
{
    putenv('VP3_PROFILE_CALL_AGENT_ENABLED=1');putenv('VP3_PROFILE_CALL_WORKER_SECRET=fixture-workspace-call-worker-secret');putenv('VP3_PROFILE_CALL_AGENT_NAME=fixture-profile-worker');
    $pdo=db();chat_settings_ensure_schema_v237($pdo);
    $pdo->exec('CREATE TABLE IF NOT EXISTS agent_compute_preferences(user_id INTEGER PRIMARY KEY,preference VARCHAR(24))');
    $pdo->exec('CREATE TABLE IF NOT EXISTS agent_compute_overrides(user_id INTEGER,agent_id INTEGER,preference VARCHAR(24),PRIMARY KEY(user_id,agent_id))');
    $pdo->exec('DELETE FROM agent_compute_preferences');$pdo->exec("INSERT INTO agent_compute_preferences VALUES(1,'vp3_cloud')");
    $pdo->exec('DELETE FROM team_user_presence');$pdo->exec("INSERT INTO team_user_presence(user_id,presence_mode,social_chat_enabled,agent_assisted_phone_chat_enabled) VALUES(1,'online',1,1)");
    $started=pa3c_start(pa3c_context(2));
    $q=$pdo->query('SELECT * FROM user_agents WHERE id=7');$agent=$q->fetch(PDO::FETCH_ASSOC);
    $notice=vp3_profile_call_assistance_offer_pa3c($pdo,1,$agent);
    if(!$notice)throw new RuntimeException('Assisted fixture readiness failed.');
    $offer=vp3_profile_call_owner_action_pa3c($pdo,pa3c_owner(),['operation'=>'offer','request_id'=>bin2hex(random_bytes(16)),
        'conversation_id'=>$started['conversation_id'],'mode'=>'audio','chat_conversation_id'=>11,'chat_agent_id'=>7,'assistance_notice'=>$notice['notice']]);
    $row=vp3_profile_call_row_pa3c($pdo,(int)$offer['call_id']);
    $accepted=vp3_profile_call_visitor_action_pa3c($pdo,$started['context'],pa3c_visitor_input($offer,'accept',
        ['assistance_notice'=>vp3_profile_call_assistance_incoming_notice_pa3c($row)]));
    vp3_profile_call_work_pa3c($pdo,(int)$offer['call_id'],'pa3c_provider');
    return ['call'=>(int)$offer['call_id'],'conversation'=>(int)$offer['conversation_id'],'public_id'=>$row['public_id']];
}
