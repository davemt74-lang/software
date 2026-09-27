<?php
declare(strict_types=1);

/**
 * Tracky V2.78 Section 7 — Cloud federation permissions, consent and site boundaries.
 *
 * Cloud mirrors HomeServer-governed policy and enforces it at relay time.
 * It cannot originate grants, consent changes, revocations, or physical authority.
 */
const VP3_TRACKY_FEDERATION_POLICY_V278='vp3-tracky-federation-policy-v278-20260927';
const VP3_TRACKY_FEDERATION_POLICY_PROTOCOL_V278='physical_federation_policy.v1';

function tracky_v278_policy_scopes(): array
{
    return [
      'semantic_world_read','agent_context_read','history_query','identity_continuity_read',
      'identity_linking','person_recognition','voice_matching','remote_observation',
    ];
}

function tracky_v278_policy_consent_scopes(): array
{
    return ['person_recognition','voice_matching','identity_linking'];
}

function tracky_v278_policy_schema_ready(?PDO $pdo=null): bool
{
    $pdo??=db();
    if(!$pdo)return false;
    foreach([
      'tracky_cloud_federation_policy_state','tracky_cloud_federation_site_policies',
      'tracky_cloud_federation_permissions','tracky_cloud_recognition_consents',
      'tracky_cloud_federation_policy_revocations','tracky_cloud_federation_policy_history',
    ] as $table){
        if(!table_exists($table))return false;
    }
    return true;
}

