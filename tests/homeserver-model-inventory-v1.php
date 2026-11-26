<?php
declare(strict_types=1);
require_once __DIR__.'/../includes/homeserver-model-inventory-v1.php';

function check_inventory(bool $condition, string $label): void {
    if (!$condition) throw new RuntimeException($label);
}
$raw = [
    'version' => 'vp3.local-model-inventory.v1', 'provider' => 'ollama', 'enabled' => true,
    'configured_model' => 'qwen:latest', 'discovery_state' => 'reported',
    'configured_model_reported' => false, 'truncated' => false,
    'models' => [
        ['model' => 'qwen:latest', 'configured_default' => false, 'locality' => 'local', 'execution_verified' => true, 'routing_eligible' => true, 'secret' => 'private'],
        ['model' => 'vendor/model:cloud', 'routing_eligible' => true],
    ], 'base_url' => 'http://127.0.0.1:11434',
];
$view = vp3_homeserver_model_inventory_v1($raw);
check_inventory($view['state'] === 'reported' && $view['configured_model_reported'] && count($view['models']) === 2, 'reported inventory');
check_inventory($view['models'][0]['configured_default'] && !$view['models'][1]['configured_default'], 'recompute default');
foreach ($view['models'] as $model) {
    check_inventory($model['locality'] === 'unverified' && !$model['execution_verified'] && !$model['routing_eligible'], 'never trust remote eligibility');
}
check_inventory(!str_contains(json_encode($view), 'private') && !str_contains(json_encode($view), '127.0.0.1'), 'strip private metadata');
foreach ([null, [], ['version' => 'v2']] as $legacy) {
    check_inventory(vp3_homeserver_model_inventory_v1($legacy)['state'] === 'unsupported', 'legacy version');
}
foreach (['disabled', 'unreachable', 'invalid_response'] as $state) {
    $empty = array_replace($raw, ['discovery_state' => $state, 'enabled' => $state !== 'disabled', 'models' => []]);
    $result = vp3_homeserver_model_inventory_v1($empty);
    check_inventory($result['state'] === $state && $result['models'] === [] && !$result['configured_model_reported'], 'empty state '.$state);
}
foreach ([
    ['enabled' => 1], ['provider' => 'openai'], ['truncated' => 'false'],
    ['discovery_state' => 'disabled'], ['discovery_state' => 'unknown'],
    ['models' => array_fill(0, 257, ['model' => 'qwen'])],
    ['models' => [['model' => 'https://host/model']]],
    ['models' => [['model' => 'a/../b']]],
    ['models' => [['model' => 'qwen'], ['model' => 'qwen']]],
    ['models' => ['not-an-object']], ['models' => ['named' => ['model' => 'qwen']]],
    ['configured_model' => 'a//b'], ['configured_model' => []],
] as $bad) {
    $result = vp3_homeserver_model_inventory_v1(array_replace($raw, $bad));
    check_inventory($result['state'] === 'invalid_response' && $result['models'] === [] && !$result['enabled'], 'malformed fails closed');
}
$bounded = array_replace($raw, ['truncated' => true, 'models' => array_map(fn($i) => ['model' => 'model'.$i], range(1, 256))]);
$result = vp3_homeserver_model_inventory_v1($bounded);
check_inventory($result['truncated'] && count($result['models']) === 256 && !$result['configured_model_reported'], 'bounded inventory');
// PHP numeric array keys must not change the string model identifier.
$result = vp3_homeserver_model_inventory_v1(array_replace($raw, ['configured_model' => '123', 'models' => [['model' => '123']]]));
check_inventory($result['models'][0]['model'] === '123' && $result['configured_model_reported'], 'numeric identifier preserved');
echo "HomeServer model inventory contract passed\n";
