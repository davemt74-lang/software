<?php
declare(strict_types=1);

/**
 * VP3 Cognitive Runtime v25.80 — Portfolio Learning & Decision Calibration.
 *
 * This layer records bounded portfolio decision snapshots, settles them against
 * canonical outcomes, and derives conservative calibration factors.
 *
 * It is NOT a second cognitive relevance learner. v5.40/v23.50 remain the
 * relevance/presentation learning authorities. v25.80 calibrates only numeric
 * portfolio projections (duration, cost, tokens, value reliability).
 */
const VP3_COGNITIVE_DECISION_CALIBRATION_V2580='vp3-cognitive-decision-calibration-v2580-20260923';
const VP3_COGNITIVE_DECISION_CALIBRATION_CONTRACT_V2580='cognitive-decision-calibration-v1';
const VP3_COGNITIVE_DECISION_CALIBRATION_MIN_SAMPLES_V2580=5;
const VP3_COGNITIVE_DECISION_CALIBRATION_WINDOW_DAYS_V2580=180;
const VP3_COGNITIVE_DECISION_CAPTURE_BUCKET_SECONDS_V2580=21600;
const VP3_COGNITIVE_DECISION_MAX_CAPTURE_V2580=8;
const VP3_COGNITIVE_DECISION_MAX_SETTLE_V2580=32;
const VP3_COGNITIVE_DECISION_MAX_ROWS_V2580=120;

function vp3_cognitive_decision_schema_ready_v2580(?PDO $pdo=null): bool
{
    $pdo??=db();
    return (bool)($pdo
        &&table_exists('cognitive_decision_snapshots_v2580')
        &&table_exists('cognitive_decision_settlements_v2580')
        &&column_exists('cognitive_decision_snapshots_v2580','snapshot_key')
        &&column_exists('cognitive_decision_snapshots_v2580','raw_forecast_likely_at')
        &&column_exists('cognitive_decision_snapshots_v2580','forecast_likely_at')
        &&column_exists('cognitive_decision_snapshots_v2580','projected_remaining_cost_micros')
        &&column_exists('cognitive_decision_snapshots_v2580','projected_remaining_tokens')
        &&column_exists('cognitive_decision_settlements_v2580','raw_forecast_error_seconds')
        &&column_exists('cognitive_decision_settlements_v2580','forecast_error_seconds')
        &&column_exists('cognitive_decision_settlements_v2580','value_realization_ratio'));
}

