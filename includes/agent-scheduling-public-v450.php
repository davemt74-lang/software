<?php
declare(strict_types=1);

/**
 * VP3 Public Agent Scheduling v4.50
 *
 * Guest-facing booking/read/self-service helpers layered over the canonical
 * v4.30 scheduling store. Public routes never accept an owner id from the
 * browser; ownership is resolved from the public profile and schedule.
 */
const VP3_AGENT_SCHEDULING_PUBLIC_V450 = 'agent-scheduling-public-v450-20260911';

function agent_scheduling_public_schedule_v450(PDO $pdo, int $ownerUserId): ?array
{
    if ($ownerUserId < 1) return null;
    $stmt = $pdo->prepare(
        "SELECT s.*,ua.display_name AS agent_name
         FROM agent_scheduling_schedules s
         LEFT JOIN user_agents ua ON ua.id=s.agent_id AND ua.user_id=s.owner_user_id AND ua.is_active=1
         WHERE s.owner_user_id=? AND s.is_active=1 AND s.public_enabled=1
         ORDER BY s.is_default DESC,s.id ASC
         LIMIT 1"
    );
    $stmt->execute([$ownerUserId]);
    return $stmt->fetch() ?: null;
}

function agent_scheduling_public_events_v450(PDO $pdo, int $scheduleId): array
{
    if ($scheduleId < 1) return [];
    $stmt = $pdo->prepare(
        'SELECT * FROM agent_scheduling_event_types WHERE schedule_id=? AND is_active=1 ORDER BY sort_order,title,id'
    );
    $stmt->execute([$scheduleId]);
    return $stmt->fetchAll() ?: [];
}

function agent_scheduling_public_event_by_slug_v450(PDO $pdo, int $ownerUserId, string $slug): ?array
{
    $slug = agent_scheduling_slug_v430($slug);
    if ($slug === '') return null;
    $schedule = agent_scheduling_public_schedule_v450($pdo, $ownerUserId);
    if (!$schedule) return null;
    $stmt = $pdo->prepare(
        "SELECT e.*,s.owner_user_id,s.agent_id,s.timezone AS schedule_timezone,s.public_enabled,s.is_active AS schedule_active
         FROM agent_scheduling_event_types e
         JOIN agent_scheduling_schedules s ON s.id=e.schedule_id
         WHERE e.schedule_id=? AND e.slug=? AND e.is_active=1 AND s.is_active=1 AND s.public_enabled=1
         LIMIT 1"
    );
    $stmt->execute([(int)$schedule['id'], $slug]);
    return $stmt->fetch() ?: null;
}

function agent_scheduling_public_event_for_owner_v450(PDO $pdo, int $ownerUserId, int $eventTypeId): ?array
{
    if ($ownerUserId < 1 || $eventTypeId < 1) return null;
    $event = agent_scheduling_event_type_v430($pdo, $eventTypeId);
    if (!$event) return null;
    if ((int)$event['owner_user_id'] !== $ownerUserId || empty($event['is_active']) || empty($event['schedule_active']) || empty($event['public_enabled'])) return null;
    return $event;
}

function agent_scheduling_booking_by_public_token_v450(PDO $pdo, string $token): ?array
{
    $token = strtolower(trim($token));
    if (!preg_match('/^[a-f0-9]{64}$/', $token)) return null;
    $stmt = $pdo->prepare('SELECT * FROM agent_scheduling_bookings WHERE public_token=? LIMIT 1');
    $stmt->execute([$token]);
    return $stmt->fetch() ?: null;
}

function agent_scheduling_booking_by_cancel_token_v450(PDO $pdo, string $token): ?array
{
    $token = strtolower(trim($token));
    if (!preg_match('/^[a-f0-9]{64}$/', $token)) return null;
    $stmt = $pdo->prepare('SELECT * FROM agent_scheduling_bookings WHERE cancel_token=? LIMIT 1');
    $stmt->execute([$token]);
    return $stmt->fetch() ?: null;
}

function agent_scheduling_public_booking_url_v450(string $username, ?string $eventSlug = null): string
{
    $username = profile_username_normalize($username);
    if ($username === '') return '';
    $path = '/' . rawurlencode($username) . '/book';
    if ($eventSlug !== null && trim($eventSlug) !== '') $path .= '/' . rawurlencode(agent_scheduling_slug_v430($eventSlug));
    return url($path);
}

function agent_scheduling_public_manage_url_v450(string $username, string $cancelToken): string
{
    $username = profile_username_normalize($username);
    $cancelToken = strtolower(trim($cancelToken));
    if ($username === '' || !preg_match('/^[a-f0-9]{64}$/', $cancelToken)) return '';
    return url('/' . rawurlencode($username) . '/book/manage/' . rawurlencode($cancelToken));
}

