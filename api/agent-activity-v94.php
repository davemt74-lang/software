<?php
declare(strict_types=1);
require dirname(__DIR__).'/includes/bootstrap.php';
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');
$user=current_user();if(!$user||!has_permission('chat.access',$user)){http_response_code(403);echo json_encode(['ok'=>false,'error'=>'Agent access unavailable.']);exit;}
$input=json_decode((string)file_get_contents('php://input'),true);if(!is_array($input))$input=$_POST;
if(!hash_equals(csrf_token(),(string)($input['csrf_token']??''))){http_response_code(419);echo json_encode(['ok'=>false,'error'=>'Session expired.']);exit;}
try{
  $action=(string)($input['action']??'heartbeat');$surface=preg_replace('/[^a-z0-9_-]/','',strtolower((string)($input['surface']??'chat')))?:'chat';$rawContext=$input['context']??[];if(is_string($rawContext))$rawContext=json_decode($rawContext,true);$context=is_array($rawContext)?$rawContext:[];
  if($action==='snapshot'){echo json_encode(['ok'=>true,'activity'=>agent_activity_v94_snapshot($user,$surface,$context),'trace_id'=>agent_runtime_v125_trace_id()],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);exit;}
  if($action==='voice_health'){
    $voice=is_array($context['voice']??null)?$context['voice']:[];
    $health=agent_runtime_v125_health($user,$surface,$voice,$context);
    echo json_encode(['ok'=>true,'accepted'=>'voice_health','health'=>$health,'runtime'=>'phase5-v125'],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);exit;
  }
  if($action!=='heartbeat')throw new RuntimeException('Unknown activity action.');
  $state=in_array((string)($input['state']??''),['working','paused','idle'],true)?(string)$input['state']:'idle';
  $activity=agent_activity_v94_record($user,$surface,$state,$context,(string)($input['reason']??'heartbeat'));
  agent_runtime_v125_trace('activity.heartbeat',['user_id'=>(int)$user['id'],'surface'=>$surface,'state'=>$state]);
  echo json_encode(['ok'=>true,'activity'=>$activity,'trace_id'=>agent_runtime_v125_trace_id()],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
}catch(Throwable $e){agent_runtime_v125_trace('activity.error',['error_class'=>get_class($e)]);http_response_code(400);echo json_encode(['ok'=>false,'error'=>$e->getMessage(),'trace_id'=>agent_runtime_v125_trace_id()]);}