<?php
declare(strict_types=1);

require_once __DIR__.'/agent-activity-scope-v1.php';
require_once __DIR__.'/agent-connection-youtube-chat-v1.php';
require_once __DIR__.'/agent-initiative-artist-v1.php';
require_once __DIR__.'/agent-initiative-scope-v1.php';

/** Project current saved media evidence without calling YouTube or starting research. */
function vp3_media_youtube_findings_v1(PDO $pdo,array $scope,array $binding,?array $refs,DateTimeImmutable $now,bool $offersOnly=false): array
{
    $out=['items'=>[],'coverage'=>'ready'];
    $owner=(int)($scope['user']['id']??0);
    $agent=$scope['agent_id']??null;
    $chat=$scope['conversation_id']??null;
    if($owner<1||!is_int($agent)||$agent<0||!is_int($chat)||$chat<1
        ||($binding['conversation_id']??null)!==$chat||vp3_initiative_binding_agent_v1($binding)!==$agent
        ||(int)($binding['owner_user_id']??$owner)!==$owner||vp3_participant_scope_v1()!==null
        ||!has_permission('account.access',$scope['user'])
        ||!vp3_agent_chat_conversation_v380($pdo,$chat,$owner,$agent>0?['id'=>$agent]:null)){
        return ['items'=>[],'coverage'=>'unconfigured'];
    }
    $searchIds=$researchIds=null;
    if($refs!==null){
        $searchIds=$researchIds=[];
        foreach($refs as $ref){
            if(!is_string($ref)||preg_match('/\Amedia:(youtube|research):([1-9][0-9]{0,15})\z/D',$ref,$match)!==1)continue;
            $id=(int)$match[2];
            if($id>9007199254740991)continue;
            if($match[1]==='youtube')$searchIds[$id]=$id;
            else $researchIds[$id]=$id;
        }
    }
    if($searchIds!==[]&&agent_connection_youtube_enabled_v1()&&agent_connection_youtube_consent_v1($pdo,$owner)){
        $items=vp3_media_youtube_saved_searches_v1($pdo,$owner,$agent,$chat,$searchIds,$now->getTimestamp());
        $out['items']+=$items['items'];
        if(isset($items['taste']))$out['taste']=$items['taste'];
    }
    if(!$offersOnly&&$researchIds!==[])$out['items']+=vp3_media_research_findings_v1($pdo,$scope,$researchIds,$now);
    return $out;
}

