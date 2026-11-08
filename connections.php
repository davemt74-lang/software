<?php
declare(strict_types=1);
require __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/agent-connections-v1.php';
require_permission('account.access');

$user = current_user();
$summary = agent_connections_summary_v1((int)$user['id'], 'gmail');
$connection = $summary['connection'] ?? null;
$status = $connection['status'] ?? 'disconnected';
$connected = $status === 'connected';
$reauthorize = $status === 'reauthorize';
$providerReady = !empty($summary['provider_ready']);
$permissions = $connected || $reauthorize
    ? $connection['permissions']
    : ['read' => true, 'send' => true, 'followups' => false];
$action = $connected ? 'update_policy' : 'start';
$labels = [
    'read' => ['Search and read Gmail', 'The Agent can look up relevant messages for work you ask it to do.'],
    'send' => ['Send after my approval', 'Review each message in Agent Chat before the Agent sends it from this account.'],
    'followups' => ['Watch for replies in the background', 'Requires Gmail read access. A reply can bring the Agent back to the original initiative while your browser is closed.'],
];
$notices = [
    'connected' => ['success', 'Gmail connected.'],
    'updated' => ['success', 'Agent permissions saved.'],
    'disconnected' => ['success', 'Gmail disconnected from VP3.'],
    'unavailable' => ['error', 'Gmail is unavailable on this installation.'],
    'invalid' => ['error', 'The connection request expired or was invalid. Review your settings and try again.'],
    'failed' => ['error', 'The Gmail request could not be completed. Try again in a moment.'],
    'denied' => ['error', 'Gmail authorization was declined or the required permissions were unavailable.'],
    'conflict' => ['error', 'The connection changed while you were working. Review its current state and try again.'],
];
$result = $_GET['result'] ?? '';
$notice = is_string($result) ? ($notices[$result] ?? null) : null;
$endpoint = url('/connection-oauth.php');

header('Cache-Control: private, no-store');
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Connections | <?= e(system_agent_name()) ?></title>
  <link rel="stylesheet" href="<?= e(url('/chat.css?v=82')) ?>">
  <link rel="stylesheet" href="<?= e(url('/connections-v1.css?v=1')) ?>">
</head>
<body>
<div class="chat-app">
  <?php $mainSidebarUser = $user; $mainSidebarActive = 'connections'; require __DIR__ . '/includes/main-sidebar.php'; ?>
  <div class="chat-sidebar-backdrop" id="chatSidebarBackdrop"></div>
  <main class="chat-main connections-main">
    <?php
    $memberHeaderUser = $user;
    $memberHeaderTitle = 'Connections';
    $memberHeaderSubtitle = 'Your external services';
    require __DIR__ . '/includes/member-header.php';
    ?>
    <div class="connections-scroll">
      <div class="connections-content">
        <h1>Connections</h1>
        <p class="connections-muted">Connect a service to your VP3 account and choose what your Agent can do with it.</p>

        <?php if ($notice): ?>
          <p class="connections-notice connections-<?= e($notice[0]) ?>" role="<?= $notice[0] === 'error' ? 'alert' : 'status' ?>"><?= e($notice[1]) ?></p>
        <?php endif; ?>

        <section class="connections-card" aria-labelledby="connections-gmail-title">
          <div class="connections-card-head">
            <div>
              <h2 id="connections-gmail-title">Gmail</h2>
              <p class="connections-muted">Use your mailbox in Agent Chat and keep email follow-ups with your work.</p>
            </div>
            <span class="connections-badge<?= $connected ? ' is-connected' : '' ?>"><?= $connected ? 'Connected' : ($reauthorize ? 'Reconnect required' : 'Not connected') ?></span>
          </div>

          <?php if (empty($summary['schema_ready'])): ?>
            <p class="connections-notice">Connections will be available after an administrator runs Database Upgrade.</p>
          <?php else: ?>
            <?php if (($connected || $reauthorize) && !empty($connection['account_email'])): ?>
              <p class="connections-account"><strong>Google account</strong> <?= e($connection['account_email']) ?></p>
            <?php endif; ?>
            <?php if (!$providerReady): ?>
              <p class="connections-notice">Gmail is currently unavailable on this installation. Contact an administrator for help.</p>
            <?php elseif ($reauthorize): ?>
              <p class="connections-notice">Reconnect Gmail to resume work with this mailbox.</p>
            <?php endif; ?>

            <?php if ($providerReady || $connected): ?>
              <form method="post" action="<?= e($endpoint) ?>">
                <?= csrf_field() ?>
                <input type="hidden" name="provider" value="gmail">
                <input type="hidden" name="action" value="<?= e($action) ?>">
                <?php if ($connected): ?>
                  <input type="hidden" name="connection_id" value="<?= (int)$connection['id'] ?>">
                  <input type="hidden" name="revision" value="<?= e($connection['revision']) ?>">
                <?php endif; ?>
                <fieldset class="connections-permissions" aria-describedby="connections-policy-note">
                  <legend>Agent permissions</legend>
                  <p class="connections-muted" id="connections-policy-note">Google asks you to authorize read and send access. These settings control when your Agent may use that access in VP3.</p>
                  <?php foreach ($labels as $key => [$label, $description]): ?>
                    <label class="connections-permission">
                      <input type="checkbox" name="allow_<?= e($key) ?>" value="1"<?= !empty($permissions[$key]) ? ' checked' : '' ?>>
                      <span><strong><?= e($label) ?></strong><small><?= e($description) ?></small></span>
                    </label>
                  <?php endforeach; ?>
                </fieldset>
                <button class="connections-button" type="submit"><?= $connected ? 'Save permissions' : ($reauthorize ? 'Reconnect Gmail' : 'Connect Gmail') ?></button>
              </form>
            <?php endif; ?>

            <?php if ($connected || $reauthorize): ?>
              <details class="connections-disconnect">
                <summary>Disconnect Gmail</summary>
                <form method="post" action="<?= e($endpoint) ?>">
                  <?= csrf_field() ?>
                  <input type="hidden" name="provider" value="gmail">
                  <input type="hidden" name="action" value="disconnect">
                  <input type="hidden" name="connection_id" value="<?= (int)$connection['id'] ?>">
                  <input type="hidden" name="revision" value="<?= e($connection['revision']) ?>">
                  <p class="connections-muted">Disconnecting stops new Gmail actions and reply checks for this connection.</p>
                  <button class="connections-button connections-danger" type="submit">Confirm disconnect</button>
                </form>
              </details>
            <?php endif; ?>
          <?php endif; ?>
        </section>
      </div>
    </div>
  </main>
</div>
</body>
</html>
