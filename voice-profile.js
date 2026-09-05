(() => {
  'use strict';
  const cfg=window.STONEFELLOW_VOICE_PROFILE||{};
  if(!cfg.endpoint||!cfg.csrf)return;
  const MAX_RECORDING_MS=120000;
  const $=id=>document.getElementById(id);
  const el={
    notice:$('voiceProfileNotice'),cloneStatus:$('cloneStatus'),recognitionStatus:$('recognitionStatus'),
    cloneVerifiedBadge:$('cloneVerifiedBadge'),recognitionDetail:$('recognitionDetail'),recognitionCopy:$('recognitionCopy'),
    sampleCount:$('sampleCount'),list:$('voiceSampleList'),selected:$('selectedSampleBox'),
    recognitionConsent:$('recognitionConsent'),cloningConsent:$('cloningConsent'),scope:$('recognitionScope'),savePrivacy:$('savePrivacy'),
    recorder:$('voiceRecorder'),start:$('startRecording'),stop:$('stopRecording'),state:$('recordingState'),timer:$('recordingTimer'),upload:$('voiceUpload'),
    createClone:$('createClone'),revokeClone:$('revokeClone'),previewClone:$('previewClone'),previewText:$('previewText'),previewPlayer:$('clonePreviewPlayer'),
    sidebar:$('chatSidebar'),backdrop:$('chatSidebarBackdrop'),openSidebar:$('openChatSidebar'),closeSidebar:$('closeChatSidebar')
  };
  let current=null,selectedSampleId=0,recorder=null,stream=null,chunks=[],startedAt=0,timerHandle=0,recordingTimeout=0,audioContext=null,analyser=null,meterHandle=0,previewUrl='';
  const meters=Array.from(el.recorder?.querySelectorAll('.voice-meter i')||[]);
  const clean=(value,limit=220)=>String(value??'').replace(/\s+/g,' ').trim().slice(0,limit);
  const systemName=clean(cfg.systemName||'System',80);

  function showNotice(message,type='success'){
    if(!el.notice)return;
    el.notice.textContent=message;el.notice.className=`voice-profile-notice ${type}`;el.notice.hidden=false;
    window.clearTimeout(showNotice.timer);showNotice.timer=window.setTimeout(()=>{el.notice.hidden=true;},6500);
  }
  async function jsonRequest(action,payload={}){
    const response=await fetch(cfg.endpoint,{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json'},body:JSON.stringify({csrf_token:cfg.csrf,action,...payload})});
    const data=await response.json().catch(()=>null);
    if(!response.ok||!data?.ok)throw new Error(data?.error||`Voice Profile request failed (${response.status}).`);
    return data;
  }
  async function loadState(){
    const response=await fetch(`${cfg.endpoint}?action=state`,{credentials:'same-origin',cache:'no-store'});
    const data=await response.json().catch(()=>null);
    if(!response.ok||!data?.ok)throw new Error(data?.error||'Voice Profile could not be loaded.');
    applyState(data.state);
  }
  function fmtDuration(ms){
    ms=Math.max(0,Number(ms||0));if(!ms)return 'Duration unavailable';
    const seconds=Math.round(ms/1000),m=Math.floor(seconds/60),s=seconds%60;return `${m}:${String(s).padStart(2,'0')}`;
  }
  function fmtBytes(bytes){
    bytes=Math.max(0,Number(bytes||0));if(bytes<1024)return `${bytes} B`;if(bytes<1048576)return `${(bytes/1024).toFixed(1)} KB`;return `${(bytes/1048576).toFixed(1)} MB`;
  }
  function renderSamples(samples){
    if(!el.list)return;
    el.sampleCount.textContent=`${samples.length} sample${samples.length===1?'':'s'}`;
    if(!samples.some(row=>Number(row.id)===selectedSampleId))selectedSampleId=0;
    if(!samples.length){el.list.innerHTML='<div class="voice-empty-state">No voice samples yet.</div>';return;}
    const sourceSampleId=Math.max(0,Number(current?.voice?.source_sample_id||0));
    el.list.innerHTML=samples.map(row=>{
      const id=Number(row.id||0),selected=id===selectedSampleId?' selected':'',source=id===sourceSampleId;
      return `<article class="voice-sample${selected}" data-sample-id="${id}">
        <div class="voice-sample-main">
          <div class="voice-sample-title"><strong>${row.source_type==='recorded'?'Recorded sample':'Uploaded sample'}</strong><span>${source?'Active clone source · ':''}${fmtDuration(row.duration_ms)} · ${fmtBytes(row.bytes)}</span></div>
          <audio controls preload="metadata" src="${String(row.url||'').replace(/"/g,'&quot;')}"></audio>
        </div>
        <div class="voice-sample-actions"><button type="button" data-select-sample="${id}">${selected?'Selected':'Use for clone'}</button><button type="button" class="delete" data-delete-sample="${id}">Delete</button></div>
      </article>`;
    }).join('');
  }
  function renderSelected(){
    const sample=(current?.samples||[]).find(row=>Number(row.id)===selectedSampleId)||null;
    if(!sample){el.selected.innerHTML='<span>No sample selected</span><strong>Select a sample above</strong>';return;}
    el.selected.innerHTML=`<span>${sample.source_type==='recorded'?'Recorded':'Uploaded'} · ${fmtDuration(sample.duration_ms)}</span><strong>Sample #${sample.id}</strong>`;
  }
  function applyState(next){
    current=next||{};
    const profile=current.profile||{},voice=current.voice||{},samples=Array.isArray(current.samples)?current.samples:[];
    el.recognitionConsent.checked=Boolean(profile.recognition_consent);
    el.cloningConsent.checked=Boolean(profile.cloning_consent);
    el.scope.value=['private','contacts','collaborators'].includes(profile.recognition_scope)?profile.recognition_scope:'private';
    const cloneReady=Boolean(voice.has_clone_binding&&voice.clone_enabled);
    const cloneVerified=Boolean(voice.clone_verified);
    const recognitionReady=Boolean(voice.has_recognition_binding&&voice.recognition_enabled&&voice.recognition_verified);
    el.cloneStatus.textContent=cloneReady?`Clone · ${cloneVerified?'Ready':'Verification pending'}`:'Clone · Not created';
    el.cloneStatus.className=`voice-status-pill ${cloneReady?(cloneVerified?'good':'warn'):''}`;
    el.recognitionStatus.textContent=recognitionReady?'Recognition · Ready':(profile.recognition_consent?'Recognition · Enrollment pending':'Recognition · Off');
    el.recognitionStatus.className=`voice-status-pill ${recognitionReady?'good':(profile.recognition_consent?'warn':'')}`;
    el.cloneVerifiedBadge.textContent=cloneReady?(cloneVerified?'Clone ready':'Verification pending'):'Not created';
    if(recognitionReady){el.recognitionDetail.textContent='Recognition ready';el.recognitionCopy.textContent='Your verified provider speaker identity can be used as conversational context within your selected privacy scope.';}
    else if(profile.recognition_consent){el.recognitionDetail.textContent='Consent enabled';el.recognitionCopy.textContent='Recognition privacy is configured. Provider speaker enrollment is still separate and has not been assumed from your clone.';}
    else{el.recognitionDetail.textContent='Recognition off';el.recognitionCopy.textContent='Enable recognition consent when you want ${systemName} to associate a verified provider voice match with your conversational identity.';}
    renderSamples(samples);renderSelected();
    el.createClone.disabled=cloneReady||!selectedSampleId||!profile.cloning_consent;
    el.revokeClone.disabled=!cloneReady;
    el.previewClone.disabled=!cloneReady;
  }
  async function uploadFile(file,sourceType='upload',durationMs=0){
    if(!file)return;
    if(file.size>26214400){showNotice('Voice samples are limited to 25 MB.','error');return;}
    const form=new FormData();form.append('csrf_token',cfg.csrf);form.append('action','upload_sample');form.append('source_type',sourceType);form.append('duration_ms',String(Math.max(0,Math.round(durationMs))));form.append('voice_sample',file,file.name||`voice-sample-${Date.now()}.webm`);
    el.state.textContent='Saving private voice sample…';
    try{
      const response=await fetch(cfg.endpoint,{method:'POST',credentials:'same-origin',body:form});
      const data=await response.json().catch(()=>null);
      if(!response.ok||!data?.ok)throw new Error(data?.error||'Voice sample upload failed.');
      applyState(data.state);el.state.textContent='Voice sample saved';showNotice('Voice sample saved privately.');
    }catch(error){el.state.textContent='Ready to record';showNotice(clean(error?.message||error),'error');}
  }
  function stopMeter(){
    if(meterHandle)cancelAnimationFrame(meterHandle);meterHandle=0;
    meters.forEach(bar=>{bar.style.height='12px';bar.style.opacity='';});
    try{audioContext?.close?.();}catch(error){}audioContext=null;analyser=null;
  }
  function startMeter(mediaStream){
    const Ctx=window.AudioContext||window.webkitAudioContext;if(!Ctx||!meters.length)return;
    audioContext=new Ctx();analyser=audioContext.createAnalyser();analyser.fftSize=256;audioContext.createMediaStreamSource(mediaStream).connect(analyser);
    const data=new Uint8Array(analyser.frequencyBinCount);
    const tick=()=>{analyser.getByteFrequencyData(data);let sum=0;for(const n of data)sum+=n;const level=Math.min(1,(sum/data.length)/105);meters.forEach((bar,index)=>{const distance=Math.abs(index-(meters.length-1)/2)/((meters.length-1)/2);const factor=Math.max(.18,level*(1-distance*.55));bar.style.height=`${10+factor*56}px`;bar.style.opacity=String(.25+factor*.75);});meterHandle=requestAnimationFrame(tick);};tick();
  }
  function updateTimer(){
    const elapsed=Math.max(0,Date.now()-startedAt),seconds=Math.floor(elapsed/1000);el.timer.textContent=`${String(Math.floor(seconds/60)).padStart(2,'0')}:${String(seconds%60).padStart(2,'0')}`;
  }
  async function beginRecording(){
    if(!navigator.mediaDevices?.getUserMedia||typeof MediaRecorder==='undefined'){showNotice('This browser does not support microphone recording. Upload an audio sample instead.','error');return;}
    try{
      stream=await navigator.mediaDevices.getUserMedia({audio:{echoCancellation:true,noiseSuppression:true,autoGainControl:true}});
      const types=['audio/webm;codecs=opus','audio/webm','audio/ogg;codecs=opus','audio/mp4'];let options={};for(const type of types){if(MediaRecorder.isTypeSupported?.(type)){options={mimeType:type};break;}}
      recorder=new MediaRecorder(stream,options);chunks=[];startedAt=Date.now();
      recorder.addEventListener('dataavailable',event=>{if(event.data?.size)chunks.push(event.data);});
      recorder.addEventListener('stop',async()=>{
        const duration=Date.now()-startedAt,type=recorder?.mimeType||chunks[0]?.type||'audio/webm';
        const blob=new Blob(chunks,{type});const ext=type.includes('ogg')?'ogg':type.includes('mp4')?'m4a':'webm';
        stream?.getTracks?.().forEach(track=>track.stop());stream=null;stopMeter();el.recorder.classList.remove('recording');el.start.disabled=false;el.stop.disabled=true;window.clearInterval(timerHandle);window.clearTimeout(recordingTimeout);recordingTimeout=0;el.timer.textContent='00:00';
        if(blob.size>0)await uploadFile(new File([blob],`voice-profile-${Date.now()}.${ext}`,{type}), 'recorded', duration);
      },{once:true});
      recorder.start(250);startMeter(stream);el.recorder.classList.add('recording');el.start.disabled=true;el.stop.disabled=false;el.state.textContent='Recording your voice…';updateTimer();timerHandle=window.setInterval(updateTimer,250);
      recordingTimeout=window.setTimeout(()=>{if(recorder?.state==='recording'){el.state.textContent='Two-minute recording limit reached — saving…';recorder.stop();}},MAX_RECORDING_MS);
    }catch(error){showNotice(error?.name==='NotAllowedError'?'Microphone permission was not granted.':clean(error?.message||error),'error');}
  }
  function endRecording(){if(recorder?.state==='recording'){el.state.textContent='Finishing recording…';recorder.stop();}}

  el.start?.addEventListener('click',()=>void beginRecording());el.stop?.addEventListener('click',endRecording);
  el.upload?.addEventListener('change',()=>{const file=el.upload.files?.[0];if(file)void uploadFile(file,'upload',0);el.upload.value='';});
  el.list?.addEventListener('click',event=>{
    const select=event.target.closest?.('[data-select-sample]');if(select){selectedSampleId=Number(select.dataset.selectSample||0);renderSamples(current?.samples||[]);renderSelected();applyState(current);return;}
    const del=event.target.closest?.('[data-delete-sample]');if(del){const id=Number(del.dataset.deleteSample||0);if(!id||!window.confirm('Delete this private voice sample?'))return;void (async()=>{try{const data=await jsonRequest('delete_sample',{sample_id:id});if(selectedSampleId===id)selectedSampleId=0;applyState(data.state);showNotice('Voice sample deleted.');}catch(error){showNotice(clean(error?.message||error),'error');}})();}
  });
  el.savePrivacy?.addEventListener('click',()=>void (async()=>{
    const before=current?.profile||{};const recognition=el.recognitionConsent.checked,cloning=el.cloningConsent.checked;
    if((recognition&&!before.recognition_consent)||(cloning&&!before.cloning_consent)){
      if(!window.confirm('Enable the selected Voice Profile permissions for your account? Voice recognition remains conversational context only.')){applyState(current);return;}
    }
    if(!cloning&&current?.voice?.has_clone_binding){showNotice('Revoke the active voice clone before disabling cloning consent.','error');applyState(current);return;}
    try{const data=await jsonRequest('save_privacy',{recognition_consent:recognition,cloning_consent:cloning,recognition_scope:el.scope.value});applyState(data.state);showNotice('Voice privacy settings saved.');}catch(error){applyState(current);showNotice(clean(error?.message||error),'error');}
  })());
  el.createClone?.addEventListener('click',()=>void (async()=>{
    if(!selectedSampleId)return;const ok=window.confirm('I confirm this selected recording contains my own voice. Create my ${systemName} voice clone and send this sample to ElevenLabs?');if(!ok)return;
    el.createClone.disabled=true;el.createClone.textContent='Creating Voice Clone…';
    try{const data=await jsonRequest('clone_from_sample',{sample_id:selectedSampleId,ownership_confirmed:true});applyState(data.state);showNotice('Your ${systemName} voice clone was created.');}catch(error){showNotice(clean(error?.message||error),'error');applyState(current);}finally{el.createClone.textContent='Create My Voice Clone';}
  })());
  el.revokeClone?.addEventListener('click',()=>void (async()=>{
    if(!window.confirm('Revoke and permanently delete your ${systemName} voice clone from ElevenLabs? Your private source samples will remain until you delete them.'))return;
    el.revokeClone.disabled=true;
    try{const data=await jsonRequest('revoke_clone');applyState(data.state);if(previewUrl){URL.revokeObjectURL(previewUrl);previewUrl='';}el.previewPlayer.pause();el.previewPlayer.hidden=true;showNotice('Voice clone revoked and deleted.');}catch(error){showNotice(clean(error?.message||error),'error');applyState(current);}
  })());
  el.previewClone?.addEventListener('click',()=>void (async()=>{
    el.previewClone.disabled=true;el.previewClone.textContent='Generating…';
    try{
      const response=await fetch(cfg.endpoint,{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json'},body:JSON.stringify({csrf_token:cfg.csrf,action:'preview_clone',text:el.previewText.value})});
      if(!response.ok){const data=await response.json().catch(()=>null);throw new Error(data?.error||'Voice preview failed.');}
      const blob=await response.blob();if(previewUrl)URL.revokeObjectURL(previewUrl);previewUrl=URL.createObjectURL(blob);el.previewPlayer.src=previewUrl;el.previewPlayer.hidden=false;await el.previewPlayer.play().catch(()=>{});
    }catch(error){showNotice(clean(error?.message||error),'error');}finally{el.previewClone.textContent='Preview Voice';el.previewClone.disabled=!current?.voice?.has_clone_binding;}
  })());

  const openSidebar=()=>{el.sidebar?.classList.add('open');el.backdrop?.classList.add('show');};const closeSidebar=()=>{el.sidebar?.classList.remove('open');el.backdrop?.classList.remove('show');};
  el.openSidebar?.addEventListener('click',openSidebar);el.closeSidebar?.addEventListener('click',closeSidebar);el.backdrop?.addEventListener('click',closeSidebar);
  window.addEventListener('beforeunload',()=>{window.clearTimeout(recordingTimeout);stream?.getTracks?.().forEach(track=>track.stop());if(previewUrl)URL.revokeObjectURL(previewUrl);});
  loadState().catch(error=>showNotice(clean(error?.message||error),'error'));
})();
