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
        'image_creations_completed'=>'completed free image creations',
        'image_initial_creations'=>'initial free image creations',
        'image_revisions_completed'=>'completed free image revisions',
        'image_requests_failed'=>'failed image requests',
        'owner_requests_submitted'=>'questions submitted to the owner',
        'knowledge_gap_requests'=>'questions with a verified knowledge gap',
        'user_response_requests'=>'questions sent directly under User responses',
        'service_unavailable_requests'=>'questions submitted after service failure',
        'quota_exhausted_requests'=>'questions submitted after an allowance limit',
        'unknown_reason_requests'=>'questions with an unknown original reason',
        'owner_requests_answered'=>'submitted questions answered by the owner',
        'owner_response_messages'=>'owner replies answering submitted questions',
        'median_owner_response_seconds'=>'seconds median owner response time from first submission',
        'p90_owner_response_seconds'=>'seconds 90th percentile owner response time from first submission',
    ];
    $key=$metric['key']??null;$row=$metric[$side]??null;
    if(!is_string($key)||!isset($labels[$key])||!is_array($row))return null;
    $coverage=$row['coverage']??null;
    $value=$coverage==='complete'?($row['value']??null):($coverage==='partial'?($row['observed_value']??null):null);
    if((!is_int($value)&&!is_float($value))||!is_finite((float)$value)||$value<0||$value>9007199254740991)return null;
    $seconds=in_array($key,['median_first_reply_seconds','median_call_accept_seconds','median_owner_response_seconds','p90_owner_response_seconds'],true);
    if(!$seconds&&floor((float)$value)!=(float)$value)return null;
    $number=$seconds?rtrim(rtrim(number_format((float)$value,2,'.',''),'0'),'.'):number_format((float)$value,0,'.',',');
    $label=$labels[$key];
    if($value==1)$label=match($key){
        'image_creations_completed'=>'completed free image creation','image_initial_creations'=>'initial free image creation',
        'image_revisions_completed'=>'completed free image revision','image_requests_failed'=>'failed image request',default=>$label,
    };
    return $number.' '.$label.($coverage==='partial'?' observed (partial)':'');
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

