<?php
declare(strict_types=1);

const STONEFELLOW_ARTIST_LISTENING_TRANSCRIPT = 'artist-listening-long-v237-20260902';
const STONEFELLOW_ARTIST_LISTENING_PAGE_WORDS_V237 = 2500;

function artist_listening_v237_schema_ready(): bool
{
    return table_exists('artist_transcript_page_analysis_v237')
        && table_exists('artist_transcript_master_analysis_v237');
}

function artist_listening_v237_ensure_schema(): void
{
    $pdo = db();
    if (!$pdo) {
        throw new RuntimeException('Database connection is unavailable.');
    }
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS artist_transcript_page_analysis_v237 (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            session_id BIGINT UNSIGNED NOT NULL,
            page_number INT UNSIGNED NOT NULL,
            source_hash CHAR(64) NOT NULL,
            source_word_count INT UNSIGNED NOT NULL DEFAULT 0,
            start_segment_index INT UNSIGNED NOT NULL DEFAULT 0,
            end_segment_index INT UNSIGNED NOT NULL DEFAULT 0,
            analysis_json LONGTEXT NOT NULL,
            provider VARCHAR(32) NOT NULL DEFAULT '',
            model VARCHAR(160) NOT NULL DEFAULT '',
            generated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_artist_transcript_page_analysis_v237 (session_id,page_number),
            INDEX idx_artist_transcript_page_analysis_hash_v237 (session_id,source_hash),
            CONSTRAINT fk_artist_transcript_page_analysis_v237
              FOREIGN KEY (session_id) REFERENCES artist_transcript_sessions_v172(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS artist_transcript_master_analysis_v237 (
            session_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
            source_hash CHAR(64) NOT NULL,
            source_word_count INT UNSIGNED NOT NULL DEFAULT 0,
            page_count INT UNSIGNED NOT NULL DEFAULT 0,
            analyzed_page_count INT UNSIGNED NOT NULL DEFAULT 0,
            analysis_json LONGTEXT NOT NULL,
            research_json LONGTEXT NULL,
            provider VARCHAR(32) NOT NULL DEFAULT '',
            model VARCHAR(160) NOT NULL DEFAULT '',
            generated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            CONSTRAINT fk_artist_transcript_master_analysis_v237
              FOREIGN KEY (session_id) REFERENCES artist_transcript_sessions_v172(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
}

function artist_listening_v237_words(string $text): int
{
    $text = trim(preg_replace('/\s+/u', ' ', $text) ?? '');
    if ($text === '') return 0;
    $parts = preg_split('/\s+/u', $text, -1, PREG_SPLIT_NO_EMPTY);
    return is_array($parts) ? count($parts) : 0;
}

function artist_listening_v237_clean_text(string $text): string
{
    return trim(preg_replace('/\s+/u', ' ', $text) ?? '');
}

function artist_listening_v237_metadata(array $session): array
{
    $decoded = json_decode((string)($session['metadata_json'] ?? ''), true);
    return is_array($decoded) ? $decoded : [];
}

function artist_listening_v237_participants(array $session): array
{
    $metadata = artist_listening_v237_metadata($session);
    $names = is_array($metadata['speaker_names_v236'] ?? null) ? $metadata['speaker_names_v236'] : [];
    $out = [];
    for ($index = 1; $index <= 4; $index++) {
        $key = 'Speaker ' . $index;
        $name = artist_listening_v237_clean_text((string)($names[$key] ?? ''));
        $out[$key] = $name !== '' ? mb_strimwidth($name, 0, 80, '') : $key;
    }
    return $out;
}

function artist_listening_transcript_page_map(array $segments, int $targetWords = STONEFELLOW_ARTIST_LISTENING_PAGE_WORDS_V237): array
{
    $targetWords = max(500, min(5000, $targetWords));
    $pages = [];
    $page = null;
    $pageNumber = 0;
    $totalWords = 0;

    $finish = static function (?array &$current, array &$target): void {
        if (!$current) return;
        $hashParts = [];
        foreach ($current['segments'] as $segment) {
            $hashParts[] = implode('|', [
                (string)($segment['id'] ?? ''),
                (string)($segment['client_segment_key'] ?? ''),
                (string)($segment['segment_index'] ?? ''),
                (string)($segment['segment_type'] ?? ''),
                (string)($segment['speaker_label'] ?? ''),
                (string)($segment['transcript_text'] ?? ''),
                (string)($segment['updated_at'] ?? ''),
            ]);
        }
        $current['source_hash'] = hash('sha256', implode("\n", $hashParts));
        $current['segment_count'] = count($current['segments']);
        $target[] = $current;
        $current = null;
    };

    foreach ($segments as $segment) {
        if (!is_array($segment)) continue;
        $type = (string)($segment['segment_type'] ?? 'transcript');
        $text = artist_listening_v237_clean_text((string)($segment['transcript_text'] ?? ''));
        $words = $type === 'transcript' ? artist_listening_v237_words($text) : 0;
        if ($words > 0 && $page && (int)$page['word_count'] > 0 && ((int)$page['word_count'] + $words) > $targetWords) {
            $finish($page, $pages);
        }
        if (!$page) {
            $pageNumber++;
            $page = [
                'page_number'=>$pageNumber,
                'word_count'=>0,
                'start_ms'=>max(0, (int)($segment['started_ms'] ?? 0)),
                'end_ms'=>max(0, (int)($segment['ended_ms'] ?? 0)),
                'start_segment_index'=>max(0, (int)($segment['segment_index'] ?? 0)),
                'end_segment_index'=>max(0, (int)($segment['segment_index'] ?? 0)),
                'segments'=>[],
            ];
        }
        $page['segments'][] = $segment;
        $page['word_count'] += $words;
        $page['end_ms'] = max((int)$page['end_ms'], (int)($segment['ended_ms'] ?? $segment['started_ms'] ?? 0));
        $page['end_segment_index'] = max((int)$page['end_segment_index'], (int)($segment['segment_index'] ?? 0));
        $totalWords += $words;
    }
    $finish($page, $pages);
    if (!$pages) {
        $pages[] = [
            'page_number'=>1,'word_count'=>0,'start_ms'=>0,'end_ms'=>0,
            'start_segment_index'=>0,'end_segment_index'=>0,'segments'=>[],
            'source_hash'=>hash('sha256', ''),'segment_count'=>0,
        ];
    }
    $sessionHash = hash('sha256', implode('|', array_map(static fn(array $row): string => (string)$row['source_hash'], $pages)));
    return ['pages'=>$pages,'total_words'=>$totalWords,'page_count'=>count($pages),'source_hash'=>$sessionHash,'target_words'=>$targetWords];
}

function artist_listening_v237_manifest(PDO $pdo, array $user, int $sessionId): array
{
    $session = artist_listening_v172_session($pdo, $user, $sessionId);
    $segments = artist_listening_v172_segments($pdo, $sessionId);
    $map = artist_listening_transcript_page_map($segments);
    $status = artist_listening_v237_analysis_status($pdo, $sessionId, $map);
    $pages = [];
    foreach ($map['pages'] as $page) {
        $number = (int)$page['page_number'];
        $pages[] = [
            'page_number'=>$number,
            'word_count'=>(int)$page['word_count'],
            'start_ms'=>(int)$page['start_ms'],
            'end_ms'=>(int)$page['end_ms'],
            'start_segment_index'=>(int)$page['start_segment_index'],
            'end_segment_index'=>(int)$page['end_segment_index'],
            'segment_count'=>(int)$page['segment_count'],
            'source_hash'=>(string)$page['source_hash'],
            'analysis_status'=>$status['pages'][(string)$number] ?? ['saved'=>false,'fresh'=>false],
        ];
    }
    return [
        'session_id'=>$sessionId,
        'title'=>(string)($session['title'] ?? 'Untitled transcription'),
        'status'=>(string)($session['status'] ?? 'draft'),
        'total_words'=>(int)$map['total_words'],
        'page_count'=>(int)$map['page_count'],
        'target_words'=>(int)$map['target_words'],
        'source_hash'=>(string)$map['source_hash'],
        'pages'=>$pages,
        'participants'=>artist_listening_v237_participants($session),
        'analysis'=>$status,
        'schema_ready'=>artist_listening_v237_schema_ready(),
    ];
}

function artist_listening_transcript_page(PDO $pdo, array $user, int $sessionId, int $pageNumber): array
{
    $session = artist_listening_v172_session($pdo, $user, $sessionId);
    $segments = artist_listening_v172_segments($pdo, $sessionId);
    $map = artist_listening_transcript_page_map($segments);
    $pageNumber = max(1, min((int)$map['page_count'], $pageNumber));
    $page = $map['pages'][$pageNumber - 1];
    $continuous = [];
    foreach ($page['segments'] as $segment) {
        if ((string)($segment['segment_type'] ?? '') === 'transcript') {
            $continuous[] = artist_listening_v237_clean_text((string)($segment['transcript_text'] ?? ''));
        }
    }
    unset($session['metadata_json']);
    $session['segments'] = $page['segments'];
    $session['continuous_text'] = trim(implode(' ', array_filter($continuous)));
    $session['word_count'] = (int)$map['total_words'];
    $session['page_word_count'] = (int)$page['word_count'];
    $session['transcript_paged'] = true;
    $session['transcript_page'] = $pageNumber;
    $session['transcript_pages'] = (int)$map['page_count'];
    $session['v237_source_hash'] = (string)$map['source_hash'];
    return [
        'session'=>$session,
        'page'=>[
            'page_number'=>$pageNumber,
            'page_count'=>(int)$map['page_count'],
            'word_count'=>(int)$page['word_count'],
            'total_words'=>(int)$map['total_words'],
            'start_ms'=>(int)$page['start_ms'],
            'end_ms'=>(int)$page['end_ms'],
            'source_hash'=>(string)$page['source_hash'],
            'segments'=>$page['segments'],
        ],
        'manifest_hash'=>(string)$map['source_hash'],
    ];
}

function artist_listening_v237_tags(mixed $value): array
{
    $raw = is_array($value) ? $value : preg_split('/[,\n]+/u', (string)$value);
    $tags = [];
    foreach ($raw ?: [] as $tag) {
        $tag = artist_listening_v237_clean_text((string)$tag);
        if ($tag === '') continue;
        $key = mb_strtolower($tag);
        if (!isset($tags[$key])) $tags[$key] = mb_strimwidth($tag, 0, 30, '');
        if (count($tags) >= 12) break;
    }
    return array_values($tags);
}

function artist_listening_v237_association(PDO $pdo, array $user, array $metadata): array
{
    $association = is_array($metadata['association'] ?? null) ? $metadata['association'] : [];
    $type = strtolower(trim((string)($association['type'] ?? 'none')));
    $trackId = max(0, (int)($association['track_id'] ?? 0));
    if (!in_array($type, ['song','studio_project'], true) || $trackId < 1 || !artist_listening_v172_track_allowed($pdo, $user, $trackId)) {
        return ['type'=>'none','track_id'=>0,'label'=>'Unassigned'];
    }
    $stmt = $pdo->prepare('SELECT title FROM tracks WHERE id=? LIMIT 1');
    $stmt->execute([$trackId]);
    $title = trim((string)$stmt->fetchColumn()) ?: ('Track #' . $trackId);
    return ['type'=>$type,'track_id'=>$trackId,'label'=>($type === 'studio_project' ? 'Studio · ' : 'Song · ') . $title];
}

function artist_listening_v237_folder(PDO $pdo, array $user, int $folderId): array
{
    if ($folderId < 1 || !table_exists('artist_transcript_folders_v177')) return ['id'=>0,'name'=>'Unfiled'];
    $stmt = $pdo->prepare('SELECT id,folder_name FROM artist_transcript_folders_v177 WHERE id=? AND created_by_user_id=? LIMIT 1');
    $stmt->execute([$folderId, (int)$user['id']]);
    $row = $stmt->fetch();
    return is_array($row) ? ['id'=>(int)$row['id'],'name'=>(string)$row['folder_name']] : ['id'=>0,'name'=>'Unfiled'];
}

function artist_listening_v237_chat(PDO $pdo, array $user, int $conversationId): ?array
{
    if ($conversationId < 1 || !table_exists('chat_conversations')) return null;
    $stmt = $pdo->prepare('SELECT id,title FROM chat_conversations WHERE id=? AND user_id=? LIMIT 1');
    $stmt->execute([$conversationId, (int)$user['id']]);
    $row = $stmt->fetch();
    return is_array($row) ? ['id'=>(int)$row['id'],'title'=>trim((string)$row['title']) ?: ('Chat #' . (int)$row['id'])] : null;
}

function artist_listening_v237_preview(PDO $pdo, int $sessionId): string
{
    $stmt = $pdo->prepare("SELECT transcript_text FROM artist_transcript_segments_v172 WHERE session_id=? AND segment_type='transcript' AND TRIM(transcript_text)<>'' ORDER BY segment_index ASC,id ASC LIMIT 4");
    $stmt->execute([$sessionId]);
    $parts = [];
    foreach ($stmt->fetchAll() ?: [] as $row) $parts[] = artist_listening_v237_clean_text((string)$row['transcript_text']);
    return mb_strimwidth(trim(implode(' ', $parts)), 0, 260, '…');
}

function artist_listening_v237_library(PDO $pdo, array $user): array
{
    $rows = artist_listening_v172_list($user, 100);
    $sessions = [];
    foreach ($rows as $row) {
        $metadata = artist_listening_v237_metadata($row);
        $folderId = max(0, (int)($metadata['folder_id'] ?? 0));
        $sessionId = (int)$row['id'];
        unset($row['metadata_json']);
        $row['metadata'] = $metadata;
        $row['tags'] = artist_listening_v237_tags($metadata['tags'] ?? []);
        $row['association'] = artist_listening_v237_association($pdo, $user, $metadata);
        $row['chat'] = artist_listening_v237_chat($pdo, $user, (int)($row['conversation_id'] ?? 0));
        $row['folder'] = artist_listening_v237_folder($pdo, $user, $folderId);
        $row['preview'] = artist_listening_v237_preview($pdo, $sessionId);
        $sessions[] = $row;
    }
    $folders = [];
    if (table_exists('artist_transcript_folders_v177')) {
        $stmt = $pdo->prepare('SELECT id,folder_name,sort_order,created_at,updated_at FROM artist_transcript_folders_v177 WHERE created_by_user_id=? ORDER BY sort_order ASC,folder_name ASC,id ASC');
        $stmt->execute([(int)$user['id']]);
        $folders = $stmt->fetchAll() ?: [];
    }
    $options = [];
    if (table_exists('tracks')) {
        $rowsTracks = $pdo->query('SELECT id,title FROM tracks ORDER BY id DESC LIMIT 300')->fetchAll() ?: [];
        foreach ($rowsTracks as $track) {
            $trackId = (int)($track['id'] ?? 0);
            if ($trackId > 0 && artist_listening_v172_track_allowed($pdo, $user, $trackId)) {
                $hasProject = false;
                if (table_exists('track_projects')) {
                    $check = $pdo->prepare('SELECT 1 FROM track_projects WHERE track_id=? LIMIT 1');
                    $check->execute([$trackId]);
                    $hasProject = (bool)$check->fetchColumn();
                }
                $options[] = ['track_id'=>$trackId,'title'=>trim((string)$track['title']) ?: ('Track #' . $trackId),'has_studio_project'=>$hasProject];
            }
        }
    }
    $chatOptions = [];
    if (table_exists('chat_conversations')) {
        $stmt = $pdo->prepare('SELECT id,title,updated_at FROM chat_conversations WHERE user_id=? ORDER BY updated_at DESC,id DESC LIMIT 100');
        $stmt->execute([(int)$user['id']]);
        foreach ($stmt->fetchAll() ?: [] as $chat) {
            $chatOptions[] = ['conversation_id'=>(int)$chat['id'],'title'=>trim((string)$chat['title']) ?: ('Chat #' . (int)$chat['id']),'updated_at'=>(string)$chat['updated_at']];
        }
    }
    return ['sessions'=>$sessions,'folders'=>$folders,'association_options'=>$options,'chat_options'=>$chatOptions];
}

function artist_listening_v237_analysis_status(PDO $pdo, int $sessionId, ?array $map = null): array
{
    $result = ['pages'=>[],'master'=>null,'analyzed_pages'=>0,'page_count'=>(int)($map['page_count'] ?? 0)];
    if (!artist_listening_v237_schema_ready()) return $result;
    $stmt = $pdo->prepare('SELECT page_number,source_hash,source_word_count,provider,model,generated_at,analysis_json FROM artist_transcript_page_analysis_v237 WHERE session_id=? ORDER BY page_number ASC');
    $stmt->execute([$sessionId]);
    foreach ($stmt->fetchAll() ?: [] as $row) {
        $number = (int)$row['page_number'];
        $expected = $map && isset($map['pages'][$number - 1]) ? (string)$map['pages'][$number - 1]['source_hash'] : '';
        $analysis = json_decode((string)$row['analysis_json'], true);
        $fresh = $expected !== '' && hash_equals($expected, (string)$row['source_hash']);
        $result['pages'][(string)$number] = [
            'saved'=>true,'fresh'=>$fresh,'source_hash'=>(string)$row['source_hash'],
            'word_count'=>(int)$row['source_word_count'],'provider'=>(string)$row['provider'],
            'model'=>(string)$row['model'],'generated_at'=>(string)$row['generated_at'],
            'analysis'=>is_array($analysis) ? $analysis : [],
        ];
        if ($fresh) $result['analyzed_pages']++;
    }
    $stmt = $pdo->prepare('SELECT * FROM artist_transcript_master_analysis_v237 WHERE session_id=? LIMIT 1');
    $stmt->execute([$sessionId]);
    $master = $stmt->fetch();
    if (is_array($master)) {
        $analysis = json_decode((string)$master['analysis_json'], true);
        $research = json_decode((string)($master['research_json'] ?? ''), true);
        $expectedHash = $map ? (string)$map['source_hash'] : '';
        $result['master'] = [
            'fresh'=>$expectedHash !== '' && hash_equals($expectedHash, (string)$master['source_hash']),
            'source_hash'=>(string)$master['source_hash'],'word_count'=>(int)$master['source_word_count'],
            'page_count'=>(int)$master['page_count'],'analyzed_page_count'=>(int)$master['analyzed_page_count'],
            'provider'=>(string)$master['provider'],'model'=>(string)$master['model'],
            'generated_at'=>(string)$master['generated_at'],'analysis'=>is_array($analysis)?$analysis:[],
            'research'=>is_array($research)?$research:[],
        ];
    }
    return $result;
}

function artist_listening_v237_parse_analysis(string $answer): array
{
    $answer = trim($answer);
    $decoded = json_decode($answer, true);
    if (!is_array($decoded)) {
        $answer = preg_replace('/^```(?:json)?\s*|\s*```$/i', '', $answer) ?? $answer;
        $decoded = json_decode(trim($answer), true);
    }
    if (!is_array($decoded)) {
        $first = strpos($answer, '{'); $last = strrpos($answer, '}');
        if ($first !== false && $last !== false && $last > $first) $decoded = json_decode(substr($answer, $first, $last - $first + 1), true);
    }
    if (!is_array($decoded)) $decoded = ['summary'=>$answer];
    $cleanList = static function (mixed $value, int $limit = 10): array {
        if (!is_array($value)) return [];
        $out = [];
        foreach ($value as $item) {
            if (is_array($item)) $item = $item['text'] ?? $item['item'] ?? $item['query'] ?? '';
            $text = artist_listening_v237_clean_text((string)$item);
            if ($text !== '') $out[] = mb_strimwidth($text, 0, 700, '…');
            if (count($out) >= $limit) break;
        }
        return $out;
    };
    return [
        'summary'=>mb_strimwidth(artist_listening_v237_clean_text((string)($decoded['summary'] ?? '')), 0, 5000, '…'),
        'key_points'=>$cleanList($decoded['key_points'] ?? [], 12),
        'decisions'=>$cleanList($decoded['decisions'] ?? [], 10),
        'action_items'=>$cleanList($decoded['action_items'] ?? [], 12),
        'open_questions'=>$cleanList($decoded['open_questions'] ?? [], 10),
        'participant_notes'=>$cleanList($decoded['participant_notes'] ?? [], 12),
        'research_queries'=>$cleanList($decoded['research_queries'] ?? [], 4),
    ];
}

function artist_listening_v237_extract_openai_text(array $decoded): string
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

function artist_listening_v237_ai(string $prompt, array $user, int $maxTokens = 1800): array
{
    $provider = ai_active_provider();
    if (!in_array($provider, ['openai','anthropic'], true) || !ai_provider_ready($provider)) {
        throw new RuntimeException('Enable and configure OpenAI or Claude before using transcript analysis.');
    }
    ai_v100_rate_limit('chat', $user);
    $model = ai_provider_model($provider);
    $started = microtime(true);
    if ($provider === 'openai') {
        $result = ai_curl_json('https://api.openai.com/v1/responses', [
            'Authorization: Bearer ' . ai_provider_api_key('openai'),'Content-Type: application/json'
        ], ['model'=>$model,'input'=>$prompt,'max_output_tokens'=>$maxTokens], 55);
        if (empty($result['ok']) || !is_array($result['data'] ?? null)) throw new RuntimeException((string)($result['error'] ?? 'OpenAI transcript analysis was unavailable.'));
        $answer = artist_listening_v237_extract_openai_text($result['data']);
        $usage = ai_v100_usage('openai', $result['data']);
    } else {
        $result = ai_curl_json('https://api.anthropic.com/v1/messages', [
            'x-api-key: ' . ai_provider_api_key('anthropic'),'anthropic-version: 2023-06-01','Content-Type: application/json'
        ], ['model'=>$model,'max_tokens'=>$maxTokens,'messages'=>[['role'=>'user','content'=>$prompt]]], 55);
        if (empty($result['ok']) || !is_array($result['data'] ?? null)) throw new RuntimeException((string)($result['error'] ?? 'Claude transcript analysis was unavailable.'));
        $parts = [];
        foreach (($result['data']['content'] ?? []) as $block) if (is_array($block) && ($block['type'] ?? '') === 'text' && is_string($block['text'] ?? null)) $parts[] = $block['text'];
        $answer = trim(implode("\n", $parts));
        $usage = ai_v100_usage('anthropic', $result['data']);
    }
    ai_v100_telemetry([
        'scope'=>'chat','service'=>'artist-listening-v237','user_id'=>(int)($user['id'] ?? 0),
        'provider'=>$provider,'model'=>$model,'status'=>$answer !== '' ? 'success' : 'empty',
        'duration_ms'=>(int)round((microtime(true)-$started)*1000),'input_chars'=>mb_strlen($prompt),
        'output_chars'=>mb_strlen($answer),'complexity'=>'long-transcript'
    ] + $usage);
    if ($answer === '') throw new RuntimeException('The AI provider returned an empty transcript analysis.');
    return ['provider'=>$provider,'model'=>$model,'answer'=>$answer];
}

function artist_listening_transcript_page_prompt(array $page, array $participants): string
{
    $lines = [];
    foreach ($page['segments'] as $segment) {
        if ((string)($segment['segment_type'] ?? '') !== 'transcript') continue;
        $speaker = trim((string)($segment['speaker_label'] ?? 'Speaker 1')) ?: 'Speaker 1';
        $name = (string)($participants[$speaker] ?? $speaker);
        $text = artist_listening_v237_clean_text((string)($segment['transcript_text'] ?? ''));
        if ($text !== '') $lines[] = $name . ': ' . $text;
    }
    return "Analyze one page of a long private transcript. Use only what was actually said. Do not invent identities, facts, decisions, commitments, or research results. Preserve participant labels. Return ONLY JSON with keys summary, key_points, decisions, action_items, open_questions, participant_notes, research_queries. research_queries should contain at most 4 topics that materially need current verification.\n\nPARTICIPANTS:\n" . json_encode($participants, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE) . "\n\nPAGE " . (int)$page['page_number'] . " TRANSCRIPT:\n" . implode("\n", $lines);
}

function artist_listening_v237_analyze_page(PDO $pdo, array $user, int $sessionId, int $pageNumber, bool $force = false): array
{
    if (!artist_listening_v237_schema_ready()) throw new RuntimeException('Run the Stonefellow database upgrade before saving long-transcript AI analysis.');
    $session = artist_listening_v172_session($pdo, $user, $sessionId);
    $map = artist_listening_transcript_page_map(artist_listening_v172_segments($pdo, $sessionId));
    $pageNumber = max(1, min((int)$map['page_count'], $pageNumber));
    $page = $map['pages'][$pageNumber - 1];
    $existing = $pdo->prepare('SELECT * FROM artist_transcript_page_analysis_v237 WHERE session_id=? AND page_number=? LIMIT 1');
    $existing->execute([$sessionId, $pageNumber]);
    $cached = $existing->fetch();
    if (!$force && is_array($cached) && hash_equals((string)$page['source_hash'], (string)$cached['source_hash'])) {
        $analysis = json_decode((string)$cached['analysis_json'], true);
        return ['cached'=>true,'page_number'=>$pageNumber,'source_hash'=>(string)$page['source_hash'],'analysis'=>is_array($analysis)?$analysis:[],'provider'=>(string)$cached['provider'],'model'=>(string)$cached['model'],'generated_at'=>(string)$cached['generated_at']];
    }
    if ((int)$page['word_count'] < 120) throw new RuntimeException('This page needs at least 120 transcript words before AI analysis starts.');
    $ai = artist_listening_v237_ai(artist_listening_transcript_page_prompt($page, artist_listening_v237_participants($session)), $user, 1800);
    $analysis = artist_listening_v237_parse_analysis((string)$ai['answer']);
    $json = json_encode($analysis, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
    if (!is_string($json)) throw new RuntimeException('Could not encode page analysis.');
    $stmt = $pdo->prepare(
        'INSERT INTO artist_transcript_page_analysis_v237 (session_id,page_number,source_hash,source_word_count,start_segment_index,end_segment_index,analysis_json,provider,model,generated_at)
         VALUES (?,?,?,?,?,?,?,?,?,NOW())
         ON DUPLICATE KEY UPDATE source_hash=VALUES(source_hash),source_word_count=VALUES(source_word_count),start_segment_index=VALUES(start_segment_index),end_segment_index=VALUES(end_segment_index),analysis_json=VALUES(analysis_json),provider=VALUES(provider),model=VALUES(model),generated_at=NOW()'
    );
    $stmt->execute([$sessionId,$pageNumber,(string)$page['source_hash'],(int)$page['word_count'],(int)$page['start_segment_index'],(int)$page['end_segment_index'],$json,(string)$ai['provider'],(string)$ai['model']]);
    return ['cached'=>false,'page_number'=>$pageNumber,'source_hash'=>(string)$page['source_hash'],'analysis'=>$analysis,'provider'=>(string)$ai['provider'],'model'=>(string)$ai['model'],'generated_at'=>gmdate('c')];
}

function artist_listening_v237_research(array $queries, array $user): array
{
    $queries = array_slice(array_values(array_filter(array_map(static fn($v): string => artist_listening_v237_clean_text((string)$v), $queries))), 0, 3);
    if (!$queries) return ['text'=>'','sources'=>[],'error'=>''];
    $provider = ai_active_provider();
    if (!in_array($provider, ['openai','anthropic'], true) || !ai_provider_ready($provider)) return ['text'=>'','sources'=>[],'error'=>'Research provider is not configured.'];
    $model = ai_provider_model($provider);
    $prompt = "Research these important items from a long transcript. Use current web sources where relevant, separate verified facts from uncertainty, and stay concise.\n\n- " . implode("\n- ", $queries);
    try {
        if ($provider === 'openai') {
            $result = ai_curl_json('https://api.openai.com/v1/responses', ['Authorization: Bearer ' . ai_provider_api_key('openai'),'Content-Type: application/json'], ['model'=>$model,'tools'=>[['type'=>'web_search']],'tool_choice'=>'auto','input'=>$prompt,'max_output_tokens'=>1400], 60);
            if (empty($result['ok']) || !is_array($result['data'] ?? null)) return ['text'=>'','sources'=>[],'error'=>(string)($result['error'] ?? 'Research unavailable.')];
            $sources = [];
            foreach (($result['data']['output'] ?? []) as $item) foreach (($item['content'] ?? []) as $content) foreach (($content['annotations'] ?? []) as $annotation) {
                if (!is_array($annotation) || ($annotation['type'] ?? '') !== 'url_citation') continue;
                $citation = is_array($annotation['url_citation'] ?? null) ? $annotation['url_citation'] : $annotation;
                $url = trim((string)($citation['url'] ?? '')); if ($url === '' || !str_starts_with($url, 'https://')) continue;
                $sources[$url] = ['url'=>$url,'title'=>mb_strimwidth(trim((string)($citation['title'] ?? $url)),0,220,'…')];
            }
            return ['text'=>mb_strimwidth(artist_listening_v237_extract_openai_text($result['data']),0,6000,'…'),'sources'=>array_slice(array_values($sources),0,10),'error'=>''];
        }
        $result = ai_curl_json('https://api.anthropic.com/v1/messages', ['x-api-key: ' . ai_provider_api_key('anthropic'),'anthropic-version: 2023-06-01','Content-Type: application/json'], ['model'=>$model,'max_tokens'=>1400,'messages'=>[['role'=>'user','content'=>$prompt]],'tools'=>[['type'=>'web_search_20260318','name'=>'web_search','max_uses'=>3,'allowed_callers'=>['direct']]]], 60);
        if (empty($result['ok']) || !is_array($result['data'] ?? null)) return ['text'=>'','sources'=>[],'error'=>(string)($result['error'] ?? 'Research unavailable.')];
        $parts = []; $sources = [];
        foreach (($result['data']['content'] ?? []) as $block) {
            if (!is_array($block)) continue;
            if (($block['type'] ?? '') === 'text' && is_string($block['text'] ?? null)) $parts[] = $block['text'];
            foreach (($block['citations'] ?? []) as $citation) {
                if (!is_array($citation)) continue; $url=trim((string)($citation['url']??'')); if($url==='')continue;
                $sources[$url]=['url'=>$url,'title'=>mb_strimwidth(trim((string)($citation['title']??$url)),0,220,'…')];
            }
        }
        return ['text'=>mb_strimwidth(trim(implode("\n",$parts)),0,6000,'…'),'sources'=>array_slice(array_values($sources),0,10),'error'=>''];
    } catch (Throwable $e) {
        return ['text'=>'','sources'=>[],'error'=>ai_v100_safe_exception($e, 'Research unavailable.')];
    }
}

function artist_listening_v237_analyze_master(PDO $pdo, array $user, int $sessionId, bool $research = false, bool $force = false): array
{
    if (!artist_listening_v237_schema_ready()) throw new RuntimeException('Run the Stonefellow database upgrade before saving long-transcript AI analysis.');
    $session = artist_listening_v172_session($pdo, $user, $sessionId);
    $map = artist_listening_transcript_page_map(artist_listening_v172_segments($pdo, $sessionId));
    $status = artist_listening_v237_analysis_status($pdo, $sessionId, $map);
    $freshPages = [];
    foreach ($map['pages'] as $page) {
        $number = (string)$page['page_number'];
        $row = $status['pages'][$number] ?? null;
        if (is_array($row) && !empty($row['fresh'])) $freshPages[] = ['page'=>(int)$page['page_number'],'analysis'=>$row['analysis']];
    }
    if (!$freshPages) throw new RuntimeException('Analyze at least one transcript page before building the whole-transcript analysis.');
    $existing = $status['master'];
    if (!$force && is_array($existing) && !empty($existing['fresh']) && (int)$existing['analyzed_page_count'] === count($freshPages)) return ['cached'=>true,'master'=>$existing];
    $prompt = "Build the master analysis for one long private transcript from page-level analyses. Do not invent facts not present in the page analyses. Preserve changes, disagreements, decisions, actions, open questions, and participant contributions across pages. Return ONLY JSON with keys summary, key_points, decisions, action_items, open_questions, participant_notes, research_queries.\n\nCOVERAGE: " . count($freshPages) . ' of ' . (int)$map['page_count'] . " pages analyzed.\n\nPAGE ANALYSES:\n" . json_encode($freshPages, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
    $ai = artist_listening_v237_ai($prompt, $user, 2200);
    $analysis = artist_listening_v237_parse_analysis((string)$ai['answer']);
    $researchResult = $research ? artist_listening_v237_research($analysis['research_queries'] ?? [], $user) : (is_array($existing['research'] ?? null) ? $existing['research'] : []);
    $analysisJson = json_encode($analysis, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
    $researchJson = json_encode($researchResult, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
    if (!is_string($analysisJson) || !is_string($researchJson)) throw new RuntimeException('Could not encode master transcript analysis.');
    $stmt = $pdo->prepare(
        'INSERT INTO artist_transcript_master_analysis_v237 (session_id,source_hash,source_word_count,page_count,analyzed_page_count,analysis_json,research_json,provider,model,generated_at)
         VALUES (?,?,?,?,?,?,?,?,?,NOW())
         ON DUPLICATE KEY UPDATE source_hash=VALUES(source_hash),source_word_count=VALUES(source_word_count),page_count=VALUES(page_count),analyzed_page_count=VALUES(analyzed_page_count),analysis_json=VALUES(analysis_json),research_json=VALUES(research_json),provider=VALUES(provider),model=VALUES(model),generated_at=NOW()'
    );
    $stmt->execute([$sessionId,(string)$map['source_hash'],(int)$map['total_words'],(int)$map['page_count'],count($freshPages),$analysisJson,$researchJson,(string)$ai['provider'],(string)$ai['model']]);
    return ['cached'=>false,'master'=>[
        'fresh'=>count($freshPages)===(int)$map['page_count'],'source_hash'=>(string)$map['source_hash'],
        'word_count'=>(int)$map['total_words'],'page_count'=>(int)$map['page_count'],'analyzed_page_count'=>count($freshPages),
        'provider'=>(string)$ai['provider'],'model'=>(string)$ai['model'],'generated_at'=>gmdate('c'),'analysis'=>$analysis,'research'=>$researchResult,
    ]];
}
