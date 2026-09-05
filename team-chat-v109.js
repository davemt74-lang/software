(() => {
  'use strict';

  const cfg = window.STONEFELLOW_TEAM_CHAT;
  if (!cfg || !cfg.endpoint || !cfg.csrf || !Number(cfg.userId || 0)) return;

  const rail = document.getElementById('sfOnlineRailV109');
  const list = document.getElementById('sfOnlineUsersV109');
  const windows = document.getElementById('sfTeamChatWindowsV109');
  if (!rail || !list || !windows) return;

  const peers = new Map();
  const chats = new Map();
  const seen = new Set();
  let cursor = 0;
  let polling = false;
  let stopped = false;
  let soundEnabled = cfg.soundEnabled !== false;
  let socialChatEnabled = cfg.socialChatEnabled !== false;
  let notificationAudioContext = null;
  let notificationSoundReady = false;

  const AudioContextCtor = window.AudioContext || window.webkitAudioContext || null;

  const initials = name => String(name || '?')
    .trim()
    .split(/\s+/)
    .slice(0, 2)
    .map(part => part.charAt(0).toUpperCase())
    .join('') || '?';

  async function unlockNotificationSound() {
    if (!AudioContextCtor) return;
    try {
      notificationAudioContext ||= new AudioContextCtor();
      if (notificationAudioContext.state === 'suspended') {
        await notificationAudioContext.resume();
      }
      notificationSoundReady = notificationAudioContext.state === 'running';
    } catch (error) {
      notificationSoundReady = false;
    }
  }

  function playIncomingSound() {
    if (!soundEnabled || !notificationSoundReady || !notificationAudioContext) return;
    try {
      const context = notificationAudioContext;
      const now = context.currentTime;
      const oscillator = context.createOscillator();
      const gain = context.createGain();
      oscillator.type = 'sine';
      oscillator.frequency.setValueAtTime(880, now);
      oscillator.frequency.setValueAtTime(660, now + 0.09);
      gain.gain.setValueAtTime(0.0001, now);
      gain.gain.exponentialRampToValueAtTime(0.11, now + 0.012);
      gain.gain.exponentialRampToValueAtTime(0.0001, now + 0.19);
      oscillator.connect(gain);
      gain.connect(context.destination);
      oscillator.start(now);
      oscillator.stop(now + 0.2);
    } catch (error) {}
  }

  function applyRuntimeSettings(settings = {}) {
    if (Object.prototype.hasOwnProperty.call(settings, 'sound_enabled')) {
      soundEnabled = settings.sound_enabled !== false;
      cfg.soundEnabled = soundEnabled;
    }
    if (Object.prototype.hasOwnProperty.call(settings, 'social_chat_enabled')) {
      socialChatEnabled = settings.social_chat_enabled !== false;
      cfg.socialChatEnabled = socialChatEnabled;
      rail.hidden = !socialChatEnabled;
      windows.hidden = !socialChatEnabled;
      document.body.classList.toggle('sf-team-rail-active', socialChatEnabled);
    }
  }

  function avatar(user, compact = false) {
    const wrap = document.createElement('span');
    wrap.className = `sf-team-avatar-v109${compact ? ' compact' : ''}`;
    if (user.avatar) {
      const img = document.createElement('img');
      img.src = String(user.avatar);
      img.alt = '';
      img.loading = 'lazy';
      img.addEventListener('error', () => {
        img.remove();
        wrap.textContent = initials(user.name);
      }, { once:true });
      wrap.appendChild(img);
    } else {
      wrap.textContent = initials(user.name);
    }
    return wrap;
  }

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

  function setChatStatus(root, peer) {
    const status = root?.querySelector('[data-team-chat-status]');
    if (!status) return;
    status.textContent = peer.online
      ? (peer.context ? `Online · ${peer.context}` : 'Online')
      : 'Offline';
    status.classList.toggle('online', !!peer.online);
  }

  function renderDirectory(users) {
    list.textContent = '';
    const incoming = new Set();

    users.forEach(raw => {
      const user = {
        ...raw,
        id:Number(raw.id || 0),
        online:Boolean(raw.online),
        unread:Number(raw.unread || 0)
      };
      if (!user.id || user.id === Number(cfg.userId)) return;

      incoming.add(user.id);
      peers.set(user.id, user);

      const button = document.createElement('button');
      button.type = 'button';
      button.className = 'sf-online-user-v109';
      button.dataset.teamUser = String(user.id);
      button.dataset.online = user.online ? '1' : '0';
      button.title = `${user.name} · ${user.role_label || user.role}${user.online ? ' · Online' : ' · Offline'}${user.context ? ` · ${user.context}` : ''}`;
      button.appendChild(avatar(user));

      if (user.online) {
        const dot = document.createElement('span');
        dot.className = 'sf-online-dot-v109';
        dot.setAttribute('aria-hidden', 'true');
        button.appendChild(dot);
      }

      if (user.unread > 0) {
        const badge = document.createElement('b');
        badge.className = 'sf-online-unread-v109';
        badge.textContent = user.unread > 99 ? '99+' : String(user.unread);
        button.appendChild(badge);
      }

      button.addEventListener('click', () => void openChat(user));
      list.appendChild(button);
      setChatStatus(chats.get(user.id)?.root, user);
    });

    for (const [id, peer] of peers.entries()) {
      if (!incoming.has(id)) peers.delete(id);
      else setChatStatus(chats.get(id)?.root, peer);
    }
  }

  function formatTime(value) {
    const date = new Date(String(value || '').replace(' ', 'T'));
    if (Number.isNaN(date.getTime())) return '';
    return date.toLocaleTimeString([], { hour:'numeric', minute:'2-digit' });
  }

  function appendMessage(chat, message) {
    if (!message?.id || seen.has(Number(message.id))) return;
    const row = document.createElement('div');
    const mine = Number(message.sender_id) === Number(cfg.userId);
    row.className = `sf-team-message-v109 ${mine ? 'mine' : 'theirs'}`;
    row.dataset.teamMessageId = String(message.id);

    const bubble = document.createElement('div');
    bubble.textContent = String(message.text || '');
    const meta = document.createElement('small');
    meta.textContent = formatTime(message.created_at);
    row.append(bubble, meta);
    chat.body.appendChild(row);
    seen.add(Number(message.id));
    chat.body.scrollTop = chat.body.scrollHeight;
  }

  function buildChat(peer) {
    const root = document.createElement('section');
    root.className = 'sf-team-chat-v109';
    root.dataset.teamChatUser = String(peer.id);

    const head = document.createElement('header');
    head.className = 'sf-team-chat-head-v109';
    head.appendChild(avatar(peer, true));

    const title = document.createElement('div');
    const name = document.createElement('strong');
    name.textContent = peer.name;
    const state = document.createElement('small');
    state.dataset.teamChatStatus = '1';
    title.append(name, state);
    head.appendChild(title);

    const actions = document.createElement('div');
    actions.className = 'sf-team-chat-head-actions-v109';
    const minimize = document.createElement('button');
    minimize.type = 'button';
    minimize.textContent = '−';
    minimize.title = 'Minimize chat';
    const close = document.createElement('button');
    close.type = 'button';
    close.textContent = '×';
    close.title = 'Close chat';
    actions.append(minimize, close);
    head.appendChild(actions);

    const body = document.createElement('div');
    body.className = 'sf-team-chat-body-v109';
    const loading = document.createElement('div');
    loading.className = 'sf-team-chat-empty-v109';
    loading.textContent = 'Loading conversation…';
    body.appendChild(loading);

    const form = document.createElement('form');
    form.className = 'sf-team-chat-form-v109';
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

    root.append(head, body, form);
    windows.appendChild(root);

    const chat = { root, body, form, input, send, peer, minimized:false };
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
    input.addEventListener('keydown', event => {
      if (event.key === 'Enter' && !event.shiftKey) {
        event.preventDefault();
        form.requestSubmit();
      }
    });
    form.addEventListener('submit', async event => {
      event.preventDefault();
      const text = input.value.trim();
      if (!text || send.disabled || !socialChatEnabled) return;
      send.disabled = true;
      const payload = new FormData();
      payload.set('action', 'send');
      payload.set('csrf_token', cfg.csrf);
      payload.set('user_id', String(peer.id));
      payload.set('message', text);
      payload.set('page', cfg.pageKey || 'workspace');
      payload.set('context', cfg.contextLabel || '');
      try {
        const data = await request(cfg.endpoint, { method:'POST', body:payload });
        input.value = '';
        appendMessage(chat, data.message);
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
    try { await request(cfg.endpoint, { method:'POST', body:payload }); } catch (error) {}
  }

  async function loadHistory(chat) {
    try {
      const data = await request(endpoint('history', { user_id:chat.peer.id }));
      chat.body.textContent = '';
      chat.peer = { ...chat.peer, ...data.user };
      peers.set(chat.peer.id, chat.peer);
      setChatStatus(chat.root, chat.peer);
      data.messages.forEach(message => appendMessage(chat, message));
      if (!data.messages.length) {
        const empty = document.createElement('div');
        empty.className = 'sf-team-chat-empty-v109';
        empty.textContent = `Start a conversation with ${chat.peer.name}.`;
        chat.body.appendChild(empty);
      }
      await markRead(chat.peer.id);
    } catch (error) {
      chat.body.textContent = '';
      const failed = document.createElement('div');
      failed.className = 'sf-team-chat-empty-v109';
      failed.textContent = 'Conversation unavailable.';
      chat.body.appendChild(failed);
    }
  }

  async function openChat(peer) {
    let chat = chats.get(Number(peer.id));
    if (!chat) {
      chat = buildChat(peer);
      await loadHistory(chat);
    } else if (chat.minimized) {
      chat.minimized = false;
      chat.root.classList.remove('minimized');
    }
    chat.input.focus();
    await markRead(Number(peer.id));
    return chat;
  }

  function peerFromMessage(message) {
    const incoming = Number(message.sender_id) !== Number(cfg.userId);
    const source = incoming ? message.sender : message.recipient;
    return peers.get(Number(source?.id || 0)) || {
      id:Number(source?.id || 0),
      name:String(source?.name || 'Stonefellow User'),
      role:String(source?.role || ''),
      role_label:String(source?.role_label || source?.role || ''),
      avatar:String(source?.avatar || ''),
      online:false,
      unread:0,
      page:'',
      context:''
    };
  }

  async function handleMessages(messages) {
    let notifyIncoming = false;
    for (const message of messages) {
      const messageId = Number(message.id || 0);
      cursor = Math.max(cursor, messageId);
      const peer = peerFromMessage(message);
      if (!peer.id) continue;
      const incoming = Number(message.sender_id) !== Number(cfg.userId);
      const shouldNotify = incoming && messageId > 0 && !seen.has(messageId);
      if (shouldNotify) notifyIncoming = true;
      let chat = chats.get(peer.id);
      if (incoming && !chat) chat = await openChat(peer);
      if (chat) appendMessage(chat, message);
      if (incoming) void markRead(peer.id);
    }
    if (notifyIncoming) playIncomingSound();
  }

  async function poll() {
    if (stopped || polling) return;
    polling = true;
    try {
      const data = await request(endpoint('poll', { since:cursor }));
      if (data.settings) applyRuntimeSettings(data.settings);
      renderDirectory(Array.isArray(data.users) ? data.users : []);
      if (Array.isArray(data.messages) && data.messages.length) await handleMessages(data.messages);
      cursor = Math.max(cursor, Number(data.cursor || 0));
      rail.classList.remove('offline');
    } catch (error) {
      rail.classList.add('offline');
      if ([401,403].includes(Number(error.status || 0))) {
        stopped = true;
        rail.hidden = true;
      }
    } finally {
      polling = false;
      if (!stopped) window.setTimeout(poll, Number(cfg.pollMs || 3000));
    }
  }

  document.addEventListener('pointerdown', unlockNotificationSound, { once:true, capture:true });
  document.addEventListener('keydown', unlockNotificationSound, { once:true, capture:true });
  document.addEventListener('visibilitychange', () => {
    if (!document.hidden && !stopped) void poll();
  });
  window.addEventListener('stonefellow:chat-settings-updated', event => {
    applyRuntimeSettings(event.detail || {});
  });

  applyRuntimeSettings({
    sound_enabled:cfg.soundEnabled !== false,
    social_chat_enabled:cfg.socialChatEnabled !== false
  });
  void poll();
})();