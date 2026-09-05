<?php
declare(strict_types=1);

/**
 * Profile Agent bridge for transcription intelligence saved to the owner's
 * canonical Agent Brain. The existing Knowledge Access policy is the single
 * sharing boundary for both My Knowledge and transcript-analysis memories.
 *
 * We intentionally expose only explicit transcript_analysis memories here,
 * never the owner's general chat archive, edit ledger, tool history, or other
 * private Agent Brain internals.
 */
function profile_agent_transcript_brain_context_v255(
    PDO $pdo,
    array $ownerUser,
    array $agent,
    ?array $viewer,
    string $query,
    int $conversationId = 0
): array {
    $ownerId = max(0, (int)($ownerUser['id'] ?? 0));
    if ($ownerId < 1
        || !personal_capability_has_v242('agent_brain.access', $ownerUser)
        || !agent_brain_schema_ready()
        || !table_exists('agent_memory_items')) {
        return [];
    }

    $principal = user_agent_principal_v236($viewer, $agent, true);
    $terms = function_exists('chat_policy_terms_v236') ? chat_policy_terms_v236($query) : [];

    try {
        $stmt = $pdo->prepare(
            "SELECT id,memory_type,subject,memory_text,metadata_json,last_seen_at
             FROM agent_memory_items
             WHERE user_id=? AND is_active=1 AND memory_type='transcript_analysis'
             ORDER BY last_seen_at DESC,id DESC
             LIMIT 80"
        );
        $stmt->execute([$ownerId]);
        $rows = $stmt->fetchAll() ?: [];
    } catch (Throwable $e) {
        return [];
    }

    $context = [];
    foreach ($rows as $row) {
        $memoryId = max(0, (int)($row['id'] ?? 0));
        $text = trim((string)($row['memory_text'] ?? ''));
        if ($memoryId < 1 || $text === '') continue;

        $metadata = json_decode((string)($row['metadata_json'] ?? ''), true);
        if (!is_array($metadata)) $metadata = [];
        $sessionId = max(0, (int)($metadata['session_id'] ?? 0));
        $sourceTitle = trim((string)($metadata['title'] ?? ''));
        $title = $sourceTitle !== ''
            ? 'Transcript analysis · ' . $sourceTitle
            : 'Transcript analysis';

        $haystack = mb_strtolower($title . ' ' . (string)($row['subject'] ?? '') . ' ' . $text);
        if ($terms) {
            $matched = false;
            foreach ($terms as $term) {
                if (str_contains($haystack, mb_strtolower((string)$term))) {
                    $matched = true;
                    break;
                }
            }
            if (!$matched) continue;
        }

        // Reuse the canonical Knowledge Access policy. This makes both
        // "Add to My Knowledge" and "Add to Agent Brain" obey the same
        // explicit Profile Agent sharing switch + audience boundary.
        $brainResourceId = 'brain:' . $memoryId;
        if (!user_data_policy_can_use_v236(
            $pdo,
            $principal,
            $ownerId,
            'knowledge',
            $brainResourceId,
            false
        )) {
            continue;
        }

        // If the same AI Summary was also saved into My Knowledge and that
        // item is approved for this visitor, My Knowledge remains the shared
        // source of truth so the Profile Agent does not receive duplicates.
        if ($sessionId > 0 && column_exists('knowledge_items', 'knowledge_scope')) {
            try {
                $marker = 'personal-' . sha1('artist-listening-analysis:' . $sessionId) . '.txt';
                $knowledge = $pdo->prepare(
                    "SELECT id FROM knowledge_items
                     WHERE created_by_user_id=? AND knowledge_scope='personal'
                       AND file_type='personal_note' AND file_name=?
                     LIMIT 1"
                );
                $knowledge->execute([$ownerId, $marker]);
                $knowledgeId = max(0, (int)$knowledge->fetchColumn());
                if ($knowledgeId > 0 && user_data_policy_can_use_v236(
                    $pdo,
                    $principal,
                    $ownerId,
                    'knowledge',
                    (string)$knowledgeId,
                    false
                )) {
                    continue;
                }
            } catch (Throwable $e) {
                // A lookup failure must not bypass the policy check above.
            }
        }

        $source = 'agent-brain:transcript-analysis:' . $memoryId;
        $context[] = [
            'source' => $source,
            'title' => mb_strimwidth($title, 0, 190, '…'),
            'text' => mb_strimwidth($text, 0, 6000, '…'),
        ];

        if (function_exists('user_data_usage_log_v236')) {
            user_data_usage_log_v236(
                $pdo,
                $principal,
                $ownerId,
                'knowledge',
                $brainResourceId,
                $title,
                $source,
                $conversationId
            );
        }

        if (count($context) >= 6) break;
    }

    return $context;
}
