<?php
declare(strict_types=1);

require_once __DIR__.'/../includes/agent-connection-gmail-inbox-initial-summary-v1.php';
require_once __DIR__.'/../includes/ai-runtime-v100.php';
require_once __DIR__.'/../includes/ai-service-policy-v1.php';
require_once __DIR__.'/../includes/user-llm-v1.php';

// Use the shipped prompt builders and broker with fixture provider transport.
$source = file_get_contents(__DIR__.'/../includes/ai-settings.php');
$start = strpos($source, 'function ai_context_prompt(');
$end = strpos($source, 'function ai_curl_json(', $start);
eval(str_replace("require_once __DIR__.'/agent-tool-json-v1.php';", '', substr($source,$start,$end-$start)));
$start = strpos($source, 'function ai_openai_response(');
$end = strpos($source, 'function ai_test_provider(', $start);
eval(substr($source,$start,$end-$start));

function db(): PDO { return $GLOBALS['gmailSummaryTestDb']; }
function setting(string $key, string $fallback = ''): string { return $fallback; }
function column_exists(string $table, string $column): bool
{
    return in_array($column,array_column(db()->query('PRAGMA table_info('.$table.')')->fetchAll(),'name'),true);
}
function subscription_schema_ready(?PDO $pdo = null): bool { return true; }
function subscription_has_entitlement(array $user, string $capability): bool { return $capability === 'main_ai.access'; }
function ai_active_provider(): string { return 'openai'; }
function ai_provider_api_key(string $provider): string { return 'fixture-system-key'; }
function ai_provider_model(string $provider): string { return 'fixture-model'; }
function ai_provider_configuration_failure(string $provider): ?array { return null; }
function ai_decrypt_secret(string $secret): string { return $secret; }
function ai_valid_model(string $provider, string $model): bool { return $model === 'fixture-model'; }
function agent_brain_soul(array $user): array { return ['canary'=>'private-chat-persona']; }
function agent_brain_tool_prompt(array $user): string { return 'private-chat-tools'; }
function chat_response_context(string $query,array $context): array
{
    throw new RuntimeException('Gmail summary accessed ordinary Chat context.');
}
function vp3_agent_runtime_plan_v420(PDO $pdo,array $user,int $agentId,string $surface,array $context): array
{
    return empty($GLOBALS['gmailSummaryHomeOnly'])
        ? ['blocked'=>false,'route'=>'vp3_cloud','cloud'=>['ready'=>true]]
        : ['blocked'=>true,'effective_preference'=>'homeserver_only'];
}
function subscription_ai_preflight(array $user,string $scope,int $input,int $output,?PDO $pdo=null): array
{
    return ['reservation_id'=>0,'max_output_tokens'=>$output];
}
function subscription_ai_commit_usage(int $reservation,array $user,string $scope,string $provider,string $model,
    array $usage,string $requestKey,?PDO $pdo=null): void
{
    ($pdo??db())->prepare('INSERT INTO ai_usage_ledger(user_id,request_key,package_tokens_used,credit_tokens_used) VALUES (?,?,?,0)')
        ->execute([$user['id'],$requestKey,$usage['total_tokens']]);
}
function ai_curl_json(string $endpoint,array $headers,array $payload,int $timeout=45,?AiV100ServiceMeter $meter=null,
    ?array $target=null,?string $personalKey=null,bool $singleAttempt=false): array
{
    if (!$meter?->active || !$target) throw new RuntimeException('Unmetered Gmail summary.');
    try {
        $ticket = $meter->begin($target,$payload,$target['provider']==='openai'?'max_output_tokens':'max_tokens');
    } catch (Throwable $error) {
        return ai_v100_service_budget_failure($error);
    }
    $GLOBALS['gmailSummaryDispatch'][] = ['payload'=>$payload,'single'=>$singleAttempt,
        'funding'=>$target['funding'],'provider'=>$target['provider']];
    $scenario = $GLOBALS['gmailSummaryScenario'] ?? 'valid';
    if ($scenario === 'rejected') {
        $meter->finish($ticket,[],'failed',['confirmed_no_charge'=>true]);
        return ['ok'=>false,'status'=>429];
    }
    if ($scenario === 'unknown') {
        $meter->finish($ticket,[],'unknown');
        return ['ok'=>false,'status'=>503];
    }
    $usage = ['input_tokens'=>120,'output_tokens'=>20,'total_tokens'=>140];
    $meter->finish($ticket,$usage,'completed');
    $answer = $scenario === 'invalid' ? '{"topics":[{"theme":"billing","refs":[99]}]}'
        : '{"topics":[{"theme":"scheduling","refs":[1]},{"theme":"billing","refs":[2]}]}';
    return ['ok'=>true,'data'=>['output_text'=>$answer,'content'=>[['type'=>'text','text'=>$answer]],'usage'=>$usage]];
}
function check_gmail_summary(bool $ok,string $message): void
{
    if (!$ok) throw new RuntimeException($message);
    echo 'PASS '.$message.PHP_EOL;
}

