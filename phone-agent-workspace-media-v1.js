(() => {
  'use strict';
  if (window.VP3PhoneAgentMediaV1) return;
  const hex32 = /^[a-f0-9]{32}$/;
  const hex64 = /^[a-f0-9]{64}$/;
  const nonce = () => Array.from(crypto.getRandomValues(new Uint8Array(16)),b => b.toString(16).padStart(2,'0')).join('');
  function open({host,item,scope,signal,onChanged,onBack}) {
    if (!(host instanceof HTMLElement) || !hex32.test(item?.item_ref || '') || !hex64.test(scope?.scope_revision || '')
      || !hex32.test(scope?.device_id || '') || !['gmail_draft','gmail_attention','image','canvas'].includes(item.source_kind)) {
      throw new Error('Reopen this item from your current Agent workspace.');
    }
    const endpoint = document.getElementById('phone-location')?.dataset.endpoint;
    const url = new URL(endpoint || 'api/phone-location-v1.php',location.href);
    if (url.origin !== location.origin || !url.pathname.endsWith('/api/phone-location-v1.php')) throw new Error('The private item view is unavailable.');
    const channel = nonce();
    url.search = new URLSearchParams({workspace_view:'1',device_id:scope.device_id,item_ref:item.item_ref,
      scope_revision:scope.scope_revision,source_revision:item.source_revision,channel}).toString();
    const frame = document.createElement('iframe');
    frame.className = 'phone-agent-native-frame';frame.title = item.title || 'Private Agent item';
    frame.referrerPolicy = 'no-referrer';frame.src = url.href;
    const loading = document.createElement('p');loading.className = 'phone-agent-native-status';
    loading.setAttribute('role','status');loading.textContent = 'Opening your private item…';
    host.classList.add('phone-agent-native-host');host.replaceChildren(loading,frame);
    let closed = false,ready = false,dirty = false,sequence = 0,closePending = null;
    const pending = new Map();
    const timer = setTimeout(() => {if (!ready && !closed) loading.textContent = 'This item is taking longer to open. Go back and try again.';},15000);
    function receive(event) {
      if (closed || event.source !== frame.contentWindow || event.origin !== location.origin || event.data?.channel !== channel) return;
      const data = event.data;
      if (data.type === 'ready') {ready = true;loading.remove();clearTimeout(timer);}
      else if (data.type === 'changed') {void onChanged?.();}
      else if (data.type === 'dirty') {dirty = data.value === true;}
      else if (data.type === 'back') {void onBack?.();}
      else if (data.type === 'close_result' && Number.isSafeInteger(data.id)) {
        const waiting = pending.get(data.id);if (!waiting) return;
        clearTimeout(waiting.timer);pending.delete(data.id);waiting.resolve(data.allowed === true);
      }
    }
    window.addEventListener('message',receive);
    function canClose() {
      if (closed || !ready) return Promise.resolve(true);
      if (closePending) return closePending;
      const id = ++sequence;
      closePending = new Promise(resolve => {
        const waiting = {resolve,timer:setTimeout(() => {pending.delete(id);resolve(false);},300000)};
        pending.set(id,waiting);
        frame.contentWindow?.postMessage({channel,type:'request_close',id},location.origin);
      }).finally(() => {closePending = null;});
      return closePending;
    }
    function close() {
      if (closed) return;closed = true;clearTimeout(timer);
      window.removeEventListener('message',receive);signal?.removeEventListener('abort',close);
      for (const waiting of pending.values()) {clearTimeout(waiting.timer);waiting.resolve(false);}pending.clear();
      frame.remove();loading.remove();host.classList.remove('phone-agent-native-host');
    }
    signal?.addEventListener('abort',close,{once:true});if (signal?.aborted) close();
    return Object.freeze({ownsScroll:true,canClose,requestClose:canClose,close,isDirty:() => dirty,
      updateItem(next) {if (!closed && next?.item_ref === item.item_ref) item = next;}});
  }
  window.VP3PhoneAgentMediaV1 = Object.freeze({open});
})();
