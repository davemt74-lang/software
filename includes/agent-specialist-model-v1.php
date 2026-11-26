<?php
declare(strict_types=1);

require_once __DIR__.'/ai-service-policy-v1.php';
require_once __DIR__.'/ai-service-usage-v1.php';
require_once __DIR__.'/ai-runtime-v100.php';
require_once __DIR__.'/user-llm-v1.php';
require_once __DIR__.'/user-agent-system-v236.php';
require_once __DIR__.'/agent-chat-boundary-v380.php';

const VP3_SPECIALIST_MODEL_V1 = 'vp3.specialist-model-preference.v1';
const VP3_SPECIALIST_MODEL_IDS_V1 = ['tasks', 'planning'];

/**
 * Configuration foundation for saved Initiative work; no runtime caller yet.
 * The caller supplies an authenticated/authorized owner, never a request user ID.
 * A future worker must derive the specialist from its verified persisted run.
 * Resolution does not authorize a Cloud route, reserve usage or verify execution.
 */
function vp3_specialist_model_scope_v1(PDO $pdo, array $user, int $agentId, string $specialist, bool $lock = false): array
{
    $id = $user['id'] ?? null;
    if ((!is_int($id) && !is_string($id)) || !preg_match('/\A[1-9][0-9]{0,15}\z/D', (string)$id)
        || $agentId < 0 || !in_array($specialist, VP3_SPECIALIST_MODEL_IDS_V1, true)
        || ($lock && !$pdo->inTransaction())
        || (function_exists('vp3_participant_scope_v1') && vp3_participant_scope_v1() !== null)) {
        throw new RuntimeException('Specialist model access is unavailable.', 403);
    }
    if (!function_exists('has_permission')) throw new RuntimeException('Specialist model authorization is unavailable.', 503);
    $owner = (int)$id;
    $suffix = $lock && $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '';
    $query = $pdo->prepare('SELECT * FROM users WHERE id=?'.$suffix);
    $query->execute([$owner]);
    $live = $query->fetch(PDO::FETCH_ASSOC);
    if (!$live || (int)($live['is_active'] ?? 0) !== 1 || !has_permission('account.access', $live)
        || !has_permission('chat.access', $live)) {
        throw new RuntimeException('Specialist model access changed.', 403);
    }
    if ($agentId > 0 && $lock) {
        // Validate the locked current row itself. A later nonlocking SELECT
        // could return an older snapshot under MySQL REPEATABLE READ.
        $query = $pdo->prepare('SELECT * FROM user_agents WHERE id=? AND owner_user_id=?'.$suffix);
        $query->execute([$agentId, $owner]);
        $agent = $query->fetch(PDO::FETCH_ASSOC);
        if (!$agent || (int)($agent['is_active'] ?? 0) !== 1) throw new RuntimeException('Specialist Agent access changed.', 403);
    } else {
        try {
            $agent = vp3_agent_chat_resolve_agent_v380($pdo, $live, $agentId);
        } catch (PDOException $error) {
            throw $error;
        } catch (RuntimeException $error) {
            throw new RuntimeException('Specialist Agent access changed.', 403, $error);
        }
    }
    if ($agent !== null && ($agent['retired_at'] ?? null) !== null) {
        throw new RuntimeException('Specialist Agent access changed.', 403);
    }
    return $live;
}

function vp3_specialist_model_key_v1(int $owner, int $agentId, string $specialist): string
{
    if ($owner < 1 || $agentId < 0 || !in_array($specialist, VP3_SPECIALIST_MODEL_IDS_V1, true)) {
        throw new InvalidArgumentException('Choose an exact owner, Agent and supported specialist.');
    }
    return 'vp3_specialist_model_v1_'.$owner.'_'.$agentId.'_'.$specialist;
}

/** Null preserves the existing Agent runtime; a fallback is always explicit. */
function vp3_specialist_model_selection_v1(?array $selection): ?array
{
    if ($selection === null) return null;
    if (count($selection) !== 2 || !array_key_exists('fallback', $selection)
        || !is_array($selection['primary'] ?? null)
        || ($selection['fallback'] !== null && !is_array($selection['fallback']))) {
        throw new InvalidArgumentException('Choose a primary model and optional fallback.');
    }
    $primary = vp3_ai_service_target_v1($selection['primary']);
    $fallback = $selection['fallback'] === null ? null : vp3_ai_service_target_v1($selection['fallback']);
    if ($primary === $fallback) throw new InvalidArgumentException('Choose a different fallback model.');
    return compact('primary', 'fallback');
}

