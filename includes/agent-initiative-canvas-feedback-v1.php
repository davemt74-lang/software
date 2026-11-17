<?php
declare(strict_types=1);

require_once __DIR__.'/agent-coordination-canvas-v1.php';

/** A reviewed app record can suggest an update only in the app's private Chat. */
function vp3_initiative_canvas_feedback_target_v1(PDO $pdo,array $question): ?array
{
    $goal=$question['goal'];
    $ref=$goal['primary']??null;
    if(($goal['tool']??null)!=='coordination.review'||($goal['coordination_route']['specialist']??null)!=='canvas'
        ||($goal['coordination_route']['action']??null)!=='ask'||!is_string($ref)||!str_starts_with($ref,'canvas:'))return null;
    $revisions=vp3_coordination_saved_revisions_v1($goal,$question['result']['initiative_tool_result']??[]);
    $target=vp3_coordination_canvas_feedback_target_v1($pdo,$question['user'],$question['agent_id'],$ref,$revisions[$ref]);
    return ($target['conversation_id']??null)===$question['conversation_id']?$target:null;
}

/** Exact selection only. The private record's title and values are never sent to the Coding Agent by this source. */
function vp3_initiative_canvas_feedback_source_v1(PDO $pdo,array $user,array $principal,int $messageId): ?array
{
    require_once __DIR__.'/agent-initiative-followthrough-v1.php';
    if(vp3_participant_scope_v1()!==null)return null;
    $lock=$pdo->inTransaction()&&$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)!=='sqlite'?' FOR UPDATE':'';
    $read=$pdo->prepare("SELECT conversation_id,message,context_json FROM chat_messages
        WHERE id=? AND user_id=? AND role='user'".$lock);
    $read->execute([$messageId,(int)$user['id']]);
    $row=$read->fetch(PDO::FETCH_ASSOC);
    $context=$row?json_decode((string)$row['context_json'],true):null;
    if(($context['canvas_improvement_reply_v1']??null)!==true)return null;
    $cid=(int)$row['conversation_id'];
    try{
        $proof=vp3_initiative_followthrough_anchor_identity_v1($pdo,$user,$principal,$cid,$messageId,allowPreservedDecision:true);
        $question=$proof['loaded']??null;
        $decision=vp3_initiative_decision_reply_v1($question['state']??[]);
        if(($question['state']['canvas_improvement_requested']??null)!==true
            ||$decision['message_id']!==$messageId)return null;
        $target=vp3_initiative_canvas_feedback_target_v1($pdo,$question);
        if($target===null)return null;
        $app=vp3_coding_canvas_require_row_v1($pdo,(int)$user['id'],$question['agent_id'],$target['canvas_id']);
    }catch(RuntimeException $error){
        if($error instanceof PDOException||!in_array($error->getCode(),[403,404,409],true))throw $error;
        return null;
    }
    $feedback=vp3_initiative_opportunity_text_v1($row['message'],1200);
    if($feedback==='')return null;
    $title=vp3_initiative_opportunity_text_v1($app['title'],170);
    $ref='canvas-feedback:'.$messageId;$record=$question['goal']['primary'];
    $revision=hash('sha256',json_encode([
        $ref,$proof['anchor']['query_hash'],$question['state']['reply_ref'],$decision['revision'],
        $record,$question['goal']['sources'][$record],$target,$title,
    ],JSON_THROW_ON_ERROR));
    return ['ref'=>$ref,'kind'=>'canvas_improvement','title'=>$title,
        'detail'=>'Owner feedback on this app: '.$feedback,
        'url'=>vp3_agent_activity_chat_href_v1($question['agent_id'],$cid).'&message_id='.$messageId,
        'revision'=>$revision,'canvas_target'=>$target];
}

/** Save the source marker and admit one proposal in the same owner decision transaction. */
function vp3_initiative_canvas_feedback_enqueue_v1(PDO $pdo,array $question,array $binding,int $messageId): void
{
    if(!$pdo->inTransaction())throw new LogicException('Canvas feedback must be saved with its owner decision.');
    require_once __DIR__.'/agent-initiative-opportunity-sources-v1.php';
    $owner=(int)$question['user']['id'];$cid=$question['conversation_id'];
    $read=$pdo->prepare("SELECT context_json FROM chat_messages WHERE id=? AND conversation_id=? AND user_id=? AND role='user'");
    $read->execute([$messageId,$cid,$owner]);
    $context=json_decode((string)$read->fetchColumn(),true,32,JSON_THROW_ON_ERROR);
    if(!is_array($context['initiative_reply_anchor_v1']??null))throw new RuntimeException('The Canvas feedback could not be verified.',409);
    $context['canvas_improvement_reply_v1']=true;
    $context['skip_brain_archive']=true;
    $pdo->prepare('UPDATE chat_messages SET context_json=? WHERE id=? AND conversation_id=? AND user_id=?')
        ->execute([json_encode($context,JSON_THROW_ON_ERROR),$messageId,$cid,$owner]);
    $source=vp3_initiative_canvas_feedback_source_v1($pdo,$question['user'],$question['principal'],$messageId);
    if($source===null)throw new RuntimeException('The Canvas feedback could not be verified.',409);
    $ref=$source['ref'];
    $key=hash('sha256',json_encode([$binding,$ref,$source['revision']],JSON_THROW_ON_ERROR));
    vp3_initiative_enqueue_v1($pdo,$question['user'],'initiative.prepare',[
        'binding'=>$binding,'sources'=>[$ref=>$source['revision']],'tool'=>'canvas.prepare','primary'=>$ref,
        'focus'=>'Propose one private improvement to this exact Canvas in response to the owner’s feedback.',
        'report'=>true,'opportunity_lane'=>'app_suggestions',
    ],$key);
}
