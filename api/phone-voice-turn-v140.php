<?php
declare(strict_types=1);
// PH3F: Twilio speech callback. Signed provider event is not account login.
require dirname(__DIR__).'/includes/bootstrap.php';
require_once dirname(__DIR__).'/includes/phone-cloud-v100.php';
require_once dirname(__DIR__).'/includes/phone-sms-v110.php';
require_once dirname(__DIR__).'/includes/phone-voice-v130.php';
require_once dirname(__DIR__).'/includes/phone-voice-conversation-v140.php';

header('Cache-Control: no-store');
header('Content-Type: application/xml; charset=UTF-8');
if (($_SERVER['REQUEST_METHOD']??'')!=='POST') {http_response_code(405);exit;}
if (!str_starts_with(strtolower((string)($_SERVER['CONTENT_TYPE']??'')),'application/x-www-form-urlencoded'))
    {http_response_code(415);exit;}
if ((int)($_SERVER['CONTENT_LENGTH']??0)>12000) {http_response_code(413);exit;}
$rawStep=$_GET['step']??'';
if (!is_string($rawStep)||!preg_match('/^[1-8]$/D',$rawStep)) {http_response_code(422);exit;}
$callback=vp3_phone_voice_callback_url_v130();
$provider=vp3_phone_twilio_state_v100();
$pdo=db();
if (!$pdo||!vp3_phone_voice_schema_ready_v130()||!vp3_phone_voice_ready_v140()
    ||!$provider['configured']||$callback==='') {http_response_code(503);exit;}
try {
    $event=vp3_phone_voice_turn_v140($pdo,$_POST,
        (string)($_SERVER['HTTP_X_TWILIO_SIGNATURE']??''),$callback,
        vp3_phone_twilio_credentials_v100()[1],(string)$provider['account_sid'],(int)$rawStep);
    http_response_code($event['accepted']?200:403);
    echo $event['accepted']?(string)$event['twiml']
        :'<?xml version="1.0" encoding="UTF-8"?><Response><Hangup/></Response>';
} catch(InvalidArgumentException|RuntimeException $error) {
    http_response_code(str_contains($error->getMessage(),'signature')
        ||str_contains($error->getMessage(),'account mismatch')?403:422);
} catch(Throwable $error) {
    error_log('PH3F phone callback failed: '.get_class($error));
    http_response_code(503);
}
