<?php
declare(strict_types=1);

/** Configuration readiness only; no bookings, workflow execution, remote probes or DDL. */
function vp3_scheduling_readiness_v1(PDO $pdo,array $user): array
{
    $out=['personal'=>vp3_capability_probe_v1(fn()=>agent_scheduling_tools_ready_v460($pdo)),
          'team'=>vp3_capability_probe_v1(fn()=>agent_team_scheduling_schema_ready_v600($pdo))];
    foreach(['personal','team'] as $kind){
        if($out[$kind]!=='ready')continue;
        try{
            if($kind==='team'&&!agent_team_scheduling_entitled_v600($pdo,(int)$user['id'])){$out[$kind]='subscription_required';continue;}
            $table=$kind==='personal'?'agent_scheduling_schedules':'agent_team_scheduling_pools';
            $owner=$kind==='personal'?'owner_user_id':'workspace_owner_user_id';
            $q=$pdo->prepare("SELECT id FROM $table WHERE $owner=? AND is_active=1 LIMIT 1");$q->execute([(int)$user['id']]);
            if(!$q->fetchColumn())$out[$kind]='setup_required';
        }catch(Throwable $e){$out[$kind]='check_failed';}
    }
    return $out;
}
