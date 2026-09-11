<?php
declare(strict_types=1);

const VP3_HOMESERVER_APPROVALS_V028 = 'homeserver-approval-federation-v028-20260908';
const VP3_HOMESERVER_APPROVAL_FEATURE = 'approvals.federation.v1';

function homeserver_approvals_v028_permissions(): array
{
    return [
        'agent.chat','approvals.review','awareness.read','contacts.read','events.read','events.write',
        'knowledge.search','memory.read','memory.write','notifications.read','plugins.read',
        'scheduling.read','scheduling.write','tasks.read','tasks.write','tools.execute','usage.read','usage.write',
    ];
}

function homeserver_approvals_v028_credentials(int $userId): ?array
{
    if ($userId < 1) return null;
    try {
        $row = homeserver_vp3_connection($userId);
        if (!$row || empty($row['relay_token_enc']) || empty($row['homeserver_token_enc'])) return null;
        $relay = homeserver_vp3_decrypt((string)$row['relay_token_enc']);
        $home = homeserver_vp3_decrypt((string)$row['homeserver_token_enc']);
    } catch (Throwable $e) {
        return null;
    }
    if (strlen($relay) < 20 || strlen($home) < 20) return null;
    return ['relay'=>$relay,'home'=>$home];
}

function homeserver_approvals_v028_error_class(string $message): string
{
    $message = mb_strtolower($message);
    if (str_contains($message, 'approvals.review') || str_contains($message, 'permission') || str_contains($message, '403')) return 'permission_required';
    if (str_contains($message, '401') || str_contains($message, 'bearer') || str_contains($message, 'revoked') || str_contains($message, 'authorization')) return 'authorization';
    if (str_contains($message, 'timeout') || str_contains($message, 'timed out')) return 'timeout';
    if (str_contains($message, 'relay') || str_contains($message, 'offline') || str_contains($message, 'connect') || str_contains($message, 'curl')) return 'offline';
    if (str_contains($message, 'conflict') || str_contains($message, 'pending') || str_contains($message, 'already') || str_contains($message, 'terminal')) return 'conflict';
    return 'unavailable';
}

function homeserver_approvals_v028_state(int $userId, bool $forceRefresh=false): array
{
    $status = homeserver_vp3_status($userId, $forceRefresh);
    $features = is_array($status['capabilities'] ?? null) ? $status['capabilities'] : [];
    $supported = in_array(VP3_HOMESERVER_APPROVAL_FEATURE, $features, true);
    $paired = !empty($status['paired']);
    $connected = !empty($status['connected']);
    $row = homeserver_vp3_connection($userId);
    $pendingRequestId = trim((string)($row['pending_request_id'] ?? ''));
    $pendingCode = trim((string)($row['pending_code'] ?? ''));
    return [
        'build'=>VP3_HOMESERVER_APPROVALS_V028,
        'connected'=>$connected,
        'paired'=>$paired,
        'supported'=>$supported,
        'state'=>(string)($status['state'] ?? 'unpaired'),
        'installed_version'=>(string)($status['installed_version'] ?? ''),
        'permission'=>'unknown',
        'permission_upgrade_pending'=>$paired && $pendingRequestId !== '' && $pendingCode !== '',
        'approval_code'=>$paired && $pendingRequestId !== '' ? $pendingCode : '',
        'error'=>(string)($status['error'] ?? ''),
    ];
}

