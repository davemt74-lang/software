const $ = (id) => document.getElementById(id);
const ui = {
  connectionState: $('connectionState'), connectControls: $('connectControls'), pendingControls: $('pendingControls'),
  shareWorkspace: $('shareWorkspace'), deviceName: $('deviceName'), connectBtn: $('connectBtn'), checkConnectionBtn: $('checkConnectionBtn'),
  settingsBtn: $('settingsBtn'), refreshCaptureBtn: $('refreshCaptureBtn'), pageTitle: $('pageTitle'), pageHost: $('pageHost'),
  selectedText: $('selectedText'), selectionCount: $('selectionCount'), destinationSelect: $('destinationSelect'), shareNote: $('shareNote'),
  shareBtn: $('shareBtn'), successCard: $('successCard'), shareResultText: $('shareResultText'), askAgentBtn: $('askAgentBtn'),
  saveKnowledgeBtn: $('saveKnowledgeBtn'), createTaskBtn: $('createTaskBtn'), openSourceBtn: $('openSourceBtn'),
  openMessagesBtn: $('openMessagesBtn'), notice: $('notice')
};

let state = null;
let capture = null;
let destinations = null;
let lastShare = null;
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
  notify.timer = setTimeout(() => { ui.notice.hidden = true; }, 4500);
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

function capabilities() {
  return new Set(Array.isArray(state?.capabilities) ? state.capabilities : []);
}

function renderCapabilities() {
  const caps = capabilities();
  ui.askAgentBtn.disabled = state?.connected ? !caps.has('agent.message') : true;
  ui.saveKnowledgeBtn.disabled = state?.connected ? !caps.has('knowledge.write') : true;
  ui.createTaskBtn.disabled = state?.connected ? !caps.has('task.propose') : true;
  const canShare = state?.connected && caps.has('team.share.create');
  ui.shareBtn.disabled = !canShare || !capture?.available || !capture?.selected_text?.trim() || !ui.destinationSelect.value;
}

function renderCapture(next) {
  capture = next || { available: false };
  ui.pageTitle.textContent = capture.title || (capture.available ? 'Untitled page' : 'No shareable page');
  ui.pageHost.textContent = hostOf(capture.source_url) || capture.reason || '';
  ui.selectedText.value = capture.selected_text || '';
  const bytes = new TextEncoder().encode(ui.selectedText.value).length;
  ui.selectionCount.textContent = `${bytes.toLocaleString()} / 32,768 bytes`;
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
    return;
  }
  ui.successCard.hidden = false;
  const replay = lastShare.idempotent_replay ? ' Existing share restored safely.' : '';
  ui.shareResultText.textContent = `Message #${lastShare.chat_message.id} was posted.${replay}`;
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
  try { renderDestinations(await message('destinations')); }
  catch (error) {
    ui.destinationSelect.replaceChildren(new Option('Destinations unavailable', ''));
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

ui.shareBtn.addEventListener('click', async () => {
  const destination = parseDestination();
  if (!destination) return notify('Choose where to share this selection.', 'error');
  if (!capture?.selected_text?.trim()) return notify('Highlight text on the page first.', 'error');
  setBusy(ui.shareBtn, true, 'Sharing…');
  try {
    const result = await message('share', { capture, destination, note: ui.shareNote.value });
    renderLastShare({ ...result, source_url: capture.source_url });
    ui.shareNote.value = '';
    notify('Shared with VP3.', 'success');
  } catch (error) { await reportError(error); }
  finally { setBusy(ui.shareBtn, false); }
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
  } catch (error) { await reportError(error); }
  finally { setBusy(button, false); }
}

ui.askAgentBtn.addEventListener('click', () => runShareAction('ask_agent', ui.askAgentBtn, 'Opening…'));
ui.saveKnowledgeBtn.addEventListener('click', () => runShareAction('save_knowledge', ui.saveKnowledgeBtn, 'Saving…'));
ui.createTaskBtn.addEventListener('click', () => runShareAction('create_task', ui.createTaskBtn, 'Creating…'));
ui.openSourceBtn.addEventListener('click', async () => {
  const url = safeHttpUrl(lastShare?.source_url || capture?.source_url);
  if (!url) return notify('The source URL is unavailable.', 'error');
  try { await message('open_url', { url }); } catch (error) { await reportError(error); }
});
ui.openMessagesBtn.addEventListener('click', async () => {
  const base = state?.base_url || 'https://vp3.me';
  const cid = Number(lastShare?.chat_message?.conversation_id || 0);
  const url = `${base}/messages.php${cid ? `?conversation_id=${cid}` : ''}`;
  try { await message('open_url', { url }); } catch (error) { await reportError(error); }
});

document.addEventListener('visibilitychange', () => {
  if (document.visibilityState === 'visible') refreshState().catch(() => {});
});

refreshState().catch(error => reportError(error));
