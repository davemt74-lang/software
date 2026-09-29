<?php
declare(strict_types=1);

/**
 * Cloud Hosting V2 Section 1 — custom-domain ownership, routing and canonical policy.
 *
 * Custom domains are Cloud-edge aliases. HomeServer continues to receive the
 * site's canonical internal/public route; Cloud edge maps verified aliases to
 * that route and rewrites the upstream host to the canonical site hostname.
 */

const VP3_CLOUD_HOSTING_DOMAINS_V200='cloud-hosting-domains-v200';

function vp3_cloud_hosting_domains_v200_schema_ready(?PDO $pdo=null): bool
{
    $pdo??=db();
    return $pdo && table_exists('cloud_hosting_custom_domains');
}

function vp3_cloud_hosting_domains_v200_ensure_schema(?PDO $pdo=null): void
{
    $pdo??=db();
    if(!$pdo)throw new RuntimeException('Database connection is unavailable.');
    vp3_cloud_hosting_v120_ensure_schema($pdo);

    $pdo->exec("CREATE TABLE IF NOT EXISTS cloud_hosting_custom_domains (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
      site_id BIGINT UNSIGNED NOT NULL,
      owner_user_id INT UNSIGNED NOT NULL,
      hostname VARCHAR(253) NOT NULL,
      verification_dns_name VARCHAR(253) NOT NULL,
      verification_token_hash CHAR(64) NOT NULL,
      verification_token_enc LONGTEXT NOT NULL,
      verification_state VARCHAR(30) NOT NULL DEFAULT 'pending',
      routing_state VARCHAR(30) NOT NULL DEFAULT 'pending',
      tls_state VARCHAR(30) NOT NULL DEFAULT 'pending',
      provider VARCHAR(30) NOT NULL DEFAULT 'manual',
      record_type VARCHAR(20) NOT NULL DEFAULT 'CNAME',
      record_value VARCHAR(253) NOT NULL,
      is_canonical TINYINT(1) NOT NULL DEFAULT 0,
      redirect_to_canonical TINYINT(1) NOT NULL DEFAULT 1,
      revision BIGINT UNSIGNED NOT NULL DEFAULT 1,
      certificate_not_after DATETIME NULL,
      certificate_fingerprint VARCHAR(128) NOT NULL DEFAULT '',
      last_error_code VARCHAR(80) NOT NULL DEFAULT '',
      last_error_message VARCHAR(500) NOT NULL DEFAULT '',
      attached_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      ownership_verified_at DATETIME NULL,
      routing_verified_at DATETIME NULL,
      detached_at DATETIME NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      UNIQUE KEY uq_cloud_hosting_custom_domain_hostname (hostname),
      INDEX idx_cloud_hosting_custom_domain_site (site_id,detached_at,is_canonical,id),
      INDEX idx_cloud_hosting_custom_domain_owner (owner_user_id,detached_at,id),
      INDEX idx_cloud_hosting_custom_domain_state (verification_state,routing_state,tls_state,id),
      CONSTRAINT fk_cloud_hosting_custom_domain_site FOREIGN KEY (site_id) REFERENCES cloud_hosting_sites(id) ON DELETE CASCADE,
      CONSTRAINT fk_cloud_hosting_custom_domain_owner FOREIGN KEY (owner_user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

function vp3_cloud_hosting_domains_v200_normalize(string $hostname): string
{
    return vp3_cloud_hosting_v110_normalize_domain($hostname,'custom domain hostname');
}

function vp3_cloud_hosting_domains_v200_verification_name(string $hostname): string
{
    $hostname=vp3_cloud_hosting_domains_v200_normalize($hostname);
    return '_vp3-verification.'.$hostname;
}

function vp3_cloud_hosting_domains_v200_token(): string
{
    return 'vp3_'.bin2hex(random_bytes(20));
}

function vp3_cloud_hosting_domains_v200_token_value(string $token): string
{
    return 'vp3-verification='.$token;
}

function vp3_cloud_hosting_domains_v200_limit(array $user): ?int
{
    $state=vp3_cloud_hosting_entitlement_v100($user,'hosting.custom_domains');
    if(empty($state['enabled']))return 0;
    if(!empty($state['unlimited']))return null;
    return max(0,(int)($state['limit']??0));
}

function vp3_cloud_hosting_domains_v200_count(int $userId,?PDO $pdo=null): int
{
    $pdo??=db();
    if(!$pdo||$userId<1)return 0;
    $stmt=$pdo->prepare('SELECT COUNT(*) FROM cloud_hosting_custom_domains WHERE owner_user_id=? AND detached_at IS NULL');
    $stmt->execute([$userId]);
    return (int)$stmt->fetchColumn();
}

function vp3_cloud_hosting_domains_v200_find(int $domainId,int $userId,?PDO $pdo=null): ?array
{
    $pdo??=db();
    if(!$pdo||$domainId<1||$userId<1)return null;
    $stmt=$pdo->prepare('SELECT * FROM cloud_hosting_custom_domains WHERE id=? AND owner_user_id=? LIMIT 1');
    $stmt->execute([$domainId,$userId]);
    $row=$stmt->fetch();
    return is_array($row)?$row:null;
}

function vp3_cloud_hosting_domains_v200_for_site(int $siteId,int $userId,?PDO $pdo=null,bool $includeDetached=false): array
{
    $pdo??=db();
    if(!$pdo||$siteId<1||$userId<1)return [];
    $sql='SELECT * FROM cloud_hosting_custom_domains WHERE site_id=? AND owner_user_id=?';
    if(!$includeDetached)$sql.=' AND detached_at IS NULL';
    $sql.=' ORDER BY is_canonical DESC,id ASC';
    $stmt=$pdo->prepare($sql);
    $stmt->execute([$siteId,$userId]);
    return $stmt->fetchAll()?:[];
}

function vp3_cloud_hosting_domains_v200_public(array $row): array
{
    return [
        'id'=>(int)$row['id'],
        'site_id'=>(int)$row['site_id'],
        'hostname'=>(string)$row['hostname'],
        'verification_dns_name'=>(string)$row['verification_dns_name'],
        'verification_state'=>(string)$row['verification_state'],
        'routing_state'=>(string)$row['routing_state'],
        'tls_state'=>(string)$row['tls_state'],
        'provider'=>(string)$row['provider'],
        'record_type'=>(string)$row['record_type'],
        'record_value'=>(string)$row['record_value'],
        'is_canonical'=>(bool)$row['is_canonical'],
        'redirect_to_canonical'=>(bool)$row['redirect_to_canonical'],
        'revision'=>(int)$row['revision'],
        'certificate_not_after'=>$row['certificate_not_after']??null,
        'attached_at'=>$row['attached_at']??null,
        'ownership_verified_at'=>$row['ownership_verified_at']??null,
        'routing_verified_at'=>$row['routing_verified_at']??null,
        'detached_at'=>$row['detached_at']??null,
        'ready'=>(string)$row['verification_state']==='verified'
            && (string)$row['routing_state']==='verified'
            && in_array((string)$row['tls_state'],['active','renewing'],true)
            && empty($row['detached_at']),
        'last_error_code'=>(string)$row['last_error_code'],
        'last_error_message'=>(string)$row['last_error_message'],
    ];
}

function vp3_cloud_hosting_domains_v200_instructions(array $row): array
{
    $token=ai_decrypt_secret((string)$row['verification_token_enc']);
    if($token==='')throw new RuntimeException('Custom-domain verification token cannot be decrypted.');
    $hostname=(string)$row['hostname'];
    $isApex=substr_count($hostname,'.')===1;
    return [
        'ownership'=>[
            'type'=>'TXT',
            'name'=>(string)$row['verification_dns_name'],
            'value'=>vp3_cloud_hosting_domains_v200_token_value($token),
        ],
        'routing'=>[
            'type'=>'CNAME',
            'name'=>$hostname,
            'value'=>(string)$row['record_value'],
            'apex_note'=>$isApex
                ? 'If your DNS provider does not allow a CNAME at the zone apex, use its ALIAS, ANAME, or CNAME-flattening equivalent to the same target.'
                : '',
        ],
    ];
}

function vp3_cloud_hosting_domains_v200_event(
    PDO $pdo,
    array $domain,
    string $type,
    string $state,
    ?int $actorUserId=null,
    array $details=[]
): void {
    $siteId=(int)$domain['site_id'];
    $revision=vp3_cloud_hosting_v110_site_revision($pdo,$siteId);
    $safe=['custom_domain'=>(string)$domain['hostname'],'domain_id'=>(int)$domain['id']];
    foreach($details as $key=>$value){
        if(preg_match('/token|secret|credential|authorization/i',(string)$key))continue;
        if(is_scalar($value)||$value===null)$safe[(string)$key]=$value;
    }
    vp3_cloud_hosting_event_v100($pdo,$siteId,'custom_domain.'.$type,$state,$revision,$actorUserId,$safe);
}

function vp3_cloud_hosting_domains_v200_attach(
    array $site,
    array $user,
    string $hostname,
    ?int $actorUserId=null,
    ?PDO $pdo=null
): array {
    $pdo??=db();
    if(!$pdo)throw new RuntimeException('Database connection is unavailable.');
    vp3_cloud_hosting_domains_v200_ensure_schema($pdo);
    $siteId=(int)($site['id']??0);
    $userId=(int)($user['id']??0);
    if($siteId<1||$userId<1||(int)($site['user_id']??0)!==$userId)throw new RuntimeException('Hosted site is unavailable to this account.');
    $hostname=vp3_cloud_hosting_domains_v200_normalize($hostname);
    $internal=trim((string)($site['requested_hostname']??''));
    if($internal!==''&&hash_equals(strtolower($internal),$hostname))throw new RuntimeException('This hostname is already the site package subdomain.');

    $snapshot=vp3_cloud_hosting_entitlement_snapshot_v100($user);
    if(empty($snapshot['entitlements']['hosting.access']['enabled']))throw new RuntimeException('This account package does not include Cloud Hosting.');
    $limit=vp3_cloud_hosting_domains_v200_limit($user);
    if($limit===0)throw new RuntimeException('This account package does not include custom Hosting domains.');

    $pdo->beginTransaction();
    try{
        $lock=$pdo->prepare('SELECT id FROM users WHERE id=? FOR UPDATE');
        $lock->execute([$userId]);
        if((int)$lock->fetchColumn()!==$userId)throw new RuntimeException('Hosting owner no longer exists.');

        $existing=$pdo->prepare('SELECT * FROM cloud_hosting_custom_domains WHERE hostname=? LIMIT 1');
        $existing->execute([$hostname]);
        $row=$existing->fetch();
        if(is_array($row)&&empty($row['detached_at'])){
            if((int)$row['owner_user_id']!==$userId)throw new RuntimeException('This custom domain is already attached to another account.');
            throw new RuntimeException('This custom domain is already attached.');
        }
        if($limit!==null&&vp3_cloud_hosting_domains_v200_count($userId,$pdo)>=$limit){
            throw new RuntimeException('This account has reached its custom-domain limit.');
        }
        if(is_array($row)){
            $token=vp3_cloud_hosting_domains_v200_token();
            $stmt=$pdo->prepare("UPDATE cloud_hosting_custom_domains SET
              site_id=?,owner_user_id=?,verification_dns_name=?,verification_token_hash=?,verification_token_enc=?,
              verification_state='pending',routing_state='pending',tls_state='pending',provider='manual',
              record_type='CNAME',record_value=?,is_canonical=0,redirect_to_canonical=1,revision=revision+1,
              certificate_not_after=NULL,certificate_fingerprint='',last_error_code='',last_error_message='',
              attached_at=UTC_TIMESTAMP(),ownership_verified_at=NULL,routing_verified_at=NULL,detached_at=NULL
              WHERE id=?");
            $stmt->execute([
                $siteId,$userId,vp3_cloud_hosting_domains_v200_verification_name($hostname),hash('sha256',$token),
                ai_encrypt_secret($token),vp3_cloud_hosting_v110_ingress_hostname(),(int)$row['id']
            ]);
            $id=(int)$row['id'];
        }else{
            $token=vp3_cloud_hosting_domains_v200_token();
            $stmt=$pdo->prepare("INSERT INTO cloud_hosting_custom_domains
              (site_id,owner_user_id,hostname,verification_dns_name,verification_token_hash,verification_token_enc,provider,record_type,record_value)
              VALUES (?,?,?,?,?,?,'manual','CNAME',?)");
            $stmt->execute([
                $siteId,$userId,$hostname,vp3_cloud_hosting_domains_v200_verification_name($hostname),
                hash('sha256',$token),ai_encrypt_secret($token),vp3_cloud_hosting_v110_ingress_hostname()
            ]);
            $id=(int)$pdo->lastInsertId();
        }
        $pdo->prepare('UPDATE cloud_hosting_sites SET desired_revision=desired_revision+1 WHERE id=?')->execute([$siteId]);
        $fresh=vp3_cloud_hosting_domains_v200_find($id,$userId,$pdo);
        if(!$fresh)throw new RuntimeException('Custom domain could not be loaded after attach.');
        vp3_cloud_hosting_domains_v200_event($pdo,$fresh,'attached','pending',$actorUserId,['provider'=>'manual']);
        $pdo->commit();
        return [
            'domain'=>vp3_cloud_hosting_domains_v200_public($fresh),
            'instructions'=>vp3_cloud_hosting_domains_v200_instructions($fresh),
        ];
    }catch(Throwable $e){
        if($pdo->inTransaction())$pdo->rollBack();
        throw $e;
    }
}

function vp3_cloud_hosting_domains_v200_default_txt_resolver(string $name): array
{
    $records=dns_get_record($name,DNS_TXT);
    return is_array($records)?$records:[];
}

function vp3_cloud_hosting_domains_v200_verify_ownership(
    array $domain,
    ?int $actorUserId=null,
    ?callable $resolver=null,
    ?PDO $pdo=null
): array {
    $pdo??=db();
    if(!$pdo)throw new RuntimeException('Database connection is unavailable.');
    $domainId=(int)($domain['id']??0);
    $userId=(int)($domain['owner_user_id']??0);
    $fresh=vp3_cloud_hosting_domains_v200_find($domainId,$userId,$pdo);
    if(!$fresh||!empty($fresh['detached_at']))throw new RuntimeException('Custom domain is not attached.');
    $token=ai_decrypt_secret((string)$fresh['verification_token_enc']);
    if($token==='')throw new RuntimeException('Custom-domain verification token cannot be decrypted.');
    $expected=vp3_cloud_hosting_domains_v200_token_value($token);
    $records=$resolver?$resolver((string)$fresh['verification_dns_name']):vp3_cloud_hosting_domains_v200_default_txt_resolver((string)$fresh['verification_dns_name']);
    if(!is_array($records))$records=[];
    $matched=false;
    foreach($records as $record){
        if(is_string($record)&&hash_equals($expected,trim($record))){$matched=true;break;}
        if(!is_array($record))continue;
        $value=(string)($record['txt']??$record['value']??'');
        if($value!==''&&hash_equals($expected,trim($value))){$matched=true;break;}
        if(isset($record['entries'])&&is_array($record['entries'])){
            $joined=implode('',array_map('strval',$record['entries']));
            if(hash_equals($expected,trim($joined))){$matched=true;break;}
        }
    }
    if(!$matched){
        $pdo->prepare("UPDATE cloud_hosting_custom_domains SET verification_state='pending',last_error_code='',last_error_message='' WHERE id=?")->execute([$domainId]);
        return vp3_cloud_hosting_domains_v200_public(vp3_cloud_hosting_domains_v200_find($domainId,$userId,$pdo)??$fresh);
    }
    if((string)$fresh['verification_state']!=='verified'){
        $pdo->prepare("UPDATE cloud_hosting_custom_domains SET verification_state='verified',revision=revision+1,ownership_verified_at=UTC_TIMESTAMP(),last_error_code='',last_error_message='' WHERE id=?")->execute([$domainId]);
        $pdo->prepare('UPDATE cloud_hosting_sites SET desired_revision=desired_revision+1 WHERE id=?')->execute([(int)$fresh['site_id']]);
    }
    $updated=vp3_cloud_hosting_domains_v200_find($domainId,$userId,$pdo)??$fresh;
    vp3_cloud_hosting_domains_v200_event($pdo,$updated,'ownership_verified','verified',$actorUserId);
    return vp3_cloud_hosting_domains_v200_public($updated);
}

function vp3_cloud_hosting_domains_v200_dns_snapshot(string $hostname,string $ingress,?callable $resolver=null): array
{
    if($resolver)return (array)$resolver($hostname,$ingress);
    $records=dns_get_record($hostname,DNS_CNAME|DNS_A|DNS_AAAA);
    $targetRecords=dns_get_record($ingress,DNS_A|DNS_AAAA);
    return ['records'=>is_array($records)?$records:[],'target_records'=>is_array($targetRecords)?$targetRecords:[]];
}

function vp3_cloud_hosting_domains_v200_verify_routing(
    array $domain,
    ?int $actorUserId=null,
    ?callable $resolver=null,
    ?PDO $pdo=null
): array {
    $pdo??=db();
    if(!$pdo)throw new RuntimeException('Database connection is unavailable.');
    $domainId=(int)($domain['id']??0);
    $userId=(int)($domain['owner_user_id']??0);
    $fresh=vp3_cloud_hosting_domains_v200_find($domainId,$userId,$pdo);
    if(!$fresh||!empty($fresh['detached_at']))throw new RuntimeException('Custom domain is not attached.');
    if((string)$fresh['verification_state']!=='verified')throw new RuntimeException('Verify custom-domain ownership before routing.');
    $hostname=(string)$fresh['hostname'];
    $target=rtrim(strtolower((string)$fresh['record_value']),'.');
    $snapshot=vp3_cloud_hosting_domains_v200_dns_snapshot($hostname,$target,$resolver);
    $records=(array)($snapshot['records']??[]);
    $targetRecords=(array)($snapshot['target_records']??[]);

    $matched=false;
    $sourceAddresses=[];$targetAddresses=[];
    foreach($targetRecords as $record){
        if(!is_array($record))continue;
        foreach(['ip','ipv6'] as $key)if(!empty($record[$key]))$targetAddresses[]=strtolower((string)$record[$key]);
    }
    foreach($records as $record){
        if(!is_array($record))continue;
        $cname=rtrim(strtolower((string)($record['target']??'')),'.');
        if($cname!==''&&hash_equals($target,$cname)){$matched=true;break;}
        foreach(['ip','ipv6'] as $key)if(!empty($record[$key]))$sourceAddresses[]=strtolower((string)$record[$key]);
    }
    if(!$matched&&$sourceAddresses&&$targetAddresses){
        $matched=(bool)array_intersect(array_unique($sourceAddresses),array_unique($targetAddresses));
    }

    if(!$matched){
        $pdo->prepare("UPDATE cloud_hosting_custom_domains SET routing_state='pending',last_error_code='',last_error_message='' WHERE id=?")->execute([$domainId]);
        return vp3_cloud_hosting_domains_v200_public(vp3_cloud_hosting_domains_v200_find($domainId,$userId,$pdo)??$fresh);
    }
    if((string)$fresh['routing_state']!=='verified'){
        $pdo->prepare("UPDATE cloud_hosting_custom_domains SET routing_state='verified',revision=revision+1,routing_verified_at=UTC_TIMESTAMP(),last_error_code='',last_error_message='' WHERE id=?")->execute([$domainId]);
        $pdo->prepare('UPDATE cloud_hosting_sites SET desired_revision=desired_revision+1 WHERE id=?')->execute([(int)$fresh['site_id']]);
    }
    $updated=vp3_cloud_hosting_domains_v200_find($domainId,$userId,$pdo)??$fresh;
    vp3_cloud_hosting_domains_v200_event($pdo,$updated,'routing_verified','verified',$actorUserId);
    return vp3_cloud_hosting_domains_v200_public($updated);
}

function vp3_cloud_hosting_domains_v200_record_tls(
    array $domain,
    string $state,
    ?string $notAfter=null,
    string $fingerprint='',
    ?int $actorUserId=null,
    ?PDO $pdo=null
): array {
    $pdo??=db();
    if(!$pdo)throw new RuntimeException('Database connection is unavailable.');
    $state=strtolower(trim($state));
    if(!in_array($state,['pending','active','renewing','failed'],true))throw new RuntimeException('Unsupported custom-domain TLS state.');
    $fresh=vp3_cloud_hosting_domains_v200_find((int)$domain['id'],(int)$domain['owner_user_id'],$pdo);
    if(!$fresh||!empty($fresh['detached_at']))throw new RuntimeException('Custom domain is not attached.');
    if(in_array($state,['active','renewing'],true)&&((string)$fresh['verification_state']!=='verified'||(string)$fresh['routing_state']!=='verified')){
        throw new RuntimeException('Ownership and routing must be verified before custom-domain TLS can become active.');
    }
    $normalized=null;
    if($notAfter!==null&&trim($notAfter)!==''){
        try{$date=new DateTimeImmutable($notAfter);}catch(Throwable $e){throw new RuntimeException('Custom-domain certificate expiry is invalid.');}
        $normalized=$date->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }
    $fingerprint=strtolower(trim($fingerprint));
    if($fingerprint!==''&&!preg_match('/^[a-f0-9]{32,128}$/',$fingerprint))throw new RuntimeException('Custom-domain certificate fingerprint is invalid.');

    $changed=(string)$fresh['tls_state']!==$state
        || (string)($fresh['certificate_not_after']??'')!==(string)$normalized
        || (string)$fresh['certificate_fingerprint']!==$fingerprint;
    if($changed){
        $pdo->prepare("UPDATE cloud_hosting_custom_domains SET tls_state=?,certificate_not_after=?,certificate_fingerprint=?,revision=revision+1,last_error_code='',last_error_message='' WHERE id=?")
            ->execute([$state,$normalized,$fingerprint,(int)$fresh['id']]);
        $pdo->prepare('UPDATE cloud_hosting_sites SET desired_revision=desired_revision+1 WHERE id=?')->execute([(int)$fresh['site_id']]);
    }
    $updated=vp3_cloud_hosting_domains_v200_find((int)$fresh['id'],(int)$fresh['owner_user_id'],$pdo)??$fresh;
    vp3_cloud_hosting_domains_v200_event($pdo,$updated,'tls_'.$state,$state,$actorUserId,['certificate_not_after'=>$normalized]);
    return vp3_cloud_hosting_domains_v200_public($updated);
}

function vp3_cloud_hosting_domains_v200_set_canonical(
    array $domain,
    bool $redirectOthers=true,
    ?int $actorUserId=null,
    ?PDO $pdo=null
): array {
    $pdo??=db();
    if(!$pdo)throw new RuntimeException('Database connection is unavailable.');
    $fresh=vp3_cloud_hosting_domains_v200_find((int)$domain['id'],(int)$domain['owner_user_id'],$pdo);
    if(!$fresh||!empty($fresh['detached_at']))throw new RuntimeException('Custom domain is not attached.');
    $public=vp3_cloud_hosting_domains_v200_public($fresh);
    if(empty($public['ready']))throw new RuntimeException('Custom domain must have verified ownership, verified routing, and active TLS before it can become canonical.');

    $pdo->beginTransaction();
    try{
        $pdo->prepare('SELECT id FROM cloud_hosting_sites WHERE id=? FOR UPDATE')->execute([(int)$fresh['site_id']]);
        $pdo->prepare('UPDATE cloud_hosting_custom_domains SET is_canonical=0,redirect_to_canonical=?,revision=revision+1 WHERE site_id=? AND detached_at IS NULL')
            ->execute([$redirectOthers?1:0,(int)$fresh['site_id']]);
        $pdo->prepare('UPDATE cloud_hosting_custom_domains SET is_canonical=1,redirect_to_canonical=0,revision=revision+1 WHERE id=?')
            ->execute([(int)$fresh['id']]);
        $pdo->prepare('UPDATE cloud_hosting_sites SET desired_revision=desired_revision+1 WHERE id=?')->execute([(int)$fresh['site_id']]);
        $updated=vp3_cloud_hosting_domains_v200_find((int)$fresh['id'],(int)$fresh['owner_user_id'],$pdo);
        if(!$updated)throw new RuntimeException('Canonical custom domain could not be reloaded.');
        vp3_cloud_hosting_domains_v200_event($pdo,$updated,'canonical_changed','active',$actorUserId,['redirect_others'=>$redirectOthers]);
        $pdo->commit();
        return vp3_cloud_hosting_domains_v200_public($updated);
    }catch(Throwable $e){
        if($pdo->inTransaction())$pdo->rollBack();
        throw $e;
    }
}

function vp3_cloud_hosting_domains_v200_set_redirect(
    array $domain,
    bool $enabled,
    ?int $actorUserId=null,
    ?PDO $pdo=null
): array {
    $pdo??=db();
    if(!$pdo)throw new RuntimeException('Database connection is unavailable.');
    $fresh=vp3_cloud_hosting_domains_v200_find((int)$domain['id'],(int)$domain['owner_user_id'],$pdo);
    if(!$fresh||!empty($fresh['detached_at']))throw new RuntimeException('Custom domain is not attached.');
    if(!empty($fresh['is_canonical'])&&$enabled)throw new RuntimeException('The canonical custom domain cannot redirect to itself.');
    if((bool)$fresh['redirect_to_canonical']!==$enabled){
        $pdo->prepare('UPDATE cloud_hosting_custom_domains SET redirect_to_canonical=?,revision=revision+1 WHERE id=?')->execute([$enabled?1:0,(int)$fresh['id']]);
        $pdo->prepare('UPDATE cloud_hosting_sites SET desired_revision=desired_revision+1 WHERE id=?')->execute([(int)$fresh['site_id']]);
    }
    $updated=vp3_cloud_hosting_domains_v200_find((int)$fresh['id'],(int)$fresh['owner_user_id'],$pdo)??$fresh;
    vp3_cloud_hosting_domains_v200_event($pdo,$updated,'redirect_changed',$enabled?'redirect':'serve',$actorUserId,['enabled'=>$enabled]);
    return vp3_cloud_hosting_domains_v200_public($updated);
}

function vp3_cloud_hosting_domains_v200_detach(
    array $domain,
    ?int $actorUserId=null,
    ?PDO $pdo=null
): array {
    $pdo??=db();
    if(!$pdo)throw new RuntimeException('Database connection is unavailable.');
    $fresh=vp3_cloud_hosting_domains_v200_find((int)$domain['id'],(int)$domain['owner_user_id'],$pdo);
    if(!$fresh)throw new RuntimeException('Custom domain was not found.');
    if(!empty($fresh['detached_at']))return vp3_cloud_hosting_domains_v200_public($fresh);
    $pdo->beginTransaction();
    try{
        $pdo->prepare("UPDATE cloud_hosting_custom_domains SET is_canonical=0,redirect_to_canonical=0,routing_state='detached',tls_state='pending',revision=revision+1,detached_at=UTC_TIMESTAMP() WHERE id=?")
            ->execute([(int)$fresh['id']]);
        $pdo->prepare('UPDATE cloud_hosting_sites SET desired_revision=desired_revision+1 WHERE id=?')->execute([(int)$fresh['site_id']]);
        $updated=vp3_cloud_hosting_domains_v200_find((int)$fresh['id'],(int)$fresh['owner_user_id'],$pdo);
        if(!$updated)throw new RuntimeException('Detached custom domain could not be reloaded.');
        vp3_cloud_hosting_domains_v200_event($pdo,$updated,'detached','detached',$actorUserId);
        $pdo->commit();
        return vp3_cloud_hosting_domains_v200_public($updated);
    }catch(Throwable $e){
        if($pdo->inTransaction())$pdo->rollBack();
        throw $e;
    }
}

function vp3_cloud_hosting_domains_v200_migrate(
    array $domain,
    array $targetSite,
    ?int $actorUserId=null,
    ?PDO $pdo=null
): array {
    $pdo??=db();
    if(!$pdo)throw new RuntimeException('Database connection is unavailable.');
    $fresh=vp3_cloud_hosting_domains_v200_find((int)$domain['id'],(int)$domain['owner_user_id'],$pdo);
    if(!$fresh||!empty($fresh['detached_at']))throw new RuntimeException('Custom domain is not attached.');
    $targetSiteId=(int)($targetSite['id']??0);
    if($targetSiteId<1||(int)$targetSite['user_id']!==(int)$fresh['owner_user_id'])throw new RuntimeException('Target hosted site is unavailable to this account.');
    if($targetSiteId===(int)$fresh['site_id'])return vp3_cloud_hosting_domains_v200_public($fresh);

    $oldSiteId=(int)$fresh['site_id'];
    $pdo->beginTransaction();
    try{
        $ids=[$oldSiteId,$targetSiteId];sort($ids,SORT_NUMERIC);
        foreach($ids as $id){
            $lock=$pdo->prepare('SELECT id FROM cloud_hosting_sites WHERE id=? FOR UPDATE');
            $lock->execute([$id]);
            if(!(int)$lock->fetchColumn())throw new RuntimeException('Hosted site disappeared during custom-domain migration.');
        }
        $pdo->prepare('UPDATE cloud_hosting_custom_domains SET site_id=?,is_canonical=0,redirect_to_canonical=1,revision=revision+1 WHERE id=?')
            ->execute([$targetSiteId,(int)$fresh['id']]);
        $pdo->prepare('UPDATE cloud_hosting_sites SET desired_revision=desired_revision+1 WHERE id IN (?,?)')
            ->execute([$oldSiteId,$targetSiteId]);
        $updated=vp3_cloud_hosting_domains_v200_find((int)$fresh['id'],(int)$fresh['owner_user_id'],$pdo);
        if(!$updated)throw new RuntimeException('Migrated custom domain could not be reloaded.');
        vp3_cloud_hosting_domains_v200_event($pdo,$updated,'migrated','active',$actorUserId,['from_site_id'=>$oldSiteId,'to_site_id'=>$targetSiteId]);
        $pdo->commit();
        return vp3_cloud_hosting_domains_v200_public($updated);
    }catch(Throwable $e){
        if($pdo->inTransaction())$pdo->rollBack();
        throw $e;
    }
}

function vp3_cloud_hosting_domains_v200_edge_projection(int $userId=0,?PDO $pdo=null): array
{
    $pdo??=db();
    if(!$pdo)throw new RuntimeException('Database connection is unavailable.');
    $sql="SELECT d.*,s.site_key,s.canonical_hostname,s.requested_hostname
      FROM cloud_hosting_custom_domains d
      JOIN cloud_hosting_sites s ON s.id=d.site_id
      WHERE d.detached_at IS NULL
        AND d.verification_state='verified'
        AND d.routing_state='verified'
        AND d.tls_state IN ('active','renewing')";
    $params=[];
    if($userId>0){$sql.=' AND d.owner_user_id=?';$params[]=$userId;}
    $sql.=' ORDER BY d.site_id,d.is_canonical DESC,d.id';
    $stmt=$pdo->prepare($sql);$stmt->execute($params);
    $rows=$stmt->fetchAll()?:[];

    $canonical=[];
    foreach($rows as $row)if(!empty($row['is_canonical']))$canonical[(int)$row['site_id']]=(string)$row['hostname'];
    $out=[];
    foreach($rows as $row){
        $upstream=(string)($row['canonical_hostname']??'');
        if($upstream==='')$upstream=(string)($row['requested_hostname']??'');
        if($upstream==='')continue;
        $siteId=(int)$row['site_id'];
        $canonicalHost=$canonical[$siteId]??'';
        $out[]=[
            'domain_id'=>(int)$row['id'],
            'hostname'=>(string)$row['hostname'],
            'cloud_site_id'=>(string)$row['site_key'],
            'upstream_hostname'=>$upstream,
            'is_canonical'=>(bool)$row['is_canonical'],
            'redirect_to'=>$canonicalHost!==''&&!empty($row['redirect_to_canonical'])&&!$row['is_canonical']
                ?'https://'.$canonicalHost
                :null,
            'tls_state'=>(string)$row['tls_state'],
            'certificate_not_after'=>$row['certificate_not_after']??null,
            'revision'=>(int)$row['revision'],
        ];
    }
    return ['contract'=>'vp3.cloud-hosting-custom-domains.v1','domains'=>$out];
}

function vp3_cloud_hosting_domains_v200_capability(): array
{
    return [
        'contract'=>'vp3.cloud-hosting-custom-domains.v1',
        'multiple_domains_per_site'=>true,
        'dns_txt_ownership_verification'=>true,
        'cname_or_flattening_route_verification'=>true,
        'cloud_edge_tls'=>true,
        'canonical_domain'=>true,
        'noncanonical_redirect_policy'=>true,
        'domain_detach'=>true,
        'same_account_domain_migration'=>true,
        'home_server_alias_engine'=>false,
        'cloud_edge_rewrites_upstream_host'=>true,
        'verification_token_encrypted_at_rest'=>true,
        'verification_token_publicly_exposed'=>false,
    ];
}
