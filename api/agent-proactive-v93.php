<?php
declare(strict_types=1);
require dirname(__DIR__).'/includes/bootstrap.php';
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');
$user=current_user();
if(!$user||!has_permission('chat.access',$user)){http_response_code(403);echo json_encode(['ok'=>false,'error'=>'Agent access unavailable.']);exit;}
$input=json_decode((string)file_get_contents('php://input'),true);if(!is_array($input))$input=$_POST;
if(!hash_equals(csrf_token(),(string)($input['csrf_token']??''))){http_response_code(419);echo json_encode(['ok'=>false,'error'=>'Session expired.']);exit;}
try{
    $action=(string)($input['action']??'list');$surface=preg_replace('/[^a-z0-9_-]/','',strtolower((string)($input['surface']??'chat')))?:'chat';
    if(in_array($action,['acted','dismissed'],true)){
        $payload=[
            'title'=>(string)($input['title']??''),'prompt'=>(string)($input['prompt']??''),'source'=>(string)($input['source']??''),
            'context'=>is_array($input['context']??null)?$input['context']:[],
            'memory_id'=>(int)($input['memory_id']??0),'task_status'=>(string)($input['task_status']??''),
        ];
        if(function_exists('agent_action_v124_record_outcome'))agent_action_v124_record_outcome($user,(string)($input['hash']??''),$action,$surface,$payload);
        else agent_proactive_v93_event($user,(string)($input['hash']??''),$action,$surface,$payload);
        echo json_encode(['ok'=>true,'runtime'=>'phase4-v124']);exit;
    }
    if($action==='task_update'){
        $task=agent_task_v123_update($user,(int)($input['memory_id']??0),(string)($input['status']??''),'explicit_user');
        if(!$task)throw new RuntimeException('Task update was not accepted.');
        echo json_encode(['ok'=>true,'task'=>$task,'runtime'=>'phase4-v124'],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);exit;
    }
    if($action==='tasks'){
        echo json_encode(['ok'=>true,'tasks'=>agent_memory_v123_tasks($user,!empty($input['include_closed'])),'runtime'=>'phase4-v124'],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);exit;
    }
    if($action!=='list')throw new RuntimeException('Unknown proactive Agent action.');
    $context=is_array($input['context']??null)?$input['context']:[];$scanStartedAt=date('Y-m-d H:i:s');
    $result=function_exists('agent_proactive_v123_suggestions')
        ? agent_proactive_v123_suggestions($user,$surface,$context)
        : agent_proactive_v93_suggestions($user,$surface,$context);
    if(function_exists('release_v105_merge_proactive'))$result=release_v105_merge_proactive($result,$user);
    if(function_exists('agent_proactive_v123_rescore_result'))$result=agent_proactive_v123_rescore_result($result,$user,$surface,$context);
    if(function_exists('agent_action_v124_enrich_result'))$result=agent_action_v124_enrich_result($result,$user,$surface,$context,$scanStartedAt);
    echo json_encode(['ok'=>true,'tracking_ready'=>agent_proactive_v93_schema_ready(),'runtime'=>'phase4-v124']+$result,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
}catch(Throwable $e){http_response_code(400);echo json_encode(['ok'=>false,'error'=>$e->getMessage()]);}
