<?php
declare(strict_types=1);
require_once __DIR__.'/transcript-brain-v1.php';
require_once __DIR__.'/agent-reminders-v1.php';

function vp3_transcript_reviewed_action_v1(PDO $pdo,array $user,array $principal,int $id,array $plan,array $context=[]): array
{
    $s=vp3_transcript_session_v1($pdo,$user,$principal,$id);$op=$plan['operation'];
    if($op==='highlight')return vp3_transcript_highlight_v1($pdo,$user,$id,(string)($plan['query']??$context['transcript_selected_text']??''),$principal);
    $load=function()use($pdo,$user,$s,$id){$map=artist_listening_transcript_page_map(artist_listening_v172_segments($pdo,$id));$st=artist_listening_v237_analysis_status($pdo,$id,$map);$m=$st['master'];if(!$m||empty($m['fresh']))throw new RuntimeException('Refresh the stale transcript analysis before reviewing or acting on it.');return $m;};
    $plugin=(string)($plan['plugin']??'');$item=(string)($plan['item_id']??'');if($plugin===''||$item==='')throw new RuntimeException('Choose an exact finding and its analysis module first.');
    if($op==='item_action'&&($plan['action']??'')==='reminder'){
        $m=$load();$snap=transcription_deeper_item_snapshot_v307($m,$plugin,$item);transcription_intelligence_require_accepted_v303($snap['item']);
        $schedule=$plan['schedule']??null;if(!is_array($schedule))throw new RuntimeException('What time and repeat schedule should this reminder use?');
        $schedule['title']=mb_substr($snap['text'],0,240);reminder_schedule_v1($schedule,reminder_now_v1());
        $canonical=json_encode(['transcript'=>$id,'item'=>$item,'schedule'=>$schedule],JSON_THROW_ON_ERROR);$key='transcript:'.hash('sha256',$canonical);
        // The existing reminder ledger provides atomic schedule creation and replay protection.
        return reminder_execute_v1($pdo,(int)$user['id'],'transcript:'.$id,$key,$canonical,['operation'=>'create','schedule'=>$schedule],[],(int)($context['conversation_id']??0));
    }
    $pdo->beginTransaction();try{
        $q=$pdo->prepare('SELECT session_id FROM artist_transcript_master_analysis_v237 WHERE session_id=?'.($pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?' FOR UPDATE':''));$q->execute([$id]);if(!$q->fetchColumn())throw new RuntimeException('Analyze the transcript before reviewing findings.');
        $m=$load();$snapshot=transcription_deeper_item_snapshot_v307($m,$plugin,$item);
        if($op==='review_item'){$state=(string)($plan['review_state']??'');$m=transcription_deeper_review_item_v307($pdo,$id,$m,$plugin,$item,$state);$r=['reviewed'=>true,'item_id'=>$item,'review_state'=>$state];}
        else{
            $action=(string)($plan['action']??'');if(!in_array($action,['main_chat','agent_brain','agent_task','personal_knowledge','project_note','crm_note','crm_task'],true))throw new RuntimeException('Choose a supported reviewed action.');
            transcription_intelligence_require_accepted_v303($snapshot['item']);
            $r=vp3_transcript_brain_scope_v1($pdo,$user,$s,static fn()=>transcription_deeper_execute_action_v307($pdo,$user,$s,$m,$plugin,$item,$action,(int)($plan['target_id']??0)),$principal);
        }
        $pdo->commit();return $r;
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}
function vp3_transcript_action_chat_v1(PDO $pdo,array $user,array $principal,array $plan,int $id,array $context): array
{
    $r=vp3_transcript_reviewed_action_v1($pdo,$user,$principal,$id,$plan,$context);
    if(isset($r['tool_receipt']))return $r; // Canonical reminder executor owns its own receipt.
    $op=$plan['operation'];$label=$op==='highlight'?'Saved the transcript highlight to Agent Brain. Profile Agent use follows Knowledge Access settings.':($op==='review_item'?'Updated that finding’s review state to '.$r['review_state'].'.':(!empty($r['existing'])?'That reviewed action was already completed.':(string)($r['receipt']['label']??'Reviewed action completed').'.'));
    return ['handled'=>true,'answer'=>$label,'actions'=>[],'sources'=>[['source'=>'transcript:'.$id,'title'=>'Transcript #'.$id,'url'=>url('/artist-listening.php?session='.$id)]],'tool_receipt'=>vp3_tool_receipt_v1('transcripts.highlight',$op,'completed','',(int)($r['memory_id']??$r['receipt']['record_id']??$id),1)];
}
