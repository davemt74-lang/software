(() => {
  'use strict';

  const config = window.VP3_MUSIC_CHAT_CONFIG_V1 || {};
  const idPattern = /^[a-f0-9]{32}$/;
  const revisionPattern = /^[a-f0-9]{64}$/;
  const records = new Set();
  const attached = new WeakMap();
  const consumed = new Set();
  let generation = 0;
  let active = null;
  let queueState = null;
  let retry = null;
  const queueLimit = 100;

  const node = (tag, className = '', text = '') => {
    const element = document.createElement(tag);
    if (className) element.className = className;
    if (text) element.textContent = text;
    return element;
  };
  const button = (label, handler, className = '') => {
    const element = node('button', className, label);
    element.type = 'button';
    element.addEventListener('click', handler);
    return element;
  };
  const text = (value, limit = 200) => typeof value === 'string' ? value.slice(0, limit) : '';
  const currentConversation = () => Number(window.STONEFELLOW_CHAT_CONTINUITY?.conversationId?.()
    ?? window.STONEFELLOW_CHAT?.initialConversationId ?? 0);
  function currentScope(scope, playback = false) {
    const live = window.STONEFELLOW_CHAT || {};
    return Number.isSafeInteger(scope?.owner_user_id) && scope.owner_user_id > 0
      && scope.owner_user_id === Number(config.userId) && scope.owner_user_id === Number(live.userId)
      && Number.isSafeInteger(scope.agent_id) && scope.agent_id === Number(config.agentId || 0)
      && scope.agent_id === Number(live.agentId || 0)
      && Number.isSafeInteger(scope.conversation_id) && scope.conversation_id > 0
      && scope.conversation_id === currentConversation()
      && String(live.csrf || '') === String(config.csrf || '')
      && !window.VP3ParticipantContinuity?.selectedContext?.()
      && (!playback || !document.hidden);
  }
  function validItem(item) {
    return item && ['vp3', 'youtube'].includes(item.source) && typeof item.source_id === 'string'
      && (item.available === false && item.source_id === '' && idPattern.test(item.item_id || '')
        || (item.source === 'vp3' ? /^[1-9][0-9]{0,15}$/.test(item.source_id) : /^[A-Za-z0-9_-]{11}$/.test(item.source_id)))
      && typeof item.title === 'string' && item.title.length <= 300 && typeof item.available === 'boolean';
  }
  function validPayload(payload) {
    return payload?.protocol === 'vp3_music_chat_v1' && payload.version === 1 && currentScope(payload)
      && Array.isArray(payload.items) && payload.items.length <= 25 && payload.items.every(validItem)
      && (payload.playlist
        ? idPattern.test(payload.playlist.id || '') && payload.playlist.visibility === 'private'
          && Number.isSafeInteger(payload.playlist.revision) && payload.playlist.revision > 0
        : idPattern.test(payload.result_id || '') && revisionPattern.test(payload.revision || ''));
  }
  function current(record, playback = false) {
    return record.card.isConnected && records.has(record) && currentScope(record.payload, playback);
  }
  function scopeFields(scope) {
    return {agent_id:scope.agent_id, conversation_id:scope.conversation_id};
  }
  function selectionFields(payload) {
    return payload.playlist
      ? {playlist_id:payload.playlist.id, revision:payload.playlist.revision}
      : {result_id:payload.result_id, revision:payload.revision};
  }
  function sameScope(left, right) {
    return ['owner_user_id', 'agent_id', 'conversation_id'].every(key => left[key] === right[key]);
  }
  async function api(scope, action, fields = {}) {
    if (!currentScope(scope)) throw new Error('This music selection belongs to another Chat.');
    let endpoint;
    try { endpoint = new URL(config.endpoint, location.href); } catch { throw new Error('Music is unavailable.'); }
    if (endpoint.origin !== location.origin) throw new Error('Music is unavailable.');
    const controller = new AbortController();
    const timeout = setTimeout(() => controller.abort(), 15000);
    try {
      const response = await fetch(endpoint, {method:'POST', credentials:'same-origin', cache:'no-store',
        headers:{'Content-Type':'application/json', 'Accept':'application/json'}, signal:controller.signal,
        body:JSON.stringify({action, csrf_token:config.csrf, ...scopeFields(scope), ...fields})});
      const result = await response.json();
      if (!currentScope(scope)) throw new Error('The account or Chat changed.');
      if (!response.ok || result.ok !== true) {
        const error = new Error(response.status === 409
          ? 'This selection changed. Refresh it before continuing.'
          : text(result.error || result.message, 300) || 'Music is temporarily unavailable. Try again.');
        error.status = response.status;
        throw error;
      }
      const projected = result.music_chat_v1 || result;
      if (action !== 'playlist_remove' && !sameScope(scope, projected)) throw new Error('Music response belongs to another Chat.');
      return result;
    } catch (error) {
      if (error.name === 'AbortError') throw new Error('Music did not respond in time. Try again.');
      throw error;
    } finally { clearTimeout(timeout); }
  }
  function status(record, message, state = '') {
    if (!current(record)) return;
    record.status.textContent = message;
    record.status.dataset.musicState = state;
    record.status.hidden = !message;
  }
  function artwork(item) {
    const value = item.cover || item.artwork || item.thumbnail_url;
    if (typeof value !== 'string' || value.length > 2048) return '';
    try {
      const url = new URL(value, location.href);
      if (url.username || url.password || !['http:', 'https:'].includes(url.protocol)) return '';
      if (url.origin !== location.origin && !(item.source === 'youtube' && url.protocol === 'https:'
          && ['i.ytimg.com', 'i1.ytimg.com', 'i2.ytimg.com', 'i3.ytimg.com', 'i4.ytimg.com', 'img.youtube.com'].includes(url.hostname))) return '';
      return url.href;
    } catch { return ''; }
  }
  function duration(value) {
    if (typeof value === 'string' && /^\d{1,3}:\d{2}(?::\d{2})?$/.test(value)) return value;
    if (typeof value !== 'number' || !Number.isFinite(value) || value <= 0) return '';
    const seconds = Math.floor(value);
    return Math.floor(seconds / 60) + ':' + String(seconds % 60).padStart(2, '0');
  }
  function cleanupActive(nextState = 'stopped', keepQueue = false) {
    const previous = active;
    const previousQueue = queueState;
    active = null;
    if (!keepQueue) queueState = null;
    generation += 1;
    if (!previous) {
      if (previousQueue?.hostRecord && current(previousQueue.hostRecord)) {
        if (!keepQueue) status(previousQueue.hostRecord, 'Stopped.', 'stopped');
        updateTransport(previousQueue.hostRecord);
      }
      return;
    }
    previous.cancel?.abort();
    try { previous.handle?.stop?.(); } catch {}
    if (current(previous.record)) {
      status(previous.record, nextState === 'paused' ? 'Paused.' : 'Stopped.', nextState);
      updateTransport(previous.record);
    }
  }
  function claimSource(source, handle) {
    if (!['vp3', 'youtube'].includes(source)) return;
    const token = source === 'vp3' ? handle?.dataset?.musicChatToken : handle?.musicToken;
    if (active && token !== active.token) cleanupActive();
    if (source === 'vp3') window.VP3_CHAT_YOUTUBE_V1?.pauseForMusic?.();
    else document.querySelectorAll('.chat-audio-player').forEach(audio => {
      if (!audio.paused) audio.pause();
    });
  }
  document.addEventListener('play', event => {
    if (event.target instanceof HTMLMediaElement && event.target.matches('.chat-audio-player')) claimSource('vp3', event.target);
  }, true);

  function playbackState(selection, state) {
    if (active !== selection || selection.generation !== generation || !current(selection.record)) return;
    selection.state = state;
    const labels = {starting:'Starting…', playing:'Playing', paused:'Paused', blocked:'Tap Play to start playback.',
      unavailable:'This song is unavailable. Choose another song or try again.', ended:'Finished.', stopped:'Stopped.'};
    status(selection.record, state === 'playing' ? 'Playing · ' + text(selection.item.title) : (labels[state] || 'Ready.'), state);
    updateTransport(selection.record);
    if (state === 'ended' && selection.queueState === queueState && !selection.advanced) {
      selection.advanced = true;
      if (queueState.position + 1 < queueState.entries.length) {
        if (document.hidden) status(selection.record, 'The next song is ready. Tap Next to continue.', 'paused');
        else void playQueueEntry(queueState, queueState.position + 1);
      }
    }
  }
  function updateTransport(record) {
    const selection = active?.record === record ? active : null;
    const queue = selection?.queueState || (!active && queueState?.hostRecord === record ? queueState : null);
    record.transport.hidden = !selection && !queue;
    if (!selection && !queue) return;
    record.transport.querySelector('[data-music-previous]').disabled = !queue || queue.position <= 0;
    record.transport.querySelector('[data-music-next]').disabled = !queue || queue.position >= queue.entries.length - 1;
    const toggle = record.transport.querySelector('[data-music-toggle]');
    toggle.textContent = selection?.state === 'playing' ? 'Pause' : 'Play';
    toggle.disabled = selection?.state === 'starting';
    record.transport.querySelector('[data-music-position]').textContent = queue
      ? (selection ? 'Song ' + (queue.position + 1) + ' of ' : 'Ready · ') + queue.entries.length + (selection ? '' : queue.entries.length === 1 ? ' song' : ' songs') : '';
  }
  function queueEntry(record, index) {
    return {record, payload:record.payload, index};
  }
  async function playQueueEntry(queue, position, autoAction = '') {
    const entry = queue?.entries[position];
    if (!entry || queue !== queueState) return false;
    if (!current(entry.record) || entry.record.payload !== entry.payload) {
      cleanupActive('stopped', true);
      queue.position = position;
      queue.hostRecord = current(entry?.record) ? entry.record : queue.hostRecord;
      if (current(queue.hostRecord)) {
        status(queue.hostRecord, 'This queued selection changed. Refresh it or choose another song.', 'unavailable');
        updateTransport(queue.hostRecord);
      }
      return false;
    }
    return playIndex(entry.record, entry.index, false, autoAction, {queue, position});
  }
  async function enqueue(record, indices, next = false, autoAction = '') {
    if (!current(record, true) || !indices.length || indices.some(index => !Number.isSafeInteger(index)
        || !record.payload.items[index]?.available)) return false;
    const observedQueue = queueState;
    const observedGeneration = generation;
    const payload = record.payload;
    if ((observedQueue?.entries.length || 0) + indices.length > queueLimit) {
      status(record, 'The queue can hold up to ' + queueLimit + ' songs. Start a new queue to add these songs.', 'unavailable');
      return false;
    }
    try {
      const result = await resolve(record, indices[0], autoAction);
      if (!current(record, true) || payload !== record.payload || observedGeneration !== generation || observedQueue !== queueState) {
        if (current(record)) status(record, 'The queue changed. Use Add to queue or Play next to try again.', 'ready');
        return false;
      }
      if (result.already_claimed) {
        status(record, 'This queue request was already handled. Use Add to queue to add it again.', 'ready');
        return false;
      }
      if ((queueState?.entries.length || 0) + indices.length > queueLimit) throw new Error('The queue can hold up to ' + queueLimit + ' songs.');
      const additions = indices.map(index => queueEntry(record, index));
      if (!queueState) queueState = {entries:additions, position:0, hostRecord:record};
      else {
        const insertion = next ? queueState.position + (active ? 1 : 0) : queueState.entries.length;
        queueState.entries.splice(insertion, 0, ...additions);
      }
      const owner = active?.record || queueState.hostRecord;
      updateTransport(owner);
      const count = additions.length;
      status(record, active
        ? (next ? 'Next in queue · ' : 'Added to queue · ') + count + (count === 1 ? ' song.' : ' songs.')
        : 'Queue ready · ' + queueState.entries.length + (queueState.entries.length === 1 ? ' song.' : ' songs.') + ' Tap Play to start.', 'queued');
      return true;
    } catch (error) {
      if (current(record)) status(record, error.message || 'This selection could not be queued.', 'unavailable');
      return false;
    }
  }
  async function resolve(record, index, autoAction = '') {
    const result = await api(record.payload, 'resolve', {...selectionFields(record.payload), index,
      ...(autoAction ? {auto_action_id:autoAction} : {})});
    if (!current(record, true)) throw new Error('Return to this Chat to play music.');
    if (result.already_claimed === true) return result;
    const expected = record.payload.items[index];
    if (!validItem(result.item) || !expected || expected.source !== result.item.source || expected.source_id !== result.item.source_id
        || result.item.available !== true) throw new Error('The selected song is no longer available. Refresh this selection.');
    return result;
  }
  async function playIndex(record, index, queue = false, autoAction = '', continuation = null) {
    if (!current(record, true) || !Number.isSafeInteger(index) || index < 0 || index >= record.payload.items.length) return false;
    const expected = record.payload.items[index];
    const selectedQueue = continuation?.queue || {entries:(queue ? record.payload.items.map((_, itemIndex) => queueEntry(record, itemIndex))
      : [queueEntry(record, index)]), position:queue ? index : 0, hostRecord:record};
    cleanupActive('stopped', true);
    queueState = selectedQueue;
    if (continuation) queueState.position = continuation.position;
    queueState.hostRecord = record;
    const selection = {record, index, queueState, item:expected, token:crypto.randomUUID(), generation:++generation,
      state:'starting', cancel:new AbortController(), advanced:false, handle:null};
    active = selection;
    if (!expected.available) { playbackState(selection, 'unavailable'); return false; }
    playbackState(selection, 'starting');
    try {
      const result = await resolve(record, index, autoAction);
      if (active !== selection || selection.generation !== generation || !current(record, true)) return false;
      if (result.already_claimed) {
        active = null;
        queueState = null;
        status(record, 'This playback request was already handled. Use Play to listen again.', 'ready');
        updateTransport(record);
        return false;
      }
      if (expected.source === 'vp3') {
        const bridge = window.STONEFELLOW_CHAT_CONTINUITY?.applyMediaPlayback;
        if (!result.media_playback || typeof bridge !== 'function') throw new Error('The VP3 player is unavailable.');
        const accepted = await bridge(result.media_playback, record.message, {
          musicChat:true,
          current:() => active === selection && selection.generation === generation && current(record, true),
          onReady(audio, lifecycle = {}) {
            if (active !== selection || selection.generation !== generation) return;
            audio.dataset.musicChatToken = selection.token;
            const signal = selection.cancel.signal;
            for (const event of ['playing', 'pause', 'ended', 'error']) audio.addEventListener(event, () => {
              playbackState(selection, event === 'pause' ? 'paused' : event === 'error' ? 'unavailable' : event);
            }, {signal});
            selection.handle = {pause:() => audio.pause(), resume:() => audio.play(), stop:() => {
              audio.pause();
              lifecycle.stop?.();
            }};
            claimSource('vp3', audio);
          },
          onState:state => playbackState(selection, state)
        });
        if (!accepted) {
          if (active === selection) playbackState(selection, 'unavailable');
          return false;
        }
      } else {
        const adapter = window.VP3_CHAT_YOUTUBE_V1?.playMusic;
        if (typeof adapter !== 'function' || !result.youtube_results) throw new Error('The YouTube player is unavailable.');
        const row = record.rows[index];
        selection.handle = adapter(row.player, row.play, result, {
          token:selection.token, onState:state => playbackState(selection, state)
        });
        if (!selection.handle) throw new Error('The YouTube selection could not be opened.');
      }
      return true;
    } catch (error) {
      if (active === selection && current(record)) {
        selection.state = 'unavailable';
        status(record, error.message || 'Playback is unavailable. Try again.', 'unavailable');
        updateTransport(record);
      }
      return false;
    }
  }
  async function control(action) {
    const selection = active;
    const record = selection?.record || queueState?.hostRecord;
    if (!record || !current(record, true)) return {answer:'There is no active music selection in this Chat.'};
    if (action === 'stop') { cleanupActive(); return {answer:'Stopped the music.'}; }
    if (action === 'pause') {
      if (!selection) return {answer:'The queue is ready and is not playing.'};
      if (selection.state === 'starting') {
        selection.cancel.abort();
        try { selection.handle?.stop?.(); } catch {}
        selection.handle = null;
        generation += 1;
        selection.state = 'paused';
        status(selection.record, 'Paused. Use Play to continue.', 'paused');
        updateTransport(selection.record);
        return {answer:'Paused the music request.'};
      }
      selection.handle?.pause?.();
      playbackState(selection, 'paused');
      return {answer:'Paused the music.'};
    }
    if (action === 'next' || action === 'previous') {
      const position = queueState?.position + (action === 'next' ? 1 : -1);
      if (!queueState || position < 0 || position >= queueState.entries.length) return {answer:'There is no ' + action + ' song in this selection.'};
      const opened = await playQueueEntry(queueState, position);
      return {answer:opened ? 'Opened the ' + action + ' song.'
        : 'The ' + action + ' song could not be opened. Check the music selection or try another song.'};
    }
    if (!selection) {
      const opened = await playQueueEntry(queueState, queueState.position);
      return {answer:opened ? 'Starting the queued song.' : 'The queued song could not be opened. Check the music selection.'};
    }
    try {
      await resolve(selection.record, selection.index);
      if (active !== selection || !current(selection.record, true)) return {answer:'The music selection changed.'};
      if (!selection.handle || selection.generation !== generation || ['stopped','ended','unavailable'].includes(selection.state)) {
        const opened = await playQueueEntry(selection.queueState, selection.queueState.position);
        return {answer:opened ? 'Resuming the selected song.' : 'The selected song could not be resumed. Check the music selection.'};
      }
      await selection.handle.resume?.();
      return {answer:'Resuming the selected song.'};
    } catch (error) {
      if (active === selection && current(selection.record)) {
        try { selection.handle?.pause?.(); } catch {}
        playbackState(selection, error.status ? 'unavailable' : 'blocked');
      }
      return {answer:error.message || 'Use Play to resume the selected song.'};
    }
  }
  function command(message) {
    const record = active?.record || queueState?.hostRecord;
    if (!record || !current(record, true)) return null;
    const phrase = String(message).trim().toLowerCase().replace(/[.!?]+$/, '').replace(/^(?:please |(?:can|could|would) you )+/, '');
    if (/^(?:play|start)(?: (?:the )?(?:music|song|track|playlist|queue))?$/.test(phrase)) return 'resume';
    const match = /^(?:play |skip to )?(pause|resume|continue|stop|next|previous)(?: (?:the )?(?:music|song|track|playlist|player))?$/.exec(phrase.replace(/ please$/, ''));
    return match ? (['continue', 'resume'].includes(match[1]) ? 'resume' : match[1]) : null;
  }
  function makeTransport(record) {
    const bar = node('div', 'vp3-music-transport');
    bar.hidden = true;
    const previous = button('Previous', () => void control('previous'));
    previous.dataset.musicPrevious = '';
    const toggle = button('Pause', () => void control(active?.state === 'playing' ? 'pause' : 'resume'));
    toggle.dataset.musicToggle = '';
    const next = button('Next', () => void control('next'));
    next.dataset.musicNext = '';
    const stop = button('Stop', () => void control('stop'));
    const position = node('span', 'vp3-music-position');
    position.dataset.musicPosition = '';
    bar.append(previous, toggle, next, stop, position);
    return bar;
  }
  async function mutate(record, action, fields) {
    if (!current(record) || record.busy) return;
    record.busy = true;
    record.card.setAttribute('aria-busy', 'true');
    try {
      const result = await api(record.payload, action, fields);
      if (!current(record)) return;
      const payload = result.music_chat_v1;
      if (!validPayload(payload)) throw new Error('The saved playlist could not be confirmed.');
      if (active?.record === record) cleanupActive();
      record.payload = payload;
      render(record);
      status(record, 'Playlist saved.', 'saved');
    } catch (error) { status(record, error.message); }
    finally { record.busy = false; record.card.removeAttribute('aria-busy'); }
  }
  function playlistOrder(record, from, to) {
    const items = record.payload.items.map(item => item.item_id);
    if (!items.every(id => idPattern.test(id || ''))) return;
    if (to === null) items.splice(from, 1);
    else [items[from], items[to]] = [items[to], items[from]];
    void mutate(record, 'playlist_update', {...selectionFields(record.payload), order:items});
  }
  async function removePlaylist(record) {
    if (!current(record) || record.busy || !record.payload.playlist) return;
    record.busy = true;
    try {
      const id = record.payload.playlist.id;
      const response = await api(record.payload, 'playlist_remove', selectionFields(record.payload));
      if (!current(record) || response.removed !== true) return;
      for (const saved of records) if (saved.payload.playlist?.id === id) {
        if (active?.record === saved) cleanupActive();
        saved.payload = {...saved.payload, items:[]};
        saved.pickerRevision += 1;
        saved.picker.replaceChildren(); saved.picker.hidden = true;
        saved.transport.hidden = true;
        saved.card.replaceChildren(node('p', '', 'Private playlist deleted.'),
          button('My playlists', () => void showSaved(saved)), saved.picker);
      }
    } catch (error) { status(record, error.message); }
    finally { record.busy = false; }
  }
  async function showSaved(record, addIndex = null, offset = 0) {
    if (!current(record)) return;
    record.picker.replaceChildren(node('p', '', 'Loading playlists…'));
    record.picker.hidden = false;
    const request = ++record.pickerRevision;
    try {
      const data = await api(record.payload, 'playlists', {limit:10, offset});
      if (!current(record) || request !== record.pickerRevision || !Array.isArray(data.playlists)) return;
      record.picker.replaceChildren();
      record.picker.append(node('h4', '', addIndex === null ? 'Your private playlists' : 'Add to a playlist'));
      for (const playlist of data.playlists) {
        if (!idPattern.test(playlist.id || '') || playlist.visibility !== 'private' || !Number.isSafeInteger(playlist.revision)) continue;
        const row = node('div', 'vp3-music-saved-row');
        row.append(node('span', '', text(playlist.title, 200) + ' · ' + Number(playlist.item_count || 0) + ' songs'));
        row.append(button(addIndex === null ? 'Open' : 'Add', async () => {
          if (!current(record)) return;
          try {
            const action = addIndex === null ? 'playlist' : 'playlist_add';
            const fields = {playlist_id:playlist.id, ...(addIndex === null ? {} : {
              revision:playlist.revision, result_id:record.payload.result_id, index:addIndex
            })};
            const response = await api(record.payload, action, fields);
            if (!current(record) || request !== record.pickerRevision) return;
            const updated = response.music_chat_v1;
            if (!validPayload(updated)) throw new Error('The playlist could not be confirmed.');
            if (active?.record === record) cleanupActive();
            record.payload = updated;
            record.editing = false;
            render(record);
            status(record, addIndex === null ? 'Saved playlist opened.' : 'Song added to the playlist.', 'saved');
          } catch (error) { status(record, error.message); }
        }));
        record.picker.append(row);
      }
      if (!data.playlists.length) record.picker.append(node('p', '', 'Create a playlist in Chat to start saving songs.'));
      const pages = node('div', 'vp3-music-actions');
      if (offset > 0) pages.append(button('Previous playlists', () => void showSaved(record, addIndex, Math.max(0, offset - 10))));
      if (offset + data.playlists.length < Number(data.total)) pages.append(button('More playlists', () => void showSaved(record, addIndex, offset + 10)));
      pages.append(button('Close playlists', () => { record.pickerRevision += 1; record.picker.hidden = true; record.picker.replaceChildren(); }));
      record.picker.append(pages);
    } catch (error) {
      if (current(record) && request === record.pickerRevision) record.picker.replaceChildren(node('p', '', error.message),
        button('Try again', () => void showSaved(record, addIndex, offset)));
    }
  }
  function render(record) {
    if (active?.record === record) cleanupActive();
    const payload = record.payload;
    record.card.replaceChildren();
    record.card.dataset.musicResultId = payload.result_id || '';
    record.card.dataset.musicPlaylistId = payload.playlist?.id || '';
    record.rows = [];
    const header = node('header', 'vp3-music-heading');
    header.append(node('h3', '', payload.playlist ? text(payload.playlist.title, 200) : payload.items.length === 1 ? 'Song' : 'Songs'));
    if (payload.playlist) header.append(node('span', 'vp3-music-private', 'Private playlist'));
    record.card.append(header);
    const actions = node('div', 'vp3-music-actions');
    if (payload.playlist) {
      const play = button('Play playlist', () => void playIndex(record, 0, true), 'vp3-music-primary');
      play.disabled = !payload.items.length;
      actions.append(play, button(record.editing ? 'Done editing' : 'Edit playlist', () => { record.editing = !record.editing; render(record); }));
    }
    actions.append(button('My playlists', () => void showSaved(record)), button('Refresh', () => void refresh(record)));
    record.card.append(actions);
    if (payload.playlist && record.editing) {
      const form = node('form', 'vp3-music-title-form');
      const label = node('label', '', 'Playlist name');
      const input = node('input');
      input.type = 'text'; input.maxLength = 160; input.required = true; input.value = text(payload.playlist.title, 160);
      label.append(input);
      const save = node('button', '', 'Save name'); save.type = 'submit';
      form.append(label, save);
      form.addEventListener('submit', event => {
        event.preventDefault();
        if (input.value.trim()) void mutate(record, 'playlist_update', {...selectionFields(payload), title:input.value.trim()});
      });
      record.card.append(form);
      const remove = node('div', 'vp3-music-delete');
      remove.append(button('Delete playlist', () => {
        remove.replaceChildren(node('span', '', 'Delete this private playlist?'),
          button('Delete playlist permanently', () => void removePlaylist(record)),
          button('Cancel deletion', () => render(record)));
      }));
      record.card.append(remove);
    }
    const list = node('ol', 'vp3-music-items');
    payload.items.forEach((item, index) => {
      const row = node('li', 'vp3-music-item');
      row.dataset.musicItemSource = item.source;
      row.dataset.musicItemId = item.source_id;
      const summary = node('div', 'vp3-music-item-summary');
      const imageUrl = artwork(item);
      if (imageUrl) {
        const image = node('img', 'vp3-music-artwork'); image.src = imageUrl; image.alt = ''; image.loading = 'lazy'; image.referrerPolicy = 'no-referrer';
        image.addEventListener('error', () => image.remove(), {once:true}); summary.append(image);
      }
      const details = node('div', 'vp3-music-details');
      details.append(node('strong', '', text(item.title)));
      if (item.artist) details.append(node('span', 'vp3-music-artist', text(item.artist, 160)));
      const meta = node('div', 'vp3-music-meta');
      meta.append(node('span', 'vp3-music-source', item.source === 'youtube' ? 'YouTube' : 'VP3'));
      const length = duration(item.duration);
      if (length) meta.append(node('span', '', length));
      if (!item.available) {
        const reason = {track_changed:'Song changed or is unavailable', track_unavailable:'Song unavailable', source_unavailable:'Source unavailable',
          consent_changed:'YouTube connection changed', youtube_unavailable:'YouTube selection unavailable',
          expired:'Selection expired', provider_unavailable:'YouTube selection unavailable',
          result_expired:'Selection expired', video_unavailable:'Video unavailable'}[item.reason];
        meta.append(node('span', '', reason || 'Unavailable'));
      }
      details.append(meta); summary.append(details); row.append(summary);
      const controls = node('div', 'vp3-music-item-actions');
      const play = button('Play', () => {
        const queuedIndex = !active && queueState?.entries.findIndex(entry => entry.record === record && entry.index === index && entry.payload === payload);
        if (Number.isInteger(queuedIndex) && queuedIndex >= 0) void playQueueEntry(queueState, queuedIndex);
        else void playIndex(record, index, false);
      }, 'vp3-music-primary');
      play.disabled = !item.available;
      play.setAttribute('aria-label', 'Play ' + text(item.title));
      const playNext = button('Play next', () => void enqueue(record, [index], true));
      const addQueue = button('Add to queue', () => void enqueue(record, [index]));
      playNext.disabled = addQueue.disabled = !item.available;
      controls.append(play, playNext, addQueue);
      if (!payload.playlist && payload.result_id) {
        const add = button('Add to playlist', () => void showSaved(record, index));
        add.disabled = !item.available; controls.append(add);
      }
      if (payload.playlist && record.editing) {
        const up = button('Move up', () => playlistOrder(record, index, index - 1)); up.disabled = index === 0;
        const down = button('Move down', () => playlistOrder(record, index, index + 1)); down.disabled = index === payload.items.length - 1;
        up.setAttribute('aria-label', 'Move up ' + text(item.title)); down.setAttribute('aria-label', 'Move down ' + text(item.title));
        const remove = button('Remove', () => playlistOrder(record, index, null)); remove.setAttribute('aria-label', 'Remove ' + text(item.title));
        controls.append(up, down, remove);
      }
      const player = node('div', 'vp3-music-player-slot');
      row.append(controls, player); list.append(row); record.rows.push({row, play, player});
    });
    if (!payload.items.length) record.card.append(node('p', '', payload.playlist ? 'This playlist is empty.' : 'No playable songs matched this request.'));
    record.card.append(list);
    if (Number.isSafeInteger(payload.requested_count) && payload.requested_count > payload.items.length) {
      record.card.append(node('p', 'vp3-music-coverage', 'Found ' + payload.items.length + ' of ' + payload.requested_count + ' requested songs.'));
    }
    const warnings = Array.isArray(payload.coverage?.warnings) ? payload.coverage.warnings : [];
    for (const warning of warnings.slice(0, 4)) if (typeof warning === 'string') record.card.append(node('p', 'vp3-music-coverage', text(warning, 300)));
    for (const source of Object.values(payload.coverage || {})) {
      if (source?.status === 'unavailable' && ['vp3','youtube'].includes(source.source)) {
        record.card.append(node('p', 'vp3-music-coverage', (source.source === 'youtube' ? 'YouTube' : 'VP3')
          + ' could not be checked for this request. You can refresh or try another music search.'));
      }
    }
    record.status = node('p', 'vp3-music-status'); record.status.setAttribute('role', 'status'); record.status.hidden = true;
    record.transport = makeTransport(record);
    record.picker = node('section', 'vp3-music-picker'); record.picker.hidden = true;
    record.pickerRevision += 1;
    record.card.append(record.transport, record.status, record.picker);
    updateTransport(record);
  }
  async function refresh(record) {
    if (!current(record) || record.refreshing) return;
    record.refreshing = true;
    status(record, 'Refreshing…');
    try {
      const fields = record.payload.playlist ? {playlist_id:record.payload.playlist.id} : {result_id:record.payload.result_id};
      const response = await api(record.payload, record.payload.playlist ? 'playlist' : 'result', fields);
      if (!current(record)) return;
      if (!validPayload(response.music_chat_v1)) throw new Error('This music selection is unavailable.');
      record.payload = response.music_chat_v1;
      render(record);
    } catch (error) {
      if (current(record)) {
        if ([401,403,404,410].includes(error.status)) {
          if (active?.record === record) cleanupActive();
          record.card.replaceChildren(node('p', '', error.message));
        } else status(record, error.message);
      }
    } finally { record.refreshing = false; }
  }
  function autoConsumed(id) {
    const key = 'vp3.music.played.' + config.userId + '.' + (config.agentId || 0);
    let stored = [];
    try { stored = JSON.parse(sessionStorage.getItem(key) || '[]'); } catch {}
    if (!Array.isArray(stored)) stored = [];
    if (consumed.has(id) || stored.includes(id)) return true;
    consumed.add(id);
    try { sessionStorage.setItem(key, JSON.stringify([...stored.slice(-99), id])); } catch {}
    return false;
  }
  async function attach(message, payload, {live = false} = {}) {
    if (!message?.isConnected || !payload || !currentScope(payload) || attached.has(message)) return null;
    if (!idPattern.test(payload.result_id || '') && !idPattern.test(payload.playlist?.id || '')) return null;
    const card = node('section', 'vp3-music-chat'); card.setAttribute('aria-label', 'Music selection');
    card.dataset.musicChat = '1';
    const record = {message, card, payload, rows:[], editing:false, busy:false, pickerRevision:0, refreshing:false};
    const host = message.querySelector('.message-body') || message;
    host.append(card); records.add(record); attached.set(message, record);
    card.append(node('p', '', 'Loading music…'));
    try {
      const automatic = live && payload.autoplay;
      if (!live || !validPayload(payload)) {
        const response = await api(payload, payload.playlist ? 'playlist' : 'result', payload.playlist
          ? {playlist_id:payload.playlist.id} : {result_id:payload.result_id});
        if (!current(record) || !validPayload(response.music_chat_v1)) throw new Error('This music selection is unavailable.');
        record.payload = response.music_chat_v1;
      }
      render(record);
      if (automatic && idPattern.test(automatic.action_id || '') && Number.isSafeInteger(automatic.index)
          && Number.isSafeInteger(automatic.expires_at) && automatic.expires_at * 1000 > Date.now()
          && !autoConsumed(automatic.action_id)) {
        if (['queue_append', 'play_next'].includes(record.payload.intent)) {
          const indices = record.payload.items.map((item, index) => item.available ? index : -1).filter(index => index >= 0);
          await enqueue(record, indices, record.payload.intent === 'play_next', automatic.action_id);
        } else await playIndex(record, automatic.index, Boolean(record.payload.playlist), automatic.action_id);
      }
    } catch (error) {
      if (current(record)) card.replaceChildren(node('p', '', error.message), button('Try again', () => {
        records.delete(record); attached.delete(message); card.remove(); void attach(message, payload, {live:false});
      }));
    }
    return record;
  }
  function clear() {
    cleanupActive();
    for (const record of records) { record.card.replaceChildren(); record.card.remove(); attached.delete(record.message); }
    records.clear(); retry = null;
  }
  for (const event of ['stonefellow:chat-context-changing', 'vp3:participant-selection-changed', 'pagehide']) window.addEventListener(event, clear);
  const requestId = (message, conversationId) => {
    const key = String(conversationId) + ':' + String(message).trim();
    if (retry?.key !== key) retry = {key, id:crypto.randomUUID()};
    return retry.id;
  };
  const completeRequest = id => { if (retry?.id === id) retry = null; };
  window.VP3_MUSIC_CHAT_V1 = Object.freeze({attach, claimSource, command, runCommand:control, requestId, completeRequest,
    isPlaying:() => active?.state === 'playing', stop:() => cleanupActive()});
})();
