<?php
declare(strict_types=1);

require_once __DIR__.'/phone-transactions-v1.php';

/** Explicit installer only. Native source content is never copied into this index. */
function vp3_phone_agent_workspace_install_v1(PDO $pdo): void
{
    if (vp3_phone_transaction_active_v1($pdo)) throw new LogicException('Install the phone workspace outside a transaction.');
    $tail=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'
        ?' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci':'';
    $sequence=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'
        ?'BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY':'INTEGER PRIMARY KEY AUTOINCREMENT';
    $feedIndex=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'
        ?', INDEX idx_phone_agent_feed_v1(owner_user_id,agent_id,sequence_id)':'';
    $pdo->exec("CREATE TABLE IF NOT EXISTS vp3_phone_agent_enrollments_v1 (
        owner_user_id INT UNSIGNED NOT NULL, device_id CHAR(32) NOT NULL,
        grant_id CHAR(32) NOT NULL, credential_hash CHAR(64) NOT NULL, owner_fingerprint CHAR(64) NOT NULL,
        enabled SMALLINT NOT NULL DEFAULT 0, revision BIGINT NOT NULL DEFAULT 1,
        selected_agent_id BIGINT UNSIGNED NOT NULL DEFAULT 0, conversation_id BIGINT UNSIGNED NOT NULL,
        notifications_enabled SMALLINT NOT NULL DEFAULT 0, notifications_since_epoch BIGINT NOT NULL DEFAULT 0,
        created_epoch BIGINT NOT NULL, updated_epoch BIGINT NOT NULL,
        PRIMARY KEY(owner_user_id,device_id), UNIQUE(owner_user_id,device_id,grant_id),
        FOREIGN KEY(owner_user_id,device_id) REFERENCES vp3_phone_devices_v1(owner_user_id,id) ON DELETE CASCADE
    )".$tail);
    $pdo->exec("CREATE TABLE IF NOT EXISTS vp3_phone_agent_device_grants_v1(
        owner_user_id INT UNSIGNED NOT NULL,device_id CHAR(32) NOT NULL,grant_id CHAR(32) NOT NULL,approved_epoch BIGINT NOT NULL,
        PRIMARY KEY(owner_user_id,device_id),
        FOREIGN KEY(owner_user_id,device_id) REFERENCES vp3_phone_devices_v1(owner_user_id,id) ON DELETE CASCADE
    )".$tail);
    $pdo->exec("CREATE TABLE IF NOT EXISTS vp3_phone_agent_items_v1 (
        sequence_id $sequence, item_ref CHAR(32) NOT NULL UNIQUE,
        owner_user_id INT UNSIGNED NOT NULL, agent_id BIGINT UNSIGNED NOT NULL, conversation_id BIGINT UNSIGNED NOT NULL,
        source_kind VARCHAR(24) NOT NULL, source_id VARCHAR(190) NOT NULL, source_key CHAR(64) NOT NULL,
        source_revision CHAR(64) NOT NULL, revision BIGINT NOT NULL DEFAULT 1,
        created_epoch BIGINT NOT NULL, updated_epoch BIGINT NOT NULL,
        UNIQUE(owner_user_id,agent_id,source_key), UNIQUE(owner_user_id,item_ref),
        FOREIGN KEY(owner_user_id) REFERENCES users(id) ON DELETE CASCADE
        $feedIndex
    )".$tail);
    if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite') $pdo->exec('CREATE INDEX IF NOT EXISTS idx_phone_agent_feed_v1 ON vp3_phone_agent_items_v1(owner_user_id,agent_id,sequence_id)');
    $pdo->exec("CREATE TABLE IF NOT EXISTS vp3_phone_agent_receipts_v1 (
        owner_user_id INT UNSIGNED NOT NULL, device_id CHAR(32) NOT NULL, grant_id CHAR(32) NOT NULL,
        item_ref CHAR(32) NOT NULL, source_revision CHAR(64) NOT NULL, revision BIGINT NOT NULL DEFAULT 0,
        seen_epoch BIGINT NULL, read_epoch BIGINT NULL, acknowledged_epoch BIGINT NULL, snoozed_until BIGINT NULL,
        updated_epoch BIGINT NOT NULL,
        PRIMARY KEY(owner_user_id,device_id,grant_id,item_ref),
        FOREIGN KEY(owner_user_id,device_id) REFERENCES vp3_phone_devices_v1(owner_user_id,id) ON DELETE CASCADE,
        FOREIGN KEY(owner_user_id,item_ref) REFERENCES vp3_phone_agent_items_v1(owner_user_id,item_ref) ON DELETE CASCADE
    )".$tail);
    $pdo->exec("CREATE TABLE IF NOT EXISTS vp3_phone_canvas_deliveries_v1(
        owner_user_id INT UNSIGNED NOT NULL,device_id CHAR(32) NOT NULL,request_id CHAR(36) NOT NULL,
        signature CHAR(64) NOT NULL,grant_id CHAR(32) NOT NULL,item_ref CHAR(32) NOT NULL,created_epoch BIGINT NOT NULL,
        PRIMARY KEY(owner_user_id,request_id),
        FOREIGN KEY(owner_user_id,device_id) REFERENCES vp3_phone_devices_v1(owner_user_id,id) ON DELETE CASCADE,
        FOREIGN KEY(owner_user_id,item_ref) REFERENCES vp3_phone_agent_items_v1(owner_user_id,item_ref) ON DELETE CASCADE
    )".$tail);
}

