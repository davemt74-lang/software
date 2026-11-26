<?php
declare(strict_types=1);

/** Native listening evidence. This is not a streaming grant or an earnings ledger. */
const MUSIC_LISTENING_V1_PROTOCOL = 'native_listening_v1';
const MUSIC_LISTENING_V1_METRIC = 'legacy_10s_80pct_v1';
const MUSIC_LISTENING_V1_TTL_MS = 86400000;
const MUSIC_LISTENING_V1_MAX_EVENTS = 10000;
const MUSIC_LISTENING_V1_MAX_INTERVAL_MS = 30000;

final class MusicListeningV1Exception extends RuntimeException
{
    public function __construct(public string $publicCode, public int $httpStatus, string $message, public array $details = [])
    { parent::__construct($message, $httpStatus); }
}

function music_listening_v1_error(string $code, int $status, string $message, array $details = []): never
{ throw new MusicListeningV1Exception($code, $status, $message, $details); }

function music_listening_v1_columns(PDO $pdo, string $table): array
{
    if (!preg_match('/^[a-z0-9_]+$/D', $table)) return [];
    try {
        if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
            return array_column($pdo->query('PRAGMA table_info('.$table.')')->fetchAll(PDO::FETCH_ASSOC), 'name');
        }
        $q = $pdo->prepare('SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');
        $q->execute([$table]); return $q->fetchAll(PDO::FETCH_COLUMN);
    } catch (PDOException) { return []; }
}

function music_listening_v1_unique_keys(PDO $pdo, string $table): array
{
    if (!preg_match('/^music_listening_[a-z0-9_]+$/D',$table)) return [];
    $keys=[];
    if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite') {
        $primary=[];
        foreach ($pdo->query('PRAGMA table_info('.$table.')')->fetchAll(PDO::FETCH_ASSOC) as $column) if ((int)$column['pk']>0) $primary[(int)$column['pk']]=$column['name'];
        if ($primary) {ksort($primary);$keys[]=array_values($primary);}
        foreach ($pdo->query('PRAGMA index_list('.$table.')')->fetchAll(PDO::FETCH_ASSOC) as $index) {
            if ((int)$index['unique']!==1 || !empty($index['partial'])) continue;
            $name=(string)$index['name'];
            if (!preg_match('/^[a-zA-Z0-9_]+$/D',$name)) continue;
            $columns=$pdo->query('PRAGMA index_info('.$name.')')->fetchAll(PDO::FETCH_ASSOC);
            $keys[]=array_column($columns,'name');
        }
    } else {
        $q=$pdo->prepare('SELECT INDEX_NAME,COLUMN_NAME,SUB_PART FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND NON_UNIQUE=0 ORDER BY INDEX_NAME,SEQ_IN_INDEX');
        $q->execute([$table]);$indexes=[];$partial=[];
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $name=$row['INDEX_NAME'];$indexes[$name][]=$row['COLUMN_NAME'];
            if ($row['SUB_PART']!==null) $partial[$name]=true;
        }
        foreach ($indexes as $name=>$columns) if (!isset($partial[$name])) $keys[]=$columns;
    }
    return $keys;
}

function music_listening_v1_schema_ready(PDO $pdo): bool
{
    $required = [
        'track_play_sessions'=>['id','session_token','track_id','user_id','listener_hash','device_type','referrer_host','source_context','started_at','last_event_at','ended_at','listened_seconds','last_position_seconds','max_position_seconds','duration_seconds','completion_percent','qualified_play','completed'],
        'track_play_events'=>['id','session_id','event_type','position_seconds','listened_delta_seconds'],
        'music_listening_starts_v1'=>['listener_key','play_id','request_hash','session_id'],
        'music_listening_sessions_v1'=>['session_id','listener_key','play_id','request_hash','requested_track_id','workspace_id','catalog_track_id','recording_id','asset_id','identity_status','asset_binding','duration_ms','duration_source','source_url','source_reason','source_provenance','source_context','listen_class','metric_version','state','created_ms','expires_ms','last_received_ms','segment_started_ms','segment_elapsed_ms','segment_accepted_ms','elapsed_ms','reported_played_ms','accepted_played_ms','position_ms','max_position_ms','playback_rate','last_seq','natural_ended','muted_seen','loop_seen','clipped','start_receipt_json'],
        'music_listening_receipts_v1'=>['session_id','event_id','seq','request_hash','receipt_json'],
    ];
    foreach ($required as $table=>$columns) if (array_diff($columns, music_listening_v1_columns($pdo, $table))) return false;
    foreach (['music_listening_starts_v1'=>[['listener_key','play_id']],
        'music_listening_sessions_v1'=>[['session_id'],['listener_key','play_id']],
        'music_listening_receipts_v1'=>[['session_id','event_id'],['session_id','seq']]] as $table=>$constraints) {
        $keys=music_listening_v1_unique_keys($pdo,$table);
        foreach ($constraints as $columns) if (!in_array($columns,$keys,true)) return false;
    }
    foreach (['music_listening_sessions_v1'=>'track_play_sessions','music_listening_receipts_v1'=>'music_listening_sessions_v1'] as $table=>$parent) {
        $found=false;
        if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite') {
            foreach ($pdo->query('PRAGMA foreign_key_list('.$table.')')->fetchAll(PDO::FETCH_ASSOC) as $key) {
                if ($key['table']===$parent && $key['from']==='session_id' && $key['to']===($parent==='track_play_sessions'?'id':'session_id') && strtoupper($key['on_delete'])==='CASCADE') $found=true;
            }
        } else {
            $q=$pdo->prepare('SELECT k.COLUMN_NAME,k.REFERENCED_COLUMN_NAME,r.DELETE_RULE FROM information_schema.KEY_COLUMN_USAGE k JOIN information_schema.REFERENTIAL_CONSTRAINTS r ON r.CONSTRAINT_SCHEMA=k.CONSTRAINT_SCHEMA AND r.TABLE_NAME=k.TABLE_NAME AND r.CONSTRAINT_NAME=k.CONSTRAINT_NAME WHERE k.CONSTRAINT_SCHEMA=DATABASE() AND k.TABLE_NAME=? AND k.REFERENCED_TABLE_NAME=?');
            $q->execute([$table,$parent]);
            foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $key) if ($key['COLUMN_NAME']==='session_id' && $key['REFERENCED_COLUMN_NAME']===($parent==='track_play_sessions'?'id':'session_id') && $key['DELETE_RULE']==='CASCADE') $found=true;
        }
        if (!$found) return false;
    }
    return true;
}

