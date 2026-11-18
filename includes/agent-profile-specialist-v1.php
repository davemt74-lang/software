<?php
declare(strict_types=1);

require_once __DIR__.'/profile-analytics-period-ref-v1.php';
require_once __DIR__.'/profile-product-marketing-ref-v1.php';

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
    if(preg_match('/\\Aprofile:(conversation|event|call|followup):[1-9][0-9]{0,17}\\z/D',$ref,$match))return $match[1];
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
    if($kind==='analytics'){
        if($ref===null||vp3_profile_specialist_ref_kind_v1($ref)!=='analytics')return false;
        $chat=preg_quote(vp3_profile_specialist_href_v1('/chat.php'),'~');
        if(!preg_match('~\\A'.$chat.'\\?agent=(system|[1-9][0-9]{0,15})&conversation_id=([1-9][0-9]{0,15})&profile_analytics=([^&]+)\\z~D',$url,$m))return false;
        $agent=$m[1]==='system'?0:(int)$m[1];$conversation=(int)$m[2];
        try{return $url===vp3_profile_analytics_period_href_v1($agent,$conversation,$ref);}catch(Throwable){return false;}
    }
    $profile=preg_quote(vp3_profile_specialist_href_v1('/profile-agent.php'),'~');
    $transcript=preg_quote(vp3_profile_specialist_href_v1('/artist-listening.php'),'~');
    return preg_match(match($kind){
        'conversation','call'=>'~\\A'.$profile.'\\?tab=inbox&conversation=[1-9][0-9]{0,17}\\z~D',
        'event'=>'~\\A'.$profile.'\\?tab=visitors&session=[1-9][0-9]{0,17}\\z~D',
        'followup'=>'~\\A'.$transcript.'\\?session=[1-9][0-9]{0,17}\\z~D',
        default=>'~(?!)~',
    },$url)===1;
}

function vp3_profile_specialist_href_v1(string $path): string{return function_exists('url')?url($path):$path;}

/** Pure, exact related-source projection; never infer a destination from prose. */
function vp3_profile_specialist_marketing_link_v1(mixed $link): ?array
{
    if(!is_array($link)||!is_string($link['ref']??null)
        ||!($parsed=vp3_product_marketing_ref_parse_v1($link['ref']))
        ||!is_string($link['revision']??null)||!preg_match('/\A[a-f0-9]{64}\z/D',$link['revision'])
        ||!is_int($link['product_id']??null)||$link['product_id']<1||$link['product_id']>9007199254740991
        ||!is_string($link['title']??null)||!is_string($link['url']??null))return null;
    $title=vp3_profile_specialist_text_v1($link['title'],190);if($title==='')return null;
    $publication=$link['publication_id']??null;
    if($parsed['kind']==='plan'){
        if($parsed['product_id']!==$link['product_id']||$publication!==null)return null;
    }elseif(!is_string($publication)||$publication!==$parsed['publication_id'])return null;
    $chat=preg_quote(vp3_profile_specialist_href_v1('/chat.php'),'~');
    if(!preg_match('~\A'.$chat.'\?agent=(system|[1-9][0-9]{0,15})&conversation_id=([1-9][0-9]{0,15})&marketing_plan=([1-9][0-9]{0,15})(?:&marketing_publication=([a-f0-9]{32})&marketing_period=([^&]+))?\z~D',$link['url'],$m))return null;
    $agent=$m[1]==='system'?0:(int)$m[1];$conversation=(int)$m[2];
    if($agent>9007199254740991||$conversation>9007199254740991||(int)$m[3]!==$link['product_id'])return null;
    $expected=vp3_profile_specialist_href_v1('/chat.php?agent='.($agent?:'system').'&conversation_id='.$conversation.'&marketing_plan='.$link['product_id']);
    if($parsed['kind']==='outcome')$expected.='&marketing_publication='.$publication.'&marketing_period='.rawurlencode($parsed['period_ref']);
    if($link['url']!==$expected)return null;
    return ['ref'=>$link['ref'],'revision'=>$link['revision'],'title'=>$title,'url'=>$expected,
        'product_id'=>$link['product_id'],'publication_id'=>$publication];
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
            if(!is_string($owner)||!in_array($owner,['','profile_attention_pa4b','profile_handoff_pa3','profile_speech_pa4a','profile_calls_pa3c','office_tasks','profile_briefing_v1'],true)
                ||$item['profile_presentation_owned']!==($owner!=='')){$out['coverage']='limited';continue;}
            $safe['presentation_owner']=$owner;
        }
        if(array_key_exists('profile_event_type',$item)){
            if($kind!=='event'||!is_string($item['profile_event_type'])||!preg_match('/\A[a-z][a-z0-9_]{0,63}\z/D',$item['profile_event_type'])){
                $out['coverage']='limited';continue;
            }
            $safe['profile_event_type']=$item['profile_event_type'];
        }
        foreach(['title'=>190,'summary'=>1200,'status'=>40,'priority'=>20,'trigger'=>40,'disposition'=>30] as $key=>$limit)
            $safe[$key]=vp3_profile_specialist_text_v1($item[$key]??'',$limit);
        if(array_key_exists('related_product_marketing',$item)){
            $links=$item['related_product_marketing'];$safe['related_product_marketing']=[];$seen=[];
            if(!is_array($links)||!array_is_list($links))$out['coverage']='limited';
            else foreach($links as $link){
                $link=vp3_profile_specialist_marketing_link_v1($link);
                if($link===null){$out['coverage']='limited';continue;}
                if(isset($seen[$link['ref']]))continue;
                if(count($safe['related_product_marketing'])>=4){$out['coverage']='limited';break;}
                $seen[$link['ref']]=true;$safe['related_product_marketing'][]=$link;
            }
        }
        if(array_key_exists('product_marketing_coverage',$item)){
            $relatedCoverage=$item['product_marketing_coverage'];
            if(in_array($relatedCoverage,['ready','limited','unavailable','unconfigured'],true))$safe['product_marketing_coverage']=$relatedCoverage;
            else $out['coverage']='limited';
        }
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
