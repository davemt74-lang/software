<?php
declare(strict_types=1);

require_once __DIR__.'/agent-connection-youtube-v1.php';
require_once __DIR__.'/agent-connection-youtube-quota-v1.php';
require_once __DIR__.'/agent-connection-youtube-consent-v1.php';
require_once __DIR__.'/agent-tool-receipts-v1.php';

function agent_connection_youtube_chat_plan_v1(string $message): ?string
{
    $message = trim($message);
    $message = preg_replace('/\A(?:please[,\s]+|(?:can|could|would) you\s+)+/iu', '', $message) ?? $message;
    if (preg_match('/\A(?:\/youtube\b|youtube\s*[:\-]|youtube\s+search\b|(?:search(?:\s+for)?|find|show|look\s+up)(?:\s+me)?\s+(?:an?\s+)?(?:(?:videos?\s+)?on\s+)?you\s*tube(?:\s+videos?)?\b)(?:\s*:\s*|\s+(?:for|about|of)\s+|\s*)(.*)\z/isu', $message, $match)
        || preg_match('/\A(?:search(?:\s+for)?|find|show|look\s+up)(?:\s+me)?\s+(.+?)\s+(?:on|from)\s+you\s*tube(?:[,\s]+please)?[.?!]*\z/isu', $message, $match)) {
        $query = preg_replace('/[.?!]+\z/u', '', trim($match[1])) ?? trim($match[1]);
        return trim(preg_replace('/\s+/u', ' ', $query) ?? $query);
    }
    return null;
}

function agent_connection_youtube_chat_authority_v1(PDO $pdo, array $user, array $principal, int $conversationId, ?callable $authority): array
{
    if ($conversationId < 1 || !knowledge_retrieval_v162_owner_session($user, $principal)
        || vp3_participant_scope_v1() !== null) {
        throw new RuntimeException('Use your own Agent Chat to search YouTube.', 403);
    }
    $live = vp3_capability_live_user_v1($pdo, $user, $principal, ['conversation_id'=>$conversationId], $authority);
    if (!has_permission('account.access', $live)) throw new RuntimeException('Account access changed. Reopen Chat.', 403);
    return $live;
}

