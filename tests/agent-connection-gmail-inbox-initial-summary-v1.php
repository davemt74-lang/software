<?php
declare(strict_types=1);

require_once __DIR__.'/../includes/agent-connection-gmail-inbox-initial-summary-v1.php';
require_once __DIR__.'/../includes/ai-runtime-v100.php';
require_once __DIR__.'/../includes/ai-service-policy-v1.php';
require_once __DIR__.'/../includes/user-llm-v1.php';

// Use the shipped prompt builders, broker and metered transport. Only the
// cURL handle is simulated, so the dispatch guard runs on the real call path.
$source = file_get_contents(__DIR__.'/../includes/ai-settings.php');
$start = strpos($source, 'function ai_context_prompt(');
$end = strpos($source, 'function ai_curl_json(', $start);
eval(str_replace("require_once __DIR__.'/agent-tool-json-v1.php';", '', substr($source,$start,$end-$start)));
$start = $end;
$end = strpos($source, 'function ai_openai_response(', $start);
eval('namespace GmailSummaryWire; use \\AiV100ServiceMeter; use \\Throwable;'.substr($source,$start,$end-$start));
foreach (['CURLOPT_POST','CURLOPT_RETURNTRANSFER','CURLOPT_CONNECTTIMEOUT','CURLOPT_TIMEOUT','CURLOPT_CONNECTTIMEOUT_MS',
    'CURLOPT_TIMEOUT_MS','CURLOPT_HTTPHEADER','CURLOPT_POSTFIELDS','CURLOPT_FOLLOWLOCATION','CURLOPT_SSL_VERIFYPEER',
    'CURLOPT_SSL_VERIFYHOST','CURLINFO_HTTP_CODE'] as $index=>$name) {
    if (!defined($name)) define($name,57000+$index);
}
eval(<<<'PHP'
namespace GmailSummaryWire;
function function_exists(string $name): bool { return \function_exists(__NAMESPACE__.'\\'.$name) || \function_exists($name); }
function curl_init(string $endpoint): object { return (object)['endpoint'=>$endpoint,'options'=>[],'status'=>200]; }
function curl_setopt_array(object $handle,array $options): bool { $handle->options=$options; return true; }
function curl_setopt(object $handle,int $option,mixed $value): bool { $handle->options[$option]=$value; return true; }
function curl_getinfo(object $handle,int $option): int { return $handle->status; }
function curl_error(object $handle): string { return ''; }
function curl_close(object $handle): void { $GLOBALS['gmailSummaryClosed']=($GLOBALS['gmailSummaryClosed']??0)+1; }
function curl_exec(object $handle): string
{
    $payload = json_decode($handle->options[CURLOPT_POSTFIELDS],true,16,JSON_THROW_ON_ERROR);
    $attempt = \db()->query('SELECT credential_source,provider FROM ai_execution_ledger WHERE service_status=\'pending\'')->fetch();
    $GLOBALS['gmailSummaryDispatch'][] = ['payload'=>$payload,'funding'=>$attempt['credential_source'],'provider'=>$attempt['provider']];
    $scenario = $GLOBALS['gmailSummaryScenario'] ?? 'valid';
    if ($scenario === 'rejected') { $handle->status=429; return '{}'; }
    if ($scenario === 'unknown') { $handle->status=503; return '{}'; }
    $answer = match ($scenario) {
        'invalid'=>'{"topics":[{"theme":"billing","refs":[99]}]}',
        'reply'=>'{"body":"Thanks for the update."}',
        default=>'{"topics":[{"theme":"scheduling","refs":[1]},{"theme":"billing","refs":[2]}]}',
    };
    return json_encode(['output_text'=>$answer,'content'=>[['type'=>'text','text'=>$answer]],
        'usage'=>['input_tokens'=>120,'output_tokens'=>20,'total_tokens'=>140]],JSON_THROW_ON_ERROR);
}
PHP);
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
function ai_haiku55_request(array $payload): array { return $payload; }
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
    if (isset($GLOBALS['gmailSummaryOnRoute'])) ($GLOBALS['gmailSummaryOnRoute'])();
    return empty($GLOBALS['gmailSummaryHomeOnly'])
        ? ['blocked'=>false,'route'=>'vp3_cloud','cloud'=>['ready'=>true]]
        : ['blocked'=>true,'effective_preference'=>'homeserver_only'];
}
function subscription_ai_preflight(array $user,string $scope,int $input,int $output,?PDO $pdo=null): array
{
    $pdo ??= db();
    $pdo->prepare('INSERT INTO ai_token_reservations(user_id) VALUES (?)')->execute([$user['id']]);
    if (!empty($GLOBALS['gmailSummaryExpireReservation'])) $GLOBALS['VP3_AI_INFERENCE_BUDGET_V1']['deadline'] = 0.0;
    return ['reservation_id'=>(int)$pdo->lastInsertId(),'max_output_tokens'=>$output];
}
function subscription_ai_commit_usage(int $reservation,array $user,string $scope,string $provider,string $model,
    array $usage,string $requestKey,?PDO $pdo=null): void
{
    $pdo ??= db();
    $pdo->prepare('INSERT INTO ai_usage_ledger(user_id,request_key,package_tokens_used,credit_tokens_used) VALUES (?,?,?,0)')
        ->execute([$user['id'],$requestKey,$usage['total_tokens']]);
    $pdo->prepare('DELETE FROM ai_token_reservations WHERE id=? AND user_id=?')->execute([$reservation,$user['id']]);
}
function ai_curl_json(string $endpoint,array $headers,array $payload,int $timeout=45,?AiV100ServiceMeter $meter=null,
    ?array $target=null,?string $personalKey=null,bool $singleAttempt=false,?callable $observe=null,?callable $beforeDispatch=null): array
{
    if (!$meter?->active || !$target) throw new RuntimeException('Unmetered Gmail summary.');
    return GmailSummaryWire\ai_curl_json($endpoint,$headers,$payload,$timeout,$meter,$target,$personalKey,
        $singleAttempt,$observe,$beforeDispatch);
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
    CREATE TABLE ai_token_reservations(id INTEGER PRIMARY KEY,user_id INTEGER);
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
$claimCurrent = true;
$GLOBALS['gmailSummaryOnRoute'] = static function () use (&$claimCurrent): void { $claimCurrent = false; };
$result = $run(static function () use (&$claimCurrent): bool { return $claimCurrent; });
unset($GLOBALS['gmailSummaryOnRoute']);
check_gmail_summary(!$claimCurrent && $result===['status'=>'unavailable','summary'=>'','tokens_charged'=>0]
    && !$GLOBALS['gmailSummaryDispatch'] && (int)$pdo->query('SELECT COUNT(*) FROM ai_execution_ledger')->fetchColumn()===0,
    'revocation during route discovery prevents dispatch and usage reservation');
$reserved = false;
$result = $run(static function () use ($pdo,&$reserved): bool {
    $reserved = (int)$pdo->query('SELECT COUNT(*) FROM ai_token_reservations WHERE user_id=1')->fetchColumn()===1;
    return !$reserved;
});
$unpaidAbort = static fn() => !$GLOBALS['gmailSummaryDispatch']
    && (int)$pdo->query('SELECT COUNT(*) FROM ai_token_reservations')->fetchColumn()===0
    && (int)$pdo->query('SELECT COUNT(*) FROM ai_execution_ledger')->fetchColumn()===0
    && (int)$pdo->query('SELECT COUNT(*) FROM ai_usage_ledger')->fetchColumn()===0;
check_gmail_summary($reserved && $result===['status'=>'unavailable','summary'=>'','tokens_charged'=>0]
    && $unpaidAbort() && ($GLOBALS['gmailSummaryClosed']??0)===1,
    'revocation after reservation closes the unsent handle and releases usage without a charge');
$GLOBALS['gmailSummaryExpireReservation'] = true;
$result = $run();
unset($GLOBALS['gmailSummaryExpireReservation']);
check_gmail_summary($result===['status'=>'unavailable','summary'=>'','tokens_charged'=>0]
    && $unpaidAbort() && ($GLOBALS['gmailSummaryClosed']??0)===2,
    'deadline expiration while reserving usage prevents dispatch and releases the reservation');
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
check_gmail_summary($payload['max_output_tokens']===384
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
$pdo->exec("UPDATE user_llm_preferences SET route='system' WHERE user_id=1");
vp3_ai_service_save_model_v1($pdo,'openai','fixture-model','Fixture',['initiative','conversation']);
vp3_ai_service_save_model_v1($pdo,'anthropic','fixture-model','Fixture fallback',['initiative','conversation']);
vp3_ai_service_save_route_v1($pdo,'conversation',['provider'=>'openai','model'=>'fixture-model'],
    ['provider'=>'anthropic','model'=>'fixture-model']);
$reply = static fn(Closure $guard): array => ai_v100_with_service_context(['service'=>'conversation',
    'stage'=>'vp3.gmail-reply-draft.v1','job_key'=>'fixture-reply-'.bin2hex(random_bytes(8))],
    static fn()=>ai_generate_chat_response('{"owner_instruction":"","mode":"auto","original_message":{"excerpt":"exact private source"}}',
        [['role'=>'assistant','message'=>'unrelated previous message']], [['title'=>'unrelated private context']],$owner,'main_ai',
        ['surface'=>'tool_json','protocol'=>'vp3.gmail-reply-draft.v1','before_gmail_reply_dispatch'=>$guard]));
$callCount = count($GLOBALS['gmailSummaryDispatch']);
$reservationHeld = false;
$blockedReply = $reply(static function() use ($pdo,&$reservationHeld): bool {
    $reservationHeld = (int)$pdo->query('SELECT COUNT(*) FROM ai_token_reservations WHERE user_id=1')->fetchColumn() === 1;
    return false;
});
check_gmail_summary($reservationHeld && empty($blockedReply['ok']) && count($GLOBALS['gmailSummaryDispatch']) === $callCount
    && (int)$pdo->query('SELECT COUNT(*) FROM ai_token_reservations')->fetchColumn() === 0,
    'Gmail reply revocation after usage reservation aborts before provider dispatch');
$GLOBALS['gmailSummaryScenario'] = 'reply';
$draft = $reply(static fn(): bool=>true);
$replyCall = end($GLOBALS['gmailSummaryDispatch']);
$replyWire = json_encode($replyCall['payload'],JSON_THROW_ON_ERROR);
$replyLedger = $pdo->query("SELECT * FROM ai_execution_ledger WHERE service_stage='vp3.gmail-reply-draft.v1' ORDER BY id DESC LIMIT 1")->fetch();
check_gmail_summary(!empty($draft['ok']) && $draft['answer'] === '{"body":"Thanks for the update."}'
    && $replyLedger['service_key'] === 'conversation' && (int)$replyLedger['is_background'] === 0 && (int)$replyLedger['cloud_tokens_charged'] === 140
    && count($GLOBALS['gmailSummaryDispatch']) === $callCount+1 && str_contains($replyWire,'exact private source')
    && !str_contains($replyWire,'unrelated previous message') && !str_contains($replyWire,'unrelated private context') && !str_contains($replyWire,'private-chat-persona'),
    'Gmail reply sends one isolated foreground request and charges its measured tokens');
$GLOBALS['gmailSummaryScenario'] = 'rejected';
$rejectedReply = $reply(static fn(): bool=>true);
check_gmail_summary(empty($rejectedReply['ok']) && count($GLOBALS['gmailSummaryDispatch']) === $callCount+2,
    'a rejected Gmail reply draft makes no retry or fallback request');
echo 'GMAIL_INBOX_INITIAL_SUMMARY_V1=PASS'.PHP_EOL;
