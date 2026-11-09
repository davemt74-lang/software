<?php
declare(strict_types=1);

/** The execution ledger is the durable meter for individual provider calls. */
function vp3_ai_service_usage_columns_v1(): array
{
    return [
        'service_key'=>"VARCHAR(40) NOT NULL DEFAULT ''",
        'service_stage'=>"VARCHAR(80) NOT NULL DEFAULT ''",
        'credential_source'=>"VARCHAR(20) NOT NULL DEFAULT ''",
        'service_attempt_key'=>'CHAR(64) NULL',
        'service_job_key'=>'CHAR(64) NULL',
        'service_status'=>"VARCHAR(16) NOT NULL DEFAULT 'legacy'",
        'usage_known'=>'TINYINT NOT NULL DEFAULT 1',
        'usage_split_known'=>'TINYINT NOT NULL DEFAULT 1',
        'usage_units'=>'INT UNSIGNED NULL',
        'usage_unit'=>"VARCHAR(24) NOT NULL DEFAULT ''",
        'provider_request_id'=>"VARCHAR(160) NOT NULL DEFAULT ''",
        'is_background'=>'TINYINT NOT NULL DEFAULT 0',
        'service_budget_tokens'=>'INT UNSIGNED NOT NULL DEFAULT 0',
        'service_reservation_id'=>'BIGINT UNSIGNED NULL',
    ];
}

function vp3_ai_service_usage_ready_v1(PDO $pdo): bool
{
    try {
        $columns=implode(',',array_keys(vp3_ai_service_usage_columns_v1()));
        return $pdo->query('SELECT '.$columns.' FROM ai_execution_ledger WHERE 1=0')!==false;
    } catch (PDOException $error) {
        return false;
    }
}

function vp3_ai_service_usage_ensure_schema_v1(PDO $pdo): void
{
    $sqlite=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite';
    $existing=$pdo->query($sqlite?'PRAGMA table_info(ai_execution_ledger)':'SHOW COLUMNS FROM ai_execution_ledger')->fetchAll(PDO::FETCH_ASSOC);
    $names=array_column($existing,$sqlite?'name':'Field');
    foreach(vp3_ai_service_usage_columns_v1() as $column=>$ddl){
        if(!in_array($column,$names,true))$pdo->exec('ALTER TABLE ai_execution_ledger ADD COLUMN '.$column.' '.$ddl);
    }
    $indexes=$pdo->query($sqlite?'PRAGMA index_list(ai_execution_ledger)':'SHOW INDEX FROM ai_execution_ledger')->fetchAll(PDO::FETCH_ASSOC);
    $indexNames=array_column($indexes,$sqlite?'name':'Key_name');
    foreach([
        'uq_ai_execution_service_attempt'=>'UNIQUE INDEX %s ON ai_execution_ledger (service_attempt_key)',
        'idx_ai_execution_service_budget'=>'INDEX %s ON ai_execution_ledger (user_id,service_key,created_at)',
        'idx_ai_execution_service_job'=>'INDEX %s ON ai_execution_ledger (user_id,service_key,service_job_key)',
    ] as $name=>$ddl){
        if(!in_array($name,$indexNames,true))$pdo->exec('CREATE '.sprintf($ddl,$name));
    }
}

/** Use the same owner lock for cap calculation and for settlement. */
function vp3_ai_service_usage_lock_owner_v1(PDO $pdo,int $owner): void
{
    if($pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite'){
        // Acquire the writer lock before reading any totals on SQLite.
        $pdo->prepare('UPDATE users SET id=id WHERE id=?')->execute([$owner]);
        $query=$pdo->prepare('SELECT id FROM users WHERE id=?');
    }else{
        $query=$pdo->prepare('SELECT id FROM users WHERE id=? FOR UPDATE');
    }
    $query->execute([$owner]);
    if((int)$query->fetchColumn()!==$owner)throw new RuntimeException('AI account is unavailable.',403);
}

function vp3_ai_service_usage_limit_v1(mixed $limit): ?int
{
    if($limit===null)return null;
    if(!is_int($limit)||$limit<0)throw new InvalidArgumentException('Invalid AI service token limit.');
    return $limit;
}

