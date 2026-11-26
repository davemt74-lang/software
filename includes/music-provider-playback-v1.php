<?php
declare(strict_types=1);

/** Anonymous provider evidence. These rows are neither personal history nor earnings. */
const MUSIC_PROVIDER_V1_SESSION_MS = 86400000;
const MUSIC_PROVIDER_V1_TICKET_MS = 300000;
const MUSIC_PROVIDER_V1_MAX_ASSET_BYTES = 67108864;
const MUSIC_PROVIDER_V1_MAX_EVENTS = 10000;
const MUSIC_PROVIDER_V1_MAX_RENEWALS = 512;
const MUSIC_PROVIDER_V1_MAX_INTERVAL_MS = 30000;
const MUSIC_PROVIDER_V1_MAX_MEDIA_REQUESTS = 2048;
const MUSIC_PROVIDER_V1_MAX_RESERVED_BYTES = 2147483648;
const MUSIC_PROVIDER_V1_METRIC = 'legacy_10s_80pct_v1';

function music_provider_v1_playback_schema_statements(string $driver): array
{
    $sqlite=$driver==='sqlite';
    $text=$sqlite?'TEXT':'LONGTEXT';
    $tail=$sqlite?'':' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
    $index=$sqlite?'':', INDEX music_provider_sessions_site_time_v1 (site_id,created_ms), INDEX music_provider_sessions_recording_v1 (workspace_id,catalog_track_id)';
    $statements=[
        "CREATE TABLE IF NOT EXISTS music_provider_sessions_v1 (
            session_id CHAR(36) NOT NULL PRIMARY KEY, site_id CHAR(36) NOT NULL,
            site_revision INTEGER NOT NULL, selection_revision INTEGER NOT NULL,
            workspace_id BIGINT NOT NULL, catalog_track_id BIGINT NOT NULL,
            recording_id CHAR(36) NOT NULL, asset_id CHAR(36) NOT NULL, metadata_revision INTEGER NOT NULL,
            play_id CHAR(36) NOT NULL, playback_key_hash CHAR(64) NOT NULL, begin_hash CHAR(64) NOT NULL,
            asset_sha256 CHAR(64) NOT NULL, asset_size BIGINT NOT NULL, mime VARCHAR(64) NOT NULL,
            duration_ms BIGINT NULL, duration_source VARCHAR(40) NOT NULL, source_json {$text} NOT NULL,
            listen_class VARCHAR(40) NOT NULL, state VARCHAR(16) NOT NULL,
            created_ms BIGINT NOT NULL, expires_ms BIGINT NOT NULL, last_received_ms BIGINT NOT NULL,
            started_ms BIGINT NULL, segment_started_ms BIGINT NULL,
            segment_elapsed_ms BIGINT NOT NULL DEFAULT 0, segment_accepted_ms BIGINT NOT NULL DEFAULT 0,
            elapsed_ms BIGINT NOT NULL DEFAULT 0, reported_played_ms BIGINT NOT NULL DEFAULT 0,
            accepted_played_ms BIGINT NOT NULL DEFAULT 0, position_ms BIGINT NOT NULL DEFAULT 0,
            max_position_ms BIGINT NOT NULL DEFAULT 0, playback_rate DECIMAL(10,5) NOT NULL DEFAULT 1,
            last_seq INTEGER NOT NULL DEFAULT 0, natural_ended INTEGER NOT NULL DEFAULT 0,
            muted_seen INTEGER NOT NULL DEFAULT 0, loop_seen INTEGER NOT NULL DEFAULT 0,
            clipped INTEGER NOT NULL DEFAULT 0, begin_receipt_json {$text} NOT NULL,
            media_requests INTEGER NOT NULL DEFAULT 0, reserved_bytes BIGINT NOT NULL DEFAULT 0,
            emitted_bytes BIGINT NOT NULL DEFAULT 0,
            UNIQUE(site_id,play_id),
            FOREIGN KEY(site_id) REFERENCES music_provider_sites_v1(site_id) ON DELETE RESTRICT{$index}
        ){$tail}",
        "CREATE TABLE IF NOT EXISTS music_provider_receipts_v1 (
            session_id CHAR(36) NOT NULL, request_id CHAR(36) NOT NULL, kind VARCHAR(12) NOT NULL,
            seq INTEGER NULL, request_hash CHAR(64) NOT NULL, receipt_json {$text} NOT NULL,
            PRIMARY KEY(session_id,request_id), UNIQUE(session_id,seq),
            FOREIGN KEY(session_id) REFERENCES music_provider_sessions_v1(session_id) ON DELETE RESTRICT
        ){$tail}",
        "CREATE TABLE IF NOT EXISTS music_provider_delivery_v1 (
            delivery_id CHAR(36) NOT NULL PRIMARY KEY, session_id CHAR(36) NOT NULL,
            method VARCHAR(4) NOT NULL, response_status INTEGER NOT NULL, planned_bytes BIGINT NOT NULL,
            reserved_bytes BIGINT NOT NULL, emitted_bytes BIGINT NULL,
            created_ms BIGINT NOT NULL, finished_ms BIGINT NULL,
            FOREIGN KEY(session_id) REFERENCES music_provider_sessions_v1(session_id) ON DELETE RESTRICT
        ){$tail}",
    ];
    if($sqlite){
        $statements[]='CREATE INDEX IF NOT EXISTS music_provider_sessions_site_time_v1 ON music_provider_sessions_v1(site_id,created_ms)';
        $statements[]='CREATE INDEX IF NOT EXISTS music_provider_sessions_recording_v1 ON music_provider_sessions_v1(workspace_id,catalog_track_id)';
    }
    return $statements;
}

