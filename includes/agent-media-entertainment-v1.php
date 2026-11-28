<?php
declare(strict_types=1);

/** Native playback suggestions are independent of external research consent. */
function vp3_media_offers_enabled_v1(PDO $pdo,int $owner): bool
{
    $read=$pdo->prepare('SELECT setting_value FROM settings WHERE setting_key=?');
    $read->execute(['native_media_offers_owner_'.$owner]);
    return (string)$read->fetchColumn()==='1';
}

function vp3_media_native_taste_v1(PDO $pdo,int $owner): string
{
    $read=$pdo->prepare('SELECT setting_value FROM settings WHERE setting_key=?');
    $read->execute(['native_media_taste_owner_'.$owner]);
    $value=$read->fetchColumn();
    return is_string($value)&&preg_match('/\A[^\p{C}]{0,120}\z/u',$value)===1?trim($value):'';
}

function vp3_media_offers_save_v1(PDO $pdo,int $owner,bool $enabled,?string $taste=null): void
{
    if($owner<1)throw new InvalidArgumentException('Media settings require an owner.');
    if($taste!==null&&preg_match('/\A[^\p{C}]{0,120}\z/u',$taste)!==1){
        throw new InvalidArgumentException('Enter a music preference up to 120 characters.');
    }
    require_once __DIR__.'/agent-commerce-recovery-pa2.php';
    agent_commerce_atomic_pa2($pdo,static function()use($pdo,$owner,$enabled,$taste):void{
        $lock=$pdo->prepare('SELECT id FROM users WHERE id=? AND is_active=1'.agent_commerce_lock_pa2($pdo));
        $lock->execute([$owner]);
        if(!$lock->fetchColumn())throw new RuntimeException('Media settings access changed.',403);
        $key='native_media_offers_owner_'.$owner;
        $pdo->prepare('DELETE FROM settings WHERE setting_key=?')->execute([$key]);
        if($enabled)$pdo->prepare('INSERT INTO settings(setting_key,setting_value) VALUES(?,?)')->execute([$key,'1']);
        if($taste!==null){
            $key='native_media_taste_owner_'.$owner;
            $pdo->prepare('DELETE FROM settings WHERE setting_key=?')->execute([$key]);
            if(trim($taste)!=='')$pdo->prepare('INSERT INTO settings(setting_key,setting_value) VALUES(?,?)')->execute([$key,trim($taste)]);
        }
    });
}

function vp3_media_quiet_chat_v1(PDO $pdo,array $user,int $cid,?string &$hold=null): bool
{
    $hold='quiet_chat_required';
    if(!vp3_initiative_owner_present_v1($pdo,$user,$cid,true))return false;
    $read=$pdo->prepare('SELECT details_json FROM agent_activity_state WHERE user_id=?');
    $read->execute([(int)$user['id']]);
    $activity=json_decode((string)$read->fetchColumn(),true);
    $hold=($activity['media_playing']??false)===true?'playing':null;
    return $hold===null;
}

function vp3_media_offer_gate_v1(PDO $pdo,array $user,int $cid): string
{
    return vp3_media_cooldown_ready_v1($pdo,(int)$user['id'])?'ready':'cooldown';
}

function vp3_media_cooldown_ready_v1(PDO $pdo,int $owner): bool
{
    $read=$pdo->prepare('SELECT setting_value FROM settings WHERE setting_key=?');
    $read->execute(['media_next_offer_owner_'.$owner]);
    return (int)$read->fetchColumn()<=time();
}

/** Called under the same owner lock as the canonical Chat post. */
function vp3_media_record_offer_v1(PDO $pdo,int $owner): void
{
    $key='media_next_offer_owner_'.$owner;
    $pdo->prepare('DELETE FROM settings WHERE setting_key=?')->execute([$key]);
    $pdo->prepare('INSERT INTO settings(setting_key,setting_value) VALUES(?,?)')
        ->execute([$key,(string)(time()+4*3600)]);
}

function vp3_media_chat_ready_v1(PDO $pdo,array $user,array $goal,?string &$hold=null): bool
{
    if(($goal['coordination_route']['specialist']??null)!=='media')return true;
    if(in_array($goal['coordination_route']['trigger']??null,['production_project','production_attention'],true))return true;
    $hold=vp3_media_offer_gate_v1($pdo,$user,$goal['binding']['conversation_id']);
    if($hold!=='ready')return false;
    $trigger=$goal['coordination_route']['trigger']??null;
    if($trigger==='catalog_pick'&&!vp3_media_catalog_has_new_chat_activity_v1($pdo,$user,$goal['binding'])){
        $hold='new_activity_required';
        return false;
    }
    if($trigger!=='recent_search')return true;
    require_once __DIR__.'/agent-connection-youtube-picks-v1.php';
    $ready=vp3_youtube_picks_ready_v1($pdo)&&(int)vp3_youtube_picks_settings_v1($pdo,(int)$user['id'])['next_offer']<=time();
    if(!$ready)$hold='cooldown';
    return $ready;
}

function vp3_media_chat_record_v1(PDO $pdo,array $user,array $goal): void
{
    if(($goal['coordination_route']['specialist']??null)!=='media')return;
    if(in_array($goal['coordination_route']['trigger']??null,['production_project','production_attention'],true))return;
    if(($goal['coordination_route']['trigger']??null)==='recent_search'
        &&!vp3_youtube_reserve_offer_v1($pdo,(int)$user['id'],time())){
        throw new RuntimeException('Media offer timing changed.',409);
    }
    vp3_media_record_offer_v1($pdo,(int)$user['id']);
}

