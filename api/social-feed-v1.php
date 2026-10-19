<?php
declare(strict_types=1);
require dirname(__DIR__).'/includes/bootstrap.php';
require_once dirname(__DIR__).'/includes/homeserver-https-relay-v1300.php';
require_once dirname(__DIR__).'/includes/social-feed-v1.php';
header('Content-Type: application/json; charset=utf-8');header('Cache-Control: private, no-store');header('X-Content-Type-Options: nosniff');
try{
    if(($_SERVER['REQUEST_METHOD']??'')!=='POST')throw new RuntimeException('POST required.',405);
    $raw=(string)file_get_contents('php://input',false,null,0,65537);if(strlen($raw)>65536)throw new RuntimeException('Post request is too large.',413);
    $input=json_decode($raw,true,16,JSON_THROW_ON_ERROR);if(!is_array($input)||array_is_list($input))throw new InvalidArgumentException('A Feed request is required.');
    $pdo=db();if(!$pdo)throw new RuntimeException('Feed storage is unavailable.',503);
    $device=trim((string)($_SERVER['HTTP_X_HOMESERVER_DEVICE']??''));$auth=(string)($_SERVER['HTTP_AUTHORIZATION']??($_SERVER['REDIRECT_HTTP_AUTHORIZATION']??''));$session=null;
    if($device!==''){
        if(!preg_match('/^Bearer\s+(.+)$/i',$auth,$m))throw new RuntimeException('Reconnect HomeServer.',401);
        try{$session=homeserver_https_v1300_authenticate(trim($m[1]),$device);}catch(Throwable $e){throw new RuntimeException('Reconnect HomeServer.',401);}
        $uid=(int)$session['user_id'];
    }else{
        $user=current_user();if(!$user)throw new RuntimeException('Sign in to use Feed.',401);
        if(!hash_equals(csrf_token(),(string)($input['csrf_token']??'')))throw new RuntimeException('Refresh your session.',419);$uid=(int)$user['id'];
    }
    $q=$pdo->prepare('SELECT id,display_name,is_active FROM users WHERE id=? AND is_active=1');$q->execute([$uid]);$user=$q->fetch(PDO::FETCH_ASSOC);if(!$user)throw new RuntimeException('Account is unavailable.',401);
    social_feed_schema_v1($pdo);
    $recheck=static function()use($pdo,$uid,$session):void{
        $q=$pdo->prepare('SELECT is_active FROM users WHERE id=?');$q->execute([$uid]);if(!$q->fetchColumn())throw new RuntimeException('Account is unavailable.',401);
        if($session){$q=$pdo->prepare('SELECT status,device_id,session_token_hash FROM homeserver_https_sessions WHERE user_id=? FOR UPDATE');$q->execute([$uid]);$r=$q->fetch(PDO::FETCH_ASSOC);if(!$r||$r['status']!=='active'||!hash_equals($r['device_id'],$session['device_id'])||!hash_equals($r['session_token_hash'],$session['session_token_hash']))throw new RuntimeException('HomeServer pairing changed.',401);}
    };
    $result=social_feed_dispatch_v1($pdo,$user,$input,$recheck);$recheck();
    echo json_encode(['ok'=>true,'contract'=>VP3_SOCIAL_FEED_V1,'viewer_id'=>$uid]+$result,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
}catch(Throwable $e){
    $status=$e instanceof InvalidArgumentException||$e instanceof JsonException?422:(int)$e->getCode();if(!in_array($status,[401,403,404,405,409,413,419,422,429],true))$status=503;
    http_response_code($status);echo json_encode(['ok'=>false,'error'=>$status===503?'Feed is temporarily unavailable.':$e->getMessage()]);
}
