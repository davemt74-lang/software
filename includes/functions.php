<?php
declare(strict_types=1);

function e(mixed $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function site_config(string $key, mixed $default = null): mixed
{
    global $config;
    return $config['site'][$key] ?? $default;
}

function base_path(): string
{
    $base = trim((string)site_config('base_path', ''));
    if ($base === '' || $base === '/') {
        return '';
    }
    return '/' . trim($base, '/');
}

function url(string $path = ''): string
{
    $path = '/' . ltrim($path, '/');
    return base_path() . ($path === '/' ? '/' : $path);
}

function redirect(string $path): never
{
    header('Location: ' . $path);
    exit;
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf_token" value="' . e(csrf_token()) . '">';
}

function verify_csrf(): bool
{
    $given = (string)($_POST['csrf_token'] ?? '');
    return $given !== '' && hash_equals((string)($_SESSION['csrf_token'] ?? ''), $given);
}

function flash(string $key, ?string $message = null): ?string
{
    if ($message !== null) {
        $_SESSION['flash'][$key] = $message;
        return null;
    }

    $value = $_SESSION['flash'][$key] ?? null;
    unset($_SESSION['flash'][$key]);
    return is_string($value) ? $value : null;
}

function setting(string $key, mixed $default = ''): mixed
{
    $pdo = db();
    if (!$pdo) {
        return $default;
    }

    try {
        $stmt = $pdo->prepare('SELECT setting_value FROM settings WHERE setting_key = ? LIMIT 1');
        $stmt->execute([$key]);
        $row = $stmt->fetch();
        return $row ? $row['setting_value'] : $default;
    } catch (Throwable $e) {
        return $default;
    }
}

function save_setting(string $key, string $value): void
{
    $pdo = db();
    if (!$pdo) {
        return;
    }

    $stmt = $pdo->prepare(
        'INSERT INTO settings (setting_key, setting_value)
         VALUES (?, ?)
         ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)'
    );
    $stmt->execute([$key, $value]);
}

function default_links(): array
{
    return [
        'spotify' => 'https://open.spotify.com/artist/4cngj2wPSfLjyibLMUpQFI',
        'apple_music' => 'https://music.apple.com/us/artist/stonefellow/1588143974',
        'tidal' => 'https://tidal.com/artist/28653042',
        'youtube' => 'https://www.youtube.com/@stonefellow',
        'instagram' => 'https://www.instagram.com/stonefellow',
        'facebook' => 'https://www.facebook.com/stonefellow',
    ];
}

function social_link(string $key): string
{
    $defaults = default_links();
    return (string)setting('link_' . $key, $defaults[$key] ?? '');
}

function default_bio(): string
{
    return <<<'BIO'
Stonefellow is an independent music project built around songs that feel lived-in: guitar-driven, direct, and centered on the story inside the lyric.

The sound moves between rock, Americana and stripped-back acoustic music. Some songs arrive with the weight of a full band in mind; others work best with little more than a voice, a guitar and the room around them. That contrast is part of the identity of Stonefellow — music that can feel intimate without losing its edge.

The writing focuses on memory, connection, distance, regret, resilience and the small moments that stay with people longer than expected. Rather than chasing a polished, over-produced feel, Stonefellow leans toward performances that preserve character: the sound of the instrument, the breath before a line, and the imperfections that make a recording feel human.

Stonefellow is also an evolving home for studio sessions, alternate versions, videos and new original music. The goal is simple: make songs worth returning to, tell stories that feel familiar even when they are personal, and build a direct connection between the music and the people listening.

New music, sessions and live information are released through the official Stonefellow site and streaming channels.
BIO;
}

function artist_bio_paragraphs(): array
{
    $bio = trim((string)setting('artist_bio', default_bio()));
    return array_values(array_filter(array_map('trim', preg_split('/\R{2,}/', $bio) ?: [])));
}

