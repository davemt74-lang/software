<?php
declare(strict_types=1);

/** Observations only. These timestamps never authorize a durable job claim. */
function agent_worker_health_path_v1(?string $directory=null): string
{
    if($directory===null){
        if(!function_exists('agent_runtime_v125_dir'))return '';
        $directory=agent_runtime_v125_dir('workers');
    }
    return $directory!==''?rtrim($directory,'/').'/initiative-runner-health-v1.json':'';
}

function agent_worker_health_read_v1(?string $directory=null,?int $now=null): array
{
    $out=['state'=>'never_seen','observation_scope'=>'server','last_attempt_at'=>'','last_success_at'=>''];
    $path=agent_worker_health_path_v1($directory);
    if($path===''||!is_file($path))return $out;
    $file=@fopen($path,'r');
    if(!$file)return $out;
    try{
        if(!flock($file,LOCK_SH))return $out;
        $raw=stream_get_contents($file,4097);
        flock($file,LOCK_UN);
    }finally{fclose($file);}
    if(!is_string($raw)||strlen($raw)>4096)return $out;
    $data=json_decode($raw,true);
    if(!is_array($data)||($data['source']??'')!=='initiative_cli_sweep')return $out;
    $clock=$now??time();
    foreach(['last_attempt_at','last_success_at'] as $key){
        $at=is_string($data[$key]??null)?strtotime($data[$key]):false;
        if($at!==false&&$at>0&&$at<=$clock)$out[$key]=gmdate(DATE_ATOM,$at);
    }
    $success=$out['last_success_at']!==''?(strtotime($out['last_success_at'])?:0):0;
    $attempt=$out['last_attempt_at']!==''?(strtotime($out['last_attempt_at'])?:0):0;
    $out['state']=$success>0?($clock-$success<=300?'fresh':'stale'):'never_seen';
    if(($data['outcome']??'')==='failed'&&$attempt>=$success&&$clock-$attempt<=300)$out['state']='failed';
    return $out;
}

/** Called only after a real CLI sweep attempt, never by a browser read/pump. */
function agent_worker_health_observe_v1(bool $success,?string $directory=null,?int $now=null): bool
{
    $path=agent_worker_health_path_v1($directory);
    if($path==='')return false;
    $file=@fopen($path,'c+');
    if(!$file)return false;
    @chmod($path,0600);
    try{
        if(!flock($file,LOCK_EX))return false;
        $old=json_decode((string)stream_get_contents($file,4096),true);
        $at=gmdate(DATE_ATOM,$now??time());
        $data=['source'=>'initiative_cli_sweep','last_attempt_at'=>$at,
            'last_success_at'=>$success?$at:(string)($old['last_success_at']??''),'outcome'=>$success?'success':'failed'];
        $json=json_encode($data,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES);
        rewind($file);
        if(!ftruncate($file,0))return false;
        $written=fwrite($file,$json)===strlen($json)&&fflush($file);
        flock($file,LOCK_UN);
        return $written;
    }finally{fclose($file);}
}
