<?php
declare(strict_types=1);

/**
 * VP3 Browser Companion v22.00 — Browser Agent Runtime.
 *
 * The v21.90 delegation envelope remains the authority boundary. This layer
 * adds reusable runtime sessions, skill contracts, task-scoped observations,
 * recovery, bounded replanning, tab references and a durable event timeline.
 * It never stores passive browser history or raw page content.
 */
const VP3_BROWSER_AGENT_RUNTIME_V2200='browser-agent-runtime-v2200-20260920';
const VP3_BROWSER_RUNTIME_MAX_REPLANS_V2200=3;
const VP3_BROWSER_RUNTIME_EVENT_LIMIT_V2200=80;
const VP3_BROWSER_RUNTIME_OBSERVATION_LIMIT_V2200=20;
const VP3_BROWSER_RUNTIME_TAB_LIMIT_V2200=24;

require_once __DIR__.'/browser-delegation-v2190.php';
require_once __DIR__.'/extension-notifications-v2140.php';

function vp3_browser_runtime_schema_ready_v2200(?PDO $pdo=null): bool
{
    $pdo??=db();
    if(!$pdo)return false;
    foreach([
        'browser_agent_runtime_sessions_v2200',
        'browser_agent_runtime_events_v2200',
        'browser_agent_runtime_observations_v2200',
        'browser_agent_runtime_tabs_v2200',
    ] as $table)if(!table_exists($table))return false;
    return vp3_browser_delegation_schema_ready_v2190($pdo);
}

