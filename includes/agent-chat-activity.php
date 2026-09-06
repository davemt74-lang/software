<?php
declare(strict_types=1);

/**
 * Canonical operational activity bridge.
 *
 * Agent Chat is the durable operational inbox. Existing subsystem ledgers stay
 * authoritative; this bridge converts meaningful rows from those ledgers into
 * deduplicated notifications which the existing attention pipeline persists in
 * Chat. No parallel activity table is introduced here.
 */

function agent_chat_activity_text(string $value, int $max = 500): string
{
    $value = trim(preg_replace('/\s+/u', ' ', $value) ?? $value);
    return mb_strimwidth($value, 0, max(20, $max), '…');
}

function agent_chat_activity_tool_is_meaningful(string $toolKey, string $status): bool
{
    $toolKey = strtolower(trim($toolKey));
    $status = strtolower(trim($status));
    if ($status !== '' && !in_array($status, ['success','ok','completed'], true)) return true;
    if ($toolKey === '') return false;

    // Read-only searches remain available in Agent Brain history without
    // flooding the operational inbox. Mutations, generated work, skill/workflow
    // execution and external effects are durable Chat activity.
    return (bool)preg_match(
        '/(?:edit|create|update|save|publish|release|book|send|message|upload|import|restore|render|export|transcrib|summar|knowledge|memory|profile|crm|contact|collab|invite|approve|delete|remove|record|capture|generate|write|skill|workflow|automation)/',
        $toolKey
    );
}

function agent_chat_activity_notify(
    array $user,
    string $kind,
    string $title,
    string $body = '',
    string $targetUrl = '',
    string $sourceType = 'agent_activity',
    ?int $sourceId = null,
    ?string $createdAt = null
): void {
    $userId = max(0, (int)($user['id'] ?? 0));
    if ($userId < 1 || !has_permission('chat.access', $user) || !table_exists('notifications')) return;

    $kind = preg_replace('/[^a-z0-9_\-]/', '_', strtolower(trim($kind))) ?: 'update';
    create_notification(
        $userId,
        'agent_activity_' . mb_substr($kind, 0, 34),
        agent_chat_activity_text($title, 190),
        agent_chat_activity_text($body, 500),
        mb_substr(trim($targetUrl), 0, 500),
        mb_substr(trim($sourceType), 0, 80),
        $sourceId,
        $createdAt
    );
}

function agent_chat_activity_safe_target(string $candidate): string
{
    $candidate = trim($candidate);
    if ($candidate === '') return '';
    if (str_starts_with($candidate, '/') && !str_starts_with($candidate, '//')) return $candidate;
    if (!filter_var($candidate, FILTER_VALIDATE_URL)) return '';
    $scheme = strtolower((string)parse_url($candidate, PHP_URL_SCHEME));
    return in_array($scheme, ['http','https'], true) ? $candidate : '';
}

function agent_chat_activity_tool_target(string $toolKey, array $result): string
{
    foreach (['open_url','target_url','url'] as $field) {
        $candidate = agent_chat_activity_safe_target((string)($result[$field] ?? ''));
        if ($candidate !== '') return $candidate;
    }

    $projectId = max(0, (int)($result['project_id'] ?? $result['track_id'] ?? 0));
    if ($projectId > 0 && str_contains(strtolower($toolKey), 'stem')) {
        return url('/admin/stems.php?track=' . $projectId);
    }
    if (str_contains(strtolower($toolKey), 'transcrib') || str_contains(strtolower($toolKey), 'listening')) {
        $sessionId = max(0, (int)($result['session_id'] ?? 0));
        return url('/artist-listening.php' . ($sessionId > 0 ? '?session=' . $sessionId : ''));
    }
    return '';
}

