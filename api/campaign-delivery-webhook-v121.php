<?php
declare(strict_types=1);

require dirname(__DIR__).'/includes/bootstrap.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if($_SERVER['REQUEST_METHOD']!=='POST'){http_response_code(405);echo json_encode(['ok'=>false,'error'=>'method_not_allowed']);exit;}
$pdo=db();if(!$pdo||!function_exists('campaigns_rewards_provider_webhook_v121')){http_response_code(503);echo json_encode(['ok'=>false,'error'=>'runtime_unavailable']);exit;}

$headers=[];foreach(getallheaders()?:[] as $key=>$value)$headers[strtolower((string)$key)]=(string)$value;
$raw=(string)file_get_contents('php://input');$provider=(string)($_GET['provider']??'');
$scheme=((string)($_SERVER['HTTP_X_FORWARDED_PROTO']??'')!=='')?(string)$_SERVER['HTTP_X_FORWARDED_PROTO']:(!empty($_SERVER['HTTPS'])&&$_SERVER['HTTPS']!=='off'?'https':'http');
$host=(string)($_SERVER['HTTP_HOST']??'');$requestUrl=$scheme.'://'.$host.(string)($_SERVER['REQUEST_URI']??'');
$configuredBase=rtrim((string)site_config('campaign_webhook_base_url',''),'/');
if($configuredBase!=='')$requestUrl=$configuredBase.url('/api/campaign-delivery-webhook-v121.php').'?'.http_build_query($_GET,'','&',PHP_QUERY_RFC3986);

try{
    $result=campaigns_rewards_provider_webhook_v121($pdo,$provider,$raw,$headers,$_POST,$requestUrl);
    echo json_encode(['ok'=>true,'processed'=>count($result)],JSON_UNESCAPED_SLASHES);
}catch(Throwable $e){
    http_response_code(401);echo json_encode(['ok'=>false,'error'=>'webhook_rejected'],JSON_UNESCAPED_SLASHES);
}
