<?php
declare(strict_types=1);

/** One request contract for VP3 and YouTube music. Parsing has no I/O or side effects. */
const VP3_MUSIC_CHAT_MAX_ITEMS_V1 = 25;

function vp3_music_chat_words_v1(string $value): string
{
    return trim(preg_replace('/[^\p{L}\p{N}]+/u',' ',mb_strtolower($value))??'');
}

function vp3_music_chat_number_v1(string $value): ?int
{
    $value=str_replace('-',' ',mb_strtolower(trim($value)));
    $numbers=['one'=>1,'two'=>2,'three'=>3,'four'=>4,'five'=>5,'six'=>6,'seven'=>7,'eight'=>8,'nine'=>9,
        'ten'=>10,'eleven'=>11,'twelve'=>12,'thirteen'=>13,'fourteen'=>14,'fifteen'=>15,'sixteen'=>16,
        'seventeen'=>17,'eighteen'=>18,'nineteen'=>19,'twenty'=>20,'twenty one'=>21,'twenty two'=>22,
        'twenty three'=>23,'twenty four'=>24,'twenty five'=>25,'a'=>1,'an'=>1,'a couple'=>2,'a few'=>3];
    return preg_match('/\A[0-9]+\z/D',$value)===1?(int)$value:($numbers[$value]??null);
}

/** null leaves ordinary conversation, video search, history and existing DJ controls untouched. */
function vp3_music_chat_plan_v1(string $request): ?array
{
    if(strlen($request)>24000||preg_match('//u',$request)!==1)return null;
    // Quoted titles and saved-list names are data, never command nouns, source
    // restrictions or result counts. Restore them only after interpreting the turn.
    $quoted=[];$prefix='vp3quoted'.substr(hash('sha256',$request),0,16);
    $protected=preg_replace_callback('/["“]([^"”]{1,200})["”]/u',static function(array $match)use(&$quoted,$prefix):string{
        $token=$prefix.count($quoted);$quoted[$token]=$match[1];return '"'.$token.'"';
    },$request)??$request;
    $plan=vp3_music_chat_parse_v1($protected);
    if($plan===null)return null;
    foreach(['query','artist','playlist_title','playlist_name'] as $field){
        if(is_string($plan[$field]??null))$plan[$field]=strtr($plan[$field],$quoted);
    }
    if(mb_strlen((string)($plan['query']??''))>200)$plan['error']='query_too_long';
    return $plan;
}

