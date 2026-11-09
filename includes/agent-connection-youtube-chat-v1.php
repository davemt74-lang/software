<?php
declare(strict_types=1);

require_once __DIR__.'/agent-connection-youtube-v1.php';
require_once __DIR__.'/agent-connection-youtube-quota-v1.php';
require_once __DIR__.'/agent-connection-youtube-consent-v1.php';
require_once __DIR__.'/agent-tool-receipts-v1.php';
require_once __DIR__.'/agent-connection-youtube-picks-v1.php';

function agent_connection_youtube_history_request_v1(string $message): bool
{
    $phrase = preg_replace('/\s+/u', ' ', trim($message)) ?? '';
    $phrase = preg_replace('/[.!?]+\z/u', '', $phrase) ?? '';
    $phrase = preg_replace('/,? please\z/iu', '', $phrase) ?? '';
    $phrase = preg_replace("~\A(?:(?:please|ok(?:ay)?|so|well|hey|tell me|(?:can|could|would) you|let['’]s (?:begin|start)(?: (?:(?:in|with|on) )?(?:the|this) show)?(?: with)?)[\s,.:!?–—-]+)+~iu", '', $phrase) ?? '';
    return preg_match('~\A(?:/youtube history|played in vp3|(?:(?:show|open|pull up) (?:me )?)?(?:my )?you ?tube (?:(?:playback|listening|watch) )?history)\z~iu', $phrase) === 1
        || preg_match("~\Awhat (?:did i (?:play|watch|listen to)|(?:have i|i(?: have|['’]ve)) (?:played|watched|listened to|been (?:playing|watching|listening to)))(?: (?:earlier|recently))? on you ?tube(?: in vp3)?(?: (?:earlier|recently))?\z~iu", $phrase) === 1;
}

function agent_connection_youtube_chat_plan_v1(string $message): ?string
{
    if (agent_connection_youtube_history_request_v1($message) || agent_connection_youtube_followup_plan_v1($message) !== null) return null;
    $topic = vp3_youtube_suggestion_plan_v1($message)['topic'] ?? null;
    if ($topic !== null) return $topic;
    $message = trim($message);
    $message = preg_replace('/\A(?:please[,\s]+|(?:can|could|would) you\s+)+/iu', '', $message) ?? $message;
    if (preg_match('/\A(?:\/youtube\b|youtube\s*[:\-]|youtube\s+search\b|(?:search(?:\s+for)?|find|show|look\s+up)(?:\s+me)?\s+(?:an?\s+)?(?:(?:videos?\s+)?on\s+)?you\s*tube(?:\s+videos?)?\b)(?:\s*:\s*|\s+(?:for|about|of)\s+|\s*)(.*)\z/isu', $message, $match)
        || preg_match('/\A(?:search(?:\s+for)?|find|show|look\s+up)(?:\s+me)?\s+(.+?)\s+(?:on|from)\s+you\s*tube(?:[,\s]+please)?[.?!]*\z/isu', $message, $match)) {
        $query = preg_replace('/[.?!]+\z/u', '', trim($match[1])) ?? trim($match[1]);
        return trim(preg_replace('/\s+/u', ' ', $query) ?? $query);
    }
    return null;
}