function vp3_ai_service_usage_assert_funding_v1(PDO $pdo,array $user,string $credential): void
{
    if(!function_exists('vp3_user_llm_v1_funding'))throw new RuntimeException('AI account funding is unavailable.',503);
    if(vp3_user_llm_v1_funding($pdo,$user)!==$credential){
        throw new RuntimeException('The account funding choice has changed. Retry with the selected funding source.',403);
    }
}

/** Reserve against the effective service/account limits before a provider can receive a request. */
function vp3_ai_service_usage_begin_v1(PDO $pdo,array $user,string $service,string $provider,string $model,string $credential,int $inputEstimate,int $outputLimit,array $options=[]): array
{
    $owner=(int)($user['id']??0);
    if($owner<1||!preg_match('/\A[a-z][a-z0-9_]{0,39}\z/D',$service)||trim($provider)===''||trim($model)===''
        ||!in_array($credential,['system','own_key'],true)||$inputEstimate<0||$inputEstimate>2000000000
        ||$outputLimit<1||$outputLimit>100000000){
        throw new InvalidArgumentException('Invalid AI service execution.');
    }
    $background=($options['background']??false)===true;
    $limitKeys=['job_tokens','daily_tokens','monthly_tokens','background_daily_tokens','background_monthly_tokens'];
    $limits=[];
    foreach($limitKeys as $key)$limits[$key]=vp3_ai_service_usage_limit_v1($options[$key]??null);
    if(!vp3_ai_service_usage_ready_v1($pdo)){
        if(!empty($options['required'])||array_filter($limits,static fn(?int $limit):bool=>$limit!==null)){
            throw new RuntimeException('Upgrade the database to enforce AI service rules.',503);
        }
        vp3_ai_service_usage_assert_funding_v1($pdo,$user,$credential);
        return ['metered'=>false,'id'=>0,'max_output_tokens'=>$outputLimit,'request_key'=>''];
    }
    if($pdo->inTransaction())throw new LogicException('Reserve AI service usage before opening an execution transaction.');

    $run=(int)($options['run_id']??0);
    $job=is_string($options['job_key']??null)?trim($options['job_key']):'';
    $jobKey=$run>0?hash('sha256','run:'.$run):($job!==''?hash('sha256','turn:'.$job):null);
    $attempt=is_string($options['attempt_key']??null)?trim($options['attempt_key']):'';
    $attemptKey=hash('sha256',$owner.'|'.$service.'|'.($attempt!==''?$attempt:bin2hex(random_bytes(16))));
    $requestKey='service:'.$attemptKey;
    $billingScope=mb_strimwidth(trim((string)($options['billing_scope']??$service)),0,60,'');
    $source=$credential==='system'?'vp3_cloud':'user_provider';
    $pdo->beginTransaction();
    try {
        vp3_ai_service_usage_lock_owner_v1($pdo,$owner);
        vp3_ai_service_usage_assert_funding_v1($pdo,$user,$credential);
        $query=$pdo->prepare('SELECT id FROM ai_execution_ledger WHERE service_attempt_key=?');
        $query->execute([$attemptKey]);
        if($query->fetchColumn()!==false)throw new RuntimeException('This AI service attempt has already been recorded.',409);
        if(function_exists('vp3_ai_service_policy_v1')){
            // Keep the caller's stricter snapshot if limits changed while this request was queued.
            $policy=vp3_ai_service_policy_v1($pdo,$user,$service,$background);
            if(($policy['enabled']??false)!==true){
                $code=match($policy['reason']??''){'service_budget_exhausted'=>402,'service_policy_unavailable'=>503,default=>403};
                throw new RuntimeException('This AI service is unavailable for the account.',$code);
            }
            $target=['provider'=>$provider,'model'=>$model];
            if(!vp3_ai_service_target_allowed_v1($policy,$target)
                ||(!empty($policy['configured'])&&!in_array($target,[$policy['primary'],$policy['fallback']],true))){
                throw new RuntimeException('This provider and model are unavailable for the AI service.',403);
            }
            foreach($limitKeys as $key){
                $live=vp3_ai_service_usage_limit_v1($policy[$key]??null);
                if($live!==null)$limits[$key]=$limits[$key]===null?$live:min($limits[$key],$live);
            }
        }
        $now=time();
        $day=gmdate('Y-m-d 00:00:00',$now);
        $month=gmdate('Y-m-01 00:00:00',$now);
        $remaining=PHP_INT_MAX;
        foreach($limits as $key=>$limit){
            if($limit===null||(!$background&&str_starts_with($key,'background_')))continue;
            $conditions='user_id=? AND service_key=?';
            $params=[$owner,$service];
            if($key==='job_tokens'){
                if($jobKey===null){$remaining=min($remaining,$limit);continue;}
                $conditions.=' AND service_job_key=?';$params[]=$jobKey;
            }else{
                $conditions.=' AND created_at>=?';$params[]=str_contains($key,'daily')?$day:$month;
                if(str_starts_with($key,'background_'))$conditions.=' AND is_background=1';
            }
            $query=$pdo->prepare('SELECT COALESCE(SUM(service_budget_tokens),0) FROM ai_execution_ledger WHERE '.$conditions);
            $query->execute($params);
            $remaining=min($remaining,max(0,$limit-(int)$query->fetchColumn()));
        }
        $minimumOutput=min(64,$outputLimit);
        if($remaining<$inputEstimate+$minimumOutput)throw new RuntimeException('The account has reached its AI service token limit.',402);
        $maxOutput=min($outputLimit,$remaining-$inputEstimate);
        $reservationId=0;
        if($credential==='system'){
            if(!function_exists('subscription_ai_preflight'))throw new RuntimeException('AI quota storage is unavailable.',503);
            $quota=subscription_ai_preflight($user,$billingScope,$inputEstimate,$maxOutput,$pdo);
            $reservationId=(int)($quota['reservation_id']??0);
            $maxOutput=min($maxOutput,(int)($quota['max_output_tokens']??$maxOutput));
        }
        $trace=function_exists('agent_runtime_v125_trace_id')?(string)agent_runtime_v125_trace_id():'';
        $q=$pdo->prepare("INSERT INTO ai_execution_ledger
            (user_id,agent_id,conversation_id,trace_id,source,provider,model,run_id,service_key,service_stage,credential_source,
             service_attempt_key,service_job_key,service_status,usage_known,usage_split_known,is_background,service_budget_tokens,service_reservation_id,created_at,cost_rate_source)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,'pending',0,0,?,?,?,?,'unknown_usage')");
        $q->execute([$owner,max(0,(int)($options['agent_id']??0))?:null,max(0,(int)($options['conversation_id']??0))?:null,
            mb_strimwidth($trace,0,120,''),$source,mb_strimwidth($provider,0,80,''),mb_strimwidth($model,0,160,''),$run>0?$run:null,
            $service,mb_strimwidth(trim((string)($options['stage']??$service)),0,80,''),$credential,$attemptKey,$jobKey,
            $background?1:0,$inputEstimate+$maxOutput,$reservationId?:null,gmdate('Y-m-d H:i:s',$now)]);
        $id=(int)$pdo->lastInsertId();
        $pdo->commit();
        return ['metered'=>true,'id'=>$id,'max_output_tokens'=>$maxOutput,'request_key'=>$requestKey,
            'attempt_key'=>$attemptKey,'user'=>$user,'billing_scope'=>$billingScope];
    }catch(Throwable $error){
        if($pdo->inTransaction())$pdo->rollBack();
        throw $error;
    }
}

