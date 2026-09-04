<?php
declare(strict_types=1);

function player_visible_track_map(?array $user = null): array
{
    $user ??= current_user();
    $map = [];

    foreach (get_tracks() as $track) {
        if (!$user || can_view_track($track, $user)) {
            $map[(int)$track['id']] = $track;
        }
    }

    return $map;
}

function player_recent_history(array $user, array $trackMap, int $limit = 12): array
{
    $pdo = db();

    if (!$pdo || !table_exists('track_play_sessions') || !$trackMap) {
        return [];
    }

    $stmt = $pdo->prepare(
        'SELECT
            latest.track_id,
            latest.started_at AS last_played_at,
            latest.last_position_seconds,
            latest.duration_seconds,
            (
              SELECT COALESCE(SUM(total.listened_seconds),0)
              FROM track_play_sessions total
              WHERE total.user_id=latest.user_id
                AND total.track_id=latest.track_id
            ) AS listened_seconds,
            (
              SELECT COALESCE(SUM(total.qualified_play),0)
              FROM track_play_sessions total
              WHERE total.user_id=latest.user_id
                AND total.track_id=latest.track_id
            ) AS qualified_plays,
            (
              SELECT COALESCE(SUM(total.completed),0)
              FROM track_play_sessions total
              WHERE total.user_id=latest.user_id
                AND total.track_id=latest.track_id
            ) AS completed_plays,
            (
              SELECT COUNT(*)
              FROM track_play_sessions total
              WHERE total.user_id=latest.user_id
                AND total.track_id=latest.track_id
            ) AS session_count
         FROM track_play_sessions latest
         WHERE latest.user_id=?
           AND latest.id=(
             SELECT latest2.id
             FROM track_play_sessions latest2
             WHERE latest2.user_id=latest.user_id
               AND latest2.track_id=latest.track_id
             ORDER BY latest2.started_at DESC,latest2.id DESC
             LIMIT 1
           )
         ORDER BY latest.started_at DESC,latest.id DESC
         LIMIT ' . max(1, min(40, $limit))
    );
    $stmt->execute([(int)$user['id']]);

    $rows = [];

    foreach ($stmt->fetchAll() as $row) {
        $trackId = (int)$row['track_id'];

        if (!isset($trackMap[$trackId])) {
            continue;
        }

        $track = $trackMap[$trackId];
        $track['last_played_at'] = (string)$row['last_played_at'];
        $track['last_position_seconds'] = (float)$row['last_position_seconds'];
        $track['duration_seconds_numeric'] = (float)$row['duration_seconds'];
        $track['listened_seconds_total'] = (float)$row['listened_seconds'];
        $track['qualified_plays'] = (int)$row['qualified_plays'];
        $track['completed_plays'] = (int)$row['completed_plays'];
        $track['session_count'] = (int)$row['session_count'];
        $rows[] = $track;
    }

    return $rows;
}

