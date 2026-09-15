<?php
declare(strict_types=1);
require __DIR__.'/includes/bootstrap.php';
require_permission('account.access');
$pdo=db();$user=current_user();if(!$pdo||!$user)redirect(url('/login.php'));
if(!video_meeting_schema_ready_v1800($pdo)||!video_meeting_transcription_schema_ready_v1800($pdo)||!video_meeting_external_calendar_schema_ready_v1801($pdo)||!video_meeting_intelligence_schema_ready_v1820($pdo))redirect(url('/upgrade.php'));

try{video_meeting_reconcile_recent_bookings_v1800($pdo,50);}catch(Throwable $ignored){}
$userId=(int)$user['id'];$timezone=function_exists('user_calendar_default_timezone_v1300')?user_calendar_default_timezone_v1300($pdo,$user):'UTC';
$agents=function_exists('user_agents_list_v236')?user_agents_list_v236($pdo,$userId,true):[];
$defaultAgentId=0;foreach($agents as $agent)if(!empty($agent['is_default'])){$defaultAgentId=(int)$agent['id'];break;}
$error='';

$parseInvitees=static function(string $raw): array {
    $out=[];$seen=[];
    foreach(preg_split('/[\r\n,]+/',$raw)?:[] as $line){
        $line=trim($line);if($line==='')continue;$name='';$email='';
        if(preg_match('/^(.*?)\s*<([^>]+)>$/',$line,$m)){$name=trim($m[1]);$email=strtolower(trim($m[2]));}
        elseif(filter_var(strtolower($line),FILTER_VALIDATE_EMAIL))$email=strtolower($line);
        if($email===''||!filter_var($email,FILTER_VALIDATE_EMAIL))continue;
        if(isset($seen[$email]))continue;$seen[$email]=true;$out[]=['display_name'=>$name,'email'=>$email,'role'=>'attendee'];
    }
    return $out;
};

if($_SERVER['REQUEST_METHOD']==='POST'){
    if(!verify_csrf())$error='Session expired. Please try again.';
    else try{
        $title=trim((string)($_POST['title']??''));$startLocal=trim((string)($_POST['start_local']??''));$duration=max(15,min(480,(int)($_POST['duration_minutes']??30)));
        $tz=user_calendar_timezone_v1300((string)($_POST['timezone']??$timezone),$timezone);$agentMode=(string)($_POST['agent_mode']??'notes')==='off'?'off':'notes';
        if($startLocal==='')throw new RuntimeException('Choose a meeting date and time.');
        $local=new DateTimeImmutable($startLocal,new DateTimeZone($tz));$startUtc=$local->setTimezone(new DateTimeZone('UTC'));$endUtc=$startUtc->modify('+'.$duration.' minutes');
        if($startUtc<=(new DateTimeImmutable('now',new DateTimeZone('UTC')))->modify('-5 minutes'))throw new RuntimeException('Choose a current or future meeting time.');
        $meeting=video_meeting_create_v1800($pdo,$user,[
            'title'=>$title,'description'=>trim((string)($_POST['description']??'')),'start_at_utc'=>$startUtc->format('Y-m-d H:i:s'),'end_at_utc'=>$endUtc->format('Y-m-d H:i:s'),'timezone'=>$tz,
            'organizer_agent_id'=>(int)($_POST['organizer_agent_id']??$defaultAgentId),'agent_mode'=>$agentMode,'transcription_enabled'=>!empty($_POST['transcription_enabled']),'recording_enabled'=>false,
        ]);
        if(!empty($meeting['transcription_enabled']))video_meeting_transcription_ensure_session_v1800($pdo,$meeting);
        foreach($parseInvitees((string)($_POST['invitees']??'')) as $invitee){
            $participant=video_meeting_add_participant_v1800($pdo,$meeting,$invitee,false);
            video_meeting_external_calendar_sync_participant_v1801($pdo,$meeting,$participant);
            video_meeting_secure_invitation_email_v1800($pdo,$meeting,$participant);
        }
        if(function_exists('create_notification'))create_notification($userId,'video_meeting_scheduled','Video meeting scheduled',(string)$meeting['title'].' · '.(string)$meeting['start_at_utc'].' UTC',url('/meeting.php?meeting='.(string)$meeting['public_id']),'video_meeting',(int)$meeting['id']);
        redirect(url('/meeting.php?meeting='.(string)$meeting['public_id'].'&created=1'));
    }catch(Throwable $e){$error=$e->getMessage();}
}

