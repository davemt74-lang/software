<?php
declare(strict_types=1);
require dirname(__DIR__).'/includes/bootstrap.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$user=current_user();
if(!$user){http_response_code(401);echo json_encode(['ok'=>false,'error'=>'login_required']);exit;}
$pdo=db();
if(!$pdo){http_response_code(503);echo json_encode(['ok'=>false,'error'=>'database_unavailable']);exit;}
try{vp3_social_ensure_schema_v320($pdo);}catch(Throwable $e){http_response_code(503);echo json_encode(['ok'=>false,'error'=>'upgrade_required']);exit;}
$username=profile_username_normalize((string)($_GET['username']??''));
$profile=$username!==''?profile_by_username($pdo,$username):null;
$viewerId=(int)$user['id'];$targetId=(int)($profile['user_id']??0);
if(!$profile||$targetId<1||$targetId===$viewerId){http_response_code(404);echo json_encode(['ok'=>false,'error'=>'not_found']);exit;}
if(empty($profile['is_active'])||empty($profile['is_public'])){http_response_code(404);echo json_encode(['ok'=>false,'error'=>'not_found']);exit;}
try{
    echo json_encode([
        'ok'=>true,
        'target'=>['user_id'=>$targetId,'username'=>$username,'display_name'=>(string)($profile['display_name']??$username)],
        'relationship'=>vp3_social_relationship_state_v320($pdo,$viewerId,$targetId),
        'csrf_token'=>csrf_token(),
        'messages_url'=>url('/messages.php'),
        'api_url'=>url('/api/messages-v320.php'),
    ],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
}catch(Throwable $e){error_log('VP3 profile social bootstrap: '.$e->getMessage());http_response_code(500);echo json_encode(['ok'=>false,'error'=>'server_error']);}
