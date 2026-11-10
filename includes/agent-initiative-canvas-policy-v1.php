<?php
declare(strict_types=1);

require_once __DIR__.'/agent-initiative-v1.php';
require_once __DIR__.'/agent-brain-v122.php';
require_once __DIR__.'/coding-canvas-store-v1.php';

/** An Agent-scoped control; reading its metadata does not grant Brain or source access. */
function vp3_initiative_canvas_setting_v1(
    PDO $pdo, array $user, int $agentId, ?bool $enabled = null,
    ?string $expectedRevision = null, ?callable $authority = null
): array {
    if ($agentId < 0 || (int)($user['id'] ?? 0) < 1 || vp3_participant_scope_v1() !== null) {
        throw new RuntimeException('Open your private Agent Chat to change Auto Build.', 403);
    }
    return vp3_initiative_in_scope_v1($user, $agentId, static function () use ($pdo, $user, $agentId, $enabled, $expectedRevision, $authority): array {
        $principal = vp3_initiative_principal_v1($pdo, $user, $agentId);
        $base = ['owner_user_id'=>(int)$user['id'], 'agent_id'=>$agentId, 'enabled'=>false,
            'initiative_on'=>false, 'available'=>false, 'can_enable'=>false, 'revision'=>'', 'reason'=>''];
        $owns = $enabled !== null && !$pdo->inTransaction();
        if ($owns) $pdo->beginTransaction();
        try {
            if ($enabled !== null) {
                vp3_initiative_owner_lock_v1($pdo, $user);
                vp3_ai_service_usage_lock_owner_v1($pdo, (int)$user['id']);
            }
            $live = vp3_capability_live_user_v1($pdo, $user, $principal, [], $authority);
            if (!has_permission('account.access', $live) || vp3_participant_scope_v1() !== null) {
                throw new RuntimeException('Agent access changed.', 403);
            }
            if (!agent_brain_schema_ready() || !agent_job_engine_schema_ready_v1900($pdo)) {
                if ($enabled !== null) throw new RuntimeException('Auto Build needs Database Upgrade.', 503);
                return array_replace($base, ['reason'=>'Run Database Upgrade to use Auto Build.']);
            }
            $policy = vp3_initiative_policy_v1($live, $agentId);
            $cid = $policy['conversation_id'] ?? 0;
            if ($policy === [] || !is_int($cid) || $cid < 1) {
                if ($enabled !== null) throw new RuntimeException('Enable Initiative in a saved Chat first.', 409);
                return array_replace($base, ['reason'=>'Enable Initiative in a saved Chat first.']);
            }
            $revision = $policy['revision'] ?? '';
            if (!is_string($revision) || !preg_match('/\A[a-f0-9]{32}\z/D', $revision)
                || vp3_initiative_binding_agent_v1($policy) !== $agentId
                || (isset($policy['owner_user_id']) && $policy['owner_user_id'] !== (int)$live['id'])
                || ($agentId > 0 && ($policy['owner_user_id'] ?? null) !== (int)$live['id'])
                || !vp3_agent_chat_conversation_v380($pdo, $cid, (int)$live['id'], $agentId > 0 ? ['id'=>$agentId] : null)) {
                throw new RuntimeException('The Agent’s saved Chat or settings changed.', 403);
            }
            $on = ($policy['allow_auto_drafts'] ?? false) === true;
            $reason = '';
            $canEnable = true;
            try {
                if (!subscription_has_entitlement($live, 'main_ai.access')) throw new RuntimeException('AI access is unavailable for this account.', 403);
                vp3_coding_canvas_authority_v1($pdo, $live, $agentId, true);
                $pin = vp3_coding_canvas_model_resolve_v1($pdo, $live);
                vp3_coding_canvas_model_authorized_v1($pdo, $live, $pin, 'code_build', true);
            } catch (RuntimeException $error) {
                $canEnable = false;
                $reason = in_array($error->getCode(), [402,403,409,503], true)
                    ? $error->getMessage() : 'The Coding Agent is currently unavailable.';
            }
            if ($enabled !== null) {
                if (!is_string($expectedRevision) || !hash_equals($revision, $expectedRevision)) {
                    throw new RuntimeException('Auto Build settings changed. Refresh and try again.', 409);
                }
                if ($enabled && !$canEnable) throw new RuntimeException($reason, 403);
                $live = vp3_capability_live_user_v1($pdo, $live, $principal, ['conversation_id'=>$cid], $authority);
                if (vp3_participant_scope_v1() !== null || !has_permission('account.access', $live)
                    || vp3_initiative_policy_v1($live, $agentId) !== $policy) {
                    throw new RuntimeException('Agent settings changed.', 409);
                }
                if ($enabled !== $on) {
                    $policy['allow_auto_drafts'] = $enabled;
                    $policy['revision'] = bin2hex(random_bytes(16));
                    if (agent_brain_v122_upsert_system_memory($live, 'initiative_policy', 'owner-opt-in', 'Owner initiative preference.', $policy) < 1) {
                        throw new RuntimeException('Auto Build could not be saved.', 503);
                    }
                    $on = $enabled;
                }
                if ($owns) $pdo->commit();
            } else {
                $live = vp3_capability_live_user_v1($pdo, $live, $principal, ['conversation_id'=>$cid], $authority);
                if (!has_permission('account.access', $live) || vp3_participant_scope_v1() !== null || vp3_initiative_policy_v1($live, $agentId) !== $policy) {
                    throw new RuntimeException('Agent settings changed.', 409);
                }
            }
            return array_replace($base, ['enabled'=>$on, 'initiative_on'=>($policy['enabled'] ?? false) === true,
                'available'=>true, 'can_enable'=>$canEnable, 'revision'=>$policy['revision'],
                'reason'=>$reason !== '' ? $reason : (empty($policy['enabled']) ? 'Runs when Initiative is on.' : '')]);
        } catch (Throwable $error) {
            if ($owns && $pdo->inTransaction()) $pdo->rollBack();
            throw $error;
        } finally {
            if ($owns && $pdo->inTransaction()) $pdo->rollBack();
        }
    });
}
