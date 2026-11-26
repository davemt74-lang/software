<?php
declare(strict_types=1);

/** Schema only. Installing this metadata must not load an Agent or provider runtime. */
function agent_email_preferences_install_v1(PDO $pdo): void
{
    if ($pdo->inTransaction()) throw new LogicException('Install email preferences outside a transaction.');
    $mysql=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql';
    $tail=$mysql?' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci':'';
    $pdo->exec("CREATE TABLE IF NOT EXISTS agent_email_preference_captures_v1 (
        draft_id CHAR(32) NOT NULL PRIMARY KEY,
        capture_id CHAR(32) NOT NULL,
        owner_user_id INT UNSIGNED NOT NULL,
        agent_id BIGINT NOT NULL,
        conversation_id BIGINT UNSIGNED NOT NULL,
        generation_key CHAR(64) NOT NULL,
        binding_hash CHAR(64) NOT NULL,
        generated_hash CHAR(64) NOT NULL,
        generated_enc TEXT NOT NULL,
        generated_soul_version BIGINT NOT NULL,
        generated_soul_revision CHAR(64) NOT NULL,
        current_revision CHAR(64) NOT NULL,
        edited_hash CHAR(64) NULL,
        generated_epoch BIGINT NOT NULL,
        edited_epoch BIGINT NOT NULL DEFAULT 0,
        revision BIGINT NOT NULL DEFAULT 1,
        UNIQUE(capture_id),
        FOREIGN KEY(owner_user_id) REFERENCES users(id) ON DELETE CASCADE
    )".$tail);
    $pdo->exec("CREATE TABLE IF NOT EXISTS agent_email_preference_proposals_v1 (
        id CHAR(32) NOT NULL PRIMARY KEY,
        owner_user_id INT UNSIGNED NOT NULL,
        agent_id BIGINT NOT NULL,
        conversation_id BIGINT UNSIGNED NOT NULL,
        draft_id CHAR(32) NOT NULL,
        capture_id CHAR(32) NOT NULL,
        edited_hash CHAR(64) NOT NULL,
        binding_hash CHAR(64) NOT NULL,
        context_hash CHAR(64) NOT NULL,
        source_revision CHAR(64) NOT NULL,
        soul_version BIGINT NOT NULL,
        soul_revision CHAR(64) NOT NULL,
        state VARCHAR(24) NOT NULL,
        revision BIGINT NOT NULL DEFAULT 1,
        category VARCHAR(24) NULL,
        preference_enc TEXT NULL,
        reason VARCHAR(48) NULL,
        attempt_key CHAR(32) NOT NULL,
        lease_epoch BIGINT NOT NULL DEFAULT 0,
        dispatched_epoch BIGINT NOT NULL DEFAULT 0,
        finished_epoch BIGINT NOT NULL DEFAULT 0,
        publish_base_revision CHAR(64) NULL,
        publish_version BIGINT NULL,
        publish_content_hash CHAR(64) NULL,
        publish_preference_enc TEXT NULL,
        accepted_version BIGINT NULL,
        accepted_revision CHAR(64) NULL,
        created_epoch BIGINT NOT NULL,
        updated_epoch BIGINT NOT NULL,
        UNIQUE(owner_user_id,draft_id,capture_id,edited_hash),
        FOREIGN KEY(owner_user_id) REFERENCES users(id) ON DELETE CASCADE
    )".$tail);
}

function agent_email_preferences_ready_v1(PDO $pdo): bool
{
    try {
        $pdo->query('SELECT draft_id,capture_id,owner_user_id,agent_id,conversation_id,generation_key,binding_hash,
            generated_hash,generated_enc,generated_soul_version,generated_soul_revision,current_revision,edited_hash,
            generated_epoch,edited_epoch,revision FROM agent_email_preference_captures_v1 WHERE 1=0');
        $pdo->query('SELECT id,owner_user_id,agent_id,conversation_id,draft_id,capture_id,edited_hash,binding_hash,
            context_hash,source_revision,soul_version,soul_revision,state,revision,category,preference_enc,reason,
            attempt_key,lease_epoch,dispatched_epoch,finished_epoch,publish_base_revision,publish_version,
            publish_content_hash,publish_preference_enc,accepted_version,accepted_revision,created_epoch,updated_epoch
            FROM agent_email_preference_proposals_v1 WHERE 1=0');
        return true;
    } catch (PDOException) { return false; }
}
