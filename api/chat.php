<?php
declare(strict_types=1);
require dirname(__DIR__) . '/includes/bootstrap.php';
require_once dirname(__DIR__).'/includes/agent-chat-runtime-v2160.php';

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
        $watchRefreshAt=(int)($_SESSION['vp3_agent_crm_watch_refresh'][$userId]??0);
        if(function_exists('vp3_agent_crm_watchlist_refresh')&&$watchRefreshAt<time()-60){
            $_SESSION['vp3_agent_crm_watch_refresh'][$userId]=time();
            try{vp3_agent_crm_watchlist_refresh($pdo,$user,7);}catch(Throwable $e){error_log('Agent CRM watchlist refresh failed: '.$e->getMessage());}
        }
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
        $activityTypes = "'agent_track_share','producer_track_share','agent_supervisor_listen','stem_region_note','production_note','new_track_release','new_album_release','show_reminder','artist_post','release_deadline','release_action','radar_security_action','radar_agent_visit_needs_attention','radar_external_security_action','radar_external_visit_needs_attention','agent_activity_radar_rate_limited','radar_agent_access_request','radar_agent_opportunity','radar_agent_conversion','radar_agent_watchlist'";

        if ($afterId > 0) {
            $stmt = $pdo->prepare(
                "SELECT id,type,title,body,target_url,created_at
                 FROM notifications
                 WHERE user_id=? AND id>?
                   AND type IN ({$activityTypes})
                 ORDER BY id ASC LIMIT 30"
            );
            $stmt->execute([$userId,$afterId]);
            $updates = $stmt->fetchAll();
        } else {
            $stmt = $pdo->prepare(
                "SELECT id,type,title,body,target_url,created_at
                 FROM notifications
                 WHERE user_id=?
                   AND type IN ({$activityTypes})
                   AND created_at>=DATE_SUB(NOW(),INTERVAL 7 DAY)
                 ORDER BY id DESC LIMIT 10"
            );
            $stmt->execute([$userId]);
            $updates = array_reverse($stmt->fetchAll());
        }

        $latestStmt = $pdo->prepare(
            "SELECT COALESCE(MAX(id),0) FROM notifications
             WHERE user_id=?
               AND type IN ({$activityTypes})"
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
        $scope=vp3_agent_chat_stream_scope_v380($pdo,$user,$input);
        $brainAllowed=personal_capability_has_v242('agent_brain.access',$user);
        $payload=vp3_agent_chat_send_v2160($pdo,$user,$scope['agent'],$scope['principal'],$input,$brainAllowed);
        echo json_encode($payload,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
        exit;
    }

    throw new RuntimeException('Unknown chat action.');
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['ok'=>false,'error'=>$e->getMessage()]);
}
