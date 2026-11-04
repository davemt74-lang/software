<?php
declare(strict_types=1);

require_once __DIR__.'/ai-settings.php';
require_once __DIR__.'/cloud-mission-web-v1.php';

const VP3_GELATO_OPERATIONS_V1 = ['orders.read', 'tickets.open.read', 'sales.daily.read'];

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
    $input = trim($input);
    if (!preg_match('~^https://([a-z0-9.-]{1,253})/?$~iD', $input, $match)) {
        throw new InvalidArgumentException('Enter the public HTTPS origin of your Gelato installation.');
    }
    return 'https://'.cloud_mission_web_host_v1($match[1]);
}

/** Build one client pinned to a checked DNS address for this action. */
function vp3_gelato_transport_v1(string $origin, ?callable $resolver = null): Closure
{
    $deadline = hrtime(true) + 12_000_000_000;
    $host = substr(vp3_gelato_origin_v1($origin), 8);
    $records = $resolver ? $resolver($host) : @dns_get_record($host, DNS_A | DNS_AAAA);
    $addresses = [];
    foreach (is_array($records) ? $records : [] as $record) {
        $address = $record['ip'] ?? $record['ipv6'] ?? null;
        if ($address === null) continue; // A CNAME can accompany checked A/AAAA records.
        if (!is_string($address) || !cloud_mission_web_public_ip_v1($address)
            || (defined('FILTER_FLAG_GLOBAL_RANGE') && !filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_GLOBAL_RANGE))
            || (str_contains($address, ':') && str_starts_with(inet_pton($address), "\x20\x01\x00\x00"))) {
            throw new RuntimeException('The Gelato hostname must resolve only to public internet addresses.', 422);
        }
        $addresses[] = $address;
    }
    if (!$addresses) {
        throw new RuntimeException('The Gelato hostname could not be securely resolved.', 502);
    }
    if (!function_exists('curl_init')) {
        throw new RuntimeException('Gelato Cloud connections require the PHP cURL extension.', 503);
    }
    // Prefer IPv4 when available because some shared Cloud hosts lack IPv6 egress.
    $ipv4 = array_filter($addresses, static fn(string $ip): bool => !str_contains($ip, ':'));
    $ip = $ipv4 ? reset($ipv4) : $addresses[0];
    $resolve = $host.':443:'.(str_contains($ip, ':') ? '['.$ip.']' : $ip);
    $url = 'https://'.$host.'/api/food-monster-vp3.php';
    return static function (?array $payload = null, ?string $token = null) use ($url, $resolve, $deadline): array {
        $remainingMs = intdiv($deadline - hrtime(true), 1_000_000);
        if ($remainingMs < 1) throw new RuntimeException('The Gelato request timed out.', 502);
        $headers = ['Accept: application/json'];
        if ($token !== null) $headers[] = 'Authorization: Bearer '.$token;
        if ($payload !== null) $headers[] = 'Content-Type: application/json';
        $body = '';
        $curl = curl_init($url);
        try {
            $options = [
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
                CURLOPT_PROXY => '',
                CURLOPT_NETRC => CURL_NETRC_IGNORED,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_RESOLVE => [$resolve],
                CURLOPT_CONNECTTIMEOUT_MS => min(2500, $remainingMs),
                CURLOPT_TIMEOUT_MS => min(5000, $remainingMs),
                CURLOPT_ENCODING => '',
                CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$body): int {
                    if (strlen($body) + strlen($chunk) > 256000) return 0;
                    $body .= $chunk;
                    return strlen($chunk);
                },
            ];
            if ($payload !== null) {
                $options[CURLOPT_POST] = true;
                $options[CURLOPT_POSTFIELDS] = json_encode($payload, JSON_THROW_ON_ERROR);
            }
            if (!curl_setopt_array($curl, $options) || curl_exec($curl) !== true) {
                throw new RuntimeException('The Gelato site could not be reached securely.', 502);
            }
            $status = (int)curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
            $contentType = strtolower(trim(explode(';', (string)curl_getinfo($curl, CURLINFO_CONTENT_TYPE))[0]));
        } finally {
            curl_close($curl);
        }
        if (in_array($status, [401, 403], true)) {
            throw new RuntimeException('Gelato declined authorization. Check the connection in Gelato.', 403);
        }
        if ($status < 200 || $status >= 300 || $contentType !== 'application/json') {
            throw new RuntimeException('The Gelato site did not return a valid connector response.', 502);
        }
        try {
            $response = json_decode($body, true, 24, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new RuntimeException('The Gelato site did not return a valid connector response.', 502);
        }
        if (!is_array($response) || ($response['ok'] ?? null) !== true) {
            throw new RuntimeException('The Gelato site did not confirm the request.', 502);
        }
        return $response;
    };
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
        throw new RuntimeException('This site did not identify itself as a supported Gelato installation.', 502);
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
        throw new RuntimeException('Gelato returned invalid location permissions.', 502);
    }
    $locations = [];
    foreach ($value as $location) {
        $id = $location['id'] ?? null;
        $name = vp3_gelato_text_v1($location['name'] ?? null, 160);
        $timezone = $location['timezone'] ?? null;
        if (!is_int($id) || $id < 1 || isset($locations[$id]) || $name === ''
            || !is_string($timezone) || !in_array($timezone, DateTimeZone::listIdentifiers(DateTimeZone::ALL_WITH_BC), true)) {
            throw new RuntimeException('Gelato returned invalid location permissions.', 502);
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
        throw new RuntimeException('The saved Gelato credential could not be verified.', 503);
    }
    return $token;
}

