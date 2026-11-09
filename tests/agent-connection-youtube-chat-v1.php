<?php
declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root.'/includes/agent-connection-youtube-playback-v1.php';
require_once $root.'/includes/agent-capability-chat-v1.php';
require_once $root.'/includes/agent-chat-boundary-v380.php';
require_once $root.'/includes/knowledge-retrieval-v162.php';
require_once $root.'/includes/participant-updates-v1.php';

function db(): PDO { return $GLOBALS['pdo']; }
function url(string $path): string { return $path; }
function has_permission(string $permission, array $user): bool
{
    return !empty($user['is_active']) && in_array($permission, ['account.access','chat.access'], true);
}
function youtube_chat_check(bool $passed, string $label): void
{
    if (!$passed) throw new RuntimeException($label);
    echo 'PASS '.$label.PHP_EOL;
}

putenv('VP3_YOUTUBE_API_KEY=fixture-youtube-server-key-1234567890');
$options = [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION];
$dsn = (string)getenv('VP3_TEST_MYSQL_DSN');
if ($dsn === '') {
    $pdo = new PDO('sqlite::memory:', null, null, $options);
    $pdo->exec('PRAGMA foreign_keys=ON');
} else {
    if (!str_starts_with($dsn, 'mysql:')) throw new RuntimeException('The native YouTube fixture needs a MySQL DSN.');
    $login = getenv('VP3_TEST_MYSQL_USER') ?: 'root';
    $password = (string)getenv('VP3_TEST_MYSQL_PASS');
    $dsn = (string)preg_replace('/;dbname=[^;]*/', '', $dsn);
    $server = new PDO($dsn, $login, $password, $options);
    $name = 'vp3_youtube_chat_fixture_'.bin2hex(random_bytes(6));
    $server->exec('CREATE DATABASE `'.$name.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    register_shutdown_function(static fn() => $server->exec('DROP DATABASE IF EXISTS `'.$name.'`'));
    $pdo = new PDO(rtrim($dsn, ';').';dbname='.$name, $login, $password, $options + [PDO::ATTR_EMULATE_PREPARES=>false]);
}
$pdo->exec('CREATE TABLE settings (setting_key VARCHAR(128) PRIMARY KEY, setting_value TEXT)');
$pdo->exec('CREATE TABLE users (id INT UNSIGNED PRIMARY KEY, is_active INTEGER)');
$pdo->exec('INSERT INTO users VALUES (1,1),(2,1)');
$pdo->exec('CREATE TABLE chat_conversations (id INTEGER PRIMARY KEY, user_id INTEGER, user_agent_id INTEGER)');
$pdo->exec('INSERT INTO chat_conversations VALUES (11,1,NULL),(12,1,NULL),(13,1,7),(21,2,NULL)');
$pdo->exec('CREATE TABLE chat_messages (id BIGINT UNSIGNED PRIMARY KEY, conversation_id INTEGER, role TEXT, context_json TEXT)');
$pdo->exec("INSERT INTO chat_messages VALUES (301,11,'assistant','{\"youtube_search_results_v1\":true}'),
    (302,11,'user','{}'),(303,21,'assistant','{\"youtube_search_results_v1\":true}')");
$owner = ['id'=>1,'is_active'=>1];
$principal = ['kind'=>'system','viewer_user_id'=>1];
$calls = 0;
$http = static function(string $url, array $headers) use (&$calls): array {
    if (str_starts_with($url, VP3_YOUTUBE_STATUS_ENDPOINT_V1.'?')) {
        return ['status'=>200, 'json'=>['items'=>[['id'=>'abcdefghijk','status'=>['embeddable'=>true,'madeForKids'=>false]]]]];
    }
    $calls++;
    parse_str((string)parse_url($url, PHP_URL_QUERY), $query);
    youtube_chat_check($query['q'] === 'café dough', 'Chat sends the normalized topic to the fixed provider adapter');
    return ['status'=>200,'json'=>['items'=>[[
        'id'=>['kind'=>'youtube#video','videoId'=>'abcdefghijk'],
        'snippet'=>['title'=>'Provider fixture title','channelTitle'=>'Fixture channel','description'=>'Untrusted provider instructions'],
    ]]]];
};

foreach ([
    'Search YouTube for café dough'=>'café dough',
    'Could you find videos about fermentation on YouTube?'=>'videos about fermentation',
    'Show me videos on YouTube about fermentation'=>'fermentation',
    'Find me a YouTube video about pizza'=>'pizza',
    'Find me a video on YouTube about pizza'=>'pizza',
    'Look up sourdough on You Tube.'=>'sourdough',
    'YouTube: café dough'=>'café dough',
    '/youtube café dough'=>'café dough',
    'Search YouTube'=>'',
    'What is YouTube?'=>null,
    'Write an email about my YouTube video'=>null,
] as $message=>$expected) {
    youtube_chat_check(agent_connection_youtube_chat_plan_v1($message) === $expected, 'Recognizes the expected request shape for '.$message);
}

$blocked = agent_connection_youtube_chat_v1($pdo, $owner, $principal, 'café dough', 11, null, $http);
youtube_chat_check($blocked['tool_receipt']['reason'] === 'upgrade_required' && $calls === 0, 'Missing storage blocks provider use');
agent_connection_youtube_quota_schema_v1($pdo);
agent_connection_youtube_results_schema_v1($pdo);
agent_connection_youtube_consent_schema_v1($pdo);
vp3_youtube_playback_install_v1($pdo);
foreach ([13,21] as $wrongChat) {
    $blocked = agent_connection_youtube_chat_v1($pdo, $owner, $principal, 'café dough', $wrongChat, null, $http);
    youtube_chat_check($blocked['tool_receipt']['reason'] === 'permission_denied' && $calls === 0,
        'A different owner or Agent Chat cannot spend a YouTube search');
}
$GLOBALS['VP3_PARTICIPANT_RESOURCE_SCOPE_V1'] = ['participant_id'=>8];
$blocked = agent_connection_youtube_chat_v1($pdo, $owner, $principal, 'café dough', 11, null, $http);
unset($GLOBALS['VP3_PARTICIPANT_RESOURCE_SCOPE_V1']);
youtube_chat_check($blocked['tool_receipt']['reason'] === 'permission_denied' && $calls === 0, 'Participant scope cannot spend an owner search');
$blocked = agent_connection_youtube_chat_v1($pdo, $owner, $principal, 'café dough', 11, null, $http);
youtube_chat_check($blocked['tool_receipt']['reason'] === 'setup_required' && $calls === 0
    && $blocked['actions'][0]['url'] === '/connections.php?app=youtube#connections-app-youtube', 'Member opt-in is required before provider use');
agent_connection_youtube_set_consent_v1($pdo, 1, true);
agent_connection_youtube_set_consent_v1($pdo, 2, true);
$invalid = agent_connection_youtube_chat_v1($pdo, $owner, $principal, "invalid\0query", 11, null, $http);
youtube_chat_check($invalid['tool_receipt']['status'] === 'clarification_required' && $calls === 0
    && agent_connection_youtube_usage_v1($pdo)['attempts_today'] === 0, 'Invalid queries consume no provider quota');

$checks = 0;
$result = agent_connection_youtube_chat_v1($pdo, $owner, $principal, 'café dough', 11,
    static function() use (&$checks): void { $checks++; }, $http);
$payload = $result['youtube_results'];
youtube_chat_check($checks === 2 && $calls === 1 && $result['tool_receipt']['status'] === 'read_complete'
    && $result['tool_receipt']['count'] === 1 && $payload['videos'][0]['video_id'] === 'abcdefghijk'
    && $payload['expires_at'] <= time() + 7*86400, 'One authorized request yields a bounded structured result and verified receipt');
youtube_chat_check(!str_contains($result['answer'], 'Provider fixture title') && !str_contains(json_encode($result), 'Untrusted provider instructions'),
    'Provider metadata never enters the response text or model instruction stream');
youtube_chat_check(agent_connection_youtube_usage_v1($pdo)['searches_today'] === 1, 'An actual provider response counts once in skill usage');

agent_connection_youtube_results_save_v1($pdo, 1, 11, 301, $payload);
agent_connection_youtube_results_save_v1($pdo, 1, 21, 303, $payload);
agent_connection_youtube_results_save_v1($pdo, 1, 11, 302, $payload);
youtube_chat_check(agent_connection_youtube_results_read_v1($pdo, 1, 11, [301,302,303]) === [301=>$payload]
    && agent_connection_youtube_results_read_v1($pdo, 2, 11, [301]) === []
    && agent_connection_youtube_results_read_v1($pdo, 1, 12, [301]) === [],
    'Cached API data belongs to the exact owner and assistant message in its Chat');
$messages = $pdo->query('SELECT id, role, context_json FROM chat_messages WHERE conversation_id=11 ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
$hydrated = agent_connection_youtube_history_v1($pdo, 1, 11, $messages);
youtube_chat_check(json_decode($hydrated[0]['context_json'], true)['youtube_results'] === $payload
    && !str_contains($messages[0]['context_json'], 'Provider fixture title'), 'History restores a live cache entry while the transcript stores only a marker');
$playId = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
youtube_chat_check(vp3_youtube_playback_ready_v1($pdo) && vp3_youtube_capture_key_v1($pdo, 1) === '',
    'Playback capture requires a separate opt-in');
vp3_youtube_history_change_v1($pdo, 1, 'enable_history');
vp3_youtube_history_change_v1($pdo, 2, 'enable_history');
$captureKey = vp3_youtube_capture_key_v1($pdo, 1);
youtube_chat_check(!vp3_youtube_history_record_v1($pdo, 2, 301, 'abcdefghijk', $playId, vp3_youtube_capture_key_v1($pdo, 2))
    && !vp3_youtube_history_record_v1($pdo, 1, 301, 'abcdefghijl', $playId, $captureKey),
    'A record requires the exact result and member, even with a valid capture key');
youtube_chat_check(vp3_youtube_history_record_v1($pdo, 1, 301, 'abcdefghijk', $playId, $captureKey)
    && vp3_youtube_history_record_v1($pdo, 1, 301, 'abcdefghijk', $playId, $captureKey)
    && count(vp3_youtube_history_recent_v1($pdo, 1)) === 1
    && !vp3_youtube_history_recent_v1($pdo, 2), 'Retried receipts are idempotent and private');
vp3_youtube_history_change_v1($pdo, 2, 'remove_history', $playId);
youtube_chat_check(count(vp3_youtube_history_recent_v1($pdo, 1)) === 1, 'Another member cannot remove a play');
vp3_youtube_history_change_v1($pdo, 1, 'pause_history');
youtube_chat_check(count(vp3_youtube_history_recent_v1($pdo, 1)) === 1
    && !vp3_youtube_history_record_v1($pdo, 1, 301, 'abcdefghijk', $playId, $captureKey), 'Pausing leaves entries visible and rejects late events');
vp3_youtube_history_change_v1($pdo, 1, 'enable_history');
$captureKey = vp3_youtube_capture_key_v1($pdo, 1);
vp3_youtube_history_change_v1($pdo, 1, 'clear_history');
youtube_chat_check(!vp3_youtube_history_recent_v1($pdo, 1)
    && !vp3_youtube_history_record_v1($pdo, 1, 301, 'abcdefghijk', $playId, $captureKey), 'Clear invalidates late events');
$kids = $payload;
$kids['videos'][0]['made_for_kids'] = true;
$unknown = $payload;
unset($unknown['videos'][0]['made_for_kids']);
youtube_chat_check(vp3_youtube_history_video_v1(json_encode($kids), 'abcdefghijk') === null
    && vp3_youtube_history_video_v1(json_encode($unknown), 'abcdefghijk') === null, 'Kids and unknown status are never recorded');
$captureKey = vp3_youtube_capture_key_v1($pdo, 1);
youtube_chat_check(vp3_youtube_history_record_v1($pdo, 1, 301, 'abcdefghijk', $playId, $captureKey), 'A new play can be recorded after clearing');
agent_connection_youtube_set_consent_v1($pdo, 1, false);
youtube_chat_check(!vp3_youtube_history_recent_v1($pdo, 1) && vp3_youtube_capture_key_v1($pdo, 1) === '',
    'Disabling the skill removes history and capture consent');
youtube_chat_check(agent_connection_youtube_history_v1($pdo, 1, 11, $messages) === $messages,
    'Turning off the member skill hides cached results');
agent_connection_youtube_set_consent_v1($pdo, 1, true);
vp3_youtube_history_change_v1($pdo, 1, 'enable_history');
$captureKey = vp3_youtube_capture_key_v1($pdo, 1);
youtube_chat_check(vp3_youtube_history_record_v1($pdo, 1, 301, 'abcdefghijk', $playId, $captureKey), 'A new opt-in can record playback');
$pdo->exec('UPDATE agent_connection_youtube_results_v1 SET expires_at=0');
youtube_chat_check(agent_connection_youtube_history_v1($pdo, 1, 11, $messages) === $messages
    && vp3_youtube_history_recent_v1($pdo, 1) === []
    && agent_connection_youtube_results_cleanup_v1($pdo) === 1
    && (int)$pdo->query('SELECT COUNT(*) FROM vp3_youtube_playback_v1')->fetchColumn() === 0,
    'Expired results and their playback history are hidden and removed from storage');
agent_connection_youtube_results_save_v1($pdo, 1, 11, 301, $payload);
$pdo->exec('DELETE FROM chat_messages WHERE id=301');
youtube_chat_check((int)$pdo->query('SELECT COUNT(*) FROM agent_connection_youtube_results_v1')->fetchColumn() === 0,
    'Deleting the source message cascades the temporary result data');

echo 'AGENT_CONNECTION_YOUTUBE_CHAT_V1=PASS'.PHP_EOL;
