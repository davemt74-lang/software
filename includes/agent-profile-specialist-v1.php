<?php
declare(strict_types=1);

require_once __DIR__.'/profile-analytics-period-ref-v1.php';
require_once __DIR__.'/profile-commerce-followthrough-ref-v1.php';

/** Pure report projection. Loading this file never loads a public responder or reads storage. */
const VP3_PROFILE_SPECIALIST_REPORT_V1='vp3.profile-report.v1';
const VP3_PROFILE_SPECIALIST_FINDING_LIMIT_V1=24;

/** Public text is evidence. Links stay on the verified source, outside report prose. */
function vp3_profile_specialist_text_v1(mixed $value,int $limit=320): string
{
    if(!is_string($value))return '';
    $text=strip_tags($value);
    $text=preg_replace('~(?:https?://|\bwww\.)[^\s<>]+~iu','[link in source]',$text)??'';
    $text=preg_replace('/[<>\p{Cc}\p{Cf}]|\s+/u',' ',$text)??'';
    return mb_substr(trim(preg_replace('/\s+/u',' ',$text)??''),0,$limit);
}

function vp3_profile_specialist_sections_v1(array $findings=[],array $actions=[],array $questions=[],string $next='',array $ownerQuestions=[]): array
{
    $section=static function(array $rows):array{
        $out=[];
        foreach($rows as $row){$text=vp3_profile_specialist_text_v1($row);if($text!==''&&!in_array($text,$out,true))$out[]=$text;}
        return array_slice($out,0,6);
    };
    return ['contract'=>VP3_PROFILE_SPECIALIST_REPORT_V1,'key_findings'=>$section($findings),
        'actions_followups'=>$section($actions),'open_questions'=>$section($questions),
        'next_step'=>vp3_profile_specialist_text_v1($next),'questions_for_you'=>$section($ownerQuestions)];
}

function vp3_profile_specialist_sections_valid_v1(mixed $report): bool
{
    $keys=['contract','key_findings','actions_followups','open_questions','next_step','questions_for_you'];
    if(!is_array($report)||array_diff(array_keys($report),$keys)||array_diff($keys,array_keys($report))
        ||($report['contract']??null)!==VP3_PROFILE_SPECIALIST_REPORT_V1)return false;
    foreach(array_slice($keys,1) as $key){
        if($key==='next_step')$rows=[$report[$key]];
        else{if(!is_array($report[$key])||!array_is_list($report[$key])||count($report[$key])>6)return false;$rows=$report[$key];}
        foreach($rows as $text)if(!is_string($text)||($key!=='next_step'&&$text==='')||mb_strlen($text)>320
            ||preg_match('~https?://|\bwww\.|[<>\p{Cc}\p{Cf}]~iu',$text))return false;
    }
    return true;
}

/** Pure canonical source validation, including fixed completed-period identities. */
function vp3_profile_specialist_ref_kind_v1(mixed $ref): ?string
{
    if(!is_string($ref)||strlen($ref)>180)return null;
    if(preg_match('/\Aprofile:(conversation|event|call|followup):[1-9][0-9]{0,17}\z/D',$ref,$match))return $match[1];
    if(str_starts_with($ref,'profile:ft:v1:')){
        try{vp3_profile_commerce_followthrough_decode_v1('marketing:'.substr($ref,8));return 'commerce';}catch(Throwable){return null;}
    }
    if(!str_starts_with($ref,'profile:analytics:v1:'))return null;
    try{vp3_profile_analytics_period_decode_v1($ref);return 'analytics';}catch(Throwable){return null;}
}

function vp3_profile_analytics_period_href_v1(int $agent,int $chat,string $ref): string
{
    if($agent<0||$agent>9007199254740991||$chat<1||$chat>9007199254740991
        ||vp3_profile_specialist_ref_kind_v1($ref)!=='analytics')throw new InvalidArgumentException('Invalid Profile period destination.');
    return vp3_profile_specialist_href_v1('/chat.php?agent='.($agent>0?$agent:'system').'&conversation_id='.$chat
        .'&profile_analytics='.rawurlencode($ref));
}

