<?php
declare(strict_types=1);

require dirname(__DIR__).'/includes/bootstrap.php';
require_once dirname(__DIR__).'/includes/agent-connection-youtube-playback-v1.php';
require_once dirname(__DIR__).'/includes/participant-updates-v1.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: private, no-store');
function vp3_youtube_playback_reply_v1(int $status, array $data): never
{
    http_response_code($status);
    echo json_encode($data, JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    vp3_youtube_playback_reply_v1(405, ['ok'=>false]);
}
$user = current_user();
if (!$user || !has_permission('chat.access', $user) || !has_permission('account.access', $user)
    || vp3_participant_scope_v1() !== null || !is_string($_POST['csrf_token'] ?? null) || !verify_csrf()) {
    vp3_youtube_playback_reply_v1(403, ['ok'=>false]);
}

try {
    $pdo = db();
    $owner = (int)$user['id'];
    if (!$pdo || !vp3_youtube_playback_ready_v1($pdo)) vp3_youtube_playback_reply_v1(503, ['ok'=>false]);
    if (!agent_connection_youtube_consent_v1($pdo, $owner) || !agent_connection_youtube_enabled_v1()) {
        vp3_youtube_playback_reply_v1(403, ['ok'=>false]);
    }
    $field = static fn(string $name): string => is_string($_POST[$name] ?? null) ? $_POST[$name] : '';
    switch ($field('action')) {
        case 'offer':
        case 'save_taste':
            if (!vp3_youtube_picks_ready_v1($pdo)) vp3_youtube_playback_reply_v1(503, ['ok'=>false]);
            $stmt = $pdo->prepare('SELECT r.payload_json FROM agent_connection_youtube_results_v1 r
                JOIN chat_messages m ON m.id=r.message_id JOIN chat_conversations c ON c.id=m.conversation_id
                WHERE r.message_id=? AND c.user_id=? AND r.expires_at>?');
            $messageId = filter_var($field('message_id'), FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]);
            $stmt->execute([$messageId ?: 0,$owner,time()]);
            $pick = json_decode((string)$stmt->fetchColumn(),true);
            if (empty($pick['suggestion_reason'])) vp3_youtube_playback_reply_v1(404, ['ok'=>false]);
            if ($field('action') === 'offer') {
                $offer = vp3_youtube_history_video_v1(json_encode($pick, JSON_THROW_ON_ERROR), $field('video_id'))
                    && vp3_youtube_reserve_offer_v1($pdo,$owner,time());
                vp3_youtube_playback_reply_v1(200, ['ok'=>true,'offer'=>(bool)$offer]);
            }
            vp3_youtube_picks_save_v1($pdo,$owner,(string)($pick['taste_topic'] ?? ''),null);
            vp3_youtube_playback_reply_v1(200, ['ok'=>true]);
        case 'snooze':
            if (!vp3_youtube_picks_ready_v1($pdo)) vp3_youtube_playback_reply_v1(503, ['ok'=>false]);
            $pdo->prepare('UPDATE vp3_youtube_picks_v1 SET next_offer=? WHERE owner_user_id=? AND proactive=1')
                ->execute([time()+86400,$owner]);
            vp3_youtube_playback_reply_v1(200, ['ok'=>true]);
        case 'record':
            $messageId = filter_var($field('message_id'), FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]);
            $recorded = $messageId && vp3_youtube_history_record_v1($pdo, $owner, $messageId, $field('video_id'), $field('play_id'), $field('capture_key'));
            vp3_youtube_playback_reply_v1(200, ['ok'=>true, 'recorded'=>(bool)$recorded]);
        case 'watch':
            if (!vp3_youtube_watch_ready_v1($pdo)) vp3_youtube_playback_reply_v1(503, ['ok'=>false]);
            $messageId = filter_var($field('message_id'), FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]);
            $seconds = filter_var($field('watch_seconds'), FILTER_VALIDATE_INT, ['options'=>['min_range'=>1,'max_range'=>VP3_YOUTUBE_MAX_WATCH_SECONDS_V1]]);
            if ($messageId === false || $seconds === false) vp3_youtube_playback_reply_v1(422, ['ok'=>false]);
            $recorded = vp3_youtube_watch_record_v1($pdo, $owner, $messageId, $field('video_id'), $field('play_id'), $field('capture_key'), $seconds);
            vp3_youtube_playback_reply_v1(200, ['ok'=>true, 'recorded'=>$recorded]);
        case 'remove':
            $id = $field('play_id');
            if (!preg_match('/\A[a-f0-9-]{36}\z/', $id)) vp3_youtube_playback_reply_v1(422, ['ok'=>false]);
            vp3_youtube_history_change_v1($pdo, $owner, 'remove_history', $id);
            vp3_youtube_playback_reply_v1(200, ['ok'=>true, 'capture_key'=>vp3_youtube_capture_key_v1($pdo, $owner)]);
        case 'recent':
            agent_connection_youtube_results_cleanup_v1($pdo);
            vp3_youtube_playback_reply_v1(200, ['ok'=>true, 'enabled'=>vp3_youtube_capture_key_v1($pdo, $owner) !== '',
                'history'=>vp3_youtube_history_recent_v1($pdo, $owner)]);
        default:
            vp3_youtube_playback_reply_v1(422, ['ok'=>false]);
    }
} catch (Throwable) {
    vp3_youtube_playback_reply_v1(503, ['ok'=>false]);
}
