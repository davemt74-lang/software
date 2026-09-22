<?php
declare(strict_types=1);

const VP3_CLIENT_RELEASE_READINESS_V150 = 'client-release-readiness-v150-20260922';

function client_release_readiness_schema_ready_v150(?PDO $pdo=null): bool
{
    $pdo??=db();
    if(!$pdo)return false;
    foreach([
        'client_release_readiness_manifest_v150',
        'client_release_readiness_ci_v150',
        'client_release_readiness_snapshots_v150',
        'client_release_readiness_signoffs_v150',
    ] as $table){
        if(!client_release_table_ready_v110($pdo,$table))return false;
    }
    return true;
}

function client_release_readiness_ensure_schema_v150(?PDO $pdo=null): void
{
    $pdo??=db();
    if(!$pdo)throw new RuntimeException('Database connection is unavailable.');
    client_release_risk_ensure_schema_v140($pdo);

    $pdo->exec("CREATE TABLE IF NOT EXISTS client_release_readiness_manifest_v150 (
        product VARCHAR(40) NOT NULL,
        release_id BIGINT UNSIGNED NOT NULL,
        source_commit_sha CHAR(40) NOT NULL DEFAULT '',
        required_ci_checks_json LONGTEXT NULL,
        requires_migration TINYINT(1) NOT NULL DEFAULT 0,
        migration_reversible TINYINT(1) NOT NULL DEFAULT 1,
        migration_notes VARCHAR(3000) NOT NULL DEFAULT '',
        rollback_required TINYINT(1) NOT NULL DEFAULT 1,
        rollback_release_id BIGINT UNSIGNED NULL,
        rollback_plan VARCHAR(3000) NOT NULL DEFAULT '',
        rollback_waiver_reason VARCHAR(1000) NOT NULL DEFAULT '',
        compatibility_prerequisites VARCHAR(2000) NOT NULL DEFAULT '',
        known_issues_reviewed TINYINT(1) NOT NULL DEFAULT 0,
        compatibility_reviewed TINYINT(1) NOT NULL DEFAULT 0,
        canary_initial_percent TINYINT UNSIGNED NOT NULL DEFAULT 10,
        canary_observation_hours SMALLINT UNSIGNED NOT NULL DEFAULT 12,
        escalation_owner_user_id INT UNSIGNED NULL,
        operator_notes VARCHAR(3000) NOT NULL DEFAULT '',
        updated_by_user_id INT UNSIGNED NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY(product,release_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS client_release_readiness_ci_v150 (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        product VARCHAR(40) NOT NULL,
        release_id BIGINT UNSIGNED NOT NULL,
        check_name VARCHAR(180) NOT NULL,
        conclusion VARCHAR(20) NOT NULL DEFAULT 'pending',
        source_commit_sha CHAR(40) NOT NULL DEFAULT '',
        run_id VARCHAR(80) NOT NULL DEFAULT '',
        details_url VARCHAR(1000) NOT NULL DEFAULT '',
        evidence_notes VARCHAR(1000) NOT NULL DEFAULT '',
        verified_by_user_id INT UNSIGNED NULL,
        verified_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uq_client_release_readiness_ci (product,release_id,check_name),
        INDEX idx_client_release_readiness_ci_release (product,release_id,conclusion,updated_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS client_release_readiness_snapshots_v150 (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        product VARCHAR(40) NOT NULL,
        release_id BIGINT UNSIGNED NOT NULL,
        readiness_status VARCHAR(20) NOT NULL,
        gate_count SMALLINT UNSIGNED NOT NULL DEFAULT 0,
        passed_count SMALLINT UNSIGNED NOT NULL DEFAULT 0,
        warning_count SMALLINT UNSIGNED NOT NULL DEFAULT 0,
        blocked_count SMALLINT UNSIGNED NOT NULL DEFAULT 0,
        fingerprint_sha256 CHAR(64) NOT NULL,
        gates_json LONGTEXT NOT NULL,
        warnings_json LONGTEXT NULL,
        blockers_json LONGTEXT NULL,
        evaluated_by_user_id INT UNSIGNED NULL,
        evaluated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_client_release_readiness_snapshot (product,release_id,evaluated_at),
        INDEX idx_client_release_readiness_status (readiness_status,evaluated_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS client_release_readiness_signoffs_v150 (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        product VARCHAR(40) NOT NULL,
        release_id BIGINT UNSIGNED NOT NULL,
        readiness_snapshot_id BIGINT UNSIGNED NOT NULL,
        decision VARCHAR(32) NOT NULL,
        note VARCHAR(1500) NOT NULL DEFAULT '',
        actor_user_id INT UNSIGNED NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_client_release_readiness_signoff (product,release_id,created_at),
        INDEX idx_client_release_readiness_signoff_snapshot (readiness_snapshot_id,decision,created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

function client_release_readiness_default_ci_checks_v150(): array
{
    return ['Client Release Operations','Recovery Baseline'];
}

function client_release_readiness_default_manifest_v150(): array
{
    return [
        'source_commit_sha'=>'',
        'required_ci_checks_json'=>json_encode(client_release_readiness_default_ci_checks_v150()),
        'requires_migration'=>0,
        'migration_reversible'=>1,
        'migration_notes'=>'',
        'rollback_required'=>1,
        'rollback_release_id'=>null,
        'rollback_plan'=>'',
        'rollback_waiver_reason'=>'',
        'compatibility_prerequisites'=>'',
        'known_issues_reviewed'=>0,
        'compatibility_reviewed'=>0,
        'canary_initial_percent'=>10,
        'canary_observation_hours'=>12,
        'escalation_owner_user_id'=>null,
        'operator_notes'=>'',
    ];
}

function client_release_readiness_manifest_v150(PDO $pdo,string $product,int $releaseId): array
{
    $defaults=client_release_readiness_default_manifest_v150();
    if(!client_release_readiness_schema_ready_v150($pdo))return $defaults;
    $stmt=$pdo->prepare('SELECT * FROM client_release_readiness_manifest_v150 WHERE product=? AND release_id=? LIMIT 1');
    $stmt->execute([$product,$releaseId]);
    $row=$stmt->fetch();
    return $row?array_merge($defaults,$row):$defaults;
}

function client_release_readiness_required_ci_v150(array $manifest): array
{
    $raw=$manifest['required_ci_checks_json']??null;
    $decoded=is_string($raw)&&$raw!==''?json_decode($raw,true):null;
    if(!is_array($decoded))return client_release_readiness_default_ci_checks_v150();
    $out=[];
    foreach($decoded as $name){
        $name=mb_strimwidth(trim((string)$name),0,180,'');
        if($name!=='')$out[$name]=true;
    }
    return array_keys($out);
}

function client_release_readiness_manifest_update_v150(PDO $pdo,string $product,int $releaseId,array $input,int $actorUserId): array
{
    if(!client_release_product_valid_v110($product))throw new RuntimeException('Unsupported release product.');
    $release=client_release_release_row_v110($pdo,$product,$releaseId);
    if(!$release)throw new RuntimeException('Client release was not found.');
    client_release_readiness_ensure_schema_v150($pdo);

    $sha=strtolower(trim((string)($input['source_commit_sha']??'')));
    if($sha!==''&&!preg_match('/^[0-9a-f]{40}$/',$sha))throw new RuntimeException('Source commit SHA must be a full 40-character Git SHA.');

    $checksRaw=(string)($input['required_ci_checks']??'');
    $checks=[];
    foreach(preg_split('/[\r\n,]+/',$checksRaw)?:[] as $check){
        $check=mb_strimwidth(trim($check),0,180,'');
        if($check!=='')$checks[$check]=true;
    }
    if(!$checks)$checks=array_fill_keys(client_release_readiness_default_ci_checks_v150(),true);
    $checksJson=json_encode(array_keys($checks),JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);

    $requiresMigration=!empty($input['requires_migration'])?1:0;
    $migrationReversible=!empty($input['migration_reversible'])?1:0;
    $migrationNotes=mb_strimwidth(trim((string)($input['migration_notes']??'')),0,3000,'');
    $rollbackRequired=!empty($input['rollback_required'])?1:0;
    $rollbackReleaseId=max(0,(int)($input['rollback_release_id']??0));
    $rollbackPlan=mb_strimwidth(trim((string)($input['rollback_plan']??'')),0,3000,'');
    $rollbackWaiver=mb_strimwidth(trim((string)($input['rollback_waiver_reason']??'')),0,1000,'');
    $compat=mb_strimwidth(trim((string)($input['compatibility_prerequisites']??'')),0,2000,'');
    $knownReviewed=!empty($input['known_issues_reviewed'])?1:0;
    $compatReviewed=!empty($input['compatibility_reviewed'])?1:0;
    $canary=max(1,min(25,(int)($input['canary_initial_percent']??10)));
    $hours=max(1,min(168,(int)($input['canary_observation_hours']??12)));
    $owner=max(0,(int)($input['escalation_owner_user_id']??0));
    if($owner<1)$owner=$actorUserId>0?$actorUserId:0;
    $notes=mb_strimwidth(trim((string)($input['operator_notes']??'')),0,3000,'');

    if($rollbackReleaseId>0){
        $rollback=client_release_release_row_v110($pdo,$product,$rollbackReleaseId);
        if(!$rollback||(string)($rollback['channel']??'stable')!==(string)($release['channel']??'stable')||$rollbackReleaseId===$releaseId){
            throw new RuntimeException('Rollback target must be a different release on the same channel.');
        }
    }

    $stmt=$pdo->prepare("INSERT INTO client_release_readiness_manifest_v150
      (product,release_id,source_commit_sha,required_ci_checks_json,requires_migration,migration_reversible,migration_notes,
       rollback_required,rollback_release_id,rollback_plan,rollback_waiver_reason,compatibility_prerequisites,
       known_issues_reviewed,compatibility_reviewed,canary_initial_percent,canary_observation_hours,
       escalation_owner_user_id,operator_notes,updated_by_user_id)
      VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
      ON DUPLICATE KEY UPDATE source_commit_sha=VALUES(source_commit_sha),required_ci_checks_json=VALUES(required_ci_checks_json),
       requires_migration=VALUES(requires_migration),migration_reversible=VALUES(migration_reversible),migration_notes=VALUES(migration_notes),
       rollback_required=VALUES(rollback_required),rollback_release_id=VALUES(rollback_release_id),rollback_plan=VALUES(rollback_plan),
       rollback_waiver_reason=VALUES(rollback_waiver_reason),compatibility_prerequisites=VALUES(compatibility_prerequisites),
       known_issues_reviewed=VALUES(known_issues_reviewed),compatibility_reviewed=VALUES(compatibility_reviewed),
       canary_initial_percent=VALUES(canary_initial_percent),canary_observation_hours=VALUES(canary_observation_hours),
       escalation_owner_user_id=VALUES(escalation_owner_user_id),operator_notes=VALUES(operator_notes),
       updated_by_user_id=VALUES(updated_by_user_id)");
    $stmt->execute([
        $product,$releaseId,$sha,$checksJson,$requiresMigration,$migrationReversible,$migrationNotes,
        $rollbackRequired,$rollbackReleaseId>0?$rollbackReleaseId:null,$rollbackPlan,$rollbackWaiver,$compat,
        $knownReviewed,$compatReviewed,$canary,$hours,$owner>0?$owner:null,$notes,$actorUserId>0?$actorUserId:null
    ]);
    client_release_audit_v110($pdo,$actorUserId,$product,$releaseId,'readiness_manifest_update','','',[
        'source_commit_sha'=>$sha,'required_ci_checks'=>array_keys($checks),'requires_migration'=>(bool)$requiresMigration,
        'migration_reversible'=>(bool)$migrationReversible,'rollback_required'=>(bool)$rollbackRequired,
        'rollback_release_id'=>$rollbackReleaseId>0?$rollbackReleaseId:null,'canary_initial_percent'=>$canary,
        'canary_observation_hours'=>$hours,'escalation_owner_user_id'=>$owner>0?$owner:null
    ]);
    return client_release_readiness_manifest_v150($pdo,$product,$releaseId);
}

function client_release_readiness_ci_rows_v150(PDO $pdo,string $product,int $releaseId): array
{
    if(!client_release_readiness_schema_ready_v150($pdo))return [];
    $stmt=$pdo->prepare('SELECT * FROM client_release_readiness_ci_v150 WHERE product=? AND release_id=? ORDER BY check_name');
    $stmt->execute([$product,$releaseId]);
    return $stmt->fetchAll()?:[];
}

function client_release_readiness_ci_save_v150(PDO $pdo,string $product,int $releaseId,array $input,int $actorUserId): array
{
    if(!client_release_release_row_v110($pdo,$product,$releaseId))throw new RuntimeException('Client release was not found.');
    client_release_readiness_ensure_schema_v150($pdo);
    $name=mb_strimwidth(trim((string)($input['check_name']??'')),0,180,'');
    if($name==='')throw new RuntimeException('CI check name is required.');
    $conclusion=strtolower(trim((string)($input['conclusion']??'pending')));
    if(!in_array($conclusion,['success','failure','cancelled','skipped','pending'],true))throw new RuntimeException('Choose a valid CI conclusion.');
    $sha=strtolower(trim((string)($input['source_commit_sha']??'')));
    if($sha!==''&&!preg_match('/^[0-9a-f]{40}$/',$sha))throw new RuntimeException('CI source commit SHA must be a full Git SHA.');
    $runId=mb_strimwidth(trim((string)($input['run_id']??'')),0,80,'');
    $url=mb_strimwidth(trim((string)($input['details_url']??'')),0,1000,'');
    if($url!==''&&!preg_match('#^https://#i',$url))throw new RuntimeException('CI details URL must use HTTPS.');
    $notes=mb_strimwidth(trim((string)($input['evidence_notes']??'')),0,1000,'');

    $stmt=$pdo->prepare("INSERT INTO client_release_readiness_ci_v150
      (product,release_id,check_name,conclusion,source_commit_sha,run_id,details_url,evidence_notes,verified_by_user_id)
      VALUES (?,?,?,?,?,?,?,?,?)
      ON DUPLICATE KEY UPDATE conclusion=VALUES(conclusion),source_commit_sha=VALUES(source_commit_sha),
       run_id=VALUES(run_id),details_url=VALUES(details_url),evidence_notes=VALUES(evidence_notes),
       verified_by_user_id=VALUES(verified_by_user_id),verified_at=NOW()");
    $stmt->execute([$product,$releaseId,$name,$conclusion,$sha,$runId,$url,$notes,$actorUserId>0?$actorUserId:null]);
    client_release_audit_v110($pdo,$actorUserId,$product,$releaseId,'readiness_ci_evidence','','',[
        'check_name'=>$name,'conclusion'=>$conclusion,'source_commit_sha'=>$sha,'run_id'=>$runId,'details_url'=>$url
    ]);
    return client_release_readiness_ci_rows_v150($pdo,$product,$releaseId);
}

function client_release_readiness_ci_delete_v150(PDO $pdo,int $evidenceId,int $actorUserId): void
{
    if($evidenceId<1||!client_release_readiness_schema_ready_v150($pdo))return;
    $stmt=$pdo->prepare('SELECT * FROM client_release_readiness_ci_v150 WHERE id=? LIMIT 1');
    $stmt->execute([$evidenceId]);$row=$stmt->fetch();
    if(!$row)throw new RuntimeException('CI evidence was not found.');
    $pdo->prepare('DELETE FROM client_release_readiness_ci_v150 WHERE id=?')->execute([$evidenceId]);
    client_release_audit_v110($pdo,$actorUserId,(string)$row['product'],(int)$row['release_id'],'readiness_ci_deleted','','',[
        'check_name'=>(string)$row['check_name'],'evidence_id'=>$evidenceId
    ]);
}

function client_release_readiness_artifacts_v150(PDO $pdo,string $product,array $release): array
{
    $gates=[];$version=(string)($release['version']??'');
    if($product==='browser_companion'){
        $path=(string)($release['package_path']??'');$expected=(string)($release['package_sha256']??'');$size=(int)($release['package_size']??0);
        if($path===''||!is_file($path))return [['key'=>'artifact','status'=>'blocked','detail'=>'Browser Companion ZIP package is missing from private release storage.']];
        $actual=hash_file('sha256',$path);$actualSize=filesize($path);
        if(!is_string($actual)||!hash_equals(strtolower($expected),strtolower($actual)))$gates[]=['key'=>'artifact_hash','status'=>'blocked','detail'=>'Browser Companion package SHA-256 does not match the stored release digest.'];
        else $gates[]=['key'=>'artifact_hash','status'=>'pass','detail'=>'Browser Companion package SHA-256 matches release metadata.'];
        if(!is_int($actualSize)||$actualSize!==$size)$gates[]=['key'=>'artifact_size','status'=>'blocked','detail'=>'Browser Companion package size does not match release metadata.'];
        else $gates[]=['key'=>'artifact_size','status'=>'pass','detail'=>'Browser Companion package size matches release metadata.'];
        try{
            $meta=chrome_extension_release_validate_zip($path);
            if((string)($meta['version']??'')!==$version)$gates[]=['key'=>'artifact_version','status'=>'blocked','detail'=>'Browser Companion manifest version does not match the release record.'];
            else $gates[]=['key'=>'artifact_version','status'=>'pass','detail'=>'Manifest V3 package identity and version are valid.'];
        }catch(Throwable $e){$gates[]=['key'=>'artifact_package','status'=>'blocked','detail'=>$e->getMessage()];}
        return $gates;
    }

    $seen=0;
    foreach([
        'portable'=>['path'=>'portable_path','sha'=>'portable_sha256','size'=>'portable_size'],
        'installer'=>['path'=>'installer_path','sha'=>'installer_sha256','size'=>'installer_size'],
    ] as $label=>$keys){
        $path=(string)($release[$keys['path']]??'');
        if($path==='')continue;
        $seen++;
        $expected=(string)($release[$keys['sha']]??'');$size=(int)($release[$keys['size']]??0);
        if(!is_file($path)){$gates[]=['key'=>'artifact_'.$label,'status'=>'blocked','detail'=>"HomeServer {$label} artifact is missing from private release storage."];continue;}
        $actual=hash_file('sha256',$path);$actualSize=filesize($path);
        if(!is_string($actual)||!hash_equals(strtolower($expected),strtolower($actual)))$gates[]=['key'=>'artifact_'.$label.'_hash','status'=>'blocked','detail'=>"HomeServer {$label} SHA-256 does not match release metadata."];
        else $gates[]=['key'=>'artifact_'.$label.'_hash','status'=>'pass','detail'=>"HomeServer {$label} SHA-256 matches release metadata."];
        if(!is_int($actualSize)||$actualSize!==$size)$gates[]=['key'=>'artifact_'.$label.'_size','status'=>'blocked','detail'=>"HomeServer {$label} size does not match release metadata."];
        else $gates[]=['key'=>'artifact_'.$label.'_size','status'=>'pass','detail'=>"HomeServer {$label} size matches release metadata."];
        try{homeserver_vp3_validate_pe_file($path);$gates[]=['key'=>'artifact_'.$label.'_pe','status'=>'pass','detail'=>"HomeServer {$label} passes Windows PE validation."];}
        catch(Throwable $e){$gates[]=['key'=>'artifact_'.$label.'_pe','status'=>'blocked','detail'=>$e->getMessage()];}
    }
    if($seen<1)$gates[]=['key'=>'artifact','status'=>'blocked','detail'=>'HomeServer release has no portable or installer artifact.'];
    return $gates;
}

function client_release_readiness_rollback_gate_v150(PDO $pdo,string $product,array $release,array $manifest): array
{
    if(empty($manifest['rollback_required'])){
        return trim((string)$manifest['rollback_waiver_reason'])!==''
            ?['key'=>'rollback','status'=>'warning','detail'=>'Rollback target waived: '.(string)$manifest['rollback_waiver_reason']]
            :['key'=>'rollback','status'=>'blocked','detail'=>'Rollback is waived but no waiver reason is documented.'];
    }
    $rid=(int)($manifest['rollback_release_id']??0);
    if($rid<1)return ['key'=>'rollback','status'=>'blocked','detail'=>'A known-good rollback release is required.'];
    $target=client_release_release_row_v110($pdo,$product,$rid);
    if(!$target||(string)($target['channel']??'stable')!==(string)($release['channel']??'stable')||empty($target['is_published'])){
        return ['key'=>'rollback','status'=>'blocked','detail'=>'Rollback release must be a published release on the same channel.'];
    }
    if(client_release_incident_active_for_release_v130($pdo,$product,$rid)){
        return ['key'=>'rollback','status'=>'blocked','detail'=>'Rollback target is currently governed by an active incident.'];
    }
    if(trim((string)$manifest['rollback_plan'])==='')return ['key'=>'rollback','status'=>'blocked','detail'=>'Rollback plan is not documented.'];
    return ['key'=>'rollback','status'=>'pass','detail'=>'Known-good rollback target and operator plan are documented.'];
}

function client_release_readiness_ci_gates_v150(PDO $pdo,string $product,int $releaseId,array $manifest): array
{
    $rows=client_release_readiness_ci_rows_v150($pdo,$product,$releaseId);$byName=[];
    foreach($rows as $row)$byName[(string)$row['check_name']]=$row;
    $required=client_release_readiness_required_ci_v150($manifest);$gates=[];
    $manifestSha=(string)($manifest['source_commit_sha']??'');
    if($manifestSha==='')$gates[]=['key'=>'source_commit','status'=>'blocked','detail'=>'A full source commit SHA is required before CI evidence can be trusted.'];
    else $gates[]=['key'=>'source_commit','status'=>'pass','detail'=>'Source commit SHA is recorded.'];
    foreach($required as $name){
        $row=$byName[$name]??null;
        if(!$row){$gates[]=['key'=>'ci:'.$name,'status'=>'blocked','detail'=>"Required CI check '{$name}' has no evidence."];continue;}
        if((string)$row['conclusion']!=='success'){$gates[]=['key'=>'ci:'.$name,'status'=>'blocked','detail'=>"Required CI check '{$name}' is ".(string)$row['conclusion'].'.'];continue;}
        if($manifestSha!==''&&(string)$row['source_commit_sha']!==$manifestSha){$gates[]=['key'=>'ci:'.$name,'status'=>'blocked','detail'=>"Required CI check '{$name}' was verified against a different source commit."];continue;}
        $gates[]=['key'=>'ci:'.$name,'status'=>'pass','detail'=>"Required CI check '{$name}' passed for the recorded source commit."];
    }
    return $gates;
}

function client_release_readiness_risk_profile_exists_v150(PDO $pdo,string $product,int $releaseId): bool
{
    if(!client_release_risk_schema_ready_v140($pdo))return false;
    $stmt=$pdo->prepare('SELECT 1 FROM client_release_risk_profiles_v140 WHERE product=? AND release_id=? LIMIT 1');
    $stmt->execute([$product,$releaseId]);
    return (bool)$stmt->fetchColumn();
}

function client_release_readiness_fingerprint_payload_v150(PDO $pdo,string $product,int $releaseId): array
{
    $release=client_release_release_row_v110($pdo,$product,$releaseId)??[];
    $rollout=client_release_rollout_for_v110($pdo,$product,$releaseId,$release);
    $manifest=client_release_readiness_manifest_v150($pdo,$product,$releaseId);
    $profile=client_release_risk_profile_v140($pdo,$product,$releaseId);
    $ci=client_release_readiness_ci_rows_v150($pdo,$product,$releaseId);
    $artifact=$product==='browser_companion'
        ?[
            'package_sha256'=>(string)($release['package_sha256']??''),'package_size'=>(int)($release['package_size']??0),
            'manifest_version'=>(int)($release['manifest_version']??0),
        ]
        :[
            'portable_sha256'=>(string)($release['portable_sha256']??''),'portable_size'=>(int)($release['portable_size']??0),
            'installer_sha256'=>(string)($release['installer_sha256']??''),'installer_size'=>(int)($release['installer_size']??0),
        ];
    $ciFingerprint=[];
    foreach($ci as $row)$ciFingerprint[]=[
        'check_name'=>(string)$row['check_name'],'conclusion'=>(string)$row['conclusion'],
        'source_commit_sha'=>(string)$row['source_commit_sha'],'run_id'=>(string)$row['run_id'],'details_url'=>(string)$row['details_url']
    ];
    return [
        'release'=>[
            'version'=>(string)($release['version']??''),'channel'=>(string)($release['channel']??''),
            'release_notes'=>(string)($release['release_notes']??''),'artifact'=>$artifact
        ],
        'rollout'=>[
            'summary'=>(string)($rollout['summary']??''),'known_issues'=>(string)($rollout['known_issues']??''),
            'compatibility_notes'=>(string)($rollout['compatibility_notes']??'')
        ],
        'manifest'=>[
            'source_commit_sha'=>(string)$manifest['source_commit_sha'],
            'required_ci_checks'=>client_release_readiness_required_ci_v150($manifest),
            'requires_migration'=>(bool)$manifest['requires_migration'],'migration_reversible'=>(bool)$manifest['migration_reversible'],
            'migration_notes'=>(string)$manifest['migration_notes'],'rollback_required'=>(bool)$manifest['rollback_required'],
            'rollback_release_id'=>(int)($manifest['rollback_release_id']??0),'rollback_plan'=>(string)$manifest['rollback_plan'],
            'rollback_waiver_reason'=>(string)$manifest['rollback_waiver_reason'],
            'compatibility_prerequisites'=>(string)$manifest['compatibility_prerequisites'],
            'known_issues_reviewed'=>(bool)$manifest['known_issues_reviewed'],'compatibility_reviewed'=>(bool)$manifest['compatibility_reviewed'],
            'canary_initial_percent'=>(int)$manifest['canary_initial_percent'],'canary_observation_hours'=>(int)$manifest['canary_observation_hours'],
            'escalation_owner_user_id'=>(int)($manifest['escalation_owner_user_id']??0),'operator_notes'=>(string)$manifest['operator_notes']
        ],
        'risk_profile'=>$profile,
        'ci'=>$ciFingerprint,
    ];
}

function client_release_readiness_fingerprint_v150(PDO $pdo,string $product,int $releaseId): string
{
    return hash('sha256',json_encode(client_release_readiness_fingerprint_payload_v150($pdo,$product,$releaseId),JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));
}

function client_release_readiness_risk_accepted_v150(PDO $pdo,string $product,int $releaseId): bool
{
    if(!client_release_risk_schema_ready_v140($pdo))return false;
    $snapshot=client_release_risk_latest_snapshot_v140($pdo,$product,$releaseId);
    if(!$snapshot||!in_array((string)($snapshot['risk_level']??''),['high','critical'],true))return false;
    $stmt=$pdo->prepare("SELECT 1 FROM client_release_risk_reviews_v140
      WHERE product=? AND release_id=? AND risk_snapshot_id=? AND decision='accepted_risk'
      ORDER BY id DESC LIMIT 1");
    $stmt->execute([$product,$releaseId,(int)$snapshot['id']]);
    return (bool)$stmt->fetchColumn();
}

function client_release_readiness_evaluate_v150(PDO $pdo,string $product,int $releaseId): array
{
    if(!client_release_product_valid_v110($product))throw new RuntimeException('Unsupported release product.');
    $release=client_release_release_row_v110($pdo,$product,$releaseId);
    if(!$release)throw new RuntimeException('Client release was not found.');
    client_release_readiness_ensure_schema_v150($pdo);
    $rollout=client_release_rollout_for_v110($pdo,$product,$releaseId,$release);
    $manifest=client_release_readiness_manifest_v150($pdo,$product,$releaseId);
    $profile=client_release_risk_profile_v140($pdo,$product,$releaseId);
    $gates=[];

    foreach(client_release_readiness_artifacts_v150($pdo,$product,$release) as $gate)$gates[]=$gate;
    foreach(client_release_readiness_ci_gates_v150($pdo,$product,$releaseId,$manifest) as $gate)$gates[]=$gate;

    $notes=trim((string)($release['release_notes']??''));
    $gates[]=$notes!==''?['key'=>'release_notes','status'=>'pass','detail'=>'Release notes are present.']:['key'=>'release_notes','status'=>'blocked','detail'=>'Release notes are required.'];
    $gates[]=trim((string)($rollout['summary']??''))!==''?['key'=>'rollout_summary','status'=>'pass','detail'=>'Operator rollout summary is present.']:['key'=>'rollout_summary','status'=>'blocked','detail'=>'Rollout summary is required.'];
    $gates[]=!empty($manifest['known_issues_reviewed'])?['key'=>'known_issues','status'=>'pass','detail'=>'Known issues were explicitly reviewed.']:['key'=>'known_issues','status'=>'blocked','detail'=>'Known issues review has not been confirmed.'];
    $gates[]=!empty($manifest['compatibility_reviewed'])?['key'=>'compatibility_review','status'=>'pass','detail'=>'Compatibility prerequisites were explicitly reviewed.']:['key'=>'compatibility_review','status'=>'blocked','detail'=>'Compatibility review has not been confirmed.'];

    if(!client_release_readiness_risk_profile_exists_v150($pdo,$product,$releaseId)||($profile['change_size']??'unknown')==='unknown'){
        $gates[]=['key'=>'risk_profile','status'=>'blocked','detail'=>'v1.40 change-risk profile is incomplete.'];
    }else{
        $gates[]=['key'=>'risk_profile','status'=>'pass','detail'=>'v1.40 change-risk profile is complete.'];
    }
    $risk=client_release_risk_assess_v140($pdo,$product,$releaseId);
    $riskLevel=(string)($risk['risk_level']??'critical');
    if($riskLevel==='critical'){
        if(client_release_readiness_risk_accepted_v150($pdo,$product,$releaseId))$gates[]=['key'=>'risk_assessment','status'=>'warning','detail'=>'Current v1.40 risk is critical but has an explicit accepted-risk review; conservative Canary and warning sign-off are required.'];
        else $gates[]=['key'=>'risk_assessment','status'=>'blocked','detail'=>'Current v1.40 risk is critical; record an explicit accepted-risk review before preflight sign-off.'];
    }elseif($riskLevel==='high')$gates[]=['key'=>'risk_assessment','status'=>'warning','detail'=>'Current v1.40 risk is high; use a conservative Canary and document operator review.'];
    else $gates[]=['key'=>'risk_assessment','status'=>'pass','detail'=>'Current v1.40 risk is '.$riskLevel.'.'];

    if(!empty($manifest['requires_migration'])){
        if(trim((string)$manifest['migration_notes'])==='')$gates[]=['key'=>'migration','status'=>'blocked','detail'=>'Migration is required but migration/upgrade notes are missing.'];
        elseif(empty($manifest['migration_reversible'])&&trim((string)$manifest['rollback_plan'])==='')$gates[]=['key'=>'migration','status'=>'blocked','detail'=>'Migration is irreversible and no rollback/forward-recovery plan is documented.'];
        elseif(empty($manifest['migration_reversible']))$gates[]=['key'=>'migration','status'=>'warning','detail'=>'Migration is irreversible; rollback implications are documented and require operator attention.'];
        else $gates[]=['key'=>'migration','status'=>'pass','detail'=>'Migration requirements and reversibility are documented.'];
    }else $gates[]=['key'=>'migration','status'=>'pass','detail'=>'No release migration is declared.'];

    $gates[]=client_release_readiness_rollback_gate_v150($pdo,$product,$release,$manifest);

    if((int)($manifest['escalation_owner_user_id']??0)<1)$gates[]=['key'=>'canary_owner','status'=>'blocked','detail'=>'Canary escalation owner is required.'];
    else $gates[]=['key'=>'canary_owner','status'=>'pass','detail'=>'Canary escalation owner is assigned.'];
    $canary=(int)$manifest['canary_initial_percent'];$hours=(int)$manifest['canary_observation_hours'];
    $gates[]=$canary>=1&&$canary<=25&&$hours>=1
        ?['key'=>'canary_plan','status'=>'pass','detail'=>"Canary plan: {$canary}% initial cohort with {$hours}h observation."]
        :['key'=>'canary_plan','status'=>'blocked','detail'=>'Canary percentage and observation window are invalid.'];

    $healthPolicy=client_release_health_policy_v120($pdo,$product,$releaseId);
    $gates[]=[
        'key'=>'health_gates','status'=>'pass',
        'detail'=>'v1.20 health gates available: max failure '.client_release_health_percent_v120((int)$healthPolicy['max_failure_rate_bps']).', min install '.client_release_health_percent_v120((int)$healthPolicy['min_install_rate_bps']).'.'
    ];

    if(client_release_incident_active_for_release_v130($pdo,$product,$releaseId))$gates[]=['key'=>'active_incident','status'=>'blocked','detail'=>'An active v1.30 release incident blocks readiness.'];
    else $gates[]=['key'=>'active_incident','status'=>'pass','detail'=>'No active release incident.'];

    $blocked=[];$warnings=[];$passed=0;
    foreach($gates as $gate){
        if($gate['status']==='blocked')$blocked[]=$gate['detail'];
        elseif($gate['status']==='warning')$warnings[]=$gate['detail'];
        else $passed++;
    }
    $status=$blocked?'blocked':($warnings?'warnings':'ready');
    return [
        'product'=>$product,'release_id'=>$releaseId,'version'=>(string)$release['version'],'channel'=>(string)$release['channel'],
        'status'=>$status,'gates'=>$gates,'passed_count'=>$passed,'warning_count'=>count($warnings),'blocked_count'=>count($blocked),
        'warnings'=>$warnings,'blockers'=>$blocked,'manifest'=>$manifest,'risk'=>$risk,'fingerprint'=>client_release_readiness_fingerprint_v150($pdo,$product,$releaseId)
    ];
}

function client_release_readiness_snapshot_v150(PDO $pdo,string $product,int $releaseId,int $actorUserId): array
{
    $result=client_release_readiness_evaluate_v150($pdo,$product,$releaseId);
    $stmt=$pdo->prepare("INSERT INTO client_release_readiness_snapshots_v150
      (product,release_id,readiness_status,gate_count,passed_count,warning_count,blocked_count,fingerprint_sha256,
       gates_json,warnings_json,blockers_json,evaluated_by_user_id)
      VALUES (?,?,?,?,?,?,?,?,?,?,?,?)");
    $stmt->execute([
        $product,$releaseId,(string)$result['status'],count($result['gates']),(int)$result['passed_count'],
        (int)$result['warning_count'],(int)$result['blocked_count'],(string)$result['fingerprint'],
        json_encode($result['gates'],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE),
        json_encode($result['warnings'],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE),
        json_encode($result['blockers'],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE),
        $actorUserId>0?$actorUserId:null
    ]);
    $result['snapshot_id']=(int)$pdo->lastInsertId();
    client_release_audit_v110($pdo,$actorUserId,$product,$releaseId,'readiness_evaluated','','',(array_intersect_key($result,array_flip(['snapshot_id','status','passed_count','warning_count','blocked_count','fingerprint']))));
    return $result;
}

function client_release_readiness_snapshot_by_id_v150(PDO $pdo,int $snapshotId): ?array
{
    if($snapshotId<1||!client_release_readiness_schema_ready_v150($pdo))return null;
    $stmt=$pdo->prepare('SELECT * FROM client_release_readiness_snapshots_v150 WHERE id=? LIMIT 1');
    $stmt->execute([$snapshotId]);$row=$stmt->fetch();
    return $row?:null;
}

function client_release_readiness_latest_snapshot_v150(PDO $pdo,string $product,int $releaseId): ?array
{
    if(!client_release_readiness_schema_ready_v150($pdo))return null;
    $stmt=$pdo->prepare('SELECT * FROM client_release_readiness_snapshots_v150 WHERE product=? AND release_id=? ORDER BY id DESC LIMIT 1');
    $stmt->execute([$product,$releaseId]);$row=$stmt->fetch();
    return $row?:null;
}

function client_release_readiness_signoff_v150(PDO $pdo,string $product,int $releaseId,int $snapshotId,string $decision,string $note,int $actorUserId): void
{
    $snapshot=client_release_readiness_snapshot_by_id_v150($pdo,$snapshotId);
    if(!$snapshot||(string)$snapshot['product']!==$product||(int)$snapshot['release_id']!==$releaseId)throw new RuntimeException('Readiness snapshot was not found.');
    if((string)$snapshot['fingerprint_sha256']!==client_release_readiness_fingerprint_v150($pdo,$product,$releaseId))throw new RuntimeException('Readiness inputs changed. Evaluate the release again before sign-off.');
    $decision=strtolower(trim($decision));
    if(!in_array($decision,['approved','approved_with_warnings','rejected'],true))throw new RuntimeException('Choose a valid readiness decision.');
    $status=(string)$snapshot['readiness_status'];
    if($status==='blocked'&&$decision!=='rejected')throw new RuntimeException('Blocked readiness snapshots cannot be approved.');
    if($status==='warnings'&&$decision==='approved')throw new RuntimeException('A warning snapshot must be approved with warnings or rejected.');
    if($status==='ready'&&$decision==='approved_with_warnings')throw new RuntimeException('A warning override is not needed for a fully ready snapshot.');
    $note=mb_strimwidth(trim($note),0,1500,'');
    if(in_array($decision,['approved_with_warnings','rejected'],true)&&$note==='')throw new RuntimeException('Add an operator note for warning approval or rejection.');
    $stmt=$pdo->prepare('INSERT INTO client_release_readiness_signoffs_v150 (product,release_id,readiness_snapshot_id,decision,note,actor_user_id) VALUES (?,?,?,?,?,?)');
    $stmt->execute([$product,$releaseId,$snapshotId,$decision,$note,$actorUserId>0?$actorUserId:null]);
    client_release_audit_v110($pdo,$actorUserId,$product,$releaseId,'readiness_signoff','','',[
        'snapshot_id'=>$snapshotId,'decision'=>$decision,'note'=>$note,'readiness_status'=>$status
    ]);
}

function client_release_readiness_latest_signoff_v150(PDO $pdo,string $product,int $releaseId,int $snapshotId): ?array
{
    if(!client_release_readiness_schema_ready_v150($pdo))return null;
    $stmt=$pdo->prepare('SELECT * FROM client_release_readiness_signoffs_v150 WHERE product=? AND release_id=? AND readiness_snapshot_id=? ORDER BY id DESC LIMIT 1');
    $stmt->execute([$product,$releaseId,$snapshotId]);$row=$stmt->fetch();
    return $row?:null;
}

function client_release_readiness_current_v150(PDO $pdo,string $product,int $releaseId): array
{
    if(!client_release_readiness_schema_ready_v150($pdo))return ['ready'=>false,'status'=>'missing','reason'=>'v1.50 readiness schema is not installed.'];
    $live=client_release_readiness_evaluate_v150($pdo,$product,$releaseId);
    if((string)$live['status']==='blocked')return ['ready'=>false,'status'=>'blocked','reason'=>'Current preflight inputs have blocking gates.','evaluation'=>$live];
    $snapshot=client_release_readiness_latest_snapshot_v150($pdo,$product,$releaseId);
    if(!$snapshot)return ['ready'=>false,'status'=>'incomplete','reason'=>'No v1.50 readiness snapshot exists.'];
    $currentFingerprint=client_release_readiness_fingerprint_v150($pdo,$product,$releaseId);
    if(!hash_equals((string)$snapshot['fingerprint_sha256'],$currentFingerprint)){
        return ['ready'=>false,'status'=>'stale','reason'=>'Readiness inputs changed after the last snapshot. Re-evaluate and sign off again.','snapshot'=>$snapshot];
    }
    if((string)$snapshot['readiness_status']==='blocked')return ['ready'=>false,'status'=>'blocked','reason'=>'The latest readiness snapshot has blocking gates.','snapshot'=>$snapshot];
    $signoff=client_release_readiness_latest_signoff_v150($pdo,$product,$releaseId,(int)$snapshot['id']);
    if(!$signoff||!in_array((string)$signoff['decision'],['approved','approved_with_warnings'],true)){
        return ['ready'=>false,'status'=>'unsigned','reason'=>'The latest readiness snapshot has not been approved.','snapshot'=>$snapshot,'signoff'=>$signoff];
    }
    return [
        'ready'=>true,'status'=>(string)$snapshot['readiness_status'],'reason'=>'Current readiness snapshot is approved and unchanged.',
        'snapshot'=>$snapshot,'signoff'=>$signoff
    ];
}

function client_release_readiness_admin_summary_v150(PDO $pdo): array
{
    $out=['browser_companion'=>[],'homeserver'=>[]];
    foreach(array_keys($out) as $product){
        foreach(client_release_admin_rollouts_v110($pdo,$product) as $release){
            $rid=(int)$release['id'];
            try{
                $snapshot=client_release_readiness_latest_snapshot_v150($pdo,$product,$rid);
                $signoff=$snapshot?client_release_readiness_latest_signoff_v150($pdo,$product,$rid,(int)$snapshot['id']):null;
                $fingerprint=client_release_readiness_fingerprint_v150($pdo,$product,$rid);
                $state='incomplete';$reason='No readiness snapshot exists.';
                if($snapshot){
                    if(!hash_equals((string)$snapshot['fingerprint_sha256'],$fingerprint)){$state='stale';$reason='Readiness inputs changed after evaluation.';}
                    elseif((string)$snapshot['readiness_status']==='blocked'){$state='blocked';$reason='Latest snapshot contains blocking gates.';}
                    elseif(!$signoff||!in_array((string)$signoff['decision'],['approved','approved_with_warnings'],true)){$state='unsigned';$reason='Latest snapshot has not been approved.';}
                    else{$state=(string)$snapshot['readiness_status'];$reason='Snapshot is signed; live artifact integrity is rechecked when readiness is enforced.';}
                }
                $out[$product][]=[
                    'product'=>$product,'release_id'=>$rid,'version'=>(string)($release['version']??''),
                    'channel'=>(string)($release['channel']??'stable'),'release'=>$release,
                    'rollout'=>client_release_rollout_for_v110($pdo,$product,$rid,$release),
                    'manifest'=>client_release_readiness_manifest_v150($pdo,$product,$rid),
                    'latest_snapshot'=>$snapshot,'signoff'=>$signoff,'state'=>$state,'state_reason'=>$reason,
                    'ci'=>client_release_readiness_ci_rows_v150($pdo,$product,$rid),
                ];
            }catch(Throwable $e){
                $out[$product][]=['product'=>$product,'release_id'=>$rid,'version'=>(string)($release['version']??''),'error'=>$e->getMessage()];
            }
        }
    }
    return $out;
}
