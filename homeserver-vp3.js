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
  const policySummary=document.getElementById('vp3HomeServerPolicySummary');
  const policyList=document.getElementById('vp3HomeServerPolicies');
  let status=null;
  let busy=false;
  let previousOverflow='';
  let previousFocus=null;

  const policyLabels={
    read_only:'Read only',
    safe_automatic:'Safe automatic',
    approval_required:'Approval required',
    sensitive_high_impact:'Sensitive / high-impact'
  };
  const policyReasons={
    unpaired:'Pair HomeServer to view this VP3 app’s effective tool permissions.',
    offline:'HomeServer is offline. The last known policy is not cached in VP3.',
    unsupported:'Update HomeServer to a build with Agent action-policy support.',
    credentials_unavailable:'Re-pair HomeServer to restore policy visibility.',
    remote_unavailable:'HomeServer policy could not be read through the Remote Bridge.',
    invalid_response:'HomeServer returned an unsupported policy response.'
  };

  const text=(id,value)=>{const el=document.getElementById(id);if(el)el.textContent=value==null||value===''?'—':String(value)};
  const show=(id,visible)=>{const el=document.getElementById(id);if(el)el.hidden=!visible};
  const fmtDate=value=>{if(!value)return 'Never';const normalized=String(value).includes('T')?String(value):String(value).replace(' ','T');const d=new Date(normalized);return Number.isNaN(d.getTime())?String(value):d.toLocaleString()};
  const focusable=()=>[...modal.querySelectorAll('a[href],button:not([disabled]),input:not([disabled]),select:not([disabled]),textarea:not([disabled]),[tabindex]:not([tabindex="-1"])')].filter(el=>!el.hidden&&el.offsetParent!==null);
  const setBusy=value=>{busy=Boolean(value);[refreshButton,checkButton,disconnectButton,claimForm&&claimForm.querySelector('button[type="submit"]')].filter(Boolean).forEach(el=>{el.disabled=busy;el.setAttribute('aria-busy',busy?'true':'false')})};

  function policyBadge(mode){
    const badge=document.createElement('span');
    badge.className='agent-policy-badge';
    badge.dataset.policy=String(mode||'');
    badge.textContent=policyLabels[mode]||'Policy unavailable';
    return badge;
  }

  function renderPolicy(policy){
    if(!policySummary||!policyList)return;
    policySummary.replaceChildren();
    policyList.replaceChildren();
    if(!policy||policy.available!==true){
      const summary=document.createElement('span');
      summary.textContent='Policy unavailable';
      policySummary.appendChild(summary);
      const empty=document.createElement('div');
      empty.className='agent-policy-empty';
      empty.textContent=policyReasons[String(policy&&policy.reason||'')]||'Effective HomeServer tool policy is not available.';
      policyList.appendChild(empty);
      return;
    }

    const counts=policy.counts&&typeof policy.counts==='object'?policy.counts:{};
    [
      ['safe_automatic','Automatic'],
      ['approval_required','Approval'],
      ['read_only','Read only'],
      ['sensitive_high_impact','Local-only']
    ].forEach(([key,label])=>{
      const chip=document.createElement('span');
      const strong=document.createElement('strong');
      strong.textContent=Number(counts[key]||0).toLocaleString();
      chip.append(strong,document.createTextNode(` ${label}`));
      policySummary.appendChild(chip);
    });

    const tools=Array.isArray(policy.tools)?policy.tools:[];
    tools.forEach(tool=>{
      const row=document.createElement('div');
      row.className='agent-policy-tool';
      const copy=document.createElement('div');
      copy.className='agent-policy-tool-copy';
      const name=document.createElement('strong');
      name.textContent=String(tool.name||tool.key||'HomeServer tool');
      const meta=document.createElement('small');
      meta.textContent=String(tool.key||'');
      if(tool.inherited){
        const inherited=document.createElement('span');
        inherited.className='agent-policy-inherited';
        inherited.textContent=' · default';
        meta.appendChild(inherited);
      }
      copy.append(name,meta);
      row.append(copy,policyBadge(String(tool.policy_mode||'')));
      policyList.appendChild(row);
    });
    if(!tools.length){
      const empty=document.createElement('div');
      empty.className='agent-policy-empty';
      empty.textContent='HomeServer reported policy support but no tools are currently available to VP3.';
      policyList.appendChild(empty);
    }
    const note=document.createElement('div');
    note.className='agent-policy-note';
    note.textContent='Changes to these rules are made in the local HomeServer Control Center. VP3 cannot elevate its own authority.';
    policyList.appendChild(note);
  }

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
    if(!status.paired)renderPolicy({available:false,reason:'unpaired'});
    else if(!connected)renderPolicy({available:false,reason:'offline'});
  }

  async function parse(response){let data={};try{data=await response.json()}catch(_){throw new Error('VP3 returned an invalid HomeServer response.')}if(!response.ok||data.ok===false)throw Object.assign(new Error(data.error||`HomeServer request failed (${response.status})`),{data});return data}
  function statusUrl(force,includePolicy){
    const params=new URLSearchParams();
    if(force)params.set('refresh','1');
    if(includePolicy)params.set('policy','1');
    const query=params.toString();
    return api+(query?`?${query}`:'');
  }
  async function load(force,includePolicy){
    if(busy||!api)return;setBusy(true);
    try{
      const response=await fetch(statusUrl(Boolean(force),Boolean(includePolicy)),{credentials:'same-origin',cache:'no-store',headers:{Accept:'application/json'}});
      const data=await parse(response);
      render(data.status);
      if(includePolicy)renderPolicy(data.policy||{available:false,reason:'unavailable'});
    }catch(error){
      if(error&&error.data&&error.data.status)render(error.data.status);
      if(includePolicy)renderPolicy({available:false,reason:'remote_unavailable'});
      button.dataset.state='error';button.title=error&&error.message?error.message:'HomeServer status unavailable';button.setAttribute('aria-label','HomeServer status unavailable');
    }finally{setBusy(false)}
  }
  async function action(name,extra){
    if(busy)return;setBusy(true);
    try{const body=new URLSearchParams({action:name,csrf_token:csrf,...(extra||{})});const response=await fetch(api,{method:'POST',credentials:'same-origin',cache:'no-store',headers:{Accept:'application/json','Content-Type':'application/x-www-form-urlencoded;charset=UTF-8'},body});const data=await parse(response);render(data.status);return data}catch(error){if(error&&error.data&&error.data.status)render(error.data.status);const el=document.getElementById('vp3HomeServerError');if(el){el.hidden=false;el.textContent=error&&error.message?error.message:'HomeServer request failed.'}throw error}finally{setBusy(false)}
  }
  function open(){previousFocus=document.activeElement;previousOverflow=document.documentElement.style.overflow;modal.hidden=false;document.documentElement.style.overflow='hidden';closeButton&&closeButton.focus();load(true,true)}
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
  refreshButton&&refreshButton.addEventListener('click',()=>load(true,true));
  checkButton&&checkButton.addEventListener('click',async()=>{try{await action('check_pairing');await load(true,true)}catch(_){}});
  disconnectButton&&disconnectButton.addEventListener('click',async()=>{if(!confirm('Disconnect this HomeServer from VP3 on this account? The VP3 app permission can also be revoked from HomeServer.'))return;try{await action('disconnect');renderPolicy({available:false,reason:'unpaired'})}catch(_){}});
  claimForm&&claimForm.addEventListener('submit',async event=>{event.preventDefault();const input=claimForm.querySelector('input[name="claim_code"]');const code=input?String(input.value||'').trim():'';if(!code)return;try{await action('claim',{claim_code:code});if(input)input.value='';await load(true,true)}catch(_){}});
  load(false,false);
  window.setInterval(()=>{if(document.visibilityState==='visible'&&modal.hidden)load(false,false)},60000);
})();
