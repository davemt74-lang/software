<?php
declare(strict_types=1);

/**
 * Tracky V2.78 Section 1 — read-only multi-site / multi-node topology mirror.
 *
 * Authority assignment and topology mutation remain local to Tracky/HomeServer.
 * Cloud accepts only compact governed summaries for awareness and later federation.
 */
const VP3_TRACKY_TOPOLOGY_V278='vp3-tracky-topology-v278-20260927';
const VP3_TRACKY_TOPOLOGY_PROTOCOL_V278='physical_site_topology.v1';

function tracky_v278_schema_ready(?PDO $pdo=null): bool
{
    $pdo??=db();
    return $pdo?table_exists('tracky_cloud_site_topology'):false;
}

function tracky_v278_ensure_schema(?PDO $pdo=null): void
{
    $pdo??=db();
    if(!$pdo)throw new RuntimeException('Database connection is unavailable.');
    $pdo->exec("CREATE TABLE IF NOT EXISTS tracky_cloud_site_topology (
      user_id INT UNSIGNED NOT NULL,
      reporting_site_id VARCHAR(100) NOT NULL,
      protocol_version VARCHAR(60) NOT NULL DEFAULT 'physical_site_topology.v1',
      schema_version INT UNSIGNED NOT NULL DEFAULT 1,
      topology_revision BIGINT UNSIGNED NOT NULL DEFAULT 0,
      site_count INT UNSIGNED NOT NULL DEFAULT 0,
      device_count INT UNSIGNED NOT NULL DEFAULT 0,
      authority_count INT UNSIGNED NOT NULL DEFAULT 0,
      mobile_device_count INT UNSIGNED NOT NULL DEFAULT 0,
      summary_json LONGTEXT NOT NULL,
      fingerprint CHAR(64) NOT NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      PRIMARY KEY (user_id,reporting_site_id),
      INDEX idx_tracky_topology_updated (user_id,updated_at),
      CONSTRAINT fk_tracky_topology_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

function tracky_v278_text(mixed $value,int $max=160,bool $required=false,string $label='value'): string
{
    $text=mb_strimwidth(trim((string)($value??'')),0,max(1,$max),'');
    if($required&&$text==='')throw new RuntimeException('Tracky site topology '.$label.' is required.');
    return $text;
}

function tracky_v278_uuid(mixed $value,string $label): string
{
    $value=strtolower(tracky_v278_text($value,64,true,$label));
    if(!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',$value)){
        throw new RuntimeException('Tracky site topology '.$label.' must be a UUID.');
    }
    return $value;
}

function tracky_v278_token(mixed $value,string $label): string
{
    $value=strtolower(tracky_v278_text($value,64,true,$label));
    if(!preg_match('/^[a-z][a-z0-9_.:-]{1,63}$/',$value)){
        throw new RuntimeException('Tracky site topology '.$label.' is invalid.');
    }
    return $value;
}

function tracky_v278_token_list(mixed $value,string $label,int $max=64): array
{
    $items=is_array($value)?array_values($value):[];
    if(count($items)>$max)throw new RuntimeException('Tracky site topology '.$label.' exceeds the limit.');
    $out=[];
    foreach($items as $item)$out[]=tracky_v278_token($item,$label);
    $out=array_values(array_unique($out));sort($out,SORT_STRING);
    return $out;
}

function tracky_v278_site(array $input): array
{
    $allowed=['id','label','kind','status','device_count','authority_device_id','authority_epoch','profiles','mobile_device_count'];
    foreach(array_keys($input) as $key){
        if(!in_array((string)$key,$allowed,true))throw new RuntimeException('Tracky site topology site contains unsupported field: '.(string)$key);
    }
    $authority=tracky_v278_text($input['authority_device_id']??'',64);
    if($authority!=='')$authority=tracky_v278_uuid($authority,'authority device id');
    $status=strtolower(tracky_v278_text($input['status']??'active',20));
    if(!in_array($status,['active','inactive','retired'],true))throw new RuntimeException('Tracky site topology site status is invalid.');
    return [
      'id'=>tracky_v278_uuid($input['id']??'','site id'),
      'label'=>tracky_v278_text($input['label']??'',160,true,'site label'),
      'kind'=>tracky_v278_token($input['kind']??'physical_site','site kind'),
      'status'=>$status,
      'device_count'=>max(0,min(10000,(int)($input['device_count']??0))),
      'authority_device_id'=>$authority,
      'authority_epoch'=>max(0,(int)($input['authority_epoch']??0)),
      'profiles'=>tracky_v278_token_list($input['profiles']??[],'hardware profile',16),
      'mobile_device_count'=>max(0,min(10000,(int)($input['mobile_device_count']??0))),
    ];
}

function tracky_v278_device(array $input): array
{
    $allowed=['id','label','site_id','hardware_profile','hardware_profile_label','mobility','trust_state','roles','capabilities'];
    foreach(array_keys($input) as $key){
        if(!in_array((string)$key,$allowed,true))throw new RuntimeException('Tracky site topology device contains unsupported field: '.(string)$key);
    }
    $siteId=tracky_v278_text($input['site_id']??'',64);
    if($siteId!=='')$siteId=tracky_v278_uuid($siteId,'device site id');
    $mobility=strtolower(tracky_v278_text($input['mobility']??'unknown',20));
    if(!in_array($mobility,['fixed','mobile','portable','unknown'],true))throw new RuntimeException('Tracky site topology device mobility is invalid.');
    $trust=strtolower(tracky_v278_text($input['trust_state']??'pending',20));
    if(!in_array($trust,['untrusted','pending','trusted','revoked'],true))throw new RuntimeException('Tracky site topology device trust state is invalid.');
    return [
      'id'=>tracky_v278_uuid($input['id']??'','device id'),
      'label'=>tracky_v278_text($input['label']??'',160,true,'device label'),
      'site_id'=>$siteId,
      'hardware_profile'=>tracky_v278_token($input['hardware_profile']??'custom','hardware profile'),
      'hardware_profile_label'=>tracky_v278_text($input['hardware_profile_label']??'Custom',80,true,'hardware profile label'),
      'mobility'=>$mobility,
      'trust_state'=>$trust,
      'roles'=>tracky_v278_token_list($input['roles']??[],'device role'),
      'capabilities'=>tracky_v278_token_list($input['capabilities']??[],'device capability',128),
    ];
}

function tracky_v278_relationship(array $input): array
{
    $allowed=['subject_id','type','object_id'];
    foreach(array_keys($input) as $key){
        if(!in_array((string)$key,$allowed,true))throw new RuntimeException('Tracky site topology relationship contains unsupported field: '.(string)$key);
    }
    return [
      'subject_id'=>tracky_v278_uuid($input['subject_id']??'','relationship subject'),
      'type'=>tracky_v278_token($input['type']??'','relationship type'),
      'object_id'=>tracky_v278_uuid($input['object_id']??'','relationship object'),
    ];
}

function tracky_v278_normalize(array $input): array
{
    tracky_cloud_v270_assert_governed_value($input,'site_topology');
    $allowed=['protocol','schema_version','revision','generated_at','summary_only','cloud_read_only','authority_assignment','sites','devices','relationships'];
    foreach(array_keys($input) as $key){
        if(!in_array((string)$key,$allowed,true))throw new RuntimeException('Tracky site topology summary contains unsupported field: '.(string)$key);
    }
    if((string)($input['protocol']??'')!==VP3_TRACKY_TOPOLOGY_PROTOCOL_V278)throw new RuntimeException('Tracky site topology protocol is unsupported.');
    if(empty($input['summary_only'])||empty($input['cloud_read_only']))throw new RuntimeException('Tracky Cloud accepts read-only site topology summaries only.');
    if((string)($input['authority_assignment']??'')!=='local_only')throw new RuntimeException('Tracky site topology authority assignment must remain local.');

    $sites=is_array($input['sites']??null)?array_values($input['sites']):[];
    $devices=is_array($input['devices']??null)?array_values($input['devices']):[];
    $relationships=is_array($input['relationships']??null)?array_values($input['relationships']):[];
    if(count($sites)>128||count($devices)>512||count($relationships)>1024)throw new RuntimeException('Tracky site topology summary exceeds Cloud limits.');

    $out=[
      'protocol'=>VP3_TRACKY_TOPOLOGY_PROTOCOL_V278,
      'schema_version'=>max(1,min(100,(int)($input['schema_version']??1))),
      'revision'=>max(0,(int)($input['revision']??0)),
      'generated_at'=>tracky_v278_text($input['generated_at']??'',64),
      'summary_only'=>true,
      'cloud_read_only'=>true,
      'authority_assignment'=>'local_only',
      'sites'=>[],'devices'=>[],'relationships'=>[],
    ];
    foreach($sites as $site){if(!is_array($site))throw new RuntimeException('Tracky site topology site is invalid.');$out['sites'][]=tracky_v278_site($site);}
    foreach($devices as $device){if(!is_array($device))throw new RuntimeException('Tracky site topology device is invalid.');$out['devices'][]=tracky_v278_device($device);}
    foreach($relationships as $rel){if(!is_array($rel))throw new RuntimeException('Tracky site topology relationship is invalid.');$out['relationships'][]=tracky_v278_relationship($rel);}

    $siteIds=array_fill_keys(array_column($out['sites'],'id'),true);
    $deviceIds=array_fill_keys(array_column($out['devices'],'id'),true);
    if(count($siteIds)!==count($out['sites'])||count($deviceIds)!==count($out['devices']))throw new RuntimeException('Tracky site topology contains duplicate stable identities.');
    foreach($out['devices'] as $device){
        if($device['site_id']!==''&&!isset($siteIds[$device['site_id']]))throw new RuntimeException('Tracky site topology device references an unknown site.');
        if($device['mobility']==='mobile'&&in_array('site_authority',$device['roles'],true))throw new RuntimeException('Tracky site topology mobile device cannot advertise site authority.');
    }
    foreach($out['sites'] as $site){
        if($site['authority_device_id']!==''){
            if(!isset($deviceIds[$site['authority_device_id']]))throw new RuntimeException('Tracky site topology authority references an unknown device.');
            $matches=array_values(array_filter($out['devices'],static fn($d)=>$d['id']===$site['authority_device_id']));
            $authority=$matches[0]??[];
            if(($authority['site_id']??'')!==$site['id'])throw new RuntimeException('Tracky site topology authority device belongs to another site.');
            if(($authority['trust_state']??'')!=='trusted'||!in_array('site_authority',$authority['roles']??[],true)||($authority['mobility']??'')==='mobile'){
                throw new RuntimeException('Tracky site topology authority device is not locally eligible.');
            }
        }
    }
    foreach($out['relationships'] as $rel){
        if(!isset($siteIds[$rel['subject_id']])&&!isset($deviceIds[$rel['subject_id']]))throw new RuntimeException('Tracky site topology relationship subject is unknown.');
        if(!isset($siteIds[$rel['object_id']])&&!isset($deviceIds[$rel['object_id']]))throw new RuntimeException('Tracky site topology relationship object is unknown.');
    }
    return $out;
}

function tracky_v278_ingest(PDO $pdo,int $userId,string $reportingSiteId,array $input): array
{
    if($userId<1)throw new RuntimeException('Authentication required.');
    $reportingSiteId=tracky_cloud_v270_site_id($reportingSiteId);
    tracky_v278_ensure_schema($pdo);
    $summary=tracky_v278_normalize($input);
    $json=tracky_cloud_v270_json($summary);
    $fingerprint=hash('sha256',$json);
    $q=$pdo->prepare('SELECT fingerprint FROM tracky_cloud_site_topology WHERE user_id=? AND reporting_site_id=? LIMIT 1');
    $q->execute([$userId,$reportingSiteId]);
    $prior=(string)($q->fetchColumn()?:'');
    $changed=$prior===''||!hash_equals($prior,$fingerprint);
    $authorityCount=count(array_filter($summary['sites'],static fn($site)=>($site['authority_device_id']??'')!==''));
    $mobileCount=count(array_filter($summary['devices'],static fn($device)=>($device['mobility']??'')==='mobile'));
    $stmt=$pdo->prepare("INSERT INTO tracky_cloud_site_topology
      (user_id,reporting_site_id,protocol_version,schema_version,topology_revision,site_count,device_count,authority_count,mobile_device_count,summary_json,fingerprint)
      VALUES (?,?,?,?,?,?,?,?,?,?,?)
      ON DUPLICATE KEY UPDATE
        protocol_version=VALUES(protocol_version),schema_version=VALUES(schema_version),
        topology_revision=GREATEST(topology_revision,VALUES(topology_revision)),
        site_count=VALUES(site_count),device_count=VALUES(device_count),authority_count=VALUES(authority_count),
        mobile_device_count=VALUES(mobile_device_count),summary_json=IF(VALUES(topology_revision)>=topology_revision,VALUES(summary_json),summary_json),
        updated_at=IF(NOT (fingerprint <=> VALUES(fingerprint)),CURRENT_TIMESTAMP,updated_at),
        fingerprint=IF(VALUES(topology_revision)>=topology_revision,VALUES(fingerprint),fingerprint)");
    $stmt->execute([$userId,$reportingSiteId,VP3_TRACKY_TOPOLOGY_PROTOCOL_V278,$summary['schema_version'],$summary['revision'],count($summary['sites']),count($summary['devices']),$authorityCount,$mobileCount,$json,$fingerprint]);
    return ['accepted'=>true,'changed'=>$changed,'fingerprint'=>$fingerprint,'summary'=>$summary];
}

function tracky_v278_report(PDO $pdo,int $userId,?string $reportingSiteId=null): array
{
    if($userId<1)return ['available'=>false,'protocol'=>VP3_TRACKY_TOPOLOGY_PROTOCOL_V278,'cloud_read_only'=>true];
    if($reportingSiteId!==null&&trim($reportingSiteId)!==''){
        $site=tracky_cloud_v270_site_id($reportingSiteId);
        $q=$pdo->prepare('SELECT * FROM tracky_cloud_site_topology WHERE user_id=? AND reporting_site_id=? LIMIT 1');$q->execute([$userId,$site]);
    }else{
        $q=$pdo->prepare('SELECT * FROM tracky_cloud_site_topology WHERE user_id=? ORDER BY updated_at DESC LIMIT 1');$q->execute([$userId]);
    }
    $row=$q->fetch();
    if(!$row)return ['available'=>false,'protocol'=>VP3_TRACKY_TOPOLOGY_PROTOCOL_V278,'authority'=>'homeserver_tracky_summary','cloud_read_only'=>true,'authority_assignment'=>'local_only'];
    $decoded=json_decode((string)$row['summary_json'],true);
    return [
      'available'=>true,'protocol'=>(string)$row['protocol_version'],'reporting_site_id'=>(string)$row['reporting_site_id'],
      'schema_version'=>(int)$row['schema_version'],'revision'=>(int)$row['topology_revision'],
      'site_count'=>(int)$row['site_count'],'device_count'=>(int)$row['device_count'],
      'authority_count'=>(int)$row['authority_count'],'mobile_device_count'=>(int)$row['mobile_device_count'],
      'topology'=>is_array($decoded)?$decoded:[],'updated_at'=>$row['updated_at']??null,
      'authority'=>'homeserver_tracky_summary','cloud_read_only'=>true,'authority_assignment'=>'local_only',
    ];
}

function tracky_v278_public_capability(): array
{
    return [
      'version'=>'2.78','protocol'=>VP3_TRACKY_TOPOLOGY_PROTOCOL_V278,
      'profiles'=>['node','desk','studio','team_node','pocket','custom'],
      'cloud_read_only'=>true,'authority_assignment'=>'local_only','topology_mutation_authority'=>false,
    ];
}
