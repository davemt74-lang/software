<?php
declare(strict_types=1);

require_once __DIR__.'/phone-transactions-v1.php';
require_once __DIR__.'/phone-agent-email-call-decisions-schema-v1.php';

/** Explicit upgrade only. Store grants and native references, never a second email. */
function vp3_phone_agent_email_calls_install_v1(PDO $pdo): void
{
    if(vp3_phone_transaction_active_v1($pdo))throw new LogicException('Install email calls outside a transaction.');
    $tail=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci':'';
    $reviewSequence=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'
        ?'BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY':'INTEGER PRIMARY KEY AUTOINCREMENT';
    $pdo->exec("CREATE TABLE IF NOT EXISTS phone_agent_email_call_policies_v1 (
        owner_user_id INT UNSIGNED NOT NULL PRIMARY KEY, revision BIGINT NOT NULL DEFAULT 1,
        urgent_enabled SMALLINT NOT NULL DEFAULT 0, drafts_enabled SMALLINT NOT NULL DEFAULT 0,
        device_id CHAR(32) NOT NULL DEFAULT '', grant_id CHAR(32) NOT NULL DEFAULT '',
        enrollment_revision BIGINT NOT NULL DEFAULT 0, credential_hash CHAR(64) NOT NULL DEFAULT '',
        owner_fingerprint CHAR(64) NOT NULL DEFAULT '', selected_agent_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
        conversation_id BIGINT UNSIGNED NOT NULL DEFAULT 0, number_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
        line_binding CHAR(64) NOT NULL DEFAULT '', phone_revision BIGINT NOT NULL DEFAULT 0,
        destination_hash CHAR(64) NOT NULL DEFAULT '', holder_profile_id CHAR(32) NOT NULL DEFAULT '',
        holder_phone_revision BIGINT NOT NULL DEFAULT 0, consent_epoch BIGINT NOT NULL DEFAULT 0,
        urgent_since_epoch BIGINT NOT NULL DEFAULT 0, drafts_since_epoch BIGINT NOT NULL DEFAULT 0,
        grace_seconds INTEGER NOT NULL DEFAULT 300, checked_epoch BIGINT NOT NULL DEFAULT 0,
        decision_code VARCHAR(48) NOT NULL DEFAULT '', decision_epoch BIGINT NOT NULL DEFAULT 0,
        created_epoch BIGINT NOT NULL, updated_epoch BIGINT NOT NULL,
        FOREIGN KEY(owner_user_id) REFERENCES users(id) ON DELETE CASCADE
    )".$tail);
    $pdo->exec("CREATE TABLE IF NOT EXISTS phone_agent_email_call_requests_v1 (
        id CHAR(32) NOT NULL PRIMARY KEY, owner_user_id INT UNSIGNED NOT NULL,
        work_key CHAR(64) NOT NULL, semantic_revision CHAR(64) NOT NULL,
        source_kind VARCHAR(24) NOT NULL, source_id VARCHAR(190) NOT NULL, source_revision CHAR(64) NOT NULL,
        item_ref CHAR(32) NOT NULL, selected_agent_id BIGINT UNSIGNED NOT NULL, conversation_id BIGINT UNSIGNED NOT NULL,
        policy_revision BIGINT NOT NULL, preferences_revision BIGINT NOT NULL, phone_revision BIGINT NOT NULL,
        device_id CHAR(32) NOT NULL, grant_id CHAR(32) NOT NULL, enrollment_revision BIGINT NOT NULL,
        credential_hash CHAR(64) NOT NULL, owner_fingerprint CHAR(64) NOT NULL,
        number_id BIGINT UNSIGNED NOT NULL, line_binding CHAR(64) NOT NULL, destination_hash CHAR(64) NOT NULL,
        holder_revision BIGINT NOT NULL, authority_hash CHAR(64) NOT NULL DEFAULT '',
        source_updated_epoch BIGINT NOT NULL, event_id BIGINT UNSIGNED NULL,
        start_epoch BIGINT NOT NULL DEFAULT 0, end_epoch BIGINT NOT NULL DEFAULT 0,
        state VARCHAR(24) NOT NULL, reason VARCHAR(48) NOT NULL, revision BIGINT NOT NULL DEFAULT 1,
        created_epoch BIGINT NOT NULL, updated_epoch BIGINT NOT NULL,
        UNIQUE(owner_user_id,work_key), UNIQUE(owner_user_id,event_id), UNIQUE(owner_user_id,id),
        FOREIGN KEY(owner_user_id) REFERENCES users(id) ON DELETE CASCADE
    )".$tail);
    $pdo->exec("CREATE TABLE IF NOT EXISTS phone_agent_email_call_reviews_v1 (
        sequence_id $reviewSequence, id CHAR(32) NOT NULL UNIQUE, owner_user_id INT UNSIGNED NOT NULL, request_id CHAR(32) NOT NULL,
        device_id CHAR(32) NOT NULL, grant_id CHAR(32) NOT NULL, enrollment_revision BIGINT NOT NULL,
        scope_binding CHAR(64) NOT NULL, session_binding CHAR(64) NOT NULL, authority_hash CHAR(64) NOT NULL,
        provider_sid VARCHAR(40) NOT NULL, call_id CHAR(32) NOT NULL, item_ref CHAR(32) NOT NULL,
        draft_id CHAR(32) NOT NULL, source_revision CHAR(64) NOT NULL, review_hash CHAR(64) NOT NULL,
        review_token_hash CHAR(64) NOT NULL, watch_replies SMALLINT NOT NULL DEFAULT 0,
        challenge_hash CHAR(64) NOT NULL, failed_challenges INTEGER NOT NULL DEFAULT 0,
        state VARCHAR(24) NOT NULL, revision BIGINT NOT NULL DEFAULT 1,
        created_epoch BIGINT NOT NULL, expires_epoch BIGINT NOT NULL, heartbeat_epoch BIGINT NOT NULL,
        requested_epoch BIGINT NOT NULL DEFAULT 0, claimed_epoch BIGINT NOT NULL DEFAULT 0,
        updated_epoch BIGINT NOT NULL,
        FOREIGN KEY(owner_user_id,request_id) REFERENCES phone_agent_email_call_requests_v1(owner_user_id,id) ON DELETE CASCADE
    )".$tail);
    vp3_phone_agent_email_call_decisions_install_v1($pdo);
}

