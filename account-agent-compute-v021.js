(() => {
  'use strict';
  const cfg=window.STONEFELLOW_ACCOUNT_AGENT_V236;
  if(!cfg)return;
  const esc=v=>String(v??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[c]));
  let state=null,lastTest=null,lastPreference='',refreshTimer=0;

  function actionLabel(action){
    return ({
      connect_homeserver:'Pair HomeServer to restore the private route.',
      check_homeserver:'Check that HomeServer and its selected model/provider are running.',
      configure_cloud:'Configure a ready VP3 Cloud provider.',
      configure_compute:'Pair HomeServer or configure VP3 Cloud.',
      check_compute:'Restore HomeServer or configure VP3 Cloud.',
      none:''
    })[action]||'';
  }

  function mount(){
    const statusGrid=document.getElementById('sfComputeStatus');
    const card=statusGrid?.closest('.sf-compute-card');
    if(!card)return false;
    if(document.getElementById('sfComputeHealthV021'))return true;
    const box=document.createElement('section');
    box.id='sfComputeHealthV021';
    box.className='sf-compute-health-v021';
    box.innerHTML=`
      <div class="sf-compute-health-copy" id="sfComputeReasonV021"></div>
      <div class="sf-compute-health-actions">
        <button type="button" class="sf-agent-button secondary" id="sfComputeTestV021">Test route</button>
        <small>Read-only health check · 0 model tokens</small>
      </div>
      <div class="sf-compute-test-result" id="sfComputeTestResultV021" aria-live="polite"></div>`;
    statusGrid.insertAdjacentElement('afterend',box);
    document.getElementById('sfComputeTestV021')?.addEventListener('click',testRoute);
    const options=document.getElementById('sfComputeOptions');
    if(options){
      const observer=new MutationObserver(()=>{
        clearTimeout(refreshTimer);
        refreshTimer=setTimeout(refreshState,80);
      });
      observer.observe(options,{childList:true,subtree:false});
    }
    return true;
  }

  function render(){
    if(!mount())return;
    const c=state?.compute||{};
    const reason=c.reason||{};
    const preference=String(c.preference||'auto');
    if(lastPreference&&lastPreference!==preference)lastTest=null;
    lastPreference=preference;
    const reasonEl=document.getElementById('sfComputeReasonV021');
    const action=actionLabel(reason.action);
    if(reasonEl)reasonEl.innerHTML=`<small>Why this route</small><strong>${esc(reason.label||c.resolved_label||'Compute route')}</strong><p>${esc(reason.detail||'VP3 selected the available Agent compute route.')}</p>${action?`<span>${esc(action)}</span>`:''}`;
    renderTest();
  }

  function renderTest(){
    const out=document.getElementById('sfComputeTestResultV021');
    if(!out)return;
    if(!lastTest){
      out.innerHTML='<span>Run a route test to verify the configured compute path without generating an Agent response.</span>';
      out.className='sf-compute-test-result idle';
      return;
    }
    const t=lastTest;
    const detail=[t.provider,t.model].filter(Boolean).join(' · ');
    const latency=t.latency_ms===null||t.latency_ms===undefined?'Not applicable':`${Number(t.latency_ms||0).toLocaleString()} ms`;
    const reason=t.reason||{};
    out.className=`sf-compute-test-result ${t.ready?'ready':'blocked'}`;
    out.innerHTML=`
      <div><small>Route test</small><strong>${esc(t.ready?'Ready':'Needs attention')}</strong></div>
      <div><small>Reached</small><strong>${esc(t.route_label||t.route||'No route')}</strong>${detail?`<span>${esc(detail)}</span>`:''}</div>
      <div><small>HomeServer latency</small><strong>${esc(latency)}</strong><span>${t.fallback_used?'Fallback verified':'Read-only status probe'}</span></div>
      <p>${esc(reason.detail||'The configured route was checked.')}</p>`;
  }

  async function refreshState(){
    if(!mount())return;
    try{
      const r=await fetch(cfg.endpoint,{credentials:'same-origin',cache:'no-store'});
      const d=await r.json().catch(()=>null);
      if(!r.ok||!d?.ok)throw new Error(d?.error||'Compute health could not be loaded.');
      state=d.state;
      render();
    }catch(err){
      const out=document.getElementById('sfComputeTestResultV021');
      if(out){out.className='sf-compute-test-result blocked';out.textContent=err.message||'Compute health could not be loaded.';}
    }
  }

  async function testRoute(){
    const button=document.getElementById('sfComputeTestV021');
    if(!button||button.disabled)return;
    button.disabled=true;button.textContent='Testing…';
    try{
      const r=await fetch(cfg.endpoint,{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json'},body:JSON.stringify({action:'test_compute_route',csrf_token:cfg.csrf})});
      const d=await r.json().catch(()=>null);
      if(!r.ok||!d?.ok)throw new Error(d?.error||'Compute route test failed.');
      state=d.state;lastTest=d.route_test||null;render();
    }catch(err){
      lastTest={ready:false,route_label:'Route test failed',latency_ms:null,reason:{detail:err.message||'The route test could not be completed.'}};renderTest();
    }finally{
      button.disabled=false;button.textContent='Test route';
    }
  }

  function boot(attempt=0){
    if(mount()){refreshState();return;}
    if(attempt<40)setTimeout(()=>boot(attempt+1),100);
  }
  boot();
})();
