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

$voicePdo = db();
if (!$voicePdo || !function_exists('chat_settings_agent_voice_enabled_v237') || !chat_settings_agent_voice_enabled_v237($voicePdo, $user)) {
    http_response_code(403);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode(['ok' => false, 'error' => 'Agent Voice is turned off or unavailable for this account.']);
    exit;
}

function stonefellow_voice_v117_models(): array
{
    return ['eleven_flash_v2_5','eleven_flash_v2','eleven_turbo_v2_5','eleven_multilingual_v2'];
}

function stonefellow_voice_v117_formats(): array
{
    return ['mp3_22050_32','mp3_44100_128'];
}

function stonefellow_voice_v244_agent_selection(array $user, int $agentId): array
{
    $selection = ['requested'=>false,'voice_id'=>'','local_verified'=>false,'source'=>'global'];
    if ($agentId < 1) return $selection;
    $pdo = db();
    if (!$pdo || !function_exists('user_agent_get_v236')) return $selection;
    $agent = user_agent_get_v236($pdo, (int)$user['id'], $agentId);
    if (!$agent || empty($agent['is_active']) || empty($agent['voice_enabled'])) return $selection;

    $selection['requested'] = true;
    $selection['source'] = 'agent_clone';
    try {
        require_once dirname(__DIR__) . '/includes/studio-participants.php';
        require_once dirname(__DIR__) . '/includes/studio-voice-profile.php';
        if (!table_exists('studio_participant_voices')) return $selection;
        $self = studio_voice_profile_self($pdo, $user);
        $stmt = $pdo->prepare(
            "SELECT clone_provider_voice_id,clone_verified
             FROM studio_participant_voices
             WHERE owner_user_id=? AND participant_id=? AND provider='elevenlabs'
             LIMIT 1"
        );
        $stmt->execute([(int)$user['id'], (int)$self['id']]);
        $row = $stmt->fetch() ?: [];
        $selection['voice_id'] = trim((string)($row['clone_provider_voice_id'] ?? ''));
        $selection['local_verified'] = !empty($row['clone_verified']);
    } catch (Throwable $error) {
        error_log('Stonefellow Agent clone selection failed: ' . mb_strimwidth($error->getMessage(), 0, 220, '…'));
    }
    return $selection;
}

function stonefellow_voice_v117_settings(?array $user = null, int $agentId = 0): array
{
    $apiKey = trim((string)(getenv('ELEVENLABS_API_KEY') ?: ''));
    $credentialState = $apiKey !== '' ? 'environment' : 'missing';
    if ($apiKey === '') {
        $encrypted = trim((string)setting('ai_elevenlabs_api_key', ''));
        $apiKey = ai_decrypt_secret($encrypted);
        if ($apiKey !== '') $credentialState = 'saved';
        elseif ($encrypted !== '') $credentialState = 'unreadable';
    }
    $voiceId = trim((string)(getenv('ELEVENLABS_VOICE_ID') ?: setting('ai_elevenlabs_voice_id', 'JBFqnCBsd6RMkjVDRZzb')));
    $voiceSource = 'global';
    if ($user && $agentId > 0) {
        $selection = stonefellow_voice_v244_agent_selection($user, $agentId);
        if (!empty($selection['requested'])) {
            $voiceId = trim((string)$selection['voice_id']);
            $voiceSource = 'agent_clone';
        }
    }
    $modelId = trim((string)(getenv('ELEVENLABS_MODEL_ID') ?: setting('ai_elevenlabs_model_id', 'eleven_flash_v2_5')));
    if (!in_array($modelId, stonefellow_voice_v117_models(), true)) $modelId = 'eleven_flash_v2_5';
    $outputFormat = trim((string)(getenv('ELEVENLABS_OUTPUT_FORMAT') ?: 'mp3_22050_32'));
    if (!in_array($outputFormat, stonefellow_voice_v117_formats(), true)) $outputFormat = 'mp3_22050_32';
    return [$apiKey, $voiceId, $modelId, $outputFormat, $credentialState, $voiceSource];
}

