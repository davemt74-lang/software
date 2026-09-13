<?php
declare(strict_types=1);
require __DIR__ . '/includes/bootstrap.php';
require_permission('account.access');
$user = current_user();
if (!$user) redirect(url('/login.php'));
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="theme-color" content="#f4f5f7">
<title><?= e(system_agent_name()) ?> | HomeServer</title>
<link rel="stylesheet" href="<?= e(url('/chat.css?v=82')) ?>">
<link rel="stylesheet" href="<?= e(url('/account.css?v=account-light-20260904')) ?>">
<link rel="stylesheet" href="<?= e(url('/homeserver-settings-v1200.css?v=20260912')) ?>">
</head>
<body>
<div class="chat-app">
  <?php $workspaceSidebarUser=$user;$workspaceSidebarActive='homeserver';require __DIR__.'/includes/workspace-sidebar-v82.php'; ?>
  <div class="chat-sidebar-backdrop" id="chatSidebarBackdrop"></div>
  <main class="chat-main account-chat-main">
    <header class="chat-topbar">
      <button class="chat-icon-button mobile-only" id="openChatSidebar" type="button" aria-label="Open settings menu">☰</button>
      <div class="chat-topbar-title"><strong>Settings</strong><span>HomeServer</span></div>
      <div class="chat-topbar-actions"><a class="account-shell-button" href="<?= e(url('/account.php')) ?>">My Account</a></div>
    </header>

    <section class="hs-settings" data-homeserver-settings data-api="<?= e(url('/api/homeserver-connection-v1200.php')) ?>" data-csrf="<?= e(csrf_token()) ?>">
      <div class="hs-settings-inner">
        <nav class="hs-breadcrumb" aria-label="Settings breadcrumb"><a href="<?= e(url('/account.php')) ?>">Settings</a><span>›</span><strong>HomeServer</strong></nav>

        <header class="hs-hero">
          <div><span class="hs-eyebrow">Private AI connection</span><h1>HomeServer</h1><p>Keep private AI knowledge, tools, models, credentials and personal context on your own computer while VP3 connects through the secure relay.</p></div>
          <div class="hs-state-pill" id="hsStatePill" data-state="loading"><span></span><strong id="hsStateLabel">Checking…</strong></div>
        </header>

        <div class="hs-alert" id="hsAlert" hidden role="status" aria-live="polite"></div>

        <section class="hs-card hs-connect-card" id="hsConnectCard">
          <div class="hs-card-head"><div><small>Connection</small><h2 id="hsConnectionTitle">Checking HomeServer</h2><p id="hsConnectionDetail">Loading connection state.</p></div></div>

          <div class="hs-stepper" id="hsStepper" hidden aria-label="Pairing steps">
            <div class="hs-step" data-step="1"><span>1</span><div><strong>Generate in VP3 Cloud</strong><small>Create an account-bound one-time pairing token below.</small></div></div>
            <div class="hs-step" data-step="2"><span>2</span><div><strong>Enter token in HomeServer</strong><small>Open HomeServer → Remote Bridge and paste the VP3 token.</small></div></div>
            <div class="hs-step" data-step="3"><span>3</span><div><strong>Approve locally</strong><small>Stay in HomeServer → Remote Bridge, review the requested VP3 permissions, and click Approve VP3.</small></div></div>
          </div>

          <div class="hs-claim-form" id="hsTokenPanel">
            <label>VP3 account pairing token</label>
            <div><button class="hs-button primary" id="hsGenerateToken" type="button">Generate Pairing Token</button></div>
            <small>VP3 creates this token for your signed-in account. It expires after 15 minutes and can be redeemed only once by a HomeServer that also proves its relay device identity.</small>
          </div>

          <div class="hs-approval" id="hsTokenResult" hidden>
            <span>One-time VP3 pairing token</span>
            <strong id="hsPairingToken">—</strong>
            <p>Copy this token now. Open HomeServer → Remote Bridge, paste it into the VP3 pairing field, and submit it. VP3 does not show this raw token again after you leave or refresh this page.</p>
            <div class="hs-actions"><button class="hs-button" id="hsCopyToken" type="button">Copy token</button><button class="hs-button quiet" id="hsRegenerateToken" type="button">Generate new token</button></div>
          </div>

          <div class="hs-approval" id="hsApproval" hidden>
            <span>Local approval required</span>
            <strong>Review VP3 in HomeServer</strong>
            <p>The account token was accepted and this HomeServer proved its relay identity. In HomeServer → Remote Bridge, review the requested capabilities and click Approve VP3. No second code is required.</p>
            <div class="hs-actions"><button class="hs-button" id="hsCheckApproval" type="button">Check now</button><button class="hs-button quiet" id="hsCancelPairing" type="button">Cancel request</button></div>
          </div>

          <div class="hs-actions" id="hsConnectedActions" hidden>
            <button class="hs-button" id="hsReconnect" type="button">Reconnect</button>
            <button class="hs-button" id="hsRepair" type="button">Re-pair permissions</button>
            <button class="hs-button danger" id="hsDisconnect" type="button">Disconnect</button>
          </div>
          <div class="hs-actions" id="hsDisconnectedActions" hidden>
            <button class="hs-button danger-outline" id="hsRemove" type="button">Remove Cloud pairing</button>
          </div>
        </section>

        <section class="hs-card" id="hsConnectionInfo" hidden>
          <div class="hs-card-head"><div><small>Connection details</small><h2>Your HomeServer</h2><p>Only connection and capability information needed by VP3 is shown here.</p></div><button class="hs-button quiet" id="hsRefresh" type="button">Refresh</button></div>
          <dl class="hs-info-grid">
            <div><dt>HomeServer</dt><dd id="hsDeviceName">—</dd></div>
            <div><dt>Device</dt><dd id="hsDeviceId">—</dd></div>
            <div><dt>Relay</dt><dd id="hsRelayStatus">—</dd></div>
            <div><dt>Last successful contact</dt><dd id="hsLastSeen">—</dd></div>
            <div><dt>Version</dt><dd id="hsVersion">—</dd></div>
            <div><dt>Reconnect</dt><dd id="hsReconnectStatus">—</dd></div>
          </dl>
        </section>

        <section class="hs-card" id="hsCapabilitiesCard" hidden>
          <div class="hs-card-head"><div><small>Authorized capabilities</small><h2>Available to VP3</h2><p>HomeServer remains the source of truth. VP3 receives only the scopes and capability projection authorized for this pairing.</p></div></div>
          <div class="hs-chips" id="hsCapabilities"><span>None reported</span></div>
        </section>

        <details class="hs-card hs-advanced" id="hsAdvanced">
          <summary><span><small>Advanced</small><strong>Connection details</strong></span><span>＋</span></summary>
          <div class="hs-advanced-body"><dl class="hs-info-grid"><div><dt>Pairing protocol</dt><dd>account-token-v1</dd></div><div><dt>Relay host</dt><dd id="hsRelayHost">—</dd></div><div><dt>Cloud build</dt><dd id="hsBuild">—</dd></div><div><dt>Paired scopes</dt><dd id="hsScopeCount">—</dd></div></dl></div>
        </details>
      </div>
    </section>
  </main>
</div>
<script src="<?= e(url('/member-shell-v77.js')) ?>"></script>
<script src="<?= e(url('/homeserver-settings-v1210.js?v=20260913')) ?>" defer></script>
<script src="<?= e(url('/homeserver-settings-lifecycle-v1200.js?v=20260912')) ?>" defer></script>
</body>
</html>
