<?php
declare(strict_types=1);

/** Stonefellow v121/v125 — provider streaming for live voice conversation. */

function ai_v121_stream_provider(
    string $provider,
    string $model,
    array $payload,
    callable $onDelta,
    int $timeout = 75
): array {
    if (!function_exists('curl_init')) return ['ok'=>false,'answer'=>'','error'=>'AI transport is unavailable.'];

    $apiKey=ai_provider_api_key($provider);
    if ($apiKey==='') return ['ok'=>false,'answer'=>'','error'=>'AI provider credentials are unavailable.'];

    if ($provider==='openai') {
        $endpoint='https://api.openai.com/v1/responses';
        $headers=['Authorization: Bearer '.$apiKey,'Content-Type: application/json','Accept: text/event-stream','Accept-Encoding: identity'];
    } elseif ($provider==='anthropic') {
        $endpoint='https://api.anthropic.com/v1/messages';
        $headers=['x-api-key: '.$apiKey,'anthropic-version: 2023-06-01','Content-Type: application/json','Accept: text/event-stream','Accept-Encoding: identity'];
    } else {
        return ['ok'=>false,'answer'=>'','error'=>'Streaming is unavailable for this provider.'];
    }

    $payload['stream']=true;
    $encoded=json_encode($payload,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
    if(!is_string($encoded))return ['ok'=>false,'answer'=>'','error'=>'Could not encode the AI request.'];

    $answer='';$lineBuffer='';$eventData=[];$usage=['input_tokens'=>0,'output_tokens'=>0,'total_tokens'=>0];
    $providerError='';$sawTerminal=false;

    $processEvent=static function() use ($provider,$onDelta,&$answer,&$eventData,&$usage,&$providerError,&$sawTerminal): void {
        if(!$eventData)return;
        $raw=implode("\n",$eventData);$eventData=[];
        if($raw===''||$raw==='[DONE]')return;
        $data=json_decode($raw,true);if(!is_array($data))return;
        $type=(string)($data['type']??'');$delta='';

        if($provider==='openai'){
            if($type==='response.output_text.delta'&&is_string($data['delta']??null))$delta=(string)$data['delta'];
            if($type==='response.completed'){
                $sawTerminal=true;$u=is_array($data['response']['usage']??null)?$data['response']['usage']:[];
                $usage['input_tokens']=(int)($u['input_tokens']??0);$usage['output_tokens']=(int)($u['output_tokens']??0);
            }
            if($type==='error')$providerError=(string)($data['error']['message']??$data['message']??'OpenAI streaming error.');
        }else{
            if($type==='content_block_delta'&&($data['delta']['type']??'')==='text_delta'&&is_string($data['delta']['text']??null))$delta=(string)$data['delta']['text'];
            if($type==='message_start'){
                $u=is_array($data['message']['usage']??null)?$data['message']['usage']:[];$usage['input_tokens']=(int)($u['input_tokens']??0);
            }
            if($type==='message_delta'){
                $u=is_array($data['usage']??null)?$data['usage']:[];$usage['output_tokens']=(int)($u['output_tokens']??$usage['output_tokens']);
            }
            if($type==='message_stop')$sawTerminal=true;
            if($type==='error')$providerError=(string)($data['error']['message']??$data['message']??'Anthropic streaming error.');
        }

        if($delta!==''){
            $answer.=$delta;
            $onDelta($delta);
        }
    };

    $curl=curl_init($endpoint);$started=microtime(true);
    curl_setopt_array($curl,[
        CURLOPT_POST=>true,CURLOPT_RETURNTRANSFER=>false,CURLOPT_CONNECTTIMEOUT=>6,
        CURLOPT_TIMEOUT=>max(10,min(120,$timeout)),CURLOPT_HTTPHEADER=>$headers,
        CURLOPT_POSTFIELDS=>$encoded,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,
        CURLOPT_BUFFERSIZE=>4096,
        CURLOPT_WRITEFUNCTION=>static function($curl,string $chunk) use (&$lineBuffer,&$eventData,$processEvent): int {
            if(connection_aborted())return 0;
            $lineBuffer.=$chunk;
            while(($pos=strpos($lineBuffer,"\n"))!==false){
                $line=rtrim(substr($lineBuffer,0,$pos),"\r");$lineBuffer=substr($lineBuffer,$pos+1);
                if($line===''){$processEvent();continue;}
                if(str_starts_with($line,'data:'))$eventData[]=ltrim(substr($line,5));
            }
            return strlen($chunk);
        }
    ]);
    if(defined('CURLOPT_PROTOCOLS')&&defined('CURLPROTO_HTTPS'))curl_setopt($curl,CURLOPT_PROTOCOLS,CURLPROTO_HTTPS);
    if(defined('CURLOPT_HTTP_VERSION')&&defined('CURL_HTTP_VERSION_2TLS'))curl_setopt($curl,CURLOPT_HTTP_VERSION,CURL_HTTP_VERSION_2TLS);
    if(defined('CURLOPT_TCP_KEEPALIVE'))curl_setopt($curl,CURLOPT_TCP_KEEPALIVE,1);
    if(defined('CURLOPT_TCP_NODELAY'))curl_setopt($curl,CURLOPT_TCP_NODELAY,1);

    $result=curl_exec($curl);$status=(int)curl_getinfo($curl,CURLINFO_HTTP_CODE);$error=curl_error($curl);curl_close($curl);
    if(trim($lineBuffer)!==''){
        $line=rtrim($lineBuffer,"\r\n");if(str_starts_with($line,'data:'))$eventData[]=ltrim(substr($line,5));
    }
    $processEvent();
    $usage['total_tokens']=$usage['input_tokens']+$usage['output_tokens'];
    $duration=(int)round((microtime(true)-$started)*1000);

    if($result===false||$status<200||$status>=300||$providerError!==''){
        error_log('Stonefellow v121 stream '.$provider.' status='.$status.' error='.mb_strimwidth($providerError!==''?$providerError:$error,0,300,'…'));
        return ['ok'=>false,'answer'=>trim($answer),'partial'=>trim($answer)!=='','error'=>$providerError!==''?$providerError:'The AI streaming connection failed.','status'=>$status,'duration_ms'=>$duration,'usage'=>$usage,'terminal'=>$sawTerminal];
    }
    $answer=trim($answer);
    if($answer==='')return ['ok'=>false,'answer'=>'','error'=>'The AI provider returned an empty response.','status'=>$status,'duration_ms'=>$duration,'usage'=>$usage,'terminal'=>$sawTerminal];
    return ['ok'=>true,'answer'=>$answer,'status'=>$status,'duration_ms'=>$duration,'usage'=>$usage,'terminal'=>$sawTerminal];
}

function ai_v125_retryable_stream_result(mixed $result,?Throwable $error,int $attempt): bool
{
    if(connection_aborted())return false;
    if($error)return $attempt<2;
    if(!is_array($result)||!empty($result['ok'])||!empty($result['partial']))return false;
    $status=(int)($result['status']??0);
    return $attempt<2&&($status===0||in_array($status,[408,425,429],true)||$status>=500);
}

function ai_v121_stream_chat_response(
    string $query,
    array $history,
    array $context,
    array $user,
    callable $onDelta
): array {
    $provider=ai_active_provider();
    try{ai_v100_rate_limit('chat',$user);}catch(Throwable $e){return ['ok'=>false,'provider'=>$provider,'answer'=>'','error'=>ai_v100_safe_exception($e)];}
    if($provider==='local')return ['ok'=>false,'provider'=>'local','answer'=>'','error'=>'Local retrieval mode is active.'];
    if(!ai_provider_enabled($provider)||!ai_provider_ready($provider))return ['ok'=>false,'provider'=>$provider,'answer'=>'','error'=>'The AI provider is not fully configured.'];

    $complexity=ai_v100_complexity($query,$context);$budget=$complexity==='deep'?3200:($complexity==='complex'?2200:1400);
    $current=ai_v100_current_message($query,$context);$models=ai_v100_model_candidates($provider,$complexity);
    $last=['ok'=>false,'provider'=>$provider,'answer'=>'','error'=>'The AI provider did not return a usable response.'];

    foreach($models as $model){
        if($provider==='openai'){
            $input=ai_history_messages($history);$input[]=['role'=>'user','content'=>$current];
            $payload=['model'=>$model,'instructions'=>ai_system_prompt($context,$user),'input'=>$input,'max_output_tokens'=>$budget];
        }else{
            $messages=ai_history_messages($history);$messages[]=['role'=>'user','content'=>$current];
            $payload=['model'=>$model,'max_tokens'=>$budget,'system'=>ai_system_prompt($context,$user),'messages'=>$messages];
        }
        $service='ai-stream-'.$provider.'-'.$model;
        try{
            if(function_exists('agent_runtime_v125_resilient_call')){
                $result=agent_runtime_v125_resilient_call(
                    $service,
                    static function(int $attempt) use($provider,$model,$payload,$onDelta,$complexity): array {
                        $row=ai_v121_stream_provider($provider,$model,$payload,$onDelta,$complexity==='deep'?95:70);$row['attempts']=$attempt;return $row;
                    },
                    'ai_v125_retryable_stream_result',
                    2,
                    160
                );
            }else{
                $result=ai_v121_stream_provider($provider,$model,$payload,$onDelta,$complexity==='deep'?95:70);$result['attempts']=1;
            }
        }catch(Throwable $e){
            $result=['ok'=>false,'answer'=>'','error'=>ai_v100_safe_exception($e),'status'=>0,'duration_ms'=>0,'usage'=>[],'attempts'=>1,'error_class'=>get_class($e)];
        }
        if(!is_array($result))$result=['ok'=>false,'answer'=>'','error'=>'The AI provider did not return a usable response.','status'=>0,'duration_ms'=>0,'usage'=>[],'attempts'=>1];
        $usage=is_array($result['usage']??null)?$result['usage']:[];
        ai_v100_telemetry([
            'scope'=>'chat','user_id'=>(int)($user['id']??0),'provider'=>$provider,'model'=>$model,'service'=>$service,
            'status'=>!empty($result['ok'])?'success':(!empty($result['partial'])?'partial':'failed'),
            'http_status'=>(int)($result['status']??0),'duration_ms'=>(int)($result['duration_ms']??0),
            'input_chars'=>mb_strlen($current),'output_chars'=>mb_strlen((string)($result['answer']??'')),'complexity'=>$complexity,
            'attempts'=>(int)($result['attempts']??1),'error_class'=>(string)($result['error_class']??'')
        ]+$usage);
        $result['provider']=$provider;$result['model']=$model;$result['complexity']=$complexity;$result['trace_id']=function_exists('agent_runtime_v125_trace_id')?agent_runtime_v125_trace_id():'';
        if(!empty($result['ok'])||!empty($result['partial']))return $result;
        $last=$result;
    }
    return $last;
}