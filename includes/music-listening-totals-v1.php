<?php
declare(strict_types=1);

/** ML3A: all retained, safely associated measurements; no writes or lifetime counters. */
require_once __DIR__.'/music-analytics-v1.php';

const MUSIC_LISTENING_TOTALS_V1_PROTOCOL = 'vp3_music_listening_totals_v1';
const MUSIC_LISTENING_TOTALS_V1_MAX_INTEGER = 9007199254740991;

function music_listening_totals_v1_filters(array $input): array
{
    $allowed=['protocol','action','csrf_token','scope','workspace_id','channel','traffic','group','limit','offset'];
    if(array_diff(array_keys($input),$allowed)||($input['protocol']??null)!==MUSIC_LISTENING_TOTALS_V1_PROTOCOL||($input['action']??null)!=='report')
        music_analytics_v1_error('invalid_request',422,'Use the supported lifetime listening report.');
    $scope=$input['scope']??'workspace';$channel=$input['channel']??'all';$traffic=$input['traffic']??'audience';
    $group=$input['group']??($scope==='platform'?'workspaces':'tracks');
    if(!in_array($scope,['workspace','platform'],true)||!in_array($channel,['all','native','provider'],true)
        ||!in_array($traffic,['audience','all','tests'],true)||!in_array($group,['tracks','workspaces'],true)||($scope==='workspace'&&$group!=='tracks'))
        music_analytics_v1_error('invalid_request',422,'Choose a supported scope, source and grouping.');
    return ['scope'=>$scope,'workspace_id'=>music_analytics_v1_integer($input['workspace_id']??0,$scope==='workspace'?1:0,2147483647),
        'channel'=>$channel,'traffic'=>$traffic,'group'=>$group,'limit'=>music_analytics_v1_integer($input['limit']??25,1,100),
        'offset'=>music_analytics_v1_integer($input['offset']??0,0,MUSIC_LISTENING_TOTALS_V1_MAX_INTEGER)];
}

/** Production permission helpers read their own db(); never authorize another connection. */
function music_listening_totals_v1_can_view_platform(PDO $pdo,array $user): bool
{
    if(db()!==$pdo)return false;
    $q=$pdo->prepare('SELECT * FROM users WHERE id=? AND is_active=1');$q->execute([(int)($user['id']??0)]);
    $current=$q->fetch(PDO::FETCH_ASSOC);
    return $current && has_permission('listening.view',$current);
}

function music_listening_totals_v1_authorize(PDO $pdo,array $user,array $filters): void
{
    if($filters['scope']==='platform'){
        if(!music_listening_totals_v1_can_view_platform($pdo,$user))
            music_analytics_v1_error('platform_unavailable',403,'Current listening report permission is required.');
    }elseif(!music_analytics_v1_can_view($pdo,$user,$filters['workspace_id']))
        music_analytics_v1_error('workspace_unavailable',403,'A current workspace owner or manager can view this report.');
}

/** Accept exact DB integer/DECIMAL results, never a rounded JSON counter. */
function music_listening_totals_v1_integer(mixed $value): int
{
    if(is_int($value)&&$value>=0&&$value<=MUSIC_LISTENING_TOTALS_V1_MAX_INTEGER)return $value;
    // SQLite TOTAL sums nonnegative integral values exactly while the result is
    // within this range, and cannot throw an intermediate signed SUM overflow.
    if(is_float($value)&&is_finite($value)&&$value>=0&&$value<=MUSIC_LISTENING_TOTALS_V1_MAX_INTEGER&&floor($value)===$value)return (int)$value;
    if(is_string($value)&&preg_match('/^([0-9]+)(?:\.0+)?$/D',$value,$match)){
        $digits=ltrim($match[1],'0');$digits=$digits===''?'0':$digits;$max=(string)MUSIC_LISTENING_TOTALS_V1_MAX_INTEGER;
        if(strlen($digits)<strlen($max)||(strlen($digits)===strlen($max)&&strcmp($digits,$max)<=0))return (int)$digits;
    }
    music_analytics_v1_error('numeric_range_exceeded',422,'These retained totals exceed the exact numeric range. Choose a narrower workspace or source.');
}

