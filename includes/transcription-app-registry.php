<?php
declare(strict_types=1);

const VP3_TRANSCRIPTION_APP_REGISTRY_VERSION = 1;

function transcription_app_registry_v300(): array
{
    return [
        'basic' => [
            'id'=>'basic','label'=>'Analysis','title'=>'Basic Analysis','description'=>'Executive analysis, findings, agreements, conflicts and gaps.','execution'=>'ai','live'=>true,'view'=>'sections',
            'sections'=>[
                ['key'=>'key_findings','title'=>'Key findings','primary'=>'text','meta'=>['evidence','confidence']],
                ['key'=>'agreements','title'=>'Agreements','primary'=>'text','meta'=>['evidence','confidence']],
                ['key'=>'conflicts','title'=>'Conflicts','primary'=>'text','meta'=>['evidence','confidence']],
                ['key'=>'changes_from_prior','title'=>'Changes from prior context','primary'=>'text','meta'=>['evidence','confidence']],
                ['key'=>'open_questions','title'=>'Open questions','primary'=>'text','meta'=>['evidence']],
                ['key'=>'context_gaps','title'=>'Context gaps','primary'=>'text','meta'=>['impact']],
            ],
            'legacy'=>['summary','logical_report','key_points','agreements','conflicts','changes_from_prior','open_questions','context_gaps'],
            'prompt'=>'Basic Analysis result object: summary is a concise executive summary; analysis is a compact evidence-grounded interpretation. key_findings, agreements, conflicts, changes_from_prior, open_questions and context_gaps are arrays of objects. Use {text,evidence,confidence} for findings/agreements/conflicts/changes, {text,evidence} for open_questions, and {text,impact} for context_gaps. Evidence should identify transcript page(s), never invent a quote. Confidence must be high, medium or low.',
        ],
        'stats' => [
            'id'=>'stats','label'=>'Stats','title'=>'Stats Report','description'=>'Deterministic transcript and speaker measurements.','execution'=>'deterministic','live'=>true,'view'=>'stats','sections'=>[],'legacy'=>['stats'],'prompt'=>'',
        ],
        'actions' => [
            'id'=>'actions','label'=>'Actions','title'=>'Suggested Actions','description'=>'Prioritized next steps with ownership, timing and evidence.','execution'=>'ai','live'=>true,'view'=>'sections',
            'sections'=>[['key'=>'items','title'=>'Recommended next actions','primary'=>'action','meta'=>['owner','timing','priority','rationale','evidence','confidence']]],
            'legacy'=>['action_items'],
            'prompt'=>'Suggested Actions result object: items is an array of {action,owner,timing,priority,rationale,evidence,confidence}. Actions must be concrete and grounded in the transcript. Use unknown when owner or timing is not supported. priority is high, medium or low. Evidence identifies transcript page(s). Confidence is high, medium or low.',
        ],
        'responses' => [
            'id'=>'responses','label'=>'Responses','title'=>'Suggested Responses','description'=>'Grounded replies, questions and talking points for follow-up.','execution'=>'ai','live'=>true,'view'=>'sections',
            'sections'=>[['key'=>'items','title'=>'Responses to consider','primary'=>'response','meta'=>['audience','purpose','tone','evidence','confidence']]],
            'legacy'=>['suggested_responses'],
            'prompt'=>'Suggested Responses result object: items is an array of {response,audience,purpose,tone,evidence,confidence}. Responses must be useful next replies, questions or talking points and must not invent facts. Evidence identifies the transcript basis. Confidence is high, medium or low.',
        ],
        'decisions' => [
            'id'=>'decisions','label'=>'Decisions','title'=>'Decisions & Commitments','description'=>'Confirmed choices and obligations separated from suggestions.','execution'=>'ai','live'=>true,'view'=>'sections',
            'sections'=>[
                ['key'=>'decisions','title'=>'Decisions','primary'=>'decision','meta'=>['owner','timing','evidence','confidence']],
                ['key'=>'commitments','title'=>'Commitments','primary'=>'commitment','meta'=>['owner','timing','evidence','confidence']],
            ],
            'legacy'=>['decisions','commitments'],
            'prompt'=>'Decisions & Commitments result object: decisions is an array of {decision,owner,timing,evidence,confidence}; commitments is an array of {commitment,owner,timing,evidence,confidence}. A decision must be a concluded choice, not an idea. A commitment must be an expressed promise or obligation. Use unknown for unsupported owner/timing. Evidence identifies transcript page(s).',
        ],
        'moments' => [
            'id'=>'moments','label'=>'Moments','title'=>'Key Moments','description'=>'Consequential moments with significance and source location.','execution'=>'ai','live'=>true,'view'=>'sections',
            'sections'=>[['key'=>'items','title'=>'Important moments','primary'=>'moment','meta'=>['why_it_matters','page','participants','confidence']]],
            'legacy'=>['key_moments'],
            'prompt'=>'Key Moments result object: items is an array of {moment,why_it_matters,page,participants,confidence}. Capture consequential changes, discoveries, decisions, objections or turning points. page should be the transcript page number when supported. participants is a short comma-separated label list. Do not fabricate timestamps or quotes.',
        ],
        'studio' => [
            'id'=>'studio','label'=>'Studio','title'=>'Studio Notes','description'=>'Production, arrangement, performance, lyric and mix notes.','execution'=>'ai','live'=>true,'view'=>'sections',
            'sections'=>[['key'=>'items','title'=>'Production / song notes','primary'=>'note','meta'=>['category','target','priority','evidence','confidence']]],
            'legacy'=>['studio_notes'],
            'prompt'=>'Studio Notes result object: items is an array of {note,category,target,priority,evidence,confidence}. Extract only relevant song, lyric, arrangement, performance, recording, mix, gear, tempo or production instructions. category should be one of song, lyrics, arrangement, performance, recording, mix, gear, tempo, production, other. target identifies the song/section/track when supported.',
        ],
        'knowledge' => [
            'id'=>'knowledge','label'=>'Knowledge','title'=>'Knowledge Extractor','description'=>'Durable facts, preferences, decisions and project state worth retaining.','execution'=>'ai','live'=>true,'view'=>'sections',
            'sections'=>[['key'=>'items','title'=>'Knowledge worth saving','primary'=>'value','meta'=>['type','key','evidence','confidence','conflict']]],
            'legacy'=>['knowledge_candidates','changes_from_prior','conflicts'],
            'prompt'=>'Knowledge Extractor result object: items is an array of {type,key,value,evidence,confidence,conflict}. type is fact, preference, decision, relationship, project_state, constraint, process or other. Extract durable information worth considering for Agent Brain or Personal Knowledge. conflict is true only when the transcript conflicts with prior authorized context. Never treat external research as a private user fact.',
        ],
        'topics' => [
            'id'=>'topics','label'=>'Topics','title'=>'Topics & Themes','description'=>'Topic map showing what was discussed and why it mattered.','execution'=>'ai','live'=>true,'view'=>'sections',
            'sections'=>[['key'=>'items','title'=>'Topics and themes','primary'=>'topic','meta'=>['summary','importance','evidence','confidence']]],
            'legacy'=>[],
            'prompt'=>'Topics & Themes result object: items is an array of {topic,summary,importance,evidence,confidence}. Consolidate duplicate subjects into useful themes. importance is high, medium or low based on transcript emphasis and consequence, not speculation. Evidence identifies transcript page(s).',
        ],
        'entities' => [
            'id'=>'entities','label'=>'Entities','title'=>'Entities & Data','description'=>'Structured people, organizations, products, dates, amounts and identifiers.','execution'=>'ai','live'=>true,'view'=>'sections',
            'sections'=>[['key'=>'items','title'=>'Entities and data','primary'=>'name','meta'=>['type','value','context','evidence','confidence']]],
            'legacy'=>[],
            'prompt'=>'Entities & Data result object: items is an array of {type,name,value,context,evidence,confidence}. Extract explicit people, organizations, locations, products, projects, dates, times, amounts, quantities, URLs, email addresses and other named identifiers. Do not infer identities. Keep value separate from name when the item is a measurement, date or amount.',
        ],
        'risks' => [
            'id'=>'risks','label'=>'Risks','title'=>'Risks & Blockers','description'=>'Risks, blockers, dependencies and mitigation opportunities.','execution'=>'ai','live'=>true,'view'=>'sections',
            'sections'=>[['key'=>'items','title'=>'Risks and blockers','primary'=>'risk','meta'=>['impact','likelihood','owner','mitigation','evidence','confidence']]],
            'legacy'=>[],
            'prompt'=>'Risks & Blockers result object: items is an array of {risk,impact,likelihood,owner,mitigation,evidence,confidence}. Include only risks, blockers or dependencies supported by the transcript. likelihood is high, medium, low or unknown. mitigation may be empty when the transcript does not support one. Do not manufacture a risk merely to fill the report.',
        ],
        'timeline' => [
            'id'=>'timeline','label'=>'Timeline','title'=>'Timeline & Milestones','description'=>'Chronology, deadlines, milestones and state changes.','execution'=>'ai','live'=>true,'view'=>'sections',
            'sections'=>[['key'=>'items','title'=>'Timeline and milestones','primary'=>'event','meta'=>['timing','status','owner','evidence','confidence']]],
            'legacy'=>[],
            'prompt'=>'Timeline & Milestones result object: items is an array of {event,timing,status,owner,evidence,confidence}. Capture explicit chronology, milestones, deadlines and state changes. timing must preserve uncertainty such as next week or unknown rather than inventing a date. status is planned, in_progress, completed, changed, cancelled, blocked or unknown.',
        ],
    ];
}

