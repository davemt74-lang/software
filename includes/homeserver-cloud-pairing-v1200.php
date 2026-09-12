<?php
declare(strict_types=1);

require_once __DIR__ . '/homeserver-approvals-v028.php';

const VP3_HOMESERVER_CLOUD_PAIRING_V1200 = 'homeserver-cloud-pairing-v1200-20260912';
const VP3_HOMESERVER_PAIRING_PROTOCOL = 'claim-v1';

function homeserver_cloud_v1200_permissions(): array
{
    return homeserver_approvals_v028_permissions();
}

function homeserver_cloud_v1200_public_error(string $message): string
{
    $message = mb_strtolower(trim($message));
    if ($message === '') return 'HomeServer connection could not be updated.';
    if (str_contains($message, 'expired')) return 'The HomeServer pairing request expired. Start pairing again.';
    if (str_contains($message, 'denied')) return 'HomeServer rejected the pairing request.';
    if (str_contains($message, 'claim') && (str_contains($message, 'invalid') || str_contains($message, 'not found'))) {
        return 'The Remote Bridge claim code is invalid or expired.';
    }
    if (str_contains($message, 'offline') || str_contains($message, '503') || str_contains($message, 'connect') || str_contains($message, 'timeout')) {
        return 'HomeServer is offline or the secure relay is temporarily unavailable.';
    }
    if (str_contains($message, '401') || str_contains($message, 'authorization') || str_contains($message, 'bearer') || str_contains($message, 'revoked')) {
        return 'HomeServer authorization is no longer valid. Re-pair HomeServer.';
    }
    if (str_contains($message, 'relay') && str_contains($message, 'configured')) {
        return 'The secure HomeServer relay is not configured on VP3.';
    }
    return 'HomeServer connection could not be updated. Check HomeServer and try again.';
}

function homeserver_cloud_v1200_relay_security(): array
{
    $url = homeserver_vp3_relay_base_url();
    if ($url === '') {
        throw new RuntimeException('HomeServer relay is not configured.');
    }
    $parts = parse_url($url);
    if (!is_array($parts) || strtolower((string)($parts['scheme'] ?? '')) !== 'https' || empty($parts['host'])) {
        throw new RuntimeException('HomeServer relay must use HTTPS.');
    }
    if (isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
        throw new RuntimeException('HomeServer relay URL is invalid.');
    }
    $host = strtolower(rtrim((string)$parts['host'], '.'));
    if ($host === '' || strlen($host) > 253 || !preg_match('/^[a-z0-9.-]+$/', $host)) {
        throw new RuntimeException('HomeServer relay host is invalid.');
    }

    $allowPrivate = trim((string)getenv('VP3_HOMESERVER_ALLOW_PRIVATE_RELAY')) === '1';
    $blockedNames = ['localhost', 'localhost.localdomain', 'metadata.google.internal'];
    $blockedSuffixes = ['.localhost', '.local', '.internal', '.lan', '.home.arpa', '.localdomain'];
    if (!$allowPrivate) {
        if (in_array($host, $blockedNames, true)) {
            throw new RuntimeException('HomeServer relay host is not allowed.');
        }
        foreach ($blockedSuffixes as $suffix) {
            if (str_ends_with($host, $suffix)) {
                throw new RuntimeException('HomeServer relay host is not allowed.');
            }
        }
    }

    $allowedHosts = array_values(array_filter(array_map(
        static fn(string $item): string => strtolower(rtrim(trim($item), '.')),
        explode(',', (string)getenv('VP3_HOMESERVER_RELAY_ALLOWED_HOSTS'))
    )));
    if ($allowedHosts && !in_array($host, $allowedHosts, true)) {
        throw new RuntimeException('HomeServer relay host is not approved by VP3 configuration.');
    }

    $addresses = [];
    if (filter_var($host, FILTER_VALIDATE_IP)) {
        $addresses[] = $host;
    } elseif (function_exists('dns_get_record')) {
        $records = @dns_get_record($host, DNS_A | DNS_AAAA);
        if (is_array($records)) {
            foreach ($records as $record) {
                $ip = (string)($record['ip'] ?? $record['ipv6'] ?? '');
                if ($ip !== '') $addresses[] = $ip;
            }
        }
    }
    $addresses = array_values(array_unique($addresses));
    if (!$addresses) {
        throw new RuntimeException('HomeServer relay host could not be resolved securely.');
    }
    if (!$allowPrivate) {
        foreach ($addresses as $ip) {
            if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                throw new RuntimeException('HomeServer relay resolved to a private or reserved network.');
            }
        }
    }

    $port = isset($parts['port']) ? (int)$parts['port'] : 443;
    if ($port < 1 || $port > 65535) {
        throw new RuntimeException('HomeServer relay port is invalid.');
    }
    return ['url'=>$url, 'host'=>$host, 'port'=>$port, 'addresses'=>$addresses, 'private_allowed'=>$allowPrivate];
}