/** Recognize a reference to results already returned, independently of fresh search and playback history. */
function agent_connection_youtube_followup_plan_v1(string $message): ?array
{
    $phrase = preg_replace('/\s+/u', ' ', trim($message)) ?? '';
    $phrase = preg_replace('/\A(?:(?:please|ok(?:ay)?|so|well|hey|(?:can|could|would) you)[,\s]+)+/iu', '', $phrase) ?? '';
    if (!preg_match('/\byou\s*tube\b/iu', $phrase)
        || !preg_match('/\A(?:what|which|where|who|show|list|open|pull up|remind|tell me|find (?:the|that)|(?:it|that) (?:is|was)|i (?:mean|meant))\b/iu', $phrase)) {
        return null;
    }

    $playlist = preg_match('/\b(?:you\s*tube (?:music )?playlist|(?:the|that|your|last|previous|earlier) playlist)\b/iu', $phrase) === 1;
    $search = preg_match('/\b(?:you\s*tube (?:search(?: results?)?|results?)|(?:the|those|your|last|previous|earlier) search results?)\b/iu', $phrase) === 1;
    if (!$playlist && !$search) return null;
    $day = preg_match('/\b(?:from|on) (\d{4}-\d{2}-\d{2})\b/u', $phrase, $date) ? $date[1] : '';
    $chatTitle = preg_match('/\b(?:in|from) (?:the )?(?:chat|conversation) (?:called |named |titled )?["“]([^"”]{1,190})["”]/iu', $phrase, $chat) ? trim($chat[1]) : '';
    $title = preg_match('/\b(?:playlist|search results?) (?:called |named |titled )?["“]([^"”]{1,200})["”]/iu', $phrase, $named) ? trim($named[1]) : '';
    $thisChat = preg_match('/\b(?:in|from) (?:this|the current) (?:chat|conversation)\b/iu', $phrase) === 1;
    if (!preg_match('/\b(?:you (?:returned|found|showed|sent|gave|made|created|shared|suggested|listed)|from before|(?:previous|earlier|last|original) (?:you\s*tube )?(?:playlist|(?:search )?results?)|(?:playlist|results?) (?:from )?(?:earlier|above))\b/iu', $phrase)
        && !preg_match('/\b(?:that|those|your) (?:you\s*tube )?(?:playlist|(?:search )?results?)\b/iu', $phrase)
        && !preg_match('/\A(?:(?:what|which|who)\b.{0,90}\b(?:was|were)|(?:it|that) (?:is|was))\b.{0,90}\b(?:in|from|on) the you\s*tube (?:playlist|(?:search )?results?)\b/iu', $phrase)
        && !(($thisChat || $day !== '' || $chatTitle !== '' || $title !== '')
            && preg_match('/\A(?:show|list|open|pull up|remind|tell me)\b.{0,90}\bthe you\s*tube (?:playlist|(?:search )?results?)\b/iu', $phrase))) {
        return null;
    }
    return ['kind'=>$playlist ? 'playlist' : 'search','this_chat'=>$thisChat,
        'title'=>$title,'day'=>$day,'chat_title'=>$chatTitle];
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

/** Read canonical VP3 playback without contacting YouTube or exposing history to the model. */
function agent_connection_youtube_history_chat_v1(PDO $pdo, array $user, array $principal, int $conversationId, ?callable $authority = null): array
{
    $settings = ['type'=>'open_url', 'url'=>url('/connections.php?app=youtube#connections-app-youtube'), 'label'=>'YouTube settings'];
    $result = static function(string $answer, string $status, string $reason = '', array $videos = [], array $actions = []): array {
        return ['handled'=>true, 'answer'=>$answer, 'actions'=>$actions, 'sources'=>[],
            'youtube_results'=>$videos ? ['history'=>true, 'expires_at'=>max(array_column($videos,'expires_at')), 'videos'=>$videos] : null,
            'tool_receipt'=>vp3_tool_receipt_v1('youtube.history','read',$status,$reason,0,count($videos))];
    };
    try {
        $owner = (int)agent_connection_youtube_chat_authority_v1($pdo,$user,$principal,$conversationId,$authority)['id'];
        require_once __DIR__.'/agent-connection-youtube-playback-v1.php';
        if (!agent_connection_youtube_consent_ready_v1($pdo) || !vp3_youtube_playback_ready_v1($pdo)
            || !agent_connection_youtube_results_ready_v1($pdo)) {
            return $result('Played in VP3 needs a VP3 database upgrade.','blocked','upgrade_required');
        }
        if (!agent_connection_youtube_enabled_v1() || !agent_connection_youtube_consent_v1($pdo,$owner)) {
            return $result('Played in VP3 history is unavailable. Check the YouTube skill in Connections.','blocked','setup_required',[],[$settings]);
        }
        $videos = vp3_youtube_history_recent_v1($pdo,$owner);
        if ($videos) return $result('Here’s what you recently played on YouTube in VP3.','read_complete','',$videos);
        return vp3_youtube_capture_key_v1($pdo,$owner) !== ''
            ? $result('Your Played in VP3 history is empty.','no_matches')
            : $result('You can enable Played in VP3 history in Connections.','blocked','setup_required',[],[$settings]);
    } catch (Throwable $error) {
        return in_array((int)$error->getCode(),[403,404,409],true)
            ? $result('Use your private Agent Chat to review your YouTube playback in VP3.','blocked','permission_denied')
            : $result('Played in VP3 history could not be loaded. Try again in a moment.','failed','execution_failed');
    }
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

function agent_connection_youtube_results_read_v1(PDO $pdo, int $ownerId, int $agentId, int $conversationId, array $messageIds): array
{
    if (!$messageIds) return [];
    return array_column(agent_connection_youtube_result_records_v1($pdo,$ownerId,$agentId,$conversationId,count($messageIds),$messageIds),'results','message_id');
}

/** Read the same private result ledger used by Agent History. Callers first authorize the Agent. */
function agent_connection_youtube_result_records_v1(PDO $pdo, int $ownerId, int $agentId, ?int $conversationId = null, int $limit = 8, array $messageIds = []): array
{
    if ($ownerId < 1 || $agentId < 0 || ($conversationId !== null && $conversationId < 1)
        || (function_exists('vp3_participant_scope_v1') && vp3_participant_scope_v1() !== null)
        || !agent_connection_youtube_enabled_v1() || !agent_connection_youtube_consent_v1($pdo,$ownerId)
        || !agent_connection_youtube_results_ready_v1($pdo)) return [];

    $now = time();
    $params = [$ownerId,$now];
    $scope = $agentId > 0 ? ' AND c.user_agent_id=?' : ' AND c.user_agent_id IS NULL';
    if ($agentId > 0) $params[] = $agentId;
    if ($conversationId !== null) {
        $scope .= ' AND c.id=?';
        $params[] = $conversationId;
    }
    if ($messageIds) {
        $ids = array_values(array_unique(array_map('intval',$messageIds)));
        $scope .= ' AND m.id IN ('.implode(',',array_fill(0,count($ids),'?')).')';
        $params = array_merge($params,$ids);
    }
    // History caps display at eight. Recall can search the full seven-day cache,
    // whose per-member daily quota fits in this bound. Loads bind exact IDs.
    $limit = max(1,min($messageIds ? 300 : 120,$limit));
    $stmt = $pdo->prepare("SELECT c.id AS conversation_id,c.title AS conversation_title,m.id AS message_id,m.created_at,r.expires_at,r.payload_json
        FROM agent_connection_youtube_results_v1 r
        JOIN chat_messages m ON m.id=r.message_id AND m.role='assistant'
        JOIN chat_conversations c ON c.id=m.conversation_id
        WHERE c.user_id=? AND r.expires_at>?{$scope} ORDER BY m.id DESC LIMIT {$limit}");
    $stmt->execute($params);
    $records = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $results = json_decode((string)$row['payload_json'],true);
        if (!is_array($results) || !is_array($results['videos'] ?? null) || !$results['videos'] || ($results['history'] ?? false) === true) continue;
        $expiresAt = min((int)$row['expires_at'],(int)($results['expires_at'] ?? $row['expires_at']));
        if ($expiresAt <= $now) continue;
        $results['expires_at'] = $expiresAt;
        $records[] = ['conversation_id'=>(int)$row['conversation_id'],'conversation_title'=>(string)$row['conversation_title'],
            'message_id'=>(int)$row['message_id'],'created_at'=>(string)$row['created_at'],'expires_at'=>$expiresAt,'results'=>$results];
    }
    return $records;
}

function agent_connection_youtube_history_v1(PDO $pdo, int $ownerId, int $agentId, int $conversationId, array $messages): array
{
    if (function_exists('vp3_participant_scope_v1') && vp3_participant_scope_v1() !== null) return $messages;
    $positions = [];
    foreach ($messages as $index=>$message) {
        $raw = $message['context_json'] ?? '';
        if (($message['role'] ?? '') !== 'assistant' || !is_string($raw)
            || (!str_contains($raw, '"youtube_search_results_v1"') && !str_contains($raw, '"youtube_results_ref_v1"'))) continue;
        $context = json_decode($raw, true);
        $id = (int)$message['id'];
        $ref = $context['youtube_results_ref_v1'] ?? null;
        if (($context['youtube_search_results_v1'] ?? false) === true) {
            $positions[$index] = [$id, $context, false];
        } elseif (is_int($ref) && $ref > 0 && $ref < $id) {
            $positions[$index] = [$ref, $context, true];
        }
    }
    if (!$positions) return $messages;
    $ids = array_values(array_unique(array_column($positions,0)));
    $records = array_column(agent_connection_youtube_result_records_v1($pdo,$ownerId,$agentId,null,count($ids),$ids),null,'message_id');
    foreach ($positions as $index=>[$sourceId, $context, $isReference]) {
        $record = $records[$sourceId] ?? null;
        if ($record === null || (!$isReference && $record['conversation_id'] !== $conversationId)) continue;
        $context['youtube_results'] = $record['results'];
        if ($isReference) $context['youtube_results']['source_message_id'] = $sourceId;
        $messages[$index]['context_json'] = json_encode($context, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_INVALID_UTF8_SUBSTITUTE);
    }
    return $messages;
}

/** Recall a saved result in the current Agent namespace. Replay stays bound to its original result. */
function agent_connection_youtube_followup_chat_v1(PDO $pdo, array $user, array $principal, array $plan, int $conversationId, ?callable $authority = null, ?int $sourceMessageId = null): array
{
    $reply = static function(string $answer, string $status, string $reason = '', ?array $results = null): array {
        return ['handled'=>true, 'answer'=>$answer, 'actions'=>[], 'sources'=>[], 'youtube_results'=>$results,
            'tool_receipt'=>vp3_tool_receipt_v1('youtube.results','read',$status,$reason,0,count($results['videos'] ?? []))]
            + ($results === null ? [] : ['youtube_source_message_id'=>$results['source_message_id']]);
    };
    try {
        $owner = (int)agent_connection_youtube_chat_authority_v1($pdo,$user,$principal,$conversationId,$authority)['id'];
        if (!agent_connection_youtube_consent_ready_v1($pdo) || !agent_connection_youtube_results_ready_v1($pdo)) {
            return $reply('Earlier YouTube results need a VP3 database upgrade.', 'blocked', 'upgrade_required');
        }
        if (!agent_connection_youtube_enabled_v1() || !agent_connection_youtube_consent_v1($pdo,$owner)) {
            return $reply('Earlier YouTube results are unavailable. Check the YouTube skill in Connections.', 'blocked', 'setup_required');
        }
        $agentId = (int)($principal['agent_id'] ?? 0);
        $scope = $plan['this_chat'] ? $conversationId : null;
        $records = match (true) {
            $sourceMessageId === null => agent_connection_youtube_result_records_v1($pdo,$owner,$agentId,$scope,120),
            $sourceMessageId > 0 => agent_connection_youtube_result_records_v1($pdo,$owner,$agentId,$scope,1,[$sourceMessageId]),
            default => [],
        };
        $selected = null;
        foreach ($records as $record) {
            $candidate = $record['results'];
            if ($sourceMessageId === null) {
                if ($plan['chat_title'] !== '' && mb_strtolower($record['conversation_title']) !== mb_strtolower($plan['chat_title'])) continue;
                if ($plan['day'] !== '' && substr($record['created_at'],0,10) !== $plan['day']) continue;
                if ($plan['title'] !== '' && !in_array(mb_strtolower($plan['title']),[
                    mb_strtolower((string)($candidate['query'] ?? '')), mb_strtolower((string)($candidate['taste_topic'] ?? '')),
                ],true)) continue;
            }
            $candidate['source_message_id'] = $record['message_id'];
            $selected ??= $candidate;
            if ($plan['kind'] !== 'playlist' || ($candidate['suggestion_kind'] ?? null) === 'playlist') {
                $selected = $candidate;
                break;
            }
        }
        if ($selected === null) {
            return $reply('I couldn’t find matching YouTube results in '.($scope === null ? 'my saved History' : 'this Chat').'. You can search YouTube again.', 'no_matches');
        }
        $name = ($selected['suggestion_kind'] ?? null) === 'playlist' ? 'YouTube playlist' : 'YouTube search results';
        return $reply('Here’s the most recent matching '.$name.'. Which song did you mean?', 'read_complete', '', $selected);
    } catch (Throwable $error) {
        return in_array((int)$error->getCode(),[403,404,409],true)
            ? $reply('Use your private Agent Chat to see its earlier YouTube results.', 'blocked', 'permission_denied')
            : $reply('The earlier YouTube results could not be loaded. Try again in a moment.', 'failed', 'execution_failed');
    }
}

function agent_connection_youtube_chat_v1(PDO $pdo, array $user, array $principal, string $query, int $conversationId, ?callable $authority=null, ?callable $http=null, ?string $suggestion=null): array
{
    $result = static function(string $answer, string $status, string $reason='', ?array $results=null): array {
        return ['handled'=>true,'answer'=>$answer,'actions'=>[],'sources'=>[],'youtube_results'=>$results,
            'tool_receipt'=>vp3_tool_receipt_v1('youtube.search','search',$status,$reason,0,count($results['videos']??[]))];
    };
    try {
        $live = agent_connection_youtube_chat_authority_v1($pdo, $user, $principal, $conversationId, $authority);
        if (!$suggestion || $query !== '') $query = agent_connection_youtube_query_v1($query);
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
        if ($suggestion === 'similar' && $query === '') {
            return $result('Which artist should I look for similar artists to?', 'clarification_required', 'invalid_plan');
        }
        $reason = '';
        if ($suggestion) {
            $taste = vp3_youtube_picks_ready_v1($pdo) ? vp3_youtube_picks_settings_v1($pdo, $ownerId)['taste'] : '';
            $reason = $query !== '' ? 'Based on the music you asked for.' : ($taste !== '' ? 'Based on the music taste you saved in VP3.' : 'A few picks to explore. You can save a music taste in Connections.');
            $query = $query !== '' ? $query : ($taste !== '' ? $taste : 'music discovery');
            $tasteTopic = mb_substr($query,0,120);
            $query = match ($suggestion) {
                'similar'=>'music artists similar to '.mb_substr($query,0,175),
                'artists'=>mb_substr($query,0,175).' music artists',
                default=>mb_substr($query,0,175).' music',
            };
            $query = agent_connection_youtube_query_v1($query);
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
        if ($suggestion) {
            // Rotate the current search results using only recent, owner-scoped cache entries.
            $recent = $pdo->prepare('SELECT r.payload_json FROM agent_connection_youtube_results_v1 r
                JOIN chat_messages m ON m.id=r.message_id JOIN chat_conversations c ON c.id=m.conversation_id
                WHERE c.user_id=? AND r.expires_at>? ORDER BY r.message_id DESC LIMIT 12');
            $recent->execute([$ownerId,time()]);
            $seen = [];
            foreach ($recent->fetchAll(PDO::FETCH_COLUMN) as $raw) {
                $prior = json_decode($raw,true);
                if (empty($prior['suggestion_reason'])) continue;
                foreach ($prior['videos'] ?? [] as $video) $seen[$video['video_id']] = true;
            }
            $fresh = array_values(array_filter($videos, static fn(array $video): bool => !isset($seen[$video['video_id']])));
            $limit = $suggestion === 'playlist' ? 6 : 3;
            $videos = array_slice($suggestion === 'playlist' ? $videos : ($fresh ?: $videos),0,$limit);
            $answer = match ($suggestion) {
                'playlist'=>'Here’s a YouTube playlist draft. Choose any video to play it in Chat.',
                'artists','similar'=>'Here are YouTube videos to explore for artist recommendations.',
                default=>'Here are some YouTube music picks.',
            };
            return $result($answer, 'read_complete', '',
                ['query'=>$query,'videos'=>$videos,'expires_at'=>time()+7*86400,'suggestion_reason'=>$reason,
                    'suggestion_kind'=>$suggestion,'taste_topic'=>$tasteTopic]);
        }
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
