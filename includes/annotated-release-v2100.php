<?php
declare(strict_types=1);

const VP3_ANNOTATED_RELEASE_V2100='annotated-release-foundation-v2100-20260918';
const VP3_ANNOTATED_EXTENSION_CURRENT_V2100='21.00.0';
const VP3_ANNOTATED_EXTENSION_MIN_V2100='20.90.0';
const VP3_ANNOTATED_EVENT_RETENTION_DAYS_V2100=365;
const VP3_ANNOTATED_RECENT_SEARCH_RETENTION_DAYS_V2100=180;
const VP3_ANNOTATED_EPHEMERAL_RETENTION_DAYS_V2100=7;

final class VP3AnnotatedRateLimitExceptionV2100 extends RuntimeException
{
    public function __construct(
        public readonly int $retryAfter,
        public readonly string $scope,
        string $message='Too many requests. Try again shortly.'
    ){parent::__construct($message);}
}

function vp3_annotated_request_id_v2100(): string
{
    static $id='';
    if($id!=='')return $id;
    $incoming=trim((string)($_SERVER['HTTP_X_REQUEST_ID']??''));
    if($incoming!==''&&preg_match('/^[A-Za-z0-9._:-]{8,100}$/',$incoming))$id=$incoming;
    else $id='vp3-'.bin2hex(random_bytes(12));
    return $id;
}

function vp3_annotated_request_boot_v2100(): void
{
    if(PHP_SAPI==='cli'||headers_sent())return;
    header('X-VP3-Request-ID: '.vp3_annotated_request_id_v2100());
}

function vp3_annotated_schema_ready_v2100(?PDO $pdo=null): bool
{
    $pdo??=db();
    return (bool)$pdo
        && vp3_extension_schema_ready_v2000($pdo)
        && table_exists('annotated_onboarding_v2100')
        && table_exists('annotated_product_events_v2100')
        && table_exists('annotated_rate_buckets_v2100');
}

function vp3_annotated_require_ready_v2100(?PDO $pdo=null): PDO
{
    $pdo??=db();
    if(!$pdo||!vp3_annotated_schema_ready_v2100($pdo))throw new RuntimeException('Annotated release foundation is not ready. Run the current database upgrade.');
    return $pdo;
}

function vp3_annotated_uuid_v2100(): string
{
    return function_exists('vp3_extension_uuid_v2000')?vp3_extension_uuid_v2000():sprintf('%08x-%04x-4%03x-%04x-%012x',random_int(0,0xffffffff),random_int(0,0xffff),random_int(0,0xfff),random_int(0,0x3fff)|0x8000,random_int(0,0xffffffffffff));
}

function vp3_annotated_extension_compatibility_v2100(string $version): array
{
    $version=trim($version);
    $valid=(bool)preg_match('/^\d+\.\d+\.\d+(?:[-+][A-Za-z0-9.-]+)?$/',$version);
    if(!$valid)return [
        'version'=>$version,'supported'=>false,'status'=>'invalid','update_required'=>true,'update_recommended'=>true,
        'minimum'=>VP3_ANNOTATED_EXTENSION_MIN_V2100,'current'=>VP3_ANNOTATED_EXTENSION_CURRENT_V2100,
    ];
    $supported=version_compare($version,VP3_ANNOTATED_EXTENSION_MIN_V2100,'>=');
    $current=version_compare($version,VP3_ANNOTATED_EXTENSION_CURRENT_V2100,'>=');
    return [
        'version'=>$version,'supported'=>$supported,
        'status'=>!$supported?'unsupported':($current?'current':'outdated'),
        'update_required'=>!$supported,'update_recommended'=>$supported&&!$current,
        'minimum'=>VP3_ANNOTATED_EXTENSION_MIN_V2100,'current'=>VP3_ANNOTATED_EXTENSION_CURRENT_V2100,
    ];
}

