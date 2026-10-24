<?php
declare(strict_types=1);

namespace ProviderRegression;

// Load production functions in a namespace solely to replace outbound HTTP.
// Credential encryption, configuration, request construction and SSE parsing
// are the actual application code; no real provider call is made.
$root=sys_get_temp_dir().'/vp3-provider-'.bin2hex(random_bytes(6));
\define('STONEFELLOW_ROOT',$root);
$settings=[];$requests=[];$replyStatus=200;$checks=0;
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
function curl_getinfo(object $handle,int $key): int {return $GLOBALS['replyStatus'];}
function curl_error(object $handle): string {return '';}
function curl_close(object $handle): void {}
function curl_exec(object $handle): string|bool {
    $payload=json_decode($handle->options[CURLOPT_POSTFIELDS],true);
    $GLOBALS['requests'][]=['endpoint'=>$handle->endpoint,'headers'=>$handle->options[CURLOPT_HTTPHEADER],'payload'=>$payload];
    if($GLOBALS['replyStatus']!==200)return json_encode(['error'=>['message'=>'authentication failed']]);
    $openai=str_contains($handle->endpoint,'openai.com');
    if(empty($payload['stream']))return json_encode($openai
        ?['output'=>[['content'=>[['type'=>'output_text','text'=>'Hello from the configured provider.']]]],'usage'=>['input_tokens'=>10,'output_tokens'=>7]]
        :['content'=>[['type'=>'text','text'=>'Hello from the configured provider.']],'usage'=>['input_tokens'=>10,'output_tokens'=>7]]);
    $events=$openai
        ?[['type'=>'response.output_text.delta','delta'=>'Hello from the configured provider.'],['type'=>'response.completed','response'=>['usage'=>['input_tokens'=>10,'output_tokens'=>7]]]]
        :[['type'=>'message_start','message'=>['usage'=>['input_tokens'=>10]]],['type'=>'content_block_delta','delta'=>['type'=>'text_delta','text'=>'Hello from the configured provider.']],['type'=>'message_delta','usage'=>['output_tokens'=>7]],['type'=>'message_stop']];
    foreach($events as $event)($handle->options[CURLOPT_WRITEFUNCTION])($handle,'data: '.json_encode($event)."\n\n");
    return true;
}
foreach(['ai-settings.php','ai-runtime-v100.php','ai-stream-v121.php','recommendations.php','agent-brain-context-v99.php','chat-engine.php'] as $file){
    $source=file_get_contents(dirname(__DIR__).'/includes/'.$file);
    eval('namespace '.__NAMESPACE__.';'.substr($source,strpos($source,'declare(strict_types=1);')+strlen('declare(strict_types=1);')));
}
try {
    foreach(['openai'=>'gpt-5.4-mini','anthropic'=>'claude-haiku-4-5'] as $provider=>$model){
        $settings=[];$requests=[];
        $key='fixture-'.$provider.'-not-a-real-provider-key';
        $cipher=ai_encrypt_secret($key);
        $settings=['ai_active_provider'=>$provider,'ai_'.$provider.'_enabled'=>'1','ai_'.$provider.'_model'=>$model,'ai_'.$provider.'_api_key'=>$cipher,
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
        $result=ai_generate_chat_response("Hey what's up",[],[],$user);
        check(!empty($result['ok'])&&$result['provider']===$provider&&$result['model']===$model,'Nonstream greeting uses existing provider and model');
        $text='';$stream=ai_v121_stream_chat_response("Hey what's up",[],[],$user,static function(string $delta)use(&$text):void{$text.=$delta;});
        check(!empty($stream['ok'])&&$stream['provider']===$provider&&$stream['model']===$model,'Stream greeting uses existing provider and model');
        check($text==='Hello from the configured provider.','Actual SSE parser delivers the answer');
        check(count($requests)===2,'One nonstream and one stream request; no silent provider fallback');
        foreach($requests as $request){
            check($request['payload']['model']===$model,'HTTP request retains model');
            check(in_array(($provider==='openai'?'Authorization: Bearer ':'x-api-key: ').$key,$request['headers'],true),'HTTP request uses original decrypted credential');
            check(str_contains(json_encode($request['payload']),"Hey what's up"),'Latest user question is present in the request');
        }
        $requests=[];$replyStatus=401;
        $failed=ai_generate_chat_response('Hello',[],[],$user);
        check(empty($failed['ok'])&&$failed['status']===401,'Provider authentication rejection is preserved');
        check(str_contains(chat_local_answer('Hello',[],$failed),'rejected this request'),'Authentication rejection has its own safe notice');
        $replyStatus=200;
        $settings['ai_'.$provider.'_enabled']='0';
        $requests=[];
        $failed=ai_generate_chat_response('Hello',[],[],$user);
        check(($failed['failure_code']??'')==='provider_disabled'&&$requests===[],'Disabled provider is identified before HTTP');
        check(str_contains(chat_local_answer('Hello',[],$failed),'disabled on the server'),'Disabled notice is distinct from connection failure');
        $settings['ai_'.$provider.'_enabled']='1';
        $settings['ai_'.$provider.'_api_key']='';
        check(ai_provider_configuration_failure($provider)['failure_code']==='credential_empty','Missing saved credential is distinct');
        $settings['ai_'.$provider.'_api_key']='unknown:fixture';
        check(ai_provider_configuration_failure($provider)['failure_code']==='credential_unsupported_format','Unreadable credential format is distinct');
        $settings['ai_'.$provider.'_api_key']=$cipher;
        unlink(ai_master_key_file());
        check(ai_provider_configuration_failure($provider)['failure_code']==='credential_missing_key','Missing installation key is distinct');
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
