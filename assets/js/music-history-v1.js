(() => {
  'use strict';

  const config = window.VP3_MUSIC_HISTORY_CONFIG_V1 || {};
  const records = new Set();
  const attached = new WeakMap();
  const revisionPattern = /^[a-f0-9]{64}$/;
  let generation = 0;
  const text = (value, limit = 300) => typeof value === 'string' ? value.slice(0, limit) : '';
  const node = (tag, className = '', value = '') => {
    const element = document.createElement(tag);
    if (className) element.className = className;
    if (value) element.textContent = value;
    return element;
  };
  const button = (label, handler, className = '') => {
    const element = node('button', className, label);
    element.type = 'button';
    element.addEventListener('click', handler);
    return element;
  };
  const conversationId = () => Number(window.STONEFELLOW_CHAT_CONTINUITY?.conversationId?.()
    ?? window.STONEFELLOW_CHAT?.initialConversationId ?? 0);
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
  function reference(payload) {
    return payload?.protocol === 'vp3_music_history_v1' && payload.version === 1 && currentScope(payload)
      && Number.isSafeInteger(payload.source_message_id) && payload.source_message_id > 0
      && revisionPattern.test(payload.revision || '');
  }
  function validItem(item) {
    const unavailable = item?.source === '' && item.evidence === 'unavailable' && item.played_at === null
      && item.played_at_local === null && item.play_available === false && item.resume_available === false;
    return item && typeof item.entry_ref === 'string' && item.entry_ref.length > 0 && item.entry_ref.length <= 4096
      && (unavailable || ['vp3', 'youtube'].includes(item.source)
        && typeof item.played_at === 'string' && typeof item.played_at_local === 'string'
        && ['measured', 'started_only'].includes(item.evidence))
      && typeof item.title === 'string' && item.title.length <= 300
      && typeof item.play_available === 'boolean' && typeof item.resume_available === 'boolean'
      && (item.listened_seconds === null || Number.isFinite(item.listened_seconds) && item.listened_seconds >= 0)
      && (item.resume_seconds === null || Number.isFinite(item.resume_seconds) && item.resume_seconds >= 0);
  }
  function validPayload(payload) {
    return reference(payload) && Array.isArray(payload.items) && payload.items.length <= 50
      && payload.items.every(validItem) && payload.filters && typeof payload.filters === 'object'
      && typeof payload.timezone === 'string' && typeof payload.has_more === 'boolean'
      && (payload.next_cursor === null || typeof payload.next_cursor === 'string' && payload.next_cursor.length <= 8192);
  }
  function current(record, action = false) {
    return records.has(record) && record.generation === generation && record.card.isConnected
      && currentScope(record.payload, action);
  }
  function scopeFields(payload) {
    return {agent_id:payload.agent_id, conversation_id:payload.conversation_id,
      source_message_id:payload.source_message_id, revision:payload.revision};
  }
  function sameScope(left, right) {
    return ['owner_user_id', 'agent_id', 'conversation_id'].every(key => left[key] === right?.[key]);
  }
  async function api(record, action, fields, signal) {
    if (!current(record)) throw new Error('This history belongs to another Chat.');
    let endpoint;
    try { endpoint = new URL(config.endpoint, location.href); } catch { throw new Error('Listening history is unavailable.'); }
    if (endpoint.origin !== location.origin) throw new Error('Listening history is unavailable.');
    const response = await fetch(endpoint, {method:'POST', credentials:'same-origin', cache:'no-store', signal,
      headers:{'Content-Type':'application/json', Accept:'application/json'},
      body:JSON.stringify({action, csrf_token:config.csrf, ...scopeFields(record.payload), ...fields})});
    const data = await response.json();
    if (!current(record)) throw new Error('The account or Chat changed.');
    if (!response.ok || data.ok !== true) {
      const error = new Error(text(data.error || data.message) || 'Listening history could not be opened. Try again.');
      error.status = response.status;
      throw error;
    }
    if (!sameScope(record.payload, data.music_history_v1 || data.music_chat_v1 || data)) {
      throw new Error('The history response belongs to another Chat.');
    }
    return data;
  }
  function status(record, message, state = '') {
    if (!current(record)) return;
    record.status.textContent = message;
    record.status.dataset.historyState = state;
    record.status.hidden = !message;
  }
  function duration(value) {
    const seconds = Math.floor(Number(value));
    if (!Number.isFinite(seconds) || seconds < 0) return '';
    const minutes = Math.floor(seconds / 60);
    return (minutes >= 60 ? Math.floor(minutes / 60) + ':' + String(minutes % 60).padStart(2, '0') : minutes)
      + ':' + String(seconds % 60).padStart(2, '0');
  }
  function localTime(value, timezone) {
    try { return new Intl.DateTimeFormat(undefined, {dateStyle:'medium', timeStyle:'medium', timeZone:timezone}).format(new Date(value)); }
    catch { return text(value, 100); }
  }
  function queryFields(payload) {
    const filters = payload.filters || {};
    const size = payload.limit ?? filters.limit;
    const fields = {source:['vp3','youtube'].includes(filters.source) ? filters.source : 'both',
      query:text(filters.query, 200), timezone:text(payload.timezone || filters.timezone, 100),
      limit:Number.isSafeInteger(size) && size >= 1 && size <= 50 ? size : 20};
    if (filters.from_at || filters.until_at) {
      if (filters.from_at) fields.from_at = text(filters.from_at, 100);
      if (filters.until_at) fields.until_at = text(filters.until_at, 100);
    } else {
      if (filters.from) fields.from = text(filters.from, 10);
      if (filters.to) fields.to = text(filters.to, 10);
    }
    return fields;
  }
  function pending(record) {
    record.controller?.abort();
    record.controller = new AbortController();
    const request = ++record.request;
    const controller = record.controller;
    const timeout = setTimeout(() => controller.abort(), 15000);
    const answer = record.message.querySelector('.message-text');
    if (answer) answer.textContent = 'Retained listening history.';
    record.card.setAttribute('aria-busy', 'true');
    return {signal:controller.signal, current:() => current(record) && request === record.request,
      finish() { clearTimeout(timeout); if (current(record) && request === record.request) record.card.removeAttribute('aria-busy'); }};
  }
  function failed(record, error, request) {
    if (!request.current()) return;
    const message = error.name === 'AbortError' ? 'History did not respond in time. Try again.' : error.message;
    const answer = record.message.querySelector('.message-text');
    if (answer) answer.textContent = 'Retained listening history could not be refreshed.';
    // A failed current-authority read must not leave old private metadata or
    // actionable references visible beneath an error message.
    record.card.replaceChildren(node('h3', '', 'Listening history'), node('p', 'vp3-music-history-status', message));
    record.status = record.card.querySelector('p');
    record.status.dataset.historyState = 'error';
    record.card.append(button('Refresh history', () => void query(record, {...queryFields(record.payload)}, {reset:true, fresh:true})));
  }
  async function replace(record, data, request) {
    if (!request.current() || !validPayload(data.music_history_v1)) throw new Error('This history page could not be confirmed.');
    const payload = data.music_history_v1;
    if (payload.source_message_id !== record.payload.source_message_id) {
      const shown = await window.STONEFELLOW_CHAT_CONTINUITY?.renderMusicHistoryResponse?.(data, {current:request.current});
      if (!shown) throw new Error('The Chat changed before the history page arrived.');
      if (request.current()) {
        record.retired = true;
        record.card.querySelectorAll('button,input,select').forEach(element => { element.disabled = true; });
        status(record, 'A newer history view is shown below.');
      }
      return false;
    }
    record.payload = payload;
    const answer = record.message.querySelector('.message-text');
    if (answer && typeof data.answer === 'string') answer.textContent = data.answer;
    render(record);
    return true;
  }
  async function read(record) {
    const request = pending(record);
    status(record, 'Loading retained listening history…');
    try { await replace(record, await api(record, 'read', {}, request.signal), request); }
    catch (error) { failed(record, error, request); }
    finally { request.finish(); }
  }
  async function query(record, fields, {reset = false, cursor = null, direction = 0, fresh = false} = {}) {
    if (!current(record) || record.retired) return;
    const request = pending(record);
    status(record, 'Loading retained listening history…');
    try {
      const data = await api(record, 'query', {...fields, ...(cursor ? {cursor} : {}),
        ...(fresh ? {source_message_id:undefined, revision:undefined} : {})}, request.signal);
      if (!request.current()) return;
      if (reset) { record.cursors = [null]; record.page = 0; }
      else if (direction === 1) { record.cursors = record.cursors.slice(0, record.page + 1); record.cursors.push(cursor); record.page += 1; }
      else if (direction === -1) record.page = Math.max(0, record.page - 1);
      await replace(record, data, request);
    } catch (error) { failed(record, error, request); }
    finally { request.finish(); }
  }
  async function select(record, item, operation) {
    if (!current(record, true) || record.retired || record.card.getAttribute('aria-busy') === 'true'
        || !item.play_available || operation === 'resume' && !item.resume_available) return;
    const request = pending(record);
    const key = item.entry_ref + ':' + operation + ':' + record.payload.revision;
    if (record.retry?.key !== key) record.retry = {key, id:crypto.randomUUID()};
    status(record, operation === 'add_to_playlist' ? 'Opening the selected song for your playlist…' : 'Checking the selected listen…');
    try {
      const data = await api(record, 'selection', {entry_ref:item.entry_ref, operation, request_id:record.retry.id}, request.signal);
      if (!request.current() || !current(record, true)) return;
      const shown = await window.STONEFELLOW_CHAT_CONTINUITY?.renderMusicHistoryResponse?.(data, {
        current:() => request.current() && current(record, true), openPlaylist:operation === 'add_to_playlist'
      });
      if (!shown) throw new Error('The Chat changed before the selected song could be opened.');
      record.retry = null;
      status(record, operation === 'add_to_playlist' ? 'Choose a saved playlist in the selection below.' : 'Selected listen opened below.');
    } catch (error) { failed(record, error, request); }
    finally { request.finish(); }
  }
  function renderCoverage(record) {
    const coverage = record.payload.coverage || {};
    const section = node('section', 'vp3-music-history-coverage');
    section.setAttribute('aria-label', 'Retained history coverage');
    section.append(node('p', '', text(coverage.summary, 1000) || 'Only retained, currently permitted listening records are shown.'));
    const details = node('details'); details.append(node('summary', '', 'Source coverage and retention'));
    const notes = Array.isArray(coverage.notes) ? coverage.notes.filter(note => typeof note === 'string').slice(0, 8) : [];
    for (const source of ['vp3', 'youtube']) {
      const entry = coverage.sources?.[source];
      if (!entry || typeof entry !== 'object') continue;
      if (entry.summary) (entry.status === 'ready' ? details : section).append(node('p', '', (source === 'vp3' ? 'VP3: ' : 'YouTube: ') + text(entry.summary, 1000)));
      if (source === 'youtube' && entry.status === 'ready' && entry.capture_enabled === false) {
        section.append(node('p', '', 'YouTube capture is paused. Retained starts remain visible; Resume is unavailable.'));
      }
      if (Array.isArray(entry.notes)) notes.push(...entry.notes.filter(note => typeof note === 'string').slice(0, 8));
    }
    if (notes.length) {
      const list = node('ul');
      for (const note of [...new Set(notes)].slice(0, 12)) list.append(node('li', '', text(note, 1000)));
      details.append(list);
    }
    section.append(details);
    record.card.append(section);
  }
  function renderFilters(record) {
    const filters = queryFields(record.payload);
    const form = node('form', 'vp3-music-history-filters');
    form.setAttribute('aria-label', 'Filter listening history');
    const field = (name, labelText, type = 'text', choices = null) => {
      const label = node('label', name === 'query' ? 'vp3-music-history-title-filter' : '', labelText);
      const input = node(choices ? 'select' : 'input');
      input.name = name;
      if (choices) for (const [value, title] of choices) {
        const option = node('option', '', title); option.value = String(value); input.append(option);
      } else input.type = type;
      input.value = String(filters[name] || '');
      label.append(input); form.append(label); return input;
    };
    const search = field('query', 'Song or artist'); search.maxLength = 200;
    const source = field('source', 'Source', 'text', [['both','VP3 + YouTube'],['vp3','VP3'],['youtube','YouTube']]);
    const from = field('from', 'From date', 'date');
    const to = field('to', 'Through date', 'date');
    const sizes = [...new Set([5,10,20,50,filters.limit])].sort((left, right) => left - right).map(size => [size, String(size)]);
    const limit = field('limit', 'Entries per page', 'text', sizes);
    let datesEdited = false;
    from.addEventListener('input', () => { datesEdited = true; });
    to.addEventListener('input', () => { datesEdited = true; });
    const actions = node('div', 'vp3-music-history-filter-actions');
    const apply = button('Apply filters', () => {}, 'vp3-music-history-primary'); apply.type = 'submit';
    const reset = button('Clear filters', () => void query(record, {source:'both', query:'', timezone:record.payload.timezone,
      limit:Number(limit.value)}, {reset:true}));
    actions.append(apply, reset); form.append(actions);
    form.addEventListener('submit', event => {
      event.preventDefault();
      const fields = {source:source.value, query:search.value.trim(), timezone:record.payload.timezone, limit:Number(limit.value)};
      if (!datesEdited && (filters.from_at || filters.until_at)) {
        if (filters.from_at) fields.from_at = filters.from_at;
        if (filters.until_at) fields.until_at = filters.until_at;
      } else {
        if (from.value) fields.from = from.value;
        if (to.value) fields.to = to.value;
      }
      void query(record, fields, {reset:true});
    });
    record.card.append(form);
  }
  function render(record) {
    const payload = record.payload;
    record.card.replaceChildren();
    record.card.dataset.historyMessageId = String(payload.source_message_id);
    record.card.dataset.historyRevision = payload.revision;
    const heading = node('header', 'vp3-music-history-heading');
    heading.append(node('h3', '', 'Listening history'), node('span', 'vp3-music-history-private', 'Private history'));
    record.card.append(heading);
    const filters = queryFields(payload);
    const windowLabel = filters.from_at || filters.until_at
      ? 'Time window: ' + (filters.from_at ? localTime(filters.from_at, payload.timezone) : 'earliest retained') + ' to '
        + (filters.until_at ? localTime(filters.until_at, payload.timezone) + ' (end excluded)' : 'now')
      : filters.from || filters.to ? 'Dates: ' + (filters.from || 'earliest retained') + ' through ' + (filters.to || 'today')
        : 'Most recent first';
    record.card.append(node('p', 'vp3-music-history-window', windowLabel + ' · ' + text(payload.timezone, 100)));
    renderCoverage(record);
    renderFilters(record);
    const list = node('ol', 'vp3-music-history-items');
    payload.items.forEach((item, index) => {
      const row = node('li', 'vp3-music-history-item'); row.dataset.historyEntryRef = item.entry_ref;
      const top = node('div', 'vp3-music-history-item-heading');
      top.append(node('span', 'vp3-music-history-ordinal', String(index + 1) + '.'));
      const details = node('div', 'vp3-music-history-details');
      details.append(node('strong', '', text(item.title)));
      if (item.artist) details.append(node('span', 'vp3-music-history-artist', text(item.artist)));
      const meta = node('div', 'vp3-music-history-meta');
      meta.append(node('span', 'vp3-music-history-source', item.source === 'vp3' ? 'VP3' : item.source === 'youtube' ? 'YouTube' : 'Unavailable source'));
      if (item.played_at) {
        const time = node('time', 'vp3-music-history-time', localTime(item.played_at, payload.timezone)); time.dateTime = item.played_at;
        meta.append(time);
      }
      details.append(meta);
      details.append(node('span', 'vp3-music-history-evidence', item.evidence === 'unavailable' ? 'Listening evidence is no longer available.'
        : item.evidence === 'measured' && item.listened_seconds !== null
          ? (item.measurement === 'native_legacy_reported' ? 'Reported listening · ' : 'Measured listening · ') + duration(item.listened_seconds)
          : 'Started only · no measured listening retained'));
      if (!item.play_available) details.append(node('span', 'vp3-music-history-unavailable', 'Currently unavailable'));
      top.append(details); row.append(top);
      const controls = node('div', 'vp3-music-history-actions');
      const play = button('Play again', () => void select(record, item, 'play'), 'vp3-music-history-primary');
      play.setAttribute('aria-label', 'Play listen ' + (index + 1) + ': ' + text(item.title));
      const add = button('Add to playlist', () => void select(record, item, 'add_to_playlist'));
      add.setAttribute('aria-label', 'Add listen ' + (index + 1) + ' to playlist');
      play.disabled = add.disabled = !item.play_available;
      controls.append(play);
      if (item.resume_available && item.resume_seconds !== null && item.resume_seconds > 0) {
        const resume = button('Resume at ' + duration(item.resume_seconds), () => void select(record, item, 'resume'));
        resume.setAttribute('aria-label', 'Resume listen ' + (index + 1) + ' at ' + duration(item.resume_seconds));
        resume.disabled = !item.play_available; controls.append(resume);
      }
      controls.append(add); row.append(controls); list.append(row);
      if (item.play_available) window.VP3_MUSIC_PREFERENCES_V1?.attachItem(row, {
        payload, item, selection:{kind:'history', source_message_id:payload.source_message_id,
          revision:payload.revision, entry_ref:item.entry_ref},
        current:() => current(record) && record.payload === payload && !record.retired,
        onUnavailable:() => void read(record)
      });
    });
    record.card.append(list);
    if (!payload.items.length) record.card.append(node('p', 'vp3-music-history-summary', 'No retained listening entries match these filters.'));
    const pages = node('nav', 'vp3-music-history-pages'); pages.setAttribute('aria-label', 'Listening history pages');
    const newer = button(record.page === 1 ? 'Refresh newest' : 'Newer listens', () => void query(record, queryFields(payload), record.page === 1
      ? {reset:true} : {cursor:record.cursors[record.page - 1], direction:-1}));
    newer.disabled = record.page < 1;
    const older = button('Older listens', () => void query(record, queryFields(payload), {cursor:payload.next_cursor, direction:1}));
    older.disabled = !payload.has_more || !payload.next_cursor;
    pages.append(newer, older, button('Refresh history', () => void query(record, queryFields(payload), {reset:true})),
      node('span', '', payload.items.length + (payload.items.length === 1 ? ' entry on this page' : ' entries on this page')));
    record.card.append(pages);
    record.status = node('p', 'vp3-music-history-status'); record.status.setAttribute('role', 'status'); record.status.hidden = true;
    record.card.append(record.status);
  }
  async function attach(message, payload, {live = false} = {}) {
    if (!message?.isConnected || !reference(payload)
        || Number(message.dataset.chatMessageId) !== payload.source_message_id) return null;
    const existing = attached.get(message);
    if (existing) {
      existing.controller?.abort();
      existing.request += 1;
      existing.payload = payload;
      existing.retry = null;
      if (!live || !validPayload(payload)) await read(existing);
      else {
        existing.card.removeAttribute('aria-busy');
        render(existing);
      }
      return existing;
    }
    const card = node('section', 'vp3-music-history'); card.setAttribute('aria-label', 'Private listening history');
    const record = {message, card, payload, generation, request:0, controller:null, cursors:[null], page:0, retry:null, retired:false};
    record.status = node('p', 'vp3-music-history-status', 'Loading retained listening history…'); card.append(record.status);
    (message.querySelector('.message-body') || message).append(card);
    records.add(record); attached.set(message, record);
    if (live && validPayload(payload)) render(record);
    else await read(record);
    return record;
  }
  function clear() {
    generation += 1;
    for (const record of records) { record.controller?.abort(); record.card.replaceChildren(); record.card.remove(); attached.delete(record.message); }
    records.clear();
  }
  for (const event of ['stonefellow:chat-context-changing', 'vp3:participant-selection-changed', 'pagehide']) window.addEventListener(event, clear);
  const playerHeading = document.querySelector('[data-chat-view="player"] .chat-player-page-head > div');
  if (playerHeading && !playerHeading.querySelector('[data-music-history-open]')) {
    const open = button('Listening history', () => {
      if (!window.STONEFELLOW_CHAT_CONTINUITY?.requestMusicHistory?.()) {
        window.STONEFELLOW_CHAT_CONTINUITY?.showTurnNotice?.('Finish the current Chat turn or keep your draft before opening listening history.');
      }
    }, 'vp3-music-history-launch');
    open.dataset.musicHistoryOpen = '';
    playerHeading.append(open);
  }
  window.VP3_MUSIC_HISTORY_V1 = Object.freeze({attach});
})();
