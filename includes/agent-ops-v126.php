<?php
declare(strict_types=1);

const STONEFELLOW_PRODUCTION_V126='production-hardening-v126-20260826';

function agent_runtime_v126_retention_policy(): array
{
    return [
        'traces'=>14*86400,
        'voice-health'=>30*86400,
        'voice-sessions'=>30*86400,
        'idempotency'=>2*86400,
        'breakers'=>7*86400,
        'jobs-failed'=>14*86400,
    ];
}

function agent_runtime_v126_redact_string(string $value): string
{
    $value=mb_strimwidth($value,0,320,'…');
    $value=preg_replace('/\bBearer\s+[A-Za-z0-9._~+\/-]+=*/i','Bearer [redacted]',$value)??$value;
    $value=preg_replace('/\b(?:sk|pk|key|token)-[A-Za-z0-9_-]{12,}\b/i','[redacted-token]',$value)??$value;
    $value=preg_replace('/\b[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}\b/i','[email]',$value)??$value;
    $value=preg_replace('/(?<!\d)(?:\+?1[ .-]?)?\(?\d{3}\)?[ .-]?\d{3}[ .-]?\d{4}(?!\d)/','[phone]',$value)??$value;
    return $value;
}

function agent_runtime_v126_sensitive_key(string $key): bool
{
    return (bool)preg_match('/(?:password|passwd|secret|api[_-]?key|authorization|cookie|csrf|access[_-]?token|refresh[_-]?token|prompt|message|query|body|content|transcript|memory_text)/i',$key);
}

function agent_runtime_v126_safe_row(string $bucket,array $row): array
{
    $safe=[];
    foreach($row as $key=>$value){
        $key=(string)$key;
        if(agent_runtime_v126_sensitive_key($key)){$safe[$key]='[redacted]';continue;}
        if(is_string($value)){$safe[$key]=agent_runtime_v126_redact_string($value);continue;}
        if(is_int($value)||is_float($value)||is_bool($value)||$value===null){$safe[$key]=$value;continue;}
        $safe[$key]='[non-scalar omitted]';
    }
    return $safe;
}

function agent_runtime_v126_file_stats(string $path): array
{
    $exists=is_file($path);return ['exists'=>$exists,'size'=>$exists?(int)@filesize($path):0,'mtime'=>$exists?(int)@filemtime($path):0];
}

function agent_runtime_v126_bucket_stats(string $bucket): array
{
    $dir=agent_runtime_v125_dir($bucket);$count=0;$bytes=0;$latest=0;
    foreach(glob($dir.'/*')?:[] as $path){if(!is_file($path))continue;$count++;$bytes+=(int)@filesize($path);$latest=max($latest,(int)@filemtime($path));}
    return ['count'=>$count,'bytes'=>$bytes,'latest_at'=>$latest?date('c',$latest):''];
}

function agent_runtime_v126_voice_quality(): array
{
    $dir=agent_runtime_v125_dir('voice-sessions');$rows=[];
    foreach(glob($dir.'/*.json')?:[] as $path){$mtime=(int)@filemtime($path);$raw=json_decode((string)@file_get_contents($path),true);if(!is_array($raw))continue;$rows[]=['mtime'=>$mtime,'quality'=>(float)($raw['quality_score']??0),'state'=>(string)($raw['state']??''),'surface'=>(string)($raw['surface']??'')];}
    usort($rows,static fn(array $a,array $b):int=>$b['mtime']<=>$a['mtime']);$rows=array_slice($rows,0,50);$avg=$rows?array_sum(array_column($rows,'quality'))/count($rows):0.0;
    return ['sessions'=>count($rows),'average_quality'=>round($avg,4),'latest'=>$rows[0]??null];
}

function agent_runtime_v126_breaker_health(): array
{
    $dir=agent_runtime_v125_dir('breakers');$open=0;$total=0;$latest=0;
    foreach(glob($dir.'/*.json')?:[] as $path){$raw=json_decode((string)@file_get_contents($path),true);if(!is_array($raw))continue;$total++;if((int)($raw['open_until']??0)>time())$open++;$latest=max($latest,(int)@filemtime($path));}
    return ['total'=>$total,'open'=>$open,'latest_at'=>$latest?date('c',$latest):''];
}

function agent_runtime_v126_job_health(): array
{
    $dir=agent_background_v125_jobs_dir();$queued=$working=$failed=$stale=0;$oldest=0;
    foreach(glob($dir.'/*')?:[] as $path){if(!is_file($path))continue;$name=basename($path);$mtime=(int)@filemtime($path);$oldest=$oldest===0?$mtime:min($oldest,$mtime);
        if(str_starts_with($name,'failed-')){$failed++;continue;}
        if(str_ends_with($name,'.working')){$working++;if($mtime>0&&time()-$mtime>120)$stale++;continue;}
        if(str_ends_with($name,'.json'))$queued++;
    }
    return ['queued'=>$queued,'working'=>$working,'failed'=>$failed,'stale_working'=>$stale,'oldest_at'=>$oldest?date('c',$oldest):''];
}

function agent_runtime_v126_health_snapshot(): array
{
    $policy=agent_runtime_v126_retention_policy();$buckets=[];
    foreach(['traces','voice-health','voice-sessions','idempotency','breakers','jobs'] as $bucket)$buckets[$bucket]=agent_runtime_v126_bucket_stats($bucket);
    $jobs=agent_runtime_v126_job_health();$breakers=agent_runtime_v126_breaker_health();$voice=agent_runtime_v126_voice_quality();
    $healthy=$jobs['stale_working']===0&&$jobs['failed']===0&&$breakers['open']===0;
    return [
        'build'=>STONEFELLOW_PRODUCTION_V126,'healthy'=>$healthy,'trace_id'=>agent_runtime_v125_trace_id(),'generated_at'=>date('c'),
        'jobs'=>$jobs,'breakers'=>$breakers,'voice'=>$voice,'buckets'=>$buckets,'retention_seconds'=>$policy,
    ];
}

