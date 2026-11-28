<?php
declare(strict_types=1);

require_once __DIR__.'/agent-casual-checkin-settings-v1.php';
require_once __DIR__.'/agent-media-entertainment-v1.php';
require_once __DIR__.'/agent-specialist-starters-v1.php';
require_once __DIR__.'/agent-specialist-invitations-v1.php';

const VP3_CASUAL_CHECKIN_QUESTIONS_V1 = [
    'youtube_history'=>'Want to watch something on YouTube?',
    'media'=>'Would you like to listen to music or watch a video?',
    'music'=>'Want to listen to some music?',
    'youtube'=>'Want to watch something on YouTube?',
    'email'=>'Want to draft an email?',
    'today'=>'Want to see what’s next today?',
    'resume'=>'Want to pick up where you left off?',
];

const VP3_CASUAL_MEDIA_KINDS_V1=['youtube_history','media','music','youtube'];

function vp3_casual_checkin_delivery_v1(PDO $pdo,int $owner): array
{
    $query=$pdo->prepare('SELECT setting_value FROM settings WHERE setting_key=?');
    $query->execute(['casual_checkin_delivery_owner_'.$owner]);
    $state=json_decode((string)$query->fetchColumn(),true);
    return is_array($state)?$state:[];
}

function vp3_casual_checkin_allowed_v1(PDO $pdo,array $user,int $cid,?string $kind=null): bool
{
    $owner=(int)($user['id']??0);
    if($owner<1||$cid<1||vp3_participant_scope_v1()!==null)return false;
    if($kind==='youtube_history')return vp3_casual_checkin_delivery_ready_v1($pdo,$user,$cid,$kind);
    if(!vp3_media_quiet_chat_v1($pdo,$user,$cid))return false;
    $starter=$kind===null||isset(VP3_SPECIALIST_STARTERS_V1[$kind]);
    $delivery=vp3_casual_checkin_delivery_v1($pdo,$owner);
    if($starter&&($kind===null||!isset(vp3_casual_starter_offers_v1($delivery)[$kind]))
        &&vp3_casual_starter_may_offer_v1($pdo,$user,$cid,$delivery))return true;
    if($kind!==null&&$starter)return false;
    $preference=vp3_casual_checkin_preference_v1($pdo,$owner);
    $media=$kind!==null&&in_array($kind,VP3_CASUAL_MEDIA_KINDS_V1,true);
    if(($kind===null||$media)&&$preference!==false&&vp3_casual_checkin_media_idle_v1($pdo,$user,$cid,$delivery,time()))return true;
    return !$media&&$preference===true&&vp3_casual_checkin_contextual_v1($pdo,$user,$cid,$delivery);
}

/** Recheck preferences for a selected offer after its exact source was verified. */
function vp3_casual_checkin_delivery_ready_v1(PDO $pdo,array $user,int $cid,string $kind): bool
{
    $owner=(int)($user['id']??0);
    if($owner<1||$cid<1||vp3_participant_scope_v1()!==null)return false;
    if($kind==='youtube_history'){
        $read=$pdo->prepare('SELECT COALESCE(user_agent_id,0) FROM chat_conversations WHERE id=? AND user_id=?');
        $read->execute([$cid,$owner]);
        $agent=$read->fetchColumn();
        return $agent!==false&&vp3_casual_checkin_history_gate_v1($pdo,$user,(int)$agent)==='ready';
    }
    if(isset(VP3_SPECIALIST_STARTERS_V1[$kind])){
        return !isset(vp3_casual_starter_offers_v1(vp3_casual_checkin_delivery_v1($pdo,$owner))[$kind]);
    }
    $preference=vp3_casual_checkin_preference_v1($pdo,$owner);
    return in_array($kind,VP3_CASUAL_MEDIA_KINDS_V1,true)?$preference!==false
        :isset(VP3_CASUAL_CHECKIN_QUESTIONS_V1[$kind])&&$preference===true;
}

/** Exact history suggestions use the saved Media preference and pacing, independently of browser attention. */
function vp3_casual_checkin_history_gate_v1(PDO $pdo,array $user,int $agent): string
{
    $owner=(int)$user['id'];
    if(vp3_casual_checkin_preference_v1($pdo,$owner)===false)return 'disabled';
    require_once __DIR__.'/agent-coordination-rules-v1.php';
    $rules=vp3_coordination_rules_v1($pdo,$user,$agent);
    if(vp3_coordination_route_v1($rules,'media',['recent_search'])['action']!=='ask')return 'report_only';
    return vp3_media_cooldown_ready_v1($pdo,$owner)?'ready':'cooldown';
}

