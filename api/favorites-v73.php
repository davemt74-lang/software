<?php
declare(strict_types=1);

require dirname(__DIR__) . '/includes/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function favorite_json(
    bool $ok,
    array $data = [],
    int $status = 200
): never {
    http_response_code($status);
    echo json_encode(
        array_merge(['ok'=>$ok], $data),
        JSON_UNESCAPED_SLASHES |
        JSON_UNESCAPED_UNICODE
    );
    exit;
}

$user = current_user();

if (!$user) {
    favorite_json(
        false,
        ['error'=>'Sign in to manage favorites.'],
        401
    );
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    favorite_json(
        false,
        ['error'=>'POST is required.'],
        405
    );
}

$raw = (string)file_get_contents('php://input');
$input = json_decode($raw, true);

if (!is_array($input)) {
    $input = $_POST;
}

$csrf = (string)($input['csrf_token'] ?? '');

if (
    $csrf === '' ||
    !hash_equals(csrf_token(), $csrf)
) {
    favorite_json(
        false,
        ['error'=>'Session expired.'],
        419
    );
}

$trackId = (int)($input['track_id'] ?? 0);
$action = trim((string)($input['action'] ?? 'toggle'));

if ($trackId < 1) {
    favorite_json(
        false,
        ['error'=>'Track is required.'],
        422
    );
}

$track = get_track_by_id($trackId);

if (
    !$track ||
    !can_view_track($track, $user)
) {
    favorite_json(
        false,
        ['error'=>'Track is unavailable.'],
        404
    );
}

$pdo = db();

if (
    !$pdo ||
    !table_exists('track_favorites')
) {
    favorite_json(
        false,
        ['error'=>'Favorites are not ready. Run the database upgrade.'],
        503
    );
}

$userId = (int)$user['id'];
$artistTrackId = $trackId >= 1000000000 ? $trackId - 1000000000 : 0;
if ($artistTrackId > 0) {
    if (!table_exists('artist_workspace_track_favorites_v181')) favorite_json(false,['error'=>'Favorites are not ready. Run the database upgrade.'],503);
    $stmt=$pdo->prepare('SELECT 1 FROM artist_workspace_track_favorites_v181 WHERE user_id=? AND artist_track_id=? LIMIT 1');$stmt->execute([$userId,$artistTrackId]);$isFavorite=(bool)$stmt->fetchColumn();
    if ($action==='add' || ($action==='toggle'&&!$isFavorite)) {$pdo->prepare('INSERT IGNORE INTO artist_workspace_track_favorites_v181 (user_id,artist_track_id) VALUES (?,?)')->execute([$userId,$artistTrackId]);$isFavorite=true;}
    elseif ($action==='remove' || ($action==='toggle'&&$isFavorite)) {$pdo->prepare('DELETE FROM artist_workspace_track_favorites_v181 WHERE user_id=? AND artist_track_id=?')->execute([$userId,$artistTrackId]);$isFavorite=false;}
    else favorite_json(false,['error'=>'Invalid favorite action.'],422);
    $count=$pdo->prepare('SELECT COUNT(*) FROM artist_workspace_track_favorites_v181 WHERE artist_track_id=?');$count->execute([$artistTrackId]);
    favorite_json(true,['track_id'=>$trackId,'favorite'=>$isFavorite,'favorite_count'=>(int)$count->fetchColumn()]);
}

$stmt = $pdo->prepare(
    'SELECT 1
     FROM track_favorites
     WHERE user_id=? AND track_id=?
     LIMIT 1'
);
$stmt->execute([$userId, $trackId]);
$isFavorite = (bool)$stmt->fetchColumn();

if ($action === 'add') {
    if (!$isFavorite) {
        $pdo->prepare(
            'INSERT IGNORE INTO track_favorites
             (user_id,track_id)
             VALUES (?,?)'
        )->execute([$userId, $trackId]);

        $isFavorite = true;
    }
} elseif ($action === 'remove') {
    $pdo->prepare(
        'DELETE FROM track_favorites
         WHERE user_id=? AND track_id=?'
    )->execute([$userId, $trackId]);

    $isFavorite = false;
} elseif ($action === 'toggle') {
    if ($isFavorite) {
        $pdo->prepare(
            'DELETE FROM track_favorites
             WHERE user_id=? AND track_id=?'
        )->execute([$userId, $trackId]);

        $isFavorite = false;
    } else {
        $pdo->prepare(
            'INSERT IGNORE INTO track_favorites
             (user_id,track_id)
             VALUES (?,?)'
        )->execute([$userId, $trackId]);

        $isFavorite = true;
    }
} else {
    favorite_json(
        false,
        ['error'=>'Invalid favorite action.'],
        422
    );
}

$countStmt = $pdo->prepare(
    'SELECT COUNT(*)
     FROM track_favorites
     WHERE track_id=?'
);
$countStmt->execute([$trackId]);

favorite_json(
    true,
    [
        'track_id'=>$trackId,
        'favorite'=>$isFavorite,
        'favorite_count'=>(int)$countStmt->fetchColumn(),
    ]
);
