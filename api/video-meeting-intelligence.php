<?php
declare(strict_types=1);
require __DIR__.'/../includes/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private');

$reply=static function(bool $ok,array $data=[],int $status=200): never {
    http_response_code($status);
    echo json_encode(['ok'=>$ok,'build'=>VP3_VIDEO_MEETINGS_INTELLIGENCE_V1820]+$data,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
    exit;
};
if(($_SERVER['REQUEST_METHOD']??'')!=='POST')$reply(false,['error'=>'POST required.'],405);
$pdo=db();
if(!$pdo||!video_meeting_schema_ready_v1800($pdo)||!video_meeting_intelligence_schema_ready_v1820($pdo))$reply(false,['error'=>'Meeting Intelligence is not ready. Run the VP3 database upgrade.'],503);
$user=current_user();
if(!$user)$reply(false,['error'=>'Sign in as the meeting organizer to use private Meeting Intelligence.'],401);
if(!verify_csrf())$reply(false,['error'=>'Session expired. Refresh and try again.'],419);
$access=video_meeting_secure_access_v1800(
    $pdo,$user,
    strtolower(trim((string)($_POST['meeting']??''))),
    strtolower(trim((string)($_POST['invite']??'')))
);
if(!$access)$reply(false,['error'=>'This meeting is not available to you.'],403);
$meeting=$access['meeting'];
if(!video_meeting_intelligence_owner_allowed_v1820($user,$meeting))$reply(false,['error'=>'Meeting Intelligence is private to the organizer.'],403);
$action=strtolower(trim((string)($_POST['action']??'state')));

try{
    if($action==='state'){
        $state=video_meeting_intelligence_public_state_v1820($pdo,$meeting);
        $state['analysis_apps_live']=['basic','actions','decisions','qa','followup','risks','topics'];
        $state['analysis_apps_final']=['basic','actions','decisions','qa','requirements','followup','risks','topics','crm'];
        $reply(true,['state'=>$state]);
    }
    if($action==='save_note'){
        $notes=video_meeting_intelligence_save_note_v1820($pdo,$meeting,$user,(string)($_POST['note_text']??''));
        $reply(true,['notes'=>$notes]);
    }
    if($action==='add_objective'){
        $objectives=video_meeting_intelligence_add_objective_v1820($pdo,$meeting,$user,(string)($_POST['objective_text']??''));
        $reply(true,['objectives'=>$objectives]);
    }
    if($action==='objective_status'){
        $objectives=video_meeting_intelligence_toggle_objective_v1820(
            $pdo,$meeting,$user,max(0,(int)($_POST['objective_id']??0)),(string)($_POST['status']??'open')
        );
        $reply(true,['objectives'=>$objectives]);
    }
    if($action==='record_analysis'){
        $mode=(string)($_POST['mode']??'live');
        $state=video_meeting_intelligence_record_analysis_v1820($pdo,$meeting,$mode,(string)($_POST['source_hash']??''));
        $reply(true,['state'=>$state]);
    }
    if($action==='handoff'){
        $result=video_meeting_intelligence_handoff_v1820($pdo,$meeting,$user);
        $reply(true,['handoff'=>$result,'state'=>video_meeting_intelligence_public_state_v1820($pdo,$meeting)]);
    }
    $reply(false,['error'=>'Unknown Meeting Intelligence action.'],404);
}catch(Throwable $e){
    error_log('VP3 meeting intelligence API error: '.$e->getMessage());
    $reply(false,['error'=>mb_strimwidth($e->getMessage(),0,500,'…')],422);
}
