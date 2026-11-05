<?php
declare(strict_types=1);

/** A trusted caller may bound inference without selecting a provider or granting tools. */
function vp3_ai_with_budget_v1(int $seconds,callable $work): mixed
{
    $had=array_key_exists('VP3_AI_INFERENCE_BUDGET_V1',$GLOBALS);
    $previous=$GLOBALS['VP3_AI_INFERENCE_BUDGET_V1']??null;
    $now=hrtime(true)/1e9;
    $deadline=$now+max(1,min(80,$seconds));
    if(is_array($previous)&&is_numeric($previous['deadline']??null))$deadline=min($deadline,(float)$previous['deadline']);
    $GLOBALS['VP3_AI_INFERENCE_BUDGET_V1']=['deadline'=>$deadline];
    try{return $work();}
    finally{
        if($had)$GLOBALS['VP3_AI_INFERENCE_BUDGET_V1']=$previous;
        else unset($GLOBALS['VP3_AI_INFERENCE_BUDGET_V1']);
    }
}

/** NULL means no extra budget: ordinary Chat/phone transport remains unchanged. */
function vp3_ai_budget_remaining_v1(): ?float
{
    if(!array_key_exists('VP3_AI_INFERENCE_BUDGET_V1',$GLOBALS))return null;
    $budget=$GLOBALS['VP3_AI_INFERENCE_BUDGET_V1'];
    if(!is_array($budget)||!is_numeric($budget['deadline']??null))return 0.0;
    $deadline=(float)$budget['deadline'];
    if(!is_finite($deadline))return 0.0;
    return max(0.0,$deadline-hrtime(true)/1e9);
}

/** Retry backoff may never consume a budget and then start an unbounded attempt. */
function vp3_ai_budget_pause_v1(int $microseconds): bool
{
    $remaining=vp3_ai_budget_remaining_v1();
    if($remaining!==null&&$remaining<=$microseconds/1e6+0.005)return false;
    usleep(max(0,$microseconds));
    return true;
}
