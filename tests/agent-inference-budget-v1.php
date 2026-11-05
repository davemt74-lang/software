<?php
declare(strict_types=1);
namespace InferenceBudgetRegression;

// Load unchanged production functions in a namespace so outbound HTTP and the
// monotonic clock are controlled. No provider request or wall-clock sleep runs.
$root=sys_get_temp_dir().'/vp3-inference-budget-'.bin2hex(random_bytes(6));
\define('STONEFELLOW_ROOT',$root);
$clock=1000.0;$requests=[];$wire=[];$settings=[];$telemetry=[];$checks=0;
foreach(['CURLOPT_POST','CURLOPT_RETURNTRANSFER','CURLOPT_CONNECTTIMEOUT','CURLOPT_TIMEOUT','CURLOPT_CONNECTTIMEOUT_MS',
    'CURLOPT_TIMEOUT_MS','CURLOPT_HTTPHEADER','CURLOPT_POSTFIELDS','CURLOPT_FOLLOWLOCATION','CURLOPT_SSL_VERIFYPEER',
    'CURLOPT_SSL_VERIFYHOST','CURLINFO_HTTP_CODE'] as $index=>$name){if(!\defined($name))\define($name,55000+$index);}
function function_exists(string $name):bool{return \function_exists(__NAMESPACE__.'\\'.$name)||\function_exists($name);}
function hrtime(bool $number=false):int|array{return $number?(int)round($GLOBALS['clock']*1e9):[(int)$GLOBALS['clock'],0];}
function microtime(bool $number=false):float|string{return $number?$GLOBALS['clock']:(string)$GLOBALS['clock'];}
function usleep(int $microseconds):void{$GLOBALS['clock']+=$microseconds/1e6;}
function random_int(int $minimum,int $maximum):int{return $minimum;}
function curl_init(string $endpoint):object{return (object)['endpoint'=>$endpoint,'options'=>[],'status'=>200,'error'=>''];}
function curl_setopt_array(object $handle,array $options):bool{$handle->options=$options;return true;}
function curl_setopt(object $handle,int $key,mixed $value):bool{$handle->options[$key]=$value;return true;}
function curl_getinfo(object $handle,int $key):int{return $handle->status;}
function curl_error(object $handle):string{return $handle->error;}
function curl_close(object $handle):void{}
function curl_exec(object $handle):string|false
{
    $GLOBALS['requests'][]=['endpoint'=>$handle->endpoint,'options'=>$handle->options];
    $response=array_shift($GLOBALS['wire'])??['status'=>200,'duration'=>0.1];
    $timeout=isset($handle->options[CURLOPT_TIMEOUT_MS])?$handle->options[CURLOPT_TIMEOUT_MS]/1000:$handle->options[CURLOPT_TIMEOUT];
    $duration=(float)($response['duration']??0.1);
    $GLOBALS['clock']+=min($duration,$timeout);
    if($duration>$timeout){$handle->status=0;$handle->error='Controlled timeout';return false;}
    $handle->status=(int)($response['status']??200);
    return $response['body']??json_encode($handle->status===200?['output_text'=>'Controlled provider reply']:['error'=>['message'=>'Controlled transient failure']]);
}
function setting(string $key,mixed $fallback=''):mixed{return $GLOBALS['settings'][$key]??$fallback;}
function save_setting(string $key,string $value):void{$GLOBALS['settings'][$key]=$value;}
function agent_brain_soul(array $user):array{return [];}
function agent_brain_default_soul():array{return [];}
function agent_brain_tool_prompt(array $user):string{return '';}
function ai_v100_complexity(string $query,array $context):string{return 'simple';}
function ai_v100_current_message(string $query,array $context):string{return $query;}
function ai_v100_model_candidates(string $provider,string $complexity):array{return ['first-model','second-model','third-model'];}
function ai_v100_telemetry(array $row):void{$GLOBALS['telemetry'][]=$row;}
function ai_v100_usage(string $provider,array $decoded):array{return [];}
function check_budget(bool $ok,string $message):void
{
    if(!$ok)throw new \RuntimeException($message);
    $GLOBALS['checks']++;echo 'PASS '.$message.PHP_EOL;
}
foreach(['agent-inference-budget-v1.php','ai-settings.php'] as $file){
    $source=file_get_contents(dirname(__DIR__).'/includes/'.$file);
    eval('namespace '.__NAMESPACE__.'; use \\Throwable; use \\RuntimeException; use \\InvalidArgumentException; use \\PDO;'
        .substr($source,strpos($source,'declare(strict_types=1);')+strlen('declare(strict_types=1);')));
}
$call=static fn()=>ai_curl_json('https://api.openai.com/v1/responses',[],['model'=>'fixture'],55);
try{
    check_budget(vp3_ai_budget_remaining_v1()===null,'ordinary requests have no implicit extra deadline');
    $wire=[['status'=>503,'duration'=>0.1],['status'=>503,'duration'=>0.1],['status'=>200,'duration'=>0.1]];
    $plain=$call();
    check_budget($plain['ok']&&count($requests)===3&&$requests[0]['options'][CURLOPT_TIMEOUT]===55
        &&$requests[0]['options'][CURLOPT_CONNECTTIMEOUT]===10&&!isset($requests[0]['options'][CURLOPT_TIMEOUT_MS]),
        'unbudgeted canonical transport retains its original timeout and retry behavior');

    $requests=[];$wire=[['status'=>200,'duration'=>60]];$started=$clock;
    $expired=vp3_ai_with_budget_v1(55,$call);
    check_budget(!$expired['ok']&&$expired['error_code']==='inference_budget_exhausted'&&count($requests)===1
        &&$clock-$started<=55.001&&$requests[0]['options'][CURLOPT_TIMEOUT_MS]<=55000
        &&$requests[0]['options'][CURLOPT_CONNECTTIMEOUT_MS]<=10000,
        'the actual provider attempt uses the remaining total budget and cannot start a late retry');
    check_budget(vp3_ai_budget_remaining_v1()===null,'completed inference restores the absence of a caller budget');

    $requests=[];$wire=[['status'=>503,'duration'=>0.9],['status'=>200,'duration'=>2]];$started=$clock;
    $retried=vp3_ai_with_budget_v1(2,$call);
    check_budget(!$retried['ok']&&$retried['error_code']==='inference_budget_exhausted'&&count($requests)===2
        &&$requests[1]['options'][CURLOPT_TIMEOUT_MS]<=900&&$clock-$started<=2.001,
        'retry backoff and the next HTTP attempt consume the same shrinking deadline');

    $requests=[];$wire=[];
    $expiredBefore=vp3_ai_with_budget_v1(1,static function()use($call):array{
        $GLOBALS['clock']+=1.01;return $call();
    });
    check_budget($expiredBefore['error_code']==='inference_budget_exhausted'&&$requests===[],
        'an already exhausted budget performs no outbound request');
    vp3_ai_with_budget_v1(5,static function():void{
        $before=$GLOBALS['VP3_AI_INFERENCE_BUDGET_V1'];
        try{vp3_ai_with_budget_v1(80,static function():void{
            check_budget(vp3_ai_budget_remaining_v1()<=5.001,'a nested call cannot extend its parent deadline');
            throw new \RuntimeException('Controlled exception');
        });}catch(\RuntimeException $e){}
        check_budget($GLOBALS['VP3_AI_INFERENCE_BUDGET_V1']===$before,'an exception restores the exact parent budget');
    });
    check_budget(!array_key_exists('VP3_AI_INFERENCE_BUDGET_V1',$GLOBALS),'the outer scope restores globals after a nested failure');
    vp3_ai_with_budget_v1(10000,static function():void{
        check_budget(vp3_ai_budget_remaining_v1()<=80.001,'trusted budgets still respect the 80 second runtime ceiling');
    });
    $GLOBALS['VP3_AI_INFERENCE_BUDGET_V1']=null;
    vp3_ai_with_budget_v1(1,static fn()=>true);
    check_budget(array_key_exists('VP3_AI_INFERENCE_BUDGET_V1',$GLOBALS)&&$GLOBALS['VP3_AI_INFERENCE_BUDGET_V1']===null,
        'scope restoration preserves an explicitly present prior null value');
    unset($GLOBALS['VP3_AI_INFERENCE_BUDGET_V1']);

    $settings['ai_openai_api_key']=ai_encrypt_secret('fixture-only-key');
    $settings['ai_anthropic_api_key']=ai_encrypt_secret('fixture-only-key');
    foreach(['openai','anthropic'] as $provider){
        $requests=[];$wire=[['status'=>200,'duration'=>1,'body'=>$provider==='openai'?'{"output_text":""}':'{"content":[]}']];
        $result=vp3_ai_with_budget_v1(1,static fn()=>($provider==='openai'?ai_openai_response('Controlled request',[],[]):ai_anthropic_response('Controlled request',[],[])));
        check_budget(!$result['ok']&&$result['error_code']==='inference_budget_exhausted'&&count($requests)===1,
            $provider.' model fallback stops once the shared deadline is exhausted');
    }
    $requests=[];$wire=[['status'=>200,'duration'=>0.1]];
    $phone=ai_curl_json('https://api.openai.com/v1/responses',[],['instructions'=>'PH3F_PHONE_VOICE. Controlled request'],55);
    check_budget($phone['ok']&&count($requests)===1&&$requests[0]['options'][CURLOPT_TIMEOUT]===10
        &&$requests[0]['options'][CURLOPT_CONNECTTIMEOUT]===4&&!isset($requests[0]['options'][CURLOPT_TIMEOUT_MS]),
        'phone requests without an Initiative budget retain their separate deadline');
    echo 'AGENT_INFERENCE_BUDGET_V1=PASS ('.$checks.' cases)'.PHP_EOL;
}finally{
    unset($GLOBALS['VP3_AI_INFERENCE_BUDGET_V1']);
    foreach(glob($root.'/private/*')?:[] as $file)@unlink($file);
    @rmdir($root.'/private');@rmdir($root);
}
