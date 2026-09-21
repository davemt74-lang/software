<?php
declare(strict_types=1);

/**
 * VP3 Cognitive Operations v23.50 — Outcome Learning & Cognitive Calibration.
 *
 * Read-only calibration over the existing v5.40 feedback ledger plus helpers
 * that write explicit plan/handoff feedback into that same canonical ledger.
 */
const VP3_COGNITIVE_CALIBRATION_V2350='vp3-cognitive-calibration-v2350-20260921';
const VP3_COGNITIVE_CALIBRATION_WINDOW_DAYS_V2350=60;
const VP3_COGNITIVE_CALIBRATION_MIN_EVIDENCE_V2350=5;

function vp3_cognitive_calibration_signal_types_v2350(): array
{
    return [
        'plan_accepted','plan_dismissed','handoff_requested','handoff_postponed','plan_completed',
        'outcome_successful','outcome_resolved','outcome_unsuccessful','outcome_ignored',
    ];
}

function vp3_cognitive_calibration_plan_candidate_v2350(
    PDO $pdo,array $user,string $namespace,array $plan
): ?array {
    if(!function_exists('vp3_cognitive_learning_schema_ready_v540')
        ||!vp3_cognitive_learning_schema_ready_v540($pdo))return null;
    $uid=(int)($user['id']??0);if($uid<1)return null;
    $namespace=vp3_cognitive_validate_namespace_v500($pdo,$user,$namespace);
    if((int)($plan['owner_user_id']??0)!==$uid||(string)($plan['agent_namespace']??'')!==$namespace)return null;

    try{
        $ref=vp3_cognitive_planning_underlying_ref_v550($plan);
        if(!vp3_cognitive_authorize_ref_v500($pdo,$user,$namespace,$ref,'read'))return null;
    }catch(Throwable $e){return null;}

    $stmt=$pdo->prepare("SELECT * FROM cognitive_item_lifecycle_v540
      WHERE owner_user_id=? AND agent_namespace=? AND item_key=? AND item_fingerprint=? LIMIT 1");
    $stmt->execute([
        $uid,$namespace,(string)($plan['source_item_key']??''),(string)($plan['source_fingerprint']??'')
    ]);
    $life=$stmt->fetch();
    if(!is_array($life))return null;
    return vp3_cognitive_learning_candidate_from_lifecycle_v540($life);
}

function vp3_cognitive_calibration_feedback_plan_v2350(
    PDO $pdo,array $user,string $namespace,array $plan,string $eventType,array $meta=[],string $dedupe=''
): bool {
    if(!in_array($eventType,vp3_cognitive_calibration_signal_types_v2350(),true))return false;
    $candidate=vp3_cognitive_calibration_plan_candidate_v2350($pdo,$user,$namespace,$plan);
    if(!$candidate)return false;
    return vp3_cognitive_learning_feedback_v540(
        $pdo,$user,$namespace,$candidate,$eventType,$meta,
        $dedupe!==''?$dedupe:'calibration:'.bin2hex(random_bytes(10))
    );
}

function vp3_cognitive_calibration_counts_v2350(PDO $pdo,array $user,string $namespace): array
{
    $types=vp3_cognitive_calibration_signal_types_v2350();
    $out=array_fill_keys($types,0);
    if(!function_exists('vp3_cognitive_learning_schema_ready_v540')
        ||!vp3_cognitive_learning_schema_ready_v540($pdo))return $out;
    $uid=(int)($user['id']??0);if($uid<1)return $out;
    $namespace=vp3_cognitive_validate_namespace_v500($pdo,$user,$namespace);

    $quoted="'".implode("','",array_map(static fn(string $v): string=>str_replace("'","''",$v),$types))."'";
    $days=VP3_COGNITIVE_CALIBRATION_WINDOW_DAYS_V2350;
    $stmt=$pdo->prepare("SELECT event_type,COUNT(*) AS total
      FROM cognitive_feedback_events_v540
      WHERE owner_user_id=? AND agent_namespace=?
        AND created_at>=DATE_SUB(UTC_TIMESTAMP(),INTERVAL {$days} DAY)
        AND event_type IN ({$quoted})
      GROUP BY event_type");
    $stmt->execute([$uid,$namespace]);
    foreach($stmt->fetchAll()?:[] as $row){
        $type=(string)($row['event_type']??'');
        if(array_key_exists($type,$out))$out[$type]=max(0,(int)($row['total']??0));
    }
    return $out;
}

function vp3_cognitive_calibration_state_v2350(PDO $pdo,array $user,string $namespace): array
{
    $counts=vp3_cognitive_calibration_counts_v2350($pdo,$user,$namespace);
    $evidence=array_sum($counts);

    // Canonical outcomes carry the most weight. Proposal choices influence
    // presentation posture but can never overpower deterministic attention.
    $positive=
        ($counts['plan_accepted']*.5)
        +($counts['handoff_requested']*1.0)
        +($counts['plan_completed']*.5)
        +($counts['outcome_successful']*2.0)
        +($counts['outcome_resolved']*.75);
    $conservative=
        ($counts['plan_dismissed']*1.0)
        +($counts['handoff_postponed']*.25)
        +($counts['outcome_unsuccessful']*2.0)
        +($counts['outcome_ignored']*1.0);

    $calibrating=$evidence<VP3_COGNITIVE_CALIBRATION_MIN_EVIDENCE_V2350;
    $mode='balanced';
    if(!$calibrating){
        if($conservative>=3.0&&$conservative>$positive*1.15)$mode='conservative';
        elseif($positive>=6.0&&$positive>$conservative*1.5)$mode='active';
    }

    $focusLimit=$mode==='conservative'?1:3;
    $voiceContext=$mode!=='conservative';
    $explanation=match(true){
        $calibrating=>'VP3 is still gathering enough explicit decisions and canonical outcomes to calibrate proactive presentation.',
        $mode==='conservative'=>'Recent decisions and outcomes suggest a quieter proactive presentation. The underlying queue and deterministic attention rules are unchanged.',
        $mode==='active'=>'Recent decisions and outcomes support the current proactive presentation level. VP3 will not exceed the existing three-item preview.',
        default=>'Recent decisions and outcomes are mixed, so VP3 is keeping the standard proactive presentation level.',
    };

    return [
        'build'=>VP3_COGNITIVE_CALIBRATION_V2350,
        'mode'=>$calibrating?'calibrating':$mode,
        'effective_mode'=>$mode,
        'window_days'=>VP3_COGNITIVE_CALIBRATION_WINDOW_DAYS_V2350,
        'evidence_count'=>$evidence,
        'positive_signals'=>
            $counts['plan_accepted']+$counts['handoff_requested']+$counts['plan_completed']
            +$counts['outcome_successful']+$counts['outcome_resolved'],
        'conservative_signals'=>
            $counts['plan_dismissed']+$counts['handoff_postponed']
            +$counts['outcome_unsuccessful']+$counts['outcome_ignored'],
        'postponed_signals'=>$counts['handoff_postponed'],
        'focus_limit'=>$focusLimit,
        'return_voice_context_enabled'=>$voiceContext,
        'explanation'=>$explanation,
        'authority'=>[
            'ranking'=>'cognitive_learning_v540_and_priority_queue_v2310',
            'deterministic_attention_unchanged'=>true,
            'queue_reordering'=>false,
            'automatic_external_writes'=>false,
            'approval_bypass'=>false,
            'execution_bypass'=>false,
        ],
    ];
}