function vp3_music_chat_parse_v1(string $request): ?array
{
    if(strlen($request)>24000||preg_match('//u',$request)!==1)return null;
    $text=trim(preg_replace('/[\s\p{Z}]+/u',' ',$request)??'');
    $text=preg_replace('/\A(?:(?:please[, ]+)|(?:(?:can|could|would|will) you\s+))+/iu','',$text)??$text;
    $text=preg_replace('/(?:[, ]+please)?[.!?]*\z/iu','',$text)??$text;
    if($text===''||preg_match('/\A(?:do not|don[\x{2019}\x27]t|never|how|why|what|when|which|who|explain|tell me)\b/iu',$text))return null;
    if(preg_match('/\b(?:watch history|listening history|listen(?:ed|ing) history|played (?:earlier|yesterday)|resume (?:my |the )?last|continue (?:my |the )?last)\b/iu',$text)
        ||preg_match('~\A(?:/dj\b|/music\s+resume\b|(?:start|stop|turn (?:on|off))\s+(?:the\s+)?DJ\b)~iu',$text))return null;
    // These named local services already have their own read/capability route.
    // The public parser shields quoted song and playlist titles before this check.
    if(preg_match('/\b(?:Home\s*Server|(?:Music|Media)\s+Server)\b/iu',$text))return null;
    // Relative playback controls belong to the existing active media context,
    // not a fresh title search. A quoted title such as "The Next One" stays literal.
    if(preg_match('/\A(?:show(?:\s+me)?|list|find|open|play|replay|watch|listen\s+to)\s+(?:the\s+)?(?:next|previous)(?:\s+(?:you\s*tube\s+)?(?:one|song|track|video|clip|result))?\z/iu',$text))return null;
    if(preg_match('/\b(?:videos?|movies?|films?|tutorials?|recipes?|documentar(?:y|ies)|lectures?|podcasts?|games?|chess|checkers|trivia|roleplay)\b/iu',$text))return null;
    if(preg_match('/\A(?:show|tell|teach|explain)(?:\s+me)?\s+(?:how|why|what|whether)\b/iu',$text))return null;

    // Explicit exclusions apply before interpreting nouns or playback verbs.
    $noPlay=preg_match('/(?:[,;]|\b(?:but|and))?\s*(?:do not|don[\x{2019}\x27]t|never)\s+(?:auto ?play|play)(?:\s+(?:it|them|anything|the playlist))?\s*\z/iu',$text)===1;
    if($noPlay)$text=preg_replace('/(?:[,;]|\b(?:but|and))?\s*(?:do not|don[\x{2019}\x27]t|never)\s+(?:auto ?play|play)(?:\s+(?:it|them|anything|the playlist))?\s*\z/iu','',$text)??$text;
    $text=preg_replace('/(?:[,;]|\b(?:but|and))?\s+(?:do not|don[\x{2019}\x27]t)\s+(?:create|make|save)\s+(?:a|any)\s+playlist\s*\z/iu','',$text)??$text;
    $text=preg_replace('/(?:[,;]|\bbut)?\s+not\s+(?:a\s+)?playlist\s*\z/iu','',$text)??$text;
    // A remaining negative action clause is not permission for a create/play mutation.
    if(preg_match('/\b(?:do not|don[\x{2019}\x27]t|never)\s+(?:create|make|save|play|queue)\b/iu',$text))return null;
    $createAndPlay=preg_match('/\A(?:create|make|build|prepare)\s+and\s+play\b/iu',$text)===1;
    if($createAndPlay)$text=preg_replace('/\A(create|make|build|prepare)\s+and\s+play\b/iu','$1',$text)??$text;
    $unsupportedSource=preg_match('/\b(?:on|from|using)\s+(?:spotify|apple music|soundcloud|bandcamp|stoneward)\b/iu',$text)===1;
    $source='both';
    $excludeYoutube=preg_match('/\b(?:not|without|excluding)\s+(?:(?:on|from|using|in)\s+)?you\s*tube\b/iu',$text)===1;
    $excludeVp3=preg_match('/\b(?:not|without|excluding)\s+(?:(?:on|from|using|in)\s+)?VP3\b/iu',$text)===1;
    $text=preg_replace('/(?:[,;]|\b(?:but|and))?\s+(?:not|without|excluding)\s+(?:(?:on|from|using|in)\s+)?(?:you\s*tube|VP3)\b/iu','',$text)??$text;
    $hasYoutube=preg_match('/\byou\s*tube\b/iu',$text)===1;
    $hasVp3=preg_match('/\bVP3\b|\b(?:my|the|main)\s+(?:music |audio )?library\b/iu',$text)===1;
    if($hasYoutube&&!$hasVp3)$source='youtube';
    elseif($hasVp3&&!$hasYoutube)$source='vp3';
    if($excludeYoutube)$source='vp3';
    if($excludeVp3)$source='youtube';
    $explicitSource=$hasYoutube||$hasVp3||$excludeYoutube||$excludeVp3;
    $plain=preg_replace('/\s+(?:on|from|in|using)\s+(?:both\s+|only\s+)?(?:(?:my|the|main)\s+)?(?:you\s*tube|VP3(?:\s+(?:music|audio))?(?:\s+library)?|(?:music |audio )?library)(?:\s+(?:and|or|\+)\s+(?:you\s*tube|VP3))?(?:\s+only)?\b/iu','',$text)??$text;
    $plain=preg_replace('/\s+(?:you\s*tube|VP3)\s+only\b/iu','',$plain)??$plain;
    $plain=preg_replace('/\b(?:you\s*tube|VP3)\s+(?=(?:music\s+)?(?:playlist|songs?|tracks?|music)\b)/iu','',$plain)??$plain;
    $plain=preg_replace('/\s+/u',' ',trim($plain))??$plain;
    $sourceConflict=($excludeYoutube&&$hasYoutube)||($excludeVp3&&$hasVp3)||($excludeYoutube&&$excludeVp3);
    if(($unsupportedSource||$sourceConflict)&&preg_match('~\A(?:/music\b|(?:play|show|list|find|give|search|suggest|recommend|hear|listen|make|create|build|prepare|queue|add)\b)~iu',$plain)){
        return ['intent'=>'show','source'=>$source,'requested_count'=>1,'query'=>'','exact'=>false,'autoplay'=>false,
            'requires_match'=>false,'error'=>$sourceConflict?'conflicting_source':'unsupported_source'];
    }

    // Follow-ups select the prior persisted result; they never rerun a search.
    if(preg_match('/\Aadd\s+(.+?)\s+to\s+(?:(?:my|the)\s+)?(.+?)\s+playlist\z/iu',$plain,$add)){
        $selection=vp3_music_chat_plan_v1('show '.$add[1]);
        if($selection!==null&&isset($selection['selection'])&&empty($selection['selection']['playlist'])){
            return ['intent'=>'add_to_playlist','source'=>$source,'requested_count'=>1,'query'=>'','exact'=>false,
                'selection'=>$selection['selection'],'playlist_name'=>trim($add[2]," \"'“”"),'autoplay'=>false,'requires_match'=>false];
        }
        return null;
    }
    if(preg_match('/\A(?:create|make|save)\s+(?:that|this)\s+playlist(?:\s+(?:and|then)\s+play\s+it)?\z/iu',$plain)){
        return ['intent'=>'create_playlist','source'=>$source,'requested_count'=>25,'query'=>'','exact'=>false,
            'selection'=>['all'=>true],'autoplay'=>!$noPlay&&preg_match('/\splay\s+it\z/iu',$plain)===1,'requires_match'=>false];
    }
    $queueIntent=null;
    if(preg_match('/\A(?:add|put)\s+(.+?)\s+(?:to|in|into)\s+(?:(?:the|my)\s+)?queue\z/iu',$plain,$queued)){
        $plain='queue '.$queued[1];$queueIntent='queue_append';
    }elseif(preg_match('/\A(?:play|queue)\s+(.+?)\s+next\z/iu',$plain,$queued)){
        $plain='queue '.$queued[1];$queueIntent='play_next';
    }
    if(preg_match('/\A(?:play|show|list|open|queue)(?:\s+me)?\s+(?:(?:the|that|this)\s+)?(first|second|third|fourth|fifth|sixth|seventh|eighth|ninth|tenth|last|[1-9][0-9]?(?:st|nd|rd|th)?)(?:\s+(?:one|song|track|result))?\z/iu',$plain,$m)){
        $word=mb_strtolower($m[1]);
        $index=['first'=>1,'second'=>2,'third'=>3,'fourth'=>4,'fifth'=>5,'sixth'=>6,'seventh'=>7,'eighth'=>8,'ninth'=>9,'tenth'=>10,'last'=>-1][$word]??(int)preg_replace('/\D/','',$word);
        $intent=preg_match('/\Aplay\b/iu',$plain)?'play':(preg_match('/\Aqueue\b/iu',$plain)?($queueIntent??'queue_append'):'show');
        return ['intent'=>$intent,'source'=>$source,'requested_count'=>1,'query'=>'','exact'=>false,'selection'=>['index'=>$index],
            'autoplay'=>in_array($intent,['play','queue_append','play_next'],true)&&!$noPlay,'requires_match'=>false];
    }
    if(preg_match('/\A(?:play|show|open|list|queue)(?:\s+me)?\s+(?:that|this|it)(?:\s+(one|song|track|playlist))?\z/iu',$plain,$m)){
        $playlist=mb_strtolower($m[1]??'')==='playlist';
        $play=preg_match('/\Aplay\b/iu',$plain)===1;
        $queue=preg_match('/\Aqueue\b/iu',$plain)===1;
        return ['intent'=>$queue?($queueIntent??'queue_append'):($playlist?($play?'play_playlist':'show_playlist'):($play?'play':'show')),'source'=>$source,
            'requested_count'=>$playlist?25:1,'query'=>'','exact'=>false,'selection'=>['index'=>$playlist?0:1,'playlist'=>$playlist,'deictic'=>!$playlist],
            'autoplay'=>($play||$queue)&&!$noPlay,'requires_match'=>false];
    }
    if(preg_match('/\A(?:play|show|open|queue)\s+(?:my\s+|the\s+)?(.+?)\s+playlist\z/iu',$plain,$m)
        &&!preg_match('/\A(?:a|an|some|one|[0-9]+)[ -]/iu',$m[1])){
        return ['intent'=>preg_match('/\Aqueue\b/iu',$plain)?($queueIntent??'queue_append'):(preg_match('/\Aplay\b/iu',$plain)?'play_playlist':'show_playlist'),'source'=>$source,'requested_count'=>25,
            'query'=>'','playlist_name'=>trim($m[1],' "\'“”'),'exact'=>false,'autoplay'=>!$noPlay&&preg_match('/\A(?:play|queue)\b/iu',$plain)===1,'requires_match'=>false];
    }

    $command=false;
    if(preg_match('~\A/music(?:\s+(show|find|search|suggest|play|playlist))?(?:\s+(.*))?\z~iu',$plain,$m)){
        $command=true;$verb=mb_strtolower($m[1]??'show');$subject=trim($m[2]??'');
        $plain=($verb==='playlist'?'create a playlist for ':$verb.' ').$subject;
    }
    if(!preg_match('/\A(show|list|find|give|search(?:\s+for)?|suggest|recommend|play|hear|listen\s+to|make|create|build|prepare|queue)(?:\s+me)?\s+(.+)\z/iu',$plain,$m))return null;
    $verb=mb_strtolower($m[1]);$subject=trim($m[2]);
    $playlist=preg_match('/\b(?:playlist|mix|set of songs)\b/iu',$subject)===1;
    if(in_array($verb,['make','create','build','prepare'],true)&&!$playlist)return null;
    $musicMarker=$command||$explicitSource||$playlist||preg_match('/\b(?:songs?|tracks?|music)\b/iu',$subject)===1;
    $autoplay=!$noPlay&&($createAndPlay||in_array($verb,['play','hear','listen to','queue'],true)||preg_match('/\b(?:and|then)\s+play(?:\s+(?:it|them|that|the playlist))?\z/iu',$subject)===1);
    $subject=preg_replace('/\s+(?:and|then)\s+play(?:\s+(?:it|them|that|the playlist))?\z/iu','',$subject)??$subject;
    $count=$playlist?8:(preg_match('/\b(?:songs|tracks)\b/iu',$subject)?4:1);
    $numeric='(?:[0-9]{1,4}|twenty[ -](?:one|two|three|four|five)|one|two|three|four|five|six|seven|eight|nine|ten|eleven|twelve|thirteen|fourteen|fifteen|sixteen|seventeen|eighteen|nineteen|twenty|a couple|a few)';
    $countPattern='/\b('.$numeric.')(?:[ -]+(?:[\p{L}]+[ -]+){0,4})?(?:songs?|tracks?)\b/iu';
    $explicitCount=false;
    if(preg_match($countPattern,$subject,$n)){
        $number=vp3_music_chat_number_v1($n[1]);
        if($number!==null){$count=$number;$explicitCount=true;}
    }elseif(preg_match('/\A(?:just\s+|only\s+)?('.$numeric.')\s+(?:recommendations?|picks?|results?)\b/iu',$subject,$n)){
        $count=vp3_music_chat_number_v1($n[1])??$count;$explicitCount=true;$musicMarker=true;
    }
    $title='';
    if($playlist&&preg_match('/\s+(?:called|named|titled)\s+["“]([^"”]{1,160})["”]\z/u',$subject,$named)){
        $title=trim($named[1]);$subject=substr($subject,0,-strlen($named[0]));
    }
    $quotedTitle=null;$quotedArtist='';
    if(!$playlist&&preg_match('/\A(?:(?:the|a|one)\s+)?(?:(?:song|track)\s+)?["“]([^"”]{1,200})["”](?:\s+by\s+(.+))?\z/u',$subject,$quoted)){
        $quotedTitle=trim($quoted[1]);$quotedArtist=trim($quoted[2]??'');
    }
    $subject=preg_replace('/\b(?:playlist|mix|set of songs)\b/iu',' ',$subject)??$subject;
    if($explicitCount){
        $subject=preg_replace('/\b'.preg_quote($n[1],'/').'(?=[ -])/iu',' ',$subject,1)??$subject;
    }
    $subject=preg_replace('/\b(?:songs?|tracks?|music|recommendations?|picks?|results?)\b/iu',' ',$subject)??$subject;
    $subject=preg_replace('/\A\s*(?:(?:just|only|a|an|some|any|the|for|of|with|about|something)\b[\s-]*)+/iu','',$subject)??$subject;
    $subject=trim(preg_replace('/[\s-]+/u',' ',trim($subject))??'');
    $subject=preg_replace('/\A(?:for|of|with|about)\s+/iu','',$subject)??$subject;
    $subject=trim($subject," \"'“”");
    $intent=$verb==='queue'?($queueIntent??'queue_append'):($playlist?'create_playlist':(in_array($verb,['suggest','recommend'],true)?'suggest':($autoplay?'play':'show')));
    $mood= function_exists('track_detect_moods')?track_detect_moods($subject):[];
    $descriptive=$playlist||$explicitCount||in_array($verb,['suggest','recommend'],true)||$mood!==[]
        ||preg_match('/\b(?:acoustic|calm|chill|relaxing|upbeat|happy|sad|ambient|instrumental|driving|workout|rock|jazz|pop|country|classical|electronic|new|similar|like)\b/iu',$subject)===1;
    $artist='';
    if($musicMarker&&preg_match('/\A(?:by|from)\s+(.+)\z/iu',$subject,$artistMatch)){
        $artist=trim($artistMatch[1]);$subject=$artist;$descriptive=true;
    }elseif(preg_match('/\A(.+?)\s+by\s+(.+)\z/iu',$subject,$artistMatch)){
        $subject=trim($artistMatch[1]);$artist=trim($artistMatch[2]);
    }
    if($quotedTitle!==null){$subject=$quotedTitle;$artist=$quotedArtist;$descriptive=false;}
    return ['intent'=>$intent,'source'=>$source,'requested_count'=>$count,'query'=>$subject,'exact'=>!$descriptive&&$subject!=='',
        'autoplay'=>$autoplay,'requires_match'=>!$musicMarker&&!$autoplay,'playlist_title'=>$title,'artist'=>$artist,
        'error'=>$unsupportedSource||($excludeYoutube&&$excludeVp3)?'unsupported_source':($count<1||$count>VP3_MUSIC_CHAT_MAX_ITEMS_V1?'count_out_of_range':(mb_strlen($subject)>200?'query_too_long':null))];
}

