<?php
declare(strict_types=1);

/** The active worker writes a single message, in the same transaction as the new draft and its receipt. */
function vp3_coding_canvas_chat_save_v1(PDO $pdo,array $user,int $agentId,int $cid,int $runId): ?int
{
    $owner=(int)$user['id'];
    $agent=$agentId>0?['id'=>$agentId]:null;
    if (!table_exists('chat_messages') || !vp3_agent_chat_conversation_v380($pdo,$cid,$owner,$agent,true)) return null;
    $context=json_encode(['source'=>'agent_coding_canvas','coding_canvas_run_id'=>$runId,'skip_brain_archive'=>true],JSON_THROW_ON_ERROR);
    $pdo->prepare("INSERT INTO chat_messages (conversation_id,user_id,role,message,context_json,created_at)
        VALUES (?,NULL,'assistant',?,?,CURRENT_TIMESTAMP)")->execute([$cid,'A private app draft is ready.',$context]);
    $messageId=(int)$pdo->lastInsertId();
    if (column_exists('chat_conversations','updated_at')) {
        $statement=$pdo->prepare('UPDATE chat_conversations SET updated_at=CURRENT_TIMESTAMP WHERE id=? AND user_id=?');
        $statement->execute([$cid,$owner]);
    }
    return $messageId;
}

/** A marker in chat_messages gives no authority to reveal a title or open an app. */
function vp3_coding_canvas_chat_card_v1(PDO $pdo,array $user,int $agentId,int $cid,int $runId,int $messageId,mixed &$voice=null): ?array
{
    $voice=null;
    require_once __DIR__.'/coding-canvas-jobs-v1.php';
    require_once __DIR__.'/agent-activity-scope-v1.php';
    try {
        $live=vp3_coding_canvas_scope_v1($pdo,$user,$agentId,$cid);
        $owner=(int)$live['id'];
        $agentScope=$agentId>0?'r.agent_id=?':'r.agent_id IS NULL';
        $statement=$pdo->prepare("SELECT r.goal,r.capability_key,a.result_json AS outcome,p.result_json AS proof,v.canvas_id,v.revision
            FROM agent_workflow_runs r
            JOIN agent_workflow_actions a ON a.run_id=r.id AND a.owner_user_id=r.owner_user_id
                AND a.sequence_no=1 AND a.action_key=r.source_key AND a.status='completed'
                AND a.execution_target='cloud' AND a.capability_key=r.capability_key
            JOIN agent_workflow_receipts p ON p.run_id=r.id AND p.action_id=a.id AND p.owner_user_id=r.owner_user_id
                AND p.attempt_no=a.attempt_count AND p.executor='cloud' AND p.status='completed'
            JOIN vp3_coding_canvas_versions_v1 v ON v.run_id=r.id
            JOIN vp3_coding_canvases_v1 c ON c.id=v.canvas_id AND c.owner_user_id=r.owner_user_id
            JOIN knowledge_items k ON k.id=v.code_item_id AND k.folder_id=c.folder_id AND k.created_by_user_id=c.owner_user_id
                AND k.knowledge_scope='personal' AND k.visibility='private' AND k.is_published=0 AND k.file_type='coding_canvas'
            WHERE r.id=? AND r.owner_user_id=? AND $agentScope AND r.source_kind='coding_canvas'
                AND r.capability_key IN ('coding.build','coding.repair') AND r.execution_target='cloud' AND r.status='completed'
            ORDER BY p.id DESC LIMIT 1");
        $statement->execute([$runId,$owner,...($agentId>0?[$agentId]:[])]);
        $proof=$statement->fetch(PDO::FETCH_ASSOC);
        if (!$proof || !is_string($proof['proof']) || $proof['proof']!==$proof['outcome']) return null;
        $goal=json_decode((string)$proof['goal'],true,16,JSON_THROW_ON_ERROR);
        $receipt=json_decode($proof['proof'],true,8,JSON_THROW_ON_ERROR);
        $canvasId=$goal['canvas_id']??null;
        $version=filter_var($receipt['canvas_revision']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
        if (($goal['conversation_id']??null)!==$cid || !is_string($canvasId)
            || ($receipt['canvas_id']??null)!==$canvasId || $version===false
            || $proof['canvas_id']!==$canvasId || (int)$proof['revision']!==$version
            || filter_var($receipt['canvas_message_id']??null,FILTER_VALIDATE_INT)!==$messageId) return null;
        if ($proof['capability_key']==='coding.repair') {
            require_once __DIR__.'/agent-initiative-canvas-v1.php';
            $target=vp3_initiative_canvas_target_v1($goal['initiative_origin']['target']??null);
            if (($goal['initiative_origin']['authorization']??null)!=='owner_followthrough'
                || !vp3_initiative_canvas_coding_matches_v1($goal,['target'=>$target])
                || $target['revision'] >= $version) return null;
        }
        vp3_coding_canvas_authority_v1($pdo,$live,$agentId);
        $canvas=vp3_coding_canvas_public_v1(vp3_coding_canvas_require_row_v1($pdo,$owner,$agentId,$canvasId));
        if ($canvas['conversation_id']!==$cid) return null;
        $status=$canvas['active_revision']===$version?'active':($canvas['draft_revision']===$version?'draft':'saved');
        $card=['version'=>1,'title'=>$canvas['title'],'canvas_id'=>$canvasId,'revision'=>$version,'run_id'=>$runId,
            'conversation_id'=>$cid,'agent_id'=>$agentId,'status'=>$status,
            'href'=>vp3_agent_activity_chat_href_v1($agentId,$cid).'&canvas_id='.rawurlencode($canvasId).'&canvas_revision='.$version];
        if (function_exists('vp3_coding_canvas_artwork_urls_v1')) {
            $card+=vp3_coding_canvas_artwork_urls_v1($pdo,$owner,$agentId,$canvasId,$version);
        }
        $voice=$receipt['initiative_voice']??null;
        return $card;
    } catch (RuntimeException | JsonException | InvalidArgumentException $error) {
        return null;
    }
}

function vp3_coding_canvas_chat_history_v1(PDO $pdo,array $user,int $agentId,int $cid,array $row,array $context): array
{
    $runId=$context['coding_canvas_run_id']??null;
    $messageId=filter_var($row['id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
    $card=is_int($runId)&&$runId>0&&$messageId!==false
        ?vp3_coding_canvas_chat_card_v1($pdo,$user,$agentId,$cid,$runId,$messageId):null;
    $row['message']=$card===null?'This app draft is unavailable.':'A private app draft is ready.';
    $safe=['source'=>'agent_coding_canvas','skip_brain_archive'=>true];
    if ($card!==null) $safe['coding_canvas_card_v1']=$card;
    $row['context_json']=json_encode($safe,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
    return $row;
}
