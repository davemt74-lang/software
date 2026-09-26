<?php
declare(strict_types=1);

/**
 * Tracky V2.7 Cloud Foundation.
 *
 * Cloud stores only governed physical meaning. Raw perception remains local to
 * OTRO/HomeServer. This module owns the physical_context.v1 cloud projection,
 * idempotent event ingestion, capability negotiation, site state and compact
 * Agent-facing context. It does not create a second Agent Brain or device runtime.
 */
const VP3_TRACKY_CLOUD_V270='vp3-tracky-cloud-v270-20260926';
const VP3_TRACKY_PROTOCOL_V270='physical_context.v1';
const VP3_TRACKY_MAX_EVENTS_V270=100;
const VP3_TRACKY_MAX_RELATIONS_V270=250;
const VP3_TRACKY_MAX_PAYLOAD_BYTES_V270=262144;
const VP3_TRACKY_FRESH_EVENT_SECONDS_V270=300;

function tracky_cloud_v270_schema_ready(?PDO $pdo=null): bool
{
    $pdo??=db();
    if(!$pdo)return false;
    foreach(['tracky_cloud_sites','tracky_cloud_events','tracky_cloud_world_state','tracky_cloud_context'] as $table){
        if(!table_exists($table))return false;
    }
    return true;
}