function music_provider_v1_playback_required_columns(): array
{
    return [
        'music_provider_sessions_v1'=>['session_id','site_id','site_revision','selection_revision','workspace_id','catalog_track_id','recording_id','asset_id','metadata_revision','play_id','playback_key_hash','begin_hash','asset_sha256','asset_size','mime','duration_ms','duration_source','source_json','listen_class','state','created_ms','expires_ms','last_received_ms','started_ms','segment_started_ms','segment_elapsed_ms','segment_accepted_ms','elapsed_ms','reported_played_ms','accepted_played_ms','position_ms','max_position_ms','playback_rate','last_seq','natural_ended','muted_seen','loop_seen','clipped','begin_receipt_json','media_requests','reserved_bytes','emitted_bytes'],
        'music_provider_receipts_v1'=>['session_id','request_id','kind','seq','request_hash','receipt_json'],
        'music_provider_delivery_v1'=>['delivery_id','session_id','method','response_status','planned_bytes','reserved_bytes','emitted_bytes','created_ms','finished_ms'],
    ];
}

function music_provider_v1_playback_number(mixed $value,int $max,int $min=0): int
{
    if(!is_int($value)||$value<$min||$value>$max)music_provider_v1_error('invalid_request',422,'A playback measurement is outside the supported range.');
    return $value;
}

function music_provider_v1_playback_rate(mixed $value): float
{
    if((!is_int($value)&&!is_float($value))||!is_finite((float)$value)||$value<0.25||$value>4)music_provider_v1_error('invalid_request',422,'Use a supported playback rate.');
    return round((float)$value,5);
}

function music_provider_v1_playback_key(mixed $value): string
{
    if(!is_string($value)||!preg_match('/^mps1_[a-f0-9]{64}$/D',$value))music_provider_v1_error('unavailable',404,'This playback is unavailable.');
    return $value;
}

function music_provider_v1_playback_origin(array $site,array $context): void
{
    $origin=$context['origin']??'';
    // A missing Origin supports server transports and cookie-independent audio.
    // It does not verify a page, a person, or authority beyond the selected grant.
    if(!is_string($origin)||($origin!==''&&!hash_equals((string)$site['origin'],$origin)))music_provider_v1_error('origin_not_allowed',403,'This playback origin is not allowed.');
}

function music_provider_v1_playback_source(array $site,array $input): array
{
    $surface=$input['source_surface']??'player';
    if(!is_string($surface)||!in_array($surface,['player','agent','embed','canvas'],true))music_provider_v1_error('invalid_request',422,'Choose a supported playback surface.');
    $candidate=$input['source_url']??'';
    if(!is_string($candidate)||strlen($candidate)>2048)music_provider_v1_error('invalid_request',422,'The playback page is invalid.');
    $result=['site_id'=>$site['site_id'],'verified_origin'=>$site['origin'],'url'=>null,'reason'=>'unavailable',
        'provenance'=>'registered_site_reported_page','surface'=>$surface,'page_verified'=>false];
    if($candidate==='')return $result;
    if(preg_match('/[\x00-\x20\x7f\\\\]/',$candidate)){$result['reason']='invalid';return $result;}
    $parts=parse_url($candidate);
    if(!$parts||isset($parts['user'])||isset($parts['pass'])||strtolower((string)($parts['scheme']??''))!=='https'||empty($parts['host'])){$result['reason']='invalid';return $result;}
    $port=(int)($parts['port']??443);
    $origin='https://'.strtolower((string)$parts['host']).($port===443?'':':'.$port);
    if(!hash_equals((string)$site['origin'],$origin)){$result['reason']='foreign_origin';return $result;}
    $path=(string)($parts['path']??'/');
    // Exact public route forms only: unknown slugs may contain account IDs or
    // credentials. Keep the registered origin rather than a hidden raw fallback.
    $routes=['/','/index.php','/music','/music/','/music.php','/listen','/listen/','/listen.php','/track.php','/song.php','/album.php','/albums.php','/discography.php'];
    if(!in_array($path,$routes,true)){$result['reason']='restricted_path';return $result;}
    $result['url']=$origin.$path;
    $result['reason']=isset($parts['query'])||isset($parts['fragment'])?'details_redacted':'available';
    return $result;
}