function homeserver_approvals_v028_claim_and_pair(int $userId, string $claimCode): array
{
    if ($userId < 1) throw new RuntimeException('A signed-in user is required.');
    $claimCode = strtoupper(trim($claimCode));
    if (!preg_match('/^[A-Z0-9-]{8,40}$/', $claimCode)) {
        throw new RuntimeException('Enter the Remote Bridge claim code shown by HomeServer.');
    }
    $claim = homeserver_vp3_relay_request('POST', '/v1/claim', ['claim_code'=>$claimCode]);
    $relayToken = trim((string)($claim['relay_token'] ?? ''));
    $deviceId = trim((string)($claim['device_id'] ?? ''));
    if (strlen($relayToken) < 20 || $deviceId === '') {
        throw new RuntimeException('HomeServer relay returned an invalid claim response.');
    }

    $permissions = homeserver_approvals_v028_permissions();
    $pairing = homeserver_vp3_remote_operation($relayToken, 'pair.request', [
        'app_key'=>'vp3',
        'app_name'=>'VP3',
        'permissions'=>$permissions,
    ]);
    $requestId = trim((string)($pairing['request_id'] ?? ''));
    $claimToken = trim((string)($pairing['claim_token'] ?? ''));
    $approvalCode = trim((string)($pairing['code'] ?? ''));
    if ($requestId === '' || strlen($claimToken) < 20 || $approvalCode === '') {
        try { homeserver_vp3_relay_request('POST', '/v1/session/rotate', [], $relayToken); } catch (Throwable $e) {}
        throw new RuntimeException('HomeServer returned an unsupported pairing response.');
    }

    $pdo = db();
    if (!$pdo) throw new RuntimeException('Database connection is unavailable.');
    homeserver_vp3_ensure_schema($pdo);
    $stmt = $pdo->prepare(
        "INSERT INTO homeserver_connections (
            user_id,device_id,relay_token_enc,pending_request_id,pending_claim_token_enc,pending_code,status,last_error
         ) VALUES (?,?,?,?,?,?,'awaiting_approval','')
         ON DUPLICATE KEY UPDATE
            device_id=VALUES(device_id), relay_token_enc=VALUES(relay_token_enc),
            homeserver_token_enc=NULL, pending_request_id=VALUES(pending_request_id),
            pending_claim_token_enc=VALUES(pending_claim_token_enc), pending_code=VALUES(pending_code),
            status='awaiting_approval', installed_version='', last_error='', last_checked_at=NULL"
    );
    $stmt->execute([
        $userId,
        $deviceId,
        homeserver_vp3_encrypt($relayToken),
        $requestId,
        homeserver_vp3_encrypt($claimToken),
        $approvalCode,
    ]);
    return [
        'device_id'=>$deviceId,
        'approval_code'=>$approvalCode,
        'expires_at'=>(string)($pairing['expires_at'] ?? ''),
        'permissions'=>$pairing['permissions'] ?? $permissions,
    ];
}

function homeserver_approvals_v028_list(int $userId, string $status='pending', int $limit=100): array
{
    $state = homeserver_approvals_v028_state($userId, false);
    if (!$state['paired']) {
        $state['permission'] = 'unavailable';
        return ['ok'=>false,'state'=>$state,'items'=>[],'error'=>'Pair a HomeServer before reviewing HomeServer approvals.'];
    }
    if (!$state['connected']) {
        $state['permission'] = 'unavailable';
        return ['ok'=>false,'state'=>$state,'items'=>[],'error'=>'HomeServer is offline.'];
    }
    if (!$state['supported']) {
        $state['permission'] = 'unsupported';
        return ['ok'=>false,'state'=>$state,'items'=>[],'error'=>'This HomeServer does not advertise approval federation. Update HomeServer and refresh the connection.'];
    }
    $credentials = homeserver_approvals_v028_credentials($userId);
    if (!$credentials) {
        $state['permission'] = 'authorization';
        return ['ok'=>false,'state'=>$state,'items'=>[],'error'=>'VP3 cannot use the paired HomeServer credential. Re-pair HomeServer.'];
    }
    $allowed = ['pending','executing','executed','denied','failed','expired'];
    if (!in_array($status, $allowed, true)) $status = 'pending';
    $limit = max(1, min(200, $limit));
    try {
        $result = homeserver_vp3_remote_operation($credentials['relay'], 'action.list', ['status'=>$status,'limit'=>$limit], $credentials['home']);
        $items = is_array($result['items'] ?? null) ? array_values(array_filter($result['items'], 'is_array')) : [];
        $state['permission'] = 'granted';
        $state['permission_upgrade_pending'] = false;
        $state['approval_code'] = '';
        return ['ok'=>true,'state'=>$state,'items'=>$items,'error'=>''];
    } catch (Throwable $e) {
        $class = homeserver_approvals_v028_error_class($e->getMessage());
        $state['permission'] = $class;
        $message = match ($class) {
            'permission_required' => 'HomeServer approval review needs one additional permission. Approve the one-time permission upgrade in HomeServer.',
            'authorization' => 'HomeServer authorization expired. Re-pair HomeServer.',
            'timeout' => 'HomeServer did not respond in time.',
            'offline' => 'HomeServer or its relay is unavailable.',
            default => 'HomeServer approvals are temporarily unavailable.',
        };
        return ['ok'=>false,'state'=>$state,'items'=>[],'error'=>$message];
    }
}