/** Deterministic source ranking is applied before the global result-count limit. */
function vp3_music_chat_rank_v1(array $items,array $plan): array
{
    $query=vp3_music_chat_words_v1((string)$plan['query']);
    $terms=$query===''?[]:explode(' ',$query);
    $ranked=[];$seen=[];
    foreach($items as $order=>$item){
        $key=($item['source']??'').':'.($item['source_id']??'');
        if(isset($seen[$key]))continue;
        $seen[$key]=true;
        $title=vp3_music_chat_words_v1((string)($item['title']??''));
        $artist=vp3_music_chat_words_v1((string)($item['artist']??''));
        $text=vp3_music_chat_words_v1(implode(' ',[(string)($item['title']??''),(string)($item['artist']??''),
            (string)($item['album']??''),(string)($item['search_text']??'')]));
        if(($plan['artist']??'')!==''){
            $identity=$item['source']==='vp3'?$artist:$text;
            if(!str_contains(' '.$identity.' ',' '.vp3_music_chat_words_v1($plan['artist']).' '))continue;
        }
        $matched=0;
        foreach($terms as $term)if(str_contains(' '.$text.' ',' '.$term.' '))$matched++;
        if(!empty($plan['exact'])){
            $exact=$title===$query;
            $titleMatch=$query!==''&&str_contains(' '.$title.' ',' '.$query.' ');
            $titleTerms=0;
            foreach($terms as $term)if(str_contains(' '.$title.' ',' '.$term.' '))$titleTerms++;
            $namedMatch=$terms!==[]&&$titleTerms===count($terms);
            if(!$exact&&!$titleMatch&&!$namedMatch)continue;
            $score=$exact?1000:($titleMatch?800:600);
        }else{
            $score=$matched*20+($title===$query&&$query!==''?100:0);
            $moods=function_exists('track_detect_moods')?track_detect_moods($query):[];
            foreach($moods as $mood){
                foreach([$mood,...(track_mood_aliases()[$mood]??[])] as $alias){
                    if(str_contains(' '.$text.' ',' '.vp3_music_chat_words_v1($alias).' ')){$score+=15;break;}
                }
            }
            // YouTube's bounded current provider search is a relevance signal for descriptive queries.
            if($query!==''&&$score===0&&$item['source']!=='youtube')continue;
        }
        $ranked[]=['item'=>$item,'score'=>$score,'order'=>$order];
    }
    usort($ranked,static function(array $a,array $b):int{
        $score=$b['score']<=>$a['score'];
        if($score!==0)return $score;
        $source=(($a['item']['source']==='vp3')?0:1)<=>(($b['item']['source']==='vp3')?0:1);
        return $source!==0?$source:$a['order']<=>$b['order'];
    });
    return array_column($ranked,'item');
}