function vp3_media_offer_runs_v1(PDO $pdo,array $user,array $binding,?string $primary): array
{
    $agent=vp3_initiative_binding_agent_v1($binding);
    $agentWhere=$agent>0?'r.agent_id=?':'r.agent_id IS NULL';
    $json="JSON_EXTRACT(CASE WHEN JSON_VALID(r.goal) THEN r.goal ELSE '{}' END,'$.primary')";
    if($pdo->getAttribute(PDO::ATTR_DRIVER_NAME)!=='sqlite')$json="JSON_UNQUOTE($json)";
    $where=$primary===null?"$json LIKE 'media:track:%'":"$json=?";
    $query=$pdo->prepare("SELECT r.id,r.status,r.goal,r.source_key FROM agent_workflow_runs r
        WHERE r.owner_user_id=? AND $agentWhere AND r.source_kind='agent_initiative' AND r.source_key LIKE 'coordination:%'
        AND r.workflow_type='initiative' AND r.capability_key='initiative.prepare' AND r.execution_target='cloud'
        AND r.status='completed' AND $where ORDER BY r.id DESC LIMIT 12");
    $query->execute([(int)$user['id'],...($agent>0?[$agent]:[]),...($primary===null?[]:[$primary])]);
    return $query->fetchAll(PDO::FETCH_ASSOC);
}

function vp3_media_offer_previously_delivered_v1(PDO $pdo,array $user,array $goal): bool
{
    $primary=$goal['primary'];
    $analysis=str_starts_with($primary,'media:youtube-play:')||str_starts_with($primary,'media:listen:');
    foreach(vp3_media_offer_runs_v1($pdo,$user,$goal['binding'],$primary) as $row){
        $previous=json_decode((string)$row['goal'],true);
        if(($previous['coordination_route']['specialist']??null)!=='media'
            ||(!$analysis&&($previous['sources'][$primary]??null)!==$goal['sources'][$primary]))continue;
        $receipt=vp3_coordination_verified_run_v1($pdo,$user,$row);
        if(($receipt['initiative_delivery']??null)!=='delivered')continue;
        if(!$analysis)return true;
        $previousBinding=$previous['binding'];
        if(vp3_coordination_chat_projection_v1($pdo,$user,$previousBinding,$row,$receipt)!==null)return true;
    }
    return false;
}

function vp3_media_play_findings_sources_v1(PDO $pdo,array $scope,?array $refs,DateTimeImmutable $now,?string &$coverage=null): array
{
    require_once __DIR__.'/agent-media-listening-analysis-v1.php';
    require_once __DIR__.'/agent-media-youtube-play-analysis-v1.php';
    $known=static fn(string $prefix):bool=>$refs===null||(bool)array_filter($refs,
        static fn(string $ref):bool=>str_starts_with($ref,$prefix));
    $items=[];
    $readers=['media:youtube-play:'=>'vp3_media_youtube_play_sources_v1','media:listen:'=>'vp3_media_native_listening_findings_v1'];
    foreach($readers as $prefix=>$read){
        if(!$known($prefix))continue;
        try{$items+=$read($pdo,$scope,$refs,$now);}
        catch(RuntimeException $error){
            if($refs!==null)throw $error;
            if($coverage==='ready')$coverage='limited';
        }
    }
    return $items;
}

/** Move to a different catalog choice when a real listen or conversation gives it new context. */
function vp3_media_catalog_has_new_chat_activity_v1(PDO $pdo,array $user,array $binding): bool
{
    foreach(vp3_media_offer_runs_v1($pdo,$user,$binding,null) as $row){
        $goal=json_decode((string)$row['goal'],true);
        if(($goal['coordination_route']['specialist']??null)!=='media'||($goal['coordination_route']['trigger']??null)!=='catalog_pick')continue;
        $receipt=vp3_coordination_verified_run_v1($pdo,$user,$row);
        if(($receipt['initiative_delivery']??null)!=='delivered')continue;
        // The offer's own acceptance/decline is private control input. A new
        // ordinary owner turn supplies context for a different suggestion.
        $private="JSON_EXTRACT(CASE WHEN JSON_VALID(context_json) THEN context_json ELSE '{}' END,'$.skip_brain_archive')";
        if($pdo->getAttribute(PDO::ATTR_DRIVER_NAME)!=='sqlite')$private="JSON_UNQUOTE($private)";
        $read=$pdo->prepare("SELECT 1 FROM chat_messages WHERE conversation_id=? AND user_id=? AND role='user'
            AND id>? AND COALESCE(CAST($private AS CHAR),'false') NOT IN ('true','1') LIMIT 1");
        $read->execute([$binding['conversation_id'],(int)$user['id'],(int)$receipt['initiative_message_id']]);
        if($read->fetchColumn()!==false)return true;
        if(!table_exists('track_play_sessions'))return false;
        require_once __DIR__.'/agent-media-playback-v1.php';
        [$live,$principal]=vp3_media_playback_user_v1($pdo,$user,vp3_initiative_binding_agent_v1($binding));
        if(!vp3_media_playback_history_allowed_v1($pdo,$live,$principal))return false;
        $posted=vp3_initiative_db_wall_epoch_sql_v1($pdo,'m.created_at');
        $read=$pdo->prepare("SELECT $posted FROM chat_messages m JOIN chat_conversations c ON c.id=m.conversation_id
            WHERE m.id=? AND c.user_id=? AND m.role='assistant' AND m.user_id IS NULL");
        $read->execute([(int)$receipt['initiative_message_id'],(int)$live['id']]);
        $postedAt=(int)$read->fetchColumn();
        if($postedAt<1)return false;
        $read=$pdo->prepare("SELECT 1 FROM track_play_sessions WHERE user_id=? AND started_at>?
            AND listened_seconds>=10 LIMIT 1");
        $read->execute([(int)$live['id'],gmdate('Y-m-d H:i:s',$postedAt)]);
        return $read->fetchColumn()!==false;
    }
    return true;
}

function vp3_media_native_item_v1(array $track,string $kind,bool $offers): array
{
    $id=$track['track_id'];
    $ref='media:'.$kind.':'.$id;
    $title=vp3_agent_activity_text_v1($track['title'],145);
    $artist=vp3_agent_activity_text_v1($track['artist']??'',110);
    $display='“'.$title.'”'.($artist!==''?' by '.$artist:'');
    $resume=$kind==='resume';
    $position=(int)($track['position_seconds']??0);
    $positionLabel=intdiv($position,60).':'.str_pad((string)($position%60),2,'0',STR_PAD_LEFT);
    $context=vp3_agent_activity_text_v1($track['genre']??'',75)?:vp3_agent_activity_text_v1($track['album']??'',75);
    $reason=$resume?'You left '.$display.' at '.$positionLabel.' in the VP3 Media Player.'
        :'I found '.$display.' in the VP3 Music Library.'.($context!==''?' It’s listed under '.$context.'.':'');
    return ['ref'=>$ref,'revision'=>$track['revision'],'specialist'=>'media','kind'=>$kind,
        'title'=>$title,'summary'=>$reason,'next_step'=>$resume?'Continue from '.$positionLabel.'.':'Play the track in the VP3 Media Player.',
        'question'=>$resume?'Want to pick up where you left off?':'Want to hear this track?',
        'url'=>$track['url']??url('/track.php?id='.$id),'status'=>'ready','priority'=>'low',
        'trigger'=>$offers?($resume?'resume':'catalog_pick'):null,
        'track_id'=>$id,'artist_url'=>$track['artist_url']??null];
}

/** Read the same exact media sources for the specialist report and shared coordinator. */
function vp3_media_sources_v1(PDO $pdo,array $scope,array $binding,?array $refs,DateTimeImmutable $now,bool $coordination=false): array
{
    require_once __DIR__.'/agent-media-playback-v1.php';
    require_once __DIR__.'/agent-media-entertainment-youtube-v1.php';
    $user=$scope['user'];$owner=(int)$user['id'];$agent=$scope['agent_id'];
    $wants=static fn(string $kind):bool=>$refs===null||(bool)array_filter($refs,
        static fn(string $ref):bool=>str_starts_with($ref,'media:'.$kind.':'));
    $items=[];$resume=null;$coverage='ready';
    $youtube=['items'=>[],'coverage'=>'unconfigured'];
    if($wants('youtube')||(!$coordination&&$wants('research'))){
        $youtube=vp3_media_youtube_findings_v1($pdo,$scope,$binding,$refs,$now,$coordination);
    }
    try{
        $items=vp3_media_play_findings_sources_v1($pdo,$scope,$refs,$now,$coverage);
        $offers=vp3_media_offers_enabled_v1($pdo,$owner);
        if($wants('resume')){
            $resume=vp3_media_resume_candidate_v1($pdo,$user,$agent,$now->getTimestamp());
            if($resume!==null){
                $item=vp3_media_native_item_v1($resume,'resume',$offers);
                $items[$item['ref']]=$item;
            }
        }
        if($wants('track')&&(table_exists('tracks')||table_exists('artist_catalog_tracks_v181'))){
            $tracks=[];
            if($refs===null){
                $taste=vp3_media_native_taste_v1($pdo,$owner);
                [$live,$principal]=vp3_media_playback_user_v1($pdo,$user,$agent);
                $limit=$coordination&&$offers?3:1;
                foreach(vp3_media_native_choices_v1($pdo,$live,$principal,$taste,$limit,(int)($resume['track_id']??0)) as $track){
                    $tracks[]=vp3_media_track_details_v1($pdo,$track)+['revision'=>vp3_media_track_revision_v1($owner,$agent,$track)];
                }
            }else{
                foreach($refs as $ref){
                    if(!str_starts_with($ref,'media:track:'))continue;
                    $tracks[]=vp3_media_native_track_v1($pdo,$user,$agent,(int)substr($ref,strlen('media:track:')));
                }
            }
            foreach(array_filter($tracks) as $track){
                $item=vp3_media_native_item_v1($track,'track',$offers);
                $items[$item['ref']]=$item;
            }
        }
    }catch(PDOException $error){
        $coverage='unavailable';
    }
    if($wants('stoneward')&&($refs!==null
        ||(table_exists('vp3_stoneward_connections_v1')&&table_exists('vp3_connected_site_reports_v1')))){
        require_once __DIR__.'/agent-media-stoneward-v1.php';
        try{
            $stoneward=vp3_stoneward_media_sources_v1($pdo,$scope,$refs,$now);
            $items=$stoneward['items']+$items;
            if($stoneward['coverage']==='limited'&&$coverage==='ready')$coverage='limited';
        }catch(PDOException){
            $coverage=$refs===null?'limited':'unavailable';
        }
    }
    $items+=$youtube['items'];
    if($wants('studio')){
        require_once __DIR__.'/agent-media-production-v1.php';
        try{$items=vp3_media_production_sources_v1($pdo,$scope,$refs)+$items;}
        catch(PDOException){$coverage='limited';}
    }
    if($refs!==null)$items=array_intersect_key($items,array_fill_keys($refs,true));
    require_once __DIR__.'/agent-media-preferences-v1.php';
    $items=vp3_media_preferences_sources_v1($pdo,$scope,$items);
    if($coverage==='ready'&&in_array($youtube['coverage'],['limited','unavailable'],true))$coverage='limited';
    $result=['items'=>$items,'coverage'=>$coverage];
    if($coordination&&$refs===null&&$coverage==='ready'&&!$items)$result['offer_gate']='no_playable_sources';
    return $result;
}

/** A running, owner-scoped report from verified media metadata and saved results. */
function vp3_initiative_media_report_v1(PDO $pdo,array $scope,DateTimeImmutable $now,array $nativeListening=[]): array
{
    require_once __DIR__.'/agent-media-offer-feedback-v1.php';
    $binding=['agent_id'=>$scope['agent_id'],'conversation_id'=>$scope['conversation_id']];
    $current=vp3_media_sources_v1($pdo,$scope,$binding,null,$now);
    $findings=[];
    $shownOffers=[];
    foreach(array_slice($current['items'],0,12) as $item){
        $actions=$item['actions']??[];
        if(str_starts_with($item['ref'],'media:youtube:')&&$scope['conversation_id']>0){
            $actions[]=['kind'=>'chat','label'=>'Open saved YouTube results','conversation_id'=>$scope['conversation_id'],
                'message_id'=>(int)substr($item['ref'],strlen('media:youtube:')),'href'=>$item['url']];
        }elseif(in_array($item['kind'],['stoneward_track','stoneward_resume'],true)){
            $actions[]=['kind'=>'stoneward_history','label'=>'Open listening history','href'=>$item['url']];
        }elseif(!$actions&&is_string($item['url']??null)&&$item['url']!==''){
            $actions[]=['kind'=>'url','label'=>in_array($item['kind'],['resume','track'],true)?'Open track':'View source','href'=>$item['url']];
        }
        if(is_string($item['artist_url']??null)&&$item['artist_url']!==''&&$item['artist_url']!==($item['url']??null)){
            $actions[]=['kind'=>'url','label'=>'Artist profile','href'=>$item['artist_url']];
        }
        $finding=['id'=>$item['ref'],'kind'=>$item['kind'],'title'=>$item['title'],'summary'=>$item['summary'],
            'next_step'=>$item['next_step'],'question'=>$item['question'],'actions'=>$actions];
        $engagement=vp3_media_offer_feedback_v1($pdo,$scope['user'],$binding,$item);
        if($engagement!==null){
            $finding['engagement']=$engagement;
            $shownOffers[$engagement['message_id']]=true;
        }
        $findings[]=$finding;
    }
    $recentOffers=vp3_media_recent_offer_feedback_v1($pdo,$scope['user'],$binding);
    $recentOffers=array_values(array_filter($recentOffers,static fn(array $offer):bool=>!isset($shownOffers[$offer['message_id']])));
    $recentListening=vp3_media_recent_listening_v1($pdo,$scope,$now,$nativeListening);
    $recentFinding=vp3_media_recent_play_finding_v1(array_values(array_filter($recentListening,
        static fn(array $play):bool=>($play['actions'][0]['kind']??null)==='stoneward_history')),$findings);
    if($recentFinding!==null)$findings[]=$recentFinding;
    return ['coverage'=>$current['coverage'],'findings'=>$findings,'recent_offers'=>$recentOffers,'recent_listening'=>$recentListening];
}

function vp3_media_recent_listening_v1(PDO $pdo,array $scope,DateTimeImmutable $now,array $nativeListening): array
{
    $at=$now->getTimestamp();
    $items=[];
    if(!vp3_media_playback_history_allowed_v1($pdo,$scope['user'],$scope['principal']))$nativeListening=[];
    foreach($nativeListening as $track){
        if($track['played_at']<$at-7*86400||$track['played_at']>$at)continue;
        $track['detail']='VP3 Music Player · '.$track['detail'];
        $items[]=$track;
    }
    if(table_exists('vp3_stoneward_connections_v1')&&table_exists('vp3_connected_site_reports_v1')){
        require_once __DIR__.'/agent-media-stoneward-v1.php';
        try{array_push($items,...vp3_stoneward_media_recent_listening_v1($pdo,$scope,$at));}
        catch(PDOException){}
    }
    usort($items,static fn(array $a,array $b):int=>$b['played_at']<=>$a['played_at']);
    return array_slice($items,0,8);
}

/** A verified listening record can explain a next step without becoming a proactive playback offer. */
function vp3_media_recent_play_finding_v1(array $listening,array $findings=[]): ?array
{
    $play=$listening[0]??null;
    if($play===null||!$play['actions'])return null;
    $links=array_column($play['actions'],'href');
    foreach($findings as $finding){
        if($finding['title']===$play['title']&&array_intersect($links,array_column($finding['actions'],'href')))return null;
    }
    $site=$play['actions'][0]['kind']==='stoneward_history';
    $source=$site?parse_url($links[0],PHP_URL_HOST):'the VP3 Music Player';
    return ['id'=>'played:'.$play['id'],'kind'=>$site?'stoneward_played':'native_played',
        'title'=>mb_substr($play['title'],0,190),'summary'=>'You played this track on '.$source.'.',
        'next_step'=>$site?'Open the artist site’s listening history.':'Open this track in the VP3 Music Player.',
        'question'=>$site?'Would you like to revisit the artist’s listening history?':'Would you like to hear this track again?',
        'actions'=>$play['actions']];
}

/** Match a named Play reply only to the exact track already offered to this owner. */
function vp3_media_offer_title_key_v1(string $title): string
{
    $plain=preg_replace('/[\p{C}\p{P}\p{S}\p{Z}\s]+/u',' ',mb_strtolower($title,'UTF-8'));
    return trim($plain??'');
}

function vp3_media_offer_named_reply_v1(string $text,string $offeredTitle): bool
{
    if(mb_strlen($text,'UTF-8')>220)return false;
    if(!preg_match('~\A(?:(?:yes|yeah|yep|sure|ok(?:ay)?)[,\s]+)?(?:please\s+)?(?:play|resume)(?:\s+me)?\s+(.+?)(?:\s+please)?[.!?]*\z~iu',$text,$match))return false;
    $request=vp3_media_offer_title_key_v1($match[1]);
    $expected=vp3_media_offer_title_key_v1($offeredTitle);
    if($request===''||$expected==='')return false;
    if(hash_equals($expected,$request))return true;
    // Spoken "play a Vacation Brain" can refer to the title "Vacation Brain".
    $withoutArticle=preg_replace('/\A(?:a|an|the)\s+/u','',$request,1);
    return is_string($withoutArticle)&&$withoutArticle!==''&&hash_equals($expected,$withoutArticle);
}

/** A bare acceptance belongs only to the immediately preceding Media question. */
function vp3_media_reply_target_v1(PDO $pdo,array $user,int $agent,int $cid,string $text,?array $explicit=null): ?array
{
    if($cid<1||vp3_participant_scope_v1()!==null)return null;
    $text=trim($text);
    $intent=match(true){
        preg_match('~\A(?:(?:yes|yeah|yep|sure|ok(?:ay)?|go ahead)(?:[ ,]+(?:please|play it|resume it|continue))?|(?:please )?(?:(?:play|watch) (?:it|that(?: one)?)|resume(?: it)?|continue(?: listening)?))[.!?]*\z~iu',$text)===1=>'play',
        preg_match('~\A(?:please\s+)?(?:queue (?:a few |some )?(?:related |more )?(?:tracks|songs)|(?:make|build) (?:me )?a (?:short )?(?:queue|playlist))(?: please)?[.!?]*\z~iu',$text)===1=>'queue',
        preg_match('~\A(?:no(?:,? (?:thanks|thank you))?|skip(?: it| this one| that one)?)[.!?]*\z~iu',$text)===1=>'decline',
        preg_match('~\A(?:not (?:right )?now|(?:maybe )?later|another time)[.!?]*\z~iu',$text)===1=>'defer',
        default=>null,
    };
    $namedReply=$intent===null&&preg_match('~\A(?:(?:yes|yeah|yep|sure|ok(?:ay)?)[,\s]+)?(?:please\s+)?(?:play|resume)(?:\s+me)?\s+.{1,220}\z~iu',$text)===1;
    if($intent===null&&!$namedReply)return null;
    $agentWhere=$agent>0?'c.user_agent_id=?':'c.user_agent_id IS NULL';
    $exact=$explicit===null?'':' AND m.id=?';
    $read=$pdo->prepare("SELECT m.id,m.role,m.user_id,m.context_json,m.created_at FROM chat_messages m JOIN chat_conversations c ON c.id=m.conversation_id
        WHERE c.user_id=? AND c.id=? AND $agentWhere$exact ORDER BY m.id DESC LIMIT 1");
    $read->execute([(int)$user['id'],$cid,...($agent>0?[$agent]:[]),...($explicit===null?[]:[$explicit['message_id']])]);
    $row=$read->fetch(PDO::FETCH_ASSOC);
    $context=json_decode((string)($row['context_json']??''),true);
    $output=$context['initiative_output']??null;
    $state=$context['initiative_followthrough_state']??null;
    if(($row['role']??null)!=='assistant'||$row['user_id']!==null||($context['source']??null)!=='agent_initiative'
        ||($output['type']??null)!=='initiative_coordination'||($output['state']??null)!=='needs_owner'
        ||!is_array($output['evidence_revisions']??null)||count($output['evidence_revisions'])!==1
        ||!str_starts_with((string)array_key_first($output['evidence_revisions']),'media:'))return null;
    $sourceRef=(string)array_key_first($output['evidence_revisions']);
    if($intent===null){
        // Do not treat an arbitrary named song as acceptance of an unrelated offer.
        // Only an existing native track/resume offer may authorize this path.
        if(!preg_match('/\Amedia:(track|resume):([1-9][0-9]*)\z/D',$sourceRef,$namedTrack))return null;
        require_once __DIR__.'/agent-media-playback-v1.php';
        $trackId=filter_var($namedTrack[2],FILTER_VALIDATE_INT);
        if($trackId===false||$trackId<1)return null;
        $track=vp3_media_playable_track_v1($pdo,$user,$trackId,$namedTrack[1]==='track');
        if($track===null||!vp3_media_offer_named_reply_v1($text,(string)$track['title']))return null;
        $intent='play';
    }
    $mediaType=match(true){
        str_starts_with($sourceRef,'media:stoneward:')=>['stoneward'=>true],
        str_starts_with($sourceRef,'media:resume:'),str_starts_with($sourceRef,'media:track:')=>['native_playback'=>true],
        str_starts_with($sourceRef,'media:youtube:')=>[],
        default=>null,
    };
    if($mediaType===null)return null; // Analysis questions use the ordinary Initiative reply interpreter.
    if($intent==='queue'&&empty($mediaType['stoneward']))return null;
    require_once __DIR__.'/agent-initiative-followthrough-v1.php';
    if($explicit===null){
        if(($state['state']??null)!=='open'||!vp3_initiative_followthrough_recent_post_v1($row['created_at']))return null;
    }else{
        if(($state['last_reply_explicit']??false)===true&&($state['reply_ref']??null)===$explicit['reply_ref']
            &&($state['revision']??null)===$explicit['revision']+1
            &&hash_equals((string)($state['last_reply_hash']??''),hash('sha256',$text))){
            return ['message_id'=>(int)$row['id'],'intent'=>'replay']+$mediaType;
        }
        if(($state['state']??null)!=='open'||($state['reply_ref']??null)!==$explicit['reply_ref']
            ||($state['revision']??null)!==$explicit['revision'])throw new RuntimeException('That media offer changed.',409);
    }
    return ['message_id'=>(int)$row['id'],'intent'=>$intent]+$mediaType;
}

/** The remote stream lookup runs before the decision locks Chat and its source. */
function vp3_media_stoneward_offer_playback_v1(
    PDO $pdo,array $user,array $principal,int $cid,array $target,?callable $authority,?callable $transport=null
): ?array
{
    $live=vp3_capability_live_user_v1($pdo,$user,$principal,['conversation_id'=>$cid],$authority);
    $origin=vp3_initiative_followthrough_origin_load_v1($pdo,$live,$principal,$cid,$target['message_id']);
    $goal=$origin['goal'];
    $primary=$goal['primary'];
    if(($goal['coordination_route']['specialist']??null)!=='media'||($goal['coordination_route']['action']??null)!=='ask'
        ||!str_starts_with($primary,'media:stoneward:')||($origin['state']['state']??null)!=='open')return null;
    try{
        $sources=vp3_coordination_result_evidence_v1($pdo,$live,$goal['binding'],$goal,$origin['result']['initiative_tool_result']);
        vp3_coordination_route_current_v1($pdo,$live,$goal,$sources);
        $source=$sources[$primary];
        require_once __DIR__.'/connected-stoneward-v1.php';
        require_once __DIR__.'/connected-stoneward-chat-v1.php';
        if(session_status()===PHP_SESSION_ACTIVE)session_write_close();
        $connection=$source['stoneward_connection_id'];
        $trackId=$source['stoneward_track_id'];
        if($target['intent']==='queue'){
            $tracks=vp3_stoneward_catalog_queue_v1($pdo,(int)$live['id'],$connection,$trackId,$transport);
            if(!$tracks)return null;
            $playback=['connected_site_queue_v1'=>$tracks];
        }elseif(isset($source['stoneward_resume_revision'])){
            $resume=vp3_stoneward_playback_resume_v1($pdo,$live,$principal,$connection);
            if(($resume['track_id']??null)!==$trackId||$resume['revision']!==$source['stoneward_resume_revision'])return null;
            $playback=['connected_site_playback_v1'=>vp3_stoneward_chat_resume_track_v1($pdo,$live,$principal,$resume,$transport)];
        }else{
            $playback=['connected_site_playback_v1'=>vp3_stoneward_playable_v1($pdo,(int)$live['id'],$connection,
                ['trackId'=>$trackId],$transport)];
        }
        $identity=array_intersect_key($source,array_fill_keys(
            ['ref','revision','stoneward_connection_id','stoneward_track_id','stoneward_resume_revision'],true));
        return ['source'=>$identity,'playback'=>$playback];
    }catch(RuntimeException $error){
        if($error instanceof Vp3InitiativeSourcesChangedV1||in_array((int)$error->getCode(),[403,404,409,501],true))return null;
        throw new RuntimeException('Stonefellow could not verify the stream right now. Try the reply again.',503,$error);
    }
}

/** One accepted owner turn consumes the original offer and returns an exact, fresh Player action. */
function vp3_media_reply_chat_v1(PDO $pdo,array $user,array $principal,int $cid,string $text,?array $explicit,array $target,
    string $inputMode,?callable $authority,?callable $stonewardTransport=null): array
{
    require_once __DIR__.'/agent-initiative-followthrough-v1.php';
    require_once __DIR__.'/agent-coordination-work-v1.php';
    require_once __DIR__.'/agent-media-playback-v1.php';
    $prepared=($target['stoneward']??false)&&in_array($target['intent'],['play','queue'],true)
        ?vp3_media_stoneward_offer_playback_v1($pdo,$user,$principal,$cid,$target,$authority,$stonewardTransport):null;
    return vp3_initiative_followthrough_transaction_v1($pdo,static function()use($pdo,$user,$principal,$cid,$text,$explicit,$target,$inputMode,$authority,$prepared):array{
        vp3_initiative_owner_lock_v1($pdo,$user);
        $agent=(int)$principal['agent_id'];
        $live=vp3_capability_live_user_v1($pdo,$user,$principal,['conversation_id'=>$cid],$authority);
        if(vp3_media_reply_target_v1($pdo,$live,$agent,$cid,$text,$explicit)!==$target){
            throw new RuntimeException('That media offer is no longer current.',409);
        }
        $origin=vp3_initiative_followthrough_origin_load_v1($pdo,$live,$principal,$cid,$target['message_id'],true);
        $goal=$origin['goal'];
        if(($goal['coordination_route']['specialist']??null)!=='media'||($goal['coordination_route']['action']??null)!=='ask'){
            throw new RuntimeException('That media offer changed.',409);
        }
        $intent=$target['intent'];
        if($intent==='replay')return vp3_media_offer_saved_reply_v1($pdo,$origin,$text,$explicit,$inputMode)+['replayed'=>true];
        if(($origin['state']['state']??null)!=='open')throw new RuntimeException('That media offer changed.',409);
        $playback=null;$youtube=null;$stoneward=null;
        if(in_array($intent,['play','queue'],true)){
            try{
                $sources=vp3_coordination_result_evidence_v1($pdo,$live,$goal['binding'],$goal,$origin['result']['initiative_tool_result']);
                vp3_coordination_route_current_v1($pdo,$live,$goal,$sources);
                $source=$sources[$goal['primary']];
                $track=$source['track_id']??0;
                if(str_starts_with($goal['primary'],'media:resume:')){
                    $playback=vp3_media_resume_action_v1($pdo,$live,$agent,$cid,$track,$source['playback_revision']??$source['revision'],$target['message_id']);
                }elseif(str_starts_with($goal['primary'],'media:track:')){
                    $playback=vp3_media_native_pick_action_v1($pdo,$live,$agent,$cid,$track,$source['playback_revision']??$source['revision'],$target['message_id']);
                }elseif(str_starts_with($goal['primary'],'media:stoneward:')){
                    if($prepared!==null&&array_intersect_key($source,$prepared['source'])===$prepared['source']){
                        $stoneward=$prepared['playback'];
                    }
                }elseif(is_array($source['playback']??null)){
                    $youtube=$source['playback'];
                    unset($youtube['source_message_id'],$youtube['play_selected']);
                }
            }catch(Vp3InitiativeSourcesChangedV1 $error){
            }catch(RuntimeException $error){
                if(!in_array($error->getCode(),[403,409],true))throw $error;
            }
        }
        $accepted=$playback!==null||$youtube!==null||$stoneward!==null;
        $answer=match(true){
            isset($stoneward['connected_site_queue_v1'])=>'Here’s a short queue of related tracks from that Stonefellow artist site.',
            $stoneward!==null=>'Here’s the Stonefellow track in the VP3 Media Player.',
            $playback!==null=>'I’ll open that track in the VP3 Media Player.',
            $youtube!==null=>'Here’s the YouTube video I offered.',
            $intent==='play'=>'That media pick is no longer available.',
            $intent==='queue'=>'I couldn’t verify more public tracks for a queue on that artist site.',
            $intent==='defer'=>'I’ll leave that one for later.',
            default=>'I’ll skip that one.',
        };
        $pdo->prepare("INSERT INTO chat_messages(conversation_id,user_id,role,message,context_json)
            VALUES(?,?,'user',?,'{\"skip_brain_archive\":true}')")->execute([$cid,(int)$live['id'],$text]);
        $userMessage=(int)$pdo->lastInsertId();
        $state=$origin['state'];
        $state['revision']++;
        $state['state']=match(true){$accepted=>'owner_resolved',in_array($intent,['play','queue'],true)=>'stale',$intent==='defer'=>'deferred',default=>'declined'};
        $state['last_intent']=match($state['state']){'owner_resolved'=>'advance','deferred'=>'defer','declined'=>'decline',default=>'discuss'};
        $state['last_reply_message_id']=$userMessage;
        $state['last_reply_hash']=hash('sha256',$text);
        $state['last_reply_explicit']=$explicit!==null;
        $state['updated_at']=gmdate(DATE_ATOM);
        if($state['state']==='stale')$state['coordination_source_changed']=true;
        $outcome=vp3_initiative_followthrough_store_v1($pdo,$origin,$state);
        $context=['skip_brain_archive'=>true,'chat_user_message_id'=>$userMessage,'initiative_followthrough'=>$outcome,
            'media_offer_reply_v1'=>true,'coordination_reply'=>true];
        if($stoneward!==null){
            $connection=$prepared['source']['stoneward_connection_id'];
            $context['media_offer_decision_v1']=['kind'=>$intent==='queue'?'stoneward_queue':'stoneward','connection_id'=>$connection];
            $context['connected_site_provider_v1']='stoneward';
            $context['connected_site_context_v1']=['provider'=>'stoneward','connectionId'=>$connection,
                'actions'=>[$intent==='queue'?'listening.track.queue':'listening.track.play']];
        }
        if($playback!==null)$context['media_offer_decision_v1']=['kind'=>'native','track_id'=>$playback['track_id']];
        if($youtube!==null){
            $context['media_offer_decision_v1']=['kind'=>'youtube','video_id'=>$youtube['selected_video_id']];
            $context['youtube_search_results_v1']=true;
        }
        $pdo->prepare("INSERT INTO chat_messages(conversation_id,user_id,role,message,context_json)
            VALUES(?,NULL,'assistant',?,?)")->execute([$cid,$answer,json_encode($context,JSON_THROW_ON_ERROR)]);
        $assistant=(int)$pdo->lastInsertId();
        if($stoneward!==null){
            $stoneward=vp3_stoneward_chat_live_playback_v1($pdo,$live,$principal,$cid,$assistant,$connection,$stoneward,
                $intent==='play'?$target['message_id']:null);
        }
        if($youtube!==null){
            agent_connection_youtube_results_save_v1($pdo,(int)$live['id'],$cid,$assistant,$youtube);
            $youtube+=['source_message_id'=>$assistant,'play_selected'=>true];
        }
        agent_workflow_event_v1400($pdo,(int)$live['id'],(int)$origin['run']['id'],'initiative_owner_decision','open',
            $state['state'],'owner','Owner answered a saved Media offer.',[
                'message_id'=>$target['message_id'],'reply_message_id'=>$userMessage,'revision'=>$state['revision'],
                'intent'=>$state['last_intent'],'continuation_run_id'=>0,
            ]);
        $pdo->prepare('UPDATE chat_conversations SET updated_at=NOW() WHERE id=? AND user_id=?')->execute([$cid,(int)$live['id']]);
        return ['ok'=>true,'conversation_id'=>$cid,'user_message_id'=>$userMessage,'assistant_message_id'=>$assistant,
            'agent_id'=>$agent,'agent_name'=>(string)($principal['display_name']??'Agent'),'answer'=>$answer,
            'input_mode'=>$inputMode,'sources'=>[],'actions'=>[],'initiative_followthrough'=>$outcome,
            'coordination_reply'=>true,'media_offer_reply_v1'=>true]
            +($playback!==null?['media_playback'=>$playback]:[])+($youtube!==null?['youtube_results'=>$youtube]:[])
            +($stoneward!==null?['connected_site_provider_v1'=>'stoneward']+$stoneward:[]);
    });
}

/** An exact retry may recover its saved reply, with no second Player action. */
function vp3_media_offer_saved_reply_v1(PDO $pdo,array $origin,string $text,?array $explicit,string $inputMode): array
{
    $outcome=vp3_initiative_followthrough_outcome_v1($origin);
    $state=$origin['state'];$cid=$outcome['conversation_id'];
    $expected=['message_id'=>$outcome['message_id'],'reply_ref'=>$outcome['reply_ref'],'revision'=>$outcome['revision']-1];
    if(!hash_equals($state['last_reply_hash'],hash('sha256',$text))||$state['last_reply_explicit']!==($explicit!==null)
        ||($explicit!==null&&$expected!==$explicit))throw new RuntimeException('The saved media reply changed.',409);
    $read=$pdo->prepare('SELECT id,user_id,role,message,context_json FROM chat_messages WHERE conversation_id=? ORDER BY id DESC LIMIT 2');
    $read->execute([$cid]);
    [$assistant,$owner]=array_pad($read->fetchAll(PDO::FETCH_ASSOC),2,null);
    $context=json_decode((string)($assistant['context_json']??''),true);
    if(($assistant['role']??null)!=='assistant'||($owner['role']??null)!=='user'
        ||(int)($owner['id']??0)!==$outcome['reply_message_id']||(int)($owner['user_id']??0)!==$outcome['owner_user_id']
        ||($owner['message']??null)!==$text||($context['media_offer_reply_v1']??false)!==true
        ||($context['initiative_followthrough']??null)!==$outcome)throw new RuntimeException('The saved media reply changed.',409);
    return ['ok'=>true,'conversation_id'=>$cid,'user_message_id'=>$outcome['reply_message_id'],
        'assistant_message_id'=>(int)$assistant['id'],'agent_id'=>$outcome['agent_id'],
        'agent_name'=>(string)($origin['principal']['display_name']??'Agent'),
        'answer'=>in_array($state['state'],['owner_resolved','completed'],true)?'I saved your playback request.':$assistant['message'],
        'input_mode'=>$inputMode,'sources'=>[],'actions'=>[],'initiative_followthrough'=>$outcome,
        'coordination_reply'=>true,'media_offer_reply_v1'=>true];
}

function vp3_media_offer_reply_replay_v1(PDO $pdo,array $user,array $principal,array $input,array $cached,?callable $authority): array
{
    if($authority)$authority();
    $outcome=$cached['initiative_followthrough']??null;
    if(!is_array($outcome)||!is_string($input['message']??null)||(int)($input['conversation_id']??0)!==$outcome['conversation_id']
        ||($cached['conversation_id']??null)!==$outcome['conversation_id'])throw new RuntimeException('The saved media reply changed.',409);
    $origin=vp3_initiative_followthrough_origin_load_v1($pdo,$user,$principal,$outcome['conversation_id'],$outcome['message_id']);
    if(($origin['goal']['coordination_route']['specialist']??null)!=='media'||vp3_initiative_followthrough_outcome_v1($origin)!==$outcome){
        throw new RuntimeException('The saved media reply changed.',409);
    }
    return vp3_media_offer_saved_reply_v1($pdo,$origin,trim($input['message']),vp3_agent_chat_initiative_reply_v2160($input),'voice');
}
