<?php
declare(strict_types=1);

/**
 * VP3 Cloud / HomeServer v2.1 — Shared Agent Data Fabric.
 *
 * Cloud and HomeServer retain authoritative ownership of their native records.
 * The paired HTTPS relay exchanges bounded versioned snapshots so both Agent
 * runtimes can retrieve one logical context without cross-database ID writes.
 */
const VP3_HOMESERVER_SHARED_AGENT_V210='vp3-homeserver-shared-agent-v210-20260924';
const VP3_HOMESERVER_SHARED_AGENT_VERSION='2.2';

function homeserver_shared_v210_ensure_schema(?PDO $pdo=null): void
{
    $pdo ??= db();
    if(!$pdo)throw new RuntimeException('Database connection is unavailable.');
    $pdo->exec("CREATE TABLE IF NOT EXISTS homeserver_agent_state (
      user_id INT UNSIGNED NOT NULL PRIMARY KEY,
      connection_state VARCHAR(40) NOT NULL DEFAULT 'not_connected',
      status_fingerprint CHAR(64) NOT NULL DEFAULT '',
      last_event_at DATETIME NULL,
      last_roundtrip_at DATETIME NULL,
      last_roundtrip_ms INT UNSIGNED NULL,
      last_roundtrip_request_id CHAR(36) NOT NULL DEFAULT '',
      last_roundtrip_ok TINYINT(1) NOT NULL DEFAULT 0,
      last_sync_at DATETIME NULL,
      cloud_revision CHAR(64) NOT NULL DEFAULT '',
      homeserver_revision CHAR(64) NOT NULL DEFAULT '',
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      CONSTRAINT fk_homeserver_agent_state_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $pdo->exec("CREATE TABLE IF NOT EXISTS homeserver_agent_events (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
      user_id INT UNSIGNED NOT NULL,
      event_type VARCHAR(80) NOT NULL,
      connection_state VARCHAR(40) NOT NULL DEFAULT '',
      detail VARCHAR(500) NOT NULL DEFAULT '',
      metadata_json LONGTEXT NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      INDEX idx_homeserver_agent_events_user (user_id,created_at,id),
      CONSTRAINT fk_homeserver_agent_events_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

function homeserver_shared_v210_schema_ready(): bool
{
    return function_exists('table_exists')
      && table_exists('homeserver_agent_state')
      && table_exists('homeserver_agent_events');
}

function homeserver_shared_v210_text(mixed $value,int $max=1600): string
{
    $text=trim(preg_replace('/\s+/u',' ',(string)$value)??(string)$value);
    return mb_strimwidth($text,0,$max,'…');
}

function homeserver_shared_v210_terms(string $query): array
{
    $parts=preg_split('/[^\pL\pN._@+-]+/u',mb_strtolower($query))?:[];
    $stop=array_flip(['the','and','for','with','from','that','this','what','when','where','which','who','why','how','have','has','your','you','my','our','are','was','were','about']);
    return array_slice(array_values(array_unique(array_filter($parts,static fn(string $x):bool=>mb_strlen($x)>=3&&!isset($stop[$x])))),0,16);
}

function homeserver_shared_v210_matches(string $query,string $text): bool
{
    $terms=homeserver_shared_v210_terms($query);
    if(!$terms)return true;
    $hay=mb_strtolower($text);
    foreach($terms as $term)if(str_contains($hay,$term))return true;
    return false;
}

function homeserver_shared_v210_record(string $dataset,mixed $id,string $title,string $content,?string $updatedAt=null): array
{
    $key=$dataset.':'.homeserver_shared_v210_text((string)$id,120);
    $item=function_exists('homeserver_federated_v240_envelope')
      ?homeserver_federated_v240_envelope('vp3_cloud',$dataset,$key,$title,$content,$updatedAt)
      :[];
    return [
      'key'=>$key,
      'title'=>homeserver_shared_v210_text($title,240),
      'content'=>homeserver_shared_v210_text($content,2200),
      'updated_at'=>$updatedAt?:null,
      'authoritative_source'=>'vp3_cloud',
      'authority_key'=>$item['authority_key']??$key,
      'canonical_id'=>$item['canonical_id']??null,
      'record_revision'=>$item['record_revision']??null,
      'federation_version'=>$item['federation_version']??null,
      'mirror_only'=>false,
      'dataset'=>$dataset,
    ];
}

function homeserver_shared_v210_fit_datasets(array $datasets,int $maxBytes=170000): array
{
    $order=['notifications','files','calendar','tasks','contacts','knowledge','memory'];
    while(true){
        $encoded=json_encode($datasets,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
        if(is_string($encoded)&&strlen($encoded)<=$maxBytes)break;
        $removed=false;
        foreach($order as $dataset){
            if(count((array)($datasets[$dataset]??[]))>5){
                array_pop($datasets[$dataset]);$removed=true;break;
            }
        }
        if(!$removed)break;
    }
    return $datasets;
}

function homeserver_shared_v210_cloud_snapshot(int $userId,string $query=''): array
{
    $pdo=db();if(!$pdo||$userId<1)throw new RuntimeException('Database connection is unavailable.');
    $datasets=['memory'=>[],'knowledge'=>[],'contacts'=>[],'tasks'=>[],'calendar'=>[],'files'=>[],'notifications'=>[]];

    if(table_exists('agent_memory_items')){
        $s=$pdo->prepare("SELECT id,memory_type,subject,memory_text,confidence,last_seen_at,metadata_json
          FROM agent_memory_items WHERE user_id=? AND is_active=1 ORDER BY last_seen_at DESC,id DESC LIMIT 120");
        $s->execute([$userId]);
        foreach($s->fetchAll()?:[] as $row){
            $text=trim((string)($row['subject']??'').' '.(string)($row['memory_text']??''));
            if(!homeserver_shared_v210_matches($query,$text))continue;
            $datasets['memory'][]=homeserver_shared_v210_record(
              'memory',(int)$row['id'],
              (string)($row['subject']??'Agent Brain memory'),
              (string)($row['memory_text']??'').' · type: '.(string)($row['memory_type']??'memory').' · confidence: '.round(((float)($row['confidence']??0))*100).'%',
              (string)($row['last_seen_at']??'')
            );
            if(count($datasets['memory'])>=40)break;
        }

        if(function_exists('homeserver_task_calendar_v243_cloud_tasks')){
            foreach(homeserver_task_calendar_v243_cloud_tasks($userId,$query,30) as $row){
                if(!is_array($row))continue;
                $datasets['tasks'][]=[
                  'id'=>(int)($row['id']??0),
                  'key'=>(string)($row['authority_key']??''),
                  'title'=>(string)($row['title']??'Agent task'),
                  'content'=>(string)($row['description']??'').' · status: '.(string)($row['status']??'open').' · due: '.(string)($row['due_at']??''),
                  'updated_at'=>$row['updated_at']??null,
                  'authoritative_source'=>'vp3_cloud',
                  'authority_source'=>'vp3_cloud',
                  'authority_key'=>(string)($row['authority_key']??''),
                  'canonical_id'=>$row['canonical_id']??null,
                  'record_revision'=>$row['record_revision']??null,
                  'federation_version'=>$row['federation_version']??'2.4',
                  'mirror_only'=>false,
                  'dataset'=>'tasks',
                ];
            }
        }
    }

    if(table_exists('knowledge_items')&&column_exists('knowledge_items','created_by_user_id')){
        $s=$pdo->prepare("SELECT id,title,description,content_text,updated_at
          FROM knowledge_items WHERE created_by_user_id=? ORDER BY updated_at DESC,id DESC LIMIT 80");
        $s->execute([$userId]);
        foreach($s->fetchAll()?:[] as $row){
            $content=trim((string)($row['description']??'').' '.(string)($row['content_text']??''));
            if(!homeserver_shared_v210_matches($query,(string)$row['title'].' '.$content))continue;
            $datasets['knowledge'][]=homeserver_shared_v210_record('knowledge',(int)$row['id'],(string)$row['title'],$content,(string)($row['updated_at']??''));
            if(count($datasets['knowledge'])>=30)break;
        }
    }

    if(function_exists('homeserver_files_v244_cloud_items')){
        try{
            foreach(homeserver_files_v244_cloud_items($userId,$query,40) as $row){
                if(!is_array($row))continue;
                $datasets['files'][]=[
                  'id'=>(int)($row['id']??0),
                  'key'=>(string)($row['authority_key']??''),
                  'title'=>(string)($row['title']??$row['name']??'Cloud file'),
                  'content'=>trim(
                    'file: '.(string)($row['name']??'')
                    .' · type: '.(string)($row['file_type']??'')
                    .' · size: '.(int)($row['size_bytes']??0).' bytes'
                    .' · folder: '.(string)($row['folder_name']??'Unfiled')
                  ),
                  'updated_at'=>$row['updated_at']??null,
                  'authoritative_source'=>'vp3_cloud',
                  'authority_source'=>'vp3_cloud',
                  'authority_key'=>(string)($row['authority_key']??''),
                  'canonical_id'=>$row['canonical_id']??null,
                  'record_revision'=>$row['record_revision']??null,
                  'federation_version'=>$row['federation_version']??'2.4',
                  'mirror_only'=>false,
                  'dataset'=>'files',
                ];
                if(count($datasets['files'])>=40)break;
            }
        }catch(Throwable $ignored){}
    }

    if(function_exists('homeserver_contacts_v241_snapshot_records')){
        try{
            foreach(homeserver_contacts_v241_snapshot_records($userId,$query,80) as $row){
                if(!is_array($row))continue;
                $datasets['contacts'][]=$row;
                if(count($datasets['contacts'])>=80)break;
            }
        }catch(Throwable $ignored){}
    }

    if(function_exists('homeserver_task_calendar_v243_cloud_calendar_records')){
        try{
            $calendarFrom=gmdate('Y-m-d H:i:s',time()-30*86400);
            $calendarTo=gmdate('Y-m-d H:i:s',time()+370*86400);
            foreach(homeserver_task_calendar_v243_cloud_calendar_records($userId,$calendarFrom,$calendarTo) as $row){
                if(!is_array($row))continue;
                $datasets['calendar'][]=[
                  'id'=>(int)($row['id']??0),
                  'key'=>(string)($row['authority_key']??''),
                  'title'=>(string)($row['title']??'Calendar event'),
                  'content'=>trim((string)($row['description']??'').' · '.(string)($row['location']??'').' · '.(string)($row['start_at_utc']??'').' → '.(string)($row['end_at_utc']??'')),
                  'updated_at'=>$row['updated_at']??null,
                  'authoritative_source'=>'vp3_cloud',
                  'authority_source'=>'vp3_cloud',
                  'authority_key'=>(string)($row['authority_key']??''),
                  'canonical_id'=>$row['canonical_id']??null,
                  'record_revision'=>$row['record_revision']??null,
                  'federation_version'=>$row['federation_version']??'2.4',
                  'mirror_only'=>false,
                  'dataset'=>'calendar',
                ];
                if(count($datasets['calendar'])>=60)break;
            }
        }catch(Throwable $ignored){}
    }

    if(table_exists('notifications')){
        $s=$pdo->prepare("SELECT id,type,title,body,is_read,created_at FROM notifications
          WHERE user_id=? AND created_at>=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 14 DAY)
          ORDER BY is_read ASC,id DESC LIMIT 80");
        $s->execute([$userId]);
        foreach($s->fetchAll()?:[] as $row){
            $text=(string)($row['title']??'').' '.(string)($row['body']??'');
            if(!homeserver_shared_v210_matches($query,$text))continue;
            $datasets['notifications'][]=homeserver_shared_v210_record(
              'notifications',(int)$row['id'],(string)($row['title']??'Notification'),
              (string)($row['body']??'').' · '.(!empty($row['is_read'])?'read':'unread').' · type: '.(string)($row['type']??''),
              (string)($row['created_at']??'')
            );
            if(count($datasets['notifications'])>=30)break;
        }
    }

    $datasets=homeserver_shared_v210_fit_datasets($datasets);
    $json=json_encode($datasets,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
    $revision=hash('sha256',is_string($json)?$json:'{}');
    return [
      'version'=>VP3_HOMESERVER_SHARED_AGENT_VERSION,
      'revision'=>$revision,
      'generated_at'=>gmdate(DATE_ATOM),
      'authoritative_source'=>'vp3_cloud',
      'federation_version'=>defined('VP3_HOMESERVER_FEDERATED_DATA_VERSION')?VP3_HOMESERVER_FEDERATED_DATA_VERSION:null,
      'snapshot_mode'=>$query===''?'full':'filtered',
      'datasets'=>$datasets,
    ];
}

function homeserver_shared_v210_exchange_once(
    int $userId,string $query='',string $triggerReason='query'
): ?array {
    $query=homeserver_shared_v210_text($query,240);
    if(!function_exists('homeserver_https_v1300_status')||!function_exists('homeserver_https_v1300_remote_operation'))return null;
    $status=homeserver_https_v1300_status($userId);
    if(!$status||empty($status['connected'])||empty($status['paired']))return null;

    $cloud=homeserver_shared_v210_cloud_snapshot($userId,$query);
    $beforeReconciliation=function_exists('homeserver_reconciliation_v246_state')
      ?homeserver_reconciliation_v246_state($userId):['needs_reconciliation'=>false];

    $requestId=homeserver_https_v1300_queue($userId,'shared.context.exchange',[
      'query'=>$query,
      'cloud_snapshot'=>$cloud,
      'reconciliation_trigger'=>$triggerReason,
    ]);
    $result=homeserver_https_v1300_wait($requestId,$query===''?9000:6500);
    $home=is_array($result['homeserver_snapshot']??null)?$result['homeserver_snapshot']:null;
    if(!$home)throw new RuntimeException('HomeServer did not return a shared Agent snapshot.');

    $remoteSummary=null;
    if(function_exists('homeserver_federated_v240_observe_snapshot')){
        homeserver_federated_v240_observe_snapshot($userId,$cloud,'vp3_cloud');
    }
    if(function_exists('homeserver_reconciliation_v246_reconcile_snapshot')){
        $remoteSummary=homeserver_reconciliation_v246_reconcile_snapshot(
          $userId,$home,'vp3_cloud',$triggerReason,(string)($cloud['revision']??'')
        );
    }elseif(function_exists('homeserver_federated_v240_observe_snapshot')){
        homeserver_federated_v240_observe_snapshot($userId,$home,'vp3_cloud');
    }

    if(($cloud['snapshot_mode']??'filtered')==='full'){
        $homeApplied=$result['reconciliation']['cloud_to_homeserver']
          ??$result['cloud_mirror']['reconciliation']
          ??null;
        if(!is_array($homeApplied)
          ||(string)($homeApplied['status']??'')!=='completed'
          ||(string)($homeApplied['snapshot_mode']??'')!=='full'){
            if(function_exists('homeserver_reconciliation_v246_mark_required')){
                homeserver_reconciliation_v246_mark_required(
                  $userId,'HomeServer did not confirm full Cloud reconciliation.'
                );
            }
            throw new RuntimeException('HomeServer did not confirm full Cloud reconciliation.');
        }
        if(!is_array($remoteSummary)
          ||(string)($remoteSummary['status']??'')!=='completed'
          ||(string)($remoteSummary['snapshot_mode']??'')!=='full'){
            if(function_exists('homeserver_reconciliation_v246_mark_required')){
                homeserver_reconciliation_v246_mark_required(
                  $userId,'Cloud did not complete the HomeServer mirror reconciliation.'
                );
            }
            throw new RuntimeException('Cloud did not complete HomeServer reconciliation.');
        }
    }

    $pdo=db();
    if($pdo&&homeserver_shared_v210_schema_ready()){
        $pdo->prepare("INSERT INTO homeserver_agent_state(user_id,last_sync_at,cloud_revision,homeserver_revision)
          VALUES (?,UTC_TIMESTAMP(),?,?)
          ON DUPLICATE KEY UPDATE last_sync_at=UTC_TIMESTAMP(),cloud_revision=VALUES(cloud_revision),homeserver_revision=VALUES(homeserver_revision)")
          ->execute([$userId,(string)($cloud['revision']??''),(string)($home['revision']??'')]);
    }

    if(($cloud['snapshot_mode']??'filtered')==='full'&&!empty($beforeReconciliation['needs_reconciliation'])){
        $summary=is_array($remoteSummary)?$remoteSummary:[];
        $changes=(int)($summary['created']??0)+(int)($summary['updated']??0)
          +(int)($summary['restored']??0)+(int)($summary['tombstoned']??0);
        if($pdo&&function_exists('vp3_cognitive_homeserver_event_v2390')){
            vp3_cognitive_homeserver_event_v2390($pdo,$userId,'homeserver.reconciled','connected',[
              'priority'=>'normal',
              'reconciliation_run_id'=>(string)($summary['run_id']??''),
              'change_count'=>$changes,
              'created'=>(int)($summary['created']??0),
              'updated'=>(int)($summary['updated']??0),
              'restored'=>(int)($summary['restored']??0),
              'tombstoned'=>(int)($summary['tombstoned']??0),
            ]);
        }
        if(!empty($beforeReconciliation['last_disconnect_at'])&&function_exists('create_notification')){
            create_notification(
              $userId,'homeserver_connection_update','HomeServer continuity restored',
              $changes>0
                ? 'HomeServer reconnected and reconciled '.$changes.' continuity change'.($changes===1?'':'s').'. Agent Brain context and approved HomeServer capabilities are current again.'
                : 'HomeServer reconnected and reconciliation completed with no continuity changes. Agent Brain context and approved HomeServer capabilities are current again.',
              url('/settings-homeserver.php'),'homeserver_reconciliation',(string)($summary['run_id']??'')
            );
        }
    }
    return $home;
}

function homeserver_shared_v210_reconcile_full(int $userId): ?array
{
    if($userId<1)return null;
    return homeserver_shared_v210_exchange_once($userId,'','reconnect');
}

function homeserver_shared_v210_exchange(int $userId,string $query=''): ?array
{
    static $cache=[];
    $query=homeserver_shared_v210_text($query,240);
    $cacheKey=$userId.'|'.sha1($query);
    if(array_key_exists($cacheKey,$cache))return $cache[$cacheKey];
    if(!function_exists('homeserver_https_v1300_status'))return $cache[$cacheKey]=null;
    $status=homeserver_https_v1300_status($userId);
    if(!$status||empty($status['connected'])||empty($status['paired']))return $cache[$cacheKey]=null;

    try{
        if(function_exists('homeserver_reconciliation_v246_state')){
            $reconciliation=homeserver_reconciliation_v246_state($userId);
            if(!empty($reconciliation['needs_reconciliation'])){
                $full=homeserver_shared_v210_reconcile_full($userId);
                if(!$full)return $cache[$cacheKey]=null;
                if($query==='')return $cache[$cacheKey]=$full;
            }
        }
        return $cache[$cacheKey]=homeserver_shared_v210_exchange_once(
          $userId,$query,$query===''?'full-sync':'query'
        );
    }catch(Throwable $e){
        if(function_exists('homeserver_reconciliation_v246_mark_required')){
            homeserver_reconciliation_v246_mark_required($userId,$e->getMessage());
        }
        $pdo=db();
        if($pdo&&function_exists('vp3_cognitive_homeserver_event_v2390')){
            vp3_cognitive_homeserver_event_v2390($pdo,$userId,'homeserver.reconciliation_failed','connection_error',[
              'priority'=>'critical','failure_class'=>'reconciliation',
            ]);
        }
        error_log('HomeServer v2.4 reconciliation/shared Agent exchange failed: '.$e->getMessage());
        return $cache[$cacheKey]=null;
    }
}

function homeserver_shared_v210_context_items(array $user,string $query,int $limit=18): array
{
    $userId=(int)($user['id']??0);if($userId<1)return [];
    $snapshot=homeserver_shared_v210_exchange($userId,$query);
    if(!$snapshot)return [];
    $datasets=is_array($snapshot['datasets']??null)?$snapshot['datasets']:[];
    $out=[];
    foreach(['memory','knowledge','contacts','tasks','calendar','files','notifications'] as $dataset){
        foreach((array)($datasets[$dataset]??[]) as $row){
            if(!is_array($row))continue;
            $text=homeserver_shared_v210_text($row['content']??'',2200);if($text==='')continue;
            $authority=homeserver_shared_v210_text($row['authoritative_source']??'homeserver',40);
            $canonical=homeserver_shared_v210_text($row['canonical_id']??'',80);
            $out[]=[
              'dataset'=>$dataset,
              'source'=>'homeserver:'.$dataset.':'.($canonical!==''?$canonical:homeserver_shared_v210_text($row['key']??$row['id']??'',120)),
              'title'=>homeserver_shared_v210_text($row['title']??ucfirst($dataset),240),
              'text'=>$text,
              'updated_at'=>$row['updated_at']??null,
              'authoritative_source'=>$authority,
              'authority_key'=>homeserver_shared_v210_text($row['authority_key']??$row['key']??'',180),
              'canonical_id'=>$canonical!==''?$canonical:null,
              'record_revision'=>homeserver_shared_v210_text($row['record_revision']??'',64)?:null,
              'federation_version'=>homeserver_shared_v210_text($row['federation_version']??'',20)?:null,
            ];
            if(count($out)>=max(1,min(40,$limit)))break 2;
        }
    }
    return $out;
}

function homeserver_shared_v210_roundtrip(int $userId): array
{
    if($userId<1)throw new RuntimeException('A signed-in user is required.');
    $nonce=bin2hex(random_bytes(12));$started=microtime(true);
    $requestId=homeserver_https_v1300_queue($userId,'system.ping',['nonce'=>$nonce,'sent_at'=>gmdate(DATE_ATOM)]);
    try{
        $payload=homeserver_https_v1300_wait($requestId,12000);
        $latency=(int)round((microtime(true)-$started)*1000);
        $ok=!empty($payload['pong'])&&hash_equals($nonce,(string)($payload['echo']??''));
        if(!$ok)throw new RuntimeException('HomeServer returned an invalid ping response.');
        $pdo=db();
        if($pdo&&homeserver_shared_v210_schema_ready()){
            $pdo->prepare("INSERT INTO homeserver_agent_state(user_id,last_roundtrip_at,last_roundtrip_ms,last_roundtrip_request_id,last_roundtrip_ok)
              VALUES (?,UTC_TIMESTAMP(),?,?,1)
              ON DUPLICATE KEY UPDATE last_roundtrip_at=UTC_TIMESTAMP(),last_roundtrip_ms=VALUES(last_roundtrip_ms),
                last_roundtrip_request_id=VALUES(last_roundtrip_request_id),last_roundtrip_ok=1")
              ->execute([$userId,$latency,$requestId]);
        }
        if(function_exists('vp3_cognitive_homeserver_event_v2390')&&$pdo instanceof PDO){
            vp3_cognitive_homeserver_event_v2390($pdo,$userId,'homeserver.status_changed','round_trip_verified',[
              'request_id'=>$requestId,'latency_ms'=>$latency,'homeserver_version'=>(string)($payload['version']??''),
            ]);
        }
        return ['verified'=>true,'request_id'=>$requestId,'latency_ms'=>$latency,'response'=>$payload];
    }catch(Throwable $e){
        $pdo=db();
        if($pdo&&homeserver_shared_v210_schema_ready()){
            $pdo->prepare("INSERT INTO homeserver_agent_state(user_id,last_roundtrip_at,last_roundtrip_request_id,last_roundtrip_ok)
              VALUES (?,UTC_TIMESTAMP(),?,0)
              ON DUPLICATE KEY UPDATE last_roundtrip_at=UTC_TIMESTAMP(),last_roundtrip_request_id=VALUES(last_roundtrip_request_id),last_roundtrip_ok=0")
              ->execute([$userId,$requestId]);
        }
        throw $e;
    }
}

function homeserver_shared_v210_diagnostics(int $userId): array
{
    $pdo=db();if(!$pdo||!homeserver_shared_v210_schema_ready())return ['round_trip_verified'=>false];
    $s=$pdo->prepare('SELECT * FROM homeserver_agent_state WHERE user_id=? LIMIT 1');$s->execute([$userId]);$row=$s->fetch()?:[];
    return [
      'round_trip_verified'=>!empty($row['last_roundtrip_ok']),
      'last_roundtrip_at'=>$row['last_roundtrip_at']??null,
      'last_roundtrip_ms'=>isset($row['last_roundtrip_ms'])?(int)$row['last_roundtrip_ms']:null,
      'last_roundtrip_request_id'=>(string)($row['last_roundtrip_request_id']??''),
      'last_shared_sync_at'=>$row['last_sync_at']??null,
      'cloud_revision'=>(string)($row['cloud_revision']??''),
      'homeserver_revision'=>(string)($row['homeserver_revision']??''),
      'reconciliation'=>function_exists('homeserver_reconciliation_v246_diagnostics')
        ?homeserver_reconciliation_v246_diagnostics($userId):null,
    ];
}

function homeserver_shared_v210_live_status(int $userId): array
{
    $raw=homeserver_vp3_status($userId,false);
    $row=homeserver_vp3_connection($userId);
    $connected=!empty($raw['connected']);$paired=!empty($raw['paired']);
    $rowStatus=(string)($row['status']??'');
    $state='not_connected';
    if(in_array($rowStatus,['disconnected','revoked'],true)||(string)($raw['state']??'')==='revoked')$state='disconnected';
    elseif($connected&&$paired)$state='connected';
    elseif($paired)$state='connection_error';
    elseif($connected)$state='connecting';
    $raw['connection_state']=$state;
    $raw['release_version']=VP3_HOMESERVER_RELEASE_VERSION;
    return $raw;
}

function homeserver_shared_v210_refresh_cognition(int $userId): array
{
    if($userId<1)return [];
    try{return homeserver_shared_v210_reconcile_status($userId,homeserver_shared_v210_live_status($userId));}
    catch(Throwable $e){error_log('HomeServer v2.1 cognition refresh failed: '.$e->getMessage());return [];}
}

function homeserver_shared_v210_reconcile_status(int $userId,array $status): array
{
    $pdo=db();if(!$pdo||$userId<1||!homeserver_shared_v210_schema_ready())return $status;
    $state=homeserver_shared_v210_text($status['connection_state']??$status['state']??'not_connected',40);
    $material=[
      'state'=>$state,
      'connected'=>!empty($status['connected']),
      'paired'=>!empty($status['paired']),
      'transport'=>(string)($status['transport']??''),
      'installed_version'=>(string)($status['installed_version']??''),
      'release_version'=>(string)($status['release_version']??VP3_HOMESERVER_RELEASE_VERSION),
      'error'=>(string)($status['error']??''),
    ];
    $fingerprint=hash('sha256',json_encode($material,JSON_UNESCAPED_SLASHES)?:'{}');
    $s=$pdo->prepare('SELECT connection_state,status_fingerprint FROM homeserver_agent_state WHERE user_id=? LIMIT 1');
    $s->execute([$userId]);$before=$s->fetch()?:null;
    $changed=!$before||!hash_equals((string)($before['status_fingerprint']??''),$fingerprint);
    $previous=(string)($before['connection_state']??'not_connected');
    $reconciliation=function_exists('homeserver_reconciliation_v246_state')
      ?homeserver_reconciliation_v246_state($userId):['needs_reconciliation'=>false];

    if($changed){
        $reconnected=$state==='connected'&&in_array($previous,['connection_error','disconnected'],true);
        if(in_array($state,['connection_error','disconnected'],true)
          &&function_exists('homeserver_reconciliation_v246_mark_required')){
            $reconciliation=homeserver_reconciliation_v246_mark_required(
              $userId,(string)($material['error']?:$state)
            );
        }elseif($state==='connected'&&function_exists('homeserver_reconciliation_v246_mark_connected')){
            $reconciliation=homeserver_reconciliation_v246_mark_connected($userId);
        }

        $eventType=$state==='connected'
          ?($reconnected?'homeserver.reconnected':'homeserver.connected')
          :(in_array($state,['connection_error','disconnected'],true)?'homeserver.disconnected':'homeserver.status_changed');
        $detail=$state==='connected'
          ?($reconnected
              ?'HomeServer reconnected to VP3 Cloud. Continuity reconciliation is running before local context is trusted again.'
              :'HomeServer is connected to VP3 Cloud. Initial continuity reconciliation is pending.')
          :(in_array($state,['connection_error','disconnected'],true)
              ?'HomeServer is '.$state.'. Local Agent Brain data and capabilities may be temporarily unavailable.'
              :'HomeServer status changed to '.$state.'.');
        $pdo->prepare('INSERT INTO homeserver_agent_events(user_id,event_type,connection_state,detail,metadata_json) VALUES (?,?,?,?,?)')
          ->execute([
            $userId,$eventType,$state,homeserver_shared_v210_text($detail,500),
            json_encode([
              'previous_state'=>$previous,'status'=>$material,
              'reconciliation_required'=>!empty($reconciliation['needs_reconciliation']),
            ],JSON_UNESCAPED_SLASHES)
          ]);
        $eventId=(int)$pdo->lastInsertId();
        $roundTripReset=in_array($state,['connection_error','disconnected'],true)?0:null;
        if($roundTripReset===0){
            $pdo->prepare("INSERT INTO homeserver_agent_state(user_id,connection_state,status_fingerprint,last_event_at,last_roundtrip_ok)
              VALUES (?,?,?,UTC_TIMESTAMP(),0)
              ON DUPLICATE KEY UPDATE connection_state=VALUES(connection_state),status_fingerprint=VALUES(status_fingerprint),
                last_event_at=UTC_TIMESTAMP(),last_roundtrip_ok=0")
              ->execute([$userId,$state,$fingerprint]);
        }else{
            $pdo->prepare("INSERT INTO homeserver_agent_state(user_id,connection_state,status_fingerprint,last_event_at)
              VALUES (?,?,?,UTC_TIMESTAMP())
              ON DUPLICATE KEY UPDATE connection_state=VALUES(connection_state),status_fingerprint=VALUES(status_fingerprint),last_event_at=UTC_TIMESTAMP()")
              ->execute([$userId,$state,$fingerprint]);
        }

        if(function_exists('vp3_cognitive_homeserver_event_v2390')){
            vp3_cognitive_homeserver_event_v2390($pdo,$userId,$eventType,$state,[
              'previous_state'=>$previous,
              'priority'=>in_array($state,['connection_error','disconnected'],true)?'critical':($reconnected?'watch':'normal'),
              'reconciliation_required'=>!empty($reconciliation['needs_reconciliation']),
            ]);
        }
        if(function_exists('create_notification')){
            if($state==='connected'){
                create_notification(
                  $userId,'homeserver_connection_update',
                  $reconnected?'HomeServer reconnected — reconciling':'HomeServer connected — synchronizing',
                  $reconnected
                    ?'HomeServer is back online. I am reconciling Agent Brain context and HomeServer data before treating local context as current.'
                    :'HomeServer is connected. I am completing the initial continuity reconciliation before treating local context as current.',
                  url('/settings-homeserver.php'),'homeserver_agent_event',$eventId
                );
            }elseif(in_array($state,['connection_error','disconnected'],true)){
                create_notification(
                  $userId,'homeserver_needs_attention','HomeServer needs attention',
                  'HomeServer is not connected. I can continue with Cloud capabilities, but local data, models and HomeServer tools are temporarily unavailable. I will reconcile continuity automatically when HomeServer reconnects.',
                  url('/settings-homeserver.php'),'homeserver_agent_event',$eventId
                );
            }
        }
        if($state==='connected'&&!empty($reconciliation['needs_reconciliation'])){
            try{
                homeserver_shared_v210_reconcile_full($userId);
                if(function_exists('homeserver_reconciliation_v246_state')){
                    $reconciliation=homeserver_reconciliation_v246_state($userId);
                }
            }catch(Throwable $reconcileError){
                if(function_exists('homeserver_reconciliation_v246_mark_required')){
                    $reconciliation=homeserver_reconciliation_v246_mark_required(
                      $userId,$reconcileError->getMessage()
                    );
                }
                if(function_exists('vp3_cognitive_homeserver_event_v2390')){
                    vp3_cognitive_homeserver_event_v2390(
                      $pdo,$userId,'homeserver.reconciliation_failed','connection_error',
                      ['priority'=>'critical','failure_class'=>'reconciliation']
                    );
                }
                if(function_exists('create_notification')){
                    create_notification(
                      $userId,'homeserver_needs_attention',
                      'HomeServer reconciliation needs attention',
                      'HomeServer is connected, but continuity reconciliation did not finish. I will keep retrying automatically; HomeServer-backed context remains marked stale until reconciliation succeeds.',
                      url('/settings-homeserver.php'),'homeserver_reconciliation_failure',$eventId
                    );
                }
            }
        }

    }

    if(function_exists('homeserver_reconciliation_v246_state')){
        $reconciliation=homeserver_reconciliation_v246_state($userId);
    }
    if(
        $state==='connected'
        &&!empty($reconciliation['needs_reconciliation'])
        &&function_exists('homeserver_reconciliation_v246_should_retry')
        &&homeserver_reconciliation_v246_should_retry($userId,30)
    ){
        try{
            homeserver_shared_v210_reconcile_full($userId);
            $reconciliation=homeserver_reconciliation_v246_state($userId);
        }catch(Throwable $ignored){
            $reconciliation=homeserver_reconciliation_v246_state($userId);
        }
    }
    $reconciling=$state==='connected'&&!empty($reconciliation['needs_reconciliation']);
    $reconcileFailed=$reconciling&&!empty($reconciliation['last_error']);
    $status['agent_brain_status']=[
      'known'=>true,
      'priority'=>in_array($state,['connection_error','disconnected'],true)||$reconcileFailed
        ?'critical':($reconciling?'watch':($state==='connected'?'normal':'watch')),
      'state'=>$reconciling?'reconciling':$state,
      'connection_state'=>$state,
      'reconciliation_required'=>!empty($reconciliation['needs_reconciliation']),
    ];
    $status['reconciliation']=$reconciliation;
    $status['diagnostics']=homeserver_shared_v210_diagnostics($userId);
    $status['shared_agent_fabric']=[
      'version'=>VP3_HOMESERVER_SHARED_AGENT_VERSION,
      'datasets'=>['memory','knowledge','contacts','tasks','calendar','files','notifications'],
      'mode'=>'federated',
      'reconciliation_contract'=>'v246',
      'filtered_snapshots_never_delete'=>true,
      'full_snapshot_reconcile_on_reconnect'=>true,
    ];
    return $status;
}
