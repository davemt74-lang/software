<?php
declare(strict_types=1);

/**
 * VP3 transcription intelligence item layer v302.
 *
 * Converts plugin array rows into durable intelligence objects without adding
 * another database table. Item review/edit state lives inside the existing
 * master-analysis module JSON and survives equivalent plugin reruns.
 */
const VP3_TRANSCRIPTION_INTELLIGENCE_ITEMS_V302 = 'transcription-intelligence-items-v302-20260906';

function transcription_intelligence_primary_v302(string $appId, string $sectionKey): string
{
    $registry = transcription_app_registry_v301();
    foreach ((array)($registry[$appId]['sections'] ?? []) as $section) {
        if ((string)($section['key'] ?? '') === $sectionKey) {
            return trim((string)($section['primary'] ?? 'text')) ?: 'text';
        }
    }
    return 'text';
}

function transcription_intelligence_evidence_refs_v302(array $item): array
{
    $pages = [];
    $addPage = static function (mixed $value) use (&$pages): void {
        if (!is_numeric($value)) return;
        $page = max(0, (int)$value);
        if ($page > 0) $pages[$page] = $page;
    };

    $addPage($item['page'] ?? null);
    foreach ((array)($item['pages'] ?? []) as $page) $addPage($page);

    $evidence = $item['evidence'] ?? '';
    $texts = is_array($evidence) ? $evidence : [$evidence];
    foreach ($texts as $text) {
        if (is_array($text)) $text = $text['text'] ?? $text['page'] ?? '';
        $text = trim((string)$text);
        if ($text === '') continue;
        if (preg_match_all('/\bpages?\s*#?\s*(\d+)(?:\s*[-–]\s*(\d+))?/iu', $text, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $start = max(1, (int)($match[1] ?? 0));
                $end = max($start, (int)($match[2] ?? $start));
                $end = min($end, $start + 12);
                for ($page = $start; $page <= $end; $page++) $pages[$page] = $page;
            }
        }
    }

    ksort($pages);
    return array_map(static fn(int $page): array => ['page'=>$page,'label'=>'Page '.$page], array_values($pages));
}

function transcription_intelligence_source_fingerprint_v302(
    string $appId,
    string $sectionKey,
    array $item,
    string $primary
): string {
    $existing = trim((string)($item['source_fingerprint'] ?? ''));
    if ($existing !== '') return $existing;

    $text = transcription_app_clean_v300((string)($item[$primary] ?? $item['text'] ?? $item['value'] ?? ''), 1400);
    $evidence = $item['evidence'] ?? '';
    if (is_array($evidence)) $evidence = json_encode($evidence, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE) ?: '';
    $basis = mb_strtolower($appId.'|'.$sectionKey.'|'.$text.'|'.transcription_app_clean_v300((string)$evidence, 1000));
    return hash('sha256', $basis);
}

function transcription_intelligence_review_index_v302(?array $master): array
{
    if (!$master) return [];
    $analysis = is_array($master['analysis'] ?? null) ? $master['analysis'] : [];
    $modules = transcription_app_modules_v301($analysis, $master);
    $registry = transcription_app_registry_v301();
    $index = [];

    foreach ($modules as $appId=>$module) {
        if (!isset($registry[$appId])) continue;
        $result = is_array($module['result'] ?? null) ? $module['result'] : [];
        foreach ((array)($registry[$appId]['sections'] ?? []) as $section) {
            $sectionKey = (string)($section['key'] ?? '');
            $primary = (string)($section['primary'] ?? 'text');
            foreach ((array)($result[$sectionKey] ?? []) as $item) {
                if (!is_array($item)) continue;
                $fingerprint = transcription_intelligence_source_fingerprint_v302($appId,$sectionKey,$item,$primary);
                $index[$fingerprint] = [
                    'item_id'=>(string)($item['item_id'] ?? ''),
                    'review_state'=>(string)($item['review_state'] ?? 'unreviewed'),
                    'reviewed_at'=>(string)($item['reviewed_at'] ?? ''),
                    'user_edited'=>!empty($item['user_edited']),
                    'edited_text'=>(string)($item['edited_text'] ?? ''),
                ];
            }
        }
    }
    return $index;
}

