<?php
declare(strict_types=1);

function vp3_stoneward_chat_alias_v1(string $address): ?string
{
    return vp3_connected_sites_chat_name_match_v1($address,'Stonefellow')
        ??vp3_connected_sites_chat_name_match_v1($address,'Stoneward');
}

function vp3_stoneward_chat_play_address_v1(string $query): ?string
{
    $site='(?<site>Stonefellow|Stoneward)';
    $via='(?:\s+via\s+(?<host>[a-z0-9][a-z0-9.-]{0,251}[a-z0-9]))?';
    $prefix='/\A(?:please\s+)?';
    $end='[?.!]*\z/iu';
    if(preg_match($prefix.'play\s+(.{1,190}?)\s+(?:from|on)\s+'.$site.$via.$end,$query,$parts)){
        $operation='play '.$parts[1];
    }elseif(preg_match($prefix.'(?:resume|continue)(?:\s+(?:my|the))?(?:\s+last)?\s+'.$site.'(?:\s+(?:track|song))?'.$via.$end,$query,$parts)){
        $operation='resume my last track';
    }elseif(preg_match($prefix.'(?:make|build|prepare|create)\s+(?:me\s+)?(?:a\s+)?(?:short\s+)?'.$site.'\s+(?:queue|playlist)'.$via.$end,$query,$parts)
        ||preg_match($prefix.'queue\s+(?:some\s+)?(?:songs|tracks)\s+from\s+'.$site.$via.$end,$query,$parts)){
        $operation='make a short queue';
    }else{
        return null;
    }
    return $parts['site'].(!empty($parts['host'])?' via '.$parts['host']:'').' '.$operation;
}

function vp3_stoneward_chat_ranking_v1(string $question): bool
{
    return (bool)preg_match('/\b(?:most[-\s]+played|(?:play|played|listen(?:ed)?\s+to)\s+(?:the\s+)?most)\b/iu',$question);
}

function vp3_stoneward_chat_followup_v1(string $query): bool
{
    return (bool)preg_match(
        '/\A(?:which|what)\s+(?:(?:(?:track|song)\s+)?(?:did|have)\s+i\s+(?:play(?:ed)?|listen(?:ed)?\s+to)\s+(?:the\s+)?most|(?:is|was)\s+my\s+most[-\s]+played\s+(?:track|song))[?.!]*\z/iu',
        trim($query)
    );
}

function vp3_stoneward_chat_plan_v1(array $site,string $question,?int $previousDays=null): array
{
    $context=['provider'=>'stoneward','connectionId'=>$site['id']];
    if(preg_match('/\A(?:to\s+)?(?:resume|continue)(?:\s+(?:(?:my|the)\s+)?(?:last\s+)?(?:track|song))?[?.!]*\z/iu',$question)){
        return ['connection'=>$site,'resume'=>true,'context'=>$context+['actions'=>['listening.track.play']]];
    }
    if(preg_match('/\A(?:to\s+)?(?:(?:make|build|prepare|create)\s+(?:me\s+)?(?:a\s+)?(?:short\s+)?(?:queue|playlist)|queue\s+(?:some\s+)?(?:songs|tracks))[?.!]*\z/iu',$question)){
        return ['connection'=>$site,'queue'=>true,'context'=>$context+['actions'=>['listening.track.queue']]];
    }
    if(preg_match('/\A(?:(?:to|please)\s+)?play\s+(.+)\z/iu',$question,$match)){
        $title=trim(preg_match('/\A["“](.+?)["”][.!?]*\z/u',$match[1],$quoted)?$quoted[1]:$match[1]);
        if(vp3_stoneward_text_v1($title,180)==='')return ['answer'=>'Give me the song’s exact title to play it from this artist site.'];
        return ['connection'=>$site,'play'=>['title'=>$title],
            'context'=>$context+['actions'=>['listening.track.play']]];
    }
    $rank=vp3_stoneward_chat_ranking_v1($question);
    if(!$rank&&!preg_match('/\b(?:listening\s+history|listened\s+to|i\s+played|(?:did|have)\s+i\s+(?:play|listen\s+to)|recent\s+plays)\b/iu',$question)){
        return ['answer'=>'Ask this artist site for your recent listening history or give me the title of a song to play.'];
    }
    $days=$previousDays??7;
    if(preg_match('/\b(?:past|last)\s+(\d+)\s+days?\b/iu',$question,$match)){
        if(!in_array($match[1],['1','7','30'],true)){
            return ['answer'=>'Choose a window of 1, 7, or 30 days for Stonefellow listening history.'];
        }
        $days=(int)$match[1];
    }elseif(preg_match('/\b(?:last month|this month)\b/iu',$question))$days=30;
    elseif(preg_match('/\b(?:last week|this week)\b/iu',$question))$days=7;
    elseif(preg_match('/\b(?:today|yesterday|on\s+\d{4}-\d{2}-\d{2}|between|since|(?:this|last|past)\s+(?:quarter|year)|(?:last|past)\s+\d+\s+(?:weeks?|months?|years?))\b/iu',$question)){
        return ['answer'=>'Choose a rolling window of 1, 7, or 30 days for Stonefellow listening history.'];
    }
    return ['connection'=>$site,'context'=>$context+['actions'=>['listening.history.read'],'days'=>$days,'rank'=>$rank]];
}