function transcription_app_registry_public_v300(): array
{
    $out = [];
    foreach (transcription_app_registry_v300() as $app) {
        $out[] = [
            'id'=>$app['id'],'label'=>$app['label'],'title'=>$app['title'],'description'=>$app['description'],
            'execution'=>$app['execution'],'live'=>(bool)$app['live'],'view'=>$app['view'],'sections'=>$app['sections'],
        ];
    }
    return $out;
}

function transcription_app_ids_v300(mixed $value): array
{
    $registry = transcription_app_registry_v300();
    if (!is_array($value)) return ['basic'];
    $out = [];
    foreach ($value as $item) {
        $id = strtolower(trim((string)$item));
        if (isset($registry[$id]) && !in_array($id, $out, true)) $out[] = $id;
    }
    return $out ?: ['basic'];
}

function transcription_app_clean_v300(string $text, int $max = 1200): string
{
    return mb_strimwidth(trim(preg_replace('/\s+/u', ' ', $text) ?? $text), 0, $max, '…');
}

function transcription_app_sanitize_value_v300(mixed $value, int $depth = 0): mixed
{
    if ($depth > 4) return null;
    if (is_string($value)) return transcription_app_clean_v300($value, 1400);
    if (is_bool($value) || is_int($value) || is_float($value) || $value === null) return $value;
    if (!is_array($value)) return transcription_app_clean_v300((string)$value, 1400);
    $out = [];
    $count = 0;
    foreach ($value as $key=>$item) {
        if ($count++ >= 30) break;
        $clean = transcription_app_sanitize_value_v300($item, $depth + 1);
        if ($clean === null && $item !== null) continue;
        $out[$key] = $clean;
    }
    return $out;
}

