<?php
declare(strict_types=1);

/**
 * VP3 Profile Conversion Outcomes v1.79
 *
 * Projects authoritative Booking and Commerce success back into the existing
 * Profile event ledger. No second analytics store, browser fingerprint, or
 * public/private boundary is introduced. Outcome events are deduped by their
 * canonical booking/order identity rather than by a time bucket.
 */
const VP3_PROFILE_CONVERSION_OUTCOMES_V179 = 'profile-conversion-outcomes-v179-20260914';

function profile_conversion_order_metadata_v179(array $order): array
{
    $decoded = json_decode((string)($order['metadata_json'] ?? ''), true);
    return is_array($decoded) ? $decoded : [];
}

function profile_conversion_viewer_is_owner_v179(int $ownerUserId): bool
{
    if ($ownerUserId < 1) return false;
    try {
        $viewer = current_user();
        return (int)($viewer['id'] ?? 0) === $ownerUserId;
    } catch (Throwable $e) {
        return false;
    }
}

function profile_conversion_session_id_v179(PDO $pdo, int $ownerUserId): int
{
    if ($ownerUserId < 1 || !function_exists('profile_runtime_session') || profile_conversion_viewer_is_owner_v179($ownerUserId)) return 0;
    try {
        $viewer = current_user();
        $session = profile_runtime_session($pdo, $ownerUserId, $viewer, false);
        return max(0, (int)($session['id'] ?? 0));
    } catch (Throwable $e) {
        return 0;
    }
}

function profile_conversion_attach_order_v179(PDO $pdo, int $orderId, int $ownerUserId, array $metadata): void
{
    if ($orderId < 1 || $ownerUserId < 1 || !$metadata || profile_conversion_viewer_is_owner_v179($ownerUserId)) return;
    try {
        $stmt = $pdo->prepare('SELECT owner_user_id,metadata_json FROM agent_commerce_orders_v800 WHERE id=? LIMIT 1');
        $stmt->execute([$orderId]);
        $order = $stmt->fetch();
        if (!$order || (int)$order['owner_user_id'] !== $ownerUserId) return;
        $current = json_decode((string)($order['metadata_json'] ?? ''), true);
        if (!is_array($current)) $current = [];
        $allowed = [
            'profile_conversion_source', 'profile_session_id', 'profile_username',
            'profile_target_id', 'profile_target_slug', 'profile_target_title', 'profile_target_url',
        ];
        foreach ($allowed as $key) {
            if (!array_key_exists($key, $metadata)) continue;
            $value = $metadata[$key];
            if ($key === 'profile_session_id' || $key === 'profile_target_id') $value = max(0, (int)$value);
            else $value = trim((string)$value);
            $current[$key] = $value;
        }
        $pdo->prepare('UPDATE agent_commerce_orders_v800 SET metadata_json=?,updated_at=NOW() WHERE id=? AND owner_user_id=?')
            ->execute([json_encode($current, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), $orderId, $ownerUserId]);
    } catch (Throwable $e) {
        // Attribution enrichment must never block checkout.
    }
}

function profile_conversion_outcome_session_v179(PDO $pdo, int $ownerUserId, int $profileSessionId): ?array
{
    if ($ownerUserId < 1 || $profileSessionId < 1) return null;
    $stmt = $pdo->prepare('SELECT * FROM profile_visit_sessions WHERE id=? AND owner_user_id=? LIMIT 1');
    $stmt->execute([$profileSessionId, $ownerUserId]);
    return $stmt->fetch() ?: null;
}