function vp3_profile_specialist_url_v1(string $kind,mixed $url,?string $ref=null): bool
{
    if(!is_string($url))return false;
    if($kind==='commerce'){
        if($ref===null||vp3_profile_specialist_ref_kind_v1($ref)!=='commerce')return false;
        $chat=preg_quote(vp3_profile_specialist_href_v1('/chat.php'),'~');
        if(!preg_match('~\A'.$chat.'\?agent=(system|[1-9][0-9]{0,15})&conversation_id=([1-9][0-9]{0,15})&marketing_followthrough=([^&]+)\z~D',$url,$m))return false;
        try{return $url===vp3_profile_commerce_followthrough_href_v1($m[1]==='system'?0:(int)$m[1],(int)$m[2],'marketing:'.substr($ref,8));}
        catch(Throwable){return false;}
    }
    if($kind==='analytics'){
        if($ref===null||vp3_profile_specialist_ref_kind_v1($ref)!=='analytics')return false;
        $chat=preg_quote(vp3_profile_specialist_href_v1('/chat.php'),'~');
        if(!preg_match('~\A'.$chat.'\?agent=(system|[1-9][0-9]{0,15})&conversation_id=([1-9][0-9]{0,15})&profile_analytics=([^&]+)\z~D',$url,$m))return false;
        $agent=$m[1]==='system'?0:(int)$m[1];$conversation=(int)$m[2];
        try{return $url===vp3_profile_analytics_period_href_v1($agent,$conversation,$ref);}catch(Throwable){return false;}
    }
    $profile=preg_quote(vp3_profile_specialist_href_v1('/profile-agent.php'),'~');
    $transcript=preg_quote(vp3_profile_specialist_href_v1('/artist-listening.php'),'~');
    return preg_match(match($kind){
        'conversation','call'=>'~\A'.$profile.'\?tab=inbox&conversation=[1-9][0-9]{0,17}\z~D',
        'event'=>'~\A'.$profile.'\?tab=visitors&session=[1-9][0-9]{0,17}\z~D',
        'followup'=>'~\A'.$transcript.'\?session=[1-9][0-9]{0,17}\z~D',
        default=>'~(?!)~',
    },$url)===1;
}

function vp3_profile_specialist_href_v1(string $path): string{return function_exists('url')?url($path):$path;}

