<?php
declare(strict_types=1);
require dirname(__DIR__).'/includes/bootstrap.php';
require_once dirname(__DIR__).'/includes/music-analytics-v1.php';

header('Cache-Control: no-store, private, no-transform');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header('Content-Type: application/json; charset=utf-8');
$correlation=bin2hex(random_bytes(12));
try{
    if(($_SERVER['REQUEST_METHOD']??'GET')!=='POST'){
        header('Allow: POST');music_analytics_v1_error('method_not_allowed',405,'Use a JSON report request.');
    }
    $user=current_user();if(!$user)music_analytics_v1_error('authentication_required',401,'Sign in to view music analytics.');
    if($_GET)music_analytics_v1_error('invalid_request',422,'Send report selectors in the JSON request.');
    if(!preg_match('#^application/json(?:\s*;|$)#i',(string)($_SERVER['CONTENT_TYPE']??'')))music_analytics_v1_error('invalid_content_type',415,'Send a JSON report request.');
    $length=$_SERVER['CONTENT_LENGTH']??'';
    if(!is_scalar($length)||($length!==''&&(!ctype_digit((string)$length)||(float)$length>8192)))music_analytics_v1_error('request_too_large',413,'The report request is too large.');
    $body=file_get_contents('php://input',false,null,0,8193);
    if($body===false||strlen($body)>8192)music_analytics_v1_error('request_too_large',413,'The report request is too large.');
    try{$input=json_decode($body,true,16,JSON_THROW_ON_ERROR);}catch(JsonException){music_analytics_v1_error('invalid_request',422,'The report request is invalid.');}
    if(!is_array($input)||array_is_list($input))music_analytics_v1_error('invalid_request',422,'Send a JSON report object.');
    $_POST=$input;if(!verify_csrf())music_analytics_v1_error('session_changed',419,'Your session changed. Refresh before viewing analytics.');
    if(session_status()===PHP_SESSION_ACTIVE)session_write_close();
    $pdo=db();if(!$pdo)music_analytics_v1_error('temporarily_unavailable',503,'Music reporting is temporarily unavailable.');
    $result=music_analytics_v1_report($pdo,$user,$input);
    echo json_encode($result+['correlation_id'=>$correlation],JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
}catch(Throwable $error){
    $known=$error instanceof MusicAnalyticsV1Exception;$status=$known?$error->httpStatus:503;
    if(!$known)error_log('Music analytics unavailable; reference '.$correlation.'.');
    http_response_code($status);
    echo json_encode(['ok'=>false,'protocol'=>MUSIC_ANALYTICS_V1_PROTOCOL,'code'=>$known?$error->publicCode:'temporarily_unavailable',
        'message'=>$known?$error->getMessage():'Music reporting is temporarily unavailable. Please retry.','retryable'=>$status===503,'correlation_id'=>$correlation],JSON_THROW_ON_ERROR);
}
