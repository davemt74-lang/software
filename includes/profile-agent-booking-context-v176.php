<?php
declare(strict_types=1);

/**
 * VP3 Profile Agent Booking Context v1.76
 *
 * Adds only explicitly public scheduling data to Profile Agent context.
 * Private calendar records, owner-only availability data and booking internals
 * are never queried here.
 */
const VP3_PROFILE_AGENT_BOOKING_CONTEXT_V176 = 'profile-agent-booking-context-v176-20260913';

function profile_agent_booking_intent_v176(string $query): bool
{
    return (bool)preg_match('/\b(book|booking|schedule|scheduling|appointment|meeting|availability|available|time slot|timeslot|consult|consultation|call|reserve|reservation)\b/i', $query);
}

function profile_agent_booking_context_v176(PDO $pdo, array $profile, string $query, int $limit = 6): array
{
    if (!profile_agent_booking_intent_v176($query)) return [];
    if (!table_exists('agent_scheduling_schedules') || !table_exists('agent_scheduling_event_types')) return [];

    $ownerUserId = max(0, (int)($profile['user_id'] ?? 0));
    $username = profile_username_normalize((string)($profile['username'] ?? ''));
    if ($ownerUserId < 1 || $username === '' || empty($profile['is_public'])) return [];

    try {
        $schedule = agent_scheduling_public_schedule_v450($pdo, $ownerUserId);
        if (!$schedule) return [];
        $events = agent_scheduling_public_events_v450($pdo, (int)$schedule['id']);
    } catch (Throwable $e) {
        return [];
    }

    $limit = max(1, min(12, $limit));
    $terms = function_exists('chat_policy_terms_v236') ? chat_policy_terms_v236($query) : [];
    $out = [];

    foreach ($events as $event) {
        $title = trim((string)($event['title'] ?? 'Appointment')) ?: 'Appointment';
        $description = trim((string)($event['description'] ?? ''));
        $duration = max(0, (int)($event['duration_minutes'] ?? 0));
        $slug = trim((string)($event['slug'] ?? ''));
        if ($slug === '') continue;

        if ($terms) {
            $haystack = mb_strtolower($title . ' ' . $description);
            $matched = false;
            foreach ($terms as $term) {
                $term = mb_strtolower(trim((string)$term));
                if ($term !== '' && str_contains($haystack, $term)) {
                    $matched = true;
                    break;
                }
            }
            // Booking intent itself is sufficient for broad questions such as
            // "Can I book a time?" even when no event title matches a term.
            if (!$matched && !preg_match('/\b(book|booking|schedule|appointment|meeting|availability|available|time|call)\b/i', $query)) continue;
        }

        $bookingUrl = agent_scheduling_public_booking_url_v450($username, $slug);
        $text = 'Public booking option: ' . $title . '. ';
        if ($duration > 0) $text .= 'Duration: ' . $duration . ' minutes. ';
        if ($description !== '') $text .= $description . ' ';
        $text .= 'Booking URL: ' . $bookingUrl . '. Only this public appointment type may be offered; do not infer private calendar availability.';

        $out[] = [
            'source' => 'profile:booking:' . (int)($event['id'] ?? 0),
            'title' => $title,
            'text' => mb_strimwidth($text, 0, 4000, '…'),
        ];
        if (count($out) >= $limit) break;
    }

    return $out;
}
