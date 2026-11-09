<?php
declare(strict_types=1);

const VP3_AI_SERVICE_CONFIG_KEY_V1 = 'vp3_ai_service_configuration_v1';
const VP3_AI_SERVICE_TOKEN_LIMITS_V1 = ['job_tokens', 'daily_tokens', 'monthly_tokens', 'background_daily_tokens', 'background_monthly_tokens'];

function vp3_ai_service_catalog_v1(): array
{
    $textProviders = ['openai', 'anthropic'];
    return [
        'conversation' => ['label' => 'Conversation', 'providers' => $textProviders, 'wired' => true],
        'initiative' => ['label' => 'Initiative', 'providers' => $textProviders, 'wired' => true],
        'chat_canvas' => ['label' => 'Chat Canvas', 'providers' => $textProviders, 'wired' => false],
        'image_generation' => ['label' => 'Image Generation', 'providers' => ['openai'], 'wired' => true],
        'code_build' => ['label' => 'Code Build', 'providers' => $textProviders, 'wired' => false],
        'code_repair' => ['label' => 'Code Repair', 'providers' => $textProviders, 'wired' => false],
        'eyes_vision' => ['label' => 'Agent Eyes Vision', 'providers' => $textProviders, 'wired' => false],
    ];
}

function vp3_ai_service_model_id_v1(mixed $model): bool
{
    return is_string($model) && preg_match('~\A[A-Za-z0-9][A-Za-z0-9._:/-]{0,119}\z~D', $model) === 1;
}

/** The existing settings table is available on both old and new installations. */
function vp3_ai_service_read_setting_v1(PDO $pdo, string $key, bool $forUpdate = false): ?array
{
    $lock = $forUpdate && $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '';
    try {
        $stmt = $pdo->prepare('SELECT setting_value FROM settings WHERE setting_key=?' . $lock);
        $stmt->execute([$key]);
        $raw = $stmt->fetchColumn();
    } catch (PDOException $error) {
        // Fixtures and installations before the settings schema have no service policy.
        if ($error->getCode() === '42S02' || ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite'
            && str_contains($error->getMessage(), 'no such table: settings'))) return null;
        throw $error;
    }
    if ($raw === false) return null;
    try {
        $value = json_decode((string)$raw, true, 12, JSON_THROW_ON_ERROR);
    } catch (JsonException $error) {
        throw new UnexpectedValueException('Stored AI service settings are invalid.', 0, $error);
    }
    if (!is_array($value)) throw new UnexpectedValueException('Stored AI service settings are invalid.');
    return $value;
}

function vp3_ai_service_write_setting_v1(PDO $pdo, string $key, array $value): void
{
    $upsert = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite'
        ? 'ON CONFLICT(setting_key) DO UPDATE SET setting_value=excluded.setting_value'
        : 'ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)';
    $encoded = json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (strlen($encoded) > 60_000) throw new InvalidArgumentException('Too many AI service settings are registered.');
    $pdo->prepare("INSERT INTO settings (setting_key,setting_value) VALUES (?,?) $upsert")->execute([$key, $encoded]);
}

function vp3_ai_service_config_v1(PDO $pdo, bool $forUpdate = false): array
{
    $empty = ['models' => [], 'routes' => []];
    $config = vp3_ai_service_read_setting_v1($pdo, VP3_AI_SERVICE_CONFIG_KEY_V1, $forUpdate) ?? $empty;
    if (!is_array($config['models'] ?? null) || !is_array($config['routes'] ?? null)) {
        throw new UnexpectedValueException('Stored AI service configuration is invalid.');
    }
    return $config;
}

/** Serialize inventory and route edits so a concurrent removal cannot orphan a new route. */
function vp3_ai_service_change_config_v1(PDO $pdo, callable $change): void
{
    $ownsTransaction = !$pdo->inTransaction();
    if ($ownsTransaction) $pdo->beginTransaction();
    try {
        $insert = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite'
            ? 'ON CONFLICT(setting_key) DO NOTHING' : 'ON DUPLICATE KEY UPDATE setting_key=VALUES(setting_key)';
        $pdo->prepare("INSERT INTO settings (setting_key,setting_value) VALUES (?,?) $insert")
            ->execute([VP3_AI_SERVICE_CONFIG_KEY_V1, '{"models":[],"routes":[]}']);
        $config = $change(vp3_ai_service_config_v1($pdo, true));
        vp3_ai_service_write_setting_v1($pdo, VP3_AI_SERVICE_CONFIG_KEY_V1, $config);
        if ($ownsTransaction) $pdo->commit();
    } catch (Throwable $error) {
        if ($ownsTransaction && $pdo->inTransaction()) $pdo->rollBack();
        throw $error;
    }
}

