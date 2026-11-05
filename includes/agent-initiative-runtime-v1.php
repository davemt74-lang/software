<?php
declare(strict_types=1);

require_once __DIR__.'/agent-initiative-v1.php';
require_once __DIR__.'/agent-worker-cloud-v1910.php';
require_once __DIR__.'/agent-worker-health-v1.php';

/** Resolve an exact private Chat before reading any scoped Initiative policy. */
function vp3_initiative_runtime_context_v1(PDO $pdo,array $user,int $agentId,int $cid): array
{
    if($agentId<0||$cid<1||(int)($user['id']??0)<1||vp3_participant_scope_v1()!==null){
        throw new RuntimeException('Initiative context is unavailable.',403);
    }
    try{$agent=vp3_agent_chat_resolve_agent_v380($pdo,$user,$agentId);}
    catch(PDOException $e){throw $e;}
    catch(RuntimeException $e){throw new RuntimeException('Initiative Agent access changed.',403,$e);}
    $principal=vp3_agent_chat_principal_v380($user,$agent);
    $live=vp3_capability_live_user_v1($pdo,$user,$principal,['conversation_id'=>$cid],null);
    if(!has_permission('account.access',$live)
        ||!vp3_agent_chat_conversation_v380($pdo,$cid,(int)$live['id'],$agent)){
        throw new RuntimeException('Initiative context is unavailable.',403);
    }
    if(!agent_brain_schema_ready()||!agent_job_engine_schema_ready_v1900($pdo)){
        throw new RuntimeException('Initiative storage is unavailable.',503);
    }
    return ['user'=>$live,'policy'=>vp3_initiative_policy_v1($live,$agentId)];
}

/** Server-only selector: never release another Agent, policy or job family. */
function vp3_initiative_runtime_runs_v1(PDO $pdo,int $uid,int $agentId,array $policy,bool $pending=false,bool $executingOnly=false): array
{
    $sqlite=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite';
    $value=static function(string $path)use($sqlite):string{
        $sql="JSON_EXTRACT(CASE WHEN JSON_VALID(r.goal) THEN r.goal ELSE '{}' END,'$path')";
        return $sqlite?$sql:"JSON_UNQUOTE($sql)";
    };
    $states=$executingOnly?" AND r.status='executing'":($pending?" AND r.status IN ('approved','executing')":"");
    $order=$pending?'ASC':'DESC';
    $sql="SELECT r.* FROM agent_workflow_runs r WHERE r.owner_user_id=? AND CAST(COALESCE(r.agent_id,0) AS CHAR)=?
        AND r.source_kind='agent_initiative' AND r.execution_target='cloud'
        AND r.capability_key IN ('initiative.plan','initiative.prepare')
        AND CAST(".$value('$.binding.conversation_id')." AS CHAR)=? AND ".$value('$.binding.revision')."=?
        $states ORDER BY r.id $order LIMIT 25";
    $stmt=$pdo->prepare($sql);
    $stmt->execute([$uid,(string)$agentId,(string)($policy['conversation_id']??0),(string)($policy['revision']??'')]);
    return array_values(array_filter($stmt->fetchAll(PDO::FETCH_ASSOC),static function(array $run)use($policy):bool{
        $payload=json_decode((string)($run['goal']??''),true);
        return is_array($payload)&&($payload['binding']??null)===$policy;
    }));
}

