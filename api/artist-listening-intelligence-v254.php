<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once dirname(__DIR__) . '/includes/artist-listening.php';
require_once dirname(__DIR__) . '/includes/artist-listening-transcript.php';

const STONEFELLOW_ARTIST_LISTENING_INTELLIGENCE_V254 = 'artist-listening-intelligence-v254-app-tabs-20260903';

function artist_listening_v254_json(bool $ok, array $data = [], int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=UTF-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    echo json_encode(['ok'=>$ok,'build'=>STONEFELLOW_ARTIST_LISTENING_INTELLIGENCE_V254] + $data, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
    exit;
}

function artist_listening_v254_clean(string $text, int $max = 700): string
{
    return mb_strimwidth(trim(preg_replace('/\s+/u', ' ', $text) ?? $text), 0, $max, '…');
}

function artist_listening_v254_list(mixed $value, int $limit = 12, int $max = 500): array
{
    if (!is_array($value)) return [];
    $out = [];
    foreach ($value as $item) {
        if (is_array($item)) $item = $item['text'] ?? $item['item'] ?? $item['query'] ?? '';
        $text = artist_listening_v254_clean((string)$item, $max);
        if ($text === '') continue;
        $out[mb_strtolower($text)] = $text;
        if (count($out) >= $limit) break;
    }
    return array_values($out);
}

function artist_listening_v254_apps(mixed $value): array
{
    $allowed = ['basic','stats','actions','responses','decisions','moments','studio','knowledge'];
    if (!is_array($value)) return ['basic'];
    $out = [];
    foreach ($value as $item) {
        $id = strtolower(trim((string)$item));
        if ($id !== '' && in_array($id, $allowed, true) && !in_array($id, $out, true)) $out[] = $id;
    }
    return $out ?: ['basic'];
}

function artist_listening_v254_tags(array $session): array
{
    $meta = json_decode((string)($session['metadata_json'] ?? ''), true);
    $raw = is_array($meta) ? ($meta['tags'] ?? []) : [];
    if (!is_array($raw)) $raw = preg_split('/[,\n]+/u', (string)$raw) ?: [];
    return artist_listening_v254_list($raw, 12, 50);
}

function artist_listening_v254_permissions(array $user): array
{
    $account = has_permission('account.access', $user);
    $personal = $account && function_exists('personal_knowledge_available') && personal_knowledge_available($user);
    return [
        'agent_brain_read'=>$account && agent_brain_schema_ready() && function_exists('agent_brain_v99_context'),
        'agent_brain_write'=>$account && agent_brain_schema_ready() && function_exists('agent_brain_v122_upsert_system_memory'),
        'personal_knowledge_read'=>$personal,
        'personal_knowledge_write'=>$personal && function_exists('personal_knowledge_store'),
        'shared_knowledge_read'=>has_permission('knowledge.access', $user) && table_exists('knowledge_items'),
    ];
}

function artist_listening_v254_terms(array $tags, array $pages): array
{
    $terms = $tags;
    foreach ($pages as $page) {
        $analysis = is_array($page['analysis'] ?? null) ? $page['analysis'] : [];
        $terms = array_merge($terms,
            artist_listening_v254_list($analysis['research_queries'] ?? [], 4, 140),
            array_slice(artist_listening_v254_list($analysis['key_points'] ?? [], 6, 160), 0, 3)
        );
    }
    return artist_listening_v254_list($terms, 16, 160);
}

function artist_listening_v254_agent_context(array $user, string $query): array
{
    if (!has_permission('account.access', $user) || !agent_brain_schema_ready() || !function_exists('agent_brain_v99_context')) return [];
    try { $rows = agent_brain_v99_context($user, $query, 6); } catch (Throwable $e) { return []; }
    $out = [];
    foreach ($rows as $row) {
        if (!is_array($row) || trim((string)($row['text'] ?? '')) === '') continue;
        $out[] = [
            'source'=>(string)($row['source'] ?? 'agent-brain'),
            'title'=>artist_listening_v254_clean((string)($row['title'] ?? 'Agent Brain context'), 160),
            'text'=>mb_strimwidth(trim((string)$row['text']), 0, 1400, '…'),
        ];
    }
    return array_slice($out, 0, 6);
}

function artist_listening_v254_knowledge_context(array $user, string $query): array
{
    if (!function_exists('search_knowledge')) return [];
    try { $rows = search_knowledge($query, $user, 10); } catch (Throwable $e) { return []; }
    $out = []; $seen = [];
    foreach ($rows as $row) {
        if (!is_array($row)) continue;
        $id = (int)($row['id'] ?? 0);
        $text = trim((string)($row['chunk_text'] ?? $row['description'] ?? ''));
        if ($id < 1 || isset($seen[$id]) || $text === '') continue;
        $seen[$id] = true;
        $scope = (string)($row['knowledge_scope'] ?? 'shared');
        $out[] = [
            'id'=>$id,
            'source'=>$scope === 'personal' ? 'personal-knowledge' : 'shared-knowledge',
            'title'=>artist_listening_v254_clean((string)($row['title'] ?? 'Knowledge item'), 180),
            'text'=>mb_strimwidth($text, 0, 1400, '…'),
        ];
        if (count($out) >= 8) break;
    }
    return $out;
}

function artist_listening_v254_duration_label(int $ms): string
{
    $seconds = max(0, (int)round($ms / 1000));
    $hours = intdiv($seconds, 3600);
    $minutes = intdiv($seconds % 3600, 60);
    $secs = $seconds % 60;
    return $hours > 0
        ? sprintf('%d:%02d:%02d', $hours, $minutes, $secs)
        : sprintf('%d:%02d', $minutes, $secs);
}

function artist_listening_v254_stats(array $segments, array $session, array $map): array
{
    $speakers = [];
    $turns = 0;
    $questions = 0;
    $notes = 0;
    $markers = 0;
    $other = 0;
    $maxEnded = 0;

    foreach ($segments as $row) {
        if (!is_array($row)) continue;
        $type = strtolower(trim((string)($row['segment_type'] ?? 'transcript'))) ?: 'transcript';
        $started = max(0, (int)($row['started_ms'] ?? 0));
        $ended = max($started, (int)($row['ended_ms'] ?? 0));
        $maxEnded = max($maxEnded, $ended);

        if ($type === 'note') { $notes++; continue; }
        if ($type === 'marker') { $markers++; continue; }
        if ($type !== 'transcript') { $other++; continue; }

        $text = trim((string)($row['transcript_text'] ?? ''));
        $label = artist_listening_v254_clean((string)($row['speaker_label'] ?? 'Speaker 1'), 80) ?: 'Speaker 1';
        $words = $text === '' ? 0 : count(preg_split('/\s+/u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: []);
        $turns++;
        $questions += preg_match_all('/\?/u', $text) ?: 0;
        if (!isset($speakers[$label])) $speakers[$label] = ['label'=>$label,'words'=>0,'turns'=>0,'duration_ms'=>0];
        $speakers[$label]['words'] += $words;
        $speakers[$label]['turns']++;
        $speakers[$label]['duration_ms'] += max(0, $ended - $started);
    }

    $totalWords = max(0, (int)($map['total_words'] ?? 0));
    $durationMs = max((int)($session['duration_ms'] ?? 0), $maxEnded);
    $speakerRows = array_values($speakers);
    usort($speakerRows, fn(array $a, array $b): int => ($b['words'] <=> $a['words']) ?: strcmp((string)$a['label'], (string)$b['label']));
    foreach ($speakerRows as &$row) {
        $row['word_share'] = $totalWords > 0 ? round(((int)$row['words'] / $totalWords) * 100, 1) : 0.0;
        $row['duration_label'] = artist_listening_v254_duration_label((int)$row['duration_ms']);
    }
    unset($row);

    $minutes = $durationMs > 0 ? $durationMs / 60000 : 0.0;
    return [
        'total_words'=>$totalWords,
        'duration_ms'=>$durationMs,
        'duration_label'=>artist_listening_v254_duration_label($durationMs),
        'transcript_turns'=>$turns,
        'speaker_count'=>count($speakerRows),
        'question_count'=>$questions,
        'note_count'=>$notes,
        'marker_count'=>$markers,
        'other_segment_count'=>$other,
        'words_per_minute'=>$minutes > 0 ? (int)round($totalWords / $minutes) : 0,
        'speakers'=>$speakerRows,
    ];
}

function artist_listening_v254_decode(string $answer): array
{
    $raw = trim($answer);
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        $raw = preg_replace('/^```(?:json)?\s*|\s*```$/i', '', $raw) ?? $raw;
        $data = json_decode(trim($raw), true);
    }
    if (!is_array($data)) {
        $first = strpos($raw, '{'); $last = strrpos($raw, '}');
        if ($first !== false && $last !== false && $last > $first) $data = json_decode(substr($raw, $first, $last-$first+1), true);
    }
    if (!is_array($data)) $data = ['summary'=>$answer,'logical_report'=>$answer];
    return [
        'summary'=>artist_listening_v254_clean((string)($data['summary'] ?? ''), 5000),
        'logical_report'=>mb_strimwidth(trim((string)($data['logical_report'] ?? $data['report'] ?? '')), 0, 7000, '…'),
        'agreements'=>artist_listening_v254_list($data['agreements'] ?? [], 12, 700),
        'conflicts'=>artist_listening_v254_list($data['conflicts'] ?? [], 12, 700),
        'changes_from_prior'=>artist_listening_v254_list($data['changes_from_prior'] ?? [], 12, 700),
        'key_points'=>artist_listening_v254_list($data['key_points'] ?? [], 12, 700),
        'decisions'=>artist_listening_v254_list($data['decisions'] ?? [], 10, 700),
        'commitments'=>artist_listening_v254_list($data['commitments'] ?? [], 12, 700),
        'action_items'=>artist_listening_v254_list($data['action_items'] ?? [], 12, 700),
        'suggested_responses'=>artist_listening_v254_list($data['suggested_responses'] ?? [], 12, 900),
        'key_moments'=>artist_listening_v254_list($data['key_moments'] ?? [], 12, 900),
        'studio_notes'=>artist_listening_v254_list($data['studio_notes'] ?? [], 16, 900),
        'knowledge_candidates'=>artist_listening_v254_list($data['knowledge_candidates'] ?? [], 16, 900),
        'open_questions'=>artist_listening_v254_list($data['open_questions'] ?? [], 10, 700),
        'participant_notes'=>artist_listening_v254_list($data['participant_notes'] ?? [], 12, 700),
        'context_gaps'=>artist_listening_v254_list($data['context_gaps'] ?? [], 10, 700),
        'research_queries'=>artist_listening_v254_list($data['research_queries'] ?? [], 6, 220),
    ];
}

function artist_listening_v254_research_gate(array $existing, array $queries, array $tags, int $words): array
{
    $queries = artist_listening_v254_list($queries, 6, 220);
    $tags = artist_listening_v254_list($tags, 12, 50);
    $nq = array_map('mb_strtolower', $queries); sort($nq);
    $nt = array_map('mb_strtolower', $tags); sort($nt);
    $meta = is_array($existing['meta'] ?? null) ? $existing['meta'] : [];
    $oldTags = is_array($meta['tags'] ?? null) ? array_map('mb_strtolower', $meta['tags']) : []; sort($oldTags);
    $newTags = array_values(array_diff($nt, $oldTags));
    $hash = sha1(implode('|', $nq));
    $oldHash = (string)($meta['query_hash'] ?? '');
    $delta = max(0, $words - (int)($meta['source_word_count'] ?? 0));
    $hasResearch = trim((string)($existing['text'] ?? '')) !== '';
    $due = false; $trigger = 'not_due';
    if ($queries && !$hasResearch) { $due=true; $trigger='initial_topics'; }
    elseif ($queries && $newTags) { $due=true; $trigger='new_tags'; }
    elseif ($queries && $oldHash !== '' && !hash_equals($oldHash, $hash) && $delta >= 250) { $due=true; $trigger='new_topics'; }
    elseif ($queries && $delta >= 600) { $due=true; $trigger='word_block'; }
    return ['due'=>$due,'trigger'=>$trigger,'queries'=>$queries,'tags'=>$tags,'query_hash'=>$hash,'source_word_count'=>$words,'delta_words'=>$delta,'new_tags'=>$newTags];
}

function artist_listening_v254_report_text(array $master, array $session, array $tags): string
{
    $a = is_array($master['analysis'] ?? null) ? $master['analysis'] : [];
    $r = is_array($master['research'] ?? null) ? $master['research'] : [];
    $lines = ['Artist Listening Analysis: ' . ((string)($session['title'] ?? '') ?: ('Transcript #' . (int)$session['id']))];
    if ($tags) $lines[] = 'Tags: ' . implode(', ', $tags);
    foreach (['summary'=>'Summary','logical_report'=>'Logical report'] as $key=>$label) {
        if (trim((string)($a[$key] ?? '')) !== '') $lines[] = $label . ': ' . $a[$key];
    }
    foreach ([
        'agreements'=>'Agreements',
        'conflicts'=>'Conflicts',
        'changes_from_prior'=>'Changes from prior context',
        'decisions'=>'Decisions',
        'commitments'=>'Commitments',
        'action_items'=>'Actions',
        'suggested_responses'=>'Suggested responses',
        'key_moments'=>'Key moments',
        'studio_notes'=>'Studio notes',
        'knowledge_candidates'=>'Knowledge candidates',
        'open_questions'=>'Open questions',
        'context_gaps'=>'Context gaps',
    ] as $key=>$label) {
        $items = artist_listening_v254_list($a[$key] ?? [], 16, 900);
        if ($items) $lines[] = $label . ":\n- " . implode("\n- ", $items);
    }
    $stats = is_array($a['stats'] ?? null) ? $a['stats'] : [];
    if ($stats) {
        $lines[] = 'Stats: ' . implode(' · ', [
            number_format((int)($stats['total_words'] ?? 0)) . ' words',
            (string)($stats['duration_label'] ?? '0:00'),
            number_format((int)($stats['transcript_turns'] ?? 0)) . ' turns',
            number_format((int)($stats['speaker_count'] ?? 0)) . ' speakers',
        ]);
    }
    if (trim((string)($r['text'] ?? '')) !== '') $lines[] = "External research:\n" . $r['text'];
    return mb_strimwidth(implode("\n\n", $lines), 0, 16000, '…');
}

function artist_listening_v254_short_page(PDO $pdo, array $user, int $sessionId, array $page, array $session): void
{
    if ((int)($page['word_count'] ?? 0) < 1) return;
    $ai = artist_listening_v237_ai(artist_listening_transcript_page_prompt($page, artist_listening_v237_participants($session)), $user, 1400);
    $json = json_encode(artist_listening_v237_parse_analysis((string)$ai['answer']), JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
    if (!is_string($json)) return;
    $stmt = $pdo->prepare('INSERT INTO artist_transcript_page_analysis_v237 (session_id,page_number,source_hash,source_word_count,start_segment_index,end_segment_index,analysis_json,provider,model,generated_at) VALUES (?,?,?,?,?,?,?,?,?,NOW()) ON DUPLICATE KEY UPDATE source_hash=VALUES(source_hash),source_word_count=VALUES(source_word_count),start_segment_index=VALUES(start_segment_index),end_segment_index=VALUES(end_segment_index),analysis_json=VALUES(analysis_json),provider=VALUES(provider),model=VALUES(model),generated_at=NOW()');
    $stmt->execute([$sessionId,(int)$page['page_number'],(string)$page['source_hash'],(int)$page['word_count'],(int)$page['start_segment_index'],(int)$page['end_segment_index'],$json,(string)$ai['provider'],(string)$ai['model']]);
}

function artist_listening_v254_module_prompt(array $apps): string
{
    $instructions = [
        'basic'=>'Basic Analysis: produce summary, logical_report, agreements, conflicts, changes_from_prior, key_points, open_questions, and context_gaps.',
        'actions'=>'Suggested Actions: action_items must be concrete next steps grounded in the transcript; include owner or timing only when supported.',
        'responses'=>'Suggested Responses: suggested_responses must be short replies, talking points, or questions the signed-in user could use next; do not invent facts.',
        'decisions'=>'Decisions & Commitments: decisions are concluded choices; commitments are promises or obligations, including owner/timing only when supported.',
        'moments'=>'Key Moments: key_moments should identify the most consequential moments and include a page reference when the page evidence makes that possible.',
        'studio'=>'Studio Notes: studio_notes should extract song, lyric, arrangement, performance, recording, mix, gear, tempo, and production instructions only when relevant.',
        'knowledge'=>'Knowledge Extractor: knowledge_candidates should be durable facts, preferences, decisions, project state, or context changes worth considering for Agent Brain or the personal Knowledge Base.',
    ];
    $selected = [];
    foreach ($apps as $app) if (isset($instructions[$app])) $selected[] = $instructions[$app];
    return implode("\n", $selected);
}

function artist_listening_v254_analyze(PDO $pdo, array $user, int $sessionId, string $mode, bool $researchOn, array $apps): array
{
    $apps = artist_listening_v254_apps($apps);
    $session = artist_listening_v172_session($pdo, $user, $sessionId);
    $segments = artist_listening_v172_segments($pdo, $sessionId);
    $map = artist_listening_transcript_page_map($segments);
    $tags = artist_listening_v254_tags($session);
    $status = artist_listening_v237_analysis_status($pdo, $sessionId, $map);
    $master = is_array($status['master'] ?? null) ? $status['master'] : null;
    $oldAnalysis = is_array($master['analysis'] ?? null) ? $master['analysis'] : [];
    $existingResearch = is_array($master['research'] ?? null) ? $master['research'] : [];
    $words = (int)$map['total_words'];
    $generatedApps = artist_listening_v254_apps($oldAnalysis['apps'] ?? ['basic']);
    $missingApps = array_values(array_diff($apps, $generatedApps));

    if ($mode === 'live' && $master) {
        $oldTags = is_array($existingResearch['meta']['tags'] ?? null) ? array_map('mb_strtolower', $existingResearch['meta']['tags']) : [];
        $newTags = array_map('mb_strtolower', $tags); sort($oldTags); sort($newTags);
        if (!$missingApps && $oldTags === $newTags && $words - (int)($master['word_count'] ?? 0) < 250) {
            return ['skipped'=>true,'reason'=>'block_not_due','master'=>$master,'tags'=>$tags,'permissions'=>artist_listening_v254_permissions($user)];
        }
    }
    if ($mode === 'live' && !$master && $words < 120) {
        return ['skipped'=>true,'reason'=>'minimum_context','master'=>null,'tags'=>$tags,'permissions'=>artist_listening_v254_permissions($user)];
    }

    $stats = artist_listening_v254_stats($segments, $session, $map);
    $aiApps = array_values(array_diff($apps, ['stats']));
    $pages = [];
    $report = $oldAnalysis;
    $provider = (string)($master['provider'] ?? 'stonefellow');
    $model = (string)($master['model'] ?? 'deterministic-stats');
    $brain = [];
    $knowledge = [];

    if ($aiApps) {
        foreach ($map['pages'] as $page) {
            $number = (int)$page['page_number'];
            $saved = $status['pages'][(string)$number] ?? null;
            if (is_array($saved) && !empty($saved['fresh'])) continue;
            if ((int)$page['word_count'] < 1) continue;
            try {
                if ((int)$page['word_count'] >= 120) artist_listening_v237_analyze_page($pdo, $user, $sessionId, $number, true);
                elseif ($mode === 'manual') artist_listening_v254_short_page($pdo, $user, $sessionId, $page, $session);
            } catch (Throwable $e) { if ($mode === 'manual') throw $e; }
            if ($mode === 'live') break;
        }

        $status = artist_listening_v237_analysis_status($pdo, $sessionId, $map);
        foreach ($map['pages'] as $page) {
            $saved = $status['pages'][(string)$page['page_number']] ?? null;
            if (is_array($saved) && !empty($saved['fresh'])) $pages[] = ['page'=>(int)$page['page_number'],'analysis'=>$saved['analysis']];
        }
        if (!$pages) throw new RuntimeException('There is not enough saved transcript analysis to build the selected apps yet.');

        $query = implode(' · ', artist_listening_v254_terms($tags, $pages));
        $brain = artist_listening_v254_agent_context($user, $query);
        $knowledge = artist_listening_v254_knowledge_context($user, $query);
        $modulePrompt = artist_listening_v254_module_prompt($aiApps);
        $prompt = "Build structured results for selected transcription apps from a private live transcript. LIVE TRANSCRIPT page analyses are primary evidence. AGENT BRAIN is this signed-in user's prior private memory and may be stale or subjective. PERSONAL KNOWLEDGE BASE items are private notes owned by this same user. SHARED KNOWLEDGE BASE items are authorized reference material. Compare sources; do not blend them. Never attribute Brain/Knowledge text to the live transcript. research_queries must contain only short public topics suitable for web verification and must never expose private context.\n\nSELECTED APPS:\n" . implode(', ', $aiApps) . "\n\nAPP INSTRUCTIONS:\n" . $modulePrompt . "\n\nReturn ONLY JSON with keys summary, logical_report, agreements, conflicts, changes_from_prior, key_points, decisions, commitments, action_items, suggested_responses, key_moments, studio_notes, knowledge_candidates, open_questions, participant_notes, context_gaps, research_queries. Leave unrequested app-specific arrays empty.\n\nTRANSCRIPT TAGS:\n" . json_encode($tags, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE) . "\n\nLIVE TRANSCRIPT PAGE ANALYSES:\n" . json_encode($pages, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE) . "\n\nAUTHORIZED AGENT BRAIN CONTEXT:\n" . json_encode($brain, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE) . "\n\nAUTHORIZED KNOWLEDGE CONTEXT:\n" . json_encode($knowledge, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
        $ai = artist_listening_v237_ai($prompt, $user, 3200);
        $report = array_replace($report, artist_listening_v254_decode((string)$ai['answer']));
        $provider = (string)$ai['provider'];
        $model = (string)$ai['model'];
    }

    $report['apps'] = array_values(array_unique(array_merge($generatedApps, $apps)));
    if (in_array('stats', $apps, true) || isset($report['stats'])) $report['stats'] = $stats;

    $oldMaster = is_array($status['master'] ?? null) ? $status['master'] : null;
    $oldResearch = is_array($oldMaster['research'] ?? null) ? $oldMaster['research'] : [];
    $gate = artist_listening_v254_research_gate($oldResearch, $report['research_queries'] ?? [], $tags, $words);
    $research = $oldResearch;
    if ($researchOn && $gate['due']) {
        $research = artist_listening_v237_research($gate['queries'], $user);
        $research['meta'] = $gate + ['generated_at'=>gmdate('c')];
    } elseif (!isset($research['meta']) && $research) {
        $research['meta'] = $gate + ['generated_at'=>(string)($oldMaster['generated_at'] ?? '')];
    }

    $analysisJson = json_encode($report, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
    $researchJson = json_encode($research, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
    if (!is_string($analysisJson) || !is_string($researchJson)) throw new RuntimeException('Could not encode the transcript intelligence report.');
    $stmt = $pdo->prepare('INSERT INTO artist_transcript_master_analysis_v237 (session_id,source_hash,source_word_count,page_count,analyzed_page_count,analysis_json,research_json,provider,model,generated_at) VALUES (?,?,?,?,?,?,?,?,?,NOW()) ON DUPLICATE KEY UPDATE source_hash=VALUES(source_hash),source_word_count=VALUES(source_word_count),page_count=VALUES(page_count),analyzed_page_count=VALUES(analyzed_page_count),analysis_json=VALUES(analysis_json),research_json=VALUES(research_json),provider=VALUES(provider),model=VALUES(model),generated_at=NOW()');
    $stmt->execute([$sessionId,(string)$map['source_hash'],$words,(int)$map['page_count'],count($pages),$analysisJson,$researchJson,$provider,$model]);
    $latest = artist_listening_v237_analysis_status($pdo, $sessionId, $map);
    return [
        'skipped'=>false,
        'master'=>$latest['master'] ?? null,
        'tags'=>$tags,
        'apps'=>$apps,
        'research_gate'=>$gate,
        'context'=>['agent_brain_items'=>count($brain),'knowledge_items'=>count($knowledge)],
        'permissions'=>artist_listening_v254_permissions($user),
    ];
}

$user = current_user();
if (!$user) artist_listening_v254_json(false, ['error'=>'Sign in to use Artist Listening.'], 401);
if (!has_permission('artist_listening.access', $user)) artist_listening_v254_json(false, ['error'=>'Artist Listening permission is required.'], 403);
if (!artist_listening_v172_schema_ready() || !artist_listening_v237_schema_ready()) artist_listening_v254_json(false, ['error'=>'Artist Listening intelligence is not ready. Run the transcript upgrades.'], 503);
$pdo = db();
if (!$pdo) artist_listening_v254_json(false, ['error'=>'Database unavailable.'], 503);

$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
$input = [];
if ($method === 'POST') {
    $input = json_decode((string)file_get_contents('php://input'), true);
    if (!is_array($input)) $input = $_POST;
    if (!hash_equals(csrf_token(), (string)($input['csrf_token'] ?? ''))) artist_listening_v254_json(false, ['error'=>'Session expired. Refresh and try again.'], 419);
}
$action = trim((string)($input['action'] ?? $_GET['action'] ?? 'status'));
$sessionId = max(0, (int)($input['session_id'] ?? $_GET['session_id'] ?? 0));

try {
    $session = $sessionId ? artist_listening_v172_session($pdo, $user, $sessionId) : null;
    if ($method === 'GET' && $action === 'status') {
        if (!$session) artist_listening_v254_json(true, ['master'=>null,'tags'=>[],'permissions'=>artist_listening_v254_permissions($user)]);
        $map = artist_listening_transcript_page_map(artist_listening_v172_segments($pdo, $sessionId));
        $status = artist_listening_v237_analysis_status($pdo, $sessionId, $map);
        artist_listening_v254_json(true, ['master'=>$status['master'] ?? null,'tags'=>artist_listening_v254_tags($session),'permissions'=>artist_listening_v254_permissions($user)]);
    }
    if ($method === 'GET') artist_listening_v254_json(false, ['error'=>'Unknown transcript intelligence request.'], 404);
    if ($method !== 'POST') artist_listening_v254_json(false, ['error'=>'POST is required.'], 405);
    if (!$session) artist_listening_v254_json(false, ['error'=>'Choose a transcript first.'], 422);

    if ($action === 'analyze') {
        $mode = strtolower((string)($input['mode'] ?? 'manual'));
        if (!in_array($mode, ['manual','live'], true)) $mode = 'manual';
        $apps = artist_listening_v254_apps($input['apps'] ?? ['basic']);
        artist_listening_v254_json(true, artist_listening_v254_analyze($pdo, $user, $sessionId, $mode, !empty($input['research']), $apps));
    }

    $map = artist_listening_transcript_page_map(artist_listening_v172_segments($pdo, $sessionId));
    $status = artist_listening_v237_analysis_status($pdo, $sessionId, $map);
    $master = is_array($status['master'] ?? null) ? $status['master'] : null;
    if (in_array($action, ['save_brain','save_knowledge'], true) && !$master) throw new RuntimeException('Analyze this transcript before saving the report.');
    $tags = artist_listening_v254_tags($session);
    $text = $master ? artist_listening_v254_report_text($master, $session, $tags) : '';

    if ($action === 'save_brain') {
        $permissions = artist_listening_v254_permissions($user);
        if (!$permissions['agent_brain_write']) throw new RuntimeException('Agent Brain storage is not available for this account.');
        $id = agent_brain_v122_upsert_system_memory($user,'transcript_analysis','artist-listening:' . $sessionId,mb_strimwidth($text,0,8000,'…'),['source'=>'artist-listening-v254','session_id'=>$sessionId,'title'=>(string)($session['title'] ?? ''),'tags'=>$tags,'analysis_generated_at'=>(string)($master['generated_at'] ?? ''),'saved_at'=>gmdate('c')],0.98);
        if ($id < 1) throw new RuntimeException('Could not save the transcript analysis to Agent Brain.');
        artist_listening_v254_json(true, ['saved'=>true,'memory_id'=>$id,'saved_at'=>gmdate('c')]);
    }

    if ($action === 'save_knowledge') {
        $permissions = artist_listening_v254_permissions($user);
        if (!$permissions['personal_knowledge_write']) throw new RuntimeException('Personal Knowledge Base storage is not available for this account.');
        $title = mb_strimwidth('Transcript Analysis · ' . ((string)($session['title'] ?? '') ?: ('Session ' . $sessionId)),0,190,'…');
        $id = personal_knowledge_store($user,'artist-listening-analysis:' . $sessionId,$title,$text,'Personal Artist Listening analysis · session #' . $sessionId);
        artist_listening_v254_json(true, ['saved'=>true,'knowledge_id'=>$id,'scope'=>'personal','published'=>false,'saved_at'=>gmdate('c')]);
    }

    artist_listening_v254_json(false, ['error'=>'Unsupported transcript intelligence action.'], 422);
} catch (Throwable $e) {
    artist_listening_v254_json(false, ['error'=>ai_v100_safe_exception($e, 'Transcript intelligence request failed.')], $e instanceof RuntimeException ? 422 : 500);
}
