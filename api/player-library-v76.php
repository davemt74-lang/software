<?php
declare(strict_types=1);

require dirname(__DIR__) . '/includes/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function player_library_json(
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
    player_library_json(
        false,
        ['error'=>'Sign in to continue.'],
        401
    );
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    player_library_json(
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
    player_library_json(
        false,
        ['error'=>'Session expired. Refresh and try again.'],
        419
    );
}

$pdo = db();

if (!$pdo) {
    player_library_json(
        false,
        ['error'=>'Database unavailable.'],
        503
    );
}

$action = trim((string)($input['action'] ?? ''));
$userId = (int)$user['id'];

function player_library_playlist(
    PDO $pdo,
    int $playlistId
): ?array {
    $stmt = $pdo->prepare(
        'SELECT p.*,u.display_name AS owner_name
         FROM playlists p
         INNER JOIN users u ON u.id=p.owner_user_id
         WHERE p.id=?
         LIMIT 1'
    );
    $stmt->execute([$playlistId]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function player_library_track_ids(
    array $input
): array {
    $ids = $input['track_ids'] ?? [];

    if (!is_array($ids)) {
        return [];
    }

    return array_values(
        array_unique(
            array_filter(
                array_map('intval', $ids),
                static fn(int $id): bool => $id > 0
            )
        )
    );
}

function player_library_visible_track_ids(
    array $ids,
    array $user
): array {
    $allowed = [];

    foreach ($ids as $trackId) {
        $track = get_track_by_id((int)$trackId);

        if ($track && can_view_track($track, $user)) {
            $allowed[] = (int)$trackId;
        }
    }

    return $allowed;
}

try {
    if ($action === 'favorite_album') {
        $albumId = (int)($input['album_id'] ?? 0);

        if ($albumId < 1 || !table_exists('album_favorites')) {
            throw new RuntimeException('Album favorites are unavailable.');
        }

        $stmt = $pdo->prepare(
            'SELECT visibility,is_published
             FROM albums
             WHERE id=?
             LIMIT 1'
        );
        $stmt->execute([$albumId]);
        $album = $stmt->fetch();

        if (
            !$album ||
            (
                (int)$album['is_published'] !== 1 &&
                !has_permission('albums.manage', $user)
            ) ||
            !can_view_visibility((string)$album['visibility'], $user)
        ) {
            throw new RuntimeException('Album is unavailable.');
        }

        $check = $pdo->prepare(
            'SELECT 1
             FROM album_favorites
             WHERE user_id=? AND album_id=?'
        );
        $check->execute([$userId, $albumId]);
        $favorite = (bool)$check->fetchColumn();

        if ($favorite) {
            $pdo->prepare(
                'DELETE FROM album_favorites
                 WHERE user_id=? AND album_id=?'
            )->execute([$userId, $albumId]);
            $favorite = false;
        } else {
            $pdo->prepare(
                'INSERT IGNORE INTO album_favorites
                 (user_id,album_id)
                 VALUES (?,?)'
            )->execute([$userId, $albumId]);
            $favorite = true;
        }

        player_library_json(
            true,
            [
                'album_id'=>$albumId,
                'favorite'=>$favorite,
            ]
        );
    }

    if ($action === 'favorite_playlist') {
        $playlistId = (int)($input['playlist_id'] ?? 0);

        if ($playlistId < 1 || !table_exists('playlist_favorites')) {
            throw new RuntimeException('Playlist favorites are unavailable.');
        }

        $playlist = player_library_playlist($pdo, $playlistId);

        if (
            !$playlist ||
            (
                (int)$playlist['owner_user_id'] !== $userId &&
                !in_array(
                    (string)$playlist['visibility'],
                    ['public','members'],
                    true
                )
            )
        ) {
            throw new RuntimeException('Playlist is unavailable.');
        }

        $check = $pdo->prepare(
            'SELECT 1
             FROM playlist_favorites
             WHERE user_id=? AND playlist_id=?'
        );
        $check->execute([$userId, $playlistId]);
        $favorite = (bool)$check->fetchColumn();

        if ($favorite) {
            $pdo->prepare(
                'DELETE FROM playlist_favorites
                 WHERE user_id=? AND playlist_id=?'
            )->execute([$userId, $playlistId]);
            $favorite = false;
        } else {
            $pdo->prepare(
                'INSERT IGNORE INTO playlist_favorites
                 (user_id,playlist_id)
                 VALUES (?,?)'
            )->execute([$userId, $playlistId]);
            $favorite = true;
        }

        player_library_json(
            true,
            [
                'playlist_id'=>$playlistId,
                'favorite'=>$favorite,
            ]
        );
    }

    if ($action === 'show_reminder') {
        $showId = (int)($input['show_id'] ?? 0);

        if ($showId < 1 || !table_exists('show_reminders')) {
            throw new RuntimeException('Show reminders are unavailable.');
        }

        $stmt = $pdo->prepare(
            'SELECT id
             FROM shows
             WHERE id=? AND is_published=1 AND show_date>=NOW()
             LIMIT 1'
        );
        $stmt->execute([$showId]);

        if (!$stmt->fetchColumn()) {
            throw new RuntimeException('Show is unavailable.');
        }

        $check = $pdo->prepare(
            'SELECT 1
             FROM show_reminders
             WHERE user_id=? AND show_id=?'
        );
        $check->execute([$userId, $showId]);
        $enabled = (bool)$check->fetchColumn();

        if ($enabled) {
            $pdo->prepare(
                'DELETE FROM show_reminders
                 WHERE user_id=? AND show_id=?'
            )->execute([$userId, $showId]);
            $enabled = false;
        } else {
            $pdo->prepare(
                'INSERT IGNORE INTO show_reminders
                 (user_id,show_id)
                 VALUES (?,?)'
            )->execute([$userId, $showId]);
            $enabled = true;
        }

        player_library_json(
            true,
            [
                'show_id'=>$showId,
                'enabled'=>$enabled,
            ]
        );
    }

    if ($action === 'playlist_update') {
        $playlistId = (int)($input['playlist_id'] ?? 0);
        $playlist = player_library_playlist($pdo, $playlistId);

        if (!$playlist || (int)$playlist['owner_user_id'] !== $userId) {
            throw new RuntimeException('Playlist is unavailable.');
        }

        $title = trim((string)($input['title'] ?? ''));
        $description = trim((string)($input['description'] ?? ''));
        $visibility = trim((string)($input['visibility'] ?? 'members'));
        $trackIds = player_library_visible_track_ids(
            player_library_track_ids($input),
            $user
        );

        if ($title === '') {
            throw new RuntimeException('Playlist title is required.');
        }

        if (!in_array($visibility, ['public','members'], true)) {
            throw new RuntimeException('Choose a valid playlist visibility.');
        }

        $pdo->beginTransaction();

        $pdo->prepare(
            'UPDATE playlists
             SET title=?,description=?,visibility=?,updated_at=NOW()
             WHERE id=? AND owner_user_id=?'
        )->execute([
            $title,
            $description,
            $visibility,
            $playlistId,
            $userId,
        ]);

        $pdo->prepare(
            'DELETE FROM playlist_tracks
             WHERE playlist_id=?'
        )->execute([$playlistId]);
        if (table_exists('artist_workspace_playlist_tracks_v181')) $pdo->prepare('DELETE FROM artist_workspace_playlist_tracks_v181 WHERE playlist_id=?')->execute([$playlistId]);

        if ($trackIds) {
            $insert = $pdo->prepare(
                'INSERT INTO playlist_tracks
                 (playlist_id,track_id,sort_order)
                 VALUES (?,?,?)'
            );
            $artistInsert = table_exists('artist_workspace_playlist_tracks_v181') ? $pdo->prepare('INSERT INTO artist_workspace_playlist_tracks_v181 (playlist_id,artist_track_id,sort_order) VALUES (?,?,?)') : null;

            foreach ($trackIds as $index => $trackId) {
                if ($trackId >= 1000000000) { if (!$artistInsert) throw new RuntimeException('Playlist storage is not ready. Run the database upgrade.'); $artistInsert->execute([$playlistId,$trackId-1000000000,$index]); }
                else $insert->execute([$playlistId,$trackId,$index]);
            }
        }

        $pdo->commit();

        player_library_json(
            true,
            [
                'playlist_id'=>$playlistId,
                'title'=>$title,
                'visibility'=>$visibility,
                'track_ids'=>$trackIds,
            ]
        );
    }

    if ($action === 'playlist_add_track') {
        $playlistId = (int)($input['playlist_id'] ?? 0);
        $trackId = (int)($input['track_id'] ?? 0);
        $playlist = player_library_playlist($pdo, $playlistId);
        $track = get_track_by_id($trackId);

        if (
            !$playlist ||
            (int)$playlist['owner_user_id'] !== $userId ||
            !$track ||
            !can_view_track($track, $user)
        ) {
            throw new RuntimeException('Playlist or track is unavailable.');
        }

        $artistTrackId = $trackId >= 1000000000 ? $trackId - 1000000000 : 0;
        $sortStmt = $pdo->prepare(
            'SELECT COALESCE(MAX(sort_order),-1)+1
             FROM playlist_tracks
             WHERE playlist_id=?'
        );
        $sortStmt->execute([$playlistId]);
        $sortOrder = (int)$sortStmt->fetchColumn();

        if ($artistTrackId > 0) {
            if (!table_exists('artist_workspace_playlist_tracks_v181')) throw new RuntimeException('Playlist storage is not ready. Run the database upgrade.');
            $pdo->prepare('INSERT IGNORE INTO artist_workspace_playlist_tracks_v181 (playlist_id,artist_track_id,sort_order) VALUES (?,?,?)')->execute([$playlistId,$artistTrackId,$sortOrder]);
        } else {
            $pdo->prepare('INSERT IGNORE INTO playlist_tracks (playlist_id,track_id,sort_order) VALUES (?,?,?)')->execute([$playlistId,$trackId,$sortOrder]);
        }

        $pdo->prepare(
            'UPDATE playlists SET updated_at=NOW() WHERE id=?'
        )->execute([$playlistId]);

        player_library_json(
            true,
            [
                'playlist_id'=>$playlistId,
                'track_id'=>$trackId,
            ]
        );
    }

    if ($action === 'playlist_delete') {
        $playlistId = (int)($input['playlist_id'] ?? 0);
        $playlist = player_library_playlist($pdo, $playlistId);

        if (!$playlist || (int)$playlist['owner_user_id'] !== $userId) {
            throw new RuntimeException('Playlist is unavailable.');
        }

        $pdo->prepare(
            'DELETE FROM playlists
             WHERE id=? AND owner_user_id=?'
        )->execute([$playlistId, $userId]);

        player_library_json(
            true,
            ['playlist_id'=>$playlistId]
        );
    }

    if ($action === 'playlist_duplicate') {
        $playlistId = (int)($input['playlist_id'] ?? 0);
        $playlist = player_library_playlist($pdo, $playlistId);

        if (
            !$playlist ||
            (
                (int)$playlist['owner_user_id'] !== $userId &&
                !in_array(
                    (string)$playlist['visibility'],
                    ['public','members'],
                    true
                )
            )
        ) {
            throw new RuntimeException('Playlist is unavailable.');
        }

        $pdo->beginTransaction();

        $stmt = $pdo->prepare(
            'INSERT INTO playlists
             (owner_user_id,title,description,visibility)
             VALUES (?,?,?,?)'
        );
        $stmt->execute([
            $userId,
            trim((string)$playlist['title']) . ' Copy',
            (string)$playlist['description'],
            'members',
        ]);
        $newId = (int)$pdo->lastInsertId();

        $trackStmt = $pdo->prepare(
            'SELECT track_id,sort_order
             FROM playlist_tracks
             WHERE playlist_id=?
             ORDER BY sort_order,added_at'
        );
        $trackStmt->execute([$playlistId]);

        $insert = $pdo->prepare(
            'INSERT INTO playlist_tracks
             (playlist_id,track_id,sort_order)
             VALUES (?,?,?)'
        );

        foreach ($trackStmt->fetchAll() as $row) {
            $track = get_track_by_id((int)$row['track_id']);

            if (!$track || !can_view_track($track, $user)) {
                continue;
            }

            $insert->execute([
                $newId,
                (int)$row['track_id'],
                (int)$row['sort_order'],
            ]);
        }

        $pdo->commit();

        player_library_json(
            true,
            [
                'playlist_id'=>$newId,
                'message'=>'Playlist duplicated.',
            ]
        );
    }

    throw new RuntimeException('Unsupported library action.');
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    player_library_json(
        false,
        ['error'=>$e->getMessage()],
        422
    );
}
