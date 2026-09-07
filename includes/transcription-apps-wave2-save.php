<?php
declare(strict_types=1);

function transcription_app_report_text_current_v301(
    array $master,
    array $session,
    array $tags,
    string $currentHash,
    array $appStatus
): string {
    $modules = transcription_app_modules_v301(is_array($master['analysis'] ?? null) ? $master['analysis'] : [], $master);
    $registry = transcription_app_registry_v301();
    $lines = ['Transcription Intelligence: '.((string)($session['title'] ?? '') ?: ('Transcript #'.(int)$session['id']))];
    if ($tags) $lines[] = 'Tags: '.implode(', ', $tags);

    foreach ($modules as $id=>$module) {
        if (!isset($registry[$id])) continue;
        if (empty($appStatus[$id]['generated']) || empty($appStatus[$id]['fresh'])) continue;
        if ($currentHash === '' || !hash_equals($currentHash, (string)($module['source_hash'] ?? ''))) continue;
        $result = is_array($module['result'] ?? null) ? $module['result'] : [];
        $encoded = json_encode($result, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
        if (!is_string($encoded) || $encoded === '' || $encoded === '[]') continue;
        $lines[] = $registry[$id]['title'] . ":\n" . mb_strimwidth($encoded, 0, 6000, '…');
    }

    $research = is_array($master['research'] ?? null) ? $master['research'] : [];
    $researchBriefCurrent = !empty($appStatus['research_brief']['fresh']);
    if ($researchBriefCurrent && trim((string)($research['text'] ?? '')) !== '') {
        $lines[] = "External public research:\n" . mb_strimwidth((string)$research['text'], 0, 6000, '…');
    }

    return mb_strimwidth(implode("\n\n", $lines), 0, 30000, '…');
}

function transcription_app_finalize_research_brief_v301(
    PDO $pdo,
    array $user,
    array $session,
    array $analysisResult,
    bool $researchOn
): array {
    if (!$researchOn || !in_array('research_brief', (array)($analysisResult['executed_apps'] ?? []), true)) {
        return $analysisResult;
    }
    $master = is_array($analysisResult['master'] ?? null) ? $analysisResult['master'] : null;
    if (!$master) return $analysisResult;

    $research = is_array($master['research'] ?? null) ? $master['research'] : [];
    if (trim((string)($research['text'] ?? '')) !== '') return $analysisResult;

    $modules = transcription_app_modules_v301(is_array($master['analysis'] ?? null) ? $master['analysis'] : [], $master);
    if (!isset($modules['research_brief']) || !is_array($modules['research_brief'])) return $analysisResult;

    $message = trim((string)($research['error'] ?? ''));
    if ($message === '') $message = 'Public research did not return a usable result. Run Research again or refine the transcript topics.';
    $modules['research_brief']['result'] = [
        'overview'=>[['summary'=>transcription_app_clean_v300($message,1200),'status'=>'unavailable']],
        'findings'=>[],
        'sources'=>[],
        'unresolved'=>[['item'=>'Public verification is incomplete.','reason'=>'No usable public research result was returned.','next_check'=>'Run Research again after the transcript contains clearer public topics.']],
    ];
    $modules['research_brief']['generated_at'] = gmdate('c');
    $modules['research_brief']['provider'] = (string)($modules['research_brief']['provider'] ?? 'vp3');
    $modules['research_brief']['model'] = (string)($modules['research_brief']['model'] ?? 'research-unavailable');
    $modules['research_brief']['context_hash'] = transcription_app_context_hash_v301($research['meta'] ?? $research);

    $projection = transcription_app_compat_projection_v300($modules);
    $projection['registry_version'] = 301;
    $json = json_encode($projection, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
    if (!is_string($json)) throw new RuntimeException('Could not normalize the Research Brief result.');
    $pdo->prepare('UPDATE artist_transcript_master_analysis_v237 SET analysis_json=? WHERE session_id=?')
        ->execute([$json, (int)$session['id']]);

    $segments = artist_listening_v172_segments($pdo, (int)$session['id']);
    $map = artist_listening_transcript_page_map($segments);
    $latest = artist_listening_v237_analysis_status($pdo, (int)$session['id'], $map);
    $view = transcription_app_status_v301($pdo,$user,$session,$latest['master'] ?? null,$map);
    $analysisResult['master'] = $view['master'];
    $analysisResult['app_status'] = $view['app_status'];
    $analysisResult['registry'] = $view['registry'];
    return $analysisResult;
}
