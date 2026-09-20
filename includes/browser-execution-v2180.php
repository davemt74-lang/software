<?php
declare(strict_types=1);

/**
 * VP3 Browser Companion Agent Execution & Follow-Through v21.80.
 *
 * The browser may propose work from ephemeral page context, but durable execution
 * state stores only VP3 object references and lifecycle metadata. Current-page
 * context is re-derived and re-authorized before proposal and execution.
 */
const VP3_BROWSER_EXECUTION_V2180='browser-execution-v2180-20260920';
const VP3_BROWSER_EXECUTION_LIST_LIMIT_V2180=30;

require_once __DIR__.'/browser-context-v2130.php';
require_once __DIR__.'/browser-source-feed-v2050.php';
require_once __DIR__.'/cognitive-orchestration-v560.php';

function vp3_browser_execution_schema_ready_v2180(?PDO $pdo=null): bool
{
    $pdo??=db();
    return (bool)$pdo
        && table_exists('browser_execution_tickets_v2180')
        && table_exists('browser_execution_events_v2180');
}

function vp3_browser_execution_ensure_schema_v2180(?PDO $pdo=null): void
{
    $pdo??=db();
    if(!$pdo)throw new RuntimeException('Database connection is unavailable.');
    if(vp3_browser_execution_schema_ready_v2180($pdo))return;
    if(!table_exists('users'))throw new RuntimeException('VP3 users must exist before Browser Execution.');

    $pdo->exec("CREATE TABLE IF NOT EXISTS browser_execution_tickets_v2180 (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
      public_id CHAR(36) NOT NULL,
      owner_user_id INT UNSIGNED NOT NULL,
      agent_namespace VARCHAR(80) NOT NULL DEFAULT 'system',
      action_key VARCHAR(80) NOT NULL,
      target_type VARCHAR(80) NOT NULL,
      target_id VARCHAR(190) NOT NULL,
      target_scope VARCHAR(40) NOT NULL DEFAULT 'personal',
      status VARCHAR(32) NOT NULL DEFAULT 'proposed',
      risk_level VARCHAR(16) NOT NULL DEFAULT 'low',
      requires_confirmation TINYINT(1) NOT NULL DEFAULT 1,
      verification_mode VARCHAR(32) NOT NULL DEFAULT 'user',
      verification_state VARCHAR(32) NOT NULL DEFAULT 'waiting',
      attempt_count INT UNSIGNED NOT NULL DEFAULT 0,
      confirmed_at DATETIME NULL,
      executed_at DATETIME NULL,
      verified_at DATETIME NULL,
      failed_at DATETIME NULL,
      cancelled_at DATETIME NULL,
      expires_at DATETIME NOT NULL,
      last_error_code VARCHAR(80) NOT NULL DEFAULT '',
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      PRIMARY KEY (id),
      UNIQUE KEY uq_browser_execution_public_v2180 (public_id),
      INDEX idx_browser_execution_owner_v2180 (owner_user_id,agent_namespace,status,updated_at),
      INDEX idx_browser_execution_target_v2180 (owner_user_id,target_type,target_id),
      CONSTRAINT fk_browser_execution_owner_v2180 FOREIGN KEY (owner_user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS browser_execution_events_v2180 (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
      ticket_id BIGINT UNSIGNED NOT NULL,
      owner_user_id INT UNSIGNED NOT NULL,
      event_type VARCHAR(40) NOT NULL,
      event_key CHAR(64) NOT NULL,
      result_code VARCHAR(80) NOT NULL DEFAULT '',
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      PRIMARY KEY (id),
      UNIQUE KEY uq_browser_execution_event_v2180 (event_key),
      INDEX idx_browser_execution_event_ticket_v2180 (ticket_id,created_at),
      CONSTRAINT fk_browser_execution_event_ticket_v2180 FOREIGN KEY (ticket_id) REFERENCES browser_execution_tickets_v2180(id) ON DELETE CASCADE,
      CONSTRAINT fk_browser_execution_event_owner_v2180 FOREIGN KEY (owner_user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

function vp3_browser_execution_action_meta_v2180(string $action): ?array
{
    return match($action){
        'follow_source'=>[
            'label'=>'Follow this VP3 Source','risk_level'=>'low','requires_confirmation'=>true,
            'mode'=>'server','verification_mode'=>'server_state'
        ],
        'unfollow_source'=>[
            'label'=>'Unfollow this VP3 Source','risk_level'=>'low','requires_confirmation'=>true,
            'mode'=>'server','verification_mode'=>'server_state'
        ],
        'draft_task'=>[
            'label'=>'Draft a task with Agent','risk_level'=>'medium','requires_confirmation'=>true,
            'mode'=>'agent_prompt','verification_mode'=>'user'
        ],
        'draft_knowledge'=>[
            'label'=>'Draft a Knowledge entry','risk_level'=>'medium','requires_confirmation'=>true,
            'mode'=>'agent_prompt','verification_mode'=>'user'
        ],
        'share_team'=>[
            'label'=>'Prepare a Team share','risk_level'=>'medium','requires_confirmation'=>true,
            'mode'=>'manual_flow','verification_mode'=>'user'
        ],
        'open_research'=>[
            'label'=>'Open related Research','risk_level'=>'low','requires_confirmation'=>false,
            'mode'=>'open','verification_mode'=>'none'
        ],
        'open_profile'=>[
            'label'=>'Open related profile','risk_level'=>'low','requires_confirmation'=>false,
            'mode'=>'open','verification_mode'=>'none'
        ],
        'open_contact'=>[
            'label'=>'Open related CRM contact','risk_level'=>'low','requires_confirmation'=>false,
            'mode'=>'open','verification_mode'=>'none'
        ],
        default=>null,
    };
}

function vp3_browser_execution_candidate_v2180(string $action,string $type,string $id,string $scope,string $detail='',string $url=''): ?array
{
    $meta=vp3_browser_execution_action_meta_v2180($action);
    $id=trim($id);
    if(!$meta||$id==='')return null;
    return [
        'action_key'=>$action,
        'label'=>(string)$meta['label'],
        'target_type'=>$type,
        'target_id'=>$id,
        'target_scope'=>$scope,
        'risk_level'=>(string)$meta['risk_level'],
        'requires_confirmation'=>(bool)$meta['requires_confirmation'],
        'mode'=>(string)$meta['mode'],
        'verification_mode'=>(string)$meta['verification_mode'],
        'detail'=>$detail,
        'url'=>$url,
    ];
}

function vp3_browser_execution_candidates_v2180(PDO $pdo,array $user,string $namespace,array $session,array $context,array $relations): array
{
    $caps=array_fill_keys((array)($session['capabilities']??[]),true);
    $out=[];
    $source=is_array($relations['source']??null)?$relations['source']:null;
    if($source&&trim((string)($source['id']??''))!==''){
        $sourceId=(string)$source['id'];
        if(isset($caps['team.chat.read'])){
            $action=!empty($source['following'])?'unfollow_source':'follow_source';
            $candidate=vp3_browser_execution_candidate_v2180(
                $action,'browser_source',$sourceId,'personal',
                !empty($source['following'])?'Stop receiving this Source in Following.':'Add this Source to Following.'
            );
            if($candidate)$out[]=$candidate;
        }
        if(isset($caps['task.propose'])){
            $candidate=vp3_browser_execution_candidate_v2180(
                'draft_task','browser_source',$sourceId,'personal',
                'Prepare a task draft in the canonical VP3 Agent Workspace. Nothing is created until you send/approve it.'
            );
            if($candidate)$out[]=$candidate;
        }
        if(isset($caps['knowledge.write'])){
            $candidate=vp3_browser_execution_candidate_v2180(
                'draft_knowledge','browser_source',$sourceId,'personal',
                'Prepare a Knowledge draft in Agent. Browser Execution does not silently write Knowledge.'
            );
            if($candidate)$out[]=$candidate;
        }
        if(isset($caps['team.share.create'])){
            $candidate=vp3_browser_execution_candidate_v2180(
                'share_team','browser_source',$sourceId,'team',
                'Open the existing Team-share flow. Final destination and publish remain explicit.'
            );
            if($candidate)$out[]=$candidate;
        }
    }

    $research=(array)($relations['research']??[]);
    if(!empty($research[0]['id'])){
        $candidate=vp3_browser_execution_candidate_v2180(
            'open_research','research_project',(string)$research[0]['id'],'project',
            'Continue work in the related Research project.',(string)($research[0]['url']??'')
        );
        if($candidate)$out[]=$candidate;
    }
    $profiles=(array)($relations['profiles']??[]);
    if(!empty($profiles[0]['id'])){
        $candidate=vp3_browser_execution_candidate_v2180(
            'open_profile','public_profile',(string)$profiles[0]['id'],'public',
            'Open the currently authorized related VP3 profile.',(string)($profiles[0]['url']??'')
        );
        if($candidate)$out[]=$candidate;
    }
    $contacts=(array)($relations['contacts']??[]);
    if(!empty($contacts[0]['id'])){
        $candidate=vp3_browser_execution_candidate_v2180(
            'open_contact','crm_contact',(string)$contacts[0]['id'],'admin',
            'Open the currently authorized CRM relationship.',(string)($contacts[0]['url']??'')
        );
        if($candidate)$out[]=$candidate;
    }
    return array_slice($out,0,8);
}

function vp3_browser_execution_find_candidate_v2180(array $candidates,string $action,string $type,string $id): ?array
{
    foreach($candidates as $candidate){
        if((string)($candidate['action_key']??'')===$action
            &&(string)($candidate['target_type']??'')===$type
            &&(string)($candidate['target_id']??'')===$id)return $candidate;
    }
    return null;
}

function vp3_browser_execution_ticket_v2180(PDO $pdo,int $userId,string $namespace,string $publicId): ?array
{
    if(!preg_match('/^[a-f0-9-]{36}$/i',$publicId))return null;
    $stmt=$pdo->prepare("SELECT * FROM browser_execution_tickets_v2180 WHERE public_id=? AND owner_user_id=? AND agent_namespace=? LIMIT 1");
    $stmt->execute([$publicId,$userId,$namespace]);
    $row=$stmt->fetch(PDO::FETCH_ASSOC);
    return is_array($row)?$row:null;
}

function vp3_browser_execution_public_ticket_v2180(array $row): array
{
    $meta=vp3_browser_execution_action_meta_v2180((string)($row['action_key']??''))??[];
    return [
        'ticket_id'=>(string)($row['public_id']??''),
        'action_key'=>(string)($row['action_key']??''),
        'label'=>(string)($meta['label']??'Browser action'),
        'target_type'=>(string)($row['target_type']??''),
        'target_id'=>(string)($row['target_id']??''),
        'target_scope'=>(string)($row['target_scope']??'personal'),
        'status'=>(string)($row['status']??'proposed'),
        'risk_level'=>(string)($row['risk_level']??'low'),
        'requires_confirmation'=>(bool)($row['requires_confirmation']??true),
        'verification_mode'=>(string)($row['verification_mode']??'user'),
        'verification_state'=>(string)($row['verification_state']??'waiting'),
        'attempt_count'=>(int)($row['attempt_count']??0),
        'confirmed_at'=>$row['confirmed_at']??null,
        'executed_at'=>$row['executed_at']??null,
        'verified_at'=>$row['verified_at']??null,
        'failed_at'=>$row['failed_at']??null,
        'cancelled_at'=>$row['cancelled_at']??null,
        'expires_at'=>$row['expires_at']??null,
        'created_at'=>$row['created_at']??null,
        'updated_at'=>$row['updated_at']??null,
        'resumable'=>in_array((string)($row['status']??''),['proposed','confirmed','awaiting_user'],true),
    ];
}

function vp3_browser_execution_list_v2180(PDO $pdo,int $userId,string $namespace,int $limit=VP3_BROWSER_EXECUTION_LIST_LIMIT_V2180): array
{
    $limit=max(1,min(VP3_BROWSER_EXECUTION_LIST_LIMIT_V2180,$limit));
    $stmt=$pdo->prepare("SELECT * FROM browser_execution_tickets_v2180 WHERE owner_user_id=? AND agent_namespace=? ORDER BY id DESC LIMIT ".$limit);
    $stmt->execute([$userId,$namespace]);
    return array_map('vp3_browser_execution_public_ticket_v2180',$stmt->fetchAll(PDO::FETCH_ASSOC)?:[]);
}

function vp3_browser_execution_continuity_v2180(PDO $pdo,int $userId,string $namespace): array
{
    if(!vp3_cognitive_orchestration_schema_ready_v560($pdo))return [];
    $stmt=$pdo->prepare("SELECT public_id,plan_public_id,status,verification_state,current_step_key,completed_steps,total_steps,updated_at
      FROM cognitive_plan_runs_v560
      WHERE owner_user_id=? AND agent_namespace=? AND status NOT IN ('closed','completed','superseded','cancelled')
      ORDER BY updated_at DESC LIMIT 8");
    $stmt->execute([$userId,$namespace]);
    return array_map(static fn(array $row): array=>[
        'run_id'=>(string)$row['public_id'],
        'plan_id'=>(string)$row['plan_public_id'],
        'status'=>(string)$row['status'],
        'verification_state'=>(string)$row['verification_state'],
        'current_step_key'=>(string)$row['current_step_key'],
        'completed_steps'=>(int)$row['completed_steps'],
        'total_steps'=>(int)$row['total_steps'],
        'updated_at'=>$row['updated_at']??null,
    ],$stmt->fetchAll(PDO::FETCH_ASSOC)?:[]);
}

function vp3_browser_execution_event_v2180(PDO $pdo,array $ticket,string $type,string $code=''): void
{
    $key=hash('sha256',(string)$ticket['public_id'].'|'.$type.'|'.$code.'|'.microtime(true).'|'.random_int(1,PHP_INT_MAX));
    $pdo->prepare("INSERT INTO browser_execution_events_v2180 (ticket_id,owner_user_id,event_type,event_key,result_code) VALUES (?,?,?,?,?)")
        ->execute([(int)$ticket['id'],(int)$ticket['owner_user_id'],$type,$key,substr($code,0,80)]);
}

function vp3_browser_execution_propose_v2180(PDO $pdo,array $user,string $namespace,array $candidate): array
{
    $userId=(int)($user['id']??0);
    if($userId<1)throw new RuntimeException('Browser Execution requires a signed-in VP3 user.');
    $publicId=vp3_extension_uuid_v2000();
    $requires=!empty($candidate['requires_confirmation']);
    $status=$requires?'proposed':'confirmed';
    $confirmed=$requires?null:gmdate('Y-m-d H:i:s');
    $expires=gmdate('Y-m-d H:i:s',time()+86400);
    $stmt=$pdo->prepare("INSERT INTO browser_execution_tickets_v2180
      (public_id,owner_user_id,agent_namespace,action_key,target_type,target_id,target_scope,status,risk_level,requires_confirmation,verification_mode,verification_state,confirmed_at,expires_at)
      VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
    $stmt->execute([
        $publicId,$userId,$namespace,(string)$candidate['action_key'],(string)$candidate['target_type'],
        (string)$candidate['target_id'],(string)$candidate['target_scope'],$status,(string)$candidate['risk_level'],
        $requires?1:0,(string)$candidate['verification_mode'],'waiting',$confirmed,$expires
    ]);
    $ticket=vp3_browser_execution_ticket_v2180($pdo,$userId,$namespace,$publicId);
    if(!$ticket)throw new RuntimeException('Browser Execution proposal could not be created.');
    vp3_browser_execution_event_v2180($pdo,$ticket,'proposed',$status);
    return vp3_browser_execution_public_ticket_v2180($ticket);
}

function vp3_browser_execution_confirm_v2180(PDO $pdo,array $user,string $namespace,string $publicId): array
{
    $ticket=vp3_browser_execution_ticket_v2180($pdo,(int)$user['id'],$namespace,$publicId);
    if(!$ticket)throw new RuntimeException('Browser Execution ticket was not found.');
    if((string)$ticket['status']==='confirmed')return vp3_browser_execution_public_ticket_v2180($ticket);
    if((string)$ticket['status']!=='proposed')throw new RuntimeException('Only a proposed Browser action can be confirmed.');
    if(strtotime((string)$ticket['expires_at'])<time())throw new RuntimeException('This Browser action expired. Prepare it again from the current page.');
    $pdo->prepare("UPDATE browser_execution_tickets_v2180 SET status='confirmed',confirmed_at=UTC_TIMESTAMP(),updated_at=UTC_TIMESTAMP() WHERE id=?")
        ->execute([(int)$ticket['id']]);
    $ticket=vp3_browser_execution_ticket_v2180($pdo,(int)$user['id'],$namespace,$publicId)?:$ticket;
    vp3_browser_execution_event_v2180($pdo,$ticket,'confirmed','user');
    return vp3_browser_execution_public_ticket_v2180($ticket);
}

function vp3_browser_execution_cancel_v2180(PDO $pdo,array $user,string $namespace,string $publicId): array
{
    $ticket=vp3_browser_execution_ticket_v2180($pdo,(int)$user['id'],$namespace,$publicId);
    if(!$ticket)throw new RuntimeException('Browser Execution ticket was not found.');
    if(in_array((string)$ticket['status'],['completed','cancelled'],true))return vp3_browser_execution_public_ticket_v2180($ticket);
    $pdo->prepare("UPDATE browser_execution_tickets_v2180 SET status='cancelled',cancelled_at=UTC_TIMESTAMP(),updated_at=UTC_TIMESTAMP() WHERE id=?")
        ->execute([(int)$ticket['id']]);
    $ticket=vp3_browser_execution_ticket_v2180($pdo,(int)$user['id'],$namespace,$publicId)?:$ticket;
    vp3_browser_execution_event_v2180($pdo,$ticket,'cancelled','user');
    return vp3_browser_execution_public_ticket_v2180($ticket);
}

function vp3_browser_execution_complete_user_v2180(PDO $pdo,array $user,string $namespace,string $publicId): array
{
    $ticket=vp3_browser_execution_ticket_v2180($pdo,(int)$user['id'],$namespace,$publicId);
    if(!$ticket)throw new RuntimeException('Browser Execution ticket was not found.');
    if((string)$ticket['status']==='completed')return vp3_browser_execution_public_ticket_v2180($ticket);
    if((string)$ticket['status']!=='awaiting_user')throw new RuntimeException('This action is not waiting for user completion.');
    $pdo->prepare("UPDATE browser_execution_tickets_v2180 SET status='completed',verification_state='user_confirmed',verified_at=UTC_TIMESTAMP(),updated_at=UTC_TIMESTAMP() WHERE id=?")
        ->execute([(int)$ticket['id']]);
    $ticket=vp3_browser_execution_ticket_v2180($pdo,(int)$user['id'],$namespace,$publicId)?:$ticket;
    vp3_browser_execution_event_v2180($pdo,$ticket,'verified','user_confirmed');
    return vp3_browser_execution_public_ticket_v2180($ticket);
}

function vp3_browser_execution_handoff_v2180(array $candidate): array
{
    $action=(string)$candidate['action_key'];
    if($action==='draft_task')return [
        'kind'=>'agent_prompt',
        'prompt'=>'Using the current Browser page as temporary context, prepare the safest concrete task proposal. Show me the title, details, owner, and due-date suggestion. Do not create or assign anything until I explicitly approve it.',
    ];
    if($action==='draft_knowledge')return [
        'kind'=>'agent_prompt',
        'prompt'=>'Using the current Browser page as temporary context, prepare a VP3 Knowledge draft with a concise title, summary, and source relationship. Do not save it until I explicitly approve it.',
    ];
    if($action==='share_team')return ['kind'=>'manual_flow','target'=>'this_page'];
    if(in_array($action,['open_research','open_profile','open_contact'],true))return ['kind'=>'open','url'=>(string)($candidate['url']??'')];
    return [];
}

function vp3_browser_execution_execute_v2180(PDO $pdo,array $user,string $namespace,array $ticket,array $candidate): array
{
    if((string)$ticket['status']!=='confirmed')throw new RuntimeException('Confirm this Browser action before execution.');
    if(strtotime((string)$ticket['expires_at'])<time())throw new RuntimeException('This Browser action expired. Prepare it again from the current page.');

    $owns=!$pdo->inTransaction();
    if($owns)$pdo->beginTransaction();
    try{
        $action=(string)$ticket['action_key'];
        $result=[];
        $handoff=[];
        $status='completed';
        $verification='not_required';

        if(in_array($action,['follow_source','unfollow_source'],true)){
            $follow=$action==='follow_source';
            $result=vp3_browser_source_follow_source_v2050(
                $pdo,(int)$user['id'],(string)$ticket['target_id'],$follow,'','',''
            );
            $following=!empty($result['following']);
            if($following!==$follow)throw new RuntimeException('VP3 could not verify the requested Source follow state.');
            $verification='verified';
        }else{
            $handoff=vp3_browser_execution_handoff_v2180($candidate);
            if(in_array($action,['draft_task','draft_knowledge','share_team'],true)){
                $status='awaiting_user';
                $verification='waiting';
            }
        }

        $verifiedAt=$verification==='verified'?gmdate('Y-m-d H:i:s'):null;
        $pdo->prepare("UPDATE browser_execution_tickets_v2180
          SET status=?,verification_state=?,attempt_count=attempt_count+1,executed_at=COALESCE(executed_at,UTC_TIMESTAMP()),verified_at=?,last_error_code='',updated_at=UTC_TIMESTAMP()
          WHERE id=?")->execute([$status,$verification,$verifiedAt,(int)$ticket['id']]);
        $fresh=vp3_browser_execution_ticket_v2180($pdo,(int)$user['id'],$namespace,(string)$ticket['public_id'])?:$ticket;
        vp3_browser_execution_event_v2180($pdo,$fresh,'executed',$action);
        if($verification==='verified')vp3_browser_execution_event_v2180($pdo,$fresh,'verified','server_state');
        if($owns)$pdo->commit();
        return [
            'ticket'=>vp3_browser_execution_public_ticket_v2180($fresh),
            'result'=>$result,
            'handoff'=>$handoff,
        ];
    }catch(Throwable $e){
        if($owns&&$pdo->inTransaction())$pdo->rollBack();
        try{
            $pdo->prepare("UPDATE browser_execution_tickets_v2180
              SET status='failed',verification_state='failed',attempt_count=attempt_count+1,failed_at=UTC_TIMESTAMP(),last_error_code='execution_failed',updated_at=UTC_TIMESTAMP()
              WHERE id=?")->execute([(int)$ticket['id']]);
            $fresh=vp3_browser_execution_ticket_v2180($pdo,(int)$user['id'],$namespace,(string)$ticket['public_id']);
            if($fresh)vp3_browser_execution_event_v2180($pdo,$fresh,'failed','execution_failed');
        }catch(Throwable $ignored){}
        throw $e;
    }
}
