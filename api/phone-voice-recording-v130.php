<?php
declare(strict_types=1);
// Recording completion metadata only; never download recordings on a webhook.
require dirname(__DIR__).'/includes/bootstrap.php';
require_once dirname(__DIR__).'/includes/phone-cloud-v100.php';
require_once dirname(__DIR__).'/includes/phone-sms-v110.php';
require_once dirname(__DIR__).'/includes/phone-voice-v130.php';

header('Cache-Control: no-store');
if (($_SERVER['REQUEST_METHOD']??'')!=='POST') {http_response_code(405);exit;}
if (!str_starts_with(strtolower((string)($_SERVER['CONTENT_TYPE']??'')),'application/x-www-form-urlencoded'))
    {http_response_code(415);exit;}
if ((int)($_SERVER['CONTENT_LENGTH']??0)>12000) {http_response_code(413);exit;}
$callback=vp3_phone_voice_callback_url_v130();
$provider=vp3_phone_twilio_state_v100();
$pdo=db();
if (!$pdo||!vp3_phone_voice_schema_ready_v130()||!$provider['configured']||$callback==='')
    {http_response_code(503);exit;}
try {
    $url=vp3_phone_voice_sibling_url_v130($callback,'phone-voice-recording-v130.php');
    vp3_phone_voice_recording_event_v130($pdo,$_POST,
        (string)($_SERVER['HTTP_X_TWILIO_SIGNATURE']??''),$url,
        vp3_phone_twilio_credentials_v100()[1],(string)$provider['account_sid']);
    http_response_code(204);
} catch(InvalidArgumentException|RuntimeException $error) {
    http_response_code(str_contains($error->getMessage(),'signature')
        ||str_contains($error->getMessage(),'account mismatch')?403:422);
} catch(Throwable) {http_response_code(503);}
