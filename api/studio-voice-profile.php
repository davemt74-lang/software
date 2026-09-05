<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once dirname(__DIR__) . '/includes/studio-participants.php';
require_once dirname(__DIR__) . '/includes/studio-voice-profile.php';

function studio_voice_profile_json(bool $ok, array $data = [], int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=UTF-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    echo json_encode(['ok'=>$ok] + $data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function studio_voice_profile_elevenlabs_key(): string
{
    $key = trim((string)(getenv('ELEVENLABS_API_KEY') ?: ''));
    if ($key !== '') return $key;
    $encrypted = trim((string)setting('ai_elevenlabs_api_key', ''));
    return $encrypted !== '' && function_exists('ai_decrypt_secret') ? ai_decrypt_secret($encrypted) : '';
}

function studio_voice_profile_delete_remote_voice(string $apiKey, string $voiceId): array
{
    $voiceId = trim($voiceId);
    if ($apiKey === '' || $voiceId === '' || !function_exists('curl_init')) {
        return ['deleted'=>false,'status'=>0,'error'=>'Voice deletion transport unavailable.'];
    }
    $curl = curl_init('https://api.elevenlabs.io/v1/voices/'.rawurlencode($voiceId));
    curl_setopt_array($curl, [
        CURLOPT_CUSTOMREQUEST=>'DELETE',
        CURLOPT_RETURNTRANSFER=>true,
        CURLOPT_CONNECTTIMEOUT=>8,
        CURLOPT_TIMEOUT=>30,
        CURLOPT_HTTPHEADER=>['xi-api-key: '.$apiKey,'Accept: application/json'],
        CURLOPT_FOLLOWLOCATION=>false,
        CURLOPT_SSL_VERIFYPEER=>true,
        CURLOPT_SSL_VERIFYHOST=>2,
    ]);
    if (defined('CURLOPT_PROTOCOLS') && defined('CURLPROTO_HTTPS')) curl_setopt($curl, CURLOPT_PROTOCOLS, CURLPROTO_HTTPS);
    curl_exec($curl);
    $status = (int)curl_getinfo($curl,CURLINFO_HTTP_CODE);
    $error = curl_error($curl);
    curl_close($curl);
    return [
        'deleted'=>(($status >= 200 && $status < 300) || $status === 404),
        'status'=>$status,
        'error'=>$error,
    ];
}

function studio_voice_profile_binding(PDO $pdo, array $user, int $participantId): array
{
    $stmt = $pdo->prepare(
        "SELECT recognition_provider_speaker_id,clone_provider_voice_id,recognition_verified,clone_verified,source_recording_key
         FROM studio_participant_voices
         WHERE owner_user_id=? AND participant_id=? AND provider='elevenlabs' LIMIT 1"
    );
    $stmt->execute([(int)$user['id'], $participantId]);
    $row = $stmt->fetch() ?: [];
    return [
        'recognition_provider_speaker_id'=>trim((string)($row['recognition_provider_speaker_id'] ?? '')),
        'clone_provider_voice_id'=>trim((string)($row['clone_provider_voice_id'] ?? '')),
        'recognition_verified'=>!empty($row['recognition_verified']),
        'clone_verified'=>!empty($row['clone_verified']),
        'source_recording_key'=>trim((string)($row['source_recording_key'] ?? '')),
    ];
}

function studio_voice_profile_state(PDO $pdo, array $user): array
{
    $self = studio_voice_profile_self($pdo, $user);
    $profiles = studio_participants_list($pdo, $user);
    $profile = null;
    foreach ($profiles as $row) {
        if ((int)$row['id'] === (int)$self['id']) { $profile = $row; break; }
    }
    if (!$profile) throw new RuntimeException('Your Voice Profile is unavailable.');
    $samples = studio_voice_profile_list_samples($pdo, $user, (int)$self['id']);
    $voice = studio_participants_voice($pdo, $user, (int)$self['id']);
    $binding = studio_voice_profile_binding($pdo, $user, (int)$self['id']);
    $voice['source_sample_id'] = 0;
    if (str_starts_with($binding['source_recording_key'], 'voice-sample-')) {
        $sourceKey = substr($binding['source_recording_key'], strlen('voice-sample-'));
        foreach ($samples as $sample) {
            if (hash_equals((string)$sample['sample_key'], $sourceKey)) {
                $voice['source_sample_id'] = (int)$sample['id'];
                break;
            }
        }
    }
    return [
        'build'=>STONEFELLOW_STUDIO_VOICE_PROFILE,
        'profile'=>$profile,
        'voice'=>$voice,
        'samples'=>$samples,
        'limits'=>[
            'max_sample_bytes'=>STONEFELLOW_STUDIO_VOICE_SAMPLE_MAX_BYTES,
            'recognition_threshold'=>STONEFELLOW_PARTICIPANT_RECOGNITION_THRESHOLD,
        ],
    ];
}

function studio_voice_profile_stream_sample(PDO $pdo, array $user, int $sampleId): never
{
    $sample = studio_voice_profile_sample($pdo, $user, $sampleId);
    $path = studio_voice_profile_sample_path($user, $sample);
    $real = realpath($path);
    $root = realpath(studio_voice_profile_private_dir($user));
    if (!$real || !$root || !is_file($real) || !str_starts_with($real, rtrim($root, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR)) {
        http_response_code(404); exit;
    }
    $size = filesize($real);
    if ($size === false || $size < 1) { http_response_code(404); exit; }
    $start = 0; $end = $size - 1; $status = 200;
    $range = (string)($_SERVER['HTTP_RANGE'] ?? '');
    if ($range !== '' && preg_match('/bytes=(\d*)-(\d*)/', $range, $match)) {
        if ($match[1] === '' && $match[2] !== '') $start = max(0, $size - (int)$match[2]);
        else {
            $start = $match[1] !== '' ? (int)$match[1] : 0;
            $end = $match[2] !== '' ? (int)$match[2] : $end;
        }
        if ($start > $end || $start >= $size) {
            header('Content-Range: bytes */'.$size); http_response_code(416); exit;
        }
        $end = min($end, $size - 1); $status = 206;
    }
    $length = $end - $start + 1;
    http_response_code($status);
    header('Content-Type: '.(string)$sample['mime_type']);
    header('Content-Length: '.$length);
    header('Accept-Ranges: bytes');
    header('Cache-Control: private, max-age=0, must-revalidate');
    header('X-Content-Type-Options: nosniff');
    if ($status === 206) header("Content-Range: bytes {$start}-{$end}/{$size}");
    $handle = fopen($real, 'rb');
    if (!$handle) { http_response_code(500); exit; }
    fseek($handle, $start);
    $remaining = $length;
    while ($remaining > 0 && !feof($handle)) {
        $chunk = fread($handle, min(8192, $remaining));
        if ($chunk === false || $chunk === '') break;
        echo $chunk; $remaining -= strlen($chunk);
        if (connection_status() !== CONNECTION_NORMAL) break;
    }
    fclose($handle); exit;
}

function studio_voice_profile_clone(PDO $pdo, array $user, array $input): array
{
    $self = studio_voice_profile_self($pdo, $user);
    $participantId = (int)$self['id'];
    if (empty($self['cloning_consent']) || empty($input['ownership_confirmed'])) {
        throw new RuntimeException('Enable cloning consent and confirm that the selected sample is your own voice.');
    }
    $existing = studio_voice_profile_binding($pdo, $user, $participantId);
    if ($existing['clone_provider_voice_id'] !== '') {
        throw new RuntimeException('A voice clone already exists. Revoke it before creating a replacement.');
    }
    $sample = studio_voice_profile_sample($pdo, $user, max(0,(int)($input['sample_id'] ?? 0)));
    if ((int)$sample['participant_id'] !== $participantId) throw new RuntimeException('Choose one of your Voice Profile samples.');
    $path = studio_voice_profile_sample_path($user, $sample);
    if (!is_file($path) || !is_readable($path)) throw new RuntimeException('The selected voice sample is unavailable.');
    if (!function_exists('curl_init')) throw new RuntimeException('Voice cloning transport is unavailable.');
    $apiKey = studio_voice_profile_elevenlabs_key();
    if ($apiKey === '') throw new RuntimeException('ElevenLabs is not configured.');

    $curl = curl_init('https://api.elevenlabs.io/v1/voices/add');
    $post = [
        'name'=>mb_strimwidth((string)$self['display_name'].' · Stonefellow',0,100,''),
        'description'=>'User-confirmed Stonefellow Voice Profile clone.',
        'remove_background_noise'=>'false',
        'files[0]'=>new CURLFile($path,(string)$sample['mime_type'],basename($path)),
    ];
    curl_setopt_array($curl, [
        CURLOPT_POST=>true,CURLOPT_RETURNTRANSFER=>true,CURLOPT_CONNECTTIMEOUT=>10,CURLOPT_TIMEOUT=>90,
        CURLOPT_HTTPHEADER=>['xi-api-key: '.$apiKey,'Accept: application/json'],CURLOPT_POSTFIELDS=>$post,
        CURLOPT_FOLLOWLOCATION=>false,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,
    ]);
    if (defined('CURLOPT_PROTOCOLS') && defined('CURLPROTO_HTTPS')) curl_setopt($curl, CURLOPT_PROTOCOLS, CURLPROTO_HTTPS);
    $raw = curl_exec($curl);
    $status = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE);
    $error = curl_error($curl);
    curl_close($curl);
    $data = is_string($raw) ? json_decode($raw, true) : null;
    $voiceId = is_array($data) ? trim((string)($data['voice_id'] ?? '')) : '';
    if ($status < 200 || $status >= 300 || $voiceId === '') {
        error_log('Stonefellow Voice Profile clone status='.$status.' error='.mb_strimwidth($error,0,160,'…'));
        throw new RuntimeException(in_array($status,[401,403],true)
            ? 'ElevenLabs rejected the saved cloning credentials or permission.'
            : 'ElevenLabs could not create the voice clone.');
    }

    try {
        studio_participants_bind_voice($pdo, $user, $participantId, [
            'provider'=>'elevenlabs',
            'clone_provider_voice_id'=>$voiceId,
            'source_session_id'=>0,
            'source_recording_key'=>'voice-sample-'.(string)$sample['sample_key'],
            'clone_verified'=>empty($data['requires_verification']),
        ]);
    } catch (Throwable $bindError) {
        $cleanup = studio_voice_profile_delete_remote_voice($apiKey, $voiceId);
        if (!$cleanup['deleted']) {
            error_log('Stonefellow Voice Profile orphan cleanup status='.(int)$cleanup['status'].' error='.mb_strimwidth((string)$cleanup['error'],0,160,'…'));
            throw new RuntimeException('Stonefellow could not finish saving the clone and remote cleanup was not confirmed. Do not retry until the ElevenLabs account is checked.');
        }
        throw new RuntimeException('Stonefellow could not finish saving the clone. The remote voice was removed; try again.');
    }
    return studio_voice_profile_state($pdo, $user);
}

function studio_voice_profile_preview(PDO $pdo, array $user, array $input): never
{
    $self = studio_voice_profile_self($pdo, $user);
    $binding = studio_voice_profile_binding($pdo, $user, (int)$self['id']);
    $voiceId = $binding['clone_provider_voice_id'];
    if ($voiceId === '') studio_voice_profile_json(false, ['error'=>'Create your voice clone before previewing it.'], 422);
    $text = trim((string)($input['text'] ?? ''));
    if ($text === '') $text = 'This is my Stonefellow voice profile.';
    $text = mb_strimwidth($text, 0, 360, '');
    $apiKey = studio_voice_profile_elevenlabs_key();
    if ($apiKey === '' || !function_exists('curl_init')) studio_voice_profile_json(false, ['error'=>'ElevenLabs preview is unavailable.'], 503);
    $allowedModels = ['eleven_flash_v2_5','eleven_flash_v2','eleven_turbo_v2_5','eleven_multilingual_v2'];
    $model = trim((string)(getenv('ELEVENLABS_MODEL_ID') ?: setting('ai_elevenlabs_model_id','eleven_flash_v2_5')));
    if (!in_array($model, $allowedModels, true)) $model = 'eleven_flash_v2_5';
    $endpoint = 'https://api.elevenlabs.io/v1/text-to-speech/'.rawurlencode($voiceId).'?output_format=mp3_44100_128';
    $payload = json_encode(['text'=>$text,'model_id'=>$model], JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
    $curl = curl_init($endpoint);
    curl_setopt_array($curl, [
        CURLOPT_POST=>true,CURLOPT_RETURNTRANSFER=>true,CURLOPT_CONNECTTIMEOUT=>8,CURLOPT_TIMEOUT=>60,
        CURLOPT_HTTPHEADER=>['xi-api-key: '.$apiKey,'Content-Type: application/json','Accept: audio/mpeg'],
        CURLOPT_POSTFIELDS=>$payload,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,
    ]);
    if (defined('CURLOPT_PROTOCOLS') && defined('CURLPROTO_HTTPS')) curl_setopt($curl, CURLOPT_PROTOCOLS, CURLPROTO_HTTPS);
    $audio = curl_exec($curl); $status = (int)curl_getinfo($curl,CURLINFO_HTTP_CODE); $error = curl_error($curl); curl_close($curl);
    if ($status < 200 || $status >= 300 || !is_string($audio) || $audio === '') {
        error_log('Stonefellow Voice Profile preview status='.$status.' error='.mb_strimwidth($error,0,160,'…'));
        studio_voice_profile_json(false, ['error'=>'ElevenLabs could not generate the voice preview.'], 502);
    }
    header('Content-Type: audio/mpeg');
    header('Content-Length: '.strlen($audio));
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    echo $audio; exit;
}

function studio_voice_profile_revoke_clone(PDO $pdo, array $user): array
{
    $self = studio_voice_profile_self($pdo, $user);
    $participantId = (int)$self['id'];
    $binding = studio_voice_profile_binding($pdo, $user, $participantId);
    $voiceId = $binding['clone_provider_voice_id'];
    if ($voiceId === '') return studio_voice_profile_state($pdo, $user);
    $apiKey = studio_voice_profile_elevenlabs_key();
    if ($apiKey === '') throw new RuntimeException('ElevenLabs is unavailable, so the remote clone was not revoked.');
    $delete = studio_voice_profile_delete_remote_voice($apiKey, $voiceId);
    if (!$delete['deleted']) {
        error_log('Stonefellow Voice Profile revoke status='.(int)$delete['status'].' error='.mb_strimwidth((string)$delete['error'],0,160,'…'));
        throw new RuntimeException('ElevenLabs did not confirm deletion, so the local clone binding was kept.');
    }
    studio_participants_bind_voice($pdo, $user, $participantId, [
        'provider'=>'elevenlabs','clone_provider_voice_id'=>'','clone_verified'=>false,
        'source_session_id'=>0,'source_recording_key'=>'',
    ]);
    return studio_voice_profile_state($pdo, $user);
}

$user = current_user();
if (!$user || !has_permission('account.access',$user) || !personal_capability_has_v242('voice_profile.access',$user)) {
    studio_voice_profile_json(false, ['error'=>'Voice Profile access unavailable.'], 403);
}
$pdo = db();
if (!$pdo) studio_voice_profile_json(false, ['error'=>'Database unavailable.'], 503);
try {
    if (!studio_participants_schema_ready()) studio_participants_ensure_schema();
    if (!studio_voice_profile_schema_ready()) studio_voice_profile_ensure_schema();
} catch (Throwable $e) {
    studio_voice_profile_json(false, ['error'=>'Voice Profile storage is not ready. Run the database upgrade.'], 503);
}

$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
$action = trim((string)($_GET['action'] ?? 'state'));
try {
    if ($method === 'GET') {
        if ($action === 'state') studio_voice_profile_json(true, ['state'=>studio_voice_profile_state($pdo,$user)]);
        if ($action === 'sample') studio_voice_profile_stream_sample($pdo,$user,max(0,(int)($_GET['sample_id'] ?? 0)));
        studio_voice_profile_json(false, ['error'=>'Unknown Voice Profile request.'], 404);
    }
    if ($method !== 'POST') studio_voice_profile_json(false, ['error'=>'GET or POST is required.'], 405);

    $contentType = strtolower((string)($_SERVER['CONTENT_TYPE'] ?? ''));
    if (str_contains($contentType, 'multipart/form-data')) $input = $_POST;
    else {
        $input = json_decode((string)file_get_contents('php://input'), true);
        if (!is_array($input)) $input = $_POST;
    }
    if (!hash_equals(csrf_token(), (string)($input['csrf_token'] ?? ''))) studio_voice_profile_json(false, ['error'=>'Session expired. Refresh and try again.'], 419);
    $action = trim((string)($input['action'] ?? ''));

    if ($action === 'upload_sample') {
        $self = studio_voice_profile_self($pdo,$user);
        studio_voice_profile_store_sample(
            $pdo,$user,(int)$self['id'],$_FILES['voice_sample'] ?? [],max(0,(int)($input['duration_ms'] ?? 0)),(string)($input['source_type'] ?? 'upload')
        );
        studio_voice_profile_json(true, ['state'=>studio_voice_profile_state($pdo,$user)]);
    }
    if ($action === 'delete_sample') {
        studio_voice_profile_delete_sample($pdo,$user,max(0,(int)($input['sample_id'] ?? 0)));
        studio_voice_profile_json(true, ['state'=>studio_voice_profile_state($pdo,$user)]);
    }
    if ($action === 'save_privacy') {
        $self = studio_voice_profile_self($pdo,$user);
        $binding = studio_voice_profile_binding($pdo,$user,(int)$self['id']);
        $cloningConsent = !empty($input['cloning_consent']);
        if (!$cloningConsent && $binding['clone_provider_voice_id'] !== '') {
            throw new RuntimeException('Revoke the active voice clone before disabling cloning consent.');
        }
        studio_participants_set_consent($pdo,$user,[
            'participant_id'=>(int)$self['id'],
            'recognition_consent'=>!empty($input['recognition_consent']),
            'cloning_consent'=>$cloningConsent,
            'recognition_scope'=>(string)($input['recognition_scope'] ?? 'private'),
        ]);
        studio_voice_profile_json(true, ['state'=>studio_voice_profile_state($pdo,$user)]);
    }
    if ($action === 'clone_from_sample') studio_voice_profile_json(true, ['state'=>studio_voice_profile_clone($pdo,$user,$input)]);
    if ($action === 'preview_clone') studio_voice_profile_preview($pdo,$user,$input);
    if ($action === 'revoke_clone') studio_voice_profile_json(true, ['state'=>studio_voice_profile_revoke_clone($pdo,$user)]);
    studio_voice_profile_json(false, ['error'=>'Unsupported Voice Profile action.'], 422);
} catch (Throwable $e) {
    $message = $e instanceof RuntimeException ? $e->getMessage() : 'Voice Profile request failed.';
    studio_voice_profile_json(false, ['error'=>$message], $e instanceof RuntimeException ? 422 : 500);
}
