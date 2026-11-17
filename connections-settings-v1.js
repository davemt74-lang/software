(() => {
  'use strict';

  const {vp3SpecialistSettings, connectionSettingsOwner, connectionSettingsChanged} = document.body.dataset;
  if (!/^[1-9]\d*$/.test(connectionSettingsOwner || '') || typeof BroadcastChannel !== 'function') return;
  const channel = new BroadcastChannel(`vp3-gmail-connection:${connectionSettingsOwner}`);
  if (connectionSettingsChanged === 'gmail') {
    channel.postMessage('changed');
    channel.close();
    return;
  }

  if (vp3SpecialistSettings !== 'gmail') {
    channel.close();
    return;
  }
  let connecting = false;
  document.addEventListener('submit', event => {
    if (event.submitter?.formTarget === '_blank' && ['start', 'prepare'].includes(event.submitter.value)) connecting = true;
  });
  channel.addEventListener('message', event => {
    if (connecting && event.data === 'changed') location.reload();
  });
  window.addEventListener('pagehide', () => channel.close(), {once:true});
})();
