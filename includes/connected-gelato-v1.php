<?php
declare(strict_types=1);

require_once __DIR__.'/ai-settings.php';
require_once __DIR__.'/connected-gelato-knowledge-v1.php';
require_once __DIR__.'/connected-pos-ticket-v1.php';

const VP3_GELATO_OPERATIONS_V1 = ['orders.read', 'tickets.open.read', 'sales.daily.read', 'tickets.detail.read'];

function vp3_gelato_schema_v1(PDO $pdo): void
{
    $options = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql'
        ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        : '';
    $pdo->exec("CREATE TABLE IF NOT EXISTS vp3_gelato_connections_v1 (
        id CHAR(32) NOT NULL PRIMARY KEY,
        owner_user_id INT UNSIGNED NOT NULL,
        instance_id CHAR(36) NOT NULL,
        remote_connection_id CHAR(32) NOT NULL,
        origin VARCHAR(261) NOT NULL,
        label VARCHAR(80) NOT NULL,
        agent_name VARCHAR(120) NOT NULL,
        scopes_json TEXT NOT NULL,
        locations_json TEXT NOT NULL,
        token_ciphertext TEXT NOT NULL,
        created_at VARCHAR(25) NOT NULL,
        UNIQUE (owner_user_id, instance_id),
        FOREIGN KEY (owner_user_id) REFERENCES users(id) ON DELETE CASCADE
    )".$options);
}

function vp3_gelato_schema_ready_v1(PDO $pdo): bool
{
    try {
        return $pdo->query('SELECT id,token_ciphertext FROM vp3_gelato_connections_v1 WHERE 1=0') !== false;
    } catch (PDOException) {
        return false;
    }
}

function vp3_gelato_origin_v1(string $input): string
{
    require_once __DIR__.'/connected-site-http-v1.php';
    return vp3_connected_site_origin_v1($input, 'FoodMonster');
}

function vp3_gelato_transport_v1(string $origin, ?callable $resolver = null): Closure
{
    require_once __DIR__.'/connected-site-http-v1.php';
    return vp3_connected_site_transport_v1($origin, '/api/food-monster-vp3.php', 'FoodMonster', $resolver);
}

function vp3_gelato_discover_v1(callable $request): array
{
    $response = $request();
    $instanceId = $response['instanceId'] ?? null;
    $capabilities = $response['capabilities'] ?? null;
    if (($response['ok'] ?? null) !== true
        || ($response['schema'] ?? null) !== 'food-monster-vp3-v1'
        || ($response['pairing'] ?? null) !== 'admin-issued-one-time-code'
        || ($response['authentication'] ?? null) !== 'bearer'
        || !is_string($instanceId)
        || !preg_match('/^[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}$/D', $instanceId)
        || !is_array($capabilities) || !array_is_list($capabilities)
        || count($capabilities) > 30 || array_filter($capabilities, static fn($capability): bool => !is_string($capability))) {
        throw new RuntimeException('This site did not identify itself as a supported FoodMonster installation.', 502);
    }
    return ['instanceId'=>$instanceId, 'capabilities'=>$capabilities];
}

function vp3_gelato_text_v1(mixed $value, int $length): string
{
    if (!is_string($value) || !mb_check_encoding($value, 'UTF-8')) return '';
    $value = trim($value);
    return $value !== '' && !preg_match('/[\p{Cc}\p{Cf}]/u', $value) && mb_strlen($value, 'UTF-8') <= $length ? $value : '';
}

function vp3_gelato_locations_v1(mixed $value): array
{
    if (!is_array($value) || !array_is_list($value) || count($value) > 50) {
        throw new RuntimeException('FoodMonster returned invalid location permissions.', 502);
    }
    $locations = [];
    foreach ($value as $location) {
        $id = $location['id'] ?? null;
        $name = vp3_gelato_text_v1($location['name'] ?? null, 160);
        $timezone = $location['timezone'] ?? null;
        if (!is_int($id) || $id < 1 || isset($locations[$id]) || $name === ''
            || !is_string($timezone) || !in_array($timezone, DateTimeZone::listIdentifiers(DateTimeZone::ALL_WITH_BC), true)) {
            throw new RuntimeException('FoodMonster returned invalid location permissions.', 502);
        }
        $locations[$id] = ['id'=>$id, 'name'=>$name, 'timezone'=>$timezone];
    }
    return array_values($locations);
}