function transcription_intelligence_normalize_modules_v302(array $modules, array $priorReviewIndex = []): array
{
    $registry = transcription_app_registry_v301();
    foreach ($modules as $appId=>&$module) {
        if (!isset($registry[$appId]) || !is_array($module)) continue;
        $result = is_array($module['result'] ?? null) ? $module['result'] : [];
        foreach ((array)($registry[$appId]['sections'] ?? []) as $section) {
            $sectionKey = (string)($section['key'] ?? '');
            if ($sectionKey === '' || !is_array($result[$sectionKey] ?? null)) continue;
            $primary = trim((string)($section['primary'] ?? 'text')) ?: 'text';
            foreach ($result[$sectionKey] as $offset=>&$item) {
                if (!is_array($item)) continue;
                $fingerprint = transcription_intelligence_source_fingerprint_v302($appId,$sectionKey,$item,$primary);
                $prior = $priorReviewIndex[$fingerprint] ?? [];
                $itemId = trim((string)($item['item_id'] ?? $prior['item_id'] ?? ''));
                if ($itemId === '') $itemId = 'ti_'.substr($fingerprint,0,20);
                $state = strtolower(trim((string)($item['review_state'] ?? $prior['review_state'] ?? 'unreviewed')));
                if (!in_array($state,['unreviewed','accepted','rejected'],true)) $state = 'unreviewed';

                $item['item_id'] = $itemId;
                $item['plugin_id'] = $appId;
                $item['section_key'] = $sectionKey;
                $item['source_fingerprint'] = $fingerprint;
                $item['review_state'] = $state;
                $item['reviewed_at'] = (string)($item['reviewed_at'] ?? $prior['reviewed_at'] ?? '');
                $item['user_edited'] = !empty($item['user_edited']) || !empty($prior['user_edited']);
                $edited = trim((string)($item['edited_text'] ?? $prior['edited_text'] ?? ''));
                if ($item['user_edited'] && $edited !== '') {
                    $item['edited_text'] = $edited;
                    $item[$primary] = $edited;
                } else {
                    unset($item['edited_text']);
                }
                $item['evidence_refs'] = transcription_intelligence_evidence_refs_v302($item);
                $result[$sectionKey][$offset] = $item;
            }
            unset($item);
        }
        $module['result'] = $result;
    }
    unset($module);
    return $modules;
}

function transcription_intelligence_projection_v302(array $modules): array
{
    $projection = transcription_app_compat_projection_v300($modules);
    $projection['registry_version'] = 302;
    $projection['intelligence_items_version'] = 302;
    return $projection;
}

