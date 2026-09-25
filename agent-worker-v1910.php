<?php
declare(strict_types=1);

if(PHP_SAPI!=='cli'){
    http_response_code(404);
    exit;
}

require_once __DIR__.'/includes/bootstrap.php';
require_once __DIR__.'/includes/agent-job-worker-v1900.php';
require_once __DIR__.'/includes/agent-worker-cloud-v1910.php';

const VP3_AGENT_WORKER_RUNNER_V1910='agent-worker-runner-v1910-20260914';

function agent_worker_runner_option_v1910(array $argv,string $name,string $fallback=''): string
{
    $prefix='--'.$name.'=';
    foreach($argv as $arg)if(str_starts_with((string)$arg,$prefix))return substr((string)$arg,strlen($prefix));
    return $fallback;
}

function agent_worker_runner_owner_ids_v1910(PDO $pdo,int $limit=50): array
{
    if(!agent_job_engine_schema_ready_v1900($pdo))return [];
    $limit=max(1,min(250,$limit));
    $sql="SELECT DISTINCT owner_user_id FROM agent_workflow_runs
          WHERE status IN ('approved','executing')
            AND (next_attempt_at IS NULL OR next_attempt_at<=UTC_TIMESTAMP())
            AND (current_action_id IS NULL OR lease_expires_at IS NULL OR lease_expires_at<=UTC_TIMESTAMP())
          ORDER BY owner_user_id LIMIT ".$limit;
    $rows=$pdo->query($sql)->fetchAll()?:[];
    return array_values(array_filter(array_map(static fn(array $r):int=>(int)($r['owner_user_id']??0),$rows),static fn(int $id):bool=>$id>0));
}

function agent_worker_runner_lock_v1910(): mixed
{
    $dir=agent_runtime_v125_dir('workers');
    $path=$dir.'/distributed-v1910.lock';
    $fh=@fopen($path,'c+');
    if(!$fh)return null;
    @chmod($path,0600);
    if(!flock($fh,LOCK_EX|LOCK_NB)){fclose($fh);return null;}
    ftruncate($fh,0);rewind($fh);
    fwrite($fh,json_encode(['pid'=>getmypid(),'started_at'=>gmdate('c'),'build'=>VP3_AGENT_WORKER_RUNNER_V1910],JSON_UNESCAPED_SLASHES));
    fflush($fh);
    return $fh;
}

function agent_worker_runner_cycle_v1910(PDO $pdo,string $executor,int $ownerLimit): array
{
    $counts=['owners'=>0,'cloud_completed'=>0,'homeserver_completed'=>0,'no_work'=>0,'failed'=>0];
    foreach(agent_worker_runner_owner_ids_v1910($pdo,$ownerLimit) as $uid){
        $user=agent_cognitive_loop_v310_user($uid);
        if(!$user)continue;
        $counts['owners']++;
        if($executor==='all'||$executor==='cloud'){
            try{
                $result=agent_worker_cloud_execute_once_v1910($pdo,$user,25);
                if(($result['reason']??'')==='completed')$counts['cloud_completed']++;
                elseif(($result['reason']??'')==='no_work')$counts['no_work']++;
                elseif(empty($result['ok']))$counts['failed']++;
            }catch(Throwable $e){$counts['failed']++;agent_runtime_v125_trace('worker.cloud.failed',['user_id'=>$uid,'error_class'=>get_class($e)]);}
        }
        if($executor==='all'||$executor==='homeserver'){
            try{
                $result=agent_job_worker_execute_homeserver_v1910($pdo,$user,25);
                if(in_array((string)($result['reason']??''),['completed','homeserver_approval_pending'],true))$counts['homeserver_completed']++;
                elseif(($result['reason']??'')==='no_work')$counts['no_work']++;
                elseif(empty($result['ok']))$counts['failed']++;
            }catch(Throwable $e){$counts['failed']++;agent_runtime_v125_trace('worker.homeserver.failed',['user_id'=>$uid,'error_class'=>get_class($e)]);}
        }
    }
    $counts['completed_at']=gmdate('c');
    return $counts;
}

$executor=strtolower(agent_worker_runner_option_v1910($argv,'executor','all'));
if(!in_array($executor,['all','cloud','homeserver'],true)){
    fwrite(STDERR,"Invalid --executor. Use all, cloud, or homeserver.\n");
    exit(2);
}
$loop=in_array('--loop',$argv,true);
$sleep=max(1,min(60,(int)agent_worker_runner_option_v1910($argv,'sleep','5')));
$ownerLimit=max(1,min(250,(int)agent_worker_runner_option_v1910($argv,'max-owners','50')));
$lock=agent_worker_runner_lock_v1910();
if(!$lock){fwrite(STDOUT,json_encode(['ok'=>true,'reason'=>'runner_already_active','build'=>VP3_AGENT_WORKER_RUNNER_V1910]).PHP_EOL);exit(0);}

$pdo=db();
if(!$pdo||!agent_job_engine_schema_ready_v1900($pdo)){
    flock($lock,LOCK_UN);fclose($lock);
    fwrite(STDERR,"Phase 19.0 durable job schema is not ready.\n");
    exit(3);
}

do{
    $cycle=agent_worker_runner_cycle_v1910($pdo,$executor,$ownerLimit);
    fwrite(STDOUT,json_encode(['ok'=>true,'executor'=>$executor,'cycle'=>$cycle,'build'=>VP3_AGENT_WORKER_RUNNER_V1910],JSON_UNESCAPED_SLASHES).PHP_EOL);
    if($loop)sleep($sleep);
}while($loop);

flock($lock,LOCK_UN);fclose($lock);
