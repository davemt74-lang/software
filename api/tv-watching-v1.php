<?php
declare(strict_types=1);
require dirname(__DIR__).'/includes/bootstrap.php';
require_once dirname(__DIR__).'/includes/tv-watching-v1.php';
require_once dirname(__DIR__).'/includes/extension-device-auth-v2001.php';
require_once dirname(__DIR__).'/includes/homeserver-https-relay-v1300.php';
header('Content-Type: application/json; charset=UTF-8');header('Cache-Control: private, no-store');header('X-Content-Type-Options: nosniff');
try{
    if(($_SERVER['REQUEST_METHOD']??'')!=='POST')throw new RuntimeException('POST required.',405);
    $raw=file_get_contents('php://input',false,null,0,900001);if(strlen($raw)>900000)throw new RuntimeException('TV request is too large.',413);
    $input=json_decode($raw,true,24,JSON_THROW_ON_ERROR);if(!is_array($input)||array_is_list($input))throw new InvalidArgumentException('Use a TV request object.');
    $pdo=db();if(!$pdo||!table_exists('tv_watching_records_v1')||!table_exists('tv_watching_state_v1'))throw new RuntimeException('Run upgrade.php to enable TV Watching.',409);
    if(trim((string)($_SERVER['HTTP_X_HOMESERVER_DEVICE']??''))!==''){
        $auth=(string)($_SERVER['HTTP_AUTHORIZATION']??($_SERVER['REDIRECT_HTTP_AUTHORIZATION']??''));
        if(!preg_match('/^Bearer\s+(.+)$/i',$auth,$m))throw new RuntimeException('HomeServer authentication required.',401);
        $session=homeserver_https_v1300_authenticate(trim($m[1]),trim((string)$_SERVER['HTTP_X_HOMESERVER_DEVICE']));
        $user=vp3_extension_user_for_permission_v2001($pdo,(int)$session['user_id']);
    }else{
        $user=current_user();if(!$user)throw new RuntimeException('Sign in to use TV Watching.',403);
        if(!hash_equals(csrf_token(),(string)($input['csrf_token']??'')))throw new RuntimeException('Refresh the current TV session.',419);
    }
    if(!$user||!has_permission('chat.access',$user))throw new RuntimeException('Agent Chat access required.',403);
    if(isset($input['account_binding'])&&$input['account_binding']!=='cloud:'.(int)$user['id'])throw new RuntimeException('Cloud account changed. Refresh TV settings.',409);
    echo json_encode(['ok'=>true,'contract'=>VP3_TV_CONTRACT,'account_binding'=>'cloud:'.(int)$user['id']]+tv_dispatch_v1($pdo,$user,$input),JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
}catch(Throwable $e){
    $code=(int)$e->getCode();$status=in_array($code,[401,403,404,405,409,410,413,419,429,502,503],true)?$code:422;
    if($e instanceof PDOException||$e instanceof JsonException)$status=503;
    http_response_code($status);echo json_encode(['ok'=>false,'error'=>($e instanceof PDOException||$e instanceof JsonException)?'TV Watching is unavailable or returned invalid data.':$e->getMessage()]);
}
