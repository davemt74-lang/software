<?php
declare(strict_types=1);
require __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/agent-connections-v1.php';
require_once __DIR__ . '/includes/agent-connection-youtube-v1.php';
require_once __DIR__ . '/includes/agent-connection-health-v1.php';
require_once __DIR__ . '/includes/agent-connection-youtube-playback-v1.php';
require_once __DIR__ . '/includes/agent-connection-youtube-consent-v1.php';
require_once __DIR__ . '/includes/agent-connection-gmail-inbox-settings-v1.php';
require_once __DIR__ . '/includes/agent-connection-gmail-inbox-initial-v1.php';
require_once __DIR__ . '/includes/agent-connection-gmail-inbox-status-v1.php';
require_once __DIR__ . '/includes/agent-initiative-native-music-v1.php';
require_once __DIR__ . '/includes/agent-media-entertainment-v1.php';
require_once __DIR__ . '/includes/agent-casual-checkin-settings-v1.php';
require_once __DIR__ . '/includes/agent-transcript-research-policy-v1.php';
require_once __DIR__ . '/includes/agent-connection-settings-view-v1.php';
require_permission('account.access');

$user = current_user();
$embedded = ($_GET['view'] ?? '') === 'specialist-settings';
$embeddedApp = $embedded ? vp3_connection_settings_app_v1($_GET['app'] ?? null) : '';
$expectedOwner = $_GET['owner_user_id'] ?? null;
if ($embedded) header("Content-Security-Policy: frame-ancestors 'self'");
if ($embedded && ($embeddedApp === '' || !vp3_connection_settings_private_v1($user)
    || ($expectedOwner !== null && $expectedOwner !== (string)$user['id']))) {
    http_response_code($embeddedApp === '' ? 400 : 403);
    header('Cache-Control: private, no-store');
    header('Content-Type: text/plain; charset=UTF-8');
    exit('Specialist connection settings are unavailable.');
}
$settingsField = $embedded
    ? '<input type="hidden" name="settings_app" value="'.e($embeddedApp).'">'
        .'<input type="hidden" name="settings_owner_user_id" value="'.(int)$user['id'].'">'
    : '';
$appLinkTarget = $embedded ? ' target="_blank" rel="noopener noreferrer"' : '';
$researchOnly = $embeddedApp === 'research';
$embeddedProvider = match ($embeddedApp) {
    'research', 'music' => 'youtube', default => $embeddedApp,
};
$showGmail = !$embedded || $embeddedProvider === 'gmail';
$showYoutube = !$embedded || $embeddedProvider === 'youtube';
$showNativeMusic = !$embedded || in_array($embeddedApp, ['research','music'], true);
$showTranscriptResearch = !$embedded || in_array($embeddedApp, ['research','transcripts'], true);
$nativeSettingsAction = vp3_connection_settings_return_v1($embeddedApp, ['research','transcripts','music'], '/connections.php',
    ['owner_user_id'=>(string)$user['id']]);
$summary = $showGmail ? agent_connections_summary_v1((int)$user['id'], 'gmail') : [];
$connection = $summary['connection'] ?? null;
$status = $connection['status'] ?? 'disconnected';
$connected = $status === 'connected';
$reauthorize = $status === 'reauthorize';
$gmailLinked = $connected || $reauthorize;
$providerReady = !empty($summary['provider_ready']);
$youtubeReady = $showYoutube && agent_connection_youtube_ready_v1();
$pdo = db();
$youtubeConsentReady = $showYoutube && $pdo && agent_connection_youtube_consent_ready_v1($pdo);
$youtubeHistoryReady = $showYoutube && $pdo && vp3_youtube_playback_ready_v1($pdo);
$youtubeHistoryEnabled = !$researchOnly && $youtubeHistoryReady && vp3_youtube_capture_key_v1($pdo, (int)$user['id']) !== '';
$youtubeWatchReady = $youtubeHistoryReady && vp3_youtube_watch_ready_v1($pdo);
$youtubePicksReady = $showYoutube && !$researchOnly && $pdo && vp3_youtube_picks_ready_v1($pdo);
$youtubePicks = $youtubePicksReady ? vp3_youtube_picks_settings_v1($pdo, (int)$user['id']) : [];
$youtubeEnabled = $youtubeConsentReady && agent_connection_youtube_consent_v1($pdo, (int)$user['id']);
$youtubeArtistResearch = $youtubeEnabled && vp3_youtube_artist_research_enabled_v1($pdo, (int)$user['id']);
$youtubeTopicResearch = $youtubeEnabled && vp3_youtube_research_enabled_v1($pdo, (int)$user['id'], 'topic');
$youtubeWatchResearch = $youtubeEnabled && vp3_youtube_watch_research_enabled_v1($pdo, (int)$user['id']);
$canChat = has_permission('chat.access', $user);
$showCasualCheckins = !$embedded && $pdo && vp3_connection_settings_private_v1($user);
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && ($_POST['action'] ?? '') === 'save_casual_checkins') {
    $preference = $_POST['casual_checkins'] ?? '';
    $saved = false;
    if ($showCasualCheckins && !array_key_exists('settings_app', $_POST)
        && ($_POST['casual_checkin_owner'] ?? null) === (string)$user['id']
        && in_array($preference, ['', '0', '1', 'default'], true)
        && is_string($_POST['csrf_token'] ?? null) && verify_csrf()) {
        try {
            vp3_casual_checkin_save_v1($pdo, (int)$user['id'], $preference === 'default' ? null : $preference === '1');
            $saved = true;
        } catch (RuntimeException | InvalidArgumentException) {}
    }
    redirect(url('/connections.php?casual_checkins='.($saved ? 'saved' : 'failed').'#casual-checkins'));
}
$casualCheckins = false;
if ($showCasualCheckins) {
    try { $casualCheckins = vp3_casual_checkin_preference_v1($pdo, (int)$user['id']); }
    catch (PDOException) {}
}
$canManageTranscriptResearch = $canChat && (int)$user['id'] > 0
    && (!function_exists('vp3_participant_scope_v1') || vp3_participant_scope_v1() === null);
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && ($_POST['action'] ?? '') === 'save_transcript_research') {
    $saved = false;
    $preference = $_POST['transcript_research'] ?? '';
    if ($pdo && $canManageTranscriptResearch && vp3_connection_settings_post_allowed_v1($user)
        && ($_POST['transcript_research_owner'] ?? null) === (string)$user['id']
        && in_array($preference, ['', '1'], true)
        && is_string($_POST['csrf_token'] ?? null) && verify_csrf()) {
        try {
            vp3_transcript_research_save_v1($pdo, (int)$user['id'], $preference === '1');
            $saved = true;
        } catch (PDOException | DomainException) {}
    }
    $result = $saved ? 'saved' : 'failed';
    redirect(vp3_connection_settings_post_return_v1(['research','transcripts'],
        '/connections.php?transcript_research='.$result.'#transcript-research', ['transcript_research'=>$result], 'transcript-research'));
}
$transcriptResearch = false;
if ($showTranscriptResearch && $pdo && $canManageTranscriptResearch) {
    try { $transcriptResearch = vp3_transcript_research_grant_v1($pdo, (int)$user['id']) !== ''; }
    catch (PDOException) {}
}
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && ($_POST['action'] ?? '') === 'save_native_music_research') {
    $saved = false;
    if ($pdo && $canChat && vp3_connection_settings_post_allowed_v1($user) && verify_csrf()) {
        try {
            vp3_native_music_research_save_v1($pdo, (int)$user['id'], ($_POST['native_music_research'] ?? '') === '1');
            $saved = true;
        } catch (PDOException | DomainException) {}
    }
    $result = $saved ? 'saved' : 'failed';
    redirect(vp3_connection_settings_post_return_v1(['research','music'],
        '/connections.php?music_research='.$result.'#music-research', ['music_research'=>$result], 'music-research'));
}
$nativeMusicResearch = false;
if ($showNativeMusic && $pdo && $canChat) {
    try { $nativeMusicResearch = vp3_native_music_research_grant_v1($pdo, (int)$user['id']) !== ''; }
    catch (PDOException) {}
}
$mediaOffersAction = vp3_connection_settings_return_v1($embeddedApp, ['music'], '/connections.php',
    ['owner_user_id'=>(string)$user['id']]);
