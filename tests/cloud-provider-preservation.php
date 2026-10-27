<?php
declare(strict_types=1);

namespace ProviderRegression;

// Load production functions in a namespace solely to replace outbound HTTP.
// Credential encryption, configuration, request construction and SSE parsing
// are the actual application code; no real provider call is made.
$root=sys_get_temp_dir().'/vp3-provider-'.bin2hex(random_bytes(6));
\define('STONEFELLOW_ROOT',$root);
$settings=[];$requests=[];$replyStatus=200;$checks=0;$own=['route'=>'system'];$wrongTestReply=false;$streamReplyStatus=null;
function db(): \PDO {
    static $pdo;
    return $pdo??=new class('sqlite::memory:') extends \PDO {
        public function query(string $query,?int $mode=null,mixed ...$args): \PDOStatement|false {
            if($query==="SHOW TABLES LIKE 'user_llm_credentials'")$query='SELECT 1 WHERE 0';
            return $mode===null?parent::query($query):parent::query($query,$mode,...$args);
        }
    };
}
function vp3_user_llm_v1_effective(\PDO $pdo,array $user): array {return $GLOBALS['own'];}
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
    $handle->status=!empty($payload['stream'])&&$GLOBALS['streamReplyStatus']!==null?$GLOBALS['streamReplyStatus']:$GLOBALS['replyStatus'];
    if($handle->status!==200)return json_encode(['error'=>['message'=>'authentication failed']]);
    $reply='Hello from the configured provider.';
    if(!$GLOBALS['wrongTestReply']&&preg_match('/VP3 connection test [a-f0-9]{16}/',json_encode($payload),$match))$reply=$match[0];
    $openai=str_contains($handle->endpoint,'openai.com');
    if(empty($payload['stream']))return json_encode($openai
        ?['output'=>[['content'=>[['type'=>'output_text','text'=>$reply]]]],'usage'=>['input_tokens'=>10,'output_tokens'=>7]]
        :['content'=>[['type'=>'text','text'=>$reply]],'usage'=>['input_tokens'=>10,'output_tokens'=>7]]);
    $events=$openai
        ?[['type'=>'response.output_text.delta','delta'=>$reply],['type'=>'response.completed','response'=>['usage'=>['input_tokens'=>10,'output_tokens'=>7]]]]
        :[['type'=>'message_start','message'=>['usage'=>['input_tokens'=>10]]],['type'=>'content_block_delta','delta'=>['type'=>'text_delta','text'=>$reply]],['type'=>'message_delta','usage'=>['output_tokens'=>7]],['type'=>'message_stop']];
    foreach($events as $event)($handle->options[CURLOPT_WRITEFUNCTION])($handle,'data: '.json_encode($event)."\n\n");
    return true;
}
foreach(['ai-settings.php','ai-runtime-v100.php','ai-stream-v121.php','recommendations.php','agent-brain-context-v99.php','chat-engine.php'] as $file){
    $source=file_get_contents(dirname(__DIR__).'/includes/'.$file);
    eval('namespace '.__NAMESPACE__.'; use \\Throwable; use \\RuntimeException;'.substr($source,strpos($source,'declare(strict_types=1);')+strlen('declare(strict_types=1);')));
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
    echo 'CLOUD_PROVIDER_PRESERVATION=PASS ('.$checks." assertions; HTTP mocked)\n";
} finally {
    $paths=new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root,\FilesystemIterator::SKIP_DOTS),\RecursiveIteratorIterator::CHILD_FIRST);
    foreach($paths as $path){if($path->isDir())rmdir($path->getPathname());else unlink($path->getPathname());}
    if(is_dir($root))rmdir($root);
}
