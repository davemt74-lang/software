<?php
declare(strict_types=1);

const VP3_AI_USAGE_ACCOUNTING_V032='ai-usage-accounting-v032-20260909';

function ai_usage_accounting_v032_schema_ready(?PDO $pdo=null): bool
{
    $pdo??=db();
    return (bool)$pdo&&table_exists('ai_execution_ledger');
}

function ai_usage_accounting_v032_ensure_schema(?PDO $pdo=null): void
{
    $pdo??=db();
    if(!$pdo)throw new RuntimeException('Database connection is unavailable.');
    $pdo->exec("CREATE TABLE IF NOT EXISTS ai_execution_ledger (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
      user_id INT UNSIGNED NOT NULL,
      agent_id BIGINT UNSIGNED NULL,
      conversation_id BIGINT UNSIGNED NULL,
      cloud_ledger_id BIGINT UNSIGNED NULL,
      trace_id VARCHAR(120) NOT NULL DEFAULT '',
      source VARCHAR(40) NOT NULL,
      provider VARCHAR(80) NOT NULL DEFAULT '',
      model VARCHAR(160) NOT NULL DEFAULT '',
      input_tokens INT UNSIGNED NOT NULL DEFAULT 0,
      output_tokens INT UNSIGNED NOT NULL DEFAULT 0,
      total_tokens INT UNSIGNED NOT NULL DEFAULT 0,
      cloud_tokens_charged INT UNSIGNED NOT NULL DEFAULT 0,
      estimated_cost_micros BIGINT UNSIGNED NULL,
      cost_currency CHAR(3) NOT NULL DEFAULT 'USD',
      cost_rate_source VARCHAR(40) NOT NULL DEFAULT 'unconfigured',
      homeserver_state VARCHAR(30) NOT NULL DEFAULT 'not_used',
      fallback_used TINYINT(1) NOT NULL DEFAULT 0,
      failure_class VARCHAR(40) NOT NULL DEFAULT 'none',
      latency_ms INT UNSIGNED NOT NULL DEFAULT 0,
      run_id BIGINT UNSIGNED NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      INDEX idx_ai_execution_user_time (user_id,created_at,id),
      INDEX idx_ai_execution_agent_time (agent_id,created_at,id),
      INDEX idx_ai_execution_conversation (conversation_id,id),
      INDEX idx_ai_execution_source_time (source,created_at,id),
      INDEX idx_ai_execution_provider_model (provider,model,created_at,id),
      INDEX idx_ai_execution_trace (trace_id,id),
      UNIQUE KEY uq_ai_execution_cloud_ledger (cloud_ledger_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

function ai_usage_accounting_v032_rate_catalog(): array
{
    global $config;
    $catalog=is_array($config['ai_cost_rates']??null)?$config['ai_cost_rates']:[];
    return $catalog;
}

function ai_usage_accounting_v032_rate(string $provider,string $model): ?array
{
    $provider=strtolower(trim($provider));$model=trim($model);
    if($provider===''||$model==='')return null;
    $catalog=ai_usage_accounting_v032_rate_catalog();
    $keys=[$provider.':'.$model,$provider.':*','*:'.$model,'*:*'];
    foreach($keys as $key){
        $row=$catalog[$key]??null;
        if(!is_array($row))continue;
        $input=$row['input_per_million_usd']??null;$output=$row['output_per_million_usd']??null;
        if(!is_numeric($input)||!is_numeric($output)||$input<0||$output<0)continue;
        return ['input_per_million_usd'=>(float)$input,'output_per_million_usd'=>(float)$output,'key'=>$key];
    }
    return null;
}

function ai_usage_accounting_v032_cost(array $execution): array
{
    $source=(string)($execution['source']??'');
    if(in_array($source,['homeserver_local','vp3_retrieval','vp3_tool'],true))return ['micros'=>0,'rate_source'=>'local_zero'];
    $usage=is_array($execution['usage']??null)?$execution['usage']:[];
    $input=max(0,(int)($usage['prompt_tokens']??$usage['input_tokens']??0));
    $output=max(0,(int)($usage['completion_tokens']??$usage['output_tokens']??0));
    $rate=ai_usage_accounting_v032_rate((string)($execution['provider']??''),(string)($execution['model']??''));
    if(!$rate)return ['micros'=>null,'rate_source'=>'unconfigured'];
    $usd=(($input/1000000)*$rate['input_per_million_usd'])+(($output/1000000)*$rate['output_per_million_usd']);
    return ['micros'=>max(0,(int)round($usd*1000000)),'rate_source'=>'config:'.$rate['key']];
}

function ai_usage_accounting_v032_cloud_ledger(PDO $pdo,int $userId,string $traceId): ?array
{
    if($userId<1||$traceId===''||!table_exists('ai_usage_ledger'))return null;
    try{
        $stmt=$pdo->prepare('SELECT id,credit_tokens_used,package_tokens_used,total_tokens FROM ai_usage_ledger WHERE user_id=? AND trace_id=? ORDER BY id DESC LIMIT 1');
        $stmt->execute([$userId,$traceId]);return $stmt->fetch()?:null;
    }catch(Throwable $e){return null;}
}

function ai_usage_accounting_v032_record(PDO $pdo,array $user,int $agentId,int $conversationId,array $execution): void
{
    $userId=max(0,(int)($user['id']??0));if($userId<1)return;
    try{
        if(!ai_usage_accounting_v032_schema_ready($pdo))return;
        $source=(string)($execution['source']??'vp3_retrieval');
        $allowed=['homeserver_local','user_provider','vp3_cloud','vp3_retrieval','vp3_tool'];
        if(!in_array($source,$allowed,true))$source='vp3_retrieval';
        $usage=is_array($execution['usage']??null)?$execution['usage']:[];
        $input=max(0,(int)($usage['prompt_tokens']??$usage['input_tokens']??0));
        $output=max(0,(int)($usage['completion_tokens']??$usage['output_tokens']??0));
        $total=max(0,(int)($usage['total_tokens']??($input+$output)));
        $trace=function_exists('agent_runtime_v125_trace_id')?trim((string)agent_runtime_v125_trace_id()):'';
        $cloud=ai_usage_accounting_v032_cloud_ledger($pdo,$userId,$trace);
        $cloudLedgerId=$cloud?(int)$cloud['id']:null;
        $cloudCharged=max(0,(int)($execution['cloud_tokens_debited']??0));
        if($source==='vp3_cloud'&&$cloud&&$cloudCharged<1)$cloudCharged=max(0,(int)$cloud['total_tokens']);
        $cost=ai_usage_accounting_v032_cost($execution);
        $stmt=$pdo->prepare('INSERT INTO ai_execution_ledger (user_id,agent_id,conversation_id,cloud_ledger_id,trace_id,source,provider,model,input_tokens,output_tokens,total_tokens,cloud_tokens_charged,estimated_cost_micros,cost_currency,cost_rate_source,homeserver_state,fallback_used,failure_class,latency_ms,run_id) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
        $stmt->execute([
            $userId,$agentId>0?$agentId:null,$conversationId>0?$conversationId:null,$cloudLedgerId,
            mb_strimwidth($trace,0,120,''),$source,mb_strimwidth(trim((string)($execution['provider']??'')),0,80,''),mb_strimwidth(trim((string)($execution['model']??'')),0,160,''),
            $input,$output,$total,$cloudCharged,$cost['micros'],'USD',mb_strimwidth((string)$cost['rate_source'],0,40,''),
            mb_strimwidth((string)($execution['homeserver']??'not_used'),0,30,''),!empty($execution['fallback_used'])?1:0,mb_strimwidth((string)($execution['failure_class']??'none'),0,40,''),max(0,(int)($execution['latency_ms']??0)),
            (int)($execution['run_id']??0)>0?(int)$execution['run_id']:null,
        ]);
    }catch(PDOException $e){
        if((string)$e->getCode()==='23000')return;
        error_log('VP3 AI execution accounting failed: '.$e->getMessage());
    }catch(Throwable $e){error_log('VP3 AI execution accounting failed: '.$e->getMessage());}
}

function ai_usage_accounting_v032_format_cost(?int $micros): string
{
    if($micros===null)return '—';
    if($micros===0)return '$0.00';
    $usd=$micros/1000000;
    return $usd<0.01?'$'.number_format($usd,4):'$'.number_format($usd,2);
}

function ai_usage_accounting_v032_user_state(PDO $pdo,int $userId,int $limit=100): array
{
    $empty=['total_requests'=>0,'total_tokens'=>0,'cloud_tokens'=>0,'local_requests'=>0,'cloud_requests'=>0,'estimated_cost_micros'=>0,'unknown_cost_requests'=>0,'today'=>[],'month'=>[],'by_source'=>[],'by_model'=>[],'by_agent'=>[],'recent'=>[]];
    if($userId<1||!ai_usage_accounting_v032_schema_ready($pdo))return $empty;
    $summary=$pdo->prepare("SELECT COUNT(*) total_requests,COALESCE(SUM(total_tokens),0) total_tokens,COALESCE(SUM(cloud_tokens_charged),0) cloud_tokens,COALESCE(SUM(source='homeserver_local'),0) local_requests,COALESCE(SUM(source='vp3_cloud'),0) cloud_requests,COALESCE(SUM(estimated_cost_micros),0) estimated_cost_micros,COALESCE(SUM(estimated_cost_micros IS NULL),0) unknown_cost_requests FROM ai_execution_ledger WHERE user_id=?");
    $summary->execute([$userId]);$s=$summary->fetch()?:[];
    $periodQuery=static function(string $where)use($pdo,$userId):array{$stmt=$pdo->prepare("SELECT COUNT(*) requests,COALESCE(SUM(total_tokens),0) total_tokens,COALESCE(SUM(cloud_tokens_charged),0) cloud_tokens,COALESCE(SUM(estimated_cost_micros),0) estimated_cost_micros,COALESCE(SUM(estimated_cost_micros IS NULL),0) unknown_cost_requests FROM ai_execution_ledger WHERE user_id=? AND {$where}");$stmt->execute([$userId]);return $stmt->fetch()?:[];};
    $today=$periodQuery('created_at>=CURDATE()');$month=$periodQuery("created_at>=DATE_FORMAT(CURDATE(),'%Y-%m-01')");
    $bySource=$pdo->prepare('SELECT source,COUNT(*) requests,SUM(total_tokens) total_tokens,SUM(cloud_tokens_charged) cloud_tokens,COALESCE(SUM(estimated_cost_micros),0) estimated_cost_micros FROM ai_execution_ledger WHERE user_id=? GROUP BY source ORDER BY requests DESC,source');$bySource->execute([$userId]);
    $byModel=$pdo->prepare("SELECT provider,model,source,COUNT(*) requests,SUM(input_tokens) input_tokens,SUM(output_tokens) output_tokens,SUM(total_tokens) total_tokens,SUM(cloud_tokens_charged) cloud_tokens,COALESCE(SUM(estimated_cost_micros),0) estimated_cost_micros,SUM(estimated_cost_micros IS NULL) unknown_cost_requests FROM ai_execution_ledger WHERE user_id=? GROUP BY provider,model,source ORDER BY total_tokens DESC,requests DESC LIMIT 50");$byModel->execute([$userId]);
    $byAgent=[];
    if(table_exists('user_agents')){$stmt=$pdo->prepare("SELECT l.agent_id,COALESCE(a.name,'System Agent') agent_name,COUNT(*) requests,SUM(l.total_tokens) total_tokens,SUM(l.cloud_tokens_charged) cloud_tokens FROM ai_execution_ledger l LEFT JOIN user_agents a ON a.id=l.agent_id WHERE l.user_id=? GROUP BY l.agent_id,a.name ORDER BY requests DESC,total_tokens DESC LIMIT 50");$stmt->execute([$userId]);$byAgent=$stmt->fetchAll()?:[];}
    $recent=$pdo->prepare('SELECT * FROM ai_execution_ledger WHERE user_id=? ORDER BY id DESC LIMIT '.max(1,min(500,$limit)));$recent->execute([$userId]);
    return array_merge($empty,[
        'total_requests'=>(int)($s['total_requests']??0),'total_tokens'=>(int)($s['total_tokens']??0),'cloud_tokens'=>(int)($s['cloud_tokens']??0),'local_requests'=>(int)($s['local_requests']??0),'cloud_requests'=>(int)($s['cloud_requests']??0),'estimated_cost_micros'=>(int)($s['estimated_cost_micros']??0),'unknown_cost_requests'=>(int)($s['unknown_cost_requests']??0),
        'today'=>$today,'month'=>$month,'by_source'=>$bySource->fetchAll()?:[],'by_model'=>$byModel->fetchAll()?:[],'by_agent'=>$byAgent,'recent'=>$recent->fetchAll()?:[],
    ]);
}

function ai_usage_accounting_v032_admin_state(PDO $pdo,int $limit=200): array
{
    $empty=['requests'=>0,'users'=>0,'tokens'=>0,'cloud_tokens'=>0,'local_requests'=>0,'estimated_cost_micros'=>0,'unknown_cost_requests'=>0,'recent'=>[],'by_source'=>[]];
    if(!ai_usage_accounting_v032_schema_ready($pdo))return $empty;
    $s=$pdo->query("SELECT COUNT(*) requests,COUNT(DISTINCT user_id) users,COALESCE(SUM(total_tokens),0) tokens,COALESCE(SUM(cloud_tokens_charged),0) cloud_tokens,COALESCE(SUM(source='homeserver_local'),0) local_requests,COALESCE(SUM(estimated_cost_micros),0) estimated_cost_micros,COALESCE(SUM(estimated_cost_micros IS NULL),0) unknown_cost_requests FROM ai_execution_ledger")->fetch()?:[];
    $recent=$pdo->query("SELECT l.*,u.display_name user_name,u.email user_email,a.name agent_name FROM ai_execution_ledger l INNER JOIN users u ON u.id=l.user_id LEFT JOIN user_agents a ON a.id=l.agent_id ORDER BY l.id DESC LIMIT ".max(1,min(500,$limit)))->fetchAll()?:[];
    $by=$pdo->query('SELECT source,COUNT(*) requests,SUM(total_tokens) total_tokens,SUM(cloud_tokens_charged) cloud_tokens,COALESCE(SUM(estimated_cost_micros),0) estimated_cost_micros FROM ai_execution_ledger GROUP BY source ORDER BY requests DESC,source')->fetchAll()?:[];
    return ['requests'=>(int)($s['requests']??0),'users'=>(int)($s['users']??0),'tokens'=>(int)($s['tokens']??0),'cloud_tokens'=>(int)($s['cloud_tokens']??0),'local_requests'=>(int)($s['local_requests']??0),'estimated_cost_micros'=>(int)($s['estimated_cost_micros']??0),'unknown_cost_requests'=>(int)($s['unknown_cost_requests']??0),'recent'=>$recent,'by_source'=>$by];
}

function ai_usage_accounting_v032_export(PDO $pdo,?int $userId=null,int $limit=10000): array
{
    if(!ai_usage_accounting_v032_schema_ready($pdo))return [];
    $limit=max(1,min(50000,$limit));
    if($userId!==null){$stmt=$pdo->prepare('SELECT * FROM ai_execution_ledger WHERE user_id=? ORDER BY id DESC LIMIT '.$limit);$stmt->execute([$userId]);return $stmt->fetchAll()?:[];}
    return $pdo->query('SELECT * FROM ai_execution_ledger ORDER BY id DESC LIMIT '.$limit)->fetchAll()?:[];
}
