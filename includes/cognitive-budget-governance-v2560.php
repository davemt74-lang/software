<?php
declare(strict_types=1);

/**
 * VP3 Cognitive Runtime v25.60 — Budget Guardrails & Spend Governance.
 *
 * Durable storage in this layer is governance configuration + audit only.
 * Canonical spend/token usage remains in the existing AI/subscription ledgers.
 * Hard policies may hold autonomous cloud admission until an explicit user
 * override exists; they never mutate packages, token balances, executors,
 * deadlines, workflow approval state, worker leases or execution receipts.
 */
const VP3_COGNITIVE_BUDGET_GOVERNANCE_V2560='vp3-cognitive-budget-governance-v2560-20260923';
const VP3_COGNITIVE_BUDGET_CONTRACT_V2560='cognitive-budget-governance-v1';
const VP3_COGNITIVE_BUDGET_MAX_POLICIES_V2560=32;
const VP3_COGNITIVE_BUDGET_MAX_ITEMS_V2560=12;
const VP3_COGNITIVE_BUDGET_MAX_AUDIT_V2560=80;
const VP3_COGNITIVE_BUDGET_DEFAULT_WARNING_PERCENT_V2560=80;

function vp3_cognitive_budget_schema_ready_v2560(?PDO $pdo=null): bool
{
    $pdo??=db();
    return (bool)($pdo
        &&table_exists('cognitive_budget_policies_v2560')
        &&table_exists('cognitive_budget_decisions_v2560')
        &&column_exists('cognitive_budget_policies_v2560','owner_user_id')
        &&column_exists('cognitive_budget_policies_v2560','scope_kind')
        &&column_exists('cognitive_budget_policies_v2560','period_kind')
        &&column_exists('cognitive_budget_policies_v2560','enforcement_mode')
        &&column_exists('cognitive_budget_policies_v2560','cost_limit_micros')
        &&column_exists('cognitive_budget_policies_v2560','token_limit')
        &&column_exists('cognitive_budget_decisions_v2560','decision_type')
        &&column_exists('cognitive_budget_decisions_v2560','subject_kind')
        &&column_exists('cognitive_budget_decisions_v2560','subject_key'));
}

