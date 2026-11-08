<?php
declare(strict_types=1);
require __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/agent-connections-v1.php';
require_once __DIR__ . '/includes/agent-connection-youtube-v1.php';
require_once __DIR__ . '/includes/agent-connection-health-v1.php';
require_once __DIR__ . '/includes/agent-connection-youtube-consent-v1.php';
require_permission('account.access');

$user = current_user();
$summary = agent_connections_summary_v1((int)$user['id'], 'gmail');
$connection = $summary['connection'] ?? null;
$status = $connection['status'] ?? 'disconnected';
$connected = $status === 'connected';
$reauthorize = $status === 'reauthorize';
$gmailLinked = $connected || $reauthorize;
$providerReady = !empty($summary['provider_ready']);
$youtubeReady = agent_connection_youtube_ready_v1();
$pdo = db();
$youtubeConsentReady = $pdo && agent_connection_youtube_consent_ready_v1($pdo);
$youtubeEnabled = $youtubeConsentReady && agent_connection_youtube_consent_v1($pdo, (int)$user['id']);
$canChat = has_permission('chat.access', $user);
$permissions = $gmailLinked
    ? $connection['permissions']
    : ['read' => true, 'send' => true, 'followups' => false];
$action = $connected ? 'update_policy' : 'start';
$labels = [
    'read' => ['Search and read Gmail', 'In typed Agent Chat, try “Find emails from sender@example.com” to search your inbox.'],
    'send' => ['Send after my approval', 'Review each message in Agent Chat before the Agent sends it from this account.'],
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
    'youtube_enabled' => ['success', 'YouTube is enabled for your VP3 account.'],
    'youtube_disabled' => ['success', 'YouTube is disabled for your VP3 account.'],
    'youtube_accept_required' => ['error', 'Review and accept the terms to enable YouTube.'],
    'youtube_unavailable' => ['error', 'YouTube is currently unavailable on this installation.'],
    'youtube_invalid' => ['error', 'The YouTube request expired or was invalid. Review the terms and try again.'],
];
$result = $_GET['result'] ?? '';
$notice = is_string($result) ? ($notices[$result] ?? null) : null;
$noticeProvider = is_string($result) && str_starts_with($result, 'youtube_') ? 'youtube' : 'gmail';
$selectedApp = $_GET['app'] ?? '';
$openApp = $notice && !in_array($result, ['disconnected', 'youtube_disabled'], true)
    ? $noticeProvider
    : (in_array($selectedApp, ['gmail', 'youtube'], true) ? $selectedApp : '');
