<?php
declare(strict_types=1);
require dirname(__DIR__) . '/includes/bootstrap.php';

header('Cache-Control: no-store');
$user = current_user();
if (!$user || !has_permission('chat.access', $user)) {
    http_response_code(403);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode(['ok' => false, 'error' => 'Agent voice access unavailable.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode(['ok' => false, 'error' => 'POST required.']);
    exit;
}

$input = json_decode((string)file_get_contents('php://input'), true);
if (!is_array($input)) $input = $_POST;
if (!hash_equals(csrf_token(), (string)($input['csrf_token'] ?? ''))) {
    http_response_code(419);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode(['ok' => false, 'error' => 'Session expired.']);
    exit;
}

$text = trim((string)($input['text'] ?? ''));
if ($text === '') {
    http_response_code(422);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode(['ok' => false, 'error' => 'Voice text is required.']);
    exit;
}
if (mb_strlen($text) > 4000) $text = mb_substr($text, 0, 4000);

$apiKey = trim((string)(getenv('ELEVENLABS_API_KEY') ?: ''));
if ($apiKey === '') {
    $apiKey = ai_decrypt_secret((string)setting('ai_elevenlabs_api_key', ''));
}
$voiceId = trim((string)(getenv('ELEVENLABS_VOICE_ID') ?: setting('ai_elevenlabs_voice_id', 'JBFqnCBsd6RMkjVDRZzb')));
$modelId = trim((string)(getenv('ELEVENLABS_MODEL_ID') ?: setting('ai_elevenlabs_model_id', 'eleven_v3')));

if ($apiKey === '' || !preg_match('/^[A-Za-z0-9_-]{8,128}$/', $voiceId)) {
    http_response_code(503);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode(['ok' => false, 'error' => 'ElevenLabs voice is not configured.']);
    exit;
}
if (!in_array($modelId, ['eleven_v3', 'eleven_multilingual_v2', 'eleven_flash_v2_5', 'eleven_turbo_v2_5'], true)) {
    $modelId = 'eleven_v3';
}
if (!function_exists('curl_init')) {
    http_response_code(503);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode(['ok' => false, 'error' => 'Voice transport is unavailable.']);
    exit;
}

$endpoint = 'https://api.elevenlabs.io/v1/text-to-speech/' . rawurlencode($voiceId) . '?output_format=mp3_44100_128';
$payload = json_encode([
    'text' => $text,
    'model_id' => $modelId,
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

$curl = curl_init($endpoint);
curl_setopt_array($curl, [
    CURLOPT_POST => true,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_CONNECTTIMEOUT => 10,
    CURLOPT_TIMEOUT => 45,
    CURLOPT_HTTPHEADER => [
        'xi-api-key: ' . $apiKey,
        'Content-Type: application/json',
        'Accept: audio/mpeg',
    ],
    CURLOPT_POSTFIELDS => $payload,
    CURLOPT_FOLLOWLOCATION => false,
    CURLOPT_SSL_VERIFYPEER => true,
    CURLOPT_SSL_VERIFYHOST => 2,
]);
if (defined('CURLOPT_PROTOCOLS') && defined('CURLPROTO_HTTPS')) {
    curl_setopt($curl, CURLOPT_PROTOCOLS, CURLPROTO_HTTPS);
}
$response = curl_exec($curl);
$status = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE);
$contentType = (string)curl_getinfo($curl, CURLINFO_CONTENT_TYPE);
$error = curl_error($curl);
curl_close($curl);

if (!is_string($response) || $status < 200 || $status >= 300) {
    error_log('Stonefellow ElevenLabs TTS status=' . $status . ' error=' . mb_strimwidth($error, 0, 160, '…'));
    http_response_code($status === 401 || $status === 403 ? 502 : 503);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode(['ok' => false, 'error' => 'Premium Agent voice is temporarily unavailable.']);
    exit;
}
if (strlen($response) > 12 * 1024 * 1024) {
    http_response_code(502);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode(['ok' => false, 'error' => 'Voice response was too large.']);
    exit;
}

header('Content-Type: ' . (str_starts_with(strtolower($contentType), 'audio/') ? $contentType : 'audio/mpeg'));
header('Content-Length: ' . strlen($response));
header('X-Content-Type-Options: nosniff');
echo $response;
