<?php
declare(strict_types=1);

require_once __DIR__.'/../includes/ai-usage-accounting-v032.php';
require_once __DIR__.'/../includes/subscription-quota.php';
require_once __DIR__.'/../includes/ai-settings.php';
require_once __DIR__.'/../includes/ai-service-policy-v1.php';
require_once __DIR__.'/../includes/user-llm-v1.php';

function usage_check(bool $condition,string $message): void
{
    if(!$condition)throw new RuntimeException($message);
}

function usage_rejects(int $code,callable $call): void
{
    try{$call();}
    catch(RuntimeException $error){
        usage_check($error->getCode()===$code,'Unexpected rejection: '.$error->getMessage());
        return;
    }
    throw new RuntimeException('Expected usage reservation to be rejected.');
}

function db(): PDO {return $GLOBALS['usageTestDb'];}
function table_exists(string $table): bool
{
    $q=db()->prepare('SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');
    $q->execute([$table]);
    return (bool)$q->fetchColumn();
}
function column_exists(string $table,string $column): bool
{
    $q=db()->prepare('SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?');
    $q->execute([$table,$column]);
    return (bool)$q->fetchColumn();
}
function subscription_schema_ready(?PDO $pdo=null): bool {return table_exists('ai_usage_ledger');}
function subscription_is_internal_admin(array $user): bool {return false;}
function subscription_has_entitlement(array $user,string $capability): bool {return $capability==='main_ai.access';}
function subscription_current_for_user_id(int $userId,PDO $pdo,bool $forUpdate=false): array
{
    return ['id'=>$userId,'ai_token_override'=>null,'ai_tokens_monthly'=>2000];
}
function agent_runtime_v125_trace_id(): string {return 'test-shared-trace';}

