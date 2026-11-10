<?php
declare(strict_types=1);

require_once __DIR__.'/ai-usage-accounting-v032.php';

/** Read projection only. The subscription ledger remains the sole token debit store. */
function vp3_ai_usage_report_sql_v1(PDO $pdo): string
{
    $service=vp3_ai_service_usage_ready_v1($pdo);
    $api="e.source IN ('vp3_cloud','user_provider')";
    $legacy=$service?"e.service_status='legacy'":'1=1';
    // Older writers defaulted omitted usage to zero. That is no evidence that a
    // provider call was free, even if its old row contains a zero cost estimate.
    $known=$service?'e.usage_known':'1';
    $known="CASE WHEN $api AND $legacy AND e.total_tokens=0 THEN 0 ELSE $known END";
    $status=$service?"e.service_status":"'legacy'";
    $cost="CASE WHEN $api AND ($known)=0 AND $status<>'rejected' THEN NULL ELSE e.estimated_cost_micros END";
    $fields=["e.id","'execution' origin",'e.user_id','e.agent_id','e.conversation_id','e.source','e.provider','e.model',
        'e.input_tokens','e.output_tokens','e.total_tokens',
        "CASE WHEN $api THEN e.cloud_tokens_charged ELSE 0 END cloud_tokens_charged",
        "$cost estimated_cost_micros",'e.cost_rate_source',"($known) usage_known",
        ($service?'e.usage_split_known':'1').' usage_split_known',"$status service_status",
        ($service?'e.service_key':"''").' service_key',($service?'e.service_stage':"''").' service_stage',
        ($service?'e.credential_source':"''").' credential_source',($service?'e.provider_request_id':"''").' provider_request_id',
        ($service?'e.is_background':'0').' is_background','e.run_id','e.homeserver_state','e.fallback_used','e.failure_class','e.latency_ms','e.created_at'];
    $sql='SELECT '.implode(',',$fields).' FROM ai_execution_ledger e';
    if(!table_exists('ai_usage_ledger'))return $sql;

    // Recover already-recorded historical charges which never reached the
    // execution ledger. A provider-linked debit appears exactly once, including
    // a service settlement whose cloud linkage is still being updated.
    $match='x.cloud_ledger_id=u.id';
    if($service){
        $key=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite'?"('service:' || x.service_attempt_key)":"CONCAT('service:',x.service_attempt_key)";
        $match.=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite'?' OR u.request_key='.$key:' OR BINARY u.request_key=BINARY '.$key;
    }
    [$rate,$rateSource]=vp3_ai_usage_report_rate_sql_v1($pdo,'u');
    $text=static fn(string $column):string=>$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite'?$column:'CONVERT('.$column.' USING utf8mb4) COLLATE utf8mb4_unicode_ci';
    $provider=$text('u.provider');$model=$text('u.model');$scope=$text('u.scope');
    return $sql." UNION ALL SELECT u.id,'subscription' origin,u.user_id,NULL agent_id,NULL conversation_id,
        'vp3_cloud' source,$provider provider,$model model,u.input_tokens,u.output_tokens,u.total_tokens,
        (u.package_tokens_used+u.credit_tokens_used) cloud_tokens_charged,$rate estimated_cost_micros,
        $rateSource cost_rate_source,1 usage_known,
        CASE WHEN u.input_tokens+u.output_tokens=u.total_tokens THEN 1 ELSE 0 END usage_split_known,
        'completed' service_status,'legacy' service_key,$scope service_stage,'system' credential_source,
        '' provider_request_id,0 is_background,NULL run_id,'not_used' homeserver_state,0 fallback_used,
        'none' failure_class,0 latency_ms,u.created_at
        FROM ai_usage_ledger u WHERE NOT EXISTS (SELECT 1 FROM ai_execution_ledger x
        WHERE x.user_id=u.user_id AND x.source IN ('vp3_cloud','user_provider') AND ($match))";
}

function vp3_ai_usage_report_rate_sql_v1(PDO $pdo,string $alias): array
{
    $rates=[];
    foreach(ai_usage_accounting_v032_rate_catalog() as $key=>$row){
        if(!is_string($key)||!is_array($row)||substr_count($key,':')!==1)continue;
        [$provider,$model]=explode(':',$key,2);
        if($provider===''||$model==='')continue;
        $input=$row['input_per_million_usd']??null;$output=$row['output_per_million_usd']??null;
        if(!is_numeric($input)||!is_numeric($output)||!is_finite((float)$input)||!is_finite((float)$output)||(float)$input<0||(float)$output<0)continue;
        $priority=($provider==='*'?0:2)+($model==='*'?0:1);
        $where=[];
        if($provider!=='*')$where[]='LOWER('.$alias.'.provider)='.$pdo->quote(strtolower($provider));
        if($model!=='*')$where[]=$alias.'.model='.$pdo->quote($model);
        $rates[]=['priority'=>$priority,'where'=>$where?implode(' AND ',$where):'1=1',
            'cost'=>'ROUND('.$alias.'.input_tokens*'.sprintf('%.12F',(float)$input).'+'.$alias.'.output_tokens*'.sprintf('%.12F',(float)$output).')',
            'source'=>$pdo->quote('config:'.$key)];
    }
    usort($rates,static fn(array $a,array $b):int=>$b['priority']<=>$a['priority']);
    $cost=[];$source=[];
    foreach($rates as $rate){$cost[]='WHEN '.$rate['where'].' THEN '.$rate['cost'];$source[]='WHEN '.$rate['where'].' THEN '.$rate['source'];}
    $split=$alias.'.input_tokens+'.$alias.'.output_tokens='.$alias.'.total_tokens';
    if(!$rates)return ['NULL',"'unconfigured'"];
    return ['CASE WHEN '.$split.' THEN CASE '.implode(' ',$cost).' ELSE NULL END ELSE NULL END',
        'CASE WHEN '.$split.' THEN CASE '.implode(' ',$source)." ELSE 'unconfigured' END ELSE 'unknown_usage' END"];
}