$meetings=video_meeting_recent_for_user_v1800($pdo,$userId,80);
$localDefault=(new DateTimeImmutable('+1 hour',new DateTimeZone($timezone)))->setTime((int)(new DateTimeImmutable('+1 hour',new DateTimeZone($timezone)))->format('H'),0)->format('Y-m-d\TH:i');
$mediaReady=video_meeting_livekit_ready_v1800();$agentWorkerReady=video_meeting_agent_worker_ready_v1800();
$serviceState=!$mediaReady?'LiveKit configuration required before joining':($agentWorkerReady?'LiveKit media + meeting Agent ready':'LiveKit media ready · transcription worker setup required');
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="theme-color" content="#f7f7f5"><title>Meetings | VP3</title><link rel="stylesheet" href="<?= e(url('/chat.css?v=82')) ?>"><link rel="stylesheet" href="<?= e(url('/video-meetings-v1800.css?v=1820')) ?>"></head>
<body class="vp3-meetings-page"><div class="chat-app">
<?php $workspaceSidebarUser=$user;$workspaceSidebarActive='meetings';require __DIR__.'/includes/workspace-sidebar-v82.php'; ?><div class="chat-sidebar-backdrop" id="chatSidebarBackdrop"></div>
<main class="chat-main meetings-main"><?php $memberHeaderUser=$user;$memberHeaderTitle='Meetings';$memberHeaderSubtitle='Video meetings connected to Calendar, Scheduling, your Agent, Meeting Intelligence and follow-up.';$memberHeaderActions='<a class="meeting-secondary" href="'.e(url('/calendar.php')).'">Calendar</a>';require __DIR__.'/includes/member-header.php'; ?>
<div class="meetings-canvas"><div class="meetings-inner">
<?php if($error!==''): ?><div class="meeting-alert error" role="alert"><?= e($error) ?></div><?php endif; ?>
<section class="meeting-create-card"><div class="meeting-section-copy"><span class="meeting-eyebrow">New video meeting</span><h2>Schedule a room around the work.</h2><p>Invite VP3 members or guests. Members receive a notification and VP3 calendar event, plus a projection to any writable connected Google or Microsoft calendar; guests receive an email with a secure join link and calendar file.</p></div>
<form method="post" class="meeting-create-form"><?= csrf_field() ?>
<label class="wide"><span>Meeting title</span><input name="title" maxlength="190" required value="<?= e((string)($_POST['title']??'')) ?>" placeholder="Project review"></label>
<label><span>Starts</span><input type="datetime-local" name="start_local" required value="<?= e((string)($_POST['start_local']??$localDefault)) ?>"></label>
<label><span>Duration</span><select name="duration_minutes"><?php foreach([15,30,45,60,90,120] as $m): ?><option value="<?= $m ?>" <?= (int)($_POST['duration_minutes']??30)===$m?'selected':'' ?>><?= $m ?> minutes</option><?php endforeach; ?></select></label>
<label class="wide"><span>Timezone</span><input name="timezone" value="<?= e((string)($_POST['timezone']??$timezone)) ?>" maxlength="80" required></label>
<label><span>Meeting Agent</span><select name="organizer_agent_id"><option value="0">System Agent</option><?php foreach($agents as $agent): ?><option value="<?= (int)$agent['id'] ?>" <?= (int)($_POST['organizer_agent_id']??$defaultAgentId)===(int)$agent['id']?'selected':'' ?>><?= e((string)$agent['display_name']) ?></option><?php endforeach; ?></select></label>
<label><span>Agent mode</span><select name="agent_mode"><option value="notes" <?= (string)($_POST['agent_mode']??'notes')==='notes'?'selected':'' ?>>Notes + intelligence</option><option value="off" <?= (string)($_POST['agent_mode']??'notes')==='off'?'selected':'' ?>>Off</option></select><small>Phase 18.2 remains notes-first. Spoken Agent participation stays disabled until its separate approval and voice policy layer ships.</small></label>
<label class="wide"><span>Invite participants</span><textarea name="invitees" rows="3" placeholder="sarah@example.com&#10;John Smith &lt;john@example.com&gt;"><?= e((string)($_POST['invitees']??'')) ?></textarea><small>One per line or comma-separated. Existing VP3 accounts are matched by email; no duplicate CRM contact is created.</small></label>
<label class="wide"><span>Description / agenda</span><textarea name="description" rows="3" placeholder="What should everyone prepare or decide?"><?= e((string)($_POST['description']??'')) ?></textarea></label>
<label class="meeting-check wide"><input type="checkbox" name="transcription_enabled" value="1" <?= !isset($_POST['transcription_enabled'])||!empty($_POST['transcription_enabled'])?'checked':'' ?>><span>Enable live transcript + Meeting Intelligence through VP3's existing transcription system. Participants see the transcription disclosure before joining.</span></label>
<div class="meeting-form-actions wide"><span class="meeting-livekit-state <?= $mediaReady?'ready':'setup' ?>"><?= e($serviceState) ?></span><button type="submit" class="meeting-primary">Schedule video meeting</button></div>
</form></section>

<section class="meeting-list-section"><div class="meeting-list-head"><div><span class="meeting-eyebrow">Meeting history</span><h2>Upcoming and recent</h2></div><span><?= count($meetings) ?> meetings</span></div>
<div class="meeting-list"><?php if(!$meetings): ?><div class="meeting-empty">No video meetings yet.</div><?php endif; ?><?php foreach($meetings as $meeting): $startTs=strtotime((string)$meeting['start_at_utc'].' UTC')?:0;$local=$startTs?(new DateTimeImmutable('@'.$startTs))->setTimezone(new DateTimeZone((string)$meeting['timezone'])):null;$agentName=video_meeting_agent_name_v1800($pdo,$meeting);$reviewable=in_array((string)$meeting['status'],['ended','processed'],true);$meetingHref=url('/meeting.php?meeting='.(string)$meeting['public_id'].($reviewable?'&review=1':'')); ?>
<a class="meeting-row" href="<?= e($meetingHref) ?>"><div class="meeting-row-date"><strong><?= $local?e($local->format('M j')):'' ?></strong><span><?= $local?e($local->format('g:i A')):'' ?></span></div><div class="meeting-row-main"><strong><?= e((string)$meeting['title']) ?></strong><span><?= e($agentName) ?> · <?= $reviewable?'Review Meeting Intelligence':e(ucfirst((string)$meeting['agent_mode'])).' mode' ?></span></div><span class="meeting-status <?= e((string)$meeting['status']) ?>"><?= e(ucfirst((string)$meeting['status'])) ?></span><span class="meeting-row-arrow">→</span></a>
<?php endforeach; ?></div></section>
</div></div></main></div><script src="<?= e(url('/member-shell-v77.js?v=20260911')) ?>"></script></body></html>