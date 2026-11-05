<?php
declare(strict_types=1);
require dirname(__DIR__).'/includes/bootstrap.php';
if (!function_exists('vp3_agent_chat_resolve_agent_v380')) require_once dirname(__DIR__) . '/includes/agent-chat-boundary-v380.php';
header('Content-Type: application/json; charset=UTF-8');header('Cache-Control: no-store');
try{
    if(($_SERVER['REQUEST_METHOD']??'')!=='POST')throw new RuntimeException('Use POST.',405);
    $raw=(string)file_get_contents('php://input');if(strlen($raw)>4096)throw new RuntimeException('Request is too large.',413);
    $input=json_decode($raw,true,16,JSON_THROW_ON_ERROR);if(!is_array($input))throw new InvalidArgumentException('Invalid media request.');
    $user=current_user();if(!$user)throw new RuntimeException('Sign in to view private media.',401);
    if(!has_permission('chat.access',$user))throw new RuntimeException('Chat access is unavailable.',403);
    if(!is_string($input['csrf_token']??null)||!hash_equals(csrf_token(),$input['csrf_token']))throw new RuntimeException('Refresh the page to restore this session.',419);
    $pdo=db();if(!$pdo)throw new RuntimeException('Media storage is unavailable.',503);
    $cid=$input['conversation_id']??null;$aid=$input['agent_id']??null;if(!is_int($cid)||$cid<1||!is_int($aid)||$aid<0)throw new InvalidArgumentException('Use the current conversation and Agent.');
    $agent=vp3_agent_chat_resolve_agent_v380($pdo,$user,$aid);$principal=vp3_agent_chat_principal_v380($user,$agent);$context=['conversation_id'=>$cid];
    $user=vp3_image_scope_v1($pdo,$user,$principal,$context);$action=$input['action']??'';
    if($action==='assets'){
        $ids=$input['asset_ids']??null;if(!is_array($ids)||!array_is_list($ids)||count($ids)>12)throw new InvalidArgumentException('Choose up to twelve assets.');
        foreach($ids as $id)if(!is_int($id)||$id<1)throw new InvalidArgumentException('Invalid asset reference.');
        $assets=media_studio_assets_by_ids($ids,$user);$order=array_flip($ids);usort($assets,static fn($a,$b)=>$order[$a['id']]<=>$order[$b['id']]);vp3_image_scope_v1($pdo,$user,$principal,$context);$result=['assets'=>$assets];
    }elseif(in_array($action,['get','tick','cancel'],true)){
        $id=$input['generation_id']??null;if(!is_string($id))throw new InvalidArgumentException('Choose an image request.');
        if(!table_exists('agent_image_generations_v1'))throw new RuntimeException('Image request not found.',404);
        $row=vp3_image_row_v1($pdo,$user,$id);if((int)$row['conversation_id']!==$cid||(int)$row['agent_id']!==(int)$principal['agent_id'])throw new RuntimeException('Image request not found in this conversation.',404);
        if($action==='cancel'){
            if(($input['confirmed']??null)!==true)throw new InvalidArgumentException('Confirm the image cancellation.');
            $run=agent_workflow_row_v1400($pdo,(int)$user['id'],(int)$row['run_id']);if($run&&!in_array($run['status'],['completed','cancelled','failed'],true))agent_job_cancel_v1900($pdo,$user,(int)$row['run_id']);
            $row=vp3_image_row_v1($pdo,$user,$id);
        }
        if(session_status()===PHP_SESSION_ACTIVE)session_write_close();
        $view=$action==='tick'?vp3_image_tick_v1($pdo,$user,$row):vp3_image_view_v1($pdo,$user,$row);$result=['generation'=>$view];
    }else throw new InvalidArgumentException('Unknown media operation.');
    echo json_encode(['ok'=>true]+$result,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
}catch(Throwable $e){
    $status=$e instanceof InvalidArgumentException||$e instanceof JsonException?422:($e instanceof PDOException?503:(in_array($e->getCode(),[401,403,404,405,409,413,419,503],true)?$e->getCode():503));
    http_response_code($status);echo json_encode(['ok'=>false,'error'=>$e instanceof PDOException?'Media storage is unavailable.':($status===503?'The media operation could not be verified. Refresh its status before creating another request.':$e->getMessage())]);
}
