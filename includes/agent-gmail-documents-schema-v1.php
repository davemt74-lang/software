<?php
declare(strict_types=1);

function agent_gmail_documents_install_v1(PDO $pdo): void
{
    if ($pdo->inTransaction()) throw new LogicException('Install attachment review outside a transaction.');
    $tail=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci':'';
    $pdo->exec("CREATE TABLE IF NOT EXISTS agent_gmail_document_reviews_v1 (
        id CHAR(32) NOT NULL PRIMARY KEY, owner_user_id INT UNSIGNED NOT NULL, agent_id BIGINT NOT NULL,
        conversation_id BIGINT UNSIGNED NOT NULL, request_key CHAR(32) NOT NULL, source_hash CHAR(64) NOT NULL,
        instruction_hash CHAR(64) NOT NULL, state VARCHAR(20) NOT NULL, result_enc MEDIUMTEXT NULL,
        reason VARCHAR(40) NULL, lease_epoch BIGINT NOT NULL, created_epoch BIGINT NOT NULL,
        updated_epoch BIGINT NOT NULL, draft_id CHAR(32) NULL,
        UNIQUE(owner_user_id,agent_id,conversation_id,request_key),
        FOREIGN KEY(owner_user_id) REFERENCES users(id) ON DELETE CASCADE
    )".$tail);
}

function agent_gmail_documents_ready_v1(PDO $pdo): bool
{
    try {$pdo->query('SELECT id,owner_user_id,agent_id,conversation_id,request_key,source_hash,instruction_hash,state,result_enc,reason,lease_epoch,created_epoch,updated_epoch,draft_id FROM agent_gmail_document_reviews_v1 WHERE 1=0');return true;}
    catch (PDOException) {return false;}
}
