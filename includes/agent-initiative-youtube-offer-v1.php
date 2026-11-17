<?php
declare(strict_types=1);

require_once __DIR__.'/agent-connection-youtube-chat-v1.php';

const VP3_INITIATIVE_YOUTUBE_OFFER_V1 = 'youtube.playback_offer';

/** Authorize current settings and one saved result in this exact owner Chat. */
function vp3_initiative_youtube_offer_source_v1(PDO $pdo, int $owner, int $agent, int $conversation, array $record): ?array
{
    $payload=$record['results'];
    $kind=in_array($payload['suggestion_kind']??null,['artists','similar'],true)?'artist':'topic';
    if(($payload['initiative_suggestion']??false)===true||!vp3_youtube_research_enabled_v1($pdo,$owner,$kind))return null;
    foreach(agent_connection_youtube_ordered_videos_v1($payload['videos']) as $video){
        if(($video['made_for_kids']??null)!==false||(($payload['music_suggestion']??false)!==true&&($video['music_category']??false)!==true))continue;
        $sourceId=$record['message_id'];
        $ref='youtube-playback-offer:'.$sourceId;
        $revision=hash('sha256',json_encode([$owner,$agent,$conversation,$sourceId,$record['created_at_epoch'],$payload],JSON_THROW_ON_ERROR));
        return ['ref'=>$ref,'revision'=>$revision,'kind'=>'youtube_playback_offer','title'=>'Saved YouTube results',
            'url'=>url('/chat.php?agent='.($agent ?: 'system').'&conversation_id='.$conversation.'&message_id='.$sourceId),
            'message_id'=>$sourceId,'video'=>$video,'expires_at'=>$record['expires_at']];
    }
    return null;
}

function vp3_initiative_youtube_offer_enabled_v1(PDO $pdo,int $owner): bool
{
    return vp3_youtube_picks_ready_v1($pdo)&&(int)vp3_youtube_picks_settings_v1($pdo,$owner)['proactive']===1;
}

function vp3_initiative_youtube_offer_evidence_v1(PDO $pdo,array $user,array $binding,array $goal): array
{
    $ref=$goal['primary']??null;
    $revisions=$goal['sources']??null;
    $owner=(int)$user['id'];$agent=vp3_initiative_binding_agent_v1($binding);$conversation=$binding['conversation_id'];
    if(($goal['tool']??null)!==VP3_INITIATIVE_YOUTUBE_OFFER_V1||($goal['report']??null)!==true||isset($goal['followthrough'])
        ||isset($goal['followup'])||!is_string($ref)||!preg_match('/\Ayoutube-playback-offer:([1-9][0-9]{0,15})\z/D',$ref,$match)
        ||!is_array($revisions)||array_keys($revisions)!==[$ref]||!has_permission('account.access',$user)
        ||!vp3_initiative_youtube_offer_enabled_v1($pdo,$owner)){
        throw new Vp3InitiativeSourcesChangedV1();
    }
    $records=agent_connection_youtube_result_records_v1($pdo,$owner,$agent,$conversation,1,[(int)$match[1]]);
    $source=$records?vp3_initiative_youtube_offer_source_v1($pdo,$owner,$agent,$conversation,$records[0]):null;
    if($source===null||$source['revision']!==$revisions[$ref])throw new Vp3InitiativeSourcesChangedV1();
    return [$ref=>$source];
}

function vp3_initiative_youtube_offer_result_v1(array $source): array
{
    return ['draft'=>'I found a music pick in your recent YouTube results. Want me to play it here?',
        'output'=>['type'=>'initiative_youtube_offer','source_message_id'=>$source['message_id'],
            'video_id'=>$source['video']['video_id'],'source_revision'=>$source['revision']]];
}

function vp3_initiative_youtube_offer_normalize_v1(array $value): ?array
{
    $output=$value['output']??null;
    if(!is_array($output)||($output['type']??null)!=='initiative_youtube_offer'
        ||!is_int($output['source_message_id']??null)||$output['source_message_id']<1
        ||!is_string($output['video_id']??null)||!preg_match('/\A[A-Za-z0-9_-]{11}\z/D',$output['video_id'])
        ||!is_string($output['source_revision']??null)||!preg_match('/\A[a-f0-9]{64}\z/D',$output['source_revision']))return null;
    $canonical=vp3_initiative_youtube_offer_result_v1(['message_id'=>$output['source_message_id'],
        'video'=>['video_id'=>$output['video_id']],'revision'=>$output['source_revision']]);
    if(!array_key_exists('draft',$value))unset($canonical['draft']);
    elseif($value['draft']!==$canonical['draft'])return null;
    return $canonical;
}

/** Live verification uses the exact canonical Initiative post and original expiring result. */
function vp3_initiative_youtube_offer_post_v1(PDO $pdo,array $user,int $agent,int $conversation,int $message): ?array
{
    require_once __DIR__.'/agent-initiative-followthrough-v1.php';
    try{
        $principal=vp3_initiative_principal_v1($pdo,$user,$agent);
        $origin=vp3_initiative_followthrough_origin_load_v1($pdo,$user,$principal,$conversation,$message);
        $goal=$origin['goal'];$work=$origin['result']['initiative_tool_result'];
        $evidence=vp3_initiative_youtube_offer_evidence_v1($pdo,$origin['user'],$goal['binding'],$goal);
        $source=$evidence[$goal['primary']];
        return $work===vp3_initiative_youtube_offer_result_v1($source)?$source:null;
    }catch(Throwable $error){return null;}
}

function vp3_initiative_youtube_offer_playback_v1(array $offer,bool $play): array
{
    return ['query'=>'YouTube music pick','expires_at'=>$offer['expires_at'],'videos'=>[$offer['video']],
        'source_message_id'=>$offer['message_id'],'selected_video_id'=>$offer['video']['video_id'],
        'play_selected'=>$play,'initiative_suggestion'=>true];
}

function vp3_initiative_youtube_offer_message_v1(array $offer): string
{
    $text=static fn(mixed $value,int $limit):string=>is_string($value)
        ?mb_substr(trim(preg_replace('/[<>\p{Cc}\p{Cf}\p{Zl}\p{Zp}]+/u',' ',strip_tags($value))??''),0,$limit):'';
    $title=$text($offer['video']['title']??null,160);
    $channel=$text($offer['video']['channel_title']??null,100);
    if($title==='')return vp3_initiative_youtube_offer_result_v1($offer)['draft'];
    return 'I found “'.$title.'”'.($channel===''?'':' from '.$channel).' in your recent YouTube results. Want me to play it here?';
}
