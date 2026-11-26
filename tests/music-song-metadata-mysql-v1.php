<?php
declare(strict_types=1);

/** Real MySQL 8 acceptance; never point this at a populated/application database. */
$dsn = getenv('MUSIC_TEST_MYSQL_DSN') ?: '';
if ($dsn === '') {
    echo "MUSIC_SONG_METADATA_MYSQL_V1=SKIP MUSIC_TEST_MYSQL_DSN is not configured; no MySQL behavior was verified.\n";
    exit(0);
}
if (!str_starts_with($dsn, 'mysql:')) throw new RuntimeException('The music MySQL fixture requires a mysql: DSN.');
if (!in_array('mysql', PDO::getAvailableDrivers(), true)) throw new RuntimeException('The music MySQL fixture requires pdo_mysql.');
$pdo = new PDO($dsn, getenv('MUSIC_TEST_MYSQL_USER') ?: '', getenv('MUSIC_TEST_MYSQL_PASSWORD') ?: '', [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
]);
$database = (string)$pdo->query('SELECT DATABASE()')->fetchColumn();
if (!preg_match('/\Avp3_music_test[a-zA-Z0-9_]*\z/', $database)) {
    throw new RuntimeException('Refusing music fixture: database name must start with vp3_music_test.');
}
if ((int)$pdo->query('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE()')->fetchColumn() !== 0) {
    throw new RuntimeException('Refusing music fixture: use an empty disposable test database.');
}

define('MUSIC_SONG_TEST_FIXTURE_ONLY', true);
define('STONEFELLOW_ROOT', sys_get_temp_dir() . '/vp3-song-mysql-' . bin2hex(random_bytes(6)));
$audioDirectory = STONEFELLOW_ROOT . '/uploads/artist-music/1';
mkdir($audioDirectory, 0700, true);
$audioPath = $audioDirectory . '/legacy.wav';
file_put_contents($audioPath, pack('a4Va4a4VvvVVvva4V', 'RIFF', 38, 'WAVE', 'fmt ', 16, 1, 1, 8000, 16000, 2, 16, 'data', 2) . "\0\0");
$GLOBALS['musicSongTestConnection'] = $pdo;
require __DIR__ . '/music-song-metadata-v1.php';

// Minimal legacy tables use MySQL types/constraints directly. The new metadata
// tables and all writes below run the actual production MySQL DDL and SQL.
$tables = [
    'users' => 'id BIGINT PRIMARY KEY, role VARCHAR(32), is_active TINYINT, enabled TINYINT, display_name VARCHAR(190)',
    'artist_workspaces_v181' => 'id BIGINT PRIMARY KEY, artist_user_id BIGINT, workspace_name VARCHAR(190)',
    'artist_team_members' => 'artist_user_id BIGINT, member_user_id BIGINT, team_role VARCHAR(32)',
    'albums' => "id BIGINT AUTO_INCREMENT PRIMARY KEY, workspace_id BIGINT, title VARCHAR(190), release_date DATE,
        description TEXT, cover_path VARCHAR(500) DEFAULT '', visibility VARCHAR(32) DEFAULT 'public',
        sort_order INT DEFAULT 0, is_published TINYINT DEFAULT 0",
    'artist_catalog_albums_v181' => "id BIGINT AUTO_INCREMENT PRIMARY KEY, workspace_id BIGINT, source_album_id BIGINT,
        title VARCHAR(190), release_date DATE, description TEXT, cover_path VARCHAR(500) DEFAULT '', cover_photo_id BIGINT,
        sort_order INT DEFAULT 0, visibility VARCHAR(32) DEFAULT 'public', is_published TINYINT DEFAULT 0,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP",
    'artist_catalog_photos_v181' => "id BIGINT AUTO_INCREMENT PRIMARY KEY, workspace_id BIGINT, image_path VARCHAR(500),
        title VARCHAR(190), caption TEXT, alt_text VARCHAR(190), visibility VARCHAR(32), is_published TINYINT",
    'tracks' => "id BIGINT AUTO_INCREMENT PRIMARY KEY, workspace_id BIGINT, owner_user_id BIGINT, producer_user_id BIGINT,
        album_id BIGINT, title VARCHAR(190), album VARCHAR(190) DEFAULT '', duration VARCHAR(32) DEFAULT '',
        lyrics TEXT NOT NULL, description TEXT, credits TEXT, genre VARCHAR(120) DEFAULT '', mood VARCHAR(255) DEFAULT '',
        energy VARCHAR(20) DEFAULT '', tempo_bpm DECIMAL(8,2), keywords VARCHAR(500) DEFAULT '',
        audio_path VARCHAR(500) DEFAULT '', cover_path VARCHAR(500) DEFAULT '', visibility VARCHAR(32) DEFAULT 'public',
        is_published TINYINT DEFAULT 0, sort_order INT DEFAULT 0, updated_at DATETIME DEFAULT CURRENT_TIMESTAMP",
    'artist_catalog_tracks_v181' => "id BIGINT AUTO_INCREMENT PRIMARY KEY, workspace_id BIGINT, source_track_id BIGINT,
        title VARCHAR(190), album VARCHAR(190) DEFAULT '', album_id BIGINT, description TEXT, genre VARCHAR(120) DEFAULT '',
        duration_seconds INT, track_number INT DEFAULT 0, audio_path VARCHAR(500) DEFAULT '', cover_path VARCHAR(500) DEFAULT '',
        cover_photo_id BIGINT, visibility VARCHAR(32) DEFAULT 'public', is_published TINYINT DEFAULT 0,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP",
    'track_favorites' => 'user_id BIGINT, track_id BIGINT, PRIMARY KEY(user_id,track_id),
        FOREIGN KEY(user_id) REFERENCES users(id), FOREIGN KEY(track_id) REFERENCES tracks(id)',
    'track_play_sessions' => 'id BIGINT PRIMARY KEY, user_id BIGINT, track_id BIGINT, listened_seconds DECIMAL(10,2),
        FOREIGN KEY(user_id) REFERENCES users(id), FOREIGN KEY(track_id) REFERENCES tracks(id)',
];
$metadataTables = ['music_parties_v1', 'music_works_v1', 'music_work_revisions_v1', 'music_recordings_v1',
    'music_assets_v1', 'music_metadata_revisions_v1', 'music_metadata_migration_issues_v1', 'music_catalog_requests_v1'];
