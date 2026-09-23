<?php
declare(strict_types=1);

/**
 * VP3 Cognitive Runtime v25.70 — Outcome Value & ROI Optimization.
 *
 * This layer stores explicit user value definitions and append-only user value
 * confirmations. It derives outcome verification from canonical goal/workflow/
 * meeting authorities and may read canonical Profile conversion revenue when
 * the user explicitly links a value profile to a conversion target.
 *
 * It never invents monetary value, performs FX conversion, charges a user,
 * mutates budgets/tokens, changes commitments, approves work, changes
 * executors/deadlines, claims leases, or declares an outcome verified.
 */
const VP3_COGNITIVE_VALUE_ROI_V2570='vp3-cognitive-value-roi-v2570-20260923';
const VP3_COGNITIVE_VALUE_ROI_CONTRACT_V2570='cognitive-outcome-value-roi-v1';
const VP3_COGNITIVE_VALUE_MAX_PROFILES_V2570=64;
const VP3_COGNITIVE_VALUE_MAX_EVENTS_V2570=120;
const VP3_COGNITIVE_VALUE_MAX_PROFILE_EVENTS_SCAN_V2570=1200;

function vp3_cognitive_value_schema_ready_v2570(?PDO $pdo=null): bool
{
    $pdo??=db();
    return (bool)($pdo
        &&table_exists('cognitive_value_profiles_v2570')
        &&table_exists('cognitive_value_events_v2570')
        &&column_exists('cognitive_value_profiles_v2570','owner_user_id')
        &&column_exists('cognitive_value_profiles_v2570','scope_kind')
        &&column_exists('cognitive_value_profiles_v2570','scope_key')
        &&column_exists('cognitive_value_profiles_v2570','value_kind')
        &&column_exists('cognitive_value_profiles_v2570','realization_mode')
        &&column_exists('cognitive_value_profiles_v2570','baseline_at')
        &&column_exists('cognitive_value_events_v2570','event_type')
        &&column_exists('cognitive_value_events_v2570','profile_id'));
}