function agent_chat_activity_reconcile_tools(PDO $pdo, array $user): void
{
    if (!table_exists('agent_tool_history')) return;
    $uid = (int)$user['id'];
    try {
        $stmt = $pdo->prepare(
            "SELECT h.id,h.tool_key,h.request_text,h.status,h.result_json,h.created_at
             FROM agent_tool_history h
             WHERE h.user_id=? AND h.created_at>=DATE_SUB(NOW(),INTERVAL 14 DAY)
               AND NOT EXISTS (
                 SELECT 1 FROM notifications n
                 WHERE n.user_id=h.user_id AND n.source_type='agent_tool_history' AND n.source_id=h.id
               )
             ORDER BY h.id DESC LIMIT 8"
        );
        $stmt->execute([$uid]);
        foreach ($stmt->fetchAll() ?: [] as $row) {
            $toolKey = (string)($row['tool_key'] ?? '');
            $status = (string)($row['status'] ?? '');
            if (!agent_chat_activity_tool_is_meaningful($toolKey, $status)) continue;
            // Stem edit events get a richer direct record below.
            if ($toolKey === 'stem.edit_ledger') continue;

            $result = json_decode((string)($row['result_json'] ?? ''), true);
            if (!is_array($result)) $result = [];
            $label = trim(ucwords(str_replace(['.','_','-'], ' ', $toolKey))) ?: 'Agent Tool';
            $failed = !in_array(strtolower($status), ['success','ok','completed'], true);
            $request = agent_chat_activity_text((string)($row['request_text'] ?? ''), 340);
            $body = $request !== '' ? $request : ($failed ? 'This tool run needs review.' : 'The agent completed this tool run.');
            agent_chat_activity_notify(
                $user,
                $failed ? 'tool_failed' : 'tool_completed',
                ($failed ? 'Agent Tool Needs Review · ' : 'Agent Tool Completed · ') . $label,
                $body,
                agent_chat_activity_tool_target($toolKey, $result),
                'agent_tool_history',
                (int)$row['id'],
                (string)($row['created_at'] ?? '')
            );
        }
    } catch (Throwable $e) {
        error_log('Agent Chat tool activity reconcile failed: ' . $e->getMessage());
    }
}

function agent_chat_activity_reconcile_stem(PDO $pdo, array $user): void
{
    if (!table_exists('agent_edit_events')) return;
    $uid = (int)$user['id'];
    try {
        $stmt = $pdo->prepare(
            "SELECT e.id,e.project_id,e.action_key,e.request_text,e.model_provider,e.model_name,e.changes_json,e.created_at
             FROM agent_edit_events e
             WHERE e.user_id=? AND e.editor_kind='stem' AND e.source_kind='agent'
               AND e.created_at>=DATE_SUB(NOW(),INTERVAL 14 DAY)
               AND NOT EXISTS (
                 SELECT 1 FROM notifications n
                 WHERE n.user_id=e.user_id AND n.source_type='agent_edit_event' AND n.source_id=e.id
               )
             ORDER BY e.id DESC LIMIT 10"
        );
        $stmt->execute([$uid]);
        foreach ($stmt->fetchAll() ?: [] as $row) {
            $trackId = max(0, (int)($row['project_id'] ?? 0));
            $trackTitle = '';
            if ($trackId > 0 && table_exists('tracks')) {
                $trackStmt = $pdo->prepare('SELECT title FROM tracks WHERE id=? LIMIT 1');
                $trackStmt->execute([$trackId]);
                $trackTitle = trim((string)$trackStmt->fetchColumn());
            }
            $changes = json_decode((string)($row['changes_json'] ?? ''), true);
            $changeCount = is_array($changes) ? count($changes) : 0;
            $request = agent_chat_activity_text((string)($row['request_text'] ?? ''), 330);
            $action = agent_chat_activity_text(str_replace(['_','.'], ' ', (string)($row['action_key'] ?? 'edit')), 90);
            $detail = $request !== '' ? $request : ('Completed ' . ($action !== '' ? $action : 'a production edit') . '.');
            if ($changeCount > 0) $detail .= ' · ' . $changeCount . ' tracked change' . ($changeCount === 1 ? '' : 's') . '.';
            $model = trim((string)($row['model_name'] ?? ''));
            if ($model !== '') $detail .= ' · ' . $model;

            agent_chat_activity_notify(
                $user,
                'stem_edit',
                'Stem Editor · Agent Edit Completed' . ($trackTitle !== '' ? ' · ' . $trackTitle : ''),
                $detail,
                $trackId > 0 ? url('/admin/stems.php?track=' . $trackId) : '',
                'agent_edit_event',
                (int)$row['id'],
                (string)($row['created_at'] ?? '')
            );
        }
    } catch (Throwable $e) {
        error_log('Agent Chat Stem activity reconcile failed: ' . $e->getMessage());
    }
}