function player_user_stats(array $user, array $trackMap): array
{
    $pdo = db();
    $stats = [
        'listening_seconds'=>0.0,
        'qualified_plays'=>0,
        'completed_plays'=>0,
        'tracks_played'=>0,
        'favorites'=>0,
        'playlists'=>0,
        'top_track'=>null,
        'top_album'=>'',
    ];

    if (!$pdo) {
        return $stats;
    }

    try {
        if (table_exists('track_play_sessions')) {
            $stmt = $pdo->prepare(
                'SELECT
                    COALESCE(SUM(listened_seconds),0) AS listening_seconds,
                    COALESCE(SUM(qualified_play),0) AS qualified_plays,
                    COALESCE(SUM(completed),0) AS completed_plays,
                    COUNT(DISTINCT track_id) AS tracks_played
                 FROM track_play_sessions
                 WHERE user_id=?'
            );
            $stmt->execute([(int)$user['id']]);
            $row = $stmt->fetch();

            if ($row) {
                $stats['listening_seconds'] = (float)$row['listening_seconds'];
                $stats['qualified_plays'] = (int)$row['qualified_plays'];
                $stats['completed_plays'] = (int)$row['completed_plays'];
                $stats['tracks_played'] = (int)$row['tracks_played'];
            }

            $topStmt = $pdo->prepare(
                'SELECT
                    track_id,
                    SUM(listened_seconds) AS seconds,
                    COUNT(*) AS sessions
                 FROM track_play_sessions
                 WHERE user_id=?
                 GROUP BY track_id
                 ORDER BY seconds DESC,sessions DESC
                 LIMIT 1'
            );
            $topStmt->execute([(int)$user['id']]);
            $top = $topStmt->fetch();

            if ($top) {
                $trackId = (int)$top['track_id'];

                if (isset($trackMap[$trackId])) {
                    $stats['top_track'] = $trackMap[$trackId];
                }
            }

            $albumStmt = $pdo->prepare(
                'SELECT
                    COALESCE(a.title,t.album) AS album_title,
                    SUM(s.listened_seconds) AS seconds
                 FROM track_play_sessions s
                 INNER JOIN tracks t ON t.id=s.track_id
                 LEFT JOIN albums a ON a.id=t.album_id
                 WHERE s.user_id=?
                 GROUP BY COALESCE(a.title,t.album)
                 ORDER BY seconds DESC
                 LIMIT 1'
            );
            $albumStmt->execute([(int)$user['id']]);
            $stats['top_album'] = (string)($albumStmt->fetchColumn() ?: '');
        }

        if (table_exists('track_favorites')) {
            $stmt = $pdo->prepare(
                'SELECT COUNT(*) FROM track_favorites WHERE user_id=?'
            );
            $stmt->execute([(int)$user['id']]);
            $stats['favorites'] = (int)$stmt->fetchColumn();
        }
        if (table_exists('artist_workspace_track_favorites_v181')) {
            $stmt=$pdo->prepare('SELECT COUNT(*) FROM artist_workspace_track_favorites_v181 WHERE user_id=?');$stmt->execute([(int)$user['id']]);$stats['favorites']+=(int)$stmt->fetchColumn();
        }

        if (table_exists('playlists')) {
            $stmt = $pdo->prepare(
                'SELECT COUNT(*) FROM playlists WHERE owner_user_id=?'
            );
            $stmt->execute([(int)$user['id']]);
            $stats['playlists'] = (int)$stmt->fetchColumn();
        }
    } catch (Throwable $e) {
        error_log('Player stats failed: ' . $e->getMessage());
    }

    return $stats;
}

