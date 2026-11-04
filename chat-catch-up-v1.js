(() => {
  'use strict';

  const script = document.currentScript;
  const thread = document.getElementById('chatThread');
  if (!script?.dataset.endpoint || !thread || thread.querySelector('[data-chat-catch-up]')) return;

  const localUrl = value => {
    if (typeof value !== 'string' || !value.trim()) return null;
    try {
      const url = new URL(value, location.href);
      return url.origin === location.origin && /^https?:$/.test(url.protocol) && !url.username && !url.password ? url : null;
    } catch {
      return null;
    }
  };
  const endpoint = localUrl(script.dataset.endpoint);
  if (!endpoint) return;
  const agentId = Number(script.dataset.agentId || 0);
  if (Number.isSafeInteger(agentId) && agentId > 0) endpoint.searchParams.set('agent_id', String(agentId));
  const headers = {Accept: 'application/json'};
  const pageBinding = window.VP3_COGNITIVE_PRESENTATION_V510?.workingAttentionBinding;
  if (typeof pageBinding === 'string' && pageBinding) headers['X-VP3-Working-Attention'] = pageBinding;

  const card = document.createElement('details');
  card.className = 'vp3-chat-catch-up';
  card.dataset.chatCatchUp = '';
  card.innerHTML = '<summary>Catch me up</summary><div class="vp3-chat-catch-up-body">'
    + '<p role="status"></p><ol aria-label="Priority updates" role="list" hidden></ol>'
    + '<button type="button">Refresh</button></div>';
  const status = card.querySelector('[role="status"]');
  const items = card.querySelector('ol');
  const refresh = card.querySelector('button');
  let request = null;

  function clearItems() {
    items.replaceChildren();
    items.hidden = true;
  }

  function reset() {
    request?.abort();
    request = null;
    refresh.disabled = false;
    status.textContent = '';
    clearItems();
  }

  function render(brief) {
    const requested = brief.items.slice(0, 3);
    for (const item of requested) {
      const url = localUrl(item?.url);
      if (!url || typeof item?.title !== 'string' || !item.title.trim()
        || typeof item.reason !== 'string' || !item.reason.trim()) continue;
      const row = document.createElement('li');
      const title = document.createElement('strong');
      title.textContent = item.title;
      const reason = document.createElement('p');
      reason.textContent = item.reason;
      const link = document.createElement('a');
      link.href = url.href;
      link.textContent = typeof item.source_label === 'string' && item.source_label.trim()
        ? item.source_label
        : 'Open source';
      row.append(title, reason, link);
      items.append(row);
    }

    const coverage = Object.values(brief.coverage || {});
    const complete = coverage.length > 0 && coverage.every(value => value === 'ready');
    items.hidden = !items.children.length;
    if (items.children.length < requested.length) {
      status.textContent = 'Some priority updates couldn’t be displayed.';
    } else if (!items.hidden) {
      status.textContent = complete ? '' : 'Some sources couldn’t be checked.';
    } else if (complete) {
      status.textContent = 'Nothing needs your attention right now.';
    } else {
      status.textContent = coverage.some(value => value === 'ready' || value === 'limited')
        ? 'No priority updates from the sources checked. Some sources couldn’t be checked.'
        : 'Priority sources are unavailable right now.';
    }
  }

  async function load() {
    if (!card.open || request) return;
    const controller = new AbortController();
    request = controller;
    refresh.disabled = true;
    clearItems();
    status.textContent = 'Checking for priority updates…';
    const timeout = window.setTimeout(() => controller.abort(), 10000);
    try {
      const response = await fetch(endpoint.href, {
        credentials: 'same-origin',
        cache: 'no-store',
        headers,
        signal: controller.signal
      });
      if (!response.ok) throw new Error('Brief unavailable');
      const data = await response.json();
      if (data?.ok !== true || !Array.isArray(data.brief?.items)) throw new Error('Invalid brief');
      if (request === controller && card.open) render(data.brief);
    } catch {
      if (request === controller && card.open) status.textContent = 'Couldn’t check for updates. Try refreshing.';
    } finally {
      window.clearTimeout(timeout);
      if (request === controller) {
        request = null;
        refresh.disabled = false;
      }
    }
  }

  function close() {
    card.open = false;
    reset();
  }

  card.addEventListener('toggle', () => {
    if (card.open) void load();
    else reset();
  });
  refresh.addEventListener('click', () => void load());
  window.addEventListener('vp3:chat-canvas-view-changed', () => {
    if (thread.dataset.view && thread.dataset.view !== 'chat') close();
  });
  window.addEventListener('pagehide', close);
  thread.prepend(card);
})();
