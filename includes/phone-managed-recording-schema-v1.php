<?php
declare(strict_types=1);

/** Per-request consent and one recording admission. No carrier I/O during migration. */
function vp3_managed_recording_install_v1(PDO $pdo):void
{
    if($pdo->inTransaction())throw new LogicException('Install managed recording outside transactions.');
    $suffix=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci':'';
    $pdo->exec("CREATE TABLE IF NOT EXISTS phone_managed_recording_consent_v1(
        request_id CHAR(32) PRIMARY KEY,owner_user_id BIGINT NOT NULL,
        policy_json TEXT NOT NULL,policy_revision BIGINT NOT NULL,
        consent_revision BIGINT NOT NULL DEFAULT 1,owner_epoch BIGINT NOT NULL,
        holder_epoch BIGINT NOT NULL DEFAULT 0,parent_epoch BIGINT NOT NULL DEFAULT 0,
        child_epoch BIGINT NOT NULL DEFAULT 0,child_sid VARCHAR(40) NULL,
        start_state VARCHAR(24) NOT NULL DEFAULT 'requested',start_epoch BIGINT NOT NULL DEFAULT 0,
        recording_sid VARCHAR(40) NULL UNIQUE,revoked_epoch BIGINT NOT NULL DEFAULT 0,
        updated_epoch BIGINT NOT NULL
    )".$suffix);
    foreach(['stop_state'=>"VARCHAR(24) NOT NULL DEFAULT 'none'",'stop_epoch'=>'BIGINT NOT NULL DEFAULT 0'] as $field=>$definition){
        try{$pdo->query('SELECT '.$field.' FROM phone_managed_recording_consent_v1 WHERE 1=0');}catch(PDOException){$pdo->exec('ALTER TABLE phone_managed_recording_consent_v1 ADD COLUMN '.$field.' '.$definition);}
    }
}
function vp3_managed_recording_ready_v1(PDO $pdo):bool
{
    try{$pdo->query('SELECT request_id,owner_user_id,policy_json,policy_revision,consent_revision,owner_epoch,holder_epoch,parent_epoch,child_epoch,child_sid,start_state,start_epoch,recording_sid,revoked_epoch,updated_epoch,stop_state,stop_epoch FROM phone_managed_recording_consent_v1 WHERE 1=0');return true;}
    catch(PDOException){return false;}
}
function vp3_managed_recording_revoke_v1(PDO $pdo,string $request,int $now):void
{
    if(!vp3_managed_recording_ready_v1($pdo))return;
    $pdo->prepare('UPDATE phone_managed_recording_consent_v1 SET revoked_epoch=?,updated_epoch=? WHERE request_id=? AND revoked_epoch=0')->execute([$now,$now,$request]);
}
function vp3_managed_recording_revoke_device_v1(PDO $pdo,int $owner,string $device,int $now):void
{
    if(!vp3_managed_recording_ready_v1($pdo))return;
    $pdo->prepare("UPDATE phone_managed_recording_consent_v1 SET revoked_epoch=?,updated_epoch=? WHERE owner_user_id=? AND revoked_epoch=0 AND request_id IN (SELECT public_id FROM phone_managed_callback_requests_v1 WHERE owner_user_id=? AND device_id=? AND state IN ('pending','approved','dispatch_claimed','revoked'))")->execute([$now,$now,$owner,$owner,$device]);
}