function transcription_intelligence_persist_modules_v302(PDO $pdo, int $sessionId, array $modules): array
{
    $projection = transcription_intelligence_projection_v302($modules);
    $json = json_encode($projection, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
    if (!is_string($json)) throw new RuntimeException('Could not encode transcription intelligence items.');
    $stmt = $pdo->prepare('UPDATE artist_transcript_master_analysis_v237 SET analysis_json=? WHERE session_id=?');
    $stmt->execute([$json,$sessionId]);
    if ($stmt->rowCount() < 1) {
        $check = $pdo->prepare('SELECT session_id FROM artist_transcript_master_analysis_v237 WHERE session_id=? LIMIT 1');
        $check->execute([$sessionId]);
        if (!$check->fetchColumn()) throw new RuntimeException('The transcription analysis could not be updated.');
    }
    return $projection;
}

function transcription_intelligence_normalize_master_v302(
    PDO $pdo,
    int $sessionId,
    array $master,
    array $priorReviewIndex = [],
    bool $persist = true
): array {
    $analysis = is_array($master['analysis'] ?? null) ? $master['analysis'] : [];
    $modules = transcription_app_modules_v301($analysis,$master);
    $modules = transcription_intelligence_normalize_modules_v302($modules,$priorReviewIndex);
    $projection = transcription_intelligence_projection_v302($modules);
    if ($persist && $projection !== $analysis) transcription_intelligence_persist_modules_v302($pdo,$sessionId,$modules);
    $master['analysis'] = $projection;
    return $master;
}

function transcription_intelligence_find_item_v302(array &$modules, string $appId, string $itemId): array
{
    $registry = transcription_app_registry_v301();
    if (!isset($registry[$appId],$modules[$appId])) throw new RuntimeException('Transcription plugin result not found.');
    $result = is_array($modules[$appId]['result'] ?? null) ? $modules[$appId]['result'] : [];
    foreach ((array)($registry[$appId]['sections'] ?? []) as $section) {
        $sectionKey = (string)($section['key'] ?? '');
        foreach ((array)($result[$sectionKey] ?? []) as $offset=>$item) {
            if (is_array($item) && hash_equals((string)($item['item_id'] ?? ''),$itemId)) {
                return [$sectionKey,(int)$offset,(string)($section['primary'] ?? 'text')];
            }
        }
    }
    throw new RuntimeException('Transcription intelligence item not found.');
}

function transcription_intelligence_review_item_v302(
    PDO $pdo,
    int $sessionId,
    array $master,
    string $appId,
    string $itemId,
    string $reviewState
): array {
    $reviewState = strtolower(trim($reviewState));
    if (!in_array($reviewState,['unreviewed','accepted','rejected'],true)) throw new RuntimeException('Choose accepted, rejected or unreviewed.');
    $modules = transcription_app_modules_v301(is_array($master['analysis'] ?? null)?$master['analysis']:[],$master);
    $modules = transcription_intelligence_normalize_modules_v302($modules);
    [$sectionKey,$offset] = transcription_intelligence_find_item_v302($modules,$appId,$itemId);
    $modules[$appId]['result'][$sectionKey][$offset]['review_state'] = $reviewState;
    $modules[$appId]['result'][$sectionKey][$offset]['reviewed_at'] = $reviewState === 'unreviewed' ? '' : gmdate('c');
    $master['analysis'] = transcription_intelligence_persist_modules_v302($pdo,$sessionId,$modules);
    return $master;
}

function transcription_intelligence_edit_item_v302(
    PDO $pdo,
    int $sessionId,
    array $master,
    string $appId,
    string $itemId,
    string $text
): array {
    $text = transcription_app_clean_v300($text,1400);
    if ($text === '') throw new RuntimeException('Edited intelligence text cannot be empty.');
    $modules = transcription_app_modules_v301(is_array($master['analysis'] ?? null)?$master['analysis']:[],$master);
    $modules = transcription_intelligence_normalize_modules_v302($modules);
    [$sectionKey,$offset,$primary] = transcription_intelligence_find_item_v302($modules,$appId,$itemId);
    $item =& $modules[$appId]['result'][$sectionKey][$offset];
    $item[$primary] = $text;
    $item['edited_text'] = $text;
    $item['user_edited'] = true;
    $item['review_state'] = 'accepted';
    $item['reviewed_at'] = gmdate('c');
    $master['analysis'] = transcription_intelligence_persist_modules_v302($pdo,$sessionId,$modules);
    return $master;
}

function transcription_intelligence_export_result_v302(mixed $value): mixed
{
    if (!is_array($value)) return $value;
    $isList = array_is_list($value);
    $out = [];
    foreach ($value as $key=>$item) {
        if (is_array($item) && ($item['review_state'] ?? '') === 'rejected') continue;
        $clean = transcription_intelligence_export_result_v302($item);
        if ($isList) $out[] = $clean;
        else $out[$key] = $clean;
    }
    return $out;
}

function transcription_intelligence_report_text_v302(
    array $master,
    array $session,
    array $tags,
    string $currentHash,
    array $appStatus
): string {
    $modules = transcription_app_modules_v301(is_array($master['analysis'] ?? null)?$master['analysis']:[],$master);
    $registry = transcription_app_registry_v301();
    $lines = ['Transcription Intelligence: '.((string)($session['title'] ?? '') ?: ('Transcript #'.(int)$session['id']))];
    if ($tags) $lines[] = 'Tags: '.implode(', ',$tags);

    foreach ($modules as $id=>$module) {
        if (!isset($registry[$id])) continue;
        if (empty($appStatus[$id]['generated']) || empty($appStatus[$id]['fresh'])) continue;
        if ($currentHash === '' || !hash_equals($currentHash,(string)($module['source_hash'] ?? ''))) continue;
        $result = transcription_intelligence_export_result_v302(is_array($module['result'] ?? null)?$module['result']:[]);
        $encoded = json_encode($result,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
        if (!is_string($encoded) || $encoded === '' || $encoded === '[]') continue;
        $lines[] = $registry[$id]['title'] . ":\n" . mb_strimwidth($encoded,0,6000,'…');
    }

    $research = is_array($master['research'] ?? null) ? $master['research'] : [];
    if (!empty($appStatus['research_brief']['fresh']) && trim((string)($research['text'] ?? '')) !== '') {
        $lines[] = "External public research:\n" . mb_strimwidth((string)$research['text'],0,6000,'…');
    }
    return mb_strimwidth(implode("\n\n",$lines),0,30000,'…');
}
