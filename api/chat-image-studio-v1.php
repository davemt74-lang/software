<?php
declare(strict_types=1);

require dirname(__DIR__).'/includes/bootstrap.php';
require_once dirname(__DIR__).'/includes/chat-image-studio-v1.php';

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: private, no-store');
header('X-Content-Type-Options: nosniff');
try{
    if(($_SERVER['REQUEST_METHOD']??'')!=='POST')throw new RuntimeException('Use POST.',405);
    $user=current_user();if(!$user)throw new RuntimeException('Sign in to use Image History.',401);
    $raw=file_get_contents('php://input',false,null,0,8193);
    if($raw===false||strlen($raw)>8192)throw new RuntimeException('The image studio request is too large.',413);
    $input=json_decode($raw,true,8,JSON_THROW_ON_ERROR);
    if(!is_array($input)||array_is_list($input))throw new InvalidArgumentException('Choose an image studio operation.');
    if(!is_string($input['csrf_token']??null)||!hash_equals(csrf_token(),$input['csrf_token']))throw new RuntimeException('Refresh Chat to restore this session.',419);
    $binding=(string)($_SERVER['HTTP_X_VP3_WORKING_ATTENTION']??'');
    if(($input['owner_key']??null)!==(string)$user['id']||$binding===''||!hash_equals(vp3_catch_up_page_binding_v1($user),$binding)){
        throw new RuntimeException('Your Agent session changed.',403);
    }
    $action=$input['action']??null;
    $fields=match($action){'history'=>['before'],'brainstorm'=>['request_id','source_revision','brief'],default=>throw new InvalidArgumentException('Choose an image studio operation.')};
    if(array_diff(array_keys($input),['action','owner_key','agent_id','conversation_id','csrf_token',...$fields])
        ||!is_int($input['agent_id']??null)||$input['agent_id']<0||!is_int($input['conversation_id']??null)||$input['conversation_id']<1){
        throw new InvalidArgumentException('Choose the current Agent and saved Chat.');
    }
    $pdo=db();if(!$pdo)throw new RuntimeException('Image history is unavailable.',503);
    $scope=vp3_agent_activity_scope_v1($pdo,$user,$input['agent_id'],$input['conversation_id']);
    if(session_status()===PHP_SESSION_ACTIVE)session_write_close();
    if($action==='history'){
        $before=$input['before']??null;
        if($before!==null&&(!is_int($before)||$before<1))throw new InvalidArgumentException('Choose a valid image history page.');
        $state=vp3_image_studio_state_v1($pdo,$scope,$before);
    }else{
        if(!is_string($input['request_id']??null)||!is_string($input['source_revision']??null)
            ||!is_string($input['brief']??null))throw new InvalidArgumentException('Choose a valid image brainstorm request.');
        vp3_image_studio_brainstorm_v1($pdo,$scope,$input['request_id'],$input['source_revision'],trim($input['brief']));
        $state=vp3_image_studio_state_v1($pdo,$scope);
        $state['brainstorm']=vp3_image_studio_brainstorm_view_v1(
            vp3_image_studio_brainstorm_row_v1($pdo,$scope,$input['request_id']),$state);
    }
    vp3_agent_activity_scope_v1($pdo,$user,$input['agent_id'],$input['conversation_id']);
    echo json_encode(['ok'=>true,'state'=>$state],JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
}catch(Throwable $error){
    $status=$error instanceof InvalidArgumentException||$error instanceof JsonException?422
        :(!$error instanceof PDOException&&in_array($error->getCode(),[401,403,404,405,409,413,419,503],true)?$error->getCode():503);
    http_response_code($status);
    $message=match($status){401=>'Sign in to use Image History.',403,404=>'Your Agent or Chat access changed.',
        405=>'Use POST for image studio requests.',409=>'The image context changed or a brainstorm is already running. Refresh the panel before continuing.',
        413=>'The image studio request is too large.',419=>'Refresh Chat to restore this session.',
        422=>'Choose a valid image studio request.',default=>'The image studio could not complete this request. Refresh its status before trying again.'};
    echo json_encode(['ok'=>false,'error'=>$message],JSON_UNESCAPED_SLASHES);
}