function agent_runtime_v126_prune_glob(string $pattern,int $maxAge,callable $allow=null): array
{
    $removed=0;$bytes=0;$cutoff=time()-max(60,$maxAge);
    foreach(glob($pattern)?:[] as $path){if(!is_file($path))continue;if($allow&&!$allow($path))continue;$mtime=(int)@filemtime($path);if($mtime<1||$mtime>=$cutoff)continue;$size=(int)@filesize($path);if(@unlink($path)){$removed++;$bytes+=$size;}}
    return ['removed'=>$removed,'bytes'=>$bytes];
}

function agent_runtime_v126_cleanup(): array
{
    $policy=agent_runtime_v126_retention_policy();$results=[];
    $results['traces']=agent_runtime_v126_prune_glob(agent_runtime_v125_dir('traces').'/*.jsonl',$policy['traces']);
    $results['voice-health']=agent_runtime_v126_prune_glob(agent_runtime_v125_dir('voice-health').'/*.jsonl',$policy['voice-health']);
    $results['voice-sessions']=agent_runtime_v126_prune_glob(agent_runtime_v125_dir('voice-sessions').'/*.json',$policy['voice-sessions']);
    $results['idempotency']=agent_runtime_v126_prune_glob(agent_runtime_v125_dir('idempotency').'/*.json',$policy['idempotency']);
    $results['breakers']=agent_runtime_v126_prune_glob(agent_runtime_v125_dir('breakers').'/*.json',$policy['breakers']);
    $results['jobs-failed']=agent_runtime_v126_prune_glob(agent_background_v125_jobs_dir().'/failed-*.json',$policy['jobs-failed']);
    $totalRemoved=array_sum(array_column($results,'removed'));$totalBytes=array_sum(array_column($results,'bytes'));
    agent_runtime_v125_trace('housekeeping.cleanup',['removed'=>$totalRemoved,'bytes'=>$totalBytes]);
    return ['removed'=>$totalRemoved,'bytes'=>$totalBytes,'details'=>$results,'completed_at'=>date('c')];
}

function agent_runtime_v126_housekeeping_maybe(int $interval=21600): ?array
{
    $path=agent_runtime_v125_dir().'/housekeeping-v126.json';$fh=@fopen($path,'c+');if(!$fh)return null;@chmod($path,0600);$run=false;
    if(flock($fh,LOCK_EX|LOCK_NB)){
        rewind($fh);$raw=json_decode((string)stream_get_contents($fh),true);$last=is_array($raw)?(int)($raw['last_run']??0):0;
        if($last<time()-max(3600,$interval)){$run=true;ftruncate($fh,0);rewind($fh);fwrite($fh,json_encode(['last_run'=>time(),'trace_id'=>agent_runtime_v125_trace_id()]));fflush($fh);}flock($fh,LOCK_UN);
    }
    fclose($fh);return $run?agent_runtime_v126_cleanup():null;
}

function agent_runtime_v126_self_test(): array
{
    $checks=[];
    $safe=agent_runtime_v126_safe_row('self-test',['api_key'=>'sk-super-secret-value','email'=>'person@example.com','status'=>'ok']);
    $checks['redaction']=($safe['api_key']??'')==='[redacted]'&&($safe['email']??'')==='[email]'&&($safe['status']??'')==='ok';

    $service='self-test-'.sha1((string)microtime(true));$breakerPath=agent_runtime_v125_breaker_path($service);@unlink($breakerPath);
    for($i=0;$i<4;$i++)agent_runtime_v125_breaker_record($service,false);
    $checks['breaker_opens']=!agent_runtime_v125_breaker_allowed($service);
    agent_runtime_v125_breaker_record($service,true);$checks['breaker_recovers']=agent_runtime_v125_breaker_allowed($service);@unlink($breakerPath);

    $scope='self-test';$key=bin2hex(random_bytes(12));$idPath=agent_runtime_v125_idempotency_path($scope,$key);@unlink($idPath);
    $first=agent_runtime_v125_idempotency_claim($scope,$key,60);agent_runtime_v125_idempotency_complete($scope,$key,['ok'=>true,'proof'=>'same']);$second=agent_runtime_v125_idempotency_claim($scope,$key,60);
    $checks['idempotency_claim']=!empty($first['claimed']);$checks['idempotency_replay']=empty($second['claimed'])&&($second['state']??'')==='completed'&&(($second['result']['proof']??'')==='same');@unlink($idPath);

    $dedupe='self-test-'.bin2hex(random_bytes(8));$jobA=agent_background_v125_enqueue('self-test',['proof'=>'queue'],$dedupe,3600);$jobB=agent_background_v125_enqueue('self-test',['proof'=>'queue'],$dedupe,3600);$jobPath=agent_background_v125_jobs_dir().'/'.$jobA.'.json';
    $checks['queue_dedupe']=$jobA===$jobB&&is_file($jobPath);@unlink($jobPath);

    $passed=!in_array(false,$checks,true);agent_runtime_v125_trace('runtime.self_test',['passed'=>$passed,'checks'=>count($checks)]);
    return ['passed'=>$passed,'checks'=>$checks,'trace_id'=>agent_runtime_v125_trace_id(),'build'=>STONEFELLOW_PRODUCTION_V126];
}
