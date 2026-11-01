<?php
declare(strict_types=1);
// Signed Twilio <Dial action> callback: hang up or offer voicemail after failed SIP.
require dirname(__DIR__).'/includes/bootstrap.php';
require_once dirname(__DIR__).'/includes/phone-cloud-v100.php';
require_once dirname(__DIR__).'/includes/phone-sms-v110.php';
require_once dirname(__DIR__).'/includes/phone-voice-v130.php';
require_once dirname(__DIR__).'/includes/phone-voice-stream-v150.php';

header('Cache-Control: no-store');
header('Content-Type: application/xml; charset=UTF-8');
if (($_SERVER['REQUEST_METHOD']??'')!=='POST') {http_response_code(405);exit;}
if (!str_starts_with(strtolower((string)($_SERVER['CONTENT_TYPE']??'')),'application/x-www-form-urlencoded'))
    {http_response_code(415);exit;}
if ((int)($_SERVER['CONTENT_LENGTH']??0)>12000) {http_response_code(413);exit;}
$callback=vp3_phone_voice_callback_url_v130();
$provider=vp3_phone_twilio_state_v100();$pdo=db();
if (!$pdo||!vp3_phone_stream_ready_v150()||!$provider['configured']||$callback==='')
    {http_response_code(503);exit;}
try {
    $result=vp3_phone_stream_complete_v150($pdo,$_POST,
        (string)($_SERVER['HTTP_X_TWILIO_SIGNATURE']??''),$callback,
        vp3_phone_twilio_credentials_v100()[1],(string)$provider['account_sid']);
    http_response_code(200);
    echo $result['accepted'] ? (string)$result['twiml']
       : '<?xml version="1.0" encoding="UTF-8"?><Response><Hangup/></Response>';
} catch (InvalidArgumentException|RuntimeException $error) {
    http_response_code(str_contains($error->getMessage(),'signature')
      ||str_contains($error->getMessage(),'account mismatch')?403:422);
} catch (Throwable) {http_response_code(503);}
