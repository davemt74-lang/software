<?php
declare(strict_types=1);

namespace LegacyExecutionCapture;

use PDO;
use RuntimeException;

// Actual hosted request construction, parsing and accounting, with HTTP
// replaced at the cURL boundary. No real provider requests are sent.
$root=sys_get_temp_dir().'/vp3-legacy-capture-'.bin2hex(random_bytes(6));
\define('STONEFELLOW_ROOT',$root);
$settings=[];$requests=[];$checks=0;$preflights=[];$database='';
$responseStatus=200;$usageShape='split';$transportFailure=false;
$config=['ai_cost_rates'=>[
    'openai:gpt-5.4-mini'=>['input_per_million_usd'=>1,'output_per_million_usd'=>2],
    'anthropic:claude-haiku-4-5'=>['input_per_million_usd'=>1,'output_per_million_usd'=>2],
]];
function check(bool $ok,string $message): void
{
    $GLOBALS['checks']++;
    if(!$ok)throw new RuntimeException($message);
}
function db(): PDO {return $GLOBALS['fixtureDb'];}
function current_user(): ?array {return $GLOBALS['currentUser']??null;}
function function_exists(string $name): bool {return \function_exists(__NAMESPACE__.'\\'.$name)||\function_exists($name);}
function table_exists(string $table): bool
{
    $query=db()->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite'
        ?"SELECT 1 FROM sqlite_master WHERE type='table' AND name=?"
        :'SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?';
    $q=db()->prepare($query);$q->execute([$table]);return (bool)$q->fetchColumn();
}
function column_exists(string $table,string $column): bool
{
    if(db()->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite')return in_array($column,array_column(db()->query('PRAGMA table_info('.$table.')')->fetchAll(PDO::FETCH_ASSOC),'name'),true);
    $q=db()->prepare('SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?');
    $q->execute([$table,$column]);return (bool)$q->fetchColumn();
}
function setting(string $key,mixed $fallback=''): mixed {return $GLOBALS['settings'][$key]??$fallback;}
function save_setting(string $key,string $value): void {$GLOBALS['settings'][$key]=$value;}
function agent_brain_soul(array $user): array {return [];}
function agent_brain_default_soul(): array {return [];}
function agent_brain_tool_prompt(array $user): string {return '';}
function agent_runtime_v125_trace_id(): string {return 'shared-legacy-trace';}
function subscription_has_entitlement(array $user,string $entitlement): bool {return true;}
function subscription_schema_ready(?PDO $pdo=null): bool {return table_exists('ai_usage_ledger');}
function subscription_estimate_tokens_from_chars(int $chars): int {return max(1,(int)ceil($chars/3.5));}
function subscription_ai_preflight(?array $user,string $scope,int $input,int $output,?PDO $pdo=null): array
{
    $GLOBALS['preflights'][]=(int)$user['id'];
    if(!empty($user['is_admin']))return ['reservation_id'=>0,'max_output_tokens'=>$output];
    $q=($pdo??db())->prepare('INSERT INTO ai_token_reservations(user_id) VALUES (?)');$q->execute([$user['id']]);
    return ['reservation_id'=>(int)($pdo??db())->lastInsertId(),'max_output_tokens'=>128];
}
function subscription_ai_commit_usage(int $reservation,?array $user,string $scope,string $provider,string $model,array $usage,string $requestKey='',?PDO $pdo=null): void
{
    $total=(int)($usage['total_tokens']??0);
    if(!empty($user['is_admin'])||$total<1){subscription_ai_release_reservation($reservation);return;}
    $q=($pdo??db())->prepare('INSERT INTO ai_usage_ledger(user_id,scope,provider,model,input_tokens,output_tokens,total_tokens,credit_tokens_used,package_tokens_used,trace_id,request_key) VALUES (?,?,?,?,?,?,?,0,?,?,?)');
    $q->execute([$user['id'],$scope,$provider,$model,$usage['input_tokens']??0,$usage['output_tokens']??0,$total,$total,agent_runtime_v125_trace_id(),$requestKey]);
    subscription_ai_release_reservation($reservation);
}
function subscription_ai_release_reservation(int $id): void
{
    if($id>0)db()->prepare('DELETE FROM ai_token_reservations WHERE id=?')->execute([$id]);
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
    $GLOBALS['requests'][]=['endpoint'=>$handle->endpoint,'headers'=>$handle->options[CURLOPT_HTTPHEADER],
        'payload'=>json_decode($handle->options[CURLOPT_POSTFIELDS],true)];
    if($GLOBALS['transportFailure'])return false;
    if($GLOBALS['responseStatus']!==200)return json_encode(['error'=>['message'=>'Fixture rejection.']]);
    $answer=str_contains($handle->endpoint,'openai.com')?['output_text'=>'Fixture response.']:['content'=>[['type'=>'text','text'=>'Fixture response.']]];
    $usage=match($GLOBALS['usageShape']){'split'=>['input_tokens'=>100,'output_tokens'=>25,'total_tokens'=>125],'total'=>['total_tokens'=>125],default=>null};
    return json_encode($answer+['id'=>'fixture-legacy-'.count($GLOBALS['requests'])]+($usage===null?[]:['usage'=>$usage]));
}
foreach(['ai-settings.php','ai-runtime-v100.php','ai-service-policy-v1.php','ai-service-usage-v1.php','ai-usage-accounting-v032.php','user-llm-v1.php'] as $file){
    $source=file_get_contents(dirname(__DIR__).'/includes/'.$file);
    $source=preg_replace('/^require_once __DIR__[^\n]*\n/m','',$source);
    $source=str_replace('__DIR__',var_export(dirname(__DIR__).'/includes',true),$source);
    eval('namespace '.__NAMESPACE__.'; use \\PDO; use \\PDOException; use \\Throwable; use \\RuntimeException; use \\InvalidArgumentException; use \\UnexpectedValueException; use \\JsonException;'.substr($source,strpos($source,'declare(strict_types=1);')+strlen('declare(strict_types=1);')));
}

$driver=$argv[1]??'sqlite';
if($driver==='mysql'){
    if(!getenv('VP3_TEST_MYSQL_DSN'))throw new RuntimeException('VP3_TEST_MYSQL_DSN is required.');
    $pdo=new PDO(getenv('VP3_TEST_MYSQL_DSN'),getenv('VP3_TEST_MYSQL_USER')?:'root',getenv('VP3_TEST_MYSQL_PASS')?:'',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
    $database='vp3_legacy_'.bin2hex(random_bytes(6));$pdo->exec('CREATE DATABASE '.$database);$pdo->exec('USE '.$database);
}else $pdo=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$GLOBALS['fixtureDb']=$pdo;
try{
    $id=$driver==='mysql'?'BIGINT PRIMARY KEY AUTO_INCREMENT':'INTEGER PRIMARY KEY AUTOINCREMENT';
    $pdo->exec("CREATE TABLE users(id INTEGER PRIMARY KEY); INSERT INTO users VALUES (1),(2),(3);
        CREATE TABLE settings(setting_key VARCHAR(100) PRIMARY KEY,setting_value TEXT);
        CREATE TABLE user_llm_preferences(user_id INTEGER PRIMARY KEY,route VARCHAR(24),provider VARCHAR(32));
        CREATE TABLE user_llm_credentials(user_id INTEGER,provider VARCHAR(32),encrypted_key TEXT,model VARCHAR(160));
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
    $settings=['ai_active_provider'=>'openai','ai_openai_enabled'=>'1','ai_openai_model'=>'gpt-5.4-mini','ai_chat_requests_per_minute'=>'120'];
    $settings['ai_openai_api_key']=ai_encrypt_secret('fixture-installation-key');
    $settings['ai_anthropic_enabled']='1';$settings['ai_anthropic_model']='claude-haiku-4-5';
    $settings['ai_anthropic_api_key']=ai_encrypt_secret('fixture-installation-claude');
    $pdo->exec("INSERT INTO user_llm_preferences VALUES (2,'own_key','anthropic')");
    $pdo->prepare('INSERT INTO user_llm_credentials VALUES (2,?,?,?)')->execute(['anthropic',ai_encrypt_secret('fixture-account-key'),'claude-haiku-4-5']);
    $admin=['id'=>1,'is_admin'=>true];$personal=['id'=>2];$member=['id'=>3];
    $GLOBALS['currentUser']=$admin;
    $last=static fn():array=>$pdo->query('SELECT * FROM ai_execution_ledger ORDER BY id DESC LIMIT 1')->fetch();
    $count=static fn(string $table):int=>(int)$pdo->query('SELECT COUNT(*) FROM '.$table)->fetchColumn();
    $infer=static fn(array $user,string $scope='legacy_default'):array=>ai_generate_chat_response('Hello legacy request.',[],[],$user,$scope);

    $result=$infer($admin);$row=$last();
    check(!empty($result['ok'])&&!array_key_exists('_legacy_usage_key',$result)&&$count('ai_execution_ledger')===1,
        'A successful unscoped legacy call creates one canonical observation and hides its internal key.');
    check((int)$row['user_id']===1&&$row['source']==='vp3_cloud'&&$row['service_key']==='legacy'
        &&$row['service_stage']==='legacy_default'&&$row['service_status']==='completed'
        &&(int)$row['total_tokens']===125&&(int)$row['usage_known']===1&&(int)$row['estimated_cost_micros']===150
        &&(int)$row['cloud_tokens_charged']===0&&$count('ai_usage_ledger')===0,'Admin legacy work keeps reported tokens and configured estimate without charging a package.');
    $before=count($preflights);$result=$infer($personal);$row=$last();
    check($result['provider']==='anthropic'&&$result['model']==='claude-haiku-4-5'&&$row['source']==='user_provider'
        &&(int)$row['user_id']===2&&(int)$row['total_tokens']===125&&count($preflights)===$before&&$count('ai_usage_ledger')===0,
        'Personal legacy inference keeps its chosen provider and model without quota or debit.');
    check(in_array('x-api-key: fixture-account-key',end($requests)['headers'],true),'Personal legacy inference sends its account key.');

    $pdo->exec("INSERT INTO ai_usage_ledger(user_id,total_tokens,package_tokens_used,credit_tokens_used,trace_id,request_key) VALUES (3,999,999,0,'shared-legacy-trace','unrelated-legacy')");
    $result=$infer($member,'legacy_known');$row=$last();
    $debit=$pdo->query("SELECT * FROM ai_usage_ledger WHERE request_key LIKE 'nonstream:legacy_known:%'")->fetch();
    check(!empty($result['ok'])&&(int)$row['cloud_ledger_id']===(int)$debit['id']&&(int)$row['cloud_tokens_charged']===125
        &&(int)$debit['total_tokens']===125,'A legacy system call links its exact completed debit rather than another shared-trace charge.');

    $usageShape='missing';$before=$count('ai_usage_ledger');$infer($member,'legacy_unknown');$row=$last();
    check((int)$row['usage_known']===0&&$row['service_status']==='unknown'&&$row['estimated_cost_micros']===null
        &&$count('ai_usage_ledger')===$before&&$count('ai_token_reservations')===0,'Unreported legacy usage remains unknown without a fabricated debit.');
    $usageShape='total';$infer($admin);$row=$last();
    check((int)$row['total_tokens']===125&&(int)$row['usage_known']===1&&(int)$row['usage_split_known']===0
        &&$row['estimated_cost_micros']===null,'A reported total without input/output split cannot manufacture a priced split.');

    $usageShape='split';$responseStatus=429;$before=$count('ai_execution_ledger');$infer($admin);
    $retries=$pdo->query('SELECT service_status,usage_known,total_tokens,estimated_cost_micros FROM ai_execution_ledger ORDER BY id DESC LIMIT 3')->fetchAll();
    check($count('ai_execution_ledger')===$before+3&&array_column($retries,'service_status')===['rejected','rejected','rejected']
        &&array_map('intval',array_column($retries,'usage_known'))===[1,1,1]
        &&array_map('intval',array_column($retries,'total_tokens'))===[0,0,0]
        &&array_map('intval',array_column($retries,'estimated_cost_micros'))===[0,0,0],
        'Every confirmed rate-limit rejection is observed separately with known zero usage.');
    $responseStatus=200;$transportFailure=true;$before=$count('ai_execution_ledger');$infer($admin);
    $unknown=$pdo->query('SELECT service_status,usage_known,estimated_cost_micros FROM ai_execution_ledger ORDER BY id DESC LIMIT 3')->fetchAll();
    check($count('ai_execution_ledger')===$before+3&&array_column($unknown,'service_status')===['unknown','unknown','unknown']
        &&array_map('intval',array_column($unknown,'usage_known'))===[0,0,0]
        &&array_column($unknown,'estimated_cost_micros')===[null,null,null],
        'Each disconnected physical request is unknown rather than silently dropped or called free.');

    $transportFailure=false;$before=$count('ai_execution_ledger');
    $result=ai_v100_with_service_context(['service'=>'conversation','agent_id'=>9,'conversation_id'=>10,'job_key'=>'metered-once'],static fn()=>ai_generate_chat_response('Hello metered request.',[],[],$admin,'chat'));
    $row=$last();
    check(!empty($result['ok'])&&count($result['service_attempt_ids'])===1&&$count('ai_execution_ledger')===$before+1
        &&$row['service_key']==='conversation'&&(int)$row['agent_id']===9&&(int)$row['conversation_id']===10
        &&(int)$row['total_tokens']===125,'An active service meter records once and bypasses the legacy observer.');

    $result=ai_generate_chat_response('Hello caller.',[],[['text'=>'PRIVATE_CONTEXT_MUST_NOT_TRAVEL']],$admin,'phone_voice',
        ['surface'=>'phone_voice','phone_agent_name'=>'Fixture Phone Agent']);$request=end($requests);$row=$last();
    check(!empty($result['ok'])&&$result['provider']==='openai'&&$request['payload']['model']==='gpt-5.4-mini'
        &&str_starts_with($request['payload']['instructions'],'PH3F_PHONE_VOICE.')
        &&!str_contains(json_encode($request['payload']),'PRIVATE_CONTEXT_MUST_NOT_TRAVEL')&&$row['service_stage']==='phone_voice',
        'Observing phone usage preserves its chosen provider, isolated phone prompt and lack of private owner context.');
    $before=$count('ai_usage_ledger');
    $result=ai_generate_chat_response('Tell me the public hours.',[],[['source'=>'public','text'=>'Public hours are noon to five.']],$personal,'profile_agent',
        ['surface'=>'profile_agent','profile_owner_user_id'=>2,'profile_agent_name'=>'Public Fixture','profile_owner_name'=>'Owner']);$request=end($requests);$row=$last();
    check(!empty($result['ok'])&&$result['provider']==='anthropic'&&$request['payload']['model']==='claude-haiku-4-5'
        &&str_contains($request['payload']['system'],'public AI representative')
        &&in_array('x-api-key: fixture-account-key',$request['headers'],true)
        &&$row['service_stage']==='profile_agent'&&(int)$row['user_id']===2&&$count('ai_usage_ledger')===$before,
        'Profile observations preserve owner attribution, approved public prompt and account-provider funding.');
    $before=$count('ai_usage_ledger');$previousPreflights=count($preflights);
    $result=ai_test_provider('openai');$row=$last();$request=end($requests);
    check(!empty($result['ok'])&&!array_key_exists('_legacy_usage_key',$result)
        &&(int)$row['user_id']===1&&$row['service_stage']==='connection_test'&&(int)$row['total_tokens']===125
        &&in_array('Authorization: Bearer fixture-installation-key',$request['headers'],true)
        &&count($preflights)===$previousPreflights&&$count('ai_usage_ledger')===$before,
        'An Admin provider connection test records reported usage with its installation key and existing quota exemption.');
    echo 'Legacy execution capture '.strtoupper($driver).' passed ('.$checks." checks).\n";
}finally{
    if($database!=='')$pdo->exec('DROP DATABASE '.$database);
    if(is_dir($root)){
        $paths=new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root,\FilesystemIterator::SKIP_DOTS),\RecursiveIteratorIterator::CHILD_FIRST);
        foreach($paths as $path){if($path->isDir())rmdir($path->getPathname());else unlink($path->getPathname());}
        rmdir($root);
    }
}
