const $ = (id) => document.getElementById(id);
const ui = {
  connectionState: $('connectionState'), connectControls: $('connectControls'), pendingControls: $('pendingControls'),
  shareWorkspace: $('shareWorkspace'), deviceName: $('deviceName'), connectBtn: $('connectBtn'), checkConnectionBtn: $('checkConnectionBtn'),
  settingsBtn: $('settingsBtn'), refreshCaptureBtn: $('refreshCaptureBtn'), pageTitle: $('pageTitle'), pageHost: $('pageHost'),
  selectedText: $('selectedText'), selectionCount: $('selectionCount'), captureSummary: $('captureSummary'),
  captureScreenshotBtn: $('captureScreenshotBtn'), screenshotPreview: $('screenshotPreview'), screenshotImage: $('screenshotImage'), screenshotMeta: $('screenshotMeta'), removeScreenshotBtn: $('removeScreenshotBtn'),
  captureMediaBtn: $('captureMediaBtn'), mediaDetectedText: $('mediaDetectedText'), mediaPreview: $('mediaPreview'), mediaPreviewTitle: $('mediaPreviewTitle'), mediaStart: $('mediaStart'), mediaEnd: $('mediaEnd'), mediaClipHint: $('mediaClipHint'), removeMediaBtn: $('removeMediaBtn'),
  recordCommentaryBtn: $('recordCommentaryBtn'), commentaryStatus: $('commentaryStatus'), commentaryPreview: $('commentaryPreview'), commentaryAudio: $('commentaryAudio'), commentaryMeta: $('commentaryMeta'), removeCommentaryBtn: $('removeCommentaryBtn'),
  destinationSelect: $('destinationSelect'), shareNote: $('shareNote'), shareBtn: $('shareBtn'), successCard: $('successCard'), shareResultText: $('shareResultText'), shareMediaResult: $('shareMediaResult'), askAgentBtn: $('askAgentBtn'),
  saveKnowledgeBtn: $('saveKnowledgeBtn'), createTaskBtn: $('createTaskBtn'), openSourceBtn: $('openSourceBtn'),
  openMessagesBtn: $('openMessagesBtn'), notice: $('notice')
};

const CLIP_MAX_SECONDS = 90;
const COMMENTARY_MAX_BYTES = 16 * 1024 * 1024;
let state = null;
let capture = null;
let destinations = null;
let lastShare = null;
let screenshotCapture = null;
let mediaReference = null;
let commentaryCapture = null;
let commentaryRecorder = null;
let commentaryStream = null;
let commentaryTimer = null;
let commentaryStartedAt = 0;
let pollTimer = null;

function message(type, data = {}) {
  return new Promise((resolve, reject) => {
    chrome.runtime.sendMessage({ type, ...data }, (response) => {
      if (chrome.runtime.lastError) return reject(new Error(chrome.runtime.lastError.message));
      if (!response?.ok) {
        const error = new Error(response?.error || 'Browser Companion request failed.');
        error.code = response?.code || '';
        return reject(error);
      }
      resolve(response.value);
    });
  });
}

function notify(text, kind = '') {
  ui.notice.textContent = text;
  ui.notice.className = `notice ${kind}`.trim();
  ui.notice.hidden = false;
  clearTimeout(notify.timer);
  notify.timer = setTimeout(() => { ui.notice.hidden = true; }, 5000);
}

async function reportError(error) {
  notify(error.message, 'error');
  if (error.code === 'reconnect_required') await refreshState().catch(() => {});
}

function setBusy(button, busy, label = '') {
  if (!button) return;
  if (busy) {
    button.dataset.label = button.textContent;
    button.disabled = true;
    if (label) button.textContent = label;
  } else {
    if (button.dataset.label) button.textContent = button.dataset.label;
    delete button.dataset.label;
    renderCapabilities();
  }
}

function safeHttpUrl(value) {
  try {
    const parsed = new URL(String(value || ''));
    return ['http:', 'https:'].includes(parsed.protocol) ? parsed.href : '';
  } catch { return ''; }
}

function hostOf(value) {
  try { return new URL(value).hostname; } catch { return ''; }
}

