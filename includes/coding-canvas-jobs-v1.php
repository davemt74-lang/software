<?php
declare(strict_types=1);

require_once __DIR__.'/agent-worker-cloud-v1910.php';
require_once __DIR__.'/agent-event-infrastructure-v1920.php';
require_once __DIR__.'/agent-chat-boundary-v380.php';
require_once __DIR__.'/agent-capability-chat-v1.php';
require_once __DIR__.'/knowledge-retrieval-v162.php';
require_once __DIR__.'/participant-updates-v1.php';
require_once __DIR__.'/coding-canvas-store-v1.php';
require_once __DIR__.'/coding-canvas-provider-v1.php';

function vp3_coding_canvas_scope_v1(PDO $pdo, array $user, int $agentId, int $conversationId): array
{
    if (vp3_participant_scope_v1() !== null || !has_permission('chat.access', $user)) {
        throw new RuntimeException('Private owner Chat is required.', 403);
    }
    try {
        $agent = vp3_agent_chat_resolve_agent_v380($pdo, $user, $agentId);
    } catch (RuntimeException $error) {
        if ($error instanceof PDOException) throw $error;
        throw new RuntimeException('This Agent is unavailable.', 403);
    }
    $principal = vp3_agent_chat_principal_v380($user, $agent);
    if (!knowledge_retrieval_v162_owner_session($user, $principal)) {
        throw new RuntimeException('Private owner Chat is required.', 403);
    }
    $live = vp3_capability_live_user_v1($pdo, $user, $principal, ['conversation_id' => $conversationId], null);
    if (!personal_knowledge_available($live)) {
        throw new RuntimeException('Private Knowledge Base access is required.', 403);
    }
    return $live;
}

function vp3_coding_canvas_job_row_v1(PDO $pdo, array $user, int $runId): array
{
    $row = agent_workflow_row_v1400($pdo, (int)$user['id'], $runId);
    if (($row['source_kind'] ?? '') !== 'coding_canvas'
        || !in_array($row['capability_key'] ?? '', ['coding.build', 'coding.repair'], true)) {
        throw new RuntimeException('Coding job unavailable.', 404);
    }
    return $row;
}

function vp3_coding_canvas_job_view_v1(PDO $pdo, array $row): array
{
    $goal = json_decode((string)$row['goal'], true, 16, JSON_THROW_ON_ERROR);
    $state = match ($row['status']) {
        'approved', 'queued' => 'queued',
        'executing' => 'running',
        'completed' => 'ready',
        'cancelled' => 'stopped',
        default => 'failed',
    };
    $revision = null;
    if ($state === 'ready') {
        $stmt = $pdo->prepare("SELECT result_json FROM agent_workflow_receipts WHERE run_id=? AND owner_user_id=? AND status='completed' ORDER BY id DESC LIMIT 1");
        $stmt->execute([(int)$row['id'], (int)$row['owner_user_id']]);
        $receipt = json_decode((string)$stmt->fetchColumn(), true);
        $revision = ($receipt['canvas_id'] ?? '') === $goal['canvas_id'] ? (int)($receipt['canvas_revision'] ?? 0) : 0;
        if ($revision < 1) {
            $revision = null;
            $state = 'failed';
        }
    }
    $reason = $state !== 'failed' ? '' : match ($row['last_error_class']) {
        'coding_quota' => 'Your Coding Agent token budget is exhausted.',
        'coding_access_changed' => 'The Canvas, model, or account access changed. Start a new request.',
        'lease_expired' => 'The Coding Agent run expired. You can start a new request.',
        default => 'The Coding Agent could not save a draft. You can start a new request.',
    };
    return [
        'run_id' => (int)$row['id'],
        'conversation_id' => (int)$goal['conversation_id'],
        'canvas_id' => $goal['canvas_id'],
        'state' => $state,
        'revision' => $revision,
        'reason' => $reason,
        'model' => $goal['model'],
    ];
}