function agent_chat_activity_reconcile_transcriptions(PDO $pdo, array $user): void
{
    $uid = (int)$user['id'];

    if (table_exists('artist_transcript_master_analysis_v237') && table_exists('artist_transcript_sessions_v172')) {
        try {
            $stmt = $pdo->prepare(
                "SELECT a.session_id,a.source_word_count,a.page_count,a.analyzed_page_count,a.provider,a.model,a.generated_at,
                        s.title,s.status
                 FROM artist_transcript_master_analysis_v237 a
                 INNER JOIN artist_transcript_sessions_v172 s ON s.id=a.session_id
                 WHERE s.created_by_user_id=? AND a.generated_at>=DATE_SUB(NOW(),INTERVAL 14 DAY)
                   AND NOT EXISTS (
                     SELECT 1 FROM notifications n
                     WHERE n.user_id=s.created_by_user_id AND n.source_type='transcript_analysis' AND n.source_id=a.session_id
                   )
                 ORDER BY a.generated_at DESC LIMIT 6"
            );
            $stmt->execute([$uid]);
            foreach ($stmt->fetchAll() ?: [] as $row) {
                $sessionId = (int)$row['session_id'];
                $title = trim((string)($row['title'] ?? '')) ?: ('Transcript #' . $sessionId);
                $words = max(0, (int)($row['source_word_count'] ?? 0));
                $pages = max(0, (int)($row['analyzed_page_count'] ?? $row['page_count'] ?? 0));
                $model = trim((string)($row['model'] ?? ''));
                $body = 'AI Summary is ready';
                if ($words > 0) $body .= ' · ' . number_format($words) . ' words';
                if ($pages > 0) $body .= ' · ' . $pages . ' analyzed page' . ($pages === 1 ? '' : 's');
                if ($model !== '') $body .= ' · ' . $model;
                agent_chat_activity_notify(
                    $user,
                    'transcription_summary',
                    'Transcription · AI Summary Ready · ' . $title,
                    $body,
                    url('/artist-listening.php?session=' . $sessionId),
                    'transcript_analysis',
                    $sessionId,
                    (string)($row['generated_at'] ?? '')
                );
            }
        } catch (Throwable $e) {
            error_log('Agent Chat transcription analysis reconcile failed: ' . $e->getMessage());
        }
    }

    if (table_exists('agent_memory_items')) {
        try {
            $stmt = $pdo->prepare(
                "SELECT m.id,m.subject,m.metadata_json,m.last_seen_at
                 FROM agent_memory_items m
                 WHERE m.user_id=? AND m.is_active=1 AND m.memory_type='transcript_analysis'
                   AND m.last_seen_at>=DATE_SUB(NOW(),INTERVAL 14 DAY)
                   AND NOT EXISTS (
                     SELECT 1 FROM notifications n
                     WHERE n.user_id=m.user_id AND n.source_type='agent_memory_item' AND n.source_id=m.id
                   )
                 ORDER BY m.last_seen_at DESC,m.id DESC LIMIT 6"
            );
            $stmt->execute([$uid]);
            foreach ($stmt->fetchAll() ?: [] as $row) {
                $meta = json_decode((string)($row['metadata_json'] ?? ''), true);
                if (!is_array($meta)) $meta = [];
                $sessionId = max(0, (int)($meta['session_id'] ?? 0));
                $title = trim((string)($meta['title'] ?? '')) ?: ($sessionId > 0 ? 'Transcript #' . $sessionId : 'Transcript analysis');
                agent_chat_activity_notify(
                    $user,
                    'brain_saved',
                    'Agent Brain · Transcription Saved · ' . $title,
                    'The transcription analysis is now part of your Agent Brain memory.',
                    $sessionId > 0 ? url('/artist-listening.php?session=' . $sessionId) : '',
                    'agent_memory_item',
                    (int)$row['id'],
                    (string)($row['last_seen_at'] ?? '')
                );
            }
        } catch (Throwable $e) {
            error_log('Agent Chat transcription memory reconcile failed: ' . $e->getMessage());
        }
    }

    if (table_exists('knowledge_items') && column_exists('knowledge_items', 'created_by_user_id') && column_exists('knowledge_items', 'knowledge_scope')) {
        try {
            $stmt = $pdo->prepare(
                "SELECT k.id,k.title,k.description,k.updated_at
                 FROM knowledge_items k
                 WHERE k.created_by_user_id=? AND k.knowledge_scope='personal'
                   AND k.description LIKE 'Personal Artist Listening analysis%'
                   AND k.updated_at>=DATE_SUB(NOW(),INTERVAL 14 DAY)
                   AND NOT EXISTS (
                     SELECT 1 FROM notifications n
                     WHERE n.user_id=k.created_by_user_id AND n.source_type='personal_knowledge_item' AND n.source_id=k.id
                   )
                 ORDER BY k.updated_at DESC,k.id DESC LIMIT 6"
            );
            $stmt->execute([$uid]);
            foreach ($stmt->fetchAll() ?: [] as $row) {
                $title = trim((string)($row['title'] ?? '')) ?: 'Transcript Analysis';
                agent_chat_activity_notify(
                    $user,
                    'knowledge_saved',
                    'My Knowledge · Transcription Saved · ' . $title,
                    'The analysis is indexed in your private My Knowledge library and can follow your existing sharing policy.',
                    url('/knowledge.php?edit=' . (int)$row['id'] . '#knowledge-form'),
                    'personal_knowledge_item',
                    (int)$row['id'],
                    (string)($row['updated_at'] ?? '')
                );
            }
        } catch (Throwable $e) {
            error_log('Agent Chat transcription knowledge reconcile failed: ' . $e->getMessage());
        }
    }
}