function vp3_music_chat_dependencies_v1(): void
{
    require_once __DIR__.'/knowledge-retrieval-v162.php';
    require_once __DIR__.'/agent-media-playback-v1.php';
    require_once __DIR__.'/agent-connection-youtube-playback-v1.php';
    require_once __DIR__.'/agent-connection-youtube-chat-v1.php';
    require_once __DIR__.'/music-chat-library-v1.php';
}

/** Current native transport access and canonical recording identity, never a title-only playback URL. */
function vp3_music_chat_native_items_v1(PDO $pdo,array $user,array $principal): array
{
    $map=player_visible_track_map($user);
    $ordered=vp3_media_playback_history_allowed_v1($pdo,$user,$principal)?player_for_you($user,$map,count($map)):array_values($map);
    $items=[];
    foreach($ordered as $row){
        $track=vp3_media_playable_track_v1($pdo,$user,(int)$row['id']);
        if($track===null)continue;
        $details=vp3_media_track_details_v1($pdo,$track);
        if($details['title']==='')continue;
        $display=track_media_payload($track);
        $items[]=['source'=>'vp3','source_id'=>(string)$track['id'],'title'=>$details['title'],'artist'=>$details['artist'],
            'album'=>$details['album'],'duration'=>(string)($track['duration']??''),'cover'=>(string)($display['cover']??''),
            'native_revision'=>vp3_media_track_revision_v1((int)$user['id'],(int)$principal['agent_id'],$track),
            'search_text'=>implode(' ',array_map(static fn($v):string=>is_scalar($v)?(string)$v:'',
                array_intersect_key($track,array_flip(['genre','mood','energy','keywords']))))];
    }
    return $items;
}

