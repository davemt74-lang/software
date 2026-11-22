<?php
declare(strict_types=1);

/** Private native production evidence, read again for every preparation/delivery. */
function vp3_media_production_sources_v1(PDO $pdo,array $scope,?array $refs): array
{
    if(!table_exists('tracks')||!table_exists('track_stems')
        ||!function_exists('music_workspace_enabled_v320')||!function_exists('can_manage_track_production'))return [];
    require_once __DIR__.'/agent-media-playback-v1.php';
    require_once __DIR__.'/stem-project-health-v1.php';
    [$user,$principal]=vp3_media_playback_user_v1($pdo,$scope['user'],(int)$scope['agent_id'],(int)$scope['conversation_id']);
    $owner=(int)$user['id'];
    if(!music_workspace_enabled_v320($user)||($principal['kind']!=='system'
        &&!user_data_policy_can_use_v236($pdo,$principal,$owner,'knowledge','music-production',false)))return [];
    $ids=$refs===null?null:array_values(array_filter(array_map(static fn(string $ref):int=>
        preg_match('/\Amedia:studio:([1-9][0-9]{0,15})\z/D',$ref,$m)?(int)$m[1]:0,$refs)));
    if($ids===[])return [];
    // Discovery is bounded and restricted to the owner's production work.
    $sql='SELECT t.* FROM tracks t WHERE (t.owner_user_id=? OR t.producer_user_id=?';
    if(table_exists('artist_workspaces_v181'))$sql.=' OR t.workspace_id IN (SELECT id FROM artist_workspaces_v181 WHERE artist_user_id=?)';
    $sql.=')';$params=[$owner,$owner];
    if(table_exists('artist_workspaces_v181'))$params[]=$owner;
    if($ids!==null){$sql.=' AND t.id IN ('.implode(',',array_fill(0,count($ids),'?')).')';array_push($params,...$ids);}
    $sql.=' ORDER BY t.updated_at DESC,t.id DESC LIMIT 15';
    $q=$pdo->prepare($sql);$q->execute($params);$items=[];
    foreach($q->fetchAll(PDO::FETCH_ASSOC) as $track){
        if(!can_view_track($track,$user)||!can_manage_track_production($track,$user))continue;
        $s=$pdo->prepare('SELECT id,project_id,stem_name,stem_role,file_path,duration_seconds FROM track_stems WHERE track_id=? AND is_active=1 AND file_path<>\'\' ORDER BY sort_order,id LIMIT 257');
        $s->execute([(int)$track['id']]);$stems=$s->fetchAll(PDO::FETCH_ASSOC);
        if(!$stems)continue;
        $health=stem_project_health_v1($stems);
        $attention=$health['unavailable']>0||$health['metadata_unknown']>0||count($stems)>256;
        $summary=count($stems).' active stems; '.$health['verified'].' have readable audio headers.';
        if($health['unavailable'])$summary.=' '.$health['unavailable'].' source files are missing or invalid: '.implode(', ',$health['problems']).'.';
        if($health['metadata_unknown'])$summary.=' '.$health['metadata_unknown'].' stems need source duration verification.';
        $summary.=' This checks saved files; browser playback and mix quality are not verified.';
        $ref='media:studio:'.(int)$track['id'];$href=url('/admin/stems.php?track='.(int)$track['id']);
        $revision=hash('sha256',json_encode([$ref,(string)$track['title'],$health['revision']],JSON_THROW_ON_ERROR));
        $items[$ref]=['ref'=>$ref,'revision'=>$revision,'specialist'=>'media','kind'=>'production_project',
            'title'=>'Production · '.(string)$track['title'],'summary'=>$summary,
            'next_step'=>$attention?'Open Stem Editor, inspect the named sources and retry loading before changing the mix.':'Open Stem Editor to review the arrangement, mix and production notes.',
            'question'=>$attention?'Would you like to review the source files that need attention?':'What would you like to work on next in this production?',
            'url'=>$href,'actions'=>[['kind'=>'url','label'=>'Open Stem Editor','href'=>$href]],
            'status'=>$attention?'needs_owner':'watching','priority'=>$attention?'high':'normal',
            'trigger'=>$attention?'production_attention':'production_project'];
    }
    return $items;
}