function vp3_cognitive_value_ensure_schema_v2570(?PDO $pdo=null): void
{
    $pdo??=db();
    if(!$pdo)throw new RuntimeException('Database connection is unavailable.');
    $pdo->exec("CREATE TABLE IF NOT EXISTS cognitive_value_profiles_v2570 (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
      owner_user_id INT UNSIGNED NOT NULL,
      label VARCHAR(190) NOT NULL DEFAULT '',
      scope_kind VARCHAR(20) NOT NULL,
      scope_key VARCHAR(190) NOT NULL,
      value_kind VARCHAR(16) NOT NULL DEFAULT 'score',
      currency CHAR(3) NOT NULL DEFAULT '',
      expected_value_micros BIGINT UNSIGNED NULL,
      expected_score SMALLINT UNSIGNED NULL,
      realization_mode VARCHAR(32) NOT NULL DEFAULT 'manual_confirmation',
      evidence_key VARCHAR(190) NOT NULL DEFAULT '',
      baseline_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      is_active TINYINT(1) NOT NULL DEFAULT 1,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      UNIQUE KEY uq_cognitive_value_scope (owner_user_id,scope_kind,scope_key),
      INDEX idx_cognitive_value_owner_active (owner_user_id,is_active,scope_kind,id),
      CONSTRAINT fk_cognitive_value_owner FOREIGN KEY (owner_user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $pdo->exec("CREATE TABLE IF NOT EXISTS cognitive_value_events_v2570 (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
      owner_user_id INT UNSIGNED NOT NULL,
      profile_id BIGINT UNSIGNED NOT NULL,
      event_type VARCHAR(32) NOT NULL,
      value_micros BIGINT UNSIGNED NULL,
      score_value SMALLINT UNSIGNED NULL,
      currency CHAR(3) NOT NULL DEFAULT '',
      evidence_kind VARCHAR(40) NOT NULL DEFAULT 'user_confirmation',
      evidence_key VARCHAR(190) NOT NULL DEFAULT '',
      note VARCHAR(500) NOT NULL DEFAULT '',
      actor_kind VARCHAR(20) NOT NULL DEFAULT 'user',
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      INDEX idx_cognitive_value_event_profile (profile_id,id),
      INDEX idx_cognitive_value_event_owner (owner_user_id,created_at,id),
      CONSTRAINT fk_cognitive_value_event_owner FOREIGN KEY (owner_user_id) REFERENCES users(id) ON DELETE CASCADE,
      CONSTRAINT fk_cognitive_value_event_profile FOREIGN KEY (profile_id) REFERENCES cognitive_value_profiles_v2570(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

function vp3_cognitive_value_ready_v2570(?PDO $pdo=null): bool
{
    $pdo??=db();
    return (bool)($pdo&&vp3_cognitive_value_schema_ready_v2570($pdo)
        &&function_exists('vp3_cognitive_economics_apply_v2550')
        &&function_exists('vp3_cognitive_budget_apply_v2560'));
}

function vp3_cognitive_value_text_v2570(mixed $value,int $limit=190): string
{
    $text=preg_replace('/\s+/u',' ',trim((string)$value))??'';
    return mb_strimwidth($text,0,max(1,$limit),'…');
}

function vp3_cognitive_value_scope_kind_v2570(mixed $value): string
{
    $kind=strtolower(trim((string)$value));
    if(!in_array($kind,['goal','workflow','project','agent','meeting'],true)){
        throw new RuntimeException('Choose a goal, workflow, project, Agent, or meeting value scope.');
    }
    return $kind;
}

function vp3_cognitive_value_scope_key_v2570(string $kind,mixed $value): string
{
    $key=vp3_cognitive_value_text_v2570($value,190);
    if(in_array($kind,['goal','workflow','agent','meeting'],true)){
        $id=max(0,(int)$key);
        if($id<1)throw new RuntimeException(ucfirst($kind).' value scope requires a valid id.');
        return (string)$id;
    }
    if($key==='')throw new RuntimeException('Project value scope requires a workflow source key.');
    return $key;
}

function vp3_cognitive_value_kind_v2570(mixed $value): string
{
    $kind=strtolower(trim((string)$value));
    if(!in_array($kind,['money','score'],true))throw new RuntimeException('Choose monetary value or a neutral outcome score.');
    return $kind;
}

function vp3_cognitive_value_currency_v2570(mixed $value): string
{
    $currency=strtoupper(trim((string)$value));
    if($currency===''||!preg_match('/^[A-Z]{3}$/',$currency))throw new RuntimeException('Use a three-letter currency code such as USD.');
    return $currency;
}

function vp3_cognitive_value_realization_mode_v2570(mixed $value): string
{
    $mode=strtolower(trim((string)$value));
    if(!in_array($mode,['manual_confirmation','verified_completion','profile_conversion'],true)){
        throw new RuntimeException('Choose manual confirmation, verified completion, or Profile conversion evidence.');
    }
    return $mode;
}

function vp3_cognitive_value_public_profile_v2570(array $row): array
{
    return [
        'id'=>(int)($row['id']??0),'label'=>(string)($row['label']??''),
        'scope_kind'=>(string)($row['scope_kind']??''),'scope_key'=>(string)($row['scope_key']??''),
        'value_kind'=>(string)($row['value_kind']??'score'),'currency'=>(string)($row['currency']??''),
        'expected_value_micros'=>$row['expected_value_micros']===null?null:max(0,(int)$row['expected_value_micros']),
        'expected_score'=>$row['expected_score']===null?null:max(0,min(100,(int)$row['expected_score'])),
        'realization_mode'=>(string)($row['realization_mode']??'manual_confirmation'),
        'evidence_key'=>(string)($row['evidence_key']??''),
        'baseline_at'=>(string)($row['baseline_at']??''),
        'is_active'=>!empty($row['is_active']),
        'created_at'=>(string)($row['created_at']??''),'updated_at'=>(string)($row['updated_at']??''),
    ];
}

function vp3_cognitive_value_profile_rows_v2570(PDO $pdo,array $user,bool $activeOnly=true): array
{
    $uid=(int)($user['id']??0);
    if($uid<1||!vp3_cognitive_value_schema_ready_v2570($pdo))return [];
    $stmt=$pdo->prepare('SELECT * FROM cognitive_value_profiles_v2570 WHERE owner_user_id=?'
        .($activeOnly?' AND is_active=1':'')
        .' ORDER BY is_active DESC,scope_kind,scope_key,id LIMIT '.VP3_COGNITIVE_VALUE_MAX_PROFILES_V2570);
    $stmt->execute([$uid]);
    return array_map('vp3_cognitive_value_public_profile_v2570',$stmt->fetchAll(PDO::FETCH_ASSOC)?:[]);
}

function vp3_cognitive_value_profile_row_v2570(PDO $pdo,int $uid,int $profileId,bool $forUpdate=false): ?array
{
    if($uid<1||$profileId<1||!vp3_cognitive_value_schema_ready_v2570($pdo))return null;
    $stmt=$pdo->prepare('SELECT * FROM cognitive_value_profiles_v2570 WHERE id=? AND owner_user_id=? LIMIT 1'.($forUpdate?' FOR UPDATE':''));
    $stmt->execute([$profileId,$uid]);$row=$stmt->fetch(PDO::FETCH_ASSOC);
    return is_array($row)?$row:null;
}

function vp3_cognitive_value_profile_save_v2570(PDO $pdo,array $user,array $input): array
{
    $uid=(int)($user['id']??0);
    if($uid<1)throw new RuntimeException('Sign in to manage outcome value.');
    if(!vp3_cognitive_value_schema_ready_v2570($pdo))throw new RuntimeException('Outcome Value & ROI is not ready. Run /upgrade.php.');

    $id=max(0,(int)($input['id']??0));
    $scopeKind=vp3_cognitive_value_scope_kind_v2570($input['scope_kind']??'goal');
    $scopeKey=vp3_cognitive_value_scope_key_v2570($scopeKind,$input['scope_key']??'');
    $kind=vp3_cognitive_value_kind_v2570($input['value_kind']??'score');
    $mode=vp3_cognitive_value_realization_mode_v2570($input['realization_mode']??'manual_confirmation');
    $currency='';$money=null;$score=null;
    if($kind==='money'){
        $currency=vp3_cognitive_value_currency_v2570($input['currency']??'USD');
        if(!array_key_exists('expected_value_micros',$input)||$input['expected_value_micros']===''||$input['expected_value_micros']===null){
            throw new RuntimeException('Set the expected monetary value explicitly.');
        }
        $money=max(0,(int)$input['expected_value_micros']);
    }else{
        if(!array_key_exists('expected_score',$input)||$input['expected_score']===''||$input['expected_score']===null){
            throw new RuntimeException('Set an expected outcome score from 0 to 100.');
        }
        $score=max(0,min(100,(int)$input['expected_score']));
    }

    if($mode==='verified_completion'&&!in_array($scopeKind,['goal','workflow','meeting'],true)){
        throw new RuntimeException('Verified-completion realization is available only for goal, workflow, or meeting scopes.');
    }
    $evidenceKey=vp3_cognitive_value_text_v2570($input['evidence_key']??'',190);
    if($mode==='profile_conversion'){
        if($kind!=='money')throw new RuntimeException('Profile conversion evidence can realize monetary value only.');
        if($evidenceKey==='')throw new RuntimeException('Link a Profile conversion target key.');
    }else $evidenceKey='';

    $label=vp3_cognitive_value_text_v2570($input['label']??'',190);
    if($label==='')$label=ucfirst($scopeKind).' outcome value';

    $before=$id>0?vp3_cognitive_value_profile_row_v2570($pdo,$uid,$id,true):null;
    if($id>0&&!$before)throw new RuntimeException('Outcome value profile not found.');

    if($before){
        $resetBaseline=(string)$before['realization_mode']!==$mode||(string)$before['evidence_key']!==$evidenceKey;
        $revokeManual=(string)$before['scope_kind']!==$scopeKind
            ||(string)$before['scope_key']!==$scopeKey
            ||(string)$before['value_kind']!==$kind
            ||strtoupper((string)$before['currency'])!==$currency;
        $manualBefore=$revokeManual?vp3_cognitive_value_latest_manual_v2570($pdo,$uid,$id):null;
        $stmt=$pdo->prepare('UPDATE cognitive_value_profiles_v2570
          SET label=?,scope_kind=?,scope_key=?,value_kind=?,currency=?,expected_value_micros=?,expected_score=?,
              realization_mode=?,evidence_key=?,baseline_at=CASE WHEN ?=1 THEN NOW() ELSE baseline_at END,
              is_active=1,updated_at=NOW()
          WHERE id=? AND owner_user_id=?');
        $stmt->execute([$label,$scopeKind,$scopeKey,$kind,$currency,$money,$score,$mode,$evidenceKey,$resetBaseline?1:0,$id,$uid]);
        if($manualBefore){
            vp3_cognitive_value_event_insert_v2570(
                $pdo,$uid,$id,'realized_revoked',null,null,'','profile_configuration_change',
                $scopeKind.':'.$scopeKey,'Profile definition changed; the prior manual realized-value confirmation no longer applies.'
            );
        }
    }else{
        $stmt=$pdo->prepare('INSERT INTO cognitive_value_profiles_v2570
          (owner_user_id,label,scope_kind,scope_key,value_kind,currency,expected_value_micros,expected_score,realization_mode,evidence_key,is_active)
          VALUES (?,?,?,?,?,?,?,?,?,?,1)
          ON DUPLICATE KEY UPDATE label=VALUES(label),value_kind=VALUES(value_kind),currency=VALUES(currency),
            expected_value_micros=VALUES(expected_value_micros),expected_score=VALUES(expected_score),
            realization_mode=VALUES(realization_mode),evidence_key=VALUES(evidence_key),is_active=1,updated_at=NOW()');
        $stmt->execute([$uid,$label,$scopeKind,$scopeKey,$kind,$currency,$money,$score,$mode,$evidenceKey]);
        if((int)$pdo->lastInsertId()>0)$id=(int)$pdo->lastInsertId();
        else{
            $q=$pdo->prepare('SELECT id FROM cognitive_value_profiles_v2570
              WHERE owner_user_id=? AND scope_kind=? AND scope_key=? LIMIT 1');
            $q->execute([$uid,$scopeKind,$scopeKey]);$id=(int)$q->fetchColumn();
        }
    }
    $row=vp3_cognitive_value_profile_row_v2570($pdo,$uid,$id)?:throw new RuntimeException('Outcome value profile could not be loaded.');
    return vp3_cognitive_value_public_profile_v2570($row);
}

function vp3_cognitive_value_profile_disable_v2570(PDO $pdo,array $user,int $profileId): array
{
    $uid=(int)($user['id']??0);$row=vp3_cognitive_value_profile_row_v2570($pdo,$uid,$profileId,true);
    if(!$row)throw new RuntimeException('Outcome value profile not found.');
    $pdo->prepare('UPDATE cognitive_value_profiles_v2570 SET is_active=0,updated_at=NOW() WHERE id=? AND owner_user_id=?')
        ->execute([$profileId,$uid]);
    $row['is_active']=0;return vp3_cognitive_value_public_profile_v2570($row);
}

function vp3_cognitive_value_event_insert_v2570(
    PDO $pdo,int $uid,int $profileId,string $eventType,?int $valueMicros,?int $scoreValue,
    string $currency,string $evidenceKind,string $evidenceKey,string $note
): int {
    $stmt=$pdo->prepare('INSERT INTO cognitive_value_events_v2570
      (owner_user_id,profile_id,event_type,value_micros,score_value,currency,evidence_kind,evidence_key,note,actor_kind)
      VALUES (?,?,?,?,?,?,?,?,?,?)');
    $stmt->execute([
        $uid,$profileId,$eventType,$valueMicros,$scoreValue,$currency,
        vp3_cognitive_value_text_v2570($evidenceKind,40),vp3_cognitive_value_text_v2570($evidenceKey,190),
        vp3_cognitive_value_text_v2570($note,500),'user'
    ]);
    return (int)$pdo->lastInsertId();
}

function vp3_cognitive_value_realization_set_v2570(
    PDO $pdo,array $user,int $profileId,array $input
): array {
    $uid=(int)($user['id']??0);$profile=vp3_cognitive_value_profile_row_v2570($pdo,$uid,$profileId);
    if(!$profile)throw new RuntimeException('Outcome value profile not found.');
    $kind=(string)$profile['value_kind'];$money=null;$score=null;$currency='';
    if($kind==='money'){
        if(!array_key_exists('value_micros',$input)||$input['value_micros']===''||$input['value_micros']===null){
            throw new RuntimeException('Set the realized monetary value explicitly.');
        }
        $money=max(0,(int)$input['value_micros']);$currency=(string)$profile['currency'];
    }else{
        if(!array_key_exists('score_value',$input)||$input['score_value']===''||$input['score_value']===null){
            throw new RuntimeException('Set the realized outcome score from 0 to 100.');
        }
        $score=max(0,min(100,(int)$input['score_value']));
    }
    $id=vp3_cognitive_value_event_insert_v2570(
        $pdo,$uid,$profileId,'realized_set',$money,$score,$currency,'user_confirmation',
        (string)($profile['scope_kind'].':'.$profile['scope_key']),$input['note']??'User confirmed realized outcome value.'
    );
    return ['id'=>$id,'profile_id'=>$profileId,'active'=>true,'value_micros'=>$money,'score_value'=>$score,'currency'=>$currency];
}

function vp3_cognitive_value_realization_revoke_v2570(PDO $pdo,array $user,int $profileId,string $note=''): array
{
    $uid=(int)($user['id']??0);$profile=vp3_cognitive_value_profile_row_v2570($pdo,$uid,$profileId);
    if(!$profile)throw new RuntimeException('Outcome value profile not found.');
    $id=vp3_cognitive_value_event_insert_v2570(
        $pdo,$uid,$profileId,'realized_revoked',null,null,'','user_confirmation',
        (string)($profile['scope_kind'].':'.$profile['scope_key']),$note!==''?$note:'User revoked the latest manual realized-value confirmation.'
    );
    return ['id'=>$id,'profile_id'=>$profileId,'active'=>false];
}

function vp3_cognitive_value_latest_manual_v2570(PDO $pdo,int $uid,int $profileId): ?array
{
    if($uid<1||$profileId<1)return null;
    $stmt=$pdo->prepare("SELECT * FROM cognitive_value_events_v2570
      WHERE owner_user_id=? AND profile_id=? AND event_type IN ('realized_set','realized_revoked')
      ORDER BY id DESC LIMIT 1");
    $stmt->execute([$uid,$profileId]);$row=$stmt->fetch(PDO::FETCH_ASSOC);
    if(!is_array($row)||(string)$row['event_type']!=='realized_set')return null;
    return [
        'verified'=>true,'source'=>'user_confirmation','verified_at'=>(string)$row['created_at'],
        'value_micros'=>$row['value_micros']===null?null:max(0,(int)$row['value_micros']),
        'score_value'=>$row['score_value']===null?null:max(0,min(100,(int)$row['score_value'])),
        'currency'=>(string)$row['currency'],'evidence_key'=>(string)$row['evidence_key'],
        'note'=>(string)$row['note'],
    ];
}

function vp3_cognitive_value_profile_target_key_v2570(string $eventType,array $metadata): string
{
    $kind=str_starts_with($eventType,'booking_')?'booking':'product';
    if(function_exists('profile_revenue_target_key_v180')){
        return profile_revenue_target_key_v180($kind,$metadata);
    }
    $id=max(0,(int)($metadata['target_id']??0));if($id>0)return $kind.':id:'.$id;
    $slug=strtolower(vp3_cognitive_value_text_v2570($metadata['target_slug']??'',120));
    if($slug!=='')return $kind.':slug:'.$slug;
    return $kind.':title:'.strtolower(vp3_cognitive_value_text_v2570($metadata['target_title']??'unknown',190));
}

function vp3_cognitive_value_profile_conversion_v2570(PDO $pdo,int $uid,array $profile): ?array
{
    if($uid<1||!table_exists('profile_events'))return null;
    $target=(string)($profile['evidence_key']??'');$currency=strtoupper((string)($profile['currency']??''));
    if($target===''||$currency==='')return null;
    $baseline=(string)($profile['baseline_at']??$profile['created_at']??'');
    try{
        $stmt=$pdo->prepare("SELECT event_type,metadata_json,created_at FROM profile_events
          WHERE owner_user_id=? AND event_type IN ('booking_converted','product_converted')
            AND created_at>=? ORDER BY id ASC LIMIT ".VP3_COGNITIVE_VALUE_MAX_PROFILE_EVENTS_SCAN_V2570);
        $stmt->execute([$uid,$baseline!==''?$baseline:'1970-01-01 00:00:00']);
        $cents=0;$matches=0;$lastAt='';
        foreach($stmt->fetchAll(PDO::FETCH_ASSOC)?:[] as $row){
            $meta=json_decode((string)($row['metadata_json']??''),true);if(!is_array($meta))$meta=[];
            if(vp3_cognitive_value_profile_target_key_v2570((string)$row['event_type'],$meta)!==$target)continue;
            $rowCurrency=strtoupper(trim((string)($meta['currency']??'')));if($rowCurrency!==$currency)continue;
            $cents+=max(0,(int)($meta['value_cents']??0));$matches++;$lastAt=(string)($row['created_at']??$lastAt);
        }
        if($matches<1)return null;
        return [
            'verified'=>true,'source'=>'profile_conversion_ledger','verified_at'=>$lastAt,
            'value_micros'=>$cents*10000,'score_value'=>null,'currency'=>$currency,
            'evidence_key'=>$target,'conversion_count'=>$matches,
        ];
    }catch(Throwable $e){return null;}
}

function vp3_cognitive_value_verified_completion_v2570(PDO $pdo,int $uid,array $profile): ?array
{
    $kind=(string)($profile['scope_kind']??'');$key=(int)($profile['scope_key']??0);
    if($uid<1||$key<1)return null;
    $verifiedAt='';
    if($kind==='goal'&&function_exists('agent_goal_review_actual_achievement_v1714')){
        try{$verifiedAt=(string)(agent_goal_review_actual_achievement_v1714($pdo,$uid,$key)??'');}catch(Throwable $e){}
    }elseif($kind==='workflow'&&table_exists('agent_workflow_runs')){
        try{
            $stmt=$pdo->prepare("SELECT objective_verification_status,objective_verified_at,updated_at FROM agent_workflow_runs
              WHERE owner_user_id=? AND id=? LIMIT 1");
            $stmt->execute([$uid,$key]);$row=$stmt->fetch(PDO::FETCH_ASSOC);
            if(is_array($row)&&(string)($row['objective_verification_status']??'')==='achieved'){
                $verifiedAt=(string)($row['objective_verified_at']??$row['updated_at']??'');
            }
        }catch(Throwable $e){}
    }elseif($kind==='meeting'&&table_exists('video_meeting_followthrough_closures')){
        try{
            $stmt=$pdo->prepare("SELECT COUNT(*) total_count,
              SUM(CASE WHEN status='verified' THEN 1 ELSE 0 END) verified_count,
              MAX(CASE WHEN status='verified' THEN COALESCE(verified_at,updated_at) ELSE NULL END) verified_at
              FROM video_meeting_followthrough_closures WHERE owner_user_id=? AND meeting_id=?");
            $stmt->execute([$uid,$key]);$row=$stmt->fetch(PDO::FETCH_ASSOC);
            $total=max(0,(int)($row['total_count']??0));$verified=max(0,(int)($row['verified_count']??0));
            if($total>0&&$total===$verified)$verifiedAt=(string)($row['verified_at']??'');
        }catch(Throwable $e){}
    }
    if($verifiedAt==='')return null;
    return [
        'verified'=>true,'source'=>'declared_completion_value','verified_at'=>$verifiedAt,
        'value_micros'=>$profile['expected_value_micros']===null?null:max(0,(int)$profile['expected_value_micros']),
        'score_value'=>$profile['expected_score']===null?null:max(0,min(100,(int)$profile['expected_score'])),
        'currency'=>(string)($profile['currency']??''),'evidence_key'=>$kind.':'.$key,
    ];
}

function vp3_cognitive_value_realization_v2570(PDO $pdo,array $user,array $profile): array
{
    $uid=(int)($user['id']??0);$profileId=(int)($profile['id']??0);
    $empty=[
        'verified'=>false,'source'=>'unverified','verified_at'=>'',
        'value_micros'=>null,'score_value'=>null,'currency'=>(string)($profile['currency']??''),
        'evidence_key'=>'',
    ];
    if($uid<1||$profileId<1)return $empty;
    $manual=vp3_cognitive_value_latest_manual_v2570($pdo,$uid,$profileId);
    if($manual)return array_merge($empty,$manual);
    $mode=(string)($profile['realization_mode']??'manual_confirmation');
    if($mode==='verified_completion'){
        $verified=vp3_cognitive_value_verified_completion_v2570($pdo,$uid,$profile);
        return $verified?array_merge($empty,$verified):$empty;
    }
    if($mode==='profile_conversion'){
        $verified=vp3_cognitive_value_profile_conversion_v2570($pdo,$uid,$profile);
        return $verified?array_merge($empty,$verified):$empty;
    }
    return $empty;
}

function vp3_cognitive_value_events_v2570(PDO $pdo,array $user,int $limit=50): array
{
    $uid=(int)($user['id']??0);$limit=max(1,min(VP3_COGNITIVE_VALUE_MAX_EVENTS_V2570,$limit));
    if($uid<1||!vp3_cognitive_value_schema_ready_v2570($pdo))return [];
    $stmt=$pdo->prepare("SELECT e.*,p.label AS profile_label,p.scope_kind,p.scope_key
      FROM cognitive_value_events_v2570 e
      INNER JOIN cognitive_value_profiles_v2570 p ON p.id=e.profile_id AND p.owner_user_id=e.owner_user_id
      WHERE e.owner_user_id=? ORDER BY e.id DESC LIMIT ".$limit);
    $stmt->execute([$uid]);$out=[];
    foreach($stmt->fetchAll(PDO::FETCH_ASSOC)?:[] as $row){
        $out[]=[
            'id'=>(int)$row['id'],'profile_id'=>(int)$row['profile_id'],'profile_label'=>(string)$row['profile_label'],
            'scope_kind'=>(string)$row['scope_kind'],'scope_key'=>(string)$row['scope_key'],
            'event_type'=>(string)$row['event_type'],'value_micros'=>$row['value_micros']===null?null:(int)$row['value_micros'],
            'score_value'=>$row['score_value']===null?null:(int)$row['score_value'],'currency'=>(string)$row['currency'],
            'evidence_kind'=>(string)$row['evidence_kind'],'evidence_key'=>(string)$row['evidence_key'],
            'note'=>(string)$row['note'],'created_at'=>(string)$row['created_at'],
        ];
    }
    return $out;
}

function vp3_cognitive_value_run_metadata_v2570(PDO $pdo,int $uid,array $runIds): array
{
    $runIds=array_values(array_unique(array_filter(array_map('intval',$runIds),static fn(int $id): bool=>$id>0)));
    if($uid<1||!$runIds||!table_exists('agent_workflow_runs'))return [];
    $runIds=array_slice($runIds,0,200);$ph=implode(',',array_fill(0,count($runIds),'?'));
    $stmt=$pdo->prepare("SELECT id,agent_id,source_key,source_kind FROM agent_workflow_runs WHERE owner_user_id=? AND id IN ({$ph})");
    $stmt->execute(array_merge([$uid],$runIds));$out=[];
    foreach($stmt->fetchAll(PDO::FETCH_ASSOC)?:[] as $row)$out[(int)$row['id']]=$row;
    return $out;
}

function vp3_cognitive_value_profile_map_v2570(array $profiles): array
{
    $map=[];foreach($profiles as $profile){
        if(!is_array($profile)||empty($profile['is_active']))continue;
        $map[(string)$profile['scope_kind']][(string)$profile['scope_key']]=$profile;
    }
    return $map;
}

function vp3_cognitive_value_resolve_item_profile_v2570(array $item,array $profileMap,array $runMeta): array
{
    $goalId=(int)($item['goal_id']??0);
    if($goalId>0&&isset($profileMap['goal'][(string)$goalId])){
        return ['profile'=>$profileMap['goal'][(string)$goalId],'source'=>'goal','ambiguous'=>false];
    }
    $runIds=array_values(array_unique(array_filter(array_map('intval',(array)($item['workflow_run_ids']??[])),static fn(int $id): bool=>$id>0)));
    $workflow=[];$projects=[];$agents=[];
    foreach($runIds as $runId){
        if(isset($profileMap['workflow'][(string)$runId]))$workflow[(int)$profileMap['workflow'][(string)$runId]['id']]=$profileMap['workflow'][(string)$runId];
        $meta=$runMeta[$runId]??[];
        $project=vp3_cognitive_value_text_v2570($meta['source_key']??'',190);
        if($project!==''&&isset($profileMap['project'][$project]))$projects[(int)$profileMap['project'][$project]['id']]=$profileMap['project'][$project];
        $agentId=max(0,(int)($meta['agent_id']??0));
        if($agentId>0&&isset($profileMap['agent'][(string)$agentId]))$agents[(int)$profileMap['agent'][(string)$agentId]['id']]=$profileMap['agent'][(string)$agentId];
    }
    foreach([['workflow',$workflow],['project',$projects],['agent',$agents]] as [$source,$rows]){
        if(count($rows)===1)return ['profile'=>array_values($rows)[0],'source'=>$source,'ambiguous'=>false];
        if(count($rows)>1)return ['profile'=>null,'source'=>$source,'ambiguous'=>true];
    }
    return ['profile'=>null,'source'=>'none','ambiguous'=>false];
}

function vp3_cognitive_value_roi_percent_v2570(?int $valueMicros,?int $costMicros,string $currency): ?float
{
    if($valueMicros===null||$costMicros===null||strtoupper($currency)!=='USD'||$costMicros<=0)return null;
    return round((($valueMicros-$costMicros)/$costMicros)*100,2);
}

function vp3_cognitive_value_planning_adjustment_v2570(
    array $item,array $profile,?int $expectedCostMicros
): float {
    if((string)($item['execution_mode']??'manual')!=='autonomous')return 0.0;
    if(!empty($item['budget_hard_hold']))return 0.0;
    if((float)($item['commitment_protection_score']??0)>=0.65)return 0.0;
    $kind=(string)($profile['value_kind']??'score');
    if($kind==='score'){
        $score=max(0,min(100,(int)($profile['expected_score']??50)));
        return round(max(-0.08,min(0.08,(($score-50)/50)*0.08)),4);
    }
    $currency=strtoupper((string)($profile['currency']??''));
    $value=$profile['expected_value_micros']===null?null:max(0,(int)$profile['expected_value_micros']);
    if($currency!=='USD'||$value===null||$expectedCostMicros===null||$expectedCostMicros<=0)return 0.0;
    $ratio=max(0.0,$value/$expectedCostMicros);
    $efficiency=$ratio/(1.0+$ratio);
    return round(max(-0.08,min(0.08,($efficiency-0.5)*0.16)),4);
}

function vp3_cognitive_value_item_at_risk_v2570(array $item): bool
{
    return !empty($item['budget_hard_hold'])
        ||!empty($item['commitment_at_risk'])
        ||!empty($item['requires_user'])
        ||in_array((string)($item['execution_state']??''),['blocked','repair_needed','waiting_approval','plan_missing'],true)
        ||in_array((string)($item['commitment_deadline_state']??''),['overdue','urgent'],true)
        ||(string)($item['hold_reason']??'')!=='';
}

function vp3_cognitive_value_calibration_v2570(array $rows,string $kind=''): array
{
    $ratios=[];
    foreach($rows as $row){
        if(!is_array($row)||empty($row['realization']['verified']))continue;
        $profile=(array)($row['profile']??[]);if($kind!==''&&(string)($profile['value_kind']??'')!==$kind)continue;
        if((string)($profile['value_kind']??'')==='money'){
            $expected=$profile['expected_value_micros']??null;$realized=$row['realization']['value_micros']??null;
        }else{
            $expected=$profile['expected_score']??null;$realized=$row['realization']['score_value']??null;
        }
        if($expected===null||$realized===null||(float)$expected<=0)continue;
        $ratios[]=max(0.0,min(2.0,(float)$realized/(float)$expected));
    }
    if(!$ratios)return ['sample_count'=>0,'median_realization_ratio'=>null,'source'=>'no_verified_value_samples'];
    sort($ratios,SORT_NUMERIC);$n=count($ratios);$mid=intdiv($n,2);
    $median=$n%2?$ratios[$mid]:(($ratios[$mid-1]+$ratios[$mid])/2);
    return ['sample_count'=>$n,'median_realization_ratio'=>round($median,4),'source'=>'verified_explicit_value_profiles'];
}

function vp3_cognitive_value_apply_v2570(
    PDO $pdo,array $user,array $items,array $capacity,array $economics,array $budgetGovernance,int $now=0
): array {
    $uid=(int)($user['id']??0);$profiles=vp3_cognitive_value_profile_rows_v2570($pdo,$user,true);
    $empty=[
        'build'=>VP3_COGNITIVE_VALUE_ROI_V2570,'contract'=>VP3_COGNITIVE_VALUE_ROI_CONTRACT_V2570,
        'configured'=>false,'focus'=>null,'goals'=>[],'profiles'=>[],'counts'=>[],'calibration'=>[],
        'projection_only'=>false,
    ];
    if($uid<1||!$profiles)return ['items'=>$items,'value_roi'=>$empty];

    $runIds=[];foreach($items as $item)foreach((array)($item['workflow_run_ids']??[]) as $runId)$runIds[]=(int)$runId;
    $runMeta=vp3_cognitive_value_run_metadata_v2570($pdo,$uid,$runIds);
    $profileMap=vp3_cognitive_value_profile_map_v2570($profiles);
    $econByGoal=[];foreach((array)($economics['goals']??[]) as $row)if(is_array($row))$econByGoal[(int)($row['goal_id']??0)]=$row;
    $accountUsage=(array)($economics['usage']??[]);
    $goalRows=[];$resolutions=[];$profileUsage=[];
    foreach($items as $item){
        if(!is_array($item))continue;$goalId=(int)($item['goal_id']??0);
        $resolved=vp3_cognitive_value_resolve_item_profile_v2570($item,$profileMap,$runMeta);
        $resolutions[$goalId]=$resolved;
        $profile=is_array($resolved['profile']??null)?$resolved['profile']:null;
        if($profile)$profileUsage[(int)$profile['id']]=($profileUsage[(int)$profile['id']]??0)+1;
    }

    foreach($items as &$item){
        if(!is_array($item))continue;$goalId=(int)($item['goal_id']??0);
        $resolved=$resolutions[$goalId]??['profile'=>null,'source'=>'none','ambiguous'=>false];
        $profile=is_array($resolved['profile']??null)?$resolved['profile']:null;
        $sharedInherited=$profile
            &&(string)($profile['scope_kind']??'')!=='goal'
            &&(int)($profileUsage[(int)$profile['id']]??0)>1;
        $item['value_profile_id']=$profile?(int)$profile['id']:0;
        $item['value_profile_source']=(string)($resolved['source']??'none');
        $item['value_profile_ambiguous']=!empty($resolved['ambiguous'])||$sharedInherited;
        $item['value_profile_shared_inheritance']=$sharedInherited;
        $item['value_planning_adjustment']=0.0;
        $item['value_at_risk']=false;
        if(!$profile)continue;

        $econ=$econByGoal[$goalId]??null;
        $historical=$econ?max(0,(int)($econ['attributed_known_cost_micros']??0)):0;
        $historicalUnknown=$econ?max(0,(int)($econ['unknown_cost_requests']??0)):0;
        $remaining=function_exists('vp3_cognitive_budget_goal_projection_v2560')
            ?vp3_cognitive_budget_goal_projection_v2560($item,$econ,$accountUsage)
            :['cost_micros'=>null,'cost_known'=>false,'tokens'=>null,'tokens_known'=>false];
        $remainingCost=$remaining['cost_micros']??null;
        $expectedCostKnown=$remainingCost!==null&&$historicalUnknown===0;
        $expectedCost=$expectedCostKnown?$historical+max(0,(int)$remainingCost):null;
        $realization=vp3_cognitive_value_realization_v2570($pdo,$user,$profile);
        $expectedMoney=$profile['expected_value_micros'];
        $expectedScore=$profile['expected_score'];
        $expectedRoi=vp3_cognitive_value_roi_percent_v2570(
            $expectedMoney===null?null:(int)$expectedMoney,$expectedCost,(string)$profile['currency']
        );
        $realizedRoi=vp3_cognitive_value_roi_percent_v2570(
            $realization['value_micros']===null?null:(int)$realization['value_micros'],
            ($historicalUnknown===0&&$historical>0)?$historical:null,
            (string)($realization['currency']??$profile['currency'])
        );
        $adjustment=$sharedInherited?0.0:vp3_cognitive_value_planning_adjustment_v2570($item,$profile,$expectedCost);
        $atRisk=!$sharedInherited&&empty($realization['verified'])&&vp3_cognitive_value_item_at_risk_v2570($item);
        $item['value_planning_adjustment']=$adjustment;
        $item['value_at_risk']=$atRisk;
        $item['expected_value_micros']=$expectedMoney;
        $item['expected_value_score']=$expectedScore;
        $item['expected_roi_percent']=$expectedRoi;
        $goalRows[]=[
            'goal_id'=>$goalId,'title'=>(string)($item['title']??('Goal #'.$goalId)),
            'profile'=>$profile,'profile_source'=>(string)$resolved['source'],'ambiguous'=>!empty($resolved['ambiguous']),
            'shared_inherited'=>$sharedInherited,
            'realization'=>$realization,
            'historical_cost_micros'=>$historical,'historical_unknown_cost_requests'=>$historicalUnknown,
            'projected_remaining_cost_micros'=>$remainingCost,
            'expected_total_cost_micros'=>$expectedCost,'expected_cost_known'=>$expectedCostKnown,
            'expected_roi_percent'=>$expectedRoi,'realized_roi_percent'=>$realizedRoi,
            'planning_adjustment'=>$adjustment,'value_at_risk'=>$atRisk,
            'budget_hard_hold'=>!empty($item['budget_hard_hold']),
            'commitment_protected'=>(float)($item['commitment_protection_score']??0)>=0.65,
            'authority'=>'explicit_value_profile_plus_canonical_outcome_evidence',
        ];
    }
    unset($item);

    $profileRows=[];
    foreach($profiles as $profile){
        $realization=vp3_cognitive_value_realization_v2570($pdo,$user,$profile);
        $profileRows[]=['profile'=>$profile,'realization'=>$realization];
    }
    $calibration=[
        'money'=>vp3_cognitive_value_calibration_v2570($profileRows,'money'),
        'score'=>vp3_cognitive_value_calibration_v2570($profileRows,'score'),
    ];

    usort($goalRows,static function(array $a,array $b): int {
        $riskA=!empty($a['value_at_risk'])?0:1;$riskB=!empty($b['value_at_risk'])?0:1;
        if($riskA!==$riskB)return $riskA<=>$riskB;
        $x=((float)($b['planning_adjustment']??0))<=>((float)($a['planning_adjustment']??0));if($x!==0)return $x;
        return ((int)$a['goal_id'])<=>((int)$b['goal_id']);
    });
    $verified=array_values(array_filter($profileRows,static fn(array $x): bool=>!empty($x['realization']['verified'])));
    $atRisk=array_values(array_filter($goalRows,static fn(array $x): bool=>!empty($x['value_at_risk'])));
    $optimized=array_values(array_filter($goalRows,static fn(array $x): bool=>(float)($x['planning_adjustment']??0)!==0.0));
    $money=array_values(array_filter($profileRows,static fn(array $x): bool=>(string)($x['profile']['value_kind']??'')==='money'));
    $score=array_values(array_filter($profileRows,static fn(array $x): bool=>(string)($x['profile']['value_kind']??'')==='score'));

    return [
        'items'=>$items,
        'value_roi'=>[
            'build'=>VP3_COGNITIVE_VALUE_ROI_V2570,'contract'=>VP3_COGNITIVE_VALUE_ROI_CONTRACT_V2570,
            'configured'=>true,'focus'=>$goalRows[0]??($profileRows[0]??null),
            'goals'=>$goalRows,'profiles'=>$profileRows,'calibration'=>$calibration,
            'counts'=>[
                'profiles'=>count($profileRows),'money_profiles'=>count($money),'score_profiles'=>count($score),
                'verified_outcomes'=>count($verified),'value_at_risk'=>count($atRisk),
                'planning_adjusted'=>count($optimized),
                'ambiguous_goals'=>count(array_filter($items,static fn(array $x): bool=>!empty($x['value_profile_ambiguous']))),
            ],
            'policy'=>[
                'monetary_value_must_be_explicit_or_canonical'=>true,
                'no_inferred_money'=>true,'no_fx_conversion'=>true,
                'usd_ai_cost_only_for_direct_monetary_roi'=>true,
                'verified_execution_is_not_automatically_revenue'=>true,
                'declared_completion_value_requires_explicit_profile'=>true,
                'commitments_outrank_value_optimization'=>true,
                'hard_budgets_outrank_value_optimization'=>true,
                'ambiguous_inherited_value_is_advisory_only'=>true,
            ],
            'authority'=>[
                'value_configuration'=>'cognitive_value_profiles_v2570',
                'manual_value_evidence'=>'cognitive_value_events_v2570_append_only',
                'goal_verification'=>'agent_goal_review_v1714_verified_achievement',
                'workflow_verification'=>'agent_workflow_runs_objective_verification',
                'meeting_verification'=>'video_meeting_followthrough_closure_v18200',
                'canonical_profile_revenue'=>'profile_events_profile_revenue_v180',
                'economics'=>'cognitive_economics_v2550',
                'budget_governance'=>'cognitive_budget_governance_v2560',
                'commitments'=>'cognitive_commitment_protection_v2540',
                'portfolio_admission'=>'cognitive_portfolio_v2480',
                'claims_leases_execution_receipts'=>'agent_job_engine_v1900',
                'execution_authority'=>false,'billing_authority'=>false,
            ],
            'projection_only'=>false,
        ],
    ];
}

function vp3_cognitive_value_snapshot_v2570(PDO $pdo,array $user): array
{
    $empty=[
        'build'=>VP3_COGNITIVE_VALUE_ROI_V2570,'contract'=>VP3_COGNITIVE_VALUE_ROI_CONTRACT_V2570,
        'configured'=>false,'focus'=>null,'goals'=>[],'profiles'=>[],'counts'=>[],'calibration'=>[],
        'projection_only'=>false,
    ];
    if(!vp3_cognitive_value_schema_ready_v2570($pdo))return $empty;
    try{$portfolio=vp3_cognitive_portfolio_snapshot_v2480($pdo,$user);}catch(Throwable $e){return $empty;}
    return is_array($portfolio['value_roi']??null)?$portfolio['value_roi']:$empty;
}

function vp3_cognitive_value_context_item_v2570(PDO $pdo,array $user,string $namespace): ?array
{
    if($namespace!=='system')return null;
    $snapshot=vp3_cognitive_value_snapshot_v2570($pdo,$user);
    if(empty($snapshot['configured']))return null;
    $json=json_encode([
        'focus'=>$snapshot['focus']??null,'counts'=>$snapshot['counts']??[],
        'goals'=>array_slice((array)($snapshot['goals']??[]),0,6),
        'calibration'=>$snapshot['calibration']??[],
    ],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE);
    if(!is_string($json))return null;
    return vp3_cognitive_context_item_v2420(
        'value_roi','cognitive-value-roi:v2570','Outcome value and ROI',$json,95.996,
        ['direct'=>true,'ephemeral_projection'=>true,'instruction_authority'=>false]
    );
}

function vp3_cognitive_value_activity_projection_v2570(PDO $pdo,array $user,string $namespace): array
{
    if($namespace!=='system')return [
        'build'=>VP3_COGNITIVE_VALUE_ROI_V2570,'configured'=>false,'focus'=>null,
        'goals'=>[],'profiles'=>[],'counts'=>[],'calibration'=>[],'manage_url'=>'','projection_only'=>false,
    ];
    $snapshot=vp3_cognitive_value_snapshot_v2570($pdo,$user);
    return [
        'build'=>VP3_COGNITIVE_VALUE_ROI_V2570,'configured'=>!empty($snapshot['configured']),
        'focus'=>$snapshot['focus']??null,'goals'=>array_slice((array)($snapshot['goals']??[]),0,8),
        'profiles'=>array_slice((array)($snapshot['profiles']??[]),0,12),
        'counts'=>$snapshot['counts']??[],'calibration'=>$snapshot['calibration']??[],
        'manage_url'=>url('/outcome-value.php'),'projection_only'=>false,
    ];
}
