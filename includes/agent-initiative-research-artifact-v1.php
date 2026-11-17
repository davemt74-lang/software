<?php
declare(strict_types=1);

require_once __DIR__.'/agent-artist-research-v1.php';

/** Recover only quotes preserved by the original public research collector. The caller owns receipt and scope verification. */
function vp3_initiative_research_artifact_v1(array $toolResult): ?array
{
    $research=vp3_initiative_research_tool_result_v1($toolResult);
    if(($research['output']['status']??null)!=='read_complete')return null;
    [$heading,$lines]=array_pad(explode("\n\n",$research['draft'],3),2,'');
    $prefix='Public source excerpts for ';
    if(!str_starts_with($heading,$prefix)||strpbrk($heading,"\r\n")!==false||strlen($heading)<=strlen($prefix))return null;
    $topic=$research['output']['public_topic']??null;
    if($topic!==null&&$heading!==$prefix.$topic)return null;

    $sources=[];
    foreach($research['sources'] as $source)$sources[$source['title']][]=$source;
    $facts=[];$seen=[];
    foreach(array_slice(explode("\n",$lines),0,4) as $line){
        if(!str_starts_with($line,'“'))continue;
        $matches=[];
        foreach($sources as $title=>$candidates){
            $suffix='” — '.$title;
            if(!str_ends_with($line,$suffix))continue;
            $quote=substr($line,strlen('“'),-strlen($suffix));
            if(mb_strlen($quote)<12||vp3_artist_research_text_v1($quote,220)!==$quote
                ||preg_match('~https?://|[<>]~iu',$quote)!==0)continue;
            foreach($candidates as $source)$matches[]=[$quote,$source];
        }
        if(count($matches)!==1)continue;
        [$quote,$source]=$matches[0];
        if($source['coverage']!=='page_excerpt'||isset($seen[$quote]))continue;
        $seen[$quote]=true;
        $facts[]=['quote'=>$quote,'source_title'=>$source['title'],'source_url'=>$source['url']];
    }
    if(!$facts)return null;
    return ['artifact_type'=>'public_research_excerpts','checked_at'=>$research['output']['checked_at'],'facts'=>$facts];
}
