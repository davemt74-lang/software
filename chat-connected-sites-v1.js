(() => {
  'use strict';
  const config = window.VP3_CONNECTED_GELATO_V1;
  const host = document.getElementById('chatToolsContent');
  if (!config?.endpoint || !host || host.querySelector('.vp3-connected-sites')) return;
  const endpoint = new URL(config.endpoint, location.href);
  if (endpoint.origin !== location.origin || endpoint.username || endpoint.password) return;
  const platforms = { foodmonster:'FoodMonster', stoneward:'Stonefellow' };
  const specialist = 'connected-sites';
  let participantActor = 0;
  let disposed = false;
  const ownerAllowed = () => !disposed && !participantActor && !window.VP3ParticipantContinuity?.selectedContext?.()
    && (!config.ownerUserId || Number(window.STONEFELLOW_CHAT?.userId) === config.ownerUserId);

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
  const platformLabel = element('label', 'Platform');
  const provider = element('select');
  provider.append(new Option(platforms.foodmonster, 'foodmonster'), new Option('Stonefellow (Stoneward)', 'stoneward'));
  platformLabel.append(provider);
  const pairingHelp = element('p');
  const pairingLink = element('a', 'Open pairing on the artist site');
  pairingLink.target = '_blank';
  pairingLink.rel = 'noopener noreferrer';
  const pairingAvailability = element('p');
  pairingAvailability.hidden = true;
  form.append(platformLabel, pairingHelp);
  function field(text, type, name) {
    const label = element('label');
    const caption = element('span', text);
    const input = element('input');
    input.type = type;
    input.name = name;
    input.autocomplete = 'off';
    input.required = true;
    label.append(caption, input);
    form.append(label);
    return {input, caption};
  }
  const {input:origin, caption:originLabel} = field('FoodMonster site HTTPS address', 'url', 'origin');
  const check = element('button', 'Test connection');
  check.type = 'button';
  const checkStatus = element('p');
  checkStatus.setAttribute('role', 'status');
  checkStatus.dataset.stonewardConnectionStatus = '';
  const diagnostic = element('div');
  diagnostic.dataset.stonewardConnectionDiagnostic = '';
  diagnostic.hidden = true;
  form.append(check, checkStatus, diagnostic, pairingLink, pairingAvailability);
  const {input:code} = field('One-time pairing code', 'password', 'code');
  const submit = element('button', 'Connect FoodMonster');
  submit.type = 'submit';
  form.append(submit);
  const refresh = element('button', 'Refresh connections');
  refresh.type = 'button';
  panel.append(element('summary', 'Connected Sites'), sites, form, refresh, status);
  host.append(panel);

  const labels = new Map([
    ['menu', 'Menu'], ['recipe', 'Recipes'], ['orders.read', 'Orders'],
    ['tickets.open.read', 'Open tickets'], ['tickets.detail.read', 'Ticket details'], ['sales.daily.read', 'Daily sales'],
    ['listening.history.read', 'Listening history'],
  ]);
  let busy = false;
  let availability = null;
  let checkController = null;
  let checkVersion = 0;

  function artistOrigin(value) {
    if (typeof value !== 'string' || !/^https:\/\/[a-z0-9.-]{1,253}\/?$/i.test(value)) return '';
    try { return new URL(value).origin; } catch { return ''; }
  }

  function clearConnectionCheck() {
    checkVersion++;
    checkController?.abort();
    checkController = null;
    checkStatus.textContent = '';
    checkStatus.hidden = true;
    diagnostic.hidden = true;
    diagnostic.replaceChildren();
  }

  function updatePairingLink() {
    pairingLink.hidden = true;
    pairingLink.removeAttribute('href');
    const address = provider.value === 'stoneward' ? artistOrigin(origin.value.trim()) : '';
    if (!address) return;
    pairingLink.href = new URL('/vp3-connections.php', address).href;
    pairingLink.hidden = false;
  }

  function updatePairingForm() {
    const stoneward = provider.value === 'stoneward';
    const platform = platforms[provider.value];
    originLabel.textContent = stoneward ? 'Artist site HTTPS address' : 'FoodMonster site HTTPS address';
    origin.placeholder = stoneward ? 'https://artist.example' : '';
    pairingHelp.textContent = stoneward
      ? 'Enter the artist site’s HTTPS address to test the connection. To connect, sign in to the artist site, create a one-time code in VP3 Connections, then paste it here.'
      : 'Create a one-time pairing code on your FoodMonster site under VP3 Connections.';
    submit.textContent = 'Connect ' + platform;
    submit.disabled = busy || checkController !== null || !availability?.[provider.value];
    check.hidden = !stoneward;
    check.disabled = busy || checkController !== null || availability?.stoneward !== true;
    checkStatus.hidden = !stoneward || !checkStatus.textContent;
    pairingAvailability.hidden = !availability || !Object.values(availability).includes(true) || availability[provider.value];
    pairingAvailability.textContent = 'Run VP3 Database Upgrade to enable ' + platform + ' connections.';
    updatePairingLink();
  }

  provider.addEventListener('change', () => {
    origin.value = '';
    code.value = '';
    status.textContent = '';
    clearConnectionCheck();
    updatePairingForm();
  });
  origin.addEventListener('input', () => {
    clearConnectionCheck();
    updatePairingForm();
  });

  function checkValue(value, pattern) {
    return typeof value === 'string' && pattern.test(value) ? value : '';
  }

  function renderConnectionDiagnostic(detail, address, summary) {
    if (!detail || artistOrigin(detail.origin) !== address) return;
    const fields = [
      ['Artist site', address],
      ['Checked at (UTC)', checkValue(detail.checkedAt, /^\d{4}-\d{2}-\d{2}T[0-9:.]{8,12}(?:Z|\+00:00)$/)],
      ['Artist site response', Number.isInteger(detail.artistSiteHttpStatus) && detail.artistSiteHttpStatus >= 100
        && detail.artistSiteHttpStatus <= 599 ? `HTTP ${detail.artistSiteHttpStatus}` : 'No HTTP response'],
      ['Stage', checkValue(detail.stage, /^[a-z][a-z_]{0,39}$/)],
      ['Result code', checkValue(detail.reason, /^[a-z][a-z0-9_]{0,63}$/)],
      ['VP3 build', checkValue(detail.vp3Build, /^[a-f0-9]{12}$/)],
      ['Response type', checkValue(detail.visit?.responseType, /^[a-z0-9][a-z0-9 /.+-]{0,79}$/i)],
      ['Server header', checkValue(detail.visit?.server, /^[a-z0-9][a-z0-9 /.+-]{0,79}$/i)],
      ['Request ID', checkValue(detail.visit?.requestId, /^[a-z0-9_.:-]{1,100}$/i)],
      ['Address VP3 tried', checkValue(detail.visit?.targetIp, /^[a-f0-9.:]{2,45}$/i)],
      ['Browser challenge', detail.visit?.challenge === true ? 'Reported by the gateway' : ''],
    ].filter(([,value]) => value);
    diagnostic.replaceChildren(...fields.map(([label,value]) => metadata(label,value)));
    const copy = element('button', 'Copy result');
    copy.type = 'button';
    const fallback = element('textarea');
    fallback.setAttribute('aria-label', 'Connection result');
    fallback.readOnly = true;
    fallback.rows = 4;
    fallback.hidden = true;
    const copyStatus = element('p');
    copyStatus.setAttribute('role', 'status');
    copyStatus.hidden = true;
    const result = ['VP3 Stonefellow connection check', summary, ...fields.map(([label,value]) => `${label} ${value}`)].join('\n');
    copy.addEventListener('click', async () => {
      if (!ownerAllowed() || !diagnostic.contains(copy)) return;
      try {
        if (typeof navigator.clipboard?.writeText !== 'function') throw new Error();
        await navigator.clipboard.writeText(result);
        copyStatus.textContent = 'Result copied.';
      } catch {
        if (!ownerAllowed() || !diagnostic.contains(copy)) return;
        fallback.value = result;
        fallback.hidden = false;
        fallback.focus();
        fallback.select();
        copyStatus.textContent = 'Select and copy the result below.';
      }
      copyStatus.hidden = false;
    });
    diagnostic.append(copy, copyStatus, fallback);
    diagnostic.hidden = false;
  }

  async function request(body, { scope, signal } = {}) {
    const url = new URL(endpoint);
    const options = { credentials: 'same-origin', cache: 'no-store', signal, headers: {} };
    if (scope) {
      url.searchParams.set('agent_id', String(scope.agent));
      url.searchParams.set('conversation_id', String(scope.cid));
      options.headers['X-VP3-Working-Attention'] = scope.binding;
    }
    if (body) {
      options.method = 'POST';
      options.headers['Content-Type'] = 'application/json';
      options.body = JSON.stringify({ ...body, csrf_token: config.csrf });
    }
    const response = await fetch(url, options);
    const data = await response.json().catch(() => null);
    if (!response.ok || !data?.ok) {
      let message = String(data?.error || data?.message || 'The connection request failed.').slice(0, 280);
      if (body?.action === 'connect' && body.provider === 'stoneward') {
        if (message === 'Stonefellow declined authorization. Check the connection in Stonefellow.') {
          message = 'VP3 received an authorization refusal without a pairing step. Reload Agent Chat on the current VP3 build and try again to see which step was blocked.';
        }
        if (/^[a-f0-9]{12}$/.test(data?.pairing?.vp3Build ?? '')) {
          message += ` VP3 build ${data.pairing.vp3Build}.`;
        }
      }
      const error = new Error(message);
      error.diagnostic = data?.diagnostic;
      throw error;
    }
    return data;
  }

  check.addEventListener('click', async () => {
    if (!ownerAllowed() || busy || checkController || provider.value !== 'stoneward' || !availability?.stoneward) return;
    clearConnectionCheck();
    status.textContent = '';
    const address = artistOrigin(origin.value.trim());
    if (!address) {
      checkStatus.textContent = 'Enter the artist site’s HTTPS address to test the connection.';
      updatePairingForm();
      origin.focus();
      return;
    }
    const controller = new AbortController();
    checkController = controller;
    const version = checkVersion;
    const isCurrent = () => ownerAllowed() && version === checkVersion
      && provider.value === 'stoneward' && artistOrigin(origin.value.trim()) === address;
    checkStatus.textContent = 'Checking from the VP3 server…';
    updatePairingForm();
    try {
      const data = await request({action:'checkConnection',provider:'stoneward',origin:address}, {signal:controller.signal});
      if (!isCurrent()) return;
      checkStatus.textContent = 'VP3 reached Stonefellow. You can now connect with a pairing code.';
      renderConnectionDiagnostic(data.diagnostic,address,checkStatus.textContent);
    } catch (error) {
      if (!isCurrent()) return;
      checkStatus.textContent = error.message;
      renderConnectionDiagnostic(error.diagnostic,address,checkStatus.textContent);
    } finally {
      if (checkController === controller) {
        checkController = null;
        updatePairingForm();
      }
    }
  });

  function draftQuestion(site) {
    if (site.provider === 'stoneward') return 'what I listened to this week';
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

  function siteName(site) {
    return String(site.label || '').trim() || String(site.agentName || '').trim() || new URL(site.origin).hostname;
  }

  function siteHeading(site, headingTag, detail) {
    const heading = element('div');
    heading.className = 'vp3-connected-site-heading';
    if (site.provider === 'foodmonster' && config.foodMonsterIcon) {
      const icon = element('img');
      icon.className = 'vp3-connected-site-icon';
      icon.src = config.foodMonsterIcon;
      icon.alt = '';
      icon.width = icon.height = 36;
      icon.decoding = 'async';
      heading.append(icon);
    }
    const text = element('div');
    text.append(element(headingTag, siteName(site)), element('p', detail));
    heading.append(text);
    return heading;
  }

  function stageQuestion(site, connections, feedback, after) {
    if (!ownerAllowed()) return;
    const input = document.getElementById('chatInput');
    if (!input || input.disabled || input.value.trim()) {
      feedback.textContent = 'Finish your current chat draft before starting another.';
      return;
    }
    const name = site.agentName || platforms[site.provider] || platforms.foodmonster;
    const duplicates = connections.filter(connection => connection.agentName?.toLowerCase() === name.toLowerCase()).length;
    const hostname = new URL(site.origin).hostname;
    const target = site.provider === 'stoneward' ? `Stonefellow via ${hostname}` :
      (duplicates > 1 ? `${name} via ${hostname}` : name);
    input.value = `Ask ${target} ${draftQuestion(site)}`;
    input.dispatchEvent(new Event('input', { bubbles: true }));
    after?.();
    input.focus();
  }

  function openManagement() {
    if (!ownerAllowed()) return false;
    const tools = document.getElementById('chatToolsButton');
    if (!tools) return false;
    if (tools.getAttribute('aria-expanded') !== 'true') tools.click();
    panel.open = true;
    panel.querySelector('summary').focus();
    return true;
  }

  function openWorkspace() {
    if (!ownerAllowed()) return false;
    const trigger = document.querySelector('[data-chat-specialist="connected-sites"]');
    return window.VP3AgentActivityHub?.openSpecialist?.(specialist, trigger, {label:'Connected Sites'}) || openManagement();
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
    for (const site of connections) {
      const row = element('div');
      row.dataset.siteRow = '';
      const permissions = site.scopes.map(scope => labels.get(scope)).filter(Boolean);
      const locations = (site.locations || []).map(location => location.name);
      const ask = element('button', 'Ask ' + site.agentName);
      ask.type = 'button';
      ask.addEventListener('click', () => stageQuestion(site, connections, status, () => {
        const tools = document.getElementById('chatToolsButton');
        if (tools?.getAttribute('aria-expanded') === 'true') tools.click();
      }));
      const disconnect = element('button', 'Disconnect');
      disconnect.type = 'button';
      disconnect.addEventListener('click', () => {
        if (!window.confirm('Disconnect ' + siteName(site) + ' from this VP3 account?')) return;
        perform(async () => {
          const result = await request({ action: 'disconnect', connectionId: site.id,
            ...(site.provider === 'stoneward' && { provider:'stoneward' }), });
          await load();
          status.textContent = result.revokedRemotely ? 'Site disconnected.' :
            'Disconnected from VP3. Open VP3 Connections on the site to revoke its grant.';
        });
      });
      const detail = site.provider === 'stoneward' ? `${platforms.stoneward} · ${site.origin}` : site.origin;
      row.append(siteHeading(site, 'strong', detail),
        element('p', [...permissions, ...locations].join(' · ')), ask, disconnect);
      const knowledge = site.provider !== 'stoneward' && knowledgeControl(site, canCreateKnowledgeFolder);
      if (knowledge) row.append(knowledge);
      if (site.provider === 'stoneward' && site.mediaSuggestionsAvailable) {
        const enabled = site.mediaSuggestions === true;
        const suggestions = element('button', 'Use listening history for proactive suggestions');
        suggestions.type = 'button';
        suggestions.setAttribute('aria-pressed', String(enabled));
        suggestions.addEventListener('click', () => perform(async () => {
          await request({action:'setMediaSuggestions',provider:'stoneward',connectionId:site.id,enabled:!enabled});
          await load();
          status.textContent = enabled ? 'Proactive suggestions are off for this artist site.'
            : 'Media & Entertainment can now review listening history from this artist site.';
        }));
        row.append(suggestions);
      }
      sites.append(row);
    }
  }

  async function load() {
    const data = await request();
    if (!ownerAllowed()) return;
    availability = { foodmonster:data.providers?.foodmonster ?? data.available === true,
      stoneward:data.providers?.stoneward === true };
    render(data.connections, data.canCreateKnowledgeFolder === true);
    if (!Object.values(availability).some(Boolean)) status.textContent = 'Connected Sites requires the VP3 Cloud database upgrade.';
  }

  async function perform(work) {
    if (busy || !ownerAllowed()) return;
    busy = true;
    status.textContent = '';
    for (const control of panel.querySelectorAll('button, input, select')) control.disabled = true;
    try {
      await work();
    } catch (error) {
      status.textContent = error.message;
    } finally {
      busy = false;
      for (const control of panel.querySelectorAll('button, input, select')) control.disabled = false;
      updatePairingForm();
    }
  }

  updatePairingForm();
  panel.addEventListener('toggle', () => { if (panel.open) perform(load); });
  refresh.addEventListener('click', () => perform(load));
  form.addEventListener('submit', event => {
    event.preventDefault();
    if (!availability?.[provider.value] || busy || checkController) return;
    clearConnectionCheck();
    const version = checkVersion;
    const values = { action: 'connect', origin: origin.value.trim(), code: code.value.trim(),
      ...(provider.value === 'stoneward' && { provider:'stoneward' }), };
    code.value = '';
    perform(async () => {
      try {
        await request(values);
      } catch (error) {
        const address = artistOrigin(values.origin);
        if (ownerAllowed() && values.provider === 'stoneward' && provider.value === 'stoneward'
          && version === checkVersion && address && artistOrigin(origin.value.trim()) === address) {
          renderConnectionDiagnostic(error.diagnostic,address,error.message);
        }
        throw error;
      }
      origin.value = '';
      await load();
      status.textContent = 'Site connected.';
    });
  });

  function publishRail() {
    const rail = window.VP3ChatSpecialists;
    if (!ownerAllowed()) { rail?.remove(specialist); return; }
    if (rail?.snapshot({includeResults:true}).some(entry => entry.key === specialist)) return;
    rail?.update(specialist, {
      label:'Connected Sites', type:specialist, state:'idle', status:'Workspace',
      presence:true, keepLauncher:true, inline:false, viewLabel:'Open connected sites', onView:openWorkspace,
    });
  }

  const reportStates = {
    ready:'Current', partial:'Some datasets could not be checked', stale:'Showing the last verified report',
    unchecked:'Waiting for the first check', unavailable:'The site data is currently unavailable',
    missing:'No sales totals for this business date',
    authorization_required:'Review the site connection permissions', upgrade_required:'Run VP3 Database Upgrade to enable site reports',
    on_demand:'Available on demand',
  };
  const reportState = state => Object.hasOwn(reportStates, state) ? reportStates[state] : '';
  function readableDate(value) {
    if (typeof value !== 'string' || !value) return '';
    const normalized = /^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/.test(value) ? value.replace(' ', 'T')+'Z' : value;
    const time = new Date(normalized);
    return Number.isFinite(time.getTime()) ? time.toLocaleString([], { dateStyle:'medium', timeStyle:'short' }) : '';
  }

  function metadata(label, value) {
    const row = element('p');
    row.append(element('strong', label), ' ', value);
    return row;
  }

  const reportCount = value => Number.isSafeInteger(value) && value >= 0;

  function salesSummary(sales) {
    if (!sales || typeof sales !== 'object') return null;
    const result = element('div');
    const date = checkValue(sales.businessDate, /^\d{4}-\d{2}-\d{2}$/);
    result.append(element('h5', date ? `Daily sales · ${date}` : 'Daily sales'));
    const totals = sales.totals;
    const verified = readableDate(sales.lastVerifiedAt);
    const stale = sales.state === 'stale';
    if ((sales.state === 'ready' || stale) && totals && typeof totals === 'object') {
      const currency = checkValue(totals.currency, /^[A-Z]{3}$/);
      const fields = [];
      for (const [key, label] of [['netSales', 'Net sales'], ['grossSales', 'Gross sales'], ['refunds', 'Refunds']]) {
        const amount = checkValue(totals[key], /^-?\d{1,15}(?:\.\d{1,4})?$/);
        if (amount) fields.push(metadata(label, currency ? `${amount} ${currency}` : `${amount} (currency unrecorded)`));
      }
      if (reportCount(totals.tickets)) fields.push(metadata('Closed tickets', String(totals.tickets)));
      if (fields.length) {
        if (stale) result.append(element('p', verified ? `Showing the last verified sales from ${verified}.` : 'Showing the last verified sales.'));
        result.append(...fields);
        return result;
      }
    }
    const state = ['missing','ready','stale'].includes(sales.state)
      ? 'No verified sales totals for this business date.' : reportState(sales.state);
    result.append(element('p', state || 'Daily sales could not be verified.'));
    return result;
  }

  function locationSummary(location, scopes) {
    if (typeof location?.name !== 'string' || !location.name.trim()) return null;
    const row = element('li');
    row.append(element('strong', location.name));
    if (scopes.includes('tickets.open.read')) {
      const ready = location.state === 'ready';
      const verified = readableDate(location.lastVerifiedAt);
      const ticketState = ready ? '' : reportState(location.state);
      row.append(element('h5', 'Open POS tickets'));
      if (ready && reportCount(location.openTickets)) {
        const late = reportCount(location.lateTickets) && location.lateTickets <= location.openTickets
          ? ` · ${location.lateTickets} late` : '';
        row.append(element('p', `${location.openTickets} open${late}`));
      } else if ((ready || location.state === 'partial' || location.state === 'stale') && reportCount(location.ticketsChecked)) {
        const checked = location.ticketsChecked;
        row.append(element('p', `${checked} ${checked === 1 ? 'ticket' : 'tickets'} checked`));
        if (!ready) row.append(element('small', 'The current open ticket total is unverified.'));
      } else if (!ticketState) {
        row.append(element('p', 'Open tickets could not be verified.'));
      }
      const details = [ticketState, verified ? `Last verified ${verified}` : ''].filter(Boolean);
      if (details.length) row.append(element('small', details.join(' · ')));
    }
    if (scopes.includes('sales.daily.read')) {
      const sales = salesSummary(location.sales);
      if (sales) row.append(sales);
    }
    return row;
  }

  function sourceLink(site, finding) {
    if (typeof finding.url !== 'string' || !finding.url || finding.url.includes('\\')) return null;
    try {
      const origin = new URL(site.origin).origin;
      const target = new URL(finding.url, origin);
      if (target.protocol !== 'https:' || target.origin !== origin || target.username || target.password) return null;
      const link = element('a', 'Open source');
      link.href = target.href;
      link.target = '_blank';
      link.rel = 'noopener noreferrer';
      return link;
    } catch {
      return null;
    }
  }

  function findingNotes(heading, findings, key) {
    const notes = findings.filter(finding => finding.status !== 'resolved' && finding[key]);
    if (!notes.length) return null;
    const section = element('section');
    const list = element('ul');
    for (const finding of notes) {
      const item = element('li');
      item.append(element('strong', finding.title), element('p', finding[key]));
      list.append(item);
    }
    section.append(element('h4', heading), list);
    return section;
  }

  function renderReport(site, state) {
    const content = element('div');
    content.dataset.connectedSiteReport = site.id;
    const stoneward = site.provider === 'stoneward';
    content.append(siteHeading(site, 'h3', `${site.platformName || platforms[site.provider] || platforms.foodmonster} · ${new URL(site.origin).hostname}`));
    const report = site.report;
    const status = stoneward && !report ? '' : reportState(report?.state || (state === 'ready' ? 'unchecked' : state));
    if (status) content.append(metadata('Report status', status));
    const checked = readableDate(report?.lastCheckedAt);
    const verified = readableDate(report?.lastVerifiedAt);
    if (checked) content.append(metadata('Last checked', checked));
    if (verified) content.append(metadata('Last verified', verified));

    const access = element('details');
    access.className = 'vp3-connected-site-coverage';
    access.append(element('summary', stoneward ? 'Datasets and access' : 'Datasets and location access'));
    const datasets = Array.isArray(report?.datasets) ? report.datasets : site.scopes.map(id => {
      const label = labels.get(id);
      return stoneward ? {id,label,access:'read',mode:'on_demand'} : {id,label};
    }).filter(row => row.label);
    const locations = Array.isArray(report?.locations) ? report.locations : (site.locations || []);
    if (datasets.length) {
      access.append(element('h4', 'Datasets'));
      const list = element('ul');
      for (const dataset of datasets) {
        if (!dataset?.label) continue;
        const row = element('li');
        row.append(element('strong', dataset.label));
        const details = [dataset.access === 'read' ? 'Read access' : '',
          dataset.mode === 'proactive' ? 'Ongoing review' : dataset.mode === 'on_demand' ? 'On demand' : '', reportState(dataset.state)].filter(Boolean);
        if (details.length) row.append(element('small', details.join(' · ')));
        list.append(row);
      }
      access.append(list);
    }
    const locationReports = !stoneward && Array.isArray(report?.locations);
    if (locations.length && !locationReports) {
      access.append(element('h4', 'Locations'));
      const list = element('ul');
      for (const location of locations) {
        if (!location?.name) continue;
        const row = element('li');
        row.append(element('strong', location.name));
        const locationVerified = readableDate(location.lastVerifiedAt);
        const details = [reportState(location.state), locationVerified ? `Last verified ${locationVerified}` : '',
          Number.isSafeInteger(location.ticketsChecked) ? `${location.ticketsChecked} tickets checked` : ''].filter(Boolean);
        if (details.length) row.append(element('small', details.join(' · ')));
        list.append(row);
      }
      access.append(list);
    }
    if (!datasets.length && !locations.length) access.append(element('p', 'The site has no data access configured yet.'));
    content.append(access);

    if (locationReports) {
      const rows = locations.map(location => locationSummary(location, site.scopes)).filter(Boolean);
      if (rows.length) {
        const section = element('section');
        const list = element('ul');
        list.append(...rows);
        section.append(element('h4', 'Location reports'), list);
        content.append(section);
      }
    }

    if (!report || report.state === 'authorization_required' || !Array.isArray(report.findings)) return content;
    const findings = report.findings.filter(row => typeof row?.title === 'string' && row.title && typeof row.summary === 'string');
    const results = element('section');
    results.append(element('h4', 'Key findings'));
    if (!findings.length) {
      results.append(element('p', !stoneward && report.state === 'ready'
        ? 'The last check found no POS changes that need attention.' : 'No verified findings yet.'));
    } else {
      const list = element('ul');
      for (const finding of findings) {
        const row = element('li');
        row.append(element('strong', finding.title), element('p', finding.summary));
        if (finding.status === 'resolved') row.append(element('small', 'Resolved at the site'));
        if (finding.source_state && finding.source_state !== 'ready') {
          const sourceState = reportState(finding.source_state);
          if (sourceState) row.append(element('small', sourceState));
        }
        const link = sourceLink(site, finding);
        if (link) row.append(link);
        list.append(row);
      }
      results.append(list);
    }
    content.append(results);
    for (const notes of [findingNotes('Actions & follow-ups', findings, 'next_step'), findingNotes('Open questions', findings, 'question')]) {
      if (notes) content.append(notes);
    }
    return content;
  }

  let selectedConnection = '';
  function mountWorkspace(host, getScope, closeWorkspace) {
    const view = element('section');
    view.className = 'vp3-connected-sites-workspace';
    const controls = element('div');
    controls.className = 'vp3-connected-site-controls';
    const label = element('label', 'Site');
    const picker = element('select');
    picker.dataset.connectedSitePicker = '';
    label.append(picker);
    label.hidden = true;
    const refresh = element('button', 'Refresh report');
    const settings = element('button', 'Manage connections');
    refresh.type = settings.type = 'button';
    settings.addEventListener('click', () => { closeWorkspace(); openManagement(); });
    controls.append(label, refresh, settings);
    const feedback = element('p');
    feedback.setAttribute('role', 'status');
    const content = element('div');
    view.append(controls, feedback, content);
    host.replaceChildren(view);
    let active = true;
    let controller = null;
    let timer = 0;
    let scopeKey = '';
    let lastData = '';
    let connections = [];
    let reportState = '';

    const bindingKey = scope => ownerAllowed() && scope?.hasChat && scope.cid > 0 && scope.binding
      ? JSON.stringify([scope.owner, scope.agent, scope.cid, scope.binding]) : '';
    function render() {
      const site = connections.find(row => row.id === picker.value);
      if (!site) { content.replaceChildren(); return; }
      const report = renderReport(site, reportState);
      const ask = element('button', 'Ask ' + site.agentName);
      ask.type = 'button';
      ask.addEventListener('click', () => stageQuestion(site, connections, feedback, closeWorkspace));
      report.append(ask);
      content.replaceChildren(report);
    }
    function selectSite() {
      selectedConnection = picker.value;
      render();
    }
    picker.addEventListener('change', selectSite);
    function clear() {
      lastData = '';
      connections = [];
      picker.replaceChildren();
      label.hidden = true;
      content.replaceChildren();
    }
    async function refreshReport() {
      window.clearTimeout(timer);
      const scope = getScope();
      const key = bindingKey(scope);
      if (!active || document.hidden || view.closest('[hidden]')) return;
      if (key !== scopeKey) {
        controller?.abort();
        controller = null;
        scopeKey = key;
        clear();
      }
      if (!key) { feedback.textContent = 'Open a saved Agent Chat to see connected-site reports.'; return; }
      if (controller) return;
      const requestController = new AbortController();
      controller = requestController;
      const timeout = window.setTimeout(() => requestController.abort(), 10000);
      const current = () => active && controller === requestController && key === bindingKey(getScope());
      refresh.disabled = true;
      if (!lastData) feedback.textContent = 'Loading connected-site reports…';
      try {
        const data = await request(null, {scope, signal:requestController.signal});
        if (!current()) return;
        if ((data.available !== true && !Object.values(data.providers ?? {}).includes(true)) || !Array.isArray(data.connections)) {
          clear();
          feedback.textContent = 'Run VP3 Database Upgrade to manage connected sites.';
          return;
        }
        const change = JSON.stringify([data.connections, data.connectedSitesReportState]);
        feedback.textContent = data.connections.length ? '' : 'Connect a site to get started.';
        if (change === lastData) return;
        lastData = change;
        connections = data.connections;
        reportState = data.connectedSitesReportState || 'unchecked';
        picker.replaceChildren(...connections.map(site => new Option(
          connections.length > 1 ? `${siteName(site)} · ${new URL(site.origin).hostname}` : siteName(site), site.id)));
        if (connections.some(site => site.id === selectedConnection)) picker.value = selectedConnection;
        label.hidden = !connections.length;
        render();
      } catch {
        if (!current()) return;
        clear();
        feedback.textContent = 'Connected-site reports could not be loaded.';
      } finally {
        window.clearTimeout(timeout);
        if (controller === requestController) {
          controller = null;
          refresh.disabled = false;
          if (active && key === bindingKey(getScope()) && !document.hidden) timer = window.setTimeout(refreshReport, 15000);
        }
      }
    }
    refresh.addEventListener('click', refreshReport);
    return {refresh:refreshReport, dispose() {
      active = false;
      window.clearTimeout(timer);
      controller?.abort();
      controller = null;
      view.remove();
    }};
  }

  function updateContext() {
    panel.hidden = !ownerAllowed();
    if (panel.hidden) {
      panel.open = false;
      sites.replaceChildren();
      selectedConnection = '';
      code.value = '';
      status.textContent = '';
      clearConnectionCheck();
      availability = null;
      updatePairingForm();
    }
    queueMicrotask(publishRail);
  }
  window.VP3ConnectedSites = Object.freeze({mountWorkspace,openWorkspace});
  window.addEventListener('DOMContentLoaded', updateContext);
  window.addEventListener('stonefellow:chat-context-changing', () => {
    clearConnectionCheck();
    updatePairingForm();
  });
  window.addEventListener('vp3:chat-context-ready', updateContext);
  window.addEventListener('vp3:participant-selection-changed', updateContext);
  window.addEventListener('vp3:participant-task-context', event => {
    participantActor = Number(event.detail?.participant_id) || 0;
    updateContext();
  });
  window.addEventListener('pagehide', () => { disposed = true; updateContext(); });
  window.addEventListener('pageshow', () => { disposed = false; updateContext(); });
  updateContext();
})();
