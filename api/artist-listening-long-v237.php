<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once dirname(__DIR__) . '/includes/artist-listening.php';
require_once dirname(__DIR__) . '/includes/artist-listening-transcript.php';

function artist_listening_v237_json(bool $ok, array $data = [], int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=UTF-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    echo json_encode(['ok'=>$ok,'build'=>STONEFELLOW_ARTIST_LISTENING_TRANSCRIPT] + $data, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * Automatic live analysis still starts at 120 words. An explicit/final forced
 * analysis may persist a non-empty short tail page so the master summary does
 * not silently omit the end of a long transcription.
 */
function artist_listening_v237_analyze_page_request(PDO $pdo, array $user, int $sessionId, int $pageNumber, bool $force): array
{
    if (!$force) {
        return artist_listening_v237_analyze_page($pdo, $user, $sessionId, $pageNumber, false);
    }
    if (!artist_listening_v237_schema_ready()) {
        throw new RuntimeException('Run the Stonefellow database upgrade before saving long-transcript AI analysis.');
    }
    $session = artist_listening_v172_session($pdo, $user, $sessionId);
    $map = artist_listening_transcript_page_map(artist_listening_v172_segments($pdo, $sessionId));
    $pageNumber = max(1, min((int)$map['page_count'], $pageNumber));
    $page = $map['pages'][$pageNumber - 1];
    if ((int)$page['word_count'] >= 120) {
        return artist_listening_v237_analyze_page($pdo, $user, $sessionId, $pageNumber, true);
    }
    if ((int)$page['word_count'] < 1) {
        throw new RuntimeException('This page does not contain transcript words to analyze.');
    }

    $existing = $pdo->prepare('SELECT * FROM artist_transcript_page_analysis_v237 WHERE session_id=? AND page_number=? LIMIT 1');
    $existing->execute([$sessionId, $pageNumber]);
    $cached = $existing->fetch();
    if (is_array($cached) && hash_equals((string)$page['source_hash'], (string)$cached['source_hash'])) {
        $analysis = json_decode((string)$cached['analysis_json'], true);
        return [
            'cached'=>true,'page_number'=>$pageNumber,'source_hash'=>(string)$page['source_hash'],
            'analysis'=>is_array($analysis)?$analysis:[],'provider'=>(string)$cached['provider'],
            'model'=>(string)$cached['model'],'generated_at'=>(string)$cached['generated_at'],
            'short_final_page'=>true,
        ];
    }

    $ai = artist_listening_v237_ai(
        artist_listening_transcript_page_prompt($page, artist_listening_v237_participants($session)),
        $user,
        1400
    );
    $analysis = artist_listening_v237_parse_analysis((string)$ai['answer']);
    $json = json_encode($analysis, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
    if (!is_string($json)) throw new RuntimeException('Could not encode page analysis.');
    $stmt = $pdo->prepare(
        'INSERT INTO artist_transcript_page_analysis_v237 (session_id,page_number,source_hash,source_word_count,start_segment_index,end_segment_index,analysis_json,provider,model,generated_at)
         VALUES (?,?,?,?,?,?,?,?,?,NOW())
         ON DUPLICATE KEY UPDATE source_hash=VALUES(source_hash),source_word_count=VALUES(source_word_count),start_segment_index=VALUES(start_segment_index),end_segment_index=VALUES(end_segment_index),analysis_json=VALUES(analysis_json),provider=VALUES(provider),model=VALUES(model),generated_at=NOW()'
    );
    $stmt->execute([
        $sessionId,$pageNumber,(string)$page['source_hash'],(int)$page['word_count'],
        (int)$page['start_segment_index'],(int)$page['end_segment_index'],$json,
        (string)$ai['provider'],(string)$ai['model'],
    ]);
    return [
        'cached'=>false,'page_number'=>$pageNumber,'source_hash'=>(string)$page['source_hash'],
        'analysis'=>$analysis,'provider'=>(string)$ai['provider'],'model'=>(string)$ai['model'],
        'generated_at'=>gmdate('c'),'short_final_page'=>true,
    ];
}

$user = current_user();
if (!$user) artist_listening_v237_json(false, ['error'=>'Sign in to use Artist Listening.'], 401);
if (!has_permission('artist_listening.access', $user)) artist_listening_v237_json(false, ['error'=>'Artist Listening permission is required.'], 403);
if (!artist_listening_v172_schema_ready()) artist_listening_v237_json(false, ['error'=>'Artist Listening is not ready.'], 503);
$pdo = db();
if (!$pdo) artist_listening_v237_json(false, ['error'=>'Database unavailable.'], 503);

$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
$input = [];
if ($method === 'POST') {
    $input = json_decode((string)file_get_contents('php://input'), true);
    if (!is_array($input)) $input = $_POST;
    if (!hash_equals(csrf_token(), (string)($input['csrf_token'] ?? ''))) artist_listening_v237_json(false, ['error'=>'Session expired. Refresh and try again.'], 419);
}
$action = trim((string)($input['action'] ?? $_GET['action'] ?? 'library'));
$sessionId = max(0, (int)($input['session_id'] ?? $_GET['session_id'] ?? 0));

try {
    if ($method === 'GET' && $action === 'library') {
        artist_listening_v237_json(true, artist_listening_v237_library($pdo, $user) + ['schema_ready'=>artist_listening_v237_schema_ready()]);
    }
    if ($method === 'GET' && $action === 'manifest') {
        artist_listening_v237_json(true, ['manifest'=>artist_listening_v237_manifest($pdo, $user, $sessionId)]);
    }
    if ($method === 'GET' && $action === 'page') {
        $page = max(1, (int)($_GET['page'] ?? 1));
        artist_listening_v237_json(true, artist_listening_transcript_page($pdo, $user, $sessionId, $page) + ['schema_ready'=>artist_listening_v237_schema_ready()]);
    }
    if ($method === 'GET' && $action === 'analysis') {
        $session = artist_listening_v172_session($pdo, $user, $sessionId);
        $map = artist_listening_transcript_page_map(artist_listening_v172_segments($pdo, $sessionId));
        $provider = ai_active_provider();
        artist_listening_v237_json(true, [
            'analysis'=>artist_listening_v237_analysis_status($pdo, $sessionId, $map),
            'schema_ready'=>artist_listening_v237_schema_ready(),
            'provider'=>$provider,
            'provider_ready'=>in_array($provider, ['openai','anthropic'], true) && ai_provider_ready($provider),
            'participants'=>artist_listening_v237_participants($session),
        ]);
    }
    if ($method === 'GET') artist_listening_v237_json(false, ['error'=>'Unknown long-transcript request.'], 404);
    if ($method !== 'POST') artist_listening_v237_json(false, ['error'=>'POST is required.'], 405);

    if ($action === 'analyze_page') {
        $page = max(1, (int)($input['page'] ?? 1));
        artist_listening_v237_json(true, ['page_analysis'=>artist_listening_v237_analyze_page_request($pdo, $user, $sessionId, $page, !empty($input['force']))]);
    }
    if ($action === 'analyze_master') {
        artist_listening_v237_json(true, artist_listening_v237_analyze_master($pdo, $user, $sessionId, !empty($input['research']), !empty($input['force'])));
    }
    artist_listening_v237_json(false, ['error'=>'Unsupported long-transcript action.'], 422);
} catch (Throwable $error) {
    artist_listening_v237_json(false, ['error'=>ai_v100_safe_exception($error, 'Long transcript request failed.')], $error instanceof RuntimeException ? 422 : 500);
}