function secondsLabel(value) {
  const seconds = Math.max(0, Math.floor(Number(value || 0)));
  return `${Math.floor(seconds / 60)}:${String(seconds % 60).padStart(2, '0')}`;
}

function bytesLabel(value) {
  const bytes = Math.max(0, Number(value || 0));
  if (bytes < 1024) return `${bytes} B`;
  if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(1)} KB`;
  return `${(bytes / 1024 / 1024).toFixed(1)} MB`;
}

function blobToDataUrl(blob) {
  return new Promise((resolve, reject) => {
    const reader = new FileReader();
    reader.onload = () => resolve(String(reader.result || ''));
    reader.onerror = () => reject(new Error('Recorded audio could not be prepared.'));
    reader.readAsDataURL(blob);
  });
}

function capabilities() {
  return new Set(Array.isArray(state?.capabilities) ? state.capabilities : []);
}

function dropCapability(capability) {
  if (!state || !capability) return;
  state.capabilities = (Array.isArray(state.capabilities) ? state.capabilities : []).filter(item => item !== capability);
  renderCapabilities();
}

function capabilityForAction(action) {
  if (action === 'save_knowledge') return 'knowledge.write';
  if (action === 'create_task') return 'task.propose';
  if (action === 'ask_agent') return 'agent.message';
  return '';
}

function hasCaptureContent() {
  return Boolean(capture?.selected_text?.trim() || screenshotCapture?.data_url || mediaReference?.kind || commentaryCapture?.data_url);
}

function renderCaptureSummary() {
  const parts = [];
  if (capture?.selected_text?.trim()) parts.push('Text');
  if (screenshotCapture?.data_url) parts.push('Screenshot');
  if (mediaReference?.kind) parts.push('Media');
  if (commentaryCapture?.data_url) parts.push('Voice');
  ui.captureSummary.textContent = parts.length ? parts.join(' + ') : 'Add capture';
}

function renderCapabilities() {
  const caps = capabilities();
  const hasShare = Boolean(lastShare?.browser_share?.id && lastShare?.chat_message?.conversation_id);
  ui.askAgentBtn.disabled = !state?.connected || !hasShare || !caps.has('agent.message');
  ui.saveKnowledgeBtn.disabled = !state?.connected || !hasShare || !caps.has('knowledge.write');
  ui.createTaskBtn.disabled = !state?.connected || !hasShare || !caps.has('task.propose');
  ui.openSourceBtn.disabled = !hasShare || !safeHttpUrl(lastShare?.source_url);
  ui.openMessagesBtn.disabled = !hasShare;
  const canShare = state?.connected && caps.has('team.share.create');
  ui.shareBtn.disabled = !canShare || !capture?.available || !hasCaptureContent() || !parseDestination() || commentaryRecorder?.state === 'recording';
  ui.captureScreenshotBtn.disabled = !capture?.available;
  renderCaptureSummary();
}

function clearRichCapture() {
  screenshotCapture = null;
  mediaReference = null;
  commentaryCapture = null;
  ui.screenshotPreview.hidden = true;
  ui.screenshotImage.removeAttribute('src');
  ui.mediaPreview.hidden = true;
  ui.commentaryPreview.hidden = true;
  ui.commentaryAudio.removeAttribute('src');
  renderCapabilities();
}

function renderCapture(next) {
  const previousUrl = capture?.source_url || '';
  capture = next || { available: false };
  if (previousUrl && capture.source_url && previousUrl !== capture.source_url) clearRichCapture();
  ui.pageTitle.textContent = capture.title || (capture.available ? 'Untitled page' : 'No shareable page');
  ui.pageHost.textContent = hostOf(capture.source_url) || capture.reason || '';
  ui.selectedText.value = capture.selected_text || '';
  const bytes = new TextEncoder().encode(ui.selectedText.value).length;
  ui.selectionCount.textContent = `${bytes.toLocaleString()} / 32,768 bytes`;
  const detected = capture.media;
  ui.captureMediaBtn.hidden = !detected?.kind || !safeHttpUrl(detected.source_media_url);
  if (!ui.captureMediaBtn.hidden) {
    const label = detected.kind === 'youtube_clip' ? 'YouTube' : detected.source_media_kind === 'audio' ? 'Audio' : 'Video';
    ui.mediaDetectedText.textContent = `${label} at ${secondsLabel(detected.current_time)}${detected.duration ? ` of ${secondsLabel(detected.duration)}` : ''}`;
  }
  renderCapabilities();
}

function addOptions(group, rows) {
  if (!Array.isArray(rows) || !rows.length) return;
  const optgroup = document.createElement('optgroup');
  optgroup.label = group;
  for (const row of rows) {
    if (!row?.kind || !Number(row.id)) continue;
    const option = document.createElement('option');
    option.value = `${row.kind}:${Number(row.id)}`;
    option.textContent = String(row.name || 'Conversation');
    optgroup.append(option);
  }
  if (optgroup.children.length) ui.destinationSelect.append(optgroup);
}

function renderDestinations(payload) {
  destinations = payload?.destinations || { recent: [], teams: [], conversations: [] };
  ui.destinationSelect.replaceChildren();
  const placeholder = document.createElement('option');
  placeholder.value = '';
  placeholder.textContent = 'Choose a team or conversation';
  ui.destinationSelect.append(placeholder);
  addOptions('Recent', destinations.recent);
  addOptions('Teams', destinations.teams);
  addOptions('Conversations', destinations.conversations);
  renderCapabilities();
}

function parseDestination() {
  const [kind, rawId] = String(ui.destinationSelect.value || '').split(':');
  const id = Number(rawId || 0);
  return ['team_general', 'conversation'].includes(kind) && id > 0 ? { kind, id } : null;
}

function renderConnection(next) {
  state = next;
  ui.connectControls.hidden = true;
  ui.pendingControls.hidden = true;
  ui.shareWorkspace.hidden = true;
  if (state.connected) {
    const name = state.user?.display_name || 'VP3 user';
    ui.connectionState.textContent = `Connected as ${name}`;
    ui.shareWorkspace.hidden = false;
  } else if (state.pending_connection) {
    ui.connectionState.textContent = 'Waiting for VP3 approval';
    ui.pendingControls.hidden = false;
  } else {
    ui.connectionState.textContent = 'Not connected';
    ui.connectControls.hidden = false;
  }
  renderCapabilities();
}

function renderLastShare(result) {
  lastShare = result || null;
  if (!lastShare?.browser_share?.id || !lastShare?.chat_message?.conversation_id) {
    ui.successCard.hidden = true;
    renderCapabilities();
    return;
  }
  ui.successCard.hidden = false;
  const replay = lastShare.idempotent_replay ? ' Existing share restored safely.' : '';
  ui.shareResultText.textContent = `Message #${lastShare.chat_message.id} was posted.${replay}`;
  const count = Array.isArray(lastShare.media) ? lastShare.media.length : 0;
  const errors = Array.isArray(lastShare.media_errors) ? lastShare.media_errors : [];
  if (count || errors.length) {
    ui.shareMediaResult.hidden = false;
    ui.shareMediaResult.dataset.error = errors.length ? '1' : '0';
    ui.shareMediaResult.textContent = errors.length
      ? `${count} rich attachment${count === 1 ? '' : 's'} saved. ${errors.join(' ')}`
      : `${count} rich attachment${count === 1 ? '' : 's'} saved with the share.`;
  } else {
    ui.shareMediaResult.hidden = true;
    ui.shareMediaResult.textContent = '';
  }
  renderCapabilities();
}