function vp3_gelato_token_key_v1(array $row, bool $create = false): string
{
    $master = ai_master_key($create);
    if ($master === null) throw new RuntimeException('VP3 credential encryption is unavailable.', 503);
    $binding = json_encode(['vp3-gelato-v1', (int)$row['owner_user_id'], $row['id'], $row['origin'], $row['instance_id'], $row['remote_connection_id']], JSON_THROW_ON_ERROR);
    return hash_hmac('sha256', $binding, $master, true);
}

function vp3_gelato_token_v1(array $row): string
{
    $token = ai_decrypt_secret_with_key($row['token_ciphertext'], vp3_gelato_token_key_v1($row));
    if (!preg_match('/^fm2_[A-Za-z0-9_-]{43}$/D', $token)) {
        throw new RuntimeException('The saved FoodMonster credential could not be verified.', 503);
    }
    return $token;
}

function vp3_gelato_public_v1(array $row): array
{
    return [
        'provider'=>'foodmonster',
        'id'=>$row['id'], 'label'=>$row['label'], 'agentName'=>$row['agent_name'],
        'origin'=>$row['origin'], 'instanceId'=>$row['instance_id'], 'status'=>'active',
        'scopes'=>json_decode($row['scopes_json'], true, 8, JSON_THROW_ON_ERROR),
        'locations'=>json_decode($row['locations_json'], true, 8, JSON_THROW_ON_ERROR),
        'createdAt'=>$row['created_at'],
    ];
}