/** Optional image highlights use proved period totals and frozen reference names only. */
function vp3_profile_analytics_image_report_v1(array $metrics,mixed $usage): array
{
    $out=['facts'=>[],'summary'=>[],'actions'=>[],'question'=>'','next_step'=>''];
    $keys=['image_creations_completed','image_initial_creations','image_revisions_completed','image_requests_failed'];
    $values=[];$active=false;
    foreach($keys as $key){
        $metric=$metrics[$key]??[];if(!is_array($metric))continue;
        foreach(['current','previous'] as $side){
            if(vp3_profile_analytics_metric_text_v1($metric,$side)===null)continue;
            $part=$metric[$side];$number=$part['coverage']==='complete'?$part['value']:$part['observed_value'];
            $values[$key][$side]=$number;if($number>0)$active=true;
        }
    }
    if(!$active)return $out;
    $number=static fn(int|float $value):string=>number_format((float)$value,0,'.',',');
    $total=$values['image_creations_completed']['current']??null;
    $failed=$values['image_requests_failed']['current']??null;
    $overview=[];
    foreach($keys as $key)if(isset($metrics[$key])&&($text=vp3_profile_analytics_metric_text_v1($metrics[$key]))!==null)$overview[]=$text;
    if($overview)$out['facts'][]=implode('; ',$overview).'.';
    if($total!==null){
        $summary=vp3_profile_analytics_metric_text_v1($metrics['image_creations_completed']);
        $initial=$values['image_initial_creations']['current']??null;$revisions=$values['image_revisions_completed']['current']??null;
        if($initial!==null&&$revisions!==null&&$initial+$revisions===$total
            &&($metrics['image_creations_completed']['current']['coverage']??null)==='complete'
            &&($metrics['image_initial_creations']['current']['coverage']??null)==='complete'
            &&($metrics['image_revisions_completed']['current']['coverage']??null)==='complete'){
            $summary.=' ('.$number($initial).' initial; '.$number($revisions).($revisions==1?' revision':' revisions').')';
        }
        if($total>0)$out['summary'][]=$summary;
        elseif(($values['image_creations_completed']['previous']??0)>0)$out['summary'][]=vp3_profile_analytics_metric_pair_v1($metrics['image_creations_completed']);
    }
    $side=is_array($usage)&&($usage['contract']??null)==='vp3.profile-image-analytics.v1'&&is_array($usage['current']??null)?$usage['current']:[];
    $rankFacts=[];
    if($total>0&&($metrics['image_creations_completed']['current']['coverage']??null)==='complete'
        &&($side['rankings_coverage']??null)==='complete'){
        foreach(['top_catalogs'=>['collection_id','name','catalogs_truncated','catalog'],
            'top_images'=>['asset_id','title','images_truncated','reference image']] as $key=>[$id,$title,$truncated,$label]){
            $rows=$side[$key]??null;$valid=is_array($rows)&&array_is_list($rows)&&count($rows)<=10&&is_bool($side[$truncated]??null);
            $seen=[];$previousCount=null;$sum=0;
            if($valid)foreach($rows as $row){
                if(!is_array($row)||!is_int($row[$id]??null)||$row[$id]<1||$row[$id]>9007199254740991
                    ||isset($seen[$row[$id]])||!is_string($row[$title]??null)||!mb_check_encoding($row[$title],'UTF-8')
                    ||mb_strlen($row[$title])>500||!is_int($row['completed_count']??null)||$row['completed_count']<1
                    ||$row['completed_count']>$total||($previousCount!==null&&$row['completed_count']>$previousCount)){$valid=false;break;}
                if($key==='top_catalogs'&&(!is_int($row['initial_count']??null)||!is_int($row['revision_count']??null)
                    ||$row['initial_count']<0||$row['revision_count']<0||$row['initial_count']+$row['revision_count']!==$row['completed_count'])){$valid=false;break;}
                $seen[$row[$id]]=true;$previousCount=$row['completed_count'];$sum+=$row['completed_count'];
            }
            if(!$valid||!$rows||($key==='top_catalogs'&&$sum>$total))continue;
            $top=$rows[0];$name=vp3_profile_specialist_text_v1($top[$title],60);
            if($name==='')$name=ucfirst($label).' '.$top[$id];
            $tie=(isset($rows[1])&&$rows[1]['completed_count']===$top['completed_count'])||($side[$truncated]&&count($rows)===1);
            $rankFacts[]=($tie?'One of the most-used '.$label.'s':'Most-used '.$label).': “'.$name.'”, used in '.$number($top['completed_count']).' completed creations.';
            if($key==='top_catalogs')$out['summary'][]=($tie?'Top catalog tie':'Top catalog').': “'.$name.'” ('.$number($top['completed_count']).')';
        }
    }
    if($rankFacts)$out['facts'][]=implode(' ',$rankFacts);
    if($failed>0){
        $out['summary'][]=vp3_profile_analytics_metric_text_v1($metrics['image_requests_failed']);
        $reasons=['provider_access'=>'provider access','provider_busy'=>'provider busy','request_rejected'=>'request rejected',
            'result_unconfirmed'=>'result not confirmed','usage_unconfirmed'=>'processing usage not confirmed',
            'image_too_large'=>'image too large','storage_unavailable'=>'result storage unavailable',
            'allowance_unavailable'=>'allowance unavailable','provider_unavailable'=>'image provider unavailable',
            'source_changed'=>'reference or conversation changed','other'=>'other processing failures'];
        $rows=$side['failures']??null;$parts=[];$seen=[];$sum=0;
        $valid=in_array($side['failure_coverage']??null,['complete','partial'],true)&&is_array($rows)&&array_is_list($rows)&&count($rows)<=count($reasons);
        if($valid)foreach($rows as $row){
            if(!is_array($row)){$valid=false;break;}
            $code=$row['code']??null;$count=$row['count']??null;
            if(!is_string($code)||!isset($reasons[$code])||isset($seen[$code])||!is_int($count)||$count<1||$count>$failed){$valid=false;break;}
            $sum+=$count;$seen[$code]=true;$parts[]=$reasons[$code].': '.$number($count);
        }
        if($valid&&$sum<=$failed&&$parts)$out['facts'][]='Recorded image failure reasons: '.implode('; ',array_slice($parts,0,2)).'.';
        $out['actions'][]='You can review the recorded image failures in this Profile period and open their saved conversations.';
    }
    if($total>0){
        $out['actions'][]='You can review the completed creations and the reference images used in this Profile period.';
        $out['question']='Would you like to review those creations or plan another reference collection?';
    }
    if($out['actions'])$out['next_step']='Open this Profile period to review image activity.';
    return $out;
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
    foreach(array_merge($data['metrics']??[],($data['response_activity']['contract']??null)==='vp3.profile-response-analytics.v1'?($data['response_activity']['metrics']??[]):[]) as $metric){
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

/** Completed-period request evidence only. Current waiting ages and note titles stay in the live workspace. */
function vp3_profile_analytics_response_report_v1(mixed $activity): array
{
    $out=['facts'=>[],'summary'=>[],'actions'=>[],'next_step'=>''];
    if(!is_array($activity)||($activity['contract']??null)!=='vp3.profile-response-analytics.v1'
        ||!in_array($activity['coverage']??null,['complete','partial'],true))return $out;
    $metrics=[];foreach($activity['metrics']??[] as $metric)if(is_array($metric)&&is_string($metric['key']??null))$metrics[$metric['key']]=$metric;
    $positive=static function(string $key)use($metrics):bool{
        $point=$metrics[$key]['current']??[];$n=($point['coverage']??null)==='complete'?($point['value']??null):($point['observed_value']??null);
        return (is_int($n)||is_float($n))&&$n>0;
    };
    if(!$positive('owner_requests_submitted')&&!$positive('owner_requests_answered'))return $out;
    $parts=[];
    foreach(['owner_requests_submitted','knowledge_gap_requests','user_response_requests','service_unavailable_requests','quota_exhausted_requests','unknown_reason_requests'] as $key)
        if($positive($key)&&($text=vp3_profile_analytics_metric_text_v1($metrics[$key]))!==null)$parts[]=$text;
    if($parts)$out['facts'][]=implode('; ',$parts).'.';
    $parts=[];
    foreach(['owner_requests_answered','owner_response_messages','median_owner_response_seconds'] as $key)
        if(isset($metrics[$key])&&($text=vp3_profile_analytics_metric_text_v1($metrics[$key]))!==null&&($positive('owner_requests_answered')||$positive($key)))$parts[]=$text;
    if($parts)$out['facts'][]=implode('; ',$parts).'. Unanswered questions are excluded from response-time samples.';
    foreach($activity['current']['questions']??[] as $question){
        if(!is_array($question)||!is_int($question['count']??null)||$question['count']<2)continue;
        $excerpt=vp3_profile_specialist_text_v1($question['question_excerpt']??'',160);if($excerpt==='')continue;
        $out['facts'][]='Repeated knowledge-gap wording in this period: “'.$excerpt.'” ('.$question['count'].' submitted questions).';
        $out['actions'][]='Review the repeated questions and relevant existing Knowledge notes in Profile Analytics. Any note update remains your choice.';break;
    }
    foreach(['knowledge_gap_requests','owner_requests_answered','owner_requests_submitted'] as $key)
        if($positive($key)&&($text=vp3_profile_analytics_metric_pair_v1($metrics[$key]))!==null)$out['summary'][]=$text;
    $out['next_step']='Open this Profile period to review submitted questions and owner response times.';
    return $out;
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
    $images=vp3_profile_analytics_image_report_v1($metrics,$data['image_usage']??null);
    $responses=vp3_profile_analytics_response_report_v1($data['response_activity']??null);
    $facts=[$period];
    $groups=$images['facts']?[
        ['visit_records','recognized_browser_contacts','new_conversations','accepted_visitor_turns'],
        ['calls_offered','calls_accepted','calls_finished','recorded_product_conversions','recorded_booking_conversions','verified_paid_orders','verified_refunded_orders'],
    ]:[
        ['visit_records','recognized_browser_contacts'],
        ['new_conversations','accepted_visitor_turns'],
        ['calls_offered','calls_accepted','calls_finished'],
        ['recorded_product_conversions','recorded_booking_conversions','verified_paid_orders','verified_refunded_orders'],
    ];
    foreach($groups as $keys){
        $parts=[];foreach($keys as $key)if(isset($metrics[$key])&&($text=vp3_profile_analytics_metric_text_v1($metrics[$key]))!==null)$parts[]=$text;
        if($parts)$facts[]=implode('; ',$parts).'.';
    }
    $details=[];
    foreach($data['money'] as $row)if(is_array($row)&&($text=vp3_profile_analytics_money_text_v1($row))!==null)$details[]=$text;
    if(!$details)foreach(['median_first_reply_seconds','median_call_accept_seconds'] as $key)
        if(isset($metrics[$key])&&($text=vp3_profile_analytics_metric_pair_v1($metrics[$key]))!==null)$details[]=$text;
    $detail=$details?implode('; ',array_slice($details,0,2)).'.':null;
    // The report is bounded to six highlights. Keep image evidence and money
    // visible; the exact period destination retains every underlying measure.
    if($images['facts'])$facts=array_merge([$period],$images['facts'],$detail!==null?[$detail]:[],array_slice($facts,1));
    elseif($detail!==null)$facts[]=$detail;
    if($responses['facts'])$facts=array_merge([$period],$responses['facts'],array_slice($facts,1));
    $summary=array_merge($responses['summary'],$images['summary']);$fallback=[];
    $keys=array_values(array_unique(array_merge(['visit_records','accepted_visitor_turns','calls_accepted','verified_paid_orders'],array_keys($metrics))));
    foreach($keys as $key){
        if(count($summary)>=3)break;
        if($images['summary']&&str_starts_with($key,'image_'))continue;
        if(!isset($metrics[$key]))continue;$metric=$metrics[$key];
        $text=vp3_profile_analytics_metric_pair_v1($metric);if($text===null)continue;
        $fallback[]=$text;$positive=false;
        foreach(['current','previous'] as $side)if(vp3_profile_analytics_metric_text_v1($metric,$side)!==null){
            $part=$metric[$side];if(($part['coverage']==='complete'?$part['value']:$part['observed_value'])>0)$positive=true;
        }
        if($positive)$summary[]=$text;
    }
    if(!$summary)$summary=array_slice($fallback,0,2);
    foreach($data['money'] as $row){
        if(!is_array($row)||($text=vp3_profile_analytics_money_text_v1($row))===null)continue;
        $part=$row['current'];$value=$part['coverage']==='complete'?$part['value_minor']:$part['observed_value_minor'];
        if($value!=='0'){if($images['summary'])$summary[]=$text;else array_unshift($summary,$text);break;}
    }
    $summary=($data['coverage']==='limited'?'Partial coverage. ':'').implode('; ',array_slice($summary,0,4)).'.';
    if($summary==='.'||$summary==='Partial coverage. .')$summary='Period details are available with the stated source coverage.';
    $next=$responses['next_step']!==''?$responses['next_step']:$images['next_step'];
    $report=vp3_profile_specialist_sections_v1($facts,array_merge($responses['actions'],$images['actions']),[],$next,$images['question']!==''?[$images['question']]:[]);
    $url=vp3_profile_analytics_period_href_v1($agent,$chat,$data['ref']);
    $revision=hash('sha256',json_encode(['profile-period-report-v1',$data['revision'],$url,$title,$summary,$report],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE));
    return ['ref'=>$data['ref'],'revision'=>$revision,'specialist'=>'profile','title'=>$title,
        'summary'=>vp3_profile_specialist_text_v1($summary,1200),'next_step'=>$next,'question'=>$images['question'],'url'=>$url,
        'status'=>'completed_period','priority'=>'low','trigger'=>'analytics_period','profile_source_kind'=>'analytics',
        'disposition'=>'current','presentation_owner'=>'profile_briefing_v1','profile_presentation_owned'=>true,
        'legacy_delivered'=>false,'private_summary_only'=>true,'public_evidence_untrusted'=>true,
        'profile_report'=>$report];
}
