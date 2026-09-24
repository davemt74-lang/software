<?php
declare(strict_types=1);

require_once __DIR__ . '/homeserver-cloud-pairing-v1200.php';
require_once __DIR__ . '/homeserver-relay-lifecycle-v1210.php';

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

function homeserver_cloud_v1200_disconnect(int $userId): array
{
    $row = homeserver_vp3_connection($userId);
    if (!$row) return ['disconnected'=>true,'relay_revoked'=>false,'local_only'=>true];

    $pdo = db();
    if (!$pdo) throw new RuntimeException('Database connection is unavailable.');

    if(function_exists('homeserver_https_v1300_session')&&homeserver_https_v1300_session($userId)){
        homeserver_https_v1300_revoke($userId,false);
        $pdo->prepare("UPDATE homeserver_connections
          SET relay_token_enc=NULL,homeserver_token_enc=NULL,pending_request_id='',pending_claim_token_enc=NULL,pending_code='',
              status='revoked',last_error='',capabilities_json=NULL,last_checked_at=UTC_TIMESTAMP()
          WHERE user_id=?")->execute([$userId]);
        return ['disconnected'=>true,'relay_revoked'=>true,'local_only'=>false,'transport'=>'vp3_https'];
    }

    $replacement = '';
    $relayRevoked = false;
    try {
        homeserver_cloud_v1200_relay_security();
        $relayToken = homeserver_vp3_decrypt((string)($row['relay_token_enc'] ?? ''));
        if ($relayToken !== '') {
            $rotated = homeserver_vp3_relay_request('POST', '/v1/session/rotate', [], $relayToken);
            $replacement = trim((string)($rotated['relay_token'] ?? ''));
            $relayRevoked = strlen($replacement) >= 32 && strlen($replacement) <= 512;
        }
    } catch (Throwable $ignored) {
        $replacement = '';
        $relayRevoked = false;
    }

    if ($relayRevoked) {
        $pdo->prepare(
            "UPDATE homeserver_connections
             SET relay_token_enc=?,homeserver_token_enc=NULL,pending_request_id='',pending_claim_token_enc=NULL,pending_code='',
                 status='disconnected',last_error='',capabilities_json=NULL,last_checked_at=UTC_TIMESTAMP()
             WHERE user_id=?"
        )->execute([homeserver_vp3_encrypt($replacement),$userId]);
    } else {
        // Fail closed locally. VP3 discards every credential it could use even if the
        // relay is offline, misconfigured, or the stored credential can no longer decrypt.
        $pdo->prepare(
            "UPDATE homeserver_connections
             SET relay_token_enc=NULL,homeserver_token_enc=NULL,pending_request_id='',pending_claim_token_enc=NULL,pending_code='',
                 status='revoked',last_error='',capabilities_json=NULL,last_checked_at=UTC_TIMESTAMP()
             WHERE user_id=?"
        )->execute([$userId]);
    }

    return ['disconnected'=>true,'relay_revoked'=>$relayRevoked,'local_only'=>!$relayRevoked];
}

function homeserver_cloud_v1200_remove_pairing(int $userId): array
{
    $row = homeserver_vp3_connection($userId);
    if (!$row) return ['removed'=>true,'relay_released'=>false];

    $status = (string)($row['status'] ?? '');
    if (!in_array($status, ['disconnected','revoked'], true)
        || !empty($row['homeserver_token_enc'])
        || trim((string)($row['pending_request_id'] ?? '')) !== '') {
        throw new RuntimeException('Disconnect HomeServer before removing the Cloud pairing.');
    }

    $relayReleased = false;
    $relayToken = '';
    try { $relayToken = homeserver_vp3_decrypt((string)($row['relay_token_enc'] ?? '')); }
    catch (Throwable $ignored) { $relayToken = ''; }

    if ($relayToken !== '') {
        try {
            homeserver_cloud_v1200_relay_security();
            $released = homeserver_relay_v1210_release($relayToken);
            $relayReleased = trim((string)($released['device_id'] ?? '')) === trim((string)($row['device_id'] ?? ''));
        } catch (Throwable $ignored) {
            // Cloud removal is still allowed because the local row and relay credential
            // are being destroyed. A later HomeServer claim establishes fresh proof.
            $relayReleased = false;
        }
    }

    $pdo = db();
    if (!$pdo) throw new RuntimeException('Database connection is unavailable.');
    if(function_exists('homeserver_https_v1300_revoke'))homeserver_https_v1300_revoke($userId,true);
    $pdo->prepare('DELETE FROM homeserver_connections WHERE user_id=?')->execute([$userId]);
    return ['removed'=>true,'relay_released'=>$relayReleased];
}
