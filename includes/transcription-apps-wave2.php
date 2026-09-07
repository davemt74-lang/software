<?php
declare(strict_types=1);

/**
 * VP3 transcription intelligence · wave two
 *
 * Adds higher-order analysis plugins on top of the stable v300 registry while
 * preserving one independently persisted result per plugin. The public API
 * remains api/artist-listening-intelligence-v300.php for compatibility.
 */
const VP3_TRANSCRIPTION_APPS_WAVE2_VERSION = 1;
const VP3_TRANSCRIPTION_APP_BATCH_SIZE_V301 = 4;
const VP3_TRANSCRIPTION_RESEARCH_TTL_SECONDS_V301 = 86400;

function transcription_app_wave2_registry_v301(): array
{
    return [
        'qa' => [
            'id'=>'qa','label'=>'Q&A','title'=>'Questions & Answers','description'=>'Tracks questions, answers, unresolved questions and follow-up needs.','execution'=>'ai','live'=>true,'view'=>'sections',
            'sections'=>[
                ['key'=>'answered','title'=>'Answered questions','primary'=>'question','meta'=>['asked_by','answer','answered_by','evidence','confidence']],
                ['key'=>'unanswered','title'=>'Unanswered questions','primary'=>'question','meta'=>['asked_by','why_open','follow_up','evidence','confidence']],
            ],
            'legacy'=>[],
            'prompt'=>'Questions & Answers result object: answered is an array of {question,asked_by,answer,answered_by,evidence,confidence}; unanswered is an array of {question,asked_by,why_open,follow_up,evidence,confidence}. Only classify a question as answered when the transcript contains a responsive answer. Preserve uncertainty and use unknown for unsupported participant identity. Evidence identifies transcript page(s).',
        ],
        'requirements' => [
            'id'=>'requirements','label'=>'Requirements','title'=>'Requirements & Constraints','description'=>'Extracts requirements, acceptance criteria, dependencies and non-negotiable constraints.','execution'=>'ai','live'=>true,'view'=>'sections',
            'sections'=>[
                ['key'=>'requirements','title'=>'Requirements','primary'=>'requirement','meta'=>['type','owner','acceptance_criteria','priority','evidence','confidence']],
                ['key'=>'constraints','title'=>'Constraints','primary'=>'constraint','meta'=>['type','impact','owner','evidence','confidence']],
                ['key'=>'dependencies','title'=>'Dependencies','primary'=>'dependency','meta'=>['status','owner','evidence','confidence']],
            ],
            'legacy'=>[],
            'prompt'=>'Requirements & Constraints result object: requirements is an array of {requirement,type,owner,acceptance_criteria,priority,evidence,confidence}; constraints is an array of {constraint,type,impact,owner,evidence,confidence}; dependencies is an array of {dependency,status,owner,evidence,confidence}. Extract explicit or strongly supported requirements only. acceptance_criteria may be empty when not stated. Never convert a suggestion into a requirement. priority is high, medium, low or unknown.',
        ],
        'followup' => [
            'id'=>'followup','label'=>'Follow-up','title'=>'Follow-up Tracker','description'=>'Turns commitments, actions and unanswered questions into a follow-up ledger.','execution'=>'ai','live'=>true,'view'=>'sections',
            'sections'=>[
                ['key'=>'items','title'=>'Follow-up items','primary'=>'follow_up','meta'=>['source_type','owner','timing','status','priority','evidence','confidence']],
                ['key'=>'waiting_on','title'=>'Waiting on','primary'=>'item','meta'=>['person','trigger','evidence','confidence']],
            ],
            'legacy'=>[],
            'prompt'=>'Follow-up Tracker result object: items is an array of {follow_up,source_type,owner,timing,status,priority,evidence,confidence}; waiting_on is an array of {item,person,trigger,evidence,confidence}. source_type is action, commitment, unanswered_question, dependency or other. status is open, waiting, scheduled, completed or unknown. Include only follow-up work supported by the transcript; do not invent deadlines or owners.',
        ],
        'opportunities' => [
            'id'=>'opportunities','label'=>'Opportunities','title'=>'Opportunity Finder','description'=>'Surfaces grounded product, commercial, content, relationship and process opportunities.','execution'=>'ai','live'=>true,'view'=>'sections',
            'sections'=>[
                ['key'=>'items','title'=>'Opportunities','primary'=>'opportunity','meta'=>['type','why_it_matters','validation_step','potential_value','evidence','confidence']],
            ],
            'legacy'=>[],
            'prompt'=>'Opportunity Finder result object: items is an array of {opportunity,type,why_it_matters,validation_step,potential_value,evidence,confidence}. type is product, commercial, content, relationship, workflow, research or other. Opportunities may be hypotheses but must be grounded in transcript evidence. validation_step must describe how to test the opportunity rather than presenting speculation as fact. potential_value is high, medium, low or unknown.',
        ],
        'changes' => [
            'id'=>'changes','label'=>'Changes','title'=>'Comparison / Change Report','description'=>'Compares this transcript with explicitly related prior transcripts and authorized context.','execution'=>'ai','live'=>false,'view'=>'sections',
            'sections'=>[
                ['key'=>'new','title'=>'New','primary'=>'change','meta'=>['compared_to','evidence','confidence']],
                ['key'=>'changed','title'=>'Changed','primary'=>'change','meta'=>['before','after','compared_to','evidence','confidence']],
                ['key'=>'contradicted','title'=>'Contradicted','primary'=>'change','meta'=>['prior','current','compared_to','evidence','confidence']],
                ['key'=>'resolved','title'=>'Resolved','primary'=>'change','meta'=>['compared_to','evidence','confidence']],
                ['key'=>'still_open','title'=>'Still open','primary'=>'change','meta'=>['compared_to','evidence','confidence']],
            ],
            'legacy'=>[],
            'prompt'=>'Comparison / Change Report result object: new, changed, contradicted, resolved and still_open are arrays. Use {change,compared_to,evidence,confidence} for new/resolved/still_open; use {change,before,after,compared_to,evidence,confidence} for changed; use {change,prior,current,compared_to,evidence,confidence} for contradicted. Compare only against the explicitly supplied RELATED PRIOR TRANSCRIPTS or authorized context. If there is no related prior transcript context, return empty arrays rather than inventing a baseline.',
        ],
        'crm' => [
            'id'=>'crm','label'=>'CRM','title'=>'CRM Intelligence','description'=>'Relates transcript signals to explicitly matched CRM contacts without changing CRM records.','execution'=>'ai','live'=>false,'view'=>'sections',
            'sections'=>[
                ['key'=>'status','title'=>'CRM match','primary'=>'message','meta'=>['state']],
                ['key'=>'matched_contacts','title'=>'Matched contacts','primary'=>'name','meta'=>['company','email','lead_stage','priority','next_follow_up']],
                ['key'=>'signals','title'=>'Relationship signals','primary'=>'signal','meta'=>['contact','type','evidence','confidence']],
                ['key'=>'recommended_updates','title'=>'Recommended CRM updates','primary'=>'update','meta'=>['contact','field','reason','evidence','confidence']],
            ],
            'legacy'=>[],
            'prompt'=>'CRM Intelligence result object: status is an array with one {message,state}; matched_contacts is an array of {name,company,email,lead_stage,priority,next_follow_up}; signals is an array of {signal,contact,type,evidence,confidence}; recommended_updates is an array of {update,contact,field,reason,evidence,confidence}. Use only contacts supplied in EXPLICIT CRM CONTEXT, which were matched by stored IDs or exact email address. Never infer identity from a name alone. Do not claim CRM records were changed; recommendations are advisory only.',
        ],
        'research_brief' => [
            'id'=>'research_brief','label'=>'Research','title'=>'Research Brief','description'=>'Builds a source-aware public research brief while keeping transcript/private context separate.','execution'=>'ai','live'=>false,'view'=>'sections',
            'sections'=>[
                ['key'=>'overview','title'=>'Research brief','primary'=>'summary','meta'=>['status']],
                ['key'=>'findings','title'=>'Verified findings','primary'=>'finding','meta'=>['verification','source_numbers','confidence']],
                ['key'=>'sources','title'=>'Sources','primary'=>'title','meta'=>['url']],
                ['key'=>'unresolved','title'=>'Unresolved / verify next','primary'=>'item','meta'=>['reason','next_check']],
            ],
            'legacy'=>[],
            'prompt'=>'Research Brief planning result object: overview is an array containing one {summary,status}; findings, sources and unresolved should be empty during planning. Generate global research_queries for the small number of public facts or topics that materially need current verification. Never put private names, private contact information, confidential project details or personal context into research_queries.',
        ],
        'stance' => [
            'id'=>'stance','label'=>'Stance','title'=>'Sentiment & Stance','description'=>'Maps expressed support, opposition, uncertainty and position changes by topic without psychological profiling.','execution'=>'ai','live'=>true,'view'=>'sections',
            'sections'=>[
                ['key'=>'stances','title'=>'Topic stances','primary'=>'position','meta'=>['topic','participant','stance','tone','evidence','confidence']],
                ['key'=>'changes','title'=>'Stance changes','primary'=>'change','meta'=>['topic','participant','before','after','evidence','confidence']],
            ],
            'legacy'=>[],
            'prompt'=>'Sentiment & Stance result object: stances is an array of {topic,participant,position,stance,tone,evidence,confidence}; changes is an array of {topic,participant,change,before,after,evidence,confidence}. stance is support, oppose, mixed, uncertain or neutral. tone may describe directly observable conversational tone but must not diagnose personality, mental state or emotion beyond what is explicitly expressed. Analyze stance toward specific topics, not people as a whole.',
        ],
    ];
}

