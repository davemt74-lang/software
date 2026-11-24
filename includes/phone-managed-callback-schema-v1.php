<?php
declare(strict_types=1);

/** Managed-callback storage and withdrawal cleanup; no carrier dependencies. */
require_once __DIR__.'/phone-managed-recording-schema-v1.php';
require_once __DIR__.'/phone-native-dialthrough-schema-v1.php';
function vp3_managed_callback_install_v1(PDO $pdo):void
{
    if($pdo->inTransaction())throw new LogicException('Install managed callbacks outside transactions.');
    $suffix=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci':'';
    $pdo->exec("CREATE TABLE IF NOT EXISTS phone_managed_callback_requests_v1(
        public_id CHAR(32) PRIMARY KEY,owner_user_id BIGINT NOT NULL,device_id CHAR(32) NOT NULL,
        generation BIGINT NOT NULL,profile_id CHAR(32) NOT NULL,profile_revision BIGINT NOT NULL,
        phone_revision BIGINT NOT NULL,number_id BIGINT NOT NULL,line_agent_id BIGINT NOT NULL,
        chat_agent_id BIGINT NOT NULL,conversation_id BIGINT NOT NULL,line_e164 VARCHAR(20) NOT NULL,
        holder_e164 VARCHAR(20) NOT NULL,destination_e164 VARCHAR(20) NOT NULL,
        state VARCHAR(24) NOT NULL,created_epoch BIGINT NOT NULL,expires_epoch BIGINT NOT NULL,
        approved_epoch BIGINT NOT NULL DEFAULT 0,updated_epoch BIGINT NOT NULL
    )".$suffix);
    if(!vp3_managed_callback_ready_v1($pdo))throw new RuntimeException('Managed callback upgrade incomplete.');
}
function vp3_managed_callback_ready_v1(PDO $pdo):bool
{
    try{$pdo->query('SELECT public_id,owner_user_id,device_id,generation,profile_id,profile_revision,phone_revision,number_id,line_agent_id,chat_agent_id,conversation_id,line_e164,holder_e164,destination_e164,state,created_epoch,expires_epoch,approved_epoch,updated_epoch FROM phone_managed_callback_requests_v1 WHERE 1=0');return true;}catch(PDOException){return false;}
}

/** Call only inside the current pairing/profile mutation transaction. */
function vp3_managed_callback_revoke_v1(PDO $pdo,int $owner,string $device,int $now):void
{
    if(!vp3_managed_callback_ready_v1($pdo))return;
    vp3_managed_recording_revoke_device_v1($pdo,$owner,$device,$now);
    vp3_native_dialthrough_revoke_device_v1($pdo,$owner,$device,$now);
    $pdo->prepare("UPDATE phone_managed_callback_requests_v1 SET state='canceled',holder_e164='',destination_e164='',updated_epoch=? WHERE owner_user_id=? AND device_id=? AND state IN ('pending','approved')")->execute([$now,$owner,$device]);
    // Already claimed callbacks need their immutable numbers until signed terminal receipts arrive.
    // Revocation prevents a subsequent answer/confirm from authorizing the destination leg.
    $pdo->prepare("UPDATE phone_managed_callback_requests_v1 SET state='revoked',updated_epoch=? WHERE owner_user_id=? AND device_id=? AND state='dispatch_claimed'")->execute([$now,$owner,$device]);
}