function stonefellow_voice_v117_json(array $payload, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function stonefellow_voice_v117_prune_tickets(): void
{
    $tickets = is_array($_SESSION['stonefellow_voice_v117'] ?? null)
        ? $_SESSION['stonefellow_voice_v117']
        : [];
    $now = time();
    foreach ($tickets as $token => $ticket) {
        if (!is_array($ticket) || (int)($ticket['expires'] ?? 0) < $now) unset($tickets[$token]);
    }
    if (count($tickets) > 12) {
        uasort($tickets, static fn(array $a, array $b): int => ((int)($a['expires'] ?? 0)) <=> ((int)($b['expires'] ?? 0)));
        $tickets = array_slice($tickets, -12, null, true);
    }
    $_SESSION['stonefellow_voice_v117'] = $tickets;
}

function stonefellow_voice_v157_upstream_error(int $status, string $detail = ''): string
{
    return match ($status) {
        401 => 'ElevenLabs rejected the saved API key. Re-enter it in Admin AI settings.',
        403 => 'The ElevenLabs account cannot use the configured voice.',
        404 => 'The configured ElevenLabs voice was not found.',
        429 => 'The ElevenLabs account has reached a rate or usage limit.',
        400, 422 => $detail !== ''
            ? 'ElevenLabs rejected the voice request: ' . mb_strimwidth($detail, 0, 180, '…')
            : 'ElevenLabs rejected the configured voice or model.',
        default => 'Premium Agent voice is temporarily unavailable.',
    };
}

function stonefellow_voice_v244_verify(string $apiKey, string $voiceId): array
{
    if ($apiKey === '' || !preg_match('/^[A-Za-z0-9_-]{8,128}$/', $voiceId)) {
        return ['ready'=>false,'verified'=>false,'status'=>0,'voice_name'=>'','error'=>'ElevenLabs voice is not configured.'];
    }
    if (!function_exists('curl_init')) {
        return ['ready'=>false,'verified'=>false,'status'=>0,'voice_name'=>'','error'=>'Voice transport is unavailable.'];
    }
    $curl = curl_init('https://api.elevenlabs.io/v1/voices/' . rawurlencode($voiceId));
    curl_setopt_array($curl, [
        CURLOPT_HTTPGET=>true,
        CURLOPT_RETURNTRANSFER=>true,
        CURLOPT_CONNECTTIMEOUT=>4,
        CURLOPT_TIMEOUT=>8,
        CURLOPT_HTTPHEADER=>['xi-api-key: ' . $apiKey, 'Accept: application/json'],
        CURLOPT_FOLLOWLOCATION=>false,
        CURLOPT_SSL_VERIFYPEER=>true,
        CURLOPT_SSL_VERIFYHOST=>2,
    ]);
    if (defined('CURLOPT_PROTOCOLS') && defined('CURLPROTO_HTTPS')) curl_setopt($curl, CURLOPT_PROTOCOLS, CURLPROTO_HTTPS);
    $raw = curl_exec($curl);
    $status = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE);
    $transportError = curl_error($curl);
    curl_close($curl);
    $decoded = is_string($raw) ? json_decode($raw, true) : null;
    if ($status >= 200 && $status < 300 && is_array($decoded) && trim((string)($decoded['voice_id'] ?? '')) !== '') {
        return [
            'ready'=>true,
            'verified'=>true,
            'status'=>$status,
            'voice_name'=>mb_strimwidth(trim((string)($decoded['name'] ?? '')), 0, 120, ''),
            'error'=>'',
        ];
    }
    $detail = '';
    if (is_array($decoded)) {
        $rawDetail = $decoded['detail'] ?? $decoded['message'] ?? '';
        $detail = is_array($rawDetail) ? trim((string)($rawDetail['message'] ?? '')) : trim((string)$rawDetail);
    }
    if ($detail === '' && $transportError !== '') $detail = $transportError;
    return [
        'ready'=>false,
        'verified'=>false,
        'status'=>$status,
        'voice_name'=>'',
        'error'=>stonefellow_voice_v157_upstream_error($status, $detail),
    ];
}