/** Read-only projection. Schema/configuration availability is not worker life. */
function vp3_initiative_worker_health_v1(PDO $pdo,array $user,int $agentId,int $cid): array
{
    $base=['state'=>'unavailable','reason'=>'context_unavailable','enabled'=>false,'agent_id'=>$agentId,
        'conversation_id'=>$cid,'policy_revision'=>'','paused'=>false,'policy_exists'=>false,
        'knowledge_scope'=>['mode'=>'off','folder_id'=>0],'configuration_ready'=>false,
        'execution_ready'=>false,'pump_available'=>false,'runner_state'=>'never_seen',
        'runner_observation_scope'=>'server','runner_last_success_at'=>'','last_checked_at'=>'','last_execution_at'=>'','retry_due_at'=>'','route'=>''];
    try{
        return vp3_initiative_in_scope_v1($user,$agentId,static function()use($pdo,$user,$agentId,$cid,$base):array{
            $context=vp3_initiative_runtime_context_v1($pdo,$user,$agentId,$cid);
            $live=$context['user'];$policy=$context['policy'];
            $health=$base;$health['configuration_ready']=true;
            $health['policy_exists']=$policy!==[];
            $revision=$policy['revision']??'';
            if(is_string($revision)&&preg_match('/^[a-f0-9]{32}$/D',$revision))$health['policy_revision']=$revision;
            $knowledge=$policy['knowledge_scope']??[];
            if(is_array($knowledge)&&in_array($knowledge['mode']??'', ['off','all','folder'],true)){
                $health['knowledge_scope']=['mode'=>$knowledge['mode'],
                    'folder_id'=>$knowledge['mode']==='folder'?max(0,(int)($knowledge['folder_id']??0)):0];
            }
            if($policy===[])return array_replace($health,['state'=>'starting','reason'=>'automatic_startup']);
            if(($policy['enabled']??null)===false)return array_replace($health,['state'=>'paused','reason'=>'initiative_paused','paused'=>true]);
            if(($policy['enabled']??null)!==true)return array_replace($health,['state'=>'unavailable','reason'=>'context_unavailable']);
            if(($policy['conversation_id']??null)!==$cid)return array_replace($health,['state'=>'linked_elsewhere','reason'=>'linked_elsewhere']);
            $live=vp3_initiative_access_v1($pdo,$live,$policy);
            $health['enabled']=true;$health['policy_revision']=(string)$policy['revision'];
            try{$runner=agent_worker_health_read_v1();}
            catch(Throwable $e){$runner=['state'=>'unknown','last_success_at'=>''];}
            $health['runner_state']=$runner['state'];$health['runner_last_success_at']=$runner['last_success_at'];
            $review=agent_brain_v122_memory($live,'initiative_sweep','last-review')['metadata']??[];
            if(($review['conversation_id']??null)===$cid&&($review['revision']??null)===$policy['revision']){
                $checked=strtotime((string)($review['checked_at']??''))?:0;
                if($checked>0&&$checked<=time())$health['last_checked_at']=gmdate(DATE_ATOM,$checked);
            }
            $health['state']=$health['last_checked_at']!==''?'checked':'waiting';
            $health['reason']=match($runner['state']){
                'fresh'=>$health['last_checked_at']!==''?'checked':'waiting_for_review',
                'stale'=>'worker_stale','failed'=>'worker_failed','unknown'=>'worker_observation_unavailable',default=>'worker_missing'};
            $latest=true;
            foreach(vp3_initiative_runtime_runs_v1($pdo,(int)$live['id'],$agentId,$policy) as $run){
                $completed=strtotime((string)($run['completed_at']??''))?:0;
                if($run['status']==='completed'&&$completed>0&&$completed<=time()&&$health['last_execution_at']===''){
                    $health['last_execution_at']=gmdate(DATE_ATOM,$completed);
                }
                if($run['status']==='executing'&&(strtotime((string)($run['lease_expires_at']??''))?:0)>=time()){
                    $health['state']='running';$health['reason']='reviewing';
                }
                if($latest&&$run['status']==='failed'){
                    $health['state']='failed';
                    $failure=(string)($run['last_error_class']??'');
                    $health['reason']=in_array($failure,['model_unavailable','quota_exhausted','route_unavailable'],true)?$failure:
                        ($failure==='lease_expired'?'worker_interrupted':'review_failed');
                }
                if($latest&&$run['status']==='approved'&&in_array($run['last_error_class']??'', ['model_unavailable','lease_expired'],true)){
                    $due=strtotime((string)($run['next_attempt_at']??''))?:0;
                    $health['state']='waiting';$health['reason']='retry_scheduled';
                    if($due>0)$health['retry_due_at']=gmdate(DATE_ATOM,$due);
                }
                $latest=false;
            }
            try{
                $driver=__DIR__.'/agent-initiative-model-v1.php';
                if(!function_exists('vp3_initiative_inference_health_v1')&&is_file($driver))require_once $driver;
                if(function_exists('vp3_initiative_inference_health_v1')){
                    $inference=vp3_initiative_inference_health_v1($pdo,$live,$agentId);
                }else{
                    $plan=vp3_agent_runtime_plan_v420($pdo,$live,$agentId,'chat');
                    $ready=($plan['route']??'')==='vp3_cloud'&&empty($plan['blocked']);
                    $inference=['ready'=>$ready,'route'=>(string)($plan['route']??''),'reason'=>'model_unavailable'];
                    if(($plan['cloud']['reason']??'')==='cloud_balance_exhausted')$inference['reason']='quota_exhausted';
                    elseif(str_starts_with($inference['route'],'homeserver')||($plan['effective_preference']??'')==='homeserver_only')$inference['reason']='route_unavailable';
                }
            }
            catch(Throwable $e){return array_replace($health,['state'=>'blocked','reason'=>'model_unavailable']);}
            $health['route']=(string)($inference['route']??'');
            if(empty($inference['ready'])){
                $reason=in_array($inference['reason']??'', ['quota_exhausted','model_unavailable','route_unavailable'],true)
                    ?$inference['reason']:'model_unavailable';
                return array_replace($health,['state'=>'blocked','reason'=>$reason]);
            }
            $health['execution_ready']=true;$health['pump_available']=true;
            return $health;
        });
    }catch(Throwable $e){
        $code=(int)$e->getCode();
        $reason=in_array($code,[403,404],true)?'context_unavailable':($code===503?'storage_unavailable':'runtime_unavailable');
        if($reason==='runtime_unavailable'&&function_exists('agent_runtime_v125_trace')){
            try{agent_runtime_v125_trace('agent_core.health_unavailable',['user_id'=>(int)($user['id']??0),
                'agent_id'=>$agentId,'conversation_id'=>$cid,'error_class'=>get_class($e)]);}
            catch(Throwable $ignored){}
        }
        return array_replace($base,['reason'=>$reason]);
    }
}

