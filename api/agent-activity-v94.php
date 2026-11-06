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
  // Validate a document's account binding before any activity write, including
  // stop/pagehide beacons. A previous account's CSRF token may still be valid.
  unset($context['_vp3_live_binding']);
  $pageBinding=(string)($_SERVER['HTTP_X_VP3_WORKING_ATTENTION']??($input['working_attention_binding']??''));
  if(array_key_exists('owner_user_id',$context)
      &&(!is_int($context['owner_user_id'])||$context['owner_user_id']!==(int)$user['id'])){
    throw new RuntimeException('Activity account changed. Refresh this page.',409);
  }
  if($pageBinding!==''&&(!function_exists('vp3_catch_up_page_binding_v1')
      ||!hash_equals(vp3_catch_up_page_binding_v1($user),$pageBinding))){
    throw new RuntimeException('Activity page changed. Refresh this page.',409);
  }
  if(array_key_exists('live_context',$context)){
    if($pageBinding===''||($context['owner_user_id']??null)!==(int)$user['id']
        ||!is_int($context['agent_id']??null)||$context['agent_id']<0
        ||!is_int($context['conversation_id']??null)||$context['conversation_id']<1){
      throw new RuntimeException('Live context binding is unavailable.',403);
    }
    require_once dirname(__DIR__).'/includes/agent-live-context-v1.php';
    if(!function_exists('vp3_agent_chat_resolve_agent_v380'))require_once dirname(__DIR__).'/includes/agent-chat-boundary-v380.php';
    $pdo=db();if(!$pdo instanceof PDO)throw new RuntimeException('Live context storage is unavailable.',503);
    $agent=vp3_agent_chat_resolve_agent_v380($pdo,$user,$context['agent_id']);
    $principal=vp3_agent_chat_principal_v380($user,$agent);
    $scope=vp3_live_context_scope_v1($pdo,$user,$principal,$context['conversation_id']);
    $context['_vp3_live_binding']=['owner_user_id'=>(int)$scope['user']['id'],'agent_id'=>$scope['agent_id'],
      'conversation_id'=>$scope['conversation_id'],'activity_tab'=>$context['activity_tab']??null,
      'visible'=>$context['visible']??null,'focused'=>$context['focused']??null];
  }
  $state=in_array((string)($input['state']??''),['working','paused','idle'],true)?(string)$input['state']:'idle';
  $activity=agent_activity_v94_record($user,$surface,$state,$context,(string)($input['reason']??'heartbeat'));
  agent_runtime_v125_trace('activity.heartbeat',['user_id'=>(int)$user['id'],'surface'=>$surface,'state'=>$state]);
  $core=[];
  // Normal saved Chat entry activates the core loop through this existing
  // authenticated observation. Reads and background-tab polls never opt in.
  if($surface==='chat'&&$pageBinding!==''&&function_exists('vp3_catch_up_page_binding_v1')
      &&hash_equals(vp3_catch_up_page_binding_v1($user),$pageBinding)){
    $eligibleCore=is_int($context['conversation_id']??null)&&$context['conversation_id']>0
      &&($context['visible']??null)===true&&($context['focused']??null)===true
      &&($context['chat_focused']??null)===true&&is_bool($context['chat_busy']??null);
    $coreFailure=static fn(string $reason):array=>['owner_user_id'=>(int)$user['id'],
      'conversation_id'=>(int)($context['conversation_id']??0),'working_attention_binding'=>$pageBinding,'reason'=>$reason];
    try{
      $pdo=db();
      if(!$pdo instanceof PDO)throw new RuntimeException('Core storage is unavailable.',503);
      require_once dirname(__DIR__).'/includes/agent-initiative-runtime-v1.php';
      require_once dirname(__DIR__).'/includes/agent-initiative-lifecycle-v1.php';
      $adoption=vp3_initiative_adopt_activity_v1($pdo,$user,['surface'=>$surface]+$context);
      if(!empty($adoption['accepted'])){
        $agentId=(int)$adoption['agent_id'];$conversationId=(int)$adoption['conversation_id'];
        $core['agent_core_context']=['owner_user_id'=>(int)$user['id'],'agent_id'=>$agentId,
          'conversation_id'=>$conversationId,'working_attention_binding'=>$pageBinding,
          'health'=>vp3_initiative_worker_health_v1($pdo,$user,$agentId,$conversationId)];
      }elseif($eligibleCore&&in_array($adoption['reason']??'', ['storage_unavailable','context_unavailable'],true)){
        $core['agent_core_context_error']=$coreFailure($adoption['reason']);
      }
    }catch(Throwable $error){
      // Activity remains usable during an upgrade or route outage. Core health
      // is independently projected; never manufacture an active policy here.
      agent_runtime_v125_trace('agent_core.activation_unavailable',['user_id'=>(int)$user['id'],'error_class'=>get_class($error)]);
      if($eligibleCore)$core['agent_core_context_error']=$coreFailure('runtime_unavailable');
    }
  }
  echo json_encode(['ok'=>true,'activity'=>$activity,'server_time_ms'=>(int)floor(microtime(true)*1000),
    'owner_user_id'=>(int)$user['id'],'trace_id'=>agent_runtime_v125_trace_id()]+$core,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
}catch(Throwable $e){agent_runtime_v125_trace('activity.error',['error_class'=>get_class($e)]);http_response_code(in_array($e->getCode(),[403,409,503],true)?$e->getCode():400);echo json_encode(['ok'=>false,'error'=>$e->getMessage(),'trace_id'=>agent_runtime_v125_trace_id()]);}
