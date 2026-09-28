(() => {
  const root=document.getElementById('crossSitePresenceV280');
  if(!root)return;
  const endpoint=root.dataset.endpoint||'/api/tracky-cross-site-presence-v280.php';
  const esc=(v='')=>String(v).replace(/[&<>'"]/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#39;','"':'&quot;'}[c]));
  const pct=(v)=>Math.round(Math.max(0,Math.min(1,Number(v||0)))*100)+'%';
  const pretty=(v)=>String(v||'unknown').replaceAll('_',' ');
  const when=(v)=>{if(!v)return'Unknown';const n=Number(v);const d=new Date(n>100000000000?n:n*1000);return Number.isNaN(d.getTime())?String(v):d.toLocaleString();};

  const card=(t)=>{
    const src=t.source_site||{},dst=t.destination_site||null,last=t.last_confirmed_site||null,p=t.current_presence||null;
    let status='';
    if(t.state==='arrived'&&dst)status='<strong>Present at '+esc(dst.label||dst.site_id)+'</strong>';
    else if(t.state==='arriving'&&dst)status='<strong>Arriving at '+esc(dst.label||dst.site_id)+'</strong><small>Destination presence is not confirmed yet.</small>';
    else if(t.state==='in_transit')status='<strong>In transit'+(dst?' to '+esc(dst.label||dst.site_id):'')+'</strong><small>Last confirmed at '+esc(last?.label||last?.site_id||'unknown')+'.</small>';
    else if(t.state==='departing')status='<strong>Departing '+esc(src.label||src.site_id)+'</strong>';
    else if(t.state==='offline')status='<strong>Offline during transition</strong><small>Last confirmed at '+esc(last?.label||last?.site_id||'unknown')+'.</small>';
    else if(t.state==='temporary_context')status='<strong>'+esc(p?.label||'Temporary context')+'</strong><small>Temporary context only — not a durable site.</small>';
    else status='<strong>'+esc(pretty(t.state))+'</strong>';
    return '<article class="csp280-card '+esc(t.state||'unknown')+'"><div class="csp280-head"><div><small>'+esc(t.subject_type||t.subject_kind||'subject')+'</small><h4>'+esc(t.subject_label||t.subject_id)+'</h4></div><span>'+esc(pretty(t.state))+'</span></div><div class="csp280-route"><span>'+esc(src.label||src.site_id||'Unknown source')+'</span><b>→</b><span>'+esc(dst?.label||dst?.site_id||'Unknown destination')+'</span></div><div class="csp280-status">'+status+'</div><div class="csp280-meta"><span>confidence <b>'+pct(t.confidence)+'</b></span><span>destination <b>'+pct(t.destination_confidence)+'</b></span><span>updated <b>'+esc(when(t.state_changed_at||t.updated_at))+'</b></span></div>'+(t.authority?.subject_is_source_authority?'<div class="csp280-warning">Moving this authority device does not transfer site authority.</div>':'')+'</article>';
  };
  const timeline=(row)=>'<div class="csp280-timeline-row"><span class="csp280-dot '+esc(row.state||'unknown')+'"></span><div><strong>'+esc(row.subject_label||row.subject_id)+'</strong><span>'+esc(pretty(row.state))+' · '+esc(row.source_site?.label||row.source_site?.site_id||'')+(row.destination_site?' → '+esc(row.destination_site.label||row.destination_site.site_id):'')+'</span></div><div><span>r'+Number(row.revision||0)+'</span><small>'+esc(when(row.occurred_at))+'</small></div></div>';
  const site=(s)=>'<div class="csp280-site"><div><strong>'+esc(s.label||s.site_id)+'</strong><span>'+esc(s.federation_status||'unknown')+'</span></div><div><span>Departing <b>'+Number(s.departing||0)+'</b></span><span>Arriving <b>'+Number(s.arriving||0)+'</b></span><span>Transit <b>'+Number((s.in_transit_from||0)+(s.in_transit_to||0))+'</b></span><span>Offline <b>'+Number(s.offline||0)+'</b></span></div></div>';

  function render(report){
    root.querySelector('[data-csp280-active-count]').textContent=Number(report.active_count||0);
    root.querySelector('[data-csp280-transit-count]').textContent=Number(report.state_counts?.in_transit||0);
    root.querySelector('[data-csp280-arriving-count]').textContent=Number(report.state_counts?.arriving||0);
    root.querySelector('[data-csp280-offline-count]').textContent=Number(report.state_counts?.offline||0);
    root.querySelector('[data-csp280-active]').innerHTML=(report.active_transitions||[]).length?report.active_transitions.map(card).join(''):'<div class="csp280-empty">No active cross-site transitions.</div>';
    root.querySelector('[data-csp280-sites]').innerHTML=(report.site_presence||[]).length?report.site_presence.map(site).join(''):'<div class="csp280-empty">No site presence summary available.</div>';
    root.querySelector('[data-csp280-timeline]').innerHTML=(report.timeline||[]).length?report.timeline.slice(0,40).map(timeline).join(''):'<div class="csp280-empty">No transition history yet.</div>';
    root.querySelector('[data-csp280-history]').textContent=report.history_source==='immutable_transition_revision_history'?'Immutable revision history':'Current snapshots only';
    root.querySelector('[data-csp280-agent-rule]').textContent=report.agent_context?.destination_claim_rule==='present_at_destination_only_after_arrived'?'Agent confirms destination presence only after ARRIVED.':'Destination claim rule unavailable.';
  }

  async function load(){
    const status=root.querySelector('[data-csp280-status]');
    status.textContent='Refreshing…';
    const response=await fetch(endpoint,{credentials:'same-origin',headers:{'Accept':'application/json'}});
    const data=await response.json();
    if(!response.ok||data.ok===false)throw new Error(data.error||'Cross-site presence unavailable.');
    const wrapper=data.cross_site_presence||{};
    render(wrapper.presence||{});
    status.textContent='Governed Cloud mirror · read only';
  }
  root.querySelector('[data-csp280-refresh]')?.addEventListener('click',()=>load().catch(err=>root.querySelector('[data-csp280-status]').textContent=err.message));
  window.loadCloudCrossSitePresence=load;
  load().catch(err=>root.querySelector('[data-csp280-status]').textContent=err.message);
})();