/** A connection prerequisite, never a schema mutation or a read-path upgrade. */
function music_listening_v1_connection(PDO $pdo): void
{
    if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME)!=='sqlite' || (int)$pdo->query('PRAGMA foreign_keys')->fetchColumn()===1) return;
    if ($pdo->inTransaction()) music_listening_v1_error('schema_unavailable',503,'Listening needs foreign-key enforcement before the transaction starts.');
    $pdo->exec('PRAGMA foreign_keys=ON');
    if ((int)$pdo->query('PRAGMA foreign_keys')->fetchColumn()!==1) music_listening_v1_error('schema_unavailable',503,'Listening storage enforcement is unavailable.');
}

/** Explicit upgrade only. Reads and requests never run DDL. */
function music_listening_v1_install(PDO $pdo): array
{
    if ($pdo->inTransaction()) music_listening_v1_error('upgrade_transaction',409,'Run the listening upgrade outside a transaction.');
    if (!music_listening_v1_columns($pdo,'track_play_sessions') || !music_listening_v1_columns($pdo,'track_play_events')) {
        music_listening_v1_error('schema_unavailable',503,'Install the existing listening tables before this upgrade.');
    }
    $sqlite = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite';
    if ($sqlite) $pdo->exec('PRAGMA foreign_keys=ON');
    $id = $sqlite ? 'INTEGER' : 'BIGINT UNSIGNED';
    $text = $sqlite ? 'TEXT' : 'LONGTEXT';
    $tail = $sqlite ? '' : ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
    // Start keys intentionally remain tombstones after history removal. They contain
    // no raw page/track/account fields or bearer token and cannot reattach to a
    // reused integer session ID.
    $pdo->exec("CREATE TABLE IF NOT EXISTS music_listening_starts_v1 (
        listener_key CHAR(64) NOT NULL, play_id CHAR(36) NOT NULL, request_hash CHAR(64) NOT NULL,
        session_id {$id} NULL, PRIMARY KEY(listener_key,play_id)
    ){$tail}");
    $pdo->exec("CREATE TABLE IF NOT EXISTS music_listening_sessions_v1 (
        session_id {$id} NOT NULL PRIMARY KEY, listener_key CHAR(64) NOT NULL,
        play_id CHAR(36) NOT NULL, request_hash CHAR(64) NOT NULL, requested_track_id BIGINT NOT NULL,
        workspace_id BIGINT NULL, catalog_track_id BIGINT NULL, recording_id CHAR(36) NULL, asset_id CHAR(36) NULL,
        identity_status VARCHAR(40) NOT NULL, asset_binding VARCHAR(64) NOT NULL,
        duration_ms BIGINT NULL, duration_source VARCHAR(40) NOT NULL,
        source_url TEXT NULL, source_reason VARCHAR(40) NOT NULL, source_provenance VARCHAR(48) NOT NULL,
        source_context VARCHAR(30) NOT NULL, listen_class VARCHAR(32) NOT NULL, metric_version VARCHAR(40) NOT NULL,
        state VARCHAR(16) NOT NULL, created_ms BIGINT NOT NULL, expires_ms BIGINT NOT NULL, last_received_ms BIGINT NOT NULL,
        segment_started_ms BIGINT NULL, segment_elapsed_ms BIGINT NOT NULL DEFAULT 0, segment_accepted_ms BIGINT NOT NULL DEFAULT 0,
        elapsed_ms BIGINT NOT NULL DEFAULT 0, reported_played_ms BIGINT NOT NULL DEFAULT 0,
        accepted_played_ms BIGINT NOT NULL DEFAULT 0, position_ms BIGINT NOT NULL DEFAULT 0,
        max_position_ms BIGINT NOT NULL DEFAULT 0, playback_rate DECIMAL(10,5) NOT NULL DEFAULT 1,
        last_seq INTEGER NOT NULL DEFAULT 0, natural_ended INTEGER NOT NULL DEFAULT 0,
        muted_seen INTEGER NOT NULL DEFAULT 0, loop_seen INTEGER NOT NULL DEFAULT 0, clipped INTEGER NOT NULL DEFAULT 0,
        start_receipt_json {$text} NOT NULL,
        UNIQUE(listener_key,play_id),
        FOREIGN KEY(session_id) REFERENCES track_play_sessions(id) ON DELETE CASCADE
    ){$tail}");
    $pdo->exec("CREATE TABLE IF NOT EXISTS music_listening_receipts_v1 (
        session_id {$id} NOT NULL, event_id CHAR(36) NOT NULL, seq INTEGER NOT NULL,
        request_hash CHAR(64) NOT NULL, receipt_json {$text} NOT NULL,
        PRIMARY KEY(session_id,event_id), UNIQUE(session_id,seq),
        FOREIGN KEY(session_id) REFERENCES music_listening_sessions_v1(session_id) ON DELETE CASCADE
    ){$tail}");
    return music_listening_v1_report($pdo);
}

