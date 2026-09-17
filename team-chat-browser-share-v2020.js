(() => {
  'use strict';

  const cfg = window.STONEFELLOW_TEAM_CHAT;
  if (!cfg?.endpoint || !cfg?.csrf) return;

  let componentPromise = null;
  const apiUrl = new URL('browser-share-chat-feed-v2020.php', new URL(cfg.endpoint, window.location.href));
  const assetUrl = new URL('../browser-share-card-v2020.js?v=2020', new URL(cfg.endpoint, window.location.href));
  const chatUrl = new URL('../chat.php', new URL(cfg.endpoint, window.location.href)).toString();

  function component() {
    if (window.VP3BrowserShareCardV2020?.create) return Promise.resolve(window.VP3BrowserShareCardV2020);
    if (componentPromise) return componentPromise;
    componentPromise = new Promise(resolve => {
      const existing = document.querySelector('script[data-browser-share-card-v2020]');
      if (existing) {
        existing.addEventListener('load', () => resolve(window.VP3BrowserShareCardV2020 || null), { once:true });
        existing.addEventListener('error', () => resolve(null), { once:true });
        return;
      }
      const script = document.createElement('script');
      script.src = assetUrl.toString();
      script.defer = true;
      script.dataset.browserShareCardV2020 = '1';
      script.addEventListener('load', () => resolve(window.VP3BrowserShareCardV2020 || null), { once:true });
      script.addEventListener('error', () => resolve(null), { once:true });
      document.head.appendChild(script);
    });
    return componentPromise;
  }

  async function getShare(params) {
    const url = new URL(apiUrl.toString());
    Object.entries(params).forEach(([key, value]) => url.searchParams.set(key, String(value)));
    const response = await fetch(url, { credentials:'same-origin', cache:'no-store' });
    if (response.status === 404) return null;
    const data = await response.json().catch(() => ({ ok:false }));
    if (!response.ok || !data.ok) throw new Error(data.message || data.error || 'Browser Share unavailable');
    return data.browser_share || null;
  }

  async function attachTeamMessage(row) {
    if (!(row instanceof Element) || row.dataset.browserShareCheckedV2020 === '1') return;
    const messageId = Number(row.dataset.teamMessageId || 0);
    if (messageId < 1) return;
    row.dataset.browserShareCheckedV2020 = '1';
    try {
      const share = await getShare({ action:'message', message_id:messageId });
      if (!share?.id || !row.isConnected) return;
      const lib = await component();
      if (!lib?.create || !row.isConnected || row.querySelector('[data-browser-share-id]')) return;
      const chat = row.closest('.sf-team-chat-v109');
      const card = lib.create(share, {
        api:apiUrl.toString(),
        csrf:String(cfg.csrf),
        chatUrl,
        onReply:value => {
          const input = chat?.querySelector('.sf-team-chat-form-v109 textarea');
          if (!input) return;
          const label = value?.source?.title || value?.source?.domain || 'Browser Share';
          input.value = input.value || `Re: ${label}\n`;
          input.focus();
        }
      });
      if (!card) return;
      const bubble = row.querySelector(':scope > div');
      if (bubble) bubble.insertAdjacentElement('afterend', card);
      else row.prepend(card);
    } catch (error) {
      delete row.dataset.browserShareCheckedV2020;
    }
  }

  function scanTeamMessages(root = document) {
    if (root instanceof Element && root.matches('.sf-team-message-v109[data-team-message-id]')) void attachTeamMessage(root);
    root.querySelectorAll?.('.sf-team-message-v109[data-team-message-id]').forEach(row => void attachTeamMessage(row));
  }

  async function mountAgentContext() {
    if (!window.STONEFELLOW_CHAT) return;
    const id = String(new URLSearchParams(window.location.search).get('browser_share_id') || '').trim();
    if (!/^[A-Za-z0-9_-]{8,96}$/.test(id)) return;
    const thread = document.getElementById('chatThread');
    if (!thread || document.querySelector('[data-agent-browser-share-v2020]')) return;
    try {
      const share = await getShare({ action:'get', browser_share_id:id });
      const lib = await component();
      if (!share?.id || !lib?.create) return;
      const host = document.createElement('section');
      host.dataset.agentBrowserShareV2020 = '1';
      host.style.cssText = 'max-width:790px;margin:0 auto 12px;padding:0 18px;box-sizing:border-box;width:100%';
      const label = document.createElement('div');
      label.textContent = 'Browser Share · active Agent context';
      label.style.cssText = 'font:700 11px/1.3 ui-sans-serif,system-ui;margin:0 0 6px;opacity:.65;text-transform:uppercase;letter-spacing:.04em';
      const card = lib.create(share, {
        api:apiUrl.toString(),
        csrf:String(cfg.csrf),
        chatUrl,
        onAsk:() => document.getElementById('chatInput')?.focus()
      });
      if (!card) return;
      host.append(label, card);
      const composer = document.getElementById('chatComposerShell');
      if (composer?.parentElement === thread) thread.insertBefore(host, composer);
      else thread.prepend(host);
    } catch (error) {
      const clean = new URL(window.location.href);
      clean.searchParams.delete('browser_share_id');
      window.history.replaceState({}, '', clean.toString());
    }
  }

  const observer = new MutationObserver(records => {
    for (const record of records) {
      for (const node of record.addedNodes) {
        if (node instanceof Element) scanTeamMessages(node);
      }
    }
  });

  scanTeamMessages();
  observer.observe(document.documentElement, { childList:true, subtree:true });
  void mountAgentContext();
  window.addEventListener('pagehide', () => observer.disconnect(), { once:true });
})();