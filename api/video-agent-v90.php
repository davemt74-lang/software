<?php
declare(strict_types=1);
require dirname(__DIR__) . '/includes/bootstrap.php';
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');

$user=current_user();
$pdo=db();
if(!$user||!$pdo||!has_permission('chat.access',$user)){http_response_code(403);echo json_encode(['ok'=>false,'error'=>'Video Editor Agent access is unavailable.']);exit;}
$input=json_decode((string)file_get_contents('php://input'),true);if(!is_array($input))$input=$_POST;
if(!hash_equals(csrf_token(),(string)($input['csrf_token']??''))){http_response_code(419);echo json_encode(['ok'=>false,'error'=>'Session expired.']);exit;}

function video_agent_v90_owned(int $id,int $userId): ?array
{
    if($id<1)return null;
    $stmt=db()?->prepare('SELECT * FROM chat_conversations WHERE id=? AND user_id=? LIMIT 1');
    $stmt?->execute([$id,$userId]);
    $row=$stmt?$stmt->fetch():false;
    return $row?:null;
}
function video_agent_v131_latest(PDO $pdo,int $userId): int
{
    if(function_exists('agent_chat_v101_latest_conversation_id')){
        $latest=agent_chat_v101_latest_conversation_id($pdo,$userId);
        if($latest>0)return $latest;
    }
    $stmt=$pdo->prepare('SELECT id FROM chat_conversations WHERE user_id=? ORDER BY updated_at DESC,id DESC LIMIT 1');
    $stmt->execute([$userId]);
    return max(0,(int)($stmt->fetchColumn()?:0));
}
function video_agent_v131_reconcile_brain(array $user,int $conversationId,int $messageId,string $message,string $inputMode): void
{
    if($conversationId<1||$messageId<1||trim($message)==='')return;
    if(function_exists('agent_brain_archive_and_parse'))agent_brain_archive_and_parse($user,$conversationId,$messageId,'assistant',$message,$inputMode);
    if(function_exists('agent_brain_v122_update_state')){
        $state=agent_brain_v122_update_state($user,$conversationId,['id'=>$messageId,'role'=>'assistant','message'=>$message]);
        if(function_exists('agent_brain_v122_rollup'))agent_brain_v122_rollup($user,$conversationId,$state);
    }
}
function video_agent_v90_json(bool $ok,array $extra=[],int $status=200): never
{
    http_response_code($status);echo json_encode(['ok'=>$ok]+$extra,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);exit;
}

