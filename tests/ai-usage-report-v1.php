<?php
declare(strict_types=1);
require_once __DIR__.'/../includes/ai-usage-report-v1.php';
function db():PDO{return $GLOBALS['reportDb'];}
function table_exists(string $table):bool{
    $pdo=db();$q=$pdo->prepare($pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite'?"SELECT name FROM sqlite_master WHERE type='table' AND name=?":"SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?");$q->execute([$table]);return(bool)$q->fetchColumn();
}
function column_exists(string $table,string $column):bool{
    $pdo=db();if($pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite')return in_array($column,array_column($pdo->query('PRAGMA table_info('.$table.')')->fetchAll(PDO::FETCH_ASSOC),'name'),true);
    $q=$pdo->prepare('SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?');$q->execute([$table,$column]);return(bool)$q->fetchColumn();
}
function agent_runtime_v125_trace_id():string{return 'shared-trace';}
function report_check(bool $condition,string $message):void{if(!$condition)throw new RuntimeException($message);}
$mysql=($argv[1]??'')==='mysql';$database='vp3_report_'.bin2hex(random_bytes(6));
$pdo=$GLOBALS['reportDb']=new PDO($mysql?(getenv('VP3_TEST_MYSQL_DSN')?:throw new RuntimeException('MySQL DSN required')):'sqlite::memory:',
    $mysql?(getenv('VP3_TEST_MYSQL_USER')?:'root'):null,$mysql?(getenv('VP3_TEST_MYSQL_PASS')?:''):null,
    [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
if($mysql){$pdo->exec('CREATE DATABASE '.$database);$pdo->exec('USE '.$database);}
try{
    $config=['ai_cost_rates'=>['openai:test-model'=>['input_per_million_usd'=>2,'output_per_million_usd'=>8]]];
    if($mysql)ai_usage_accounting_v032_ensure_schema($pdo);
    else{
        $pdo->exec("CREATE TABLE ai_execution_ledger(id INTEGER PRIMARY KEY AUTOINCREMENT,user_id INTEGER,agent_id INTEGER,conversation_id INTEGER,cloud_ledger_id INTEGER UNIQUE,trace_id TEXT,source TEXT,provider TEXT,model TEXT,input_tokens INTEGER DEFAULT 0,output_tokens INTEGER DEFAULT 0,total_tokens INTEGER DEFAULT 0,cloud_tokens_charged INTEGER DEFAULT 0,estimated_cost_micros INTEGER,cost_rate_source TEXT,cost_currency TEXT,homeserver_state TEXT,fallback_used INTEGER,failure_class TEXT,latency_ms INTEGER,run_id INTEGER,created_at TEXT DEFAULT CURRENT_TIMESTAMP)");
        vp3_ai_service_usage_ensure_schema_v1($pdo);
    }
    $auto=$mysql?'BIGINT PRIMARY KEY AUTO_INCREMENT':'INTEGER PRIMARY KEY AUTOINCREMENT';
    $pdo->exec("CREATE TABLE ai_usage_ledger(id $auto,user_id INTEGER,scope VARCHAR(60),provider VARCHAR(40),model VARCHAR(120),input_tokens INTEGER,output_tokens INTEGER,total_tokens INTEGER,credit_tokens_used INTEGER,package_tokens_used INTEGER,trace_id VARCHAR(120),request_key VARCHAR(160),created_at DATETIME)");
    $debit=static function(int $owner,string $key,int $total=125)use($pdo):int{
        $q=$pdo->prepare("INSERT INTO ai_usage_ledger(user_id,scope,provider,model,input_tokens,output_tokens,total_tokens,package_tokens_used,credit_tokens_used,trace_id,request_key,created_at) VALUES(?,'transcription','openai','test-model',100,25,?,50,75,'shared-trace',?,'2026-11-10 11:00:00')");$q->execute([$owner,$total,$key]);return(int)$pdo->lastInsertId();
    };
    $unlinked=$debit(1,'direct:historic');$linked=$debit(1,'direct:linked');$toolLinked=$debit(1,'legacy:planner');$foreign=$debit(2,'direct:foreign');
    ai_usage_accounting_v032_record($pdo,['id'=>1],7,11,['source'=>'vp3_cloud','provider'=>'openai','model'=>'test-model','usage'=>['input_tokens'=>100,'output_tokens'=>25], 'cloud_request_key'=>'direct:linked']);
    $row=$pdo->query('SELECT * FROM ai_execution_ledger')->fetch();
    report_check((int)$row['cloud_ledger_id']===$linked&&(int)$row['cloud_tokens_charged']===125,'Exact server request key must link the true package+credit debit.');
    $pdo->exec("UPDATE ai_execution_ledger SET created_at='2026-11-10 12:00:00'");
    ai_usage_accounting_v032_record($pdo,['id'=>1],7,11,['source'=>'vp3_tool','cloud_tokens_debited'=>999]);
    $tool=(int)$pdo->lastInsertId();$row=$pdo->query('SELECT * FROM ai_execution_ledger WHERE id='.$tool)->fetch();
    report_check($row['cloud_ledger_id']===null&&(int)$row['cloud_tokens_charged']===0,'A local tool outcome must never claim a planner debit.');
    // Reproduce an old tool-linked debit; report must recover provider usage and
    // not count the stale local charge a second time.
    $pdo->exec("UPDATE ai_execution_ledger SET cloud_ledger_id=$toolLinked,cloud_tokens_charged=999,created_at='2026-11-10 12:01:00' WHERE id=$tool");
    ai_usage_accounting_v032_record($pdo,['id'=>1],7,11,['source'=>'vp3_cloud','provider'=>'openai','model'=>'test-model','cloud_request_key'=>'does-not-exist']);
    $unknown=(int)$pdo->lastInsertId();$row=$pdo->query('SELECT * FROM ai_execution_ledger WHERE id='.$unknown)->fetch();
    report_check($row['cloud_ledger_id']===null&&(int)$row['usage_known']===0&&$row['estimated_cost_micros']===null,'Missing provider usage and absent exact debit must remain unknown.');
    $pdo->exec("UPDATE ai_execution_ledger SET created_at='2026-11-10 12:02:00' WHERE id=$unknown");
    $now=new DateTimeImmutable('2026-11-10 20:00:00',new DateTimeZone('UTC'));
    $state=vp3_ai_usage_report_v1($pdo,1,[],$now);
    report_check((int)$state['summary']['requests']===4,'Expected three distinct provider calls plus one unknown call, no duplicate linked debit.');
    report_check((int)$state['summary']['total_tokens']===375&&(int)$state['summary']['cloud_tokens']===375,'Provider totals must include historical debit-only usage exactly once.');
    report_check((int)$state['summary']['estimated_cost_micros']===1200&&(int)$state['summary']['unknown_cost_requests']===1,'Known estimates and missing usage must stay distinct.');
    report_check((int)$state['overall']['local_requests']===1,'Local tool is visible separately from paid provider requests.');
    report_check(count(array_filter($state['recent'],fn($r)=>$r['origin']==='subscription'))===2,'Recover both historical unlinked and old tool-linked debits.');
    $all=vp3_ai_usage_report_v1($pdo,1,['view'=>'all'],$now);
    report_check((int)$all['summary']['requests']===5&&(int)$all['summary']['cloud_tokens']===375,'All executions must not double charge an old local/tool ledger link.');
    $export=vp3_ai_usage_report_export_v1($pdo,1);
    report_check(count($export)===5&&array_unique(array_map('intval',array_column($export,'user_id')))===[1],'CSV projection must share dedupe and owner boundaries.');
    $other=vp3_ai_usage_report_v1($pdo,2,[],$now);
    report_check((int)$other['summary']['requests']===1&&(int)$other['summary']['total_tokens']===125,'Usage projection must preserve owner boundaries.');
    $pdo->prepare("INSERT INTO ai_execution_ledger(user_id,source,provider,model,total_tokens,estimated_cost_micros,cost_rate_source,service_status,usage_known,created_at) VALUES(1,'vp3_cloud','openai','test-model',0,0,'config:openai:test-model','legacy',1,'2026-11-10 13:00:00')")->execute();
    $state=vp3_ai_usage_report_v1($pdo,1,[],$now);
    report_check((int)$state['summary']['unknown_usage_requests']===2,'Old zero-default provider rows must not masquerade as confirmed free API calls.');
    $debit(1,'malformed:split',200);
    $state=vp3_ai_usage_report_v1($pdo,1,[],$now);
    $malformed=array_values(array_filter($state['recent'],fn($r)=>$r['origin']==='subscription'&&(int)$r['total_tokens']===200))[0];
    report_check($malformed['estimated_cost_micros']===null&&(int)$malformed['usage_split_known']===0,'A mismatched historical token split cannot produce an invented estimate.');
    for($i=0;$i<29;$i++)$pdo->exec("INSERT INTO ai_execution_ledger(user_id,source,provider,model,total_tokens,estimated_cost_micros,cost_rate_source,service_status,usage_known,created_at) VALUES(1,'vp3_cloud','other','unpriced',10,NULL,'unconfigured','completed',1,'2026-11-10 14:00:00')");
    $first=vp3_ai_usage_report_v1($pdo,1,['page'=>1],$now);$second=vp3_ai_usage_report_v1($pdo,1,['page'=>2],$now);
    $keys=static fn(array $rows):array=>array_map(fn($r)=>$r['origin'].':'.$r['id'],$rows);
    report_check(count($first['recent'])===25&&count($second['recent'])===10&&!array_intersect($keys($first['recent']),$keys($second['recent'])),'History pagination must be bounded and stable across equal timestamps.');
    $pdo->exec("UPDATE ai_execution_ledger SET created_at='2026-10-31 23:59:59' WHERE id=$unknown");
    $month=vp3_ai_usage_report_v1($pdo,1,[],$now);$lifetime=vp3_ai_usage_report_v1($pdo,1,['period'=>'all'],$now);
    report_check((int)$lifetime['summary']['requests']===(int)$month['summary']['requests']+1,'UTC period filter must apply equally to totals and history.');
    report_check(vp3_ai_usage_report_options_v1(['view'=>['providers'],'period'=>"' OR 1=1",'page'=>-8])===['period'=>'month','view'=>'providers','page'=>1],'Invalid filter inputs must not enter SQL.');
    report_check(vp3_ai_usage_report_cost_label_v1(['estimated_cost_micros'=>null,'usage_known'=>1,'service_status'=>'completed'])==='Unpriced','Unknown price must have an explicit label.');
    report_check(vp3_ai_usage_report_cost_label_v1(['estimated_cost_micros'=>null,'usage_known'=>0,'service_status'=>'pending'])==='Pending','Pending cost must not look like zero.');
    echo 'AI usage report '.($mysql?'MySQL':'SQLite')." owner scope, historical recovery, estimates, unknowns and pagination passed.\n";
}finally{if($mysql)$pdo->exec('DROP DATABASE '.$database);}