function agent_connection_youtube_results_schema_v1(PDO $pdo): void
{
    $mysql = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
    $pdo->exec('CREATE TABLE IF NOT EXISTS agent_connection_youtube_results_v1 (
        message_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
        expires_at BIGINT UNSIGNED NOT NULL,
        payload_json TEXT NOT NULL,
        FOREIGN KEY (message_id) REFERENCES chat_messages(id) ON DELETE CASCADE'
        .($mysql ? ',
        INDEX idx_connection_youtube_results_expiry (expires_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci' : ')'));
    if (!$mysql) $pdo->exec('CREATE INDEX IF NOT EXISTS idx_connection_youtube_results_expiry ON agent_connection_youtube_results_v1 (expires_at)');
}

function agent_connection_youtube_results_ready_v1(PDO $pdo): bool
{
    try {
        $pdo->query('SELECT message_id, expires_at, payload_json FROM agent_connection_youtube_results_v1 WHERE 1=0');
        return true;
    } catch (PDOException) {
        return false;
    }
}

function agent_connection_youtube_results_cleanup_v1(PDO $pdo): int
{
    if (!agent_connection_youtube_results_ready_v1($pdo)) return 0;
    $stmt = $pdo->prepare('DELETE FROM agent_connection_youtube_results_v1 WHERE expires_at<=?');
    $stmt->execute([time()]);
    return $stmt->rowCount();
}

/** Persist public API data in expiring storage rather than the durable Chat transcript. */
function agent_connection_youtube_results_save_v1(PDO $pdo, int $ownerId, int $conversationId, int $messageId, array $results): void
{
    $stmt = $pdo->prepare("INSERT INTO agent_connection_youtube_results_v1 (message_id, expires_at, payload_json)
        SELECT m.id, ?, ? FROM chat_messages m JOIN chat_conversations c ON c.id=m.conversation_id
        WHERE m.id=? AND m.conversation_id=? AND c.user_id=? AND m.role='assistant'");
    $stmt->execute([
        $results['expires_at'], json_encode($results, JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),
        $messageId, $conversationId, $ownerId,
    ]);
}

function agent_connection_youtube_results_read_v1(PDO $pdo, int $ownerId, int $conversationId, array $messageIds): array
{
    if ($ownerId < 1 || $conversationId < 1 || !$messageIds || !agent_connection_youtube_results_ready_v1($pdo)
        || !agent_connection_youtube_enabled_v1() || !agent_connection_youtube_consent_v1($pdo, $ownerId)) return [];
    $placeholders = implode(',', array_fill(0, count($messageIds), '?'));
    $stmt = $pdo->prepare("SELECT r.message_id,r.payload_json FROM agent_connection_youtube_results_v1 r
        JOIN chat_messages m ON m.id=r.message_id
        JOIN chat_conversations c ON c.id=m.conversation_id
        WHERE c.id=? AND c.user_id=? AND r.expires_at>? AND r.message_id IN ($placeholders)");
    $stmt->execute([$conversationId, $ownerId, time(), ...$messageIds]);
    $results = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $payload = json_decode((string)$row['payload_json'], true);
        if (is_array($payload)) $results[(int)$row['message_id']] = $payload;
    }
    return $results;
}

function agent_connection_youtube_history_v1(PDO $pdo, int $ownerId, int $conversationId, array $messages): array
{
    $positions = [];
    $contexts = [];
    foreach ($messages as $index=>$message) {
        $raw = $message['context_json'] ?? '';
        if (($message['role'] ?? '') !== 'assistant' || !is_string($raw) || !str_contains($raw, '"youtube_search_results_v1"')) continue;
        $context = json_decode($raw, true);
        if (($context['youtube_search_results_v1'] ?? false) !== true) continue;
        $id = (int)$message['id'];
        $positions[$id] = $index;
        $contexts[$id] = $context;
    }
    foreach (agent_connection_youtube_results_read_v1($pdo, $ownerId, $conversationId, array_keys($positions)) as $id=>$results) {
        $contexts[$id]['youtube_results'] = $results;
        $messages[$positions[$id]]['context_json'] = json_encode($contexts[$id], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_INVALID_UTF8_SUBSTITUTE);
    }
    return $messages;
}

function agent_connection_youtube_chat_v1(PDO $pdo, array $user, array $principal, string $query, int $conversationId, ?callable $authority=null, ?callable $http=null): array
{
    $result = static function(string $answer, string $status, string $reason='', ?array $results=null): array {
        return ['handled'=>true,'answer'=>$answer,'actions'=>[],'sources'=>[],'youtube_results'=>$results,
            'tool_receipt'=>vp3_tool_receipt_v1('youtube.search','search',$status,$reason,0,count($results['videos']??[]))];
    };
    try {
        $live = agent_connection_youtube_chat_authority_v1($pdo, $user, $principal, $conversationId, $authority);
        $query = agent_connection_youtube_query_v1($query);
        if (!agent_connection_youtube_ready_v1()) {
            $answer = agent_connection_youtube_enabled_v1()
                ? 'YouTube search needs to be configured by a VP3 administrator in Connections.'
                : 'YouTube search is paused for this VP3 installation.';
            return $result($answer, 'blocked', 'setup_required');
        }
        if (!agent_connection_youtube_results_ready_v1($pdo) || !agent_connection_youtube_quota_ready_v1($pdo)
            || !agent_connection_youtube_consent_ready_v1($pdo)) {
            return $result('YouTube search needs a VP3 database upgrade.', 'blocked', 'upgrade_required');
        }

        $ownerId = (int)$live['id'];
        if (!agent_connection_youtube_consent_v1($pdo, $ownerId)) {
            $blocked = $result('Enable the YouTube skill in Connections before searching.', 'blocked', 'setup_required');
            $blocked['actions'] = [['type'=>'open_url','url'=>url('/connections.php?app=youtube#connections-app-youtube'),'label'=>'Open YouTube in Connections']];
            return $blocked;
        }
        agent_connection_youtube_results_cleanup_v1($pdo);
        $day = agent_connection_youtube_reserve_v1($pdo, $ownerId);
        $videos = agent_connection_youtube_video_status_v1(agent_connection_youtube_search_v1($query, 6, $http), $http);
        agent_connection_youtube_record_success_v1($pdo, $ownerId, $day);
        agent_connection_youtube_chat_authority_v1($pdo, $live, $principal, $conversationId, $authority);
        if (!agent_connection_youtube_ready_v1()) {
            return $result('YouTube search is currently unavailable on this VP3 installation.', 'blocked', 'provider_unavailable');
        }
        if (!agent_connection_youtube_consent_v1($pdo, $ownerId)) {
            return $result('The YouTube skill is disabled for this account.', 'blocked', 'scope_restricted');
        }
        if (!$videos) return $result('I couldn’t find a playable YouTube video for that search. Try a different title or topic.', 'no_matches');
        return $result('Choose a YouTube result to play it here in Chat.', 'read_complete', '',
            ['query'=>$query,'videos'=>$videos,'expires_at'=>time()+7*86400]);
    } catch (AgentConnectionYoutubeExceptionV1 $error) {
        return match ($error->getCode()) {
            400 => $result('Enter a YouTube topic or title up to 200 characters.', 'clarification_required', 'invalid_plan'),
            429 => $result('YouTube search has reached a request limit. Try again later.', 'blocked', 'provider_unavailable'),
            403, 503 => $result('YouTube search is currently unavailable. Check the YouTube setup in Admin Connections.', 'blocked', 'provider_unavailable'),
            default => $result('YouTube didn’t return a usable response. Try the search again.', 'failed', 'execution_failed'),
        };
    } catch (Throwable $error) {
        if ($error->getCode() === 403) {
            return $result('Use your own Agent Chat to search YouTube.', 'blocked', 'permission_denied');
        }
        return $result('The YouTube search could not be completed.', 'failed', 'execution_failed');
    }
}