$endpoint = url('/connection-oauth.php');
$health = [
    'gmail' => $pdo && $gmailLinked ? agent_connection_health_member_v1($pdo, (int)$user['id'], (int)$connection['id']) : null,
    'youtube' => $pdo ? agent_connection_health_platform_v1($pdo, 'youtube') : null,
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

header('Cache-Control: private, no-store');
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Connections | <?= e(system_agent_name()) ?></title>
  <link rel="stylesheet" href="<?= e(url('/chat.css?v=82')) ?>">
  <link rel="stylesheet" href="<?= e(url('/connections-v1.css?v=2')) ?>">
</head>
<body>
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

        <?php if ($notice): ?>
          <p class="connections-notice connections-<?= e($notice[0]) ?>" role="<?= $notice[0] === 'error' ? 'alert' : 'status' ?>"><?= e($notice[1]) ?></p>
        <?php endif; ?>

        <?php foreach ($sections as $section => [$title, $sectionApps]): ?>
          <?php if (!$sectionApps && $section === 'available') continue; ?>
          <section class="connections-section" aria-labelledby="connections-<?= e($section) ?>-title">
            <h2 id="connections-<?= e($section) ?>-title"><?= e($title) ?></h2>
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
                        <?php if ($appHealth = $health[$provider]): ?>
                          <div class="connections-health">
                            <strong>Connection check · <?= e($healthLabels[$appHealth['status']] ?? 'Unknown') ?></strong>
                            <?php if (!empty($appHealth['checked_at'])): ?>
                              <time datetime="<?= e(str_replace(' ', 'T', $appHealth['checked_at']) . 'Z') ?>"><?= e($appHealth['checked_at']) ?> UTC</time>
                            <?php endif; ?>
                            <?php if (!empty($appHealth['message'])): ?><p><?= e($appHealth['message']) ?></p><?php endif; ?>
                            <?php if ($provider === 'gmail' && $connected && $providerReady): ?>
                              <form class="connections-test" method="post" action="<?= e(url('/connection-test.php')) ?>">
                                <?= csrf_field() ?>
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
                              <form method="post" action="<?= e($endpoint) ?>">
                                <?= csrf_field() ?>
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
                                  <button class="connections-button" type="submit" name="action" value="<?= e($action) ?>"><?= $connected ? 'Save permissions' : ($reauthorize ? 'Reconnect Gmail' : 'Connect Gmail') ?></button>
                                  <?php if ($connected && $providerReady && $gmailNeedsAttention): ?>
                                    <button class="connections-button connections-secondary" type="submit" name="action" value="start">Reconnect Gmail</button>
                                  <?php endif; ?>
                                  <?php if ($gmailPopupAvailable): ?>
                                    <button class="connections-button connections-secondary" type="submit" name="action" value="prepare" formaction="<?= e(url('/connection-gmail-popup.php')) ?>" aria-describedby="connections-gmail-popup-note">Use Google popup</button>
                                  <?php endif; ?>
                                </div>
                                <?php if ($gmailPopupAvailable): ?>
                                  <p class="connections-muted" id="connections-gmail-popup-note">Use the popup if your hosting provider blocks Google’s return page.</p>
                                <?php endif; ?>
                              </form>
                            <?php endif; ?>

                            <?php if ($gmailLinked): ?>
                              <details class="connections-disconnect">
                                <summary>Disconnect Gmail</summary>
                                <form method="post" action="<?= e($endpoint) ?>">
                                  <?= csrf_field() ?>
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
                          <?php if (!$youtubeConsentReady): ?>
                            <p class="connections-notice">YouTube will be available after an administrator runs Database Upgrade.</p>
                          <?php elseif ($youtubeReady): ?>
                            <p class="connections-muted">Your VP3 administrator provides this shared connection for public YouTube videos.</p>
                          <?php else: ?>
                            <p class="connections-muted">Your VP3 administrator can make this shared connection available under Admin → Connections.</p>
                          <?php endif; ?>
                          <?php if ($youtubeEnabled): ?>
                            <?php if ($youtubeReady && $canChat): ?><p>Type or say <q>Search YouTube for beginner guitar lessons</q> in Agent Chat.</p><?php endif; ?>
                            <div class="connections-actions">
                              <?php if ($youtubeReady && $canChat): ?><a class="connections-button" href="<?= e(url('/chat.php')) ?>">Open Agent Chat</a><?php endif; ?>
                              <form method="post" action="<?= e(url('/youtube-connection-v1.php')) ?>">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="disable">
                                <button class="connections-button connections-secondary" type="submit">Disable YouTube</button>
                              </form>
                            </div>
                          <?php elseif ($youtubeReady && $youtubeConsentReady && $canChat): ?>
                            <form method="post" action="<?= e(url('/youtube-connection-v1.php')) ?>">
                              <?= csrf_field() ?>
                              <input type="hidden" name="action" value="enable">
                              <label class="connections-permission">
                                <input type="checkbox" name="accept_youtube_terms" value="1" required>
                                <span>I agree to the YouTube Terms of Service and the VP3 Terms of Service.</span>
                              </label>
                              <button class="connections-button" type="submit">Enable YouTube</button>
                            </form>
                          <?php elseif ($youtubeReady && $youtubeConsentReady): ?>
                            <p class="connections-muted">Your account needs Agent Chat access to enable the YouTube skill.</p>
                          <?php endif; ?>
                          <p class="connections-service-links">
                            <a href="https://www.youtube.com/t/terms" target="_blank" rel="noopener noreferrer">YouTube Terms of Service</a>
                            <a href="https://policies.google.com/privacy" target="_blank" rel="noopener noreferrer">Google Privacy Policy</a>
                            <a href="<?= e(url('/terms.php#youtube')) ?>">VP3 Terms of Service</a>
                            <a href="<?= e(url('/privacy.php#youtube')) ?>">VP3 Privacy Policy</a>
                          </p>
                        <?php endif; ?>
                      </div>
                    </details>
                  </li>
                <?php endforeach; ?>
              </ul>
            <?php endif; ?>
          </section>
        <?php endforeach; ?>
      </div>
    </div>
  </main>
</div>
</body>
</html>