$mediaOffersAllowed = (!$embedded || $embeddedApp === 'music') && $pdo && vp3_connection_settings_private_v1($user);
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && ($_POST['action'] ?? '') === 'save_native_media_offers') {
    $saved = false;
    $preference = $_POST['native_media_offers'] ?? '';
    $taste = $_POST['native_media_taste'] ?? null;
    if ($mediaOffersAllowed && vp3_connection_settings_post_allowed_v1($user)
        && (!array_key_exists('settings_app', $_POST) || $_POST['settings_app'] === 'music')
        && in_array($preference, ['', '1'], true) && ($taste === null || is_string($taste))
        && is_string($_POST['csrf_token'] ?? null) && verify_csrf()) {
        try {
            vp3_media_offers_save_v1($pdo, (int)$user['id'], $preference === '1', $taste);
            $saved = true;
        } catch (RuntimeException | InvalidArgumentException) {}
    }
    $result = $saved ? 'saved' : 'failed';
    redirect(vp3_connection_settings_post_return_v1(['music'],
        '/connections.php?native_media_offers='.$result.'#media-offers', ['native_media_offers'=>$result], 'media-offers'));
}
$nativeMediaOffers = false;
$nativeMediaTaste = '';
if ($mediaOffersAllowed) {
    try {
        $nativeMediaOffers = vp3_media_offers_enabled_v1($pdo, (int)$user['id']);
        $nativeMediaTaste = vp3_media_native_taste_v1($pdo, (int)$user['id']);
    }
    catch (PDOException) {}
}
$autosend = $connected && $pdo ? agent_connection_gmail_autosend_settings_v1($pdo, $user, (int)$connection['id']) : null;
$permissions = $gmailLinked
    ? $connection['permissions']
    : ['read' => true, 'send' => true, 'followups' => false];
$inboxReady = $connected && $pdo && agent_connection_gmail_inbox_storage_ready_v1($pdo);
$inboxPolicy = $inboxReady ? agent_connection_gmail_inbox_policy_v1($pdo, (int)$user['id']) : null;
$inboxRules = $inboxPolicy['rules'] ?? [];
$inboxGrantChanged = $inboxPolicy && ((int)$inboxPolicy['connection_id'] !== (int)$connection['id']
    || !hash_equals($connection['revision'], hash('sha256', $inboxPolicy['authorization_key'])));
if ($inboxGrantChanged) {
    $inboxPolicy = null;
    unset($inboxRules['triage_enabled'], $inboxRules['content_analysis']);
}
$inboxCanEnable = $inboxReady && $providerReady && agent_connection_gmail_inbox_authority_v1($user, $connection);
$inboxEnabled = !empty($inboxPolicy['enabled']);
$inboxActive = $inboxCanEnable && $inboxEnabled;
$inboxMonitor = $inboxActive ? agent_connection_gmail_inbox_background_status_v1($pdo, $user, includeResults: false) : null;
$inboxSync = $inboxActive ? agent_connection_gmail_inbox_sync_view_v1($pdo, $inboxPolicy) : null;
$inboxStatus = $inboxMonitor['state'] ?? ($inboxActive ? ($inboxPolicy['last_status'] ?? '') : '');
$inboxCheckedAt = $inboxActive ? ($inboxPolicy['last_checked_at'] ?? '') : '';
$inboxNextCheckAt = $inboxMonitor['next_check_at'] ?? '';
$inboxReview = !$embedded && $inboxActive && !empty($inboxPolicy['last_review_json'])
    ? agent_connection_gmail_inbox_latest_view_v1($pdo, $user, $inboxPolicy['revision']) : null;
$inboxPriorityCount = count(array_filter($inboxReview['items'] ?? [], static fn(array $item): bool => !empty($item['priority_signals'])));
$inboxInitialReview = !$embedded && $inboxActive ? agent_connection_gmail_inbox_initial_view_v1($pdo, $user, true, $inboxPolicy['revision']) : null;
$inboxRuleFields = [
    'senders' => ['Sender addresses', 'person@example.com'],
    'domains' => ['Sender domains', 'example.com'],
    'subjects' => ['Subject phrases', 'Build completed'],
];
$inboxCategories = ['reply' => 'Incoming reply', 'important' => 'Important', 'new' => 'New'];
$action = $connected ? 'update_policy' : 'start';
$labels = [
    'read' => ['Search and read Gmail', 'In typed Agent Chat, try “Find emails from sender@example.com” to search your inbox.'],
    'send' => ['Allow Gmail sends', 'Review drafts before sending, or enable Auto Send for specific addresses below.'],
    'followups' => ['Watch for senders and replies in the background', 'Requires Gmail read access and a specific request. Try “Notify me when I get an email from sender@example.com.” Watches can run while your browser is closed.'],
];
$notices = [
    'connected' => ['success', 'Gmail connected.'],
    'updated' => ['success', 'Agent permissions saved.'],
    'disconnected' => ['success', 'Gmail disconnected from VP3.'],
    'unavailable' => ['error', 'Gmail is unavailable on this installation.'],
    'invalid' => ['error', 'The connection request expired or was invalid. Review your settings and try again.'],
    'failed' => ['error', 'The Gmail request could not be completed. Try again in a moment.'],
    'denied' => ['error', 'Gmail authorization was declined or the required permissions were unavailable.'],
    'offline' => ['error', 'Google did not grant offline Gmail access. Remove the VP3 Gmail connection under your Google Account’s third-party connections, then use the popup again.'],
    'conflict' => ['error', 'The connection changed while you were working. Review its current state and try again.'],
    'tested' => ['info', 'Connection check complete. See the result under Gmail.'],
    'test_invalid' => ['error', 'The connection changed or the test request expired. Open Gmail and try again.'],
    'test_unavailable' => ['error', 'Gmail connection testing is currently unavailable.'],
    'test_limited' => ['info', 'Gmail was checked recently. Wait a moment and try again.'],
    'youtube_picks_saved' => ['success', 'Your YouTube music preferences were saved.'],
    'youtube_history_saved' => ['success', 'Your VP3 playback history settings were updated.'],
    'inbox_saved' => ['success', 'Inbox review settings saved.'],
    'inbox_sync_continued' => ['success', 'Gmail catch-up will continue from the last saved page.'],
    'inbox_restarted' => ['success', 'Inbox review restart scheduled.'],
    'inbox_disabled' => ['success', 'Inbox review is off. Your ignore list was saved.'],
    'inbox_invalid' => ['error', 'The request or an ignore list entry was invalid. Check your entries and try again.'],
    'inbox_conflict' => ['error', 'Gmail or these settings changed while you were working. Review the current settings and try again.'],
    'inbox_unavailable' => ['error', 'Inbox review is currently unavailable.'],
    'inbox_failed' => ['error', 'Inbox review settings could not be saved. Try again in a moment.'],
    'inbox_reconnect' => ['error', 'Reconnect Gmail before enabling Inbox review.'],
    'autosend_saved' => ['success', 'Auto Send settings saved.'],
    'autosend_invalid' => ['error', 'Enter up to 20 exact email addresses, one per line. Auto Send needs at least one approved address.'],
    'autosend_conflict' => ['error', 'Gmail or Auto Send settings changed. Review the current settings and try again.'],
    'autosend_unavailable' => ['error', 'Auto Send needs a working Gmail connection and permission to read and send.'],
    'youtube_enabled' => ['success', 'YouTube is enabled for your VP3 account.'],
    'youtube_disabled' => ['success', 'YouTube is disabled for your VP3 account.'],
    'youtube_artist_research_saved' => ['success', 'Background artist research preference saved.'],
    'youtube_topic_research_saved' => ['success', 'Background topic research preference saved.'],
    'youtube_watch_research_saved' => ['success', 'Watch-time preference saved.'],
    'youtube_accept_required' => ['error', 'Review and accept the terms to enable YouTube.'],
    'youtube_unavailable' => ['error', 'YouTube is currently unavailable on this installation.'],
    'youtube_invalid' => ['error', 'The YouTube request expired or was invalid. Review the terms and try again.'],
];
$result = $_GET['result'] ?? '';
$notice = is_string($result) ? ($notices[$result] ?? null) : null;
$inboxNotice = $connected && is_string($result) && str_starts_with($result, 'inbox_') ? $notice : null;
$autosendNotice = $connected && is_string($result) && str_starts_with($result, 'autosend_') ? $notice : null;
$noticeProvider = is_string($result) && str_starts_with($result, 'youtube_') ? 'youtube' : 'gmail';
$selectedApp = $_GET['app'] ?? '';
$openApp = $notice && !in_array($result, ['disconnected', 'youtube_disabled'], true)
    ? $noticeProvider
    : (in_array($selectedApp, ['gmail', 'youtube'], true) ? $selectedApp : '');