function profile_conversion_outcome_record_v179(
    PDO $pdo,
    int $ownerUserId,
    string $eventType,
    int $sourceId,
    int $profileSessionId,
    array $target = [],
    array $outcome = []
): ?array {
    if (!in_array($eventType, ['booking_converted', 'product_converted'], true)) return null;
    if ($ownerUserId < 1 || $sourceId < 1 || !function_exists('profile_event_create')) return null;

    try {
        $session = profile_conversion_outcome_session_v179($pdo, $ownerUserId, $profileSessionId);
        if ($session && (int)($session['visitor_user_id'] ?? 0) === $ownerUserId) return null;

        $targetId = max(0, (int)($target['id'] ?? 0));
        $targetSlug = mb_strimwidth(trim((string)($target['slug'] ?? '')), 0, 120, '');
        $targetTitle = mb_strimwidth(trim((string)($target['title'] ?? '')), 0, 190, '…');
        $targetUrl = trim((string)($target['url'] ?? ''));
        if ($targetUrl !== '' && (!filter_var($targetUrl, FILTER_VALIDATE_URL)
            || !in_array(strtolower((string)parse_url($targetUrl, PHP_URL_SCHEME)), ['http', 'https'], true))) {
            $targetUrl = '';
        }
        $targetUrl = mb_strimwidth($targetUrl, 0, 500, '');
        $currency = strtolower(trim((string)($outcome['currency'] ?? '')));
        if ($currency !== '' && !preg_match('/^[a-z]{3}$/', $currency)) $currency = '';
        $status = preg_replace('/[^a-z0-9_]+/', '_', strtolower(trim((string)($outcome['status'] ?? 'converted')))) ?? 'converted';
        $status = mb_strimwidth(trim($status, '_'), 0, 40, '');
        $source = mb_strimwidth(trim((string)($outcome['source'] ?? 'canonical_conversion')), 0, 80, '');

        $metadata = [
            'conversion_kind' => $eventType === 'booking_converted' ? 'booking' : 'product',
            'conversion_stage' => 'outcome',
            'target_id' => $targetId,
            'target_slug' => $targetSlug,
            'target_title' => $targetTitle,
            'target_url' => $targetUrl,
            'source' => $source,
            'source_id' => $sourceId,
            'outcome_status' => $status ?: 'converted',
            'value_cents' => max(0, (int)($outcome['value_cents'] ?? 0)),
            'currency' => $currency,
        ];
        $dedupeKey = hash('sha256', 'profile-conversion-outcome-v179|' . $ownerUserId . '|' . $eventType . '|' . $sourceId);
        $profile = function_exists('profile_for_user') ? profile_for_user($pdo, $ownerUserId, false) : null;
        $agent = $profile && function_exists('profile_active_agent') ? profile_active_agent($pdo, $profile) : null;
        $priority = $eventType === 'booking_converted' ? 70 : 65;

        if ($session) {
            return profile_event_create(
                $pdo,
                $ownerUserId,
                $session,
                $eventType,
                $priority,
                $agent ? (int)$agent['id'] : null,
                $metadata,
                $dedupeKey
            );
        }

        // Webhook verification can outlive the browser session cookie. The event
        // ledger already permits a NULL profile_session_id, so preserve the true
        // conversion without manufacturing a new visitor identity.
        try {
            $stmt = $pdo->prepare('INSERT INTO profile_events (owner_user_id,profile_session_id,visitor_user_id,profile_agent_id,event_type,priority,dedupe_key,metadata_json) VALUES (?,NULL,NULL,?,?,?,?,?)');
            $stmt->execute([
                $ownerUserId,
                $agent ? (int)$agent['id'] : null,
                $eventType,
                $priority,
                $dedupeKey,
                json_encode($metadata, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            ]);
            $id = (int)$pdo->lastInsertId();
            $get = $pdo->prepare('SELECT * FROM profile_events WHERE id=? LIMIT 1');
            $get->execute([$id]);
            return $get->fetch() ?: ['id' => $id, 'event_type' => $eventType];
        } catch (PDOException $e) {
            if ((string)$e->getCode() === '23000') return null;
            throw $e;
        }
    } catch (Throwable $e) {
        // Conversion analytics must never block booking/payment completion.
        return null;
    }
}

function profile_conversion_booking_confirmed_v179(PDO $pdo, array $profile, array $booking, array $event): ?array
{
    $ownerUserId = max(0, (int)($profile['user_id'] ?? 0));
    $bookingId = max(0, (int)($booking['id'] ?? 0));
    if ($ownerUserId < 1 || $bookingId < 1 || profile_conversion_viewer_is_owner_v179($ownerUserId)) return null;
    $username = profile_username_normalize((string)($profile['username'] ?? ''));
    $slug = trim((string)($event['slug'] ?? ''));
    $url = $username !== '' && function_exists('agent_scheduling_public_booking_url_v450')
        ? agent_scheduling_public_booking_url_v450($username, $slug !== '' ? $slug : null)
        : '';
    return profile_conversion_outcome_record_v179(
        $pdo,
        $ownerUserId,
        'booking_converted',
        $bookingId,
        profile_conversion_session_id_v179($pdo, $ownerUserId),
        [
            'id' => (int)($event['id'] ?? $booking['event_type_id'] ?? 0),
            'slug' => $slug,
            'title' => (string)($event['title'] ?? $booking['event_title'] ?? 'Appointment'),
            'url' => $url,
        ],
        ['source' => 'public_booking_confirmed', 'status' => 'confirmed', 'value_cents' => 0, 'currency' => '']
    );
}

function profile_conversion_commerce_order_v179(PDO $pdo, array $order): ?array
{
    $orderId = max(0, (int)($order['id'] ?? 0));
    $ownerUserId = max(0, (int)($order['owner_user_id'] ?? 0));
    $paymentStatus = (string)($order['payment_status'] ?? '');
    if ($orderId < 1 || $ownerUserId < 1 || !in_array($paymentStatus, ['paid', 'partially_paid'], true)) return null;

    $metadata = profile_conversion_order_metadata_v179($order);
    $source = trim((string)($metadata['profile_conversion_source'] ?? ''));
    $sessionId = max(0, (int)($metadata['profile_session_id'] ?? 0));
    $profile = function_exists('profile_for_user') ? profile_for_user($pdo, $ownerUserId, false) : null;
    $username = profile_username_normalize((string)($metadata['profile_username'] ?? $profile['username'] ?? ''));

    if ($source === 'public_profile_booking' && (string)($order['fulfillment_type'] ?? '') === 'appointment') {
        $bookingId = max(0, (int)($order['fulfillment_ref_id'] ?? 0));
        if ($bookingId < 1) return null;
        $booking = function_exists('agent_appointment_lifecycle_booking_v700')
            ? agent_appointment_lifecycle_booking_v700($pdo, $bookingId)
            : null;
        $eventTypeId = max(0, (int)($metadata['profile_target_id'] ?? $booking['event_type_id'] ?? 0));
        $event = $eventTypeId > 0 && function_exists('agent_scheduling_event_type_v430')
            ? agent_scheduling_event_type_v430($pdo, $eventTypeId)
            : null;
        $slug = trim((string)($metadata['profile_target_slug'] ?? $event['slug'] ?? ''));
        $title = trim((string)($metadata['profile_target_title'] ?? $event['title'] ?? $booking['event_title'] ?? 'Appointment'));
        $targetUrl = trim((string)($metadata['profile_target_url'] ?? ''));
        if ($targetUrl === '' && $username !== '' && function_exists('agent_scheduling_public_booking_url_v450')) {
            $targetUrl = agent_scheduling_public_booking_url_v450($username, $slug !== '' ? $slug : null);
        }
        return profile_conversion_outcome_record_v179(
            $pdo,
            $ownerUserId,
            'booking_converted',
            $bookingId,
            $sessionId,
            ['id' => $eventTypeId, 'slug' => $slug, 'title' => $title, 'url' => $targetUrl],
            [
                'source' => 'appointment_payment_verified',
                'status' => $paymentStatus === 'paid' ? 'confirmed_paid' : 'confirmed_deposit',
                'value_cents' => (int)($order['amount_paid_cents'] ?? 0),
                'currency' => (string)($order['currency'] ?? ''),
            ]
        );
    }

    if ($source === 'profile_commerce_v900' && $paymentStatus === 'paid') {
        $items = function_exists('agent_commerce_order_items_v800') ? agent_commerce_order_items_v800($pdo, $orderId) : [];
        $item = $items[0] ?? [];
        $productId = max(0, (int)($metadata['profile_target_id'] ?? $item['product_id'] ?? 0));
        $slug = trim((string)($metadata['profile_target_slug'] ?? ''));
        $title = trim((string)($metadata['profile_target_title'] ?? $item['title_snapshot'] ?? 'Product'));
        $targetUrl = trim((string)($metadata['profile_target_url'] ?? ''));
        if ($targetUrl === '' && $username !== '' && $slug !== '') {
            $targetUrl = url('/' . rawurlencode($username) . '/product/' . rawurlencode($slug));
        }
        return profile_conversion_outcome_record_v179(
            $pdo,
            $ownerUserId,
            'product_converted',
            $orderId,
            $sessionId,
            ['id' => $productId, 'slug' => $slug, 'title' => $title, 'url' => $targetUrl],
            [
                'source' => 'commerce_payment_verified',
                'status' => 'paid',
                'value_cents' => (int)($order['amount_paid_cents'] ?? $order['total_cents'] ?? 0),
                'currency' => (string)($order['currency'] ?? ''),
            ]
        );
    }

    return null;
}
