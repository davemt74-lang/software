(() => {
  'use strict';

  const proof = window.STONEFELLOW_TEAM_CHAT_RUNTIME = {
    build:'force-cache-bust-20260825-2',
    bootstrapLoaded:true,
    railCreated:false,
    runtimeLoaded:false,
    configSource:'',
    endpoint:''
  };

  function boot() {
    if (document.getElementById('sfOnlineRail')) {
      proof.railCreated = true;
      return;
    }

    const explicit = window.STONEFELLOW_TEAM_CHAT_ADMIN || null;
    const chatCfg = window.STONEFELLOW_CHAT || null;
    const studioCfg = window.STONEFELLOW_STUDIO_AGENT || null;
    const source = explicit || studioCfg || chatCfg;
    proof.configSource = explicit ? 'explicit-admin' : (studioCfg ? 'studio' : (chatCfg ? 'chat' : 'missing'));
    if (!source?.csrf || !Number(source.userId || 0)) return;

    let endpoint = String(explicit?.endpoint || '');
    let pageKey = String(explicit?.pageKey || 'workspace');
    let contextLabel = String(explicit?.contextLabel || '');

    if (!endpoint) {
      const sourceEndpoint = String(source.endpoint || '');
      if (!sourceEndpoint) return;
      try {
        endpoint = new URL('team-chat-v103.php', new URL(sourceEndpoint, window.location.href)).toString();
      } catch (error) {
        return;
      }
      if (studioCfg) {
        pageKey = 'stem_studio';
        contextLabel = `Stem Studio · ${String(studioCfg.trackTitle || 'Track')}`;
      } else if (chatCfg) {
        pageKey = 'agent_chat';
        contextLabel = 'Agent Chat';
      }
    }
    proof.endpoint = endpoint;

    if (!document.querySelector('link[data-team-chat-v108-style]')) {
      const style = document.createElement('link');
      style.rel = 'stylesheet';
      style.href = new URL('team-chat-v108.css?v=force-cache-bust-20260825-2', window.location.href).toString();
      style.dataset.teamChatV108Style = '1';
      document.head.appendChild(style);
    }

    const rail = document.createElement('aside');
    rail.className = 'sf-online-rail';
    rail.id = 'sfOnlineRail';
    rail.setAttribute('aria-label', 'Stonefellow team chat');
    rail.innerHTML = '<div class="sf-online-users" id="sfOnlineUsers"></div>';

    const windows = document.createElement('div');
    windows.className = 'sf-team-chat-windows';
    windows.id = 'sfTeamChatWindows';
    windows.setAttribute('aria-live', 'polite');
    windows.setAttribute('aria-label', 'Direct message chats');

    document.body.append(rail, windows);
    proof.railCreated = true;

    window.STONEFELLOW_TEAM_CHAT = {
      endpoint,
      csrf:String(source.csrf),
      userId:Number(source.userId),
      role:'manager',
      pageKey,
      contextLabel,
      pollMs:3000
    };

    if (!document.querySelector('script[data-team-chat-v108-runtime]')) {
      const script = document.createElement('script');
      script.src = new URL('team-chat-v108.js?v=force-cache-bust-20260825-2', window.location.href).toString();
      script.async = false;
      script.dataset.teamChatV108Runtime = '1';
      script.addEventListener('load', () => { proof.runtimeLoaded = true; }, { once:true });
      document.body.appendChild(script);
    }
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot, { once:true });
  } else {
    boot();
  }
})();
