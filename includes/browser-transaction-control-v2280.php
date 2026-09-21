<?php
declare(strict_types=1);

/**
 * VP3 Browser Companion v22.80 — Transaction Trust, Control & Production Hardening.
 *
 * Final transaction-family phase. Adds owner-authoritative controls, bounded
 * retention, cross-device scan leases, operational receipts, audit visibility
 * and explicit Stop / Resume / Forget semantics above v22.40-v22.70.
 */
const VP3_BROWSER_CONTROL_V2280='browser-transaction-control-v2280-20260921';
const VP3_BROWSER_CONTROL_RETENTION_DEFAULT_V2280=90;
const VP3_BROWSER_CONTROL_RETENTION_MIN_V2280=7;
const VP3_BROWSER_CONTROL_RETENTION_MAX_V2280=365;
const VP3_BROWSER_CONTROL_SCAN_MIN_DEFAULT_V2280=120;
const VP3_BROWSER_CONTROL_SCAN_MIN_MIN_V2280=30;
const VP3_BROWSER_CONTROL_SCAN_MIN_MAX_V2280=3600;
const VP3_BROWSER_CONTROL_NOTIFY_DEFAULT_V2280=60;
const VP3_BROWSER_CONTROL_NOTIFY_MIN_V2280=5;
const VP3_BROWSER_CONTROL_NOTIFY_MAX_V2280=1440;
const VP3_BROWSER_CONTROL_LEASE_SECONDS_V2280=45;
const VP3_BROWSER_CONTROL_MAX_AUDIT_V2280=120;

require_once __DIR__.'/browser-transaction-intelligence-v2270.php';

function vp3_browser_control_schema_ready_v2280(?PDO $pdo=null): bool
{
    $pdo??=db();
    return (bool)$pdo
        && table_exists('browser_transaction_control_settings_v2280')
        && table_exists('browser_transaction_scan_leases_v2280')
        && table_exists('browser_transaction_control_receipts_v2280')
        && table_exists('browser_transaction_notification_state_v2280')
        && vp3_browser_intelligence_schema_ready_v2270($pdo);
}

