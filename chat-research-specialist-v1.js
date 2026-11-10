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

  const specialistKey = 'research';
  const label = 'Research Specialist';
  const statuses = {
    queued: 'Waiting for the research worker',
    running: 'Researching public sources',
    failed: 'Research did not finish'
  };
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
  progress.addEventListener('click', () => window.VP3ChatSpecialists?.open(specialistKey, progress));
  inline.append(inlineStatus, ' ', progress);
  composer.prepend(inline);

  let request = null;
  let timer = 0;
  let leaseTimer = 0;
  let suspended = false;
  let shown = '';

  function currentConversation() {
    const chat = window.STONEFELLOW_CHAT;
    const continuity = window.STONEFELLOW_CHAT_CONTINUITY_V87 || window.STONEFELLOW_CHAT_CONTINUITY;
    if (suspended || document.hidden || navigator.onLine === false || thread.dataset.view !== 'chat' ||
        Number(chat?.userId) !== ownerId || Number(chat?.agentId || 0) !== agentId ||
        chat?.workingAttentionBinding !== binding || continuity?.agentId?.() !== agentId ||
        continuity?.isReady?.() !== true || continuity?.isRestoring?.() ||
        window.VP3ParticipantContinuity?.selectedContext?.()) return 0;
    const conversationId = continuity.conversationId();
    return Number.isSafeInteger(conversationId) && conversationId > 0 ? conversationId : 0;
  }

  function show(status) {
    const running = status?.state === 'running';
    const expiresAt = running ? Date.parse(status.lease_expires_at) : 0;
    const valid = Number.isSafeInteger(status?.run_id) && status.run_id > 0 &&
      (status.kind === 'topic' || status.kind === 'artist') && Object.hasOwn(statuses, status.state) &&
      typeof status.subject === 'string' && status.subject.trim() && Array.from(status.subject).length <= 120 &&
      (!running || expiresAt > Date.now());
    const signature = valid ? JSON.stringify([status.run_id, status.state, status.kind, status.subject, expiresAt]) : '';
    if (signature === shown) return;
    shown = signature;
    clearTimeout(leaseTimer);
    progress.hidden = !signature || !running;
    if (!signature || !running) window.VP3ChatSpecialists?.remove(specialistKey);
    if (!signature) {
      inline.hidden = true;
      inlineStatus.textContent = '';
      return;
    }
    const caption = statuses[status.state];
    const description = `${label} · ${caption} · ${status.subject}`;
    if (inlineStatus.textContent !== description) inlineStatus.textContent = description;
    inline.hidden = false;
    if (!running) return;
    window.VP3ChatSpecialists?.update(specialistKey, {label, title: status.subject, status: caption, expiresAt,
      detail: status.kind === 'artist' ? 'Artist research' : 'Topic research'});
    leaseTimer = setTimeout(() => show(null), Math.max(0, expiresAt - Date.now()));
  }

  function clear() {
    clearTimeout(timer);
    request?.abort();
    request = null;
    show(null);
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
        show(null);
        if (response.ok || response.status < 500) delay = 0;
        return;
      }
      show(data.specialist);
      delay = shown ? 5000 : 12000;
    } catch (_error) {
      if (current()) show(null);
    } finally {
      clearTimeout(timeout);
      if (request === controller) {
        if (!current()) show(null);
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
