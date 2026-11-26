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
  <script src="phone-pwa-v1.js?v=2" defer></script>
  <link rel="stylesheet" href="phone-location-v1.css?v=13">
  <link rel="stylesheet" href="phone-remote-dialer-v1.css?v=1">
  <link rel="stylesheet" href="phone-managed-call-ui-v1.css?v=3">
  <link rel="stylesheet" href="phone-camera-app-v1.css?v=2">
  <script src="phone-camera-ice-v1.js?v=1" defer></script>
  <link rel="stylesheet" href="phone-recording-holder-v1.css?v=1">
  <script src="phone-recording-holder-v1.js?v=1" defer></script>
  <script src="phone-camera-holder-v1.js?v=6" defer></script>
  <script src="phone-agent-chat-v1.js?v=6" defer></script>
  <script src="phone-save-place-v1.js?v=1" defer></script>
  <script src="phone-information-ui-v1.js?v=4" defer></script>
  <script src="phone-remote-dialer-v1.js?v=1" defer></script>
  <script src="phone-managed-call-ui-v1.js?v=5" defer></script>
  <script src="phone-handoff-v1.js?v=1" defer></script>
  <script src="phone-push-subscriptions-v1.js?v=2" defer></script>
  <script src="phone-location-v1.js?v=18" defer></script>
</head>
<body>
<main id="phone-location" data-endpoint="api/phone-location-v1.php">
  <header class="phone-header">
    <div class="phone-header-inner">
      <div class="phone-brand-line"><b class="brand">VP3</b><span>Phone connection</span></div>
      <div class="phone-tabs" id="phone-tabs" role="tablist" aria-label="Connected phone" hidden>
        <button type="button" role="tab" id="phone-tab-connection" aria-controls="phone-connection" aria-selected="true">Connection</button>
        <button type="button" role="tab" id="phone-tab-chat" aria-controls="phone-chat" aria-selected="false" tabindex="-1">Chat</button>
        <button type="button" role="tab" id="phone-tab-info" aria-controls="phone-info" aria-selected="false" tabindex="-1">Info</button>
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
        <button id="phone-pair-cancel" class="secondary" type="button" hidden>Use a different pairing code</button>
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
      <p id="phone-setup-help" class="phone-guide-footer">Need help getting started? <button id="phone-setup-guide" class="phone-guide-link" type="button" aria-label="Click here for iPhone setup and notifications">Click here</button></p>
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
  <section id="phone-info" role="region" aria-labelledby="phone-info-title" hidden>
    <div class="phone-guide">
      <h1 id="phone-info-title" tabindex="-1">Using VP3 Phone</h1>
      <p>Your connection, private messages and Agent requests, in one place.</p>
      <section id="phone-install-panel" class="phone-guide-section" aria-labelledby="phone-install-title">
        <h2 id="phone-install-title">Add to your iPhone</h2>
        <p id="phone-install-status" role="status">Checking installation…</p>
        <ol>
          <li>Open this page in Safari.</li>
          <li>Tap Share, or the page menu and then Share.</li>
          <li>Choose Add to Home Screen. If offered, turn on Open as Web App, then tap Add.</li>
          <li>Open VP3 Phone from your Home Screen.</li>
        </ol>
        <p id="phone-install-help">If the installed app is not connected, use an owner-approved pairing code.</p>
        <button id="phone-install-button" type="button" class="secondary" hidden>Install VP3 Phone</button>
      </section>
      <section class="phone-guide-section" aria-labelledby="phone-guide-notifications-title">
        <h2 id="phone-guide-notifications-title">Notifications</h2>
        <p class="phone-guide-status" id="phone-guide-notification-state">Background notifications are not enabled yet.</p>
        <p>Install VP3 Phone on your Home Screen, then allow notifications. On iPhone or iPad, iOS or iPadOS 16.4 or later is required. Use Settings &gt; Notifications &gt; VP3 Phone to manage alerts.</p>
        <div id="phone-push-controls" hidden>
          <button id="phone-push-enable" type="button" hidden>Enable notifications</button>
          <button id="phone-push-disable" type="button" class="secondary" hidden>Turn off notifications</button>
          <button id="phone-push-test" type="button" class="secondary" hidden>Send test notification</button>
          <p id="phone-push-feedback" role="status"></p>
          <p id="phone-push-test-state" role="status"></p>
        </div>
        <p class="phone-install-note">When background delivery is enabled, alerts can notify you about private replies and information requests for this phone. Test status distinguishes push-service acceptance from an alert reported by this installation. Opening an alert checks your current connection and permissions.</p>
      </section>
      <section class="phone-guide-section" aria-labelledby="phone-guide-requests-title">
        <h2 id="phone-guide-requests-title">Agent requests</h2>
        <dl class="phone-guide-requests">
          <div><dt>Chat</dt><dd>Send a private message and see replies.</dd></div>
          <div><dt>Calls</dt><dd>Review the destination and choose whether to approve. Carrier charges may apply.</dd></div>
          <div><dt>Location &amp; camera</dt><dd>You decide when to share or stop.</dd></div>
          <div><dt>Information</dt><dd>Review questions and requests for photos or files.</dd></div>
        </dl>
        <p>A request or notification is not permission to start a call, record, or turn on your camera or location. You can decline requests and disconnect.</p>
      </section>
      <details id="phone-handoff-panel" class="phone-guide-section" hidden>
        <summary>Move an existing connection</summary>
        <p id="phone-handoff-help"></p>
        <div id="phone-handoff-source" hidden>
          <button id="phone-handoff-offer" type="button" class="secondary">Create move code</button>
          <div id="phone-handoff-ticket" hidden>
            <label for="phone-handoff-code">One-time move code</label>
            <input id="phone-handoff-code" type="text" readonly autocomplete="off" spellcheck="false">
            <button id="phone-handoff-copy" type="button" class="secondary">Copy code</button>
          </div>
          <button id="phone-handoff-cancel" type="button" class="text-button" hidden>Cancel move</button>
        </div>
        <form id="phone-handoff-target" autocomplete="off" hidden>
          <label for="phone-handoff-entry">Move code from your old app</label>
          <input id="phone-handoff-entry" type="text" maxlength="43" autocomplete="off" autocapitalize="none" spellcheck="false" required>
          <button id="phone-handoff-claim" type="submit" class="secondary">Request move</button>
        </form>
        <div id="phone-handoff-review" hidden>
          <p>Tell the owner this verification code: <strong id="phone-handoff-compare"></strong></p>
          <button id="phone-handoff-check" type="button" class="secondary">Check approval</button>
          <button id="phone-handoff-finish" type="button" hidden>Finish move</button>
          <button id="phone-handoff-abandon" type="button" class="text-button">Clear this request</button>
        </div>
        <p id="phone-handoff-status" role="status"></p>
      </details>
      <button id="phone-info-back" type="button" class="secondary">Back to connection</button>
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
      <p id="phone-dock-help" class="phone-guide-footer">iPhone setup &amp; notifications? <button id="phone-footer-guide" class="phone-guide-link" type="button" aria-label="Click here for iPhone setup and notifications">Click here</button></p>
    </div>
  </footer>
</main>
<?php require __DIR__.'/includes/phone-save-place-view.php'; ?>
</body>
</html>
