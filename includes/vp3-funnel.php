<?php
declare(strict_types=1);

/**
 * Public acquisition-funnel continuity helpers.
 *
 * This layer intentionally stores only non-sensitive marketing intent in the
 * existing PHP session. It does not change authentication, subscription, CRM,
 * or Agent authority.
 */

const VP3_FUNNEL_SESSION_KEY = 'vp3_public_funnel_intent';
const VP3_FUNNEL_MAX_RETURN_LENGTH = 2048;
const VP3_FUNNEL_ALLOWED_BILLING = ['weekly', 'monthly', 'annual'];
const VP3_FUNNEL_ALLOWED_EVENTS = [
    'pricing_view',
    'signup_view',
    'signup_submit',
    'signup_success',
    'signup_failure',
    'login_view',
    'login_submit',
    'login_success',
    'login_failure',
    'demo_view',
    'demo_submit',
    'demo_success',
    'demo_failure',
];

function vp3_funnel_token(mixed $value, int $maxLength = 64): ?string
{
    if (!is_scalar($value)) {
        return null;
    }

    $value = trim((string) $value);
    if ($value === '' || mb_strlen($value) > $maxLength) {
        return null;
    }

    return preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]*$/', $value) === 1 ? $value : null;
}

function vp3_funnel_plan(mixed $value): ?string
{
    return vp3_funnel_token($value, 80);
}

function vp3_funnel_billing(mixed $value): ?string
{
    if (!is_scalar($value)) {
        return null;
    }

    $value = strtolower(trim((string) $value));
    if ($value === 'yearly' || $value === 'year') {
        $value = 'annual';
    }

    return in_array($value, VP3_FUNNEL_ALLOWED_BILLING, true) ? $value : null;
}

function vp3_funnel_source(mixed $value): ?string
{
    return vp3_funnel_token($value, 80);
}

/**
 * Accept only a local absolute-path destination such as /pricing.php?plan=2.
 * Scheme-relative URLs, backslashes, control characters, and external URLs
 * are rejected to prevent open redirects.
 */
function vp3_funnel_safe_return(mixed $value): ?string
{
    if (!is_scalar($value)) {
        return null;
    }

    $value = trim((string) $value);
    if ($value === '' || strlen($value) > VP3_FUNNEL_MAX_RETURN_LENGTH) {
        return null;
    }
    if ($value[0] !== '/' || str_starts_with($value, '//') || str_contains($value, '\\')) {
        return null;
    }
    if (preg_match('/[\x00-\x1F\x7F]/', $value) === 1) {
        return null;
    }

    $parts = parse_url($value);
    if ($parts === false) {
        return null;
    }
    foreach (['scheme', 'host', 'user', 'pass', 'port'] as $forbidden) {
        if (array_key_exists($forbidden, $parts)) {
            return null;
        }
    }

    $path = (string) ($parts['path'] ?? '');
    return str_starts_with($path, '/') ? $value : null;
}

function vp3_funnel_normalize_intent(array $intent): array
{
    $normalized = [];

    $plan = vp3_funnel_plan($intent['plan'] ?? null);
    if ($plan !== null) {
        $normalized['plan'] = $plan;
    }

    $billing = vp3_funnel_billing($intent['billing'] ?? null);
    if ($billing !== null) {
        $normalized['billing'] = $billing;
    }

    $source = vp3_funnel_source($intent['source'] ?? null);
    if ($source !== null) {
        $normalized['source'] = $source;
    }

    $return = vp3_funnel_safe_return($intent['return_to'] ?? ($intent['return'] ?? null));
    if ($return !== null) {
        $normalized['return_to'] = $return;
    }

    return $normalized;
}

function vp3_funnel_intent(): array
{
    $stored = $_SESSION[VP3_FUNNEL_SESSION_KEY] ?? [];
    return is_array($stored) ? vp3_funnel_normalize_intent($stored) : [];
}

/**
 * Merge only valid incoming values. Invalid input never destroys a previously
 * validated value already in the session.
 */
function vp3_funnel_capture(array $input): array
{
    $intent = vp3_funnel_intent();
    $incoming = vp3_funnel_normalize_intent($input);
    foreach ($incoming as $key => $value) {
        $intent[$key] = $value;
    }

    if ($intent !== []) {
        $_SESSION[VP3_FUNNEL_SESSION_KEY] = $intent;
    }

    return $intent;
}

function vp3_funnel_clear(): void
{
    unset($_SESSION[VP3_FUNNEL_SESSION_KEY]);
}

function vp3_funnel_query(array $intent = []): string
{
    $intent = $intent === [] ? vp3_funnel_intent() : vp3_funnel_normalize_intent($intent);
    return http_build_query($intent, '', '&', PHP_QUERY_RFC3986);
}

function vp3_funnel_url(string $path, array $overrides = []): string
{
    $intent = vp3_funnel_intent();
    foreach ($overrides as $key => $value) {
        if ($value === null || $value === '') {
            unset($intent[$key]);
        } else {
            $intent[$key] = $value;
        }
    }
    $intent = vp3_funnel_normalize_intent($intent);

    $target = url($path);
    $query = vp3_funnel_query($intent);
    if ($query === '') {
        return $target;
    }

    return $target . (str_contains($target, '?') ? '&' : '?') . $query;
}

function vp3_funnel_destination(string $fallback): string
{
    $intent = vp3_funnel_intent();
    return vp3_funnel_safe_return($intent['return_to'] ?? null) ?? $fallback;
}

/**
 * Consume a one-time post-auth return target so an old acquisition redirect
 * cannot unexpectedly affect a later login. Plan/billing/source attribution is
 * preserved for downstream onboarding and conversion telemetry.
 */
function vp3_funnel_take_destination(string $fallback): string
{
    $intent = vp3_funnel_intent();
    $destination = vp3_funnel_safe_return($intent['return_to'] ?? null) ?? $fallback;
    unset($intent['return_to']);

    if ($intent === []) {
        unset($_SESSION[VP3_FUNNEL_SESSION_KEY]);
    } else {
        $_SESSION[VP3_FUNNEL_SESSION_KEY] = $intent;
    }

    return $destination;
}

function vp3_funnel_event_context(array $extra = []): array
{
    $intent = vp3_funnel_intent();
    $path = parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);
    $context = [
        'path' => is_string($path) ? mb_substr($path, 0, 160) : '',
        'plan' => $intent['plan'] ?? null,
        'billing' => $intent['billing'] ?? null,
        'source' => $intent['source'] ?? null,
    ];

    foreach (['plan', 'billing', 'source'] as $key) {
        if (array_key_exists($key, $extra)) {
            $context[$key] = $extra[$key];
        }
    }

    return array_filter($context, static fn (mixed $value): bool => $value !== null && $value !== '');
}

/**
 * First-party public funnel telemetry. No PII, credentials, IP addresses,
 * cookies, session IDs, free-form request bodies, or raw headers are logged.
 * The application/server log remains the collection authority until these
 * anonymous acquisition events have a dedicated product analytics owner.
 */
function vp3_funnel_event(string $event, array $context = []): void
{
    if (!in_array($event, VP3_FUNNEL_ALLOWED_EVENTS, true)) {
        return;
    }

    $payload = [
        'type' => 'vp3_public_funnel',
        'event' => $event,
        'occurred_at' => gmdate('c'),
        'context' => vp3_funnel_event_context($context),
    ];

    $encoded = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if (is_string($encoded)) {
        error_log('[vp3-funnel] ' . $encoded);
    }
}
