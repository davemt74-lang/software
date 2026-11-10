<?php
declare(strict_types=1);

require_once __DIR__.'/agent-connection-youtube-playback-v1.php';
require_once __DIR__.'/agent-public-research-plan-v1.php';
require_once __DIR__.'/agent-initiative-native-music-v1.php';

/** Correlate saved owner queries without using result titles or channel names as public search terms. */
function vp3_initiative_youtube_subject_v1(array $result): string
{
    if (($result['initiative_suggestion']??false)===true) return '';
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

/** Only the owner's words can name an artist. Public Music results corroborate the name. */
function vp3_initiative_youtube_artist_subject_v1(string $subject,array $videos): ?string
{
    $artist=preg_replace('/\A(?:songs|albums|music|music videos|live performances) (?:by|from) /iu','',$subject)??$subject;
    $artist=preg_replace('/\s+(?:live(?:\s+.*)?|concert(?:s|\s+.*)?|tour(?:\s+.*)?|songs|albums|discography|(?:official )?music videos)\z/iu','',$artist)??$artist;
    $artist=trim($artist," \t\"“”");
    if(mb_strlen($artist)>80||!vp3_topic_research_subject_valid_v1($artist))return null;
    $name=mb_strtolower($artist);
    $titles=[];
    foreach(array_slice($videos,0,6) as $video){
        if(($video['music_category']??false)!==true||!is_bool($video['made_for_kids']??null))continue;
        $channel=$video['channel_title']??null;
        if(is_string($channel)){
            $channel=preg_replace('/\s+(?:-\s+Topic|Official|VEVO)\z/iu','',trim($channel))??$channel;
            if(mb_strtolower($channel)===$name)return $artist;
        }
        $title=$video['title']??null;
        if(is_string($title)&&preg_match('/\A(.+?)\s+[-–—]\s+/u',$title,$match)&&mb_strtolower($match[1])===$name){
            $id=$video['video_id']??null;
            if(is_string($id)&&preg_match('/\A[A-Za-z0-9_-]{11}\z/D',$id))$titles[$id]=true;
        }
    }
    return count($titles)>=2?$artist:null;
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
function vp3_initiative_youtube_research_evidence_v1(PDO $pdo, array $scope, DateTimeImmutable $now, ?array $selectedRefs, array &$coverage): array
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
            if($recorded>$at||$recorded<=$at-7*86400)continue;
            $subject=vp3_initiative_youtube_subject_v1($payload);
            if($subject==='')continue;
            if($kind==='topic'&&empty($payload['suggestion_kind'])){
                $artist=vp3_initiative_youtube_artist_subject_v1($subject,$payload['videos']);
                if($artist!==null){$kind='artist';$subject=$artist;}
            }
            if(!isset($kinds[$kind]))continue;
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

function vp3_initiative_artist_recent_runs_v1(PDO $pdo,array $scope,array $refs,DateTimeImmutable $now,?array $binding=null): array
{
    $mysql=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql';
    $field=static function(string $name)use($mysql):string{
        $value="JSON_EXTRACT(CASE WHEN JSON_VALID(goal) THEN goal ELSE '{}' END,'$.$name')";
        return $mysql?"JSON_UNQUOTE($value)":$value;
    };
    $primary=$field('primary');$tool=$field('tool');
    $list=implode(',',array_fill(0,count($refs),'?'));
    $q=$pdo->prepare("SELECT $primary AS source_ref,status,goal FROM agent_workflow_runs
        WHERE owner_user_id=? AND CAST(COALESCE(agent_id,0) AS CHAR)=?
            AND source_kind='agent_initiative' AND capability_key='initiative.prepare' AND $tool='artist.research'
            AND status IN ('queued','approved','executing','completed') AND created_at>=? AND $primary IN ($list)");
    $q->execute([(int)$scope['user']['id'],(string)$scope['agent_id'],gmdate('Y-m-d H:i:s',$now->getTimestamp()-7*86400),...$refs]);
    $completed=[];$unfinished=[];
    foreach($q->fetchAll(PDO::FETCH_ASSOC) as $row){
        $ref=$row['source_ref'];
        if($row['status']==='completed'){$completed[$ref]=true;continue;}
        $goal=json_decode($row['goal'],true);
        $binding??=function_exists('vp3_initiative_policy_v1')?vp3_initiative_policy_v1($scope['user'],$scope['agent_id']):[];
        if(($binding['enabled']??false)!==true||($goal['binding']??null)!==$binding)continue;
        $revision=$goal['canonical_sources'][$ref]??$goal['sources'][$ref]??null;
        if(is_string($revision))$unfinished[$ref][$revision]=true;
    }
    $pending=array_keys(array_diff_key($unfinished,$completed));
    if(!$pending)return array_keys($completed);

    // Stale or revoked work must not mute independent consent in the other source.
    $coverage=[];
    $current=vp3_initiative_native_music_evidence_v1($pdo,$scope,$now,$pending,$coverage)
        +vp3_initiative_youtube_research_evidence_v1($pdo,$scope,$now,$pending,$coverage);
    foreach($current as $ref=>$source){
        if(isset($unfinished[$ref][$source['revision']]))$completed[$ref]=true;
    }
    return array_keys($completed);
}

/** Admission repeats the cooldown under the canonical owner lock. */
function vp3_initiative_artist_duplicate_v1(PDO $pdo,array $user,array $binding,string $primary): bool
{
    if(!preg_match('/\A(native-artist-interest|artist-interest):([a-f0-9]{64})\z/D',$primary,$match))return false;
    $refs=['native-artist-interest:'.$match[2]];
    if($match[1]==='native-artist-interest')$refs[]='artist-interest:'.$match[2];
    $agent=vp3_initiative_binding_agent_v1($binding);
    $scope=['user'=>$user,'agent_id'=>$agent,'principal'=>vp3_initiative_principal_v1($pdo,$user,$agent)];
    return vp3_initiative_artist_recent_runs_v1($pdo,$scope,$refs,new DateTimeImmutable('now',new DateTimeZone('UTC')),$binding)!==[];
}

function vp3_initiative_research_evidence_v1(PDO $pdo,array $scope,DateTimeImmutable $now,?array $selectedRefs,array &$coverage): array
{
    $youtube=vp3_initiative_youtube_research_evidence_v1($pdo,$scope,$now,$selectedRefs,$coverage);
    $native=vp3_initiative_native_music_evidence_v1($pdo,$scope,$now,$selectedRefs,$coverage);
    if($selectedRefs!==null)return $youtube+$native;

    // A single artist can have independent evidence in two systems. Present one research choice.
    $refs=[];
    foreach($youtube+$native as $ref=>$source){
        if(preg_match('/\A(native-artist-interest|artist-interest|youtube-interest-checkin:artist):([a-f0-9]{64})\z/D',$ref,$match)){
            $refs[$match[2]][$match[1]]=$ref;
        }
    }
    if(!$refs)return $youtube+$native;
    foreach($refs as $group){
        if(isset($group['native-artist-interest'])){
            unset($youtube[$group['youtube-interest-checkin:artist']??'']);
            if(isset($group['artist-interest']))unset($native[$group['native-artist-interest']]);
        }
    }
    $primaries=[];
    foreach(array_keys($refs) as $subject){
        $primaries[]='native-artist-interest:'.$subject;
        $primaries[]='artist-interest:'.$subject;
    }
    try{
        foreach(vp3_initiative_artist_recent_runs_v1($pdo,$scope,$primaries,$now) as $primary){
            $group=$refs[substr($primary,-64)];
            unset($native[$group['native-artist-interest']??'']);
            // Preserve the existing YouTube behavior except when native listening already started the same research.
            if(str_starts_with($primary,'native-artist-interest:')){
                unset($youtube[$group['artist-interest']??''],$youtube[$group['youtube-interest-checkin:artist']??'']);
            }
        }
    }catch(PDOException){
        // The native lane needs durable duplicate prevention before it can launch.
        if($native)$coverage['native_artist_research']='unavailable';
        return $youtube;
    }
    return $youtube+$native;
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
