<?php
declare(strict_types=1);

require_once __DIR__.'/agent-connection-youtube-playback-v1.php';
require_once __DIR__.'/agent-public-research-plan-v1.php';

/** Correlate saved owner queries without using result titles or channel names as public search terms. */
function vp3_initiative_youtube_subject_v1(array $result): string
{
    $suggestion=$result['suggestion_kind']??'';
    if($suggestion==='')return vp3_topic_research_youtube_subject_v1($result);
    if(!in_array($suggestion,['similar','playlist','music','artists'],true))return '';
    $subject=$result['owner_topic']??null;
    // Before owner_topic was recorded, similar searches always needed an explicit subject.
    if($subject===null&&($suggestion==='similar'||($result['suggestion_reason']??null)==='Based on the music you asked for.')){
        $subject=$result['taste_topic']??null;
    }
    return is_string($subject)&&vp3_topic_research_subject_valid_v1($subject)?$subject:'';
}

function vp3_initiative_youtube_terms_v1(string $phrase): array
{
    $terms=explode(' ',$phrase);
    $terms=array_diff($terms,['a','an','the','for','to','of','on','and','with','in','at','how','best','top','youtube','video','videos',
        'guide','tips','tutorial','explained','review','search','find','show','watch','play']);
    return array_values(array_unique(array_map(static fn(string $term): string=>
        strlen($term)>=5&&preg_match('/[^su]s$/D',$term)?substr($term,0,-1):$term,$terms)));
}

/** Two different phrases need several shared terms; a generic word cannot join two topics. */
function vp3_initiative_youtube_related_v1(array $anchor,array $search): bool
{
    if($anchor['kind']!==$search['kind'])return false;
    if($anchor['phrase']===$search['phrase'])return true;
    if($anchor['kind']==='artist')return false;
    $shared=count(array_intersect($anchor['terms'],$search['terms']));
    return $shared>=2&&3*$shared>=2*min(count($anchor['terms']),count($search['terms']));
}

function vp3_initiative_youtube_watch_stats_v1(array $watches,int $after): array
{
    usort($watches,static fn(array $a,array $b): int=>$a['played_at']<=>$b['played_at']);
    $episodes=[];$current=[];
    foreach($watches as $watch){
        $video=$watch['video_id'];$start=$watch['played_at'];$seconds=$watch['watch_seconds'];$last=$watch['last_watched_at'];
        if(isset($current[$video])&&$start-$episodes[$current[$video]]['start']<900){
            $index=$current[$video];
            $episodes[$index]['seconds']=max($episodes[$index]['seconds'],$seconds);
            if($last>=$episodes[$index]['last']){
                $episodes[$index]['last']=$last;
                $episodes[$index]['chat']=$watch['conversation_id'];
            }
        }else{
            $current[$video]=count($episodes);
            $episodes[]=['start'=>$start,'seconds'=>$seconds,'last'=>$last,'chat'=>$watch['conversation_id']];
        }
    }
    // Filter whole episodes after grouping so heartbeats and quick reopens of older viewing do not qualify.
    $episodes=array_filter($episodes,static fn(array $episode): bool=>$episode['start']>$after);
    $last=0;$chat=0;
    foreach($episodes as $episode){
        if($episode['last']>=$last){$last=$episode['last'];$chat=$episode['chat'];}
    }
    $times=array_column($episodes,'seconds');
    return ['sessions'=>count($times),'total'=>array_sum($times),'longest'=>$times?max($times):0,'last'=>$last,'chat'=>$chat];
}

function vp3_initiative_youtube_pattern_stats_v1(array $searches,array $watches,int $after=0): array
{
    $unique=[];$phrases=[];$searchCount=0;$last=0;$chat=0;
    foreach($searches as $search){
        $recorded=$search['created_at_epoch'];$cid=$search['conversation_id'];$phrase=$search['phrase'];
        $key=$phrase.'|'.$cid;
        if(!isset($unique[$key])||$recorded-$unique[$key]>=900){
            $unique[$key]=$recorded;
            if($recorded>$after){$phrases[$phrase]=true;$searchCount++;}
        }
        if($recorded>$after&&$recorded>=$last){$last=$recorded;$chat=$cid;}
    }
    $watch=vp3_initiative_youtube_watch_stats_v1($watches,$after);
    if($watch['last']>$last){$last=$watch['last'];$chat=$watch['chat'];}
    $searchPattern=count($phrases)>=2||$searchCount>=3;
    $viewPattern=$watch['sessions']>=2||$watch['longest']>=120||($watch['sessions']>0&&$searchCount>=2);
    return ['search_count'=>$searchCount,'related_search_count'=>count($phrases),'watch_sessions'=>$watch['sessions'],
        'watch_seconds'=>$watch['total'],'last_signal_at'=>$last,'conversation_id'=>$chat,
        'search_pattern'=>$searchPattern,'view_pattern'=>$viewPattern];
}

