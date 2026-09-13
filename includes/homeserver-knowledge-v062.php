<?php
declare(strict_types=1);

require_once __DIR__ . '/homeserver-cloud-pairing-v1200.php';

const VP3_HOMESERVER_KNOWLEDGE_V062 = 'homeserver-knowledge-v062-20260912';
const VP3_HOMESERVER_KNOWLEDGE_SOURCE_SHA = '8e99d762aa39f8ce6165013c635175249e37f4d3';
const VP3_HOMESERVER_KNOWLEDGE_MAP_TIMEOUT = 660;

final class HomeServerKnowledgeV062Exception extends RuntimeException
{
    public function __construct(
        public readonly string $publicCode,
        public readonly string $publicMessage,
        public readonly int $httpStatus = 400
    ) {
        parent::__construct($publicCode);
    }
}

function homeserver_knowledge_v062_fail(string $code, string $message, int $status = 400): never
{
    throw new HomeServerKnowledgeV062Exception($code, $message, $status);
}

function homeserver_knowledge_v062_credentials(int $userId): array
{
    if ($userId < 1) {
        homeserver_knowledge_v062_fail('authentication', 'Sign in to use HomeServer Knowledge.', 401);
    }
    $row = homeserver_vp3_connection($userId);
    if (!$row) {
        homeserver_knowledge_v062_fail('not_paired', 'Connect HomeServer before using Local Knowledge.', 409);
    }
    try {
        $relay = homeserver_vp3_decrypt((string)($row['relay_token_enc'] ?? ''));
        $home = homeserver_vp3_decrypt((string)($row['homeserver_token_enc'] ?? ''));
    } catch (Throwable $e) {
        homeserver_knowledge_v062_fail('authorization', 'HomeServer authorization is unavailable. Re-pair HomeServer.', 401);
    }
    if (strlen($relay) < 20 || strlen($home) < 20) {
        homeserver_knowledge_v062_fail('authorization', 'HomeServer authorization is unavailable. Re-pair HomeServer.', 401);
    }
    return ['relay' => $relay, 'home' => $home, 'row' => $row];
}

function homeserver_knowledge_v062_classify_transport(Throwable $e): never
{
    $message = mb_strtolower($e->getMessage());
    if (str_contains($message, 'unsupported')) {
        homeserver_knowledge_v062_fail('update_required', 'Update HomeServer to v0.62 or later to use Local Knowledge.', 409);
    }
    if (str_contains($message, 'permission') || str_contains($message, '403')) {
        homeserver_knowledge_v062_fail('permission_required', 'HomeServer needs one-time approval for Knowledge write access.', 403);
    }
    if (str_contains($message, '401') || str_contains($message, 'authorization') || str_contains($message, 'bearer') || str_contains($message, 'revoked')) {
        homeserver_knowledge_v062_fail('authorization', 'HomeServer authorization is no longer valid. Re-pair HomeServer.', 401);
    }
    if (str_contains($message, 'timeout') || str_contains($message, 'timed out')) {
        homeserver_knowledge_v062_fail('timeout', 'HomeServer did not respond in time. The local folder picker may have closed or timed out.', 504);
    }
    homeserver_knowledge_v062_fail('unavailable', 'HomeServer or its secure relay is temporarily unavailable.', 503);
}

function homeserver_knowledge_v062_unwrap(array $response): array
{
    $status = (int)($response['status'] ?? 200);
    $ok = array_key_exists('ok', $response) ? !empty($response['ok']) : ($status >= 200 && $status < 300);
    if (!$ok || $status < 200 || $status >= 300) {
        if ($status === 401) {
            homeserver_knowledge_v062_fail('authorization', 'HomeServer authorization is no longer valid. Re-pair HomeServer.', 401);
        }
        if ($status === 403) {
            homeserver_knowledge_v062_fail('permission_required', 'HomeServer needs one-time approval for Knowledge write access.', 403);
        }
        if ($status === 404) {
            homeserver_knowledge_v062_fail('not_found', 'The HomeServer Knowledge resource no longer exists.', 404);
        }
        if ($status === 409) {
            homeserver_knowledge_v062_fail('conflict', 'HomeServer could not complete that Knowledge action in its current state.', 409);
        }
        if ($status === 422 || $status === 400) {
            homeserver_knowledge_v062_fail('invalid_request', 'HomeServer rejected the Knowledge request.', 422);
        }
        homeserver_knowledge_v062_fail('unavailable', 'HomeServer Knowledge is temporarily unavailable.', 503);
    }
    $payload = $response['payload'] ?? $response;
    if (!is_array($payload)) {
        homeserver_knowledge_v062_fail('invalid_response', 'HomeServer returned an invalid Knowledge response.', 502);
    }
    return $payload;
}

