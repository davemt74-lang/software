<?php
declare(strict_types=1);

require_once __DIR__.'/ai-service-policy-v1.php';
require_once __DIR__.'/agent-image-provider-v1.php';
if (!function_exists('ai_decrypt_secret')) if (!function_exists('ai_decrypt_secret')) require_once __DIR__.'/ai-settings.php';

const VP3_AI_SERVICE_OWN_KEY_SCOPES_V1 = ['initiative', 'image_generation'];

function vp3_ai_service_own_key_scope_v1(string $service, string $provider, string $model): bool
{
    return in_array($service, VP3_AI_SERVICE_OWN_KEY_SCOPES_V1, true)
        && in_array($provider, vp3_ai_service_catalog_v1()[$service]['providers'], true)
        && vp3_ai_service_model_id_v1($model);
}

function vp3_ai_service_own_key_credential_v1(PDO $pdo, int $owner, string $provider): string
{
    try {
        $stmt = $pdo->prepare('SELECT encrypted_key FROM user_llm_credentials WHERE user_id=? AND provider=?');
        $stmt->execute([$owner, $provider]);
        return (string)$stmt->fetchColumn();
    } catch (PDOException) {
        return '';
    }
}

function vp3_ai_service_own_key_v1(PDO $pdo, int $owner, string $service, string $provider, string $model): string
{
    if ($owner < 1 || !vp3_ai_service_own_key_scope_v1($service, $provider, $model)) return '';
    try {
        $grants = vp3_ai_service_read_setting_v1($pdo, "vp3_ai_service_own_key_v1_{$owner}_{$service}") ?? [];
        $grant = $grants[$provider.':'.$model] ?? null;
        if (!is_string($grant['revision'] ?? null)) return '';
        $encrypted = vp3_ai_service_own_key_credential_v1($pdo, $owner, $provider);
        if ($encrypted === '' || !hash_equals($grant['revision'], hash('sha256', $encrypted))) return '';
        return ai_decrypt_secret($encrypted);
    } catch (PDOException | UnexpectedValueException) {
        return '';
    }
}

function vp3_ai_service_own_key_targets_v1(PDO $pdo, array $user, string $service): array
{
    $policy = vp3_ai_service_policy_v1($pdo, $user, $service, $service === 'initiative');
    $targets = [];
    foreach (['primary', 'fallback'] as $slot) {
        $target = $policy[$slot] ?? null;
        if (($target['funding'] ?? '') !== 'own_key' || !vp3_ai_service_target_allowed_v1($policy, $target)) continue;
        $targets[$target['provider'].':'.$target['model']] = $target;
    }
    return $targets;
}

function vp3_ai_service_own_key_state_v1(PDO $pdo, array $user, array $providers): array
{
    $owner = (int)($user['id'] ?? 0);
    if ($owner < 1) return [];
    $state = [];
    foreach (VP3_AI_SERVICE_OWN_KEY_SCOPES_V1 as $service) {
        $configured = vp3_ai_service_own_key_targets_v1($pdo, $user, $service);
        $grants = vp3_ai_service_read_setting_v1($pdo, "vp3_ai_service_own_key_v1_{$owner}_{$service}") ?? [];
        foreach ($configured + $grants as $id => $target) {
            $provider = $target['provider'] ?? '';
            $model = $target['model'] ?? '';
            if (!is_string($provider) || !is_string($model)
                || !vp3_ai_service_own_key_scope_v1($service, $provider, $model)) continue;
            $approved = vp3_ai_service_own_key_v1($pdo, $owner, $service, $provider, $model) !== '';
            if (!$approved && !isset($configured[$id])) continue;
            $state[] = [
                'service' => $service, 'label' => vp3_ai_service_catalog_v1()[$service]['label'],
                'provider' => $provider, 'model' => $model, 'approved' => $approved,
                'can_approve' => isset($configured[$id]) && !empty($providers[$provider]['configured']),
            ];
        }
    }
    return $state;
}

function vp3_ai_service_own_key_update_v1(PDO $pdo, array $user, string $service, string $provider, string $model, bool $approve): void
{
    $owner = (int)($user['id'] ?? 0);
    if ($owner < 1 || !vp3_ai_service_own_key_scope_v1($service, $provider, $model)) {
        throw new InvalidArgumentException('Choose a valid personal key service and model.');
    }
    $key = "vp3_ai_service_own_key_v1_{$owner}_{$service}";
    $id = $provider.':'.$model;
    $mysql = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
    $upsert = $mysql ? 'ON DUPLICATE KEY UPDATE setting_key=VALUES(setting_key)' : 'ON CONFLICT(setting_key) DO NOTHING';
    $pdo->beginTransaction();
    try {
        $pdo->prepare("INSERT INTO settings (setting_key,setting_value) VALUES (?,?) $upsert")->execute([$key, '[]']);
        $grants = vp3_ai_service_read_setting_v1($pdo, $key, true) ?? [];
        if ($approve) {
            $targets = vp3_ai_service_own_key_targets_v1($pdo, $user, $service);
            if (!isset($targets[$id])) throw new InvalidArgumentException('This personal key route is unavailable for your account.');
            $encrypted = vp3_ai_service_own_key_credential_v1($pdo, $owner, $provider);
            if ($encrypted === '' || ai_decrypt_secret($encrypted) === '') {
                throw new InvalidArgumentException('Save a working provider key before approving this service.');
            }
            $grants = array_intersect_key($grants, $targets);
            $grants[$id] = ['provider' => $provider, 'model' => $model, 'revision' => hash('sha256', $encrypted)];
        } else {
            unset($grants[$id]);
        }
        vp3_ai_service_write_setting_v1($pdo, $key, $grants);
        $pdo->commit();
    } catch (Throwable $error) {
        $pdo->rollBack();
        throw $error;
    }
}