function vp3_browser_runtime_ensure_schema_v2200(?PDO $pdo=null): void
{
    $pdo??=db();
    if(!$pdo)throw new RuntimeException('Database connection is unavailable.');
    vp3_browser_delegation_ensure_schema_v2190($pdo);
    vp3_extension_notifications_ensure_schema_v2140($pdo);

    $pdo->exec("CREATE TABLE IF NOT EXISTS browser_agent_runtime_sessions_v2200 (
      id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      public_id CHAR(36) NOT NULL,
      owner_user_id INT UNSIGNED NOT NULL,
      agent_namespace VARCHAR(80) NOT NULL DEFAULT 'system',
      delegation_id BIGINT UNSIGNED NOT NULL,
      workflow_run_id BIGINT UNSIGNED NOT NULL,
      status VARCHAR(32) NOT NULL DEFAULT 'ready',
      current_action_id BIGINT UNSIGNED NULL,
      current_skill_key VARCHAR(80) NOT NULL DEFAULT '',
      plan_revision INT UNSIGNED NOT NULL DEFAULT 1,
      replan_count TINYINT UNSIGNED NOT NULL DEFAULT 0,
      max_replans TINYINT UNSIGNED NOT NULL DEFAULT 3,
      observation_count INT UNSIGNED NOT NULL DEFAULT 0,
      last_observation_fingerprint CHAR(64) NOT NULL DEFAULT '',
      last_verified_at DATETIME NULL,
      recovery_code VARCHAR(80) NOT NULL DEFAULT '',
      expires_at DATETIME NOT NULL,
      started_at DATETIME NULL,
      paused_at DATETIME NULL,
      completed_at DATETIME NULL,
      cancelled_at DATETIME NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      UNIQUE KEY uq_browser_runtime_public_v2200 (public_id),
      UNIQUE KEY uq_browser_runtime_delegation_v2200 (delegation_id),
      INDEX idx_browser_runtime_owner_v2200 (owner_user_id,agent_namespace,status,updated_at),
      INDEX idx_browser_runtime_workflow_v2200 (owner_user_id,workflow_run_id),
      CONSTRAINT fk_browser_runtime_owner_v2200 FOREIGN KEY (owner_user_id) REFERENCES users(id) ON DELETE CASCADE,
      CONSTRAINT fk_browser_runtime_delegation_v2200 FOREIGN KEY (delegation_id) REFERENCES browser_delegations_v2190(id) ON DELETE CASCADE,
      CONSTRAINT fk_browser_runtime_workflow_v2200 FOREIGN KEY (workflow_run_id) REFERENCES agent_workflow_runs(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS browser_agent_runtime_events_v2200 (
      id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      runtime_session_id BIGINT UNSIGNED NOT NULL,
      owner_user_id INT UNSIGNED NOT NULL,
      event_type VARCHAR(60) NOT NULL,
      skill_key VARCHAR(80) NOT NULL DEFAULT '',
      action_id BIGINT UNSIGNED NULL,
      summary VARCHAR(500) NOT NULL DEFAULT '',
      result_code VARCHAR(80) NOT NULL DEFAULT '',
      metadata_json TEXT NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      INDEX idx_browser_runtime_event_session_v2200 (runtime_session_id,id),
      INDEX idx_browser_runtime_event_owner_v2200 (owner_user_id,created_at,id),
      CONSTRAINT fk_browser_runtime_event_session_v2200 FOREIGN KEY (runtime_session_id) REFERENCES browser_agent_runtime_sessions_v2200(id) ON DELETE CASCADE,
      CONSTRAINT fk_browser_runtime_event_owner_v2200 FOREIGN KEY (owner_user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS browser_agent_runtime_observations_v2200 (
      id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      runtime_session_id BIGINT UNSIGNED NOT NULL,
      owner_user_id INT UNSIGNED NOT NULL,
      observation_type VARCHAR(40) NOT NULL DEFAULT 'vp3_state',
      target_type VARCHAR(40) NOT NULL DEFAULT '',
      target_id VARCHAR(190) NOT NULL DEFAULT '',
      state_json TEXT NULL,
      fingerprint CHAR(64) NOT NULL,
      expires_at DATETIME NOT NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      INDEX idx_browser_runtime_observation_session_v2200 (runtime_session_id,id),
      INDEX idx_browser_runtime_observation_expiry_v2200 (expires_at),
      CONSTRAINT fk_browser_runtime_observation_session_v2200 FOREIGN KEY (runtime_session_id) REFERENCES browser_agent_runtime_sessions_v2200(id) ON DELETE CASCADE,
      CONSTRAINT fk_browser_runtime_observation_owner_v2200 FOREIGN KEY (owner_user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS browser_agent_runtime_tabs_v2200 (
      id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      runtime_session_id BIGINT UNSIGNED NOT NULL,
      owner_user_id INT UNSIGNED NOT NULL,
      client_tab_key CHAR(64) NOT NULL,
      role VARCHAR(32) NOT NULL DEFAULT 'runtime',
      target_type VARCHAR(40) NOT NULL DEFAULT '',
      target_id VARCHAR(190) NOT NULL DEFAULT '',
      status VARCHAR(24) NOT NULL DEFAULT 'open',
      opened_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      last_seen_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      closed_at DATETIME NULL,
      UNIQUE KEY uq_browser_runtime_tab_v2200 (runtime_session_id,client_tab_key),
      INDEX idx_browser_runtime_tab_owner_v2200 (owner_user_id,status,last_seen_at),
      CONSTRAINT fk_browser_runtime_tab_session_v2200 FOREIGN KEY (runtime_session_id) REFERENCES browser_agent_runtime_sessions_v2200(id) ON DELETE CASCADE,
      CONSTRAINT fk_browser_runtime_tab_owner_v2200 FOREIGN KEY (owner_user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

function vp3_browser_runtime_skills_v2200(): array
{
    return [
        'inspect_source'=>[
            'label'=>'Inspect authorized VP3 Source','capability'=>'agent.message','risk_level'=>'low',
            'mode'=>'observe','verification_mode'=>'server_reference','requires_checkpoint'=>false,
            'target_types'=>['browser_source'],
        ],
        'follow_source'=>[
            'label'=>'Follow VP3 Source','capability'=>'team.chat.read','risk_level'=>'low',
            'mode'=>'server_mutation','verification_mode'=>'server_state','requires_checkpoint'=>false,
            'target_types'=>['browser_source'],
        ],
        'unfollow_source'=>[
            'label'=>'Unfollow VP3 Source','capability'=>'team.chat.read','risk_level'=>'low',
            'mode'=>'server_mutation','verification_mode'=>'server_state','requires_checkpoint'=>false,
            'target_types'=>['browser_source'],
        ],
        'open_research'=>[
            'label'=>'Open related Research','capability'=>'agent.message','risk_level'=>'low',
            'mode'=>'navigate','verification_mode'=>'navigation','requires_checkpoint'=>false,
            'target_types'=>['research_project'],
        ],
        'open_profile'=>[
            'label'=>'Open related profile','capability'=>'agent.message','risk_level'=>'low',
            'mode'=>'navigate','verification_mode'=>'navigation','requires_checkpoint'=>false,
            'target_types'=>['public_profile'],
        ],
        'open_contact'=>[
            'label'=>'Open related CRM contact','capability'=>'agent.message','risk_level'=>'low',
            'mode'=>'navigate','verification_mode'=>'navigation','requires_checkpoint'=>false,
            'target_types'=>['crm_contact'],
        ],
        'draft_task'=>[
            'label'=>'Prepare Task checkpoint','capability'=>'task.propose','risk_level'=>'medium',
            'mode'=>'checkpoint','verification_mode'=>'user_confirmation','requires_checkpoint'=>true,
            'target_types'=>['browser_source'],
        ],
        'draft_knowledge'=>[
            'label'=>'Prepare Knowledge checkpoint','capability'=>'knowledge.write','risk_level'=>'medium',
            'mode'=>'checkpoint','verification_mode'=>'user_confirmation','requires_checkpoint'=>true,
            'target_types'=>['browser_source'],
        ],
        'share_team'=>[
            'label'=>'Prepare Team-share checkpoint','capability'=>'team.share.create','risk_level'=>'medium',
            'mode'=>'checkpoint','verification_mode'=>'user_confirmation','requires_checkpoint'=>true,
            'target_types'=>['browser_source'],
        ],
    ];
}

function vp3_browser_runtime_public_skills_v2200(): array
{
    $out=[];
    foreach(vp3_browser_runtime_skills_v2200() as $key=>$skill)$out[]=[
        'key'=>$key,'label'=>(string)$skill['label'],'capability'=>(string)$skill['capability'],
        'risk_level'=>(string)$skill['risk_level'],'mode'=>(string)$skill['mode'],
        'verification_mode'=>(string)$skill['verification_mode'],
        'requires_checkpoint'=>(bool)$skill['requires_checkpoint'],
        'target_types'=>(array)$skill['target_types'],
    ];
    return $out;
}

function vp3_browser_runtime_skill_v2200(string $key): ?array
{
    $skills=vp3_browser_runtime_skills_v2200();
    return $skills[$key]??null;
}

function vp3_browser_runtime_risk_rank_v2200(string $risk): int
{
    return match($risk){'low'=>1,'medium'=>2,'high'=>3,default=>99};
}

function vp3_browser_runtime_row_v2200(PDO $pdo,int $uid,string $namespace,string $publicId,bool $lock=false): ?array
{
    if(!preg_match('/^[a-f0-9-]{36}$/i',$publicId))return null;
    $sql="SELECT r.*,d.public_id delegation_public_id,d.status delegation_status,d.source_type,d.source_id,
        d.allowed_actions_json,d.allowed_domains_json,d.max_steps,d.risk_budget,d.expires_at delegation_expires_at,
        w.title,w.goal,w.status workflow_status,w.progress_message workflow_progress
      FROM browser_agent_runtime_sessions_v2200 r
      INNER JOIN browser_delegations_v2190 d ON d.id=r.delegation_id
      INNER JOIN agent_workflow_runs w ON w.id=r.workflow_run_id
      WHERE r.public_id=? AND r.owner_user_id=? AND r.agent_namespace=? LIMIT 1".($lock?' FOR UPDATE':'');
    $stmt=$pdo->prepare($sql);$stmt->execute([$publicId,$uid,$namespace]);$row=$stmt->fetch(PDO::FETCH_ASSOC);
    return is_array($row)?$row:null;
}

function vp3_browser_runtime_by_delegation_v2200(PDO $pdo,int $uid,string $namespace,string $delegationPublicId): ?array
{
    if(!preg_match('/^[a-f0-9-]{36}$/i',$delegationPublicId))return null;
    $stmt=$pdo->prepare("SELECT r.public_id FROM browser_agent_runtime_sessions_v2200 r
      INNER JOIN browser_delegations_v2190 d ON d.id=r.delegation_id
      WHERE r.owner_user_id=? AND r.agent_namespace=? AND d.public_id=? LIMIT 1");
    $stmt->execute([$uid,$namespace,$delegationPublicId]);$id=(string)($stmt->fetchColumn()?:'');
    return $id!==''?vp3_browser_runtime_row_v2200($pdo,$uid,$namespace,$id):null;
}

function vp3_browser_runtime_for_workflow_v2200(PDO $pdo,int $uid,int $workflowRunId): ?array
{
    if($uid<1||$workflowRunId<1||!vp3_browser_runtime_schema_ready_v2200($pdo))return null;
    $stmt=$pdo->prepare("SELECT r.public_id,r.agent_namespace FROM browser_agent_runtime_sessions_v2200 r
      WHERE r.owner_user_id=? AND r.workflow_run_id=? LIMIT 1");
    $stmt->execute([$uid,$workflowRunId]);$row=$stmt->fetch(PDO::FETCH_ASSOC);
    if(!is_array($row))return null;
    return vp3_browser_runtime_public_v2200($pdo,['id'=>$uid],(string)$row['agent_namespace'],(string)$row['public_id'],true);
}

function vp3_browser_runtime_event_metadata_v2200(array $metadata): array
{
    $allowed=['target_type','target_id','sequence_no','plan_revision','from_status','to_status','reason','verification_mode','tab_role','step_count','capability'];
    $out=[];
    foreach($allowed as $key){
        if(!array_key_exists($key,$metadata))continue;
        $value=$metadata[$key];
        if(is_bool($value)||is_int($value)||is_float($value))$out[$key]=$value;
        elseif(is_scalar($value))$out[$key]=mb_strimwidth(trim((string)$value),0,190,'');
    }
    return $out;
}

function vp3_browser_runtime_event_v2200(PDO $pdo,array $runtime,string $type,string $summary,string $skillKey='',?int $actionId=null,string $code='',array $metadata=[]): void
{
    $safe=vp3_browser_runtime_event_metadata_v2200($metadata);
    $pdo->prepare("INSERT INTO browser_agent_runtime_events_v2200
      (runtime_session_id,owner_user_id,event_type,skill_key,action_id,summary,result_code,metadata_json)
      VALUES (?,?,?,?,?,?,?,?)")->execute([
        (int)$runtime['id'],(int)$runtime['owner_user_id'],mb_strimwidth($type,0,60,''),
        mb_strimwidth($skillKey,0,80,''),$actionId&&$actionId>0?$actionId:null,
        agent_workflow_text_v1400($summary,500),mb_strimwidth($code,0,80,''),
        $safe?json_encode($safe,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE):null
    ]);
}

function vp3_browser_runtime_events_v2200(PDO $pdo,int $uid,int $runtimeId,int $limit=VP3_BROWSER_RUNTIME_EVENT_LIMIT_V2200): array
{
    $limit=max(1,min(VP3_BROWSER_RUNTIME_EVENT_LIMIT_V2200,$limit));
    $stmt=$pdo->prepare("SELECT id,event_type,skill_key,action_id,summary,result_code,metadata_json,created_at
      FROM browser_agent_runtime_events_v2200 WHERE runtime_session_id=? AND owner_user_id=? ORDER BY id DESC LIMIT ".$limit);
    $stmt->execute([$runtimeId,$uid]);$out=[];
    foreach($stmt->fetchAll(PDO::FETCH_ASSOC)?:[] as $row)$out[]=[
        'id'=>(int)$row['id'],'event_type'=>(string)$row['event_type'],'skill_key'=>(string)$row['skill_key'],
        'action_id'=>(int)($row['action_id']??0),'summary'=>(string)$row['summary'],'result_code'=>(string)$row['result_code'],
        'metadata'=>agent_workflow_public_json_v1400((string)($row['metadata_json']??'')),'created_at'=>(string)$row['created_at'],
    ];
    return $out;
}

function vp3_browser_runtime_observations_v2200(PDO $pdo,int $uid,int $runtimeId,int $limit=VP3_BROWSER_RUNTIME_OBSERVATION_LIMIT_V2200): array
{
    $limit=max(1,min(VP3_BROWSER_RUNTIME_OBSERVATION_LIMIT_V2200,$limit));
    $pdo->prepare("DELETE FROM browser_agent_runtime_observations_v2200 WHERE runtime_session_id=? AND owner_user_id=? AND expires_at<UTC_TIMESTAMP()")->execute([$runtimeId,$uid]);
    $stmt=$pdo->prepare("SELECT id,observation_type,target_type,target_id,state_json,fingerprint,expires_at,created_at
      FROM browser_agent_runtime_observations_v2200 WHERE runtime_session_id=? AND owner_user_id=? AND expires_at>=UTC_TIMESTAMP()
      ORDER BY id DESC LIMIT ".$limit);
    $stmt->execute([$runtimeId,$uid]);$out=[];
    foreach($stmt->fetchAll(PDO::FETCH_ASSOC)?:[] as $row)$out[]=[
        'id'=>(int)$row['id'],'observation_type'=>(string)$row['observation_type'],
        'target_type'=>(string)$row['target_type'],'target_id'=>(string)$row['target_id'],
        'state'=>agent_workflow_public_json_v1400((string)($row['state_json']??'')),
        'fingerprint'=>(string)$row['fingerprint'],'expires_at'=>(string)$row['expires_at'],'created_at'=>(string)$row['created_at'],
    ];
    return $out;
}

function vp3_browser_runtime_tabs_v2200(PDO $pdo,int $uid,int $runtimeId,int $limit=VP3_BROWSER_RUNTIME_TAB_LIMIT_V2200): array
{
    $limit=max(1,min(VP3_BROWSER_RUNTIME_TAB_LIMIT_V2200,$limit));
    $stmt=$pdo->prepare("SELECT id,client_tab_key,role,target_type,target_id,status,opened_at,last_seen_at,closed_at
      FROM browser_agent_runtime_tabs_v2200 WHERE runtime_session_id=? AND owner_user_id=? ORDER BY last_seen_at DESC,id DESC LIMIT ".$limit);
    $stmt->execute([$runtimeId,$uid]);$out=[];
    foreach($stmt->fetchAll(PDO::FETCH_ASSOC)?:[] as $row)$out[]=[
        'id'=>(int)$row['id'],'tab_ref'=>substr((string)$row['client_tab_key'],0,12),
        'role'=>(string)$row['role'],'target_type'=>(string)$row['target_type'],'target_id'=>(string)$row['target_id'],
        'status'=>(string)$row['status'],'opened_at'=>(string)$row['opened_at'],
        'last_seen_at'=>(string)$row['last_seen_at'],'closed_at'=>(string)($row['closed_at']??''),
    ];
    return $out;
}

function vp3_browser_runtime_current_step_v2200(PDO $pdo,int $uid,int $runId): ?array
{
    $stmt=$pdo->prepare("SELECT * FROM agent_workflow_actions
      WHERE run_id=? AND owner_user_id=? AND status IN ('queued','approval_pending','executing','failed')
      ORDER BY sequence_no,id LIMIT 1");
    $stmt->execute([$runId,$uid]);$row=$stmt->fetch(PDO::FETCH_ASSOC);
    return is_array($row)?$row:null;
}

function vp3_browser_runtime_public_v2200(PDO $pdo,array $user,string $namespace,string $publicId,bool $history=false): ?array
{
    $uid=(int)($user['id']??0);$row=vp3_browser_runtime_row_v2200($pdo,$uid,$namespace,$publicId);
    if(!$row)return null;
    $delegation=vp3_browser_delegation_public_v2190($pdo,$user,$namespace,(string)$row['delegation_public_id'],$history);
    $step=vp3_browser_runtime_current_step_v2200($pdo,$uid,(int)$row['workflow_run_id']);
    $stepPublic=$step?vp3_browser_delegation_public_step_v2190($step):null;
    $out=[
        'runtime_id'=>(string)$row['public_id'],'build'=>VP3_BROWSER_AGENT_RUNTIME_V2200,
        'delegation_id'=>(string)$row['delegation_public_id'],'workflow_run_id'=>(int)$row['workflow_run_id'],
        'title'=>(string)$row['title'],'instruction'=>(string)$row['goal'],'status'=>(string)$row['status'],
        'delegation_status'=>(string)$row['delegation_status'],'workflow_status'=>(string)$row['workflow_status'],
        'current_action_id'=>(int)($row['current_action_id']??0),'current_skill_key'=>(string)$row['current_skill_key'],
        'current_step'=>$stepPublic,'plan_revision'=>(int)$row['plan_revision'],'replan_count'=>(int)$row['replan_count'],
        'max_replans'=>(int)$row['max_replans'],'observation_count'=>(int)$row['observation_count'],
        'last_observation_fingerprint'=>(string)$row['last_observation_fingerprint'],
        'last_verified_at'=>(string)($row['last_verified_at']??''),'recovery_code'=>(string)$row['recovery_code'],
        'expires_at'=>(string)$row['expires_at'],'started_at'=>(string)($row['started_at']??''),
        'paused_at'=>(string)($row['paused_at']??''),'completed_at'=>(string)($row['completed_at']??''),
        'cancelled_at'=>(string)($row['cancelled_at']??''),'created_at'=>(string)$row['created_at'],'updated_at'=>(string)$row['updated_at'],
        'delegation'=>$delegation,
        'agent_workflow_url'=>'/agent-workflows.php?id='.(int)$row['workflow_run_id'],
        'authority'=>[
            'source'=>'v21.90_delegation','can_expand'=>false,'raw_page_content_persisted'=>false,
            'task_scoped_observations'=>true,'skill_permissions_rechecked'=>true
        ],
    ];
    if($history){
        $out['timeline']=vp3_browser_runtime_events_v2200($pdo,$uid,(int)$row['id']);
        $out['observations']=vp3_browser_runtime_observations_v2200($pdo,$uid,(int)$row['id']);
        $out['tabs']=vp3_browser_runtime_tabs_v2200($pdo,$uid,(int)$row['id']);
    }
    return $out;
}

function vp3_browser_runtime_list_v2200(PDO $pdo,array $user,string $namespace,int $limit=20): array
{
    $uid=(int)($user['id']??0);$limit=max(1,min(30,$limit));
    $stmt=$pdo->prepare("SELECT public_id FROM browser_agent_runtime_sessions_v2200
      WHERE owner_user_id=? AND agent_namespace=? ORDER BY id DESC LIMIT ".$limit);
    $stmt->execute([$uid,$namespace]);$out=[];
    foreach($stmt->fetchAll(PDO::FETCH_ASSOC)?:[] as $row){
        $item=vp3_browser_runtime_public_v2200($pdo,$user,$namespace,(string)$row['public_id'],false);
        if($item)$out[]=$item;
    }
    return $out;
}

function vp3_browser_runtime_status_from_delegation_v2200(string $status): string
{
    return match($status){
        'active'=>'ready','checkpoint'=>'checkpoint','paused'=>'paused',
        'completed'=>'completed','cancelled'=>'cancelled','expired'=>'expired',
        default=>'ready'
    };
}

function vp3_browser_runtime_attach_v2200(PDO $pdo,array $user,string $namespace,string $delegationPublicId): array
{
    $uid=(int)($user['id']??0);
    $delegation=vp3_browser_delegation_row_v2190($pdo,$uid,$namespace,$delegationPublicId);
    if(!$delegation)throw new RuntimeException('Delegated Browser job was not found.');
    $existing=vp3_browser_runtime_by_delegation_v2200($pdo,$uid,$namespace,$delegationPublicId);
    if($existing){
        return vp3_browser_runtime_public_v2200($pdo,$user,$namespace,(string)$existing['public_id'],true)
            ??throw new RuntimeException('Browser Agent Runtime could not be loaded.');
    }
    $publicId=vp3_extension_uuid_v2000();
    $status=vp3_browser_runtime_status_from_delegation_v2200((string)$delegation['status']);
    $stmt=$pdo->prepare("INSERT INTO browser_agent_runtime_sessions_v2200
      (public_id,owner_user_id,agent_namespace,delegation_id,workflow_run_id,status,max_replans,expires_at,started_at,paused_at,completed_at,cancelled_at)
      VALUES (?,?,?,?,?,?,?,?,?,?,?,?)");
    $stmt->execute([
        $publicId,$uid,$namespace,(int)$delegation['id'],(int)$delegation['workflow_run_id'],$status,
        VP3_BROWSER_RUNTIME_MAX_REPLANS_V2200,(string)$delegation['expires_at'],
        in_array($status,['ready','running','checkpoint','paused'],true)?gmdate('Y-m-d H:i:s'):null,
        $status==='paused'?gmdate('Y-m-d H:i:s'):null,
        $status==='completed'?gmdate('Y-m-d H:i:s'):null,
        $status==='cancelled'?gmdate('Y-m-d H:i:s'):null,
    ]);
    $runtime=vp3_browser_runtime_row_v2200($pdo,$uid,$namespace,$publicId);
    if(!$runtime)throw new RuntimeException('Browser Agent Runtime could not be created.');
    vp3_browser_runtime_event_v2200($pdo,$runtime,'runtime_attached','Browser Agent Runtime attached to the approved delegation.','','','attached',[
        'plan_revision'=>1,'step_count'=>(int)$delegation['max_steps']
    ]);
    agent_workflow_event_v1400($pdo,$uid,(int)$delegation['workflow_run_id'],'browser_runtime_attached',(string)$delegation['workflow_status'],(string)$delegation['workflow_status'],'browser','Browser Agent Runtime v22.00 attached to this delegated workflow.',['runtime_id'=>$publicId]);
    return vp3_browser_runtime_public_v2200($pdo,$user,$namespace,$publicId,true)
        ??throw new RuntimeException('Browser Agent Runtime could not be loaded.');
}

function vp3_browser_runtime_observe_v2200(PDO $pdo,array $user,string $namespace,array $runtime,array $context,array $relations): array
{
    $uid=(int)($user['id']??0);
    $source=is_array($relations['source']??null)?$relations['source']:null;
    $step=vp3_browser_runtime_current_step_v2200($pdo,$uid,(int)$runtime['workflow_run_id']);
    $targetType='';$targetId='';$state=[
        'research_count'=>count((array)($relations['research']??[])),
        'profile_count'=>count((array)($relations['profiles']??[])),
        'contact_count'=>count((array)($relations['contacts']??[])),
    ];
    if($source&&trim((string)($source['id']??''))!==''){
        $targetType='browser_source';$targetId=(string)$source['id'];
        $state['authorized_source']=true;$state['following']=!empty($source['following']);
    }elseif($step){
        $target=vp3_browser_delegation_step_target_v2190($step);
        $targetType=(string)$target['target_type'];$targetId=(string)$target['target_id'];
        $resolved=vp3_browser_delegation_target_v2190($pdo,$user,$step);
        $state['authorized_target']=$resolved!==null;
    }
    $state=array_filter($state,static fn($v): bool=>is_bool($v)||is_int($v));
    $sourceDigest=trim((string)($context['page_text_sha256']??''));
    $fingerprint=hash('sha256',implode('|',[
        (string)$runtime['public_id'],$targetType,$targetId,
        json_encode($state,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE),$sourceDigest
    ]));
    $expiresAt=(string)$runtime['expires_at'];
    $pdo->prepare("INSERT INTO browser_agent_runtime_observations_v2200
      (runtime_session_id,owner_user_id,observation_type,target_type,target_id,state_json,fingerprint,expires_at)
      VALUES (?,?,?,?,?,?,?,?)")->execute([
        (int)$runtime['id'],$uid,'vp3_state',$targetType,$targetId,
        json_encode($state,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE),$fingerprint,$expiresAt
    ]);
    $pdo->prepare("UPDATE browser_agent_runtime_sessions_v2200
      SET observation_count=observation_count+1,last_observation_fingerprint=?,updated_at=UTC_TIMESTAMP()
      WHERE id=? AND owner_user_id=?")->execute([$fingerprint,(int)$runtime['id'],$uid]);
    vp3_browser_runtime_event_v2200($pdo,$runtime,'observed','Task-scoped VP3 state observed for the current runtime step.',(string)($runtime['current_skill_key']??''),null,'observed',[
        'target_type'=>$targetType,'target_id'=>$targetId
    ]);
    return ['fingerprint'=>$fingerprint,'target_type'=>$targetType,'target_id'=>$targetId,'state'=>$state,'expires_at'=>$expiresAt];
}

function vp3_browser_runtime_skill_check_v2200(array $runtime,array $session,array $step): array
{
    $target=vp3_browser_delegation_step_target_v2190($step);
    $action=(string)$target['action_key'];$skill=vp3_browser_runtime_skill_v2200($action);
    if(!$skill)return ['ok'=>false,'code'=>'skill_unavailable','message'=>'That workflow action is not registered as a Browser Runtime skill.'];
    $allowed=vp3_browser_delegation_json_array_v2190($runtime['allowed_actions_json']??'');
    if($action!=='inspect_source'&&!in_array($action,$allowed,true))return ['ok'=>false,'code'=>'authority_scope','message'=>'That skill is outside the approved delegation action scope.'];
    if(!in_array((string)$target['target_type'],(array)$skill['target_types'],true))return ['ok'=>false,'code'=>'target_scope','message'=>'That skill is not registered for this VP3 target type.'];
    if(vp3_browser_runtime_risk_rank_v2200((string)$skill['risk_level'])>vp3_browser_runtime_risk_rank_v2200((string)$runtime['risk_budget']))return ['ok'=>false,'code'=>'risk_budget','message'=>'That skill exceeds the approved delegation risk budget.'];
    $caps=array_fill_keys((array)($session['capabilities']??[]),true);
    $cap=(string)$skill['capability'];
    if($cap!==''&&!isset($caps[$cap]))return ['ok'=>false,'code'=>'capability_required','message'=>'This Browser Runtime skill requires additional VP3 authority.','capability'=>$cap,'skill'=>$skill,'target'=>$target];
    return ['ok'=>true,'skill_key'=>$action,'skill'=>$skill,'target'=>$target];
}

function vp3_browser_runtime_notify_v2200(PDO $pdo,array $runtime,string $kind,array $step=[]): void
{
    if(!function_exists('create_notification'))return;
    $uid=(int)$runtime['owner_user_id'];$runId=(int)$runtime['workflow_run_id'];$runtimeId=(int)$runtime['id'];
    $actionId=(int)($step['id']??0);$label=agent_workflow_text_v1400($step['label']??'',140);
    if($kind==='checkpoint'){
        create_notification($uid,'browser_runtime_approval_required_'.$actionId,'Browser Agent needs your approval',
            $label!==''?$label:'A delegated Browser step needs your review.',
            '/agent-workflows.php?id='.$runId,'browser_runtime',$runtimeId);
    }elseif($kind==='failed'){
        create_notification($uid,'browser_runtime_failed_'.$actionId,'Browser Agent workflow needs attention',
            $label!==''?$label.' could not be verified.':'A Browser Runtime step could not be verified.',
            '/agent-workflows.php?id='.$runId,'browser_runtime',$runtimeId);
    }elseif($kind==='completed'){
        create_notification($uid,'browser_runtime_workflow_completed','Browser Agent workflow completed',
            'The delegated Browser job completed its verified runtime plan.',
            '/agent-workflows.php?id='.$runId,'browser_runtime',$runtimeId);
    }
}

function vp3_browser_runtime_set_state_v2200(PDO $pdo,array $runtime,string $status,string $skill='',?int $actionId=null,string $recovery=''): void
{
    $terminal=in_array($status,['completed','cancelled','expired'],true);
    $pdo->prepare("UPDATE browser_agent_runtime_sessions_v2200
      SET status=?,current_skill_key=?,current_action_id=?,recovery_code=?,
          paused_at=CASE WHEN ?='paused' THEN UTC_TIMESTAMP() WHEN ?<>'paused' THEN NULL ELSE paused_at END,
          completed_at=CASE WHEN ?='completed' THEN UTC_TIMESTAMP() ELSE completed_at END,
          cancelled_at=CASE WHEN ?='cancelled' THEN UTC_TIMESTAMP() ELSE cancelled_at END,
          updated_at=UTC_TIMESTAMP()
      WHERE id=? AND owner_user_id=?")->execute([
        $status,$skill,$terminal?null:$actionId,$recovery,$status,$status,$status,$status,
        (int)$runtime['id'],(int)$runtime['owner_user_id']
    ]);
    if($terminal){
        $pdo->prepare("DELETE FROM browser_agent_runtime_observations_v2200 WHERE runtime_session_id=? AND owner_user_id=?")
            ->execute([(int)$runtime['id'],(int)$runtime['owner_user_id']]);
        $pdo->prepare("UPDATE browser_agent_runtime_tabs_v2200 SET status='released',last_seen_at=UTC_TIMESTAMP()
          WHERE runtime_session_id=? AND owner_user_id=? AND status='open'")
            ->execute([(int)$runtime['id'],(int)$runtime['owner_user_id']]);
    }
}

function vp3_browser_runtime_tick_v2200(PDO $pdo,array $user,string $namespace,array $session,string $runtimePublicId,array $context,array $relations): array
{
    $uid=(int)($user['id']??0);$runtime=vp3_browser_runtime_row_v2200($pdo,$uid,$namespace,$runtimePublicId);
    if(!$runtime)throw new RuntimeException('Browser Agent Runtime session was not found.');
    if(strtotime((string)$runtime['expires_at'])<time()){
        $delegationRow=vp3_browser_delegation_row_v2190($pdo,$uid,$namespace,(string)$runtime['delegation_public_id']);
        if($delegationRow)vp3_browser_delegation_expire_v2190($pdo,$delegationRow);
        vp3_browser_runtime_set_state_v2200($pdo,$runtime,'expired');
        vp3_browser_runtime_event_v2200($pdo,$runtime,'expired','Browser Agent Runtime authority expired.','','','expired');
        return ['state'=>'expired','runtime'=>vp3_browser_runtime_public_v2200($pdo,$user,$namespace,$runtimePublicId,true)];
    }
    if((string)$runtime['status']==='paused')return ['state'=>'paused','runtime'=>vp3_browser_runtime_public_v2200($pdo,$user,$namespace,$runtimePublicId,true)];
    if(in_array((string)$runtime['status'],['completed','cancelled','expired'],true))return ['state'=>(string)$runtime['status'],'runtime'=>vp3_browser_runtime_public_v2200($pdo,$user,$namespace,$runtimePublicId,true)];

    vp3_browser_runtime_observe_v2200($pdo,$user,$namespace,$runtime,$context,$relations);
    $runtime=vp3_browser_runtime_row_v2200($pdo,$uid,$namespace,$runtimePublicId)?:$runtime;
    $step=vp3_browser_runtime_current_step_v2200($pdo,$uid,(int)$runtime['workflow_run_id']);
    if(!$step){
        $delegation=vp3_browser_delegation_public_v2190($pdo,$user,$namespace,(string)$runtime['delegation_public_id'],true);
        if($delegation&&(string)$delegation['status']==='completed'){
            vp3_browser_runtime_set_state_v2200($pdo,$runtime,'completed');
            vp3_browser_runtime_event_v2200($pdo,$runtime,'completed','Browser Agent Runtime completed the verified delegation.','','','completed');
            vp3_browser_runtime_notify_v2200($pdo,$runtime,'completed');
        }
        return ['state'=>'completed','runtime'=>vp3_browser_runtime_public_v2200($pdo,$user,$namespace,$runtimePublicId,true)];
    }

    if((string)$step['status']==='failed'){
        vp3_browser_runtime_set_state_v2200($pdo,$runtime,'recovering','',(int)$step['id'],'step_failed');
        return [
            'state'=>'recovering','replan_available'=>(int)$runtime['replan_count']<(int)$runtime['max_replans'],
            'recovery_code'=>'step_failed','step'=>vp3_browser_delegation_public_step_v2190($step),
            'runtime'=>vp3_browser_runtime_public_v2200($pdo,$user,$namespace,$runtimePublicId,true)
        ];
    }

    $check=vp3_browser_runtime_skill_check_v2200($runtime,$session,$step);
    if(empty($check['ok'])){
        $cap=(string)($check['capability']??'');
        vp3_browser_runtime_set_state_v2200($pdo,$runtime,'checkpoint',(string)($check['skill_key']??''),(int)$step['id'],(string)$check['code']);
        vp3_browser_runtime_event_v2200($pdo,$runtime,'authority_checkpoint',(string)$check['message'],(string)($check['skill_key']??''),(int)$step['id'],(string)$check['code'],[
            'capability'=>$cap,'target_type'=>(string)($check['target']['target_type']??''),'target_id'=>(string)($check['target']['target_id']??'')
        ]);
        vp3_browser_runtime_notify_v2200($pdo,$runtime,'checkpoint',$step);
        return ['state'=>'authority_required','required_capability'=>$cap,'message'=>(string)$check['message'],'step'=>vp3_browser_delegation_public_step_v2190($step),'runtime'=>vp3_browser_runtime_public_v2200($pdo,$user,$namespace,$runtimePublicId,true)];
    }
    $skillKey=(string)$check['skill_key'];$skill=(array)$check['skill'];$target=(array)$check['target'];

    $resolved=vp3_browser_delegation_target_v2190($pdo,$user,$step);
    if(!$resolved){
        vp3_browser_runtime_set_state_v2200($pdo,$runtime,'recovering',$skillKey,(int)$step['id'],'target_unavailable');
        vp3_browser_runtime_event_v2200($pdo,$runtime,'recovery_required','The current runtime target is no longer authorized.',$skillKey,(int)$step['id'],'target_unavailable',[
            'target_type'=>(string)$target['target_type'],'target_id'=>(string)$target['target_id']
        ]);
        vp3_browser_runtime_notify_v2200($pdo,$runtime,'failed',$step);
        return ['state'=>'recovering','replan_available'=>(int)$runtime['replan_count']<(int)$runtime['max_replans'],'recovery_code'=>'target_unavailable','runtime'=>vp3_browser_runtime_public_v2200($pdo,$user,$namespace,$runtimePublicId,true)];
    }

    if(in_array($skillKey,['inspect_source','follow_source','unfollow_source'],true)){
        $source=is_array($relations['source']??null)?$relations['source']:null;
        if(!$source||(string)($source['id']??'')!==(string)$target['target_id']){
            vp3_browser_runtime_set_state_v2200($pdo,$runtime,'recovering',$skillKey,(int)$step['id'],'active_source_changed');
            vp3_browser_runtime_event_v2200($pdo,$runtime,'recovery_required','The active page no longer matches the runtime Source target.',$skillKey,(int)$step['id'],'active_source_changed',[
                'target_type'=>(string)$target['target_type'],'target_id'=>(string)$target['target_id']
            ]);
            return ['state'=>'recovering','replan_available'=>(int)$runtime['replan_count']<(int)$runtime['max_replans'],'recovery_code'=>'active_source_changed','runtime'=>vp3_browser_runtime_public_v2200($pdo,$user,$namespace,$runtimePublicId,true)];
        }
    }

    vp3_browser_runtime_set_state_v2200($pdo,$runtime,'running',$skillKey,(int)$step['id'],'');
    vp3_browser_runtime_event_v2200($pdo,$runtime,'skill_started','Browser Runtime skill started: '.(string)$skill['label'],$skillKey,(int)$step['id'],'started',[
        'target_type'=>(string)$target['target_type'],'target_id'=>(string)$target['target_id'],'verification_mode'=>(string)$skill['verification_mode']
    ]);

    $result=vp3_browser_delegation_next_v2190($pdo,$user,$namespace,(string)$runtime['delegation_public_id']);
    $state=(string)($result['state']??'');
    if($state==='advanced'){
        $pdo->prepare("UPDATE browser_agent_runtime_sessions_v2200 SET status='ready',current_action_id=NULL,current_skill_key='',recovery_code='',last_verified_at=UTC_TIMESTAMP(),updated_at=UTC_TIMESTAMP() WHERE id=? AND owner_user_id=?")->execute([(int)$runtime['id'],$uid]);
        vp3_browser_runtime_event_v2200($pdo,$runtime,'skill_verified','Browser Runtime skill completed and verified.',$skillKey,(int)$step['id'],'verified',[
            'target_type'=>(string)$target['target_type'],'target_id'=>(string)$target['target_id'],'verification_mode'=>(string)$skill['verification_mode']
        ]);
    }elseif($state==='navigate'){
        vp3_browser_runtime_set_state_v2200($pdo,$runtime,'running',$skillKey,(int)$step['id'],'navigation_pending');
        vp3_browser_runtime_event_v2200($pdo,$runtime,'navigation_requested','Browser Runtime opened an authorized VP3 destination and is waiting for active-tab verification.',$skillKey,(int)$step['id'],'navigation_pending',[
            'target_type'=>(string)$target['target_type'],'target_id'=>(string)$target['target_id']
        ]);
    }elseif($state==='checkpoint'){
        vp3_browser_runtime_set_state_v2200($pdo,$runtime,'checkpoint',$skillKey,(int)$step['id'],'user_checkpoint');
        vp3_browser_runtime_event_v2200($pdo,$runtime,'user_checkpoint','Browser Runtime stopped at an explicit user checkpoint.',$skillKey,(int)$step['id'],'checkpoint',[
            'target_type'=>(string)$target['target_type'],'target_id'=>(string)$target['target_id']
        ]);
        vp3_browser_runtime_notify_v2200($pdo,$runtime,'checkpoint',$step);
    }elseif($state==='failed'){
        vp3_browser_runtime_set_state_v2200($pdo,$runtime,'recovering',$skillKey,(int)$step['id'],'step_failed');
        vp3_browser_runtime_event_v2200($pdo,$runtime,'recovery_required','Browser Runtime could not verify the current step.',$skillKey,(int)$step['id'],'step_failed');
        vp3_browser_runtime_notify_v2200($pdo,$runtime,'failed',$step);
    }elseif($state==='completed'){
        vp3_browser_runtime_set_state_v2200($pdo,$runtime,'completed');
        vp3_browser_runtime_event_v2200($pdo,$runtime,'completed','Browser Agent Runtime completed the verified delegation.',$skillKey,(int)$step['id'],'completed');
        vp3_browser_runtime_notify_v2200($pdo,$runtime,'completed');
    }elseif(in_array($state,['paused','cancelled','expired'],true)){
        vp3_browser_runtime_set_state_v2200($pdo,$runtime,$state);
    }

    return $result+[
        'skill'=>['key'=>$skillKey]+$skill,
        'runtime'=>vp3_browser_runtime_public_v2200($pdo,$user,$namespace,$runtimePublicId,true)
    ];
}

function vp3_browser_runtime_tab_key_v2200(array $runtime,int $tabId): string
{
    if($tabId<1)throw new InvalidArgumentException('A valid Browser tab reference is required.');
    return hash('sha256',(int)$runtime['owner_user_id'].'|'.(string)$runtime['agent_namespace'].'|'.(string)$runtime['public_id'].'|'.$tabId);
}

function vp3_browser_runtime_tab_seen_v2200(PDO $pdo,array $user,string $namespace,string $runtimePublicId,int $tabId,string $role,string $targetType,string $targetId): array
{
    $uid=(int)($user['id']??0);$runtime=vp3_browser_runtime_row_v2200($pdo,$uid,$namespace,$runtimePublicId);
    if(!$runtime)throw new RuntimeException('Browser Agent Runtime session was not found.');
    $role=in_array($role,['source','runtime','checkpoint'],true)?$role:'runtime';
    $targetType=vp3_browser_memory_target_type_v2170($targetType);$targetId=vp3_browser_memory_target_id_v2170($targetId);
    if($targetType===''||$targetId===''||!vp3_browser_memory_target_v2170($pdo,$user,$targetType,$targetId))throw new RuntimeException('Runtime tab target is no longer authorized.');
    $key=vp3_browser_runtime_tab_key_v2200($runtime,$tabId);
    $pdo->prepare("INSERT INTO browser_agent_runtime_tabs_v2200
      (runtime_session_id,owner_user_id,client_tab_key,role,target_type,target_id,status)
      VALUES (?,?,?,?,?,?,'open')
      ON DUPLICATE KEY UPDATE role=VALUES(role),target_type=VALUES(target_type),target_id=VALUES(target_id),status='open',closed_at=NULL,last_seen_at=UTC_TIMESTAMP()")
      ->execute([(int)$runtime['id'],$uid,$key,$role,$targetType,$targetId]);
    vp3_browser_runtime_event_v2200($pdo,$runtime,'tab_seen','Runtime tab reference registered without storing its URL or title.',(string)$runtime['current_skill_key'],(int)($runtime['current_action_id']??0),'tab_open',[
        'tab_role'=>$role,'target_type'=>$targetType,'target_id'=>$targetId
    ]);
    return vp3_browser_runtime_tabs_v2200($pdo,$uid,(int)$runtime['id']);
}

function vp3_browser_runtime_tab_closed_v2200(PDO $pdo,array $user,string $namespace,string $runtimePublicId,int $tabId): array
{
    $uid=(int)($user['id']??0);$runtime=vp3_browser_runtime_row_v2200($pdo,$uid,$namespace,$runtimePublicId);
    if(!$runtime)throw new RuntimeException('Browser Agent Runtime session was not found.');
    $key=vp3_browser_runtime_tab_key_v2200($runtime,$tabId);
    $pdo->prepare("UPDATE browser_agent_runtime_tabs_v2200 SET status='closed',closed_at=UTC_TIMESTAMP(),last_seen_at=UTC_TIMESTAMP()
      WHERE runtime_session_id=? AND owner_user_id=? AND client_tab_key=?")->execute([(int)$runtime['id'],$uid,$key]);
    return vp3_browser_runtime_tabs_v2200($pdo,$uid,(int)$runtime['id']);
}

function vp3_browser_runtime_verify_navigation_v2200(PDO $pdo,array $user,string $namespace,string $runtimePublicId,int $actionId,array $context,int $tabId=0): array
{
    $uid=(int)($user['id']??0);$runtime=vp3_browser_runtime_row_v2200($pdo,$uid,$namespace,$runtimePublicId);
    if(!$runtime)throw new RuntimeException('Browser Agent Runtime session was not found.');
    $step=vp3_browser_runtime_current_step_v2200($pdo,$uid,(int)$runtime['workflow_run_id']);
    if(!$step||(int)$step['id']!==$actionId)throw new RuntimeException('That runtime navigation step is no longer current.');
    $target=vp3_browser_delegation_step_target_v2190($step);
    $delegation=vp3_browser_delegation_verify_navigation_v2190($pdo,$user,$namespace,(string)$runtime['delegation_public_id'],$actionId,$context);
    $pdo->prepare("UPDATE browser_agent_runtime_sessions_v2200 SET status='ready',current_action_id=NULL,current_skill_key='',recovery_code='',last_verified_at=UTC_TIMESTAMP(),updated_at=UTC_TIMESTAMP() WHERE id=? AND owner_user_id=?")->execute([(int)$runtime['id'],$uid]);
    vp3_browser_runtime_event_v2200($pdo,$runtime,'navigation_verified','Authorized VP3 navigation verified against the active Browser tab.',(string)$runtime['current_skill_key'],$actionId,'verified',[
        'target_type'=>(string)$target['target_type'],'target_id'=>(string)$target['target_id'],'verification_mode'=>'navigation'
    ]);
    if($tabId>0)vp3_browser_runtime_tab_seen_v2200($pdo,$user,$namespace,$runtimePublicId,$tabId,'runtime',(string)$target['target_type'],(string)$target['target_id']);
    return ['state'=>(string)$delegation['status'],'delegation'=>$delegation,'runtime'=>vp3_browser_runtime_public_v2200($pdo,$user,$namespace,$runtimePublicId,true)];
}

function vp3_browser_runtime_complete_checkpoint_v2200(PDO $pdo,array $user,string $namespace,string $runtimePublicId,int $actionId): array
{
    $uid=(int)($user['id']??0);$runtime=vp3_browser_runtime_row_v2200($pdo,$uid,$namespace,$runtimePublicId);
    if(!$runtime)throw new RuntimeException('Browser Agent Runtime session was not found.');
    $step=vp3_browser_runtime_current_step_v2200($pdo,$uid,(int)$runtime['workflow_run_id']);
    if(!$step||(int)$step['id']!==$actionId)throw new RuntimeException('That runtime checkpoint is no longer current.');
    $delegation=vp3_browser_delegation_complete_checkpoint_v2190($pdo,$user,$namespace,(string)$runtime['delegation_public_id'],$actionId);
    $terminal=(string)$delegation['status']==='completed';
    $pdo->prepare("UPDATE browser_agent_runtime_sessions_v2200 SET status=?,current_action_id=NULL,current_skill_key='',recovery_code='',last_verified_at=UTC_TIMESTAMP(),completed_at=?,updated_at=UTC_TIMESTAMP() WHERE id=? AND owner_user_id=?")
        ->execute([$terminal?'completed':'ready',$terminal?gmdate('Y-m-d H:i:s'):null,(int)$runtime['id'],$uid]);
    vp3_browser_runtime_event_v2200($pdo,$runtime,'checkpoint_verified','User-confirmed runtime checkpoint completed.',(string)$runtime['current_skill_key'],$actionId,'verified',['verification_mode'=>'user_confirmation']);
    if($terminal)vp3_browser_runtime_notify_v2200($pdo,$runtime,'completed');
    return ['state'=>$terminal?'completed':'ready','delegation'=>$delegation,'runtime'=>vp3_browser_runtime_public_v2200($pdo,$user,$namespace,$runtimePublicId,true)];
}

function vp3_browser_runtime_lifecycle_v2200(PDO $pdo,array $user,string $namespace,string $runtimePublicId,string $action): array
{
    $uid=(int)($user['id']??0);$runtime=vp3_browser_runtime_row_v2200($pdo,$uid,$namespace,$runtimePublicId);
    if(!$runtime)throw new RuntimeException('Browser Agent Runtime session was not found.');
    if(!in_array($action,['pause','resume','cancel'],true))throw new InvalidArgumentException('Unknown Browser Runtime lifecycle action.');
    $delegation=vp3_browser_delegation_update_status_v2190($pdo,$user,$namespace,(string)$runtime['delegation_public_id'],$action);
    $status=$action==='pause'?'paused':($action==='resume'?'ready':'cancelled');
    vp3_browser_runtime_set_state_v2200($pdo,$runtime,$status);
    vp3_browser_runtime_event_v2200($pdo,$runtime,'runtime_'.$action,'Browser Agent Runtime '.($action==='cancel'?'cancelled':$action.'d').'.','','',$status,[
        'from_status'=>(string)$runtime['status'],'to_status'=>$status
    ]);
    return ['state'=>$status,'delegation'=>$delegation,'runtime'=>vp3_browser_runtime_public_v2200($pdo,$user,$namespace,$runtimePublicId,true)];
}

function vp3_browser_runtime_retry_v2200(PDO $pdo,array $user,string $namespace,string $runtimePublicId,int $actionId): array
{
    $uid=(int)($user['id']??0);$runtime=vp3_browser_runtime_row_v2200($pdo,$uid,$namespace,$runtimePublicId);
    if(!$runtime)throw new RuntimeException('Browser Agent Runtime session was not found.');
    $delegation=vp3_browser_delegation_retry_v2190($pdo,$user,$namespace,(string)$runtime['delegation_public_id'],$actionId);
    $pdo->prepare("UPDATE browser_agent_runtime_sessions_v2200 SET status='ready',current_action_id=NULL,current_skill_key='',recovery_code='',updated_at=UTC_TIMESTAMP() WHERE id=? AND owner_user_id=?")->execute([(int)$runtime['id'],$uid]);
    vp3_browser_runtime_event_v2200($pdo,$runtime,'retry_queued','Runtime step queued for bounded retry.','',$actionId,'retry');
    return ['state'=>'ready','delegation'=>$delegation,'runtime'=>vp3_browser_runtime_public_v2200($pdo,$user,$namespace,$runtimePublicId,true)];
}

function vp3_browser_runtime_skip_v2200(PDO $pdo,array $user,string $namespace,string $runtimePublicId,int $actionId): array
{
    $uid=(int)($user['id']??0);$runtime=vp3_browser_runtime_row_v2200($pdo,$uid,$namespace,$runtimePublicId);
    if(!$runtime)throw new RuntimeException('Browser Agent Runtime session was not found.');
    $stmt=$pdo->prepare("SELECT * FROM agent_workflow_actions WHERE id=? AND run_id=? AND owner_user_id=? LIMIT 1");
    $stmt->execute([$actionId,(int)$runtime['workflow_run_id'],$uid]);$step=$stmt->fetch(PDO::FETCH_ASSOC);
    if(!is_array($step)||!in_array((string)$step['status'],['queued','failed','approval_pending','executing'],true))throw new RuntimeException('That runtime step cannot be skipped.');
    $pdo->prepare("UPDATE agent_workflow_actions SET status='skipped',result_summary='Skipped explicitly by user in Browser Runtime.',error_class='',completed_at=UTC_TIMESTAMP(),updated_at=UTC_TIMESTAMP() WHERE id=? AND run_id=? AND owner_user_id=?")->execute([$actionId,(int)$runtime['workflow_run_id'],$uid]);
    $pdo->prepare("UPDATE browser_delegations_v2190 SET status='active',updated_at=UTC_TIMESTAMP() WHERE id=? AND owner_user_id=?")->execute([(int)$runtime['delegation_id'],$uid]);
    $pdo->prepare("UPDATE agent_workflow_runs SET status='approved',current_action_id=NULL,progress_message='Runtime step skipped by user',next_attempt_at=UTC_TIMESTAMP() WHERE id=? AND owner_user_id=?")->execute([(int)$runtime['workflow_run_id'],$uid]);
    $delegationRow=vp3_browser_delegation_row_v2190($pdo,$uid,$namespace,(string)$runtime['delegation_public_id']);
    if($delegationRow)vp3_browser_delegation_complete_if_done_v2190($pdo,$delegationRow);
    $delegation=vp3_browser_delegation_public_v2190($pdo,$user,$namespace,(string)$runtime['delegation_public_id'],true);
    $terminal=$delegation&&(string)$delegation['status']==='completed';
    vp3_browser_runtime_set_state_v2200($pdo,$runtime,$terminal?'completed':'ready');
    vp3_browser_runtime_event_v2200($pdo,$runtime,'step_skipped','Runtime step skipped explicitly by the user.','',$actionId,'skipped');
    if($terminal)vp3_browser_runtime_notify_v2200($pdo,$runtime,'completed');
    return ['state'=>$terminal?'completed':'ready','delegation'=>$delegation,'runtime'=>vp3_browser_runtime_public_v2200($pdo,$user,$namespace,$runtimePublicId,true)];
}

function vp3_browser_runtime_replan_v2200(PDO $pdo,array $user,string $namespace,array $session,string $runtimePublicId,array $context,array $relations): array
{
    $uid=(int)($user['id']??0);$runtime=vp3_browser_runtime_row_v2200($pdo,$uid,$namespace,$runtimePublicId);
    if(!$runtime)throw new RuntimeException('Browser Agent Runtime session was not found.');
    if((int)$runtime['replan_count']>=(int)$runtime['max_replans'])throw new RuntimeException('This Browser Runtime reached its bounded replan limit.');
    if(strtotime((string)$runtime['expires_at'])<time())throw new RuntimeException('This Browser Runtime authority has expired.');

    $steps=vp3_browser_delegation_steps_v2190($pdo,$uid,(int)$runtime['workflow_run_id']);
    foreach($steps as $step)if((string)$step['status']==='approval_pending')throw new RuntimeException('Complete or skip the current user checkpoint before replanning.');
    foreach($steps as $step)if((string)$step['status']==='executing')throw new RuntimeException('Verify or skip the active Browser step before replanning.');

    $source=is_array($relations['source']??null)?$relations['source']:null;
    if(!$source||(string)($source['id']??'')!==(string)$runtime['source_id'])throw new RuntimeException('Runtime replanning cannot change the delegation Source authority.');

    $constraints=[
        'allowed_domains'=>vp3_browser_delegation_json_array_v2190($runtime['allowed_domains_json']),
        'allowed_actions'=>vp3_browser_delegation_json_array_v2190($runtime['allowed_actions_json']),
        'max_steps'=>(int)$runtime['max_steps'],
        'risk_budget'=>(string)$runtime['risk_budget'],
        'expires_minutes'=>max(15,(int)ceil((strtotime((string)$runtime['expires_at'])-time())/60)),
    ];
    $plan=vp3_browser_delegation_plan_v2190($pdo,$user,$namespace,$session,$context,$relations,(string)$runtime['goal'],$constraints);

    $completed=[];$completedCount=0;$mutable=[];$maxSequence=0;
    foreach($steps as $step){
        $maxSequence=max($maxSequence,(int)$step['sequence_no']);
        $target=vp3_browser_delegation_step_target_v2190($step);
        $key=(string)$target['action_key'].'|'.(string)$target['target_type'].'|'.(string)$target['target_id'];
        if((string)$step['status']==='completed'){$completed[$key]=true;$completedCount++;}
        elseif(in_array((string)$step['status'],['queued','failed','skipped'],true))$mutable[]=$step;
    }
    $slots=max(0,(int)$runtime['max_steps']-$completedCount);
    $desired=[];
    foreach((array)$plan['steps'] as $candidate){
        $key=(string)$candidate['action_key'].'|'.(string)$candidate['target_type'].'|'.(string)$candidate['target_id'];
        if(isset($completed[$key]))continue;
        $desired[]=$candidate;if(count($desired)>=$slots)break;
    }

    $revision=(int)$runtime['plan_revision']+1;
    $update=$pdo->prepare("UPDATE agent_workflow_actions SET action_key=?,action_type=?,label=?,summary=?,status='queued',requires_approval=?,execution_target='browser',capability_key=?,result_summary='',result_json=NULL,error_class='',available_at=UTC_TIMESTAMP(),started_at=NULL,completed_at=NULL,updated_at=UTC_TIMESTAMP() WHERE id=? AND run_id=? AND owner_user_id=?");
    $insert=$pdo->prepare("INSERT INTO agent_workflow_actions
      (run_id,owner_user_id,sequence_no,action_key,action_type,label,summary,status,requires_approval,execution_target,capability_key,available_at,max_attempts,timeout_seconds)
      VALUES (?,?,?,?,?,?,?,'queued',?,'browser',?,UTC_TIMESTAMP(),3,900)");
    $used=0;
    foreach($desired as $index=>$candidate){
        $action=(string)$candidate['action_key'];$skill=vp3_browser_runtime_skill_v2200($action);
        if(!$skill)continue;
        $stepKey='runtime-r'.$revision.'-step-'.($index+1).'-'.$action;
        $summary=$action.'|'.(string)$candidate['target_type'].'|'.(string)$candidate['target_id'];
        $capability='browser.runtime.skill.'.$action;
        if(isset($mutable[$used])){
            $update->execute([
                $stepKey,(string)$candidate['step_kind'],agent_workflow_text_v1400($candidate['label'],190),
                agent_workflow_text_v1400($summary,1500),!empty($candidate['requires_checkpoint'])?1:0,$capability,
                (int)$mutable[$used]['id'],(int)$runtime['workflow_run_id'],$uid
            ]);
        }else{
            $maxSequence++;
            $insert->execute([
                (int)$runtime['workflow_run_id'],$uid,$maxSequence,$stepKey,(string)$candidate['step_kind'],
                agent_workflow_text_v1400($candidate['label'],190),agent_workflow_text_v1400($summary,1500),
                !empty($candidate['requires_checkpoint'])?1:0,$capability
            ]);
        }
        $used++;
    }
    for($i=$used;$i<count($mutable);$i++){
        $pdo->prepare("UPDATE agent_workflow_actions SET status='skipped',result_summary='Superseded by bounded Browser Runtime replan.',completed_at=UTC_TIMESTAMP(),updated_at=UTC_TIMESTAMP() WHERE id=? AND run_id=? AND owner_user_id=?")
            ->execute([(int)$mutable[$i]['id'],(int)$runtime['workflow_run_id'],$uid]);
    }

    $pdo->prepare("UPDATE browser_agent_runtime_sessions_v2200 SET status='ready',current_action_id=NULL,current_skill_key='',plan_revision=?,replan_count=replan_count+1,recovery_code='',updated_at=UTC_TIMESTAMP() WHERE id=? AND owner_user_id=?")
        ->execute([$revision,(int)$runtime['id'],$uid]);
    $pdo->prepare("UPDATE browser_delegations_v2190 SET status='active',updated_at=UTC_TIMESTAMP() WHERE id=? AND owner_user_id=?")->execute([(int)$runtime['delegation_id'],$uid]);
    $pdo->prepare("UPDATE agent_workflow_runs SET status='approved',current_action_id=NULL,next_attempt_at=UTC_TIMESTAMP(),progress_message='Browser Runtime replanned within approved authority' WHERE id=? AND owner_user_id=?")
        ->execute([(int)$runtime['workflow_run_id'],$uid]);
    vp3_browser_runtime_event_v2200($pdo,$runtime,'replanned','Browser Runtime revised remaining steps without expanding delegation authority.','','','replanned',[
        'plan_revision'=>$revision,'step_count'=>count($desired),'reason'=>(string)$runtime['recovery_code']
    ]);
    agent_workflow_event_v1400($pdo,$uid,(int)$runtime['workflow_run_id'],'browser_runtime_replanned',(string)$runtime['workflow_status'],'approved','browser','Browser Runtime replanned remaining work inside the original delegation authority.',['plan_revision'=>$revision,'step_count'=>count($desired)]);
    return ['state'=>'ready','plan_revision'=>$revision,'runtime'=>vp3_browser_runtime_public_v2200($pdo,$user,$namespace,$runtimePublicId,true)];
}
