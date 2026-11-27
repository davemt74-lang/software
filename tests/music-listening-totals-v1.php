<?php
declare(strict_types=1);

// Reuse real ledger seed/copy and connection helpers; do not run the date-window suite.
if(!defined('MUSIC_ANALYTICS_TEST_LIBRARY_ONLY'))define('MUSIC_ANALYTICS_TEST_LIBRARY_ONLY',true);
require_once __DIR__.'/music-analytics-v1.php';
require_once dirname(__DIR__).'/includes/music-listening-totals-v1.php';

function music_listening_totals_test_equal(mixed $actual,mixed $expected,string $label): void
{
    $GLOBALS['musicListeningTotalsChecks']=($GLOBALS['musicListeningTotalsChecks']??0)+1;
    $same=is_array($actual)&&is_array($expected)?music_provider_v1_json($actual)===music_provider_v1_json($expected):$actual===$expected;
    if(!$same)throw new RuntimeException($label.': '.json_encode(['expected'=>$expected,'actual'=>$actual],JSON_PARTIAL_OUTPUT_ON_ERROR));
}

function music_listening_totals_test_reject(callable $operation,int $status,string $code,string $label): void
{
    try{$operation();}catch(MusicAnalyticsV1Exception $error){
        music_listening_totals_test_equal($error->httpStatus,$status,$label.' status');music_listening_totals_test_equal($error->publicCode,$code,$label.' code');return;
    }
    throw new RuntimeException($label.' unexpectedly succeeded');
}

function music_listening_totals_test_report(array $changes=[],int $userId=11): array
{
    return music_listening_totals_v1_report(db(),['id'=>$userId,'role'=>'admin'],array_replace([
        'protocol'=>'vp3_music_listening_totals_v1','action'=>'report','workspace_id'=>1,
    ],$changes),['now_ms'=>$GLOBALS['musicAnalyticsNow']]);
}

/** Many actual legacy/native rows with valid distinct session identities, no PHP source fetch. */
function music_listening_totals_test_bulk(int $source,int $count,int $created): array
{
    $pdo=db();$start=(int)$pdo->query('SELECT MAX(id) FROM track_play_sessions')->fetchColumn()+1;
    $q=$pdo->prepare('SELECT * FROM music_listening_sessions_v1 WHERE session_id=?');$q->execute([$source]);$template=$q->fetch(PDO::FETCH_ASSOC);
    $template['created_ms']=$created;$template['expires_ms']=$created+86400000;$template['last_received_ms']=$created+10000;
    $template['accepted_played_ms']=10000;$template['duration_ms']=25000;$template['clipped']=0;
    $pdo->beginTransaction();
    try{
        for($done=0;$done<$count;){
            $size=min(500,$count-$done);$values=[];$args=[];
            for($i=0;$i<$size;$i++){
                $id=$start+$done+$i;$values[]='(?,?,?,?,?)';
                array_push($args,$id,hash('sha256','totals-bulk-'.$id),str_repeat('c',64),42,gmdate('Y-m-d H:i:s',intdiv($created,1000)));
            }
            $pdo->prepare('INSERT INTO track_play_sessions (id,session_token,listener_hash,track_id,started_at) VALUES '.implode(',',$values))->execute($args);$done+=$size;
        }
        $expressions=[];$args=[];
        foreach($template as $key=>$value){
            if($key==='session_id')$expressions[]='backing.id';
            elseif($key==='play_id')$expressions[]=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite'?"printf('00000000-0000-4000-8000-%012d',backing.id)":"CONCAT('00000000-0000-4000-8000-',LPAD(backing.id,12,'0'))";
            else{$expressions[]='?';$args[]=$value;}
        }
        $args[]=$start;$args[]=$start+$count;
        $pdo->prepare('INSERT INTO music_listening_sessions_v1 ('.implode(',',array_keys($template)).') SELECT '.implode(',',$expressions)
            .' FROM track_play_sessions backing WHERE backing.id>=? AND backing.id<?')->execute($args);
        $pdo->commit();
    }catch(Throwable $error){if($pdo->inTransaction())$pdo->rollBack();throw $error;}
    return [$start,$start+$count];
}

