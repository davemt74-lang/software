<?php
declare(strict_types=1);

namespace DirectExecutionCapture;

// Run the real request builder and HTTP-result parser against a local HTTP
// double. No provider endpoint, credential or invoice is contacted.
use PDO;
use RuntimeException;
use Throwable;

$checks=0;$requests=[];$responseStatus=200;$omitUsage=false;$totalOnly=false;$transportFailure=false;
$preflights=[];$database='';
$config=['ai_cost_rates'=>[
    'openai:fixture-model'=>['input_per_million_usd'=>1,'output_per_million_usd'=>2],
    'anthropic:fixture-model'=>['input_per_million_usd'=>1,'output_per_million_usd'=>2],
]];
function check(bool $ok,string $message): void
{
    $GLOBALS['checks']++;
    if(!$ok)throw new RuntimeException($message);
}
function db(): PDO {return $GLOBALS['fixtureDb'];}
function function_exists(string $name): bool {return \function_exists(__NAMESPACE__.'\\'.$name)||\function_exists($name);}
function table_exists(string $table): bool
{
    if(db()->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite'){
        $q=db()->prepare("SELECT 1 FROM sqlite_master WHERE type='table' AND name=?");
    }else $q=db()->prepare('SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');
    $q->execute([$table]);return (bool)$q->fetchColumn();
}
function column_exists(string $table,string $column): bool
{
    if(db()->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite')return in_array($column,array_column(db()->query('PRAGMA table_info('.$table.')')->fetchAll(PDO::FETCH_ASSOC),'name'),true);
    $q=db()->prepare('SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?');
    $q->execute([$table,$column]);return (bool)$q->fetchColumn();
}
function agent_runtime_v125_trace_id(): string {return 'shared-trace-is-not-a-request';}
function ai_active_provider(): string {return 'openai';}
function ai_provider_ready(string $provider): bool {return true;}
function ai_provider_model(string $provider): string {return 'fixture-model';}
function ai_provider_api_key(string $provider): string {return 'fixture-system-secret';}
function ai_decrypt_secret(string $secret): string {return $secret;}
function ai_valid_model(string $provider,string $model): bool {return $model==='fixture-model';}
function ai_haiku55_request(array $payload): array {return $payload;}
function subscription_has_entitlement(array $user,string $entitlement): bool {return true;}
function subscription_schema_ready(?PDO $pdo=null): bool {return table_exists('ai_usage_ledger');}
function subscription_estimate_tokens_from_chars(int $chars): int {return max(1,(int)ceil($chars/3.5));}
function subscription_ai_preflight(array $user,string $scope,int $input,int $output,?PDO $pdo=null): array
{
    check($pdo?->inTransaction()===true,'Quota reservation shares the owner funding transaction.');
    $GLOBALS['preflights'][]=(int)$user['id'];
    if(!empty($user['is_admin']))return ['reservation_id'=>0,'max_output_tokens'=>$output];
    $q=$pdo->prepare('INSERT INTO ai_token_reservations(user_id) VALUES (?)');$q->execute([$user['id']]);
    return ['reservation_id'=>(int)$pdo->lastInsertId(),'max_output_tokens'=>128];
}
function subscription_ai_commit_usage(int $reservation,array $user,string $scope,string $provider,string $model,array $usage,string $requestKey): void
{
    // Match the subscription contract: admin access is unlimited and never
    // creates a package debit; execution observation must still happen.
    if(!empty($user['is_admin']))return;
    $q=db()->prepare('INSERT INTO ai_usage_ledger(user_id,scope,provider,model,input_tokens,output_tokens,total_tokens,credit_tokens_used,package_tokens_used,trace_id,request_key) VALUES (?,?,?,?,?,?,?,0,?,?,?)');
    $q->execute([$user['id'],$scope,$provider,$model,$usage['input_tokens'],$usage['output_tokens'],$usage['total_tokens'],$usage['total_tokens'],agent_runtime_v125_trace_id(),$requestKey]);
    subscription_ai_release_reservation($reservation);
}
function subscription_ai_release_reservation(int $id): void
{
    db()->prepare('DELETE FROM ai_token_reservations WHERE id=?')->execute([$id]);
}
function vp3_ai_budget_pause_v1(int $microseconds): bool {return true;}
foreach(['CURLOPT_POST','CURLOPT_RETURNTRANSFER','CURLOPT_CONNECTTIMEOUT','CURLOPT_TIMEOUT','CURLOPT_HTTPHEADER','CURLOPT_POSTFIELDS','CURLOPT_FOLLOWLOCATION','CURLOPT_SSL_VERIFYPEER','CURLOPT_SSL_VERIFYHOST','CURLINFO_HTTP_CODE'] as $index=>$name){
    if(!\defined($name))\define($name,50000+$index);
}
function curl_init(string $endpoint): object {return (object)['endpoint'=>$endpoint,'options'=>[]];}
function curl_setopt_array(object $handle,array $options): bool {$handle->options=$options;return true;}
function curl_setopt(object $handle,int $key,mixed $value): bool {$handle->options[$key]=$value;return true;}
function curl_getinfo(object $handle,int $key): int {return $GLOBALS['transportFailure']?0:$GLOBALS['responseStatus'];}
function curl_error(object $handle): string {return $GLOBALS['transportFailure']?'fixture disconnect':'';}
function curl_close(object $handle): void {}
function curl_exec(object $handle): string|bool
{
    $GLOBALS['requests'][]=['endpoint'=>$handle->endpoint,'options'=>$handle->options,'payload'=>json_decode($handle->options[CURLOPT_POSTFIELDS],true)];
    if($GLOBALS['transportFailure'])return false;
    if($GLOBALS['responseStatus']!==200)return json_encode(['error'=>['message'=>'Fixture rejection.']]);
    return json_encode(['id'=>'fixture-request-'.count($GLOBALS['requests']),'output_text'=>'Fixture result.']
        +($GLOBALS['omitUsage']?[]:['usage'=>$GLOBALS['totalOnly']?['total_tokens'=>125]:['input_tokens'=>100,'output_tokens'=>25,'total_tokens'=>125]]));
}

foreach(['ai-service-usage-v1.php','ai-usage-accounting-v032.php','user-llm-v1.php','ai-direct-account-v1.php'] as $file){
    $source=file_get_contents(dirname(__DIR__).'/includes/'.$file);
    $source=preg_replace('/^require_once __DIR__[^\n]*\n/m','',$source);
    $source=str_replace('__DIR__',var_export(dirname(__DIR__).'/includes',true),$source);
    eval('namespace '.__NAMESPACE__.'; use \\PDO; use \\PDOException; use \\Throwable; use \\RuntimeException; use \\InvalidArgumentException;'.substr($source,strpos($source,'declare(strict_types=1);')+strlen('declare(strict_types=1);')));
}
$source=file_get_contents(dirname(__DIR__).'/includes/ai-settings.php');
$start=strpos($source,'function ai_curl_json(');$end=strpos($source,'function ai_openai_response(',$start);
eval('namespace '.__NAMESPACE__.'; use \\Throwable;'.substr($source,$start,$end-$start));

$driver=$argv[1]??'sqlite';
if($driver==='mysql'){
    if(!getenv('VP3_TEST_MYSQL_DSN'))throw new RuntimeException('VP3_TEST_MYSQL_DSN is required.');
    $pdo=new PDO(getenv('VP3_TEST_MYSQL_DSN'),getenv('VP3_TEST_MYSQL_USER')?:'root',getenv('VP3_TEST_MYSQL_PASS')?:'',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
    $database='vp3_direct_'.bin2hex(random_bytes(6));$pdo->exec('CREATE DATABASE '.$database);$pdo->exec('USE '.$database);
}else $pdo=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$GLOBALS['fixtureDb']=$pdo;
try{
    $id=$driver==='mysql'?'BIGINT PRIMARY KEY AUTO_INCREMENT':'INTEGER PRIMARY KEY AUTOINCREMENT';
    $pdo->exec("CREATE TABLE users(id INTEGER PRIMARY KEY);
        INSERT INTO users VALUES (1),(2),(3);
        CREATE TABLE user_llm_preferences(user_id INTEGER PRIMARY KEY,route VARCHAR(24),provider VARCHAR(32));
        CREATE TABLE user_llm_credentials(user_id INTEGER,provider VARCHAR(32),encrypted_key TEXT,model VARCHAR(160));
        INSERT INTO user_llm_preferences VALUES (2,'own_key','anthropic');
        INSERT INTO user_llm_credentials VALUES (2,'anthropic','fixture-personal-secret','fixture-model');
        CREATE TABLE ai_token_reservations(id $id,user_id INTEGER);
        CREATE TABLE ai_usage_ledger(id $id,user_id INTEGER,scope VARCHAR(60),provider VARCHAR(40),model VARCHAR(120),
            input_tokens INTEGER,output_tokens INTEGER,total_tokens INTEGER,credit_tokens_used INTEGER,package_tokens_used INTEGER,
            trace_id VARCHAR(120),request_key VARCHAR(160) UNIQUE,created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP);");
    if($driver==='mysql')ai_usage_accounting_v032_ensure_schema($pdo);
    else{
        $pdo->exec("CREATE TABLE ai_execution_ledger(id INTEGER PRIMARY KEY AUTOINCREMENT,user_id INTEGER,agent_id INTEGER,conversation_id INTEGER,
            cloud_ledger_id INTEGER UNIQUE,trace_id TEXT,source TEXT,provider TEXT,model TEXT,input_tokens INTEGER,output_tokens INTEGER,total_tokens INTEGER,
            cloud_tokens_charged INTEGER,estimated_cost_micros INTEGER,cost_currency TEXT,cost_rate_source TEXT,homeserver_state TEXT,fallback_used INTEGER,
            failure_class TEXT,latency_ms INTEGER,run_id INTEGER,runtime_version TEXT,requested_route TEXT,attempted_route TEXT,actual_route TEXT,
            route_reason TEXT,fallback_reason TEXT,created_at TEXT DEFAULT CURRENT_TIMESTAMP)");
        vp3_ai_service_usage_ensure_schema_v1($pdo);
    }
    $last=static fn():array=>$pdo->query('SELECT * FROM ai_execution_ledger ORDER BY id DESC LIMIT 1')->fetch();
    $count=static fn(string $table):int=>(int)$pdo->query('SELECT COUNT(*) FROM '.$table)->fetchColumn();
    $admin=['id'=>1,'is_admin'=>true];$personal=['id'=>2];$member=['id'=>3];
    $request=static function(array $user,string $scope='video_editor'):array{
        $route=vp3_ai_direct_account_route_v1($user);
        $payload=$route['provider']==='openai'
            ?['max_output_tokens'=>400,'input'=>'Fixture instruction.']
            :['max_tokens'=>400,'messages'=>[['role'=>'user','content'=>'Fixture instruction.']]];
        return vp3_ai_direct_request_v1($user,$route,$scope,$payload,30);
    };
    check($request($admin)['ok'],'The real direct transport returns the fixture result.');
    $row=$last();
    check((int)$row['user_id']===1&&$row['source']==='vp3_cloud'&&$row['service_key']==='direct'
        &&$row['service_stage']==='video_editor'&&$row['service_status']==='completed','Admin provider work has exact owner, source and scope attribution.');
    check((int)$row['total_tokens']===125&&(int)$row['usage_known']===1&&(int)$row['estimated_cost_micros']===150
        &&(int)$row['cloud_tokens_charged']===0&&$count('ai_usage_ledger')===0,'Admin tokens and configured estimate are observed without a package debit.');
    check($row['provider_request_id']==='fixture-request-1'&&strlen($row['service_attempt_key'])===64,'The final provider response keeps its request identity.');

    $before=count($preflights);$request($personal,'stem_editor');$row=$last();
    check($row['source']==='user_provider'&&$row['credential_source']==='own_key'&&$row['provider']==='anthropic'
        &&count($preflights)===$before&&$count('ai_usage_ledger')===0,'Personal provider work is observed without touching system quota.');
    check($requests[1]['options'][CURLOPT_HTTPHEADER][0]==='x-api-key: fixture-personal-secret','The direct transport preserves the selected personal credential.');

    // An unrelated debit with the same trace cannot become this request's charge.
    $pdo->exec("INSERT INTO ai_usage_ledger(user_id,total_tokens,package_tokens_used,credit_tokens_used,trace_id,request_key) VALUES (3,999,999,0,'shared-trace-is-not-a-request','unrelated-debit')");
    $request($member,'transcription');$row=$last();
    $debit=$pdo->query("SELECT * FROM ai_usage_ledger WHERE user_id=3 AND request_key LIKE 'direct:%'")->fetch();
    check((int)$row['cloud_ledger_id']===(int)$debit['id']&&(int)$row['cloud_tokens_charged']===125
        &&(int)$debit['total_tokens']===125&&$debit['scope']==='transcription','Known system usage links the exact debit instead of a shared trace.');
    check($requests[2]['payload']['max_output_tokens']===128,'Observing usage preserves the quota output cap.');

    $omitUsage=true;$before=$count('ai_usage_ledger');$request($member);$row=$last();
    check((int)$row['usage_known']===0&&$row['service_status']==='unknown'&&$row['estimated_cost_micros']===null
        &&$count('ai_usage_ledger')===$before&&$count('ai_token_reservations')===0,'Missing usage stays unknown and releases its unused reservation without a fake debit.');
    $omitUsage=false;$totalOnly=true;$request($personal);$row=$last();
    check((int)$row['usage_known']===1&&(int)$row['usage_split_known']===0&&(int)$row['total_tokens']===125&&$row['estimated_cost_micros']===null,
        'Total-only provider usage stays visible without inventing a token split or zero estimate.');
    $totalOnly=false;$savedRates=$config['ai_cost_rates'];$config['ai_cost_rates']=[];
    $request($personal);$row=$last();
    check((int)$row['usage_known']===1&&(int)$row['total_tokens']===125&&$row['estimated_cost_micros']===null,
        'Reported tokens remain visible when no provider price has been configured.');
    $config['ai_cost_rates']=$savedRates;$responseStatus=400;$request($personal);$row=$last();
    check($row['service_status']==='rejected'&&(int)$row['usage_known']===1&&(int)$row['total_tokens']===0
        &&(int)$row['estimated_cost_micros']===0,'An explicit provider rejection is a confirmed zero-usage response.');

    $responseStatus=200;$transportFailure=true;$before=$count('ai_execution_ledger');$request($member);$row=$last();
    check($count('ai_execution_ledger')===$before+3,'Every disconnected direct transport retry has its own canonical observation.');
    check($row['service_status']==='unknown'&&(int)$row['usage_known']===0&&$row['estimated_cost_micros']===null,
        'A disconnected request keeps unknown usage rather than a zero-charge claim.');
    $transportFailure=false;$before=$count('ai_usage_ledger');
    vp3_ai_direct_request_v1($personal,vp3_ai_direct_system_route_v1(),'profile_call_public',['max_output_tokens'=>400,'input'=>'Public fixture.'],30);
    $row=$last();
    check((int)$row['user_id']===2&&$row['credential_source']==='system'&&$row['service_stage']==='profile_call_public'
        &&$count('ai_usage_ledger')===$before,'Public-profile inference preserves its authorized system route without a private package debit.');
    $before=$count('ai_execution_ledger');
    vp3_ai_direct_request_v1(['id'=>999],vp3_ai_direct_system_route_v1(),'profile_call_public',['max_output_tokens'=>400],30);
    check($count('ai_execution_ledger')===$before,'A missing supplied public owner is never replaced with a visitor.');
    echo 'Direct execution capture '.strtoupper($driver).' passed ('.$checks." checks).\n";
}finally{
    if($database!=='')$pdo->exec('DROP DATABASE '.$database);
}