/** Recent YouTube activity is evidence for Initiative. Its planner decides whether research would help. */
function vp3_initiative_research_evidence_v1(PDO $pdo, array $scope, DateTimeImmutable $now, ?array $selectedRefs, array &$coverage): array
{
    $kinds=[];
    foreach(['artist','topic'] as $kind){
        if ($selectedRefs === null || array_filter($selectedRefs, static fn($ref): bool => is_string($ref)
            && (str_starts_with($ref,$kind.'-interest:')||str_starts_with($ref,'youtube-interest-checkin:'.$kind.':')))) $kinds[$kind]=true;
    }
    if (!$kinds) return [];

    $owner = (int)$scope['user']['id'];
    $agent = (int)$scope['agent_id'];
    $allowed=$owner > 0 && $agent >= 0 && has_permission('account.access', $scope['user'])
        && (!function_exists('vp3_participant_scope_v1') || vp3_participant_scope_v1() === null)
        && agent_connection_youtube_enabled_v1() && agent_connection_youtube_consent_v1($pdo, $owner);
    foreach(array_keys($kinds) as $kind){
        if($allowed&&vp3_youtube_research_enabled_v1($pdo,$owner,$kind))continue;
        $coverage[$kind.'_research']='forbidden';
        unset($kinds[$kind]);
    }
    if (!$kinds) return [];
    if (!agent_connection_youtube_results_ready_v1($pdo)) {
        foreach($kinds as $kind=>$_)$coverage[$kind.'_research']='unavailable';
        return [];
    }

    try {
        $at=$now->getTimestamp();
        $records=agent_connection_youtube_result_records_v1($pdo,$owner,$agent,limit:120);
        foreach($kinds as $kind=>$_)$coverage[$kind.'_research']='ready';
        $searches=[];
        foreach($records as $record){
            $payload=$record['results'];$kind=($payload['suggestion_kind']??null)==='similar'?'artist':'topic';
            $recorded=$record['created_at_epoch'];
            if(!isset($kinds[$kind])||$recorded>$at||$recorded<=$at-7*86400)continue;
            $subject=vp3_initiative_youtube_subject_v1($payload);
            if($subject==='')continue;
            $phrase=implode(' ',preg_split('/[^\p{L}\p{N}]+/u',mb_strtolower($subject),-1,PREG_SPLIT_NO_EMPTY)?:[]);
            if($phrase==='')continue;
            $searches[]=['message_id'=>$record['message_id'],'conversation_id'=>$record['conversation_id'],'created_at_epoch'=>$recorded,
                'kind'=>$kind,'subject'=>$subject,'phrase'=>$phrase,'terms'=>vp3_initiative_youtube_terms_v1($phrase)];
        }
        if(!$searches)return [];
        usort($searches,static fn(array $a,array $b): int=>($a['created_at_epoch']<=>$b['created_at_epoch'])?:($a['message_id']<=>$b['message_id']));

        $capture='';$watches=[];
        if(vp3_youtube_watch_research_enabled_v1($pdo,$owner)&&vp3_youtube_watch_ready_v1($pdo)){
            $capture=vp3_youtube_capture_key_v1($pdo,$owner);
            if($capture!==''){
                foreach(vp3_youtube_history_recent_v1($pdo,$owner,$agent,limit:120) as $play){
                    if($play['watch_seconds']>=30&&$play['played_at']>$at-7*86400&&$play['last_watched_at']<=$at){
                        $watches[$play['message_id']][]=$play;
                    }
                }
                if(!vp3_youtube_watch_research_enabled_v1($pdo,$owner)||!hash_equals($capture,vp3_youtube_capture_key_v1($pdo,$owner))){
                    $capture='';$watches=[];
                }
            }
        }

        $groups=[];
        foreach($searches as $search){
            foreach($groups as $ref=>$group){
                if(vp3_initiative_youtube_related_v1($group[0],$search)){
                    $groups[$ref][]=$search;
                    continue 2;
                }
            }
            $groups[$search['kind'].'-interest:'.hash('sha256',mb_strtolower($search['subject']))]=[$search];
        }

        require_once __DIR__.'/agent-initiative-engagement-feedback-v1.php';
        $feedbackRefs=$selectedRefs===null?array_reverse(array_keys($groups)):array_values(array_filter(
            array_map('vp3_initiative_youtube_interest_ref_v1',array_filter($selectedRefs,'is_string')),
            static fn(?string $ref): bool=>$ref!==null&&isset($groups[$ref])));
        $decisions=[];
        $feedback=vp3_initiative_youtube_interest_feedback_v1($pdo,$scope['user'],$agent,$feedbackRefs,$now,$decisions);
        $evidence=[];
        foreach($groups as $interestRef=>$group){
            $anchor=$group[0];$kind=$anchor['kind'];$subject=$anchor['subject'];
            $topic=substr($interestRef,strpos($interestRef,':')+1);
            $checkInRef='youtube-interest-checkin:'.$kind.':'.$topic;
            if($selectedRefs!==null&&!in_array($interestRef,$selectedRefs,true)&&!in_array($checkInRef,$selectedRefs,true))continue;
            $watchRows=[];
            foreach($group as $search){
                foreach($watches[$search['message_id']]??[] as $watch)$watchRows[]=$watch;
            }
            $signal=vp3_initiative_youtube_pattern_stats_v1($group,$watchRows);
            $strong=$signal['search_pattern']||$signal['view_pattern'];
            if((!$strong&&$signal['search_count']<2&&!$signal['watch_sessions'])||$signal['last_signal_at']<=$at-2*86400)continue;
            $disposition=$feedback[$interestRef]??null;
            $decision=$decisions[$interestRef]??[];
            $reopened=null;$declinedAt=$decision['at']??0;
            if($disposition==='suppress'&&($decision['intent']??null)==='decline'&&$declinedAt>0){
                $fresh=vp3_initiative_youtube_pattern_stats_v1($group,$watchRows,$declinedAt);
                if(($fresh['search_pattern']||$fresh['view_pattern'])&&$fresh['search_count']+$fresh['watch_sessions']>=2
                    &&$fresh['last_signal_at']>$at-2*86400)$reopened=$fresh;
            }
            $refs=[];
            if($reopened!==null||(($strong||$disposition==='positive')&&$disposition!=='suppress'))$refs[$interestRef]=$kind.'_interest';
            // Exact checks can still rehydrate the conversation that clarified a formerly tentative pattern.
            if(($selectedRefs===null&&$disposition===null)
                ||($selectedRefs!==null&&in_array($checkInRef,$selectedRefs,true)))$refs[$checkInRef]='youtube_interest_checkin';

            foreach($refs as $ref=>$evidenceKind){
                if($selectedRefs!==null&&!in_array($ref,$selectedRefs,true))continue;
                $research=$ref===$interestRef;
                $current=$research?($reopened??$signal):$signal;
                $searchCount=$current['search_count'];$sessions=$current['watch_sessions'];
                $detail=[];
                if($searchCount)$detail[]=$searchCount.' distinct YouTube '.($searchCount===1?'search':'searches');
                if($sessions)$detail[]=$sessions.' meaningful viewing '.($sessions===1?'session':'sessions').' totaling '.$current['watch_seconds'].' seconds';
                $detail=implode(' and ',$detail).($research&&$reopened!==null?' since the owner declined this topic':' in the last seven days');
                if($disposition==='positive')$detail.='. The owner confirmed this direction in a check-in';
                $basis=$research?($disposition==='positive'?'clarified':($current['view_pattern']?'viewing':'searches')):'clarify';
                // Only a viewing-dependent candidate is revoked when the user clears or disables watch use.
                $watchBound=$research?!$current['search_pattern']&&($current['view_pattern']||$searchCount<2):$searchCount<2;
                $identity=[$owner,$agent,$kind,$topic,$anchor['message_id'],$watchBound?$capture:''];
                if($research&&$reopened!==null)$identity[]=$declinedAt;
                $evidence[$ref]=[
                    'ref'=>$ref,'kind'=>$evidenceKind,'title'=>$subject,
                    'detail'=>$detail.($research?'. Consider whether public research would add something useful.'
                        :'. Ask one short question only if clarification would help.'),
                    'url'=>url('/chat.php?agent='.($agent ?: 'system').'&conversation_id='.$current['conversation_id']),
                    'revision'=>hash('sha256',implode('|',$identity)),
                    'interest'=>array_diff_key($current,['conversation_id'=>true,'search_pattern'=>true,'view_pattern'=>true])+['basis'=>$basis],
                    'interest_ref'=>$interestRef,
                ];
            }
        }
        if($selectedRefs!==null)return $evidence;
        uasort($evidence,static fn(array $a,array $b): int=>$b['interest']['last_signal_at']<=>$a['interest']['last_signal_at']);
        $limited=[];
        foreach(array_keys($kinds) as $kind)$limited+=array_slice(array_filter($evidence,
            static fn(array $row): bool=>$row['kind']===$kind.'_interest'),0,3,true);
        $checkIns=array_filter($evidence,static fn(array $row): bool=>$row['kind']==='youtube_interest_checkin');
        $tentative=array_filter($checkIns,static fn(array $row): bool=>!isset($evidence[$row['interest_ref']]));
        return $limited+array_slice($tentative+$checkIns,0,2,true);
    } catch (PDOException $error) {
        foreach($kinds as $kind=>$_)$coverage[$kind.'_research']='unavailable';
        return [];
    }
}

function vp3_initiative_research_result_v1(array $toolResult): array
{
    $output = $toolResult['output'] ?? [];
    return [
        'status'=>$output['status'] ?? '', 'reason'=>$output['reason'] ?? '',
        'answer'=>$toolResult['draft'] ?? '', 'sources'=>$toolResult['sources'] ?? [],
        'checked_at'=>$output['checked_at'] ?? '',
    ];
}
