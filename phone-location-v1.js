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
  const updateInterval = d => [5,15,30].includes(d?.update_interval_seconds) ? d.update_interval_seconds : 5;
  const highAccuracy = d => d?.accuracy_mode !== 'balanced';
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
  const scopes = ['history','places'];
  function storedWithdrawal(scope) {
    const value = read('localStorage',scope + '-withdrawal');
    if (!value || typeof value.id !== 'string' || !Number.isSafeInteger(value.generation)) return null;
    if (scope === 'places') return value.action === 'set_place_permission' && Number.isSafeInteger(value.place_permission_version) ? value : null;
    return ['stop_history','decline_history'].includes(value.action) && Number.isSafeInteger(value.history_version)
      ? value : null;
  }
  const withdrawals = {history:storedWithdrawal('history'), places:storedWithdrawal('places')};
  function matchesWithdrawal(scope, intent, d) {
    if (!intent || !d || intent.id !== d.id) return false;
    return scope === 'history' ? intent.history_version === d.history_version
      : intent.generation === d.generation && intent.place_permission_version === d.place_permission_version;
  }
  function withdrawing(scope) {
    return matchesWithdrawal(scope,withdrawals[scope],device)
      || matchesWithdrawal(scope,storedWithdrawal(scope),device);
  }
  function clearWithdrawal(scope) {
    const stored = storedWithdrawal(scope), intent = withdrawals[scope];
    if (intent && stored?.id === intent.id && stored.generation === intent.generation
      && stored.history_version === intent.history_version && stored.place_permission_version === intent.place_permission_version
      && stored.action === intent.action) {
      write('localStorage',scope + '-withdrawal',null);
    }
    withdrawals[scope] = storedWithdrawal(scope);
  }
  const arrivalKey = 'arrival-withdrawal:';
  const ruleIdPattern = /^[a-f0-9]{32}$/;
  const arrivalWithdrawals = new Map();
  function readArrivalWithdrawal(ruleId) {
    const item = read('localStorage',arrivalKey + ruleId);
    return item && item.rule_id === ruleId && typeof item.device_id === 'string'
      && Number.isSafeInteger(item.generation) && Number.isSafeInteger(item.rule_version)
      && ['decline_arrival','stop_arrival'].includes(item.action) ? item : null;
  }
  // Each rule has its own key so simultaneous withdrawals in different tabs remain independent.
  try {
    for (const key of Object.keys(localStorage)) {
      if (!key.startsWith(prefix + arrivalKey)) continue;
      const ruleId = key.slice((prefix + arrivalKey).length);
      const intent = ruleIdPattern.test(ruleId) && readArrivalWithdrawal(ruleId);
      if (intent) arrivalWithdrawals.set(ruleId,intent);
    }
  } catch { /* Offline withdrawals still work in memory when storage is disabled. */ }
  function arrivalRules() {
    return Array.isArray(device?.arrival_rules) ? device.arrival_rules.filter(rule =>
      rule && ruleIdPattern.test(rule.id) && rule.generation === device.generation && Number.isSafeInteger(rule.version)
      && typeof rule.place_name === 'string' && ['active','requested'].includes(rule.state)) : [];
  }
  function sameArrivalIntent(item,rule) {
    return !!item && item.device_id === device?.id && item.generation === device.generation
      && item.rule_id === rule.id && (item.rule_version === rule.version
        || (item.action === 'decline_arrival' && rule.state === 'active' && rule.version === item.rule_version + 1));
  }
  function endingArrival(rule) {
    return sameArrivalIntent(arrivalWithdrawals.get(rule.id),rule) || sameArrivalIntent(readArrivalWithdrawal(rule.id),rule);
  }
  function clearArrivalIntent(item) {
    const stored = readArrivalWithdrawal(item.rule_id);
    if (stored?.device_id === item.device_id && stored.generation === item.generation
      && stored.rule_version === item.rule_version && stored.action === item.action) {
      write('localStorage',arrivalKey + item.rule_id,null);
    }
    if (arrivalWithdrawals.get(item.rule_id) === item) arrivalWithdrawals.delete(item.rule_id);
  }
  function arrivalGrants() {
    return Object.fromEntries(arrivalRules().filter(rule => rule.state === 'active' && !endingArrival(rule))
      .map(rule => [rule.id,rule.version]));
  }
  let available = false;
  let initial = true;
  let info = '';
  // Only this open page can describe the browser result of its own attempt.
  let gpsNotice = null;
  const policyMessage = 'This page’s permissions policy blocks location. Ask the site owner to allow geolocation on the phone connection page.';
  function policyBlocksLocation() {
    try {
      const policy = document.permissionsPolicy || document.featurePolicy;
      return policy?.allowsFeature?.('geolocation') === false;
    } catch { return false; }
  }
  function noteGps(message) {
    gpsNotice = {id:device.id,generation:device.generation,message};
  }
  let chain = Promise.resolve();
  let pollQueued = false;
  let dead = false;
  const queue = task => { chain = chain.then(task).catch(() => { info = 'Connection interrupted. Check again.'; render(); }); };
  const endpoint = new URL(root.dataset.endpoint, location.href);
  if (endpoint.origin !== location.origin) return;

  async function request(action, data = {}, keepalive = false) {
    const controller = new AbortController();
    const timer = setTimeout(() => controller.abort(), action === 'chat_send' ? 90000 : 10000);
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
  const camera = window.VP3PhoneCameraHolder?.(request);
  const savedPlace = window.VP3PhoneSavedPlace?.(request);
  const chat = window.VP3PhoneAgentChat?.(request, {
    camera: () => camera?.open(),
    savePlace: () => savedPlace?.open()
  });
  function discardDeferred(session) {
    clearTimeout(session.timer);
    session.timer = null;
    session.next = null;
  }
  function clearSensorWatch(id) {
    if (id === null) return;
    try { navigator.geolocation.clearWatch(id); } catch { /* Cancelled readers also ignore late callbacks. */ }
  }
  function cancelWatch() {
    const previous = run;
    run = null;
    if (previous) {
      clearSensorWatch(previous.watch);
      discardDeferred(previous);
    }
    write('sessionStorage', 'watch', null);
    return previous;
  }
  function clearPending() {
    const stored = read('localStorage', 'pending');
    if (pending && stored?.id === pending.id && stored.generation === pending.generation && stored.action === pending.action) {
      write('localStorage', 'pending', null);
    }
    if (gpsNotice?.end === pending) gpsNotice = null;
    pending = null;
  }
  function endDevice(message) {
    cancelWatch();
    clearPending();
    for (const scope of scopes) clearWithdrawal(scope);
    for (const item of arrivalWithdrawals.values()) clearArrivalIntent(item);
    device = null;
    gpsNotice = null;
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
    if (gpsNotice && !gpsNotice.end && (gpsNotice.id !== next.id || gpsNotice.generation !== next.generation)) gpsNotice = null;
    device = next;
    owner = typeof result.owner_name === 'string' ? result.owner_name : '';
    initial = false;
    available = true;
    if (run && (run.interval !== updateInterval(next) || run.highAccuracy !== highAccuracy(next))) {
      const replaceWatch = run.highAccuracy !== highAccuracy(next);
      run.interval = updateInterval(next);
      run.highAccuracy = highAccuracy(next);
      clearTimeout(run.timer);
      run.timer = null;
      if (replaceWatch) {
        run.next = null;
        monitor(run);
      }
      if (run) sendLatestFix(run);
    }
  }
  function render() {
    const connected = device?.status === 'approved';
    const queued = Boolean(pending && pending.id === device?.id);
    const paired = connected && pending?.action !== 'disconnect' ? device : null;
    camera?.bind(paired?.id || null, owner);
    savedPlace?.bind(paired, owner, available && !queued && navigator.onLine !== false);
    chat?.bind(paired?.id || null, available && navigator.onLine !== false);
    const gps = Boolean(secure && navigator.geolocation);
    el('label').textContent = device?.name || 'Connect this phone';
    el('badge').textContent = device ? (connected ? 'Connected phone' : 'Pending approval') : 'Phone Tracking';
    el('details').hidden = !device;
    el('owner').textContent = owner || 'Checking account…';
    el('preference-row').hidden = !connected || !device.allow_location;
    el('preferences').textContent = 'Every ' + updateInterval(device) + ' seconds · ' +
      (highAccuracy(device) ? 'High accuracy' : 'Balanced accuracy');
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
    renderPermissions(connected && device.allow_location && !queued);
    let message = 'Scan the QR code in VP3 Phone Tracking to connect this phone.';
    let gpsState = 'Off';
    if (!secure) message = 'Open this page over HTTPS to connect and share location.';
    else if (initial) message = 'Checking your connection…';
    else if (device?.status === 'pending') message = 'Waiting for the account owner to approve this phone.';
    else if (connected) {
      message = device.allow_location ? 'You choose when to share your location.' : 'The owner has disabled location access for this phone.';
      if (device.location_state === 'requested') message = 'The owner requested your location. Tap Start sharing to allow it.';
      if (device.location_state === 'denied') message = 'The previous location attempt ended without access. Tap Start sharing to try again.';
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
      if (run?.sensorMessage) message = run.sensorMessage;
      if (!run && device.allow_location && gpsNotice?.id === device.id && gpsNotice.generation === device.generation) {
        message = gpsNotice.message;
      }
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
  function renderPermissions(connected) {
    const historyState = device?.history_state || 'off';
    const days = device?.history_retention_days;
    const period = days === 1 ? '1 day' : days + ' days';
    const canApprove = mobileActive(device) && available && secure && !!owner && !actionBusy && !dead && navigator.onLine !== false;
    renderArrivals(connected,canApprove);
    const endingHistory = withdrawing('history');
    el('history').hidden = !connected || !Number.isSafeInteger(device?.history_version);
    let historyText = 'Off';
    if (endingHistory) historyText = 'History is off on this phone. Waiting for VP3 to confirm.';
    else if (historyState === 'requested') historyText = (owner || 'The owner') + ' wants to keep a trail of this phone’s shared locations for ' + period + '.';
    else if (historyState === 'active') historyText = (owner || 'The owner') + ' can keep each new shared location for ' + period + '.';
    el('history-status').textContent = historyText;
    el('history-accept').hidden = el('history-decline').hidden = historyState !== 'requested' || endingHistory;
    el('history-accept').disabled = !canApprove;
    el('history-decline').disabled = actionBusy;
    el('history-stop').hidden = historyState !== 'active' || endingHistory;
    const endingPlaces = withdrawing('places');
    const allowed = device?.allow_saved_places === true;
    el('places').hidden = !connected || !mobileActive(device) || typeof device.allow_saved_places !== 'boolean';
    const who = owner || 'The owner';
    el('places-status').textContent = endingPlaces ? 'Permission withdrawn on this phone. Waiting for VP3 to confirm.'
      : allowed ? who + ' can save named places during this live session. Saved places remain until the owner deletes them.'
      : 'Allow ' + who + ' to save your current and future live locations as named places. Saved places remain until the owner deletes them.';
    el('places-allow').hidden = allowed || endingPlaces;
    el('places-allow').disabled = !canApprove;
    el('places-stop').hidden = !allowed || endingPlaces;
  }
  let arrivalSignature = '';
  function renderArrivals(connected,canApprove) {
    const rules = connected && mobileActive(device) ? arrivalRules() : [];
    const rows = rules.map(rule => ({rule,ending:endingArrival(rule)}));
    const signature = JSON.stringify([rows,owner,canApprove,actionBusy]);
    if (arrivalSignature === signature) return;
    arrivalSignature = signature;
    el('arrivals').hidden = !rows.length;
    const list = el('arrival-rules');
    list.replaceChildren();
    for (const {rule,ending} of rows) {
      const row = document.createElement('li'), name = document.createElement('h3'), status = document.createElement('p');
      row.dataset.ruleId = rule.id;
      name.textContent = rule.place_name;
      const who = owner || 'The account owner';
      if (ending) status.textContent = 'Alerts stopped on this phone. Waiting for VP3 to confirm.';
      else if (rule.state === 'active') status.textContent = who + ' can receive one private Agent Chat alert per visit here during this sharing session.';
      else status.textContent = who + ' requests one private Agent Chat alert per visit here while this phone shares live.';
      if (!ending && rule.offers_local_search) {
        status.textContent += ' An arrival can prompt the account owner to approve a local business search.';
      }
      row.append(name,status);
      if (!ending) {
        const buttons = document.createElement('div');
        buttons.className = 'phone-permission-buttons';
        function add(action,label,secondary) {
          const button = document.createElement('button');
          button.type = 'button';
          button.dataset.arrivalAction = action;
          button.textContent = label;
          button.className = secondary ? 'secondary' : '';
          button.disabled = action === 'accept_arrival' ? !canApprove : action === 'decline_arrival' && actionBusy;
          button.addEventListener('click',() => actOnArrival(rule,action));
          buttons.append(button);
        }
        if (rule.state === 'requested') {
          add('accept_arrival','Allow arrival alerts',false);
          add('decline_arrival','Decline',true);
        } else add('stop_arrival','Stop arrival alerts',true);
        row.append(buttons);
      }
      list.append(row);
    }
  }
  function reportArrivalFailure(error) {
    // A rule may have been removed while this phone was off-line. Check the credential and scope separately.
    if ([403,404,409].includes(error.status)) {
      info = 'The arrival alert changed. Checking this phone’s current permissions.';
      schedulePoll();
    } else reportFailure(error);
  }
  async function flushArrivalWithdrawals() {
    if (!device || dead || pending || navigator.onLine === false) return;
    for (const item of Array.from(arrivalWithdrawals.values())) {
      const rule = arrivalRules().find(r => r.id === item.rule_id);
      if (!rule || !sameArrivalIntent(item,rule)) { clearArrivalIntent(item); continue; }
      // A lost acceptance reply can leave a local decline one revision behind the server's active grant.
      const action = rule.state === 'active' ? 'stop_arrival' : 'decline_arrival';
      const body = {device_id:item.device_id,generation:item.generation,rule_id:item.rule_id,rule_version:rule.version};
      try {
        accept(await request(action,body));
        clearArrivalIntent(item);
        info = '';
      } catch (error) { reportArrivalFailure(error); break; }
    }
  }
  function actOnArrival(rule,action) {
    const current = arrivalRules().find(r => r.id === rule.id && r.version === rule.version && r.state === rule.state);
    if (!current || pending || dead || endingArrival(rule) || (action === 'decline_arrival' && actionBusy)) return;
    const body = {device_id:device.id,generation:device.generation,rule_id:rule.id,rule_version:rule.version};
    if (action === 'accept_arrival') {
      if (rule.state !== 'requested' || !owner || !secure || !available || actionBusy || navigator.onLine === false
        || device.status !== 'approved' || !device.allow_location || !mobileActive(device)) return;
      actionBusy = true;
      render();
      queue(async () => {
        try {
          if (!pending && !dead && available && navigator.onLine !== false && body.device_id === device?.id
            && body.generation === device.generation && !endingArrival(rule)
            && arrivalRules().some(r => r.id === rule.id && r.version === rule.version && r.state === 'requested')) {
            accept(await request(action,body)); info = '';
          }
        } catch (error) { reportArrivalFailure(error); }
        finally { actionBusy = false; render(); }
      });
      return;
    }
    if (action !== (rule.state === 'requested' ? 'decline_arrival' : 'stop_arrival')) return;
    const intent = {...body,action};
    write('localStorage',arrivalKey + rule.id,intent);
    arrivalWithdrawals.set(rule.id,intent);
    render();
    queue(async () => { await flushArrivalWithdrawals(); render(); });
  }
  function grantScope(scope) {
    if (!device || !owner || !available || dead || !secure || actionBusy || pending || withdrawing(scope)
      || navigator.onLine === false || device.status !== 'approved' || !device.allow_location || !mobileActive(device)) return;
    const history = scope === 'history';
    if (history ? device.history_state !== 'requested' : device.allow_saved_places !== false) return;
    const body = {device_id:device.id,generation:device.generation};
    if (history) body.history_version = device.history_version;
    else { body.allow_saved_places = true; body.place_permission_version = device.place_permission_version; }
    actionBusy = true;
    render();
    queue(async () => {
      try {
        if (dead || pending || withdrawing(scope) || navigator.onLine === false || body.device_id !== device?.id
          || body.generation !== device.generation || (history ? body.history_version !== device.history_version :
            body.place_permission_version !== device.place_permission_version)) return;
        accept(await request(history ? 'accept_history' : 'set_place_permission',body));
        info = '';
      } catch (error) { reportFailure(error); }
      finally { actionBusy = false; render(); }
    });
  }
  async function flushWithdrawal(scope) {
    const item = withdrawals[scope];
    if (!item || !device || pending || dead || navigator.onLine === false) return;
    if (!matchesWithdrawal(scope,item,device) || (scope === 'history'
      ? !['requested','active'].includes(device.history_state) : device.allow_saved_places !== true)) {
      clearWithdrawal(scope);
      return;
    }
    const body = {device_id:item.id,generation:device.generation};
    if (scope === 'history') body.history_version = item.history_version;
    else { body.allow_saved_places = false; body.place_permission_version = item.place_permission_version; }
    try {
      accept(await request(item.action,body));
      if (withdrawals[scope] === item) clearWithdrawal(scope);
      info = '';
    } catch (error) { reportFailure(error); }
  }
  function withdrawScope(scope) {
    if (!device || withdrawing(scope) || pending) return;
    const intent = {id:device.id,generation:device.generation};
    if (scope === 'history') {
      if (!['requested','active'].includes(device.history_state)) return;
      intent.history_version = device.history_version;
      intent.action = device.history_state === 'requested' ? 'decline_history' : 'stop_history';
    } else {
      if (!device.allow_saved_places) return;
      intent.action = 'set_place_permission';
      intent.place_permission_version = device.place_permission_version;
    }
    write('localStorage',scope + '-withdrawal',intent);
    withdrawals[scope] = intent;
    render();
    queue(async () => { await flushWithdrawal(scope); render(); });
  }
  function reportFailure(error) {
    if (error.status === 401 || error.status === 403 || error.status === 404) {
      endDevice('Connection expired or disconnected. Scan a new QR code to connect again.');
    } else if (error.status === 409) {
      info = 'The phone’s permissions changed. Checking the current state.';
      schedulePoll();
    } else {
      if (run) discardDeferred(run);
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
      const message = pending === item && gpsNotice?.end === item ? gpsNotice.message : '';
      if (pending === item) clearPending();
      info = '';
      accept(result);
      if (message && device?.id === item.id) noteGps(message);
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
    gpsNotice = reason ? {end:pending,message:reason} : null;
    info = '';
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
      for (const scope of scopes) await flushWithdrawal(scope);
      await flushArrivalWithdrawals();
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
  function canCollect(session) {
    return run === session && available && !dead && navigator.onLine !== false && !withdrawing('places');
  }
  function historyVersion() {
    return device?.history_state === 'active' && !withdrawing('history') ? device.history_version : null;
  }
  function fresh(time) {
    return Number.isFinite(time) && time >= now() - 55 && time <= now() + 5;
  }
  function reportDelay(session) {
    return session.lastSent === null ? 0 : Math.max(0, session.lastSent + session.interval * 1000 - performance.now());
  }
  function sendLatestFix(session) {
    if (!canCollect(session) || !session.confirmed || session.sending || !session.next || session.timer !== null) return;
    const delay = reportDelay(session);
    if (delay > 0) {
      session.timer = setTimeout(() => {
        session.timer = null;
        sendLatestFix(session);
      }, Math.ceil(delay));
      return;
    }
    session.sending = true;
    queue(async () => {
      try {
        // A preceding status response may have lengthened the interval while this upload was queued.
        if (reportDelay(session) > 0) return;
        const sample = session.next;
        const sampleHistory = session.nextHistory;
        const activeGrants = arrivalGrants();
        const grants = Object.fromEntries(Object.entries(session.nextArrivals || {}).filter(([id,version]) => activeGrants[id] === version));
        session.next = null;
        if (!sample || !canCollect(session)) return;
        const time = Math.floor(sample.timestamp / 1000);
        if (!fresh(time) || time <= session.lastTime) return;
        const {latitude,longitude,accuracy} = sample.coords;
        const result = await request('fix',{device_id:session.id,generation:session.generation,latitude,longitude,accuracy,fix_at:time,
          ...(sampleHistory && sampleHistory === historyVersion() ? {history_version:sampleHistory} : {}),
          ...(Object.keys(grants).length ? {arrival_grants:grants} : {})});
        if (run !== session) return;
        session.lastTime = time;
        session.lastSent = performance.now();
        session.needsFresh = false;
        session.sensorMessage = '';
        accept(result);
        info = '';
      } catch (error) {
        if (run !== session) return;
        if ([401,403,404,409].includes(error.status)) cancelWatch();
        if (error.status === 422) {
          session.needsFresh = true;
          info = 'Waiting for a fresh GPS reading.';
        } else reportFailure(error);
      } finally {
        session.sending = false;
        // Only the newest unsent sample survives an in-flight upload, within this grant.
        if (canCollect(session)) sendLatestFix(session);
        else session.next = null;
        render();
      }
    });
  }
  function monitor(session) {
    // Ignore a late callback from a watch replaced during the same sharing session.
    const reader = {};
    session.reader = reader;
    session.needsFresh = false;
    session.hasReading = false;
    clearSensorWatch(session.watch);
    session.watch = null;
    session.refreshedAt = Date.now();
    try {
      const watch = navigator.geolocation.watchPosition(position => {
        if (!canCollect(session) || session.reader !== reader) return;
        const {latitude,longitude,accuracy} = position.coords;
        if (!fresh(Math.floor(position.timestamp / 1000)) ||
            ![latitude,longitude,accuracy].every(Number.isFinite) || Math.abs(latitude)>90 ||
            Math.abs(longitude)>180 || accuracy<0 || accuracy>40075000) return;
        session.hasReading = true;
        if (Math.floor(position.timestamp / 1000) <= session.lastTime) return;
        if (!session.next || position.timestamp >= session.next.timestamp) {
          session.next = position;
          session.nextHistory = historyVersion();
          session.nextArrivals = arrivalGrants();
        }
        sendLatestFix(session);
      }, error => {
        if (run !== session || session.reader !== reader) return;
        if (error.code === 1) {
          if (policyBlocksLocation()) stopReader(session,policyMessage);
          else finish('denied','Location permission was denied for this attempt. Check location access for this site and browser. On iPhone, open Settings → Privacy & Security → Location Services → Safari Websites and choose While Using the App.');
        } else {
          session.needsFresh = true;
          session.sensorMessage = (error.code === 3 ? 'The location request timed out.' : 'The phone could not determine its location.') +
            ' Waiting for a fresh reading.';
          render();
        }
      }, {enableHighAccuracy:session.highAccuracy,maximumAge:0,timeout:20000});
      if (run === session && session.reader === reader) session.watch = watch;
      else clearSensorWatch(watch);
    } catch {
      if (run === session) stopReader(session,policyBlocksLocation() ? policyMessage :
        'The browser could not start location updates. Tap Start sharing to try again.');
    }
  }
  function stopReader(session, message) {
    if (session.startSent) { finish('stop',message); return; }
    // The queued server grant has not left this page. Leave the owner's request intact for retry.
    cancelWatch();
    noteGps(message);
    info = '';
    render();
  }
  function start() {
    if (dead || !available || !secure || !navigator.geolocation || !owner || actionBusy || run || pending ||
        device?.status !== 'approved' || !device.allow_location || mobileActive(device)) return;
    info = '';
    if (policyBlocksLocation()) { noteGps(policyMessage); render(); return; }
    gpsNotice = null;
    const initialGeneration = device.generation;
    const session = {id:device.id,baseGeneration:initialGeneration,startSent:false,generation:initialGeneration + (device.location_state === 'requested' ? 0 : 1),
      watch:null,confirmed:false,next:null,lastTime:0,lastSent:null,timer:null,sending:false,needsFresh:false,
      interval:updateInterval(device),highAccuracy:highAccuracy(device)};
    run = session; actionBusy = true;
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
        sendLatestFix(session);
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
  el('history-accept').addEventListener('click',() => grantScope('history'));
  el('history-decline').addEventListener('click',() => withdrawScope('history'));
  el('history-stop').addEventListener('click',() => withdrawScope('history'));
  el('places-allow').addEventListener('click',() => grantScope('places'));
  el('places-stop').addEventListener('click',() => withdrawScope('places'));
  el('retry').addEventListener('click',schedulePoll);
  window.addEventListener('online',schedulePoll);
  window.addEventListener('offline',() => {
    if (run) discardDeferred(run);
    available = false; info = ''; render();
  });
  window.addEventListener('storage',event => {
    if (typeof event.key === 'string' && event.key.startsWith(prefix + arrivalKey)) {
      const id = event.key.slice((prefix + arrivalKey).length);
      if (!ruleIdPattern.test(id)) return;
      const item = readArrivalWithdrawal(id);
      if (item) arrivalWithdrawals.set(id,item);
      else arrivalWithdrawals.delete(id);
      render(); schedulePoll(); return;
    }
    for (const scope of scopes) {
      if (event.key === prefix + scope + '-withdrawal') {
        withdrawals[scope] = storedWithdrawal(scope);
        render(); schedulePoll(); return;
      }
    }
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
    if (run?.confirmed && canCollect(run) && !run.sending && !run.next) {
      // Allow the native reader to finish its first acquisition before replacing it.
      const interval = run.needsFresh ? 6000 : Math.max(run.interval * 1000, run.hasReading ? 0 : 25000);
      if (Date.now() - Math.max(run.refreshedAt, run.lastTime * 1000) >= interval) monitor(run);
    }
  }, 3000);
  render();
  schedulePoll();
})();
