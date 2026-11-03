(() => {
  'use strict';
  window.VP3_SCREEN_REVIEW_CAPTURE=(card,row,cfg,refresh)=>{
    const form=document.createElement('form'),select=document.createElement('select'),note=document.createElement('input'),button=document.createElement('button'),status=document.createElement('p');
    form.className='vp3-screen-review';select.setAttribute('aria-label','Observation status');
    for(const [value,label] of [['active','Current observation'],['incorrect','Incorrect'],['outdated','Outdated'],['irrelevant','Not relevant']])select.add(new Option(label,value));select.value=row.review?.state||'active';
    note.type='text';note.maxLength=1000;note.placeholder='Correction or clarification';note.setAttribute('aria-label','Correction or clarification');note.value=row.review?.note||'';button.type='submit';button.textContent='Save review';status.setAttribute('role','status');form.append(select,note,button,status);card.append(form);
    form.addEventListener('submit',async e=>{e.preventDefault();button.disabled=true;try{const r=await fetch(cfg.insightsEndpoint||cfg.endpoint,{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json'},body:JSON.stringify({action:'review_capture',owner_key:String(cfg.ownerKey),csrf_token:cfg.csrf,agent_id:Number(cfg.agentId)||0,capture_id:row.capture_id,revision:Number(row.revision),state:select.value,note:note.value})});const data=await r.json();if(!r.ok||!data.ok||String(data.owner_key)!==String(cfg.ownerKey))throw Error(data.error||'Observation review failed.');status.textContent='Review saved. Prior Brain promotion was removed; restore and save again if appropriate.';window.dispatchEvent(new CustomEvent('vp3:screen-observation-reviewed',{detail:{captureId:row.capture_id,state:select.value}}));await refresh?.();}catch(err){status.textContent=err.message;}finally{button.disabled=false;}});
  };
})();
