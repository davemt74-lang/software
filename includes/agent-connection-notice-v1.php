<?php
declare(strict_types=1);

function agent_connection_notice_record_v1(int $ownerId, string $provider): void
{
    if (!function_exists('notification_create_locked')) return;
    [$type, $title, $body] = match ($provider) {
        'gmail' => ['gmail_connected', 'Gmail connected', 'Review your Gmail permissions and try the Gmail skill in Agent Chat.'],
        'youtube' => ['youtube_enabled', 'YouTube enabled', 'Search for public videos by typing or speaking in Agent Chat.'],
    };
    notification_create_locked($ownerId, $type, $title, $body,
        url('/connections.php?app='.$provider.'#connections-app-'.$provider), 'agent_connection_activation');
}

function agent_connection_notice_owner_v1(array $user): bool
{
    return (int)($user['id'] ?? 0) > 0 && has_permission('account.access', $user)
        && has_permission('chat.access', $user) && vp3_participant_scope_v1() === null;
}

/** Use the same exact, owner-scoped stored marker for polling and retries. */
function agent_connection_notice_stored_v1(PDO $pdo): string
{
    $sqlite = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite';
    $value = "JSON_EXTRACT(CASE WHEN JSON_VALID(m.context_json) THEN m.context_json ELSE '{}' END,'$.attention.notification_id')";
    if (!$sqlite) $value = 'JSON_UNQUOTE('.$value.')';
    return 'SELECT m.id,m.conversation_id FROM chat_messages m JOIN chat_conversations c ON c.id=m.conversation_id'
        ." WHERE c.user_id=? AND m.role='assistant' AND CAST($value AS ".($sqlite ? 'INTEGER' : 'UNSIGNED').')=?';
}

