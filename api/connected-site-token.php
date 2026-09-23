<?php
declare(strict_types=1);
require dirname(__DIR__).'/includes/bootstrap.php';
header('Content-Type: application/json; charset=utf-8');header('Cache-Control: no-store');
$pdo=db();try{
 if(!$pdo)throw new RuntimeException('Database unavailable.');$client=(string)($_POST['client_id']??'');$secret=(string)($_POST['client_secret']??'');$app=vp3_connected_site_app_v100($client);
 if(!$app||strlen((string)$app['client_secret'])<32||!hash_equals((string)$app['client_secret'],$secret))throw new RuntimeException('Connected-site client authentication failed.');
 $grant=(string)($_POST['grant_type']??'authorization_code');
 if($grant==='authorization_code')$result=vp3_connected_site_exchange_code_v100($pdo,$app,(string)($_POST['code']??''),(string)($_POST['redirect_uri']??''));
 elseif($grant==='refresh_token')$result=vp3_connected_site_refresh_v100($pdo,$app,(string)($_POST['refresh_token']??''));
 else throw new RuntimeException('Unsupported grant type.');
 echo json_encode($result,JSON_UNESCAPED_SLASHES);
}catch(Throwable $e){http_response_code(400);echo json_encode(['error'=>'invalid_grant','error_description'=>$e->getMessage()],JSON_UNESCAPED_SLASHES);}
