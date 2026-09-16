<?php
declare(strict_types=1);
require __DIR__.'/../includes/bootstrap.php';
require_once __DIR__.'/../includes/video-meetings-intelligence-handoff-v1890.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private');

$reply=static function(bool $ok,array $data=[],int $status=200): never {
    http_response_code($status);
    echo json_encode(['ok'=>$ok,'build'=>VP3_VIDEO_MEETINGS_INTELLIGENCE_HYBRID_V1890]+$data,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
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
        $state=video_meeting_intelligence_public_state_v1890($pdo,$meeting);
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
    if($action==='run_hybrid_analysis'){
        $mode=strtolower(trim((string)($_POST['mode']??'live')))==='final'?'final':'live';
        $sourceHash=strtolower(trim((string)($_POST['source_hash']??'')));
        $state=video_meeting_intelligence_run_homeserver_v1890($pdo,$meeting,$mode,$sourceHash);
        $reply(true,['state'=>$state,'route'=>'homeserver']);
    }
    if($action==='record_analysis'){
        $mode=strtolower(trim((string)($_POST['mode']??'live')))==='final'?'final':'live';
        if($mode==='final'&&!in_array((string)$meeting['status'],['ended','processed'],true)){
            throw new RuntimeException('Final meeting intelligence is only available after the meeting ends.');
        }
        $source=video_meeting_intelligence_source_v1820($pdo,$meeting);
        $route=video_meeting_intelligence_hybrid_route_v1890($pdo,$meeting,$source,false);
        if(($route['route']??'')!=='cloud'||empty($route['ready'])){
            throw new RuntimeException('VP3 Cloud Meeting Intelligence is not authorized for this meeting.');
        }
        $currentHash=(string)($source['source_hash']??'');$submittedHash=trim((string)($_POST['source_hash']??''));
        if($currentHash===''||$submittedHash===''||!hash_equals($currentHash,$submittedHash)){
            throw new RuntimeException('The meeting transcript changed while intelligence was running. Refresh and try again.');
        }
        $bundle=video_meeting_intelligence_modules_v1820($pdo,$source);
        if(empty($bundle['fresh']))throw new RuntimeException('Canonical Transcription Intelligence did not finish for the current meeting transcript.');
        $hasResult=false;
        foreach((array)($bundle['modules']??[]) as $module){
            if(is_array($module)&&function_exists('transcription_app_has_result_v300')&&transcription_app_has_result_v300($module['result']??null)){$hasResult=true;break;}
        }
        if(!$hasResult)throw new RuntimeException('Canonical Transcription Intelligence returned no current meeting analysis.');
        // A verified cloud analysis for this exact transcript replaces any prior
        // sanitized HomeServer projection. Do not let stale private output mask
        // the route the organizer actually selected for the current source hash.
        $pdo->prepare('DELETE FROM video_meeting_artifacts WHERE meeting_id=? AND app_id=? AND source_hash=?')
            ->execute([(int)$meeting['id'],VP3_VIDEO_MEETINGS_INTELLIGENCE_HOMESERVER_APP_V1890,$submittedHash]);
        video_meeting_intelligence_record_analysis_v1820($pdo,$meeting,$mode,$submittedHash);
        $reply(true,['state'=>video_meeting_intelligence_public_state_v1890($pdo,$meeting),'route'=>'cloud']);
    }
    if($action==='handoff'){
        if(!in_array((string)$meeting['status'],['ended','processed'],true))throw new RuntimeException('End the meeting before publishing final intelligence to Agent Chat.');
        $review=video_meeting_intelligence_public_state_v1890($pdo,$meeting);
        if(empty($review['final_analysis_at'])||!empty($review['final_analysis_due'])){
            throw new RuntimeException('Finalize and review the current meeting intelligence before sending it to Agent Chat.');
        }
        $result=video_meeting_intelligence_handoff_v1890($pdo,$meeting,$user);
        $reply(true,['handoff'=>$result,'state'=>video_meeting_intelligence_public_state_v1890($pdo,$meeting)]);
    }
    $reply(false,['error'=>'Unknown Meeting Intelligence action.'],404);
}catch(Throwable $e){
    error_log('VP3 meeting intelligence API error: '.$e->getMessage());
    $reply(false,['error'=>mb_strimwidth($e->getMessage(),0,500,'…')],422);
}
