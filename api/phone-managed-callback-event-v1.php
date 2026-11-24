<?php
declare(strict_types=1);

/** Public carrier boundary only. A browser session grants no carrier authority. */
require dirname(__DIR__).'/includes/bootstrap.php';
require_once dirname(__DIR__).'/includes/phone-managed-transport-v1.php';
header('Cache-Control: private, no-store');
header('X-Content-Type-Options: nosniff');
if(($_SERVER['REQUEST_METHOD']??'')!=='POST'){http_response_code(405);return;}
if(!str_starts_with(strtolower((string)($_SERVER['CONTENT_TYPE']??'')),'application/x-www-form-urlencoded')){http_response_code(415);return;}
if((int)($_SERVER['CONTENT_LENGTH']??0)>12000||count($_POST)>60){http_response_code(413);return;}
$bytes=0;
foreach($_POST as $key=>$value){
    if(!is_string($value)||strlen((string)$key)>100||strlen($value)>3000){http_response_code(422);return;}
    $bytes+=strlen((string)$key)+strlen($value);
}
if($bytes>12000){http_response_code(413);return;}
$event=$_GET['event']??null;$call=$_GET['call']??null;$nonce=$_GET['nonce']??null;
if(!is_string($event)||!in_array($event,['answer','confirm','parent','child','complete','record_notice','record_consent','recording','native_pin'],true)||!is_string($call)||!is_string($nonce)){http_response_code(422);return;}
$xml=in_array($event,['answer','confirm','complete','record_notice','record_consent','native_pin'],true);
if($xml)header('Content-Type: application/xml; charset=UTF-8');
$pdo=db();
if(!$pdo||!vp3_phone_workspace_schema_ready_v1($pdo)||!vp3_managed_transport_ready_v1($pdo)||!vp3_phone_twilio_state_v100()['configured']){http_response_code(503);return;}
try{
    $result=vp3_managed_transport_event_v1($pdo,$event,$call,$nonce,$_POST,(string)($_SERVER['HTTP_X_TWILIO_SIGNATURE']??''),time());
    if($xml){http_response_code(200);echo $result['xml'];}else http_response_code(204);
}catch(InvalidArgumentException|RuntimeException $error){
    $message=$error->getMessage();
    http_response_code(str_contains($message,'signature')||str_contains($message,'account mismatch')?403:422);
}catch(Throwable){http_response_code(503);}