function music_listening_totals_v1_sum(PDO $pdo,string $expression): string
{ return $pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite'?'TOTAL('.$expression.')':'COALESCE(SUM('.$expression.'),0)'; }

function music_listening_totals_v1_query(PDO $pdo,string $sql,array $args=[]): array
{ $q=$pdo->prepare($sql);$q->execute($args);return $q->fetch(PDO::FETCH_ASSOC)?:[]; }

function music_listening_totals_v1_metrics(array $row): array
{
    $metrics=music_analytics_v1_metrics();
    foreach($metrics as $key=>$value)if($key!=='completion_percent')$metrics[$key]=music_listening_totals_v1_integer($row[$key]??0);
    return music_analytics_v1_finish($metrics);
}

function music_listening_totals_v1_add(array &$target,array $values): void
{
    foreach($target as $key=>$value)if($key!=='completion_percent'){
        $increment=$values[$key];
        if($value>MUSIC_LISTENING_TOTALS_V1_MAX_INTEGER-$increment)
            music_analytics_v1_error('numeric_range_exceeded',422,'These retained totals exceed the exact numeric range. Choose a narrower workspace or source.');
        $target[$key]+=$increment;
    }
}

function music_listening_totals_v1_report(PDO $pdo,array $user,array $input,array $context=[]): array
{
    $now=$context['now_ms']??(int)floor(microtime(true)*1000);
    if(!is_int($now)||$now<1||$now>MUSIC_LISTENING_TOTALS_V1_MAX_INTEGER)throw new LogicException('Invalid report clock.');
    $filters=music_listening_totals_v1_filters($input);
    if($pdo->inTransaction())music_analytics_v1_error('transaction_boundary_required',409,'Open the report outside an existing transaction.');
    music_listening_totals_v1_authorize($pdo,$user,$filters);
    if($pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'){
        $pdo->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        $pdo->exec('START TRANSACTION WITH CONSISTENT SNAPSHOT, READ ONLY');
    }else $pdo->beginTransaction();
    try{
        music_listening_totals_v1_authorize($pdo,$user,$filters);
        $report=music_listening_totals_v1_snapshot($pdo,$filters,$now);
        $pdo->commit();
    }catch(Throwable $error){if($pdo->inTransaction())$pdo->rollBack();throw $error;}
    music_listening_totals_v1_authorize($pdo,$user,$filters);
    return $report;
}

/** Only retained canonical associations establish song/workspace ownership. */
function music_listening_totals_v1_join(string $channel): string
{
    $join=' JOIN music_recordings_v1 m ON m.recording_id=s.recording_id AND m.workspace_id=s.workspace_id AND m.catalog_track_id=s.catalog_track_id
        JOIN artist_catalog_tracks_v181 c ON c.id=m.catalog_track_id AND c.workspace_id=m.workspace_id
        JOIN artist_workspaces_v181 w ON w.id=m.workspace_id';
    if($channel==='provider')$join.=' JOIN music_provider_sites_v1 site_scope ON site_scope.site_id=s.site_id AND site_scope.workspace_id=s.workspace_id';
    return $join;
}

function music_listening_totals_v1_traffic(string $channel,string $traffic): string
{
    if($traffic==='audience')return $channel==='native'?" AND s.listen_class='audience'":" AND s.listen_class='public_anonymous_unclassified'";
    if($traffic==='tests')return $channel==='native'?" AND s.listen_class IN ('owner_or_manager_preview','authorized_private')":" AND s.listen_class='staging_test'";
    return '';
}

/** Legacy DATETIME/TEXT has no portable epoch column; validate before lexical UTC cutoff. */
function music_listening_totals_v1_invalid_legacy_date(PDO $pdo): string
{
    $valid=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite'
        ? "typeof(s.started_at)='text' AND length(s.started_at)=19 AND substr(s.started_at,1,4)>='0001' AND strftime('%Y-%m-%d %H:%M:%S',s.started_at,'+0 seconds')=s.started_at"
        : "s.started_at IS NOT NULL AND YEAR(s.started_at)>=1 AND DAYOFMONTH(s.started_at)>=1 AND DAYOFMONTH(s.started_at)<=DAYOFMONTH(LAST_DAY(s.started_at)) AND DATE_FORMAT(s.started_at,'%Y-%m-%d %H:%i:%s')=CAST(s.started_at AS CHAR)";
    return 'CASE WHEN '.$valid.' THEN 0 ELSE 1 END';
}

/** SQL expressions are server aggregates, never fetched session rows. */
function music_listening_totals_v1_source(PDO $pdo,array $f,string $channel,int $now,bool $nativeIds): array
{
    $metrics=array_fill_keys(array_diff(array_keys(music_analytics_v1_metrics()),['completion_percent']),'0');
    $coverage=array_fill_keys(['partial_sessions','unknown_duration_sessions','ongoing_sessions','muted_sessions','loop_sessions','preview_sessions','private_sessions','staging_sessions'],'0');
    $args=[];$where=' WHERE 1=1';
    if($channel==='legacy'){
        $from=' FROM track_play_sessions s JOIN tracks backing ON backing.id=s.track_id
            JOIN music_recordings_v1 m ON m.source_track_id=s.track_id AND m.workspace_id=backing.workspace_id
            JOIN artist_catalog_tracks_v181 c ON c.id=m.catalog_track_id AND c.workspace_id=m.workspace_id AND c.source_track_id=m.source_track_id
            JOIN artist_workspaces_v181 w ON w.id=m.workspace_id';
        $where.=' AND NOT EXISTS (SELECT 1 FROM music_recordings_v1 other WHERE other.source_track_id=m.source_track_id AND other.recording_id<>m.recording_id)
            AND NOT EXISTS (SELECT 1 FROM artist_catalog_tracks_v181 other_catalog WHERE other_catalog.source_track_id=m.source_track_id AND other_catalog.id<>m.catalog_track_id)';
        if($nativeIds)$where.=' AND NOT EXISTS (SELECT 1 FROM music_listening_sessions_v1 native_row WHERE native_row.session_id=s.id)';
        $invalidDate=music_listening_totals_v1_invalid_legacy_date($pdo);
        // Keep invalid rows in the aggregate so the error is explicit, rather
        // than turning missing/malformed dates into a believable zero.
        $where.=' AND (s.started_at<=? OR '.$invalidDate.'=1)';$args[]=gmdate('Y-m-d H:i:s',intdiv($now,1000));
        $metrics['legacy_sessions']='1';$metrics['legacy_listening_ms']='ROUND(CASE WHEN s.listened_seconds>0 THEN s.listened_seconds*1000 ELSE 0 END,0)';
        $invalid='s.listened_seconds IS NULL OR s.listened_seconds>'.MUSIC_LISTENING_TOTALS_V1_MAX_INTEGER.'/1000.0 OR '.$invalidDate.'=1';
        if($pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite')$invalid.=" OR typeof(s.listened_seconds) NOT IN ('integer','real')";
        $created='NULL';$unsupported='0';
    }else{
        $table=$channel==='native'?'music_listening_sessions_v1':'music_provider_sessions_v1';
        $from=' FROM '.$table.' s'.music_listening_totals_v1_join($channel);
        $where.=' AND s.created_ms<=?'.music_listening_totals_v1_traffic($channel,$f['traffic']);$args[]=$now;
        $played='CASE WHEN s.accepted_played_ms>0 THEN s.accepted_played_ms ELSE 0 END';
        $started=$channel==='native'?'s.last_seq>0':'s.started_ms IS NOT NULL';
        $known='s.duration_ms IS NOT NULL AND s.duration_ms>0';
        $fifth=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite'?'CAST(s.duration_ms/5 AS INTEGER)':'(s.duration_ms DIV 5)';
        $metrics['playback_requests']='1';$metrics['starts']='CASE WHEN '.$started.' THEN 1 ELSE 0 END';
        $metrics['qualified_plays']='CASE WHEN s.accepted_played_ms>=10000 THEN 1 ELSE 0 END';$metrics['listening_ms']=$played;
        $metrics['completion_known_plays']='CASE WHEN '.$started.' AND '.$known.' THEN 1 ELSE 0 END';
        // ceil(4*duration/5) == duration-floor(duration/5), without float or multiplication overflow.
        $metrics['completed_plays']='CASE WHEN '.$started.' AND '.$known.' AND s.accepted_played_ms>=s.duration_ms-'.$fifth.' THEN 1 ELSE 0 END';
        foreach(['partial_sessions'=>'clipped','muted_sessions'=>'muted_seen','loop_sessions'=>'loop_seen'] as $key=>$column)$coverage[$key]='CASE WHEN s.'.$column.'<>0 THEN 1 ELSE 0 END';
        $coverage['unknown_duration_sessions']='CASE WHEN '.$known.' THEN 0 ELSE 1 END';
        $coverage['ongoing_sessions']="CASE WHEN s.state NOT IN ('ended','stopped','withdrawn','revoked') AND s.expires_ms>".$now.' THEN 1 ELSE 0 END';
        foreach(['preview_sessions'=>'owner_or_manager_preview','private_sessions'=>'authorized_private','staging_sessions'=>'staging_test'] as $key=>$class)$coverage[$key]="CASE WHEN s.listen_class='".$class."' THEN 1 ELSE 0 END";
        $invalid='s.created_ms<1 OR s.accepted_played_ms IS NULL OR s.accepted_played_ms>'.MUSIC_LISTENING_TOTALS_V1_MAX_INTEGER.' OR s.duration_ms>'.MUSIC_LISTENING_TOTALS_V1_MAX_INTEGER;
        $integers=['created_ms','accepted_played_ms','expires_ms'];
        if($channel==='provider')foreach(['emitted_bytes','reserved_bytes','media_requests'] as $key){
            $metrics[$key]='CASE WHEN s.'.$key.'>0 THEN s.'.$key.' ELSE 0 END';
            $invalid.=' OR s.'.$key.' IS NULL OR s.'.$key.'>'.MUSIC_LISTENING_TOTALS_V1_MAX_INTEGER;$integers[]=$key;
        }
        if($pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite'){
            foreach($integers as $column)$invalid.=" OR typeof(s.".$column.")<>'integer'";
            $invalid.=" OR (s.duration_ms IS NOT NULL AND typeof(s.duration_ms)<>'integer')";
        }
        $created='s.created_ms';$unsupported=$channel==='native'?"CASE WHEN s.metric_version='".MUSIC_LISTENING_V1_METRIC."' THEN 0 ELSE 1 END":'0';
    }
    if($f['workspace_id']){$where.=' AND m.workspace_id=?';$args[]=$f['workspace_id'];}
    return compact('from','where','args','metrics','coverage','created','unsupported')+['invalid'=>'CASE WHEN '.$invalid.' THEN 1 ELSE 0 END'];
}

function music_listening_totals_v1_aggregate(PDO $pdo,array $source,bool $grouped=false): string
{
    $select=[];
    if($grouped)$select=['m.recording_id','m.workspace_id'];
    foreach($source['metrics'] as $key=>$expression)$select[]=music_listening_totals_v1_sum($pdo,$expression).' AS '.$key;
    if(!$grouped){
        foreach($source['coverage'] as $key=>$expression)$select[]=music_listening_totals_v1_sum($pdo,$expression).' AS '.$key;
        $select[]=music_listening_totals_v1_sum($pdo,$source['invalid']).' AS invalid_values';
        $select[]=music_listening_totals_v1_sum($pdo,$source['unsupported']).' AS unsupported_metrics';
        $select[]='MIN('.$source['created'].') AS first_ms';$select[]='MAX('.$source['created'].') AS last_ms';
    }
    return 'SELECT '.implode(',',$select).$source['from'].$source['where'].($grouped?' GROUP BY m.recording_id,m.workspace_id':'');
}

/** Count omitted evidence once, without disclosing any sessions or inferred owner. */
function music_listening_totals_v1_gaps(PDO $pdo,array $f,string $channel,int $now): array
{
    $table=$channel==='native'?'music_listening_sessions_v1':'music_provider_sessions_v1';
    $match='EXISTS (SELECT 1 FROM music_recordings_v1 m JOIN artist_catalog_tracks_v181 c ON c.id=m.catalog_track_id AND c.workspace_id=m.workspace_id
        JOIN artist_workspaces_v181 w ON w.id=m.workspace_id WHERE m.recording_id=s.recording_id AND m.workspace_id=s.workspace_id AND m.catalog_track_id=s.catalog_track_id)';
    if($channel==='provider')$match.=' AND EXISTS (SELECT 1 FROM music_provider_sites_v1 site_scope WHERE site_scope.site_id=s.site_id AND site_scope.workspace_id=s.workspace_id)';
    $classes=$channel==='native'?"('audience','owner_or_manager_preview','authorized_private')":"('public_anonymous_unclassified','staging_test')";
    $where=' WHERE s.created_ms<=?';$args=[$now];
    if($f['workspace_id']){$where.=' AND s.workspace_id=?';$args[]=$f['workspace_id'];}
    $row=music_listening_totals_v1_query($pdo,'SELECT '.music_listening_totals_v1_sum($pdo,'CASE WHEN '.$match.' THEN 0 ELSE 1 END').' AS unassociated,'
        .music_listening_totals_v1_sum($pdo,'CASE WHEN s.listen_class IN '.$classes.' THEN 0 ELSE 1 END').' AS unclassified FROM '.$table.' s'.$where,$args);
    return [$channel.'_unassociated_sessions'=>music_listening_totals_v1_integer($row['unassociated']),$channel.'_unclassified_sessions'=>music_listening_totals_v1_integer($row['unclassified'])];
}

function music_listening_totals_v1_legacy_gaps(PDO $pdo,array $f,bool $nativeIds,int $now): array
{
    $mapped='EXISTS (SELECT 1 FROM music_recordings_v1 m JOIN artist_catalog_tracks_v181 c ON c.id=m.catalog_track_id AND c.workspace_id=m.workspace_id AND c.source_track_id=m.source_track_id
        JOIN artist_workspaces_v181 w ON w.id=m.workspace_id WHERE m.source_track_id=s.track_id AND m.workspace_id=backing.workspace_id)';
    $unique='(SELECT COUNT(*) FROM music_recordings_v1 m WHERE m.source_track_id=s.track_id)=1 AND (SELECT COUNT(*) FROM artist_catalog_tracks_v181 c WHERE c.source_track_id=s.track_id)=1';
    $native=$nativeIds?'EXISTS (SELECT 1 FROM music_listening_sessions_v1 native_row WHERE native_row.session_id=s.id)':'0=1';
    $where=' WHERE (s.started_at<=? OR '.music_listening_totals_v1_invalid_legacy_date($pdo).'=1)';$args=[gmdate('Y-m-d H:i:s',intdiv($now,1000))];
    if($f['workspace_id']){$where.=' AND backing.workspace_id=?';$args[]=$f['workspace_id'];}
    $row=music_listening_totals_v1_query($pdo,'SELECT '
        .music_listening_totals_v1_sum($pdo,'CASE WHEN NOT ('.$native.') AND '.$mapped.' AND NOT ('.$unique.') THEN 1 ELSE 0 END').' AS ambiguous,'
        .music_listening_totals_v1_sum($pdo,'CASE WHEN NOT ('.$native.') AND NOT ('.$mapped.') THEN 1 ELSE 0 END').' AS unmapped,'
        .music_listening_totals_v1_sum($pdo,'CASE WHEN '.$native.' THEN 1 ELSE 0 END').' AS backing_rows FROM track_play_sessions s LEFT JOIN tracks backing ON backing.id=s.track_id'.$where,$args);
    return ['legacy_ambiguous_sessions'=>music_listening_totals_v1_integer($row['ambiguous']),'legacy_unmapped_sessions'=>music_listening_totals_v1_integer($row['unmapped']),
        'native_backing_rows_excluded'=>music_listening_totals_v1_integer($row['backing_rows'])];
}

