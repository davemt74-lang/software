<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';

$user = current_user();
if (!$user) {
    redirect(url('/login.php'));
}
if (!has_permission('artist_listening.access', $user)) {
    http_response_code(403);
    exit('Artist Listening permission is required.');
}

if (!function_exists('public_platform_v159_user_has_type')) {
    function public_platform_v159_user_has_type(int $userId, string $role): bool
    {
        $userId = max(0, $userId);
        $role = strtolower(trim($role));
        if ($userId < 1 || $role === '') return false;
        $pdo = db();
        if (!$pdo) return false;
        try {
            if (table_exists('user_account_types')) {
                $stmt = $pdo->prepare('SELECT 1 FROM user_account_types WHERE user_id=? AND role=? LIMIT 1');
                $stmt->execute([$userId, $role]);
                if ($stmt->fetchColumn()) return true;
            }
            $stmt = $pdo->prepare('SELECT role FROM users WHERE id=? LIMIT 1');
            $stmt->execute([$userId]);
            return strtolower(trim((string)$stmt->fetchColumn())) === $role;
        } catch (Throwable $e) {
            return false;
        }
    }
}

require_once __DIR__ . '/includes/artist-listening.php';
require_once __DIR__ . '/includes/artist-listening-transcript.php';

$pdo = db();
$tracks = [];
if ($pdo && table_exists('tracks')) {
    try {
        $rows = $pdo->query('SELECT id,title FROM tracks ORDER BY id DESC LIMIT 250')->fetchAll() ?: [];
        foreach ($rows as $row) {
            $trackId = (int)($row['id'] ?? 0);
            if ($trackId > 0 && artist_listening_v172_track_allowed($pdo, $user, $trackId)) {
                $tracks[] = ['id'=>$trackId,'title'=>trim((string)($row['title'] ?? '')) ?: ('Track #' . $trackId)];
            }
        }
    } catch (Throwable $e) {
        $tracks = [];
    }
}

$canProjectNotes = has_permission('track_notes.manage', $user)
    || has_permission('tracks.manage', $user)
    || has_permission('producer.access', $user);
$canKnowledge = has_permission('knowledge.manage', $user)
    && table_exists('knowledge_items')
    && column_exists('knowledge_items', 'owner_user_id');