function transcription_app_decode_json_v300(string $answer): array
{
    $raw = trim($answer);
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        $raw = preg_replace('/^```(?:json)?\s*|\s*```$/i', '', $raw) ?? $raw;
        $data = json_decode(trim($raw), true);
    }
    if (!is_array($data)) {
        $first = strpos($raw, '{');
        $last = strrpos($raw, '}');
        if ($first !== false && $last !== false && $last > $first) $data = json_decode(substr($raw, $first, $last - $first + 1), true);
    }
    if (!is_array($data)) throw new RuntimeException('The AI provider did not return a structured transcription app result.');
    return $data;
}

function transcription_app_legacy_result_v300(string $id, array $analysis): array
{
    $list = static function (mixed $value, string $primary): array {
        if (!is_array($value)) return [];
        $out = [];
        foreach ($value as $item) {
            if (is_array($item)) $out[] = transcription_app_sanitize_value_v300($item);
            else {
                $text = transcription_app_clean_v300((string)$item, 900);
                if ($text !== '') $out[] = [$primary=>$text];
            }
        }
        return $out;
    };
    return match ($id) {
        'basic' => [
            'summary'=>transcription_app_clean_v300((string)($analysis['summary'] ?? ''), 5000),
            'analysis'=>transcription_app_clean_v300((string)($analysis['logical_report'] ?? ''), 7000),
            'key_findings'=>$list($analysis['key_points'] ?? [], 'text'),
            'agreements'=>$list($analysis['agreements'] ?? [], 'text'),
            'conflicts'=>$list($analysis['conflicts'] ?? [], 'text'),
            'changes_from_prior'=>$list($analysis['changes_from_prior'] ?? [], 'text'),
            'open_questions'=>$list($analysis['open_questions'] ?? [], 'text'),
            'context_gaps'=>$list($analysis['context_gaps'] ?? [], 'text'),
        ],
        'stats' => is_array($analysis['stats'] ?? null) ? $analysis['stats'] : [],
        'actions' => ['items'=>$list($analysis['action_items'] ?? [], 'action')],
        'responses' => ['items'=>$list($analysis['suggested_responses'] ?? [], 'response')],
        'decisions' => ['decisions'=>$list($analysis['decisions'] ?? [], 'decision'),'commitments'=>$list($analysis['commitments'] ?? [], 'commitment')],
        'moments' => ['items'=>$list($analysis['key_moments'] ?? [], 'moment')],
        'studio' => ['items'=>$list($analysis['studio_notes'] ?? [], 'note')],
        'knowledge' => ['items'=>$list($analysis['knowledge_candidates'] ?? [], 'value')],
        default => [],
    };
}