function transcription_app_registry_v301(): array
{
    return array_replace(transcription_app_registry_v300(), transcription_app_wave2_registry_v301());
}

function transcription_app_registry_public_v301(): array
{
    $out = [];
    foreach (transcription_app_registry_v301() as $app) {
        $out[] = [
            'id'=>$app['id'],'label'=>$app['label'],'title'=>$app['title'],'description'=>$app['description'],
            'execution'=>$app['execution'],'live'=>(bool)$app['live'],'view'=>$app['view'],'sections'=>$app['sections'],
        ];
    }
    return $out;
}

function transcription_app_ids_v301(mixed $value): array
{
    $registry = transcription_app_registry_v301();
    if (!is_array($value)) return ['basic'];
    $out = [];
    foreach ($value as $item) {
        $id = strtolower(trim((string)$item));
        if (isset($registry[$id]) && !in_array($id, $out, true)) $out[] = $id;
    }
    return $out ?: ['basic'];
}

function transcription_app_modules_v301(array $analysis, ?array $master = null): array
{
    $modules = transcription_app_modules_v300($analysis, $master);
    $stored = is_array($analysis['modules'] ?? null) ? $analysis['modules'] : [];
    foreach (transcription_app_wave2_registry_v301() as $id=>$app) {
        $module = $stored[$id] ?? null;
        if (!is_array($module)) continue;
        $result = is_array($module['result'] ?? null) ? transcription_app_sanitize_value_v300($module['result']) : [];
        $modules[$id] = [
            'app_id'=>$id,
            'source_hash'=>(string)($module['source_hash'] ?? $master['source_hash'] ?? ''),
            'source_word_count'=>max(0, (int)($module['source_word_count'] ?? $master['word_count'] ?? 0)),
            'generated_at'=>(string)($module['generated_at'] ?? $master['generated_at'] ?? ''),
            'provider'=>(string)($module['provider'] ?? $master['provider'] ?? ''),
            'model'=>(string)($module['model'] ?? $master['model'] ?? ''),
            'contract_version'=>max(1, (int)($module['contract_version'] ?? 1)),
            'context_hash'=>(string)($module['context_hash'] ?? ''),
            'result'=>is_array($result) ? $result : [],
        ];
    }
    return $modules;
}