function vp3_media_youtube_saved_searches_v1(PDO $pdo,int $owner,int $agent,int $chat,?array $ids,int $now): array
{
    $items=[];
    $records=agent_connection_youtube_result_records_v1($pdo,$owner,$agent,$chat,$ids===null?8:count($ids),$ids??[]);
    $settings=vp3_youtube_picks_ready_v1($pdo)?vp3_youtube_picks_settings_v1($pdo,$owner):[];
    $proactive=(int)($settings['proactive']??0)===1;
    $taste=vp3_agent_activity_text_v1($settings['taste']??'',120);
    require_once __DIR__.'/agent-media-preferences-v1.php';
    $preferences=['ready'=>false];
    $historyAllowed=true;
    if(vp3_media_preferences_ready_v1($pdo)){
        require_once __DIR__.'/agent-media-playback-v1.php';
        [$live,$principal]=vp3_media_playback_user_v1($pdo,['id'=>$owner],$agent,$chat);
        $historyAllowed=vp3_media_playback_history_allowed_v1($pdo,$live,$principal);
        $candidates=[];
        foreach($records as $record){
            if($record['expires_at']<=$now)continue;
            array_push($candidates,...vp3_media_preferences_candidates_v1(
                agent_connection_youtube_ordered_videos_v1($record['results']['videos']),'youtube'));
        }
        if($historyAllowed&&$candidates)$preferences=vp3_media_preferences_read_v1($pdo,$live,$agent,$chat,$candidates);
    }
    $seen=$playedSources=[];
    if($historyAllowed&&vp3_youtube_playback_ready_v1($pdo)&&vp3_youtube_capture_key_v1($pdo,$owner)!==''){
        foreach(vp3_youtube_history_recent_v1($pdo,$owner,$agent,limit:120) as $play){
            if($play['played_at']>$now)continue;
            $seen[$play['video_id']]=true;
            $playedSources[$play['message_id']]=true;
        }
    }
    foreach($records as $record){
        $result=$record['results'];
        $created=$record['created_at_epoch'];
        if(($result['initiative_suggestion']??false)===true||$created<1||$created>$now||$record['expires_at']<=$now)continue;
        $query=vp3_agent_activity_text_v1($result['query']??'',160);
        if($query==='')continue;
        $videos=array_values(array_filter(agent_connection_youtube_ordered_videos_v1($result['videos']),
            static fn(array $video):bool=>($video['made_for_kids']??null)===false&&vp3_agent_activity_text_v1($video['title']??'')!==''));
        if(!$videos)continue;
        $subject=vp3_initiative_youtube_subject_v1($result);
        $suggestion=$result['suggestion_kind']??'';
        $artist=$subject!==''&&($suggestion===''||in_array($suggestion,['artists','similar'],true))
            ?vp3_initiative_youtube_artist_subject_v1($subject,$videos):null;
        $artistContext=$artist!==null||in_array($suggestion,['artists','similar'],true);
        $music=$artistContext||($result['music_suggestion']??false)===true
            ||!array_filter($videos,static fn(array $video):bool=>($video['music_category']??false)!==true);
        // Honor provider order for general searches, with Music-category videos first on a music search.
        if($music)usort($videos,static fn(array $left,array $right):int=>
            (int)(($right['music_category']??false)===true)<=>(int)(($left['music_category']??false)===true));
        $similar=[];
        if($suggestion==='similar'&&$subject!==''){
            require_once __DIR__.'/agent-media-similar-artists-v1.php';
            $similar=vp3_media_similar_artist_results_v1($subject,$videos);
            if($similar)$videos=array_column($similar,'video');
        }
        $relevance=[];
        if($music)foreach($videos as $video)$relevance[$video['video_id']]=(int)(($video['music_category']??false)===true);
        $videos=vp3_media_preferences_rank_v1($videos,$preferences,'youtube',$relevance);
        if(!$videos)continue;
        if($similar){
            $available=array_fill_keys(array_column($videos,'video_id'),true);
            $similar=array_values(array_filter($similar,static fn(array $row):bool=>isset($available[$row['video']['video_id']])));
        }
        $unplayed=array_values(array_filter($videos,static fn(array $video):bool=>!isset($seen[$video['video_id']])));
        $selected=($preferences['ready']??false)===true?$videos[0]:($unplayed[0]??$videos[0]);
        $title=vp3_agent_activity_text_v1($selected['title'],160);
        $channel=vp3_agent_activity_text_v1($selected['channel_title']??'',90);
        $playlist=$suggestion==='playlist'&&count($videos)>=2;
        $ref='media:youtube:'.$record['message_id'];
        $trigger=$proactive&&$subject!==''&&$created>=$now-7200&&$unplayed&&!isset($playedSources[$record['message_id']])
            &&vp3_youtube_research_enabled_v1($pdo,$owner,$artistContext?'artist':'topic')?'recent_search':null;
        $summary=($playlist?'Your saved YouTube playlist for ':'Your recent YouTube search for ').'“'.$query
            .'” has '.count($videos).' playable '.(count($videos)===1?'video':'videos').'.';
        if($unplayed)$summary.=' One pick is “'.$title.'”'.($channel===''?'':' from '.$channel).'.';
        if($similar)$summary=vp3_media_similar_artist_summary_v1($similar);
        $items[$ref]=['ref'=>$ref,'revision'=>hash('sha256',json_encode([$owner,$agent,$chat,$record['message_id'],$created,
                $record['expires_at'],$result,$selected['video_id']],JSON_THROW_ON_ERROR)),
            'kind'=>$similar?'similar_artists':($playlist?'playlist':'youtube_pick'),
            'title'=>$similar?'Similar artists for '.$subject:($playlist?'YouTube playlist · '.$query:$title),
            'summary'=>$summary,'next_step'=>$similar?'Open the saved results in Chat to explore their tracks.'
                :($playlist?'Open the saved playlist in Chat.':'Play the saved video in Chat.'),
            'question'=>$trigger!==null?'Want me to play “'.$title.'” here?':'',
            'url'=>vp3_agent_activity_chat_href_v1($agent,$chat).'&message_id='.$record['message_id'],
            'created_at'=>gmdate(DATE_ATOM,$created),'trigger'=>$trigger,'priority'=>'low','status'=>'ready','specialist'=>'media',
            'playback'=>['query'=>$query,'expires_at'=>$record['expires_at'],'videos'=>[$selected],
                'source_message_id'=>$record['message_id'],'selected_video_id'=>$selected['video_id'],'initiative_suggestion'=>true]];
    }
    return ['items'=>$items]+($ids===null&&$taste!==''?['taste'=>$taste]:[]);
}

