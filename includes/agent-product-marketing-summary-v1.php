<?php
declare(strict_types=1);

require_once __DIR__.'/agent-profile-analytics-summary-v1.php';
require_once __DIR__.'/profile-commerce-followthrough-ref-v1.php';

/** Pure reference validation; no plan, provider or public responder is loaded. */
function vp3_product_marketing_ref_kind_v1(mixed $ref): ?string
{
    if(!is_string($ref)||strlen($ref)>180)return null;
    if(preg_match('/\Amarketing:product-plan:([1-9][0-9]{0,15})\z/D',$ref,$m))
        return (int)$m[1]<=9007199254740991?'plan':null;
    if(!str_starts_with($ref,'marketing:ft:v1:'))return null;
    try{vp3_profile_commerce_followthrough_decode_v1($ref);return 'followthrough';}catch(Throwable){return null;}
}

function vp3_product_marketing_plan_href_v1(int $agent,int $chat,int $product): string
{
    if($agent<0||$agent>9007199254740991||$chat<1||$chat>9007199254740991
        ||vp3_product_marketing_ref_kind_v1('marketing:product-plan:'.$product)!=='plan')
        throw new InvalidArgumentException('Invalid product marketing plan destination.');
    return vp3_profile_specialist_href_v1('/chat.php?agent='.($agent?:'system').'&conversation_id='.$chat.'&marketing_plan='.$product);
}

function vp3_product_marketing_metric_text_v1(array $metric,string $side='current'): ?string
{
    $labels=['recorded_cta_clicks'=>'recorded CTA clicks','conversation_attributed_clicks'=>'clicks with verified conversation links',
        'promotion_linked_exchanges'=>'promotion-linked exchanges','recorded_attributed_orders'=>'attributed orders',
        'verified_checkout_openings'=>'checkout links recorded available','verified_paid_orders'=>'verified paid orders',
        'verified_refunded_orders'=>'orders with verified refunds'];
    $key=$metric['key']??null;$part=$metric[$side]??null;
    if(!is_string($key)||!isset($labels[$key])||!is_array($part))return null;
    $coverage=$part['coverage']??null;
    $value=$coverage==='complete'?($part['value']??null):($coverage==='partial'?($part['observed_value']??null):null);
    if(!is_int($value)||$value<0||$value>9007199254740991)return null;
    return number_format($value,0,'.',',').' '.$labels[$key].($coverage==='partial'?' observed (partial)':'');
}

function vp3_product_marketing_period_discoverable_v1(array $data): bool
{
    if(!in_array($data['coverage']??null,['ready','limited'],true))return false;
    foreach($data['metrics']??[] as $metric)if(is_array($metric))foreach(['current','previous'] as $side){
        if(vp3_product_marketing_metric_text_v1($metric,$side)===null)continue;
        $part=$metric[$side];if(($part['coverage']==='complete'?$part['value']:$part['observed_value'])>0)return true;
    }
    foreach($data['money']??[] as $row)if(is_array($row))foreach(['current','previous'] as $side){
        if(vp3_profile_analytics_money_text_v1($row,$side)===null)continue;
        $part=$row[$side];if(($part['coverage']==='complete'?$part['value_minor']:$part['observed_value_minor'])!=='0')return true;
    }
    return false;
}

