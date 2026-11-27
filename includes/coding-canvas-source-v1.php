<?php
declare(strict_types=1);

require_once __DIR__.'/coding-canvas-store-v1.php';
require_once __DIR__.'/agent-job-engine-v1900.php';

/** Explicit Database Upgrade only; keep manual provenance in the canonical version store. */
function vp3_coding_canvas_source_schema_v1(PDO $pdo): void
{
    foreach (['edit_base_revision'=>'INT UNSIGNED NULL','edit_origin_revision'=>'INT UNSIGNED NULL',
        'edit_request_id'=>'CHAR(36) NULL','edit_request_hash'=>'CHAR(64) NULL'] as $column=>$type) {
        if (schema_metadata_column_exists($pdo,'vp3_coding_canvas_versions_v1',$column)) continue;
        try { $pdo->exec('ALTER TABLE vp3_coding_canvas_versions_v1 ADD COLUMN '.$column.' '.$type); }
        catch (PDOException $error) {
            if (!schema_metadata_column_exists($pdo,'vp3_coding_canvas_versions_v1',$column)) throw $error;
        }
    }
    if (!vp3_coding_canvas_source_index_v1($pdo)) {
        try { $pdo->exec('CREATE UNIQUE INDEX vp3_canvas_edit_request_v1 ON vp3_coding_canvas_versions_v1(canvas_id,edit_request_id)'); }
        catch (PDOException $error) { if (!vp3_coding_canvas_source_index_v1($pdo)) throw $error; }
    }
}

function vp3_coding_canvas_source_index_v1(PDO $pdo): bool
{
    if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite') {
        return in_array('vp3_canvas_edit_request_v1',array_column($pdo->query('PRAGMA index_list(vp3_coding_canvas_versions_v1)')->fetchAll(PDO::FETCH_ASSOC),'name'),true);
    }
    return (bool)$pdo->query("SHOW INDEX FROM vp3_coding_canvas_versions_v1 WHERE Key_name='vp3_canvas_edit_request_v1'")->fetch();
}

function vp3_coding_canvas_source_ready_v1(PDO $pdo): bool
{
    foreach (['edit_base_revision','edit_origin_revision','edit_request_id','edit_request_hash'] as $column) {
        if (!schema_metadata_column_exists($pdo,'vp3_coding_canvas_versions_v1',$column)) return false;
    }
    return vp3_coding_canvas_source_index_v1($pdo);
}

/** Byte ranges are checked against the exact immutable saved source, including Unicode. */
function vp3_coding_canvas_edit_context_v1(mixed $context, ?array $source = null): ?array
{
    if ($context===null) return null;
    if (!is_array($context) || count($context)!==4 || !in_array($context['language']??null,['html','css','js'],true)
        || !is_int($context['start_byte']??null) || !is_int($context['end_byte']??null)
        || $context['start_byte']<0 || $context['end_byte']<=$context['start_byte']
        || $context['end_byte']-$context['start_byte']>8000 || $context['end_byte']>32000
        || !is_string($context['source_hash']??null) || !preg_match('/\A[a-f0-9]{64}\z/D',$context['source_hash'])) {
        throw new InvalidArgumentException('Select a saved source range within the size limit.',422);
    }
    if ($source!==null) {
        $text=$source['artifact'][$context['language']];
        if (!hash_equals($source['source_hash'],$context['source_hash']) || $context['end_byte']>strlen($text)
            || !preg_match('//u',substr($text,0,$context['start_byte']))
            || !preg_match('//u',substr($text,$context['start_byte'],$context['end_byte']-$context['start_byte']))) {
            throw new RuntimeException('The selected source changed. Select the saved code again.',409);
        }
    }
    return ['language'=>$context['language'],'start_byte'=>$context['start_byte'],'end_byte'=>$context['end_byte'],'source_hash'=>$context['source_hash']];
}

