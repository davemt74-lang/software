<?php
declare(strict_types=1);
require dirname(__DIR__).'/includes/bootstrap.php';
require_once dirname(__DIR__).'/includes/agent-phone-shell-v1.php';
header('Content-Type: application/json; charset=UTF-8');header('Cache-Control: no-store, private');header('X-Content-Type-Options: nosniff');
try{
    if(($_SERVER['REQUEST_METHOD']??'')!=='POST'){http_response_code(405);header('Allow: POST');throw new RuntimeException('Use POST.',405);}
    if(!str_starts_with(strtolower((string)($_SERVER['CONTENT_TYPE']??'')),'application/json'))throw new RuntimeException('Use JSON.',415);
    $raw=file_get_contents('php://input',false,null,0,4097);if($raw===false||strlen($raw)>4096)throw new RuntimeException('Request is too large.',413);
    $input=json_decode($raw,true,16,JSON_THROW_ON_ERROR);if(!is_array($input)||array_is_list($input)||array_diff(array_keys($input),['action','csrf_token','agent_id','create']))throw new RuntimeException('Invalid request.',422);
    $user=current_user();$pdo=db();if(!$user||!$pdo)throw new RuntimeException('Sign in to your account.',403);
    if(!is_string($input['csrf_token']??null)||!hash_equals(csrf_token(),$input['csrf_token']))throw new RuntimeException('Refresh your session.',419);
    if(!vp3_phone_chat_page_v1($user)||vp3_participant_scope_v1()!==null||vp3_participant_chat_actor_v1($pdo,$user,[])!==null)throw new RuntimeException('Private account access required.',403);
    $action=$input['action']??'';
    if($action==='phone_context'){
        if(!is_bool($input['create']??false))throw new RuntimeException('Invalid context request.',422);
        $aid=vp3_phone_workspace_id_v1($input['agent_id']??0,true);
        $result=['context'=>vp3_agent_phone_shell_context_v1($pdo,$user,$aid,$input['create']??false)];
    }elseif($action==='activity'){
        if(array_diff(array_keys($input),['action','csrf_token']))throw new RuntimeException('Invalid activity request.',422);
        $result=['updates'=>vp3_agent_phone_shell_activity_v1($pdo,$user)];
    }
    else throw new RuntimeException('Unknown action.',422);
    echo json_encode(['ok'=>true,'owner_user_id'=>(int)$user['id']]+$result,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES);
}catch(Throwable $error){$status=$error instanceof VP3PhoneWorkspaceException?$error->httpStatus:(in_array($error->getCode(),[403,405,409,413,415,419,422,503],true)?$error->getCode():503);http_response_code($status);echo json_encode(['ok'=>false,'error'=>$status===503?'Phone needs its database upgrade or is temporarily unavailable.':'Phone access could not be confirmed.']);}
