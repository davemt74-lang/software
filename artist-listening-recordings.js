(() => {
  'use strict';

  const BUILD = 'artist-recordings-v206-20260901';
  const CHAT_LIMIT = 8;
  const POLL_MS = 7000;
  const SEEN_KEY = 'stonefellow.artist-recordings.v206.seen';
  const pageCfg = window.STONEFELLOW_ARTIST_LISTENING_V172 || {};
  const chatCfg = window.STONEFELLOW_RECORDINGS_V198_CONFIG || {};
  const endpoint = String(chatCfg.endpoint || pageCfg.recordingsEndpoint || '/api/artist-recordings-v198.php');
  const csrf = () => String(chatCfg.csrf || pageCfg.csrf || window.STONEFELLOW_AGENT_CONTEXT?.csrf || '');

  const state = {
    library: [],
    loading: null,
    current: null,
    workspaceObserver: null,
    workspaceRefreshing: false,
    workspaceRefreshQueued: false,
    chatObserver: null,
    pollTimer: 0,
    seen: new Set(),
    seenLoaded: false,
  };

  const proof = window.STONEFELLOW_ARTIST_RECORDINGS_V198 = {
    build: BUILD,
    loaded: true,
    libraryLoads: 0,
    renames: 0,
    favorites: 0,
    deletes: 0,
    plays: 0,
    agentCommands: 0,
    newRecordingNotices: 0,
    inlineResults: 0,
    lastError: '',
  };

  function escapeHtml(value) {
    return String(value ?? '').replace(/[&<>'"]/g, char => ({
      '&':'&amp;',
      '<':'&lt;',
      '>':'&gt;',
      "'":'&#39;',
      '"':'&quot;',
    }[char]));
  }

  function clean(value) {
    return String(value || '').replace(/\s+/g, ' ').trim();
  }

  function normalize(value) {
    return clean(value)
      .toLowerCase()
      .replace(/[^a-z0-9:\s'.-]/g, ' ')
      .replace(/\s+/g, ' ')
      .trim();
  }

  function formatTime(milliseconds) {
    const seconds = Math.max(0, Math.round(Number(milliseconds || 0) / 1000));
    const hours = Math.floor(seconds / 3600);
    const minutes = Math.floor((seconds % 3600) / 60);
    const secs = seconds % 60;
    return hours
      ? `${hours}:${String(minutes).padStart(2,'0')}:${String(secs).padStart(2,'0')}`
      : `${minutes}:${String(secs).padStart(2,'0')}`;
  }

  function formatDate(value) {
    const date = new Date(String(value || '').replace(' ', 'T'));
    if (!Number.isFinite(date.getTime())) return '';
    return date.toLocaleString([], {
      month:'short',
      day:'numeric',
      hour:'numeric',
      minute:'2-digit',
    });
  }

  function isToday(item) {
    const date = new Date(String(item?.created_at || item?.session_started_at || '').replace(' ', 'T'));
    if (!Number.isFinite(date.getTime())) return false;
    const now = new Date();
    return (
      date.getFullYear() === now.getFullYear() &&
      date.getMonth() === now.getMonth() &&
      date.getDate() === now.getDate()
    );
  }

  function parseClock(value) {
    const parts = String(value || '').split(':').map(Number);
    if (!parts.length || parts.some(part => !Number.isFinite(part) || part < 0)) return null;
    if (parts.length === 2) return parts[0] * 60 + parts[1];
    if (parts.length === 3) return parts[0] * 3600 + parts[1] * 60 + parts[2];
    return null;
  }

  function itemText(item) {
    return clean([
      item?.name,
      item?.session_title,
      item?.association?.label,
      item?.transcript_excerpt,
    ].join(' ')).toLowerCase();
  }

  function itemId(item) {
    return `${Number(item?.session_id || 0)}:${String(item?.key || '')}`;
  }

  async function api(action, payload = {}, method = 'GET') {
    let url = endpoint;
    const options = {
      method,
      credentials:'same-origin',
      headers:{Accept:'application/json'},
    };

    if (method === 'GET') {
      const target = new URL(url, location.href);
      target.searchParams.set('action', action);
      for (const [key, value] of Object.entries(payload)) {
        if (value !== undefined && value !== null && value !== '') {
          target.searchParams.set(key, String(value));
        }
      }
      url = target.toString();
    } else {
      options.headers['Content-Type'] = 'application/json';
      options.body = JSON.stringify({
        action,
        csrf_token:csrf(),
        ...payload,
      });
    }

    const response = await fetch(url, options);
    const data = await response.json().catch(() => ({
      ok:false,
      error:'Recording library returned an invalid response.',
    }));

    if (!response.ok || !data.ok) {
      throw new Error(String(data.error || `Recording library failed (${response.status}).`));
    }

    return data;
  }

  async function loadLibrary(force = false) {
    if (state.loading && !force) return state.loading;

    state.loading = api('library', {limit:200})
      .then(data => {
        state.library = Array.isArray(data.recordings) ? data.recordings : [];
        proof.libraryLoads += 1;
        return state.library;
      })
      .catch(error => {
        proof.lastError = String(error?.message || error);
        return state.library;
      })
      .finally(() => {
        state.loading = null;
      });

    return state.loading;
  }

  function findItem(sessionId, key) {
    sessionId = Number(sessionId || 0);
    key = String(key || '');
    return state.library.find(item =>
      Number(item.session_id || 0) === sessionId &&
      String(item.key || '') === key
    ) || null;
  }

  function findByWords(words) {
    const query = normalize(words);
    if (!query) return null;
    return state.library.find(item => normalize(item.name) === query)
      || state.library.find(item => normalize(item.session_title) === query)
      || state.library.find(item => itemText(item).includes(query))
      || null;
  }

  function findMany(words, options = {}) {
    const query = normalize(words);
    return state.library.filter(item => {
      if (options.today && !isToday(item)) return false;
      if (options.favorites && !item.favorite) return false;
      return !query || itemText(item).includes(query);
    }).slice(0, CHAT_LIMIT);
  }

  async function renameItem(item, name = '') {
    if (!item) return null;

    let next = clean(name);
    if (!next) {
      const entered = window.prompt(
        'Rename this recording:',
        String(item.name || 'Recording')
      );
      if (entered === null) return null;
      next = clean(entered);
    }

    if (!next) return null;

    const data = await api('rename', {
      session_id:item.session_id,
      recording_key:item.key,
      name:next,
    }, 'POST');

    proof.renames += 1;
    await loadLibrary(true);
    syncAllViews();
    return data.recording || findItem(item.session_id, item.key);
  }

  async function favoriteItem(item, favorite = !item?.favorite) {
    if (!item) return null;

    const data = await api('favorite', {
      session_id:item.session_id,
      recording_key:item.key,
      favorite:!!favorite,
    }, 'POST');

    proof.favorites += 1;
    await loadLibrary(true);
    syncAllViews();
    return data.recording || findItem(item.session_id, item.key);
  }

  async function deleteItem(item, confirmUser = true) {
    if (!item) return false;

    if (confirmUser && !window.confirm(
      `Delete “${item.name || 'this recording'}”? The transcription itself will remain.`
    )) {
      return false;
    }

    await api('delete', {
      session_id:item.session_id,
      recording_key:item.key,
    }, 'POST');

    proof.deletes += 1;

    if (state.current && itemId(state.current) === itemId(item)) {
      state.current = null;
    }

    await loadLibrary(true);
    removeRenderedItem(item);
    syncAllViews();
    return true;
  }

  function stopAllPlayback() {
    document.querySelectorAll(
      'audio[data-v206-recording-audio],audio[data-v198-recording-audio],audio.chat-transcription-audio,[data-listening-workspace-recording-audio]'
    ).forEach(audio => {
      try { audio.pause(); } catch (error) {}
    });
  }

  function highlightWorkspaceTurn(audio, item) {
    const workspace = document.querySelector('.sf-transcript-workspace');
    if (!workspace || !audio || !item) return;

    const absoluteMs =
      Number(item.started_ms || 0) +
      Math.max(0, Number(audio.currentTime || 0) * 1000);

    const rows = [...workspace.querySelectorAll('[data-listening-workspace-turn]')];
    let selected = null;

    for (const row of rows) {
      const at = Number(row.querySelector('[data-listening-workspace-time]')?.dataset.listeningWorkspaceTime || 0);
      if (at <= absoluteMs) selected = row;
      else break;
    }

    rows.forEach(row => {
      row.classList.toggle('sf-v198-playing-turn', row === selected);
    });
  }

  function recordingParts(audio) {
    try {
      const url = new URL(
        audio.currentSrc || audio.getAttribute('src') || '',
        location.href
      );
      return {
        sessionId:Math.max(0, Number(url.searchParams.get('session_id') || 0)),
        key:String(url.searchParams.get('recording_key') || ''),
      };
    } catch (error) {
      return {sessionId:0,key:''};
    }
  }

  function workspaceButton(label, action, item) {
    const button = document.createElement('button');
    button.type = 'button';
    button.className = 'sf-v198-small-btn';
    button.textContent = label;
    button.dataset.v198Action = action;
    button.dataset.v198Session = String(item.session_id || 0);
    button.dataset.v198Key = String(item.key || '');
    return button;
  }

  function enhanceWorkspaceRows() {
    const target = document.querySelector('[data-listening-workspace-recordings]');
    if (!target) return;

    let missingLibraryItem = false;

    for (const article of target.querySelectorAll('.sf-listening-workspace-recording')) {
      const audio = article.querySelector('audio[data-listening-workspace-recording-audio]');
      if (!audio) continue;

      const parts = recordingParts(audio);
      const item = findItem(parts.sessionId, parts.key);

      if (!item) {
        if (parts.sessionId > 0 && parts.key) missingLibraryItem = true;
        continue;
      }

      if (article.dataset.v198Session !== String(item.session_id)) article.dataset.v198Session = String(item.session_id);
      if (article.dataset.v198Key !== item.key) article.dataset.v198Key = item.key;
      if (audio.dataset.v198RecordingAudio !== '1') audio.dataset.v198RecordingAudio = '1';
      if (audio.dataset.v198Session !== String(item.session_id)) audio.dataset.v198Session = String(item.session_id);
      if (audio.dataset.v198Key !== item.key) audio.dataset.v198Key = item.key;

      const title = article.querySelector('strong');
      if (title && title.textContent !== item.name) title.textContent = item.name;

      let actions = article.querySelector('[data-v198-workspace-actions]');

      if (!actions) {
        actions = document.createElement('div');
        actions.className = 'sf-v198-workspace-actions';
        actions.dataset.v198WorkspaceActions = '1';
        actions.append(
          workspaceButton(
            item.favorite ? '★ Favorite' : '☆ Favorite',
            'favorite',
            item
          ),
          workspaceButton('Rename', 'rename', item)
        );

        const download = document.createElement('a');
        download.className = 'sf-v198-small-btn';
        download.textContent = 'Download';
        download.href = item.url;
        download.download =
          clean(item.name || 'recording').replace(/[^a-z0-9_-]+/gi,'-') ||
          'recording';

        actions.appendChild(download);
        actions.appendChild(workspaceButton('Delete', 'delete', item));
        article.appendChild(actions);
      } else {
        const favorite = actions.querySelector('[data-v198-action="favorite"]');
        if (favorite) {
          const favoriteLabel = item.favorite ? '★ Favorite' : '☆ Favorite';
          if (favorite.textContent !== favoriteLabel) favorite.textContent = favoriteLabel;
        }
      }

      if (!audio.dataset.v198Bound) {
        audio.dataset.v198Bound = '1';

        audio.addEventListener('play', () => {
          const current = findItem(parts.sessionId, parts.key) || item;
          document.querySelectorAll('audio[data-v198-recording-audio]')
            .forEach(other => {
              if (other !== audio) other.pause();
            });
          state.current = current;
          proof.plays += 1;
        });

        audio.addEventListener('timeupdate', () => {
          highlightWorkspaceTurn(
            audio,
            findItem(parts.sessionId, parts.key) || item
          );
        });

        audio.addEventListener('ended', () => {
          document.querySelectorAll('.sf-v198-playing-turn')
            .forEach(row => row.classList.remove('sf-v198-playing-turn'));
        });
      }
    }

    if (
      missingLibraryItem &&
      !state.workspaceRefreshing &&
      !state.workspaceRefreshQueued
    ) {
      state.workspaceRefreshQueued = true;

      queueMicrotask(() => {
        state.workspaceRefreshQueued = false;
        if (state.workspaceRefreshing) return;

        state.workspaceRefreshing = true;

        void loadLibrary(true)
          .then(() => enhanceWorkspaceRows())
          .catch(showError)
          .finally(() => {
            state.workspaceRefreshing = false;
          });
      });
    }
  }

  async function initWorkspace() {
    const workspace = document.querySelector('.sf-transcript-workspace');
    if (!workspace) return;

    await loadLibrary();
    enhanceWorkspaceRows();

    const target = workspace.querySelector('[data-listening-workspace-recordings]');
    if (target && !state.workspaceObserver) {
      let enhancementQueued = false;
      state.workspaceObserver = new MutationObserver(() => {
        if (enhancementQueued) return;
        enhancementQueued = true;
        requestAnimationFrame(() => {
          enhancementQueued = false;
          enhanceWorkspaceRows();
        });
      });
      state.workspaceObserver.observe(target, {
        childList:true,
        subtree:true,
      });
    }

    workspace.addEventListener('click', event => {
      const control = event.target.closest?.('[data-v198-action]');
      if (!control) return;

      const item = findItem(
        control.dataset.v198Session,
        control.dataset.v198Key
      );
      if (!item) return;

      event.preventDefault();
      event.stopPropagation();

      const action = String(control.dataset.v198Action || '');
      if (action === 'rename') void renameItem(item).catch(showError);
      if (action === 'favorite') void favoriteItem(item).catch(showError);
      if (action === 'delete') void deleteItem(item).catch(showError);
    }, true);
  }

  function chatThread() {
    return document.getElementById('chatThread');
  }

  function removeLegacyChatCanvas() {
    if (!chatThread()) return;

    document.querySelectorAll(
      '#chatRecordingsCanvas,.chat-recordings-canvas'
    ).forEach(canvas => {
      if (!canvas.closest?.('[data-v206-recording-card]')) {
        canvas.remove();
      }
    });
  }

  function loadSeenState() {
    if (state.seenLoaded) return true;
    state.seenLoaded = true;

    try {
      const raw = localStorage.getItem(SEEN_KEY);
      if (!raw) return false;

      const values = JSON.parse(raw);
      if (!Array.isArray(values)) return false;

      values.forEach(value => {
        const id = String(value || '');
        if (id) state.seen.add(id);
      });
      return true;
    } catch (error) {
      return false;
    }
  }

  function persistSeenState() {
    try {
      const values = [...state.seen];
      const bounded = values.length > 600
        ? values.slice(values.length - 600)
        : values;
      localStorage.setItem(SEEN_KEY, JSON.stringify(bounded));
    } catch (error) {}
  }

  function markSeen(items) {
    let changed = false;

    for (const item of items || []) {
      const id = itemId(item);
      if (!id || state.seen.has(id)) continue;
      state.seen.add(id);
      changed = true;
    }

    if (changed) persistSeenState();
  }

  function chatCard(item) {
    const association = clean(item?.association?.label || '');
    const excerpt = clean(item?.transcript_excerpt || '');
    const meta = [
      formatDate(item?.created_at),
      formatTime(item?.duration_ms),
      association && association.toLowerCase() !== 'unassigned'
        ? association
        : '',
    ].filter(Boolean).join(' · ');

    const downloadName =
      clean(item?.name || 'recording').replace(/[^a-z0-9_-]+/gi,'-') ||
      'recording';

    return `<article class="chat-recording-card sf-v206-inline-recording-card" data-v206-recording-card data-v206-recording-id="${escapeHtml(itemId(item))}" data-v206-session="${Number(item?.session_id || 0)}" data-v206-key="${escapeHtml(item?.key || '')}">
      <div class="chat-recording-card-head sf-v206-recording-head">
        <span>
          <strong data-v206-recording-title>${escapeHtml(item?.name || 'Recording')}</strong>
          <small>${escapeHtml(clean(item?.session_title || 'Voice memo'))}${meta ? ` · ${escapeHtml(meta)}` : ''}</small>
        </span>
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
      ${excerpt ? `<p class="sf-v206-recording-excerpt">${escapeHtml(excerpt)}</p>` : ''}
      <audio class="chat-transcription-audio" data-v206-recording-audio controls preload="metadata" src="${escapeHtml(item?.url || '')}"></audio>
    </article>`;
  }

  function buildAssistantMessage(content, extraClass = '') {
    const message = document.createElement('div');
    message.className = `message assistant ${extraClass}`.trim();
    message.innerHTML = `<div class="message-avatar" aria-hidden="true">S</div>
      <div class="message-body">
        <div class="message-role">Stonefellow</div>
        <div class="message-text">${content}</div>
      </div>`;
    return message;
  }

  function appendAssistantText(text) {
    const thread = chatThread();
    if (!thread) return null;

    removeLegacyChatCanvas();

    const message = buildAssistantMessage(
      `<p class="sf-v206-result-copy">${escapeHtml(text)}</p>`,
      'sf-v206-recording-message'
    );

    thread.appendChild(message);
    requestAnimationFrame(() => {
      message.scrollIntoView({block:'nearest',behavior:'smooth'});
    });
    return message;
  }

  function appendInlineResults(items, options = {}) {
    const thread = chatThread();
    if (!thread) return null;

    const rows = Array.isArray(items)
      ? items.filter(Boolean).slice(0, CHAT_LIMIT)
      : [];

    if (!rows.length) {
      return appendAssistantText(
        options.emptyMessage || 'I could not find any matching recordings.'
      );
    }

    removeLegacyChatCanvas();

    const isNew = Boolean(options.newRecording);
    const heading = clean(options.heading) || (
      isNew
        ? (rows.length === 1 ? 'New recording saved.' : `${rows.length} new recordings saved.`)
        : (rows.length === 1 ? 'I found 1 recording.' : `I found ${rows.length} recordings.`)
    );

    const content = `${
      isNew
        ? '<div class="sf-v206-recording-kicker">ARTIST LISTENING · NEW RECORDING</div>'
        : ''
    }<p class="sf-v206-result-copy">${escapeHtml(heading)}</p>
      <div class="sf-v206-recording-results">${rows.map(chatCard).join('')}</div>`;

    const message = buildAssistantMessage(
      content,
      `sf-v206-recording-message${isNew ? ' is-new-recording' : ''}`
    );

    thread.appendChild(message);
    markSeen(rows);

    if (isNew) proof.newRecordingNotices += rows.length;
    else proof.inlineResults += rows.length;

    requestAnimationFrame(() => {
      message.scrollIntoView({block:'nearest',behavior:'smooth'});
    });

    return message;
  }

  function findRenderedCard(item) {
    const id = itemId(item);
    return [...document.querySelectorAll('[data-v206-recording-card]')]
      .find(card => String(card.dataset.v206RecordingId || '') === id) || null;
  }

  function removeRenderedItem(item) {
    const id = itemId(item);

    document.querySelectorAll('[data-v206-recording-card]').forEach(card => {
      if (String(card.dataset.v206RecordingId || '') !== id) return;

      const message = card.closest('.sf-v206-recording-message');
      card.remove();

      if (
        message &&
        !message.querySelector('[data-v206-recording-card]')
      ) {
        message.remove();
      }
    });
  }

  function refreshRenderedCards() {
    document.querySelectorAll('[data-v206-recording-card]').forEach(card => {
      const item = findItem(
        card.dataset.v206Session,
        card.dataset.v206Key
      );

      if (!item) return;

      const title = card.querySelector('[data-v206-recording-title]');
      if (title) title.textContent = String(item.name || 'Recording');

      const favorite = card.querySelector('[data-v206-action="favorite"]');
      if (favorite) {
        favorite.textContent = item.favorite
          ? 'Remove favorite'
          : 'Favorite';
      }
    });
  }

  async function playItem(item, seekAbsoluteSeconds = null) {
    if (!item) {
      appendAssistantText('I could not find that recording.');
      return false;
    }

    let card = findRenderedCard(item);

    if (!card) {
      appendInlineResults([item], {
        heading:`Here is ${item.name || 'that recording'}.`,
      });
      card = findRenderedCard(item);
    }

    const audio = card?.querySelector('audio[data-v206-recording-audio]');
    if (!audio) return false;

    document.querySelectorAll('audio[data-v206-recording-audio]').forEach(other => {
      if (other !== audio) other.pause();
    });

    state.current = item;

    if (seekAbsoluteSeconds !== null) {
      const clipStart = Number(item.started_ms || 0) / 1000;
      const local = Math.max(
        0,
        Number(seekAbsoluteSeconds) - clipStart
      );
      const duration = Number(item.duration_ms || 0) / 1000;
      audio.currentTime = duration > 0
        ? Math.min(local, duration)
        : local;
    }

    try {
      await audio.play();
      proof.plays += 1;
      card.scrollIntoView({block:'nearest',behavior:'smooth'});
      return true;
    } catch (error) {
      appendAssistantText(
        `“${item.name || 'The recording'}” is ready. Tap Play if your browser blocked automatic playback.`
      );
      return false;
    }
  }

  function selectByAbsoluteTime(seconds) {
    const ms = Math.max(0, Number(seconds || 0) * 1000);
    return state.library.find(item =>
      ms >= Number(item.started_ms || 0) &&
      ms <= Number(item.ended_ms || 0)
    ) || null;
  }

  function clearChatInput(form) {
    const input =
      form?.querySelector('#chatInput') ||
      document.getElementById('chatInput');
    if (input) input.value = '';
  }

  async function handleAgentCommand(text, form, event) {
    const heard = normalize(text);
    if (!heard) return false;

    let match;

    const intercept = () => {
      event.preventDefault();
      event.stopImmediatePropagation();
      clearChatInput(form);
      proof.agentCommands += 1;
    };

    if (/^(?:show|open|search)(?: my)? recordings(?: from today)?$/.test(heard)) {
      intercept();
      await loadLibrary();
      const today = heard.includes('today');
      appendInlineResults(
        findMany('', {today}),
        {
          heading:today
            ? 'Here are your recordings from today.'
            : 'Here are your latest recordings.',
          emptyMessage:today
            ? 'You do not have any recordings from today.'
            : 'You do not have any recordings yet.',
        }
      );
      return true;
    }

    if (/^(?:show|open)(?: my)? favorite recordings$/.test(heard)) {
      intercept();
      await loadLibrary();
      appendInlineResults(
        findMany('', {favorites:true}),
        {
          heading:'Here are your favorite recordings.',
          emptyMessage:'You do not have any favorite recordings yet.',
        }
      );
      return true;
    }

    if (
      (match = heard.match(
        /^(?:find|search|show)(?: for)? (?:my )?recordings? (?:where|with|about|for) (.+)$/
      ))
    ) {
      intercept();
      await loadLibrary();
      appendInlineResults(
        findMany(match[1]),
        {
          heading:`Recording results for “${match[1]}”.`,
          emptyMessage:`I could not find any recordings matching “${match[1]}”.`,
        }
      );
      return true;
    }

    if (/^play (?:my )?(?:last|latest) recording$/.test(heard)) {
      intercept();
      await loadLibrary();
      await playItem(state.library[0] || null);
      return true;
    }

    if (
      (match = heard.match(
        /^play (?:the )?recording (?:where|with|about) (.+)$/
      ))
    ) {
      intercept();
      await loadLibrary();
      await playItem(findByWords(match[1]));
      return true;
    }

    if (
      (match = heard.match(
        /^play recording at (\d+(?::\d+){1,2})$/
      ))
    ) {
      intercept();
      await loadLibrary();
      const seconds = parseClock(match[1]);
      const item = seconds === null
        ? null
        : selectByAbsoluteTime(seconds);
      await playItem(item, seconds);
      return true;
    }

    if (
      (match = heard.match(
        /^play recording (.+?)(?: at (\d+(?::\d+){1,2}))?$/
      ))
    ) {
      intercept();
      await loadLibrary();
      const item = findByWords(match[1]);
      const seconds = match[2]
        ? parseClock(match[2])
        : null;
      await playItem(item, seconds);
      return true;
    }

    if (/^stop (?:audio |recording )?playback$/.test(heard)) {
      intercept();
      stopAllPlayback();
      appendAssistantText('Recording playback stopped.');
      return true;
    }

    if (
      (match = heard.match(
        /^rename this recording(?: to)? (.+)$/
      ))
    ) {
      intercept();
      await loadLibrary();

      if (state.current) {
        await renameItem(
          findItem(
            state.current.session_id,
            state.current.key
          ) || state.current,
          match[1]
        );
      } else {
        appendAssistantText('Play or select a recording first.');
      }

      return true;
    }

    if (
      (match = heard.match(
        /^rename recording (.+?) to (.+)$/
      ))
    ) {
      intercept();
      await loadLibrary();

      const item = findByWords(match[1]);
      if (item) {
        await renameItem(item, match[2]);
      } else {
        appendAssistantText('I could not find that recording.');
      }

      return true;
    }

    if (/^show (?:the )?transcript for this recording$/.test(heard)) {
      intercept();

      if (state.current?.open_url) {
        location.assign(state.current.open_url);
      } else {
        appendAssistantText('Play or select a recording first.');
      }

      return true;
    }

    return false;
  }

  async function pollNewRecordings() {
    if (!chatThread() || document.hidden) return;

    await loadLibrary(true);

    const fresh = state.library.filter(item =>
      !state.seen.has(itemId(item))
    );

    if (!fresh.length) return;

    appendInlineResults(
      fresh.slice(0, CHAT_LIMIT),
      {newRecording:true}
    );
    markSeen(fresh);
  }

  async function initChat() {
    const form = document.getElementById('chatForm');
    const thread = chatThread();
    if (!form || !thread) return;

    removeLegacyChatCanvas();

    if (!state.chatObserver) {
      state.chatObserver = new MutationObserver(() => {
        removeLegacyChatCanvas();
      });
      state.chatObserver.observe(document.body, {
        childList:true,
        subtree:true,
      });
    }

    const hadSeenState = loadSeenState();
    await loadLibrary();

    if (!hadSeenState) {
      markSeen(state.library);
    } else {
      const fresh = state.library.filter(item =>
        !state.seen.has(itemId(item))
      );

      if (fresh.length) {
        appendInlineResults(
          fresh.slice(0, CHAT_LIMIT),
          {newRecording:true}
        );
        markSeen(fresh);
      }
    }

    form.addEventListener('submit', event => {
      const input =
        form.querySelector('#chatInput') ||
        document.getElementById('chatInput');

      void handleAgentCommand(
        String(input?.value || ''),
        form,
        event
      ).catch(showError);
    }, true);

    document.addEventListener('click', event => {
      const action = event.target.closest?.('[data-v206-action]');
      if (!action) return;

      const card = action.closest('[data-v206-recording-card]');
      const item = findItem(
        card?.dataset.v206Session,
        card?.dataset.v206Key
      );
      if (!item) return;

      event.preventDefault();
      const details = action.closest('details');
      if (details) details.open = false;

      const name = String(action.dataset.v206Action || '');
      if (name === 'favorite') {
        void favoriteItem(item).catch(showError);
      }
      if (name === 'rename') {
        void renameItem(item).catch(showError);
      }
      if (name === 'delete') {
        void deleteItem(item).catch(showError);
      }
    });

    document.addEventListener('play', event => {
      const audio = event.target.closest?.(
        'audio[data-v206-recording-audio]'
      );
      if (!audio) return;

      const card = audio.closest('[data-v206-recording-card]');
      const item = findItem(
        card?.dataset.v206Session,
        card?.dataset.v206Key
      );
      if (!item) return;

      document.querySelectorAll(
        'audio[data-v206-recording-audio]'
      ).forEach(other => {
        if (other !== audio) other.pause();
      });

      state.current = item;
    }, true);

    state.pollTimer = window.setInterval(() => {
      void pollNewRecordings().catch(error => {
        proof.lastError = String(error?.message || error);
      });
    }, POLL_MS);

    document.addEventListener('visibilitychange', () => {
      if (!document.hidden) {
        void pollNewRecordings().catch(error => {
          proof.lastError = String(error?.message || error);
        });
      }
    });

    window.addEventListener('stonefellow:recording-saved', () => {
      void pollNewRecordings().catch(error => {
        proof.lastError = String(error?.message || error);
      });
    });
  }

  function injectStyles() {
    if (document.getElementById('artistRecordingsV198Styles')) return;

    const style = document.createElement('style');
    style.id = 'artistRecordingsV198Styles';
    style.textContent = `
      .sf-v198-workspace-actions{display:flex;align-items:center;gap:6px;flex-wrap:wrap;margin-top:7px}
      .sf-v198-small-btn,.sf-v198-workspace-actions a{appearance:none;border:1px solid rgba(255,255,255,.12);border-radius:6px;background:rgba(255,255,255,.04);color:#cfc7c0;padding:5px 7px;font:700 10px/1.1 inherit;text-decoration:none;cursor:pointer}
      .sf-v198-small-btn:hover,.sf-v198-workspace-actions a:hover{background:rgba(255,255,255,.09);color:#fff}
      .sf-v198-playing-turn{outline:1px solid rgba(187,160,238,.75);background:rgba(187,160,238,.08)!important}
      #chatRecordingsCanvas,.chat-recordings-canvas{display:none!important}
      .sf-v206-recording-message .message-text{display:grid;gap:8px;max-width:790px}
      .sf-v206-result-copy{margin:0;color:inherit}
      .sf-v206-recording-kicker{margin-bottom:1px;color:#8f8175;font-size:.57rem;font-weight:850;letter-spacing:.1em;text-transform:uppercase}
      .sf-v206-recording-results{display:grid;gap:8px;width:100%;margin-top:2px}
      .sf-v206-inline-recording-card{position:relative;gap:8px;width:100%;box-sizing:border-box}
      .sf-v206-recording-head{align-items:flex-start}
      .sf-v206-recording-excerpt{margin:0;color:#968c84;font-size:.66rem;line-height:1.45;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden}
      .sf-v206-recording-menu{position:relative;flex:0 0 auto}
      .sf-v206-recording-menu>summary{display:grid;place-items:center;width:30px;height:30px;border-radius:8px;color:#a99f97;cursor:pointer;list-style:none;font-size:1rem;line-height:1}
      .sf-v206-recording-menu>summary::-webkit-details-marker{display:none}
      .sf-v206-recording-menu>summary:hover,.sf-v206-recording-menu[open]>summary{background:rgba(255,255,255,.07);color:#fff}
      .sf-v206-recording-menu-popover{position:absolute;z-index:40;top:34px;right:0;display:grid;min-width:150px;padding:5px;border:1px solid rgba(255,255,255,.12);border-radius:9px;background:#171411;box-shadow:0 12px 34px rgba(0,0,0,.42)}
      .sf-v206-recording-menu-popover button,.sf-v206-recording-menu-popover a{display:block;width:100%;padding:8px 9px;border:0;border-radius:6px;background:transparent;color:#d5cbc1;font:700 11px/1.2 inherit;text-align:left;text-decoration:none;cursor:pointer;box-sizing:border-box}
      .sf-v206-recording-menu-popover button:hover,.sf-v206-recording-menu-popover a:hover{background:rgba(255,255,255,.07);color:#fff}
      .sf-v206-recording-menu-popover .danger{color:#dd9b9b}
      .sf-v206-inline-recording-card .chat-transcription-audio{display:block;width:100%;min-width:0;height:34px}
      @media(max-width:640px){
        .sf-v206-recording-message .message-body{min-width:0}
        .sf-v206-recording-message .message-text{min-width:0}
        .sf-v206-recording-menu-popover{right:0;min-width:140px}
      }
    `;

    document.head.appendChild(style);
  }

  function syncAllViews() {
    enhanceWorkspaceRows();
    refreshRenderedCards();
    removeLegacyChatCanvas();
  }

  function showError(error) {
    proof.lastError = String(
      error?.message ||
      error ||
      'Recording action failed.'
    );

    if (chatThread()) {
      appendAssistantText(proof.lastError);
    } else {
      window.alert(proof.lastError);
    }
  }


  function transcriptionRecordingState(){
    return {
      library:state.library.map(item=>({...item})),
      current:state.current?{...state.current}:null,
      loading:!!state.loading,
      lastError:String(proof.lastError||''),
    };
  }
  function transcriptionResolveRecording(args={}){
    const sessionId=Math.max(0,Number(args.sessionId||0));
    const key=String(args.key||'');
    if(sessionId&&key)return findItem(sessionId,key);
    if(args.query)return findByWords(args.query);
    return state.current||state.library[0]||null;
  }
  proof.api={
    getState:transcriptionRecordingState,
    getSelection:()=>state.current?{...state.current}:null,
    refresh:async()=>{await loadLibrary(true);return transcriptionRecordingState();},
    search:async(args={})=>{await loadLibrary();return findMany(String(args.query||''),{today:!!args.today,favorites:!!args.favorites}).map(item=>({...item}));},
    select:async(args={})=>{await loadLibrary();const item=transcriptionResolveRecording(args);if(!item)throw new Error('Recording not found.');state.current=item;return {...item};},
    play:async(args={})=>{await loadLibrary();const item=transcriptionResolveRecording(args);if(!item)throw new Error('Recording not found.');const ok=await playItem(item,args.seconds===undefined?null:Number(args.seconds));if(!ok)throw new Error('Recording playback did not start.');state.current=item;return {...item};},
    stop:()=>{stopAllPlayback();return true;},
    rename:async(args={})=>{await loadLibrary();const item=transcriptionResolveRecording(args);if(!item)throw new Error('Recording not found.');const updated=await renameItem(item,String(args.name||''));if(updated&&state.current&&itemId(state.current)===itemId(item))state.current=updated;return updated;},
    favorite:async(args={})=>{await loadLibrary();const item=transcriptionResolveRecording(args);if(!item)throw new Error('Recording not found.');const updated=await favoriteItem(item,args.favorite===undefined?!item.favorite:!!args.favorite);if(updated&&state.current&&itemId(state.current)===itemId(item))state.current=updated;return updated;},
    delete:async(args={})=>{await loadLibrary();const item=transcriptionResolveRecording(args);if(!item)throw new Error('Recording not found.');const sessionId=Number(item.session_id||0),key=String(item.key||'');const deleted=await deleteItem(item,false);return {deleted:deleted===true,sessionId,key};},
  };

  async function start() {
    injectStyles();
    removeLegacyChatCanvas();
    await Promise.all([
      initWorkspace(),
      initChat(),
    ]);
  }

  window.addEventListener('pagehide', () => {
    state.workspaceObserver?.disconnect();
    state.chatObserver?.disconnect();

    if (state.pollTimer) {
      clearInterval(state.pollTimer);
      state.pollTimer = 0;
    }

    stopAllPlayback();
  }, {once:true});

  if (document.readyState === 'loading') {
    document.addEventListener(
      'DOMContentLoaded',
      () => void start(),
      {once:true}
    );
  } else {
    void start();
  }
})();