function player_for_you(array $user, array $trackMap, int $limit = 8): array
{
    $pdo = db();

    if (!$pdo || !$trackMap) {
        return array_slice(array_values($trackMap), 0, $limit);
    }

    $score = [];
    $genreAffinity = [];
    $moodAffinity = [];

    foreach ($trackMap as $trackId => $track) {
        $score[$trackId] = 0.0;
    }

    try {
        if (table_exists('track_favorites')) {
            $stmt = $pdo->prepare(
                'SELECT track_id FROM track_favorites WHERE user_id=?'
            );
            $stmt->execute([(int)$user['id']]);

            foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $trackId) {
                $trackId = (int)$trackId;

                if (!isset($trackMap[$trackId])) {
                    continue;
                }

                $score[$trackId] += 12;

                $genre = trim((string)($trackMap[$trackId]['genre'] ?? ''));
                $mood = trim((string)($trackMap[$trackId]['mood'] ?? ''));

                if ($genre !== '') {
                    $genreAffinity[$genre] = ($genreAffinity[$genre] ?? 0) + 3;
                }

                if ($mood !== '') {
                    $moodAffinity[$mood] = ($moodAffinity[$mood] ?? 0) + 2;
                }
            }
        }
        if (table_exists('artist_workspace_track_favorites_v181')) {
            $stmt=$pdo->prepare('SELECT artist_track_id FROM artist_workspace_track_favorites_v181 WHERE user_id=?');$stmt->execute([(int)$user['id']]);
            foreach($stmt->fetchAll(PDO::FETCH_COLUMN) as $artistTrackId){$trackId=1000000000+(int)$artistTrackId;if(isset($trackMap[$trackId])){$score[$trackId]+=12;$genre=trim((string)($trackMap[$trackId]['genre']??''));$mood=trim((string)($trackMap[$trackId]['mood']??''));if($genre!=='')$genreAffinity[$genre]=($genreAffinity[$genre]??0)+3;if($mood!=='')$moodAffinity[$mood]=($moodAffinity[$mood]??0)+2;}}
        }

        if (table_exists('track_play_sessions')) {
            $stmt = $pdo->prepare(
                'SELECT
                    track_id,
                    SUM(qualified_play) AS qualified_plays,
                    SUM(completed) AS completed_plays,
                    SUM(listened_seconds) AS seconds,
                    SUM(CASE WHEN listened_seconds < 10 THEN 1 ELSE 0 END) AS skips,
                    MAX(started_at) AS last_played
                 FROM track_play_sessions
                 WHERE user_id=?
                 GROUP BY track_id'
            );
            $stmt->execute([(int)$user['id']]);

            foreach ($stmt->fetchAll() as $row) {
                $trackId = (int)$row['track_id'];

                if (!isset($trackMap[$trackId])) {
                    continue;
                }

                $score[$trackId] += min(12, (int)$row['qualified_plays'] * 2);
                $score[$trackId] += min(12, (int)$row['completed_plays'] * 3);
                $score[$trackId] += min(8, ((float)$row['seconds']) / 180);
                $score[$trackId] -= min(8, (int)$row['skips'] * 1.5);

                $genre = trim((string)($trackMap[$trackId]['genre'] ?? ''));
                $mood = trim((string)($trackMap[$trackId]['mood'] ?? ''));

                if ($genre !== '') {
                    $genreAffinity[$genre] = ($genreAffinity[$genre] ?? 0)
                        + min(5, (int)$row['qualified_plays']);
                }

                if ($mood !== '') {
                    $moodAffinity[$mood] = ($moodAffinity[$mood] ?? 0)
                        + min(4, (int)$row['completed_plays']);
                }
            }
        }

        if (
            table_exists('playlists') &&
            table_exists('playlist_tracks')
        ) {
            $stmt = $pdo->prepare(
                'SELECT pt.track_id,COUNT(*) AS memberships
                 FROM playlist_tracks pt
                 INNER JOIN playlists p ON p.id=pt.playlist_id
                 WHERE p.owner_user_id=?
                 GROUP BY pt.track_id'
            );
            $stmt->execute([(int)$user['id']]);

            foreach ($stmt->fetchAll() as $row) {
                $trackId = (int)$row['track_id'];

                if (!isset($trackMap[$trackId])) {
                    continue;
                }

                $score[$trackId] += min(
                    9,
                    (int)$row['memberships'] * 3
                );

                $genre = trim((string)($trackMap[$trackId]['genre'] ?? ''));
                $mood = trim((string)($trackMap[$trackId]['mood'] ?? ''));

                if ($genre !== '') {
                    $genreAffinity[$genre] = ($genreAffinity[$genre] ?? 0) + 2;
                }

                if ($mood !== '') {
                    $moodAffinity[$mood] = ($moodAffinity[$mood] ?? 0) + 1;
                }
            }
        }
    } catch (Throwable $e) {}

    foreach ($trackMap as $trackId => $track) {
        $genre = trim((string)($track['genre'] ?? ''));
        $mood = trim((string)($track['mood'] ?? ''));

        if ($genre !== '') {
            $score[$trackId] += ($genreAffinity[$genre] ?? 0) * 0.8;
        }

        if ($mood !== '') {
            $score[$trackId] += ($moodAffinity[$mood] ?? 0) * 0.6;
        }

        $createdAt = strtotime((string)($track['created_at'] ?? ''));

        if ($createdAt && $createdAt >= strtotime('-60 days')) {
            $score[$trackId] += 2.0;
        }
    }

    arsort($score);

    $result = [];

    foreach (array_keys($score) as $trackId) {
        if (!isset($trackMap[$trackId])) {
            continue;
        }

        $result[] = $trackMap[$trackId];

        if (count($result) >= $limit) {
            break;
        }
    }

    return $result ?: array_slice(array_values($trackMap), 0, $limit);
}

function player_process_show_reminders(array $user): void
{
    $pdo = db();

    if (
        !$pdo ||
        !table_exists('show_reminders') ||
        !table_exists('shows') ||
        !table_exists('notifications')
    ) {
        return;
    }

    try {
        $stmt = $pdo->prepare(
            'SELECT
                r.show_id,
                s.show_date,
                s.venue,
                s.city,
                s.region
             FROM show_reminders r
             INNER JOIN shows s ON s.id=r.show_id
             WHERE
                r.user_id=?
                AND r.reminded_at IS NULL
                AND s.is_published=1
                AND s.show_date >= NOW()
                AND s.show_date <= DATE_ADD(NOW(), INTERVAL 48 HOUR)
             ORDER BY s.show_date'
        );
        $stmt->execute([(int)$user['id']]);

        foreach ($stmt->fetchAll() as $show) {
            $location = trim(
                implode(
                    ', ',
                    array_filter([
                        (string)$show['city'],
                        (string)$show['region'],
                    ])
                )
            );

            create_notification(
                (int)$user['id'],
                'show_reminder',
                'Stonefellow show coming up',
                (string)$show['venue']
                    . ($location !== '' ? ' · ' . $location : '')
                    . ' · '
                    . date('M j, g:i A', strtotime((string)$show['show_date'])),
                url('/chat.php?view=shows'),
                'show_reminder',
                (int)$show['show_id']
            );

            $pdo->prepare(
                'UPDATE show_reminders
                 SET reminded_at=NOW()
                 WHERE user_id=? AND show_id=?'
            )->execute([
                (int)$user['id'],
                (int)$show['show_id'],
            ]);
        }
    } catch (Throwable $e) {
        error_log('Show reminder processing failed: ' . $e->getMessage());
    }
}

