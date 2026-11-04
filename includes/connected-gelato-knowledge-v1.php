<?php
declare(strict_types=1);

require_once __DIR__.'/shared-folders-v161.php';

function vp3_gelato_knowledge_schema_v1(PDO $pdo): void
{
    $options = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql'
        ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        : '';
    $pdo->exec("CREATE TABLE IF NOT EXISTS vp3_gelato_knowledge_folders_v1 (
        owner_user_id INT UNSIGNED NOT NULL,
        instance_id CHAR(36) NOT NULL,
        origin VARBINARY(261) NOT NULL,
        folder_id BIGINT UNSIGNED NOT NULL UNIQUE,
        PRIMARY KEY (owner_user_id, instance_id, origin),
        FOREIGN KEY (owner_user_id) REFERENCES users(id) ON DELETE CASCADE,
        FOREIGN KEY (folder_id) REFERENCES artist_transcript_folders_v177(id) ON DELETE CASCADE
    )".$options);
}

function vp3_gelato_knowledge_ready_v1(PDO $pdo): bool
{
    try {
        return $pdo->query('SELECT folder_id FROM vp3_gelato_knowledge_folders_v1 WHERE 1=0') !== false;
    } catch (PDOException) {
        return false;
    }
}

function vp3_gelato_knowledge_folders_v1(PDO $pdo, int $userId): array
{
    $query = $pdo->prepare('SELECT c.id connection_id,f.id,f.folder_name
        FROM vp3_gelato_knowledge_folders_v1 b
        JOIN vp3_gelato_connections_v1 c ON c.owner_user_id=b.owner_user_id AND c.instance_id=b.instance_id AND c.origin=b.origin
        JOIN artist_transcript_folders_v177 f ON f.id=b.folder_id AND f.created_by_user_id=b.owner_user_id
        WHERE b.owner_user_id=?');
    $query->execute([$userId]);
    $folders = [];
    foreach ($query->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $folders[$row['connection_id']] = ['id'=>(int)$row['id'], 'name'=>$row['folder_name']];
    }
    return $folders;
}

function vp3_gelato_knowledge_lock_owner_v1(PDO $pdo, int $userId): void
{
    // Serialize folder creation for this account, including different sites with the same name.
    $suffix = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '';
    $query = $pdo->prepare('SELECT id FROM users WHERE id=?'.$suffix);
    $query->execute([$userId]);
    if (!$query->fetchColumn()) throw new RuntimeException('Gelato connection is unavailable.', 404);
}

/** Called in a transaction with the owner locked and the connection verified. */
function vp3_gelato_knowledge_ensure_v1(PDO $pdo, array $connection): array
{
    $userId = (int)$connection['owner_user_id'];
    $key = [$userId, $connection['instance_id'], $connection['origin']];
    $existing = vp3_gelato_knowledge_folders_v1($pdo, $userId)[$connection['id']] ?? null;
    if ($existing) return $existing;

    $agent = mb_substr($connection['agent_name'], 0, 80, 'UTF-8');
    $host = substr((string)parse_url($connection['origin'], PHP_URL_HOST), 0, 48);
    $named = $pdo->prepare('SELECT 1 FROM artist_transcript_folders_v177 WHERE created_by_user_id=? AND folder_name=?');
    for ($attempt = 0; $attempt < 20; $attempt++) {
        $suffix = $attempt === 0 ? '' : ' ('.$host.($attempt === 1 ? '' : ' '.$attempt).')';
        $name = rtrim(mb_substr($agent, 0, 80 - strlen($suffix), 'UTF-8')).$suffix;
        $named->execute([$userId, $name]);
        if ($named->fetchColumn()) continue;
        try {
            $folder = shared_folders_v161_create($pdo, ['id'=>$userId], $name);
        } catch (RuntimeException $e) {
            if ($e->getMessage() === 'A folder with that name already exists.') continue;
            throw $e;
        }
        $pdo->prepare('INSERT INTO vp3_gelato_knowledge_folders_v1 (owner_user_id,instance_id,origin,folder_id)
            VALUES (?,?,?,?)')->execute([...$key, (int)$folder['id']]);
        return ['id'=>(int)$folder['id'], 'name'=>$folder['folder_name']];
    }
    throw new RuntimeException('A unique Knowledge folder name could not be allocated.', 409);
}

function vp3_gelato_create_knowledge_folder_v1(PDO $pdo, int $userId, string $connectionId): array
{
    $pdo->beginTransaction();
    try {
        vp3_gelato_knowledge_lock_owner_v1($pdo, $userId);
        $connection = vp3_gelato_connection_v1($pdo, $userId, $connectionId, true);
        $folder = vp3_gelato_knowledge_ensure_v1($pdo, $connection);
        $pdo->commit();
        return $folder;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}
