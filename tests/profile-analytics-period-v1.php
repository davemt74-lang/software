<?php
declare(strict_types=1);

/** Native SQL proof for fixed periods, current authority and honest partial data. */
require_once __DIR__.'/fixtures/profile-analytics-period-data-v1.php';
require_once dirname(__DIR__).'/includes/profile-analytics-period-v1.php';

final class ProfilePeriodPDO extends PDO
{
    public bool $readOnlyGuard=false;
    public string $failQuery='';
    public mixed $afterCommitHook=null;
    public array $readSql=[];
    private function inspect(string $sql): void
    {
        if($this->readOnlyGuard){
            $this->readSql[]=$sql;
            if(!preg_match('/\A\s*(SELECT|SHOW|PRAGMA|SET\s+TRANSACTION)\b/i',$sql))
                throw new RuntimeException('Analytics attempted a write: '.$sql);
        }
        if($this->failQuery!==''&&str_contains($sql,$this->failQuery))throw new PDOException('SECRET storage failure');
    }
    public function prepare(string $query,array $options=[]): PDOStatement|false{$this->inspect($query);return parent::prepare($query,$options);}
    public function query(string $query,?int $fetchMode=null,mixed ...$fetchModeArgs): PDOStatement|false
    {$this->inspect($query);return $fetchMode===null?parent::query($query):parent::query($query,$fetchMode,...$fetchModeArgs);}
    public function exec(string $statement): int|false{$this->inspect($statement);return parent::exec($statement);}
    public function commit(): bool
    {
        $result=parent::commit();
        if($hook=$this->afterCommitHook){$this->afterCommitHook=null;$guard=$this->readOnlyGuard;$this->readOnlyGuard=false;
            try{$hook($this);}finally{$this->readOnlyGuard=$guard;}}
        return $result;
    }
}

