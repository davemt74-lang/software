<?php
declare(strict_types=1);

require_once __DIR__.'/agent-coordination-sources-v1.php';
require_once __DIR__.'/agent-coordination-work-v1.php';
require_once __DIR__.'/agent-initiative-followthrough-v1.php';
require_once __DIR__.'/agent-initiative-engagement-feedback-v1.php';
require_once __DIR__.'/agent-job-engine-v1900.php';

/** Use the original offer, rather than whichever pick is currently ranked first. */
function vp3_media_offer_feedback_v1(PDO $pdo,array $user,array $binding,array $item): ?array
{
    if ((int)($binding['conversation_id']??0)<1 || vp3_participant_scope_v1()!==null
        || !agent_job_engine_schema_ready_v1900($pdo)) return null;
    $id=vp3_coordination_scan_id_v1($user,$binding,$item['ref']);
    foreach(vp3_coordination_runs_v1($pdo,$user,$binding,$id,12) as $run){
        $row=vp3_media_offer_feedback_from_run_v1($pdo,$user,$binding,$run,$item);
        if($row!==null)return array_intersect_key($row,['state'=>true,'message_id'=>true,'href'=>true]);
    }
    return null;
}

/** Keep recent responses visible when playback advances the resume or YouTube source. */
function vp3_media_recent_offer_feedback_v1(PDO $pdo,array $user,array $binding): array
{
    if ((int)($binding['conversation_id']??0)<1 || vp3_participant_scope_v1()!==null
        || !agent_job_engine_schema_ready_v1900($pdo)) return [];
    $rows=[];
    foreach(vp3_coordination_runs_v1($pdo,$user,$binding,limit:40) as $run){
        $row=vp3_media_offer_feedback_from_run_v1($pdo,$user,$binding,$run);
        if($row!==null)$rows[$row['message_id']]=$row;
        if(count($rows)>=6)break;
    }
    krsort($rows,SORT_NUMERIC);
    return array_values($rows);
}