function vp3_ai_usage_report_options_v1(array $input): array
{
    $period=is_string($input['period']??null)?$input['period']:'month';
    $view=is_string($input['view']??null)?$input['view']:'providers';
    $page=is_scalar($input['page']??null)?filter_var($input['page'],FILTER_VALIDATE_INT):false;
    return ['period'=>in_array($period,['today','month','all'],true)?$period:'month',
        'view'=>in_array($view,['providers','all','local'],true)?$view:'providers',
        'page'=>max(1,min(1000000,$page===false?1:(int)$page))];
}

function vp3_ai_usage_report_v1(PDO $pdo,int $userId,array $input=[],?DateTimeImmutable $now=null,int $perPage=25): array
{
    $options=vp3_ai_usage_report_options_v1($input);$now=($now??new DateTimeImmutable('now',new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('UTC'));
    $start=match($options['period']){'today'=>$now->format('Y-m-d 00:00:00'),'month'=>$now->format('Y-m-01 00:00:00'),default=>null};
    $where='user_id=?';$params=[$userId];
    if($start!==null){$where.=' AND created_at>=?';$params[]=$start;}
    // A strict upper bound keeps future-dated records out of current totals.
    $where.=' AND created_at<=?';$params[]=$now->format('Y-m-d H:i:s');
    $from='('.vp3_ai_usage_report_sql_v1($pdo).') report';
    $aggregate="COUNT(*) requests,COALESCE(SUM(CASE WHEN source IN ('vp3_cloud','user_provider') THEN 1 ELSE 0 END),0) provider_requests,
        COALESCE(SUM(CASE WHEN source NOT IN ('vp3_cloud','user_provider') THEN 1 ELSE 0 END),0) local_requests,
        COALESCE(SUM(CASE WHEN usage_known=1 THEN total_tokens ELSE 0 END),0) total_tokens,
        COALESCE(SUM(cloud_tokens_charged),0) cloud_tokens,
        COALESCE(SUM(estimated_cost_micros),0) estimated_cost_micros,
        COALESCE(SUM(CASE WHEN estimated_cost_micros IS NULL THEN 1 ELSE 0 END),0) unknown_cost_requests,
        COALESCE(SUM(CASE WHEN usage_known=0 AND service_status<>'rejected' THEN 1 ELSE 0 END),0) unknown_usage_requests,
        COALESCE(SUM(CASE WHEN service_status='pending' THEN 1 ELSE 0 END),0) pending_requests";
    $query=$pdo->prepare('SELECT '.$aggregate.' FROM '.$from.' WHERE '.$where);$query->execute($params);$overall=$query->fetch(PDO::FETCH_ASSOC)?:[];
    if($options['view']==='providers')$where.=" AND source IN ('vp3_cloud','user_provider')";
    elseif($options['view']==='local')$where.=" AND source NOT IN ('vp3_cloud','user_provider')";
    $query=$pdo->prepare('SELECT '.$aggregate.' FROM '.$from.' WHERE '.$where);$query->execute($params);$summary=$query->fetch(PDO::FETCH_ASSOC)?:[];
    $perPage=max(1,min(100,$perPage));$pages=max(1,(int)ceil((int)$summary['requests']/$perPage));$options['page']=min($options['page'],$pages);
    $query=$pdo->prepare('SELECT * FROM '.$from.' WHERE '.$where.' ORDER BY created_at DESC,id DESC,origin DESC LIMIT '.$perPage.' OFFSET '.(($options['page']-1)*$perPage));$query->execute($params);$recent=$query->fetchAll(PDO::FETCH_ASSOC);
    $query=$pdo->prepare('SELECT provider,model,source,'.$aggregate.' FROM '.$from.' WHERE '.$where.' GROUP BY provider,model,source ORDER BY total_tokens DESC,requests DESC LIMIT 50');$query->execute($params);$models=$query->fetchAll(PDO::FETCH_ASSOC);
    return ['options'=>$options,'summary'=>$summary,'overall'=>$overall,'recent'=>$recent,'by_model'=>$models,'pages'=>$pages,'per_page'=>$perPage,'start'=>$start];
}

function vp3_ai_usage_report_cost_label_v1(array $row): string
{
    if(($row['estimated_cost_micros']??null)!==null)return ai_usage_accounting_v032_format_cost((int)$row['estimated_cost_micros']);
    if(($row['service_status']??'')==='pending')return 'Pending';
    if((int)($row['usage_known']??1)===0||($row['cost_rate_source']??'')==='unknown_usage')return 'Usage unreported';
    return 'Unpriced';
}

function vp3_ai_usage_report_export_v1(PDO $pdo,int $userId,int $limit=10000): array
{
    if($userId<1||!ai_usage_accounting_v032_schema_ready($pdo))return [];
    $limit=max(1,min(50000,$limit));
    $q=$pdo->prepare('SELECT * FROM ('.vp3_ai_usage_report_sql_v1($pdo).') report WHERE user_id=? ORDER BY created_at DESC,id DESC,origin DESC LIMIT '.$limit);
    $q->execute([$userId]);return $q->fetchAll(PDO::FETCH_ASSOC);
}
