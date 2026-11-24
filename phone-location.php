<?php
declare(strict_types=1);

header('Cache-Control: private, no-store, max-age=0');
header('Referrer-Policy: no-referrer');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Permissions-Policy: geolocation=(self), camera=(self), microphone=()');
header("Content-Security-Policy: default-src 'none'; script-src 'self'; style-src 'self'; connect-src 'self'; manifest-src 'self'; worker-src 'self'; img-src 'self'; media-src 'self' blob: mediastream:; base-uri 'none'; frame-ancestors 'none'; form-action 'none'");
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover,interactive-widget=resizes-content">
  <meta name="referrer" content="no-referrer">
  <title>VP3 Phone</title>
  <meta name="theme-color" content="#111111">
  <meta name="apple-mobile-web-app-capable" content="yes">
  <meta name="apple-mobile-web-app-title" content="VP3 Phone">
  <link rel="manifest" href="phone-app.webmanifest">
  <link rel="apple-touch-icon" sizes="180x180" href="assets/phone-pwa/icon-180.png">
  <script src="phone-pwa-v1.js?v=1" defer></script>
  <link rel="stylesheet" href="phone-location-v1.css?v=12">
  <link rel="stylesheet" href="phone-remote-dialer-v1.css?v=1">
  <link rel="stylesheet" href="phone-managed-call-ui-v1.css?v=3">
  <link rel="stylesheet" href="phone-camera-app-v1.css?v=2">
  <script src="phone-camera-ice-v1.js?v=1" defer></script>
  <link rel="stylesheet" href="phone-recording-holder-v1.css?v=1">
  <script src="phone-recording-holder-v1.js?v=1" defer></script>
  <script src="phone-camera-holder-v1.js?v=6" defer></script>
  <script src="phone-agent-chat-v1.js?v=4" defer></script>
  <script src="phone-save-place-v1.js?v=1" defer></script>
  <script src="phone-information-ui-v1.js?v=2" defer></script>
  <script src="phone-remote-dialer-v1.js?v=1" defer></script>
  <script src="phone-managed-call-ui-v1.js?v=5" defer></script>
  <script src="phone-location-v1.js?v=14" defer></script>