function vp3_ai_service_usage_tokens_v1(array $usage): ?array
{
    $read=static function(mixed $value):?int{
        if(!is_int($value)&&(!is_string($value)||!preg_match('/\A[0-9]{1,10}\z/D',$value)))return null;
        $value=(int)$value;
        return $value>=0&&$value<=2000000000?$value:null;
    };
    $input=$read($usage['input_tokens']??$usage['prompt_tokens']??null);
    $output=$read($usage['output_tokens']??$usage['completion_tokens']??null);
    $total=$read($usage['total_tokens']??null);
    if($total===null&&($input===null||$output===null))return null;
    return ['input_tokens'=>$input??0,'output_tokens'=>$output??0,'total_tokens'=>$total??($input+$output),
        'split_known'=>$input!==null&&$output!==null];
}

/** Confirm one provider attempt and link only its exact package debit. */
function vp3_ai_service_usage_finish_v1(PDO $pdo,array $ticket,array $usage,string $status='completed',array $options=[]): array
{
    try{
        return vp3_ai_service_usage_settle_v1($pdo,$ticket,$usage,$status,$options);
    }catch(PDOException $error){
        $driverCode=(int)($error->errorInfo[1]??0);
        if((string)$error->getCode()!=='40001'&&!in_array($driverCode,[1205,1213],true))throw $error;
        // The receipt key makes a DB-only retry safe without repeating provider work.
        usleep(random_int(20000,50000));
        return vp3_ai_service_usage_settle_v1($pdo,$ticket,$usage,$status,$options);
    }
}

