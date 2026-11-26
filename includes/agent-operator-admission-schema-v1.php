<?php
declare(strict_types=1);
require_once __DIR__.'/agent-operator-scheduled-assignment-v1.php';

/** Owner attestation is NOT independent recipient verification or a dial grant. */
function vp3_operator_admission_schema_ready_v1(PDO $pdo): bool
{
    try {
        $pdo->query('SELECT owner_user_id,contact_id,agent_id,revision,enabled,binding_hash,purpose,attested_epoch,expires_epoch FROM phone_operator_consent_reviews_v1 WHERE 1=0');
        $pdo->query('SELECT owner_user_id,destination_hash,withdrawn_epoch FROM phone_operator_recipient_optouts_v1 WHERE 1=0');
        return vp3_operator_schedule_assignment_ready_v1($pdo);
    }catch(PDOException){return false;}
}
function vp3_operator_admission_install_v1(PDO $pdo): void
{
    if($pdo->inTransaction())throw new RuntimeException('Install Operator readiness storage outside a transaction.');
    $suffix=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite'?'':' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
    $pdo->exec("CREATE TABLE IF NOT EXISTS phone_operator_consent_reviews_v1 (
        owner_user_id BIGINT NOT NULL,contact_id BIGINT NOT NULL,agent_id BIGINT NOT NULL,revision BIGINT NOT NULL,
        enabled SMALLINT NOT NULL DEFAULT 0,binding_hash CHAR(64) NOT NULL,purpose VARCHAR(32) NOT NULL,
        attested_epoch BIGINT NOT NULL,expires_epoch BIGINT NOT NULL,
        PRIMARY KEY(owner_user_id,contact_id,agent_id)
    )".$suffix);
    $pdo->exec("CREATE TABLE IF NOT EXISTS phone_operator_recipient_optouts_v1 (
        owner_user_id BIGINT NOT NULL,destination_hash CHAR(64) NOT NULL,withdrawn_epoch BIGINT NOT NULL,
        PRIMARY KEY(owner_user_id,destination_hash)
    )".$suffix);
    vp3_operator_schedule_assignment_install_v1($pdo,$suffix);
    if(!vp3_operator_admission_schema_ready_v1($pdo))throw new RuntimeException('Operator readiness storage could not be installed.');
}
