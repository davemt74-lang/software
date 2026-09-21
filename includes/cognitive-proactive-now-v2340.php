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
    $attention=max(0,(int)($counts['needs_attention']??0));
    $next=max(0,(int)($counts['next_up']??0));
    $opportunities=max(0,(int)($counts['opportunities']??0));
    $waiting=max(0,(int)($counts['waiting']??0));

    if($attention>0)$parts[]=$attention.' item'.($attention===1?' needs':'s need').' attention';
    if($next>0)$parts[]=$next.' thing'.($next===1?'':'s').' next up';
    if($opportunities>0)$parts[]=$opportunities.' opportunit'.($opportunities===1?'y':'ies').' to review';
    if($waiting>0)$parts[]=$waiting.' item'.($waiting===1?'':'s').' waiting';
    if($plans>0)$parts[]=$plans.' active plan'.($plans===1?'':'s');

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
    PDO $pdo,array $user,string $namespace,array $priorityQueue,?array $operations=null
): array {
    $namespace=vp3_cognitive_validate_namespace_v500($pdo,$user,$namespace);
    $counts=vp3_cognitive_proactive_now_counts_v2340($priorityQueue);
    $plans=max(0,(int)($operations['plan_count']??0));
    $focus=[];

    foreach((array)($priorityQueue['items']??[]) as $item){
        if(!is_array($item))continue;
        $lane=vp3_cognitive_id_v500($item['lane']??'',40);
        if(!in_array($lane,['needs_attention','next_up','opportunities','priorities','waiting','recent_changes'],true))continue;
        $key=mb_strimwidth(trim((string)($item['key']??'')),0,190,'');
        if($key==='')continue;
        $focus[]=[
            'key'=>$key,
            'lane'=>$lane,
            'lane_label'=>vp3_cognitive_text_v500($item['lane_label']??'',80),
            'title'=>vp3_cognitive_text_v500($item['title']??'VP3 item',190),
            'status'=>vp3_cognitive_text_v500($item['status']??'',80),
        ];
        if(count($focus)>=VP3_COGNITIVE_PROACTIVE_NOW_MAX_FOCUS_V2340)break;
    }

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
        'voice'=>[
            'enabled'=>$voiceEnabled,
            'delivery_authority'=>'cognitive_presentation_v510_and_extension_notifications_v2140',
            'immediate_alerts'=>'canonical_notification_cursor_only',
            'return_briefing'=>'persisted_return_digest',
            'standalone_opportunity_voice'=>false,
            'context'=>vp3_cognitive_proactive_now_voice_context_v2340($counts,$plans),
        ],
        'authority'=>[
            'item_set'=>'cognitive_feed_v530_selected_items',
            'ranking'=>'cognitive_priority_queue_v2310',
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