/** Additive exact links; malformed optional relations never replace native authority. */
function vp3_profile_specialist_related_product_marketing_v1(array $item): array
{
    $invalid=['related_product_marketing'=>[],'related_product_marketing_coverage'=>'unavailable'];
    $coverage=$item['related_product_marketing_coverage']??null;$rows=$item['related_product_marketing']??null;
    if(!preg_match('/\Aprofile:conversation:([1-9][0-9]{0,15})\z/D',(string)($item['ref']??''),$parent)
        ||!in_array($coverage,['ready','limited','unconfigured','unavailable'],true)||!is_array($rows)||!array_is_list($rows)||count($rows)>4
        ||(in_array($coverage,['unavailable','unconfigured'],true)&&$rows!==[]))return $invalid;
    $safe=[];$seen=[];$base=preg_quote(vp3_profile_specialist_href_v1('/chat.php'),'~');
    foreach($rows as $row){
        $valid=is_array($row)&&($row['relation']??null)==='saved_promotion_reply'
            &&is_string($row['ref']??null)&&is_string($row['revision']??null)&&preg_match('/\A[a-f0-9]{64}\z/D',$row['revision'])
            &&is_string($row['publication_id']??null)&&preg_match('/\A[a-f0-9]{32}\z/D',$row['publication_id'])
            &&is_string($row['url']??null)&&preg_match('~\A'.$base.'\?agent=(system|[1-9][0-9]{0,15})&conversation_id=([1-9][0-9]{0,15})&(?:marketing_followthrough|marketing_plan)=[^&]+\z~D',$row['url'],$destination);
        foreach(['product_id','public_conversation_id','turn_id','visitor_message_id','answer_message_id','linked_at_utc_ms'] as $key)
            if(!is_int($row[$key]??null)||$row[$key]<1||$row[$key]>9007199254740991)$valid=false;
        foreach(['title'=>240,'question'=>600] as $key=>$limit)
            if(!is_string($row[$key]??null)||$row[$key]===''||!mb_check_encoding($row[$key],'UTF-8')
                ||vp3_profile_specialist_text_v1($row[$key],$limit)!==$row[$key])$valid=false;
        if(!$valid||$row['public_conversation_id']!==(int)$parent[1]||$row['answer_message_id']<=$row['visitor_message_id']){$coverage='limited';continue;}
        if(isset($seen[$row['publication_id']])){if($seen[$row['publication_id']]!==$row)$coverage='limited';continue;}
        $agent=$destination[1]==='system'?0:(int)$destination[1];$chat=(int)$destination[2];
        try{
            if(str_starts_with($row['ref'],'marketing:ft:v1:')){
                $period=vp3_profile_commerce_followthrough_decode_v1($row['ref']);
                $valid=$period['publication_id']===$row['publication_id']&&$row['linked_at_utc_ms']>=$period['window']['from_utc_ms']
                    &&$row['linked_at_utc_ms']<$period['window']['to_utc_ms']
                    &&$row['url']===vp3_profile_commerce_followthrough_href_v1($agent,$chat,$row['ref']);
            }else{
                $valid=$row['ref']==='marketing:product-plan:'.$row['product_id']&&$agent<=9007199254740991&&$chat<=9007199254740991
                    &&$row['url']===vp3_profile_specialist_href_v1('/chat.php?agent='.($agent?:'system').'&conversation_id='.$chat.'&marketing_plan='.$row['product_id']);
            }
        }catch(Throwable){$valid=false;}
        if(!$valid){$coverage='limited';continue;}
        $safe[]=array_intersect_key($row,array_flip(['ref','revision','title','url','publication_id','product_id','public_conversation_id',
            'turn_id','visitor_message_id','answer_message_id','question','linked_at_utc_ms','relation']));
        $seen[$row['publication_id']]=$row;
    }
    return ['related_product_marketing'=>$safe,'related_product_marketing_coverage'=>$coverage];
}

/** Group only explicitly typed informational visits; never infer intent from volume. */
function vp3_profile_specialist_routine_visit_v1(array $item): bool
{
    if(($item['profile_source_kind']??null)!=='event'
        ||!in_array($item['profile_event_type']??null,['profile_view','return_visitor'],true)
        ||!is_string($item['presentation_owner']??null)
        ||($item['trigger']??null)!=='activity'||($item['priority']??null)!=='low')return false;
    $report=$item['profile_report']??[];
    return ($report['actions_followups']??null)===[]&&($report['open_questions']??null)===[]
        &&($report['next_step']??null)===''&&($report['questions_for_you']??null)===[];
}

