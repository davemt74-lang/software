<?php
declare(strict_types=1);

// Exercise the shipped inference wrapper, Chat broker, provider payload builder,
// service policy/context and usage meter together. Configuration, subscription
// settlement and the outbound HTTP response are the only external fixtures.
require __DIR__.'/fixtures/image-generation-v1.php';
require_once dirname(__DIR__).'/includes/agent-initiative-model-v1.php';
require_once dirname(__DIR__).'/includes/ai-runtime-v100.php';
require_once dirname(__DIR__).'/includes/ai-service-policy-v1.php';
require_once dirname(__DIR__).'/includes/user-llm-v1.php';
require_once dirname(__DIR__).'/includes/ai-service-usage-v1.php';

$source=file_get_contents(dirname(__DIR__).'/includes/ai-settings.php');
$from=strpos($source,'function ai_context_prompt(');$to=strpos($source,'function ai_curl_json(',$from);
if($from===false||$to===false)throw new RuntimeException('Canonical provider prompt builders are unavailable.');
eval(str_replace("require_once __DIR__.'/agent-tool-json-v1.php';",'',substr($source,$from,$to-$from)));
$from=strpos($source,'function ai_openai_response(');$to=strpos($source,'function ai_test_provider(',$from);
if($from===false||$to===false)throw new RuntimeException('Canonical Chat broker is unavailable.');
eval(substr($source,$from,$to-$from));

function ai_active_provider(): string{return 'anthropic';}
function ai_provider_model(string $provider): string{return 'legacy-conversation-model';}
function ai_valid_model(string $provider,string $model): bool{return in_array($provider,['openai','anthropic'],true)&&$model==='legacy-conversation-model';}
function ai_provider_configuration_failure(string $provider): ?array{return null;}
function agent_brain_soul(array $user): array{return [];}
function agent_brain_default_soul(): array{return [];}
function agent_brain_tool_prompt(array $user): string{return 'Legacy unrelated tools';}
function chat_response_context(string $query,array $context): array
{
    $GLOBALS['canvasEnrichmentCalls']=($GLOBALS['canvasEnrichmentCalls']??0)+1;
    return [...$context,['source'=>'private-history','title'=>'Unrelated private history','text'=>'SECRET_UNDECLARED_HISTORY']];
}
function vp3_agent_runtime_plan_v420(PDO $pdo,array $user,int $agentId,string $surface,array $context=[]): array
{
    $GLOBALS['canvasRuntimeContexts'][]=$context;
    return ['route'=>'vp3_cloud','effective_preference'=>'vp3_cloud','blocked'=>false,'cloud'=>['ready'=>true]];
}
function ai_curl_json(string $endpoint,array $headers,array $payload,int $timeout=45,?AiV100ServiceMeter $meter=null,
    ?array $target=null,?string $personalKey=null,bool $singleAttempt=false): array
{
    $usage=['input_tokens'=>120,'output_tokens'=>60,'total_tokens'=>180];
    if($meter!==null&&$target!==null){
        try{$ticket=$meter->begin($target,$payload,$target['provider']==='openai'?'max_output_tokens':'max_tokens');}
        catch(Throwable $error){return ai_v100_service_budget_failure($error);}
    }
    $GLOBALS['canvasTransportCalls'][]=['endpoint'=>$endpoint,'payload'=>$payload,'target'=>$target,
        'personal_key'=>$personalKey,'service'=>ai_v100_service_context()];
    if($meter!==null&&$target!==null){
        $meter->finish($ticket,$usage,'completed',['provider_request_id'=>'fixture-canvas-proposal']);
    }
    $answer='{"title":"Prep board","prompt":"Track launch preparation tasks privately","draft":"Would a prep board help?"}';
    return ['ok'=>true,'data'=>['output_text'=>$answer,'content'=>[['type'=>'text','text'=>$answer]],'usage'=>$usage],
        'attempts'=>1,'duration_ms'=>1];
}

