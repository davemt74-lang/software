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

    // A failed permission refresh must not destroy a previously approved app token.
    if ($hadApprovedToken && in_array($pairState, ['denied','expired'], true)) {
        $pdo = db();
        if (!$pdo) throw new RuntimeException('Database connection is unavailable.');
        $pdo->prepare(
            "UPDATE homeserver_connections
             SET status='paired',last_error='',last_checked_at=NULL
             WHERE user_id=? AND homeserver_token_enc IS NOT NULL"
        )->execute([$userId]);
        homeserver_vp3_status($userId, true);
        $result['retained_previous_pairing'] = true;
    }
    return $result;
}
