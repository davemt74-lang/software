<?php
declare(strict_types=1);

/** Bounded provider SSE accumulator. Only public answer text is retained as source. */
final class Vp3CodingCanvasStreamV1
{
    public string $text='';
    public string $messageId='';
    public string $stopReason='';
    public array $usage=[];
    public bool $terminal=false;
    public string $failure='';
    private string $buffer='';
    private array $data=[];
    private array $blocks=[];
    private int $bytes=0;
    private int $eventBytes=0;

    public function __construct(private readonly string $provider='anthropic') {}

    public function feed(string $chunk): bool
    {
        $this->bytes+=strlen($chunk);
        if($this->bytes>2097152){$this->failure='provider_response_too_large';return false;}
        $this->buffer.=$chunk;
        while(($end=strpos($this->buffer,"\n"))!==false){
            if($end>262144){$this->failure='provider_response_too_large';return false;}
            $line=rtrim(substr($this->buffer,0,$end),"\r");$this->buffer=substr($this->buffer,$end+1);
            if($line===''){$this->event();if($this->failure!=='')return false;}
            elseif(str_starts_with($line,'data:')){
                $this->eventBytes+=strlen($line);
                if($this->eventBytes>262144){$this->failure='provider_response_too_large';return false;}
                $this->data[]=ltrim(substr($line,5));
            }
        }
        if(strlen($this->buffer)>262144){$this->failure='provider_response_too_large';return false;}
        return $this->failure==='';
    }

    private function event(): void
    {
        if(!$this->data)return;
        $raw=implode("\n",$this->data);$this->data=[];$this->eventBytes=0;
        if($raw==='[DONE]')return;
        try{$event=json_decode($raw,true,24,JSON_THROW_ON_ERROR);}catch(JsonException){$this->failure='provider_response_invalid';return;}
        if(!is_array($event)){$this->failure='provider_response_invalid';return;}
        $type=$event['type']??'';
        if($type==='ping')return;
        if($this->terminal){$this->failure='provider_response_invalid';return;}
        if($this->provider==='openai'){$this->openai($event);return;}
        if($type==='message_start'){
            $id=$event['message']['id']??null;
            if($this->messageId!==''||!is_string($id)||!preg_match('/\A[a-zA-Z0-9_.:-]{1,160}\z/D',$id)){$this->failure='provider_response_invalid';return;}
            $this->messageId=$id;$this->usage=is_array($event['message']['usage']??null)?$event['message']['usage']:[];
        }elseif($type==='content_block_start'){
            $index=$event['index']??null;$block=$event['content_block']??null;
            if($this->messageId===''||!is_int($index)||$index<0||$index>63||!is_array($block)||isset($this->blocks[$index])){$this->failure='provider_response_invalid';return;}
            $this->blocks[$index]=['type'=>$block['type']??'','closed'=>false];
            if(($block['type']??'')==='text')$this->append(is_string($block['text']??null)?$block['text']:'');
        }elseif($type==='content_block_delta'){
            $index=$event['index']??null;$block=is_int($index)?($this->blocks[$index]??null):null;
            if(!$block||$block['closed']){$this->failure='provider_response_invalid';return;}
            if(($event['delta']['type']??'')==='text_delta'){
                if($block['type']!=='text'||!is_string($event['delta']['text']??null)){$this->failure='provider_response_invalid';return;}
                $this->append($event['delta']['text']);
            }
        }elseif($type==='content_block_stop'){
            $index=$event['index']??null;
            if(!is_int($index)||!isset($this->blocks[$index])||$this->blocks[$index]['closed']){$this->failure='provider_response_invalid';return;}
            $this->blocks[$index]['closed']=true;
        }elseif($type==='message_delta'){
            if($this->messageId===''){$this->failure='provider_response_invalid';return;}
            if(is_string($event['delta']['stop_reason']??null))$this->stopReason=$event['delta']['stop_reason'];
            if(is_array($event['usage']??null))$this->usage=array_replace($this->usage,$event['usage']);
        }elseif($type==='message_stop'){
            if($this->messageId===''||$this->stopReason===''||array_filter($this->blocks,static fn(array $b):bool=>!$b['closed'])){$this->failure='provider_response_invalid';return;}
            $this->terminal=true;
        }elseif($type==='error'){
            $this->failure=match($event['error']['type']??''){'overloaded_error'=>'provider_overloaded','rate_limit_error'=>'provider_rate_limited',default=>'provider_stream_error'};
        }
    }

