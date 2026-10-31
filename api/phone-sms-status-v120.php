<?php
declare(strict_types=1);
require dirname(__DIR__).'/includes/bootstrap.php';
require_once dirname(__DIR__).'/includes/phone-cloud-v100.php';
require_once dirname(__DIR__).'/includes/phone-sms-v110.php';
require_once dirname(__DIR__).'/includes/phone-sms-outbound-v120.php';

header('Cache-Control: no-store');
header('Content-Type: application/xml; charset=UTF-8');
if (($_SERVER['REQUEST_METHOD']??'')!=='POST') {http_response_code(405);exit;}
if (!str_starts_with(strtolower((string)($_SERVER['CONTENT_TYPE']??'')),
        'application/x-www-form-urlencoded')) {http_response_code(415);exit;}
if ((int)($_SERVER['CONTENT_LENGTH']??0)>12000) {http_response_code(413);exit;}
$pdo=db();$state=vp3_phone_twilio_state_v100();$url=vp3_phone_sms_status_url_v120();
if (!$pdo||!vp3_phone_sms_outbound_ready_v120()||!$state['configured']||$url==='') {
    http_response_code(503);exit;
}
try {
    vp3_phone_sms_delivery_event_v120($pdo,$_POST,
        (string)($_SERVER['HTTP_X_TWILIO_SIGNATURE']??''),$url,
        vp3_phone_twilio_credentials_v100()[1],(string)$state['account_sid']);
    http_response_code(200);echo '<?xml version="1.0" encoding="UTF-8"?><Response></Response>';
} catch(RuntimeException $error) {
    http_response_code(str_contains($error->getMessage(),'signature')||str_contains($error->getMessage(),'account mismatch')?403:503);
} catch(Throwable) {http_response_code(503);}
