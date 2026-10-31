<?php
declare(strict_types=1);

// These MySQL services exercise the real Chat owner/conversation boundary.
require_once dirname(__DIR__,2).'/includes/agent-capability-chat-v1.php';
require_once dirname(__DIR__,2).'/includes/knowledge-retrieval-v162.php';
require_once dirname(__DIR__,2).'/includes/agent-chat-boundary-v380.php';
if(!function_exists('has_permission')){
    function has_permission(string $key,?array $user=null): bool {return true;}
}
function system_app_chat_auth_fixture_v1(PDO $pdo,int $conversationId): void
{
    $pdo->exec('ALTER TABLE users ADD COLUMN is_active TINYINT NOT NULL DEFAULT 1');
    // Each suite recreates users and owns its isolated test database.
    $pdo->exec('DROP TABLE IF EXISTS chat_conversations');
    $pdo->exec('CREATE TABLE chat_conversations (id INT UNSIGNED NOT NULL PRIMARY KEY,user_id INT UNSIGNED NOT NULL,user_agent_id INT UNSIGNED NULL) ENGINE=InnoDB');
    $q=$pdo->prepare('INSERT INTO chat_conversations(id,user_id,user_agent_id) VALUES(?,1,NULL)');
    $q->execute([$conversationId]);
}