$requestMethod = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));

if ($requestMethod === 'GET') {
    $token = strtolower(trim((string)($_GET['token'] ?? '')));
    if (!preg_match('/^[a-f0-9]{48}$/', $token)) {
        stonefellow_voice_v117_json(['ok' => false, 'error' => 'Invalid voice stream token.'], 404);
    }

    stonefellow_voice_v117_prune_tickets();
    $ticket = $_SESSION['stonefellow_voice_v117'][$token] ?? null;
    unset($_SESSION['stonefellow_voice_v117'][$token]);
    if (
        !is_array($ticket) ||
        (int)($ticket['user_id'] ?? 0) !== (int)$user['id'] ||
        (int)($ticket['expires'] ?? 0) < time()
    ) {
        stonefellow_voice_v117_json(['ok' => false, 'error' => 'Voice stream expired.'], 404);
    }

    $text = trim((string)($ticket['text'] ?? ''));
    if ($text === '') stonefellow_voice_v117_json(['ok' => false, 'error' => 'Voice stream is empty.'], 422);

    // Persist one-use ticket consumption and release the PHP session lock
    // before the long-lived audio stream begins.
    if (session_status() === PHP_SESSION_ACTIVE) session_write_close();

    [$apiKey, $configuredVoiceId, $configuredModelId, $configuredOutputFormat] = stonefellow_voice_v117_settings();
    $voiceId = trim((string)($ticket['voice_id'] ?? $configuredVoiceId));
    if ($apiKey === '' || !preg_match('/^[A-Za-z0-9_-]{8,128}$/', $voiceId)) {
        stonefellow_voice_v117_json(['ok' => false, 'error' => 'ElevenLabs voice is not configured.'], 503);
    }
    if (!function_exists('curl_init')) {
        stonefellow_voice_v117_json(['ok' => false, 'error' => 'Voice transport is unavailable.'], 503);
    }

    $modelId = trim((string)($ticket['model_id'] ?? $configuredModelId));
    if (!in_array($modelId, stonefellow_voice_v117_models(), true)) $modelId = 'eleven_flash_v2_5';
    $outputFormat = trim((string)($ticket['output_format'] ?? $configuredOutputFormat));
    if (!in_array($outputFormat, stonefellow_voice_v117_formats(), true)) $outputFormat = 'mp3_22050_32';
    $endpoint = 'https://api.elevenlabs.io/v1/text-to-speech/'
        . rawurlencode($voiceId)
        . '/stream?output_format=' . rawurlencode($outputFormat);
    $payload = json_encode([
        'text' => $text,
        'model_id' => $modelId,
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if (!is_string($payload)) {
        stonefellow_voice_v117_json(['ok' => false, 'error' => 'Could not prepare voice request.'], 500);
    }

    @ini_set('zlib.output_compression', '0');
    @ini_set('output_buffering', '0');
    @set_time_limit(180);
    ignore_user_abort(false);

    $upstreamStatus = 0;
    $upstreamContentType = 'audio/mpeg';
    $errorBody = '';
    $streamStarted = false;
    $streamBytes = 0;
    $maxBytes = 12 * 1024 * 1024;

    $startStream = static function () use (&$streamStarted, &$upstreamContentType, $modelId, $outputFormat): void {
        if ($streamStarted) return;
        $streamStarted = true;
        while (ob_get_level() > 0) @ob_end_flush();
        header('Content-Type: ' . (str_starts_with(strtolower($upstreamContentType), 'audio/') ? $upstreamContentType : 'audio/mpeg'));
        header('X-Content-Type-Options: nosniff');
        header('X-Accel-Buffering: no');
        header('X-Stonefellow-Voice-Stream: 1');
        header('X-Stonefellow-Voice-Model: ' . $modelId);
        header('X-Stonefellow-Voice-Format: ' . $outputFormat);
        header('Accept-Ranges: none');
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        @ob_implicit_flush(true);
    };

    $curl = curl_init($endpoint);
    $options = [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => false,
        CURLOPT_CONNECTTIMEOUT => 6,
        CURLOPT_TIMEOUT => 150,
        CURLOPT_HTTPHEADER => [
            'xi-api-key: ' . $apiKey,
            'Content-Type: application/json',
            'Accept: audio/mpeg',
        ],
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_BUFFERSIZE => 8192,
        CURLOPT_HEADERFUNCTION => static function ($ch, string $line) use (&$upstreamStatus, &$upstreamContentType): int {
            $length = strlen($line);
            if (preg_match('/^HTTP\/\S+\s+(\d{3})/i', trim($line), $match)) {
                $upstreamStatus = (int)$match[1];
            } elseif (stripos($line, 'Content-Type:') === 0) {
                $type = trim(substr($line, strlen('Content-Type:')));
                if ($type !== '') $upstreamContentType = $type;
            }
            return $length;
        },
        CURLOPT_WRITEFUNCTION => static function ($ch, string $chunk) use (&$upstreamStatus, &$errorBody, &$streamBytes, $maxBytes, $startStream): int {
            $length = strlen($chunk);
            if ($upstreamStatus >= 200 && $upstreamStatus < 300) {
                if ($streamBytes + $length > $maxBytes) return 0;
                $startStream();
                $streamBytes += $length;
                echo $chunk;
                @flush();
                return $length;
            }
            if (strlen($errorBody) < 8192) $errorBody .= substr($chunk, 0, 8192 - strlen($errorBody));
            return $length;
        },
    ];
    if (defined('CURLOPT_PROTOCOLS') && defined('CURLPROTO_HTTPS')) $options[CURLOPT_PROTOCOLS] = CURLPROTO_HTTPS;
    if (defined('CURLOPT_HTTP_VERSION') && defined('CURL_HTTP_VERSION_2TLS')) $options[CURLOPT_HTTP_VERSION] = CURL_HTTP_VERSION_2TLS;
    if (defined('CURLOPT_TCP_KEEPALIVE')) $options[CURLOPT_TCP_KEEPALIVE] = 1;
    if (defined('CURLOPT_TCP_NODELAY')) $options[CURLOPT_TCP_NODELAY] = 1;
    curl_setopt_array($curl, $options);

    $ok = curl_exec($curl);
    $curlStatus = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE);
    $error = curl_error($curl);
    if ($upstreamStatus === 0) $upstreamStatus = $curlStatus;
    curl_close($curl);

    if ($streamStarted) exit;

    $upstreamMessage = '';
    $decoded = json_decode($errorBody, true);
    if (is_array($decoded)) {
        $detail = $decoded['detail'] ?? '';
        $upstreamMessage = is_array($detail)
            ? trim((string)($detail['message'] ?? ''))
            : trim((string)$detail);
    }
    error_log(
        'Stonefellow ElevenLabs stream model=' . $modelId
        . ' format=' . $outputFormat
        . ' status=' . $upstreamStatus
        . ' error=' . mb_strimwidth($error, 0, 160, '…')
        . ($upstreamMessage !== '' ? ' detail=' . mb_strimwidth($upstreamMessage, 0, 160, '…') : '')
    );
    stonefellow_voice_v117_json(
        ['ok' => false, 'error' => stonefellow_voice_v157_upstream_error($upstreamStatus, $upstreamMessage)],
        $upstreamStatus >= 400 && $upstreamStatus < 500 ? 502 : 503
    );
}

if ($requestMethod !== 'POST') {
    header('Allow: GET, POST');
    stonefellow_voice_v117_json(['ok' => false, 'error' => 'GET or POST required.'], 405);
}

$input = json_decode((string)file_get_contents('php://input'), true);
if (!is_array($input)) $input = $_POST;
if (!hash_equals(csrf_token(), (string)($input['csrf_token'] ?? ''))) {
    stonefellow_voice_v117_json(['ok' => false, 'error' => 'Session expired.'], 419);
}

$action = (string)($input['action'] ?? 'ticket');
if ($action === 'warm') {
    $agentId = max(0, (int)($_GET['agent'] ?? $input['agent'] ?? 0));
    [$apiKey, $voiceId, $modelId, $outputFormat, $credentialState, $voiceSource] = stonefellow_voice_v117_settings($user, $agentId);
    $verification = stonefellow_voice_v244_verify($apiKey, $voiceId);
    $ready = !empty($verification['ready']);
    $error = (string)($verification['error'] ?? '');
    if (!$ready && $credentialState === 'unreadable') {
        $error = 'The saved ElevenLabs credential cannot be decrypted. Re-enter it in Admin AI settings.';
    } elseif (!$ready && $voiceSource === 'agent_clone' && $voiceId === '') {
        $error = 'This Agent is set to use your ElevenLabs clone, but no clone is currently available.';
    }
    header('X-Stonefellow-Voice-Model: ' . $modelId);
    header('X-Stonefellow-Voice-Format: ' . $outputFormat);
    header('X-Stonefellow-Voice-Ready: ' . ($ready ? '1' : '0'));
    stonefellow_voice_v117_json([
        'ok' => $ready,
        'ready' => $ready,
        'verified' => !empty($verification['verified']),
        'upstream_status' => (int)($verification['status'] ?? 0),
        'model_id' => $modelId,
        'output_format' => $outputFormat,
        'voice_source' => $voiceSource,
        'voice_name' => (string)($verification['voice_name'] ?? ''),
        'streaming' => true,
        'chunked' => true,
        'latency_profile' => 'fast',
        'credential_state' => $credentialState,
        'readiness_authority' => 'elevenlabs-get-voice',
        'error' => $error,
    ], 200);
}
if (!in_array($action, ['ticket', 'speak'], true)) {
    stonefellow_voice_v117_json(['ok' => false, 'error' => 'Unknown voice action.'], 422);
}

$text = trim((string)($input['text'] ?? ''));
if ($text === '') stonefellow_voice_v117_json(['ok' => false, 'error' => 'Voice text is required.'], 422);
// Never silently truncate spoken output. The browser splits long responses at
// sentence boundaries; an oversized ticket means that client contract failed.
if (mb_strlen($text) > 2000) {
    stonefellow_voice_v117_json(['ok' => false, 'error' => 'Voice chunk is too long.'], 422);
}

$agentId = max(0, (int)($_GET['agent'] ?? $input['agent'] ?? 0));
[$apiKey, $voiceId, $modelId, $outputFormat] = stonefellow_voice_v117_settings($user, $agentId);
if ($apiKey === '' || !preg_match('/^[A-Za-z0-9_-]{8,128}$/', $voiceId)) {
    stonefellow_voice_v117_json(['ok' => false, 'error' => 'ElevenLabs voice is not configured.'], 503);
}

stonefellow_voice_v117_prune_tickets();
try {
    $token = bin2hex(random_bytes(24));
} catch (Throwable $error) {
    stonefellow_voice_v117_json(['ok' => false, 'error' => 'Could not create voice stream.'], 500);
}
$_SESSION['stonefellow_voice_v117'][$token] = [
    'user_id' => (int)$user['id'],
    'text' => $text,
    'voice_id' => $voiceId,
    'model_id' => $modelId,
    'output_format' => $outputFormat,
    'expires' => time() + 180,
];

stonefellow_voice_v117_json([
    'ok' => true,
    'streaming' => true,
    'model_id' => $modelId,
    'output_format' => $outputFormat,
    'latency_profile' => 'fast',
    'stream_url' => url('/api/agent-voice-v117.php?token=' . rawurlencode($token)),
]);
