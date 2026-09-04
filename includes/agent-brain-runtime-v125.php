<?php
declare(strict_types=1);

/** Stonefellow v125 — background-safe Agent Brain refresh helpers. */
function agent_brain_v122_refresh_conversation(array $user,int $conversationId): void
{
    if(!function_exists('agent_brain_schema_ready')||!agent_brain_schema_ready()||!table_exists('chat_messages')||!table_exists('chat_conversations'))return;
    $pdo=db();$uid=(int)($user['id']??0);$conversationId=max(0,$conversationId);if(!$pdo||$uid<1||$conversationId<1)return;
    try{
        $owned=$pdo->prepare('SELECT id FROM chat_conversations WHERE id=? AND user_id=? LIMIT 1');$owned->execute([$conversationId,$uid]);if(!$owned->fetchColumn())return;
        $stmt=$pdo->prepare('SELECT id,conversation_id,role,message FROM chat_messages WHERE conversation_id=? ORDER BY id DESC LIMIT 1');$stmt->execute([$conversationId]);$latest=$stmt->fetch()?:null;if(!$latest)return;
        $state=agent_brain_v122_state($user,$conversationId);
        if((int)($state['last_message_id']??0)!==(int)$latest['id'])$state=agent_brain_v122_update_state($user,$conversationId,$latest);
        agent_brain_v122_rollup($user,$conversationId,$state);
        if(function_exists('agent_runtime_v125_trace'))agent_runtime_v125_trace('brain.refresh',['user_id'=>$uid,'conversation_id'=>$conversationId,'message_id'=>(int)$latest['id']]);
    }catch(Throwable $e){
        if(function_exists('agent_runtime_v125_trace'))agent_runtime_v125_trace('brain.refresh.failed',['user_id'=>$uid,'conversation_id'=>$conversationId,'error_class'=>get_class($e)]);
    }
}