function vp3_gelato_connections_v1(PDO $pdo, int $userId, bool $requireSchema = false): array
{
    if ($userId < 1) return [];
    if (!vp3_gelato_schema_ready_v1($pdo)) {
        if ($requireSchema) throw new RuntimeException('FoodMonster connections are not ready. Run the database upgrade.', 503);
        return [];
    }
    $query = $pdo->prepare('SELECT id,label,agent_name,origin,instance_id,scopes_json,locations_json,created_at
        FROM vp3_gelato_connections_v1 WHERE owner_user_id=? ORDER BY agent_name,id');
    $query->execute([$userId]);
    return array_map('vp3_gelato_public_v1', $query->fetchAll(PDO::FETCH_ASSOC));
}

function vp3_gelato_connection_v1(PDO $pdo, int $userId, string $id, bool $forUpdate = false): array
{
    if ($userId < 1 || !preg_match('/^[a-f0-9]{32}$/D', $id) || !vp3_gelato_schema_ready_v1($pdo)) {
        throw new RuntimeException('FoodMonster connection is unavailable.', 404);
    }
    $lock = $forUpdate && $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '';
    $query = $pdo->prepare('SELECT * FROM vp3_gelato_connections_v1 WHERE owner_user_id=? AND id=?'.$lock);
    $query->execute([$userId, $id]);
    $row = $query->fetch(PDO::FETCH_ASSOC);
    if (!$row) throw new RuntimeException('FoodMonster connection is unavailable.', 404);
    return $row;
}

/** Confirm remote revocation only for the exact connection and instance. */
function vp3_gelato_remote_revoke_v1(callable $request, string $token, string $instance, string $remoteId): bool
{
    try {
        $response = $request(['action'=>'revoke'], $token);
        return ($response['ok'] ?? null) === true
            && ($response['schema'] ?? null) === 'food-monster-vp3-v1'
            && ($response['revoked'] ?? null) === true
            && ($response['instanceId'] ?? null) === $instance
            && ($response['connectionId'] ?? null) === $remoteId;
    } catch (Throwable) {
        return false;
    }
}

function vp3_gelato_connect_v1(PDO $pdo, int $userId, string $origin, string $code, string $label = '',
    ?callable $request = null, bool $createKnowledgeFolder = false): array
{
    if ($userId < 1 || !vp3_gelato_schema_ready_v1($pdo)) {
        throw new RuntimeException('FoodMonster connections are not ready. Run the database upgrade.', 503);
    }
    $origin = vp3_gelato_origin_v1($origin);
    $code = trim($code);
    if (!preg_match('/^fmlink_[A-Za-z0-9_-]{43}$/D', $code)) {
        throw new InvalidArgumentException('Enter a current one-time pairing code from FoodMonster.');
    }
    if ($label !== '' && vp3_gelato_text_v1($label, 80) === '') {
        throw new InvalidArgumentException('Use a connection label of up to 80 characters.');
    }

    $request ??= vp3_gelato_transport_v1($origin);
    $discovery = vp3_gelato_discover_v1($request);
    $instance = $discovery['instanceId'];
    $query = $pdo->prepare('SELECT id FROM vp3_gelato_connections_v1 WHERE owner_user_id=? AND instance_id=?');
    $query->execute([$userId, $instance]);
    if ($query->fetchColumn()) {
        throw new RuntimeException('This FoodMonster installation is already connected. Disconnect it before pairing again.', 409);
    }

    // Establish that the local encryption key works before consuming the one-time code.
    $row = ['id'=>bin2hex(random_bytes(16)), 'owner_user_id'=>$userId, 'origin'=>$origin, 'instance_id'=>$instance];
    $master = ai_master_key(true);
    if ($master === null || (!function_exists('sodium_crypto_secretbox') && !function_exists('openssl_encrypt'))) {
        throw new RuntimeException('VP3 credential encryption is unavailable.', 503);
    }
    $exchange = $request(['action'=>'exchange','code'=>$code]);
    $token = $exchange['accessToken'] ?? null;
    $remoteId = $exchange['connectionId'] ?? null;
    if (($exchange['ok'] ?? null) !== true || ($exchange['schema'] ?? null) !== 'food-monster-vp3-v1'
        || ($exchange['tokenType'] ?? null) !== 'Bearer' || ($exchange['instanceId'] ?? null) !== $instance
        || !is_string($token) || !preg_match('/^fm2_[A-Za-z0-9_-]{43}$/D', $token)
        || !is_string($remoteId) || !preg_match('/^[a-f0-9]{32}$/D', $remoteId)) {
        throw new RuntimeException('FoodMonster pairing did not match the discovered installation.', 502);
    }
    $folder = null;
    $folderTransaction = false;
    try {
        $scopes = $exchange['scopes'] ?? null;
        $allowed = array_merge(['menu', 'recipe'], VP3_GELATO_OPERATIONS_V1);
        if (!is_array($scopes) || !array_is_list($scopes) || !$scopes || count($scopes) > count($allowed)
            || array_filter($scopes, static fn($scope): bool => !is_string($scope) || !in_array($scope, $allowed, true))
            || count(array_unique($scopes)) !== count($scopes)) {
            throw new RuntimeException('FoodMonster returned unsupported permissions.', 502);
        }
        $operations = array_intersect($scopes, VP3_GELATO_OPERATIONS_V1);
        $agentName = vp3_gelato_text_v1($exchange['agentName'] ?? null, 120);
        $locations = vp3_gelato_locations_v1($exchange['locations'] ?? null);
        if ($agentName === '' || ($operations && (!$locations || array_diff($operations, $discovery['capabilities'])))) {
            throw new RuntimeException('FoodMonster did not return the verified agent and location permissions. Update FoodMonster before pairing.', 502);
        }
        $row += [
            'remote_connection_id'=>$remoteId, 'agent_name'=>$agentName,
            'label'=>trim($label), 'scopes_json'=>json_encode($scopes, JSON_THROW_ON_ERROR),
            'locations_json'=>json_encode($locations, JSON_THROW_ON_ERROR), 'created_at'=>gmdate('Y-m-d\TH:i:s\Z'),
        ];
        $ciphertext = ai_encrypt_secret_with_key($token, vp3_gelato_token_key_v1($row));
        if ($createKnowledgeFolder) {
            $pdo->beginTransaction();
            $folderTransaction = true;
            vp3_gelato_knowledge_lock_owner_v1($pdo, $userId);
        }
        $pdo->prepare('INSERT INTO vp3_gelato_connections_v1
            (id,owner_user_id,instance_id,remote_connection_id,origin,label,agent_name,scopes_json,locations_json,token_ciphertext,created_at)
            VALUES (?,?,?,?,?,?,?,?,?,?,?)')->execute([
                $row['id'], $userId, $instance, $remoteId, $origin, $row['label'], $agentName,
                $row['scopes_json'], $row['locations_json'], $ciphertext, $row['created_at'],
            ]);
        if ($folderTransaction) {
            $folder = vp3_gelato_knowledge_ensure_v1($pdo, $row);
            $pdo->commit();
        }
    } catch (Throwable $e) {
        if ($folderTransaction && $pdo->inTransaction()) $pdo->rollBack();
        $revoked = vp3_gelato_remote_revoke_v1($request, $token, $instance, $remoteId);
        if (!$revoked) {
            throw new RuntimeException('VP3 did not save the connection. Revoke this pairing in FoodMonster before retrying.', 502);
        }
        if ($e instanceof PDOException && (string)$e->getCode() === '23000') {
            throw new RuntimeException('This FoodMonster installation is already connected.', 409);
        }
        throw $e;
    }
    return vp3_gelato_public_v1($row) + ['knowledgeFolder'=>$folder];
}