/** Marketing and Profile share the same immutable facts, revision and destination. */
function vp3_product_marketing_period_item_v1(array $data,int $agent,int $chat,bool $profile=false): ?array
{
    if(($data['contract']??null)!=='vp3.profile-commerce-followthrough.v1'
        ||!in_array($data['coverage']??null,['ready','limited'],true)
        ||vp3_product_marketing_ref_kind_v1($data['ref']??null)!=='followthrough'
        ||!is_string($data['revision']??null)||!preg_match('/\A[a-f0-9]{64}\z/D',$data['revision'])
        ||!is_array($data['metrics']??null)||!array_is_list($data['metrics'])
        ||!is_array($data['money']??null)||!array_is_list($data['money']))return null;
    $canonical=$data['ref'];
    try{$selection=vp3_profile_commerce_followthrough_decode_v1($canonical);}
    catch(Throwable){return null;}
    if(($data['window']??null)!==$selection['window']
        ||($data['publication']['publication_id']??null)!==$selection['publication_id']
        ||($data['publication']['chat_agent_id']??null)!==$agent
        ||($data['publication']['chat_conversation_id']??null)!==$chat)return null;
    $window=$selection['window'];$publication=$data['publication'];
    $name=vp3_profile_specialist_text_v1($publication['title']??'',120);
    if($name==='')$name='Published promotion';
    $title=$name.' · completed promotion period';
    $period='Completed local period '.$window['from_local'].' to '.$window['to_local'].' (end excluded), '.$window['timezone'].'.';
    if($data['coverage']==='limited')$period='Partial coverage. '.$period;
    $metrics=[];
    foreach($data['metrics'] as $metric)if(is_array($metric)&&is_string($metric['key']??null))$metrics[$metric['key']]=$metric;
    $facts=[$period];$summary=[];$fallback=[];
    foreach([
        ['recorded_cta_clicks','conversation_attributed_clicks'],
        ['promotion_linked_exchanges','recorded_attributed_orders'],
        ['verified_checkout_openings','verified_paid_orders','verified_refunded_orders'],
    ] as $keys){
        $group=[];
        foreach($keys as $key){
            $metric=$metrics[$key]??[];$current=vp3_product_marketing_metric_text_v1($metric);
            if($current===null)continue;
            $group[]=$current;$pair=$current;
            if(($metric['current']['coverage']??null)==='complete'&&($metric['previous']['coverage']??null)==='complete'
                &&($previous=vp3_product_marketing_metric_text_v1($metric,'previous'))!==null)$pair.='; previous: '.$previous;
            $fallback[]=$pair;
            foreach(['current','previous'] as $side){
                if(vp3_product_marketing_metric_text_v1($metric,$side)===null)continue;
                $part=$metric[$side];
                if(($part['coverage']==='complete'?$part['value']:$part['observed_value'])>0){$summary[]=$pair;break;}
            }
        }
        if($group)$facts[]=implode('; ',$group).'.';
    }
    $money=[];
    foreach($data['money'] as $row)if(is_array($row)&&($text=vp3_profile_analytics_money_text_v1($row))!==null){
        $money[]=$text;$part=$row['current'];
        if(($part['coverage']==='complete'?$part['value_minor']:$part['observed_value_minor'])!=='0')array_unshift($summary,$text);
    }
    if($money)$facts[]=implode('; ',array_slice($money,0,2)).'.';
    $facts[]='An available checkout link does not prove a buyer opened the payment provider. Linked exchanges do not establish purchase intent.';
    if(!$summary)$summary=array_slice($fallback,0,2);
    $summary=($data['coverage']==='limited'?'Partial coverage. ':'').implode('; ',array_slice($summary,0,3));
    if(trim($summary)===''||$summary==='Partial coverage. ')$summary='Promotion period details retain their stated evidence coverage.';
    $report=vp3_profile_specialist_sections_v1($facts);
    $url=vp3_profile_commerce_followthrough_href_v1($agent,$chat,$canonical);
    $revision=hash('sha256',json_encode(['product-marketing-period-v1',$data['revision'],$canonical,$url,$title,$summary,$report],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE));
    $item=['ref'=>$profile?'profile:'.substr($canonical,10):$canonical,'canonical_ref'=>$canonical,'canonical_revision'=>$revision,
        'revision'=>$revision,'specialist'=>$profile?'profile':'marketing','title'=>$title,
        'summary'=>vp3_profile_specialist_text_v1($summary,1200),'next_step'=>'','question'=>'','url'=>$url,
        'status'=>'completed_period','priority'=>'low','trigger'=>$profile?'commerce_followthrough':'promotion_period',
        'disposition'=>'current','presentation_owner'=>'product_marketing_briefing_v1','legacy_delivered'=>false,
        'private_summary_only'=>true,'public_evidence_untrusted'=>true];
    if($profile)$item+=['profile_source_kind'=>'commerce','profile_presentation_owned'=>true,
        'profile_canonical_ref'=>$canonical,'profile_report'=>$report];
    else $item+=['marketing_source_kind'=>'followthrough','marketing_presentation_owned'=>true];
    return $item;
}
