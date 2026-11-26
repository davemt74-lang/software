<?php
declare(strict_types=1);

require_once dirname(__DIR__).'/includes/bootstrap.php';
require_once dirname(__DIR__).'/includes/phone-tracking-http-v1.php';
require_once dirname(__DIR__).'/includes/phone-agent-email-calls-settings-v1.php';

vp3_phone_http_headers_v1();
try{
    vp3_phone_http_transport_v1();
    if(($_SERVER['REQUEST_METHOD']??'')!=='POST'){header('Allow: POST');throw new VP3PhoneAgentEmailCallException('invalid_request',405);}
    $phone=defined('VP3_PHONE_AGENT_EMAIL_CALLS_V1')&&VP3_PHONE_AGENT_EMAIL_CALLS_V1===true;
    $origin=$_SERVER['HTTP_ORIGIN']??'';
    $expected=(vp3_phone_http_secure_v1()?'https://':'http://').($_SERVER['HTTP_HOST']??'');
    vp3_phone_agent_email_calls_check_v1(($_SERVER[$phone?'HTTP_X_VP3_PHONE':'HTTP_X_VP3_EMAIL_CALLS']??'')==='1'
        &&($origin===''||strcasecmp($origin,$expected)===0)
        &&in_array($_SERVER['HTTP_SEC_FETCH_SITE']??'same-origin',['same-origin','none'],true),'owner_access_changed',403);
    $input=vp3_phone_http_json_v1();$pdo=db();
    vp3_phone_agent_email_calls_check_v1($pdo instanceof PDO,'upgrade_required',503);
    $result=vp3_phone_agent_email_calls_request_v1($pdo,current_user(),$input,
        $phone?(string)($_COOKIE['vp3_phone_device_v1']??''):null);
    vp3_phone_http_send_v1($result);
}catch(Throwable $error){
    $status=(int)$error->getCode();
    if(!($error instanceof RuntimeException)||$error instanceof PDOException||!in_array($status,[401,403,404,405,409,413,415,419,422,429,503],true))$status=503;
    $reason=$error instanceof VP3PhoneAgentEmailCallException?$error->reason:match($status){
        401=>'owner_sign_in_required',403=>'owner_access_changed',409=>'workspace_changed',419=>'session_changed',
        503=>'temporarily_unavailable',default=>'invalid_request'};
    http_response_code($status);
    echo json_encode(['ok'=>false,'error'=>$reason,'message'=>vp3_phone_agent_email_calls_message_v1($reason)],JSON_UNESCAPED_SLASHES);
}
