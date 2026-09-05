<?php
declare(strict_types=1);
require dirname(__DIR__) . '/includes/bootstrap.php';
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');

$user=current_user();
if(!$user||!has_permission('account.access',$user)){
    http_response_code(401);
    echo json_encode(['ok'=>false,'error'=>'Sign in to view notifications.']);
    exit;
}
if(strtoupper((string)($_SERVER['REQUEST_METHOD']??'GET'))!=='GET'){
    http_response_code(405);
    echo json_encode(['ok'=>false,'error'=>'GET is required.']);
    exit;
}

$recent=notification_recent($user,8);
$items=[];
foreach($recent as $row){
    $items[]=[
        'id'=>(int)$row['id'],
        'type'=>(string)$row['type'],
        'title'=>(string)$row['title'],
        'body'=>(string)$row['body'],
        'is_read'=>(bool)$row['is_read'],
        'created_at'=>(string)$row['created_at'],
        'open_url'=>url('/notifications.php?open='.(int)$row['id']),
    ];
}

echo json_encode([
    'ok'=>true,
    'unread'=>notification_unread_count($user),
    'items'=>$items,
],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
