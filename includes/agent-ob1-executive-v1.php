<?php
declare(strict_types=1);

require_once __DIR__.'/agent-initiative-specialist-registry-v1.php';
require_once __DIR__.'/agent-initiative-runtime-v1.php';

/** Canonical child work only; the executive view owns no queue or copied report. */
function vp3_ob1_executive_rows_v1(PDO $pdo,array $user,array $binding,?int $assessment=null,?array $runIds=null): array
{
    if(!agent_job_engine_schema_ready_v1900($pdo))return [];
    $sqlite=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite';
    $json=static function(string $path)use($sqlite):string{
        $value="JSON_EXTRACT(CASE WHEN JSON_VALID(r.goal) THEN r.goal ELSE '{}' END,'$path')";
        return $sqlite?$value:"JSON_UNQUOTE($value)";
    };
    $parent=$json('$.ob1_assessment_run_id');
    $filter=$assessment===null?"$parent IS NOT NULL AND r.status IN ('completed','failed')":"CAST($parent AS CHAR)=?";
    if($assessment===null)$filter.=' AND a.completed_at<=?';
    if($runIds!==null){
        if(!$runIds||count($runIds)>8||array_filter($runIds,static fn(mixed $id):bool=>!is_int($id)||$id<1))return [];
        $filter.=' AND r.id IN ('.implode(',',array_fill(0,count($runIds),'?')).')';
    }
    $q=$pdo->prepare("SELECT r.id,r.goal,r.status,r.current_action_id,r.lease_expires_at,r.next_attempt_at,
        supervisor.goal AS supervisor_goal,
        a.id AS action_id,a.status AS action_status,a.attempt_count,a.completed_at,a.result_json,a.error_class,
        p.status AS receipt_status,p.result_json AS receipt_json,p.error_class AS receipt_error
        FROM agent_workflow_runs r
        JOIN agent_workflow_runs supervisor ON CAST(supervisor.id AS CHAR)=CAST($parent AS CHAR)
            AND supervisor.owner_user_id=r.owner_user_id AND COALESCE(supervisor.agent_id,0)=COALESCE(r.agent_id,0)
            AND supervisor.source_kind='agent_initiative' AND supervisor.capability_key='initiative.plan'
            AND supervisor.workflow_type='initiative' AND supervisor.execution_target='cloud' AND supervisor.status='completed'
        LEFT JOIN agent_workflow_actions a ON a.run_id=r.id AND a.owner_user_id=r.owner_user_id
            AND a.sequence_no=1 AND a.action_key='initiative.prepare' AND a.capability_key='initiative.prepare'
            AND a.execution_target='cloud'
        LEFT JOIN agent_workflow_receipts p ON p.id=(SELECT MAX(prior.id) FROM agent_workflow_receipts prior
            WHERE prior.run_id=r.id AND prior.action_id=a.id AND prior.owner_user_id=r.owner_user_id
                AND prior.attempt_no=a.attempt_count AND prior.executor='cloud')
        WHERE r.owner_user_id=? AND CAST(COALESCE(r.agent_id,0) AS CHAR)=?
            AND r.source_kind='agent_initiative' AND r.workflow_type='initiative'
            AND r.capability_key='initiative.prepare' AND r.execution_target='cloud'
            AND CAST(".$json('$.binding.conversation_id')." AS CHAR)=? AND ".$json('$.binding.revision')."=?
            AND $filter ORDER BY r.id DESC LIMIT 25");
    $params=[(int)$user['id'],(string)vp3_initiative_binding_agent_v1($binding),
        (string)$binding['conversation_id'],$binding['revision']];
    if($assessment!==null)$params[]=(string)$assessment;
    else $params[]=gmdate('Y-m-d H:i:s',time()-30);
    if($runIds!==null)$params=array_merge($params,$runIds);
    $q->execute($params);
    return array_values(array_filter($q->fetchAll(PDO::FETCH_ASSOC),static function(array $row)use($binding,$assessment):bool{
        $goal=json_decode((string)$row['goal'],true);
        $supervisor=json_decode((string)$row['supervisor_goal'],true);
        $parent=$goal['ob1_assessment_run_id']??null;
        return ($goal['binding']??null)===$binding&&($supervisor['binding']??null)===$binding
            &&!isset($supervisor['opportunity_lane'])&&is_int($parent)&&$parent>0&&$parent<(int)$row['id']
            &&($assessment===null||$parent===$assessment);
    }));
}

/** A receipt proves preparation; it does not prove the owner's objective was achieved. */
function vp3_ob1_executive_state_v1(array $row,int $now): string
{
    $status=$row['status'];$action=$row['action_status'];
    if(in_array($status,['completed','failed'],true)){
        $at=agent_job_utc_timestamp_v1900($row['completed_at']);
        if($action!==$status||$row['receipt_status']!==$status||$row['receipt_error']!==$row['error_class']
            ||!is_string($row['result_json'])||$row['result_json']!==$row['receipt_json']||$at<1||$at>$now)return 'unverified';
        $result=json_decode($row['result_json'],true);
        if(!is_array($result))return 'unverified';
        if($status==='failed')return 'failed';
        $work=$result['initiative_tool_result']??null;
        return ($result['effect_state']??null)==='private_draft_ready'&&is_array($work)
            &&is_string($result['initiative_draft']??null)&&($work['draft']??null)===$result['initiative_draft']
            &&agent_workflow_initiative_result_v1400($work)!==[]?'prepared':'unverified';
    }
    if($status==='executing')return $action==='executing'&&(int)$row['current_action_id']===(int)$row['action_id']
        &&agent_job_utc_timestamp_v1900($row['lease_expires_at'])>$now?'working':'unverified';
    if(in_array($status,['approved','queued'],true)&&$action==='queued')return 'queued';
    return match($status){'paused','approval_pending'=>'waiting_owner','cancelled'=>'cancelled',default=>'unverified'};
}

/** Stable terminal work revision: heartbeat, delivery and assessment rows cannot wake themselves. */
function vp3_ob1_executive_revision_v1(PDO $pdo,array $user,array $binding): string
{
    $rows=[];
    foreach(vp3_ob1_executive_rows_v1($pdo,$user,$binding) as $row){
        $state=vp3_ob1_executive_state_v1($row,time());
        $result=$state==='unverified'?[]:json_decode((string)$row['result_json'],true);
        $rows[]=[(int)$row['id'],$state,$result['effect_state']??null,$result['initiative_tool_result']??null,
            $row['error_class'],$result['failure_phase']??null,$result['failure_cause']??null];
    }
    return $rows?hash('sha256',json_encode($rows,JSON_THROW_ON_ERROR)):'';
}

/** Detect exact pending work; scheduling serializes inference while fresh owner decisions may queue. */
function vp3_ob1_assessment_pending_v1(PDO $pdo,array $user,array $binding,bool $executingOnly=false): bool
{
    $sqlite=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite';
    $value=static function(string $path)use($sqlite):string{
        $sql="JSON_EXTRACT(CASE WHEN JSON_VALID(r.goal) THEN r.goal ELSE '{}' END,'$path')";
        return $sqlite?$sql:"JSON_UNQUOTE($sql)";
    };
    $states=$executingOnly?"'executing'":"'approved','queued','executing'";
    $q=$pdo->prepare("SELECT r.goal FROM agent_workflow_runs r WHERE r.owner_user_id=?
        AND CAST(COALESCE(r.agent_id,0) AS CHAR)=? AND r.source_kind='agent_initiative'
        AND r.workflow_type='initiative'
        AND r.capability_key='initiative.plan' AND r.execution_target='cloud'
        AND r.status IN ($states) AND ".$value('$.opportunity_lane')." IS NULL
        AND (r.status='executing' OR r.next_attempt_at IS NULL OR r.next_attempt_at<=UTC_TIMESTAMP())
        AND CAST(".$value('$.binding.conversation_id')." AS CHAR)=? AND ".$value('$.binding.revision')."=?
        ORDER BY r.id DESC LIMIT 25");
    $q->execute([(int)$user['id'],(string)vp3_initiative_binding_agent_v1($binding),
        (string)$binding['conversation_id'],$binding['revision']]);
    foreach($q->fetchAll(PDO::FETCH_COLUMN) as $json){
        if((json_decode((string)$json,true)['binding']??null)===$binding)return true;
    }
    return false;
}

/** Read a receipt-backed assignment cycle. No model, source scan, mutation or provider request. */
function vp3_ob1_executive_cycle_v1(PDO $pdo,array $user,array $binding): ?array
{
    if(!agent_job_engine_schema_ready_v1900($pdo))return null;
    $fallback=null;
    foreach(array_slice(vp3_initiative_runtime_assessment_rows_v1($pdo,(int)$user['id'],
        vp3_initiative_binding_agent_v1($binding),$binding,true,false),0,8) as $row){
        $goal=json_decode((string)$row['goal'],true);
        if(isset($goal['opportunity_lane']))continue;
        $assessment=vp3_initiative_runtime_assessment_v1($row);
        if($assessment===null)continue;
        $ids=json_decode((string)$row['result_json'],true)['ob1_assignment_run_ids']??null;
        if(!is_array($ids)||!array_is_list($ids)||!$ids||count($ids)!==$assessment['queued']
            ||count(array_unique($ids))!==count($ids)||array_filter($ids,static fn(mixed $id):bool=>!is_int($id)||$id<1))continue;
        $children=vp3_ob1_executive_rows_v1($pdo,$user,$binding,(int)$row['id'],$ids);
        $children=array_values(array_filter($children,static fn(array $child):bool=>in_array((int)$child['id'],$ids,true)));
        $counts=array_fill_keys(['queued','working','prepared','failed','unverified','waiting_owner','cancelled'],0);
        $specialists=[];
        foreach($children as $child){
            $state=vp3_ob1_executive_state_v1($child,time());++$counts[$state];
            $id=vp3_initiative_goal_specialist_v1(json_decode((string)$child['goal'],true))??'ob1';
            $specialists[$id]??=[];$specialists[$id][$state]=($specialists[$id][$state]??0)+1;
        }
        if(count($children)!==count($ids)){
            // Missing or extra rows cannot turn an incomplete projection into a success.
            $counts['unverified']+=count($ids)-count($children);
        }
        $state=$counts['failed']||$counts['unverified']?'needs_review':
            ($counts['working']||$counts['queued']?'in_progress':($counts['waiting_owner']?'waiting_owner':'prepared'));
        if($counts['cancelled']&&$state==='prepared')$state='closed';
        $cycle=['assessment_run_id'=>(int)$row['id'],'assigned'=>$assessment['queued'],'state'=>$state,
            'counts'=>$counts,'specialists'=>$specialists,'assessed_at'=>$assessment['at']];
        $fallback??=$cycle;
        // Do not lose unfinished older assignments merely because a newer assessment was idle.
        if(in_array($state,['needs_review','in_progress','waiting_owner'],true))return $cycle;
    }
    return $fallback;
}