function fallback_tracks(): array
{
    return [
        [
            'id' => 1,
            'title' => 'Close to My Heart',
            'album' => 'Stonefellow Sessions',
            'duration' => '3:58',
            'lyrics' => '',
            'description' => '',
            'genre' => '',
            'mood' => '',
            'energy' => '',
            'tempo_bpm' => null,
            'keywords' => '',
            'audio_path' => '/audio/close-to-my-heart.mp3',
            'cover_path' => '/images/stonefellow-studio.png',
            'visibility' => 'public',
        ],
        [
            'id' => 2,
            'title' => 'The Long Way Home',
            'album' => 'Stonefellow',
            'duration' => '4:22',
            'lyrics' => '',
            'description' => '',
            'genre' => '',
            'mood' => '',
            'energy' => '',
            'tempo_bpm' => null,
            'keywords' => '',
            'audio_path' => '/audio/the-long-way-home.mp3',
            'cover_path' => '/images/stonefellow-studio.png',
            'visibility' => 'public',
        ],
        [
            'id' => 3,
            'title' => 'Backroads Prayer',
            'album' => 'Stonefellow',
            'duration' => '3:46',
            'lyrics' => '',
            'description' => '',
            'genre' => '',
            'mood' => '',
            'energy' => '',
            'tempo_bpm' => null,
            'keywords' => '',
            'audio_path' => '/audio/backroads-prayer.mp3',
            'cover_path' => '/images/stonefellow-studio.png',
            'visibility' => 'public',
        ],
        [
            'id' => 4,
            'title' => 'Ghosts of the Radio',
            'album' => 'Stonefellow Sessions',
            'duration' => '3:33',
            'lyrics' => '',
            'description' => '',
            'genre' => '',
            'mood' => '',
            'energy' => '',
            'tempo_bpm' => null,
            'keywords' => '',
            'audio_path' => '/audio/ghosts-of-the-radio.mp3',
            'cover_path' => '/images/stonefellow-studio.png',
            'visibility' => 'public',
        ],
        [
            'id' => 5,
            'title' => 'Whiskey & Words',
            'album' => 'Stonefellow',
            'duration' => '4:08',
            'lyrics' => '',
            'description' => '',
            'genre' => '',
            'mood' => '',
            'energy' => '',
            'tempo_bpm' => null,
            'keywords' => '',
            'audio_path' => '/audio/whiskey-and-words.mp3',
            'cover_path' => '/images/stonefellow-studio.png',
            'visibility' => 'public',
        ],
    ];
}

/**
 * Combine the platform catalog with native Artist Workspace releases.
 *
 * Workspace rows with source_track_id are migration shadows of platform rows,
 * so the platform record wins when both exist. Native workspace tracks use the
 * reserved player id range and can safely coexist with platform track ids.
 */
function merge_player_track_catalogs(array $platformTracks, array $artistTracks): array
{
    $merged = [];
    $platformIds = [];

    foreach ($platformTracks as $track) {
        $trackId = (int)($track['id'] ?? 0);
        if ($trackId < 1) {
            continue;
        }
        $platformIds[$trackId] = true;
        $merged[] = $track;
    }

    foreach ($artistTracks as $track) {
        $artistTrackId = (int)($track['id'] ?? 0);
        $sourceTrackId = (int)($track['source_track_id'] ?? 0);

        if ($sourceTrackId > 0 && isset($platformIds[$sourceTrackId])) {
            continue;
        }
        if ($sourceTrackId < 1 && $artistTrackId < 1) {
            continue;
        }

        $track['id'] = $sourceTrackId > 0
            ? $sourceTrackId
            : 1000000000 + $artistTrackId;
        foreach (['duration','lyrics','description','genre','mood','energy','keywords'] as $key) {
            $track[$key] ??= '';
        }
        $track['tempo_bpm'] ??= null;
        $merged[] = $track;
    }

    return $merged;
}

function get_tracks(): array
{
    $pdo = db();
    if (!$pdo) {
        return array_values(array_filter(fallback_tracks(), 'can_view_track'));
    }

    try {
        $visibilitySelect = column_exists('tracks', 'visibility') ? ', visibility' : ", 'public' AS visibility";
        $platformTracks = $pdo->query(
            "SELECT id, title, album, duration, lyrics, description, genre, mood, energy, tempo_bpm, keywords, audio_path, cover_path{$visibilitySelect}
             FROM tracks
             WHERE is_published = 1
             ORDER BY sort_order ASC, id ASC"
        )->fetchAll();
        $artistTracks = artist_workspace_v181_schema_ready($pdo)
            ? artist_workspace_v181_public_records('tracks', current_user())
            : [];

        // Merge before filtering so a hidden platform row cannot reappear via
        // an older Artist Workspace migration shadow with the same source id.
        return array_values(array_filter(
            merge_player_track_catalogs($platformTracks, $artistTracks),
            'can_view_track'
        ));
    } catch (Throwable $e) {
        return array_values(array_filter(fallback_tracks(), 'can_view_track'));
    }
}

