(() => {
  const root=document.getElementById('federationAgentHealthV280');
  if(!root)return;
  const endpoint=root.dataset.endpoint||'/api/tracky-federation-agent-health-v280.php';
  const esc=(v='')=>String(v).replace(/[&<>'"]/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#39;','"':'&quot;'}[c]));
  const pretty=v=>String(v||'unknown').replaceAll('_',' ');
  const badge=s=>'<span class="cfh280-state '+esc(s||'unknown')+'">'+esc(pretty(s))+'</span>';
  function site(row){
    const trust=row.trust||{};
    return '<article class="cfh280-site"><div class="cfh280-site-head"><div><strong>'+esc(row.label||row.site_id)+'</strong><span>'+esc(row.site_id||'')+'</span></div>'+badge(row.state)+'</div><div class="cfh280-meta"><span>Severity <b>'+esc(row.severity||'info')+'</b></span><span>Cause <b>'+esc(pretty(row.cause||'none'))+'</b></span><span>Fresh <b>'+(row.fresh?'Yes':'No')+'</b></span><span>Recovery <b>'+(row.recovery_complete?'Complete':'Pending')+'</b></span></div><div class="cfh280-trust"><span>Semantic '+esc(pretty(trust.semantic_state||'unknown'))+'</span><span>Agent '+esc(pretty(trust.agent_use||'unknown'))+'</span><span>Physical '+esc(pretty(trust.physical_claims||'unknown'))+'</span></div><p>'+esc(row.message||'')+'</p></article>';
  }
  function history(row){
    const p=row.payload||{};
    return '<div class="cfh280-history-row"><div><strong>'+esc(pretty(row.event_type||''))+'</strong><span>'+esc(p.label||p.site_id||p.component||'')+'</span></div><div><span>'+esc(p.severity||'')+'</span><small>'+esc(row.occurred_at||'')+'</small></div></div>';
  }
  function render(wrapper){
    const report=wrapper.preferred_health||{};
    root.querySelector('[data-cfh280-reporter]').textContent=wrapper.preferred_reporting_site_id||'No HomeServer report';
    root.querySelector('[data-cfh280-overall]').innerHTML=badge(report.overall_state||'unknown');
    root.querySelector('[data-cfh280-current]').textContent=Number(report.counts?.current||0);
    root.querySelector('[data-cfh280-degraded]').textContent=Number(report.counts?.degraded||0);
    root.querySelector('[data-cfh280-critical]').textContent=Number(report.counts?.critical||0);
    root.querySelector('[data-cfh280-recovering]').textContent=Number(report.counts?.recovering||0);
    root.querySelector('[data-cfh280-relay]').innerHTML=badge(report.relay_health?.state||'unknown');
    root.querySelector('[data-cfh280-agent]').textContent=report.agent_context?.summary||'No origin-reported federation health summary.';
    root.querySelector('[data-cfh280-sites]').innerHTML=(report.sites||[]).length?(report.sites||[]).map(site).join(''):'<div class="cfh280-empty">No origin-reported federation health state yet.</div>';
    root.querySelector('[data-cfh280-history]').innerHTML=(report.history||[]).length?(report.history||[]).slice(0,40).map(history).join(''):'<div class="cfh280-empty">No mirrored federation health events yet.</div>';
  }
  async function load(){
    const status=root.querySelector('[data-cfh280-status]');status.textContent='Refreshing…';
    const response=await fetch(endpoint,{credentials:'same-origin',headers:{'Accept':'application/json'}});
    const data=await response.json();
    if(!response.ok||data.ok===false)throw new Error(data.error||'Federation Agent health unavailable.');
    render(data.federation_agent_health||{});
    status.textContent='Origin-reported mirror · Cloud read only';
  }
  root.querySelector('[data-cfh280-refresh]')?.addEventListener('click',()=>load().catch(err=>root.querySelector('[data-cfh280-status]').textContent=err.message));
  window.loadCloudFederationAgentHealth=load;
  load().catch(err=>root.querySelector('[data-cfh280-status]').textContent=err.message);
})();