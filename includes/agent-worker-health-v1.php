<?php
declare(strict_types=1);

/** Observations only. These timestamps never authorize a durable job claim. */
function agent_worker_health_path_v1(?string $directory=null): string
{
    if($directory===null){
        // A status read must not provision or chmod runtime storage. The CLI
        // observer below is the only writer and creates the canonical directory.
        if(!function_exists('agent_runtime_v125_root'))return '';
        $directory=agent_runtime_v125_root().'/workers';
    }
    return $directory!==''?rtrim($directory,'/').'/initiative-runner-health-v1.json':'';
}

function agent_worker_health_empty_v1(string $state='never_seen'): array
{
    return ['state'=>$state,'observation_scope'=>'server','last_attempt_at'=>'','last_success_at'=>''];
}

function agent_worker_health_decode_v1(string $raw,int $clock): array
{
    $out=agent_worker_health_empty_v1('unknown');
    if(strlen($raw)>4096)return $out;
    $data=json_decode($raw,true);
    if(!is_array($data)||($data['source']??'')!=='initiative_cli_sweep'
        ||!in_array($data['outcome']??null,['success','failed'],true))return $out;
    foreach(['last_attempt_at','last_success_at'] as $key){
        if($key==='last_success_at'&&($data[$key]??null)==='')continue;
        $at=is_string($data[$key]??null)?strtotime($data[$key]):false;
        if($at===false||$at<1||$at>$clock)return agent_worker_health_empty_v1('unknown');
        $out[$key]=gmdate(DATE_ATOM,$at);
    }
    $success=$out['last_success_at']!==''?(strtotime($out['last_success_at'])?:0):0;
    $attempt=$out['last_attempt_at']!==''?(strtotime($out['last_attempt_at'])?:0):0;
    if($success>$attempt||($data['outcome']==='success'&&$success<1))return agent_worker_health_empty_v1('unknown');
    $out['state']=$success>0?($clock-$success<=300?'fresh':'stale'):'never_seen';
    if($data['outcome']==='failed'&&$clock-$attempt<=300)$out['state']='failed';
    return $out;
}

function agent_worker_health_read_v1(?string $directory=null,?int $now=null): array
{
    try{
        $path=agent_worker_health_path_v1($directory);
        if($path==='')return agent_worker_health_empty_v1('unknown');
        if(is_link($path))return agent_worker_health_empty_v1('unknown');
        if(!is_file($path))return agent_worker_health_empty_v1(file_exists($path)?'unknown':'never_seen');
        $file=@fopen($path,'r');
        if(!$file)return agent_worker_health_empty_v1('unknown');
        try{
            // Observation is optional: never stall a Chat request behind a
            // writer, and never mistake a failed read for an account failure.
            if(!flock($file,LOCK_SH|LOCK_NB))return agent_worker_health_empty_v1('unknown');
            $raw=stream_get_contents($file,4097);
            flock($file,LOCK_UN);
        }finally{fclose($file);}
        return is_string($raw)?agent_worker_health_decode_v1($raw,$now??time()):agent_worker_health_empty_v1('unknown');
    }catch(Throwable $e){return agent_worker_health_empty_v1('unknown');}
}

/** Called only after a real CLI sweep attempt, never by a browser read/pump. */
function agent_worker_health_observe_v1(bool $success,?string $directory=null,?int $now=null): bool
{
    try{
        if($directory===null){
            if(!function_exists('agent_runtime_v125_dir'))return false;
            $directory=agent_runtime_v125_dir('workers');
        }
        $path=agent_worker_health_path_v1($directory);
        if($path===''||is_link($path))return false;
        $file=@fopen($path,'c+');
        if(!$file)return false;
        try{
            if(!flock($file,LOCK_EX|LOCK_NB))return false;
            @chmod($path,0600);
            $clock=$now??time();$raw=stream_get_contents($file,4097);
            $old=is_string($raw)?agent_worker_health_decode_v1($raw,$clock):agent_worker_health_empty_v1('unknown');
            $at=gmdate(DATE_ATOM,$clock);
            $data=['source'=>'initiative_cli_sweep','last_attempt_at'=>$at,
                'last_success_at'=>$success?$at:$old['last_success_at'],'outcome'=>$success?'success':'failed'];
            $json=json_encode($data,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES);
            rewind($file);
            if(!ftruncate($file,0))return false;
            $written=fwrite($file,$json)===strlen($json)&&fflush($file);
            flock($file,LOCK_UN);
            return $written;
        }finally{fclose($file);}
    }catch(Throwable $e){return false;}
}