    private function openai(array $event): void
    {
        $type=$event['type']??'';
        if($type==='response.created'){
            $id=$event['response']['id']??null;
            if($this->messageId!==''||!is_string($id)||!preg_match('/\A[a-zA-Z0-9_.:-]{1,160}\z/D',$id)){$this->failure='provider_response_invalid';return;}
            $this->messageId=$id;
        }elseif($type==='response.output_text.delta'){
            if($this->messageId===''||!is_string($event['delta']??null)){$this->failure='provider_response_invalid';return;}
            $this->append($event['delta']);
        }elseif(in_array($type,['response.completed','response.incomplete','response.failed'],true)){
            $response=$event['response']??null;
            if(!is_array($response)||$this->messageId===''||($response['id']??null)!==$this->messageId
                ||($response['status']??null)!==substr($type,9)){$this->failure='provider_response_invalid';return;}
            $final='';
            foreach(is_array($response['output']??null)?$response['output']:[] as $item){
                foreach(is_array($item['content']??null)?$item['content']:[] as $part){
                    if(($part['type']??null)==='output_text'&&is_string($part['text']??null))$final.=$part['text'];
                }
            }
            if($final!==$this->text){$this->failure='provider_response_invalid';return;}
            $this->usage=is_array($response['usage']??null)?$response['usage']:[];
            $this->stopReason=$response['status'];$this->terminal=true;
        }elseif($type==='error')$this->failure='provider_stream_error';
    }

    private function append(string $value): void
    {
        if(strlen($this->text)+strlen($value)>200000){$this->failure='provider_response_too_large';return;}
        $this->text.=$value;
    }

    public function snapshot(): array
    {
        return ['provider'=>$this->provider,'text'=>$this->text,'message_id'=>$this->messageId,'stop_reason'=>$this->stopReason,
            'terminal'=>$this->terminal,'usage'=>$this->terminal?$this->usage:[],'failure'=>$this->failure,'received_bytes'=>strlen($this->text)];
    }
}