function transcription_app_prior_context_v301(PDO $pdo, array $user, array $session): array
{
    $sessionId = max(0, (int)($session['id'] ?? 0));
    $userId = max(0, (int)($user['id'] ?? 0));
    $trackId = max(0, (int)($session['project_track_id'] ?? 0));
    $conversationId = max(0, (int)($session['conversation_id'] ?? 0));
    if ($sessionId < 1 || $userId < 1 || ($trackId < 1 && $conversationId < 1)) return [];

    $relations = [];
    $params = [$userId, $sessionId];
    if ($trackId > 0) { $relations[] = 's.project_track_id=?'; $params[] = $trackId; }
    if ($conversationId > 0) { $relations[] = 's.conversation_id=?'; $params[] = $conversationId; }
    if (!$relations) return [];

    try {
        $sql = "SELECT s.id,s.title,s.updated_at,s.project_track_id,s.conversation_id,
                       m.source_hash,m.source_word_count,m.analysis_json,m.generated_at
                FROM artist_transcript_sessions_v172 s
                JOIN artist_transcript_master_analysis_v237 m ON m.session_id=s.id
                WHERE s.created_by_user_id=? AND s.id<>? AND s.status<>'discarded'
                  AND (" . implode(' OR ', $relations) . ")
                ORDER BY s.updated_at DESC,s.id DESC LIMIT 4";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $out = [];
        foreach ($stmt->fetchAll() ?: [] as $row) {
            $analysis = json_decode((string)($row['analysis_json'] ?? ''), true);
            if (!is_array($analysis)) continue;
            $master = [
                'source_hash'=>(string)($row['source_hash'] ?? ''),'word_count'=>(int)($row['source_word_count'] ?? 0),
                'generated_at'=>(string)($row['generated_at'] ?? ''),'provider'=>'','model'=>'','analysis'=>$analysis,
            ];
            $modules = transcription_app_modules_v301($analysis, $master);
            $basic = is_array($modules['basic']['result'] ?? null) ? $modules['basic']['result'] : [];
            $summary = transcription_app_clean_v300((string)($basic['summary'] ?? $basic['analysis'] ?? ''), 1600);
            if ($summary === '') continue;
            $out[] = [
                'session_id'=>(int)$row['id'],'title'=>transcription_app_clean_v300((string)$row['title'],190),
                'generated_at'=>(string)$row['generated_at'],'relation'=>$trackId > 0 && (int)$row['project_track_id'] === $trackId ? 'same_project' : 'same_conversation',
                'summary'=>$summary,
            ];
        }
        return $out;
    } catch (Throwable $e) {
        return [];
    }
}