/** Owner-authored edits use no model, never activate code and never replace app records. */
function vp3_coding_canvas_source_save_v1(PDO $pdo,array $user,int $agentId,int $cid,string $id,int $base,
    string $sourceHash,string $requestId,array $artifact,?int $expectedDraft,?int $expectedActive): array
{
    $agent=vp3_coding_canvas_authority_v1($pdo,$user,$agentId,true);
    if (!vp3_coding_canvas_source_ready_v1($pdo)) throw new RuntimeException('Run Database Upgrade to enable source editing.',503);
    if ($cid<1 || !vp3_agent_chat_conversation_v380($pdo,$cid,(int)$user['id'],$agent)) throw new RuntimeException('Conversation not found for this Agent.',404);
    if ($base<1 || !preg_match('/\A[a-f0-9]{64}\z/D',$sourceHash)
        || ($expectedDraft!==null&&$expectedDraft<1) || ($expectedActive!==null&&$expectedActive<1)) throw new InvalidArgumentException('Open the saved source again.',422);
    $id=vp3_coding_canvas_id_v1($id);$requestId=vp3_coding_canvas_id_v1($requestId);
    $artifact=vp3_coding_canvas_artifact_v1($artifact);
    $owner=(int)$user['id'];
    return vp3_coding_canvas_atomic_v1($pdo,$owner,static function()use($pdo,$user,$agentId,$cid,$id,$base,$sourceHash,$requestId,$artifact,$expectedDraft,$expectedActive,$owner):array{
        $row=vp3_coding_canvas_require_row_v1($pdo,$owner,$agentId,$id,true);
        $selected=vp3_coding_canvas_details_v1($pdo,$row,$base);
        if (!hash_equals($selected['revision']['source_hash'],$sourceHash)) throw new RuntimeException('The saved source changed. Reopen this version.',409);
        $hash=hash('sha256',json_encode([$cid,$base,$sourceHash,$expectedDraft,$expectedActive,$artifact,$selected['revision']['commands']],JSON_THROW_ON_ERROR));
        $q=$pdo->prepare('SELECT revision,edit_request_hash FROM vp3_coding_canvas_versions_v1 WHERE canvas_id=? AND edit_request_id=?');
        $q->execute([$id,$requestId]);$previous=$q->fetch(PDO::FETCH_ASSOC);
        if ($previous) {
            if (!hash_equals((string)$previous['edit_request_hash'],$hash)) throw new RuntimeException('This save request already has different source edits.',409);
            return vp3_coding_canvas_details_v1($pdo,$row,(int)$previous['revision'])+['source_saved'=>true];
        }
        if ($selected['canvas']['draft_revision']!==$expectedDraft || $selected['canvas']['active_revision']!==$expectedActive) {
            throw new RuntimeException('This app has a newer draft or working version. Your edits were kept; reopen the latest version before saving.',409);
        }
        if (schema_metadata_table_exists($pdo,'agent_workflow_runs')) {
            $q=$pdo->prepare("SELECT goal FROM agent_workflow_runs WHERE owner_user_id=? AND source_kind='coding_canvas' AND status IN ('queued','approved','executing','paused') LIMIT 1");
            $q->execute([$owner]);$active=$q->fetchColumn();$activeGoal=$active===false?null:json_decode((string)$active,true);
            if($active!==false&&(!is_array($activeGoal)||($activeGoal['canvas_id']??null)===$id))throw new RuntimeException('Finish or stop the Coding Agent request for this app before saving source edits.',409);
        }
        require_once __DIR__.'/coding-canvas-email-v1.php';
        if ((int)$row['conversation_id']!==$cid && vp3_coding_canvas_email_binding_v1($pdo,$user,$agentId,$id,$base)!==null) {
            throw new RuntimeException('Open the original Chat to edit this email-connected Canvas.',409);
        }
        if ($artifact===$selected['revision']['artifact']) return $selected+['source_saved'=>false];
        $q=$pdo->prepare('SELECT edit_origin_revision FROM vp3_coding_canvas_versions_v1 WHERE canvas_id=? AND revision=?');
        $q->execute([$id,$base]);$origin=$q->fetchColumn();$origin=$origin===null||$origin===false?$base:(int)$origin;
        if ($origin<1||$origin>$base)throw new RuntimeException('This source history could not be verified.',409);
        $saved=vp3_coding_canvas_save_draft_v1($pdo,$user,$agentId,(int)$row['conversation_id'],$id,$row['title'],$artifact,[],0,$expectedDraft,$expectedActive,$selected['revision']['commands']);
        $q=$pdo->prepare('UPDATE vp3_coding_canvas_versions_v1 SET edit_base_revision=?,edit_origin_revision=?,edit_request_id=?,edit_request_hash=? WHERE canvas_id=? AND revision=? AND run_id IS NULL');
        $q->execute([$base,$origin,$requestId,$hash,$id,$saved['revision']['id']]);
        return vp3_coding_canvas_details_v1($pdo,vp3_coding_canvas_require_row_v1($pdo,$owner,$agentId,$id),$saved['revision']['id'])+['source_saved'=>true];
    });
}