$dsn=getenv('VP3_TEST_MYSQL_DSN');
if(!$dsn)throw new RuntimeException('VP3_TEST_MYSQL_DSN is required for the MySQL service usage test.');
$pdo=new PDO($dsn,getenv('VP3_TEST_MYSQL_USER')?:'root',getenv('VP3_TEST_MYSQL_PASS')?:'',
    [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$GLOBALS['usageTestDb']=$pdo;
if(($argv[1]??'')==='quota-worker'){
    $database=(string)($argv[2]??'');
    usage_check((bool)preg_match('/\Avp3_usage_[a-f0-9]{12}\z/D',$database),'Expected a disposable usage database.');
    $pdo->exec('USE '.$database);
    echo "ready\n";
    try{
        subscription_ai_preflight(['id'=>1],'legacy_chat',20,100);
        echo "reserved\n";
    }catch(RuntimeException $error){
        usage_check(str_contains($error->getMessage(),'balance is exhausted'),'Unexpected quota rejection.');
        echo "blocked\n";
    }
    exit;
}
$database='vp3_usage_'.bin2hex(random_bytes(6));
$pdo->exec('CREATE DATABASE '.$database);
try{
    $pdo->exec('USE '.$database);
    $pdo->exec("CREATE TABLE users(id INT PRIMARY KEY);
        INSERT INTO users VALUES (1),(2),(3);
        CREATE TABLE settings(setting_key VARCHAR(100) PRIMARY KEY,setting_value TEXT NOT NULL);
        CREATE TABLE user_llm_preferences(user_id INT PRIMARY KEY,route VARCHAR(24),provider VARCHAR(32));
        INSERT INTO user_llm_preferences VALUES (1,'own_key','openai'),(2,'own_key','openai');
        CREATE TABLE ai_usage_ledger(id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,user_id INT NOT NULL,subscription_id BIGINT,
            scope VARCHAR(60),provider VARCHAR(40),model VARCHAR(120),input_tokens BIGINT,output_tokens BIGINT,total_tokens BIGINT,
            credit_tokens_used BIGINT,package_tokens_used BIGINT,trace_id VARCHAR(120),request_key VARCHAR(160) UNIQUE,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP);
        CREATE TABLE ai_token_reservations(id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,user_id INT NOT NULL,
            subscription_id BIGINT,scope VARCHAR(60),reserved_tokens BIGINT,expires_at DATETIME);
        CREATE TABLE ai_token_credits(id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,user_id INT NOT NULL,
            remaining_amount BIGINT,expires_at DATETIME,updated_at DATETIME);");
    $owner=['id'=>1];
    $other=['id'=>2];
    $begin=static fn(array $options=[],array $user=['id'=>1])=>vp3_ai_service_usage_begin_v1(
        $pdo,$user,'initiative','openai','gpt-5.4-mini','own_key',20,100,$options
    );
    usage_check(!$begin()['metered'],'Legacy execution should be available before upgrade.');
    usage_rejects(403,fn()=>$begin([],['id'=>3]));
    usage_rejects(503,fn()=>$begin(['required'=>true]));
    usage_rejects(503,fn()=>$begin(['daily_tokens'=>0]));
    ai_usage_accounting_v032_ensure_schema($pdo);
    ai_usage_accounting_v032_ensure_schema($pdo);
    usage_check(vp3_ai_service_usage_ready_v1($pdo),'Service usage schema should upgrade idempotently.');

    $background=['background'=>true,'background_daily_tokens'=>150,'background_monthly_tokens'=>170,'monthly_tokens'=>500,'run_id'=>54,'job_tokens'=>140];
    $first=$begin($background+['attempt_key'=>'first','stage'=>'planning']);
    usage_check($first['max_output_tokens']===100,'First attempt should receive its requested allowance.');
    usage_rejects(409,fn()=>$begin($background+['attempt_key'=>'first']));
    usage_rejects(402,fn()=>$begin($background));
    $independent=$begin($background,$other);
    vp3_ai_service_usage_finish_v1($pdo,$independent,[],'aborted');
    $used=vp3_ai_service_usage_finish_v1($pdo,$first,['input_tokens'=>20,'output_tokens'=>30],'completed',
        ['provider_request_id'=>'req_first','latency_ms'=>24]);
    usage_check($used['cloud_tokens_charged']===0,'Personal credentials must never debit the VP3 package.');
    $second=$begin($background+['attempt_key'=>'second']);
    usage_check($second['max_output_tokens']===70,'The job allowance must reconcile against reported prior usage.');
    vp3_ai_service_usage_finish_v1($pdo,$second,[],'unknown');
    usage_rejects(402,fn()=>$begin($background+['attempt_key'=>'third']));
    $foreground=$begin(['run_id'=>55,'background_daily_tokens'=>0,'daily_tokens'=>250]);
    usage_check($foreground['max_output_tokens']===90,'Foreground should ignore the background cap and respect the total daily cap.');
    vp3_ai_service_usage_finish_v1($pdo,$foreground,[],'aborted');

    ai_usage_accounting_v032_record($pdo,$owner,7,8,['service_attempt_ids'=>[$first['id'],$second['id']],
        'source'=>'user_provider','fallback_used'=>true,'actual_route'=>'own_key','homeserver'=>'offline']);
    $rows=ai_usage_accounting_v032_export($pdo,1);
    usage_check(count($rows)===2,'The logical Chat receipt must not add a duplicate usage row.');
    usage_check((int)$rows[1]['agent_id']===7&&(int)$rows[1]['conversation_id']===8
        &&$rows[1]['provider_request_id']==='req_first'&&$rows[1]['service_stage']==='planning'
        &&$rows[1]['actual_route']==='own_key'&&(int)$rows[1]['fallback_used']===1&&$rows[1]['homeserver_state']==='offline',
        'Receipt should preserve attribution and route provenance.');
    usage_check((int)$rows[0]['service_budget_tokens']===90
        &&ai_usage_accounting_v032_token_label($rows[0])==='Usage unreported'
        &&ai_usage_accounting_v032_token_csv($rows[0])===['','',''],'An uncertain call must reserve conservatively and show unreported usage.');
    usage_rejects(402,fn()=>$begin(['monthly_tokens'=>200]));
    $rejected=$begin(['daily_tokens'=>240]);
    vp3_ai_service_usage_finish_v1($pdo,$rejected,[],'failed',['confirmed_no_charge'=>true]);
    $rejectedRow=ai_usage_accounting_v032_export($pdo,1)[0];
    usage_check((int)$rejectedRow['service_budget_tokens']===0
        &&ai_usage_accounting_v032_token_label($rejectedRow)==='Provider rejected','Confirmed provider rejection should release its service cap.');
    $partial=$begin(['daily_tokens'=>240]);
    vp3_ai_service_usage_finish_v1($pdo,$partial,['total_tokens'=>21],'completed',['units'=>1,'unit'=>'images']);
    $row=ai_usage_accounting_v032_export($pdo,1)[0];
    usage_check(ai_usage_accounting_v032_token_csv($row)===['','',21]&&(int)$row['usage_units']===1
        &&$row['estimated_cost_micros']===null,'Total-only usage should not fabricate a token split or estimated cost.');

    $system=static fn(string $key)=>vp3_ai_service_usage_begin_v1($pdo,$owner,'conversation','openai','gpt-5.4-mini','system',20,100,
        ['attempt_key'=>$key,'billing_scope'=>'chat']);
    $personalBeforeSwitch=$begin();
    usage_rejects(403,fn()=>$system('funding-mismatch'));
    $pdo->exec("UPDATE user_llm_preferences SET route='system',provider='' WHERE user_id=1");
    usage_rejects(403,fn()=>$begin());
    $settled=vp3_ai_service_usage_finish_v1($pdo,$personalBeforeSwitch,['input_tokens'=>20,'output_tokens'=>30]);
    usage_check($settled['cloud_tokens_charged']===0,'An in-flight personal call keeps its original funding after a switch.');
    vp3_ai_service_save_route_v1($pdo,'conversation',['provider'=>'openai','model'=>'gpt-5.4-mini'],null);
    usage_rejects(403,fn()=>vp3_ai_service_usage_begin_v1($pdo,$owner,'conversation','openai','gpt-5.6-luna','system',20,100));
    $firstSystem=$system('chat-first');
    $secondSystem=$system('chat-second');
    usage_check((int)$pdo->query('SELECT COUNT(*) FROM ai_token_reservations')->fetchColumn()===2,'Canonical quota reservations should be created.');
    $actual=['prompt_tokens'=>20,'completion_tokens'=>20,'total_tokens'=>40];
    $firstCharge=vp3_ai_service_usage_finish_v1($pdo,$firstSystem,$actual);
    $replay=vp3_ai_service_usage_finish_v1($pdo,$firstSystem,$actual);
    usage_check($firstCharge===$replay&&$firstCharge['cloud_tokens_charged']===40,'A replay should return the original debit.');
    $pdo->exec("CREATE TRIGGER usage_test_reject_settlement BEFORE UPDATE ON ai_execution_ledger FOR EACH ROW
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Test settlement failure'");
    try{
        vp3_ai_service_usage_finish_v1($pdo,$secondSystem,$actual);
        throw new RuntimeException('The deliberately failed settlement succeeded.');
    }catch(PDOException $error){usage_check($error->getCode()==='45000','Unexpected settlement error.');}
    $pdo->exec('DROP TRIGGER usage_test_reject_settlement');
    usage_check((int)$pdo->query('SELECT COUNT(*) FROM ai_usage_ledger')->fetchColumn()===1
        &&(int)$pdo->query('SELECT COUNT(*) FROM ai_token_reservations')->fetchColumn()===1,'Settlement failure must roll back the debit and keep the reservation.');
    vp3_ai_service_usage_finish_v1($pdo,$secondSystem,$actual,'failed');
    $rows=$pdo->query("SELECT l.cloud_ledger_id,a.request_key,a.package_tokens_used
        FROM ai_execution_ledger l JOIN ai_usage_ledger a ON a.id=l.cloud_ledger_id ORDER BY l.id")->fetchAll();
    usage_check(count($rows)===2&&(int)$rows[0]['package_tokens_used']===40&&$rows[0]['request_key']===$firstSystem['request_key']
        &&$rows[1]['request_key']===$secondSystem['request_key'],'Each call must link its exact package debit even under a shared trace.');
    usage_check((int)$pdo->query('SELECT COUNT(*) FROM ai_token_reservations')->fetchColumn()===0,'Settled package reserves must be released.');
    ai_usage_accounting_v032_record($pdo,$owner,7,8,['source'=>'vp3_tool']);
    usage_check(ai_usage_accounting_v032_export($pdo,1)[0]['source']==='vp3_tool','Legacy execution after a metered call must remain visible.');
    $pdo->beginTransaction();
    vp3_ai_service_usage_lock_owner_v1($pdo,1);
    $worker=proc_open([PHP_BINARY,__FILE__,'quota-worker',$database],[1=>['pipe','w'],2=>['pipe','w']],$pipes);
    usage_check(is_resource($worker),'Could not start concurrent quota test.');
    try{
        usage_check(fgets($pipes[1])==="ready\n",'Concurrent quota worker did not start.');
        $read=[$pipes[1]];$write=$except=[];
        usage_check(stream_select($read,$write,$except,0,200000)===0,'Legacy quota must wait on the shared owner lock.');
        $pdo->exec("INSERT INTO ai_token_reservations(user_id,subscription_id,scope,reserved_tokens,expires_at)
            VALUES(1,1,'chat',1900,DATE_ADD(NOW(),INTERVAL 3 MINUTE))");
        $pdo->commit();
        usage_check(fgets($pipes[1])==="blocked\n",'Legacy quota must see the concurrent reservation after the owner lock releases.');
    }finally{
        if($pdo->inTransaction())$pdo->rollBack();
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($worker);
    }
    echo "AI service usage, budget reservations and canonical package billing passed.\n";
}finally{
    $pdo->exec('DROP DATABASE '.$database);
}