function transcription_app_crm_context_v301(PDO $pdo, array $user, array $session, array $segments): array
{
    if (!function_exists('crm_v180_can_manage') || !function_exists('crm_v180_schema_ready') || !crm_v180_can_manage($user) || !crm_v180_schema_ready($pdo)) {
        return ['available'=>false,'matched'=>false,'contacts'=>[],'reason'=>'CRM is unavailable for this account.'];
    }

    $metadata = json_decode((string)($session['metadata_json'] ?? ''), true);
    $metadata = is_array($metadata) ? $metadata : [];
    $association = is_array($metadata['association'] ?? null) ? $metadata['association'] : [];
    $contactIds = [];
    $leadIds = [];
    foreach ([$metadata['crm_contact_id'] ?? 0, $association['crm_contact_id'] ?? 0, $association['contact_id'] ?? 0] as $id) {
        $id = max(0, (int)$id); if ($id > 0) $contactIds[$id] = $id;
    }
    foreach ([$metadata['crm_lead_id'] ?? 0, $association['crm_lead_id'] ?? 0, $association['lead_id'] ?? 0] as $id) {
        $id = max(0, (int)$id); if ($id > 0) $leadIds[$id] = $id;
    }

    $emails = [];
    foreach ($segments as $segment) {
        if (!is_array($segment) || (string)($segment['segment_type'] ?? 'transcript') !== 'transcript') continue;
        $text = (string)($segment['transcript_text'] ?? '');
        if (preg_match_all('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/iu', $text, $matches)) {
            foreach ($matches[0] as $email) {
                $email = strtolower(trim((string)$email));
                if (filter_var($email, FILTER_VALIDATE_EMAIL)) $emails[$email] = $email;
                if (count($emails) >= 10) break 2;
            }
        }
    }

    if (!$contactIds && !$leadIds && !$emails) {
        return ['available'=>true,'matched'=>false,'contacts'=>[],'reason'=>'No explicit CRM contact ID, lead ID or exact email address is linked to this transcript.'];
    }

    try {
        $where = [];
        $params = [];
        if ($contactIds) {
            $where[] = 'c.id IN (' . implode(',', array_fill(0, count($contactIds), '?')) . ')';
            array_push($params, ...array_values($contactIds));
        }
        if ($emails) {
            $where[] = 'c.email_normalized IN (' . implode(',', array_fill(0, count($emails), '?')) . ')';
            array_push($params, ...array_values($emails));
        }
        if ($leadIds) {
            $where[] = 'EXISTS (SELECT 1 FROM crm_leads lx WHERE lx.contact_id=c.id AND lx.id IN (' . implode(',', array_fill(0, count($leadIds), '?')) . '))';
            array_push($params, ...array_values($leadIds));
        }
        if (!$where) return ['available'=>true,'matched'=>false,'contacts'=>[],'reason'=>'No explicit CRM match was available.'];

        $stmt = $pdo->prepare('SELECT c.id,c.name,c.email,c.company FROM crm_contacts c WHERE ' . implode(' OR ', $where) . ' ORDER BY c.updated_at DESC,c.id DESC LIMIT 5');
        $stmt->execute($params);
        $contacts = [];
        $leadStmt = $pdo->prepare('SELECT id,stage,priority,next_follow_up_at,last_contacted_at,demo_scheduled_at FROM crm_leads WHERE contact_id=? ORDER BY updated_at DESC,id DESC LIMIT 1');
        foreach ($stmt->fetchAll() ?: [] as $contact) {
            $leadStmt->execute([(int)$contact['id']]);
            $lead = $leadStmt->fetch() ?: [];
            $contacts[] = [
                'contact_id'=>(int)$contact['id'],'lead_id'=>max(0,(int)($lead['id'] ?? 0)),
                'name'=>transcription_app_clean_v300((string)$contact['name'],120),'email'=>strtolower(trim((string)$contact['email'])),
                'company'=>transcription_app_clean_v300((string)$contact['company'],190),
                'lead_stage'=>(string)($lead['stage'] ?? ''),'priority'=>(string)($lead['priority'] ?? ''),
                'next_follow_up'=>(string)($lead['next_follow_up_at'] ?? ''),'last_contacted'=>(string)($lead['last_contacted_at'] ?? ''),
                'demo_scheduled'=>(string)($lead['demo_scheduled_at'] ?? ''),
            ];
        }
        return [
            'available'=>true,'matched'=>(bool)$contacts,'contacts'=>$contacts,
            'reason'=>$contacts ? 'Matched using stored CRM IDs or exact transcript email addresses.' : 'No CRM record matched the explicit identifiers in this transcript.',
        ];
    } catch (Throwable $e) {
        return ['available'=>true,'matched'=>false,'contacts'=>[],'reason'=>'CRM context could not be loaded.'];
    }
}

