<?php
declare(strict_types=1);
// Authenticated owner-only voicemail stream. Recording bytes are not saved in
// Cloud storage or delivered to an LLM. Twilio credentials remain server-side.
require dirname(__DIR__).'/includes/bootstrap.php';
require_once dirname(__DIR__).'/includes/phone-cloud-v100.php';
require_once dirname(__DIR__).'/includes/phone-voice-v130.php';
require_permission('account.access');
$user=current_user();
if (!$user||!has_permission('chat.access',$user)) {http_response_code(403);exit;}
if (($_SERVER['REQUEST_METHOD']??'')!=='GET') {http_response_code(405);exit;}
header('Cache-Control: private, no-store');
header('X-Content-Type-Options: nosniff');
$recordId=(int)($_GET['id']??0);
$pdo=db();
if (!$pdo||!vp3_phone_voice_schema_ready_v130()||$recordId<1) {http_response_code(404);exit;}
$q=$pdo->prepare('SELECT provider_recording_sid FROM phone_voice_recordings_v130
  WHERE id=? AND owner_user_id=? AND provider=\'twilio\' AND recording_status=\'completed\' LIMIT 1');
$q->execute([$recordId,(int)$user['id']]);
$sid=(string)($q->fetchColumn()?:'');
if (!preg_match('/^RE[a-f0-9]{32}$/iD',$sid)) {http_response_code(404);exit;}
if (!function_exists('curl_init')) {http_response_code(503);exit;}
try {
    [$account,$token]=vp3_phone_twilio_credentials_v100();
    $url='https://api.twilio.com/2010-04-01/Accounts/'.$account.'/Recordings/'.$sid.'.mp3';
    $ch=curl_init($url);
    if ($ch===false) throw new RuntimeException('Transport unavailable.');
    $audio='';$maxBytes=10*1024*1024;
    curl_setopt_array($ch,[
        CURLOPT_FOLLOWLOCATION=>true,CURLOPT_MAXREDIRS=>3,
        CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_REDIR_PROTOCOLS=>CURLPROTO_HTTPS,
        CURLOPT_CONNECTTIMEOUT=>5,CURLOPT_TIMEOUT=>20,
        CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,
        CURLOPT_USERPWD=>$account.':'.$token,CURLOPT_HTTPAUTH=>CURLAUTH_BASIC,
        CURLOPT_WRITEFUNCTION=>static function($handle,string $chunk) use (&$audio,$maxBytes): int {
            if (strlen($audio)+strlen($chunk)>$maxBytes) return 0;
            $audio.=$chunk;return strlen($chunk);
        },
    ]);
    $ok=curl_exec($ch);$status=(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE);
    $mime=(string)curl_getinfo($ch,CURLINFO_CONTENT_TYPE);curl_close($ch);
    if (!$ok||$status!==200||!str_starts_with(strtolower($mime),'audio/')
        ||strlen($audio)<4) throw new RuntimeException('Recording unavailable.');
    header('Content-Type: audio/mpeg');
    header('Content-Length: '.strlen($audio));
    echo $audio;
} catch(Throwable) {http_response_code(503);}