function player_notify_new_release(
    string $kind,
    int $sourceId,
    string $title,
    string $targetUrl
): void {
    $pdo = db();

    if (!$pdo || !table_exists('notifications') || $sourceId < 1) {
        return;
    }

    $type = $kind === 'album'
        ? 'new_album_release'
        : 'new_track_release';
    $label = $kind === 'album'
        ? 'New Stonefellow album'
        : 'New Stonefellow track';

    try {
        $source = null;

        if ($kind === 'album') {
            $stmt = $pdo->prepare(
                'SELECT id,visibility,is_published
                 FROM albums
                 WHERE id=?
                 LIMIT 1'
            );
            $stmt->execute([$sourceId]);
            $source = $stmt->fetch() ?: null;
        } else {
            $source = get_track_by_id($sourceId);
        }

        if (
            !$source ||
            (
                $kind !== 'album' &&
                (int)($source['is_published'] ?? 0) !== 1
            )
        ) {
            return;
        }

        $users = $pdo->query(
            'SELECT *
             FROM users
             WHERE is_active=1
             ORDER BY id'
        )->fetchAll();

        foreach ($users as $recipient) {
            $canView = $kind === 'album'
                ? (
                    (int)($source['is_published'] ?? 0) === 1
                    && can_view_visibility(
                        (string)($source['visibility'] ?? 'members'),
                        $recipient
                    )
                )
                : can_view_track($source, $recipient);

            if (!$canView) {
                continue;
            }

            create_notification(
                (int)$recipient['id'],
                $type,
                $label,
                $title . ' is now available in Player.',
                $targetUrl,
                $kind . '_release',
                $sourceId
            );
        }
    } catch (Throwable $e) {
        error_log('Release notification fanout failed: ' . $e->getMessage());
    }
}

function player_notify_artist_post(
    int $postId,
    string $title
): void {
    $pdo = db();

    if (!$pdo || !table_exists('notifications') || $postId < 1) {
        return;
    }

    try {
        $stmt = $pdo->prepare(
            'SELECT id,visibility,is_published
             FROM artist_posts
             WHERE id=?
             LIMIT 1'
        );
        $stmt->execute([$postId]);
        $post = $stmt->fetch();

        if (!$post || (int)$post['is_published'] !== 1) {
            return;
        }

        $users = $pdo->query(
            'SELECT *
             FROM users
             WHERE is_active=1
             ORDER BY id'
        )->fetchAll();

        foreach ($users as $recipient) {
            if (
                !can_view_visibility(
                    (string)$post['visibility'],
                    $recipient
                )
            ) {
                continue;
            }

            create_notification(
                (int)$recipient['id'],
                'artist_post',
                'New Stonefellow update',
                $title . ' is now available in Player.',
                url('/chat.php?view=player'),
                'artist_post',
                $postId
            );
        }
    } catch (Throwable $e) {
        error_log('Artist post notification fanout failed: ' . $e->getMessage());
    }
}

function player_visible_posts(array $user, int $limit = 12): array
{
    $pdo = db();

    if (!$pdo) {
        return [];
    }

    try {
        if (artist_workspace_v181_schema_ready($pdo)) return artist_workspace_v181_public_records('posts', $user, $limit);
        if (!table_exists('artist_posts')) return [];
        $stmt = $pdo->query(
            'SELECT *
             FROM artist_posts
             WHERE is_published=1
             ORDER BY COALESCE(published_at,created_at) DESC,id DESC
             LIMIT ' . max(1, min(50, $limit))
        );

        $posts = [];

        foreach ($stmt->fetchAll() as $post) {
            if (can_view_visibility((string)$post['visibility'], $user)) {
                $posts[] = $post;
            }
        }

        return $posts;
    } catch (Throwable $e) {
        return [];
    }
}
