(() => {
  'use strict';
  const cfg = window.VP3_CHAT_RESEARCH_SPECIALIST_V1;
  const binding = window.STONEFELLOW_CHAT?.workingAttentionBinding;
  const ownerId = Number(cfg?.ownerKey);
  const agentId = cfg?.agentId;
  const thread = document.getElementById('chatThread');
  const composer = document.getElementById('chatComposerShell');
  if (!cfg?.endpoint || !binding || !Number.isSafeInteger(ownerId) || ownerId < 1 ||
      !Number.isSafeInteger(agentId) || agentId < 0 || !thread || !composer) return;

  let endpoint;
  try { endpoint = new URL(cfg.endpoint, location.href); } catch (_error) { return; }
  if (endpoint.origin !== location.origin || endpoint.username || endpoint.password) return;

  const label = 'Research Specialist';
  const statuses = {
    queued: 'Waiting for the research worker',
    running: 'Researching public sources',
    ready: 'Research is ready to report',
    completed: 'Research is ready in Chat',
    failed: 'Research did not finish'
  };
  let assignments = new Map();
  const keyFor = id => `research:${id}`;
  const inline = document.createElement('div');
  inline.className = 'vp3-chat-research-specialist-status';
  inline.hidden = true;
  const inlineStatus = document.createElement('span');
  inlineStatus.setAttribute('role', 'status');
  const progress = document.createElement('button');
  progress.type = 'button';
  progress.textContent = 'View progress';
  progress.setAttribute('aria-label', 'View research progress');
  progress.setAttribute('aria-haspopup', 'dialog');
  progress.setAttribute('aria-expanded', 'false');
  progress.hidden = true;
  progress.addEventListener('click', () => {
    if (fallbackKey) window.VP3ChatSpecialists?.open(fallbackKey, progress);
  });
  inline.append(inlineStatus, ' ', progress);
  composer.prepend(inline);

  let request = null;
  let timer = 0;
  let leaseTimer = 0;
  let suspended = false;
  let fallbackKey = '';

  const chatContinuity = () => window.STONEFELLOW_CHAT_CONTINUITY_V87 || window.STONEFELLOW_CHAT_CONTINUITY;
  function currentConversation() {
    const chat = window.STONEFELLOW_CHAT;
    const continuity = chatContinuity();
    if (suspended || document.hidden || navigator.onLine === false || thread.dataset.view !== 'chat' ||
        Number(chat?.userId) !== ownerId || Number(chat?.agentId || 0) !== agentId ||
        chat?.workingAttentionBinding !== binding || continuity?.agentId?.() !== agentId ||
        continuity?.isReady?.() !== true || continuity?.isRestoring?.() ||
        window.VP3ParticipantContinuity?.selectedContext?.()) return 0;
    const conversationId = continuity.conversationId();
    return Number.isSafeInteger(conversationId) && conversationId > 0 ? conversationId : 0;
  }

  function showResult(conversationId, messageId) {
    if (currentConversation() !== conversationId) return;
    const row = thread.querySelector(`[data-chat-message-id="${messageId}"][data-chat-conversation-id="${conversationId}"]`);
    if (row) row.scrollIntoView({block:'center'});
    else {
      const chat = chatContinuity();
      if (!chat?.isBusy?.()) void chat?.openConversation?.(conversationId,{messageId})?.catch(() => {});
    }
  }

  function show(records, conversationId) {
    const now = Date.now();
    const next = new Map();
    for (const status of records) {
      const id = status?.run_id;
      if (!Number.isSafeInteger(id) || id < 1 || next.has(id) ||
          (status.kind !== 'topic' && status.kind !== 'artist') || !Object.hasOwn(statuses, status.state) ||
          typeof status.subject !== 'string' || !status.subject.trim() || Array.from(status.subject).length > 120) continue;
      const expiresAt = status.state === 'running' ? Date.parse(status.lease_expires_at) :
        status.expires_at ? Date.parse(status.expires_at) : 0;
      if ((status.state === 'running' || status.expires_at) && !(expiresAt > now)) continue;
      const messageId = status.state === 'completed' ? status.message_id : 0;
      if (status.state === 'completed' && (!assignments.has(id) || !Number.isSafeInteger(messageId) || messageId < 1)) continue;
      const signature = JSON.stringify([status.state,status.kind,status.subject,expiresAt,messageId]);
      const row = {status,expiresAt,signature};
      next.set(id,row);
      if (assignments.get(id)?.signature === signature) continue;
      window.VP3ChatSpecialists?.update(keyFor(id), {
        type:'research', state:status.state, label, title:status.subject, status:statuses[status.state], expiresAt,
        desktop:status.state === 'running',
        detail:status.kind === 'artist' ? 'Artist research' : 'Topic research',
        ...(messageId ? {viewLabel:'View result', onView:() => showResult(conversationId,messageId)} : {})
      });
    }
    for (const id of assignments.keys()) {
      if (!next.has(id)) window.VP3ChatSpecialists?.remove(keyFor(id));
    }
    assignments = next;
    clearTimeout(leaseTimer);
    const deadline = Math.min(...[...next.values()].map(row => row.expiresAt || Infinity));
    if (Number.isFinite(deadline)) leaseTimer = setTimeout(() => show([...next.values()].map(row => row.status),conversationId),
      Math.max(0,deadline-Date.now()));
    const fallback = [...next.values()].find(row => ['queued','running','failed'].includes(row.status.state))?.status;
    fallbackKey = fallback ? keyFor(fallback.run_id) : '';
    progress.hidden = fallback?.state !== 'running';
    const description = fallback ? `${label} · ${statuses[fallback.state]} · ${fallback.subject}` : '';
    if (inlineStatus.textContent !== description) inlineStatus.textContent = description;
    inline.hidden = !fallback;
  }

  function clear() {
    clearTimeout(timer);
    request?.abort();
    request = null;
    show([]);
  }

  async function read() {
    const conversationId = currentConversation();
    if (!conversationId) { clear(); return; }
    if (request) return;
    const controller = new AbortController();
    request = controller;
    const timeout = setTimeout(() => controller.abort(), 10000);
    const current = () => request === controller && currentConversation() === conversationId;
    const url = new URL(endpoint);
    url.searchParams.set('agent_id', String(agentId));
    url.searchParams.set('conversation_id', String(conversationId));
    let delay = 20000;
    try {
      const response = await fetch(url, {method: 'GET', credentials: 'same-origin', cache: 'no-store',
        redirect: 'error', headers: {'X-VP3-Working-Attention': binding}, signal: controller.signal});
      const data = await response.json();
      if (!current()) return;
      if (!response.ok || data?.ok !== true || data.owner_user_id !== ownerId || data.agent_id !== agentId ||
          data.conversation_id !== conversationId || data.working_attention_binding !== binding) {
        show([]);
        if (response.ok || response.status < 500) delay = 0;
        return;
      }
      show(Array.isArray(data.specialists) ? data.specialists : [data.specialist],conversationId);
      delay = assignments.size ? 5000 : 12000;
    } catch (_error) {
      if (current()) show([]);
    } finally {
      clearTimeout(timeout);
      if (request === controller) {
        if (!current()) show([]);
        else if (delay) timer = setTimeout(read, delay);
        request = null;
      }
    }
  }

  function refresh() {
    clearTimeout(timer);
    if (!currentConversation()) { clear(); return; }
    if (!request) timer = setTimeout(read, 0);
  }

  window.addEventListener('stonefellow:chat-context-changing', clear);
  window.addEventListener('vp3:participant-selection-changed', refresh);
  window.addEventListener('vp3:participant-task-context', refresh);
  window.addEventListener('vp3:chat-context-ready', refresh);
  window.addEventListener('vp3:chat-canvas-view-changed', refresh);
  window.addEventListener('vp3:chat-history-updated', refresh);
  window.addEventListener('focus', refresh);
  window.addEventListener('online', refresh);
  window.addEventListener('offline', clear);
  document.addEventListener('visibilitychange', refresh);
  window.addEventListener('pagehide', () => { suspended = true; clear(); });
  window.addEventListener('pageshow', () => { suspended = false; refresh(); });
  refresh();
})();
