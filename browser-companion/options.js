const baseUrl = document.getElementById('baseUrl');
const saveBaseUrl = document.getElementById('saveBaseUrl');
const connectionSummary = document.getElementById('connectionSummary');
const extensionOrigin = document.getElementById('extensionOrigin');
const disconnectBtn = document.getElementById('disconnectBtn');
const notice = document.getElementById('notice');
const connectionError = document.getElementById('connectionError');
const diagnoseBtn = document.getElementById('diagnoseBtn');
const diagnosticResult = document.getElementById('diagnosticResult');
const resetLocalBtn = document.getElementById('resetLocalBtn');

function message(type, data = {}) {
  return new Promise((resolve, reject) => {
    chrome.runtime.sendMessage({ type, ...data }, (response) => {
      if (chrome.runtime.lastError) return reject(new Error(chrome.runtime.lastError.message));
      if (!response?.ok) return reject(new Error(response?.error || 'Browser Companion request failed.'));
      resolve(response.value);
    });
  });
}

function notify(text, kind = '') {
  notice.textContent = text;
  notice.className = `notice ${kind}`.trim();
  notice.hidden = false;
  clearTimeout(notify.timer);
  notify.timer = setTimeout(() => { notice.hidden = true; }, 4500);
}

async function render() {
  const state = await message('state');
  baseUrl.value = state.base_url || 'https://vp3.me';
  connectionSummary.textContent = state.connected
    ? `Connected${state.user?.display_name ? ` as ${state.user.display_name}` : ''}`
    : 'Not connected';
  disconnectBtn.hidden = !state.connected;
  resetLocalBtn.hidden=state.connected||!state.local_paired;
  connectionError.hidden=!state.connection_error;
  connectionError.textContent=state.connection_error?.message||'';
  extensionOrigin.textContent = `Extension origin: chrome-extension://${chrome.runtime.id}`;
}

diagnoseBtn.addEventListener('click',async()=>{
  diagnoseBtn.disabled=true;diagnosticResult.hidden=false;diagnosticResult.textContent='Checking installed integration…';
  try{const report=await message('integration_diagnostics');diagnosticResult.textContent=JSON.stringify(report,null,2);}
  catch(error){diagnosticResult.textContent=error.message;await render();}
  finally{diagnoseBtn.disabled=false;}
});

resetLocalBtn.addEventListener('click',async()=>{
  if(!confirm('Forget this browser’s local pairing so you can correct the Cloud URL and reconnect? This does not revoke the old device authorization on Cloud.'))return;
  resetLocalBtn.disabled=true;
  try{await message('reset_local_connection',{confirmed:true});diagnosticResult.textContent='';diagnosticResult.hidden=true;notify('Local pairing cleared. Correct the VP3 site, then reconnect.','success');await render();}
  catch(error){notify(error.message,'error');}
  finally{resetLocalBtn.disabled=false;}
});

saveBaseUrl.addEventListener('click', async () => {
  saveBaseUrl.disabled = true;
  try {
    const result = await message('set_base_url', { base_url: baseUrl.value });
    baseUrl.value = result.base_url;
    notify('VP3 site saved.', 'success');
  } catch (error) { notify(error.message, 'error'); }
  finally { saveBaseUrl.disabled = false; }
});

disconnectBtn.addEventListener('click', async () => {
  if (!confirm('Disconnect and revoke this browser from VP3?')) return;
  disconnectBtn.disabled = true;
  try {
    await message('disconnect');
    notify('This browser has been disconnected from VP3.', 'success');
    await render();
  } catch (error) { notify(error.message, 'error'); }
  finally { disconnectBtn.disabled = false; }
});

render().catch(error => notify(error.message, 'error'));
