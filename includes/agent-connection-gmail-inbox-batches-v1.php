<?php
declare(strict_types=1);

function agent_connection_gmail_inbox_batches_install_v1(PDO $pdo): void
{
    $sqlite = agent_commerce_sqlite_pa2($pdo);
    $id = $sqlite ? 'INTEGER PRIMARY KEY AUTOINCREMENT' : 'BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY';
    $owner = $sqlite ? 'INTEGER' : 'INT UNSIGNED';
    $pdo->exec("CREATE TABLE IF NOT EXISTS agent_connection_gmail_inbox_batches_v1 (
        id $id,
        batch_id CHAR(32) NOT NULL UNIQUE,
        owner_user_id $owner NOT NULL,
        connection_id BIGINT UNSIGNED NOT NULL,
        generation_id BIGINT UNSIGNED NOT NULL,
        report_json LONGTEXT NOT NULL,
        notification_id BIGINT UNSIGNED NULL,
        agent_id BIGINT UNSIGNED NULL,
        conversation_id BIGINT UNSIGNED NULL,
        message_id BIGINT UNSIGNED NULL,
        FOREIGN KEY (owner_user_id) REFERENCES users(id) ON DELETE CASCADE,
        FOREIGN KEY (connection_id) REFERENCES agent_connections_v1(id) ON DELETE CASCADE".($sqlite ? ')' : ",
        INDEX idx_gmail_inbox_batches_delivery_v1 (owner_user_id, connection_id, generation_id, message_id, id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin"));
    if ($sqlite) $pdo->exec('CREATE INDEX IF NOT EXISTS idx_gmail_inbox_batches_delivery_v1
        ON agent_connection_gmail_inbox_batches_v1 (owner_user_id, connection_id, generation_id, message_id, id)');
}

function agent_connection_gmail_inbox_batches_ready_v1(PDO $pdo): bool
{
    try {
        $pdo->query('SELECT id,batch_id,owner_user_id,connection_id,generation_id,report_json,notification_id,agent_id,conversation_id,message_id
            FROM agent_connection_gmail_inbox_batches_v1 WHERE 1=0');
        return true;
    } catch (PDOException) {
        return false;
    }
}

function agent_connection_gmail_inbox_batches_clear_v1(PDO $pdo,int $owner): void
{
    if (!agent_connection_gmail_inbox_batches_ready_v1($pdo)) return;
    $pdo->prepare('DELETE FROM agent_connection_gmail_inbox_batches_v1 WHERE owner_user_id=?')->execute([$owner]);
}

function agent_connection_gmail_inbox_batch_id_v1(array $policy,string $raw): string
{
    return substr(agent_connection_gmail_inbox_key_v1($policy,'review-batch:'.hash('sha256',$raw)),0,32);
}

function agent_connection_gmail_inbox_batch_report_v1(string $raw): ?array
{
    $report = json_decode($raw,true,12);
    if (!is_array($report) || !is_int($report['total'] ?? null) || $report['total'] < 1
        || $report['total'] > 50 || !is_array($report['items'] ?? null) || !$report['items']
        || !is_string($report['at'] ?? null) || !preg_match('/\A\d{4}-\d\d-\d\d \d\d:\d\d:\d\d\z/D',$report['at'])) return null;
    return $report;
}

/** Called inside the Inbox policy transaction that owns the cursor or delivery. */
function agent_connection_gmail_inbox_batch_save_v1(PDO $pdo,array $policy,string $raw,?int $notificationId=null): void
{
    if (agent_connection_gmail_inbox_batch_report_v1($raw) === null) return;
    $generation = agent_connection_gmail_inbox_notice_generation_v1($policy);
    if (!$pdo->inTransaction() || $generation === null) throw new LogicException('Inbox batches require a current Inbox Review transaction.');
    $id = agent_connection_gmail_inbox_batch_id_v1($policy,$raw);
    $query = $pdo->prepare('SELECT 1 FROM agent_connection_gmail_inbox_batches_v1
        WHERE batch_id=? AND owner_user_id=? AND connection_id=? AND generation_id=?');
    $scope = [$policy['owner_user_id'],$policy['connection_id'],$generation];
    $query->execute([$id,...$scope]);
    if ($query->fetchColumn() !== false) return;
    $pdo->prepare('INSERT INTO agent_connection_gmail_inbox_batches_v1
        (batch_id,owner_user_id,connection_id,generation_id,report_json,notification_id) VALUES (?,?,?,?,?,?)')
        ->execute([$id,...$scope,$raw,$notificationId]);
}

function agent_connection_gmail_inbox_batch_v1(PDO $pdo,array $scope,string $id): ?array
{
    if (!preg_match('/\A[a-f0-9]{32}\z/D',$id) || !agent_connection_gmail_inbox_batches_ready_v1($pdo)) return null;
    $query = $pdo->prepare('SELECT * FROM agent_connection_gmail_inbox_batches_v1
        WHERE batch_id=? AND owner_user_id=? AND connection_id=? AND generation_id=?');
    $query->execute([$id,$scope['owner'],$scope['policy']['connection_id'],$scope['generation']]);
    $row = $query->fetch(PDO::FETCH_ASSOC);
    return is_array($row) ? $row : null;
}
