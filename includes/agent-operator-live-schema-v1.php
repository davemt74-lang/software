<?php
declare(strict_types=1);
require_once __DIR__.'/agent-operator-live-assist-v1.php';
require_once __DIR__.'/agent-operator-voice-turns-v1.php';
require_once __DIR__.'/agent-operator-participation-v1.php';

/** Optional metadata-only live caption session storage; install explicitly. */
function vp3_operator_live_schema_ready_v1(PDO $pdo): bool
{
    try {
        $pdo->query('SELECT call_id,owner_user_id,agent_id,chat_agent_id,conversation_id,number_id,policy_revision,token_hash,state,consent_epoch,expires_epoch,reserved_seconds,next_seq,pending_seq,last_dispatch_epoch FROM phone_operator_live_sessions_v1 WHERE 1=0');
        return vp3_operator_live_assist_ready_v1($pdo)&&vp3_operator_voice_ready_v1($pdo);
    } catch(PDOException) {return false;}
}
function vp3_operator_live_controls_ready_v1(PDO $pdo):bool
{
    try{$pdo->query('SELECT audio_limit_seconds FROM phone_operator_live_sessions_v1 WHERE 1=0');return vp3_operator_participation_ready_v1($pdo);}
    catch(PDOException){return false;}
}
function vp3_operator_live_ensure_schema_v1(PDO $pdo): void
{
    if($pdo->inTransaction())throw new RuntimeException('Install live caption preferences outside a transaction.');
    $suffix=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite'?'':' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
    $pdo->exec("CREATE TABLE IF NOT EXISTS phone_operator_live_sessions_v1(
        call_id BIGINT PRIMARY KEY,owner_user_id BIGINT NOT NULL,agent_id BIGINT NOT NULL,
        chat_agent_id BIGINT NOT NULL,conversation_id BIGINT NOT NULL,number_id BIGINT NOT NULL,
        policy_revision BIGINT NOT NULL,token_hash CHAR(64) NOT NULL,state VARCHAR(20) NOT NULL,
        consent_epoch BIGINT NOT NULL,expires_epoch BIGINT NOT NULL,reserved_seconds INTEGER NOT NULL DEFAULT 0,
        next_seq INTEGER NOT NULL DEFAULT 1,pending_seq INTEGER NOT NULL DEFAULT 0,last_dispatch_epoch BIGINT NOT NULL DEFAULT 0
    )".$suffix);
    vp3_operator_metadata_column_v1($pdo,'phone_operator_live_sessions_v1','audio_limit_seconds','INTEGER NOT NULL DEFAULT 120');
    vp3_operator_live_assist_install_v1($pdo);
    vp3_operator_voice_install_v1($pdo);
    vp3_operator_participation_install_v1($pdo);
    if(!vp3_operator_live_schema_ready_v1($pdo))throw new RuntimeException('Live caption metadata installation failed.');
    if($pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite')
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_phone_operator_live_owner_v1 ON phone_operator_live_sessions_v1(owner_user_id,consent_epoch)');
    else{
        $q=$pdo->query("SHOW INDEX FROM phone_operator_live_sessions_v1 WHERE Key_name='idx_phone_operator_live_owner_v1'");
        $exists=(bool)$q->fetch();$q->closeCursor();
        if(!$exists)$pdo->exec('CREATE INDEX idx_phone_operator_live_owner_v1 ON phone_operator_live_sessions_v1(owner_user_id,consent_epoch)');
    }
}

/** Disabling/reassigning a line closes live processing without affecting calls. */
function vp3_operator_live_revoke_line_v1(PDO $pdo,int $numberId): void
{
    if(!vp3_operator_live_schema_ready_v1($pdo))return;
    if(vp3_operator_participation_ready_v1($pdo))$pdo->prepare("UPDATE phone_operator_participation_v1 SET state='closed' WHERE call_id IN (SELECT call_id FROM phone_operator_live_sessions_v1 WHERE number_id=?)")->execute([$numberId]);
    $pdo->prepare("UPDATE phone_operator_voice_turns_v1 SET state='closed' WHERE call_id IN (SELECT call_id FROM phone_operator_live_sessions_v1 WHERE number_id=?) AND state<>'closed'")->execute([$numberId]);
    $pdo->prepare("UPDATE phone_operator_live_sessions_v1 SET state='closed',pending_seq=0 WHERE number_id=? AND state='active'")->execute([$numberId]);
}