function vp3_cognitive_budget_ensure_schema_v2560(?PDO $pdo=null): void
{
    $pdo??=db();
    if(!$pdo)throw new RuntimeException('Database connection is unavailable.');
    $pdo->exec("CREATE TABLE IF NOT EXISTS cognitive_budget_policies_v2560 (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
      owner_user_id INT UNSIGNED NOT NULL,
      label VARCHAR(190) NOT NULL DEFAULT '',
      scope_kind VARCHAR(20) NOT NULL DEFAULT 'account',
      scope_key VARCHAR(190) NOT NULL DEFAULT '',
      period_kind VARCHAR(20) NOT NULL DEFAULT 'monthly',
      enforcement_mode VARCHAR(16) NOT NULL DEFAULT 'soft',
      cost_limit_micros BIGINT UNSIGNED NULL,
      token_limit BIGINT UNSIGNED NULL,
      warning_percent SMALLINT UNSIGNED NOT NULL DEFAULT 80,
      is_active TINYINT(1) NOT NULL DEFAULT 1,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      UNIQUE KEY uq_cognitive_budget_scope (owner_user_id,scope_kind,scope_key,period_kind),
      INDEX idx_cognitive_budget_owner_active (owner_user_id,is_active,period_kind,id),
      CONSTRAINT fk_cognitive_budget_owner FOREIGN KEY (owner_user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $pdo->exec("CREATE TABLE IF NOT EXISTS cognitive_budget_decisions_v2560 (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
      owner_user_id INT UNSIGNED NOT NULL,
      policy_id BIGINT UNSIGNED NOT NULL,
      decision_type VARCHAR(32) NOT NULL,
      subject_kind VARCHAR(20) NOT NULL DEFAULT 'scope',
      subject_key VARCHAR(190) NOT NULL DEFAULT '',
      actor_kind VARCHAR(20) NOT NULL DEFAULT 'user',
      reason VARCHAR(500) NOT NULL DEFAULT '',
      expires_at DATETIME NULL,
      metadata_json TEXT NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      INDEX idx_cognitive_budget_decision_policy (policy_id,subject_kind,subject_key,id),
      INDEX idx_cognitive_budget_decision_owner (owner_user_id,created_at,id),
      CONSTRAINT fk_cognitive_budget_decision_owner FOREIGN KEY (owner_user_id) REFERENCES users(id) ON DELETE CASCADE,
      CONSTRAINT fk_cognitive_budget_decision_policy FOREIGN KEY (policy_id) REFERENCES cognitive_budget_policies_v2560(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

function vp3_cognitive_budget_ready_v2560(?PDO $pdo=null): bool
{
    $pdo??=db();
    return (bool)($pdo
        &&vp3_cognitive_budget_schema_ready_v2560($pdo)
        &&function_exists('vp3_cognitive_economics_apply_v2550')
        &&function_exists('ai_usage_accounting_v032_schema_ready')
        &&ai_usage_accounting_v032_schema_ready($pdo));
}

function vp3_cognitive_budget_text_v2560(mixed $value,int $limit=190): string
{
    $text=preg_replace('/\s+/u',' ',trim((string)$value))??'';
    return mb_strimwidth($text,0,max(1,$limit),'…');
}

function vp3_cognitive_budget_scope_kind_v2560(mixed $value): string
{
    $kind=strtolower(trim((string)$value));
    return in_array($kind,['account','goal','agent','project'],true)?$kind:'account';
}

function vp3_cognitive_budget_scope_key_v2560(string $kind,mixed $value): string
{
    if($kind==='account')return '';
    $key=vp3_cognitive_budget_text_v2560($value,190);
    if(in_array($kind,['goal','agent'],true)){
        $id=max(0,(int)$key);
        if($id<1)throw new RuntimeException(ucfirst($kind).' budget scope requires a valid id.');
        return (string)$id;
    }
    if($key==='')throw new RuntimeException('Project budget scope requires a project/source key.');
    return $key;
}

function vp3_cognitive_budget_period_kind_v2560(mixed $value): string
{
    $kind=strtolower(trim((string)$value));
    if(!in_array($kind,['daily','weekly','monthly'],true))throw new RuntimeException('Choose a daily, weekly or monthly budget period.');
    return $kind;
}

function vp3_cognitive_budget_mode_v2560(mixed $value): string
{
    $mode=strtolower(trim((string)$value));
    if(!in_array($mode,['soft','hard'],true))throw new RuntimeException('Choose soft warning or hard approval budget enforcement.');
    return $mode;
}

function vp3_cognitive_budget_period_bounds_v2560(string $kind,int $now=0): array
{
    $now=$now>0?$now:time();
    $kind=vp3_cognitive_budget_period_kind_v2560($kind);
    if($kind==='daily'){
        $start=strtotime(gmdate('Y-m-d 00:00:00',$now).' UTC')?:$now;
        $end=$start+86400;
    }elseif($kind==='weekly'){
        $weekday=(int)gmdate('N',$now);
        $dayStart=strtotime(gmdate('Y-m-d 00:00:00',$now).' UTC')?:$now;
        $start=$dayStart-(($weekday-1)*86400);
        $end=$start+(7*86400);
    }else{
        $start=strtotime(gmdate('Y-m-01 00:00:00',$now).' UTC')?:$now;
        $end=strtotime('+1 month',$start)?:($start+31*86400);
    }
    $elapsed=max(0,$now-$start);$duration=max(1,$end-$start);
    return [
        'kind'=>$kind,'start_ts'=>$start,'end_ts'=>$end,
        'start_at'=>gmdate('c',$start),'end_at'=>gmdate('c',$end),
        'elapsed_ratio'=>round(max(0.0,min(1.0,$elapsed/$duration)),6),
        'seconds_remaining'=>max(0,$end-$now),
    ];
}

function vp3_cognitive_budget_public_policy_v2560(array $row): array
{
    return [
        'id'=>(int)($row['id']??0),
        'label'=>(string)($row['label']??''),
        'scope_kind'=>(string)($row['scope_kind']??'account'),
        'scope_key'=>(string)($row['scope_key']??''),
        'period_kind'=>(string)($row['period_kind']??'monthly'),
        'enforcement_mode'=>(string)($row['enforcement_mode']??'soft'),
        'cost_limit_micros'=>$row['cost_limit_micros']===null?null:max(0,(int)$row['cost_limit_micros']),
        'token_limit'=>$row['token_limit']===null?null:max(0,(int)$row['token_limit']),
        'warning_percent'=>max(1,min(99,(int)($row['warning_percent']??VP3_COGNITIVE_BUDGET_DEFAULT_WARNING_PERCENT_V2560))),
        'is_active'=>!empty($row['is_active']),
        'created_at'=>(string)($row['created_at']??''),
        'updated_at'=>(string)($row['updated_at']??''),
    ];
}

function vp3_cognitive_budget_policy_rows_v2560(PDO $pdo,array $user,bool $activeOnly=true): array
{
    $uid=(int)($user['id']??0);
    if($uid<1||!vp3_cognitive_budget_schema_ready_v2560($pdo))return [];
    $sql='SELECT * FROM cognitive_budget_policies_v2560 WHERE owner_user_id=?'
        .($activeOnly?' AND is_active=1':'')
        .' ORDER BY is_active DESC,scope_kind,scope_key,period_kind,id LIMIT '.VP3_COGNITIVE_BUDGET_MAX_POLICIES_V2560;
    $stmt=$pdo->prepare($sql);$stmt->execute([$uid]);
    return array_map('vp3_cognitive_budget_public_policy_v2560',$stmt->fetchAll(PDO::FETCH_ASSOC)?:[]);
}

function vp3_cognitive_budget_policy_row_v2560(PDO $pdo,int $uid,int $policyId,bool $forUpdate=false): ?array
{
    if($uid<1||$policyId<1||!vp3_cognitive_budget_schema_ready_v2560($pdo))return null;
    $stmt=$pdo->prepare('SELECT * FROM cognitive_budget_policies_v2560 WHERE id=? AND owner_user_id=? LIMIT 1'.($forUpdate?' FOR UPDATE':''));
    $stmt->execute([$policyId,$uid]);$row=$stmt->fetch(PDO::FETCH_ASSOC);
    return is_array($row)?$row:null;
}

function vp3_cognitive_budget_audit_v2560(
    PDO $pdo,int $uid,int $policyId,string $type,string $subjectKind='scope',
    string $subjectKey='',string $reason='',?string $expiresAt=null,array $metadata=[]
): int {
    $type=vp3_cognitive_budget_text_v2560($type,32);
    $subjectKind=vp3_cognitive_budget_text_v2560($subjectKind,20);
    $subjectKey=vp3_cognitive_budget_text_v2560($subjectKey,190);
    $reason=vp3_cognitive_budget_text_v2560($reason,500);
    $json=$metadata?json_encode($metadata,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE):null;
    $stmt=$pdo->prepare('INSERT INTO cognitive_budget_decisions_v2560
      (owner_user_id,policy_id,decision_type,subject_kind,subject_key,actor_kind,reason,expires_at,metadata_json)
      VALUES (?,?,?,?,?,?,?,?,?)');
    $stmt->execute([$uid,$policyId,$type,$subjectKind,$subjectKey,'user',$reason,$expiresAt,$json?:null]);
    return (int)$pdo->lastInsertId();
}

function vp3_cognitive_budget_policy_save_v2560(PDO $pdo,array $user,array $input): array
{
    $uid=(int)($user['id']??0);
    if($uid<1)throw new RuntimeException('Sign in to manage AI budgets.');
    if(!vp3_cognitive_budget_schema_ready_v2560($pdo))throw new RuntimeException('Budget Governance is not ready. Run /upgrade.php.');
    $id=max(0,(int)($input['id']??0));
    $scopeKind=vp3_cognitive_budget_scope_kind_v2560($input['scope_kind']??'account');
    $scopeKey=vp3_cognitive_budget_scope_key_v2560($scopeKind,$input['scope_key']??'');
    $period=vp3_cognitive_budget_period_kind_v2560($input['period_kind']??'monthly');
    $mode=vp3_cognitive_budget_mode_v2560($input['enforcement_mode']??'soft');
    $warning=max(50,min(99,(int)($input['warning_percent']??VP3_COGNITIVE_BUDGET_DEFAULT_WARNING_PERCENT_V2560)));
    $cost=array_key_exists('cost_limit_micros',$input)&&$input['cost_limit_micros']!==''&&$input['cost_limit_micros']!==null
        ?max(0,(int)$input['cost_limit_micros']):null;
    $tokens=array_key_exists('token_limit',$input)&&$input['token_limit']!==''&&$input['token_limit']!==null
        ?max(0,(int)$input['token_limit']):null;
    if($cost===null&&$tokens===null)throw new RuntimeException('Set a USD estimate limit, a cloud-token limit, or both.');
    $label=vp3_cognitive_budget_text_v2560($input['label']??'',190);
    if($label==='')$label=ucfirst($scopeKind).' '.ucfirst($period).' AI Budget';

    $before=$id>0?vp3_cognitive_budget_policy_row_v2560($pdo,$uid,$id,true):null;
    if($id>0&&!$before)throw new RuntimeException('Budget policy not found.');

    if($before){
        $stmt=$pdo->prepare('UPDATE cognitive_budget_policies_v2560
          SET label=?,scope_kind=?,scope_key=?,period_kind=?,enforcement_mode=?,
              cost_limit_micros=?,token_limit=?,warning_percent=?,is_active=1,updated_at=NOW()
          WHERE id=? AND owner_user_id=?');
        $stmt->execute([$label,$scopeKind,$scopeKey,$period,$mode,$cost,$tokens,$warning,$id,$uid]);
        $event='policy_updated';
    }else{
        $stmt=$pdo->prepare('INSERT INTO cognitive_budget_policies_v2560
          (owner_user_id,label,scope_kind,scope_key,period_kind,enforcement_mode,cost_limit_micros,token_limit,warning_percent,is_active)
          VALUES (?,?,?,?,?,?,?,?,?,1)
          ON DUPLICATE KEY UPDATE label=VALUES(label),enforcement_mode=VALUES(enforcement_mode),
            cost_limit_micros=VALUES(cost_limit_micros),token_limit=VALUES(token_limit),
            warning_percent=VALUES(warning_percent),is_active=1,updated_at=NOW()');
        $stmt->execute([$uid,$label,$scopeKind,$scopeKey,$period,$mode,$cost,$tokens,$warning]);
        if((int)$pdo->lastInsertId()>0)$id=(int)$pdo->lastInsertId();
        else{
            $q=$pdo->prepare('SELECT id FROM cognitive_budget_policies_v2560
              WHERE owner_user_id=? AND scope_kind=? AND scope_key=? AND period_kind=? LIMIT 1');
            $q->execute([$uid,$scopeKind,$scopeKey,$period]);$id=(int)$q->fetchColumn();
        }
        $event='policy_saved';
    }
    $saved=vp3_cognitive_budget_policy_row_v2560($pdo,$uid,$id)?:throw new RuntimeException('Budget policy could not be loaded.');
    vp3_cognitive_budget_audit_v2560($pdo,$uid,$id,$event,'scope',$scopeKind.':'.$scopeKey,'Budget policy saved.',null,[
        'period_kind'=>$period,'enforcement_mode'=>$mode,'cost_limit_micros'=>$cost,
        'token_limit'=>$tokens,'warning_percent'=>$warning,
    ]);
    return vp3_cognitive_budget_public_policy_v2560($saved);
}

function vp3_cognitive_budget_policy_disable_v2560(PDO $pdo,array $user,int $policyId): array
{
    $uid=(int)($user['id']??0);$row=vp3_cognitive_budget_policy_row_v2560($pdo,$uid,$policyId,true);
    if(!$row)throw new RuntimeException('Budget policy not found.');
    $pdo->prepare('UPDATE cognitive_budget_policies_v2560 SET is_active=0,updated_at=NOW() WHERE id=? AND owner_user_id=?')->execute([$policyId,$uid]);
    vp3_cognitive_budget_audit_v2560($pdo,$uid,$policyId,'policy_disabled','scope',(string)$row['scope_kind'].':'.(string)$row['scope_key'],'Budget policy disabled.');
    $row['is_active']=0;return vp3_cognitive_budget_public_policy_v2560($row);
}

function vp3_cognitive_budget_subject_v2560(string $kind,mixed $key): array
{
    $kind=strtolower(trim($kind));
    if(!in_array($kind,['goal','run','scope'],true))throw new RuntimeException('Budget override subject is invalid.');
    $key=vp3_cognitive_budget_text_v2560($key,190);
    if($key==='')throw new RuntimeException('Budget override subject is required.');
    if(in_array($kind,['goal','run'],true)){
        $id=max(0,(int)$key);if($id<1)throw new RuntimeException('Budget override subject id is invalid.');
        $key=(string)$id;
    }
    return [$kind,$key];
}

function vp3_cognitive_budget_override_v2560(
    PDO $pdo,array $user,int $policyId,string $subjectKind,string $subjectKey,
    bool $grant,string $reason='',?int $expiresTs=null
): array {
    $uid=(int)($user['id']??0);$policy=vp3_cognitive_budget_policy_row_v2560($pdo,$uid,$policyId);
    if(!$policy)throw new RuntimeException('Budget policy not found.');
    [$subjectKind,$subjectKey]=vp3_cognitive_budget_subject_v2560($subjectKind,$subjectKey);
    $expiresAt=null;
    if($grant){
        $bounds=vp3_cognitive_budget_period_bounds_v2560((string)$policy['period_kind']);
        $expiresTs=$expiresTs&&$expiresTs>time()?min($expiresTs,(int)$bounds['end_ts']):(int)$bounds['end_ts'];
        $expiresAt=gmdate('Y-m-d H:i:s',$expiresTs);
    }
    $id=vp3_cognitive_budget_audit_v2560(
        $pdo,$uid,$policyId,$grant?'override_granted':'override_revoked',
        $subjectKind,$subjectKey,$reason!==''?$reason:($grant?'User approved a bounded budget override.':'User revoked a budget override.'),
        $expiresAt
    );
    return [
        'id'=>$id,'policy_id'=>$policyId,'subject_kind'=>$subjectKind,'subject_key'=>$subjectKey,
        'active'=>$grant,'expires_at'=>$expiresAt??'',
    ];
}

function vp3_cognitive_budget_active_override_v2560(
    PDO $pdo,int $uid,int $policyId,string $subjectKind,string $subjectKey,int $now=0
): ?array {
    if($uid<1||$policyId<1||!vp3_cognitive_budget_schema_ready_v2560($pdo))return null;
    [$subjectKind,$subjectKey]=vp3_cognitive_budget_subject_v2560($subjectKind,$subjectKey);
    $stmt=$pdo->prepare("SELECT * FROM cognitive_budget_decisions_v2560
      WHERE owner_user_id=? AND policy_id=? AND subject_kind=? AND subject_key=?
        AND decision_type IN ('override_granted','override_revoked')
      ORDER BY id DESC LIMIT 1");
    $stmt->execute([$uid,$policyId,$subjectKind,$subjectKey]);$row=$stmt->fetch(PDO::FETCH_ASSOC);
    if(!is_array($row)||(string)$row['decision_type']!=='override_granted')return null;
    $now=$now>0?$now:time();$expires=strtotime((string)($row['expires_at']??''))?:0;
    if($expires>0&&$expires<=$now)return null;
    return [
        'id'=>(int)$row['id'],'policy_id'=>$policyId,'subject_kind'=>$subjectKind,'subject_key'=>$subjectKey,
        'expires_at'=>(string)($row['expires_at']??''),'reason'=>(string)($row['reason']??''),
    ];
}

function vp3_cognitive_budget_active_overrides_v2560(PDO $pdo,array $user,int $now=0): array
{
    $uid=(int)($user['id']??0);$now=$now>0?$now:time();
    if($uid<1||!vp3_cognitive_budget_schema_ready_v2560($pdo))return [];
    $stmt=$pdo->prepare("SELECT d.*,p.label AS policy_label,p.period_kind
      FROM cognitive_budget_decisions_v2560 d
      INNER JOIN cognitive_budget_policies_v2560 p ON p.id=d.policy_id AND p.owner_user_id=d.owner_user_id
      WHERE d.owner_user_id=? AND d.decision_type IN ('override_granted','override_revoked')
      ORDER BY d.id DESC LIMIT ".VP3_COGNITIVE_BUDGET_MAX_AUDIT_V2560);
    $stmt->execute([$uid]);$seen=[];$out=[];
    foreach($stmt->fetchAll(PDO::FETCH_ASSOC)?:[] as $row){
        $key=(int)$row['policy_id'].'|'.(string)$row['subject_kind'].'|'.(string)$row['subject_key'];
        if(isset($seen[$key]))continue;$seen[$key]=true;
        if((string)$row['decision_type']!=='override_granted')continue;
        $expires=strtotime((string)($row['expires_at']??''))?:0;
        if($expires>0&&$expires<=$now)continue;
        $out[]=[
            'id'=>(int)$row['id'],'policy_id'=>(int)$row['policy_id'],
            'policy_label'=>(string)$row['policy_label'],
            'subject_kind'=>(string)$row['subject_kind'],'subject_key'=>(string)$row['subject_key'],
            'reason'=>(string)$row['reason'],'expires_at'=>(string)($row['expires_at']??''),
            'created_at'=>(string)$row['created_at'],
        ];
    }
    return $out;
}

function vp3_cognitive_budget_audit_rows_v2560(PDO $pdo,array $user,int $limit=30): array
{
    $uid=(int)($user['id']??0);$limit=max(1,min(VP3_COGNITIVE_BUDGET_MAX_AUDIT_V2560,$limit));
    if($uid<1||!vp3_cognitive_budget_schema_ready_v2560($pdo))return [];
    $stmt=$pdo->prepare('SELECT d.*,p.label AS policy_label FROM cognitive_budget_decisions_v2560 d
      INNER JOIN cognitive_budget_policies_v2560 p ON p.id=d.policy_id AND p.owner_user_id=d.owner_user_id
      WHERE d.owner_user_id=? ORDER BY d.id DESC LIMIT '.$limit);
    $stmt->execute([$uid]);$out=[];
    foreach($stmt->fetchAll(PDO::FETCH_ASSOC)?:[] as $row){
        $out[]=[
            'id'=>(int)$row['id'],'policy_id'=>(int)$row['policy_id'],'policy_label'=>(string)$row['policy_label'],
            'decision_type'=>(string)$row['decision_type'],'subject_kind'=>(string)$row['subject_kind'],
            'subject_key'=>(string)$row['subject_key'],'reason'=>(string)$row['reason'],
            'expires_at'=>(string)($row['expires_at']??''),'created_at'=>(string)$row['created_at'],
        ];
    }
    return $out;
}

function vp3_cognitive_budget_run_metadata_v2560(PDO $pdo,int $uid,array $runIds): array
{
    $runIds=array_values(array_unique(array_filter(array_map('intval',$runIds),static fn(int $id): bool=>$id>0)));
    if($uid<1||!$runIds||!table_exists('agent_workflow_runs'))return [];
    $runIds=array_slice($runIds,0,200);$ph=implode(',',array_fill(0,count($runIds),'?'));
    $stmt=$pdo->prepare("SELECT id,agent_id,source_kind,source_key,execution_target FROM agent_workflow_runs
      WHERE owner_user_id=? AND id IN ({$ph})");
    $stmt->execute(array_merge([$uid],$runIds));$out=[];
    foreach($stmt->fetchAll(PDO::FETCH_ASSOC)?:[] as $row)$out[(int)$row['id']]=$row;
    return $out;
}

function vp3_cognitive_budget_item_context_v2560(array $item,array $runMeta): array
{
    $agentIds=[];$projectKeys=[];
    foreach((array)($item['workflow_run_ids']??[]) as $runId){
        $row=$runMeta[(int)$runId]??null;if(!$row)continue;
        $agentId=max(0,(int)($row['agent_id']??0));if($agentId>0)$agentIds[$agentId]=true;
        $sourceKey=vp3_cognitive_budget_text_v2560($row['source_key']??'',190);
        if($sourceKey!=='')$projectKeys[$sourceKey]=true;
    }
    return [
        'goal_id'=>(int)($item['goal_id']??0),
        'run_ids'=>array_values(array_unique(array_filter(array_map('intval',(array)($item['workflow_run_ids']??[]))))),
        'agent_ids'=>array_map('intval',array_keys($agentIds)),
        'project_keys'=>array_values(array_keys($projectKeys)),
    ];
}

function vp3_cognitive_budget_allocation_compare_v2560(array $a,array $b): int
{
    $aCommit=(float)($a['commitment_protection_score']??0.0);
    $bCommit=(float)($b['commitment_protection_score']??0.0);
    $x=$bCommit<=>$aCommit;if($x!==0)return $x;
    $aRisk=!empty($a['commitment_at_risk'])?0:1;$bRisk=!empty($b['commitment_at_risk'])?0:1;
    if($aRisk!==$bRisk)return $aRisk<=>$bRisk;
    $aTarget=strtotime((string)($a['target_date']??''))?:PHP_INT_MAX;
    $bTarget=strtotime((string)($b['target_date']??''))?:PHP_INT_MAX;
    if($aTarget!==$bTarget)return $aTarget<=>$bTarget;
    $x=((float)($b['optimization_strategy_score']??$b['forecast_sequence_score']??$b['score']??0.0))<=>
        ((float)($a['optimization_strategy_score']??$a['forecast_sequence_score']??$a['score']??0.0));
    if($x!==0)return $x;
    return ((int)($a['goal_id']??0))<=>((int)($b['goal_id']??0));
}

function vp3_cognitive_budget_policy_matches_v2560(array $policy,array $context): bool
{
    $kind=(string)($policy['scope_kind']??'account');$key=(string)($policy['scope_key']??'');
    if($kind==='account')return true;
    if($kind==='goal')return (int)$key>0&&(int)$key===(int)($context['goal_id']??0);
    if($kind==='agent')return in_array((int)$key,array_map('intval',(array)($context['agent_ids']??[])),true);
    if($kind==='project')return in_array($key,(array)($context['project_keys']??[]),true);
    return false;
}

function vp3_cognitive_budget_usage_v2560(
    PDO $pdo,int $uid,array $policy,array $context=[],int $now=0
): array {
    $bounds=vp3_cognitive_budget_period_bounds_v2560((string)($policy['period_kind']??'monthly'),$now);
    $empty=[
        'known_cost_micros'=>0,'unknown_cost_requests'=>0,'cloud_tokens_charged'=>0,
        'requests'=>0,'period'=>$bounds,'authority'=>'ai_execution_ledger_v032',
    ];
    if($uid<1||!ai_usage_accounting_v032_schema_ready($pdo))return $empty;
    $where=['l.user_id=?','l.created_at>=?','l.created_at<?'];
    $params=[$uid,gmdate('Y-m-d H:i:s',(int)$bounds['start_ts']),gmdate('Y-m-d H:i:s',(int)$bounds['end_ts'])];
    $join='';
    $kind=(string)($policy['scope_kind']??'account');$key=(string)($policy['scope_key']??'');
    if($kind==='goal'){
        if(!table_exists('agent_goal_objectives'))return $empty;
        try{
            $stmt=$pdo->prepare("SELECT COUNT(*) requests,
              COALESCE(SUM(l.estimated_cost_micros / GREATEST(1,x.goal_count)),0) known_cost_micros,
              SUM(CASE WHEN l.estimated_cost_micros IS NULL THEN 1 ELSE 0 END) unknown_cost_requests,
              COALESCE(SUM(l.cloud_tokens_charged / GREATEST(1,x.goal_count)),0) cloud_tokens_charged
              FROM ai_execution_ledger l
              INNER JOIN agent_goal_objectives mine
                ON mine.objective_run_id=l.run_id AND mine.owner_user_id=l.user_id AND mine.goal_id=?
              INNER JOIN (
                SELECT owner_user_id,objective_run_id,COUNT(DISTINCT goal_id) goal_count
                FROM agent_goal_objectives GROUP BY owner_user_id,objective_run_id
              ) x ON x.owner_user_id=mine.owner_user_id AND x.objective_run_id=mine.objective_run_id
              WHERE l.user_id=? AND l.created_at>=? AND l.created_at<?");
            $stmt->execute([(int)$key,$uid,$params[1],$params[2]]);$row=$stmt->fetch(PDO::FETCH_ASSOC)?:[];
            return [
                'known_cost_micros'=>max(0,(int)round((float)($row['known_cost_micros']??0))),
                'unknown_cost_requests'=>max(0,(int)($row['unknown_cost_requests']??0)),
                'cloud_tokens_charged'=>max(0,(int)round((float)($row['cloud_tokens_charged']??0))),
                'requests'=>max(0,(int)($row['requests']??0)),
                'period'=>$bounds,'authority'=>'ai_execution_ledger_v032_proportional_goal_attribution',
            ];
        }catch(Throwable $e){return $empty;}
    }elseif($kind==='agent'){
        $where[]='l.agent_id=?';$params[]=(int)$key;
    }elseif($kind==='project'){
        $join=' INNER JOIN agent_workflow_runs r ON r.id=l.run_id AND r.owner_user_id=l.user_id';
        $where[]='r.source_key=?';$params[]=$key;
    }
    try{
        $stmt=$pdo->prepare("SELECT COUNT(*) requests,
          COALESCE(SUM(l.estimated_cost_micros),0) known_cost_micros,
          SUM(CASE WHEN l.estimated_cost_micros IS NULL THEN 1 ELSE 0 END) unknown_cost_requests,
          COALESCE(SUM(l.cloud_tokens_charged),0) cloud_tokens_charged
          FROM ai_execution_ledger l{$join} WHERE ".implode(' AND ',$where));
        $stmt->execute($params);$row=$stmt->fetch(PDO::FETCH_ASSOC)?:[];
        return [
            'known_cost_micros'=>max(0,(int)($row['known_cost_micros']??0)),
            'unknown_cost_requests'=>max(0,(int)($row['unknown_cost_requests']??0)),
            'cloud_tokens_charged'=>max(0,(int)($row['cloud_tokens_charged']??0)),
            'requests'=>max(0,(int)($row['requests']??0)),
            'period'=>$bounds,'authority'=>'ai_execution_ledger_v032',
        ];
    }catch(Throwable $e){return $empty;}
}

function vp3_cognitive_budget_goal_projection_v2560(
    array $item,?array $economicsRow,array $accountUsage=[]
): array {
    if((string)($item['execution_mode']??'manual')!=='autonomous'||(string)($item['executor']??'cloud')!=='cloud'){
        return ['cost_micros'=>0,'tokens'=>0,'cost_known'=>true,'tokens_known'=>true,'work_units'=>0.0];
    }
    $units=function_exists('vp3_cognitive_resource_work_units_v2520')
        ?vp3_cognitive_resource_work_units_v2520($item)
        :(function_exists('vp3_cognitive_forecast_work_units_v2490')?vp3_cognitive_forecast_work_units_v2490($item):1.0);
    $units=max(0.25,min(8.0,(float)$units));
    $goalAvgCost=$economicsRow&&is_int($economicsRow['average_attributed_known_cost_micros']??null)
        ?(int)$economicsRow['average_attributed_known_cost_micros']:null;
    $accountAvgCost=is_int($accountUsage['cloud_average_known_cost_micros']??null)
        ?(int)$accountUsage['cloud_average_known_cost_micros']:null;
    $avgCost=$goalAvgCost??$accountAvgCost;
    $goalTokens=$economicsRow?max(0,(int)($economicsRow['attributed_cloud_tokens_charged']??0)):0;
    $goalRequests=$economicsRow?max(0,(int)($economicsRow['attributed_cloud_requests']??0)):0;
    $avgTokens=$goalRequests>0?(int)round($goalTokens/$goalRequests):null;
    if($avgTokens===null){
        $cloudRequests=max(0,(int)($accountUsage['cloud_requests']??0));
        $cloudTokens=max(0,(int)($accountUsage['cloud_tokens_charged']??0));
        if($cloudRequests>0)$avgTokens=(int)round($cloudTokens/$cloudRequests);
    }
    return [
        'cost_micros'=>$avgCost===null?null:max(0,(int)round($avgCost*$units)),
        'tokens'=>$avgTokens===null?null:max(0,(int)round($avgTokens*$units)),
        'cost_known'=>$avgCost!==null,'tokens_known'=>$avgTokens!==null,'work_units'=>round($units,2),
    ];
}

function vp3_cognitive_budget_ratio_v2560(int|float $used,?int $limit): ?float
{
    if($limit===null)return null;
    if($limit<=0)return $used>0?INF:0.0;
    return max(0.0,$used/$limit);
}

function vp3_cognitive_budget_state_v2560(
    array $policy,array $usage,int|float $projectedCost,int|float $projectedTokens,
    int $unknownProjectedCost=0,int $unknownProjectedTokens=0
): array {
    $costLimit=$policy['cost_limit_micros'];$tokenLimit=$policy['token_limit'];
    $costActual=max(0,(int)$usage['known_cost_micros']);$tokenActual=max(0,(int)$usage['cloud_tokens_charged']);
    $costForecast=$costActual+max(0,(int)round($projectedCost));
    $tokenForecast=$tokenActual+max(0,(int)round($projectedTokens));
    $costActualRatio=vp3_cognitive_budget_ratio_v2560($costActual,$costLimit);
    $tokenActualRatio=vp3_cognitive_budget_ratio_v2560($tokenActual,$tokenLimit);
    $costForecastRatio=vp3_cognitive_budget_ratio_v2560($costForecast,$costLimit);
    $tokenForecastRatio=vp3_cognitive_budget_ratio_v2560($tokenForecast,$tokenLimit);
    $ratios=[];
    foreach([$costForecastRatio,$tokenForecastRatio] as $ratio){
        if($ratio===null)continue;
        $ratios[]=is_finite((float)$ratio)?max(0.0,(float)$ratio):2.0;
    }
    $maxForecast=$ratios?max($ratios):0.0;
    $warning=max(0.50,min(0.99,((int)($policy['warning_percent']??80))/100));
    $actualExceeded=($costActualRatio!==null&&$costActualRatio>=1.0)||($tokenActualRatio!==null&&$tokenActualRatio>=1.0);
    $forecastExceeded=($costForecastRatio!==null&&$costForecastRatio>1.0)||($tokenForecastRatio!==null&&$tokenForecastRatio>1.0);
    $unknownCost=($costLimit!==null)&&((int)($usage['unknown_cost_requests']??0)>0||$unknownProjectedCost>0);
    $unknownTokens=($tokenLimit!==null)&&$unknownProjectedTokens>0;
    $state='healthy';
    if($actualExceeded)$state='exhausted';
    elseif($unknownCost||$unknownTokens)$state='uncertain';
    elseif($forecastExceeded)$state='at_risk';
    elseif($maxForecast>=$warning)$state='watch';
    return [
        'state'=>$state,'warning_ratio'=>$warning,
        'actual_exceeded'=>$actualExceeded,'forecast_exceeded'=>$forecastExceeded,
        'unknown_cost'=>$unknownCost,'unknown_tokens'=>$unknownTokens,
        'cost_actual_ratio'=>$costActualRatio,'token_actual_ratio'=>$tokenActualRatio,
        'cost_forecast_ratio'=>$costForecastRatio,'token_forecast_ratio'=>$tokenForecastRatio,
        'max_forecast_ratio'=>is_finite($maxForecast)?round($maxForecast,4):null,
        'forecast_cost_micros'=>$costForecast,'forecast_tokens'=>$tokenForecast,
    ];
}

function vp3_cognitive_budget_apply_v2560(
    PDO $pdo,array $user,array $items,array $capacity,array $economics,int $now=0
): array {
    $uid=(int)($user['id']??0);$now=$now>0?$now:time();
    $policies=vp3_cognitive_budget_policy_rows_v2560($pdo,$user,true);
    $empty=[
        'build'=>VP3_COGNITIVE_BUDGET_GOVERNANCE_V2560,'contract'=>VP3_COGNITIVE_BUDGET_CONTRACT_V2560,
        'configured'=>false,'focus'=>null,'policies'=>[],'held_goals'=>[],'counts'=>[],
        'authority'=>[],'projection_only'=>true,
    ];
    if($uid<1||!$policies)return ['items'=>$items,'budget_governance'=>$empty];

    $runIds=[];foreach($items as $item)foreach((array)($item['workflow_run_ids']??[]) as $runId)$runIds[]=(int)$runId;
    $runMeta=vp3_cognitive_budget_run_metadata_v2560($pdo,$uid,$runIds);
    $econByGoal=[];foreach((array)($economics['goals']??[]) as $row)if(is_array($row))$econByGoal[(int)($row['goal_id']??0)]=$row;
    $accountUsage=(array)($economics['usage']??[]);
    $itemContexts=[];$projections=[];
    foreach($items as $index=>$item){
        if(!is_array($item))continue;
        $goalId=(int)($item['goal_id']??0);
        $itemContexts[$goalId]=vp3_cognitive_budget_item_context_v2560($item,$runMeta);
        $projections[$goalId]=vp3_cognitive_budget_goal_projection_v2560($item,$econByGoal[$goalId]??null,$accountUsage);
    }

    $policySnapshots=[];$heldByGoal=[];$overridesByGoal=[];$softPressureByGoal=[];
    foreach($policies as $policy){
        $policyId=(int)$policy['id'];
        $applicable=[];
        foreach($items as $item){
            if(!is_array($item))continue;$goalId=(int)($item['goal_id']??0);
            if((string)($item['execution_mode']??'manual')!=='autonomous'||(string)($item['executor']??'cloud')!=='cloud')continue;
            if(vp3_cognitive_budget_policy_matches_v2560($policy,$itemContexts[$goalId]??[]))$applicable[]=$item;
        }

        usort($applicable,'vp3_cognitive_budget_allocation_compare_v2560');

        $usageContext=[];
        if((string)$policy['scope_kind']==='goal'){
            $goalId=(int)$policy['scope_key'];$usageContext=$itemContexts[$goalId]??['run_ids'=>[]];
        }
        $usage=vp3_cognitive_budget_usage_v2560($pdo,$uid,$policy,$usageContext,$now);
        $projectedCost=0;$projectedTokens=0;$unknownCost=0;$unknownTokens=0;
        foreach($applicable as $item){
            $goalId=(int)$item['goal_id'];$projection=$projections[$goalId]??[];
            if(($projection['cost_micros']??null)===null)$unknownCost++;else $projectedCost+=(int)$projection['cost_micros'];
            if(($projection['tokens']??null)===null)$unknownTokens++;else $projectedTokens+=(int)$projection['tokens'];
        }
        $state=vp3_cognitive_budget_state_v2560($policy,$usage,$projectedCost,$projectedTokens,$unknownCost,$unknownTokens);
        $remainingCost=$policy['cost_limit_micros']===null?null:max(0,(int)$policy['cost_limit_micros']-(int)$usage['known_cost_micros']);
        $remainingTokens=$policy['token_limit']===null?null:max(0,(int)$policy['token_limit']-(int)$usage['cloud_tokens_charged']);
        $admitted=[];$held=[];$overrideGoalIds=[];
        $availableCost=$remainingCost;$availableTokens=$remainingTokens;
        $hard=(string)$policy['enforcement_mode']==='hard';

        foreach($applicable as $item){
            $goalId=(int)$item['goal_id'];$projection=$projections[$goalId]??[];
            $override=vp3_cognitive_budget_active_override_v2560($pdo,$uid,$policyId,'goal',(string)$goalId,$now)
                ?:vp3_cognitive_budget_active_override_v2560($pdo,$uid,$policyId,'scope',(string)$policy['scope_kind'].':'.(string)$policy['scope_key'],$now);
            if($override){
                $admitted[]=$goalId;$overrideGoalIds[]=$goalId;$overridesByGoal[$goalId][]=$policyId;
                if($availableCost!==null&&is_int($projection['cost_micros']??null))$availableCost-=(int)$projection['cost_micros'];
                if($availableTokens!==null&&is_int($projection['tokens']??null))$availableTokens-=(int)$projection['tokens'];
                continue;
            }
            if(!$hard){
                $admitted[]=$goalId;
                $pressure=(float)($state['max_forecast_ratio']??0.0);
                if($pressure>=((int)$policy['warning_percent']/100))$softPressureByGoal[$goalId]=max((float)($softPressureByGoal[$goalId]??0.0),min(1.5,$pressure));
                continue;
            }
            $hold=false;$reason='';
            if($policy['cost_limit_micros']!==null){
                if((int)$usage['unknown_cost_requests']>0){$hold=true;$reason='actual_cost_unknown';}
                elseif(($projection['cost_micros']??null)===null){$hold=true;$reason='projected_cost_unknown';}
                elseif($availableCost!==null&&(int)$projection['cost_micros']>$availableCost){$hold=true;$reason='cost_limit';}
            }
            if(!$hold&&$policy['token_limit']!==null){
                if(($projection['tokens']??null)===null){$hold=true;$reason='projected_tokens_unknown';}
                elseif($availableTokens!==null&&(int)$projection['tokens']>$availableTokens){$hold=true;$reason='token_limit';}
            }
            if($hold){
                $held[]=$goalId;$heldByGoal[$goalId][]=[$policyId,$reason];
            }else{
                $admitted[]=$goalId;
                if($availableCost!==null&&is_int($projection['cost_micros']??null))$availableCost-=(int)$projection['cost_micros'];
                if($availableTokens!==null&&is_int($projection['tokens']??null))$availableTokens-=(int)$projection['tokens'];
            }
        }

        $elapsed=max(0.000001,(float)($usage['period']['elapsed_ratio']??0.0));
        $runRateCost=(int)round((int)$usage['known_cost_micros']/$elapsed);
        $runRateTokens=(int)round((int)$usage['cloud_tokens_charged']/$elapsed);
        $policySnapshots[]=[
            'policy'=>$policy,'usage'=>$usage,'state'=>$state,
            'projected_remaining_cost_micros'=>$projectedCost,
            'projected_remaining_tokens'=>$projectedTokens,
            'projected_unknown_cost_goals'=>$unknownCost,
            'projected_unknown_token_goals'=>$unknownTokens,
            'run_rate_period_end_cost_micros'=>$runRateCost,
            'run_rate_period_end_tokens'=>$runRateTokens,
            'remaining_cost_micros'=>$remainingCost,'remaining_tokens'=>$remainingTokens,
            'admitted_goal_ids'=>$admitted,'held_goal_ids'=>$held,'override_goal_ids'=>$overrideGoalIds,
        ];
    }

    foreach($items as &$item){
        if(!is_array($item))continue;$goalId=(int)($item['goal_id']??0);
        $holds=(array)($heldByGoal[$goalId]??[]);
        $item['budget_hard_hold']=!empty($holds);
        $item['budget_hold_policy_ids']=array_values(array_map(static fn(array $x): int=>(int)$x[0],$holds));
        $item['budget_hold_reasons']=array_values(array_map(static fn(array $x): string=>(string)$x[1],$holds));
        $item['budget_override_policy_ids']=array_values(array_map('intval',(array)($overridesByGoal[$goalId]??[])));
        $pressure=max(0.0,(float)($softPressureByGoal[$goalId]??0.0));
        $commitment=(float)($item['commitment_protection_score']??0.0)>=0.65;
        $item['budget_planning_adjustment']=$commitment?0.0:round(max(-0.10,min(0.0,-0.08*min(1.25,$pressure))),4);
        $item['budget_requires_user']=!empty($holds);
        $item['budget_commitment_conflict']=!empty($holds)&&$commitment;
        if(!empty($holds))$item['requires_user']=true;
    }
    unset($item);

    $heldGoalIds=array_values(array_unique(array_map('intval',array_keys($heldByGoal))));
    $conflictGoals=array_values(array_filter($items,static fn(array $x): bool=>!empty($x['budget_commitment_conflict'])));
    $hardPolicies=array_values(array_filter($policySnapshots,static fn(array $x): bool=>(string)($x['policy']['enforcement_mode']??'soft')==='hard'));
    $watchPolicies=array_values(array_filter($policySnapshots,static fn(array $x): bool=>in_array((string)($x['state']['state']??'healthy'),['watch','at_risk','exhausted','uncertain'],true)));
    usort($policySnapshots,static function(array $a,array $b): int {
        $rank=['exhausted'=>0,'uncertain'=>1,'at_risk'=>2,'watch'=>3,'healthy'=>4];
        $x=($rank[$a['state']['state']??'healthy']??9)<=>($rank[$b['state']['state']??'healthy']??9);
        if($x!==0)return $x;
        return ((int)$a['policy']['id'])<=>((int)$b['policy']['id']);
    });

    return [
        'items'=>$items,
        'budget_governance'=>[
            'build'=>VP3_COGNITIVE_BUDGET_GOVERNANCE_V2560,
            'contract'=>VP3_COGNITIVE_BUDGET_CONTRACT_V2560,
            'configured'=>true,'focus'=>$policySnapshots[0]??null,
            'policies'=>$policySnapshots,'held_goals'=>$heldGoalIds,
            'counts'=>[
                'policies'=>count($policySnapshots),'hard_policies'=>count($hardPolicies),
                'attention_policies'=>count($watchPolicies),'held_goals'=>count($heldGoalIds),
                'commitment_conflicts'=>count($conflictGoals),
                'active_overrides'=>count(vp3_cognitive_budget_active_overrides_v2560($pdo,$user,$now)),
            ],
            'authority'=>[
                'budget_configuration'=>'cognitive_budget_policies_v2560',
                'budget_decision_audit'=>'cognitive_budget_decisions_v2560',
                'usage_cost'=>'ai_execution_ledger_v032',
                'token_balance_and_enforcement'=>'subscription_quota_and_ai_gateway',
                'economics'=>'cognitive_economics_v2550',
                'commitments'=>'cognitive_commitment_protection_v2540',
                'portfolio_admission'=>'cognitive_portfolio_v2480',
                'claims_leases_execution_receipts'=>'agent_job_engine_v1900',
                'billing_authority'=>false,'token_mutation_authority'=>false,
                'executor_mutation_authority'=>false,'deadline_mutation_authority'=>false,
            ],
            'projection_only'=>false,
        ],
    ];
}

function vp3_cognitive_budget_goals_for_run_v2560(PDO $pdo,int $uid,int $runId): array
{
    if($uid<1||$runId<1||!table_exists('agent_goal_objectives')||!table_exists('agent_goals'))return [];
    try{
        $stmt=$pdo->prepare("SELECT DISTINCT g.id FROM agent_goal_objectives x
          INNER JOIN agent_goals g ON g.id=x.goal_id AND g.owner_user_id=x.owner_user_id
          WHERE x.owner_user_id=? AND x.objective_run_id=? AND g.execution_mode='autonomous'
          ORDER BY g.priority DESC,g.id");
        $stmt->execute([$uid,$runId]);
        return array_values(array_unique(array_filter(array_map(
            'intval',array_column($stmt->fetchAll(PDO::FETCH_ASSOC)?:[],'id')
        ),static fn(int $id): bool=>$id>0)));
    }catch(Throwable $e){return [];}
}

/**
 * Phase 19 candidate guard: membership is only narrowed for canonical
 * autonomous goal runs under an explicit active hard policy. It never creates
 * candidates, changes run status or claims a lease.
 */
function vp3_cognitive_budget_filter_claim_candidates_v2560(
    PDO $pdo,array $user,string $executor,array $rows,int $now=0
): array {
    if($executor!=='cloud'||!$rows||!vp3_cognitive_budget_schema_ready_v2560($pdo))return $rows;
    $uid=(int)($user['id']??0);if($uid<1)return $rows;
    $now=$now>0?$now:time();

    // Never interrupt an already-started multi-step workflow between actions.
    // The budget boundary governs new autonomous Cloud admission only.
    $approved=[];$passthrough=[];
    foreach($rows as $row){
        if((string)($row['status']??'approved')==='executing')$passthrough[]=$row;
        else $approved[]=$row;
    }
    if(!$approved)return $rows;

    // Use the same canonical portfolio projection that calculated hard holds.
    // This preserves account/goal/Agent/project scope behavior and projected
    // next-work checks rather than reducing Phase 19 to actual-spend-only logic.
    try{$portfolio=vp3_cognitive_portfolio_snapshot_v2480($pdo,$user);}
    catch(Throwable $e){return $rows;}

    $holdPoliciesByGoal=[];
    foreach((array)($portfolio['items']??[]) as $item){
        if(!is_array($item)||empty($item['budget_hard_hold']))continue;
        $goalId=(int)($item['goal_id']??0);if($goalId<1)continue;
        $ids=array_values(array_unique(array_filter(
            array_map('intval',(array)($item['budget_hold_policy_ids']??[])),
            static fn(int $id): bool=>$id>0
        )));
        if($ids)$holdPoliciesByGoal[$goalId]=$ids;
    }
    if(!$holdPoliciesByGoal)return $rows;

    $policyMap=[];
    foreach(vp3_cognitive_budget_policy_rows_v2560($pdo,$user,true) as $policy){
        $policyMap[(int)$policy['id']]=$policy;
    }

    $admitted=[];
    foreach($approved as $row){
        $runId=(int)($row['id']??0);if($runId<1)continue;
        $goalIds=vp3_cognitive_budget_goals_for_run_v2560($pdo,$uid,$runId);
        if(!$goalIds){$admitted[]=$row;continue;}

        $blocked=false;
        foreach($goalIds as $goalId){
            $holds=(array)($holdPoliciesByGoal[$goalId]??[]);
            foreach($holds as $policyId){
                $policy=$policyMap[(int)$policyId]??null;
                if(!$policy)continue;
                $scopeSubject=(string)$policy['scope_kind'].':'.(string)$policy['scope_key'];
                $override=vp3_cognitive_budget_active_override_v2560($pdo,$uid,(int)$policyId,'run',(string)$runId,$now)
                    ?:vp3_cognitive_budget_active_override_v2560($pdo,$uid,(int)$policyId,'goal',(string)$goalId,$now)
                    ?:vp3_cognitive_budget_active_override_v2560($pdo,$uid,(int)$policyId,'scope',$scopeSubject,$now);
                if(!$override){$blocked=true;break 2;}
            }
        }
        if(!$blocked)$admitted[]=$row;
    }

    // Existing executing runs keep their relative precedence; approved work is
    // narrowed only by explicit hard budget holds/overrides.
    return array_merge($passthrough,$admitted);
}

function vp3_cognitive_budget_snapshot_v2560(PDO $pdo,array $user): array
{
    $empty=[
        'build'=>VP3_COGNITIVE_BUDGET_GOVERNANCE_V2560,'contract'=>VP3_COGNITIVE_BUDGET_CONTRACT_V2560,
        'configured'=>false,'focus'=>null,'policies'=>[],'held_goals'=>[],'counts'=>[],
        'projection_only'=>false,
    ];
    if(!vp3_cognitive_budget_schema_ready_v2560($pdo))return $empty;
    try{$portfolio=vp3_cognitive_portfolio_snapshot_v2480($pdo,$user);}catch(Throwable $e){return $empty;}
    return is_array($portfolio['budget_governance']??null)?$portfolio['budget_governance']:$empty;
}

function vp3_cognitive_budget_context_item_v2560(PDO $pdo,array $user,string $namespace): ?array
{
    if($namespace!=='system')return null;
    $snapshot=vp3_cognitive_budget_snapshot_v2560($pdo,$user);
    if(empty($snapshot['configured']))return null;
    $json=json_encode([
        'focus'=>$snapshot['focus']??null,'counts'=>$snapshot['counts']??[],
        'held_goals'=>$snapshot['held_goals']??[],
        'policies'=>array_slice((array)($snapshot['policies']??[]),0,6),
    ],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE);
    if(!is_string($json))return null;
    return vp3_cognitive_context_item_v2420(
        'budget_governance','cognitive-budget-governance:v2560',
        'Explicit AI budget guardrails and spend governance',$json,95.997,
        ['direct'=>true,'ephemeral_projection'=>true,'instruction_authority'=>false]
    );
}

function vp3_cognitive_budget_activity_projection_v2560(PDO $pdo,array $user,string $namespace): array
{
    if($namespace!=='system')return [
        'build'=>VP3_COGNITIVE_BUDGET_GOVERNANCE_V2560,'configured'=>false,
        'focus'=>null,'policies'=>[],'held_goals'=>[],'counts'=>[],'projection_only'=>false,
    ];
    $snapshot=vp3_cognitive_budget_snapshot_v2560($pdo,$user);
    return [
        'build'=>VP3_COGNITIVE_BUDGET_GOVERNANCE_V2560,
        'configured'=>!empty($snapshot['configured']),'focus'=>$snapshot['focus']??null,
        'policies'=>array_slice((array)($snapshot['policies']??[]),0,8),
        'held_goals'=>$snapshot['held_goals']??[],'counts'=>$snapshot['counts']??[],
        'manage_url'=>url('/budget-governance.php'),'projection_only'=>false,
    ];
}