function homeserver_knowledge_v062_long_remote_operation(string $relayToken, string $operation, array $payload, string $homeServerToken): array
{
    if ($operation !== 'knowledge.folder.map') {
        homeserver_knowledge_v062_fail('unsupported_operation', 'Unsupported Local Knowledge action.', 400);
    }
    homeserver_cloud_v1200_relay_security();
    $base = homeserver_vp3_relay_base_url();
    if ($base === '' || !function_exists('curl_init')) {
        homeserver_knowledge_v062_fail('unavailable', 'HomeServer secure relay is unavailable.', 503);
    }
    $ch = curl_init($base . '/v1/request');
    if ($ch === false) {
        homeserver_knowledge_v062_fail('unavailable', 'HomeServer secure relay is unavailable.', 503);
    }
    $request = json_encode([
        'operation' => $operation,
        'payload' => $payload,
        'bearer_token' => $homeServerToken,
    ], JSON_UNESCAPED_SLASHES);
    if (!is_string($request)) {
        curl_close($ch);
        homeserver_knowledge_v062_fail('invalid_request', 'Could not prepare the Local Knowledge request.', 500);
    }
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => false,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_CONNECTTIMEOUT => 3,
        CURLOPT_TIMEOUT => VP3_HOMESERVER_KNOWLEDGE_MAP_TIMEOUT,
        CURLOPT_HTTPHEADER => [
            'Accept: application/json',
            'Content-Type: application/json',
            'Authorization: Bearer ' . $relayToken,
        ],
        CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
        CURLOPT_CUSTOMREQUEST => 'POST',
        CURLOPT_POSTFIELDS => $request,
    ]);
    $body = curl_exec($ch);
    $curlError = curl_error($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    if (!is_string($body)) {
        if ($curlError !== '' && str_contains(mb_strtolower($curlError), 'timed out')) {
            homeserver_knowledge_v062_fail('timeout', 'HomeServer did not respond in time. The local folder picker may have closed or timed out.', 504);
        }
        homeserver_knowledge_v062_fail('unavailable', 'HomeServer or its secure relay is temporarily unavailable.', 503);
    }
    $data = json_decode($body, true);
    if (!is_array($data)) {
        homeserver_knowledge_v062_fail('invalid_response', 'HomeServer returned an invalid Knowledge response.', 502);
    }
    if ($status < 200 || $status >= 300) {
        if ($status === 401) homeserver_knowledge_v062_fail('authorization', 'HomeServer authorization is no longer valid. Re-pair HomeServer.', 401);
        if ($status === 403) homeserver_knowledge_v062_fail('permission_required', 'HomeServer needs one-time approval for Knowledge write access.', 403);
        homeserver_knowledge_v062_fail('unavailable', 'HomeServer or its secure relay is temporarily unavailable.', 503);
    }
    $result = $data['payload'] ?? [];
    if (!is_array($result)) {
        homeserver_knowledge_v062_fail('invalid_response', 'HomeServer returned an invalid Knowledge response.', 502);
    }
    return $result;
}

function homeserver_knowledge_v062_remote(int $userId, string $operation, array $payload = []): array
{
    $allowed = [
        'knowledge.collections.list',
        'knowledge.folders.list',
        'knowledge.folder.map',
        'knowledge.folder.unmap',
        'knowledge.item.write',
    ];
    if (!in_array($operation, $allowed, true)) {
        homeserver_knowledge_v062_fail('unsupported_operation', 'Unsupported Local Knowledge action.', 400);
    }
    $credentials = homeserver_knowledge_v062_credentials($userId);
    try {
        $response = $operation === 'knowledge.folder.map'
            ? homeserver_knowledge_v062_long_remote_operation($credentials['relay'], $operation, $payload, $credentials['home'])
            : homeserver_vp3_remote_operation($credentials['relay'], $operation, $payload, $credentials['home']);
    } catch (HomeServerKnowledgeV062Exception $e) {
        throw $e;
    } catch (Throwable $e) {
        homeserver_knowledge_v062_classify_transport($e);
    }
    return homeserver_knowledge_v062_unwrap($response);
}

