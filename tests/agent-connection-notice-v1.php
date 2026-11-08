<?php
declare(strict_types=1);

require_once dirname(__DIR__).'/includes/notifications.php';
require_once dirname(__DIR__).'/includes/agent-connection-youtube-consent-v1.php';

function db(): PDO { return $GLOBALS['pdo']; }
function url(string $path): string { return $path; }
function table_exists(string $table): bool {
    $query = db()->prepare("SELECT 1 FROM sqlite_master WHERE type='table' AND name=?");
    $query->execute([$table]);
    return (bool)$query->fetchColumn();
}
function has_permission(string $permission, array $user): bool { return !in_array($permission,$user['denied'] ?? [],true); }
function vp3_participant_scope_v1(): ?array { return $GLOBALS['participant'] ?? null; }
function chat_settings_agent_voice_enabled_v237(PDO $pdo, array $user): bool { return !empty($user['voice']); }
function notice_check(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
    echo 'PASS '.$message.PHP_EOL;
}

$pdo = new PDO('sqlite::memory:');
$pdo->exec("CREATE TABLE users (id INTEGER PRIMARY KEY);
    INSERT INTO users VALUES (1),(2);
    CREATE TABLE settings (setting_key TEXT PRIMARY KEY, setting_value TEXT);
    CREATE TABLE notifications (id INTEGER PRIMARY KEY AUTOINCREMENT,user_id INTEGER,type TEXT,title TEXT,
        body TEXT,target_url TEXT,source_type TEXT,source_id INTEGER,is_read INTEGER DEFAULT 0,created_at TEXT DEFAULT CURRENT_TIMESTAMP);
    CREATE TABLE chat_conversations (id INTEGER PRIMARY KEY,user_id INTEGER,user_agent_id INTEGER,updated_at TEXT);
    INSERT INTO chat_conversations VALUES (10,1,NULL,NULL),(11,1,7,NULL),(12,1,8,NULL),(20,2,9,NULL);
    CREATE TABLE chat_messages (id INTEGER PRIMARY KEY AUTOINCREMENT,conversation_id INTEGER,user_id INTEGER,role TEXT,message TEXT,context_json TEXT);
    INSERT INTO chat_messages (conversation_id,role,message,context_json) VALUES (11,'assistant','legacy','malformed JSON');");
agent_connection_youtube_consent_schema_v1($pdo);
putenv('VP3_YOUTUBE_API_KEY=fixture_youtube_key_not_live');

$owner = ['id'=>1,'voice'=>true];
$other = ['id'=>2,'voice'=>true];
agent_connection_youtube_set_consent_v1($pdo,1,true);
agent_connection_youtube_set_consent_v1($pdo,1,true);
notice_check((int)$pdo->query('SELECT COUNT(*) FROM notifications')->fetchColumn()===1,'repeated enable records one canonical owner notification');
$first = $pdo->query('SELECT * FROM notifications')->fetch(PDO::FETCH_ASSOC);
notice_check(agent_connection_notice_after_v1($pdo,$owner,0)['items']===
    [['id'=>(int)$first['id'],'type'=>'youtube_enabled','source_type'=>'agent_connection_activation']]
    && agent_connection_notice_after_v1($pdo,$other,0)['items']===[], 'only the owner sees the current activation in Chat');
$participant = ['id'=>7];
notice_check(agent_connection_notice_after_v1($pdo,$owner,0)['items']===[], 'a participant session cannot discover owner notices');
unset($participant);
notice_check(agent_connection_notice_after_v1($pdo,$owner+['denied'=>['account.access']],0)['items']===[], 'the owner needs current account access');

$present = static fn(array $user,array $note,int $conversation,int $agent): array =>
    agent_commerce_atomic_pa2(db(), static fn(): array => agent_connection_notice_present_v1(db(),$user,$note,$conversation,$agent));
notice_check(!$present($other,$first,20,9)['handled'],'the activation ID does not let another account present it');
try {
    $present($owner,$first,12,7);
    throw new LogicException('A different Agent Chat must fail validation.');
} catch (DomainException) {}
$result = $present($owner,$first,11,7);
$message = $pdo->query('SELECT * FROM chat_messages ORDER BY id DESC LIMIT 1')->fetch(PDO::FETCH_ASSOC);
notice_check($result['handled'] && !$result['duplicate'] && $result['voice_allowed'] && $result['preserve_unread']
    && (int)$message['conversation_id']===11 && str_contains($message['message'],'public YouTube search')
    && str_contains($message['message'],'no access to your YouTube account')
    && str_contains($message['message'],'watch it in Chat')
    && !str_contains(json_encode([$result,$message]),'fixture_youtube_key_not_live'),
    'YouTube stores safe permission and skill guidance and a concise spoken status');
$repeat = $present($owner,$first,11,7);
notice_check($repeat['handled'] && $repeat['duplicate'] && !$repeat['voice_allowed'] && $repeat['message_id']===$result['message_id']
    && !$present($owner,$first,12,8)['handled'] && !$present($owner,$first,10,0)['handled']
    && agent_connection_notice_after_v1($pdo,$owner,0)['items']===[]
    && (int)$pdo->query('SELECT is_read FROM notifications')->fetchColumn()===0,
    'reload and competing Agent Chats do not repost or repeat the announcement, and the bell remains unread');

agent_connection_youtube_set_consent_v1($pdo,1,false);
agent_connection_youtube_set_consent_v1($pdo,1,true);
$second = $pdo->query('SELECT * FROM notifications ORDER BY id DESC LIMIT 1')->fetch(PDO::FETCH_ASSOC);
notice_check((int)$second['id']>(int)$first['id'],'re-enabling creates a distinct activation even in the same second');
agent_connection_youtube_set_consent_v1($pdo,1,false);
notice_check(agent_connection_notice_after_v1($pdo,$owner,0)['items']===[] && !$present($owner,$second,11,7)['handled'],
    'disabling before delivery stops a stale activation from presenting');
agent_connection_youtube_set_consent_v1($pdo,1,true);
$third = $pdo->query('SELECT * FROM notifications ORDER BY id DESC LIMIT 1')->fetch(PDO::FETCH_ASSOC);
$result = $present(['id'=>1,'voice'=>false],$third,10,0);
notice_check(!$present($owner,$second,10,0)['handled'] && $result['handled'] && !$result['voice_allowed'],
    'the latest activation replaces a pending stale one and muted users still get the Canvas notice');

$gmail = agent_connection_notice_copy_v1('gmail_connected',['read'=>true,'send'=>true,'followups'=>true]);
notice_check(str_contains($gmail['message'],'Google account identity') && str_contains($gmail['message'],'Gmail read and send access')
    && str_contains($gmail['message'],'typed Chat') && str_contains($gmail['message'],'approve it before it sends')
    && str_contains($gmail['message'],'Notify me when I get an email from') && !str_contains($gmail['speech'],'@'),
    'Gmail explains both Google authorization and enabled VP3 controls without narrating mailbox details');
$limited = agent_connection_notice_copy_v1('gmail_connected',['read'=>false,'send'=>false,'followups'=>true]);
notice_check(substr_count($limited['message'],'Off.')===3 && !str_contains($limited['message'],'Try one in Chat'),
    'Gmail onboarding offers only skills enabled by effective local policy');
echo 'AGENT_CONNECTION_NOTICE_V1=PASS'.PHP_EOL;