function vp3_media_offer_feedback_from_run_v1(PDO $pdo,array $user,array $binding,array $run,?array $item=null): ?array
{
    $goal=json_decode((string)$run['goal'],true);
    $primary=$goal['primary']??'';
    $route=$goal['coordination_route']??[];
    $casual=($route['specialist']??null)==='casual'
        &&in_array($primary,['casual:media','casual:music','casual:youtube','casual:youtube_history'],true);
    if(($route['action']??null)!=='ask'||(!$casual&&($route['specialist']??null)!=='media')
        ||isset($goal['followthrough'])||isset($goal['coordination_work']))return null;
    if($item!==null&&($item['ref']!==$primary||($goal['sources'][$primary]??null)!==$item['revision']))return null;
    $result=vp3_coordination_verified_run_v1($pdo,$user,$run);
    $work=$result['initiative_tool_result']??null;
    if(($work['output']['state']??null)!=='needs_owner')return null;
    $post=vp3_coordination_chat_projection_v1($pdo,$user,$binding,$run,$result);
    if($post===null)return null;

    $agent=vp3_initiative_binding_agent_v1($binding);$cid=$binding['conversation_id'];
    try{
        $principal=vp3_initiative_principal_v1($pdo,$user,$agent);
        $origin=vp3_initiative_followthrough_origin_load_v1($pdo,$user,$principal,$cid,$post['message_id']);
        if((int)$origin['run']['id']!==(int)$run['id'])return null;
        if($item===null){
            try{$item=vp3_coordination_result_evidence_v1($pdo,$origin['user'],$goal['binding'],$goal,$work)[$primary];}
            catch(Vp3InitiativeSourcesChangedV1|Vp3InitiativeSourceProofUnavailableV1 $error){}
        }
    }catch(RuntimeException|JsonException $error){
        if($error instanceof PDOException)throw $error;
        return null;
    }
    $state=$origin['state'];
    $replyContext=[];
    $assistantId=0;
    $live=$origin['user'];$owner=(int)$live['id'];
    if($state['state']==='open'){
        if($item===null)return null;
        $status='open';
    }else{
        if(!vp3_initiative_engagement_reply_verified_v1($pdo,$live,$cid,$post['message_id'],$state))return null;
        $read=$pdo->prepare("SELECT id,context_json FROM chat_messages WHERE conversation_id=? AND id>? AND role='assistant' AND user_id IS NULL ORDER BY id LIMIT 1");
        $priorDecision=vp3_initiative_decision_reply_v1($state);
        $read->execute([$cid,$priorDecision['message_id']]);
        $reply=$read->fetch(PDO::FETCH_ASSOC);
        $saved=json_decode((string)($reply['context_json']??''),true);
        $outcome=array_replace(vp3_initiative_followthrough_outcome_v1($origin),[
            'reply_message_id'=>$priorDecision['message_id'],'revision'=>$priorDecision['revision'],
        ]);
        if(($saved['chat_user_message_id']??null)===$outcome['reply_message_id']
            &&($saved['initiative_followthrough']??null)===$outcome){
            $replyContext=$saved;
            $assistantId=(int)$reply['id'];
        }
        $status=match($state['state']){
            'completed'=>'play_requested','owner_resolved'=>'accepted','declined'=>'declined','deferred'=>'deferred','stale'=>'stale',default=>null,
        };
        if($status===null)return null;
    }
    $decision=$replyContext['media_offer_decision_v1']??null;
    $videoId=$decision['video_id']??null;
    $trackId=$decision['track_id']??null;
    $title=$item!==null?$work['output']['title']:'Media suggestion';
    $youtube=str_starts_with($primary,'media:youtube:')||$primary==='casual:youtube_history'||($decision['kind']??null)==='youtube';
    if($youtube){
        require_once __DIR__.'/agent-connection-youtube-playback-v1.php';
        if(!agent_connection_youtube_enabled_v1()||!agent_connection_youtube_consent_v1($pdo,$owner))return null;
        if($item===null)$title='YouTube suggestion';
        if(($decision['kind']??null)==='youtube'&&$assistantId>0&&is_string($videoId)
            &&preg_match('/\A[A-Za-z0-9_-]{11}\z/D',$videoId)){
            $record=agent_connection_youtube_result_records_v1($pdo,$owner,$agent,$cid,1,[$assistantId])[0]??null;
            $video=$record?vp3_youtube_history_video_v1($record['results'],$videoId):null;
            if($video){
                $title=vp3_agent_activity_text_v1($video['title']??'',160)?:$title;
                $status='play_requested';
                if(vp3_youtube_playback_ready_v1($pdo)&&vp3_youtube_capture_key_v1($pdo,$owner)!==''){
                    $read=$pdo->prepare('SELECT 1 FROM vp3_youtube_playback_v1 WHERE owner_user_id=? AND message_id=? AND video_id=? LIMIT 1');
                    $read->execute([$owner,$assistantId,$videoId]);
                    if($read->fetchColumn()!==false)$status='played';
                }
            }
        }
    }elseif(($decision['kind']??null)==='native'&&is_int($trackId)&&$trackId>0){
        require_once __DIR__.'/agent-media-playback-v1.php';
        if(!vp3_media_playback_history_allowed_v1($pdo,$live,$principal)&&str_starts_with($primary,'media:resume:'))return null;
        $track=vp3_media_playable_track_v1($pdo,$live,$trackId);
        if($track===null)return null;
        $title=vp3_agent_activity_text_v1($track['title']??'',160)?:$title;
        $status='play_requested';
        require_once __DIR__.'/agent-media-offer-native-receipt-v1.php';
        if(vp3_media_offer_native_played_v1($pdo,$origin,$replyContext))$status='played';
    }elseif(($decision['kind']??null)==='stoneward'&&$status==='accepted'){
        require_once __DIR__.'/agent-media-stoneward-playback-v1.php';
        if(vp3_stoneward_playback_offer_origin_v1($origin)){
            $status=vp3_stoneward_playback_offer_played_v1($pdo,$origin)?'played':'play_requested';
        }
    }elseif($item===null&&str_starts_with($primary,'media:resume:')){
        return null;
    }
    return ['title'=>$title,'state'=>$status,'message_id'=>$post['message_id'],'href'=>$item===null?null:$post['url']];
}
