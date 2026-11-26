<?php
declare(strict_types=1);

require dirname(__DIR__).'/includes/bootstrap.php';
require_once dirname(__DIR__).'/includes/phone-tracking-http-v1.php';
require_once dirname(__DIR__).'/includes/phone-tracking-v1.php';

vp3_phone_http_headers_v1();
if(session_status()===PHP_SESSION_ACTIVE)session_write_close();
try{
    vp3_phone_http_transport_v1();
    if(($_SERVER['REQUEST_METHOD']??'')!=='POST'){
        header('Allow: POST');throw new RuntimeException('Method not allowed.',405);
    }
    $origin=$_SERVER['HTTP_ORIGIN']??'';
    $expected=(vp3_phone_http_secure_v1()?'https://':'http://').($_SERVER['HTTP_HOST']??'');
    if(($_SERVER['HTTP_X_VP3_PHONE']??'')!=='1'||($origin!==''&&strcasecmp($origin,$expected)!==0)
        ||!in_array($_SERVER['HTTP_SEC_FETCH_SITE']??'same-origin',['same-origin','none'],true))throw new RuntimeException('Invalid origin.',403);
    $input=vp3_phone_http_json_v1();
    $pdo=db();
    if(!$pdo||!vp3_phone_push_delivery_ready_v1($pdo))throw new RuntimeException('Upgrade required.',503);
    // An event capability can only say that showNotification completed. It
    // cannot open a source, authenticate a pairing, or change any subscription.
    vp3_phone_push_delivery_receipt_v1($pdo,$input,time());
    vp3_phone_http_send_v1([]);
}catch(Throwable $error){vp3_phone_http_error_v1($error);}
