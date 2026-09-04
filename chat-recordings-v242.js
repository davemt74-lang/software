(() => {
  'use strict';

  const BUILD = 'chat-recordings-v242-20260902';
  const cfg = window.STONEFELLOW_CHAT_RECORDINGS_V242_CONFIG || {};
  const persistEndpoint = String(cfg.persistEndpoint || '');
  const libraryEndpoint = String(cfg.libraryEndpoint || '/api/artist-recordings-v198.php');
  const csrf = String(cfg.csrf || window.STONEFELLOW_CHAT?.csrf || '');
  const thread = document.getElementById('chatThread');
  const form = document.getElementById('chatForm');

  if (!thread || !form || !persistEndpoint) return;

  const proof = window.STONEFELLOW_CHAT_RECORDINGS_V242 = {
    build: BUILD,
    loaded: true,
    persisted: 0,
    restored: 0,
    missing: 0,
    lastError: '',
  };

  let pendingCommand = null;
  let library = [];
  let libraryAt = 0;
  let scanQueued = false;
  const persisting = new WeakSet();

  const clean = value => String(value || '').replace(/\s+/g, ' ').trim();
  const escapeHtml = value => String(value ?? '').replace(/[&<>'"]/g, char => ({
    '&':'&amp;', '<':'&lt;', '>':'&gt;', "'":'&#39;', '"':'&quot;'
  }[char]));

  function clientId() {
    if (crypto?.randomUUID) return crypto.randomUUID().replace(/-/g, '');
    return `${Date.now().toString(36)}${Math.random().toString(36).slice(2, 14)}`;
  }

  function isRecordingCommand(value) {
    const text = clean(value).toLowerCase();
    if (!text) return false;
    return /\b(recording|recordings|transcript|transcription)\b/.test(text) && (
      /^(show|open|search|find|play|rename|stop)/.test(text) ||
      /\b(favorite|latest|last|where|with|about|at)\b/.test(text)
    );
  }

  function currentConversationId() {
    try {
      return Math.max(0, Number(window.STONEFELLOW_CHAT_CONTINUITY_V87?.conversationId?.() || 0));
    } catch (error) {
      return 0;
    }
  }

  function captureCommand() {
    const input = form.querySelector('#chatInput') || document.getElementById('chatInput');
    const value = clean(input?.value || '');
    if (!isRecordingCommand(value)) return;
    pendingCommand = {text:value, at:Date.now()};
  }

  async function fetchLibrary(force = false) {
    if (!force && library.length && Date.now() - libraryAt < 5000) return library;
    const url = new URL(libraryEndpoint, location.href);
    url.searchParams.set('action', 'library');
    url.searchParams.set('limit', '200');
    const response = await fetch(url, {credentials:'same-origin', headers:{Accept:'application/json'}});
    const data = await response.json().catch(() => ({ok:false,error:'Recording library returned an invalid response.'}));
    if (!response.ok || !data.ok) throw new Error(String(data.error || `Recording library failed (${response.status}).`));
    library = Array.isArray(data.recordings) ? data.recordings : [];
    libraryAt = Date.now();
    return library;
  }

  function refId(sessionId, key) {
    return `${Math.max(0, Number(sessionId || 0))}:${String(key || '')}`;
  }

  function recordingRefs(message) {
    return [...message.querySelectorAll('[data-v206-recording-card]')]
      .map(card => ({
        session_id:Math.max(0, Number(card.dataset.v206Session || 0)),
        key:String(card.dataset.v206Key || ''),
      }))
      .filter(ref => ref.session_id > 0 && /^[a-z0-9-]{16,64}$/i.test(ref.key));
  }

  function messageCopy(message) {
    return clean(message.querySelector('.sf-v206-result-copy')?.textContent || 'Recording results.');
  }

  async function persistMessage(message) {
    if (
      !message?.isConnected ||
      message.dataset.listeningUiPersisted === '1' ||
      message.dataset.listeningUiRestored === '1' ||
      persisting.has(message)
    ) return;

    const refs = recordingRefs(message);
    if (!refs.length) return;

    persisting.add(message);
    const id = clientId();
    message.dataset.listeningUiClientId = id;
    const command = pendingCommand && Date.now() - pendingCommand.at <= 3500
      ? pendingCommand.text
      : '';
    if (command) pendingCommand = null;

    try {
      const response = await fetch(persistEndpoint, {
        method:'POST',
        credentials:'same-origin',
        headers:{'Content-Type':'application/json',Accept:'application/json'},
        body:JSON.stringify({
          action:'persist',
          csrf_token:csrf,
          conversation_id:currentConversationId(),
          user_message:command,
          assistant_message:messageCopy(message),
          recording_refs:refs,
          client_id:id,
        }),
      });
      const data = await response.json().catch(() => ({ok:false,error:'Recording result could not be saved.'}));
      if (!response.ok || !data.ok) throw new Error(String(data.error || `Recording result save failed (${response.status}).`));
      message.dataset.listeningUiPersisted = '1';
      proof.persisted += 1;

      if (currentConversationId() < 1 && Number(data.conversation_id || 0) > 0) {
        location.reload();
      }
    } catch (error) {
      proof.lastError = String(error?.message || error);
      message.dataset.listeningUiPersistError = '1';
    } finally {
      persisting.delete(message);
    }
  }

  function decodePayload(encoded) {
    try {
      const base64 = String(encoded || '').replace(/-/g, '+').replace(/_/g, '/');
      const padded = base64 + '='.repeat((4 - base64.length % 4) % 4);
      return JSON.parse(atob(padded));
    } catch (error) {
      return null;
    }
  }

  function formatTime(milliseconds) {
    const seconds = Math.max(0, Math.round(Number(milliseconds || 0) / 1000));
    const minutes = Math.floor(seconds / 60);
    const secs = seconds % 60;
    return `${minutes}:${String(secs).padStart(2, '0')}`;
  }

  function formatDate(value) {
    const date = new Date(String(value || '').replace(' ', 'T'));
    if (!Number.isFinite(date.getTime())) return '';
    return date.toLocaleString([], {month:'short',day:'numeric',hour:'numeric',minute:'2-digit'});
  }

  function cardHtml(item) {
    const association = clean(item?.association?.label || '');
    const meta = [formatDate(item?.created_at), formatTime(item?.duration_ms), association].filter(Boolean).join(' · ');
    const downloadName = clean(item?.name || 'recording').replace(/[^a-z0-9_-]+/gi, '-') || 'recording';
    return `<article class="chat-recording-card sf-v206-inline-recording-card sf-listening-ui-restored-card" data-v206-recording-card data-v206-recording-id="${escapeHtml(refId(item?.session_id,item?.key))}" data-v206-session="${Number(item?.session_id || 0)}" data-v206-key="${escapeHtml(item?.key || '')}">
      <div class="chat-recording-card-head sf-v206-recording-head">
        <span><strong data-v206-recording-title>${escapeHtml(item?.name || 'Recording')}</strong><small>${escapeHtml(clean(item?.session_title || 'Voice memo'))}${meta ? ` · ${escapeHtml(meta)}` : ''}</small></span>
        <details class="sf-v206-recording-menu" data-v206-menu>
          <summary aria-label="Recording actions" title="Recording actions">&hellip;</summary>
          <div class="sf-v206-recording-menu-popover">
            <button type="button" data-v206-action="favorite">${item?.favorite ? 'Remove favorite' : 'Favorite'}</button>
            <button type="button" data-v206-action="rename">Rename</button>
            <a href="${escapeHtml(item?.url || '')}" download="${escapeHtml(downloadName)}">Download</a>
            <a href="${escapeHtml(item?.open_url || '#')}">Open transcript</a>
            <button type="button" data-v206-action="delete" class="danger">Delete</button>
          </div>
        </details>
      </div>
      ${clean(item?.transcript_excerpt || '') ? `<p class="sf-v206-recording-excerpt">${escapeHtml(clean(item.transcript_excerpt))}</p>` : ''}
      <audio class="chat-transcription-audio" data-v206-recording-audio controls preload="metadata" src="${escapeHtml(item?.url || '')}"></audio>
    </article>`;
  }

  async function restoreMessage(message, match, payload) {
    if (message.dataset.listeningUiRestored === '1') return;
    message.dataset.listeningUiRestored = '1';

    const text = message.querySelector('.message-text');
    if (!text) return;
    text.textContent = String(text.textContent || '').replace(match[0], '').trim();

    const rows = await fetchLibrary();
    const map = new Map(rows.map(item => [refId(item.session_id, item.key), item]));
    const refs = Array.isArray(payload?.refs) ? payload.refs : [];
    const items = refs.map(ref => map.get(refId(ref?.session_id, ref?.key))).filter(Boolean);
    proof.missing += Math.max(0, refs.length - items.length);

    const existing = message.querySelector('[data-listening-ui-persisted-recordings]');
    if (existing) existing.remove();

    const container = document.createElement('div');
    container.className = 'sf-v206-recording-results sf-listening-ui-persisted-recordings';
    container.dataset.listeningUiPersistedRecordings = '1';
    container.innerHTML = items.length
      ? items.map(cardHtml).join('')
      : '<div class="sf-listening-ui-recording-missing">The saved recording is no longer available.</div>';
    message.querySelector('.message-body')?.appendChild(container);
    proof.restored += items.length;

    const id = String(payload?.client_id || '');
    if (id) {
      document.querySelectorAll(`[data-listening-ui-client-id="${CSS.escape(id)}"]`).forEach(node => {
        if (node !== message && node.dataset.listeningUiRestored !== '1') node.remove();
      });
    }
  }

  function scanPersistedMessages() {
    const marker = /\[\[STONEFELLOW_RECORDINGS_V242:([A-Za-z0-9_-]+)\]\]/;
    thread.querySelectorAll('.message.assistant .message-text').forEach(text => {
      const message = text.closest('.message.assistant');
      if (!message || message.dataset.listeningUiRestored === '1') return;
      const match = String(text.textContent || '').match(marker);
      if (!match) return;
      const payload = decodePayload(match[1]);
      if (!payload) {
        text.textContent = String(text.textContent || '').replace(match[0], '').trim();
        message.dataset.listeningUiRestored = '1';
        return;
      }
      void restoreMessage(message, match, payload).catch(error => {
        proof.lastError = String(error?.message || error);
        message.dataset.listeningUiRestored = '';
      });
    });
  }

  function scanNewMessages() {
    thread.querySelectorAll('.sf-v206-recording-message').forEach(message => {
      if (message.dataset.listeningUiRestored === '1') return;
      void persistMessage(message);
    });
    scanPersistedMessages();
  }

  function queueScan() {
    if (scanQueued) return;
    scanQueued = true;
    queueMicrotask(() => {
      scanQueued = false;
      scanNewMessages();
    });
  }

  form.addEventListener('submit', captureCommand, true);
  new MutationObserver(queueScan).observe(thread, {childList:true,subtree:true,characterData:true});
  queueScan();
})();
