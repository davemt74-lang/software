<?php
declare(strict_types=1);

/**
 * VP3 Cognitive Operations v23.40 — Proactive Agent Now + Agent Voice.
 *
 * Presentation-only projection over the existing v23.10 Priority Queue.
 * Agent Voice enrichment is limited to persisted v5.10 return digests.
 */
const VP3_COGNITIVE_PROACTIVE_NOW_V2340='vp3-cognitive-proactive-now-v2340-20260921';
const VP3_COGNITIVE_PROACTIVE_NOW_CONTRACT_V2340='cognitive-proactive-now-v1';
const VP3_COGNITIVE_PROACTIVE_NOW_MAX_FOCUS_V2340=3;

function vp3_cognitive_proactive_now_timezone_v2340(PDO $pdo,array $user): string
{
    $timezone='UTC';
    if(function_exists('user_calendar_default_timezone_v1300')){
        try{$timezone=(string)user_calendar_default_timezone_v1300($pdo,$user)?:'UTC';}
        catch(Throwable $e){$timezone='UTC';}
    }
    try{new DateTimeZone($timezone);return $timezone;}
    catch(Throwable $e){return 'UTC';}
}

function vp3_cognitive_proactive_now_greeting_v2340(PDO $pdo,array $user): string
{
    $timezone=vp3_cognitive_proactive_now_timezone_v2340($pdo,$user);
    try{$hour=(int)(new DateTimeImmutable('now',new DateTimeZone($timezone)))->format('G');}
    catch(Throwable $e){$hour=(int)gmdate('G');}
    $daypart=$hour<12?'morning':($hour<17?'afternoon':'evening');
    $display=trim((string)($user['display_name']??''));
    $first=$display!==''?(preg_split('/\s+/u',$display)[0]??''):'';
    $first=vp3_cognitive_text_v500($first,60);
    return 'Good '.$daypart.($first!==''?', '.$first:'').'.';
}

function vp3_cognitive_proactive_now_counts_v2340(array $queue): array
{
    $source=is_array($queue['counts']??null)?$queue['counts']:[];
    $out=[];
    foreach(['needs_attention','next_up','priorities','opportunities','waiting','recent_changes'] as $lane){
        $out[$lane]=max(0,(int)($source[$lane]??0));
    }
    return $out;
}

function vp3_cognitive_proactive_now_headline_v2340(array $counts): string
{
    $attention=max(0,(int)($counts['needs_attention']??0));
    $next=max(0,(int)($counts['next_up']??0));
    $opportunities=max(0,(int)($counts['opportunities']??0));
    if($attention>0)return $attention.' thing'.($attention===1?' needs':'s need').' your attention.';
    if($next>0)return 'Your next work is lined up.';
    if($opportunities>0)return 'I found '.$opportunities.' opportunit'.($opportunities===1?'y':'ies').' worth reviewing.';
    return 'You are caught up on the current Agent queue.';
}

function vp3_cognitive_proactive_now_summary_v2340(array $counts,int $plans): string
{
    $parts=[];
    $attention=max(0,(int)($counts['needs_attention']??0));
    $next=max(0,(int)($counts['next_up']??0));
    $opportunities=max(0,(int)($counts['opportunities']??0));
    $waiting=max(0,(int)($counts['waiting']??0));
    if($attention>0)$parts[]=$attention.' attention item'.($attention===1?'':'s');
    if($next>0)$parts[]=$next.' next up';
    if($opportunities>0)$parts[]=$opportunities.' opportunit'.($opportunities===1?'y':'ies');
    if($waiting>0)$parts[]=$waiting.' waiting';
    if($plans>0)$parts[]=$plans.' active plan'.($plans===1?'':'s');
    return $parts?implode(' · ',$parts):'No higher-priority cognitive work is currently surfaced.';
}

function vp3_cognitive_proactive_now_voice_context_v2340(array $counts,int $plans): string
{
    $parts=[];
    // The persisted return digest already summarizes notification/attention
    // volume. Only append non-interruptive cognitive context here so spoken
    // briefings do not double-count the same alert.
    $opportunities=max(0,(int)($counts['opportunities']??0));
    $waiting=max(0,(int)($counts['waiting']??0));
    $priorities=max(0,(int)($counts['priorities']??0));

    if($opportunities>0)$parts[]=$opportunities.' opportunit'.($opportunities===1?'y':'ies').' to review';
    if($plans>0)$parts[]=$plans.' active plan'.($plans===1?'':'s');
    if($waiting>0)$parts[]=$waiting.' item'.($waiting===1?'':'s').' waiting';
    if($priorities>0&&count($parts)<3)$parts[]=$priorities.' current priorit'.($priorities===1?'y':'ies');

    if(!$parts)return '';
    $parts=array_slice($parts,0,3);
    if(count($parts)===1)$joined=$parts[0];
    else{
        $last=array_pop($parts);
        $joined=implode(', ',$parts).' and '.$last;
    }
    return vp3_cognitive_text_v500('Your Agent brief also has '.$joined.'.',220);
}

