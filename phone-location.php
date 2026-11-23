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
  <link rel="stylesheet" href="phone-location-v1.css?v=5">
  <script src="phone-camera-ice-v1.js?v=1" defer></script>
  <script src="phone-camera-holder-v1.js?v=2" defer></script>
  <script src="phone-location-v1.js?v=7" defer></script>
</head>
<body>
<main id="phone-location" data-endpoint="api/phone-location-v1.php">
  <p class="brand">VP3 <span>/</span> Phone connection</p>
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
      <section id="phone-camera" class="phone-permission" aria-labelledby="phone-camera-title" hidden>
        <h2 id="phone-camera-title">Agent Chat · Camera preview</h2>
        <p id="phone-camera-status" role="status" aria-live="polite"></p>
        <video id="phone-camera-preview" aria-label="Your camera preview" muted autoplay playsinline hidden></video>
        <div class="phone-permission-buttons">
          <button type="button" id="phone-camera-start" hidden>Start Sharing</button>
          <button type="button" id="phone-camera-decline" class="secondary" hidden>Decline</button>
          <button type="button" id="phone-camera-stop" hidden>Stop Sharing</button>
        </div>
      </section>
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
</main>
</body>
</html>
