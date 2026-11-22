<?php
declare(strict_types=1);

function vp3_phone_http_headers_v1(): void
{
    header('Content-Type: application/json; charset=UTF-8');
    header('Cache-Control: private, no-store, max-age=0');
    header('Referrer-Policy: no-referrer');
    header('X-Content-Type-Options: nosniff');
    header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
}

function vp3_phone_http_secure_v1(): bool
{
    $https = strtolower((string)($_SERVER['HTTPS'] ?? ''));
    return $https !== '' && $https !== 'off' && $https !== '0';
}

function vp3_phone_http_transport_v1(): void
{
    $host = strtolower((string)parse_url('http://'.($_SERVER['HTTP_HOST'] ?? ''), PHP_URL_HOST));
    $local = in_array($host, ['localhost','127.0.0.1','[::1]'], true)
        && in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1','::1'], true);
    if (!vp3_phone_http_secure_v1() && !$local) {
        throw new RuntimeException('Open Phone Tracking over HTTPS.', 403);
    }
}

function vp3_phone_http_json_v1(): array
{
    $type = strtolower(trim(explode(';', (string)($_SERVER['CONTENT_TYPE'] ?? ''), 2)[0]));
    if ($type !== 'application/json') throw new RuntimeException('Use a JSON request.', 415);
    $raw = file_get_contents('php://input', false, null, 0, 4097);
    if ($raw === false || strlen($raw) > 4096) throw new RuntimeException('Request too large.', 413);
    $object = json_decode($raw, true, 6);
    if (!is_array($object) || !str_starts_with(ltrim($raw), '{')) throw new RuntimeException('Invalid request.', 422);
    return $object;
}

function vp3_phone_http_send_v1(array $result): void
{
    echo json_encode(['ok'=>true] + $result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
}

function vp3_phone_http_error_v1(Throwable $error): void
{
    $status = (int)$error->getCode();
    if (!($error instanceof RuntimeException) || $error instanceof PDOException ||
        !in_array($status, [400,401,403,404,405,409,413,415,419,422,429,503], true)) $status = 503;
    http_response_code($status);
    $message = match ($status) {
        401,419 => 'Your session changed. Refresh before continuing.',
        403,404 => 'This phone connection or request is unavailable. Check the connection and permissions.',
        409 => 'The device or invitation changed. Refresh its status before trying again.',
        413,415,400,422 => 'Check the phone request and try again.',
        405 => 'Use the correct method for this phone action.',
        429 => 'Please wait before trying again.',
        default => 'Phone Tracking is unavailable. Check the database upgrade and try again.',
    };
    echo json_encode(['ok'=>false, 'error'=>$message]);
}