function vp3_specialist_model_record_v1(PDO $pdo, int $owner, int $agentId, string $specialist, bool $lock = false): array
{
    if ($lock && !$pdo->inTransaction()) throw new LogicException('Lock specialist preferences inside a transaction.');
    $key = vp3_specialist_model_key_v1($owner, $agentId, $specialist);
    // Unlike legacy policy discovery, a missing settings table cannot erase a pin.
    try {
        $pdo->query('SELECT setting_key,setting_value FROM settings WHERE 1=0');
        $record = vp3_ai_service_read_setting_v1($pdo, $key, $lock);
    } catch (PDOException | UnexpectedValueException $error) {
        throw new RuntimeException('Stored specialist model settings are unavailable.', 503, $error);
    }
    if ($record === null) return ['version'=>VP3_SPECIALIST_MODEL_V1, 'owner_user_id'=>$owner, 'agent_id'=>$agentId,
        'specialist'=>$specialist, 'revision'=>hash('sha256', VP3_SPECIALIST_MODEL_V1.'|'.$key.'|inherit'),
        'selection'=>null, 'funding'=>null];
    try {
        if (count($record) !== 8 || ($record['version'] ?? null) !== VP3_SPECIALIST_MODEL_V1
            || ($record['owner_user_id'] ?? null) !== $owner || ($record['agent_id'] ?? null) !== $agentId
            || ($record['specialist'] ?? null) !== $specialist || !is_string($record['revision'] ?? null)
            || !preg_match('/\A[a-f0-9]{64}\z/D', $record['revision']) || !array_key_exists('selection', $record)
            || !array_key_exists('funding', $record) || !is_int($record['updated_at'] ?? null)
            || ($record['selection'] !== null && !is_array($record['selection']))) {
            throw new InvalidArgumentException('Invalid preference record.');
        }
        $record['selection'] = vp3_specialist_model_selection_v1($record['selection']);
        if ($record['selection'] === null ? $record['funding'] !== null : !in_array($record['funding'], ['system', 'own_key'], true)) {
            throw new InvalidArgumentException('Invalid preference funding.');
        }
    } catch (InvalidArgumentException $error) {
        throw new RuntimeException('Stored specialist model settings are unavailable.', 503, $error);
    }
    return $record;
}

/** Current configured service targets remain the ceiling enforced by usage admission. */
function vp3_specialist_model_options_v1(PDO $pdo, array $live, bool $background): array
{
    $policy = vp3_ai_service_policy_v1($pdo, $live, 'initiative', $background);
    $result = ['funding'=>null, 'policy'=>$policy, 'options'=>[], 'reason'=>'service_route_unconfigured'];
    if (empty($policy['enabled'])) return array_replace($result, ['reason'=>$policy['reason'] ?: 'service_unavailable']);
    if (empty($policy['configured'])) return $result;
    if (!function_exists('subscription_has_entitlement') || !subscription_has_entitlement($live, 'main_ai.access')) {
        return array_replace($result, ['reason'=>'account_not_entitled']);
    }
    try {
        // An explicit specialist preference cannot use legacy missing-table
        // fallback to change a personal-funded choice to system-funded usage.
        $pdo->query('SELECT user_id,route,provider FROM user_llm_preferences WHERE 1=0');
        $result['funding'] = vp3_user_llm_v1_funding($pdo, $live);
        $models = vp3_ai_service_models_v1($pdo, 'initiative');
        foreach ([$policy['primary'], $policy['fallback']] as $target) {
            if ($target === null || !isset($models[$target['provider']][$target['model']])) continue;
            // Reuse the exact service credential boundary; never return its keys.
            $credential = ai_v100_service_target_credential($target + ['funding'=>$result['funding']], $live, $pdo);
            if (isset($credential['failure'])) continue;
            $result['options'][$target['provider'].':'.$target['model']] = $target + ['label'=>$models[$target['provider']][$target['model']]];
        }
    } catch (Throwable $error) {
        return array_replace($result, ['options'=>[], 'reason'=>'cloud_configuration_unavailable']);
    }
    $result['options'] = array_values($result['options']);
    $result['reason'] = $result['options'] ? 'requires_runtime_admission' : 'provider_unavailable';
    return $result;
}

/**
 * No DDL, network, commits or rollbacks. In a caller transaction, owner, Agent
 * and preference reads lock their current rows. The caller must acquire its
 * owner lock before establishing a snapshot and recheck current route, funding,
 * policy and usage admission before dispatch; this result grants none of those.
 */
