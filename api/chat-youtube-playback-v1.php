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
        case 'record':
            $messageId = filter_var($field('message_id'), FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]);
            $recorded = $messageId && vp3_youtube_history_record_v1($pdo, $owner, $messageId, $field('video_id'), $field('play_id'), $field('capture_key'));
            vp3_youtube_playback_reply_v1(200, ['ok'=>true, 'recorded'=>(bool)$recorded]);
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
