<?php
declare(strict_types=1);

function agent_task_v123_statuses(): array
{
    return ['open','in_progress','waiting','completed','cancelled'];
}

function agent_task_v123_update(array $user,int $memoryId,string $status,string $reason='explicit_user'): ?array
{
    $uid=(int)($user['id']??0);$pdo=db();$status=mb_strtolower(trim($status));
    if(!$pdo||$uid<1||$memoryId<1||!in_array($status,agent_task_v123_statuses(),true)||!table_exists('agent_memory_items'))return null;
    try{$s=$pdo->prepare("SELECT * FROM agent_memory_items WHERE id=? AND user_id=? AND memory_type IN ('commitment','task') LIMIT 1");$s->execute([$memoryId,$uid]);$row=$s->fetch()?:null;}catch(Throwable $e){return null;}
    if(!$row)return null;$meta=agent_memory_v123_metadata($row);$from=(string)($meta['task_status']??'open');
    $history=is_array($meta['status_history']??null)?$meta['status_history']:[];
    if($from!==$status)$history[]=['from'=>$from,'to'=>$status,'at'=>date('c'),'reason'=>mb_substr($reason,0,80)];
    $meta['status_history']=array_slice($history,-20);$meta['task_status']=$status;$meta['task_kind']=$meta['task_kind']??(string)$row['memory_type'];$meta['task_key']=$meta['task_key']??sha1(agent_brain_normalize((string)$row['subject'].'|'.(string)$row['memory_text']));$meta['updated_by']=$reason;$meta['status_updated_at']=date('c');
    if(in_array($status,['completed','cancelled'],true))$meta['closed_at']=date('c');else unset($meta['closed_at']);
    $confidence=max((float)$row['confidence'],$reason==='explicit_user'?0.92:0.72);agent_memory_v123_write_row($memoryId,$meta,$confidence,null);
    $row['metadata_json']=json_encode($meta,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);$row['confidence']=$confidence;
    return ['memory_id'=>$memoryId,'task_key'=>$meta['task_key'],'status'=>$status,'previous_status'=>$from,'title'=>(string)$row['subject'],'text'=>(string)$row['memory_text'],'confidence'=>agent_memory_v123_effective_confidence($row),'status_history'=>$meta['status_history']];
}
