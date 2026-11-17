<?php
declare(strict_types=1);

const VP3_INITIATIVE_OFFICE_REVIEW_VERSION_V1 = 2;

function vp3_initiative_office_entry_key_v1(array $binding,string $ref): string
{
    return 'office-entry:'.hash('sha256',((int)($binding['agent_id']??0)).'|'.$ref);
}

function vp3_initiative_office_entry_hash_v1(array $binding,string $ref,string $revision,bool $reassess=false): string
{
    $identity=[vp3_initiative_office_entry_key_v1($binding,$ref),$revision,$binding['conversation_id'],$binding['revision']];
    if($reassess)$identity[]=VP3_INITIATIVE_OFFICE_REVIEW_VERSION_V1;
    return hash('sha256',json_encode($identity,JSON_THROW_ON_ERROR));
}

function vp3_initiative_office_agent_task_key_v1(int $reportRunId): string
{
    return 'office-agent-task:'.$reportRunId;
}

function vp3_initiative_office_entry_evidence_v1(PDO $pdo,array $scope,array $binding,array $goal): array
{
    require_once __DIR__.'/agent-initiative-office-entry-source-v1.php';
    $ref=$goal['primary']??null;
    $tool=$goal['tool']??null;
    $sources=$goal['sources']??null;
    if(($goal['office_entry']??null)!==true||!is_string($ref)||!is_array($sources)||array_keys($sources)!==[$ref]
        ||!is_string($sources[$ref])||!in_array($tool,['tasks.read','tasks.lists.read','reminders.read'],true)){
        throw new RuntimeException('Office report assignment changed.',409);
    }
    $source=vp3_initiative_office_entry_source_v1($pdo,$scope,$binding,$ref);
    if(!$source||$source['tool']!==$tool||!hash_equals($source['revision'],$sources[$ref])){
        throw new Vp3InitiativeSourcesChangedV1();
    }
    return [$ref=>vp3_initiative_office_entry_source_evidence_v1($source)];
}

function vp3_initiative_office_entry_source_evidence_v1(array $source): array
{
    return ['ref'=>$source['ref'],'kind'=>$source['kind'],'title'=>$source['title'],'url'=>$source['url'],
        'detail'=>'Exact saved Office entry.','revision'=>$source['revision'],'capability_tool'=>$source['tool']];
}

function vp3_initiative_office_entry_read_v1(PDO $pdo,array $scope,array $binding,array $job,array $evidence): array
{
    require_once __DIR__.'/agent-initiative-office-entry-source-v1.php';
    $ref=$job['primary'];
    $source=vp3_initiative_office_entry_source_v1($pdo,$scope,$binding,$ref);
    if($job['refs']!==[$ref]||!$source||$source['tool']!==$job['tool']
        ||!hash_equals($source['revision'],$evidence[$ref]['revision'])){
        throw new Vp3InitiativeSourcesChangedV1();
    }
    $cite=['source'=>$ref,'title'=>$source['title'],'url'=>$source['url']];
    $text=json_encode($source['record'],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    return ['records'=>[$cite+['revision'=>$source['revision'],'text'=>$text,
        'data'=>['closed'=>$source['closed'],'kind'=>$source['kind'],'status'=>$source['record']['status']??'']]],'sources'=>[$cite],
        'coverage'=>[$ref=>$source['limited']?'limited':'ready']];
}

/** Child work inherits the exact source and only a proposal recorded by its successful review. */
function vp3_initiative_office_agent_task_parent_v1(PDO $pdo,array $user,array $goal): array
{
    $task=$goal['office_agent_task']??null;
    if(!is_array($task)||!is_int($task['report_run_id']??null)||$task['report_run_id']<1
        ||!is_string($task['title']??null)||($goal['office_entry']??null)!==true||($goal['report']??null)!==false
        ||isset($goal['followthrough'])||isset($goal['followup'])){
        throw new RuntimeException('Agent Task assignment changed.',409);
    }
    $query=$pdo->prepare("SELECT r.goal,a.result_json,c.result_json AS receipt_json
        FROM agent_workflow_runs r JOIN agent_workflow_actions a ON a.run_id=r.id AND a.owner_user_id=r.owner_user_id
            AND a.sequence_no=1 AND a.capability_key='initiative.prepare' AND a.status='completed'
        JOIN agent_workflow_receipts c ON c.run_id=r.id AND c.action_id=a.id AND c.owner_user_id=r.owner_user_id
            AND c.attempt_no=a.attempt_count AND c.status='completed' AND c.executor='cloud'
        WHERE r.id=? AND r.owner_user_id=? AND r.source_kind='agent_initiative' AND r.status='completed'
            AND r.source_key=? AND CAST(COALESCE(r.agent_id,0) AS CHAR)=? AND r.capability_key='initiative.prepare' ORDER BY c.id DESC LIMIT 1");
    $binding=$goal['binding'];$ref=$goal['primary'];
    $query->execute([$task['report_run_id'],(int)$user['id'],vp3_initiative_office_entry_key_v1($binding,$ref),(string)($binding['agent_id']??0)]);
    $row=$query->fetch(PDO::FETCH_ASSOC);
    $parent=$row?json_decode((string)$row['goal'],true):null;
    $result=$row?json_decode((string)$row['result_json'],true):null;
    $work=$result['initiative_tool_result']??null;
    $output=$work['output']??null;
    if(!$row||$row['result_json']!==$row['receipt_json']||($parent['office_entry']??null)!==true
        ||isset($parent['office_agent_task'])||($parent['report']??null)!==true||($parent['binding']??null)!==$binding
        ||($parent['primary']??null)!==$ref||($parent['sources']??null)!==$goal['sources']||($parent['tool']??null)!==$goal['tool']
        ||($result['effect_state']??null)!=='private_draft_ready'||($work['tool_receipt']['changes_applied']??null)!==false
        ||($output['type']??null)!=='initiative_capability_read'||($output['status']??null)!=='read_complete'
        ||($output['primary']??null)!==$ref||($output['source_revisions']??null)!==$goal['sources']
        ||($output['office_disposition']??null)!=='review'||($output['agent_task_title']??null)!==$task['title']){
        throw new RuntimeException('Agent Task origin changed.',409);
    }
    return ['title'=>$task['title'],'action_plan'=>$output['action_plan']??[]];
}

function vp3_initiative_office_agent_task_enqueue_v1(PDO $pdo,array $user,array $goal,int $reportRunId,array $toolResult): void
{
    $title=$toolResult['output']['agent_task_title']??null;
    if(($goal['office_entry']??null)!==true||isset($goal['office_agent_task'])||isset($goal['followthrough'])
        ||!empty($goal['office_reassessment'])||($toolResult['output']['office_disposition']??null)!=='review'||!is_string($title))return;
    $child=$goal;
    $child['focus']='Prepare the private work identified in the verified Office report.';
    $child['report']=false;
    $child['office_agent_task']=['report_run_id'=>$reportRunId,'title'=>$title];
    $hash=hash('sha256',vp3_initiative_office_agent_task_key_v1($reportRunId));
    vp3_initiative_enqueue_v1($pdo,$user,'initiative.prepare',$child,$hash);
}