$initialSessionId = max(0, (int)($_GET['session'] ?? 0));
$config = [
    'endpoint'=>url('/api/artist-listening-v172.php'),
    'longEndpoint'=>url('/api/artist-listening-long-v237.php'),
    'userId'=>(int)$user['id'],
    'userMenuLinks'=>member_navigation_menu_links($user),
    'csrf'=>csrf_token(),
    'conversationId'=>0,
    'schemaReady'=>artist_listening_v172_schema_ready(),
    'longSchemaReady'=>artist_listening_v237_schema_ready(),
    'initialSessionId'=>$initialSessionId,
    'canProjectNotes'=>$canProjectNotes,
    'canKnowledge'=>$canKnowledge,
    'tracks'=>$tracks,
];
?>
<!doctype html>
<html lang="en-US">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Artist Listening · Stonefellow</title>
  <meta name="robots" content="noindex,nofollow">
  <link rel="stylesheet" href="<?= e(url('/artist-listening.css?v=artist-listening-normalized-20260903')) ?>">
  <style>
    :root{color-scheme:light;--sf-listening-sidebar-width:292px;--sf-listening-player-height:58px;--sf-listening-nav-height:42px}
    *{box-sizing:border-box}
    body{margin:0;background:#fff;color:#171717;font-family:Inter,ui-sans-serif,system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif}
    .sf-hidden-command-form{position:absolute;width:1px;height:1px;overflow:hidden;clip:rect(0,0,0,0);white-space:nowrap}
    .sf-listening-workspace-file-rename{border:0;background:transparent;color:#777;cursor:pointer;font-size:16px;line-height:1;padding:8px 6px}
    .sf-listening-workspace-file-rename:hover,.sf-listening-workspace-file-rename:focus-visible{color:#111}
    .sf-listening-workspace-file-rename:disabled{opacity:.45;cursor:default}

    /* Two-layer footer: transcript navigation above the full-width control footer. */
    .sf-listening-workspace-editor-content{grid-template-rows:auto auto auto minmax(0,1fr)!important}
    .sf-listening-workspace-editor-top{grid-row:1!important}
    .sf-listening-workspace-meta-bar{grid-row:3!important}
    .sf-listening-workspace-document-area,.sf-listening-transcript-continuous{grid-row:4!important;min-height:0}
    .sf-transcript-workspace{padding-bottom:calc(var(--sf-listening-player-height) + var(--sf-listening-nav-height))!important}

    #sfListeningTranscriptNav{
      grid-row:2!important;
      position:fixed!important;
      z-index:10015!important;
      left:var(--sf-listening-sidebar-width)!important;
      right:0!important;
      bottom:var(--sf-listening-player-height)!important;
      width:auto!important;
      max-width:none!important;
      height:var(--sf-listening-nav-height)!important;
      min-height:var(--sf-listening-nav-height)!important;
      max-height:var(--sf-listening-nav-height)!important;
      margin:0!important;
      padding:6px 12px!important;
      display:flex!important;
      align-items:center!important;
      justify-content:flex-start!important;
      flex-wrap:nowrap!important;
      gap:6px!important;
      overflow-x:auto!important;
      overflow-y:hidden!important;
      white-space:nowrap!important;
      border:0!important;
      border-top:1px solid #e3e3e3!important;
      border-bottom:1px solid #e3e3e3!important;
      border-radius:0!important;
      background:#fff!important;
      box-shadow:none!important;
    }
    #sfListeningTranscriptNav>*{flex:0 0 auto}
    #sfListeningTranscriptNav .sf-listening-transcript-total{display:block!important;margin-left:auto!important;padding-left:12px;white-space:nowrap!important}

    .sf-listening-workspace-listening-player{
      position:fixed!important;
      z-index:10020!important;
      left:0!important;
      right:0!important;
      bottom:0!important;
      width:100vw!important;
      height:var(--sf-listening-player-height)!important;
      min-height:var(--sf-listening-player-height)!important;
      max-height:var(--sf-listening-player-height)!important;
      margin:0!important;
      padding:8px 16px 8px calc(var(--sf-listening-sidebar-width) + 16px)!important;
      display:flex!important;
      align-items:center!important;
      justify-content:flex-start!important;
      flex-direction:row!important;
      flex-wrap:nowrap!important;
      gap:12px!important;
      overflow-x:auto!important;
      overflow-y:hidden!important;
      white-space:nowrap!important;
      border-top:1px solid #ddd!important;
      background:rgba(255,255,255,.98)!important;
      box-shadow:0 -8px 24px rgba(0,0,0,.08)!important;
      backdrop-filter:blur(12px)
    }
    .sf-listening-workspace-capture-actions.sf-listening-footer-left{
      display:flex!important;
      align-items:center!important;
      justify-content:flex-start!important;
      flex:0 0 auto!important;
      flex-wrap:nowrap!important;
      gap:7px!important;
      overflow:visible!important;
      white-space:nowrap!important;
    }
    .sf-listening-workspace-capture-actions.sf-listening-footer-left .sf-listening-workspace-live-status{
      display:flex!important;
      align-items:center!important;
      flex:0 0 auto!important;
      gap:6px!important;
      margin-left:4px!important;
      white-space:nowrap!important;
    }
    .sf-listening-footer-right{
      display:flex!important;
      align-items:center!important;
      flex:0 0 auto!important;
      flex-wrap:nowrap!important;
      gap:7px!important;
      margin-left:auto!important;
      white-space:nowrap!important;
    }
    .sf-listening-footer-right [data-listening-workspace-save],
    .sf-listening-footer-right [data-listening-start]{flex:0 0 auto!important}

    /* EXIT belongs to the library header; the main header keeps AI Summary only. */
    .sf-listening-workspace-side-head [data-listening-workspace-exit]{display:inline-flex!important;align-items:center!important;justify-content:center!important;flex:0 0 auto!important;min-height:31px;padding:6px 9px;border:1px solid #d6d6d6;border-radius:7px;background:#fff;color:#222;font:800 9px/1 system-ui;text-decoration:none;letter-spacing:.04em;white-space:nowrap}
    .sf-listening-workspace-side-head [data-listening-workspace-exit]:hover{background:#f4f4f4;border-color:#aaa}
    .sf-listening-workspace-editor-top>.sf-listening-workspace-toolbar{margin-left:auto!important;overflow:visible!important}

    /* AI Summary footer, app filters, result tabs, and stats canvas. */
    .sf-listening-ai-footer{display:block!important;padding:10px 12px!important;background:#fafafa!important}
    .sf-listening-ai-footer-actions{display:flex!important;align-items:center!important;gap:7px!important;flex-wrap:nowrap!important;overflow-x:auto!important;overflow-y:hidden!important;width:100%!important}
    .sf-listening-ai-footer-actions button{flex:1 1 0!important;min-width:0!important;min-height:34px!important;padding:7px 8px!important;white-space:nowrap!important;font-size:9px!important}
    .sf-listening-ai-footer-actions [data-listening-ai-analyze]{flex:0 0 88px!important;background:#171717!important;border-color:#171717!important;color:#fff!important}
    .sf-listening-ai-report-state{display:flex!important;align-items:center!important;gap:8px!important;margin:0 0 12px!important;padding:8px 10px!important;border:1px solid #ececec!important;border-radius:7px!important;background:#fafafa!important;color:#747474!important;font-size:10px!important;line-height:1.35!important}
    .sf-listening-ai-report-state strong{color:#333!important;font-weight:800!important}
    .sf-listening-ai-report-state span{margin-left:auto!important;color:#777!important;text-align:right!important}
    .sf-listening-ai-status-row{display:flex!important;align-items:center!important;gap:8px!important}
    .sf-listening-ai-status-row>[data-listening-ai-status]{flex:1 1 auto;min-width:0}
    .sf-listening-ai-settings{display:grid;place-items:center;flex:0 0 28px;width:28px;height:28px;margin-left:auto;padding:0;border:1px solid transparent;border-radius:7px;background:transparent;color:#777;cursor:pointer}
    .sf-listening-ai-settings:hover,.sf-listening-ai-settings.open{border-color:#d8d8d8;background:#f3f3f3;color:#222}
    .sf-listening-ai-settings:focus-visible{outline:2px solid #222;outline-offset:2px}
    .sf-listening-ai-settings svg{width:15px;height:15px;fill:none;stroke:currentColor;stroke-width:1.6;stroke-linecap:round;stroke-linejoin:round}
    .sf-listening-ai-apps{padding:10px 12px 11px;border-bottom:1px solid #ececec;background:#fff}
    .sf-listening-ai-apps[hidden]{display:none!important}
    .sf-listening-ai-apps-head{display:flex;align-items:baseline;justify-content:space-between;gap:10px;margin-bottom:8px}
    .sf-listening-ai-apps-head strong{font-size:10px;letter-spacing:.04em;text-transform:uppercase;color:#333}
    .sf-listening-ai-apps-head span{font-size:9px;color:#8b8b8b}
    .sf-listening-ai-app-options{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:6px}
    .sf-listening-ai-app-options label{display:flex;align-items:flex-start;gap:7px;min-width:0;padding:7px 8px;border:1px solid #e6e6e6;border-radius:7px;background:#fafafa;color:#444;font-size:9px;line-height:1.25;cursor:pointer}
    .sf-listening-ai-app-options label:has(input:checked){border-color:#bdbdbd;background:#f2f2f2;color:#171717;font-weight:750}
    .sf-listening-ai-app-options input{flex:0 0 auto;margin:1px 0 0;accent-color:#171717}
    .sf-listening-ai-app-options span{min-width:0}
    .sf-listening-ai-tabs{display:flex;align-items:center;gap:4px;min-height:38px;padding:5px 8px;border-bottom:1px solid #e8e8e8;background:#fafafa;overflow-x:auto;overflow-y:hidden;white-space:nowrap}
    .sf-listening-ai-tabs button{flex:0 0 auto;min-height:27px;padding:5px 9px;border:1px solid transparent;border-radius:6px;background:transparent;color:#777;font-size:9px;font-weight:750;cursor:pointer}
    .sf-listening-ai-tabs button:hover{background:#f0f0f0;color:#222}
    .sf-listening-ai-tabs button.active{border-color:#d4d4d4;background:#fff;color:#171717;box-shadow:0 1px 2px rgba(0,0,0,.04)}
    .sf-listening-ai-stat-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:7px;margin:0 0 14px}
    .sf-listening-ai-stat-grid>div{padding:10px;border:1px solid #e7e7e7;border-radius:8px;background:#fafafa}
    .sf-listening-ai-stat-grid small{display:block;margin-bottom:4px;color:#858585;font-size:8px;font-weight:750;letter-spacing:.05em;text-transform:uppercase}
    .sf-listening-ai-stat-grid strong{display:block;color:#202020;font-size:17px;line-height:1.1}
    .sf-listening-ai-chart{display:grid;gap:10px;margin-top:9px}
    .sf-listening-ai-chart-row{display:grid;gap:5px}
    .sf-listening-ai-chart-label{display:flex;align-items:center;justify-content:space-between;gap:10px;font-size:9px;color:#555}
    .sf-listening-ai-chart-label span{font-weight:750;color:#333}
    .sf-listening-ai-chart-label b{font-size:8px;color:#8a8a8a;white-space:nowrap}
    .sf-listening-ai-chart-track{height:7px;border-radius:999px;background:#ececec;overflow:hidden}
    .sf-listening-ai-chart-track i{display:block;height:100%;min-width:2px;border-radius:inherit;background:#222}

    /* Compact account menu beside AI Summary. */
    .sf-listening-user-menu{position:relative;display:flex;align-items:center;flex:0 0 auto;z-index:10070}
    .sf-listening-user-menu-button{display:grid;place-items:center;width:34px;height:34px;padding:0;border:1px solid #d8d8d8;border-radius:50%;background:#fff;color:#333;cursor:pointer;overflow:hidden}
    .sf-listening-user-menu-button:hover,.sf-listening-user-menu-button[aria-expanded="true"]{border-color:#a9a9a9;background:#f7f7f7}
    .sf-listening-user-avatar{position:relative;display:grid;place-items:center;width:100%;height:100%;border-radius:50%;overflow:hidden;background:#f2f2f2;color:#666}
    .sf-listening-user-avatar img{width:100%;height:100%;object-fit:cover}
    .sf-listening-user-avatar svg{width:17px;height:17px;fill:none;stroke:currentColor;stroke-width:1.7;stroke-linecap:round;stroke-linejoin:round}
    .sf-listening-user-menu-dropdown{position:absolute;top:calc(100% + 8px);right:0;width:238px;padding:7px;border:1px solid #dedede;border-radius:10px;background:#fff;box-shadow:0 18px 50px rgba(0,0,0,.15);z-index:10080}
    .sf-listening-user-menu-dropdown[hidden]{display:none!important}
    .sf-listening-user-menu-dropdown a{display:flex;align-items:center;justify-content:space-between;gap:16px;padding:10px 11px;border-radius:7px;color:#242424;text-decoration:none;font-size:11px;font-weight:750;white-space:nowrap}
    .sf-listening-user-menu-dropdown a:hover{background:#f4f4f4}
    .sf-listening-user-menu-dropdown a:last-child{margin-top:4px;border-top:1px solid #ececec;border-radius:0 0 7px 7px;color:#8a3535}
    .sf-listening-user-menu-dropdown span:last-child{color:#999;font-weight:600}

    /* Mobile library drawer reuses the existing sidebar and transcript DOM. */
    .sf-listening-mobile-menu-toggle,.sf-listening-mobile-menu-shade{display:none}

    @media(max-width:900px) and (min-width:721px){
      :root{--sf-listening-sidebar-width:238px;--sf-listening-player-height:60px}
    }

    @media(max-width:720px){
      :root{--sf-listening-sidebar-width:0px;--sf-listening-player-height:60px;--sf-listening-nav-height:42px}
      body.sf-listening-mobile-menu-open{overflow:hidden!important}
      .sf-transcript-workspace{display:block!important;width:100%!important;min-height:100dvh!important;padding-bottom:calc(var(--sf-listening-player-height) + var(--sf-listening-nav-height))!important}
      .sf-listening-workspace-editor-shell{width:100%!important;min-width:0!important}
      .sf-listening-workspace-editor-content{width:100%!important;min-height:0!important}

      .sf-listening-workspace-sidebar{
        position:fixed!important;
        z-index:10060!important;
        inset:0 auto 0 0!important;
        display:grid!important;
        grid-template-rows:auto auto auto minmax(0,1fr)!important;
        width:min(86vw,320px)!important;
        max-width:320px!important;
        height:100dvh!important;
        min-height:0!important;
        overflow:hidden!important;
        border:0!important;
        border-right:1px solid #ddd!important;
        background:#fafafa!important;
        box-shadow:18px 0 45px rgba(0,0,0,.16)!important;
        transform:translateX(-104%)!important;
        transition:transform .2s ease!important;
      }
      body.sf-listening-mobile-menu-open .sf-listening-workspace-sidebar{transform:translateX(0)!important}
      .sf-listening-workspace-side-head{display:flex!important;align-items:center!important;gap:8px!important;padding:10px!important}
      .sf-listening-workspace-side-head .sf-listening-workspace-new{margin-left:auto!important}
      .sf-listening-workspace-search{display:block!important;padding:0 10px 10px!important}
      .sf-listening-workspace-folders{display:block!important;max-height:44dvh!important;overflow:auto!important;padding:6px 8px!important}
      .sf-listening-workspace-folder-label,.sf-listening-workspace-folder-heading{display:flex!important}
      .sf-listening-workspace-tag-folders{display:block!important;max-height:none!important}
      .sf-listening-workspace-folder-row{display:grid!important;grid-template-columns:minmax(0,1fr) 25px!important;min-width:0!important}
      .sf-listening-workspace-folder{width:100%!important;min-width:0!important}
      .sf-listening-workspace-folder-delete{display:block!important}
      .sf-listening-workspace-files{display:block!important;min-height:0!important;overflow:auto!important;padding:8px!important}
      .sf-listening-workspace-file{width:100%!important;min-width:0!important;max-width:none!important}

      .sf-listening-mobile-menu-toggle{
        display:grid!important;
        place-items:center!important;
        flex:0 0 36px!important;
        width:36px!important;
        height:34px!important;
        padding:0!important;
        border:1px solid #d6d6d6!important;
        border-radius:8px!important;
        background:#fff!important;
        color:#222!important;
        font:800 19px/1 system-ui!important;
        cursor:pointer!important;
      }
      .sf-listening-mobile-menu-shade{
        display:block!important;
        position:fixed!important;
        z-index:10055!important;
        inset:0!important;
        border:0!important;
        background:rgba(0,0,0,.24)!important;
        opacity:0!important;
        pointer-events:none!important;
        transition:opacity .2s ease!important;
      }
      body.sf-listening-mobile-menu-open .sf-listening-mobile-menu-shade{opacity:1!important;pointer-events:auto!important}

      .sf-listening-workspace-editor-top{flex-wrap:nowrap!important;gap:8px!important;padding:9px 10px!important}
      .sf-listening-workspace-title-input{flex:1 1 auto!important;min-width:0!important}
      .sf-listening-workspace-editor-top>.sf-listening-workspace-toolbar{flex:0 0 auto!important;overflow:visible!important}
      .sf-listening-user-menu-button{width:32px;height:32px}
      .sf-listening-user-menu-dropdown{position:fixed;top:52px;right:8px;width:min(250px,calc(100vw - 16px))}
      .sf-listening-ai-apps-head{align-items:flex-start;flex-direction:column;gap:2px}
      .sf-listening-ai-tabs{padding-inline:6px}

      #sfListeningTranscriptNav{left:0!important;right:0!important;width:100vw!important;padding:6px 10px!important}
      .sf-listening-workspace-listening-player{padding:7px 10px!important;gap:10px!important}
      .sf-listening-workspace-capture-actions.sf-listening-footer-left{gap:5px!important}
      .sf-listening-workspace-capture-actions.sf-listening-footer-left .sf-listening-workspace-live-status{gap:5px!important;margin-left:2px!important}
      .sf-listening-footer-right{gap:5px!important}
      .sf-listening-workspace-listening-player .sf-listening-workspace-btn{min-height:32px!important;padding:6px 8px!important}
    }
  </style>
</head>
<body>
<form id="chatForm" class="sf-hidden-command-form" aria-hidden="true"><input id="chatInput" type="text" tabindex="-1" autocomplete="off"></form>
<script>
  window.STONEFELLOW_ARTIST_LISTENING_CONFIG=<?= json_encode($config, JSON_UNESCAPED_SLASHES|JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT) ?>;
  window.STONEFELLOW_ARTIST_LISTENING_V172=window.STONEFELLOW_ARTIST_LISTENING_CONFIG;
</script>
  <script src="<?= e(url('/artist-listening-realtime.js?v=e07b7c39')) ?>"></script>
  <script src="<?= e(url('/artist-listening-recognition.js?v=artist-listening-normalized-20260903')) ?>"></script>
  <script src="<?= e(url('/artist-listening-transcript.js?v=artist-listening-normalized-20260903')) ?>"></script>
  <script src="<?= e(url('/artist-listening-workspace.js?v=artist-listening-normalized-20260903')) ?>"></script>
  <script src="<?= e(url('/artist-listening.js?v=9ac023be')) ?>"></script>
  <script src="<?= e(url('/artist-listening-recordings.js?v=artist-listening-normalized-20260903')) ?>"></script>
  <script src="<?= e(url('/artist-listening-naming.js?v=artist-listening-normalized-20260903')) ?>"></script>
  <script>window.STONEFELLOW_ARTIST_LISTENING_V172=Object.assign(window.STONEFELLOW_ARTIST_LISTENING_V172||{},window.STONEFELLOW_ARTIST_LISTENING_CONFIG||{});</script>
  <script src="<?= e(url('/artist-listening-ai.js?v=c18c3dc8&b=1d511bb5')) ?>"></script>
  <script src="<?= e(url('/artist-listening-ui.js?v=artist-listening-normalized-20260903')) ?>"></script>
  <script src="<?= e(url('/transcription-editor.js?v=transcription-editor-api-20260903')) ?>"></script>
  <script>
  (() => {
    'use strict';

    const mobileQuery = window.matchMedia('(max-width:720px)');
    const listeningConfig = window.STONEFELLOW_ARTIST_LISTENING_CONFIG || {};
    let wired = false;

    function closeMobileMenu() {
      document.body.classList.remove('sf-listening-mobile-menu-open');
      const toggle = document.querySelector('[data-listening-mobile-menu-toggle]');
      if (toggle) toggle.setAttribute('aria-expanded', 'false');
    }

    function toggleMobileMenu() {
      const open = !document.body.classList.contains('sf-listening-mobile-menu-open');
      document.body.classList.toggle('sf-listening-mobile-menu-open', open);
      const toggle = document.querySelector('[data-listening-mobile-menu-toggle]');
      if (toggle) toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
    }

    function ensureUserMenu(editorTop) {
      const toolbar = editorTop?.querySelector('.sf-listening-workspace-toolbar');
      const aiButton = toolbar?.querySelector('[data-listening-ai-toggle]');
      const userId = Math.max(0, Number(listeningConfig.userId || 0));
      if (!toolbar || !aiButton || !userId) return false;
      if (toolbar.querySelector('[data-listening-user-menu]')) return true;

      const menu = document.createElement('div');
      menu.className = 'sf-listening-user-menu';
      menu.dataset.listeningUserMenu = '1';
      menu.innerHTML = `<button type="button" class="sf-listening-user-menu-button" data-listening-user-menu-toggle aria-expanded="false" aria-controls="sfListeningUserMenuDropdown" aria-label="Open user menu"><span class="sf-listening-user-avatar"><span data-listening-user-avatar-fallback aria-hidden="true"><svg viewBox="0 0 24 24"><circle cx="12" cy="8" r="3.5"></circle><path d="M5.5 19c.8-3.7 3-5.5 6.5-5.5s5.7 1.8 6.5 5.5"></path></svg></span><img data-listening-user-avatar hidden alt=""></span></button><div class="sf-listening-user-menu-dropdown" id="sfListeningUserMenuDropdown" data-listening-user-menu-dropdown hidden></div>`;
      const dropdown = menu.querySelector('[data-listening-user-menu-dropdown]');
      const menuLinks = Array.isArray(listeningConfig.userMenuLinks) ? listeningConfig.userMenuLinks : [];
      menuLinks.forEach(item => {
        if (!item || !item.url || !item.label || !dropdown) return;
        const link = document.createElement('a');
        link.href = String(item.url);
        if (item.danger) link.classList.add('logout');
        const label = document.createElement('span');
        label.textContent = String(item.label);
        const arrow = document.createElement('span');
        arrow.textContent = '↗';
        link.append(label, arrow);
        dropdown.appendChild(link);
      });
      aiButton.insertAdjacentElement('afterend', menu);

      const button = menu.querySelector('[data-listening-user-menu-toggle]');
      const image = menu.querySelector('[data-listening-user-avatar]');
      const fallback = menu.querySelector('[data-listening-user-avatar-fallback]');
      const close = () => {
        if (!button || !dropdown) return;
        dropdown.hidden = true;
        button.setAttribute('aria-expanded', 'false');
      };

      if (image) {
        image.addEventListener('load', () => {
          image.hidden = false;
          if (fallback) fallback.hidden = true;
        }, {once:true});
        image.addEventListener('error', () => {
          image.hidden = true;
          if (fallback) fallback.hidden = false;
        }, {once:true});
        image.src = `avatar.php?user=${encodeURIComponent(String(userId))}`;
      }

      button?.addEventListener('click', event => {
        event.stopPropagation();
        const opening = Boolean(dropdown?.hidden);
        if (dropdown) dropdown.hidden = !opening;
        button.setAttribute('aria-expanded', opening ? 'true' : 'false');
      });
      dropdown?.addEventListener('click', event => event.stopPropagation());
      document.addEventListener('click', event => {
        if (!menu.contains(event.target)) close();
      });
      document.addEventListener('keydown', event => {
        if (event.key === 'Escape') close();
      });
      return true;
    }

    function composeLayout() {
      const workspace = document.querySelector('.sf-transcript-workspace');
      const sidebar = workspace?.querySelector('.sf-listening-workspace-sidebar');
      const sideHead = sidebar?.querySelector('.sf-listening-workspace-side-head');
      const editorTop = workspace?.querySelector('.sf-listening-workspace-editor-top');
      const player = workspace?.querySelector('.sf-listening-workspace-listening-player');
      const capture = player?.querySelector('.sf-listening-workspace-capture-actions');
      const liveStatus = player?.querySelector('.sf-listening-workspace-live-status');
      const save = workspace?.querySelector('[data-listening-workspace-save]');
      const start = workspace?.querySelector('[data-listening-start]');
      const exit = workspace?.querySelector('[data-listening-workspace-exit]');
      if (!workspace || !sidebar || !sideHead || !editorTop || !player || !capture || !save || !start) return false;

      if (!sidebar.id) sidebar.id = 'sfListeningMobileLibrary';

      const newRecording = sideHead.querySelector('[data-listening-workspace-new]');
      if (exit && exit.parentElement !== sideHead) sideHead.insertBefore(exit, newRecording || null);
      ensureUserMenu(editorTop);

      capture.classList.add('sf-listening-footer-left');
      const pause = workspace.querySelector('[data-listening-workspace-pause]');
      const stop = workspace.querySelector('[data-listening-stop]');
      const record = workspace.querySelector('[data-listening-record]');
      const marker = workspace.querySelector('[data-listening-marker]');
      const note = workspace.querySelector('[data-listening-note]');
      [pause, stop, record, marker, note, liveStatus].forEach(node => {
        if (node) capture.appendChild(node);
      });

      let right = player.querySelector('.sf-listening-footer-right');
      if (!right) {
        right = document.createElement('div');
        right.className = 'sf-listening-footer-right';
        player.appendChild(right);
      }
      if (save.parentElement !== right) right.appendChild(save);
      if (start.parentElement !== right) right.appendChild(start);

      let mobileToggle = editorTop.querySelector('[data-listening-mobile-menu-toggle]');
      if (!mobileToggle) {
        mobileToggle = document.createElement('button');
        mobileToggle.type = 'button';
        mobileToggle.className = 'sf-listening-mobile-menu-toggle';
        mobileToggle.dataset.listeningMobileMenuToggle = '1';
        mobileToggle.setAttribute('aria-label', 'Open transcript library');
        mobileToggle.setAttribute('aria-controls', sidebar.id);
        mobileToggle.setAttribute('aria-expanded', 'false');
        mobileToggle.textContent = '☰';
        editorTop.prepend(mobileToggle);
      }

      let shade = document.querySelector('[data-listening-mobile-menu-shade]');
      if (!shade) {
        shade = document.createElement('button');
        shade.type = 'button';
        shade.className = 'sf-listening-mobile-menu-shade';
        shade.dataset.listeningMobileMenuShade = '1';
        shade.setAttribute('aria-label', 'Close transcript library');
        document.body.appendChild(shade);
      }

      if (!wired) {
        wired = true;
        document.addEventListener('click', event => {
          if (event.target.closest?.('[data-listening-mobile-menu-toggle]')) {
            event.preventDefault();
            toggleMobileMenu();
            return;
          }
          if (event.target.closest?.('[data-listening-mobile-menu-shade]')) {
            event.preventDefault();
            closeMobileMenu();
            return;
          }
          if (!mobileQuery.matches) return;
          if (event.target.closest?.('[data-listening-workspace-file-open],[data-listening-workspace-folder],[data-listening-workspace-new],[data-listening-workspace-create]')) {
            closeMobileMenu();
          }
        });
        document.addEventListener('keydown', event => {
          if (event.key === 'Escape') closeMobileMenu();
        });
        mobileQuery.addEventListener?.('change', event => {
          if (!event.matches) closeMobileMenu();
        });
      }
      return true;
    }

    function bootLayout() {
      if (composeLayout()) return;
      let attempts = 0;
      const timer = setInterval(() => {
        attempts += 1;
        if (composeLayout() || attempts >= 40) clearInterval(timer);
      }, 100);
    }

    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', bootLayout, {once:true});
    else bootLayout();
  })();
  </script>
</body>
</html>