function agent_scheduling_public_calendar_url_v450(string $publicToken): string
{
    $publicToken = strtolower(trim($publicToken));
    if (!preg_match('/^[a-f0-9]{64}$/', $publicToken)) return '';
    return url('/booking-calendar/' . rawurlencode($publicToken) . '.ics');
}

function agent_scheduling_public_rate_limit_v450(string $bucket = 'book', int $limit = 12, int $windowSeconds = 60): void
{
    $limit = max(1, min(100, $limit));
    $windowSeconds = max(10, min(3600, $windowSeconds));
    $key = 'vp3_schedule_rl_' . preg_replace('/[^a-z0-9_-]+/i', '_', $bucket);
    $now = time();
    $state = $_SESSION[$key] ?? ['started_at' => $now, 'count' => 0];
    if (!is_array($state) || $now - (int)($state['started_at'] ?? 0) >= $windowSeconds) {
        $state = ['started_at' => $now, 'count' => 0];
    }
    $state['count'] = (int)($state['count'] ?? 0) + 1;
    $_SESSION[$key] = $state;
    if ($state['count'] > $limit) throw new RuntimeException('Too many booking attempts. Please wait a minute and try again.');
}

function agent_scheduling_public_reschedule_v450(PDO $pdo, array $booking, string $cancelToken, string $startAtUtc, string $guestTimezone): array
{
    $bookingId = (int)($booking['id'] ?? 0);
    $cancelToken = strtolower(trim($cancelToken));
    if ($bookingId < 1 || $cancelToken === '' || !hash_equals((string)($booking['cancel_token'] ?? ''), $cancelToken)) {
        throw new RuntimeException('This booking management link is not valid.');
    }
    if (!in_array((string)($booking['status'] ?? ''), ['pending','confirmed'], true)) {
        throw new RuntimeException('Only an active booking can be rescheduled.');
    }

    $ownerUserId = (int)$booking['owner_user_id'];
    $eventTypeId = (int)($booking['event_type_id'] ?? 0);
    $event = agent_scheduling_public_event_for_owner_v450($pdo, $ownerUserId, $eventTypeId);
    if (!$event) throw new RuntimeException('This appointment type is no longer open for public booking.');

    // Hold the same per-schedule named lock used by v4.30 booking creation for
    // the entire cancel+create transaction. The inner create call reacquires
    // this lock on the same connection and releases only its own reference, so
    // no second request can see the old slot released before the new row commits.
    $lockName = 'vp3_schedule_' . (int)$event['schedule_id'];
    $lockStmt = $pdo->prepare('SELECT GET_LOCK(?,5)');
    $lockStmt->execute([$lockName]);
    if ((int)$lockStmt->fetchColumn() !== 1) throw new RuntimeException('That schedule is busy. Please try rescheduling again.');

    $started = !$pdo->inTransaction();
    try {
        if ($started) $pdo->beginTransaction();
        $cancel = $pdo->prepare(
            "UPDATE agent_scheduling_bookings
             SET status='cancelled',cancelled_at=NOW(),updated_at=NOW()
             WHERE id=? AND cancel_token=? AND status IN ('pending','confirmed')"
        );
        $cancel->execute([$bookingId, $cancelToken]);
        if ($cancel->rowCount() !== 1) throw new RuntimeException('This booking changed before it could be rescheduled.');

        $newBooking = agent_scheduling_create_booking_v430($pdo, [
            'event_type_id' => $eventTypeId,
            'start_at_utc' => $startAtUtc,
            'guest_timezone' => $guestTimezone,
            'guest_name' => (string)$booking['guest_name'],
            'guest_email' => (string)$booking['guest_email'],
            'guest_phone' => (string)$booking['guest_phone'],
            'guest_notes' => (string)($booking['guest_notes'] ?? ''),
            'source' => 'public_reschedule',
        ]);
        $pdo->prepare('UPDATE agent_scheduling_bookings SET rescheduled_from_id=? WHERE id=?')->execute([$bookingId, (int)$newBooking['id']]);

        if ($started) $pdo->commit();
        return $newBooking;
    } catch (Throwable $e) {
        if ($started && $pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    } finally {
        try {
            $release = $pdo->prepare('SELECT RELEASE_LOCK(?)');
            $release->execute([$lockName]);
        } catch (Throwable $ignored) {
        }
    }
}

function agent_scheduling_public_display_time_v450(string $utc, string $timezone, string $format = 'D, M j · g:i A T'): string
{
    try {
        return (new DateTimeImmutable($utc, new DateTimeZone('UTC')))
            ->setTimezone(new DateTimeZone(agent_scheduling_timezone_v430($timezone)))
            ->format($format);
    } catch (Throwable $e) {
        return '';
    }
}
