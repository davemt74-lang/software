<?php
declare(strict_types=1);
require_once dirname(__DIR__,2).'/includes/ai-usage-report-v1.php';
function db():PDO{return $GLOBALS['pageDb'];}
function table_exists(string $table):bool{$q=db()->prepare("SELECT name FROM sqlite_master WHERE type='table' AND name=?");$q->execute([$table]);return(bool)$q->fetchColumn();}
function column_exists(string $table,string $column):bool{return in_array($column,array_column(db()->query('PRAGMA table_info('.$table.')')->fetchAll(PDO::FETCH_ASSOC),'name'),true);}
function require_login():void{}
function current_user():array{return ['id'=>1];}
function subscription_ai_balance(array $user,PDO $pdo):array{return ['unlimited'=>true,'remaining'=>PHP_INT_MAX];}
function has_permission(string $key,array $user):bool{return true;}
function e(string $value):string{return htmlspecialchars($value,ENT_QUOTES,'UTF-8');}
function url(string $value):string{return $value;}
$pdo=$GLOBALS['pageDb']=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$pdo->exec("CREATE TABLE ai_execution_ledger(id INTEGER PRIMARY KEY AUTOINCREMENT,user_id INTEGER,agent_id INTEGER,conversation_id INTEGER,cloud_ledger_id INTEGER UNIQUE,trace_id TEXT,source TEXT,provider TEXT,model TEXT,input_tokens INTEGER DEFAULT 0,output_tokens INTEGER DEFAULT 0,total_tokens INTEGER DEFAULT 0,cloud_tokens_charged INTEGER DEFAULT 0,estimated_cost_micros INTEGER,cost_rate_source TEXT,cost_currency TEXT,homeserver_state TEXT,fallback_used INTEGER,failure_class TEXT,latency_ms INTEGER,run_id INTEGER,created_at TEXT DEFAULT CURRENT_TIMESTAMP)");
vp3_ai_service_usage_ensure_schema_v1($pdo);
for($i=0;$i<30;$i++)ai_usage_accounting_v032_record($pdo,['id'=>1],7,11,['source'=>'user_provider','provider'=>'anthropic','model'=>'fixture-unpriced','usage'=>['input_tokens'=>100,'output_tokens'=>25],'service_key'=>'conversation','service_stage'=>'planning']);
for($i=0;$i<40;$i++)ai_usage_accounting_v032_record($pdo,['id'=>1],7,11,['source'=>'vp3_tool']);
ai_usage_accounting_v032_record($pdo,['id'=>1],7,11,['source'=>'vp3_cloud','provider'=>'openai','model'=>'fixture-configured','usage'=>['input_tokens'=>100,'output_tokens'=>25],'service_key'=>'conversation']);
$pdo->exec("UPDATE ai_execution_ledger SET estimated_cost_micros=400,cost_rate_source='config:openai:fixture-configured' WHERE model='fixture-configured'");
ai_usage_accounting_v032_record($pdo,['id'=>1],7,11,['source'=>'vp3_cloud','provider'=>'openai','model'=>'fixture-unknown','service_key'=>'direct','service_status'=>'unknown']);
ai_usage_accounting_v032_record($pdo,['id'=>1],7,11,['source'=>'vp3_cloud','provider'=>'openai','model'=>'fixture-pending','service_key'=>'initiative','service_status'=>'pending']);
ai_usage_accounting_v032_record($pdo,['id'=>2],8,12,['source'=>'vp3_cloud','provider'=>'foreign-provider','model'=>'private-foreign-model','usage'=>['input_tokens'=>999,'output_tokens'=>999]]);
parse_str($argv[1]??'',$_GET);
$source=file_get_contents(dirname(__DIR__,2).'/ai-usage.php');
$source=str_replace(["<?php\ndeclare(strict_types=1);", "require __DIR__.'/includes/bootstrap.php';", "require_once __DIR__.'/includes/ai-usage-report-v1.php';"],['<?php','',''],$source);
$source=str_replace("require __DIR__.'/includes/workspace-sidebar-v82.php';",'?><aside class="chat-sidebar" id="chatSidebar"><strong>VP3</strong><p>AI Usage</p></aside><?php ',$source);
eval('?>'.$source);