function music_listening_totals_v1_snapshot(PDO $pdo,array $f,int $now): array
{
    foreach(['artist_workspaces_v181'=>['id','workspace_name'],'artist_catalog_tracks_v181'=>['id','workspace_id','source_track_id','title'],
        'music_recordings_v1'=>['recording_id','workspace_id','catalog_track_id','source_track_id']] as $table=>$columns)
        if(!music_analytics_v1_has($pdo,$table,$columns))music_analytics_v1_error('schema_unavailable',503,'Install the music catalog upgrade before opening totals.');
    if($f['workspace_id']){
        $q=$pdo->prepare('SELECT id FROM artist_workspaces_v181 WHERE id=?');$q->execute([$f['workspace_id']]);
        if(!$q->fetchColumn())music_analytics_v1_error('selector_unavailable',404,'This workspace is unavailable.');
    }
    $nativeColumns=music_analytics_v1_columns($pdo,'music_listening_sessions_v1');$nativeIds=in_array('session_id',$nativeColumns,true);
    $availability=[
        'native'=>!array_diff(['session_id','workspace_id','catalog_track_id','recording_id','created_ms','accepted_played_ms','duration_ms','last_seq','state','expires_ms','clipped','muted_seen','loop_seen','listen_class','metric_version'],$nativeColumns)?'available':'unavailable',
        'provider'=>music_analytics_v1_has($pdo,'music_provider_sessions_v1',['session_id','site_id','workspace_id','catalog_track_id','recording_id','created_ms','accepted_played_ms','duration_ms','started_ms','state','expires_ms','clipped','muted_seen','loop_seen','listen_class','emitted_bytes','reserved_bytes','media_requests'])
            &&music_analytics_v1_has($pdo,'music_provider_sites_v1',['site_id','workspace_id'])?'available':'unavailable',
        'legacy'=>music_analytics_v1_has($pdo,'track_play_sessions',['id','track_id','started_at','listened_seconds'])&&music_analytics_v1_has($pdo,'tracks',['id','workspace_id'])&&$nativeIds?'available':'unavailable'];
    foreach(['native','provider'] as $channel)if($f['channel']!=='all'&&$f['channel']!==$channel)$availability[$channel]='not_selected';
    if($f['channel']!=='all'||$f['traffic']!=='all')$availability['legacy']='not_selected';
    if(($f['channel']!=='all'&&$availability[$f['channel']]!=='available')||($availability['native']!=='available'&&$availability['provider']!=='available'))
        music_analytics_v1_error('source_unavailable',503,'The selected listening measurement sources are unavailable.');
    $coverage=['native_sessions'=>null,'provider_sessions'=>null,'legacy_sessions'=>null,'partial_sessions'=>0,'unknown_duration_sessions'=>0,
        'ongoing_sessions'=>0,'muted_sessions'=>0,'loop_sessions'=>0,'preview_sessions'=>0,'private_sessions'=>0,'staging_sessions'=>0,
        'native_unassociated_sessions'=>null,'provider_unassociated_sessions'=>null,'native_unclassified_sessions'=>null,'provider_unclassified_sessions'=>null,
        'legacy_ambiguous_sessions'=>null,'legacy_unmapped_sessions'=>null,'native_backing_rows_excluded'=>null,
        'missing_sources'=>array_keys(array_filter($availability,static fn($status)=>$status==='unavailable')),'legacy_included'=>$availability['legacy']==='available',
        'listener_identity'=>'not_available','notes'=>[]];
    $sources=[];$channels=['native'=>null,'provider'=>null,'legacy'=>null];$summary=music_analytics_v1_metrics();$first=null;$last=null;
    foreach($availability as $channel=>$state){
        if($state!=='available')continue;
        $source=music_listening_totals_v1_source($pdo,$f,$channel,$now,$nativeIds);$sources[$channel]=$source;
        $row=music_listening_totals_v1_query($pdo,music_listening_totals_v1_aggregate($pdo,$source),$source['args']);
        if(music_listening_totals_v1_integer($row['unsupported_metrics']))music_analytics_v1_error('unsupported_metric',503,'Some retained sessions use an unsupported listening metric.');
        if(music_listening_totals_v1_integer($row['invalid_values']))music_analytics_v1_error('evidence_unavailable',503,'Some retained measurements need repair before totals can be shown.');
        $channels[$channel]=music_listening_totals_v1_metrics($row);music_listening_totals_v1_add($summary,$channels[$channel]);
        $coverage[$channel.'_sessions']=$channels[$channel][$channel==='legacy'?'legacy_sessions':'playback_requests'];
        foreach($source['coverage'] as $key=>$unused){$value=music_listening_totals_v1_integer($row[$key]);
            if($coverage[$key]>MUSIC_LISTENING_TOTALS_V1_MAX_INTEGER-$value)music_analytics_v1_error('numeric_range_exceeded',422,'Coverage exceeds the exact numeric range.');$coverage[$key]+=$value;}
        if($row['first_ms']!==null){$stamp=music_listening_totals_v1_integer($row['first_ms']);$first=$first===null?$stamp:min($first,$stamp);}
        if($row['last_ms']!==null){$stamp=music_listening_totals_v1_integer($row['last_ms']);$last=$last===null?$stamp:max($last,$stamp);}
        $coverage=array_replace($coverage,$channel==='legacy'?music_listening_totals_v1_legacy_gaps($pdo,$f,$nativeIds,$now):music_listening_totals_v1_gaps($pdo,$f,$channel,$now));
    }
    $scopeWhere=$f['workspace_id']?' AND c.workspace_id=?':'';$scopeArgs=$f['workspace_id']?[$f['workspace_id']]:[];
    $row=music_listening_totals_v1_query($pdo,'SELECT COUNT(*) AS missing FROM artist_catalog_tracks_v181 c WHERE NOT EXISTS
        (SELECT 1 FROM music_recordings_v1 m WHERE m.catalog_track_id=c.id AND m.workspace_id=c.workspace_id)'.$scopeWhere,$scopeArgs);
    $coverage['catalog_tracks_without_recordings']=music_listening_totals_v1_integer($row['missing']);
    $row=music_listening_totals_v1_query($pdo,'SELECT COUNT(*) AS missing FROM music_recordings_v1 m WHERE NOT EXISTS
        (SELECT 1 FROM artist_catalog_tracks_v181 c JOIN artist_workspaces_v181 w ON w.id=c.workspace_id WHERE c.id=m.catalog_track_id AND c.workspace_id=m.workspace_id)'
        .($f['workspace_id']?' AND m.workspace_id=?':''),$scopeArgs);
    $coverage['orphan_recordings']=music_listening_totals_v1_integer($row['missing']);
    $groups=music_listening_totals_v1_groups($pdo,$f,$sources);
    $coverage['notes'][]='Totals cover retained measured sessions with a current, unambiguous catalog association. Deleted history cannot be reconstructed; these totals do not prove complete lifetime history.';
    $coverage['notes'][]='A qualified listen is one session with at least 10 seconds of accepted playback. Requests, starts and unique people are different measures; unique people are not available.';
    $coverage['notes'][]='Association and classification gap counts describe all retained evidence in the selected workspace and sources, independently of the traffic filter.';
    if($f['traffic']==='audience')$coverage['notes'][]='Known owner previews, private listening, staging tests and legacy history without traffic classification are excluded.';
    if($f['traffic']==='tests')$coverage['notes'][]='This selection includes known owner previews, private listening and staging tests only.';
    if(($coverage['native_unclassified_sessions']??0)+($coverage['provider_unclassified_sessions']??0))$coverage['notes'][]='Some source sessions have an unknown traffic classification. They are included only when all traffic is selected.';
    if($coverage['legacy_included'])$coverage['notes'][]='Legacy sessions and their listening time are separate historical evidence. They are never added to measured starts or qualified listens.';
    foreach($coverage['missing_sources'] as $channel)$coverage['notes'][]=ucfirst($channel).' evidence is unavailable; displayed totals cover the available sources only.';
    if(($coverage['native_unassociated_sessions']??0)+($coverage['provider_unassociated_sessions']??0))$coverage['notes'][]='Some retained sessions have missing or mismatched catalog or site associations and are excluded.';
    if(($coverage['legacy_ambiguous_sessions']??0)+($coverage['legacy_unmapped_sessions']??0))$coverage['notes'][]='Legacy history with ambiguous or missing workspace/catalog mapping is excluded.';
    if($coverage['catalog_tracks_without_recordings']||$coverage['orphan_recordings'])$coverage['notes'][]='Some catalog or recording identities are missing a valid association; group rows cover safely associated recordings.';
    if($coverage['ongoing_sessions'])$coverage['notes'][]='Active-session totals are provisional until later playback events are accepted.';
    $coverage['notes'][]='Provider bytes are server delivery accounting, not listening time, royalties or payment amounts.';
    return ['ok'=>true,'protocol'=>MUSIC_LISTENING_TOTALS_V1_PROTOCOL,'filters'=>$f,'snapshot_at'=>gmdate('c',intdiv($now,1000)),
        'history'=>['basis'=>'all_retained_measured_history_v1','first_measured_at'=>$first===null?null:gmdate('c',intdiv($first,1000)),'last_measured_at'=>$last===null?null:gmdate('c',intdiv($last,1000)),'complete_lifetime'=>false],
        'availability'=>$availability,'summary'=>music_analytics_v1_finish($summary),'channels'=>$channels,'groups'=>$groups,'coverage'=>$coverage,
        'financial'=>['earnings'=>'not_configured','payments'=>'not_configured']];
}

function music_listening_totals_v1_groups(PDO $pdo,array $f,array $sources): array
{
    $parts=[];$arguments=[];
    foreach($sources as $source){$parts[]=music_listening_totals_v1_aggregate($pdo,$source,true);array_push($arguments,...$source['args']);}
    $metricKeys=array_diff(array_keys(music_analytics_v1_metrics()),['completion_percent']);$select=[];
    foreach($metricKeys as $key)$select[]=music_listening_totals_v1_sum($pdo,'source_rows.'.$key).' AS '.$key;
    $aggregate='SELECT source_rows.workspace_id'.($f['group']==='tracks'?',source_rows.recording_id':'').','.implode(',',$select)
        .' FROM ('.implode(' UNION ALL ',$parts).') source_rows GROUP BY source_rows.workspace_id'.($f['group']==='tracks'?',source_rows.recording_id':'');
    if($f['group']==='tracks'){
        $base=' FROM music_recordings_v1 m JOIN artist_catalog_tracks_v181 c ON c.id=m.catalog_track_id AND c.workspace_id=m.workspace_id JOIN artist_workspaces_v181 w ON w.id=m.workspace_id';
        $columns='m.workspace_id,w.workspace_name,m.recording_id,c.id AS catalog_track_id,c.title';$join=' ON totals.recording_id=m.recording_id AND totals.workspace_id=m.workspace_id';$order='m.recording_id';
    }else{$base=' FROM artist_workspaces_v181 w';$columns='w.id AS workspace_id,w.workspace_name';$join=' ON totals.workspace_id=w.id';$order='w.id';}
    $where=$f['workspace_id']?' WHERE w.id=?':'';$args=$f['workspace_id']?[$f['workspace_id']]:[];
    $count=music_listening_totals_v1_query($pdo,'SELECT COUNT(*) AS total'.$base.$where,$args);$total=music_listening_totals_v1_integer($count['total']);
    $items=[];
    if($f['offset']<$total){
        $project=[];foreach($metricKeys as $key)$project[]='COALESCE(totals.'.$key.',0) AS '.$key;
        $q=$pdo->prepare('SELECT '.$columns.','.implode(',',$project).$base.' LEFT JOIN ('.$aggregate.') totals'.$join.$where
            .' ORDER BY qualified_plays DESC,listening_ms DESC,'.$order.' LIMIT '.$f['limit'].' OFFSET '.$f['offset']);
        $q->execute(array_merge($arguments,$args));
        while($row=$q->fetch(PDO::FETCH_ASSOC)){
            $item=['workspace_id'=>music_listening_totals_v1_integer($row['workspace_id']),'workspace_name'=>music_analytics_v1_text($row['workspace_name'])];
            if($f['group']==='tracks')$item+=['recording_id'=>$row['recording_id'],'catalog_track_id'=>music_listening_totals_v1_integer($row['catalog_track_id']),'title'=>music_analytics_v1_text($row['title'])];
            $items[]=$item+['metrics'=>music_listening_totals_v1_metrics($row)];
        }
    }
    return ['type'=>$f['group'],'rows'=>$items,'total'=>$total,'limit'=>$f['limit'],'offset'=>$f['offset'],'has_more'=>$f['offset']<$total-count($items)];
}