async function refreshState() {
  const next = await message('state');
  renderConnection(next);
  if (next.connected) {
    renderLastShare(next.last_share);
    if (next.pending_capture?.available && next.pending_capture.selected_text) {
      renderCapture(next.pending_capture);
      await message('clear_pending_capture').catch(() => {});
    } else {
      await refreshCapture();
    }
    await loadDestinations();
  } else if (next.pending_connection) startPolling();
}

async function refreshCapture() {
  try { renderCapture(await message('capture')); }
  catch (error) { await reportError(error); }
}

async function loadDestinations() {
  try {
    const payload = await message('destinations');
    if (Array.isArray(payload?.capabilities) && state) state.capabilities = payload.capabilities;
    renderDestinations(payload);
  } catch (error) {
    ui.destinationSelect.replaceChildren(new Option('Destinations unavailable', ''));
    if (error.code === 'capability_denied') dropCapability('team.destinations.read');
    await reportError(error);
  }
}

function stopPolling() {
  if (pollTimer) clearInterval(pollTimer);
  pollTimer = null;
}

async function checkConnection() {
  try {
    const result = await message('poll_connect');
    if (result.status === 'approved' && result.session) {
      stopPolling();
      notify('Browser connected to VP3.', 'success');
      await refreshState();
      return;
    }
    if (['denied', 'expired'].includes(result.status) || result.reconnect_required) {
      stopPolling();
      notify(result.reconnect_required ? 'Reconnect this browser to receive a new credential.' : `Connection ${result.status}.`, 'error');
      await refreshState();
    }
  } catch (error) { stopPolling(); await reportError(error); await refreshState().catch(() => {}); }
}