function vp3_phone_agent_workspace_ready_v1(PDO $pdo): bool
{
    try {
        $pdo->query('SELECT owner_user_id,device_id,grant_id,credential_hash,owner_fingerprint,enabled,revision,
            selected_agent_id,conversation_id,notifications_enabled,notifications_since_epoch,created_epoch,updated_epoch
            FROM vp3_phone_agent_enrollments_v1 WHERE 1=0');
        $pdo->query('SELECT sequence_id,item_ref,owner_user_id,agent_id,conversation_id,source_kind,source_id,source_key,
            source_revision,revision,created_epoch,updated_epoch FROM vp3_phone_agent_items_v1 WHERE 1=0');
        $pdo->query('SELECT owner_user_id,device_id,grant_id,item_ref,source_revision,revision,seen_epoch,read_epoch,
            acknowledged_epoch,snoozed_until,updated_epoch FROM vp3_phone_agent_receipts_v1 WHERE 1=0');
        return true;
    } catch (PDOException) { return false; }
}

/** Rotation metadata only; source content and delivery outcomes remain in their canonical stores. */
function vp3_phone_agent_workspace_worker_install_v1(PDO $pdo): void
{
    if(vp3_phone_transaction_active_v1($pdo))throw new LogicException('Install Phone Agent ingestion outside a transaction.');
    $tail=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci':'';
    $pdo->exec("CREATE TABLE IF NOT EXISTS vp3_phone_agent_ingestion_v1 (
        owner_user_id INT UNSIGNED NOT NULL,device_id CHAR(32) NOT NULL,
        checked_epoch BIGINT NOT NULL DEFAULT 0,
        PRIMARY KEY(owner_user_id,device_id),
        FOREIGN KEY(owner_user_id,device_id) REFERENCES vp3_phone_devices_v1(owner_user_id,id) ON DELETE CASCADE
    )".$tail);
}

function vp3_phone_agent_workspace_worker_ready_v1(PDO $pdo): bool
{
    if(!vp3_phone_agent_workspace_ready_v1($pdo))return false;
    try{$pdo->query('SELECT owner_user_id,device_id,checked_epoch FROM vp3_phone_agent_ingestion_v1 WHERE 1=0');return true;}
    catch(PDOException){return false;}
}

function vp3_phone_agent_device_grants_ready_v1(PDO $pdo):bool
{
    try{$pdo->query('SELECT owner_user_id,device_id,grant_id,approved_epoch FROM vp3_phone_agent_device_grants_v1 WHERE 1=0');$pdo->query('SELECT owner_user_id,device_id,request_id,signature,grant_id,item_ref,created_epoch FROM vp3_phone_canvas_deliveries_v1 WHERE 1=0');return true;}
    catch(PDOException){return false;}
}
