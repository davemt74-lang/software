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

function table_exists(string $table): bool
{
    $pdo = db();
    return $pdo !== null && schema_metadata_table_exists($pdo, $table);
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
require_once dirname(__DIR__).'/includes/schema-metadata.php';

$mysqlDsn = getenv('VP3_TEST_MYSQL_DSN');
$pdo = new PDO($mysqlDsn ?: 'sqlite::memory:', getenv('VP3_TEST_MYSQL_USER') ?: null,
    getenv('VP3_TEST_MYSQL_PASS') ?: null, [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$GLOBALS['gelato_cloud_test_pdo'] = $pdo;
$mysql = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
if (!$mysql) $pdo->exec('PRAGMA foreign_keys=ON');
$ownerType = $mysql ? 'INT UNSIGNED' : 'INTEGER';
$folderIdType = $mysql ? 'BIGINT UNSIGNED' : 'INTEGER';
$auto = $mysql ? 'AUTO_INCREMENT' : 'AUTOINCREMENT';
$folderPrimaryKey = $mysql ? "$folderIdType $auto PRIMARY KEY" : "$folderIdType PRIMARY KEY $auto";
$pdo->exec("CREATE TABLE users (id $ownerType PRIMARY KEY)");
$pdo->exec('CREATE TABLE settings (setting_key VARCHAR(200),setting_value TEXT)');
$pdo->exec('INSERT INTO users VALUES (11),(12),(13)');
$pdo->exec("CREATE TABLE artist_transcript_folders_v177 (
    id $folderPrimaryKey,
    created_by_user_id $ownerType NOT NULL,
    folder_name VARCHAR(80) NOT NULL,
    sort_order INTEGER NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (created_by_user_id) REFERENCES users(id) ON DELETE CASCADE,
    UNIQUE(created_by_user_id, folder_name)
)");
$pdo->exec("CREATE TABLE knowledge_items (
    folder_id $folderIdType, created_by_user_id $ownerType NOT NULL, content_text TEXT NOT NULL,
    FOREIGN KEY (folder_id) REFERENCES artist_transcript_folders_v177(id) ON DELETE SET NULL
)");
gelato_cloud_check(vp3_gelato_connections_v1($pdo, 11) === [], 'Missing schema should leave Chat available.');
vp3_gelato_schema_v1($pdo);
vp3_gelato_schema_v1($pdo);
gelato_cloud_check(vp3_gelato_schema_ready_v1($pdo), 'Upgrade schema must be ready and idempotent.');
vp3_gelato_knowledge_schema_v1($pdo);
vp3_gelato_knowledge_schema_v1($pdo);

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
gelato_cloud_check(array_key_exists('knowledgeFolder', $connection) && $connection['knowledgeFolder'] === null
    && vp3_gelato_knowledge_folders_v1($pdo, 11) === [], 'Pairing without Knowledge authority created a private folder.');
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
$listed = vp3_gelato_connections_v1($pdo, 11);
$expectedListEntry = $connection;
unset($expectedListEntry['knowledgeFolder'], $listed[0]['knowledgeFolder']);
gelato_cloud_check($listed === [$expectedListEntry] && vp3_gelato_connections_v1($pdo, 12) === [],
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

$readFolder = $pdo->prepare('SELECT created_by_user_id,folder_name FROM artist_transcript_folders_v177 WHERE id=?');
$folderRow = static function (int $folderId) use ($readFolder): array|false {
    $readFolder->execute([$folderId]);
    $row = $readFolder->fetch(PDO::FETCH_ASSOC);
    if (!$row) return false;
    $row['created_by_user_id'] = (int)$row['created_by_user_id'];
    return $row;
};
$pdo->exec("INSERT INTO artist_transcript_folders_v177(created_by_user_id,folder_name) VALUES (11,'Food Monster')");
$manualFolderId = (int)$pdo->lastInsertId();
$paired = vp3_gelato_connect_v1($pdo, 11, $origin, $code, 'Local nickname', $request, true);
$folder = $paired['knowledgeFolder'] ?? null;
gelato_cloud_check(is_array($folder) && $folder['id'] !== $manualFolderId
    && str_starts_with($folder['name'], 'Food Monster') && $folder['name'] !== 'Food Monster',
    'Provisioning did not distinguish the connected agent from an existing owner folder.');
gelato_cloud_check($folderRow($folder['id']) === ['created_by_user_id'=>11,'folder_name'=>$folder['name']]
    && $folderRow($manualFolderId) === ['created_by_user_id'=>11,'folder_name'=>'Food Monster'],
    'Provisioning altered an existing folder or assigned it to the wrong owner.');
gelato_cloud_check(vp3_gelato_knowledge_folders_v1($pdo, 11) === [$paired['id']=>$folder]
    && vp3_gelato_knowledge_folders_v1($pdo, 12) === [], 'Folder binding crossed accounts.');
gelato_cloud_reject(fn()=>vp3_gelato_create_knowledge_folder_v1($pdo, 12, $paired['id']), 404,
    'Another user provisioned a folder on the owner’s connection.');

$folderCount = (int)$pdo->query('SELECT COUNT(*) FROM artist_transcript_folders_v177')->fetchColumn();
$pdo->exec('CREATE TRIGGER reject_fixture_folder_binding BEFORE INSERT ON vp3_gelato_knowledge_folders_v1 '
    .($mysql ? "FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Fixture folder binding blocked'"
        : "BEGIN SELECT RAISE(ABORT, 'Fixture folder binding blocked'); END"));
$postCount = count($posts);
$failed = false;
try {
    vp3_gelato_connect_v1($pdo, 12, $origin, $code, '', $request, true);
} catch (RuntimeException $e) {
    $failed = true;
}
$pdo->exec('DROP TRIGGER reject_fixture_folder_binding');
gelato_cloud_check($failed && array_slice($posts, $postCount) === ['exchange','revoke']
    && vp3_gelato_connections_v1($pdo, 12) === []
    && (int)$pdo->query('SELECT COUNT(*) FROM artist_transcript_folders_v177')->fetchColumn() === $folderCount,
    'Failed folder binding left a credential, orphan folder or unrevoked remote grant.');
$otherOwner = vp3_gelato_connect_v1($pdo, 12, $origin, $code, '', $request, true);
$otherFolder = $otherOwner['knowledgeFolder'];
gelato_cloud_check($otherFolder['id'] !== $folder['id'] && $otherFolder['name'] === 'Food Monster'
    && $folderRow($otherFolder['id'])['created_by_user_id'] === 12
    && vp3_gelato_knowledge_folders_v1($pdo, 12) === [$otherOwner['id']=>$otherFolder],
    'The same source did not create separate private folders for separate owners.');

$savedText = 'User-saved kitchen notes';
$pdo->prepare('INSERT INTO knowledge_items VALUES (?,?,?)')->execute([$folder['id'], 11, $savedText]);

$pdo->prepare('UPDATE artist_transcript_folders_v177 SET folder_name=? WHERE id=?')->execute(['Saved kitchen references', $folder['id']]);
$folder['name'] = 'Saved kitchen references';
gelato_cloud_check(vp3_gelato_create_knowledge_folder_v1($pdo, 11, $paired['id']) === $folder,
    'Reprovisioning discarded the owner’s renamed folder.');
gelato_cloud_check(vp3_gelato_disconnect_v1($pdo, 11, $paired['id'], $request)
    && vp3_gelato_knowledge_folders_v1($pdo, 11) === [] && $folderRow($folder['id']) !== false,
    'Disconnect either deleted the saved folder or still advertised it as connected.');
$saved = $pdo->query('SELECT folder_id,content_text FROM knowledge_items WHERE created_by_user_id=11')->fetch(PDO::FETCH_ASSOC);
gelato_cloud_check((int)$saved['folder_id'] === $folder['id'] && $saved['content_text'] === $savedText,
    'Disconnect did not preserve user-saved Knowledge in its existing folder.');

$differentSite = vp3_gelato_connect_v1($pdo, 11, 'https://other.example.com', $code, '', $request, true);
gelato_cloud_check($differentSite['knowledgeFolder']['id'] !== $folder['id'],
    'A different origin claiming the same instance inherited a private folder.');
gelato_cloud_check(vp3_gelato_disconnect_v1($pdo, 11, $differentSite['id'], $request), 'Alternate fixture connection remained active.');
$reconnected = vp3_gelato_connect_v1($pdo, 11, $origin, $code, '', $request, true);
gelato_cloud_check($reconnected['knowledgeFolder'] === $folder,
    'Reconnecting the same installation did not reuse its saved folder.');

$pdo->prepare('DELETE FROM artist_transcript_folders_v177 WHERE id=?')->execute([$folder['id']]);
gelato_cloud_check(vp3_gelato_knowledge_folders_v1($pdo, 11) === [], 'Deleted folder still appeared as a destination.');
$replacement = vp3_gelato_create_knowledge_folder_v1($pdo, 11, $reconnected['id']);
gelato_cloud_check($replacement['id'] !== $folder['id']
    && vp3_gelato_knowledge_folders_v1($pdo, 11) === [$reconnected['id']=>$replacement], 'Owner could not recreate the deleted destination.');
gelato_cloud_check(vp3_gelato_token_v1(vp3_gelato_connection_v1($pdo, 11, $reconnected['id'])) === $secret,
    'Folder lifecycle altered the paired credential.');
echo "Connected Gelato security and lifecycle checks passed.\n";