function vp3_stoneward_chat_play_error_v1(Throwable $error): string
{
    return match((int)$error->getCode()){
        403=>'The artist site declined playback. Check your permissions in Stonefellow and reconnect if needed.',
        404=>'I couldn’t find that song as a full public stream on this artist site.',
        409=>'The artist site couldn’t resolve a single playable track. Check the title or choose the artist domain.',
        501=>'Update this Stonefellow artist site to enable playback in VP3.',
        default=>'I couldn’t verify the artist site’s audio stream right now. Try again in a moment.',
    };
}

function vp3_stoneward_chat_resume_track_v1(
    PDO $pdo,array $user,array $principal,array $resume,?callable $transport=null
): array
{
    $connection=$resume['connection_id'];
    $playback=vp3_stoneward_playable_v1($pdo,(int)$user['id'],$connection,['publicTrackId'=>$resume['track_id']],$transport);
    $current=vp3_stoneward_playback_resume_v1($pdo,$user,$principal,$connection);
    if(($current['revision']??null)!==$resume['revision']){
        throw new RuntimeException('The saved Stonefellow listening position changed.',409);
    }
    return $playback+['position_seconds'=>$resume['position_seconds']];
}

/** Attach live-only player tickets after the corresponding assistant message exists. */
function vp3_stoneward_chat_live_playback_v1(
    PDO $pdo,array $user,array $principal,int $cid,int $assistant,string $connection,array $result,?int $offerMessageId=null
): array
{
    $fields=array_intersect_key($result,['connected_site_playback_v1'=>true,'connected_site_queue_v1'=>true]);
    if(!$fields||$assistant<1)return [];
    require_once __DIR__.'/agent-media-stoneward-playback-v1.php';
    $issue=static function(array $playback)use($pdo,$user,$principal,$cid,$assistant,$connection,$offerMessageId):array{
        $ticket=vp3_stoneward_playback_ticket_v1($pdo,$user,$principal,$cid,$assistant,$connection,$playback,$offerMessageId);
        return $ticket===null?$playback:$playback+['ticket'=>$ticket];
    };
    if(isset($fields['connected_site_playback_v1']))$fields['connected_site_playback_v1']=$issue($fields['connected_site_playback_v1']);
    if(isset($fields['connected_site_queue_v1']))$fields['connected_site_queue_v1']=array_map($issue,$fields['connected_site_queue_v1']);
    return $fields;
}

function vp3_stoneward_chat_answer_v1(array $context,array $read,string $name): string
{
    $plays=$read['data']['plays'];
    $truncated=$read['data']['truncated'];
    $days=$context['days'];
    $window=$days===1?'past 24 hours':'past '.$days.' days';
    if(!$plays)return $name.' · Listening history'."\n\n".'No recorded plays in the '.$window.'.';
    if(!empty($context['rank'])){
        $tracks=[];
        foreach($plays as $play){
            $artist=vp3_connected_sites_chat_text_v1($play['artist'],100);
            $title=vp3_connected_sites_chat_text_v1($play['title'],140);
            $id=$play['trackId'];
            $tracks[$id]??=['label'=>$title.($artist!==''?' by '.$artist:''),'count'=>0];
            $tracks[$id]['count']++;
        }
        $highest=max(array_column($tracks,'count'));
        $leaders=array_filter($tracks,static fn(array $track):bool=>$track['count']===$highest);
        $lines=[$truncated?'Most played among the returned sessions in the '.$window.'.'
            :'Most played in the '.$window.'.'];
        foreach(array_slice($leaders,0,10) as $track){
            $lines[]='• '.$track['label'].' · '.$track['count'].($track['count']===1?' play':' plays');
        }
        if(count($leaders)>10)$lines[]='Showing 10 of '.count($leaders).' tied tracks.';
        if($truncated)$lines[]='The site returned a partial history, so the ranking can change with more records.';
    }else{
        $lines=['Recorded plays in the '.$window.':'];
        foreach(array_slice($plays,0,12) as $play){
            $artist=vp3_connected_sites_chat_text_v1($play['artist'],100);
            $lines[]='• '.vp3_connected_sites_chat_text_v1($play['title'],140)
                .($artist!==''?' by '.$artist:'').' · '.substr($play['playedAt'],0,10);
        }
        if(count($plays)>12)$lines[]='Showing the 12 most recent of '.count($plays).' returned plays.';
        if($truncated)$lines[]='The site returned a partial history for this window.';
    }
    return $name.' · Listening history'."\n\n".implode("\n",$lines);
}
