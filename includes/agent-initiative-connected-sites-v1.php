<?php
declare(strict_types=1);

/** Queue connection reads on the existing Initiative worker. Discovery stays local. */
function vp3_initiative_connected_sites_schedule_v1(PDO $pdo,array $user,array $binding): void
{
    if($pdo->inTransaction())return;
    try{
        if(!table_exists('vp3_gelato_connections_v1')&&!table_exists('vp3_stoneward_connections_v1'))return;
        require_once __DIR__.'/connected-sites-reports-v1.php';
        require_once __DIR__.'/agent-media-stoneward-v1.php';
        $now=new DateTimeImmutable('now',new DateTimeZone('UTC'));
        $due=array_merge(vp3_connected_sites_due_v1($pdo,$user,$now),vp3_stoneward_media_due_v1($pdo,$user,$now));
        if(!$due)return;
        usort($due,static fn(array $a,array $b):int=>[$a['checked_at'],$a['connection_id'],$a['location_id']]
            <=> [$b['checked_at'],$b['connection_id'],$b['location_id']]);
        $pdo->beginTransaction();
        vp3_initiative_owner_lock_v1($pdo,$user);
        $live=vp3_initiative_access_v1($pdo,$user,$binding);
        $query=$pdo->prepare("SELECT goal FROM agent_workflow_runs
            WHERE owner_user_id=? AND source_kind='agent_initiative' AND source_key=?
            AND status IN ('approved','executing') ORDER BY id DESC LIMIT 8");
        foreach(array_slice($due,0,4) as $target){
            $key=vp3_initiative_connected_sites_key_v1($target);
            $query->execute([(int)$live['id'],$key]);
            $pending=$query->fetchAll(PDO::FETCH_COLUMN);
            if(array_filter($pending,static fn(string $goal):bool=>(json_decode($goal,true)['binding']??null)===$binding))continue;
            $slot=intdiv($now->getTimestamp(),VP3_CONNECTED_SITES_CHECK_INTERVAL_V1);
            $hash=hash('sha256',json_encode([$key,$target['checked_at'],$slot,$binding],JSON_THROW_ON_ERROR));
            vp3_initiative_enqueue_v1($pdo,$live,'initiative.prepare',[
                'binding'=>$binding,'sources'=>[],'tool'=>'connected-sites.scan',
                'connected_site_target'=>$target,'report'=>false,
            ],$hash);
        }
        $pdo->commit();
    }catch(Throwable $error){
        if($pdo->inTransaction())$pdo->rollBack();
        if(function_exists('agent_runtime_v125_trace')){
            agent_runtime_v125_trace('initiative.connected_sites_schedule_failed',
                ['user_id'=>(int)$user['id'],'error_class'=>get_class($error)]);
        }
    }
}

function vp3_initiative_connected_sites_key_v1(array $target): string
{
    $provider=match(true){
        ($target['provider']??null)==='stoneward'=>['stoneward'],
        ($target['dataset']??null)==='sales'=>['foodmonster_sales'],
        default=>[],
    };
    return 'connected-sites-scan:'.hash('sha256',json_encode([
        ...$provider,$target['connection_id'],$target['location_id'],$target['grant_revision'],
    ],JSON_THROW_ON_ERROR));
}

function vp3_initiative_connected_sites_execute_v1(PDO $pdo,array $user,array $claim,array $run,array $goal,?callable $transport=null): array
{
    $target=$goal['connected_site_target']??null;
    $stoneward=($target['provider']??null)==='stoneward';
    $sales=($target['dataset']??null)==='sales';
    if($run['action_capability_key']!=='initiative.prepare'||$goal['sources']!==[]
        ||($goal['report']??null)!==false||!is_array($target)||($sales&&$stoneward)
        ||count($target)!==($stoneward||$sales?5:4)
        ||!is_string($target['connection_id']??null)||!preg_match('/\A[a-f0-9]{32}\z/D',$target['connection_id'])
        ||!is_int($target['location_id']??null)||($stoneward?$target['location_id']!==0:$target['location_id']<1)
        ||!is_int($target['checked_at']??null)||$target['checked_at']<0
        ||!is_string($target['grant_revision']??null)||!preg_match('/\A[a-f0-9]{64}\z/D',$target['grant_revision'])
        ||$run['source_key']!==vp3_initiative_connected_sites_key_v1($target)){
        throw new RuntimeException('Connected Sites assignment changed.',403);
    }
    $authorize=static function()use($pdo,$user,$claim,$goal):array{
        $live=vp3_initiative_access_v1($pdo,$user,$goal['binding']);
        if(!agent_worker_cloud_active_row_v1910($pdo,$live,$claim,$pdo->inTransaction())){
            throw new RuntimeException('Connected Sites assignment lease changed.',409);
        }
        return $live;
    };
    $live=$authorize();
    $progress=match(true){
        $stoneward=>'Checking recent Stonefellow listening.',
        $sales=>'Checking FoodMonster daily sales.',
        default=>'Checking the connected POS location.',
    };
    if(!agent_job_heartbeat_v1900($pdo,$live,(int)$claim['run_id'],(int)$claim['action']['id'],
        (string)$claim['lease_token'],0,$progress,120)){
        throw new RuntimeException('Connected Sites assignment lease changed.',409);
    }
    if($stoneward){
        require_once __DIR__.'/agent-media-stoneward-v1.php';
        $scan=vp3_stoneward_media_scan_v1($pdo,$live,new DateTimeImmutable('now',new DateTimeZone('UTC')),$target,$transport,$authorize);
    }else{
        require_once __DIR__.'/connected-sites-reports-v1.php';
        $scan=vp3_connected_sites_scan_v1($pdo,$live,transport:$transport,target:$target,commitAuthority:$authorize);
    }
    $live=$authorize();
    $coverage=match($scan['state']??null){
        'ready','on_demand'=>'ready','partial','missing'=>'limited','authorization_required'=>'access_changed',
        'superseded','no_due_source'=>'already_checked','upgrade_required'=>'upgrade_required',default=>'unavailable',
    };
    $receipt=agent_worker_cloud_result_v1910($pdo,$live,$claim,true,'Connected site scan checked.',
        ['effect_state'=>'connected_site_scan_checked','source_coverage'=>$coverage]);
    return ['ok'=>true,'reason'=>'completed','receipt'=>$receipt];
}
