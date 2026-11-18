<?php
declare(strict_types=1);

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

function vp3_profile_specialist_url_v1(string $kind,mixed $url): bool
{
    if(!is_string($url))return false;
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
    foreach($items as $item){
        if(is_array($item)&&($item['specialist']??null)!=='profile')continue;
        $kind=is_array($item)?($item['profile_source_kind']??''):'';
        if(!is_array($item)||!is_string($kind)||!is_string($item['ref']??null)
            ||!preg_match('/\Aprofile:(conversation|event|call|followup):[1-9][0-9]{0,17}\z/D',$item['ref'],$match)
            ||$kind!==$match[1]||!is_string($item['revision']??null)||!preg_match('/\A[a-f0-9]{64}\z/D',$item['revision'])
            ||!vp3_profile_specialist_url_v1($kind,$item['url']??null)
            ||!vp3_profile_specialist_sections_valid_v1($item['profile_report']??null)
            ||!is_bool($item['profile_presentation_owned']??null)||!is_bool($item['legacy_delivered']??null)){
            $out['coverage']='limited';continue;
        }
        if(count($out['findings'])>=VP3_PROFILE_SPECIALIST_FINDING_LIMIT_V1){$out['coverage']='limited';break;}
        $safe=['ref'=>$item['ref'],'revision'=>$item['revision'],'url'=>$item['url'],'profile_source_kind'=>$kind,
            'profile_presentation_owned'=>$item['profile_presentation_owned'],'legacy_delivered'=>$item['legacy_delivered']];
        foreach(['title'=>190,'summary'=>1200,'status'=>40,'priority'=>20,'trigger'=>40,'disposition'=>30] as $key=>$limit)
            $safe[$key]=vp3_profile_specialist_text_v1($item[$key]??'',$limit);
        $out['findings'][]=$safe;
        foreach(['key_findings','actions_followups','open_questions','questions_for_you'] as $key){
            foreach($item['profile_report'][$key] as $text){
                if(in_array($text,$out[$key],true))continue;
                if(count($out[$key])>=6){$out['coverage']='limited';continue;}
                $out[$key][]=$text;
            }
        }
        if($out['next_step']==='')$out['next_step']=$item['profile_report']['next_step'];
    }
    return $out;
}