$runtimeRoot = sys_get_temp_dir().'/vp3-gmail-summary-'.bin2hex(random_bytes(6));
define('STONEFELLOW_ROOT',$runtimeRoot);
register_shutdown_function(static function () use ($runtimeRoot): void {
    if (!is_dir($runtimeRoot)) return;
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($runtimeRoot,FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($files as $file) {
        if ($file->isDir()) rmdir($file->getPathname());
        else unlink($file->getPathname());
    }
    rmdir($runtimeRoot);
});
$pdo = new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$GLOBALS['gmailSummaryTestDb'] = $pdo;
$GLOBALS['gmailSummaryDispatch'] = [];
$pdo->exec("CREATE TABLE users(id INTEGER PRIMARY KEY); INSERT INTO users VALUES(1);
    CREATE TABLE settings(setting_key TEXT PRIMARY KEY, setting_value TEXT);
    CREATE TABLE ai_execution_ledger(id INTEGER PRIMARY KEY,user_id INTEGER,agent_id INTEGER,conversation_id INTEGER,
        trace_id TEXT,source TEXT,provider TEXT,model TEXT,run_id INTEGER,created_at TEXT,
        input_tokens INTEGER DEFAULT 0,output_tokens INTEGER DEFAULT 0,total_tokens INTEGER DEFAULT 0,
        cloud_tokens_charged INTEGER DEFAULT 0,latency_ms INTEGER DEFAULT 0,estimated_cost_micros INTEGER,
        cost_rate_source TEXT DEFAULT '',cloud_ledger_id INTEGER,failure_class TEXT DEFAULT 'none');
    CREATE TABLE ai_usage_ledger(id INTEGER PRIMARY KEY,user_id INTEGER,request_key TEXT UNIQUE,
        package_tokens_used INTEGER,credit_tokens_used INTEGER);
    CREATE TABLE user_llm_preferences(user_id INTEGER PRIMARY KEY,route TEXT,provider TEXT);
    CREATE TABLE user_llm_credentials(user_id INTEGER,provider TEXT,encrypted_key TEXT,model TEXT)");
$owner = ['id'=>1,'is_active'=>1];
$report = ['total'=>9,'items'=>[
    ['from'=>'calendar@example.test','subject'=>"Meeting\nproposal",'category'=>'new','body'=>'private-mail-body'],
    ['from'=>'billing@example.test','subject'=>'Invoice question','category'=>'reply'],
    ...array_fill(0,6,['from'=>'news@example.test','subject'=>str_repeat('é',200),'category'=>'new']),
    ['from'=>'ninth@example.test','subject'=>'ninth-header','category'=>'new'],
]];
$run = static fn(?callable $before=null) => agent_connection_gmail_inbox_initial_summary_v1($pdo,$owner,$report,$before);
check_gmail_summary($run()['status']==='unavailable' && !$GLOBALS['gmailSummaryDispatch'],
    'a missing usage ledger blocks the paid dispatch');
vp3_ai_service_usage_ensure_schema_v1($pdo);
vp3_ai_service_save_model_v1($pdo,'openai','fixture-model','Fixture',['initiative']);
vp3_ai_service_save_model_v1($pdo,'anthropic','fixture-model','Fixture fallback',['initiative']);
vp3_ai_service_save_route_v1($pdo,'initiative',['provider'=>'openai','model'=>'fixture-model'],
    ['provider'=>'anthropic','model'=>'fixture-model']);
check_gmail_summary(agent_connection_gmail_inbox_initial_summary_v1($pdo,$owner,['total'=>0])['status']==='no_messages'
    && !$GLOBALS['gmailSummaryDispatch'],'an empty scan needs no model call');
check_gmail_summary($run(static fn()=>false)['status']==='unavailable' && !$GLOBALS['gmailSummaryDispatch'],
    'an invalidated review claim prevents the paid dispatch');
$GLOBALS['gmailSummaryHomeOnly'] = true;
check_gmail_summary($run()['status']==='unavailable' && !$GLOBALS['gmailSummaryDispatch'],
    'HomeServer-only policy prevents Cloud summary dispatch');
$GLOBALS['gmailSummaryHomeOnly'] = false;
$result = $run();
check_gmail_summary($result===['status'=>'ready','summary'=>'The sampled subjects suggest scheduling and billing.',
    'tokens_charged'=>140],'verified topic refs produce a short summary and exact token debit');
$call = $GLOBALS['gmailSummaryDispatch'][0] ?? [];
$payload = $call['payload'] ?? [];
$wire = json_encode($payload,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);
check_gmail_summary($call['single']===true && $payload['max_output_tokens']===384
    && $call['funding']==='system' && str_contains($wire,'Meeting proposal')
    && !str_contains($wire,'private-mail-body') && !str_contains($wire,'ninth-header')
    && !str_contains($wire,'private-chat-persona') && !str_contains($wire,str_repeat('é',91)),
    'provider request has one bounded attempt with only eight sanitized headers');
$row = $pdo->query('SELECT * FROM ai_execution_ledger')->fetch(PDO::FETCH_ASSOC);
check_gmail_summary($row['service_key']==='initiative' && (int)$row['is_background']===1
    && $row['service_stage']==='vp3.gmail-inbox-initial-summary.v1' && ai_v100_service_context()===[],
    'the summary is attributed to the background Initiative budget');

vp3_ai_service_save_rule_v1($pdo,'user',1,'initiative',['background_daily_tokens'=>140]);
check_gmail_summary($run()['status']==='unavailable' && count($GLOBALS['gmailSummaryDispatch'])===1,
    'an exhausted background budget prevents a second provider call');
vp3_ai_service_save_rule_v1($pdo,'user',1,'initiative',[]);
$GLOBALS['gmailSummaryScenario'] = 'invalid';
check_gmail_summary($run()===['status'=>'unverified','summary'=>'','tokens_charged'=>140]
    && count($GLOBALS['gmailSummaryDispatch'])===2,'invalid refs are withheld and the paid attempt stays accounted');
$GLOBALS['gmailSummaryScenario'] = 'rejected';
check_gmail_summary($run()===['status'=>'unavailable','summary'=>'','tokens_charged'=>0]
    && count($GLOBALS['gmailSummaryDispatch'])===3,'a rejected provider request does not try the configured fallback');
$GLOBALS['gmailSummaryScenario'] = 'unknown';
check_gmail_summary($run()===['status'=>'unverified','summary'=>'','tokens_charged'=>null]
    && count($GLOBALS['gmailSummaryDispatch'])===4,'unknown provider usage remains unknown without replay');

$pdo->exec("INSERT INTO user_llm_preferences VALUES(1,'own_key','openai')");
check_gmail_summary($run()['status']==='unavailable' && count($GLOBALS['gmailSummaryDispatch'])===4,
    'missing personal credentials do not fall back to the shared system key');
$pdo->exec("INSERT INTO user_llm_credentials VALUES(1,'openai','fixture-personal-key','fixture-model')");
$GLOBALS['gmailSummaryScenario'] = 'valid';
$result = $run();
check_gmail_summary($result['status']==='ready' && $result['tokens_charged']===0
    && end($GLOBALS['gmailSummaryDispatch'])['funding']==='own_key'
    && (int)$pdo->query('SELECT COUNT(*) FROM ai_usage_ledger')->fetchColumn()===2,
    'personal funding uses the account key and records no VP3 token debit');
echo 'GMAIL_INBOX_INITIAL_SUMMARY_V1=PASS'.PHP_EOL;