try{
    if(!function_exists('agent_v90_plan_video'))throw new RuntimeException('The v90 Agent Brain tools are not loaded.');
    $action=(string)($input['action']??'send');$userId=(int)$user['id'];$conversationId=max(0,(int)($input['conversation_id']??0));

    if($action==='history'){
        if($conversationId<1||!video_agent_v90_owned($conversationId,$userId))$conversationId=video_agent_v131_latest($pdo,$userId);
        if($conversationId<1)video_agent_v90_json(true,['conversation_id'=>0,'history'=>[]]);
        $stmt=$pdo->prepare('SELECT id,role,message,context_json,created_at FROM chat_messages WHERE conversation_id=? ORDER BY id ASC LIMIT 300');
        $stmt->execute([$conversationId]);video_agent_v90_json(true,['conversation_id'=>$conversationId,'history'=>$stmt->fetchAll()]);
    }

    if($action==='result'){
        $request=trim((string)($input['request_text']??''));$status=in_array((string)($input['status']??''),['success','failed','cancelled'],true)?(string)$input['status']:'success';
        $assistantMessageId=max(0,(int)($input['assistant_message_id']??0));$resultText=mb_strimwidth(trim((string)($input['result_text']??'')),0,8000,'…');
        $resultMode=(string)($input['input_mode']??'text');$resultMode=$resultMode==='voice'?'voice':'text';
        if($conversationId>0&&$assistantMessageId>0&&$resultText!==''&&video_agent_v90_owned($conversationId,$userId)){
            $stmt=$pdo->prepare("UPDATE chat_messages SET message=? WHERE id=? AND conversation_id=? AND role='assistant'");
            $stmt->execute([$resultText,$assistantMessageId,$conversationId]);
            if($stmt->rowCount()>0)video_agent_v131_reconcile_brain($user,$conversationId,$assistantMessageId,$resultText,$resultMode);
            $pdo->prepare('UPDATE chat_conversations SET updated_at=NOW() WHERE id=? AND user_id=?')->execute([$conversationId,$userId]);
        }
        agent_tool_log($user,'video_editor.agent_result',$request,$status,[
            'project_id'=>max(0,(int)($input['project_id']??0)),
            'changes'=>max(0,(int)($input['change_count']??0)),
            'model'=>(string)($input['model']??''),
            'assistant_message_id'=>$assistantMessageId,
        ],$conversationId>0?$conversationId:null);
        video_agent_v90_json(true,['conversation_id'=>$conversationId,'assistant_message_id'=>$assistantMessageId]);
    }

    if($action!=='send')throw new RuntimeException('Unknown Video Editor Agent action.');
    $message=trim((string)($input['message']??''));if($message==='')throw new RuntimeException('Enter a Video Editor request.');if(mb_strlen($message)>6000)throw new RuntimeException('That request is too long.');
    $inputMode=(string)($input['input_mode']??'text');$inputMode=$inputMode==='voice'?'voice':'text';

    if($conversationId<1||!video_agent_v90_owned($conversationId,$userId))$conversationId=video_agent_v131_latest($pdo,$userId);
    if($conversationId<1){
        $title='Video Editor · '.mb_strimwidth($message,0,55,'…');
        $stmt=$pdo->prepare('INSERT INTO chat_conversations (user_id,title) VALUES (?,?)');$stmt->execute([$userId,$title]);$conversationId=(int)$pdo->lastInsertId();
    }

    $rawAgentContext=is_array($input['agent_context']??null)?$input['agent_context']:[];
    $rawAgentContext['conversation_id']=$conversationId;
    $rawAgentContext['project_id']=max(0,(int)($input['project_id']??($rawAgentContext['project_id']??0)));
    $agentContext=agent_surface_v131_enrich($user,'video',$rawAgentContext);

    $historyStmt=$pdo->prepare('SELECT role,message FROM chat_messages WHERE conversation_id=? ORDER BY id DESC LIMIT 12');$historyStmt->execute([$conversationId]);$history=array_reverse($historyStmt->fetchAll());
    $userContext=json_encode(['editor'=>'video','agent_context'=>$agentContext],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
    $stmt=$pdo->prepare('INSERT INTO chat_messages (conversation_id,user_id,role,message,context_json) VALUES (?,?,\'user\',?,?)');$stmt->execute([$conversationId,$userId,$message,is_string($userContext)?$userContext:'{}']);$userMessageId=(int)$pdo->lastInsertId();
    agent_brain_archive_and_parse($user,$conversationId,$userMessageId,'user',$message,$inputMode);

    $state=is_array($input['state']??null)?$input['state']:[];
    $state['agent_context']=$agentContext;
    $plan=agent_v90_plan_video($message,$state,$user,$conversationId);
    $commands=is_array($plan['commands']??null)?$plan['commands']:[];
    $model=(string)($plan['model']??'');$complexity=(string)($plan['complexity']??'routine');
    if(!empty($plan['handled'])){
        $answer=(string)$plan['answer'];$context=[];
    }else{
        $generated=function_exists('chat_generate_answer_v105')
            ? chat_generate_answer_v105($message,$history,$user,$agentContext)
            : chat_generate_answer($message,$history,$user);
        $answer=(string)$generated['answer'];$context=$generated['context'];
    }

    $messageContext=['editor'=>'video','commands'=>$commands,'model'=>$model,'complexity'=>$complexity,'agent_context'=>$agentContext];
    $stmt=$pdo->prepare('INSERT INTO chat_messages (conversation_id,user_id,role,message,context_json) VALUES (?,NULL,\'assistant\',?,?)');
    $stmt->execute([$conversationId,$answer,json_encode($messageContext,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)]);$assistantMessageId=(int)$pdo->lastInsertId();
    agent_brain_archive_and_parse($user,$conversationId,$assistantMessageId,'assistant',$answer,$inputMode);
    $pdo->prepare('UPDATE chat_conversations SET updated_at=NOW() WHERE id=?')->execute([$conversationId]);

    video_agent_v90_json(true,[
        'conversation_id'=>$conversationId,
        'user_message_id'=>$userMessageId,
        'assistant_message_id'=>$assistantMessageId,
        'answer'=>$answer,
        'commands'=>$commands,
        'model'=>$model,
        'provider'=>$model==='deterministic'||str_starts_with($model,'deterministic_')?'local':ai_active_provider(),
        'complexity'=>$complexity,
    ]);
}catch(Throwable $e){video_agent_v90_json(false,['error'=>$e->getMessage()],400);}
