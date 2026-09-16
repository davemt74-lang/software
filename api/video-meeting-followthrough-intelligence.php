<?php
declare(strict_types=1);
require dirname(__DIR__).'/includes/bootstrap.php';
require_once dirname(__DIR__).'/includes/video-meetings-followthrough-intelligence-v18160.php';
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');

if(strtoupper((string)($_SERVER['REQUEST_METHOD']??''))!=='POST'){
    header('Allow: POST');http_response_code(405);echo json_encode(['ok'=>false,'error'=>'Meeting Follow-Through Intelligence accepts POST requests only.']);exit;
}
$user=current_user();if(!$user){http_response_code(403);echo json_encode(['ok'=>false,'error'=>'Sign in to review meeting follow-through.']);exit;}
$pdo=db();if(!$pdo||!video_meeting_followthrough_intelligence_schema_ready_v18160($pdo)){http_response_code(503);echo json_encode(['ok'=>false,'error'=>'Meeting Follow-Through Intelligence is not ready. Run the current database upgrade first.']);exit;}
$input=json_decode((string)file_get_contents('php://input'),true);if(!is_array($input))$input=$_POST;
$csrf=(string)($input['csrf_token']??'');if($csrf===''||!hash_equals(csrf_token(),$csrf)){http_response_code(419);echo json_encode(['ok'=>false,'error'=>'Session expired. Refresh the page and try again.']);exit;}
$userId=(int)$user['id'];$publicId=strtolower(trim((string)($input['meeting']??'')));$meeting=video_meeting_by_public_id_v1800($pdo,$publicId);
if(!$meeting||(int)($meeting['owner_user_id']??0)!==$userId){http_response_code(404);echo json_encode(['ok'=>false,'error'=>'Meeting not found.']);exit;}
$action=strtolower(trim((string)($input['action']??'state')));
try{
    if($action==='state'){echo json_encode(['ok'=>true,'followthrough'=>video_meeting_followthrough_state_v18160($pdo,$meeting,$user)],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);exit;}
    if($action==='set_expected'){video_meeting_followthrough_expected_v18160($pdo,$meeting,$user,(int)($input['monitor_id']??0),(string)($input['expected_at']??''));echo json_encode(['ok'=>true,'followthrough'=>video_meeting_followthrough_state_v18160($pdo,$meeting,$user)],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);exit;}
    if($action==='confirm'){video_meeting_followthrough_confirm_v18160($pdo,$meeting,$user,(int)($input['monitor_id']??0));echo json_encode(['ok'=>true,'followthrough'=>video_meeting_followthrough_state_v18160($pdo,$meeting,$user)],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);exit;}
    if($action==='dismiss'){video_meeting_followthrough_dismiss_v18160($pdo,$meeting,$user,(int)($input['monitor_id']??0));echo json_encode(['ok'=>true,'followthrough'=>video_meeting_followthrough_state_v18160($pdo,$meeting,$user)],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);exit;}
    if($action==='reopen'){video_meeting_followthrough_reopen_v18160($pdo,$meeting,$user,(int)($input['monitor_id']??0));echo json_encode(['ok'=>true,'followthrough'=>video_meeting_followthrough_state_v18160($pdo,$meeting,$user)],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);exit;}
    http_response_code(400);echo json_encode(['ok'=>false,'error'=>'Unsupported Meeting Follow-Through Intelligence request.']);
}catch(RuntimeException $e){http_response_code(422);echo json_encode(['ok'=>false,'error'=>$e->getMessage()],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);}
catch(Throwable $e){error_log('VP3 meeting follow-through intelligence: '.$e->getMessage());http_response_code(500);echo json_encode(['ok'=>false,'error'=>'Meeting Follow-Through Intelligence could not complete this request.']);}
