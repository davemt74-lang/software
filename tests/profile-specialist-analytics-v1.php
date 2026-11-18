<?php
declare(strict_types=1);

require_once __DIR__.'/fixtures/profile-specialist-sources-v1.php';
require_once __DIR__.'/fixtures/profile-specialist-analytics-v1.php';
require_once dirname(__DIR__).'/includes/profile-specialist-analytics-v1.php';

$checks=0;
function psa_check(bool $ok,string $label): void
{
    if(!$ok)throw new RuntimeException('FAIL '.$label);
    $GLOBALS['checks']++;echo 'PASS '.$label.PHP_EOL;
}
function psa_reject(callable $read,int $code,string $label): void
{
    try{$read();}catch(RuntimeException $error){psa_check($error->getCode()===$code&&!str_contains($error->getMessage(),'SECRET'),$label);return;}
    throw new RuntimeException('FAIL '.$label);
}
function psa_snapshot(): array
{
    $out=pa4b_snapshot()+pp_no_external_snapshot();
    foreach(['vp3_radar_properties','vp3_radar_sessions','vp3_radar_events','profile_visit_sessions','user_profiles','profile_calls_pa3c'] as $table)
        $out[$table]=db()->query('SELECT * FROM '.$table)->fetchAll(PDO::FETCH_ASSOC);
    return $out;
}

pfs_reset();
$pdo=db();
profile_specialist_analytics_fixture_v1($pdo);
$scope=pfs_scope();$before=psa_snapshot();
$all=pa4b_readonly(fn()=>vp3_profile_specialist_analytics_v1($pdo,$scope,0,30));
psa_check($all['ready']===true&&$all['coverage']==='complete'&&$all['stats']['page_views']===6&&$all['stats']['sessions']===4,'the workspace uses complete native owned aggregates');
psa_check($all['native_profile_coverage']==='ready'&&$all['native_included']===true&&$all['stats']['native_visit_events']===2,'timestamped native visits remain separate from website events');
$one=pa4b_readonly(fn()=>vp3_profile_specialist_analytics_v1($pdo,$scope,11,7));
psa_check($one['property_id']===11&&$one['days']===7&&$one['stats']['page_views']===4&&!$one['native_included'],'a selected property and reporting window remain exact');
$revenue=pa4b_readonly(fn()=>vp3_profile_specialist_revenue_v1($pdo,$scope));
psa_check($revenue['ready']===true&&$revenue['coverage']==='complete'&&$revenue['value_model']==='recorded_conversion_value'
    &&isset($revenue['periods']['today'],$revenue['periods']['7d'],$revenue['periods']['30d']),'existing conversion intelligence retains its explicit event-value model and periods');
psa_check($before===psa_snapshot(),'analytics/revenue reads do not initialize identities, write data, dispatch providers or acknowledge activity');
psa_check(!str_contains(json_encode([$all,$one,$revenue],JSON_THROW_ON_ERROR),'SECRET'),'aggregate projections omit raw session, credential and event metadata');
$private=vp3_profile_specialist_analytics_v1($pdo,pfs_scope(8,12),0,30);
psa_check($private['stats']===$all['stats'],'a current private named Agent reads the same owner metrics independently of the public responder');
psa_check(!$pdo->inTransaction(),'an owned analytics snapshot is closed before returning to the endpoint');

foreach([[-1,30],[21,30],[0,14]] as [$property,$days])psa_reject(fn()=>vp3_profile_specialist_analytics_v1($pdo,$scope,$property,$days),422,'invalid or foreign property/period never broadens the read: '.$property.'/'.$days);
psa_reject(fn()=>vp3_profile_specialist_analytics_v1($pdo,array_replace($scope,['conversation_id'=>13]),0,30),403,'a foreign private Chat cannot read owner analytics');
$pdo->exec('UPDATE user_agents SET is_active=0 WHERE id=8');
psa_reject(fn()=>vp3_profile_specialist_revenue_v1($pdo,array_replace($scope,['agent_id'=>8,'conversation_id'=>12])),403,'a retired private Agent cannot retrieve revenue');
$pdo->exec('UPDATE user_agents SET is_active=1 WHERE id=8');
$pdo->exec('UPDATE user_profiles SET is_public=0 WHERE user_id=1');
psa_check(vp3_profile_specialist_analytics_v1($pdo,$scope,0,30)['stats']===$all['stats'],'private analytics remains available when the public Profile is hidden');
$pdo->exec('UPDATE user_profiles SET is_public=1 WHERE user_id=1');

$pdo->exec('DELETE FROM vp3_radar_properties WHERE id=13');$before=psa_snapshot();
$noNative=vp3_profile_specialist_analytics_v1($pdo,$scope,0,30);
psa_check($noNative['native_profile_coverage']==='unconfigured'&&!$noNative['native_included'],'a missing native property has explicit coverage rather than a fabricated Profile total');
psa_check($before===psa_snapshot(),'workspace reads do not recreate a missing native property');
analytics_agent_fixture_v1($pdo);

