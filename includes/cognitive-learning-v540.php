<?php
declare(strict_types=1);

/**
 * VP3 Cognitive Outcomes & Learning v5.40 — Phase 11B.5.
 *
 * This layer learns bounded relevance from references, lifecycle signals and
 * canonical outcomes. It never grants authority, executes tools, mutates the
 * canonical object, or stores copied titles/messages/transcripts/content.
 */
const VP3_COGNITIVE_LEARNING_V540='vp3-cognitive-learning-v540-20260918';
const VP3_COGNITIVE_LEARNING_SURFACE_COOLDOWN_V540=21600;
const VP3_COGNITIVE_LEARNING_RECONCILE_LIMIT_V540=40;
const VP3_COGNITIVE_LEARNING_STALE_DAYS_V540=7;

function vp3_cognitive_learning_schema_ready_v540(?PDO $pdo=null): bool
{
    $pdo??=db();
    return (bool)$pdo
        && table_exists('cognitive_feedback_events_v540')
        && table_exists('cognitive_outcomes_v540')
        && table_exists('cognitive_learning_profiles_v540')
        && table_exists('cognitive_item_lifecycle_v540');
}

function vp3_cognitive_learning_ensure_schema_v540(?PDO $pdo=null): void
{
    $pdo??=db();
    if(!$pdo)throw new RuntimeException('Database connection is unavailable.');
    if(vp3_cognitive_learning_schema_ready_v540($pdo))return;
    if(!table_exists('users'))throw new RuntimeException('VP3 users must exist before Cognitive Outcomes & Learning.');

    $pdo->exec("CREATE TABLE IF NOT EXISTS cognitive_feedback_events_v540 (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
      owner_user_id INT UNSIGNED NOT NULL,
      agent_namespace VARCHAR(80) NOT NULL DEFAULT 'system',
      item_key VARCHAR(190) NOT NULL,
      item_fingerprint CHAR(64) NOT NULL DEFAULT '',
      source_kind VARCHAR(80) NOT NULL DEFAULT '',
      section_key VARCHAR(32) NOT NULL DEFAULT '',
      object_type VARCHAR(80) NOT NULL DEFAULT '',
      object_id VARCHAR(190) NOT NULL DEFAULT '',
      event_type VARCHAR(40) NOT NULL,
      event_key CHAR(64) NOT NULL,
      context_json LONGTEXT NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      PRIMARY KEY (id),
      UNIQUE KEY uq_cognitive_feedback_event_v540 (event_key),
      INDEX idx_cognitive_feedback_owner_v540 (owner_user_id,agent_namespace,created_at),
      INDEX idx_cognitive_feedback_item_v540 (owner_user_id,agent_namespace,item_key),
      CONSTRAINT fk_cognitive_feedback_owner_v540 FOREIGN KEY (owner_user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS cognitive_outcomes_v540 (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
      owner_user_id INT UNSIGNED NOT NULL,
      agent_namespace VARCHAR(80) NOT NULL DEFAULT 'system',
      item_key VARCHAR(190) NOT NULL,
      item_fingerprint CHAR(64) NOT NULL DEFAULT '',
      source_kind VARCHAR(80) NOT NULL DEFAULT '',
      object_type VARCHAR(80) NOT NULL DEFAULT '',
      object_id VARCHAR(190) NOT NULL DEFAULT '',
      outcome_code VARCHAR(32) NOT NULL,
      evidence_kind VARCHAR(64) NOT NULL DEFAULT '',
      evidence_ref VARCHAR(190) NOT NULL DEFAULT '',
      confidence DECIMAL(5,4) NOT NULL DEFAULT 1.0000,
      outcome_key CHAR(64) NOT NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      PRIMARY KEY (id),
      UNIQUE KEY uq_cognitive_outcome_v540 (outcome_key),
      INDEX idx_cognitive_outcome_owner_v540 (owner_user_id,agent_namespace,created_at),
      INDEX idx_cognitive_outcome_object_v540 (owner_user_id,object_type,object_id),
      CONSTRAINT fk_cognitive_outcome_owner_v540 FOREIGN KEY (owner_user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS cognitive_learning_profiles_v540 (
      owner_user_id INT UNSIGNED NOT NULL,
      agent_namespace VARCHAR(80) NOT NULL DEFAULT 'system',
      dimension_type VARCHAR(32) NOT NULL,
      dimension_key VARCHAR(120) NOT NULL,
      exposures INT UNSIGNED NOT NULL DEFAULT 0,
      engagements INT UNSIGNED NOT NULL DEFAULT 0,
      actions_taken INT UNSIGNED NOT NULL DEFAULT 0,
      presentation_hides INT UNSIGNED NOT NULL DEFAULT 0,
      successful INT UNSIGNED NOT NULL DEFAULT 0,
      resolved INT UNSIGNED NOT NULL DEFAULT 0,
      unsuccessful INT UNSIGNED NOT NULL DEFAULT 0,
      ignored INT UNSIGNED NOT NULL DEFAULT 0,
      relevance_factor DECIMAL(6,4) NOT NULL DEFAULT 1.0000,
      updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      PRIMARY KEY (owner_user_id,agent_namespace,dimension_type,dimension_key),
      INDEX idx_cognitive_learning_factor_v540 (owner_user_id,agent_namespace,relevance_factor),
      CONSTRAINT fk_cognitive_learning_profile_owner_v540 FOREIGN KEY (owner_user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS cognitive_item_lifecycle_v540 (
      owner_user_id INT UNSIGNED NOT NULL,
      agent_namespace VARCHAR(80) NOT NULL DEFAULT 'system',
      item_key VARCHAR(190) NOT NULL,
      item_fingerprint CHAR(64) NOT NULL DEFAULT '',
      source_kind VARCHAR(80) NOT NULL DEFAULT '',
      section_key VARCHAR(32) NOT NULL DEFAULT '',
      object_type VARCHAR(80) NOT NULL DEFAULT '',
      object_id VARCHAR(190) NOT NULL DEFAULT '',
      object_scope VARCHAR(40) NOT NULL DEFAULT 'personal',
      lifecycle_state VARCHAR(32) NOT NULL DEFAULT 'active',
      current_outcome VARCHAR(32) NOT NULL DEFAULT '',
      surface_count INT UNSIGNED NOT NULL DEFAULT 0,
      engagement_count INT UNSIGNED NOT NULL DEFAULT 0,
      action_count INT UNSIGNED NOT NULL DEFAULT 0,
      hidden_count INT UNSIGNED NOT NULL DEFAULT 0,
      reopened_count INT UNSIGNED NOT NULL DEFAULT 0,
      first_seen_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      last_seen_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      last_surfaced_at DATETIME NULL,
      last_engaged_at DATETIME NULL,
      last_outcome_at DATETIME NULL,
      updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      PRIMARY KEY (owner_user_id,agent_namespace,item_key),
      INDEX idx_cognitive_lifecycle_state_v540 (owner_user_id,agent_namespace,lifecycle_state,last_seen_at),
      INDEX idx_cognitive_lifecycle_object_v540 (owner_user_id,object_type,object_id),
      CONSTRAINT fk_cognitive_lifecycle_owner_v540 FOREIGN KEY (owner_user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

function vp3_cognitive_learning_token_v540(mixed $value,int $limit=120): string
{
    return vp3_cognitive_id_v500((string)$value,$limit);
}

function vp3_cognitive_learning_ref_v540(array $candidate): array
{
    $request=is_array($candidate['card_request']??null)?$candidate['card_request']:[];
    $ref=is_array($request['object_ref']??null)?$request['object_ref']:[];
    return [
        'type'=>vp3_cognitive_learning_token_v540($ref['type']??'',80),
        'id'=>mb_strimwidth(trim((string)($ref['id']??'')),0,190,''),
        'scope'=>vp3_cognitive_learning_token_v540($ref['scope']??'personal',40)?:'personal',
    ];
}

function vp3_cognitive_learning_meta_v540(array $meta): array
{
    $allowed=['action_type','section','surface','trigger','status_from','status_to','evidence_kind','automatic'];
    $out=[];
    foreach($allowed as $key){
        if(!array_key_exists($key,$meta))continue;
        $value=$meta[$key];
        if(is_bool($value)){$out[$key]=$value;continue;}
        if(is_int($value)||is_float($value)){$out[$key]=$value;continue;}
        $out[$key]=vp3_cognitive_learning_token_v540($value,120);
    }
    return $out;
}

function vp3_cognitive_learning_dimensions_v540(array $candidate): array
{
    $ref=vp3_cognitive_learning_ref_v540($candidate);
    $dims=[];
    $source=vp3_cognitive_learning_token_v540($candidate['source']??'',80);
    $section=vp3_cognitive_learning_token_v540($candidate['section']??'',32);
    if($source!=='')$dims[]=['source',$source];
    if($ref['type']!=='')$dims[]=['object_type',$ref['type']];
    if($section!=='')$dims[]=['section',$section];
    return $dims;
}

function vp3_cognitive_learning_profile_recalculate_v540(PDO $pdo,int $uid,string $namespace,string $dimensionType,string $dimensionKey): float
{
    $stmt=$pdo->prepare("SELECT exposures,engagements,actions_taken,successful,resolved,unsuccessful,ignored
      FROM cognitive_learning_profiles_v540
      WHERE owner_user_id=? AND agent_namespace=? AND dimension_type=? AND dimension_key=?");
    $stmt->execute([$uid,$namespace,$dimensionType,$dimensionKey]);
    $row=$stmt->fetch()?:[];
    $exposures=max(0,(int)($row['exposures']??0));
    $engagements=max(0,(int)($row['engagements']??0));
    $actions=max(0,(int)($row['actions_taken']??0));
    $successful=max(0,(int)($row['successful']??0));
    $resolved=max(0,(int)($row['resolved']??0));
    $unsuccessful=max(0,(int)($row['unsuccessful']??0));
    $ignored=max(0,(int)($row['ignored']??0));

    $engagementRate=min(1.0,($engagements+$actions)/max(3,$exposures));
    $outcomeN=$successful+$resolved+$unsuccessful+$ignored;
    $outcomeScore=$outcomeN>0
        ? (($successful+($resolved*.4)-($unsuccessful*.75)-($ignored*.4))/$outcomeN)
        : 0.0;
    $noActionPenalty=($exposures>=4&&($engagements+$actions+$outcomeN)===0)
        ? min(.05,($exposures-3)*.01)
        : 0.0;
    $factor=1.0+min(.05,$engagementRate*.05)+max(-.06,min(.06,$outcomeScore*.06))-$noActionPenalty;
    $factor=max(.90,min(1.10,$factor));
    $pdo->prepare("UPDATE cognitive_learning_profiles_v540 SET relevance_factor=? WHERE owner_user_id=? AND agent_namespace=? AND dimension_type=? AND dimension_key=?")
        ->execute([round($factor,4),$uid,$namespace,$dimensionType,$dimensionKey]);
    return round($factor,4);
}

function vp3_cognitive_learning_profile_apply_v540(PDO $pdo,array $user,string $namespace,array $candidate,string $eventType): void
{
    $columns=[
        'shown'=>'exposures',
        'engaged'=>'engagements',
        'acted'=>'actions_taken',
        'hidden'=>'presentation_hides',
        'dismissed'=>'ignored',
        'outcome_successful'=>'successful',
        'outcome_resolved'=>'resolved',
        'outcome_unsuccessful'=>'unsuccessful',
        'outcome_ignored'=>'ignored',
    ];
    $column=$columns[$eventType]??'';
    if($column==='')return;
    $uid=(int)($user['id']??0);if($uid<1)return;
    foreach(vp3_cognitive_learning_dimensions_v540($candidate) as [$dimensionType,$dimensionKey]){
        $pdo->prepare("INSERT INTO cognitive_learning_profiles_v540
          (owner_user_id,agent_namespace,dimension_type,dimension_key,{$column})
          VALUES (?,?,?,?,1)
          ON DUPLICATE KEY UPDATE {$column}={$column}+1,updated_at=UTC_TIMESTAMP()")
          ->execute([$uid,$namespace,$dimensionType,$dimensionKey]);
        vp3_cognitive_learning_profile_recalculate_v540($pdo,$uid,$namespace,$dimensionType,$dimensionKey);
    }
}

function vp3_cognitive_learning_feedback_v540(
    PDO $pdo,array $user,string $namespace,array $candidate,string $eventType,array $meta=[],string $dedupe=''
): bool {
    if(!vp3_cognitive_learning_schema_ready_v540($pdo))return false;
    $allowed=['shown','engaged','acted','hidden','restored','dismissed','outcome_successful','outcome_resolved','outcome_unsuccessful','outcome_ignored'];
    if(!in_array($eventType,$allowed,true))throw new InvalidArgumentException('Unsupported cognitive feedback event.');
    $uid=(int)($user['id']??0);if($uid<1)return false;
    $itemKey=mb_strimwidth(trim((string)($candidate['key']??'')),0,190,'');
    $fingerprint=strtolower(trim((string)($candidate['fingerprint']??'')));
    if($itemKey===''||!preg_match('/^[a-f0-9]{64}$/',$fingerprint))return false;
    $source=vp3_cognitive_learning_token_v540($candidate['source']??'',80);
    $section=vp3_cognitive_learning_token_v540($candidate['section']??'',32);
    $ref=vp3_cognitive_learning_ref_v540($candidate);
    $safeMeta=vp3_cognitive_learning_meta_v540($meta);
    $dedupe=$dedupe!==''?$dedupe:bin2hex(random_bytes(12));
    $eventKey=hash('sha256',vp3_cognitive_json_v500([$uid,$namespace,$itemKey,$fingerprint,$eventType,$dedupe]));
    $stmt=$pdo->prepare("INSERT IGNORE INTO cognitive_feedback_events_v540
      (owner_user_id,agent_namespace,item_key,item_fingerprint,source_kind,section_key,object_type,object_id,event_type,event_key,context_json,created_at)
      VALUES (?,?,?,?,?,?,?,?,?,?,?,UTC_TIMESTAMP())");
    $stmt->execute([
        $uid,$namespace,$itemKey,$fingerprint,$source,$section,$ref['type'],$ref['id'],$eventType,$eventKey,
        vp3_cognitive_json_v500($safeMeta),
    ]);
    if($stmt->rowCount()<1)return false;

    $counterSql='';
    if($eventType==='shown')$counterSql=',surface_count=surface_count+1,last_surfaced_at=UTC_TIMESTAMP()';
    elseif($eventType==='engaged')$counterSql=',engagement_count=engagement_count+1,last_engaged_at=UTC_TIMESTAMP()';
    elseif($eventType==='acted')$counterSql=',action_count=action_count+1,engagement_count=engagement_count+1,last_engaged_at=UTC_TIMESTAMP()';
    elseif($eventType==='hidden')$counterSql=',hidden_count=hidden_count+1';
    $pdo->prepare("UPDATE cognitive_item_lifecycle_v540 SET updated_at=UTC_TIMESTAMP() {$counterSql}
      WHERE owner_user_id=? AND agent_namespace=? AND item_key=?")
      ->execute([$uid,$namespace,$itemKey]);

    vp3_cognitive_learning_profile_apply_v540($pdo,$user,$namespace,$candidate,$eventType);
    return true;
}

function vp3_cognitive_learning_observe_candidates_v540(PDO $pdo,array $user,string $namespace,array $candidates): void
{
    if(!vp3_cognitive_learning_schema_ready_v540($pdo))return;
    $uid=(int)($user['id']??0);if($uid<1)return;
    foreach($candidates as $candidate){
        if(!is_array($candidate))continue;
        $itemKey=mb_strimwidth(trim((string)($candidate['key']??'')),0,190,'');
        $fingerprint=strtolower(trim((string)($candidate['fingerprint']??'')));
        if($itemKey===''||!preg_match('/^[a-f0-9]{64}$/',$fingerprint))continue;
        $ref=vp3_cognitive_learning_ref_v540($candidate);
        $source=vp3_cognitive_learning_token_v540($candidate['source']??'',80);
        $section=vp3_cognitive_learning_token_v540($candidate['section']??'',32);

        $priorStmt=$pdo->prepare("SELECT item_fingerprint,lifecycle_state FROM cognitive_item_lifecycle_v540
          WHERE owner_user_id=? AND agent_namespace=? AND item_key=?");
        $priorStmt->execute([$uid,$namespace,$itemKey]);
        $prior=$priorStmt->fetch()?:null;
        $reopened=is_array($prior)
            && !hash_equals((string)($prior['item_fingerprint']??''),$fingerprint)
            && in_array((string)($prior['lifecycle_state']??''),['resolved','stale','closed'],true);

        $pdo->prepare("INSERT INTO cognitive_item_lifecycle_v540
          (owner_user_id,agent_namespace,item_key,item_fingerprint,source_kind,section_key,object_type,object_id,object_scope,lifecycle_state,current_outcome,first_seen_at,last_seen_at)
          VALUES (?,?,?,?,?,?,?,?,?,'active','',UTC_TIMESTAMP(),UTC_TIMESTAMP())
          ON DUPLICATE KEY UPDATE
            item_fingerprint=VALUES(item_fingerprint),
            source_kind=VALUES(source_kind),
            section_key=VALUES(section_key),
            object_type=VALUES(object_type),
            object_id=VALUES(object_id),
            object_scope=VALUES(object_scope),
            lifecycle_state=IF(item_fingerprint<>VALUES(item_fingerprint),'active',lifecycle_state),
            current_outcome=IF(item_fingerprint<>VALUES(item_fingerprint),'',current_outcome),
            last_seen_at=UTC_TIMESTAMP(),
            updated_at=UTC_TIMESTAMP()")
          ->execute([$uid,$namespace,$itemKey,$fingerprint,$source,$section,$ref['type'],$ref['id'],$ref['scope']]);
        if($reopened){
            $pdo->prepare("UPDATE cognitive_item_lifecycle_v540 SET reopened_count=reopened_count+1,lifecycle_state='active',current_outcome=''
              WHERE owner_user_id=? AND agent_namespace=? AND item_key=?")
              ->execute([$uid,$namespace,$itemKey]);
        }
    }
}

function vp3_cognitive_learning_profile_factor_v540(PDO $pdo,array $user,string $namespace,string $dimensionType,string $dimensionKey): float
{
    if(!vp3_cognitive_learning_schema_ready_v540($pdo)||$dimensionKey==='')return 1.0;
    $stmt=$pdo->prepare("SELECT relevance_factor FROM cognitive_learning_profiles_v540
      WHERE owner_user_id=? AND agent_namespace=? AND dimension_type=? AND dimension_key=?");
    $stmt->execute([(int)$user['id'],$namespace,$dimensionType,$dimensionKey]);
    $value=$stmt->fetchColumn();
    return is_numeric($value)?max(.90,min(1.10,(float)$value)):1.0;
}

function vp3_cognitive_learning_adjust_candidate_v540(PDO $pdo,array $user,string $namespace,array $candidate): array
{
    if(!vp3_cognitive_learning_schema_ready_v540($pdo))return $candidate;
    $section=(string)($candidate['section']??'');
    if(!in_array($section,['priorities','opportunities','recent'],true))return $candidate;

    $ref=vp3_cognitive_learning_ref_v540($candidate);
    $source=vp3_cognitive_learning_token_v540($candidate['source']??'',80);
    $sourceFactor=vp3_cognitive_learning_profile_factor_v540($pdo,$user,$namespace,'source',$source);
    $typeFactor=vp3_cognitive_learning_profile_factor_v540($pdo,$user,$namespace,'object_type',$ref['type']);
    $sectionFactor=vp3_cognitive_learning_profile_factor_v540($pdo,$user,$namespace,'section',$section);
    $adjust=(($sourceFactor-1)*45)+(($typeFactor-1)*25)+(($sectionFactor-1)*15);

    $stmt=$pdo->prepare("SELECT surface_count,engagement_count,action_count,reopened_count,current_outcome
      FROM cognitive_item_lifecycle_v540 WHERE owner_user_id=? AND agent_namespace=? AND item_key=?");
    $stmt->execute([(int)$user['id'],$namespace,(string)($candidate['key']??'')]);
    $life=$stmt->fetch()?:[];
    $surface=(int)($life['surface_count']??0);
    $engaged=(int)($life['engagement_count']??0);
    $actions=(int)($life['action_count']??0);
    if($surface>=4&&($engaged+$actions)===0)$adjust-=min(4.0,($surface-3)*.75);
    if(($engaged+$actions)>0)$adjust+=min(2.5,($engaged*.35)+($actions*.75));
    if((int)($life['reopened_count']??0)>0)$adjust+=min(1.5,(int)$life['reopened_count']*.5);

    $adjust=max(-8.0,min(8.0,$adjust));
    $candidate['score']=max(0,min(100,(float)($candidate['score']??0)+$adjust));
    $candidate['learning_adjustment']=round($adjust,3);
    return $candidate;
}

function vp3_cognitive_learning_surface_v540(PDO $pdo,array $user,string $namespace,array $candidate): void
{
    if(!vp3_cognitive_learning_schema_ready_v540($pdo))return;
    $uid=(int)($user['id']??0);if($uid<1)return;
    $itemKey=(string)($candidate['key']??'');if($itemKey==='')return;
    $stmt=$pdo->prepare("SELECT last_surfaced_at,item_fingerprint FROM cognitive_item_lifecycle_v540
      WHERE owner_user_id=? AND agent_namespace=? AND item_key=?");
    $stmt->execute([$uid,$namespace,$itemKey]);
    $row=$stmt->fetch()?:[];
    $last=strtotime((string)($row['last_surfaced_at']??''))?:0;
    $changed=!hash_equals((string)($row['item_fingerprint']??''),(string)($candidate['fingerprint']??''));
    if(!$changed&&$last>0&&(time()-$last)<VP3_COGNITIVE_LEARNING_SURFACE_COOLDOWN_V540)return;
    $bucket=(string)floor(time()/VP3_COGNITIVE_LEARNING_SURFACE_COOLDOWN_V540);
    vp3_cognitive_learning_feedback_v540($pdo,$user,$namespace,$candidate,'shown',[
        'section'=>$candidate['section']??'','surface'=>'agent_chat_now',
    ],'surface:'.$bucket);
}

function vp3_cognitive_learning_status_v540(array $resolved): string
{
    $row=is_array($resolved['row']??null)?$resolved['row']:[];
    if(is_array($row['goal']??null))$row=$row['goal'];
    if(isset($row['is_read'])&&(int)$row['is_read']===1)return 'resolved';
    $raw=strtolower(trim((string)($row['derived_status']??$row['status']??$row['state']??$row['result']??$row['outcome']??'')));
    $raw=str_replace([' ','-'],['_','_'],$raw);
    if(in_array($raw,['completed','complete','succeeded','success','successful','fulfilled','converted','paid','delivered','done','closed_won'],true))return 'successful';
    if(in_array($raw,['failed','failure','error','rejected','declined','closed_lost'],true))return 'unsuccessful';
    if(in_array($raw,['cancelled','canceled','dismissed','archived','closed','ended','resolved','expired'],true))return 'resolved';
    return '';
}

function vp3_cognitive_learning_candidate_from_lifecycle_v540(array $row): array
{
    return [
        'key'=>(string)($row['item_key']??''),
        'fingerprint'=>(string)($row['item_fingerprint']??''),
        'source'=>(string)($row['source_kind']??''),
        'section'=>(string)($row['section_key']??''),
        'card_request'=>[
            'card_type'=>(string)($row['object_type']??''),
            'object_ref'=>vp3_cognitive_object_ref_v500(
                (string)($row['object_type']??''),
                (string)($row['object_id']??''),
                (string)($row['object_scope']??'personal')
            ),
            'display_mode'=>'standard',
        ],
    ];
}

function vp3_cognitive_learning_record_outcome_v540(
    PDO $pdo,array $user,string $namespace,array $lifecycle,string $outcome,string $evidenceKind,string $evidenceRef='',float $confidence=1.0
): bool {
    if(!in_array($outcome,['successful','resolved','unsuccessful','ignored'],true))return false;
    $uid=(int)($user['id']??0);if($uid<1)return false;
    $candidate=vp3_cognitive_learning_candidate_from_lifecycle_v540($lifecycle);
    $outcomeKey=hash('sha256',vp3_cognitive_json_v500([
        $uid,$namespace,$candidate['key'],$candidate['fingerprint'],$outcome,$evidenceKind,$evidenceRef,
    ]));
    $ref=vp3_cognitive_learning_ref_v540($candidate);
    $stmt=$pdo->prepare("INSERT IGNORE INTO cognitive_outcomes_v540
      (owner_user_id,agent_namespace,item_key,item_fingerprint,source_kind,object_type,object_id,outcome_code,evidence_kind,evidence_ref,confidence,outcome_key,created_at)
      VALUES (?,?,?,?,?,?,?,?,?,?,?,?,UTC_TIMESTAMP())");
    $stmt->execute([
        $uid,$namespace,$candidate['key'],$candidate['fingerprint'],$candidate['source'],$ref['type'],$ref['id'],$outcome,
        vp3_cognitive_learning_token_v540($evidenceKind,64),mb_strimwidth(trim($evidenceRef),0,190,''),max(0,min(1,$confidence)),$outcomeKey,
    ]);
    if($stmt->rowCount()<1)return false;

    $pdo->prepare("UPDATE cognitive_item_lifecycle_v540
      SET lifecycle_state='resolved',current_outcome=?,last_outcome_at=UTC_TIMESTAMP(),updated_at=UTC_TIMESTAMP()
      WHERE owner_user_id=? AND agent_namespace=? AND item_key=?")
      ->execute([$outcome,$uid,$namespace,$candidate['key']]);

    vp3_cognitive_learning_feedback_v540(
        $pdo,$user,$namespace,$candidate,'outcome_'.$outcome,
        ['evidence_kind'=>$evidenceKind,'automatic'=>true],
        'outcome:'.$outcomeKey
    );
    return true;
}

function vp3_cognitive_learning_reconcile_v540(PDO $pdo,array $user,string $namespace): array
{
    $summary=['resolved'=>0,'stale'=>0,'checked'=>0];
    if(!vp3_cognitive_learning_schema_ready_v540($pdo))return $summary;
    $uid=(int)($user['id']??0);if($uid<1)return $summary;
    $limit=VP3_COGNITIVE_LEARNING_RECONCILE_LIMIT_V540;
    $stmt=$pdo->prepare("SELECT * FROM cognitive_item_lifecycle_v540
      WHERE owner_user_id=? AND agent_namespace=? AND lifecycle_state IN ('active','stale')
      ORDER BY last_seen_at ASC LIMIT {$limit}");
    $stmt->execute([$uid,$namespace]);
    foreach($stmt->fetchAll()?:[] as $row){
        $summary['checked']++;
        $type=(string)($row['object_type']??'');$id=(string)($row['object_id']??'');
        if($type===''||$id==='')continue;
        $ref=vp3_cognitive_object_ref_v500($type,$id,(string)($row['object_scope']??'personal'));
        try{
            if(!vp3_cognitive_authorize_ref_v500($pdo,$user,$namespace,$ref,'read'))continue;
            $resolved=vp3_cognitive_cards_object_v520($pdo,$user,$namespace,$ref);
        }catch(Throwable $e){$resolved=null;}
        if(is_array($resolved)){
            $outcome=vp3_cognitive_learning_status_v540($resolved);
            if($outcome!==''&&vp3_cognitive_learning_record_outcome_v540($pdo,$user,$namespace,$row,$outcome,'canonical_status',$type.':'.$id,1.0)){
                $summary['resolved']++;
            }
            continue;
        }
        $last=strtotime((string)($row['last_seen_at']??''))?:time();
        if(time()-$last>=VP3_COGNITIVE_LEARNING_STALE_DAYS_V540*86400){
            $pdo->prepare("UPDATE cognitive_item_lifecycle_v540 SET lifecycle_state='stale',updated_at=UTC_TIMESTAMP()
              WHERE owner_user_id=? AND agent_namespace=? AND item_key=?")
              ->execute([$uid,$namespace,(string)$row['item_key']]);
            $summary['stale']++;
        }
    }
    return $summary;
}

function vp3_cognitive_learning_explain_v540(PDO $pdo,array $user,string $namespace,array $candidate): array
{
    $section=(string)($candidate['section']??'');
    $ref=vp3_cognitive_learning_ref_v540($candidate);
    $source=vp3_cognitive_learning_token_v540($candidate['source']??'',80);
    $sourceFactor=vp3_cognitive_learning_profile_factor_v540($pdo,$user,$namespace,'source',$source);
    $typeFactor=vp3_cognitive_learning_profile_factor_v540($pdo,$user,$namespace,'object_type',$ref['type']);
    $stmt=$pdo->prepare("SELECT surface_count,engagement_count,action_count,reopened_count,current_outcome,lifecycle_state
      FROM cognitive_item_lifecycle_v540 WHERE owner_user_id=? AND agent_namespace=? AND item_key=?");
    $stmt->execute([(int)$user['id'],$namespace,(string)$candidate['key']]);
    $life=$stmt->fetch()?:[];

    $parts=[];
    if(in_array($section,['attention','next_up'],true)){
        $parts[]='Time and attention rules take precedence over learned relevance for this item.';
    }elseif($sourceFactor>1.025||$typeFactor>1.025){
        $parts[]='Similar items have recently led to engagement or useful outcomes, so this receives a small relevance boost.';
    }elseif($sourceFactor<.975||$typeFactor<.975){
        $parts[]='Similar items have recently produced less engagement, so this is ranked more conservatively.';
    }else{
        $parts[]='This item is primarily ranked from its current VP3 state, timing, and deterministic feed rules.';
    }
    if((int)($life['surface_count']??0)>=4&&((int)($life['engagement_count']??0)+(int)($life['action_count']??0))===0){
        $parts[]='It has been surfaced several times without a deliberate action, so its learned boost is limited.';
    }
    if((int)($life['reopened_count']??0)>0)$parts[]='It resurfaced after the underlying canonical state changed.';
    $parts[]='Learning can adjust relevance only; permissions, approvals, tool execution, and canonical object state remain authoritative elsewhere.';

    return [
        'build'=>VP3_COGNITIVE_LEARNING_V540,
        'item_key'=>(string)$candidate['key'],
        'explanation'=>implode(' ',$parts),
        'basis'=>[
            'source'=>$source,
            'object_type'=>$ref['type'],
            'section'=>$section,
            'surface_count'=>(int)($life['surface_count']??0),
            'engagement_count'=>(int)($life['engagement_count']??0),
            'action_count'=>(int)($life['action_count']??0),
            'reopened_count'=>(int)($life['reopened_count']??0),
        ],
        'authority'=>'relevance_only',
    ];
}