/** Read a bounded immutable snapshot. The returned handle is the served bytes. */
function music_provider_v1_open_asset(PDO $pdo,array $site,string $recordingId,string $assetId,bool $probe=false): array
{
    $query=$pdo->prepare('SELECT a.* FROM music_assets_v1 a JOIN music_recordings_v1 r ON r.recording_id=a.recording_id AND r.workspace_id=a.workspace_id WHERE a.workspace_id=? AND a.recording_id=? AND a.asset_id=?');
    $query->execute([(int)$site['workspace_id'],$recordingId,$assetId]);$asset=$query->fetch(PDO::FETCH_ASSOC);
    $hash=(string)($asset['sha256']??'');$size=(int)($asset['byte_size']??0);
    if(!$asset||!preg_match('/^[a-f0-9]{64}$/D',$hash)||$size<1||$size>MUSIC_PROVIDER_V1_MAX_ASSET_BYTES)music_provider_v1_error('unavailable',404,'This recording is unavailable.');
    require_once __DIR__.'/music-media.php';
    $path=@music_media_local_path((string)$asset['stored_path']);
    // Catalog assets must be uploads, never arbitrary application/config files.
    $uploadRoot=realpath(STONEFELLOW_ROOT.'/uploads');
    if(!$path||!$uploadRoot||!str_starts_with($path,rtrim($uploadRoot,DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR))music_provider_v1_error('unavailable',404,'This recording is unavailable.');
    $source=@fopen($path,'rb');$snapshot=null;
    try{
        if(!$source)music_provider_v1_error('unavailable',404,'This recording is unavailable.');
        $initial=fstat($source);
        if(!$initial||(int)$initial['size']!==$size||(($initial['mode']&0170000)!==0100000))music_provider_v1_error('unavailable',404,'This recording is unavailable.');
        $snapshot=@tmpfile();
        if(!$snapshot)music_provider_v1_error('temporarily_unavailable',503,'Audio delivery is temporarily unavailable.',['retryable'=>true]);
        $digest=hash_init('sha256');$copied=0;
        while($copied<$size){
            $chunk=@fread($source,min(262144,$size-$copied));
            if($chunk===false||$chunk==='')music_provider_v1_error('unavailable',404,'This recording is unavailable.');
            hash_update($digest,$chunk);$offset=0;
            while($offset<strlen($chunk)){
                $written=@fwrite($snapshot,substr($chunk,$offset));
                if($written===false||$written<1)music_provider_v1_error('temporarily_unavailable',503,'Audio delivery is temporarily unavailable.',['retryable'=>true]);
                $offset+=$written;
            }
            $copied+=strlen($chunk);
        }
        // An in-place mutation can never mix versions under an old identifier:
        // only the exact expected digest is released, from this private snapshot.
        if(@fread($source,1)!==''||!hash_equals($hash,hash_final($digest)))music_provider_v1_error('unavailable',404,'This recording is unavailable.');
        if(!@fflush($snapshot))music_provider_v1_error('temporarily_unavailable',503,'Audio delivery is temporarily unavailable.',['retryable'=>true]);
        $meta=stream_get_meta_data($snapshot);$snapshotPath=(string)($meta['uri']??'');
        if(!class_exists('finfo'))music_provider_v1_error('temporarily_unavailable',503,'Audio validation is unavailable.',['retryable'=>false]);
        $mime=@(new finfo(FILEINFO_MIME_TYPE))->file($snapshotPath);
        if(!in_array($mime,['audio/mpeg','audio/mp4','audio/x-m4a','audio/wav','audio/x-wav','audio/vnd.wave','audio/ogg','application/ogg'],true))music_provider_v1_error('unavailable',404,'This recording is unavailable.');
        $duration=null;$durationSource='unknown';
        if($probe&&$mime==='audio/mpeg'){
            require_once __DIR__.'/stem-mp3-metadata-v1.php';
            $metadata=stem_mp3_metadata_v1($snapshotPath);$seconds=(float)($metadata['duration_seconds']??0);
            if(is_finite($seconds)&&$seconds>0&&$seconds<=43200){$duration=(int)round($seconds*1000);$durationSource='server_mp3_probe';}
        }
        if(!rewind($snapshot))music_provider_v1_error('temporarily_unavailable',503,'Audio delivery is temporarily unavailable.',['retryable'=>true]);
        $result=['handle'=>$snapshot,'size'=>$size,'sha256'=>$hash,'mime'=>$mime,'duration_ms'=>$duration,'duration_source'=>$durationSource,'etag'=>'"sha256-'.$hash.'"'];
        $snapshot=null;return $result;
    }finally{
        if(is_resource($source))fclose($source);
        if(is_resource($snapshot))fclose($snapshot);
    }
}

function music_provider_v1_playback_receipt(PDO $pdo,array $session,int $delta=0): array
{
    $duration=$session['duration_ms']===null?null:(int)$session['duration_ms'];
    return ['ok'=>true,'protocol'=>'vp3_music_provider_v1','session_id'=>$session['session_id'],'play_id'=>$session['play_id'],
        'site_id'=>$session['site_id'],'site_revision'=>(int)$session['site_revision'],'selection_revision'=>(int)$session['selection_revision'],
        'recording_id'=>$session['recording_id'],'asset_id'=>$session['asset_id'],'state'=>$session['state'],
        'session_expires_ms'=>(int)$session['expires_ms'],'accepted_seq'=>(int)$session['last_seq'],'next_seq'=>(int)$session['last_seq']+1,
        'accepted_played_ms'=>(int)$session['accepted_played_ms'],'accepted_delta_ms'=>$delta,
        'started'=>$session['started_ms']!==null,'duration_ms'=>$duration,'metric_version'=>MUSIC_PROVIDER_V1_METRIC,
        'qualified_play'=>(int)$session['accepted_played_ms']>=10000,
        'completed'=>$duration===null?null:(int)$session['accepted_played_ms']>=$duration*0.8,
        'natural_ended'=>(bool)$session['natural_ended'],
        'position_progress_percent'=>$duration?min(100,round((int)$session['max_position_ms']/$duration*100,2)):null,
        'identity'=>['provider_id'=>music_provider_v1_setting($pdo)['installation_id'],'workspace_id'=>(int)$session['workspace_id'],
            'catalog_track_id'=>(int)$session['catalog_track_id'],'recording_id'=>$session['recording_id'],'asset_id'=>$session['asset_id'],
            'metadata_revision'=>(int)$session['metadata_revision'],'binding'=>'sha256_verified_snapshot_v1'],
        'coverage'=>['measurement'=>'client_reported_bounded_v1','partial'=>(bool)$session['clipped'],
            'duration'=>$session['duration_source'],'muted_observed'=>(bool)$session['muted_seen'],'loop_observed'=>(bool)$session['loop_seen'],
            'attention'=>'not_observed','listener_identity'=>'anonymous_session_only','listen_class'=>$session['listen_class'],
            'delivery'=>(int)$session['emitted_bytes']>0?'server_emitted_bytes':'not_observed'],
        'source'=>json_decode((string)$session['source_json'],true,16,JSON_THROW_ON_ERROR)];
}

function music_provider_v1_playback_ticket(PDO $pdo,array $session,int $now): array
{
    $settings=music_provider_v1_setting($pdo);$expires=min((int)$session['expires_ms'],$now+MUSIC_PROVIDER_V1_TICKET_MS);
    $claims=['p'=>1,'i'=>$settings['installation_id'],'s'=>$session['session_id'],'t'=>$session['site_id'],
        'r'=>$session['recording_id'],'a'=>$session['asset_id'],'v'=>(int)$session['site_revision'],
        'q'=>(int)$session['selection_revision'],'n'=>$now,'e'=>$expires];
    $encoded=rtrim(strtr(base64_encode(music_provider_v1_json($claims)),'+/','-_'),'=');
    $ticket='mpd1.'.$encoded.'.'.hash_hmac('sha256','mpd1.'.$encoded,(string)$settings['signing_key']);
    return ['url'=>music_provider_v1_base_url().'/api/music-provider-media-v1.php?ticket='.rawurlencode($ticket),
        'ticket_expires_ms'=>$expires,'mime'=>$session['mime'],'byte_size'=>(int)$session['asset_size'],
        'etag'=>'"sha256-'.$session['asset_sha256'].'"','cache'=>'no_store'];
}

/** Cryptographic decoding only; callers must also enforce current DB authority. */
function music_provider_v1_ticket_claims(PDO $pdo,string $ticket,int $now): array
{
    if(strlen($ticket)>2048||!preg_match('/^mpd1\.([A-Za-z0-9_-]{1,1600})\.([a-f0-9]{64})$/D',$ticket,$parts))music_provider_v1_error('unavailable',404,'This playback is unavailable.');
    $settings=music_provider_v1_setting($pdo);
    if(!hash_equals(hash_hmac('sha256','mpd1.'.$parts[1],(string)$settings['signing_key']),$parts[2]))music_provider_v1_error('unavailable',404,'This playback is unavailable.');
    $decoded=base64_decode(strtr($parts[1],'-_','+/'),true);
    try{$claims=json_decode($decoded===false?'':$decoded,true,12,JSON_THROW_ON_ERROR);}catch(JsonException){music_provider_v1_error('unavailable',404,'This playback is unavailable.');}
    if(!is_array($claims)||array_is_list($claims)||array_diff(array_keys($claims),['p','i','s','t','r','a','v','q','n','e'])||count($claims)!==10
        ||($claims['p']??null)!==1||($claims['i']??null)!==$settings['installation_id'])music_provider_v1_error('unavailable',404,'This playback is unavailable.');
    foreach(['s','t','r','a'] as $field)music_provider_v1_uuid($claims[$field]??null);
    foreach(['v','q','n','e'] as $field)music_provider_v1_playback_number($claims[$field]??null,PHP_INT_MAX,1);
    if($claims['e']<=$claims['n']||$claims['e']-$claims['n']>MUSIC_PROVIDER_V1_TICKET_MS)music_provider_v1_error('unavailable',404,'This playback is unavailable.');
    if($now<$claims['n']||$now>=$claims['e'])music_provider_v1_error('ticket_expired',410,'This media ticket expired. Renew the existing playback.',['retryable'=>false]);
    return $claims;
}

function music_provider_v1_playback_session(PDO $pdo,string $sessionId,int $now,?string $key=null,bool $lock=false,array $context=[]): array
{
    $siteId=$context['site_id']??null;
    if($lock&&!is_string($siteId))throw new LogicException('A locking playback request needs its explicit site binding.');
    if(!is_string($siteId)){
        $query=$pdo->prepare('SELECT site_id FROM music_provider_sessions_v1 WHERE session_id=?');$query->execute([$sessionId]);$siteId=$query->fetchColumn();
        if(!is_string($siteId))music_provider_v1_error('unavailable',404,'This playback is unavailable.');
    }
    // Lock the explicit site before any consistent transaction read. Under
    // MySQL REPEATABLE READ, a pre-lock session lookup would retain an old
    // receipt snapshot after waiting for an identical request to commit.
    $site=music_provider_v1_authorize_site($pdo,$siteId,$now,$lock);
    music_provider_v1_playback_origin($site,$context);
    $query=$pdo->prepare('SELECT * FROM music_provider_sessions_v1 WHERE session_id=?'.($lock&&$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?' FOR UPDATE':''));$query->execute([$sessionId]);$session=$query->fetch(PDO::FETCH_ASSOC);
    if(!$session||$session['site_id']!==$siteId||($key!==null&&!hash_equals((string)$session['playback_key_hash'],hash('sha256',$key))))music_provider_v1_error('unavailable',404,'This playback is unavailable.');
    if((int)$session['site_revision']!==(int)$site['revision']||(int)$session['selection_revision']!==(int)$site['selection_revision']||$session['state']==='revoked')music_provider_v1_error('unavailable',404,'This playback is unavailable.');
    if($now<(int)$session['created_ms']||$now>=(int)$session['expires_ms'])music_provider_v1_error('session_expired',410,'This playback session expired.');
    music_provider_v1_public_recording($pdo,$site,(string)$session['recording_id'],true);
    return ['session'=>$session,'site'=>$site];
}

/** Current lightweight authorization for media preflight, without metering/bytes. */
function music_provider_v1_ticket_context(PDO $pdo,string $ticket,array $context=[]): array
{
    music_provider_v1_require_schema($pdo);
    $now=music_provider_v1_playback_number($context['now_ms']??(int)floor(microtime(true)*1000),PHP_INT_MAX,1);
    $claims=music_provider_v1_ticket_claims($pdo,$ticket,$now);
    if(isset($context['site_id'])&&$context['site_id']!==$claims['t'])music_provider_v1_error('unavailable',404,'This playback is unavailable.');
    $context['site_id']=$claims['t'];
    $authorization=music_provider_v1_playback_session($pdo,$claims['s'],$now,null,false,$context);
    $session=$authorization['session'];
    foreach(['site_id'=>'t','recording_id'=>'r','asset_id'=>'a','site_revision'=>'v','selection_revision'=>'q'] as $field=>$claim){
        if((string)$session[$field]!== (string)$claims[$claim])music_provider_v1_error('unavailable',404,'This playback is unavailable.');
    }
    if($claims['e']>(int)$session['expires_ms']||in_array($session['state'],['ended','stopped'],true))music_provider_v1_error('session_closed',410,'This playback session is closed.');
    return $authorization+['claims'=>$claims,'origin'=>$authorization['site']['origin']];
}

function music_provider_v1_playback_action(PDO $pdo,array $input,array $context=[]): array
{
    music_provider_v1_require_schema($pdo);
    if(($input['protocol']??null)!=='vp3_music_provider_v1')music_provider_v1_error('unsupported_protocol',422,'Use a supported music provider protocol.');
    $action=$input['action']??null;
    $now=music_provider_v1_playback_number($context['now_ms']??(int)floor(microtime(true)*1000),PHP_INT_MAX,1);
    if($action==='playback.begin')return music_provider_v1_playback_begin($pdo,$input,$context,$now);
    if(!in_array($action,['playback.renew','playback.events'],true))music_provider_v1_error('unsupported_action',422,'Choose a supported playback action.');
    $allowed=$action==='playback.renew'?['protocol','action','site_id','session_id','request_id']:
        ['protocol','action','site_id','session_id','event_id','seq','event_type','state','position_ms','elapsed_ms','cumulative_played_ms','playback_rate','muted','loop','observation_partial'];
    if(array_diff(array_keys($input),$allowed))music_provider_v1_error('invalid_request',422,'This playback request contains unsupported fields.');
    $siteId=music_provider_v1_uuid($input['site_id']??null);
    if(isset($context['site_id'])&&$context['site_id']!==$siteId)music_provider_v1_error('unavailable',404,'This playback is unavailable.');
    $context['site_id']=$siteId;
    $sessionId=music_provider_v1_uuid($input['session_id']??null);$key=music_provider_v1_playback_key($context['playback_key']??null);
    return music_provider_v1_atomic($pdo,function()use($pdo,$sessionId,$key,$input,$context,$action,$now):array{
        $authorization=music_provider_v1_playback_session($pdo,$sessionId,$now,$key,true,$context);$session=$authorization['session'];
        if($action==='playback.renew')return music_provider_v1_playback_renew($pdo,$session,$input,$now);
        return music_provider_v1_playback_event($pdo,$session,$input,$now);
    });
}

function music_provider_v1_playback_begin(PDO $pdo,array $input,array $context,int $now): array
{
    if(array_diff(array_keys($input),['protocol','action','site_id','recording_id','play_id','playback_key','source_url','source_surface','position_ms','playback_rate','muted','loop']))music_provider_v1_error('invalid_request',422,'This playback request contains unsupported fields.');
    $siteId=music_provider_v1_uuid($input['site_id']??null);$recordingId=music_provider_v1_uuid($input['recording_id']??null);$playId=music_provider_v1_uuid($input['play_id']??null);
    $key=music_provider_v1_playback_key($input['playback_key']??null);
    $position=music_provider_v1_playback_number($input['position_ms']??0,MUSIC_PROVIDER_V1_SESSION_MS);$rate=music_provider_v1_playback_rate($input['playback_rate']??1);
    foreach(['muted','loop'] as $flag)if(array_key_exists($flag,$input)&&!is_bool($input[$flag]))music_provider_v1_error('invalid_request',422,'Use boolean playback observations.');
    if(isset($context['site_id'])&&$context['site_id']!==$siteId)music_provider_v1_error('unavailable',404,'This playback is unavailable.');
    $context['site_id']=$siteId;
    return music_provider_v1_atomic($pdo,function()use($pdo,$siteId,$recordingId,$playId,$key,$position,$rate,$input,$context,$now):array{
        $site=music_provider_v1_authorize_site($pdo,$siteId,$now,true);music_provider_v1_playback_origin($site,$context);
        $public=music_provider_v1_public_recording($pdo,$site,$recordingId,true);$source=music_provider_v1_playback_source($site,$input);
        $hash=hash('sha256',music_provider_v1_json(['recording_id'=>$recordingId,'position_ms'=>$position,'playback_rate'=>$rate,'muted'=>$input['muted']??false,'loop'=>$input['loop']??false,'source'=>$source]));
        $query=$pdo->prepare('SELECT * FROM music_provider_sessions_v1 WHERE site_id=? AND play_id=?'.($pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?' FOR UPDATE':''));$query->execute([$siteId,$playId]);$existing=$query->fetch(PDO::FETCH_ASSOC);
        if($existing){
            if(!hash_equals((string)$existing['playback_key_hash'],hash('sha256',$key))||!hash_equals((string)$existing['begin_hash'],$hash))music_provider_v1_error('play_conflict',409,'This playback identifier already describes a different attempt.');
            $authorized=music_provider_v1_playback_session($pdo,(string)$existing['session_id'],$now,$key,true,$context);
            return json_decode((string)$authorized['session']['begin_receipt_json'],true,24,JSON_THROW_ON_ERROR);
        }
        // Only a fresh play uses current rendition readiness. An existing play
        // keeps its independently verified old asset when a replacement is pending.
        if(($public['availability']??null)!=='ready')music_provider_v1_error('unavailable',404,'This recording is unavailable.');
        $query=$pdo->prepare('SELECT COUNT(*) FROM music_provider_sessions_v1 WHERE site_id=? AND created_ms>=?');$query->execute([$siteId,$now-60000]);
        if((int)$query->fetchColumn()>=300)music_provider_v1_error('site_rate_limit',429,'This site is temporarily at its playback request limit.',['retryable'=>true,'retry_after_seconds'=>60]);
        $query=$pdo->prepare("SELECT COUNT(*) FROM music_provider_sessions_v1 WHERE site_id=? AND expires_ms>? AND last_received_ms>? AND state NOT IN ('ended','stopped','revoked')");$query->execute([$siteId,$now,$now-120000]);
        if((int)$query->fetchColumn()>=500)music_provider_v1_error('site_concurrency_limit',429,'This site is temporarily at its active playback limit.',['retryable'=>true,'retry_after_seconds'=>30]);
        if(!is_string($public['asset_id']??null)||$public['asset_id']==='')music_provider_v1_error('unavailable',404,'This recording is unavailable.');
        $assetId=music_provider_v1_uuid($public['asset_id']);
        $asset=music_provider_v1_open_asset($pdo,$site,$recordingId,$assetId,true);fclose($asset['handle']);
        $session=['session_id'=>music_song_metadata_v1_uuid(),'site_id'=>$siteId,'site_revision'=>(int)$site['revision'],
            'selection_revision'=>(int)$site['selection_revision'],'workspace_id'=>(int)$site['workspace_id'],
            'catalog_track_id'=>(int)$public['catalog_track_id'],'recording_id'=>$recordingId,'asset_id'=>$assetId,
            'metadata_revision'=>(int)$public['metadata_revision'],'play_id'=>$playId,'playback_key_hash'=>hash('sha256',$key),'begin_hash'=>$hash,
            'asset_sha256'=>$asset['sha256'],'asset_size'=>$asset['size'],'mime'=>$asset['mime'],
            'duration_ms'=>$asset['duration_ms'],'duration_source'=>$asset['duration_source'],'source_json'=>music_provider_v1_json($source),
            'listen_class'=>($site['environment']??'production')==='staging'?'staging_test':'public_anonymous_unclassified',
            'state'=>'pending','created_ms'=>$now,'expires_ms'=>$now+MUSIC_PROVIDER_V1_SESSION_MS,'last_received_ms'=>$now,
            'started_ms'=>null,'segment_started_ms'=>null,'segment_elapsed_ms'=>0,'segment_accepted_ms'=>0,
            'elapsed_ms'=>0,'reported_played_ms'=>0,'accepted_played_ms'=>0,'position_ms'=>$position,'max_position_ms'=>$position,'playback_rate'=>$rate,
            'last_seq'=>0,'natural_ended'=>0,'muted_seen'=>!empty($input['muted'])?1:0,'loop_seen'=>!empty($input['loop'])?1:0,'clipped'=>0,
            'media_requests'=>0,'reserved_bytes'=>0,'emitted_bytes'=>0];
        $reply=music_provider_v1_playback_receipt($pdo,$session)+['media'=>music_provider_v1_playback_ticket($pdo,$session,$now)];
        $session['begin_receipt_json']=music_provider_v1_json($reply);$fields=array_keys($session);
        $pdo->prepare('INSERT INTO music_provider_sessions_v1 ('.implode(',',$fields).') VALUES ('.implode(',',array_fill(0,count($fields),'?')).')')->execute(array_values($session));
        return $reply;
    });
}

function music_provider_v1_playback_renew(PDO $pdo,array $session,array $input,int $now): array
{
    $id=music_provider_v1_uuid($input['request_id']??null);$hash=hash('sha256',music_provider_v1_json(['action'=>'playback.renew','request_id'=>$id]));
    $query=$pdo->prepare('SELECT * FROM music_provider_receipts_v1 WHERE session_id=? AND request_id=?');$query->execute([$session['session_id'],$id]);$saved=$query->fetch(PDO::FETCH_ASSOC);
    if($saved){
        if($saved['kind']!=='renew'||!hash_equals($saved['request_hash'],$hash))music_provider_v1_error('event_conflict',409,'This request identifier was already used.');
        return json_decode($saved['receipt_json'],true,24,JSON_THROW_ON_ERROR);
    }
    if(in_array($session['state'],['ended','stopped'],true))music_provider_v1_error('session_closed',410,'This playback session is closed.');
    $query=$pdo->prepare("SELECT COUNT(*) FROM music_provider_receipts_v1 WHERE session_id=? AND kind='renew'");$query->execute([$session['session_id']]);
    if((int)$query->fetchColumn()>=MUSIC_PROVIDER_V1_MAX_RENEWALS)music_provider_v1_error('session_limit',429,'This playback reached its renewal limit.',['retryable'=>false]);
    // Verify the retained pinned asset. A replacement is never substituted.
    $site=music_provider_v1_authorize_site($pdo,(string)$session['site_id'],$now,true);
    $asset=music_provider_v1_open_asset($pdo,$site,(string)$session['recording_id'],(string)$session['asset_id']);
    try{music_provider_v1_playback_asset_match($session,$asset);}finally{fclose($asset['handle']);}
    $reply=music_provider_v1_playback_receipt($pdo,$session)+['request_id'=>$id,'media'=>music_provider_v1_playback_ticket($pdo,$session,$now)];
    $pdo->prepare('INSERT INTO music_provider_receipts_v1 (session_id,request_id,kind,seq,request_hash,receipt_json) VALUES (?,?,?,NULL,?,?)')->execute([$session['session_id'],$id,'renew',$hash,music_provider_v1_json($reply)]);
    return $reply;
}

function music_provider_v1_playback_event(PDO $pdo,array $session,array $input,int $now): array
{
    $types=['resume'=>['playing'],'heartbeat'=>['playing','paused','buffering'],'pause'=>['paused'],'seek'=>['seeking','paused','playing'],
        'waiting'=>['buffering'],'ratechange'=>['playing','paused','buffering'],'ended'=>['ended'],'stop'=>['stopped']];
    $type=$input['event_type']??null;$state=$input['state']??null;
    if(!is_string($type)||!isset($types[$type])||!is_string($state)||!in_array($state,$types[$type],true))music_provider_v1_error('invalid_request',422,'This playback transition is invalid.');
    $id=music_provider_v1_uuid($input['event_id']??null);$seq=music_provider_v1_playback_number($input['seq']??null,MUSIC_PROVIDER_V1_MAX_EVENTS+1,1);
    $position=music_provider_v1_playback_number($input['position_ms']??null,MUSIC_PROVIDER_V1_SESSION_MS);
    $elapsed=music_provider_v1_playback_number($input['elapsed_ms']??null,MUSIC_PROVIDER_V1_SESSION_MS);
    $played=music_provider_v1_playback_number($input['cumulative_played_ms']??null,MUSIC_PROVIDER_V1_SESSION_MS);
    $rate=music_provider_v1_playback_rate($input['playback_rate']??1);
    foreach(['muted','loop','observation_partial'] as $flag)if(array_key_exists($flag,$input)&&!is_bool($input[$flag]))music_provider_v1_error('invalid_request',422,'Use boolean playback observations.');
    $muted=$input['muted']??false;$loop=$input['loop']??false;$partial=$input['observation_partial']??false;
    if($played>$elapsed)music_provider_v1_error('invalid_request',422,'Playing time cannot exceed observed elapsed time.');
    $hash=hash('sha256',music_provider_v1_json(['event_id'=>$id,'seq'=>$seq,'event_type'=>$type,'state'=>$state,'position_ms'=>$position,'elapsed_ms'=>$elapsed,'cumulative_played_ms'=>$played,'playback_rate'=>$rate,'muted'=>$muted,'loop'=>$loop,'observation_partial'=>$partial]));
    $query=$pdo->prepare('SELECT * FROM music_provider_receipts_v1 WHERE session_id=? AND (request_id=? OR seq=?)');$query->execute([$session['session_id'],$id,$seq]);$existing=$query->fetchAll(PDO::FETCH_ASSOC);
    if($existing){
        if(count($existing)!==1||$existing[0]['kind']!=='event'||$existing[0]['request_id']!==$id||(int)$existing[0]['seq']!==$seq||!hash_equals($existing[0]['request_hash'],$hash))music_provider_v1_error('event_conflict',409,'This event identifier or sequence already describes different evidence.');
        return json_decode($existing[0]['receipt_json'],true,24,JSON_THROW_ON_ERROR);
    }
    if(in_array($session['state'],['ended','stopped'],true))music_provider_v1_error('session_closed',410,'This playback session is closed.');
    if((int)$session['last_seq']>=MUSIC_PROVIDER_V1_MAX_EVENTS)music_provider_v1_error('session_limit',429,'This playback reached its event limit.',['retryable'=>false]);
    if($seq!==(int)$session['last_seq']+1)music_provider_v1_error('sequence_gap',409,'Retry the next unacknowledged playback event.',['expected_seq'=>(int)$session['last_seq']+1]);
    if($now<(int)$session['last_received_ms'])music_provider_v1_error('temporarily_unavailable',503,'Playback timing is temporarily unavailable.',['retryable'=>true]);
    if($played<(int)$session['reported_played_ms']||$elapsed<(int)$session['elapsed_ms'])music_provider_v1_error('event_conflict',409,'Playback counters cannot move backward.');
    if($session['state']==='pending'&&($type!=='resume'||$state!=='playing'||$played!==0))music_provider_v1_error('invalid_transition',409,'Begin the playback interval before reporting time.');
    $claimed=$played-(int)$session['reported_played_ms'];$clientInterval=$elapsed-(int)$session['elapsed_ms'];
    $serverBudget=max(0,min($elapsed,max(0,$now-(int)$session['created_ms']))-(int)$session['elapsed_ms']);
    $segmentServer=$session['segment_started_ms']===null?0:max(0,$now-(int)$session['segment_started_ms']-(int)$session['segment_accepted_ms']);
    $segmentClient=max(0,$elapsed-(int)$session['segment_elapsed_ms']-(int)$session['segment_accepted_ms']);
    $serverBudget=min($serverBudget,$segmentServer,$segmentClient);
    $movement=$position-(int)$session['position_ms'];$oldRate=(float)$session['playback_rate'];$delta=0;
    if($session['state']==='playing'&&$type!=='seek'&&$movement>=0&&$movement<=($clientInterval*$oldRate)+1000){
        $delta=(int)floor(min($claimed,$clientInterval,$serverBudget,MUSIC_PROVIDER_V1_MAX_INTERVAL_MS,$movement/$oldRate));
    }
    // Duration is authoritative only when probed from the exact pinned bytes.
    // Position beyond that duration cannot increase the elapsed-playback budget.
    if($session['duration_ms']!==null){
        $available=max(0,(int)$session['duration_ms']-min((int)$session['position_ms'],(int)$session['duration_ms']));
        $delta=min($delta,(int)floor($available/$oldRate));
    }
    if($delta<$claimed||$partial)$session['clipped']=1;
    $session['segment_accepted_ms']=(int)$session['segment_accepted_ms']+$delta;
    if($state==='playing'&&($session['state']!=='playing'||$type==='seek')){
        $session['segment_started_ms']=$now;$session['segment_elapsed_ms']=$elapsed;$session['segment_accepted_ms']=0;
    }
    if($session['started_ms']===null&&$state==='playing')$session['started_ms']=$now;
    $session['elapsed_ms']=$elapsed;$session['reported_played_ms']=$played;$session['accepted_played_ms']=(int)$session['accepted_played_ms']+$delta;
    $session['position_ms']=$position;$session['max_position_ms']=max((int)$session['max_position_ms'],$position);
    $session['playback_rate']=$rate;$session['state']=$state;$session['last_seq']=$seq;$session['last_received_ms']=$now;
    $session['natural_ended']=((int)$session['natural_ended']||$type==='ended')?1:0;
    $session['muted_seen']=((int)$session['muted_seen']||$muted)?1:0;$session['loop_seen']=((int)$session['loop_seen']||$loop)?1:0;
    $fields=['elapsed_ms','reported_played_ms','accepted_played_ms','position_ms','max_position_ms','playback_rate','state','last_seq','last_received_ms','started_ms','segment_started_ms','segment_elapsed_ms','segment_accepted_ms','natural_ended','muted_seen','loop_seen','clipped'];
    $values=array_map(static fn(string $field)=>$session[$field],$fields);$values[]=$session['session_id'];
    $pdo->prepare('UPDATE music_provider_sessions_v1 SET '.implode(',',array_map(static fn(string $field)=>$field.'=?',$fields)).' WHERE session_id=?')->execute($values);
    $reply=music_provider_v1_playback_receipt($pdo,$session,$delta);
    $pdo->prepare('INSERT INTO music_provider_receipts_v1 (session_id,request_id,kind,seq,request_hash,receipt_json) VALUES (?,?,?,?,?,?)')->execute([$session['session_id'],$id,'event',$seq,$hash,music_provider_v1_json($reply)]);
    return $reply;
}

function music_provider_v1_playback_asset_match(array $session,array $asset): void
{
    if(!hash_equals((string)$session['asset_sha256'],(string)$asset['sha256'])||(int)$session['asset_size']!==(int)$asset['size']||$session['mime']!==$asset['mime'])music_provider_v1_error('unavailable',404,'This recording is unavailable.');
}

/** RFC 9110: Range applies to GET; a failed If-Range returns the full pinned body. */
function music_provider_v1_media_range(int $size,string $etag,string $method,string $range='',string $ifRange=''): array
{
    if(!in_array($method,['GET','HEAD'],true))music_provider_v1_error('method_not_allowed',405,'Use GET or HEAD.');
    if($method==='HEAD'||($ifRange!==''&&!hash_equals($etag,$ifRange)))$range='';
    require_once __DIR__.'/music-media.php';
    $parsed=music_media_range($range,$size);
    if($parsed===null)return ['status'=>416,'start'=>0,'end'=>0,'length'=>0];
    [$start,$end,$status]=$parsed;
    return ['status'=>$status,'start'=>$start,'end'=>$end,'length'=>$end-$start+1];
}

function music_provider_v1_media(PDO $pdo,string $ticket,array $context=[]): array
{
    music_provider_v1_require_schema($pdo);
    $now=music_provider_v1_playback_number($context['now_ms']??(int)floor(microtime(true)*1000),PHP_INT_MAX,1);
    $method=$context['method']??'GET';$range=$context['range']??'';$ifRange=$context['if_range']??'';
    if(!is_string($method)||!is_string($range)||!is_string($ifRange)||strlen($range)>128||strlen($ifRange)>160)music_provider_v1_error('invalid_request',422,'The media request is invalid.');
    $claims=music_provider_v1_ticket_claims($pdo,$ticket,$now);$asset=null;
    if(isset($context['site_id'])&&$context['site_id']!==$claims['t'])music_provider_v1_error('unavailable',404,'This playback is unavailable.');
    $context['site_id']=$claims['t'];
    try{
        $result=music_provider_v1_atomic($pdo,function()use($pdo,$claims,$now,$context,$method,$range,$ifRange,&$asset):array{
            $authorized=music_provider_v1_playback_session($pdo,$claims['s'],$now,null,true,$context);
            $session=$authorized['session'];$site=$authorized['site'];
            foreach(['site_id'=>'t','recording_id'=>'r','asset_id'=>'a','site_revision'=>'v','selection_revision'=>'q'] as $field=>$claim){
                if((string)$session[$field]!== (string)$claims[$claim])music_provider_v1_error('unavailable',404,'This playback is unavailable.');
            }
            if($claims['e']>(int)$session['expires_ms']||in_array($session['state'],['ended','stopped'],true))music_provider_v1_error('session_closed',410,'This playback session is closed.');
            if((int)$session['media_requests']>=MUSIC_PROVIDER_V1_MAX_MEDIA_REQUESTS)music_provider_v1_error('session_limit',429,'This playback reached its media request limit.',['retryable'=>false]);
            $asset=music_provider_v1_open_asset($pdo,$site,(string)$session['recording_id'],(string)$session['asset_id']);music_provider_v1_playback_asset_match($session,$asset);
            $plan=music_provider_v1_media_range((int)$asset['size'],(string)$asset['etag'],$method,$range,$ifRange);
            $reserved=$method==='GET'&&in_array($plan['status'],[200,206],true)?$plan['length']:0;
            if((int)$session['reserved_bytes']+$reserved>MUSIC_PROVIDER_V1_MAX_RESERVED_BYTES)music_provider_v1_error('session_limit',429,'This playback reached its media transfer limit.',['retryable'=>false]);
            $deliveryId=music_song_metadata_v1_uuid();
            $pdo->prepare('INSERT INTO music_provider_delivery_v1 (delivery_id,session_id,method,response_status,planned_bytes,reserved_bytes,created_ms) VALUES (?,?,?,?,?,?,?)')->execute([$deliveryId,$session['session_id'],$method,$plan['status'],$plan['length'],$reserved,$now]);
            $pdo->prepare('UPDATE music_provider_sessions_v1 SET media_requests=media_requests+1,reserved_bytes=reserved_bytes+? WHERE session_id=?')->execute([$reserved,$session['session_id']]);
            return $asset+['session_id'=>$session['session_id'],'site_id'=>$site['site_id'],'origin'=>$site['origin'],'delivery_id'=>$deliveryId,'range'=>$plan,'method'=>$method];
        });
        $asset=null;return $result;
    }finally{if(is_array($asset)&&is_resource($asset['handle']??null))fclose($asset['handle']);}
}

/** Exactly-once server-emitted byte accounting, never evidence of human attention. */
function music_provider_v1_delivery_record(PDO $pdo,array $descriptor,string $method,int $status,int $bytes,?int $nowMs=null): void
{
    $id=music_provider_v1_uuid($descriptor['delivery_id']??null);$sessionId=music_provider_v1_uuid($descriptor['session_id']??null);
    $bytes=music_provider_v1_playback_number($bytes,MUSIC_PROVIDER_V1_MAX_ASSET_BYTES);
    $now=$nowMs??(int)floor(microtime(true)*1000);
    music_provider_v1_atomic($pdo,function()use($pdo,$id,$sessionId,$method,$status,$bytes,$now):void{
        $lock=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?' FOR UPDATE':'';
        // Same order as request admission: session before its delivery row.
        $q=$pdo->prepare('SELECT session_id FROM music_provider_sessions_v1 WHERE session_id=?'.$lock);$q->execute([$sessionId]);
        if(!$q->fetchColumn())music_provider_v1_error('unavailable',404,'This delivery is unavailable.');
        $q=$pdo->prepare('SELECT * FROM music_provider_delivery_v1 WHERE delivery_id=? AND session_id=?'.$lock);$q->execute([$id,$sessionId]);$row=$q->fetch(PDO::FETCH_ASSOC);
        if(!$row||$row['method']!==$method||(int)$row['response_status']!==$status||$bytes>(int)$row['reserved_bytes'])music_provider_v1_error('invalid_request',422,'The delivery accounting is invalid.');
        if($row['finished_ms']!==null){
            if((int)$row['emitted_bytes']!==$bytes)music_provider_v1_error('event_conflict',409,'This delivery was already recorded.');
            return;
        }
        $pdo->prepare('UPDATE music_provider_delivery_v1 SET emitted_bytes=?,finished_ms=? WHERE delivery_id=?')->execute([$bytes,$now,$id]);
        $pdo->prepare('UPDATE music_provider_sessions_v1 SET emitted_bytes=emitted_bytes+? WHERE session_id=?')->execute([$bytes,$sessionId]);
    });
}

/** Called in the canonical catalog transaction; republishing cannot revive an old grant. */
function music_provider_v1_withdraw_recording(PDO $pdo,int $workspaceId,int $catalogTrackId): void
{
    if(array_diff(['workspace_id','catalog_track_id','state'],music_song_metadata_v1_columns($pdo,'music_provider_sessions_v1')))return;
    $pdo->prepare("UPDATE music_provider_sessions_v1 SET state='revoked' WHERE workspace_id=? AND catalog_track_id=? AND state NOT IN ('ended','stopped','revoked')")->execute([$workspaceId,$catalogTrackId]);
}
