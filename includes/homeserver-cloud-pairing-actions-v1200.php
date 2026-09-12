<?php
declare(strict_types=1);

require_once __DIR__ . '/homeserver-cloud-pairing-v1200.php';

function homeserver_cloud_v1200_cancel_pairing(int $userId): void
{
    $row = homeserver_vp3_connection($userId);
    if (!$row) return;
    if (trim((string)($row['pending_request_id'] ?? '')) === '') return;
    $pdo = db();
    if (!$pdo) throw new RuntimeException('Database connection is unavailable.');
    $hasApproved = !empty($row['homeserver_token_enc']);
    $status = $hasApproved ? 'paired' : 'connected';
    $pdo->prepare(
        "UPDATE homeserver_connections
         SET pending_request_id='',pending_claim_token_enc=NULL,pending_code='',status=?,last_error='',last_checked_at=NULL
         WHERE user_id=?"
    )->execute([$status,$userId]);
}

function homeserver_cloud_v1200_check_pairing_safe(int $userId): array
{
    homeserver_cloud_v1200_relay_security();
    $before = homeserver_vp3_connection($userId);
    if (!$before) throw new RuntimeException('HomeServer has not been claimed.');
    $hadApprovedToken = !empty($before['homeserver_token_enc']);
    $result = homeserver_vp3_check_pairing($userId);
    $pairState = (string)($result['status'] ?? 'pending');

    if (!empty($result['ready'])) {
        homeserver_vp3_status($userId, true);
        return $result;
    }

    if ($hadApprovedToken && in_array($pairState, ['denied','expired'], true)) {
        $pdo = db();
        if (!$pdo throw new RuntimeException('Database connection is unavailable.'));
    }
    return homeserver_cloud_v1200_restore_previous_pairing($userId, $result, $hadApprovedToken, $pairState);
}

function homeserver_cloud_v1200_restore_previous_pairing(int $userId, array $result, bool $hadApprovedToken, string $pairState): array
{
    if (!$hadApprovedToken || !in_array($pairState, ['denied','expired'], true)) return $result;
    $pdo = db();
    if (!$pdo) throw new RuntimeException('Database connection is unavailable.');
    $pdo->prepare(
        "UPDATE homeserver_connections
         SET status='paired',last_error='',last_checked_at=NULL
         WHERE user_id=? AND homeserver_token_enc IS NOT NULL"
    )->execute([$userId]);
    homeserver_vp3_status($userId, true);
    $result['retained_previous_pairing'] = true;
    return $result;
}

function homeserver_cloud_v1200_disconnect(int $userId): void
{
    homeserver_cloud_v1200_relay_security();
    $row = homeserver_vp3_connection($userId);
    if (!$row) return;
    $relayToken = homeserver_vp3_decrypt((string)($row['relay_token_enc'] ?? ''));
    if ($relayToken === '') throw new RuntimeException('HomeServer relay authorization is unavailable.');

    // Rotate first so every previously issued Cloud relay credential is invalidated.
    // Preserve only the replacement server-side so this already-claimed HomeServer can be re-paired without an owner reset.
    $rotated = homeserver_vp3_relay_request('POST', '/v1/session/rotate', [], $relayToken);
    $replacement = trim((string)($rotated['relay_token'] ?? ''));
    if (strlen($replacement) < 32 || strlen($replacement) > 512) {
        throw new RuntimeException('HomeServer relay did not return a replacement authorization.');
    }

    $pdo = db();
    if (!$pdo) throw new RuntimeException('Database connection is unavailable.');
    $pdo->prepare(
        "UPDATE homeserver_connections
         SET relay_token_enc=?,homeserver_token_enc=NULL,pending_request_id='',pending_claim_token_enc=NULL,pending_code='',
             status='disconnected',last_error='',capabilities_json=NULL,last_checked_at=NOW()
         WHERE user_id=?"
    )->execute([homeserver_vp3_encrypt($replacement),$userId]);
}

function homeserver_cloud_v1200_remove_pairing(int $userId): void
{
    homeserver_cloud_v1200_relay_security();
    $row = homeserver_vp3_connection($userId);
    if (!$row) return;
    $relayToken = homeserver_vp3_decrypt((string)($row['relay_token_enc'] ?? ''));
    if ($relayToken !== '') {
        // Final removal invalidates the latest Cloud relay credential and intentionally discards its replacement.
        homeserver_vp3_relay_request('POST', '/v1/session/rotate', [], $relayToken);
    }
    $pdo = db();
    if (!$pdo) throw new RuntimeException('Database connection is unavailable.');
    $pdo->prepare('DELETE FROM homeserver_connections WHERE user_id=?')->execute([$userId]);
}
