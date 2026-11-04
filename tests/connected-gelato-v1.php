<?php
declare(strict_types=1);

function gelato_cloud_check(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

function gelato_cloud_reject(callable $call, int $code, string $message): void
{
    try {
        $call();
    } catch (InvalidArgumentException | RuntimeException $e) {
        gelato_cloud_check($e->getCode() === $code, $message.': '.$e->getMessage());
        return;
    }
    throw new RuntimeException($message);
}

function db(): ?PDO
{
    return $GLOBALS['gelato_cloud_test_pdo'] ?? null;
}

function setting(string $key, mixed $default = null): mixed
{
    return $default;
}

$testRoot = sys_get_temp_dir().'/vp3-gelato-'.bin2hex(random_bytes(8));
mkdir($testRoot, 0700);
define('STONEFELLOW_ROOT', $testRoot);
register_shutdown_function(static function () use ($testRoot): void {
    foreach (glob($testRoot.'/private/*') ?: [] as $path) unlink($path);
    if (is_dir($testRoot.'/private')) rmdir($testRoot.'/private');
    rmdir($testRoot);
});
require_once dirname(__DIR__).'/includes/connected-gelato-v1.php';

$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$GLOBALS['gelato_cloud_test_pdo'] = $pdo;
$pdo->exec('PRAGMA foreign_keys=ON');
$pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY)');
$pdo->exec('CREATE TABLE settings (setting_key VARCHAR(200),setting_value TEXT)');
$pdo->exec('INSERT INTO users VALUES (11),(12),(13)');
gelato_cloud_check(vp3_gelato_connections_v1($pdo, 11) === [], 'Missing schema should leave Chat available.');
vp3_gelato_schema_v1($pdo);
vp3_gelato_schema_v1($pdo);
gelato_cloud_check(vp3_gelato_schema_ready_v1($pdo), 'Upgrade schema must be ready and idempotent.');

gelato_cloud_check(vp3_gelato_origin_v1('https://Gelato.Example.COM/') === 'https://gelato.example.com', 'Origin normalization failed.');
foreach (['http://gelato.example.com','https://gelato.example.com/pos.php','https://gelato.example.com?redirect=/',
          'https://gelato.example.com:443','https://gelato.example.com@internal.example.com','https://127.0.0.1',
          'https://[::1]','https://kitchen.home.arpa','https://site.internal','https://host%2eexample.com'] as $unsafe) {
    gelato_cloud_reject(fn()=>vp3_gelato_origin_v1($unsafe), 0, 'Non-public or non-origin URL accepted');
}
foreach (['127.0.0.1','10.0.0.2','100.64.0.1','169.254.169.254','192.0.2.4','224.0.0.1',
          '::1','::ffff:10.0.0.1','2001:0:4136:e378:8000:63bf:3fff:fdd2'] as $unsafe) {
    gelato_cloud_reject(fn()=>vp3_gelato_transport_v1('https://gelato.example.com',
        static fn(string $host): array => [['ip'=>'1.1.1.1'], [str_contains($unsafe, ':') ? 'ipv6' : 'ip'=>$unsafe]]),
        422, 'Unsafe DNS answer accepted alongside a public answer');
}
gelato_cloud_reject(fn()=>vp3_gelato_transport_v1('https://gelato.example.com', fn()=>[]), 502, 'Missing DNS unexpectedly allowed.');
gelato_cloud_check(vp3_gelato_transport_v1('https://gelato.example.com', fn()=>[['ip'=>'1.1.1.1']]) instanceof Closure,
    'Public DNS should be accepted without issuing a request during transport creation.');

$instance = '24a90254-4541-4130-8977-e5a271eb41f0';
$remote = str_repeat('c', 32);
$secret = 'fm2_'.str_repeat('b', 43);
$code = 'fmlink_'.str_repeat('d', 43);
$scopes = ['menu','orders.read','sales.daily.read'];
$location = ['id'=>71,'name'=>'Phoenix','timezone'=>'America/Phoenix'];
$origin = 'https://gelato.example.com';
$discover = ['ok'=>true,'schema'=>'food-monster-vp3-v1','instanceId'=>$instance,
    'capabilities'=>array_merge(['knowledge.search'], VP3_GELATO_OPERATIONS_V1),
    'pairing'=>'admin-issued-one-time-code','authentication'=>'bearer'];
