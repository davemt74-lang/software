'use strict';

window.VP3PhoneAgentChat = function (request, apps) {
  const get = id => document.getElementById('phone-' + id);
  const dock = get('dock'), chat = get('chat'), transcript = get('chat-turns');
  const form = get('chat-form'), input = get('chat-input'), send = get('chat-send');
  const toggle = get('apps-toggle'), menu = get('apps'), notice = get('chat-status');
  const main = get('location');
  const history = get('chat-history'), empty = get('chat-empty'), tabs = get('tabs');
  const connection = get('connection'), info = get('info'), connectionTab = get('tab-connection'), chatTab = get('tab-chat'), infoTab = get('tab-info');
  const tabItems = [['connection',connectionTab,connection],['chat',chatTab,chat],['info',infoTab,info]];
  let selectedTab = 'connection';
  let deviceId = null, online = false, generation = 0, nextPoll = 0, polling = false;
  let turns = [], outgoing = null, sending = false, lastTranscript = '';
  let followReplies = true;
  let notificationRequest = null, focusNotification = false;

  function sizeViewport() {
    const viewport = window.visualViewport;
    if (deviceId && viewport?.scale === 1 && viewport.height > 0) {
      main.style.setProperty('--phone-viewport-height',viewport.height + 'px');
    } else {
      main.style.removeProperty('--phone-viewport-height');
    }
  }
  function selectTab(view) {
    if(view==='chat'&&!deviceId)view='connection';
    if(view!=='chat'){notificationRequest=null;focusNotification=false;}
    selectedTab=view;
    for(const [key,tab,panel] of tabItems){
      const active=key===view;panel.hidden=!active;
      tab.setAttribute('aria-selected',String(active));tab.tabIndex=active?0:-1;
    }
    if(deviceId)main.dataset.tab=view;
    else delete main.dataset.tab;
    form.hidden=view==='info';
    get('dock-help').hidden=view==='info';
    closeApps();
    sizeViewport();
    if(view==='chat'){
      if(followReplies)history.scrollTop=history.scrollHeight;
      nextPoll=0;void refresh();
    }else if(view==='connection')connection.scrollTop=0;
    else info.scrollTop=0;
  }
  for(const [key,tab] of tabItems)tab.addEventListener('click',()=>selectTab(key));
  function openGuide(){
    selectTab('info');
    get('info-title').focus({preventScroll:true});
  }
  for(const id of ['setup-guide','footer-guide'])get(id).addEventListener('click',openGuide);
  get('info-back').addEventListener('click',()=>{
    selectTab('connection');(deviceId?connectionTab:get('setup-guide')).focus({preventScroll:true});
  });
  tabs.addEventListener('keydown',event=>{
    if(!['ArrowLeft','ArrowRight','Home','End'].includes(event.key))return;
    event.preventDefault();
    let index=tabItems.findIndex(([,tab])=>tab===event.target);
    if(index<0)index=tabItems.findIndex(([key])=>key===selectedTab);
    const next=event.key==='Home'?0:event.key==='End'?tabItems.length-1:(index+(event.key==='ArrowRight'?1:-1)+tabItems.length)%tabItems.length;
    selectTab(tabItems[next][0]);tabItems[next][1].focus();
  });
  history.addEventListener('scroll', () => {
    if (!chat.hidden) followReplies = history.scrollHeight - history.clientHeight - history.scrollTop < 96;
  });
  window.visualViewport?.addEventListener('resize',sizeViewport);

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
    const keepBottom = !chat.hidden && followReplies;
    const retained = new Set();
    const existing = new Map(Array.from(transcript.children, n => [n.dataset.request,n]));
    let previous = null;
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
      }
      const next = previous ? previous.nextElementSibling : transcript.firstElementChild;
      if (next !== row) transcript.insertBefore(row,next);
      previous = row;
      if (row.dataset.render === key) continue;
      row.dataset.render = key;
      const user = bubble('You',turn.message,true);
      const replied = turn.state === 'replied';
      const text = replied ? turn.reply :
        turn.state === 'awaiting_owner' ? 'Sent. Waiting for the owner to reply.' :
        turn.state === 'failed' ? (turn.failure_message || 'The agent could not reply.') :
        turn.state === 'unconfirmed' ? 'Delivery could not be confirmed.' :
        outgoing?.request_id === id ? 'Sending…' : 'Waiting for a reply…';
      const reply = bubble(replied ? (turn.reply_author === 'owner' ? 'Owner' : 'Agent') : 'Message status',text,false);
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
      if (replied && turn.reply_author !== 'owner' && turn.owner_reply) {
        row.append(bubble('Owner',turn.owner_reply,false));
      }
    }
    for (const [id,row] of existing) if (!retained.has(id)) row.remove();
    empty.hidden = visible.length > 0;
    if (keepBottom) history.scrollTop = history.scrollHeight;
    if(focusNotification&&notificationRequest&&!chat.hidden){
      const target=Array.from(transcript.children).find(row=>row.dataset.request===notificationRequest);
      if(target){target.tabIndex=-1;target.focus({preventScroll:true});target.scrollIntoView({block:'center'});focusNotification=false;followReplies=false;}
    }
  }
  function accept(result) {
    if (!Array.isArray(result.turns)) throw new Error('Missing chat response');
    turns = result.turns;
    if (outgoing && turns.some(t => t.request_id === outgoing.request_id)) outgoing = null;
    controls();
    render();
  }
  async function refresh() {
    if (!deviceId || !online || chat.hidden || document.hidden || polling || sending || Date.now() < nextPoll) return;
    const version = generation;
    polling = true;
    nextPoll = Date.now() + (outgoing || turns.some(t => t.state === 'pending' || t.state === 'awaiting_owner') ? 3000 : 12000);
    try {
      const result = await request('chat_status',{device_id:deviceId,...(notificationRequest?{focus_request_id:notificationRequest}:{})});
      if (generation !== version) return;
      accept(result);
      if (online) status('');
    } catch {
      if (generation === version && online) status('Could not refresh Chat.');
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
    history.scrollTop = history.scrollHeight;
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
    void sendTurn({request_id:id,message});
    if (chat.hidden) selectTab('chat');
    else closeApps();
  });
  function bind(id,reachable) {
    const changed = id !== deviceId;
    const reconnected = reachable && !online;
    if (changed) {
      generation++;
      deviceId = id;
      notificationRequest=null;focusNotification=false;
      turns = [];
      outgoing = null;
      sending = polling = false;
      followReplies = true;
      input.value = '';
      transcript.replaceChildren();
      empty.hidden = false;
      tabs.hidden = !id;
      dock.hidden = !id;
      if (id) connection.setAttribute('role','tabpanel');
      else connection.removeAttribute('role');
      connection.setAttribute('aria-labelledby',id ? connectionTab.id : 'phone-label');
      info.setAttribute('role',id?'tabpanel':'region');info.setAttribute('aria-labelledby',id?infoTab.id:'phone-info-title');
      get('setup-help').hidden=Boolean(id);
      selectTab('connection');
      lastTranscript = '';
      status('');
    }
    online = reachable;
    if (!id) return;
    get('app-camera').disabled = !online;
    if (!online) status('Offline. Reconnect to send a message.');
    else if (reconnected) status('');
    if (changed || reconnected) nextPoll = 0;
    controls();
    render();
    void refresh();
  }
  window.addEventListener('pagehide', () => bind(null,false));
  function openNotification(requestId){
    if(!deviceId||!online||typeof requestId!=='string'||!/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/.test(requestId))return;
    notificationRequest=requestId;focusNotification=true;lastTranscript='';followReplies=false;
    selectTab('chat');render();
  }
  return {bind,openInfo:openGuide,openConnection:()=>selectTab('connection'),openNotification};
};
