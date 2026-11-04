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
  const origin = field('Food Monster site HTTPS address', 'url', 'origin');
  const code = field('One-time pairing code', 'password', 'code');
  const submit = element('button', 'Connect Food Monster');
  submit.type = 'submit';
  form.append(submit);
  const refresh = element('button', 'Refresh connections');
  refresh.type = 'button';
  panel.append(element('summary', 'Connected Sites'),
    element('p', 'Create a one-time pairing code in your Food Monster installation under VP3 Connections.'),
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

  function render(connections) {
    sites.replaceChildren();
    if (!connections.length) sites.append(element('p', 'No sites connected yet.'));
    for (const site of connections) {
      const row = element('div');
      row.dataset.siteRow = '';
      const permissions = site.scopes.map(scope => labels.get(scope)).filter(Boolean);
      const locations = site.locations.map(location => location.name);
      const ambiguous = connections.some(other => other.id !== site.id &&
        other.agentName.toLowerCase() === site.agentName.toLowerCase());
      const target = ambiguous ? site.agentName + ' via ' + new URL(site.origin).hostname : site.agentName;
      const ask = element('button', 'Ask ' + site.agentName);
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
      sites.append(row);
    }
  }

  async function load() {
    const data = await request();
    available = data.available === true;
    render(data.connections);
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