$pdo->failSql='FROM profile_events';
psa_reject(fn()=>vp3_profile_specialist_revenue_v1($pdo,$scope),503,'failed revenue reads remain unavailable with no internal diagnostic leak');
psa_reject(fn()=>vp3_profile_specialist_analytics_v1($pdo,$scope,0,30),503,'failed native visit reads cannot establish zero Profile visits');
$pdo->failSql='';
psa_check(!$pdo->inTransaction(),'failed reads close their owned snapshots');
$pdo->exec('ALTER TABLE vp3_radar_events RENAME TO vp3_radar_events_unavailable');
try{psa_reject(fn()=>vp3_profile_specialist_analytics_v1($pdo,$scope,0,30),503,'missing Analytics storage cannot become successful empty totals');}
finally{$pdo->exec('ALTER TABLE vp3_radar_events_unavailable RENAME TO vp3_radar_events');}

// MySQL changes ownership on a separate native connection while the aggregate
// snapshot is open. SQLite's in-memory fixture injects the same source change.
$moved=false;
$pdo->afterSql=static function(string $sql,PA3PDO $connection)use(&$moved):void{
    if(!$connection->inTransaction()||!str_contains($sql,'SELECT s.property_id,COUNT(*) AS sessions'))return;
    $connection->afterSql=null;
    $writer=$connection;
    if($connection->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'){
        $config=$GLOBALS['pa3Config'];$writer=new PDO($config['dsn'],$config['user'],$config['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
    }
    $writer->exec('UPDATE vp3_radar_properties SET owner_user_id=2 WHERE id=11');$moved=true;
};
psa_reject(fn()=>vp3_profile_specialist_analytics_v1($pdo,$scope,0,30),503,'property ownership changed during the snapshot cannot survive the current-property reread');
$pdo->afterSql=null;psa_check($moved,'the ownership race crosses the actual aggregate query');
$pdo->exec('UPDATE vp3_radar_properties SET owner_user_id=1 WHERE id=11');

// The fixture executes real SQL. Remove a permission only after the aggregate
// transaction has ended, so an in-snapshot authority check cannot pass this test.
foreach(['profile_agent.access','profile_chat.access','account.access'] as $permission){
    $sawSnapshot=false;$revoked=false;
    $pdo->afterSql=static function(string $sql,PA3PDO $connection)use(&$sawSnapshot,&$revoked,$permission):void{
        if($connection->inTransaction()&&str_contains($sql,'FROM vp3_radar_events'))$sawSnapshot=true;
        if(!$sawSnapshot||$connection->inTransaction()||!str_contains($sql,'FROM users'))return;
        $connection->afterSql=null;$connection->prepare('DELETE FROM role_permissions WHERE permission_key=?')->execute([$permission]);$revoked=true;
    };
    psa_reject(fn()=>vp3_profile_specialist_analytics_v1($pdo,$scope,0,30),403,'post-snapshot revocation withholds prior totals: '.$permission);
    $pdo->afterSql=null;
    psa_check($sawSnapshot&&$revoked,'revocation occurred outside the completed aggregate snapshot: '.$permission);
    $pdo->prepare("INSERT INTO role_permissions(role,permission_key) VALUES('member',?)")->execute([$permission]);
}
$sawRevenue=false;$revoked=false;
$pdo->afterSql=static function(string $sql,PA3PDO $connection)use(&$sawRevenue,&$revoked):void{
    if($connection->inTransaction()&&str_contains($sql,'FROM profile_events'))$sawRevenue=true;
    if(!$sawRevenue||$connection->inTransaction()||!str_contains($sql,'FROM users'))return;
    $connection->afterSql=null;$connection->exec("DELETE FROM role_permissions WHERE permission_key='profile_chat.access'");$revoked=true;
};
psa_reject(fn()=>vp3_profile_specialist_revenue_v1($pdo,$scope),403,'post-snapshot Profile revocation also withholds revenue');
$pdo->afterSql=null;psa_check($sawRevenue&&$revoked,'revenue permission test crosses the actual transaction boundary');
$pdo->exec("INSERT INTO role_permissions(role,permission_key) VALUES('member','profile_chat.access')");
psa_check(vp3_profile_specialist_analytics_v1($pdo,$scope,11,30)['ready']===true&&vp3_profile_specialist_revenue_v1($pdo,$scope)['ready']===true,'fresh authorized reads recover after unavailable or revoked states');

echo 'PROFILE_SPECIALIST_ANALYTICS_V1=PASS ('.$checks.' checks; driver='.$pdo->getAttribute(PDO::ATTR_DRIVER_NAME).'; skips=0)'.PHP_EOL;
