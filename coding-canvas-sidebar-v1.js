(() => {
  'use strict';

  let participantActor = 0;
  const inParticipantContext = () => participantActor > 0 || Boolean(window.VP3ParticipantContinuity?.selectedContext?.());
  window.addEventListener('vp3:participant-task-context',event => { participantActor = Number(event.detail?.participant_id) || 0; });

  function bindSettings(sidebar, fixedScope = null) {
    const panel = sidebar?.querySelector('[data-coding-sidebar]');
    if (!panel) return () => {};

    const lifetime = new AbortController();
    const listen = {signal:lifetime.signal};
    const select = panel.querySelector('select');
    const status = panel.querySelector('#vp3CodingSidebarStatus') || panel.querySelector('[role="status"]:not([data-coding-auto-status])');
    const autoToggle = sidebar.querySelector('[data-coding-auto-drafts]');
    const autoStatus = sidebar.querySelector('[data-coding-auto-status]');
    const autoControls = sidebar.querySelector('[data-coding-auto-controls]');
    const initialOwner = Number(panel.dataset.codingOwner || window.STONEFELLOW_CHAT?.userId);
    const balanceLabel = panel.querySelector('[data-coding-balance-label]');
    const balanceValue = panel.querySelector('[data-coding-balance]');
    const meter = panel.querySelector('[data-coding-token-meter]');
    const numbers = new Intl.NumberFormat();
    const providerLabel = { openai: 'OpenAI', anthropic: 'Anthropic' };
    const modelKey = target => target ? `${target.provider}:${target.model}` : '';
    let state = null;
    let revision = 0;
    let saving = false;
    let reading = false;
    let queued = false;
    let refreshTimer = null;
    let modelController = null;
    function contextAllowed() {
      const chat = window.STONEFELLOW_CHAT;
      if (lifetime.signal.aborted || inParticipantContext() || chat && Number(chat.userId) !== initialOwner) return false;
      return !fixedScope || Number(chat?.agentId) === fixedScope.agent
        && String(chat?.workingAttentionBinding || '') === fixedScope.binding;
    }
    let autoState = null;
    let autoRevision = 0;
    let autoReading = false;
    let autoSaving = false;
    let autoQueued = false;
    let autoTimer = 0;
    let autoController = null;
    let lastAutoScope = '';
    function autoScope() {
      const owner = Number(window.STONEFELLOW_CHAT?.userId);
      const agent = Number(window.STONEFELLOW_CHAT?.agentId);
      if (!autoToggle || !panel.dataset.codingAutoEndpoint || !contextAllowed() || !initialOwner || owner !== initialOwner
        || !Number.isSafeInteger(agent) || agent < 0) return null;
      return {owner, agent};
    }
    const scopeKey = scope => scope ? `${scope.owner}:${scope.agent}` : '';
    const autoStateMatches = scope => scope && autoState?.owner_user_id === scope.owner && autoState?.agent_id === scope.agent;
    function showAutoStatus(message = '', error = false) {
      if (!autoStatus) return;
      autoStatus.textContent = message;
      autoStatus.hidden = message === '';
      autoStatus.dataset.error = String(error);
    }
    function renderAuto() {
      if (!autoToggle) return;
      const valid = autoStateMatches(autoScope());
      autoToggle.checked = valid && autoState?.enabled === true;
      autoToggle.disabled = autoReading || autoSaving || !valid || autoState?.available !== true
        || !autoState.enabled && autoState.can_enable !== true;
      showAutoStatus(valid ? autoState?.reason || '' : '');
    }
    async function autoRequest(scope, post) {
      const endpoint = new URL(panel.dataset.codingAutoEndpoint, location.href);
      const controller = new AbortController();
      autoController = controller;
      const timeout = window.setTimeout(() => controller.abort(), 10000);
      const options = {credentials:'same-origin', cache:'no-store', signal:controller.signal};
      if (post) {
        options.method = 'POST';
        options.headers = {'Content-Type':'application/json'};
        options.body = JSON.stringify({agent_id:scope.agent, csrf_token:sidebar.dataset.csrf, ...post});
      } else endpoint.searchParams.set('agent_id', String(scope.agent));
      try {
        const response = await fetch(endpoint, options);
        const data = await response.json().catch(() => null);
        if (!response.ok || !data?.ok) throw new Error(data?.error || 'Auto Build settings are unavailable.');
        const next = data.state;
        if (next?.owner_user_id !== scope.owner || next.agent_id !== scope.agent
          || !['enabled','initiative_on','available','can_enable'].every(key => typeof next[key] === 'boolean')
          || typeof next.revision !== 'string' || next.available && !/^[a-f0-9]{32}$/.test(next.revision)
          || typeof next.reason !== 'string') throw new Error('Auto Build settings could not be verified.');
        return next;
      } finally {
        window.clearTimeout(timeout);
        if (autoController === controller) autoController = null;
      }
    }
    async function refreshAuto() {
      const scope = autoScope();
      if (!scope || document.hidden) return;
      if (autoReading || autoSaving) { autoQueued = true; return; }
      autoReading = true;
      const snapshot = autoRevision;
      renderAuto();
      try {
        const next = await autoRequest(scope);
        if (snapshot === autoRevision && scopeKey(scope) === scopeKey(autoScope())) autoState = next;
      } catch (error) {
        if (snapshot === autoRevision && scopeKey(scope) === scopeKey(autoScope())) {
          autoState = null;
          showAutoStatus(error.message, true);
        }
      } finally {
        autoReading = false;
        if (snapshot === autoRevision && scopeKey(scope) === scopeKey(autoScope())) {
          const failed = autoState === null;
          if (!failed) renderAuto();
          else if (autoToggle) { autoToggle.checked = false; autoToggle.disabled = true; }
        }
        if (autoQueued) { autoQueued = false; scheduleAutoRefresh(); }
      }
    }
    function scheduleAutoRefresh() {
      if (lifetime.signal.aborted) return;
      window.clearTimeout(autoTimer);
      autoTimer = window.setTimeout(refreshAuto, 350);
    }
    autoToggle?.addEventListener('change', async () => {
      const scope = autoScope();
      if (scope && autoState && !autoStateMatches(scope)) { updateAutoScope(); return; }
      if (!scope || autoReading || autoSaving || !autoState || autoState.available !== true
        || autoToggle.checked && autoState.can_enable !== true) { renderAuto(); return; }
      const enabled = autoToggle.checked;
      const savedRevision = autoState.revision;
      autoSaving = true;
      const snapshot = ++autoRevision;
      autoToggle.disabled = true;
      showAutoStatus('Saving…');
      try {
        const next = await autoRequest(scope, {enabled, revision:savedRevision});
        if (snapshot === autoRevision && scopeKey(scope) === scopeKey(autoScope())) {
          autoState = next;
          autoSaving = false;
          renderAuto();
          if (!next.reason) showAutoStatus('Saved.');
          window.dispatchEvent(new CustomEvent('vp3:initiative-canvas-setting-changed', {detail:{agent_id:scope.agent}}));
        }
      } catch (error) {
        if (snapshot === autoRevision && scopeKey(scope) === scopeKey(autoScope())) {
          autoSaving = false;
          renderAuto();
          showAutoStatus(error.message, true);
          scheduleAutoRefresh();
        }
      } finally {
        autoSaving = false;
        if (autoQueued) { autoQueued = false; scheduleAutoRefresh(); }
      }
    },listen);
    function updateAutoScope() {
      autoRevision++;
      autoController?.abort();
      autoState = null;
      lastAutoScope = scopeKey(autoScope());
      renderAuto();
      scheduleAutoRefresh();
    }

    async function request(post) {
      const controller = new AbortController();
      modelController = controller;
      const timeout = window.setTimeout(() => controller.abort(), 10000);
      const options = { credentials: 'same-origin', cache: 'no-store', signal:controller.signal };
      if (post) {
        options.method = 'POST';
        options.headers = { 'Content-Type': 'application/json' };
        options.body = JSON.stringify(post);
      }
      try {
        const response = await fetch(panel.dataset.codingSidebar, options);
        const data = await response.json().catch(() => null);
        if (!response.ok || !data?.ok) throw new Error(data?.error || 'Coding Agent settings are unavailable.');
        return data;
      } finally {
        window.clearTimeout(timeout);
        if (modelController === controller) modelController = null;
      }
    }

    function showStatus(message, error = false) {
      if (status.textContent !== message) status.textContent = message;
      status.hidden = message === '';
      status.dataset.error = String(error);
      select.title = message || 'Coding Agent model';
      select.setAttribute('aria-invalid', String(error));
    }

    function showModels() {
      const options = state?.options ?? [];
      const selected = modelKey(state?.selected);
      const unavailable = selected && !options.some(option => modelKey(option) === selected);
      const items = [];
      if (unavailable) items.push(new Option('Saved model unavailable', selected));
      if (options.length) items.push(new Option(`Automatic · ${state.default.label}`, ''));
      for (const option of options) {
        const description = `${option.label} · ${providerLabel[option.provider]}`;
        const item = new Option(description, modelKey(option));
        item.title = option.model;
        items.push(item);
      }
      if (!items.length) items.push(new Option('No model available', ''));
      const current = [...select.options];
      if (items.length !== current.length || items.some((item, i) => item.value !== current[i].value || item.text !== current[i].text)) {
        if (unavailable) items[0].disabled = true;
        select.replaceChildren(...items);
      }
      if (select.value !== selected) select.value = selected;
      select.disabled = options.length === 0;
    }

    function showBalance(balance) {
      const personal = state?.funding === 'own_key';
      balanceLabel.textContent = 'Tokens';
      balanceValue.textContent = 'Unavailable';
      balanceValue.removeAttribute('title');
      meter.hidden = true;
      if (!balance) return;
      if (personal) {
        balanceValue.textContent = numbers.format(balance.tracked_month_tokens);
        balanceValue.title = 'This month using your API keys';
        return;
      }
      if (balance.unlimited) {
        balanceValue.textContent = 'Unlimited';
        return;
      }
      const remaining = Math.max(0, balance.package_remaining + balance.credits_remaining - balance.reserved);
      balanceValue.textContent = numbers.format(remaining);
      balanceValue.title = 'Available plan tokens and credits';
      if (balance.package_allowance > 0) {
        meter.max = balance.package_allowance;
        meter.value = balance.package_remaining;
        meter.hidden = false;
      }
    }

    function render(data) {
      state = data.state;
      showModels();
      showBalance(data.balance);
      showStatus(state.unavailable, Boolean(state.unavailable));
    }

    async function refresh() {
      if (document.hidden || !contextAllowed()) return;
      if (saving || reading) {
        queued = true;
        return;
      }
      reading = true;
      const snapshot = revision;
      try {
        const data = await request();
        if (snapshot === revision && contextAllowed()) render(data);
      } catch (error) {
        if (snapshot === revision && contextAllowed()) {
          showModels();
          showBalance(null);
          showStatus(error.message, true);
        }
      } finally {
        reading = false;
        finishRefresh();
      }
    }

    function finishRefresh() {
      if (!queued || reading || saving) return;
      queued = false;
      scheduleRefresh();
    }

    function scheduleRefresh() {
      if (lifetime.signal.aborted) return;
      window.clearTimeout(refreshTimer);
      refreshTimer = window.setTimeout(refresh, 350);
      scheduleAutoRefresh();
    }

    select.addEventListener('change', async () => {
      if (saving || !state || !contextAllowed()) return;
      const target = state.options.find(option => modelKey(option) === select.value);
      const model = target ? { provider: target.provider, model: target.model } : null;
      saving = true;
      const snapshot = ++revision;
      select.disabled = true;
      showStatus('Saving…');
      try {
        const data = await request({ model, csrf_token: sidebar.dataset.csrf });
        if (snapshot === revision && contextAllowed()) {
          render(data);
          if (!state.unavailable) showStatus('Saved for new runs.');
        }
        if (!lifetime.signal.aborted) window.dispatchEvent(new CustomEvent('vp3:coding-model-setting-changed',{detail:{panel}}));
      } catch (error) {
        if (snapshot === revision && contextAllowed()) {
          showModels();
          showStatus(error.message, true);
        }
      } finally {
        saving = false;
        finishRefresh();
      }
    },listen);

    function updateParticipantScope() {
      panel.hidden = !contextAllowed();
      if (autoControls) autoControls.hidden = panel.hidden;
      revision++;
      modelController?.abort();
      updateAutoScope();
      if (panel.hidden) {
        state = null;
        showModels();
        showBalance(null);
        showStatus('');
        meter.removeAttribute('value');
        meter.removeAttribute('max');
      } else {
        scheduleRefresh();
      }
    }

    window.addEventListener('vp3:participant-selection-changed', updateParticipantScope,listen);
    window.addEventListener('vp3:participant-task-context', updateParticipantScope,listen);
    window.addEventListener('vp3-coding-usage-change', scheduleRefresh,listen);
    window.addEventListener('vp3:coding-model-setting-changed', event => {
      if (event.detail?.panel !== panel) scheduleRefresh();
    },listen);
    window.addEventListener('vp3:chat-context-ready', () => {
      if (panel.hidden !== !contextAllowed()) { updateParticipantScope(); return; }
      if (lastAutoScope !== scopeKey(autoScope())) updateAutoScope();
      else scheduleAutoRefresh();
    },listen);
    window.addEventListener('vp3:initiative-canvas-setting-changed', scheduleAutoRefresh,listen);
    window.addEventListener('vp3:chat-message-persisted', scheduleRefresh,listen);
    document.addEventListener('visibilitychange', scheduleRefresh,listen);
    window.addEventListener('pageshow', event => { if (event.persisted) scheduleRefresh(); },listen);
    updateParticipantScope();
    return () => {
      lifetime.abort();
      modelController?.abort();
      autoController?.abort();
      window.clearTimeout(refreshTimer);
      window.clearTimeout(autoTimer);
    };
  }

  const sidebar = document.getElementById('chatSidebar');
  if (sidebar) bindSettings(sidebar);
  let nextMount = 0;
  window.VP3CodingAgentSettings = Object.freeze({mount(host) {
    const chat = window.STONEFELLOW_CHAT;
    const panel = sidebar?.querySelector('[data-coding-sidebar]');
    const owner = Number(panel?.dataset.codingOwner);
    const agent = Number(chat?.agentId);
    if (!host?.isConnected || !panel || !sidebar.dataset.csrf || Number(chat?.userId) !== owner || owner < 1
      || !Number.isSafeInteger(agent) || agent < 0 || inParticipantContext()) return null;
    const contents = document.createElement('div');
    contents.className = 'coding-specialist-settings';
    contents.dataset.csrf = sidebar.dataset.csrf;
    contents.append(panel.cloneNode(true));
    const autoControls = sidebar.querySelector('[data-coding-auto-controls]');
    if (autoControls && !panel.contains(autoControls)) contents.append(autoControls.cloneNode(true));
    const prefix = `vp3CodingSettings${++nextMount}-`;
    const ids = new Map();
    for (const element of contents.querySelectorAll('[id]')) {
      ids.set(element.id,prefix + element.id);
      element.id = ids.get(element.id);
    }
    for (const element of contents.querySelectorAll('[for],[aria-describedby]')) {
      for (const attribute of ['for','aria-describedby']) {
        const value = element.getAttribute(attribute);
        if (value) element.setAttribute(attribute,value.split(' ').map(id => ids.get(id) || id).join(' '));
      }
    }
    const select = contents.querySelector('select');
    select.replaceChildren(new Option('Checking…'));
    select.disabled = true;
    for (const status of contents.querySelectorAll('[role="status"]')) {
      status.textContent = '';
      status.hidden = true;
    }
    host.append(contents);
    const release = bindSettings(contents,{agent,binding:String(chat.workingAttentionBinding || '')});
    return () => { release(); contents.remove(); };
  }});
})();
