<?php
declare(strict_types=1);

require_once __DIR__.'/agent-memory-scope-v410.php';

/** Wake discovery within the source transaction, or after a verified autocommit write. */
function vp3_coordination_source_changed_v1(PDO $pdo,int $owner,?int $agentId=null,bool $sourceAlreadyCommitted=false): void
{
    $transactional=$pdo->inTransaction();
    if($owner<1||($agentId!==null&&$agentId<0)||(!$transactional&&!$sourceAlreadyCommitted))return;
    try{
        if(!table_exists('agent_memory_items'))return;
        // Account-wide sources wake every Agent's checkpoint for the verified owner.
        [$scope,$params]=$agentId===null?['1=1',[]]:vp3_agent_memory_scope_sql_v410($agentId);
        $query=$pdo->prepare("UPDATE agent_memory_items SET is_active=0
            WHERE user_id=? AND $scope AND memory_type='initiative_sweep' AND subject='coordination-scan' AND is_active=1");
        $query->execute([$owner,...$params]);
        $metadata="CASE WHEN JSON_VALID(metadata_json) THEN metadata_json ELSE '{}' END";
        $query=$pdo->prepare("UPDATE agent_memory_items
            SET metadata_json=JSON_SET($metadata,'$.specialist_source_changed',1)
            WHERE user_id=? AND $scope AND memory_type='initiative_sweep' AND subject='last-review' AND is_active=1
                AND JSON_EXTRACT($metadata,'$.specialist_source_changed') IS NULL");
        $query->execute([$owner,...$params]);
    }catch(PDOException $error){
        if($transactional&&!$pdo->inTransaction())throw $error;
        // A missing checkpoint store can be reconciled on the next scheduled scan.
        if(function_exists('agent_runtime_v125_trace')){
            agent_runtime_v125_trace('initiative.source_wake_unavailable',['error_class'=>get_class($error)]);
        }
    }
}
