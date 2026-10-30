<?php
declare(strict_types=1);
// Reuse the same engine as the Transcription Intelligence UI; no API-to-API requests.
foreach(['transcription-app-registry','transcription-apps-wave2','transcription-apps-wave2-save','transcription-intelligence-items','transcription-intelligence-relations','transcription-intelligence-actions','transcription-workflow-config','transcription-intelligence-outputs','transcription-deeper-intelligence','transcription-deeper-items','transcription-deeper-chat','transcription-deeper-report','transcription-deeper-advanced','video-meetings-v1800','video-meetings-homeserver-v1801'] as $module)require_once __DIR__.'/'.$module.'.php';

function vp3_transcript_intelligence_v1(PDO $pdo,array $user,array $principal,int $id,string $view='analysis',string $plugin='',int $offset=0): array
{
    $s=vp3_transcript_session_v1($pdo,$user,$principal,$id);
    if(!artist_listening_v237_schema_ready())throw new RuntimeException('Transcript analysis storage needs the database upgrade.');
    $map=artist_listening_transcript_page_map(artist_listening_v172_segments($pdo,$id));$saved=artist_listening_v237_analysis_status($pdo,$id,$map);$m=$saved['master'];
    $p=['session_id'=>$id,'title'=>$s['title'],'source_hash'=>$map['source_hash'],'page_count'=>$map['page_count'],'generated'=>is_array($m),'fresh'=>!empty($m['fresh']),'text'=>'','sources'=>[]];
    if(!$m)return $p;
    $p['generated_at']=$m['generated_at'];$p['analyzed_pages']=$saved['analyzed_pages'];
    if(!$p['fresh'])return $p;
    $status=transcription_deeper_advanced_status_v307($pdo,$user,$s,$m,$map);$mods=transcription_app_modules_v306($m['analysis'],$m);$items=[];$counts=['accepted'=>0,'unreviewed'=>0,'rejected'=>0];
    foreach($mods as $key=>$mod){
        if($plugin!==''&&$key!==$plugin)continue;
        if($view==='research'&&$key!=='research_brief')continue;
        $fresh=!empty($status['app_status'][$key]['fresh']);
        $items[$key]=['fresh'=>$fresh,'fresh_reason'=>$status['app_status'][$key]['fresh_reason']??'not_checked','result'=>$fresh?($mod['result']??[]):[]];
        foreach((array)($mod['result']??[]) as $rows)if(is_array($rows))foreach($rows as $row)if(is_array($row)&&isset($row['item_id'])){$review=$row['review_state']??'unreviewed';if(isset($counts[$review]))$counts[$review]++;}
    }
    $p['review_counts']=$counts;
    if($view==='research'){
        $p['fresh']=!empty($status['app_status']['research_brief']['fresh']);
        if(!$p['fresh']){$p['fresh_reason']=$status['app_status']['research_brief']['fresh_reason']??'not_generated';return $p;}
        $items['public_research']=['text'=>$m['research']['text']??'','error'=>$m['research']['error']??''];
        foreach(array_slice((array)($m['research']['sources']??[]),0,20) as $source){$url=(string)($source['url']??'');if(filter_var($url,FILTER_VALIDATE_URL)&&str_starts_with($url,'https://'))$p['sources'][]=['title'=>mb_substr((string)($source['title']??$url),0,220),'url'=>$url];}
    }
    $lines=[];$walk=function($v,$label='')use(&$walk,&$lines){if(is_array($v)){foreach($v as $key=>$value){if(in_array((string)$key,['actions','__deep_meta','source_fingerprint'],true))continue;$walk($value,is_int($key)?$label:$label.($label!==''?' · ':'').str_replace('_',' ',(string)$key));}}elseif(is_scalar($v)&&trim((string)$v)!=='')$lines[]=$label.': '.(is_bool($v)?($v?'yes':'no'):(string)$v);};
    $walk($items);$text=implode("\n",$lines);$p['text']=mb_substr($text,$offset,16000);$p['next_offset']=$offset+mb_strlen($p['text'])<mb_strlen($text)?$offset+mb_strlen($p['text']):null;
    return $p;
}
function vp3_transcript_analyze_v1(PDO $pdo,array $user,array $principal,int $id,array $apps,string $focus,bool $research,?callable $runner=null): array
{
    $s=vp3_transcript_session_v1($pdo,$user,$principal,$id);
    $registry=transcription_app_registry_v307();
    if(!$apps||count($apps)>8||array_diff($apps,array_keys($registry)))throw new RuntimeException('Choose up to eight existing transcript analysis modules.');
    if($research&&!in_array('research_brief',$apps,true))$apps[]='research_brief';
    if(function_exists('video_meeting_transcription_assert_cloud_ai_v1801'))video_meeting_transcription_assert_cloud_ai_v1801($pdo,$user,$s);
    $workflow=['depth'=>'standard','live_analysis'=>false,'web_research'=>$research,'context_mode'=>'transcript','focus'=>mb_substr($focus,0,240)];
    // One explicit engine invocation; uncertain or failed analysis is never automatically replayed.
    $r=$runner?$runner($pdo,$user,$id,'manual',$apps,$workflow):transcription_app_analyze_v307($pdo,$user,$id,'manual',$apps,$workflow);
    return ['session_id'=>$id,'title'=>$s['title'],'executed_apps'=>$r['executed_apps']??[],'errors'=>$r['plugin_errors']??[],'skipped'=>!empty($r['skipped']),'reason'=>$r['reason']??''];
}
function vp3_transcript_intelligence_chat_v1(PDO $pdo,array $user,array $principal,array $plan,int $id): array
{
    $op=$plan['operation'];
    if(in_array($op,['run_analysis','run_research'],true)){
        $r=vp3_transcript_analyze_v1($pdo,$user,$principal,$id,$plan['apps']??['basic'],(string)($plan['query']??''),$op==='run_research');
        $ok=!$r['errors']&&!$r['skipped']&&count($r['executed_apps'])>0;
        $answer=$ok?'Transcript analysis completed using: '.implode(', ',$r['executed_apps']).'. Findings remain interpretations until reviewed; external verification is separate.':($r['skipped']?'No analysis was run: '.($r['reason']?:'the engine skipped this request').'.':'The analysis did not fully complete. Some pages may have updated; inspect the saved result before requesting another run.');
        return ['handled'=>true,'answer'=>$answer,'actions'=>[],'sources'=>[['source'=>'transcript:'.$id,'title'=>$r['title'],'url'=>url('/artist-listening.php?session='.$id)]],'tool_receipt'=>vp3_tool_receipt_v1('transcripts.analyze',$op,$ok?'completed':'failed',$ok?'':'execution_failed',$id,count($r['executed_apps']))];
    }
    $p=vp3_transcript_intelligence_v1($pdo,$user,$principal,$id,$op==='read_research'?'research':'analysis',$op==='read_analysis'?(string)($plan['plugin']??''):'',(int)($plan['offset']??0));
    if(!$p['generated'])$answer='No saved AI analysis exists for this transcript. Ask me to analyze it.';
    elseif(!$p['fresh'])$answer='The saved '.($op==='read_research'?'research':'analysis').' is stale or unavailable'.(!empty($p['fresh_reason'])?' ('.$p['fresh_reason'].')':' because the transcript changed').'. Ask me to refresh it; I have not run a new analysis or web search.';
    else{$answer='Transcript #'.$id.' · '.$p['title'].' · AI interpretation, not transcript quotations or independently verified user facts. Generated '.$p['generated_at'].'. Review counts: '.json_encode($p['review_counts'])."\n".$p['text'];if($p['next_offset']!==null)$answer.="\nContinues at character offset ".$p['next_offset'].'.';}
    $r=vp3_transcript_result_v1($answer,$op,'read_complete',$id,$p['generated']?1:0,$p);
    foreach($p['sources'] as $source)$r['sources'][]=['source'=>'transcript-research:'.$id,...$source];return $r;
}
