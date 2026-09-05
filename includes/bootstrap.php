<?php
declare(strict_types=1);

if (!headers_sent()) {
    header('Permissions-Policy: microphone=(self), camera=(self), midi=(self)');
}

if (session_status() !== PHP_SESSION_ACTIVE) {
    $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
    session_set_cookie_params([
        'httponly' => true,
        'secure' => $secure,
        'samesite' => 'Lax',
        'path' => '/',
    ]);
    session_start();
}

define('STONEFELLOW_ROOT', dirname(__DIR__));

$configFile = STONEFELLOW_ROOT . '/config.php';
$config = is_file($configFile) ? require $configFile : [
    'db' => ['dsn' => '', 'user' => '', 'pass' => ''],
    'site' => ['name' => 'Stonefellow','email' => 'stonefellow74@gmail.com','base_path' => '','send_contact_email' => false],
    'uploads' => ['max_audio_bytes' => 25 * 1024 * 1024,'max_image_bytes' => 5 * 1024 * 1024,'max_stem_package_bytes' => 2 * 1024 * 1024 * 1024,'stem_chunk_bytes' => 8 * 1024 * 1024,'max_user_photo_bytes' => 25 * 1024 * 1024,'max_user_recording_bytes' => 512 * 1024 * 1024,'max_user_video_bytes' => 4 * 1024 * 1024 * 1024],
];

require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/agent-runtime-v125.php';
require_once __DIR__ . '/agent-ops-v126.php';
if (!headers_sent()) header('X-Stonefellow-Production: ' . STONEFELLOW_PRODUCTION_V126);
require_once __DIR__ . '/permissions.php';
require_once __DIR__ . '/chat-settings-v237.php';
require_once __DIR__ . '/permissions-v105.php';
require_once __DIR__ . '/user-agent-system-v236.php';
require_once __DIR__ . '/user-data-usage-v236.php';
require_once __DIR__ . '/midi-v217.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/notifications.php';
require_once __DIR__ . '/artist-workspaces-v104.php';
require_once __DIR__ . '/artist-workspace-v181.php';
require_once __DIR__ . '/artist-media-v182.php';
require_once __DIR__ . '/artist-posts-v183.php';
require_once __DIR__ . '/artist-shows-v184.php';
require_once __DIR__ . '/artist-music-v185.php';
require_once __DIR__ . '/artist-admin-routing-v185.php';
require_once __DIR__ . '/release-agent-v105.php';
require_once __DIR__ . '/agent-integrations-v105.php';
require_once __DIR__ . '/release-guard-v105.php';
require_once __DIR__ . '/knowledge.php';
require_once __DIR__ . '/shared-knowledge-index-v236.php';
require_once __DIR__ . '/recommendations.php';
require_once __DIR__ . '/player-v76.php';
require_once __DIR__ . '/stems-v30.php';
require_once __DIR__ . '/agent-brain-v82.php';
require_once __DIR__ . '/agent-brain-v122.php';
require_once __DIR__ . '/agent-brain-runtime-v125.php';
require_once __DIR__ . '/agent-memory-lifecycle-v123.php';
require_once __DIR__ . '/agent-task-lifecycle-v123.php';
require_once __DIR__ . '/agent-brain-context-v142.php';
require_once __DIR__ . '/agent-proactive-v93.php';
require_once __DIR__ . '/agent-action-system-v124.php';
require_once __DIR__ . '/agent-proactive-v123.php';
require_once __DIR__ . '/release-proactive-v105.php';
require_once __DIR__ . '/agent-proactive-rescore-v123.php';
require_once __DIR__ . '/agent-activity-v94.php';
require_once __DIR__ . '/agent-chat-continuity-v101.php';
require_once __DIR__ . '/crm-v180.php';
require_once __DIR__ . '/agent-ecosystem-v118.php';
require_once __DIR__ . '/agent-surface-context-v131.php';
require_once __DIR__ . '/media-studio-v86.php';
require_once __DIR__ . '/agent-tools-v84.php';
require_once __DIR__ . '/ai-settings.php';
require_once __DIR__ . '/ai-runtime-v100.php';
require_once __DIR__ . '/agent-tools-v90.php';
require_once __DIR__ . '/agent-tools-v91.php';
require_once __DIR__ . '/chat-engine.php';
require_once __DIR__ . '/chat-agent-policy-v236.php';
require_once __DIR__ . '/profile-agent.php';
require_once __DIR__ . '/profile-agent-runtime.php';
require_once __DIR__ . '/chat-onboarding-v241.php';
require_once __DIR__ . '/member-navigation.php';
require_once __DIR__ . '/release-chat-v105.php';

permission_v105_enforce_request_gates();
artist_admin_routing_v185_apply();
agent_runtime_v125_boot();
agent_runtime_v126_housekeeping_maybe();