$snapshot = static function () use ($pdo, $tables, $metadataTables): array {
    $rows = [];
    foreach (array_merge(array_keys($tables), $metadataTables) as $table) {
        $result = $pdo->query('SELECT * FROM `' . $table . '`')->fetchAll();
        usort($result, static fn(array $a, array $b): int => strcmp(json_encode($a, JSON_THROW_ON_ERROR), json_encode($b, JSON_THROW_ON_ERROR)));
        $rows[$table] = $result;
    }
    return $rows;
};

try {
    foreach ($tables as $name => $definition) $pdo->exec('CREATE TABLE `' . $name . '` (' . $definition . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    $pdo->exec("INSERT INTO users VALUES (11,'customer',1,1,'Artist One'),(14,'customer',1,1,'Artist Two')");
    $pdo->exec("INSERT INTO artist_workspaces_v181 VALUES (1,11,'First Studio'),(2,14,'Second Studio')");
    $pdo->exec("INSERT INTO artist_catalog_albums_v181 (id,workspace_id,title,description) VALUES (1,1,'First album',''),(2,2,'Foreign album','')");
    $pdo->exec("INSERT INTO tracks (id,workspace_id,owner_user_id,title,lyrics,description,audio_path,visibility,is_published) VALUES (42,1,11,'Legacy recording','Keep lyrics','','/uploads/artist-music/1/legacy.wav','public',1)");
    $pdo->exec("INSERT INTO artist_catalog_tracks_v181 (id,workspace_id,source_track_id,title,description,audio_path,visibility,is_published) VALUES (10,1,42,'Legacy recording','','/uploads/artist-music/1/legacy.wav','public',1)");
    $pdo->exec('INSERT INTO track_favorites VALUES (11,42)');
    $pdo->exec('INSERT INTO track_play_sessions VALUES (7,11,42,45)');

    $firstInstall = music_song_metadata_v1_install($pdo);
    music_song_test_check($firstInstall['migrated'] === 1, 'MySQL installer did not migrate the legacy recording');
    $afterInstall = $snapshot();
    music_song_metadata_v1_install($pdo);
    music_song_test_check($snapshot() === $afterInstall, 'MySQL upgrade rerun changed stable recording data');

    $owner = current_user();
    $legacy = music_song_metadata_v1_get($pdo, 1, 10, $owner);
    $input = music_song_test_input(10, ['title' => 'MySQL canonical edit']);
    $input['music_metadata'] = music_song_test_metadata();
    $input['music_metadata']['work_id'] = $legacy['work_id'];
    $input['music_metadata']['work_revision'] = $legacy['work_revision'];
    music_catalog_write($pdo, 1, $owner, 'track', 'save', $input);
    $edited = music_song_metadata_v1_get($pdo, 1, 10, $owner);
    music_song_test_check($edited['recording_id'] === $legacy['recording_id'] && $edited['asset_id'] === $legacy['asset_id'], 'MySQL edit changed stable recording/asset identity');
    music_song_test_check($edited['metadata']['contact_email'] === 'private@example.test', 'MySQL save lost private metadata');
    $public = music_song_metadata_v1_public($pdo, 1, 10, null);
    music_song_test_check(is_array($public), 'MySQL published projection is unavailable');
    music_song_test_check(!preg_match('/PRIVATE-AGREEMENT|private@example\.test|00123456789|\/uploads\//', json_encode($public, JSON_THROW_ON_ERROR)), 'MySQL public projection leaked private fields');
    music_song_test_rejects_status(fn() => music_catalog_write($pdo, 1, $owner, 'track', 'save', $input), 409, 'MySQL stale edit bypassed version checks');
    $outsider = ['id' => 14, 'role' => 'customer'];
    music_song_test_rejects_status(fn() => music_song_metadata_v1_get($pdo, 1, 10, $outsider), 403, 'MySQL private read crossed workspace authority');

    $draftInput = ['title' => 'MySQL audio-free draft', 'visibility' => 'admin', 'metadata_revision' => 0,
        'music_metadata' => music_song_metadata_v1_defaults(['title' => 'MySQL audio-free draft']),
        'request_key' => 'mysql-create-draft-0000001'];
    $draftId = music_catalog_write($pdo, 1, $owner, 'track', 'save', $draftInput);
    $draft = music_song_metadata_v1_get($pdo, 1, $draftId, $owner);
    music_song_test_check(!empty($draft['recording_id']) && empty($draft['asset_id']), 'MySQL could not persist an honest audio-free draft');
    $afterCreate = $snapshot();
    music_song_test_check(music_catalog_write($pdo, 1, $owner, 'track', 'save', $draftInput) === $draftId, 'MySQL retry created another catalog ID');
    music_song_test_check($snapshot() === $afterCreate, 'MySQL retry duplicated data/revisions');
    music_song_test_rejects_status(fn() => music_catalog_write($pdo, 1, $owner, 'track', 'save', array_replace($draftInput, ['title' => 'Changed retry'])), 409, 'MySQL request key accepted different content');

    $beforeFailure = $snapshot();
    $pdo->exec("CREATE TRIGGER music_song_test_failure BEFORE INSERT ON music_metadata_revisions_v1 FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='forced music metadata failure'");
    $failure = music_song_test_rejects(fn() => music_catalog_write($pdo, 1, $owner, 'track', 'save', music_song_test_input(10, ['title' => 'Must roll back'])), 'MySQL metadata trigger failure was hidden');
    music_song_test_check(str_contains($failure->getMessage(), 'forced music metadata failure'), 'MySQL rollback test failed before reaching the real trigger');
    music_song_test_check(!$pdo->inTransaction(), 'MySQL failure leaked an open transaction');
    music_song_test_check($snapshot() === $beforeFailure, 'MySQL trigger failure left partial catalog/backing/metadata changes');

    // Verify a failed nested write rolls back only its savepoint, preserving the
    // caller's transaction; this uses real MySQL SAVEPOINT and SELECT FOR UPDATE.
    $pdo->beginTransaction();
    $pdo->exec("UPDATE users SET display_name='Caller transaction survives' WHERE id=11");
    music_song_test_rejects(fn() => music_catalog_write($pdo, 1, $owner, 'track', 'save', music_song_test_input(10, ['title' => 'Nested rollback'])), 'Nested MySQL failure was hidden');
    music_song_test_check($pdo->inTransaction() && $pdo->query('SELECT display_name FROM users WHERE id=11')->fetchColumn() === 'Caller transaction survives', 'Nested catalog failure rolled back its caller transaction');
    $pdo->rollBack();
    $pdo->exec('DROP TRIGGER music_song_test_failure');
    music_song_test_check($snapshot() === $beforeFailure, 'Nested failure changed committed data');

    music_catalog_write($pdo, 1, $owner, 'track', 'delete', music_song_test_input(10));
    $archived = music_song_metadata_v1_get($pdo, 1, 10, $owner);
    music_song_test_check($archived['archived'] && $archived['recording_id'] === $legacy['recording_id'] && $archived['asset_id'] === $legacy['asset_id'], 'MySQL archive destroyed recording/asset identity');
    music_song_test_check(music_song_metadata_v1_public($pdo, 1, 10, null) === null, 'MySQL archive remained public');
    music_catalog_write($pdo, 1, $owner, 'track', 'restore', music_song_test_input(10));
    $restored = music_song_metadata_v1_get($pdo, 1, 10, $owner);
    music_song_test_check(!$restored['archived'] && $restored['recording_id'] === $legacy['recording_id'], 'MySQL restore changed recording identity');
    music_song_test_check((int)artist_music_v185_track($pdo, 1, 10)['source_track_id'] === 42 && (int)artist_music_v185_track($pdo, 1, 10)['is_published'] === 0, 'MySQL restore lost backing identity or republished music');
    music_song_test_check($pdo->query('SELECT * FROM track_favorites')->fetchAll() === $afterInstall['track_favorites'] && $pdo->query('SELECT * FROM track_play_sessions')->fetchAll() === $afterInstall['track_play_sessions'], 'MySQL lifecycle changed personal references');

    echo "MUSIC_SONG_METADATA_MYSQL_V1=PASS production MySQL DDL/rerun, native prepared SQL and row locks, canonical draft/edit, private projection, stale/idempotent saves, trigger/savepoint rollback and preserved archive/restore references\n";
} finally {
    if ($pdo->inTransaction()) $pdo->rollBack();
    $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
    foreach (array_reverse(array_merge(array_keys($tables), $metadataTables)) as $table) $pdo->exec('DROP TABLE IF EXISTS `' . $table . '`');
    $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
    unlink($audioPath);
    foreach ([$audioDirectory, dirname($audioDirectory), dirname($audioDirectory, 2), STONEFELLOW_ROOT] as $directory) @rmdir($directory);
}