/** One real provider search and one exact current-status request, subject to existing consent/quota. */
function vp3_music_chat_youtube_items_v1(PDO $pdo,array $user,array $principal,int $cid,array $plan,?callable $authority=null,?callable $http=null): array
{
    $coverage=['source'=>'youtube','status'=>'unavailable','reason'=>'provider_unavailable'];
    try{
        $live=agent_connection_youtube_chat_authority_v1($pdo,$user,$principal,$cid,$authority);
        if(!agent_connection_youtube_ready_v1())return ['items'=>[],'coverage'=>$coverage+['message'=>'YouTube search is not configured or is paused.']];
        if(!agent_connection_youtube_results_ready_v1($pdo)||!agent_connection_youtube_quota_ready_v1($pdo)
            ||!agent_connection_youtube_consent_ready_v1($pdo)){
            $coverage['reason']='upgrade_required';
            return ['items'=>[],'coverage'=>$coverage+['message'=>'YouTube needs the current database upgrade.']];
        }
        if(!agent_connection_youtube_consent_v1($pdo,(int)$live['id'])){
            $coverage['reason']='source_disabled';
            return ['items'=>[],'coverage'=>$coverage+['message'=>'Enable YouTube in Connections to include it.']];
        }
        $query=trim((string)$plan['query'].' '.(string)($plan['artist']??''));
        if($query===''&&vp3_youtube_picks_ready_v1($pdo))$query=vp3_youtube_picks_settings_v1($pdo,(int)$live['id'])['taste'];
        if($query==='')$query='music discovery';
        $query=agent_connection_youtube_query_v1($query);
        $day=agent_connection_youtube_reserve_v1($pdo,(int)$live['id']);
        $videos=agent_connection_youtube_music_search_v1($query,max(8,(int)$plan['requested_count']),$http);
        agent_connection_youtube_record_success_v1($pdo,(int)$live['id'],$day);
        agent_connection_youtube_chat_authority_v1($pdo,$live,$principal,$cid,$authority);
        if(!agent_connection_youtube_ready_v1()||!agent_connection_youtube_consent_v1($pdo,(int)$live['id'])){
            $coverage['reason']='source_changed';
            return ['items'=>[],'coverage'=>$coverage+['message'=>'YouTube availability changed during the search.']];
        }
        $items=[];$expires=time()+7*86400;
        foreach($videos as $video){
            $items[]=['source'=>'youtube','source_id'=>$video['video_id'],'title'=>$video['title'],
                'artist'=>$video['channel_title'],'album'=>'','duration'=>'','cover'=>$video['thumbnail_url'],
                'youtube_video'=>$video,'expires_at'=>$expires];
        }
        return ['items'=>$items,'coverage'=>['source'=>'youtube','status'=>'available','reason'=>'',
            'returned_count'=>count($items),'search_limit'=>max(8,(int)$plan['requested_count'])]];
    }catch(Throwable $error){
        if((int)$error->getCode()===403&&!$error instanceof AgentConnectionYoutubeExceptionV1)throw $error;
        $coverage['reason']=(int)$error->getCode()===429?'request_limit':'provider_unavailable';
        return ['items'=>[],'coverage'=>$coverage+['message'=>(int)$error->getCode()===429
            ?'YouTube has reached its current request limit.':'YouTube could not verify playable results right now.']];
    }
}

