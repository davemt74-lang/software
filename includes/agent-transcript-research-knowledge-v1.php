<?php
declare(strict_types=1);

require_once __DIR__.'/agent-initiative-scope-v1.php';
require_once __DIR__.'/knowledge-retrieval-v162.php';
require_once __DIR__.'/knowledge.php';
if (!function_exists('personal_capability_has_strict_v1')) require_once __DIR__.'/personal-capabilities-strict-v1.php';
if (!function_exists('user_data_policy_can_use_v236')) require_once __DIR__.'/user-agent-system-v236.php';

/** Project links for an already verified recording and, when supplied, its stored plugin item. */
function vp3_transcript_research_knowledge_v1(PDO $pdo, array $scope, array $binding, int $sessionId, ?array $item = null): array
{
    $user = $scope['user'] ?? [];
    $principal = $scope['principal'] ?? [];
    $owner = (int)($user['id'] ?? 0);
    $agentId = $scope['agent_id'] ?? null;
    $conversationId = $scope['conversation_id'] ?? null;
    $knowledge = vp3_initiative_knowledge_scope_v1($binding['knowledge_scope'] ?? null);
    if ($sessionId < 1 || $knowledge['mode'] === 'off' || !is_int($agentId) || $agentId < 0
        || !is_int($conversationId) || $conversationId < 1 || ($binding['conversation_id'] ?? null) !== $conversationId
        || ($binding['agent_id'] ?? 0) !== $agentId || ($binding['owner_user_id'] ?? $owner) !== $owner
        || ($principal['kind'] ?? null) !== ($agentId > 0 ? 'user_agent' : 'system')
        || (function_exists('vp3_participant_scope_v1') && vp3_participant_scope_v1() !== null)
        || ($principal['agent_id'] ?? 0) !== $agentId || !knowledge_retrieval_v162_owner_session($user, $principal)) {
        return [];
    }
    if (!empty($scope['verification_only'])) {
        $current = function_exists('vp3_initiative_policy_v1') ? vp3_initiative_policy_v1($user, $agentId) : [];
        if ($knowledge !== vp3_initiative_knowledge_scope_v1($current['knowledge_scope'] ?? null)) return [];
    }
    if (!table_exists('knowledge_items') || !column_exists('knowledge_items', 'folder_id')
        || !column_exists('knowledge_items', 'knowledge_scope') || !personal_capability_has_strict_v1('personal_knowledge.access', $user)) {
        return [];
    }

    $keys = ['transcript'=>'artist-listening-transcript:'.$sessionId, 'report'=>'artist-listening-analysis:'.$sessionId];
    $receipt = $item['actions']['personal_knowledge'] ?? [];
    $recordId = $receipt['record_id'] ?? null;
    $itemId = $item['item_id'] ?? null;
    $origin = $receipt['source'] ?? null;
    if (is_int($recordId) && $recordId > 0 && is_string($itemId) && $itemId !== '' && strlen($itemId) <= 190
        && ($origin === null || (is_array($origin) && ($origin['session_id'] ?? null) === $sessionId
            && ($origin['plugin_id'] ?? null) === ($item['plugin'] ?? null)
            && ($origin['section_key'] ?? null) === ($item['section'] ?? null)
            && ($origin['item_id'] ?? null) === $itemId))) {
        $keys['finding'] = 'transcription-intelligence-item:'.$itemId;
    }

    $candidates = [];
    foreach ($keys as $kind => $key) {
        $saved = personal_knowledge_note_by_key($pdo, $user, $key, true);
        if ($saved && ($kind !== 'finding' || (int)$saved['id'] === $recordId)) {
            $candidates[] = ['kind'=>$kind] + $saved;
        }
    }
    $prefix = personal_knowledge_transcript_highlight_prefix_v1($sessionId);
    $folderFilter = $knowledge['mode'] === 'folder' ? ' AND folder_id=?' : '';
    $highlights = $pdo->prepare("SELECT id,folder_id,file_name FROM knowledge_items
        WHERE created_by_user_id=? AND knowledge_scope='personal' AND file_type='personal_note' AND file_name LIKE ?
        $folderFilter ORDER BY id DESC LIMIT 12");
    $highlights->execute($folderFilter === '' ? [$owner, $prefix.'%'] : [$owner, $prefix.'%', $knowledge['folder_id']]);
    foreach ($highlights->fetchAll(PDO::FETCH_ASSOC) as $saved) {
        if (preg_match('/\A'.preg_quote($prefix, '/').'[a-f0-9]{64}\.txt\z/D', $saved['file_name'])) {
            $candidates[] = ['kind'=>'highlight'] + $saved;
        }
    }

    $links = [];
    $folders = [];
    $highlightCount = 0;
    foreach ($candidates as $saved) {
        $kind = $saved['kind'];
        $id = (int)($saved['id'] ?? 0);
        $folderId = (int)($saved['folder_id'] ?? 0);
        if ($kind === 'highlight' && $highlightCount >= 2) break;
        if ($id < 1 || isset($links[$id])
            || ($knowledge['mode'] === 'folder' && $folderId !== $knowledge['folder_id'])
            || ($agentId > 0 && !user_data_policy_can_use_v236($pdo, $principal, $owner, 'knowledge', (string)$id, false))) {
            continue;
        }
        if ($folderId > 0) {
            if (!array_key_exists($folderId, $folders)) $folders[$folderId] = personal_knowledge_folder($pdo, $user, $folderId);
            if (!$folders[$folderId]) continue;
        }
        $query = http_build_query(['folder'=>$folderId > 0 ? $folderId : 'unfiled', 'edit'=>$id]);
        $links[$id] = ['kind'=>$kind, 'knowledge_id'=>$id, 'folder_id'=>$folderId,
            'folder_name'=>$folders[$folderId]['folder_name'] ?? 'Unfiled', 'url'=>url('/knowledge.php?'.$query.'#knowledge-form')];
        if ($kind === 'highlight') ++$highlightCount;
    }
    return array_values($links);
}
