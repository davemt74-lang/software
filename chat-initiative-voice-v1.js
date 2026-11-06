/* Optional speech for one existing, verified short Chat check-in. No timer,
 * notification, model call, second conversation, or browser-persisted replay. */
(() => {
  'use strict';
  if (window.VP3InitiativeVoice) return;
  const page = window.STONEFELLOW_CHAT;
  const TAB = /^[a-f0-9]{32}$/, TOKEN = /^[a-f0-9]{64}$/;
  let active = null, inFlight = null, epoch = 0, voiceEnabled = page?.agentVoiceEnabled !== false;
  const chat = () => window.STONEFELLOW_CHAT_CONTINUITY_V87 || window.STONEFELLOW_CHAT_CONTINUITY;
  const packet = () => window.VP3LiveContext?.snapshot?.() || null;
  function visualKey(value) {
    const safe = window.VP3LiveContext?.semantic?.(value) ?? value;
    if (!safe) return 'null';
    const copy = JSON.parse(JSON.stringify(safe)); delete copy.microphone;
    return JSON.stringify(copy);
  }
  function context(given = {}) {
    const current = chat(), cfg = window.STONEFELLOW_CHAT;
    if (!page || cfg !== page || !current?.isReady?.() || current.isRestoring?.()) return null;
    const source = packet();
    const result = {
      userId:Number(given.userId ?? page.userId),agentId:Number(given.agentId ?? page.agentId ?? 0),
      conversationId:Number(given.conversationId ?? current.conversationId?.()),
      workingAttentionBinding:String(given.workingAttentionBinding ?? page.workingAttentionBinding ?? ''),
      activityTab:String(given.activityTab || source?.activity_tab || window.StonefellowAgentActivity?.activityTab?.() || ''),
      endpoint:String(given.endpoint || window.VP3_COGNITIVE_PRESENTATION_V510?.endpoint || ''),
      csrf:String(given.csrf || window.VP3_COGNITIVE_PRESENTATION_V510?.csrf || page.csrf || ''),
      liveSession:source?.session_id || null,visual:visualKey(source)
    };
    if (!Number.isSafeInteger(result.userId) || result.userId < 1 || result.userId !== Number(page.userId)
      || !Number.isSafeInteger(result.agentId) || result.agentId < 0 || result.agentId !== Number(current.agentId?.() ?? page.agentId ?? 0)
      || !Number.isSafeInteger(result.conversationId) || result.conversationId < 1 || result.conversationId !== Number(current.conversationId?.())
      || result.workingAttentionBinding !== String(page.workingAttentionBinding || '') || !result.workingAttentionBinding
      || !TAB.test(result.activityTab) || !result.endpoint || !result.csrf) return null;
    return result;
  }
  function safe(record) {
    const ctx = record.ctx, current = chat(), cfg = window.STONEFELLOW_CHAT;
    if (!voiceEnabled || cfg !== page || document.hidden || !document.hasFocus() || !current?.isReady?.() || current.isRestoring?.()
      || Number(cfg?.userId) !== ctx.userId || Number(current.agentId?.() ?? cfg?.agentId ?? 0) !== ctx.agentId
      || Number(current.conversationId?.()) !== ctx.conversationId || String(cfg?.workingAttentionBinding || '') !== ctx.workingAttentionBinding
      || String(document.getElementById('chatInput')?.value || '').trim() || current.isBusy?.()
      || window.VP3LiveContext?.voiceDeferral?.()?.defer) return false;
    const state = String(document.body?.dataset.stonefellowAgentState || '');
    if (['thinking','processing'].includes(state) || state === 'speaking' && !record.started) return false;
    const source = packet();
    return (source?.session_id || null) === (record.liveSession ?? ctx.liveSession) && visualKey(source) === ctx.visual;
  }
  function matches(data, ctx) {
    return data?.ok === true && data.owner_user_id === ctx.userId && data.agent_id === ctx.agentId
      && data.conversation_id === ctx.conversationId && data.working_attention_binding === ctx.workingAttentionBinding
      && data.activity_tab === ctx.activityTab && data.live_session_id === ctx.liveSession;
  }
  async function request(record, action, extra = {}, finish = false) {
    const c = record.ctx;
    const response = await fetch(c.endpoint, {method:'POST',credentials:'same-origin',cache:'no-store',
      headers:{'Content-Type':'application/json','X-VP3-Working-Attention':c.workingAttentionBinding},
      body:JSON.stringify({action,csrf_token:c.csrf,agent_id:c.agentId,conversation_id:c.conversationId,
        activity_tab:c.activityTab,working_attention_binding:c.workingAttentionBinding,...extra}),
      ...(finish ? {keepalive:true} : {signal:record.controller.signal})});
    const data = await response.json();
    if (!response.ok || data?.ok !== true) { const error = new Error('Voice delivery unavailable'); error.status = response.status; throw error; }
    return data;
  }
  async function finish(record, outcome) {
    if (record.finished || !record.token) return;
    record.finished = true;
    try { await request(record, 'voice_finish', {run_id:record.run,message_id:record.message,request_id:record.request,
      token:record.token,outcome}, true); } catch (_error) { /* The claim stays consumed; never replay uncertain speech. */ }
  }
  function cancel() {
    const old = active; active = null; epoch++;
    if (inFlight === old) inFlight = null;
    if (!old) return;
    old.controller.abort();
    if (old.attempted) window.STONEFELLOW_NOTIFICATION_CENTER?.cancelSpeech?.();
    void finish(old, old.completed === true ? 'spoken' : 'suppressed');
  }
  async function advance(given = {}) {
    if (inFlight) return false;
    const ctx = context(given);
    if (!ctx) { cancel(); return false; }
    if (active && (active.ctx.conversationId !== ctx.conversationId || active.ctx.agentId !== ctx.agentId
      || active.ctx.workingAttentionBinding !== ctx.workingAttentionBinding || active.ctx.activityTab !== ctx.activityTab)) cancel();
    if (!active) active = {ctx,controller:new AbortController(),stage:'candidates',started:false,finished:false};
    const record = active, generation = epoch;
    if (!safe(record)) { cancel(); return false; }
    if (record.attempted) return false;
    const center = window.STONEFELLOW_NOTIFICATION_CENTER;
    if (typeof center?.announceWhen !== 'function') { active = null; return false; }
    inFlight = record;
    try {
      if (record.stage === 'candidates') {
        const data = await request(record, 'voice_candidates');
        if (active !== record || generation !== epoch || !safe(record) || !matches(data, ctx)) return false;
        const candidate = data.candidates?.[0];
        if (!Number.isSafeInteger(candidate?.run_id) || candidate.run_id < 1 || !Number.isSafeInteger(candidate?.message_id) || candidate.message_id < 1) {
          active = null; return false;
        }
        record.run = candidate.run_id; record.message = candidate.message_id; record.request = crypto.randomUUID(); record.stage = 'claim';
      }
      const data = await request(record, 'voice_claim', {run_id:record.run,message_id:record.message,request_id:record.request});
      if (active !== record || generation !== epoch || !matches(data, ctx)) return false;
      const claim = data.claim;
      if (claim?.run_id !== record.run || claim.message_id !== record.message || claim.request_id !== record.request
        || !TOKEN.test(claim.token || '') || typeof claim.text !== 'string' || !claim.text.trim() || [...claim.text].length > 320) { cancel(); return false; }
      record.token = claim.token; record.text = claim.text; record.liveSession = data.live_session_id;
      if (!safe(record)) { cancel(); return false; }
      // From here there is exactly one playback attempt, even if the transport
      // outcome or its completion acknowledgement is lost.
      record.attempted = true;
      const spoken = (await Promise.resolve(center.announceWhen(record.text, () => active === record && generation === epoch && safe(record)))) === true;
      if (active !== record) return false;
      if (!spoken || record.ended || !record.started) {
        await finish(record, spoken && record.completed !== false ? 'spoken' : 'suppressed');
        if (active === record) active = null;
      }
      return spoken;
    } catch (error) {
      // Only a network failure before playback may recover this same request
      // within this document. Nothing survives reload or changes identity.
      if (active === record && (record.stage !== 'claim' || record.attempted || error?.status || error?.name === 'AbortError')) cancel();
      return false;
    } finally { if (inFlight === record) inFlight = null; }
  }
  window.addEventListener('stonefellow:agent-proactive-speech', event => {
    const record = active;
    if (!record?.attempted || event.detail?.text !== record.text) return;
    if (event.detail.state === 'start') record.started = true;
    if (event.detail.state === 'end') {
      record.ended = true; record.completed = event.detail.completed === true;
      void finish(record, record.completed ? 'spoken' : 'suppressed');
      if (active === record) active = null;
    }
  });
  window.addEventListener('stonefellow:agent-voice', event => { voiceEnabled = event.detail?.enabled !== false; if (!voiceEnabled) cancel(); });
  window.addEventListener('storage', event => {
    if (event.key !== `vp3:agent-voice-sync:${Number(page?.userId || 0)}` || !event.newValue) return;
    try {
      const update = JSON.parse(event.newValue);
      if (typeof update?.enabled === 'boolean') { voiceEnabled = update.enabled; if (!voiceEnabled) cancel(); }
    } catch (_error) { /* Unrelated or malformed storage is not a voice setting. */ }
  });
  window.addEventListener('stonefellow:chat-context-changing', cancel);
  window.addEventListener('pagehide', cancel);
  window.addEventListener('blur', cancel);
  document.addEventListener('visibilitychange', () => { if (document.hidden) cancel(); });
  document.addEventListener('input', event => { if (event.target?.id === 'chatInput' && String(event.target.value || '').trim()) cancel(); });
  window.addEventListener('vp3:live-context-changed', () => queueMicrotask(() => { if (active && !safe(active)) cancel(); }));
  window.VP3InitiativeVoice = Object.freeze({advance,cancel});
})();