function vp3_annotated_milestone_columns_v2100(): array
{
    return [
        'account_created'=>'account_created_at',
        'welcome_seen'=>'welcome_seen_at',
        'annotated_introduced'=>'annotated_introduced_at',
        'extension_offer_seen'=>'extension_offer_seen_at',
        'extension_offer_dismissed'=>'extension_offer_dismissed_at',
        'extension_connected'=>'extension_connected_at',
        'first_annotation_created'=>'first_annotation_at',
        'source_followed'=>'source_followed_at',
        'research_used'=>'research_used_at',
        'search_used'=>'search_used_at',
        'live_used'=>'live_used_at',
    ];
}

function vp3_annotated_manual_milestones_v2100(): array
{
    return ['welcome_seen','annotated_introduced','extension_offer_seen','extension_offer_dismissed'];
}

function vp3_annotated_ensure_user_row_v2100(PDO $pdo,int $userId): void
{
    if($userId<1)return;
    $pdo->prepare('INSERT IGNORE INTO annotated_onboarding_v2100(user_id,created_at,updated_at) VALUES(?,UTC_TIMESTAMP(),UTC_TIMESTAMP())')->execute([$userId]);
}

function vp3_annotated_event_v2100(PDO $pdo,int $userId,string $eventType,string $eventKey,array $metadata=[]): void
{
    if($userId<1||$eventType===''||$eventKey==='')return;
    $clean=[];
    foreach($metadata as $k=>$v){
        if(count($clean)>=20)break;
        $key=preg_replace('/[^a-zA-Z0-9_.-]/','',(string)$k)??'';
        if($key===''||in_array(strtolower($key),['password','token','secret','credential','authorization','cookie'],true))continue;
        if(is_bool($v)||is_int($v)||is_float($v)||is_null($v))$clean[$key]=$v;
        elseif(is_string($v))$clean[$key]=mb_substr($v,0,300);
    }
    $json=json_encode($clean,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
    $pdo->prepare("INSERT IGNORE INTO annotated_product_events_v2100(public_id,user_id,event_type,event_key,metadata_json,created_at)
      VALUES(?,?,?,?,?,UTC_TIMESTAMP())")
      ->execute([vp3_annotated_uuid_v2100(),$userId,mb_substr($eventType,0,60),mb_substr($eventKey,0,120),is_string($json)?$json:'{}']);
}

function vp3_annotated_mark_milestone_v2100(PDO $pdo,int $userId,string $milestone,array $metadata=[]): bool
{
    if($userId<1||!vp3_annotated_schema_ready_v2100($pdo))return false;
    $columns=vp3_annotated_milestone_columns_v2100();
    if(!isset($columns[$milestone]))throw new InvalidArgumentException('Unknown Annotated milestone.');
    vp3_annotated_ensure_user_row_v2100($pdo,$userId);
    $column=$columns[$milestone];
    $stmt=$pdo->prepare("UPDATE annotated_onboarding_v2100 SET {$column}=COALESCE({$column},UTC_TIMESTAMP()),updated_at=UTC_TIMESTAMP() WHERE user_id=?");
    $stmt->execute([$userId]);
    vp3_annotated_event_v2100($pdo,$userId,'milestone',$milestone,$metadata);
    return true;
}

function vp3_annotated_mark_milestone_safe_v2100(?PDO $pdo,int $userId,string $milestone,array $metadata=[]): void
{
    try{if($pdo&&vp3_annotated_schema_ready_v2100($pdo))vp3_annotated_mark_milestone_v2100($pdo,$userId,$milestone,$metadata);}catch(Throwable $e){error_log('Annotated milestone '.$milestone.' failed: '.$e->getMessage());}
}

function vp3_annotated_first_date_v2100(PDO $pdo,string $sql,array $params=[]): string
{
    try{$stmt=$pdo->prepare($sql);$stmt->execute($params);return trim((string)($stmt->fetchColumn()?:''));}catch(Throwable $e){return '';}
}

function vp3_annotated_sync_derived_milestones_v2100(PDO $pdo,int $userId): void
{
    if($userId<1||!vp3_annotated_schema_ready_v2100($pdo))return;
    vp3_annotated_ensure_user_row_v2100($pdo,$userId);
    $derived=[];

    $created=vp3_annotated_first_date_v2100($pdo,'SELECT created_at FROM users WHERE id=? LIMIT 1',[$userId]);
    if($created!=='')$derived['account_created_at']=$created;

    if(table_exists('extension_devices_v2000')){
        $v=vp3_annotated_first_date_v2100($pdo,"SELECT approved_at FROM extension_devices_v2000 WHERE user_id=? AND device_status='active' AND revoked_at IS NULL ORDER BY approved_at ASC LIMIT 1",[$userId]);
        if($v!=='')$derived['extension_connected_at']=$v;
    }
    if(table_exists('browser_shares_v2010')){
        $v=vp3_annotated_first_date_v2100($pdo,'SELECT created_at FROM browser_shares_v2010 WHERE sender_user_id=? AND deleted_at IS NULL ORDER BY created_at ASC,id ASC LIMIT 1',[$userId]);
        if($v!=='')$derived['first_annotation_at']=$v;
    }
    if(table_exists('browser_source_follows_v2050')){
        $v=vp3_annotated_first_date_v2100($pdo,'SELECT created_at FROM browser_source_follows_v2050 WHERE user_id=? ORDER BY created_at ASC LIMIT 1',[$userId]);
        if($v!=='')$derived['source_followed_at']=$v;
    }
    if(table_exists('browser_research_queue_v2050')){
        $v=vp3_annotated_first_date_v2100($pdo,'SELECT created_at FROM browser_research_queue_v2050 WHERE user_id=? ORDER BY created_at ASC LIMIT 1',[$userId]);
        if($v!=='')$derived['research_used_at']=$v;
    }
    if(!isset($derived['research_used_at'])&&table_exists('research_projects_v2060')){
        $v=vp3_annotated_first_date_v2100($pdo,'SELECT created_at FROM research_projects_v2060 WHERE owner_user_id=? AND deleted_at IS NULL ORDER BY created_at ASC LIMIT 1',[$userId]);
        if($v!=='')$derived['research_used_at']=$v;
    }
    if(table_exists('search_recent_queries_v2090')){
        $v=vp3_annotated_first_date_v2100($pdo,'SELECT created_at FROM search_recent_queries_v2090 WHERE user_id=? ORDER BY created_at ASC LIMIT 1',[$userId]);
        if($v!=='')$derived['search_used_at']=$v;
    }
    if(table_exists('live_room_members_v2070')){
        $v=vp3_annotated_first_date_v2100($pdo,'SELECT joined_at FROM live_room_members_v2070 WHERE user_id=? ORDER BY joined_at ASC LIMIT 1',[$userId]);
        if($v!=='')$derived['live_used_at']=$v;
    }

    foreach($derived as $column=>$value){
        if(!in_array($column,array_values(vp3_annotated_milestone_columns_v2100()),true))continue;
        $pdo->prepare("UPDATE annotated_onboarding_v2100 SET {$column}=COALESCE({$column},?),updated_at=UTC_TIMESTAMP() WHERE user_id=?")->execute([$value,$userId]);
    }
}

function vp3_annotated_device_state_v2100(PDO $pdo,int $userId): array
{
    if($userId<1||!vp3_extension_schema_ready_v2000($pdo))return ['connected'=>false,'active_count'=>0,'devices'=>[]];
    $rows=vp3_extension_devices_for_user_v2000($pdo,$userId);$devices=[];$active=0;
    foreach($rows as $row){
        if((string)$row['device_status']==='active'&&empty($row['revoked_at']))$active++;
        $compat=vp3_annotated_extension_compatibility_v2100((string)($row['extension_version']??''));
        $devices[]=[
            'id'=>(string)$row['public_id'],'name'=>(string)$row['device_name'],'browser'=>(string)$row['browser_family'],
            'version'=>(string)$row['extension_version'],'status'=>(string)$row['device_status'],'approved_at'=>(string)$row['approved_at'],
            'last_used_at'=>(string)($row['last_used_at']??''),'revoked_at'=>(string)($row['revoked_at']??''),
            'compatibility'=>$compat,
        ];
    }
    return ['connected'=>$active>0,'active_count'=>$active,'devices'=>$devices];
}

function vp3_annotated_user_state_v2100(PDO $pdo,int $userId): array
{
    if($userId<1)return ['available'=>false];
    vp3_annotated_require_ready_v2100($pdo);
    vp3_annotated_sync_derived_milestones_v2100($pdo,$userId);
    $stmt=$pdo->prepare('SELECT * FROM annotated_onboarding_v2100 WHERE user_id=? LIMIT 1');$stmt->execute([$userId]);$row=$stmt->fetch(PDO::FETCH_ASSOC)?:[];
    $milestones=[];$complete=0;
    foreach(vp3_annotated_milestone_columns_v2100() as $key=>$column){
        $at=(string)($row[$column]??'');$done=$at!=='';
        $milestones[$key]=['complete'=>$done,'at'=>$at];
        if($done)$complete++;
    }
    $devices=vp3_annotated_device_state_v2100($pdo,$userId);
    return [
        'available'=>true,'build'=>VP3_ANNOTATED_RELEASE_V2100,'product'=>'annotated',
        'milestones'=>$milestones,'completion_percent'=>(int)round(($complete/max(1,count($milestones)))*100),
        'browser_companion'=>$devices,
        'extension'=>['current'=>VP3_ANNOTATED_EXTENSION_CURRENT_V2100,'minimum'=>VP3_ANNOTATED_EXTENSION_MIN_V2100],
        'updated_at'=>(string)($row['updated_at']??''),
    ];
}

function vp3_annotated_privacy_inventory_v2100(PDO $pdo,int $userId): array
{
    $counts=[];
    foreach([
        'annotations'=>['browser_shares_v2010','sender_user_id','deleted_at IS NULL'],
        'followed_sources'=>['browser_source_follows_v2050','user_id','1=1'],
        'research_inbox'=>['browser_research_queue_v2050','user_id','1=1'],
        'saved_searches'=>['search_saved_queries_v2090','user_id','1=1'],
        'recent_searches'=>['search_recent_queries_v2090','user_id','1=1'],
        'live_memberships'=>['live_room_members_v2070','user_id','1=1'],
        'claims'=>['browser_claims_v2080','created_by_user_id','deleted_at IS NULL'],
    ] as $key=>[$table,$column,$extra]){
        if(!table_exists($table)){$counts[$key]=0;continue;}
        try{$stmt=$pdo->prepare("SELECT COUNT(*) FROM {$table} WHERE {$column}=? AND {$extra}");$stmt->execute([$userId]);$counts[$key]=(int)$stmt->fetchColumn();}catch(Throwable $e){$counts[$key]=0;}
    }
    return [
        'product'=>'annotated','counts'=>$counts,'browser_companion'=>vp3_annotated_device_state_v2100($pdo,$userId),
        'retention'=>[
            'expired_browser_sessions_days'=>VP3_ANNOTATED_EPHEMERAL_RETENTION_DAYS_V2100,
            'expired_connection_requests_days'=>VP3_ANNOTATED_EPHEMERAL_RETENTION_DAYS_V2100,
            'recent_search_history_days'=>VP3_ANNOTATED_RECENT_SEARCH_RETENTION_DAYS_V2100,
            'onboarding_event_ledger_days'=>VP3_ANNOTATED_EVENT_RETENTION_DAYS_V2100,
            'content_auto_delete'=>false,
        ],
    ];
}

function vp3_annotated_rate_policy_v2100(string $scope): array
{
    return match($scope){
        'browser_share_create'=>[30,60],
        'media_upload'=>[60,60],
        'annotation_publish'=>[30,60],
        'annotation_comment'=>[60,60],
        'source_follow'=>[60,60],
        'research_write'=>[60,60],
        'live_create'=>[10,600],
        'live_send'=>[120,60],
        'live_read'=>[240,60],
        'source_observe'=>[60,60],
        'claim_create'=>[20,3600],
        'report_create'=>[20,3600],
        'search_read'=>[120,60],
        'search_write'=>[60,60],
        'release_state'=>[120,60],
        default=>[120,60],
    };
}

function vp3_annotated_rate_subject_v2100(int $userId,string $suffix=''): string
{
    if($userId>0)return hash('sha256','user:'.$userId.':'.$suffix);
    $ip=function_exists('vp3_extension_request_ip_v2000')?vp3_extension_request_ip_v2000():(string)($_SERVER['REMOTE_ADDR']??'');
    $secret=function_exists('vp3_extension_secret_v2000')?vp3_extension_secret_v2000():'vp3-anonymous';
    return hash_hmac('sha256','ip:'.$ip.':'.$suffix,$secret);
}

function vp3_annotated_rate_limit_v2100(PDO $pdo,int $userId,string $scope,string $suffix=''): array
{
    if(!vp3_annotated_schema_ready_v2100($pdo))return ['remaining'=>null,'limit'=>null,'retry_after'=>0];
    [$limit,$window]=vp3_annotated_rate_policy_v2100($scope);
    $now=time();$bucketEpoch=intdiv($now,$window)*$window;$bucket=gmdate('Y-m-d H:i:s',$bucketEpoch);
    $subject=vp3_annotated_rate_subject_v2100($userId,$suffix);
    $pdo->prepare("INSERT INTO annotated_rate_buckets_v2100(subject_hash,rate_scope,bucket_start,hit_count,updated_at)
      VALUES(?,?,?,1,UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE hit_count=hit_count+1,updated_at=UTC_TIMESTAMP()")
      ->execute([$subject,$scope,$bucket]);
    $stmt=$pdo->prepare('SELECT hit_count FROM annotated_rate_buckets_v2100 WHERE subject_hash=? AND rate_scope=? AND bucket_start=? LIMIT 1');
    $stmt->execute([$subject,$scope,$bucket]);$count=(int)$stmt->fetchColumn();
    $retry=max(1,$bucketEpoch+$window-$now);
    if($count>$limit)throw new VP3AnnotatedRateLimitExceptionV2100($retry,$scope);
    return ['remaining'=>max(0,$limit-$count),'limit'=>$limit,'retry_after'=>$retry];
}

function vp3_annotated_housekeeping_v2100(PDO $pdo): array
{
    if(!vp3_annotated_schema_ready_v2100($pdo))return [];
    $out=[];
    $out['rate_buckets']=$pdo->exec("DELETE FROM annotated_rate_buckets_v2100 WHERE bucket_start<DATE_SUB(UTC_TIMESTAMP(),INTERVAL 2 DAY)");
    $out['events']=$pdo->exec('DELETE FROM annotated_product_events_v2100 WHERE created_at<DATE_SUB(UTC_TIMESTAMP(),INTERVAL '.VP3_ANNOTATED_EVENT_RETENTION_DAYS_V2100.' DAY)');
    if(table_exists('search_recent_queries_v2090'))$out['recent_searches']=$pdo->exec('DELETE FROM search_recent_queries_v2090 WHERE last_used_at<DATE_SUB(UTC_TIMESTAMP(),INTERVAL '.VP3_ANNOTATED_RECENT_SEARCH_RETENTION_DAYS_V2100.' DAY)');
    if(table_exists('extension_sessions_v2000'))$out['extension_sessions']=$pdo->exec('DELETE FROM extension_sessions_v2000 WHERE expires_at<DATE_SUB(UTC_TIMESTAMP(),INTERVAL '.VP3_ANNOTATED_EPHEMERAL_RETENTION_DAYS_V2100.' DAY)');
    if(table_exists('extension_connection_requests_v2000'))$out['connection_requests']=$pdo->exec('DELETE FROM extension_connection_requests_v2000 WHERE expires_at<DATE_SUB(UTC_TIMESTAMP(),INTERVAL '.VP3_ANNOTATED_EPHEMERAL_RETENTION_DAYS_V2100.' DAY)');
    return $out;
}

function vp3_annotated_housekeeping_maybe_v2100(): void
{
    if(PHP_SAPI==='cli')return;
    $today=gmdate('Y-m-d');
    if((string)($_SESSION['vp3_annotated_housekeeping_v2100']??'')===$today)return;
    $_SESSION['vp3_annotated_housekeeping_v2100']=$today;
    try{$pdo=db();if($pdo&&vp3_annotated_schema_ready_v2100($pdo))vp3_annotated_housekeeping_v2100($pdo);}catch(Throwable $e){error_log('Annotated housekeeping failed: '.$e->getMessage());}
}

function vp3_annotated_health_v2100(PDO $pdo): array
{
    $ready=vp3_annotated_schema_ready_v2100($pdo);
    $health=['ready'=>$ready,'build'=>VP3_ANNOTATED_RELEASE_V2100,'extension'=>['current'=>VP3_ANNOTATED_EXTENSION_CURRENT_V2100,'minimum'=>VP3_ANNOTATED_EXTENSION_MIN_V2100]];
    if(!$ready)return $health;
    $counts=[];
    foreach(['annotated_onboarding_v2100','annotated_product_events_v2100','annotated_rate_buckets_v2100'] as $table){
        try{$counts[$table]=(int)$pdo->query("SELECT COUNT(*) FROM {$table}")->fetchColumn();}catch(Throwable $e){$counts[$table]=-1;}
    }
    $versions=[];
    if(table_exists('extension_devices_v2000')){
        $stmt=$pdo->query("SELECT extension_version,COUNT(*) c FROM extension_devices_v2000 WHERE device_status='active' AND revoked_at IS NULL GROUP BY extension_version ORDER BY c DESC,extension_version DESC LIMIT 20");
        foreach($stmt->fetchAll(PDO::FETCH_ASSOC)?:[] as $row)$versions[]=['version'=>(string)$row['extension_version'],'count'=>(int)$row['c'],'compatibility'=>vp3_annotated_extension_compatibility_v2100((string)$row['extension_version'])];
    }
    $health['counts']=$counts;$health['extension_versions']=$versions;
    return $health;
}

function vp3_annotated_ensure_schema_v2100(?PDO $pdo=null): void
{
    $pdo??=db();if(!$pdo)throw new RuntimeException('Database connection is unavailable.');
    if(vp3_annotated_schema_ready_v2100($pdo))return;
    if(!vp3_extension_schema_ready_v2000($pdo))throw new RuntimeException('Browser Companion device authentication must be installed before Annotated release foundation.');

    $pdo->exec("CREATE TABLE IF NOT EXISTS annotated_onboarding_v2100 (
      user_id INT UNSIGNED NOT NULL PRIMARY KEY,
      account_created_at DATETIME NULL,
      welcome_seen_at DATETIME NULL,
      annotated_introduced_at DATETIME NULL,
      extension_offer_seen_at DATETIME NULL,
      extension_offer_dismissed_at DATETIME NULL,
      extension_connected_at DATETIME NULL,
      first_annotation_at DATETIME NULL,
      source_followed_at DATETIME NULL,
      research_used_at DATETIME NULL,
      search_used_at DATETIME NULL,
      live_used_at DATETIME NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      CONSTRAINT fk_annotated_onboarding_user_v2100 FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS annotated_product_events_v2100 (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
      public_id CHAR(36) NOT NULL,
      user_id INT UNSIGNED NOT NULL,
      event_type VARCHAR(60) NOT NULL,
      event_key VARCHAR(120) NOT NULL,
      metadata_json TEXT NOT NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      UNIQUE KEY uq_annotated_event_public_v2100 (public_id),
      UNIQUE KEY uq_annotated_event_key_v2100 (user_id,event_key),
      INDEX idx_annotated_event_user_v2100 (user_id,created_at,id),
      INDEX idx_annotated_event_type_v2100 (event_type,created_at,id),
      CONSTRAINT fk_annotated_event_user_v2100 FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS annotated_rate_buckets_v2100 (
      subject_hash CHAR(64) NOT NULL,
      rate_scope VARCHAR(60) NOT NULL,
      bucket_start DATETIME NOT NULL,
      hit_count INT UNSIGNED NOT NULL DEFAULT 0,
      updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      PRIMARY KEY (subject_hash,rate_scope,bucket_start),
      INDEX idx_annotated_rate_cleanup_v2100 (bucket_start,updated_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("INSERT IGNORE INTO annotated_onboarding_v2100(user_id,account_created_at,created_at,updated_at)
      SELECT id,created_at,UTC_TIMESTAMP(),UTC_TIMESTAMP() FROM users");
    vp3_annotated_housekeeping_v2100($pdo);
}
