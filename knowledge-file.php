<?php
declare(strict_types=1);
require __DIR__ . '/includes/bootstrap.php';
require_login();

$id = (int)($_GET['id'] ?? 0);
$item = get_knowledge_item($id);
$user = current_user();
$userId=(int)($user['id']??0);
$scope=column_exists('knowledge_items','knowledge_scope')?(string)($item['knowledge_scope']??'system'):'system';
$allowed=false;

if($item&&$user){
    if($scope==='personal'){
        $allowed=(int)($item['created_by_user_id']??0)===$userId
            && personal_capability_has_v242('personal_knowledge.access',$user);
    }else{
        $allowed=has_permission('knowledge.manage',$user)
            || (has_permission('knowledge.access',$user)
                && (int)$item['is_published']===1
                && knowledge_visibility_allowed((string)$item['visibility'],$user));
    }
}

if (!$item || !$allowed) {
    http_response_code(404);
    exit('Knowledge file not found.');
}

$path = trim((string)$item['file_path']);
if ($path === '' || !str_starts_with($path, '/uploads/knowledge/')) {
    http_response_code(404);
    exit('No file attached.');
}

$absolute = STONEFELLOW_ROOT . '/' . ltrim($path, '/');
$realBase = realpath(STONEFELLOW_ROOT . '/uploads/knowledge');
$realFile = realpath($absolute);

if (!$realBase || !$realFile || !str_starts_with($realFile, $realBase . DIRECTORY_SEPARATOR) || !is_file($realFile)) {
    http_response_code(404);
    exit('File not found.');
}

$mime = trim((string)$item['mime_type']) ?: 'application/octet-stream';
$name = trim((string)$item['file_name']) ?: basename($realFile);

header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($realFile));
header('Content-Disposition: inline; filename="' . str_replace('"', '', $name) . '"');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store');
readfile($realFile);
exit;