function agent_chat_activity_reconcile_proactive(PDO $pdo, array $user): void
{
    if (!table_exists('agent_proactive_events')) return;
    $uid = (int)$user['id'];
    try {
        $stmt = $pdo->prepare(
            "SELECT p.id,p.event_type,p.title,p.prompt,p.source_kind,p.context_json,p.created_at
             FROM agent_proactive_events p
             WHERE p.user_id=? AND p.event_type='shown' AND p.created_at>=DATE_SUB(NOW(),INTERVAL 7 DAY)
               AND NOT EXISTS (
                 SELECT 1 FROM notifications n
                 WHERE n.user_id=p.user_id AND n.source_type='agent_proactive_event' AND n.source_id=p.id
               )
             ORDER BY p.id DESC LIMIT 8"
        );
        $stmt->execute([$uid]);
        foreach ($stmt->fetchAll() ?: [] as $row) {
            $title = trim((string)($row['title'] ?? ''));
            $prompt = trim((string)($row['prompt'] ?? ''));
            if ($title === '' || $prompt === '') continue;
            $source = trim((string)($row['source_kind'] ?? ''));
            agent_chat_activity_notify(
                $user,
                'opportunity',
                'Agent Opportunity · ' . $title,
                $prompt . ($source !== '' ? ' · Source: ' . str_replace('_', ' ', $source) : ''),
                url('/chat.php'),
                'agent_proactive_event',
                (int)$row['id'],
                (string)($row['created_at'] ?? '')
            );
        }
    } catch (Throwable $e) {
        error_log('Agent Chat proactive activity reconcile failed: ' . $e->getMessage());
    }
}

function agent_chat_activity_reconcile(?array $user = null): void
{
    static $done = [];
    $user ??= current_user();
    $uid = max(0, (int)($user['id'] ?? 0));
    if ($uid < 1 || isset($done[$uid]) || !has_permission('chat.access', $user)) return;
    $done[$uid] = true;

    $pdo = db();
    if (!$pdo || !table_exists('notifications')) return;

    // Reconcile authoritative ledgers into the one existing notification → Chat
    // delivery path. Each query excludes source rows already promoted, so the
    // 5-second Chat poll stays cheap after the initial catch-up.
    agent_chat_activity_reconcile_stem($pdo, $user);
    agent_chat_activity_reconcile_transcriptions($pdo, $user);
    agent_chat_activity_reconcile_tools($pdo, $user);
    agent_chat_activity_reconcile_proactive($pdo, $user);
}
