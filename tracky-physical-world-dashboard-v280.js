(() => {
  const root=document.getElementById('physicalWorldDashboardV280');
  if(!root)return;
  const endpoint=root.dataset.endpoint||'/api/tracky-physical-world-dashboard-v280.php';
  const initialSite=root.dataset.selectedSite||'';
  const esc=(v='')=>String(v).replace(/[&<>'"]/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#39;','"':'&quot;'}[c]));
  const pct=(v)=>Math.round(Math.max(0,Math.min(1,Number(v||0)))*100)+'%';
  const nice=(v)=>String(v||'unknown').replaceAll('_',' ');
  const entity=(item)=>{
    const loc=item.location||null;
    return '<article class="pw280-entity"><div class="pw280-entity-head"><div><strong>'+esc(item.label||item.local_id||item.type)+'</strong><span>'+esc(item.type||'entity')+'</span></div><span class="pw280-state '+(item.current?'current':'')+'">'+esc(item.current?'current':item.state||'unknown')+'</span></div><div class="pw280-meta"><span>confidence <b>'+pct(item.confidence)+'</b></span>'+(loc?'<span>location <b>'+esc(loc.location_label||loc.location_local_id||'')+'</b> · '+pct(loc.confidence)+'</span>':'<span>location <b>not established</b></span>')+'</div></article>';
  };
  const list=(selector,items,empty)=>{const node=root.querySelector(selector);node.innerHTML=items.length?items.map(entity).join(''):'<div class="pw280-empty">'+esc(empty)+'</div>';};

  function render(report){
    const selected=report.selected_site||{};
    root.querySelector('[data-pw280-site-name]').textContent=selected.label||selected.site_id||'No site';
    root.querySelector('[data-pw280-health]').textContent=selected.health||'unknown';
    root.querySelector('[data-pw280-federation]').textContent=selected.federation_status||'unknown';
    root.querySelector('[data-pw280-revision]').textContent=String(selected.world_revision||0);

    const picker=root.querySelector('[data-pw280-site-select]');
    picker.innerHTML=(report.site_options||[]).map(site=>'<option value="'+esc(site.site_id)+'"'+(site.selected?' selected':'')+'>'+esc(site.label||site.site_id)+' · '+esc(site.health||'unknown')+'</option>').join('');
    picker.disabled=!(report.site_options||[]).length;

    const counts=report.counts||{};
    root.querySelector('[data-pw280-rooms]').textContent=Number(counts.rooms||0);
    root.querySelector('[data-pw280-people]').textContent=Number(counts.people||0);
    root.querySelector('[data-pw280-objects]').textContent=Number(counts.objects||0);
    root.querySelector('[data-pw280-hardware-count]').textContent=Number(counts.hardware_units||0);

    const ctx=report.agent_context||{};
    root.querySelector('[data-pw280-agent-view]').textContent=ctx.view_site_id||'None';
    root.querySelector('[data-pw280-agent-physical]').textContent=ctx.physical_current_site_id||'Unknown';
    root.querySelector('[data-pw280-agent-basis]').textContent=nice(ctx.view_basis);
    root.querySelector('[data-pw280-agent-note]').textContent=ctx.view_site_id&&ctx.physical_current_site_id&&ctx.view_site_id!==ctx.physical_current_site_id
      ?'Agent dashboard context follows the selected site; physical current-site evidence remains unchanged.'
      :'Agent dashboard context and physical current site are aligned.';

    list('[data-pw280-room-list]',report.rooms||[],'No rooms visible in this governed site projection.');
    list('[data-pw280-people-list]',report.people||[],'No people visible under current policy and consent.');
    list('[data-pw280-object-list]',report.objects||[],'No objects visible in this site projection.');
    root.querySelector('[data-pw280-hardware-list]').innerHTML=(report.hardware_units||[]).length
      ?report.hardware_units.map(item=>'<div class="pw280-hardware"><div><strong>'+esc(item.label||item.id)+'</strong><span>'+esc(item.hardware_profile_label||item.hardware_profile||'Device')+'</span></div><div>'+(item.is_authority?'<b>site authority</b> ':'')+esc(item.trust_state||'unknown')+(item.version?' · v'+esc(item.version):'')+'</div></div>').join('')
      :'<div class="pw280-empty">No registered hardware units at this site.</div>';
    const warning=root.querySelector('[data-pw280-warning]');
    const missing=(report.issues||[]).some(x=>x.code==='requested_site_not_available');
    warning.hidden=!missing;
    warning.textContent=missing?'Requested site is not available; an authorized site was selected instead.':'';
  }

  async function load(siteId=initialSite){
    const status=root.querySelector('[data-pw280-status]');
    status.textContent='Refreshing…';
    const response=await fetch(endpoint+(siteId?'?site='+encodeURIComponent(siteId):''),{credentials:'same-origin',headers:{'Accept':'application/json'}});
    const data=await response.json();
    if(!response.ok||data.ok===false)throw new Error(data.error||'Physical World dashboard unavailable.');
    const wrapper=data.physical_world||{};
    render(wrapper.dashboard||{});
    status.textContent='Governed Cloud mirror · view only';
  }

  root.querySelector('[data-pw280-refresh]')?.addEventListener('click',()=>load(root.querySelector('[data-pw280-site-select]')?.value||initialSite).catch(err=>root.querySelector('[data-pw280-status]').textContent=err.message));
  root.querySelector('[data-pw280-site-select]')?.addEventListener('change',(event)=>{
    const url=new URL(window.location.href);
    if(event.target.value)url.searchParams.set('site',event.target.value);else url.searchParams.delete('site');
    window.location.assign(url.toString());
  });
  load(initialSite).catch(err=>root.querySelector('[data-pw280-status]').textContent=err.message);
})();
