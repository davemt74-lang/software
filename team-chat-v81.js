(() => {
  'use strict';

  const cfg = window.STONEFELLOW_TEAM_CHAT;
  if (!cfg || !['manager', 'producer', 'supervisor'].includes(String(cfg.role || ''))) {
    return;
  }

  const rail = document.getElementById('sfOnlineRail');
  const onlineList = document.getElementById('sfOnlineUsers');
  const onlineCount = document.getElementById('sfOnlineCount');
  const windows = document.getElementById('sfTeamChatWindows');

  if (!rail || !onlineList || !windows) {
    return;
  }

  const peers = new Map();
  const chats = new Map();
  const seenMessageIds = new Set();
  let cursor = 0;
  let polling = false;
  let stopped = false;
  let audioContext = null;

  function escapeInitials(name) {
    return String(name || '?')
      .trim()
      .split(/\s+/)
      .slice(0, 2)
      .map(part => part.charAt(0).toUpperCase())
      .join('') || '?';
  }

  function makeAvatar(user, className = '') {
    const wrap = document.createElement('span');
    wrap.className = `sf-team-avatar ${className}`.trim();

    if (user.avatar) {
      const img = document.createElement('img');
      img.src = user.avatar;
      img.alt = '';
      img.loading = 'lazy';
      img.addEventListener('error', () => {
        img.remove();
        wrap.textContent = escapeInitials(user.name);
      }, { once:true });
      wrap.appendChild(img);
    } else {
      wrap.textContent = escapeInitials(user.name);
    }

    return wrap;
  }

  function unlockAudio() {
    try {
      if (!audioContext) {
        const AudioCtor = window.AudioContext || window.webkitAudioContext;
        if (!AudioCtor) return;
        audioContext = new AudioCtor();
      }
      if (audioContext.state === 'suspended') {
        audioContext.resume().catch(() => {});
      }
    } catch (error) {}
  }

  function tone(frequency, delay, duration, volume) {
    if (!audioContext || audioContext.state !== 'running') return;
    const start = audioContext.currentTime + delay;
    const osc = audioContext.createOscillator();
    const gain = audioContext.createGain();
    osc.type = 'sine';
    osc.frequency.setValueAtTime(frequency, start);
    gain.gain.setValueAtTime(0.0001, start);
    gain.gain.exponentialRampToValueAtTime(volume, start + 0.012);
    gain.gain.exponentialRampToValueAtTime(0.0001, start + duration);
    osc.connect(gain);
    gain.connect(audioContext.destination);
    osc.start(start);
    osc.stop(start + duration + 0.03);
  }

  function playSound(kind) {
    unlockAudio();
    if (!audioContext || audioContext.state !== 'running') return;
    if (kind === 'incoming') {
      tone(760, 0, 0.10, 0.055);
      tone(1020, 0.11, 0.13, 0.045);
    } else {
      tone(520, 0, 0.075, 0.035);
      tone(690, 0.07, 0.08, 0.028);
    }
  }

  document.addEventListener('pointerdown', unlockAudio, { passive:true });
  document.addEventListener('keydown', unlockAudio, { passive:true });

  async function request(url, options = {}) {
    const response = await fetch(url, {
      credentials:'same-origin',
      cache:'no-store',
      ...options
    });
    const data = await response.json().catch(() => ({ ok:false, error:'invalid_response' }));
    if (!response.ok || !data.ok) {
      const error = new Error(data.error || 'request_failed');
      error.status = response.status;
      throw error;
    }
    return data;
  }

  function endpoint(action, params = {}) {
    const url = new URL(cfg.endpoint, window.location.href);
    url.searchParams.set('action', action);
    url.searchParams.set('page', cfg.pageKey || 'workspace');
    if (cfg.contextLabel) url.searchParams.set('context', cfg.contextLabel);
    Object.entries(params).forEach(([key, value]) => url.searchParams.set(key, String(value)));
    return url.toString();
  }

  function formatTime(value) {
    const date = new Date(String(value || '').replace(' ', 'T'));
    if (Number.isNaN(date.getTime())) return '';
    return date.toLocaleTimeString([], { hour:'numeric', minute:'2-digit' });
  }

  function peerFromMessage(message) {
    const incoming = Number(message.sender_id) !== Number(cfg.userId);
    const source = incoming ? message.sender : message.recipient;
    return {
      id:Number(source.id || 0),
      name:String(source.name || 'Stonefellow User'),
      role:String(source.role || ''),
      role_label:String(source.role_label || source.role || ''),
      avatar:String(source.avatar || ''),
      online:peers.get(Number(source.id || 0))?.online || false,
      page:peers.get(Number(source.id || 0))?.page || '',
      context:peers.get(Number(source.id || 0))?.context || '',
      unread:0
    };
  }

  function setChatStatus(chat, peer) {
    if (!chat) return;
    const status = chat.querySelector('[data-team-chat-status]');
    if (!status) return;
    status.textContent = peer.online
      ? (peer.context ? `Online · ${peer.context}` : 'Online')
      : 'Offline';
    status.classList.toggle('online', !!peer.online);
  }

  function renderOnline(users) {
    const incomingIds = new Set();
    onlineList.textContent = '';

    users.forEach(user => {
      user.id = Number(user.id || 0);
      user.online = true;
      incomingIds.add(user.id);
      peers.set(user.id, user);

      const button = document.createElement('button');
      button.type = 'button';
      button.className = 'sf-online-user';
      button.dataset.teamUser = String(user.id);
      button.title = `${user.name} · ${user.role_label}${user.context ? ` · ${user.context}` : ''}`;
      button.appendChild(makeAvatar(user));

      const dot = document.createElement('span');
      dot.className = 'sf-online-dot';
      dot.setAttribute('aria-hidden', 'true');
      button.appendChild(dot);

      if (Number(user.unread || 0) > 0) {
        const badge = document.createElement('b');
        badge.className = 'sf-online-unread';
        badge.textContent = Number(user.unread) > 99 ? '99+' : String(Number(user.unread));
        button.appendChild(badge);
      }

      const label = document.createElement('span');
      label.className = 'sf-online-user-label';
      label.textContent = user.name;
      button.appendChild(label);

      button.addEventListener('click', () => openChat(user));
      onlineList.appendChild(button);
    });

    for (const [id, peer] of peers.entries()) {
      if (!incomingIds.has(id)) {
        peer.online = false;
      }
      setChatStatus(chats.get(id)?.root, peer);
    }

    if (onlineCount) onlineCount.textContent = String(users.length);

    if (!users.length) {
      const empty = document.createElement('div');
      empty.className = 'sf-online-empty';
      empty.textContent = 'No teammates online';
      onlineList.appendChild(empty);
    }
  }

  function appendMessage(chat, message) {
    if (!chat || !message || !message.id) return false;
    const messageId = Number(message.id);
    if (seenMessageIds.has(messageId)) return false;
    if (chat.body.querySelector(`[data-team-message-id="${messageId}"]`)) {
      seenMessageIds.add(messageId);
      return false;
    }

    const mine = Number(message.sender_id) === Number(cfg.userId);
    const item = document.createElement('div');
    item.className = `sf-team-message ${mine ? 'mine' : 'theirs'}`;
    item.dataset.teamMessageId = String(Number(message.id));

    const bubble = document.createElement('div');
    bubble.textContent = String(message.text || '');
    item.appendChild(bubble);

    const meta = document.createElement('small');
    meta.textContent = formatTime(message.created_at);
    item.appendChild(meta);

    chat.body.appendChild(item);
    seenMessageIds.add(messageId);
    chat.body.scrollTop = chat.body.scrollHeight;
    return true;
  }

  function buildChat(peer) {
    const root = document.createElement('section');
    root.className = 'sf-team-chat';
    root.dataset.teamChatUser = String(peer.id);

    const header = document.createElement('header');
    header.className = 'sf-team-chat-head';
    header.appendChild(makeAvatar(peer, 'compact'));

    const title = document.createElement('div');
    const strong = document.createElement('strong');
    strong.textContent = peer.name;
    const status = document.createElement('small');
    status.dataset.teamChatStatus = '1';
    title.append(strong, status);
    header.appendChild(title);

    const controls = document.createElement('div');
    controls.className = 'sf-team-chat-head-actions';
    const minimize = document.createElement('button');
    minimize.type = 'button';
    minimize.textContent = '−';
    minimize.title = 'Minimize chat';
    const close = document.createElement('button');
    close.type = 'button';
    close.textContent = '×';
    close.title = 'Close chat';
    controls.append(minimize, close);
    header.appendChild(controls);

    const body = document.createElement('div');
    body.className = 'sf-team-chat-body';
    const loading = document.createElement('div');
    loading.className = 'sf-team-chat-loading';
    loading.textContent = 'Loading conversation…';
    body.appendChild(loading);

    const form = document.createElement('form');
    form.className = 'sf-team-chat-form';
    const input = document.createElement('textarea');
    input.rows = 1;
    input.maxLength = 2000;
    input.placeholder = `Message ${peer.name}`;
    input.setAttribute('aria-label', `Message ${peer.name}`);
    const send = document.createElement('button');
    send.type = 'submit';
    send.textContent = '➤';
    send.title = 'Send message';
    form.append(input, send);

    root.append(header, body, form);
    windows.appendChild(root);

    const chat = { root, body, form, input, send, peer, loaded:false, minimized:false };
    chats.set(peer.id, chat);
    setChatStatus(root, peer);

    minimize.addEventListener('click', () => {
      chat.minimized = !chat.minimized;
      root.classList.toggle('minimized', chat.minimized);
      minimize.textContent = chat.minimized ? '□' : '−';
    });

    close.addEventListener('click', () => {
      chats.delete(peer.id);
      root.remove();
    });

    header.addEventListener('dblclick', event => {
      if (event.target.closest('button')) return;
      minimize.click();
    });

    input.addEventListener('keydown', event => {
      if (event.key === 'Enter' && !event.shiftKey) {
        event.preventDefault();
        form.requestSubmit();
      }
    });

    form.addEventListener('submit', async event => {
      event.preventDefault();
      const message = input.value.trim();
      if (!message || send.disabled) return;
      unlockAudio();
      send.disabled = true;

      const payload = new FormData();
      payload.set('action', 'send');
      payload.set('csrf_token', cfg.csrf);
      payload.set('user_id', String(peer.id));
      payload.set('message', message);
      payload.set('page', cfg.pageKey || 'workspace');
      payload.set('context', cfg.contextLabel || '');

      try {
        const data = await request(cfg.endpoint, { method:'POST', body:payload });
        input.value = '';
        appendMessage(chat, data.message);
        playSound('outgoing');
      } catch (error) {
        root.classList.add('error');
        window.setTimeout(() => root.classList.remove('error'), 900);
      } finally {
        send.disabled = false;
        input.focus();
      }
    });

    return chat;
  }

  async function markRead(peerId) {
    const payload = new FormData();
    payload.set('action', 'read');
    payload.set('csrf_token', cfg.csrf);
    payload.set('user_id', String(peerId));
    payload.set('page', cfg.pageKey || 'workspace');
    payload.set('context', cfg.contextLabel || '');
    try {
      await request(cfg.endpoint, { method:'POST', body:payload });
    } catch (error) {}
  }

  async function loadHistory(chat) {
    try {
      const data = await request(endpoint('history', { user_id:chat.peer.id }));
      chat.body.textContent = '';
      chat.peer = { ...chat.peer, ...data.user };
      peers.set(chat.peer.id, chat.peer);
      setChatStatus(chat.root, chat.peer);
      data.messages.forEach(message => {
        appendMessage(chat, message);
      });
      if (!data.messages.length) {
        const empty = document.createElement('div');
        empty.className = 'sf-team-chat-empty';
        empty.textContent = `Start a conversation with ${chat.peer.name}.`;
        chat.body.appendChild(empty);
      }
      chat.loaded = true;
      await markRead(chat.peer.id);
    } catch (error) {
      chat.body.textContent = '';
      const failed = document.createElement('div');
      failed.className = 'sf-team-chat-empty';
      failed.textContent = 'Conversation unavailable.';
      chat.body.appendChild(failed);
    }
  }

  async function openChat(peer) {
    if (!peer || !Number(peer.id)) return null;
    let chat = chats.get(Number(peer.id));
    if (!chat) {
      chat = buildChat(peer);
      await loadHistory(chat);
    } else if (chat.minimized) {
      chat.minimized = false;
      chat.root.classList.remove('minimized');
      chat.root.querySelector('.sf-team-chat-head-actions button')?.replaceChildren(document.createTextNode('−'));
    }
    chat.input.focus();
    await markRead(Number(peer.id));
    return chat;
  }

  async function handleMessages(messages) {
    let incoming = false;
    for (const message of messages) {
      cursor = Math.max(cursor, Number(message.id || 0));
      const peer = peerFromMessage(message);
      if (!peer.id) continue;
      const isIncoming = Number(message.sender_id) !== Number(cfg.userId);
      const isNew = !seenMessageIds.has(Number(message.id || 0));
      let chat = chats.get(peer.id);

      if (isIncoming) {
        if (isNew) incoming = true;
        if (!chat) {
          chat = await openChat(peer);
        }
      }

      if (chat) appendMessage(chat, message);
      if (isIncoming) markRead(peer.id);
    }
    if (incoming) playSound('incoming');
  }

  async function poll() {
    if (stopped || polling) return;
    polling = true;
    try {
      const data = await request(endpoint('poll', { since:cursor }));
      renderOnline(Array.isArray(data.online) ? data.online : []);
      if (Array.isArray(data.messages) && data.messages.length) {
        await handleMessages(data.messages);
      }
      cursor = Math.max(cursor, Number(data.cursor || 0));
      rail.classList.remove('offline');
    } catch (error) {
      rail.classList.add('offline');
      if (Number(error.status || 0) === 401 || Number(error.status || 0) === 403) {
        stopped = true;
        rail.hidden = true;
      }
    } finally {
      polling = false;
      if (!stopped) {
        window.setTimeout(poll, Number(cfg.pollMs || 3000));
      }
    }
  }

  document.addEventListener('visibilitychange', () => {
    if (!document.hidden && !stopped) poll();
  });

  poll();
})();
