<?php
declare(strict_types=1);

header('Cache-Control: private, no-store, max-age=0');
header('Referrer-Policy: no-referrer');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Permissions-Policy: geolocation=(self), camera=(self), microphone=()');
header("Content-Security-Policy: default-src 'none'; script-src 'self'; style-src 'self'; connect-src 'self'; img-src 'self'; media-src 'self' blob: mediastream:; base-uri 'none'; frame-ancestors 'none'; form-action 'none'");
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
  <meta name="referrer" content="no-referrer">
  <title>Phone connection · VP3</title>
  <link rel="stylesheet" href="phone-location-v1.css?v=6">
  <link rel="stylesheet" href="phone-camera-app-v1.css?v=2">
  <script src="phone-camera-ice-v1.js?v=1" defer></script>
  <script src="phone-camera-holder-v1.js?v=4" defer></script>
  <script src="phone-agent-chat-v1.js?v=1" defer></script>
  <script src="phone-save-place-v1.js?v=1" defer></script>
  <script src="phone-location-v1.js?v=8" defer></script>
</head>
<body>
<main id="phone-location" data-endpoint="api/phone-location-v1.php">
  <header class="phone-header"><b class="brand">VP3</b><span>Phone connection</span></header>
  <section class="phone-card" aria-labelledby="phone-label">
    <p class="state" id="phone-badge">Phone Tracking</p>
    <h1 id="phone-label">Connect this phone</h1>
    <p id="phone-status" role="status" aria-live="polite">Checking your connection…</p>
    <dl id="phone-details" hidden>
      <div><dt>Shares with</dt><dd id="phone-owner"></dd></div>
      <div><dt>Location</dt><dd id="phone-gps"></dd></div>
      <div id="phone-preference-row" hidden><dt>Updates</dt><dd id="phone-preferences"></dd></div>
    </dl>
    <form id="phone-join" hidden>
      <label for="phone-name">Phone name</label>
      <input id="phone-name" name="name" maxlength="48" autocomplete="off" placeholder="My iPhone" required>
      <button id="phone-claim" type="submit">Request connection</button>
    </form>
    <div id="phone-controls" hidden>
      <p id="phone-consent"></p>
      <button type="button" id="phone-start" hidden>Start sharing</button>
      <button type="button" id="phone-stop" hidden>Stop sharing</button>
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
    <noscript>Enable JavaScript to pair or manage this phone.</noscript>
  </section>
  <section id="phone-chat" aria-labelledby="phone-chat-title" hidden>
    <h2 id="phone-chat-title">Agent Chat</h2>
    <div id="phone-chat-turns" role="log" aria-label="Conversation" aria-live="polite" aria-relevant="additions text"></div>
  </section>
</main>
<footer id="phone-dock" aria-label="Agent Chat and phone apps" hidden>
  <div class="phone-dock-inner">
    <?php require __DIR__.'/includes/phone-camera-app-view.php'; ?>
    <div id="phone-apps" class="phone-apps" role="group" aria-label="Phone apps" hidden>
      <h2>Phone apps</h2>
      <button type="button" id="phone-app-camera"><span aria-hidden="true" class="phone-app-icon camera-icon"></span>Camera</button>
      <button type="button" id="phone-app-save-place" hidden><span aria-hidden="true" class="phone-app-icon place-icon"></span>Save location</button>
    </div>
    <p id="phone-chat-status" role="status" hidden></p>
    <form id="phone-chat-form">
      <button type="button" id="phone-apps-toggle" aria-label="Phone apps" aria-expanded="false" aria-controls="phone-apps"><span aria-hidden="true">+</span></button>
      <input id="phone-chat-input" type="text" aria-label="Message the connected agent" placeholder="Message the connected agent…" maxlength="1000" enterkeyhint="send" autocomplete="off" required>
      <button type="submit" id="phone-chat-send" aria-label="Send message" disabled><span aria-hidden="true">↑</span></button>
    </form>
  </div>
</footer>
<?php require __DIR__.'/includes/phone-save-place-view.php'; ?>
</body>
</html>