$pdo->exec("CREATE TABLE settings(setting_key TEXT PRIMARY KEY,setting_value TEXT);
    CREATE TABLE ai_execution_ledger(id INTEGER PRIMARY KEY,user_id INTEGER,agent_id INTEGER,conversation_id INTEGER,
        trace_id TEXT,source TEXT,provider TEXT,model TEXT,run_id INTEGER,created_at TEXT,
        input_tokens INTEGER DEFAULT 0,output_tokens INTEGER DEFAULT 0,total_tokens INTEGER DEFAULT 0,
        cloud_tokens_charged INTEGER DEFAULT 0,latency_ms INTEGER DEFAULT 0,estimated_cost_micros INTEGER,
        cost_rate_source TEXT DEFAULT '',cloud_ledger_id INTEGER,failure_class TEXT DEFAULT 'none')");
vp3_ai_service_usage_ensure_schema_v1($pdo);
vp3_ai_service_save_model_v1($pdo,'openai','canvas-proposal-fixture','Canvas proposal',['initiative']);
vp3_ai_service_save_route_v1($pdo,'initiative',['provider'=>'openai','model'=>'canvas-proposal-fixture'],null);
$GLOBALS['imageSettings']['ai_chat_requests_per_minute']='120';
$runtimeRoot=sys_get_temp_dir().'/vp3-canvas-transport-'.bin2hex(random_bytes(5));
if(!defined('STONEFELLOW_ROOT'))define('STONEFELLOW_ROOT',$runtimeRoot);
register_shutdown_function(static function()use($runtimeRoot):void{
    if(!is_dir($runtimeRoot))return;
    $files=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($runtimeRoot,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
    foreach($files as $file){if($file->isDir())rmdir($file->getPathname());else unlink($file->getPathname());}
    rmdir($runtimeRoot);
});
$checks=0;
function canvas_transport_check(bool $ok,string $label): void
{
    if(!$ok)throw new RuntimeException($label);
    ++$GLOBALS['checks'];echo 'PASS '.$label.PHP_EOL;
}
$owner=['id'=>1,'is_active'=>1];
$prompt=json_encode(['focus'=>'Build a practical launch prep board','evidence'=>[
    ['ref'=>'task:1','title'=>'CANVAS_ONLY_CONTEXT','detail'=>'The verified owner task is preparing a launch.']]],JSON_THROW_ON_ERROR);
$answer=vp3_initiative_infer_v1($pdo,$owner,7,$prompt,'vp3.initiative-canvas.v1',false,null,
    ['background'=>true,'run_id'=>44,'conversation_id'=>11]);
canvas_transport_check(json_decode($answer,true)['title']==='Prep board',
    'the real inference, Chat broker and provider builders accept the Canvas proposal protocol');
$call=$GLOBALS['canvasTransportCalls'][0]??[];$payload=$call['payload']??[];
canvas_transport_check(($GLOBALS['canvasEnrichmentCalls']??0)===0
    &&!str_contains(json_encode($payload,JSON_THROW_ON_ERROR),'SECRET_UNDECLARED_HISTORY')
    &&count($payload['input']??[])===1&&($payload['input'][0]['role']??'')==='user'
    &&str_ends_with($payload['input'][0]['content']??'',$prompt)
    &&str_contains($payload['input'][0]['content']??'',"<stonefellow_retrieved_data_json>\n[]\n"),
    'Canvas proposal dispatch contains only supplied evidence and never generic private Chat enrichment');
canvas_transport_check(str_contains($payload['instructions']??'','practical private Canvas/app brief')
    &&!str_contains($payload['instructions']??'','USER STYLE')
    &&!str_contains($payload['instructions']??'','Legacy unrelated tools'),
    'the actual provider receives the Canvas structured contract without persona or undeclared tool catalogs');
canvas_transport_check(($call['endpoint']??'')==='https://api.openai.com/v1/responses'
    &&($payload['model']??'')==='canvas-proposal-fixture'
    &&($call['target']['funding']??'')==='system'&&$call['personal_key']===null,
    'the configured Initiative model and system funding override the unrelated default conversation provider');
canvas_transport_check(($call['service']??null)===['service'=>'initiative','agent_id'=>7,
    'stage'=>'vp3.initiative-canvas.v1','background'=>true,'run_id'=>44,'conversation_id'=>11]
    &&ai_v100_service_context()===[],
    'actual service attribution carries the exact owner run and restores the previous context');
$row=$pdo->query('SELECT * FROM ai_execution_ledger ORDER BY id DESC LIMIT 1')->fetch(PDO::FETCH_ASSOC);
canvas_transport_check($row['service_key']==='initiative'&&$row['service_stage']==='vp3.initiative-canvas.v1'
    &&(int)$row['user_id']===1&&(int)$row['agent_id']===7&&(int)$row['conversation_id']===11
    &&(int)$row['run_id']===44&&(int)$row['is_background']===1&&$row['credential_source']==='system'
    &&$row['service_status']==='completed'&&(int)$row['total_tokens']===180&&(int)$row['service_budget_tokens']===180,
    'the real usage meter settles one background Initiative attempt with exact user, Agent, Chat and tokens');
canvas_transport_check(count($GLOBALS['imagePreflight']??[])===1&&count($GLOBALS['imageUsage']??[])===1,
    'one physical Canvas-proposal provider call reserves and settles package usage once');

$before=count($GLOBALS['canvasTransportCalls']);
vp3_ai_service_save_rule_v1($pdo,'user',1,'initiative',['enabled'=>false]);
try{
    vp3_initiative_infer_v1($pdo,$owner,7,$prompt,'vp3.initiative-canvas.v1',false,null,['background'=>true,'run_id'=>45]);
    throw new LogicException('Disabled Initiative inference was accepted.');
}catch(RuntimeException $error){
    canvas_transport_check(count($GLOBALS['canvasTransportCalls'])===$before
        &&(int)$pdo->query('SELECT COUNT(*) FROM ai_execution_ledger')->fetchColumn()===1,
        'current Initiative policy disables Canvas-proposal inference without a provider request or extra usage');
}
vp3_ai_service_save_rule_v1($pdo,'user',1,'initiative',[]);

vp3_ai_service_save_rule_v1($pdo,'user',1,'initiative',['background_daily_tokens'=>180]);
try{
    vp3_initiative_infer_v1($pdo,$owner,7,$prompt,'vp3.initiative-canvas.v1',false,null,['background'=>true,'run_id'=>46]);
    throw new LogicException('Exhausted Initiative budget was accepted.');
}catch(RuntimeException $error){
    canvas_transport_check(count($GLOBALS['canvasTransportCalls'])===$before
        &&(int)$pdo->query('SELECT COUNT(*) FROM ai_execution_ledger')->fetchColumn()===1,
        'the actual exhausted background Initiative token budget prevents another provider dispatch');
}
vp3_ai_service_save_rule_v1($pdo,'user',1,'initiative',[]);

// Prove that the enrichment canary is live: ordinary conversation still uses
// its existing context route, so the Canvas assertions cannot pass accidentally.
$ordinary=ai_generate_chat_response('Ordinary conversation',[],[],$owner,'main_ai',null);
canvas_transport_check(!empty($ordinary['ok'])&&($GLOBALS['canvasEnrichmentCalls']??0)===1
    &&str_contains(json_encode(end($GLOBALS['canvasTransportCalls']),JSON_THROW_ON_ERROR),'SECRET_UNDECLARED_HISTORY'),
    'ordinary conversation retains its existing enrichment while the Canvas protocol is isolated');
echo 'AGENT_INITIATIVE_CANVAS_TRANSPORT_V1=PASS ('.$checks.' cases; fixture HTTP, actual broker, policy and meter)'.PHP_EOL;