function vp3_gelato_public_v1(array $row): array
{
    return [
        'id'=>$row['id'], 'label'=>$row['label'], 'agentName'=>$row['agent_name'],
        'origin'=>$row['origin'], 'instanceId'=>$row['instance_id'], 'status'=>'active',
        'scopes'=>json_decode($row['scopes_json'], true, 8, JSON_THROW_ON_ERROR),
        'locations'=>json_decode($row['locations_json'], true, 8, JSON_THROW_ON_ERROR),
        'createdAt'=>$row['created_at'],
    ];
}

function vp3_gelato_connections_v1(PDO $pdo, int $userId): array
{
    if ($userId < 1 || !vp3_gelato_schema_ready_v1($pdo)) return [];
    $query = $pdo->prepare('SELECT id,label,agent_name,origin,instance_id,scopes_json,locations_json,created_at
        FROM vp3_gelato_connections_v1 WHERE owner_user_id=? ORDER BY agent_name,id');
    $query->execute([$userId]);
    return array_map('vp3_gelato_public_v1', $query->fetchAll(PDO::FETCH_ASSOC));
}

function vp3_gelato_connection_v1(PDO $pdo, int $userId, string $id): array
{
    if ($userId < 1 || !preg_match('/^[a-f0-9]{32}$/D', $id) || !vp3_gelato_schema_ready_v1($pdo)) {
        throw new RuntimeException('Gelato connection is unavailable.', 404);
    }
    $query = $pdo->prepare('SELECT * FROM vp3_gelato_connections_v1 WHERE owner_user_id=? AND id=?');
    $query->execute([$userId, $id]);
    $row = $query->fetch(PDO::FETCH_ASSOC);
    if (!$row) throw new RuntimeException('Gelato connection is unavailable.', 404);
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

function vp3_gelato_connect_v1(PDO $pdo, int $userId, string $origin, string $code, string $label = '', ?callable $request = null): array
{
    if ($userId < 1 || !vp3_gelato_schema_ready_v1($pdo)) {
        throw new RuntimeException('Gelato connections are not ready. Run the database upgrade.', 503);
    }
    $origin = vp3_gelato_origin_v1($origin);
    $code = trim($code);
    if (!preg_match('/^fmlink_[A-Za-z0-9_-]{43}$/D', $code)) {
        throw new InvalidArgumentException('Enter a current one-time pairing code from Gelato.');
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
        throw new RuntimeException('This Gelato installation is already connected. Disconnect it before pairing again.', 409);
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
        throw new RuntimeException('Gelato pairing did not match the discovered installation.', 502);
    }
    try {
        $scopes = $exchange['scopes'] ?? null;
        $allowed = array_merge(['menu', 'recipe'], VP3_GELATO_OPERATIONS_V1);
        if (!is_array($scopes) || !array_is_list($scopes) || !$scopes || count($scopes) > count($allowed)
            || array_filter($scopes, static fn($scope): bool => !is_string($scope) || !in_array($scope, $allowed, true))
            || count(array_unique($scopes)) !== count($scopes)) {
            throw new RuntimeException('Gelato returned unsupported permissions.', 502);
        }
        $operations = array_intersect($scopes, VP3_GELATO_OPERATIONS_V1);
        $agentName = vp3_gelato_text_v1($exchange['agentName'] ?? null, 120);
        $locations = vp3_gelato_locations_v1($exchange['locations'] ?? null);
        if ($agentName === '' || ($operations && (!$locations || array_diff($operations, $discovery['capabilities'])))) {
            throw new RuntimeException('Gelato did not return the verified agent and location permissions. Update Gelato before pairing.', 502);
        }
        $row += [
            'remote_connection_id'=>$remoteId, 'agent_name'=>$agentName,
            'label'=>trim($label), 'scopes_json'=>json_encode($scopes, JSON_THROW_ON_ERROR),
            'locations_json'=>json_encode($locations, JSON_THROW_ON_ERROR), 'created_at'=>gmdate('Y-m-d\TH:i:s\Z'),
        ];
        $ciphertext = ai_encrypt_secret_with_key($token, vp3_gelato_token_key_v1($row));
        $pdo->prepare('INSERT INTO vp3_gelato_connections_v1
            (id,owner_user_id,instance_id,remote_connection_id,origin,label,agent_name,scopes_json,locations_json,token_ciphertext,created_at)
            VALUES (?,?,?,?,?,?,?,?,?,?,?)')->execute([
                $row['id'], $userId, $instance, $remoteId, $origin, $row['label'], $agentName,
                $row['scopes_json'], $row['locations_json'], $ciphertext, $row['created_at'],
            ]);
    } catch (Throwable $e) {
        $revoked = vp3_gelato_remote_revoke_v1($request, $token, $instance, $remoteId);
        if (!$revoked) {
            throw new RuntimeException('VP3 did not save the connection. Revoke this pairing in Gelato before retrying.', 502);
        }
        if ($e instanceof PDOException && (string)$e->getCode() === '23000') {
            throw new RuntimeException('This Gelato installation is already connected.', 409);
        }
        throw $e;
    }
    return vp3_gelato_public_v1($row);
}

function vp3_gelato_disconnect_v1(PDO $pdo, int $userId, string $id, ?callable $request = null): bool
{
    $row = vp3_gelato_connection_v1($pdo, $userId, $id);
    $delete = $pdo->prepare('DELETE FROM vp3_gelato_connections_v1 WHERE owner_user_id=? AND id=?');
    $delete->execute([$userId, $id]);
    if ($delete->rowCount() !== 1) return false;
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
        throw new RuntimeException('The Gelato installation identity changed. Reconnect it before reading.', 502);
    }
    if ($production) $verified[$key] = $request;
    return $request;
}

