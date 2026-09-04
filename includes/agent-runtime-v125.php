<?php
declare(strict_types=1);

const STONEFELLOW_RUNTIME_V125='runtime-phase5-v125-20260826';

function agent_runtime_v125_root(): string{return STONEFELLOW_ROOT.'/private/runtime-v125';}
function agent_runtime_v125_dir(string $child=''): string
{
    $root=agent_runtime_v125_root();$dir=$child!==''?$root.'/'.trim($child,'/'):$root;
    if(!is_dir($dir)&&!mkdir($dir,0700,true)&&!is_dir($dir))throw new RuntimeException('Stonefellow runtime storage is unavailable.');@chmod($dir,0700);return $dir;
}
function agent_runtime_v125_safe_key(string $value): string{return preg_replace('/[^a-zA-Z0-9._-]/','-',mb_substr($value,0,120))?:'runtime';}
function agent_runtime_v125_trace_id(): string
{
    static $id=null;if($id!==null)return $id;
    $incoming=(string)($_SERVER['HTTP_X_STONEFELLOW_TRACE']??'');
    if(preg_match('/^[a-zA-Z0-9._-]{8,96}$/',$incoming))$id=$incoming;else try{$id=bin2hex(random_bytes(12));}catch(Throwable $e){$id=sha1(uniqid('',true));}
    return $id;
}
function agent_runtime_v125_boot(): void
{
    static $booted=false;if($booted)return;$booted=true;
    $trace=agent_runtime_v125_trace_id();if(!headers_sent())header('X-Stonefellow-Trace: '.$trace);
    $GLOBALS['STONEFELLOW_RUNTIME_V125_STARTED']=microtime(true);
    register_shutdown_function(static function() use($trace): void {
        $started=(float)($GLOBALS['STONEFELLOW_RUNTIME_V125_STARTED']??microtime(true));$error=error_get_last();
        agent_runtime_v125_trace('request.end',['duration_ms'=>(int)round((microtime(true)-$started)*1000),'fatal'=>is_array($error)&&in_array((int)($error['type']??0),[E_ERROR,E_PARSE,E_CORE_ERROR,E_COMPILE_ERROR],true),'status'=>http_response_code()]);
        agent_background_v125_drain(3,900);
    });
    agent_runtime_v125_trace('request.start',['method'=>(string)($_SERVER['REQUEST_METHOD']??'CLI'),'path'=>(string)parse_url((string)($_SERVER['REQUEST_URI']??''),PHP_URL_PATH)]);
}
function agent_runtime_v125_append_jsonl(string $bucket,array $row): void
{
    try{
        if(function_exists('agent_runtime_v126_safe_row'))$row=agent_runtime_v126_safe_row($bucket,$row);
        $dir=agent_runtime_v125_dir($bucket);$path=$dir.'/'.gmdate('Y-m-d').'.jsonl';file_put_contents($path,json_encode($row,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)."\n",FILE_APPEND|LOCK_EX);@chmod($path,0600);
    }catch(Throwable $e){}
}
function agent_runtime_v125_trace(string $event,array $data=[]): void
{
    $allowed=[];foreach($data as $k=>$v)if(is_scalar($v)||$v===null)$allowed[(string)$k]=$v;
    agent_runtime_v125_append_jsonl('traces',['time'=>gmdate('c'),'trace_id'=>agent_runtime_v125_trace_id(),'event'=>mb_substr($event,0,100)]+$allowed);
}
function agent_runtime_v125_span(string $name,callable $fn,array $data=[]): mixed
{
    $started=microtime(true);agent_runtime_v125_trace($name.'.start',$data);
    try{$result=$fn();agent_runtime_v125_trace($name.'.end',$data+['duration_ms'=>(int)round((microtime(true)-$started)*1000),'ok'=>true]);return $result;}
    catch(Throwable $e){agent_runtime_v125_trace($name.'.end',$data+['duration_ms'=>(int)round((microtime(true)-$started)*1000),'ok'=>false,'error_class'=>get_class($e)]);throw $e;}
}

function agent_runtime_v125_health(array $user,string $surface,array $voice,array $context=[]): array
{
    $uid=(int)($user['id']??0);$session=mb_substr((string)($voice['session_id']??''),0,120);$state=mb_substr((string)($voice['state']??''),0,40);
    $metrics=[];foreach(['recognition_starts','barge_starts','recognition_errors','accepted','low_confidence_rejected','duplicates_rejected','echo_rejected','interruptions','preserved_interruptions','recoveries','circuit_breaks'] as $k)$metrics[$k]=max(0,(int)($voice[$k]??0));
    $row=['time'=>gmdate('c'),'trace_id'=>agent_runtime_v125_trace_id(),'user_id'=>$uid,'surface'=>mb_substr($surface,0,30),'session_id'=>$session,'state'=>$state,'source'=>mb_substr((string)($voice['source']??''),0,40),'reason'=>mb_substr((string)($voice['reason']??''),0,80)]+$metrics;
    agent_runtime_v125_append_jsonl('voice-health',$row);
    $quality=max(0.0,min(1.0,1.0-min(.75,$metrics['recognition_errors']*.04+$metrics['circuit_breaks']*.12+$metrics['echo_rejected']*.003)+min(.2,$metrics['accepted']*.01+$metrics['preserved_interruptions']*.03)));
    if($session!==''){
        try{$dir=agent_runtime_v125_dir('voice-sessions');$path=$dir.'/'.sha1($uid.'|'.$session).'.json';file_put_contents($path,json_encode($row+['quality_score'=>round($quality,4),'updated_at'=>gmdate('c')],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE),LOCK_EX);@chmod($path,0600);}catch(Throwable $e){}
    }
    return ['session_id'=>$session,'quality_score'=>round($quality,4),'metrics'=>$metrics,'trace_id'=>agent_runtime_v125_trace_id()];
}