$endpoint = url('/connection-oauth.php');
$health = [
    'gmail' => $pdo && $gmailLinked ? agent_connection_health_member_v1($pdo, (int)$user['id'], (int)$connection['id']) : null,
    'youtube' => $showYoutube && !$researchOnly && $pdo ? agent_connection_health_platform_v1($pdo, 'youtube') : null,
];
$gmailNeedsAttention = ($health['gmail']['status'] ?? null) === 'needs_attention';
$gmailPopupAvailable = $providerReady && (!$connected || $gmailNeedsAttention);
$youtubeNeedsAttention = ($health['youtube']['status'] ?? null) === 'needs_attention';
$apps = [
    'gmail' => [
        'name' => 'Gmail',
        'description' => $gmailLinked && !empty($connection['account_email'])
            ? $connection['account_email']
            : 'Search mail, review sends, and watch for replies.',
        'linked' => $gmailLinked,
        'status' => match (true) {
            $reauthorize => 'Reconnect required',
            !$providerReady => 'Unavailable',
            $connected && $gmailNeedsAttention => 'Needs attention',
            $connected => 'Connected',
            default => 'Ready to connect',
        },
        'ready' => $connected && $providerReady && !$gmailNeedsAttention,
    ],
    'youtube' => [
        'name' => 'YouTube',
        'description' => 'Search public videos and play them in Agent Chat.',
        'linked' => $youtubeEnabled,
        'status' => match (true) {
            !$youtubeConsentReady => 'Setup required',
            !$youtubeReady && $youtubeEnabled => 'Unavailable',
            !$youtubeReady => 'Setup required',
            $youtubeNeedsAttention => 'Needs attention',
            $youtubeEnabled => 'Enabled',
            default => 'Ready to enable',
        },
        'ready' => $youtubeReady && $youtubeEnabled && !$youtubeNeedsAttention,
    ],
];
$healthLabels = [
    'healthy' => 'Verified',
    'setup_ready' => 'Setup ready',
    'needs_attention' => 'Needs attention',
    'unchecked' => 'Untested',
    'unavailable' => 'Unavailable',
];
$sections = [
    'connected' => ['Connected apps', array_filter($apps, static fn(array $app): bool => $app['linked'])],
    'available' => ['Available apps', array_filter($apps, static fn(array $app): bool => !$app['linked'])],
];
if ($embedded) {
    $openApp = $embeddedProvider;
    $sections = isset($apps[$embeddedProvider]) ? ['settings'=>['', [$embeddedProvider=>$apps[$embeddedProvider]]]] : [];
}
$settingsTitle = match ($embeddedApp) {
    'gmail'=>'Gmail settings', 'youtube'=>'YouTube settings', 'research'=>'Research settings',
    'transcripts'=>'Saved Transcripts settings', 'music'=>'Music settings', default=>'Connections',
};
$gmailConnectionChanged = !$embedded && ($result === 'connected' || $result === 'updated' || $result === 'disconnected');

header('Cache-Control: private, no-store');
?>
<!doctype html>
<html lang="en"<?= $embedded ? ' class="connections-embedded-document"' : '' ?>>
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title><?= e($settingsTitle) ?> | <?= e(system_agent_name()) ?></title>
  <link rel="stylesheet" href="<?= e(url('/chat.css?v=82')) ?>">
  <link rel="stylesheet" href="<?= e(url('/connections-v1.css?v=' . substr(hash_file('sha256', __DIR__ . '/connections-v1.css'), 0, 12))) ?>">
  <?php if ($inboxInitialReview !== null): ?><link rel="stylesheet" href="<?= e(url('/chat-email-v1.css?v=' . substr(hash_file('sha256', __DIR__ . '/chat-email-v1.css'), 0, 12))) ?>"><?php endif; ?>
</head>
<body<?= $embedded ? ' class="connections-embedded" data-vp3-specialist-settings="'.e($embeddedApp).'" data-owner-user-id="'.(int)$user['id'].'"' : '' ?><?= $embeddedApp === 'gmail' || $gmailConnectionChanged ? ' data-connection-settings-owner="'.(int)$user['id'].'"' : '' ?><?= $gmailConnectionChanged ? ' data-connection-settings-changed="gmail"' : '' ?>>
<?php if ($embedded): ?>
  <main class="connections-content" aria-label="<?= e($settingsTitle) ?>" data-connection-settings-content>
<?php else: ?>
<div class="chat-app">
  <?php $mainSidebarUser = $user; $mainSidebarActive = 'connections'; require __DIR__ . '/includes/main-sidebar.php'; ?>
  <div class="chat-sidebar-backdrop" id="chatSidebarBackdrop"></div>
  <main class="chat-main connections-main">
    <?php
    $memberHeaderUser = $user;
    $memberHeaderTitle = 'Connections';
    $memberHeaderSubtitle = 'Manage app access';
    require __DIR__ . '/includes/member-header.php';
    ?>
    <div class="connections-scroll">
      <div class="connections-content">
        <h1>Connections</h1>
        <p class="connections-muted">Manage your connected apps and see what your Agent can use.</p>
