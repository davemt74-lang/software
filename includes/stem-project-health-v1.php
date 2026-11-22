<?php
declare(strict_types=1);
require_once __DIR__.'/stem-import-media.php';
require_once __DIR__.'/stem-mp3-metadata-v1.php';

/** Resolve source metadata for an already-authorized project. This read does
 * not alter saved clips, mixes, source bytes or publication state. */
function stem_project_resolve_metadata_v1(array $stems): array
{
    foreach($stems as &$stem){
        if(strtolower(pathinfo((string)($stem['file_name']??''),PATHINFO_EXTENSION))!=='mp3')continue;
        $resolved=stem_media_v229_resolve_path(STONEFELLOW_ROOT,(string)($stem['file_path']??''));
        if(empty($resolved['ok']))continue;
        $info=stem_mp3_metadata_v1((string)$resolved['path']);
        if($info===null)continue;
        $stored=(float)($stem['duration_seconds']??0);
        $stem['duration_metadata_recovered']=$stored<=0||abs($stored-$info['duration_seconds'])>.1;
        $stem['stored_source_duration']=$stored;
        foreach(['duration_seconds','channels','sample_rate','bit_depth'] as $field)$stem[$field]=$info[$field];
    }
    unset($stem);
    return $stems;
}

/** Header validation is server evidence, not a promise of browser decoding. */
function stem_project_health_v1(array $stems): array
{
    $verified=0;$unavailable=0;$unknown=0;$revision=[];$problems=[];
    foreach($stems as $stem){
        $path=stem_media_v229_resolve_path(STONEFELLOW_ROOT,(string)($stem['file_path']??''));
        $check=empty($path['ok'])?['ok'=>false,'reason'=>'missing']:stem_media_v229_inspect_file((string)$path['path']);
        $duration=(float)($stem['duration_seconds']??0);
        if(!empty($check['ok'])){
            $verified++;
            if($duration<=0&&strtolower(pathinfo((string)$path['path'],PATHINFO_EXTENSION))==='mp3')$duration=(float)(stem_mp3_metadata_v1((string)$path['path'])['duration_seconds']??0);
            if($duration<=0)$unknown++;
        }else{
            $unavailable++;
            if(count($problems)<6)$problems[]=mb_strimwidth((string)($stem['stem_name']??$stem['id']),0,70,'…').' ('.(string)($check['reason']??'unavailable').')';
        }
        $revision[]=[(int)$stem['id'],(int)($stem['project_id']??0),(string)($stem['stem_name']??''),(string)($stem['stem_role']??''),(string)($stem['file_path']??''),$duration,!empty($check['ok']),$check['reason']??'',!empty($path['ok'])?filesize($path['path']):0,!empty($path['ok'])?filemtime($path['path']):0];
    }
    return ['verified'=>$verified,'unavailable'=>$unavailable,'metadata_unknown'=>$unknown,'problems'=>$problems,'revision'=>$revision];
}
