<?php
declare(strict_types=1);
function knowledge_r7_research_guard(PDO $pdo,array $user,array $research): void {
    if(!function_exists('table_exists')||!table_exists('chat_knowledge_refresh_r7'))return;
    $q=$pdo->prepare('SELECT * FROM chat_knowledge_refresh_r7 WHERE research_id=? AND owner_user_id=?');$q->execute([$research['id']??'',$user['id']]);
    $row=$q->fetch(PDO::FETCH_ASSOC);if(!$row||$row['knowledge_id'])return;
    require_once __DIR__.'/project-knowledge-r7.php';
    knowledge_r7_bound($pdo,$user,$row);
}
