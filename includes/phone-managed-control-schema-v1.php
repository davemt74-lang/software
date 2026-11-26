<?php
declare(strict_types=1);

/** Explicit optional control migration. No carrier request occurs during upgrade. */
function vp3_managed_control_install_v1(PDO $pdo):void
{
    if($pdo->inTransaction())throw new LogicException('Install managed controls outside transactions.');
    $suffix=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci':'';
    $pdo->exec("CREATE TABLE IF NOT EXISTS phone_managed_callback_controls_v1(
        call_public_id CHAR(32) PRIMARY KEY,owner_user_id BIGINT NOT NULL,
        last_read_epoch BIGINT NOT NULL DEFAULT 0,read_state VARCHAR(32) NOT NULL DEFAULT 'none',
        end_state VARCHAR(32) NOT NULL DEFAULT 'none',end_epoch BIGINT NOT NULL DEFAULT 0,
        updated_epoch BIGINT NOT NULL
    )".$suffix);
}
function vp3_managed_control_ready_v1(PDO $pdo):bool
{
    try{$pdo->query('SELECT call_public_id,owner_user_id,last_read_epoch,read_state,end_state,end_epoch,updated_epoch FROM phone_managed_callback_controls_v1 WHERE 1=0');return true;}
    catch(PDOException){return false;}
}
