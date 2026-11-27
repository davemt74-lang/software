window.VP3PhoneSavedPlace = function (request) {
  'use strict';
  const el = id => document.getElementById(id);
  const entry = el('phone-app-save-place');
  const phoneEntry = el('phone-save-current-place');
  const dialog = el('phone-save-place-dialog');
  const form = el('phone-save-place-form');
  const name = el('phone-save-place-name');
  const message = el('phone-save-place-status');
  const submit = el('phone-save-place-submit');
  const close = el('phone-save-place-close');
  let device = null, owner = '', online = false;
  let attempt = null, retry = null;

  function reset() {
    attempt = null;
    retry = null;
    form.reset();
    name.disabled = false;
    submit.hidden = false;
    submit.disabled = false;
    submit.textContent = 'Save current location';
    close.textContent = 'Cancel';
    close.disabled = false;
    message.textContent = '';
  }
  function bind(next, ownerName, reachable) {
    const granted = next?.status === 'approved' && next.holder_can_save_places === true;
    const changed = device?.id !== next?.id || device?.generation !== next?.generation
      || device?.holder_place_version !== next?.holder_place_version;
    device = granted ? next : null;
    owner = ownerName;
    online = reachable && navigator.onLine !== false;
    entry.hidden = !device;
    entry.disabled = !online;
    if(phoneEntry){phoneEntry.hidden=!device;phoneEntry.disabled=!online;}
    if (dialog.open && (!device || changed || !online)) dialog.close();
  }
  function open() {
    if (!device || !online || dialog.open) return;
    reset();
    el('phone-save-place-recipient').textContent = 'Save this spot once to '+owner+'’s saved locations.';
    dialog.showModal();
    name.focus();
  }
  phoneEntry?.addEventListener('click',event=>{if(event.isTrusted)open();});
  close.addEventListener('click',() => dialog.close());
  dialog.addEventListener('close',reset);
  dialog.addEventListener('cancel',event => { if (close.disabled) event.preventDefault(); });

  form.addEventListener('submit',async event => {
    event.preventDefault();
    if (!device || !online || !dialog.open || attempt) return;
    const label = name.value.trim();
    if (!label) { name.focus(); return; }
    const current = {};
    attempt = current;
    name.disabled = true;
    submit.disabled = true;
    try {
      if (!retry) {
        if (!window.isSecureContext || !navigator.geolocation) {
          message.textContent = 'Open this page over HTTPS in a browser with location access.';
          return;
        }
        message.textContent = 'Getting your current location…';
        const {id,generation,holder_place_version} = device;
        let position;
        try {
          position = await new Promise((resolve,reject) => navigator.geolocation.getCurrentPosition(resolve,reject,
            {enableHighAccuracy:true,maximumAge:0,timeout:20000}));
        } catch (error) {
          if (attempt === current) message.textContent = error?.code === 1
            ? 'Location access was denied. Check location access for this site and browser before trying again.'
            : 'Could not get a fresh location. Try again.';
          return;
        }
        if (attempt !== current || !dialog.open || !device || !online) return;
        const {latitude,longitude,accuracy} = position.coords;
        retry = {device_id:id,generation,holder_place_version,request_id:crypto.randomUUID(),name:label,
          latitude,longitude,accuracy,fix_at:Math.floor(position.timestamp/1000)};
      }
      close.disabled = true;
      message.textContent = 'Saving location…';
      const result = await request('holder_save_place',retry);
      if (attempt !== current || !dialog.open) return;
      message.textContent = 'Saved “'+result.saved_place.name+'” to '+owner+'’s saved locations.';
      retry = null;
      submit.hidden = true;
      close.textContent = 'Done';
    } catch (error) {
      if (attempt !== current || !dialog.open) return;
      if ([401,403,404].includes(error.status)) {
        retry = null;
        message.textContent = 'This phone no longer has permission to save locations.';
        submit.hidden = true;
      } else if ([409,422].includes(error.status)) {
        retry = null;
        message.textContent = error.status === 409
          ? 'The name may already be in use or the phone permission changed. Check the name and try again.'
          : 'Check the location name and get a fresh reading.';
      } else {
        message.textContent = 'The save could not be confirmed. Retry to check this location.';
        submit.textContent = 'Retry save';
      }
    } finally {
      if (attempt === current) {
        attempt = null;
        close.disabled = false;
        submit.disabled = false;
        name.disabled = Boolean(retry) || submit.hidden;
      }
    }
  });
  return {bind,open};
};
