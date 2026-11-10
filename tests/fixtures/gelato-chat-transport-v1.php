<?php
declare(strict_types=1);

// Test-only credentials for the capability harness, which replaces AI settings.
function ai_master_key(bool $create = false): string { return str_repeat('k', 32); }
function ai_encrypt_secret_with_key(string $value, string $key): string
{
    return hash_hmac('sha256', $value, $key).$value;
}
function ai_decrypt_secret_with_key(string $ciphertext, string $key): string
{
    $value = substr($ciphertext, 64);
    return hash_equals(ai_encrypt_secret_with_key($value, $key), $ciphertext) ? $value : '';
}

/** Wire fixture installed only in the temporary HTTP server's copy. */
function vp3_gelato_transport_v1(string $origin, ?callable $resolver = null): Closure
{
    if ($origin !== 'https://kitchen.example.com') throw new RuntimeException('Unexpected test origin.');
    return static function (?array $body = null, ?string $bearer = null): array {
        $instance = '24a90254-4541-4130-8977-e5a271eb41f0';
        $discovery = ['ok'=>true, 'schema'=>'food-monster-vp3-v1', 'instanceId'=>$instance,
            'capabilities'=>VP3_GELATO_OPERATIONS_V1, 'pairing'=>'admin-issued-one-time-code', 'authentication'=>'bearer'];
        if ($body === null && $bearer === null) return $discovery;
        $token = 'fm2_'.str_repeat('b', 43);
        $remoteId = str_repeat('c', 32);
        $agent = 'Fixture Kitchen Agent';
        $location = ['id'=>71, 'name'=>'Fixture Phoenix', 'timezone'=>'America/Phoenix'];
        if ($body === ['action'=>'exchange','code'=>'fmlink_'.str_repeat('d',43)] && $bearer === null) {
            return $discovery + ['connectionId'=>$remoteId, 'agentName'=>$agent, 'tokenType'=>'Bearer',
                'accessToken'=>$token, 'locations'=>[$location], 'scopes'=>['sales.daily.read', 'tickets.open.read']];
        }
        file_put_contents(dirname(__DIR__).'/gelato-chat-reads.jsonl', json_encode($body, JSON_THROW_ON_ERROR)."\n", FILE_APPEND);
        $action = $body['action'] ?? '';
        if ($bearer !== $token || $body !== ['action'=>$action,'locationId'=>71]) {
            throw new RuntimeException('Unexpected test read.');
        }
        [$path, $data] = match ($action) {
            'sales.daily.read' => ['/sales-intelligence.php', [
                'businessDate'=>'2026-11-10', 'available'=>true, 'currency'=>'USD', 'totals'=>['netSales'=>'987.65'],
            ]],
            'tickets.open.read' => ['/pos.php', [
                'tickets'=>[['checkNumber'=>'F-17', 'customerName'=>'PRIVATE_GELATO_CUSTOMER']], 'truncated'=>false,
            ]],
            default => throw new RuntimeException('Unapproved fixture read.'),
        };
        return ['ok'=>true, 'schema'=>'food-monster-vp3-operations-v1', 'instanceId'=>$instance,
            'connectionId'=>$remoteId, 'agentName'=>$agent, 'capability'=>$action, 'location'=>$location,
            'asOf'=>'2026-11-10T22:00:00Z', 'source'=>['label'=>'Fixture Gelato', 'path'=>$path], 'data'=>$data];
    };
}
