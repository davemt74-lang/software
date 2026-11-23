'use strict';

window.VP3PhoneAgentChat = function (request, apps) {
  const get = id => document.getElementById('phone-' + id);
  const dock = get('dock'), chat = get('chat'), transcript = get('chat-turns');
  const form = get('chat-form'), input = get('chat-input'), send = get('chat-send');
  const toggle = get('apps-toggle'), menu = get('apps'), notice = get('chat-status');
  const main = get('location');
  let deviceId = null, online = false, generation = 0, nextPoll = 0, polling = false;
  let turns = [], outgoing = null, sending = false, lastTranscript = '';
  let followReplies = false;

  function status(message) {
    if (notice.textContent !== message) notice.textContent = message;
    notice.hidden = !message;
  }
  function controls() {
    send.disabled = !deviceId || !online || sending || !!outgoing || !input.value.trim();
  }
  function closeApps(restoreFocus = false) {
    menu.hidden = true;
    toggle.setAttribute('aria-expanded','false');
    if (restoreFocus) toggle.focus();
  }
  toggle.addEventListener('click', () => {
    if (!menu.hidden) { closeApps(true); return; }
    menu.hidden = false;
    toggle.setAttribute('aria-expanded','true');
    get('app-camera').focus();
  });
  for (const [id,open] of [['app-camera',apps.camera],['app-save-place',apps.savePlace]]) {
    get(id).addEventListener('click', () => { closeApps(true); open(); });
  }
  document.addEventListener('pointerdown', event => {
    if (!menu.hidden && !menu.contains(event.target) && !toggle.contains(event.target)) closeApps();
  });
  document.addEventListener('keydown', event => {
    if (event.key === 'Escape' && !menu.hidden) { event.preventDefault(); closeApps(true); }
  });
  dock.addEventListener('focusout', event => {
    if (event.relatedTarget && !dock.contains(event.relatedTarget)) closeApps();
  });

  function bubble(author,text,mine) {
    const container = document.createElement('div');
    container.className = mine ? 'phone-bubble mine' : 'phone-bubble';
    const name = document.createElement('small'), body = document.createElement('p');
    name.textContent = author;
    body.dir = 'auto';
    body.textContent = text;
    container.append(name,body);
    return container;
  }
  function render() {
    const visible = turns.slice();
    if (outgoing && !visible.some(t => t.request_id === outgoing.request_id)) visible.push(outgoing);
    const signature = JSON.stringify([visible,online,sending]);
    if (signature === lastTranscript) return;
    lastTranscript = signature;
    const keepBottom = followReplies && main.scrollHeight - main.clientHeight - main.scrollTop < 96;
    const retained = new Set();
    const existing = new Map(Array.from(transcript.children, n => [n.dataset.request,n]));
    for (const turn of visible) {
      const id = turn.request_id;
      retained.add(id);
      const retryable = turn.state === 'failed' || turn.state === 'unconfirmed';
      const key = JSON.stringify([turn,retryable && (sending || !online || !!outgoing && outgoing.request_id !== id)]);
      let row = existing.get(id);
      if (!row) {
        row = document.createElement('div');
        row.className = 'phone-turn';
        row.dataset.request = id;
        transcript.append(row);
      }
      if (row.dataset.render === key) continue;
      row.dataset.render = key;
      const user = bubble('You',turn.message,true);
      const text = turn.state === 'replied' ? turn.reply :
        turn.state === 'failed' ? 'The agent could not reply.' :
        turn.state === 'unconfirmed' ? 'Delivery could not be confirmed.' : 'Waiting for a reply…';
      const reply = bubble(turn.state === 'replied' ? 'Agent' : 'Message status',text,false);
      if (retryable) {
        const retry = document.createElement('button');
        retry.type = 'button';
        retry.className = 'phone-chat-retry';
        retry.textContent = turn.state === 'failed' ? 'Retry reply' : 'Retry delivery';
        retry.disabled = sending || !online || (!!outgoing && outgoing.request_id !== id);
        retry.addEventListener('click', () => sendTurn(turn));
        reply.append(retry);
      }
      row.replaceChildren(user,reply);
    }
    for (const [id,row] of existing) if (!retained.has(id)) row.remove();
    chat.hidden = !visible.length;
    if (keepBottom) main.scrollTop = main.scrollHeight;
  }
  function accept(result) {
    if (!Array.isArray(result.turns)) throw new Error('Missing chat response');
    turns = result.turns;
    if (outgoing && turns.some(t => t.request_id === outgoing.request_id)) outgoing = null;
    controls();
    render();
  }
  async function refresh() {
    if (!deviceId || !online || document.hidden || polling || sending || Date.now() < nextPoll) return;
    const version = generation;
    polling = true;
    nextPoll = Date.now() + (outgoing || turns.some(t => t.state === 'pending') ? 3000 : 12000);
    try {
      const result = await request('chat_status',{device_id:deviceId});
      if (generation !== version) return;
      accept(result);
      if (online) status('');
    } catch {
      if (generation === version && online) status('Could not refresh Agent Chat.');
    } finally {
      if (generation === version) polling = false;
    }
  }
  async function sendTurn(turn) {
    if (!deviceId || !online || sending || (outgoing && outgoing.request_id !== turn.request_id)) return;
    const version = ++generation;
    polling = false;
    sending = true;
    outgoing = {request_id:turn.request_id,message:turn.message,state:'pending',reply:null};
    turns = turns.filter(t => t.request_id !== turn.request_id);
    status('');
    controls();
    render();
    followReplies = true;
    main.scrollTop = main.scrollHeight;
    try {
      const result = await request('chat_send',{device_id:deviceId,request_id:turn.request_id,message:turn.message});
      if (generation !== version) return;
      accept(result);
      if (outgoing) outgoing.state = 'unconfirmed';
    } catch {
      if (generation !== version) return;
      outgoing.state = 'unconfirmed';
    } finally {
      if (generation === version) {
        sending = false;
        nextPoll = 0;
        controls();
        render();
        if (outgoing) void refresh();
      }
    }
  }
  input.addEventListener('input', controls);
  form.addEventListener('submit', event => {
    event.preventDefault();
    const message = input.value.trim();
    if (!message || send.disabled) return;
    if (new TextEncoder().encode(message).length > 3000) {
      status('Shorten your message and try again.');
      return;
    }
    // Use an opaque request ID to make a retry safe after an interrupted response.
    const bytes = crypto.getRandomValues(new Uint8Array(16));
    bytes[6] = (bytes[6] & 15) | 64;
    bytes[8] = (bytes[8] & 63) | 128;
    const hex = Array.from(bytes, value => value.toString(16).padStart(2,'0')).join('');
    const id = [hex.slice(0,8),hex.slice(8,12),hex.slice(12,16),hex.slice(16,20),hex.slice(20)].join('-');
    input.value = '';
    closeApps();
    void sendTurn({request_id:id,message});
  });
  function bind(id,reachable) {
    const changed = id !== deviceId;
    const reconnected = reachable && !online;
    if (changed) {
      generation++;
      deviceId = id;
      turns = [];
      outgoing = null;
      sending = polling = followReplies = false;
      input.value = '';
      closeApps();
      transcript.replaceChildren();
      chat.hidden = true;
      lastTranscript = '';
      status('');
    }
    online = reachable;
    dock.hidden = !id;
    if (!id) return;
    get('app-camera').disabled = !online;
    if (!online) status('Offline. Reconnect to send a message.');
    else if (reconnected) status('');
    if (changed || reconnected) nextPoll = 0;
    controls();
    render();
    void refresh();
  }
  // iOS can resize or pan the visual viewport independently of the layout viewport.
  const viewport = window.visualViewport;
  if (viewport) {
    const size = () => {
      if (viewport.scale !== 1) return;
      document.body.style.setProperty('--phone-viewport-height',viewport.height + 'px');
      document.body.style.setProperty('--phone-viewport-top',viewport.offsetTop + 'px');
    };
    viewport.addEventListener('resize',size);
    viewport.addEventListener('scroll',size);
    size();
  }
  window.addEventListener('pagehide', () => bind(null,false));
  return {bind};
};