function vp3_specialist_model_resolve_v1(PDO $pdo, array $user, int $agentId, string $specialist, bool $background = true): array
{
    $lock = $pdo->inTransaction();
    $live = vp3_specialist_model_scope_v1($pdo, $user, $agentId, $specialist, $lock);
    $record = vp3_specialist_model_record_v1($pdo, (int)$live['id'], $agentId, $specialist, $lock);
    $result = array_intersect_key($record, array_flip(['version', 'owner_user_id', 'agent_id', 'specialist', 'revision', 'selection', 'funding']))
        + ['service'=>'initiative', 'compute'=>'inherit', 'state'=>'inherit', 'reason'=>'inherit_agent_runtime', 'targets'=>[], 'policy'=>null];
    if ($record['selection'] === null) return $result;
    $available = vp3_specialist_model_options_v1($pdo, $live, $background);
    $result['policy'] = $available['policy'];
    $result['state'] = 'blocked';
    $result['reason'] = $available['reason'];
    if ($available['reason'] !== 'requires_runtime_admission') return $result;
    if ($record['funding'] !== $available['funding']) {
        $result['reason'] = 'funding_changed';
        return $result;
    }
    // Compare complete provider/model identities; identical model names may exist at two providers.
    $eligible = static fn(array $target): bool => (bool)array_filter($available['options'],
        static fn(array $option): bool => $option['provider'] === $target['provider'] && $option['model'] === $target['model']);
    foreach (['primary', 'fallback'] as $slot) {
        $target = $record['selection'][$slot];
        if ($target !== null && $eligible($target)) $result['targets'][] = $target + ['funding'=>$record['funding']];
    }
    if (!$result['targets']) {
        $result['reason'] = 'selected_models_unavailable';
        return $result;
    }
    $result['state'] = 'resolved';
    $result['reason'] = $eligible($record['selection']['primary']) ? 'requires_runtime_admission' : 'primary_unavailable_fallback_selected';
    return $result;
}

function vp3_specialist_model_state_v1(PDO $pdo, array $user, int $agentId, string $specialist): array
{
    $live = vp3_specialist_model_scope_v1($pdo, $user, $agentId, $specialist, $pdo->inTransaction());
    $resolution = vp3_specialist_model_resolve_v1($pdo, $live, $agentId, $specialist);
    $available = vp3_specialist_model_options_v1($pdo, $live, true);
    unset($resolution['policy']);
    return $resolution + ['options'=>$available['options'], 'options_reason'=>$available['reason']];
}

/** Save only after private-owner endpoint/worker authorization; use the returned revision for the next edit. */
function vp3_specialist_model_save_v1(PDO $pdo, array $user, int $agentId, string $specialist, string $expectedRevision, ?array $selection): array
{
    $selection = vp3_specialist_model_selection_v1($selection);
    if (!preg_match('/\A[a-f0-9]{64}\z/D', $expectedRevision)) throw new InvalidArgumentException('Choose the current settings revision.');
    if ($pdo->inTransaction()) throw new LogicException('Save specialist models outside an existing transaction.');
    $live = vp3_specialist_model_scope_v1($pdo, $user, $agentId, $specialist);
    $owner = (int)$live['id'];
    $pdo->beginTransaction();
    try {
        vp3_ai_service_usage_lock_owner_v1($pdo, $owner);
        $live = vp3_specialist_model_scope_v1($pdo, $live, $agentId, $specialist, true);
        $record = vp3_specialist_model_record_v1($pdo, $owner, $agentId, $specialist, true);
        if (!hash_equals($record['revision'], $expectedRevision)) throw new RuntimeException('Specialist model settings changed. Refresh and try again.', 409);
        $funding = null;
        if ($selection !== null) {
            $available = vp3_specialist_model_options_v1($pdo, $live, true);
            $options = array_map(static fn(array $target): array => array_intersect_key($target, ['provider'=>true, 'model'=>true]), $available['options']);
            if ($available['reason'] !== 'requires_runtime_admission') throw new RuntimeException('Specialist Cloud models are unavailable.', 409);
            foreach ($selection as $target) {
                if ($target !== null && !in_array($target, $options, true)) throw new RuntimeException('Choose a currently allowed specialist model.', 403);
            }
            $funding = $available['funding'];
        }
        // Re-saving the same reviewed choice is idempotent and does not invalidate queued work.
        if ($record['selection'] !== $selection || $record['funding'] !== $funding) {
            $record = ['version'=>VP3_SPECIALIST_MODEL_V1, 'owner_user_id'=>$owner, 'agent_id'=>$agentId,
                'specialist'=>$specialist, 'revision'=>bin2hex(random_bytes(32)), 'selection'=>$selection,
                'funding'=>$funding, 'updated_at'=>time()];
            vp3_ai_service_write_setting_v1($pdo, vp3_specialist_model_key_v1($owner, $agentId, $specialist), $record);
        }
        $pdo->commit();
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $error;
    }
    return vp3_specialist_model_state_v1($pdo, $live, $agentId, $specialist);
}