/** Reuse exact Initiative worker receipts and current artist/YouTube source permissions. */
function vp3_media_research_findings_v1(PDO $pdo,array $scope,?array $ids,DateTimeImmutable $now): array
{
    require_once __DIR__.'/agent-job-engine-v1900.php';
    if(!agent_job_engine_schema_ready_v1900($pdo))return [];
    require_once __DIR__.'/agent-initiative-research-status-v1.php';
    $owner=(int)$scope['user']['id'];
    $agent=$scope['agent_id'];$chat=$scope['conversation_id'];$epoch=$now->getTimestamp();
    $sqlite=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite';
    $json=static function(string $path)use($sqlite):string{
        $value="JSON_EXTRACT(CASE WHEN JSON_VALID(r.goal) THEN r.goal ELSE '{}' END,'$path')";
        return $sqlite?$value:"JSON_UNQUOTE($value)";
    };
    $agentFilter=$agent>0?'r.agent_id=?':'r.agent_id IS NULL';
    $conversation=$json('$.binding.conversation_id');
    $tool=$json('$.tool');$primary=$json('$.primary');
    $exact=$ids===null?'':' AND r.id IN ('.implode(',',array_fill(0,count($ids),'?')).')';
    $query=$pdo->prepare("SELECT r.id AS run_id,r.goal,r.status AS run_status,a.id AS action_id,a.attempt_count,
        a.result_json,a.completed_at AS action_completed_at FROM agent_workflow_runs r JOIN agent_workflow_actions a
        ON a.run_id=r.id AND a.owner_user_id=r.owner_user_id AND a.sequence_no=1 AND a.action_key='initiative.prepare'
        AND a.capability_key='initiative.prepare' AND a.execution_target='cloud' AND a.status='completed'
        WHERE r.owner_user_id=? AND $agentFilter AND CAST($conversation AS CHAR)=?
        AND r.workflow_type='initiative' AND r.source_kind='agent_initiative' AND r.capability_key='initiative.prepare'
        AND r.execution_target='cloud' AND r.status='completed' AND $tool IN ('artist.research','topic.research')
        AND ($primary LIKE 'native-artist-interest:%' OR $primary LIKE 'artist-interest:%' OR $primary LIKE 'topic-interest:%')
        AND a.completed_at BETWEEN ? AND ?$exact ORDER BY a.completed_at DESC,r.id DESC LIMIT 8");
    $query->execute([$owner,...($agent>0?[$agent]:[]),(string)$chat,gmdate('Y-m-d H:i:s',$epoch-86400),
        gmdate('Y-m-d H:i:s',$epoch),...($ids??[])]);
    $candidates=[];$sourceRefs=[];
    foreach($query->fetchAll(PDO::FETCH_ASSOC) as $row){
        $goal=json_decode((string)$row['goal'],true);
        $result=json_decode((string)$row['result_json'],true);
        $saved=$goal['binding']??[];
        $source=$goal['primary']??null;
        $kind=($goal['tool']??null)==='artist.research'?'artist':'topic';
        if(!is_string($source)||!vp3_public_research_source_ref_valid_v1($kind,$source)||!is_array($saved)
            ||!is_array($goal['sources']??null)||array_keys($goal['sources'])!==[$source]||vp3_initiative_binding_agent_v1($saved)!==$agent
            ||($saved['owner_user_id']??($agent===0?$owner:null))!==$owner)continue;
        $work=$result['initiative_tool_result']??null;
        $verified=is_array($work)?vp3_initiative_research_tool_result_v1($work):null;
        if($verified===null||($result['effect_state']??null)!=='private_draft_ready'
            ||($result['initiative_draft']??null)!==$verified['draft']
            ||$verified['output']['type']!=='initiative_'.$kind.'_research'||$verified['output']['status']!=='read_complete'
            ||$verified['output']['source_ref']!==$source||$verified['output']['source_revision']!==$goal['sources'][$source])continue;
        $checked=strtotime($verified['output']['checked_at']);
        if($checked===false||$checked>$epoch||$checked<=$epoch-86400)continue;
        $delivery=$result['initiative_delivery']??null;
        $posted=null;
        if($delivery==='private'){
            if(!in_array($result['initiative_message_id']??null,[0,'0'],true)
                ||!vp3_initiative_research_status_receipt_v1($pdo,$owner,$row))continue;
        }else{
            $completion=vp3_initiative_research_status_completion_v1($pdo,$owner,$agent,$chat,$row,$goal,$epoch,true);
            if($completion===null)continue;
            $posted=$completion['message_id']??null;
        }
        $candidates[]=['run_id'=>(int)$row['run_id'],'source'=>$source,'kind'=>$kind,'checked'=>$checked,'posted'=>$posted,'work'=>$verified];
        $sourceRefs[$source]=true;
    }
    if(!$candidates)return [];
    $coverage=[];
    $current=vp3_initiative_research_evidence_v1($pdo,$scope,$now,array_keys($sourceRefs),$coverage);
    $items=[];
    foreach($candidates as $candidate){
        $work=$candidate['work'];
        $source=$current[$candidate['source']]??null;
        if(($source['revision']??null)!==$work['output']['source_revision'])continue;
        $subject=vp3_agent_activity_text_v1($source['title']??'',120);
        $summary=vp3_agent_activity_text_v1($work['draft'],500);
        if($subject===''||$summary==='')continue;
        $run=$candidate['run_id'];$posted=$candidate['posted'];$kind=$candidate['kind'];
        $playlist=null;
        if(($work['output']['playlist_run_id']??null)===$run){
            $playlist=$posted===null?vp3_initiative_youtube_playlist_load_v1($pdo,$owner,$run)
                :(agent_connection_youtube_result_records_v1($pdo,$owner,$agent,$chat,1,[$posted])[0]['results']??null);
            if(!in_array($playlist['suggestion_kind']??null,['playlist','similar'],true)||($playlist['initiative_suggestion']??null)!==true
                ||($playlist['expires_at']??0)<=$epoch)$playlist=null;
        }
        $videoCount=$playlist===null?0:count(array_filter(agent_connection_youtube_ordered_videos_v1($playlist['videos']),
            static fn(array $video):bool=>($video['made_for_kids']??null)===false));
        $similar=[];
        if($playlist!==null&&$kind==='artist'){
            require_once __DIR__.'/agent-media-similar-artists-v1.php';
            $similar=vp3_media_similar_artist_payload_results_v1($playlist);
            if($similar)$summary=vp3_media_similar_artist_summary_v1($similar).' '.$summary;
        }
        $ref='media:research:'.$run;
        $artistUrl=str_starts_with($candidate['source'],'native-artist-interest:')?$source['url']:null;
        $url=$posted!==null?vp3_agent_activity_chat_href_v1($agent,$chat).'&message_id='.$posted:$artistUrl;
        $items[$ref]=['ref'=>$ref,'revision'=>hash('sha256',json_encode([$owner,$agent,$chat,$run,$work,$posted,$videoCount,$similar],JSON_THROW_ON_ERROR)),
            'kind'=>$kind==='artist'?'artist_research':'video_research','title'=>$subject,'summary'=>$summary,
            'next_step'=>$similar?($posted===null?'Similar-artist suggestions with '.count($similar).' playable tracks are ready.'
                :'Open the report in Chat to try the similar-artist suggestions.')
                :($videoCount>=2?($posted===null?'A private playlist with '.$videoCount.' playable videos is ready.'
                    :'Open the '.$videoCount.'-video playlist in Chat.'):($artistUrl!==null?'Explore the artist’s VP3 profile.':'')),
            'question'=>'','url'=>$url??'','created_at'=>gmdate(DATE_ATOM,$candidate['checked']),'trigger'=>null,
            'priority'=>'low','status'=>'ready','specialist'=>'media']
            +($artistUrl!==null?['artist_url'=>$artistUrl]:[]);
    }
    return $items;
}
