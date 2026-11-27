<?php
declare(strict_types=1);

/** ML3: authorized aggregate evidence, never personal history or financial accrual. */
require_once __DIR__.'/music-listening-v1.php';
require_once __DIR__.'/music-provider-v1.php';

const MUSIC_ANALYTICS_V1_PROTOCOL = 'vp3_music_analytics_v1';
const MUSIC_ANALYTICS_V1_MAX_ROWS = 25000;
const MUSIC_ANALYTICS_V1_MAX_DAYS = 366;

final class MusicAnalyticsV1Exception extends RuntimeException
{
    public function __construct(public string $publicCode, public int $httpStatus, string $message)
    { parent::__construct($message, $httpStatus); }
}

function music_analytics_v1_error(string $code, int $status, string $message): never
{ throw new MusicAnalyticsV1Exception($code,$status,$message); }

/** The caller's old role and the global listening.view permission are not authority. */
function music_analytics_v1_can_view(PDO $pdo,array $user,int $workspaceId): bool
{
    $q=$pdo->prepare('SELECT * FROM users WHERE id=? AND is_active=1');
    $q->execute([(int)($user['id']??0)]);$current=$q->fetch(PDO::FETCH_ASSOC);
    return $current && music_workspace_resources_v330_workspace_enabled($pdo,$workspaceId)
        && music_workspace_resources_v330_can_manage($pdo,$workspaceId,'tracks',$current);
}