function vp3_gelato_operation_v1(
    PDO $pdo, int $userId, string $connectionId, string $capability, int $locationId,
    ?string $businessDate = null, ?callable $request = null
): array {
    if (!in_array($capability, VP3_GELATO_OPERATIONS_V1, true) || $locationId < 1) {
        throw new InvalidArgumentException('Choose a supported Gelato read and location.');
    }
    if ($businessDate !== null && ($capability !== 'sales.daily.read'
        || !preg_match('/^([0-9]{4})-([0-9]{2})-([0-9]{2})$/D', $businessDate, $date)
        || !checkdate((int)$date[2], (int)$date[3], (int)$date[1]))) {
        throw new InvalidArgumentException('Choose a valid sales business date.');
    }
    $row = vp3_gelato_connection_v1($pdo, $userId, $connectionId);
    $public = vp3_gelato_public_v1($row);
    if (!in_array($capability, $public['scopes'], true) || !in_array($locationId, array_column($public['locations'], 'id'), true)) {
        throw new RuntimeException('This connection does not permit that Gelato read or location.', 403);
    }
    $payload = ['action'=>$capability,'locationId'=>$locationId];
    if ($businessDate !== null) $payload['businessDate'] = $businessDate;
    $request = vp3_gelato_verified_request_v1($row, $request);
    $response = $request($payload, vp3_gelato_token_v1($row));
    // Refuse an in-flight result if the connection was deleted while Gelato was responding.
    vp3_gelato_connection_v1($pdo, $userId, $connectionId);

    $location = vp3_gelato_locations_v1([$response['location'] ?? null])[0];
    $agentName = vp3_gelato_text_v1($response['agentName'] ?? null, 120);
    $sourceLabel = vp3_gelato_text_v1($response['source']['label'] ?? null, 120);
    $sourcePath = $response['source']['path'] ?? null;
    $expectedPath = match ($capability) {
        'orders.read' => '/pickup-fulfillment.php',
        'tickets.open.read' => '/pos.php',
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
        throw new RuntimeException('Gelato returned data outside the verified connection.', 502);
    }
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
        throw new InvalidArgumentException('Enter a supported Gelato knowledge search.');
    }
    $row = vp3_gelato_connection_v1($pdo, $userId, $connectionId);
    $scopes = array_intersect(vp3_gelato_public_v1($row)['scopes'], ['menu', 'recipe']);
    if (!$scopes || ($type !== 'all' && !in_array($type, $scopes, true))) {
        throw new RuntimeException('This connection does not permit that Gelato search.', 403);
    }
    $request = vp3_gelato_verified_request_v1($row, $request);
    $response = $request(['action'=>'search','q'=>$query,'type'=>$type,'limit'=>$limit], vp3_gelato_token_v1($row));
    vp3_gelato_connection_v1($pdo, $userId, $connectionId);
    $results = $response['results'] ?? null;
    if (($response['ok'] ?? null) !== true || ($response['schema'] ?? null) !== 'food-monster-knowledge-v1'
        || ($response['readOnly'] ?? null) !== true || !is_array($results) || !array_is_list($results)
        || count($results) > $limit) {
        throw new RuntimeException('Gelato did not return a valid knowledge result.', 502);
    }
    foreach ($results as $result) {
        if (!in_array($result['type'] ?? null, $scopes, true) || ($type !== 'all' && ($result['type'] ?? null) !== $type)) {
            throw new RuntimeException('Gelato returned data outside the verified connection.', 502);
        }
    }
    return $response;
}
