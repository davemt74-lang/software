<?php
declare(strict_types=1);

require_once __DIR__.'/agent-profile-specialist-v1.php';

/** Fixed-period prose uses verified aggregates, never mutable current backlog. */
function vp3_profile_analytics_metric_text_v1(array $metric,string $side='current'): ?string
{
    $labels=[
        'visit_records'=>'visit records','recognized_browser_contacts'=>'recognized browser contacts',
        'accepted_visitor_turns'=>'accepted visitor turns','active_conversations'=>'active conversations',
        'new_conversations'=>'new conversations','owner_takeovers'=>'owner takeovers',
        'owner_reply_messages'=>'owner reply messages','agent_reply_messages'=>'Agent reply messages',
        'calls_offered'=>'calls offered','calls_accepted'=>'calls accepted','calls_finished'=>'calls finished',
        'recorded_product_conversions'=>'recorded product conversion events',
        'recorded_booking_conversions'=>'recorded booking conversion events',
        'verified_paid_orders'=>'verified paid orders','verified_refunded_orders'=>'verified refunded orders',
        'median_first_reply_seconds'=>'seconds median first reply for new conversations',
        'median_call_accept_seconds'=>'seconds median delay before call acceptance',
    ];
    $key=$metric['key']??null;$row=$metric[$side]??null;
    if(!is_string($key)||!isset($labels[$key])||!is_array($row))return null;
    $coverage=$row['coverage']??null;
    $value=$coverage==='complete'?($row['value']??null):($coverage==='partial'?($row['observed_value']??null):null);
    if((!is_int($value)&&!is_float($value))||!is_finite((float)$value)||$value<0||$value>9007199254740991)return null;
    $seconds=in_array($key,['median_first_reply_seconds','median_call_accept_seconds'],true);
    if(!$seconds&&floor((float)$value)!=(float)$value)return null;
    $number=$seconds?rtrim(rtrim(number_format((float)$value,2,'.',''),'0'),'.'):number_format((float)$value,0,'.',',');
    return $number.' '.$labels[$key].($coverage==='partial'?' observed (partial)':'');
}

function vp3_profile_analytics_metric_pair_v1(array $metric): ?string
{
    $current=vp3_profile_analytics_metric_text_v1($metric);
    if($current===null)return null;
    if(($metric['current']['coverage']??null)==='complete'&&($metric['previous']['coverage']??null)==='complete'){
        $previous=vp3_profile_analytics_metric_text_v1($metric,'previous');
        if($previous!==null)$current.='; previous period: '.$previous;
    }
    return $current;
}

/** Ledger scale is explicit; integer strings never pass through floating-point money. */
function vp3_profile_analytics_money_text_v1(array $row,string $side='current'): ?string
{
    if(!in_array($row['key']??null,['verified_payments','verified_refunds'],true)
        ||!is_string($row['currency']??null)||!preg_match('/\A[A-Z]{3}\z/D',$row['currency'])
        ||($row['amount_unit']??null)!=='commerce_cent'||($row['scale']??null)!==2
        ||($row['scale_source']??null)!=='vp3.commerce.v800')return null;
    $part=$row[$side]??null;if(!is_array($part))return null;
    $coverage=$part['coverage']??null;
    $minor=$coverage==='complete'?($part['value_minor']??null):($coverage==='partial'?($part['observed_value_minor']??null):null);
    if(!is_string($minor)||!preg_match('/\A(?:0|[1-9][0-9]{0,59})\z/D',$minor))return null;
    $digits=str_pad($minor,3,'0',STR_PAD_LEFT);$amount=substr($digits,0,-2).'.'.substr($digits,-2);
    return $row['currency'].' '.$amount.' '.($row['key']==='verified_payments'?'verified payments':'verified refunds')
        .($coverage==='partial'?' observed (partial)':'');
}

function vp3_profile_analytics_discoverable_v1(array $data): bool
{
    if(!in_array($data['coverage']??null,['ready','limited'],true))return false;
    foreach($data['metrics']??[] as $metric){
        if(!is_array($metric))continue;
        foreach(['current','previous'] as $side){
            if(vp3_profile_analytics_metric_text_v1($metric,$side)===null)continue;
            $part=$metric[$side];$value=$part['coverage']==='complete'?$part['value']:$part['observed_value'];
            if($value>0)return true;
        }
    }
    foreach($data['money']??[] as $row){
        if(!is_array($row))continue;
        foreach(['current','previous'] as $side){
            if(vp3_profile_analytics_money_text_v1($row,$side)===null)continue;
            $part=$row[$side];$value=$part['coverage']==='complete'?$part['value_minor']:$part['observed_value_minor'];
            if($value!=='0')return true;
        }
    }
    return false;
}

