<?php
declare(strict_types=1);

const VP3_TRACKY_GOVERNED_OPERATIONS_PROTOCOL_V280='physical_federation_governed_operations.v1';
const VP3_TRACKY_GOVERNED_OPERATIONS_V280='2.80';
const VP3_TRACKY_GOVERNED_OPERATION_TYPES_V280=['reconnect','reconcile','restart_runtime','request_update','revoke_site','revoke_device','transfer_authority'];
const VP3_TRACKY_GOVERNED_OPERATION_STATES_V280=['proposed','awaiting_approval','approved','queued','running','reconciling','completed','failed','rejected','cancelled','expired'];

function tracky_v280_fgo_text(mixed $v,int $n=240): string { return mb_strimwidth(trim((string)($v??'')),0,max(1,$n),''); }
function tracky_v280_fgo_uuid(mixed $v,string $label): string {
    $v=strtolower(tracky_v280_fgo_text($v,64));
    if(!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',$v))throw new RuntimeException('Tracky governed operation '.$label.' must be a UUID.');
    return $v;
}
function tracky_v280_fgo_operation(mixed $v): string {
    $v=strtolower(tracky_v280_fgo_text($v,40));
    if(!in_array($v,VP3_TRACKY_GOVERNED_OPERATION_TYPES_V280,true))throw new RuntimeException('Unsupported Tracky governed operation.');
    return $v;
}
function tracky_v280_fgo_state(mixed $v): string {
    $v=strtolower(tracky_v280_fgo_text($v,40));
    return in_array($v,VP3_TRACKY_GOVERNED_OPERATION_STATES_V280,true)?$v:'proposed';
}
function tracky_v280_fgo_ensure_schema(?PDO $pdo=null): void {
    $pdo??=db(); if(!$pdo)throw new RuntimeException('Database connection is unavailable.');
    $pdo->exec("CREATE TABLE IF NOT EXISTS tracky_cloud_federation_governed_operations (
      user_id INT UNSIGNED NOT NULL, reporting_site_id VARCHAR(100) NOT NULL, local_site_uuid CHAR(36) NOT NULL,
      generated_at_ms BIGINT UNSIGNED NOT NULL DEFAULT 0, semantic_hash CHAR(64) NOT NULL, snapshot_json LONGTEXT NOT NULL,
      received_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      PRIMARY KEY(user_id,reporting_site_id), INDEX idx_tracky_fgo_user_updated(user_id,updated_at),
      CONSTRAINT fk_tracky_fgo_user FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $pdo->exec("CREATE TABLE IF NOT EXISTS tracky_cloud_federation_operation_requests (
      user_id INT UNSIGNED NOT NULL, request_id VARCHAR(128) NOT NULL, idempotency_key VARCHAR(160) NOT NULL,
      operation_type VARCHAR(40) NOT NULL, origin_site_uuid CHAR(36) NOT NULL, target_site_uuid CHAR(36) NOT NULL, device_id VARCHAR(80) NOT NULL DEFAULT '',
      new_authority_device_id VARCHAR(80) NOT NULL DEFAULT '', explicit_confirmation TINYINT(1) NOT NULL DEFAULT 0,
      request_json TEXT NOT NULL, status VARCHAR(40) NOT NULL DEFAULT 'proposed', expires_at_ms BIGINT UNSIGNED NOT NULL DEFAULT 0,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      PRIMARY KEY(user_id,request_id), UNIQUE KEY uq_tracky_fgo_request_idem(user_id,idempotency_key),
      INDEX idx_tracky_fgo_request_target(user_id,target_site_uuid,status,created_at), INDEX idx_tracky_fgo_request_origin(user_id,origin_site_uuid,status,created_at),
      CONSTRAINT fk_tracky_fgo_request_user FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}
function tracky_v280_fgo_operation_row(array $row): array {
    return [
      'request_id'=>tracky_v280_fgo_text($row['request_id']??'',128),
      'idempotency_key'=>tracky_v280_fgo_text($row['idempotency_key']??'',160),
      'operation_type'=>tracky_v280_fgo_operation($row['operation_type']??''),
      'target_site_id'=>tracky_v280_fgo_uuid($row['target_site_id']??'','target site id'),
      'device_id'=>tracky_v280_fgo_text($row['device_id']??'',80),
      'new_authority_device_id'=>tracky_v280_fgo_text($row['new_authority_device_id']??'',80),
      'state'=>tracky_v280_fgo_state($row['state']??'proposed'),
      'requires_approval'=>!empty($row['requires_approval']),
      'requires_reconciliation'=>!empty($row['requires_reconciliation']),
      'expires_at_ms'=>max(0,(int)($row['expires_at_ms']??0)),
      'authority_epoch_before'=>max(0,(int)($row['authority_epoch_before']??0)),
      'authority_epoch_after'=>max(0,(int)($row['authority_epoch_after']??0)),
      'last_error'=>tracky_v280_fgo_text($row['last_error']??'',300),
    ];
}
function tracky_v280_fgo_normalize(array $input): array {
    if((string)($input['protocol']??'')!==VP3_TRACKY_GOVERNED_OPERATIONS_PROTOCOL_V280)throw new RuntimeException('Tracky governed operations protocol is unsupported.');
    if(isset($input['cloud_read_only'])&&!$input['cloud_read_only'])throw new RuntimeException('Cloud governed operations projection must remain read-only.');
    if(!empty($input['remote_command_execution'])||!empty($input['authority_mutation']))throw new RuntimeException('Cloud cannot execute federation operations or mutate authority.');
    $local=tracky_v280_fgo_uuid($input['local_site_id']??'','local site id');
    $ops=[];$seen=[];
    foreach(array_slice(is_array($input['operations']??null)?$input['operations']:[],0,250) as $row){
        if(!is_array($row))continue;
        $n=tracky_v280_fgo_operation_row($row);
        if($n['request_id']===''||isset($seen[$n['request_id']]))continue;
        $seen[$n['request_id']]=true;$ops[]=$n;
    }
    return [
      'protocol'=>VP3_TRACKY_GOVERNED_OPERATIONS_PROTOCOL_V280,'version'=>'2.80','schema_version'=>1,
      'generated_at'=>max(0,(int)($input['generated_at']??round(microtime(true)*1000))),'local_site_id'=>$local,
      'operations'=>$ops,
      'counts'=>[
        'total'=>count($ops),'active'=>count(array_filter($ops,static fn($x)=>!in_array($x['state'],['completed','failed','rejected','cancelled','expired'],true))),
        'awaiting_approval'=>count(array_filter($ops,static fn($x)=>$x['state']==='awaiting_approval')),
        'reconciling'=>count(array_filter($ops,static fn($x)=>$x['state']==='reconciling'))
      ],
      'summary_only'=>true,'cloud_read_only'=>true,'remote_command_execution'=>false,'authority_mutation'=>false,
      'safety'=>['section7_health_is_authoritative'=>true,'cloud_execution_allowed'=>false,'agent_execution_allowed'=>false,'authority_transfer_automatic'=>false,'reconnect_marks_recovered'=>false,'revocation_wins'=>true,'operation_expiration_mirrored'=>true]
    ];
}
function tracky_v280_fgo_ingest(PDO $pdo,int $userId,string $reportingSiteId,array $input): array {
    tracky_v280_fgo_ensure_schema($pdo); $snapshot=tracky_v280_fgo_normalize($input);
    $json=tracky_cloud_v270_json($snapshot);$hash=hash('sha256',$json);
    $q=$pdo->prepare('SELECT generated_at_ms,semantic_hash FROM tracky_cloud_federation_governed_operations WHERE user_id=? AND reporting_site_id=? LIMIT 1');
    $q->execute([$userId,$reportingSiteId]);$prior=$q->fetch();
    if($prior&&(int)$snapshot['generated_at']<(int)$prior['generated_at_ms'])return ['accepted'=>true,'changed'=>0,'stale'=>1,'idempotent'=>0];
    if($prior&&(int)$snapshot['generated_at']===(int)$prior['generated_at_ms']){
        if(hash_equals((string)$prior['semantic_hash'],$hash))return ['accepted'=>true,'changed'=>0,'stale'=>0,'idempotent'=>1];
        throw new RuntimeException('Tracky governed operations timestamp conflicts with the Cloud mirror.');
    }
    $s=$pdo->prepare("INSERT INTO tracky_cloud_federation_governed_operations(user_id,reporting_site_id,local_site_uuid,generated_at_ms,semantic_hash,snapshot_json)
      VALUES(?,?,?,?,?,?) ON DUPLICATE KEY UPDATE local_site_uuid=VALUES(local_site_uuid),generated_at_ms=VALUES(generated_at_ms),semantic_hash=VALUES(semantic_hash),snapshot_json=VALUES(snapshot_json),received_at=CURRENT_TIMESTAMP,updated_at=CURRENT_TIMESTAMP");
    $s->execute([$userId,$reportingSiteId,$snapshot['local_site_id'],$snapshot['generated_at'],$hash,$json]);
    foreach($snapshot['operations'] as $op){
        $u=$pdo->prepare("UPDATE tracky_cloud_federation_operation_requests SET status=? WHERE user_id=? AND request_id=?");
        $u->execute([$op['state'],$userId,$op['request_id']]);
    }
    return ['accepted'=>true,'changed'=>1,'stale'=>0,'idempotent'=>0];
}
function tracky_v280_fgo_create_request(PDO $pdo,int $userId,array $input): array {
    tracky_v280_fgo_ensure_schema($pdo);
    $op=tracky_v280_fgo_operation($input['operation_type']??'');
    $origin=tracky_v280_fgo_uuid($input['origin_site_id']??'','origin site id');
    $target=tracky_v280_fgo_uuid($input['target_site_id']??'','target site id');
    $device=tracky_v280_fgo_text($input['device_id']??'',80);
    $newAuthority=tracky_v280_fgo_text($input['new_authority_device_id']??'',80);
    if($op==='revoke_device'&&$device==='')throw new RuntimeException('Device id is required for this operation.');
    if($op==='transfer_authority'&&($newAuthority===''||empty($input['explicit_confirmation'])))throw new RuntimeException('Authority transfer requires an explicit confirmation and new authority device.');
    $params=[];
    if($op==='request_update'){
        $sha=strtolower(tracky_v280_fgo_text($input['package_sha256']??'',64));
        $version=tracky_v280_fgo_text($input['release_version']??'',40);
        if($sha===''||$version==='')throw new RuntimeException('Update requests require a staged package checksum and release version.');
        if($sha!==''&&!preg_match('/^[0-9a-f]{64}$/',$sha))throw new RuntimeException('Update package checksum is invalid.');
        if($sha!=='')$params['package_sha256']=$sha;if($version!=='')$params['release_version']=$version;
        if(isset($input['rollout_id'])&&$input['rollout_id']!=='')$params['rollout_id']=max(0,(int)$input['rollout_id']);
    }
    $requestId='cloud-fop-'.bin2hex(random_bytes(16));
    $expiresAt=(int)round(microtime(true)*1000)+900000;
    $idem=tracky_v280_fgo_text($input['idempotency_key']??'',160)?:$requestId;
    $payload=['request_id'=>$requestId,'idempotency_key'=>$idem,'operation_type'=>$op,'origin_site_id'=>$origin,'target_site_id'=>$target,'device_id'=>$device,
      'new_authority_device_id'=>$newAuthority,'explicit_confirmation'=>!empty($input['explicit_confirmation']),'expires_at_ms'=>$expiresAt,'reason'=>tracky_v280_fgo_text($input['reason']??'',240),'parameters'=>$params];
    $json=tracky_cloud_v270_json($payload);
    try{
      $s=$pdo->prepare("INSERT INTO tracky_cloud_federation_operation_requests(user_id,request_id,idempotency_key,operation_type,origin_site_uuid,target_site_uuid,device_id,new_authority_device_id,explicit_confirmation,request_json,status,expires_at_ms) VALUES(?,?,?,?,?,?,?,?,?,?,'proposed',?)");
      $s->execute([$userId,$requestId,$idem,$op,$origin,$target,$device,$newAuthority,!empty($input['explicit_confirmation'])?1:0,$json,$expiresAt]);
    }catch(PDOException $e){
      $q=$pdo->prepare('SELECT request_json,status FROM tracky_cloud_federation_operation_requests WHERE user_id=? AND idempotency_key=? LIMIT 1');$q->execute([$userId,$idem]);$row=$q->fetch();
      if(!$row)throw $e;$existing=json_decode((string)$row['request_json'],true);if(!is_array($existing)||($existing['operation_type']??'')!==$op||($existing['origin_site_id']??'')!==$origin||($existing['target_site_id']??'')!==$target||($existing['device_id']??'')!==$device)throw new RuntimeException('Governed operation idempotency conflict.');
      return ['created'=>false,'request'=>$existing,'status'=>(string)$row['status']];
    }
    return ['created'=>true,'request'=>$payload,'status'=>'proposed'];
}
function tracky_v280_fgo_pending_for_site(PDO $pdo,int $userId,string $siteId): array {
    tracky_v280_fgo_ensure_schema($pdo);$siteId=tracky_v280_fgo_uuid($siteId,'target site id');
    $now=(int)round(microtime(true)*1000);
    $expire=$pdo->prepare("UPDATE tracky_cloud_federation_operation_requests SET status='expired' WHERE user_id=? AND origin_site_uuid=? AND status IN ('proposed','awaiting_approval') AND expires_at_ms>0 AND expires_at_ms<=?");
    $expire->execute([$userId,$siteId,$now]);
    $q=$pdo->prepare("SELECT request_json,status FROM tracky_cloud_federation_operation_requests WHERE user_id=? AND origin_site_uuid=? AND status IN ('proposed','awaiting_approval') ORDER BY created_at,idempotency_key LIMIT 50");
    $q->execute([$userId,$siteId]);$out=[];
    foreach($q->fetchAll()?:[] as $r){$x=json_decode((string)$r['request_json'],true);if(is_array($x)){$x['status']=(string)$r['status'];$out[]=$x;}}
    return ['protocol'=>VP3_TRACKY_GOVERNED_OPERATIONS_PROTOCOL_V280,'cloud_role'=>'request_relay_only','remote_command_execution'=>false,'authority_mutation'=>false,'requests'=>$out];
}
function tracky_v280_fgo_report(PDO $pdo,int $userId,string $originSiteId=''): array {
    tracky_v280_fgo_ensure_schema($pdo);
    $origin=trim($originSiteId)!==''?tracky_v280_fgo_uuid($originSiteId,'origin site id'):'';
    if($origin!==''){
        $q=$pdo->prepare('SELECT reporting_site_id,snapshot_json,updated_at FROM tracky_cloud_federation_governed_operations WHERE user_id=? AND local_site_uuid=? ORDER BY updated_at DESC');
        $q->execute([$userId,$origin]);
    }else{
        $q=$pdo->prepare('SELECT reporting_site_id,snapshot_json,updated_at FROM tracky_cloud_federation_governed_operations WHERE user_id=? ORDER BY updated_at DESC');
        $q->execute([$userId]);
    }
    $snapshots=[];
    foreach($q->fetchAll()?:[] as $r){$x=json_decode((string)$r['snapshot_json'],true);if(is_array($x))$snapshots[]=['reporting_site_id'=>(string)$r['reporting_site_id'],'updated_at'=>(string)$r['updated_at'],'operations'=>$x];}
    if($origin!==''){
        $q=$pdo->prepare('SELECT request_json,status,created_at,updated_at FROM tracky_cloud_federation_operation_requests WHERE user_id=? AND origin_site_uuid=? ORDER BY created_at DESC LIMIT 100');
        $q->execute([$userId,$origin]);
    }else{
        $q=$pdo->prepare('SELECT request_json,status,created_at,updated_at FROM tracky_cloud_federation_operation_requests WHERE user_id=? ORDER BY created_at DESC LIMIT 100');
        $q->execute([$userId]);
    }
    $requests=[];
    foreach($q->fetchAll()?:[] as $r){$x=json_decode((string)$r['request_json'],true);if(is_array($x)){$x['status']=(string)$r['status'];$x['created_at']=(string)$r['created_at'];$x['updated_at']=(string)$r['updated_at'];$requests[]=$x;}}
    return ['available'=>!empty($snapshots),'protocol'=>VP3_TRACKY_GOVERNED_OPERATIONS_PROTOCOL_V280,'origin_site_id'=>$origin,'snapshots'=>$snapshots,'preferred_operations'=>$snapshots[0]['operations']??null,'cloud_requests'=>$requests,'cloud_role'=>'request_and_mirror_only','cloud_execution_allowed'=>false,'authority_mutation'=>false];
}
function tracky_v280_fgo_public_capability(): array {
    return ['version'=>'2.80','protocol'=>VP3_TRACKY_GOVERNED_OPERATIONS_PROTOCOL_V280,'operations'=>VP3_TRACKY_GOVERNED_OPERATION_TYPES_V280,'states'=>VP3_TRACKY_GOVERNED_OPERATION_STATES_V280,'cloud_request_creation'=>true,'explicit_origin_routing'=>true,'operation_expiration_mirrored'=>true,'revocation_wins'=>true,'cloud_execution_allowed'=>false,'agent_execution_allowed'=>false,'section7_health_is_authoritative'=>true,'completion_requires_authoritative_reconciliation'=>true,'authority_transfer_requires_epoch_advance'=>true];
}
