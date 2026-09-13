<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$service = (string)file_get_contents($root . '/includes/knowledge-retrieval-v162.php');
$chat = (string)file_get_contents($root . '/api/chat-v236.php');
$stream = (string)file_get_contents($root . '/api/chat-stream-v121.php');
$home = (string)file_get_contents($root . '/includes/homeserver-knowledge-v062.php');

$failures=[];
$check=static function(bool $condition,string $message)use(&$failures):void{if(!$condition)$failures[]=$message;};

$check(str_contains($service, 'kc.knowledge_id'), 'canonical knowledge_chunks.knowledge_id is required');
$check(str_contains($service, 'kc.chunk_index'), 'canonical knowledge_chunks.chunk_index is required');
$check(str_contains($service, 'ki.created_by_user_id=:owner_id'), 'Cloud item owner predicate is required');
$check(str_contains($service, "ki.knowledge_scope=\\'personal\\'"), 'retrieval must be restricted to Personal Knowledge');
$check(str_contains($service, 'f.created_by_user_id=ki.created_by_user_id'), 'folder join must be owner scoped');
$check(str_contains($service, 'WHERE id=? AND created_by_user_id=?'), 'folder selector must validate owner');
$check(str_contains($service, "knowledge_scope='personal'") || str_contains($service, "knowledge_scope=\\'personal\\'"), 'legacy replacement must target Personal Knowledge only');
$check(!str_contains($service, 'account_id'), 'obsolete Knowledge account_id must not be used');
$check(!str_contains($service, 'kc.user_id'), 'obsolete chunk user_id must not be used');
$check(!str_contains($service, 'native_path'), 'native_path must not appear in Cloud retrieval implementation');
$check(!str_contains($service, 'storage_path'), 'storage_path must not appear in Cloud retrieval implementation');
$check(!str_contains($service, 'file_path'), 'file_path must not be selected or exposed by Cloud retrieval');
$check(str_contains($service, "'homeserver_local_knowledge'=>'not_queried'"), 'HomeServer non-query provenance marker missing');
$check(!preg_match('/homeserver_[a-z0-9_]+\s*\(/i', $service), 'Cloud retrieval service must not call HomeServer content APIs');

$check(str_contains($chat, 'includes/knowledge-retrieval-v162.php'), 'normal Chat must load v16.2 retrieval');
$check(str_contains($chat, 'knowledge_retrieval_v162_generate_answer'), 'normal Chat must run v16.2 retrieval before model generation');
$check(str_contains($chat, "'knowledge'=>\$knowledgeContext"), 'normal Chat must persist/return Knowledge citations');
$check(str_contains($chat, 'knowledge_scope'), 'normal Chat must accept conversation Knowledge scope');

$check(str_contains($stream, 'includes/knowledge-retrieval-v162.php'), 'stream Chat must load v16.2 retrieval');
$check(str_contains($stream, 'knowledge_retrieval_v162_for_chat'), 'stream Chat must use v16.2 retrieval before streaming');
$check(str_contains($stream, "'knowledge'=>\$knowledgeContext"), 'stream Chat must persist/return Knowledge citations');
$check(str_contains($stream, 'knowledge_scope'), 'stream Chat must accept conversation Knowledge scope');

$check(str_contains($home, 'native_path') || str_contains($home, 'path'), 'HomeServer Local Knowledge contract unexpectedly absent');
$check(!str_contains($chat, 'native_path') && !str_contains($stream, 'native_path'), 'chat endpoints must never expose native_path');

if($failures){fwrite(STDERR,"Knowledge Agent Context v16.2 security failures:\n- ".implode("\n- ",$failures)."\n");exit(1);}echo "Knowledge Agent Context v16.2 security contract passed\n";