/**
 * Bounded authenticated POST primitive. Reuses the canonical queue, claimant,
 * lease, receipt and domain executor; it cannot pump images or public replies.
 */
function vp3_initiative_pump_v1(PDO $pdo,array $user,int $agentId,int $cid,string $expectedRevision,?callable $model=null): array
{
    return vp3_initiative_in_scope_v1($user,$agentId,static function()use($pdo,$user,$agentId,$cid,$expectedRevision,$model):array{
        $context=vp3_initiative_runtime_context_v1($pdo,$user,$agentId,$cid);
        $live=$context['user'];$policy=$context['policy'];
        if(empty($policy['enabled']))return ['ok'=>true,'reason'=>'off','health'=>vp3_initiative_worker_health_v1($pdo,$live,$agentId,$cid)];
        if(($policy['conversation_id']??null)!==$cid||!preg_match('/^[a-f0-9]{32}$/D',$expectedRevision)
            ||!hash_equals((string)($policy['revision']??''),$expectedRevision)){
            throw new RuntimeException('Initiative settings changed. Refresh Chat.',409);
        }
        $live=vp3_initiative_access_v1($pdo,$live,$policy);
        $health=vp3_initiative_worker_health_v1($pdo,$live,$agentId,$cid);
        if(empty($health['pump_available']))return ['ok'=>false,'reason'=>'blocked','health'=>$health];
        vp3_initiative_schedule_v1($pdo,$live,$agentId);
        vp3_initiative_deliver_pending_v1($pdo,$live,$agentId);
        $workerId=agent_worker_cloud_worker_id_v1910((int)$live['id']);
        $active=$pdo->prepare("SELECT COUNT(*) FROM agent_workflow_runs WHERE owner_user_id=? AND status='executing' AND lease_owner LIKE ? AND lease_expires_at>=UTC_TIMESTAMP()");
        $active->execute([(int)$live['id'],'cloud:u'.(int)$live['id'].':%']);
        if((int)$active->fetchColumn()>=4)return ['ok'=>true,'reason'=>'at_capacity','health'=>vp3_initiative_worker_health_v1($pdo,$live,$agentId,$cid)];
        $reason='no_work';
        foreach(vp3_initiative_runtime_runs_v1($pdo,(int)$live['id'],$agentId,$policy,true) as $run){
            if($run['status']==='executing'){
                if((strtotime((string)($run['lease_expires_at']??''))?:0)>=time()){$reason='already_running';continue;}
                agent_job_recover_expired_v1900($pdo,$live,1,(int)$run['id']);
            }
            $live=vp3_initiative_access_v1($pdo,$live,$policy);
            $claim=agent_job_claim_run_v1900($pdo,$live,(int)$run['id'],'cloud',$workerId,180);
            if(!$claim)continue;
            $result=vp3_initiative_execute_v1($pdo,$live,$claim,$model);
            return ['ok'=>!empty($result['ok']),'reason'=>(string)($result['reason']??'failed'),
                'health'=>vp3_initiative_worker_health_v1($pdo,$live,$agentId,$cid)];
        }
        $health=vp3_initiative_worker_health_v1($pdo,$live,$agentId,$cid);
        if($reason==='no_work'&&$health['reason']==='retry_scheduled')$reason='retry_scheduled';
        return ['ok'=>true,'reason'=>$reason,'health'=>$health];
    });
}
