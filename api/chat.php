<?php
declare(strict_types=1);
require dirname(__DIR__) . '/includes/bootstrap.php';

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');

$user = current_user();
if (!$user || !has_permission('chat.access', $user)) {
    http_response_code(403);
    echo json_encode(['ok'=>false,'error'=>'Chat access is not available for this account.']);
    exit;
}

$pdo = db();
if (!$pdo || !table_exists('chat_conversations')) {
    http_response_code(503);
    echo json_encode(['ok'=>false,'error'=>'Chat storage is not ready. An administrator needs to run the database upgrade.']);
    exit;
}

$input = json_decode((string)file_get_contents('php://input'), true);
if (!is_array($input)) {
    $input = $_POST;
}

$csrf = (string)($input['csrf_token'] ?? '');
if ($csrf === '' || !hash_equals(csrf_token(), $csrf)) {
    http_response_code(419);
    echo json_encode(['ok'=>false,'error'=>'Session expired. Refresh the page and try again.']);
    exit;
}

$action = (string)($input['action'] ?? 'send');
$userId = (int)$user['id'];

function chat_conversation_owned(PDO $pdo, int $conversationId, int $userId): ?array
{
    $stmt = $pdo->prepare('SELECT * FROM chat_conversations WHERE id=? AND user_id=? LIMIT 1');
    $stmt->execute([$conversationId, $userId]);
    $row = $stmt->fetch();
    return $row ?: null;
}

