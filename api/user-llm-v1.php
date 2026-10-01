<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/includes/bootstrap.php';
require_once dirname(__DIR__).'/includes/ai-settings.php';
require_once dirname(__DIR__).'/includes/user-llm-v1.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private');
header('X-Content-Type-Options: nosniff');
function vp3_llm_json(array $value,int $status=200): never
{ http_response_code($status);echo json_encode($value,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);exit; }
$user=current_user();
if(!$user||(int)($user['id']??0)<1)vp3_llm_json(['ok'=>false,'error'=>'login_required'],401);
if(!has_permission('account.access',$user))vp3_llm_json(['ok'=>false,'error'=>'forbidden'],403);
try {
    $pdo=db();if(!$pdo)throw new RuntimeException('Database unavailable.');
    // A missing table must not be silently treated as successful credential storage.
    vp3_user_llm_v1_schema($pdo);
    if($_SERVER['REQUEST_METHOD']==='GET'){
        $state=vp3_user_llm_v1_read($pdo,(int)$user['id']);
        vp3_llm_json(['ok'=>true,'state'=>$state,
            'homeserver_credentials'=>'local_only',
            'system_funded_tokens'=>'subscription_gated',
            'user_keys_transmitted_to_homeserver'=>false]);
    }
    if($_SERVER['REQUEST_METHOD']!=='POST')vp3_llm_json(['ok'=>false,'error'=>'method_not_allowed'],405);
    $data=json_decode((string)file_get_contents('php://input'),true);
    if(!is_array($data))vp3_llm_json(['ok'=>false,'error'=>'invalid_json'],400);
    $csrf=(string)($data['csrf_token']??'');
    if($csrf===''||!hash_equals(csrf_token(),$csrf))vp3_llm_json(['ok'=>false,'error'=>'invalid_csrf'],419);
    $action=(string)($data['action']??'');
    $provider=(string)($data['provider']??'');
    if($action==='save'){
        $state=vp3_user_llm_v1_save($pdo,$user,$provider,(string)($data['api_key']??''),(string)($data['model']??''));
    }elseif($action==='select'){
        $state=vp3_user_llm_v1_select($pdo,$user,(string)($data['route']??''),$provider);
    }elseif($action==='remove'){
        $state=vp3_user_llm_v1_remove($pdo,$user,$provider);
    }else vp3_llm_json(['ok'=>false,'error'=>'unknown_action'],400);
    vp3_llm_json(['ok'=>true,'state'=>$state]);
}catch(Throwable $e){
    error_log('VP3 user LLM settings: '.get_class($e));
    $safe=($e instanceof RuntimeException||$e instanceof InvalidArgumentException)
        ?mb_strimwidth($e->getMessage(),0,180,'…'):'Provider settings unavailable.';
    vp3_llm_json(['ok'=>false,'error'=>$safe],400);
}