function transcription_app_context_hash_v301(mixed $value): string
{
    $json = json_encode($value, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
    return hash('sha256', is_string($json) ? $json : '');
}

function transcription_app_contexts_v301(PDO $pdo, array $user, array $session, array $segments, array $apps, array $baseContext): array
{
    $needPrior = in_array('changes', $apps, true);
    $needCrm = in_array('crm', $apps, true);
    $prior = $needPrior ? transcription_app_prior_context_v301($pdo, $user, $session) : [];
    $crm = $needCrm ? transcription_app_crm_context_v301($pdo, $user, $session, $segments) : ['available'=>false,'matched'=>false,'contacts'=>[],'reason'=>'Not requested.'];
    return $baseContext + [
        'prior'=>$prior,'crm'=>$crm,
        'prior_hash'=>transcription_app_context_hash_v301($prior),
        'crm_hash'=>transcription_app_context_hash_v301($crm),
    ];
}

function transcription_app_prompt_v301(array $apps, array $tags, array $pages, array $context): string
{
    $registry = transcription_app_registry_v301();
    $instructions = [];
    foreach ($apps as $id) {
        if (isset($registry[$id]) && $registry[$id]['execution'] === 'ai') $instructions[] = $id . ': ' . $registry[$id]['prompt'];
    }
    return "Run the selected transcription intelligence plugins over a private transcript. LIVE TRANSCRIPT PAGE ANALYSES are the primary evidence. AGENT BRAIN and KNOWLEDGE are authorized private context but may be stale; use them only for comparison and never attribute them to the live transcript. RELATED PRIOR TRANSCRIPTS are included only when the system found an explicit same-project or same-conversation relationship. EXPLICIT CRM CONTEXT is included only when CRM access is authorized and a stored ID or exact transcript email matched. Do not invent identities, quotes, dates, owners, commitments, CRM matches or certainty.\n\nReturn ONLY JSON in this shape:\n{\"apps\":{\"APP_ID\":{...result object...}},\"research_queries\":[\"short public topic\"]}\nInclude every selected APP_ID exactly once. An empty result is valid when evidence is absent. research_queries may contain at most 6 short public topics that materially need current verification and must never expose private context.\n\nSELECTED PLUGIN CONTRACTS:\n" . implode("\n", $instructions) .
        "\n\nTRANSCRIPT TAGS:\n" . json_encode($tags, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE) .
        "\n\nLIVE TRANSCRIPT PAGE ANALYSES:\n" . json_encode($pages, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE) .
        "\n\nAUTHORIZED AGENT BRAIN CONTEXT:\n" . json_encode($context['brain'] ?? [], JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE) .
        "\n\nAUTHORIZED KNOWLEDGE CONTEXT:\n" . json_encode($context['knowledge'] ?? [], JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE) .
        "\n\nRELATED PRIOR TRANSCRIPTS:\n" . json_encode($context['prior'] ?? [], JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE) .
        "\n\nEXPLICIT CRM CONTEXT:\n" . json_encode($context['crm'] ?? [], JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
}

function transcription_app_research_brief_v301(array $user, array $pages, array $research): array
{
    $sources = [];
    foreach ((array)($research['sources'] ?? []) as $index=>$source) {
        if (!is_array($source)) continue;
        $url = trim((string)($source['url'] ?? ''));
        if ($url === '') continue;
        $sources[] = ['number'=>count($sources)+1,'title'=>transcription_app_clean_v300((string)($source['title'] ?? $url),220),'url'=>$url];
        if (count($sources) >= 10) break;
    }
    $text = trim((string)($research['text'] ?? ''));
    $error = trim((string)($research['error'] ?? ''));
    if ($text === '') {
        return [
            'overview'=>[['summary'=>$error !== '' ? $error : 'No external research result is available yet.','status'=>'unavailable']],
            'findings'=>[],'sources'=>$sources,'unresolved'=>[],
        ];
    }

    $prompt = "Build a source-aware Research Brief for a private transcript. The transcript page analyses establish what needs verification; PUBLIC RESEARCH is the only evidence for external factual verification. Do not turn public research into private user facts. Do not invent source URLs or source numbers. Return ONLY JSON as {\"apps\":{\"research_brief\":{\"overview\":[{\"summary\":\"...\",\"status\":\"current\"}],\"findings\":[{\"finding\":\"...\",\"verification\":\"verified|mixed|uncertain\",\"source_numbers\":[1],\"confidence\":\"high|medium|low\"}],\"unresolved\":[{\"item\":\"...\",\"reason\":\"...\",\"next_check\":\"...\"}]}}}. Use only source numbers from PUBLIC SOURCES.\n\nTRANSCRIPT PAGE ANALYSES:\n" . json_encode($pages, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE) . "\n\nPUBLIC RESEARCH:\n" . $text . "\n\nPUBLIC SOURCES:\n" . json_encode($sources, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
    $ai = artist_listening_v237_ai($prompt, $user, 2600);
    $decoded = transcription_app_decode_json_v300((string)$ai['answer']);
    $result = is_array($decoded['apps']['research_brief'] ?? null) ? transcription_app_sanitize_value_v300($decoded['apps']['research_brief']) : [];
    if (!is_array($result)) $result = [];
    $result['sources'] = array_map(static fn(array $source): array => ['title'=>$source['title'],'url'=>$source['url']], $sources);
    if (!isset($result['overview']) || !is_array($result['overview'])) $result['overview'] = [['summary'=>'Public research completed.','status'=>'current']];
    if (!isset($result['findings']) || !is_array($result['findings'])) $result['findings'] = [];
    if (!isset($result['unresolved']) || !is_array($result['unresolved'])) $result['unresolved'] = [];
    return ['result'=>$result,'provider'=>(string)$ai['provider'],'model'=>(string)$ai['model']];
}

function transcription_app_module_fresh_v301(string $id, array $module, string $currentHash, array $contextHashes): array
{
    if ($currentHash === '' || !hash_equals($currentHash, (string)($module['source_hash'] ?? ''))) return [false,'transcript_changed'];
    $storedContext = (string)($module['context_hash'] ?? '');
    if ($id === 'changes' && $storedContext !== '' && !hash_equals($storedContext, (string)($contextHashes['prior_hash'] ?? ''))) return [false,'related_context_changed'];
    if ($id === 'crm' && $storedContext !== '' && !hash_equals($storedContext, (string)($contextHashes['crm_hash'] ?? ''))) return [false,'crm_context_changed'];
    if ($id === 'research_brief') {
        $stamp = strtotime((string)($module['generated_at'] ?? '')) ?: 0;
        if ($stamp < 1 || time() - $stamp > VP3_TRANSCRIPTION_RESEARCH_TTL_SECONDS_V301) return [false,'research_age'];
    }
    return [true,'current'];
}

function transcription_app_status_v301(PDO $pdo, array $user, ?array $session, ?array $master, array $map): array
{
    $analysis = is_array($master['analysis'] ?? null) ? $master['analysis'] : [];
    $modules = transcription_app_modules_v301($analysis, $master);
    $projection = transcription_app_compat_projection_v300($modules);
    if ($master) $master['analysis'] = $projection;
    $segments = $session ? artist_listening_v172_segments($pdo, (int)$session['id']) : [];
    $prior = $session ? transcription_app_prior_context_v301($pdo, $user, $session) : [];
    $crm = $session ? transcription_app_crm_context_v301($pdo, $user, $session, $segments) : ['available'=>false,'matched'=>false,'contacts'=>[]];
    $hashes = ['prior_hash'=>transcription_app_context_hash_v301($prior),'crm_hash'=>transcription_app_context_hash_v301($crm)];
    $currentHash = (string)($map['source_hash'] ?? '');
    $status = [];
    foreach (transcription_app_registry_v301() as $id=>$app) {
        $module = $modules[$id] ?? null;
        [$fresh,$reason] = is_array($module) ? transcription_app_module_fresh_v301($id,$module,$currentHash,$hashes) : [false,'not_generated'];
        $status[$id] = [
            'generated'=>is_array($module),'fresh'=>$fresh,'fresh_reason'=>$reason,
            'generated_at'=>(string)($module['generated_at'] ?? ''),'provider'=>(string)($module['provider'] ?? ''),
            'model'=>(string)($module['model'] ?? ''),'word_count'=>max(0,(int)($module['source_word_count'] ?? 0)),
        ];
    }
    return ['master'=>$master,'app_status'=>$status,'registry'=>transcription_app_registry_public_v301()];
}

function transcription_app_analyze_v301(PDO $pdo, array $user, int $sessionId, string $mode, bool $researchOn, array $requestedApps): array
{
    $registry = transcription_app_registry_v301();
    $requested = transcription_app_ids_v301($requestedApps);
    $apps = $requested;
    if ($mode === 'live') {
        $apps = array_values(array_filter($requested, static fn(string $id): bool => !empty($registry[$id]['live'])));
        if (!$apps) {
            $session = artist_listening_v172_session($pdo,$user,$sessionId);
            $segments = artist_listening_v172_segments($pdo,$sessionId);
            $map = artist_listening_transcript_page_map($segments);
            $status = artist_listening_v237_analysis_status($pdo,$sessionId,$map);
            return ['skipped'=>true,'reason'=>'selected_plugins_manual_only','requested_apps'=>$requested] + transcription_app_status_v301($pdo,$user,$session,$status['master'] ?? null,$map) + ['permissions'=>transcription_app_permissions_v300($user)];
        }
    }

    $session = artist_listening_v172_session($pdo, $user, $sessionId);
    $segments = artist_listening_v172_segments($pdo, $sessionId);
    $map = artist_listening_transcript_page_map($segments);
    $tags = transcription_app_tags_v300($session);
    $status = artist_listening_v237_analysis_status($pdo, $sessionId, $map);
    $master = is_array($status['master'] ?? null) ? $status['master'] : null;
    $analysis = is_array($master['analysis'] ?? null) ? $master['analysis'] : [];
    $modules = transcription_app_modules_v301($analysis, $master);
    $words = (int)$map['total_words'];

    $priorForFreshness = transcription_app_prior_context_v301($pdo,$user,$session);
    $crmForFreshness = transcription_app_crm_context_v301($pdo,$user,$session,$segments);
    $freshHashes = ['prior_hash'=>transcription_app_context_hash_v301($priorForFreshness),'crm_hash'=>transcription_app_context_hash_v301($crmForFreshness)];

    if ($mode === 'live') {
        if (!$master && $words < 120) return ['skipped'=>true,'reason'=>'minimum_context','requested_apps'=>$requested] + transcription_app_status_v301($pdo,$user,$session,null,$map) + ['permissions'=>transcription_app_permissions_v300($user)];
        $allFresh = true;
        foreach ($apps as $id) {
            $module = $modules[$id] ?? null;
            if (!is_array($module) || !transcription_app_module_fresh_v301($id,$module,(string)$map['source_hash'],$freshHashes)[0]) { $allFresh=false; break; }
        }
        $reportedCounts = array_map(static fn(string $id): int => (int)($modules[$id]['source_word_count'] ?? 0), $apps);
        $delta = max(0, $words - ($reportedCounts ? max($reportedCounts) : 0));
        if ($allFresh && $delta < 250) return ['skipped'=>true,'reason'=>'block_not_due','requested_apps'=>$requested] + transcription_app_status_v301($pdo,$user,$session,$master,$map) + ['permissions'=>transcription_app_permissions_v300($user)];
    }

    $pending = [];
    if (in_array('stats',$apps,true)) {
        $pending['stats'] = [
            'app_id'=>'stats','source_hash'=>(string)$map['source_hash'],'source_word_count'=>$words,'generated_at'=>gmdate('c'),
            'provider'=>'vp3','model'=>'deterministic-stats-v300','contract_version'=>1,'context_hash'=>'',
            'result'=>transcription_app_stats_v300($segments,$session,$map),
        ];
    }

    $aiApps = array_values(array_filter($apps, static fn(string $id): bool => ($registry[$id]['execution'] ?? '') === 'ai'));
    $researchQueries = [];
    $provider = (string)($master['provider'] ?? 'vp3');
    $model = (string)($master['model'] ?? 'deterministic');
    $baseContext = ['brain'=>[],'knowledge'=>[]];
    $contexts = $baseContext + ['prior'=>$priorForFreshness,'crm'=>$crmForFreshness,'prior_hash'=>$freshHashes['prior_hash'],'crm_hash'=>$freshHashes['crm_hash']];
    $pages = [];

    if ($aiApps) {
        foreach ($map['pages'] as $page) {
            $number = (int)$page['page_number'];
            $saved = $status['pages'][(string)$number] ?? null;
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
        foreach ($map['pages'] as $page) {
            $saved = $status['pages'][(string)$page['page_number']] ?? null;
            if (is_array($saved) && !empty($saved['fresh'])) $pages[] = ['page'=>(int)$page['page_number'],'analysis'=>$saved['analysis']];
        }
        if (!$pages) throw new RuntimeException('There is not enough saved transcript analysis to run the selected plugins yet.');

        $terms = $tags;
        foreach ($pages as $page) {
            foreach (['key_points','decisions','action_items','open_questions','research_queries'] as $key) foreach ((array)($page['analysis'][$key] ?? []) as $item) $terms[] = (string)$item;
        }
        $query = implode(' · ',array_slice(array_values(array_filter(array_map(static fn($x): string => transcription_app_clean_v300((string)$x,160),$terms))),0,16));
        $baseContext = transcription_app_context_v300($user,$query);
        $contexts = transcription_app_contexts_v301($pdo,$user,$session,$segments,$apps,$baseContext);

        foreach (array_chunk($aiApps, VP3_TRANSCRIPTION_APP_BATCH_SIZE_V301) as $batch) {
            $ai = artist_listening_v237_ai(transcription_app_prompt_v301($batch,$tags,$pages,$contexts),$user,3400);
            $decoded = transcription_app_decode_json_v300((string)$ai['answer']);
            $returned = is_array($decoded['apps'] ?? null) ? $decoded['apps'] : [];
            foreach ($batch as $id) {
                if (!isset($returned[$id]) || !is_array($returned[$id])) throw new RuntimeException('The AI provider omitted the ' . $registry[$id]['title'] . ' result. No transcription plugin results were overwritten.');
            }
            foreach ($batch as $id) {
                $contextHash = $id === 'changes' ? (string)$contexts['prior_hash'] : ($id === 'crm' ? (string)$contexts['crm_hash'] : '');
                $pending[$id] = [
                    'app_id'=>$id,'source_hash'=>(string)$map['source_hash'],'source_word_count'=>$words,'generated_at'=>gmdate('c'),
                    'provider'=>(string)$ai['provider'],'model'=>(string)$ai['model'],'contract_version'=>1,'context_hash'=>$contextHash,
                    'result'=>transcription_app_sanitize_value_v300($returned[$id]),
                ];
            }
            foreach ((array)($decoded['research_queries'] ?? []) as $queryItem) {
                $clean = transcription_app_clean_v300((string)$queryItem,220);
                if ($clean !== '') $researchQueries[mb_strtolower($clean)] = $clean;
                if (count($researchQueries) >= 6) break;
            }
            $provider=(string)$ai['provider']; $model=(string)$ai['model'];
        }
    }

    $researchQueries = array_values($researchQueries);
    $oldResearch = is_array($master['research'] ?? null) ? $master['research'] : [];
    $gate = transcription_app_research_gate_v300($oldResearch,$researchQueries,$tags,$words);
    $research = $oldResearch;
    if ($researchOn && $gate['due']) {
        $research = artist_listening_v237_research($gate['queries'],$user);
        $research['meta'] = $gate + ['generated_at'=>gmdate('c')];
    } elseif ($research && !isset($research['meta'])) {
        $research['meta'] = $gate + ['generated_at'=>(string)($master['generated_at'] ?? '')];
    }

    if (in_array('research_brief',$apps,true)) {
        if (!$researchOn) {
            $pending['research_brief']['result'] = [
                'overview'=>[['summary'=>'Turn Research ON and run Analyze to build a public-source research brief.','status'=>'research_off']],
                'findings'=>[],'sources'=>[],'unresolved'=>[],
            ];
        } else {
            $brief = transcription_app_research_brief_v301($user,$pages,$research);
            if (isset($brief['result'])) {
                $pending['research_brief']['result'] = $brief['result'];
                $pending['research_brief']['provider'] = (string)($brief['provider'] ?? $pending['research_brief']['provider']);
                $pending['research_brief']['model'] = (string)($brief['model'] ?? $pending['research_brief']['model']);
                $pending['research_brief']['generated_at'] = gmdate('c');
                $pending['research_brief']['context_hash'] = transcription_app_context_hash_v301($research['meta'] ?? $research);
                $provider = (string)$pending['research_brief']['provider'];
                $model = (string)$pending['research_brief']['model'];
            }
        }
    }

    foreach ($pending as $id=>$module) $modules[$id] = $module;
    $projection = transcription_app_compat_projection_v300($modules);
    $projection['registry_version'] = 301;
    $analysisJson = json_encode($projection,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
    $researchJson = json_encode($research,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
    if (!is_string($analysisJson) || !is_string($researchJson)) throw new RuntimeException('Could not encode transcription plugin results.');
    $freshPages = 0;
    foreach (($status['pages'] ?? []) as $row) if (!empty($row['fresh'])) $freshPages++;
    $stmt=$pdo->prepare('INSERT INTO artist_transcript_master_analysis_v237 (session_id,source_hash,source_word_count,page_count,analyzed_page_count,analysis_json,research_json,provider,model,generated_at) VALUES (?,?,?,?,?,?,?,?,?,NOW()) ON DUPLICATE KEY UPDATE source_hash=VALUES(source_hash),source_word_count=VALUES(source_word_count),page_count=VALUES(page_count),analyzed_page_count=VALUES(analyzed_page_count),analysis_json=VALUES(analysis_json),research_json=VALUES(research_json),provider=VALUES(provider),model=VALUES(model),generated_at=NOW()');
    $stmt->execute([$sessionId,(string)$map['source_hash'],$words,(int)$map['page_count'],$freshPages,$analysisJson,$researchJson,$provider,$model]);

    $latest = artist_listening_v237_analysis_status($pdo,$sessionId,$map);
    return [
        'skipped'=>false,'requested_apps'=>$requested,'executed_apps'=>$apps,'research_gate'=>$gate,
        'context'=>['agent_brain_items'=>count($baseContext['brain'] ?? []),'knowledge_items'=>count($baseContext['knowledge'] ?? []),'prior_transcripts'=>count($contexts['prior'] ?? []),'crm_contacts'=>count($contexts['crm']['contacts'] ?? [])],
    ] + transcription_app_status_v301($pdo,$user,$session,$latest['master'] ?? null,$map) + ['permissions'=>transcription_app_permissions_v300($user)];
}

function transcription_app_report_text_v301(array $master, array $session, array $tags, string $currentHash): string
{
    $modules = transcription_app_modules_v301(is_array($master['analysis'] ?? null) ? $master['analysis'] : [],$master);
    $registry = transcription_app_registry_v301();
    $lines = ['Transcription Intelligence: '.((string)($session['title'] ?? '') ?: ('Transcript #'.(int)$session['id']))];
    if ($tags) $lines[] = 'Tags: '.implode(', ',$tags);
    foreach ($modules as $id=>$module) {
        if (!isset($registry[$id]) || $currentHash === '' || !hash_equals($currentHash,(string)($module['source_hash'] ?? ''))) continue;
        $result = is_array($module['result'] ?? null) ? $module['result'] : [];
        $encoded = json_encode($result,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
        if (!is_string($encoded) || $encoded === '' || $encoded === '[]') continue;
        $lines[] = $registry[$id]['title'] . ":\n" . mb_strimwidth($encoded,0,6000,'…');
    }
    $research = is_array($master['research'] ?? null) ? $master['research'] : [];
    if (trim((string)($research['text'] ?? '')) !== '') $lines[] = "External research:\n".$research['text'];
    return mb_strimwidth(implode("\n\n",$lines),0,30000,'…');
}