function get_track_by_id(int $id): ?array
{
    if ($id < 1) {
        return null;
    }

    $pdo = db();
    if (!$pdo) {
        foreach (fallback_tracks() as $track) {
            if ((int)$track['id'] === $id) {
                return $track;
            }
        }
        return null;
    }

    try {
        if (artist_workspace_v181_schema_ready($pdo)) {
            $stmt=$pdo->prepare('SELECT * FROM artist_catalog_tracks_v181 WHERE is_published=1 AND (source_track_id=? OR id=?) LIMIT 1');
            $stmt->execute([$id,$id>=1000000000?$id-1000000000:0]); $track=$stmt->fetch();
            if ($track && can_view_visibility((string)$track['visibility'],current_user())) {$track['id']=(int)($track['source_track_id'] ?: (1000000000+(int)$track['id']));return $track;}
        }
        $visibilitySelect = column_exists('tracks', 'visibility') ? ', visibility' : ", 'public' AS visibility";
        $stmt = $pdo->prepare(
            "SELECT id, title, album, duration, lyrics, description, genre, mood, energy, tempo_bpm, keywords, audio_path, cover_path{$visibilitySelect}
             FROM tracks WHERE id = ? AND is_published = 1 LIMIT 1"
        );
        $stmt->execute([$id]);
        $track = $stmt->fetch();
        return $track ?: null;
    } catch (Throwable $e) {
        return null;
    }
}

function get_upcoming_shows(): array
{
    $pdo = db();
    if (!$pdo) {
        return [];
    }

    try {
        if (artist_workspace_v181_schema_ready($pdo)) {
            $rows=artist_workspace_v181_public_records('shows',current_user());
            return array_values(array_filter($rows,static fn(array $show): bool => (string)$show['show_date']>=date('Y-m-d')));
        }
        $stmt = $pdo->query(
            "SELECT id, show_date, venue, city, region, notes, ticket_url
             FROM shows
             WHERE is_published = 1
               AND show_date >= CURRENT_DATE()
             ORDER BY show_date ASC"
        );
        return $stmt->fetchAll();
    } catch (Throwable $e) {
        return [];
    }
}

function normalize_public_path(string $path, string $fallback): string
{
    $path = trim($path);
    if ($path === '') {
        return $fallback;
    }
    if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) {
        return $path;
    }
    return url($path);
}

function upload_file(
    array $file,
    array $allowedExtensions,
    array $allowedMimes,
    int $maxBytes,
    string $subdir
): ?string {
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return null;
    }

    if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Upload failed.');
    }

    if ((int)$file['size'] > $maxBytes) {
        throw new RuntimeException('Uploaded file is too large.');
    }

    $extension = strtolower(pathinfo((string)$file['name'], PATHINFO_EXTENSION));
    if (!in_array($extension, $allowedExtensions, true)) {
        throw new RuntimeException('Unsupported file extension.');
    }

    $mime = '';
    if (function_exists('finfo_open')) {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime = $finfo ? (string)finfo_file($finfo, (string)$file['tmp_name']) : '';
        if ($finfo) {
            finfo_close($finfo);
        }
    }

    if ($mime !== '' && !in_array($mime, $allowedMimes, true)) {
        throw new RuntimeException('Unsupported file type.');
    }

    $targetDir = STONEFELLOW_ROOT . '/uploads/' . trim($subdir, '/');
    if (!is_dir($targetDir) && !mkdir($targetDir, 0755, true) && !is_dir($targetDir)) {
        throw new RuntimeException('Could not create upload directory.');
    }

    $safeName = bin2hex(random_bytes(16)) . '.' . $extension;
    $destination = $targetDir . '/' . $safeName;

    if (!move_uploaded_file((string)$file['tmp_name'], $destination)) {
        throw new RuntimeException('Could not save uploaded file.');
    }

    return '/uploads/' . trim($subdir, '/') . '/' . $safeName;
}


function user_initials(?array $user = null): string
{
    $user ??= current_user();
    $name = trim((string)($user['display_name'] ?? 'User'));
    if ($name === '') {
        return 'U';
    }

    $parts = preg_split('/\s+/', $name) ?: [];
    $first = mb_substr((string)($parts[0] ?? 'U'), 0, 1);
    $last = count($parts) > 1 ? mb_substr((string)end($parts), 0, 1) : '';
    return mb_strtoupper($first . $last);
}

function user_avatar_url(?array $user = null): string
{
    $user ??= current_user();
    $path = trim((string)($user['avatar_path'] ?? ''));
    if ($path === '') {
        return '';
    }

    if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) {
        return $path;
    }

    $userId = (int)($user['id'] ?? 0);
    if ($userId < 1) {
        return '';
    }

    return url('/avatar.php?user=' . $userId . '&v=' . rawurlencode(substr(hash('sha256', $path), 0, 12)));
}

function delete_local_upload(?string $publicPath): void
{
    $publicPath = trim((string)$publicPath);
    if ($publicPath === '' || !str_starts_with($publicPath, '/uploads/')) {
        return;
    }

    $realRoot = realpath(STONEFELLOW_ROOT . '/uploads');
    $candidate = STONEFELLOW_ROOT . '/' . ltrim($publicPath, '/');
    $realCandidate = realpath($candidate);

    if ($realRoot && $realCandidate && str_starts_with($realCandidate, $realRoot . DIRECTORY_SEPARATOR) && is_file($realCandidate)) {
        @unlink($realCandidate);
    }
}
