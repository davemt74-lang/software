(() => {
  'use strict';
  if (window.VP3VoiceDiagnostics) return;

  const page = window.STONEFELLOW_CHAT;
  if (!page) return;
  const owner = Number(page.userId);
  const agent = Number(page.agentId || 0);
  const binding = String(page.workingAttentionBinding || '');
  const states = {
    greeting:new Set(['waiting','skipped','queued','speaking','completed','cancelled','failed']),
    announcement:new Set(['queued','waiting','retrying','blocked','starting','speaking','completed','cancelled','failed']),
    reply:new Set(['opening','listening','submitting','received','closed','failed','blocked'])
  };
  // Only these fixed messages and a numeric Chat message ID can enter the trace.
  const details = {
    agent_voice_off:'Agent Voice is off.',
    auto_greet_off:'Auto Greet is off.',
    attention_unavailable:'The server has no recent attention check for this Chat.',
    tab_changed:'The server recorded a different Chat tab.',
    chat_not_focused:'Agent Chat was not focused.',
    chat_busy:'Chat was finishing another turn.',
    microphone_active:'Recent microphone or screen activity deferred the announcement.',
    live_context_changed:'The session or Chat view changed.',
    voice_settings_unavailable:'The server could not verify the voice settings.',
    post_expired:'The announcement window for this post expired.',
    post_changed:'The saved post changed or Chat moved on.',
    page_closed:'This Chat page closed before playback finished.',
    post_unverified:'The saved post and its delivery receipt could not be matched.',
    voice_already_claimed:'A browser already claimed this post for voice.',
    voice_completion_unverified:'The server did not confirm the announcement receipt.',
    voice_unavailable:'The Chat voice controller was unavailable.',
    post_queued:'A new proactive post arrived in Chat.',
    queue_full:'The announcement was dropped because its queue was full.',
    draft_in_composer:'The Chat composer contains a draft.',
    reply_selected:'Chat already has a reply selected.',
    manual_listening:'Listening was already active.',
    another_announcement:'Another voice turn is active.',
    participant_chat:'This is a participant Chat.',
    no_recognizer:'This browser does not offer speech recognition.',
    no_local_voice:'This browser did not report a device-local voice.',
    no_speech_output:'This browser does not offer speech playback.',
    microphone_permission_denied:'Microphone permission is denied in this browser.',
    microphone_permission_prompt:'The browser is waiting for microphone permission.',
    microphone_permission_unavailable:'The browser did not confirm microphone permission.',
    capture_in_use:'Another browser tab or tool is using the microphone.',
    claim_pending:'Checking the saved post and its voice delivery receipt.',
    claim_unavailable:'The voice receipt request failed.',
    voice_claim_timeout:'The server did not confirm the voice claim in time.',
    private_cue_starting:'No device-local voice was available. Preparing a Chat alert.',
    private_cue_started:'A short Chat alert began playing.',
    private_cue_completed:'The Chat alert finished. The full post is in Chat.',
    local_playback_starting:'The device-local voice is preparing the announcement.',
    local_playback_started:'The device-local voice began the announcement.',
    legacy_playback_starting:'The voice service is preparing an Initiative check-in.',
    legacy_playback_started:'The voice service began the Initiative check-in.',
    playback_completed:'Playback finished.',
    playback_cancelled:'Playback was cancelled.',
    playback_failed:'The browser did not complete playback.',
    playback_autoplay:'The browser blocked playback before it started.',
    playback_device:'The browser reported a problem with the audio output.',
    playback_voice:'The selected voice or language became unavailable.',
    playback_timeout:'The browser did not confirm playback before the timeout.',
    reply_opening:'Waiting for the browser to open the microphone.',
    reply_listening:'The browser opened the timed microphone window.',
    reply_submitting:'Sending the spoken reply to the original post.',
    reply_received:'Chat confirmed the reply on the original post.',
    reply_closed:'The microphone window closed.',
    reply_timeout:'The microphone window expired without a reply.',
    reply_cancelled:'The automatic microphone window was stopped.',
    reply_post_changed:'The spoken reply could not be attached to the original post.',
    reply_owner_unverified:'Chat could not verify the owner for this spoken reply.',
    reply_send_failed:'Chat could not confirm the spoken reply.',
    reply_no_followup:'This announcement had no open reply to capture.',
    reply_speech_filtered:'The browser heard speech that the voice controls could not accept.',
    microphone_start_failed:'The browser did not open the microphone.',
    microphone_input_unavailable:'The browser could not access a usable microphone.',
    speech_service_unavailable:'The browser speech recognition service could not connect.'
  };
  // Greeting producers use fixed display messages registered here.
  const greetingDetails = new Set([
    'Agent Voice is off.',
    'Auto Greet is off.',
    'Waiting for this Chat tab to be visible and focused.',
    'Waiting for the Agent Chat view.',
    'The owner greeting is unavailable in participant Chat.',
    'Waiting for Chat to finish opening.',
    'Waiting for Chat to be idle.',
    'Waiting for the current voice session to finish.',
    'Waiting for the Chat draft to be cleared or sent.',
    'Waiting for the current recording to finish.',
    'Waiting for the Chat speech engine.',
    'Waiting for greeting preferences.',
    'Waiting to check the greeting cooldown across tabs.',
    'Greeting cooldown is active. Auto Greet can still open a quiet reply window.',
    'The greeting could not be added to Chat.',
    'Greeting text is in Chat. Waiting for audio playback.',
    'Greeting playback started.',
    'Greeting playback completed.',
    'Greeting playback started and ended before completion.',
    'Greeting audio did not start. Hear greeting is available in Chat.',
    'The Chat context changed before greeting playback.',
    'Participant selection changed before the greeting finished.',
    'The view changed before the greeting finished.',
    'The Chat context changed before the greeting finished.',
    'The Chat tab lost focus before the greeting finished.',
    'The Chat tab was hidden before the greeting finished.',
    'The user stopped the greeting or selected Listen.',
    'The user started typing before the greeting finished.',
    'The user sent a Chat message before the greeting finished.',
    'Greeting reply controls are unavailable on this page.',
    'Chat was no longer ready for the greeting reply.',
    'Checking microphone permission for the greeting reply.',
    'Microphone permission is denied.',
    'Microphone permission has not been granted.',
    'The browser did not confirm microphone permission in time.',
    'This browser could not report its microphone permission.',
    'Microphone permission is granted. Starting the greeting reply window.',
    'The greeting reply window could not start.',
    'The greeting reply window was cancelled before microphone capture.'
  ]);
  const kinds = Object.keys(states);
  const timeline = Object.fromEntries(kinds.map(kind => [kind,[]]));
  const byMessage = new Map();
  let conversation = Number(page.initialConversationId || 0), scheduled = false;

  function notify() {
    if (scheduled) return;
    scheduled = true;
    queueMicrotask(() => {
      scheduled = false;
      window.dispatchEvent(new Event('vp3:voice-diagnostics-changed'));
    });
  }
  function clear() {
    const changed = byMessage.size || kinds.some(kind => timeline[kind].length);
    for (const kind of kinds) timeline[kind].length = 0;
    byMessage.clear();
    if (changed) notify();
  }
  function current() {
    if (window.STONEFELLOW_CHAT !== page || Number(page.userId) !== owner || Number(page.agentId || 0) !== agent ||
      String(page.workingAttentionBinding || '') !== binding || window.VP3ParticipantContinuity?.selectedContext?.()) {
      clear();
      return false;
    }
    const chat = window.STONEFELLOW_CHAT_CONTINUITY_V87 || window.STONEFELLOW_CHAT_CONTINUITY;
    const now = Number(chat?.conversationId?.() ?? page.initialConversationId ?? 0);
    if (now !== conversation) {
      if (conversation > 0) clear();
      conversation = now;
    }
    return true;
  }
  function describe(code) {
    return typeof code === 'string' && Object.hasOwn(details,code) ? details[code] : '';
  }
  function record(kind,state,code,messageId) {
    if (!Object.hasOwn(states,kind) || !states[kind].has(state) || !current()) return;
    const label = describe(code);
    const detail = label || ((kind === 'greeting' || kind === 'reply') && greetingDetails.has(code) ? code : '');
    if (!detail) return;
    const id = Number.isSafeInteger(messageId) && messageId > 0 ? messageId : null;
    const recent = timeline[kind];
    const last = recent.at(-1);
    if (last?.state === state && last.detail === detail && (last.messageId || null) === id) return;
    const entry = {state,detail,at:new Date().toISOString()};
    if (label) entry.code = code;
    if (id) entry.messageId = id;
    recent.push(Object.freeze(entry));
    if (recent.length > 6) recent.shift();
    if (id && kind !== 'greeting') {
      const post = byMessage.get(id) || {messageId:id,announcement:[],reply:[]};
      post[kind].push(entry);
      if (post[kind].length > 4) post[kind].shift();
      byMessage.delete(id);
      byMessage.set(id,post);
      if (byMessage.size > 8) byMessage.delete(byMessage.keys().next().value);
    }
    notify();
  }
  function snapshot() {
    current();
    const copy = Object.fromEntries(kinds.map(kind => [kind,timeline[kind].slice()]));
    const posts = Array.from(byMessage.values(),post => ({
      messageId:post.messageId,announcement:post.announcement.slice(),reply:post.reply.slice()
    }));
    return {greeting:copy.greeting.at(-1) || null,announcement:copy.announcement.at(-1) || null,
      reply:copy.reply.at(-1) || null,timeline:copy,posts};
  }
  window.addEventListener('stonefellow:chat-context-changing',clear);
  window.addEventListener('vp3:participant-selection-changed',clear);
  window.addEventListener('vp3:participant-task-context',clear);
  window.VP3VoiceDiagnostics = Object.freeze({record,snapshot,describe});
})();
