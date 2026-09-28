(() => {
  const root=document.getElementById('federationAgentHealthV280');
  if(!root)return;
  const endpoint=root.dataset.endpoint||'/api/tracky-federation-agent-health-v280.php';
  const esc=(v='')=>String(v).replace(/[&<>'"]/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#39;','"':'&quot;'}[c]));
  const pretty=v=>String(v||'unknown').replaceAll('_',' ');
  const age=ms=>{ms=Number(ms||0);if(!ms)return'0s';if(ms<60000)return Math.round(ms/1000)+'s';if(ms<3600000)return Math.round(ms/60000)+'m';return(ms/3600000).toFixed(1)+'h';};
  const badge=s=>'<span class="cfh280-state '+esc(s||'unknown')+'">'+esc(pretty(s))+'</span>';

  function site(row){
    const trust=row.trust||{};
    const authority=row.authority_device||{};
    return '<article class="cfh280-site"><div class="cfh280-site-head"><div><strong>'+esc(row.label||row.site_id)+'</strong><span>'+esc(row.site_id||'')+'</span></div>'+badge(row.state)+'</div><div class="cfh280-grid"><div><span>Sync</span><b>'+esc(pretty(row.sync_status))+'</b></div><div><span>Age</span><b>'+esc(age(row.state_age_ms))+'</b></div><div><span>Priority</span><b>'+esc(pretty(row.priority))+'</b></div><div><span>Authority</span><b>'+esc(authority.label||row.authority?.device_id||'—')+'</b></div></div><p>'+esc(row.message||'')+'</p>'+(row.recovery_pending?'<div class="cfh280-warning">Reconnect detected, but recovery is still pending authoritative reconciliation.</div>':'')+'<div class="cfh280-trust"><span>Local truth <b>'+(trust.local_physical_truth_current?'current':'not verified')+'</b></span><span>Remote federation <b>'+(trust.remote_federation_truth_current?'current':'stale/unknown')+'</b></span><span>Cloud transport <b>'+(trust.cloud_transport_connected?'connected':'offline')+'</b></span></div></article>';
  }
  function eventRow(row){
    const payload=row.payload||{};
    const type=String(payload.event_type||row.event_type||'').replace('tracky.federation_health.','');
    return '<div class="cfh280-history-row"><div><strong>'+esc(pretty(type))+'</strong><span>'+esc(payload.site_label||payload.site_id||row.entity_key||'VP3 Cloud Relay')+'</span></div><div><span>'+esc(pretty(payload.priority||'info'))+'</span><small>'+esc(row.occurred_at||row.created_at||'')+'</small></div></div>';
  }
  function render(wrapper){
    const report=wrapper.preferred_health||{};
    root.querySelector('[data-cfh280-reporter]').textContent=wrapper.preferred_reporting_site_id||'No HomeServer report';
    root.querySelector('[data-cfh280-overall]').innerHTML=badge(report.overall_state||'unknown');
    root.querySelector('[data-cfh280-connected]').textContent=Number(report.counts?.connected||0);
    root.querySelector('[data-cfh280-recovering]').textContent=Number(report.counts?.recovering||0)+Number(report.counts?.reconciling||0);
    root.querySelector('[data-cfh280-critical]').textContent=Number(report.counts?.partitioned||0)+Number(report.counts?.offline||0)+Number(report.counts?.failed||0);
    root.querySelector('[data-cfh280-bridge]').innerHTML=badge(report.bridge?.state||'unknown');
    root.querySelector('[data-cfh280-sites]').innerHTML=(report.sites||[]).length?(report.sites||[]).map(site).join(''):'<div class="cfh280-empty">No origin-reported site health yet.</div>';
    root.querySelector('[data-cfh280-history]').innerHTML=(report.history||[]).length?(report.history||[]).slice(0,40).map(eventRow).join(''):'<div class="cfh280-empty">No mirrored health transitions yet.</div>';
  }
  async function load(){
    const status=root.querySelector('[data-cfh280-status]');status.textContent='Refreshing…';
    const response=await fetch(endpoint,{credentials:'same-origin',headers:{'Accept':'application/json'}});
    const data=await response.json();
    if(!response.ok||data.ok===false)throw new Error(data.error||'Federation health mirror unavailable.');
    render(data.federation_health||{});
    status.textContent='HomeServer-governed mirror · Cloud read only';
  }
  root.querySelector('[data-cfh280-refresh]')?.addEventListener('click',()=>load().catch(err=>root.querySelector('[data-cfh280-status]').textContent=err.message));
  window.loadCloudFederationAgentHealth=load;
  load().catch(err=>root.querySelector('[data-cfh280-status]').textContent=err.message);
  setInterval(()=>load().catch(()=>{}),10000);
})();