/** One physical request through the native meter. No retry, fallback or browser-abort cancellation. */
function vp3_coding_canvas_provider_stream_v1(array $payload,string $key,AiV100ServiceMeter $meter,array $pin,?string $personalKey,?callable $beforeDispatch=null,?callable $checkpoint=null,?callable $transport=null): array
{
    $provider=$pin['provider'];$openai=$provider==='openai';$outputField=$openai?'max_output_tokens':'max_tokens';
    if(!$transport&&!function_exists('curl_init'))return ai_v100_service_failure('AI transport is unavailable.','transport_unavailable');
    if(!$meter->personal_key_is_current($pin,$personalKey))return ai_v100_service_failure('The personal provider credential changed.','personal_route_unavailable');
    if(!$openai)$payload=ai_haiku55_request($payload);
    $payload['stream']=true;
    try{
        json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
        $ticket=$meter->begin($pin,$payload,$outputField);
    }catch(Throwable $error){return ai_v100_service_budget_failure($error);}
    $payload[$outputField]=min($payload[$outputField],(int)($ticket['max_output_tokens']??$payload[$outputField]));
    $budget=function_exists('vp3_ai_budget_remaining_v1')?vp3_ai_budget_remaining_v1():null;
    try{$allowed=($beforeDispatch===null||$beforeDispatch()===true)&&$meter->personal_key_is_current($pin,$personalKey);}catch(Throwable){$allowed=false;}
    if(($budget!==null&&$budget<15)||!$allowed){
        try{$meter->finish($ticket,[],'aborted');}catch(Throwable $error){return ai_v100_service_budget_failure($error);}
        return ai_v100_service_failure('The saved Coding request changed or its execution budget expired.','coding_dispatch_unavailable');
    }
    $timeout=$budget===null?300:max(10,min(300,(int)floor($budget)));
    $stream=new Vp3CodingCanvasStreamV1($provider);$started=microtime(true);$lastSaved=0.0;$requestId='';$cancelled=false;$transportFailure='';
    $save=static function(bool $force=false)use($stream,$checkpoint,$started,&$lastSaved,&$requestId,&$cancelled,&$transportFailure):bool{
        if(!$checkpoint)return true;
        $now=microtime(true);if(!$force&&$now-$lastSaved<1.0)return !$cancelled;
        $lastSaved=$now;$snapshot=$stream->snapshot();if($transportFailure!=='')$snapshot['failure']=$transportFailure;
        try{$cancelled=$checkpoint($snapshot+['request_id'=>$requestId,'duration_ms'=>(int)round(($now-$started)*1000)])===false;}
        catch(Throwable){$cancelled=true;}
        return !$cancelled;
    };
    $body=json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
    $consume=static function(string $chunk)use($stream,$save):bool{
        $valid=$stream->feed($chunk);$saved=$save(!$valid);return $valid&&$saved;
    };
    $header=static function(string $line)use(&$requestId):void{if(preg_match('/\A(?:x-)?request-id:\s*([a-zA-Z0-9_.:-]{1,160})\s*\z/i',trim($line),$m))$requestId=$m[1];};
    $dispatched=false;
    try{
        if($transport!==null){$dispatched=true;$wire=$transport($body,$consume,$header,$timeout);}
        else{
            $endpoint=$openai?'https://api.openai.com/v1/responses':'https://api.anthropic.com/v1/messages';
            $curl=curl_init($endpoint);
            if($curl===false)throw new RuntimeException('transport_unavailable');
            $headers=$openai?['Authorization: Bearer '.$key]:['x-api-key: '.$key,'anthropic-version: 2023-06-01'];
            $errorBody='';
            curl_setopt_array($curl,[CURLOPT_POST=>true,CURLOPT_RETURNTRANSFER=>false,CURLOPT_CONNECTTIMEOUT=>10,CURLOPT_TIMEOUT=>$timeout,
                CURLOPT_HTTPHEADER=>[...$headers,'Content-Type: application/json','Accept: text/event-stream','Accept-Encoding: identity'],
                CURLOPT_POSTFIELDS=>$body,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,
                CURLOPT_NOPROGRESS=>false,
                CURLOPT_XFERINFOFUNCTION=>static function()use($save):int{return $save()?0:1;},
                CURLOPT_HEADERFUNCTION=>static function($curl,string $line)use($header):int{$header($line);return strlen($line);},
                CURLOPT_WRITEFUNCTION=>static function($curl,string $chunk)use($consume,&$errorBody):int{
                    $status=(int)curl_getinfo($curl,CURLINFO_HTTP_CODE);
                    if($status<200||$status>=300){if(strlen($errorBody)+strlen($chunk)<=8192)$errorBody.=$chunk;return strlen($chunk);}
                    return $consume($chunk)?strlen($chunk):0;
                }]);
            if(defined('CURLOPT_TCP_KEEPALIVE'))curl_setopt($curl,CURLOPT_TCP_KEEPALIVE,1);
            if(defined('CURLOPT_PROTOCOLS')&&defined('CURLPROTO_HTTPS'))curl_setopt($curl,CURLOPT_PROTOCOLS,CURLPROTO_HTTPS);
            $dispatched=true;
            $ok=curl_exec($curl)!==false;$status=(int)curl_getinfo($curl,CURLINFO_HTTP_CODE);$errno=curl_errno($curl);curl_close($curl);
            $wire=['ok'=>$ok,'status'=>$status,'errno'=>$errno,'error_body'=>$errorBody];
        }
    }catch(Throwable){$wire=['ok'=>false,'status'=>0,'errno'=>0];}
    $status=(int)($wire['status']??0);$known=$stream->terminal&&vp3_ai_service_usage_tokens_v1($stream->usage)!==null;
    $rejected=$stream->messageId===''&&in_array($status,[400,401,403,404,422,429],true);
    $success=!$cancelled&&!empty($wire['ok'])&&$status>=200&&$status<300&&$stream->terminal&&$stream->failure==='';
    $transportFailure=$cancelled?'coding_cancelled':((int)($wire['errno']??0)===28?'provider_timeout':($stream->failure!==''?$stream->failure:($rejected?'provider_rejected':($status===0?'provider_connection':($status>=500?'provider_server':'provider_response_incomplete')))));
    if($success)$transportFailure='';
    // Persist the final received bytes before settlement or source validation can fail.
    if(!$save(true))$success=false;
    $duration=(int)round((microtime(true)-$started)*1000);
    try{$meter->finish($ticket,$known?$stream->usage:[],$known?($success?'completed':'failed'):($rejected?'failed':($dispatched?'unknown':'aborted')),
        ['confirmed_no_charge'=>$rejected,'latency_ms'=>$duration,'provider_request_id'=>$requestId?:$stream->messageId]);}
    catch(Throwable $error){return ai_v100_service_budget_failure($error)+['received_output'=>$stream->text!==''];}
    if(!$success){
        $credit=$rejected&&!$openai&&ai_anthropic_credit_rejection_v1($status,json_decode((string)($wire['error_body']??''),true));
        $code=!$dispatched?'transport_unavailable':($credit?'anthropic_credit_balance_low':($rejected?($status===429?'coding_provider_rate_limited':'provider_rejected'):'provider_attempt_unconfirmed'));
        return ['ok'=>false,'failure_code'=>$code,'provider_failure_class'=>$transportFailure,'status'=>$status,'duration_ms'=>$duration,'attempts'=>1,'received_output'=>$stream->text!==''];
    }
    $decoded=$openai?['id'=>$stream->messageId,'status'=>$stream->stopReason,'output_text'=>$stream->text,'usage'=>$stream->usage]
        :['id'=>$stream->messageId,'stop_reason'=>$stream->stopReason,'content'=>[['type'=>'text','text'=>$stream->text]],'usage'=>$stream->usage];
    return ['ok'=>true,'data'=>$decoded,'status'=>$status,'duration_ms'=>$duration,'attempts'=>1];
}