function tracky_cloud_v270_ensure_schema(?PDO $pdo=null): void
{
    $pdo??=db();
    if(!$pdo)throw new RuntimeException('Database connection is unavailable.');

    $pdo->exec("CREATE TABLE IF NOT EXISTS tracky_cloud_sites (
      user_id INT UNSIGNED NOT NULL,
      site_id VARCHAR(100) NOT NULL,
      device_id VARCHAR(100) NOT NULL DEFAULT '',
      label VARCHAR(160) NOT NULL DEFAULT '',
      protocol_version VARCHAR(40) NOT NULL DEFAULT 'physical_context.v1',
      status VARCHAR(30) NOT NULL DEFAULT 'unknown',
      capabilities_json LONGTEXT NULL,
      health_json LONGTEXT NULL,
      last_sequence BIGINT UNSIGNED NOT NULL DEFAULT 0,
      sync_cursor VARCHAR(190) NOT NULL DEFAULT '',
      last_event_at DATETIME NULL,
      last_seen_at DATETIME NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      PRIMARY KEY (user_id,site_id),
      INDEX idx_tracky_sites_device (user_id,device_id),
      INDEX idx_tracky_sites_status (status,last_seen_at,user_id),
      CONSTRAINT fk_tracky_site_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS tracky_cloud_events (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
      user_id INT UNSIGNED NOT NULL,
      site_id VARCHAR(100) NOT NULL,
      event_id VARCHAR(128) NOT NULL,
      sequence_no BIGINT UNSIGNED NOT NULL,
      event_type VARCHAR(100) NOT NULL,
      severity VARCHAR(30) NOT NULL DEFAULT 'informational',
      confidence DECIMAL(6,5) NOT NULL DEFAULT 0,
      privacy_class VARCHAR(40) NOT NULL,
      occurred_at DATETIME NOT NULL,
      received_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      is_fresh TINYINT(1) NOT NULL DEFAULT 0,
      projected TINYINT(1) NOT NULL DEFAULT 0,
      event_json LONGTEXT NOT NULL,
      UNIQUE KEY uq_tracky_event (user_id,site_id,event_id),
      INDEX idx_tracky_event_sequence (user_id,site_id,sequence_no,id),
      INDEX idx_tracky_event_recent (user_id,occurred_at,id),
      INDEX idx_tracky_event_type (event_type,occurred_at,id),
      CONSTRAINT fk_tracky_event_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS tracky_cloud_world_state (
      user_id INT UNSIGNED NOT NULL,
      site_id VARCHAR(100) NOT NULL,
      relation_key CHAR(64) NOT NULL,
      subject_id VARCHAR(128) NOT NULL,
      predicate VARCHAR(80) NOT NULL,
      object_id VARCHAR(128) NOT NULL DEFAULT '',
      value_json LONGTEXT NULL,
      confidence DECIMAL(6,5) NOT NULL DEFAULT 0,
      temporal_state VARCHAR(30) NOT NULL DEFAULT 'current',
      source_event_id VARCHAR(128) NOT NULL DEFAULT '',
      sequence_no BIGINT UNSIGNED NOT NULL DEFAULT 0,
      as_of DATETIME NOT NULL,
      updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      PRIMARY KEY (user_id,site_id,relation_key),
      INDEX idx_tracky_world_subject (user_id,site_id,subject_id,predicate),
      INDEX idx_tracky_world_predicate (user_id,site_id,predicate,as_of),
      CONSTRAINT fk_tracky_world_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS tracky_cloud_context (
      user_id INT UNSIGNED NOT NULL,
      site_id VARCHAR(100) NOT NULL,
      sequence_no BIGINT UNSIGNED NOT NULL DEFAULT 0,
      context_json LONGTEXT NOT NULL,
      fingerprint CHAR(64) NOT NULL,
      observed_at DATETIME NULL,
      updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      PRIMARY KEY (user_id,site_id),
      INDEX idx_tracky_context_updated (user_id,updated_at),
      CONSTRAINT fk_tracky_context_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

function tracky_cloud_v270_plugin_enabled(PDO $pdo,array $user): bool
{
    return function_exists('vp3_plugin_effective_enabled_v360')
        && vp3_plugin_effective_enabled_v360($pdo,$user,'tracky');
}

function tracky_cloud_v270_site_id(string $value): string
{
    $value=trim($value);
    if(!preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{2,99}$/',$value))throw new RuntimeException('Tracky site identity is invalid.');
    return $value;
}

function tracky_cloud_v270_event_id(string $value): string
{
    $value=trim($value);
    if(!preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{7,127}$/',$value))throw new RuntimeException('Tracky event identity is invalid.');
    return $value;
}

function tracky_cloud_v270_forbidden_key(string $key): bool
{
    $key=strtolower(trim($key));
    if($key==='')return false;
    return (bool)preg_match('/(?:^|_)(?:raw|frame|frames|image|images|video|videos|audio|recording|recordings|embedding|embeddings|face_embedding|transcript|filesystem_path|file_path|source_uri|camera_uri)(?:$|_)/',$key);
}

function tracky_cloud_v270_assert_governed_value(mixed $value,string $path='payload',int $depth=0): void
{
    if($depth>8)throw new RuntimeException('Tracky payload nesting is too deep.');
    if(is_array($value)){
        foreach($value as $key=>$child){
            $name=is_string($key)?$key:(string)$key;
            if(tracky_cloud_v270_forbidden_key($name))throw new RuntimeException('Tracky cloud payload contains local-only perception data at '.$path.'.'.$name.'.');
            tracky_cloud_v270_assert_governed_value($child,$path.'.'.$name,$depth+1);
        }
        return;
    }
    if(is_object($value))throw new RuntimeException('Tracky payload objects must be normalized before synchronization.');
    if(is_string($value)&&strlen($value)>20000)throw new RuntimeException('Tracky cloud payload contains an oversized value.');
}

function tracky_cloud_v270_json(array $value): string
{
    tracky_cloud_v270_assert_governed_value($value);
    $json=json_encode($value,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
    if(!is_string($json))throw new RuntimeException('Tracky cloud payload could not be encoded.');
    return $json;
}

function tracky_cloud_v270_time(string $value): DateTimeImmutable
{
    $value=trim($value);
    if($value==='')throw new RuntimeException('Tracky timestamp is required.');
    try{$dt=new DateTimeImmutable($value);}catch(Throwable $e){throw new RuntimeException('Tracky timestamp is invalid.');}
    return $dt->setTimezone(new DateTimeZone('UTC'));
}

function tracky_cloud_v270_privacy_class(string $value): string
{
    $value=strtolower(trim($value));
    if(!in_array($value,['cloud_derived','system_health','user_approved'],true)){
        throw new RuntimeException('Tracky cloud payload is not eligible for cloud synchronization.');
    }
    return $value;
}

function tracky_cloud_v270_severity(string $value): string
{
    $value=strtolower(trim($value));
    return in_array($value,['informational','notable','actionable','urgent','system-health'],true)?$value:'informational';
}

function tracky_cloud_v270_confidence(mixed $value): float
{
    if(!is_numeric($value))return 0.0;
    return max(0.0,min(1.0,(float)$value));
}

function tracky_cloud_v270_event_type(string $value): string
{
    $value=strtolower(trim($value));
    if(!preg_match('/^(room|person|object|environment|routine|gesture|sensor|camera|tracky|physical_context|safety)\.[a-z0-9_]{2,70}$/',$value)){
        throw new RuntimeException('Tracky event type is unsupported.');
    }
    return $value;
}

function tracky_cloud_v270_context(array $input): array
{
    tracky_cloud_v270_assert_governed_value($input,'context');
    $out=[
        'current_room'=>mb_strimwidth(trim((string)($input['current_room']??'')),0,160,''),
        'environment_status'=>mb_strimwidth(trim((string)($input['environment_status']??'')),0,120,''),
        'confidence'=>tracky_cloud_v270_confidence($input['confidence']??0),
        'people_present'=>[],
        'recent_changes'=>[],
        'exceptions'=>[],
    ];
    foreach(array_slice(is_array($input['people_present']??null)?$input['people_present']:[],0,30) as $item){
        if(!is_scalar($item))continue;
        $v=mb_strimwidth(trim((string)$item),0,160,'');if($v!=='')$out['people_present'][]=$v;
    }
    foreach(['recent_changes'=>20,'exceptions'=>20] as $key=>$limit){
        foreach(array_slice(is_array($input[$key]??null)?$input[$key]:[],0,$limit) as $item){
            if(!is_scalar($item))continue;
            $v=mb_strimwidth(trim((string)$item),0,300,'');if($v!=='')$out[$key][]=$v;
        }
    }
    return $out;
}

function tracky_cloud_v270_relation(array $input): array
{
    tracky_cloud_v270_assert_governed_value($input,'world_state');
    $subject=mb_strimwidth(trim((string)($input['subject_id']??'')),0,128,'');
    $predicate=strtolower(trim((string)($input['predicate']??'')));
    $object=mb_strimwidth(trim((string)($input['object_id']??'')),0,128,'');
    if($subject===''||!preg_match('/^[a-z0-9_.:-]{2,80}$/',$predicate))throw new RuntimeException('Tracky world-state relation is invalid.');
    $temporal=strtolower(trim((string)($input['temporal_state']??'current')));
    if(!in_array($temporal,['current','last_seen','historical','inferred','predicted','unknown'],true))$temporal='unknown';
    $value=is_array($input['value']??null)?$input['value']:[];
    return [
        'subject_id'=>$subject,
        'predicate'=>$predicate,
        'object_id'=>$object,
        'value'=>$value,
        'confidence'=>tracky_cloud_v270_confidence($input['confidence']??0),
        'temporal_state'=>$temporal,
        'source_event_id'=>mb_strimwidth(trim((string)($input['source_event_id']??'')),0,128,''),
        'sequence'=>max(0,(int)($input['sequence']??0)),
        'as_of'=>tracky_cloud_v270_time((string)($input['as_of']??gmdate(DATE_ATOM)))->format('Y-m-d H:i:s'),
    ];
}

function tracky_cloud_v270_site_status(PDO $pdo,int $userId,string $siteId): ?array
{
    if($userId<1||!tracky_cloud_v270_schema_ready($pdo))return null;
    $q=$pdo->prepare('SELECT * FROM tracky_cloud_sites WHERE user_id=? AND site_id=? LIMIT 1');
    $q->execute([$userId,$siteId]);$row=$q->fetch();
    if(!$row)return null;
    foreach(['capabilities_json'=>'capabilities','health_json'=>'health'] as $jsonKey=>$outKey){
        $decoded=json_decode((string)($row[$jsonKey]??''),true);$row[$outKey]=is_array($decoded)?$decoded:[];
    }
    return $row;
}

function tracky_cloud_v270_sites(PDO $pdo,int $userId): array
{
    if($userId<1||!tracky_cloud_v270_schema_ready($pdo))return [];
    $q=$pdo->prepare('SELECT user_id,site_id,device_id,label,protocol_version,status,last_sequence,sync_cursor,last_event_at,last_seen_at,capabilities_json,health_json FROM tracky_cloud_sites WHERE user_id=? ORDER BY label,site_id');
    $q->execute([$userId]);$rows=$q->fetchAll()?:[];
    foreach($rows as &$row){
        $caps=json_decode((string)($row['capabilities_json']??''),true);$row['capabilities']=is_array($caps)?$caps:[];
        $health=json_decode((string)($row['health_json']??''),true);$row['health']=is_array($health)?$health:[];
        unset($row['capabilities_json'],$row['health_json']);
    }
    unset($row);
    return $rows;
}

function tracky_cloud_v270_current_context(PDO $pdo,int $userId,?string $siteId=null): array
{
    if($userId<1||!tracky_cloud_v270_schema_ready($pdo))return [];
    if($siteId!==null&&$siteId!==''){
        $siteId=tracky_cloud_v270_site_id($siteId);
        $q=$pdo->prepare('SELECT site_id,sequence_no,context_json,observed_at,updated_at FROM tracky_cloud_context WHERE user_id=? AND site_id=? LIMIT 1');
        $q->execute([$userId,$siteId]);
    }else{
        $q=$pdo->prepare('SELECT site_id,sequence_no,context_json,observed_at,updated_at FROM tracky_cloud_context WHERE user_id=? ORDER BY updated_at DESC LIMIT 1');
        $q->execute([$userId]);
    }
    $row=$q->fetch();if(!$row)return [];
    $context=json_decode((string)$row['context_json'],true);if(!is_array($context))$context=[];
    return ['site_id'=>(string)$row['site_id'],'sequence'=>(int)$row['sequence_no'],'observed_at'=>$row['observed_at'],'updated_at'=>$row['updated_at'],'context'=>$context];
}

function tracky_cloud_v270_recent_events(PDO $pdo,int $userId,?string $siteId=null,int $limit=30): array
{
    if($userId<1||!tracky_cloud_v270_schema_ready($pdo))return [];
    $limit=max(1,min(100,$limit));
    if($siteId!==null&&$siteId!==''){
        $siteId=tracky_cloud_v270_site_id($siteId);
        $q=$pdo->prepare("SELECT site_id,event_id,sequence_no,event_type,severity,confidence,privacy_class,occurred_at,is_fresh,projected,event_json FROM tracky_cloud_events WHERE user_id=? AND site_id=? ORDER BY sequence_no DESC,id DESC LIMIT {$limit}");
        $q->execute([$userId,$siteId]);
    }else{
        $q=$pdo->prepare("SELECT site_id,event_id,sequence_no,event_type,severity,confidence,privacy_class,occurred_at,is_fresh,projected,event_json FROM tracky_cloud_events WHERE user_id=? ORDER BY occurred_at DESC,id DESC LIMIT {$limit}");
        $q->execute([$userId]);
    }
    $rows=$q->fetchAll()?:[];
    foreach($rows as &$row){$event=json_decode((string)$row['event_json'],true);$row['event']=is_array($event)?$event:[];unset($row['event_json']);}
    unset($row);return $rows;
}

function tracky_cloud_v270_world_state(PDO $pdo,int $userId,?string $siteId=null,int $limit=200): array
{
    if($userId<1||!tracky_cloud_v270_schema_ready($pdo))return [];
    $limit=max(1,min(500,$limit));
    if($siteId!==null&&$siteId!==''){
        $siteId=tracky_cloud_v270_site_id($siteId);
        $q=$pdo->prepare("SELECT site_id,subject_id,predicate,object_id,value_json,confidence,temporal_state,source_event_id,sequence_no,as_of FROM tracky_cloud_world_state WHERE user_id=? AND site_id=? ORDER BY as_of DESC LIMIT {$limit}");
        $q->execute([$userId,$siteId]);
    }else{
        $q=$pdo->prepare("SELECT site_id,subject_id,predicate,object_id,value_json,confidence,temporal_state,source_event_id,sequence_no,as_of FROM tracky_cloud_world_state WHERE user_id=? ORDER BY as_of DESC LIMIT {$limit}");
        $q->execute([$userId]);
    }
    $rows=$q->fetchAll()?:[];
    foreach($rows as &$row){$value=json_decode((string)($row['value_json']??''),true);$row['value']=is_array($value)?$value:[];unset($row['value_json']);}
    unset($row);return $rows;
}

function tracky_cloud_v270_ingest(PDO $pdo,int $userId,string $deviceId,array $payload): array
{
    if($userId<1)throw new RuntimeException('Tracky cloud synchronization requires an authenticated account.');
    $encoded=tracky_cloud_v270_json($payload);
    if(strlen($encoded)>VP3_TRACKY_MAX_PAYLOAD_BYTES_V270)throw new RuntimeException('Tracky cloud payload is too large.');
    $protocol=trim((string)($payload['protocol']??''));
    if($protocol!==VP3_TRACKY_PROTOCOL_V270)throw new RuntimeException('Tracky physical-context protocol is unsupported.');

    $site=is_array($payload['site']??null)?$payload['site']:[];
    $siteId=tracky_cloud_v270_site_id((string)($site['id']??''));
    $label=mb_strimwidth(trim((string)($site['label']??$siteId)),0,160,'');
    $status=strtolower(trim((string)($payload['status']??'healthy')));
    if(!in_array($status,['healthy','degraded','offline','disabled','recovering','failed','unknown'],true))$status='unknown';
    $capabilities=is_array($payload['capabilities']??null)?$payload['capabilities']:[];
    $health=is_array($payload['health']??null)?$payload['health']:[];
    tracky_cloud_v270_assert_governed_value($capabilities,'capabilities');
    tracky_cloud_v270_assert_governed_value($health,'health');
    $capsJson=tracky_cloud_v270_json($capabilities);
    $healthJson=tracky_cloud_v270_json($health);
    $cursor=mb_strimwidth(trim((string)($payload['cursor']??'')),0,190,'');

    $events=is_array($payload['events']??null)?array_values($payload['events']):[];
    $relations=is_array($payload['world_state']??null)?array_values($payload['world_state']):[];
    if(count($events)>VP3_TRACKY_MAX_EVENTS_V270)throw new RuntimeException('Tracky event batch exceeds the cloud synchronization limit.');
    if(count($relations)>VP3_TRACKY_MAX_RELATIONS_V270)throw new RuntimeException('Tracky world-state batch exceeds the cloud synchronization limit.');

    tracky_cloud_v270_ensure_schema($pdo);
    $existing=tracky_cloud_v270_site_status($pdo,$userId,$siteId);
    $lastSequence=(int)($existing['last_sequence']??0);
    $maxSequence=$lastSequence;
    $inserted=0;$duplicates=0;$projected=0;$fresh=0;
    $latestEventAt=null;

    $pdo->beginTransaction();
    try{
        $siteStmt=$pdo->prepare("INSERT INTO tracky_cloud_sites
          (user_id,site_id,device_id,label,protocol_version,status,capabilities_json,health_json,last_sequence,sync_cursor,last_seen_at)
          VALUES (?,?,?,?,?,?,?,?,?,?,UTC_TIMESTAMP())
          ON DUPLICATE KEY UPDATE device_id=VALUES(device_id),label=VALUES(label),protocol_version=VALUES(protocol_version),
            status=VALUES(status),capabilities_json=VALUES(capabilities_json),health_json=VALUES(health_json),
            sync_cursor=VALUES(sync_cursor),last_seen_at=UTC_TIMESTAMP()");
        $siteStmt->execute([$userId,$siteId,mb_strimwidth(trim($deviceId),0,100,''),$label,$protocol,$status,$capsJson,$healthJson,$lastSequence,$cursor]);

        foreach($events as $raw){
            if(!is_array($raw))throw new RuntimeException('Tracky event batch contains an invalid event.');
            tracky_cloud_v270_assert_governed_value($raw,'events');
            $eventId=tracky_cloud_v270_event_id((string)($raw['event_id']??''));
            $sequence=max(0,(int)($raw['sequence']??0));
            if($sequence<1)throw new RuntimeException('Tracky event sequence is required.');
            $type=tracky_cloud_v270_event_type((string)($raw['event_type']??''));
            $privacy=tracky_cloud_v270_privacy_class((string)($raw['privacy_class']??''));
            $severity=tracky_cloud_v270_severity((string)($raw['severity']??'informational'));
            $confidence=tracky_cloud_v270_confidence($raw['confidence']??0);
            $occurred=tracky_cloud_v270_time((string)($raw['occurred_at']??''));
            $occurredSql=$occurred->format('Y-m-d H:i:s');
            $age=max(0,time()-$occurred->getTimestamp());
            $isFresh=$age<=VP3_TRACKY_FRESH_EVENT_SECONDS_V270?1:0;
            $canProject=$sequence>$lastSequence?1:0;
            $json=tracky_cloud_v270_json($raw);
            $stmt=$pdo->prepare("INSERT IGNORE INTO tracky_cloud_events
              (user_id,site_id,event_id,sequence_no,event_type,severity,confidence,privacy_class,occurred_at,is_fresh,projected,event_json)
              VALUES (?,?,?,?,?,?,?,?,?,?,?,?)");
            $stmt->execute([$userId,$siteId,$eventId,$sequence,$type,$severity,$confidence,$privacy,$occurredSql,$isFresh,$canProject,$json]);
            if($stmt->rowCount()===1){$inserted++;if($isFresh)$fresh++;if($canProject)$projected++;}else{$duplicates++;}
            $maxSequence=max($maxSequence,$sequence);
            if($latestEventAt===null||$occurredSql>$latestEventAt)$latestEventAt=$occurredSql;
        }

        foreach($relations as $rawRelation){
            if(!is_array($rawRelation))throw new RuntimeException('Tracky world-state batch contains an invalid relation.');
            $rel=tracky_cloud_v270_relation($rawRelation);
            if($rel['sequence']<$lastSequence)continue;
            $key=hash('sha256',$rel['subject_id']."\0".$rel['predicate']."\0".$rel['object_id']);
            $valueJson=tracky_cloud_v270_json($rel['value']);
            $stmt=$pdo->prepare("INSERT INTO tracky_cloud_world_state
              (user_id,site_id,relation_key,subject_id,predicate,object_id,value_json,confidence,temporal_state,source_event_id,sequence_no,as_of)
              VALUES (?,?,?,?,?,?,?,?,?,?,?,?)
              ON DUPLICATE KEY UPDATE
                object_id=IF(VALUES(sequence_no)>=sequence_no,VALUES(object_id),object_id),
                value_json=IF(VALUES(sequence_no)>=sequence_no,VALUES(value_json),value_json),
                confidence=IF(VALUES(sequence_no)>=sequence_no,VALUES(confidence),confidence),
                temporal_state=IF(VALUES(sequence_no)>=sequence_no,VALUES(temporal_state),temporal_state),
                source_event_id=IF(VALUES(sequence_no)>=sequence_no,VALUES(source_event_id),source_event_id),
                as_of=IF(VALUES(sequence_no)>=sequence_no,VALUES(as_of),as_of),
                sequence_no=GREATEST(sequence_no,VALUES(sequence_no))");
            $stmt->execute([$userId,$siteId,$key,$rel['subject_id'],$rel['predicate'],$rel['object_id'],$valueJson,$rel['confidence'],$rel['temporal_state'],$rel['source_event_id'],$rel['sequence'],$rel['as_of']]);
            $maxSequence=max($maxSequence,(int)$rel['sequence']);
        }

        if(is_array($payload['context']??null)){
            $context=tracky_cloud_v270_context($payload['context']);
            $contextSequence=max(0,(int)($payload['context_sequence']??$maxSequence));
            if($contextSequence>=$lastSequence){
                $contextJson=tracky_cloud_v270_json($context);
                $fingerprint=hash('sha256',$contextJson);
                $observed=(string)($payload['context_observed_at']??'');
                $observedSql=$observed!==''?tracky_cloud_v270_time($observed)->format('Y-m-d H:i:s'):null;
                $stmt=$pdo->prepare("INSERT INTO tracky_cloud_context(user_id,site_id,sequence_no,context_json,fingerprint,observed_at)
                  VALUES (?,?,?,?,?,?)
                  ON DUPLICATE KEY UPDATE
                    context_json=IF(VALUES(sequence_no)>=sequence_no,VALUES(context_json),context_json),
                    fingerprint=IF(VALUES(sequence_no)>=sequence_no,VALUES(fingerprint),fingerprint),
                    observed_at=IF(VALUES(sequence_no)>=sequence_no,VALUES(observed_at),observed_at),
                    sequence_no=GREATEST(sequence_no,VALUES(sequence_no))");
                $stmt->execute([$userId,$siteId,$contextSequence,$contextJson,$fingerprint,$observedSql]);
                $maxSequence=max($maxSequence,$contextSequence);
            }
        }

        $update=$pdo->prepare("UPDATE tracky_cloud_sites SET last_sequence=?,sync_cursor=?,last_event_at=COALESCE(?,last_event_at),last_seen_at=UTC_TIMESTAMP() WHERE user_id=? AND site_id=?");
        $update->execute([$maxSequence,$cursor,$latestEventAt,$userId,$siteId]);
        $pdo->commit();
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}

    return [
        'ok'=>true,
        'protocol'=>VP3_TRACKY_PROTOCOL_V270,
        'site_id'=>$siteId,
        'accepted_events'=>$inserted,
        'duplicate_events'=>$duplicates,
        'projected_events'=>$projected,
        'fresh_events'=>$fresh,
        'last_sequence'=>$maxSequence,
        'cursor'=>$cursor,
        'cloud_time'=>gmdate(DATE_ATOM),
    ];
}

function tracky_cloud_v270_simulator_payload(int $sequence=1,string $siteId='sim-home'): array
{
    $sequence=max(1,$sequence);
    $eventId='sim-event-'.str_pad((string)$sequence,8,'0',STR_PAD_LEFT);
    $now=gmdate(DATE_ATOM);
    return [
        'protocol'=>VP3_TRACKY_PROTOCOL_V270,
        'site'=>['id'=>$siteId,'label'=>'Tracky Simulator'],
        'status'=>'healthy',
        'cursor'=>'sim-'.$sequence,
        'capabilities'=>[
            'camera_count'=>1,
            'scene_graph'=>true,
            'active_perception'=>false,
            'recognition'=>false,
            'protocol'=>VP3_TRACKY_PROTOCOL_V270,
        ],
        'health'=>['runtime'=>'healthy','camera'=>'healthy','world_state'=>'fresh'],
        'events'=>[[
            'event_id'=>$eventId,
            'sequence'=>$sequence,
            'event_type'=>'room.entered',
            'severity'=>'notable',
            'confidence'=>0.98,
            'privacy_class'=>'cloud_derived',
            'occurred_at'=>$now,
            'subject'=>['entity_id'=>'person:dave','type'=>'person'],
            'room_id'=>'room:office',
        ]],
        'world_state'=>[[
            'subject_id'=>'person:dave',
            'predicate'=>'located_in',
            'object_id'=>'room:office',
            'confidence'=>0.98,
            'temporal_state'=>'current',
            'source_event_id'=>$eventId,
            'sequence'=>$sequence,
            'as_of'=>$now,
        ]],
        'context'=>[
            'current_room'=>'Office',
            'people_present'=>['Dave'],
            'recent_changes'=>['Dave entered Office'],
            'environment_status'=>'normal',
            'confidence'=>0.98,
            'exceptions'=>[],
        ],
        'context_sequence'=>$sequence,
        'context_observed_at'=>$now,
    ];
}
