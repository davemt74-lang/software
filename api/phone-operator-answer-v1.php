<?php
declare(strict_types=1);
require dirname(__DIR__).'/includes/bootstrap.php';
require_once dirname(__DIR__).'/includes/agent-operator-answer-v1.php';
header('Cache-Control: private, no-store');
header('Content-Type: application/xml; charset=UTF-8');
if(($_SERVER['REQUEST_METHOD']??'')!=='POST'){http_response_code(405);return;}
if(strtolower(trim(explode(';',$_SERVER['CONTENT_TYPE']??'')[0]))!=='application/x-www-form-urlencoded'){http_response_code(415);return;}
$event=$_GET['event']??null;
if(!is_string($event)||($_SERVER['QUERY_STRING']??'')!=='event='.rawurlencode($event)){http_response_code(422);return;}
if((int)($_SERVER['CONTENT_LENGTH']??0)>12000||count($_POST)>60){http_response_code(413);return;}
$bytes=0;
foreach($_POST as $key=>$value){
    if(!is_string($value)||strlen((string)$key)>100||strlen($value)>3000){http_response_code(422);return;}
    $bytes+=strlen((string)$key)+strlen($value);
}
if($bytes>12000){http_response_code(413);return;}
try{
    $response=vp3_operator_answer_v1(db(),$event,$_POST,(string)($_SERVER['HTTP_X_TWILIO_SIGNATURE']??''),time());
    if(in_array($event,['status','recording'],true))http_response_code(204);
    else echo $response;
}catch(InvalidArgumentException){http_response_code(422);}
catch(RuntimeException $e){
    http_response_code(in_array($e->getMessage(),['Invalid provider signature.','Provider account mismatch.'],true)?403:503);
}catch(Throwable){http_response_code(503);}
