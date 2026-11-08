<?php
declare(strict_types=1);

/** @var array{client_id:string,origin:string,scope:string,attempt:string,csrf_token:string,endpoint:string,return_url:string} $popup */
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Connect Gmail · VP3</title>
  <link rel="stylesheet" href="<?= e(url('/connections-v1.css?v=2')) ?>">
  <link rel="stylesheet" href="<?= e(url('/gmail-popup-v1.css')) ?>">
  <script src="https://accounts.google.com/gsi/client" defer></script>
  <script src="<?= e(url('/gmail-popup-v1.js')) ?>" defer></script>
</head>
<body class="gmail-popup-page">
  <main class="connections-content gmail-popup-content">
    <a class="gmail-popup-back" href="<?= e($popup['return_url']) ?>">← Back to Connections</a>
    <section class="connections-app gmail-popup-card" aria-labelledby="gmail-popup-title"
      data-gmail-popup
      data-client-id="<?= e($popup['client_id']) ?>"
      data-origin="<?= e($popup['origin']) ?>"
      data-scope="<?= e($popup['scope']) ?>"
      data-attempt="<?= e($popup['attempt']) ?>"
      data-csrf-token="<?= e($popup['csrf_token']) ?>"
      data-endpoint="<?= e($popup['endpoint']) ?>"
      data-return-url="<?= e($popup['return_url']) ?>">
      <h1 id="gmail-popup-title">Connect Gmail</h1>
      <p class="connections-muted">Choose your Gmail account in the Google window and grant the requested read and send permissions. Keep this VP3 page open while you connect.</p>
      <button class="connections-button" type="button" data-gmail-popup-start disabled>Continue with Google</button>
      <p class="gmail-popup-status" role="status" data-gmail-popup-status></p>
      <p class="connections-notice connections-error" role="alert" data-gmail-popup-error hidden></p>
      <noscript><p class="connections-notice connections-error">Enable JavaScript in your browser to connect using the Google window.</p></noscript>
    </section>
  </main>
</body>
</html>