function vp3_ai_service_models_from_config_v1(array $config, string $service): array
{
    $definition = vp3_ai_service_catalog_v1()[$service] ?? null;
    if ($definition === null) return [];
    if ($service === 'image_generation') {
        $models = ['openai' => function_exists('vp3_image_models_v1') ? vp3_image_models_v1() : []];
    } else {
        $legacy = function_exists('ai_model_catalog') ? ai_model_catalog() : [];
        $models = array_intersect_key($legacy, array_fill_keys($definition['providers'], true));
    }
    foreach ($config['models'] as $record) {
        if (!is_array($record) || !in_array($record['provider'] ?? null, ['openai', 'anthropic'], true)
            || !vp3_ai_service_model_id_v1($record['model'] ?? null) || !is_string($record['label'] ?? null)
            || !is_array($record['services'] ?? null)) throw new UnexpectedValueException('Stored AI model is invalid.');
        $provider = $record['provider'];
        if (!in_array($provider, $definition['providers'], true)) continue;
        $model = $record['model'];
        if (in_array($service, $record['services'], true)) $models[$provider][$model] = $record['label'];
        else unset($models[$provider][$model]);
    }
    return $models;
}

function vp3_ai_service_models_v1(PDO $pdo, string $service): array
{
    return vp3_ai_service_models_from_config_v1(vp3_ai_service_config_v1($pdo), $service);
}

function vp3_ai_service_registered_models_v1(PDO $pdo): array
{
    return array_values(vp3_ai_service_config_v1($pdo)['models']);
}

function vp3_ai_service_save_model_v1(PDO $pdo, string $provider, string $model, string $label, array $services): void
{
    $catalog = vp3_ai_service_catalog_v1();
    $model = trim($model);
    $label = trim($label);
    if (!vp3_ai_service_model_id_v1($model) || $label === '' || mb_strlen($label) > 120
        || preg_match('/[\p{Cc}\p{Cf}]/u', $label) !== 0 || !$services || !array_is_list($services)) {
        throw new InvalidArgumentException('Enter a valid model ID, label, and service.');
    }
    foreach ($services as $service) {
        if (!is_string($service) || !in_array($provider, $catalog[$service]['providers'] ?? [], true)) {
            throw new InvalidArgumentException('That provider does not support the selected service.');
        }
    }
    $services = array_values(array_unique($services));
    vp3_ai_service_change_config_v1($pdo, static function (array $config) use ($provider, $model, $label, $services): array {
        $key = $provider . ':' . $model;
        if (!isset($config['models'][$key]) && count($config['models']) >= 100) {
            throw new InvalidArgumentException('The model inventory is full.');
        }
        $config['models'][$key] = compact('provider', 'model', 'label', 'services');
        foreach ($config['routes'] as $service => $route) {
            foreach (['primary', 'fallback'] as $slot) {
                if (($route[$slot]['provider'] ?? null) === $provider && ($route[$slot]['model'] ?? null) === $model
                    && !in_array($service, $services, true)) throw new InvalidArgumentException('A service route still uses this model.');
            }
        }
        return $config;
    });
}

function vp3_ai_service_remove_model_v1(PDO $pdo, string $provider, string $model): void
{
    if (!in_array($provider, ['openai', 'anthropic'], true) || !vp3_ai_service_model_id_v1($model)) {
        throw new InvalidArgumentException('Select a valid registered model.');
    }
    vp3_ai_service_change_config_v1($pdo, static function (array $config) use ($provider, $model): array {
        foreach ($config['routes'] as $route) {
            foreach (['primary', 'fallback'] as $slot) {
                if (($route[$slot]['provider'] ?? null) === $provider && ($route[$slot]['model'] ?? null) === $model) {
                    throw new InvalidArgumentException('A service route still uses this model.');
                }
            }
        }
        unset($config['models'][$provider . ':' . $model]);
        return $config;
    });
}

function vp3_ai_service_target_v1(array $target): array
{
    $provider = $target['provider'] ?? null;
    $model = $target['model'] ?? null;
    $funding = $target['funding'] ?? null;
    if (count($target) !== 3 || !in_array($provider, ['openai', 'anthropic'], true)
        || !vp3_ai_service_model_id_v1($model) || !in_array($funding, ['system', 'own_key'], true)) {
        throw new InvalidArgumentException('Select a provider, exact model, and credential source.');
    }
    return compact('provider', 'model', 'funding');
}

