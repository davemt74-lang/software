(() => {
  'use strict';
  const root = document.querySelector('#phone-workspace');
  if (!root) return;
  const byId = id => document.getElementById(id);
  const status = byId('phone-status');
  const pairing = byId('phone-pairing');
  const streetDialog = byId('phone-street-consent');
  const revokeDialog = byId('phone-revoke-confirm');
  const pendingHost = byId('phone-pending');
  const handoffHost=byId('phone-handoff-pending');
  let handoffSignature='';
  const list = byId('phone-devices');
  const detail = byId('phone-detail');
  const summary = byId('phone-detail-summary');
  const preferences = byId('phone-preferences');
  const intervalSelect = preferences.elements.update_interval_seconds;
  const accuracySelect = preferences.elements.accuracy_mode;
  const saveSettings = preferences.querySelector('button[type=submit]');
  const settingsStatus = preferences.querySelector('[role=status]');
  const followStates = new Set(['requested', 'waiting', 'live', 'stale']);
  const stateLabels = {off:'Location off', requested:'Sharing requested', waiting:'Waiting for location', live:'Sharing live', stale:'Last known location', denied:'Location denied'};
  const roleLabels = {owner:'Owner', guest:'Guest', device:'Device'};
  let devices = [];
  let selectedId = '';
  let followId = '';
  let linkedDevice = new URLSearchParams(location.search).get('device_id');
  const initialInformation=new URLSearchParams(location.search).get('profile')==='information';
  const initialManaged=new URLSearchParams(location.search).get('profile')==='managed';
  let openManagedFromLink=initialManaged;
  let resumeSelection = null;
  let choiceToRevoke = null;
  let requestNotice = null;
  let expires = 0;
  let clockDelta = 0;
  let staleAfter = 60;
  let revision = 0;
  let reading = false;
  let writing = false;
  let savingPreferencesFor = '';
  let available = false;
  let inviteVersion = 0;
  let listSignature = '';
  let detailSignature = '';
  let markerSignature = '';
  let lastFollowPoint = '';
  const seconds = () => Math.floor(Date.now() / 1000) + clockDelta;
  const selected = () => devices.find(d => d.id === selectedId && d.status === 'approved');
  function element(tag, text, className) {
    const node = document.createElement(tag);
    if (text !== undefined) node.textContent = text;
    if (className) node.className = className;
    return node;
  }
  function actionButton(action, label, className) {
    const button = element('button', label, className);
    button.type = 'button';
    button.dataset.action = action;
    return button;
  }
  function effectiveState(d) {
    if (d.location_state === 'live' && (!d.fix_at || seconds() - d.fix_at >= staleAfter)) return 'stale';
    return d.location_state;
  }
  function hasPoint(d) {
    return d.status === 'approved' && d.allow_location && ['live','stale'].includes(effectiveState(d))
      && Number.isFinite(d.latitude) && Number.isFinite(d.longitude) && d.fix_at > 0;
  }
  function connection(d) {
    return d.connected && seconds() - d.last_seen_at < staleAfter ? 'Connected' : 'Offline';
  }
  function timestamp(time) {
    return time ? new Date(time * 1000).toLocaleString(undefined, {month:'short', day:'numeric', hour:'numeric', minute:'2-digit', second:'2-digit'}) : '—';
  }
  const leaflet = window.L;
  const map = leaflet?.map('phone-map', {
    minZoom:1, maxZoom:19, maxBounds:[[-86,-210],[86,210]], maxBoundsViscosity:.85,
    scrollWheelZoom:false, preferCanvas:true, attributionControl:true, zoomSnap:.25
  }).setView([22,0], 1.75);
  let countries, regions, labels, streets;
  const grid = map && leaflet.layerGroup().addTo(map);
  const cityPositions = [];
  const labelPositions = [];
  const markers = map && leaflet.layerGroup().addTo(map);
  const camera = window.vp3PhoneMapCameraV1(root, seconds, (id, fromMarker) => {
    // A map refresh may have replaced the triggering pin while the dialog was open.
    queueMicrotask(() => {
      if (document.hidden || byId('phone-stream-dialog').open) return;
      const marker = fromMarker && markers?.getLayers().find(layer => layer.options?.deviceId === id)?.getElement();
      const button = Array.from(list.querySelectorAll('[data-focus]')).find(item => item.dataset.focus === id);
      (marker || (!fromMarker && detail.querySelector('[data-action="open_stream"]')) || button || byId('phone-connect')).focus({preventScroll:true});
    });
  });
  const archive = window.vp3PhoneArchiveV1({
    map, leaflet, byId, element, seconds, timestamp, request, selectDevice, selected,
    available:() => available && !document.hidden, writing:() => writing, effectiveState,
    stopFollowing:() => { followId = ''; }, write:writeArchive,
    onReadDenied:reportFailure
  });
  function drawCountryNames() {
    if (!labels) return;
    labels.clearLayers();
    grid.clearLayers();
    if (streets) return;
    const zoom = map.getZoom();
    const rank = zoom < 2 ? 1 : zoom < 3 ? 2 : zoom < 4 ? 3 : 6;
    const box = map.getBounds().pad(.1);
    const occupied = [];
    if (zoom >= 4 && regions && !map.hasLayer(regions)) regions.addTo(map);
    if (zoom < 4 && regions) regions.remove();
    // A local reference grid stays visible even when zoomed inside a single city.
    const span = Math.max(.0001, box.getEast()-box.getWest());
    const step = [0.0005,.001,.002,.005,.01,.02,.05,.1,.2,.5,1,2,5,10,20,30,60,90].find(x => x >= span/10) || 90;
    const style = {color:'#92aaa0',weight:1,opacity:.28,interactive:false,dashArray:'2 5'};
    for (let lng=Math.ceil(box.getWest()/step)*step; lng<=box.getEast(); lng+=step) {
      leaflet.polyline([[box.getSouth(),lng],[box.getNorth(),lng]],style).addTo(grid);
    }
    for (let lat=Math.ceil(box.getSouth()/step)*step; lat<=box.getNorth(); lat+=step) {
      leaflet.polyline([[lat,box.getWest()],[lat,box.getEast()]],style).addTo(grid);
    }
    const names = zoom > 4 ? cityPositions.filter(p => p.minZoom <= zoom+1).concat(labelPositions) : labelPositions;
    for (const pos of names) {
      if (pos.rank > rank || !box.contains([pos.y,pos.x])) continue;
      const point = map.latLngToContainerPoint([pos.y,pos.x]);
      const width = pos.name.length * 6.2 + 10;
      if (occupied.some(other => Math.abs(other.x - point.x) < (other.width + width) / 2 && Math.abs(other.y - point.y) < 18)) continue;
      occupied.push({x:point.x, y:point.y, width});
      leaflet.marker([pos.y,pos.x], {
        keyboard:false, interactive:false, pane:'shadowPane', icon:leaflet.divIcon({
          className:pos.city ? 'phone-city' : 'phone-country', html:element('span', pos.name), iconSize:[width,15], iconAnchor:[pos.city ? 2 : width/2,7.5]
        })
      }).addTo(labels);
    }
  }
  if (map) {
    map.attributionControl.setPrefix(false);
    map.attributionControl.addAttribution('Natural Earth');
    leaflet.control.scale({imperial:false}).addTo(map);
    map.on('dragstart', () => { if (followId) { followId = ''; render(); } });
    map.on('moveend', drawCountryNames);
    fetch(root.dataset.basemap, {credentials:'same-origin'}).then(r => {
      if (!r.ok) throw new Error();
      return r.json();
    }).then(data => {
      countries = leaflet.geoJSON(data, {interactive:false, style:{color:'#a5bcae',weight:1,opacity:.85,fillColor:'#edf0e6',fillOpacity:1}});
      if (!streets) countries.addTo(map).bringToBack();
      regions = leaflet.geoJSON(data.regions, {interactive:false,style:{color:'#a9bbac',weight:1,opacity:.8}});
      cityPositions.push(...data.cities.map(([y,x,name,minZoom]) => ({x,y,name,minZoom,city:true,rank:0})));
      labels = leaflet.layerGroup().addTo(map);
      labelPositions.push(...data.features.map(f => f.properties).sort((a,b) => a.rank-b.rank));
      drawCountryNames();
    }).catch(() => { byId('phone-map').setAttribute('aria-label','Country outlines could not load. Phone locations are also listed below.'); });
  } else {
    byId('phone-map').textContent = 'Map unavailable. Location details appear below.';
    byId('phone-streets').disabled = true;
  }
  function removeStreets() {
    if (streets) {
      map.removeLayer(streets);
      streets = null;
      if (countries) countries.addTo(map).bringToBack();
      drawCountryNames();
      byId('phone-streets').textContent = 'Street detail';
    }
  }
  byId('phone-streets').addEventListener('click', () => streets ? removeStreets() : streetDialog.showModal());
  byId('phone-street-accept').addEventListener('click', () => {
    streetDialog.close();
    if (streets || !map) return;
    if (countries) countries.remove();
    regions?.remove();
    grid.clearLayers();
    labels?.clearLayers();
    streets = leaflet.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', {
      maxZoom:19, keepBuffer:0, updateWhenIdle:true, referrerPolicy:'origin',
      attribution:'&copy; <a href="https://www.openstreetmap.org/copyright" target="_blank" rel="noopener noreferrer">OpenStreetMap contributors</a>'
    }).addTo(map);
    byId('phone-streets').textContent = 'Use local map';
    if (selected() && hasPoint(selected())) focusPoint(selected());
  });
  for (const id of ['phone-street-cancel','phone-street-close']) byId(id).addEventListener('click', () => streetDialog.close());
  function focusPoint(d) {
    if (!map || !hasPoint(d)) return;
    map.setView([d.latitude,d.longitude], streets ? 16 : 7, {animate:false});
  }
  function fitPhones() {
    if (!map) return;
    const points = devices.filter(hasPoint).map(d => [d.latitude,d.longitude]);
    if (points.length) {
      map.fitBounds(points, {padding:[38,38], maxZoom:streets ? 16 : 7, animate:false});
    }
  }
  byId('phone-fit').addEventListener('click', () => { followId = ''; fitPhones(); render(); });
  function renderMarkers() {
    const withPoint = devices.filter(hasPoint);
    const liveCount = withPoint.filter(d => effectiveState(d) === 'live').length;
    const staleCount = withPoint.length - liveCount;
    byId('phone-map-count').textContent = !available ? 'Location unavailable' :
      (staleCount ? liveCount+' live · '+staleCount+' last known' : liveCount ? liveCount+' sharing live' : 'No live locations');
    byId('phone-fit').disabled = !map || !withPoint.length;
    const signature = JSON.stringify([selectedId, withPoint.map(d => [d.id,d.name,d.latitude,d.longitude,d.accuracy,effectiveState(d),camera.liveCamera(d)?.session_id])]);
    if (!map || signature === markerSignature) return;
    const wasEmpty = markers.getLayers().length === 0;
    markerSignature = signature;
    markers.clearLayers();
    for (const d of withPoint) {
      const stale = effectiveState(d) === 'stale';
      const coordinate = [d.latitude,d.longitude];
      if (Number.isFinite(d.accuracy) && d.accuracy > 0) {
        leaflet.circle(coordinate, {radius:d.accuracy, interactive:false, color:stale ? '#966d36' : '#14725c', weight:1.25, fillOpacity:.16}).addTo(markers);
      }
      const streaming = Boolean(camera.liveCamera(d));
      const title = d.name+' · '+stateLabels[effectiveState(d)]+(streaming ? ' · Open live camera' : '');
      const marker = leaflet.marker(coordinate, {
        title, alt:title, deviceId:d.id, zIndexOffset:d.id === selectedId ? 1000 : 0,
        icon:leaflet.divIcon({className:'phone-map-marker'+(stale ? ' is-stale' : '')+(d.id === selectedId ? ' is-selected' : '')
          +(streaming ? ' is-streaming' : ''),iconSize:[18,18],iconAnchor:[9,9]})
      }).bindTooltip(element('span',title),{direction:'top',offset:[0,-11]}).on('click', () => {
        const device = devices.find(device => device.id === d.id);
        selectDevice(d.id);
        if (camera.liveCamera(device)) camera.open(device,true);
      }).addTo(markers);
      const icon = marker.getElement();
      icon.setAttribute('aria-label',title);
      if (streaming) icon.setAttribute('aria-haspopup','dialog');
      icon.addEventListener('keydown', event => {
        if (event.key !== 'Enter' && event.key !== ' ') return;
        event.preventDefault();
        event.stopPropagation();
        marker.fire('click');
      });
    }
    const followed = withPoint.find(d => d.id === followId);
    if (followed) {
      const position = [followed.latitude,followed.longitude].join(',');
      if (wasEmpty) focusPoint(followed);
      else if (lastFollowPoint !== position) map.panTo([followed.latitude,followed.longitude], {animate:false});
      lastFollowPoint = position;
    } else if (wasEmpty && withPoint.length) fitPhones();
    if (!withPoint.length && !wasEmpty && !archive.hasMapData()) {
      removeStreets();
      map.setView([22,0],1.75,{animate:false});
    }
  }
  function selectDevice(id) {
    if (!devices.some(d => d.id === id && d.status === 'approved')) return;
    linkedDevice = null;
    if (id !== selectedId) followId = '';
    selectedId = id;
    if (selected()) focusPoint(selected());
    render();
  }
  function renderList(approved) {
    const signature = JSON.stringify([available, selectedId, approved.map(d => [d.id,d.name,d.role,connection(d),effectiveState(d),d.checkins])]);
    if (listSignature === signature) return;
    const focusedId = list.contains(document.activeElement) ? document.activeElement.closest('[data-device-id]')?.dataset.deviceId : '';
    listSignature = signature;
    list.replaceChildren();
    if (!approved.length) {
      list.append(element('p', available ? 'No phones connected. Use + Connect Phone to pair your first phone.' : 'Phone information is unavailable.', 'phone-empty'));
    }
    for (const d of approved) {
      const wrapper = element('div');
      wrapper.dataset.deviceId = d.id;
      const button = element('button', undefined, 'phone-device');
      button.type = 'button';
      button.dataset.focus = d.id;
      button.setAttribute('aria-pressed', String(d.id === selectedId));
      const heading = element('span',undefined,'phone-device-heading');
      heading.append(element('strong',d.name),element('span',roleLabels[d.role] || 'Device','phone-role'));
      button.append(heading, element('small', connection(d)+' · '+stateLabels[effectiveState(d)]));
      const visit=d.checkins?.active;
      if(visit){const mins=Math.floor(visit.duration_seconds/60);button.append(element('small','Checked in'+(visit.label?' at '+visit.label:'')+' · '+Math.floor(mins/60)+'h '+mins%60+'m'));}
      else if(d.checkins?.recent?.[0]?.reason==='left_area')button.append(element('small','Automatically checked out after leaving.'));
      button.addEventListener('click', () => selectDevice(d.id));
      wrapper.append(button);
      list.append(wrapper);
      if (focusedId === d.id) button.focus({preventScroll:true});
    }
  }
  // The map remains the main workspace. These tabs are scoped to its selected phone.
  let profileTab='communication',profileDevice='',profileSignature='';
  const exchange=window.VP3PhoneInformationV1?.({request,onChanged:()=>{void refresh();}});
  const dialerOwner=window.VP3RemoteDialerV1?.owner({request:dialerOwnerRequest});
  const managedOwner=window.VP3ManagedCallUIV1?.owner({request:managedOwnerRequest,directRoot:dialerOwner?.root,openBrowser:(d,source)=>{
    if(window.VP3PhoneWorkspaceV1?.openConnectedPhone?.(d,source)!==true)status.textContent='Review the current connected phone and assigned line before calling.';
  }});
  if(managedOwner)byId('phone-dialer-owner-host')?.append(managedOwner.root);
  else if(dialerOwner)byId('phone-dialer-owner-host')?.append(dialerOwner.root);
  async function managedOwnerRequest(action,fields){
    const d=selected();
    if(!available||writing||document.hidden||!d||d.id!==fields.device_id||d.generation!==fields.generation)throw Error('Selected phone changed.');
    writing=true;const version=++revision;
    try{
      const result=await request(action,fields);
      if(version!==revision||document.hidden)throw Error('View changed.');
      const index=devices.findIndex(x=>x.id===d.id&&x.generation===d.generation&&x.communication?.revision===d.communication?.revision);
      if(index<0)throw Error('Connected profile changed.');
      devices[index]={...devices[index],managed_callback:result.managed_callback};render();return result;
    }finally{writing=false;void refresh();}
  }
  async function dialerOwnerRequest(action,fields){
    const d=selected();
    if(!available||writing||document.hidden||!d||d.id!==fields.device_id||d.generation!==fields.generation||d.dialer?.revision!==fields.dialer_revision)throw Error('Selected phone changed.');
    writing=true;const version=++revision;
    try{
      const result=await request(action,fields);
      if(version!==revision||document.hidden)throw Error('View changed.');
      const index=devices.findIndex(x=>x.id===d.id&&x.generation===d.generation&&x.dialer?.revision===d.dialer.revision);
      if(index<0||result.dialer?.revision!==d.dialer.revision)throw Error('Dialer changed.');
      devices[index]={...devices[index],dialer:result.dialer};render();return result;
    }finally{writing=false;void refresh();}
  }
  function renderCommunication(d){
    const host=byId('phone-communication-profile');if(!host)return;
    host.hidden=!d;
    if(!d){exchange?.bind(null,false,false);host.replaceChildren();profileDevice='';profileSignature='';return;}
    if(profileDevice!==d.id){profileDevice=d.id;profileTab=initialInformation&&linkedDevice===d.id?'information':'communication';profileSignature='';}
    exchange?.bind(d,available&&!document.hidden,profileTab==='information');
    if(profileTab==='information'&&exchange&&host.contains(exchange.root)&&host.dataset.exchangeDevice===d.id)return;
    const p=d.communication;
    const extra=profileTab==='activity'?[connection(d),d.last_seen_at,effectiveState(d),d.history_state,d.history_retention_days,Boolean(camera.liveCamera(d)),Boolean(d.agent_chat?.conversation_id)]:profileTab==='permissions'?[d.allow_location,d.holder_can_save_places,Boolean(camera.liveCamera(d)),writing]:[d.agent_chat,typeof window.VP3PhoneWorkspaceV1?.openConnectedPhone==='function'];
    const sig=JSON.stringify([d.id,d.generation,p,profileTab,extra,d.push]);
    if(sig===profileSignature)return;
    // Do not overwrite an in-progress permission form on an unrelated GPS poll.
    const focused=host.contains(document.activeElement)&&document.activeElement.closest('form');
    const oldRev=host.dataset.profileRevision;
    if(focused&&oldRev===String(p?.revision??'')&&profileTab==='permissions'&&!writing)return;
    profileSignature=sig;host.dataset.profileRevision=String(p?.revision??'');host.replaceChildren();
    host.append(element('h3','Connected user'));
    const tabs=element('div',undefined,'phone-profile-tabs');tabs.setAttribute('aria-label','Connected user sections');
    for(const [key,title] of [['communication','Communication'],['permissions','Permissions'],['information','Information'],['activity','Activity']]){
      const b=element('button',title);b.type='button';b.setAttribute('aria-pressed',String(key===profileTab));
      b.addEventListener('click',()=>{profileTab=key;profileSignature='';renderCommunication(selected());});tabs.append(b);
    }
    host.append(tabs);
    const facts=element('dl',undefined,'phone-info');
    const field=(key,val)=>facts.append(element('dt',key),element('dd',String(val)));
    if(profileTab==='communication'){
      if(!p){host.append(element('p','Communication preferences require the latest Cloud upgrade.','phone-detail-meta'));return;}
      field('Personal number',p?.phone_saved?p.phone_masked:'Not saved');
      field('Telephone calls',p?.call_permission_saved?'Both permissions saved':p?.owner_calls?'Awaiting phone holder':'Not allowed');
      field('Proactive check-ins',p?.proactive_permission_saved?'Preference saved':'Off');
      field('Information requests',p?.request_permission_saved?'Preference saved':'Off');
      if(p){field('Calling hours',p.window_start+' – '+p.window_end+' · '+p.timezone);field('Call limit',p.max_calls_per_day+' per day · '+p.min_gap_minutes+' min between calls');}
      host.append(facts);
      const ready=p.call_permission_saved===true&&Number.isSafeInteger(d.agent_chat?.conversation_id)&&d.agent_chat.conversation_id>0&&typeof window.VP3PhoneWorkspaceV1?.openConnectedPhone==='function';
      const actions=element('div',undefined,'phone-command-row'),call=actionButton('open_connected_call','Call phone','phone-primary');call.disabled=!ready;actions.append(call);host.append(actions);
      host.append(element('p',ready?'Place a user-requested call with your assigned line. The Phone drawer will ask for confirmation.':!p.call_permission_saved?'Both calling permissions are required.':!d.agent_chat?.conversation_id?'Open the paired phone chat to establish its linked conversation.':'The assigned browser calling service is unavailable.','phone-detail-meta'));
      host.append(element('p','Autonomous Agent calls remain disabled. Use Information to share updates or send requests.','phone-detail-meta'));
    }else if(profileTab==='permissions'){
      if(!p){host.append(element('p','Communication preferences require the latest Cloud upgrade.','phone-detail-meta'));return;}
      const form=element('form',undefined,'phone-profile-form');
      const checks={};
      for(const [key,label] of [['owner_calls','Allow telephone calls'],['owner_requests','Allow information requests']]){
        const l=element('label',undefined,'phone-profile-check'),input=element('input');input.type='checkbox';input.checked=p[key]===true;input.disabled=writing;checks[key]=input;l.append(input,element('span',label));form.append(l);
      }
      const save=element('button','Save permissions','phone-primary');save.type='submit';save.disabled=writing;form.append(save);
      form.addEventListener('submit',event=>{event.preventDefault();const current=selected();if(!event.isTrusted||!current||current.id!==d.id||current.communication?.revision!==p.revision||writing)return;
        void mutate('save_communication_grants',current,{communication_revision:p.revision,owner_calls:checks.owner_calls.checked,owner_requests:checks.owner_requests.checked});});
      host.append(form);
      field('Holder call consent',p.holder_calls?'Saved':'Off');field('Holder information consent',p.holder_requests?'Saved':'Off');
      field('Future relationship memory',p.holder_memory?'Requested':'Off');field('Location permission',d.allow_location?'Allowed':'Off');
      field('Saved places',d.holder_can_save_places?'Owner allows':'Off');field('Camera',camera.liveCamera(d)?'Currently shared':'Not currently shared');host.append(facts);
      host.append(element('p','The phone holder chooses their own number and preferences. These new settings do not grant access to location, camera or private files.','phone-detail-meta'));
    }else if(profileTab==='information'){
      host.dataset.exchangeDevice=d.id;
      if(exchange)host.append(exchange.root);else host.append(element('p','Information exchange is unavailable.','phone-detail-meta'));
    }else{
      field('Connection',connection(d));field('Last seen',timestamp(d.last_seen_at));field('Location',stateLabels[effectiveState(d)]);
      field('Push notifications',d.push?.state==='registered'?'Subscription saved · delivery pending':d.push?.state==='unavailable'?'Needs attention':'Not enrolled');
      field('Location history',d.history_state==='active'?'Enabled · '+d.history_retention_days+' days':d.history_state==='requested'?'Awaiting holder':'Off');
      field('Live camera',camera.liveCamera(d)?'Sharing':'Not sharing');field('Phone chat',d.agent_chat?.conversation_id?'Linked':'Not linked');
      host.append(facts);
      const actions=element('div',undefined,'phone-command-row');
      for(const [panel,label] of [['history','Location history'],['places','Saved places']]){
        const b=element('button',label);b.type='button';b.addEventListener('click',()=>{if(selected()?.id===d.id)archive.openPanel(panel);});actions.append(b);
      }
      host.append(actions,element('h3','Recent telephone activity'));
      const calls=p?.recent_calls;
      if(Array.isArray(calls)&&calls.length){
        const rows=element('ul',undefined,'phone-connected-calls');
        for(const call of calls.slice(0,5))rows.append(element('li',timestamp(call.created_epoch)+' · '+String(call.state||'unknown').replaceAll('_',' ')+' · '+Math.max(0,Number(call.duration_seconds)||0)+' sec'));
        host.append(rows);
      }else host.append(element('p',Array.isArray(calls)?'No connected-phone call reservations yet.':'Connected-phone call history is unavailable.','phone-detail-meta'));
      host.append(element('p','Relationship Brain and ongoing objectives remain a separate build.','phone-detail-meta'));
    }
  }

  function renderDetail(d) {
    detail.hidden = !d;
    renderPreferences(d);
    renderCommunication(d);
    const signature = JSON.stringify(d ? [d.id,d.name,d.role,d.agent_chat,d.allow_location,d.holder_can_save_places,d.generation,d.latitude,d.longitude,d.accuracy,d.fix_at,d.expires_at,connection(d),effectiveState(d),followId,camera.liveCamera(d)?.session_id] : []);
    if (signature === detailSignature) return;
    detailSignature = signature;
    const focused = summary.contains(document.activeElement) ? document.activeElement.dataset.action : null;
    summary.replaceChildren();
    delete detail.dataset.deviceId;
    if (!d) return;
    detail.dataset.deviceId = d.id;
    summary.append(element('h3',d.name),element('p',(roleLabels[d.role] || 'Device')+' · '+connection(d)+' · '+stateLabels[effectiveState(d)],'phone-detail-meta'));
    const fields = element('dl',undefined,'phone-info');
    function field(label,value) { fields.append(element('dt',label),element('dd',value)); }
    if (hasPoint(d)) {
      field(effectiveState(d) === 'stale' ? 'Last known position' : 'Position',d.latitude.toFixed(5)+', '+d.longitude.toFixed(5));
      field('Accuracy',Number.isFinite(d.accuracy) ? '± '+Math.round(d.accuracy)+' m' : 'Unavailable');
      field('Location updated',timestamp(d.fix_at));
    }
    const accessLabels = {
      off:'Phone approval required', requested:'Awaiting phone approval', denied:'Denied on phone',
      waiting:'Accepted on phone', live:'Accepted on phone', stale:'Accepted on phone'
    };
    field('Location access',d.allow_location ? accessLabels[effectiveState(d)] : 'Disabled');
    field('Session expires',timestamp(d.expires_at));
    summary.append(fields);
    const actions = element('div',undefined,'phone-command-row');
    if (Number.isSafeInteger(d.agent_chat?.conversation_id) && d.agent_chat.conversation_id > 0
        && Number.isSafeInteger(d.agent_chat.agent_id) && d.agent_chat.agent_id >= 0) {
      const link = element('a','Open phone chat');
      const url = new URL('chat.php',location.href);
      url.searchParams.set('agent',String(d.agent_chat.agent_id || 'system'));
      url.searchParams.set('conversation_id',String(d.agent_chat.conversation_id));
      link.href = url.href;
      actions.append(link);
    }
    if (camera.liveCamera(d)) {
      const play = actionButton('open_stream','View live camera','phone-primary');
      play.setAttribute('aria-haspopup','dialog');
      actions.append(play);
    }
    if (d.allow_location && !followStates.has(d.location_state)) actions.append(actionButton('request_start','Request sharing','phone-primary'));
    if (followStates.has(d.location_state)) actions.append(actionButton('stop','Stop sharing','phone-primary'));
    if (hasPoint(d)) {
      const follow = actionButton('follow',followId === d.id ? 'Following phone' : 'Follow phone');
      follow.setAttribute('aria-pressed',String(followId === d.id));
      actions.append(follow);
    }
    const placeAccess = actionButton('set_holder_place_access','Allow phone to add saved locations');
    placeAccess.setAttribute('aria-pressed',String(d.holder_can_save_places === true));
    placeAccess.textContent += d.holder_can_save_places ? ' · On' : ' · Off';
    actions.append(placeAccess);
    actions.append(actionButton('revoke','Disconnect','phone-destructive'));
    summary.append(actions);
    if (focused) (Array.from(actions.children).find(b => b.dataset.action === focused) || actions.firstElementChild)?.focus({preventScroll:true});
  }
  function preferencesChanged(d) {
    return Number(intervalSelect.value) !== d.update_interval_seconds || accuracySelect.value !== d.accuracy_mode;
  }
  function renderPreferences(d) {
    preferences.hidden = !d?.allow_location;
    if (preferences.hidden) {
      if (preferences.dataset.deviceId) preferences.reset();
      delete preferences.dataset.deviceId;
      delete preferences.dataset.version;
      saveSettings.disabled = true;
      settingsStatus.textContent = '';
      return;
    }
    preferences.querySelector('fieldset').disabled = d.id === savingPreferencesFor;
    const newDevice = preferences.dataset.deviceId !== d.id;
    const version = String(d.preferences_version);
    if (newDevice || preferences.dataset.version !== version) {
      const hadDraft = !newDevice && !saveSettings.disabled;
      preferences.dataset.deviceId = d.id;
      preferences.dataset.version = version;
      intervalSelect.value = String(d.update_interval_seconds);
      accuracySelect.value = d.accuracy_mode;
      settingsStatus.textContent = hadDraft ? 'Settings changed elsewhere. Review the current values before editing.' : '';
    }
    saveSettings.disabled = writing || !preferencesChanged(d);
  }
  preferences.addEventListener('change', () => {
    const d = selected();
    if (!d || d.id !== preferences.dataset.deviceId) return;
    settingsStatus.textContent = '';
    saveSettings.disabled = writing || !preferencesChanged(d);
  });
  preferences.addEventListener('submit', event => {
    event.preventDefault();
    const d = selected();
    if (!d?.allow_location || d.id !== preferences.dataset.deviceId || writing || !preferencesChanged(d)) return;
    void mutate('set_preferences',d,{
      preferences_version:Number(preferences.dataset.version),
      update_interval_seconds:Number(intervalSelect.value),
      accuracy_mode:accuracySelect.value
    });
  });
  function renderHandoffRequests(moves){
    const sig=JSON.stringify(moves.map(d=>[d.id,d.generation,d.handoff,d.name]));
    if(handoffSignature===sig)return;
    handoffSignature=sig;handoffHost.replaceChildren();
    for(const d of moves){
      const h=d.handoff,card=element('section',undefined,'phone-pending-card');
      card.dataset.handoffId=h.transfer_id;card.dataset.deviceId=d.id;
      card.append(element('h3','Move '+d.name),element('p',h.state==='approved'?'Approved. Waiting for the new app to finish.':'Compare this code with the intended phone holder before approving.'));
      card.append(element('p','Verification code: '+h.comparison_code));
      const row=element('div',undefined,'phone-command-row');
      if(h.state==='claimed')row.append(actionButton('handoff_approve','Approve move','phone-primary'));
      row.append(actionButton('handoff_owner_cancel','Cancel move'));card.append(row);handoffHost.append(card);
    }
  }
  function renderPending(pending) {
    const review = byId('phone-review');
    const moves=devices.filter(d=>d.status==='approved'&&['claimed','approved'].includes(d.handoff?.state)&&d.handoff.expires_epoch>seconds());
    review.hidden = !(pending.length+moves.length);
    review.textContent = 'Review requests ('+(pending.length+moves.length)+')';
    renderHandoffRequests(moves);
    for (const card of Array.from(pendingHost.children)) {
      if (!pending.some(d => d.id === card.dataset.deviceId)) card.remove();
    }
    for (const d of pending) {
      if (Array.from(pendingHost.children).some(card => card.dataset.deviceId === d.id)) continue;
      const card = element('section',undefined,'phone-pending-card');
      card.dataset.deviceId = d.id;
      card.append(element('h3',d.name),element('p','Wants to connect to your account.'));
      const fields = element('div',undefined,'phone-grant-fields');
      const roleLabel = element('label','Role');
      const role = element('select');
      role.name = 'role';
      for (const [value,label] of Object.entries(roleLabels)) role.add(new Option(label,value));
      role.value = Object.hasOwn(roleLabels,d.role) ? d.role : 'device';
      roleLabel.append(role);
      const locationLabel = element('label','Allow location requests');
      const location = element('input');
      location.type = 'checkbox';
      location.name = 'allow_location';
      location.checked = true;
      locationLabel.prepend(location);
      fields.append(roleLabel, locationLabel);
      const actions = element('div',undefined,'phone-command-row');
      actions.append(actionButton('approve','Approve','phone-primary'),actionButton('deny','Deny'));
      card.append(fields,actions);
      pendingHost.append(card);
    }
  }
  function render() {
    const approved = devices.filter(d => d.status === 'approved');
    if (requestNotice && !status.dataset.failure) {
      const phone = approved.find(d => d.id === requestNotice.id && d.generation === requestNotice.generation);
      if (phone && ['requested','waiting'].includes(phone.location_state)) {
        const notice = phone.location_state === 'requested' ? 'Waiting for '+phone.name+' to accept sharing.' : 'Waiting for a location update from '+phone.name+'.';
        if (status.textContent !== notice) status.textContent = notice;
      } else {
        requestNotice = null;
        status.textContent = '';
      }
    }
    // A link from private Chat refers to one specific phone, even after access changes.
    if (linkedDevice !== null) selectedId = approved.find(d => d.id === linkedDevice && (initialInformation||initialManaged||d.allow_location))?.id || '';
    else if (!approved.some(d => d.id === selectedId)) selectedId = approved[0]?.id || '';
    if (!devices.some(d => d.id === followId && hasPoint(d))) followId = '';
    camera.update(approved);
    renderList(approved);
    renderDetail(selected());
    dialerOwner?.bind(selected(),available&&!document.hidden);
    managedOwner?.bind(selected(),available&&!document.hidden);
    if(openManagedFromLink&&selected()&&(!linkedDevice||selected().id===linkedDevice)&&available){openManagedFromLink=false;managedOwner?.setMode('managed');}
    renderPending(devices.filter(d => d.status === 'pending'));
    renderMarkers();
    archive.render(approved);
  }
  function eraseInvite() {
    inviteVersion++;
    expires = 0;
    byId('phone-qr').replaceChildren();
    byId('phone-join').removeAttribute('href');
    byId('phone-join').hidden = true;
    byId('phone-install-code').value='';byId('phone-install-code-box').open=false;byId('phone-install-code-box').hidden=true;
    byId('phone-copy-install-status').textContent='';
    byId('phone-expiry').textContent = '';
    byId('phone-invite-box').hidden = true;
  }
  function updateExpiry() {
    if (!expires) return;
    const remaining = expires - seconds();
    if (remaining <= 0) {
      eraseInvite();
      byId('phone-invite-box').hidden = false;
      byId('phone-expiry').textContent = 'This code has expired.';
      byId('phone-refresh-invite').hidden = false;
    } else {
      byId('phone-expiry').textContent = 'Code expires in '+Math.floor(remaining/60)+':'+String(remaining%60).padStart(2,'0');
    }
  }
  function clearView() {
    exchange?.clear();
    revision++;
    camera.close();
    devices = [];
    selectedId = followId = '';
    lastFollowPoint = '';
    available = false;
    choiceToRevoke = null;
    archive.clear();
    if (revokeDialog.open) revokeDialog.close();
    byId('phone-revoke-message').textContent = '';
    eraseInvite();
    removeStreets();
    map?.setView([22,0],1.75,{animate:false});
    render();
  }
  async function request(action, fields) {
    const url = new URL(root.dataset.endpoint, location.href);
    const opts = {credentials:'same-origin',cache:'no-store',redirect:'error',headers:{'X-VP3-Working-Attention':root.dataset.binding, Accept:'application/json'}};
    if (['state','history','places','information_list'].includes(action)) {
      url.searchParams.set('action',action);
      for (const [key,value] of Object.entries(fields || {})) url.searchParams.set(key,value);
    }
    else {
      opts.method = 'POST';
      if(action==='information_create_file')url.searchParams.set('information_file','1');
      opts.headers['Content-Type'] = 'application/json';
      opts.body = JSON.stringify({...fields, action, csrf_token:root.dataset.csrf, request_id:crypto.randomUUID()});
    }
    const controller = new AbortController();
    const timer = setTimeout(() => controller.abort(),['managed_reconcile','managed_end','managed_pilot_dispatch'].includes(action)?50000:action==='information_create_file'||action==='information_download'?30000:10000);
    try {
      const response = await fetch(url,{...opts, signal:controller.signal});
      const data = await response.json();
      if (!response.ok || data.ok !== true) {
        const error = new Error(typeof data.error === 'string' ? data.error : 'Phone Tracking is unavailable.');
        error.status = response.status;
        throw error;
      }
      return data;
    } finally { clearTimeout(timer); }
  }
  function userError(error) {
    return error.status ? error.message : 'Connection unavailable. Phone information will refresh when the connection returns.';
  }
  function reportFailure(error) {
    resumeSelection = null;
    clearView();
    status.textContent = userError(error);
    status.dataset.failure = '1';
    if (pairing.open) {
      byId('phone-pair-status').textContent = status.textContent;
      byId('phone-invite-box').hidden = false;
      byId('phone-refresh-invite').hidden = false;
    }
  }
  async function refresh() {
    if (document.hidden || reading || writing) return;
    reading = true;
    const version = revision;
    try {
      const value = await request('state');
      if (revision !== version || document.hidden) return;
      if (!Array.isArray(value.devices) || !Number.isFinite(value.server_time)) throw new Error();
      clockDelta = value.server_time - Math.floor(Date.now()/1000);
      staleAfter = Number(value.stale_seconds) || 60;
      available = true;
      if (status.dataset.failure) { status.textContent = ''; delete status.dataset.failure; }
      devices = value.devices;
      if (resumeSelection) {
        const device = devices.find(d => d.status === 'approved' && d.id === resumeSelection.id);
        if (device) {
          selectedId = device.id;
          if (resumeSelection.follow && hasPoint(device)) followId = device.id;
        }
        resumeSelection = null;
      }
      render();
      void archive.refresh();
    } catch (error) {
      if (revision === version && !document.hidden) reportFailure(error);
    } finally { reading = false; }
  }
  async function newInvite() {
    if (writing || document.hidden || !pairing.open) return;
    writing = true;
    revision++;
    eraseInvite();
    const version = inviteVersion;
    byId('phone-pair-status').textContent = 'Creating pairing code…';
    byId('phone-refresh-invite').hidden = true;
    try {
      const result = await request('invite');
      if (version !== inviteVersion || !pairing.open || document.hidden) return;
      const url = new URL(result.phone_url, location.origin);
      if (url.origin !== location.origin || !/^#invite=[\w-]{43}$/.test(url.hash) || !result.expires_at) throw new Error();
      expires = result.expires_at;
      byId('phone-invite-box').hidden = false;
      const join = byId('phone-join');
      join.href = url.href;
      join.hidden = false;
      byId('phone-install-code').value=url.hash.slice('#invite='.length);byId('phone-install-code-box').hidden=false;
      // The local encoder returns rectangles derived from its boolean matrix only.
      try {
        byId('phone-qr').innerHTML = window.LuckyQR.svg(url.href);
        byId('phone-pair-status').textContent = 'Waiting for a phone to scan.';
      } catch {
        byId('phone-pair-status').textContent = 'QR unavailable. Open the pairing link on your phone.';
      }
      updateExpiry();
    } catch (error) {
      if (version === inviteVersion && pairing.open) reportFailure(error);
    } finally { writing = false; void refresh(); }
  }
  function openPairing(createInvite) {
    if (!pairing.open) pairing.showModal();
    byId('phone-pair-status').textContent = '';
    if (createInvite) void newInvite();
    else void refresh();
  }
  byId('phone-connect').addEventListener('click', () => openPairing(true));
  byId('phone-review').addEventListener('click', () => openPairing(false));
  byId('phone-pair-close').addEventListener('click', () => pairing.close());
  pairing.addEventListener('close', () => { eraseInvite(); byId('phone-pair-status').textContent = ''; });
  byId('phone-refresh-invite').addEventListener('click',newInvite);
  byId('phone-copy-install-code').addEventListener('click',async event=>{
    const field=byId('phone-install-code'),notice=byId('phone-copy-install-status'),version=inviteVersion;
    if(!event.isTrusted||document.hidden||!pairing.open||!byId('phone-install-code-box').open||!expires||expires<=seconds()||!/^[A-Za-z0-9_-]{43}$/.test(field.value))return;
    try{await navigator.clipboard.writeText(field.value);if(version===inviteVersion&&pairing.open)notice.textContent='Copied. Paste this code only into the intended VP3 Phone app.';}
    catch(_error){if(version===inviteVersion&&pairing.open){field.focus();field.select();notice.textContent='Select and copy the code, then paste it into VP3 Phone.';}}
  });
  function updateDevice(index, device) {
    // Mutation receipts omit camera status. Its shorter lease still comes from the last map refresh.
    devices[index] = {live_camera:devices[index].live_camera, ...device};
  }
  async function mutate(action, device, fields = {}) {
    if (!device || writing || document.hidden) return;
    writing = true;
    const version = ++revision;
    if (action !== 'set_preferences') requestNotice = null;
    const message = pairing.open ? byId('phone-pair-status') : status;
    if (action === 'set_preferences') {
      savingPreferencesFor = device.id;
      renderPreferences(device);
      settingsStatus.textContent = 'Saving settings…';
    } else message.textContent = 'Saving…';
    try {
      const agent = Number(root.dataset.chatAgent);
      const conversation = Number(root.dataset.chatConversation);
      if (['approve','request_start'].includes(action) && Number.isSafeInteger(agent) && agent >= 0 && Number.isSafeInteger(conversation) && conversation > 0) {
        fields.chat_origin = {agent_id:agent,conversation_id:conversation};
      }
      const result = await request(action, {device_id:device.id,generation:device.generation,...fields});
      if (version !== revision || document.hidden) return;
      const index = devices.findIndex(d => d.id === device.id);
      if (index >= 0) {
        if (result.device && ['approved','pending'].includes(result.device.status)) updateDevice(index,result.device);
        else devices.splice(index,1);
      }
      if (action === 'approve') { linkedDevice = null; selectedId = device.id; }
      if (action === 'request_start' && result.device) requestNotice = {id:result.device.id,generation:result.device.generation};
      if (action !== 'set_preferences') {
        const messages = {approve:device.name+' approved.',deny:'Connection request denied for '+device.name+'.',request_start:'',stop:'Sharing stopped for '+device.name+'.',revoke:device.name+' disconnected.'};
        message.textContent = status.textContent = action === 'set_holder_place_access'
          ? (result.device.holder_can_save_places ? 'Enabled' : 'Disabled')+' saved location creation for '+device.name+'.'
          : messages[action] || (action==='save_communication_grants'?'Communication permissions saved.':'Settings saved.');
      }
      delete status.dataset.failure;
      render();
      if (action === 'set_preferences' && selectedId === device.id) settingsStatus.textContent = 'Settings saved for '+device.name+'.';
      if (pairing.open && !devices.some(d => d.status === 'pending')) pairing.close();
    } catch (error) {
      if (version === revision) {
        if (action === 'set_preferences' && error.status === 409) {
          if (selectedId === device.id) settingsStatus.textContent = 'The phone changed. Review its current settings and try again.';
        } else reportFailure(error);
      }
    } finally {
      writing = false;
      savingPreferencesFor = '';
      renderPreferences(selected());
      renderCommunication(selected());
      void refresh();
    }
  }
  async function writeArchive(action, fields) {
    if (!available || document.hidden || writing) return null;
    writing = true;
    const version = ++revision;
    try {
      const result = await request(action, fields);
      if (version !== revision || document.hidden) return null;
      if (result.device && result.device.id === fields.device_id) {
        const index = devices.findIndex(d => d.id === fields.device_id);
        if (index >= 0) updateDevice(index,result.device);
      }
      return result;
    } catch (error) {
      if (version !== revision || document.hidden) return null;
      if (!error.status || [401,419].includes(error.status)) reportFailure(error);
      throw error;
    } finally {
      writing = false;
      render();
      void refresh();
    }
  }
  handoffHost.addEventListener('click',async event=>{
    const button=event.target.closest('button[data-action]'),card=button?.closest('[data-handoff-id]');
    if(!event.isTrusted||!card||writing||document.hidden||!available)return;
    const d=devices.find(d=>d.id===card.dataset.deviceId&&d.handoff?.transfer_id===card.dataset.handoffId);
    if(!d||d.handoff.expires_epoch<=seconds())return;
    const action=button.dataset.action;
    if(!['handoff_approve','handoff_owner_cancel'].includes(action))return;
    if(action==='handoff_approve'&&!window.confirm('Does the intended holder show verification code '+d.handoff.comparison_code+'? The old session will disconnect after they finish. Holder sharing preferences will reset.'))return;
    try{const result=await writeArchive(action,{device_id:d.id,generation:d.generation,transfer_id:d.handoff.transfer_id,comparison_code:d.handoff.comparison_code,confirmed:true});if(result)byId('phone-pair-status').textContent=action==='handoff_approve'?'Move approved. The holder must finish on the new app.':'Move canceled.';}
    catch(_error){byId('phone-pair-status').textContent='Move was not confirmed. Refresh the request.';}
  });
  pendingHost.addEventListener('click', event => {
    const button = event.target.closest('button[data-action]');
    if (!button) return;
    const card = button.closest('[data-device-id]');
    const device = devices.find(d => d.id === card.dataset.deviceId && d.status === 'pending');
    const fields = button.dataset.action === 'approve' ? {role:card.querySelector('select').value,allow_location:card.querySelector('input').checked} : {};
    void mutate(button.dataset.action, device, fields);
  });
  detail.addEventListener('click', event => {
    const action = event.target.closest('button[data-action]')?.dataset.action;
    const d = selected();
    if (!d || !action || writing) return;
    if (action === 'open_connected_call') {
      if(event.isTrusted&&window.VP3PhoneWorkspaceV1?.openConnectedPhone?.(d,event.target.closest('button'))!==true)status.textContent='Review the current connected phone and assigned line before calling.';
    } else if (action === 'open_stream') {
      camera.open(d,false);
    } else if (action === 'follow') {
      followId = followId === d.id ? '' : d.id;
      if (followId) focusPoint(d);
      render();
    } else if (action === 'set_holder_place_access') {
      void mutate(action,d,{holder_can_save_places:!d.holder_can_save_places,holder_place_version:d.holder_place_version});
    } else if (action === 'revoke') {
      choiceToRevoke = {id:d.id,name:d.name,generation:d.generation};
      byId('phone-revoke-message').textContent = d.name+' will stop sharing and will need a new pairing code to reconnect.';
      revokeDialog.showModal();
    } else {
      void mutate(action,d);
    }
  });
  byId('phone-revoke-cancel').addEventListener('click', () => revokeDialog.close());
  byId('phone-revoke-accept').addEventListener('click', () => {
    const target = choiceToRevoke;
    revokeDialog.close();
    if (target) void mutate('revoke', target);
  });
  revokeDialog.addEventListener('close', () => { choiceToRevoke = null; });
  function resetAndRefresh(shouldRefresh = true) {
    clearView();
    status.textContent = '';
    byId('phone-pair-status').textContent = '';
    if (pairing.open) pairing.close();
    if (streetDialog.open) streetDialog.close();
    if (shouldRefresh && !document.hidden) void refresh();
  }
  window.addEventListener('pagehide', () => { resumeSelection = null; resetAndRefresh(false); });
  window.addEventListener('pageshow', event => { if (event.persisted) resetAndRefresh(); });
  document.addEventListener('visibilitychange', () => {
    if (document.hidden && selectedId) resumeSelection = {id:selectedId, follow:followId === selectedId};
    resetAndRefresh();
  });
  window.addEventListener('offline', () => reportFailure(new Error()));
  window.addEventListener('online',refresh);
  setInterval(refresh,4000);
  setInterval(() => { if (!document.hidden) { updateExpiry(); if (available) render(); } },1000);
  if (root.dataset.connect === '1') {
    const clean = new URL(location.href);
    clean.searchParams.delete('connect');
    history.replaceState(history.state,'',clean);
    openPairing(true);
  } else void refresh();
})();