function vp3_browser_control_ensure_schema_v2280(?PDO $pdo=null): void
{
    $pdo??=db();if(!$pdo)throw new RuntimeException('Database connection is unavailable.');
    vp3_browser_intelligence_ensure_schema_v2270($pdo);
    $pdo->exec("CREATE TABLE IF NOT EXISTS browser_transaction_control_settings_v2280 (
      owner_user_id INT UNSIGNED NOT NULL,
      monitoring_enabled TINYINT(1) NOT NULL DEFAULT 1,
      retention_days SMALLINT UNSIGNED NOT NULL DEFAULT 90,
      notification_cooldown_minutes SMALLINT UNSIGNED NOT NULL DEFAULT 60,
      scan_min_interval_seconds SMALLINT UNSIGNED NOT NULL DEFAULT 120,
      revision INT UNSIGNED NOT NULL DEFAULT 1,
      stopped_at DATETIME NULL,
      updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      PRIMARY KEY (owner_user_id),
      CONSTRAINT fk_browser_control_owner_v2280 FOREIGN KEY (owner_user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS browser_transaction_scan_leases_v2280 (
      owner_user_id INT UNSIGNED NOT NULL,
      scope_key CHAR(64) NOT NULL,
      device_hash CHAR(64) NOT NULL,
      domain VARCHAR(190) NOT NULL DEFAULT '',
      lease_until DATETIME NULL,
      last_scan_at DATETIME NULL,
      last_result_code VARCHAR(48) NOT NULL DEFAULT '',
      consecutive_failures SMALLINT UNSIGNED NOT NULL DEFAULT 0,
      updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      PRIMARY KEY (owner_user_id,scope_key),
      INDEX idx_browser_scan_lease_owner_v2280 (owner_user_id,lease_until,last_scan_at),
      CONSTRAINT fk_browser_scan_lease_owner_v2280 FOREIGN KEY (owner_user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS browser_transaction_control_receipts_v2280 (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
      public_id CHAR(36) NOT NULL,
      owner_user_id INT UNSIGNED NOT NULL,
      continuity_id BIGINT UNSIGNED NULL,
      action_key VARCHAR(48) NOT NULL,
      result_code VARCHAR(48) NOT NULL DEFAULT 'ok',
      detail_code VARCHAR(80) NOT NULL DEFAULT '',
      actor_device_hash CHAR(64) NOT NULL DEFAULT '',
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      PRIMARY KEY (id),
      UNIQUE KEY uq_browser_control_receipt_public_v2280 (public_id),
      INDEX idx_browser_control_receipt_owner_v2280 (owner_user_id,created_at,id),
      INDEX idx_browser_control_receipt_continuity_v2280 (continuity_id,id),
      CONSTRAINT fk_browser_control_receipt_owner_v2280 FOREIGN KEY (owner_user_id) REFERENCES users(id) ON DELETE CASCADE,
      CONSTRAINT fk_browser_control_receipt_continuity_v2280 FOREIGN KEY (continuity_id) REFERENCES browser_transaction_continuities_v2260(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS browser_transaction_notification_state_v2280 (
      owner_user_id INT UNSIGNED NOT NULL,
      continuity_id BIGINT UNSIGNED NOT NULL,
      exception_type VARCHAR(48) NOT NULL,
      last_priority_score TINYINT UNSIGNED NOT NULL DEFAULT 0,
      last_notified_at DATETIME NULL,
      updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      PRIMARY KEY (owner_user_id,continuity_id,exception_type),
      CONSTRAINT fk_browser_notify_owner_v2280 FOREIGN KEY (owner_user_id) REFERENCES users(id) ON DELETE CASCADE,
      CONSTRAINT fk_browser_notify_continuity_v2280 FOREIGN KEY (continuity_id) REFERENCES browser_transaction_continuities_v2260(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

function vp3_browser_control_uuid_v2280(): string
{
    return function_exists('vp3_extension_uuid_v2000')?vp3_extension_uuid_v2000():vp3_browser_intelligence_uuid_v2270();
}
function vp3_browser_control_device_hash_v2280(string $deviceId): string
{
    return $deviceId!==''?hash('sha256','vp3-browser-device|'.$deviceId):'';
}
function vp3_browser_control_settings_v2280(PDO $pdo,int $uid,bool $lock=false): array
{
    $pdo->prepare("INSERT IGNORE INTO browser_transaction_control_settings_v2280 (owner_user_id) VALUES (?)")->execute([$uid]);
    $stmt=$pdo->prepare("SELECT * FROM browser_transaction_control_settings_v2280 WHERE owner_user_id=? LIMIT 1".($lock?' FOR UPDATE':''));
    $stmt->execute([$uid]);$row=$stmt->fetch(PDO::FETCH_ASSOC);
    if(!is_array($row))throw new RuntimeException('Transaction control settings are unavailable.');
    return $row;
}
function vp3_browser_control_settings_public_v2280(array $row): array
{
    return [
      'monitoring_enabled'=>!empty($row['monitoring_enabled']),
      'retention_days'=>(int)$row['retention_days'],
      'notification_cooldown_minutes'=>(int)$row['notification_cooldown_minutes'],
      'scan_min_interval_seconds'=>(int)$row['scan_min_interval_seconds'],
      'revision'=>(int)$row['revision'],
      'stopped_at'=>(string)($row['stopped_at']??''),
      'updated_at'=>(string)$row['updated_at'],
    ];
}
function vp3_browser_control_receipt_v2280(PDO $pdo,int $uid,?int $continuityId,string $action,string $result='ok',string $detail='',string $deviceHash=''): void
{
    $stmt=$pdo->prepare("INSERT INTO browser_transaction_control_receipts_v2280
      (public_id,owner_user_id,continuity_id,action_key,result_code,detail_code,actor_device_hash)
      VALUES (?,?,?,?,?,?,?)");
    $stmt->execute([vp3_browser_control_uuid_v2280(),$uid,$continuityId,$action,$result,mb_strimwidth($detail,0,80,''),$deviceHash]);
}
function vp3_browser_control_update_settings_v2280(PDO $pdo,array $user,array $input,string $deviceId=''): array
{
    $uid=(int)($user['id']??0);if($uid<1)throw new RuntimeException('Transaction controls require an owner.');
    $pdo->beginTransaction();
    try{
      $row=vp3_browser_control_settings_v2280($pdo,$uid,true);
      $ret=max(VP3_BROWSER_CONTROL_RETENTION_MIN_V2280,min(VP3_BROWSER_CONTROL_RETENTION_MAX_V2280,(int)($input['retention_days']??$row['retention_days'])));
      $cool=max(VP3_BROWSER_CONTROL_NOTIFY_MIN_V2280,min(VP3_BROWSER_CONTROL_NOTIFY_MAX_V2280,(int)($input['notification_cooldown_minutes']??$row['notification_cooldown_minutes'])));
      $scan=max(VP3_BROWSER_CONTROL_SCAN_MIN_MIN_V2280,min(VP3_BROWSER_CONTROL_SCAN_MIN_MAX_V2280,(int)($input['scan_min_interval_seconds']??$row['scan_min_interval_seconds'])));
      $enabled=array_key_exists('monitoring_enabled',$input)?(!empty($input['monitoring_enabled'])?1:0):(int)$row['monitoring_enabled'];
      $pdo->prepare("UPDATE browser_transaction_control_settings_v2280 SET monitoring_enabled=?,retention_days=?,notification_cooldown_minutes=?,scan_min_interval_seconds=?,revision=revision+1,stopped_at=CASE WHEN ?=0 THEN COALESCE(stopped_at,UTC_TIMESTAMP()) ELSE NULL END,updated_at=UTC_TIMESTAMP() WHERE owner_user_id=?")
        ->execute([$enabled,$ret,$cool,$scan,$enabled,$uid]);
      vp3_browser_control_receipt_v2280($pdo,$uid,null,'settings_updated','ok','revision_updated',vp3_browser_control_device_hash_v2280($deviceId));
      $pdo->commit();
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
    return vp3_browser_control_settings_public_v2280(vp3_browser_control_settings_v2280($pdo,$uid));
}
function vp3_browser_control_stop_all_v2280(PDO $pdo,array $user,string $deviceId=''): array
{
    $uid=(int)($user['id']??0);$pdo->beginTransaction();
    try{
      vp3_browser_control_settings_v2280($pdo,$uid,true);
      $pdo->prepare("UPDATE browser_transaction_control_settings_v2280 SET monitoring_enabled=0,revision=revision+1,stopped_at=UTC_TIMESTAMP(),updated_at=UTC_TIMESTAMP() WHERE owner_user_id=?")->execute([$uid]);
      $stmt=$pdo->prepare("SELECT public_id FROM browser_transaction_continuities_v2260 WHERE owner_user_id=? AND tracking_status='active' ORDER BY id");
      $stmt->execute([$uid]);$ids=array_map('strval',$stmt->fetchAll(PDO::FETCH_COLUMN)?:[]);
      $pdo->prepare("UPDATE browser_transaction_continuities_v2260 SET tracking_status='closed',closure_reason='user_closed',closed_at=UTC_TIMESTAMP(),updated_at=UTC_TIMESTAMP() WHERE owner_user_id=? AND tracking_status='active'")->execute([$uid]);
      $pdo->prepare("UPDATE browser_transaction_intelligence_cases_v2270 SET status='resolved',resolved_at=UTC_TIMESTAMP(),updated_at=UTC_TIMESTAMP() WHERE owner_user_id=? AND status IN ('open','acknowledged')")->execute([$uid]);
      $pdo->prepare("UPDATE browser_transaction_recovery_proposals_v2270 SET status='dismissed',resolved_at=UTC_TIMESTAMP() WHERE owner_user_id=? AND status='proposed'")->execute([$uid]);
      vp3_browser_control_receipt_v2280($pdo,$uid,null,'stop_all','ok','closed_'.count($ids),vp3_browser_control_device_hash_v2280($deviceId));
      $pdo->commit();
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
    return ['stopped_count'=>count($ids),'settings'=>vp3_browser_control_settings_public_v2280(vp3_browser_control_settings_v2280($pdo,$uid))];
}
function vp3_browser_control_resume_all_v2280(PDO $pdo,array $user,string $deviceId=''): array
{
    $uid=(int)($user['id']??0);$pdo->beginTransaction();
    try{
      vp3_browser_control_settings_v2280($pdo,$uid,true);
      $stmt=$pdo->prepare("SELECT id FROM browser_transaction_continuities_v2260 WHERE owner_user_id=? AND tracking_status='closed' AND closure_reason='user_closed' AND match_mode='reference' AND reference_hash<>''");
      $stmt->execute([$uid]);$ids=array_map('intval',$stmt->fetchAll(PDO::FETCH_COLUMN)?:[]);
      if($ids){$marks=implode(',',array_fill(0,count($ids),'?'));$pdo->prepare("UPDATE browser_transaction_continuities_v2260 SET tracking_status='active',closure_reason='',closed_at=NULL,updated_at=UTC_TIMESTAMP() WHERE owner_user_id=? AND id IN ($marks)")->execute(array_merge([$uid],$ids));}
      $pdo->prepare("UPDATE browser_transaction_control_settings_v2280 SET monitoring_enabled=1,revision=revision+1,stopped_at=NULL,updated_at=UTC_TIMESTAMP() WHERE owner_user_id=?")->execute([$uid]);
      vp3_browser_control_receipt_v2280($pdo,$uid,null,'resume_all','ok','reopened_'.count($ids),vp3_browser_control_device_hash_v2280($deviceId));
      $pdo->commit();
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
    return ['resumed_count'=>count($ids),'settings'=>vp3_browser_control_settings_public_v2280(vp3_browser_control_settings_v2280($pdo,$uid))];
}
function vp3_browser_control_forget_v2280(PDO $pdo,array $user,string $continuityPublicId,string $deviceId=''): array
{
    $uid=(int)($user['id']??0);$row=vp3_browser_continuity_row_v2260($pdo,$uid,$continuityPublicId,true);
    if(!$row)throw new RuntimeException('Transaction tracker was not found.');
    if((string)$row['tracking_status']!=='closed')throw new RuntimeException('Stop tracking this transaction before forgetting its continuity record.');
    $cid=(int)$row['id'];
    vp3_browser_control_receipt_v2280($pdo,$uid,$cid,'forget_tracker','ok','continuity_deleted',vp3_browser_control_device_hash_v2280($deviceId));
    $pdo->prepare("DELETE FROM browser_transaction_continuities_v2260 WHERE id=? AND owner_user_id=?")->execute([$cid,$uid]);
    return ['forgotten'=>true,'continuity_id'=>$continuityPublicId,'submission_history_retained'=>true];
}
function vp3_browser_control_prune_v2280(PDO $pdo,array $user,string $deviceId=''): array
{
    $uid=(int)($user['id']??0);$settings=vp3_browser_control_settings_v2280($pdo,$uid);$days=(int)$settings['retention_days'];
    $stmt=$pdo->prepare("SELECT id FROM browser_transaction_continuities_v2260 WHERE owner_user_id=? AND tracking_status='closed' AND closed_at IS NOT NULL AND closed_at<DATE_SUB(UTC_TIMESTAMP(),INTERVAL ".$days." DAY)");
    $stmt->execute([$uid]);$ids=array_map('intval',$stmt->fetchAll(PDO::FETCH_COLUMN)?:[]);
    if($ids){$marks=implode(',',array_fill(0,count($ids),'?'));$pdo->prepare("DELETE FROM browser_transaction_continuities_v2260 WHERE owner_user_id=? AND id IN ($marks)")->execute(array_merge([$uid],$ids));}
    $pdo->prepare("DELETE FROM browser_transaction_scan_leases_v2280 WHERE owner_user_id=? AND updated_at<DATE_SUB(UTC_TIMESTAMP(),INTERVAL 7 DAY)")->execute([$uid]);
    vp3_browser_control_receipt_v2280($pdo,$uid,null,'retention_prune','ok','deleted_'.count($ids),vp3_browser_control_device_hash_v2280($deviceId));
    return ['pruned_count'=>count($ids),'retention_days'=>$days,'submission_history_retained'=>true];
}
function vp3_browser_control_scan_permit_v2280(PDO $pdo,array $user,string $deviceId,string $domain): array
{
    $uid=(int)($user['id']??0);$domain=strtolower(trim($domain));
    if(!preg_match('/^(?=.{1,190}$)[a-z0-9.-]+$/',$domain))throw new InvalidArgumentException('Transaction scan domain is invalid.');
    $settings=vp3_browser_control_settings_v2280($pdo,$uid);
    if(empty($settings['monitoring_enabled']))return ['allowed'=>false,'reason'=>'monitoring_stopped','retry_after_seconds'=>0];
    $exists=$pdo->prepare("SELECT 1 FROM browser_transaction_continuities_v2260 WHERE owner_user_id=? AND domain=? AND tracking_status='active' LIMIT 1");
    $exists->execute([$uid,$domain]);if(!$exists->fetchColumn())return ['allowed'=>false,'reason'=>'no_active_trackers','retry_after_seconds'=>0];
    $scope=hash('sha256','domain|'.$domain);$deviceHash=vp3_browser_control_device_hash_v2280($deviceId);$min=(int)$settings['scan_min_interval_seconds'];
    $pdo->beginTransaction();
    try{
      $stmt=$pdo->prepare("SELECT * FROM browser_transaction_scan_leases_v2280 WHERE owner_user_id=? AND scope_key=? LIMIT 1 FOR UPDATE");
      $stmt->execute([$uid,$scope]);$row=$stmt->fetch(PDO::FETCH_ASSOC);
      $now=time();
      if(is_array($row)){
        $leaseUntil=!empty($row['lease_until'])?strtotime((string)$row['lease_until']):0;
        if($leaseUntil>$now&&!hash_equals((string)$row['device_hash'],$deviceHash)){
          $pdo->commit();return ['allowed'=>false,'reason'=>'leased_to_another_device','retry_after_seconds'=>max(1,$leaseUntil-$now)];
        }
        $last=!empty($row['last_scan_at'])?strtotime((string)$row['last_scan_at']):0;
        if($last>0&&($now-$last)<$min){
          $pdo->commit();return ['allowed'=>false,'reason'=>'scan_cooldown','retry_after_seconds'=>max(1,$min-($now-$last))];
        }
        $pdo->prepare("UPDATE browser_transaction_scan_leases_v2280 SET device_hash=?,domain=?,lease_until=DATE_ADD(UTC_TIMESTAMP(),INTERVAL ".VP3_BROWSER_CONTROL_LEASE_SECONDS_V2280." SECOND),updated_at=UTC_TIMESTAMP() WHERE owner_user_id=? AND scope_key=?")
          ->execute([$deviceHash,$domain,$uid,$scope]);
      }else{
        $pdo->prepare("INSERT INTO browser_transaction_scan_leases_v2280 (owner_user_id,scope_key,device_hash,domain,lease_until) VALUES (?,?,?,?,DATE_ADD(UTC_TIMESTAMP(),INTERVAL ".VP3_BROWSER_CONTROL_LEASE_SECONDS_V2280." SECOND))")
          ->execute([$uid,$scope,$deviceHash,$domain]);
      }
      $pdo->commit();
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
    return ['allowed'=>true,'reason'=>'permit_granted','scope_key'=>$scope,'lease_seconds'=>VP3_BROWSER_CONTROL_LEASE_SECONDS_V2280];
}
function vp3_browser_control_scan_result_v2280(PDO $pdo,array $user,string $deviceId,string $scopeKey,string $resultCode): array
{
    $uid=(int)($user['id']??0);$scopeKey=strtolower(trim($scopeKey));$resultCode=preg_replace('/[^a-z0-9_:-]/','',strtolower($resultCode))??'';
    if(!preg_match('/^[a-f0-9]{64}$/',$scopeKey))throw new InvalidArgumentException('Transaction scan scope is invalid.');
    if($resultCode==='')$resultCode='unknown';
    $deviceHash=vp3_browser_control_device_hash_v2280($deviceId);
    $stmt=$pdo->prepare("SELECT * FROM browser_transaction_scan_leases_v2280 WHERE owner_user_id=? AND scope_key=? LIMIT 1 FOR UPDATE");
    $stmt->execute([$uid,$scopeKey]);$row=$stmt->fetch(PDO::FETCH_ASSOC);
    if(!is_array($row)||!hash_equals((string)$row['device_hash'],$deviceHash))throw new RuntimeException('Transaction scan lease is no longer owned by this browser.');
    $failure=in_array($resultCode,['api_error','capture_error','auth_error','timeout'],true);
    $pdo->prepare("UPDATE browser_transaction_scan_leases_v2280 SET lease_until=NULL,last_scan_at=UTC_TIMESTAMP(),last_result_code=?,consecutive_failures=CASE WHEN ?=1 THEN LEAST(consecutive_failures+1,65535) ELSE 0 END,updated_at=UTC_TIMESTAMP() WHERE owner_user_id=? AND scope_key=?")
      ->execute([$resultCode,$failure?1:0,$uid,$scopeKey]);
    if($failure)vp3_browser_control_receipt_v2280($pdo,$uid,null,'scan_failure','warning',$resultCode,$deviceHash);
    return ['recorded'=>true,'result_code'=>$resultCode];
}
function vp3_browser_control_audit_v2280(PDO $pdo,array $user,int $limit=80): array
{
    $uid=(int)($user['id']??0);$limit=max(10,min(VP3_BROWSER_CONTROL_MAX_AUDIT_V2280,$limit));
    $timeline=[];
    $q=$pdo->prepare("SELECT public_id,domain,lifecycle_family,lifecycle_state,tracking_status,closure_reason,created_at,updated_at FROM browser_transaction_continuities_v2260 WHERE owner_user_id=? ORDER BY updated_at DESC,id DESC LIMIT ".$limit);
    $q->execute([$uid]);
    foreach($q->fetchAll(PDO::FETCH_ASSOC)?:[] as $r)$timeline[]=['kind'=>'continuity','at'=>(string)$r['updated_at'],'id'=>(string)$r['public_id'],'domain'=>(string)$r['domain'],'state'=>(string)$r['lifecycle_state'],'status'=>(string)$r['tracking_status'],'detail'=>(string)$r['closure_reason']];
    $q=$pdo->prepare("SELECT public_id,exception_type,priority_band,status,opened_at,updated_at FROM browser_transaction_intelligence_cases_v2270 WHERE owner_user_id=? ORDER BY updated_at DESC,id DESC LIMIT ".$limit);
    $q->execute([$uid]);
    foreach($q->fetchAll(PDO::FETCH_ASSOC)?:[] as $r)$timeline[]=['kind'=>'exception','at'=>(string)$r['updated_at'],'id'=>(string)$r['public_id'],'state'=>(string)$r['exception_type'],'status'=>(string)$r['status'],'detail'=>(string)$r['priority_band']];
    $q=$pdo->prepare("SELECT public_id,action_key,result_code,detail_code,created_at FROM browser_transaction_control_receipts_v2280 WHERE owner_user_id=? ORDER BY id DESC LIMIT ".$limit);
    $q->execute([$uid]);
    foreach($q->fetchAll(PDO::FETCH_ASSOC)?:[] as $r)$timeline[]=['kind'=>'control','at'=>(string)$r['created_at'],'id'=>(string)$r['public_id'],'state'=>(string)$r['action_key'],'status'=>(string)$r['result_code'],'detail'=>(string)$r['detail_code']];
    usort($timeline,static fn(array $a,array $b)=>strcmp((string)$b['at'],(string)$a['at']));
    return array_slice($timeline,0,$limit);
}
function vp3_browser_control_status_v2280(PDO $pdo,array $user): array
{
    $uid=(int)($user['id']??0);$settings=vp3_browser_control_settings_v2280($pdo,$uid);
    $counts=$pdo->prepare("SELECT COUNT(*) total,SUM(tracking_status='active') active,SUM(tracking_status='closed') closed FROM browser_transaction_continuities_v2260 WHERE owner_user_id=?");
    $counts->execute([$uid]);$c=$counts->fetch(PDO::FETCH_ASSOC)?:[];
    $health=$pdo->prepare("SELECT SUM(consecutive_failures>0) failing_scopes,MAX(consecutive_failures) max_failures,MAX(last_scan_at) last_scan_at FROM browser_transaction_scan_leases_v2280 WHERE owner_user_id=?");
    $health->execute([$uid]);$h=$health->fetch(PDO::FETCH_ASSOC)?:[];
    return [
      'settings'=>vp3_browser_control_settings_public_v2280($settings),
      'counts'=>['total'=>(int)($c['total']??0),'active'=>(int)($c['active']??0),'closed'=>(int)($c['closed']??0)],
      'health'=>['failing_scopes'=>(int)($h['failing_scopes']??0),'max_failures'=>(int)($h['max_failures']??0),'last_scan_at'=>(string)($h['last_scan_at']??'')],
      'privacy'=>[
        'raw_page_text_persisted'=>false,'raw_url_persisted'=>false,'raw_reference_values_persisted'=>false,
        'device_identity_persisted_as_hash'=>true,'submission_history_survives_forget'=>true
      ],
      'audit'=>vp3_browser_control_audit_v2280($pdo,$user,40),
    ];
}
