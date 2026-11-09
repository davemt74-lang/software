<?php
declare(strict_types=1);

require __DIR__.'/includes/bootstrap.php';
require_once __DIR__.'/includes/agent-connection-youtube-v1.php';
require_once __DIR__.'/includes/agent-connection-youtube-playback-v1.php';
require_permission('account.access');
header('Cache-Control: private, no-store');
header('Referrer-Policy: no-referrer');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    http_response_code(405);
    exit;
}

$user = current_user();
$pdo = db();
$action = $_POST['action'] ?? '';
$result = 'youtube_invalid';
if ($user && is_string($_POST['csrf_token'] ?? null) && verify_csrf() && $action === 'save_picks') {
    try {
        $owner = (int)$user['id'];
        if (!$pdo || !has_permission('chat.access', $user) || !vp3_youtube_picks_ready_v1($pdo)
            || !agent_connection_youtube_consent_v1($pdo,$owner) || !is_string($_POST['taste'] ?? null)) {
            throw new DomainException('Music preferences unavailable.');
        }
        vp3_youtube_picks_save_v1($pdo,$owner,$_POST['taste'],($_POST['proactive'] ?? '') === '1');
        $result = 'youtube_picks_saved';
    } catch (Throwable) {
        $result = 'youtube_unavailable';
    }
} elseif ($user && is_string($_POST['csrf_token'] ?? null) && verify_csrf()
    && in_array($action, ['save_artist_research','save_topic_research','save_watch_research'], true)) {
    try {
        if (!$pdo || !has_permission('chat.access', $user)) throw new DomainException('Research preference unavailable.');
        $kind = match ($action) {
            'save_artist_research' => 'artist',
            'save_topic_research' => 'topic',
            'save_watch_research' => 'watch',
        };
        vp3_youtube_research_save_v1($pdo, (int)$user['id'], $kind, ($_POST[$kind.'_research'] ?? null) === '1');
        $result = 'youtube_'.$kind.'_research_saved';
    } catch (Throwable) {
        $result = 'youtube_unavailable';
    }
} elseif ($user && is_string($_POST['csrf_token'] ?? null) && verify_csrf()
    && in_array($action, ['enable_history','pause_history','clear_history'], true)) {
    try {
        if (!$pdo || !vp3_youtube_playback_ready_v1($pdo) || !has_permission('chat.access', $user)
            || ($action === 'enable_history' && !agent_connection_youtube_ready_v1())) {
            throw new DomainException('Playback history unavailable.');
        }
        vp3_youtube_history_change_v1($pdo, (int)$user['id'], $action);
        $result = 'youtube_history_saved';
    } catch (Throwable) {
        $result = 'youtube_unavailable';
    }
} elseif ($user && is_string($_POST['csrf_token'] ?? null) && verify_csrf() && in_array($action, ['enable', 'disable'], true)) {
    if ($action === 'enable' && ($_POST['accept_youtube_terms'] ?? null) !== '1') {
        $result = 'youtube_accept_required';
    } elseif (!$pdo || !agent_connection_youtube_consent_ready_v1($pdo)
        || ($action === 'enable' && (!has_permission('chat.access', $user) || !agent_connection_youtube_ready_v1()))) {
        $result = 'youtube_unavailable';
    } else {
        try {
            agent_connection_youtube_set_consent_v1($pdo, (int)$user['id'], $action === 'enable',
                $action === 'enable' && ($_POST['record_vp3_playback'] ?? null) === '1');
            $result = $action === 'enable' ? 'youtube_enabled' : 'youtube_disabled';
        } catch (Throwable $e) {
            $result = 'youtube_unavailable';
        }
    }
}
redirect(url('/connections.php?result='.$result.'#connections-app-youtube'));