function homeserver_approvals_v028_review(int $userId, string $requestId, string $decision): array
{
    if (!preg_match('/^[A-Za-z0-9._:-]{8,160}$/', $requestId)) {
        throw new RuntimeException('Invalid HomeServer action request.');
    }
    if (!in_array($decision, ['approve','deny'], true)) {
        throw new RuntimeException('Invalid approval decision.');
    }
    $state = homeserver_approvals_v028_state($userId, false);
    if (empty($state['connected']) || empty($state['paired']) || empty($state['supported'])) {
        throw new RuntimeException('HomeServer approval federation is not available.');
    }
    $credentials = homeserver_approvals_v028_credentials($userId);
    if (!$credentials) throw new RuntimeException('HomeServer authorization is unavailable.');
    $operation = $decision === 'approve' ? 'action.approve' : 'action.deny';
    try {
        $result = homeserver_vp3_remote_operation($credentials['relay'], $operation, ['request_id'=>$requestId], $credentials['home']);
    } catch (Throwable $e) {
        $class = homeserver_approvals_v028_error_class($e->getMessage());
        $message = match ($class) {
            'permission_required' => 'Approval review permission is required. Complete the HomeServer permission upgrade.',
            'authorization' => 'HomeServer authorization expired. Re-pair HomeServer.',
            'timeout' => 'HomeServer did not respond in time.',
            'offline' => 'HomeServer or its relay is unavailable.',
            'conflict' => 'This action request is no longer pending. Refresh approvals.',
            default => 'HomeServer could not review this action request.',
        };
        throw new RuntimeException($message);
    }
    $request = is_array($result['request'] ?? null) ? $result['request'] : [];
    return ['ok'=>true,'request'=>$request];
}

function homeserver_approvals_v028_begin_permission_upgrade(int $userId): array
{
    $state = homeserver_approvals_v028_state($userId, true);
    if (empty($state['connected']) || empty($state['paired'])) {
        throw new RuntimeException('Pair and connect HomeServer before upgrading approval permissions.');
    }
    if (empty($state['supported'])) {
        throw new RuntimeException('Update HomeServer before enabling federated approvals.');
    }
    $row = homeserver_vp3_connection($userId);
    if (!$row) throw new RuntimeException('HomeServer connection was not found.');

    $pendingRequestId = trim((string)($row['pending_request_id'] ?? ''));
    $pendingCode = trim((string)($row['pending_code'] ?? ''));
    if ($pendingRequestId !== '' && $pendingCode !== '') {
        return ['ok'=>true,'pending'=>true,'approval_code'=>$pendingCode,'request_id'=>$pendingRequestId];
    }

    $relay = homeserver_vp3_decrypt((string)($row['relay_token_enc'] ?? ''));
    if (strlen($relay) < 20) throw new RuntimeException('HomeServer relay authorization is unavailable.');
    $pairing = homeserver_vp3_remote_operation($relay, 'pair.request', [
        'app_key'=>'vp3',
        'app_name'=>'VP3',
        'permissions'=>homeserver_approvals_v028_permissions(),
    ]);
    $requestId = trim((string)($pairing['request_id'] ?? ''));
    $claimToken = trim((string)($pairing['claim_token'] ?? ''));
    $approvalCode = trim((string)($pairing['code'] ?? ''));
    if ($requestId === '' || strlen($claimToken) < 20 || $approvalCode === '') {
        throw new RuntimeException('HomeServer returned an unsupported permission-upgrade response.');
    }
    $pdo = db();
    if (!$pdo) throw new RuntimeException('Database connection is unavailable.');
    $stmt = $pdo->prepare("UPDATE homeserver_connections SET pending_request_id=?,pending_claim_token_enc=?,pending_code=?,last_error='' WHERE user_id=?");
    $stmt->execute([$requestId,homeserver_vp3_encrypt($claimToken),$approvalCode,$userId]);
    return [
        'ok'=>true,
        'pending'=>true,
        'approval_code'=>$approvalCode,
        'request_id'=>$requestId,
        'expires_at'=>(string)($pairing['expires_at'] ?? ''),
    ];
}

function homeserver_approvals_v028_check_permission_upgrade(int $userId): array
{
    $row = homeserver_vp3_connection($userId);
    if (!$row) throw new RuntimeException('HomeServer connection was not found.');
    if (trim((string)($row['pending_request_id'] ?? '')) !== '') {
        $pairing = homeserver_vp3_check_pairing($userId);
        if (($pairing['status'] ?? '') !== 'paired') {
            return ['ok'=>true,'ready'=>false,'pairing'=>$pairing];
        }
    }
    $list = homeserver_approvals_v028_list($userId, 'pending', 1);
    return ['ok'=>true,'ready'=>!empty($list['ok']),'state'=>$list['state'],'error'=>$list['error'] ?? ''];
}
