<?php
declare(strict_types=1);

require_once __DIR__.'/ai-service-policy-v1.php';
require_once __DIR__.'/ai-service-usage-v1.php';
require_once __DIR__.'/user-llm-v1.php';

function vp3_coding_canvas_model_admin_state_v1(PDO $pdo): array
{
    $config = vp3_ai_service_config_v1($pdo);
    $catalog = vp3_ai_service_coding_catalog_v1($config);
    $builtins = vp3_ai_service_coding_builtin_models_v1();
    $enabled = $catalog['enabled'] ?? [];
    $default = $catalog['default'] ?? null;
    if ($catalog === null) {
        $legacy = vp3_ai_service_coding_models_v1($config);
        foreach ($builtins as $key => $model) {
            if (isset($legacy[$model['provider']][$model['model']])) $enabled[] = $key;
        }
        if (!$enabled && isset($builtins[VP3_AI_CODING_DEFAULT_MODEL_V1])) $enabled[] = VP3_AI_CODING_DEFAULT_MODEL_V1;
        $default = in_array(VP3_AI_CODING_DEFAULT_MODEL_V1, $enabled, true) ? VP3_AI_CODING_DEFAULT_MODEL_V1 : ($enabled[0] ?? null);
        foreach (VP3_AI_CODING_SERVICES_V1 as $service) {
            if (!isset($config['routes'][$service])) continue;
            foreach (vp3_ai_service_stored_route_v1($config['routes'][$service]) as $target) {
                if ($target === null) continue;
                $key = $target['provider'].':'.$target['model'];
                if (in_array($key, $enabled, true)) {
                    $default = $key;
                    break 2;
                }
            }
        }
    }
    $options = [];
    foreach ($builtins as $key => $target) $options[] = $target + ['enabled'=>in_array($key, $enabled, true)];
    return ['options'=>$options, 'default'=>$default === null ? null : ($builtins[$default] ?? null)];
}

function vp3_coding_canvas_model_admin_save_v1(PDO $pdo, array $enabled, ?string $default): void
{
    $builtins = vp3_ai_service_coding_builtin_models_v1();
    if (!array_is_list($enabled) || count($enabled) > count($builtins)) {
        throw new InvalidArgumentException('Choose models from the Coding Agent list.');
    }
    foreach ($enabled as $key) {
        if (!is_string($key) || !isset($builtins[$key])) throw new InvalidArgumentException('Choose models from the Coding Agent list.');
    }
    if ($default === null || !in_array($default, $enabled, true)) {
        throw new InvalidArgumentException('Enable at least one Coding Agent model and choose an enabled default.');
    }
    $enabled = array_values(array_unique($enabled));
    vp3_ai_service_change_config_v1($pdo, static function (array $config) use ($enabled, $default): array {
        $config['coding_catalog'] = compact('enabled', 'default');
        foreach (VP3_AI_CODING_SERVICES_V1 as $service) {
            if (!isset($config['routes'][$service])) continue;
            $route = vp3_ai_service_stored_route_v1($config['routes'][$service]);
            foreach ($route as $slot => $target) {
                if ($target !== null && !in_array($target['provider'].':'.$target['model'], $enabled, true)) $route[$slot] = null;
            }
            if ($route['primary'] === null && $route['fallback'] !== null) {
                $route = ['primary'=>$route['fallback'], 'fallback'=>null];
            }
            if ($route['primary'] === null) unset($config['routes'][$service]);
            else $config['routes'][$service] = $route;
        }
        return $config;
    });
}

function vp3_coding_canvas_model_policies_v1(PDO $pdo, array $user, bool $background = false): array
{
    $policies = [];
    foreach (VP3_AI_CODING_SERVICES_V1 as $service) {
        $policies[$service] = vp3_ai_service_policy_v1($pdo, $user, $service, $background);
    }
    return $policies;
}

function vp3_coding_canvas_model_provider_key_v1(PDO $pdo, int $owner, array $funding, string $provider): string
{
    if ($funding['route'] === 'own_key') {
        return vp3_user_llm_v1_saved_key($pdo, $owner, $provider);
    }
    return ai_provider_enabled($provider) ? ai_provider_api_key($provider) : '';
}

function vp3_coding_canvas_model_preference_key_v1(int $owner): string
{
    if ($owner < 1) throw new RuntimeException('A signed-in account is required.', 403);
    return 'vp3_coding_model_v1_'.$owner;
}

