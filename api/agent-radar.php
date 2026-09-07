<?php
declare(strict_types=1);
require dirname(__DIR__).'/includes/bootstrap.php';
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');

$pdo=db();
$user=current_user();
if(!$pdo||!$user||!has_permission('account.access',$user)){
    http_response_code(401);
    echo json_encode(['ok'=>false,'error'=>'Sign in to view Agent Radar.']);
    exit;
}
if(!personal_capability_has_v242('profile_agent.access',$user)){
    http_response_code(403);
    echo json_encode(['ok'=>false,'error'=>'Agent Radar is unavailable for this account.']);
    exit;
}

echo json_encode([
    'ok'=>true,
    'radar'=>vp3_radar_portal_state($pdo,(int)$user['id']),
],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