function startPolling() {
  stopPolling();
  pollTimer = setInterval(checkConnection, 2000);
}

function clampMediaWindow(changed = 'end') {
  if (!mediaReference) return;
  const duration = Math.max(0, Number(mediaReference.metadata.duration_seconds || 0));
  let start = Math.max(0, Number(ui.mediaStart.value || 0));
  let end = Math.max(start, Number(ui.mediaEnd.value || start));
  if (duration > 0) {
    start = Math.min(start, duration);
    end = Math.min(end, duration);
  }
  if (end - start > CLIP_MAX_SECONDS) {
    if (changed === 'start') start = Math.max(0, end - CLIP_MAX_SECONDS);
    else end = start + CLIP_MAX_SECONDS;
  }
  mediaReference.metadata.start_seconds = Number(start.toFixed(3));
  mediaReference.metadata.end_seconds = Number(end.toFixed(3));
  ui.mediaStart.value = String(mediaReference.metadata.start_seconds);
  ui.mediaEnd.value = String(mediaReference.metadata.end_seconds);
  ui.mediaClipHint.textContent = `Clip ${secondsLabel(start)}–${secondsLabel(end)} · ${(end - start).toFixed(1)}s · source timestamps only, maximum 90s.`;
}

async function startCommentary() {
  commentaryStream = await navigator.mediaDevices.getUserMedia({ audio: true });
  const preferred = ['audio/webm;codecs=opus', 'audio/ogg;codecs=opus', 'audio/webm'];
  const mimeType = preferred.find(type => MediaRecorder.isTypeSupported(type)) || '';
  const chunks = [];
  commentaryRecorder = new MediaRecorder(commentaryStream, mimeType ? { mimeType } : undefined);
  commentaryStartedAt = Date.now();
  commentaryRecorder.addEventListener('dataavailable', event => { if (event.data?.size) chunks.push(event.data); });
  commentaryRecorder.addEventListener('stop', async () => {
    clearTimeout(commentaryTimer);
    commentaryStream?.getTracks().forEach(track => track.stop());
    commentaryStream = null;
    const duration = Math.min(CLIP_MAX_SECONDS, (Date.now() - commentaryStartedAt) / 1000);
    const blob = new Blob(chunks, { type: commentaryRecorder?.mimeType || 'audio/webm' });
    commentaryRecorder = null;
    ui.recordCommentaryBtn.textContent = 'Voice commentary';
    ui.commentaryStatus.textContent = 'Add your own audio note, up to 90 seconds';
    if (!blob.size) return renderCapabilities();
    if (blob.size > COMMENTARY_MAX_BYTES) {
      commentaryCapture = null;
      notify('Voice commentary is larger than 16 MB. Record a shorter note.', 'error');
      return renderCapabilities();
    }
    try {
      commentaryCapture = {
        data_url: await blobToDataUrl(blob),
        mime_type: blob.type || 'audio/webm',
        metadata: { duration_seconds: Number(duration.toFixed(3)) }
      };
      ui.commentaryAudio.src = commentaryCapture.data_url;
      ui.commentaryMeta.textContent = `${duration.toFixed(1)} seconds · ${bytesLabel(blob.size)}`;
      ui.commentaryPreview.hidden = false;
      notify('Voice commentary captured.', 'success');
    } catch (error) { await reportError(error); }
    renderCapabilities();
  }, { once: true });
  commentaryRecorder.start(500);
  ui.recordCommentaryBtn.textContent = 'Stop recording';
  ui.commentaryStatus.textContent = 'Recording… click to stop';
  commentaryTimer = setTimeout(() => {
    if (commentaryRecorder?.state === 'recording') commentaryRecorder.stop();
  }, CLIP_MAX_SECONDS * 1000);
  renderCapabilities();
}

