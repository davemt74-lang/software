<?php
declare(strict_types=1);

/**
 * Cloud Hosting V1 Section 4 — Agent Chat / Agent Brain hosting tools.
 *
 * Read-only hosting diagnostics may execute directly. Consequential changes
 * are always prepared first, persisted, and require an explicit confirmation
 * code before execution.
 */

const VP3_CLOUD_HOSTING_AGENT_V130='cloud-hosting-agent-v130';
const VP3_CLOUD_HOSTING_AGENT_CONFIRM_TTL_SECONDS=600;

function vp3_cloud_hosting_agent_v130_schema_ready(?PDO $pdo=null): bool
{
    $pdo??=db();
    return $pdo && table_exists('cloud_hosting_agent_actions');
}

function vp3_cloud_hosting_agent_v130_ensure_schema(?PDO $pdo=null): void
{
    $pdo??=db();
    if(!$pdo)throw new RuntimeException('Database connection is unavailable.');
    vp3_cloud_hosting_v120_ensure_schema($pdo);
    $pdo->exec("CREATE TABLE IF NOT EXISTS cloud_hosting_agent_actions (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
      public_id VARCHAR(64) NOT NULL,
      user_id INT UNSIGNED NOT NULL,
      conversation_id BIGINT UNSIGNED NULL,
      site_id BIGINT UNSIGNED NULL,
      action_type VARCHAR(60) NOT NULL,
      payload_json LONGTEXT NOT NULL,
      preview_json LONGTEXT NOT NULL,
      confirmation_token_hash CHAR(64) NOT NULL,
      idempotency_key VARCHAR(160) NOT NULL,
      status VARCHAR(30) NOT NULL DEFAULT 'prepared',
      execution_token CHAR(32) NOT NULL DEFAULT '',
      execution_expires_at DATETIME NULL,
      result_json LONGTEXT NULL,
      error_message VARCHAR(500) NOT NULL DEFAULT '',
      expires_at DATETIME NOT NULL,
      confirmed_at DATETIME NULL,
      completed_at DATETIME NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      UNIQUE KEY uq_cloud_hosting_agent_public (public_id),
      UNIQUE KEY uq_cloud_hosting_agent_idempotency (user_id,idempotency_key),
      INDEX idx_cloud_hosting_agent_pending (user_id,status,expires_at,id),
      CONSTRAINT fk_cloud_hosting_agent_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
      CONSTRAINT fk_cloud_hosting_agent_site FOREIGN KEY (site_id) REFERENCES cloud_hosting_sites(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

function vp3_cloud_hosting_agent_v130_empty(): array
{
    return [
        'handled'=>false,'answer'=>'','stem_media'=>[],'media'=>[],'actions'=>[],'sources'=>[],
        'hosting_plan'=>null,
    ];
}

function vp3_cloud_hosting_agent_v130_intent(string $query): bool
{
    $q=mb_strtolower(trim($query));
    if($q==='')return false;
    return (bool)preg_match('/\b(?:hosting|hosted\s+sites?|subdomains?|custom\s+domains?|domains?|dns|deployment|deploy|rollback|home\s*server\s+sites?|homeserver\s+sites?|website\s+offline|site\s+offline)\b/u',$q);
}

function vp3_cloud_hosting_agent_v130_user_site(int $userId,string $needle,?PDO $pdo=null): ?array
{
    $pdo??=db();
    if(!$pdo||$userId<1)return null;
    $needle=trim($needle);
    if($needle!==''){
        if(ctype_digit($needle)){
            $site=vp3_cloud_hosting_site_v100((int)$needle,$userId,$pdo);
            if($site)return $site;
        }
        $stmt=$pdo->prepare("SELECT * FROM cloud_hosting_sites
          WHERE user_id=? AND (site_key=? OR LOWER(display_name)=LOWER(?) OR LOWER(requested_hostname)=LOWER(?))
          ORDER BY id DESC LIMIT 1");
        $stmt->execute([$userId,$needle,$needle,$needle]);
        $row=$stmt->fetch();
        if(is_array($row))return $row;
        $like='%'.str_replace(['%','_'],['\\%','\\_'],$needle).'%';
        $stmt=$pdo->prepare("SELECT * FROM cloud_hosting_sites
          WHERE user_id=? AND (display_name LIKE ? ESCAPE '\\\\' OR requested_hostname LIKE ? ESCAPE '\\\\')
          ORDER BY id DESC LIMIT 2");
        $stmt->execute([$userId,$like,$like]);
        $rows=$stmt->fetchAll()?:[];
        if(count($rows)===1)return $rows[0];
    }
    $sites=vp3_cloud_hosting_sites_v100($userId,$pdo);
    return count($sites)===1?$sites[0]:null;
}

function vp3_cloud_hosting_agent_v130_extract_site_hint(string $query): string
{
    $patterns=[
        '/\b(?:site|website|hosting)\s+(?:named\s+|called\s+)?["\']([^"\']{1,160})["\']/iu',
        '/\b(?:site|website|hosting)\s+(?:named\s+|called\s+)([A-Za-z0-9][A-Za-z0-9 ._-]{0,159})/iu',
        '/\b([a-z0-9][a-z0-9.-]+\.[a-z]{2,})\b/iu',
    ];
    foreach($patterns as $pattern){
        if(preg_match($pattern,$query,$m))return trim((string)$m[1]);
    }
    return '';
}

function vp3_cloud_hosting_agent_v130_sync_row(int $siteId,?PDO $pdo=null): ?array
{
    $pdo??=db();if(!$pdo||$siteId<1)return null;
    $stmt=$pdo->prepare('SELECT * FROM cloud_hosting_site_sync WHERE site_id=? LIMIT 1');
    $stmt->execute([$siteId]);
    $row=$stmt->fetch();
    return is_array($row)?$row:null;
}

function vp3_cloud_hosting_agent_v130_latest_deployment(int $siteId,?PDO $pdo=null): ?array
{
    $pdo??=db();if(!$pdo||$siteId<1)return null;
    $stmt=$pdo->prepare('SELECT id,request_key,operation,desired_revision,package_bytes,state,release_id,error_code,error_message,created_at,completed_at,updated_at FROM cloud_hosting_deployments WHERE site_id=? ORDER BY id DESC LIMIT 1');
    $stmt->execute([$siteId]);
    $row=$stmt->fetch();
    return is_array($row)?$row:null;
}

function vp3_cloud_hosting_agent_v130_site_status(array $site,?callable $remote=null,?PDO $pdo=null): array
{
    $pdo??=db();
    if(!$pdo)throw new RuntimeException('Database connection is unavailable.');
    $siteId=(int)($site['id']??0);
    $userId=(int)($site['user_id']??0);
    if($siteId<1||$userId<1)throw new RuntimeException('A valid hosted site is required.');
    $route=vp3_cloud_hosting_v110_route_for_site($siteId,$pdo);
    $sync=vp3_cloud_hosting_agent_v130_sync_row($siteId,$pdo);
    $deployment=vp3_cloud_hosting_agent_v130_latest_deployment($siteId,$pdo);
    $connection=function_exists('homeserver_vp3_connection')?homeserver_vp3_connection($userId):null;
    $dashboard=null;
    try{
        $dashboard=vp3_cloud_hosting_v120_remote($userId,'hosting.dashboard',[],$remote);
    }catch(Throwable $e){
        $dashboard=null;
    }
    $remoteSite=null;
    foreach((array)($dashboard['sites']??[]) as $row){
        if(!is_array($row))continue;
        $binding=(array)($row['cloud_binding']??[]);
        if((string)($binding['cloud_site_id']??'')===(string)$site['site_key']){
            $remoteSite=$row;break;
        }
        if((string)($row['cloud_site_id']??'')===(string)$site['site_key']){
            $remoteSite=$row;break;
        }
    }

    $issues=[];
    $connectionStatus=(string)($connection['status']??'unpaired');
    if(!in_array($connectionStatus,['connected','paired','online'],true))$issues[]='HomeServer is not currently connected.';
    if((string)($site['observed_state']??'pending')!==(string)($site['desired_state']??'configured')){
        $issues[]='HomeServer observed state does not yet match the Cloud desired state.';
    }
    if((string)($site['last_error_message']??'')!=='')$issues[]=mb_substr((string)$site['last_error_message'],0,240);
    if($route!==null&&(string)($route['dns_state']??'')!=='verified')$issues[]='DNS is not verified yet.';
    if($route!==null&&!in_array((string)($route['tls_state']??''),['active','renewing'],true))$issues[]='Cloud-edge TLS is not active.';
    if($deployment!==null&&in_array((string)$deployment['state'],['failed','interrupted'],true)){
        $issues[]='The latest deployment is '.(string)$deployment['state'].'.';
    }
    if(is_array($sync)&&($sync['last_error_message']??'')!=='')$issues[]=mb_substr((string)$sync['last_error_message'],0,240);

    $traffic=is_array($remoteSite)?(array)($remoteSite['observability']??[]):[];
    $customDomains=function_exists('vp3_cloud_hosting_domains_v200_for_site')
        ?array_map('vp3_cloud_hosting_domains_v200_public',vp3_cloud_hosting_domains_v200_for_site($siteId,$userId,$pdo))
        :[];
    return [
        'site_id'=>$siteId,
        'site_key'=>(string)$site['site_key'],
        'display_name'=>(string)$site['display_name'],
        'hostname'=>$site['canonical_hostname']??$site['requested_hostname']??null,
        'desired_state'=>(string)$site['desired_state'],
        'observed_state'=>(string)$site['observed_state'],
        'desired_revision'=>(int)$site['desired_revision'],
        'observed_revision'=>(int)$site['observed_revision'],
        'route_state'=>(string)$site['route_state'],
        'tls_state'=>(string)$site['tls_state'],
        'active_release_id'=>$site['active_release_id']??null,
        'previous_release_id'=>$site['previous_release_id']??null,
        'homeserver_status'=>$connectionStatus,
        'latest_deployment'=>$deployment,
        'custom_domains'=>$customDomains,
        'traffic'=>[
            'requests_total'=>(int)($traffic['requests_total']??0),
            'client_error_total'=>(int)($traffic['client_error_total']??0),
            'server_error_total'=>(int)($traffic['server_error_total']??0),
            'average_duration_ms'=>(float)($traffic['average_duration_ms']??0),
        ],
        'issues'=>array_values(array_unique(array_filter($issues))),
    ];
}

function vp3_cloud_hosting_agent_v130_list(array $user,?callable $remote=null,?PDO $pdo=null): array
{
    $pdo??=db();if(!$pdo)return [];
    $uid=(int)($user['id']??0);if($uid<1)return [];
    $out=[];
    foreach(vp3_cloud_hosting_sites_v100($uid,$pdo) as $site){
        $out[]=vp3_cloud_hosting_agent_v130_site_status($site,$remote,$pdo);
    }
    return $out;
}

function vp3_cloud_hosting_agent_v130_code(): string
{
    $alphabet='ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    $value='';
    for($i=0;$i<8;$i++)$value.=$alphabet[random_int(0,strlen($alphabet)-1)];
    return $value;
}

function vp3_cloud_hosting_agent_v130_prepare(
    array $user,
    string $actionType,
    array $payload,
    array $preview,
    ?int $siteId,
    int $conversationId=0,
    ?PDO $pdo=null
): array {
    $pdo??=db();if(!$pdo)throw new RuntimeException('Database connection is unavailable.');
    vp3_cloud_hosting_agent_v130_ensure_schema($pdo);
    $uid=(int)($user['id']??0);if($uid<1)throw new RuntimeException('A signed-in user is required.');
    $allowed=[
        'site.create','site.state','route.provision','site.reconcile','deployment.rollback',
        'deployment.promote','deployment.prune',
        'domain.attach','domain.canonical','domain.detach'
    ];
    if(!in_array($actionType,$allowed,true))throw new RuntimeException('Unsupported Hosting Agent action.');
    if($siteId!==null){
        $site=vp3_cloud_hosting_site_v100($siteId,$uid,$pdo);
        if($site===null)throw new RuntimeException('Hosted site is unavailable to this account.');
    }
    $code=vp3_cloud_hosting_agent_v130_code();
    $publicId='hostact_'.bin2hex(random_bytes(12));
    $idempotency='agent-hosting-'.hash('sha256',$uid.'|'.$publicId.'|'.$actionType);
    $stmt=$pdo->prepare("INSERT INTO cloud_hosting_agent_actions
      (public_id,user_id,conversation_id,site_id,action_type,payload_json,preview_json,confirmation_token_hash,idempotency_key,status,expires_at)
      VALUES (?,?,?,?,?,?,?,?,?,'prepared',DATE_ADD(UTC_TIMESTAMP(),INTERVAL 10 MINUTE))");
    $stmt->execute([
        $publicId,$uid,$conversationId>0?$conversationId:null,$siteId,$actionType,
        json_encode($payload,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE),
        json_encode($preview,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE),
        hash('sha256',$code),$idempotency,
    ]);
    return [
        'contract'=>'vp3.cloud-hosting-agent-action.v1',
        'action_id'=>$publicId,
        'action_type'=>$actionType,
        'preview'=>$preview,
        'requires_confirmation'=>true,
        'confirmation_code'=>$code,
        'expires_in_seconds'=>VP3_CLOUD_HOSTING_AGENT_CONFIRM_TTL_SECONDS,
    ];
}

function vp3_cloud_hosting_agent_v130_action(string $publicId,int $userId,?PDO $pdo=null): ?array
{
    $pdo??=db();if(!$pdo||$userId<1)return null;
    $stmt=$pdo->prepare('SELECT * FROM cloud_hosting_agent_actions WHERE public_id=? AND user_id=? LIMIT 1');
    $stmt->execute([$publicId,$userId]);
    $row=$stmt->fetch();
    return is_array($row)?$row:null;
}

function vp3_cloud_hosting_agent_v130_latest_prepared(int $userId,int $conversationId=0,?PDO $pdo=null): ?array
{
    $pdo??=db();if(!$pdo||$userId<1)return null;
    if($conversationId>0){
        $stmt=$pdo->prepare("SELECT * FROM cloud_hosting_agent_actions
          WHERE user_id=? AND conversation_id=? AND status='prepared' AND expires_at>UTC_TIMESTAMP()
          ORDER BY id DESC LIMIT 1");
        $stmt->execute([$userId,$conversationId]);
    }else{
        $stmt=$pdo->prepare("SELECT * FROM cloud_hosting_agent_actions
          WHERE user_id=? AND status='prepared' AND expires_at>UTC_TIMESTAMP()
          ORDER BY id DESC LIMIT 1");
        $stmt->execute([$userId]);
    }
    $row=$stmt->fetch();
    return is_array($row)?$row:null;
}

function vp3_cloud_hosting_agent_v130_execute(array $row,array $user,?callable $remote=null,?callable $providerTransport=null,?PDO $pdo=null): array
{
    $pdo??=db();if(!$pdo)throw new RuntimeException('Database connection is unavailable.');
    $uid=(int)($user['id']??0);
    if($uid<1||(int)$row['user_id']!==$uid)throw new RuntimeException('Hosting action does not belong to this user.');
    $payload=json_decode((string)$row['payload_json'],true);
    if(!is_array($payload))$payload=[];
    $type=(string)$row['action_type'];
    $siteId=(int)($row['site_id']??0);
    $site=$siteId>0?vp3_cloud_hosting_site_v100($siteId,$uid,$pdo):null;
    $key=(string)$row['idempotency_key'];

    if($type==='site.create'){
        $actionId=(int)($row['id']??0);
        $existingSiteId=(int)($row['site_id']??0);
        if($existingSiteId>0){
            $existingSite=vp3_cloud_hosting_site_v100($existingSiteId,$uid,$pdo);
            if($existingSite!==null)return ['site'=>$existingSite,'idempotent_replay'=>true];
        }
        $payload['_creation_key']='agent:'.(string)$row['public_id'];
        $created=vp3_cloud_hosting_create_site_v100($user,$payload,$uid);
        $createdId=(int)($created['id']??0);
        if($createdId<1)throw new RuntimeException('Hosted site creation did not return an identifier.');
        $pdo->prepare('UPDATE cloud_hosting_agent_actions SET site_id=? WHERE id=? AND user_id=?')->execute([$createdId,$actionId,$uid]);
        return ['site'=>$created,'idempotent_replay'=>$existingSiteId>0];
    }
    if($site===null)throw new RuntimeException('Hosted site no longer exists.');

    if($type==='site.state'){
        $state=(string)($payload['desired_state']??'');
        $fresh=vp3_cloud_hosting_set_desired_state_v100($site,$state,$uid,'agent_chat',$pdo);
        return vp3_cloud_hosting_v120_reconcile_site($fresh,$remote,$pdo);
    }
    if($type==='route.provision'){
        $result=vp3_cloud_hosting_v110_provision_dns($site,$key,$uid,$providerTransport);
        return ['route'=>$result['route']??null,'replayed'=>!empty($result['replayed']),'reused'=>!empty($result['reused'])];
    }
    if($type==='site.reconcile'){
        return vp3_cloud_hosting_v120_reconcile_site($site,$remote,$pdo);
    }
    if($type==='deployment.rollback'){
        return vp3_cloud_hosting_v120_rollback($site,$key,$uid,$remote,$pdo);
    }
    if($type==='deployment.promote'){
        return vp3_cloud_hosting_releases_v220_promote($site,(string)($payload['release_id']??''),$key,$uid,$remote,$pdo);
    }
    if($type==='deployment.prune'){
        return vp3_cloud_hosting_releases_v220_prune($site,(int)($payload['keep']??5),$key,$uid,$remote,$pdo);
    }
    if($type==='domain.attach'){
        $attached=vp3_cloud_hosting_domains_v200_attach($site,$user,(string)($payload['hostname']??''),$uid,$pdo);
        return [
            'domain'=>$attached['domain']??null,
            'dns_instructions_available_in_hosting_ui'=>true,
        ];
    }
    if($type==='domain.canonical'||$type==='domain.detach'){
        $domainId=(int)($payload['domain_id']??0);
        $domain=vp3_cloud_hosting_domains_v200_find($domainId,$uid,$pdo);
        if($domain===null||(int)$domain['site_id']!==$siteId)throw new RuntimeException('Custom domain is unavailable to this hosted site.');
        if($type==='domain.canonical')return vp3_cloud_hosting_domains_v200_set_canonical($domain,true,$uid,$pdo);
        return vp3_cloud_hosting_domains_v200_detach($domain,$uid,$pdo);
    }
    throw new RuntimeException('Unsupported Hosting Agent action.');
}

function vp3_cloud_hosting_agent_v130_confirm(
    array $user,
    string $code,
    int $conversationId=0,
    ?callable $remote=null,
    ?callable $providerTransport=null,
    ?PDO $pdo=null
): array {
    $pdo??=db();if(!$pdo)throw new RuntimeException('Database connection is unavailable.');
    vp3_cloud_hosting_agent_v130_ensure_schema($pdo);
    $uid=(int)($user['id']??0);if($uid<1)throw new RuntimeException('A signed-in user is required.');
    $code=strtoupper(trim($code));
    if(!preg_match('/^[A-Z2-9]{8}$/',$code))throw new RuntimeException('Enter the 8-character Hosting confirmation code.');
    $hash=hash('sha256',$code);
    $executionToken=bin2hex(random_bytes(16));

    $pdo->beginTransaction();
    try{
        $sql="SELECT * FROM cloud_hosting_agent_actions
          WHERE user_id=? AND status IN ('prepared','executing') AND expires_at>UTC_TIMESTAMP()
          AND confirmation_token_hash=?";
        $params=[$uid,$hash];
        if($conversationId>0){$sql.=' AND conversation_id=?';$params[]=$conversationId;}
        $sql.=' ORDER BY id DESC LIMIT 1 FOR UPDATE';
        $stmt=$pdo->prepare($sql);$stmt->execute($params);
        $row=$stmt->fetch();
        if(!is_array($row))throw new RuntimeException('Hosting confirmation code is invalid or expired.');
        if((string)$row['status']==='executing'){
            $lease=!empty($row['execution_expires_at'])?strtotime((string)$row['execution_expires_at'].' UTC'):false;
            if($lease!==false&&$lease>time())throw new RuntimeException('This Hosting action is already in progress.');
        }
        $stmt=$pdo->prepare("UPDATE cloud_hosting_agent_actions
          SET status='executing',execution_token=?,execution_expires_at=DATE_ADD(UTC_TIMESTAMP(),INTERVAL 5 MINUTE),
              confirmed_at=COALESCE(confirmed_at,UTC_TIMESTAMP()),error_message=''
          WHERE id=?");
        $stmt->execute([$executionToken,(int)$row['id']]);
        $pdo->commit();
    }catch(Throwable $e){
        if($pdo->inTransaction())$pdo->rollBack();
        throw $e;
    }

    try{
        $result=vp3_cloud_hosting_agent_v130_execute($row,$user,$remote,$providerTransport,$pdo);
        $safe=vp3_cloud_hosting_v120_public_remote($result);
        $stmt=$pdo->prepare("UPDATE cloud_hosting_agent_actions
          SET status='completed',result_json=?,error_message='',execution_token='',execution_expires_at=NULL,completed_at=UTC_TIMESTAMP()
          WHERE id=? AND execution_token=?");
        $stmt->execute([json_encode($safe,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE),(int)$row['id'],$executionToken]);
        if($stmt->rowCount()!==1)throw new RuntimeException('Hosting action execution lease was lost before completion.');
        return ['action_id'=>(string)$row['public_id'],'action_type'=>(string)$row['action_type'],'completed'=>true,'result'=>$safe];
    }catch(Throwable $e){
        $pdo->prepare("UPDATE cloud_hosting_agent_actions
          SET status='prepared',error_message=?,execution_token='',execution_expires_at=NULL
          WHERE id=? AND execution_token=?")
            ->execute([mb_substr($e->getMessage(),0,500),(int)$row['id'],$executionToken]);
        throw $e;
    }
}

function vp3_cloud_hosting_agent_v130_site_lines(array $sites): array
{
    $lines=[];
    foreach($sites as $site){
        $name=(string)$site['display_name'];
        $host=trim((string)($site['hostname']??''));
        $line='• '.$name;
        if($host!=='')$line.=' — '.$host;
        $line.=' — desired '.(string)$site['desired_state'].', observed '.(string)$site['observed_state'];
        if(!empty($site['issues']))$line.=' — '.implode(' ',array_slice((array)$site['issues'],0,2));
        $lines[]=$line;
    }
    return $lines;
}

function vp3_cloud_hosting_agent_v130_prepare_result(array $plan,string $answer): array
{
    return [
        'handled'=>true,
        'answer'=>$answer."\n\nConfirmation code: **".(string)$plan['confirmation_code']."**. Reply with “confirm hosting ".(string)$plan['confirmation_code']."” to execute.",
        'stem_media'=>[],'media'=>[],'actions'=>[],'sources'=>[],
        'hosting_plan'=>$plan,
    ];
}

function vp3_cloud_hosting_agent_v130_query(
    string $query,
    array $user,
    int $conversationId=0,
    ?callable $remote=null,
    ?callable $providerTransport=null,
    ?PDO $pdo=null
): array {
    $empty=vp3_cloud_hosting_agent_v130_empty();
    if(!vp3_cloud_hosting_agent_v130_intent($query)&&!preg_match('/\bconfirm\s+hosting\s+[A-Z2-9]{8}\b/i',$query))return $empty;
    $pdo??=db();if(!$pdo)return $empty;
    vp3_cloud_hosting_agent_v130_ensure_schema($pdo);
    $uid=(int)($user['id']??0);if($uid<1)return $empty;

    if(preg_match('/\bconfirm\s+hosting\s+([A-Z2-9]{8})\b/i',$query,$m)){
        try{
            $done=vp3_cloud_hosting_agent_v130_confirm($user,(string)$m[1],$conversationId,$remote,$providerTransport,$pdo);
            if(function_exists('agent_tool_log'))agent_tool_log($user,'hosting.confirm',$query,'success',['action_type'=>$done['action_type']??''],$conversationId);
            return [
                'handled'=>true,'answer'=>'The Hosting action completed successfully.',
                'stem_media'=>[],'media'=>[],'actions'=>[],'sources'=>[],'hosting_plan'=>$done,
            ];
        }catch(Throwable $e){
            return [
                'handled'=>true,'answer'=>'I could not complete that Hosting action: '.$e->getMessage(),
                'stem_media'=>[],'media'=>[],'actions'=>[],'sources'=>[],'hosting_plan'=>null,
            ];
        }
    }

    $q=mb_strtolower($query);
    $releaseId='';
    if(preg_match('/\b(release_[0-9a-f]{24})\b/i',$query,$releaseMatch))$releaseId=strtolower((string)$releaseMatch[1]);
    $customDomainHost='';
    if(preg_match('/\b(?:custom\s+domain|domain)\s+([a-z0-9][a-z0-9.-]+\.[a-z]{2,})\b/i',$query,$m)){
        $customDomainHost=strtolower((string)$m[1]);
    }elseif(preg_match('/\b([a-z0-9][a-z0-9.-]+\.[a-z]{2,})\b/i',$query,$m)&&str_contains($q,'custom domain')){
        $customDomainHost=strtolower((string)$m[1]);
    }
    $siteQuery=$customDomainHost!==''?str_ireplace($customDomainHost,'',$query):$query;
    $hint=vp3_cloud_hosting_agent_v130_extract_site_hint($siteQuery);
    $site=vp3_cloud_hosting_agent_v130_user_site($uid,$hint,$pdo);

    if(preg_match('/\b(?:list|show|what|which)\b.*\b(?:hosting|hosted\s+sites?|sites?)\b|\bmy\s+hosted\s+sites?\b/i',$query)){
        $sites=vp3_cloud_hosting_agent_v130_list($user,$remote,$pdo);
        $answer=$sites?'Your hosted sites:'."\n".implode("\n",vp3_cloud_hosting_agent_v130_site_lines($sites)):'You do not have any Cloud Hosting sites yet.';
        return ['handled'=>true,'answer'=>$answer,'stem_media'=>[],'media'=>[],'actions'=>[],'sources'=>[],'hosting_plan'=>null];
    }

    if(preg_match('/\b(?:releases?|release\s+history|deployment\s+history)\b/i',$query)
        &&preg_match('/\b(?:list|show|what|which|history)\b/i',$query)){
        if(!$site){
            return ['handled'=>true,'answer'=>'I need a specific hosted site name or hostname for that release-history check.','stem_media'=>[],'media'=>[],'actions'=>[],'sources'=>[],'hosting_plan'=>null];
        }
        try{
            $catalog=vp3_cloud_hosting_releases_v220_catalog($site,$remote,$pdo);
            $rows=(array)($catalog['releases']??[]);
            if(!$rows)$answer='There are no retained HomeServer releases for “'.(string)$site['display_name'].'” yet.';
            else{
                $lines=[];
                foreach(array_slice($rows,0,10) as $release){
                    $label=(string)($release['app_version']??'');
                    if($label==='')$label=(string)$release['release_id'];
                    $flags=[];
                    if(!empty($release['active']))$flags[]='active';
                    if(!empty($release['previous']))$flags[]='previous';
                    $lines[]='• '.$label.' — '.(string)$release['release_id'].($flags?' — '.implode(', ',$flags):'');
                }
                $answer='Retained releases for “'.(string)$site['display_name'].'”:'."\n".implode("\n",$lines);
            }
            return ['handled'=>true,'answer'=>$answer,'stem_media'=>[],'media'=>[],'actions'=>[],'sources'=>[],'hosting_plan'=>['read_only'=>true,'release_catalog'=>$catalog]];
        }catch(Throwable $e){
            return ['handled'=>true,'answer'=>'I could not load that release history: '.$e->getMessage(),'stem_media'=>[],'media'=>[],'actions'=>[],'sources'=>[],'hosting_plan'=>null];
        }
    }

    if(preg_match('/\b(?:status|health|offline|down|error|errors|traffic|requests|slow|latency|why)\b/i',$query)){
        if(!$site){
            return ['handled'=>true,'answer'=>'I need a specific hosted site name or hostname for that Hosting status check.','stem_media'=>[],'media'=>[],'actions'=>[],'sources'=>[],'hosting_plan'=>null];
        }
        $status=vp3_cloud_hosting_agent_v130_site_status($site,$remote,$pdo);
        $issues=(array)$status['issues'];
        $answer=(string)$status['display_name'].' is desired '.(string)$status['desired_state'].' and HomeServer currently reports '.(string)$status['observed_state'].'.';
        if($issues)$answer.="\n".implode("\n",array_map(static fn($x)=>'• '.$x,$issues));
        else $answer.="\nI do not see a current Hosting fault.";
        if(str_contains($q,'traffic')||str_contains($q,'request')||str_contains($q,'error')||str_contains($q,'slow')){
            $t=(array)$status['traffic'];
            $answer.="\nTraffic: ".(int)$t['requests_total']." requests, ".(int)$t['client_error_total']." client errors, ".(int)$t['server_error_total']." server errors, ".number_format((float)$t['average_duration_ms'],1)." ms average.";
        }
        return ['handled'=>true,'answer'=>$answer,'stem_media'=>[],'media'=>[],'actions'=>[],'sources'=>[],'hosting_plan'=>['read_only'=>true,'status'=>$status]];
    }

    if($customDomainHost!==''&&preg_match('/\bverify\b/i',$query)&&function_exists('vp3_cloud_hosting_domains_v200_find')){
        $stmt=$pdo->prepare('SELECT * FROM cloud_hosting_custom_domains WHERE owner_user_id=? AND hostname=? AND detached_at IS NULL LIMIT 1');
        $stmt->execute([$uid,$customDomainHost]);
        $domain=$stmt->fetch();
        if(!is_array($domain)){
            return ['handled'=>true,'answer'=>'That custom domain is not attached to your Hosting account.','stem_media'=>[],'media'=>[],'actions'=>[],'sources'=>[],'hosting_plan'=>null];
        }
        try{
            $ownership=vp3_cloud_hosting_domains_v200_verify_ownership($domain,$uid,null,$pdo);
            $routing=null;
            if((string)$ownership['verification_state']==='verified'){
                $fresh=vp3_cloud_hosting_domains_v200_find((int)$domain['id'],$uid,$pdo)??$domain;
                $routing=vp3_cloud_hosting_domains_v200_verify_routing($fresh,$uid,null,$pdo);
            }
            $state=$routing??$ownership;
            $answer='Custom domain '.$customDomainHost.': ownership '.(string)$state['verification_state'].', routing '.(string)$state['routing_state'].', TLS '.(string)$state['tls_state'].'.';
            return ['handled'=>true,'answer'=>$answer,'stem_media'=>[],'media'=>[],'actions'=>[],'sources'=>[],'hosting_plan'=>['read_only'=>true,'custom_domain'=>$state]];
        }catch(Throwable $e){
            return ['handled'=>true,'answer'=>'I could not verify that custom domain: '.$e->getMessage(),'stem_media'=>[],'media'=>[],'actions'=>[],'sources'=>[],'hosting_plan'=>null];
        }
    }

    if(preg_match('/\bcreate\b.*\b(?:hosted\s+site|hosting\s+site|website)\b/i',$query)){
        $name='';
        if(preg_match('/\b(?:named|called)\s+["\']?([^"\']+?)["\']?(?:\s+(?:at|on|with|using)\b|$)/i',$query,$m))$name=trim((string)$m[1]);
        if($name==='')return ['handled'=>true,'answer'=>'Tell me the name for the new hosted site, for example: “create a hosted site called Demo Site”.','stem_media'=>[],'media'=>[],'actions'=>[],'sources'=>[],'hosting_plan'=>null];
        $hostname=null;
        if(preg_match('/\b([a-z0-9][a-z0-9.-]+\.[a-z]{2,})\b/i',$query,$m))$hostname=strtolower((string)$m[1]);
        $runtime=preg_match('/\bphp\b/i',$query)?'php':'static';
        $payload=['display_name'=>$name,'requested_hostname'=>$hostname,'runtime_kind'=>$runtime];
        try{
            $plan=vp3_cloud_hosting_agent_v130_prepare($user,'site.create',$payload,['site_name'=>$name,'hostname'=>$hostname,'runtime'=>$runtime],null,$conversationId,$pdo);
            return vp3_cloud_hosting_agent_v130_prepare_result($plan,'I prepared creation of the hosted site “'.$name.'”. No site has been created yet.');
        }catch(Throwable $e){
            return ['handled'=>true,'answer'=>'I could not prepare that hosted site: '.$e->getMessage(),'stem_media'=>[],'media'=>[],'actions'=>[],'sources'=>[],'hosting_plan'=>null];
        }
    }

    if(!$site&&$customDomainHost===''){
        return ['handled'=>true,'answer'=>'I need a specific hosted site name or hostname before I can prepare that Hosting action.','stem_media'=>[],'media'=>[],'actions'=>[],'sources'=>[],'hosting_plan'=>null];
    }

    $actionType='';$payload=[];$preview=[];$intro='';
    if($customDomainHost!==''&&preg_match('/\b(?:attach|add|connect)\b/i',$query)){
        if(!$site){
            return ['handled'=>true,'answer'=>'Tell me which hosted site should receive custom domain '.$customDomainHost.'.','stem_media'=>[],'media'=>[],'actions'=>[],'sources'=>[],'hosting_plan'=>null];
        }
        $actionType='domain.attach';$payload=['hostname'=>$customDomainHost];
        $preview=['site'=>(string)$site['display_name'],'custom_domain'=>$customDomainHost];
        $intro='I prepared attachment of custom domain “'.$customDomainHost.'” to “'.(string)$site['display_name'].'”. This will create an ownership challenge and DNS instructions.';
    }elseif($customDomainHost!==''&&preg_match('/\b(?:canonical|primary)\b/i',$query)){
        $stmt=$pdo->prepare('SELECT * FROM cloud_hosting_custom_domains WHERE owner_user_id=? AND hostname=? AND detached_at IS NULL LIMIT 1');
        $stmt->execute([$uid,$customDomainHost]);$domain=$stmt->fetch();
        if(!is_array($domain))return ['handled'=>true,'answer'=>'That custom domain is not attached to your Hosting account.','stem_media'=>[],'media'=>[],'actions'=>[],'sources'=>[],'hosting_plan'=>null];
        $site=vp3_cloud_hosting_site_v100((int)$domain['site_id'],$uid,$pdo);
        if(!$site)return ['handled'=>true,'answer'=>'The hosted site for that custom domain could not be loaded.','stem_media'=>[],'media'=>[],'actions'=>[],'sources'=>[],'hosting_plan'=>null];
        $actionType='domain.canonical';$payload=['domain_id'=>(int)$domain['id']];
        $preview=['site'=>(string)$site['display_name'],'custom_domain'=>$customDomainHost,'canonical'=>true];
        $intro='I prepared making “'.$customDomainHost.'” the canonical public domain for “'.(string)$site['display_name'].'”.';
    }elseif($customDomainHost!==''&&preg_match('/\b(?:detach|remove|disconnect)\b/i',$query)){
        $stmt=$pdo->prepare('SELECT * FROM cloud_hosting_custom_domains WHERE owner_user_id=? AND hostname=? AND detached_at IS NULL LIMIT 1');
        $stmt->execute([$uid,$customDomainHost]);$domain=$stmt->fetch();
        if(!is_array($domain))return ['handled'=>true,'answer'=>'That custom domain is not attached to your Hosting account.','stem_media'=>[],'media'=>[],'actions'=>[],'sources'=>[],'hosting_plan'=>null];
        $site=vp3_cloud_hosting_site_v100((int)$domain['site_id'],$uid,$pdo);
        if(!$site)return ['handled'=>true,'answer'=>'The hosted site for that custom domain could not be loaded.','stem_media'=>[],'media'=>[],'actions'=>[],'sources'=>[],'hosting_plan'=>null];
        $actionType='domain.detach';$payload=['domain_id'=>(int)$domain['id']];
        $preview=['site'=>(string)$site['display_name'],'custom_domain'=>$customDomainHost];
        $intro='I prepared detaching custom domain “'.$customDomainHost.'”. Existing DNS records at the user’s DNS provider will not be deleted automatically.';
    }
    if($actionType===''&&$releaseId!==''&&preg_match('/\b(?:promote|activate|use|restore)\b/i',$query)){
        $actionType='deployment.promote';$payload=['release_id'=>$releaseId];
        $preview=['site'=>(string)$site['display_name'],'release_id'=>$releaseId];
        $intro='I prepared promotion of retained release “'.$releaseId.'” for “'.(string)$site['display_name'].'”. HomeServer will create a recovery point before activation.';
    }elseif($actionType===''&&preg_match('/\b(?:prune|trim|clean\s+up)\b.*\breleases?\b/i',$query)){
        $keep=5;
        if(preg_match('/\bkeep\s+(\d{1,2})\b/i',$query,$keepMatch))$keep=max(2,min(50,(int)$keepMatch[1]));
        $actionType='deployment.prune';$payload=['keep'=>$keep];
        $preview=['site'=>(string)$site['display_name'],'keep'=>$keep,'active_previous_protected'=>true];
        $intro='I prepared HomeServer release retention for “'.(string)$site['display_name'].'”, keeping at least '.$keep.' retained releases while always protecting active and previous releases.';
    }
    if($actionType===''){ if(preg_match('/\b(?:activate|enable|publish|bring\s+online)\b/i',$query)){
        $actionType='site.state';$payload=['desired_state'=>'active'];
        $preview=['site'=>(string)$site['display_name'],'desired_state'=>'active'];
        $intro='I prepared activation of “'.(string)$site['display_name'].'”. This can change the public serving state.';
    }elseif(preg_match('/\b(?:suspend|disable|take\s+offline)\b/i',$query)){
        $actionType='site.state';$payload=['desired_state'=>'suspended'];
        $preview=['site'=>(string)$site['display_name'],'desired_state'=>'suspended'];
        $intro='I prepared suspension of “'.(string)$site['display_name'].'”. This will stop the hosted site from serving.';
    }elseif(preg_match('/\b(?:provision|create|set\s+up)\b.*\b(?:dns|subdomain)\b|\b(?:dns|subdomain)\b.*\b(?:provision|create|set\s+up)\b/i',$query)){
        $actionType='route.provision';$payload=[];
        $preview=['site'=>(string)$site['display_name'],'hostname'=>$site['requested_hostname']??null,'provider'=>'cPanel DNS'];
        $intro='I prepared cPanel DNS provisioning for “'.(string)$site['display_name'].'”. This creates or reuses the public CNAME route.';
    }elseif(preg_match('/\b(?:rollback|roll\s+back|revert)\b/i',$query)){
        $actionType='deployment.rollback';$payload=[];
        $preview=['site'=>(string)$site['display_name'],'active_release_id'=>$site['active_release_id']??null,'previous_release_id'=>$site['previous_release_id']??null];
        $intro='I prepared rollback of “'.(string)$site['display_name'].'” to its previous HomeServer release.';
    }elseif(preg_match('/\b(?:reconcile|resync|sync|refresh)\b/i',$query)){
        $actionType='site.reconcile';$payload=[];
        $preview=['site'=>(string)$site['display_name'],'revision'=>(int)$site['desired_revision']];
        $intro='I prepared Cloud ↔ HomeServer reconciliation for “'.(string)$site['display_name'].'”.';
    }}

    if($actionType!==''){
        try{
            $plan=vp3_cloud_hosting_agent_v130_prepare($user,$actionType,$payload,$preview,(int)$site['id'],$conversationId,$pdo);
            if(function_exists('agent_tool_log'))agent_tool_log($user,'hosting.prepare',$query,'success',['action_type'=>$actionType,'site_id'=>(int)$site['id']],$conversationId);
            return vp3_cloud_hosting_agent_v130_prepare_result($plan,$intro);
        }catch(Throwable $e){
            return ['handled'=>true,'answer'=>'I could not prepare that Hosting action: '.$e->getMessage(),'stem_media'=>[],'media'=>[],'actions'=>[],'sources'=>[],'hosting_plan'=>null];
        }
    }

    if(preg_match('/\bdeploy\b/i',$query)){
        return [
            'handled'=>true,
            'answer'=>'I can inspect deployments and roll back a hosted site from Agent Chat. A new deployment also needs the deployment ZIP itself, so I will hand that file-bearing action to the Hosting deployment surface rather than accepting opaque file bytes from chat.',
            'stem_media'=>[],'media'=>[],'actions'=>[],'sources'=>[],'hosting_plan'=>['read_only'=>true,'requires_package_handoff'=>true],
        ];
    }

    return $empty;
}

function vp3_cloud_hosting_agent_v130_prompt(array $user): string
{
    $uid=(int)($user['id']??0);
    if($uid<1)return '';
    return 'Cloud Hosting tools: list and diagnose the user’s hosted sites; inspect route, deployment, HomeServer and traffic health; prepare site creation, activation/suspension, cPanel DNS provisioning, custom-domain attach/canonical/detach, release promotion/retention, reconciliation and rollback; inspect release history and verify custom-domain ownership/routing read-only. Consequential Hosting changes always require the explicit 8-character confirmation code returned by the prepare step and execute through a bounded server-side lease with idempotent retry protection. Never request or reveal cPanel API tokens, HomeServer credentials, route tokens, private keys, raw SQL or filesystem paths.';
}
