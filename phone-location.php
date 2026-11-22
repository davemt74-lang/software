<?php
declare(strict_types=1);

header('Cache-Control: private, no-store, max-age=0');
header('Referrer-Policy: no-referrer');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Permissions-Policy: geolocation=(self), camera=(), microphone=()');
header("Content-Security-Policy: default-src 'none'; script-src 'self'; style-src 'self'; connect-src 'self'; img-src 'self'; base-uri 'none'; frame-ancestors 'none'; form-action 'none'");
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
  <meta name="referrer" content="no-referrer">
  <title>Phone connection · VP3</title>
  <link rel="stylesheet" href="phone-location-v1.css">
  <script src="phone-location-v1.js?v=2" defer></script>
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
      <p id="phone-consent"></p>
      <button type="button" id="phone-start" hidden>Start sharing</button>
      <button type="button" id="phone-stop" hidden>Stop sharing</button>
      <button type="button" class="secondary" id="phone-retry" hidden>Check connection</button>
      <button type="button" class="text-button" id="phone-disconnect" hidden>Disconnect phone</button>
    </div>
    <noscript>Enable JavaScript to pair or manage this phone.</noscript>
  </section>
</main>
</body>
</html>