function transcription_app_has_result_v300(mixed $value): bool
{
    if (is_string($value)) return trim($value) !== '';
    if (!is_array($value)) return $value !== null;
    foreach ($value as $item) if (transcription_app_has_result_v300($item)) return true;
    return false;
}

function transcription_app_modules_v300(array $analysis, ?array $master = null): array
{
    $registry = transcription_app_registry_v300();
    $stored = is_array($analysis['modules'] ?? null) ? $analysis['modules'] : [];
    $modules = [];
    foreach ($stored as $id=>$module) {
        if (!isset($registry[$id]) || !is_array($module)) continue;
        $result = is_array($module['result'] ?? null) ? transcription_app_sanitize_value_v300($module['result']) : [];
        $modules[$id] = [
            'app_id'=>$id,
            'source_hash'=>(string)($module['source_hash'] ?? $master['source_hash'] ?? ''),
            'source_word_count'=>max(0, (int)($module['source_word_count'] ?? $master['word_count'] ?? 0)),
            'generated_at'=>(string)($module['generated_at'] ?? $master['generated_at'] ?? ''),
            'provider'=>(string)($module['provider'] ?? $master['provider'] ?? ''),
            'model'=>(string)($module['model'] ?? $master['model'] ?? ''),
            'result'=>is_array($result) ? $result : [],
        ];
    }
    $legacyApps = transcription_app_ids_v300($analysis['apps'] ?? ['basic']);
    foreach ($legacyApps as $id) {
        if (isset($modules[$id])) continue;
        $result = transcription_app_legacy_result_v300($id, $analysis);
        if (!transcription_app_has_result_v300($result)) continue;
        $modules[$id] = [
            'app_id'=>$id,'source_hash'=>(string)($master['source_hash'] ?? ''),
            'source_word_count'=>max(0, (int)($master['word_count'] ?? 0)),
            'generated_at'=>(string)($master['generated_at'] ?? ''),'provider'=>(string)($master['provider'] ?? ''),
            'model'=>(string)($master['model'] ?? ''),'result'=>$result,
        ];
    }
    return $modules;
}

function transcription_app_compat_projection_v300(array $modules): array
{
    $projection = ['registry_version'=>VP3_TRANSCRIPTION_APP_REGISTRY_VERSION,'modules'=>$modules,'apps'=>array_keys($modules)];
    $basic = is_array($modules['basic']['result'] ?? null) ? $modules['basic']['result'] : [];
    if ($basic) {
        $projection['summary'] = (string)($basic['summary'] ?? '');
        $projection['logical_report'] = (string)($basic['analysis'] ?? '');
        $projection['key_points'] = array_values(array_filter(array_map(static fn($x): string => is_array($x) ? (string)($x['text'] ?? '') : (string)$x, $basic['key_findings'] ?? [])));
        foreach (['agreements','conflicts','changes_from_prior','open_questions','context_gaps'] as $key) {
            $projection[$key] = array_values(array_filter(array_map(static fn($x): string => is_array($x) ? (string)($x['text'] ?? '') : (string)$x, $basic[$key] ?? [])));
        }
    }
    $maps = [
        'actions'=>['items','action_items','action'],
        'responses'=>['items','suggested_responses','response'],
        'moments'=>['items','key_moments','moment'],
        'studio'=>['items','studio_notes','note'],
        'knowledge'=>['items','knowledge_candidates','value'],
    ];
    foreach ($maps as $id=>[$source,$target,$primary]) {
        $rows = $modules[$id]['result'][$source] ?? [];
        if (is_array($rows)) $projection[$target] = array_values(array_filter(array_map(static fn($x): string => is_array($x) ? (string)($x[$primary] ?? '') : (string)$x, $rows)));
    }
    if (isset($modules['decisions'])) {
        foreach (['decisions'=>'decision','commitments'=>'commitment'] as $key=>$primary) {
            $rows = $modules['decisions']['result'][$key] ?? [];
            if (is_array($rows)) $projection[$key] = array_values(array_filter(array_map(static fn($x): string => is_array($x) ? (string)($x[$primary] ?? '') : (string)$x, $rows)));
        }
    }
    if (isset($modules['stats'])) $projection['stats'] = $modules['stats']['result'];
    return $projection;
}

function transcription_app_status_v300(?array $master, string $currentHash): array
{
    $analysis = is_array($master['analysis'] ?? null) ? $master['analysis'] : [];
    $modules = transcription_app_modules_v300($analysis, $master);
    $analysis = transcription_app_compat_projection_v300($modules);
    if ($master) $master['analysis'] = $analysis;
    $status = [];
    foreach (transcription_app_registry_v300() as $id=>$app) {
        $module = $modules[$id] ?? null;
        $status[$id] = [
            'generated'=>is_array($module),
            'fresh'=>is_array($module) && $currentHash !== '' && hash_equals($currentHash, (string)($module['source_hash'] ?? '')),
            'generated_at'=>(string)($module['generated_at'] ?? ''),
            'provider'=>(string)($module['provider'] ?? ''),'model'=>(string)($module['model'] ?? ''),
            'word_count'=>max(0, (int)($module['source_word_count'] ?? 0)),
        ];
    }
    return ['master'=>$master,'app_status'=>$status,'registry'=>transcription_app_registry_public_v300()];
}

