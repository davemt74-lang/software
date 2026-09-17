<?php
declare(strict_types=1);
require dirname(__DIR__).'/includes/bootstrap.php';
require_once dirname(__DIR__).'/includes/video-meetings-closure-recurring-continuity-v18220.php';
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');

if(!function_exists('video_meeting_by_id_v1800')){
    function video_meeting_by_id_v1800(PDO $pdo,int $meetingId): ?array
    {
        if($meetingId<1)return null;$stmt=$pdo->prepare('SELECT * FROM video_meetings WHERE id=? LIMIT 1');$stmt->execute([$meetingId]);$row=$stmt->fetch();return is_array($row)?$row:null;
    }
}

if(strtoupper((string)($_SERVER['REQUEST_METHOD']??''))!=='POST'){
    header('Allow: POST');http_response_code(405);echo json_encode(['ok'=>false,'error'=>'Meeting Closure accepts POST requests only.']);exit;
}
$user=current_user();if(!$user){http_response_code(403);echo json_encode(['ok'=>false,'error'=>'Sign in to review meeting closure.']);exit;}
$pdo=db();if(!$pdo||!video_meeting_closure_schema_ready_v18220($pdo)){http_response_code(503);echo json_encode(['ok'=>false,'error'=>'Meeting Closure is not ready. Run the current database upgrade first.']);exit;}
$input=json_decode((string)file_get_contents('php://input'),true);if(!is_array($input))$input=$_POST;
$csrf=(string)($input['csrf_token']??'');if($csrf===''||!hash_equals(csrf_token(),$csrf)){http_response_code(419);echo json_encode(['ok'=>false,'error'=>'Session expired. Refresh the page and try again.']);exit;}
$userId=(int)$user['id'];$publicId=strtolower(trim((string)($input['meeting']??'')));$meeting=video_meeting_by_public_id_v1800($pdo,$publicId);
if(!$meeting||(int)($meeting['owner_user_id']??0)!==$userId){http_response_code(404);echo json_encode(['ok'=>false,'error'=>'Meeting not found.']);exit;}
$action=strtolower(trim((string)($input['action']??'state')));
try{
    if($action==='state'){echo json_encode(['ok'=>true,'closure'=>video_meeting_closure_state_v18220($pdo,$meeting,$userId)],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);exit;}
    if($action==='close'){echo json_encode(['ok'=>true,'closure'=>video_meeting_closure_close_v18220($pdo,$meeting,$userId)],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);exit;}
    if($action==='reopen'){echo json_encode(['ok'=>true,'closure'=>video_meeting_closure_reopen_v18220($pdo,$meeting,$userId)],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);exit;}
    if($action==='carry_to_next'){echo json_encode(['ok'=>true,'closure'=>video_meeting_closure_carry_to_next_v18220($pdo,$meeting,$userId,(int)($input['closure_snapshot_id']??0),(int)($input['source_agenda_item_id']??0),(int)($input['target_meeting_id']??0))],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);exit;}
    http_response_code(400);echo json_encode(['ok'=>false,'error'=>'Unsupported Meeting Closure request.']);
}catch(RuntimeException $e){http_response_code(422);echo json_encode(['ok'=>false,'error'=>$e->getMessage()],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);}
catch(Throwable $e){error_log('VP3 meeting closure v18.22: '.$e->getMessage());http_response_code(500);echo json_encode(['ok'=>false,'error'=>'Meeting Closure could not complete this request.']);}
