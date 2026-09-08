(function(){
  'use strict';
  const button=document.getElementById('vp3HomeServerStatus');
  const modal=document.getElementById('vp3HomeServerModal');
  if(!button||!modal)return;
  const api=String(button.dataset.statusUrl||'');
  const csrf=String(button.dataset.csrf||'');
  const closeButton=document.getElementById('vp3HomeServerClose');
  const backdrop=modal.querySelector('[data-homeserver-close]');
  const claimForm=document.getElementById('vp3HomeServerClaimForm');
  const refreshButton=document.getElementById('vp3HomeServerRefresh');
  const checkButton=document.getElementById('vp3HomeServerCheckPairing');
  const disconnectButton=document.getElementById('vp3HomeServerDisconnect');
  const updateButton=document.getElementById('vp3HomeServerDownload');
  let status=null;
  let busy=false;
  let previousOverflow='';
  let previousFocus=null;

  const text=(id,value)=>{const el=document.getElementById(id);if(el)el.textContent=value==null||value===''?'—':String(value)};
  const show=(id,visible)=>{const el=document.getElementById(id);if(el)el.hidden=!visible};
  const fmtDate=value=>{if(!value)return 'Never';const normalized=String(value).includes('T')?String(value):String(value).replace(' ','T');const d=new Date(normalized);return Number.isNaN(d.getTime())?String(value):d.toLocaleString()};
  const focusable=()=>[...modal.querySelectorAll('a[href],button:not([disabled]),input:not([disabled]),select:not([disabled]),textarea:not([disabled]),[tabindex]:not([tabindex="-1"])')].filter(el=>!el.hidden&&el.offsetParent!==null);
  const setBusy=value=>{busy=Boolean(value);[refreshButton,checkButton,disconnectButton,claimForm&&claimForm.querySelector('button[type="submit"]')].filter(Boolean).forEach(el=>{el.disabled=busy;el.setAttribute('aria-busy',busy?'true':'false')})};

  function render(data){
    status=data||{};
    const connected=Boolean(status.connected);
    const update=Boolean(status.update_available);
    const state=update?'update':connected?'connected':status.state==='error'?'error':status.state==='unpaired'?'unpaired':'offline';
    const buttonLabel=update?'HomeServer connected, update available':connected?'HomeServer connected':status.state==='awaiting_approval'?'HomeServer approval required':status.state==='unpaired'?'Connect HomeServer':'HomeServer offline';
    button.dataset.state=state;
    button.title=buttonLabel;
    button.setAttribute('aria-label',buttonLabel);
    const summaryTitle=update?'Connected · Update available':connected?'Connected':status.state==='awaiting_approval'?'Approval required':status.state==='unpaired'?'Not connected':status.state==='error'?'Connection error':'Offline';
    text('vp3HomeServerSummaryTitle',summaryTitle);
    text('vp3HomeServerSummaryDetail',connected?'Remote Bridge is online and VP3 can reach this HomeServer.':(status.error||'Connect HomeServer to use your private Agent Brain from VP3.'));
    const summary=document.getElementById('vp3HomeServerSummary');if(summary)summary.dataset.state=state;
    text('vp3HomeServerRelay',status.relay_configured?(connected?'Connected':'Configured'):'Not configured');
    text('vp3HomeServerLastSeen',fmtDate(status.last_seen_at));
    text('vp3HomeServerInstalled',status.installed_version?`v${status.installed_version}`:'Unknown');
    const latest=status.latest_release||null;
    text('vp3HomeServerLatest',latest&&latest.version?`v${latest.version}`:'No published release');
    text('vp3HomeServerUpdate',update?'Update available':(status.installed_version&&latest?'Up to date':'Unknown'));
    text('vp3HomeServerBrain',status.agent_brain_ready?'Ready':(connected&&status.paired?'Inference unavailable':'Not paired'));
    const inference=status.inference||{};
    text('vp3HomeServerCompute',inference.compute_source||'—');
    text('vp3HomeServerProvider',inference.selected_provider||'—');
    text('vp3HomeServerModel',inference.model||'—');
    const caps=document.getElementById('vp3HomeServerCapabilities');
    if(caps){caps.replaceChildren();(Array.isArray(status.capabilities)?status.capabilities:[]).slice(0,40).forEach(cap=>{const span=document.createElement('span');span.className='vp3-homeserver-capability';span.textContent=String(cap);caps.appendChild(span)});if(!caps.children.length){const span=document.createElement('span');span.className='vp3-homeserver-capability';span.textContent='No capabilities reported';caps.appendChild(span)}}
    show('vp3HomeServerError',Boolean(status.error));text('vp3HomeServerError',status.error||'');
    show('vp3HomeServerConnectPanel',!status.paired);
    const pairing=status.pairing||null;
    show('vp3HomeServerApprovalPanel',Boolean(pairing&&pairing.approval_code));
    if(pairing&&pairing.approval_code)text('vp3HomeServerApprovalCode',pairing.approval_code);
    show('vp3HomeServerClaimForm',!(pairing&&pairing.approval_code));
    if(updateButton){const file=latest&&(latest.installer||latest.portable);updateButton.hidden=!file;updateButton.href=file&&file.url?file.url:'#';updateButton.textContent=update?'Download Update':'Download HomeServer'}
    if(disconnectButton)disconnectButton.hidden=status.state==='unpaired';
  }

  async function parse(response){let data={};try{data=await response.json()}catch(_){throw new Error('VP3 returned an invalid HomeServer response.')}if(!response.ok||data.ok===false)throw Object.assign(new Error(data.error||`HomeServer request failed (${response.status})`),{data});return data}
  async function load(force){
    if(busy||!api)return;setBusy(true);
    try{const response=await fetch(api+(force?'?refresh=1':''),{credentials:'same-origin',cache:'no-store',headers:{Accept:'application/json'}});const data=await parse(response);render(data.status)}catch(error){if(error&&error.data&&error.data.status)render(error.data.status);button.dataset.state='error';button.title=error&&error.message?error.message:'HomeServer status unavailable';button.setAttribute('aria-label','HomeServer status unavailable')}finally{setBusy(false)}
  }
  async function action(name,extra){
    if(busy)return;setBusy(true);
    try{const body=new URLSearchParams({action:name,csrf_token:csrf,...(extra||{})});const response=await fetch(api,{method:'POST',credentials:'same-origin',cache:'no-store',headers:{Accept:'application/json','Content-Type':'application/x-www-form-urlencoded;charset=UTF-8'},body});const data=await parse(response);render(data.status);return data}catch(error){if(error&&error.data&&error.data.status)render(error.data.status);const el=document.getElementById('vp3HomeServerError');if(el){el.hidden=false;el.textContent=error&&error.message?error.message:'HomeServer request failed.'}throw error}finally{setBusy(false)}
  }
  function open(){previousFocus=document.activeElement;previousOverflow=document.documentElement.style.overflow;modal.hidden=false;document.documentElement.style.overflow='hidden';closeButton&&closeButton.focus();load(true)}
  function close(){modal.hidden=true;document.documentElement.style.overflow=previousOverflow;if(previousFocus&&typeof previousFocus.focus==='function')previousFocus.focus();else button.focus()}
  button.addEventListener('click',open);closeButton&&closeButton.addEventListener('click',close);backdrop&&backdrop.addEventListener('click',close);
  document.addEventListener('keydown',event=>{
    if(modal.hidden)return;
    if(event.key==='Escape'){event.preventDefault();close();return}
    if(event.key!=='Tab')return;
    const items=focusable();if(!items.length){event.preventDefault();return}
    const first=items[0],last=items[items.length-1];
    if(event.shiftKey&&document.activeElement===first){event.preventDefault();last.focus()}else if(!event.shiftKey&&document.activeElement===last){event.preventDefault();first.focus()}
  });
  refreshButton&&refreshButton.addEventListener('click',()=>load(true));
  checkButton&&checkButton.addEventListener('click',async()=>{try{await action('check_pairing')}catch(_){}});
  disconnectButton&&disconnectButton.addEventListener('click',async()=>{if(!confirm('Disconnect this HomeServer from VP3 on this account? The VP3 app permission can also be revoked from HomeServer.'))return;try{await action('disconnect')}catch(_){}});
  claimForm&&claimForm.addEventListener('submit',async event=>{event.preventDefault();const input=claimForm.querySelector('input[name="claim_code"]');const code=input?String(input.value||'').trim():'';if(!code)return;try{await action('claim',{claim_code:code});if(input)input.value=''}catch(_){}});
  load(false);
  window.setInterval(()=>{if(document.visibilityState==='visible'&&modal.hidden)load(false)},60000);
})();
