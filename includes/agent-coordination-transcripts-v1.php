<?php
declare(strict_types=1);

require_once __DIR__.'/agent-initiative-transcripts-v1.php';

const VP3_COORDINATION_TRANSCRIPT_RECENT_SECONDS_V1=86400;
const VP3_COORDINATION_TRANSCRIPT_INTERVAL_SECONDS_V1=600;
const VP3_COORDINATION_TRANSCRIPT_OPEN_INTERVAL_SECONDS_V1=1800;

/** Read current saved findings in the same owner and Agent scope as Transcripts. */
function vp3_coordination_transcripts_sources_v1(PDO $pdo,array $scope,array $binding,?array $refs,DateTimeImmutable $now): array
{
    $out=['items'=>[],'coverage'=>'ready'];
    if(!table_exists('artist_transcript_sessions_v172')||!table_exists('artist_transcript_segments_v172')
        ||!artist_listening_v237_schema_ready()){
        return ['items'=>[],'coverage'=>'unavailable','failure'=>'storage_unavailable'];
    }
    $scope=vp3_initiative_transcript_capabilities_scope_v1($pdo,$scope,$binding);
    $user=$scope['user'];$principal=$scope['principal'];
    if($refs===null){
        $scan=vp3_initiative_transcript_saved_scan_v1($pdo,$scope,$now,draftOnly:false);
        $ids=$scan['ids'];
        $focus=$scan['focus'];
        if($scan['limited'])$out['coverage']='limited';
    }else{
        $ids=array_map(static fn(string $ref):int=>vp3_initiative_transcript_capabilities_id_v1('transcript:'.$ref),$refs);
        $focus=vp3_transcript_focus_id_v1($pdo,(int)$user['id'],$scope['agent_id'],$scope['conversation_id'],$now);
    }
    $masterTime=null;
    foreach($ids as $id){
        try{
            $session=vp3_transcript_session_v1($pdo,$user,$principal,(int)$id);
            // Meeting-origin material stays with the separate workflow that can
            // refresh its processing proof outside this coordination transaction.
            if(video_meeting_transcription_readonly_binding_v1801($pdo,$user,$session)!==null){
                $out['coverage']='limited';
                continue;
            }
            vp3_initiative_transcript_capabilities_cloud_v1($pdo,$scope,$session);
            $saved=vp3_transcript_intelligence_v1($pdo,$user,$principal,(int)$id,'research_candidates');
        }catch(RuntimeException $error){
            if(!in_array($error->getCode(),[403,404,409],true))throw $error;
            if($refs===null)$out['coverage']='limited';
            continue;
        }
        if(empty($saved['fresh']))continue;
        $item=vp3_coordination_transcript_item_v1($session,$saved);
        if($item===null)continue;
        $generatedAt=$item['analysis_generated_at'];
        unset($item['analysis_generated_at']);
        if($generatedAt===0){
            require_once __DIR__.'/agent-initiative-runtime-v1.php';
            $epoch=vp3_initiative_db_wall_epoch_sql_v1($pdo,'generated_at');
            $masterTime??=$pdo->prepare("SELECT $epoch FROM artist_transcript_master_analysis_v237
                WHERE session_id=? AND source_hash=? AND generated_at=?");
            $masterTime->execute([$id,$saved['source_hash'],$saved['generated_at']]);
            $generatedAt=(int)$masterTime->fetchColumn();
        }
        $item['transcript_focused']=$id===$focus;
        $item['transcript_recent']=$generatedAt>0&&$generatedAt<=$now->getTimestamp()+5
            &&$generatedAt>$now->getTimestamp()-VP3_COORDINATION_TRANSCRIPT_RECENT_SECONDS_V1;
        $out['items'][$item['ref']]=$item;
    }
    return $out;
}

/** Choose one open, source-linked item. Explicitly reviewed findings come first. */
function vp3_coordination_transcript_item_v1(array $session,array $saved): ?array
{
    $candidates=array_filter($saved['research_candidates']??[],static fn(array $item):bool=>$item['kind']==='followup');
    uasort($candidates,static fn(array $a,array $b):int=>($b['review_state']==='accepted')<=>($a['review_state']==='accepted'));
    foreach($candidates as $item){
        $text=vp3_agent_activity_text_v1($item['question'],300);
        if($text==='')continue;
        $next=vp3_agent_activity_text_v1($item['next_step'],280);
        $section=$item['plugin'].'.'.$item['section'];
        $question=str_ends_with($text,'?')&&in_array($section,['basic.open_questions','qa.unanswered','summary_output.open_questions'],true);
        $summary=($question?'Open question in the saved transcript analysis — ':'Open follow-up in the saved transcript analysis — ').$text;
        $title=trim((string)$session['title'])?:'Saved transcription';
        $ref='transcript:'.(int)$session['id'];
        $result=vp3_coordination_source_item_v1($ref,'transcripts',$title,$summary,$next,
            $question?$text:'',$item['source_url'],'open','normal',[
                (int)$session['created_by_user_id'],(int)($session['conversation_id']??0),$session['status'],$title,
                $saved['source_hash'],$item['plugin'],$item['section'],$item['item_id'],
                $item['source_url'],$summary,$next,
            ]);
        $result['trigger']=$question?'open_question':'open_followup';
        $moduleTime=$item['analysis_generated_at']??null;
        $result['analysis_generated_at']=is_string($moduleTime)&&preg_match('/(?:Z|[+-]\d\d:\d\d)\z/D',$moduleTime)
            ?(strtotime($moduleTime)?:0):0;
        return $result;
    }
    return null;
}

function vp3_coordination_transcript_automatic_v1(array $goal): bool
{
    return ($goal['tool']??null)==='coordination.review'
        &&($goal['coordination_route']['specialist']??null)==='transcripts'
        &&!isset($goal['followthrough'])&&!isset($goal['followup'])&&!isset($goal['coordination_work']);
}

function vp3_coordination_transcript_handoff_hold_v1(PDO $pdo,array $user,array $binding,array $item,?bool &$paced=null): ?string
{
    if(!empty($item['transcript_focused']))return null;
    if(empty($item['transcript_recent']))return 'transcript_history';
    $paced??=vp3_coordination_transcript_recent_post_v1($pdo,$user,$binding);
    return $paced?'transcript_pacing':null;
}

/** Space automatic suggestions using the verified messages in this Agent's linked Chat. */
function vp3_coordination_transcript_recent_post_v1(PDO $pdo,array $user,array $binding): bool
{
    require_once __DIR__.'/agent-initiative-engagement-status-v1.php';
    require_once __DIR__.'/agent-coordination-work-v1.php';
    $now=time();$owner=(int)$user['id'];$cid=(int)$binding['conversation_id'];$agent=vp3_initiative_binding_agent_v1($binding);
    $epoch=vp3_initiative_db_wall_epoch_sql_v1($pdo,'m.created_at');
    $run=vp3_initiative_engagement_json_v1($pdo,'m.context_json','initiative_run_id');
    $family=vp3_initiative_engagement_json_v1($pdo,'r.goal','coordination_route.specialist');
    $source=vp3_initiative_engagement_json_v1($pdo,'m.context_json','source');
    $sqlite=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite';
    $integer=$sqlite?'INTEGER':'UNSIGNED';
    $started=$sqlite?"DATETIME(CAST(? AS INTEGER),'unixepoch')":'FROM_UNIXTIME(?)';
    $agentWhere=$agent>0?'r.agent_id=?':'r.agent_id IS NULL';
    $messages=$pdo->prepare("SELECT r.id,r.goal,r.status,r.source_key,m.id AS message_id,$epoch AS posted_at
        FROM chat_messages m JOIN agent_workflow_runs r ON r.id=CAST($run AS $integer)
        WHERE m.conversation_id=? AND m.role='assistant' AND m.user_id IS NULL AND $source='agent_initiative'
        AND m.created_at>=$started AND r.owner_user_id=? AND $agentWhere
        AND r.source_kind='agent_initiative' AND r.status='completed' AND r.capability_key='initiative.prepare'
        AND $family='transcripts' ORDER BY m.id DESC LIMIT 20");
    $messages->execute([$cid,$now-VP3_COORDINATION_TRANSCRIPT_OPEN_INTERVAL_SECONDS_V1,$owner,...($agent>0?[$agent]:[])]);
    foreach($messages->fetchAll(PDO::FETCH_ASSOC) as $row){
        $goal=json_decode((string)$row['goal'],true);
        if(!is_array($goal)||!vp3_coordination_transcript_automatic_v1($goal))continue;
        $result=vp3_coordination_verified_run_v1($pdo,$user,$row);
        if($result===null)continue;
        $post=vp3_coordination_chat_projection_v1($pdo,$user,$binding,$row,$result);
        if(($post['message_id']??0)!==(int)$row['message_id'])continue;
        if((int)$row['posted_at']>$now-VP3_COORDINATION_TRANSCRIPT_INTERVAL_SECONDS_V1
            ||(($post['reply_state']??null)==='open'&&($result['initiative_tool_result']['output']['state']??null)==='needs_owner')){
            return true;
        }
    }
    return false;
}