$config=['dsn'=>'sqlite::memory:','user'=>null,'password'=>null];
if($dsn=getenv('VP3_TEST_MYSQL_DSN')){
    $user=getenv('VP3_TEST_MYSQL_USER')?:'root';$password=getenv('VP3_TEST_MYSQL_PASS')?:'root';
    $admin=new PDO($dsn,$user,$password,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
    $database='vp3_profile_period_'.bin2hex(random_bytes(6));
    $admin->exec('CREATE DATABASE `'.$database.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    register_shutdown_function(static fn()=>$admin->exec('DROP DATABASE IF EXISTS `'.$database.'`'));
    $config=['dsn'=>preg_match('/dbname=[^;]+/',$dsn)?preg_replace('/dbname=[^;]+/','dbname='.$database,$dsn):rtrim($dsn,';').';dbname='.$database,'user'=>$user,'password'=>$password];
}
$pdo=new ProfilePeriodPDO($config['dsn'],$config['user'],$config['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$mysql=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql';
if($mysql){$pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES,false);$pdo->exec("SET SESSION time_zone='+05:30'");$pdo->exec('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');}
$fixture=profile_analytics_period_fixture_v1($pdo);
$scope=$fixture['scope'];$fixed=['ref'=>$fixture['ref']];$now=new DateTimeImmutable('2026-11-18T12:00:00Z');$checks=0;
function p5_check(bool $condition,string $label): void
{if(!$condition)throw new RuntimeException('FAIL '.$label);++$GLOBALS['checks'];echo 'PASS '.$label.PHP_EOL;}
function p5_read(?array $selection=null,?array $scope=null,?DateTimeImmutable $now=null): array
{
    $pdo=$GLOBALS['pdo'];$pdo->readOnlyGuard=true;
    try{return vp3_profile_analytics_period_v1($pdo,$scope??$GLOBALS['scope'],$selection??$GLOBALS['fixed'],$now??$GLOBALS['now']);}
    finally{$pdo->readOnlyGuard=false;}
}
function p5_reject(callable $read,int $code,string $label): void
{
    try{$read();}catch(Throwable $error){p5_check($error instanceof RuntimeException&&$error->getCode()===$code&&!str_contains($error->getMessage(),'SECRET'),$label);return;}
    throw new RuntimeException('FAIL '.$label);
}
function p5_metrics(array $result): array{return array_column($result['metrics'],null,'key');}
function p5_money(array $result,string $key,string $currency): array
{foreach($result['money'] as $row)if($row['key']===$key&&$row['currency']===$currency)return $row;throw new RuntimeException('Missing money row.');}
function p5_mutation(callable $work): void
{
    $pdo=$GLOBALS['pdo'];$pdo->beginTransaction();
    try{$work($pdo);}finally{if($pdo->inTransaction())$pdo->rollBack();}
}
function p5_snapshot(ProfilePeriodPDO $pdo): string
{
    $tables=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?$pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN)
        :$pdo->query("SELECT name FROM sqlite_master WHERE type='table' ORDER BY name")->fetchAll(PDO::FETCH_COLUMN);
    sort($tables);$rows=[];foreach($tables as $table)$rows[$table]=$pdo->query('SELECT * FROM '.$table)->fetchAll(PDO::FETCH_ASSOC);
    return serialize($rows);
}

$before=p5_snapshot($pdo);$baseline=p5_read();$m=p5_metrics($baseline);
p5_check($baseline['coverage']==='ready'&&count($m)===17,'all seventeen defined period measures use available verified storage');
p5_check($baseline['ref']===$fixture['ref']&&$baseline['window']['to_utc_ms']===$fixture['cutoff']*1000,'the selected fixed reference and cutoff are echoed exactly');
foreach(['visit_records'=>[3,1],'recognized_browser_contacts'=>[1,1],'accepted_visitor_turns'=>[2,1],'active_conversations'=>[2,1],
    'new_conversations'=>[2,1],'owner_takeovers'=>[1,0],'owner_reply_messages'=>[1,0],'agent_reply_messages'=>[0,1],
    'calls_offered'=>[2,1],'calls_accepted'=>[1,1],'calls_finished'=>[2,1],'median_call_accept_seconds'=>[12,20],
    'recorded_product_conversions'=>[1,1],'recorded_booking_conversions'=>[1,0],'verified_paid_orders'=>[2,0],
    'verified_refunded_orders'=>[1,0],'median_first_reply_seconds'=>[15,30]] as $key=>[$current,$previous])
    p5_check($m[$key]['current']['value']==$current&&$m[$key]['previous']['value']==$previous&&$m[$key]['current']['coverage']==='complete','verified current and comparable prior measure: '.$key);
p5_check($m['owner_takeovers']['comparison']['percent']===null&&$m['visit_records']['comparison']['percent']===200.0,'zero denominators have no invented rate while comparable nonzero periods have a delta');
p5_check($baseline['first_reply_cohorts']['current']===['eligible'=>2,'replied'=>1,'unanswered'=>1,'unverifiable'=>0,'coverage'=>'complete','reason'=>'complete'],'first reply median retains the new-conversation cohort and unanswered denominator');
p5_check($baseline['call_breakdowns']['current']['accepted']===['direct'=>0,'assisted'=>1]&&$baseline['call_breakdowns']['current']['finished']['declined']===1,'accepted modes and terminal outcomes are separate from talk duration');
p5_check(p5_money($baseline,'verified_payments','USD')['current']['value_minor']==='2000'
    &&p5_money($baseline,'verified_payments','USD')['previous']['value_minor']==='1000'
    &&p5_money($baseline,'verified_payments','EUR')['current']['value_minor']==='1500'
    &&p5_money($baseline,'verified_refunds','USD')['current']['value_minor']==='500','payments and refunds retain exact decimal-string amounts by currency');
p5_check(p5_snapshot($pdo)===$before&&!$pdo->inTransaction(),'period reads create no records, acknowledgements, schema or provider work and close their own transaction');
$serialized=json_encode($baseline,JSON_THROW_ON_ERROR);
p5_check(!str_contains($serialized,'fixture_analytics_merchant')&&!str_contains($serialized,'Exact historical visitor statement')&&!str_contains($serialized,'credential_hash')&&!str_contains($serialized,'fixture-owner-password'),'public projection exposes aggregate facts without raw private statements, credentials or merchant identities');
$later=p5_read(null,null,$now->modify('+3 days'));
p5_check($later['revision']===$baseline['revision']&&$later['window']===$baseline['window']&&$later['backlog']['as_of_utc_ms']!==$baseline['backlog']['as_of_utc_ms'],'time passage preserves fixed source identity while live backlog has its own observation time');

p5_mutation(function(PDO $pdo)use($baseline,$fixture){
    $pdo->exec("UPDATE profile_agent_conversations SET status='resolved' WHERE id=991003");
    $pdo->exec("UPDATE profile_agent_handoff_turns_pa3 SET state='cancelled' WHERE id=995003");
    $r=p5_read();$backlog=array_column($r['backlog']['metrics'],null,'key');
    p5_check($r['revision']===$baseline['revision']&&$r['backlog']['revision']!==$baseline['backlog']['revision'],'handling current backlog does not fabricate a change to the fixed completed period');
    p5_check($backlog['current_unresolved_conversations']['value']===0&&$backlog['current_unanswered_visitor_turns']['value']===0,'current unresolved threads and current unanswered accepted turns are explicit separate measures');
    p5_check($pdo->inTransaction(),'the reader preserves its caller transaction');
});
p5_mutation(function(PDO $pdo)use($baseline,$fixture){
    $pdo->prepare("UPDATE profile_events SET created_at='2099-12-31 23:59:59' WHERE owner_user_id=?")->execute([1]);
    p5_check(p5_read()['revision']===$baseline['revision'],'legacy DB wall-clock dates never override the dedicated epoch recorded time');
    $pdo->prepare('UPDATE profile_events SET recorded_at_utc_ms=? WHERE id=993003')->execute([$fixture['cutoff']*1000]);
    p5_check(p5_metrics(p5_read())['visit_records']['current']['value']===2,'the reporting upper boundary is exclusive');
    $pdo->prepare('UPDATE profile_events SET recorded_at_utc_ms=? WHERE id=993003')->execute([($fixture['cutoff']-86400)*1000]);
    p5_check(p5_metrics(p5_read())['visit_records']['current']['value']===3,'the reporting lower boundary is inclusive');
});
p5_mutation(function(PDO $pdo)use($fixture){
    $insert=$pdo->prepare("INSERT INTO profile_events(id,owner_user_id,profile_session_id,visitor_user_id,profile_agent_id,event_type,metadata_json,recorded_at_utc_ms) SELECT ?,owner_user_id,profile_session_id,visitor_user_id,profile_agent_id,event_type,metadata_json,recorded_at_utc_ms FROM profile_events WHERE id=993003");
    for($i=0;$i<101;$i++)$insert->execute([994000+$i]);
    p5_check(p5_metrics(p5_read())['visit_records']['current']['value']===104,'aggregate totals include more than the bounded activity feed can contain');
});
p5_mutation(function(PDO $pdo)use($baseline,$fixture){
    $pdo->exec("INSERT INTO profile_events(id,owner_user_id,event_type,created_at) VALUES(999900,1,'profile_view','2000-01-01 00:00:00')");
    $r=p5_read();$point=p5_metrics($r)['visit_records'];
    p5_check($point['current']['value']===null&&$point['current']['observed_value']===3&&$point['current']['reason']==='clock_gap'&&$point['comparison']['delta']===null,'unclocked post-activation records withhold full totals and comparisons while preserving the observed subset');
    $pdo->exec('UPDATE profile_events SET clock_legacy_token_v1=(SELECT token FROM profile_event_clock_activation_v1 WHERE id=1) WHERE id=999900');
    p5_check(p5_read()['revision']===$baseline['revision'],'proved pre-activation legacy rows do not pretend to have historical UTC times');
    $pdo->prepare('UPDATE profile_event_clock_activation_v1 SET activated_at_utc_ms=? WHERE id=1')->execute([($fixture['cutoff']-43200)*1000]);
    $p=p5_metrics(p5_read())['visit_records']['current'];
    p5_check($p['coverage']==='partial'&&$p['reason']==='before_clock_activation'&&$p['observed_value']===3,'a reporting interval crossing the activation baseline remains explicitly partial');
});
p5_mutation(function(PDO $pdo){
    $pdo->exec('UPDATE user_profiles SET share_visit_identity=0 WHERE user_id=98002');
    $m=p5_metrics(p5_read());
    p5_check($m['visit_records']['current']['value']===3&&$m['recognized_browser_contacts']['current']['value']===null&&$m['recognized_browser_contacts']['current']['observed_value']===0,'current identity nondisclosure withholds recognized contacts without discarding verified visit records');
    $pdo->exec('UPDATE user_profiles SET share_visit_identity=1 WHERE user_id=98002');
    $pdo->exec("UPDATE profile_events SET metadata_json='{}' WHERE id=993001");
    p5_check(p5_metrics(p5_read())['recognized_browser_contacts']['current']['coverage']==='partial','unproved CRM identity cannot establish an authenticated browser contact');
});
p5_mutation(function(PDO $pdo){
    $pdo->exec("UPDATE profile_agent_messages SET context_json='{}' WHERE id=991102");
    $r=p5_read();$m=p5_metrics($r);
    p5_check($m['owner_reply_messages']['current']['value']===null&&$m['owner_reply_messages']['current']['observed_value']===0,'an owner message without its exact operation marker is not a verified response');
    p5_check($r['first_reply_cohorts']['current']['unverifiable']===1&&$r['first_reply_cohorts']['current']['unanswered']===1&&$m['median_first_reply_seconds']['current']['value']===null,'missing reply receipts remain unverifiable rather than lowering reply delay or being counted as unanswered');
});
p5_mutation(function(PDO $pdo){
    $pdo->exec("UPDATE agent_workflow_receipts SET result_json='{}' WHERE id=998001");
    $r=p5_read();p5_check(p5_metrics($r)['agent_reply_messages']['previous']['value']===null&&$r['first_reply_cohorts']['previous']['unverifiable']===1,'Agent reply counts require an exact completed native execution receipt');
});
p5_mutation(function(PDO $pdo)use($fixture,$baseline){
    // A late native turn in an existing thread cannot turn it into a new conversation.
    $row=$pdo->query('SELECT * FROM profile_agent_handoff_turns_pa3 WHERE id=995002')->fetch(PDO::FETCH_ASSOC);
    $row['id']=995020;$row['visitor_message_id']=991220;$row['client_request_id']=str_repeat('a',32);$row['created_at']=gmdate('Y-m-d H:i:s',$fixture['cutoff']-3600);
    $message=$pdo->query('SELECT * FROM profile_agent_messages WHERE id=991201')->fetch(PDO::FETCH_ASSOC);$message['id']=991220;$message['created_at']=$row['created_at'];
    profile_analytics_period_fixture_row_v1($pdo,'profile_agent_messages',$message);profile_analytics_period_fixture_row_v1($pdo,'profile_agent_handoff_turns_pa3',$row);
    $m=p5_metrics(p5_read());p5_check($m['accepted_visitor_turns']['current']['value']===3&&$m['new_conversations']['current']['value']===2,'the first-reply cohort is based on first native conversation start, not each fresh visitor turn');
});
p5_mutation(function(PDO $pdo)use($baseline){
    $pdo->exec("UPDATE profile_calls_pa3c SET state='ending' WHERE id=994001");
    $m=p5_metrics(p5_read());p5_check($m['calls_finished']['current']['value']===1&&$m['calls_accepted']['current']['value']===1,'calls still ending are not completed outcomes');
    $pdo->exec('UPDATE profile_calls_pa3c SET accepted_at_ms=created_at_ms-1 WHERE id=994001');
    p5_check(p5_metrics(p5_read())['median_call_accept_seconds']['current']['value']===null,'impossible acceptance ordering cannot produce a negative timing measure');
});

p5_mutation(function(PDO $pdo)use($baseline){
    $pdo->exec("UPDATE agent_commerce_checkout_attempts_v800 SET external_payment_id='foreign-payment' WHERE id=993402");
    $r=p5_read();$m=p5_metrics($r);
    p5_check($m['verified_paid_orders']['current']['value']===null&&$m['verified_paid_orders']['current']['observed_value']===1,'a mismatched payment receipt cannot complete its order');
    p5_check($m['verified_refunded_orders']['current']['value']===null&&p5_money($r,'verified_payments','EUR')['current']['observed_value_minor']==='1500','missing payment proof also withholds dependent refunds and labels the known currency subset');
    p5_check($m['recorded_product_conversions']['current']['value']===1,'recorded conversion events remain distinct from verified Commerce receipts');
});
p5_mutation(function(PDO $pdo){
    $pdo->exec("UPDATE agent_commerce_checkout_attempts_v800 SET creation_request_json='{}' WHERE id=993402");
    p5_check(p5_metrics(p5_read())['verified_paid_orders']['current']['observed_value']===1,'a nominally completed attempt without its frozen request and merchant proof is not verified payment');
});
p5_mutation(function(PDO $pdo){
    $pdo->exec('DELETE FROM agent_commerce_payments_v800 WHERE id=992401');
    $r=p5_read();$m=p5_metrics($r);
    p5_check($m['verified_paid_orders']['current']['value']===null&&$m['verified_paid_orders']['current']['observed_value']===1,
        'an incomplete cumulative payment ledger cannot certify when the affected order reached payment completion');
    p5_check(p5_money($r,'verified_payments','USD')['previous']['value_minor']===null
        &&p5_money($r,'verified_payments','USD')['previous']['observed_value_minor']==='0',
        'known missing payment amounts cannot be rendered as a verified prior zero');
});
p5_mutation(function(PDO $pdo){
    $pdo->exec('UPDATE agent_commerce_orders_v800 SET amount_refunded_cents=1000 WHERE id=992201');
    $r=p5_read();
    p5_check(p5_metrics($r)['verified_refunded_orders']['current']['value']===null
        &&p5_money($r,'verified_refunds','USD')['current']['observed_value_minor']==='500',
        'an incomplete cumulative refund ledger preserves the observed subset with partial coverage');
});
p5_mutation(function(PDO $pdo)use($fixture,$baseline){
    $payment=$pdo->query('SELECT * FROM agent_commerce_payments_v800 WHERE id=992402')->fetch(PDO::FETCH_ASSOC);
    $attempt=$pdo->query('SELECT * FROM agent_commerce_checkout_attempts_v800 WHERE id=993402')->fetch(PDO::FETCH_ASSOC);
    $payment['id']=992450;$payment['checkout_attempt_id']=993450;$payment['external_payment_id']='fixture_payment_after_cutoff';
    $payment['received_at']=gmdate('Y-m-d H:i:s',$fixture['cutoff']+86400);
    $attempt['id']=993450;$attempt['external_payment_id']=$payment['external_payment_id'];
    $attempt['external_session_id']='fixture_session_after_cutoff';$attempt['provider_order_id']=$attempt['external_session_id'];
    $attempt['idempotency_key']=hash('sha256','fixture_after_cutoff');
    profile_analytics_period_fixture_row_v1($pdo,'agent_commerce_checkout_attempts_v800',$attempt);
    profile_analytics_period_fixture_row_v1($pdo,'agent_commerce_payments_v800',$payment);
    $pdo->exec('UPDATE agent_commerce_orders_v800 SET amount_paid_cents=5000 WHERE id=992201');
    p5_check(p5_read()['revision']===$baseline['revision'],'valid later payment completions reconcile globally without changing earlier period metrics or identity');
});
p5_mutation(function(PDO $pdo){
    $pdo->exec('UPDATE agent_commerce_refunds_v800 SET amount_cents=2500 WHERE id=992601');
    $r=p5_read();p5_check(p5_metrics($r)['verified_refunded_orders']['current']['observed_value']===0
        &&p5_metrics($r)['verified_paid_orders']['current']['value']===2,'a refund exceeding its payment is withheld without erasing verified payment completion');
});
p5_mutation(function(PDO $pdo)use($fixture,$baseline){
    $pdo->prepare('UPDATE agent_commerce_refunds_v800 SET completed_at=? WHERE id=992601')->execute([gmdate('Y-m-d H:i:s',$fixture['cutoff']+3600)]);
    $r=p5_read();p5_check(p5_metrics($r)['verified_refunded_orders']['current']['value']===0&&p5_metrics($r)['verified_paid_orders']['current']['value']===2,'a refund is dated independently and never rewrites the original paid-order completion');
});
p5_mutation(function(PDO $pdo){
    $pdo->exec('UPDATE agent_commerce_provider_connections_v800 SET owner_user_id=2 WHERE id=992001');
    $m=p5_metrics(p5_read());p5_check($m['verified_paid_orders']['current']['value']===null&&$m['verified_paid_orders']['current']['observed_value']===0,'foreign merchant ownership cannot contribute private Commerce analytics');
});
p5_check(vp3_profile_analytics_add_v1('9007199254740993','9007199254740993')==='18014398509481986'
    &&vp3_profile_analytics_sub_v1('0','9007199254740993')==='-9007199254740993','money aggregation and differences retain integers beyond JavaScript float precision');

foreach(['profile_events'=>'events','profile_calls_pa3c'=>'calls','agent_commerce_payments_v800'=>'commerce','profile_agent_handoff_turns_pa3'=>'conversations'] as $table=>$group){
    $pdo->failQuery='FROM '.$table.' WHERE';
    if($table==='agent_commerce_payments_v800')$pdo->failQuery='FROM '.$table.' p';
    try{$r=p5_read();p5_check($r['groups'][$group]['coverage']==='unavailable'||($group==='commerce'&&$r['groups'][$group]['coverage']==='limited'),$group.' SQL failure remains explicit unavailable coverage');
        p5_check(!str_contains(json_encode($r,JSON_THROW_ON_ERROR),'SECRET'),'SQL failure details are excluded from the '.$group.' projection');}
    finally{$pdo->failQuery='';}
}
$pdo->exec('ALTER TABLE agent_commerce_payments_v800 RENAME TO agent_commerce_payments_hidden');
try{$r=p5_read();p5_check(p5_metrics($r)['verified_paid_orders']['current']['value']===null
    &&p5_metrics($r)['verified_paid_orders']['current']['reason']==='storage_unconfigured','missing payment storage is unknown, never a verified zero');}
finally{$pdo->exec('ALTER TABLE agent_commerce_payments_hidden RENAME TO agent_commerce_payments_v800');}

p5_mutation(function(PDO $pdo)use($baseline){
    $pdo->exec('UPDATE user_profiles SET is_public=0,profile_agent_enabled=0 WHERE user_id=1');
    p5_check(p5_read()['revision']===$baseline['revision'],'private owner historical metrics remain available with public Profile and public responder off');
});
foreach(['account.access','chat.access','profile_agent.access','profile_chat.access'] as $permission){
    p5_mutation(function(PDO $pdo)use($permission){$pdo->prepare('DELETE FROM role_permissions WHERE permission_key=?')->execute([$permission]);
        p5_reject(fn()=>p5_read(),403,'current required permission is checked: '.$permission);});
}
p5_mutation(function(PDO $pdo){$pdo->exec('UPDATE user_agents SET owner_user_id=2 WHERE id=7');p5_reject(fn()=>p5_read(),403,'a formerly owned private Agent does not retain Profile analytics access');});
p5_mutation(function(PDO $pdo){$pdo->exec('UPDATE chat_conversations SET user_id=2 WHERE id=11');p5_reject(fn()=>p5_read(),403,'a foreign private Chat does not inherit owner analytics access');});
p5_mutation(function(PDO $pdo){$pdo->exec('UPDATE users SET is_active=0 WHERE id=1');p5_reject(fn()=>p5_read(),403,'an inactive owner cannot retrieve stored analytics');});
p5_reject(fn()=>p5_read(null,array_replace($scope,['conversation_id'=>99999])),403,'an unknown private Chat cannot broaden the source');
p5_reject(fn()=>p5_read(null,array_replace($scope,['principal'=>['kind'=>'user_agent','agent_id'=>7,'owner_user_id'=>1,'viewer_user_id'=>2]])),403,'a participant or alternate viewer cannot borrow the private owner principal');

foreach([['period'=>0],['period'=>'01'],['period'=>90],['ref'=>$fixture['ref'],'period'=>1],['ref'=>[]],['period'=>1,'owner_user_id'=>2]] as $selection)
    p5_reject(fn()=>p5_read($selection),422,'invalid exact reporting selection is rejected: '.json_encode($selection));

p5_mutation(function(PDO $pdo)use($baseline){
    $pdo->exec("UPDATE users SET timezone='America/Phoenix' WHERE id=1");
    $r=p5_read(['period'=>7]);
    p5_check($r['window']['timezone']==='America/Phoenix'&&$r['window']['timezone_source']==='explicit'&&str_ends_with($r['window']['to_local'],'-07:00'),'current periods resolve the current explicit owner-local timezone');
    p5_check(p5_read()['revision']===$baseline['revision'],'an old exact reference retains its original timezone after the owner changes settings');
});
$pdo->exec("CREATE TABLE agent_scheduling_schedules(id BIGINT PRIMARY KEY,owner_user_id BIGINT,timezone VARCHAR(80),is_default INTEGER,is_active INTEGER)");
p5_mutation(function(PDO $pdo){
    $pdo->exec("UPDATE users SET timezone='' WHERE id=1");
    $r=p5_read(['period'=>1]);p5_check($r['window']['timezone']==='UTC'&&$r['window']['timezone_source']==='utc_fallback','missing owner and schedule timezone has visible UTC fallback provenance');
    $pdo->exec("INSERT INTO agent_scheduling_schedules VALUES(1,1,'America/New_York',0,1),(2,1,'America/Phoenix',1,1),(3,2,'Asia/Tokyo',1,1)");
    $r=p5_read(['period'=>1]);p5_check($r['window']['timezone']==='America/Phoenix'&&$r['window']['timezone_source']==='schedule','the owner default schedule supplies timezone before unrelated schedules');
});

// Revocation after the completed aggregate snapshot must suppress its result.
$revoked=false;
$pdo->afterCommitHook=static function(PDO $pdo)use(&$revoked){$pdo->exec("DELETE FROM role_permissions WHERE role='fan' AND permission_key='profile_chat.access'");$revoked=true;};
p5_reject(fn()=>p5_read(),403,'post-snapshot permission revocation withholds the completed aggregate');
p5_check($revoked&&!$pdo->inTransaction(),'final authority uses a fresh transaction and closes it after revocation');
$pdo->exec("INSERT INTO role_permissions(role,permission_key) VALUES('fan','profile_chat.access')");
if($mysql){
    // Establish an older ordinary read view, then revoke on another connection.
    // The current locking reader must not accept its caller's obsolete grants.
    $writer=new PDO($config['dsn'],$config['user'],$config['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
    $pdo->beginTransaction();$pdo->query("SELECT * FROM role_permissions WHERE role='fan'")->fetchAll();
    $writer->exec("DELETE FROM role_permissions WHERE role='fan' AND permission_key='profile_chat.access'");
    try{p5_reject(fn()=>p5_read(),403,'native MySQL current grant locks reject an obsolete outer repeatable-read snapshot');}
    finally{$pdo->rollBack();$writer->exec("INSERT INTO role_permissions(role,permission_key) VALUES('fan','profile_chat.access')");}
    $pdo->exec('SET SESSION TRANSACTION ISOLATION LEVEL SERIALIZABLE');$pdo->beginTransaction();
    try{p5_check(p5_read()['coverage']==='ready','native MySQL supports an existing stronger serializable transaction');}
    finally{$pdo->rollBack();$pdo->exec('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');}
}
p5_check(p5_read()['revision']===$baseline['revision'],'fresh authorized reads recover to the same exact verified period');
echo 'PROFILE_ANALYTICS_PERIOD_V1=PASS ('.$checks.' checks; driver='.$pdo->getAttribute(PDO::ATTR_DRIVER_NAME).'; skips=0)'.PHP_EOL;
