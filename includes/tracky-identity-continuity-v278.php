<?php
declare(strict_types=1);

/**
 * Tracky V2.78 Section 5 — Cloud cross-site identity continuity mirror.
 *
 * Cloud stores governed semantic identity-link decisions, validates the
 * governing HomeServer authority, and relays decisions only to member sites.
 * Cloud never confirms, merges, splits, rejects, or revokes identities.
 */
const VP3_TRACKY_IDENTITY_CONTINUITY_V278='vp3-tracky-identity-continuity-v278-20260927';
const VP3_TRACKY_IDENTITY_CONTINUITY_PROTOCOL_V278='physical_identity_continuity.v1';

function tracky_v278_identity_schema_ready(?PDO $pdo=null): bool
{
    $pdo??=db();
    return $pdo?table_exists('tracky_cloud_identity_links'):false;
}

function tracky_v278_identity_ensure_schema(?PDO $pdo=null): void
{
    $pdo??=db();
    if(!$pdo)throw new RuntimeException('Database connection is unavailable.');

    $pdo->exec("CREATE TABLE IF NOT EXISTS tracky_cloud_canonical_identities (
      user_id INT UNSIGNED NOT NULL,
      canonical_identity_uuid CHAR(36) NOT NULL,
      entity_type VARCHAR(40) NOT NULL,
      status VARCHAR(40) NOT NULL,
      aliases_json LONGTEXT NOT NULL,
      members_json LONGTEXT NOT NULL,
      revision BIGINT UNSIGNED NOT NULL,
      governing_site_uuid CHAR(36) NULL,
      identity_json LONGTEXT NOT NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      PRIMARY KEY (user_id,canonical_identity_uuid),
      INDEX idx_tracky_identity_status (user_id,status,entity_type,updated_at),
      CONSTRAINT fk_tracky_identity_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS tracky_cloud_identity_links (
      user_id INT UNSIGNED NOT NULL,
      link_uuid CHAR(36) NOT NULL,
      pair_key VARCHAR(700) NOT NULL,
      canonical_identity_uuid CHAR(36) NOT NULL,
      entity_type VARCHAR(40) NOT NULL,
      left_ref VARCHAR(320) NOT NULL,
      right_ref VARCHAR(320) NOT NULL,
      left_site_uuid CHAR(36) NOT NULL,
      right_site_uuid CHAR(36) NOT NULL,
      status VARCHAR(40) NOT NULL,
      reason VARCHAR(200) NOT NULL DEFAULT '',
      confidence DECIMAL(6,5) NOT NULL DEFAULT 0,
      auto_confirmed TINYINT(1) NOT NULL DEFAULT 0,
      revision BIGINT UNSIGNED NOT NULL,
      reporting_site_id VARCHAR(100) NOT NULL,
      governing_site_uuid CHAR(36) NOT NULL,
      governing_authority_device_uuid CHAR(36) NOT NULL,
      governing_authority_epoch BIGINT UNSIGNED NOT NULL,
      source_fingerprint CHAR(64) NOT NULL,
      semantic_hash CHAR(64) NOT NULL,
      link_json LONGTEXT NOT NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      PRIMARY KEY (user_id,link_uuid),
      UNIQUE KEY uq_tracky_identity_pair (user_id,pair_key),
      INDEX idx_tracky_identity_canonical (user_id,canonical_identity_uuid,status),
      INDEX idx_tracky_identity_left_site (user_id,left_site_uuid,status,updated_at),
      INDEX idx_tracky_identity_right_site (user_id,right_site_uuid,status,updated_at),
      INDEX idx_tracky_identity_governance (user_id,governing_site_uuid,governing_authority_epoch),
      CONSTRAINT fk_tracky_identity_link_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS tracky_cloud_identity_blocked_pairs (
      user_id INT UNSIGNED NOT NULL,
      pair_key VARCHAR(700) NOT NULL,
      reason VARCHAR(200) NOT NULL,
      blocked_at_ms BIGINT UNSIGNED NOT NULL DEFAULT 0,
      governing_site_uuid CHAR(36) NOT NULL,
      updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      PRIMARY KEY (user_id,pair_key),
      INDEX idx_tracky_identity_blocked_site (user_id,governing_site_uuid,updated_at),
      CONSTRAINT fk_tracky_identity_blocked_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS tracky_cloud_identity_history (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
      user_id INT UNSIGNED NOT NULL,
      link_uuid CHAR(36) NOT NULL,
      revision BIGINT UNSIGNED NOT NULL,
      status VARCHAR(40) NOT NULL,
      semantic_hash CHAR(64) NOT NULL,
      snapshot_json LONGTEXT NOT NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      UNIQUE KEY uq_tracky_identity_history (user_id,link_uuid,revision),
      INDEX idx_tracky_identity_history_recent (user_id,link_uuid,revision),
      CONSTRAINT fk_tracky_identity_history_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

function tracky_v278_identity_uuid(mixed $value,string $label): string
{
    $value=strtolower(mb_strimwidth(trim((string)($value??'')),0,64,''));
    if(!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',$value)){
        throw new RuntimeException('Tracky identity continuity '.$label.' must be a UUID.');
    }
    return $value;
}

function tracky_v278_identity_text(mixed $value,int $max=240,bool $required=false,string $label='value'): string
{
    $text=mb_strimwidth(trim((string)($value??'')),0,max(1,$max),'');
    if($required&&$text==='')throw new RuntimeException('Tracky identity continuity '.$label.' is required.');
    return $text;
}

function tracky_v278_identity_ref(mixed $value,string $label): array
{
    $ref=tracky_v278_identity_text($value,320,true,$label);
    if(!preg_match('/^site:([0-9a-fA-F-]{36})::(.+)$/',$ref,$m)){
        throw new RuntimeException('Tracky identity continuity '.$label.' must be a site-qualified entity ref.');
    }
    $site=tracky_v278_identity_uuid($m[1],$label.' site');
    $local=rawurldecode((string)$m[2]);
    if($local===''||mb_strlen($local)>160)throw new RuntimeException('Tracky identity continuity '.$label.' local id is invalid.');
    return [$ref,$site,$local];
}

function tracky_v278_identity_pair_key(string $left,string $right): string
{
    $refs=[$left,$right];sort($refs,SORT_STRING);
    return implode('|',$refs);
}

function tracky_v278_identity_identity(array $input): array
{
    tracky_cloud_v270_assert_governed_value($input,'identity_continuity.identity');
    $type=strtolower(tracky_v278_identity_text($input['entity_type']??'',40));
    if(!in_array($type,['person','device','object','animal'],true)){
        throw new RuntimeException('Tracky canonical identity entity type is unsupported.');
    }
    $status=strtolower(tracky_v278_identity_text($input['status']??'candidate',40));
    if(!in_array($status,['candidate','active','split','revoked'],true)){
        throw new RuntimeException('Tracky canonical identity status is unsupported.');
    }
    $members=[];
    foreach(array_slice(is_array($input['members']??null)?$input['members']:[],0,256) as $raw){
        [$ref]=tracky_v278_identity_ref($raw,'identity member ref');
        if(!in_array($ref,$members,true))$members[]=$ref;
    }
    sort($members,SORT_STRING);
    $aliases=[];
    foreach(array_slice(is_array($input['aliases']??null)?$input['aliases']:[],0,128) as $raw){
        $alias=tracky_v278_identity_text($raw,160);
        if($alias!==''&&!in_array($alias,$aliases,true))$aliases[]=$alias;
    }
    sort($aliases,SORT_STRING);
    return [
      'canonical_identity_id'=>tracky_v278_identity_uuid($input['canonical_identity_id']??'','canonical identity id'),
      'entity_type'=>$type,'status'=>$status,'members'=>$members,'aliases'=>$aliases,
      'revision'=>max(1,(int)($input['revision']??1)),
      'created_at'=>max(0,(int)($input['created_at']??0)),
      'updated_at'=>max(0,(int)($input['updated_at']??0)),
    ];
}

function tracky_v278_identity_link(array $input): array
{
    tracky_cloud_v270_assert_governed_value($input,'identity_continuity.link');
    [$left,$leftSite]=tracky_v278_identity_ref($input['left_ref']??'','left ref');
    [$right,$rightSite]=tracky_v278_identity_ref($input['right_ref']??'','right ref');
    if($left===$right)throw new RuntimeException('Tracky identity link requires two distinct refs.');
    if($leftSite===$rightSite)throw new RuntimeException('Tracky identity link must cross sites.');

    $type=strtolower(tracky_v278_identity_text($input['entity_type']??'',40));
    if(!in_array($type,['person','device','object','animal'],true)){
        throw new RuntimeException('Tracky identity link entity type is unsupported.');
    }
    $status=strtolower(tracky_v278_identity_text($input['status']??'',40));
    if(!in_array($status,['proposed','confirmed','rejected','revoked','split'],true)){
        throw new RuntimeException('Tracky identity link status is unsupported.');
    }
    $evidence=is_array($input['evidence']??null)?array_values($input['evidence']):[];
    if(count($evidence)>128)throw new RuntimeException('Tracky identity evidence exceeds the limit.');
    tracky_cloud_v270_assert_governed_value($evidence,'identity_continuity.link.evidence');

    $governingSite=tracky_v278_identity_uuid($input['governing_site_id']??'','governing site id');
    $authorityDevice=tracky_v278_identity_uuid($input['governing_authority_device_id']??'','governing authority device id');
    $authorityEpoch=max(0,(int)($input['governing_authority_epoch']??0));
    if($authorityEpoch<1)throw new RuntimeException('Tracky identity governing authority epoch is required.');
    if(!in_array($governingSite,[$leftSite,$rightSite],true)){
        throw new RuntimeException('Tracky identity governing site must be one of the linked member sites.');
    }

    $out=[
      'link_id'=>tracky_v278_identity_uuid($input['link_id']??'','link id'),
      'canonical_identity_id'=>tracky_v278_identity_uuid($input['canonical_identity_id']??'','canonical identity id'),
      'entity_type'=>$type,'left_ref'=>$left,'right_ref'=>$right,
      'left_site_id'=>$leftSite,'right_site_id'=>$rightSite,
      'status'=>$status,'reason'=>tracky_v278_identity_text($input['reason']??'',200),
      'evidence'=>$evidence,
      'confidence'=>is_numeric($input['confidence']??null)?max(0.0,min(1.0,(float)$input['confidence'])):0.0,
      'auto_confirmed'=>!empty($input['auto_confirmed']),
      'revision'=>max(1,(int)($input['revision']??1)),
      'created_at'=>max(0,(int)($input['created_at']??0)),
      'updated_at'=>max(0,(int)($input['updated_at']??0)),
      'confirmed_at'=>isset($input['confirmed_at'])&&$input['confirmed_at']!==null?(int)$input['confirmed_at']:null,
      'rejected_at'=>isset($input['rejected_at'])&&$input['rejected_at']!==null?(int)$input['rejected_at']:null,
      'revoked_at'=>isset($input['revoked_at'])&&$input['revoked_at']!==null?(int)$input['revoked_at']:null,
      'split_at'=>isset($input['split_at'])&&$input['split_at']!==null?(int)$input['split_at']:null,
      'governing_site_id'=>$governingSite,
      'governing_authority_device_id'=>$authorityDevice,
      'governing_authority_epoch'=>$authorityEpoch,
      'fingerprint'=>strtolower(tracky_v278_identity_text($input['fingerprint']??'',64)),
    ];
    if(!preg_match('/^[0-9a-f]{64}$/',$out['fingerprint'])){
        throw new RuntimeException('Tracky identity source fingerprint is required and must be SHA-256.');
    }
    $semantic=$out;
    unset($semantic['governing_site_id'],$semantic['governing_authority_device_id'],$semantic['governing_authority_epoch'],$semantic['fingerprint']);
    $out['semantic_hash']=hash('sha256',tracky_cloud_v270_json($semantic));
    return $out;
}

function tracky_v278_identity_normalize(array $input): array
{
    tracky_cloud_v270_assert_governed_value($input,'identity_continuity');
    if((string)($input['protocol']??'')!==VP3_TRACKY_IDENTITY_CONTINUITY_PROTOCOL_V278){
        throw new RuntimeException('Tracky identity continuity protocol is unsupported.');
    }
    if(isset($input['site_local_entities_immutable'])&&!$input['site_local_entities_immutable']){
        throw new RuntimeException('Tracky identity continuity cannot mutate site-local entity identity.');
    }
    if(isset($input['reversible'])&&!$input['reversible']){
        throw new RuntimeException('Tracky identity continuity must remain reversible.');
    }
    if(isset($input['cloud_can_confirm_links'])&&!empty($input['cloud_can_confirm_links'])
      ||isset($input['cloud_can_merge_identities'])&&!empty($input['cloud_can_merge_identities'])
      ||isset($input['cloud_can_split_identities'])&&!empty($input['cloud_can_split_identities'])){
        throw new RuntimeException('Tracky Cloud cannot receive identity decision authority.');
    }

    $identitiesRaw=is_array($input['identities']??null)?array_values($input['identities']):[];
    $linksRaw=is_array($input['links']??null)?array_values($input['links']):[];
    $blockedRaw=is_array($input['blocked_pairs']??null)?array_values($input['blocked_pairs']):[];
    if(count($identitiesRaw)>256||count($linksRaw)>512||count($blockedRaw)>512){
        throw new RuntimeException('Tracky identity continuity projection exceeds limits.');
    }

    $identities=[];
    foreach($identitiesRaw as $item){
        if(!is_array($item))throw new RuntimeException('Tracky canonical identity item is invalid.');
        $normalized=tracky_v278_identity_identity($item);
        $identities[$normalized['canonical_identity_id']]=$normalized;
    }
    $links=[];
    foreach($linksRaw as $item){
        if(!is_array($item))throw new RuntimeException('Tracky identity link item is invalid.');
        $link=tracky_v278_identity_link($item);
        if(!isset($identities[$link['canonical_identity_id']])&&$link['status']!=='confirmed'){
            $identities[$link['canonical_identity_id']]=[
              'canonical_identity_id'=>$link['canonical_identity_id'],'entity_type'=>$link['entity_type'],
              'status'=>'candidate','members'=>[],'aliases'=>[],'revision'=>$link['revision'],
              'created_at'=>$link['created_at'],'updated_at'=>$link['updated_at'],
            ];
        }
        if(!isset($identities[$link['canonical_identity_id']])){
            throw new RuntimeException('Confirmed Tracky identity link references a missing canonical identity.');
        }
        $identity=$identities[$link['canonical_identity_id']];
        if($identity['entity_type']!==$link['entity_type']){
            throw new RuntimeException('Tracky identity link type conflicts with canonical identity.');
        }
        if($link['status']==='confirmed'
          &&(!in_array($link['left_ref'],$identity['members'],true)||!in_array($link['right_ref'],$identity['members'],true))){
            throw new RuntimeException('Confirmed Tracky identity link members are missing from canonical identity.');
        }
        $links[]=$link;
    }

    $blocked=[];
    foreach($blockedRaw as $item){
        if(!is_array($item))continue;
        $blocked[]=[
          'pair'=>tracky_v278_identity_text($item['pair']??'',700,true,'blocked pair'),
          'reason'=>tracky_v278_identity_text($item['reason']??'',200),
          'at'=>max(0,(int)($item['at']??0)),
        ];
    }

    return [
      'protocol'=>VP3_TRACKY_IDENTITY_CONTINUITY_PROTOCOL_V278,'schema_version'=>1,
      'identities'=>array_values($identities),'links'=>$links,'blocked_pairs'=>$blocked,
      'semantic_only'=>true,'summary_only'=>true,'cloud_read_only'=>true,
      'site_local_entities_immutable'=>true,'reversible'=>true,
      'authority_assignment'=>'homeserver_governed',
      'cloud_can_confirm_links'=>false,'cloud_can_merge_identities'=>false,'cloud_can_split_identities'=>false,
    ];
}

function tracky_v278_identity_topology(PDO $pdo,int $userId,string $reportingSiteId): array
{
    $report=tracky_v278_report($pdo,$userId,$reportingSiteId);
    return is_array($report['topology']??null)?$report['topology']:[];
}

function tracky_v278_identity_authority(array $topology,string $siteId): array
{
    foreach((array)($topology['sites']??[]) as $site){
        if(!is_array($site)||($site['id']??'')!==$siteId)continue;
        return [
          'device_id'=>(string)($site['authority_device_id']??''),
          'epoch'=>(int)($site['authority_epoch']??0),
          'status'=>(string)($site['status']??'active'),
        ];
    }
    throw new RuntimeException('Tracky identity governing site is not present in the topology mirror.');
}

function tracky_v278_identity_topology_has_site(array $topology,string $siteId): bool
{
    foreach((array)($topology['sites']??[]) as $site){
        if(is_array($site)&&($site['id']??'')===$siteId)return true;
    }
    return false;
}

function tracky_v278_identity_assert_no_collision(PDO $pdo,int $userId,array $link): void
{
    if($link['status']!=='confirmed')return;
    foreach([$link['left_ref'],$link['right_ref']] as $ref){
        $q=$pdo->prepare("SELECT DISTINCT canonical_identity_uuid
          FROM tracky_cloud_identity_links
          WHERE user_id=? AND status='confirmed'
            AND (left_ref=? OR right_ref=?)
            AND canonical_identity_uuid<>?
          LIMIT 1");
        $q->execute([$userId,$ref,$ref,$link['canonical_identity_id']]);
        if($q->fetch()){
            throw new RuntimeException('Tracky identity ref is already assigned to a different active canonical identity.');
        }
    }
}

function tracky_v278_identity_ingest(PDO $pdo,int $userId,string $reportingSiteId,array $input): array
{
    if($userId<1)throw new RuntimeException('Authentication required.');
    $reportingSiteId=tracky_cloud_v270_site_id($reportingSiteId);
    if(!tracky_v278_identity_schema_ready($pdo))tracky_v278_identity_ensure_schema($pdo);
    $projection=tracky_v278_identity_normalize($input);
    $topology=tracky_v278_identity_topology($pdo,$userId,$reportingSiteId);
    $changed=0;$stale=0;$idempotent=0;

    foreach($projection['links'] as $link){
        if(!tracky_v278_identity_topology_has_site($topology,$link['left_site_id'])
          ||!tracky_v278_identity_topology_has_site($topology,$link['right_site_id'])){
            throw new RuntimeException('Tracky identity link references a site outside the current topology mirror.');
        }
        $authority=tracky_v278_identity_authority($topology,$link['governing_site_id']);
        if($authority['status']!=='active'
          ||$authority['device_id']!==$link['governing_authority_device_id']
          ||$authority['epoch']!==$link['governing_authority_epoch']){
            throw new RuntimeException('Tracky identity governing authority does not match the current topology mirror.');
        }

        $pairOwner=$pdo->prepare('SELECT link_uuid,governing_site_uuid FROM tracky_cloud_identity_links WHERE user_id=? AND pair_key=? LIMIT 1');
        $pairOwner->execute([$userId,tracky_v278_identity_pair_key($link['left_ref'],$link['right_ref'])]);
        $pairRow=$pairOwner->fetch();
        if($pairRow&&(string)$pairRow['link_uuid']!==$link['link_id']){
            throw new RuntimeException('Tracky identity pair is already governed by a different link.');
        }
        $q=$pdo->prepare('SELECT revision,semantic_hash FROM tracky_cloud_identity_links WHERE user_id=? AND link_uuid=? LIMIT 1');
        $q->execute([$userId,$link['link_id']]);$prior=$q->fetch();
        if($prior&&$link['revision']<(int)$prior['revision']){$stale++;continue;}
        if($prior&&$link['revision']===(int)$prior['revision']){
            if(!hash_equals((string)$prior['semantic_hash'],$link['semantic_hash'])){
                throw new RuntimeException('Tracky identity link revision conflicts with the existing Cloud mirror.');
            }
            $refresh=$pdo->prepare("UPDATE tracky_cloud_identity_links
              SET reporting_site_id=?,governing_site_uuid=?,governing_authority_device_uuid=?,
                  governing_authority_epoch=?,source_fingerprint=?,updated_at=CURRENT_TIMESTAMP
              WHERE user_id=? AND link_uuid=?");
            $refresh->execute([
              $reportingSiteId,$link['governing_site_id'],$link['governing_authority_device_id'],
              $link['governing_authority_epoch'],$link['fingerprint'],$userId,$link['link_id']
            ]);
            $idempotent++;continue;
        }
        if(in_array($link['status'],['proposed','confirmed'],true)){
            $blocked=$pdo->prepare('SELECT reason FROM tracky_cloud_identity_blocked_pairs WHERE user_id=? AND pair_key=? LIMIT 1');
            $blocked->execute([$userId,tracky_v278_identity_pair_key($link['left_ref'],$link['right_ref'])]);
            if($blocked->fetch())throw new RuntimeException('Tracky identity pair is blocked by a prior rejection or split.');
        }

        tracky_v278_identity_assert_no_collision($pdo,$userId,$link);
        $identity=$projection['identities'][0]??null;
        foreach($projection['identities'] as $candidate){
            if($candidate['canonical_identity_id']===$link['canonical_identity_id']){$identity=$candidate;break;}
        }
        if(!$identity)throw new RuntimeException('Tracky identity projection is missing canonical identity state.');

        $identityJson=tracky_cloud_v270_json($identity);
        $idStmt=$pdo->prepare("INSERT INTO tracky_cloud_canonical_identities(
          user_id,canonical_identity_uuid,entity_type,status,aliases_json,members_json,revision,
          governing_site_uuid,identity_json
        ) VALUES (?,?,?,?,?,?,?,?,?)
        ON DUPLICATE KEY UPDATE
          entity_type=IF(VALUES(revision)>=revision,VALUES(entity_type),entity_type),
          status=IF(VALUES(revision)>=revision,VALUES(status),status),
          aliases_json=IF(VALUES(revision)>=revision,VALUES(aliases_json),aliases_json),
          members_json=IF(VALUES(revision)>=revision,VALUES(members_json),members_json),
          governing_site_uuid=IF(VALUES(revision)>=revision,VALUES(governing_site_uuid),governing_site_uuid),
          identity_json=IF(VALUES(revision)>=revision,VALUES(identity_json),identity_json),
          revision=GREATEST(revision,VALUES(revision)),
          updated_at=CURRENT_TIMESTAMP");
        $idStmt->execute([
          $userId,$identity['canonical_identity_id'],$identity['entity_type'],$identity['status'],
          tracky_cloud_v270_json($identity['aliases']),tracky_cloud_v270_json($identity['members']),
          $identity['revision'],$link['governing_site_id'],$identityJson
        ]);

        $linkJson=tracky_cloud_v270_json($link);
        $stmt=$pdo->prepare("INSERT INTO tracky_cloud_identity_links(
          user_id,link_uuid,pair_key,canonical_identity_uuid,entity_type,left_ref,right_ref,
          left_site_uuid,right_site_uuid,status,reason,confidence,auto_confirmed,revision,
          reporting_site_id,governing_site_uuid,governing_authority_device_uuid,
          governing_authority_epoch,source_fingerprint,semantic_hash,link_json
        ) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
        ON DUPLICATE KEY UPDATE
          pair_key=VALUES(pair_key),canonical_identity_uuid=VALUES(canonical_identity_uuid),
          entity_type=VALUES(entity_type),left_ref=VALUES(left_ref),right_ref=VALUES(right_ref),
          left_site_uuid=VALUES(left_site_uuid),right_site_uuid=VALUES(right_site_uuid),
          status=VALUES(status),reason=VALUES(reason),confidence=VALUES(confidence),
          auto_confirmed=VALUES(auto_confirmed),revision=VALUES(revision),
          reporting_site_id=VALUES(reporting_site_id),governing_site_uuid=VALUES(governing_site_uuid),
          governing_authority_device_uuid=VALUES(governing_authority_device_uuid),
          governing_authority_epoch=VALUES(governing_authority_epoch),
          source_fingerprint=VALUES(source_fingerprint),semantic_hash=VALUES(semantic_hash),
          link_json=VALUES(link_json),updated_at=CURRENT_TIMESTAMP");
        $stmt->execute([
          $userId,$link['link_id'],tracky_v278_identity_pair_key($link['left_ref'],$link['right_ref']),
          $link['canonical_identity_id'],$link['entity_type'],$link['left_ref'],$link['right_ref'],
          $link['left_site_id'],$link['right_site_id'],$link['status'],$link['reason'],$link['confidence'],
          $link['auto_confirmed']?1:0,$link['revision'],$reportingSiteId,$link['governing_site_id'],
          $link['governing_authority_device_id'],$link['governing_authority_epoch'],
          $link['fingerprint'],$link['semantic_hash'],$linkJson
        ]);

        $history=$pdo->prepare("INSERT IGNORE INTO tracky_cloud_identity_history(
          user_id,link_uuid,revision,status,semantic_hash,snapshot_json
        ) VALUES (?,?,?,?,?,?)");
        $history->execute([$userId,$link['link_id'],$link['revision'],$link['status'],$link['semantic_hash'],$linkJson]);
        $changed++;
    }

    foreach($projection['blocked_pairs'] as $blocked){
        $governing='';
        foreach($projection['links'] as $link){
            if(tracky_v278_identity_pair_key($link['left_ref'],$link['right_ref'])===$blocked['pair']){
                $governing=$link['governing_site_id'];break;
            }
        }
        if($governing==='')continue;
        $stmt=$pdo->prepare("INSERT INTO tracky_cloud_identity_blocked_pairs(
          user_id,pair_key,reason,blocked_at_ms,governing_site_uuid
        ) VALUES (?,?,?,?,?)
        ON DUPLICATE KEY UPDATE reason=VALUES(reason),
          blocked_at_ms=GREATEST(blocked_at_ms,VALUES(blocked_at_ms)),
          governing_site_uuid=VALUES(governing_site_uuid),updated_at=CURRENT_TIMESTAMP");
        $stmt->execute([$userId,$blocked['pair'],$blocked['reason'],$blocked['at'],$governing]);
    }

    return ['accepted'=>true,'changed'=>$changed,'stale'=>$stale,'idempotent'=>$idempotent];
}

function tracky_v278_identity_build_relay(PDO $pdo,int $userId,array $federationRequest): array
{
    if(!tracky_v278_identity_schema_ready($pdo))tracky_v278_identity_ensure_schema($pdo);
    $request=tracky_v278_sync_normalize($federationRequest);
    if(!$request['available']){
        return [
          'protocol'=>VP3_TRACKY_IDENTITY_CONTINUITY_PROTOCOL_V278,'schema_version'=>1,
          'identities'=>[],'links'=>[],'blocked_pairs'=>[],'semantic_only'=>true,
          'summary_only'=>true,'cloud_read_only'=>true,'site_local_entities_immutable'=>true,
          'reversible'=>true,'authority_assignment'=>'homeserver_governed',
          'cloud_can_confirm_links'=>false,'cloud_can_merge_identities'=>false,'cloud_can_split_identities'=>false,
        ];
    }

    $destination=$request['local_site_id'];
    $q=$pdo->prepare("SELECT * FROM tracky_cloud_identity_links
      WHERE user_id=? AND (left_site_uuid=? OR right_site_uuid=?)
        AND governing_site_uuid<>?
      ORDER BY updated_at DESC,link_uuid
      LIMIT 512");
    $q->execute([$userId,$destination,$destination,$destination]);
    $links=[];$identityIds=[];$pairKeys=[];

    foreach($q->fetchAll()?:[] as $row){
        $reporting=(string)($row['reporting_site_id']??'');
        if($reporting==='')continue;
        $topology=tracky_v278_identity_topology($pdo,$userId,$reporting);
        $authority=tracky_v278_identity_authority($topology,(string)$row['governing_site_uuid']);
        if($authority['status']!=='active'
          ||$authority['device_id']!==(string)$row['governing_authority_device_uuid']
          ||$authority['epoch']!==(int)$row['governing_authority_epoch']){
            continue;
        }
        $decoded=json_decode((string)($row['link_json']??''),true);
        if(!is_array($decoded))continue;
        $decoded['governing_site_id']=(string)$row['governing_site_uuid'];
        $decoded['governing_authority_device_id']=(string)$row['governing_authority_device_uuid'];
        $decoded['governing_authority_epoch']=(int)$row['governing_authority_epoch'];
        $decoded['fingerprint']=(string)$row['source_fingerprint'];
        $links[]=$decoded;
        $identityIds[(string)$row['canonical_identity_uuid']]=true;
        $pairKeys[(string)$row['pair_key']]=true;
    }

    $identities=[];
    if($identityIds){
        foreach(array_keys($identityIds) as $canonicalId){
            $q=$pdo->prepare('SELECT identity_json FROM tracky_cloud_canonical_identities WHERE user_id=? AND canonical_identity_uuid=? LIMIT 1');
            $q->execute([$userId,$canonicalId]);
            $decoded=json_decode((string)($q->fetchColumn()?:''),true);
            if(is_array($decoded))$identities[]=$decoded;
        }
    }

    $blocked=[];
    foreach(array_keys($pairKeys) as $pair){
        $q=$pdo->prepare('SELECT pair_key,reason,blocked_at_ms FROM tracky_cloud_identity_blocked_pairs WHERE user_id=? AND pair_key=? LIMIT 1');
        $q->execute([$userId,$pair]);$row=$q->fetch();
        if($row)$blocked[]=['pair'=>(string)$row['pair_key'],'reason'=>(string)$row['reason'],'at'=>(int)$row['blocked_at_ms']];
    }

    return [
      'protocol'=>VP3_TRACKY_IDENTITY_CONTINUITY_PROTOCOL_V278,'schema_version'=>1,
      'identities'=>$identities,'links'=>$links,'blocked_pairs'=>$blocked,
      'semantic_only'=>true,'summary_only'=>true,'cloud_read_only'=>true,
      'site_local_entities_immutable'=>true,'reversible'=>true,
      'authority_assignment'=>'homeserver_governed','destination_site_id'=>$destination,
      'cloud_can_confirm_links'=>false,'cloud_can_merge_identities'=>false,'cloud_can_split_identities'=>false,
      'cloud_role'=>'mirror_relay_only',
    ];
}

function tracky_v278_identity_report(PDO $pdo,int $userId): array
{
    if($userId<1)return ['available'=>false,'protocol'=>VP3_TRACKY_IDENTITY_CONTINUITY_PROTOCOL_V278];
    if(!tracky_v278_identity_schema_ready($pdo))tracky_v278_identity_ensure_schema($pdo);
    $q=$pdo->prepare('SELECT * FROM tracky_cloud_identity_links WHERE user_id=? ORDER BY updated_at DESC,link_uuid');
    $q->execute([$userId]);$links=[];
    foreach($q->fetchAll()?:[] as $row){
        $decoded=json_decode((string)($row['link_json']??''),true);
        if(is_array($decoded))$links[]=$decoded;
    }
    $q=$pdo->prepare('SELECT identity_json FROM tracky_cloud_canonical_identities WHERE user_id=? ORDER BY updated_at DESC,canonical_identity_uuid');
    $q->execute([$userId]);$identities=[];
    foreach($q->fetchAll()?:[] as $row){
        $decoded=json_decode((string)($row['identity_json']??''),true);
        if(is_array($decoded))$identities[]=$decoded;
    }
    return [
      'available'=>!empty($links)||!empty($identities),
      'protocol'=>VP3_TRACKY_IDENTITY_CONTINUITY_PROTOCOL_V278,
      'identities'=>$identities,'links'=>$links,
      'site_local_entities_immutable'=>true,'reversible'=>true,'cloud_read_only'=>true,
      'authority_assignment'=>'homeserver_governed','cloud_role'=>'mirror_relay_only',
      'cloud_can_confirm_links'=>false,'cloud_can_merge_identities'=>false,'cloud_can_split_identities'=>false,
    ];
}

function tracky_v278_identity_public_capability(): array
{
    return [
      'version'=>'2.78','protocol'=>VP3_TRACKY_IDENTITY_CONTINUITY_PROTOCOL_V278,
      'cloud_role'=>'mirror_relay_only','authority_assignment'=>'homeserver_governed',
      'site_local_entities_immutable'=>true,'reversible'=>true,'world_mutation_authority'=>false,
      'cloud_can_confirm_links'=>false,'cloud_can_merge_identities'=>false,'cloud_can_split_identities'=>false,
      'entity_types'=>['person','device','object','animal'],
    ];
}
