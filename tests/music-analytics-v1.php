<?php
declare(strict_types=1);

if(!defined('STONEFELLOW_ROOT'))define('STONEFELLOW_ROOT',sys_get_temp_dir().'/vp3-analytics-'.bin2hex(random_bytes(6)));
if(!is_dir(STONEFELLOW_ROOT))mkdir(STONEFELLOW_ROOT,0700,true);
if(!isset($GLOBALS['musicSongTestConnection']))putenv('MUSIC_LISTENING_TEST_DB='.STONEFELLOW_ROOT.'/analytics.sqlite');
require_once __DIR__.'/fixtures/music-analytics-v1.php';

class MusicAnalyticsAcceptanceStatement extends PDOStatement
{
    protected function __construct(){}
    public function execute(?array $params=null): bool
    {
        if(isset($GLOBALS['musicAnalyticsQueryHook']))($GLOBALS['musicAnalyticsQueryHook'])($this->queryString);
        return parent::execute($params);
    }
}

function music_analytics_test_equal(mixed $actual,mixed $expected,string $label): void
{
    $GLOBALS['musicAnalyticsChecks']=($GLOBALS['musicAnalyticsChecks']??0)+1;
    if(is_array($actual)&&is_array($expected))$same=music_provider_v1_json($actual)===music_provider_v1_json($expected);else $same=$actual===$expected;
    if(!$same)throw new RuntimeException($label.': '.json_encode(['expected'=>$expected,'actual'=>$actual],JSON_PARTIAL_OUTPUT_ON_ERROR));
}

function music_analytics_test_reject(callable $operation,int $status,string $code,string $label): void
{
    try{$operation();}catch(MusicAnalyticsV1Exception $error){
        music_analytics_test_equal($error->httpStatus,$status,$label.' status');music_analytics_test_equal($error->publicCode,$code,$label.' code');return;
    }
    throw new RuntimeException($label.' unexpectedly succeeded');
}

function music_analytics_test_report(array $changes=[],int $userId=11): array
{
    return music_analytics_v1_report(db(),['id'=>$userId,'role'=>'admin'],array_replace([
        'protocol'=>'vp3_music_analytics_v1','action'=>'report','workspace_id'=>1,'timezone'=>'UTC','period'=>'30d',
    ],$changes),['now_ms'=>$GLOBALS['musicAnalyticsNow']]);
}

