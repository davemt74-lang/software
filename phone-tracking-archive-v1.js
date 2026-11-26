'use strict';

window.vp3PhoneArchiveV1 = function ({
  map, leaflet, byId, element, seconds, timestamp, request, selectDevice, selected,
  available, writing, effectiveState, stopFollowing, write, onReadDenied
}) {
  const panelNames = ['devices','history','places'];
  const tabs = panelNames.map(name => byId('phone-tab-'+name));
  const panels = panelNames.map(name => byId('phone-panel-'+name));
  const historyDevice = byId('phone-history-device');
  const placeDevice = byId('phone-place-device');
  const duration = byId('phone-history-retention');
  const placeName = byId('phone-place-name');
  const placesList = byId('phone-places-list');
  const historyList = byId('phone-history-points');
  const dialog = byId('phone-archive-dialog');
  const layers = map && leaflet.layerGroup().addTo(map);
  const trailRenderer = map && leaflet.svg();
  const initialPanel = new URLSearchParams(location.search).get('panel');
  let panel = panelNames.includes(initialPanel) ? initialPanel : 'devices';
  let generation = 0;
  let readInFlight = null;
  let loaded = false;
  let points = [], places = [];
  let olderThan = null, nextBefore = null;
  let selectedKey = '', historyKey = '', deviceOptions = '', displayKey = '';
  let errorMessage = '', deleteTarget = null;
  let fitting = true;
  let busy = false;

  function syncTabs() {
    panelNames.forEach((name,index) => {
      const active = name === panel;
      tabs[index].setAttribute('aria-selected',String(active));
      tabs[index].tabIndex = active ? 0 : -1;
      panels[index].hidden = !active;
    });
  }
  function clearDialog() {
    deleteTarget = null;
    if (dialog.open) dialog.close();
    byId('phone-archive-dialog-title').textContent = '';
    byId('phone-archive-dialog-message').textContent = '';
  }
  function clearRead() {
    generation++;
    readInFlight = null;
    loaded = false;
    errorMessage = '';
    points = [];
    places = [];
    nextBefore = null;
    displayKey = '';
    layers?.clearLayers();
    historyList.replaceChildren();
    placesList.replaceChildren();
    byId('phone-history-count').textContent = '';
    byId('phone-history-range').textContent = '';
    for (const name of ['fit','earlier','latest','delete']) byId('phone-history-'+name).hidden = true;
  }
  function clear() {
    clearRead();
    clearDialog();
    selectedKey = historyKey = deviceOptions = '';
    olderThan = null;
    fitting = true;
    historyDevice.replaceChildren();
    placeDevice.replaceChildren();
    duration.value = '1';
    placeName.value = '';
    for (const id of ['phone-history-status','phone-history-feedback','phone-place-source','phone-place-feedback']) byId(id).textContent = '';
  }
  function openPanel(name) {
    if (name === panel) return;
    panel = name;
    clear();
    syncTabs();
    // Update the controls before starting a scoped read.
    refreshUI();
    if (panel !== 'devices') void refresh();
  }
  tabs.forEach((tab,index) => {
    tab.addEventListener('click',() => openPanel(panelNames[index]));
    tab.addEventListener('keydown',event => {
      const target = {ArrowLeft:(index+2)%3,ArrowRight:(index+1)%3,Home:0,End:2}[event.key];
      if (target === undefined) return;
      event.preventDefault();
      tabs[target].focus();
      openPanel(panelNames[target]);
    });
  });
  for (const select of [historyDevice,placeDevice]) {
    select.addEventListener('change',() => selectDevice(select.value));
  }

  let approvedPhones = [];
  function refreshUI() { render(approvedPhones); }
  function render(approved) {
    approvedPhones = approved;
    if (panel === 'devices' || !available()) return;
    const d = selected();
    const choices = JSON.stringify([Boolean(d),approved.map(phone => [phone.id,phone.name])]);
    if (choices !== deviceOptions) {
      for (const select of [historyDevice,placeDevice]) {
        select.replaceChildren(...approved.map(phone => new Option(phone.name,phone.id)));
        if (!d) {
          const prompt = new Option(approved.length ? 'Choose a phone' : 'No connected phones','');
          prompt.disabled = true;
          select.add(prompt);
        }
        select.disabled = !approved.length;
      }
      deviceOptions = choices;
    }
    historyDevice.value = placeDevice.value = d?.id || '';
    const key = d ? d.id+'/'+d.generation : '';
    if (key !== selectedKey) {
      selectedKey = key;
      placeName.value = '';
      byId('phone-place-feedback').textContent = '';
    }
    if (panel === 'history') {
      const current = key+'/'+(d?.history_version || 0);
      if (historyKey !== current) {
        clearRead();
        clearDialog();
        olderThan = null;
        fitting = true;
        duration.value = String(d?.history_retention_days || 1);
        byId('phone-history-feedback').textContent = '';
        historyKey = current;
        if (d?.allow_location) void refresh();
      }
      const state = d?.history_state;
      const active = state === 'active', pending = state === 'requested';
      const scoped = Boolean(available() && d?.allow_location);
      const sharing = d && ['waiting','live','stale'].includes(effectiveState(d));
      byId('phone-history-form').hidden = !scoped;
      duration.disabled = pending || active || busy || writing();
      byId('phone-history-request').hidden = pending || active;
      byId('phone-history-request').disabled = !scoped || !sharing || busy || writing();
      const stop = byId('phone-history-stop');
      stop.hidden = !(active || pending);
      stop.disabled = busy || writing();
      stop.textContent = pending ? 'Cancel request' : 'Stop history';
      byId('phone-history-status').textContent = !d ? (approved.length ? 'The linked phone is unavailable. Choose a phone to view its history.' : 'Connect a phone to view its history.') :
        !d.allow_location ? 'Location access is disabled for this phone.' :
        pending ? 'Waiting for '+d.name+' to approve history.' :
        active ? 'History enabled for '+d.name+'. Each point is kept for '+formatDays(d.history_retention_days)+'.' :
        !sharing ? 'Start live sharing on '+d.name+' before requesting history.' :
        'History is off for '+d.name+'. The phone holder chooses whether to keep a trail.';
      renderTrail();
    } else {
      const allowed = canSave(d);
      placeName.disabled = !allowed || busy || writing();
      byId('phone-place-save').disabled = !allowed || !placeName.value.trim() || busy || writing();
      byId('phone-place-source').textContent = !d ? (approved.length ? 'Choose a phone to save its current location.' : 'Connect a phone to save its current location.') :
        effectiveState(d) !== 'live' ? 'A fresh live location from '+d.name+' is required.' :
        !d.allow_saved_places ? 'The holder of '+d.name+' must allow saved places on the phone.' :
        'Save the current location from '+d.name+' to this account.';
      renderPlaces();
    }
  }
  function formatDays(n) { return n+' '+(n === 1 ? 'day' : 'days'); }
  function canSave(d) {
    return Boolean(available() && d?.allow_location && d.allow_saved_places && effectiveState(d) === 'live');
  }
  function pointCoords(p) { return [p.latitude,p.longitude]; }
  function fitLocations(items) {
    if (!map || !items.length) return;
    stopFollowing();
    map.fitBounds(items.map(pointCoords),{padding:[42,42],maxZoom:15,animate:false});
  }
  function drawTrail(data) {
    if (!layers) return;
    layers.clearLayers();
    let line = [];
    function finishLine() {
      if (line.length > 1) leaflet.polyline(line,{renderer:trailRenderer,className:'phone-history-trail',color:'#7655a3',weight:4,interactive:false}).addTo(layers);
      // Give even a single stationary sample a visible marker.
      if (line.length) leaflet.circleMarker(line[line.length-1],{renderer:trailRenderer,className:'phone-history-trail',radius:5,color:'#654591',fillColor:'#fff',fillOpacity:1,weight:2,interactive:false}).addTo(layers);
      line = [];
    }
    for (let i=0;i<data.length;i++) {
      const p=data[i], previous=data[i-1];
      if (previous && (previous.generation !== p.generation || previous.history_version !== p.history_version ||
          p.fix_at-previous.fix_at > 60 || Math.abs(p.longitude-previous.longitude)>180)) finishLine();
      line.push(pointCoords(p));
    }
    finishLine();
  }
  function renderTrail() {
    points = points.filter(p => p.expires_at > seconds());
    const key = JSON.stringify([loaded,errorMessage,olderThan,nextBefore,points]);
    byId('phone-history-delete').disabled = busy || writing();
    if (displayKey === key) return;
    displayKey = key;
    const count = points.length;
    const latest = count ? points[count-1] : null;
    byId('phone-history-count').textContent = loaded ? count+' '+(count === 1 ? 'point' : 'points') : '';
    byId('phone-history-range').textContent = errorMessage || (!selected()?.allow_location ? '' : !loaded ? 'Loading trail…' :
      latest ? timestamp(points[0].fix_at)+' – '+timestamp(latest.fix_at) : 'No retained points in this view.');
    historyList.replaceChildren();
    for (const p of points.slice(-5).reverse()) {
      const row = element('li');
      row.dataset.fixAt = String(p.fix_at);
      row.append(element('time',timestamp(p.fix_at)),element('span',p.latitude.toFixed(5)+', '+p.longitude.toFixed(5)));
      historyList.append(row);
    }
    byId('phone-history-fit').hidden = !map || !count;
    byId('phone-history-delete').hidden = !count && !nextBefore && selected()?.history_state !== 'active';
    byId('phone-history-earlier').hidden = !nextBefore;
    byId('phone-history-latest').hidden = !olderThan;
    drawTrail(points);
    if (fitting && loaded && points.length) { fitting = false; fitLocations(points); }
  }
  function renderPlaces() {
    const key = JSON.stringify([loaded,errorMessage,places]);
    if (displayKey === key) return;
    displayKey = key;
    const focused = document.activeElement;
    const focusId = placesList.contains(focused) ? focused.dataset.showPlace || focused.dataset.deletePlace : '';
    const focusShow = focused?.hasAttribute('data-show-place');
    placesList.replaceChildren();
    layers?.clearLayers();
    for (const p of places) {
      const row = element('li'), label = element('div'), actions = element('div',undefined,'phone-place-actions');
      row.dataset.placeId = p.id;
      label.append(element('strong',p.name),element('small',p.latitude.toFixed(5)+', '+p.longitude.toFixed(5)));
      const show = element('button','Show on map'), remove = element('button','Delete','phone-destructive');
      show.type = remove.type = 'button';
      show.dataset.showPlace = remove.dataset.deletePlace = p.id;
      show.disabled = !map;
      show.setAttribute('aria-label','Show '+p.name+' on map');
      remove.setAttribute('aria-label','Delete '+p.name);
      actions.append(show,remove); row.append(label,actions); placesList.append(row);
      if (focusId === p.id) (focusShow ? show : remove).focus({preventScroll:true});
      if (layers) leaflet.marker(pointCoords(p),{
        title:p.name,alt:p.name,icon:leaflet.divIcon({className:'phone-place-marker',iconSize:[14,14],iconAnchor:[7,7]})
      }).bindTooltip(element('span',p.name)).addTo(layers);
    }
    if (!places.length) placesList.append(element('li',errorMessage || (loaded ? 'No saved places yet.' : 'Loading saved places…'),'phone-archive-empty'));
    if (fitting && loaded && places.length) { fitting = false; fitLocations(places); }
  }
  async function refresh() {
    if (!available() || busy || readInFlight || panel === 'devices' || (panel === 'history' && !selected()?.allow_location)) return;
    const which = panel, token = {}, scope = generation;
    readInFlight = token;
    try {
      const fields = which === 'history' ? {device_id:selected().id,...(olderThan ? {before:olderThan} : {})} : {};
      const result = await request(which,fields);
      if (scope !== generation || readInFlight !== token || !available()) return;
      if (which === 'history') {
        if (!Array.isArray(result.points)) throw new Error();
        points = result.points;
        nextBefore = result.next_before || null;
      } else {
        if (!Array.isArray(result.places)) throw new Error();
        places = result.places;
      }
      loaded = true;
      errorMessage = '';
    } catch (error) {
      if (scope !== generation || !available()) return;
      if ([401,403,419].includes(error.status)) { onReadDenied(error); return; }
      clearRead();
      errorMessage = 'Could not load '+(which === 'history' ? 'the trail.' : 'saved places.');
    } finally {
      if (token === readInFlight) readInFlight = null;
      if (available()) refreshUI();
    }
  }
  function loadPage(before) {
    clearRead();
    olderThan = before;
    fitting = true;
    refreshUI();
    void refresh();
  }
  byId('phone-history-earlier').addEventListener('click',() => { if (nextBefore) loadPage(nextBefore); });
  byId('phone-history-latest').addEventListener('click',() => loadPage(null));
  byId('phone-history-fit').addEventListener('click',() => fitLocations(points.filter(p => p.expires_at > seconds())));
  function deviceFields(d) { return {device_id:d.id,generation:d.generation,history_version:d.history_version}; }
  async function change(action,fields,message) {
    if (!available() || busy || writing()) return;
    busy = true;
    const which = panel, id = selected()?.id, scope = generation;
    const feedback = byId(which === 'history' ? 'phone-history-feedback' : 'phone-place-feedback');
    feedback.textContent = '';
    refreshUI();
    try {
      const result = await write(action,fields);
      if (!result || !available() || which !== panel || id !== selected()?.id) return;
      if (action === 'save_place') placeName.value = '';
      if (action === 'delete_history') olderThan = null;
      clearRead();
      feedback.textContent = message;
    } catch (error) {
      if (scope === generation && which === panel && id === selected()?.id && available()) {
        feedback.textContent = error.message || 'The change could not be saved.';
      }
    } finally {
      busy = false;
      refreshUI();
      void refresh();
    }
  }
  byId('phone-history-form').addEventListener('submit',event => {
    event.preventDefault();
    const d = selected();
    if (d?.allow_location && d.history_state === 'off' && ['waiting','live','stale'].includes(effectiveState(d))) {
      void change('request_history',{...deviceFields(d),retention_days:Number(duration.value)},'History request sent to '+d.name+'.');
    }
  });
  byId('phone-history-stop').addEventListener('click',() => {
    const d = selected();
    if (d && ['active','requested'].includes(d.history_state)) void change('stop_history',deviceFields(d),'History stopped for '+d.name+'.');
  });
  placeName.addEventListener('input',refreshUI);
  byId('phone-place-form').addEventListener('submit',event => {
    event.preventDefault();
    const d = selected(), name = placeName.value.trim();
    if (name && canSave(d)) void change('save_place',{device_id:d.id,generation:d.generation,name},'Saved '+name+'.');
  });
  function confirmDelete(target,title,description) {
    if (busy || writing() || !available()) return;
    deleteTarget = target;
    byId('phone-archive-dialog-title').textContent = title;
    byId('phone-archive-dialog-message').textContent = description;
    dialog.showModal();
  }
  byId('phone-history-delete').addEventListener('click',() => {
    const d = selected();
    if (d) confirmDelete({action:'delete_history',fields:deviceFields(d),message:'History deleted for '+d.name+'.'},
      'Delete history for '+d.name+'?', 'This erases the entire retained trail and stops history collection. Your saved places are kept.');
  });
  placesList.addEventListener('click',event => {
    const button = event.target.closest('button');
    if (!button) return;
    const p = places.find(item => item.id === (button.dataset.showPlace || button.dataset.deletePlace));
    if (!p) return;
    if (button.dataset.showPlace) {
      fitLocations([p]);
      byId('phone-map').scrollIntoView({block:'center'});
    }
    else confirmDelete({action:'delete_place',fields:{place_id:p.id},message:'Deleted '+p.name+'.'},
      'Delete '+p.name+'?', 'This removes the saved place from your account.');
  });
  byId('phone-archive-cancel').addEventListener('click',clearDialog);
  byId('phone-archive-confirm').addEventListener('click',() => {
    const target = deleteTarget;
    clearDialog();
    if (target) void change(target.action,target.fields,target.message);
  });
  dialog.addEventListener('close',() => { if (!dialog.open) deleteTarget = null; });
  syncTabs();
  return {render,refresh,clear,openPanel,hasMapData:() => Boolean(points.length || places.length)};
};