function agent_runtime_v125_breaker_path(string $service): string{return agent_runtime_v125_dir('breakers').'/'.sha1($service).'.json';}
function agent_runtime_v125_breaker_state(string $service): array
{
    $path=agent_runtime_v125_breaker_path($service);$state=['failures'=>0,'window_started'=>0,'open_until'=>0,'last_failure'=>0];
    try{$raw=json_decode((string)@file_get_contents($path),true);if(is_array($raw))$state=array_merge($state,$raw);}catch(Throwable $e){}return $state;
}
function agent_runtime_v125_breaker_allowed(string $service): bool{return (int)(agent_runtime_v125_breaker_state($service)['open_until']??0)<=time();}
function agent_runtime_v125_breaker_record(string $service,bool $ok): void
{
    $path=agent_runtime_v125_breaker_path($service);$now=time();$state=agent_runtime_v125_breaker_state($service);
    if($ok){$state=['failures'=>0,'window_started'=>$now,'open_until'=>0,'last_failure'=>(int)($state['last_failure']??0)];}
    else{
        $window=(int)($state['window_started']??0);if($window<1||$now-$window>90){$state['failures']=0;$state['window_started']=$now;}
        $state['failures']=(int)($state['failures']??0)+1;$state['last_failure']=$now;
        if($state['failures']>=4)$state['open_until']=$now+min(60,8*(int)$state['failures']);
    }
    try{file_put_contents($path,json_encode($state,JSON_UNESCAPED_SLASHES),LOCK_EX);@chmod($path,0600);}catch(Throwable $e){}
}
function agent_runtime_v125_resilient_call(string $service,callable $fn,callable $shouldRetry,int $maxAttempts=2,int $baseDelayMs=140): mixed
{
    if(!agent_runtime_v125_breaker_allowed($service)){agent_runtime_v125_trace('breaker.open',['service'=>$service]);throw new RuntimeException('The '.$service.' service is temporarily recovering.');}
    $last=null;$attempts=max(1,min(4,$maxAttempts));
    for($attempt=1;$attempt<=$attempts;$attempt++){
        $started=microtime(true);
        try{
            $result=$fn($attempt);$retry=(bool)$shouldRetry($result,null,$attempt);
            if(!$retry){
                $ok=!(is_array($result)&&array_key_exists('ok',$result)&&empty($result['ok'])&&empty($result['partial']));
                agent_runtime_v125_breaker_record($service,$ok);agent_runtime_v125_trace('service.call',['service'=>$service,'attempt'=>$attempt,'ok'=>$ok,'duration_ms'=>(int)round((microtime(true)-$started)*1000)]);return $result;
            }
            $last=$result;agent_runtime_v125_breaker_record($service,false);agent_runtime_v125_trace('service.retry',['service'=>$service,'attempt'=>$attempt,'duration_ms'=>(int)round((microtime(true)-$started)*1000)]);
        }catch(Throwable $e){$last=$e;agent_runtime_v125_breaker_record($service,false);if(!$shouldRetry(null,$e,$attempt)||$attempt===$attempts)throw $e;}
        if($attempt<$attempts){$delay=min(1500,$baseDelayMs*(2**($attempt-1))+random_int(0,90));usleep($delay*1000);}
    }
    return $last;
}

