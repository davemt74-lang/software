<?php
declare(strict_types=1);

/**
 * VP3 Cognitive Memory & Cross-Time Continuity v5.70 — Phase 11B.8.
 *
 * Memory is reference-first. Persistent rows store object references, hashes,
 * timestamps, lifecycle classes and outcome codes — never copied domain text.
 * Current canonical context is re-resolved and re-authorized at read time.
 */
const VP3_COGNITIVE_MEMORY_V570='vp3-cognitive-memory-v570-20260919';
const VP3_COGNITIVE_MEMORY_SYNC_LIMIT_V570=160;
const VP3_COGNITIVE_MEMORY_CONTEXT_LIMIT_V570=5;
const VP3_COGNITIVE_MEMORY_FEED_LIMIT_V570=2;

function vp3_cognitive_memory_schema_ready_v570(?PDO $pdo=null): bool
{
    $pdo??=db();
    return (bool)$pdo
        && table_exists('cognitive_memory_threads_v570')
        && table_exists('cognitive_memory_occurrences_v570');
}

function vp3_cognitive_memory_ensure_schema_v570(?PDO $pdo=null): void
{
    $pdo??=db();
    if(!$pdo)throw new RuntimeException('Database connection is unavailable.');
    if(vp3_cognitive_memory_schema_ready_v570($pdo))return;
    if(!table_exists('users'))throw new RuntimeException('VP3 users must exist before Cognitive Memory.');

    $pdo->exec("CREATE TABLE IF NOT EXISTS cognitive_memory_threads_v570 (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
      public_id CHAR(36) NOT NULL,
      owner_user_id INT UNSIGNED NOT NULL,
      agent_namespace VARCHAR(80) NOT NULL DEFAULT 'system',
      thread_key CHAR(64) NOT NULL,
      thread_kind VARCHAR(32) NOT NULL,
      signature_hash CHAR(64) NOT NULL DEFAULT '',
      source_kind VARCHAR(80) NOT NULL DEFAULT '',
      object_type VARCHAR(80) NOT NULL DEFAULT '',
      status VARCHAR(24) NOT NULL DEFAULT 'active',
      occurrence_count INT UNSIGNED NOT NULL DEFAULT 0,
      distinct_object_count INT UNSIGNED NOT NULL DEFAULT 0,
      reopened_count INT UNSIGNED NOT NULL DEFAULT 0,
      successful_count INT UNSIGNED NOT NULL DEFAULT 0,
      resolved_count INT UNSIGNED NOT NULL DEFAULT 0,
      unsuccessful_count INT UNSIGNED NOT NULL DEFAULT 0,
      first_seen_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      last_seen_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      last_event_kind VARCHAR(40) NOT NULL DEFAULT '',
      last_object_type VARCHAR(80) NOT NULL DEFAULT '',
      last_object_id VARCHAR(190) NOT NULL DEFAULT '',
      last_object_scope VARCHAR(40) NOT NULL DEFAULT 'personal',
      updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      PRIMARY KEY (id),
      UNIQUE KEY uq_cognitive_memory_thread_public_v570 (public_id),
      UNIQUE KEY uq_cognitive_memory_thread_key_v570 (owner_user_id,agent_namespace,thread_key),
      INDEX idx_cognitive_memory_thread_rank_v570 (owner_user_id,agent_namespace,status,occurrence_count,last_seen_at),
      CONSTRAINT fk_cognitive_memory_thread_owner_v570 FOREIGN KEY (owner_user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS cognitive_memory_occurrences_v570 (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
      thread_id BIGINT UNSIGNED NOT NULL,
      owner_user_id INT UNSIGNED NOT NULL,
      occurrence_key CHAR(64) NOT NULL,
      event_kind VARCHAR(40) NOT NULL,
      source_kind VARCHAR(80) NOT NULL DEFAULT '',
      object_type VARCHAR(80) NOT NULL,
      object_id VARCHAR(190) NOT NULL,
      object_scope VARCHAR(40) NOT NULL DEFAULT 'personal',
      item_key VARCHAR(190) NOT NULL DEFAULT '',
      item_fingerprint CHAR(64) NOT NULL DEFAULT '',
      outcome_code VARCHAR(32) NOT NULL DEFAULT '',
      evidence_ref_type VARCHAR(80) NOT NULL DEFAULT '',
      evidence_ref_id VARCHAR(190) NOT NULL DEFAULT '',
      occurred_at DATETIME NOT NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      PRIMARY KEY (id),
      UNIQUE KEY uq_cognitive_memory_occurrence_v570 (thread_id,occurrence_key),
      INDEX idx_cognitive_memory_occurrence_recent_v570 (thread_id,occurred_at,id),
      INDEX idx_cognitive_memory_occurrence_object_v570 (owner_user_id,object_type,object_id,occurred_at),
      CONSTRAINT fk_cognitive_memory_occurrence_thread_v570 FOREIGN KEY (thread_id) REFERENCES cognitive_memory_threads_v570(id) ON DELETE CASCADE,
      CONSTRAINT fk_cognitive_memory_occurrence_owner_v570 FOREIGN KEY (owner_user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

function vp3_cognitive_memory_terms_v570(string $text): array
{
    $text=mb_strtolower(trim($text));if($text==='')return [];
    $parts=preg_split('/[^\p{L}\p{N}]+/u',$text,-1,PREG_SPLIT_NO_EMPTY)?:[];
    $stop=array_flip([
        'a','an','and','are','as','at','be','by','for','from','has','have','in','is','it','of','on','or','the','this','to','with',
        'now','today','tomorrow','yesterday','recent','recently','current','currently','upcoming','starts','started','active',
        'item','items','agent','vp3','needs','need','review','consider','suggested','suggestion'
    ]);
    $out=[];
    foreach($parts as $part){
        $part=mb_substr((string)$part,0,40);
        if(mb_strlen($part)<3||isset($stop[$part]))continue;
        $out[$part]=true;
        if(count($out)>=18)break;
    }
    return array_keys($out);
}

function vp3_cognitive_memory_signature_v570(array $candidate): string
{
    $terms=vp3_cognitive_memory_terms_v570((string)($candidate['reason']??''));
    if(count($terms)<2)return '';
    sort($terms,SORT_STRING);
    return hash('sha256',implode('|',$terms));
}

function vp3_cognitive_memory_candidate_ref_v570(array $candidate): ?array
{
    $request=is_array($candidate['card_request']??null)?$candidate['card_request']:[];
    $ref=is_array($request['object_ref']??null)?$request['object_ref']:[];
    try{return vp3_cognitive_validate_object_ref_v500($ref,true);}catch(Throwable $e){return null;}
}

function vp3_cognitive_memory_thread_key_v570(string $kind,string $source,string $type,string $id='',string $scope='personal',string $signature=''): string
{
    return hash('sha256',vp3_cognitive_json_v500([$kind,$source,$type,$id,$scope,$signature]));
}

function vp3_cognitive_memory_thread_v570(
    PDO $pdo,int $uid,string $namespace,string $kind,string $key,string $source,string $objectType,string $signature=''
): array {
    $pdo->prepare("INSERT INTO cognitive_memory_threads_v570
      (public_id,owner_user_id,agent_namespace,thread_key,thread_kind,signature_hash,source_kind,object_type,status,occurrence_count,distinct_object_count,first_seen_at,last_seen_at)
      VALUES (?,?,?,?,?,?,?,?,'active',0,0,UTC_TIMESTAMP(),UTC_TIMESTAMP())
      ON DUPLICATE KEY UPDATE last_seen_at=GREATEST(last_seen_at,UTC_TIMESTAMP())")
      ->execute([vp3_cognitive_uuid_v500(),$uid,$namespace,$key,$kind,$signature,$source,$objectType]);
    $stmt=$pdo->prepare("SELECT * FROM cognitive_memory_threads_v570 WHERE owner_user_id=? AND agent_namespace=? AND thread_key=? LIMIT 1");
    $stmt->execute([$uid,$namespace,$key]);
    return $stmt->fetch()?:[];
}

function vp3_cognitive_memory_refresh_thread_v570(PDO $pdo,int $threadId): void
{
    $stats=$pdo->prepare("SELECT COUNT(*) occurrence_count,
      COUNT(DISTINCT CONCAT(object_type,CHAR(31),object_id,CHAR(31),object_scope)) distinct_object_count,
      SUM(event_kind='reopened') reopened_count,
      SUM(outcome_code='successful') successful_count,
      SUM(outcome_code='resolved') resolved_count,
      SUM(outcome_code IN ('unsuccessful','ignored')) unsuccessful_count,
      MIN(occurred_at) first_seen_at,MAX(occurred_at) last_seen_at
      FROM cognitive_memory_occurrences_v570 WHERE thread_id=?");
    $stats->execute([$threadId]);$row=$stats->fetch()?:[];
    $last=$pdo->prepare("SELECT event_kind,object_type,object_id,object_scope,outcome_code FROM cognitive_memory_occurrences_v570
      WHERE thread_id=? ORDER BY occurred_at DESC,id DESC LIMIT 1");
    $last->execute([$threadId]);$tail=$last->fetch()?:[];
    $status=in_array((string)($tail['outcome_code']??''),['successful','resolved'],true)?'resolved':'active';
    $pdo->prepare("UPDATE cognitive_memory_threads_v570 SET
      occurrence_count=?,distinct_object_count=?,reopened_count=?,successful_count=?,resolved_count=?,unsuccessful_count=?,
      first_seen_at=COALESCE(?,first_seen_at),last_seen_at=COALESCE(?,last_seen_at),last_event_kind=?,
      last_object_type=?,last_object_id=?,last_object_scope=?,status=?,updated_at=UTC_TIMESTAMP()
      WHERE id=?")
      ->execute([
          (int)($row['occurrence_count']??0),(int)($row['distinct_object_count']??0),(int)($row['reopened_count']??0),
          (int)($row['successful_count']??0),(int)($row['resolved_count']??0),(int)($row['unsuccessful_count']??0),
          $row['first_seen_at']??null,$row['last_seen_at']??null,(string)($tail['event_kind']??''),
          (string)($tail['object_type']??''),(string)($tail['object_id']??''),(string)($tail['object_scope']??'personal'),
          $status,$threadId
      ]);
}

function vp3_cognitive_memory_occurrence_v570(
    PDO $pdo,array $thread,int $uid,string $eventKind,string $source,array $ref,string $occurredAt,
    string $occurrenceKey,string $itemKey='',string $fingerprint='',string $outcome='',string $evidenceType='',string $evidenceId=''
): bool {
    if(!$thread||!isset($thread['id']))return false;
    $stmt=$pdo->prepare("INSERT IGNORE INTO cognitive_memory_occurrences_v570
      (thread_id,owner_user_id,occurrence_key,event_kind,source_kind,object_type,object_id,object_scope,item_key,item_fingerprint,outcome_code,evidence_ref_type,evidence_ref_id,occurred_at,created_at)
      VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,UTC_TIMESTAMP())");
    $stmt->execute([
        (int)$thread['id'],$uid,$occurrenceKey,vp3_cognitive_id_v500($eventKind,40)?:'observed',
        vp3_cognitive_id_v500($source,80),(string)$ref['type'],(string)$ref['id'],(string)$ref['scope'],
        mb_strimwidth(trim($itemKey),0,190,''),preg_match('/^[a-f0-9]{64}$/',$fingerprint)?$fingerprint:'',
        vp3_cognitive_id_v500($outcome,32),vp3_cognitive_id_v500($evidenceType,80),mb_strimwidth(trim($evidenceId),0,190,''),
        date('Y-m-d H:i:s',strtotime($occurredAt)?:time())
    ]);
    if($stmt->rowCount()<1)return false;
    vp3_cognitive_memory_refresh_thread_v570($pdo,(int)$thread['id']);
    return true;
}

function vp3_cognitive_memory_observe_candidates_v570(PDO $pdo,array $user,string $namespace,array $candidates): void
{
    if(!vp3_cognitive_memory_schema_ready_v570($pdo))return;
    $uid=(int)($user['id']??0);if($uid<1)return;
    foreach(array_slice($candidates,0,VP3_COGNITIVE_MEMORY_SYNC_LIMIT_V570) as $candidate){
        if(!is_array($candidate)||(string)($candidate['source']??'')==='cognitive_memory')continue;
        $ref=vp3_cognitive_memory_candidate_ref_v570($candidate);if(!$ref)continue;
        if(!vp3_cognitive_authorize_ref_v500($pdo,$user,$namespace,$ref,'read'))continue;
        $source=vp3_cognitive_id_v500($candidate['source']??'',80);if($source==='')$source='cognitive';
        $itemKey=mb_strimwidth(trim((string)($candidate['key']??'')),0,190,'');
        $fingerprint=strtolower(trim((string)($candidate['fingerprint']??'')));
        if($itemKey===''||!preg_match('/^[a-f0-9]{64}$/',$fingerprint))continue;
        $occurred=(string)($candidate['updated_at']??gmdate('Y-m-d H:i:s'));

        $exactKey=vp3_cognitive_memory_thread_key_v570('object_continuity','',(string)$ref['type'],(string)$ref['id'],(string)$ref['scope']);
        $exact=vp3_cognitive_memory_thread_v570($pdo,$uid,$namespace,'object_continuity',$exactKey,$source,(string)$ref['type']);
        $existing=$pdo->prepare("SELECT item_fingerprint FROM cognitive_memory_occurrences_v570 WHERE thread_id=? AND item_key=? ORDER BY occurred_at DESC,id DESC LIMIT 1");
        $existing->execute([(int)$exact['id'],$itemKey]);$previous=(string)($existing->fetchColumn()?:'');
        $changed=$previous!==''&&!hash_equals($previous,$fingerprint);
        $event=$changed?((string)($exact['status']??'')==='resolved'?'reopened':'changed'):'observed';
        $occurrenceKey=hash('sha256',vp3_cognitive_json_v500(['candidate',$itemKey,$fingerprint]));
        vp3_cognitive_memory_occurrence_v570($pdo,$exact,$uid,$event,$source,$ref,$occurred,$occurrenceKey,$itemKey,$fingerprint);

        $signature=vp3_cognitive_memory_signature_v570($candidate);
        if($signature!==''){
            $patternKey=vp3_cognitive_memory_thread_key_v570('recurring_pattern',$source,(string)$ref['type'],'','personal',$signature);
            $pattern=vp3_cognitive_memory_thread_v570($pdo,$uid,$namespace,'recurring_pattern',$patternKey,$source,(string)$ref['type'],$signature);
            $patternOccurrence=hash('sha256',vp3_cognitive_json_v500(['pattern',$signature,$ref['type'],$ref['id'],$fingerprint]));
            vp3_cognitive_memory_occurrence_v570($pdo,$pattern,$uid,$event,$source,$ref,$occurred,$patternOccurrence,$itemKey,$fingerprint);
        }
    }
}

function vp3_cognitive_memory_sync_outcomes_v570(PDO $pdo,array $user,string $namespace): void
{
    if(!table_exists('cognitive_outcomes_v540'))return;
    $uid=(int)$user['id'];$limit=VP3_COGNITIVE_MEMORY_SYNC_LIMIT_V570;
    $stmt=$pdo->prepare("SELECT * FROM cognitive_outcomes_v540 WHERE owner_user_id=? AND agent_namespace=? ORDER BY id DESC LIMIT {$limit}");
    $stmt->execute([$uid,$namespace]);
    foreach($stmt->fetchAll()?:[] as $row){
        $type=vp3_cognitive_id_v500($row['object_type']??'',80);$id=trim((string)($row['object_id']??''));
        if($type===''||$id==='')continue;
        try{$ref=vp3_cognitive_object_ref_v500($type,$id,'personal');}catch(Throwable $e){continue;}
        if(!vp3_cognitive_authorize_ref_v500($pdo,$user,$namespace,$ref,'read'))continue;
        $source=vp3_cognitive_id_v500($row['source_kind']??'',80)?:'cognitive_outcome';
        $key=vp3_cognitive_memory_thread_key_v570('object_continuity','',$type,$id,'personal');
        $thread=vp3_cognitive_memory_thread_v570($pdo,$uid,$namespace,'object_continuity',$key,$source,$type);
        $occurrence=hash('sha256',vp3_cognitive_json_v500(['outcome',(string)$row['outcome_key']]));
        vp3_cognitive_memory_occurrence_v570(
            $pdo,$thread,$uid,'outcome',$source,$ref,(string)$row['created_at'],$occurrence,
            (string)$row['item_key'],(string)$row['item_fingerprint'],(string)$row['outcome_code'],
            (string)$row['evidence_kind'],(string)$row['evidence_ref']
        );
    }
}

function vp3_cognitive_memory_sync_orchestration_v570(PDO $pdo,array $user,string $namespace): void
{
    if(!table_exists('cognitive_plan_step_events_v560')||!table_exists('cognitive_plan_runs_v560')||!table_exists('cognitive_plans_v550'))return;
    $uid=(int)$user['id'];$limit=VP3_COGNITIVE_MEMORY_SYNC_LIMIT_V570;
    $stmt=$pdo->prepare("SELECT e.id event_id,e.event_type,e.created_at,r.public_id run_public_id,
      p.object_type,p.object_id,p.object_scope
      FROM cognitive_plan_step_events_v560 e
      JOIN cognitive_plan_runs_v560 r ON r.id=e.run_id
      JOIN cognitive_plans_v550 p ON p.id=r.plan_id
      WHERE e.owner_user_id=? AND r.agent_namespace=?
        AND e.event_type IN ('run_materialized','run_closed','replan_required','step_superseded','step_cancelled','verification_passed','verification_failed')
      ORDER BY e.id DESC LIMIT {$limit}");
    $stmt->execute([$uid,$namespace]);
    foreach($stmt->fetchAll()?:[] as $row){
        $type=vp3_cognitive_id_v500($row['object_type']??'',80);$id=trim((string)($row['object_id']??''));
        if($type===''||$id==='')continue;
        try{$ref=vp3_cognitive_object_ref_v500($type,$id,(string)($row['object_scope']??'personal'));}catch(Throwable $e){continue;}
        if(!vp3_cognitive_authorize_ref_v500($pdo,$user,$namespace,$ref,'read'))continue;
        $source='cognitive_orchestration';
        $key=vp3_cognitive_memory_thread_key_v570('object_continuity','',$type,$id,(string)$ref['scope']);
        $thread=vp3_cognitive_memory_thread_v570($pdo,$uid,$namespace,'object_continuity',$key,$source,$type);
        $occurrence=hash('sha256',vp3_cognitive_json_v500(['orchestration',(int)$row['event_id']]));
        vp3_cognitive_memory_occurrence_v570(
            $pdo,$thread,$uid,(string)$row['event_type'],$source,$ref,(string)$row['created_at'],$occurrence,
            'orchestration:'.(string)$row['run_public_id'],'','','orchestration_run',(string)$row['run_public_id']
        );
    }
}

function vp3_cognitive_memory_sync_v570(PDO $pdo,array $user,string $namespace,array $candidates=[]): void
{
    if(!vp3_cognitive_memory_schema_ready_v570($pdo))return;
    $namespace=vp3_cognitive_validate_namespace_v500($pdo,$user,$namespace);
    if($candidates)vp3_cognitive_memory_observe_candidates_v570($pdo,$user,$namespace,$candidates);
    vp3_cognitive_memory_sync_outcomes_v570($pdo,$user,$namespace);
    vp3_cognitive_memory_sync_orchestration_v570($pdo,$user,$namespace);
}

function vp3_cognitive_memory_thread_row_v570(PDO $pdo,array $user,string $namespace,string|int $id): ?array
{
    if(!vp3_cognitive_memory_schema_ready_v570($pdo))return null;
    $namespace=vp3_cognitive_validate_namespace_v500($pdo,$user,$namespace);
    $uid=(int)($user['id']??0);if($uid<1)return null;
    $value=trim((string)$id);if($value==='')return null;
    if(ctype_digit($value)){
        $stmt=$pdo->prepare("SELECT * FROM cognitive_memory_threads_v570 WHERE id=? AND owner_user_id=? AND agent_namespace=? LIMIT 1");
        $stmt->execute([(int)$value,$uid,$namespace]);
    }else{
        $stmt=$pdo->prepare("SELECT * FROM cognitive_memory_threads_v570 WHERE public_id=? AND owner_user_id=? AND agent_namespace=? LIMIT 1");
        $stmt->execute([$value,$uid,$namespace]);
    }
    return $stmt->fetch()?:null;
}

function vp3_cognitive_memory_occurrences_for_thread_v570(PDO $pdo,int $threadId,int $limit=20): array
{
    $limit=max(1,min(50,$limit));
    $stmt=$pdo->prepare("SELECT * FROM cognitive_memory_occurrences_v570 WHERE thread_id=? ORDER BY occurred_at DESC,id DESC LIMIT {$limit}");
    $stmt->execute([$threadId]);
    return $stmt->fetchAll()?:[];
}

function vp3_cognitive_memory_permission_v570(PDO $pdo,array $user,string $namespace,array $ref,string $operation='read'): bool
{
    if((string)($ref['type']??'')!=='memory_thread'||$operation!=='read')return false;
    $thread=vp3_cognitive_memory_thread_row_v570($pdo,$user,$namespace,(string)($ref['id']??''));
    return is_array($thread);
}

function vp3_cognitive_memory_resolved_timeline_v570(PDO $pdo,array $user,string $namespace,array $thread): array
{
    $timeline=[];
    foreach(vp3_cognitive_memory_occurrences_for_thread_v570($pdo,(int)$thread['id'],12) as $row){
        try{
            $ref=vp3_cognitive_object_ref_v500((string)$row['object_type'],(string)$row['object_id'],(string)$row['object_scope']);
            if(!vp3_cognitive_authorize_ref_v500($pdo,$user,$namespace,$ref,'read'))continue;
            $entry=[
                'occurred_at'=>(string)$row['occurred_at'],
                'event_kind'=>(string)$row['event_kind'],
                'outcome_code'=>(string)$row['outcome_code'],
                'object_ref'=>$ref,
                'evidence_ref'=>[
                    'type'=>(string)$row['evidence_ref_type'],
                    'id'=>(string)$row['evidence_ref_id'],
                ],
            ];
            if(count($timeline)<VP3_COGNITIVE_MEMORY_CONTEXT_LIMIT_V570){
                try{
                    $packet=vp3_cognitive_context_for_ref_v500($pdo,$user,$namespace,$ref,['memory_continuity'=>true]);
                    $entry['current_context']=$packet['context']??[];
                }catch(Throwable $e){}
            }
            $timeline[]=$entry;
            if(count($timeline)>=12)break;
        }catch(Throwable $e){}
    }
    return $timeline;
}

function vp3_cognitive_memory_context_v570(PDO $pdo,array $user,string $namespace,array $ref,array $options=[]): array
{
    $thread=vp3_cognitive_memory_thread_row_v570($pdo,$user,$namespace,(string)($ref['id']??''));
    if(!$thread)throw new RuntimeException('Cognitive memory thread not found.');
    return [
        'memory_thread'=>[
            'id'=>(string)$thread['public_id'],
            'kind'=>(string)$thread['thread_kind'],
            'status'=>(string)$thread['status'],
            'occurrence_count'=>(int)$thread['occurrence_count'],
            'distinct_object_count'=>(int)$thread['distinct_object_count'],
            'reopened_count'=>(int)$thread['reopened_count'],
            'successful_count'=>(int)$thread['successful_count'],
            'resolved_count'=>(int)$thread['resolved_count'],
            'unsuccessful_count'=>(int)$thread['unsuccessful_count'],
            'first_seen_at'=>(string)$thread['first_seen_at'],
            'last_seen_at'=>(string)$thread['last_seen_at'],
            'last_event_kind'=>(string)$thread['last_event_kind'],
        ],
        'timeline'=>vp3_cognitive_memory_resolved_timeline_v570($pdo,$user,$namespace,$thread),
        'storage_boundary'=>'reference_only',
        'authority'=>'memory_context_only',
    ];
}

function vp3_cognitive_memory_card_v570(PDO $pdo,array $user,string $namespace,array $ref,string $mode): array
{
    $thread=vp3_cognitive_memory_thread_row_v570($pdo,$user,$namespace,(string)($ref['id']??''));
    if(!$thread)throw new RuntimeException('Cognitive memory thread not found.');
    $kind=(string)$thread['thread_kind'];
    $occurrences=(int)$thread['occurrence_count'];$objects=(int)$thread['distinct_object_count'];$reopens=(int)$thread['reopened_count'];
    $summary=$kind==='recurring_pattern'
        ? 'VP3 has seen this pattern '.$occurrences.' times across '.$objects.' authorized object'.($objects===1?'':'s').'.'
        : 'VP3 has continuity for this item across '.$occurrences.' recorded state change'.($occurrences===1?'':'s').'.';
    if($reopens>0)$summary.=' It has reopened '.$reopens.' time'.($reopens===1?'':'s').'.';
    $actions=[[
        'type'=>'prompt','label'=>'Review continuity',
        'prompt'=>'Review cognitive memory thread '.(string)$thread['public_id'].' with me. Re-resolve the authorized source objects, explain the timeline, what changed over time, repeated outcomes, reopenings, and any unresolved pattern. Do not infer missing historical content and do not execute anything.'
    ]];
    return [
        'title'=>$kind==='recurring_pattern'?'Recurring pattern':'Cross-time continuity',
        'subtitle'=>'Cognitive memory · reference-based',
        'status'=>(string)$thread['status'],
        'summary'=>$summary,
        'timestamp'=>(string)$thread['last_seen_at'],
        'badges'=>array_values(array_filter([
            $reopens>0?$reopens.' reopened':null,
            (int)$thread['unsuccessful_count']>0?(int)$thread['unsuccessful_count'].' unsuccessful':null,
        ])),
        'facts'=>[
            ['label'=>'Occurrences','value'=>(string)$occurrences],
            ['label'=>'Objects','value'=>(string)$objects],
            ['label'=>'First seen','value'=>(string)$thread['first_seen_at']],
            ['label'=>'Last seen','value'=>(string)$thread['last_seen_at']],
        ],
        'sections'=>[
            ['label'=>'Outcome history','items'=>[
                'Successful: '.(int)$thread['successful_count'],
                'Resolved: '.(int)$thread['resolved_count'],
                'Unsuccessful/ignored: '.(int)$thread['unsuccessful_count'],
            ]],
            ['label'=>'Memory boundary','text'=>'VP3 stores references, hashes, lifecycle classes, and timestamps here. Current source content is re-authorized and resolved only when needed.'],
        ],
        'actions'=>$actions,
    ];
}

function vp3_cognitive_memory_feed_candidates_v570(PDO $pdo,array $user,string $namespace): array
{
    if(!vp3_cognitive_memory_schema_ready_v570($pdo))return [];
    $stmt=$pdo->prepare("SELECT * FROM cognitive_memory_threads_v570
      WHERE owner_user_id=? AND agent_namespace=? AND status='active'
        AND ((thread_kind='recurring_pattern' AND occurrence_count>=3 AND distinct_object_count>=2)
          OR (thread_kind='object_continuity' AND reopened_count>=1))
      ORDER BY (reopened_count*3+unsuccessful_count*2+distinct_object_count) DESC,last_seen_at DESC
      LIMIT ".VP3_COGNITIVE_MEMORY_FEED_LIMIT_V570);
    $stmt->execute([(int)$user['id'],$namespace]);$out=[];
    foreach($stmt->fetchAll()?:[] as $row){
        try{
            $request=vp3_cognitive_feed_request_v530('memory_thread',(string)$row['public_id'],'personal','standard');
            $reason=(string)$row['thread_kind']==='recurring_pattern'
                ? 'A recurring cross-time pattern has appeared across multiple authorized objects.'
                : 'This item has reopened after an earlier state change or resolution.';
            $out[]=vp3_cognitive_feed_candidate_v530(
                'memory:'.(string)$row['public_id'],'priorities',74,$reason,$request,'cognitive_memory',(string)$row['last_seen_at'],[
                    'occurrences'=>$row['occurrence_count'],'objects'=>$row['distinct_object_count'],
                    'reopened'=>$row['reopened_count'],'unsuccessful'=>$row['unsuccessful_count'],
                ],false
            );
        }catch(Throwable $e){}
    }
    return $out;
}

function vp3_cognitive_memory_register_v570(): void
{
    $registry=vp3_cognitive_registry_storage_v500();
    if(isset($registry['modules']['cognitive_memory']))return;
    vp3_cognitive_register_module_v500([
        'module'=>'cognitive_memory',
        'version'=>'cognitive-memory-v570',
        'objects'=>['memory_thread'],
        'events'=>[],
        'permission_resolver'=>'vp3_cognitive_memory_permission_v570',
        'context_provider'=>'vp3_cognitive_memory_context_v570',
        'relationship_provider'=>null,
        'cards'=>['memory_thread'=>'vp3_cognitive_memory_card_v570'],
        'tools'=>[],
        'freshness_policy'=>['default_seconds'=>300],
        'sensitivity_policy'=>['reference_only'=>true,'resolve_current_context_on_read'=>true],
        'surfaces'=>['memory','brief','away_digest','ask_user','chat_response'],
        'voice_safe'=>false,
    ]);
}

vp3_cognitive_memory_register_v570();