function agent_connection_notice_after_v1(PDO $pdo, array $user, int $afterId): array
{
    $result = ['ok'=>true, 'latest_id'=>$afterId, 'items'=>[]];
    if (!agent_connection_notice_owner_v1($user) || !table_exists('notifications')) return $result;
    $owner = (int)$user['id'];
    $query = $pdo->prepare("SELECT MAX(id) AS id,type FROM notifications WHERE user_id=? AND id>?
        AND source_type='agent_connection_activation' AND type IN ('gmail_connected','youtube_enabled')
        GROUP BY type ORDER BY id");
    $query->bindValue(1,$owner,PDO::PARAM_INT);
    $query->bindValue(2,$afterId,PDO::PARAM_INT);
    $query->execute();
    $stored = $pdo->prepare(agent_connection_notice_stored_v1($pdo).' LIMIT 1');
    foreach ($query->fetchAll(PDO::FETCH_ASSOC) as $item) {
        $id = (int)$item['id'];
        $result['latest_id'] = $id;
        $stored->execute([$owner,$id]);
        if (!$stored->fetchColumn() && agent_connection_notice_current_v1($pdo, $owner, $item['type']) !== null) {
            $result['items'][] = ['id'=>$id, 'type'=>$item['type'], 'source_type'=>'agent_connection_activation'];
        }
    }
    return $result;
}

/** A notice describes current access. Re-read policy before disclosing it. */
function agent_connection_notice_current_v1(PDO $pdo, int $owner, string $type): ?array
{
    if ($type === 'gmail_connected') {
        require_once __DIR__.'/agent-connections-v1.php';
        $summary = agent_connections_summary_v1($owner);
        $connection = $summary['connection'] ?? null;
        return !empty($summary['provider_ready']) && ($connection['status'] ?? null) === 'connected'
            ? $connection['permissions'] : null;
    }
    if ($type === 'youtube_enabled') {
        require_once __DIR__.'/agent-connection-youtube-v1.php';
        require_once __DIR__.'/agent-connection-youtube-consent-v1.php';
        return agent_connection_youtube_ready_v1() && agent_connection_youtube_consent_v1($pdo, $owner) ? [] : null;
    }
    return null;
}

function agent_connection_notice_copy_v1(string $type, array $permissions): array
{
    if ($type === 'youtube_enabled') {
        return [
            'message'=>"YouTube is enabled.\n\nAccount permissions · VP3 uses its site connection for public YouTube search. It has no access to your YouTube account, history, subscriptions or playlists, and no permission to upload or make changes.\n\nYouTube skill · Search public videos by text or voice. Select a playable result to watch it in Chat.\n\nTry saying “Search YouTube for beginner guitar lessons.”",
            'speech'=>'YouTube is enabled. Ask me to search for a video and you can watch it here in Chat.',
            'provider'=>'youtube',
        ];
    }

    $read = !empty($permissions['read']);
    $send = !empty($permissions['send']);
    $followups = $read && !empty($permissions['followups']);
    $message = "Gmail is connected.\n\nGoogle permissions · VP3 uses your Google account identity and email address to identify the mailbox. Google granted Gmail read and send access.\n\nAgent permissions in VP3";
    $message .= "\n• Search and read · ".($read ? 'On. Search or read private Gmail in typed Chat.' : 'Off. The Agent cannot search or read your mailbox.');
    $message .= "\n• Reviewed send · ".($send ? 'On. Draft a message in Chat and approve it before it sends.' : 'Off. The Agent cannot send mail from this account.');
    $message .= "\n• Background follow-ups · ".($followups
        ? 'On. Set a sender alert or a reply watch. Each watch starts only after you request it, and can run while your browser is closed.'
        : 'Off. Sender alerts and reply watches are disabled.');
    $examples = [];
    if ($read) $examples[] = 'Find emails from sender@example.com.';
    if ($send) $examples[] = 'Draft an email to sender@example.com thanking them for the update.';
    if ($followups) $examples[] = 'Notify me when I get an email from sender@example.com.';
    if ($examples) $message .= "\n\nTry one in Chat\n• ".implode("\n• ", $examples);
    return ['message'=>$message, 'speech'=>'Gmail is connected. I’ve outlined your permissions and what you can do in Chat.', 'provider'=>'gmail'];
}

/** The caller holds the existing notification owner's presentation lock. */
function agent_connection_notice_present_v1(PDO $pdo, array $user, array $notification, int $conversationId, int $agentId): array
{
    if (!$pdo->inTransaction()) throw new LogicException('Connection notice presentation requires the owner transaction.');
    $owner = (int)$user['id'];
    $id = (int)$notification['id'];
    $type = $notification['type'] ?? '';
    $result = ['ok'=>true,'handled'=>false,'notification_id'=>$id,'voice_allowed'=>false,'preserve_unread'=>true];
    if (!agent_connection_notice_owner_v1($user) || (int)$notification['user_id'] !== $owner
        || ($notification['source_type'] ?? '') !== 'agent_connection_activation'
        || !in_array($type, ['gmail_connected','youtube_enabled'], true)) return $result;

    $scope = $pdo->prepare('SELECT id FROM chat_conversations WHERE id=? AND user_id=? AND '
        .($agentId > 0 ? 'user_agent_id=?' : 'user_agent_id IS NULL'));
    $scope->execute($agentId > 0 ? [$conversationId,$owner,$agentId] : [$conversationId,$owner]);
    if (!$scope->fetchColumn()) throw new DomainException('The selected Agent Chat conversation is unavailable.');

    $stored = $pdo->prepare(agent_connection_notice_stored_v1($pdo).' LIMIT 1');
    $stored->execute([$owner,$id]);
    if ($prior = $stored->fetch(PDO::FETCH_ASSOC)) {
        return (int)$prior['conversation_id'] !== $conversationId ? $result : array_replace($result, [
            'handled'=>true,'duplicate'=>true,'conversation_id'=>$conversationId,'message_id'=>(int)$prior['id'],
        ]);
    }

    $latest = $pdo->prepare("SELECT MAX(id) FROM notifications WHERE user_id=? AND type=? AND source_type='agent_connection_activation'");
    $latest->execute([$owner,$type]);
    if ((int)$latest->fetchColumn() !== $id) return $result;
    $permissions = agent_connection_notice_current_v1($pdo,$owner,$type);
    if ($permissions === null) return $result;

    $copy = agent_connection_notice_copy_v1($type,$permissions);
    $target = url('/connections.php?app='.$copy['provider'].'#connections-app-'.$copy['provider']);
    $context = [
        'actions'=>[['type'=>'open_url','label'=>'Manage connection','url'=>$target]],
        'attention'=>['required'=>false,'notification_id'=>$id,'notification_type'=>$type,'target_url'=>$target],
    ];
    $query = $pdo->prepare("INSERT INTO chat_messages (conversation_id,user_id,role,message,context_json) VALUES (?,NULL,'assistant',?,?)");
    $query->execute([$conversationId,$copy['message'],json_encode($context,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)]);
    $messageId = (int)$pdo->lastInsertId();
    $pdo->prepare('UPDATE chat_conversations SET updated_at=CURRENT_TIMESTAMP WHERE id=? AND user_id=?')->execute([$conversationId,$owner]);
    if (function_exists('agent_brain_archive_message') && personal_capability_has_v242('agent_brain.access',$user)) {
        try { agent_brain_archive_message($user,$conversationId,$messageId,'assistant',$copy['message']); }
        catch (Throwable) { error_log('Connection notice History archive unavailable.'); }
    }
    return array_replace($result,[
        'handled'=>true,'duplicate'=>false,'conversation_id'=>$conversationId,'message_id'=>$messageId,
        'voice_allowed'=>chat_settings_agent_voice_enabled_v237($pdo,$user),'speech'=>$copy['speech'],
        'presentation_owner'=>'agent_connection_activation',
    ]);
}