$exchange = ['ok'=>true,'schema'=>'food-monster-vp3-v1','instanceId'=>$instance,'connectionId'=>$remote,
    'tokenType'=>'Bearer','accessToken'=>$secret,'scopes'=>$scopes,'agentName'=>'Food Monster','locations'=>[$location]];
$revoke = ['ok'=>true,'schema'=>'food-monster-vp3-v1','instanceId'=>$instance,'connectionId'=>$remote,'revoked'=>true];
$orders = ['ok'=>true,'schema'=>'food-monster-vp3-operations-v1','instanceId'=>$instance,'connectionId'=>$remote,
    'agentName'=>'Food Monster','capability'=>'orders.read','location'=>$location,'asOf'=>'2026-11-04T01:00:00+00:00',
    'source'=>['label'=>'Pickup Fulfillment','path'=>'/pickup-fulfillment.php'],'data'=>['orders'=>[],'truncated'=>false]];

$posts = [];
$request = static function (?array $body = null, ?string $token = null) use (
    $discover, $exchange, $revoke, $orders, $secret, $code, &$posts
): array {
    if ($body === null) {
        gelato_cloud_check($token === null, 'Discovery received a private credential.');
        return $discover;
    }
    $posts[] = $body['action'] ?? '';
    if ($body['action'] === 'exchange') {
        gelato_cloud_check($token === null && $body === ['action'=>'exchange','code'=>$code], 'Pairing request leaked authority.');
        return $exchange;
    }
    gelato_cloud_check($token === $secret, 'The stored credential did not match the paired token.');
    if ($body['action'] === 'revoke') return $revoke;
    if ($body['action'] === 'search') {
        gelato_cloud_check($body === ['action'=>'search','q'=>'margherita','type'=>'menu','limit'=>10], 'Wrong search parameters.');
        return ['ok'=>true,'schema'=>'food-monster-knowledge-v1','readOnly'=>true,'results'=>[['type'=>'menu']]];
    }
    gelato_cloud_check($body === ['action'=>'orders.read','locationId'=>71], 'An unverified field reached Gelato.');
    return $orders;
};

$connection = vp3_gelato_connect_v1($pdo, 11, $origin, $code, 'Local nickname', $request);
$id = $connection['id'];
gelato_cloud_check($connection['agentName'] === 'Food Monster' && $connection['label'] === 'Local nickname'
    && $connection['locations'] === [$location], 'Pairing metadata was not preserved.');
gelato_cloud_check(!str_contains(json_encode($connection), $secret) && !str_contains(json_encode($connection), $remote),
    'Connection projection exposed a credential or source grant ID.');
$stored = vp3_gelato_connection_v1($pdo, 11, $id);
gelato_cloud_check($stored['token_ciphertext'] !== $secret && vp3_gelato_token_v1($stored) === $secret,
    'Bearer was not persisted encrypted.');
gelato_cloud_check(ai_saved_encrypted_credentials_exist(), 'Gelato ciphertext is missing from the shared key-loss guard.');
gelato_cloud_reject(fn()=>vp3_gelato_token_v1(array_replace($stored, ['owner_user_id'=>12])), 503,
    'Copied ciphertext decrypted under a different owner.');
gelato_cloud_check(vp3_gelato_connections_v1($pdo, 11) === [$connection] && vp3_gelato_connections_v1($pdo, 12) === [],
    'Per-user connection isolation failed.');
gelato_cloud_reject(fn()=>vp3_gelato_connect_v1($pdo, 11, $origin, $code, '', $request), 409,
    'Duplicate source accepted.');
gelato_cloud_reject(fn()=>vp3_gelato_operation_v1($pdo, 12, $id, 'orders.read', 71, null, $request), 404,
    'Another VP3 user accessed a private Gelato connection.');
gelato_cloud_reject(fn()=>vp3_gelato_operation_v1($pdo, 11, $id, 'orders.read', 72, null, $request), 403,
    'An ungranted location was accessed.');
gelato_cloud_reject(fn()=>vp3_gelato_operation_v1($pdo, 11, $id, 'tickets.open.read', 71, null, $request), 403,
    'An ungranted operation was accessed.');
gelato_cloud_reject(fn()=>vp3_gelato_operation_v1($pdo, 11, $id, 'sales.daily.read', 71, '2026-02-30', $request), 0,
    'An invalid business date was sent.');