function vp3_casual_checkin_contextual_v1(PDO $pdo,array $user,int $cid,array $delivery): bool
{
    $owner=(int)$user['id'];
    $previous=max([vp3_casual_checkin_last_message_v1($pdo,$owner,$delivery),...array_values(vp3_casual_starter_offers_v1($delivery))]);
    if($previous===0)return true;

    // Polling, switching between old Chats, and answering a check-in
    // do not count as starting new work.
    $query=$pdo->prepare("SELECT 1 FROM chat_messages WHERE conversation_id=? AND user_id=? AND role='user' AND id>?
        AND JSON_EXTRACT(CASE WHEN JSON_VALID(context_json) THEN context_json ELSE '{}' END,'$.casual_checkin_reply_v1') IS NULL
        LIMIT 1");
    $query->execute([$cid,$owner,$previous]);
    return $query->fetchColumn()!==false;
}

/** Only explicit foreground activity can start another idle Media stretch. */
function vp3_casual_checkin_media_idle_v1(PDO $pdo,array $user,int $cid,array $delivery,int $now,
    ?string &$hold=null,?int &$activityWatermark=null): bool
{
    require_once __DIR__.'/agent-idle-evidence-v1.php';
    $hold='verification_unavailable';$activityWatermark=0;
    $owner=(int)$user['id'];
    $read=$pdo->prepare('SELECT COALESCE(user_agent_id,0) FROM chat_conversations WHERE id=? AND user_id=?');
    $read->execute([$cid,$owner]);
    $agent=$read->fetchColumn();
    if($agent===false)return false;
    $activity=vp3_agent_idle_evidence_v1($pdo,$user,(int)$agent,$cid,new DateTimeImmutable('@'.$now));
    if(($activity['readiness']??null)!=='ready')return false;
    if(!empty($activity['busy'])){
        $hold=($activity['busy_reason']??null)==='playback'?'playing':'quiet_chat_required';
        return false;
    }
    $lastActivity=(int)($activity['activity_at']??0);
    $quietSince=max($lastActivity,(int)($activity['quiet_since']??0));
    if($quietSince>0&&$now-$quietSince<30){$hold='idle_spacing';return false;}
    $lastIndependentActivity=(int)($activity['ordinary_chat_at']??0);
    if(in_array($activity['source']??null,['chat_interaction','youtube_search'],true)){
        $lastIndependentActivity=max($lastIndependentActivity,$lastActivity);
    }
    $activityWatermark=$lastIndependentActivity;

    $lastMedia=$delivery['media_offer_message_id']??0;
    if($lastMedia===0&&in_array($delivery['kind']??null,VP3_CASUAL_MEDIA_KINDS_V1,true)){
        $lastMedia=vp3_casual_checkin_last_message_v1($pdo,$owner,$delivery);
    }
    $posted=0;
    if(is_int($lastMedia)&&$lastMedia>0){
        $time=vp3_initiative_db_wall_epoch_sql_v1($pdo,'m.created_at');
        $read=$pdo->prepare("SELECT $time FROM chat_messages m JOIN chat_conversations c ON c.id=m.conversation_id
            WHERE c.user_id=? AND m.id=? AND m.role='assistant' AND m.user_id IS NULL");
        $read->execute([$owner,$lastMedia]);
        $posted=(int)$read->fetchColumn();
    }
    // Native ranked Media offers use the same quiet period as a generic or history-based invitation.
    $read=$pdo->prepare('SELECT setting_value FROM settings WHERE setting_key=?');
    $read->execute(['media_next_offer_owner_'.$owner]);
    $posted=max($posted,(int)$read->fetchColumn()-4*3600);
    if($posted>0&&$now-$posted<120){$hold='idle_spacing';return false;}
    if($posted>0&&$lastIndependentActivity<=$posted){$hold='new_activity_required';return false;}
    $hold=null;
    return true;
}

function vp3_casual_checkin_last_message_v1(PDO $pdo,int $owner,array $delivery): int
{
    if(is_int($delivery['message_id']??null)&&$delivery['message_id']>0)return $delivery['message_id'];
    $kind=$delivery['kind']??null;
    $cid=$delivery['conversation_id']??0;
    if(!is_int($cid)||$cid<1||!is_string($kind)||!isset(VP3_CASUAL_CHECKIN_QUESTIONS_V1[$kind])
        ||!is_int($delivery['delivered_at']??null))return 0;

    // Older settings predate the message ID. Recover the post and any
    // old reply from the same owner's saved Chat.
    $query=$pdo->prepare("SELECT m.id,m.context_json FROM chat_messages m
        JOIN chat_conversations c ON c.id=m.conversation_id WHERE c.user_id=? AND m.conversation_id=?
        AND m.role='assistant' AND m.created_at>=? AND m.created_at<=? AND m.message=? ORDER BY m.id DESC LIMIT 1");
    $time=$delivery['delivered_at'];
    $query->execute([$owner,$cid,gmdate('Y-m-d H:i:s',$time-5),gmdate('Y-m-d H:i:s',$time+5),VP3_CASUAL_CHECKIN_QUESTIONS_V1[$kind]]);
    $row=$query->fetch(PDO::FETCH_ASSOC);
    $context=json_decode((string)($row['context_json']??''),true);
    $refs=$context['initiative_output']['evidence_revisions']??null;
    if(($context['source']??null)!=='agent_initiative'||!isset($refs['casual:'.$kind]))return 0;
    return max((int)$row['id'],(int)($context['initiative_followthrough_state']['last_reply_message_id']??0));
}

function vp3_casual_checkin_seed_v1(array $delivery): string|int
{
    $message=$delivery['message_id']??0;
    return is_int($message)&&$message>0?'message:'.$message:(int)($delivery['delivered_at']??0);
}

/** The shared Initiative delivery transaction owns the account lock. */
function vp3_casual_checkin_record_v1(PDO $pdo,int $owner,string $kind,int $cid,int $messageId,array $evidence): void
{
    $starter=isset(VP3_SPECIALIST_STARTERS_V1[$kind]);
    if($owner<1||$cid<1||$messageId<1||(!$starter&&!isset(VP3_CASUAL_CHECKIN_QUESTIONS_V1[$kind]))){
        throw new InvalidArgumentException('Invalid check-in delivery.');
    }
    $key='casual_checkin_delivery_owner_'.$owner;
    $prior=vp3_casual_checkin_delivery_v1($pdo,$owner);
    $offers=vp3_casual_starter_offers_v1($prior);
    if($starter){
        $state=$prior;
        $offers[$kind]=$messageId;
    }else{
        $state=['message_id'=>$messageId,'kind'=>$kind,'offer_seed'=>vp3_casual_checkin_seed_v1($prior),
            'conversation_id'=>$cid,'agent_id'=>$evidence['agent_id']??null];
        if(in_array($kind,['music','media'],true)&&is_int($evidence['track_id']??null))$state['track_id']=$evidence['track_id'];
        if(in_array($kind,['today','resume'],true)&&is_string($evidence['source_ref']??null))$state['source_ref']=$evidence['source_ref'];
    }
    if($offers)$state['starter_offers']=$offers;
    $invitationOffers=is_array($prior['invitation_offers']??null)?$prior['invitation_offers']:[];
    if(isset($evidence['invitation_pin'])){
        $invitationOffers[$kind]=['kind'=>$kind,'conversation_id'=>$cid,'agent_id'=>$evidence['agent_id']??null,
            'offer_seed'=>$state['offer_seed']??vp3_casual_checkin_seed_v1($prior),'invitation_pin'=>$evidence['invitation_pin']];
    }else unset($invitationOffers[$kind]);
    if($invitationOffers)$state['invitation_offers']=$invitationOffers;
    $lastMedia=in_array($kind,VP3_CASUAL_MEDIA_KINDS_V1,true)?$messageId:($prior['media_offer_message_id']??0);
    if($lastMedia===0&&in_array($prior['kind']??null,VP3_CASUAL_MEDIA_KINDS_V1,true)){
        $lastMedia=vp3_casual_checkin_last_message_v1($pdo,$owner,$prior);
    }
    if($lastMedia>0)$state['media_offer_message_id']=$lastMedia;
    if($kind==='youtube_history'&&is_array($evidence['history_pin']??null)){
        $state['history_pin']=$evidence['history_pin'];
        $state['last_history_offer']=array_intersect_key($state,array_flip(['kind','message_id','conversation_id','agent_id','offer_seed','history_pin']));
        $seen=(array)($prior['history_shown']??[]);
        $previous=$prior['last_history_offer']['history_pin']??null;
        if(is_array($previous)&&!in_array($previous,$seen,true))$seen[]=$previous;
        if(!in_array($state['history_pin'],$seen,true))$seen[]=$state['history_pin'];
        // At one offer per four hours, this covers the full 30-day history window.
        $state['history_shown']=array_slice($seen,-192);
        vp3_media_record_offer_v1($pdo,$owner);
    }elseif(is_array($prior['last_history_offer']??null)){
        $state['last_history_offer']=$prior['last_history_offer'];
        if(is_array($prior['history_shown']??null))$state['history_shown']=$prior['history_shown'];
    }
    $pdo->prepare('DELETE FROM settings WHERE setting_key=?')->execute([$key]);
    $pdo->prepare('INSERT INTO settings(setting_key,setting_value) VALUES(?,?)')
        ->execute([$key,json_encode($state,JSON_THROW_ON_ERROR)]);
}

/** Exact reads use the posted source to validate the current invitation. */
function vp3_casual_checkin_sources_v1(PDO $pdo,array $scope,array $binding,?array $refs,DateTimeImmutable $now,
    ?array $existingItems=null,bool $usePosted=true,bool $allCandidates=false,bool $mediaOnly=false,
    bool $allowStartersWithPrivateWork=false,bool $historyOnly=false): array
{
    $result=['items'=>[],'coverage'=>'unconfigured'];
    $owner=(int)$scope['user']['id'];
    $preference=vp3_casual_checkin_preference_v1($pdo,$owner);
    $casual=!$mediaOnly&&$preference===true;
    $media=$preference!==false;
    $starter=(!$mediaOnly||$allowStartersWithPrivateWork)&&($binding['enabled']??null)===true;
    if(!$media&&!$starter)return $result;
    require_once __DIR__.'/agent-idle-media-preferences-v1.php';
    $choices=$media?vp3_idle_media_preferences_v1($pdo,$scope['user'],$scope['agent_id']):[];
    $allowMusic=($choices['allow_music']??false)===true;
    $allowYoutube=($choices['allow_youtube']??false)===true;
    $delivery=vp3_casual_checkin_delivery_v1($pdo,$owner);
    $discovery=$refs===null;
    $historyGate=$allowYoutube&&($discovery||in_array('casual:youtube_history',$refs,true))
        ?vp3_casual_checkin_history_gate_v1($pdo,$scope['user'],$scope['agent_id']):'disabled';
    if($discovery){
        $result['media_enabled']=$allowMusic||$allowYoutube;
        $cid=$scope['conversation_id'];
        $kinds=[];
        if(!$historyOnly){
            $canMedia=($allowMusic||$allowYoutube)&&vp3_casual_checkin_media_idle_v1($pdo,$scope['user'],$cid,$delivery,$now->getTimestamp());
            $canCasual=$casual&&vp3_casual_checkin_contextual_v1($pdo,$scope['user'],$cid,$delivery);
            $all=$casual?array_keys(VP3_CASUAL_CHECKIN_QUESTIONS_V1):VP3_CASUAL_MEDIA_KINDS_V1;
            $last=$delivery['kind']??null;
            // The combined invitation and either single-option fallback share one slot.
            $index=array_search(in_array($last,VP3_CASUAL_MEDIA_KINDS_V1,true)?'youtube':$last,$all,true);
            $next=$index===false?0:$index+1;
            $kinds=array_merge(array_slice($all,$next),array_slice($all,0,$next));
            $kinds=array_filter($kinds,static fn(string $kind):bool=>$kind!=='youtube_history'
                &&(in_array($kind,VP3_CASUAL_MEDIA_KINDS_V1,true)?$canMedia:$canCasual));
        }
        if($historyGate==='ready')array_unshift($kinds,'youtube_history');
        if(!$historyOnly&&$starter&&vp3_casual_starter_may_offer_v1($pdo,$scope['user'],$cid,$delivery)){
            $offers=vp3_casual_starter_offers_v1($delivery);
            $examples=array_keys(array_diff_key(VP3_SPECIALIST_STARTERS_V1,$offers));
            $lastCasual=vp3_casual_checkin_last_message_v1($pdo,$owner,$delivery);
            // Give Media the first choice, then make room for starters between regular invitations.
            $kinds=$lastCasual>max([0,...array_values($offers)])?[...$examples,...$kinds]:[...$kinds,...$examples];
        }
        $refs=array_map(static fn(string $kind):string=>'casual:'.$kind,$kinds);
    }
    foreach($refs as $ref){
        if(!str_starts_with($ref,'casual:'))continue;
        $kind=substr($ref,strlen('casual:'));
        $example=VP3_SPECIALIST_STARTERS_V1[$kind]??null;
        if($example!==null){
            if(!$starter)continue;
            if($result['coverage']==='unconfigured')$result['coverage']='ready';
            try{$available=vp3_casual_starter_available_v1($pdo,$scope,$kind);}
            catch(Throwable $error){
                if(!$discovery&&!in_array((int)$error->getCode(),[403,404,409],true))$result['coverage']='unavailable';
                continue;
            }
            if(!$available)continue;
            // Existing work takes precedence over a one-time generic capability demonstration.
            if($kind==='starter-gmail'){
                $drafts=vp3_specialist_invitation_email_v1($pdo,$scope,$binding);
                if($drafts['state']!=='empty')continue;
            }
            if($kind==='starter-media'&&vp3_casual_checkin_music_v1($pdo,$scope,null)!==null)continue;
            $result['items'][$ref]=vp3_coordination_source_item_v1($ref,'casual',$example['title'],$example['title'],'',$example['question'],
                vp3_agent_activity_chat_href_v1($scope['agent_id'],$scope['conversation_id']),'ready','low',
                ['specialist-starter-v1',$owner,$scope['agent_id'],$scope['conversation_id'],$example['question']])
                +['trigger'=>$kind,'agent_id'=>$scope['agent_id']];
            if($discovery&&!$allCandidates)break;
            continue;
        }
        $mediaKind=in_array($kind,VP3_CASUAL_MEDIA_KINDS_V1,true);
        if(!($mediaKind?$media:$casual)||!isset(VP3_CASUAL_CHECKIN_QUESTIONS_V1[$kind]))continue;
        if($result['coverage']==='unconfigured')$result['coverage']='ready';
        // The previous delivery anchors a new invitation. The just-posted
        // invitation keeps that anchor and its exact source while it is fresh.
        $savedHistory=$delivery['last_history_offer']??[];
        $previous=$kind==='youtube_history'&&($delivery['kind']??null)!==$kind?$savedHistory:$delivery;
        if(isset($delivery['invitation_offers'][$kind]))$previous=$delivery['invitation_offers'][$kind]+$previous;
        $posted=$usePosted&&!$discovery
            &&($previous['kind']??null)===$kind&&($previous['conversation_id']??null)===$scope['conversation_id']
            &&($previous['agent_id']??null)===$scope['agent_id'];
        $seed=$posted?($previous['offer_seed']??(int)($previous['prior_delivered_at']??0)):vp3_casual_checkin_seed_v1($delivery);
        $trackId=$posted&&is_int($previous['track_id']??null)?$previous['track_id']:null;
        $sourceRef=$posted&&is_string($previous['source_ref']??null)?$previous['source_ref']:null;
        $historyPin=$posted&&is_array($previous['history_pin']??null)?$previous['history_pin']:null;
        $invitationPin=$posted&&is_array($previous['invitation_pin']??null)?$previous['invitation_pin']:null;
        $selectionPin=$kind!=='email'&&isset($invitationPin['selection'])?$invitationPin['selection']:$invitationPin;
        $excludePin=!$posted&&is_array($savedHistory['history_pin']??null)?$savedHistory['history_pin']:null;
        // Pacing governs new offers, while existing offers retain their exact reply target.
        if($kind==='youtube_history'&&in_array($historyGate,['disabled','report_only'],true))continue;
        try{
            $choice=match($kind){
                'youtube_history'=>vp3_casual_checkin_youtube_history_v1($pdo,$scope,$now->getTimestamp(),$historyPin,$excludePin,
                    (array)($delivery['history_shown']??[])),
                'media'=>$allowMusic&&$allowYoutube?vp3_casual_checkin_media_v1($pdo,$scope,$trackId,$selectionPin):null,
                'music'=>$allowMusic?vp3_casual_checkin_music_v1($pdo,$scope,$trackId,$selectionPin):null,
                'youtube'=>$allowYoutube?vp3_casual_checkin_youtube_choice_v1($pdo,$scope,$selectionPin):null,
                'email'=>vp3_casual_checkin_email_v1($pdo,$owner,$scope,$binding,$invitationPin),
                'today'=>vp3_casual_checkin_today_v1($pdo,$scope,$now,$existingItems,$sourceRef),
                'resume'=>vp3_casual_checkin_resume_v1($pdo,$scope,$binding,$now,$existingItems,$sourceRef),
                default=>null,
            };
            if($choice!==null&&$kind!=='email'&&isset($choice['evidence']['invitation'])){
                $invitation=$choice['evidence']['invitation'];
                $hint=vp3_specialist_invitation_connection_v1(implode(' ',array_column($invitation['items'],'title')),
                    vp3_specialist_invitation_reports_v1($pdo,$scope,$binding));
                $invitation['context']=$hint['text']??'';
                $contextPin=['selection'=>$choice['evidence']['invitation_pin'],'context_key'=>$hint['key']??null];
                if($invitationPin!==null&&$invitationPin!==$contextPin)$choice=null;
                else{
                    $choice['evidence']['invitation']=$invitation;
                    $choice['evidence']['invitation_pin']=$contextPin;
                    $choice['identity'][]=[$contextPin,$invitation];
                }
            }
        }catch(Throwable $error){
            if($error instanceof PDOException&&((string)$error->getCode()==='40001'
                ||in_array((int)($error->errorInfo[1]??0),[1020,1205,1213],true)))throw $error;
            if(!in_array((int)$error->getCode(),[403,404,409],true))$result['coverage']=$discovery?'limited':'unavailable';
            continue;
        }
        if($choice===null)continue;
        $question=$choice['question']??VP3_CASUAL_CHECKIN_QUESTIONS_V1[$kind];
        $item=vp3_coordination_source_item_v1($ref,'casual',$choice['title'],$choice['summary'],'',$question,
            $choice['url']??vp3_agent_activity_chat_href_v1($scope['agent_id'],$scope['conversation_id']),
            'ready','low',['casual-checkin-v1',$owner,$scope['agent_id'],$scope['conversation_id'],$seed,$choice['identity']]);
        $result['items'][$ref]=$item+['trigger'=>$kind,'agent_id'=>$scope['agent_id']]+($choice['evidence']??[]);
        if($discovery&&!$allCandidates)break;
    }
    return $result;
}

function vp3_casual_checkin_youtube_history_v1(PDO $pdo,array $scope,int $now,?array $pin,?array $exclude,array $shown=[]): ?array
{
    require_once __DIR__.'/agent-idle-media-history-v1.php';
    $choice=vp3_idle_media_history_choice_v1($pdo,$scope,$now,$pin,$exclude,$shown);
    return $exclude!==null&&($choice['evidence']['history_pin']??null)===$exclude?null:$choice;
}

function vp3_casual_checkin_media_v1(PDO $pdo,array $scope,?int $trackId,?array $pin=null): ?array
{
    $music=vp3_casual_checkin_music_v1($pdo,$scope,$trackId,$pin['music']??null);
    if($music===null)return null;
    $youtube=vp3_casual_checkin_youtube_v1($pdo,(int)$scope['user']['id']);
    if($youtube===null)return null;
    $video=vp3_casual_checkin_youtube_choice_v1($pdo,$scope,$pin['youtube']??null);
    if($pin!==null&&($pin['music']??null)!==($music['evidence']['invitation_pin']??null))return null;
    $savedVideo=isset($video['evidence']['invitation']);
    if($pin!==null&&($pin['youtube']??null)!==($savedVideo?$video['evidence']['invitation_pin']:null))return null;
    $presentation=$music['evidence']['invitation'];
    $presentation['kind']='media';
    if($savedVideo){$presentation['kind']='media';$presentation['items'][]=$video['evidence']['invitation']['items'][0];}
    return ['title'=>'Music or a video','summary'=>'Named choices from your current permitted Media sources.',
        'question'=>$savedVideo?'Would you like to listen to the track or watch the saved video?':'Want to hear this track, or find something new on YouTube?',
        'identity'=>[$music['identity'],$savedVideo?$video['identity']:$youtube['identity']],
        'evidence'=>array_replace($music['evidence'],$savedVideo?array_intersect_key($video['evidence'],array_flip(['history_playback','history_topic'])):[],
            ['invitation'=>$presentation,'invitation_pin'=>['music'=>$music['evidence']['invitation_pin'],'youtube'=>$savedVideo?$video['evidence']['invitation_pin']:null],
                'invitation_default'=>$savedVideo?null:'music'])];
}

function vp3_casual_checkin_music_v1(PDO $pdo,array $scope,?int $trackId,?array $pin=null): ?array
{
    require_once __DIR__.'/agent-media-playback-v1.php';
    $user=$scope['user'];
    if($pin!==null){
        if(array_keys($pin)!==['track_id','revision']||!is_int($pin['track_id'])||$pin['track_id']<1
            ||!is_string($pin['revision']))return null;
        $trackId=$pin['track_id'];
    }
    $track=$trackId===null
        ?vp3_media_native_pick_v1($pdo,$user,$scope['agent_id'],vp3_media_native_taste_v1($pdo,(int)$user['id']))
        :vp3_media_native_track_v1($pdo,$user,$scope['agent_id'],$trackId);
    if($track===null)return null;
    $nextPin=['track_id'=>$track['track_id'],'revision'=>$track['revision']];
    if($pin!==null&&$pin!==$nextPin)return null;
    $title=vp3_specialist_invitation_text_v1($track['title'],100);
    $artist=vp3_specialist_invitation_text_v1($track['artist']??'',80);
    $genre=vp3_specialist_invitation_text_v1($track['genre']??'',65);
    $detail='VP3 Music Library'.($artist===''?'':' · '.$artist).($genre===''?'':' · '.$genre);
    return ['title'=>'Music','summary'=>'A named track from your current VP3 Music Library.',
        'question'=>'How about listening to this track?',
        'url'=>url('/music-library.php'),
        'identity'=>[$track['track_id'],$track['revision']],
        'evidence'=>['track_id'=>$track['track_id'],'track_revision'=>$track['revision'],'source_title'=>$track['title'],
            'invitation'=>['kind'=>'music','items'=>[['title'=>$title,'detail'=>vp3_specialist_invitation_text_v1($detail,180)]],'context'=>''],
            'invitation_pin'=>$nextPin]];
}

/** Saved YouTube evidence is optional and remains subject to its existing history consent. */
function vp3_casual_checkin_youtube_choice_v1(PDO $pdo,array $scope,?array $pin=null): ?array
{
    $available=vp3_casual_checkin_youtube_v1($pdo,(int)$scope['user']['id']);
    if($available===null)return null;
    require_once __DIR__.'/agent-idle-media-history-v1.php';
    $choice=vp3_idle_media_history_choice_v1($pdo,$scope,time(),$pin,null);
    if($pin!==null&&$choice===null)return null;
    if($choice===null)return $available;
    $choice['evidence']['invitation_pin']=$choice['evidence']['history_pin'];
    $choice['evidence']['invitation']=['kind'=>'youtube','items'=>[['title'=>vp3_specialist_invitation_text_v1($choice['title'],100),
        'detail'=>vp3_specialist_invitation_text_v1($choice['summary'],180)]],'context'=>''];
    return $choice;
}

function vp3_casual_checkin_youtube_v1(PDO $pdo,int $owner): ?array
{
    require_once __DIR__.'/agent-connection-youtube-chat-v1.php';
    if(!agent_connection_youtube_ready_v1()||!agent_connection_youtube_consent_v1($pdo,$owner)
        ||!agent_connection_youtube_results_ready_v1($pdo)||!agent_connection_youtube_quota_ready_v1($pdo))return null;
    $query=$pdo->prepare('SELECT owner_user_id,attempts FROM agent_connection_youtube_usage_v1
        WHERE quota_day=? AND owner_user_id IN (0,?)');
    $query->execute([agent_connection_youtube_clock_v1()->format('Y-m-d'),$owner]);
    $attempts=$query->fetchAll(PDO::FETCH_KEY_PAIR);
    if((int)($attempts[0]??0)>=VP3_YOUTUBE_SITE_SEARCH_LIMIT_V1
        ||(int)($attempts[$owner]??0)>=VP3_YOUTUBE_MEMBER_SEARCH_LIMIT_V1)return null;
    return ['title'=>'YouTube','summary'=>'YouTube search is available in this Chat.',
        'identity'=>[VP3_YOUTUBE_CONSENT_POLICY_V1]];
}

function vp3_casual_checkin_email_v1(PDO $pdo,int $owner,?array $scope=null,array $binding=[],?array $pin=null): ?array
{
    if(!table_exists('agent_connection_email_drafts_v1'))return null;
    require_once __DIR__.'/agent-connections-v1.php';
    $status=agent_connections_summary_v1($owner,'gmail');
    $connection=$status['connection']??null;
    if(empty($status['provider_ready'])||($connection['status']??null)!=='connected'
        ||empty($connection['permissions']['send'])||empty($connection['revision']))return null;
    if($scope!==null){
        $drafts=vp3_specialist_invitation_email_v1($pdo,$scope,$binding,$pin);
        if($drafts['state']==='ready')return $drafts['choice'];
        if($drafts['state']!=='empty')return null;
    }
    return ['title'=>'Draft an email','summary'=>'Gmail drafting is available in this Chat.',
        'identity'=>[$connection['id'],$connection['revision']]];
}

function vp3_casual_checkin_today_v1(PDO $pdo,array $scope,DateTimeImmutable $now,?array $items,?string $sourceRef): ?array
{
    require_once __DIR__.'/agent-initiative-office-records-v1.php';
    $selected=$sourceRef===null?null:[substr($sourceRef,strlen('planning:'))];
    $items??=vp3_coordination_planning_sources_v1($pdo,$scope,$selected,$now)['items'];
    $userTimezone=$scope['user']['timezone']??null;
    $local=is_string($userTimezone)&&$userTimezone!==''&&in_array($userTimezone,DateTimeZone::listIdentifiers(DateTimeZone::ALL_WITH_BC),true)
        ?$now->setTimezone(new DateTimeZone($userTimezone)):vp3_initiative_task_local_time_v1($pdo,$scope,$now);
    $end=$local->modify('tomorrow')->setTime(0,0)->getTimestamp();
    $today=[];
    foreach($items as $item){
        if(($sourceRef!==null&&$item['ref']!==$sourceRef)||($item['specialist']??null)!=='planning'
            ||!in_array($item['status']??null,['ready','confirmed','rescheduled'],true)
            ||empty($item['start_at']))continue;
        $start=new DateTimeImmutable($item['start_at']);
        if($start<=$now||$start->getTimestamp()>=$end)continue;
        $today[]=['item'=>$item,'start'=>$start];
    }
    usort($today,static fn(array $a,array $b): int=>$a['start']<=>$b['start']?:strcmp($a['item']['ref'],$b['item']['ref']));
    if(!$today)return null;
    ['item'=>$item,'start'=>$start]=$today[0];
    $time=!empty($item['all_day'])?'It’s an all-day event.':'It starts at '.$start->setTimezone($local->getTimezone())->format('g:i A T').'.';
    return ['title'=>'Today’s schedule','summary'=>'There is an upcoming item on today’s schedule.',
        'url'=>$item['url'],
        'identity'=>[$item['ref'],$item['revision'],$time],
        'evidence'=>['source_ref'=>$item['ref'],'source_revision'=>$item['revision'],'source_title'=>$item['title'],
            'source_summary'=>$time,'source_url'=>$item['url']]];
}

function vp3_casual_checkin_resume_v1(PDO $pdo,array $scope,array $binding,DateTimeImmutable $now,?array $items,?string $sourceRef): ?array
{
    $selected=$sourceRef===null?null:[substr($sourceRef,strlen('office:'))];
    $items??=vp3_coordination_office_sources_v1($pdo,$scope,$binding,$selected,$now)['items'];
    foreach($items as $item){
        if(($sourceRef!==null&&$item['ref']!==$sourceRef)||!str_starts_with($item['ref'],'office:task:')||($item['status']??null)!=='ready'
            ||($item['priority']??null)==='high'||!empty($item['trigger'])||empty($item['next_step']))continue;
        $task=vp3_initiative_office_entry_source_v1($pdo,$scope,$binding,substr($item['ref'],strlen('office:')));
        $record=$task['record']??[];
        if(($record['status']??null)!=='in_progress'||!empty($record['assigned_to_someone_else']))continue;
        $url=str_contains($item['url'],'message_id=')?$item['url']:url('/account.php#agent-brain');
        return ['title'=>'Pick up an open task','summary'=>'An in-progress task has a verified next step.',
            'url'=>$url,
            'identity'=>[$item['ref'],$item['revision'],$url],
            'evidence'=>['source_ref'=>$item['ref'],'source_revision'=>$item['revision'],'source_title'=>$item['title'],
                'source_summary'=>$item['next_step'],'source_url'=>$url]];
    }
    return null;
}