function vp3_cognitive_proactive_now_compose_v2340(
    PDO $pdo,array $user,string $namespace,array $priorityQueue
): array {
    $namespace=vp3_cognitive_validate_namespace_v500($pdo,$user,$namespace);
    $counts=vp3_cognitive_proactive_now_counts_v2340($priorityQueue);
    $calibration=function_exists('vp3_cognitive_calibration_state_v2350')
        ? vp3_cognitive_calibration_state_v2350($pdo,$user,$namespace)
        : ['mode'=>'balanced','effective_mode'=>'balanced','focus_limit'=>VP3_COGNITIVE_PROACTIVE_NOW_MAX_FOCUS_V2340,'return_voice_context_enabled'=>true];
    $focusLimit=max(1,min(
        VP3_COGNITIVE_PROACTIVE_NOW_MAX_FOCUS_V2340,
        (int)($calibration['focus_limit']??VP3_COGNITIVE_PROACTIVE_NOW_MAX_FOCUS_V2340)
    ));
    $plans=0;$focus=[];

    foreach((array)($priorityQueue['items']??[]) as $item){
        if(!is_array($item))continue;
        $lane=vp3_cognitive_id_v500($item['lane']??'',40);
        if(!in_array($lane,['needs_attention','next_up','opportunities','priorities','waiting','recent_changes'],true))continue;
        $key=mb_strimwidth(trim((string)($item['key']??'')),0,190,'');
        if($key==='')continue;
        if(in_array((string)($item['source']??''),['cognitive_plan','cognitive_orchestration'],true))$plans++;
        if(count($focus)>=$focusLimit)continue;
        $focus[]=[
            'key'=>$key,
            'lane'=>$lane,
            'lane_label'=>vp3_cognitive_text_v500($item['lane_label']??'',80),
            'title'=>vp3_cognitive_text_v500($item['title']??'VP3 item',190),
            'status'=>vp3_cognitive_text_v500($item['status']??'',80),
        ];
    }

    $followthrough=function_exists('vp3_cognitive_followthrough_activity_projection_v2450')
        ?vp3_cognitive_followthrough_activity_projection_v2450($pdo,$user,$namespace)
        :['focus'=>null,'counts'=>[],'open_count'=>0];
    $supervision=function_exists('vp3_cognitive_supervision_activity_projection_v2460')
        ?vp3_cognitive_supervision_activity_projection_v2460($pdo,$user,$namespace)
        :['focus'=>null,'counts'=>[],'issues'=>[]];
    $autonomy=function_exists('vp3_cognitive_autonomy_activity_projection_v2470')
        ?vp3_cognitive_autonomy_activity_projection_v2470($pdo,$user,$namespace)
        :['focus'=>null,'counts'=>[],'items'=>[]];
    $portfolio=function_exists('vp3_cognitive_portfolio_activity_projection_v2480')
        ?vp3_cognitive_portfolio_activity_projection_v2480($pdo,$user,$namespace)
        :['focus'=>null,'counts'=>[],'items'=>[],'capacity'=>[]];
    $forecast=function_exists('vp3_cognitive_forecast_activity_projection_v2490')
        ?vp3_cognitive_forecast_activity_projection_v2490($pdo,$user,$namespace)
        :['focus'=>null,'counts'=>[],'items'=>[],'conflicts'=>[]];
    $optimization=function_exists('vp3_cognitive_optimization_activity_projection_v2510')
        ?vp3_cognitive_optimization_activity_projection_v2510($pdo,$user,$namespace)
        :['recommended_strategy'=>'','focus'=>null,'counts'=>[],'scenarios'=>[]];
    $resourceBudget=function_exists('vp3_cognitive_resource_activity_projection_v2520')
        ?vp3_cognitive_resource_activity_projection_v2520($pdo,$user,$namespace)
        :['focus'=>null,'reservations'=>[],'executors'=>[],'counts'=>[]];
    $replanning=function_exists('vp3_cognitive_replanning_activity_projection_v2530')
        ?vp3_cognitive_replanning_activity_projection_v2530($pdo,$user,$namespace)
        :['health'=>'unavailable','replan_needed'=>false,'focus'=>null,'issues'=>[],'changes'=>[],'counts'=>[]];
    $commitmentProtection=function_exists('vp3_cognitive_commitment_activity_projection_v2540')
        ?vp3_cognitive_commitment_activity_projection_v2540($pdo,$user,$namespace)
        :['focus'=>null,'commitments'=>[],'counts'=>[]];
    $economics=function_exists('vp3_cognitive_economics_activity_projection_v2550')
        ?vp3_cognitive_economics_activity_projection_v2550($pdo,$user,$namespace)
        :['focus'=>null,'goals'=>[],'usage'=>[],'quota'=>[],'counts'=>[]];
    $budgetGovernance=function_exists('vp3_cognitive_budget_activity_projection_v2560')
        ?vp3_cognitive_budget_activity_projection_v2560($pdo,$user,$namespace)
        :['configured'=>false,'focus'=>null,'policies'=>[],'held_goals'=>[],'counts'=>[],'manage_url'=>''];
    $valueRoi=function_exists('vp3_cognitive_value_activity_projection_v2570')
        ?vp3_cognitive_value_activity_projection_v2570($pdo,$user,$namespace)
        :['configured'=>false,'focus'=>null,'goals'=>[],'profiles'=>[],'counts'=>[],'calibration'=>[],'manage_url'=>''];
    $decisionCalibration=function_exists('vp3_cognitive_decision_activity_projection_v2580')
        ?vp3_cognitive_decision_activity_projection_v2580($pdo,$user,$namespace,[
            'portfolio'=>$portfolio,'forecast'=>$forecast,'resource_budget'=>$resourceBudget,
            'replanning'=>$replanning,'value_roi'=>$valueRoi,
        ])
        :['ready'=>false,'calibration'=>[],'accuracy'=>[],'recent'=>[],'manage_url'=>''];
    $voiceEnabled=false;
    if(function_exists('chat_settings_get_v237')){
        try{$voiceEnabled=!empty(chat_settings_get_v237($pdo,(int)($user['id']??0))['agent_voice_enabled']);}
        catch(Throwable $e){$voiceEnabled=false;}
    }

    return [
        'contract'=>VP3_COGNITIVE_PROACTIVE_NOW_CONTRACT_V2340,
        'build'=>VP3_COGNITIVE_PROACTIVE_NOW_V2340,
        'mode'=>'selected_queue_brief',
        'greeting'=>vp3_cognitive_proactive_now_greeting_v2340($pdo,$user),
        'headline'=>vp3_cognitive_proactive_now_headline_v2340($counts),
        'summary'=>vp3_cognitive_proactive_now_summary_v2340($counts,$plans),
        'counts'=>$counts,
        'plan_count'=>$plans,
        'focus_items'=>$focus,
        'followthrough'=>[
            'focus'=>$followthrough['focus']??null,
            'counts'=>$followthrough['counts']??[],
        ],
        'supervision'=>[
            'focus'=>$supervision['focus']??null,
            'counts'=>$supervision['counts']??[],
        ],
        'autonomy'=>[
            'focus'=>$autonomy['focus']??null,
            'counts'=>$autonomy['counts']??[],
        ],
        'portfolio'=>[
            'focus'=>$portfolio['focus']??null,
            'counts'=>$portfolio['counts']??[],
            'capacity'=>$portfolio['capacity']??[],
        ],
        'forecast'=>[
            'focus'=>$forecast['focus']??null,
            'counts'=>$forecast['counts']??[],
            'conflicts'=>$forecast['conflicts']??[],
            'adaptive_sequence_goal_ids'=>$forecast['adaptive_sequence_goal_ids']??[],
        ],
        'optimization'=>[
            'recommended_strategy'=>$optimization['recommended_strategy']??'',
            'focus'=>$optimization['focus']??null,
            'counts'=>$optimization['counts']??[],
            'scenarios'=>$optimization['scenarios']??[],
        ],
        'resource_budget'=>[
            'focus'=>$resourceBudget['focus']??null,
            'counts'=>$resourceBudget['counts']??[],
            'executors'=>$resourceBudget['executors']??[],
            'reservations'=>$resourceBudget['reservations']??[],
        ],
        'replanning'=>[
            'health'=>$replanning['health']??'unavailable',
            'replan_needed'=>!empty($replanning['replan_needed']),
            'focus'=>$replanning['focus']??null,
            'counts'=>$replanning['counts']??[],
            'issues'=>$replanning['issues']??[],
            'changes'=>$replanning['changes']??[],
        ],
        'commitment_protection'=>[
            'focus'=>$commitmentProtection['focus']??null,
            'counts'=>$commitmentProtection['counts']??[],
            'commitments'=>$commitmentProtection['commitments']??[],
        ],
        'economics'=>[
            'focus'=>$economics['focus']??null,
            'counts'=>$economics['counts']??[],
            'usage'=>$economics['usage']??[],
            'quota'=>$economics['quota']??[],
            'goals'=>$economics['goals']??[],
        ],
        'budget_governance'=>[
            'configured'=>!empty($budgetGovernance['configured']),
            'focus'=>$budgetGovernance['focus']??null,
            'counts'=>$budgetGovernance['counts']??[],
            'policies'=>$budgetGovernance['policies']??[],
            'held_goals'=>$budgetGovernance['held_goals']??[],
            'manage_url'=>$budgetGovernance['manage_url']??'',
        ],
        'value_roi'=>[
            'configured'=>!empty($valueRoi['configured']),
            'focus'=>$valueRoi['focus']??null,
            'counts'=>$valueRoi['counts']??[],
            'goals'=>$valueRoi['goals']??[],
            'calibration'=>$valueRoi['calibration']??[],
            'manage_url'=>$valueRoi['manage_url']??'',
        ],
        'decision_calibration'=>[
            'ready'=>!empty($decisionCalibration['ready']),
            'calibration'=>$decisionCalibration['calibration']??[],
            'accuracy'=>$decisionCalibration['accuracy']??[],
            'recent'=>$decisionCalibration['recent']??[],
            'manage_url'=>$decisionCalibration['manage_url']??'',
        ],
        'voice'=>[
            'enabled'=>$voiceEnabled,
            'delivery_authority'=>'cognitive_presentation_v510_and_extension_notifications_v2140',
            'immediate_alerts'=>'canonical_notification_cursor_only',
            'return_briefing'=>'persisted_return_digest',
            'standalone_opportunity_voice'=>false,
            'context'=>!empty($calibration['return_voice_context_enabled'])
                ? vp3_cognitive_proactive_now_voice_context_v2340($counts,$plans)
                : '',
        ],
        'calibration'=>$calibration,
        'authority'=>[
            'item_set'=>'cognitive_feed_v530_selected_items',
            'ranking'=>'cognitive_priority_queue_v2310',
            'attention_policy'=>function_exists('vp3_cognitive_attention_owns_policy_v2410')?'cognitive_attention_v2410':'cognitive_runtime_v500',
            'delivery'=>'cognitive_presentation_v510_and_extension_notifications_v2140',
            'followthrough'=>'cognitive_followthrough_v2450',
            'supervision'=>'cognitive_supervision_v2460',
            'autonomy'=>'cognitive_autonomy_v2470',
            'portfolio'=>'cognitive_portfolio_v2480',
            'forecast'=>'cognitive_forecast_v2490',
            'optimization'=>'cognitive_optimization_v2510',
            'resource_budget'=>'cognitive_resource_budget_v2520',
            'replanning'=>'cognitive_replanning_v2530',
            'commitment_protection'=>'cognitive_commitment_protection_v2540',
            'economics'=>'cognitive_economics_v2550',
            'budget_governance'=>'cognitive_budget_governance_v2560',
            'value_roi'=>'cognitive_value_roi_v2570',
            'decision_calibration'=>'cognitive_decision_calibration_v2580',
            'automatic_external_writes'=>false,
            'approval_bypass'=>false,
            'execution_bypass'=>false,
        ],
    ];
}

function vp3_cognitive_proactive_now_digest_summary_v2340(
    PDO $pdo,array $user,string $namespace,string $baseSummary
): string {
    $baseSummary=vp3_cognitive_text_v500($baseSummary,420);
    if($baseSummary==='')return '';
    if(!function_exists('vp3_cognitive_feed_compose_v530'))return $baseSummary;

    try{$feed=vp3_cognitive_feed_compose_v530($pdo,$user,$namespace,false);}
    catch(Throwable $e){return $baseSummary;}

    $brief=is_array($feed['proactive_brief']??null)?$feed['proactive_brief']:[];
    $voice=is_array($brief['voice']??null)?$brief['voice']:[];
    $context=vp3_cognitive_text_v500($voice['context']??'',220);
    if($context==='')return $baseSummary;

    return vp3_cognitive_text_v500($baseSummary.' '.$context,600);
}
