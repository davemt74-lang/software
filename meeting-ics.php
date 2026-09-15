<?php
declare(strict_types=1);
require __DIR__.'/includes/bootstrap.php';

$pdo=db();$invite=strtolower(trim((string)($_GET['invite']??'')));$user=current_user();
$access=$pdo?video_meeting_secure_access_v1800($pdo,$user,'',$invite):null;
$participant=$access['participant']??null;$meeting=$access['meeting']??null;
if(!$participant||!$meeting){http_response_code(404);exit('Meeting invitation not found.');}

$escape=static function(string $value): string {
    return str_replace(["\\","\r\n","\n","\r",",",";"],["\\\\","\\n","\\n","","\\,","\\;"],$value);
};
$start=(new DateTimeImmutable((string)$meeting['start_at_utc'],new DateTimeZone('UTC')))->format('Ymd\THis\Z');
$end=(new DateTimeImmutable((string)$meeting['end_at_utc'],new DateTimeZone('UTC')))->format('Ymd\THis\Z');
$stamp=gmdate('Ymd\THis\Z');
try{$join=video_meeting_secure_invite_url_v1801($participant);}catch(Throwable $e){http_response_code(503);exit('Meeting calendar links are not configured.');}
$status=(string)$meeting['status']==='cancelled'?'CANCELLED':'CONFIRMED';
$uid=$escape((string)$meeting['public_id'].'@vp3');
$description=trim((string)($meeting['description']??''));
if($description!=='')$description.="\n\n";$description.='Join VP3 video meeting: '.$join;
$ics="BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//VP3//Video Meetings//EN\r\nCALSCALE:GREGORIAN\r\nMETHOD:PUBLISH\r\nBEGIN:VEVENT\r\nUID:{$uid}\r\nDTSTAMP:{$stamp}\r\nDTSTART:{$start}\r\nDTEND:{$end}\r\nSUMMARY:".$escape((string)$meeting['title'])."\r\nDESCRIPTION:".$escape($description)."\r\nLOCATION:".$escape($join)."\r\nURL:".$escape($join)."\r\nSTATUS:{$status}\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n";
header('Content-Type: text/calendar; charset=utf-8');
header('Content-Disposition: attachment; filename="vp3-meeting.ics"');
header('Cache-Control: private, no-store');
header('Referrer-Policy: no-referrer');
echo $ics;