function homeserver_cloud_v1200_pair_request(string $relayToken): array
{
    $pairing = homeserver_vp3_remote_operation($relayToken, 'pair.request', [
        'app_key'=>'vp3',
        'app_name'=>'VP3',
        'permissions'=>homeserver_cloud_v1200_permissions(),
    ]);
    $protocol = trim((string)($pairing['protocol'] ?? VP3_HOMESERVER_PAIRING_PROTOCOL));
    $requestId = trim((string)($pairing['request_id'] ?? ''));
    $claimToken = trim((string)($pairing['claim_token'] ?? ''));
    $approvalCode = strtoupper(trim((string)($pairing['code'] ?? '')));
    $expiresAt = trim((string)($pairing['expires_at'] ?? ''));
    if ($protocol !== VP3_HOMESERVER_PAIRING_PROTOCOL
        || $requestId === '' || strlen($requestId) > 128
        || strlen($claimToken) < 32 || strlen($claimToken) > 512
        || !preg_match('/^[A-Z0-9-]{8,40}$/', $approvalCode)) {
        throw new RuntimeException('HomeServer returned an unsupported pairing response.');
    }
    return [
        'protocol'=>$protocol,
        'request_id'=>$requestId,
        'claim_token'=>$claimToken,
        'approval_code'=>$approvalCode,
        'expires_at'=>$expiresAt,
        'permissions'=>is_array($pairing['permissions'] ?? null) ? array_values($pairing['permissions']) : homeserver_cloud_v1200_permissions(),
    ];
}

function homeserver_cloud_v1200_store_pending(int $userId, string $deviceId, string $relayToken, array $pairing, bool $preserveApprovedToken=false): void
{
    $pdo = db();
    if (!$pdo) throw new RuntimeException('Database connection is unavailable.');
    homeserver_vp3_ensure_schema($pdo);
    if ($preserveApprovedToken) {
        $stmt = $pdo->prepare(
            "UPDATE homeserver_connections
             SET pending_request_id=?,pending_claim_token_enc=?,pending_code=?,status='awaiting_approval',last_error='',last_checked_at=NULL
             WHERE user_id=?"
        );
        $stmt->execute([
            (string)$pairing['request_id'], homeserver_vp3_encrypt((string)$pairing['claim_token']),
            (string)$pairing['approval_code'], $userId,
        ]);
        if ($stmt->rowCount() < 1 && !homeserver_vp3_connection($userId)) {
            throw new RuntimeException('HomeServer connection was not found.');
        }
        return;
    }
    $stmt = $pdo->prepare(
        "INSERT INTO homeserver_connections (
            user_id,device_id,relay_token_enc,homeserver_token_enc,pending_request_id,pending_claim_token_enc,pending_code,status,last_error,last_checked_at
         ) VALUES (?,?,?,NULL,?,?,?,'awaiting_approval','',NULL)
         ON DUPLICATE KEY UPDATE
            device_id=VALUES(device_id),relay_token_enc=VALUES(relay_token_enc),homeserver_token_enc=NULL,
            pending_request_id=VALUES(pending_request_id),pending_claim_token_enc=VALUES(pending_claim_token_enc),
            pending_code=VALUES(pending_code),status='awaiting_approval',installed_version='',last_error='',last_checked_at=NULL"
    );
    $stmt->execute([
        $userId,$deviceId,homeserver_vp3_encrypt($relayToken),(string)$pairing['request_id'],
        homeserver_vp3_encrypt((string)$pairing['claim_token']),(string)$pairing['approval_code'],
    ]);
}