ui.connectBtn.addEventListener('click', async () => {
  setBusy(ui.connectBtn, true, 'Opening VP3…');
  try {
    await message('connect', { device_name: ui.deviceName.value.trim() || 'Chrome Browser' });
    notify('Approve this browser in the VP3 tab.');
    await refreshState();
    startPolling();
  } catch (error) { await reportError(error); }
  finally { setBusy(ui.connectBtn, false); }
});

ui.checkConnectionBtn.addEventListener('click', checkConnection);
ui.settingsBtn.addEventListener('click', () => chrome.runtime.openOptionsPage());
ui.refreshCaptureBtn.addEventListener('click', refreshCapture);
ui.destinationSelect.addEventListener('change', renderCapabilities);

ui.captureScreenshotBtn.addEventListener('click', async () => {
  setBusy(ui.captureScreenshotBtn, true, 'Select region on page…');
  try {
    const result = await message('capture_region');
    if (result?.cancelled) return notify('Screenshot capture cancelled.');
    screenshotCapture = result;
    ui.screenshotImage.src = result.data_url;
    ui.screenshotMeta.textContent = `${Number(result.metadata?.width || 0).toLocaleString()} × ${Number(result.metadata?.height || 0).toLocaleString()} px`;
    ui.screenshotPreview.hidden = false;
    notify('Screenshot region captured.', 'success');
  } catch (error) { await reportError(error); }
  finally { setBusy(ui.captureScreenshotBtn, false); renderCapabilities(); }
});

ui.removeScreenshotBtn.addEventListener('click', () => {
  screenshotCapture = null;
  ui.screenshotPreview.hidden = true;
  ui.screenshotImage.removeAttribute('src');
  renderCapabilities();
});

ui.captureMediaBtn.addEventListener('click', () => {
  const detected = capture?.media;
  if (!detected?.kind || !safeHttpUrl(detected.source_media_url)) return notify('No playable web media was detected on this page.', 'error');
  const start = Math.max(0, Number(detected.current_time || 0));
  const duration = Math.max(0, Number(detected.duration || 0));
  const end = duration > 0 ? Math.min(duration, start + 30) : start + 30;
  mediaReference = {
    kind: detected.kind,
    metadata: {
      source_media_url: detected.source_media_url,
      source_media_title: String(detected.source_media_title || capture.title || '').slice(0, 512),
      source_media_kind: detected.source_media_kind || '',
      duration_seconds: duration,
      start_seconds: start,
      end_seconds: end
    }
  };
  ui.mediaPreviewTitle.textContent = detected.kind === 'youtube_clip' ? 'YouTube moment' : detected.source_media_kind === 'audio' ? 'Audio moment' : 'Video moment';
  ui.mediaStart.value = String(Number(start.toFixed(3)));
  ui.mediaEnd.value = String(Number(end.toFixed(3)));
  ui.mediaPreview.hidden = false;
  clampMediaWindow();
  renderCapabilities();
});

ui.mediaStart.addEventListener('change', () => { clampMediaWindow('start'); renderCapabilities(); });
ui.mediaEnd.addEventListener('change', () => { clampMediaWindow('end'); renderCapabilities(); });
ui.removeMediaBtn.addEventListener('click', () => { mediaReference = null; ui.mediaPreview.hidden = true; renderCapabilities(); });

