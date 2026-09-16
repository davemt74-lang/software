<?php
declare(strict_types=1);
require dirname(__DIR__).'/includes/bootstrap.php';
require_once dirname(__DIR__).'/includes/video-meetings-agenda-v18140.php';
require_once dirname(__DIR__).'/includes/video-meetings-actions-v18150.php';
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');

if(strtoupper((string)($_SERVER['REQUEST_METHOD']??''))!=='POST'){
    header('Allow: POST');http_response_code(405);echo json_encode(['ok'=>false,'error'=>'Meeting Agenda accepts POST requests only.']);exit;
}
$user=current_user();if(!$user){http_response_code(403);echo json_encode(['ok'=>false,'error'=>'Sign in to manage the meeting agenda.']);exit;}
$pdo=db();if(!$pdo||!video_meeting_agenda_schema_ready_v18140($pdo)){http_response_code(503);echo json_encode(['ok'=>false,'error'=>'Meeting Agenda is not ready. Run the current database upgrade first.']);exit;}
$input=json_decode((string)file_get_contents('php://input'),true);if(!is_array($input))$input=$_POST;
$csrf=(string)($input['csrf_token']??'');if($csrf===''||!hash_equals(csrf_token(),$csrf)){http_response_code(419);echo json_encode(['ok'=>false,'error'=>'Session expired. Refresh the page and try again.']);exit;}
$userId=(int)$user['id'];$publicId=strtolower(trim((string)($input['meeting']??'')));$meeting=video_meeting_by_public_id_v1800($pdo,$publicId);
if(!$meeting||(int)($meeting['owner_user_id']??0)!==$userId){http_response_code(404);echo json_encode(['ok'=>false,'error'=>'Meeting not found.']);exit;}
$action=strtolower(trim((string)($input['action']??'state')));
try{
    if($action==='state'){echo json_encode(['ok'=>true,'agenda'=>video_meeting_agenda_state_v18140($pdo,$meeting,$userId)],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);exit;}
    if($action==='add'){$items=video_meeting_agenda_insert_v18140($pdo,$meeting,$userId,(string)($input['item_text']??''),(string)($input['item_type']??'discussion'),(string)($input['priority']??'normal'));echo json_encode(['ok'=>true,'items'=>$items],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);exit;}
    if($action==='promote_prep'){$items=video_meeting_agenda_promote_prep_v18140($pdo,$meeting,$userId,(string)($input['source_bucket']??''),(int)($input['source_index']??-1));echo json_encode(['ok'=>true,'items'=>$items],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);exit;}
    if($action==='promote_suggestion'){$items=video_meeting_agenda_promote_suggestion_v18140($pdo,$meeting,$userId,(int)($input['suggestion_index']??-1));echo json_encode(['ok'=>true,'items'=>$items],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);exit;}
    if($action==='accept_suggestions'){$items=video_meeting_agenda_accept_suggestions_v18140($pdo,$meeting,$userId);echo json_encode(['ok'=>true,'items'=>$items],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);exit;}
    if($action==='update'){video_meeting_action_guard_agenda_mutation_v18150($pdo,$userId,(int)($input['item_id']??0),'update',$input);$changes=[];foreach(['item_text','item_type','status','priority'] as $key){if(array_key_exists($key,$input))$changes[$key]=$input[$key];}$items=video_meeting_agenda_update_v18140($pdo,$meeting,$userId,(int)($input['item_id']??0),$changes);echo json_encode(['ok'=>true,'items'=>$items],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);exit;}
    if($action==='reorder'){$ids=is_array($input['ids']??null)?$input['ids']:[];$items=video_meeting_agenda_reorder_v18140($pdo,$meeting,$userId,$ids);echo json_encode(['ok'=>true,'items'=>$items],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);exit;}
    if($action==='delete'){video_meeting_action_guard_agenda_mutation_v18150($pdo,$userId,(int)($input['item_id']??0),'delete',$input);$items=video_meeting_agenda_delete_v18140($pdo,$meeting,$userId,(int)($input['item_id']??0));echo json_encode(['ok'=>true,'items'=>$items],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);exit;}
    if($action==='action_state'){video_meeting_action_guard_agenda_mutation_v18150($pdo,$userId,(int)($input['item_id']??0),'action_state',$input);$items=video_meeting_agenda_action_state_v18140($pdo,$meeting,$userId,(int)($input['item_id']??0),(string)($input['action_kind']??''),(string)($input['approval_state']??'proposed'));echo json_encode(['ok'=>true,'items'=>$items,'side_effects_executed'=>false],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);exit;}
    http_response_code(400);echo json_encode(['ok'=>false,'error'=>'Unsupported Meeting Agenda action.']);
}catch(RuntimeException $e){http_response_code(422);echo json_encode(['ok'=>false,'error'=>$e->getMessage()],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);}
catch(Throwable $e){error_log('VP3 meeting agenda: '.$e->getMessage());http_response_code(500);echo json_encode(['ok'=>false,'error'=>'Meeting Agenda could not complete this request.']);}
