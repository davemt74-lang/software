(() => {
  const root=document.getElementById('federationSyncVisibilityV280');
  if(!root)return;
  const endpoint=root.dataset.endpoint||'/api/tracky-federation-sync-visibility-v280.php';
  const esc=(v='')=>String(v).replace(/[&<>'"]/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#39;','"':'&quot;'}[c]));
  const pretty=v=>String(v||'unknown').replaceAll('_',' ');
  const age=ms=>{ms=Number(ms||0);if(!ms)return'—';if(ms<60000)return Math.round(ms/1000)+'s';if(ms<3600000)return Math.round(ms/60000)+'m';return (ms/3600000).toFixed(1)+'h';};
  const when=v=>{if(!v)return'—';const n=Number(v);const d=new Date(n>100000000000?n:n*1000);return Number.isNaN(d.getTime())?String(v):d.toLocaleString();};
  const badge=s=>'<span class="csv280-state '+esc(s||'unknown')+'">'+esc(pretty(s))+'</span>';
  const site=row=>'<article class="csv280-site"><div class="csv280-head"><div><strong>'+esc(row.label||row.site_id)+'</strong><span>'+esc(row.site_id||'')+'</span></div>'+badge(row.status)+'</div><div class="csv280-grid"><div><span>Local rev</span><b>'+Number(row.local_cursor?.revision||0)+'</b></div><div><span>Remote rev</span><b>'+Number(row.remote_cursor?.revision||0)+'</b></div><div><span>Gap</span><b>'+Number(row.revision_gap||0)+'</b></div><div><span>Epoch</span><b>'+Number(row.local_cursor?.authority_epoch||0)+' / '+Number(row.remote_cursor?.authority_epoch||0)+'</b></div></div><div class="csv280-grid"><div><span>Stale</span><b>'+esc(age(row.stale_age_ms))+'</b></div><div><span>Retry</span><b>'+Number(row.retry_count||0)+'</b></div><div><span>Next retry</span><b>'+esc(row.next_retry_at?when(row.next_retry_at):'—')+'</b></div><div><span>Last contact</span><b>'+esc(row.last_contact_at?when(row.last_contact_at):'—')+'</b></div></div>'+(row.reconciliation_required?'<div class="csv280-progress"><span style="width:'+Math.round(Number(row.catch_up?.progress||0)*100)+'%"></span></div>':'')+'<p>'+esc(row.message||'')+'</p>'+(row.conflict_code?'<div class="csv280-warning">'+esc(row.conflict_code)+'</div>':'')+'</article>';
  const run=row=>'<div class="csv280-run"><div><strong>'+esc(row.site_label||row.site_id)+'</strong><span>'+esc(pretty(row.status))+' · '+esc(pretty(row.request_mode))+'</span></div><div><span>'+Number(row.local_revision||0)+' → '+Number(row.remote_revision||0)+'</span><small>'+esc(row.started_at?when(row.started_at):'')+'</small></div></div>';
  function render(wrapper){
    const report=wrapper.preferred_visibility||{};
    root.querySelector('[data-csv280-reporter]').textContent=wrapper.preferred_reporting_site_id||'No HomeServer report';
    root.querySelector('[data-csv280-state]').innerHTML=badge(report.overall_state||'unknown');
    root.querySelector('[data-csv280-current]').textContent=Number(report.counts?.current||0);
    root.querySelector('[data-csv280-reconciling]').textContent=Number(report.counts?.reconciling||0);
    root.querySelector('[data-csv280-partitioned]').textContent=Number(report.counts?.partitioned||0);
    root.querySelector('[data-csv280-failed]').textContent=Number(report.counts?.failed||0);
    root.querySelector('[data-csv280-agent]').textContent=report.agent_context?.summary||'No origin-reported sync summary.';
    root.querySelector('[data-csv280-sites]').innerHTML=(report.sites||[]).length?report.sites.map(site).join(''):'<div class="csv280-empty">No origin-reported federation sync state yet.</div>';
    root.querySelector('[data-csv280-runs]').innerHTML=(report.reconciliation_runs||[]).length?report.reconciliation_runs.slice(0,30).map(run).join(''):'<div class="csv280-empty">No reconciliation history reported.</div>';
  }
  async function load(){
    const status=root.querySelector('[data-csv280-status]');status.textContent='Refreshing…';
    const response=await fetch(endpoint,{credentials:'same-origin',headers:{'Accept':'application/json'}});
    const data=await response.json();
    if(!response.ok||data.ok===false)throw new Error(data.error||'Federation sync visibility unavailable.');
    render(data.sync_visibility||{});
    status.textContent='Origin-reported mirror · Cloud read only';
  }
  root.querySelector('[data-csv280-refresh]')?.addEventListener('click',()=>load().catch(err=>root.querySelector('[data-csv280-status]').textContent=err.message));
  window.loadCloudFederationSyncVisibility=load;
  load().catch(err=>root.querySelector('[data-csv280-status]').textContent=err.message);
})();