ui.recordCommentaryBtn.addEventListener('click', async () => {
  try {
    if (commentaryRecorder?.state === 'recording') commentaryRecorder.stop();
    else await startCommentary();
  } catch (error) {
    commentaryStream?.getTracks().forEach(track => track.stop());
    commentaryStream = null;
    commentaryRecorder = null;
    ui.recordCommentaryBtn.textContent = 'Voice commentary';
    ui.commentaryStatus.textContent = 'Microphone permission is required to record commentary';
    await reportError(error);
    renderCapabilities();
  }
});

ui.removeCommentaryBtn.addEventListener('click', () => {
  commentaryCapture = null;
  ui.commentaryPreview.hidden = true;
  ui.commentaryAudio.pause();
  ui.commentaryAudio.removeAttribute('src');
  renderCapabilities();
});

ui.shareBtn.addEventListener('click', async () => {
  const destination = parseDestination();
  if (!destination) return notify('Choose where to share this capture.', 'error');
  if (!hasCaptureContent()) return notify('Highlight text or add a screenshot, media moment, or voice commentary first.', 'error');
  setBusy(ui.shareBtn, true, 'Sharing…');
  try {
    if (mediaReference) clampMediaWindow();
    const rich_media = {
      screenshot: screenshotCapture,
      media_reference: mediaReference,
      commentary: commentaryCapture
    };
    const result = await message('share', { capture, destination, note: ui.shareNote.value, rich_media });
    renderLastShare({ ...result, source_url: capture.source_url });
    ui.shareNote.value = '';
    const hadErrors = Array.isArray(result.media_errors) && result.media_errors.length;
    if (!hadErrors) clearRichCapture();
    notify(hadErrors ? 'Share posted, but one or more rich attachments need attention.' : 'Shared with VP3.', hadErrors ? 'error' : 'success');
  } catch (error) {
    if (error.code === 'capability_denied') dropCapability('team.share.create');
    await reportError(error);
  } finally { setBusy(ui.shareBtn, false); }
});

async function runShareAction(action, button, busyLabel) {
  const id = lastShare?.browser_share?.id;
  if (!id) return notify('Share something first.', 'error');
  setBusy(button, true, busyLabel);
  try {
    const result = await message('share_action', { action, browser_share_id: id });
    if (action === 'ask_agent' && result.handoff_url) {
      await message('open_url', { url: result.handoff_url });
      notify('Opened this share in VP3 Agent Chat.', 'success');
    } else if (action === 'save_knowledge') {
      notify('Saved to My Knowledge.', 'success');
    } else if (action === 'create_task') {
      notify('VP3 task created.', 'success');
    }
  } catch (error) {
    if (error.code === 'capability_denied') dropCapability(capabilityForAction(action));
    await reportError(error);
  } finally { setBusy(button, false); }
}

ui.askAgentBtn.addEventListener('click', () => runShareAction('ask_agent', ui.askAgentBtn, 'Opening…'));
ui.saveKnowledgeBtn.addEventListener('click', () => runShareAction('save_knowledge', ui.saveKnowledgeBtn, 'Saving…'));
ui.createTaskBtn.addEventListener('click', () => runShareAction('create_task', ui.createTaskBtn, 'Creating…'));
ui.openSourceBtn.addEventListener('click', async () => {
  const url = safeHttpUrl(lastShare?.source_url);
  if (!url) return notify('The original source is not retained in extension storage. Open it from the VP3 Browser Share card.', 'error');
  try { await message('open_url', { url }); } catch (error) { await reportError(error); }
});
ui.openMessagesBtn.addEventListener('click', async () => {
  const base = state?.base_url || 'https://vp3.me';
  const cid = Number(lastShare?.chat_message?.conversation_id || 0);
  if (!cid) return notify('The VP3 conversation is unavailable.', 'error');
  const url = `${base}/messages.php?conversation_id=${cid}`;
  try { await message('open_url', { url }); } catch (error) { await reportError(error); }
});

document.addEventListener('visibilitychange', () => {
  if (document.visibilityState === 'visible' && commentaryRecorder?.state !== 'recording') refreshState().catch(() => {});
});

refreshState().catch(error => reportError(error));
