<?php
declare(strict_types=1);

/**
 * ML1 service/transaction fixture. Authentication and legacy schema discovery
 * are test adapters; catalog writes, workspace authority and metadata are real.
 * The API test includes this file with MUSIC_SONG_TEST_FIXTURE_ONLY enabled.
 */
if (!defined('STONEFELLOW_ROOT')) {
    define('STONEFELLOW_ROOT', sys_get_temp_dir() . '/vp3-song-unit-' . getmypid());
}

class MusicSongTestPDO extends PDO
{
    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        // Legacy helpers use MySQL row locks. SQLite owns the fixture transaction.
        return parent::prepare(str_replace(' FOR UPDATE', '', $query), $options);
    }
}

function db(): PDO
{
    // The MySQL acceptance entry point injects its guarded, real PDO connection.
    if (isset($GLOBALS['musicSongTestConnection'])) return $GLOBALS['musicSongTestConnection'];
    static $pdo;
    if (!$pdo) {
        $path = getenv('MUSIC_SONG_TEST_DB') ?: ':memory:';
        $pdo = new MusicSongTestPDO('sqlite:' . $path, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $pdo->exec('PRAGMA foreign_keys=ON');
        $pdo->sqliteCreateFunction('NOW', static fn() => date('Y-m-d H:i:s'));
    }
    return $pdo;
}

function current_user(): ?array
{
    $id = (int)($_SERVER['HTTP_X_USER'] ?? $GLOBALS['musicSongTestUserId'] ?? 11);
    if ($id < 1) return null;
    $statement = db()->prepare('SELECT * FROM users WHERE id=? AND is_active=1');
    $statement->execute([$id]);
    return $statement->fetch() ?: null;
}

function is_logged_in(): bool { return current_user() !== null; }
function access_schema_ready(): bool { return true; }
function artist_workspace_v181_schema_ready(?PDO $pdo = null): bool { return true; }
function music_workspace_enabled_v320(array $user): bool { return !empty($user['enabled']); }
function user_has_role(string $role, ?array $user = null): bool
{
    return ($user ?? current_user())['role'] === $role;
}
function has_permission(string $permission, ?array $user = null): bool
{
    // Workspace owners have no global tracks.manage grant in this fixture.
    return $permission === 'chat.access' && ($user ?? current_user()) !== null;
}
function table_exists(string $table): bool
{
    $sql = db()->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql'
        ? 'SELECT 1 FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?'
        : "SELECT 1 FROM sqlite_master WHERE type='table' AND name=?";
    $statement = db()->prepare($sql);
    $statement->execute([$table]);
    return (bool)$statement->fetchColumn();
}
function column_exists(string $table, string $column): bool
{
    if (!preg_match('/^[A-Za-z0-9_]+$/', $table)) return false;
    if (db()->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') {
        $statement = db()->prepare('SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name=? AND column_name=?');
        $statement->execute([$table, $column]);
        return (bool)$statement->fetchColumn();
    }
    return in_array($column, array_column(db()->query('PRAGMA table_info(' . $table . ')')->fetchAll(), 'name'), true);
}
function valid_visibility(string $visibility): bool
{
    return in_array($visibility, ['public', 'members', 'admin'], true);
}
function can_view_visibility(string $visibility, ?array $user = null): bool
{
    // Match the legacy helper's session fallback; the new visitor projection
    // must not accidentally inherit it when explicitly given a null audience.
    $visibility = valid_visibility($visibility) ? $visibility : 'public';
    if ($visibility === 'public') return true;
    $user ??= current_user();
    return $user !== null && ($visibility === 'members' || $user['role'] === 'admin');
}
function can_manage_track_production(array $track, ?array $user = null): bool
{
    return music_workspace_resources_v330_can_manage_track(db(), $track, $user);
}
function can_view_track(array $track, ?array $user = null): bool
{
    return can_manage_track_production($track, $user) || can_view_visibility((string)$track['visibility'], $user);
}

require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/artist-music-v185.php';
require_once dirname(__DIR__) . '/includes/artist-media-v182.php';
require_once dirname(__DIR__) . '/includes/music-workspace-resources-v330.php';
require_once dirname(__DIR__) . '/includes/music-song-metadata-v1.php';

function music_song_test_seed(): void
{
    db()->exec(<<<'SQL'
CREATE TABLE users (
    id INTEGER PRIMARY KEY, role TEXT, is_active INTEGER, enabled INTEGER, display_name TEXT
);
INSERT INTO users VALUES
    (11,'customer',1,1,'Artist One'), (12,'customer',1,0,'Manager'),
    (13,'customer',1,0,'Producer'), (14,'customer',1,1,'Artist Two'),
    (15,'admin',1,1,'Administrator');
CREATE TABLE artist_workspaces_v181 (
    id INTEGER PRIMARY KEY, artist_user_id INTEGER, workspace_name TEXT
);
INSERT INTO artist_workspaces_v181 VALUES (1,11,'First Studio'),(2,14,'Second Studio');
CREATE TABLE artist_team_members (artist_user_id INTEGER, member_user_id INTEGER, team_role TEXT);
INSERT INTO artist_team_members VALUES (11,12,'manager'),(11,13,'producer');
CREATE TABLE albums (
    id INTEGER PRIMARY KEY AUTOINCREMENT, workspace_id INTEGER, title TEXT, release_date TEXT,
    description TEXT DEFAULT '', cover_path TEXT DEFAULT '', visibility TEXT DEFAULT 'public',
    sort_order INTEGER DEFAULT 0, is_published INTEGER DEFAULT 0
);
CREATE TABLE artist_catalog_albums_v181 (
    id INTEGER PRIMARY KEY AUTOINCREMENT, workspace_id INTEGER, source_album_id INTEGER,
    title TEXT, release_date TEXT, description TEXT DEFAULT '', cover_path TEXT DEFAULT '',
    cover_photo_id INTEGER, sort_order INTEGER DEFAULT 0, visibility TEXT DEFAULT 'public',
    is_published INTEGER DEFAULT 0, updated_at TEXT DEFAULT CURRENT_TIMESTAMP
);
INSERT INTO artist_catalog_albums_v181 (id,workspace_id,title) VALUES
    (1,1,'First album'), (2,2,'Foreign album');
CREATE TABLE artist_catalog_photos_v181 (
    id INTEGER PRIMARY KEY AUTOINCREMENT, workspace_id INTEGER, image_path TEXT,
    title TEXT DEFAULT '', caption TEXT DEFAULT '', alt_text TEXT DEFAULT '',
    visibility TEXT DEFAULT 'public', is_published INTEGER DEFAULT 0, sort_order INTEGER DEFAULT 0,
    updated_at TEXT DEFAULT CURRENT_TIMESTAMP
);
INSERT INTO artist_catalog_photos_v181 (id,workspace_id,image_path) VALUES
    (1,1,'/uploads/artist-media/1/photos/cover.png'), (2,2,'/uploads/artist-media/2/photos/cover.png');
CREATE TABLE tracks (
    id INTEGER PRIMARY KEY AUTOINCREMENT, workspace_id INTEGER, owner_user_id INTEGER,
    producer_user_id INTEGER, album_id INTEGER, title TEXT, album TEXT DEFAULT '',
    duration TEXT DEFAULT '', lyrics TEXT NOT NULL DEFAULT '', description TEXT DEFAULT '',
    credits TEXT DEFAULT '', genre TEXT DEFAULT '', mood TEXT DEFAULT '', energy TEXT DEFAULT '',
    tempo_bpm REAL, keywords TEXT DEFAULT '', audio_path TEXT DEFAULT '', cover_path TEXT DEFAULT '',
    visibility TEXT DEFAULT 'public', is_published INTEGER DEFAULT 0, sort_order INTEGER DEFAULT 0,
    updated_at TEXT DEFAULT CURRENT_TIMESTAMP
);
CREATE TABLE artist_catalog_tracks_v181 (
    id INTEGER PRIMARY KEY AUTOINCREMENT, workspace_id INTEGER, source_track_id INTEGER,
    title TEXT, album TEXT DEFAULT '', album_id INTEGER, description TEXT DEFAULT '',
    genre TEXT DEFAULT '', duration_seconds INTEGER, track_number INTEGER DEFAULT 0,
    audio_path TEXT DEFAULT '', cover_path TEXT DEFAULT '', cover_photo_id INTEGER,
    visibility TEXT DEFAULT 'public', is_published INTEGER DEFAULT 0, updated_at TEXT DEFAULT CURRENT_TIMESTAMP
);
INSERT INTO tracks (id,workspace_id,owner_user_id,producer_user_id,title,album,duration,lyrics,audio_path,visibility,is_published) VALUES
    (42,1,11,13,'Legacy recording','First album','2:00','Keep original lyrics','/uploads/artist-music/1/legacy.wav','public',1),
    (84,2,14,NULL,'Foreign secret','','1:00','','/uploads/artist-music/2/foreign.wav','admin',0);
INSERT INTO artist_catalog_tracks_v181 (id,workspace_id,source_track_id,title,album,album_id,duration_seconds,audio_path,visibility,is_published) VALUES
    (10,1,42,'Legacy recording','First album',1,120,'/uploads/artist-music/1/legacy.wav','public',1),
    (20,2,84,'Foreign secret','',NULL,60,'/uploads/artist-music/2/foreign.wav','admin',0);
CREATE TABLE playlists (id INTEGER PRIMARY KEY, owner_user_id INTEGER, artist_workspace_id INTEGER, title TEXT, visibility TEXT);
INSERT INTO playlists VALUES (5,11,NULL,'Personal list','members');
CREATE TABLE playlist_tracks (
    playlist_id INTEGER REFERENCES playlists(id), track_id INTEGER REFERENCES tracks(id),
    sort_order INTEGER DEFAULT 0, added_at TEXT DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY (playlist_id,track_id)
);
INSERT INTO playlist_tracks (playlist_id,track_id) VALUES (5,42);
CREATE TABLE artist_workspace_playlist_tracks_v181 (
    playlist_id INTEGER REFERENCES playlists(id), artist_track_id INTEGER REFERENCES artist_catalog_tracks_v181(id),
    sort_order INTEGER DEFAULT 0, added_at TEXT DEFAULT CURRENT_TIMESTAMP
);
INSERT INTO artist_workspace_playlist_tracks_v181 (playlist_id,artist_track_id,sort_order) VALUES (5,10,1);
CREATE TABLE track_favorites (user_id INTEGER REFERENCES users(id), track_id INTEGER REFERENCES tracks(id), PRIMARY KEY(user_id,track_id));
INSERT INTO track_favorites VALUES (11,42);
CREATE TABLE artist_workspace_track_favorites_v181 (user_id INTEGER, artist_track_id INTEGER);
INSERT INTO artist_workspace_track_favorites_v181 VALUES (11,10);
CREATE TABLE track_play_sessions (
    id INTEGER PRIMARY KEY, user_id INTEGER REFERENCES users(id), track_id INTEGER REFERENCES tracks(id),
    started_at TEXT, last_position_seconds REAL, listened_seconds REAL, duration_seconds REAL,
    qualified_play INTEGER, completed INTEGER
);
INSERT INTO track_play_sessions VALUES (7,11,42,'2026-01-02 03:04:05',27,45,120,1,0);
CREATE TABLE track_projects (id INTEGER PRIMARY KEY, workspace_id INTEGER, track_id INTEGER REFERENCES tracks(id), project_name TEXT);
INSERT INTO track_projects VALUES (8,1,42,'Preserved production project');
CREATE TABLE track_stems (id INTEGER PRIMARY KEY, workspace_id INTEGER, track_id INTEGER REFERENCES tracks(id), project_id INTEGER REFERENCES track_projects(id), file_name TEXT);
INSERT INTO track_stems VALUES (9,1,42,8,'Bass.wav');
CREATE TABLE stem_mix_saves (id INTEGER PRIMARY KEY, track_id INTEGER REFERENCES tracks(id), mix_json TEXT);
INSERT INTO stem_mix_saves VALUES (6,42,'{"volume":0.4}');
CREATE TABLE shows (id INTEGER PRIMARY KEY, workspace_id INTEGER);
CREATE TABLE release_plans (id INTEGER PRIMARY KEY, workspace_id INTEGER);
CREATE TABLE release_items (id INTEGER PRIMARY KEY, workspace_id INTEGER);
CREATE TABLE track_credits (id INTEGER PRIMARY KEY, workspace_id INTEGER);
SQL);
}

function music_song_test_check(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

function music_song_test_rejects(callable $operation, string $message, ?string $class = null): Throwable
{
    try {
        $operation();
    } catch (Throwable $error) {
        if ($class !== null) music_song_test_check($error instanceof $class, $message . ': wrong exception ' . get_class($error));
        return $error;
    }
    throw new RuntimeException($message);
}

function music_song_test_legacy_references(): array
{
    $snapshot = [];
    foreach (['playlist_tracks', 'artist_workspace_playlist_tracks_v181', 'track_favorites',
        'artist_workspace_track_favorites_v181', 'track_play_sessions', 'track_projects',
        'track_stems', 'stem_mix_saves'] as $table) {
        $snapshot[$table] = db()->query('SELECT * FROM ' . $table . ' ORDER BY rowid')->fetchAll();
    }
    return $snapshot;
}

function music_song_test_snapshot(): array
{
    $snapshot = [];
    $tables = db()->query("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%' ORDER BY name")->fetchAll(PDO::FETCH_COLUMN);
    foreach ($tables as $table) $snapshot[$table] = db()->query('SELECT * FROM ' . $table . ' ORDER BY rowid')->fetchAll();
    return $snapshot;
}

function music_song_test_metadata(): array
{
    return [
        'recording_version' => 'Studio mix', 'isrc' => 'USABC2600001', 'isrc_status' => 'provided',
        'language' => 'en', 'explicit_content' => 'no', 'work_id' => '', 'work_revision' => 0,
        'work_title' => 'Fixture composition', 'iswc' => 'T1234567890', 'iswc_status' => 'provided',
        'writers' => [['party_id' => '', 'name' => 'Writer One', 'ipi' => '00123456789', 'pro' => 'BMI', 'role' => 'Composer']],
        'publishers' => [['party_id' => '', 'name' => 'Publishing Co', 'ipi' => '00123456780', 'pro' => 'ASCAP', 'role' => 'Publisher']],
        'performers' => [['party_id' => '', 'name' => 'Artist One', 'ipi' => '', 'pro' => '', 'role' => 'Lead vocals']],
        'label' => ['party_id' => '', 'name' => 'Label One'],
        'copyright_notice' => '© 2026 Writer One', 'phonogram_notice' => '℗ 2026 Artist One',
        'rights_notes' => 'PRIVATE-AGREEMENT-DO-NOT-PUBLISH', 'contact_email' => 'private@example.test',
    ];
}

function music_song_test_input(int $id, array $changes = []): array
{
    $row = artist_music_v185_track(db(), 1, $id);
    music_song_test_check($row !== null, 'Fixture catalog track is missing');
    $recording = music_song_metadata_v1_get(db(), 1, $id, current_user());
    return array_replace([
        'id' => $id, 'title' => $row['title'], 'description' => $row['description'],
        'genre' => $row['genre'], 'album_id' => $row['album_id'] ?? 0,
        'duration_seconds' => $row['duration_seconds'] ?? 0, 'track_number' => $row['track_number'],
        'cover_photo_id' => $row['cover_photo_id'] ?? 0,
        'visibility' => $row['visibility'], 'is_published' => $row['is_published'],
        'revision' => music_catalog_revision($row), 'metadata_revision' => $recording['revision'],
        'music_metadata' => $recording['metadata'],
    ], $changes);
}

function music_song_test_rejects_status(callable $operation, int $status, string $message): void
{
    $error = music_song_test_rejects($operation, $message);
    music_song_test_check($error->getCode() === $status, $message . ': expected ' . $status . ', got ' . $error->getCode() . ' (' . $error->getMessage() . ')');
}

function music_song_test_run(): void
{
    music_song_test_seed();
    $pdo = db();
    $owner = current_user();
    $references = music_song_test_legacy_references();
    $beforeInstall = music_song_test_snapshot();
    music_song_test_rejects_status(
        fn() => music_catalog_write($pdo, 1, $owner, 'track', 'save', ['title' => 'Blocked before upgrade']),
        503, 'Missing metadata schema must block writes without request-time DDL'
    );
    music_song_test_check(music_song_test_snapshot() === $beforeInstall, 'A blocked save installed schema or changed legacy data');

    music_song_metadata_v1_install($pdo);
    music_song_test_check(music_song_metadata_v1_schema_ready($pdo), 'Metadata upgrade did not create the required schema');
    $afterInstall = music_song_test_snapshot();
    music_song_metadata_v1_install($pdo);
    music_song_test_check(music_song_test_snapshot() === $afterInstall, 'Repeated upgrade mutated migrated data or revision history');
    music_song_test_check(music_song_test_legacy_references() === $references, 'Migration changed existing personal/production references');
    music_song_test_check((int)artist_music_v185_track($pdo, 1, 10)['source_track_id'] === 42, 'Migration replaced the legacy backing ID');

    $legacy = music_song_metadata_v1_get($pdo, 1, 10, $owner);
    music_song_test_check(!empty($legacy['recording_id']) && !empty($legacy['work_id']) && !empty($legacy['asset_id']), 'Existing recording/work/asset identities were not migrated');
    music_song_test_check($legacy['readiness']['playback']['ready'] === true, 'Matching published catalog/backing with readable audio is not ready');
    $pdo->exec('UPDATE tracks SET is_published=0 WHERE id=42');
    music_song_test_check(music_song_metadata_v1_get($pdo, 1, 10, $owner)['readiness']['playback']['ready'] === false, 'Readable catalog audio ignored an unpublished backing');
    music_song_test_check(music_song_metadata_v1_public($pdo, 1, 10, null) === null, 'Published catalog resurrected unpublished backing credits');
    $pdo->exec("UPDATE tracks SET is_published=1,visibility='members' WHERE id=42");
    music_song_test_check(music_song_metadata_v1_get($pdo, 1, 10, $owner)['readiness']['playback']['ready'] === false, 'Playback readiness ignored a backing audience mismatch');
    $pdo->exec("UPDATE tracks SET visibility='public' WHERE id=42");
    $pdo->exec('UPDATE music_recordings_v1 SET source_track_id=84 WHERE catalog_track_id=10');
    music_song_test_check(music_song_metadata_v1_get($pdo, 1, 10, $owner)['readiness']['playback']['ready'] === false, 'Playback readiness ignored recording/backing identity drift');
    music_song_test_check(music_song_metadata_v1_public($pdo, 1, 10, null) === null, 'Public projection ignored recording/backing identity drift');
    $beforeReport = music_song_test_snapshot();
    foreach (['save', 'delete', 'restore'] as $action) {
        music_song_test_rejects_status(fn() => music_catalog_write($pdo, 1, $owner, 'track', $action, music_song_test_input(10)), 409, 'Identity drift was accepted by ' . $action);
        music_song_test_check(music_song_test_snapshot() === $beforeReport, 'Identity drift mutation partially changed the song');
    }
    $report = music_song_metadata_v1_report($pdo);
    music_song_test_check(($report['issue_counts']['recording_backing_conflict'] ?? 0) === 1, 'Read-only review did not identify recording/backing identity drift');
    music_song_test_check(music_song_test_snapshot() === $beforeReport, 'Read-only mapping report mutated stored data');
    $stagedDrift = music_song_metadata_v1_install($pdo);
    music_song_test_check($stagedDrift['staged'] === 1 && (int)$pdo->query('SELECT source_track_id FROM music_recordings_v1 WHERE catalog_track_id=10')->fetchColumn() === 84, 'Migration silently repaired recording/backing drift');
    $pdo->exec('UPDATE music_recordings_v1 SET source_track_id=42 WHERE catalog_track_id=10');
    music_song_metadata_v1_install($pdo);
    $private = music_song_test_metadata();
    $private['work_id'] = $legacy['work_id'];
    $private['work_revision'] = $legacy['work_revision'];
    $firstEdit = music_song_test_input(10, ['title' => 'Edited through canonical service', 'music_metadata' => $private]);
    music_catalog_write($pdo, 1, $owner, 'track', 'save', $firstEdit);
    $edited = music_song_metadata_v1_get($pdo, 1, 10, $owner);
    music_song_test_check($edited['recording_id'] === $legacy['recording_id'] && $edited['asset_id'] === $legacy['asset_id'], 'Metadata edit replaced recording or audio identity');
    music_song_test_check((int)$edited['revision'] === (int)$legacy['revision'] + 1, 'Successful edit did not advance metadata revision');
    music_song_test_check($edited['metadata']['rights_notes'] === $private['rights_notes'] && $edited['metadata']['contact_email'] === $private['contact_email'], 'Private metadata did not survive save/read');
    foreach (['earnings', 'payout'] as $capability) {
        music_song_test_check($edited['readiness'][$capability]['state'] === 'not_configured' && $edited['readiness'][$capability]['ready'] === false, 'Metadata completion fabricated ' . $capability . ' readiness');
    }
    $audit = $pdo->prepare('SELECT * FROM music_metadata_revisions_v1 WHERE recording_id=? AND revision=?');
    $audit->execute([$edited['recording_id'], $edited['revision']]);
    $revision = $audit->fetch();
    music_song_test_check((int)$revision['actor_user_id'] === 11 && $revision['created_at'] !== '' && $revision['provenance'] !== 'legacy_import', 'Saved metadata lacks actor/time/provenance');
    $recorded = json_decode($revision['snapshot_json'], true, 32, JSON_THROW_ON_ERROR);
    music_song_test_check($recorded['recording_id'] === $edited['recording_id'] && (int)$recorded['source_track_id'] === 42 && $recorded['metadata']['rights_notes'] === $private['rights_notes'], 'Audit snapshot did not preserve the actual saved metadata/identity');
    $beforeActiveRestore = music_song_test_snapshot();
    music_catalog_write($pdo, 1, $owner, 'track', 'restore', music_song_test_input(10));
    music_song_test_check(music_song_test_snapshot() === $beforeActiveRestore, 'Restore on an active published song changed publication or revisions');
    music_song_test_check((int)artist_music_v185_track($pdo, 1, 10)['source_track_id'] === 42, 'Canonical edit changed the legacy backing ID');
    music_song_test_check($pdo->query('SELECT lyrics FROM tracks WHERE id=42')->fetchColumn() === 'Keep original lyrics', 'Metadata edit erased production lyrics');
    music_song_test_check((int)get_track_by_id(1000000010)['id'] === 1000000010, 'Historical reserved Player reference stopped resolving');
    music_song_test_check(music_song_test_legacy_references() === $references, 'Metadata edit changed personal history, playlists, favorites or production references');

    $public = music_song_metadata_v1_public($pdo, 1, 10, null);
    music_song_test_check(is_array($public), 'Published recording has no public projection');
    $publicJson = json_encode($public, JSON_THROW_ON_ERROR);
    foreach (['PRIVATE-AGREEMENT', 'private@example.test', '00123456789', '00123456780', 'BMI', 'ASCAP', '/uploads/', 'contact_email', 'rights_notes'] as $secret) {
        music_song_test_check(!str_contains($publicJson, $secret), 'Public projection leaked ' . $secret);
    }
    music_song_test_check(str_contains($publicJson, 'Writer One'), 'Safe public creator credit is absent');
    $pdo->exec("UPDATE artist_catalog_tracks_v181 SET visibility='members' WHERE id=10; UPDATE tracks SET visibility='members' WHERE id=42;");
    music_song_test_check(music_song_metadata_v1_public($pdo, 1, 10, null) === null, 'Anonymous projection inherited the logged-in owner session for members-only music');
    music_song_test_check(music_song_metadata_v1_public($pdo, 1, 10, ['id' => 0, 'role' => 'admin']) === null, 'An invalid audience acquired administrator visibility');
    music_song_test_check(is_array(music_song_metadata_v1_public($pdo, 1, 10, $owner)), 'Explicit authorized member lost members-only public credits');
    $pdo->exec("UPDATE artist_catalog_tracks_v181 SET visibility='public' WHERE id=10; UPDATE tracks SET visibility='public' WHERE id=42;");

    $beforeRejected = music_song_test_snapshot();
    music_song_test_rejects_status(fn() => music_catalog_write($pdo, 1, $owner, 'track', 'save', $firstEdit), 409, 'Stale edit overwrote newer metadata');
    $missingRevision = music_song_test_input(10);
    unset($missingRevision['revision']);
    music_song_test_rejects_status(fn() => music_catalog_write($pdo, 1, $owner, 'track', 'save', $missingRevision), 409, 'Missing catalog revision bypassed concurrency control');
    $missingMetadataRevision = music_song_test_input(10);
    unset($missingMetadataRevision['metadata_revision']);
    music_song_test_rejects_status(fn() => music_catalog_write($pdo, 1, $owner, 'track', 'save', $missingMetadataRevision), 409, 'Missing metadata revision bypassed concurrency control');
    music_song_test_rejects(fn() => music_catalog_write($pdo, 1, $owner, 'track', 'save', music_song_test_input(10, ['album_id' => 2])), 'Foreign workspace album was accepted');
    music_song_test_rejects(fn() => music_catalog_write($pdo, 1, $owner, 'track', 'save', music_song_test_input(10, ['cover_photo_id' => 2])), 'Foreign workspace artwork was accepted');
    music_song_test_check(music_song_test_snapshot() === $beforeRejected, 'A rejected stale/cross-workspace save changed stored data');

    $GLOBALS['musicSongTestUserId'] = 14;
    $outsider = current_user();
    music_song_test_rejects_status(fn() => music_song_metadata_v1_get($pdo, 1, 10, $outsider), 403, 'Foreign owner read private metadata');
    music_song_test_rejects_status(fn() => music_song_metadata_v1_parties($pdo, 1, $outsider), 403, 'Foreign owner read party defaults');
    music_song_test_rejects_status(fn() => music_song_metadata_v1_works($pdo, 1, $outsider), 403, 'Foreign owner read private work list');
    music_song_test_rejects_status(fn() => music_catalog_write($pdo, 1, $outsider, 'track', 'save', ['title' => 'Foreign write']), 403, 'Foreign owner wrote another workspace');
    $GLOBALS['musicSongTestUserId'] = 11;

    $draftMetadata = music_song_metadata_v1_defaults(['title' => 'Incomplete draft']);
    $draftInput = [
        'title' => 'Incomplete draft', 'visibility' => 'admin', 'is_published' => 0,
        'metadata_revision' => 0, 'music_metadata' => $draftMetadata,
        'request_key' => 'unit-create-incomplete-draft-0001',
    ];
    $draftId = music_catalog_write($pdo, 1, $owner, 'track', 'save', $draftInput);
    $draft = music_song_metadata_v1_get($pdo, 1, $draftId, $owner);
    music_song_test_check(!empty($draft['recording_id']) && empty($draft['asset_id']), 'Audio-free draft did not retain an honest empty asset state');
    music_song_test_check(music_song_metadata_v1_public($pdo, 1, $draftId, null) === null, 'Anonymous projection exposed a draft through the active owner identity');
    $afterDraft = music_song_test_snapshot();
    music_song_test_check(music_catalog_write($pdo, 1, $owner, 'track', 'save', $draftInput) === $draftId, 'Same request retry created another catalog ID');
    music_song_test_check(music_song_test_snapshot() === $afterDraft, 'Same request retry duplicated backing, asset or metadata revisions');
    music_song_test_rejects_status(fn() => music_catalog_write($pdo, 1, $owner, 'track', 'save', array_replace($draftInput, ['title' => 'Different payload'])), 409, 'Request key accepted different content');
    music_song_test_rejects(fn() => music_catalog_write($pdo, 1, $owner, 'track', 'save', music_song_test_input($draftId, ['is_published' => 1])), 'Audio-free draft became published');
    music_song_test_check(music_song_test_snapshot() === $afterDraft, 'Invalid retry/publication changed the draft');
    $draftSourceId = (int)artist_music_v185_track($pdo, 1, $draftId)['source_track_id'];
    $missingPath = '/uploads/artist-music/1/missing-listening-audio.wav';
    $pdo->prepare('UPDATE artist_catalog_tracks_v181 SET audio_path=? WHERE id=?')->execute([$missingPath, $draftId]);
    $pdo->prepare('UPDATE tracks SET audio_path=? WHERE id=?')->execute([$missingPath, $draftSourceId]);
    $beforeMissingPublish = music_song_test_snapshot();
    music_song_test_rejects_status(fn() => music_catalog_write($pdo, 1, $owner, 'track', 'save', music_song_test_input($draftId, ['is_published' => 1])), 422, 'A nonempty but missing audio path was published');
    music_song_test_check(music_song_test_snapshot() === $beforeMissingPublish, 'Missing-audio publication partially saved');
    music_catalog_write($pdo, 1, $owner, 'track', 'save', music_song_test_input($draftId, ['title' => 'Repairable missing-audio draft', 'is_published' => 0]));
    music_song_test_check(music_song_metadata_v1_get($pdo, 1, $draftId, $owner)['readiness']['playback']['ready'] === false, 'Missing audio draft acquired playback readiness');
    $legacyPath = STONEFELLOW_ROOT . '/uploads/artist-music/1/legacy.wav';
    chmod($legacyPath, 0000);
    clearstatcache(true, $legacyPath);
    try {
        if (!is_readable($legacyPath)) {
            $beforeUnreadable = music_song_test_snapshot();
            music_song_test_rejects_status(fn() => music_catalog_write($pdo, 1, $owner, 'track', 'save', music_song_test_input(10)), 422, 'An unreadable audio file remained publishable');
            music_song_test_check(music_song_test_snapshot() === $beforeUnreadable, 'Unreadable-audio publication partially saved');
        } else {
            echo "MUSIC_SONG_METADATA_UNREADABLE_FILE=SKIP runtime can read mode-0000 fixture bytes\n";
        }
    } finally {
        chmod($legacyPath, 0600);
        clearstatcache(true, $legacyPath);
    }

    $secondMetadata = music_song_metadata_v1_defaults(['title' => 'Second interpretation']);
    $secondMetadata['work_id'] = $edited['work_id'];
    $secondMetadata['work_revision'] = $edited['work_revision'];
    $secondId = music_catalog_write($pdo, 1, $owner, 'track', 'save', [
        'title' => 'Second interpretation', 'visibility' => 'admin', 'metadata_revision' => 0,
        'music_metadata' => $secondMetadata, 'request_key' => 'unit-create-shared-work-0002',
    ]);
    $second = music_song_metadata_v1_get($pdo, 1, $secondId, $owner);
    music_song_test_check($second['work_id'] === $edited['work_id'] && $second['recording_id'] !== $edited['recording_id'], 'Shared composition merged recording identity');
    music_song_test_check($second['metadata']['writers'] === $edited['metadata']['writers'], 'Selecting an existing work lost canonical creators');
    music_song_test_check(empty($second['metadata']['isrc']), 'A unique recording identifier was silently copied');
    $sharedEdit = music_song_test_input(10);
    $sharedEdit['music_metadata']['work_title'] = 'Do not silently edit both recordings';
    $beforeSharedConflict = music_song_test_snapshot();
    music_song_test_rejects_status(fn() => music_catalog_write($pdo, 1, $owner, 'track', 'save', $sharedEdit), 409, 'Shared work changed through a single-recording edit');
    music_song_test_check(music_song_test_snapshot() === $beforeSharedConflict, 'Shared-work conflict partially saved the recording');

    $invalid = music_song_test_input(10);
    $invalid['music_metadata']['isrc'] = 'not-an-isrc';
    music_song_test_rejects_status(fn() => music_catalog_write($pdo, 1, $owner, 'track', 'save', $invalid), 422, 'Malformed provided ISRC was accepted');
    $partyConflict = music_song_test_input(10);
    $partyConflict['music_metadata']['performers'][0]['name'] = 'Mutate canonical party through copied form';
    music_song_test_rejects_status(fn() => music_catalog_write($pdo, 1, $owner, 'track', 'save', $partyConflict), 409, 'Existing party identity was silently redefined');
    $economicField = music_song_test_input(10);
    $economicField['music_metadata']['royalty_rate'] = '0.01';
    music_song_test_rejects_status(fn() => music_catalog_write($pdo, 1, $owner, 'track', 'save', $economicField), 422, 'A descriptive metadata save accepted an economic rate');

    $beforeRollback = music_song_test_snapshot();
    $pdo->exec("CREATE TRIGGER reject_metadata_revision BEFORE INSERT ON music_metadata_revisions_v1 BEGIN SELECT RAISE(ABORT,'forced metadata audit failure'); END;");
    $rollbackInput = music_song_test_input(10, ['title' => 'Must roll back catalog and backing']);
    music_song_test_rejects(fn() => music_catalog_write($pdo, 1, $owner, 'track', 'save', $rollbackInput), 'Related metadata failure was hidden');
    $pdo->exec('DROP TRIGGER reject_metadata_revision');
    music_song_test_check(!$pdo->inTransaction(), 'Failed metadata write leaked an open transaction');
    music_song_test_check(music_song_test_snapshot() === $beforeRollback, 'Related metadata failure left partial catalog/backing/party/work changes');

    $GLOBALS['musicSongTestUserId'] = 12;
    $manager = current_user();
    $managerInput = music_song_test_input(10, ['title' => 'Authorized manager edit']);
    music_catalog_write($pdo, 1, $manager, 'track', 'save', $managerInput);
    $pdo->exec('DELETE FROM artist_team_members WHERE member_user_id=12');
    music_song_test_rejects_status(fn() => music_song_metadata_v1_get($pdo, 1, 10, $manager), 403, 'Revoked manager retained private read access');
    music_song_test_rejects_status(fn() => music_catalog_write($pdo, 1, $manager, 'track', 'save', $managerInput), 403, 'Revoked manager retained write access');
    $GLOBALS['musicSongTestUserId'] = 11;

    $beforeArchive = music_song_metadata_v1_get($pdo, 1, 10, $owner);
    $assetRows = $pdo->query('SELECT * FROM music_assets_v1 ORDER BY rowid')->fetchAll();
    music_catalog_write($pdo, 1, $owner, 'track', 'delete', music_song_test_input(10));
    $archived = music_song_metadata_v1_get($pdo, 1, 10, $owner);
    music_song_test_check(!empty($archived['archived']) && $archived['recording_id'] === $beforeArchive['recording_id'], 'Archive destroyed recording identity');
    music_song_test_check((int)artist_music_v185_track($pdo, 1, 10)['is_published'] === 0 && (int)$pdo->query('SELECT is_published FROM tracks WHERE id=42')->fetchColumn() === 0, 'Archive left catalog/backing published');
    music_song_test_check(music_song_metadata_v1_public($pdo, 1, 10, null) === null, 'Archived recording remained public');
    music_song_test_check($pdo->query('SELECT * FROM music_assets_v1 ORDER BY rowid')->fetchAll() === $assetRows, 'Archive removed immutable audio-version evidence');
    music_catalog_write($pdo, 1, $owner, 'track', 'restore', music_song_test_input(10));
    $restored = music_song_metadata_v1_get($pdo, 1, 10, $owner);
    music_song_test_check(empty($restored['archived']) && $restored['recording_id'] === $legacy['recording_id'] && $restored['asset_id'] === $legacy['asset_id'], 'Restore changed recording/audio identity');
    music_song_test_check((int)artist_music_v185_track($pdo, 1, 10)['is_published'] === 0, 'Restore unexpectedly published archived music');
    music_song_test_check(music_song_test_legacy_references() === $references, 'ML1 lifecycle changed personal/production references');

    // Ambiguous legacy mappings are staged without inventing ownership or
    // changing an existing recording, asset, user history or production link.
    $stableRecordings = $pdo->query('SELECT * FROM music_recordings_v1 ORDER BY rowid')->fetchAll();
    $pdo->exec("INSERT INTO tracks (id,workspace_id,owner_user_id,title) VALUES (90,NULL,11,'Unassigned legacy source');");
    $pdo->exec("INSERT INTO artist_catalog_tracks_v181 (id,workspace_id,source_track_id,title) VALUES
        (30,NULL,NULL,'Unassigned legacy catalog'), (31,1,999,'Missing source'),
        (32,1,84,'Cross-workspace source'), (33,1,42,'Duplicate source mapping');");
    $catalogBeforeStaging = $pdo->query('SELECT * FROM artist_catalog_tracks_v181 ORDER BY id')->fetchAll();
    $tracksBeforeStaging = $pdo->query('SELECT * FROM tracks ORDER BY id')->fetchAll();
    $staged = music_song_metadata_v1_install($pdo);
    music_song_test_check($staged['staged'] >= 5 && $staged['migrated'] === 0, 'Ambiguous legacy rows were not staged for explicit mapping review');
    $issues = $pdo->query("SELECT issue_key,reason FROM music_metadata_migration_issues_v1 WHERE status='open'")->fetchAll(PDO::FETCH_KEY_PAIR);
    foreach (['catalog:30' => 'catalog_workspace_unassigned', 'catalog:31' => 'backing_track_missing',
        'catalog:32' => 'backing_workspace_conflict', 'catalog:33' => 'backing_multiple_catalog_links',
        'player:90' => 'player_without_catalog_link'] as $key => $reason) {
        music_song_test_check(($issues[$key] ?? null) === $reason, 'Migration did not report the concrete mapping problem for ' . $key);
    }
    music_song_metadata_v1_install($pdo);
    music_song_test_check($pdo->query('SELECT * FROM artist_catalog_tracks_v181 ORDER BY id')->fetchAll() === $catalogBeforeStaging, 'Migration rewrote unresolved catalog ownership');
    music_song_test_check($pdo->query('SELECT * FROM tracks ORDER BY id')->fetchAll() === $tracksBeforeStaging, 'Migration guessed or rewrote backing ownership');
    music_song_test_check($pdo->query('SELECT * FROM music_recordings_v1 ORDER BY rowid')->fetchAll() === $stableRecordings, 'Migration changed existing recording identity or created an ambiguous recording');
    music_song_test_check(music_song_test_legacy_references() === $references, 'Mapping review staging changed personal/production references');

    echo "MUSIC_SONG_METADATA_V1=PASS real schema rerun/mapping review, canonical drafts/edits, shared works, stable legacy IDs, private audience/projection, provenance, honest financial readiness, authority/revocation, stale/retry conflicts, rollback and archive/restore\n";
}

if (!defined('MUSIC_SONG_TEST_FIXTURE_ONLY')) {
    $audioDirectory = STONEFELLOW_ROOT . '/uploads/artist-music/1';
    if (!is_dir($audioDirectory)) mkdir($audioDirectory, 0700, true);
    $audioPath = $audioDirectory . '/legacy.wav';
    $pcm = str_repeat("\0", 16000);
    file_put_contents($audioPath, pack('a4Va4a4VvvVVvva4V', 'RIFF', 36 + strlen($pcm), 'WAVE', 'fmt ', 16, 1, 1, 8000, 16000, 2, 16, 'data', strlen($pcm)) . $pcm);
    try {
        music_song_test_run();
    } finally {
        unlink($audioPath);
        foreach ([$audioDirectory, dirname($audioDirectory), dirname($audioDirectory, 2), STONEFELLOW_ROOT] as $directory) @rmdir($directory);
    }
}