/** Release a run whose origin was deleted or whose sole execution lease expired. */
function vp3_coding_canvas_clear_abandoned_v1(PDO $pdo, array $user): void
{
    $owner = (int)$user['id'];
    $stmt = $pdo->prepare("SELECT id, agent_id, goal, status, lease_expires_at FROM agent_workflow_runs
        WHERE owner_user_id=? AND source_kind='coding_canvas' AND status IN ('queued','approved','executing') LIMIT 1");
    $stmt->execute([$owner]);
    $run = $stmt->fetch(PDO::FETCH_ASSOC);
    $stmt->closeCursor();
    if (!$run) return;
    $runId = (int)$run['id'];
    $expires = $run['lease_expires_at'];
    if ($run['status'] === 'executing' && $expires && agent_job_utc_timestamp_v1900($expires) < time()) {
        agent_job_recover_expired_v1900($pdo, $user, 1, $runId);
        return;
    }
    $goal = json_decode((string)$run['goal'], true, 16, JSON_THROW_ON_ERROR);
    $agent = $run['agent_id'] !== null ? ['id' => (int)$run['agent_id']] : null;
    if (vp3_agent_chat_conversation_v380($pdo, (int)$goal['conversation_id'], $owner, $agent)) return;
    try {
        agent_job_cancel_v1900($pdo, $user, $runId);
    } catch (RuntimeException $error) {
        $current = vp3_coding_canvas_job_row_v1($pdo, $user, $runId);
        if (!in_array($current['status'], ['cancelled', 'completed', 'failed'], true)) throw $error;
    }
}

function vp3_coding_canvas_queue_v1(
    PDO $pdo, array $user, int $agentId, int $conversationId, string $requestId, string $prompt,
    ?string $canvasId = null, ?int $revision = null, ?string $diagnostic = null, ?array $initiativeOrigin = null
): array {
    if (!agent_job_engine_schema_ready_v1900($pdo) || !agent_job_control_ready_v1900($pdo)
        || !vp3_ai_service_usage_ready_v1($pdo)) {
        throw new RuntimeException('Coding Agent requires Database Upgrade.', 503);
    }
    if ($conversationId < 1) throw new InvalidArgumentException('Choose a current Chat.');
    $owner = (int)$user['id'];
    $repair = $canvasId !== null;
    $capability = $repair ? 'coding.repair' : 'coding.build';
    $dedupe = hash('sha256', 'coding_canvas:'.$requestId);
    $requestFields = [$capability, $agentId, $conversationId, $prompt, $canvasId, $revision, $diagnostic];
    if ($initiativeOrigin !== null) $requestFields[] = ['initiative_origin'=>$initiativeOrigin];
    $requestHash = hash('sha256', json_encode($requestFields, JSON_THROW_ON_ERROR));
    $ownsTransaction = !$pdo->inTransaction();
    if ($ownsTransaction) {
        vp3_coding_canvas_clear_abandoned_v1($pdo, $user);
        $pdo->beginTransaction();
    }
    try {
        vp3_ai_service_usage_lock_owner_v1($pdo, $owner);
        $user = vp3_coding_canvas_scope_v1($pdo, $user, $agentId, $conversationId);
        vp3_coding_canvas_authority_v1($pdo, $user, $agentId, true);
        if ($initiativeOrigin !== null) {
            require_once __DIR__.'/agent-initiative-canvas-v1.php';
            if ($repair || ($initiativeOrigin['request_id'] ?? null) !== $requestId) throw new RuntimeException('Automatic Canvas admission changed.', 409);
            $user = vp3_initiative_canvas_origin_current_v1($pdo, $user, $initiativeOrigin);
        }
        $stmt = $pdo->prepare('SELECT * FROM agent_workflow_runs WHERE owner_user_id=? AND dedupe_key=?');
        $stmt->execute([$owner, $dedupe]);
        $existing = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($existing) {
            $goal = json_decode((string)$existing['goal'], true, 16, JSON_THROW_ON_ERROR);
            if (($existing['source_kind'] ?? '') !== 'coding_canvas'
                || !hash_equals((string)($goal['request_hash'] ?? ''), $requestHash)) {
                throw new RuntimeException('This request already has a different Canvas instruction.', 409);
            }
            $view = vp3_coding_canvas_job_view_v1($pdo, $existing);
            vp3_coding_canvas_scope_v1($pdo, $user, $agentId, $view['conversation_id']);
            if ($ownsTransaction) $pdo->commit();
            return $view;
        }
        $stmt = $pdo->prepare("SELECT id FROM agent_workflow_runs
            WHERE owner_user_id=? AND source_kind='coding_canvas' AND status IN ('queued','approved','executing','paused') LIMIT 1");
        $stmt->execute([$owner]);
        if ($stmt->fetchColumn()) {
            throw new RuntimeException('Finish or stop the current Coding Agent request first.', 409);
        }
        if (!subscription_has_entitlement($user, 'main_ai.access')) {
            throw new RuntimeException('The Coding Agent is unavailable on this account.', 403);
        }
        $model = vp3_coding_canvas_model_resolve_v1($pdo, $user);
        vp3_coding_canvas_model_authorized_v1($pdo, $user, $model, $repair ? 'code_repair' : 'code_build', $initiativeOrigin !== null);
        $source = $repair ? vp3_coding_canvas_read_v1($pdo, $user, $agentId, $canvasId, $revision) : null;
        $canvasId ??= agent_event_uuid_v1920();
        $goal = [
            'canvas_id' => $canvasId,
            'conversation_id' => $conversationId,
            'source_revision' => $revision,
            'expected_draft' => $source['canvas']['draft_revision'] ?? null,
            'expected_active' => $source['canvas']['active_revision'] ?? null,
            'request_hash' => $requestHash,
            'prompt' => $prompt,
            'diagnostic' => $diagnostic,
            'model' => $model,
        ];
        if ($initiativeOrigin !== null) $goal['initiative_origin'] = $initiativeOrigin;
        $title = $repair ? 'Repair a private Canvas' : 'Build a private Canvas';
        $pdo->prepare("INSERT INTO agent_workflow_runs
            (owner_user_id,agent_id,workflow_type,origin,source_kind,source_key,dedupe_key,title,goal,
             status,execution_target,capability_key,max_attempts,timeout_seconds)
            VALUES (?,?,'coding_canvas',?,'coding_canvas',?,?,?,?,'approved','cloud',?,1,180)")
            ->execute([$owner, $agentId ?: null, $initiativeOrigin === null ? 'chat' : 'brain', $requestId, $dedupe, $title, json_encode($goal, JSON_THROW_ON_ERROR), $capability]);
        $runId = (int)$pdo->lastInsertId();
        $pdo->prepare("INSERT INTO agent_workflow_actions
            (run_id,owner_user_id,sequence_no,action_key,action_type,label,status,execution_target,capability_key,max_attempts,timeout_seconds)
            VALUES (?,?,1,?,'execute',?,'queued','cloud',?,1,180)")
            ->execute([$runId, $owner, $requestId, $title, $capability]);
        agent_workflow_event_v1400($pdo, $owner, $runId, 'coding_requested', '', 'approved', $initiativeOrigin === null ? 'user' : 'system', $title.'.');
        $view = vp3_coding_canvas_job_view_v1($pdo, vp3_coding_canvas_job_row_v1($pdo, $user, $runId));
        if ($ownsTransaction) $pdo->commit();
        return $view;
    } catch (Throwable $error) {
        if ($ownsTransaction && $pdo->inTransaction()) $pdo->rollBack();
        throw $error;
    }
}

function vp3_coding_canvas_execute_v1(PDO $pdo, array $user, array $claim, ?callable $generate = null): array
{
    $run = agent_worker_cloud_active_row_v1910($pdo, $user, $claim);
    if (!$run || $run['source_kind'] !== 'coding_canvas'
        || !in_array($run['capability_key'], ['coding.build', 'coding.repair'], true)
        || $run['action_capability_key'] !== $run['capability_key']
        || $run['action_key'] !== $run['source_key']) {
        return ['ok' => false, 'reason' => 'coding_lease_unavailable'];
    }
    try {
        $goal = json_decode((string)$run['goal'], true, 16, JSON_THROW_ON_ERROR);
        $runId = (int)$run['id'];
        $agentId = (int)$run['agent_id'];
        $cid = (int)$goal['conversation_id'];
        $user = vp3_coding_canvas_scope_v1($pdo, $user, $agentId, $cid);
        vp3_coding_canvas_authority_v1($pdo, $user, $agentId, true);
        if (isset($goal['initiative_origin'])) {
            require_once __DIR__.'/agent-initiative-canvas-v1.php';
            $user = vp3_initiative_canvas_origin_current_v1($pdo, $user, $goal['initiative_origin'], $runId);
        }
        if (!subscription_has_entitlement($user, 'main_ai.access')) {
            throw new RuntimeException('The Coding Agent is unavailable on this account.', 403);
        }
        $canvasId = $goal['canvas_id'];
        $source = $run['capability_key'] === 'coding.repair'
            ? vp3_coding_canvas_read_v1($pdo, $user, $agentId, $canvasId, $goal['source_revision'])
            : null;
        if ($source !== null && ($source['canvas']['draft_revision'] !== $goal['expected_draft']
            || $source['canvas']['active_revision'] !== $goal['expected_active'])) {
            throw new RuntimeException('This Canvas changed.', 409);
        }
        if (!agent_job_heartbeat_v1900($pdo, $user, $runId, (int)$claim['action']['id'],
            $claim['lease_token'], 25, 'Generating a private Canvas draft', 180)) {
            return ['ok' => false, 'reason' => 'coding_lease_unavailable'];
        }
        $generate ??= 'vp3_coding_canvas_generate_v1';
        $draft = $generate($pdo, $user, $goal['model'], (string)$runId, $run['source_key'],
            $goal['prompt'], $source['revision']['artifact'] ?? null, $goal['diagnostic'],
            ['agent_id' => $agentId, 'conversation_id' => $cid] + (isset($goal['initiative_origin']) ? ['background'=>true] : []));

        if (isset($goal['initiative_origin'])) {
            // Refresh any remote privacy proof before opening the save lock;
            // repeat the exact authorization under that lock before writing.
            $user = vp3_initiative_canvas_origin_current_v1($pdo, $user, $goal['initiative_origin'], $runId);
        }
        $pdo->beginTransaction();
        vp3_ai_service_usage_lock_owner_v1($pdo, (int)$user['id']);
        if (!agent_worker_cloud_active_row_v1910($pdo, $user, $claim, true)) {
            $pdo->rollBack();
            return ['ok' => false, 'reason' => 'coding_lease_unavailable'];
        }
        $user = vp3_coding_canvas_scope_v1($pdo, $user, $agentId, $cid);
        if (isset($goal['initiative_origin'])) {
            $user = vp3_initiative_canvas_origin_current_v1($pdo, $user, $goal['initiative_origin'], $runId);
        }
        $saved = vp3_coding_canvas_save_draft_v1(
            $pdo, $user, $agentId, $source['canvas']['conversation_id'] ?? $cid, $canvasId,
            $source['canvas']['title'] ?? $draft['title'], $draft['artifact'], [], $runId,
            $goal['expected_draft'], $goal['expected_active']
        );
        $receipt = agent_worker_cloud_result_v1910($pdo, $user, $claim, true, 'Private Canvas draft saved.', [
            'canvas_id' => $canvasId, 'canvas_revision' => $saved['revision']['id'],
        ]);
        $pdo->commit();
        return ['ok' => true, 'reason' => 'completed', 'receipt' => $receipt];
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $reason = match ((int)$error->getCode()) {
            402 => 'coding_quota',
            403, 404, 409 => 'coding_access_changed',
            default => 'coding_failed',
        };
        if (agent_worker_cloud_active_row_v1910($pdo, $user, $claim)) {
            try {
                agent_worker_cloud_result_v1910($pdo, $user, $claim, false, 'A Canvas draft could not be saved.', [], $reason);
            } catch (Throwable $receiptError) {
                if (agent_worker_cloud_active_row_v1910($pdo, $user, $claim)) throw $receiptError;
            }
        }
        return ['ok' => false, 'reason' => $reason];
    }
}

function vp3_coding_canvas_job_v1(PDO $pdo, array $user, int $agentId, int $cid, int $runId, string $action): array
{
    $row = vp3_coding_canvas_job_row_v1($pdo, $user, $runId);
    $view = vp3_coding_canvas_job_view_v1($pdo, $row);
    if ((int)$row['agent_id'] !== $agentId || $view['conversation_id'] !== $cid) {
        throw new RuntimeException('Coding job unavailable in this conversation.', 404);
    }
    $leaseExpiry = agent_job_utc_timestamp_v1900($row['lease_expires_at'] ?? null);
    if ($view['state'] === 'running' && $leaseExpiry > 0 && $leaseExpiry < time()) {
        agent_job_recover_expired_v1900($pdo, $user, 1, $runId);
        return vp3_coding_canvas_job_view_v1($pdo, vp3_coding_canvas_job_row_v1($pdo, $user, $runId));
    }
    if ($action === 'tick') {
        $claim = agent_job_claim_run_v1900($pdo, $user, $runId, 'cloud',
            agent_worker_cloud_worker_id_v1910((int)$user['id']), 180);
        if ($claim) vp3_coding_canvas_execute_v1($pdo, $user, $claim);
    } elseif ($action === 'stop' && in_array($view['state'], ['queued', 'running'], true)) {
        try {
            agent_job_cancel_v1900($pdo, $user, $runId);
        } catch (Throwable $error) {
            $current = vp3_coding_canvas_job_view_v1($pdo, vp3_coding_canvas_job_row_v1($pdo, $user, $runId));
            if (in_array($current['state'], ['queued', 'running'], true)) throw $error;
        }
    } else {
        return $view;
    }
    return vp3_coding_canvas_job_view_v1($pdo, vp3_coding_canvas_job_row_v1($pdo, $user, $runId));
}

function vp3_coding_canvas_resume_v1(PDO $pdo, array $user, int $agentId, int $cid): ?array
{
    $agentScope = $agentId > 0 ? 'agent_id=?' : 'agent_id IS NULL';
    $stmt = $pdo->prepare("SELECT * FROM agent_workflow_runs
        WHERE owner_user_id=? AND $agentScope AND source_kind='coding_canvas'
        AND status IN ('queued','approved','executing','paused') ORDER BY id DESC LIMIT 1");
    $stmt->execute($agentId > 0 ? [(int)$user['id'], $agentId] : [(int)$user['id']]);
    $run = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$run) return null;
    $view = vp3_coding_canvas_job_view_v1($pdo, $run);
    return $view['conversation_id'] === $cid ? $view : null;
}
