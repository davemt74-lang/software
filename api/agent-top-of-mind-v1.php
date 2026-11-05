<?php
declare(strict_types=1);
require dirname(__DIR__).'/includes/bootstrap.php';
require_once dirname(__DIR__).'/includes/agent-top-of-mind-v1.php';
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
try{
    if($_SERVER['REQUEST_METHOD']!=='GET')throw new RuntimeException('Use a status read.',405);
    $user=current_user();
    if(!$user||!has_permission('chat.access',$user))throw new RuntimeException('Agent access is unavailable.',403);
    $binding=(string)($_SERVER['HTTP_X_VP3_WORKING_ATTENTION']??'');
    if($binding===''||!hash_equals(vp3_catch_up_page_binding_v1($user),$binding))throw new RuntimeException('Your Agent session changed.',403);
    $agent=filter_var($_GET['agent_id']??'0',FILTER_VALIDATE_INT,['options'=>['min_range'=>0]]);
    $cid=filter_var($_GET['conversation_id']??'0',FILTER_VALIDATE_INT,['options'=>['min_range'=>0]]);
    if($agent===false||$cid===false)throw new RuntimeException('Choose an exact Agent and conversation.',422);
    if(session_status()===PHP_SESSION_ACTIVE)session_write_close();
    $state=vp3_top_of_mind_v1(db(),$user,$agent,$cid);
    $state['working_attention_binding']=$binding;
    echo json_encode(['ok'=>true,'state'=>$state],JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
}catch(Throwable $error){
    $status=in_array($error->getCode(),[403,405,409,422,503],true)?(int)$error->getCode():503;
    http_response_code($status);
    echo json_encode(['ok'=>false,'error'=>$status===403?'Your Agent session or access changed.':'Top of Mind could not be refreshed.'],JSON_UNESCAPED_SLASHES);
}