function music_listening_totals_test_run(): void
{
    $pdo=db();$now=(new DateTimeImmutable('2026-11-27 18:00:00',new DateTimeZone('UTC')))->getTimestamp()*1000;
    $GLOBALS['musicAnalyticsNow']=$now;$seed=music_analytics_test_seed($now);
    $pdo->setAttribute(PDO::ATTR_STATEMENT_CLASS,[MusicAnalyticsAcceptanceStatement::class,[]]);
    if($pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite')$pdo->exec('PRAGMA journal_mode=WAL');
    $before=music_listening_test_snapshot();$report=music_listening_totals_test_report();
    music_listening_totals_test_equal(music_listening_test_snapshot(),$before,'Read-only report mutated stored evidence');
    music_listening_totals_test_equal($report['protocol'],'vp3_music_listening_totals_v1','Protocol');
    music_listening_totals_test_equal($report['summary'],[
        'playback_requests'=>4,'starts'=>2,'qualified_plays'=>2,'listening_ms'=>40000,'completed_plays'=>2,'completion_known_plays'=>2,
        'completion_percent'=>100.0,'legacy_sessions'=>0,'legacy_listening_ms'=>0,'emitted_bytes'=>2048,'reserved_bytes'=>4096,'media_requests'=>3,
    ],'Measured total, pending request and receipt duplicate protections');
    music_listening_totals_test_equal($report['history'],['basis'=>'all_retained_measured_history_v1','first_measured_at'=>gmdate('c',intdiv($seed['clock_ms'],1000)),
        'last_measured_at'=>gmdate('c',intdiv($now-30000,1000)),'complete_lifetime'=>false],'Observed-history semantics');
    music_listening_totals_test_equal($report['financial'],['earnings'=>'not_configured','payments'=>'not_configured'],'Financial state');
    music_listening_totals_test_equal([$report['coverage']['native_sessions'],$report['coverage']['provider_sessions'],$report['coverage']['partial_sessions'],
        $report['coverage']['unknown_duration_sessions'],$report['coverage']['ongoing_sessions']],[2,2,1,1,2],'Coverage');
    foreach(['playback_requests','starts','qualified_plays','listening_ms','completed_plays','emitted_bytes','reserved_bytes'] as $metric){
        music_listening_totals_test_equal(array_sum(array_map(static fn($r)=>$r['metrics'][$metric],$report['groups']['rows'])),$report['summary'][$metric],'Group rollup '.$metric);
        music_listening_totals_test_equal($report['channels']['native'][$metric]+$report['channels']['provider'][$metric],$report['summary'][$metric],'Channel rollup '.$metric);
    }
    $wire=json_encode($report,JSON_THROW_ON_ERROR);
    foreach(['PRIVATE','session_token','listener_hash','listener_key','playback_key','token_hash','challenge','creator_user_id','Foreign draft','Second Studio','contact_email','rights_notes','source_json','/uploads/'] as $secret)
        music_listening_totals_test_equal(str_contains($wire,$secret),false,'Aggregate leaked '.$secret);
    $all=music_listening_totals_test_report(['traffic'=>'all']);
    music_listening_totals_test_equal([$all['summary']['qualified_plays'],$all['summary']['starts'],$all['summary']['listening_ms'],$all['summary']['legacy_sessions'],$all['summary']['legacy_listening_ms']],[4,4,65000,1,45000],'Legacy backing rows re-counted or legacy relabeled');
    music_listening_totals_test_equal([$all['coverage']['preview_sessions'],$all['coverage']['staging_sessions'],$all['coverage']['muted_sessions'],$all['coverage']['loop_sessions'],$all['coverage']['native_backing_rows_excluded']],[1,1,1,1,3],'Traffic and legacy coverage');
    music_listening_totals_test_equal(music_listening_totals_test_report(['traffic'=>'tests'])['summary']['listening_ms'],25000,'Known test traffic');
    music_listening_totals_test_equal(music_listening_totals_test_report(['channel'=>'native'])['summary']['listening_ms'],20000,'Native source');
    music_listening_totals_test_equal(music_listening_totals_test_report(['channel'=>'provider'])['summary']['listening_ms'],20000,'Provider source');
    $page=music_listening_totals_test_report(['limit'=>1,'offset'=>1]);
    music_listening_totals_test_equal($page['summary'],$report['summary'],'Page changed totals');
    music_listening_totals_test_equal([$page['groups']['total'],count($page['groups']['rows']),$page['groups']['has_more']],[2,1,false],'Paged songs');
    music_listening_totals_test_equal(music_listening_totals_test_report(['limit'=>1])['groups']['has_more'],true,'First page has-more');
    music_listening_totals_test_equal(music_listening_totals_test_report(['offset'=>MUSIC_LISTENING_TOTALS_V1_MAX_INTEGER])['groups']['rows'],[],'Safe large offset');
    music_listening_totals_test_equal(music_listening_totals_test_report([],12)['summary'],$report['summary'],'Current manager');
    foreach([13,14,16,17,91] as $id)music_listening_totals_test_reject(static fn()=>music_listening_totals_test_report([],$id),403,'workspace_unavailable','No current workspace management '.$id);
    $platform=['scope'=>'platform','workspace_id'=>null,'group'=>'workspaces'];
    music_listening_totals_test_equal(music_listening_totals_test_report($platform,91)['summary'],$report['summary'],'Platform default excludes foreign private/preview traffic');
    $platformAll=music_listening_totals_test_report($platform+['traffic'=>'all'],91);
    music_listening_totals_test_equal([$platformAll['summary']['qualified_plays'],$platformAll['summary']['listening_ms'],$platformAll['groups']['total']],[5,75000,2],'Platform all-workspace total');
    music_listening_totals_test_equal(array_sum(array_map(static fn($r)=>$r['metrics']['listening_ms'],$platformAll['groups']['rows'])),75000,'Workspace group rollup');
    $selected=music_listening_totals_test_report(array_replace($platform,['workspace_id'=>2,'group'=>'tracks','traffic'=>'all']),91);
    music_listening_totals_test_equal([$selected['summary']['qualified_plays'],$selected['groups']['rows'][0]['workspace_id'],$selected['groups']['rows'][0]['catalog_track_id']],[1,2,20],'Platform workspace drilldown');
    music_listening_totals_test_equal(music_listening_totals_test_report($platform,15)['summary'],$report['summary'],'Administrator platform authority');
    foreach([11,12,16,17] as $id)music_listening_totals_test_reject(static fn()=>music_listening_totals_test_report($platform,$id),403,'platform_unavailable','Stale supplied admin role '.$id);
    $pdo->exec("DELETE FROM role_permissions WHERE role='supervisor' AND permission_key='listening.view'");
    music_listening_totals_test_reject(static fn()=>music_listening_totals_test_report($platform,91),403,'platform_unavailable','Removed listening grant');
    $pdo->exec("INSERT INTO role_permissions VALUES ('supervisor','listening.view')");
    $pdo->exec('UPDATE users SET is_active=0 WHERE id=91');
    music_listening_totals_test_reject(static fn()=>music_listening_totals_test_report($platform,91),403,'platform_unavailable','Disabled global viewer');$pdo->exec('UPDATE users SET is_active=1 WHERE id=91');
    $pdo->exec('UPDATE users SET is_active=0 WHERE id=11');
    music_listening_totals_test_reject(static fn()=>music_listening_totals_test_report([],12),403,'workspace_unavailable','Disabled workspace owner');$pdo->exec('UPDATE users SET is_active=1 WHERE id=11');
    $pdo->exec('DELETE FROM artist_team_members WHERE member_user_id=12');
    music_listening_totals_test_reject(static fn()=>music_listening_totals_test_report([],12),403,'workspace_unavailable','Removed manager');$pdo->exec("INSERT INTO artist_team_members VALUES (11,12,'manager')");
    foreach([['workspace_id'=>'1'],['workspace_id'=>0],['scope'=>'unknown'],['scope'=>[]],['group'=>'workspaces'],['channel'=>'legacy'],['traffic'=>'bogus'],['limit'=>101],['offset'=>-1],['offset'=>9007199254740992],['period'=>'all'],['source_url'=>'private']] as $input)
        music_listening_totals_test_reject(static fn()=>music_listening_totals_test_report($input),422,'invalid_request','Malformed selector');
    music_listening_totals_test_reject(static fn()=>music_listening_totals_test_report(array_replace($platform,['workspace_id'=>999]),91),404,'selector_unavailable','Unknown platform workspace');
    $pdo->beginTransaction();music_listening_totals_test_reject(static fn()=>music_listening_totals_test_report(),409,'transaction_boundary_required','Caller transaction');
    music_listening_totals_test_equal($pdo->inTransaction(),true,'Refusal closed caller transaction');$pdo->rollBack();

    // No date-window, publication, archive, current-selection or live-site restriction on historical measurements.
    $old=music_analytics_test_copy_native((int)$seed['native']['session_id'],$now-800*86400000);
    $historical=music_listening_totals_test_report();
    music_listening_totals_test_equal([$historical['summary']['qualified_plays'],$historical['history']['first_measured_at']],[3,gmdate('c',intdiv($now-800*86400000,1000))],'History over 366 days');
    $pdo->prepare('UPDATE music_listening_sessions_v1 SET created_ms=? WHERE session_id=?')->execute([$now+1,$old]);
    music_listening_totals_test_equal(music_listening_totals_test_report()['summary'],$report['summary'],'Future session included before snapshot');
    $pdo->prepare('DELETE FROM track_play_sessions WHERE id=?')->execute([$old]);
    $pdo->prepare('UPDATE track_play_sessions SET started_at=? WHERE id=7')->execute([gmdate('Y-m-d H:i:s',intdiv($now+86400000,1000))]);
    music_listening_totals_test_equal(music_listening_totals_test_report(['traffic'=>'all'])['summary']['legacy_sessions'],0,'Future legacy session included before snapshot');
    foreach([null,'not-a-date','2026-02-30 10:00:00','2026-01-01 24:00:00','2026-00-00 00:00:00'] as $date){
        $q=$pdo->prepare('SELECT '.music_listening_totals_v1_invalid_legacy_date($pdo).' AS invalid_date FROM (SELECT ? AS started_at) s');$q->execute([$date]);
        music_listening_totals_test_equal((int)$q->fetchColumn(),1,'Invalid legacy date predicate '.json_encode($date));
    }
    foreach(['2024-02-29 10:00:00','2026-11-27 18:00:00'] as $date){
        $q=$pdo->prepare('SELECT '.music_listening_totals_v1_invalid_legacy_date($pdo).' AS invalid_date FROM (SELECT ? AS started_at) s');$q->execute([$date]);
        music_listening_totals_test_equal((int)$q->fetchColumn(),0,'Valid legacy timestamp rejected');
    }
    // Native MySQL's DATETIME NOT NULL column rejects these writes itself.
    if($pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite')foreach(['not-a-date','2026-02-30 10:00:00'] as $date){
        $pdo->prepare('UPDATE track_play_sessions SET started_at=? WHERE id=7')->execute([$date]);
        music_listening_totals_test_reject(static fn()=>music_listening_totals_test_report(['traffic'=>'all']),503,'evidence_unavailable','Malformed retained legacy timestamp');
        music_listening_totals_test_equal(music_listening_totals_test_report()['summary'],$report['summary'],'Unselected legacy date fault affected audience');
    }
    $pdo->prepare('UPDATE track_play_sessions SET started_at=? WHERE id=7')->execute([gmdate('Y-m-d H:i:s',intdiv($seed['clock_ms'],1000))]);
    $pdo->prepare('UPDATE music_recordings_v1 SET archived_at=? WHERE recording_id=?')->execute(['2026-11-26 00:00:00',$seed['recordings'][10]]);
    $pdo->exec("UPDATE artist_catalog_tracks_v181 SET is_published=0,visibility='admin' WHERE id=10");
    $pdo->prepare("UPDATE music_provider_sites_v1 SET status='revoked',credential_expires_ms=1 WHERE site_id=?")->execute([$seed['production']['site_id']]);
    $pdo->prepare('DELETE FROM music_provider_selections_v1 WHERE site_id=?')->execute([$seed['production']['site_id']]);
    music_listening_totals_test_equal(music_listening_totals_test_report()['summary'],$report['summary'],'Historical accepted sessions erased by archive/revocation');
    $pdo->prepare('UPDATE music_recordings_v1 SET archived_at=NULL WHERE recording_id=?')->execute([$seed['recordings'][10]]);
    $pdo->exec("UPDATE artist_catalog_tracks_v181 SET is_published=1,visibility='public' WHERE id=10");

    // Missing, conflicting and globally ambiguous ownership never transfers historical listens.
    $pdo->exec('UPDATE tracks SET workspace_id=2 WHERE id=42');
    music_listening_totals_test_equal(music_listening_totals_test_report(['traffic'=>'all'])['summary']['legacy_sessions'],0,'Cross-workspace backing history attributed');
    $pdo->exec('UPDATE tracks SET workspace_id=1 WHERE id=42');
    $pdo->exec('UPDATE artist_catalog_tracks_v181 SET source_track_id=42 WHERE id=11');
    $ambiguous=music_listening_totals_test_report(['traffic'=>'all']);
    music_listening_totals_test_equal([$ambiguous['summary']['legacy_sessions'],$ambiguous['coverage']['legacy_ambiguous_sessions']],[0,1],'Ambiguous legacy mapping not disclosed');
    $pdo->exec('UPDATE artist_catalog_tracks_v181 SET source_track_id=43 WHERE id=11');
    $pdo->prepare('UPDATE music_recordings_v1 SET source_track_id=42 WHERE recording_id=?')->execute([$seed['recordings'][20]]);
    music_listening_totals_test_equal(music_listening_totals_test_report(['traffic'=>'all'])['summary']['legacy_sessions'],0,'Foreign canonical ambiguity guessed');
    $pdo->prepare('UPDATE music_recordings_v1 SET source_track_id=84 WHERE recording_id=?')->execute([$seed['recordings'][20]]);
    $pdo->prepare('UPDATE music_listening_sessions_v1 SET catalog_track_id=20 WHERE session_id=?')->execute([$seed['native']['session_id']]);
    $orphan=music_listening_totals_test_report();music_listening_totals_test_equal([$orphan['summary']['qualified_plays'],$orphan['coverage']['native_unassociated_sessions']],[1,1],'Native mismatched association counted or hidden');
    $pdo->prepare('UPDATE music_listening_sessions_v1 SET catalog_track_id=10 WHERE session_id=?')->execute([$seed['native']['session_id']]);
    $pdo->prepare('UPDATE music_provider_sessions_v1 SET workspace_id=2 WHERE session_id=?')->execute([$seed['provider']['session_id']]);
    $orphan=music_listening_totals_test_report($platform,91);music_listening_totals_test_equal([$orphan['summary']['qualified_plays'],$orphan['coverage']['provider_unassociated_sessions']],[1,1],'Provider mismatched association counted or hidden');
    $pdo->prepare('UPDATE music_provider_sessions_v1 SET workspace_id=1 WHERE session_id=?')->execute([$seed['provider']['session_id']]);
    $pdo->prepare('UPDATE music_provider_sites_v1 SET workspace_id=2 WHERE site_id=?')->execute([$seed['production']['site_id']]);
    $orphan=music_listening_totals_test_report();music_listening_totals_test_equal([$orphan['summary']['qualified_plays'],$orphan['coverage']['provider_unassociated_sessions']],[1,2],'Provider cross-workspace site accepted');
    $pdo->prepare('UPDATE music_provider_sites_v1 SET workspace_id=1 WHERE site_id=?')->execute([$seed['production']['site_id']]);
    $pdo->exec('UPDATE artist_catalog_tracks_v181 SET workspace_id=2 WHERE id=10');
    $orphan=music_listening_totals_test_report();music_listening_totals_test_equal([$orphan['summary']['qualified_plays'],$orphan['coverage']['orphan_recordings']],[0,1],'Retained orphan canonical association counted');
    $pdo->exec('UPDATE artist_catalog_tracks_v181 SET workspace_id=1 WHERE id=10');
    $pdo->prepare('INSERT INTO track_play_sessions (session_token,listener_hash,track_id,listened_seconds,started_at) VALUES (?,?,99,75,?)')->execute([hash('sha256','unmapped-totals'),str_repeat('e',64),gmdate('Y-m-d H:i:s',intdiv($seed['clock_ms'],1000))]);$unmapped=(int)$pdo->lastInsertId();
    music_listening_totals_test_equal(music_listening_totals_test_report($platform+['traffic'=>'all'],91)['coverage']['legacy_unmapped_sessions'],1,'Unmapped platform history undisclosed');
    music_listening_totals_test_equal(music_listening_totals_test_report(['traffic'=>'all'])['coverage']['legacy_unmapped_sessions'],0,'Unknown owner history assigned to artist');
    $pdo->prepare('DELETE FROM track_play_sessions WHERE id=?')->execute([$unmapped]);
    $pdo->prepare("UPDATE music_listening_sessions_v1 SET listen_class='future_unknown' WHERE session_id=?")->execute([$seed['native']['session_id']]);
    $unknown=music_listening_totals_test_report();music_listening_totals_test_equal([$unknown['summary']['qualified_plays'],$unknown['coverage']['native_unclassified_sessions']],[1,1],'Unknown audience classification silently included');
    $pdo->prepare("UPDATE music_listening_sessions_v1 SET listen_class='audience' WHERE session_id=?")->execute([$seed['native']['session_id']]);
    $pdo->prepare("UPDATE music_listening_sessions_v1 SET metric_version='future_metric' WHERE session_id=?")->execute([$seed['native']['session_id']]);
    music_listening_totals_test_reject(static fn()=>music_listening_totals_test_report(),503,'unsupported_metric','Unknown metric');
    music_listening_totals_test_equal(music_listening_totals_test_report(['channel'=>'provider'])['summary']['qualified_plays'],1,'Unselected unsupported native source blocked provider');
    $pdo->prepare('UPDATE music_listening_sessions_v1 SET metric_version=? WHERE session_id=?')->execute([MUSIC_LISTENING_V1_METRIC,$seed['native']['session_id']]);

    // Exact completion threshold, unknown-duration denominator and exact aggregate number boundary.
    $pdo->prepare('UPDATE music_listening_sessions_v1 SET duration_ms=6,accepted_played_ms=4 WHERE session_id=?')->execute([$seed['native']['session_id']]);
    music_listening_totals_test_equal(music_listening_totals_test_report(['channel'=>'native'])['summary']['completed_plays'],0,'Completion rounded threshold down');
    $pdo->prepare('UPDATE music_listening_sessions_v1 SET accepted_played_ms=5 WHERE session_id=?')->execute([$seed['native']['session_id']]);
    music_listening_totals_test_equal(music_listening_totals_test_report(['channel'=>'native'])['summary']['completed_plays'],1,'Integer completion threshold');
    $pdo->prepare('UPDATE music_listening_sessions_v1 SET duration_ms=25000,accepted_played_ms=? WHERE session_id=?')->execute([MUSIC_LISTENING_TOTALS_V1_MAX_INTEGER-20000,$seed['native']['session_id']]);
    music_listening_totals_test_equal(music_listening_totals_test_report()['summary']['listening_ms'],MUSIC_LISTENING_TOTALS_V1_MAX_INTEGER,'Safe exact aggregate boundary');
    $pdo->prepare('UPDATE music_listening_sessions_v1 SET accepted_played_ms=? WHERE session_id=?')->execute([MUSIC_LISTENING_TOTALS_V1_MAX_INTEGER,$seed['native']['session_id']]);
    music_listening_totals_test_reject(static fn()=>music_listening_totals_test_report(),422,'numeric_range_exceeded','Cross-source numeric overflow');
    $pdo->prepare('UPDATE music_listening_sessions_v1 SET accepted_played_ms=20000 WHERE session_id=?')->execute([$seed['native']['session_id']]);
    music_listening_totals_test_equal(music_listening_totals_v1_integer('9007199254740991.000'),MUSIC_LISTENING_TOTALS_V1_MAX_INTEGER,'Native DECIMAL exact parse');
    foreach(['9007199254740992','999999999999999999999999999999999999','1.1','1e3',-1,INF,NAN] as $number)
        music_listening_totals_test_reject(static fn()=>music_listening_totals_v1_integer($number),422,'numeric_range_exceeded','Unsafe aggregate encoding');

    // A committed writer between native/provider/group reads cannot split one report's snapshot.
    $writer=music_analytics_test_other_connection();$called=false;
    $GLOBALS['musicAnalyticsQueryHook']=static function(string $query)use($writer,$seed,&$called):void{
        if(!$called&&str_contains($query,' FROM music_provider_sessions_v1 s')){
            $called=true;$writer->prepare('UPDATE music_provider_sessions_v1 SET accepted_played_ms=30000 WHERE session_id=?')->execute([$seed['provider']['session_id']]);
        }
    };
    $snapshot=music_listening_totals_test_report();unset($GLOBALS['musicAnalyticsQueryHook']);
    music_listening_totals_test_equal($called,true,'Concurrent writer hook');music_listening_totals_test_equal($snapshot['summary']['listening_ms'],40000,'Mixed source snapshots');
    music_listening_totals_test_equal($snapshot['groups']['rows'][0]['metrics']['listening_ms'],40000,'Mixed summary/group snapshots');
    music_listening_totals_test_equal(music_listening_totals_test_report()['summary']['listening_ms'],50000,'Next snapshot missed committed update');
    $writer->prepare('UPDATE music_provider_sessions_v1 SET accepted_played_ms=20000 WHERE session_id=?')->execute([$seed['provider']['session_id']]);
    foreach(['workspace','platform'] as $scope){
        $inside=false;$removed=false;
        $GLOBALS['musicAnalyticsQueryHook']=static function(string $query)use($scope,&$inside,&$removed):void{
            if(db()->inTransaction())$inside=true;
            elseif($inside&&str_starts_with($query,'SELECT * FROM users WHERE id=')){
                unset($GLOBALS['musicAnalyticsQueryHook']);
                if($scope==='workspace')db()->exec('DELETE FROM artist_team_members WHERE member_user_id=12');
                else db()->exec("DELETE FROM role_permissions WHERE role='supervisor' AND permission_key='listening.view'");
                $removed=true;
            }
        };
        music_listening_totals_test_reject(static fn()=>music_listening_totals_test_report($scope==='workspace'?[]:$platform,$scope==='workspace'?12:91),403,$scope.'_unavailable','Post-snapshot '.$scope.' revocation');
        unset($GLOBALS['musicAnalyticsQueryHook']);music_listening_totals_test_equal($removed,true,'Revocation timing '.$scope);
        if($scope==='workspace')$pdo->exec("INSERT INTO artist_team_members VALUES (11,12,'manager')");else $pdo->exec("INSERT INTO role_permissions VALUES ('supervisor','listening.view')");
    }
    $GLOBALS['musicAnalyticsQueryHook']=static function(string $query):void{if(str_contains($query,' FROM music_provider_sessions_v1 s'))throw new PDOException('PRIVATE-SQL-FAULT');};
    try{music_listening_totals_test_report();throw new RuntimeException('SQL fault became zero');}catch(PDOException){music_listening_totals_test_equal($pdo->inTransaction(),false,'Failed read retained transaction');}
    unset($GLOBALS['musicAnalyticsQueryHook']);
    $pdo->exec('ALTER TABLE music_provider_sessions_v1 RENAME TO music_provider_sessions_missing');
    $partial=music_listening_totals_test_report();music_listening_totals_test_equal([$partial['availability']['provider'],$partial['channels']['provider'],$partial['coverage']['provider_sessions'],$partial['coverage']['missing_sources']],['unavailable',null,null,['provider']],'Unavailable source became zero');
    music_listening_totals_test_equal($partial['summary']['listening_ms'],20000,'Available measurements lost with missing source');
    music_listening_totals_test_reject(static fn()=>music_listening_totals_test_report(['channel'=>'provider']),503,'source_unavailable','Explicit unavailable source');
    $pdo->exec('ALTER TABLE music_provider_sessions_missing RENAME TO music_provider_sessions_v1');
    // An incomplete native schema still identifies compatibility rows by session_id.
    $pdo->exec('ALTER TABLE music_listening_sessions_v1 RENAME COLUMN last_seq TO last_seq_missing');
    $partial=music_listening_totals_test_report(['traffic'=>'all']);
    music_listening_totals_test_equal([$partial['availability']['native'],$partial['summary']['qualified_plays'],$partial['summary']['legacy_sessions'],$partial['coverage']['native_backing_rows_excluded']],['unavailable',2,1,3],'Incomplete native schema reclassified compatibility history');
    $pdo->exec('ALTER TABLE music_listening_sessions_v1 RENAME COLUMN last_seq_missing TO last_seq');
    // A missing native identity table cannot certify which old rows are legacy-only.
    $pdo->exec('ALTER TABLE music_listening_sessions_v1 RENAME TO music_listening_sessions_missing');
    $partial=music_listening_totals_test_report(['traffic'=>'all']);
    music_listening_totals_test_equal([$partial['availability']['native'],$partial['availability']['legacy'],$partial['channels']['legacy']],['unavailable','unavailable',null],'Missing native identity became known legacy-only evidence');
    $pdo->exec('ALTER TABLE music_provider_sessions_v1 RENAME TO music_provider_sessions_missing');
    music_listening_totals_test_reject(static fn()=>music_listening_totals_test_report(),503,'source_unavailable','All measurement sources missing');
    $pdo->exec('ALTER TABLE music_provider_sessions_missing RENAME TO music_provider_sessions_v1');
    $pdo->exec('ALTER TABLE music_listening_sessions_missing RENAME TO music_listening_sessions_v1');

    // Zero-play canonical tracks and truly empty workspaces still have truthful group rows.
    $pdo->exec("INSERT INTO users VALUES (92,'customer',1,1,'Empty Artist')");$pdo->exec("INSERT INTO artist_workspaces_v181 VALUES (3,92,'Empty Studio')");
    $empty=music_listening_totals_test_report(['workspace_id'=>3],92);
    music_listening_totals_test_equal([$empty['summary']['qualified_plays'],$empty['summary']['completion_percent'],$empty['history']['first_measured_at'],$empty['groups']['rows']],[0,null,null,[]],'Empty workspace truth');
    $pdo->exec("INSERT INTO artist_catalog_tracks_v181 (id,workspace_id,source_track_id,title,audio_path,visibility,is_published) VALUES (30,3,NULL,'Zero-play song','','public',0)");
    $pdo->beginTransaction();music_song_metadata_v1_import_legacy($pdo,$pdo->query('SELECT * FROM artist_catalog_tracks_v181 WHERE id=30')->fetch(PDO::FETCH_ASSOC));$pdo->commit();
    $zero=music_listening_totals_test_report(['workspace_id'=>3],92);
    music_listening_totals_test_equal([$zero['groups']['total'],$zero['groups']['rows'][0]['catalog_track_id'],$zero['groups']['rows'][0]['metrics']['qualified_plays']],[1,30,0],'Zero-play canonical song omitted');
    music_listening_totals_test_equal(music_listening_totals_test_report($platform,91)['groups']['total'],3,'Empty workspace omitted globally');

    // More than the old 25,000-row and 366-day bounds, with no row materialization or truncation.
    [$start,$end]=music_listening_totals_test_bulk((int)$seed['native']['session_id'],25001,$now-800*86400000);
    $large=music_listening_totals_test_report(['traffic'=>'all','limit'=>1]);
    music_listening_totals_test_equal([$large['summary']['qualified_plays'],$large['summary']['listening_ms'],$large['summary']['legacy_sessions'],$large['coverage']['native_backing_rows_excluded']],[25005,250075000,1,25004],'Large retained cohort truncated or backing double-counted');
    music_listening_totals_test_equal($large['history']['first_measured_at'],gmdate('c',intdiv($now-800*86400000,1000)),'Large historical cohort start');
    music_listening_totals_test_equal($large['groups']['rows'][0]['metrics']['qualified_plays'],25005,'Large per-song total');
    $pdo->prepare('UPDATE music_listening_sessions_v1 SET accepted_played_ms=? WHERE session_id>=? AND session_id<?')->execute([MUSIC_LISTENING_TOTALS_V1_MAX_INTEGER,$start,$start+1100]);
    music_listening_totals_test_reject(static fn()=>music_listening_totals_test_report(),422,'numeric_range_exceeded','Aggregate exceeds signed SQL integer range');
    $pdo->prepare('DELETE FROM track_play_sessions WHERE id>=? AND id<?')->execute([$start,$end]);
    music_listening_totals_test_equal(music_listening_totals_test_report()['summary'],$report['summary'],'Stress fixture cleanup');
    echo 'MUSIC_LISTENING_TOTALS_V1=PASS '.$GLOBALS['musicListeningTotalsChecks'].' checks; exact aggregate SQL, retained history, authority/snapshot boundaries, coverage, pagination and >25k historical sessions'.PHP_EOL;
}

if(!defined('MUSIC_LISTENING_TOTALS_TEST_LIBRARY_ONLY')){
    try{music_listening_totals_test_run();}finally{unset($GLOBALS['musicAnalyticsQueryHook']);if(db()->inTransaction())db()->rollBack();music_listening_test_clean_files(STONEFELLOW_ROOT);}
}
