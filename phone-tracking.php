<?php
declare(strict_types=1);

require __DIR__.'/includes/bootstrap.php';
require_once __DIR__.'/includes/agent-catch-up-v1.php';
require_login();
require_permission('chat.access');
require_permission('account.access');
$user = current_user();
header('Cache-Control: private, no-store, max-age=0');
header('Referrer-Policy: same-origin');
header('Permissions-Policy: camera=(), microphone=(self), geolocation=()');

$phoneChatParams = [];
foreach (['agent' => '/^(?:system|0|[1-9][0-9]{0,18})$/D', 'conversation_id' => '/^[1-9][0-9]{0,18}$/D'] as $key => $pattern) {
    if (is_string($_GET[$key] ?? null) && preg_match($pattern, $_GET[$key])) $phoneChatParams[$key] = $_GET[$key];
}
if (($phoneChatParams['agent'] ?? '') === '0') $phoneChatParams['agent'] = 'system';
$phoneChatUrl = url('/chat.php'.($phoneChatParams ? '?'.http_build_query($phoneChatParams) : ''));
$asset = static function (string $path): string {
    return url('/'.$path.'?v='.substr(hash_file('sha256', __DIR__.'/'.$path), 0, 12));
};
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Phone Tracking | <?= e(system_agent_name()) ?></title>
<link rel="stylesheet" href="<?= e(url('/chat.css?v=82')) ?>">
<link rel="stylesheet" href="<?= e($asset('assets/phone-map/leaflet.css')) ?>">
<link rel="stylesheet" href="<?= e($asset('phone-tracking-v1.css')) ?>">
<script defer src="<?= e($asset('assets/phone-map/leaflet.js')) ?>"></script>
<script defer src="<?= e($asset('assets/phone-map/qr.js')) ?>"></script>
<script defer src="<?= e($asset('phone-tracking-archive-v1.js')) ?>"></script>
<script defer src="<?= e($asset('phone-camera-ice-v1.js')) ?>"></script>
<script defer src="<?= e($asset('phone-camera-receiver-v1.js')) ?>"></script>
<script defer src="<?= e($asset('phone-map-camera-v1.js')) ?>"></script>
<script defer data-voice-capture-scope="<?= (int)$user['id'] ?>" src="<?= e($asset('voice-lease-v122.js')) ?>"></script>
<script defer src="<?= e($asset('phone-information-ui-v1.js')) ?>"></script>
<link rel="stylesheet" href="<?= e($asset('phone-remote-dialer-v1.css')) ?>">
<script defer src="<?= e($asset('phone-remote-dialer-v1.js')) ?>"></script>
<link rel="stylesheet" href="<?= e($asset('phone-managed-call-ui-v1.css')) ?>">
<script defer src="<?= e($asset('phone-managed-call-ui-v1.js')) ?>"></script>
<script defer src="<?= e($asset('phone-tracking-v1.js')) ?>"></script>
</head>
<body class="phone-tracking-page">
<div class="chat-app">
<?php $mainSidebarUser=$user; $mainSidebarActive='phone_tracking'; require __DIR__.'/includes/main-sidebar.php'; ?>
<div class="chat-sidebar-backdrop" id="chatSidebarBackdrop"></div>
<main class="chat-main phone-tracking-main">
<?php
$memberHeaderUser=$user;
$memberHeaderTitle='Phone Tracking';
require __DIR__.'/includes/member-header.php';
?>
<div class="phone-workspace" id="phone-workspace" data-endpoint="<?= e(url('/api/phone-tracking-v1.php')) ?>"
     data-basemap="<?= e($asset('assets/phone-map/world.json')) ?>" data-csrf="<?= e(csrf_token()) ?>"
     data-binding="<?= e(vp3_catch_up_page_binding_v1($user)) ?>"
     <?php if (isset($phoneChatParams['agent'], $phoneChatParams['conversation_id'])): ?>
     data-chat-agent="<?= (int)$phoneChatParams['agent'] ?>" data-chat-conversation="<?= e($phoneChatParams['conversation_id']) ?>"
     <?php endif; ?> data-connect="<?= ($_GET['connect'] ?? '') === '1' ? '1' : '0' ?>">
  <header class="phone-heading">
    <div><a class="phone-back" href="<?= e($phoneChatUrl) ?>"><span aria-hidden="true">←</span> Back to chat</a><h1>Phone Tracking</h1></div>
  </header>
  <p class="phone-message" id="phone-status" role="status"></p>
  <section class="phone-map-card" aria-label="Location map">
    <button type="button" class="phone-primary" id="phone-connect" aria-haspopup="dialog">+ Connect Phone</button>
    <div id="phone-map" aria-label="Phone location map. Use arrow keys to pan and plus or minus to zoom."></div>
    <div class="phone-map-tools"><span id="phone-map-count">Loading phones…</span><button type="button" id="phone-fit" disabled>Fit phones</button><button type="button" id="phone-streets" aria-haspopup="dialog">Street detail</button></div>
  </section>
  <div class="phone-panel-tabs" role="tablist" aria-label="Tracking views">
    <button type="button" role="tab" id="phone-tab-devices" aria-controls="phone-panel-devices" aria-selected="true">Connected phones</button>
    <button type="button" role="tab" id="phone-tab-history" aria-controls="phone-panel-history" aria-selected="false" tabindex="-1">History</button>
    <button type="button" role="tab" id="phone-tab-places" aria-controls="phone-panel-places" aria-selected="false" tabindex="-1">Saved places</button>
  </div>
  <section class="phone-devices-section" role="tabpanel" id="phone-panel-devices" aria-labelledby="phone-tab-devices">
    <div class="phone-list-title"><h2 id="phone-devices-title">Connected phones</h2><button type="button" id="phone-review" hidden>Review requests</button></div>
    <div class="phone-devices-layout">
      <div id="phone-devices"></div>
      <article id="phone-detail" aria-label="Phone details" hidden>
        <div id="phone-detail-summary"></div>
        <section id="phone-communication-profile" class="phone-connected-profile" aria-label="Connected user communication and activity" hidden></section>
        <div id="phone-dialer-owner-host"></div>
        <form id="phone-preferences" hidden>
          <fieldset>
            <legend>Location settings</legend>
            <div class="phone-preference-controls">
              <label>Update interval
                <select name="update_interval_seconds">
                  <option value="5">5 seconds</option>
                  <option value="15">15 seconds</option>
                  <option value="30">30 seconds</option>
                </select>
              </label>
              <label>GPS accuracy
                <select name="accuracy_mode">
                  <option value="high">High accuracy</option>
                  <option value="balanced">Balanced</option>
                </select>
              </label>
              <button type="submit" disabled>Save settings</button>
            </div>
          </fieldset>
          <p class="phone-preferences-status" role="status"></p>
        </form>
      </article>
    </div>
  </section>
  <section class="phone-archive-panel" role="tabpanel" id="phone-panel-history" aria-labelledby="phone-tab-history" hidden>
    <div class="phone-archive-heading"><h2>Location history</h2><label>Phone <select id="phone-history-device"></select></label></div>
    <p id="phone-history-status"></p>
    <form id="phone-history-form" class="phone-archive-actions">
      <label>Keep points for <select id="phone-history-retention"><option value="1">1 day</option><option value="7">7 days</option><option value="30">30 days</option></select></label>
      <button type="submit" id="phone-history-request">Request history</button>
      <button type="button" id="phone-history-stop" hidden>Stop history</button>
    </form>
    <div class="phone-trail-heading"><h3>Recorded trail</h3><span id="phone-history-count"></span></div>
    <p id="phone-history-range"></p>
    <ul id="phone-history-points" aria-label="Recent points on this page"></ul>
    <div class="phone-archive-actions">
      <button type="button" id="phone-history-fit" hidden>Fit trail</button>
      <button type="button" id="phone-history-earlier" hidden>Earlier points</button>
      <button type="button" id="phone-history-latest" hidden>Latest points</button>
      <button type="button" class="phone-destructive" id="phone-history-delete" hidden>Delete history</button>
    </div>
    <p id="phone-history-feedback" role="status"></p>
  </section>
  <section class="phone-archive-panel" role="tabpanel" id="phone-panel-places" aria-labelledby="phone-tab-places" hidden>
    <h2>Saved places</h2>
    <form id="phone-place-form" class="phone-archive-actions">
      <label>Phone <select id="phone-place-device"></select></label>
      <label>Place name <input id="phone-place-name" type="text" required maxlength="80" placeholder="Studio" autocomplete="off"></label>
      <button type="submit" id="phone-place-save">Save current location</button>
    </form>
    <p id="phone-place-source"></p>
    <ul id="phone-places-list" aria-label="Saved places"></ul>
    <p id="phone-place-feedback" role="status"></p>
  </section>
  <dialog id="phone-stream-dialog" class="phone-dialog phone-stream-dialog" aria-labelledby="phone-stream-name"
          data-endpoint="<?= e(url('/api/phone-camera-v1.php')) ?>">
    <header><h2 id="phone-stream-name">Phone camera</h2><button type="button" class="phone-close" id="phone-stream-close" aria-label="Close camera preview" autofocus>×</button></header>
    <div id="phone-stream-preview" data-state="connecting">
      <video id="phone-stream-video" autoplay playsinline muted disablepictureinpicture aria-label="Private live phone camera"></video>
      <span id="phone-stream-status" role="status"></span>
    </div>
    <button type="button" id="phone-stream-retry" hidden>Retry camera preview</button>
  </dialog>
  <dialog id="phone-archive-dialog" class="phone-dialog" aria-labelledby="phone-archive-dialog-title" aria-describedby="phone-archive-dialog-message">
    <header><h2 id="phone-archive-dialog-title"></h2></header><p id="phone-archive-dialog-message"></p>
    <div class="phone-dialog-actions"><button type="button" id="phone-archive-cancel" autofocus>Cancel</button><button type="button" class="phone-destructive" id="phone-archive-confirm">Delete</button></div>
  </dialog>
  <noscript><p>Enable JavaScript to pair phones and display their locations.</p></noscript>
  <dialog id="phone-pairing" aria-labelledby="phone-pair-title" class="phone-dialog">
    <header><h2 id="phone-pair-title">Connect phone</h2><button type="button" class="phone-close" id="phone-pair-close" aria-label="Close pairing">×</button></header>
    <div id="phone-invite-box" hidden>
      <p>Scan with the phone you want to connect. You’ll review its name and access here.</p>
      <div id="phone-qr"></div><p class="phone-expiry" id="phone-expiry"></p>
      <div class="phone-invite-actions"><a id="phone-join" target="_blank" rel="noopener noreferrer" hidden>Open pairing link</a><button type="button" id="phone-refresh-invite" hidden>Create new code</button></div>
      <details id="phone-install-code-box" hidden>
        <summary>Pair an installed VP3 Phone app instead</summary>
        <p>Open VP3 Phone from the Home Screen and enter this fresh one-use code. Share only with the intended phone holder. You still approve the resulting connection.</p>
        <label for="phone-install-code">Pairing code</label>
        <input id="phone-install-code" type="text" readonly autocomplete="off" spellcheck="false" autocapitalize="none">
        <button type="button" id="phone-copy-install-code">Copy pairing code</button>
        <p id="phone-copy-install-status" role="status"></p>
      </details>
    </div>
    <p id="phone-pair-status" role="status"></p>
    <div id="phone-handoff-pending"></div>
    <div id="phone-pending"></div>
  </dialog>
  <dialog id="phone-street-consent" class="phone-dialog" aria-labelledby="phone-street-title">
    <header><h2 id="phone-street-title">Load street detail?</h2><button type="button" class="phone-close" id="phone-street-close" aria-label="Close street detail">×</button></header>
    <p>OpenStreetMap will receive your IP address and the map areas you view, including areas around visible phones, trails and saved places. This applies while street detail is on.</p>
    <div class="phone-dialog-actions"><button type="button" id="phone-street-cancel">Keep local map</button><button type="button" class="phone-primary" id="phone-street-accept">Load street detail</button></div>
  </dialog>
  <dialog id="phone-revoke-confirm" class="phone-dialog" aria-labelledby="phone-revoke-title">
    <header><h2 id="phone-revoke-title">Disconnect phone?</h2></header><p id="phone-revoke-message"></p>
    <div class="phone-dialog-actions"><button type="button" id="phone-revoke-cancel" autofocus>Keep connected</button><button type="button" class="phone-primary" id="phone-revoke-accept">Disconnect phone</button></div>
  </dialog>
</div>
</main>
</div>
</body>
</html>
