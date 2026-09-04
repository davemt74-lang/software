<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once dirname(__DIR__) . '/includes/studio-participants.php';
require_once dirname(__DIR__) . '/includes/artist-listening.php';

function studio_participants_json(bool $ok, array $data = [], int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=UTF-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    echo json_encode(['ok'=>$ok] + $data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function studio_participants_elevenlabs_key(): string
{
    $key = trim((string)(getenv('ELEVENLABS_API_KEY') ?: ''));
    if ($key !== '') return $key;
    $encrypted = trim((string)setting('ai_elevenlabs_api_key', ''));
    return $encrypted !== '' && function_exists('ai_decrypt_secret') ? ai_decrypt_secret($encrypted) : '';
}

function studio_participants_existing_binding(PDO $pdo, array $user, int $participantId): array
{
    $stmt = $pdo->prepare(
        "SELECT recognition_provider_speaker_id,clone_provider_voice_id,source_session_id,source_recording_key,recognition_verified,clone_verified
         FROM studio_participant_voices
         WHERE owner_user_id=? AND participant_id=? AND provider='elevenlabs' LIMIT 1"
    );
    $stmt->execute([(int)$user['id'], $participantId]);
    $row = $stmt->fetch() ?: [];
    return [
        'recognition_provider_speaker_id'=>trim((string)($row['recognition_provider_speaker_id'] ?? '')),
        'clone_provider_voice_id'=>trim((string)($row['clone_provider_voice_id'] ?? '')),
        'source_session_id'=>max(0,(int)($row['source_session_id'] ?? 0)),
        'source_recording_key'=>trim((string)($row['source_recording_key'] ?? '')),
        'recognition_verified'=>!empty($row['recognition_verified']),
        'clone_verified'=>!empty($row['clone_verified']),
    ];
}

function studio_participants_recording_source(PDO $pdo, array $user, int $sessionId, string $recordingKey): array
{
    if ($sessionId < 1) throw new RuntimeException('Choose a retained recording.');
    $recordingKey = artist_listening_v197_recording_key($recordingKey);
    $session = artist_listening_v172_session($pdo, $user, $sessionId);
    foreach (artist_listening_v197_recordings($session) as $recording) {
        if ((string)($recording['key'] ?? '') !== $recordingKey) continue;
        $file = basename((string)($recording['file_name'] ?? ''));
        $path = artist_listening_v197_private_dir($user, $sessionId) . '/' . $file;
        if ($file === '' || !is_file($path) || !is_readable($path)) throw new RuntimeException('The retained recording file is unavailable.');
        return ['path'=>$path,'mime'=>(string)($recording['mime_type'] ?? 'audio/webm'),'recording'=>$recording];
    }
    throw new RuntimeException('Retained recording not found.');
}

function studio_participants_clone_voice(PDO $pdo, array $user, array $input): array
{
    if (!has_permission('artist_listening.access', $user)) {
        throw new RuntimeException('Artist Listening access is required to clone from a retained recording.');
    }
    $participantId = max(0, (int)($input['participant_id'] ?? 0));
    $profile = studio_participants_profile($pdo, $user, $participantId);
    if ((int)($profile['linked_user_id'] ?? 0) !== (int)$user['id'] || (string)$profile['relationship_scope'] !== 'self') {
        throw new RuntimeException('A Stonefellow account may only create a clone of its own voice.');
    }
    if (empty($profile['cloning_consent']) || empty($input['consent_confirmed'])) {
        throw new RuntimeException('Explicit voice-cloning consent and ownership confirmation are required.');
    }
    $sessionId = max(0, (int)($input['session_id'] ?? 0));
    $recordingKey = trim((string)($input['recording_key'] ?? ''));
    $source = studio_participants_recording_source($pdo, $user, $sessionId, $recordingKey);
    if (!function_exists('curl_init')) throw new RuntimeException('Voice cloning transport is unavailable.');
    $apiKey = studio_participants_elevenlabs_key();
    if ($apiKey === '') throw new RuntimeException('ElevenLabs is not configured.');

    $curl = curl_init('https://api.elevenlabs.io/v1/voices/add');
    $file = new CURLFile($source['path'], $source['mime'], basename($source['path']));
    $post = [
        'name'=>mb_strimwidth((string)$profile['display_name'] . ' · Stonefellow', 0, 100, ''),
        'description'=>'User-consented Stonefellow voice clone.',
        'remove_background_noise'=>'false',
        'files[0]'=>$file,
    ];
    curl_setopt_array($curl, [
        CURLOPT_POST=>true,
        CURLOPT_RETURNTRANSFER=>true,
        CURLOPT_CONNECTTIMEOUT=>10,
        CURLOPT_TIMEOUT=>90,
        CURLOPT_HTTPHEADER=>['xi-api-key: '.$apiKey,'Accept: application/json'],
        CURLOPT_POSTFIELDS=>$post,
        CURLOPT_FOLLOWLOCATION=>false,
        CURLOPT_SSL_VERIFYPEER=>true,
        CURLOPT_SSL_VERIFYHOST=>2,
    ]);
    if (defined('CURLOPT_PROTOCOLS') && defined('CURLPROTO_HTTPS')) curl_setopt($curl, CURLOPT_PROTOCOLS, CURLPROTO_HTTPS);
    $raw = curl_exec($curl);
    $status = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE);
    $error = curl_error($curl);
    curl_close($curl);
    $data = is_string($raw) ? json_decode($raw, true) : null;
    if ($status < 200 || $status >= 300 || !is_array($data) || trim((string)($data['voice_id'] ?? '')) === '') {
        error_log('Stonefellow voice clone status='.$status.' error='.mb_strimwidth($error,0,160,'…'));
        throw new RuntimeException($status === 401 || $status === 403
            ? 'ElevenLabs rejected the voice-cloning credentials or permission.'
            : 'ElevenLabs could not create the voice clone.');
    }

    $existing = studio_participants_existing_binding($pdo, $user, $participantId);
    studio_participants_bind_voice($pdo, $user, $participantId, [
        'provider'=>'elevenlabs',
        'recognition_provider_speaker_id'=>$existing['recognition_provider_speaker_id'],
        'clone_provider_voice_id'=>(string)$data['voice_id'],
        'source_session_id'=>$sessionId,
        'source_recording_key'=>$recordingKey,
        'recognition_verified'=>$existing['recognition_verified'],
        'clone_verified'=>empty($data['requires_verification']),
    ]);
    return studio_participants_voice($pdo, $user, $participantId);
}

$user = current_user();
if (!$user || !has_permission('chat.access', $user)) studio_participants_json(false, ['error'=>'Studio participant access unavailable.'], 403);
$pdo = db();
if (!$pdo) studio_participants_json(false, ['error'=>'Database unavailable.'], 503);
try {
    if (!studio_participants_schema_ready()) studio_participants_ensure_schema();
} catch (Throwable $e) {
    studio_participants_json(false, ['error'=>'Studio participant storage is not ready. Run the database upgrade.'], 503);
}

$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
$action = trim((string)($_GET['action'] ?? 'profiles'));

try {
    if ($method === 'GET') {
        if ($action === 'profiles') {
            studio_participants_json(true, [
                'build'=>STONEFELLOW_STUDIO_PARTICIPANTS,
                'recognition_threshold'=>STONEFELLOW_PARTICIPANT_RECOGNITION_THRESHOLD,
                'profiles'=>studio_participants_list($pdo, $user),
            ]);
        }
        if ($action === 'context') {
            studio_participants_json(true, ['context'=>studio_participants_context(
                $pdo,$user,max(0,(int)($_GET['conversation_id'] ?? 0)),max(0,(int)($_GET['transcript_session_id'] ?? 0))
            )]);
        }
        studio_participants_json(false, ['error'=>'Unknown participant request.'], 404);
    }
    if ($method !== 'POST') studio_participants_json(false, ['error'=>'POST is required.'], 405);
    $input = json_decode((string)file_get_contents('php://input'), true);
    if (!is_array($input)) $input = $_POST;
    $csrf = trim((string)($input['csrf_token'] ?? ''));
    if ($csrf === '' || !hash_equals(csrf_token(), $csrf)) studio_participants_json(false, ['error'=>'Session expired. Refresh and try again.'], 419);
    $action = trim((string)($input['action'] ?? ''));

    if ($action === 'save_profile') {
        $profile = studio_participants_save_profile($pdo, $user, $input);
        studio_participants_json(true, ['participant_id'=>(int)$profile['id'],'profiles'=>studio_participants_list($pdo, $user)]);
    }
    if ($action === 'set_consent') {
        $profile = studio_participants_set_consent($pdo, $user, $input);
        studio_participants_json(true, ['participant_id'=>(int)$profile['id'],'profiles'=>studio_participants_list($pdo, $user)]);
    }
    if ($action === 'bind_recognition') {
        $participantId = max(0, (int)($input['participant_id'] ?? 0));
        $profile = studio_participants_profile($pdo, $user, $participantId);
        if ((int)($profile['linked_user_id'] ?? 0) !== (int)$user['id']) {
            throw new RuntimeException('Contact voice identities must be shared by the contact account; they cannot be created on someone else’s behalf.');
        }
        $speakerId = trim((string)($input['provider_speaker_id'] ?? ''));
        if ($speakerId === '') throw new RuntimeException('A provider speaker identity is required.');
        $existing = studio_participants_existing_binding($pdo, $user, $participantId);
        $voice = studio_participants_bind_voice($pdo, $user, $participantId, [
            'provider'=>'elevenlabs',
            'recognition_provider_speaker_id'=>$speakerId,
            'clone_provider_voice_id'=>$existing['clone_provider_voice_id'],
            'source_session_id'=>$existing['source_session_id'],
            'source_recording_key'=>$existing['source_recording_key'],
            'recognition_verified'=>true,
            'clone_verified'=>$existing['clone_verified'],
        ]);
        studio_participants_json(true, ['voice'=>$voice]);
    }
    if ($action === 'clone_from_recording') {
        studio_participants_json(true, ['voice'=>studio_participants_clone_voice($pdo, $user, $input)]);
    }
    if ($action === 'record_presence' || $action === 'record_recognition') {
        $receipt = studio_participants_record_presence($pdo, $user, $input);
        studio_participants_json(true, [
            'receipt'=>$receipt,
            'authentication_authority'=>false,
            'context'=>studio_participants_context($pdo,$user,max(0,(int)($input['conversation_id'] ?? 0)),max(0,(int)($input['transcript_session_id'] ?? 0))),
        ]);
    }
    studio_participants_json(false, ['error'=>'Unsupported participant action.'], 422);
} catch (Throwable $e) {
    $message = $e instanceof RuntimeException ? $e->getMessage() : 'Studio participant request failed.';
    studio_participants_json(false, ['error'=>$message], $e instanceof RuntimeException ? 422 : 500);
}