function music_analytics_test_other_connection(): PDO
{
    if(db()->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite'){
        $rows=db()->query('PRAGMA database_list')->fetchAll(PDO::FETCH_ASSOC);$dsn='sqlite:'.$rows[0]['file'];$user=null;$password=null;
    }else{$dsn=getenv('MUSIC_TEST_MYSQL_DSN');$user=getenv('MUSIC_TEST_MYSQL_USER')?:'';$password=getenv('MUSIC_TEST_MYSQL_PASSWORD')?:'';}
    return new PDO($dsn,$user,$password,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
}

function music_analytics_test_copy_native(int $source,int $created): int
{
    $pdo=db();$q=$pdo->prepare('SELECT * FROM track_play_sessions WHERE id=?');$q->execute([$source]);$legacy=$q->fetch(PDO::FETCH_ASSOC);
    unset($legacy['id']);$legacy['session_token']=bin2hex(random_bytes(32));$legacy['started_at']=gmdate('Y-m-d H:i:s',intdiv($created,1000));
    $pdo->prepare('INSERT INTO track_play_sessions ('.implode(',',array_keys($legacy)).') VALUES ('.implode(',',array_fill(0,count($legacy),'?')).')')->execute(array_values($legacy));
    $id=(int)$pdo->lastInsertId();$q=$pdo->prepare('SELECT * FROM music_listening_sessions_v1 WHERE session_id=?');$q->execute([$source]);$native=$q->fetch(PDO::FETCH_ASSOC);
    $native['session_id']=$id;$native['play_id']=music_song_metadata_v1_uuid();$native['created_ms']=$created;$native['expires_ms']=$created+86400000;$native['last_received_ms']=$created+20000;
    $pdo->prepare('INSERT INTO music_listening_sessions_v1 ('.implode(',',array_keys($native)).') VALUES ('.implode(',',array_fill(0,count($native),'?')).')')->execute(array_values($native));
    return $id;
}

function music_analytics_test_run(): void
{
    $pdo=db();$now=(new DateTimeImmutable('2026-11-27 18:00:00',new DateTimeZone('UTC')))->getTimestamp()*1000;
    $GLOBALS['musicAnalyticsNow']=$now;$seed=music_analytics_test_seed($now);
    $pdo->setAttribute(PDO::ATTR_STATEMENT_CLASS,[MusicAnalyticsAcceptanceStatement::class,[]]);
    if($pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite')$pdo->exec('PRAGMA journal_mode=WAL');
    $before=music_listening_test_snapshot();$report=music_analytics_test_report();
    music_analytics_test_equal(music_listening_test_snapshot(),$before,'Read-only report changed stored records');
    music_analytics_test_equal($report['period']['basis'],'session_start_cohort_v1','Period semantics missing');
    music_analytics_test_equal($report['summary'],[
        'playback_requests'=>4,'starts'=>2,'qualified_plays'=>2,'listening_ms'=>40000,'completed_plays'=>2,'completion_known_plays'=>2,
        'completion_percent'=>100.0,'legacy_sessions'=>0,'legacy_listening_ms'=>0,'emitted_bytes'=>2048,'reserved_bytes'=>4096,'media_requests'=>3,
    ],'Measured native/provider summary or begin-zero accounting');
    music_analytics_test_equal([$report['coverage']['native_sessions'],$report['coverage']['provider_sessions'],$report['coverage']['partial_sessions'],
        $report['coverage']['unknown_duration_sessions'],$report['coverage']['ongoing_sessions'],$report['coverage']['unfinished_delivery_requests']],[2,2,1,1,2,1],'Coverage does not match source evidence');
    foreach(['starts','qualified_plays','listening_ms','completed_plays','emitted_bytes','reserved_bytes'] as $metric){
        music_analytics_test_equal(array_sum(array_column($report['daily'],$metric)),$report['summary'][$metric],'Daily rollup differs: '.$metric);
        music_analytics_test_equal(array_sum(array_column($report['tracks'],$metric)),$report['summary'][$metric],'Track rollup differs: '.$metric);
        music_analytics_test_equal(array_sum(array_column($report['pages'],$metric)),$report['summary'][$metric],'Page rollup differs: '.$metric);
    }
    music_analytics_test_equal($report['financial'],['earnings'=>'not_configured','payments'=>'not_configured'],'Unset finance became money');
    music_analytics_test_reject(static function():void{$sum=music_analytics_v1_metrics();$sum['legacy_listening_ms']=9007199254740991;$increment=music_analytics_v1_metrics();$increment['legacy_listening_ms']=1;music_analytics_v1_add($sum,$increment);},422,'report_window_too_large','Large aggregate lost integer precision');
    music_analytics_test_equal($report['catalog']['playback_ready'],2,'Existing readiness service was not reused');
    music_analytics_test_equal(count($report['options']['recordings']),2,'Options include another workspace');
    $wire=json_encode($report,JSON_THROW_ON_ERROR);
    foreach(['PRIVATE','session_token','listener_hash','listener_key','playback_key','token_hash','challenge','creator_user_id','Foreign draft','Second Studio','contact_email','rights_notes','source_json','/uploads/'] as $secret)
        music_analytics_test_equal(str_contains($wire,$secret),false,'Aggregate output leaked '.$secret);
    music_analytics_test_equal(in_array('https://artist.example.test/track/public-fixture',array_column($report['pages'],'url'),true),true,'Public Stoneward page was dropped');
    $all=music_analytics_test_report(['traffic'=>'all']);
    music_analytics_test_equal([$all['summary']['starts'],$all['summary']['listening_ms'],$all['summary']['legacy_sessions'],$all['summary']['legacy_listening_ms']],[4,65000,1,45000],'Native backing rows were double-counted or legacy promoted');
    music_analytics_test_equal([$all['coverage']['preview_sessions'],$all['coverage']['staging_sessions'],$all['coverage']['muted_sessions'],$all['coverage']['loop_sessions']],[1,1,1,1],'Preview/staging/mute/loop classes lost');
    music_analytics_test_equal($all['summary']['completed_plays'],2,'Natural ending with unknown duration became completion');
    $tests=music_analytics_test_report(['traffic'=>'tests']);music_analytics_test_equal($tests['summary']['listening_ms'],25000,'Test traffic filter');
    music_analytics_test_equal(music_analytics_test_report(['channel'=>'native'])['summary']['listening_ms'],20000,'Native filter');
    music_analytics_test_equal(music_analytics_test_report(['channel'=>'provider'])['summary']['listening_ms'],20000,'Provider filter');
    $legacy=music_analytics_test_report(['channel'=>'legacy','traffic'=>'all']);
    music_analytics_test_equal([$legacy['summary']['starts'],$legacy['summary']['listening_ms'],$legacy['summary']['legacy_sessions']],[0,0,1],'Legacy became measured starts');
    music_analytics_test_equal(music_analytics_test_report(['site_id'=>$seed['production']['site_id']])['summary']['listening_ms'],20000,'Site filter included native listening');
    music_analytics_test_equal(music_analytics_test_report(['recording_id'=>$seed['recordings'][11]])['summary']['starts'],0,'Pending song got actual start');
    music_analytics_test_equal(music_analytics_test_report(['album_id'=>1])['summary'],$report['summary'],'Album filter changed same-release totals');
    $paged=music_analytics_test_report(['limit'=>1,'offset'=>1]);
    music_analytics_test_equal($paged['summary'],$report['summary'],'Pagination reduced report totals');
    music_analytics_test_equal([$paged['pagination']['tracks']['total'],count($paged['tracks'])],[2,1],'Track page metadata incorrect');
    music_analytics_test_equal(music_analytics_test_report([],12)['summary'],$report['summary'],'Current manager denied');
    foreach([13,14,16,17] as $user)music_analytics_test_reject(static fn()=>music_analytics_test_report([],$user),403,'workspace_unavailable','Unscoped actor '.$user);
    $pdo->exec('DELETE FROM artist_team_members WHERE member_user_id=12');
    music_analytics_test_reject(static fn()=>music_analytics_test_report([],12),403,'workspace_unavailable','Removed manager retained captured admin role');
    $pdo->exec("INSERT INTO artist_team_members VALUES (11,12,'manager')");
    $pdo->exec('UPDATE users SET is_active=0 WHERE id=11');
    music_analytics_test_reject(static fn()=>music_analytics_test_report([],15),403,'workspace_unavailable','Administrator read disabled artist workspace');
    $pdo->exec('UPDATE users SET is_active=1 WHERE id=11');
    foreach([['recording_id'=>$seed['recordings'][20]],['album_id'=>2],['site_id'=>music_song_metadata_v1_uuid()]] as $filter)
        music_analytics_test_reject(static fn()=>music_analytics_test_report($filter),404,'selector_unavailable','Foreign or unavailable selector');
    foreach([['workspace_id'=>'1'],['recording_id'=>"' OR 1=1"],['album_id'=>-1],['limit'=>101],['offset'=>-1],['extra'=>'secret'],['channel'=>'bogus'],['traffic'=>'bogus'],['site_id'=>$seed['production']['site_id'],'channel'=>'native'],['channel'=>'legacy']] as $input)
        music_analytics_test_reject(static fn()=>music_analytics_test_report($input),422,'invalid_request','Malformed report selector');
    music_analytics_test_reject(static fn()=>music_analytics_test_report(['timezone'=>'../../private']),422,'invalid_timezone','Invalid timezone');
    foreach([['period'=>'custom','start_date'=>'2026-02-30','end_date'=>'2026-03-01'],['period'=>'custom','start_date'=>'2025-01-01','end_date'=>'2026-11-26'],['period'=>'custom','start_date'=>'2026-11-28','end_date'=>'2026-11-28'],['period'=>'30d','start_date'=>'2026-11-01']] as $input)
        music_analytics_test_reject(static fn()=>music_analytics_test_report($input),422,'invalid_period','Invalid calendar period');
    $pdo->beginTransaction();music_analytics_test_reject(static fn()=>music_analytics_test_report(),409,'transaction_boundary_required','Caller snapshot accepted');
    music_analytics_test_equal($pdo->inTransaction(),true,'Refusal committed caller transaction');$pdo->rollBack();

    // True calendar boundaries, including a timezone whose DST transition skips midnight.
    foreach([
        ['America/New_York','2026-03-08',23],['America/New_York','2026-11-01',25],
        ['America/Sao_Paulo','2018-11-04',23],['America/Phoenix','2026-03-08',24],
    ] as [$zone,$date,$hours]){
        $r=music_analytics_test_report(['period'=>'custom','start_date'=>$date,'end_date'=>$date,'timezone'=>$zone]);
        music_analytics_test_equal(($r['period']['end_exclusive_ms']-$r['period']['start_ms'])/3600000,$hours,'DST day length '.$zone.' '.$date);
        music_analytics_test_equal(array_column($r['daily'],'date'),[$date],'DST daily labels '.$zone);
    }
    $zone=new DateTimeZone('America/Sao_Paulo');
    $boundary=(new DateTimeImmutable('2018-11-05 00:00:00',$zone))->getTimestamp()*1000;
    $id1=music_analytics_test_copy_native((int)$seed['native']['session_id'],$boundary-1);
    $id2=music_analytics_test_copy_native((int)$seed['native']['session_id'],$boundary);
    $single=music_analytics_test_report(['period'=>'custom','start_date'=>'2018-11-04','end_date'=>'2018-11-04','timezone'=>'America/Sao_Paulo']);
    music_analytics_test_equal($single['summary']['starts'],1,'Midnight DST end was inclusive or carried 01:00');
    $span=music_analytics_test_report(['period'=>'custom','start_date'=>'2018-11-03','end_date'=>'2018-11-05','timezone'=>'America/Sao_Paulo']);
    music_analytics_test_equal(array_column($span['daily'],'date'),['2018-11-03','2018-11-04','2018-11-05'],'Midnight DST omitted final day');
    music_analytics_test_equal(array_column($span['daily'],'starts'),[0,1,1],'Midnight DST allocation');
    $pdo->exec('DELETE FROM track_play_sessions WHERE id IN ('.$id1.','.$id2.')');
    $empty=music_analytics_test_report(['period'=>'custom','start_date'=>'2026-04-01','end_date'=>'2026-04-02']);
    music_analytics_test_equal([$empty['summary']['starts'],$empty['summary']['completion_percent'],count($empty['daily'])],[0,null,2],'Empty measured period is not honest zero');

    // Legacy mapping must be both unique and consistent with current backing ownership.
    $pdo->exec('UPDATE tracks SET workspace_id=2 WHERE id=42');
    music_analytics_test_equal(music_analytics_test_report(['traffic'=>'all'])['summary']['legacy_sessions'],0,'Foreign backing history leaked through stale sole mapping');
    $pdo->exec('UPDATE tracks SET workspace_id=1 WHERE id=42');
    $pdo->exec('UPDATE artist_catalog_tracks_v181 SET source_track_id=42 WHERE id=11');
    music_analytics_test_equal(music_analytics_test_report(['traffic'=>'all'])['summary']['legacy_sessions'],0,'Ambiguous catalog backing history was guessed');
    $pdo->exec('UPDATE artist_catalog_tracks_v181 SET source_track_id=43 WHERE id=11');

    // Current revocation is terminal for provisional counts; it does not erase retained evidence.
    music_provider_v1_withdraw_recording($pdo,1,10);
    $revoked=music_analytics_test_report();
    music_analytics_test_equal($revoked['coverage']['ongoing_sessions'],1,'Revoked provider session was still ongoing');
    music_analytics_test_equal($revoked['summary']['listening_ms'],40000,'Revocation erased historical evidence');

    // A writer commits between source queries; one report must retain its consistent snapshot.
    $writer=music_analytics_test_other_connection();$called=false;
    $GLOBALS['musicAnalyticsQueryHook']=static function(string $query)use($writer,$seed,&$called):void{
        if(!$called&&str_contains($query,' FROM music_listening_sessions_v1 s JOIN')){
            $called=true;unset($GLOBALS['musicAnalyticsQueryHook']);
            $writer->prepare('UPDATE music_provider_sessions_v1 SET accepted_played_ms=30000 WHERE session_id=?')->execute([$seed['provider']['session_id']]);
        }
    };
    $consistent=music_analytics_test_report();unset($GLOBALS['musicAnalyticsQueryHook']);
    music_analytics_test_equal($called,true,'Concurrent writer scenario was not exercised');
    music_analytics_test_equal($consistent['summary']['listening_ms'],40000,'Mixed-source report read different snapshots');
    music_analytics_test_equal(music_analytics_test_report()['summary']['listening_ms'],50000,'Later report failed to observe committed writer');
    $writer->prepare('UPDATE music_provider_sessions_v1 SET accepted_played_ms=20000 WHERE session_id=?')->execute([$seed['provider']['session_id']]);

    // A membership change immediately after the read snapshot must prevent response release.
    $inside=false;$removed=false;
    $GLOBALS['musicAnalyticsQueryHook']=static function(string $query)use(&$inside,&$removed):void{
        if(db()->inTransaction())$inside=true;
        elseif($inside&&str_starts_with($query,'SELECT * FROM users WHERE id=')){
            unset($GLOBALS['musicAnalyticsQueryHook']);db()->exec('DELETE FROM artist_team_members WHERE member_user_id=12');$removed=true;
        }
    };
    music_analytics_test_reject(static fn()=>music_analytics_test_report([],12),403,'workspace_unavailable','Post-snapshot manager revocation');
    unset($GLOBALS['musicAnalyticsQueryHook']);music_analytics_test_equal($removed,true,'Revocation timing hook did not run');
    $pdo->exec("INSERT INTO artist_team_members VALUES (11,12,'manager')");

    $GLOBALS['musicAnalyticsQueryHook']=static function(string $query):void{
        if(str_contains($query,' FROM music_provider_sessions_v1 s JOIN'))throw new PDOException('PRIVATE-SQL-FAULT');
    };
    try{music_analytics_test_report();throw new RuntimeException('SQL fault became fake zero');}catch(PDOException){music_analytics_test_equal($pdo->inTransaction(),false,'Read fault retained open transaction');}
    unset($GLOBALS['musicAnalyticsQueryHook']);
    $pdo->exec('ALTER TABLE music_provider_sessions_v1 RENAME TO music_provider_sessions_missing');
    $partial=music_analytics_test_report();music_analytics_test_equal($partial['availability']['provider'],'unavailable','Missing source became available zero');
    music_analytics_test_equal($partial['summary']['listening_ms'],20000,'Missing source corrupted available measurements');
    music_analytics_test_reject(static fn()=>music_analytics_test_report(['channel'=>'provider']),503,'source_unavailable','Explicit unavailable channel');
    $pdo->exec('ALTER TABLE music_provider_sessions_missing RENAME TO music_provider_sessions_v1');

    // Bounded execution never returns a believable but truncated total.
    $lastId=(int)$pdo->query('SELECT MAX(id) FROM track_play_sessions')->fetchColumn();$date=gmdate('Y-m-d H:i:s',intdiv($seed['clock_ms'],1000));
    $pdo->beginTransaction();
    for($batch=0;$batch<50;$batch++){
        $values=[];$arguments=[];
        for($i=0;$i<500;$i++){$values[]='(?,?,?,?)';array_push($arguments,bin2hex(random_bytes(32)),str_repeat('b',64),42,$date);}
        $pdo->prepare('INSERT INTO track_play_sessions (session_token,listener_hash,track_id,started_at) VALUES '.implode(',',$values))->execute($arguments);
    }
    $pdo->commit();
    music_analytics_test_reject(static fn()=>music_analytics_test_report(['traffic'=>'all','channel'=>'legacy']),422,'report_window_too_large','Large cohort silently truncated');
    $pdo->exec('DELETE FROM track_play_sessions WHERE id>'.$lastId);
    echo 'MUSIC_ANALYTICS_V1=PASS '.$GLOBALS['musicAnalyticsChecks'].' checks; workspace authority, privacy, deduplication, cohort/DST boundaries, live read snapshots, no-write reads, source/usage coverage, pagination and bounds'.PHP_EOL;
}

if(!defined('MUSIC_ANALYTICS_TEST_LIBRARY_ONLY')){
    try{music_analytics_test_run();}finally{unset($GLOBALS['musicAnalyticsQueryHook']);if(db()->inTransaction())db()->rollBack();music_listening_test_clean_files(STONEFELLOW_ROOT);}
}