/** Public settings contain model identities and account funding mode. */
function vp3_coding_canvas_model_state_v1(PDO $pdo, array $user): array
{
    $owner = (int)($user['id'] ?? 0);
    $preference = vp3_ai_service_read_setting_v1($pdo, vp3_coding_canvas_model_preference_key_v1($owner));
    $selected = $preference ? vp3_ai_service_target_v1($preference) : null;
    $funding = vp3_user_llm_v1_preference($pdo, $owner);
    $policies = vp3_coding_canvas_model_policies_v1($pdo, $user);
    $config = vp3_ai_service_config_v1($pdo);
    $catalog = vp3_ai_service_coding_catalog_v1($config);
    $models = vp3_ai_service_coding_models_v1($config);
    $options = [];
    foreach ($models as $provider => $byModel) {
        if (!$byModel || vp3_coding_canvas_model_provider_key_v1($pdo, $owner, $funding, $provider) === '') continue;
        foreach ($byModel as $model => $label) {
            $target = ['provider' => $provider, 'model' => (string)$model];
            if (vp3_ai_service_coding_target_allowed_v1($models, $policies, $target)) {
                $options[$provider.':'.$model] = $target + ['label' => $label];
            }
        }
    }
    ksort($options, SORT_STRING);
    $default = $options[$catalog['default'] ?? ''] ?? null;
    if ($catalog === null) {
        foreach ($policies as $policy) {
            foreach ([$policy['primary'], $policy['fallback']] as $target) {
                if ($target !== null && isset($options[$target['provider'].':'.$target['model']])) {
                    $default = $options[$target['provider'].':'.$target['model']];
                    break 2;
                }
            }
        }
    }
    if ($default === null && $funding['route'] === 'own_key') {
        foreach ($options as $option) {
            if ($option['provider'] === $funding['provider']) {
                $default = $option;
                break;
            }
        }
    }
    $default ??= $options ? reset($options) : null;
    $effective = $selected === null ? $default : ($options[$selected['provider'].':'.$selected['model']] ?? null);
    $blocked = array_column($policies, 'reason');
    $unavailable = match (true) {
        $effective !== null => '',
        in_array('service_policy_unavailable', $blocked, true) => 'Coding Agent policy is temporarily unavailable.',
        in_array('service_budget_exhausted', $blocked, true) => 'The Coding Agent token budget is exhausted.',
        in_array('service_disabled', $blocked, true) => 'Coding Agent is unavailable for this account.',
        $selected !== null && $options !== [] => 'Your saved coding model is unavailable. Choose an available model.',
        !array_filter($models) => 'Choose Coding Agent models in Admin → AI / API.',
        default => 'No coding model is available for your account funding and access.',
    };
    return [
        'funding' => $funding['route'],
        'selected' => $selected, 'default' => $default, 'effective' => $effective,
        'options' => array_values($options), 'unavailable' => $unavailable,
    ];
}

function vp3_coding_canvas_model_save_v1(PDO $pdo, array $user, ?array $target): array
{
    $owner = (int)($user['id'] ?? 0);
    $preferenceKey = vp3_coding_canvas_model_preference_key_v1($owner);
    if ($target !== null) $target = vp3_ai_service_target_v1($target);
    $pdo->beginTransaction();
    try {
        vp3_ai_service_usage_lock_owner_v1($pdo, $owner);
        if ($target !== null) {
            $funding = vp3_user_llm_v1_preference($pdo, $owner);
            $pin = $target + ['funding' => $funding['route']];
            vp3_coding_canvas_model_authorized_v1($pdo, $user, $pin, 'code_build');
        }
        vp3_ai_service_write_setting_v1($pdo, $preferenceKey, $target ?? []);
        $state = vp3_coding_canvas_model_state_v1($pdo, $user);
        $pdo->commit();
        return $state;
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $error;
    }
}

function vp3_coding_canvas_model_resolve_v1(PDO $pdo, array $user): array
{
    $state = vp3_coding_canvas_model_state_v1($pdo, $user);
    $model = $state['effective'];
    if ($model === null) throw new RuntimeException($state['unavailable'], 409);
    return ['provider' => $model['provider'], 'model' => $model['model'], 'funding' => $state['funding']];
}

/** Recheck a pinned model against current account funding and both Admin service policies. */
function vp3_coding_canvas_model_authorized_v1(PDO $pdo, array $user, array $pin, string $service, bool $background = false): array
{
    $owner = (int)($user['id'] ?? 0);
    if ($owner < 1 || !in_array($service, VP3_AI_CODING_SERVICES_V1, true)) {
        throw new RuntimeException('Coding Agent authorization is unavailable.', 403);
    }
    $target = vp3_ai_service_target_v1(['provider' => $pin['provider'] ?? null, 'model' => $pin['model'] ?? null]);
    $funding = vp3_user_llm_v1_preference($pdo, $owner);
    if ($funding['route'] !== ($pin['funding'] ?? null)) {
        throw new RuntimeException('Your account funding has changed. Start a new coding run.', 409);
    }
    $policies = vp3_coding_canvas_model_policies_v1($pdo, $user, $background);
    foreach ($policies as $policy) {
        if ($policy['enabled']) continue;
        throw match ($policy['reason']) {
            'service_budget_exhausted' => new RuntimeException('The Coding Agent token budget is exhausted.', 402),
            'service_policy_unavailable' => new RuntimeException('Coding Agent policy is temporarily unavailable.', 503),
            default => new RuntimeException('Coding Agent is unavailable for this account.', 403),
        };
    }
    $models = vp3_ai_service_coding_models_v1(vp3_ai_service_config_v1($pdo));
    if (!vp3_ai_service_coding_target_allowed_v1($models, $policies, $target)) {
        throw new RuntimeException('This coding model is unavailable for the account.', 403);
    }
    $key = vp3_coding_canvas_model_provider_key_v1($pdo, $owner, $funding, $target['provider']);
    if ($key === '') throw new RuntimeException('This coding model is unavailable with your account funding.', 409);
    return ['policy' => $policies[$service], 'key' => $key];
}