function vp3_gelato_disconnect_v1(PDO $pdo, int $userId, string $id, ?callable $request = null): bool
{
    require_once __DIR__.'/connected-sites-reports-v1.php';
    $row = vp3_gelato_connection_v1($pdo, $userId, $id);
    $removed = agent_commerce_atomic_pa2($pdo,static function () use ($pdo,$userId,$id): bool {
        $delete = $pdo->prepare('DELETE FROM vp3_gelato_connections_v1 WHERE owner_user_id=? AND id=?');
        $delete->execute([$userId, $id]);
        if ($delete->rowCount() !== 1) return false;
        vp3_connected_sites_report_forget_v1($pdo,$userId,$id);
        vp3_coordination_source_changed_v1($pdo,$userId);
        return true;
    });
    if (!$removed) return false;
    try {
        $request ??= vp3_gelato_transport_v1($row['origin']);
        if (vp3_gelato_discover_v1($request)['instanceId'] !== $row['instance_id']) return false;
        return vp3_gelato_remote_revoke_v1($request, vp3_gelato_token_v1($row), $row['instance_id'], $row['remote_connection_id']);
    } catch (Throwable) {
        // Local revocation is final even when the source is unreachable.
        return false;
    }
}

function vp3_gelato_verified_request_v1(array $row, ?callable $request): callable
{
    // The pinned transport and its deadline are shared only within this PHP request.
    // Each read still rechecks connection ownership and revocation in storage.
    static $verified = [];
    $key = $row['owner_user_id'].':'.$row['id'];
    $production = $request === null;
    if ($production && isset($verified[$key])) return $verified[$key];
    $request ??= vp3_gelato_transport_v1($row['origin']);
    if (vp3_gelato_discover_v1($request)['instanceId'] !== $row['instance_id']) {
        throw new RuntimeException('The FoodMonster installation identity changed. Reconnect it before reading.', 502);
    }
    if ($production) $verified[$key] = $request;
    return $request;
}