function music_analytics_v1_columns(PDO $pdo,string $table): array
{
    if(!preg_match('/^[a-z0-9_]+$/D',$table))throw new LogicException('Invalid analytics table.');
    if($pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite')
        return array_column($pdo->query('PRAGMA table_info('.$table.')')->fetchAll(PDO::FETCH_ASSOC),'name');
    $q=$pdo->prepare('SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');
    $q->execute([$table]);return $q->fetchAll(PDO::FETCH_COLUMN);
}

function music_analytics_v1_has(PDO $pdo,string $table,array $columns): bool
{ return !array_diff($columns,music_analytics_v1_columns($pdo,$table)); }

function music_analytics_v1_integer(mixed $value,int $min,int $max): int
{
    if(!is_int($value)||$value<$min||$value>$max)music_analytics_v1_error('invalid_request',422,'Use a valid report selector.');
    return $value;
}

function music_analytics_v1_uuid(mixed $value): string
{
    if($value==='')return '';
    if(!is_string($value)||!preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[1-8][a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/iD',$value))
        music_analytics_v1_error('invalid_request',422,'Use a valid recording or site selector.');
    return strtolower($value);
}

function music_analytics_v1_date(mixed $value,DateTimeZone $timezone): DateTimeImmutable
{
    if(!is_string($value)||!preg_match('/^\d{4}-\d{2}-\d{2}$/D',$value))music_analytics_v1_error('invalid_period',422,'Choose valid report dates.');
    $date=DateTimeImmutable::createFromFormat('!Y-m-d',$value,$timezone);
    if(!$date||$date->format('Y-m-d')!==$value)music_analytics_v1_error('invalid_period',422,'Choose valid report dates.');
    return $date;
}

/** Shift the calendar label in UTC, then resolve that day's first local instant. */
function music_analytics_v1_shift_date(DateTimeImmutable $date,int $days,DateTimeZone $timezone): DateTimeImmutable
{
    $label=(new DateTimeImmutable($date->format('Y-m-d'),new DateTimeZone('UTC')))->modify(($days>=0?'+':'').$days.' days')->format('Y-m-d');
    // A timezone may skip midnight (or an entire date). Resolve that boundary
    // afresh instead of carrying the previous day's normalized 01:00 forward.
    return DateTimeImmutable::createFromFormat('!Y-m-d',$label,$timezone);
}

function music_analytics_v1_filters(array $input,int $now): array
{
    $allowed=['protocol','action','csrf_token','workspace_id','period','start_date','end_date','timezone','channel','traffic','recording_id','album_id','site_id','limit','offset'];
    if(array_diff(array_keys($input),$allowed)||($input['protocol']??null)!==MUSIC_ANALYTICS_V1_PROTOCOL||($input['action']??null)!=='report')
        music_analytics_v1_error('invalid_request',422,'Use the supported music analytics report.');
    $workspace=music_analytics_v1_integer($input['workspace_id']??null,1,2147483647);
    $zone=$input['timezone']??'UTC';
    if(!is_string($zone)||!in_array($zone,DateTimeZone::listIdentifiers(DateTimeZone::ALL_WITH_BC),true))
        music_analytics_v1_error('invalid_timezone',422,'Choose a supported reporting timezone.');
    $timezone=new DateTimeZone($zone);
    $period=$input['period']??'30d';
    if(!in_array($period,['7d','30d','90d','custom'],true))music_analytics_v1_error('invalid_period',422,'Choose 7, 30 or 90 days, or a custom period.');
    $today=(new DateTimeImmutable('@'.intdiv($now,1000)))->setTimezone($timezone)->setTime(0,0);
    if($period==='custom'){
        $start=music_analytics_v1_date($input['start_date']??null,$timezone);$end=music_analytics_v1_date($input['end_date']??null,$timezone);
    }else{
        if(isset($input['start_date'])||isset($input['end_date']))music_analytics_v1_error('invalid_period',422,'Choose custom to supply report dates.');
        $end=$today;$start=music_analytics_v1_shift_date($end,-((int)$period-1),$timezone);
    }
    $calendarStart=new DateTimeImmutable($start->format('Y-m-d'),new DateTimeZone('UTC'));
    $calendarEnd=new DateTimeImmutable($end->format('Y-m-d'),new DateTimeZone('UTC'));
    if($end<$start||$calendarStart->diff($calendarEnd)->days>=MUSIC_ANALYTICS_V1_MAX_DAYS||$end>$today)
        music_analytics_v1_error('invalid_period',422,'Choose a period of at most 366 days ending today or earlier.');
    $channel=$input['channel']??'all';$traffic=$input['traffic']??'audience';
    if(!in_array($channel,['all','native','provider','legacy'],true)||!in_array($traffic,['all','audience','tests'],true))
        music_analytics_v1_error('invalid_request',422,'Choose a supported listening source and traffic filter.');
    if($channel==='legacy'&&$traffic!=='all')music_analytics_v1_error('invalid_request',422,'Legacy listening has no audience classification. Choose all traffic.');
    $site=music_analytics_v1_uuid($input['site_id']??'');
    if($site!==''&&!in_array($channel,['all','provider'],true))music_analytics_v1_error('invalid_request',422,'A connected site filter requires connected-site listening.');
    return ['workspace_id'=>$workspace,'period'=>$period,'start_date'=>$start->format('Y-m-d'),'end_date'=>$end->format('Y-m-d'),
        'timezone'=>$zone,'start_ms'=>$start->getTimestamp()*1000,'end_exclusive_ms'=>music_analytics_v1_shift_date($end,1,$timezone)->getTimestamp()*1000,
        'channel'=>$channel,'traffic'=>$traffic,'recording_id'=>music_analytics_v1_uuid($input['recording_id']??''),
        'album_id'=>music_analytics_v1_integer($input['album_id']??0,0,2147483647),'site_id'=>$site,
        'limit'=>music_analytics_v1_integer($input['limit']??20,1,100),'offset'=>music_analytics_v1_integer($input['offset']??0,0,25000)];
}

function music_analytics_v1_metrics(): array
{
    return ['playback_requests'=>0,'starts'=>0,'qualified_plays'=>0,'listening_ms'=>0,'completed_plays'=>0,
        'completion_known_plays'=>0,'completion_percent'=>null,'legacy_sessions'=>0,'legacy_listening_ms'=>0,
        'emitted_bytes'=>0,'reserved_bytes'=>0,'media_requests'=>0];
}

function music_analytics_v1_add(array &$target,array $metrics): void
{
    foreach($metrics as $key=>$value)if($key!=='completion_percent'){
        // The browser consumes JSON numbers. Never silently round an otherwise
        // valid large historical aggregate beyond its exact integer range.
        if(!is_int($value)||$value<0||$target[$key]>9007199254740991-$value)
            music_analytics_v1_error('report_window_too_large',422,'This report exceeds the exact numeric range. Choose a shorter period or a song or site filter.');
        $target[$key]+=$value;
    }
}

function music_analytics_v1_finish(array $row): array
{
    $row['completion_percent']=$row['completion_known_plays']>0?round(100*$row['completed_plays']/$row['completion_known_plays'],2):null;
    return $row;
}

function music_analytics_v1_text(mixed $value,int $limit=190): string
{
    $text=is_string($value)?$value:'';
    if(!mb_check_encoding($text,'UTF-8'))return '';
    return mb_substr(preg_replace('/[\x00-\x1f\x7f]/u','',$text),0,$limit);
}

/** Project stored context again; never expose hidden raw URL fallbacks. */
function music_analytics_v1_source(array $row,string $channel): array
{
    if($channel==='legacy')return ['url'=>null,'provenance'=>'legacy_unknown','surface'=>'legacy'];
    if($channel==='provider'){
        try{$source=json_decode((string)$row['source_json'],true,16,JSON_THROW_ON_ERROR);}catch(JsonException){$source=[];}
        if(!is_array($source))$source=[];
        $surface=in_array($source['surface']??'',['player','agent','embed','canvas'],true)?$source['surface']:'player';
        $origin=music_listening_v1_origin((string)($source['verified_origin']??''));
        if(!$origin||!str_starts_with($origin,'https://'))return ['url'=>null,'provenance'=>'registered_site_reported_page','surface'=>$surface];
        try{$safe=music_provider_v1_playback_source(['site_id'=>$row['site_id'],'origin'=>$origin],['source_url'=>(string)($source['url']??''),'source_surface'=>$surface]);}
        catch(Throwable){$safe=['url'=>null];}
        return ['url'=>$safe['url'],'provenance'=>'registered_site_reported_page','surface'=>$surface];
    }
    global $config;
    $base=rtrim((string)($config['site']['base_url']??''),'/');$path=rtrim((string)($config['site']['base_path']??''),'/');
    if($path!==''&&(parse_url($base,PHP_URL_PATH)??'')==='')$base.=$path;
    $safe=music_listening_v1_safe_url((string)($row['source_url']??''),$base);
    $surface=in_array($row['source_context']??'',['player','chat','agent_chat','agent_player','profile','embed','canvas'],true)?$row['source_context']:'unknown';
    $provenance=in_array($row['source_provenance']??'',['same_origin_referrer_match','same_origin_referrer','client_claimed_same_origin'],true)?$row['source_provenance']:'unknown';
    return ['url'=>$safe['url'],'provenance'=>$provenance,'surface'=>$surface];
}

function music_analytics_v1_page(array $rows,int $offset,int $limit): array
{
    $rows=array_values($rows);
    usort($rows,static fn($a,$b)=>($b['listening_ms']<=>$a['listening_ms'])?:($b['starts']<=>$a['starts'])?:strcmp(json_encode($a),json_encode($b)));
    return ['items'=>array_map('music_analytics_v1_finish',array_slice($rows,$offset,$limit)),
        'pagination'=>['total'=>count($rows),'offset'=>$offset,'limit'=>$limit,'has_more'=>$offset+$limit<count($rows)]];
}

/** No request DDL, no shared cache, and no caller transaction with an older authority snapshot. */
function music_analytics_v1_report(PDO $pdo,array $user,array $input,array $context=[]): array
{
    $now=$context['now_ms']??(int)floor(microtime(true)*1000);
    if(!is_int($now)||$now<1)throw new LogicException('Invalid report clock.');
    $filters=music_analytics_v1_filters($input,$now);$workspaceId=$filters['workspace_id'];
    if($pdo->inTransaction())music_analytics_v1_error('transaction_boundary_required',409,'Open the report outside an existing transaction.');
    if(!music_analytics_v1_can_view($pdo,$user,$workspaceId))music_analytics_v1_error('workspace_unavailable',403,'A current workspace owner or manager can view this report.');
    if($pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'){
        $pdo->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        $pdo->exec('START TRANSACTION WITH CONSISTENT SNAPSHOT, READ ONLY');
    }else $pdo->beginTransaction();
    try{
        // Recheck inside the snapshot, then again after closing it.
        if(!music_analytics_v1_can_view($pdo,$user,$workspaceId))music_analytics_v1_error('workspace_unavailable',403,'This workspace report is unavailable.');
        $report=music_analytics_v1_snapshot($pdo,$filters,$now);
        $pdo->commit();
    }catch(Throwable $error){if($pdo->inTransaction())$pdo->rollBack();throw $error;}
    if(!music_analytics_v1_can_view($pdo,$user,$workspaceId))music_analytics_v1_error('workspace_unavailable',403,'Your workspace access changed.');
    return $report;
}

function music_analytics_v1_snapshot(PDO $pdo,array $f,int $now): array
{
    $workspaceId=$f['workspace_id'];$workspace=music_workspace_resources_v330_workspace($pdo,$workspaceId);
    if(!$workspace)music_analytics_v1_error('workspace_unavailable',403,'This workspace report is unavailable.');
    $catalogRequired=['id','workspace_id','source_track_id','title','album_id','is_published','visibility','audio_path'];
    $recordingRequired=['recording_id','workspace_id','catalog_track_id','source_track_id','metadata_json','work_id','current_asset_id','archived_at','revision'];
    if(!music_analytics_v1_has($pdo,'artist_catalog_tracks_v181',$catalogRequired)||!music_analytics_v1_has($pdo,'music_recordings_v1',$recordingRequired)
        ||!music_analytics_v1_has($pdo,'artist_catalog_albums_v181',['id','workspace_id','title']))
        music_analytics_v1_error('schema_unavailable',503,'Install the music catalog upgrade before opening analytics.');
    $availability=['catalog'=>'available',
        'native'=>music_analytics_v1_has($pdo,'music_listening_sessions_v1',['session_id','workspace_id','catalog_track_id','recording_id','created_ms','accepted_played_ms','duration_ms','last_seq','state','expires_ms','clipped','muted_seen','loop_seen','listen_class','source_url','source_context','source_provenance','metric_version'])?'available':'unavailable',
        'provider'=>music_analytics_v1_has($pdo,'music_provider_sessions_v1',['site_id','workspace_id','catalog_track_id','recording_id','created_ms','accepted_played_ms','duration_ms','started_ms','state','expires_ms','clipped','muted_seen','loop_seen','listen_class','source_json','emitted_bytes','reserved_bytes','media_requests','last_received_ms'])
            &&music_analytics_v1_has($pdo,'music_provider_sites_v1',['site_id','workspace_id','label','origin','status','environment','credential_expires_ms','creator_user_id'])
            &&music_analytics_v1_has($pdo,'music_provider_selections_v1',['site_id','recording_id'])?'available':'unavailable',
        'legacy'=>music_analytics_v1_has($pdo,'track_play_sessions',['id','track_id','started_at','listened_seconds'])
            &&music_analytics_v1_has($pdo,'tracks',['id','workspace_id'])?'available':'unavailable',
        'delivery'=>music_analytics_v1_has($pdo,'music_provider_delivery_v1',['session_id','finished_ms','emitted_bytes'])?'available':'unavailable'];
    if($f['channel']!=='all'&&$availability[$f['channel']]!=='available')music_analytics_v1_error('source_unavailable',503,'This listening source needs its database upgrade.');
    if($availability['native']==='unavailable'&&$availability['provider']==='unavailable'&&($f['traffic']!=='all'||$availability['legacy']==='unavailable'))
        music_analytics_v1_error('source_unavailable',503,'Listening measurement is unavailable until its database upgrade is installed.');
    if($f['recording_id']!==''){
        $q=$pdo->prepare('SELECT recording_id FROM music_recordings_v1 WHERE workspace_id=? AND recording_id=?');$q->execute([$workspaceId,$f['recording_id']]);
        if(!$q->fetchColumn())music_analytics_v1_error('selector_unavailable',404,'This recording is unavailable in the selected workspace.');
    }
    if($f['album_id']){
        $q=$pdo->prepare('SELECT id FROM artist_catalog_albums_v181 WHERE workspace_id=? AND id=?');$q->execute([$workspaceId,$f['album_id']]);
        if(!$q->fetchColumn())music_analytics_v1_error('selector_unavailable',404,'This release is unavailable in the selected workspace.');
    }
    $sites=[];$siteOptions=[];
    if($availability['provider']==='available'){
        $q=$pdo->prepare('SELECT s.site_id,s.label,s.origin,s.status,s.environment,s.credential_expires_ms,s.creator_user_id,
            (SELECT COUNT(*) FROM music_provider_selections_v1 x WHERE x.site_id=s.site_id) AS selected_count,
            (SELECT MAX(p.last_received_ms) FROM music_provider_sessions_v1 p WHERE p.site_id=s.site_id AND p.workspace_id=s.workspace_id AND p.started_ms IS NOT NULL) AS last_playback_ms
            FROM music_provider_sites_v1 s WHERE s.workspace_id=? ORDER BY s.label,s.site_id LIMIT 101');$q->execute([$workspaceId]);
        foreach($q->fetchAll(PDO::FETCH_ASSOC) as $site){
            $status=in_array($site['status'],['active','pending','revoked'],true)?$site['status']:'unavailable';
            if($status==='active'&&((int)$site['credential_expires_ms']<=$now||!music_provider_v1_manager($pdo,$workspaceId,(int)$site['creator_user_id'])))$status='reconnect_required';
            $id=$site['site_id'];$origin=music_listening_v1_origin((string)$site['origin']);
            $sites[$id]=['site_id'=>$id,'label'=>music_analytics_v1_text($site['label'],120),'origin'=>$origin,'status'=>$status,
                'environment'=>$site['environment']==='staging'?'staging':'production','selected_count'=>(int)$site['selected_count'],
                'last_playback_at'=>$site['last_playback_ms']===null?null:gmdate('c',intdiv((int)$site['last_playback_ms'],1000))]+music_analytics_v1_metrics();
            $siteOptions[]=['id'=>$id,'label'=>$sites[$id]['label']];
        }
        if(count($sites)>100)music_analytics_v1_error('report_window_too_large',422,'This workspace has too many connections for this report.');
    }
    if($f['site_id']!==''&&!isset($sites[$f['site_id']]))music_analytics_v1_error('selector_unavailable',404,'This site is unavailable in the selected workspace.');
    $q=$pdo->prepare('SELECT COUNT(*) FROM artist_catalog_tracks_v181 WHERE workspace_id=?');$q->execute([$workspaceId]);$catalogCount=(int)$q->fetchColumn();
    $q=$pdo->prepare("SELECT COUNT(*) FROM music_recordings_v1 m JOIN artist_catalog_tracks_v181 c ON c.id=m.catalog_track_id AND c.workspace_id=m.workspace_id WHERE m.workspace_id=? AND m.archived_at IS NULL AND TRIM(c.title)<>''");$q->execute([$workspaceId]);$metadataCount=(int)$q->fetchColumn();
    $q=$pdo->prepare('SELECT m.recording_id,c.id AS catalog_track_id,c.title,c.album_id FROM artist_catalog_tracks_v181 c LEFT JOIN music_recordings_v1 m ON m.catalog_track_id=c.id AND m.workspace_id=c.workspace_id WHERE c.workspace_id=? ORDER BY c.title,c.id LIMIT 501');$q->execute([$workspaceId]);$catalogRows=$q->fetchAll(PDO::FETCH_ASSOC);
    $recordingOptions=[];foreach(array_slice($catalogRows,0,500) as $c)if($c['recording_id'])$recordingOptions[]=['id'=>$c['recording_id'],'title'=>music_analytics_v1_text($c['title'])];
    $q=$pdo->prepare('SELECT id,title FROM artist_catalog_albums_v181 WHERE workspace_id=? ORDER BY title,id LIMIT 501');$q->execute([$workspaceId]);$albumRows=$q->fetchAll(PDO::FETCH_ASSOC);
    $albumOptions=array_map(static fn($a)=>['id'=>(int)$a['id'],'title'=>music_analytics_v1_text($a['title'])],array_slice($albumRows,0,500));
    $catalog=['tracks'=>$catalogCount,'metadata_ready'=>$metadataCount,'playback_ready'=>0,'readiness_checked'=>0,'readiness_total'=>$catalogCount,'readiness_complete'=>$catalogCount<=100,'needs_attention'=>[]];
    foreach(array_slice($catalogRows,0,100) as $c){
        $catalog['readiness_checked']++;$reasons=[];
        if(!$c['recording_id'])$reasons=['Complete the song metadata upgrade.'];
        else{
            $q=$pdo->prepare('SELECT * FROM music_recordings_v1 WHERE workspace_id=? AND recording_id=?');$q->execute([$workspaceId,$c['recording_id']]);$recording=$q->fetch(PDO::FETCH_ASSOC);
            $track=music_workspace_resources_v330_catalog_track($pdo,$workspaceId,(int)$c['catalog_track_id']);
            $envelope=music_song_metadata_v1_envelope($pdo,$recording,$track);
            if($envelope['readiness']['playback']['ready'])$catalog['playback_ready']++;
            else $reasons=$envelope['readiness']['playback']['reasons'];
        }
        if($reasons&&count($catalog['needs_attention'])<20)$catalog['needs_attention'][]=['recording_id'=>$c['recording_id'],'catalog_track_id'=>(int)$c['catalog_track_id'],'title'=>music_analytics_v1_text($c['title']),'reasons'=>$reasons];
    }
    if(!$catalog['readiness_complete'])$catalog['playback_ready']=null;
    $summary=music_analytics_v1_metrics();$daily=[];$tracks=[];$albums=[];$pages=[];$surfaces=[];$processed=0;
    $timezone=new DateTimeZone($f['timezone']);$day=music_analytics_v1_date($f['start_date'],new DateTimeZone('UTC'));$last=music_analytics_v1_date($f['end_date'],new DateTimeZone('UTC'));
    for(;$day<=$last;$day=$day->modify('+1 day'))$daily[$day->format('Y-m-d')]=['date'=>$day->format('Y-m-d')]+music_analytics_v1_metrics();
    $coverage=['native_sessions'=>0,'provider_sessions'=>0,'legacy_sessions'=>0,'partial_sessions'=>0,'unknown_duration_sessions'=>0,
        'preview_sessions'=>0,'private_sessions'=>0,'staging_sessions'=>0,'unknown_page_sessions'=>0,'ongoing_sessions'=>0,
        'muted_sessions'=>0,'loop_sessions'=>0,'unfinished_delivery_requests'=>$availability['delivery']==='available'?0:null,
        'listener_identity'=>'not_available','notes'=>[]];
    $baseJoin=' JOIN music_recordings_v1 m ON m.recording_id=s.recording_id AND m.workspace_id=s.workspace_id AND m.catalog_track_id=s.catalog_track_id
        JOIN artist_catalog_tracks_v181 c ON c.id=m.catalog_track_id AND c.workspace_id=m.workspace_id
        LEFT JOIN artist_catalog_albums_v181 a ON a.id=c.album_id AND a.workspace_id=c.workspace_id ';
    $projection='m.recording_id,c.id AS catalog_track_id,c.title,a.id AS album_id,a.title AS album_title';
    foreach(['native','provider','legacy'] as $channel){
        if($availability[$channel]!=='available'||($f['channel']!=='all'&&$f['channel']!==$channel)||($f['site_id']!==''&&$channel!=='provider')||($channel==='legacy'&&$f['traffic']!=='all'))continue;
        $args=[$workspaceId];$where=' WHERE s.workspace_id=?';
        if($channel==='legacy'){
            $nativeIdKnown=in_array('session_id',music_analytics_v1_columns($pdo,'music_listening_sessions_v1'),true);
            $where=' WHERE m.workspace_id=? AND s.started_at>=? AND s.started_at<?';
            $args[] = gmdate('Y-m-d H:i:s',intdiv($f['start_ms'],1000));$args[]=gmdate('Y-m-d H:i:s',intdiv(min($f['end_exclusive_ms'],$now+1000),1000));
            $where.=' AND NOT EXISTS (SELECT 1 FROM music_recordings_v1 other WHERE other.source_track_id=m.source_track_id AND other.recording_id<>m.recording_id)';
            $where.=' AND NOT EXISTS (SELECT 1 FROM artist_catalog_tracks_v181 other_catalog WHERE other_catalog.source_track_id=m.source_track_id AND other_catalog.id<>m.catalog_track_id)';
            if($nativeIdKnown)$where.=' AND NOT EXISTS (SELECT 1 FROM music_listening_sessions_v1 native_row WHERE native_row.session_id=s.id)';
            $from=' FROM music_recordings_v1 m JOIN artist_catalog_tracks_v181 c ON c.id=m.catalog_track_id AND c.workspace_id=m.workspace_id AND c.source_track_id=m.source_track_id
                JOIN tracks backing ON backing.id=m.source_track_id AND backing.workspace_id=m.workspace_id
                JOIN track_play_sessions s ON s.track_id=m.source_track_id LEFT JOIN artist_catalog_albums_v181 a ON a.id=c.album_id AND a.workspace_id=c.workspace_id';
            $select=$projection.',s.started_at,s.listened_seconds';
        }else{
            $where.=' AND s.created_ms>=? AND s.created_ms<?';$args[]=$f['start_ms'];$args[]=min($f['end_exclusive_ms'],$now+1);
            $testClasses=$channel==='native'?"('owner_or_manager_preview','authorized_private')":"('staging_test')";
            if($f['traffic']==='tests')$where.=' AND s.listen_class IN '.$testClasses;
            if($f['traffic']==='audience')$where.=$channel==='native'?" AND s.listen_class='audience'":" AND s.listen_class='public_anonymous_unclassified'";
            $select=$projection.',s.created_ms,s.accepted_played_ms,s.duration_ms,s.state,s.expires_ms,s.clipped,s.muted_seen,s.loop_seen,s.listen_class';
            if($channel==='native'){
                $select.=',s.last_seq,s.source_url,s.source_provenance,s.source_context,s.metric_version';$from=' FROM music_listening_sessions_v1 s'.$baseJoin;
            }else{
                $select.=',s.started_ms,s.site_id,s.source_json,s.emitted_bytes,s.reserved_bytes,s.media_requests';$from=' FROM music_provider_sessions_v1 s'.$baseJoin.' JOIN music_provider_sites_v1 site_scope ON site_scope.site_id=s.site_id AND site_scope.workspace_id=s.workspace_id ';
                if($f['site_id']!==''){$where.=' AND s.site_id=?';$args[]=$f['site_id'];}
            }
        }
        if($f['recording_id']!==''){$where.=' AND m.recording_id=?';$args[]=$f['recording_id'];}
        if($f['album_id']){$where.=' AND a.id=?';$args[]=$f['album_id'];}
        $remaining=MUSIC_ANALYTICS_V1_MAX_ROWS-$processed;
        $q=$pdo->prepare('SELECT '.$select.$from.$where.' LIMIT '.($remaining+1));$q->execute($args);
        while($row=$q->fetch(PDO::FETCH_ASSOC)){
            if(++$processed>MUSIC_ANALYTICS_V1_MAX_ROWS)music_analytics_v1_error('report_window_too_large',422,'This report contains more than 25,000 sessions. Choose a shorter period or a song or site filter.');
            $metrics=music_analytics_v1_metrics();$siteId=null;$class='legacy_unclassified';
            if($channel==='legacy'){
                $stamp=DateTimeImmutable::createFromFormat('!Y-m-d H:i:s',(string)$row['started_at'],new DateTimeZone('UTC'));
                if(!$stamp||$stamp->format('Y-m-d H:i:s')!==$row['started_at'])music_analytics_v1_error('evidence_unavailable',503,'Some listening dates need repair before this report can be shown.');
                $created=$stamp->getTimestamp()*1000;$metrics['legacy_sessions']=1;$metrics['legacy_listening_ms']=(int)round(max(0,(float)$row['listened_seconds'])*1000);
            }else{
                if($channel==='native'&&$row['metric_version']!==MUSIC_LISTENING_V1_METRIC)music_analytics_v1_error('unsupported_metric',503,'This report needs support for the stored listening metric version.');
                $created=(int)$row['created_ms'];$class=$row['listen_class'];$played=max(0,(int)$row['accepted_played_ms']);
                $started=$channel==='native'?(int)$row['last_seq']>0:$row['started_ms']!==null;
                $duration=$row['duration_ms']!==null&&(int)$row['duration_ms']>0?(int)$row['duration_ms']:null;
                $metrics['playback_requests']=1;$metrics['starts']=$started?1:0;$metrics['listening_ms']=$played;$metrics['qualified_plays']=$played>=10000?1:0;
                $metrics['completion_known_plays']=$started&&$duration!==null?1:0;$metrics['completed_plays']=$started&&$duration!==null&&$played>=$duration*0.8?1:0;
                $coverage['partial_sessions']+=(int)!empty($row['clipped']);$coverage['muted_sessions']+=(int)!empty($row['muted_seen']);$coverage['loop_sessions']+=(int)!empty($row['loop_seen']);
                $coverage['unknown_duration_sessions']+=(int)($duration===null);
                $coverage['ongoing_sessions']+=(int)(!in_array($row['state'],['ended','stopped','withdrawn','revoked'],true)&&(int)$row['expires_ms']>$now);
                if($channel==='provider'){$siteId=$row['site_id'];$metrics['emitted_bytes']=max(0,(int)$row['emitted_bytes']);$metrics['reserved_bytes']=max(0,(int)$row['reserved_bytes']);$metrics['media_requests']=max(0,(int)$row['media_requests']);}
            }
            $coverage[$channel.'_sessions']++;$coverage['preview_sessions']+=(int)($class==='owner_or_manager_preview');
            $coverage['private_sessions']+=(int)($class==='authorized_private');$coverage['staging_sessions']+=(int)($class==='staging_test');
            $source=music_analytics_v1_source($row,$channel);$coverage['unknown_page_sessions']+=(int)($source['url']===null);
            $date=(new DateTimeImmutable('@'.intdiv($created,1000)))->setTimezone($timezone)->format('Y-m-d');
            music_analytics_v1_add($summary,$metrics);music_analytics_v1_add($daily[$date],$metrics);
            $trackKey=$row['recording_id'];
            if(!isset($tracks[$trackKey]))$tracks[$trackKey]=['recording_id'=>$trackKey,'catalog_track_id'=>(int)$row['catalog_track_id'],
                'title'=>music_analytics_v1_text($row['title']),'album_id'=>$row['album_id']===null?null:(int)$row['album_id'],'album_title'=>music_analytics_v1_text($row['album_title'])]+music_analytics_v1_metrics();
            music_analytics_v1_add($tracks[$trackKey],$metrics);
            $albumKey=$row['album_id']===null?'none':(string)$row['album_id'];
            if(!isset($albums[$albumKey]))$albums[$albumKey]=['album_id'=>$row['album_id']===null?null:(int)$row['album_id'],'title'=>$row['album_id']===null?'No release':music_analytics_v1_text($row['album_title'])]+music_analytics_v1_metrics();
            music_analytics_v1_add($albums[$albumKey],$metrics);
            $pageKey=json_encode([$siteId,$source['url'],$source['provenance']]);
            if(!isset($pages[$pageKey]))$pages[$pageKey]=['url'=>$source['url'],'site_id'=>$siteId,'provenance'=>$source['provenance']]+music_analytics_v1_metrics();
            music_analytics_v1_add($pages[$pageKey],$metrics);
            if(!isset($surfaces[$source['surface']]))$surfaces[$source['surface']]=['surface'=>$source['surface']]+music_analytics_v1_metrics();
            music_analytics_v1_add($surfaces[$source['surface']],$metrics);
            if($siteId!==null)music_analytics_v1_add($sites[$siteId],$metrics);
        }
        if($channel==='provider'&&$availability['delivery']==='available'){
            $delivery=$pdo->prepare('SELECT COUNT(*)'.$from.' JOIN music_provider_delivery_v1 d ON d.session_id=s.session_id '.$where.' AND (d.finished_ms IS NULL OR d.emitted_bytes IS NULL)');
            $delivery->execute($args);$coverage['unfinished_delivery_requests']=(int)$delivery->fetchColumn();
        }
    }
    foreach($availability as $source=>$state)if($state==='unavailable')$coverage['notes'][]=$source==='delivery'
        ?'Delivery completion coverage is unavailable until its upgrade is installed.'
        :ucfirst($source).' listening is unavailable until its upgrade is installed; totals cover the available sources only.';
    $coverage['notes'][]='Dates select sessions requested in the period. Listening time is each selected session’s cumulative accepted time as of this report; it is not time allocated to calendar-day intervals.';
    $coverage['notes'][]='Anonymous production sessions are unclassified. Session counts do not identify unique people or prove human attention.';
    $coverage['notes'][]='Emitted bytes are acknowledged server delivery accounting, not bytes heard or received by a listener. Reservations can remain consumed after interruption.';
    if($coverage['unfinished_delivery_requests'])$coverage['notes'][]='Some delivery requests have unfinished accounting; emitted-byte totals are incomplete.';
    if($f['traffic']==='audience')$coverage['notes'][]='Known previews, private sessions, staging tests and unclassified legacy history are excluded.';
    if($coverage['ongoing_sessions'])$coverage['notes'][]='Active-session totals remain provisional and can change when later events are acknowledged.';
    if(!$catalog['readiness_complete'])$coverage['notes'][]='Catalog playback readiness checks cover the first 100 songs; open the library to review the remainder.';
    $pagination=[];$groups=[];
    foreach(['tracks'=>$tracks,'albums'=>$albums,'pages'=>$pages,'surfaces'=>$surfaces] as $key=>$rows){$paged=music_analytics_v1_page($rows,$f['offset'],$f['limit']);$groups[$key]=$paged['items'];$pagination[$key]=$paged['pagination'];}
    if($f['site_id']!=='')$sites=[$f['site_id']=>$sites[$f['site_id']]];
    return ['ok'=>true,'protocol'=>MUSIC_ANALYTICS_V1_PROTOCOL,'workspace'=>['id'=>$workspaceId,'name'=>music_analytics_v1_text($workspace['workspace_name'])],
        'generated_at'=>gmdate('c',intdiv($now,1000)),
        'period'=>array_intersect_key($f,array_flip(['start_date','end_date','timezone','start_ms','end_exclusive_ms']))+['basis'=>'session_start_cohort_v1','label'=>'Sessions begun in the selected dates; cumulative accepted totals'],
        'filters'=>array_intersect_key($f,array_flip(['period','channel','traffic','recording_id','album_id','site_id','limit','offset'])),
        'summary'=>music_analytics_v1_finish($summary),'daily'=>array_map('music_analytics_v1_finish',array_values($daily))]+$groups+
        ['sites'=>array_map('music_analytics_v1_finish',array_values($sites)),'coverage'=>$coverage,'catalog'=>$catalog,
        'options'=>['recordings'=>$recordingOptions,'sites'=>$siteOptions,'albums'=>$albumOptions,'truncated'=>count($catalogRows)>500||count($albumRows)>500,'recordings_total'=>$catalogCount],
        'pagination'=>$pagination,'availability'=>$availability,'financial'=>['earnings'=>'not_configured','payments'=>'not_configured']];
}
