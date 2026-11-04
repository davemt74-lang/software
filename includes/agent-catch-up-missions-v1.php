<?php
declare(strict_types=1);

/** Project actionable Cloud Mission runs in the exact owner Chat Agent namespace. */
function vp3_catch_up_missions_v1(PDO $pdo,array $user,array $principal,DateTimeImmutable $now): array
{
    $owner=(int)($user['id']??0);
    $agentId=(int)($principal['agent_id']??0);
    $kind=$principal['kind']??null;
    if($owner<1||!has_permission('chat.access',$user)||!has_permission('account.access',$user)
        ||($agentId===0?$kind!=='system':($agentId<0||$kind!=='user_agent'||(int)($principal['owner_user_id']??0)!==$owner))){
        return ['forbidden',[]];
    }
    if(!table_exists('agent_cloud_missions_v1')||!table_exists('agent_workflow_runs'))return ['unavailable',[]];
    require_once __DIR__.'/cloud-missions-v1.php';

    // Match durable workflow eligibility. A prerequisite may itself be in another
    // Agent's namespace, so only its blocking effect is visible here.
    $blocked=agent_work_dependencies_schema_ready_v174($pdo)?"EXISTS (
        SELECT 1 FROM agent_workflow_run_dependencies d
        JOIN agent_workflow_runs p ON p.id=d.depends_on_run_id AND p.owner_user_id=d.owner_user_id
        WHERE d.owner_user_id=r.owner_user_id AND d.run_id=r.id AND p.status<>'completed'
    )":'0';
    $runAgentScope=$agentId>0?'r.agent_id=m.agent_id':'r.agent_id IS NULL';
    // The workflow ledger updates this DATETIME with the database session clock.
    $updatedEpoch=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite'
        ? "CAST(strftime('%s',r.updated_at) AS INTEGER)" : 'UNIX_TIMESTAMP(r.updated_at)';
    $q=$pdo->prepare("SELECT m.*,r.source_kind,r.source_key,r.execution_target,r.title,r.status,{$updatedEpoch} AS updated_epoch
        FROM agent_cloud_missions_v1 m JOIN agent_workflow_runs r
          ON r.id=m.run_id AND r.owner_user_id=m.owner_user_id AND {$runAgentScope}
        WHERE m.owner_user_id=? AND m.agent_id=? AND r.source_kind='cloud_mission'
          AND r.source_key=m.mission_id AND r.execution_target='cloud'
          AND (r.status='approval_pending' OR (r.status='failed' AND {$updatedEpoch}>=?)
            OR (r.status IN ('queued','planning','approved') AND {$blocked}))
        ORDER BY CASE r.status WHEN 'approval_pending' THEN 1 WHEN 'failed' THEN 3 ELSE 2 END,r.updated_at DESC,r.id DESC
        LIMIT 21");
    $q->bindValue(1,$owner,PDO::PARAM_INT);
    $q->bindValue(2,$agentId,PDO::PARAM_INT);
    $q->bindValue(3,$now->getTimestamp()-7*86400,PDO::PARAM_INT);
    $q->execute();
    $rows=$q->fetchAll(PDO::FETCH_ASSOC);
    $limited=count($rows)>20;$items=[];
    foreach(array_slice($rows,0,20) as $row){
        $missionId=(string)$row['mission_id'];
        if($row['source_kind']!=='cloud_mission'||$row['source_key']!==$missionId||$row['execution_target']!=='cloud'
            ||!preg_match('/^[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}$/D',$missionId))continue;
        try{
            cloud_mission_authority_v1($pdo,$user,$row);
            foreach(agent_workflow_actions_v1400($pdo,$owner,(int)$row['run_id']) as $action){
                $result=json_decode((string)($action['result_json']??''),true);
                if(!empty($result['mission_output']))cloud_mission_result_authority_v1($pdo,$user,$row,$result);
            }
        }
        catch(RuntimeException $e){
            if(!in_array($e->getCode(),[403,404,409],true))throw $e;
            $limited=true;continue;
        }
        $updated=(int)$row['updated_epoch'];
        if($updated<1||$updated>$now->getTimestamp()){$limited=true;continue;}
        [$status,$reason,$priority]=match($row['status']){
            'approval_pending'=>['awaiting_review','This Mission is waiting for your assignment review or approval.',86],
            'failed'=>['failed','A worker failed. Review the Mission before deciding how to continue.',78],
            default=>['blocked','This Mission is waiting for an unfinished prerequisite workflow.',84],
        };
        $path='/chat.php?agent='.($agentId>0?$agentId:'system').'&mission='.$missionId;
        $items[]=vp3_catch_up_item_v1('mission:'.$missionId,(string)$row['title'],$reason,'Cloud Missions',$path,$priority,$updated)
            +['status'=>$status,'source_updated_at'=>gmdate(DATE_ATOM,$updated)];
    }
    return [$limited?'limited':'ready',$items];
}