function homeserver_cloud_v1200_start_pairing(int $userId, string $claimCode): array
{
    if ($userId < 1) throw new RuntimeException('Authentication required.');
    homeserver_cloud_v1200_relay_security();
    if (homeserver_vp3_connection($userId)) {
        throw new RuntimeException('A HomeServer connection already exists. Re-pair it or disconnect it first.');
    }
    $claimCode = strtoupper(trim($claimCode));
    if (!preg_match('/^[A-Z0-9-]{8,40}$/', $claimCode)) {
        throw new RuntimeException('Enter the Remote Bridge claim code shown by HomeServer.');
    }

    $relayToken = '';
    try {
        $claim = homeserver_vp3_relay_request('POST', '/v1/claim', ['claim_code'=>$claimCode]);
        $relayToken = trim((string)($claim['relay_token'] ?? ''));
        $deviceId = trim((string)($claim['device_id'] ?? ''));
        if (strlen($relayToken) < 32 || strlen($relayToken) > 512 || !preg_match('/^hs-[a-f0-9]{24}$/', $deviceId)) {
            throw new RuntimeException('HomeServer relay returned an invalid claim response.');
        }
        if (isset($claim['trust_model']) && (string)$claim['trust_model'] !== 'trusted-relay') {
            throw new RuntimeException('HomeServer relay trust model is not supported.');
        }
        $pairing = homeserver_cloud_v1200_pair_request($relayToken);
        homeserver_cloud_v1200_store_pending($userId, $deviceId, $relayToken, $pairing, false);
        return [
            'device_id'=>$deviceId,
            'approval_code'=>(string)$pairing['approval_code'],
            'expires_at'=>(string)$pairing['expires_at'],
            'permissions'=>$pairing['permissions'],
        ];
    } catch (Throwable $e) {
        if ($relayToken !== '') {
            try { homeserver_vp3_relay_request('POST', '/v1/session/rotate', [], $relayToken); } catch (Throwable $ignored) {}
        }
        throw $e;
    }
}

function homeserver_cloud_v1200_repair(int $userId): array
{
    homeserver_cloud_v1200_relay_security();
    $row = homeserver_vp3_connection($userId);
    if (!$row) throw new RuntimeException('Connect HomeServer before re-pairing it.');
    $relayToken = homeserver_vp3_decrypt((string)($row['relay_token_enc'] ?? ''));
    if (strlen($relayToken) < 32) throw new RuntimeException('HomeServer relay authorization is unavailable.');
    $session = homeserver_vp3_relay_request('GET', '/v1/session', null, $relayToken);
    if (empty($session['connected'])) throw new RuntimeException('HomeServer is offline. Reconnect HomeServer and try again.');
    $pairing = homeserver_cloud_v1200_pair_request($relayToken);
    homeserver_cloud_v1200_store_pending($userId, (string)($row['device_id'] ?? ''), $relayToken, $pairing, true);
    return [
        'device_id'=>(string)($row['device_id'] ?? ''),
        'approval_code'=>(string)$pairing['approval_code'],
        'expires_at'=>(string)$pairing['expires_at'],
        'permissions'=>$pairing['permissions'],
    ];
}

function homeserver_cloud_v1200_check_pairing(int $userId): array
{
    homeserver_cloud_v1200_relay_security();
    $pairing = homeserver_vp3_check_pairing($userId);
    if (!empty($pairing['ready'])) {
        homeserver_vp3_status($userId, true);
    }
    return $pairing;
}

function homeserver_cloud_v1200_reconnect(int $userId): array
{
    homeserver_cloud_v1200_relay_security();
    if (!homeserver_vp3_connection($userId)) throw new RuntimeException('HomeServer is not connected to this account.');
    return homeserver_cloud_v1200_status($userId, true);
}

function homeserver_cloud_v1200_revoke_access(int $userId): void
{
    homeserver_cloud_v1200_relay_security();
    $row = homeserver_vp3_connection($userId);
    if (!$row) return;
    $relayToken = homeserver_vp3_decrypt((string)($row['relay_token_enc'] ?? ''));
    if ($relayToken !== '') {
        // A successful rotation revokes the exact relay credential held by VP3.
        // The replacement is intentionally discarded, so Cloud has no relay access afterward.
        homeserver_vp3_relay_request('POST', '/v1/session/rotate', [], $relayToken);
    }
    $pdo = db();
    if (!$pdo) throw new RuntimeException('Database connection is unavailable.');
    $pdo->prepare(
        "UPDATE homeserver_connections
         SET relay_token_enc=NULL,homeserver_token_enc=NULL,pending_request_id='',pending_claim_token_enc=NULL,pending_code='',
             status='revoked',last_error='',capabilities_json=NULL,last_checked_at=NOW()
         WHERE user_id=?"
    )->execute([$userId]);
}

function homeserver_cloud_v1200_remove_local(int $userId): void
{
    $row = homeserver_vp3_connection($userId);
    if (!$row) return;
    if (!empty($row['relay_token_enc']) || !empty($row['homeserver_token_enc']) || (string)($row['status'] ?? '') !== 'revoked') {
        throw new RuntimeException('HomeServer access must be revoked before removing the Cloud connection.');
    }
    $pdo = db();
    if (!$pdo) throw new RuntimeException('Database connection is unavailable.');
    $pdo->prepare('DELETE FROM homeserver_connections WHERE user_id=?')->execute([$userId]);
}

