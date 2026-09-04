(() => {
  'use strict';

  async function bootAdminTeamChat() {
    if (document.getElementById('sfOnlineRail')) return;

    const chatCfg = window.STONEFELLOW_CHAT || null;
    const studioCfg = window.STONEFELLOW_STUDIO_AGENT || null;
    const source = studioCfg || chatCfg;
    if (!source?.csrf || !Number(source.userId || 0)) return;

    let sourceEndpoint = '';
    let pageKey = 'workspace';
    let contextLabel = '';

    if (studioCfg?.endpoint) {
      sourceEndpoint = String(studioCfg.endpoint);
      pageKey = 'stem_studio';
      contextLabel = `Stem Studio · ${String(studioCfg.trackTitle || 'Track')}`;
    } else if (chatCfg?.endpoint) {
      sourceEndpoint = String(chatCfg.endpoint);
      pageKey = 'agent_chat';
      contextLabel = 'Agent Chat';
    }

    if (!sourceEndpoint) return;

    let endpoint = '';
    try {
      endpoint = new URL(
        'team-chat-v103.php',
        new URL(sourceEndpoint, window.location.href)
      ).toString();
    } catch (error) {
      return;
    }

    const probeUrl = new URL(endpoint);
    probeUrl.searchParams.set('action', 'poll');
    probeUrl.searchParams.set('since', '0');
    probeUrl.searchParams.set('page', pageKey);
    if (contextLabel) probeUrl.searchParams.set('context', contextLabel);

    try {
      const response = await fetch(probeUrl.toString(), {
        credentials:'same-origin',
        cache:'no-store',
        headers:{ Accept:'application/json' }
      });
      const data = await response.json().catch(() => ({ ok:false }));
      if (!response.ok || !data.ok) return;
    } catch (error) {
      return;
    }

    if (document.getElementById('sfOnlineRail')) return;

    if (!document.querySelector('link[data-team-chat-v107-style]')) {
      const style = document.createElement('link');
      style.rel = 'stylesheet';
      style.href = new URL('team-chat-v107.css?v=107', window.location.href).toString();
      style.dataset.teamChatV107Style = '1';
      document.head.appendChild(style);
    }

    const rail = document.createElement('aside');
    rail.className = 'sf-online-rail';
    rail.id = 'sfOnlineRail';
    rail.setAttribute('aria-label', 'Stonefellow team chat');
    rail.innerHTML = `
      <div class="sf-online-users" id="sfOnlineUsers">
        <div class="sf-online-empty">Checking…</div>
      </div>`;

    const windows = document.createElement('div');
    windows.className = 'sf-team-chat-windows';
    windows.id = 'sfTeamChatWindows';
    windows.setAttribute('aria-live', 'polite');
    windows.setAttribute('aria-label', 'Direct message chats');

    document.body.append(rail, windows);

    window.STONEFELLOW_TEAM_CHAT = {
      endpoint,
      csrf:String(source.csrf),
      userId:Number(source.userId),
      role:'manager',
      pageKey,
      contextLabel,
      pollMs:3000
    };

    const script = document.createElement('script');
    script.src = new URL('team-chat-v107.js?v=107', window.location.href).toString();
    script.async = false;
    document.body.appendChild(script);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', bootAdminTeamChat, { once:true });
  } else {
    void bootAdminTeamChat();
  }
})();
