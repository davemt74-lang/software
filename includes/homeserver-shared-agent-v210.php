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
const VP3_HOMESERVER_SHARED_AGENT_VERSION='2.1';

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
    return [
      'key'=>$dataset.':'.homeserver_shared_v210_text((string)$id,120),
      'title'=>homeserver_shared_v210_text($title,240),
      'content'=>homeserver_shared_v210_text($content,2200),
      'updated_at'=>$updatedAt?:null,
      'authoritative_source'=>'vp3_cloud',
      'dataset'=>$dataset,
    ];
}

function homeserver_shared_v210_fit_datasets(array $datasets,int $maxBytes=170000): array
{
    $order=['notifications','tasks','contacts','knowledge','memory'];
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
    $datasets=['memory'=>[],'knowledge'=>[],'contacts'=>[],'tasks'=>[],'notifications'=>[]];

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

        $s=$pdo->prepare("SELECT id,memory_type,subject,memory_text,last_seen_at,metadata_json
          FROM agent_memory_items WHERE user_id=? AND is_active=1 AND memory_type IN ('task','commitment')
          ORDER BY last_seen_at DESC,id DESC LIMIT 60");
        $s->execute([$userId]);
        foreach($s->fetchAll()?:[] as $row){
            $meta=json_decode((string)($row['metadata_json']??''),true);if(!is_array($meta))$meta=[];
            $text=trim((string)($row['subject']??'').' '.(string)($row['memory_text']??''));
            if(!homeserver_shared_v210_matches($query,$text))continue;
            $datasets['tasks'][]=homeserver_shared_v210_record(
              'tasks',(int)$row['id'],
              (string)($row['subject']??'Agent task'),
              (string)($row['memory_text']??'').' · status: '.(string)($meta['task_status']??'open').' · due: '.(string)($meta['due_at']??''),
              (string)($row['last_seen_at']??'')
            );
            if(count($datasets['tasks'])>=30)break;
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

    if(function_exists('profile_visitor_contact_list_v243')){
        try{
            foreach(profile_visitor_contact_list_v243($pdo,$userId,80) as $row){
                if(!is_array($row))continue;
                $title=trim((string)($row['visitor_label']??''))?:trim((string)($row['contact_ref']??''))?:'Contact';
                $body=implode(' · ',array_filter([
                  !empty($row['signed_in'])?'signed-in member':'visitor',
                  (string)($row['relationship_scope']??''),
                  'visits '.(int)($row['visit_count']??0),
                  'conversations '.(int)($row['conversation_count']??0),
                ]));
                if(!homeserver_shared_v210_matches($query,$title.' '.$body))continue;
                $datasets['contacts'][]=homeserver_shared_v210_record('contacts',(string)($row['contact_ref']??$row['id']??count($datasets['contacts'])),$title,$body,(string)($row['last_seen_at']??''));
                if(count($datasets['contacts'])>=30)break;
            }
        }catch(Throwable $ignored){}
    }

    if(table_exists('vp3_agent_contacts')){
        try{
            $s=$pdo->prepare("SELECT id,display_name,operator_name,visitor_class,relationship_status,verification_status,
              inferred_intent,risk_score,engagement_score,value_score,last_seen_at
              FROM vp3_agent_contacts WHERE owner_user_id=? ORDER BY last_seen_at DESC,id DESC LIMIT 80");
            $s->execute([$userId]);
            foreach($s->fetchAll()?:[] as $row){
                $title=trim((string)($row['display_name']??''))?:'Automated agent';
                $body=implode(' · ',array_filter([
                  trim((string)($row['operator_name']??'')),
                  (string)($row['visitor_class']??'automated'),
                  'relationship '.(string)($row['relationship_status']??'observed'),
                  'verification '.(string)($row['verification_status']??'unverified'),
                  trim((string)($row['inferred_intent']??'')),
                  'risk '.(int)($row['risk_score']??0),
                  'engagement '.(int)($row['engagement_score']??0),
                  'value '.(int)($row['value_score']??0),
                ]));
                if(!homeserver_shared_v210_matches($query,$title.' '.$body))continue;
                $datasets['contacts'][]=homeserver_shared_v210_record('contacts','agent:'.(int)$row['id'],$title,$body,(string)($row['last_seen_at']??''));
                if(count($datasets['contacts'])>=50)break;
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
      'datasets'=>$datasets,
    ];
}

function homeserver_shared_v210_exchange(int $userId,string $query=''): ?array
{
    static $cache=[];
    $query=homeserver_shared_v210_text($query,240);
    $cacheKey=$userId.'|'.sha1($query);
    if(array_key_exists($cacheKey,$cache))return $cache[$cacheKey];
    if(!function_exists('homeserver_https_v1300_status')||!function_exists('homeserver_https_v1300_remote_operation'))return $cache[$cacheKey]=null;
    $status=homeserver_https_v1300_status($userId);
    if(!$status||empty($status['connected'])||empty($status['paired']))return $cache[$cacheKey]=null;
    try{
        $cloud=homeserver_shared_v210_cloud_snapshot($userId,$query);
        $result=homeserver_https_v1300_remote_operation($userId,'shared.context.exchange',[
          'query'=>$query,'cloud_snapshot'=>$cloud,
        ]);
        $home=is_array($result['homeserver_snapshot']??null)?$result['homeserver_snapshot']:null;
        if(!$home)return $cache[$cacheKey]=null;
        $pdo=db();
        if($pdo&&homeserver_shared_v210_schema_ready()){
            $pdo->prepare("INSERT INTO homeserver_agent_state(user_id,last_sync_at,cloud_revision,homeserver_revision)
              VALUES (?,UTC_TIMESTAMP(),?,?)
              ON DUPLICATE KEY UPDATE last_sync_at=UTC_TIMESTAMP(),cloud_revision=VALUES(cloud_revision),homeserver_revision=VALUES(homeserver_revision)")
              ->execute([$userId,(string)$cloud['revision'],(string)($home['revision']??'')]);
        }
        return $cache[$cacheKey]=$home;
    }catch(Throwable $e){
        error_log('HomeServer v2.1 shared Agent exchange failed: '.$e->getMessage());
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
    foreach(['memory','knowledge','contacts','tasks','notifications'] as $dataset){
        foreach((array)($datasets[$dataset]??[]) as $row){
            if(!is_array($row))continue;
            $text=homeserver_shared_v210_text($row['content']??'',2200);if($text==='')continue;
            $out[]=[
              'dataset'=>$dataset,
              'source'=>'homeserver:'.$dataset.':'.homeserver_shared_v210_text($row['key']??$row['id']??'',120),
              'title'=>homeserver_shared_v210_text($row['title']??ucfirst($dataset),240),
              'text'=>$text,
              'updated_at'=>$row['updated_at']??null,
              'authoritative_source'=>'homeserver',
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
    $s=$pdo->prepare('SELECT connection_state,status_fingerprint FROM homeserver_agent_state WHERE user_id=? LIMIT 1');$s->execute([$userId]);$before=$s->fetch()?:null;
    $changed=!$before||!hash_equals((string)($before['status_fingerprint']??''),$fingerprint);
    if($changed){
        $previous=(string)($before['connection_state']??'not_connected');
        $eventType=$state==='connected'?'homeserver.connected':(in_array($state,['connection_error','disconnected'],true)?'homeserver.disconnected':'homeserver.status_changed');
        $detail=$state==='connected'
          ? 'HomeServer is connected to VP3 Cloud.'
          : (in_array($state,['connection_error','disconnected'],true)
              ? 'HomeServer is '.$state.'. Local Agent Brain data and capabilities may be temporarily unavailable.'
              : 'HomeServer status changed to '.$state.'.');
        $pdo->prepare('INSERT INTO homeserver_agent_events(user_id,event_type,connection_state,detail,metadata_json) VALUES (?,?,?,?,?)')
          ->execute([$userId,$eventType,$state,homeserver_shared_v210_text($detail,500),json_encode(['previous_state'=>$previous,'status'=>$material],JSON_UNESCAPED_SLASHES)]);
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
              'previous_state'=>$previous,'priority'=>in_array($state,['connection_error','disconnected'],true)?'critical':'normal',
            ]);
        }
        if(in_array($state,['connection_error','disconnected'],true)&&function_exists('create_notification')){
            create_notification(
              $userId,'homeserver_needs_attention','HomeServer needs attention',
              'Your HomeServer is not connected. The Agent Brain knows the local datasets and capabilities are unavailable and will resume them automatically when HomeServer reconnects.',
              url('/settings-homeserver.php'),'homeserver_agent_event',$eventId
            );
        }
    }
    $status['agent_brain_status']=[
      'known'=>true,
      'priority'=>in_array($state,['connection_error','disconnected'],true)?'critical':($state==='connected'?'normal':'watch'),
      'state'=>$state,
    ];
    $status['diagnostics']=homeserver_shared_v210_diagnostics($userId);
    $status['shared_agent_fabric']=[
      'version'=>VP3_HOMESERVER_SHARED_AGENT_VERSION,
      'datasets'=>['memory','knowledge','contacts','tasks','notifications'],
      'mode'=>'federated',
    ];
    return $status;
}