function homeserver_cloud_v1200_status(int $userId, bool $forceRefresh=false): array
{
    $row = homeserver_vp3_connection($userId);
    $relaySecurity = null;
    $relayError = '';
    try { $relaySecurity = homeserver_cloud_v1200_relay_security(); }
    catch (Throwable $e) { $relayError = homeserver_cloud_v1200_public_error($e->getMessage()); }

    if (!$row) {
        return [
            'build'=>VP3_HOMESERVER_CLOUD_PAIRING_V1200,
            'state'=>'unpaired','connection_state'=>'not_connected','connected'=>false,'paired'=>false,
            'relay_configured'=>$relaySecurity !== null,'relay_status'=>$relaySecurity ? 'ready' : 'configuration_error',
            'relay_host'=>$relaySecurity['host'] ?? '', 'device_id'=>'','device_name'=>'', 'last_seen_at'=>null,
            'installed_version'=>'','latest_release'=>null,'update_available'=>false,'agent_brain_ready'=>false,
            'inference'=>null,'capabilities'=>[],'paired_scopes'=>[],'pairing'=>null,'reconnect_status'=>'not_available',
            'error'=>$relayError,
        ];
    }

    if ($relaySecurity === null) {
        $decoded = [];
        if (!empty($row['capabilities_json'])) {
            $value = json_decode((string)$row['capabilities_json'], true);
            if (is_array($value)) $decoded = $value;
        }
        return [
            'build'=>VP3_HOMESERVER_CLOUD_PAIRING_V1200,
            'state'=>'error','connection_state'=>'connection_error','connected'=>false,'paired'=>!empty($row['homeserver_token_enc']),
            'relay_configured'=>false,'relay_status'=>'configuration_error','relay_host'=>'','device_id'=>(string)($row['device_id'] ?? ''),
            'device_name'=>(string)($decoded['service'] ?? 'HomeServer'),'last_seen_at'=>$row['last_seen_at'] ?? null,
            'installed_version'=>(string)($row['installed_version'] ?? ''),'latest_release'=>null,'update_available'=>false,
            'agent_brain_ready'=>false,'inference'=>null,
            'capabilities'=>is_array($decoded['features'] ?? null) ? array_values($decoded['features']) : [],
            'paired_scopes'=>!empty($row['homeserver_token_enc']) ? homeserver_cloud_v1200_permissions() : [],
            'pairing'=>!empty($row['pending_request_id']) ? ['status'=>'awaiting_approval','approval_code'=>(string)($row['pending_code'] ?? '')] : null,
            'reconnect_status'=>'blocked','error'=>$relayError,
        ];
    }

    $raw = homeserver_vp3_status($userId, $forceRefresh);
    $row = homeserver_vp3_connection($userId) ?? $row;
    $pending = trim((string)($row['pending_request_id'] ?? '')) !== '';
    $rowStatus = (string)($row['status'] ?? '');
    $connected = !empty($raw['connected']);
    $paired = !empty($raw['paired']);
    $connectionState = 'disconnected';
    if ($rowStatus === 'revoked') $connectionState = 'disconnected';
    elseif ($pending) $connectionState = 'waiting_for_approval';
    elseif ($connected && $paired) $connectionState = 'connected';
    elseif (in_array($rowStatus, ['error','expired','denied'], true)) $connectionState = 'connection_error';
    elseif (!$paired && $rowStatus === 'awaiting_approval') $connectionState = 'waiting_for_approval';

    $publicError = trim((string)($raw['error'] ?? '')) !== '' ? homeserver_cloud_v1200_public_error((string)$raw['error']) : '';
    $raw['build'] = VP3_HOMESERVER_CLOUD_PAIRING_V1200;
    $raw['connection_state'] = $connectionState;
    $raw['relay_status'] = $connected ? 'connected' : ($relaySecurity ? 'available' : 'configuration_error');
    $raw['relay_host'] = (string)$relaySecurity['host'];
    $raw['device_name'] = 'HomeServer';
    $raw['paired_scopes'] = $paired ? homeserver_cloud_v1200_permissions() : [];
    $raw['reconnect_status'] = $connected ? 'not_needed' : ($paired ? 'available' : 'not_available');
    $raw['pairing'] = $pending ? [
        'status'=>'awaiting_approval',
        'approval_code'=>(string)($row['pending_code'] ?? ''),
    ] : ($raw['pairing'] ?? null);
    $raw['error'] = $publicError;
    return $raw;
}