/** Keep the detail response limited to the fields approved for Chat. */
function vp3_gelato_ticket_detail_v1(array $data, string $number, ?string $businessDate): array
{
    $badResponse = 'FoodMonster returned invalid POS ticket details.';
    $ambiguous = $data['ambiguous'] ?? false;
    if (!array_key_exists('ticket',$data) || !is_bool($ambiguous)) {
        throw new RuntimeException($badResponse,502);
    }
    $ticket = $data['ticket'];
    if ($ticket === null) return ['ticket'=>null,'ambiguous'=>$ambiguous];
    $date = $ticket['businessDate'] ?? null;
    $canonicalNumber = vp3_gelato_ticket_number_v1($ticket['checkNumber'] ?? null);
    $status = vp3_gelato_text_v1($ticket['status'] ?? null,40);
    $items = $ticket['items'] ?? null;
    $truncated = $ticket['truncated'] ?? null;
    if (!is_array($ticket) || $ambiguous || $canonicalNumber === null
        || !vp3_gelato_ticket_number_matches_v1($number,$canonicalNumber)
        || !is_string($date) || !vp3_gelato_business_date_v1($date) || ($businessDate !== null && $date !== $businessDate)
        || $status === '' || !is_bool($truncated) || !is_array($items) || !array_is_list($items) || count($items)>50) {
        throw new RuntimeException($badResponse,502);
    }
    $lines = [];
    foreach ($items as $item) {
        $name = vp3_gelato_text_v1($item['name'] ?? null,160);
        $quantity = $item['quantity'] ?? null;
        if ((!is_int($quantity) && !is_string($quantity)) || $name === ''
            || !preg_match('/^\d{1,9}(?:\.\d{1,4})?$/D',(string)$quantity) || (float)$quantity<=0) {
            throw new RuntimeException($badResponse,502);
        }
        $lines[] = ['name'=>$name,'quantity'=>(string)$quantity];
    }
    return ['ticket'=>[
        'checkNumber'=>$canonicalNumber,'businessDate'=>$date,'status'=>$status,'items'=>$lines,'truncated'=>$truncated,
    ],'ambiguous'=>false];
}

