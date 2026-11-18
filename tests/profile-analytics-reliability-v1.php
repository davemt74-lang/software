<?php
declare(strict_types=1);

require dirname(__DIR__).'/includes/vp3-analytics.php';
require dirname(__DIR__).'/includes/vp3-analytics-dashboard.php';
require dirname(__DIR__).'/includes/vp3-analytics-intelligence.php';
require dirname(__DIR__).'/includes/profile-revenue-intelligence-v180.php';
require __DIR__.'/fixtures/analytics-agent-v1.php';

/** The aggregate SQL is native SQLite/MySQL. Only the reporting clock is frozen for exact boundary cases. */
class ProfileAnalyticsReliabilityPDO extends PDO
{
    public ?string $fixtureClock=null;
    public bool $failClock=false,$failProfile=false;
    public function prepare(string $sql,array $options=[]): PDOStatement|false
    {
        if($this->failProfile&&str_contains($sql,'FROM profile_events'))throw new PDOException('SECRET synthetic profile read failure');
        return parent::prepare($sql,$options);
    }
    public function query(string $sql,?int $mode=null,mixed ...$args): PDOStatement|false
    {
        if(str_contains($sql,' AS as_of')){
            if($this->failClock)throw new PDOException('SECRET synthetic reporting clock failure');
            if($this->fixtureClock!==null){
                if(!preg_match('/(?:INTERVAL (\d+) DAY|-(\d+) days)/',$sql,$match))throw new RuntimeException('Unknown fixture reporting window.');
                $days=(int)($match[1]!==''?$match[1]:$match[2]);
                $from=(new DateTimeImmutable($this->fixtureClock,new DateTimeZone('UTC')))->modify('-'.$days.' days')->format('Y-m-d H:i:s');
                $sql='SELECT '.$this->quote($this->fixtureClock).' AS as_of,'.$this->quote($from).' AS from_at,'
                    .$this->quote($this->fixtureClock)." AS utc_now,'UTC' AS zone,'UTC' AS system_zone,0 AS utc_offset";
            }
        }
        return $mode===null?parent::query($sql):parent::query($sql,$mode,...$args);
    }
}