function vp3_music_chat_answer_v1(array $payload): string
{
    $items=$payload['items']??[];$count=count($items);$intent=$payload['intent']??'show';
    $requested=(int)($payload['requested_count']??1);
    $status=$payload['status']??'ready';
    if($status==='ambiguous')return 'More than one recording matches that title. Include the artist or version so I can select the exact song.';
    if(!$items){
        return $status==='unavailable'?'The selected music source is unavailable right now. Check its connection and try again.'
            :'I couldn’t verify a playable song matching that request. Try the title and artist, or a different music description.';
    }
    if(!array_filter($items,static fn(array $item):bool=>($item['available']??true)===true)){
        return 'The saved music selection is currently unavailable. Check its source or ask me to find another song.';
    }
    if($intent==='create_playlist'){
        $playlist=$payload['playlist']??null;
        if(!is_array($playlist)||empty($playlist['id']))return 'Preparing your saved playlist.';
        $title=(string)($playlist['title']??'Your playlist');
        $answer='Created “'.$title.'” with '.$count.' '.($count===1?'song':'songs').'.';
    }elseif($intent==='play_playlist')$answer='Your saved playlist is ready to play.';
    elseif($intent==='show_playlist')$answer='Here is your saved playlist.';
    elseif($intent==='add_to_playlist')return !empty($payload['bound'])&&!empty($payload['playlist']['id'])
        ?'Your selected song is in “'.(string)$payload['playlist']['title'].'”.':'Preparing the playlist update.';
    elseif($intent==='queue_append')$answer='Selected '.$count.' '.($count===1?'song':'songs').' for your queue.';
    elseif($intent==='play_next')$answer='Selected '.$count.' '.($count===1?'song':'songs').' to play next.';
    elseif($count===1)$answer=($intent==='play'?'Ready to play ':''). '“'.(string)$items[0]['title'].'”.';
    else $answer='Here are '.$count.' matching songs.';
    if($count<$requested)$answer.=' I verified '.$count.' of the '.$requested.' requested songs in the current search.';
    foreach($payload['coverage']??[] as $coverage){
        if(is_array($coverage)&&($coverage['status']??'')==='unavailable'){
            $answer.=' '.(($coverage['source']??'')==='youtube'?'YouTube':'VP3').' was unavailable for this request.';
        }
    }
    return $answer;
}

function vp3_music_chat_reply_v1(array $payload): array
{
    $receipt=in_array($payload['intent']??'',['create_playlist','add_to_playlist'],true)&&!empty($payload['bound'])&&!empty($payload['playlist']['id'])
        ?vp3_tool_receipt_v1('music.playlists.manage',$payload['intent']==='add_to_playlist'?'add':'create',
            !empty($payload['replayed'])||($payload['intent']==='add_to_playlist'&&empty($payload['playlist_changed']))?'read_complete':'completed','',0,count($payload['items']??[]))
        :vp3_tool_receipt_v1('music.library.read','search',empty($payload['items'])?'no_matches':'read_complete','',0,count($payload['items']??[]));
    return ['handled'=>true,'answer'=>vp3_music_chat_answer_v1($payload),'actions'=>[],'sources'=>[],
        'music_chat_handled_v1'=>true,'music_chat_v1'=>$payload,
        'tool_receipt'=>$receipt];
}

/** A clarification retains selection continuity without replaying cards or audio. */
function vp3_music_chat_clarify_v1(array $prior,string $answer): array
{
    return ['handled'=>true,'answer'=>$answer,'actions'=>[],'sources'=>[],'music_chat_handled_v1'=>true,
        'music_chat_followup_v1'=>vp3_music_chat_result_reference_v1($prior),
        'tool_receipt'=>vp3_tool_receipt_v1('music.library.read','search','clarification_required')];
}

