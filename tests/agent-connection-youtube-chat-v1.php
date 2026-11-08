<?php
declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root.'/includes/agent-connection-youtube-chat-v1.php';
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
$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$pdo->exec('PRAGMA foreign_keys=ON');
$pdo->exec('CREATE TABLE settings (setting_key TEXT PRIMARY KEY, setting_value TEXT)');
$pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, is_active INTEGER)');
$pdo->exec('INSERT INTO users VALUES (1,1),(2,1)');
$pdo->exec('CREATE TABLE chat_conversations (id INTEGER PRIMARY KEY, user_id INTEGER, user_agent_id INTEGER)');
$pdo->exec('INSERT INTO chat_conversations VALUES (11,1,NULL),(12,1,NULL),(13,1,7),(21,2,NULL)');
$pdo->exec('CREATE TABLE chat_messages (id INTEGER PRIMARY KEY, conversation_id INTEGER, role TEXT, context_json TEXT)');
$pdo->exec("INSERT INTO chat_messages VALUES (301,11,'assistant','{\"youtube_search_results_v1\":true}'),
    (302,11,'user','{}'),(303,21,'assistant','{\"youtube_search_results_v1\":true}')");
$owner = ['id'=>1,'is_active'=>1];
$principal = ['kind'=>'system','viewer_user_id'=>1];
$calls = 0;
$http = static function(string $url, array $headers) use (&$calls): array {
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
agent_connection_youtube_set_consent_v1($pdo, 1, false);
youtube_chat_check(agent_connection_youtube_history_v1($pdo, 1, 11, $messages) === $messages,
    'Turning off the member skill hides cached results');
agent_connection_youtube_set_consent_v1($pdo, 1, true);
$pdo->exec('UPDATE agent_connection_youtube_results_v1 SET expires_at=0');
youtube_chat_check(agent_connection_youtube_history_v1($pdo, 1, 11, $messages) === $messages
    && agent_connection_youtube_results_cleanup_v1($pdo) === 1, 'Expired results are hidden and removed from storage');
agent_connection_youtube_results_save_v1($pdo, 1, 11, 301, $payload);
$pdo->exec('DELETE FROM chat_messages WHERE id=301');
youtube_chat_check((int)$pdo->query('SELECT COUNT(*) FROM agent_connection_youtube_results_v1')->fetchColumn() === 0,
    'Deleting the source message cascades the temporary result data');

echo 'AGENT_CONNECTION_YOUTUBE_CHAT_V1=PASS'.PHP_EOL;