gelato_cloud_reject(fn()=>vp3_gelato_operation_v1($pdo, 11, $id, 'orders.create', 71, null, $request), 0,
    'A write action was sent.');
gelato_cloud_check($posts === ['exchange'], 'Rejected requests sent a remote POST.');
gelato_cloud_check(vp3_gelato_operation_v1($pdo, 11, $id, 'orders.read', 71, null, $request) === $orders,
    'The verified operational result could not be read.');
gelato_cloud_check(vp3_gelato_search_v1($pdo, 11, $id, 'margherita', 'menu', 10, $request)['results'] === [['type'=>'menu']],
    'The existing authorized knowledge endpoint could not be used.');
gelato_cloud_reject(fn()=>vp3_gelato_search_v1($pdo, 11, $id, 'secret recipe', 'recipe', 10, $request), 403,
    'A search bypassed approved knowledge scope.');

$spoof = static function (?array $body = null, ?string $token = null) use ($discover): array {
    gelato_cloud_check($body === null && $token === null, 'Changed instance received a bearer.');
    return array_replace($discover, ['instanceId'=>'e2425066-4541-4130-8977-e5a271eb41f0']);
};
gelato_cloud_reject(fn()=>vp3_gelato_operation_v1($pdo, 11, $id, 'orders.read', 71, null, $spoof), 502,
    'Changed remote instance was trusted.');
foreach (['instanceId'=>'wrong','connectionId'=>'wrong','agentName'=>'Different Agent','capability'=>'tickets.open.read',
    'location'=>array_replace($location, ['id'=>72]),'source'=>['label'=>'unsafe','path'=>'//evil.example/']] as $key=>$value) {
    $wrong = static fn(?array $body=null): array => $body === null ? $discover : array_replace($orders, [$key=>$value]);
    gelato_cloud_reject(fn()=>vp3_gelato_operation_v1($pdo, 11, $id, 'orders.read', 71, null, $wrong), 502,
        'Mismatched remote operation accepted.');
}
$wrongName = static function (?array $body = null, ?string $token = null) use ($discover, $exchange, $revoke, $secret): array {
    if ($body === null) return $discover;
    if ($body['action'] === 'exchange') return array_replace($exchange, ['agentName'=>'']);
    gelato_cloud_check($body === ['action'=>'revoke'] && $token === $secret, 'Invalid exchange was not cleaned up.');
    return $revoke;
};
gelato_cloud_reject(fn()=>vp3_gelato_connect_v1($pdo, 12, $origin, $code, '', $wrongName), 502,
    'Unnamed agent was persisted.');

gelato_cloud_reject(fn()=>vp3_gelato_disconnect_v1($pdo, 12, $id, $request), 404, 'Another user revoked a connection.');
$midRead = static function (?array $body=null, ?string $token=null) use ($pdo, $id, $request, $orders, $discover): array {
    if ($body === null) return $discover;
    gelato_cloud_check(vp3_gelato_disconnect_v1($pdo, 11, $id, $request), 'Remote revocation receipt was not verified.');
    return $orders;
};
gelato_cloud_reject(fn()=>vp3_gelato_operation_v1($pdo, 11, $id, 'orders.read', 71, null, $midRead), 404,
    'An in-flight reply survived local revocation.');
gelato_cloud_check(vp3_gelato_connections_v1($pdo, 11) === [], 'Revoked credential remained linked.');

$other = vp3_gelato_connect_v1($pdo, 12, $origin, $code, '', $request);
$failedRevoke = static fn(?array $body=null): array => $body === null ? $discover : array_replace($revoke, ['connectionId'=>'wrong']);
gelato_cloud_check(!vp3_gelato_disconnect_v1($pdo, 12, $other['id'], $failedRevoke)
    && vp3_gelato_connections_v1($pdo, 12) === [], 'Failed remote revoke did not clear the local secret or reported success.');
$final = vp3_gelato_connect_v1($pdo, 13, $origin, $code, '', $request);
$pdo->exec('DELETE FROM users WHERE id=13');
gelato_cloud_check(vp3_gelato_connections_v1($pdo, 13) === [], 'Account deletion retained the connection credential.');
echo "Connected Gelato security and lifecycle checks passed.\n";
