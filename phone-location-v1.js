(() => {
  'use strict';
  const root = document.getElementById('phone-location');
  if (!root) return;

  const el = id => document.getElementById('phone-' + id);
  const prefix = 'vp3-phone:' + location.pathname + ':';
  // Only the device API receives the HttpOnly cookie. No GPS samples or device credentials go into web storage.
  function read(area, key) {
    try { return JSON.parse(window[area].getItem(prefix + key)); } catch { return null; }
  }
  function write(area, key, data) {
    try {
      const storage = window[area];
      if (data === null) storage.removeItem(prefix + key);
      else storage.setItem(prefix + key, JSON.stringify(data));
    } catch { /* Some private browsers disable web storage. In-memory controls still work. */ }
  }
  const now = () => Math.floor(Date.now() / 1000);
  const mobileActive = d => ['waiting', 'live', 'stale'].includes(d?.location_state);
  const stoppable = d => mobileActive(d) || d?.location_state === 'requested';
  const secure = window.isSecureContext;
  const secretPattern = /^[A-Za-z0-9_-]{43}$/;
  const hash = location.hash.slice(1);
  if (location.hash) history.replaceState(null, '', location.pathname + location.search);
  const invite = hash.startsWith('invite=') ? new URLSearchParams(hash).get('invite') : hash;
  let claim = read('sessionStorage', 'claim');
  if (claim && (!secretPattern.test(claim.invite) || claim.expires <= now())) claim = null;
  if (invite && secretPattern.test(invite) && (!claim || claim.invite !== invite)) {
    claim = {invite, expires:now() + 300};
  }
  write('sessionStorage', 'claim', claim);
  let device = null;
  let owner = '';
  let actionBusy = false;
  let run = null;
  function storedEnd() {
    const item = read('localStorage','pending');
    return item && ['stop','denied','disconnect'].includes(item.action) && typeof item.id === 'string' &&
      Number.isSafeInteger(item.generation) ? item : null;
  }
  let pending = storedEnd();
  let available = false;
  let initial = true;
  let info = '';
  let chain = Promise.resolve();
  let pollQueued = false;
  let dead = false;
  const queue = task => { chain = chain.then(task).catch(() => { info = 'Connection interrupted. Check again.'; render(); }); };
  const endpoint = new URL(root.dataset.endpoint, location.href);
  if (endpoint.origin !== location.origin) return;

  async function request(action, data = {}, keepalive = false) {
    const controller = new AbortController();
    const timer = setTimeout(() => controller.abort(), 10000);
    try {
      const response = await fetch(endpoint, {
        method:'POST', credentials:'same-origin', cache:'no-store', redirect:'error',
        referrerPolicy:'no-referrer', signal:controller.signal, keepalive,
        headers:{'Content-Type':'application/json','X-VP3-Phone':'1'},
        body:JSON.stringify({action,...data})
      });
      const result = await response.json().catch(() => ({}));
      if (!response.ok || result.ok !== true) {
        const error = new Error('Phone action unavailable'); error.status = response.status; throw error;
      }
      return result;
    } finally { clearTimeout(timer); }
  }
  function cancelWatch() {
    const previous = run;
    run = null;
    if (previous) {
      if (previous.watch !== null) navigator.geolocation.clearWatch(previous.watch);
      previous.next = null;
    }
    write('sessionStorage', 'watch', null);
    return previous;
  }
  function clearPending() {
    const stored = read('localStorage', 'pending');
    if (pending && stored?.id === pending.id && stored.generation === pending.generation && stored.action === pending.action) {
      write('localStorage', 'pending', null);
    }
    pending = null;
  }
  function endDevice(message) {
    cancelWatch();
    clearPending();
    device = null;
    owner = '';
    available = true;
    initial = false;
    info = message;
  }
  function accept(result) {
    const next = result.device;
    if (!next || typeof next.id !== 'string' || !Number.isSafeInteger(next.generation)) throw new Error('Invalid phone response');
    if (next.status === 'revoked' || next.status === 'denied') {
      endDevice('This connection has ended. Scan a new QR code to connect again.');
      return;
    }
    const awaitingStart = run && !run.confirmed && next.id === run.id && next.generation === run.baseGeneration &&
      next.status === 'approved' && next.allow_location && !mobileActive(next);
    if (run && !awaitingStart && (next.id !== run.id || next.generation !== run.generation ||
        next.status !== 'approved' || !next.allow_location || !mobileActive(next))) cancelWatch();
    device = next;
    owner = typeof result.owner_name === 'string' ? result.owner_name : '';
    initial = false;
    available = true;
  }
  function render() {
    const connected = device?.status === 'approved';
    const queued = Boolean(pending && pending.id === device?.id);
    const gps = Boolean(secure && navigator.geolocation);
    el('label').textContent = device?.name || 'Connect this phone';
    el('badge').textContent = device ? (connected ? 'Connected phone' : 'Pending approval') : 'Phone Tracking';
    el('details').hidden = !device;
    el('owner').textContent = owner || 'Checking account…';
    el('controls').hidden = !device && (initial || Boolean(claim));
    el('join').hidden = !secure || Boolean(device) || !claim || initial;
    if (claim?.name && el('name').value === '') el('name').value = claim.name;
    el('claim').disabled = actionBusy || navigator.onLine === false;
    el('start').hidden = !connected || !device.allow_location || !gps || !owner || !available || queued || !!run || mobileActive(device);
    el('start').disabled = actionBusy;
    el('stop').hidden = !connected || queued || !(run || stoppable(device));
    el('stop').textContent = device?.location_state === 'requested' && !run ? 'Decline sharing request' : 'Stop sharing';
    el('disconnect').hidden = !device || queued;
    el('retry').hidden = !secure || (!device && !claim && available) || (available && !queued);
    el('consent').textContent = connected && device.allow_location && owner
      ? 'Start sharing lets ' + owner + ' see this phone’s latest location, accuracy and update time. Keep this page open for updates.' : '';
    let message = 'Scan the QR code in VP3 Phone Tracking to connect this phone.';
    let gpsState = 'Off';
    if (!secure) message = 'Open this page over HTTPS to connect and share location.';
    else if (initial) message = 'Checking your connection…';
    else if (device?.status === 'pending') message = 'Waiting for the account owner to approve this phone.';
    else if (connected) {
      message = device.allow_location ? 'You choose when to share your location.' : 'The owner has disabled location access for this phone.';
      if (device.location_state === 'requested') message = 'The owner requested your location. Tap Start sharing to allow it.';
      if (device.location_state === 'denied') message = 'Location permission was denied. You can change browser permissions and try again.';
      if (mobileActive(device) && !run) message = 'This phone has an open sharing session. You can stop it here.';
      if (run && !run.confirmed) message = 'Requesting sharing permission…';
      else if (device.location_state === 'waiting') message = 'Waiting for a fresh location from this phone…';
      const age = typeof device.fix_at === 'number' ? now() - device.fix_at : Infinity;
      if (device.location_state === 'live' && age <= 60 && age >= -5 && available) {
        gpsState = 'Shared at ' + new Date(device.fix_at*1000).toLocaleTimeString();
        message = 'VP3 received this phone’s location' + (Number.isFinite(device.accuracy) ? ' (±' + Math.round(device.accuracy) + ' m).' : '.');
      } else if (device.location_state === 'stale' || (device.location_state === 'live' && age > 60)) {
        gpsState = 'Last known'; message = 'VP3 is waiting for a fresh location. Locked or suspended phones may pause updates.';
      } else if (mobileActive(device) || run) gpsState = 'Awaiting update';
      if (!gps) message = 'Location is unavailable in this browser. Open this page in a browser with location access.';
    } else if (claim) message = 'Choose a name so the owner can recognize this phone. Pairing leaves location off.';
    if (queued) {
      gpsState = 'Stopped on this page';
      message = pending.action === 'disconnect' ? 'Waiting to confirm disconnection with VP3.' :
        'Location collection stopped on this page. Waiting for VP3 to confirm.';
    } else if (!available && !initial && device) {
      gpsState = 'Unconfirmed'; message = 'Cannot reach VP3. Its current connection status is unknown.';
    }
    el('gps').textContent = gpsState;
    el('status').textContent = secure && info ? info : message;
  }
  function reportFailure(error) {
    if (error.status === 401 || error.status === 403 || error.status === 404) {
      endDevice('Connection expired or disconnected. Scan a new QR code to connect again.');
    } else if (error.status === 409) {
      info = 'The phone’s permissions changed. Checking the current state.';
      schedulePoll();
    } else {
      available = false;
      info = pending ? '' : 'Cannot reach VP3. Check your connection and try again.';
    }
  }
  // Persist only a terminal intent. No start request or GPS sample is ever queued for offline replay.
  function rememberEnd(action, id, generation) {
    if (!id || !Number.isSafeInteger(generation)) return;
    pending = {id,generation,action};
    write('localStorage', 'pending', pending);
  }
  async function sendEnd(keepalive = false) {
    const item = pending;
    if (!item || navigator.onLine === false || !device || item.id !== device.id) return;
    try {
      const result = await request(item.action,{device_id:item.id,generation:item.generation},keepalive);
      if (pending === item) clearPending();
      info = '';
      accept(result);
      if (item.action === 'disconnect') endDevice('Phone disconnected. Scan a new QR code to pair again.');
    } catch (error) {
      if (error.status === 409 && pending === item) { clearPending(); info = 'The sharing session changed. Checking its current state.'; schedulePoll(); }
      else reportFailure(error);
    }
    render();
  }
  function finish(action, reason = '') {
    if (!device) return;
    const previous = cancelWatch();
    rememberEnd(action,device.id,previous?.startSent ? previous.generation : device.generation);
    info = reason;
    render();
    queue(sendEnd);
  }
  async function poll() {
    if (!secure || dead) return;
    try {
      const result = await request('status');
      info = '';
      accept(result);
      // A reload does not grant new consent. Close this tab's orphaned watch at its exact generation.
      const interrupted = read('sessionStorage','watch');
      if (!run && !pending && interrupted) {
        write('sessionStorage','watch',null);
        if (interrupted.id === device?.id && interrupted.generation === device.generation && mobileActive(device)) {
          rememberEnd('stop',device.id,device.generation);
        }
      }
      if (pending && device) {
        if (pending.id !== device.id || pending.generation !== device.generation) clearPending();
        else await sendEnd();
      }
    } catch (error) {
      const hadDevice = Boolean(device);
      if (!hadDevice && [401,403,404].includes(error.status)) {
        endDevice(claim ? '' : (hash && !secretPattern.test(invite) ? 'Invalid invitation. Scan a new QR code.' : ''));
      } else reportFailure(error);
    } finally { initial = false; render(); }
  }
  function schedulePoll() {
    if (pollQueued || dead || !secure) return;
    pollQueued = true;
    queue(async () => { pollQueued = false; await poll(); });
  }

  async function claimPhone(event) {
    event.preventDefault();
    if (actionBusy || !secure || !claim || device) return;
    const name = el('name').value.trim();
    if (!name) { el('name').focus(); return; }
    actionBusy = true; info = 'Sending your connection request…'; render();
    if (!claim.key) claim.key = Array.from(crypto.getRandomValues(new Uint8Array(32)), b=>b.toString(16).padStart(2,'0')).join('');
    claim.name = name; write('sessionStorage','claim',claim);
    queue(async () => {
      try {
        const result = await request('claim',{invite:claim.invite,name,claim_key:claim.key});
        accept(result); claim = null; write('sessionStorage','claim',null); info = '';
      } catch (error) {
        if ([401,403,404,409,410].includes(error.status)) {
          claim = null; write('sessionStorage','claim',null);
          info = 'This invitation expired or has been used. Ask the owner for a new QR code.';
        } else info = 'Connection request could not be confirmed. Try again.';
      } finally { actionBusy = false; render(); }
    });
  }
  function submitFix(session, position) {
    if (run !== session || !session.confirmed || !available || dead || navigator.onLine === false) return;
    const time = Math.floor(position.timestamp / 1000);
    const {latitude,longitude,accuracy} = position.coords;
    if (!Number.isFinite(time) || time < now() - 55 || time > now() + 5 ||
        ![latitude,longitude,accuracy].every(Number.isFinite) || Math.abs(latitude)>90 || Math.abs(longitude)>180 || accuracy<0) return;
    if (session.sending || time <= session.lastTime || (session.lastTime > 0 && time - session.lastTime < 5)) return;
    session.sending = true;
    queue(async () => {
      try {
        if (run !== session || dead || navigator.onLine === false || !available || time < now()-55) return;
        const result = await request('fix',{device_id:session.id,generation:session.generation,latitude,longitude,accuracy,fix_at:time});
        if (run !== session) return;
        accept(result); session.lastTime = time; info = '';
      } catch (error) {
        if (run !== session) return;
        if ([401,403,404,409].includes(error.status)) cancelWatch();
        if (error.status === 422) info = 'Waiting for a fresh GPS reading.';
        else reportFailure(error);
      } finally { session.sending = false; render(); }
    });
  }
  function monitor(session) {
    if (session.watch !== null) navigator.geolocation.clearWatch(session.watch);
    session.refreshedAt = Date.now();
    try {
      session.watch = navigator.geolocation.watchPosition(position => {
        if (run !== session) return;
        if (session.confirmed) submitFix(session,position);
        else session.next = position;
      }, error => {
        if (run !== session) return;
        if (error.code === 1) finish('denied','Location permission denied. Sending the update to VP3.');
        else { info = 'GPS is temporarily unavailable. Waiting for a fresh position.'; render(); }
      }, {enableHighAccuracy:true,maximumAge:0,timeout:20000});
    } catch { finish('denied','Location is unavailable in this browser.'); }
  }
  function start() {
    if (dead || !available || !secure || !navigator.geolocation || !owner || actionBusy || run || pending ||
        device?.status !== 'approved' || !device.allow_location || mobileActive(device)) return;
    const initialGeneration = device.generation;
    const session = {id:device.id,baseGeneration:initialGeneration,startSent:false,generation:initialGeneration + (device.location_state === 'requested' ? 0 : 1),
      watch:null,confirmed:false,next:null,lastTime:0,sending:false};
    run = session; actionBusy = true; info = '';
    write('sessionStorage','watch',{id:session.id,generation:session.generation}); render();
    // Ask for browser permission directly in the holder's tap. Send no sample until the matching server grant is confirmed.
    queue(async () => {
      try {
        if (run !== session) return;
        session.startSent = true;
        const result = await request('start',{device_id:session.id,generation:initialGeneration});
        if (run !== session) return;
        session.generation = result.device.generation;
        accept(result);
        if (run !== session) return;
        session.confirmed = true;
        write('sessionStorage','watch',{id:session.id,generation:session.generation});
        if (session.next) {
          const pos = session.next; session.next = null;
          submitFix(session,pos);
          // The first sample may predate the server grant. Ask for a new uncached reading as well.
          monitor(session);
        }
      } catch (error) {
        if (run !== session) return;
        cancelWatch();
        if (!error.status || error.status >= 500) rememberEnd('stop',session.id,session.generation);
        reportFailure(error);
      } finally { actionBusy = false; render(); }
    });
    monitor(session);
  }
  el('join').addEventListener('submit',claimPhone);
  el('start').addEventListener('click',start);
  el('stop').addEventListener('click',() => finish('stop'));
  el('disconnect').addEventListener('click',() => finish('disconnect'));
  el('retry').addEventListener('click',schedulePoll);
  window.addEventListener('online',schedulePoll);
  window.addEventListener('offline',() => { available = false; info = ''; render(); });
  window.addEventListener('storage',event => {
    if (event.key !== prefix+'pending') return;
    pending = storedEnd();
    if (pending && pending.id === run?.id && pending.generation === run.generation) cancelWatch();
    schedulePoll();
  });
  window.addEventListener('pagehide',() => {
    dead = true;
    if (run) {
      const old = cancelWatch();
      rememberEnd('stop',old.id,old.generation);
      // Keep the pending marker until a confirmed reply or a later authoritative status.
      if (navigator.onLine !== false) void request('stop',{device_id:old.id,generation:old.generation},true).catch(()=>{});
    }
  });
  window.addEventListener('pageshow',() => { dead = false; schedulePoll(); });
  document.addEventListener('visibilitychange',() => { if (!document.hidden) schedulePoll(); });
  setInterval(() => {
    if (document.hidden || dead) return;
    if (device || !available) schedulePoll();
    // A stationary device may receive no watch events. A fresh sensor read is required to keep a marker live.
    if (run?.confirmed && available && navigator.onLine !== false &&
        Date.now() - Math.max(run.refreshedAt, run.lastTime * 1000) > 40000) monitor(run);
  }, 3000);
  render();
  schedulePoll();
})();
