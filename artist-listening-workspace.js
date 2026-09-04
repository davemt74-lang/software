(() => {
  'use strict';

  const BUILD = 'artist-listening-workspace';
  const cfg = window.STONEFELLOW_ARTIST_LISTENING_V172 || {};
  if (!cfg.endpoint) return;

  const endpoint172 = String(cfg.endpoint);
  const endpoint174 = endpoint172.replace(/artist-listening-v172\.php(?:\?.*)?$/i, 'artist-listening-v174.php');
  const accordionUserId = Math.max(0, Number(cfg.userId || 0));
  const accordionKey = `stonefellow:artist-listening:sidebar:${accordionUserId}`;
  const legacyAccordionKey = `stonefellow:artist-listening:sidebar:v238:${accordionUserId}`;
  let savedAccordions = {};
  try {
    const current = localStorage.getItem(accordionKey);
    const legacy = current === null ? localStorage.getItem(legacyAccordionKey) : null;
    savedAccordions = JSON.parse(current ?? legacy ?? '{}') || {};
    if (current === null && legacy !== null) localStorage.setItem(accordionKey, legacy);
  } catch (error) {}
  const proof = window.STONEFELLOW_ARTIST_LISTENING_WORKSPACE = {
    build: BUILD,
    loaded: true,
    audioRetained: true,
    workspaceMode: false,
    sessionCount: 0,
    currentSessionId: 0,
    saves: 0,
    pauses: 0,
    resumes: 0,
    micTests: 0,
    sidebarOpens: 0,
    staleActiveBypasses: 0,
    lastError: '',
    recognition: null,
  };

  const state = {
    sessions: [],
    folders: [],
    options: [],
    chatOptions: [],
    current: null,
    folder: 'all',
    query: '',
    workspace: null,
    saveTimer: 0,
    titleTimer: 0,
    activeFetches: 0,
    paused: false,
    micTesting: false,
    turnView: false,
    liveSessionId: 0,
    turnNodes: new Map(),
    selectedTurnId: 0,
    viewSessionId: 0,
    accordions: {
      library: savedAccordions.library !== false,
      folders: savedAccordions.folders !== false,
      tags: savedAccordions.tags === true,
    },
  };

  const nativeFetch = window.fetch.bind(window);

  function escapeHtml(value) {
    return String(value ?? '').replace(/[&<>'"]/g, char => ({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#39;','"':'&quot;'}[char]));
  }
  function cleanSpaces(value) {
    return String(value || '').replace(/\s+/g, ' ').trim();
  }
  function formatTime(milliseconds) {
    const seconds = Math.max(0, Math.floor(Number(milliseconds || 0) / 1000));
    const hours = Math.floor(seconds / 3600);
    const minutes = Math.floor((seconds % 3600) / 60);
    const secs = seconds % 60;
    return hours
      ? `${String(hours).padStart(2,'0')}:${String(minutes).padStart(2,'0')}:${String(secs).padStart(2,'0')}`
      : `${String(minutes).padStart(2,'0')}:${String(secs).padStart(2,'0')}`;
  }
  function dateObject(value) {
    const raw = String(value || '').trim();
    if (!raw) return null;
    const date = new Date(raw.replace(' ', 'T'));
    return Number.isFinite(date.getTime()) ? date : null;
  }
  function formatDate(value, compact = false) {
    const date = dateObject(value);
    if (!date) return String(value || '');
    return compact
      ? date.toLocaleDateString([], {month:'short',day:'numeric'})
      : date.toLocaleString([], {month:'short',day:'numeric',year:'numeric',hour:'numeric',minute:'2-digit'});
  }
  function wordCount(text) {
    const clean = cleanSpaces(text);
    return clean ? clean.split(' ').length : 0;
  }
  function sessionSearchText(row) {
    const tags = Array.isArray(row.tags) ? row.tags.join(' ') : '';
    const association = row.association?.label || '';
    const folder = row.folder?.name || '';
    return `${row.title || ''} ${row.preview || ''} ${tags} ${association} ${folder}`.toLowerCase();
  }
  function currentTrackId() {
    return Math.max(0, Number(state.current?.association?.track_id || 0));
  }
  function currentAssociationType() {
    return String(state.current?.association?.type || 'none');
  }
  function currentConversationId() {
    return Math.max(0, Number(state.current?.conversation_id || state.current?.chat?.id || 0));
  }
  function selectedText() {
    const editor = state.workspace?.querySelector('[data-listening-workspace-editor]');
    if (!editor) return '';
    const start = Number(editor.selectionStart || 0);
    const end = Number(editor.selectionEnd || 0);
    return start !== end ? editor.value.slice(start, end) : '';
  }
  function browserListeningActive() {
    return !!document.getElementById('artistListeningButton')?.classList.contains('active');
  }

  function setEditorState(label, kind = 'saved') {
    const node = state.workspace?.querySelector('[data-listening-workspace-editor-state]');
    if (!node) return;
    node.textContent = label;
    node.dataset.state = kind;
  }
  function setFooter(message, strong = false) {
    const node = state.workspace?.querySelector('[data-listening-workspace-footer-message]');
    if (node) node.innerHTML = strong ? `<strong>${escapeHtml(message)}</strong>` : escapeHtml(message);
    const splash = state.workspace?.querySelector('[data-listening-workspace-splash-message]');
    if (splash) {
      splash.textContent = String(message || '');
      splash.classList.toggle('error', strong);
    }
  }

  // Pause/resume is scoped to passive Artist Listening only. It does not alter Agent Chat.
  const BaseRecognition = window.SpeechRecognition || window.webkitSpeechRecognition || null;
  if (typeof BaseRecognition === 'function') {
    class ArtistListeningWorkspaceRecognition extends BaseRecognition {
      constructor(...args) {
        super(...args);
        this._sfPaused = false;
        proof.recognition = this;
      }
      pauseTranscription() {
        if (this._sfPaused) return true;
        if (typeof this._clearRestart !== 'function' || typeof this._spawn !== 'function') return false;
        this._sfPaused = true;
        this._clearRestart();
        this._running = false;
        this._closing = true;
        const native = this._native;
        if (native) {
          native.onend = () => {
            if (this._native === native) this._native = null;
            this._startedEventSent = false;
          };
          try { native.stop(); } catch (error) {
            try { native.abort(); } catch (abortError) {}
          }
        } else {
          this._startedEventSent = false;
        }
        return true;
      }
      resumeTranscription() {
        if (!this._sfPaused) return true;
        if (typeof this._spawn !== 'function') return false;
        this._sfPaused = false;
        this._closing = false;
        this._fatal = false;
        this._running = true;
        this._startedEventSent = true;
        this._spawn(false);
        return true;
      }
      stop() {
        this._sfPaused = false;
        return super.stop();
      }
      abort() {
        this._sfPaused = false;
        return super.abort();
      }
    }
    window.SpeechRecognition = ArtistListeningWorkspaceRecognition;
    window.webkitSpeechRecognition = ArtistListeningWorkspaceRecognition;
  }

  // Truthful save state for the existing v172 capture API and the v174 document API.
  async function trackedFetch(...args) {
    const input = args[0];
    const init = args[1] || {};
    const href = typeof input === 'string' ? input : String(input?.url || '');
    let tracked = false;
    let source172 = false;
    try {
      const url = new URL(href, location.href);
      tracked = /artist-listening-v17[24]\.php$/i.test(url.pathname);
      source172 = /artist-listening-v172\.php$/i.test(url.pathname);
    } catch (error) {}
    const method = String(init.method || (typeof input !== 'string' ? input?.method : '') || 'GET').toUpperCase();
    if (tracked && method !== 'GET') {
      state.activeFetches += 1;
      setEditorState(navigator.onLine ? 'Saving…' : 'Offline', navigator.onLine ? 'saving' : 'error');
    }
    try {
      const response = await nativeFetch(...args);
      if (tracked && method !== 'GET') {
        state.activeFetches = Math.max(0, state.activeFetches - 1);
        if (response.ok && state.activeFetches === 0) setEditorState('Saved', 'saved');
        if (!response.ok) setEditorState('Save needs attention', 'error');
      }
      if (source172 && method !== 'GET' && response.ok) {
        try {
          const data = await response.clone().json();
          const sessionId = Number(data?.session?.id || 0);
          if (sessionId > 0) proof.currentSessionId = sessionId;
        } catch (error) {}
      }
      return response;
    } catch (error) {
      if (tracked && method !== 'GET') {
        state.activeFetches = Math.max(0, state.activeFetches - 1);
        setEditorState(navigator.onLine ? 'Sync retry pending' : 'Offline', 'error');
      }
      throw error;
    }
  }

  async function request(endpoint, action, payload = {}, method = 'GET') {
    let url = endpoint;
    const options = {method, credentials:'same-origin', headers:{Accept:'application/json'}};
    if (method === 'GET') {
      const target = new URL(url, location.href);
      target.searchParams.set('action', action);
      Object.entries(payload).forEach(([key, value]) => {
        if (value !== undefined && value !== null && value !== '') target.searchParams.set(key, String(value));
      });
      url = target.toString();
    } else {
      options.headers['Content-Type'] = 'application/json';
      options.body = JSON.stringify({action, csrf_token:String(cfg.csrf || ''), ...payload});
    }
    const response = await trackedFetch(url, options);
    const data = await response.json().catch(() => ({ok:false,error:'Artist Listening returned an invalid response.'}));
    if (!response.ok || !data.ok) throw new Error(String(data.error || `Request failed (${response.status}).`));
    return data;
  }
  const api172 = (action, payload = {}, method = 'GET') => request(endpoint172, action, payload, method);
  const api174 = (action, payload = {}, method = 'GET') => {
    const transcript = window.STONEFELLOW_ARTIST_LISTENING_TRANSCRIPT;
    if (method === 'GET' && transcript?.workspaceRequest && ['library','session'].includes(action)) {
      return transcript.workspaceRequest(action, payload);
    }
    return request(endpoint174, action, payload, method);
  };

  function isToday(row) {
    const date = dateObject(row.started_at);
    if (!date) return false;
    const now = new Date();
    return date.getFullYear() === now.getFullYear() && date.getMonth() === now.getMonth() && date.getDate() === now.getDate();
  }
  function isThisWeek(row) {
    const date = dateObject(row.started_at);
    if (!date) return false;
    return Date.now() - date.getTime() <= 7 * 86400000;
  }
  function matchesFolder(row, folder) {
    const association = String(row.association?.type || 'none');
    const folderId = Math.max(0, Number(row.folder?.id || 0));
    if (folder === 'all') return true;
    if (folder === 'today') return isToday(row);
    if (folder === 'week') return isThisWeek(row);
    if (folder === 'songs') return association === 'song';
    if (folder === 'projects') return association === 'studio_project';
    if (folder === 'unassigned') return association === 'none';
    if (folder === 'unfiled') return folderId === 0;
    if (folder.startsWith('folder:')) return folderId === Math.max(0, Number(folder.slice(7) || 0));
    if (folder.startsWith('tag:')) {
      const wanted = folder.slice(4).toLowerCase();
      return (row.tags || []).some(tag => String(tag).toLowerCase() === wanted);
    }
    return true;
  }
  function filteredSessions() {
    const query = state.query.trim().toLowerCase();
    return state.sessions.filter(row => matchesFolder(row, state.folder) && (!query || sessionSearchText(row).includes(query)));
  }
  function folderCount(folder) {
    return state.sessions.filter(row => matchesFolder(row, folder)).length;
  }
  function allTags() {
    const counts = new Map();
    state.sessions.forEach(row => (row.tags || []).forEach(tag => {
      const clean = cleanSpaces(tag);
      if (!clean) return;
      const key = clean.toLowerCase();
      counts.set(key, {label:clean,count:(counts.get(key)?.count || 0) + 1});
    }));
    return [...counts.entries()].sort((a,b) => b[1].count - a[1].count || a[1].label.localeCompare(b[1].label));
  }

  function buildWorkspace() {
    if (state.workspace) return state.workspace;
    const workspace = document.createElement('main');
    workspace.className = 'sf-transcript-workspace';
    workspace.hidden = false;
    workspace.innerHTML = `<aside class="sf-listening-workspace-sidebar">
      <header class="sf-listening-workspace-side-head"><button type="button" class="sf-listening-workspace-new" data-listening-workspace-new>+ New Recording</button></header>
      <div class="sf-listening-workspace-search"><input data-listening-workspace-search placeholder="Search transcripts" aria-label="Search transcripts"></div>
      <nav class="sf-listening-workspace-folders" data-listening-workspace-folders></nav>
      <div class="sf-listening-workspace-files" data-listening-workspace-files></div>
    </aside>
    <section class="sf-listening-workspace-editor-shell">
      <div class="sf-listening-workspace-splash" data-listening-workspace-splash>
        <div class="sf-listening-workspace-splash-mark" aria-hidden="true">▤</div>
        <p class="sf-listening-workspace-splash-kicker">Artist Listening</p>
        <h1>No transcription loaded</h1>
        <p>Create a private transcript document, then start listening from its header. Retained audio is optional and only starts when you explicitly choose Start Recording.</p>
        <button type="button" class="sf-listening-workspace-splash-create" data-listening-workspace-create>Create new transcription</button>
        <p class="sf-listening-workspace-splash-message" data-listening-workspace-splash-message aria-live="polite"></p>
      </div>
      <div class="sf-listening-workspace-editor-content" data-listening-workspace-editor-content hidden>
      <header class="sf-listening-workspace-editor-top">
        <a class="sf-listening-workspace-exit" data-listening-workspace-exit href="chat.php" title="Exit Artist Listening" aria-label="Exit Artist Listening">EXIT</a>
        <input class="sf-listening-workspace-title-input" data-listening-workspace-title aria-label="Transcript title" placeholder="Untitled transcript">
        <div class="sf-listening-workspace-toolbar">
          <button type="button" class="sf-listening-workspace-btn primary" data-listening-workspace-save>Save</button>
          <button type="button" class="sf-listening-ai-toggle" data-listening-ai-toggle aria-expanded="false" title="Open AI Summary"><span>AI Summary</span><b data-listening-ai-badge>OFF</b></button>
        </div>
      </header>
      <div class="sf-listening-workspace-meta-bar">
        <label class="sf-listening-workspace-meta-label" data-label="Tags"><input data-listening-workspace-tags placeholder="rehearsal, mix notes, publishing"></label>
        <label class="sf-listening-workspace-meta-label" data-label="Folder"><select data-listening-workspace-folder-select><option value="0">Unfiled</option></select></label>
        <label class="sf-listening-workspace-meta-label" data-label="Association"><select data-listening-workspace-type><option value="none">None</option><option value="song">Song</option><option value="studio_project">Studio project</option></select></label>
        <label class="sf-listening-workspace-meta-label sf-listening-workspace-meta-track" data-label="Song / Project"><select data-listening-workspace-track><option value="0">Choose song</option></select></label>
        <label class="sf-listening-workspace-meta-label sf-listening-workspace-meta-chat" data-label="Agent Chat"><select data-listening-workspace-chat><option value="0">No Agent Chat</option></select></label>
        <span class="sf-listening-workspace-meta-status" data-listening-workspace-meta-status>Original private transcript</span>
      </div>
      <div class="sf-listening-workspace-document-area">
        <div class="sf-listening-workspace-document">
          <div class="sf-listening-workspace-document-tools"><input data-listening-workspace-find placeholder="Find in transcript"><span class="sf-listening-workspace-find-count" data-listening-workspace-find-count></span><button type="button" class="sf-listening-workspace-doc-view" data-listening-workspace-turn-view>Speaker turns</button><span class="sf-listening-workspace-doc-stat" data-listening-workspace-doc-stat></span></div>
          <textarea class="sf-listening-workspace-editor" data-listening-workspace-editor spellcheck="true" aria-label="Transcript document editor"></textarea>
          <div class="sf-listening-workspace-turn-document" data-listening-workspace-turns hidden aria-label="Speaker turn transcript"></div>
        </div>
        <aside class="sf-listening-workspace-inspector">
          <section class="sf-listening-workspace-inspector-section"><h3>Session</h3><div class="sf-listening-workspace-stat-grid" data-listening-workspace-stats></div></section>
          <section class="sf-listening-workspace-inspector-section"><h3>Markers & Notes</h3><div data-listening-workspace-events></div></section>
          <section class="sf-listening-workspace-inspector-section"><h3>Audio Clips</h3><div class="sf-listening-workspace-recording-list" data-listening-workspace-recordings><div class="sf-listening-workspace-no-events">No retained audio clips in this transcription.</div></div></section>
          <section class="sf-listening-workspace-inspector-section"><h3>Knowledge</h3><div class="sf-listening-workspace-inspector-actions"><button type="button" class="sf-listening-workspace-btn" data-listening-workspace-memory>Send selection to Agent Brain</button><button type="button" class="sf-listening-workspace-btn" data-listening-workspace-knowledge>Send selection to Knowledge Base</button><button type="button" class="sf-listening-workspace-btn" data-listening-workspace-project-note>Send selection to Project Notes</button></div></section>
          <section class="sf-listening-workspace-inspector-section"><h3>Capture Input</h3><div class="sf-listening-workspace-capture-strip"><select data-listening-workspace-mic><option value="">System / browser default</option></select><button type="button" data-listening-workspace-test-mic>Test</button></div><div class="sf-listening-workspace-capture-strip"><div class="sf-listening-workspace-level"><span data-listening-workspace-level></span><span data-listening-live-level></span></div><span class="sf-listening-workspace-quality" data-listening-workspace-quality>Not tested</span></div><div class="sf-listening-workspace-capture-strip"><select data-listening-speaker-mode aria-label="Speaker mode"><option value="auto">Multi-person · Auto</option><option value="1">Single speaker</option><option value="2">Expect 2 speakers</option><option value="3">Expect 3 speakers</option><option value="4">Expect 4 speakers</option></select></div></section>
        </aside>
      </div>
      <div class="sf-listening-workspace-listening-player" aria-label="Listening controls">
        <div class="sf-listening-workspace-live-status"><span class="sf-listening-workspace-editor-state" data-listening-workspace-editor-state data-state="saved">Saved</span><span data-listening-state>READY</span><span data-listening-timer>00:00</span><span><b data-listening-speaker-count>0</b> voices</span></div>
        <div class="sf-listening-workspace-capture-actions">
          <button type="button" class="sf-listening-workspace-btn primary" id="artistListeningButton" data-listening-start title="Start/stop transcription · Ctrl/Cmd+Shift+L">Start Listening</button>
          <button type="button" class="sf-listening-workspace-btn" data-listening-workspace-pause disabled>Pause</button>
          <button type="button" class="sf-listening-workspace-btn" data-listening-stop disabled>Stop Listening</button>
          <button type="button" class="sf-listening-workspace-btn sf-listening-workspace-record-btn" data-listening-record aria-pressed="false" disabled title="Start/stop retained audio · Ctrl/Cmd+Shift+R">Start Recording</button>
          <button type="button" class="sf-listening-workspace-btn" data-listening-marker disabled>Mark That</button>
          <button type="button" class="sf-listening-workspace-btn" data-listening-note disabled>Add Note</button>
        </div>
      </div>
      
      </div>
    </section>`;
    document.body.appendChild(workspace);
    state.workspace = workspace;
    bindWorkspace();
    return workspace;
  }

  function renderFolders() {
    const nav = state.workspace?.querySelector('[data-listening-workspace-folders]');
    if (!nav) return;
    const base = [
      ['all','All Recordings'],['today','Today'],['week','Last 7 Days'],['songs','Songs'],['projects','Studio Projects'],['unassigned','No song / project'],
    ];
    const buttons = base.map(([key,label]) => `<button type="button" class="sf-listening-workspace-folder${state.folder === key ? ' active' : ''}" data-listening-workspace-folder="${key}"><span>${label}</span><span>${folderCount(key)}</span></button>`).join('');
    const custom = [
      `<div class="sf-listening-workspace-folder-row"><button type="button" class="sf-listening-workspace-folder${state.folder === 'unfiled' ? ' active' : ''}" data-listening-workspace-folder="unfiled"><span>Unfiled</span><span>${folderCount('unfiled')}</span></button></div>`,
      ...state.folders.map(folder => {
        const id = Math.max(0, Number(folder.id || 0));
        const key = `folder:${id}`;
        const name = String(folder.folder_name || folder.name || 'Folder');
        return `<div class="sf-listening-workspace-folder-row"><button type="button" class="sf-listening-workspace-folder${state.folder === key ? ' active' : ''}" data-listening-workspace-folder="${key}"><span>${escapeHtml(name)}</span><span>${folderCount(key)}</span></button><button type="button" class="sf-listening-workspace-folder-delete" data-listening-workspace-delete-folder="${id}" aria-label="Delete ${escapeHtml(name)}" title="Delete folder">×</button></div>`;
      }),
    ].join('');
    const tags = allTags().slice(0, 10).map(([key,tag]) => `<button type="button" class="sf-listening-workspace-folder${state.folder === `tag:${key}` ? ' active' : ''}" data-listening-workspace-folder="tag:${escapeHtml(key)}"><span>#${escapeHtml(tag.label)}</span><span>${tag.count}</span></button>`).join('');
    const accordion = (key, label, content, extra = '') => `<section class="sf-listening-workspace-accordion${state.accordions[key] ? ' open' : ''}" data-listening-workspace-accordion-section="${key}"><header><button type="button" data-listening-workspace-accordion="${key}" aria-expanded="${state.accordions[key] ? 'true' : 'false'}"><span>${label}</span><i aria-hidden="true">⌄</i></button>${extra}</header><div class="sf-listening-workspace-accordion-body">${content}</div></section>`;
    nav.innerHTML = accordion('library','Library',buttons)
      + accordion('folders','Folders',custom,'<button type="button" class="sf-listening-workspace-accordion-create" data-listening-workspace-create-folder>+ Create</button>')
      + (tags ? accordion('tags','Tags',`<div class="sf-listening-workspace-tag-folders">${tags}</div>`) : '');
  }

  function toggleAccordion(key) {
    if (!Object.hasOwn(state.accordions, key)) return;
    state.accordions[key] = !state.accordions[key];
    try { localStorage.setItem(accordionKey, JSON.stringify(state.accordions)); } catch (error) {}
    renderFolders();
  }

  function renderFiles() {
    const list = state.workspace?.querySelector('[data-listening-workspace-files]');
    if (!list) return;
    const rows = filteredSessions();
    if (!rows.length) {
      list.innerHTML = '<div class="sf-listening-workspace-file-empty">No transcript files match this folder or search.</div>';
      return;
    }
    list.innerHTML = rows.map(row => {
      const live = String(row.status || '') === 'active';
      const folder = row.folder?.name || 'Unfiled';
      const title = String(row.title || 'Untitled transcript');
      return `<div class="sf-listening-workspace-file${Number(row.id) === Number(state.current?.id) ? ' active' : ''}" data-listening-workspace-file="${Number(row.id)}">
        <button type="button" class="sf-listening-workspace-file-open" data-listening-workspace-file-open="${Number(row.id)}" aria-label="Open ${escapeHtml(title)}"><span class="sf-listening-workspace-file-icon" aria-hidden="true">▤</span><span class="sf-listening-workspace-file-copy"><span class="sf-listening-workspace-file-title">${escapeHtml(title)}</span><span class="sf-listening-workspace-file-meta"><span class="${live ? 'sf-listening-workspace-file-live' : ''}">${live ? 'LIVE' : escapeHtml(formatDate(row.started_at, true))}</span><span>·</span><span>${escapeHtml(folder)}</span></span></span></button>
        <button type="button" class="sf-listening-workspace-file-delete" data-listening-workspace-delete-session="${Number(row.id)}" aria-label="Delete ${escapeHtml(title)}" title="Delete transcript"${live ? ' disabled' : ''}>×</button>
      </div>`;
    }).join('');
  }

  function renderFolderOptions(selected = 0) {
    const select = state.workspace?.querySelector('[data-listening-workspace-folder-select]');
    if (!select) return;
    const value = Math.max(0, Number(selected || 0));
    select.innerHTML = `<option value="0">Unfiled</option>${state.folders.map(folder => `<option value="${Number(folder.id || 0)}"${Number(folder.id || 0) === value ? ' selected' : ''}>${escapeHtml(folder.folder_name || folder.name || 'Folder')}</option>`).join('')}`;
    select.value = String(value);
  }

  function renderTrackOptions(type, selected = 0) {
    const select = state.workspace?.querySelector('[data-listening-workspace-track]');
    if (!select) return;
    const placeholder = type === 'studio_project' ? 'Choose Studio project' : 'Choose song';
    const options = state.options
      .filter(option => type !== 'studio_project' || option.has_studio_project)
      .map(option => `<option value="${Number(option.track_id)}"${Number(option.track_id) === Number(selected) ? ' selected' : ''}>${escapeHtml(option.title || `Track #${option.track_id}`)}</option>`)
      .join('');
    select.innerHTML = `<option value="0">${placeholder}</option>${options}`;
    select.disabled = false;
  }

  function renderChatOptions(selected = 0) {
    const select = state.workspace?.querySelector('[data-listening-workspace-chat]');
    if (!select) return;
    const value = Math.max(0, Number(selected || 0));
    select.innerHTML = `<option value="0">No Agent Chat</option>${state.chatOptions.map(option => `<option value="${Number(option.conversation_id || 0)}"${Number(option.conversation_id || 0) === value ? ' selected' : ''}>${escapeHtml(option.title || `Chat #${option.conversation_id}`)}</option>`).join('')}`;
    select.value = String(value);
  }

  function renderEvents(session) {
    const target = state.workspace?.querySelector('[data-listening-workspace-events]');
    if (!target) return;
    const segments = Array.isArray(session?.segments) ? session.segments : [];
    const events = segments.filter(segment => ['marker','note'].includes(String(segment.segment_type || '')));
    target.innerHTML = events.length ? events.map(segment => `<div class="sf-listening-workspace-event"><button type="button" class="sf-listening-workspace-time" data-listening-workspace-time="${Number(segment.started_ms || 0)}">${escapeHtml(formatTime(segment.started_ms))}</button><div class="sf-listening-workspace-event-copy"><b>${escapeHtml(String(segment.segment_type || '').toUpperCase())}</b>${escapeHtml(segment.transcript_text || '')}</div></div>`).join('') : '<div class="sf-listening-workspace-no-events">No markers or notes in this transcript.</div>';
  }

  function renderRecordings(session) {
    const target = state.workspace?.querySelector('[data-listening-workspace-recordings]');
    if (!target) return;
    const recordings = Array.isArray(session?.recordings) ? session.recordings : [];
    if (!recordings.length) {
      target.innerHTML = '<div class="sf-listening-workspace-no-events">No retained audio clips in this transcription.</div>';
      return;
    }
    target.innerHTML = recordings.map((recording,index) => {
      const started = Math.max(0,Number(recording.started_ms || 0));
      const ended = Math.max(started,Number(recording.ended_ms || started));
      const duration = Math.max(0,Number(recording.duration_ms || ended - started));
      const url = escapeHtml(recording.url || '');
      return `<article class="sf-listening-workspace-recording"><div><strong>Recording ${index + 1}</strong><span>${escapeHtml(formatTime(started))}–${escapeHtml(formatTime(ended))} · ${escapeHtml(formatTime(duration))}</span><details class="sf-listening-workspace-clip-menu"><summary aria-label="Recording ${index + 1} options">⌄</summary><div><button type="button" data-listening-workspace-play-recording>Play clip</button><a href="${url}" download>Download clip</a></div></details></div><audio controls preload="metadata" data-listening-workspace-recording-audio data-start-ms="${started}" data-end-ms="${ended}" src="${url}"></audio></article>`;
    }).join('');
  }

  function seekRecordingTo(milliseconds) {
    const time = Math.max(0,Number(milliseconds || 0));
    const players = [...(state.workspace?.querySelectorAll('[data-listening-workspace-recording-audio]') || [])];
    const player = players.find(audio => time >= Number(audio.dataset.startMs || 0) && time <= Number(audio.dataset.endMs || 0));
    if (!player) {
      setFooter(`No retained audio clip covers ${formatTime(time)}. Start Recording during listening to capture that section.`);
      return false;
    }
    player.currentTime = Math.max(0,(time - Number(player.dataset.startMs || 0)) / 1000);
    player.play().catch(() => {});
    setFooter(`Playing retained audio at ${formatTime(time)}.`);
    return true;
  }


  function renderStats(session) {
    const target = state.workspace?.querySelector('[data-listening-workspace-stats]');
    if (!target) return;
    const words = Number(session.word_count || wordCount(session.continuous_text || ''));
    target.innerHTML = `<div class="sf-listening-workspace-stat"><b>${formatTime(session.duration_ms)}</b><span>duration</span></div><div class="sf-listening-workspace-stat"><b>${words}</b><span>words</span></div><div class="sf-listening-workspace-stat"><b>${escapeHtml(String(session.status || 'draft'))}</b><span>status</span></div><div class="sf-listening-workspace-stat"><b>${Number((session.tags || []).length)}</b><span>tags</span></div>`;
  }

  function transcriptSegments(session) {
    return (Array.isArray(session?.segments) ? session.segments : []).filter(segment => String(segment.segment_type || '') === 'transcript');
  }

  function turnKey(segment) {
    return String(segment.id || segment.client_segment_key || segment.key || `${segment.segment_index || 0}-${segment.started_ms || 0}`);
  }

  function speakerOptions(selected) {
    const label = String(selected || 'Speaker 1');
    return [1,2,3,4].map(index => `<option value="Speaker ${index}"${label === `Speaker ${index}` ? ' selected' : ''}>Speaker ${index}</option>`).join('');
  }

  function upsertTurn(segment, active) {
    const target = state.workspace?.querySelector('[data-listening-workspace-turns]');
    if (!target) return;
    const key = turnKey(segment);
    let row = state.turnNodes.get(key);
    if (!row) {
      row = document.createElement('article');
      row.className = 'sf-listening-workspace-turn';
      row.dataset.listeningWorkspaceTurn = key;
      row.innerHTML = `<header><select data-listening-workspace-turn-speaker aria-label="Correct speaker label">${speakerOptions(segment.speaker_label)}</select><button type="button" data-listening-workspace-time="${Number(segment.started_ms || 0)}">${escapeHtml(formatTime(segment.started_ms))}</button><span data-listening-workspace-inferred>Inferred speaker</span></header><textarea data-listening-workspace-turn-text spellcheck="true" aria-label="Speaker turn text"></textarea>`;
      target.appendChild(row);
      state.turnNodes.set(key,row);
    }
    row.dataset.segmentId = String(segment.id || '');
    const select = row.querySelector('[data-listening-workspace-turn-speaker]');
    const text = row.querySelector('[data-listening-workspace-turn-text]');
    const inference = row.querySelector('[data-listening-workspace-inferred]');
    if (document.activeElement !== select) select.value = String(segment.speaker_label || 'Speaker 1');
    if (document.activeElement !== text && text.value !== String(segment.transcript_text || segment.text || '')) text.value = String(segment.transcript_text || segment.text || '');
    select.disabled = active || !Number(segment.id || 0);
    text.readOnly = active || !Number(segment.id || 0);
    inference.textContent = segment.speaker_inferred === false ? 'Corrected label' : 'Inferred speaker';
  }

  function renderTurnDocument(session, interim = '', incremental = false) {
    const target = state.workspace?.querySelector('[data-listening-workspace-turns]');
    const editor = state.workspace?.querySelector('[data-listening-workspace-editor]');
    if (!target || !editor) return;
    const active = String(session?.status || '') === 'active' || !!state.liveSessionId;
    editor.hidden = true;
    target.hidden = false;
    if (!incremental) { target.replaceChildren(); state.turnNodes.clear(); }
    for (const segment of transcriptSegments(session)) upsertTurn(segment, active);
    updateLiveInterim(interim);
  }

  function updateLiveInterim(interim = '') {
    const target = state.workspace?.querySelector('[data-listening-workspace-turns]');
    if (!target) return;
    let tail = target.querySelector('[data-listening-workspace-live-interim]');
    if (interim) {
      if (!tail) { tail = document.createElement('div'); tail.className = 'sf-listening-workspace-live-interim'; tail.dataset.listeningWorkspaceLiveInterim = 'true'; target.appendChild(tail); }
      tail.textContent = interim;
      target.scrollTop = target.scrollHeight;
    } else tail?.remove();
  }

  function chooseDocumentView(session) {
    const speakers = new Set(transcriptSegments(session).map(segment => String(segment.speaker_label || 'Speaker 1')));
    const active = String(session?.status || '') === 'active' || Number(session?.id || 0) === state.liveSessionId;
    const sessionId = Number(session?.id || 0);
    if (sessionId !== state.viewSessionId) {
      state.viewSessionId = sessionId;
      state.turnView = active || speakers.size > 1;
    } else if (active || speakers.size > 1) state.turnView = true;
    const editor = state.workspace.querySelector('[data-listening-workspace-editor]');
    const turns = state.workspace.querySelector('[data-listening-workspace-turns]');
    const button = state.workspace.querySelector('[data-listening-workspace-turn-view]');
    editor.hidden = state.turnView;
    turns.hidden = !state.turnView;
    button.disabled = active || speakers.size > 1;
    button.textContent = (active || speakers.size > 1) ? 'Speaker turns · inferred' : (state.turnView ? 'Continuous prose' : 'Speaker turns');
    state.workspace.querySelector('[data-listening-workspace-save]').disabled = active || state.turnView;
    if (state.turnView) renderTurnDocument(session);
  }

  function fillEditor(session) {
    if (!state.workspace || !session) return;
    showEditor();
    state.current = session;
    proof.currentSessionId = Number(session.id || 0);
    const title = state.workspace.querySelector('[data-listening-workspace-title]');
    const tags = state.workspace.querySelector('[data-listening-workspace-tags]');
    const type = state.workspace.querySelector('[data-listening-workspace-type]');
    const editor = state.workspace.querySelector('[data-listening-workspace-editor]');
    title.value = String(session.title || 'Untitled transcript');
    tags.value = (session.tags || []).join(', ');
    type.value = currentAssociationType();
    renderFolderOptions(session.folder?.id || 0);
    renderTrackOptions(type.value, currentTrackId());
    renderChatOptions(currentConversationId());
    editor.value = String(session.continuous_text || '');
    editor.readOnly = String(session.status || '') === 'active';
    chooseDocumentView(session);
    state.workspace.querySelector('[data-listening-workspace-save]').disabled = editor.readOnly || state.turnView;
    state.workspace.querySelector('[data-listening-workspace-meta-status]').textContent = session.association?.label || 'Original private transcript · Unassigned';
    state.workspace.querySelector('[data-listening-workspace-doc-stat]').textContent = `${wordCount(editor.value)} words · ${formatDate(session.started_at)}`;
    renderStats(session);
    renderEvents(session);
    renderRecordings(session);
    renderFiles();
    setEditorState('Saved', 'saved');
    setFooter(editor.readOnly ? 'Listening is active. Stop Listening before editing transcript text.' : '');
    updateKnowledgeButtons();
  }

  async function openSession(id) {
    id = Math.max(0, Number(id || 0));
    if (!id) return;

    const listeningActive = browserListeningActive();
    if (!listeningActive && state.liveSessionId) {
      state.liveSessionId = 0;
      proof.staleActiveBypasses += 1;
    }
    const activeSessionId = Number(
      state.liveSessionId
      || (listeningActive ? state.current?.id : 0)
      || (listeningActive ? state.sessions.find(row => String(row.status || '') === 'active')?.id : 0)
      || 0
    );
    if (listeningActive && activeSessionId && id !== activeSessionId) {
      setFooter('Stop the active transcription before opening another transcript.', true);
      return;
    }

    try {
      const data = await api174('session', {session_id:id});
      fillEditor(data.session);
      proof.sidebarOpens += 1;
      window.dispatchEvent(new CustomEvent('stonefellow:artist-listening-document-selected', {detail:{session:data.session}}));
    } catch (error) {
      proof.lastError = String(error?.message || error);
      setEditorState('Load failed', 'error');
      setFooter(proof.lastError, true);
    }
  }

  function enterWorkspace() {
    const workspace = buildWorkspace();
    workspace.hidden = false;
    document.querySelector('.sf-listening-page')?.classList.add('sf-listening-workspace-library-mode');
    document.body.classList.add('sf-listening-workspace-workspace-open');
    proof.workspaceMode = true;
  }
  function showSplash() {
    if (!state.workspace) return;
    state.current = null;
    state.viewSessionId = 0;
    state.turnNodes.clear();
    proof.currentSessionId = 0;
    state.workspace.querySelector('[data-listening-workspace-splash]').hidden = false;
    state.workspace.querySelector('[data-listening-workspace-editor-content]').hidden = true;
    window.dispatchEvent(new CustomEvent('stonefellow:artist-listening-document-selected', {detail:{session:null}}));
  }

  function showEditor() {
    if (!state.workspace) return;
    state.workspace.querySelector('[data-listening-workspace-splash]').hidden = true;
    state.workspace.querySelector('[data-listening-workspace-editor-content]').hidden = false;
  }

  async function loadLibrary(options = {}) {
    try {
      const data = await api174('library');
      state.sessions = Array.isArray(data.sessions) ? data.sessions : [];
      state.folders = Array.isArray(data.folders) ? data.folders : [];
      state.options = Array.isArray(data.association_options) ? data.association_options : [];
      if (!state.options.length && Array.isArray(cfg.tracks)) {
        state.options = cfg.tracks.map(track => ({track_id:Number(track.id || 0),title:String(track.title || ''),has_studio_project:false}));
      }
      state.chatOptions = Array.isArray(data.chat_options) ? data.chat_options : [];
      proof.sessionCount = state.sessions.length;
      enterWorkspace();
      renderFolders();
      renderFiles();
      if (!state.sessions.length) {
        showSplash();
        return;
      }
      const firstVisible = Number(filteredSessions()[0]?.id || 0);
      const requestedId = Math.max(0, Number(options.openId || 0));
      const requestedSession = requestedId
        ? state.sessions.find((session) => Number(session?.id || 0) === requestedId)
        : null;
      const preferred = Number(requestedSession?.id || state.current?.id || (options.selectFirst === false ? 0 : firstVisible) || 0);
      if (preferred) await openSession(preferred);
      else showSplash();
    } catch (error) {
      proof.lastError = String(error?.message || error);
      if (state.workspace) setFooter(proof.lastError, true);
    }
  }

  async function saveTitle() {
    if (!state.current) return;
    const input = state.workspace.querySelector('[data-listening-workspace-title]');
    const title = cleanSpaces(input.value);
    if (!title || title === String(state.current.title || '')) return;
    try {
      const data = await api172('rename', {session_id:Number(state.current.id),title}, 'POST');
      state.current.title = data.session?.title || title;
      proof.saves += 1;
      await loadLibrary({openId:state.current.id});
    } catch (error) {
      proof.lastError = String(error?.message || error);
      setEditorState('Title save failed', 'error');
    }
  }

  async function saveMetadata() {
    if (!state.current) return;
    const tags = state.workspace.querySelector('[data-listening-workspace-tags]').value;
    const type = state.workspace.querySelector('[data-listening-workspace-type]').value;
    const trackId = Number(state.workspace.querySelector('[data-listening-workspace-track]').value || 0);
    const folderId = Number(state.workspace.querySelector('[data-listening-workspace-folder-select]').value || 0);
    const conversationId = Number(state.workspace.querySelector('[data-listening-workspace-chat]').value || 0);
    try {
      const data = await api174('update_metadata', {session_id:Number(state.current.id),tags,association_type:type,track_id:trackId,folder_id:folderId,conversation_id:conversationId}, 'POST');
      state.current = data.session;
      proof.saves += 1;
      window.dispatchEvent(new CustomEvent('stonefellow:artist-listening-metadata-saved', {detail:{session:state.current}}));
      await loadLibrary({openId:state.current.id});
    } catch (error) {
      proof.lastError = String(error?.message || error);
      setEditorState('Organization save failed', 'error');
      setFooter(proof.lastError, true);
    }
  }

  async function saveTranscript() {
    if (!state.current) return;
    const editor = state.workspace.querySelector('[data-listening-workspace-editor]');
    if (editor.readOnly) return;
    const text = cleanSpaces(editor.value);
    if (!text) {
      setFooter('Transcript text cannot be empty.', true);
      return;
    }
    try {
      const data = await api174('replace_transcript', {session_id:Number(state.current.id),text}, 'POST');
      proof.saves += 1;
      state.current = data.session;
      fillEditor(data.session);
      await loadLibrary({openId:data.session.id});
    } catch (error) {
      proof.lastError = String(error?.message || error);
      setEditorState('Save failed', 'error');
      setFooter(proof.lastError, true);
    }
  }

  async function saveTurn(row) {
    if (!state.current || !row) return;
    const segmentId = Math.max(0, Number(row.dataset.segmentId || 0));
    if (!segmentId) return;
    const speaker = String(row.querySelector('[data-listening-workspace-turn-speaker]')?.value || 'Speaker 1');
    const text = cleanSpaces(row.querySelector('[data-listening-workspace-turn-text]')?.value || '');
    if (!text) { setFooter('Speaker turn text cannot be empty.', true); return; }
    try {
      const data = await api174('update_turn', {session_id:Number(state.current.id),segment_id:segmentId,speaker_label:speaker,text}, 'POST');
      state.current = data.session;
      proof.saves += 1;
      setEditorState('Saved','saved');
      renderTurnDocument(data.session);
    } catch (error) {
      proof.lastError = String(error?.message || error);
      setEditorState('Turn save failed','error');
      setFooter(proof.lastError,true);
    }
  }

  function queueTranscriptSave() {
    if (state.saveTimer) clearTimeout(state.saveTimer);
    setEditorState('Unsaved changes', 'saving');
    state.saveTimer = setTimeout(() => {
      state.saveTimer = 0;
      void saveTranscript();
    }, 1400);
  }

  function findInDocument() {
    const find = state.workspace.querySelector('[data-listening-workspace-find]').value.trim().toLowerCase();
    const text = state.turnView
      ? [...state.workspace.querySelectorAll('[data-listening-workspace-turn-text]')].map(node => node.value).join(' ').toLowerCase()
      : state.workspace.querySelector('[data-listening-workspace-editor]').value.toLowerCase();
    const count = state.workspace.querySelector('[data-listening-workspace-find-count]');
    if (!find) { count.textContent = ''; return; }
    let hits = 0;
    let offset = 0;
    while ((offset = text.indexOf(find, offset)) !== -1) { hits += 1; offset += Math.max(1, find.length); }
    count.textContent = `${hits} match${hits === 1 ? '' : 'es'}`;
  }



  function turnDocumentText() {
    return [...state.workspace.querySelectorAll('[data-listening-workspace-turn]')].map(row => {
      const speaker = row.querySelector('[data-listening-workspace-turn-speaker]')?.value || 'Speaker 1';
      const time = row.querySelector('[data-listening-workspace-time]')?.textContent || '00:00';
      const text = row.querySelector('[data-listening-workspace-turn-text]')?.value || '';
      return `${speaker} · ${time}\n${text}`;
    }).join('\n\n');
  }

  function selectedFolderForNewDocument() {
    return state.folder.startsWith('folder:') ? Math.max(0, Number(state.folder.slice(7) || 0)) : 0;
  }

  async function createDocument() {
    if (browserListeningActive()) {
      setFooter('Stop the active transcription before creating another transcript.', true);
      return;
    }
    try {
      const data = await api174('create_draft', {folder_id:selectedFolderForNewDocument()}, 'POST');
      enterWorkspace();
      await loadLibrary({openId:Number(data.session?.id || 0)});
      state.workspace.querySelector('[data-listening-workspace-title]')?.focus();
      setFooter('New transcription document created. Press Start Listening when ready. Recording audio is optional.');
    } catch (error) {
      proof.lastError = String(error?.message || error);
      setFooter(proof.lastError, true);
    }
  }

  async function createFolder() {
    const name = window.prompt('Name this recording folder:', '');
    if (name === null || !cleanSpaces(name)) return;
    try {
      const data = await api174('create_folder', {name:cleanSpaces(name)}, 'POST');
      const id = Math.max(0, Number(data.folder?.id || 0));
      state.folder = id ? `folder:${id}` : state.folder;
      await loadLibrary({selectFirst:false});
      setFooter('Folder created. New recordings made here will start in this folder.');
    } catch (error) {
      proof.lastError = String(error?.message || error);
      setFooter(proof.lastError, true);
    }
  }

  async function deleteFolder(folderId) {
    folderId = Math.max(0, Number(folderId || 0));
    const folder = state.folders.find(row => Number(row.id || 0) === folderId);
    if (!folderId || !folder) return;
    const name = String(folder.folder_name || folder.name || 'this folder');
    if (!window.confirm(`Delete “${name}”? Its recordings will move to Unfiled.`)) return;
    try {
      await api174('delete_folder', {folder_id:folderId}, 'POST');
      if (state.folder === `folder:${folderId}`) state.folder = 'unfiled';
      await loadLibrary({openId:Number(state.current?.id || 0),selectFirst:false});
      setFooter('Folder deleted. Its recordings are now Unfiled.');
    } catch (error) {
      proof.lastError = String(error?.message || error);
      setFooter(proof.lastError, true);
    }
  }

  async function deleteSession(sessionId) {
    sessionId = Math.max(0, Number(sessionId || 0));
    const session = state.sessions.find(row => Number(row.id || 0) === sessionId);
    if (!session || String(session.status || '') === 'active') return;
    if (!window.confirm(`Delete “${session.title || 'Untitled transcript'}”?`)) return;
    try {
      await api172('discard', {session_id:sessionId}, 'POST');
      if (Number(state.current?.id || 0) === sessionId) state.current = null;
      await loadLibrary({selectFirst:true});
      setFooter('Transcript removed.');
    } catch (error) {
      proof.lastError = String(error?.message || error);
      setFooter(proof.lastError, true);
    }
  }

  function updatePauseButton() {
    const button = state.workspace?.querySelector('[data-listening-workspace-pause]');
    const listeningButton = document.getElementById('artistListeningButton');
    const active = !!listeningButton?.classList.contains('active');
    if (!button) return;
    if (!active) state.paused = false;
    button.disabled = !active;
    button.textContent = state.paused ? 'Resume' : 'Pause';
  }

  function togglePause() {
    const recognition = proof.recognition;
    if (!recognition) return;
    if (!state.paused) {
      window.dispatchEvent(new CustomEvent('stonefellow:artist-listening-before-pause'));
      if (!recognition.pauseTranscription?.()) return;
      state.paused = true;
      proof.pauses += 1;
      setFooter('Transcription paused. Resume continues this same session.');
    } else {
      if (!recognition.resumeTranscription?.()) return;
      state.paused = false;
      proof.resumes += 1;
      setFooter('Transcription resumed.');
    }
    updatePauseButton();
  }

  async function enumerateMics() {
    const select = state.workspace?.querySelector('[data-listening-workspace-mic]');
    if (!select || !navigator.mediaDevices?.enumerateDevices) return;
    try {
      const devices = (await navigator.mediaDevices.enumerateDevices()).filter(device => device.kind === 'audioinput');
      const remembered = localStorage.getItem('stonefellow:artist-listening:v175:mic') || '';
      select.innerHTML = '<option value="">System / browser default</option>';
      devices.forEach((device,index) => {
        const option = document.createElement('option');
        option.value = device.deviceId;
        option.textContent = device.label || `Microphone ${index + 1}`;
        select.appendChild(option);
      });
      if ([...select.options].some(option => option.value === remembered)) select.value = remembered;
    } catch (error) {}
  }

  function quality(rms, peak) {
    if (peak >= .95) return ['Clipping','clipping'];
    if (rms < .006) return ['No signal','quiet'];
    if (rms < .025) return ['Too quiet','quiet'];
    if (rms > .35 || peak > .82) return ['Hot','hot'];
    return ['Good','good'];
  }

  async function testMic() {
    if (state.micTesting || !navigator.mediaDevices?.getUserMedia) return {ok:false,label:'Microphone testing is unavailable.',kind:'error'};
    const listeningActive = !!document.getElementById('artistListeningButton')?.classList.contains('active');
    if (listeningActive) { const label='Stop transcription before running the separate input test.'; setFooter(label, true); return {ok:false,label,kind:'error'}; }
    const select = state.workspace.querySelector('[data-listening-workspace-mic]');
    const label = state.workspace.querySelector('[data-listening-workspace-quality]');
    const meter = state.workspace.querySelector('[data-listening-workspace-level]');
    state.micTesting = true;
    proof.micTests += 1;
    label.textContent = 'Testing…';
    let stream = null;
    let context = null;
    let outcome = {ok:false,label:'Input test failed',kind:'error'};
    try {
      const deviceId = String(select.value || '');
      localStorage.setItem('stonefellow:artist-listening:v175:mic', deviceId);
      stream = await navigator.mediaDevices.getUserMedia({audio:deviceId ? {deviceId:{exact:deviceId},echoCancellation:true,noiseSuppression:true,autoGainControl:true} : {echoCancellation:true,noiseSuppression:true,autoGainControl:true},video:false});
      await enumerateMics();
      if (deviceId && [...select.options].some(option => option.value === deviceId)) select.value = deviceId;
      const Context = window.AudioContext || window.webkitAudioContext;
      if (!Context) throw new Error('Audio level analysis is unavailable.');
      context = new Context();
      const analyser = context.createAnalyser(); analyser.fftSize = 512;
      context.createMediaStreamSource(stream).connect(analyser);
      const values = new Uint8Array(analyser.fftSize);
      const started = performance.now();
      let totalRms = 0, frames = 0, maxPeak = 0;
      await new Promise(resolve => {
        const tick = () => {
          analyser.getByteTimeDomainData(values);
          let sum = 0, peak = 0;
          values.forEach(value => { const sample = Math.abs((value - 128) / 128); peak = Math.max(peak,sample); sum += sample * sample; });
          const rms = Math.sqrt(sum / values.length); totalRms += rms; frames += 1; maxPeak = Math.max(maxPeak,peak);
          const [text,kind] = quality(rms,peak); label.textContent = text; label.className = `sf-listening-workspace-quality ${kind}`; meter.style.width = `${Math.min(100,Math.max(2,Math.round(rms * 260)))}%`;
          if (performance.now() - started < 3000) requestAnimationFrame(tick); else resolve();
        };
        requestAnimationFrame(tick);
      });
      const [finalLabel,kind] = quality(frames ? totalRms / frames : 0,maxPeak); label.textContent = finalLabel; label.className = `sf-listening-workspace-quality ${kind}`;
      outcome = {ok:true,label:finalLabel,kind};
    } catch (error) {
      const message=String(error?.message || 'Input test failed');
      label.textContent = message; label.className = 'sf-listening-workspace-quality clipping';
      outcome = {ok:false,label:message,kind:'error'};
    } finally {
      for (const track of stream?.getTracks?.() || []) { try { track.stop(); } catch (error) {} }
      if (context) { try { await context.close(); } catch (error) {} }
      state.micTesting = false;
    }
    return outcome;
  }

  function updateKnowledgeButtons() {
    if (!state.workspace) return;
    const trackId = currentTrackId();
    state.workspace.querySelector('[data-listening-workspace-project-note]').disabled = !trackId || !cfg.canProjectNotes;
    state.workspace.querySelector('[data-listening-workspace-knowledge]').disabled = !cfg.canKnowledge;
  }

  async function promote(kind) {
    if (!state.current) return;
    const text = selectedText();
    const trackId = currentTrackId();
    try {
      if (kind === 'memory') await api172('promote_memory', {session_id:Number(state.current.id),selected_text:text}, 'POST');
      if (kind === 'knowledge') await api172('promote_knowledge', {session_id:Number(state.current.id),track_id:trackId,selected_text:text}, 'POST');
      if (kind === 'project') await api172('promote_project_note', {session_id:Number(state.current.id),track_id:trackId,selected_text:text}, 'POST');
      setFooter(text ? 'Selected transcript text sent successfully.' : 'Transcript sent successfully.');
    } catch (error) {
      setFooter(String(error?.message || error), true);
    }
  }

  function bindWorkspace() {
    const w = state.workspace;
    w.querySelector('[data-listening-workspace-new]').addEventListener('click', () => void createDocument());
    w.querySelector('[data-listening-workspace-create]').addEventListener('click', () => void createDocument());
    w.querySelector('[data-listening-workspace-search]').addEventListener('input', event => { state.query = event.target.value; renderFiles(); });
    w.querySelector('[data-listening-workspace-folders]').addEventListener('click', event => {
      const accordion = event.target.closest('[data-listening-workspace-accordion]');
      if (accordion) { toggleAccordion(String(accordion.dataset.listeningWorkspaceAccordion || '')); return; }
      const create = event.target.closest('[data-listening-workspace-create-folder]');
      if (create) { void createFolder(); return; }
      const remove = event.target.closest('[data-listening-workspace-delete-folder]');
      if (remove) { void deleteFolder(Number(remove.dataset.listeningWorkspaceDeleteFolder || 0)); return; }
      const button = event.target.closest('[data-listening-workspace-folder]'); if (!button) return;
      state.folder = String(button.dataset.listeningWorkspaceFolder || 'all'); renderFolders(); renderFiles();
      const first = filteredSessions()[0];
      if (first) void openSession(first.id);
      else if (!browserListeningActive()) showSplash();
    });
    w.querySelector('[data-listening-workspace-files]').addEventListener('click', event => {
      const remove = event.target.closest('[data-listening-workspace-delete-session]');
      if (remove) { void deleteSession(Number(remove.dataset.listeningWorkspaceDeleteSession || 0)); return; }
      const file = event.target.closest('[data-listening-workspace-file-open]');
      if (file) void openSession(Number(file.dataset.listeningWorkspaceFileOpen || 0));
    });
    w.querySelector('[data-listening-workspace-recordings]').addEventListener('click', event => {
      const play = event.target.closest('[data-listening-workspace-play-recording]');
      if (!play) return;
      const recording = play.closest('.sf-listening-workspace-recording');
      const audio = recording?.querySelector('[data-listening-workspace-recording-audio]');
      if (audio) void audio.play();
      play.closest('details')?.removeAttribute('open');
    });
    w.querySelector('[data-listening-workspace-title]').addEventListener('input', () => { if (state.titleTimer) clearTimeout(state.titleTimer); setEditorState('Unsaved title','saving'); state.titleTimer = setTimeout(() => { state.titleTimer = 0; void saveTitle(); }, 700); });
    w.querySelector('[data-listening-workspace-tags]').addEventListener('change', () => void saveMetadata());
    w.querySelector('[data-listening-workspace-folder-select]').addEventListener('change', () => void saveMetadata());
    w.querySelector('[data-listening-workspace-type]').addEventListener('change', event => { renderTrackOptions(event.target.value,0); if (event.target.value === 'none') void saveMetadata(); });
    w.querySelector('[data-listening-workspace-track]').addEventListener('change', event => { if (Number(event.target.value || 0) > 0 && w.querySelector('[data-listening-workspace-type]').value === 'none') w.querySelector('[data-listening-workspace-type]').value = 'song'; void saveMetadata(); });
    w.querySelector('[data-listening-workspace-chat]').addEventListener('change', () => void saveMetadata());
    w.querySelector('[data-listening-workspace-editor]').addEventListener('input', event => { w.querySelector('[data-listening-workspace-doc-stat]').textContent = `${wordCount(event.target.value)} words · ${formatDate(state.current?.started_at)}`; queueTranscriptSave(); });
    w.querySelector('[data-listening-workspace-editor]').addEventListener('keydown', event => { if ((event.ctrlKey || event.metaKey) && event.key.toLowerCase() === 's') { event.preventDefault(); if (state.saveTimer) clearTimeout(state.saveTimer); state.saveTimer = 0; void saveTranscript(); } });
    w.querySelector('[data-listening-workspace-turn-view]').addEventListener('click', () => { state.turnView = !state.turnView; chooseDocumentView(state.current); });
    w.querySelector('[data-listening-workspace-turns]').addEventListener('change', event => { const row = event.target.closest('[data-listening-workspace-turn]'); if (row) { state.selectedTurnId = Math.max(0, Number(row.dataset.segmentId || 0)); void saveTurn(row); } });
    w.querySelector('[data-listening-workspace-turns]').addEventListener('click', event => { const row = event.target.closest('[data-listening-workspace-turn]'); if (row) state.selectedTurnId = Math.max(0, Number(row.dataset.segmentId || 0)); const button = event.target.closest('[data-listening-workspace-time]'); if (button) seekRecordingTo(Number(button.dataset.listeningWorkspaceTime || 0)); });
    w.querySelector('[data-listening-workspace-save]').addEventListener('click', () => { if (state.saveTimer) clearTimeout(state.saveTimer); state.saveTimer = 0; void saveTranscript(); });
    w.querySelector('[data-listening-workspace-find]').addEventListener('input', findInDocument);
    w.querySelector('[data-listening-workspace-events]').addEventListener('click', event => { const button = event.target.closest('[data-listening-workspace-time]'); if (button) seekRecordingTo(Number(button.dataset.listeningWorkspaceTime || 0)); });
    w.querySelector('[data-listening-workspace-memory]').addEventListener('click', () => void promote('memory'));
    w.querySelector('[data-listening-workspace-knowledge]').addEventListener('click', () => void promote('knowledge'));
    w.querySelector('[data-listening-workspace-project-note]').addEventListener('click', () => void promote('project'));
    w.querySelector('[data-listening-workspace-test-mic]').addEventListener('click', () => void testMic());
    w.querySelector('[data-listening-workspace-mic]').addEventListener('change', event => { try { localStorage.setItem('stonefellow:artist-listening:v175:mic', event.target.value); } catch (error) {} });
    w.querySelector('[data-listening-workspace-pause]').addEventListener('click', togglePause);
    void enumerateMics();
  }

  function handleLiveDocument(event) {
    const detail = event?.detail || {};
    const id = Math.max(0, Number(detail.sessionId || detail.session?.id || 0));
    if (!state.liveSessionId && detail.active && id && id === Number(state.current?.id || 0)) {
      state.liveSessionId = id;
      state.viewSessionId = id;
      state.turnView = true;
      showEditor();
    }
    if (detail.action === 'session-started' && id) {
      state.liveSessionId = id;
      state.viewSessionId = id;
      state.turnView = true;
      enterWorkspace();
      void loadLibrary({openId:id});
      return;
    }
    if (detail.action === 'stopped' && id) {
      state.liveSessionId = 0;
      void loadLibrary({openId:id});
      return;
    }
    if (!state.liveSessionId || (id && id !== state.liveSessionId)) return;
    enterWorkspace();
    if (detail.action === 'interim' || detail.action === 'recognition-end') {
      updateLiveInterim(String(detail.interim || ''));
      setEditorState('Listening…','saving');
      return;
    }
    const live = {...(state.current || {}),...(detail.session || {}),id:state.liveSessionId,status:detail.active === false && detail.action === 'stopping' ? 'active' : 'active',segments:Array.isArray(detail.segments) ? detail.segments : (state.current?.segments || [])};
    state.current = live;
    state.turnView = true;
    renderTurnDocument(live, String(detail.interim || ''), true);
    renderEvents(live);
    renderRecordings(live);
    renderStats({...live,duration_ms:Number(detail.elapsedMs || live.duration_ms || 0)});
    const editor = state.workspace.querySelector('[data-listening-workspace-editor]');
    editor.hidden = true;
    state.workspace.querySelector('[data-listening-workspace-turns]').hidden = false;
    state.workspace.querySelector('[data-listening-workspace-title]').value = String(live.title || 'Live transcription');
    state.workspace.querySelector('[data-listening-workspace-doc-stat]').textContent = `${wordCount(turnDocumentText())} words · LIVE`;
    setEditorState(detail.action === 'synced' ? 'Saved · listening' : 'Listening…', detail.action === 'synced' ? 'saved' : 'saving');
    setFooter(`Live transcript · ${Number(detail.speakerCount || 0)} inferred voice${Number(detail.speakerCount || 0) === 1 ? '' : 's'} · ${detail.recordingActive ? 'audio recording ON' : (detail.recordingUploading ? 'saving audio clip' : 'audio optional')}`);
  }


  function transcriptionSessionSummary(session) {
    if (!session || typeof session !== 'object') return null;
    return {
      id: Math.max(0, Number(session.id || 0)),
      title: String(session.title || ''),
      status: String(session.status || ''),
      wordCount: Number(session.word_count || wordCount(session.continuous_text || '')),
      durationMs: Math.max(0, Number(session.duration_ms || 0)),
      startedAt: String(session.started_at || ''),
      tags: Array.isArray(session.tags) ? [...session.tags] : [],
      conversationId: Math.max(0, Number(session.conversation_id || session.chat?.id || 0)),
      association: session.association ? {...session.association} : null,
      folder: session.folder ? {...session.folder} : null,
    };
  }

  function transcriptionWorkspaceState() {
    const editor = state.workspace?.querySelector('[data-listening-workspace-editor]');
    const turns = transcriptSegments(state.current).map(segment => ({
      id: Math.max(0, Number(segment.id || 0)),
      key: turnKey(segment),
      speaker: String(segment.speaker_label || 'Speaker 1'),
      text: String(segment.transcript_text || segment.text || ''),
      startedMs: Math.max(0, Number(segment.started_ms || 0)),
      endedMs: Math.max(0, Number(segment.ended_ms || 0)),
      inferred: segment.speaker_inferred !== false,
    }));
    return {
      ready: !!state.workspace,
      sessionId: Math.max(0, Number(state.current?.id || 0)),
      current: transcriptionSessionSummary(state.current),
      sessions: state.sessions.map(transcriptionSessionSummary).filter(Boolean),
      folders: state.folders.map(folder => ({...folder})),
      filter: {folder:String(state.folder || 'all'), query:String(state.query || '')},
      view: state.turnView ? 'turns' : 'prose',
      paused: !!state.paused,
      liveSessionId: Math.max(0, Number(state.liveSessionId || 0)),
      documentText: state.turnView ? turnDocumentText() : String(editor?.value || state.current?.continuous_text || ''),
      turns,
      editable: !!editor && !editor.readOnly && !state.turnView,
      microphoneId: String(state.workspace?.querySelector('[data-listening-workspace-mic]')?.value || ''),
      selectedTurnId: Math.max(0, Number(state.selectedTurnId || 0)),
    };
  }

  function transcriptionWorkspaceSelection() {
    const editor = state.workspace?.querySelector('[data-listening-workspace-editor]');
    const start = Math.max(0, Number(editor?.selectionStart || 0));
    const end = Math.max(start, Number(editor?.selectionEnd || start));
    const text = editor && end > start ? String(editor.value || '').slice(start, end) : '';
    const activeRow = document.activeElement?.closest?.('[data-listening-workspace-turn]') || null;
    const segmentId = Math.max(0, Number(state.selectedTurnId || activeRow?.dataset.segmentId || 0));
    const segment = transcriptSegments(state.current).find(row => Number(row.id || 0) === segmentId) || null;
    return {
      sessionId: Math.max(0, Number(state.current?.id || 0)),
      text: {start, end, value:text},
      turn: segment ? {
        id: segmentId,
        key: turnKey(segment),
        speaker: String(segment.speaker_label || 'Speaker 1'),
        text: String(segment.transcript_text || segment.text || ''),
        startedMs: Math.max(0, Number(segment.started_ms || 0)),
        endedMs: Math.max(0, Number(segment.ended_ms || 0)),
      } : null,
    };
  }

  async function transcriptionCreateDocument(options = {}) {
    if (browserListeningActive()) throw new Error('Stop the active transcription before creating another transcript.');
    const folderId = options.folderId === undefined
      ? selectedFolderForNewDocument()
      : Math.max(0, Number(options.folderId || 0));
    const data = await api174('create_draft', {folder_id:folderId}, 'POST');
    enterWorkspace();
    await loadLibrary({openId:Number(data.session?.id || 0)});
    return transcriptionSessionSummary(state.current || data.session);
  }

  async function transcriptionOpenDocument(sessionId) {
    sessionId = Math.max(0, Number(sessionId || 0));
    if (!sessionId) throw new Error('A transcription session id is required.');
    const activeId = Math.max(0, Number(state.liveSessionId || (browserListeningActive() ? state.current?.id : 0) || 0));
    if (browserListeningActive() && activeId && activeId !== sessionId) {
      throw new Error('Stop the active transcription before opening another transcript.');
    }
    const data = await api174('session', {session_id:sessionId});
    fillEditor(data.session);
    proof.sidebarOpens += 1;
    window.dispatchEvent(new CustomEvent('stonefellow:artist-listening-document-selected', {detail:{session:data.session}}));
    return transcriptionSessionSummary(data.session);
  }

  async function transcriptionDeleteDocument(sessionId) {
    sessionId = Math.max(0, Number(sessionId || state.current?.id || 0));
    if (!sessionId) throw new Error('A transcription session id is required.');
    const target = state.sessions.find(row => Number(row.id || 0) === sessionId) || state.current;
    if (String(target?.status || '') === 'active' || (browserListeningActive() && Number(state.current?.id || 0) === sessionId)) {
      throw new Error('Stop the active transcription before deleting it.');
    }
    await api172('discard', {session_id:sessionId}, 'POST');
    if (Number(state.current?.id || 0) === sessionId) state.current = null;
    await loadLibrary({selectFirst:true});
    return {deleted:true, sessionId};
  }

  async function transcriptionRenameDocument(title) {
    if (!state.current) throw new Error('Open a transcription before renaming it.');
    title = cleanSpaces(title);
    if (!title) throw new Error('A transcript title is required.');
    const data = await api172('rename', {session_id:Number(state.current.id), title}, 'POST');
    state.current.title = data.session?.title || title;
    proof.saves += 1;
    await loadLibrary({openId:state.current.id});
    return transcriptionSessionSummary(state.current);
  }

  async function transcriptionReplaceText(text) {
    if (!state.current) throw new Error('Open a transcription before editing it.');
    if (String(state.current.status || '') === 'active') throw new Error('Stop listening before replacing transcript text.');
    text = cleanSpaces(text);
    if (!text) throw new Error('Transcript text cannot be empty.');
    const data = await api174('replace_transcript', {session_id:Number(state.current.id), text}, 'POST');
    state.current = data.session;
    proof.saves += 1;
    fillEditor(data.session);
    await loadLibrary({openId:Number(data.session?.id || 0)});
    return transcriptionSessionSummary(state.current);
  }

  async function transcriptionUpdateMetadata(patch = {}) {
    if (!state.current) throw new Error('Open a transcription before updating metadata.');
    const current = state.current;
    const tags = patch.tags === undefined ? (current.tags || []).join(', ') : (Array.isArray(patch.tags) ? patch.tags.join(', ') : String(patch.tags || ''));
    const associationType = patch.associationType === undefined ? currentAssociationType() : String(patch.associationType || 'none');
    const trackId = patch.trackId === undefined ? currentTrackId() : Math.max(0, Number(patch.trackId || 0));
    const folderId = patch.folderId === undefined ? Math.max(0, Number(current.folder?.id || 0)) : Math.max(0, Number(patch.folderId || 0));
    const conversationId = patch.conversationId === undefined ? currentConversationId() : Math.max(0, Number(patch.conversationId || 0));
    const data = await api174('update_metadata', {
      session_id:Number(current.id), tags, association_type:associationType, track_id:trackId, folder_id:folderId, conversation_id:conversationId,
    }, 'POST');
    state.current = data.session;
    proof.saves += 1;
    window.dispatchEvent(new CustomEvent('stonefellow:artist-listening-metadata-saved', {detail:{session:state.current}}));
    await loadLibrary({openId:Number(state.current.id || 0)});
    return transcriptionSessionSummary(state.current);
  }

  async function transcriptionUpdateTurn(args = {}) {
    if (!state.current) throw new Error('Open a transcription before editing a speaker turn.');
    if (String(state.current.status || '') === 'active') throw new Error('Stop listening before correcting a speaker turn.');
    const segmentId = Math.max(0, Number(args.segmentId || state.selectedTurnId || 0));
    if (!segmentId) throw new Error('A speaker turn must be selected.');
    const existing = transcriptSegments(state.current).find(row => Number(row.id || 0) === segmentId);
    if (!existing) throw new Error('The selected speaker turn was not found.');
    const speaker = cleanSpaces(args.speaker === undefined ? existing.speaker_label : args.speaker) || 'Speaker 1';
    const text = cleanSpaces(args.text === undefined ? existing.transcript_text : args.text);
    if (!text) throw new Error('Speaker turn text cannot be empty.');
    const data = await api174('update_turn', {session_id:Number(state.current.id), segment_id:segmentId, speaker_label:speaker, text}, 'POST');
    state.current = data.session;
    state.selectedTurnId = segmentId;
    proof.saves += 1;
    renderTurnDocument(data.session);
    return transcriptionWorkspaceSelection().turn;
  }

  function transcriptionSearchDocument(query) {
    query = cleanSpaces(query).toLowerCase();
    const text = (state.turnView ? turnDocumentText() : String(state.workspace?.querySelector('[data-listening-workspace-editor]')?.value || '')).toLowerCase();
    if (!query) return {query:'', count:0, positions:[]};
    const positions = [];
    let offset = 0;
    while ((offset = text.indexOf(query, offset)) !== -1) {
      positions.push(offset);
      offset += Math.max(1, query.length);
    }
    return {query, count:positions.length, positions};
  }

  function transcriptionFilterLibrary(options = {}) {
    if (options.folder !== undefined) state.folder = String(options.folder || 'all');
    if (options.query !== undefined) state.query = String(options.query || '');
    renderFolders();
    renderFiles();
    return filteredSessions().map(transcriptionSessionSummary).filter(Boolean);
  }

  async function transcriptionCreateFolder(name) {
    name = cleanSpaces(name);
    if (!name) throw new Error('A recording folder name is required.');
    const data = await api174('create_folder', {name}, 'POST');
    const id = Math.max(0, Number(data.folder?.id || 0));
    if (id) state.folder = `folder:${id}`;
    await loadLibrary({selectFirst:false});
    return data.folder || null;
  }

  async function transcriptionDeleteFolder(folderId) {
    folderId = Math.max(0, Number(folderId || 0));
    if (!folderId) throw new Error('A recording folder id is required.');
    await api174('delete_folder', {folder_id:folderId}, 'POST');
    if (state.folder === `folder:${folderId}`) state.folder = 'unfiled';
    await loadLibrary({openId:Number(state.current?.id || 0), selectFirst:false});
    return {deleted:true, folderId};
  }

  function transcriptionSetView(view) {
    view = String(view || '').toLowerCase();
    if (!['prose','turns'].includes(view)) throw new Error('Transcript view must be prose or turns.');
    if (!state.current) throw new Error('Open a transcription before changing its view.');
    state.turnView = view === 'turns';
    chooseDocumentView(state.current);
    return state.turnView ? 'turns' : 'prose';
  }

  function transcriptionSelectText(args = {}) {
    const editor = state.workspace?.querySelector('[data-listening-workspace-editor]');
    if (!editor) throw new Error('The transcript editor is unavailable.');
    const length = String(editor.value || '').length;
    const start = Math.max(0, Math.min(length, Number(args.start || 0)));
    const end = Math.max(start, Math.min(length, Number(args.end === undefined ? start : args.end)));
    editor.setSelectionRange(start, end);
    state.selectedTurnId = 0;
    window.dispatchEvent(new CustomEvent('stonefellow:transcription-selection-changed', {detail:transcriptionWorkspaceSelection()}));
    return transcriptionWorkspaceSelection();
  }

  function transcriptionSelectTurn(segmentId) {
    segmentId = Math.max(0, Number(segmentId || 0));
    const segment = transcriptSegments(state.current).find(row => Number(row.id || 0) === segmentId);
    if (!segment) throw new Error('The requested speaker turn was not found.');
    state.selectedTurnId = segmentId;
    window.dispatchEvent(new CustomEvent('stonefellow:transcription-selection-changed', {detail:transcriptionWorkspaceSelection()}));
    return transcriptionWorkspaceSelection();
  }

  function transcriptionClearSelection() {
    state.selectedTurnId = 0;
    const editor = state.workspace?.querySelector('[data-listening-workspace-editor]');
    if (editor) {
      const at = Math.max(0, Number(editor.selectionEnd || 0));
      editor.setSelectionRange(at, at);
    }
    const selection = transcriptionWorkspaceSelection();
    window.dispatchEvent(new CustomEvent('stonefellow:transcription-selection-changed', {detail:selection}));
    return selection;
  }

  function transcriptionPause() {
    if (state.paused) return true;
    const recognition = proof.recognition;
    if (!recognition?.pauseTranscription?.()) throw new Error('Active transcription recognition is unavailable.');
    window.dispatchEvent(new CustomEvent('stonefellow:artist-listening-before-pause'));
    state.paused = true;
    proof.pauses += 1;
    updatePauseButton();
    return true;
  }

  function transcriptionResume() {
    if (!state.paused) return true;
    const recognition = proof.recognition;
    if (!recognition?.resumeTranscription?.()) throw new Error('Paused transcription recognition is unavailable.');
    state.paused = false;
    proof.resumes += 1;
    updatePauseButton();
    return true;
  }

  async function transcriptionPromote(kind, text = '') {
    if (!state.current) throw new Error('Open a transcription before promoting knowledge.');
    const selected = cleanSpaces(text || selectedText());
    const trackId = currentTrackId();
    if (kind === 'memory') await api172('promote_memory', {session_id:Number(state.current.id), selected_text:selected}, 'POST');
    else if (kind === 'knowledge') await api172('promote_knowledge', {session_id:Number(state.current.id), track_id:trackId, selected_text:selected}, 'POST');
    else if (kind === 'project') await api172('promote_project_note', {session_id:Number(state.current.id), track_id:trackId, selected_text:selected}, 'POST');
    else throw new Error('Unsupported transcription promotion target.');
    return {promoted:kind, sessionId:Number(state.current.id), selectedText:selected, trackId};
  }

  function transcriptionSetMicrophone(deviceId = '') {
    const select = state.workspace?.querySelector('[data-listening-workspace-mic]');
    if (!select) throw new Error('Microphone selection is unavailable.');
    deviceId = String(deviceId || '');
    if (deviceId && ![...select.options].some(option => option.value === deviceId)) throw new Error('The requested microphone is not available.');
    select.value = deviceId;
    try { localStorage.setItem('stonefellow:artist-listening:v175:mic', deviceId); } catch (error) {}
    return deviceId;
  }

  async function transcriptionTestMicrophone() {
    const result = await testMic();
    if (!result?.ok) throw new Error(String(result?.label || 'Microphone test failed.'));
    return result;
  }

  proof.api = {
    getState: transcriptionWorkspaceState,
    getSelection: transcriptionWorkspaceSelection,
    createDocument: transcriptionCreateDocument,
    openDocument: transcriptionOpenDocument,
    deleteDocument: transcriptionDeleteDocument,
    renameDocument: transcriptionRenameDocument,
    replaceText: transcriptionReplaceText,
    updateMetadata: transcriptionUpdateMetadata,
    updateTurn: transcriptionUpdateTurn,
    searchDocument: transcriptionSearchDocument,
    filterLibrary: transcriptionFilterLibrary,
    createFolder: transcriptionCreateFolder,
    deleteFolder: transcriptionDeleteFolder,
    setView: transcriptionSetView,
    selectText: transcriptionSelectText,
    selectTurn: transcriptionSelectTurn,
    clearSelection: transcriptionClearSelection,
    pause: transcriptionPause,
    resume: transcriptionResume,
    seekAudio: seekRecordingTo,
    promote: transcriptionPromote,
    setMicrophone: transcriptionSetMicrophone,
    testMicrophone: transcriptionTestMicrophone,
  };

  function start() {
    enterWorkspace();
    showSplash();
    renderFolders();
    renderFiles();
    window.addEventListener('stonefellow:artist-listening-live', handleLiveDocument);
    void loadLibrary({openId:Math.max(0, Number(cfg.initialSessionId || 0))});
    setInterval(updatePauseButton, 500);
    window.addEventListener('online', () => setEditorState('Saved','saved'));
    window.addEventListener('offline', () => setEditorState('Offline','error'));
  }

  buildWorkspace();
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start, {once:true});
  else start();
})();