<?php endif; ?>

        <?php if ($notice && !$inboxNotice && !$autosendNotice): ?>
          <p class="connections-notice connections-<?= e($notice[0]) ?>" role="<?= $notice[0] === 'error' ? 'alert' : 'status' ?>"><?= e($notice[1]) ?></p>
        <?php endif; ?>

        <?php foreach ($sections as $section => [$title, $sectionApps]): ?>
          <?php if (!$sectionApps && $section === 'available') continue; ?>
          <section class="connections-section"<?= !$embedded ? ' aria-labelledby="connections-'.e($section).'-title"' : '' ?>>
            <?php if (!$embedded): ?><h2 id="connections-<?= e($section) ?>-title"><?= e($title) ?></h2><?php endif; ?>
            <?php if (!$sectionApps): ?>
              <p class="connections-empty connections-muted">No apps are connected yet.</p>
            <?php else: ?>
              <ul class="connections-app-list" role="list">
                <?php foreach ($sectionApps as $provider => $app): ?>
                  <li>
                    <details class="connections-app" id="connections-app-<?= e($provider) ?>"<?= $provider === $openApp ? ' open' : '' ?>>
                      <summary class="connections-app-summary">
                        <span class="connections-app-icon connections-icon-<?= e($provider) ?>" aria-hidden="true">
                          <?php if ($provider === 'gmail'): ?>
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="5" width="18" height="14" rx="3"/><path d="m4 7 8 6 8-6"/></svg>
                          <?php else: ?>
                            <svg viewBox="0 0 24 24" fill="currentColor"><path d="M21.6 7.2A2.9 2.9 0 0 0 19.5 5c-1.9-.5-7.5-.5-7.5-.5s-5.6 0-7.5.5a2.9 2.9 0 0 0-2.1 2.2A30.4 30.4 0 0 0 2 12c0 1.6.1 3.2.4 4.8A2.9 2.9 0 0 0 4.5 19c1.9.5 7.5.5 7.5.5s5.6 0 7.5-.5a2.9 2.9 0 0 0 2.1-2.2A30.4 30.4 0 0 0 22 12c0-1.6-.1-3.2-.4-4.8ZM10 15.4V8.6l5.8 3.4-5.8 3.4Z"/></svg>
                          <?php endif; ?>
                        </span>
                        <span class="connections-app-label">
                          <span class="connections-app-title"><strong><?= e($app['name']) ?></strong><span class="connections-skill-tag">Official VP3 skill</span></span>
                          <span class="connections-muted"><?= e($app['description']) ?></span>
                        </span>
                        <span class="connections-badge<?= $app['ready'] ? ' is-connected' : '' ?>"><?= e($app['status']) ?></span>
                        <span class="connections-app-chevron" aria-hidden="true"></span>
                      </summary>
                      <div class="connections-app-body">
                        <?php if (!$researchOnly && ($appHealth = $health[$provider])): ?>
                          <div class="connections-health">
                            <strong>Connection check · <?= e($healthLabels[$appHealth['status']] ?? 'Unknown') ?></strong>
                            <?php if (!empty($appHealth['checked_at'])): ?>
                              <time datetime="<?= e(str_replace(' ', 'T', $appHealth['checked_at']) . 'Z') ?>"><?= e($appHealth['checked_at']) ?> UTC</time>
                            <?php endif; ?>
                            <?php if (!empty($appHealth['message'])): ?><p><?= e($appHealth['message']) ?></p><?php endif; ?>
                            <?php if ($provider === 'gmail' && $connected && $providerReady): ?>
                              <form class="connections-test" method="post" action="<?= e(url('/connection-test.php')) ?>">
                                <?= csrf_field() ?>
                                <?= $settingsField ?>
                                <input type="hidden" name="provider" value="gmail">
                                <input type="hidden" name="connection_id" value="<?= (int)$connection['id'] ?>">
                                <input type="hidden" name="revision" value="<?= e($connection['revision']) ?>">
                                <button class="connections-button connections-secondary" type="submit">Test connection</button>
                              </form>
                            <?php endif; ?>
                          </div>
                        <?php endif; ?>
                        <?php if ($provider === 'gmail'): ?>
                          <?php if (empty($summary['schema_ready'])): ?>
                            <p class="connections-notice">Gmail will be available after an administrator runs Database Upgrade.</p>
                          <?php else: ?>
                            <?php if (!$providerReady): ?>
                              <p class="connections-notice">Gmail is currently unavailable on this installation. Contact an administrator for help.</p>
                            <?php elseif ($reauthorize): ?>
                              <p class="connections-notice">Reconnect Gmail to resume work with this mailbox.</p>
                            <?php endif; ?>

                            <?php if ($providerReady || $connected): ?>
                              <form method="post" action="<?= e($endpoint) ?>"<?= $embedded ? ' rel="noopener"' : '' ?>>
                                <?= csrf_field() ?>
                                <?= $settingsField ?>
                                <input type="hidden" name="provider" value="gmail">
                                <?php if ($gmailLinked): ?>
                                  <input type="hidden" name="connection_id" value="<?= (int)$connection['id'] ?>">
                                  <input type="hidden" name="revision" value="<?= e($connection['revision']) ?>">
                                <?php endif; ?>
                                <fieldset class="connections-permissions" aria-describedby="connections-policy-note">
                                  <legend>Agent permissions</legend>
                                  <p class="connections-muted" id="connections-policy-note">Google asks you to authorize your Google account identity and email address, plus Gmail read and send access. These settings control when your Agent may use that access in VP3.</p>
                                  <?php foreach ($labels as $permission => [$label, $description]): ?>
                                    <label class="connections-permission">
                                      <input type="checkbox" name="allow_<?= e($permission) ?>" value="1"<?= !empty($permissions[$permission]) ? ' checked' : '' ?>>
                                      <span><strong><?= e($label) ?></strong><small><?= e($description) ?></small></span>
                                    </label>
                                  <?php endforeach; ?>
                                </fieldset>
                                <div class="connections-actions">
                                  <button class="connections-button" type="submit" name="action" value="<?= e($action) ?>"<?= $embedded && $action === 'start' ? ' formtarget="_blank"' : '' ?>><?= $connected ? 'Save permissions' : ($reauthorize ? 'Reconnect Gmail' : 'Connect Gmail') ?></button>
                                  <?php if ($connected && $providerReady && $gmailNeedsAttention): ?>
                                    <button class="connections-button connections-secondary" type="submit" name="action" value="start"<?= $embedded ? ' formtarget="_blank"' : '' ?>>Reconnect Gmail</button>
                                  <?php endif; ?>
                                  <?php if ($gmailPopupAvailable): ?>
                                    <button class="connections-button connections-secondary" type="submit" name="action" value="prepare" formaction="<?= e(url('/connection-gmail-popup.php')) ?>" aria-describedby="connections-gmail-popup-note"<?= $embedded ? ' formtarget="_blank"' : '' ?>>Use Google popup</button>
                                  <?php endif; ?>
                                </div>
                                <?php if ($gmailPopupAvailable): ?>
                                  <p class="connections-muted" id="connections-gmail-popup-note">Use the popup if your hosting provider blocks Google’s return page.</p>
                                <?php endif; ?>
                              </form>
                            <?php endif; ?>

                            <?php if ($connected): ?>
                              <section class="connections-inbox" id="gmail-inbox-review" aria-labelledby="gmail-inbox-review-title">
                                <h3 id="gmail-inbox-review-title">Inbox review</h3>
                                <p class="connections-muted">Review recent Gmail headers once, then let your Agent check new arrivals in the background. Sender and reply watches run separately.</p>
                                <?php if ($inboxNotice): ?>
                                  <p class="connections-notice connections-<?= e($inboxNotice[0]) ?>" role="<?= $inboxNotice[0] === 'error' ? 'alert' : 'status' ?>"><?= e($inboxNotice[1]) ?></p>
                                <?php endif; ?>
                                <?php if (!$inboxReady): ?>
                                  <p class="connections-notice">Inbox review will be available after an administrator runs Database Upgrade.</p>
                                <?php else: ?>
                                  <?php if (!$inboxCanEnable): ?>
                                    <p class="connections-notice">Inbox review requires a working Gmail connection, permission to search and read Gmail, and access to Agent Chat.</p>
                                  <?php elseif (!$inboxEnabled): ?>
                                    <p class="connections-notice">Inbox review is off for this Gmail connection. Your ignore list is still available below.</p>
                                  <?php elseif ($inboxStatus === 'restart_required' && empty($inboxSync['can_continue'])): ?>
                                    <form class="connections-notice" method="post" action="<?= e(url('/connection-gmail-inbox.php')) ?>">
                                      <?= csrf_field() ?>
                                      <?= $settingsField ?>
                                      <input type="hidden" name="connection_id" value="<?= (int)$connection['id'] ?>">
                                      <input type="hidden" name="connection_revision" value="<?= e($connection['revision']) ?>">
                                      <input type="hidden" name="inbox_revision" value="<?= e($inboxPolicy['revision']) ?>">
                                      <p><?= !empty($inboxRules['triage_enabled'])
                                        ? 'Gmail’s review position expired. Restart to begin another bounded review of recent inbox mail.'
                                        : 'Gmail’s review position expired. Restart to review messages that arrive from now on.' ?></p>
                                      <button class="connections-button connections-secondary" type="submit" name="action" value="restart">Restart Inbox review</button>
                                    </form>
                                  <?php elseif ($inboxStatus === 'overdue'): ?>
                                    <p class="connections-notice">The new-mail check is overdue. <a href="<?= e(url('/chat.php')) ?>"<?= $appLinkTarget ?>>Open Agent Chat</a> to catch up.</p>
                                  <?php elseif ($inboxStatus === 'working' && ($inboxSync['status'] ?? '') !== 'pending'): ?>
                                    <p class="connections-muted">Checking new Gmail messages.</p>
                                  <?php elseif ($inboxStatus === 'retrying'): ?>
                                    <p class="connections-notice">The last background check could not finish. Inbox review will try again.</p>
                                  <?php elseif ($inboxStatus === 'unavailable'): ?>
                                    <p class="connections-notice">New-mail checking is unavailable. Check Gmail connection and database upgrade status.</p>
                                  <?php elseif (!$embedded && $inboxStatus === 'checked' && !is_array($inboxReview)): ?>
                                    <p class="connections-muted">No new inbox messages found yet.</p>
                                  <?php endif; ?>
                                  <?php if ($inboxCheckedAt): ?>
                                    <p class="connections-muted connections-inbox-time">Last checked <time datetime="<?= e(str_replace(' ', 'T', $inboxCheckedAt) . 'Z') ?>"><?= e($inboxCheckedAt) ?> UTC</time></p>
                                  <?php elseif ($inboxActive): ?>
                                    <p class="connections-muted connections-inbox-time">Last checked · No recurring check recorded yet</p>
                                  <?php endif; ?>
                                  <?php if ($inboxNextCheckAt): ?>
                                    <p class="connections-muted connections-inbox-time"><?= $inboxStatus === 'overdue' ? 'Check due' : 'Next check' ?> <time datetime="<?= e($inboxNextCheckAt) ?>"><?= e(str_replace('T', ' ', substr($inboxNextCheckAt, 0, 19))) ?> UTC</time></p>
                                  <?php endif; ?>
                                  <?php if (is_array($inboxSync) && $inboxSync['status'] === 'pending'): ?>
                                    <p class="connections-muted" role="status">Reviewing recent Gmail quietly. Checked <?= (int)$inboxSync['checked'] ?> messages so far.</p>
                                  <?php elseif (is_array($inboxSync) && $inboxSync['status'] === 'complete'): ?>
                                    <p class="connections-muted" role="status">Recent Gmail review complete. Checked <?= (int)$inboxSync['checked'] ?> messages in the selected window and classified <?= (int)$inboxSync['reviewed'] ?>.</p>
                                  <?php elseif (is_array($inboxSync) && $inboxSync['status'] === 'truncated'): ?>
                                    <p class="connections-notice" role="status">Gmail catch-up is incomplete. Checked <?= (int)$inboxSync['checked'] ?> messages in the selected window.</p>
                                    <?php if (!empty($inboxSync['can_continue'])): ?>
                                      <form method="post" action="<?= e(url('/connection-gmail-inbox.php')) ?>">
                                        <?= csrf_field() ?>
                                        <?= $settingsField ?>
                                        <input type="hidden" name="connection_id" value="<?= (int)$connection['id'] ?>">
                                        <input type="hidden" name="connection_revision" value="<?= e($connection['revision']) ?>">
                                        <input type="hidden" name="inbox_revision" value="<?= e($inboxPolicy['revision']) ?>">
                                        <button class="connections-button connections-secondary" type="submit" name="action" value="continue_sync">Continue Gmail catch-up</button>
                                      </form>
                                    <?php endif; ?>
                                  <?php endif; ?>
                                  <?php if ($inboxInitialReview !== null): ?>
                                    <section class="connections-inbox-latest" data-gmail-initial-review aria-labelledby="gmail-initial-review-title">
                                      <h4 id="gmail-initial-review-title">First inbox review</h4>
                                      <p class="connections-muted" data-gmail-review-status role="status"></p>
                                      <form class="connections-inbox-review-start" data-gmail-review-start hidden>
                                        <p class="connections-muted" id="gmail-initial-review-help">Checks up to 50 inbox headers. The AI summary sends up to 8 sender/subject pairs to your configured Cloud AI and uses tokens. Message bodies are excluded.</p>
                                        <label>Look back <select name="days" aria-describedby="gmail-initial-review-help">
                                          <?php foreach ([1, 3, 7, 14, 30] as $days): ?>
                                            <option value="<?= $days ?>"<?= $days === 7 ? ' selected' : '' ?>><?= $days ?> <?= $days === 1 ? 'day' : 'days' ?></option>
                                          <?php endforeach; ?>
                                        </select></label>
                                        <button class="connections-button" type="submit">Start first review</button>
                                      </form>
                                      <div data-gmail-review-report></div>
                                    </section>
                                  <?php endif; ?>
                                  <?php if (is_array($inboxReview)): ?>
                                    <section class="connections-inbox-latest" data-gmail-review-latest-section aria-labelledby="gmail-inbox-latest-title">
                                      <h4 id="gmail-inbox-latest-title">Latest new-arrivals review</h4>
                                      <?php if (!empty($inboxReview['at'])): ?>
                                        <time class="connections-muted connections-inbox-time" datetime="<?= e(str_replace(' ', 'T', $inboxReview['at']) . 'Z') ?>"><?= e($inboxReview['at']) ?> UTC</time>
                                      <?php endif; ?>
                                      <dl class="connections-inbox-counts">
                                        <div><dt>New messages</dt><dd><?= (int)($inboxReview['total'] ?? 0) ?></dd></div>
                                        <div><dt>Important</dt><dd><?= (int)($inboxReview['important'] ?? 0) ?></dd></div>
                                        <div><dt>Incoming replies</dt><dd><?= (int)($inboxReview['replies'] ?? 0) ?></dd></div>
                                        <?php if ($inboxPriorityCount > 0): ?>
                                          <div><dt>Subjects with priority keywords</dt><dd><?= $inboxPriorityCount ?></dd></div>
                                        <?php endif; ?>
                                        <div><dt>Ignored</dt><dd><?= (int)($inboxReview['filtered'] ?? 0) ?></dd></div>
                                      </dl>
                                      <?php if (!empty($inboxReview['items'])): ?>
                                        <ul class="connections-inbox-messages" role="list">
                                          <?php foreach ($inboxReview['items'] as $item): ?>
                                            <?php if (!is_array($item)) continue; ?>
                                            <li data-gmail-review-latest-item data-item-ref="<?= e($item['item_ref'] ?? '') ?>" data-legacy-ref="<?= e($item['legacy_ref'] ?? '') ?>">
                                              <strong><?= e(($item['subject'] ?? '') ?: '(No subject)') ?></strong>
                                              <span class="connections-muted" data-gmail-review-sender><?= e($item['from'] ?? '') ?></span>
                                              <div class="gmail-review-item-labels">
                                                <span class="connections-badge"><?= e($inboxCategories[$item['category'] ?? ''] ?? 'New') ?></span>
                                                <?php if (($item['category'] ?? '') === 'reply' && ($item['important'] ?? false) === true): ?>
                                                  <span class="connections-badge"><?= e($inboxCategories['important']) ?></span>
                                                <?php endif; ?>
                                              </div>
                                              <?php if (!empty($item['priority_signals'])): ?>
                                                <small class="gmail-review-item-priority">Priority keywords in subject · <?= e(implode(', ', $item['priority_signals'])) ?></small>
                                              <?php endif; ?>
                                            </li>
                                          <?php endforeach; ?>
                                        </ul>
                                      <?php endif; ?>
                                    </section>
                                  <?php endif; ?>
                                  <form class="connections-inbox-form" method="post" action="<?= e(url('/connection-gmail-inbox.php')) ?>">
                                    <?= csrf_field() ?>
                                    <?= $settingsField ?>
                                    <input type="hidden" name="connection_id" value="<?= (int)$connection['id'] ?>">
                                    <input type="hidden" name="connection_revision" value="<?= e($connection['revision']) ?>">
                                    <input type="hidden" name="inbox_revision" value="<?= e($inboxPolicy['revision'] ?? '') ?>">
                                    <label class="connections-permission">
                                      <input type="checkbox" name="enabled" value="1"<?= $inboxEnabled ? ' checked' : '' ?><?= !$inboxCanEnable && !$inboxEnabled ? ' disabled' : '' ?>>
                                      <span><strong>Review new Gmail messages</strong><small>Enable background checks and choose when to start a first review of recent inbox mail.</small></span>
                                    </label>
                                    <label class="connections-permission">
                                      <input type="checkbox" name="triage_enabled" value="1"<?= !empty($inboxRules['triage_enabled']) ? ' checked' : '' ?>>
                                      <span><strong>Classify and prioritize inbox mail</strong><small>Allow the Email Specialist to read relevant message bodies and recent threads to identify deadlines and open commitments. Routine mail appears in grouped summaries.</small></span>
                                    </label>
                                    <label class="connections-permission">
                                      <input type="checkbox" name="content_analysis" value="1"<?= !empty($inboxRules['content_analysis']) ? ' checked' : '' ?><?= !agent_email_running_report_ready_v1($pdo) ? ' disabled' : '' ?>>
                                      <span><strong>Analyze email content for the running report</strong><small>Requires Email triage. Enabling analysis starts a quiet review of recent inbox mail. Sends excerpts from selected Gmail threads, including your replies, to the configured VP3 Cloud AI model to identify important findings and follow-up questions. Background analysis uses Cloud tokens.</small></span>
                                    </label>
                                    <label class="connections-permission">
                                      <input type="checkbox" name="catch_up_recent" value="1"<?= is_array($inboxSync)
                                        && ($inboxSync['status'] === 'pending' || ($inboxSync['status'] === 'truncated' && $inboxSync['mode'] === 'recovery')) ? ' disabled' : '' ?>>
                                      <span><strong>Catch up quietly on recent mail</strong><small>With classification enabled, review up to 200 inbox messages from the past seven days. Results appear in the summary without individual alerts.</small></span>
                                    </label>
                                    <fieldset class="connections-permissions" aria-describedby="gmail-inbox-ignore-note">
                                      <legend>Ignore list</legend>
                                      <p class="connections-muted" id="gmail-inbox-ignore-note">These rules apply to Inbox review. Enter up to 20 entries in each field, one per line. Sender addresses and domains match exactly. A subject matches if it contains a listed phrase, regardless of case.</p>
                                      <div class="connections-inbox-fields">
                                        <?php foreach ($inboxRuleFields as $kind => [$label, $placeholder]): ?>
                                          <label>
                                            <span><?= e($label) ?></span>
                                            <textarea name="ignore_<?= e($kind) ?>" rows="3" placeholder="<?= e($placeholder) ?>"><?= e(implode("\n", $inboxRules[$kind] ?? [])) ?></textarea>
                                          </label>
                                        <?php endforeach; ?>
                                      </div>
                                      <label class="connections-permission">
                                        <input type="checkbox" name="ignore_github_actions" value="1"<?= !empty($inboxRules['github_actions']) ? ' checked' : '' ?>>
                                        <span><strong>Ignore GitHub Actions workflow emails</strong><small>Other GitHub notifications remain eligible for review.</small></span>
                                      </label>
                                    </fieldset>
                                    <button class="connections-button" type="submit" name="action" value="save">Save Inbox review</button>
                                  </form>
                                <?php endif; ?>
                              </section>
                            <?php endif; ?>

                            <?php if ($autosend !== null): ?>
                              <section class="connections-inbox" id="gmail-autosend" aria-labelledby="gmail-autosend-title">
                                <h3 id="gmail-autosend-title">Auto Send</h3>
                                <?php if ($autosendNotice): ?>
                                  <p class="connections-notice connections-<?= e($autosendNotice[0]) ?>" role="<?= $autosendNotice[0] === 'error' ? 'alert' : 'status' ?>"><?= e($autosendNotice[1]) ?></p>
                                <?php endif; ?>
                                <?php if (!$autosend['ready']): ?>
                                  <p class="connections-muted">Auto Send is available after Database Upgrade.</p>
                                <?php elseif (!$autosend['available'] && $autosend['revision'] === ''): ?>
                                  <p class="connections-muted">Enable Gmail reading and sending to configure Auto Send.</p>
                                <?php else: ?>
                                  <form class="connections-inbox-form" method="post" action="<?= e(url('/connection-gmail-autosend.php')) ?>">
                                    <?= csrf_field() ?>
                                    <?= $settingsField ?>
                                    <input type="hidden" name="connection_id" value="<?= (int)$connection['id'] ?>">
                                    <input type="hidden" name="connection_revision" value="<?= e($connection['revision']) ?>">
                                    <input type="hidden" name="autosend_revision" value="<?= e($autosend['revision']) ?>">
                                    <label class="connections-permission">
                                      <input type="checkbox" name="enabled" value="1"<?= $autosend['enabled'] ? ' checked' : '' ?>>
                                      <span><strong>Enable Auto Send</strong><small>Clicking Auto Reply can draft and send to an approved address. The draft uses AI tokens. Confirmed sends appear in Chat and history.</small></span>
                                    </label>
                                    <div class="connections-inbox-fields">
                                      <label><span>Approved email addresses</span>
                                        <textarea name="addresses" rows="4" maxlength="6000" placeholder="person@example.com" aria-describedby="gmail-autosend-help"><?= e(implode("\n", $autosend['addresses'])) ?></textarea>
                                      </label>
                                    </div>
                                    <p class="connections-muted" id="gmail-autosend-help">Enter one exact email address per line, up to 20.</p>
                                    <?php if (!$autosend['available']): ?>
                                      <p class="connections-muted">Auto Send is paused until Gmail reading and sending are available.</p>
                                    <?php endif; ?>
                                    <button class="connections-button" type="submit">Save Auto Send</button>
                                  </form>
                                <?php endif; ?>
                              </section>
                            <?php endif; ?>

                            <?php if ($gmailLinked): ?>
                              <details class="connections-disconnect">
                                <summary>Disconnect Gmail</summary>
                                <form method="post" action="<?= e($endpoint) ?>">
                                  <?= csrf_field() ?>
                                  <?= $settingsField ?>
                                  <input type="hidden" name="provider" value="gmail">
                                  <input type="hidden" name="action" value="disconnect">
                                  <input type="hidden" name="connection_id" value="<?= (int)$connection['id'] ?>">
                                  <input type="hidden" name="revision" value="<?= e($connection['revision']) ?>">
                                  <p class="connections-muted">Disconnecting stops new Gmail actions and sender or reply checks for this connection.</p>
                                  <button class="connections-button connections-danger" type="submit">Confirm disconnect</button>
                                </form>
                              </details>
                            <?php endif; ?>
                          <?php endif; ?>
                        <?php else: ?>
                          <?php if ($researchOnly && !$youtubeEnabled): ?>
                            <p class="connections-muted">Enable YouTube to use its search and viewing activity for research. <a href="<?= e(url('/connections.php?app=youtube#connections-app-youtube')) ?>"<?= $appLinkTarget ?>>Open YouTube connection</a>.</p>
                          <?php elseif (!$youtubeConsentReady): ?>
                            <p class="connections-notice">YouTube will be available after an administrator runs Database Upgrade.</p>
                          <?php elseif ($youtubeReady && !$researchOnly): ?>
                            <p class="connections-muted">Your VP3 administrator provides this shared connection for public YouTube videos.</p>
                          <?php elseif (!$researchOnly): ?>
                            <p class="connections-muted">Your VP3 administrator can make this shared connection available under Admin → Connections.</p>
                          <?php endif; ?>
                          <?php if ($youtubeEnabled): ?>
                            <?php if (!$researchOnly): ?>
                            <?php if ($youtubeReady && $canChat): ?><p>Type or say <q>Search YouTube for beginner guitar lessons</q> in Agent Chat.</p><?php endif; ?>
                            <div class="connections-actions">
                              <?php if ($youtubeReady && $canChat): ?><a class="connections-button" href="<?= e(url('/chat.php')) ?>"<?= $appLinkTarget ?>>Open Agent Chat</a><?php endif; ?>
                              <form method="post" action="<?= e(url('/youtube-connection-v1.php')) ?>">
                                <?= csrf_field() ?>
                                <?= $settingsField ?>
                                <input type="hidden" name="action" value="disable">
                                <button class="connections-button connections-secondary" type="submit">Disable YouTube</button>
                              </form>
                            </div>
                            <?php if ($youtubePicksReady && $canChat): ?>
                              <form method="post" action="<?= e(url('/youtube-connection-v1.php')) ?>">
                                <?= csrf_field() ?>
                                <?= $settingsField ?>
                                <input type="hidden" name="action" value="save_picks">
                                <p><strong>YouTube music picks</strong></p>
                                <p>Ask Agent Chat for <q>Recommend mellow songs on YouTube</q>.</p>
                                <label>Music taste <input name="taste" maxlength="120" value="<?= e($youtubePicks['taste']) ?>" placeholder="Genres, moods, or artists"></label>
                                <label>YouTube offers from my activity
                                  <select name="proactive_mode">
                                    <?php foreach (['idle'=>'Saved activity (default)','on'=>'Saved activity and extra picks','off'=>'Off'] as $mode => $label): ?>
                                      <option value="<?= e($mode) ?>" <?= ($youtubePicks['proactive_mode'] ?? 'idle') === $mode ? 'selected' : '' ?>><?= e($label) ?></option>
                                    <?php endforeach; ?>
                                  </select>
                                </label>
                                <button class="connections-button connections-secondary" type="submit">Save music preferences</button>
                              </form>
                            <?php endif; ?>
                            <?php endif; ?>
                            <?php if ($canChat): ?>
                              <form method="post" action="<?= e(url('/youtube-connection-v1.php')) ?>">
                                <?= csrf_field() ?>
                                <?= $settingsField ?>
                                <input type="hidden" name="action" value="save_artist_research">
                                <label class="connections-permission">
                                  <input type="checkbox" name="artist_research" value="1" <?= $youtubeArtistResearch ? 'checked' : '' ?>>
                                  <span>Let Initiative research artists when my YouTube activity suggests an interest, using public web search and my configured AI.</span>
                                </label>
                                <button class="connections-button connections-secondary" type="submit">Save artist research preference</button>
                              </form>
                              <form method="post" action="<?= e(url('/youtube-connection-v1.php')) ?>">
                                <?= csrf_field() ?>
                                <?= $settingsField ?>
                                <input type="hidden" name="action" value="save_topic_research">
                                <label class="connections-permission">
                                  <input type="checkbox" name="topic_research" value="1" <?= $youtubeTopicResearch ? 'checked' : '' ?>>
                                  <span>Let Initiative research patterns in topics I search for on YouTube. It can send the search topic to public web search and prepare a private result with my configured AI.</span>
                                </label>
                                <button class="connections-button connections-secondary" type="submit">Save topic research preference</button>
                              </form>
                            <?php endif; ?>
                            <?php if ($youtubeHistoryReady && $canChat): ?>
                              <?php if (!$researchOnly): ?>
                              <p><strong>Played in VP3</strong></p>
                              <p class="connections-muted">Keep a private record of eligible videos that start playing in VP3 and where you left off. Entries expire with the cached result within seven days. Pausing keeps existing entries until they expire. Clearing affects VP3 only.</p>
                              <div class="connections-actions">
                                <form method="post" action="<?= e(url('/youtube-connection-v1.php')) ?>">
                                  <?= csrf_field() ?>
                                  <?= $settingsField ?>
                                  <input type="hidden" name="action" value="<?= $youtubeHistoryEnabled ? 'pause_history' : 'enable_history' ?>">
                                  <button class="connections-button connections-secondary" type="submit"><?= $youtubeHistoryEnabled ? 'Pause VP3 playback history' : 'Enable VP3 playback history' ?></button>
                                </form>
                                <form method="post" action="<?= e(url('/youtube-connection-v1.php')) ?>">
                                  <?= csrf_field() ?>
                                  <?= $settingsField ?>
                                  <input type="hidden" name="action" value="clear_history">
                                  <button class="connections-button connections-secondary" type="submit">Clear VP3 playback history</button>
                                </form>
                              </div>
                              <?php endif; ?>
                              <?php if ($youtubeWatchReady): ?>
                                <form method="post" action="<?= e(url('/youtube-connection-v1.php')) ?>">
                                  <?= csrf_field() ?>
                                  <?= $settingsField ?>
                                  <input type="hidden" name="action" value="save_watch_research">
                                  <label class="connections-permission">
                                    <input type="checkbox" name="watch_research" value="1" <?= $youtubeWatchResearch ? 'checked' : '' ?>>
                                    <span>Record watch time while Played in VP3 is enabled. Let Initiative use watch time and repeat views to judge what may be worth following up.</span>
                                  </label>
                                  <button class="connections-button connections-secondary" type="submit">Save watch-time preference</button>
                                </form>
                              <?php endif; ?>
                            <?php endif; ?>
                          <?php elseif (!$researchOnly && $youtubeReady && $youtubeConsentReady && $canChat): ?>
                            <form method="post" action="<?= e(url('/youtube-connection-v1.php')) ?>">
                              <?= csrf_field() ?>
                              <?= $settingsField ?>
                              <input type="hidden" name="action" value="enable">
                              <label class="connections-permission">
                                <input type="checkbox" name="accept_youtube_terms" value="1" required>
                                <span>I agree to the YouTube Terms of Service and the VP3 Terms of Service.</span>
                              </label>
                              <?php if ($youtubeHistoryReady): ?>
                                <label class="connections-permission">
                                  <input type="checkbox" name="record_vp3_playback" value="1" checked>
                                  <span>Played in VP3. Keep a private record of eligible videos that start in VP3 for up to seven days. You can pause or clear it here.</span>
                                </label>
                              <?php endif; ?>
                              <button class="connections-button" type="submit">Enable YouTube</button>
                            </form>
                          <?php elseif (!$researchOnly && $youtubeReady && $youtubeConsentReady): ?>
                            <p class="connections-muted">Your account needs Agent Chat access to enable the YouTube skill.</p>
                          <?php endif; ?>
                          <?php if (!$researchOnly): ?><p class="connections-service-links">
                            <a href="https://www.youtube.com/t/terms" target="_blank" rel="noopener noreferrer">YouTube Terms of Service</a>
                            <a href="https://policies.google.com/privacy" target="_blank" rel="noopener noreferrer">Google Privacy Policy</a>
                            <a href="<?= e(url('/terms.php#youtube')) ?>"<?= $appLinkTarget ?>>VP3 Terms of Service</a>
                            <a href="<?= e(url('/privacy.php#youtube')) ?>"<?= $appLinkTarget ?>>VP3 Privacy Policy</a>
                          </p><?php endif; ?>
                        <?php endif; ?>
                      </div>
                    </details>
                  </li>
                <?php endforeach; ?>
              </ul>
            <?php endif; ?>
          </section>
        <?php endforeach; ?>
        <?php if ($showCasualCheckins): ?>
          <section class="connections-section" id="casual-checkins" aria-labelledby="casual-checkins-title">
            <h2 id="casual-checkins-title">Casual check-ins</h2>
            <?php if (($_GET['casual_checkins'] ?? '') === 'saved'): ?>
              <p class="connections-notice connections-success" role="status">Check-in preference saved.</p>
            <?php elseif (($_GET['casual_checkins'] ?? '') === 'failed'): ?>
              <p class="connections-notice connections-error" role="alert">Check-in preference could not be saved.</p>
            <?php endif; ?>
            <form method="post" action="<?= e(url('/connections.php')) ?>">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="save_casual_checkins">
              <input type="hidden" name="casual_checkin_owner" value="<?= e((string)$user['id']) ?>">
              <label class="connections-permission">
                <span>Casual check-ins in Agent Chat</span>
                <select name="casual_checkins">
                  <option value="default" <?= $casualCheckins === null ? 'selected' : '' ?>>Music and video</option>
                  <option value="1" <?= $casualCheckins === true ? 'selected' : '' ?>>All check-ins</option>
                  <option value="0" <?= $casualCheckins === false ? 'selected' : '' ?>>Off</option>
                </select>
              </label>
              <p class="connections-muted">When Initiative is on and Chat is quiet, your Agent can offer music or video. All check-ins also allows offers to draft an email, review your day, or continue open work. Your app permissions apply to each offer.</p>
              <button class="connections-button" type="submit">Save check-in preference</button>
            </form>
          </section>
        <?php endif; ?>
        <?php if ($showNativeMusic && $pdo && $canChat): ?>
          <section class="connections-section" id="music-research" aria-labelledby="music-research-title">
            <h2 id="music-research-title">VP3 Music</h2>
            <?php if ($mediaOffersAllowed): ?>
              <form id="media-offers" method="post" action="<?= e($mediaOffersAction) ?>">
                <?= csrf_field() ?>
                <?= $settingsField ?>
                <input type="hidden" name="action" value="save_native_media_offers">
                <label class="connections-permission">
                  <input type="checkbox" name="native_media_offers" value="1" <?= $nativeMediaOffers ? 'checked' : '' ?>>
                  <span>Suggest unfinished VP3 tracks and Music Library picks in Agent Chat</span>
                </label>
                <p class="connections-muted">With Initiative enabled, Media &amp; Entertainment can offer to resume a track or suggest a Music Library pick when Chat is quiet.</p>
                <label>VP3 music taste <input name="native_media_taste" maxlength="120" value="<?= e($nativeMediaTaste) ?>" placeholder="Genres, moods, or artists"></label>
                <?php if (($_GET['native_media_offers'] ?? '') === 'saved'): ?>
                  <p class="connections-notice connections-success" role="status">Playback suggestion preference saved.</p>
                <?php elseif (($_GET['native_media_offers'] ?? '') === 'failed'): ?>
                  <p class="connections-notice connections-error" role="alert">Playback suggestion preference could not be saved.</p>
                <?php endif; ?>
                <button class="connections-button" type="submit">Save playback suggestions</button>
              </form>
            <?php endif; ?>
            <?php if (($_GET['music_research'] ?? '') === 'saved'): ?>
              <p class="connections-notice connections-success" role="status">Artist research preference saved.</p>
            <?php elseif (($_GET['music_research'] ?? '') === 'failed'): ?>
              <p class="connections-notice connections-error" role="alert">Artist research preference could not be saved.</p>
            <?php endif; ?>
            <form method="post" action="<?= e($nativeSettingsAction) ?>">
              <?= csrf_field() ?>
              <?= $settingsField ?>
              <input type="hidden" name="action" value="save_native_music_research">
              <label class="connections-permission">
                <input type="checkbox" name="native_music_research" value="1" <?= $nativeMusicResearch ? 'checked' : '' ?>>
                <span>Use recent VP3 Music listening for artist research</span>
              </label>
              <p class="connections-muted">With Initiative enabled, separate Music Player listening sessions in the past seven days can prompt research about artists with a public profile. Sessions need a 15-minute break between them. Plays started from Chat suggestions stay outside this setting. Public searches use the artist name. Research can use tokens and reports can appear in your private Agent Chat.</p>
              <button class="connections-button" type="submit">Save preference</button>
            </form>
          </section>
        <?php endif; ?>
        <?php if ($showTranscriptResearch && $pdo && $canManageTranscriptResearch): ?>
          <section class="connections-section" id="transcript-research" aria-labelledby="transcript-research-title">
            <h2 id="transcript-research-title">Saved Transcripts</h2>
            <?php if (($_GET['transcript_research'] ?? '') === 'saved'): ?>
              <p class="connections-notice connections-success" role="status">Transcript research preference saved.</p>
            <?php elseif (($_GET['transcript_research'] ?? '') === 'failed'): ?>
              <p class="connections-notice connections-error" role="alert">Transcript research preference could not be saved.</p>
            <?php endif; ?>
            <form method="post" action="<?= e($nativeSettingsAction) ?>">
              <?= csrf_field() ?>
              <?= $settingsField ?>
              <input type="hidden" name="action" value="save_transcript_research">
              <input type="hidden" name="transcript_research_owner" value="<?= e((string)$user['id']) ?>">
              <label class="connections-permission">
                <input type="checkbox" name="transcript_research" value="1" <?= $transcriptResearch ? 'checked' : '' ?>>
                <span>Use research questions from saved transcript findings</span>
              </label>
              <p class="connections-muted">With Initiative enabled, your configured Agent model can assess saved questions and suggested checks you allow it to read. VP3 sends generated public search terms to DuckDuckGo and shares findings in your private Agent Chat. Research can use tokens.</p>
              <button class="connections-button" type="submit">Save preference</button>
            </form>
          </section>
        <?php endif; ?>
