<?php
declare(strict_types=1);

namespace ProfileInferenceRegression;

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
function agent_brain_soul(array $user): array {throw new \RuntimeException('Public inference read private owner Brain.');}
function agent_brain_default_soul(): array {return [];}
function agent_brain_tool_prompt(array $user): string {throw new \RuntimeException('Public inference read private tool catalog.');}
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
$quotaClosed=false;$preflights=[];$commits=[];$released=[];
function subscription_has_entitlement(array $user,string $cap):bool{return (int)$user['id']===7;}
function subscription_ai_preflight(array $user,string $scope,int $input,int $output):array{
    $GLOBALS['preflights'][]=['user_id'=>(int)$user['id'],'scope'=>$scope];
    if($GLOBALS['quotaClosed'])throw new \RuntimeException('AI token balance exhausted');
    return ['reservation_id'=>63,'max_output_tokens'=>200];
}
function subscription_ai_commit_usage(int $reservation,array $user,string $scope,string $provider,string $model,array $usage,string $key=''):void{$GLOBALS['commits'][]=compact('reservation','user','scope','provider','model','usage');}
function subscription_ai_release_reservation(int $id):void{$GLOBALS['released'][]=$id;}
foreach(['ai-settings.php','ai-runtime-v100.php','ai-stream-v121.php','recommendations.php','agent-brain-context-v99.php','chat-engine.php'] as $file){
    $source=file_get_contents(dirname(__DIR__).'/includes/'.$file);
    eval('namespace '.__NAMESPACE__.'; use \\Throwable; use \\RuntimeException;'.substr($source,strpos($source,'declare(strict_types=1);')+strlen('declare(strict_types=1);')));
}
try{
    foreach(['openai'=>'gpt-5.4-mini','anthropic'=>'claude-haiku-4-5'] as $provider=>$model){
        $requests=[];$settings=['ai_chat_requests_per_minute'=>'120','ai_active_provider'=>$provider,'ai_'.$provider.'_enabled'=>'1','ai_'.$provider.'_model'=>$model];
        $key='fixture-profile-not-a-real-key';$settings['ai_'.$provider.'_api_key']=ai_encrypt_secret($key);
        $control=['surface'=>'profile_agent','profile_owner_user_id'=>7,'profile_agent_id'=>9,'profile_agent_name'=>'Public Guide','profile_owner_name'=>'Profile Owner','profile_instructions'=>'Be welcoming.'];
        $context=[['source'=>'profile:identity','title'=>'Profile Owner','text'=>'Public biography'],['source'=>'knowledge:1','title'=>'Published hours','text'=>'Open on Mondays']];
        $_SERVER['REQUEST_URI']='/api/profile-webmcp-external-v120.php';
        $result=ai_generate_chat_response('Can we chat?',[],$context,['id'=>7],'profile_agent',$control);
        check(!empty($result['ok']),'Actual provider builder returns a model answer');
        check(end($preflights)===['user_id'=>7,'scope'=>'profile_agent'],'Connected-site call reserves against profile OWNER and Profile Agent scope');
        check(end($commits)['user']['id']===7&&end($commits)['scope']==='profile_agent','Usage is committed to the owner and explicit scope');
        $request=end($requests);$payload=$request['payload'];
        check(in_array(($provider==='openai'?'Authorization: Bearer ':'x-api-key: ').$key,$request['headers'],true),'Profile inference uses the existing configured credential');
        check($payload['model']===$model,'Profile inference preserves the selected model');
        check(($payload[$provider==='openai'?'max_output_tokens':'max_tokens']??0)===200,'Provider output cap honors the remaining account allowance');
        $prompt=$payload[$provider==='openai'?'instructions':'system'];
        check(str_contains($prompt,'Public Guide')&&str_contains($prompt,'Profile Owner'),'Provider prompt identifies the correct public representative');
        check(str_contains($prompt,'approved public context')&&!str_contains($prompt,'SERVER-AUTHORIZED TOOL CATALOG'),'Public model receives public boundaries without a private tool catalog');
        check(str_contains(json_encode($payload),'Open on Mondays'),'Owner-approved public knowledge reaches the actual request');
        $before=count($requests);$quotaClosed=true;
        $blocked=ai_generate_chat_response('More questions',[],$context,['id'=>7],'profile_agent',$control);
        check(!empty($blocked['quota_exhausted'])&&count($requests)===$before,'Exhausted owner quota blocks HTTP before any provider call');$quotaClosed=false;
        $replyStatus=401;
        $failed=ai_generate_chat_response('Try',[],$context,['id'=>7],'profile_agent',$control);
        check(empty($failed['ok'])&&end($released)===63,'Failed provider request releases reserved allowance');$replyStatus=200;
        $own=['route'=>'own_key','provider'=>$provider,'model'=>$model,'api_key'=>'fixture-owner-byok'];
        $personal=ai_generate_chat_response('Hello',[],$context,['id'=>7],'profile_agent',$control);
        check(!empty($personal['ok'])&&empty($personal['system_tokens_charged']),'Owner BYOK retains existing system-token billing rules');
        check(in_array(($provider==='openai'?'Authorization: Bearer ':'x-api-key: ').'fixture-owner-byok',end($requests)['headers'],true),'Profile BYOK cannot silently use the visitor or installation key');
        $own=['route'=>'system'];
    }
    echo 'PROFILE_INFERENCE_V1341='.$checks." assertions PASS (provider HTTP mocked)\n";
}finally{
    $paths=new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root,\FilesystemIterator::SKIP_DOTS),\RecursiveIteratorIterator::CHILD_FIRST);
    foreach($paths as $path){if($path->isDir())rmdir($path->getPathname());else unlink($path->getPathname());}if(is_dir($root))rmdir($root);
}
