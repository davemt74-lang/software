<?php
declare(strict_types=1);

/**
 * VP3 v20.11 Browser Share correctness/privacy hardening.
 *
 * v20.10 owns the additive storage model and canonical Human Messaging bridge.
 * This layer binds idempotency keys to the exact normalized request, bounds the
 * client capture timestamp, and reconciles soft-deleted chat messages by
 * scrubbing captured browser content. No Browser Share body/note/full URL is
 * copied into operational audit logs.
 */
const VP3_BROWSER_SHARE_HARDENING_V2011 = 'browser-share-v2011-20260917';
const VP3_BROWSER_SHARE_CAPTURE_MAX_AGE_V2011 = 86400;
const VP3_BROWSER_SHARE_CAPTURE_FUTURE_SKEW_V2011 = 600;
const VP3_BROWSER_SHARE_IDEMPOTENCY_TTL_DAYS_V2011 = 7;

function vp3_browser_share_capture_v2011(array $input): array
{
    $capture = vp3_browser_share_validate_capture_v2010($input);
    $captured = strtotime((string)$capture['captured_at'].' UTC');
    $now = time();
    if ($captured === false
        || $captured < $now - VP3_BROWSER_SHARE_CAPTURE_MAX_AGE_V2011
        || $captured > $now + VP3_BROWSER_SHARE_CAPTURE_FUTURE_SKEW_V2011) {
        throw new VP3BrowserShareExceptionV2010(
            'invalid_capture_time',
            422,
            'Capture time must be within the supported Browser Companion window.'
        );
    }
    return $capture;
}

function vp3_browser_share_request_fingerprint_v2011(array $capture, array $destination): string
{
    $kind = trim((string)($destination['kind'] ?? ''));
    $id = (int)($destination['id'] ?? 0);
    if (!in_array($kind, ['conversation','team_general'], true) || $id < 1) {
        throw new VP3BrowserShareExceptionV2010('invalid_request', 422, 'Choose a valid share destination.');
    }

    $payload = [
        'share_type' => (string)$capture['share_type'],
        'source_url' => (string)$capture['source_url'],
        'canonical_url' => (string)$capture['canonical_url'],
        'source_title' => (string)$capture['source_title'],
        'source_domain' => (string)$capture['source_domain'],
        'selected_text' => (string)$capture['selected_text'],
        'user_note' => (string)$capture['user_note'],
        'captured_at' => (string)$capture['captured_at'],
        'destination_kind' => $kind,
        'destination_id' => $id,
    ];
    $encoded = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if (!is_string($encoded)) {
        throw new VP3BrowserShareExceptionV2010('invalid_request', 422, 'Browser Share request could not be encoded.');
    }

    // browser_share_idempotency_v2010.operation is VARCHAR(40). A truncated
    // SHA-256 still provides a 160-bit request-binding fingerprint while keeping
    // the additive v20.10 schema unchanged before its first production merge.
    return substr(hash('sha256', $encoded), 0, 40);
}