<?php if ($embedded): ?>
  </main>
<?php else: ?>
      </div>
    </div>
  </main>
</div>
<?php endif; ?>
<?php if ($embeddedApp === 'gmail' || $gmailConnectionChanged): ?>
<script src="<?= e(url('/connections-settings-v1.js?v='.substr(hash_file('sha256', __DIR__.'/connections-settings-v1.js'),0,12))) ?>" defer></script>
<?php endif; ?>
<?php if ($inboxInitialReview !== null): ?>
<script>window.VP3_GMAIL_INBOX_INITIAL_V1=<?= json_encode([
    'endpoint'=>url('/api/gmail-inbox-initial-v1.php'),'csrf'=>csrf_token(),
    'ownerKey'=>(string)$user['id'],'connectionsUrl'=>url('/connections.php?app=gmail#gmail-inbox-review'),'chatUrl'=>url('/chat.php'),
    'initialReview'=>$inboxInitialReview,
], JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_UNESCAPED_SLASHES|JSON_INVALID_UTF8_SUBSTITUTE) ?>;</script>
<script src="<?= e(url('/gmail-inbox-initial-v1.js?v='.substr(hash_file('sha256', __DIR__.'/gmail-inbox-initial-v1.js'),0,12))) ?>" defer></script>
<?php endif; ?>
</body>
</html>
