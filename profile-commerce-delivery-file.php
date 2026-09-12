<?php
declare(strict_types=1);

require __DIR__.'/includes/bootstrap.php';
require_once __DIR__.'/includes/profile-commerce-lifecycle-v1100.php';
require_once __DIR__.'/includes/profile-commerce-delivery-file-v1130.php';

header('Cache-Control: no-store, private, max-age=0');
header('Pragma: no-cache');
header('Referrer-Policy: no-referrer');
header('X-Robots-Tag: noindex, nofollow, noarchive');
header('X-Content-Type-Options: nosniff');

$pdo=db();if(!$pdo||!profile_agent_schema_ready($pdo)||!agent_commerce_schema_ready_v800($pdo)){http_response_code(503);exit('Profile Commerce is not ready.');}
$username=profile_username_normalize((string)($_GET['username']??''));$orderNumber=trim((string)($_GET['order']??''));$receipt=strtolower(trim((string)($_GET['receipt']??'')));
$profile=profile_by_username($pdo,$username);if(!$profile||empty($profile['is_active'])){http_response_code(404);exit('File not found.');}
$order=profile_commerce_customer_order_v1100($pdo,(int)$profile['user_id'],$orderNumber,$receipt);if(!$order){http_response_code(404);exit('File not found.');}
$file=profile_commerce_delivery_file_for_customer_v1130($order);if(!$file){http_response_code(404);exit('File not found.');}
try{$path=profile_commerce_delivery_file_path_v1130((int)$profile['user_id'],(int)$order['id'],$file);}catch(Throwable $e){http_response_code(404);exit('File not found.');}
if(!is_file($path)||!is_readable($path)){http_response_code(404);exit('File not found.');}
$bytes=(int)filesize($path);if($bytes<1||$bytes!==(int)$file['bytes']){http_response_code(409);exit('Delivery file verification failed.');}

$name=(string)$file['original_name'];$fallback=preg_replace('/[^A-Za-z0-9._-]+/','_',basename($name))?:'download.'.$file['extension'];$fallback=substr($fallback,0,120);$encoded=rawurlencode($name);
header('Content-Type: '.(string)$file['mime']);
header('Content-Length: '.$bytes);
header('Content-Disposition: attachment; filename="'.$fallback.'"; filename*=UTF-8\'\''.$encoded);
header('Content-Security-Policy: default-src \'none\'; sandbox');
$handle=fopen($path,'rb');if($handle===false){http_response_code(404);exit('File not found.');}
fpassthru($handle);fclose($handle);exit;
