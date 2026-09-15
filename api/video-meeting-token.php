<?php
declare(strict_types=1);
require __DIR__.'/../includes/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private');
header('Pragma: no-cache');

$fail=static function(int $status,string $message): never {
    http_response_code($status);
    echo json_encode(['ok'=>false,'error'=>$message],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
    exit;
};

if($_SERVER['REQUEST_METHOD']!=='POST')$fail(405,'POST required.');
$pdo=db();if(!$pdo||!video_meeting_schema_ready_v1800($pdo))$fail(503,'Video Meetings are not ready.');
if(!video_meeting_livekit_ready_v1800())$fail(503,'LiveKit is not configured on this VP3 deployment.');

$user=current_user();
if($user&&!verify_csrf())$fail(403,'Session expired. Refresh the meeting and try again.');

$publicId=strtolower(trim((string)($_POST['meeting']??'')));
$invite=strtolower(trim((string)($_POST['invite']??'')));
$access=video_meeting_secure_access_v1800($pdo,$user,$publicId,$invite);
if(!$access)$fail(403,'This meeting invitation is not available to you.');
$meeting=$access['meeting'];$participant=$access['participant'];

if(!in_array((string)$meeting['status'],['scheduled','ready','live'],true)){
    $fail(409,(string)$meeting['status']==='cancelled'?'This meeting was cancelled.':'This meeting has ended.');
}

// Paid Scheduling can reserve time before checkout settles. Even if a stale
// meeting row or copied URL exists, media credentials are never minted while
// the canonical Commerce order is still awaiting payment.
$bookingId=(int)($meeting['booking_id']??0);
if($bookingId>0&&function_exists('agent_paid_appointments_schema_ready_v800')&&agent_paid_appointments_schema_ready_v800($pdo)&&function_exists('agent_paid_appointments_paid_booking_for_booking_v800')){
    try{
        $paid=agent_paid_appointments_paid_booking_for_booking_v800($pdo,$bookingId);
        if(is_array($paid)&&(string)($paid['payment_status']??'')==='awaiting_payment')$fail(402,'Complete the appointment payment before joining this meeting.');
    }catch(Throwable $e){
        if((int)http_response_code()===402)exit;
        error_log('VP3 meeting payment gate check failed: '.$e->getMessage());
        $fail(503,'Meeting payment status could not be verified.');
    }
}

$now=time();$start=strtotime((string)$meeting['start_at_utc'].' UTC')?:0;$end=strtotime((string)$meeting['end_at_utc'].' UTC')?:0;
if(empty($access['is_organizer'])&&$start>0&&$now<$start-1800)$fail(409,'This meeting opens 30 minutes before its scheduled start.');
if($end>0&&$now>$end+14400)$fail(409,'This meeting is no longer open.');

$displayName=trim(preg_replace('/\s+/u',' ',(string)($_POST['display_name']??$participant['display_name']??''))??'');
if($displayName==='')$displayName='Guest';$displayName=mb_strimwidth($displayName,0,190,'');
if(!$user&&(int)$participant['user_id']<1&&$displayName!==(string)$participant['display_name']){
    $pdo->prepare('UPDATE video_meeting_participants SET display_name=? WHERE id=? AND meeting_id=?')->execute([$displayName,(int)$participant['id'],(int)$meeting['id']]);
    $participant['display_name']=$displayName;
}

try{
    if(!empty($meeting['transcription_enabled']))video_meeting_transcription_ensure_session_v1800($pdo,$meeting);
    $identity=video_meeting_participant_identity_v1800($meeting,$participant);
    $token=video_meeting_livekit_participant_token_v1800($meeting,$identity,$displayName);
    $cfg=video_meeting_livekit_config_v1800();
    $processingRoute='off';
    if(!empty($meeting['transcription_enabled'])){
        $policy=function_exists('video_meeting_homeserver_public_policy_v1801')?video_meeting_homeserver_public_policy_v1801($pdo,$meeting):['policy_resolved'=>true,'cloud_processing_allowed'=>true];
        if(empty($policy['policy_resolved']))$processingRoute='organizer_policy';
        elseif(($policy['cloud_processing_allowed']??null)===true)$processingRoute='cloud';
        else $processingRoute='private_required';
    }
    echo json_encode([
        'ok'=>true,
        'server_url'=>(string)$cfg['url'],
        'participant_token'=>$token,
        'participant'=>['identity'=>$identity,'name'=>$displayName,'role'=>(string)$participant['role'],'is_organizer'=>!empty($access['is_organizer'])],
        'meeting'=>['public_id'=>(string)$meeting['public_id'],'title'=>(string)$meeting['title'],'status'=>(string)$meeting['status'],'agent_mode'=>(string)$meeting['agent_mode'],'transcription_enabled'=>!empty($meeting['transcription_enabled']),'recording_enabled'=>!empty($meeting['recording_enabled']),'processing_route'=>$processingRoute],
        'agent'=>['name'=>video_meeting_agent_name_v1800($pdo,$meeting),'mode'=>(string)$meeting['agent_mode']],
    ],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
}catch(Throwable $e){
    error_log('VP3 meeting token error: '.$e->getMessage());
    $fail(500,'The meeting connection could not be prepared.');
}
