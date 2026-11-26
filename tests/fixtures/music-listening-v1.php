<?php
declare(strict_types=1);

/**
 * Isolated legacy catalog/authentication adapters for native listening tests.
 * The metadata migration, media authorization, native ledger, receipt service,
 * Player history and HTTP controllers are the production implementations.
 */
if (!defined('MUSIC_SONG_TEST_FIXTURE_ONLY')) define('MUSIC_SONG_TEST_FIXTURE_ONLY', true);
require_once dirname(__DIR__) . '/music-song-metadata-v1.php';

class MusicListeningTestPDO extends MusicSongTestPDO
{
    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        // These are pre-existing MySQL-only expressions in legacy adapters.
        $query = str_replace(
            ['COALESCE(user_id,0)=?', 'INSERT IGNORE INTO'],
            ['COALESCE(user_id,0)=CAST(? AS INTEGER)', 'INSERT OR IGNORE INTO'],
            $query
        );
        return parent::prepare($query, $options);
    }
}

if (!isset($GLOBALS['musicSongTestConnection'])) {
    $connection = new MusicListeningTestPDO('sqlite:' . (getenv('MUSIC_LISTENING_TEST_DB') ?: ':memory:'), null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $connection->exec('PRAGMA foreign_keys=ON');
    $connection->sqliteCreateFunction('NOW', static fn(): string => date('Y-m-d H:i:s'));
    $GLOBALS['musicSongTestConnection'] = $connection;
}

require_once dirname(__DIR__, 2) . '/includes/music-media.php';
require_once dirname(__DIR__, 2) . '/includes/player-v76.php';
require_once dirname(__DIR__, 2) . '/includes/notifications.php';
require_once dirname(__DIR__, 2) . '/includes/agent-chat-activity.php';
require_once dirname(__DIR__, 2) . '/includes/recommendations.php';
require_once dirname(__DIR__, 2) . '/includes/stem-mp3-metadata-v1.php';
require_once dirname(__DIR__, 2) . '/includes/music-listening-v1.php';

function music_listening_test_tables(): array
{
    $mysql = db()->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
    $id = $mysql ? 'INT UNSIGNED AUTO_INCREMENT PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT';
    $integer = $mysql ? 'INT UNSIGNED' : 'INTEGER';
    $nativeId = $mysql ? 'BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT';
    $sessionId = $mysql ? 'BIGINT UNSIGNED' : 'INTEGER';
    $nativeNumber = $mysql ? 'DECIMAL(12,2)' : 'REAL';
    $number = $mysql ? 'DECIMAL(16,3)' : 'REAL';
    $time = $mysql ? 'DATETIME' : 'TEXT';
    return [
        'users' => "id $integer PRIMARY KEY, role VARCHAR(32), is_active INTEGER, enabled INTEGER, display_name VARCHAR(190)",
        'artist_workspaces_v181' => "id $integer PRIMARY KEY, artist_user_id $integer, workspace_name VARCHAR(190)",
        'artist_team_members' => "artist_user_id $integer, member_user_id $integer, team_role VARCHAR(32)",
        'albums' => "id $id, workspace_id $integer, title VARCHAR(190), release_date DATE, description TEXT,
            cover_path VARCHAR(500) DEFAULT '', visibility VARCHAR(32) DEFAULT 'public', sort_order INTEGER DEFAULT 0, is_published INTEGER DEFAULT 0",
        'artist_catalog_albums_v181' => "id $id, workspace_id $integer, source_album_id $integer, title VARCHAR(190), release_date DATE,
            description TEXT, cover_path VARCHAR(500) DEFAULT '', cover_photo_id $integer, sort_order INTEGER DEFAULT 0,
            visibility VARCHAR(32) DEFAULT 'public', is_published INTEGER DEFAULT 0, updated_at $time DEFAULT CURRENT_TIMESTAMP",
        'artist_catalog_photos_v181' => "id $id, workspace_id $integer, image_path VARCHAR(500), title VARCHAR(190), caption TEXT,
            alt_text VARCHAR(190), visibility VARCHAR(32) DEFAULT 'public', is_published INTEGER DEFAULT 0, sort_order INTEGER DEFAULT 0,
            updated_at $time DEFAULT CURRENT_TIMESTAMP",
        'tracks' => "id $id, workspace_id $integer, owner_user_id $integer, producer_user_id $integer, album_id $integer,
            title VARCHAR(190), album VARCHAR(190) DEFAULT '', duration VARCHAR(32) DEFAULT '', lyrics TEXT NOT NULL,
            description TEXT, credits TEXT, genre VARCHAR(120) DEFAULT '', mood VARCHAR(255) DEFAULT '', energy VARCHAR(20) DEFAULT '',
            tempo_bpm $number, keywords VARCHAR(500) DEFAULT '', audio_path VARCHAR(500) DEFAULT '', cover_path VARCHAR(500) DEFAULT '',
            visibility VARCHAR(32) DEFAULT 'public', is_published INTEGER DEFAULT 0, sort_order INTEGER DEFAULT 0,
            updated_at $time DEFAULT CURRENT_TIMESTAMP",
        'artist_catalog_tracks_v181' => "id $id, workspace_id $integer, source_track_id $integer, title VARCHAR(190),
            album VARCHAR(190) DEFAULT '', album_id $integer, description TEXT, genre VARCHAR(120) DEFAULT '', duration_seconds INTEGER,
            track_number INTEGER DEFAULT 0, audio_path VARCHAR(500) DEFAULT '', cover_path VARCHAR(500) DEFAULT '', cover_photo_id $integer,
            visibility VARCHAR(32) DEFAULT 'public', is_published INTEGER DEFAULT 0, updated_at $time DEFAULT CURRENT_TIMESTAMP",
        'playlists' => "id $integer PRIMARY KEY, owner_user_id $integer, artist_workspace_id $integer, title VARCHAR(190), description TEXT, visibility VARCHAR(32), updated_at $time",
        'playlist_tracks' => "playlist_id $integer, track_id $integer, sort_order INTEGER DEFAULT 0, added_at $time DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY(playlist_id,track_id), FOREIGN KEY(playlist_id) REFERENCES playlists(id), FOREIGN KEY(track_id) REFERENCES tracks(id)",
        'artist_workspace_playlist_tracks_v181' => "playlist_id $integer, artist_track_id $integer, sort_order INTEGER DEFAULT 0, added_at $time DEFAULT CURRENT_TIMESTAMP",
        'track_favorites' => "user_id $integer, track_id $integer, PRIMARY KEY(user_id,track_id), FOREIGN KEY(user_id) REFERENCES users(id), FOREIGN KEY(track_id) REFERENCES tracks(id)",
        'artist_workspace_track_favorites_v181' => "user_id $integer, artist_track_id $integer",
        'track_play_sessions' => "id $nativeId, session_token CHAR(64) NOT NULL UNIQUE, track_id $integer NOT NULL, user_id $integer,
            listener_hash CHAR(64) NOT NULL, device_type VARCHAR(30) NOT NULL DEFAULT 'unknown', referrer_host VARCHAR(190) NOT NULL DEFAULT '',
            source_context VARCHAR(30) NOT NULL DEFAULT 'player', duration_seconds $nativeNumber NOT NULL DEFAULT 0,
            last_position_seconds $nativeNumber NOT NULL DEFAULT 0, max_position_seconds $nativeNumber NOT NULL DEFAULT 0,
            listener_city VARCHAR(120) NOT NULL DEFAULT '', listener_region VARCHAR(120) NOT NULL DEFAULT '', listener_country VARCHAR(80) NOT NULL DEFAULT '',
            listener_latitude $number, listener_longitude $number, started_at $time NOT NULL DEFAULT CURRENT_TIMESTAMP,
            last_event_at $time NOT NULL DEFAULT CURRENT_TIMESTAMP, ended_at $time, listened_seconds $nativeNumber NOT NULL DEFAULT 0,
            completion_percent $nativeNumber NOT NULL DEFAULT 0, qualified_play INTEGER NOT NULL DEFAULT 0, completed INTEGER NOT NULL DEFAULT 0,
            FOREIGN KEY(track_id) REFERENCES tracks(id), FOREIGN KEY(user_id) REFERENCES users(id)",
        'track_play_events' => "id $nativeId, session_id $sessionId NOT NULL, event_type VARCHAR(30) NOT NULL,
            position_seconds $nativeNumber NOT NULL DEFAULT 0, listened_delta_seconds $nativeNumber NOT NULL DEFAULT 0,
            created_at $time NOT NULL DEFAULT CURRENT_TIMESTAMP, FOREIGN KEY(session_id) REFERENCES track_play_sessions(id)",
        'notifications' => "id $id, user_id $integer, type VARCHAR(50), title VARCHAR(190), body TEXT, target_url VARCHAR(500),
            source_type VARCHAR(80), source_id $integer, is_read INTEGER DEFAULT 0, created_at $time DEFAULT CURRENT_TIMESTAMP",
    ];
}

function music_listening_test_seed(): void
{
    $pdo = db();
    $suffix = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4' : '';
    foreach (music_listening_test_tables() as $name => $definition) $pdo->exec('CREATE TABLE ' . $name . ' (' . $definition . ')' . $suffix);
    $pdo->exec("INSERT INTO users VALUES (11,'customer',1,1,'Artist One'),(12,'customer',1,0,'Manager'),
        (13,'customer',1,0,'Producer'),(14,'customer',1,1,'Artist Two'),(15,'admin',1,1,'Administrator')");
    $pdo->exec("INSERT INTO artist_workspaces_v181 VALUES (1,11,'First Studio'),(2,14,'Second Studio')");
    $pdo->exec("INSERT INTO artist_team_members VALUES (11,12,'manager'),(11,13,'producer')");
    $pdo->exec("INSERT INTO artist_catalog_albums_v181 (id,workspace_id,title) VALUES (1,1,'First album'),(2,2,'Foreign album')");
    $pdo->exec("INSERT INTO tracks (id,workspace_id,owner_user_id,producer_user_id,title,album,duration,lyrics,audio_path,visibility,is_published) VALUES
        (42,1,11,13,'Fixture recording','First album','2:00','Original lyrics','/uploads/artist-music/1/legacy.wav','public',1),
        (84,2,14,NULL,'Foreign draft','','1:00','','/uploads/artist-music/2/foreign.wav','admin',0),
        (99,NULL,11,NULL,'Unmapped public recording','','2:00','','/uploads/artist-music/1/legacy.wav','public',1),
        (43,1,11,13,'MP3 recording','First album','2:00','','/uploads/artist-music/1/known.mp3','public',1)");
    $pdo->exec("INSERT INTO artist_catalog_tracks_v181 (id,workspace_id,source_track_id,title,album,album_id,duration_seconds,audio_path,visibility,is_published) VALUES
        (10,1,42,'Fixture recording','First album',1,120,'/uploads/artist-music/1/legacy.wav','public',1),
        (20,2,84,'Foreign draft','',NULL,60,'/uploads/artist-music/2/foreign.wav','admin',0),
        (11,1,43,'MP3 recording','First album',1,120,'/uploads/artist-music/1/known.mp3','public',1)");
    $pdo->exec("INSERT INTO playlists VALUES (5,11,NULL,'Personal list','','members',CURRENT_TIMESTAMP)");
    $pdo->exec('INSERT INTO playlist_tracks (playlist_id,track_id) VALUES (5,42)');
    $pdo->exec('INSERT INTO track_favorites VALUES (11,42)');
    $pdo->prepare("INSERT INTO track_play_sessions (id,session_token,listener_hash,user_id,track_id,started_at,last_position_seconds,listened_seconds,duration_seconds,qualified_play,completed)
        VALUES (7,?,?,11,42,'2026-01-02 03:04:05',27,45,120,1,0)")->execute([str_repeat('a', 64), str_repeat('b', 64)]);
    music_listening_test_audio();
}

function music_listening_test_audio(): void
{
    foreach ([1, 2] as $workspace) {
        $directory = STONEFELLOW_ROOT . '/uploads/artist-music/' . $workspace;
        if (!is_dir($directory)) mkdir($directory, 0700, true);
        // Thirty seconds of silent PCM is playable by a real browser and permits
        // pause/seek/resume checks. This is not trusted MP3 duration evidence.
        $bytes = 8000 * 2 * 30;
        $wav = pack('a4Va4a4VvvVVvva4V', 'RIFF', $bytes + 36, 'WAVE', 'fmt ', 16, 1, 1, 8000, 16000, 2, 16, 'data', $bytes)
            . str_repeat("\0", $bytes);
        file_put_contents($directory . ($workspace === 1 ? '/legacy.wav' : '/foreign.wav'), $wav);
    }
    // Existing checked-in synthetic MP3 has an ffprobe oracle, without a test
    // dependency on an encoder/decoder or private musician audio.
    $fixtures = json_decode((string)file_get_contents(__DIR__ . '/stem-mp3-metadata-v1.json'), true, 512, JSON_THROW_ON_ERROR);
    file_put_contents(STONEFELLOW_ROOT . '/uploads/artist-music/1/known.mp3', base64_decode($fixtures['fixtures'][0]['base64'], true));
}

function music_listening_test_snapshot(): array
{
    $pdo = db();
    $tables = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql'
        ? $pdo->query('SELECT table_name FROM information_schema.tables WHERE table_schema=DATABASE() ORDER BY table_name')->fetchAll(PDO::FETCH_COLUMN)
        : $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%' ORDER BY name")->fetchAll(PDO::FETCH_COLUMN);
    $snapshot = [];
    foreach ($tables as $table) {
        $rows = $pdo->query('SELECT * FROM `' . $table . '`')->fetchAll();
        usort($rows, static fn(array $a, array $b): int => strcmp(json_encode($a, JSON_THROW_ON_ERROR), json_encode($b, JSON_THROW_ON_ERROR)));
        $snapshot[$table] = $rows;
    }
    return $snapshot;
}

function music_listening_test_clean_files(string $directory): void
{
    if (!is_dir($directory)) return;
    foreach (new FilesystemIterator($directory, FilesystemIterator::SKIP_DOTS) as $entry) {
        if ($entry->isDir()) music_listening_test_clean_files($entry->getPathname());
        else unlink($entry->getPathname());
    }
    rmdir($directory);
}
