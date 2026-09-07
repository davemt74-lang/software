<?php
declare(strict_types=1);
require dirname(__DIR__).'/includes/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

$user=current_user();
if(!$user){
    http_response_code(401);
    echo json_encode(['ok'=>false,'error'=>'Sign in to view Agent Brain learning history.'],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
    exit;
}
if(!personal_capability_has_v242('agent_brain.access',$user)){
    http_response_code(403);
    echo json_encode(['ok'=>false,'error'=>'Agent Brain is not enabled for this account.'],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
    exit;
}

try{
    $learning=agent_learning_history_v317_state($user,100);
    echo json_encode(['ok'=>true,'learning'=>$learning],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
}catch(Throwable $e){
    error_log('Agent Brain learning history endpoint failed: '.$e->getMessage());
    http_response_code(500);
    echo json_encode(['ok'=>false,'error'=>'Agent Brain learning history is temporarily unavailable.'],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
}