/** Counts and evidence classifications only; no listener or recording identifiers. */
function music_listening_v1_report(PDO $pdo): array
{
    $ready = music_listening_v1_schema_ready($pdo);
    $report = ['ready'=>$ready,'protocol'=>MUSIC_LISTENING_V1_PROTOCOL,'metric_version'=>MUSIC_LISTENING_V1_METRIC,
        'session_ttl_seconds'=>intdiv(MUSIC_LISTENING_V1_TTL_MS,1000),'max_events_per_session'=>MUSIC_LISTENING_V1_MAX_EVENTS,
        'automatic_retention_deletion'=>false,'counts'=>[],'coverage'=>[]];
    if (music_listening_v1_columns($pdo,'track_play_sessions')) $report['counts']['legacy_history_rows']=(int)$pdo->query('SELECT COUNT(*) FROM track_play_sessions')->fetchColumn();
    if (!$ready) return $report;
    foreach (['sessions'=>'music_listening_sessions_v1','receipts'=>'music_listening_receipts_v1','start_keys'=>'music_listening_starts_v1'] as $label=>$table) {
        $report['counts'][$label]=(int)$pdo->query('SELECT COUNT(*) FROM '.$table)->fetchColumn();
    }
    $report['counts']['legacy_unversioned_rows']=max(0,$report['counts']['legacy_history_rows']-$report['counts']['sessions']);
    foreach (['identity_status','duration_source','source_provenance','source_reason','listen_class','state','clipped','muted_seen','loop_seen'] as $field) {
        $report['coverage'][$field]=$pdo->query('SELECT '.$field.' AS classification,COUNT(*) AS rows_count FROM music_listening_sessions_v1 GROUP BY '.$field)->fetchAll(PDO::FETCH_ASSOC);
    }
    return $report;
}

function music_listening_v1_json(mixed $value): string
{
    if (is_array($value)) {
        if (!array_is_list($value)) ksort($value);
        foreach ($value as &$item) if (is_array($item)) $item=json_decode(music_listening_v1_json($item),true,32,JSON_THROW_ON_ERROR);
        unset($item);
    }
    return json_encode($value,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_PRESERVE_ZERO_FRACTION);
}

function music_listening_v1_uuid(mixed $value): string
{
    if (!is_string($value) || !preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[1-8][a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/iD',$value)) {
        music_listening_v1_error('invalid_request',422,'Use a valid playback or event identifier.');
    }
    return strtolower($value);
}

function music_listening_v1_int(mixed $value, int $max, int $min=0): int
{
    if (!is_int($value) || $value<$min || $value>$max) music_listening_v1_error('invalid_request',422,'A playback measurement is outside the supported range.');
    return $value;
}

function music_listening_v1_rate(mixed $value): float
{
    if ((!is_int($value)&&!is_float($value)) || !is_finite((float)$value) || $value<0.25 || $value>4) {
        music_listening_v1_error('invalid_request',422,'Use a supported playback rate.');
    }
    return round((float)$value,5);
}

function music_listening_v1_origin(string $url): ?string
{
    $parts=parse_url($url);
    if (!$parts || !in_array(strtolower((string)($parts['scheme']??'')),['http','https'],true)
        || empty($parts['host']) || isset($parts['user']) || isset($parts['pass'])
        || preg_match('/[\x00-\x20\x7f\\\\]/',$url)) return null;
    $scheme=strtolower($parts['scheme']);$host=strtolower($parts['host']);
    $port=(int)($parts['port']??0);$suffix=$port&&!(($scheme==='https'&&$port===443)||($scheme==='http'&&$port===80))?':'.$port:'';
    return $scheme.'://'.$host.$suffix;
}

/** Public route forms only. Discard secrets in paths as well as query/fragment. */
function music_listening_v1_safe_url(string $candidate, string $origin): array
{
    if ($origin==='') return ['url'=>null,'reason'=>'origin_unconfigured'];
    if ($candidate==='') return ['url'=>null,'reason'=>'unavailable'];
    if (strlen($candidate)>2048 || preg_match('/[\x00-\x20\x7f\\\\]/',$candidate)) return ['url'=>null,'reason'=>'invalid'];
    $base=music_listening_v1_origin($origin);$source=music_listening_v1_origin($candidate);
    if (!$base || !$source) return ['url'=>null,'reason'=>'invalid'];
    if (!hash_equals($base,$source)) return ['url'=>null,'reason'=>'foreign_origin'];
    $path=(string)(parse_url($candidate,PHP_URL_PATH)??'/');
    $basePath=rtrim((string)(parse_url($origin,PHP_URL_PATH)??''),'/');
    if ($basePath!=='' && (!preg_match('#^/(?:[A-Za-z0-9_-]+/)*[A-Za-z0-9_-]+$#D',$basePath) || !str_starts_with($path,$basePath.'/'))) return ['url'=>null,'reason'=>'restricted_path'];
    $route=$basePath===''?$path:substr($path,strlen($basePath));
    $routes=['/','/player.php','/track.php','/album.php','/albums.php','/music-workspace.php','/music-library.php','/chat.php','/profile.php','/admin/artist-music.php'];
    if (!in_array($route,$routes,true)) return ['url'=>null,'reason'=>'restricted_path'];
    // Even numeric query values may identify a private conversation or account.
    return ['url'=>$base.$path,'reason'=>(parse_url($candidate,PHP_URL_QUERY)!==null||parse_url($candidate,PHP_URL_FRAGMENT)!==null)?'details_redacted':'available'];
}

function music_listening_v1_source(array $input, array $context): array
{
    $claimed=music_listening_v1_safe_url((string)($input['source_url']??''),(string)($context['origin']??''));
    $observed=music_listening_v1_safe_url((string)($context['referrer']??''),(string)($context['origin']??''));
    if ($claimed['url']!==null) return $claimed+['provenance'=>$observed['url']===$claimed['url']?'same_origin_referrer_match':'client_claimed_same_origin'];
    if ($observed['url']!==null) return $observed+['provenance'=>'same_origin_referrer'];
    return $claimed+['provenance'=>'unknown'];
}

function music_listening_v1_atomic(PDO $pdo, callable $work): mixed
{
    $owned=!$pdo->inTransaction();$savepoint='music_listening_'.bin2hex(random_bytes(5));
    if ($owned) $pdo->beginTransaction(); else $pdo->exec('SAVEPOINT '.$savepoint);
    try {
        // Acquire SQLite's write reservation before any read; retain PDO's real
        // transaction state for the existing Agent offer/notification services.
        if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite') $pdo->exec('UPDATE music_listening_sessions_v1 SET last_seq=last_seq WHERE session_id=-1');
        $result=$work();
        if ($owned) $pdo->commit(); else $pdo->exec('RELEASE SAVEPOINT '.$savepoint);
        return $result;
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) {
            if ($owned) $pdo->rollBack();
            else {$pdo->exec('ROLLBACK TO SAVEPOINT '.$savepoint);$pdo->exec('RELEASE SAVEPOINT '.$savepoint);}
        }
        throw $error;
    }
}

