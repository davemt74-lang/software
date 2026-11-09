<?php
declare(strict_types=1);

require_once __DIR__.'/agent-connection-youtube-playback-v1.php';

/** Read the existing, expiring YouTube ledgers for an already authorized Agent scope. */
function vp3_youtube_activity_v1(PDO $pdo, array $user, int $agentId): ?array
{
    $owner = (int)($user['id'] ?? 0);
    if ($owner < 1 || $agentId < 0 || !has_permission('account.access', $user)
        || (function_exists('vp3_participant_scope_v1') && vp3_participant_scope_v1() !== null)
        || !agent_connection_youtube_enabled_v1() || !agent_connection_youtube_consent_v1($pdo, $owner)
        || !agent_connection_youtube_results_ready_v1($pdo)) return null;

    $chatUrl = static fn(int $id): string => url('/chat.php?agent='.($agentId ?: 'system').'&conversation_id='.$id);
    try {
        $searches = [];
        foreach (agent_connection_youtube_result_records_v1($pdo,$owner,$agentId) as $row) {
            $payload = $row['results'];
            $videos = [];
            foreach (array_slice($payload['videos'], 0, 6) as $video) {
                $title = agent_connection_youtube_text_v1($video['title'] ?? null, 200);
                if ($title === '') continue;
                $videos[] = ['title'=>$title, 'channel'=>agent_connection_youtube_text_v1($video['channel_title'] ?? null, 120)];
            }
            if ($videos) {
                $searches[] = [
                    'message_id'=>(int)$row['message_id'],
                    'query'=>agent_connection_youtube_text_v1($payload['query'] ?? null, 200),
                    'videos'=>$videos, 'searched_at'=>(string)$row['created_at'], 'expires_at'=>(int)$row['expires_at'],
                    'chat_url'=>$chatUrl((int)$row['conversation_id']),
                ];
            }
        }
        $ready = vp3_youtube_playback_ready_v1($pdo);
        $plays = $ready ? array_map(static fn(array $video): array => [
            'title'=>agent_connection_youtube_text_v1($video['title'] ?? null, 200),
            'channel'=>agent_connection_youtube_text_v1($video['channel_title'] ?? null, 120),
            'played_at'=>(int)$video['played_at'], 'expires_at'=>(int)$video['expires_at'],
            'chat_url'=>$chatUrl((int)$video['conversation_id']),
        ], vp3_youtube_history_recent_v1($pdo, $owner, $agentId)) : [];
        return ['searches'=>$searches, 'plays'=>$plays, 'playback_ready'=>$ready,
            'capture_enabled'=>$ready && vp3_youtube_capture_key_v1($pdo, $owner) !== '',
            'connections_url'=>url('/connections.php?app=youtube#connections-app-youtube')];
    } catch (PDOException) {
        return null;
    }
}