function vp3_gelato_operation_v1(
    PDO $pdo, int $userId, string $connectionId, string $capability, int $locationId,
    ?string $businessDate = null, ?callable $request = null, ?string $ticketNumber = null, ?int $limit = null
): array {
    if (!in_array($capability, VP3_GELATO_OPERATIONS_V1, true) || $locationId < 1) {
        throw new InvalidArgumentException('Choose a supported FoodMonster read and location.');
    }
    if ($businessDate !== null && (!in_array($capability,['sales.daily.read','tickets.detail.read'],true)
        || !vp3_gelato_business_date_v1($businessDate))) {
        throw new InvalidArgumentException('Choose a valid business date.');
    }
    if (($capability === 'tickets.detail.read') !== ($ticketNumber !== null)
        || ($ticketNumber !== null && ($ticketNumber=vp3_gelato_ticket_number_v1($ticketNumber))===null)) {
        throw new InvalidArgumentException('Choose a valid POS ticket number.');
    }
    if ($limit !== null && (!in_array($capability,['tickets.open.read','orders.read'],true) || $limit<1 || $limit>50)) {
        throw new InvalidArgumentException('Choose a read limit between 1 and 50 for open tickets or active orders.');
    }
    $row = vp3_gelato_connection_v1($pdo, $userId, $connectionId);
    $public = vp3_gelato_public_v1($row);
    if (!in_array($capability, $public['scopes'], true) || !in_array($locationId, array_column($public['locations'], 'id'), true)) {
        throw new RuntimeException('This connection does not permit that FoodMonster read or location.', 403);
    }
    $payload = ['action'=>$capability,'locationId'=>$locationId];
    if ($businessDate !== null) $payload['businessDate'] = $businessDate;
    if ($ticketNumber !== null) $payload['ticketNumber'] = $ticketNumber;
    if ($limit !== null) $payload['limit'] = $limit;
    $request = vp3_gelato_verified_request_v1($row, $request);
    $response = $request($payload, vp3_gelato_token_v1($row));
    // Refuse an in-flight result if the connection was deleted while FoodMonster was responding.
    vp3_gelato_connection_v1($pdo, $userId, $connectionId);

    $location = vp3_gelato_locations_v1([$response['location'] ?? null])[0];
    $agentName = vp3_gelato_text_v1($response['agentName'] ?? null, 120);
    $sourceLabel = vp3_gelato_text_v1($response['source']['label'] ?? null, 120);
    $sourcePath = $response['source']['path'] ?? null;
    $expectedPath = match ($capability) {
        'orders.read' => '/pickup-fulfillment.php',
        'tickets.open.read', 'tickets.detail.read' => '/pos.php',
        'sales.daily.read' => '/sales-intelligence.php',
    };
    $asOf = $response['asOf'] ?? null;
    $data = $response['data'] ?? null;
    if (($response['ok'] ?? null) !== true
        || ($response['schema'] ?? null) !== 'food-monster-vp3-operations-v1'
        || ($response['instanceId'] ?? null) !== $row['instance_id']
        || ($response['connectionId'] ?? null) !== $row['remote_connection_id']
        || ($response['capability'] ?? null) !== $capability || $location['id'] !== $locationId
        || $agentName !== $row['agent_name'] || $sourceLabel === '' || $sourcePath !== $expectedPath
        || !is_string($asOf) || !preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?(?:Z|\+00:00)$/D', $asOf)
        || !is_array($data)) {
        throw new RuntimeException('FoodMonster returned data outside the verified connection.', 502);
    }
    if ($ticketNumber !== null) $data = vp3_gelato_ticket_detail_v1($data,$ticketNumber,$businessDate);
    return [
        'ok'=>true, 'schema'=>'food-monster-vp3-operations-v1', 'instanceId'=>$row['instance_id'],
        'connectionId'=>$row['remote_connection_id'], 'agentName'=>$agentName, 'capability'=>$capability,
        'location'=>$location, 'asOf'=>$asOf, 'source'=>['label'=>$sourceLabel,'path'=>$sourcePath], 'data'=>$data,
    ];
}

function vp3_gelato_search_v1(
    PDO $pdo, int $userId, string $connectionId, string $query, string $type = 'all',
    int $limit = 10, ?callable $request = null
): array {
    $query = vp3_gelato_text_v1($query, 160);
    if ($query === '' || !in_array($type, ['all', 'menu', 'recipe'], true) || $limit < 1 || $limit > 20) {
        throw new InvalidArgumentException('Enter a supported FoodMonster knowledge search.');
    }
    $row = vp3_gelato_connection_v1($pdo, $userId, $connectionId);
    $scopes = array_intersect(vp3_gelato_public_v1($row)['scopes'], ['menu', 'recipe']);
    if (!$scopes || ($type !== 'all' && !in_array($type, $scopes, true))) {
        throw new RuntimeException('This connection does not permit that FoodMonster search.', 403);
    }
    $request = vp3_gelato_verified_request_v1($row, $request);
    $response = $request(['action'=>'search','q'=>$query,'type'=>$type,'limit'=>$limit], vp3_gelato_token_v1($row));
    vp3_gelato_connection_v1($pdo, $userId, $connectionId);
    $results = $response['results'] ?? null;
    if (($response['ok'] ?? null) !== true || ($response['schema'] ?? null) !== 'food-monster-knowledge-v1'
        || ($response['readOnly'] ?? null) !== true || !is_array($results) || !array_is_list($results)
        || count($results) > $limit) {
        throw new RuntimeException('FoodMonster did not return a valid knowledge result.', 502);
    }
    foreach ($results as $result) {
        if (!in_array($result['type'] ?? null, $scopes, true) || ($type !== 'all' && ($result['type'] ?? null) !== $type)) {
            throw new RuntimeException('FoodMonster returned data outside the verified connection.', 502);
        }
    }
    return $response;
}
