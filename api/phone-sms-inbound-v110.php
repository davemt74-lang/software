<?php
declare(strict_types=1);

// Public carrier callback. Authentication is the Twilio signature, not a user
// session, API token, sender phone number, or CSRF exemption.
require dirname(__DIR__).'/includes/bootstrap.php';
require_once dirname(__DIR__).'/includes/phone-cloud-v100.php';
require_once dirname(__DIR__).'/includes/phone-sms-v110.php';

header('Cache-Control: no-store');
header('Content-Type: application/xml; charset=UTF-8');
if (($_SERVER['REQUEST_METHOD']??'')!=='POST') {http_response_code(405);exit;}
$contentType=strtolower((string)($_SERVER['CONTENT_TYPE']??''));
if (!str_starts_with($contentType,'application/x-www-form-urlencoded')) {http_response_code(415);exit;}
if ((int)($_SERVER['CONTENT_LENGTH']??0)>12000) {http_response_code(413);exit;}

$callback=trim((string)setting('phone_sms_webhook_url_v110',''));
$provider=vp3_phone_twilio_state_v100();
$signature=(string)($_SERVER['HTTP_X_TWILIO_SIGNATURE']??'');
$pdo=db();
if (!$pdo || !vp3_phone_sms_schema_ready_v110() || !$provider['configured'] || $callback==='') {
    http_response_code(503);exit;
}
try {
    $token=vp3_phone_twilio_credentials_v100()[1];
    $result=vp3_phone_sms_ingest_v110($pdo,$_POST,$signature,$callback,$token,(string)$provider['account_sid']);
    // Valid Twilio traffic sent to an inactive/unassigned number is intentionally
    // acknowledged without recording or leaking the owner of that number.
    http_response_code(200);
    echo '<?xml version="1.0" encoding="UTF-8"?><Response></Response>';
} catch (InvalidArgumentException|RuntimeException $error) {
    http_response_code(str_contains($error->getMessage(),'signature')||str_contains($error->getMessage(),'account mismatch')?403:422);
} catch (Throwable $error) {
    // A transient storage error must be retryable; never echo message contents.
    http_response_code(503);
}
