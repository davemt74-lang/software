<?php
declare(strict_types=1);
require __DIR__.'/includes/bootstrap.php';

header('Referrer-Policy: no-referrer');
header('X-Robots-Tag: noindex, nofollow, noarchive');

$pdo=db();
if(!$pdo||!video_meeting_schema_ready_v1800($pdo)||!video_meeting_manual_schema_ready_v1830($pdo)){
    http_response_code(503);exit('Video Meetings are not ready.');
}
$user=current_user();
$publicId=strtolower(trim((string)($_GET['meeting']??$_POST['meeting']??'')));
$meeting=video_meeting_by_public_id_v1800($pdo,$publicId);
if(!$meeting||video_meeting_guest_access_mode_v1830($pdo,$meeting)!=='email_gate'){
    http_response_code(404);exit('Meeting invitation not found.');
}

// Signed-in invited members never need the email gate. Their VP3 identity is
// the authorization boundary and the stable public meeting URL is sufficient.
if($user&&video_meeting_secure_access_v1800($pdo,$user,$publicId,'')){
    redirect(url('/meeting.php?meeting='.rawurlencode($publicId)));
}
if(!$user&&video_meeting_email_gate_access_v1830($pdo,null,$publicId)){
    redirect(url('/meeting.php?meeting='.rawurlencode($publicId)));
}

$error='';
if($_SERVER['REQUEST_METHOD']==='POST'){
    if(!verify_csrf())$error='Session expired. Refresh this page and try again.';
    else{
        try{
            if($user)throw new RuntimeException('This signed-in VP3 account is not on the attendee list. Open the invitation from the invited account, or sign out to use an eligible guest email.');
            video_meeting_email_gate_claim_v1830($pdo,$meeting,(string)($_POST['email']??''));
            redirect(url('/meeting.php?meeting='.rawurlencode($publicId)));
        }catch(Throwable $e){$error=$e->getMessage();}
    }
}

try{
    $localStart=(new DateTimeImmutable((string)$meeting['start_at_utc'],new DateTimeZone('UTC')))->setTimezone(new DateTimeZone((string)$meeting['timezone']));
    $when=$localStart->format('D, M j · g:i A T');
}catch(Throwable $e){$when=(string)$meeting['start_at_utc'].' UTC';}
$closed=in_array((string)$meeting['status'],['cancelled','ended','processed'],true);
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="theme-color" content="#0b0b0c"><title><?= e((string)$meeting['title']) ?> | VP3 Meeting</title><link rel="stylesheet" href="<?= e(url('/video-meetings-v1800.css?v=1830')) ?>"></head>
<body class="vp3-meeting-room-page"><div class="video-meeting-shell"><div class="meeting-lobby"><div class="meeting-lobby-card">
<a class="video-meeting-brand" href="<?= e(url('/')) ?>"><span class="video-meeting-brand-mark"><i></i><i></i><i></i><i></i></span><span>VP3</span></a>
<div class="meeting-lobby-kicker" style="margin-top:24px">Guest meeting access</div><h1><?= e((string)$meeting['title']) ?></h1><div class="meeting-lobby-meta"><?= e($when) ?></div>
<?php if($closed): ?><div class="meeting-lobby-disclosure"><strong><?= (string)$meeting['status']==='cancelled'?'Meeting cancelled':'Meeting ended' ?></strong><br>This meeting is no longer accepting guest entry.</div>
<?php else: ?>
<div class="meeting-lobby-disclosure"><strong>Email verification required</strong><br>Enter the same email address the organizer invited. The public meeting link alone does not grant access.</div>
<?php if($error!==''): ?><div class="meeting-room-error" style="display:block"><?= e($error) ?></div><?php endif; ?>
<form method="post" class="meeting-lobby-form" autocomplete="off"><?= csrf_field() ?><input type="hidden" name="meeting" value="<?= e($publicId) ?>"><label><span>Invited email address</span><input type="email" name="email" maxlength="190" required autocomplete="email" placeholder="you@example.com"></label><button class="meeting-join-button" type="submit">Continue to meeting</button></form>
<?php if(!$user): ?><p class="meeting-lobby-meta" style="margin-top:16px">Have a VP3 account? <a href="<?= e(url('/login.php')) ?>">Sign in</a> and return to this link.</p><?php endif; ?>
<?php endif; ?>
</div></div></div></body></html>