function vp3_ai_service_read_route_v1(PDO $pdo, string $service): array
{
    return vp3_ai_service_config_v1($pdo)['routes'][$service] ?? ['primary' => null, 'fallback' => null];
}

function vp3_ai_service_save_route_v1(PDO $pdo, string $service, ?array $primary, ?array $fallback): void
{
    if (!isset(vp3_ai_service_catalog_v1()[$service]) || ($primary === null && $fallback !== null)) {
        throw new InvalidArgumentException('Select a service and a primary model before saving a fallback.');
    }
    $primary = $primary !== null ? vp3_ai_service_target_v1($primary) : null;
    $fallback = $fallback !== null ? vp3_ai_service_target_v1($fallback) : null;
    if ($primary !== null && $primary === $fallback) throw new InvalidArgumentException('Choose a different fallback.');
    vp3_ai_service_change_config_v1($pdo, static function (array $config) use ($service, $primary, $fallback): array {
        if ($primary === null) {
            unset($config['routes'][$service]);
            return $config;
        }
        $models = vp3_ai_service_models_from_config_v1($config, $service);
        foreach ([$primary, $fallback] as $target) {
            if ($target !== null && !isset($models[$target['provider']][$target['model']])) {
                throw new InvalidArgumentException('Register the selected model for this service first.');
            }
        }
        $config['routes'][$service] = compact('primary', 'fallback');
        return $config;
    });
}

function vp3_ai_service_rule_key_v1(string $scope, int $id, string $service): string
{
    if (!isset(vp3_ai_service_catalog_v1()[$service])
        || !(($scope === 'site' && $id === 0) || (in_array($scope, ['package', 'user'], true) && $id > 0))) {
        throw new InvalidArgumentException('Select a valid service rule scope.');
    }
    return 'vp3_ai_rule_v1_' . $scope . '_' . $id . '_' . $service;
}

function vp3_ai_service_rule_v1(array $input): array
{
    $rule = [];
    foreach ($input as $key => $value) {
        if (in_array($key, ['enabled', 'background_enabled'], true) && is_bool($value)) {
            $rule[$key] = $value;
        } elseif (in_array($key, VP3_AI_SERVICE_TOKEN_LIMITS_V1, true) && is_int($value) && $value >= 0 && $value <= 1_000_000_000) {
            $rule[$key] = $value;
        } elseif (in_array($key, ['allowed_models', 'allowed_funding'], true) && is_array($value)
            && array_is_list($value) && count($value) <= 100) {
            foreach ($value as $entry) {
                if (!is_string($entry)) throw new InvalidArgumentException('An AI service allowlist entry is invalid.');
                if ($key === 'allowed_funding') {
                    if (!in_array($entry, ['system', 'own_key'], true)) throw new InvalidArgumentException('A credential source is invalid.');
                } else {
                    [$provider, $model] = array_pad(explode(':', $entry, 2), 2, '');
                    if (!in_array($provider, ['openai', 'anthropic'], true) || !vp3_ai_service_model_id_v1($model)) {
                        throw new InvalidArgumentException('A model allowlist entry is invalid.');
                    }
                }
            }
            $rule[$key] = array_values(array_unique($value));
        } else {
            throw new InvalidArgumentException('An AI service rule is invalid.');
        }
    }
    return $rule;
}

function vp3_ai_service_read_rule_v1(PDO $pdo, string $scope, int $id, string $service): array
{
    $value = vp3_ai_service_read_setting_v1($pdo, vp3_ai_service_rule_key_v1($scope, $id, $service));
    if ($value === null) return [];
    if (!is_array($value['rule'] ?? null)) throw new UnexpectedValueException('Stored AI service rules are invalid.');
    return vp3_ai_service_rule_v1($value['rule']);
}

function vp3_ai_service_save_rule_v1(PDO $pdo, string $scope, int $id, string $service, array $rule, ?int $actorId = null): void
{
    $key = vp3_ai_service_rule_key_v1($scope, $id, $service);
    $rule = vp3_ai_service_rule_v1($rule);
    if ($scope !== 'site') {
        $table = $scope === 'package' ? 'subscription_packages' : 'users';
        $stmt = $pdo->prepare("SELECT 1 FROM $table WHERE id=?");
        $stmt->execute([$id]);
        if (!$stmt->fetchColumn()) throw new InvalidArgumentException('The selected account or package was not found.');
    }
    vp3_ai_service_write_setting_v1($pdo, $key, [
        'rule' => $rule, 'actor_id' => $actorId, 'updated_at' => gmdate(DATE_ATOM),
    ]);
}

