(() => {
  'use strict';

  const config = window.VP3_MUSIC_PREFERENCES_V1_CONFIG || {};
  const records = new Set();
  const attached = new WeakMap();
  const skipped = new Set();
  const savedPanels = new Set();
  const feedbackCards = new Set();
  const chatAttached = new WeakMap();
  const revisions = new Map();
  const idPattern = /^[a-f0-9]{32}$/;
  const revisionPattern = /^[a-f0-9]{64}$/;
  const uuidPattern = /^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/;
  let generation = 0;
  const text = (value, limit = 300) => typeof value === 'string' ? value.slice(0, limit) : '';
  const node = (tag, className = '', value = '') => {
    const element = document.createElement(tag);
    if (className) element.className = className;
    if (value) element.textContent = value;
    return element;
  };
  const button = (label, handler) => {
    const element = node('button', '', label);
    element.type = 'button';
    element.addEventListener('click', handler);
    return element;
  };
  const conversationId = () => Number(window.STONEFELLOW_CHAT_CONTINUITY?.conversationId?.()
    ?? window.STONEFELLOW_CHAT?.initialConversationId ?? config.conversationId ?? 0);
  function currentScope(payload, action = false) {
    const live = window.STONEFELLOW_CHAT || {};
    return Number.isSafeInteger(payload?.owner_user_id) && payload.owner_user_id > 0
      && payload.owner_user_id === Number(config.userId) && payload.owner_user_id === Number(live.userId)
      && Number.isSafeInteger(payload.agent_id) && payload.agent_id === Number(config.agentId || 0)
      && payload.agent_id === Number(live.agentId || 0)
      && Number.isSafeInteger(payload.conversation_id) && payload.conversation_id > 0
      && payload.conversation_id === conversationId()
      && String(config.csrf || '') === String(live.csrf || '')
      && !window.VP3ParticipantContinuity?.selectedContext?.() && (!action || !document.hidden);
  }
  function validSelection(selection) {
    if (!selection || typeof selection !== 'object') return false;
    if (selection.kind === 'history') return Number.isSafeInteger(selection.source_message_id) && selection.source_message_id > 0
      && revisionPattern.test(selection.revision || '') && typeof selection.entry_ref === 'string'
      && selection.entry_ref.length > 0 && selection.entry_ref.length <= 4096;
    if (selection.kind === 'result') return idPattern.test(selection.result_id || '') && revisionPattern.test(selection.revision || '')
      && Number.isSafeInteger(selection.index) && selection.index >= 0 && selection.index < 25;
    if (selection.kind === 'playlist') return idPattern.test(selection.playlist_id || '')
      && Number.isSafeInteger(selection.revision) && selection.revision > 0
      && Number.isSafeInteger(selection.index) && selection.index >= 0 && selection.index < 25;
    return selection.kind === 'preference' && typeof selection.reference === 'string'
      && selection.reference.length > 0 && selection.reference.length <= 4096;
  }
  function identity(item) {
    return item && ['vp3', 'youtube'].includes(item.source) && typeof item.source_id === 'string'
      && (item.source === 'vp3' ? /^[1-9][0-9]{0,18}$/.test(item.source_id) : /^[A-Za-z0-9_-]{11}$/.test(item.source_id));
  }
  function current(record, action = false) {
    return records.has(record) && record.generation === generation && record.host.isConnected
      && currentScope(record.payload, action) && (typeof record.isCurrent !== 'function' || record.isCurrent() === true);
  }
  function body(payload, action, fields = {}) {
    return {action, csrf_token:config.csrf, agent_id:payload.agent_id, conversation_id:payload.conversation_id, ...fields};
  }
  async function request(payload, frozen, signal) {
    if (!currentScope(payload)) throw new Error('These music preferences belong to another Chat.');
    let endpoint;
    try { endpoint = new URL(config.endpoint, location.href); } catch { throw new Error('Music preferences are unavailable.'); }
    if (endpoint.origin !== location.origin) throw new Error('Music preferences are unavailable.');
    const response = await fetch(endpoint, {method:'POST', credentials:'same-origin', cache:'no-store', signal,
      headers:{'Content-Type':'application/json', Accept:'application/json'}, body:frozen});
    const data = await response.json();
    if (!currentScope(payload)) throw new Error('The account or Chat changed.');
    if (!response.ok || data.ok !== true) {
      const error = new Error(response.status === 409 ? 'This choice changed. Refresh it before continuing.'
        : text(data.error || data.message) || 'Music preferences could not be confirmed.');
      error.status = response.status;
      throw error;
    }
    return data;
  }
  function validState(state, item, clearOnly = false) {
    return state?.protocol === 'vp3_music_preferences_v1' && ['like', 'dislike', 'neutral'].includes(state.preference)
      && Number.isSafeInteger(state.revision) && state.revision >= 0 && typeof state.reason === 'string'
      && typeof state.has_saved_preference === 'boolean'
      && currentScope(state)
      && (clearOnly || state.source === item.source && state.source_id === item.source_id);
  }
  function rememberRevision(state) {
    if (!identity(state)) return true;
    // Another visible row may have observed a newer acknowledgement while an
    // older request was still in flight. Re-read; do not restore its old value.
    const key = [state.owner_user_id, state.agent_id, state.conversation_id, state.source, state.source_id].join(':');
    if ((revisions.get(key) ?? 0) > state.revision) return false;
    revisions.set(key, state.revision);
    return true;
  }
  function status(record, message, state = '') {
    if (!current(record)) return;
    record.status.textContent = message;
    record.status.dataset.preferenceStatus = state;
    record.status.hidden = !message;
  }
  function controls(record) {
    const disabled = record.busy || !record.state || !!record.retry;
    for (const [choice, control] of Object.entries(record.buttons)) {
      control.disabled = disabled || choice === 'clear' && record.state?.has_saved_preference !== true;
      if (choice !== 'clear') control.setAttribute('aria-pressed', String(record.state?.preference === choice));
    }
    record.retryButton.hidden = !record.retry;
    record.retryButton.disabled = record.busy;
    record.refreshButton.hidden = !!record.state && !record.readFailed || !!record.retry;
    record.refreshButton.disabled = record.busy;
    record.host.setAttribute('aria-busy', String(record.busy));
  }
  function begin(record) {
    record.controller?.abort();
    record.controller = new AbortController();
    const controller = record.controller;
    const requestId = ++record.requestId;
    const timeout = setTimeout(() => controller.abort(), 15000);
    record.busy = true;
    controls(record);
    return {signal:controller.signal, current:() => current(record) && requestId === record.requestId,
      finish() { clearTimeout(timeout); if (current(record) && requestId === record.requestId) {record.busy = false; controls(record);} }};
  }
  function unavailable(record, error) {
    if ([403,404,409,410].includes(error.status) && typeof record.onUnavailable === 'function') record.onUnavailable();
  }
  function acceptRedaction(record, state) {
    if (record.clearOnly || !validState(state, {}, true) || state.metadata_available !== false
        || state.source !== record.item.source || state.source_id !== '') return false;
    // A replay is allowed to acknowledge the old write while returning only
    // today's redacted choice. It must not stay in an endless retry or retain
    // formerly authorized names in feedback controls.
    record.retry = null; record.state = null; record.readFailed = true;
    for (const [choice, control] of Object.entries(record.buttons)) {
      control.setAttribute('aria-label', choice === 'clear' ? 'Clear preference for unavailable song'
        : (choice === 'like' ? 'Like' : 'Dislike') + ' unavailable song');
    }
    status(record, 'The request was checked. This song is currently unavailable. Refreshing its details…');
    unavailable(record, {status:409});
    return true;
  }
  async function read(record, refreshing = false) {
    if (!current(record) || record.retry) return;
    const pending = begin(record);
    let superseded = false;
    status(record, 'Checking your choice…');
    try {
      const data = await request(record.payload, JSON.stringify(body(record.payload, 'read', {selection:record.selection})), pending.signal);
      if (!pending.current()) return;
      if (acceptRedaction(record, data.state)) return;
      if (!validState(data.state, record.item, record.clearOnly)) throw new Error('This music preference could not be confirmed.');
      if (!rememberRevision(data.state)) {
        record.state = null; superseded = true;
        status(record, 'This choice changed elsewhere. Loading its current value…');
      } else {
        record.state = data.state;
        record.readFailed = false;
        status(record, data.state.reason || (data.state.preference === 'neutral' ? 'No saved preference.'
          : data.state.preference === 'like' ? 'You like this song.' : 'You prefer fewer suggestions for this song.'));
      }
    } catch (error) {
      if (!pending.current()) return;
      record.state = null;
      record.readFailed = true;
      status(record, error.name === 'AbortError' ? 'Preferences did not respond. Refresh to try again.' : error.message, 'error');
      unavailable(record, error);
    } finally { pending.finish(); }
    if (superseded && current(record)) {
      if (!refreshing) await read(record, true);
      else {record.readFailed = true; status(record, 'The latest choice could not be confirmed. Refresh to try again.', 'error');}
    }
  }
  async function set(record, preference) {
    if (!current(record, true) || record.busy || !record.state || record.clearOnly && preference !== 'clear') return;
    if (!record.retry) {
      if (!['like','dislike','clear'].includes(preference)) return;
      record.retry = {preference, frozen:JSON.stringify(body(record.payload, 'set', {
        selection:record.selection, preference, expected_revision:record.state.revision, request_id:crypto.randomUUID()
      }))};
    }
    const mutation = record.retry;
    const pending = begin(record);
    status(record, 'Saving your choice…');
    let conflict = false;
    try {
      const data = await request(record.payload, mutation.frozen, pending.signal);
      if (!pending.current()) return;
      if (acceptRedaction(record, data.state)) return;
      if (!validState(data.state, record.item, record.clearOnly)) throw new Error('The saved choice could not be confirmed. Retry the same change.');
      record.retry = null;
      record.readFailed = false;
      if (!rememberRevision(data.state)) {
        record.state = null; conflict = true;
        status(record, 'This choice changed elsewhere. Loading its current value…');
      } else {
        record.state = data.state;
        status(record, data.state.reason || (data.state.preference === 'neutral' ? 'Preference cleared.'
          : data.state.preference === 'like' ? 'Saved: you like this song.' : 'Saved: suggest this song less often.'));
        for (const other of records) if (other !== record && current(other) && !other.busy && !other.retry
            && other.item.source === record.item.source && other.item.source_id === record.item.source_id) void read(other);
        window.dispatchEvent(new CustomEvent('vp3:music-preferences-changed', {detail:{
          owner_user_id:record.payload.owner_user_id, agent_id:record.payload.agent_id, conversation_id:record.payload.conversation_id,
          source:record.item.source, source_id:record.item.source_id, preference:data.state.preference, revision:data.state.revision
        }}));
      }
    } catch (error) {
      if (!pending.current()) return;
      if (error.status === 409) {
        record.retry = null;
        record.state = null;
        conflict = true;
        status(record, 'This choice changed elsewhere. Loading its current value…');
      } else if ([401,403,404,410,419,422].includes(error.status)) {
        record.retry = null;
        record.state = null;
        record.readFailed = true;
        status(record, error.message, 'error');
        unavailable(record, error);
      } else {
        status(record, 'The saved choice could not be confirmed. Retry the same change.', 'error');
      }
    } finally { pending.finish(); }
    if (conflict && current(record)) await read(record);
  }
  function attachItem(host, {payload, item, selection, current:isCurrent, onUnavailable, clearOnly = false, state = null} = {}) {
    if (!host || !currentScope(payload) || !validSelection(selection) || !clearOnly && !identity(item)) return null;
    if (attached.has(host)) {
      const existing = attached.get(host);
      if (existing.host.isConnected && existing.host.parentNode === host && current(existing)) return existing;
      existing.controller?.abort(); records.delete(existing); attached.delete(host);
    }
    const box = node('div', 'vp3-music-preferences');
    box.setAttribute('aria-label', 'Music preference');
    const label = text(item.title) || 'this song';
    const choices = node('div', 'vp3-music-preference-choices');
    const record = {host:box, payload, item, selection:JSON.parse(JSON.stringify(selection)), isCurrent, onUnavailable,
      clearOnly, state:null, retry:null, requestId:0, controller:null, busy:false, readFailed:false, generation, buttons:{}};
    for (const [choice, title] of clearOnly ? [['clear','Clear preference']] : [['like','Like'],['dislike','Dislike'],['clear','Clear']]) {
      const control = button(title, () => void set(record, choice));
      control.dataset.musicPreference = choice;
      control.setAttribute('aria-label', (choice === 'clear' ? 'Clear preference for ' : title + ' ') + label);
      if (choice !== 'clear') control.setAttribute('aria-pressed', 'false');
      choices.append(control); record.buttons[choice] = control;
    }
    record.retryButton = button('Retry preference', () => void set(record, record.retry?.preference));
    record.retryButton.dataset.musicPreferenceRetry = '';
    record.refreshButton = button('Refresh preference', () => void read(record));
    record.refreshButton.dataset.musicPreferenceRefresh = '';
    choices.append(record.retryButton, record.refreshButton);
    record.status = node('p', 'vp3-music-preference-status', 'Checking your choice…');
    record.status.setAttribute('role', 'status');
    box.append(choices, record.status); host.append(box);
    records.add(record); attached.set(host, record);
    if (state && validState(state, item, clearOnly) && rememberRevision(state)) {
      record.state = state;
      record.status.textContent = state.reason || '';
      controls(record);
    } else controls(record);
    queueMicrotask(() => {
      for (const old of records) if (!old.host.isConnected) { old.controller?.abort(); records.delete(old); }
      if (current(record) && !record.state) void read(record);
    });
    return record;
  }
  function recordSkip(payload, selection, receipt) {
    if (!currentScope(payload, true) || !validSelection(selection) || !receipt || !['n','y','p'].includes(receipt.kind)
        || typeof receipt.id !== 'string' || !(receipt.kind === 'n' ? /^[1-9][0-9]{0,18}$/.test(receipt.id) : uuidPattern.test(receipt.id))) return;
    const key = [payload.owner_user_id, payload.agent_id, receipt.kind, receipt.id].join(':');
    if (skipped.has(key)) return;
    skipped.add(key);
    const frozen = JSON.stringify(body(payload, 'skip', {selection:JSON.parse(JSON.stringify(selection)),
      receipt:{kind:receipt.kind,id:receipt.id}, request_id:crypto.randomUUID()}));
    const scopeGeneration = generation;
    // Feedback is best effort and never delays the user's next playback. A
    // failed transport may retry only this frozen, idempotent receipt.
    const send = async attempt => {
      if (scopeGeneration !== generation || !currentScope(payload)) return;
      const controller = new AbortController();
      const timeout = setTimeout(() => controller.abort(), 10000);
      try { await request(payload, frozen, controller.signal); }
      catch (error) {
        if (attempt === 0 && (!error.status || error.status >= 500) && scopeGeneration === generation && currentScope(payload)) {
          setTimeout(() => { void send(1); }, 500);
        }
      } finally { clearTimeout(timeout); }
    };
    void send(0);
  }
  function panelCurrent(panel) {
    return savedPanels.has(panel) && panel.host.isConnected && panel.generation === generation && currentScope(panel.payload);
  }
  function validList(list) {
    return list?.protocol === 'vp3_music_preferences_v1' && currentScope(list)
      && Array.isArray(list.items) && list.items.length <= 50 && typeof list.has_more === 'boolean'
      && (list.next_cursor === null || typeof list.next_cursor === 'string' && list.next_cursor.length <= 8192)
      && list.items.every(item => typeof item.reference === 'string' && item.reference.length > 0 && item.reference.length <= 4096
        && ['vp3','youtube'].includes(item.source) && typeof item.title === 'string' && item.title.length <= 300
        && typeof item.artist === 'string' && typeof item.reason === 'string' && typeof item.metadata_available === 'boolean'
        && ['like','dislike','neutral'].includes(item.preference) && item.has_saved_preference === true
        && Number.isSafeInteger(item.revision) && item.revision > 0
        && (!item.metadata_available || identity(item)));
  }
  function renderSaved(panel, data = null, error = '') {
    if (!panelCurrent(panel)) return;
    panel.host.replaceChildren();
    const heading = node('div', 'vp3-music-preferences-heading');
    heading.append(node('h3', '', 'Music preferences'), button('Close preferences', () => {
      panel.controller?.abort(); panel.host.remove(); savedPanels.delete(panel);
    }));
    panel.host.append(heading, node('p', 'vp3-music-preferences-note',
      'Your private choices guide suggestions. Listening and skips are separate signals; a skipped song is not a dislike.'));
    const filter = node('label', 'vp3-music-preferences-filter', 'Source');
    const source = node('select'); source.setAttribute('aria-label', 'Preference source');
    for (const [value, title] of [['both','Both sources'],['vp3','VP3'],['youtube','YouTube']]) {
      const option = node('option', '', title); option.value = value; source.append(option);
    }
    source.value = panel.source;
    source.disabled = panel.loading;
    source.addEventListener('change', () => {
      panel.source = source.value; panel.cursors = [null]; panel.page = 0; void listSaved(panel);
    });
    filter.append(source); panel.host.append(filter);
    if (error || panel.loading) {
      const notice = node('p', 'vp3-music-preference-status', error || 'Loading your saved choices…');
      notice.setAttribute('role', 'status');
      if (error) notice.dataset.preferenceStatus = 'error';
      panel.host.append(notice);
      if (error) panel.host.append(button('Refresh preferences', () => void listSaved(panel)));
      return;
    }
    if (!data) return;
    const coverage = text(data.coverage?.summary, 1000);
    if (coverage) panel.host.append(node('p', 'vp3-music-preferences-note', coverage));
    const list = node('ul', 'vp3-music-preferences-list');
    for (const item of data.items) {
      const row = node('li', 'vp3-music-preferences-saved-item');
      row.dataset.musicPreferenceReference = item.reference;
      row.append(node('strong', '', item.metadata_available ? text(item.title) : 'Unavailable song'));
      if (item.metadata_available && item.artist) row.append(node('span', 'vp3-music-preferences-artist', text(item.artist, 160)));
      row.append(node('span', 'vp3-music-preferences-source', (item.source === 'vp3' ? 'VP3' : 'YouTube')
        + ' · ' + (item.preference === 'like' ? 'Liked' : item.preference === 'dislike' ? 'Disliked' : 'Inactive saved choice')));
      list.append(row);
      attachItem(row, {payload:panel.payload, item, selection:{kind:'preference',reference:item.reference},
        clearOnly:!item.metadata_available, current:() => panelCurrent(panel),
        state:{protocol:data.protocol, owner_user_id:data.owner_user_id, agent_id:data.agent_id,
          conversation_id:data.conversation_id, ...item}, onUnavailable:() => void listSaved(panel)});
    }
    panel.host.append(list);
    if (!data.items.length) panel.host.append(node('p', 'vp3-music-preferences-note', 'No saved choices for this source. Use Like or Dislike on a song or listening-history entry.'));
    const pages = node('nav', 'vp3-music-preferences-pages'); pages.setAttribute('aria-label', 'Saved music preference pages');
    const newer = button('Previous choices', () => { panel.page = Math.max(0, panel.page - 1); void listSaved(panel); });
    newer.disabled = panel.page < 1;
    const older = button('More choices', () => {
      panel.cursors = panel.cursors.slice(0, panel.page + 1); panel.cursors.push(data.next_cursor); panel.page += 1; void listSaved(panel);
    });
    older.disabled = !data.has_more || !data.next_cursor;
    pages.append(newer, older, button('Refresh preferences', () => {panel.cursors = [null]; panel.page = 0; void listSaved(panel);}));
    panel.host.append(pages);
  }
  async function listSaved(panel) {
    if (!panelCurrent(panel)) return;
    panel.controller?.abort(); panel.controller = new AbortController();
    const controller = panel.controller;
    const id = ++panel.requestId;
    const timeout = setTimeout(() => controller.abort(), 15000);
    panel.loading = true; renderSaved(panel);
    try {
      const data = await request(panel.payload, JSON.stringify(body(panel.payload, 'list', {
        source:panel.source, limit:20, cursor:panel.cursors[panel.page] || null
      })), controller.signal);
      if (!panelCurrent(panel) || panel.requestId !== id) return;
      if (!validList(data.preferences)) throw new Error('Your saved preferences could not be confirmed.');
      panel.loading = false; renderSaved(panel, data.preferences);
    } catch (error) {
      if (!panelCurrent(panel) || panel.requestId !== id) return;
      panel.loading = false;
      renderSaved(panel, null, error.name === 'AbortError' ? 'Preferences did not respond. Refresh to try again.' : error.message);
    } finally { clearTimeout(timeout); }
  }
  function openSaved(host, payload) {
    if (!host?.isConnected || !currentScope(payload)) return false;
    for (const old of savedPanels) if (old.container === host || !old.host.isConnected) {
      old.controller?.abort(); old.host.remove(); savedPanels.delete(old);
    }
    const panelHost = node('section', 'vp3-music-preferences-panel');
    panelHost.setAttribute('aria-label', 'Saved music preferences');
    host.append(panelHost);
    const panel = {host:panelHost, container:host, payload:{owner_user_id:payload.owner_user_id,agent_id:payload.agent_id,
      conversation_id:payload.conversation_id}, generation, source:'both', cursors:[null], page:0, requestId:0, loading:false, controller:null};
    savedPanels.add(panel); void listSaved(panel);
    return true;
  }
  function feedbackCurrent(frame) {
    return feedbackCards.has(frame) && frame.host.isConnected && frame.generation === generation && currentScope(frame.payload)
      && chatAttached.get(frame.container) === frame;
  }
  async function readFeedback(frame) {
    if (!feedbackCurrent(frame)) return;
    frame.controller?.abort(); const controller = new AbortController(); frame.controller = controller;
    const requestId = ++frame.requestId;
    const timeout = setTimeout(() => controller.abort(), 15000);
    frame.host.replaceChildren(node('h3', '', 'Music preference'), node('p', 'vp3-music-preference-status', 'Checking your saved choice…'));
    try {
      const data = await request(frame.payload, JSON.stringify(body(frame.payload, 'read', {
        selection:{kind:'preference',reference:frame.payload.reference}
      })), controller.signal);
      if (!feedbackCurrent(frame) || requestId !== frame.requestId) return;
      if (!validState(data.state, {}, true)) throw new Error('This saved music preference could not be confirmed.');
      const state = data.state;
      const available = identity(state) && state.metadata_available !== false;
      const item = {source:state.source, source_id:state.source_id,
        title:available ? text(state.title) || 'Selected song' : 'Unavailable song'};
      frame.host.replaceChildren(node('h3', '', 'Music preference'), node('strong', '', item.title));
      if (available && state.artist) frame.host.append(node('span', 'vp3-music-preferences-artist', text(state.artist, 160)));
      attachItem(frame.host, {payload:frame.payload, item, selection:{kind:'preference',reference:frame.payload.reference},
        state, clearOnly:!available, current:() => feedbackCurrent(frame), onUnavailable:() => void readFeedback(frame)});
      for (const other of records) if (current(other) && !other.busy && !other.retry && other.payload !== frame.payload
          && other.item.source === item.source && other.item.source_id === item.source_id) void read(other);
    } catch (error) {
      if (!feedbackCurrent(frame) || requestId !== frame.requestId) return;
      frame.host.replaceChildren(node('h3', '', 'Music preference'), node('p', 'vp3-music-preference-status',
        error.name === 'AbortError' ? 'Preferences did not respond. Refresh to try again.' : error.message),
      button('Refresh preference', () => void readFeedback(frame)));
    } finally { clearTimeout(timeout); }
  }
  async function attach(host, payload) {
    if (!host?.isConnected || payload?.protocol !== 'vp3_music_preferences_v1' || !currentScope(payload)) return null;
    if (payload.action === 'list') {openSaved(host.querySelector('.message-body') || host, payload); return true;}
    if (payload.action !== 'feedback' || !validSelection({kind:'preference',reference:payload.reference})) return null;
    const old = chatAttached.get(host);
    if (old) {old.controller?.abort();old.host.remove();feedbackCards.delete(old);}
    const box = node('section', 'vp3-music-preferences-panel'); box.setAttribute('aria-label', 'Saved music feedback');
    (host.querySelector('.message-body') || host).append(box);
    const frame = {host:box,container:host,payload,generation,controller:null,requestId:0};
    feedbackCards.add(frame); chatAttached.set(host, frame); await readFeedback(frame); return frame;
  }
  function clear() {
    generation += 1;
    for (const record of records) { record.controller?.abort(); record.host.remove(); }
    records.clear(); skipped.clear(); revisions.clear();
    for (const panel of savedPanels) {panel.controller?.abort();panel.host.remove();}
    savedPanels.clear();
    for (const frame of feedbackCards) {frame.controller?.abort();frame.host.remove();chatAttached.delete(frame.container);}
    feedbackCards.clear();
  }
  for (const event of ['stonefellow:chat-context-changing','vp3:participant-selection-changed','pagehide']) window.addEventListener(event, clear);
  window.addEventListener('vp3:music-preferences-changed', () => {
    for (const panel of savedPanels) if (panelCurrent(panel)) void listSaved(panel);
  });
  const player = document.querySelector('[data-chat-view="player"]');
  const playerHeading = player?.querySelector('.chat-player-page-head > div');
  if (playerHeading && !playerHeading.querySelector('[data-music-preferences-open]')) {
    const open = button('Music preferences', () => openSaved(player, {
      owner_user_id:Number(config.userId),agent_id:Number(config.agentId || 0),conversation_id:conversationId()
    }));
    open.className = 'vp3-music-preferences-launch'; open.dataset.musicPreferencesOpen = ''; playerHeading.append(open);
  }
  window.VP3_MUSIC_PREFERENCES_V1 = Object.freeze({attach, attachItem, recordSkip, openSaved});
})();
