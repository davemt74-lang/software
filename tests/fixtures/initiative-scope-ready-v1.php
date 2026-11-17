<?php
declare(strict_types=1);

// Seed current storage and real provider configuration without executing another
// suite's scenarios. The standalone scope suite retains its default provider,
// route and policy tests; consumers pass the explicit model callback below.
require_once __DIR__.'/initiative-ai-settings-v1.php';
require_once __DIR__.'/initiative-scope-v1.php';

$taskInsert=db()->prepare("INSERT INTO agent_memory_items(id,user_id,user_agent_id,memory_type,subject,memory_text,memory_hash,
    memory_scope_version,confidence,occurrence_count,first_seen_at,last_seen_at,is_active,metadata_json)
    VALUES(?,?,?,'task',?,'Existing private task',?,410,.95,1,NOW(),NOW(),1,?)");
foreach([[1001,1,null,'Only System task'],[1002,1,17,'Only Agent A task'],[1003,1,18,'Only Agent B task'],[1004,2,19,'Other owner task']]
    as [$id,$owner,$agent,$title]){
    $taskInsert->execute([$id,$owner,$agent,$title,'scope-task-'.$id,
        json_encode(['task_status'=>'open','due_at'=>gmdate('Y-m-d H:i:s',time()-60)],JSON_THROW_ON_ERROR)]);
}
foreach([[$one,0,142],[$one,17,170],[$one,18,180],[$two,19,190]] as [$user,$agent,$cid]){
    if(empty(scope_command($user,$agent,$cid,'on')['ok']))throw new RuntimeException('The scope fixture could not enable its saved owner policy.');
}
db()->prepare("INSERT INTO agent_activity_state VALUES(1,'chat','chat:170','paused',NOW(),NULL,?)")
    ->execute([json_encode(['visible'=>true,'conversation_id'=>170,'chat_focused'=>true,'chat_busy'=>false],JSON_THROW_ON_ERROR)]);
$GLOBALS['scopeInference']=[];
$GLOBALS['scopeRoutes']=[];
$GLOBALS['scopeRouteBlocked']=false;
$GLOBALS['scopeModel']=static fn():string=>throw new LogicException('This scenario must explicitly configure its model response.');

/** The worker still verifies ownership, sources and the lease before this boundary. */
function initiative_scope_model_v1(string $prompt,string $protocol): string
{
    if(db()->inTransaction())throw new LogicException('Model transport must run outside a database transaction.');
    $GLOBALS['scopeInference'][]=['protocol'=>$protocol,'input'=>json_decode($prompt,true,32,JSON_THROW_ON_ERROR)];
    return ($GLOBALS['scopeModel'])($prompt,$protocol);
}
