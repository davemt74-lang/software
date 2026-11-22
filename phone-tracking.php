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
header('Permissions-Policy: camera=(), microphone=(), geolocation=()');

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
  <section class="phone-devices-section" aria-labelledby="phone-devices-title">
    <div class="phone-list-title"><h2 id="phone-devices-title">Connected phones</h2><button type="button" id="phone-review" hidden>Review requests</button></div>
    <div class="phone-devices-layout"><div id="phone-devices"></div><article id="phone-detail" aria-label="Phone details" hidden></article></div>
  </section>
  <noscript><p>Enable JavaScript to pair phones and display their locations.</p></noscript>
  <dialog id="phone-pairing" aria-labelledby="phone-pair-title" class="phone-dialog">
    <header><h2 id="phone-pair-title">Connect phone</h2><button type="button" class="phone-close" id="phone-pair-close" aria-label="Close pairing">×</button></header>
    <div id="phone-invite-box" hidden>
      <p>Scan with the phone you want to connect. You’ll review its name and access here.</p>
      <div id="phone-qr"></div><p class="phone-expiry" id="phone-expiry"></p>
      <div class="phone-invite-actions"><a id="phone-join" target="_blank" rel="noopener noreferrer" hidden>Open pairing link</a><button type="button" id="phone-refresh-invite" hidden>Create new code</button></div>
    </div>
    <p id="phone-pair-status" role="status"></p>
    <div id="phone-pending"></div>
  </dialog>
  <dialog id="phone-street-consent" class="phone-dialog" aria-labelledby="phone-street-title">
    <header><h2 id="phone-street-title">Load street detail?</h2><button type="button" class="phone-close" id="phone-street-close" aria-label="Close street detail">×</button></header>
    <p>OpenStreetMap will receive your IP address and the map areas you view, including areas around visible phones. This applies while street detail is on.</p>
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
