(() => {
  'use strict';

  const cfg=window.STONEFELLOW_ACCOUNT_AGENT_V236;
  if(!cfg)return;
  const esc=value=>String(value??'').replace(/[&<>"']/g,char=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[char]));
  const sourceLabel=source=>source==='homeserver'?'HomeServer':source==='vp3_cloud'?'VP3 Cloud':source==='vp3_tool'?'VP3 Tools':'VP3';
  const reasonLabel=reason=>({
    homeserver_capability_ready:'Available from HomeServer',
    homeserver_not_paired:'HomeServer not paired',
    homeserver_offline:'HomeServer offline',
    capability_not_advertised:'Not advertised by this HomeServer',
    provider_unavailable:'HomeServer model route unavailable',
  }[reason]||'VP3 fallback available');
  let root=null;

  async function requestState(force=false){
    if(force){
      const response=await fetch(cfg.endpoint,{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json'},body:JSON.stringify({action:'refresh_homeserver_capabilities',csrf_token:cfg.csrf})});
      const data=await response.json().catch(()=>null);
      if(!response.ok||!data?.ok)throw new Error(data?.error||'HomeServer capabilities could not be refreshed.');
      return data.state||{};
    }
    const response=await fetch(cfg.endpoint,{credentials:'same-origin',cache:'no-store'});
    const data=await response.json().catch(()=>null);
    if(!response.ok||!data?.ok)throw new Error(data?.error||'HomeServer capabilities could not be loaded.');
    return data.state||{};
  }

  function capabilityCard(key,cap,registry){
    const ready=Boolean(cap?.ready);
    const supported=Boolean(cap?.supported);
    const source=sourceLabel(cap?.source||cap?.fallback_source||'vp3');
    let detail=reasonLabel(cap?.reason);
    if(key==='inference'&&registry?.inference){
      const runtime=[registry.inference.provider,registry.inference.model].filter(Boolean).join(' · ');
      if(runtime)detail=runtime;
    }
    const status=ready?'homeserver':supported?'available':'fallback';
    return `<article class="sf-hs-capability ${status}"><div><small>${esc(cap?.label||key)}</small><strong>${esc(source)}</strong></div><span>${esc(detail)}</span></article>`;
  }

  function render(state){
    if(!root)return;
    const registry=state?.homeserver_capabilities||{};
    const caps=registry.capabilities||{};
    const keys=['agent_brain','inference','memory','knowledge','contacts','awareness','tools','skills','plugins'];
    const paired=Boolean(registry.paired);
    const connected=Boolean(registry.connected);
    const headline=connected?'Connected':paired?'Paired · currently offline':'Not paired';
    const version=registry.installed_version?`HomeServer v${registry.installed_version}`:'HomeServer';
    const advertised=registry.advertised?`${Number(registry.feature_count||0)} capabilities advertised`:'Compatibility mode';
    root.innerHTML=`
      <div class="sf-hs-capability-head">
        <div><span class="sf-agent-section-label">HomeServer Capabilities</span><h3>Agent capability routing</h3><p class="sf-agent-help">VP3 uses the paired HomeServer for capabilities it advertises and falls back to VP3 services when needed. HomeServer permissions remain the final authority for private data and tools.</p></div>
        <div class="sf-hs-capability-meta"><small>${esc(version)}</small><strong>${esc(headline)}</strong><span>${esc(advertised)}</span><button type="button" class="sf-agent-button secondary" data-refresh-homeserver>Refresh HomeServer</button></div>
      </div>
      <div class="sf-hs-capability-grid">${keys.map(key=>capabilityCard(key,caps[key]||{},registry)).join('')}</div>
      <p class="sf-hs-capability-note">Local and user-provider HomeServer execution does not consume VP3 cloud tokens. VP3 tokens are charged only when VP3 Cloud actually performs billable compute.</p>`;
    root.querySelector('[data-refresh-homeserver]')?.addEventListener('click',refresh);
  }

  async function refresh(event){
    const button=event?.currentTarget||null;
    if(button)button.disabled=true;
    try{
      render(await requestState(true));
    }catch(error){
      const note=root?.querySelector('.sf-hs-capability-note');
      if(note)note.textContent=error?.message||'HomeServer capabilities could not be refreshed.';
    }finally{
      const next=root?.querySelector('[data-refresh-homeserver]');
      if(next)next.disabled=false;
    }
  }

  function mount(){
    if(document.querySelector('[data-homeserver-capabilities-v024]'))return true;
    const compute=document.querySelector('.sf-compute-card');
    if(!compute)return false;
    root=document.createElement('section');
    root.className='sf-homeserver-capabilities-v024';
    root.setAttribute('data-homeserver-capabilities-v024','1');
    root.setAttribute('aria-label','HomeServer capability routing');
    compute.insertAdjacentElement('afterend',root);
    root.innerHTML='<div class="sf-agent-empty">Loading HomeServer capabilities…</div>';
    requestState(false).then(render).catch(error=>{if(root)root.innerHTML=`<div class="sf-agent-empty">${esc(error?.message||'HomeServer capabilities could not be loaded.')}</div>`;});
    return true;
  }

  if(!mount()){
    const observer=new MutationObserver(()=>{if(mount())observer.disconnect();});
    observer.observe(document.body,{childList:true,subtree:true});
    window.setTimeout(()=>observer.disconnect(),10000);
  }
})();
