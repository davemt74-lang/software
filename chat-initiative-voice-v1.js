/* Claim verified proactive Chat posts before handing them to the voice runtime. */
(() => {
  'use strict';
  if (window.VP3InitiativeVoice) return;
  const page = window.STONEFELLOW_CHAT;
  const TAB = /^[a-f0-9]{32}$/, TOKEN = /^[a-f0-9]{64}$/;
  const REQUEST_ID = /^[0-9a-f]{8}(?:-[0-9a-f]{4}){3}-[0-9a-f]{12}$/;
  const REQUEST_TIMEOUT_MS = 8000;
  let active = null, inFlight = null, live = null, epoch = 0, voiceEnabled = page?.agentVoiceEnabled !== false;
  const chat = () => window.STONEFELLOW_CHAT_CONTINUITY_V87 || window.STONEFELLOW_CHAT_CONTINUITY;
  const packet = () => window.VP3LiveContext?.snapshot?.() || null;
  const controls = () => window.VP3ConversationControls;
  const acceptsLive = () => voiceEnabled && controls()?.acceptsProactivePosts?.() === true;
  const foreground = () => !document.hidden && document.hasFocus()
    && document.getElementById('chatThread')?.dataset.view === 'chat';
  const trace = (state,code,messageId) => window.VP3VoiceDiagnostics?.record('announcement',state,code,messageId);
  const refusals = new Set(['agent_voice_off','auto_greet_off','attention_unavailable','tab_changed','chat_not_focused',
    'chat_busy','microphone_active','live_context_changed','voice_settings_unavailable','post_expired','post_changed',
    'post_unverified','voice_already_claimed','voice_completion_unverified']);
  const attentionDeferrals = new Set(['attention_unavailable','tab_changed','chat_busy','chat_not_focused','microphone_active']);
  const browserDeferrals = new Set([...attentionDeferrals,'another_announcement','draft_in_composer','live_context_changed']);
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
    if (!voiceEnabled || cfg !== page || !current?.isReady?.() || current.isRestoring?.()
      || Number(cfg?.userId) !== ctx.userId || Number(current.agentId?.() ?? cfg?.agentId ?? 0) !== ctx.agentId
      || Number(current.conversationId?.()) !== ctx.conversationId || String(cfg?.workingAttentionBinding || '') !== ctx.workingAttentionBinding
      || window.VP3ParticipantContinuity?.selectedContext?.()) return false;
    if (record.started) return true;
    if (!foreground() || String(document.getElementById('chatInput')?.value || '').trim() || current.isBusy?.()
      || window.VP3LiveContext?.voiceDeferral?.()?.defer) return false;
    const state = String(document.body?.dataset.stonefellowAgentState || '');
    if (['thinking','processing','speaking'].includes(state)) return false;
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
    const controller = new AbortController();
    const from = record.controller.signal;
    const abort = () => controller.abort();
    let timedOut = false;
    if (!finish) {
      from.addEventListener('abort',abort,{once:true});
      if (from.aborted) abort();
    }
    const timer = setTimeout(() => { timedOut = true; abort(); },REQUEST_TIMEOUT_MS);
    try {
      const response = await fetch(c.endpoint, {method:'POST',credentials:'same-origin',cache:'no-store',
        headers:{'Content-Type':'application/json','X-VP3-Working-Attention':c.workingAttentionBinding},
        body:JSON.stringify({action,csrf_token:c.csrf,agent_id:c.agentId,conversation_id:c.conversationId,
          activity_tab:c.activityTab,working_attention_binding:c.workingAttentionBinding,
          ...(record.livePost ? {live_post:true} : {}),...(record.notice ? {live_notice:true} : {}),...extra}),
        signal:controller.signal,...(finish ? {keepalive:true} : {})});
      const data = await response.json();
      if (!response.ok || data?.ok !== true) {
        const error = new Error('Voice delivery unavailable');
        error.status = response.status;
        error.voiceReason = refusals.has(data?.voice_reason) ? data.voice_reason : 'claim_unavailable';
        throw error;
      }
      return data;
    } catch (error) {
      if (!timedOut) throw error;
      throw Object.assign(new Error('Voice receipt timed out'),{voiceReason:'voice_claim_timeout'});
    } finally {
      clearTimeout(timer);
      from.removeEventListener('abort',abort);
    }
  }
  async function finish(record, outcome) {
    if (record.finished || !record.token) return;
    record.finished = true;
    const reason = record.suppressionReason;
    try { await request(record, 'voice_finish', {run_id:record.run,message_id:record.message,request_id:record.request,
      token:record.token,outcome,
      ...(record.livePost && outcome === 'suppressed' && reason ? {suppression_reason:reason} : {})}, true); }
    catch (_error) {
      if (record.traceCurrent?.()) trace(outcome === 'spoken' ? 'completed' : 'failed','voice_completion_unverified',record.message);
    }
  }
  function cancel(reason) {
    if (live && !live.controller.signal.aborted) {
      live.cancelReason = reason;
      if (!live.token && typeof reason === 'string') trace(reason === 'chat_not_focused' ? 'waiting' : 'cancelled',reason,live.message);
    }
    live?.controller.abort();
    const old = active; active = null; epoch++;
    if (inFlight === old) inFlight = null;
    if (!old) return;
    old.controller.abort();
    if (old.attempted) window.STONEFELLOW_NOTIFICATION_CENTER?.cancelSpeech?.();
    void finish(old, old.completed === true ? 'spoken' : 'suppressed');
  }
  function cancelBeforePlayback() {
    if ((live || active) && !live?.started && !active?.started) cancel('chat_not_focused');
  }
  function readyForLive() {
    return liveBlockReason() === null;
  }
  function liveBlockReason() {
    if (!voiceEnabled) return 'agent_voice_off';
    const voice = controls();
    if (!voice?.proactiveBlockReason) return 'voice_unavailable';
    const reason = voice.proactiveBlockReason();
    return reason || (live || active || inFlight ? 'another_announcement' : null);
  }
  async function announceLive({runId,messageId,current,ready=foreground,selectReply,clearReply,notice=false,requestId} = {}) {
    if (!Number.isSafeInteger(runId) || runId < 1 || !Number.isSafeInteger(messageId) || messageId < 1
      || typeof current !== 'function' || !current()) return false;
    const blocked = liveBlockReason();
    const defer = reason => {
      trace('waiting',reason,messageId);
      return {retry:true,reason};
    };
    if (blocked) {
      if (browserDeferrals.has(blocked)) return defer(blocked);
      trace('blocked',blocked,messageId); return false;
    }
    const ctx = context();
    if (!ctx) { trace('blocked','live_context_changed',messageId); return false; }
    if (typeof requestId !== 'string' || !REQUEST_ID.test(requestId)) return false;
    const record = {ctx,controller:new AbortController(),livePost:true,notice,run:runId,message:messageId,request:requestId};
    const stillCurrent = () => !record.controller.signal.aborted && acceptsLive() && current();
    const stillReady = () => stillCurrent() && ready() && (packet()?.session_id || null) === ctx.liveSession;
    record.traceCurrent = stillCurrent;
    live = record;
    let spoken = false;
    try {
      if (!stillReady() || !controls().proactiveReady()) {
        const reason = controls().proactiveBlockReason() || 'live_context_changed';
        if (stillCurrent() && browserDeferrals.has(reason)) return defer(reason);
        if (current()) trace('blocked',reason,messageId);
        return false;
      }
      trace('waiting','claim_pending',messageId);
      const identity = {run_id:runId,message_id:messageId,request_id:record.request};
      const data = await request(record, 'voice_claim', identity);
      const claim = data.claim;
      if (!matches(data, ctx) || claim?.run_id !== runId || claim.message_id !== messageId
        || claim.request_id !== record.request || !TOKEN.test(claim.token || '')) {
        if (current()) trace('blocked','post_unverified',messageId);
        return false;
      }
      record.token = claim.token;
      if (!stillReady()) {
        record.suppressionReason = record.cancelReason || controls()?.proactiveBlockReason?.() || 'live_context_changed';
        if (current()) trace('blocked',record.suppressionReason,messageId);
        return false;
      }
      const reply = claim.reply;
      if (typeof claim.text !== 'string' || !claim.text.trim() || (reply !== null
        && (reply?.message_id !== messageId || !TOKEN.test(reply.reply_ref || '')
        || !Number.isSafeInteger(reply.revision) || reply.revision < 1))) {
        record.suppressionReason = 'post_unverified';
        if (current()) trace('blocked','post_unverified',messageId);
        return false;
      }
      spoken = await controls().proactivePost({
        messageId,text:claim.text,current:stillCurrent,ready:stillReady,onPlaybackStart:()=>{record.started=true;},
        onSuppressed:reason=>{record.suppressionReason = record.cancelReason || reason;},
        selectReply:reply === null || typeof selectReply !== 'function' ? null : () => selectReply(reply),clearReply,signal:record.controller.signal
      });
      return spoken;
    } catch (error) {
      if (!record.token && current() && acceptsLive()) {
        if (record.cancelReason === 'chat_not_focused') return defer('chat_not_focused');
        if (!record.controller.signal.aborted && error.status === 409 && attentionDeferrals.has(error.voiceReason)) {
          if (stillReady()) await window.StonefellowAgentActivity?.refreshCoreContext?.();
          return defer(error.voiceReason);
        }
        // Recover a lost acknowledgement with the same claim ID once the
        // document is ready again. An accepted claim must never be replayed.
        if (!record.controller.signal.aborted && !error.status) return defer(error.voiceReason || 'claim_unavailable');
      }
      if (record.token) record.suppressionReason ||= record.cancelReason || 'playback_failed';
      if (!record.controller.signal.aborted && current()) trace('failed',record.suppressionReason || error.voiceReason || 'claim_unavailable',messageId);
      return false;
    } finally {
      if (live === record) live = null;
      void finish(record, spoken ? 'spoken' : 'suppressed');
    }
  }
  async function advance(given = {}) {
    if (live || inFlight || acceptsLive() || (!controls() && page?.autoGreetEnabled === true)) return false;
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
      trace('starting','legacy_playback_starting',record.message);
      const spoken = (await Promise.resolve(center.announceWhen(record.text, () => active === record && generation === epoch && safe(record)))) === true;
      if (active !== record) return false;
      if (!spoken) trace('failed','playback_failed',record.message);
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
    if (event.detail.state === 'start') {
      record.started = true;
      trace('speaking','legacy_playback_started',record.message);
    }
    if (event.detail.state === 'end') {
      record.ended = true; record.completed = event.detail.completed === true;
      trace(record.completed ? 'completed' : 'cancelled',record.completed ? 'playback_completed' : 'playback_cancelled',record.message);
      void finish(record, record.completed ? 'spoken' : 'suppressed');
      if (active === record) active = null;
    }
  });
  window.addEventListener('stonefellow:agent-voice', event => { voiceEnabled = event.detail?.enabled !== false; if (!voiceEnabled) cancel('agent_voice_off'); });
  window.addEventListener('vp3:agent-auto-greet', event => { if (event.detail?.enabled === false && live) cancel('auto_greet_off'); });
  window.addEventListener('storage', event => {
    if (event.key !== `vp3:agent-voice-sync:${Number(page?.userId || 0)}` || !event.newValue) return;
    try {
      const update = JSON.parse(event.newValue);
      if (typeof update?.enabled === 'boolean') { voiceEnabled = update.enabled; if (!voiceEnabled) cancel('agent_voice_off'); }
    } catch (_error) { /* Unrelated or malformed storage is not a voice setting. */ }
  });
  window.addEventListener('stonefellow:chat-context-changing', () => cancel('live_context_changed'));
  window.addEventListener('vp3:participant-selection-changed', () => cancel('live_context_changed'));
  window.addEventListener('vp3:participant-task-context', () => cancel('live_context_changed'));
  window.addEventListener('pagehide', () => cancel('page_closed'));
  window.addEventListener('blur', cancelBeforePlayback);
  window.addEventListener('stonefellow:agent-stop', () => cancel('playback_cancelled'));
  document.addEventListener('visibilitychange', () => { if (document.hidden) cancelBeforePlayback(); });
  window.addEventListener('vp3:chat-canvas-view-changed', () => { if (!foreground()) cancelBeforePlayback(); });
  document.addEventListener('input', event => { if (event.target?.id === 'chatInput' && String(event.target.value || '').trim()) cancel('draft_in_composer'); });
  window.addEventListener('vp3:live-context-changed', () => queueMicrotask(() => { if (active && !safe(active)) cancel(); }));
  window.VP3InitiativeVoice = Object.freeze({advance,cancel,acceptsLive,readyForLive,liveBlockReason,announceLive});
})();