/** Runtime checks never run DDL or make an uninstalled channel appear ready. */
function vp3_phone_agent_email_calls_ready_v1(PDO $pdo): bool
{
    try{
        $pdo->query('SELECT owner_user_id,revision,urgent_enabled,drafts_enabled,device_id,grant_id,enrollment_revision,
            credential_hash,owner_fingerprint,selected_agent_id,conversation_id,number_id,line_binding,phone_revision,
            destination_hash,holder_profile_id,holder_phone_revision,consent_epoch,urgent_since_epoch,drafts_since_epoch,
            grace_seconds,checked_epoch,decision_code,decision_epoch,created_epoch,updated_epoch
            FROM phone_agent_email_call_policies_v1 WHERE 1=0');
        $pdo->query('SELECT id,owner_user_id,work_key,semantic_revision,source_kind,source_id,source_revision,item_ref,
            selected_agent_id,conversation_id,policy_revision,preferences_revision,phone_revision,device_id,grant_id,
            enrollment_revision,credential_hash,owner_fingerprint,number_id,line_binding,destination_hash,
            holder_revision,authority_hash,source_updated_epoch,event_id,start_epoch,end_epoch,state,reason,revision,
            created_epoch,updated_epoch FROM phone_agent_email_call_requests_v1 WHERE 1=0');
        $pdo->query('SELECT sequence_id,id,owner_user_id,request_id,device_id,grant_id,enrollment_revision,scope_binding,session_binding,
            authority_hash,provider_sid,call_id,item_ref,draft_id,source_revision,review_hash,review_token_hash,watch_replies,
            challenge_hash,failed_challenges,state,revision,created_epoch,expires_epoch,heartbeat_epoch,requested_epoch,
            claimed_epoch,updated_epoch FROM phone_agent_email_call_reviews_v1 WHERE 1=0');
        return vp3_phone_agent_email_call_decisions_ready_v1($pdo);
    }catch(PDOException){return false;}
}

/** Called inside the existing owner/line mutation; a moved-back line needs new consent. */
function vp3_phone_agent_email_calls_revoke_line_v1(PDO $pdo,int $numberId): void
{
    if(!vp3_phone_agent_email_calls_ready_v1($pdo))return;
    $pdo->prepare("UPDATE phone_agent_email_call_policies_v1 SET urgent_enabled=0,drafts_enabled=0,
        urgent_since_epoch=0,drafts_since_epoch=0,consent_epoch=0,revision=revision+1,decision_code='line_changed',
        decision_epoch=?,updated_epoch=? WHERE number_id=? AND (urgent_enabled=1 OR drafts_enabled=1)")
        ->execute([time(),time(),$numberId]);
}
