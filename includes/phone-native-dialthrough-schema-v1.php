<?php
declare(strict_types=1);

/** Private native dial-through authority; no plaintext keypad codes at rest. */
function vp3_native_dialthrough_install_v1(PDO $pdo):void
{
    if($pdo->inTransaction())throw new LogicException('Install native dial-through outside transactions.');
    $suffix=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci':'';
    $pdo->exec("CREATE TABLE IF NOT EXISTS phone_native_dialthrough_v1(
        request_id CHAR(32) PRIMARY KEY,owner_user_id BIGINT NOT NULL,
        pin_hash CHAR(64) NULL,issued_epoch BIGINT NOT NULL DEFAULT 0,
        challenge_sid VARCHAR(40) NULL UNIQUE,challenge_epoch BIGINT NOT NULL DEFAULT 0,
        call_public_id CHAR(32) NULL UNIQUE,consumed_epoch BIGINT NOT NULL DEFAULT 0,
        state VARCHAR(24) NOT NULL DEFAULT 'review',updated_epoch BIGINT NOT NULL
    )".$suffix);
}
function vp3_native_dialthrough_ready_v1(PDO $pdo):bool
{
    try{$pdo->query('SELECT request_id,owner_user_id,pin_hash,issued_epoch,challenge_sid,challenge_epoch,call_public_id,consumed_epoch,state,updated_epoch FROM phone_native_dialthrough_v1 WHERE 1=0');return true;}
    catch(PDOException){return false;}
}
function vp3_native_dialthrough_revoke_v1(PDO $pdo,string $request,int $now):void
{
    if(!vp3_native_dialthrough_ready_v1($pdo))return;
    $pdo->prepare("UPDATE phone_native_dialthrough_v1 SET state='revoked',pin_hash=NULL,updated_epoch=? WHERE request_id=? AND state IN ('review','issued','challenge')")->execute([$now,$request]);
}
function vp3_native_dialthrough_revoke_device_v1(PDO $pdo,int $owner,string $device,int $now):void
{
    if(!vp3_native_dialthrough_ready_v1($pdo))return;
    $pdo->prepare("UPDATE phone_native_dialthrough_v1 SET state='revoked',pin_hash=NULL,updated_epoch=? WHERE owner_user_id=? AND state IN ('review','issued','challenge') AND request_id IN (SELECT public_id FROM phone_managed_callback_requests_v1 WHERE owner_user_id=? AND device_id=?)")->execute([$now,$owner,$owner,$device]);
}