/** Pure renderer shared by source discovery, exact rereads and explicit catch-up. */
function vp3_profile_analytics_source_item_v1(array $data,int $agent,int $chat): ?array
{
    if(($data['contract']??null)!=='vp3.profile-analytics-period.v1'
        ||!in_array($data['coverage']??null,['ready','limited'],true)
        ||vp3_profile_specialist_ref_kind_v1($data['ref']??null)!=='analytics'
        ||!is_string($data['revision']??null)||!preg_match('/\A[a-f0-9]{64}\z/D',$data['revision'])
        ||!is_array($data['window']??null)||!is_array($data['metrics']??null)||!array_is_list($data['metrics'])
        ||!is_array($data['money']??null)||!array_is_list($data['money']))return null;
    $window=$data['window'];
    foreach(['from_local','to_local','timezone'] as $key)if(!is_string($window[$key]??null)||$window[$key]==='')return null;
    $from=vp3_profile_specialist_text_v1($window['from_local'],40);$to=vp3_profile_specialist_text_v1($window['to_local'],40);
    $zone=vp3_profile_specialist_text_v1($window['timezone'],100);
    $title='Profile analytics: '.substr($from,0,10).' to '.substr($to,0,10).' ('.$zone.')';
    $period='Completed local period '.$from.' to '.$to.' (end excluded), '.$zone.'.';
    if(is_string($window['previous']['from_local']??null)&&is_string($window['previous']['to_local']??null))
        $period.=' Previous: '.vp3_profile_specialist_text_v1($window['previous']['from_local'],40).' to '.vp3_profile_specialist_text_v1($window['previous']['to_local'],40).' (end excluded).';
    if($data['coverage']==='limited')$period.=' Some totals are partial or unavailable.';
    $metrics=[];
    foreach($data['metrics'] as $metric)if(is_array($metric)&&is_string($metric['key']??null))$metrics[$metric['key']]=$metric;
    $facts=[$period];
    foreach([
        ['visit_records','recognized_browser_contacts'],
        ['new_conversations','accepted_visitor_turns'],
        ['calls_offered','calls_accepted','calls_finished'],
        ['recorded_product_conversions','recorded_booking_conversions','verified_paid_orders','verified_refunded_orders'],
    ] as $keys){
        $parts=[];foreach($keys as $key)if(isset($metrics[$key])&&($text=vp3_profile_analytics_metric_text_v1($metrics[$key]))!==null)$parts[]=$text;
        if($parts)$facts[]=implode('; ',$parts).'.';
    }
    $details=[];
    foreach($data['money'] as $row)if(is_array($row)&&($text=vp3_profile_analytics_money_text_v1($row))!==null)$details[]=$text;
    if(!$details)foreach(['median_first_reply_seconds','median_call_accept_seconds'] as $key)
        if(isset($metrics[$key])&&($text=vp3_profile_analytics_metric_pair_v1($metrics[$key]))!==null)$details[]=$text;
    if($details)$facts[]=implode('; ',array_slice($details,0,2)).'.';
    $summary=[];$fallback=[];
    $keys=array_values(array_unique(array_merge(['visit_records','accepted_visitor_turns','calls_accepted','verified_paid_orders'],array_keys($metrics))));
    foreach($keys as $key){
        if(!isset($metrics[$key]))continue;$metric=$metrics[$key];
        $text=vp3_profile_analytics_metric_pair_v1($metric);if($text===null)continue;
        $fallback[]=$text;$positive=false;
        foreach(['current','previous'] as $side)if(vp3_profile_analytics_metric_text_v1($metric,$side)!==null){
            $part=$metric[$side];if(($part['coverage']==='complete'?$part['value']:$part['observed_value'])>0)$positive=true;
        }
        if($positive)$summary[]=$text;
        if(count($summary)>=3)break;
    }
    if(!$summary)$summary=array_slice($fallback,0,2);
    foreach($data['money'] as $row){
        if(!is_array($row)||($text=vp3_profile_analytics_money_text_v1($row))===null)continue;
        $part=$row['current'];$value=$part['coverage']==='complete'?$part['value_minor']:$part['observed_value_minor'];
        if($value!=='0'){array_unshift($summary,$text);break;}
    }
    $summary=($data['coverage']==='limited'?'Partial coverage. ':'').implode('; ',array_slice($summary,0,4)).'.';
    if($summary==='.'||$summary==='Partial coverage. .')$summary='Period details are available with the stated source coverage.';
    $report=vp3_profile_specialist_sections_v1($facts);
    $url=vp3_profile_analytics_period_href_v1($agent,$chat,$data['ref']);
    $revision=hash('sha256',json_encode(['profile-period-report-v1',$data['revision'],$url,$title,$summary,$report],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE));
    return ['ref'=>$data['ref'],'revision'=>$revision,'specialist'=>'profile','title'=>$title,
        'summary'=>vp3_profile_specialist_text_v1($summary,1200),'next_step'=>'','question'=>'','url'=>$url,
        'status'=>'completed_period','priority'=>'low','trigger'=>'analytics_period','profile_source_kind'=>'analytics',
        'disposition'=>'current','presentation_owner'=>'profile_briefing_v1','profile_presentation_owned'=>true,
        'legacy_delivered'=>false,'private_summary_only'=>true,'public_evidence_untrusted'=>true,
        'profile_report'=>$report];
}