$mysqlDsn=getenv('VP3_TEST_MYSQL_DSN');$driver=$mysqlDsn?'mysql':'sqlite';
$dsn='sqlite::memory:';$dbUser=null;$dbPass=null;
if($mysqlDsn){
    $dbUser=getenv('VP3_TEST_MYSQL_USER')?:'root';$dbPass=getenv('VP3_TEST_MYSQL_PASS')?:'root';
    $admin=new PDO($mysqlDsn,$dbUser,$dbPass,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
    $database='vp3_profile_analytics_'.bin2hex(random_bytes(6));
    $admin->exec('CREATE DATABASE `'.$database.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    $dsn=preg_match('/dbname=[^;]+/',$mysqlDsn)?preg_replace('/dbname=[^;]+/','dbname='.$database,$mysqlDsn):rtrim($mysqlDsn,';').';dbname='.$database;
    register_shutdown_function(static fn()=>$admin->exec('DROP DATABASE IF EXISTS `'.$database.'`'));
}
$pdo=new ProfileAnalyticsReliabilityPDO($dsn,$dbUser,$dbPass,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
if($driver==='mysql'){$pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES,false);$pdo->exec("SET time_zone='+00:00'");}
$checks=0;$nativeCalls=0;
function reliability_check(bool $ok,string $label): void
{
    if(!$ok)throw new RuntimeException($label);
    $GLOBALS['checks']++;echo 'PASS '.$label.PHP_EOL;
}
function table_exists(string $table): bool
{
    $pdo=$GLOBALS['pdo'];
    $q=$pdo->prepare($pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'
        ?'SELECT 1 FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?'
        :"SELECT 1 FROM sqlite_master WHERE type='table' AND name=?");
    $q->execute([$table]);return $q->fetchColumn()!==false;
}
function vp3_radar_schema_ready(PDO $pdo): bool{return table_exists('vp3_radar_properties')&&table_exists('vp3_radar_sessions')&&table_exists('vp3_radar_events');}
function vp3_radar_native_property(PDO $pdo,int $owner): int{$GLOBALS['nativeCalls']++;return vp3_analytics_native_property_id($pdo,$owner);}
function url(string $path): string{return $path;}
function vp3_agent_activity_text_v1(string $value,int $limit): string{return mb_substr($value,0,$limit);}
function create_notification(...$args): never{throw new RuntimeException('No notification may be fabricated by read-only fixture signals.');}
function analytics_failure(callable $work,string $label): void
{
    $caught=false;try{$work();}catch(Throwable $error){$caught=true;}
    reliability_check($caught,$label);
}
function reliability_event(PDO $pdo,int $id,int $owner,int $property,?int $session,?int $contact,string $type,string $at,array $details=[],int $risk=0): void
{
    $q=$pdo->prepare("INSERT INTO vp3_radar_events(id,owner_user_id,property_id,session_id,agent_contact_id,event_type,severity,path,method,status_code,significance_score,risk_score,summary,details_json,occurred_at) VALUES(?,?,?,?,?,?,'low','/fixture','GET',200,0,?,'fixture',?,?)");
    $q->execute([$id,$owner,$property,$session,$contact,$type,$risk,json_encode($details,JSON_THROW_ON_ERROR),$at]);
}
function reliability_visit(PDO $pdo,int $id,int $owner,?int $session,string $at,string $type='profile_view',array $meta=[]): void
{
    $q=$pdo->prepare('INSERT INTO profile_events(id,owner_user_id,profile_session_id,event_type,metadata_json,created_at) VALUES(?,?,?,?,?,?)');
    $q->execute([$id,$owner,$session,$type,json_encode($meta,JSON_THROW_ON_ERROR),$at]);
}

analytics_agent_fixture_v1($pdo);
$pdo->exec('CREATE TABLE vp3_agent_contacts(id INTEGER PRIMARY KEY,owner_user_id INTEGER,display_name VARCHAR(190),operator_name VARCHAR(190),visitor_class VARCHAR(40))');
$pdo->exec("INSERT INTO vp3_agent_contacts VALUES(31,1,'Fixture Crawler','Fixture','ai_crawler'),(32,1,'Fixture Agent','Fixture','ai_user_agent')");
$clock=vp3_analytics_reporting_window_v1($pdo,30);
reliability_check($clock['from']<$clock['to']&&str_contains($clock['zone'],'UTC'),'native database clock declares its reporting window and offset');
$pdo->fixtureClock=$clock['to'];$time=new DateTimeImmutable($clock['to'],new DateTimeZone('UTC'));
$stamp=static fn(string $offset):string=>$time->modify($offset)->format('Y-m-d H:i:s');
$browser=static fn(string $name,string $device='Desktop',string $family='Chrome'):array=>['collector'=>'browser_analytics','event_name'=>$name,'device_type'=>$device,'browser_family'=>$family];
$pdo->beginTransaction();
for($i=0;$i<1300;$i++)reliability_event($pdo,1000+$i,1,11,101,null,'analytics_event',$stamp('-1 minute'),$browser('purchase'),80);
for($i=0;$i<1250;$i++)reliability_event($pdo,3000+$i,1,11,102,31,'webmcp_tool_called',$stamp('-1 minute'));
foreach(['webmcp_tool_completed','webmcp_tool_failed','webmcp_tool_denied'] as $i=>$type)reliability_event($pdo,4300+$i,1,11,102,31,$type,$stamp('-2 minutes'));
$pdo->commit();
$dashboard=vp3_analytics_dashboard_state($pdo,['id'=>1],11,30);$s=$dashboard['stats'];
reliability_check($dashboard['ready']===true&&$dashboard['coverage']==='complete','dashboard returns explicit complete coverage');
reliability_check($s['conversions']===1301&&$s['custom_events']===1301,'conversion and custom-event totals cover every matching row beyond 1200');
reliability_check($s['high_risk_events']===1300,'high-risk totals are independent of the activity feed cap');
reliability_check($s['webmcp_tool_calls']===1250&&$s['webmcp_completed']===1&&$s['webmcp_failed']===1&&$s['webmcp_denied']===1,'WebMCP outcomes aggregate beyond 1200 unrelated or newer events');
reliability_check(array_sum(array_column($dashboard['devices'],'count'))===1304&&array_sum(array_column($dashboard['browsers'],'count'))===1304,'device and browser distributions cover all collected browser events');
reliability_check(count($dashboard['events'])===80&&count($dashboard['webmcp_activity'])===80,'recent feeds remain bounded independently of complete aggregates');
reliability_check($s['human_page_views']===3&&$s['agent_page_views']===1&&$s['page_views']===4,'page views use actual typed events, not session counters or purchases');
reliability_check(array_sum(array_column($dashboard['timeline'],'page_views'))===4,'timeline totals use event occurrence dates');
reliability_check(!in_array('/checkout',array_column($dashboard['top_pages'],'path'),true),'purchase-only destinations are not reported as page views');
reliability_check($s['sessions']===2&&$s['human_sessions']===1&&$s['agent_sessions']===1,'session counts remain scoped and are separate from page-view events');
$foreign=vp3_analytics_dashboard_state($pdo,['id'=>1],21,30);
reliability_check($foreign['ready']===false&&$foreign['stats']===[]&&$foreign['property_id']===21,'an unmatched or foreign property does not broaden to all owned traffic');
$all=vp3_analytics_dashboard_state($pdo,['id'=>1],0,30);
reliability_check($all['stats']['sessions']===4&&$all['stats']['page_views']===6,'corrupt cross-owner property/session joins cannot enter all-owned totals');
// An automated WebMCP visitor may have no Agent CRM contact; absence of identity is not human classification.
$session=$pdo->prepare("INSERT INTO vp3_radar_sessions(id,property_id,owner_user_id,agent_contact_id,visitor_type,page_view_count,last_seen_at,referrer_host) VALUES(118,11,1,NULL,'webmcp_unclassified',999999,?,'')");$session->execute([$clock['to']]);
reliability_event($pdo,4400,1,11,118,null,'webmcp_tool_called',$stamp('-3 minutes'),['tool'=>'public_profile','status'=>'called']);
$pdo->exec("UPDATE vp3_radar_events SET path='/webmcp-unclassified' WHERE id=4400");
$webmcp=vp3_analytics_dashboard_state($pdo,['id'=>1],11,30);
reliability_check($webmcp['stats']['sessions']===3&&$webmcp['stats']['human_sessions']===1&&$webmcp['stats']['agent_sessions']===2&&$webmcp['stats']['unclassified_sessions']===0,'unclassified WebMCP sessions remain automated traffic without an Agent CRM contact');
reliability_check($webmcp['stats']['webmcp_tool_calls']===1251&&$webmcp['stats']['page_views']===4&&$webmcp['stats']['conversions']===1301
    &&!in_array('/webmcp-unclassified',array_column($webmcp['top_pages'],'path'),true),'WebMCP lifecycle activity increments tool calls without inflating browser conversions or Top pages');
$webmcpMetrics=vp3_analytics_intelligence_property_metrics_v310($pdo,1,['id'=>11,'property_type'=>'external']);
reliability_check($webmcpMetrics['current_agent_sessions']===2&&$webmcpMetrics['current_human_sessions']===1,'traffic-spike actor classification also treats unclassified WebMCP sessions as automated');
$native=vp3_analytics_dashboard_state_v2($pdo,['id'=>1],13,30);
reliability_check($native['stats']['native_visit_events']===2&&$native['stats']['native_browser_contacts']===1,'native visits use deduplicated events and distinct owner browser contacts');
reliability_check($native['stats']['human_sessions']===0&&$native['stats']['page_views']===1&&$native['native_page_views_available']===false,'native browser contacts and lifetime view counters are never relabeled as sessions or page views');
$nativeCard=array_values(array_filter($native['property_stats'],static fn(array $r):bool=>(int)$r['id']===13))[0];
reliability_check($nativeCard['native_visit_events']===2&&$nativeCard['page_views']===1,'native property card cannot re-add cumulative view counters');
reliability_check(array_sum(array_column($native['timeline'],'native_visit_events'))===2,'native timeline uses visit-event dates rather than latest contact activity');
$pdo->exec("UPDATE profile_visit_sessions SET view_count=999999,last_seen_at='".$pdo->fixtureClock."' WHERE id=301");
$unchanged=vp3_analytics_dashboard_state($pdo,['id'=>1],13,30);
reliability_check($unchanged['stats']===$native['stats']&&$unchanged['timeline']===$native['timeline'],'later polling or lifetime-counter changes cannot move historical native views into this period');
$metrics=vp3_analytics_intelligence_property_metrics_v310($pdo,1,['id'=>13,'property_type'=>'native']);
reliability_check($metrics['current_views']===1&&$metrics['current_profile_visits']===2&&empty(vp3_analytics_intelligence_spike_v310($metrics)['spike']),'one returning browser with inflated lifetime views cannot create a false spike');
$foreignMetrics=vp3_analytics_intelligence_property_metrics_v310($pdo,1,['id'=>21,'property_type'=>'native']);
reliability_check($foreignMetrics['ready']===false&&empty(vp3_analytics_intelligence_spike_v310($foreignMetrics)['spike']),'foreign property identifiers cannot acquire native owner metrics');

// A fixed database clock makes current/baseline boundaries deterministic in both engines.
$pdo->exec("INSERT INTO vp3_radar_properties(id,owner_user_id,property_type,label,domain,is_active) VALUES(17,1,'external','Boundary fixture','boundary.example',1)");
$session=$pdo->prepare("INSERT INTO vp3_radar_sessions(id,property_id,owner_user_id,visitor_type,page_view_count,last_seen_at,referrer_host) VALUES(117,17,1,'human',999999,?,'')");$session->execute([$clock['to']]);
foreach([['-8 days',5000],['-8 days -1 second',5001],['-24 hours',5002],['-24 hours -1 second',5003],['+1 second',5004],['-100 days',5005]] as [$offset,$id])reliability_event($pdo,$id,1,17,117,null,'analytics_page_view',$stamp($offset),$browser('page_view'));
$metrics=vp3_analytics_intelligence_property_metrics_v310($pdo,1,['id'=>17,'property_type'=>'external']);
reliability_check($metrics['current_views']===1&&$metrics['baseline_views']===2,'spike windows are disjoint and exclude older and future events');
reliability_check($metrics['current_sessions']===1&&$metrics['baseline_sessions']===2,'a session contributes once per active aligned day in the baseline and once today');
$pdo->exec("UPDATE vp3_radar_sessions SET last_seen_at='".$stamp('-100 days')."' WHERE id=117");
$again=vp3_analytics_intelligence_property_metrics_v310($pdo,1,['id'=>17,'property_type'=>'external']);
reliability_check($again===$metrics,'mutating a session last-seen timestamp cannot manufacture a traffic spike or erase its baseline');
reliability_event($pdo,5006,1,17,117,null,'analytics_page_view',$stamp('-7 days -1 second'),$browser('page_view'));
reliability_event($pdo,5007,1,17,117,null,'analytics_page_view',$stamp('-7 days'),$browser('page_view'));
$bucketMetrics=vp3_analytics_intelligence_property_metrics_v310($pdo,1,['id'=>17,'property_type'=>'external']);
reliability_check($bucketMetrics['baseline_sessions']===3&&$bucketMetrics['baseline_views']===4,'baseline session buckets change exactly at aligned 24-hour boundaries, not calendar midnight');
$pdo->exec("INSERT INTO vp3_radar_properties(id,owner_user_id,property_type,label,domain,is_active) VALUES(18,1,'external','Steady sessions','steady.example',1)");
$steadySession=$pdo->prepare("INSERT INTO vp3_radar_sessions(id,property_id,owner_user_id,agent_contact_id,visitor_type,page_view_count,last_seen_at,referrer_host) VALUES(?,18,1,?, ?,999999,?,'')");
$eventId=9000;
for($i=0;$i<8;$i++){
    $contact=$i<4?null:31;$type=$contact===null?'human':'ai_crawler';
    $steadySession->execute([180+$i,$contact,$type,$clock['to']]);
    for($day=0;$day<7;$day++){
        $at=$time->modify('-8 days')->modify('+'.$day.' days')->modify('+1 hour')->format('Y-m-d H:i:s');
        reliability_event($pdo,$eventId++,1,18,180+$i,$contact,$contact===null?'analytics_page_view':'external_agent_page_view',$at,$contact===null?$browser('page_view'):[]);
    }
    reliability_event($pdo,$eventId++,1,18,180+$i,$contact,$contact===null?'analytics_page_view':'external_agent_page_view',$stamp('-1 hour'),$contact===null?$browser('page_view'):[]);
}
$steady=vp3_analytics_intelligence_property_metrics_v310($pdo,1,['id'=>18,'property_type'=>'external']);
reliability_check($steady['current_sessions']===8&&$steady['baseline_sessions']===56&&$steady['baseline_human_sessions']===28&&$steady['baseline_agent_sessions']===28,'persistent sessions contribute one observation per active baseline day for each actor class');
reliability_check(empty(vp3_analytics_intelligence_spike_v310($steady)['spike']),'eight steady daily sessions do not create a false spike against seven days of the same activity');
foreach(range(0,13) as $i)reliability_visit($pdo,6000+$i,1,301,$stamp('-'.($i+1).' hours'));
$metrics=vp3_analytics_intelligence_property_metrics_v310($pdo,1,['id'=>13,'property_type'=>'native']);
$spike=vp3_analytics_intelligence_spike_v310($metrics);
reliability_check(!empty($spike['spike'])&&!empty($spike['visit_spike'])&&empty($spike['view_spike']),'a real increase in timestamped native visits still creates a separately labeled visit signal');
$signals=vp3_analytics_intelligence_signals_v310($pdo,['id'=>1],false);
$nativeSignal=array_values(array_filter($signals,static fn(array $r):bool=>(int)$r['property_id']===13))[0]??null;
reliability_check(is_array($nativeSignal)&&str_contains($nativeSignal['body'],'Profile visit events')&&str_contains($nativeSignal['body'],'database timezone'),'signal copy states the evidence unit and exact reporting clock');

$pdo->exec("UPDATE vp3_radar_events SET details_json='not json' WHERE id=203");
analytics_failure(fn()=>vp3_analytics_dashboard_state($pdo,['id'=>1],11,30),'malformed required browser metadata is unavailable rather than silently zero');
$other=vp3_analytics_dashboard_state($pdo,['id'=>1],12,30);
reliability_check($other['ready']===true&&$other['stats']['human_page_views']===1,'an exact unrelated property does not depend on broken metadata elsewhere');
$q=$pdo->prepare('UPDATE vp3_radar_events SET details_json=? WHERE id=203');$q->execute([json_encode($browser('purchase'))]);
$pdo->failClock=true;
analytics_failure(fn()=>vp3_analytics_dashboard_state($pdo,['id'=>1],11,30),'dashboard clock failure cannot invent a reporting period');
try{profile_revenue_intelligence_v180($pdo,1);reliability_check(false,'revenue clock failure must throw');}catch(RuntimeException $e){reliability_check(!str_contains($e->getMessage(),'SECRET')&&$e->getPrevious() instanceof PDOException,'revenue clock failure propagates a safe unavailable result instead of empty success');}
$pdo->failClock=false;$pdo->failProfile=true;
analytics_failure(fn()=>profile_revenue_intelligence_v180($pdo,1),'revenue event query failure cannot become successful empty totals');
$pdo->failProfile=false;
$pdo->exec('DELETE FROM profile_events WHERE owner_user_id=1');
$zero=profile_revenue_intelligence_v180($pdo,1);
reliability_check($zero['ready']===true&&$zero['coverage']==='complete'&&$zero['periods']['30d']['current']['conversions']===0,'a complete empty revenue ledger remains a verified zero');
$meta=['target_id'=>10,'target_title'=>'Fixture product','source'=>'commerce_payment_verified','value_cents'=>1200,'currency'=>'usd'];
reliability_visit($pdo,7000,1,301,$clock['to'],'product_converted',$meta);
reliability_visit($pdo,7001,1,301,$stamp('+1 second'),'product_converted',$meta);
reliability_visit($pdo,7002,1,301,$stamp('-7 days'),'product_intent',['target_id'=>10]);
$priorZone=date_default_timezone_get();date_default_timezone_set('America/Los_Angeles');
$revenue=profile_revenue_intelligence_v180($pdo,1);date_default_timezone_set($priorZone);
reliability_check($revenue['periods']['today']['current']['conversions']===1&&$revenue['periods']['7d']['current']['conversions']===1&&$revenue['periods']['30d']['current']['conversions']===1,'an outcome at the as-of second is included consistently; a future event is excluded');
reliability_check($revenue['periods']['7d']['current']['product_intents']===1&&$revenue['periods']['7d']['previous']['product_intents']===0,'current and previous revenue periods do not overlap at the start boundary');
reliability_check($revenue['revenue_all_time'][0]['value_cents']===1200&&$revenue['top_targets'][0]['conversions']===1&&$revenue['sources'][0]['conversions']===1,'all-time values, targets and sources share the same as-of cutoff despite a different PHP timezone');

if($driver==='mysql'){
    $pdo->fixtureClock=null;$pdo->exec("SET time_zone='-07:00'");
    $localClock=vp3_analytics_reporting_window_v1($pdo,7);
    reliability_check(str_contains($localClock['zone'],'UTC-07:00'),'native MySQL reporting explicitly preserves a non-UTC session timezone');
    $pdo->exec("INSERT INTO vp3_radar_properties(id,owner_user_id,property_type,label,domain,is_active) VALUES(19,1,'external','Timezone fixture','timezone.example',1)");
    reliability_event($pdo,8000,1,19,null,null,'analytics_page_view',$localClock['from'],$browser('page_view'));
    reliability_event($pdo,8001,1,19,null,null,'analytics_page_view',(new DateTimeImmutable($localClock['from'],new DateTimeZone('UTC')))->modify('-1 second')->format('Y-m-d H:i:s'),$browser('page_view'));
    // Freeze the native server clock for the aggregate read, retaining its own -07:00 zone.
    $serverUnix=(int)$pdo->query('SELECT UNIX_TIMESTAMP()')->fetchColumn();$pdo->exec('SET timestamp='.$serverUnix);
    $localClock=vp3_analytics_reporting_window_v1($pdo,7);
    $fix=$pdo->prepare('UPDATE vp3_radar_events SET occurred_at=? WHERE id=?');$fix->execute([$localClock['from'],8000]);$fix->execute([(new DateTimeImmutable($localClock['from'],new DateTimeZone('UTC')))->modify('-1 second')->format('Y-m-d H:i:s'),8001]);
    $local=vp3_analytics_dashboard_state($pdo,['id'=>1],19,7);
    reliability_check($local['stats']['page_views']===1&&str_contains($local['reporting_window']['zone'],'UTC-07:00'),'native MySQL date and JSON aggregates use the database window without PHP/UTC drift');
    $pdo->exec('SET timestamp=0');$pdo->exec("SET time_zone='+00:00'");$pdo->fixtureClock=$clock['to'];
}
$pdo->exec('DROP TABLE profile_events');
analytics_failure(fn()=>vp3_analytics_dashboard_state($pdo,['id'=>1],13,30),'missing native event storage is unavailable rather than zero Profile visits');
$external=vp3_analytics_dashboard_state($pdo,['id'=>1],12,30);
reliability_check($external['ready']===true,'an external selection still reads when unrelated native history is unavailable');
analytics_failure(fn()=>profile_revenue_intelligence_v180($pdo,1),'missing revenue storage cannot report successful zero');
echo 'PROFILE_ANALYTICS_RELIABILITY_V1=PASS ('.$checks.' cases; '.$driver.'; native aggregate SQL)'.PHP_EOL;
