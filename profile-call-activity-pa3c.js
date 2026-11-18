(() => {
  'use strict';

  if (window.VP3ProfileCallActivityPa3c) return;
  const pendingResearch = new Map();
  let workspaceController = null;
  let legacyController = null;
  let mountSequence = 0;

  function mount(host, options = {}) {
  const embedded = typeof options.context === 'function' && typeof options.read === 'function' && typeof options.mutate === 'function';
  if (!host || host.dataset.ready || (!embedded && (!host.dataset.api || !host.dataset.csrf))) return null;
  const endpoint = embedded ? null : new URL(host.dataset.api, document.baseURI);
  if (endpoint && endpoint.origin !== location.origin) return null;
  if (embedded) { workspaceController?.dispose(); legacyController?.suspend(); }
  const suffix = embedded ? `-workspace-${++mountSequence}` : '';
  const listeners = [];
  const listen = (target, name, handler, settings) => {
    target.addEventListener(name, handler, settings);
    listeners.push(() => target.removeEventListener(name, handler, settings));
  };
  let disposed = false;
  let suspended = false;
  host.dataset.ready = '1';

  const mobile = matchMedia('(max-width: 760px)');
  const stateLabels = {
    connecting: 'Connecting', listening: 'Listening', researching: 'Researching',
    speaking: 'Speaking', paused: 'Assistance off', ended: 'Call ended',
    unavailable: 'Assistance unavailable', reconnecting: embedded ? 'Checking call activity' : 'Reconnecting', updating: 'Updating assistance', loading: 'Loading call activity',
  };
  const requestLabels = {queued: 'Queued', running: 'Researching', complete: 'Complete', failed: 'Could not complete'};
  const identifier = value => /^[A-Za-z0-9_-]{1,128}$/.test(String(value ?? '')) ? String(value) : '';

  host.innerHTML = `
    <button class="vp3-call-launch" type="button" aria-haspopup="dialog" aria-controls="vp3CallActivityDrawer${suffix}" aria-expanded="false">
      <span class="vp3-call-icon" aria-hidden="true">A</span>
    </button>`;
  const launcher = host.firstElementChild;
  launcher.hidden = embedded;
  const launchIcon = launcher.firstElementChild;
  if (embedded) launcher.remove();
  const drawer = document.createElement(embedded ? 'section' : 'dialog');
  drawer.id = 'vp3CallActivityDrawer' + suffix;
  drawer.className = 'vp3-call-activity' + (embedded ? ' vp3-call-activity--embedded' : '');
  drawer.setAttribute('aria-labelledby', 'vp3CallActivityTitle' + suffix);
  drawer.innerHTML = `
    <header class="vp3-call-head">
      <span class="vp3-call-icon" data-call-icon aria-hidden="true">A</span>
      <div class="vp3-call-heading">
        <h2 id="vp3CallActivityTitle${suffix}">Call activity</h2>
        <p data-call-status role="status"></p>
      </div>
      <button class="vp3-call-close" type="button" aria-label="Close call activity" autofocus>×</button>
    </header>
    <div class="vp3-call-tools">
      <label data-call-toggle hidden><input type="checkbox" role="switch"> Agent assistance</label>
      <nav aria-label="Call activity sections">
        <button type="button" data-call-jump="transcript">Transcript</button>
        <button type="button" data-call-jump="research">Research</button>
      </nav>
      <p class="vp3-call-feedback" data-call-toggle-feedback role="status" hidden></p>
    </div>
    <div class="vp3-call-scroll">
      <section data-call-section="transcript" aria-labelledby="vp3CallTranscriptTitle${suffix}">
        <h3 id="vp3CallTranscriptTitle${suffix}">Transcript</h3>
        <p class="vp3-call-empty" data-call-transcript-empty>Transcript will appear here as the Agent transcribes the call.</p>
        <ol data-call-segments class="vp3-call-list"></ol>
        <a data-call-transcript-link hidden>Open in Transcriptions</a>
      </section>
      <section data-call-section="research" aria-labelledby="vp3CallResearchTitle${suffix}">
        <h3 id="vp3CallResearchTitle${suffix}">Research</h3>
        <form class="vp3-call-search" hidden>
          <label for="vp3CallResearchQuery${suffix}">Research a question</label>
          <div><input id="vp3CallResearchQuery${suffix}" type="text" maxlength="500" autocomplete="off" required>
          <button type="submit">Research</button></div>
          <small>The question is sent to web search.</small>
        </form>
        <p class="vp3-call-feedback" data-call-search-feedback role="status" hidden></p>
        <p class="vp3-call-empty" data-call-research-empty>No research for this call yet.</p>
        <ol data-call-results class="vp3-call-list"></ol>
      </section>
    </div>`;
  (embedded ? host : document.body).appendChild(drawer);

  const find = selector => drawer.querySelector(selector);
  const title = find('h2');
  const icon = find('[data-call-icon]');
  const closeButton = find('.vp3-call-close');
  closeButton.hidden = embedded;
  const status = find('[data-call-status]');
  const scroll = find('.vp3-call-scroll');
  const segments = find('[data-call-segments]');
  const transcriptEmpty = find('[data-call-transcript-empty]');
  const transcriptLink = find('[data-call-transcript-link]');
  const results = find('[data-call-results]');
  const researchEmpty = find('[data-call-research-empty]');
  const form = find('.vp3-call-search');
  const query = form.querySelector('input');
  const searchButton = form.querySelector('button');
  const searchFeedback = find('[data-call-search-feedback]');
  const toggleLabel = find('[data-call-toggle]');
  const toggle = toggleLabel.querySelector('input');
  const toggleFeedback = find('[data-call-toggle-feedback]');

  let current = null;
  let timer = 0;
  const retiredCalls = new Set();

  function capture() {
    const raw = options.context?.();
    if (!raw || !Number.isSafeInteger(raw.ownerUserId) || raw.ownerUserId < 1
      || !Number.isSafeInteger(raw.chatAgentId) || raw.chatAgentId < 0
      || !Number.isSafeInteger(raw.chatConversationId) || raw.chatConversationId < 1
      || !Number.isSafeInteger(raw.publicConversationId) || raw.publicConversationId < 1
      || !Number.isSafeInteger(raw.callId) || raw.callId < 1
      || typeof raw.workingAttentionBinding !== 'string' || !raw.workingAttentionBinding) return null;
    return Object.freeze({ownerUserId: raw.ownerUserId, chatAgentId: raw.chatAgentId,
      chatConversationId: raw.chatConversationId, workingAttentionBinding: raw.workingAttentionBinding,
      publicConversationId: raw.publicConversationId, callId: raw.callId});
  }

  function active(session) {
    return !!session && !disposed && !suspended && session === current && host.isConnected
      && (!embedded || JSON.stringify(capture()) === JSON.stringify(session.context));
  }

  function unavailable(error) {
    reset();
    if (embedded && !disposed) {
      host.hidden = false;
      status.textContent = 'Call activity is unavailable for this selection.';
      options.onUnavailable?.(error);
    }
  }

  function dockLauncher() {
    const slot = !mobile.matches && document.getElementById('sfChatSettingsRailSlotV132');
    const target = slot || document.body;
    if (host.parentElement !== target) target.prepend(host);
    return Boolean(slot);
  }

  const railObserver = new MutationObserver(() => {
    if (dockLauncher()) railObserver.disconnect();
  });
  function placeLauncher() {
    railObserver.disconnect();
    if (!dockLauncher() && !mobile.matches) railObserver.observe(document.body, {childList: true, subtree: true});
  }
  if (!embedded) listen(mobile, 'change', placeLauncher);

  function updateText(node, value) {
    if (node.textContent !== value) node.textContent = value;
  }

  function localURL(value) {
    if (typeof value !== 'string' || !value) return null;
    try {
      const url = new URL(value, document.baseURI);
      if (url.origin === location.origin && !url.username && !url.password && /^https?:$/.test(url.protocol)) return url;
    } catch (_) {}
    return null;
  }

  function sourceURL(value) {
    if (typeof value !== 'string' || !/^https?:\/\//i.test(value)) return null;
    try {
      const url = new URL(value);
      if (!url.username && !url.password && /^https?:$/.test(url.protocol)) return url;
    } catch (_) {}
    return null;
  }

  function setAgent(session, agent) {
    const name = typeof agent?.name === 'string' ? agent.name.trim().slice(0, 100) : '';
    if (name) session.name = name;
    if (Object.hasOwn(agent ?? {}, 'avatar_url')) session.avatar = localURL(agent.avatar_url)?.href || '';
    const signature = JSON.stringify([session.name, session.avatar]);
    if (session.iconSignature === signature) return;
    session.iconSignature = signature;
    updateText(title, `${session.name} · Call activity`);
    const initial = session.name.match(/[\p{L}\p{N}]/u)?.[0].toUpperCase() || 'A';
    for (const node of [launchIcon, icon]) {
      node.textContent = initial;
      if (!session.avatar) continue;
      const image = document.createElement('img');
      image.alt = '';
      image.referrerPolicy = 'no-referrer';
      image.addEventListener('error', () => image.remove(), {once: true});
      image.src = session.avatar;
      node.appendChild(image);
    }
  }

  function setState(session, value) {
    const state = session.localEnded ? 'ended' : value;
    session.state = Object.hasOwn(stateLabels, state) ? state : 'unavailable';
    const label = stateLabels[session.state];
    host.dataset.state = session.state;
    drawer.dataset.state = session.state;
    launcher.title = `${session.name} · ${label}`;
    launcher.setAttribute('aria-label', `${session.name} call activity · ${label}`);
    updateText(status, label);
    updateControls(session);
  }

  function updateControls(session) {
    const ended = session.localEnded || session.state === 'ended';
    toggleLabel.hidden = !session.toggleAvailable || !session.assistanceRevision || ended;
    toggle.checked = session.togglePost ? session.toggleTarget : session.assistanceEnabled === true;
    toggle.disabled = Boolean(session.togglePost);
    form.hidden = !session.researchAvailable || ended;
    const saved = session.key ? pendingResearch.get(session.key) : null;
    const busy = Boolean(session.researchPost || session.researchPending || saved?.pending);
    searchButton.disabled = busy || Boolean(session.togglePost) || session.assistanceEnabled === false;
    query.disabled = searchButton.disabled || saved?.uncertain === true;
    searchButton.textContent = saved?.uncertain ? 'Retry research' : 'Research';
    researchEmpty.hidden = results.childElementCount > 0;
    transcriptEmpty.hidden = segments.childElementCount > 0;
    updateText(transcriptEmpty, ended ? 'No transcript was captured for this call.' : 'Transcript will appear here as the Agent transcribes the call.');
  }

  function feedback(node, message = '') {
    updateText(node, message);
    node.hidden = !message;
  }

  function reset(session = current) {
    if (session && session !== current) return;
    clearTimeout(timer);
    if (session) {
      session.read?.abort();
      if (!embedded) { session.researchPost?.abort(); session.togglePost?.abort(); }
    }
    current = null;
    host.hidden = !embedded;
    if (drawer.open) drawer.close();
    segments.replaceChildren();
    results.replaceChildren();
    transcriptLink.hidden = true;
    transcriptLink.removeAttribute('href');
    form.reset();
    form.hidden = true;
    toggleLabel.hidden = true;
    feedback(searchFeedback);
    feedback(toggleFeedback);
    scroll.scrollTop = 0;
  }

  function elapsed(milliseconds) {
    const seconds = Math.floor(milliseconds / 1000);
    const hours = Math.floor(seconds / 3600);
    const min = Math.floor(seconds / 60) % 60;
    const sec = String(seconds % 60).padStart(2, '0');
    return hours ? `${hours}:${String(min).padStart(2, '0')}:${sec}` : `${min}:${sec}`;
  }

  function appendSegments(session, rows) {
    if (!Array.isArray(rows)) return;
    for (const row of rows) {
      const id = identifier(row?.id);
      const text = typeof row?.text === 'string' ? row.text.trim() : '';
      if (!id || !text || session.segments.has(id)) continue;
      session.segments.add(id);
      const entry = document.createElement('li');
      const meta = document.createElement('div');
      meta.className = 'vp3-call-segment-meta';
      const speaker = document.createElement('strong');
      speaker.textContent = typeof row.speaker === 'string' && row.speaker.trim() ? row.speaker : 'Speaker';
      meta.appendChild(speaker);
      const ms = Number(row.start_ms);
      if (row.start_ms != null && Number.isFinite(ms) && ms >= 0) {
        const stamp = document.createElement('time');
        stamp.dateTime = `PT${Math.floor(ms / 1000)}S`;
        stamp.textContent = elapsed(ms);
        meta.appendChild(stamp);
      }
      entry.dataset.start = Number.isFinite(ms) && ms >= 0 ? String(ms) : String(Number.MAX_SAFE_INTEGER);
      const paragraph = document.createElement('p');
      paragraph.textContent = text;
      entry.append(meta, paragraph);
      let previous = segments.lastElementChild;
      while (previous && Number(previous.dataset.start) > Number(entry.dataset.start)) previous = previous.previousElementSibling;
      if (previous) previous.after(entry);
      else segments.prepend(entry);
    }
  }

  function renderResearch(session, rows) {
    if (!Array.isArray(rows)) return;
    session.researchPending = false;
    for (const row of rows) {
      const id = identifier(row?.id);
      if (!id || !Object.hasOwn(requestLabels, row.status)) continue;
      if (session.draft?.text === row.query) settleResearch(session, id);
      if (row.status === 'queued' || row.status === 'running') session.researchPending = true;
      const signature = JSON.stringify(row);
      if (session.research.get(id)?.signature === signature) continue;
      const entry = document.createElement('li');
      const header = document.createElement('div');
      header.className = 'vp3-call-segment-meta';
      const label = document.createElement('strong');
      label.textContent = typeof row.query === 'string' ? row.query : 'Research request';
      const badge = document.createElement('span');
      badge.textContent = requestLabels[row.status];
      header.append(label, badge);
      entry.appendChild(header);
      if (typeof row.summary === 'string' && row.summary) {
        const summary = document.createElement('p');
        summary.textContent = row.summary;
        entry.appendChild(summary);
      }
      const sourceList = document.createElement('ul');
      for (const source of Array.isArray(row.sources) ? row.sources : []) {
        const url = sourceURL(source?.url);
        if (!url) continue;
        const item = document.createElement('li');
        const link = document.createElement('a');
        link.href = url.href;
        link.target = '_blank';
        link.rel = 'noopener noreferrer';
        link.textContent = typeof source.title === 'string' && source.title.trim() ? source.title : url.hostname;
        item.appendChild(link);
        sourceList.appendChild(item);
      }
      if (sourceList.childElementCount) entry.appendChild(sourceList);
      const previous = session.research.get(id)?.entry;
      if (previous) previous.replaceWith(entry);
      else results.appendChild(entry);
      session.research.set(id, {entry, signature});
    }
  }

  function settleResearch(session, id) {
    if (session.key && pendingResearch.get(session.key)?.id === id) pendingResearch.delete(session.key);
    if (session !== current) return;
    if (session.draft?.id !== id) return;
    if (query.value.trim() === session.draft.text) query.value = '';
    session.draft = null;
    feedback(searchFeedback);
  }

  async function request(url, controller, payload) {
    const timeout = setTimeout(() => controller.abort(), payload?.action === 'research' ? 65000 : 12000);
    try {
      const options = {credentials: 'same-origin', cache: 'no-store', redirect: 'error', signal: controller.signal};
      if (payload) {
        options.method = 'POST';
        options.headers = {'Content-Type': 'application/json', 'X-CSRF-Token': host.dataset.csrf};
        options.body = JSON.stringify({...payload, csrf: host.dataset.csrf});
      }
      const response = await fetch(url, options);
      const data = await response.json().catch(() => null);
      if (!response.ok || !data?.ok) {
        const message = response.status >= 400 && response.status < 500 && typeof data?.error === 'string'
          ? data.error.slice(0, 240) : '';
        throw Object.assign(new Error(message), {status: response.status});
      }
      return data;
    } finally {
      clearTimeout(timeout);
    }
  }

  async function workspaceRequest(session, controller, operation = '', input = {}) {
    const timeout = setTimeout(() => controller.abort(), operation === 'research' ? 65000 : 12000);
    try {
      const data = operation
        ? await options.mutate({context: session.context, operation, input: Object.freeze({...input}), signal: controller.signal})
        : await options.read({context: session.context, after: Number(session.cursor), signal: controller.signal});
      if (!data || data.call_id !== session.context.callId || data.public_conversation_id !== session.context.publicConversationId
        || (operation && data.operation !== operation)) throw Object.assign(new Error('Call activity selection changed.'),{code:'PROFILE_ACTIVITY_SELECTION_CHANGED'});
      if (operation) {
        if (!data.result || typeof data.result !== 'object' || Array.isArray(data.result)) throw new Error('Call action could not be confirmed.');
        if (operation === 'research' && !['queued', 'running', 'complete', 'failed'].includes(data.result.research_status)) throw new Error('Research could not be confirmed.');
        return data.result;
      }
      const value = data.activity;
      if (!value || !/^[a-f0-9]{32}$/.test(value.call_id)
        || session.activityId && session.activityId !== value.call_id
        || !Object.hasOwn(stateLabels, value.state) || !Array.isArray(value.segments) || value.segments.length > 100
        || !Array.isArray(value.research) || value.research.length > 12
        || !Number.isSafeInteger(value.cursor) || value.cursor < 0 || typeof value.has_more !== 'boolean'
        || typeof value.assistance_enabled !== 'boolean' || typeof value.assistance_toggle_available !== 'boolean'
        || !Number.isSafeInteger(value.assistance_revision) || value.assistance_revision < 1
        || typeof value.research_available !== 'boolean') throw Object.assign(new Error('Call activity identity changed.'),{code:'PROFILE_ACTIVITY_SELECTION_CHANGED'});
      session.activityId = value.call_id;
      return value;
    } finally { clearTimeout(timeout); }
  }

  function deny(session, error) {
    if (![401, 403, 404, 410].includes(error?.status) && error?.code !== 'PROFILE_ACTIVITY_SELECTION_CHANGED') return false;
    retiredCalls.add(session.id);
    if (embedded) unavailable(error); else reset(session);
    return true;
  }

  function schedule(session, delay = 0) {
    if (!active(session)) return;
    clearTimeout(timer);
    if (!document.hidden && !session.read && !session.togglePost) timer = setTimeout(() => refresh(session), delay);
  }

  async function refresh(session) {
    if (!active(session)) { if (embedded && session === current && !suspended && !disposed) unavailable(); return; }
    if (session.read || session.togglePost) return;
    const controller = new AbortController();
    session.read = controller;
    let delay = null;
    try {
      let data;
      if (embedded) data = await workspaceRequest(session, controller);
      else {
        const url = new URL(endpoint);
        url.searchParams.set('call_id', session.id);
        url.searchParams.set('after', session.cursor);
        data = await request(url, controller);
      }
      if (!active(session) || session.read !== controller || controller.signal.aborted) return;
      if (identifier(data.call_id) !== (embedded ? session.activityId : session.id)) throw new Error('Call activity changed.');
      session.failures = 0;
      setAgent(session, data.agent);
      if (typeof data.research_available === 'boolean') session.researchAvailable = data.research_available;
      if (typeof data.assistance_enabled === 'boolean') session.assistanceEnabled = data.assistance_enabled;
      session.assistanceRevision = Number.isSafeInteger(data.assistance_revision) && data.assistance_revision > 0 ? data.assistance_revision : 0;
      session.toggleAvailable = data.assistance_toggle_available === true;
      feedback(toggleFeedback);
      let discarded = false;
      if (Object.hasOwn(data, 'transcript_url')) {
        const link = localURL(data.transcript_url);
        transcriptLink.hidden = !link;
        if (link) transcriptLink.href = link.href;
        else {
          discarded = transcriptLink.hasAttribute('href');
          transcriptLink.removeAttribute('href');
          if (discarded) {
            segments.replaceChildren();
            session.segments.clear();
            session.cursor = '0';
          }
        }
      }
      if (!discarded) appendSegments(session, data.segments);
      renderResearch(session, data.research);
      const next = discarded ? '' : identifier(data.cursor);
      const more = data.has_more === true && next && next !== session.cursor;
      if (next) session.cursor = next;
      if (data.state === 'ended') session.localEnded = true;
      setState(session, session.assistanceEnabled === false && data.state !== 'unavailable' ? 'paused' : data.state);
      if (more) delay = 0;
      else if (!session.localEnded || session.researchPending || session.researchPost) delay = 2400;
    } catch (error) {
      if (!active(session) || session.read !== controller) return;
      if (deny(session, error)) return;
      session.failures += 1;
      setState(session, 'reconnecting');
      if (!session.localEnded || session.failures < 3) delay = 5000;
    } finally {
      if (embedded && session === current && !disposed && !suspended && !active(session)) unavailable();
      if (session === current && session.read === controller) {
        session.read = null;
        if (delay !== null) schedule(session, delay);
      }
    }
  }

  function receiveCall(call) {
    if (call?.clear === true) { reset(); return; }
    const id = identifier(call?.id);
    if (!id || retiredCalls.has(id)) return;
    if (call.status === 'ended') {
      if (current?.id === id && !current.localEnded) {
        current.localEnded = true;
        setState(current, 'ended');
        schedule(current);
      }
      return;
    }
    if (call.status !== 'connecting' && call.status !== 'active') return;
    if (current?.id === id) return;
    if (current) retiredCalls.add(current.id);
    reset();
    if (call.assisted !== true) return;
    current = {
      id, callId: Number.isSafeInteger(call.callId) ? call.callId : 0,
      publicConversationId: Number.isSafeInteger(call.conversationId) ? call.conversationId : 0,
      name: 'Profile Agent', avatar: '', cursor: '0', failures: 0,
      segments: new Set(), research: new Map(),
    };
    setAgent(current, call.agent);
    setState(current, 'connecting');
    host.hidden = false;
    placeLauncher();
    schedule(current);
  }
  function select(callId) {
    if (!embedded || disposed) return false;
    const context = capture();
    if (!context || context.callId !== callId) { unavailable(); return false; }
    if (current && JSON.stringify(current.context) === JSON.stringify(context)) { schedule(current); return true; }
    reset();
    const key = [context.ownerUserId, context.chatAgentId, context.chatConversationId, context.publicConversationId, context.callId].join(':');
    current = {id: String(callId), context, key, name: 'Profile Agent', avatar: '', cursor: '0', failures: 0,
      segments: new Set(), research: new Map(), draft: pendingResearch.get(key) || null};
    if (current.draft) query.value = current.draft.text;
    setAgent(current, {});
    setState(current, 'loading');
    host.hidden = false;
    schedule(current);
    return true;
  }
  if (!embedded) {
    listen(window, 'vp3:profile-call-assistance', event => receiveCall(event.detail));
    receiveCall(window.VP3_PROFILE_CALL_OWNER_STATE);
  }

  listen(launcher, 'click', () => {
    if (!current) return;
    if (current.callId > 0 && current.publicConversationId > 0
      && window.VP3AgentActivityHub?.openProfile?.({tab: 'calls', callId: current.callId, publicConversationId: current.publicConversationId}, launcher) === true) return;
    if (drawer.open) { drawer.close(); return; }
    if (mobile.matches) drawer.showModal();
    else drawer.show();
    launcher.setAttribute('aria-expanded', 'true');
    schedule(current);
  });
  listen(closeButton, 'click', () => drawer.close?.());
  listen(window, 'keydown', event => {
    if (event.key !== 'Escape' || event.defaultPrevented || !drawer.open) return;
    const focusedDialog = event.target instanceof Element && event.target.closest('dialog,[role="dialog"]');
    if (focusedDialog && focusedDialog !== drawer) return;
    event.preventDefault();
    drawer.close();
  });
  listen(drawer, 'close', () => {
    launcher.setAttribute('aria-expanded', 'false');
    if (!host.hidden && !suspended && !disposed) launcher.focus({preventScroll: true});
  });
  listen(find('nav'), 'click', event => {
    const section = event.target.dataset.callJump;
    const target = section && find(`[data-call-section="${section}"]`);
    if (target) {
      if (embedded) target.scrollIntoView?.({block: 'nearest', behavior: 'auto'});
      else scroll.scrollTop = target.offsetTop - 12;
    }
  });

  listen(form, 'submit', async event => {
    event.preventDefault();
    const session = current;
    const text = query.value.trim();
    if (!session || !active(session) || searchButton.disabled || form.hidden || !text) return;
    const controller = new AbortController();
    session.researchPost = controller;
    if (session.draft?.text !== text) {
      const bytes = crypto.getRandomValues(new Uint8Array(16));
      if (embedded && !pendingResearch.has(session.key) && pendingResearch.size >= 32) {
        session.researchPost = null;
        feedback(searchFeedback, 'Check your earlier research requests before starting another.');
        return;
      }
      session.draft = {text, id: Array.from(bytes, n => n.toString(16).padStart(2, '0')).join('')};
    }
    const attempt = session.draft;
    if (embedded) { attempt.pending = true; attempt.uncertain = false; pendingResearch.set(session.key, attempt); }
    const requestId = session.draft.id;
    updateControls(session);
    feedback(searchFeedback, 'Research requested.');
    schedule(session);
    try {
      if (embedded) await workspaceRequest(session, controller, 'research', {query: attempt.text, request_id: requestId});
      else await request(endpoint, controller, {action: 'research', call_id: session.id, query: text, request_id: requestId});
      settleResearch(session, requestId);
    } catch (error) {
      if (embedded) attempt.uncertain = true;
      if (active(session) && !deny(session, error) && session.draft?.id === requestId) {
        feedback(searchFeedback, error.status && error.message || 'The request could not be confirmed. Try again to check the same request.');
      }
    } finally {
      if (embedded) attempt.pending = false;
      if (embedded && session === current && !disposed && !suspended && !active(session)) unavailable();
      if (session === current) {
        session.researchPost = null;
        updateControls(session);
        schedule(session);
      }
    }
  });

  listen(toggle, 'change', async () => {
    const session = current;
    if (!session || !active(session) || toggleLabel.hidden || session.togglePost) return;
    const enabled = toggle.checked;
    const controller = new AbortController();
    session.togglePost = controller;
    session.toggleTarget = enabled;
    session.read?.abort();
    session.read = null;
    clearTimeout(timer);
    setState(session, 'updating');
    feedback(toggleFeedback, 'Updating Agent assistance…');
    try {
      const data = embedded
        ? await workspaceRequest(session, controller, 'set_assistance', {enabled, assistance_revision: session.assistanceRevision})
        : await request(endpoint, controller, {action: 'set_assistance', call_id: session.id, enabled, assistance_revision: session.assistanceRevision});
      if (!active(session)) return;
      if (typeof data.assistance_enabled !== 'boolean' || !Number.isSafeInteger(data.assistance_revision) || data.assistance_revision <= 0) {
        throw new Error('Missing assistance state.');
      }
      session.assistanceEnabled = data.assistance_enabled;
      session.assistanceRevision = data.assistance_revision;
      setState(session, session.assistanceEnabled ? 'connecting' : 'paused');
      feedback(toggleFeedback);
    } catch (error) {
      if (active(session) && !deny(session, error)) feedback(toggleFeedback, 'The change could not be confirmed. Checking call status.');
    } finally {
      if (embedded && session === current && !disposed && !suspended && !active(session)) unavailable();
      if (session === current) {
        session.togglePost = null;
        updateControls(session);
        schedule(session);
      }
    }
  });

  listen(document, 'visibilitychange', () => {
    if (current && (!current.localEnded || drawer.open)) schedule(current);
  });
  listen(window, 'pagehide', () => {
    railObserver.disconnect();
    reset();
  });
  listen(window, 'pageshow', event => {
    if (event.persisted && !embedded) receiveCall(window.VP3_PROFILE_CALL_OWNER_STATE);
  });
  if (embedded) listen(window, 'stonefellow:chat-context-changing', () => unavailable());

  const controller = Object.freeze({select,
    suspend() { suspended = true; clearTimeout(timer); current?.read?.abort(); if (current) current.read = null; if (drawer.open) drawer.close(); },
    resume() { if (disposed) return; suspended = false; if (current) schedule(current); },
    dispose() {
      if (disposed) return;
      disposed = true; reset(); railObserver.disconnect();
      for (const remove of listeners) remove();
      drawer.remove(); host.replaceChildren(); delete host.dataset.ready;
      if (workspaceController === controller) { workspaceController = null; legacyController?.resume(); }
    },
  });
  if (embedded) { workspaceController = controller; const context = capture(); if (context) select(context.callId); else unavailable(); }
  return controller;
  }

  window.VP3ProfileCallActivityPa3c = Object.freeze({mount});
  const legacyHost = document.getElementById('profileCallActivityPa3c');
  if (legacyHost) legacyController = mount(legacyHost);
})();