function tracky_v278_policy_ensure_schema(?PDO $pdo=null): void
{
    $pdo??=db();
    if(!$pdo)throw new RuntimeException('Database connection is unavailable.');

    $pdo->exec("CREATE TABLE IF NOT EXISTS tracky_cloud_federation_policy_state (
      user_id INT UNSIGNED NOT NULL,
      governing_site_uuid CHAR(36) NOT NULL,
      revision BIGINT UNSIGNED NOT NULL DEFAULT 0,
      revocation_epoch BIGINT UNSIGNED NOT NULL DEFAULT 0,
      reporting_site_id VARCHAR(100) NOT NULL,
      authority_device_uuid CHAR(36) NOT NULL,
      authority_epoch BIGINT UNSIGNED NOT NULL DEFAULT 0,
      projection_hash CHAR(64) NOT NULL DEFAULT '',
      updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      PRIMARY KEY (user_id,governing_site_uuid),
      INDEX idx_tracky_policy_state_reporter (user_id,reporting_site_id,updated_at),
      CONSTRAINT fk_tracky_policy_state_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS tracky_cloud_federation_site_policies (
      user_id INT UNSIGNED NOT NULL,
      site_uuid CHAR(36) NOT NULL,
      revision BIGINT UNSIGNED NOT NULL DEFAULT 0,
      mode VARCHAR(30) NOT NULL DEFAULT 'private',
      allow_federation TINYINT(1) NOT NULL DEFAULT 0,
      allow_remote_observation TINYINT(1) NOT NULL DEFAULT 0,
      default_identity_visibility VARCHAR(30) NOT NULL DEFAULT 'none',
      allowed_peer_sites_json LONGTEXT NOT NULL,
      reporting_site_id VARCHAR(100) NOT NULL,
      authority_device_uuid CHAR(36) NOT NULL,
      authority_epoch BIGINT UNSIGNED NOT NULL DEFAULT 0,
      semantic_hash CHAR(64) NOT NULL,
      policy_json LONGTEXT NOT NULL,
      updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      PRIMARY KEY (user_id,site_uuid),
      CONSTRAINT fk_tracky_policy_site_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS tracky_cloud_federation_permissions (
      user_id INT UNSIGNED NOT NULL,
      source_site_uuid CHAR(36) NOT NULL,
      destination_site_uuid CHAR(36) NOT NULL,
      scope VARCHAR(60) NOT NULL,
      status VARCHAR(30) NOT NULL DEFAULT 'revoked',
      revision BIGINT UNSIGNED NOT NULL DEFAULT 0,
      reason VARCHAR(200) NOT NULL DEFAULT '',
      reporting_site_id VARCHAR(100) NOT NULL,
      authority_device_uuid CHAR(36) NOT NULL,
      authority_epoch BIGINT UNSIGNED NOT NULL DEFAULT 0,
      semantic_hash CHAR(64) NOT NULL,
      grant_json LONGTEXT NOT NULL,
      updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      PRIMARY KEY (user_id,source_site_uuid,destination_site_uuid,scope),
      INDEX idx_tracky_policy_grants_destination (user_id,destination_site_uuid,status,scope),
      CONSTRAINT fk_tracky_policy_grant_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS tracky_cloud_recognition_consents (
      user_id INT UNSIGNED NOT NULL,
      site_uuid CHAR(36) NOT NULL,
      canonical_identity_uuid CHAR(36) NOT NULL,
      scope VARCHAR(60) NOT NULL,
      status VARCHAR(30) NOT NULL DEFAULT 'pending',
      revision BIGINT UNSIGNED NOT NULL DEFAULT 0,
      source VARCHAR(80) NOT NULL DEFAULT 'user',
      reason VARCHAR(200) NOT NULL DEFAULT '',
      reporting_site_id VARCHAR(100) NOT NULL,
      authority_device_uuid CHAR(36) NOT NULL,
      authority_epoch BIGINT UNSIGNED NOT NULL DEFAULT 0,
      semantic_hash CHAR(64) NOT NULL,
      consent_json LONGTEXT NOT NULL,
      updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      PRIMARY KEY (user_id,site_uuid,canonical_identity_uuid,scope),
      INDEX idx_tracky_policy_consent_identity (user_id,canonical_identity_uuid,site_uuid,status),
      CONSTRAINT fk_tracky_policy_consent_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS tracky_cloud_federation_policy_revocations (
      user_id INT UNSIGNED NOT NULL,
      revocation_key VARCHAR(420) NOT NULL,
      governing_site_uuid CHAR(36) NOT NULL,
      revision BIGINT UNSIGNED NOT NULL DEFAULT 0,
      revocation_epoch BIGINT UNSIGNED NOT NULL DEFAULT 0,
      reason VARCHAR(200) NOT NULL DEFAULT '',
      reporting_site_id VARCHAR(100) NOT NULL,
      semantic_hash CHAR(64) NOT NULL,
      revocation_json LONGTEXT NOT NULL,
      updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      PRIMARY KEY (user_id,revocation_key),
      INDEX idx_tracky_policy_revocation_site (user_id,governing_site_uuid,revocation_epoch),
      CONSTRAINT fk_tracky_policy_revocation_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS tracky_cloud_federation_policy_history (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
      user_id INT UNSIGNED NOT NULL,
      governing_site_uuid CHAR(36) NOT NULL,
      event_type VARCHAR(80) NOT NULL,
      revision BIGINT UNSIGNED NOT NULL DEFAULT 0,
      revocation_epoch BIGINT UNSIGNED NOT NULL DEFAULT 0,
      snapshot_json LONGTEXT NOT NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      INDEX idx_tracky_policy_history_site (user_id,governing_site_uuid,id),
      CONSTRAINT fk_tracky_policy_history_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

function tracky_v278_policy_uuid(mixed $value,string $label): string
{
    $value=strtolower(mb_strimwidth(trim((string)($value??'')),0,64,''));
    if(!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',$value)){
        throw new RuntimeException('Tracky federation policy '.$label.' must be a UUID.');
    }
    return $value;
}

function tracky_v278_policy_text(mixed $value,int $max=200): string
{
    return mb_strimwidth(trim((string)($value??'')),0,max(1,$max),'');
}

function tracky_v278_policy_scope(mixed $value): string
{
    $scope=strtolower(tracky_v278_policy_text($value,60));
    if(!in_array($scope,tracky_v278_policy_scopes(),true)){
        throw new RuntimeException('Tracky federation permission scope is unsupported.');
    }
    return $scope;
}

function tracky_v278_policy_consent_scope(mixed $value): string
{
    $scope=strtolower(tracky_v278_policy_text($value,60));
    if(!in_array($scope,tracky_v278_policy_consent_scopes(),true)){
        throw new RuntimeException('Tracky federation recognition consent scope is unsupported.');
    }
    return $scope;
}

function tracky_v278_policy_normalize(array $input): array
{
    tracky_cloud_v270_assert_governed_value($input,'federation_policy');
    if((string)($input['protocol']??'')!==VP3_TRACKY_FEDERATION_POLICY_PROTOCOL_V278){
        throw new RuntimeException('Tracky federation policy protocol is unsupported.');
    }
    if(empty($input['semantic_only'])||empty($input['summary_only'])||!empty($input['raw_perception'])){
        throw new RuntimeException('Tracky Cloud accepts semantic policy summaries only; raw perception is forbidden.');
    }
    if((string)($input['authority_assignment']??'')!=='local_site_policy'){
        throw new RuntimeException('Tracky federation policy authority must remain local to the governing site.');
    }
    if((string)($input['cloud_role']??'')!=='mirror_relay_enforcer'
      ||!empty($input['cloud_can_grant'])||!empty($input['cloud_can_revoke'])||!empty($input['cloud_can_change_consent'])){
        throw new RuntimeException('Tracky Cloud may mirror and enforce policy but cannot mutate it.');
    }

    $site=tracky_v278_policy_uuid($input['governing_site_id']??'','governing site id');
    $device=tracky_v278_policy_uuid($input['governing_authority_device_id']??'','governing authority device id');
    $epoch=max(0,(int)($input['governing_authority_epoch']??0));
    if($epoch<1)throw new RuntimeException('Tracky federation policy authority epoch is required.');

    $sites=[];
    foreach(array_slice(is_array($input['sites']??null)?$input['sites']:[],0,8) as $row){
        if(!is_array($row))throw new RuntimeException('Tracky federation site policy is invalid.');
        $rowSite=tracky_v278_policy_uuid($row['site_id']??'','site policy id');
        if($rowSite!==$site)throw new RuntimeException('Tracky federation projection may contain only the governing local site policy.');
        $mode=strtolower(tracky_v278_policy_text($row['mode']??'private',30));
        if(!in_array($mode,['private','household','team','shared'],true))$mode='private';
        $visibility=strtolower(tracky_v278_policy_text($row['default_identity_visibility']??'none',30));
        if(!in_array($visibility,['none','anonymous','consented'],true))$visibility='none';
        $peers=[];
        foreach(array_slice(is_array($row['allowed_peer_sites']??null)?$row['allowed_peer_sites']:[],0,128) as $peer){
            $peer=tracky_v278_policy_uuid($peer,'allowed peer site id');
            if($peer!==$site&&!in_array($peer,$peers,true))$peers[]=$peer;
        }
        sort($peers,SORT_STRING);
        $sites[]=[
          'site_id'=>$site,'revision'=>max(1,(int)($row['revision']??1)),
          'mode'=>$mode,'allow_federation'=>!empty($row['allow_federation']),
          'allow_remote_observation'=>!empty($row['allow_remote_observation']),
          'default_identity_visibility'=>$visibility,'allowed_peer_sites'=>$peers,
        ];
    }

    $grants=[];
    foreach(array_slice(is_array($input['grants']??null)?$input['grants']:[],0,512) as $row){
        if(!is_array($row))throw new RuntimeException('Tracky federation permission record is invalid.');
        $source=tracky_v278_policy_uuid($row['source_site_id']??'','permission source site id');
        if($source!==$site)throw new RuntimeException('Tracky federation projection may contain only grants governed by its local site.');
        $destination=tracky_v278_policy_uuid($row['destination_site_id']??'','permission destination site id');
        if($destination===$source)throw new RuntimeException('Tracky federation permission requires distinct sites.');
        $status=strtolower(tracky_v278_policy_text($row['status']??'revoked',30));
        if(!in_array($status,['granted','revoked'],true))$status='revoked';
        $grants[]=[
          'source_site_id'=>$source,'destination_site_id'=>$destination,
          'scope'=>tracky_v278_policy_scope($row['scope']??''),'status'=>$status,
          'revision'=>max(1,(int)($row['revision']??1)),
          'reason'=>tracky_v278_policy_text($row['reason']??'',200),
        ];
    }

    $consents=[];
    foreach(array_slice(is_array($input['consents']??null)?$input['consents']:[],0,1024) as $row){
        if(!is_array($row))throw new RuntimeException('Tracky recognition consent record is invalid.');
        $consentSite=tracky_v278_policy_uuid($row['site_id']??'','consent site id');
        if($consentSite!==$site)throw new RuntimeException('Tracky federation projection may contain only local-site consent decisions.');
        $status=strtolower(tracky_v278_policy_text($row['status']??'pending',30));
        if(!in_array($status,['pending','granted','denied','revoked'],true))$status='pending';
        $consents[]=[
          'site_id'=>$site,
          'canonical_identity_id'=>tracky_v278_policy_uuid($row['canonical_identity_id']??'','canonical identity id'),
          'scope'=>tracky_v278_policy_consent_scope($row['scope']??''),
          'status'=>$status,'revision'=>max(1,(int)($row['revision']??1)),
          'source'=>tracky_v278_policy_text($row['source']??'user',80),
          'reason'=>tracky_v278_policy_text($row['reason']??'',200),
        ];
    }

    $revocations=[];
    foreach(array_slice(is_array($input['revocations']??null)?$input['revocations']:[],0,2048) as $row){
        if(!is_array($row))throw new RuntimeException('Tracky federation policy revocation is invalid.');
        $governing=tracky_v278_policy_uuid($row['governing_site_id']??'','revocation governing site id');
        if($governing!==$site)throw new RuntimeException('Tracky federation projection may contain only local-site revocations.');
        $key=tracky_v278_policy_text($row['revocation_key']??($row['key']??''),420);
        if($key===''||!str_starts_with($key,'grant:')&&!str_starts_with($key,'consent:')){
            throw new RuntimeException('Tracky federation policy revocation key is invalid.');
        }
        $revocations[]=[
          'revocation_key'=>$key,'governing_site_id'=>$site,
          'revision'=>max(1,(int)($row['revision']??1)),
          'revocation_epoch'=>max(1,(int)($row['revocation_epoch']??1)),
          'reason'=>tracky_v278_policy_text($row['reason']??'',200),
        ];
    }

    return [
      'protocol'=>VP3_TRACKY_FEDERATION_POLICY_PROTOCOL_V278,'schema_version'=>1,
      'revision'=>max(0,(int)($input['revision']??0)),
      'revocation_epoch'=>max(0,(int)($input['revocation_epoch']??0)),
      'governing_site_id'=>$site,'governing_authority_device_id'=>$device,
      'governing_authority_epoch'=>$epoch,
      'sites'=>$sites,'grants'=>$grants,'consents'=>$consents,'revocations'=>$revocations,
      'semantic_only'=>true,'summary_only'=>true,'authority_assignment'=>'local_site_policy',
      'cloud_role'=>'mirror_relay_enforcer','cloud_can_grant'=>false,'cloud_can_revoke'=>false,
      'cloud_can_change_consent'=>false,'raw_perception'=>false,
    ];
}

function tracky_v278_policy_topology_authority(PDO $pdo,int $userId,string $reportingSiteId,string $siteId): array
{
    $report=tracky_v278_report($pdo,$userId,$reportingSiteId);
    foreach((array)($report['topology']['sites']??[]) as $site){
        if(!is_array($site)||($site['id']??'')!==$siteId)continue;
        return [
          'device_id'=>(string)($site['authority_device_id']??''),
          'epoch'=>(int)($site['authority_epoch']??0),
          'status'=>(string)($site['status']??'active'),
        ];
    }
    throw new RuntimeException('Tracky federation policy governing site is not present in the topology mirror.');
}

function tracky_v278_policy_hash(array $value): string
{
    return hash('sha256',tracky_cloud_v270_json($value));
}

function tracky_v278_policy_ingest(PDO $pdo,int $userId,string $reportingSiteId,array $input): array
{
    if($userId<1)throw new RuntimeException('Authentication required.');
    $reportingSiteId=tracky_cloud_v270_site_id($reportingSiteId);
    if(!tracky_v278_policy_schema_ready($pdo))tracky_v278_policy_ensure_schema($pdo);
    $projection=tracky_v278_policy_normalize($input);
    $source=$projection['governing_site_id'];
    $authority=tracky_v278_policy_topology_authority($pdo,$userId,$reportingSiteId,$source);
    if($authority['status']!=='active'
      ||$authority['device_id']!==$projection['governing_authority_device_id']
      ||$authority['epoch']!==$projection['governing_authority_epoch']){
        throw new RuntimeException('Tracky federation policy authority does not match the current topology mirror.');
    }

    $state=$pdo->prepare('SELECT revision,revocation_epoch,projection_hash FROM tracky_cloud_federation_policy_state WHERE user_id=? AND governing_site_uuid=? LIMIT 1');
    $state->execute([$userId,$source]);$priorState=$state->fetch();
    if($priorState&&$projection['revocation_epoch']<(int)$priorState['revocation_epoch']){
        return ['accepted'=>true,'changed'=>0,'stale'=>1,'idempotent'=>0];
    }

    $changed=0;$stale=0;$idempotent=0;
    foreach($projection['sites'] as $row){
        $json=tracky_cloud_v270_json($row);$hash=hash('sha256',$json);
        $q=$pdo->prepare('SELECT revision,semantic_hash FROM tracky_cloud_federation_site_policies WHERE user_id=? AND site_uuid=? LIMIT 1');
        $q->execute([$userId,$source]);$prior=$q->fetch();
        if($prior&&$row['revision']<(int)$prior['revision']){$stale++;continue;}
        if($prior&&$row['revision']===(int)$prior['revision']){
            if(!hash_equals((string)$prior['semantic_hash'],$hash))throw new RuntimeException('Tracky federation site policy revision conflicts with the Cloud mirror.');
            $idempotent++;continue;
        }
        $stmt=$pdo->prepare("INSERT INTO tracky_cloud_federation_site_policies
          (user_id,site_uuid,revision,mode,allow_federation,allow_remote_observation,
           default_identity_visibility,allowed_peer_sites_json,reporting_site_id,
           authority_device_uuid,authority_epoch,semantic_hash,policy_json)
          VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)
          ON DUPLICATE KEY UPDATE revision=VALUES(revision),mode=VALUES(mode),
           allow_federation=VALUES(allow_federation),allow_remote_observation=VALUES(allow_remote_observation),
           default_identity_visibility=VALUES(default_identity_visibility),
           allowed_peer_sites_json=VALUES(allowed_peer_sites_json),reporting_site_id=VALUES(reporting_site_id),
           authority_device_uuid=VALUES(authority_device_uuid),authority_epoch=VALUES(authority_epoch),
           semantic_hash=VALUES(semantic_hash),policy_json=VALUES(policy_json),updated_at=CURRENT_TIMESTAMP");
        $stmt->execute([
          $userId,$source,$row['revision'],$row['mode'],$row['allow_federation']?1:0,
          $row['allow_remote_observation']?1:0,$row['default_identity_visibility'],
          tracky_cloud_v270_json($row['allowed_peer_sites']),$reportingSiteId,
          $projection['governing_authority_device_id'],$projection['governing_authority_epoch'],$hash,$json
        ]);$changed++;
    }

    foreach($projection['revocations'] as $row){
        $json=tracky_cloud_v270_json($row);$hash=hash('sha256',$json);
        $q=$pdo->prepare('SELECT revision,revocation_epoch,semantic_hash FROM tracky_cloud_federation_policy_revocations WHERE user_id=? AND revocation_key=? LIMIT 1');
        $q->execute([$userId,$row['revocation_key']]);$prior=$q->fetch();
        if($prior&&($row['revocation_epoch']<(int)$prior['revocation_epoch']
          ||($row['revocation_epoch']===(int)$prior['revocation_epoch']&&$row['revision']<(int)$prior['revision']))){$stale++;continue;}
        if($prior&&$row['revocation_epoch']===(int)$prior['revocation_epoch']&&$row['revision']===(int)$prior['revision']){
            if(!hash_equals((string)$prior['semantic_hash'],$hash))throw new RuntimeException('Tracky federation revocation revision conflicts with the Cloud mirror.');
            $idempotent++;continue;
        }
        $stmt=$pdo->prepare("INSERT INTO tracky_cloud_federation_policy_revocations
          (user_id,revocation_key,governing_site_uuid,revision,revocation_epoch,reason,reporting_site_id,semantic_hash,revocation_json)
          VALUES (?,?,?,?,?,?,?,?,?)
          ON DUPLICATE KEY UPDATE governing_site_uuid=VALUES(governing_site_uuid),revision=VALUES(revision),
           revocation_epoch=VALUES(revocation_epoch),reason=VALUES(reason),reporting_site_id=VALUES(reporting_site_id),
           semantic_hash=VALUES(semantic_hash),revocation_json=VALUES(revocation_json),updated_at=CURRENT_TIMESTAMP");
        $stmt->execute([$userId,$row['revocation_key'],$source,$row['revision'],$row['revocation_epoch'],$row['reason'],$reportingSiteId,$hash,$json]);
        $changed++;
    }

    foreach($projection['grants'] as $row){
        $revocationKey='grant:'.$row['source_site_id'].'|'.$row['destination_site_id'].'|'.$row['scope'];
        $rq=$pdo->prepare('SELECT revision FROM tracky_cloud_federation_policy_revocations WHERE user_id=? AND revocation_key=? LIMIT 1');
        $rq->execute([$userId,$revocationKey]);$revokedRevision=(int)($rq->fetchColumn()?:0);
        if($row['status']==='granted'&&$row['revision']<=$revokedRevision){$stale++;continue;}
        $json=tracky_cloud_v270_json($row);$hash=hash('sha256',$json);
        $q=$pdo->prepare('SELECT revision,semantic_hash FROM tracky_cloud_federation_permissions WHERE user_id=? AND source_site_uuid=? AND destination_site_uuid=? AND scope=? LIMIT 1');
        $q->execute([$userId,$source,$row['destination_site_id'],$row['scope']]);$prior=$q->fetch();
        if($prior&&$row['revision']<(int)$prior['revision']){$stale++;continue;}
        if($prior&&$row['revision']===(int)$prior['revision']){
            if(!hash_equals((string)$prior['semantic_hash'],$hash))throw new RuntimeException('Tracky federation permission revision conflicts with the Cloud mirror.');
            $idempotent++;continue;
        }
        $stmt=$pdo->prepare("INSERT INTO tracky_cloud_federation_permissions
          (user_id,source_site_uuid,destination_site_uuid,scope,status,revision,reason,reporting_site_id,
           authority_device_uuid,authority_epoch,semantic_hash,grant_json)
          VALUES (?,?,?,?,?,?,?,?,?,?,?,?)
          ON DUPLICATE KEY UPDATE status=VALUES(status),revision=VALUES(revision),reason=VALUES(reason),
           reporting_site_id=VALUES(reporting_site_id),authority_device_uuid=VALUES(authority_device_uuid),
           authority_epoch=VALUES(authority_epoch),semantic_hash=VALUES(semantic_hash),
           grant_json=VALUES(grant_json),updated_at=CURRENT_TIMESTAMP");
        $stmt->execute([
          $userId,$source,$row['destination_site_id'],$row['scope'],$row['status'],$row['revision'],$row['reason'],
          $reportingSiteId,$projection['governing_authority_device_id'],$projection['governing_authority_epoch'],$hash,$json
        ]);$changed++;
    }

    foreach($projection['consents'] as $row){
        $revocationKey='consent:'.$source.'|'.$row['canonical_identity_id'].'|'.$row['scope'];
        $rq=$pdo->prepare('SELECT revision FROM tracky_cloud_federation_policy_revocations WHERE user_id=? AND revocation_key=? LIMIT 1');
        $rq->execute([$userId,$revocationKey]);$revokedRevision=(int)($rq->fetchColumn()?:0);
        if($row['status']==='granted'&&$row['revision']<=$revokedRevision){$stale++;continue;}
        $json=tracky_cloud_v270_json($row);$hash=hash('sha256',$json);
        $q=$pdo->prepare('SELECT revision,semantic_hash FROM tracky_cloud_recognition_consents WHERE user_id=? AND site_uuid=? AND canonical_identity_uuid=? AND scope=? LIMIT 1');
        $q->execute([$userId,$source,$row['canonical_identity_id'],$row['scope']]);$prior=$q->fetch();
        if($prior&&$row['revision']<(int)$prior['revision']){$stale++;continue;}
        if($prior&&$row['revision']===(int)$prior['revision']){
            if(!hash_equals((string)$prior['semantic_hash'],$hash))throw new RuntimeException('Tracky recognition consent revision conflicts with the Cloud mirror.');
            $idempotent++;continue;
        }
        $stmt=$pdo->prepare("INSERT INTO tracky_cloud_recognition_consents
          (user_id,site_uuid,canonical_identity_uuid,scope,status,revision,source,reason,reporting_site_id,
           authority_device_uuid,authority_epoch,semantic_hash,consent_json)
          VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)
          ON DUPLICATE KEY UPDATE status=VALUES(status),revision=VALUES(revision),source=VALUES(source),
           reason=VALUES(reason),reporting_site_id=VALUES(reporting_site_id),
           authority_device_uuid=VALUES(authority_device_uuid),authority_epoch=VALUES(authority_epoch),
           semantic_hash=VALUES(semantic_hash),consent_json=VALUES(consent_json),updated_at=CURRENT_TIMESTAMP");
        $stmt->execute([
          $userId,$source,$row['canonical_identity_id'],$row['scope'],$row['status'],$row['revision'],
          $row['source'],$row['reason'],$reportingSiteId,$projection['governing_authority_device_id'],
          $projection['governing_authority_epoch'],$hash,$json
        ]);$changed++;
    }

    $projectionHash=tracky_v278_policy_hash($projection);
    $stmt=$pdo->prepare("INSERT INTO tracky_cloud_federation_policy_state
      (user_id,governing_site_uuid,revision,revocation_epoch,reporting_site_id,authority_device_uuid,authority_epoch,projection_hash)
      VALUES (?,?,?,?,?,?,?,?)
      ON DUPLICATE KEY UPDATE revision=GREATEST(revision,VALUES(revision)),
       revocation_epoch=GREATEST(revocation_epoch,VALUES(revocation_epoch)),
       reporting_site_id=VALUES(reporting_site_id),authority_device_uuid=VALUES(authority_device_uuid),
       authority_epoch=VALUES(authority_epoch),projection_hash=VALUES(projection_hash),updated_at=CURRENT_TIMESTAMP");
    $stmt->execute([
      $userId,$source,$projection['revision'],$projection['revocation_epoch'],$reportingSiteId,
      $projection['governing_authority_device_id'],$projection['governing_authority_epoch'],$projectionHash
    ]);
    if($changed>0){
        $history=$pdo->prepare("INSERT INTO tracky_cloud_federation_policy_history
          (user_id,governing_site_uuid,event_type,revision,revocation_epoch,snapshot_json)
          VALUES (?,?,?,?,?,?)");
        $history->execute([$userId,$source,'policy_projection_ingested',$projection['revision'],$projection['revocation_epoch'],tracky_cloud_v270_json($projection)]);
    }
    return ['accepted'=>true,'changed'=>$changed,'stale'=>$stale,'idempotent'=>$idempotent];
}

function tracky_v278_policy_site(PDO $pdo,int $userId,string $siteId): ?array
{
    if(!tracky_v278_policy_schema_ready($pdo))return null;
    $q=$pdo->prepare('SELECT policy_json FROM tracky_cloud_federation_site_policies WHERE user_id=? AND site_uuid=? LIMIT 1');
    $q->execute([$userId,$siteId]);$decoded=json_decode((string)($q->fetchColumn()?:''),true);
    return is_array($decoded)?$decoded:null;
}

function tracky_v278_policy_recognition_decision(PDO $pdo,int $userId,string $siteId,string $canonicalIdentityId,string $scope): array
{
    $siteId=tracky_v278_policy_uuid($siteId,'consent site id');
    $canonicalIdentityId=tracky_v278_policy_uuid($canonicalIdentityId,'canonical identity id');
    $scope=tracky_v278_policy_consent_scope($scope);
    $q=$pdo->prepare('SELECT status,revision FROM tracky_cloud_recognition_consents WHERE user_id=? AND site_uuid=? AND canonical_identity_uuid=? AND scope=? LIMIT 1');
    $q->execute([$userId,$siteId,$canonicalIdentityId,$scope]);$row=$q->fetch();
    if(!$row)return ['allowed'=>false,'reason'=>'consent_required'];
    $key='consent:'.$siteId.'|'.$canonicalIdentityId.'|'.$scope;
    $rq=$pdo->prepare('SELECT revision FROM tracky_cloud_federation_policy_revocations WHERE user_id=? AND revocation_key=? LIMIT 1');
    $rq->execute([$userId,$key]);$revokedRevision=(int)($rq->fetchColumn()?:0);
    if((string)$row['status']!=='granted'||$revokedRevision>=(int)$row['revision']){
        return ['allowed'=>false,'reason'=>(string)$row['status']==='denied'?'consent_denied':'consent_revoked'];
    }
    return ['allowed'=>true,'reason'=>'site_scoped_consent','revision'=>(int)$row['revision']];
}

function tracky_v278_policy_decision(PDO $pdo,int $userId,string $sourceSiteId,string $destinationSiteId,string $scope,?string $canonicalIdentityId=null): array
{
    $source=tracky_v278_policy_uuid($sourceSiteId,'source site id');
    $destination=tracky_v278_policy_uuid($destinationSiteId,'destination site id');
    $scope=tracky_v278_policy_scope($scope);
    $policy=tracky_v278_policy_site($pdo,$userId,$source);
    if(!$policy)return ['allowed'=>false,'reason'=>'source_site_policy_missing'];
    if(empty($policy['allow_federation']))return ['allowed'=>false,'reason'=>'source_site_federation_disabled'];
    if(!in_array($destination,(array)($policy['allowed_peer_sites']??[]),true)){
        return ['allowed'=>false,'reason'=>'destination_not_allowed_peer'];
    }
    if($scope==='remote_observation'&&empty($policy['allow_remote_observation'])){
        return ['allowed'=>false,'reason'=>'remote_observation_disabled'];
    }
    $q=$pdo->prepare('SELECT status,revision FROM tracky_cloud_federation_permissions WHERE user_id=? AND source_site_uuid=? AND destination_site_uuid=? AND scope=? LIMIT 1');
    $q->execute([$userId,$source,$destination,$scope]);$grant=$q->fetch();
    if(!$grant)return ['allowed'=>false,'reason'=>'permission_not_granted'];
    $key='grant:'.$source.'|'.$destination.'|'.$scope;
    $rq=$pdo->prepare('SELECT revision,revocation_epoch FROM tracky_cloud_federation_policy_revocations WHERE user_id=? AND revocation_key=? LIMIT 1');
    $rq->execute([$userId,$key]);$revoked=$rq->fetch();
    if((string)$grant['status']!=='granted'||($revoked&&(int)$revoked['revision']>=(int)$grant['revision'])){
        return ['allowed'=>false,'reason'=>'permission_revoked'];
    }
    if($canonicalIdentityId!==null&&in_array($scope,tracky_v278_policy_consent_scopes(),true)){
        $consent=tracky_v278_policy_recognition_decision($pdo,$userId,$source,$canonicalIdentityId,$scope);
        if(empty($consent['allowed']))return $consent;
    }
    $sq=$pdo->prepare('SELECT revocation_epoch FROM tracky_cloud_federation_policy_state WHERE user_id=? AND governing_site_uuid=? LIMIT 1');
    $sq->execute([$userId,$source]);
    return [
      'allowed'=>true,'reason'=>'explicit_site_grant','grant_revision'=>(int)$grant['revision'],
      'policy_revision'=>(int)($policy['revision']??0),'revocation_epoch'=>(int)($sq->fetchColumn()?:0),
    ];
}

function tracky_v278_policy_filter_world_fragment(PDO $pdo,int $userId,string $sourceSiteId,string $destinationSiteId,array $fragment): ?array
{
    $decision=tracky_v278_policy_decision($pdo,$userId,$sourceSiteId,$destinationSiteId,'semantic_world_read');
    if(empty($decision['allowed']))return null;
    $denied=[];
    $entities=[];
    foreach((array)($fragment['entities']??[]) as $entity){
        if(!is_array($entity))continue;
        if(strtolower((string)($entity['type']??''))==='person'){
            $id=(string)($entity['local_id']??'');if($id!=='')$denied[$id]=true;
            continue;
        }
        $entities[]=$entity;
    }
    $relations=[];
    foreach((array)($fragment['relations']??[]) as $relation){
        if(!is_array($relation))continue;
        if(isset($denied[(string)($relation['subject_local_id']??'')]))continue;
        if(isset($denied[(string)($relation['object_local_id']??'')]))continue;
        $relations[]=$relation;
    }
    $filtered=$fragment;
    $filtered['entities']=$entities;
    $filtered['relations']=$relations;
    $filtered['context']=[];
    unset($filtered['fingerprint']);
    return tracky_v278_world_fragment($filtered);
}

function tracky_v278_policy_build_relay(PDO $pdo,int $userId,array $federationRequest): array
{
    if(!tracky_v278_policy_schema_ready($pdo))tracky_v278_policy_ensure_schema($pdo);
    $request=tracky_v278_sync_normalize($federationRequest);
    $destination=(string)($request['local_site_id']??'');
    if(empty($request['available'])||$destination===''){
        return [
          'protocol'=>'physical_federation_policy_relay.v1','schema_version'=>1,
          'destination_site_id'=>'','projections'=>[],'cloud_role'=>'mirror_relay_enforcer',
          'cloud_can_grant'=>false,'cloud_can_revoke'=>false,'cloud_can_change_consent'=>false,
        ];
    }

    $sources=[];
    $q=$pdo->prepare("SELECT DISTINCT source_site_uuid
      FROM tracky_cloud_federation_permissions
      WHERE user_id=? AND destination_site_uuid=? AND source_site_uuid<>?
      ORDER BY source_site_uuid LIMIT 128");
    $q->execute([$userId,$destination,$destination]);
    foreach($q->fetchAll()?:[] as $row){
        $source=(string)($row['source_site_uuid']??'');
        if($source!=='')$sources[$source]=true;
    }

    $projections=[];
    foreach(array_keys($sources) as $source){
        $sq=$pdo->prepare("SELECT * FROM tracky_cloud_federation_policy_state
          WHERE user_id=? AND governing_site_uuid=? LIMIT 1");
        $sq->execute([$userId,$source]);$state=$sq->fetch();
        if(!$state)continue;

        $pq=$pdo->prepare("SELECT policy_json FROM tracky_cloud_federation_site_policies
          WHERE user_id=? AND site_uuid=? LIMIT 1");
        $pq->execute([$userId,$source]);
        $policy=json_decode((string)($pq->fetchColumn()?:''),true);
        if(!is_array($policy))continue;

        $gq=$pdo->prepare("SELECT grant_json FROM tracky_cloud_federation_permissions
          WHERE user_id=? AND source_site_uuid=? AND destination_site_uuid=?
          ORDER BY scope");
        $gq->execute([$userId,$source,$destination]);
        $grants=[];
        foreach($gq->fetchAll()?:[] as $row){
            $v=json_decode((string)($row['grant_json']??''),true);
            if(is_array($v))$grants[]=$v;
        }

        $identityIds=[];
        if(table_exists('tracky_cloud_identity_links')){
            $iq=$pdo->prepare("SELECT DISTINCT canonical_identity_uuid FROM tracky_cloud_identity_links
              WHERE user_id=? AND governing_site_uuid=?
                AND ((left_site_uuid=? AND right_site_uuid=?) OR (left_site_uuid=? AND right_site_uuid=?))");
            $iq->execute([$userId,$source,$source,$destination,$destination,$source]);
            foreach($iq->fetchAll()?:[] as $row){
                $id=(string)($row['canonical_identity_uuid']??'');
                if($id!=='')$identityIds[$id]=true;
            }
        }

        $consents=[];
        foreach(array_keys($identityIds) as $canonicalId){
            $cq=$pdo->prepare("SELECT consent_json FROM tracky_cloud_recognition_consents
              WHERE user_id=? AND site_uuid=? AND canonical_identity_uuid=?
              ORDER BY scope");
            $cq->execute([$userId,$source,$canonicalId]);
            foreach($cq->fetchAll()?:[] as $row){
                $v=json_decode((string)($row['consent_json']??''),true);
                if(is_array($v))$consents[]=$v;
            }
        }

        $revocations=[];
        $rq=$pdo->prepare("SELECT revocation_json,revocation_key FROM tracky_cloud_federation_policy_revocations
          WHERE user_id=? AND governing_site_uuid=? ORDER BY revocation_epoch,revision");
        $rq->execute([$userId,$source]);
        foreach($rq->fetchAll()?:[] as $row){
            $key=(string)($row['revocation_key']??'');
            $include=str_starts_with($key,'grant:'.$source.'|'.$destination.'|');
            if(!$include&&str_starts_with($key,'consent:')){
                foreach(array_keys($identityIds) as $canonicalId){
                    if(str_contains($key,'|'.$canonicalId.'|')){$include=true;break;}
                }
            }
            if(!$include)continue;
            $v=json_decode((string)($row['revocation_json']??''),true);
            if(is_array($v))$revocations[]=$v;
        }

        $projections[]=[
          'protocol'=>VP3_TRACKY_FEDERATION_POLICY_PROTOCOL_V278,'schema_version'=>1,
          'revision'=>(int)($state['revision']??0),
          'revocation_epoch'=>(int)($state['revocation_epoch']??0),
          'governing_site_id'=>$source,
          'governing_authority_device_id'=>(string)($state['authority_device_uuid']??''),
          'governing_authority_epoch'=>(int)($state['authority_epoch']??0),
          'sites'=>[$policy],'grants'=>$grants,'consents'=>$consents,'revocations'=>$revocations,
          'semantic_only'=>true,'summary_only'=>true,'authority_assignment'=>'local_site_policy',
          'cloud_role'=>'mirror_relay_enforcer','cloud_can_grant'=>false,
          'cloud_can_revoke'=>false,'cloud_can_change_consent'=>false,'raw_perception'=>false,
        ];
    }

    return [
      'protocol'=>'physical_federation_policy_relay.v1','schema_version'=>1,
      'destination_site_id'=>$destination,'projections'=>$projections,
      'cloud_role'=>'mirror_relay_enforcer','cloud_can_grant'=>false,
      'cloud_can_revoke'=>false,'cloud_can_change_consent'=>false,
    ];
}

function tracky_v278_policy_report(PDO $pdo,int $userId): array
{
    if($userId<1)return ['available'=>false,'protocol'=>VP3_TRACKY_FEDERATION_POLICY_PROTOCOL_V278];
    tracky_v278_policy_ensure_schema($pdo);
    $q=$pdo->prepare('SELECT policy_json FROM tracky_cloud_federation_site_policies WHERE user_id=? ORDER BY site_uuid');
    $q->execute([$userId]);$sites=[];
    foreach($q->fetchAll()?:[] as $row){$v=json_decode((string)$row['policy_json'],true);if(is_array($v))$sites[]=$v;}
    $q=$pdo->prepare('SELECT grant_json FROM tracky_cloud_federation_permissions WHERE user_id=? ORDER BY source_site_uuid,destination_site_uuid,scope');
    $q->execute([$userId]);$grants=[];
    foreach($q->fetchAll()?:[] as $row){$v=json_decode((string)$row['grant_json'],true);if(is_array($v))$grants[]=$v;}
    $q=$pdo->prepare('SELECT consent_json FROM tracky_cloud_recognition_consents WHERE user_id=? ORDER BY site_uuid,canonical_identity_uuid,scope');
    $q->execute([$userId]);$consents=[];
    foreach($q->fetchAll()?:[] as $row){$v=json_decode((string)$row['consent_json'],true);if(is_array($v))$consents[]=$v;}
    return [
      'available'=>!empty($sites)||!empty($grants)||!empty($consents),
      'protocol'=>VP3_TRACKY_FEDERATION_POLICY_PROTOCOL_V278,
      'sites'=>$sites,'grants'=>$grants,'consents'=>$consents,
      'deny_by_default'=>true,'semantic_only'=>true,'raw_perception'=>false,
      'cloud_role'=>'mirror_relay_enforcer','cloud_can_grant'=>false,
      'cloud_can_revoke'=>false,'cloud_can_change_consent'=>false,
    ];
}

function tracky_v278_policy_public_capability(): array
{
    return [
      'version'=>'2.78','protocol'=>VP3_TRACKY_FEDERATION_POLICY_PROTOCOL_V278,
      'permission_scopes'=>tracky_v278_policy_scopes(),
      'consent_scopes'=>tracky_v278_policy_consent_scopes(),
      'deny_by_default'=>true,'cloud_role'=>'mirror_relay_enforcer',
      'authority_assignment'=>'local_site_policy','cloud_can_grant'=>false,
      'cloud_can_revoke'=>false,'cloud_can_change_consent'=>false,
      'raw_perception'=>false,
    ];
}