function agent_runtime_v125_idempotency_path(string $scope,string $key): string{return agent_runtime_v125_dir('idempotency').'/'.sha1($scope.'|'.$key).'.json';}
function agent_runtime_v125_idempotency_claim(string $scope,string $key,int $ttl=900): array
{
    $path=agent_runtime_v125_idempotency_path($scope,$key);$now=time();$fh=fopen($path,'c+');if(!$fh)return ['claimed'=>true,'state'=>'untracked','path'=>''];@chmod($path,0600);
    $result=['claimed'=>false,'state'=>'processing','result'=>null,'path'=>$path];
    if(flock($fh,LOCK_EX)){rewind($fh);$raw=json_decode((string)stream_get_contents($fh),true);$created=is_array($raw)?(int)($raw['created_at']??0):0;$status=is_array($raw)?(string)($raw['status']??''):'';
        if($created>0&&$now-$created<max(30,$ttl)&&in_array($status,['processing','completed'],true)){$result['state']=$status;$result['result']=$raw['result']??null;}
        else{$row=['scope'=>$scope,'key_hash'=>sha1($key),'status'=>'processing','created_at'=>$now,'trace_id'=>agent_runtime_v125_trace_id()];ftruncate($fh,0);rewind($fh);fwrite($fh,json_encode($row,JSON_UNESCAPED_SLASHES));fflush($fh);$result['claimed']=true;$result['state']='processing';}
        flock($fh,LOCK_UN);
    }fclose($fh);return $result;
}
function agent_runtime_v125_idempotency_complete(string $scope,string $key,mixed $result=null): void
{
    $path=agent_runtime_v125_idempotency_path($scope,$key);try{file_put_contents($path,json_encode(['scope'=>$scope,'key_hash'=>sha1($key),'status'=>'completed','created_at'=>time(),'trace_id'=>agent_runtime_v125_trace_id(),'result'=>$result],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE),LOCK_EX);@chmod($path,0600);}catch(Throwable $e){}
}

function agent_background_v125_jobs_dir(): string{return agent_runtime_v125_dir('jobs');}
function agent_background_v125_enqueue(string $kind,array $payload=[],string $dedupeKey='',int $delaySeconds=0): string
{
    $kind=agent_runtime_v125_safe_key($kind);$dedupeKey=$dedupeKey!==''?$dedupeKey:(string)json_encode($payload,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);$id=sha1($kind.'|'.$dedupeKey);$path=agent_background_v125_jobs_dir().'/'.$id.'.json';
    if(is_file($path))return $id;$fh=@fopen($path,'x');if(!$fh)return $id;@chmod($path,0600);
    $row=['id'=>$id,'kind'=>$kind,'payload'=>$payload,'status'=>'queued','attempts'=>0,'available_at'=>time()+max(0,$delaySeconds),'created_at'=>time(),'trace_id'=>agent_runtime_v125_trace_id()];
    fwrite($fh,json_encode($row,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));fflush($fh);fclose($fh);return $id;
}
function agent_background_v125_handle(array $job): bool
{
    $kind=(string)($job['kind']??'');$payload=is_array($job['payload']??null)?$job['payload']:[];
    if($kind==='memory-reconcile'&&function_exists('agent_memory_v123_reconcile_user')){$user=['id'=>(int)($payload['user_id']??0)];if($user['id']>0){agent_memory_v123_reconcile_user($user);return true;}}
    if($kind==='conversation-summary'&&function_exists('agent_brain_v122_refresh_conversation')){$user=['id'=>(int)($payload['user_id']??0)];$cid=(int)($payload['conversation_id']??0);if($user['id']>0&&$cid>0){agent_brain_v122_refresh_conversation($user,$cid);return true;}}
    return false;
}
function agent_background_v125_recover_orphans(string $dir): void
{
    foreach(glob($dir.'/*.json.working')?:[] as $working){$mtime=(int)@filemtime($working);if($mtime>0&&time()-$mtime<120)continue;$queued=substr($working,0,-8);if(!is_file($queued))@rename($working,$queued);}
}
function agent_background_v125_drain(int $maxJobs=3,int $budgetMs=900): array
{
    $started=microtime(true);$done=0;$failed=0;$dir=agent_background_v125_jobs_dir();agent_background_v125_recover_orphans($dir);$files=glob($dir.'/*.json')?:[];sort($files,SORT_STRING);
    foreach($files as $path){
        if(str_starts_with(basename($path),'failed-'))continue;
        if($done+$failed>=max(1,$maxJobs)||(microtime(true)-$started)*1000>$budgetMs)break;
        $raw=json_decode((string)@file_get_contents($path),true);if(!is_array($raw)||(int)($raw['available_at']??0)>time())continue;
        $working=$path.'.working';if(!@rename($path,$working))continue;$raw['attempts']=(int)($raw['attempts']??0)+1;
        try{
            $ok=agent_background_v125_handle($raw);if(!$ok)throw new RuntimeException('No handler accepted the job.');
            @unlink($working);$done++;agent_runtime_v125_trace('background.done',['kind'=>(string)$raw['kind'],'attempts'=>$raw['attempts']]);
        }catch(Throwable $e){
            $failed++;
            if($raw['attempts']>=3){$raw['status']='failed';$raw['error_class']=get_class($e);$failedPath=$dir.'/failed-'.$raw['id'].'.json';@file_put_contents($failedPath,json_encode($raw,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE),LOCK_EX);@chmod($failedPath,0600);@unlink($working);}
            else{$raw['available_at']=time()+min(60,5*(2**($raw['attempts']-1)));@file_put_contents($working,json_encode($raw,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE),LOCK_EX);@rename($working,$path);}
            agent_runtime_v125_trace('background.failed',['kind'=>(string)$raw['kind'],'attempts'=>$raw['attempts'],'error_class'=>get_class($e)]);
        }
    }
    return ['done'=>$done,'failed'=>$failed,'duration_ms'=>(int)round((microtime(true)-$started)*1000)];
}