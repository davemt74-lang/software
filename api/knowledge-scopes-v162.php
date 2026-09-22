<?php

declare(strict_types=1);

require dirname(__DIR__) . '/includes/bootstrap.php';

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

$user = current_user();
if (!$user || !has_permission('chat.access', $user)) {
    http_response_code(403);
    echo json_encode(['ok'=>false, 'error'=>'Chat access is not available for this account.']);
    exit;
}

$pdo = db();
if (!$pdo) {
    http_response_code(503);
    echo json_encode(['ok'=>false, 'error'=>'Knowledge storage is not ready.']);
    exit;
}

$userId = (int)($user['id'] ?? 0);
$folders = [];

if ($userId < 1) {
    http_response_code(403);
    echo json_encode(['ok'=>false, 'error'=>'Knowledge folders are not available for this account.']);
    exit;
}
if (!table_exists('artist_transcript_folders_v177')) {
    http_response_code(503);
    echo json_encode(['ok'=>false, 'error'=>'Knowledge folders need the latest VP3 database upgrade.']);
    exit;
}

try {
    $stmt = $pdo->prepare(
        'SELECT id,folder_name
         FROM artist_transcript_folders_v177
         WHERE created_by_user_id=?
         ORDER BY folder_name,id'
    );
    $stmt->execute([$userId]);

    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
        $id = max(0, (int)($row['id'] ?? 0));
        if ($id < 1) continue;
        $name = trim((string)($row['folder_name'] ?? ''));
        $folders[] = [
            'id'=>$id,
            'name'=>$name !== '' ? $name : 'Folder ' . $id,
        ];
    }
} catch (Throwable $e) {
    error_log('Knowledge scopes v16.2 folder discovery failed: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok'=>false, 'error'=>'Knowledge folders are temporarily unavailable.']);
    exit;
}

echo json_encode([
    'ok'=>true,
    'default_scope'=>['mode'=>'all', 'folder_id'=>0],
    'folders'=>$folders,
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