</head>
<body>
<main id="phone-location" data-endpoint="api/phone-location-v1.php">
  <header class="phone-header">
    <div class="phone-header-inner">
      <div class="phone-brand-line"><b class="brand">VP3</b><span>Phone connection</span></div>
      <div class="phone-tabs" id="phone-tabs" role="tablist" aria-label="Connected phone" hidden>
        <button type="button" role="tab" id="phone-tab-connection" aria-controls="phone-connection" aria-selected="true">Connection</button>
        <button type="button" role="tab" id="phone-tab-chat" aria-controls="phone-chat" aria-selected="false" tabindex="-1">Chat</button>
      </div>
      <?php require __DIR__.'/includes/phone-camera-app-view.php'; ?>
      <div id="phone-dialer-alert-host"></div>
      <div id="phone-managed-alert-host"></div>
    </div>
  </header>
  <section id="phone-connection" aria-labelledby="phone-label">
    <div class="phone-card">
      <p class="state" id="phone-badge">Phone Tracking</p>
      <h1 id="phone-label">Connect this phone</h1>
      <p id="phone-status" role="status" aria-live="polite">Checking your connection…</p>
      <dl id="phone-details" hidden>
        <div><dt>Shares with</dt><dd id="phone-owner"></dd></div>
        <div><dt>Location</dt><dd id="phone-gps"></dd></div>
        <div id="phone-preference-row" hidden><dt>Updates</dt><dd id="phone-preferences"></dd></div>
      </dl>
      <form id="phone-pair-entry" hidden autocomplete="off">
        <label for="phone-pair-code">Pairing code</label>
        <input id="phone-pair-code" type="text" inputmode="text" autocomplete="off" autocapitalize="none" spellcheck="false" maxlength="43" pattern="[A-Za-z0-9_\-]{43}" placeholder="Paste the fresh code" required>
        <p class="phone-install-note">Use a new code from the account owner's Connect Phone dialog. The owner must approve this new connection.</p>
        <button id="phone-pair-continue" type="submit">Continue pairing</button>
      </form>
      <form id="phone-join" hidden>
        <label for="phone-name">Phone name</label>
        <input id="phone-name" name="name" maxlength="48" autocomplete="off" placeholder="My iPhone" required>
        <button id="phone-claim" type="submit">Request connection</button>
      </form>
      <div id="phone-controls" hidden>
        <p id="phone-consent"></p>
        <button type="button" id="phone-start" hidden>Start sharing</button>
        <button type="button" id="phone-stop" hidden>Stop sharing</button>
        <details id="phone-information" class="phone-permission" hidden>
          <summary id="phone-information-summary">Information exchange</summary>
          <div id="phone-information-host"></div>
        </details>
        <div id="phone-dialer-settings-host"></div>
        <section id="phone-communication" class="phone-permission" aria-labelledby="phone-communication-title" hidden>
          <h2 id="phone-communication-title">Communication preferences</h2>
          <p id="phone-communication-feedback" role="status"></p>
          <form id="phone-communication-form"></form>
        </section>
        <section id="phone-history" class="phone-permission" aria-labelledby="phone-history-title" hidden>
          <h2 id="phone-history-title">Location history</h2>
          <p id="phone-history-status" role="status"></p>
          <div class="phone-permission-buttons">
            <button type="button" id="phone-history-accept" hidden>Save history</button>
            <button type="button" id="phone-history-decline" class="secondary" hidden>Decline</button>
            <button type="button" id="phone-history-stop" class="secondary" hidden>Stop saving history</button>
          </div>
        </section>
        <section id="phone-places" class="phone-permission" aria-labelledby="phone-places-title" hidden>
          <h2 id="phone-places-title">Saved places</h2>
          <p id="phone-places-status" role="status"></p>
          <button type="button" id="phone-places-allow" class="secondary" hidden>Allow saved places</button>
          <button type="button" id="phone-places-stop" class="secondary" hidden>Stop allowing saved places</button>
        </section>
        <section id="phone-arrivals" class="phone-permission" aria-labelledby="phone-arrivals-title" hidden>
          <h2 id="phone-arrivals-title">Arrival alerts</h2>
          <ul id="phone-arrival-rules"></ul>
        </section>
        <button type="button" class="secondary" id="phone-retry" hidden>Check connection</button>
        <button type="button" class="text-button" id="phone-disconnect" hidden>Disconnect phone</button>
      </div>
      <section id="phone-record-retained" aria-labelledby="phone-record-retained-title" hidden>
        <h2 id="phone-record-retained-title">Recording on this phone</h2>
        <p>Camera sharing ended. Save a copy before closing this page.</p>
        <div id="phone-record-retained-list"></div>
      </section>
      <section id="phone-install-panel" class="phone-permission" aria-labelledby="phone-install-title">
        <h2 id="phone-install-title">Install VP3 Phone</h2>
        <p id="phone-install-status" role="status">Checking installation…</p>
        <p id="phone-install-help">Add this page to your Home Screen. Notification delivery is not enabled yet.</p>
        <button id="phone-install-button" type="button" class="secondary" hidden>Install VP3 Phone</button>
      </section>
      <noscript>Enable JavaScript to pair or manage this phone.</noscript>
    </div>
  </section>
  <section id="phone-chat" role="tabpanel" aria-labelledby="phone-tab-chat" hidden>
    <div class="phone-chat-history" id="phone-chat-history" tabindex="0" aria-label="Chat history" role="region">
      <h1 id="phone-chat-title">Chat</h1>
      <p id="phone-chat-empty">Send a message to the account owner.</p>
      <div id="phone-chat-turns" role="log" aria-label="Conversation" aria-live="polite" aria-relevant="additions text"></div>
    </div>
  </section>
  <footer id="phone-dock" aria-label="Phone chat and apps" hidden>
    <div class="phone-dock-inner">
      <div id="phone-apps" class="phone-apps" role="group" aria-label="Phone apps" hidden>
        <h2>Phone apps</h2>
        <button type="button" id="phone-app-camera"><span aria-hidden="true" class="phone-app-icon camera-icon"></span>Camera</button>
        <button type="button" id="phone-app-save-place" hidden><span aria-hidden="true" class="phone-app-icon place-icon"></span>Save location</button>
      </div>
      <p id="phone-chat-status" role="status" hidden></p>
      <form id="phone-chat-form">
        <button type="button" id="phone-apps-toggle" aria-label="Phone apps" aria-expanded="false" aria-controls="phone-apps"><span aria-hidden="true">+</span></button>
        <input id="phone-chat-input" type="text" aria-label="Message the account owner" placeholder="Message the owner…" maxlength="1000" enterkeyhint="send" autocomplete="off" required>
        <button type="submit" id="phone-chat-send" aria-label="Send message" disabled><span aria-hidden="true">↑</span></button>
      </form>
    </div>
  </footer>
</main>
<?php require __DIR__.'/includes/phone-save-place-view.php'; ?>
</body>
</html>