/** One source snapshot supports the specialist UI, saved reports and exact source links. */
function vp3_profile_specialist_report_v1(array $snapshot): array
{
    $rawCoverage=$snapshot['coverage']??'unavailable';
    $coverage=is_array($rawCoverage)?($rawCoverage['profile']??'unavailable'):$rawCoverage;
    if(!in_array($coverage,['ready','limited','unavailable','unconfigured'],true))$coverage='unavailable';
    $out=vp3_profile_specialist_sections_v1()+['coverage'=>$coverage,'findings'=>[]];
    if(in_array($coverage,['unavailable','unconfigured'],true))return $out;
    $items=$snapshot['items']??null;
    if(!is_array($items))return array_replace($out,['coverage'=>'unavailable']);
    $sectionItems=[];$routineVisits=0;
    foreach($items as $item){
        if(is_array($item)&&($item['specialist']??null)!=='profile')continue;
        $kind=is_array($item)?($item['profile_source_kind']??''):'';
        if(!is_array($item)||!is_string($kind)||!is_string($item['ref']??null)
            ||vp3_profile_specialist_ref_kind_v1($item['ref'])!==$kind||!is_string($item['revision']??null)||!preg_match('/\A[a-f0-9]{64}\z/D',$item['revision'])
            ||!vp3_profile_specialist_url_v1($kind,$item['url']??null,$item['ref'])
            ||!vp3_profile_specialist_sections_valid_v1($item['profile_report']??null)
            ||!is_bool($item['profile_presentation_owned']??null)||!is_bool($item['legacy_delivered']??null)){
            $out['coverage']='limited';continue;
        }
        if(count($out['findings'])>=VP3_PROFILE_SPECIALIST_FINDING_LIMIT_V1){$out['coverage']='limited';break;}
        $safe=['ref'=>$item['ref'],'revision'=>$item['revision'],'url'=>$item['url'],'profile_source_kind'=>$kind,
            'profile_presentation_owned'=>$item['profile_presentation_owned'],'legacy_delivered'=>$item['legacy_delivered']];
        // Older saved source snapshots may omit these additive fields. They
        // remain readable, but their event kind is not guessed for grouping.
        if(array_key_exists('presentation_owner',$item)){
            $owner=$item['presentation_owner'];
            if(!is_string($owner)||!in_array($owner,['','profile_attention_pa4b','profile_handoff_pa3','profile_speech_pa4a','profile_calls_pa3c','office_tasks','profile_briefing_v1','product_marketing_briefing_v1'],true)
                ||$item['profile_presentation_owned']!==($owner!=='')){$out['coverage']='limited';continue;}
            $safe['presentation_owner']=$owner;
        }
        if(array_key_exists('profile_event_type',$item)){
            if($kind!=='event'||!is_string($item['profile_event_type'])||!preg_match('/\A[a-z][a-z0-9_]{0,63}\z/D',$item['profile_event_type'])){
                $out['coverage']='limited';continue;
            }
            $safe['profile_event_type']=$item['profile_event_type'];
        }
        if(array_key_exists('related_product_marketing',$item)||array_key_exists('related_product_marketing_coverage',$item)){
            $relations=vp3_profile_specialist_related_product_marketing_v1($item);$safe+=$relations;
            if($relations['related_product_marketing_coverage']!==($item['related_product_marketing_coverage']??null))$out['coverage']='limited';
        }
        foreach(['title'=>190,'summary'=>1200,'status'=>40,'priority'=>20,'trigger'=>40,'disposition'=>30] as $key=>$limit)
            $safe[$key]=vp3_profile_specialist_text_v1($item[$key]??'',$limit);
        $out['findings'][]=$safe;
        if(vp3_profile_specialist_routine_visit_v1($item)){++$routineVisits;continue;}
        $sectionItems[]=$item;
    }
    // Keep unresolved conversations, calls and reviewed work ahead of routine
    // visit prose while preserving every original finding and source revision.
    foreach($sectionItems as $item){
        foreach(['key_findings','actions_followups','open_questions','questions_for_you'] as $key){
            foreach($item['profile_report'][$key] as $text){
                if(in_array($text,$out[$key],true))continue;
                if(count($out[$key])>=6){$out['coverage']='limited';continue;}
                $out[$key][]=$text;
            }
        }
        if($out['next_step']==='')$out['next_step']=$item['profile_report']['next_step'];
    }
    if($routineVisits>0){
        if(count($out['key_findings'])<6)$out['key_findings'][]=$routineVisits.' visit '.($routineVisits===1?'record':'records').' in this checked view.';
        else $out['coverage']='limited';
    }
    return $out;
}