function vp3_ai_service_target_allowed_v1(array $policy, array $target): bool
{
    if (($policy['enabled'] ?? null) !== true) return false;
    try {
        $target = vp3_ai_service_target_v1($target);
    } catch (InvalidArgumentException) {
        return false;
    }
    if (!in_array($target['provider'], vp3_ai_service_catalog_v1()[$policy['service'] ?? '']['providers'] ?? [], true)) return false;
    $models = $policy['allowed_models'] ?? null;
    $funding = $policy['allowed_funding'] ?? null;
    return ($models === null || in_array($target['provider'] . ':' . $target['model'], $models, true))
        && ($funding === null || in_array($target['funding'], $funding, true));
}

/** Site and package restrictions remain upper bounds for account overrides. */
function vp3_ai_service_policy_v1(PDO $pdo, ?array $user, string $service, bool $background = false): array
{
    $policy = [
        'service' => $service, 'configured' => false, 'enabled' => true, 'reason' => '',
        'primary' => null, 'fallback' => null, 'background_enabled' => true,
        'allowed_models' => null, 'allowed_funding' => null,
    ] + array_fill_keys(VP3_AI_SERVICE_TOKEN_LIMITS_V1, null);
    if (!isset(vp3_ai_service_catalog_v1()[$service])) {
        return array_replace($policy, ['enabled' => false, 'reason' => 'service_unsupported']);
    }
    try {
        $config = vp3_ai_service_config_v1($pdo);
        $policy['configured'] = array_key_exists($service, $config['routes']);
        $rules = [vp3_ai_service_read_rule_v1($pdo, 'site', 0, $service)];
        $uid = (int)($user['id'] ?? 0);
        if ($uid > 0) {
            if (function_exists('subscription_current_for_user_id')) {
                $subscription = subscription_current_for_user_id($uid, $pdo);
                $packageId = (int)($subscription['package_id'] ?? 0);
                if ($packageId > 0) $rules[] = vp3_ai_service_read_rule_v1($pdo, 'package', $packageId, $service);
            }
            $rules[] = vp3_ai_service_read_rule_v1($pdo, 'user', $uid, $service);
        }
        foreach ($rules as $rule) {
            foreach ($rule as $key => $value) {
                if (is_bool($value)) $policy[$key] = $policy[$key] && $value;
                elseif ($policy[$key] === null) $policy[$key] = $value;
                elseif (is_array($value)) $policy[$key] = array_values(array_intersect($policy[$key], $value));
                else $policy[$key] = min($policy[$key], $value);
            }
        }
        if (!$policy['enabled']) {
            $policy['reason'] = 'service_disabled';
            return $policy;
        }
        if ($background && !$policy['background_enabled']) {
            return array_replace($policy, ['enabled' => false, 'reason' => 'background_disabled']);
        }
        foreach (VP3_AI_SERVICE_TOKEN_LIMITS_V1 as $limit) {
            if ($policy[$limit] === 0 && ($background || !str_starts_with($limit, 'background_'))) {
                return array_replace($policy, ['enabled' => false, 'reason' => 'service_budget_exhausted']);
            }
        }
        if ($policy['allowed_models'] === [] || $policy['allowed_funding'] === []) {
            return array_replace($policy, ['enabled' => false, 'reason' => 'service_model_forbidden']);
        }
        if ($policy['configured']) {
            $route = $config['routes'][$service];
            if (!is_array($route['primary'] ?? null)) throw new UnexpectedValueException('Stored AI service route is invalid.');
            $models = vp3_ai_service_models_from_config_v1($config, $service);
            foreach (['primary', 'fallback'] as $slot) {
                if (($route[$slot] ?? null) === null) continue;
                if (!is_array($route[$slot])) throw new UnexpectedValueException('Stored AI service route is invalid.');
                $target = vp3_ai_service_target_v1($route[$slot]);
                if (isset($models[$target['provider']][$target['model']]) && vp3_ai_service_target_allowed_v1($policy, $target)) {
                    $policy[$slot] = $target;
                }
            }
            if ($policy['primary'] === null && $policy['fallback'] === null) {
                return array_replace($policy, ['enabled' => false, 'reason' => 'service_model_forbidden']);
            }
        }
        return $policy;
    } catch (PDOException | UnexpectedValueException | InvalidArgumentException) {
        return array_replace($policy, ['enabled' => false, 'reason' => 'service_policy_unavailable', 'primary' => null, 'fallback' => null]);
    }
}