function vp3_ai_service_usage_settle_v1(PDO $pdo,array $ticket,array $usage,string $status,array $options): array
{
    if(empty($ticket['metered']))return [];
    if(!in_array($status,['completed','failed','unknown','aborted'],true))throw new InvalidArgumentException('Invalid AI service usage status.');
    if($pdo->inTransaction())throw new LogicException('Settle AI service usage outside an execution transaction.');
    $user=is_array($ticket['user']??null)?$ticket['user']:[];
    $owner=(int)($user['id']??0);$id=(int)($ticket['id']??0);$key=(string)($ticket['attempt_key']??'');
    if($owner<1||$id<1||!preg_match('/\A[a-f0-9]{64}\z/D',$key))throw new InvalidArgumentException('Invalid AI service usage ticket.');
    $pdo->beginTransaction();
    try{
        vp3_ai_service_usage_lock_owner_v1($pdo,$owner);
        $q=$pdo->prepare('SELECT * FROM ai_execution_ledger WHERE id=? AND user_id=? AND service_attempt_key=?');
        $q->execute([$id,$owner,$key]);$row=$q->fetch(PDO::FETCH_ASSOC);
        if(!$row){$pdo->commit();return ['id'=>0,'cloud_tokens_charged'=>0];}
        if($row['service_status']!=='pending'){
            $pdo->commit();
            return ['id'=>$id,'cloud_tokens_charged'=>(int)$row['cloud_tokens_charged']];
        }
        $reservation=(int)($row['service_reservation_id']??0);
        if($status==='aborted'){
            if($reservation>0)$pdo->prepare('DELETE FROM ai_token_reservations WHERE id=? AND user_id=?')->execute([$reservation,$owner]);
            $pdo->prepare('DELETE FROM ai_execution_ledger WHERE id=? AND user_id=?')->execute([$id,$owner]);
            $pdo->commit();
            return ['id'=>0,'cloud_tokens_charged'=>0];
        }
        $tokens=vp3_ai_service_usage_tokens_v1($usage);
        $rejected=$tokens===null&&$status==='failed'&&($options['confirmed_no_charge']??false)===true;
        if($rejected)$status='rejected';
        $cost=$rejected?['micros'=>0,'rate_source'=>'provider_rejected']:['micros'=>null,'rate_source'=>'unknown_usage'];
        $cloudId=null;$charged=0;
        if($tokens!==null){
            if(!function_exists('ai_usage_accounting_v032_cost'))require_once __DIR__.'/ai-usage-accounting-v032.php';
            if($tokens['split_known']||$tokens['total_tokens']===0){
                $rate=ai_usage_accounting_v032_rate((string)$row['provider'],(string)$row['model']);
                $exactRate=($rate['key']??null)===$row['provider'].':'.$row['model'];
                $cost=$row['service_key']==='image_generation'&&!$exactRate&&$tokens['total_tokens']>0
                    ?['micros'=>null,'rate_source'=>'unconfigured']
                    :ai_usage_accounting_v032_cost(['source'=>$row['source'],'provider'=>$row['provider'],'model'=>$row['model'],'usage'=>$tokens]);
            }
            if($row['credential_source']==='system'&&$tokens['total_tokens']>0){
                if(!function_exists('subscription_ai_commit_usage'))throw new RuntimeException('AI quota storage is unavailable.',503);
                $requestKey='service:'.$key;
                subscription_ai_commit_usage($reservation,$user,(string)$ticket['billing_scope'],(string)$row['provider'],(string)$row['model'],$tokens,$requestKey,$pdo);
                if(function_exists('subscription_schema_ready')&&subscription_schema_ready($pdo)){
                    $q=$pdo->prepare('SELECT id,package_tokens_used,credit_tokens_used FROM ai_usage_ledger WHERE user_id=? AND request_key=?');
                    $q->execute([$owner,$requestKey]);$debit=$q->fetch(PDO::FETCH_ASSOC);
                    if($debit){$cloudId=(int)$debit['id'];$charged=(int)$debit['package_tokens_used']+(int)$debit['credit_tokens_used'];}
                }
                $reservation=0;
            }
        }
        if($reservation>0)$pdo->prepare('DELETE FROM ai_token_reservations WHERE id=? AND user_id=?')->execute([$reservation,$owner]);
        $unit=(string)($options['unit']??'');
        $units=$options['units']??null;
        if(!is_int($units)||$units<0||!preg_match('/\A[a-z][a-z0-9_]{0,23}\z/D',$unit)){$unit='';$units=null;}
        $providerRequest=(string)($options['provider_request_id']??'');
        if(!preg_match('/\A[a-zA-Z0-9_.:-]{1,160}\z/D',$providerRequest))$providerRequest='';
        $q=$pdo->prepare('UPDATE ai_execution_ledger SET service_status=?,usage_known=?,usage_split_known=?,input_tokens=?,output_tokens=?,total_tokens=?,
            service_budget_tokens=?,usage_units=?,usage_unit=?,provider_request_id=?,latency_ms=?,estimated_cost_micros=?,
            cost_rate_source=?,cloud_ledger_id=?,cloud_tokens_charged=?,service_reservation_id=NULL,failure_class=? WHERE id=? AND user_id=?');
        $q->execute([$status,$tokens!==null?1:0,!empty($tokens['split_known'])?1:0,$tokens['input_tokens']??0,$tokens['output_tokens']??0,$tokens['total_tokens']??0,
            $tokens['total_tokens']??($rejected?0:(int)$row['service_budget_tokens']),$units,$unit,$providerRequest,max(0,(int)($options['latency_ms']??0)),
            $cost['micros'],mb_strimwidth((string)$cost['rate_source'],0,40,''),$cloudId,$charged,$status==='completed'?'none':'provider_'.$status,$id,$owner]);
        $pdo->commit();
        return ['id'=>$id,'cloud_tokens_charged'=>$charged];
    }catch(Throwable $error){
        if($pdo->inTransaction())$pdo->rollBack();
        throw $error;
    }
}

/** Attach context allocated by Chat without counting the same provider call twice. */
function vp3_ai_service_usage_attach_v1(PDO $pdo,int $owner,int $agentId,int $conversationId,array $execution): bool
{
    $attempts=$execution['service_attempt_ids']??null;
    if(!is_array($attempts)||!$attempts||!vp3_ai_service_usage_ready_v1($pdo))return false;
    $ids=array_values(array_unique(array_filter(array_slice($attempts,0,32),static fn(mixed $id):bool=>is_int($id)&&$id>0)));
    if(!$ids)return false;
    $args=[$owner,...$ids];
    $where='user_id=? AND id IN ('.implode(',',array_fill(0,count($ids),'?')).') AND service_attempt_key IS NOT NULL';
    if($agentId>0){$where.=' AND (agent_id IS NULL OR agent_id=?)';$args[]=$agentId;}else $where.=' AND agent_id IS NULL';
    if($conversationId>0){$where.=' AND (conversation_id IS NULL OR conversation_id=?)';$args[]=$conversationId;}else $where.=' AND conversation_id IS NULL';
    $q=$pdo->prepare('SELECT id FROM ai_execution_ledger WHERE '.$where);
    $q->execute($args);
    if(!$q->fetchColumn())return false;
    $changes='agent_id=COALESCE(agent_id,?),conversation_id=COALESCE(conversation_id,?),homeserver_state=?,fallback_used=?';
    $values=[$agentId>0?$agentId:null,$conversationId>0?$conversationId:null,
        mb_strimwidth((string)($execution['homeserver']??'not_used'),0,30,''),!empty($execution['fallback_used'])?1:0];
    if(ai_usage_accounting_v032_route_schema_ready($pdo)){
        foreach(['runtime_version'=>16,'requested_route'=>32,'attempted_route'=>48,'actual_route'=>48,'route_reason'=>80,'fallback_reason'=>80] as $field=>$length){
            $changes.=','.$field.'=?';
            $values[]=mb_strimwidth((string)($execution[$field]??''),0,$length,'');
        }
    }
    $q=$pdo->prepare('UPDATE ai_execution_ledger SET '.$changes.' WHERE '.$where);
    $q->execute([...$values,...$args]);
    return true;
}
