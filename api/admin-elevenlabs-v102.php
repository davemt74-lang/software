<?php
declare(strict_types=1);
require dirname(__DIR__) . '/includes/bootstrap.php';
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');

$user = current_user();
if (!$user || !has_permission('ai.manage', $user)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'AI settings access unavailable.']);
    exit;
}

$allowedModels = [
    'eleven_flash_v2_5' => 'Flash v2.5 · Fastest',
    'eleven_flash_v2' => 'Flash v2 · Fast English',
    'eleven_turbo_v2_5' => 'Turbo v2.5',
    'eleven_multilingual_v2' => 'Multilingual v2 · Higher quality / slower',
];

$readState = static function () use ($allowedModels): array {
    $encrypted = trim((string)setting('ai_elevenlabs_api_key', ''));
    $plain = $encrypted !== '' ? ai_decrypt_secret($encrypted) : '';
    $saved = $plain !== '';
    $credentialError = $encrypted !== '' && $plain === '';
    $suffix = '';
    if ($saved) {
        $suffix = mb_strlen($plain) > 6 ? mb_substr($plain, -6) : $plain;
    }
    $voiceId = trim((string)setting('ai_elevenlabs_voice_id', 'JBFqnCBsd6RMkjVDRZzb'));
    $modelId = trim((string)setting('ai_elevenlabs_model_id', 'eleven_flash_v2_5'));
    // Older builds exposed eleven_v3 here even though Stonefellow's realtime
    // voice path uses the standard TTS streaming endpoint. Migrate that stale
    // selection to the fastest supported realtime TTS model.
    if (!isset($allowedModels[$modelId])) $modelId = 'eleven_flash_v2_5';
    $probe = $_SESSION['stonefellow_voice_v157_probe'] ?? null;
    $probeKey = $saved ? hash('sha256', $voiceId . "\0" . $plain) : '';
    $verified = is_array($probe)
        && $probeKey !== ''
        && hash_equals((string)($probe['key'] ?? ''), $probeKey)
        && !empty($probe['ok'])
        && (int)($probe['expires'] ?? 0) >= time();
    return [
        'saved' => $saved,
        'verified' => $verified,
        'credential_error' => $credentialError,
        'key_suffix' => $suffix,
        'voice_id' => $voiceId,
        'model_id' => $modelId,
        'models' => $allowedModels,
        'latency_profile' => 'fast',
    ];
};

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    echo json_encode(['ok' => true, 'settings' => $readState()], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: GET, POST');
    echo json_encode(['ok' => false, 'error' => 'Unsupported method.']);
    exit;
}

$input = json_decode((string)file_get_contents('php://input'), true);
if (!is_array($input)) $input = $_POST;
if (!hash_equals(csrf_token(), (string)($input['csrf_token'] ?? ''))) {
    http_response_code(419);
    echo json_encode(['ok' => false, 'error' => 'Session expired.']);
    exit;
}

try {
    $voiceId = trim((string)($input['voice_id'] ?? ''));
    $modelId = trim((string)($input['model_id'] ?? 'eleven_flash_v2_5'));
    $apiKey = trim((string)($input['api_key'] ?? ''));
    $removeKey = !empty($input['remove_key']);

    if (!preg_match('/^[A-Za-z0-9_-]{8,128}$/', $voiceId)) {
        throw new RuntimeException('Enter a valid ElevenLabs Voice ID.');
    }
    if (!isset($allowedModels[$modelId])) {
        throw new RuntimeException('Select a valid ElevenLabs model.');
    }

    save_setting('ai_elevenlabs_voice_id', $voiceId);
    save_setting('ai_elevenlabs_model_id', $modelId);
    if ($removeKey) {
        save_setting('ai_elevenlabs_api_key', '');
    } elseif ($apiKey !== '') {
        save_setting('ai_elevenlabs_api_key', ai_encrypt_secret($apiKey));
    }

    echo json_encode(['ok' => true, 'settings' => $readState()], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
