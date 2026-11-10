(() => {
  'use strict';
  const config = window.VP3_CONNECTED_GELATO_V1;
  const host = document.getElementById('chatToolsContent');
  if (!config?.endpoint || !host || host.querySelector('.vp3-connected-sites')) return;

  function element(tag, text) {
    const node = document.createElement(tag);
    if (text) node.textContent = text;
    return node;
  }

  const panel = element('details');
  panel.className = 'vp3-connected-sites';
  const status = element('p');
  status.setAttribute('role', 'status');
  const sites = element('div');
  const form = element('form');
  function field(text, type, name) {
    const label = element('label', text);
    const input = element('input');
    input.type = type;
    input.name = name;
    input.autocomplete = 'off';
    input.required = true;
    label.append(input);
    form.append(label);
    return input;
  }
  const origin = field('Gelato site HTTPS address', 'url', 'origin');
  const code = field('One-time pairing code', 'password', 'code');
  const submit = element('button', 'Connect Gelato');
  submit.type = 'submit';
  form.append(submit);
  const refresh = element('button', 'Refresh connections');
  refresh.type = 'button';
  panel.append(element('summary', 'Connected Sites'),
    element('p', 'Create a one-time pairing code in your Gelato installation under VP3 Connections.'),
    sites, form, refresh, status);
  host.append(panel);

  const labels = new Map([
    ['menu', 'Menu'], ['recipe', 'Recipes'], ['orders.read', 'Orders'],
    ['tickets.open.read', 'Open tickets'], ['sales.daily.read', 'Daily sales'],
  ]);
  let busy = false;
  let available = false;

  async function request(body) {
    const options = { credentials: 'same-origin', cache: 'no-store' };
    if (body) {
      options.method = 'POST';
      options.headers = { 'Content-Type': 'application/json' };
      options.body = JSON.stringify({ ...body, csrf_token: config.csrf });
    }
    const response = await fetch(config.endpoint, options);
    const data = await response.json().catch(() => null);
    if (!response.ok || !data?.ok) {
      throw new Error(String(data?.error || data?.message || 'The connection request failed.').slice(0, 280));
    }
    return data;
  }

  function draftQuestion(site) {
    const scopes = site.scopes;
    if (scopes.includes('sales.daily.read') && scopes.includes('tickets.open.read')) {
      return 'how we did today and what’s still open';
    }
    if (scopes.includes('tickets.open.read')) return 'which tickets are still open';
    if (scopes.includes('orders.read')) return 'which orders are active';
    if (scopes.includes('sales.daily.read')) return 'how we did today';
    if (scopes.includes('menu')) return 'search menu for pizza';
    return 'find recipe for dough';
  }

  function knowledgeControl(site, canCreate) {
    const folder = site.knowledgeFolder;
    if (!folder) {
      if (!canCreate) return null;
      const create = element('button', 'Create Knowledge folder');
      create.type = 'button';
      create.addEventListener('click', () => perform(async () => {
        await request({ action: 'createKnowledgeFolder', connectionId: site.id });
        await load();
        status.textContent = 'Knowledge folder ready.';
      }));
      return create;
    }
    if (!Number.isSafeInteger(folder.id) || folder.id < 1 || !config.knowledgeUrl) return null;
    let url;
    try {
      url = new URL(config.knowledgeUrl, window.location.href);
    } catch {
      return null;
    }
    if (url.origin !== window.location.origin || url.username || url.password) return null;
    url.searchParams.set('folder', String(folder.id));
    const link = element('a', 'Open Knowledge folder');
    link.href = url.href;
    link.setAttribute('aria-label', 'Open Knowledge folder for ' + folder.name);
    return link;
  }

  function render(connections, canCreateKnowledgeFolder) {
    sites.replaceChildren();
    if (!connections.length) sites.append(element('p', 'No sites connected yet.'));
    else sites.append(element('p', 'In Agent Chat, say “Ask Gelato” or type “/ask gelato” followed by your question.'));
    for (const site of connections) {
      const row = element('div');
      row.dataset.siteRow = '';
      const permissions = site.scopes.map(scope => labels.get(scope)).filter(Boolean);
      const locations = site.locations.map(location => location.name);
      const target = connections.length > 1 ? 'Gelato via ' + new URL(site.origin).hostname : 'Gelato';
      const ask = element('button', 'Ask Gelato');
      ask.type = 'button';
      ask.addEventListener('click', () => {
        const input = document.getElementById('chatInput');
        if (!input || input.disabled || input.value.trim()) {
          status.textContent = 'Finish your current chat draft before starting another.';
          return;
        }
        input.value = 'Ask ' + target + ' ' + draftQuestion(site);
        input.dispatchEvent(new Event('input', { bubbles: true }));
        const tools = document.getElementById('chatToolsButton');
        if (tools?.getAttribute('aria-expanded') === 'true') tools.click();
        input.focus();
      });
      const disconnect = element('button', 'Disconnect');
      disconnect.type = 'button';
      disconnect.addEventListener('click', () => {
        if (!window.confirm('Disconnect ' + site.agentName + ' from this VP3 account?')) return;
        perform(async () => {
          const result = await request({ action: 'disconnect', connectionId: site.id });
          await load();
          status.textContent = result.revokedRemotely ? 'Site disconnected.' :
            'Disconnected from VP3. Open VP3 Connections on the site to revoke its grant.';
        });
      });
      row.append(element('strong', site.agentName), element('p', site.origin),
        element('p', [...permissions, ...locations].join(' · ')), ask, disconnect);
      const knowledge = knowledgeControl(site, canCreateKnowledgeFolder);
      if (knowledge) row.append(knowledge);
      sites.append(row);
    }
  }

  async function load() {
    const data = await request();
    available = data.available === true;
    render(data.connections, data.canCreateKnowledgeFolder === true);
    if (!available) status.textContent = 'Connected Sites requires the VP3 Cloud database upgrade.';
  }

  async function perform(work) {
    if (busy) return;
    busy = true;
    status.textContent = '';
    for (const control of panel.querySelectorAll('button, input')) control.disabled = true;
    try {
      await work();
    } catch (error) {
      status.textContent = error.message;
    } finally {
      busy = false;
      for (const control of panel.querySelectorAll('button, input')) control.disabled = false;
      submit.disabled = !available;
    }
  }

  submit.disabled = true;
  panel.addEventListener('toggle', () => { if (panel.open) perform(load); });
  refresh.addEventListener('click', () => perform(load));
  form.addEventListener('submit', event => {
    event.preventDefault();
    if (!available || busy) return;
    const values = { action: 'connect', origin: origin.value.trim(), code: code.value.trim() };
    code.value = '';
    perform(async () => {
      await request(values);
      origin.value = '';
      await load();
      status.textContent = 'Site connected.';
    });
  });
})();
