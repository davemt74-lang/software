<?php
declare(strict_types=1);

/**
 * Cloud Hosting V1 Section 2 — cPanel DNS provisioning + route lifecycle.
 *
 * cPanel is DNS provisioning authority only. Cloud edge remains TLS authority
 * and HomeServer remains execution authority.
 */

const VP3_CLOUD_HOSTING_V110='cloud-hosting-v110';
const VP3_CLOUD_HOSTING_PROVIDER_CPanel='cpanel_dns';

function vp3_cloud_hosting_v110_schema_ready(?PDO $pdo=null): bool
{
    $pdo??=db();
    return $pdo && table_exists('cloud_hosting_routes') && table_exists('cloud_hosting_provider_operations');
}

function vp3_cloud_hosting_v110_ensure_schema(?PDO $pdo=null): void
{
    $pdo??=db();
    if(!$pdo)throw new RuntimeException('Database connection is unavailable.');
    vp3_cloud_hosting_ensure_schema_v100($pdo);

    $pdo->exec("CREATE TABLE IF NOT EXISTS cloud_hosting_routes (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
      site_id BIGINT UNSIGNED NOT NULL,
      provider VARCHAR(40) NOT NULL DEFAULT 'cpanel_dns',
      zone_domain VARCHAR(253) NOT NULL,
      hostname VARCHAR(253) NOT NULL,
      record_type VARCHAR(12) NOT NULL DEFAULT 'CNAME',
      record_value VARCHAR(253) NOT NULL,
      dns_state VARCHAR(30) NOT NULL DEFAULT 'pending',
      tls_state VARCHAR(30) NOT NULL DEFAULT 'pending',
      last_provider_status VARCHAR(40) NOT NULL DEFAULT '',
      last_error_code VARCHAR(80) NOT NULL DEFAULT '',
      last_error_message VARCHAR(500) NOT NULL DEFAULT '',
      provisioned_at DATETIME NULL,
      verified_at DATETIME NULL,
      deactivated_at DATETIME NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      UNIQUE KEY uq_cloud_hosting_route_site (site_id),
      UNIQUE KEY uq_cloud_hosting_route_hostname (hostname),
      INDEX idx_cloud_hosting_route_state (dns_state,tls_state,id),
      CONSTRAINT fk_cloud_hosting_route_site FOREIGN KEY (site_id) REFERENCES cloud_hosting_sites(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS cloud_hosting_provider_operations (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
      site_id BIGINT UNSIGNED NOT NULL,
      request_key VARCHAR(120) NOT NULL,
      operation VARCHAR(80) NOT NULL,
      provider VARCHAR(40) NOT NULL,
      status VARCHAR(30) NOT NULL DEFAULT 'pending',
      response_code INT NOT NULL DEFAULT 0,
      response_json LONGTEXT NULL,
      error_code VARCHAR(80) NOT NULL DEFAULT '',
      error_message VARCHAR(500) NOT NULL DEFAULT '',
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      completed_at DATETIME NULL,
      UNIQUE KEY uq_cloud_hosting_provider_request (site_id,request_key),
      INDEX idx_cloud_hosting_provider_status (status,created_at,id),
      CONSTRAINT fk_cloud_hosting_provider_operation_site FOREIGN KEY (site_id) REFERENCES cloud_hosting_sites(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

function vp3_cloud_hosting_v110_normalize_domain(string $value,string $label='domain'): string
{
    $value=strtolower(trim($value));
    if($value===''||strlen($value)>253||str_contains($value,':')||!preg_match('/^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/',$value)){
        throw new RuntimeException('Enter a valid '.$label.'.');
    }
    return $value;
}

function vp3_cloud_hosting_v110_zone_domain(): string
{
    return vp3_cloud_hosting_v110_normalize_domain((string)setting('hosting_cpanel_zone_domain',''),'cPanel DNS zone domain');
}

function vp3_cloud_hosting_v110_ingress_hostname(): string
{
    return vp3_cloud_hosting_v110_normalize_domain((string)setting('hosting_public_ingress_hostname',''),'Cloud Hosting ingress hostname');
}

function vp3_cloud_hosting_v110_hostname_in_zone(string $hostname,string $zone): bool
{
    $hostname=vp3_cloud_hosting_v110_normalize_domain($hostname,'hosting hostname');
    $zone=vp3_cloud_hosting_v110_normalize_domain($zone,'cPanel DNS zone domain');
    return $hostname!==$zone && str_ends_with($hostname,'.'.$zone);
}

function vp3_cloud_hosting_v110_transport(
    string $url,
    array $headers,
    int $timeoutSeconds=15,
    ?callable $transport=null
): array {
    if($transport!==null){
        $result=$transport($url,$headers,$timeoutSeconds);
        if(!is_array($result))throw new RuntimeException('Hosting provider transport returned an invalid response.');
        return $result;
    }
    if(!function_exists('curl_init'))throw new RuntimeException('cURL is required for cPanel Hosting provisioning.');
    $ch=curl_init($url);
    if($ch===false)throw new RuntimeException('Could not initialize cPanel request.');
    curl_setopt_array($ch,[
        CURLOPT_RETURNTRANSFER=>true,
        CURLOPT_FOLLOWLOCATION=>false,
        CURLOPT_CONNECTTIMEOUT=>5,
        CURLOPT_TIMEOUT=>max(5,min(30,$timeoutSeconds)),
        CURLOPT_HTTPHEADER=>$headers,
        CURLOPT_USERAGENT=>'VP3-Cloud-Hosting/1.10',
        CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,
        CURLOPT_REDIR_PROTOCOLS=>CURLPROTO_HTTPS,
    ]);
    $body=curl_exec($ch);
    $status=(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE);
    $error=curl_error($ch);
    curl_close($ch);
    if($body===false)throw new RuntimeException('cPanel request failed: '.($error!==''?$error:'transport error'));
    return ['status'=>$status,'body'=>(string)$body];
}

function vp3_cloud_hosting_v110_cpanel_call(
    string $mode,
    string $module,
    string $function,
    array $params=[],
    ?callable $transport=null
): array {
    $config=vp3_cloud_hosting_cpanel_config_v100();
    if(empty($config['configured']))throw new RuntimeException('Configure the cPanel API server, username, and API token in Admin → AI / API Settings.');
    $server=vp3_cloud_hosting_normalize_cpanel_server_v100((string)$config['server']);
    $username=(string)$config['username'];
    $token=(string)$config['token'];
    if($token==='')throw new RuntimeException('The saved cPanel API token cannot be decrypted.');

    if($mode==='uapi'){
        $url=$server.'/execute/'.rawurlencode($module).'/'.rawurlencode($function);
        if($params)$url.='?'.http_build_query($params,'','&',PHP_QUERY_RFC3986);
    }elseif($mode==='api2'){
        $query=[
            'cpanel_jsonapi_user'=>$username,
            'cpanel_jsonapi_apiversion'=>2,
            'cpanel_jsonapi_module'=>$module,
            'cpanel_jsonapi_func'=>$function,
        ]+$params;
        $url=$server.'/json-api/cpanel?'.http_build_query($query,'','&',PHP_QUERY_RFC3986);
    }else{
        throw new RuntimeException('Unsupported cPanel API mode.');
    }

    $response=vp3_cloud_hosting_v110_transport($url,[
        'Accept: application/json',
        'Authorization: cpanel '.$username.':'.$token,
    ],15,$transport);
    $status=(int)($response['status']??0);
    $decoded=json_decode((string)($response['body']??''),true);
    if($status<200||$status>=300||!is_array($decoded)){
        throw new RuntimeException('cPanel API returned an invalid response.');
    }

    if($mode==='uapi'){
        $result=(array)($decoded['result']??[]);
        if((int)($result['status']??0)!==1){
            $errors=$result['errors']??null;
            $message=is_array($errors)?implode('; ',array_map('strval',$errors)):trim((string)$errors);
            throw new RuntimeException($message!==''?'cPanel UAPI: '.$message:'cPanel UAPI request failed.');
        }
    }else{
        $cpanel=(array)($decoded['cpanelresult']??[]);
        $data=(array)($cpanel['data']??[]);
        $first=is_array($data[0]??null)?$data[0]:[];
        $successRaw=$first['result']??$first['status']??($cpanel['event']['result']??null);
        if($successRaw===null)throw new RuntimeException('cPanel API2 response did not include an explicit result status.');
        $success=(int)$successRaw;
        if($success!==1){
            $message=trim((string)($first['reason']??$first['error']??$cpanel['error']??''));
            throw new RuntimeException($message!==''?'cPanel API2: '.$message:'cPanel API2 request failed.');
        }
    }
    return ['status'=>$status,'payload'=>$decoded];
}

function vp3_cloud_hosting_v110_provider_response_public(array $result): array
{
    return [
        'status'=>(int)($result['status']??0),
        'ok'=>true,
    ];
}

function vp3_cloud_hosting_v110_begin_operation(PDO $pdo,int $siteId,string $requestKey,string $operation): array
{
    $requestKey=trim($requestKey);
    if($requestKey===''||strlen($requestKey)>120)throw new RuntimeException('A valid idempotency request key is required.');
    $stmt=$pdo->prepare('SELECT * FROM cloud_hosting_provider_operations WHERE site_id=? AND request_key=? LIMIT 1');
    $stmt->execute([$siteId,$requestKey]);
    $existing=$stmt->fetch();
    if(is_array($existing)){
        if((string)$existing['operation']!==$operation)throw new RuntimeException('Idempotency key was already used for a different Hosting operation.');
        return ['replay'=>true,'row'=>$existing];
    }
    $stmt=$pdo->prepare("INSERT INTO cloud_hosting_provider_operations (site_id,request_key,operation,provider,status) VALUES (?,?,?,'cpanel_dns','pending')");
    $stmt->execute([$siteId,$requestKey,$operation]);
    return ['replay'=>false,'id'=>(int)$pdo->lastInsertId()];
}

function vp3_cloud_hosting_v110_finish_operation(PDO $pdo,int $id,string $status,int $code,array $response=[],string $error=''): void
{
    $public=[];
    foreach($response as $key=>$value){
        if(preg_match('/token|authorization|secret|credential|cookie/i',(string)$key))continue;
        if(is_scalar($value)||$value===null)$public[(string)$key]=$value;
    }
    $stmt=$pdo->prepare('UPDATE cloud_hosting_provider_operations SET status=?,response_code=?,response_json=?,error_code=?,error_message=?,completed_at=NOW() WHERE id=?');
    $stmt->execute([
        $status,$code,json_encode($public,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE),
        $error!==''?'provider_error':'',mb_substr($error,0,500),$id,
    ]);
}

function vp3_cloud_hosting_v110_site_revision(PDO $pdo,int $siteId): int
{
    $stmt=$pdo->prepare('SELECT desired_revision FROM cloud_hosting_sites WHERE id=? LIMIT 1');
    $stmt->execute([$siteId]);
    return max(0,(int)$stmt->fetchColumn());
}

function vp3_cloud_hosting_v110_bump_site_route_state(
    PDO $pdo,
    int $siteId,
    ?string $canonicalHostname,
    ?string $routeState,
    ?string $tlsState,
    string $errorCode='',
    string $errorMessage=''
): int {
    $sets=['desired_revision=desired_revision+1','last_error_code=?','last_error_message=?'];
    $params=[$errorCode,mb_substr($errorMessage,0,500)];
    if($canonicalHostname!==null){$sets[]='canonical_hostname=?';$params[]=$canonicalHostname;}
    if($routeState!==null){$sets[]='route_state=?';$params[]=$routeState;}
    if($tlsState!==null){$sets[]='tls_state=?';$params[]=$tlsState;}
    $params[]=$siteId;
    $stmt=$pdo->prepare('UPDATE cloud_hosting_sites SET '.implode(',',$sets).' WHERE id=?');
    $stmt->execute($params);
    if($stmt->rowCount()!==1)throw new RuntimeException('Hosted site route state could not be updated.');
    return vp3_cloud_hosting_v110_site_revision($pdo,$siteId);
}

function vp3_cloud_hosting_v110_route_for_site(int $siteId,?PDO $pdo=null): ?array
{
    $pdo??=db();if(!$pdo)return null;
    $stmt=$pdo->prepare('SELECT * FROM cloud_hosting_routes WHERE site_id=? LIMIT 1');
    $stmt->execute([$siteId]);
    $row=$stmt->fetch();
    return is_array($row)?$row:null;
}

function vp3_cloud_hosting_v110_assert_provision_entitled(array $site,?PDO $pdo=null): void
{
    $pdo??=db();
    if(!$pdo)throw new RuntimeException('Database connection is unavailable.');
    $userId=(int)($site['user_id']??0);
    if($userId<1)throw new RuntimeException('A valid hosting owner is required.');
    $stmt=$pdo->prepare('SELECT * FROM users WHERE id=? LIMIT 1');
    $stmt->execute([$userId]);
    $user=$stmt->fetch();
    if(!is_array($user))throw new RuntimeException('Hosting owner could not be loaded.');
    $snapshot=vp3_cloud_hosting_entitlement_snapshot_v100($user);
    if(empty($snapshot['entitlements']['hosting.access']['enabled'])){
        throw new RuntimeException('This account package does not currently include Cloud Hosting.');
    }
    $limit=vp3_cloud_hosting_limit_v100($snapshot,'hosting.subdomains');
    if($limit===0)throw new RuntimeException('This account package does not currently include hosting subdomains.');
    $siteId=(int)($site['id']??0);
    $existing=vp3_cloud_hosting_v110_route_for_site($siteId,$pdo);
    if($existing!==null)return;
    if($limit!==null){
        $stmt=$pdo->prepare("SELECT COUNT(*) FROM cloud_hosting_routes r JOIN cloud_hosting_sites s ON s.id=r.site_id WHERE s.user_id=? AND r.deactivated_at IS NULL");
        $stmt->execute([$userId]);
        if((int)$stmt->fetchColumn()>=$limit){
            throw new RuntimeException('This account has reached its hosting subdomain limit.');
        }
    }
}


function vp3_cloud_hosting_v110_claim_route(PDO $pdo,int $siteId,string $zone,string $hostname,string $target): array
{
    $pdo->beginTransaction();
    try{
        $stmt=$pdo->prepare('SELECT id FROM cloud_hosting_sites WHERE id=? FOR UPDATE');
        $stmt->execute([$siteId]);
        if(!(int)$stmt->fetchColumn())throw new RuntimeException('Hosted site no longer exists.');
        $route=vp3_cloud_hosting_v110_route_for_site($siteId,$pdo);
        if($route!==null){
            $same=(string)$route['zone_domain']===$zone
                && (string)$route['hostname']===$hostname
                && (string)$route['record_type']==='CNAME'
                && rtrim(strtolower((string)$route['record_value']),'.')===rtrim(strtolower($target),'.');
            if(!$same)throw new RuntimeException('This hosted site already has a different DNS route.');
            if(in_array((string)$route['dns_state'],['provisioned','verified'],true)){
                $pdo->commit();
                return ['claimed'=>false,'reusable'=>true,'route'=>$route];
            }
            if((string)$route['dns_state']==='provisioning'){
                throw new RuntimeException('DNS provisioning is already in progress for this hosted site.');
            }
            $stmt=$pdo->prepare("UPDATE cloud_hosting_routes SET dns_state='provisioning',last_provider_status='requesting',last_error_code='',last_error_message='',deactivated_at=NULL WHERE site_id=?");
            $stmt->execute([$siteId]);
        }else{
            $stmt=$pdo->prepare("INSERT INTO cloud_hosting_routes
              (site_id,provider,zone_domain,hostname,record_type,record_value,dns_state,tls_state,last_provider_status)
              VALUES (?,'cpanel_dns',?,?, 'CNAME',?,'provisioning','pending','requesting')");
            $stmt->execute([$siteId,$zone,$hostname,$target]);
        }
        $pdo->commit();
        return ['claimed'=>true,'reusable'=>false,'route'=>vp3_cloud_hosting_v110_route_for_site($siteId,$pdo)];
    }catch(Throwable $e){
        if($pdo->inTransaction())$pdo->rollBack();
        throw $e;
    }
}


function vp3_cloud_hosting_v110_provision_dns(
    array $site,
    string $requestKey,
    ?int $actorUserId=null,
    ?callable $transport=null
): array {
    $pdo=db();if(!$pdo)throw new RuntimeException('Database connection is unavailable.');
    vp3_cloud_hosting_v110_ensure_schema($pdo);
    $siteId=(int)($site['id']??0);
    $userId=(int)($site['user_id']??0);
    if($siteId<1||$userId<1)throw new RuntimeException('A valid hosted site is required.');
    vp3_cloud_hosting_v110_assert_provision_entitled($site,$pdo);
    $hostname=vp3_cloud_hosting_normalize_hostname_v100($site['requested_hostname']??null);
    if($hostname===null)throw new RuntimeException('Assign a hosting subdomain before provisioning DNS.');
    $zone=vp3_cloud_hosting_v110_zone_domain();
    if(!vp3_cloud_hosting_v110_hostname_in_zone($hostname,$zone))throw new RuntimeException('The requested hosting hostname is outside the configured cPanel DNS zone.');
    $target=vp3_cloud_hosting_v110_ingress_hostname();
    $recordName=substr($hostname,0,-strlen('.'.$zone));
    if($recordName===''||$recordName===$hostname)throw new RuntimeException('Could not derive a safe cPanel DNS record name.');

    $op=vp3_cloud_hosting_v110_begin_operation($pdo,$siteId,$requestKey,'dns.provision');
    if(!empty($op['replay'])){
        $route=vp3_cloud_hosting_v110_route_for_site($siteId,$pdo);
        if($route!==null&&in_array((string)$route['dns_state'],['provisioned','verified'],true)){
            return ['replayed'=>true,'route'=>$route];
        }
        throw new RuntimeException('The previous DNS provisioning attempt did not complete successfully.');
    }

    $operationId=(int)$op['id'];
    try{
        $claim=vp3_cloud_hosting_v110_claim_route($pdo,$siteId,$zone,$hostname,$target);
        if(!empty($claim['reusable'])){
            vp3_cloud_hosting_v110_finish_operation($pdo,$operationId,'succeeded',200,['reused_existing_route'=>true]);
            return ['replayed'=>false,'reused'=>true,'route'=>$claim['route']];
        }
        $result=vp3_cloud_hosting_v110_cpanel_call('api2','ZoneEdit','add_zone_record',[
            'domain'=>$zone,
            'name'=>$recordName,
            'type'=>'CNAME',
            'cname'=>$target.'.',
            'ttl'=>300,
            'class'=>'IN',
        ],$transport);

        $stmt=$pdo->prepare("UPDATE cloud_hosting_routes SET
          dns_state='provisioned',tls_state='pending',last_provider_status='success',
          last_error_code='',last_error_message='',provisioned_at=NOW(),deactivated_at=NULL
          WHERE site_id=? AND dns_state='provisioning'");
        $stmt->execute([$siteId]);
        if($stmt->rowCount()!==1)throw new RuntimeException('Hosting DNS route lost its provisioning claim.');

        $revision=vp3_cloud_hosting_v110_bump_site_route_state($pdo,$siteId,$hostname,'provisioned','pending');
        vp3_cloud_hosting_event_v100($pdo,$siteId,'dns.provisioned','provisioned',$revision,$actorUserId,[
            'hostname'=>$hostname,'record_type'=>'CNAME','record_value'=>$target,'provider'=>'cpanel_dns',
        ]);
        vp3_cloud_hosting_v110_finish_operation($pdo,$operationId,'succeeded',(int)$result['status'],vp3_cloud_hosting_v110_provider_response_public($result));
    }catch(Throwable $e){
        vp3_cloud_hosting_v110_finish_operation($pdo,$operationId,'failed',0,[],$e->getMessage());
        $pdo->prepare("UPDATE cloud_hosting_routes SET dns_state='failed',last_provider_status='failed',last_error_code='dns_provision_failed',last_error_message=? WHERE site_id=? AND dns_state='provisioning'")
            ->execute([mb_substr($e->getMessage(),0,500),$siteId]);
        $pdo->prepare("UPDATE cloud_hosting_sites SET route_state='failed',last_error_code='dns_provision_failed',last_error_message=? WHERE id=?")
            ->execute([mb_substr($e->getMessage(),0,500),$siteId]);
        throw $e;
    }

    return ['replayed'=>false,'route'=>vp3_cloud_hosting_v110_route_for_site($siteId,$pdo)];
}

function vp3_cloud_hosting_v110_verify_dns(array $site,?int $actorUserId=null,?callable $resolver=null): array
{
    $pdo=db();if(!$pdo)throw new RuntimeException('Database connection is unavailable.');
    $siteId=(int)($site['id']??0);if($siteId<1)throw new RuntimeException('A valid hosted site is required.');
    $route=vp3_cloud_hosting_v110_route_for_site($siteId,$pdo);
    if($route===null||!in_array((string)$route['dns_state'],['provisioned','pending'],true))throw new RuntimeException('DNS must be provisioned before it can be verified.');
    $hostname=(string)$route['hostname'];
    $target=rtrim(strtolower((string)$route['record_value']),'.');
    $records=$resolver!==null?$resolver($hostname):dns_get_record($hostname,DNS_CNAME);
    if(!is_array($records))$records=[];
    $matched=false;
    foreach($records as $record){
        if(!is_array($record))continue;
        $candidate=rtrim(strtolower((string)($record['target']??'')),'.');
        if($candidate!==''&&hash_equals($target,$candidate)){$matched=true;break;}
    }
    if(!$matched){
        $pdo->prepare("UPDATE cloud_hosting_routes SET dns_state='pending',last_provider_status='waiting_dns',last_error_code='',last_error_message='' WHERE site_id=?")->execute([$siteId]);
        $stmt=$pdo->prepare('SELECT route_state FROM cloud_hosting_sites WHERE id=? LIMIT 1');
        $stmt->execute([$siteId]);
        if((string)$stmt->fetchColumn()!=='pending'){
            vp3_cloud_hosting_v110_bump_site_route_state($pdo,$siteId,null,'pending',null);
        }
        return vp3_cloud_hosting_v110_route_for_site($siteId,$pdo)??[];
    }
    $pdo->prepare("UPDATE cloud_hosting_routes SET dns_state='verified',last_provider_status='verified',verified_at=NOW() WHERE site_id=?")->execute([$siteId]);
    $revision=vp3_cloud_hosting_v110_bump_site_route_state($pdo,$siteId,null,'verified',null);
    vp3_cloud_hosting_event_v100($pdo,$siteId,'dns.verified','verified',$revision,$actorUserId,[
        'hostname'=>$hostname,'provider'=>'cpanel_dns',
    ]);
    return vp3_cloud_hosting_v110_route_for_site($siteId,$pdo)??[];
}

function vp3_cloud_hosting_v110_mark_tls_state(array $site,string $state,?int $actorUserId=null): array
{
    $state=strtolower(trim($state));
    if(!in_array($state,['pending','active','renewing','failed'],true))throw new RuntimeException('Unsupported Cloud-edge TLS state.');
    $pdo=db();if(!$pdo)throw new RuntimeException('Database connection is unavailable.');
    $siteId=(int)($site['id']??0);if($siteId<1)throw new RuntimeException('A valid hosted site is required.');
    $route=vp3_cloud_hosting_v110_route_for_site($siteId,$pdo);
    if($route===null)throw new RuntimeException('DNS route is not provisioned.');
    if($state==='active'&&(string)$route['dns_state']!=='verified')throw new RuntimeException('DNS must be verified before Cloud-edge TLS can become active.');
    if((string)$route['tls_state']===$state)return $route;
    $pdo->prepare('UPDATE cloud_hosting_routes SET tls_state=? WHERE site_id=?')->execute([$state,$siteId]);
    $revision=vp3_cloud_hosting_v110_bump_site_route_state($pdo,$siteId,null,null,$state);
    vp3_cloud_hosting_event_v100($pdo,$siteId,'tls.'.$state,$state,$revision,$actorUserId,[
        'hostname'=>(string)$route['hostname'],'authority'=>'cloud_edge',
    ]);
    return vp3_cloud_hosting_v110_route_for_site($siteId,$pdo)??[];
}

function vp3_cloud_hosting_v110_desired_projection(array $site,?PDO $pdo=null): array
{
    $pdo??=db();
    if(!$pdo)throw new RuntimeException('Database connection is unavailable.');
    $siteId=(int)($site['id']??0);
    if($siteId<1)throw new RuntimeException('A valid hosted site is required.');
    $fresh=vp3_cloud_hosting_site_v100($siteId,(int)($site['user_id']??0),$pdo);
    if($fresh===null)throw new RuntimeException('Hosted site could not be loaded.');
    $base=vp3_cloud_hosting_desired_projection_v100($fresh);
    $route=vp3_cloud_hosting_v110_route_for_site($siteId,$pdo);
    $base['public_route']=$route===null?null:[
        'hostname'=>(string)$route['hostname'],
        'dns_state'=>(string)$route['dns_state'],
        'tls_state'=>(string)$route['tls_state'],
        'ready'=>(string)$route['dns_state']==='verified'&&(string)$route['tls_state']==='active',
        'provider'=>'cpanel_dns',
        'record_type'=>(string)$route['record_type'],
        'record_value'=>(string)$route['record_value'],
        'tls_authority'=>'cloud_edge',
    ];
    return $base;
}


function vp3_cloud_hosting_v110_public_state(): array
{
    $cpanel=vp3_cloud_hosting_cpanel_public_state_v100();
    return [
        'contract'=>'vp3.cloud-hosting-routing.v1',
        'cpanel_configured'=>(bool)$cpanel['configured'],
        'zone_domain'=>trim((string)setting('hosting_cpanel_zone_domain','')),
        'ingress_hostname'=>trim((string)setting('hosting_public_ingress_hostname','')),
        'dns_provider'=>'cpanel',
        'dns_record_type'=>'CNAME',
        'dns_api'=>'cPanel API2 ZoneEdit::add_zone_record (no UAPI equivalent)',
        'tls_authority'=>'cloud_edge',
        'cpanel_tls_authority'=>false,
        'homeserver_public_listener'=>false,
    ];
}