function homeserver_knowledge_v062_collection(array $item): array
{
    return [
        'collection_key' => substr(trim((string)($item['collection_key'] ?? '')), 0, 64),
        'name' => mb_substr(trim((string)($item['name'] ?? 'Collection')), 0, 120),
        'description' => mb_substr(trim((string)($item['description'] ?? '')), 0, 500),
        'source_count' => max(0, (int)($item['source_count'] ?? 0)),
        'direct_item_count' => max(0, (int)($item['direct_item_count'] ?? 0)),
    ];
}

function homeserver_knowledge_v062_mapping(array $item): array
{
    $mappingId = trim((string)($item['mapping_id'] ?? ''));
    if (!preg_match('/^source-\d{1,18}$/', $mappingId)) $mappingId = '';
    return [
        'mapping_id' => $mappingId,
        'label' => mb_substr(trim((string)($item['label'] ?? 'Local folder')), 0, 200),
        'collection_key' => substr(trim((string)($item['collection_key'] ?? '')), 0, 64),
        'collection_name' => mb_substr(trim((string)($item['collection_name'] ?? 'General')), 0, 120),
        'enabled' => !empty($item['enabled']),
        'recursive' => !empty($item['recursive']),
        'scan_interval_seconds' => max(30, min(3600, (int)($item['scan_interval_seconds'] ?? 120))),
        'status' => substr(trim((string)($item['status'] ?? 'pending')), 0, 40),
        'last_scan_completed_at' => isset($item['last_scan_completed_at']) ? substr((string)$item['last_scan_completed_at'], 0, 40) : null,
        'tracked_files' => max(0, (int)($item['tracked_files'] ?? 0)),
        'indexed_files' => max(0, (int)($item['indexed_files'] ?? 0)),
        'error_files' => max(0, (int)($item['error_files'] ?? 0)),
    ];
}

function homeserver_knowledge_v062_collections(int $userId): array
{
    $payload = homeserver_knowledge_v062_remote($userId, 'knowledge.collections.list');
    $items = is_array($payload['items'] ?? null) ? $payload['items'] : [];
    $safe = [];
    foreach ($items as $item) {
        if (!is_array($item)) continue;
        $collection = homeserver_knowledge_v062_collection($item);
        if ($collection['collection_key'] !== '') $safe[] = $collection;
    }
    return [
        'items' => $safe,
        'default_collection' => substr(trim((string)($payload['default_collection'] ?? 'general')), 0, 64),
        'scope_restricted' => !empty($payload['scope']['restricted']),
    ];
}

function homeserver_knowledge_v062_mappings(int $userId): array
{
    $payload = homeserver_knowledge_v062_remote($userId, 'knowledge.folders.list');
    $items = is_array($payload['items'] ?? null) ? $payload['items'] : [];
    $safe = [];
    foreach ($items as $item) {
        if (!is_array($item)) continue;
        $mapping = homeserver_knowledge_v062_mapping($item);
        if ($mapping['mapping_id'] !== '') $safe[] = $mapping;
    }
    return [
        'items' => $safe,
        'privacy' => [
            'absolute_paths_exposed' => false,
            'picker_runs_on_homeserver' => true,
            'local_index' => true,
            'full_documents_returned' => false,
        ],
    ];
}

