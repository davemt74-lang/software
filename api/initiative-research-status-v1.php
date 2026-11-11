<?php
declare(strict_types=1);

require dirname(__DIR__).'/includes/bootstrap.php';
require_once dirname(__DIR__).'/includes/agent-initiative-research-status-v1.php';

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
try{
    if(($_SERVER['REQUEST_METHOD']??'')!=='GET'){
        header('Allow: GET');
        throw new RuntimeException('Use a status read.',405);
    }
    $user=current_user();
    if(!$user||!has_permission('chat.access',$user))throw new RuntimeException('Agent access is unavailable.',403);
    $binding=(string)($_SERVER['HTTP_X_VP3_WORKING_ATTENTION']??'');
    if($binding===''||!hash_equals(vp3_catch_up_page_binding_v1($user),$binding))throw new RuntimeException('Your Agent session changed.',403);
    $rawAgent=$_GET['agent_id']??null;$rawChat=$_GET['conversation_id']??null;
    if(!is_string($rawAgent)||!preg_match('/\A(?:0|[1-9][0-9]{0,17})\z/D',$rawAgent)
        ||!is_string($rawChat)||!preg_match('/\A[1-9][0-9]{0,17}\z/D',$rawChat)
        ||filter_var($rawAgent,FILTER_VALIDATE_INT)===false||filter_var($rawChat,FILTER_VALIDATE_INT)===false){
        throw new RuntimeException('Choose an exact Agent and conversation.',422);
    }
    if(session_status()===PHP_SESSION_ACTIVE)session_write_close();
    $state=vp3_initiative_research_status_v1(db(),$user,(int)$rawAgent,(int)$rawChat);
    if(($_GET['view']??null)!=='assignments')unset($state['specialists']);
    echo json_encode(['ok'=>true,'working_attention_binding'=>$binding]+$state,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);
}catch(Throwable $error){
    $status=in_array($error->getCode(),[403,405,409,422,503],true)?(int)$error->getCode():503;
    http_response_code($status);
    echo json_encode(['ok'=>false,'error'=>$status===403?'Your Agent session or access changed.':'Research status could not be refreshed.']);
}
