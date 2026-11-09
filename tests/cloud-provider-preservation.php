<?php
declare(strict_types=1);

namespace ProviderRegression;

// Load production functions in a namespace solely to replace outbound HTTP.
// Credential encryption, configuration, request construction and SSE parsing
// are the actual application code; no real provider call is made.
$root=sys_get_temp_dir().'/vp3-provider-'.bin2hex(random_bytes(6));
\define('STONEFELLOW_ROOT',$root);
$settings=[];$requests=[];$replyStatus=200;$checks=0;$own=['route'=>'system'];$wrongTestReply=false;$streamReplyStatus=null;$providerStatuses=[];
function db(): \PDO {
    static $pdo;
    if($pdo instanceof \PDO)return $pdo;
    $pdo=new \PDO('sqlite::memory:',null,null,[\PDO::ATTR_ERRMODE=>\PDO::ERRMODE_EXCEPTION]);
    // Actual empty SQL inventories let production prove that no encrypted
    // Profile call settings/snapshots exist before creating the fixture key.
    $pdo->exec('CREATE TABLE settings(setting_key TEXT PRIMARY KEY,setting_value TEXT NOT NULL)');
    $pdo->exec('CREATE TABLE profile_calls_pa3c(id INTEGER PRIMARY KEY,provider_snapshot TEXT NOT NULL)');
    return $pdo;
}
function vp3_user_llm_v1_effective(\PDO $pdo,array $user): array {return $GLOBALS['own'];}
function subscription_ai_preflight(?array $user,string $scope,int $input,int $output): array {
    return ['reservation_id'=>0,'max_output_tokens'=>$output,'unlimited'=>true];
}
function subscription_ai_commit_usage(...$args): void {}
// The HTTP double also runs in local/CI PHP builds without the cURL extension.
foreach(['CURLOPT_POST','CURLOPT_RETURNTRANSFER','CURLOPT_CONNECTTIMEOUT','CURLOPT_TIMEOUT','CURLOPT_HTTPHEADER','CURLOPT_POSTFIELDS','CURLOPT_FOLLOWLOCATION','CURLOPT_SSL_VERIFYPEER','CURLOPT_SSL_VERIFYHOST','CURLOPT_BUFFERSIZE','CURLOPT_WRITEFUNCTION','CURLINFO_HTTP_CODE'] as $index=>$name){
    if(!\defined($name))\define($name,50000+$index);
}
function check(bool $ok,string $label): void {
    $GLOBALS['checks']++;
    if(!$ok)throw new \RuntimeException($label);
}
function function_exists(string $name): bool {
    return \function_exists(__NAMESPACE__.'\\'.$name)||\function_exists($name);
}
function setting(string $key,mixed $fallback=''): mixed {return $GLOBALS['settings'][$key]??$fallback;}
function save_setting(string $key,string $value): void {$GLOBALS['settings'][$key]=$value;}
function agent_brain_soul(array $user): array {return [];}
function agent_brain_default_soul(): array {return [];}
function agent_brain_tool_prompt(array $user): string {return '';}
function connection_aborted(): int {return 0;}
function curl_init(string $endpoint): object {return (object)['endpoint'=>$endpoint,'options'=>[]];}
function curl_setopt_array(object $handle,array $options): bool {$handle->options=$options;return true;}
function curl_setopt(object $handle,int $key,mixed $value): bool {$handle->options[$key]=$value;return true;}
function curl_getinfo(object $handle,int $key): int {return $handle->status??$GLOBALS['replyStatus'];}
function curl_error(object $handle): string {return '';}
function curl_close(object $handle): void {}
function curl_exec(object $handle): string|bool {
    $payload=json_decode($handle->options[CURLOPT_POSTFIELDS],true);
    $GLOBALS['requests'][]=['endpoint'=>$handle->endpoint,'headers'=>$handle->options[CURLOPT_HTTPHEADER],'payload'=>$payload];
    $provider=str_contains($handle->endpoint,'openai.com')?'openai':'anthropic';
    $handle->status=$GLOBALS['providerStatuses'][$provider]??(!empty($payload['stream'])&&$GLOBALS['streamReplyStatus']!==null?$GLOBALS['streamReplyStatus']:$GLOBALS['replyStatus']);
    if($handle->status!==200)return json_encode(['error'=>['message'=>'authentication failed']]);
    $reply='Hello from the configured provider.';
    if(!$GLOBALS['wrongTestReply']&&preg_match('/VP3 connection test [a-f0-9]{16}/',json_encode($payload),$match))$reply=$match[0];
    $openai=$provider==='openai';
    if(empty($payload['stream']))return json_encode($openai
        ?['output'=>[['content'=>[['type'=>'output_text','text'=>$reply]]]],'usage'=>['input_tokens'=>10,'output_tokens'=>7]]
        :['content'=>[['type'=>'text','text'=>$reply]],'usage'=>['input_tokens'=>10,'output_tokens'=>7]]);
    $events=$openai
        ?[['type'=>'response.output_text.delta','delta'=>$reply],['type'=>'response.completed','response'=>['usage'=>['input_tokens'=>10,'output_tokens'=>7]]]]
        :[['type'=>'message_start','message'=>['usage'=>['input_tokens'=>10]]],['type'=>'content_block_delta','delta'=>['type'=>'text_delta','text'=>$reply]],['type'=>'message_delta','usage'=>['output_tokens'=>7]],['type'=>'message_stop']];
    foreach($events as $event)($handle->options[CURLOPT_WRITEFUNCTION])($handle,'data: '.json_encode($event)."\n\n");
    return true;
}
foreach(['ai-settings.php','ai-runtime-v100.php','ai-stream-v121.php','recommendations.php','agent-brain-context-v99.php','chat-engine.php',
    'ai-service-policy-v1.php','ai-service-usage-v1.php','ai-usage-accounting-v032.php','ai-service-own-key-v1.php'] as $file){
    $source=file_get_contents(dirname(__DIR__).'/includes/'.$file);
    $source=preg_replace('/^require_once __DIR__[^\n]*\n/m','',$source);
    eval('namespace '.__NAMESPACE__.'; use \\Throwable; use \\RuntimeException; use \\PDO; use \\PDOException; use \\JsonException; use \\UnexpectedValueException; use \\InvalidArgumentException;'.substr($source,strpos($source,'declare(strict_types=1);')+strlen('declare(strict_types=1);')));
}
try {
    foreach(['openai'=>'gpt-5.4-mini','anthropic'=>'claude-haiku-4-5'] as $provider=>$model){
        $settings=[];$requests=[];$own=['route'=>'system'];
        $key='fixture-'.$provider.'-not-a-real-provider-key';
        $cipher=ai_encrypt_secret($key);
        $settings=['ai_test_requests_per_minute'=>'120','ai_chat_requests_per_minute'=>'120','ai_active_provider'=>$provider,'ai_'.$provider.'_enabled'=>'1','ai_'.$provider.'_model'=>$model,'ai_'.$provider.'_api_key'=>$cipher,
            // An enabled second provider with a saved, unreadable key must not
            // replace the known working connection during upgrade.
            'ai_'.($provider==='openai'?'anthropic':'openai').'_enabled'=>'1',
            'ai_'.($provider==='openai'?'anthropic':'openai').'_api_key'=>'sbx1:invalid'];
        $keyFile=file_get_contents(ai_master_key_file());
        $before=$settings;
        ai_haiku55_upgrade_defaults();
        check(ai_active_provider()===$provider,'Upgrade preserves selected provider');
        check(ai_provider_model($provider)===$model,'Upgrade preserves selected model');
        check(ai_provider_api_key($provider)===$key,'Existing encrypted credential remains readable');
        check(file_get_contents(ai_master_key_file())===$keyFile,'Installation master key unchanged');
        check($settings['ai_'.$provider.'_api_key']===$cipher,'Credential ciphertext unchanged');
        check(ai_provider_configuration_failure($provider)===null,'Configured provider is ready');
        $user=['id'=>7];
        $history=[['role'=>'assistant','message'=>'The server cannot read its saved AI credential. The site administrator needs to restore credential access in AI / API Settings.'],['role'=>'assistant','message'=>"The server can't read its saved AI credential, so I can only send you this fixed message."],['role'=>'assistant','message'=>'Ordinary earlier reply.']];
        $result=ai_generate_chat_response("Hey what's up",$history,[],$user);
        check(!empty($result['ok'])&&$result['provider']===$provider&&$result['model']===$model,'Nonstream greeting uses existing provider and model');
        $text='';$stream=ai_v121_stream_chat_response("Hey what's up",$history,[],$user,static function(string $delta)use(&$text):void{$text.=$delta;});
        check(!empty($stream['ok'])&&$stream['provider']===$provider&&$stream['model']===$model,'Stream greeting uses existing provider and model');
        check($text==='Hello from the configured provider.','Actual SSE parser delivers the answer');
        check(count($requests)===2,'One nonstream and one stream request; no silent provider fallback');
        foreach($requests as $request){
            check($request['payload']['model']===$model,'HTTP request retains model');
            check(in_array(($provider==='openai'?'Authorization: Bearer ':'x-api-key: ').$key,$request['headers'],true),'HTTP request uses original decrypted credential');
            check(str_contains(json_encode($request['payload']),"Hey what's up"),'Latest user question is present in the request');
            $sentHistory=$request['payload'][$provider==='openai'?'input':'messages'];
            check(!str_contains(json_encode($sentHistory),'saved AI credential'),'Actual provider request excludes historical credential-failure notices');
            check(str_contains(json_encode($sentHistory),'Ordinary earlier reply.'),'Actual provider request retains normal conversation history');
        }
        $test=ai_test_provider($provider);
        check(!empty($test['ok'])&&$test['model']===$model,'Admin connection test uses the saved provider and model');
        check(in_array(($provider==='openai'?'Authorization: Bearer ':'x-api-key: ').$key,end($requests)['headers'],true),'Admin connection test sends the saved decrypted key');
        $requests=[];$connection=ai_test_chat_connection($user);
        check(!empty($connection['ok'])&&!empty($connection['text_verified'])&&!empty($connection['stream_verified']),'Cloud Chat test verifies fresh text and voice-stream replies');
        check($connection['provider']===$provider&&$connection['credential_source']==='installation','Cloud Chat test reports the actual system provider and key source');
        check(count($requests)===2,'Cloud Chat test uses one normal text request and one normal voice request');
        $challenges=[];
        foreach($requests as $request){
            check(in_array(($provider==='openai'?'Authorization: Bearer ':'x-api-key: ').$key,$request['headers'],true),'Cloud Chat test uses the same saved key as ordinary Chat');
            check($request['payload']['model']===$model,'Cloud Chat test keeps the selected model');
            preg_match('/VP3 connection test [a-f0-9]{16}/',json_encode($request['payload']),$nonce);$challenges[]=$nonce[0]??'';
        }
        check($challenges[0]!==''&&$challenges[0]!==$challenges[1],'Both response paths must answer distinct fresh challenges');
        check(!str_contains(json_encode($connection),$key),'Test results never expose provider keys');
        $wrongTestReply=true;$badReply=ai_test_chat_connection($user);$wrongTestReply=false;
        check(empty($badReply['ok'])&&!empty($badReply['connected'])&&$badReply['failure_code']==='unexpected_test_reply','A generated reply with the wrong test phrase is distinguished from a connection failure');
        $streamReplyStatus=401;$badStream=ai_test_chat_connection($user);$streamReplyStatus=null;
        check(empty($badStream['ok'])&&!empty($badStream['connected'])&&$badStream['stage']==='voice stream'&&$badStream['status']===401,'A working text connection cannot hide a failed voice-stream authentication');
        $own=['route'=>'own_key','provider'=>$provider,'model'=>$model,'api_key'=>'fixture-personal-key'];$requests=[];
        $personal=ai_test_chat_connection($user);
        check(!empty($personal['ok'])&&$personal['credential_source']==='account','Cloud Chat test follows the account provider selection');
        foreach($requests as $request)check(in_array(($provider==='openai'?'Authorization: Bearer ':'x-api-key: ').'fixture-personal-key',$request['headers'],true),'Personal-key Chat test cannot silently test the system key');
        $own=['route'=>'system'];
        check(file_get_contents(ai_master_key_file())===$keyFile&&$settings['ai_'.$provider.'_api_key']===$cipher,'Tests preserve the saved key and encryption file');
        $requests=[];$replyStatus=401;
        $failed=ai_generate_chat_response('Hello',[],[],$user);
        check(empty($failed['ok'])&&$failed['status']===401,'Provider authentication rejection is preserved');
        check(str_contains(chat_local_answer('Hello',[],$failed),'rejected this request'),'Authentication rejection has its own safe notice');
        check((ai_test_provider($provider)['status']??0)===401,'Admin connection test preserves provider authentication rejection');
        $connection=ai_test_chat_connection($user);
        check(empty($connection['ok'])&&empty($connection['connected'])&&$connection['status']===401,'Cloud Chat test reports an actual provider rejection without a generated fallback');
        $replyStatus=200;
        $settings['ai_'.$provider.'_enabled']='0';
        $requests=[];
        $failed=ai_generate_chat_response('Hello',[],[],$user);
        check(($failed['failure_code']??'')==='provider_disabled'&&$requests===[],'Disabled provider is identified before HTTP');
        check(str_contains(chat_local_answer('Hello',[],$failed),'disabled on the server'),'Disabled notice is distinct from connection failure');
        check((ai_test_provider($provider)['failure_code']??'')==='provider_disabled','Admin connection test identifies a disabled provider');
        $settings['ai_'.$provider.'_enabled']='1';
        $settings['ai_'.$provider.'_api_key']='';
        check(ai_provider_configuration_failure($provider)['failure_code']==='credential_empty','Missing saved credential is distinct');
        $settings['ai_'.$provider.'_api_key']='unknown:fixture';
        check(ai_provider_configuration_failure($provider)['failure_code']==='credential_unsupported_format','Unreadable credential format is distinct');
        $settings['ai_'.$provider.'_api_key']=$cipher;
        unlink(ai_master_key_file());
        check(ai_provider_configuration_failure($provider)['failure_code']==='credential_missing_key','Missing installation key is distinct');
        check((ai_test_provider($provider)['failure_code']??'')==='credential_missing_key','Admin connection test identifies the missing installation key before HTTP');
        file_put_contents(ai_master_key_file(),$keyFile);
        $settings['ai_'.$provider.'_api_key']=$cipher;
        $settings['ai_active_provider']='local';unset($settings['ai_haiku55_defaults_applied']);
        ai_haiku55_upgrade_defaults();
        check(ai_active_provider()==='local','Explicit local mode also remains selected');
    }
    $pdo=db();
    $pdo->exec("CREATE TABLE users(id INTEGER PRIMARY KEY);
        INSERT INTO users VALUES (7);
        CREATE TABLE user_llm_credentials(user_id INT,provider TEXT,encrypted_key TEXT,PRIMARY KEY(user_id,provider));
        CREATE TABLE ai_execution_ledger(id INTEGER PRIMARY KEY,user_id INT,agent_id INT,conversation_id INT,
            trace_id TEXT,source TEXT,provider TEXT,model TEXT,run_id INT,created_at TEXT,cost_rate_source TEXT,
            input_tokens INT,output_tokens INT,total_tokens INT,latency_ms INT,estimated_cost_micros INT,
            cloud_ledger_id INT,cloud_tokens_charged INT,failure_class TEXT)");
    $settings['ai_openai_enabled']=$settings['ai_anthropic_enabled']='1';
    $settings['ai_openai_api_key']=ai_encrypt_secret('fixture-routed-openai');
    $settings['ai_anthropic_api_key']=ai_encrypt_secret('fixture-routed-claude');
    $settings['ai_active_provider']='local';
    $primary=['provider'=>'openai','model'=>'gpt-5.4-mini','funding'=>'system'];
    $fallback=['provider'=>'anthropic','model'=>'claude-haiku-4-5','funding'=>'system'];
    vp3_ai_service_save_route_v1($pdo,'conversation',$primary,$fallback);
    $context=['service'=>'conversation','agent_id'=>11,'conversation_id'=>18,'job_key'=>'chat:27'];
    $query='Deep dive and compare the alternatives.';
    $typed=static fn()=>ai_v100_with_service_context($context,static fn()=>ai_generate_chat_response($query,[],[],$user));
    $voice=static fn()=>ai_v121_stream_chat_response($query,[],[],$user,static fn(string $delta)=>null,$context);
    $requests=[];
    check(($typed()['failure_code']??'')==='service_usage_unavailable'&&$requests===[],'An explicit route waits for the meter upgrade before dispatch');
    vp3_ai_service_usage_ensure_schema_v1($pdo);
    $typedResult=$typed();$voiceResult=$voice();
    check(!empty($typedResult['ok'])&&!empty($voiceResult['ok'])&&count($requests)===2,'Configured text and SSE routes use actual hosted inference');
    check(array_column(array_column($requests,'payload'),'model')===['gpt-5.4-mini','gpt-5.4-mini'],'A routed model is pinned even for a deep request and a different legacy provider');
    $rows=$pdo->query('SELECT id,agent_id,conversation_id,service_key,service_stage,total_tokens,usage_known FROM ai_execution_ledger ORDER BY id')->fetchAll(\PDO::FETCH_ASSOC);
    check(array_column($rows,'service_stage')===['text','stream']&&array_column($rows,'total_tokens')===[17,17]&&array_column($rows,'usage_known')===[1,1]
        &&array_column($rows,'agent_id')===[11,11]&&array_column($rows,'conversation_id')===[18,18]
        &&[$typedResult['service_attempt_ids'][0],$voiceResult['service_attempt_ids'][0]]===array_column($rows,'id'),
        'Each adapter meters the actual provider attempt once and returns the exact ledger row');

    $requests=[];$providerStatuses=['openai'=>401];
    $fallbackResult=$typed();
    check(!empty($fallbackResult['ok'])&&$fallbackResult['provider']==='anthropic'&&count($fallbackResult['service_attempt_ids'])===2,
        'A rejected primary can use the configured fallback with two distinct attempts');
    check(array_column(array_column($requests,'payload'),'model')===['gpt-5.4-mini','claude-haiku-4-5'],'Fallback pins its own configured model');
    $requests=[];$providerStatuses=['openai'=>503];
    check(($typed()['failure_code']??'')==='provider_attempt_unconfirmed'&&count($requests)===1,'An unconfirmed paid attempt does not start a second provider request');
    $providerStatuses=[];$requests=[];
    vp3_ai_service_save_rule_v1($pdo,'user',7,'conversation',['daily_tokens'=>1]);
    check(!empty($typed()['quota_exhausted'])&&!empty($voice()['quota_exhausted'])&&$requests===[],'Conversation cap applies before both typed and streaming dispatch');

    vp3_ai_service_save_rule_v1($pdo,'user',7,'conversation',['daily_tokens'=>30_000]);
    $image='data:image/png;base64,'.str_repeat('A',300_000);
    $vision=ai_v100_with_service_context($context,static fn()=>ai_generate_chat_response($query,[],[],$user,null,null,[$image]));
    check(!empty($vision['ok'])&&count($requests)===1&&end($requests)['payload']['input'][0]['content'][1]['image_url']===$image,
        'Metering estimates inline vision separately and preserves the image sent to the provider');
    $requests=[];
    $personal=['provider'=>'openai','model'=>'gpt-5.4-mini','funding'=>'own_key'];
    $initiative=static fn()=>ai_v100_with_service_context(['service'=>'initiative','background'=>true,'run_id'=>31,'agent_id'=>11,'conversation_id'=>18],
        static fn()=>ai_generate_chat_response($query,[],[],$user,'main_ai'));
    $own=['route'=>'own_key','provider'=>'openai','model'=>'gpt-5.4-mini','api_key'=>'fixture-legacy-initiative'];
    check(!empty($initiative()['ok'])&&count($requests)===1&&in_array('Authorization: Bearer fixture-legacy-initiative',$requests[0]['headers'],true),
        'Unconfigured Initiative retains legacy personal routing after service metering is enabled');
    $own=['route'=>'system'];$requests=[];
    vp3_ai_service_save_route_v1($pdo,'initiative',$personal,null);
    $pdo->prepare("INSERT INTO user_llm_credentials VALUES (7,'openai',?)")->execute([ai_encrypt_secret('fixture-initiative-personal')]);
    check(($initiative()['failure_code']??'')==='personal_service_grant_required'&&$requests===[],'A saved personal key requires explicit Initiative approval before dispatch');
    vp3_ai_service_own_key_update_v1($pdo,$user,'initiative','openai','gpt-5.4-mini',true);
    $initiativeResult=$initiative();
    check(!empty($initiativeResult['ok'])&&$initiativeResult['compute_source']==='user_provider'&&count($requests)===1
        &&in_array('Authorization: Bearer fixture-initiative-personal',$requests[0]['headers'],true),
        'Approved Initiative work uses the exact personal key independently of the account Conversation choice');
    $row=$pdo->query('SELECT service_key,credential_source,is_background,run_id FROM ai_execution_ledger ORDER BY id DESC LIMIT 1')->fetch(\PDO::FETCH_ASSOC);
    check($row===['service_key'=>'initiative','credential_source'=>'own_key','is_background'=>1,'run_id'=>31],'Initiative metering carries the worker run and background identity');
    vp3_ai_service_own_key_update_v1($pdo,$user,'initiative','openai','gpt-5.4-mini',false);
    $requests=[];
    check(($initiative()['failure_code']??'')==='personal_service_grant_required'&&$requests===[],'Revocation blocks later paid Initiative work');
    echo 'CLOUD_PROVIDER_PRESERVATION=PASS ('.$checks." assertions; HTTP mocked)\n";
} finally {
    $paths=is_dir($root)?new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root,\FilesystemIterator::SKIP_DOTS),\RecursiveIteratorIterator::CHILD_FIRST):[];
    foreach($paths as $path){if($path->isDir())rmdir($path->getPathname());else unlink($path->getPathname());}
    if(is_dir($root))rmdir($root);
}
