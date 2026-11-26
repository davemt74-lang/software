<?php
declare(strict_types=1);

/** Explicit transport migration; no provider traffic. */
function vp3_managed_transport_install_v1(PDO $pdo):void
{
    if($pdo->inTransaction())throw new LogicException('Install managed transport outside transactions.');
    $suffix=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci':'';
    $pdo->exec("CREATE TABLE IF NOT EXISTS phone_managed_callback_dispatch_v1(
        request_id CHAR(32) PRIMARY KEY,call_public_id CHAR(32) NOT NULL UNIQUE,
        owner_user_id BIGINT NOT NULL,callback_nonce_hash CHAR(64) NOT NULL UNIQUE,
        state VARCHAR(32) NOT NULL,parent_state VARCHAR(24) NOT NULL DEFAULT '',
        created_epoch BIGINT NOT NULL,confirmation_epoch BIGINT NOT NULL DEFAULT 0,
        updated_epoch BIGINT NOT NULL
    )".$suffix);
    try{$pdo->query('SELECT pilot_required FROM phone_managed_callback_dispatch_v1 WHERE 1=0');}catch(PDOException){$pdo->exec('ALTER TABLE phone_managed_callback_dispatch_v1 ADD COLUMN pilot_required SMALLINT NOT NULL DEFAULT 0');}
    if(!vp3_managed_transport_ready_v1($pdo))throw new RuntimeException('Managed transport upgrade incomplete.');
}
function vp3_managed_transport_ready_v1(PDO $pdo):bool
{
    try{$pdo->query('SELECT request_id,call_public_id,owner_user_id,callback_nonce_hash,state,parent_state,created_epoch,confirmation_epoch,updated_epoch,pilot_required FROM phone_managed_callback_dispatch_v1 WHERE 1=0');return true;}catch(PDOException){return false;}
}
