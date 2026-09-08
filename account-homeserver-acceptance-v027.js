(() => {
  'use strict';
  const cfg=window.STONEFELLOW_ACCOUNT_AGENT_V236;
  if(!cfg)return;

  const esc=value=>String(value??'').replace(/[&<>"']/g,char=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[char]));
  const endpoint=new URL('./api/homeserver-acceptance-v027.php',window.location.href);
  let root=null;

  function statusLabel(status){
    return ({ready:'Ready',warning:'Check',blocked:'Blocked',not_applicable:'N/A'})[status]||'Check';
  }

  function metaText(check){
    const meta=check?.meta||{};
    const parts=[];
    if(meta.provider)parts.push(meta.provider);
    if(meta.model)parts.push(meta.model);
    if(Number.isFinite(Number(meta.count)))parts.push(`${Number(meta.count)} available`);
    if(meta.version)parts.push(`v${meta.version}`);
    if(check?.latency_ms!==null&&check?.latency_ms!==undefined)parts.push(`${Number(check.latency_ms).toLocaleString()} ms`);
    return parts.join(' · ');
  }

  function checkCard(check){
    const status=['ready','warning','blocked','not_applicable'].includes(check?.status)?check.status:'blocked';
    const meta=metaText(check);
    return `<article class="sf-hs-acceptance-check ${status}">
      <div class="sf-hs-acceptance-check-head"><small>${esc(check?.label||'Check')}</small><strong>${esc(statusLabel(status))}</strong></div>
      <p>${esc(check?.detail||'No diagnostic detail was returned.')}</p>
      ${meta?`<span>${esc(meta)}</span>`:''}
    </article>`;
  }

  function renderIdle(){
    if(!root)return;
    root.innerHTML=`
      <div class="sf-hs-capability-head">
        <div><span class="sf-agent-section-label">Production Acceptance</span><h3>HomeServer connection test</h3><p class="sf-agent-help">Verify the complete VP3 → Remote Bridge → HomeServer control path before relying on private Agent compute.</p></div>
        <div class="sf-hs-capability-meta"><small>Read-only · 0 model tokens</small><strong>Not tested</strong><span>No Agent prompt or private Memory/Knowledge content is read.</span><button type="button" class="sf-agent-button secondary" data-hs-acceptance-run>Run connection test</button></div>
      </div>
      <p class="sf-hs-capability-note">The test checks pairing, relay authentication, VP3 scope, Agent Brain, model readiness, Memory, Knowledge, Tools, Skills, Plugins, cloud fallback policy and HomeServer version.</p>`;
    root.querySelector('[data-hs-acceptance-run]')?.addEventListener('click',run);
  }

  function renderResult(result){
    if(!root)return;
    const summary=result?.summary||{};
    const ready=Number(summary.ready||0),warnings=Number(summary.warnings||0),blocked=Number(summary.blocked||0);
    const productionReady=Boolean(result?.production_ready);
    root.innerHTML=`
      <div class="sf-hs-capability-head">
        <div><span class="sf-agent-section-label">Production Acceptance</span><h3>HomeServer connection test</h3><p class="sf-agent-help">Read-only verification of the live VP3 → Remote Bridge → HomeServer integration.</p></div>
        <div class="sf-hs-capability-meta"><small>Read-only · ${Number(result?.token_spend||0)} model tokens</small><strong>${esc(productionReady?'Production route ready':'Needs attention')}</strong><span>${esc(`${ready} ready · ${warnings} warnings · ${blocked} blocked · ${Number(result?.duration_ms||0).toLocaleString()} ms`)}</span><button type="button" class="sf-agent-button secondary" data-hs-acceptance-run>Run again</button></div>
      </div>
      <div class="sf-hs-acceptance-summary ${productionReady?'ready':'blocked'}">${esc(productionReady?'Core HomeServer integration passed the acceptance gate.':'One or more required HomeServer integration checks are blocked.')}</div>
      <div class="sf-hs-acceptance-grid">${(Array.isArray(result?.checks)?result.checks:[]).map(checkCard).join('')}</div>
      <p class="sf-hs-capability-note">This diagnostic never sends an Agent prompt and does not copy private Memory or Knowledge contents into VP3.</p>`;
    root.querySelector('[data-hs-acceptance-run]')?.addEventListener('click',run);
  }

  async function run(event){
    const button=event?.currentTarget||root?.querySelector('[data-hs-acceptance-run]');
    if(button){button.disabled=true;button.textContent='Testing…';}
    try{
      const response=await fetch(endpoint,{method:'POST',credentials:'same-origin',cache:'no-store',headers:{'Content-Type':'application/json'},body:JSON.stringify({csrf_token:cfg.csrf})});
      const data=await response.json().catch(()=>null);
      if(!response.ok||!data?.ok)throw new Error(data?.error||'HomeServer connection test failed.');
      renderResult(data.acceptance||{});
    }catch(error){
      if(root)root.innerHTML=`<div class="sf-hs-capability-head"><div><span class="sf-agent-section-label">Production Acceptance</span><h3>HomeServer connection test</h3><p class="sf-agent-help">${esc(error?.message||'HomeServer connection test failed.')}</p></div><div class="sf-hs-capability-meta"><small>Read-only · 0 model tokens</small><strong>Test failed</strong><button type="button" class="sf-agent-button secondary" data-hs-acceptance-run>Try again</button></div></div>`;
      root?.querySelector('[data-hs-acceptance-run]')?.addEventListener('click',run);
    }
  }

  function mount(){
    if(document.querySelector('[data-homeserver-acceptance-v027]'))return true;
    const capabilities=document.querySelector('[data-homeserver-capabilities-v024]');
    if(!capabilities)return false;
    root=document.createElement('section');
    root.className='sf-homeserver-acceptance-v027';
    root.setAttribute('data-homeserver-acceptance-v027','1');
    root.setAttribute('aria-label','Production HomeServer connection test');
    capabilities.insertAdjacentElement('afterend',root);
    renderIdle();
    return true;
  }

  if(!mount()){
    const observer=new MutationObserver(()=>{if(mount())observer.disconnect();});
    observer.observe(document.body,{childList:true,subtree:true});
    window.setTimeout(()=>observer.disconnect(),10000);
  }
})();