function vp3_browser_share_audit_v2011(string $event, array $metadata = []): void
{
    $allowed = [
        'user_id','device_id','browser_share_id','human_message_id',
        'conversation_id','source_domain','idempotent_replay','scrubbed_count',
    ];
    $safe = ['event' => $event];
    foreach ($allowed as $key) {
        if (array_key_exists($key, $metadata)) $safe[$key] = $metadata[$key];
    }
    // Deliberately excludes selected_text, user_note, source_url and canonical_url.
    error_log('VP3 Browser Share audit '.json_encode($safe, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
}

function vp3_browser_share_scrub_message_v2011(PDO $pdo, int $messageId): bool
{
    if ($messageId < 1 || !vp3_browser_share_schema_ready_v2010($pdo)) return false;
    $stmt = $pdo->prepare("SELECT s.id,s.public_id,s.source_domain,m.deleted_at
        FROM human_message_browser_shares_v2010 l
        INNER JOIN browser_shares_v2010 s ON s.id=l.browser_share_id
        INNER JOIN human_messages m ON m.id=l.human_message_id
        WHERE l.human_message_id=? AND m.deleted_at IS NOT NULL AND s.deleted_at IS NULL
        LIMIT 1");
    $stmt->execute([$messageId]);
    $row = $stmt->fetch();
    if (!$row) return false;

    $update = $pdo->prepare("UPDATE browser_shares_v2010
        SET source_url='',canonical_url='',source_title='',source_domain='',selected_text='',user_note='',
            deleted_at=COALESCE(deleted_at,?)
        WHERE id=? AND deleted_at IS NULL");
    $update->execute([(string)$row['deleted_at'], (int)$row['id']]);
    if ($update->rowCount() < 1) return false;

    vp3_browser_share_audit_v2011('browser_share.content_scrubbed', [
        'browser_share_id' => (string)$row['public_id'],
        'human_message_id' => $messageId,
        'source_domain' => (string)$row['source_domain'],
    ]);
    return true;
}

function vp3_browser_share_reconcile_deleted_v2011(PDO $pdo, int $limit = 100): int
{
    if (!vp3_browser_share_schema_ready_v2010($pdo)) return 0;
    $limit = max(1, min(500, $limit));
    $stmt = $pdo->query("SELECT l.human_message_id
        FROM human_message_browser_shares_v2010 l
        INNER JOIN browser_shares_v2010 s ON s.id=l.browser_share_id
        INNER JOIN human_messages m ON m.id=l.human_message_id
        WHERE m.deleted_at IS NOT NULL AND s.deleted_at IS NULL
        ORDER BY l.human_message_id ASC LIMIT {$limit}");
    $count = 0;
    foreach ($stmt->fetchAll() ?: [] as $row) {
        if (vp3_browser_share_scrub_message_v2011($pdo, (int)$row['human_message_id'])) $count++;
    }
    if ($count > 0) vp3_browser_share_audit_v2011('browser_share.reconciled', ['scrubbed_count' => $count]);
    return $count;
}

function vp3_browser_share_idempotent_result_v2011(PDO $pdo, int $deviceDbId, string $key, string $requestFingerprint): ?array
{
    $stmt = $pdo->prepare("SELECT i.operation,i.browser_share_id,i.human_message_id,
        s.public_id,s.share_type,s.snapshot_hash,m.conversation_id
        FROM browser_share_idempotency_v2010 i
        LEFT JOIN browser_shares_v2010 s ON s.id=i.browser_share_id
        LEFT JOIN human_messages m ON m.id=i.human_message_id
        WHERE i.device_id=? AND i.idempotency_key=? LIMIT 1 FOR UPDATE");
    $stmt->execute([$deviceDbId, $key]);
    $row = $stmt->fetch();
    if (!$row) return null;

    if (!hash_equals((string)$row['operation'], $requestFingerprint)) {
        throw new VP3BrowserShareExceptionV2010(
            'idempotency_conflict',
            409,
            'This idempotency key was already used for a different Browser Share request.'
        );
    }
    if ((int)($row['browser_share_id'] ?? 0) < 1 || (int)($row['human_message_id'] ?? 0) < 1) return null;

    return [
        'browser_share' => [
            'id' => (string)$row['public_id'],
            'type' => (string)$row['share_type'],
            'snapshot_hash' => (string)$row['snapshot_hash'],
        ],
        'chat_message' => [
            'id' => (int)$row['human_message_id'],
            'conversation_id' => (int)$row['conversation_id'],
        ],
        'idempotent_replay' => true,
    ];
}

function vp3_browser_share_create_v2011(PDO $pdo, array $session, array $input, string $idempotencyKey): array
{
    vp3_browser_share_require_ready_v2010($pdo);
    if (!vp3_extension_session_has_capability_v2001($session, 'team.share.create')) {
        throw new VP3BrowserShareExceptionV2010('capability_denied', 403, 'This browser connection does not have permission for that action.');
    }
    if (!vp3_extension_valid_uuid_v2000($idempotencyKey)) {
        throw new VP3BrowserShareExceptionV2010('invalid_request', 422, 'A valid X-VP3-Idempotency-Key is required.');
    }

    $userId = (int)($session['user_id'] ?? 0);
    if ($userId < 1) throw new VP3BrowserShareExceptionV2010('authentication_required', 401, 'Browser Companion authentication is required.');
    $deviceDbId = vp3_browser_share_device_db_id_v2010($pdo, $session);
    $capture = vp3_browser_share_capture_v2011($input);
    $destination = is_array($input['destination'] ?? null) ? $input['destination'] : [];
    $fingerprint = vp3_browser_share_request_fingerprint_v2011($capture, $destination);

    // Privacy reconciliation is deliberately outside the create transaction so
    // it cannot alter canonical Human Messaging lock order.
    vp3_browser_share_reconcile_deleted_v2011($pdo, 100);

    $owns = !$pdo->inTransaction();
    if ($owns) $pdo->beginTransaction();
    try {
        $pdo->prepare("DELETE FROM browser_share_idempotency_v2010
            WHERE device_id=? AND idempotency_key=? AND expires_at<=NOW()")
            ->execute([$deviceDbId, $idempotencyKey]);
        $pdo->prepare("INSERT IGNORE INTO browser_share_idempotency_v2010
            (device_id,idempotency_key,operation,expires_at)
            VALUES (?,?,?,DATE_ADD(NOW(),INTERVAL 7 DAY))")
            ->execute([$deviceDbId, $idempotencyKey, $fingerprint]);

        $existing = vp3_browser_share_idempotent_result_v2011($pdo, $deviceDbId, $idempotencyKey, $fingerprint);
        if ($existing) {
            if ($owns) $pdo->commit();
            vp3_browser_share_audit_v2011('browser_share.replayed', [
                'user_id' => $userId,
                'device_id' => (string)($session['device_id'] ?? ''),
                'browser_share_id' => (string)$existing['browser_share']['id'],
                'human_message_id' => (int)$existing['chat_message']['id'],
                'conversation_id' => (int)$existing['chat_message']['conversation_id'],
                'idempotent_replay' => true,
            ]);
            return $existing;
        }

        vp3_browser_share_enforce_rate_v2010($pdo, $deviceDbId);
        $conversation = vp3_browser_share_destination_conversation_v2010($pdo, $userId, $destination);
        try {
            $message = vp3_human_send_message_v370(
                $pdo,
                (int)$conversation['id'],
                $userId,
                vp3_browser_share_fallback_body_v2010($capture)
            );
        } catch (PDOException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw new VP3BrowserShareExceptionV2010('destination_denied', 403, 'This Browser Share cannot be posted to that conversation.');
        }

        $publicId = vp3_extension_uuid_v2000();
        $stmt = $pdo->prepare("INSERT INTO browser_shares_v2010
            (public_id,sender_user_id,device_id,share_type,source_url,canonical_url,source_title,source_domain,selected_text,user_note,captured_at,snapshot_hash,dedupe_fingerprint)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)");
        $stmt->execute([
            $publicId,$userId,$deviceDbId,$capture['share_type'],$capture['source_url'],$capture['canonical_url'],
            $capture['source_title'],$capture['source_domain'],$capture['selected_text'],$capture['user_note'],
            $capture['captured_at'],$capture['snapshot_hash'],$capture['dedupe_fingerprint'],
        ]);
        $shareDbId = (int)$pdo->lastInsertId();
        $messageId = (int)$message['id'];
        $pdo->prepare('INSERT INTO human_message_browser_shares_v2010 (human_message_id,browser_share_id) VALUES (?,?)')
            ->execute([$messageId, $shareDbId]);
        $pdo->prepare("UPDATE browser_share_idempotency_v2010
            SET browser_share_id=?,human_message_id=?
            WHERE device_id=? AND idempotency_key=? AND operation=?")
            ->execute([$shareDbId,$messageId,$deviceDbId,$idempotencyKey,$fingerprint]);

        if ($owns) $pdo->commit();
        $result = [
            'browser_share' => ['id'=>$publicId,'type'=>$capture['share_type'],'snapshot_hash'=>$capture['snapshot_hash']],
            'chat_message' => ['id'=>$messageId,'conversation_id'=>(int)$conversation['id']],
            'idempotent_replay' => false,
        ];
        vp3_browser_share_audit_v2011('browser_share.created', [
            'user_id' => $userId,
            'device_id' => (string)($session['device_id'] ?? ''),
            'browser_share_id' => $publicId,
            'human_message_id' => $messageId,
            'conversation_id' => (int)$conversation['id'],
            'source_domain' => (string)$capture['source_domain'],
            'idempotent_replay' => false,
        ]);
        return $result;
    } catch (Throwable $e) {
        if ($owns && $pdo->inTransaction()) $pdo->rollBack();
        if ($e instanceof VP3BrowserShareExceptionV2010) throw $e;
        throw new VP3BrowserShareExceptionV2010('service_unavailable', 503, 'Browser Share could not be created.');
    }
}

function vp3_browser_share_for_message_v2011(PDO $pdo, int $messageId, int $userId): ?array
{
    vp3_browser_share_scrub_message_v2011($pdo, $messageId);
    return vp3_browser_share_for_message_v2010($pdo, $messageId, $userId);
}

function vp3_browser_share_by_public_id_v2011(PDO $pdo, string $publicId, int $userId): ?array
{
    $stmt = $pdo->prepare("SELECT l.human_message_id
        FROM browser_shares_v2010 s
        INNER JOIN human_message_browser_shares_v2010 l ON l.browser_share_id=s.id
        WHERE s.public_id=? LIMIT 1");
    $stmt->execute([$publicId]);
    $messageId = (int)($stmt->fetchColumn() ?: 0);
    return $messageId > 0 ? vp3_browser_share_for_message_v2011($pdo, $messageId, $userId) : null;
}