function homeserver_knowledge_v062_snapshot(int $userId, bool $force = false): array
{
    try {
        $status = homeserver_cloud_v1200_status($userId, $force);
    } catch (Throwable $e) {
        $status = ['connected' => false, 'paired' => false, 'connection_state' => 'connection_error', 'installed_version' => ''];
    }
    $row = homeserver_vp3_connection($userId);
    $snapshot = [
        'build' => VP3_HOMESERVER_KNOWLEDGE_V062,
        'contract_sha' => VP3_HOMESERVER_KNOWLEDGE_SOURCE_SHA,
        'connected' => !empty($status['connected']),
        'paired' => !empty($status['paired']),
        'connection_state' => substr((string)($status['connection_state'] ?? $status['state'] ?? 'not_connected'), 0, 40),
        'installed_version' => substr((string)($status['installed_version'] ?? ''), 0, 64),
        'supported' => false,
        'collections' => [],
        'mappings' => [],
        'default_collection' => 'general',
        'scope_restricted' => false,
        'privacy' => [
            'absolute_paths_exposed' => false,
            'picker_runs_on_homeserver' => true,
            'cloud_stores_native_paths' => false,
        ],
        'permission_upgrade' => [
            'pending' => is_array($row) && trim((string)($row['pending_request_id'] ?? '')) !== '',
            'approval_code' => is_array($row) ? substr(trim((string)($row['pending_code'] ?? '')), 0, 40) : '',
        ],
        'error' => '',
        'error_code' => '',
    ];
    if (!$snapshot['paired']) {
        $snapshot['error'] = 'Connect HomeServer to use Local Knowledge.';
        $snapshot['error_code'] = 'not_paired';
        return $snapshot;
    }
    if (!$snapshot['connected']) {
        $snapshot['error'] = 'HomeServer is paired but currently offline.';
        $snapshot['error_code'] = 'offline';
        return $snapshot;
    }
    try {
        $collections = homeserver_knowledge_v062_collections($userId);
        $mappings = homeserver_knowledge_v062_mappings($userId);
        $snapshot['supported'] = true;
        $snapshot['collections'] = $collections['items'];
        $snapshot['default_collection'] = $collections['default_collection'];
        $snapshot['scope_restricted'] = $collections['scope_restricted'];
        $snapshot['mappings'] = $mappings['items'];
    } catch (HomeServerKnowledgeV062Exception $e) {
        $snapshot['error'] = $e->publicMessage;
        $snapshot['error_code'] = $e->publicCode;
    }
    return $snapshot;
}

function homeserver_knowledge_v062_map_folder(int $userId, array $input): array
{
    $collection = trim((string)($input['collection_key'] ?? ''));
    if (!preg_match('/^[a-zA-Z0-9_.:-]{1,64}$/', $collection)) {
        homeserver_knowledge_v062_fail('invalid_request', 'Choose a valid HomeServer Knowledge collection.', 422);
    }
    $label = mb_substr(trim((string)($input['label'] ?? '')), 0, 200);
    $recursive = !isset($input['recursive']) || (string)$input['recursive'] !== '0';
    $scan = max(30, min(3600, (int)($input['scan_interval_seconds'] ?? 120)));
    $excludes = [];
    foreach ((array)($input['excludes'] ?? []) as $exclude) {
        $exclude = mb_substr(trim((string)$exclude), 0, 200);
        if ($exclude !== '' && count($excludes) < 40) $excludes[] = $exclude;
    }
    $payload = homeserver_knowledge_v062_remote($userId, 'knowledge.folder.map', [
        'collection_key' => $collection,
        'label' => $label,
        'recursive' => $recursive,
        'scan_interval_seconds' => $scan,
        'excludes' => $excludes,
    ]);
    if (!empty($payload['cancelled'])) return ['created' => false, 'cancelled' => true, 'mapping' => null];
    $mapping = is_array($payload['mapping'] ?? null) ? homeserver_knowledge_v062_mapping($payload['mapping']) : null;
    return [
        'created' => !empty($payload['created']),
        'cancelled' => false,
        'scan_state' => substr((string)($payload['scan_state'] ?? ''), 0, 40),
        'mapping' => $mapping,
    ];
}

function homeserver_knowledge_v062_unmap_folder(int $userId, string $mappingId): array
{
    $mappingId = trim($mappingId);
    if (!preg_match('/^source-\d{1,18}$/', $mappingId)) {
        homeserver_knowledge_v062_fail('invalid_request', 'Choose a valid Local Knowledge folder mapping.', 422);
    }
    $payload = homeserver_knowledge_v062_remote($userId, 'knowledge.folder.unmap', ['mapping_id' => $mappingId]);
    return [
        'deleted' => !empty($payload['deleted']),
        'mapping_id' => preg_match('/^source-\d{1,18}$/', (string)($payload['mapping_id'] ?? '')) ? (string)$payload['mapping_id'] : $mappingId,
    ];
}

