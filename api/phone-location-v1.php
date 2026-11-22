<?php
declare(strict_types=1);

require dirname(__DIR__).'/includes/bootstrap.php';
require_once dirname(__DIR__).'/includes/phone-tracking-http-v1.php';
require_once dirname(__DIR__).'/includes/phone-tracking-v1.php';

vp3_phone_http_headers_v1();
// Holder credentials and owner web sessions deliberately have different authority.
if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
try {
    vp3_phone_http_transport_v1();
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        header('Allow: POST');
        throw new RuntimeException('Method not allowed.', 405);
    }
    // A custom header and no CORS grants also protect same-site sibling origins.
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    $expected = (vp3_phone_http_secure_v1() ? 'https://' : 'http://').($_SERVER['HTTP_HOST'] ?? '');
    if (($_SERVER['HTTP_X_VP3_PHONE'] ?? '') !== '1' ||
        ($origin !== '' && strcasecmp($expected, $origin) !== 0) ||
        !in_array($_SERVER['HTTP_SEC_FETCH_SITE'] ?? 'same-origin', ['same-origin','none'], true)) {
        throw new RuntimeException('Invalid origin.', 403);
    }
    $input = vp3_phone_http_json_v1();
    $action = $input['action'] ?? null;
    unset($input['action']);
    if (!in_array($action, ['claim','status','start','fix','stop','denied','disconnect'], true)) {
        throw new RuntimeException('Unknown action.', 422);
    }
    $pdo = db();
    if (!$pdo || !vp3_phone_schema_ready_v1($pdo)) throw new RuntimeException('Upgrade required.', 503);
    $name = 'vp3_phone_device_v1';
    $token = (string)($_COOKIE[$name] ?? '');
    if ($action !== 'claim' && $token === '') throw new RuntimeException('Pair a phone first.', 401);
    $result = vp3_phone_device_v1($pdo, $token, $action, $input);
    if ($action === 'claim') {
        // Keep the credential out of script-readable storage. Server-side expiry
        // and revocation remain authoritative throughout the cookie lifetime.
        setcookie($name, $result['token'], ['expires'=>time()+365*86400,
            'path'=>url('/api/phone-location-v1.php'), 'secure'=>vp3_phone_http_secure_v1(),
            'httponly'=>true, 'samesite'=>'Strict']);
    }
    unset($result['token']);
    vp3_phone_http_send_v1($result);
} catch (Throwable $error) {
    vp3_phone_http_error_v1($error);
}
