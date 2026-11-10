(() => {
  'use strict';

  const sidebar = document.getElementById('chatSidebar');
  const panel = sidebar?.querySelector('[data-coding-sidebar]');
  if (!panel) return;

  const select = panel.querySelector('select');
  const status = panel.querySelector('[role="status"]');
  const funding = panel.querySelector('[data-coding-funding]');
  const balanceLabel = panel.querySelector('[data-coding-balance-label]');
  const balanceValue = panel.querySelector('[data-coding-balance]');
  const meter = panel.querySelector('[data-coding-token-meter]');
  const credits = panel.querySelector('[data-coding-credits]');
  const reserved = panel.querySelector('[data-coding-reserved]');
  const periodLabel = panel.querySelector('[data-coding-period-label]');
  const period = panel.querySelector('[data-coding-period]');
  const numbers = new Intl.NumberFormat();
  const dates = new Intl.DateTimeFormat(undefined, { month: 'short', day: 'numeric', year: 'numeric', timeZone: 'UTC' });
  const providerLabel = { openai: 'OpenAI', anthropic: 'Anthropic' };
  const modelKey = target => target ? `${target.provider}:${target.model}` : '';
  let state = null;
  let revision = 0;
  let saving = false;
  let reading = false;
  let queued = false;
  let refreshTimer = null;
  let participantActor = 0;
  const inParticipantContext = () => participantActor > 0 || Boolean(window.VP3ParticipantContinuity?.selectedContext?.());

  async function request(post) {
    const options = { credentials: 'same-origin', cache: 'no-store' };
    if (post) {
      options.method = 'POST';
      options.headers = { 'Content-Type': 'application/json' };
      options.body = JSON.stringify(post);
    }
    const response = await fetch(panel.dataset.codingSidebar, options);
    const data = await response.json().catch(() => null);
    if (!response.ok || !data?.ok) throw new Error(data?.error || 'Coding Agent settings are unavailable.');
    return data;
  }

  function showStatus(message, error = false) {
    if (status.textContent !== message) status.textContent = message;
    status.hidden = message === '';
    status.dataset.error = String(error);
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
    funding.textContent = state ? (personal ? 'My API keys' : 'VP3 Cloud') : '—';
    balanceLabel.textContent = personal ? 'Tracked this month' : 'Plan tokens left';
    balanceValue.textContent = 'Unavailable';
    meter.hidden = credits.parentElement.hidden = reserved.parentElement.hidden = period.parentElement.hidden = true;
    if (!balance) return;
    if (personal) {
      balanceValue.textContent = `${numbers.format(balance.tracked_month_tokens)} tokens`;
      return;
    }
    if (balance.unlimited) {
      balanceValue.textContent = 'Unlimited';
      return;
    }
    balanceValue.textContent = numbers.format(balance.package_remaining);
    if (balance.package_allowance > 0) {
      meter.max = balance.package_allowance;
      meter.value = balance.package_remaining;
      meter.hidden = false;
    }
    credits.textContent = numbers.format(balance.credits_remaining);
    credits.parentElement.hidden = false;
    if (balance.reserved > 0) {
      reserved.textContent = numbers.format(balance.reserved);
      reserved.parentElement.hidden = false;
    }
    if (balance.period_end) {
      periodLabel.textContent = balance.renews ? 'Plan resets' : 'Period ends';
      period.dateTime = balance.period_end;
      period.textContent = dates.format(new Date(`${balance.period_end}T00:00:00Z`));
      period.parentElement.hidden = false;
    }
  }

  function render(data) {
    state = data.state;
    showModels();
    showBalance(data.balance);
    showStatus(state.unavailable);
  }

  async function refresh() {
    if (document.hidden || inParticipantContext()) return;
    if (saving || reading) {
      queued = true;
      return;
    }
    reading = true;
    const snapshot = revision;
    try {
      const data = await request();
      if (snapshot === revision) render(data);
    } catch (error) {
      if (snapshot === revision) {
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
    window.clearTimeout(refreshTimer);
    refreshTimer = window.setTimeout(refresh, 350);
  }

  select.addEventListener('change', async () => {
    if (saving || !state || inParticipantContext()) return;
    const target = state.options.find(option => modelKey(option) === select.value);
    const model = target ? { provider: target.provider, model: target.model } : null;
    saving = true;
    const snapshot = ++revision;
    select.disabled = true;
    showStatus('Saving…');
    try {
      const data = await request({ model, csrf_token: sidebar.dataset.csrf });
      if (snapshot === revision) {
        render(data);
        if (!state.unavailable) showStatus('Saved for new runs.');
      }
    } catch (error) {
      if (snapshot === revision) {
        showModels();
        showStatus(error.message, true);
      }
    } finally {
      saving = false;
      finishRefresh();
    }
  });

  function updateParticipantScope() {
    panel.hidden = inParticipantContext();
    revision++;
    if (panel.hidden) {
      state = null;
      showModels();
      showBalance(null);
      showStatus('');
      credits.textContent = reserved.textContent = period.textContent = '';
      period.removeAttribute('datetime');
      meter.removeAttribute('value');
      meter.removeAttribute('max');
    } else {
      scheduleRefresh();
    }
  }

  window.addEventListener('vp3:participant-selection-changed', updateParticipantScope);
  window.addEventListener('vp3:participant-task-context', event => {
    participantActor = Number(event.detail?.participant_id) || 0;
    updateParticipantScope();
  });
  window.addEventListener('vp3-coding-usage-change', scheduleRefresh);
  window.addEventListener('vp3:chat-message-persisted', scheduleRefresh);
  document.addEventListener('visibilitychange', scheduleRefresh);
  window.addEventListener('pageshow', event => { if (event.persisted) scheduleRefresh(); });
  updateParticipantScope();
})();
