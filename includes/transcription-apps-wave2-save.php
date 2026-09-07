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