/** Recognize the current MC1 card without requiring its additive schema to be complete. */
function vp3_music_chat_selection_context_v1(PDO $pdo,int $owner,int $agent,int $cid): bool
{
    [$scope,$params]=vp3_agent_chat_scope_sql_v380($agent>0?['id'=>$agent]:null,'c');
    $q=$pdo->prepare("SELECT m.id,m.context_json FROM chat_messages m JOIN chat_conversations c ON c.id=m.conversation_id
        WHERE c.id=? AND c.user_id=? AND {$scope} AND m.role='assistant' ORDER BY m.id DESC LIMIT 1");
    $q->execute([$cid,$owner,...$params]);$message=$q->fetch(PDO::FETCH_ASSOC);
    if(!$message)return false;
    $context=json_decode((string)($message['context_json']??''),true);
    if(is_array($context['music_chat_v1']??null)||is_array($context['music_chat_followup_v1']??null))return true;
    // Earlier stored results can be bound to this assistant turn without a context reference.
    try{
        $q=$pdo->prepare('SELECT 1 FROM music_chat_results_v1 WHERE owner_user_id=? AND agent_id=? AND conversation_id=? AND assistant_message_id=?');
        $q->execute([$owner,$agent,$cid,(int)$message['id']]);
        return (bool)$q->fetchColumn();
    }catch(PDOException){return false;}
}

/** Current private-owner turn; the saved result is the only source for later selection and execution. */
function vp3_music_chat_execute_v1(PDO $pdo,array $user,array $principal,int $cid,int $userMessageId,string $query,
    array $input=[],?callable $authority=null,?callable $http=null): ?array
{
    $plan=vp3_music_chat_plan_v1($query);
    if($plan===null)return null;
    vp3_music_chat_dependencies_v1();
    if($authority)$authority();
    [$live,$current]=vp3_media_playback_user_v1($pdo,$user,(int)($principal['agent_id']??0),$cid);
    if(!knowledge_retrieval_v162_owner_session($live,$current))throw new RuntimeException('Use your private Agent Chat for music.',403);
    if(!empty($plan['error'])){
        return ['handled'=>true,'answer'=>match($plan['error']){'count_out_of_range'=>'Choose between 1 and 25 songs for one request.',
            'unsupported_source'=>'Music Chat supports VP3 and YouTube. Choose one of those sources, or both.',
            'conflicting_source'=>'That request includes conflicting music sources. Choose VP3, YouTube, or both without excluding the chosen source.',
            default=>'Use a music title or description up to 200 characters.'},'actions'=>[],'sources'=>[],'music_chat_handled_v1'=>true];
    }
    if(!vp3_music_chat_ready_v1($pdo)){
        // A legacy media selection does not require the new music tables. A
        // current stored MC1 card still needs its upgrade, including partial schemas.
        if(isset($plan['selection'])&&!vp3_music_chat_selection_context_v1($pdo,(int)$live['id'],(int)$current['agent_id'],$cid))return null;
        // A bare Find/Show request belongs here only if the current permitted
        // catalog identifies music. An outstanding upgrade must not capture
        // unrelated Chat commands before their normal tools can handle them.
        if(!empty($plan['requires_match'])&&vp3_music_chat_rank_v1(vp3_music_chat_native_items_v1($pdo,$live,$current),$plan)===[])return null;
        return ['handled'=>true,'answer'=>'Music Chat needs the current VP3 database upgrade.',
            'actions'=>[],'sources'=>[],'music_chat_handled_v1'=>true];
    }
    $key=(string)($input['music_request_id']??$input['idempotency_key']??('message:'.$userMessageId));
    if(!preg_match('/\A[A-Za-z0-9:_-]{8,128}\z/D',$key))throw new RuntimeException('Music request could not be confirmed. Reload Chat and try again.',422);
    $hash=hash('sha256',json_encode(['query'=>trim($query),'plan'=>$plan],JSON_THROW_ON_ERROR));
    $prior=vp3_music_chat_result_request_v1($pdo,$live,(int)$current['agent_id'],$cid,$key,$hash);
    if($prior!==null)return vp3_music_chat_reply_v1($prior);
    $items=[];$coverage=[];$status='ready';$selectedPlaylist=null;$targetPlaylist=null;
    if(isset($plan['selection'])){
        $prior=vp3_music_chat_result_latest_v1($pdo,$live,(int)$current['agent_id'],$cid);
        if($prior===null)return null;
        if(!empty($plan['selection']['all'])){
            $items=vp3_music_chat_result_items_v1($pdo,$live,(int)$current['agent_id'],$cid,$prior['result_id']);
            if($plan['source']!=='both'&&array_filter($items,static fn(array $item):bool=>$item['source']!==$plan['source']))return ['handled'=>true,
                'answer'=>'Those displayed songs include another source. Choose that full selection or request songs from the source you want.',
                'actions'=>[],'sources'=>[],'music_chat_handled_v1'=>true];
            $plan['requested_count']=max(1,count($items));$plan['query']=$prior['query']??'';
        }elseif(!empty($plan['selection']['playlist'])){
            if(!is_array($prior['playlist']??null))return vp3_music_chat_clarify_v1($prior,
                'Those songs are search results. Create a playlist first, then ask me to play that playlist.');
            $selectedPlaylist=$prior['playlist'];
            $items=vp3_music_chat_playlist_items_v1($pdo,$live,(int)$current['agent_id'],$cid,$selectedPlaylist['id'],$http);
            if($plan['source']!=='both'&&array_filter($items,static fn(array $item):bool=>$item['source']!==$plan['source'])){
                return ['handled'=>true,'answer'=>'That saved playlist contains another music source. Open it to choose a specific song, or request the whole playlist.',
                    'actions'=>[],'sources'=>[],'music_chat_handled_v1'=>true];
            }
            $plan['requested_count']=max(1,count($items));$plan['query']=$selectedPlaylist['title'];
        }else{
            if(!empty($plan['selection']['deictic'])&&count($prior['items'])!==1)return vp3_music_chat_clarify_v1($prior,
                'Which song do you mean? Use its number in the displayed results.');
            $index=(int)$plan['selection']['index'];
            $index=$index===-1?count($prior['items'])-1:$index-1;
            if(!isset($prior['items'][$index]))return vp3_music_chat_clarify_v1($prior,'That position is not in the current song results.');
            $selected=vp3_music_chat_result_item_reference_v1($pdo,$live,(int)$current['agent_id'],$cid,$prior['result_id'],$index);
            if($plan['source']!=='both'&&$selected['source']!==$plan['source'])return vp3_music_chat_clarify_v1($prior,
                'That numbered result is from '.($selected['source']==='vp3'?'VP3':'YouTube').'. Choose its displayed source, or name a different result.');
            $items=[$selected];
        }
        $coverage=$prior['coverage']??[];
        if($plan['intent']==='add_to_playlist'){
            $saved=vp3_music_chat_playlist_find_v1($pdo,$live,(int)$current['agent_id'],$cid,$plan['playlist_name']);
            if($saved===null)return vp3_music_chat_clarify_v1($prior,
                'No private playlist has that exact name. Open Saved playlists to check its title, or ask me to create it.');
            $targetPlaylist=$saved['playlist'];
        }
    }elseif(isset($plan['playlist_name'])){
        $saved=vp3_music_chat_playlist_find_v1($pdo,$live,(int)$current['agent_id'],$cid,$plan['playlist_name']);
        if($saved===null)return ['handled'=>true,'answer'=>'No private playlist has that exact name. Open Saved playlists to check its title, or ask me to create it.',
            'actions'=>[],'sources'=>[],'music_chat_handled_v1'=>true];
        $selectedPlaylist=$saved['playlist'];
        $items=vp3_music_chat_playlist_items_v1($pdo,$live,(int)$current['agent_id'],$cid,$selectedPlaylist['id'],$http);
        if($plan['source']!=='both'&&array_filter($items,static fn(array $item):bool=>$item['source']!==$plan['source']))return ['handled'=>true,
            'answer'=>'That saved playlist contains another music source. Open it to choose a specific song, or request the whole playlist.',
            'actions'=>[],'sources'=>[],'music_chat_handled_v1'=>true];
        $plan['requested_count']=max(1,count($items));$plan['query']=$selectedPlaylist['title'];
    }else{
        if($plan['source']!=='youtube'){
            $items=vp3_music_chat_native_items_v1($pdo,$live,$current);
            $coverage[]=['source'=>'vp3','status'=>'available','reason'=>''];
        }
        $native=vp3_music_chat_rank_v1($items,$plan);
        if($plan['requires_match']&&$native===[])return null;
        if($plan['source']!=='vp3'&&!$plan['requires_match']){
            $youtube=vp3_music_chat_youtube_items_v1($pdo,$live,$current,$cid,$plan,$authority,$http);
            $items=[...$items,...$youtube['items']];$coverage[]=$youtube['coverage'];
        }
        $ranked=vp3_music_chat_rank_v1($items,$plan);
        $ambiguous=false;
        if(!empty($plan['exact'])&&count($ranked)>1){
            $topTitle=vp3_music_chat_words_v1($ranked[0]['title']);
            $topSource=$ranked[0]['source'];
            $topArtist=vp3_music_chat_words_v1((string)($ranked[0]['artist']??''));
            foreach(array_slice($ranked,1) as $candidate){
                $candidateTitle=vp3_music_chat_words_v1($candidate['title']);
                $candidateArtist=vp3_music_chat_words_v1((string)($candidate['artist']??''));
                // The same named recording may be available through both sources.
                // Different artist/version labels must not be resolved by source order.
                if($candidate['source']!==$topSource&&$candidateTitle===$topTitle&&$topArtist!==''&&$candidateArtist===$topArtist)continue;
                $ambiguous=true;break;
            }
        }
        $items=$ambiguous?[]:array_slice($ranked,0,(int)$plan['requested_count']);
        if($ambiguous)$status='ambiguous';
    }
    if($authority)$authority();
    vp3_media_playback_user_v1($pdo,$live,(int)$current['agent_id'],$cid);
    if(!$items&&$status==='ready'){
        $unavailable=(bool)array_filter($coverage,static fn($c):bool=>is_array($c)&&($c['status']??'')==='unavailable');
        $status=$unavailable&&$plan['source']!=='both'?'unavailable':'no_matches';
    }
    foreach($items as &$item)unset($item['search_text']);
    unset($item);
    $title=$plan['playlist_title']??'';
    if($title===''&&$plan['intent']==='create_playlist')$title=trim((string)$plan['query'])!==''?mb_substr(ucfirst($plan['query']).' playlist',0,160):'My music playlist';
    $meta=['intent'=>$plan['intent'],'requested_count'=>(int)$plan['requested_count'],'source'=>$plan['source'],'query'=>$plan['query'],
        'coverage'=>$coverage,'status'=>$status,'autoplay'=>(bool)$plan['autoplay'],'create_playlist'=>$plan['intent']==='create_playlist','title'=>$title];
    if($selectedPlaylist!==null)$meta+=['existing_playlist_id'=>$selectedPlaylist['id'],'expected_playlist_revision'=>$selectedPlaylist['revision']];
    if($targetPlaylist!==null)$meta+=['target_playlist_id'=>$targetPlaylist['id'],'expected_target_revision'=>$targetPlaylist['revision']];
    $payload=vp3_music_chat_result_store_v1($pdo,$live,(int)$current['agent_id'],$cid,$key,$hash,$items,$meta);
    return vp3_music_chat_reply_v1($payload);
}

/** Called only after the actual owner assistant message has been saved. */
function vp3_music_chat_bind_reply_v1(PDO $pdo,array $user,array $principal,int $cid,int $assistantMessageId,array $tool): array
{
    if(!is_array($tool['music_chat_v1']??null))return $tool;
    $payload=vp3_music_chat_result_bind_v1($pdo,$user,(int)($principal['agent_id']??0),$cid,
        (string)$tool['music_chat_v1']['result_id'],$assistantMessageId);
    return vp3_music_chat_reply_v1($payload);
}
