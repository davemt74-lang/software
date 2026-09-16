<?php
declare(strict_types=1);
require dirname(__DIR__).'/includes/bootstrap.php';
require_once dirname(__DIR__).'/includes/video-meetings-actions-v18150.php';
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');

if(strtoupper((string)($_SERVER['REQUEST_METHOD']??''))!=='POST'){
    header('Allow: POST');http_response_code(405);echo json_encode(['ok'=>false,'error'=>'Meeting Actions accepts POST requests only.']);exit;
}
$user=current_user();if(!$user){http_response_code(403);echo json_encode(['ok'=>false,'error'=>'Sign in to manage meeting actions.']);exit;}
$pdo=db();if(!$pdo||!video_meeting_action_schema_ready_v18150($pdo)){http_response_code(503);echo json_encode(['ok'=>false,'error'=>'Meeting Action Execution is not ready. Run the current database upgrade first.']);exit;}
$input=json_decode((string)file_get_contents('php://input'),true);if(!is_array($input))$input=$_POST;
$csrf=(string)($input['csrf_token']??'');if($csrf===''||!hash_equals(csrf_token(),$csrf)){http_response_code(419);echo json_encode(['ok'=>false,'error'=>'Session expired. Refresh the page and try again.']);exit;}
$userId=(int)$user['id'];$publicId=strtolower(trim((string)($input['meeting']??'')));$meeting=video_meeting_by_public_id_v1800($pdo,$publicId);
if(!$meeting||(int)($meeting['owner_user_id']??0)!==$userId){http_response_code(404);echo json_encode(['ok'=>false,'error'=>'Meeting not found.']);exit;}
$action=strtolower(trim((string)($input['action']??'state')));
try{
    if($action==='state'){echo json_encode(['ok'=>true,'actions'=>video_meeting_action_state_v18150($pdo,$meeting,$user)],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);exit;}
    if($action==='prepare'){$execution=video_meeting_action_prepare_v18150($pdo,$meeting,$user,(int)($input['item_id']??0));echo json_encode(['ok'=>true,'execution'=>$execution],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);exit;}
    if($action==='save_draft'){$draft=is_array($input['draft']??null)?$input['draft']:[];$execution=video_meeting_action_save_draft_v18150($pdo,$meeting,$user,(int)($input['execution_id']??0),$draft);echo json_encode(['ok'=>true,'execution'=>$execution],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);exit;}
    if($action==='approve'){$execution=video_meeting_action_approve_v18150($pdo,$meeting,$user,(int)($input['execution_id']??0));echo json_encode(['ok'=>true,'execution'=>$execution],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);exit;}
    if($action==='execute'){$execution=video_meeting_action_execute_v18150($pdo,$meeting,$user,(int)($input['execution_id']??0));echo json_encode(['ok'=>true,'execution'=>$execution],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);exit;}
    if($action==='reopen'){$execution=video_meeting_action_reopen_v18150($pdo,$meeting,$user,(int)($input['execution_id']??0));echo json_encode(['ok'=>true,'execution'=>$execution],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);exit;}
    if($action==='retry'){$execution=video_meeting_action_retry_v18150($pdo,$meeting,$user,(int)($input['execution_id']??0));echo json_encode(['ok'=>true,'execution'=>$execution],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);exit;}
    if($action==='complete'){$execution=video_meeting_action_complete_v18150($pdo,$meeting,$user,(int)($input['execution_id']??0));echo json_encode(['ok'=>true,'execution'=>$execution],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);exit;}
    if($action==='discard'){video_meeting_action_discard_v18150($pdo,$meeting,$user,(int)($input['execution_id']??0));echo json_encode(['ok'=>true]);exit;}
    http_response_code(400);echo json_encode(['ok'=>false,'error'=>'Unsupported Meeting Action request.']);
}catch(RuntimeException $e){http_response_code(422);echo json_encode(['ok'=>false,'error'=>$e->getMessage()],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);}
catch(Throwable $e){error_log('VP3 meeting action execution: '.$e->getMessage());http_response_code(500);echo json_encode(['ok'=>false,'error'=>'Meeting Action Execution could not complete this request.']);}
