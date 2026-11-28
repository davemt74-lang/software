<?php
declare(strict_types=1);

function gmail_email_capture_install_v1(PDO $pdo): void
{
    if ($pdo->inTransaction()) throw new LogicException('Install email capture outside a transaction.');
    $tail=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci':'';
    $pdo->exec("CREATE TABLE IF NOT EXISTS agent_gmail_captures_v1 (
        id CHAR(32) NOT NULL PRIMARY KEY, owner_user_id INT UNSIGNED NOT NULL,
        agent_id BIGINT NOT NULL, conversation_id BIGINT UNSIGNED NOT NULL,
        request_key CHAR(32) NOT NULL, source_hash CHAR(64) NOT NULL, input_hash CHAR(64) NOT NULL,
        state VARCHAR(20) NOT NULL, capture_enc MEDIUMTEXT NOT NULL,
        answer_enc MEDIUMTEXT NULL, result_enc MEDIUMTEXT NULL, reason VARCHAR(40) NULL,
        lease_epoch BIGINT NOT NULL, created_epoch BIGINT NOT NULL, updated_epoch BIGINT NOT NULL,
        UNIQUE(owner_user_id,agent_id,conversation_id,request_key),
        FOREIGN KEY(owner_user_id) REFERENCES users(id) ON DELETE CASCADE
    )".$tail);
    $pdo->exec("CREATE TABLE IF NOT EXISTS agent_gmail_capture_actions_v1 (
        id CHAR(64) NOT NULL PRIMARY KEY, owner_user_id INT UNSIGNED NOT NULL,
        agent_id BIGINT NOT NULL, conversation_id BIGINT UNSIGNED NOT NULL,
        source_hash CHAR(64) NOT NULL, effect_key CHAR(64) NOT NULL,
        capture_id CHAR(32) NOT NULL DEFAULT '', kind VARCHAR(12) NOT NULL,
        record_id BIGINT UNSIGNED NOT NULL, created_epoch BIGINT NOT NULL,
        FOREIGN KEY(owner_user_id) REFERENCES users(id) ON DELETE CASCADE
    )".$tail);
    foreach (['agent_gmail_captures_v1'=>['gmail_capture_source','owner_user_id,agent_id,conversation_id,source_hash,created_epoch,id'],
        'agent_gmail_capture_actions_v1'=>['gmail_capture_action_source','owner_user_id,agent_id,conversation_id,source_hash,capture_id']] as $table=>[$index,$columns]) {
        if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite') $pdo->exec("CREATE INDEX IF NOT EXISTS $index ON $table ($columns)");
        else { $q=$pdo->query("SHOW INDEX FROM $table WHERE Key_name='$index'");if (!$q->fetch()) $pdo->exec("CREATE INDEX $index ON $table ($columns)"); }
    }
}

function gmail_email_capture_ready_v1(PDO $pdo): bool
{
    try {
        $pdo->query('SELECT id,owner_user_id,agent_id,conversation_id,request_key,source_hash,input_hash,state,capture_enc,answer_enc,result_enc,reason,lease_epoch,created_epoch,updated_epoch FROM agent_gmail_captures_v1 WHERE 1=0');
        $pdo->query('SELECT id,owner_user_id,agent_id,conversation_id,source_hash,effect_key,capture_id,kind,record_id,created_epoch FROM agent_gmail_capture_actions_v1 WHERE 1=0');return true;
    }
    catch (PDOException) {return false;}
}
