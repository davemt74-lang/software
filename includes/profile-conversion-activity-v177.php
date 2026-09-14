<?php
declare(strict_types=1);

/**
 * VP3 Profile Conversion Activity v1.77
 *
 * Records first-party human intent when a visitor actually reaches a canonical
 * public Booking or Product destination. This reuses the existing owner-scoped
 * Profile visitor/session/event ledger; it does not create a second analytics
 * store, fingerprint visitors, or inspect private scheduling/commerce data.
 */
const VP3_PROFILE_CONVERSION_ACTIVITY_V177 = 'profile-conversion-activity-v177-20260913';
const VP3_PROFILE_CONVERSION_DEDUPE_SECONDS_V177 = 1800;

function profile_conversion_activity_v177_record(PDO $pdo, array $profile, string $eventType, array $target = []): ?array
{
    if (!in_array($eventType, ['booking_intent', 'product_intent'], true)) return null;
    $ownerUserId = max(0, (int)($profile['user_id'] ?? 0));
    if ($ownerUserId < 1 || empty($profile['is_active']) || empty($profile['is_public'])) return null;
    if (!function_exists('profile_runtime_session') || !function_exists('profile_event_create')) return null;

    try {
        $viewer = current_user();
        $session = profile_runtime_session($pdo, $ownerUserId, $viewer, false);
        $targetId = max(0, (int)($target['id'] ?? 0));
        $targetSlug = mb_strimwidth(trim((string)($target['slug'] ?? '')), 0, 120, '');
        $targetTitle = mb_strimwidth(trim((string)($target['title'] ?? '')), 0, 190, '…');
        $targetUrl = trim((string)($target['url'] ?? ''));
        if ($targetUrl !== '' && (!filter_var($targetUrl, FILTER_VALIDATE_URL) || !in_array(strtolower((string)parse_url($targetUrl, PHP_URL_SCHEME)), ['http','https'], true))) {
            $targetUrl = '';
        }
        $targetUrl = mb_strimwidth($targetUrl, 0, 500, '');

        $metadata = function_exists('profile_visitor_request_context_v243')
            ? profile_visitor_request_context_v243($session)
            : [];
        $metadata = array_merge($metadata, [
            'conversion_kind' => $eventType === 'booking_intent' ? 'booking' : 'product',
            'target_id' => $targetId,
            'target_slug' => $targetSlug,
            'target_title' => $targetTitle,
            'target_url' => $targetUrl,
            'source' => 'public_destination',
        ]);

        $bucket = (int)floor(time() / VP3_PROFILE_CONVERSION_DEDUPE_SECONDS_V177);
        $dedupeKey = hash('sha256', (string)$session['session_key'] . '|' . $eventType . '|' . $targetId . '|' . $targetSlug . '|' . $bucket);
        $agent = function_exists('profile_active_agent') ? profile_active_agent($pdo, $profile) : null;
        $priority = $eventType === 'booking_intent' ? 35 : 30;

        // Activity only: these events appear in the existing owner activity/
        // Radar timeline but deliberately do not create attention notifications.
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
    } catch (Throwable $e) {
        // Analytics must never block public Booking or Commerce destinations.
        return null;
    }
}