function transcription_app_permissions_v300(array $user): array
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

function transcription_app_tags_v300(array $session): array
{
    $meta = json_decode((string)($session['metadata_json'] ?? ''), true);
    $raw = is_array($meta) ? ($meta['tags'] ?? []) : [];
    if (!is_array($raw)) $raw = preg_split('/[,\n]+/u', (string)$raw) ?: [];
    $out = [];
    foreach ($raw as $tag) {
        $tag = transcription_app_clean_v300((string)$tag, 50);
        if ($tag === '') continue;
        $out[mb_strtolower($tag)] = $tag;
        if (count($out) >= 12) break;
    }
    return array_values($out);
}

function transcription_app_stats_v300(array $segments, array $session, array $map): array
{
    $speakers = [];
    $turns = 0; $questions = 0; $notes = 0; $markers = 0; $other = 0; $maxEnded = 0; $longestTurn = 0;
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
        $label = transcription_app_clean_v300((string)($row['speaker_label'] ?? 'Speaker 1'), 80) ?: 'Speaker 1';
        $words = $text === '' ? 0 : count(preg_split('/\s+/u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: []);
        $q = preg_match_all('/\?/u', $text) ?: 0;
        $turns++; $questions += $q; $longestTurn = max($longestTurn, $words);
        if (!isset($speakers[$label])) $speakers[$label] = ['label'=>$label,'words'=>0,'turns'=>0,'questions'=>0,'duration_ms'=>0];
        $speakers[$label]['words'] += $words;
        $speakers[$label]['turns']++;
        $speakers[$label]['questions'] += $q;
        $speakers[$label]['duration_ms'] += max(0, $ended - $started);
    }
    $totalWords = max(0, (int)($map['total_words'] ?? 0));
    $durationMs = max((int)($session['duration_ms'] ?? 0), $maxEnded);
    $speakerRows = array_values($speakers);
    usort($speakerRows, static fn(array $a,array $b): int => ($b['words'] <=> $a['words']) ?: strcmp((string)$a['label'], (string)$b['label']));
    foreach ($speakerRows as &$row) {
        $row['word_share'] = $totalWords > 0 ? round(((int)$row['words'] / $totalWords) * 100, 1) : 0.0;
        $row['turn_share'] = $turns > 0 ? round(((int)$row['turns'] / $turns) * 100, 1) : 0.0;
        $row['avg_words_per_turn'] = (int)$row['turns'] > 0 ? round((int)$row['words'] / (int)$row['turns'], 1) : 0.0;
        $row['duration_label'] = transcription_app_duration_v300((int)$row['duration_ms']);
    }
    unset($row);
    $minutes = $durationMs > 0 ? $durationMs / 60000 : 0.0;
    return [
        'total_words'=>$totalWords,'duration_ms'=>$durationMs,'duration_label'=>transcription_app_duration_v300($durationMs),
        'transcript_turns'=>$turns,'speaker_count'=>count($speakerRows),'question_count'=>$questions,
        'questions_per_1000_words'=>$totalWords > 0 ? round(($questions / $totalWords) * 1000, 1) : 0.0,
        'avg_words_per_turn'=>$turns > 0 ? round($totalWords / $turns, 1) : 0.0,'longest_turn_words'=>$longestTurn,
        'note_count'=>$notes,'marker_count'=>$markers,'other_segment_count'=>$other,
        'words_per_minute'=>$minutes > 0 ? (int)round($totalWords / $minutes) : 0,'speakers'=>$speakerRows,
    ];
}

function transcription_app_duration_v300(int $ms): string
{
    $seconds = max(0, (int)round($ms / 1000));
    $hours = intdiv($seconds, 3600); $minutes = intdiv($seconds % 3600, 60); $secs = $seconds % 60;
    return $hours > 0 ? sprintf('%d:%02d:%02d', $hours, $minutes, $secs) : sprintf('%d:%02d', $minutes, $secs);
}

function transcription_app_context_v300(array $user, string $query): array
{
    $brain = [];
    if (has_permission('account.access', $user) && agent_brain_schema_ready() && function_exists('agent_brain_v99_context')) {
        try {
            foreach (agent_brain_v99_context($user, $query, 6) as $row) {
                if (!is_array($row) || trim((string)($row['text'] ?? '')) === '') continue;
                $brain[] = ['source'=>(string)($row['source'] ?? 'agent-brain'),'title'=>transcription_app_clean_v300((string)($row['title'] ?? 'Agent Brain context'),160),'text'=>mb_strimwidth(trim((string)$row['text']),0,1400,'…')];
            }
        } catch (Throwable $e) { $brain = []; }
    }
    $knowledge = [];
    if (function_exists('search_knowledge')) {
        try {
            $seen = [];
            foreach (search_knowledge($query, $user, 10) as $row) {
                if (!is_array($row)) continue;
                $id = (int)($row['id'] ?? 0); $text = trim((string)($row['chunk_text'] ?? $row['description'] ?? ''));
                if ($id < 1 || isset($seen[$id]) || $text === '') continue;
                $seen[$id] = true;
                $knowledge[] = ['id'=>$id,'source'=>(string)($row['knowledge_scope'] ?? 'shared'),'title'=>transcription_app_clean_v300((string)($row['title'] ?? 'Knowledge item'),180),'text'=>mb_strimwidth($text,0,1400,'…')];
                if (count($knowledge) >= 8) break;
            }
        } catch (Throwable $e) { $knowledge = []; }
    }
    return ['brain'=>$brain,'knowledge'=>$knowledge];
}

function transcription_app_prompt_v300(array $apps, array $tags, array $pages, array $context): string
{
    $registry = transcription_app_registry_v300();
    $instructions = [];
    foreach ($apps as $id) if (isset($registry[$id]) && $registry[$id]['execution'] === 'ai') $instructions[] = $id . ': ' . $registry[$id]['prompt'];
    return "Run selected transcription analysis apps over a private transcript. LIVE TRANSCRIPT PAGE ANALYSES are primary evidence. AGENT BRAIN and KNOWLEDGE are authorized context but may be stale; use them only for comparison and never attribute them to the live transcript. External research is not included in this prompt. Do not invent identities, quotes, dates, owners, commitments or certainty.\n\nReturn ONLY JSON in this shape:\n{\"apps\":{\"APP_ID\":{...result object...}},\"research_queries\":[\"short public topic\"]}\nInclude every selected APP_ID exactly once. research_queries may contain at most 6 short public topics that materially need current verification and must never expose private context.\n\nSELECTED APP CONTRACTS:\n" . implode("\n", $instructions) . "\n\nTRANSCRIPT TAGS:\n" . json_encode($tags, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE) . "\n\nLIVE TRANSCRIPT PAGE ANALYSES:\n" . json_encode($pages, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE) . "\n\nAUTHORIZED AGENT BRAIN CONTEXT:\n" . json_encode($context['brain'] ?? [], JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE) . "\n\nAUTHORIZED KNOWLEDGE CONTEXT:\n" . json_encode($context['knowledge'] ?? [], JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
}

function transcription_app_research_gate_v300(array $existing, array $queries, array $tags, int $words): array
{
    $queries = array_values(array_slice(array_filter(array_map(static fn($x): string => transcription_app_clean_v300((string)$x,220), is_array($queries)?$queries:[])),0,6));
    $nt = array_map('mb_strtolower', $tags); sort($nt);
    $nq = array_map('mb_strtolower', $queries); sort($nq);
    $meta = is_array($existing['meta'] ?? null) ? $existing['meta'] : [];
    $oldTags = is_array($meta['tags'] ?? null) ? array_map('mb_strtolower', $meta['tags']) : []; sort($oldTags);
    $newTags = array_values(array_diff($nt, $oldTags));
    $hash = sha1(implode('|', $nq)); $oldHash = (string)($meta['query_hash'] ?? '');
    $delta = max(0, $words - (int)($meta['source_word_count'] ?? 0));
    $hasResearch = trim((string)($existing['text'] ?? '')) !== '';
    $due = false; $trigger = 'not_due';
    if ($queries && !$hasResearch) { $due=true; $trigger='initial_topics'; }
    elseif ($queries && $newTags) { $due=true; $trigger='new_tags'; }
    elseif ($queries && $oldHash !== '' && !hash_equals($oldHash,$hash) && $delta >= 250) { $due=true; $trigger='new_topics'; }
    elseif ($queries && $delta >= 600) { $due=true; $trigger='word_block'; }
    return ['due'=>$due,'trigger'=>$trigger,'queries'=>$queries,'tags'=>$tags,'query_hash'=>$hash,'source_word_count'=>$words,'delta_words'=>$delta,'new_tags'=>$newTags];
}

function transcription_app_analyze_v300(PDO $pdo, array $user, int $sessionId, string $mode, bool $researchOn, array $requestedApps): array
{
    $registry = transcription_app_registry_v300();
    $apps = transcription_app_ids_v300($requestedApps);
    $session = artist_listening_v172_session($pdo, $user, $sessionId);
    $segments = artist_listening_v172_segments($pdo, $sessionId);
    $map = artist_listening_transcript_page_map($segments);
    $tags = transcription_app_tags_v300($session);
    $status = artist_listening_v237_analysis_status($pdo, $sessionId, $map);
    $master = is_array($status['master'] ?? null) ? $status['master'] : null;
    $analysis = is_array($master['analysis'] ?? null) ? $master['analysis'] : [];
    $modules = transcription_app_modules_v300($analysis, $master);
    $words = (int)$map['total_words'];

    if ($mode === 'live') {
        if (!$master && $words < 120) return ['skipped'=>true,'reason'=>'minimum_context'] + transcription_app_status_v300(null,(string)$map['source_hash']) + ['permissions'=>transcription_app_permissions_v300($user)];
        $allFresh = true;
        foreach ($apps as $id) {
            $module = $modules[$id] ?? null;
            if (!is_array($module) || !hash_equals((string)$map['source_hash'], (string)($module['source_hash'] ?? ''))) { $allFresh=false; break; }
        }
        $delta = max(0, $words - max(array_map(static fn($id): int => (int)($modules[$id]['source_word_count'] ?? 0), $apps)));
        if ($allFresh && $delta < 250) return ['skipped'=>true,'reason'=>'block_not_due'] + transcription_app_status_v300($master,(string)$map['source_hash']) + ['permissions'=>transcription_app_permissions_v300($user)];
    }

    $provider = (string)($master['provider'] ?? 'vp3'); $model = (string)($master['model'] ?? 'deterministic');
    if (in_array('stats',$apps,true)) {
        $modules['stats'] = ['app_id'=>'stats','source_hash'=>(string)$map['source_hash'],'source_word_count'=>$words,'generated_at'=>gmdate('c'),'provider'=>'vp3','model'=>'deterministic-stats-v300','result'=>transcription_app_stats_v300($segments,$session,$map)];
    }

    $aiApps = array_values(array_filter($apps, static fn(string $id): bool => ($registry[$id]['execution'] ?? '') === 'ai'));
    $researchQueries = [];
    $brain = []; $knowledge = [];
    if ($aiApps) {
        foreach ($map['pages'] as $page) {
            $number = (int)$page['page_number']; $saved = $status['pages'][(string)$number] ?? null;
            if (is_array($saved) && !empty($saved['fresh'])) continue;
            if ((int)$page['word_count'] < 1) continue;
            try {
                if ((int)$page['word_count'] >= 120) artist_listening_v237_analyze_page($pdo,$user,$sessionId,$number,true);
                elseif ($mode === 'manual') {
                    $ai = artist_listening_v237_ai(artist_listening_transcript_page_prompt($page,artist_listening_v237_participants($session)),$user,1400);
                    $json = json_encode(artist_listening_v237_parse_analysis((string)$ai['answer']),JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
                    if (is_string($json)) {
                        $stmt=$pdo->prepare('INSERT INTO artist_transcript_page_analysis_v237 (session_id,page_number,source_hash,source_word_count,start_segment_index,end_segment_index,analysis_json,provider,model,generated_at) VALUES (?,?,?,?,?,?,?,?,?,NOW()) ON DUPLICATE KEY UPDATE source_hash=VALUES(source_hash),source_word_count=VALUES(source_word_count),start_segment_index=VALUES(start_segment_index),end_segment_index=VALUES(end_segment_index),analysis_json=VALUES(analysis_json),provider=VALUES(provider),model=VALUES(model),generated_at=NOW()');
                        $stmt->execute([$sessionId,$number,(string)$page['source_hash'],(int)$page['word_count'],(int)$page['start_segment_index'],(int)$page['end_segment_index'],$json,(string)$ai['provider'],(string)$ai['model']]);
                    }
                }
            } catch (Throwable $e) { if ($mode === 'manual') throw $e; }
            if ($mode === 'live') break;
        }
        $status = artist_listening_v237_analysis_status($pdo,$sessionId,$map);
        $pages=[];
        foreach ($map['pages'] as $page) {
            $saved=$status['pages'][(string)$page['page_number']]??null;
            if (is_array($saved)&&!empty($saved['fresh'])) $pages[]=['page'=>(int)$page['page_number'],'analysis'=>$saved['analysis']];
        }
        if (!$pages) throw new RuntimeException('There is not enough saved transcript analysis to run the selected apps yet.');
        $terms=$tags;
        foreach ($pages as $page) {
            foreach (['key_points','decisions','action_items','open_questions','research_queries'] as $key) foreach ((array)($page['analysis'][$key]??[]) as $item) $terms[]=(string)$item;
        }
        $query=implode(' · ',array_slice(array_values(array_filter(array_map(static fn($x): string=>transcription_app_clean_v300((string)$x,160),$terms))),0,16));
        $context=transcription_app_context_v300($user,$query); $brain=$context['brain']; $knowledge=$context['knowledge'];
        $ai=artist_listening_v237_ai(transcription_app_prompt_v300($aiApps,$tags,$pages,$context),$user,3800);
        $decoded=transcription_app_decode_json_v300((string)$ai['answer']);
        $returned=is_array($decoded['apps']??null)?$decoded['apps']:[];
        foreach ($aiApps as $id) {
            if (!isset($returned[$id]) || !is_array($returned[$id])) throw new RuntimeException('The AI provider omitted the ' . $registry[$id]['title'] . ' result. No transcription app results were overwritten.');
        }
        foreach ($aiApps as $id) {
            $modules[$id]=['app_id'=>$id,'source_hash'=>(string)$map['source_hash'],'source_word_count'=>$words,'generated_at'=>gmdate('c'),'provider'=>(string)$ai['provider'],'model'=>(string)$ai['model'],'result'=>transcription_app_sanitize_value_v300($returned[$id])];
        }
        $provider=(string)$ai['provider']; $model=(string)$ai['model'];
        $researchQueries=is_array($decoded['research_queries']??null)?$decoded['research_queries']:[];
    }

    $oldResearch=is_array($master['research']??null)?$master['research']:[];
    $gate=transcription_app_research_gate_v300($oldResearch,$researchQueries,$tags,$words);
    $research=$oldResearch;
    if ($researchOn && $gate['due']) {
        $research=artist_listening_v237_research($gate['queries'],$user);
        $research['meta']=$gate+['generated_at'=>gmdate('c')];
    } elseif ($research && !isset($research['meta'])) $research['meta']=$gate+['generated_at'=>(string)($master['generated_at']??'')];

    $projection=transcription_app_compat_projection_v300($modules);
    $analysisJson=json_encode($projection,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
    $researchJson=json_encode($research,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
    if (!is_string($analysisJson)||!is_string($researchJson)) throw new RuntimeException('Could not encode transcription app results.');
    $freshPages=0; foreach (($status['pages']??[]) as $row) if (!empty($row['fresh'])) $freshPages++;
    $stmt=$pdo->prepare('INSERT INTO artist_transcript_master_analysis_v237 (session_id,source_hash,source_word_count,page_count,analyzed_page_count,analysis_json,research_json,provider,model,generated_at) VALUES (?,?,?,?,?,?,?,?,?,NOW()) ON DUPLICATE KEY UPDATE source_hash=VALUES(source_hash),source_word_count=VALUES(source_word_count),page_count=VALUES(page_count),analyzed_page_count=VALUES(analyzed_page_count),analysis_json=VALUES(analysis_json),research_json=VALUES(research_json),provider=VALUES(provider),model=VALUES(model),generated_at=NOW()');
    $stmt->execute([$sessionId,(string)$map['source_hash'],$words,(int)$map['page_count'],$freshPages,$analysisJson,$researchJson,$provider,$model]);
    $latest=artist_listening_v237_analysis_status($pdo,$sessionId,$map);
    return ['skipped'=>false,'requested_apps'=>$apps,'research_gate'=>$gate,'context'=>['agent_brain_items'=>count($brain),'knowledge_items'=>count($knowledge)]] + transcription_app_status_v300($latest['master']??null,(string)$map['source_hash']) + ['permissions'=>transcription_app_permissions_v300($user)];
}

function transcription_app_report_text_v300(array $master, array $session, array $tags, string $currentHash): string
{
    $modules=transcription_app_modules_v300(is_array($master['analysis']??null)?$master['analysis']:[],$master);
    $registry=transcription_app_registry_v300();
    $lines=['Transcription Analysis: '.((string)($session['title']??'')?:('Transcript #'.(int)$session['id']))];
    if ($tags) $lines[]='Tags: '.implode(', ',$tags);
    foreach ($modules as $id=>$module) {
        if (!isset($registry[$id]) || $currentHash==='' || !hash_equals($currentHash,(string)($module['source_hash']??''))) continue;
        $result=is_array($module['result']??null)?$module['result']:[];
        $lines[]=$registry[$id]['title'].":\n".mb_strimwidth(json_encode($result,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)?:'',0,6000,'…');
    }
    $research=is_array($master['research']??null)?$master['research']:[];
    if (trim((string)($research['text']??''))!=='') $lines[]="External research:\n".$research['text'];
    return mb_strimwidth(implode("\n\n",$lines),0,20000,'…');
}
