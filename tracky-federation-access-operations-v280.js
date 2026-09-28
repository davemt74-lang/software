(() => {
  const root=document.getElementById('federationAccessOperationsV280');
  if(!root)return;
  const endpoint=root.dataset.endpoint||'/api/tracky-federation-access-operations-v280.php';
  const esc=(v='')=>String(v).replace(/[&<>'"]/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#39;','"':'&quot;'}[c]));
  const pretty=v=>String(v||'unknown').replaceAll('_',' ');
  const badge=s=>'<span class="cfa280-state '+esc(s||'denied')+'">'+esc(pretty(s))+'</span>';

  function scope(row){
    return '<div class="cfa280-scope"><div><strong>'+esc(pretty(row.scope||''))+'</strong><span>r'+Number(row.revision||0)+(row.revocation_epoch?' · revoke epoch '+Number(row.revocation_epoch):'')+'</span></div>'+badge(row.effective_allowed?'allowed':(row.status||'denied'))+'</div>';
  }
  function category(name,row){
    return '<div class="cfa280-category"><div class="cfa280-category-head"><strong>'+esc(pretty(name))+'</strong>'+badge(row.state)+'</div>'+(row.scopes||[]).map(scope).join('')+'</div>';
  }
  function peer(row){
    return '<article class="cfa280-peer"><div class="cfa280-peer-head"><div><strong>'+esc(row.label||row.site_id)+'</strong><span>'+esc(row.site_id||'')+'</span></div>'+badge(row.sync?.status||'unknown')+'</div><div class="cfa280-peer-meta"><span>Allowed peer <b>'+(row.policy_peer_allowed?'Yes':'No')+'</b></span><span>Federation <b>'+(row.federation_enabled?'On':'Off')+'</b></span><span>Remote observation <b>'+(row.remote_observation_enabled?'On':'Off')+'</b></span><span>Revocation wins <b>Yes</b></span></div>'+(row.sync&&!row.sync.fresh?'<div class="cfa280-warning">Origin sync is '+esc(pretty(row.sync.status))+'. Cloud keeps newer revocations effective and never promotes a stale grant.</div>':'')+'<div class="cfa280-category-grid">'+Object.entries(row.categories||{}).map(([name,value])=>category(name,value)).join('')+'</div></article>';
  }
  function consent(row){
    return '<div class="cfa280-consent"><div><strong>'+esc(row.canonical_identity_id||'')+'</strong><span>'+esc(pretty(row.scope))+' · r'+Number(row.revision||0)+'</span></div>'+badge(row.status)+'</div>';
  }
  function history(row){
    return '<div class="cfa280-history-row"><div><strong>'+esc(pretty(row.event_type))+'</strong><span>rev '+Number(row.revision||0)+' · epoch '+Number(row.revocation_epoch||0)+'</span></div><div><span>'+esc(row.governing_site_id||'')+'</span><small>'+esc(row.occurred_at||'')+'</small></div></div>';
  }
  function source(view){
    const policy=view.local_policy||{};
    return '<section class="cfa280-source"><div class="cfa280-source-head"><div><small>ORIGIN SITE</small><h4>'+esc(view.source_label||view.source_site_id)+'</h4><span>'+esc(view.source_site_id||'')+'</span></div><div><span>Policy r'+Number(view.policy_revision||0)+'</span><span>Revocation epoch '+Number(view.revocation_epoch||0)+'</span></div></div><div class="cfa280-policy"><span>Mode <b>'+esc(policy.mode||'private')+'</b></span><span>Federation <b>'+(policy.allow_federation?'On':'Off')+'</b></span><span>Remote observation <b>'+(policy.allow_remote_observation?'On':'Off')+'</b></span><span>Identity <b>'+esc(policy.default_identity_visibility||'none')+'</b></span></div><div class="cfa280-peer-list">'+((view.peers||[]).length?(view.peers||[]).map(peer).join(''):'<div class="cfa280-empty">No peer grants from this site.</div>')+'</div><div class="cfa280-subgrid"><div><h4>Recognition consent</h4><div>'+((view.consents||[]).length?(view.consents||[]).map(consent).join(''):'<div class="cfa280-empty">No consent records.</div>')+'</div></div><div><h4>Policy history</h4><div>'+((view.history||[]).length?(view.history||[]).slice(0,25).map(history).join(''):'<div class="cfa280-empty">No mirrored policy history.</div>')+'</div></div></div></section>';
  }
  function render(report){
    root.querySelector('[data-cfa280-sources]').textContent=Number(report.counts?.source_sites||0);
    root.querySelector('[data-cfa280-allowed]').textContent=Number(report.counts?.grants_allowed||0);
    root.querySelector('[data-cfa280-revoked]').textContent=Number(report.counts?.grants_revoked||0)+Number(report.counts?.consents_revoked||0);
    root.querySelector('[data-cfa280-suppressed]').textContent=Number(report.counts?.stale_grants_suppressed||0);
    root.querySelector('[data-cfa280-views]').innerHTML=(report.source_views||[]).length?(report.source_views||[]).map(source).join(''):'<div class="cfa280-empty">No HomeServer federation policy mirrors are available.</div>';
  }
  async function load(){
    const status=root.querySelector('[data-cfa280-status]');status.textContent='Refreshing…';
    const response=await fetch(endpoint,{credentials:'same-origin',headers:{'Accept':'application/json'}});
    const data=await response.json();
    if(!response.ok||data.ok===false)throw new Error(data.error||'Federation access mirror unavailable.');
    render(data.federation_access||{});
    status.textContent='HomeServer-governed mirror · Cloud read only';
  }
  root.querySelector('[data-cfa280-refresh]')?.addEventListener('click',()=>load().catch(err=>root.querySelector('[data-cfa280-status]').textContent=err.message));
  window.loadCloudFederationAccessOperations=load;
  load().catch(err=>root.querySelector('[data-cfa280-status]').textContent=err.message);
})();