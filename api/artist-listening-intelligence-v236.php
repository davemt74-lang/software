<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once dirname(__DIR__) . '/includes/artist-listening.php';

const STONEFELLOW_ARTIST_LISTENING_INTELLIGENCE_V236 = 'artist-listening-intelligence-v236-20260902';
const STONEFELLOW_ARTIST_LISTENING_AI_MIN_WORDS_V236 = 120;
const STONEFELLOW_ARTIST_LISTENING_AI_DELTA_WORDS_V236 = 60;
const STONEFELLOW_ARTIST_LISTENING_AI_MIN_INTERVAL_V236 = 30;
const STONEFELLOW_ARTIST_LISTENING_RESEARCH_DELTA_V236 = 180;

function artist_listening_v236_json(bool $ok, array $data = [], int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=UTF-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    echo json_encode(['ok'=>$ok,'build'=>STONEFELLOW_ARTIST_LISTENING_INTELLIGENCE_V236] + $data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function artist_listening_v236_words(string $text): int
{
    $text = trim(preg_replace('/\s+/u', ' ', $text) ?? '');
    if ($text === '') return 0;
    $parts = preg_split('/\s+/u', $text, -1, PREG_SPLIT_NO_EMPTY);
    return is_array($parts) ? count($parts) : 0;
}

function artist_listening_v236_metadata(array $session): array
{
    $decoded = json_decode((string)($session['metadata_json'] ?? ''), true);
    return is_array($decoded) ? $decoded : [];
}

function artist_listening_v236_participants(array $metadata): array
{
    $raw = is_array($metadata['speaker_names_v236'] ?? null) ? $metadata['speaker_names_v236'] : [];
    $out = [];
    for ($index = 1; $index <= 4; $index++) {
        $key = 'Speaker ' . $index;
        $name = trim(preg_replace('/\s+/u', ' ', (string)($raw[$key] ?? '')) ?? '');
        $out[$key] = $name !== '' ? mb_strimwidth($name, 0, 80, '') : $key;
    }
    return $out;
}

function artist_listening_v236_transcript(array $segments, array $participants): array
{
    $lines = [];
    $plain = [];
    foreach ($segments as $segment) {
        if ((string)($segment['segment_type'] ?? '') !== 'transcript') continue;
        $text = trim((string)($segment['transcript_text'] ?? ''));
        if ($text === '') continue;
        $speaker = trim((string)($segment['speaker_label'] ?? 'Speaker 1')) ?: 'Speaker 1';
        $display = (string)($participants[$speaker] ?? $speaker);
        $lines[] = $display . ': ' . $text;
        $plain[] = $text;
    }
    return [
        'text'=>implode("\n", $lines),
        'plain'=>implode(' ', $plain),
        'word_count'=>artist_listening_v236_words(implode(' ', $plain)),
    ];
}

function artist_listening_v236_clean_list(mixed $value, int $limit = 8, int $chars = 500): array
{
    if (!is_array($value)) return [];
    $out = [];
    foreach ($value as $item) {
        if (is_array($item)) $item = $item['text'] ?? $item['item'] ?? $item['query'] ?? '';
        $text = trim(preg_replace('/\s+/u', ' ', (string)$item) ?? '');
        if ($text === '') continue;
        $out[] = mb_strimwidth($text, 0, $chars, '…');
        if (count($out) >= $limit) break;
    }
    return $out;
}

function artist_listening_v236_parse_json(string $answer): array
{
    $answer = trim($answer);
    $decoded = json_decode($answer, true);
    if (!is_array($decoded)) {
        $answer = preg_replace('/^```(?:json)?\s*|\s*```$/i', '', $answer) ?? $answer;
        $decoded = json_decode(trim($answer), true);
    }
    if (!is_array($decoded)) {
        $first = strpos($answer, '{');
        $last = strrpos($answer, '}');
        if ($first !== false && $last !== false && $last > $first) $decoded = json_decode(substr($answer, $first, $last - $first + 1), true);
    }
    if (!is_array($decoded)) {
        return [
            'summary'=>mb_strimwidth($answer, 0, 2400, '…'),
            'key_points'=>[],
            'decisions'=>[],
            'action_items'=>[],
            'open_questions'=>[],
            'participant_notes'=>[],
            'research_queries'=>[],
        ];
    }
    return [
        'summary'=>mb_strimwidth(trim((string)($decoded['summary'] ?? '')), 0, 2400, '…'),
        'key_points'=>artist_listening_v236_clean_list($decoded['key_points'] ?? [], 8, 520),
        'decisions'=>artist_listening_v236_clean_list($decoded['decisions'] ?? [], 6, 520),
        'action_items'=>artist_listening_v236_clean_list($decoded['action_items'] ?? [], 8, 520),
        'open_questions'=>artist_listening_v236_clean_list($decoded['open_questions'] ?? [], 6, 520),
        'participant_notes'=>artist_listening_v236_clean_list($decoded['participant_notes'] ?? [], 8, 520),
        'research_queries'=>artist_listening_v236_clean_list($decoded['research_queries'] ?? [], 3, 240),
    ];
}

function artist_listening_v236_extract_openai_text(array $decoded): string
{
    if (is_string($decoded['output_text'] ?? null)) return trim($decoded['output_text']);
    $parts = [];
    foreach (($decoded['output'] ?? []) as $item) {
        if (!is_array($item)) continue;
        foreach (($item['content'] ?? []) as $content) {
            if (is_array($content) && ($content['type'] ?? '') === 'output_text' && is_string($content['text'] ?? null)) $parts[] = $content['text'];
        }
    }
    return trim(implode("\n", $parts));
}

function artist_listening_v236_summary_ai(string $prompt, array $user): array
{
    $provider = ai_active_provider();
    if (!in_array($provider, ['openai','anthropic'], true) || !ai_provider_ready($provider)) {
        return ['ok'=>false,'provider'=>$provider,'model'=>'','error'=>'Enable and configure OpenAI or Claude before using live transcript analysis.'];
    }
    try {
        ai_v100_rate_limit('chat', $user);
        $model = ai_provider_model($provider);
        $started = microtime(true);
        if ($provider === 'openai') {
            $key = ai_provider_api_key('openai');
            $result = ai_curl_json(
                'https://api.openai.com/v1/responses',
                ['Authorization: Bearer ' . $key, 'Content-Type: application/json'],
                ['model'=>$model,'input'=>$prompt,'max_output_tokens'=>1600],
                45
            );
            if (empty($result['ok']) || !is_array($result['data'] ?? null)) {
                return ['ok'=>false,'provider'=>$provider,'model'=>$model,'error'=>(string)($result['error'] ?? 'OpenAI transcript analysis was unavailable.')];
            }
            $answer = artist_listening_v236_extract_openai_text($result['data']);
            $usage = ai_v100_usage('openai', $result['data']);
        } else {
            $key = ai_provider_api_key('anthropic');
            $result = ai_curl_json(
                'https://api.anthropic.com/v1/messages',
                ['x-api-key: ' . $key, 'anthropic-version: 2023-06-01', 'Content-Type: application/json'],
                ['model'=>$model,'max_tokens'=>1600,'messages'=>[['role'=>'user','content'=>$prompt]]],
                45
            );
            if (empty($result['ok']) || !is_array($result['data'] ?? null)) {
                return ['ok'=>false,'provider'=>$provider,'model'=>$model,'error'=>(string)($result['error'] ?? 'Claude transcript analysis was unavailable.')];
            }
            $parts = [];
            foreach (($result['data']['content'] ?? []) as $block) {
                if (is_array($block) && ($block['type'] ?? '') === 'text' && is_string($block['text'] ?? null)) $parts[] = $block['text'];
            }
            $answer = trim(implode("\n", $parts));
            $usage = ai_v100_usage('anthropic', $result['data']);
        }
        ai_v100_telemetry([
            'scope'=>'chat','service'=>'artist-listening-v236','user_id'=>(int)($user['id'] ?? 0),
            'provider'=>$provider,'model'=>$model,'status'=>$answer !== '' ? 'success' : 'empty',
            'duration_ms'=>(int)round((microtime(true)-$started)*1000),'input_chars'=>mb_strlen($prompt),
            'output_chars'=>mb_strlen($answer),'complexity'=>'live-summary'
        ] + $usage);
        if ($answer === '') return ['ok'=>false,'provider'=>$provider,'model'=>$model,'error'=>'The AI provider returned an empty transcript analysis.'];
        return ['ok'=>true,'provider'=>$provider,'model'=>$model,'answer'=>$answer,'usage'=>$usage];
    } catch (Throwable $error) {
        return ['ok'=>false,'provider'=>$provider,'model'=>ai_provider_model($provider),'error'=>ai_v100_safe_exception($error, 'Live transcript analysis was unavailable.')];
    }
}

function artist_listening_v236_sources_openai(array $decoded): array
{
    $sources = [];
    foreach (($decoded['output'] ?? []) as $item) {
        if (!is_array($item)) continue;
        foreach (($item['content'] ?? []) as $content) {
            if (!is_array($content)) continue;
            foreach (($content['annotations'] ?? []) as $annotation) {
                if (!is_array($annotation) || ($annotation['type'] ?? '') !== 'url_citation') continue;
                $citation = is_array($annotation['url_citation'] ?? null) ? $annotation['url_citation'] : $annotation;
                $url = trim((string)($citation['url'] ?? ''));
                if ($url === '' || !preg_match('#^https://#i', $url)) continue;
                $sources[$url] = ['url'=>$url,'title'=>mb_strimwidth(trim((string)($citation['title'] ?? $url)),0,220,'…')];
            }
        }
    }
    return array_slice(array_values($sources), 0, 8);
}

function artist_listening_v236_sources_anthropic(array $decoded): array
{
    $sources = [];
    foreach (($decoded['content'] ?? []) as $block) {
        if (!is_array($block)) continue;
        foreach (($block['citations'] ?? []) as $citation) {
            if (!is_array($citation)) continue;
            $url = trim((string)($citation['url'] ?? ''));
            if ($url === '' || !preg_match('#^https://#i', $url)) continue;
            $sources[$url] = ['url'=>$url,'title'=>mb_strimwidth(trim((string)($citation['title'] ?? $url)),0,220,'…')];
        }
        if (($block['type'] ?? '') === 'web_search_tool_result') {
            foreach (($block['content'] ?? []) as $result) {
                if (!is_array($result) || ($result['type'] ?? '') !== 'web_search_result') continue;
                $url = trim((string)($result['url'] ?? ''));
                if ($url === '' || !preg_match('#^https://#i', $url)) continue;
                $sources[$url] = ['url'=>$url,'title'=>mb_strimwidth(trim((string)($result['title'] ?? $url)),0,220,'…')];
            }
        }
    }
    return array_slice(array_values($sources), 0, 8);
}

function artist_listening_v236_web_research(array $queries, array $user): array
{
    $queries = artist_listening_v236_clean_list($queries, 2, 220);
    if (!$queries) return ['text'=>'','sources'=>[],'provider'=>'','model'=>'','error'=>''];
    $provider = ai_active_provider();
    if (!in_array($provider, ['openai','anthropic'], true) || !ai_provider_ready($provider)) {
        return ['text'=>'','sources'=>[],'provider'=>$provider,'model'=>'','error'=>'Web research requires an enabled OpenAI or Claude provider.'];
    }
    $model = ai_provider_model($provider);
    $prompt = "Research the following items that were flagged as important in a live meeting/transcript analysis. Use current web sources only where useful. Keep the result concise and factual. Do not infer facts that sources do not establish.\n\nTOPICS:\n- " . implode("\n- ", $queries);
    try {
        if ($provider === 'openai') {
            $key = ai_provider_api_key('openai');
            $result = ai_curl_json(
                'https://api.openai.com/v1/responses',
                ['Authorization: Bearer ' . $key, 'Content-Type: application/json'],
                ['model'=>$model,'tools'=>[['type'=>'web_search']],'tool_choice'=>'auto','input'=>$prompt,'max_output_tokens'=>1200],
                55
            );
            if (empty($result['ok']) || !is_array($result['data'] ?? null)) {
                return ['text'=>'','sources'=>[],'provider'=>$provider,'model'=>$model,'error'=>(string)($result['error'] ?? 'Web research was unavailable.')];
            }
            return [
                'text'=>mb_strimwidth(artist_listening_v236_extract_openai_text($result['data']),0,5000,'…'),
                'sources'=>artist_listening_v236_sources_openai($result['data']),
                'provider'=>$provider,
                'model'=>$model,
                'error'=>'',
            ];
        }
        $key = ai_provider_api_key('anthropic');
        $result = ai_curl_json(
            'https://api.anthropic.com/v1/messages',
            ['x-api-key: ' . $key, 'anthropic-version: 2023-06-01', 'Content-Type: application/json'],
            [
                'model'=>$model,
                'max_tokens'=>1200,
                'messages'=>[['role'=>'user','content'=>$prompt]],
                'tools'=>[['type'=>'web_search_20260318','name'=>'web_search','max_uses'=>3,'allowed_callers'=>['direct']]],
            ],
            55
        );
        if (empty($result['ok']) || !is_array($result['data'] ?? null)) {
            return ['text'=>'','sources'=>[],'provider'=>$provider,'model'=>$model,'error'=>(string)($result['error'] ?? 'Web research was unavailable.')];
        }
        $parts = [];
        foreach (($result['data']['content'] ?? []) as $block) {
            if (is_array($block) && ($block['type'] ?? '') === 'text' && is_string($block['text'] ?? null)) $parts[] = $block['text'];
        }
        return [
            'text'=>mb_strimwidth(trim(implode("\n", $parts)),0,5000,'…'),
            'sources'=>artist_listening_v236_sources_anthropic($result['data']),
            'provider'=>$provider,
            'model'=>$model,
            'error'=>'',
        ];
    } catch (Throwable $error) {
        return ['text'=>'','sources'=>[],'provider'=>$provider,'model'=>$model,'error'=>ai_v100_safe_exception($error, 'Web research was unavailable.')];
    }
}

function artist_listening_v236_save_metadata(PDO $pdo, array $user, int $sessionId, array $metadata): void
{
    $json = json_encode($metadata, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if (!is_string($json)) throw new RuntimeException('Could not encode Artist Listening intelligence metadata.');
    $stmt = $pdo->prepare('UPDATE artist_transcript_sessions_v172 SET metadata_json=?,last_activity_at=NOW() WHERE id=? AND created_by_user_id=?');
    $stmt->execute([$json, $sessionId, (int)$user['id']]);
}

function artist_listening_v236_checkpoint(PDO $pdo, array $user, int $sessionId, array $segments): array
{
    if ($sessionId < 1 || !$segments) throw new RuntimeException('Transcript checkpoint segments are required.');
    if (count($segments) > 50) throw new RuntimeException('Send no more than 50 checkpoint segments at once.');

    $pdo->beginTransaction();
    try {
        $session = artist_listening_v172_session($pdo, $user, $sessionId, true);
        if ((string)($session['status'] ?? '') === 'discarded') throw new RuntimeException('Restore this transcript before recovering missing text.');

        $maxStmt = $pdo->prepare('SELECT COALESCE(MAX(segment_index),-1) FROM artist_transcript_segments_v172 WHERE session_id=? FOR UPDATE');
        $maxStmt->execute([$sessionId]);
        $nextIndex = max(0, (int)$maxStmt->fetchColumn() + 1);
        $exists = $pdo->prepare('SELECT 1 FROM artist_transcript_segments_v172 WHERE session_id=? AND client_segment_key=? LIMIT 1');
        $insert = $pdo->prepare(
            'INSERT INTO artist_transcript_segments_v172
             (session_id,client_segment_key,segment_index,segment_type,speaker_label,transcript_text,started_ms,ended_ms,confidence)
             VALUES (?,?,?,?,?,?,?,?,?)'
        );
        $persisted = [];
        $accepted = 0;
        foreach ($segments as $segment) {
            if (!is_array($segment)) continue;
            $key = strtolower(trim((string)($segment['key'] ?? $segment['client_segment_key'] ?? '')));
            if (!preg_match('/^[a-z0-9-]{16,64}$/', $key)) continue;
            $text = trim((string)($segment['text'] ?? $segment['transcript_text'] ?? ''));
            if ($text === '') continue;
            $exists->execute([$sessionId, $key]);
            if ($exists->fetchColumn()) {
                $persisted[] = $key;
                continue;
            }
            $type = strtolower(trim((string)($segment['type'] ?? $segment['segment_type'] ?? 'transcript')));
            if (!in_array($type, ['transcript','marker','note'], true)) $type = 'transcript';
            $speaker = trim((string)($segment['speaker'] ?? $segment['speaker_label'] ?? 'Speaker 1'));
            $speaker = $speaker !== '' ? mb_strimwidth($speaker, 0, 80, '') : 'Speaker 1';
            $text = mb_strimwidth($text, 0, 8000, '');
            $started = max(0, (int)($segment['started_ms'] ?? 0));
            $ended = max($started, (int)($segment['ended_ms'] ?? $started));
            $confidence = isset($segment['confidence']) && is_numeric($segment['confidence'])
                ? max(0.0, min(1.0, (float)$segment['confidence']))
                : null;
            $insert->execute([$sessionId, $key, $nextIndex++, $type, $speaker, $text, $started, $ended, $confidence]);
            $accepted += $insert->rowCount();
            $persisted[] = $key;
        }
        if ($persisted) {
            $pdo->prepare('UPDATE artist_transcript_sessions_v172 SET last_activity_at=NOW() WHERE id=? AND created_by_user_id=?')
                ->execute([$sessionId, (int)$user['id']]);
        }
        $pdo->commit();

        $saved = artist_listening_v172_segments($pdo, $sessionId);
        $participants = artist_listening_v236_participants(artist_listening_v236_metadata(artist_listening_v172_session($pdo, $user, $sessionId)));
        $transcript = artist_listening_v236_transcript($saved, $participants);
        return [
            'persisted_keys'=>array_values(array_unique($persisted)),
            'accepted'=>$accepted,
            'segment_count'=>count($saved),
            'word_count'=>(int)$transcript['word_count'],
        ];
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $error;
    }
}

$user = current_user();
if (!$user) artist_listening_v236_json(false, ['error'=>'Sign in to use Artist Listening.'], 401);
if (!has_permission('artist_listening.access', $user)) artist_listening_v236_json(false, ['error'=>'Artist Listening permission is required.'], 403);
if (!artist_listening_v172_schema_ready()) artist_listening_v236_json(false, ['error'=>'Artist Listening is not ready.'], 503);
$pdo = db();
if (!$pdo) artist_listening_v236_json(false, ['error'=>'Database unavailable.'], 503);

$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
$input = [];
if ($method === 'POST') {
    $input = json_decode((string)file_get_contents('php://input'), true);
    if (!is_array($input)) $input = $_POST;
    if (!hash_equals(csrf_token(), (string)($input['csrf_token'] ?? ''))) artist_listening_v236_json(false, ['error'=>'Session expired. Refresh and try again.'], 419);
}
$action = trim((string)($input['action'] ?? $_GET['action'] ?? 'status'));
$sessionId = max(0, (int)($input['session_id'] ?? $_GET['session_id'] ?? 0));

try {
    $session = artist_listening_v172_session($pdo, $user, $sessionId);
    $metadata = artist_listening_v236_metadata($session);
    $participants = artist_listening_v236_participants($metadata);
    $segments = artist_listening_v172_segments($pdo, $sessionId);
    $transcript = artist_listening_v236_transcript($segments, $participants);
    $current = is_array($metadata['intelligence_v236'] ?? null) ? $metadata['intelligence_v236'] : [];

    if ($method === 'GET' && $action === 'status') {
        $provider = ai_active_provider();
        artist_listening_v236_json(true, [
            'session_id'=>$sessionId,
            'word_count'=>$transcript['word_count'],
            'segment_count'=>count($segments),
            'min_words'=>STONEFELLOW_ARTIST_LISTENING_AI_MIN_WORDS_V236,
            'participants'=>$participants,
            'intelligence'=>$current,
            'provider'=>$provider,
            'provider_ready'=>in_array($provider, ['openai','anthropic'], true) && ai_provider_ready($provider),
        ]);
    }

    if ($method !== 'POST') artist_listening_v236_json(false, ['error'=>'POST is required.'], 405);

    if ($action === 'checkpoint') {
        $checkpoint = artist_listening_v236_checkpoint($pdo, $user, $sessionId, is_array($input['segments'] ?? null) ? $input['segments'] : []);
        artist_listening_v236_json(true, $checkpoint);
    }

    if ($action === 'save_participants') {
        $incoming = is_array($input['participants'] ?? null) ? $input['participants'] : [];
        $saved = [];
        for ($index = 1; $index <= 4; $index++) {
            $key = 'Speaker ' . $index;
            $name = trim(preg_replace('/\s+/u', ' ', (string)($incoming[$key] ?? '')) ?? '');
            if ($name !== '' && strcasecmp($name, $key) !== 0) $saved[$key] = mb_strimwidth($name, 0, 80, '');
        }
        $metadata['speaker_names_v236'] = $saved;
        artist_listening_v236_save_metadata($pdo, $user, $sessionId, $metadata);
        artist_listening_v236_json(true, ['participants'=>artist_listening_v236_participants($metadata)]);
    }

    if ($action !== 'analyze') artist_listening_v236_json(false, ['error'=>'Unsupported Artist Listening intelligence action.'], 422);

    $wordCount = (int)$transcript['word_count'];
    if ($wordCount < STONEFELLOW_ARTIST_LISTENING_AI_MIN_WORDS_V236) {
        artist_listening_v236_json(true, [
            'skipped'=>true,
            'reason'=>'minimum_words',
            'word_count'=>$wordCount,
            'min_words'=>STONEFELLOW_ARTIST_LISTENING_AI_MIN_WORDS_V236,
            'participants'=>$participants,
            'intelligence'=>$current,
        ]);
    }

    $lastWords = max(0, (int)($current['word_count'] ?? 0));
    $lastGenerated = strtotime((string)($current['generated_at'] ?? '')) ?: 0;
    $force = !empty($input['force']);
    if (!$force && $current && ($wordCount - $lastWords) < STONEFELLOW_ARTIST_LISTENING_AI_DELTA_WORDS_V236 && (time() - $lastGenerated) < STONEFELLOW_ARTIST_LISTENING_AI_MIN_INTERVAL_V236) {
        artist_listening_v236_json(true, [
            'skipped'=>true,
            'reason'=>'cadence',
            'word_count'=>$wordCount,
            'min_words'=>STONEFELLOW_ARTIST_LISTENING_AI_MIN_WORDS_V236,
            'participants'=>$participants,
            'intelligence'=>$current,
        ]);
    }

    $previous = is_array($current['analysis'] ?? null) ? $current['analysis'] : [];
    $contextText = mb_strimwidth((string)$transcript['text'], 0, 60000, '…');
    $query = "You are the real-time intelligence layer for a live private transcript. Analyze only what the participants actually said. Do not invent decisions, identities, facts, or commitments. Keep the rolling summary concise and useful while preserving important changes from earlier context. Identify claims or topics that would materially benefit from current web verification in research_queries. If nothing needs current research, return an empty research_queries array.\n\nReturn ONLY valid JSON with exactly these keys: summary (string), key_points (array of strings), decisions (array), action_items (array), open_questions (array), participant_notes (array), research_queries (array, maximum 3). Participant notes may describe what a labeled participant contributed, but must never guess a real person's identity.\n\nPARTICIPANT LABELS:\n" . json_encode($participants, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE) . "\n\nPREVIOUS ANALYSIS:\n" . json_encode($previous, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE) . "\n\nLIVE TRANSCRIPT:\n" . $contextText;
    $result = artist_listening_v236_summary_ai($query, $user);
    if (empty($result['ok'])) {
        artist_listening_v236_json(false, ['error'=>(string)($result['error'] ?? 'AI analysis is unavailable.'),'provider'=>(string)($result['provider'] ?? ai_active_provider())], 503);
    }
    $analysis = artist_listening_v236_parse_json((string)($result['answer'] ?? ''));
    $research = is_array($current['research'] ?? null) ? $current['research'] : [];
    $researchWords = max(0, (int)($current['research_word_count'] ?? 0));
    $shouldResearch = !empty($analysis['research_queries']) && ($force || !$research || ($wordCount - $researchWords) >= STONEFELLOW_ARTIST_LISTENING_RESEARCH_DELTA_V236);
    if ($shouldResearch) {
        $research = artist_listening_v236_web_research($analysis['research_queries'], $user);
        $researchWords = $wordCount;
    }

    $current = [
        'word_count'=>$wordCount,
        'generated_at'=>gmdate('c'),
        'provider'=>(string)($result['provider'] ?? ai_active_provider()),
        'model'=>(string)($result['model'] ?? ''),
        'analysis'=>$analysis,
        'research'=>$research,
        'research_word_count'=>$researchWords,
    ];
    $metadata['intelligence_v236'] = $current;
    artist_listening_v236_save_metadata($pdo, $user, $sessionId, $metadata);

    artist_listening_v236_json(true, [
        'skipped'=>false,
        'session_id'=>$sessionId,
        'word_count'=>$wordCount,
        'min_words'=>STONEFELLOW_ARTIST_LISTENING_AI_MIN_WORDS_V236,
        'participants'=>$participants,
        'intelligence'=>$current,
    ]);
} catch (Throwable $error) {
    artist_listening_v236_json(false, ['error'=>ai_v100_safe_exception($error, 'Artist Listening intelligence could not complete that request.')], $error instanceof RuntimeException ? 422 : 500);
}
