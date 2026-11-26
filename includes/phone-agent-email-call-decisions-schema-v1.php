<?php
declare(strict_types=1);

require_once __DIR__.'/phone-transactions-v1.php';

/** Installer-only metadata. No Gmail, Agent, provider or telephone runtime is loaded here. */
function vp3_phone_agent_email_call_decisions_install_v1(PDO $pdo): void
{
    if (vp3_phone_transaction_active_v1($pdo)) throw new LogicException('Install email-call decisions outside a transaction.');
    $tail=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'
        ?' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci':'';
    $pdo->exec("CREATE TABLE IF NOT EXISTS phone_agent_email_call_decisions_v1 (
        owner_user_id INT UNSIGNED NOT NULL, work_key CHAR(64) NOT NULL, semantic_revision CHAR(64) NOT NULL,
        source_kind VARCHAR(24) NOT NULL, source_id VARCHAR(190) NOT NULL, source_revision CHAR(64) NOT NULL,
        item_ref CHAR(32) NOT NULL, context_revision CHAR(64) NOT NULL, decision_context_revision CHAR(64) NULL,
        checked_epoch BIGINT NOT NULL DEFAULT 0, evidence_hash CHAR(64) NULL,
        decision_state VARCHAR(16) NOT NULL DEFAULT 'pending', decision VARCHAR(16) NULL, reason VARCHAR(48) NULL,
        not_before_epoch BIGINT NOT NULL DEFAULT 0, attempt_key CHAR(32) NULL, lease_epoch BIGINT NOT NULL DEFAULT 0,
        dispatched_epoch BIGINT NOT NULL DEFAULT 0, finished_epoch BIGINT NOT NULL DEFAULT 0,
        created_epoch BIGINT NOT NULL, updated_epoch BIGINT NOT NULL,
        PRIMARY KEY(owner_user_id,work_key,semantic_revision),
        FOREIGN KEY(owner_user_id) REFERENCES users(id) ON DELETE CASCADE
    )".$tail);
}

function vp3_phone_agent_email_call_decisions_ready_v1(PDO $pdo): bool
{
    try {
        $pdo->query('SELECT owner_user_id,work_key,semantic_revision,source_kind,source_id,source_revision,item_ref,
            context_revision,decision_context_revision,checked_epoch,evidence_hash,decision_state,decision,reason,not_before_epoch,
            attempt_key,lease_epoch,dispatched_epoch,finished_epoch,created_epoch,updated_epoch
            FROM phone_agent_email_call_decisions_v1 WHERE 1=0');
        return true;
    } catch (PDOException) { return false; }
}
