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
$pdo->exec('CREATE TABLE chat_conversations (id INTEGER PRIMARY KEY, user_id INTEGER, user_agent_id INTEGER, title TEXT)');
$pdo->exec("INSERT INTO chat_conversations VALUES (11,1,NULL,'Current music'),(12,1,NULL,'Earlier music'),(13,1,7,'Another Agent'),(21,2,NULL,'Another owner')");
$pdo->exec('CREATE TABLE chat_messages (id BIGINT UNSIGNED PRIMARY KEY, conversation_id INTEGER, role TEXT, context_json TEXT, created_at DATETIME DEFAULT CURRENT_TIMESTAMP)');
$pdo->exec("INSERT INTO chat_messages (id,conversation_id,role,context_json) VALUES (301,11,'assistant','{\"youtube_search_results_v1\":true}'),
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
    'Show me my YouTube watch history'=>null,
    '/youtube history'=>null,
    'Write an email about my YouTube video'=>null,
] as $message=>$expected) {
    youtube_chat_check(agent_connection_youtube_chat_plan_v1($message) === $expected, 'Recognizes the expected request shape for '.$message);
}

foreach ([
    "Let's begin in the show What have I listened to on YouTube",
    'What did I play on YouTube earlier?',
    'Could you tell me what I’ve been listening to on You Tube recently?',
    'Show me my YouTube watch history',
    '/youtube history',
] as $message) {
    youtube_chat_check(agent_connection_youtube_history_request_v1($message)
        && agent_connection_youtube_chat_plan_v1($message) === null, 'Private playback intent avoids public search for '.$message);
}
foreach (['What have I listened to on Stonefellow?', 'Search YouTube for the history of pizza', 'Write an email asking what I watched on YouTube'] as $message) {
    youtube_chat_check(!agent_connection_youtube_history_request_v1($message), 'Unrelated turn retains its route for '.$message);
}
foreach ([
    'It was a song in the YouTube playlist you returned in search results'=>'playlist',
    'What was the third song in the YouTube playlist?'=>'playlist',
    'Which songs were in the YouTube playlist?'=>'playlist',
    'What was that song in the playlist you showed me on YouTube?'=>'playlist',
    'Could you pull up those YouTube search results again?'=>'search',
    'Show me the earlier YouTube results.'=>'search',
] as $message=>$expected) {
    youtube_chat_check((agent_connection_youtube_followup_plan_v1($message)['kind'] ?? null) === $expected
        && agent_connection_youtube_chat_plan_v1($message) === null, 'Earlier results route to cached follow-up for '.$message);
}
foreach ([
    'Search YouTube for the latest playlist by Abba', 'Create a YouTube playlist for the drive',
    'Show me a YouTube playlist for last year', 'Write an email about the YouTube playlist you sent',
    'What did I play on YouTube earlier?', 'Which songs were in the Spotify playlist you shared?',
] as $message) {
    youtube_chat_check(agent_connection_youtube_followup_plan_v1($message) === null, 'Earlier results do not intercept '.$message);
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
youtube_chat_check(agent_connection_youtube_results_read_v1($pdo, 1, 0, 11, [301,302,303]) === [301=>$payload]
    && agent_connection_youtube_results_read_v1($pdo, 2, 0, 11, [301]) === []
    && agent_connection_youtube_results_read_v1($pdo, 1, 0, 12, [301]) === [],
    'Cached API data belongs to the exact owner and assistant message in its Chat');
$messages = $pdo->query('SELECT id, role, context_json FROM chat_messages WHERE conversation_id=11 ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
$hydrated = agent_connection_youtube_history_v1($pdo, 1, 0, 11, $messages);
youtube_chat_check(json_decode($hydrated[0]['context_json'], true)['youtube_results'] === $payload
    && !str_contains($messages[0]['context_json'], 'Provider fixture title'), 'History restores a live cache entry while the transcript stores only a marker');
$playId = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
youtube_chat_check(vp3_youtube_playback_ready_v1($pdo) && vp3_youtube_capture_key_v1($pdo, 1) === '',
    'Playback capture requires a separate opt-in');
$noHistory = agent_connection_youtube_history_chat_v1($pdo, $owner, $principal, 11);
youtube_chat_check($noHistory['tool_receipt']['reason'] === 'setup_required' && $noHistory['youtube_results'] === null
    && $noHistory['actions'][0]['label'] === 'YouTube settings',
    'A search result does not count as a play and the owner can open history settings');
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
$historyReply = agent_connection_youtube_history_chat_v1($pdo, $owner, $principal, 11);
youtube_chat_check($historyReply['tool_receipt']['capability'] === 'youtube.history'
    && $historyReply['tool_receipt']['status'] === 'read_complete' && $historyReply['tool_receipt']['count'] === 1
    && $historyReply['youtube_results']['history'] === true
    && $historyReply['youtube_results']['videos'][0]['play_id'] === $playId
    && !str_contains($historyReply['answer'], 'Provider fixture title') && $calls === 1,
    'The Chat tool reads actual recorded playback into a transient card without fetching or narrating provider data');
$blockedHistory = agent_connection_youtube_history_chat_v1($pdo, $owner, $principal, 21);
youtube_chat_check($blockedHistory['tool_receipt']['reason'] === 'permission_denied' && $blockedHistory['youtube_results'] === null,
    'A different member’s Chat cannot read private playback');
$GLOBALS['VP3_PARTICIPANT_RESOURCE_SCOPE_V1'] = ['participant_id'=>8];
$blockedHistory = agent_connection_youtube_history_chat_v1($pdo, $owner, $principal, 11);
unset($GLOBALS['VP3_PARTICIPANT_RESOURCE_SCOPE_V1']);
youtube_chat_check($blockedHistory['tool_receipt']['reason'] === 'permission_denied' && $blockedHistory['youtube_results'] === null,
    'A participant session cannot read owner playback');
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
$clearedHistory = agent_connection_youtube_history_chat_v1($pdo, $owner, $principal, 11);
youtube_chat_check($clearedHistory['tool_receipt']['status'] === 'no_matches' && $clearedHistory['youtube_results'] === null,
    'The Chat reader does not resurface cleared history');
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
youtube_chat_check(agent_connection_youtube_history_v1($pdo, 1, 0, 11, $messages) === $messages,
    'Turning off the member skill hides cached results');
agent_connection_youtube_set_consent_v1($pdo, 1, true);
vp3_youtube_history_change_v1($pdo, 1, 'enable_history');
$captureKey = vp3_youtube_capture_key_v1($pdo, 1);
youtube_chat_check(vp3_youtube_history_record_v1($pdo, 1, 301, 'abcdefghijk', $playId, $captureKey), 'A new opt-in can record playback');
$pdo->exec('UPDATE agent_connection_youtube_results_v1 SET expires_at=0');
youtube_chat_check(agent_connection_youtube_history_v1($pdo, 1, 0, 11, $messages) === $messages
    && vp3_youtube_history_recent_v1($pdo, 1) === []
    && agent_connection_youtube_results_cleanup_v1($pdo) === 1
    && (int)$pdo->query('SELECT COUNT(*) FROM vp3_youtube_playback_v1')->fetchColumn() === 0,
    'Expired results and their playback history are hidden and removed from storage');
agent_connection_youtube_results_save_v1($pdo, 1, 11, 301, $payload);
$pdo->exec('DELETE FROM chat_messages WHERE id=301');
youtube_chat_check((int)$pdo->query('SELECT COUNT(*) FROM agent_connection_youtube_results_v1')->fetchColumn() === 0,
    'Deleting the source message cascades the temporary result data');

vp3_youtube_picks_install_v1($pdo);
youtube_chat_check(vp3_youtube_suggestion_plan_v1('Recommend three mellow songs on YouTube') === ['kind'=>'music','topic'=>'mellow']
    && vp3_youtube_suggestion_plan_v1('Recommend a camera on YouTube') === null
    && agent_connection_youtube_chat_plan_v1('/youtube picks') === '', 'Song suggestions route without taking unrelated requests');
youtube_chat_check(vp3_youtube_suggestion_plan_v1('Suggest indie artists on YouTube') === ['kind'=>'artists','topic'=>'indie']
    && vp3_youtube_suggestion_plan_v1('Find artists similar to Stonefellow on YouTube') === ['kind'=>'similar','topic'=>'Stonefellow']
    && agent_connection_youtube_chat_plan_v1('Create a YouTube playlist for the drive') === 'the drive'
    && vp3_youtube_suggestion_plan_v1('Create a playlist of quiet jazz on YouTube') === ['kind'=>'playlist','topic'=>'quiet jazz'],
    'Artist discovery, related artists, and playlist drafts parse as distinct music requests');
vp3_youtube_picks_save_v1($pdo,1,'ambient jazz',true);
vp3_youtube_picks_save_v1($pdo,1,'quiet jazz',null);
youtube_chat_check((bool)vp3_youtube_picks_settings_v1($pdo,1)['proactive']
    && vp3_youtube_picks_settings_v1($pdo,2)['taste'] === '', 'Saving taste preserves opt-in and stays member-private');
$clock = time();
youtube_chat_check(!vp3_youtube_reserve_offer_v1($pdo,2,$clock)
    && vp3_youtube_reserve_offer_v1($pdo,1,$clock)
    && !vp3_youtube_reserve_offer_v1($pdo,1,$clock+1), 'Offers require an opt-in and reserve one account-wide cooldown');
$seenQuery = '';
$musicHttp = static function (string $url, array $headers) use ($http, &$seenQuery): array {
    if (str_starts_with($url, VP3_YOUTUBE_SEARCH_ENDPOINT_V1.'?')) {
        parse_str((string)parse_url($url,PHP_URL_QUERY), $params);
        $seenQuery = $params['q'];
        $url = VP3_YOUTUBE_SEARCH_ENDPOINT_V1.'?'.http_build_query(['q'=>'café dough']);
    }
    return $http($url,$headers);
};
$picks = agent_connection_youtube_chat_v1($pdo,$owner,$principal,'',11,null,$musicHttp,'music');
youtube_chat_check($seenQuery === 'quiet jazz music' && ($picks['youtube_results']['suggestion_reason'] ?? '') !== ''
    && !str_contains($picks['answer'], 'quiet jazz'), 'Generic suggestions use explicit taste and keep it out of the assistant transcript');
$similar = agent_connection_youtube_chat_v1($pdo,$owner,$principal,'Stonefellow',11,null,$musicHttp,'similar');
youtube_chat_check($seenQuery === 'music artists similar to Stonefellow' && ($similar['youtube_results']['suggestion_kind'] ?? '') === 'similar'
    && !empty($similar['youtube_results']['videos']), 'Similar artist discovery returns real search cards from the public YouTube tool');
$playlist = agent_connection_youtube_chat_v1($pdo,$owner,$principal,'quiet jazz',11,null,$musicHttp,'playlist');
youtube_chat_check(($playlist['youtube_results']['suggestion_kind'] ?? '') === 'playlist' && !empty($playlist['youtube_results']['videos'])
    && str_contains($playlist['answer'],'playlist draft'), 'A playlist request creates a playable private Chat draft through read-only search');

$pdo->exec("INSERT INTO chat_messages (id,conversation_id,role,context_json) VALUES
    (401,11,'assistant','{\"youtube_search_results_v1\":true}'),
    (402,11,'assistant','{\"youtube_search_results_v1\":true}'),
    (403,12,'assistant','{\"youtube_search_results_v1\":true}'),
    (404,21,'assistant','{\"youtube_search_results_v1\":true}'),
    (405,11,'assistant','{\"youtube_results_ref_v1\":404}'),
    (406,11,'assistant','{\"youtube_results_ref_v1\":403}'),
    (407,11,'assistant','{\"youtube_results_ref_v1\":401}'),
    (408,13,'assistant','{\"youtube_search_results_v1\":true}'),
    (409,11,'assistant','{\"youtube_results_ref_v1\":408}')");
$originalPlaylist = $playlist['youtube_results'];
$otherPlaylist = array_replace($originalPlaylist,['query'=>'Other music','taste_topic'=>'Other music']);
agent_connection_youtube_results_save_v1($pdo,1,11,401,$originalPlaylist);
agent_connection_youtube_results_save_v1($pdo,1,11,402,$payload);
agent_connection_youtube_results_save_v1($pdo,1,12,403,$otherPlaylist);
agent_connection_youtube_results_save_v1($pdo,2,21,404,$originalPlaylist);
agent_connection_youtube_results_save_v1($pdo,1,13,408,$originalPlaylist);
$pdo->prepare('UPDATE chat_messages SET created_at=? WHERE id=401')->execute(['2026-11-07 12:00:00']);
$pdo->prepare('UPDATE chat_messages SET created_at=? WHERE id=403')->execute(['2026-11-08 12:00:00']);
$priorUsage = agent_connection_youtube_usage_v1($pdo);
$priorCalls = $calls;
putenv('VP3_YOUTUBE_API_KEY');

$playlistPlan = agent_connection_youtube_followup_plan_v1('It was a song in the YouTube playlist you returned in search results');
$searchPlan = agent_connection_youtube_followup_plan_v1('Show me the earlier YouTube results.');
$sameChat = agent_connection_youtube_followup_plan_v1('Show me the earlier YouTube playlist in this chat.');
$recalled = agent_connection_youtube_followup_chat_v1($pdo,$owner,$principal,$playlistPlan,11);
youtube_chat_check($recalled['tool_receipt']['capability'] === 'youtube.results'
    && $recalled['tool_receipt']['status'] === 'read_complete' && $recalled['youtube_source_message_id'] === 403
    && $recalled['youtube_results'] === $otherPlaylist + ['source_message_id'=>403]
    && str_contains($recalled['answer'],'Which song') && !str_contains($recalled['answer'],'Provider fixture title'),
    'A vague song follow-up reuses the most recent matching playlist across that Agent’s private chats');
$recentSearch = agent_connection_youtube_followup_chat_v1($pdo,$owner,$principal,$searchPlan,11);
$scoped = agent_connection_youtube_followup_chat_v1($pdo,$owner,$principal,$sameChat,11);
youtube_chat_check($recentSearch['youtube_source_message_id'] === 403
    && $scoped['youtube_source_message_id'] === 401 && $scoped['youtube_results'] === $originalPlaylist + ['source_message_id'=>401],
    'Only an explicit current Chat reference restricts the search to that Chat');
foreach (['Show the YouTube playlist called "quiet jazz" you returned.',
    'Show the YouTube playlist you returned on 2026-11-07.',
    'Show the YouTube playlist you returned in the chat "Current music".'] as $specific) {
    $specificPlan = agent_connection_youtube_followup_plan_v1($specific);
    $specificResult = agent_connection_youtube_followup_chat_v1($pdo,$owner,$principal,$specificPlan,11);
    youtube_chat_check($specificResult['youtube_source_message_id'] === 401, 'An explicit title, date, or Chat name narrows History before recency');
}
$exact = agent_connection_youtube_followup_chat_v1($pdo,$owner,$principal,$searchPlan,11,null,401);
youtube_chat_check($exact['youtube_source_message_id'] === 401 && $exact['youtube_results'] === $scoped['youtube_results'],
    'Exact replay keeps its original source instead of moving to newer results');
foreach ([0,404,408] as $wrongSource) {
    $missing = agent_connection_youtube_followup_chat_v1($pdo,$owner,$principal,$playlistPlan,11,null,$wrongSource);
    youtube_chat_check($missing['youtube_results'] === null && $missing['tool_receipt']['status'] === 'no_matches',
        'Exact replay does not substitute a result for an absent or foreign source');
}
youtube_chat_check(agent_connection_youtube_followup_chat_v1($pdo,$owner,$principal,$sameChat,11,null,403)['youtube_results'] === null,
    'Exact replay respects an explicit current-Chat restriction');
foreach ([13,21] as $wrongChat) {
    $missing = agent_connection_youtube_followup_chat_v1($pdo,$owner,$principal,$playlistPlan,$wrongChat);
    youtube_chat_check($missing['youtube_results'] === null && $missing['tool_receipt']['reason'] === 'permission_denied',
        'Earlier search results remain scoped to the signed-in owner and selected Agent');
}
$references = $pdo->query('SELECT id,role,context_json FROM chat_messages WHERE conversation_id=11 AND id BETWEEN 405 AND 409 ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
$restored = agent_connection_youtube_history_v1($pdo,1,0,11,$references);
youtube_chat_check($restored[0] === $references[0]
    && json_decode($restored[1]['context_json'],true)['youtube_results'] === $recalled['youtube_results']
    && json_decode($restored[2]['context_json'],true)['youtube_results'] === $scoped['youtube_results']
    && $restored[3] === $references[3],
    'Reload restores same-Agent cross-Chat references and rejects another owner or Agent');

$GLOBALS['VP3_PARTICIPANT_RESOURCE_SCOPE_V1'] = ['participant_id'=>8];
$blockedFollowup = agent_connection_youtube_followup_chat_v1($pdo,$owner,$principal,$playlistPlan,11);
$blockedRestore = agent_connection_youtube_history_v1($pdo,1,0,11,$references);
unset($GLOBALS['VP3_PARTICIPANT_RESOURCE_SCOPE_V1']);
youtube_chat_check($blockedFollowup['tool_receipt']['reason'] === 'permission_denied' && $blockedRestore === $references,
    'Participant scope cannot use an owner follow-up or restore its cached videos');

$pdo->prepare('UPDATE agent_connection_youtube_results_v1 SET expires_at=? WHERE message_id IN (401,403)')->execute([time()-1]);
$expired = agent_connection_youtube_followup_chat_v1($pdo,$owner,$principal,$playlistPlan,11,null,403);
$fallback = agent_connection_youtube_followup_chat_v1($pdo,$owner,$principal,$playlistPlan,11);
youtube_chat_check($expired['youtube_results'] === null && $fallback['youtube_source_message_id'] === 402
    && agent_connection_youtube_history_v1($pdo,1,0,11,$references) === $references,
    'An expired reference remains expired, while a new vague follow-up can use an earlier ordinary search');
agent_connection_youtube_set_consent_v1($pdo,1,false);
$disabledFollowup = agent_connection_youtube_followup_chat_v1($pdo,$owner,$principal,$searchPlan,11);
youtube_chat_check($disabledFollowup['tool_receipt']['reason'] === 'setup_required' && $disabledFollowup['youtube_results'] === null,
    'Saved search follow-up honors YouTube opt-out');
youtube_chat_check($calls === $priorCalls && agent_connection_youtube_usage_v1($pdo) === $priorUsage,
    'Recalling results does not spend provider quota or create a search');
echo 'AGENT_CONNECTION_YOUTUBE_CHAT_V1=PASS'.PHP_EOL;
