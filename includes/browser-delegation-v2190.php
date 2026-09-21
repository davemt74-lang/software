<?php
declare(strict_types=1);

/**
 * VP3 Browser Companion Delegated Browser Workflows v21.90.
 *
 * A delegation is explicit, bounded authority for one user-authored task.
 * Canonical work state is mirrored into Agent Workflow Runs. Browser page
 * content remains ephemeral and is never copied into delegation storage.
 */
const VP3_BROWSER_DELEGATION_V2190='browser-delegation-v2190-20260920';
const VP3_BROWSER_DELEGATION_MAX_STEPS_V2190=8;
const VP3_BROWSER_DELEGATION_MAX_RECENT_V2190=30;

require_once __DIR__.'/browser-execution-v2180.php';
require_once __DIR__.'/browser-memory-v2170.php';
require_once __DIR__.'/agent-workflow-runs-v1400.php';
require_once __DIR__.'/agent-job-engine-v1900.php';

function vp3_browser_delegation_schema_ready_v2190(?PDO $pdo=null): bool
{
    $pdo??=db();
    return (bool)$pdo
        && table_exists('browser_delegations_v2190')
        && agent_workflow_schema_ready_v1400($pdo);
}

function vp3_browser_delegation_ensure_schema_v2190(?PDO $pdo=null): void
{
    $pdo??=db();
    if(!$pdo)throw new RuntimeException('Database connection is unavailable.');
    agent_job_engine_ensure_schema_v1900($pdo);
    if(vp3_browser_delegation_schema_ready_v2190($pdo))return;

    $pdo->exec("CREATE TABLE IF NOT EXISTS browser_delegations_v2190 (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
      public_id CHAR(36) NOT NULL,
      owner_user_id INT UNSIGNED NOT NULL,
      agent_namespace VARCHAR(80) NOT NULL DEFAULT 'system',
      workflow_run_id BIGINT UNSIGNED NOT NULL,
      source_type VARCHAR(40) NOT NULL,
      source_id VARCHAR(190) NOT NULL,
      source_scope VARCHAR(40) NOT NULL DEFAULT 'personal',
      plan_hash CHAR(64) NOT NULL,
      allowed_domains_json TEXT NULL,
      allowed_actions_json TEXT NULL,
      max_steps TINYINT UNSIGNED NOT NULL DEFAULT 5,
      risk_budget VARCHAR(16) NOT NULL DEFAULT 'low',
      status VARCHAR(32) NOT NULL DEFAULT 'active',
      auto_step_count INT UNSIGNED NOT NULL DEFAULT 0,
      expires_at DATETIME NOT NULL,
      paused_at DATETIME NULL,
      completed_at DATETIME NULL,
      cancelled_at DATETIME NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      PRIMARY KEY (id),
      UNIQUE KEY uq_browser_delegation_public_v2190 (public_id),
      UNIQUE KEY uq_browser_delegation_run_v2190 (workflow_run_id),
      INDEX idx_browser_delegation_owner_v2190 (owner_user_id,agent_namespace,status,updated_at),
      CONSTRAINT fk_browser_delegation_owner_v2190 FOREIGN KEY (owner_user_id) REFERENCES users(id) ON DELETE CASCADE,
      CONSTRAINT fk_browser_delegation_run_v2190 FOREIGN KEY (workflow_run_id) REFERENCES agent_workflow_runs(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

function vp3_browser_delegation_uuid_v2190(): string
{
    return vp3_extension_uuid_v2000();
}

function vp3_browser_delegation_instruction_v2190(mixed $value): string
{
    $text=preg_replace('/\s+/u',' ',trim((string)$value))??'';
    $text=mb_strimwidth($text,0,2000,'');
    if(mb_strlen($text)<3)throw new InvalidArgumentException('Describe the Browser job you want the Agent to complete.');
    return $text;
}

function vp3_browser_delegation_domain_v2190(mixed $value): string
{
    $domain=strtolower(trim((string)$value));
    $domain=preg_replace('/^www\./','',$domain)??$domain;
    if($domain===''||strlen($domain)>253||!preg_match('/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)*[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/',$domain))return '';
    return $domain;
}

function vp3_browser_delegation_domains_v2190(array $input,string $currentDomain): array
{
    $out=[];
    $current=vp3_browser_delegation_domain_v2190($currentDomain);
    if($current!=='')$out[$current]=true;
    foreach(array_slice($input,0,5) as $value){
        $domain=vp3_browser_delegation_domain_v2190($value);
        if($domain!=='')$out[$domain]=true;
    }
    return array_slice(array_keys($out),0,5);
}

function vp3_browser_delegation_allowed_actions_v2190(array $input): array
{
    $allowed=[
        'follow_source','unfollow_source',
        'open_research','open_profile','open_contact',
        'draft_task','draft_knowledge','share_team',
        'web_click','web_focus','web_type','web_clear','web_select','web_toggle','web_scroll','web_open_link','web_submit','transaction_submit','multisite_handoff','browser_research'
    ];
    $out=[];
    foreach(array_slice($input,0,24) as $value){
        $action=trim((string)$value);
        if(in_array($action,$allowed,true))$out[$action]=true;
    }
    return array_keys($out);
}

function vp3_browser_delegation_json_array_v2190(mixed $json): array
{
    if(is_array($json))return array_values(array_filter($json,static fn($v): bool=>is_scalar($v)));
    $decoded=json_decode((string)$json,true);
    return is_array($decoded)?array_values(array_filter($decoded,static fn($v): bool=>is_scalar($v))):[];
}

function vp3_browser_delegation_plan_v2190(
    PDO $pdo,array $user,string $namespace,array $session,array $context,array $relations,
    string $instruction,array $constraints
): array {
    $instruction=vp3_browser_delegation_instruction_v2190($instruction);
    $maxSteps=max(1,min(VP3_BROWSER_DELEGATION_MAX_STEPS_V2190,(int)($constraints['max_steps']??5)));
    $expiresMinutes=max(15,min(1440,(int)($constraints['expires_minutes']??120)));
    $riskBudget=in_array((string)($constraints['risk_budget']??'low'),['low','medium'],true)?(string)$constraints['risk_budget']:'low';
    $domains=vp3_browser_delegation_domains_v2190(
        is_array($constraints['allowed_domains']??null)?$constraints['allowed_domains']:[],
        (string)($context['domain']??'')
    );
    $allowedActions=vp3_browser_delegation_allowed_actions_v2190(
        is_array($constraints['allowed_actions']??null)?$constraints['allowed_actions']:[]
    );
    if(in_array('transaction_submit',$allowedActions,true)&&$riskBudget!=='medium'){
        throw new InvalidArgumentException('Transaction & Submission Safety requires a Medium risk budget because every final external submission is an explicit checkpoint.');
    }
    $candidates=vp3_browser_execution_candidates_v2180($pdo,$user,$namespace,$session,$context,$relations);
    $source=is_array($relations['source']??null)?$relations['source']:null;
    if(!$source||trim((string)($source['id']??''))===''){
        throw new RuntimeException('This page must be connected to an authorized VP3 Source before it can start a delegated Browser job.');
    }

    $steps=[[
        'step_key'=>'inspect_source',
        'action_key'=>'inspect_source',
        'label'=>'Verify current VP3 Source',
        'step_kind'=>'inspect',
        'target_type'=>'browser_source',
        'target_id'=>(string)$source['id'],
        'target_scope'=>'personal',
        'risk_level'=>'low',
        'requires_checkpoint'=>false,
        'verification_mode'=>'server_reference',
        'mode'=>'server'
    ]];

    if(in_array('multisite_handoff',$allowedActions,true)&&count($domains)>1&&count($steps)<$maxSteps){
        $steps[]=[
            'step_key'=>'step_'.(count($steps)+1).'_multisite_handoff',
            'action_key'=>'multisite_handoff',
            'label'=>'Coordinate approved multi-site workflow',
            'step_kind'=>'checkpoint',
            'target_type'=>'browser_source',
            'target_id'=>(string)$source['id'],
            'target_scope'=>'personal',
            'risk_level'=>'low',
            'requires_checkpoint'=>true,
            'verification_mode'=>'user_confirmation',
            'mode'=>'checkpoint',
        ];
    }

    if(in_array('browser_research',$allowedActions,true)&&count($steps)<$maxSteps){
        $steps[]=[
            'step_key'=>'step_'.(count($steps)+1).'_browser_research',
            'action_key'=>'browser_research',
            'label'=>'Run Browser Research mission',
            'step_kind'=>'checkpoint',
            'target_type'=>'browser_source',
            'target_id'=>(string)$source['id'],
            'target_scope'=>'personal',
            'risk_level'=>'low',
            'requires_checkpoint'=>true,
            'verification_mode'=>'user_confirmation',
            'mode'=>'checkpoint',
        ];
    }

    foreach($candidates as $candidate){
        $action=(string)($candidate['action_key']??'');
        if(!in_array($action,$allowedActions,true))continue;
        $risk=(string)($candidate['risk_level']??'low');
        if($risk==='high')continue;
        $mode=(string)($candidate['mode']??'');
        $checkpoint=in_array($action,['draft_task','draft_knowledge','share_team'],true)
            ||$mode==='agent_prompt'||$mode==='manual_flow';
        $steps[]=[
            'step_key'=>'step_'.(count($steps)+1).'_'.$action,
            'action_key'=>$action,
            'label'=>(string)($candidate['label']??str_replace('_',' ',$action)),
            'step_kind'=>$checkpoint?'checkpoint':($mode==='open'?'navigate':'execute'),
            'target_type'=>(string)$candidate['target_type'],
            'target_id'=>(string)$candidate['target_id'],
            'target_scope'=>(string)$candidate['target_scope'],
            'risk_level'=>$risk,
            'requires_checkpoint'=>$checkpoint,
            'verification_mode'=>$checkpoint?'user_confirmation':($mode==='open'?'navigation':'server_state'),
            'mode'=>$mode,
        ];
        if(count($steps)>=$maxSteps)break;
    }

    $steps=array_slice($steps,0,$maxSteps);
    $hashPayload=[
        'source'=>['type'=>'browser_source','id'=>(string)$source['id']],
        'instruction'=>$instruction,
        'domains'=>$domains,'actions'=>$allowedActions,'max_steps'=>$maxSteps,
        'expires_minutes'=>$expiresMinutes,'risk_budget'=>$riskBudget,
        'steps'=>array_map(static fn(array $step): array=>[
            'key'=>$step['step_key'],'action'=>$step['action_key'],'target_type'=>$step['target_type'],
            'target_id'=>$step['target_id'],'checkpoint'=>$step['requires_checkpoint']
        ],$steps)
    ];
    $planHash=hash('sha256',json_encode($hashPayload,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));

    return [
        'instruction'=>$instruction,
        'plan_hash'=>$planHash,
        'source'=>['type'=>'browser_source','id'=>(string)$source['id'],'title'=>(string)($source['title']??$source['domain']??'Current VP3 Source')],
        'constraints'=>[
            'allowed_domains'=>$domains,'allowed_actions'=>$allowedActions,'max_steps'=>$maxSteps,
            'expires_minutes'=>$expiresMinutes,'risk_budget'=>$riskBudget
        ],
        'steps'=>$steps,
        'authority'=>[
            'delegated'=>true,
            'low_risk_auto_only'=>true,
            'consequential_actions_require_checkpoint'=>true,
            'expires'=>true,
            'stores_browser_history'=>false,
        ],
    ];
}

function vp3_browser_delegation_insert_workflow_v2190(
    PDO $pdo,array $user,?int $agentId,array $plan,string $publicId
): int {
    $uid=(int)$user['id'];
    $instruction=(string)$plan['instruction'];
    $source=(array)$plan['source'];
    $risk=(string)($plan['constraints']['risk_budget']??'low');
    $title=agent_workflow_text_v1400('Browser delegation · '.$instruction,190);
    $dedupe=hash('sha256','browser-delegation|'.$uid.'|'.$publicId);
    $stmt=$pdo->prepare("INSERT INTO agent_workflow_runs
      (owner_user_id,agent_id,workflow_type,origin,source_kind,source_key,source_hash,dedupe_key,title,goal,decision_summary,status,risk_level,requires_approval,approval_status,execution_target,capability_key,approved_at,next_attempt_at,progress_percent,progress_message,max_attempts,retry_backoff_seconds,timeout_seconds)
      VALUES (?,?,?,?,?,?,?,?,?,?,?,'approved',?,0,'approved','browser','browser.delegation',UTC_TIMESTAMP(),UTC_TIMESTAMP(),0,'Delegated Browser job approved',3,15,900)");
    $stmt->execute([
        $uid,$agentId&&$agentId>0?$agentId:null,'browser_delegation','browser_delegation',
        (string)$source['type'],(string)$source['id'],'',$dedupe,$title,$instruction,
        'User approved a bounded Browser Companion delegation plan.',$risk
    ]);
    $runId=(int)$pdo->lastInsertId();
    agent_workflow_event_v1400($pdo,$uid,$runId,'created','','approved','user','Bounded Browser delegation approved by the user.',[
        'delegation_id'=>$publicId,'max_steps'=>(int)$plan['constraints']['max_steps'],
        'risk_budget'=>$risk
    ]);

    $insert=$pdo->prepare("INSERT INTO agent_workflow_actions
      (run_id,owner_user_id,sequence_no,action_key,action_type,label,summary,status,requires_approval,execution_target,capability_key,available_at,max_attempts,timeout_seconds)
      VALUES (?,?,?,?,?,?,?,'queued',?,'browser','browser.delegation',UTC_TIMESTAMP(),3,900)");
    foreach((array)$plan['steps'] as $index=>$step){
        $insert->execute([
            $runId,$uid,$index+1,(string)$step['step_key'],(string)$step['step_kind'],
            agent_workflow_text_v1400($step['label'],190),
            agent_workflow_text_v1400($step['action_key'].'|'.$step['target_type'].'|'.$step['target_id'],1500),
            !empty($step['requires_checkpoint'])?1:0
        ]);
    }
    return $runId;
}

function vp3_browser_delegation_create_v2190(
    PDO $pdo,array $user,string $namespace,?int $agentId,array $plan,string $expectedHash
): array {
    if(!hash_equals((string)$plan['plan_hash'],$expectedHash))throw new RuntimeException('The delegation plan changed. Review it again before starting.');
    $uid=(int)$user['id'];
    $publicId=vp3_browser_delegation_uuid_v2190();
    $constraints=(array)$plan['constraints'];
    $expiresAt=gmdate('Y-m-d H:i:s',time()+((int)$constraints['expires_minutes']*60));

    try{
        $pdo->beginTransaction();
        $runId=vp3_browser_delegation_insert_workflow_v2190($pdo,$user,$agentId,$plan,$publicId);
        $stmt=$pdo->prepare("INSERT INTO browser_delegations_v2190
          (public_id,owner_user_id,agent_namespace,workflow_run_id,source_type,source_id,source_scope,plan_hash,allowed_domains_json,allowed_actions_json,max_steps,risk_budget,status,expires_at)
          VALUES (?,?,?,?,?,?,?,?,?,?,?,?, 'active',?)");
        $stmt->execute([
            $publicId,$uid,$namespace,$runId,(string)$plan['source']['type'],(string)$plan['source']['id'],'personal',
            (string)$plan['plan_hash'],
            json_encode($constraints['allowed_domains'],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE),
            json_encode($constraints['allowed_actions'],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE),
            (int)$constraints['max_steps'],(string)$constraints['risk_budget'],$expiresAt
        ]);
        $pdo->commit();
    }catch(Throwable $e){
        if($pdo->inTransaction())$pdo->rollBack();
        throw $e;
    }
    return vp3_browser_delegation_public_v2190($pdo,$user,$namespace,$publicId,true)
        ??throw new RuntimeException('Delegated Browser job could not be loaded.');
}

function vp3_browser_delegation_row_v2190(PDO $pdo,int $uid,string $namespace,string $publicId,bool $lock=false): ?array
{
    if(!preg_match('/^[a-f0-9-]{36}$/i',$publicId))return null;
    $sql="SELECT d.*,r.title,r.goal,r.status workflow_status,r.progress_percent,r.progress_message,r.last_error_class
      FROM browser_delegations_v2190 d INNER JOIN agent_workflow_runs r ON r.id=d.workflow_run_id
      WHERE d.public_id=? AND d.owner_user_id=? AND d.agent_namespace=? LIMIT 1".($lock?' FOR UPDATE':'');
    $stmt=$pdo->prepare($sql);$stmt->execute([$publicId,$uid,$namespace]);$row=$stmt->fetch(PDO::FETCH_ASSOC);
    return is_array($row)?$row:null;
}

function vp3_browser_delegation_steps_v2190(PDO $pdo,int $uid,int $runId): array
{
    $stmt=$pdo->prepare("SELECT * FROM agent_workflow_actions WHERE run_id=? AND owner_user_id=? ORDER BY sequence_no,id");
    $stmt->execute([$runId,$uid]);return $stmt->fetchAll(PDO::FETCH_ASSOC)?:[];
}

function vp3_browser_delegation_step_target_v2190(array $step): array
{
    $parts=explode('|',(string)($step['summary']??''),3);
    return [
        'action_key'=>$parts[0]??'',
        'target_type'=>$parts[1]??'',
        'target_id'=>$parts[2]??'',
    ];
}

function vp3_browser_delegation_public_step_v2190(array $step): array
{
    $target=vp3_browser_delegation_step_target_v2190($step);
    return [
        'id'=>(int)$step['id'],'sequence_no'=>(int)$step['sequence_no'],'step_key'=>(string)$step['action_key'],
        'step_kind'=>(string)$step['action_type'],'label'=>(string)$step['label'],
        'action_key'=>(string)$target['action_key'],'target_type'=>(string)$target['target_type'],'target_id'=>(string)$target['target_id'],
        'status'=>(string)$step['status'],'requires_checkpoint'=>!empty($step['requires_approval']),
        'attempt_count'=>(int)$step['attempt_count'],'result_summary'=>(string)($step['result_summary']??''),
        'error_class'=>(string)($step['error_class']??''),'started_at'=>(string)($step['started_at']??''),
        'completed_at'=>(string)($step['completed_at']??''),'updated_at'=>(string)($step['updated_at']??''),
    ];
}

function vp3_browser_delegation_public_v2190(
    PDO $pdo,array $user,string $namespace,string $publicId,bool $history=false
): ?array {
    $uid=(int)$user['id'];$row=vp3_browser_delegation_row_v2190($pdo,$uid,$namespace,$publicId);
    if(!$row)return null;
    $steps=vp3_browser_delegation_steps_v2190($pdo,$uid,(int)$row['workflow_run_id']);
    $completed=0;foreach($steps as $step)if((string)$step['status']==='completed')$completed++;
    return [
        'delegation_id'=>(string)$row['public_id'],'workflow_run_id'=>(int)$row['workflow_run_id'],
        'title'=>(string)$row['title'],'instruction'=>(string)$row['goal'],'status'=>(string)$row['status'],
        'workflow_status'=>(string)$row['workflow_status'],'source_type'=>(string)$row['source_type'],'source_id'=>(string)$row['source_id'],
        'max_steps'=>(int)$row['max_steps'],'risk_budget'=>(string)$row['risk_budget'],
        'allowed_domains'=>vp3_browser_delegation_json_array_v2190($row['allowed_domains_json']),
        'allowed_actions'=>vp3_browser_delegation_json_array_v2190($row['allowed_actions_json']),
        'completed_steps'=>$completed,'total_steps'=>count($steps),'auto_step_count'=>(int)$row['auto_step_count'],
        'progress_percent'=>count($steps)>0?(int)floor(($completed/count($steps))*100):0,
        'progress_message'=>(string)($row['progress_message']??''),
        'expires_at'=>(string)$row['expires_at'],'paused_at'=>(string)($row['paused_at']??''),
        'completed_at'=>(string)($row['completed_at']??''),'cancelled_at'=>(string)($row['cancelled_at']??''),
        'updated_at'=>(string)$row['updated_at'],
        'steps'=>$history?array_map('vp3_browser_delegation_public_step_v2190',$steps):[],
        'agent_workflow_url'=>'/agent-workflows.php?id='.(int)$row['workflow_run_id'],
    ];
}

function vp3_browser_delegation_list_v2190(PDO $pdo,array $user,string $namespace,int $limit=20): array
{
    $uid=(int)$user['id'];$limit=max(1,min(VP3_BROWSER_DELEGATION_MAX_RECENT_V2190,$limit));
    $stmt=$pdo->prepare("SELECT public_id FROM browser_delegations_v2190 WHERE owner_user_id=? AND agent_namespace=? ORDER BY id DESC LIMIT ".$limit);
    $stmt->execute([$uid,$namespace]);$out=[];
    foreach($stmt->fetchAll(PDO::FETCH_ASSOC)?:[] as $row){
        $item=vp3_browser_delegation_public_v2190($pdo,$user,$namespace,(string)$row['public_id'],false);
        if($item)$out[]=$item;
    }
    return $out;
}

function vp3_browser_delegation_update_status_v2190(
    PDO $pdo,array $user,string $namespace,string $publicId,string $action
): array {
    $uid=(int)$user['id'];
    $row=vp3_browser_delegation_row_v2190($pdo,$uid,$namespace,$publicId,true);
    if(!$row)throw new RuntimeException('Delegated Browser job was not found.');
    $status=(string)$row['status'];$runId=(int)$row['workflow_run_id'];

    if($action==='pause'){
        if(!in_array($status,['active','checkpoint'],true))throw new RuntimeException('This delegated job cannot be paused.');
        $pdo->prepare("UPDATE browser_delegations_v2190 SET status='paused',paused_at=UTC_TIMESTAMP(),updated_at=UTC_TIMESTAMP() WHERE id=?")->execute([(int)$row['id']]);
        agent_workflow_event_v1400($pdo,$uid,$runId,'delegation_paused',(string)$row['workflow_status'],(string)$row['workflow_status'],'user','Browser delegation paused.');
    }elseif($action==='resume'){
        if($status!=='paused')throw new RuntimeException('This delegated job is not paused.');
        $pdo->prepare("UPDATE browser_delegations_v2190 SET status='active',paused_at=NULL,updated_at=UTC_TIMESTAMP() WHERE id=?")->execute([(int)$row['id']]);
        agent_workflow_event_v1400($pdo,$uid,$runId,'delegation_resumed',(string)$row['workflow_status'],(string)$row['workflow_status'],'user','Browser delegation resumed.');
    }elseif($action==='cancel'){
        if(in_array($status,['completed','cancelled','expired'],true))return vp3_browser_delegation_public_v2190($pdo,$user,$namespace,$publicId,true)??[];
        $pdo->prepare("UPDATE browser_delegations_v2190 SET status='cancelled',cancelled_at=UTC_TIMESTAMP(),updated_at=UTC_TIMESTAMP() WHERE id=?")->execute([(int)$row['id']]);
        $pdo->prepare("UPDATE agent_workflow_actions SET status='cancelled',completed_at=UTC_TIMESTAMP() WHERE run_id=? AND owner_user_id=? AND status IN ('queued','approval_pending','executing')")->execute([$runId,$uid]);
        $pdo->prepare("UPDATE agent_workflow_runs SET status='cancelled',cancelled_at=UTC_TIMESTAMP(),progress_message='Delegation cancelled' WHERE id=? AND owner_user_id=? AND status NOT IN ('completed','cancelled')")->execute([$runId,$uid]);
        agent_workflow_event_v1400($pdo,$uid,$runId,'cancelled',(string)$row['workflow_status'],'cancelled','user','Browser delegation cancelled.');
    }else throw new InvalidArgumentException('Unknown delegation lifecycle action.');

    return vp3_browser_delegation_public_v2190($pdo,$user,$namespace,$publicId,true)
        ??throw new RuntimeException('Delegated Browser job could not be refreshed.');
}

function vp3_browser_delegation_expire_v2190(PDO $pdo,array $row): void
{
    $pdo->prepare("UPDATE browser_delegations_v2190 SET status='expired',updated_at=UTC_TIMESTAMP() WHERE id=? AND status NOT IN ('completed','cancelled','expired')")->execute([(int)$row['id']]);
    $pdo->prepare("UPDATE agent_workflow_actions SET status='cancelled',completed_at=UTC_TIMESTAMP() WHERE run_id=? AND owner_user_id=? AND status IN ('queued','approval_pending','executing')")->execute([(int)$row['workflow_run_id'],(int)$row['owner_user_id']]);
    $pdo->prepare("UPDATE agent_workflow_runs SET status='cancelled',cancelled_at=UTC_TIMESTAMP(),progress_message='Delegation expired' WHERE id=? AND owner_user_id=? AND status NOT IN ('completed','cancelled')")->execute([(int)$row['workflow_run_id'],(int)$row['owner_user_id']]);
    agent_workflow_event_v1400($pdo,(int)$row['owner_user_id'],(int)$row['workflow_run_id'],'delegation_expired',(string)$row['workflow_status'],'cancelled','system','Bounded Browser delegation expired.');
}

function vp3_browser_delegation_complete_if_done_v2190(PDO $pdo,array $row): bool
{
    $stmt=$pdo->prepare("SELECT COUNT(*) FROM agent_workflow_actions WHERE run_id=? AND owner_user_id=? AND status NOT IN ('completed','cancelled','skipped')");
    $stmt->execute([(int)$row['workflow_run_id'],(int)$row['owner_user_id']]);
    if((int)$stmt->fetchColumn()>0)return false;
    $pdo->prepare("UPDATE browser_delegations_v2190 SET status='completed',completed_at=UTC_TIMESTAMP(),updated_at=UTC_TIMESTAMP() WHERE id=?")->execute([(int)$row['id']]);
    $pdo->prepare("UPDATE agent_workflow_runs SET status='completed',completed_at=UTC_TIMESTAMP(),progress_percent=100,progress_message='Delegated Browser job completed' WHERE id=? AND owner_user_id=?")->execute([(int)$row['workflow_run_id'],(int)$row['owner_user_id']]);
    agent_workflow_event_v1400($pdo,(int)$row['owner_user_id'],(int)$row['workflow_run_id'],'completed',(string)$row['workflow_status'],'completed','browser','Delegated Browser workflow completed with verified/checkpointed steps.');
    return true;
}

function vp3_browser_delegation_mark_step_v2190(
    PDO $pdo,array $row,array $step,string $status,string $summary,string $error=''
): void {
    $completed=in_array($status,['completed','failed','cancelled','skipped'],true);
    $attemptIncrement=in_array($status,['executing','failed'],true)?1:0;
    $pdo->prepare("UPDATE agent_workflow_actions SET status=?,attempt_count=attempt_count+?,
      result_summary=?,error_class=?,started_at=COALESCE(started_at,UTC_TIMESTAMP()),
      completed_at=?,progress_percent=?,progress_message=?,updated_at=UTC_TIMESTAMP()
      WHERE id=? AND run_id=? AND owner_user_id=?")
      ->execute([
          $status,$attemptIncrement,agent_workflow_text_v1400($summary,1500),agent_workflow_text_v1400($error,80),
          $completed?gmdate('Y-m-d H:i:s'):null,$status==='completed'?100:0,
          agent_workflow_text_v1400($summary,500),(int)$step['id'],(int)$row['workflow_run_id'],(int)$row['owner_user_id']
      ]);
    $pdo->prepare("UPDATE agent_workflow_runs SET status=?,current_action_id=?,progress_message=?,updated_at=UTC_TIMESTAMP() WHERE id=? AND owner_user_id=?")
      ->execute([
          $status==='approval_pending'?'approval_pending':'executing',
          in_array($status,['queued','completed','failed','cancelled','skipped'],true)?null:(int)$step['id'],
          agent_workflow_text_v1400($summary,500),(int)$row['workflow_run_id'],(int)$row['owner_user_id']
      ]);
    agent_workflow_event_v1400($pdo,(int)$row['owner_user_id'],(int)$row['workflow_run_id'],'delegation_step_'.$status,(string)$row['workflow_status'],$status==='approval_pending'?'approval_pending':'executing','browser',$summary,['action_id'=>(int)$step['id'],'sequence_no'=>(int)$step['sequence_no']]);
}

function vp3_browser_delegation_target_v2190(PDO $pdo,array $user,array $step): ?array
{
    $target=vp3_browser_delegation_step_target_v2190($step);
    if((string)$target['target_type']==='browser_source'
        ||in_array((string)$target['target_type'],['research_project','knowledge_item','public_profile','crm_contact'],true)){
        return vp3_browser_memory_target_v2170($pdo,$user,(string)$target['target_type'],(string)$target['target_id']);
    }
    return null;
}

function vp3_browser_delegation_next_v2190(PDO $pdo,array $user,string $namespace,string $publicId): array
{
    $uid=(int)$user['id'];
    $row=vp3_browser_delegation_row_v2190($pdo,$uid,$namespace,$publicId);
    if(!$row)throw new RuntimeException('Delegated Browser job was not found.');
    if(strtotime((string)$row['expires_at'])<time()){
        vp3_browser_delegation_expire_v2190($pdo,$row);
        return ['state'=>'expired','delegation'=>vp3_browser_delegation_public_v2190($pdo,$user,$namespace,$publicId,true)];
    }
    if((string)$row['status']==='paused')return ['state'=>'paused','delegation'=>vp3_browser_delegation_public_v2190($pdo,$user,$namespace,$publicId,true)];
    if(in_array((string)$row['status'],['completed','cancelled','expired'],true))return ['state'=>(string)$row['status'],'delegation'=>vp3_browser_delegation_public_v2190($pdo,$user,$namespace,$publicId,true)];

    $steps=vp3_browser_delegation_steps_v2190($pdo,$uid,(int)$row['workflow_run_id']);
    $step=null;foreach($steps as $candidate){
        if(in_array((string)$candidate['status'],['queued','approval_pending','executing'],true)){$step=$candidate;break;}
    }
    if(!$step){
        vp3_browser_delegation_complete_if_done_v2190($pdo,$row);
        return ['state'=>'completed','delegation'=>vp3_browser_delegation_public_v2190($pdo,$user,$namespace,$publicId,true)];
    }

    $target=vp3_browser_delegation_step_target_v2190($step);
    $action=(string)$target['action_key'];
    $resolved=vp3_browser_delegation_target_v2190($pdo,$user,$step);
    if(!$resolved){
        vp3_browser_delegation_mark_step_v2190($pdo,$row,$step,'failed','The delegated VP3 target is no longer authorized.','authorization_changed');
        return ['state'=>'failed','delegation'=>vp3_browser_delegation_public_v2190($pdo,$user,$namespace,$publicId,true)];
    }

    if(!empty($step['requires_approval'])){
        if((string)$step['status']!=='approval_pending'){
            vp3_browser_delegation_mark_step_v2190($pdo,$row,$step,'approval_pending','Waiting for explicit user checkpoint.');
            foreach(vp3_browser_delegation_steps_v2190($pdo,$uid,(int)$row['workflow_run_id']) as $freshStep)if((int)$freshStep['id']===(int)$step['id']){$step=$freshStep;break;}
        }
        $handoff=vp3_browser_execution_handoff_v2180([
            'action_key'=>$action,'url'=>(string)($resolved['url']??'')
        ]);
        $pdo->prepare("UPDATE browser_delegations_v2190 SET status='checkpoint',updated_at=UTC_TIMESTAMP() WHERE id=?")->execute([(int)$row['id']]);
        return [
            'state'=>'checkpoint','step'=>vp3_browser_delegation_public_step_v2190($step),
            'handoff'=>$handoff,'delegation'=>vp3_browser_delegation_public_v2190($pdo,$user,$namespace,$publicId,true)
        ];
    }

    if($action==='inspect_source'){
        vp3_browser_delegation_mark_step_v2190($pdo,$row,$step,'completed','Authorized VP3 Source verified.');
        $pdo->prepare("UPDATE browser_delegations_v2190 SET auto_step_count=auto_step_count+1,status='active',updated_at=UTC_TIMESTAMP() WHERE id=?")->execute([(int)$row['id']]);
        $row=vp3_browser_delegation_row_v2190($pdo,$uid,$namespace,$publicId)?:$row;
        vp3_browser_delegation_complete_if_done_v2190($pdo,$row);
        return ['state'=>'advanced','delegation'=>vp3_browser_delegation_public_v2190($pdo,$user,$namespace,$publicId,true)];
    }

    if(in_array($action,['follow_source','unfollow_source'],true)){
        $follow=$action==='follow_source';
        $result=vp3_browser_source_follow_source_v2050($pdo,$uid,(string)$resolved['id'],$follow,'','','');
        if(!array_key_exists('following',$result)||((bool)$result['following'])!==$follow){
            vp3_browser_delegation_mark_step_v2190($pdo,$row,$step,'failed','VP3 could not verify the requested Source state.','verification_failed');
            return ['state'=>'failed','delegation'=>vp3_browser_delegation_public_v2190($pdo,$user,$namespace,$publicId,true)];
        }
        vp3_browser_delegation_mark_step_v2190($pdo,$row,$step,'completed',$follow?'Source followed and verified.':'Source unfollowed and verified.');
        $pdo->prepare("UPDATE browser_delegations_v2190 SET auto_step_count=auto_step_count+1,status='active',updated_at=UTC_TIMESTAMP() WHERE id=?")->execute([(int)$row['id']]);
        $row=vp3_browser_delegation_row_v2190($pdo,$uid,$namespace,$publicId)?:$row;
        vp3_browser_delegation_complete_if_done_v2190($pdo,$row);
        return ['state'=>'advanced','delegation'=>vp3_browser_delegation_public_v2190($pdo,$user,$namespace,$publicId,true)];
    }

    if(in_array($action,['open_research','open_profile','open_contact'],true)){
        if((string)$step['status']!=='executing')vp3_browser_delegation_mark_step_v2190($pdo,$row,$step,'executing','Opening authorized VP3 context for verification.');
        return [
            'state'=>'navigate',
            'step'=>vp3_browser_delegation_public_step_v2190($step),
            'navigation'=>['url'=>(string)($resolved['url']??''),'target_type'=>(string)$resolved['type'],'target_id'=>(string)$resolved['id']],
            'delegation'=>vp3_browser_delegation_public_v2190($pdo,$user,$namespace,$publicId,true)
        ];
    }

    vp3_browser_delegation_mark_step_v2190($pdo,$row,$step,'failed','Delegated step type is unavailable.','unsupported_step');
    return ['state'=>'failed','delegation'=>vp3_browser_delegation_public_v2190($pdo,$user,$namespace,$publicId,true)];
}

function vp3_browser_delegation_url_matches_v2190(string $currentUrl,string $expectedRelative): bool
{
    $current=parse_url($currentUrl);$expected=parse_url($expectedRelative);
    if(!is_array($current)||!is_array($expected))return false;
    $currentPath=(string)($current['path']??'/');$expectedPath=(string)($expected['path']??'/');
    if($currentPath!==$expectedPath)return false;
    parse_str((string)($current['query']??''),$cq);parse_str((string)($expected['query']??''),$eq);
    foreach($eq as $key=>$value)if(!array_key_exists($key,$cq)||(string)$cq[$key]!==((string)$value))return false;
    return true;
}

function vp3_browser_delegation_verify_navigation_v2190(
    PDO $pdo,array $user,string $namespace,string $publicId,int $actionId,array $context
): array {
    $uid=(int)$user['id'];$row=vp3_browser_delegation_row_v2190($pdo,$uid,$namespace,$publicId);
    if(!$row)throw new RuntimeException('Delegated Browser job was not found.');
    $steps=vp3_browser_delegation_steps_v2190($pdo,$uid,(int)$row['workflow_run_id']);$step=null;
    foreach($steps as $candidate)if((int)$candidate['id']===$actionId){$step=$candidate;break;}
    if(!$step||(string)$step['status']!=='executing')throw new RuntimeException('Delegated navigation is not waiting for verification.');
    $resolved=vp3_browser_delegation_target_v2190($pdo,$user,$step);
    if(!$resolved||!vp3_browser_delegation_url_matches_v2190((string)($context['source_url']??''),(string)($resolved['url']??''))){
        throw new RuntimeException('The active tab does not match the delegated VP3 destination yet.');
    }
    vp3_browser_delegation_mark_step_v2190($pdo,$row,$step,'completed','Navigation verified against the authorized VP3 target.');
    $pdo->prepare("UPDATE browser_delegations_v2190 SET auto_step_count=auto_step_count+1,status='active',updated_at=UTC_TIMESTAMP() WHERE id=?")->execute([(int)$row['id']]);
    $row=vp3_browser_delegation_row_v2190($pdo,$uid,$namespace,$publicId)?:$row;
    vp3_browser_delegation_complete_if_done_v2190($pdo,$row);
    return vp3_browser_delegation_public_v2190($pdo,$user,$namespace,$publicId,true)
        ??throw new RuntimeException('Delegated Browser job could not be refreshed.');
}

function vp3_browser_delegation_complete_checkpoint_v2190(
    PDO $pdo,array $user,string $namespace,string $publicId,int $actionId
): array {
    $uid=(int)$user['id'];$row=vp3_browser_delegation_row_v2190($pdo,$uid,$namespace,$publicId);
    if(!$row)throw new RuntimeException('Delegated Browser job was not found.');
    $steps=vp3_browser_delegation_steps_v2190($pdo,$uid,(int)$row['workflow_run_id']);$step=null;
    foreach($steps as $candidate)if((int)$candidate['id']===$actionId){$step=$candidate;break;}
    if(!$step||(string)$step['status']!=='approval_pending'||empty($step['requires_approval']))throw new RuntimeException('This step is not waiting for a user checkpoint.');
    vp3_browser_delegation_mark_step_v2190($pdo,$row,$step,'completed','User confirmed the delegated checkpoint was completed.');
    $pdo->prepare("UPDATE browser_delegations_v2190 SET status='active',updated_at=UTC_TIMESTAMP() WHERE id=?")->execute([(int)$row['id']]);
    $row=vp3_browser_delegation_row_v2190($pdo,$uid,$namespace,$publicId)?:$row;
    vp3_browser_delegation_complete_if_done_v2190($pdo,$row);
    return vp3_browser_delegation_public_v2190($pdo,$user,$namespace,$publicId,true)
        ??throw new RuntimeException('Delegated Browser job could not be refreshed.');
}

function vp3_browser_delegation_retry_v2190(PDO $pdo,array $user,string $namespace,string $publicId,int $actionId): array
{
    $uid=(int)$user['id'];$row=vp3_browser_delegation_row_v2190($pdo,$uid,$namespace,$publicId);
    if(!$row)throw new RuntimeException('Delegated Browser job was not found.');
    $steps=vp3_browser_delegation_steps_v2190($pdo,$uid,(int)$row['workflow_run_id']);$step=null;
    foreach($steps as $candidate)if((int)$candidate['id']===$actionId){$step=$candidate;break;}
    if(!$step||(string)$step['status']!=='failed')throw new RuntimeException('Only a failed delegated step can be retried.');
    if((int)$step['attempt_count']>=3)throw new RuntimeException('This delegated step has reached its retry limit.');
    $pdo->prepare("UPDATE agent_workflow_actions SET status='queued',error_class='',result_summary='',completed_at=NULL,available_at=UTC_TIMESTAMP(),updated_at=UTC_TIMESTAMP() WHERE id=? AND run_id=? AND owner_user_id=?")->execute([$actionId,(int)$row['workflow_run_id'],$uid]);
    $pdo->prepare("UPDATE browser_delegations_v2190 SET status='active',updated_at=UTC_TIMESTAMP() WHERE id=?")->execute([(int)$row['id']]);
    $pdo->prepare("UPDATE agent_workflow_runs SET status='approved',last_error_class='',next_attempt_at=UTC_TIMESTAMP(),progress_message='Delegated step retry queued' WHERE id=? AND owner_user_id=?")->execute([(int)$row['workflow_run_id'],$uid]);
    agent_workflow_event_v1400($pdo,$uid,(int)$row['workflow_run_id'],'delegation_retry','failed','approved','user','Failed delegated Browser step queued for bounded retry.',['action_id'=>$actionId]);
    return vp3_browser_delegation_public_v2190($pdo,$user,$namespace,$publicId,true)
        ??throw new RuntimeException('Delegated Browser job could not be refreshed.');
}