function vp3_cognitive_decision_ensure_schema_v2580(?PDO $pdo=null): void
{
    $pdo??=db();
    if(!$pdo)throw new RuntimeException('Database connection is unavailable.');
    $pdo->exec("CREATE TABLE IF NOT EXISTS cognitive_decision_snapshots_v2580 (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
      owner_user_id INT UNSIGNED NOT NULL,
      goal_id BIGINT UNSIGNED NOT NULL,
      snapshot_key CHAR(64) NOT NULL,
      decision_fingerprint CHAR(64) NOT NULL,
      executor VARCHAR(20) NOT NULL DEFAULT 'cloud',
      execution_state VARCHAR(32) NOT NULL DEFAULT '',
      sequence_rank INT UNSIGNED NOT NULL DEFAULT 0,
      target_date VARCHAR(32) NOT NULL DEFAULT '',
      raw_forecast_earliest_at DATETIME NULL,
      raw_forecast_likely_at DATETIME NULL,
      raw_forecast_latest_at DATETIME NULL,
      forecast_earliest_at DATETIME NULL,
      forecast_likely_at DATETIME NULL,
      forecast_latest_at DATETIME NULL,
      forecast_calibration_factor DECIMAL(8,4) NOT NULL DEFAULT 1.0000,
      forecast_confidence DECIMAL(6,4) NULL,
      historical_cost_micros BIGINT UNSIGNED NULL,
      historical_tokens BIGINT UNSIGNED NULL,
      historical_unknown_cost_requests INT UNSIGNED NOT NULL DEFAULT 0,
      raw_projected_remaining_cost_micros BIGINT UNSIGNED NULL,
      projected_remaining_cost_micros BIGINT UNSIGNED NULL,
      raw_projected_remaining_tokens BIGINT UNSIGNED NULL,
      projected_remaining_tokens BIGINT UNSIGNED NULL,
      cost_calibration_factor DECIMAL(8,4) NOT NULL DEFAULT 1.0000,
      token_calibration_factor DECIMAL(8,4) NOT NULL DEFAULT 1.0000,
      value_profile_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
      value_kind VARCHAR(16) NOT NULL DEFAULT '',
      value_currency CHAR(3) NOT NULL DEFAULT '',
      expected_value_micros BIGINT UNSIGNED NULL,
      expected_value_score SMALLINT UNSIGNED NULL,
      expected_roi_percent DECIMAL(12,4) NULL,
      value_reliability_factor DECIMAL(8,4) NOT NULL DEFAULT 1.0000,
      budget_hard_hold TINYINT(1) NOT NULL DEFAULT 0,
      reservation_state VARCHAR(24) NOT NULL DEFAULT '',
      replan_action VARCHAR(48) NOT NULL DEFAULT '',
      commitment_protected TINYINT(1) NOT NULL DEFAULT 0,
      metadata_json TEXT NULL,
      captured_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      UNIQUE KEY uq_cognitive_decision_snapshot_key_v2580 (snapshot_key),
      INDEX idx_cognitive_decision_owner_goal_v2580 (owner_user_id,goal_id,captured_at,id),
      INDEX idx_cognitive_decision_owner_executor_v2580 (owner_user_id,executor,captured_at,id),
      CONSTRAINT fk_cognitive_decision_snapshot_owner_v2580 FOREIGN KEY (owner_user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $pdo->exec("CREATE TABLE IF NOT EXISTS cognitive_decision_settlements_v2580 (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
      owner_user_id INT UNSIGNED NOT NULL,
      snapshot_id BIGINT UNSIGNED NOT NULL,
      goal_id BIGINT UNSIGNED NOT NULL,
      actual_completion_at DATETIME NOT NULL,
      actual_incremental_cost_micros BIGINT UNSIGNED NULL,
      actual_incremental_tokens BIGINT UNSIGNED NULL,
      actual_unknown_cost_requests INT UNSIGNED NOT NULL DEFAULT 0,
      realized_value_micros BIGINT UNSIGNED NULL,
      realized_value_score SMALLINT UNSIGNED NULL,
      value_currency CHAR(3) NOT NULL DEFAULT '',
      raw_forecast_error_seconds BIGINT NULL,
      forecast_error_seconds BIGINT NULL,
      forecast_window_hit TINYINT(1) NULL,
      cost_error_micros BIGINT NULL,
      cost_error_ratio DECIMAL(12,6) NULL,
      token_error BIGINT NULL,
      token_error_ratio DECIMAL(12,6) NULL,
      value_realization_ratio DECIMAL(12,6) NULL,
      realized_roi_percent DECIMAL(12,4) NULL,
      evidence_json TEXT NULL,
      settled_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      UNIQUE KEY uq_cognitive_decision_settlement_snapshot_v2580 (snapshot_id),
      INDEX idx_cognitive_decision_settlement_owner_goal_v2580 (owner_user_id,goal_id,settled_at,id),
      CONSTRAINT fk_cognitive_decision_settlement_owner_v2580 FOREIGN KEY (owner_user_id) REFERENCES users(id) ON DELETE CASCADE,
      CONSTRAINT fk_cognitive_decision_settlement_snapshot_v2580 FOREIGN KEY (snapshot_id) REFERENCES cognitive_decision_snapshots_v2580(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

function vp3_cognitive_decision_ready_v2580(?PDO $pdo=null): bool
{
    $pdo??=db();
    return (bool)($pdo
        &&vp3_cognitive_decision_schema_ready_v2580($pdo)
        &&function_exists('agent_goal_review_actual_achievement_v1714')
        &&function_exists('vp3_cognitive_forecast_snapshot_v2490')
        &&function_exists('vp3_cognitive_value_realization_v2570'));
}

function vp3_cognitive_decision_sql_datetime_v2580(mixed $value): ?string
{
    $raw=trim((string)$value);
    if($raw==='')return null;
    $ts=strtotime($raw);
    return $ts===false?null:gmdate('Y-m-d H:i:s',$ts);
}

function vp3_cognitive_decision_ratio_factor_v2580(array $values,float $min,float $max): array
{
    $clean=[];
    foreach($values as $value){
        $n=(float)$value;
        if($n>=0&&is_finite($n))$clean[]=$n;
    }
    sort($clean,SORT_NUMERIC);
    $count=count($clean);
    if($count<VP3_COGNITIVE_DECISION_CALIBRATION_MIN_SAMPLES_V2580){
        return ['factor'=>1.0,'sample_count'=>$count,'calibrated'=>false,'median_ratio'=>null];
    }
    $mid=intdiv($count,2);
    $median=$count%2?$clean[$mid]:(($clean[$mid-1]+$clean[$mid])/2);
    return [
        'factor'=>round(max($min,min($max,$median)),4),
        'sample_count'=>$count,'calibrated'=>true,'median_ratio'=>round($median,4),
    ];
}

function vp3_cognitive_decision_factor_v2580(
    PDO $pdo,array $user,string $metric,string $dimension=''
): array {
    $uid=(int)($user['id']??0);
    $metric=strtolower(trim($metric));$dimension=strtolower(trim($dimension));
    $default=['factor'=>1.0,'sample_count'=>0,'calibrated'=>false,'median_ratio'=>null,'metric'=>$metric,'dimension'=>$dimension];
    if($uid<1||!vp3_cognitive_decision_schema_ready_v2580($pdo))return $default;

    if(in_array($metric,['value_money','value_score'],true)
        &&function_exists('vp3_cognitive_value_profile_rows_v2570')
        &&function_exists('vp3_cognitive_value_realization_v2570')){
        $kind=$metric==='value_money'?'money':'score';$ratios=[];
        try{
            foreach(vp3_cognitive_value_profile_rows_v2570($pdo,$user,true) as $profile){
                if((string)($profile['value_kind']??'')!==$kind)continue;
                $realization=vp3_cognitive_value_realization_v2570($pdo,$user,$profile);
                if(empty($realization['verified']))continue;
                $expected=$kind==='money'?($profile['expected_value_micros']??null):($profile['expected_score']??null);
                $realized=$kind==='money'?($realization['value_micros']??null):($realization['score_value']??null);
                if($expected===null||$realized===null||(float)$expected<=0)continue;
                $ratio=max(0.0,(float)$realized/(float)$expected);
                if(is_finite($ratio))$ratios[]=$ratio;
            }
        }catch(Throwable $e){$ratios=[];}
        return array_merge($default,vp3_cognitive_decision_ratio_factor_v2580($ratios,.70,1.15),[
            'evidence_unit'=>'verified_value_profile'
        ]);
    }

    $where=["s.owner_user_id=?","x.settled_at>=DATE_SUB(UTC_TIMESTAMP(),INTERVAL ".VP3_COGNITIVE_DECISION_CALIBRATION_WINDOW_DAYS_V2580." DAY)"];
    $params=[$uid];
    $expr='NULL';$min=.75;$max=1.35;

    if($metric==='forecast_duration'){
        $expr="TIMESTAMPDIFF(SECOND,s.captured_at,x.actual_completion_at) / NULLIF(TIMESTAMPDIFF(SECOND,s.captured_at,s.raw_forecast_likely_at),0)";
        if(in_array($dimension,['cloud','homeserver'],true)){$where[]='s.executor=?';$params[]=$dimension;}
    }elseif($metric==='cost'){
        $expr='x.actual_incremental_cost_micros / NULLIF(s.raw_projected_remaining_cost_micros,0)';
        $where[]='x.actual_unknown_cost_requests=0';
        if(in_array($dimension,['cloud','homeserver'],true)){$where[]='s.executor=?';$params[]=$dimension;}
    }elseif($metric==='tokens'){
        $expr='x.actual_incremental_tokens / NULLIF(s.raw_projected_remaining_tokens,0)';
        if(in_array($dimension,['cloud','homeserver'],true)){$where[]='s.executor=?';$params[]=$dimension;}
    }else return $default;

    try{
        // One latest settled snapshot per goal prevents a single long-running
        // goal from overpowering calibration with many correlated snapshots.
        $sql="SELECT ({$expr}) AS ratio
          FROM cognitive_decision_settlements_v2580 x
          INNER JOIN cognitive_decision_snapshots_v2580 s ON s.id=x.snapshot_id
          INNER JOIN (
            SELECT s2.goal_id,MAX(s2.id) snapshot_id
            FROM cognitive_decision_snapshots_v2580 s2
            INNER JOIN cognitive_decision_settlements_v2580 x2 ON x2.snapshot_id=s2.id
            WHERE s2.owner_user_id=?
              AND x2.settled_at>=DATE_SUB(UTC_TIMESTAMP(),INTERVAL ".VP3_COGNITIVE_DECISION_CALIBRATION_WINDOW_DAYS_V2580." DAY)
            GROUP BY s2.goal_id
          ) latest ON latest.snapshot_id=s.id
          WHERE ".implode(' AND ',$where);
        $stmt=$pdo->prepare($sql);
        $stmt->execute(array_merge([$uid],$params));
        $values=[];
        foreach($stmt->fetchAll(PDO::FETCH_ASSOC)?:[] as $row){
            if($row['ratio']===null)continue;
            $ratio=(float)$row['ratio'];if($ratio>0&&is_finite($ratio))$values[]=$ratio;
        }
        return array_merge($default,vp3_cognitive_decision_ratio_factor_v2580($values,$min,$max),[
            'evidence_unit'=>'latest_settled_goal'
        ]);
    }catch(Throwable $e){return $default;}
}

function vp3_cognitive_decision_calibration_state_v2580(PDO $pdo,array $user): array
{
    $forecastCloud=vp3_cognitive_decision_factor_v2580($pdo,$user,'forecast_duration','cloud');
    $forecastHome=vp3_cognitive_decision_factor_v2580($pdo,$user,'forecast_duration','homeserver');
    $costCloud=vp3_cognitive_decision_factor_v2580($pdo,$user,'cost','cloud');
    $costHome=vp3_cognitive_decision_factor_v2580($pdo,$user,'cost','homeserver');
    $tokenCloud=vp3_cognitive_decision_factor_v2580($pdo,$user,'tokens','cloud');
    $tokenHome=vp3_cognitive_decision_factor_v2580($pdo,$user,'tokens','homeserver');
    $valueMoney=vp3_cognitive_decision_factor_v2580($pdo,$user,'value_money');
    $valueScore=vp3_cognitive_decision_factor_v2580($pdo,$user,'value_score');
    return [
        'build'=>VP3_COGNITIVE_DECISION_CALIBRATION_V2580,
        'window_days'=>VP3_COGNITIVE_DECISION_CALIBRATION_WINDOW_DAYS_V2580,
        'minimum_samples'=>VP3_COGNITIVE_DECISION_CALIBRATION_MIN_SAMPLES_V2580,
        'forecast'=>['cloud'=>$forecastCloud,'homeserver'=>$forecastHome],
        'cost'=>['cloud'=>$costCloud,'homeserver'=>$costHome],
        'tokens'=>['cloud'=>$tokenCloud,'homeserver'=>$tokenHome],
        'value'=>['money'=>$valueMoney,'score'=>$valueScore],
        'authority'=>[
            'calibration'=>'cognitive_decision_settlements_v2580',
            'relevance_learning'=>'cognitive_learning_v540',
            'proactive_presentation_calibration'=>'cognitive_calibration_v2350',
            'execution_authority'=>false,'approval_authority'=>false,
        ],
    ];
}

function vp3_cognitive_decision_item_map_v2580(array $rows,string $key='goal_id'): array
{
    $out=[];foreach($rows as $row){
        if(!is_array($row))continue;$id=(int)($row[$key]??0);if($id>0)$out[$id]=$row;
    }
    return $out;
}

function vp3_cognitive_decision_reservation_map_v2580(array $resourceBudget): array
{
    $out=[];
    foreach((array)($resourceBudget['reservations']??[]) as $row){
        if(!is_array($row))continue;$goalId=(int)($row['goal_id']??0);if($goalId>0)$out[$goalId]=$row;
    }
    return $out;
}

function vp3_cognitive_decision_snapshot_fingerprint_v2580(array $payload): string
{
    $stable=[
        'goal_id'=>(int)($payload['goal_id']??0),
        'executor'=>(string)($payload['executor']??'cloud'),
        'execution_state'=>(string)($payload['execution_state']??''),
        'sequence_rank'=>(int)($payload['sequence_rank']??0),
        'target_date'=>(string)($payload['target_date']??''),
        'raw_forecast_seconds'=>(int)($payload['raw_forecast_seconds']??0),
        'forecast_factor'=>round((float)($payload['forecast_calibration_factor']??1.0),4),
        'raw_cost'=>$payload['raw_projected_remaining_cost_micros']??null,
        'cost_factor'=>round((float)($payload['cost_calibration_factor']??1.0),4),
        'raw_tokens'=>$payload['raw_projected_remaining_tokens']??null,
        'token_factor'=>round((float)($payload['token_calibration_factor']??1.0),4),
        'value_profile_id'=>(int)($payload['value_profile_id']??0),
        'expected_value_micros'=>$payload['expected_value_micros']??null,
        'expected_value_score'=>$payload['expected_value_score']??null,
        'value_factor'=>round((float)($payload['value_reliability_factor']??1.0),4),
        'budget_hard_hold'=>!empty($payload['budget_hard_hold']),
        'reservation_state'=>(string)($payload['reservation_state']??''),
        'replan_action'=>(string)($payload['replan_action']??''),
        'commitment_protected'=>!empty($payload['commitment_protected']),
    ];
    return hash('sha256',json_encode($stable,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE)?:'');
}

function vp3_cognitive_decision_capture_v2580(
    PDO $pdo,array $user,array $portfolio,array $forecast,array $resourceBudget=[],
    array $replanning=[],array $valueRoi=[]
): array {
    $uid=(int)($user['id']??0);
    if($uid<1||!vp3_cognitive_decision_schema_ready_v2580($pdo))return ['captured'=>0,'skipped'=>0];

    $portfolioItems=vp3_cognitive_decision_item_map_v2580((array)($portfolio['items']??[]));
    $forecastItems=vp3_cognitive_decision_item_map_v2580((array)($forecast['items']??[]));
    $valueItems=vp3_cognitive_decision_item_map_v2580((array)($valueRoi['goals']??[]));
    $econItems=vp3_cognitive_decision_item_map_v2580((array)($portfolio['economics']['goals']??[]));
    $reservationItems=vp3_cognitive_decision_reservation_map_v2580($resourceBudget?:((array)($portfolio['resource_budget']??[])));
    $replanItems=vp3_cognitive_decision_item_map_v2580((array)($replanning['issues']??[]));
    if(!$replanItems)$replanItems=vp3_cognitive_decision_item_map_v2580((array)($replanning['changes']??[]));

    $captured=0;$skipped=0;$bucket=(int)floor(time()/VP3_COGNITIVE_DECISION_CAPTURE_BUCKET_SECONDS_V2580);
    foreach(array_slice(array_values($forecastItems),0,VP3_COGNITIVE_DECISION_MAX_CAPTURE_V2580) as $f){
        $goalId=(int)($f['goal_id']??0);$item=$portfolioItems[$goalId]??null;
        if($goalId<1||!is_array($item))continue;
        if(in_array((string)($item['execution_state']??''),['achieved','objective_achieved','archived'],true))continue;

        $value=$valueItems[$goalId]??[];$econ=$econItems[$goalId]??[];$reservation=$reservationItems[$goalId]??[];
        $budgetProjection=(array)($item['budget_projection']??[]);
        $rawLikely=vp3_cognitive_decision_sql_datetime_v2580($f['raw_likely_completion_at']??$f['likely_completion_at']??null);
        $likely=vp3_cognitive_decision_sql_datetime_v2580($f['likely_completion_at']??null);
        $capturedAt=time();
        $rawForecastSeconds=$rawLikely?((strtotime($rawLikely.' UTC')?:$capturedAt)-$capturedAt):0;
        $payload=[
            'goal_id'=>$goalId,'executor'=>(string)($f['executor']??$item['executor']??'cloud'),
            'execution_state'=>(string)($item['execution_state']??''),'sequence_rank'=>(int)($f['sequence_rank']??0),
            'target_date'=>(string)($f['target_date']??$item['target_date']??''),
            'raw_forecast_seconds'=>max(0,$rawForecastSeconds),
            'forecast_calibration_factor'=>(float)($f['calibration_factor']??1.0),
            'raw_projected_remaining_cost_micros'=>$item['budget_raw_projected_remaining_cost_micros']??($value['raw_projected_remaining_cost_micros']??null),
            'projected_remaining_cost_micros'=>$item['budget_projected_remaining_cost_micros']??($value['projected_remaining_cost_micros']??null),
            'raw_projected_remaining_tokens'=>$item['budget_raw_projected_remaining_tokens']??null,
            'projected_remaining_tokens'=>$item['budget_projected_remaining_tokens']??null,
            'cost_calibration_factor'=>(float)($item['budget_cost_calibration_factor']??1.0),
            'token_calibration_factor'=>(float)($item['budget_token_calibration_factor']??1.0),
            'value_profile_id'=>(int)($item['value_profile_id']??0),
            'expected_value_micros'=>$item['expected_value_micros']??null,
            'expected_value_score'=>$item['expected_value_score']??null,
            'value_reliability_factor'=>(float)($item['value_reliability_factor']??1.0),
            'budget_hard_hold'=>!empty($item['budget_hard_hold']),
            'reservation_state'=>(string)($reservation['state']??''),
            'replan_action'=>(string)($item['replan_action']??$item['coordination_action']??''),
            'commitment_protected'=>(float)($item['commitment_protection_score']??0)>=0.65,
        ];
        $fingerprint=vp3_cognitive_decision_snapshot_fingerprint_v2580($payload);
        $snapshotKey=hash('sha256',$uid.'|'.$goalId.'|'.$bucket.'|'.$fingerprint);
        $profile=(array)($value['profile']??[]);
        $metadata=[
            'title'=>(string)($item['title']??$f['title']??''),
            'risk'=>(string)($f['risk']??''),
            'hold_reason'=>(string)($item['hold_reason']??''),
            'value_at_risk'=>!empty($item['value_at_risk']),
            'budget_commitment_conflict'=>!empty($item['budget_commitment_conflict']),
            'replan_issues'=>$replanItems[$goalId]['issues']??[],
        ];
        try{
            $stmt=$pdo->prepare("INSERT IGNORE INTO cognitive_decision_snapshots_v2580
              (owner_user_id,goal_id,snapshot_key,decision_fingerprint,executor,execution_state,sequence_rank,target_date,
               raw_forecast_earliest_at,raw_forecast_likely_at,raw_forecast_latest_at,
               forecast_earliest_at,forecast_likely_at,forecast_latest_at,forecast_calibration_factor,forecast_confidence,
               historical_cost_micros,historical_tokens,historical_unknown_cost_requests,
               raw_projected_remaining_cost_micros,projected_remaining_cost_micros,
               raw_projected_remaining_tokens,projected_remaining_tokens,cost_calibration_factor,token_calibration_factor,
               value_profile_id,value_kind,value_currency,expected_value_micros,expected_value_score,expected_roi_percent,
               value_reliability_factor,budget_hard_hold,reservation_state,replan_action,commitment_protected,metadata_json)
              VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
            $stmt->execute([
                $uid,$goalId,$snapshotKey,$fingerprint,$payload['executor'],$payload['execution_state'],$payload['sequence_rank'],$payload['target_date'],
                vp3_cognitive_decision_sql_datetime_v2580($f['raw_earliest_completion_at']??$f['earliest_completion_at']??null),
                $rawLikely,
                vp3_cognitive_decision_sql_datetime_v2580($f['raw_latest_completion_at']??$f['latest_completion_at']??null),
                vp3_cognitive_decision_sql_datetime_v2580($f['earliest_completion_at']??null),$likely,
                vp3_cognitive_decision_sql_datetime_v2580($f['latest_completion_at']??null),
                $payload['forecast_calibration_factor'],(float)($f['confidence']['score']??0),
                isset($econ['attributed_known_cost_micros'])?(int)$econ['attributed_known_cost_micros']:null,
                isset($econ['attributed_cloud_tokens_charged'])?(int)$econ['attributed_cloud_tokens_charged']:null,
                max(0,(int)($econ['unknown_cost_requests']??0)),
                $payload['raw_projected_remaining_cost_micros'],$payload['projected_remaining_cost_micros'],
                $payload['raw_projected_remaining_tokens'],$payload['projected_remaining_tokens'],
                $payload['cost_calibration_factor'],$payload['token_calibration_factor'],
                $payload['value_profile_id'],(string)($profile['value_kind']??''),(string)($profile['currency']??''),
                $payload['expected_value_micros'],$payload['expected_value_score'],$value['expected_roi_percent']??null,
                $payload['value_reliability_factor'],$payload['budget_hard_hold']?1:0,
                $payload['reservation_state'],$payload['replan_action'],$payload['commitment_protected']?1:0,
                json_encode($metadata,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE)?:null,
            ]);
            if($stmt->rowCount()>0)$captured++;else $skipped++;
        }catch(Throwable $e){$skipped++;}
    }
    return ['captured'=>$captured,'skipped'=>$skipped];
}

function vp3_cognitive_decision_goal_run_shares_v2580(PDO $pdo,int $uid,int $goalId): array
{
    if($uid<1||$goalId<1||!table_exists('agent_goal_objectives')||!table_exists('agent_workflow_runs'))return [];
    try{
        $stmt=$pdo->prepare("SELECT r.source_hash
          FROM agent_goal_objectives l
          INNER JOIN agent_workflow_runs r ON r.id=l.objective_run_id AND r.owner_user_id=l.owner_user_id
          WHERE l.owner_user_id=? AND l.goal_id=? AND r.source_kind='objective_plan'");
        $stmt->execute([$uid,$goalId]);$hashes=array_values(array_unique(array_filter(array_map(
            static fn(array $r): string=>trim((string)($r['source_hash']??'')),
            $stmt->fetchAll(PDO::FETCH_ASSOC)?:[]
        ))));
        if(!$hashes)return [];
        $hph=implode(',',array_fill(0,count($hashes),'?'));
        $runs=$pdo->prepare("SELECT id,source_hash FROM agent_workflow_runs
          WHERE owner_user_id=? AND source_hash IN ({$hph}) AND source_kind IN ('objective_plan','objective_step')");
        $runs->execute(array_merge([$uid],$hashes));
        $goalCounts=$pdo->prepare("SELECT r.source_hash,COUNT(DISTINCT l.goal_id) goal_count
          FROM agent_goal_objectives l
          INNER JOIN agent_workflow_runs r ON r.id=l.objective_run_id AND r.owner_user_id=l.owner_user_id
          WHERE l.owner_user_id=? AND r.source_hash IN ({$hph}) AND r.source_kind='objective_plan'
          GROUP BY r.source_hash");
        $goalCounts->execute(array_merge([$uid],$hashes));$counts=[];
        foreach($goalCounts->fetchAll(PDO::FETCH_ASSOC)?:[] as $row)$counts[(string)$row['source_hash']]=max(1,(int)$row['goal_count']);
        $out=[];
        foreach($runs->fetchAll(PDO::FETCH_ASSOC)?:[] as $row){
            $id=(int)$row['id'];if($id<1)continue;
            $out[$id]=1.0/max(1,(int)($counts[(string)$row['source_hash']]??1));
        }
        return $out;
    }catch(Throwable $e){return [];}
}

function vp3_cognitive_decision_goal_usage_v2580(PDO $pdo,int $uid,int $goalId,string $since=''): array
{
    $empty=['known_cost_micros'=>0,'cloud_tokens_charged'=>0,'unknown_cost_requests'=>0,'requests'=>0];
    if($uid<1||$goalId<1||!table_exists('ai_execution_ledger'))return $empty;
    $shares=vp3_cognitive_decision_goal_run_shares_v2580($pdo,$uid,$goalId);if(!$shares)return $empty;
    $runIds=array_keys($shares);$ph=implode(',',array_fill(0,count($runIds),'?'));
    $sql="SELECT run_id,estimated_cost_micros,cloud_tokens_charged FROM ai_execution_ledger
      WHERE user_id=? AND run_id IN ({$ph})".($since!==''?' AND created_at>=?':'');
    $params=array_merge([$uid],$runIds);if($since!=='')$params[]=$since;
    try{
        $stmt=$pdo->prepare($sql);$stmt->execute($params);
        $cost=0.0;$tokens=0.0;$unknown=0;$requests=0;
        foreach($stmt->fetchAll(PDO::FETCH_ASSOC)?:[] as $row){
            $share=(float)($shares[(int)$row['run_id']]??1.0);$requests++;
            if($row['estimated_cost_micros']===null)$unknown++;
            else $cost+=max(0,(int)$row['estimated_cost_micros'])*$share;
            $tokens+=max(0,(int)($row['cloud_tokens_charged']??0))*$share;
        }
        return [
            'known_cost_micros'=>max(0,(int)round($cost)),
            'cloud_tokens_charged'=>max(0,(int)round($tokens)),
            'unknown_cost_requests'=>$unknown,'requests'=>$requests,
        ];
    }catch(Throwable $e){return $empty;}
}

function vp3_cognitive_decision_settle_v2580(PDO $pdo,array $user): array
{
    $uid=(int)($user['id']??0);
    if($uid<1||!vp3_cognitive_decision_schema_ready_v2580($pdo))return ['settled'=>0,'pending'=>0];
    $stmt=$pdo->prepare("SELECT s.* FROM cognitive_decision_snapshots_v2580 s
      LEFT JOIN cognitive_decision_settlements_v2580 x ON x.snapshot_id=s.id
      WHERE s.owner_user_id=? AND x.id IS NULL
      ORDER BY s.id ASC LIMIT ".VP3_COGNITIVE_DECISION_MAX_SETTLE_V2580);
    $stmt->execute([$uid]);$rows=$stmt->fetchAll(PDO::FETCH_ASSOC)?:[];
    $settled=0;
    foreach($rows as $row){
        $goalId=(int)$row['goal_id'];
        try{$actual=(string)(agent_goal_review_actual_achievement_v1714($pdo,$uid,$goalId)??'');}
        catch(Throwable $e){$actual='';}
        if($actual==='')continue;
        $actualTs=strtotime($actual)?:0;if($actualTs<1)continue;
        $capturedAt=(string)$row['captured_at'];
        $usage=vp3_cognitive_decision_goal_usage_v2580($pdo,$uid,$goalId,$capturedAt);
        $totalUsage=vp3_cognitive_decision_goal_usage_v2580($pdo,$uid,$goalId,'');
        $realization=null;
        $profileId=(int)($row['value_profile_id']??0);
        if($profileId>0&&function_exists('vp3_cognitive_value_profile_row_v2570')){
            try{
                $profile=vp3_cognitive_value_profile_row_v2570($pdo,$uid,$profileId);
                if($profile)$realization=vp3_cognitive_value_realization_v2570($pdo,$user,$profile);
            }catch(Throwable $e){$realization=null;}
        }

        $rawLikely=strtotime((string)($row['raw_forecast_likely_at']??''))?:0;
        $likely=strtotime((string)($row['forecast_likely_at']??''))?:0;
        $earliest=strtotime((string)($row['forecast_earliest_at']??''))?:0;
        $latest=strtotime((string)($row['forecast_latest_at']??''))?:0;
        $rawError=$rawLikely>0?$actualTs-$rawLikely:null;
        $error=$likely>0?$actualTs-$likely:null;
        $windowHit=$earliest>0&&$latest>0?($actualTs>=$earliest&&$actualTs<=$latest):null;

        $predCost=$row['raw_projected_remaining_cost_micros'];
        $actualCost=$usage['unknown_cost_requests']===0?(int)$usage['known_cost_micros']:null;
        $costError=($predCost!==null&&$actualCost!==null)?$actualCost-(int)$predCost:null;
        $costRatio=($predCost!==null&&(int)$predCost>0&&$actualCost!==null)?$actualCost/(int)$predCost:null;

        $predTokens=$row['raw_projected_remaining_tokens'];
        $actualTokens=(int)$usage['cloud_tokens_charged'];
        $tokenError=$predTokens!==null?$actualTokens-(int)$predTokens:null;
        $tokenRatio=($predTokens!==null&&(int)$predTokens>0)?$actualTokens/(int)$predTokens:null;

        $realizedMoney=is_array($realization)&&!empty($realization['verified'])&&$realization['value_micros']!==null
            ?(int)$realization['value_micros']:null;
        $realizedScore=is_array($realization)&&!empty($realization['verified'])&&$realization['score_value']!==null
            ?(int)$realization['score_value']:null;
        $valueRatio=null;
        if((string)$row['value_kind']==='money'&&$row['expected_value_micros']!==null&&$realizedMoney!==null&&(int)$row['expected_value_micros']>0){
            $valueRatio=$realizedMoney/(int)$row['expected_value_micros'];
        }elseif((string)$row['value_kind']==='score'&&$row['expected_value_score']!==null&&$realizedScore!==null&&(int)$row['expected_value_score']>0){
            $valueRatio=$realizedScore/(int)$row['expected_value_score'];
        }
        $realizedRoi=null;
        $currency=strtoupper((string)($realization['currency']??$row['value_currency']??''));
        if($currency==='USD'&&$realizedMoney!==null&&$totalUsage['unknown_cost_requests']===0&&(int)$totalUsage['known_cost_micros']>0){
            $realizedRoi=round((($realizedMoney-(int)$totalUsage['known_cost_micros'])/(int)$totalUsage['known_cost_micros'])*100,2);
        }
        $evidence=[
            'completion'=>'agent_goal_review_v1714_verified_achievement',
            'usage'=>'ai_execution_ledger_proportional_goal_attribution',
            'value'=>$realization['source']??'unverified',
            'reservation_state'=>(string)($row['reservation_state']??''),
            'replan_action'=>(string)($row['replan_action']??''),
            'budget_hard_hold'=>!empty($row['budget_hard_hold']),
            'commitment_protected'=>!empty($row['commitment_protected']),
        ];

        try{
            $ins=$pdo->prepare("INSERT IGNORE INTO cognitive_decision_settlements_v2580
              (owner_user_id,snapshot_id,goal_id,actual_completion_at,actual_incremental_cost_micros,
               actual_incremental_tokens,actual_unknown_cost_requests,realized_value_micros,realized_value_score,
               value_currency,raw_forecast_error_seconds,forecast_error_seconds,forecast_window_hit,
               cost_error_micros,cost_error_ratio,token_error,token_error_ratio,value_realization_ratio,
               realized_roi_percent,evidence_json)
              VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
            $ins->execute([
                $uid,(int)$row['id'],$goalId,gmdate('Y-m-d H:i:s',$actualTs),$actualCost,$actualTokens,
                (int)$usage['unknown_cost_requests'],$realizedMoney,$realizedScore,$currency,
                $rawError,$error,$windowHit===null?null:($windowHit?1:0),$costError,$costRatio,
                $tokenError,$tokenRatio,$valueRatio,$realizedRoi,
                json_encode($evidence,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE)?:null
            ]);
            if($ins->rowCount()>0)$settled++;
        }catch(Throwable $e){}
    }
    return ['settled'=>$settled,'pending'=>max(0,count($rows)-$settled)];
}

function vp3_cognitive_decision_accuracy_v2580(PDO $pdo,array $user): array
{
    $uid=(int)($user['id']??0);
    $out=[
        'settled_goals'=>0,'forecast_samples'=>0,'forecast_window_hit_rate'=>null,
        'median_abs_forecast_error_seconds'=>null,'median_abs_raw_forecast_error_seconds'=>null,
        'cost_samples'=>0,'median_cost_ratio'=>null,'token_samples'=>0,'median_token_ratio'=>null,
        'value_samples'=>0,'median_value_realization_ratio'=>null,
        'reservation_samples'=>0,'reservation_median_abs_forecast_error_seconds'=>null,
    ];
    if($uid<1||!vp3_cognitive_decision_schema_ready_v2580($pdo))return $out;
    try{
        $stmt=$pdo->prepare("SELECT s.goal_id,s.reservation_state,x.*
          FROM cognitive_decision_settlements_v2580 x
          INNER JOIN cognitive_decision_snapshots_v2580 s ON s.id=x.snapshot_id
          INNER JOIN (
            SELECT s2.goal_id,MAX(s2.id) snapshot_id
            FROM cognitive_decision_snapshots_v2580 s2
            INNER JOIN cognitive_decision_settlements_v2580 x2 ON x2.snapshot_id=s2.id
            WHERE s2.owner_user_id=?
              AND x2.settled_at>=DATE_SUB(UTC_TIMESTAMP(),INTERVAL ".VP3_COGNITIVE_DECISION_CALIBRATION_WINDOW_DAYS_V2580." DAY)
            GROUP BY s2.goal_id
          ) latest ON latest.snapshot_id=s.id
          WHERE s.owner_user_id=?");
        $stmt->execute([$uid,$uid]);$rows=$stmt->fetchAll(PDO::FETCH_ASSOC)?:[];
        $out['settled_goals']=count($rows);
        $forecast=[];$rawForecast=[];$hits=[];$cost=[];$tokens=[];$value=[];$reservation=[];
        foreach($rows as $row){
            if($row['forecast_error_seconds']!==null)$forecast[]=abs((int)$row['forecast_error_seconds']);
            if($row['forecast_window_hit']!==null)$hits[]=(int)$row['forecast_window_hit'];
            if($row['raw_forecast_error_seconds']!==null)$rawForecast[]=abs((int)$row['raw_forecast_error_seconds']);
            if($row['cost_error_ratio']!==null&&(float)$row['cost_error_ratio']>0)$cost[]=(float)$row['cost_error_ratio'];
            if($row['token_error_ratio']!==null&&(float)$row['token_error_ratio']>0)$tokens[]=(float)$row['token_error_ratio'];
            if($row['value_realization_ratio']!==null&&(float)$row['value_realization_ratio']>=0)$value[]=(float)$row['value_realization_ratio'];
            if(trim((string)$row['reservation_state'])!==''&&$row['forecast_error_seconds']!==null)$reservation[]=abs((int)$row['forecast_error_seconds']);
        }
        $median=static function(array $values): ?float {
            if(!$values)return null;sort($values,SORT_NUMERIC);$n=count($values);$m=intdiv($n,2);
            return $n%2?(float)$values[$m]:((float)$values[$m-1]+(float)$values[$m])/2;
        };
        $out['forecast_samples']=count($forecast);
        $out['forecast_window_hit_rate']=$hits?round(array_sum($hits)/count($hits),4):null;
        $out['median_abs_forecast_error_seconds']=$median($forecast);
        $out['median_abs_raw_forecast_error_seconds']=$median($rawForecast);
        $out['cost_samples']=count($cost);$out['median_cost_ratio']=$median($cost);
        $out['token_samples']=count($tokens);$out['median_token_ratio']=$median($tokens);
        $out['value_samples']=count($value);$out['median_value_realization_ratio']=$median($value);
        $out['reservation_samples']=count($reservation);
        $out['reservation_median_abs_forecast_error_seconds']=$median($reservation);
        return $out;
    }catch(Throwable $e){return $out;}
}

function vp3_cognitive_decision_recent_v2580(PDO $pdo,array $user,int $limit=20): array
{
    $uid=(int)($user['id']??0);$limit=max(1,min(VP3_COGNITIVE_DECISION_MAX_ROWS_V2580,$limit));
    if($uid<1||!vp3_cognitive_decision_schema_ready_v2580($pdo))return [];
    $stmt=$pdo->prepare("SELECT s.*,x.actual_completion_at,x.actual_incremental_cost_micros,
      x.actual_incremental_tokens,x.actual_unknown_cost_requests,x.realized_value_micros,
      x.realized_value_score,x.raw_forecast_error_seconds,x.forecast_error_seconds,
      x.forecast_window_hit,x.cost_error_ratio,x.token_error_ratio,x.value_realization_ratio,
      x.realized_roi_percent,x.settled_at
      FROM cognitive_decision_snapshots_v2580 s
      LEFT JOIN cognitive_decision_settlements_v2580 x ON x.snapshot_id=s.id
      WHERE s.owner_user_id=? ORDER BY s.id DESC LIMIT {$limit}");
    $stmt->execute([$uid]);return $stmt->fetchAll(PDO::FETCH_ASSOC)?:[];
}

function vp3_cognitive_decision_refresh_v2580(
    PDO $pdo,array $user,array $portfolio=[],array $forecast=[],array $resourceBudget=[],
    array $replanning=[],array $valueRoi=[]
): array {
    if(!vp3_cognitive_decision_schema_ready_v2580($pdo))return [
        'build'=>VP3_COGNITIVE_DECISION_CALIBRATION_V2580,'ready'=>false,
        'capture'=>[],'settlement'=>[],'calibration'=>[],'accuracy'=>[],'recent'=>[],
    ];
    if(!$portfolio&&function_exists('vp3_cognitive_portfolio_snapshot_v2480')){
        try{$portfolio=vp3_cognitive_portfolio_snapshot_v2480($pdo,$user);}catch(Throwable $e){$portfolio=[];}
    }
    if(!$forecast&&function_exists('vp3_cognitive_forecast_snapshot_v2490')){
        try{$forecast=vp3_cognitive_forecast_snapshot_v2490($pdo,$user);}catch(Throwable $e){$forecast=[];}
    }
    if(!$resourceBudget)$resourceBudget=(array)($portfolio['resource_budget']??[]);
    if(!$replanning)$replanning=(array)($portfolio['replanning']??[]);
    if(!$valueRoi)$valueRoi=(array)($portfolio['value_roi']??[]);
    $settlement=vp3_cognitive_decision_settle_v2580($pdo,$user);
    $capture=vp3_cognitive_decision_capture_v2580($pdo,$user,$portfolio,$forecast,$resourceBudget,$replanning,$valueRoi);
    return [
        'build'=>VP3_COGNITIVE_DECISION_CALIBRATION_V2580,'ready'=>true,
        'capture'=>$capture,'settlement'=>$settlement,
        'calibration'=>vp3_cognitive_decision_calibration_state_v2580($pdo,$user),
        'accuracy'=>vp3_cognitive_decision_accuracy_v2580($pdo,$user),
        'recent'=>vp3_cognitive_decision_recent_v2580($pdo,$user,16),
        'authority'=>[
            'decision_snapshots'=>'cognitive_decision_snapshots_v2580_append_only',
            'derived_settlements'=>'cognitive_decision_settlements_v2580_append_only',
            'goal_outcome'=>'agent_goal_review_v1714',
            'usage_cost'=>'ai_execution_ledger_v032',
            'value_evidence'=>'cognitive_value_roi_v2570_and_canonical_sources',
            'relevance_learning'=>'cognitive_learning_v540_unchanged',
            'presentation_calibration'=>'cognitive_calibration_v2350_unchanged',
            'execution_authority'=>false,'approval_authority'=>false,
        ],
    ];
}

function vp3_cognitive_decision_context_item_v2580(PDO $pdo,array $user,string $namespace): ?array
{
    if($namespace!=='system'||!vp3_cognitive_decision_schema_ready_v2580($pdo))return null;
    $state=[
        'calibration'=>vp3_cognitive_decision_calibration_state_v2580($pdo,$user),
        'accuracy'=>vp3_cognitive_decision_accuracy_v2580($pdo,$user),
    ];
    $json=json_encode($state,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE);
    if(!is_string($json))return null;
    return vp3_cognitive_context_item_v2420(
        'decision_calibration','cognitive-decision-calibration:v2580',
        'Portfolio decision calibration',$json,96.03,
        ['direct'=>true,'ephemeral_projection'=>true,'instruction_authority'=>false]
    );
}

function vp3_cognitive_decision_activity_projection_v2580(
    PDO $pdo,array $user,string $namespace,array $bundle=[]
): array {
    if($namespace!=='system'||!vp3_cognitive_decision_schema_ready_v2580($pdo))return [
        'build'=>VP3_COGNITIVE_DECISION_CALIBRATION_V2580,'ready'=>false,'calibration'=>[],
        'accuracy'=>[],'recent'=>[],'manage_url'=>'',
    ];
    $state=vp3_cognitive_decision_refresh_v2580(
        $pdo,$user,
        (array)($bundle['portfolio']??[]),(array)($bundle['forecast']??[]),
        (array)($bundle['resource_budget']??[]),(array)($bundle['replanning']??[]),
        (array)($bundle['value_roi']??[])
    );
    return [
        'build'=>VP3_COGNITIVE_DECISION_CALIBRATION_V2580,'ready'=>!empty($state['ready']),
        'calibration'=>$state['calibration']??[],'accuracy'=>$state['accuracy']??[],
        'recent'=>array_slice((array)($state['recent']??[]),0,10),
        'capture'=>$state['capture']??[],'settlement'=>$state['settlement']??[],
        'manage_url'=>url('/decision-calibration.php'),
    ];
}
