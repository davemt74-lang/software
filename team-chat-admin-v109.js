(() => {
  'use strict';

  const BUILD = 'team-chat-bootstrap-v236-20260905';
  const ASSET_BUILD = 'team-chat-light-v117-20260905';
  const proof = window.STONEFELLOW_TEAM_CHAT_RUNTIME = {
    build: BUILD,
    assetBuild: ASSET_BUILD,
    bootstrapLoaded: true,
    railCreated: false,
    runtimeLoaded: false,
    configSource: '',
    endpoint: '',
    assetBase: '',
    adminDisabled: false
  };

  function removeExistingRail() {
    document.getElementById('sfOnlineRail')?.remove();
    document.getElementById('sfTeamChatWindows')?.remove();
    document.getElementById('sfOnlineRailV109')?.remove();
    document.getElementById('sfTeamChatWindowsV109')?.remove();
    document.body?.classList.remove('sf-team-rail-active');
  }

  function boot() {
    /* Agent Chat already renders the canonical Team Chat widget directly.
       Reusing it avoids a second poller, duplicate incoming-message sounds and
       losing Chat Settings state when this compatibility bootstrap runs later. */
    const existingRail = document.getElementById('sfOnlineRailV109');
    const existingWindows = document.getElementById('sfTeamChatWindowsV109');
    if (window.STONEFELLOW_CHAT && window.STONEFELLOW_TEAM_CHAT && existingRail && existingWindows) {
      proof.configSource = 'existing-chat-widget';
      proof.railCreated = true;
      proof.runtimeLoaded = true;
      proof.endpoint = String(window.STONEFELLOW_TEAM_CHAT.endpoint || '');
      return;
    }

    removeExistingRail();

    /* Admin and Artist Admin are management workspaces, not Team Chat surfaces.
       Keep the shared bootstrap file for compatibility, but never create the
       right-side rail, chat windows, polling runtime, or active-layout class
       anywhere below /admin/. Agent Chat outside /admin/ retains Team Chat. */
    if (/\/admin(?:\/|$)/i.test(window.location.pathname)) {
      proof.configSource = 'disabled-admin';
      proof.adminDisabled = true;
      return;
    }

    const explicit = window.STONEFELLOW_TEAM_CHAT_ADMIN || null;
    const chatCfg = window.STONEFELLOW_CHAT || null;
    const studioCfg = window.STONEFELLOW_STUDIO_AGENT || null;
    const source = explicit || studioCfg || chatCfg;
    proof.configSource = explicit ? 'explicit-admin' : (studioCfg ? 'studio' : (chatCfg ? 'chat' : 'missing'));
    if (!source?.csrf || !Number(source.userId || 0)) return;

    const sourceEndpoint = String(explicit?.endpoint || source.endpoint || '');
    if (!sourceEndpoint) return;

    let endpoint = '';
    let assetBase = '';
    try {
      const endpointUrl = new URL(sourceEndpoint, window.location.href);
      endpoint = new URL('team-chat-v109.php', endpointUrl).toString();
      assetBase = new URL('../', endpointUrl).toString();
    } catch (error) {
      return;
    }

    const pageKey = String(explicit?.pageKey || (studioCfg ? 'stem_studio' : (chatCfg ? 'agent_chat' : 'workspace')));
    const contextLabel = String(
      explicit?.contextLabel ||
      (studioCfg ? `Stem Studio · ${String(studioCfg.trackTitle || 'Track')}` : (chatCfg ? 'Agent Chat' : ''))
    );
    proof.endpoint = endpoint;
    proof.assetBase = assetBase;

    document.querySelectorAll('link[data-team-chat-runtime-style]').forEach(node => node.remove());
    const style = document.createElement('link');
    style.rel = 'stylesheet';
    style.href = new URL('team-chat-v109.css?v=' + ASSET_BUILD, assetBase).toString();
    style.dataset.teamChatRuntimeStyle = ASSET_BUILD;
    document.head.appendChild(style);

    const rail = document.createElement('aside');
    rail.className = 'sf-online-rail-v109';
    rail.id = 'sfOnlineRailV109';
    rail.setAttribute('aria-label', 'Stonefellow team chat');
    rail.innerHTML = '<div class="sf-online-users-v109" id="sfOnlineUsersV109"></div>';

    const windows = document.createElement('div');
    windows.className = 'sf-team-chat-windows-v109';
    windows.id = 'sfTeamChatWindowsV109';
    windows.setAttribute('aria-live', 'polite');
    windows.setAttribute('aria-label', 'Direct message chats');

    document.body.append(rail, windows);
    document.body.classList.add('sf-team-rail-active');
    proof.railCreated = true;

    window.STONEFELLOW_TEAM_CHAT = {
      endpoint,
      csrf: String(source.csrf),
      userId: Number(source.userId),
      role: String(source.role || 'manager'),
      pageKey,
      contextLabel,
      pollMs: 3000
    };

    document.querySelectorAll('script[data-team-chat-runtime-script]').forEach(node => node.remove());
    const script = document.createElement('script');
    script.src = new URL('team-chat-v109.js?v=' + ASSET_BUILD, assetBase).toString();
    script.async = false;
    script.dataset.teamChatRuntimeScript = ASSET_BUILD;
    script.addEventListener('load', () => { proof.runtimeLoaded = true; }, { once:true });
    document.body.appendChild(script);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot, { once:true });
  } else {
    boot();
  }
})();