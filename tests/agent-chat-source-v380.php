<?php
declare(strict_types=1);

require_once dirname(__DIR__).'/includes/agent-chat-boundary-v380.php';

$dsn = getenv('VP3_TEST_MYSQL_DSN');
$pdo = $dsn
    ? new PDO($dsn, getenv('VP3_TEST_MYSQL_USER'), getenv('VP3_TEST_MYSQL_PASS'), [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION])
    : new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
if ($dsn) {
    $database = 'activity_source_'.bin2hex(random_bytes(5));
    $pdo->exec('CREATE DATABASE '.$database.' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    register_shutdown_function(static fn() => $pdo->exec('DROP DATABASE '.$database));
    $pdo->exec('USE '.$database);
}
$pdo->exec("CREATE TABLE chat_conversations(id INT PRIMARY KEY,user_id INT,user_agent_id INT NULL);
    INSERT INTO chat_conversations VALUES(10,1,NULL),(11,1,11),(12,1,12),(21,2,21);
    CREATE TABLE chat_messages(id INT PRIMARY KEY,conversation_id INT,role VARCHAR(20),message TEXT,context_json TEXT,created_at DATETIME)");
$insert = $pdo->prepare("INSERT INTO chat_messages VALUES(?,11,'assistant',?,'{}',?)");
for ($id=1; $id<=320; $id++) $insert->execute([$id,'Saved source '.$id,'2026-11-10 00:00:00']);
$pdo->exec("INSERT INTO chat_messages VALUES(400,12,'assistant','Other Agent source','{}','2026-11-10 00:00:00'),
    (401,21,'assistant','Foreign owner source','{}','2026-11-10 00:00:00')");
$tail = array_reverse($pdo->query('SELECT * FROM chat_messages WHERE conversation_id=11 ORDER BY id DESC LIMIT 300')->fetchAll());
$user = ['id'=>1];
$agent = ['id'=>11];
$checks = 0;
function source_check(bool $ok, string $message): void
{
    if (!$ok) throw new RuntimeException($message);
    $GLOBALS['checks']++;
}
function source_rejects(int $code, callable $action): void
{
    try { $action(); }
    catch (Throwable $error) { source_check($error->getCode()===$code, 'Expected source access rejection '.$code); return; }
    throw new RuntimeException('Source access should have been rejected.');
}
source_check(count($tail)===300 && (int)$tail[0]['id']===21, 'The ordinary load keeps its recent 300-message window');
source_check(vp3_agent_chat_include_source_v380($pdo,$user,$agent,11,$tail,null)===$tail, 'Ordinary loads retain their current result');
$selected = vp3_agent_chat_include_source_v380($pdo,$user,$agent,11,$tail,1);
source_check(count($selected)===301 && (int)$selected[0]['id']===1, 'An explicitly requested older source is included');
source_check(max(array_column($selected,'id'))===320, 'The latest message cursor remains the recent tail');
source_check(vp3_agent_chat_include_source_v380($pdo,$user,$agent,11,$selected,1)===$selected, 'A source already in the load is not duplicated');
source_rejects(404,fn()=>vp3_agent_chat_include_source_v380($pdo,$user,$agent,11,$tail,400));
source_rejects(404,fn()=>vp3_agent_chat_include_source_v380($pdo,$user,$agent,11,$tail,999));
source_rejects(403,fn()=>vp3_agent_chat_include_source_v380($pdo,$user,['id'=>12],11,[],1));
source_rejects(403,fn()=>vp3_agent_chat_include_source_v380($pdo,$user,['id'=>21],21,[],401));
source_rejects(422,fn()=>vp3_agent_chat_include_source_v380($pdo,$user,$agent,11,$tail,[]));
source_rejects(422,fn()=>vp3_agent_chat_include_source_v380($pdo,$user,$agent,11,$tail,'9007199254740992'));
echo 'AGENT_CHAT_SOURCE=PASS ('.$checks.' assertions, '.$pdo->getAttribute(PDO::ATTR_DRIVER_NAME).")\n";
