<?php
declare(strict_types=1);
require dirname(__DIR__).'/includes/bootstrap.php';
require_once dirname(__DIR__).'/includes/video-meetings-automation-v18130.php';
require_once dirname(__DIR__).'/includes/video-meetings-followthrough-intelligence-v18160.php';
require_once dirname(__DIR__).'/includes/video-meetings-outcome-learning-v18170.php';
require_once dirname(__DIR__).'/includes/video-meetings-cross-meeting-continuity-v18210.php';
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');

if(strtoupper((string)($_SERVER['REQUEST_METHOD']??''))!=='POST'){
    header('Allow: POST');
    http_response_code(405);
    echo json_encode(['ok'=>false,'error'=>'Meeting Intelligence Automation accepts POST requests only.']);
    exit;
}

$user=current_user();
if(!$user){http_response_code(403);echo json_encode(['ok'=>false,'error'=>'Sign in to load meeting preparation.']);exit;}
$pdo=db();
if(!$pdo||!video_meeting_schema_ready_v1800($pdo)||!video_meeting_intelligence_schema_ready_v1820($pdo)){
    http_response_code(503);echo json_encode(['ok'=>false,'error'=>'Meeting Intelligence Automation is not ready. Run the current database upgrade first.']);exit;
}
$input=json_decode((string)file_get_contents('php://input'),true);if(!is_array($input))$input=$_POST;
$csrf=(string)($input['csrf_token']??'');
if($csrf===''||!hash_equals(csrf_token(),$csrf)){http_response_code(419);echo json_encode(['ok'=>false,'error'=>'Session expired. Refresh the page and try again.']);exit;}
$action=strtolower(trim((string)($input['action']??'prep')));$userId=(int)$user['id'];

try{
    if($action!=='prep'){http_response_code(400);echo json_encode(['ok'=>false,'error'=>'Unsupported Meeting Intelligence Automation action.']);exit;}
    $publicId=strtolower(trim((string)($input['meeting']??'')));$meeting=video_meeting_by_public_id_v1800($pdo,$publicId);
    if(!$meeting||(int)($meeting['owner_user_id']??0)!==$userId){http_response_code(404);echo json_encode(['ok'=>false,'error'=>'Meeting not found.']);exit;}
    $prep=video_meeting_automation_prep_v18130($pdo,$meeting,$userId);
    $prep['followthrough_intelligence']=video_meeting_followthrough_prep_v18160($pdo,$meeting,$userId);
    $prep['outcome_learning']=video_meeting_outcome_learning_for_meeting_v18170($pdo,$meeting,$userId);
    $prep['continuity']=video_meeting_continuity_schema_ready_v18210($pdo)?video_meeting_continuity_state_v18210($pdo,$meeting,$userId):['version'=>'v18.21','schema'=>'vp3.meeting.continuity','candidates'=>[],'not_ready'=>true];
    echo json_encode(['ok'=>true,'prep'=>$prep],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
}catch(RuntimeException $e){http_response_code(422);echo json_encode(['ok'=>false,'error'=>$e->getMessage()],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);}
catch(Throwable $e){error_log('VP3 meeting automation: '.$e->getMessage());http_response_code(500);echo json_encode(['ok'=>false,'error'=>'Meeting Intelligence Automation could not complete this request.']);}