/** No lazy catalog creation, ownership inference, or client-supplied identity. */
function music_listening_v1_track(PDO $pdo, int $requested, ?array $user): array
{
    $track=music_media_track($requested,$user);
    if (!$track || !can_view_track($track,$user)) music_listening_v1_error('track_unavailable',404,'This recording is unavailable.');
    if ($requested>=1000000000) {
        $catalogId=(int)($track['artist_track_id']??($requested-1000000000));
        $q=$pdo->prepare('SELECT source_track_id FROM artist_catalog_tracks_v181 WHERE id=? AND workspace_id=?');
        $q->execute([$catalogId,(int)($track['workspace_id']??0)]);$backing=(int)$q->fetchColumn();
        if ($backing<1) music_listening_v1_error('identity_unmapped',503,'This recording needs its catalog upgrade before measured playback.');
        $resolved=music_media_track($backing,$user);
        if (!$resolved || !can_view_track($resolved,$user) || (int)($resolved['workspace_id']??0)!==(int)($track['workspace_id']??0)) {
            music_listening_v1_error('track_unavailable',404,'This recording is unavailable.');
        }
        $track=$resolved;
    }
    $track['id']=(int)$track['id'];return $track;
}

function music_listening_v1_identity(PDO $pdo, array $track, ?array $user): array
{
    $identity=['workspace_id'=>null,'catalog_track_id'=>null,'recording_id'=>null,'asset_id'=>null,
        'identity_status'=>'legacy_unmapped','asset_binding'=>'catalog_snapshot_unverified_delivery',
        'duration_ms'=>null,'duration_source'=>'unknown','listen_class'=>'audience'];
    if ($user && function_exists('can_manage_track_production') && can_manage_track_production($track,$user)) $identity['listen_class']='owner_or_manager_preview';
    elseif ((int)($track['is_published']??0)!==1 || ($track['visibility']??'')!=='public') $identity['listen_class']='authorized_private';
    $path=null;
    if (function_exists('music_media_local_path')) $path=music_media_local_path((string)($track['audio_path']??''));
    if (music_listening_v1_columns($pdo,'music_recordings_v1') && music_listening_v1_columns($pdo,'artist_catalog_tracks_v181')) {
        $q=$pdo->prepare('SELECT c.id,c.workspace_id,c.source_track_id,c.audio_path,r.recording_id,r.current_asset_id,r.source_track_id AS recording_source,r.workspace_id AS recording_workspace
            FROM artist_catalog_tracks_v181 c LEFT JOIN music_recordings_v1 r ON r.catalog_track_id=c.id WHERE c.source_track_id=?');
        $q->execute([(int)$track['id']]);$rows=$q->fetchAll(PDO::FETCH_ASSOC);
        if (count($rows)>1) $identity['identity_status']='ambiguous';
        elseif (count($rows)===1) {
            $row=$rows[0];$workspace=(int)$row['workspace_id'];
            if ($workspace>0 && $workspace===(int)($track['workspace_id']??0) && $workspace===(int)$row['recording_workspace']
                && (int)$row['recording_source']===(int)$track['id'] && is_string($row['recording_id'])) {
                $identity['workspace_id']=$workspace;$identity['catalog_track_id']=(int)$row['id'];
                $identity['recording_id']=$row['recording_id'];$identity['identity_status']='mapped';
                if ($row['current_asset_id'] && music_listening_v1_columns($pdo,'music_assets_v1')) {
                    $a=$pdo->prepare('SELECT asset_id,stored_path FROM music_assets_v1 WHERE asset_id=? AND recording_id=? AND workspace_id=?');
                    $a->execute([$row['current_asset_id'],$row['recording_id'],$workspace]);$asset=$a->fetch(PDO::FETCH_ASSOC);
                    if ($asset && (string)$asset['stored_path']===(string)$row['audio_path']) {
                        $identity['asset_id']=$asset['asset_id'];
                        // Media.php can prefer the legacy master. Never assert this
                        // catalog snapshot proves which bytes reached the browser.
                    } else $identity['identity_status']='asset_unavailable';
                }
            } else $identity['identity_status']='mapping_conflict';
        }
    }
    // Probe once on allocation, never on each heartbeat. Other codecs, oversized
    // masters and unverifiable files retain unknown completion denominators.
    if ($path && strtolower(pathinfo($path,PATHINFO_EXTENSION))==='mp3' && (int)filesize($path)<=67108864) {
        require_once __DIR__.'/stem-mp3-metadata-v1.php';
        $metadata=stem_mp3_metadata_v1($path);$seconds=(float)($metadata['duration_seconds']??0);
        if (is_finite($seconds)&&$seconds>0&&$seconds<=43200) {
            $identity['duration_ms']=(int)round($seconds*1000);$identity['duration_source']='server_mp3_probe';
        }
    }
    return $identity;
}

function music_listening_v1_receipt(array $session, array $legacy, int $delta=0): array
{
    $duration=$session['duration_ms']===null?null:(int)$session['duration_ms'];
    return ['ok'=>true,'protocol'=>MUSIC_LISTENING_V1_PROTOCOL,'session_id'=>(int)$legacy['id'],
        'session_token'=>(string)$legacy['session_token'],'play_id'=>$session['play_id'],'user_id'=>(int)($legacy['user_id']??0),
        'state'=>$session['state'],'accepted_seq'=>(int)$session['last_seq'],'next_seq'=>(int)$session['last_seq']+1,
        'accepted_played_ms'=>(int)$session['accepted_played_ms'],'accepted_delta_ms'=>$delta,
        'duration_ms'=>$duration,'metric_version'=>MUSIC_LISTENING_V1_METRIC,
        'qualified_play'=>(int)$session['accepted_played_ms']>=10000,
        'completed'=>$duration===null?null:(int)$session['accepted_played_ms']>=$duration*0.8,
        'natural_ended'=>(bool)$session['natural_ended'],
        'position_progress_percent'=>$duration?min(100,round((int)$session['max_position_ms']/$duration*100,2)):null,
        'coverage'=>['measurement'=>'client_reported_bounded_v1','partial'=>(bool)$session['clipped'],
            'duration'=>$session['duration_source'],'muted_observed'=>(bool)$session['muted_seen'],'loop_observed'=>(bool)$session['loop_seen'],
            'attention'=>'not_observed','listen_class'=>$session['listen_class']],
        'identity'=>['workspace_id'=>$session['workspace_id']===null?null:(int)$session['workspace_id'],
            'catalog_track_id'=>$session['catalog_track_id']===null?null:(int)$session['catalog_track_id'],
            'recording_id'=>$session['recording_id'],'asset_id'=>$session['asset_id'],
            'mapping'=>$session['identity_status'],'binding'=>$session['asset_binding']],
        'source'=>['url'=>$session['source_url'],'reason'=>$session['source_reason'],'provenance'=>$session['source_provenance'],'surface'=>$session['source_context']]];
}

function music_listening_v1_legacy(PDO $pdo, int $sessionId): ?array
{
    $q=$pdo->prepare('SELECT * FROM track_play_sessions WHERE id=?');$q->execute([$sessionId]);return $q->fetch(PDO::FETCH_ASSOC)?:null;
}

/** Refuse a stale sidecar even if another SQLite connection disabled cascades. */
function music_listening_v1_original_receipt(array $saved, array $legacy): array
{
    $original=json_decode((string)$saved['start_receipt_json'],true,32,JSON_THROW_ON_ERROR);
    if (!is_array($original) || !is_string($original['session_token']??null)
        || !hash_equals($original['session_token'],(string)$legacy['session_token'])
        || (int)($original['session_id']??0)!==(int)$legacy['id']
        || (int)($original['user_id']??0)!==(int)($legacy['user_id']??0)) {
        music_listening_v1_error('session_removed',410,'This listening attempt is no longer available.');
    }
    return $original;
}

/** Called after commit. Existing notification keys make retry/reconciliation safe. */
function music_listening_v1_after_commit(PDO $pdo, ?array $user, array $track, array $receipt): void
{
    if ($pdo->inTransaction() || !$user) return;
    try {
        $legacy=music_listening_v1_legacy($pdo,(int)$receipt['session_id']);
        if (!$legacy || (int)$legacy['user_id']!==(int)$user['id'] || !hash_equals((string)$legacy['session_token'],(string)$receipt['session_token'])) return;
        if ((int)$receipt['accepted_played_ms']>0 && function_exists('player_history_activity_notify')) {
            player_history_activity_notify($user,$track,(int)$legacy['id'],(string)$legacy['started_at']);
        }
        if ((int)$receipt['accepted_seq']===0 && ($user['role']??'')==='supervisor' && function_exists('create_notification')) {
            $owner=(int)($track['owner_user_id']??0);$name=trim((string)($user['display_name']??''));
            $label=match($receipt['source']['surface']){'agent_chat'=>'Agent Chat','agent_player'=>'Player',default=>'Music Player'};
            $body=($name!==''?$name:'A Supervisor').' started listening to '.(string)$track['title'].' in '.$label.'.';
            $target=function_exists('url')?url('/admin/track.php?id='.(int)$track['id']):'/admin/track.php?id='.(int)$track['id'];
            if ($owner>0&&$owner!==(int)$user['id']) create_notification($owner,'agent_supervisor_listen','Supervisor started listening',$body,$target,'supervisor_play_session',(int)$legacy['id']);
            elseif ($owner<1&&function_exists('create_notification_for_permission')) create_notification_for_permission('tracks.manage','agent_supervisor_listen','Supervisor started listening',$body,$target,'supervisor_play_session',(int)$legacy['id']);
        }
    } catch (Throwable) { error_log('Native listening activity reconciliation unavailable.'); }
}

function music_listening_v1_handle(PDO $pdo, ?array $user, array $input, array $context=[]): array
{
    if (($input['protocol']??null)!==MUSIC_LISTENING_V1_PROTOCOL) music_listening_v1_error('unsupported_protocol',422,'Use a supported listening protocol.');
    music_listening_v1_connection($pdo);
    if (!music_listening_v1_schema_ready($pdo)) music_listening_v1_error('schema_unavailable',503,'Native listening needs the explicit database upgrade.');
    $userId=(int)($user['id']??0);
    if (music_listening_v1_int($input['listener_user_id']??null,PHP_INT_MAX)!==$userId) music_listening_v1_error('listener_changed',403,'The listener account changed. Start playback again in the current account.');
    $listener=$context['listener_hash']??'';
    if (!is_string($listener)||!preg_match('/^[a-f0-9]{64}$/D',$listener)) music_listening_v1_error('listener_unavailable',403,'The listener session is unavailable.');
    $now=$context['now_ms']??(int)floor(microtime(true)*1000);$now=music_listening_v1_int($now,PHP_INT_MAX,1);
    $trackId=music_listening_v1_int($input['track_id']??null,PHP_INT_MAX,1);
    $play=music_listening_v1_uuid($input['play_id']??null);
    $position=music_listening_v1_int($input['position_ms']??null,MUSIC_LISTENING_V1_TTL_MS);
    $rate=music_listening_v1_rate($input['playback_rate']??1);
    foreach (['muted','loop','observation_partial'] as $diagnostic) if (array_key_exists($diagnostic,$input)&&!is_bool($input[$diagnostic])) music_listening_v1_error('invalid_request',422,'Use boolean playback observations.');
    $muted=(bool)($input['muted']??false);$loop=(bool)($input['loop']??false);$partial=(bool)($input['observation_partial']??false);$action=$input['action']??null;
    if (!is_string($action)) music_listening_v1_error('invalid_request',422,'Choose a listening action.');
    $common=['protocol','csrf_token','listener_user_id','track_id','play_id','position_ms','playback_rate','muted','loop','action'];
    $allowed=$action==='start'?array_merge($common,['source','source_url','media_offer_playback']):array_merge($common,['session_token','event_id','seq','cumulative_played_ms','elapsed_ms','state','observation_partial']);
    if (array_diff(array_keys($input),$allowed)) music_listening_v1_error('invalid_request',422,'This listening request contains unsupported fields.');
    $track=music_listening_v1_track($pdo,$trackId,$user);
    $listenerKey=hash('sha256',$userId.':'.$listener);
    $lock=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?' FOR UPDATE':'';
    if ($action==='start') {
        $source=$input['source']??'player';
        if (!is_string($source)||!in_array($source,['player','agent_chat','agent_player'],true)
            ||(isset($input['source_url'])&&(!is_string($input['source_url'])||strlen($input['source_url'])>2048))
            ||(isset($input['media_offer_playback'])&&(!is_array($input['media_offer_playback'])||strlen(music_listening_v1_json($input['media_offer_playback']))>8192))) {
            music_listening_v1_error('invalid_request',422,'This playback source is invalid.');
        }
        $attribution=music_listening_v1_source($input,$context);
        $hash=hash('sha256',music_listening_v1_json(['track_id'=>$trackId,'position_ms'=>$position,'playback_rate'=>$rate,'muted'=>$muted,'loop'=>$loop,
            'source'=>$source,'source_url'=>music_listening_v1_safe_url((string)($input['source_url']??''),(string)($context['origin']??'')),
            'media_offer_playback'=>$input['media_offer_playback']??null]));
        $receipt=music_listening_v1_atomic($pdo,function()use($pdo,$user,$userId,$track,$trackId,$listener,$listenerKey,$play,$position,$rate,$muted,$loop,$now,$source,$attribution,$hash,$lock,$input,$context):array{
            $insert=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite'
                ?'INSERT OR IGNORE INTO music_listening_starts_v1(listener_key,play_id,request_hash) VALUES(?,?,?)'
                :'INSERT INTO music_listening_starts_v1(listener_key,play_id,request_hash) VALUES(?,?,?) ON DUPLICATE KEY UPDATE listener_key=listener_key';
            $pdo->prepare($insert)->execute([$listenerKey,$play,$hash]);
            $q=$pdo->prepare('SELECT * FROM music_listening_starts_v1 WHERE listener_key=? AND play_id=?'.$lock);$q->execute([$listenerKey,$play]);$key=$q->fetch(PDO::FETCH_ASSOC);
            if (!$key||!hash_equals($key['request_hash'],$hash)) music_listening_v1_error('play_conflict',409,'This playback identifier already describes a different attempt.');
            // Retries must still satisfy the current publication/account boundary.
            music_listening_v1_track($pdo,$trackId,$user);
            if ($key['session_id']!==null) {
                $q=$pdo->prepare('SELECT * FROM music_listening_sessions_v1 WHERE session_id=?'.$lock);$q->execute([(int)$key['session_id']]);$saved=$q->fetch(PDO::FETCH_ASSOC);
                $legacy=music_listening_v1_legacy($pdo,(int)$key['session_id']);
                if (!$saved||!$legacy||$saved['play_id']!==$play||$saved['listener_key']!==$listenerKey||$saved['request_hash']!==$hash
                    ||(int)($legacy['user_id']??0)!==$userId||$legacy['listener_hash']!==$listener||(int)$legacy['track_id']!==(int)$track['id']) {
                    music_listening_v1_error('session_removed',410,'This listening attempt is no longer available.');
                }
                if ($now>=(int)$saved['expires_ms']||$now<(int)$saved['created_ms']) music_listening_v1_error('session_expired',410,'This listening attempt has expired.');
                return music_listening_v1_original_receipt($saved,$legacy);
            }
            // Recheck visibility while holding the idempotency lock, including retry.
            music_listening_v1_track($pdo,$trackId,$user);
            $identity=music_listening_v1_identity($pdo,$track,$user);$token=bin2hex(random_bytes(32));$stamp=gmdate('Y-m-d H:i:s',intdiv($now,1000));
            $device=function_exists('sf_device_type')?sf_device_type((string)($context['user_agent']??'')):'unknown';
            $host=(string)(parse_url((string)($attribution['url']??''),PHP_URL_HOST)??'');
            $q=$pdo->prepare('INSERT INTO track_play_sessions (session_token,track_id,user_id,listener_hash,device_type,referrer_host,source_context,duration_seconds,last_position_seconds,max_position_seconds,started_at,last_event_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)');
            $q->execute([$token,(int)$track['id'],$userId?:null,$listener,$device,$host,$source,($identity['duration_ms']??0)/1000,$position/1000,$position/1000,$stamp,$stamp]);
            $sessionId=(int)$pdo->lastInsertId();
            $pdo->prepare('INSERT INTO track_play_events (session_id,event_type,position_seconds,listened_delta_seconds) VALUES (?,?,?,0)')->execute([$sessionId,'start',$position/1000]);
            if ($user&&isset($input['media_offer_playback'])&&in_array($source,['agent_chat','agent_player'],true)) {
                require_once __DIR__.'/agent-media-offer-native-receipt-v1.php';
                try { music_listening_v1_atomic($pdo,function()use($pdo,$user,$input,$trackId,$track,$sessionId):void{
                    $offer=vp3_media_offer_native_start_v1($pdo,$user,$input['media_offer_playback'],$trackId);
                    if ($offer!==null) vp3_media_offer_native_record_v1($pdo,$offer,$sessionId,(int)$track['id']);
                }); } catch (PDOException $error) { throw $error; } catch (Throwable) { /* Optional offer evidence is independently authorized. */ }
            }
            $saved=['session_id'=>$sessionId,'listener_key'=>$listenerKey,'play_id'=>$play,'request_hash'=>$hash,'requested_track_id'=>$trackId]+$identity+
                ['source_url'=>$attribution['url'],'source_reason'=>$attribution['reason'],'source_provenance'=>$attribution['provenance'],
                'source_context'=>$source,'metric_version'=>MUSIC_LISTENING_V1_METRIC,'state'=>'pending','created_ms'=>$now,'expires_ms'=>$now+MUSIC_LISTENING_V1_TTL_MS,
                'last_received_ms'=>$now,'segment_started_ms'=>null,'segment_elapsed_ms'=>0,'segment_accepted_ms'=>0,
                'elapsed_ms'=>0,'reported_played_ms'=>0,'accepted_played_ms'=>0,'position_ms'=>$position,'max_position_ms'=>$position,
                'playback_rate'=>$rate,'last_seq'=>0,'natural_ended'=>0,'muted_seen'=>$muted?1:0,'loop_seen'=>$loop?1:0,'clipped'=>0];
            $legacy=music_listening_v1_legacy($pdo,$sessionId);$reply=music_listening_v1_receipt($saved,$legacy);$saved['start_receipt_json']=music_listening_v1_json($reply);
            $fields=array_keys($saved);$pdo->prepare('INSERT INTO music_listening_sessions_v1 ('.implode(',',$fields).') VALUES ('.implode(',',array_fill(0,count($fields),'?')).')')->execute(array_values($saved));
            $pdo->prepare('UPDATE music_listening_starts_v1 SET session_id=? WHERE listener_key=? AND play_id=?')->execute([$sessionId,$listenerKey,$play]);
            return $reply;
        });
        music_listening_v1_after_commit($pdo,$user,$track,$receipt);return $receipt;
    }
    $states=['heartbeat'=>['playing','paused','buffering'],'pause'=>['paused'],'resume'=>['playing'],'seek'=>['seeking','paused','playing'],
        'waiting'=>['buffering'],'ratechange'=>['playing','paused','buffering'],'ended'=>['ended'],'stop'=>['stopped']];
    $state=$input['state']??null;
    if (!isset($states[$action])||!is_string($state)||!in_array($state,$states[$action],true)) music_listening_v1_error('invalid_request',422,'This listening transition is invalid.');
    $event=music_listening_v1_uuid($input['event_id']??null);$seq=music_listening_v1_int($input['seq']??null,MUSIC_LISTENING_V1_MAX_EVENTS+1,1);
    $played=music_listening_v1_int($input['cumulative_played_ms']??null,MUSIC_LISTENING_V1_TTL_MS);
    $elapsed=music_listening_v1_int($input['elapsed_ms']??null,MUSIC_LISTENING_V1_TTL_MS);
    if ($played>$elapsed) music_listening_v1_error('invalid_request',422,'Playing time cannot exceed observed elapsed time.');
    $token=$input['session_token']??null;
    if (!is_string($token)||!preg_match('/^[a-f0-9]{64}$/D',$token)) music_listening_v1_error('session_unavailable',404,'This listening session is unavailable.');
    $hash=hash('sha256',music_listening_v1_json(['event_id'=>$event,'seq'=>$seq,'action'=>$action,'state'=>$state,'position_ms'=>$position,'playback_rate'=>$rate,'muted'=>$muted,'loop'=>$loop,'observation_partial'=>$partial,'cumulative_played_ms'=>$played,'elapsed_ms'=>$elapsed]));
    $receipt=music_listening_v1_atomic($pdo,function()use($pdo,$user,$userId,$track,$trackId,$listener,$listenerKey,$play,$token,$now,$lock,$action,$state,$event,$seq,$played,$elapsed,$position,$rate,$muted,$loop,$partial,$hash):array{
        $q=$pdo->prepare('SELECT s.* FROM music_listening_sessions_v1 s JOIN track_play_sessions p ON p.id=s.session_id WHERE s.listener_key=? AND s.play_id=? AND p.session_token=?'.$lock);
        $q->execute([$listenerKey,$play,$token]);$saved=$q->fetch(PDO::FETCH_ASSOC);
        if (!$saved) music_listening_v1_error('session_unavailable',404,'This listening session is unavailable.');
        $legacy=music_listening_v1_legacy($pdo,(int)$saved['session_id']);
        if (!$legacy||(int)($legacy['user_id']??0)!==$userId||$legacy['listener_hash']!==$listener||(int)$legacy['track_id']!==(int)$track['id']||(int)$saved['requested_track_id']!==$trackId) {
            music_listening_v1_error('session_unavailable',404,'This listening session is unavailable.');
        }
        music_listening_v1_original_receipt($saved,$legacy);
        music_listening_v1_track($pdo,$trackId,$user);
        if ($now>=(int)$saved['expires_ms']||$now<(int)$saved['created_ms']) music_listening_v1_error('session_expired',410,'This listening attempt has expired.');
        $q=$pdo->prepare('SELECT * FROM music_listening_receipts_v1 WHERE session_id=? AND (event_id=? OR seq=?)');
        $q->execute([(int)$saved['session_id'],$event,$seq]);$existing=$q->fetchAll(PDO::FETCH_ASSOC);
        if ($existing) {
            if (count($existing)!==1||$existing[0]['event_id']!==$event||(int)$existing[0]['seq']!==$seq||!hash_equals($existing[0]['request_hash'],$hash)) music_listening_v1_error('event_conflict',409,'This event identifier or sequence already describes different evidence.');
            return json_decode($existing[0]['receipt_json'],true,32,JSON_THROW_ON_ERROR);
        }
        if (in_array($saved['state'],['ended','stopped'],true)||$legacy['ended_at']!==null) music_listening_v1_error('session_closed',410,'This listening attempt is closed.');
        if ((int)$saved['last_seq']>=MUSIC_LISTENING_V1_MAX_EVENTS) music_listening_v1_error('session_limit',429,'This listening attempt reached its receipt limit.',['retryable'=>false]);
        if ($seq!==(int)$saved['last_seq']+1) music_listening_v1_error('sequence_gap',409,'Retry the next unacknowledged listening event.',['expected_seq'=>(int)$saved['last_seq']+1]);
        if ($played<(int)$saved['reported_played_ms']||$elapsed<(int)$saved['elapsed_ms']) music_listening_v1_error('event_conflict',409,'Listening counters cannot move backward.');
        if ($saved['state']==='pending'&&($action!=='resume'||$state!=='playing'||$played!==0)) music_listening_v1_error('invalid_transition',409,'Begin the playback interval before reporting time.');
        $claimed=$played-(int)$saved['reported_played_ms'];$clientInterval=$elapsed-(int)$saved['elapsed_ms'];
        // Bound this interval on the client's monotonic timeline. Unlike a
        // total-listening budget, acknowledged paused time cannot fund future
        // playing time. Delayed ordered receipts can still consume their own
        // past intervals without depending on time between HTTP arrivals.
        $serverHorizon=max(0,$now-(int)$saved['created_ms']);
        $serverBudget=max(0,min($elapsed,$serverHorizon)-(int)$saved['elapsed_ms']);
        // An acknowledged inactive state cannot bank server time for a later
        // backdated resume. Receipts delayed within an already-playing segment
        // still share its elapsed budget. An offline pause/resume can lose
        // unverifiable intervals; those claims are explicitly marked partial.
        $segmentServer=$saved['segment_started_ms']===null?0:max(0,$now-(int)$saved['segment_started_ms']-(int)$saved['segment_accepted_ms']);
        $segmentClient=max(0,$elapsed-(int)$saved['segment_elapsed_ms']-(int)$saved['segment_accepted_ms']);
        $serverBudget=min($serverBudget,$segmentServer,$segmentClient);
        $movement=$position-(int)$saved['position_ms'];$oldRate=(float)$saved['playback_rate'];
        $delta=0;
        if ($saved['state']==='playing'&&$action!=='seek'&&$movement>=0) {
            // A discontinuous forward jump is not an interval. Small media-clock
            // quantization is allowed, but cannot create time without played_ms.
            $plausible=$movement<=($clientInterval*$oldRate)+1000;
            if ($plausible) $delta=(int)floor(min($claimed,$clientInterval,$serverBudget,MUSIC_LISTENING_V1_MAX_INTERVAL_MS,$movement/$oldRate));
        }
        if ($delta<$claimed || $partial) $saved['clipped']=1;
        $saved['segment_accepted_ms']=(int)$saved['segment_accepted_ms']+$delta;
        if ($state==='playing'&&($saved['state']!=='playing'||$action==='seek')) {
            $saved['segment_started_ms']=$now;$saved['segment_elapsed_ms']=$elapsed;$saved['segment_accepted_ms']=0;
        }
        $saved['elapsed_ms']=$elapsed;$saved['reported_played_ms']=$played;$saved['accepted_played_ms']=(int)$saved['accepted_played_ms']+$delta;
        $saved['position_ms']=$position;$saved['max_position_ms']=max((int)$saved['max_position_ms'],$position);
        $saved['playback_rate']=$rate;$saved['state']=$state;$saved['last_seq']=$seq;$saved['last_received_ms']=$now;
        $saved['natural_ended']=((int)$saved['natural_ended']||$action==='ended')?1:0;$saved['muted_seen']=((int)$saved['muted_seen']||$muted)?1:0;
        $saved['loop_seen']=((int)$saved['loop_seen']||$loop)?1:0;
        $duration=$saved['duration_ms']===null?null:(int)$saved['duration_ms'];
        $completed=$duration!==null&&(int)$saved['accepted_played_ms']>=$duration*0.8;
        $stamp=gmdate('Y-m-d H:i:s',intdiv($now,1000));$ended=in_array($state,['ended','stopped'],true)?$stamp:null;
        $pdo->prepare('UPDATE track_play_sessions SET last_event_at=?,ended_at=?,listened_seconds=?,last_position_seconds=?,max_position_seconds=?,duration_seconds=?,completion_percent=?,qualified_play=?,completed=? WHERE id=?')->execute([
            $stamp,$ended,$saved['accepted_played_ms']/1000,$position/1000,$saved['max_position_ms']/1000,($duration??0)/1000,
            $duration?min(100,round($saved['max_position_ms']/$duration*100,2)):0,$saved['accepted_played_ms']>=10000?1:0,$completed?1:0,(int)$saved['session_id']]);
        $legacyAction=in_array($action,['waiting','ratechange'],true)?'heartbeat':$action;
        $pdo->prepare('INSERT INTO track_play_events (session_id,event_type,position_seconds,listened_delta_seconds) VALUES (?,?,?,?)')->execute([(int)$saved['session_id'],$legacyAction,$position/1000,$delta/1000]);
        $fields=['elapsed_ms','reported_played_ms','accepted_played_ms','position_ms','max_position_ms','playback_rate','state','last_seq','last_received_ms','segment_started_ms','segment_elapsed_ms','segment_accepted_ms','natural_ended','muted_seen','loop_seen','clipped'];
        $values=array_map(static fn(string $field)=>$saved[$field],$fields);$values[]=(int)$saved['session_id'];
        $pdo->prepare('UPDATE music_listening_sessions_v1 SET '.implode(',',array_map(static fn(string $field)=>$field.'=?',$fields)).' WHERE session_id=?')->execute($values);
        $reply=music_listening_v1_receipt($saved,$legacy,$delta);
        $pdo->prepare('INSERT INTO music_listening_receipts_v1 (session_id,event_id,seq,request_hash,receipt_json) VALUES (?,?,?,?,?)')->execute([(int)$saved['session_id'],$event,$seq,$hash,music_listening_v1_json($reply)]);
        return $reply;
    });
    music_listening_v1_after_commit($pdo,$user,$track,$receipt);return $receipt;
}
