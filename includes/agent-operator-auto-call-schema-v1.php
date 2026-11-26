<?php
declare(strict_types=1);

require_once __DIR__.'/agent-operator-admission-schema-v1.php';

// Explicit install/upgrade only. No contact or call is modified during install.
function vp3_operator_auto_call_schema_ready_v1(PDO $pdo): bool
{
    try {
        $pdo->query('SELECT owner_user_id,contact_id,contact_public_id,agent_id,number_id,line_e164,provider_sid_hash,destination_e164,enabled,timezone,window_start,window_end,max_calls_per_day,min_gap_minutes,max_duration_seconds,revision,created_epoch,updated_epoch FROM phone_operator_contact_policies_v1 WHERE 1=0');
        return vp3_operator_admission_schema_ready_v1($pdo);
    } catch (PDOException) {return false;}
}

function vp3_operator_auto_call_ensure_schema_v1(PDO $pdo): void
{
    if ($pdo->inTransaction()) throw new RuntimeException('Install Operator preferences outside a transaction.');
    $suffix=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite'?'':' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
    $pdo->exec("CREATE TABLE IF NOT EXISTS phone_operator_contact_policies_v1 (
        owner_user_id BIGINT NOT NULL,contact_id BIGINT NOT NULL,contact_public_id VARCHAR(64) NOT NULL,
        agent_id BIGINT NOT NULL,number_id BIGINT NOT NULL,line_e164 VARCHAR(20) NOT NULL,
        provider_sid_hash CHAR(64) NOT NULL,destination_e164 VARCHAR(20) NOT NULL,
        enabled SMALLINT NOT NULL DEFAULT 0,timezone VARCHAR(80) NOT NULL,
        window_start CHAR(5) NOT NULL,window_end CHAR(5) NOT NULL,max_calls_per_day INTEGER NOT NULL,
        min_gap_minutes INTEGER NOT NULL,max_duration_seconds INTEGER NOT NULL,
        revision BIGINT NOT NULL,created_epoch BIGINT NOT NULL,updated_epoch BIGINT NOT NULL,
        PRIMARY KEY(owner_user_id,contact_id,agent_id)
    )".$suffix);
    vp3_operator_admission_install_v1($pdo);
    if (!vp3_operator_auto_call_schema_ready_v1($pdo)) throw new RuntimeException('Operator contact preferences could not be installed.');
}

/** An explicit number reassignment invalidates every prior owner grant, even if it returns later. */
function vp3_operator_auto_call_revoke_line_v1(PDO $pdo,int $numberId): void
{
    require_once __DIR__.'/schema-metadata.php';
    if(!schema_metadata_table_exists($pdo,'phone_operator_contact_policies_v1'))return;
    $pdo->prepare('UPDATE phone_operator_contact_policies_v1 SET enabled=0,revision=revision+1,updated_epoch=? WHERE number_id=? AND enabled=1')
        ->execute([time(),$numberId]);
}