function homeserver_knowledge_v062_write_item(int $userId, array $input): array
{
    $collection = trim((string)($input['collection_key'] ?? ''));
    $title = mb_substr(trim((string)($input['title'] ?? '')), 0, 240);
    $content = trim((string)($input['content'] ?? ''));
    $kind = strtolower(trim((string)($input['kind'] ?? 'summary')));
    if (!preg_match('/^[a-zA-Z0-9_.:-]{1,64}$/', $collection) || $title === '' || $content === '') {
        homeserver_knowledge_v062_fail('invalid_request', 'Collection, title and knowledge text are required.', 422);
    }
    if (strlen($content) > 250000 || !preg_match('/^[a-z0-9_.:-]{1,40}$/', $kind)) {
        homeserver_knowledge_v062_fail('invalid_request', 'The Local Knowledge item is too large or has an invalid type.', 422);
    }
    $payload = homeserver_knowledge_v062_remote($userId, 'knowledge.item.write', [
        'collection_key' => $collection,
        'title' => $title,
        'content' => $content,
        'kind' => $kind,
    ]);
    $item = is_array($payload['item'] ?? null) ? $payload['item'] : [];
    return [
        'created' => !empty($payload['created']),
        'item' => [
            'id' => max(0, (int)($item['id'] ?? 0)),
            'title' => mb_substr(trim((string)($item['title'] ?? $title)), 0, 240),
            'kind' => substr(trim((string)($item['kind'] ?? $kind)), 0, 40),
            'collection_key' => substr(trim((string)($item['collection_key'] ?? $collection)), 0, 64),
            'collection_name' => mb_substr(trim((string)($item['collection_name'] ?? '')), 0, 120),
            'chunk_count' => max(0, (int)($item['chunk_count'] ?? 0)),
        ],
    ];
}

function homeserver_knowledge_v062_request_write_permission(int $userId): array
{
    $credentials = homeserver_knowledge_v062_credentials($userId);
    $row = $credentials['row'];
    $pendingId = trim((string)($row['pending_request_id'] ?? ''));
    $pendingCode = trim((string)($row['pending_code'] ?? ''));
    if ($pendingId !== '' && $pendingCode !== '') {
        return ['pending' => true, 'approval_code' => substr($pendingCode, 0, 40), 'existing' => true];
    }
    $permissions = array_values(array_unique(array_merge(homeserver_cloud_v1200_permissions(), ['knowledge.write'])));
    try {
        $pairing = homeserver_vp3_remote_operation($credentials['relay'], 'pair.request', [
            'app_key' => 'vp3',
            'app_name' => 'VP3',
            'permissions' => $permissions,
        ]);
    } catch (Throwable $e) {
        homeserver_knowledge_v062_classify_transport($e);
    }
    $requestId = trim((string)($pairing['request_id'] ?? ''));
    $claimToken = trim((string)($pairing['claim_token'] ?? ''));
    $approvalCode = strtoupper(trim((string)($pairing['code'] ?? '')));
    if ($requestId === '' || strlen($requestId) > 128 || strlen($claimToken) < 32 || strlen($claimToken) > 512 || !preg_match('/^[A-Z0-9-]{8,40}$/', $approvalCode)) {
        homeserver_knowledge_v062_fail('invalid_response', 'HomeServer returned an invalid permission-upgrade response.', 502);
    }
    homeserver_cloud_v1200_store_pending($userId, (string)($row['device_id'] ?? ''), $credentials['relay'], [
        'request_id' => $requestId,
        'claim_token' => $claimToken,
        'approval_code' => $approvalCode,
    ], true);
    return [
        'pending' => true,
        'approval_code' => $approvalCode,
        'expires_at' => substr((string)($pairing['expires_at'] ?? ''), 0, 64),
        'existing' => false,
    ];
}

function homeserver_knowledge_v062_check_write_permission(int $userId): array
{
    $row = homeserver_vp3_connection($userId);
    if (!$row) homeserver_knowledge_v062_fail('not_paired', 'Connect HomeServer before using Local Knowledge.', 409);
    if (trim((string)($row['pending_request_id'] ?? '')) === '') {
        return ['ready' => false, 'pending' => false, 'status' => 'none'];
    }
    try {
        $pairing = homeserver_vp3_check_pairing($userId);
    } catch (Throwable $e) {
        homeserver_knowledge_v062_classify_transport($e);
    }
    return [
        'ready' => !empty($pairing['ready']),
        'pending' => !empty($pairing['status']) && !in_array((string)$pairing['status'], ['paired', 'denied', 'expired'], true),
        'status' => substr((string)($pairing['status'] ?? 'pending'), 0, 30),
    ];
}