try {
    if ($action === 'list') {
        $stmt = $pdo->prepare(
            'SELECT c.id,c.title,c.created_at,c.updated_at,COALESCE(MAX(m.id),0) AS latest_message_id
             FROM chat_conversations c
             LEFT JOIN chat_messages m ON m.conversation_id=c.id
             WHERE c.user_id=?
             GROUP BY c.id
             ORDER BY latest_message_id DESC,c.updated_at DESC,c.id DESC LIMIT 50'
        );
        $stmt->execute([$userId]);
        echo json_encode(['ok'=>true,'conversations'=>$stmt->fetchAll()]);
        exit;
    }

    if ($action === 'activity') {
        player_process_show_reminders($user);
        $activeConversationId = agent_chat_v101_latest_conversation_id($pdo,$userId);
        $activeMessageId = 0;
        if ($activeConversationId > 0) {
            $messageStmt=$pdo->prepare('SELECT COALESCE(MAX(id),0) FROM chat_messages WHERE conversation_id=?');
            $messageStmt->execute([$activeConversationId]);
            $activeMessageId=(int)$messageStmt->fetchColumn();
        }

        if (!table_exists('notifications')) {
            echo json_encode([
                'ok'=>true,
                'updates'=>[],
                'latest_id'=>0,
                'unread_count'=>0,
                'conversation_id'=>$activeConversationId,
                'latest_message_id'=>$activeMessageId,
            ]);
            exit;
        }

        $afterId = max(0,(int)($input['after_id'] ?? 0));

        if ($afterId > 0) {
            $stmt = $pdo->prepare(
                "SELECT id,type,title,body,target_url,created_at
                 FROM notifications
                 WHERE user_id=? AND id>?
                   AND type IN ('agent_track_share','producer_track_share','agent_supervisor_listen','stem_region_note','production_note','new_track_release','new_album_release','show_reminder','artist_post','release_deadline','release_action')
                 ORDER BY id ASC LIMIT 30"
            );
            $stmt->execute([$userId,$afterId]);
            $updates = $stmt->fetchAll();
        } else {
            $stmt = $pdo->prepare(
                "SELECT id,type,title,body,target_url,created_at
                 FROM notifications
                 WHERE user_id=?
                   AND type IN ('agent_track_share','producer_track_share','agent_supervisor_listen','stem_region_note','production_note','new_track_release','new_album_release','show_reminder','artist_post','release_deadline','release_action')
                   AND created_at>=DATE_SUB(NOW(),INTERVAL 7 DAY)
                 ORDER BY id DESC LIMIT 10"
            );
            $stmt->execute([$userId]);
            $updates = array_reverse($stmt->fetchAll());
        }

        $latestStmt = $pdo->prepare(
            "SELECT COALESCE(MAX(id),0) FROM notifications
             WHERE user_id=?
               AND type IN ('agent_track_share','producer_track_share','agent_supervisor_listen','stem_region_note','production_note','new_track_release','new_album_release','show_reminder','artist_post','release_deadline','release_action')"
        );
        $latestStmt->execute([$userId]);
        $latestId = (int)$latestStmt->fetchColumn();

        echo json_encode([
            'ok'=>true,'updates'=>$updates,'latest_id'=>$latestId,
            'unread_count'=>notification_unread_count($user),
            'conversation_id'=>$activeConversationId,'latest_message_id'=>$activeMessageId,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'new') {
        $workspaceId = artist_workspace_v181_scope_id($user);
        $stmt = $pdo->prepare('INSERT INTO chat_conversations (user_id,artist_workspace_id,title) VALUES (?,?,?)');
        $stmt->execute([$userId, $workspaceId ?: null, 'New chat']);
        echo json_encode(['ok'=>true,'conversation_id'=>(int)$pdo->lastInsertId()]);
        exit;
    }

    if ($action === 'load') {
        $conversationId = (int)($input['conversation_id'] ?? 0);
        $conversation = chat_conversation_owned($pdo, $conversationId, $userId);
        if (!$conversation) throw new RuntimeException('Conversation not found.');
        $stmt = $pdo->prepare('SELECT id,role,message,context_json,created_at FROM chat_messages WHERE conversation_id=? ORDER BY id DESC LIMIT 300');
        $stmt->execute([$conversationId]);
        echo json_encode(['ok'=>true,'conversation'=>$conversation,'messages'=>array_reverse($stmt->fetchAll())]);
        exit;
    }

    if ($action === 'messages_after') {
        $conversationId=(int)($input['conversation_id']??0);
        if(!chat_conversation_owned($pdo,$conversationId,$userId))throw new RuntimeException('Conversation not found.');
        $afterId=max(0,(int)($input['after_id']??0));
        $stmt=$pdo->prepare('SELECT id,role,message,context_json,created_at FROM chat_messages WHERE conversation_id=? AND id>? ORDER BY id ASC LIMIT 80');
        $stmt->execute([$conversationId,$afterId]);
        echo json_encode(['ok'=>true,'conversation_id'=>$conversationId,'messages'=>$stmt->fetchAll()],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'delete') {
        $conversationId = (int)($input['conversation_id'] ?? 0);
        if (!chat_conversation_owned($pdo, $conversationId, $userId)) throw new RuntimeException('Conversation not found.');
        $pdo->prepare('DELETE FROM chat_conversations WHERE id=? AND user_id=?')->execute([$conversationId,$userId]);
        echo json_encode(['ok'=>true]);
        exit;
    }

    if ($action === 'send') {
        $query = trim((string)($input['message'] ?? ''));
        $conversationId = (int)($input['conversation_id'] ?? 0);
        $inputMode = (string)($input['input_mode'] ?? 'text');
        $inputMode = $inputMode === 'voice' ? 'voice' : 'text';

        if ($query === '') throw new RuntimeException('Enter a message.');
        if (mb_strlen($query) > 6000) throw new RuntimeException('That message is too long.');

        if ($conversationId < 1) {
            $title = mb_strimwidth($query, 0, 70, '…');
            $workspaceId = artist_workspace_v181_scope_id($user);
            $stmt = $pdo->prepare('INSERT INTO chat_conversations (user_id,artist_workspace_id,title) VALUES (?,?,?)');
            $stmt->execute([$userId, $workspaceId ?: null, $title]);
            $conversationId = (int)$pdo->lastInsertId();
        } else {
            $conversation = chat_conversation_owned($pdo, $conversationId, $userId);
            if (!$conversation) throw new RuntimeException('Conversation not found.');
        }

        $rawAgentContext=is_array($input['agent_context']??null)?$input['agent_context']:[];
        $rawAgentContext['conversation_id']=$conversationId;
        $agentContext=agent_surface_v131_enrich($user,'chat',$rawAgentContext);

        $historyStmt = $pdo->prepare('SELECT role,message FROM chat_messages WHERE conversation_id=? ORDER BY id DESC LIMIT 12');
        $historyStmt->execute([$conversationId]);
        $history = array_reverse($historyStmt->fetchAll());

        $stmt = $pdo->prepare('INSERT INTO chat_messages (conversation_id,user_id,role,message) VALUES (?,?,?,?)');
        $stmt->execute([$conversationId,$userId,'user',$query]);
        $userMessageId = (int)$pdo->lastInsertId();
        agent_brain_archive_and_parse($user, $conversationId, $userMessageId, 'user', $query, $inputMode);

        $toolResult = function_exists('release_v105_chat_tool')
            ? release_v105_chat_tool($query, $user, $conversationId)
            : ['handled'=>false,'answer'=>'','stem_media'=>[],'media'=>[],'actions'=>[],'sources'=>[]];
        if (empty($toolResult['handled'])) {
            $toolResult = agent_tool_execute_query($query, $user, $conversationId);
        }

        if (!empty($toolResult['handled'])) {
            $answer = (string)$toolResult['answer'];
            $context = [];
        } else {
            $result = function_exists('chat_generate_answer_v105')
                ? chat_generate_answer_v105($query, $history, $user, $agentContext)
                : chat_generate_answer($query, $history, $user);
            $answer = (string)$result['answer'];
            $context = $result['context'];
        }

        $publicSources = [];
        foreach ($context as $item) {
            $source = (string)($item['source'] ?? '');
            if($source==='agent-context:v131')continue;
            $entry = ['source'=>$source,'title'=>(string)($item['title'] ?? '')];
            if (str_starts_with($source, 'knowledge:')) {
                $knowledgeId = (int)substr($source, strlen('knowledge:'));
                $knowledge = get_knowledge_item($knowledgeId);
                if ($knowledge && !empty($knowledge['file_path'])) $entry['url'] = url('/knowledge-file.php?id=' . $knowledgeId);
            }
            $publicSources[] = $entry;
        }

        $mediaLimit = track_is_playlist_request($query) ? 7 : (track_is_next_request($query) ? 1 : 4);
        $answerMedia = track_media_from_answer($answer, $user, $mediaLimit);
        $queryMedia = track_media_suggestions($query, $user, $mediaLimit);
        $media = array_slice(track_merge_media_suggestions($answerMedia, $queryMedia),0,$mediaLimit);
        $playlistTitle = $media ? track_playlist_title($query) : '';

        $toolSources = !empty($toolResult['sources']) && is_array($toolResult['sources']) ? $toolResult['sources'] : [];
        $publicSources = array_merge($publicSources, $toolSources);
        $messageContext = [
            'sources'=>$publicSources,
            'media'=>$media,
            'stem_media'=>!empty($toolResult['stem_media']) && is_array($toolResult['stem_media']) ? $toolResult['stem_media'] : [],
            'actions'=>!empty($toolResult['actions']) && is_array($toolResult['actions']) ? $toolResult['actions'] : [],
            'playlist_title'=>$playlistTitle,
            'agent_context'=>$agentContext,
        ];

        $contextJson = json_encode($messageContext,JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $stmt = $pdo->prepare('INSERT INTO chat_messages (conversation_id,user_id,role,message,context_json) VALUES (?,NULL,?,?,?)');
        $stmt->execute([$conversationId,'assistant',$answer,$contextJson]);
        $assistantMessageId = (int)$pdo->lastInsertId();
        agent_brain_archive_and_parse($user, $conversationId, $assistantMessageId, 'assistant', $answer, $inputMode);
        $pdo->prepare('UPDATE chat_conversations SET updated_at=NOW() WHERE id=?')->execute([$conversationId]);

        echo json_encode([
            'ok'=>true,'conversation_id'=>$conversationId,'user_message_id'=>$userMessageId,
            'assistant_message_id'=>$assistantMessageId,'answer'=>$answer,'sources'=>$publicSources,
            'media'=>$media,'stem_media'=>$messageContext['stem_media'],'actions'=>$messageContext['actions'],
            'playlist_title'=>$playlistTitle,'input_mode'=>$inputMode,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }

    throw new RuntimeException('Unknown chat action.');
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['ok'=>false,'error'=>$e->getMessage()]);
}
