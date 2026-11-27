<?php
declare(strict_types=1);

/** Follow an exact completed Canvas revision back through explicit repairs. */
function vp3_coding_canvas_email_binding_v1(PDO $pdo, array $user, int $agentId, string $canvasId, int $revision): ?array
{
    if (!agent_job_engine_schema_ready_v1900($pdo)) return null;
    $owner = (int)$user['id'];
    $manual = null;
    if (schema_metadata_column_exists($pdo,'vp3_coding_canvas_versions_v1','edit_origin_revision')) {
        $manual = $pdo->prepare('SELECT v.run_id,v.edit_origin_revision,v.edit_base_revision,v.edit_request_id,v.edit_request_hash
            FROM vp3_coding_canvas_versions_v1 v JOIN vp3_coding_canvases_v1 c ON c.id=v.canvas_id
            WHERE c.id=? AND c.owner_user_id=? AND c.agent_id=? AND v.revision=?');
    }
    $q = $pdo->prepare("SELECT c.conversation_id,r.id,r.goal,r.capability_key,r.source_key
        FROM vp3_coding_canvases_v1 c JOIN vp3_coding_canvas_versions_v1 v ON v.canvas_id=c.id
        JOIN agent_workflow_runs r ON r.id=v.run_id AND r.owner_user_id=c.owner_user_id
            AND COALESCE(r.agent_id,0)=c.agent_id AND r.source_kind='coding_canvas'
            AND r.execution_target='cloud' AND r.status='completed'
        WHERE c.id=? AND c.owner_user_id=? AND c.agent_id=? AND v.revision=?");
    $receipt = $pdo->prepare("SELECT a.result_json,r.result_json AS receipt_json FROM agent_workflow_actions a
        JOIN agent_workflow_receipts r ON r.run_id=a.run_id AND r.action_id=a.id AND r.owner_user_id=a.owner_user_id
            AND r.executor='cloud' AND r.status='completed' AND r.attempt_no=a.attempt_count
        WHERE a.run_id=? AND a.owner_user_id=? AND a.capability_key=? AND a.sequence_no=1 AND a.status='completed'
        ORDER BY r.id DESC LIMIT 1");
    for ($depth = 0; $depth < 64; $depth++) {
        if ($manual) {
            $manual->execute([$canvasId,$owner,$agentId,$revision]);$edit=$manual->fetch(PDO::FETCH_ASSOC);
            if ($edit && $edit['run_id']===null && ($edit['edit_origin_revision']!==null||$edit['edit_base_revision']!==null||$edit['edit_request_id']!==null||$edit['edit_request_hash']!==null)) {
                $origin=(int)$edit['edit_origin_revision'];$base=(int)$edit['edit_base_revision'];
                if ($origin<1||$origin>$base||$base>=$revision
                    || !preg_match('/\A[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}\z/D',(string)$edit['edit_request_id'])
                    || !preg_match('/\A[a-f0-9]{64}\z/D',(string)$edit['edit_request_hash'])) {
                    throw new RuntimeException('This Canvas source history could not be verified.',409);
                }
                $revision=$origin;continue;
            }
        }
        $q->execute([$canvasId,$owner,$agentId,$revision]);
        $run = $q->fetch(PDO::FETCH_ASSOC);
        if (!$run || !in_array($run['capability_key'], ['coding.build','coding.repair'], true)) return null;
        $goal = json_decode((string)$run['goal'], true);
        $cid = (int)$run['conversation_id'];
        if (($goal['canvas_id'] ?? null) !== $canvasId || ($goal['conversation_id'] ?? null) !== $cid) return null;
        // The common nonconnected Canvas needs no further proof or Gmail read.
        if ($run['capability_key'] === 'coding.build' && !is_array($goal['initiative_origin'] ?? null)) return null;
        $receipt->execute([(int)$run['id'],$owner,$run['capability_key']]);
        $saved = $receipt->fetch(PDO::FETCH_ASSOC);
        $output = json_decode((string)($saved['result_json'] ?? ''), true);
        $savedRevision = filter_var($output['canvas_revision'] ?? null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
        if (($saved['result_json'] ?? null) !== ($saved['receipt_json'] ?? null)
            || ($output['canvas_id'] ?? null) !== $canvasId || $savedRevision !== $revision) return null;
        if ($run['capability_key'] === 'coding.build') break;
        $source = $goal['source_revision'] ?? null;
        if (!is_int($source) || $source < 1 || $source >= $revision) return null;
        $revision = $source;
    }
    if ($depth===64) throw new RuntimeException('This Canvas source history is too deep to verify. Open an earlier verified version.',409);
    if (($run['capability_key'] ?? null) !== 'coding.build') return null;
    $origin = $goal['initiative_origin'];
    $parentId = $origin['initiative_run_id'] ?? null;
    if (($origin['version'] ?? null) !== 1 || ($origin['kind'] ?? null) !== 'initiative_canvas'
        || !is_int($parentId) || $parentId < 1 || ($origin['request_id'] ?? null) !== $run['source_key']
        || !in_array($origin['authorization'] ?? null, ['owner_followthrough','owner_started','auto_drafts'], true)) return null;
    require_once __DIR__.'/agent-initiative-canvas-v1.php';
    try {
        $parent = vp3_initiative_canvas_parent_v1($pdo,$user,$parentId,(string)($origin['parent_dedupe'] ?? ''));
        $parentGoal = $parent['goal'];
        $binding = $parentGoal['binding'];
        $ref = $parentGoal['primary'];
        if (!preg_match('/\Aemail-canvas:[a-f0-9]{64}\z/D',$ref) || ($binding['conversation_id'] ?? null) !== $cid
            || (int)($binding['agent_id'] ?? 0) !== $agentId || $parent['row']['status'] !== 'completed'
            || !hash_equals(hash('sha256',(string)$parent['row']['goal']),(string)($origin['parent_goal_hash'] ?? ''))) return null;
        $receipt->execute([$parentId,$owner,'initiative.prepare']);
        $saved = $receipt->fetch(PDO::FETCH_ASSOC);
        $output = json_decode((string)($saved['result_json'] ?? ''),true)['initiative_tool_result']['output'] ?? [];
        if (($saved['result_json'] ?? null) !== ($saved['receipt_json'] ?? null)
            || ($output['type'] ?? null) !== 'initiative_canvas' || ($output['status'] ?? null) !== 'draft_queued'
            || ($output['parent_run_id'] ?? null) !== $parentId || ($output['coding_run_id'] ?? null) !== (int)$run['id']
            || ($output['canvas_id'] ?? null) !== $canvasId || ($output['source_ref'] ?? null) !== $ref
            || ($output['source_revision'] ?? null) !== $parentGoal['sources'][$ref]) return null;
        $proposal = vp3_initiative_canvas_proposal_v1($output['proposal'] ?? null);
        if (($goal['prompt'] ?? null) !== $proposal['prompt'] || !hash_equals(
            hash('sha256',json_encode($proposal,JSON_THROW_ON_ERROR)),(string)($origin['proposal_hash'] ?? '')
        )) return null;
        return ['binding'=>$binding,'ref'=>$ref];
    } catch (PDOException $error) {
        throw $error;
    } catch (RuntimeException|JsonException $error) {
        return null;
    }
}

/** Runtime reads check the current Gmail grant, independently from build deduplication. */
function vp3_coding_canvas_email_data_v1(PDO $pdo,array $user,int $agentId,int $cid,string $canvasId,int $revision): ?array
{
    if ($cid < 1) return null;
    $user = vp3_coding_canvas_scope_v1($pdo,$user,$agentId,$cid);
    vp3_coding_canvas_authority_v1($pdo,$user,$agentId);
    $origin = vp3_coding_canvas_email_binding_v1($pdo,$user,$agentId,$canvasId,$revision);
    if ($origin === null || $origin['binding']['conversation_id'] !== $cid) return null;
    require_once __DIR__.'/agent-initiative-email-canvas-v1.php';
    return vp3_initiative_email_canvas_projection_v1($pdo,$user,$origin['binding'],$origin['ref']);
}

/** Record the browser's sample check together with the current draft's test marker. */
function vp3_coding_canvas_email_test_v1(PDO $pdo,array $user,int $agentId,int $cid,string $canvasId,int $revision,string $stateRevision,bool $checked,?string $expectedImage=null): array
{
    $origin = vp3_coding_canvas_email_binding_v1($pdo,$user,$agentId,$canvasId,$revision);
    if ($origin === null) return vp3_coding_canvas_mark_tested_v1($pdo,$user,$agentId,$canvasId,$revision,$stateRevision,$expectedImage);
    if (!$checked || $origin['binding']['conversation_id'] !== $cid) {
        throw new RuntimeException('Run the email deadline test in the original Chat.',409);
    }
    $owner = (int)$user['id'];
    return vp3_coding_canvas_atomic_v1($pdo,$owner,static function()use($pdo,$user,$agentId,$canvasId,$revision,$stateRevision,$owner,$origin,$expectedImage):array{
        if (vp3_coding_canvas_email_binding_v1($pdo,$user,$agentId,$canvasId,$revision) !== $origin) {
            throw new RuntimeException('The email connection for this Canvas changed.',409);
        }
        $result = vp3_coding_canvas_mark_tested_v1($pdo,$user,$agentId,$canvasId,$revision,$stateRevision,$expectedImage);
        $q = $pdo->prepare('SELECT run_id FROM vp3_coding_canvas_versions_v1 WHERE canvas_id=? AND revision=?');
        $q->execute([$canvasId,$revision]);
        $run = (int)$q->fetchColumn();
        $metadata = ['canvas_id'=>$canvasId,'revision'=>$revision,'state_revision'=>$stateRevision];
        $q = $pdo->prepare("SELECT 1 FROM agent_workflow_events WHERE run_id=? AND owner_user_id=?
            AND event_type='email_canvas_fixture_passed' AND metadata_json=? LIMIT 1");
        $q->execute([$run,$owner,agent_workflow_json_v1400($metadata)]);
        if (!$q->fetchColumn()) agent_workflow_event_v1400($pdo,$owner,$run,'email_canvas_fixture_passed','completed','completed',
            'user','Email deadline check passed with sample data.',$metadata);
        return $result;
    });
}
