<?php
declare(strict_types=1);

require_once dirname(__DIR__).'/includes/agent-connection-youtube-playback-v1.php';

function db(): PDO { return $GLOBALS['pdo']; }
function playlist_check(bool $passed,string $label): void
{
    if (!$passed) throw new RuntimeException($label);
    echo 'PASS '.$label.PHP_EOL;
}

$options = [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION];
$dsn = (string)getenv('VP3_TEST_MYSQL_DSN');
if ($dsn === '') {
    $pdo = new PDO('sqlite::memory:',null,null,$options);
    $pdo->exec('PRAGMA foreign_keys=ON');
} else {
    if (!str_starts_with($dsn,'mysql:')) throw new RuntimeException('Use the native MySQL fixture DSN.');
    $login = getenv('VP3_TEST_MYSQL_USER') ?: 'root';
    $password = (string)getenv('VP3_TEST_MYSQL_PASS');
    $dsn = (string)preg_replace('/;dbname=[^;]*/','',$dsn);
    $server = new PDO($dsn,$login,$password,$options);
    $name = 'vp3_youtube_playlists_'.bin2hex(random_bytes(6));
    $server->exec('CREATE DATABASE `'.$name.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    register_shutdown_function(static fn() => $server->exec('DROP DATABASE IF EXISTS `'.$name.'`'));
    $pdo = new PDO(rtrim($dsn,';').';dbname='.$name,$login,$password,$options+[PDO::ATTR_EMULATE_PREPARES=>false]);
}
$pdo->exec('CREATE TABLE settings (setting_key VARCHAR(128) PRIMARY KEY, setting_value TEXT)');
$pdo->exec('CREATE TABLE users (id INT UNSIGNED PRIMARY KEY)');
$pdo->exec('INSERT INTO users VALUES (1),(2)');
$pdo->exec('CREATE TABLE chat_conversations (id INTEGER PRIMARY KEY, user_id INTEGER, user_agent_id INTEGER, title TEXT)');
$pdo->exec("INSERT INTO chat_conversations VALUES (41,1,NULL,'First Chat'),(42,1,NULL,'New Chat'),(55,2,NULL,'Private Chat')");
$pdo->exec('CREATE TABLE chat_messages (id BIGINT UNSIGNED PRIMARY KEY, conversation_id INTEGER, role VARCHAR(16))');
$pdo->exec("INSERT INTO chat_messages VALUES (501,41,'assistant'),(502,41,'assistant')");
agent_connection_youtube_consent_schema_v1($pdo);
agent_connection_youtube_results_schema_v1($pdo);
vp3_youtube_playback_install_v1($pdo);
vp3_youtube_playback_install_v1($pdo);
playlist_check(vp3_youtube_playlists_ready_v1($pdo) && vp3_youtube_playhead_ready_v1($pdo),'Install and upgrade create both playback features idempotently');

$consent = $pdo->prepare('INSERT INTO agent_connection_youtube_consent_v1 VALUES (?,?,?)');
$consent->execute([1,VP3_YOUTUBE_CONSENT_POLICY_V1,time()]);
$consent->execute([2,VP3_YOUTUBE_CONSENT_POLICY_V1,time()]);
vp3_youtube_history_change_v1($pdo,1,'enable_history');
vp3_youtube_history_change_v1($pdo,2,'enable_history');
$capture = vp3_youtube_capture_key_v1($pdo,1);
$first = 'abcdefghijk';
$second = 'zyxwvutsrqp';
$now = time();
$video = static fn(string $id,string $title): array => ['video_id'=>$id,'title'=>$title,'channel_title'=>'Fixture channel',
    'thumbnail_url'=>'','made_for_kids'=>false];
$videos = [$video($first,'Original title'),$video($second,'Second video')];
agent_connection_youtube_results_save_v1($pdo,1,41,501,['query'=>'Video workshop','owner_topic'=>'Video workshop',
    'suggestion_kind'=>'playlist','expires_at'=>$now+100,'videos'=>$videos]);
agent_connection_youtube_results_save_v1($pdo,1,41,502,['query'=>'Separate video','expires_at'=>$now+100,'videos'=>$videos]);
$play = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
playlist_check(vp3_youtube_history_record_v1($pdo,1,502,$first,$play,$capture)
    && vp3_youtube_position_record_v1($pdo,1,502,$first,$play,$capture,12045,2)
    && vp3_youtube_position_record_v1($pdo,1,502,$first,$play,$capture,6000,1)
    && vp3_youtube_position_read_v1($pdo,1,$first,$capture) === 12045
    && !vp3_youtube_watch_research_enabled_v1($pdo,1),
    'Exact playhead is independent of watch-time consent, keeps milliseconds, and ignores a late write');
playlist_check(vp3_youtube_position_read_v1($pdo,2,$first,vp3_youtube_capture_key_v1($pdo,2)) === null
    && !vp3_youtube_position_record_v1($pdo,1,502,$second,$play,$capture,2,3)
    && !vp3_youtube_position_record_v1($pdo,1,502,$first,$play,str_repeat('f',32),2,3),
    'Owner, capture epoch and exact play provenance scope a saved playhead');

$playlist = vp3_youtube_playlist_save_v1($pdo,1,501);
playlist_check(is_string($playlist) && vp3_youtube_playlist_save_v1($pdo,1,501) === $playlist
    && vp3_youtube_playlist_save_v1($pdo,2,501) === null && vp3_youtube_playlist_save_v1($pdo,1,502) === null
    && vp3_youtube_playlists_list_v1($pdo,2) === [] && vp3_youtube_playlist_open_v1($pdo,2,$playlist) === null,
    'Saving is idempotent, owner-private, and limited to an exact playlist result');
playlist_check(vp3_youtube_playlist_open_v1($pdo,1,$playlist)['videos'] === $videos
    && vp3_youtube_playlists_list_v1($pdo,1)[0]['title'] === 'Video workshop',
    'A fresh saved playlist opens in original order without a new provider call');

$pdo->exec('DELETE FROM chat_messages WHERE id=501');
$pdo->exec('UPDATE vp3_youtube_saved_playlists_v1 SET cached_until=1');
putenv('VP3_YOUTUBE_API_KEY=fixture-youtube-playlist-key-123456789');
$refreshes = 0;
$refresh = static function(string $url,array $headers) use (&$refreshes,$first,$second): array {
    ++$refreshes;
    parse_str((string)parse_url($url,PHP_URL_QUERY),$query);
    playlist_check(str_starts_with($url,VP3_YOUTUBE_STATUS_ENDPOINT_V1.'?') && $query['id'] === "$first,$second"
        && !isset($query['key']), 'Refresh revalidates the exact saved IDs with a server-only credential');
    $item = static fn(string $id,string $title): array => ['id'=>$id,'snippet'=>['title'=>$title,'channelTitle'=>'Fixture'],
        'status'=>['privacyStatus'=>'public','embeddable'=>true,'madeForKids'=>false]];
    return ['status'=>200,'json'=>['items'=>[$item($second,'Second refreshed'),$item($first,'First refreshed')]]];
};
$refreshed = vp3_youtube_playlist_open_v1($pdo,1,$playlist,$refresh);
playlist_check($refreshes === 1 && array_column($refreshed['videos'],'video_id') === [$first,$second]
    && $refreshed['videos'][0]['title'] === 'First refreshed',
    'Saved playlists survive a deleted source Chat and expired metadata while retaining exact order');

$playlistPlay = 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb';
playlist_check(vp3_youtube_playlist_play_v1($pdo,1,$playlist,$second,$playlistPlay,$capture,'record')
    && !vp3_youtube_playlist_play_v1($pdo,1,$playlist,$first,$playlistPlay,$capture,'record')
    && vp3_youtube_playlist_play_v1($pdo,1,$playlist,$second,$playlistPlay,$capture,'position_save',8732,1)
    && vp3_youtube_position_read_v1($pdo,1,$second,$capture) === 8732,
    'Saved playlist playback shares the owner history and resumes its exact selected video');
$history = array_column(vp3_youtube_history_recent_v1($pdo,1),null,'play_id');
playlist_check($history[$playlistPlay]['playlist_id'] === $playlist && $history[$playlistPlay]['resume_ms'] === 8732
    && $history[$playlistPlay]['conversation_id'] === 0 && !vp3_youtube_history_recent_v1($pdo,2),
    'History keeps playable saved-playlist provenance even after the source Chat is gone');
vp3_youtube_history_change_v1($pdo,1,'remove_history',$playlistPlay);
playlist_check(vp3_youtube_position_read_v1($pdo,1,$second,vp3_youtube_capture_key_v1($pdo,1)) === null
    && !vp3_youtube_playlist_play_v1($pdo,1,$playlist,$second,$playlistPlay,$capture,'record'),
    'Removing an entry also removes its playhead and invalidates late events');

$pdo->exec('UPDATE vp3_youtube_saved_playlists_v1 SET refresh_at=1');
playlist_check(vp3_youtube_playlists_maintain_v1($pdo,$refresh) === 1 && $refreshes === 2,
    'Worker refreshes idle playlist references before expiry');
$pdo->exec('UPDATE vp3_youtube_saved_playlists_v1 SET cached_until=1,references_until=1');
playlist_check(vp3_youtube_playlists_maintain_v1($pdo,$refresh) === 0 && $refreshes === 2
    && $pdo->query('SELECT ordered_ids_json FROM vp3_youtube_saved_playlists_v1')->fetchColumn() === '[]'
    && $pdo->query('SELECT videos_json FROM vp3_youtube_saved_playlists_v1')->fetchColumn() === '[]'
    && vp3_youtube_playlist_open_v1($pdo,1,$playlist,$refresh)['videos'] === [],
    'Expired provider data is cleared without replaying stale references');
agent_connection_youtube_set_consent_v1($pdo,1,false);
playlist_check((int)$pdo->query('SELECT COUNT(*) FROM vp3_youtube_saved_playlists_v1')->fetchColumn() === 0
    && (int)$pdo->query('SELECT COUNT(*) FROM vp3_youtube_playhead_v1')->fetchColumn() === 0,
    'Revoking YouTube consent removes playlists and playheads');
echo "YOUTUBE_PLAYLISTS_V1=PASS\n";
