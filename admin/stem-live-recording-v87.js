(() => {
  'use strict';
  const cfg=window.STONEFELLOW_STEM_STUDIO;
  if(!cfg || !cfg.projectEndpoint)return;
  const masterButton=document.getElementById('masterLiveRecordingToggle');
  const inspectorToggle=document.getElementById('inspectorLiveRecording');
  const storageKey=`stonefellow:live-record:v87:${Number(cfg.userId||0)}:${Number(cfg.trackId||0)}`;
  let masterArmed=false;
  let trackArmed=new Set();
  let sessions=[];
  let refreshPending=false;
  try{const saved=JSON.parse(localStorage.getItem(storageKey)||'{}');masterArmed=!!saved.master;trackArmed=new Set((saved.tracks||[]).map(Number).filter(Boolean));}catch(e){}
  const runtime=()=>window.STONEFELLOW_STUDIO_RUNTIME_V87;
  const save=()=>{try{localStorage.setItem(storageKey,JSON.stringify({master:masterArmed,tracks:[...trackArmed]}));}catch(e){}};
  const esc=v=>String(v??'').replaceAll('&','&amp;').replaceAll('<','&lt;').replaceAll('>','&gt;');
  function updateButtons(){
    masterButton?.classList.toggle('active',masterArmed); masterButton?.setAttribute('aria-pressed',masterArmed?'true':'false');
    if(masterButton)masterButton.textContent=masterArmed?'LIVE MIX · ON':'LIVE MIX';
    document.querySelectorAll('[data-stem-id]').forEach(row=>row.classList.toggle('live-record-armed',trackArmed.has(Number(row.dataset.stemId||0))));
    const selected=runtime()?.getSelectedStem?.();
    if(inspectorToggle){inspectorToggle.disabled=!selected;inspectorToggle.checked=!!selected&&trackArmed.has(Number(selected.id));}
  }
  const refreshCoreRecordingUi=()=>runtime()?.refreshRecordingUi?.();
  masterButton?.addEventListener('click',()=>{if(sessions.length)return;masterArmed=!masterArmed;save();updateButtons();refreshCoreRecordingUi();});
  inspectorToggle?.addEventListener('change',()=>{if(sessions.length)return;const stem=runtime()?.getSelectedStem?.();if(!stem)return;if(inspectorToggle.checked)trackArmed.add(Number(stem.id));else trackArmed.delete(Number(stem.id));save();updateButtons();refreshCoreRecordingUi();});
  window.setInterval(updateButtons,250); updateButtons(); window.setTimeout(refreshCoreRecordingUi,0);

  async function request(action,fields={}){
    const form=new FormData();form.append('csrf_token',String(cfg.csrf||''));form.append('action',action);form.append('track_id',String(cfg.trackId||0));
    Object.entries(fields).forEach(([k,v])=>{if(v!==undefined&&v!==null)form.append(k,String(v));});
    const r=await fetch(cfg.projectEndpoint,{method:'POST',credentials:'same-origin',body:form});
    const d=await r.json().catch(()=>({ok:false,error:'Invalid live recording response.'}));if(!r.ok||!d.ok)throw new Error(d.error||`Live recording failed (${r.status}).`);return d;
  }
  function pcm16(buffer,channels=2){
    const frames=buffer.length, sourceChannels=Math.max(1,buffer.numberOfChannels||1), out=new ArrayBuffer(frames*channels*2), view=new DataView(out);let offset=0;
    const data=[];for(let c=0;c<channels;c++)data.push(buffer.getChannelData(Math.min(c,sourceChannels-1)));
    for(let f=0;f<frames;f++)for(let c=0;c<channels;c++){const s=Math.max(-1,Math.min(1,Number(data[c][f]||0)));view.setInt16(offset,s<0?Math.round(s*0x8000):Math.round(s*0x7fff),true);offset+=2;}
    return new Uint8Array(out);
  }
  function nextName(key,label){let n=1;try{const k=`${storageKey}:take:${key}`;n=Number(localStorage.getItem(k)||0)+1;localStorage.setItem(k,String(n));}catch(e){}return `${label} · Take ${String(n).padStart(2,'0')}`;}
  async function uploadChunk(session,bytes){
    const index=session.chunkIndex++;const blob=new Blob([bytes],{type:'application/octet-stream'});
    session.uploadChain=session.uploadChain.then(async()=>{if(session.error)return;const form=new FormData();form.append('csrf_token',String(cfg.csrf||''));form.append('action','recording_chunk');form.append('track_id',String(cfg.trackId));form.append('recording_id',session.id);form.append('chunk_index',String(index));form.append('pcm',blob,`live-${index}.pcm`);try{const r=await fetch(cfg.projectEndpoint,{method:'POST',credentials:'same-origin',body:form});const d=await r.json().catch(()=>({ok:false,error:'Invalid upload response.'}));if(!r.ok||!d.ok)throw new Error(d.error||'Live recording upload failed.');}catch(e){session.error=e instanceof Error?e:new Error('Live recording upload failed.');}});
  }
  function flush(session,force=false){if(!session.pendingBytes||(!force&&session.pendingBytes<512*1024))return;const merged=new Uint8Array(session.pendingBytes);let off=0;session.pending.forEach(b=>{merged.set(b,off);off+=b.byteLength;});session.pending=[];session.pendingBytes=0;uploadChunk(session,merged);}
  async function makeSession(source,startOffset){
    const rt=runtime(),ctx=rt?.getContext?.();if(!ctx||!source.node)throw new Error('Live recording source is unavailable.');
    const started=await request('recording_start',{track_name:source.name,start_offset:startOffset,sample_rate:Math.round(ctx.sampleRate||48000),channels:2,session_tempo:rt.getSessionTempo(),device_label:source.deviceLabel,target_stem_id:0,create_new:1,capture_kind:source.kind,stem_role:source.role||'Other'});
    const processor=(ctx.createScriptProcessor||ctx.createJavaScriptNode).call(ctx,2048,2,2);const sink=ctx.createGain();sink.gain.value=0;const session={id:String(started.recording_id||''),source:source.node,processor,sink,name:source.name,kind:source.kind,chunkIndex:0,pending:[],pendingBytes:0,captured:0,uploadChain:Promise.resolve(),error:null,active:false,attached:false};
    if(!session.id)throw new Error('Live recording session was not created.');
    processor.onaudioprocess=e=>{if(!session.active)return;const bytes=pcm16(e.inputBuffer,2);session.pending.push(bytes);session.pendingBytes+=bytes.byteLength;session.captured+=bytes.byteLength;flush(session,false);};
    return session;
  }
  function captureSources(){
    const rt=runtime();if(!rt)return[];const result=[];
    if(masterArmed){const node=rt.getMasterSource?.();if(node)result.push({kind:'master',node,name:nextName('master','LIVE MIX'),role:'Other',deviceLabel:'Stonefellow Post-Master Output'});}
    [...trackArmed].forEach(id=>{const stem=rt.getStem?.(id),node=rt.getStemCaptureSource?.(id);if(stem&&node){result.push({kind:'stem',node,name:nextName(`stem-${id}`,`${stem.name||stem.label||'Track'} LIVE`),role:String(stem.role||'Other'),deviceLabel:`Stonefellow Post-Fader Output · ${stem.name||stem.label||id}`});}});
    return result;
  }
  function activateSession(session,ctx){
    if(!session||session.attached)return;
    session.source.connect(session.processor);session.processor.connect(session.sink);session.sink.connect(ctx.destination);session.attached=true;session.active=true;
  }
  function haltSession(session){
    if(!session)return;session.active=false;
    if(session.attached){try{session.source?.disconnect(session.processor);}catch(e){}session.attached=false;}
    session.processor.onaudioprocess=null;try{session.processor.disconnect();session.sink.disconnect();}catch(e){}flush(session,true);
  }
  async function startAll(startOffset=0){
    if(sessions.length)return;sessions=[];const rt=runtime();rt?.ensureAudioGraph?.();const ctx=rt?.getContext?.();if(ctx?.state==='suspended')await ctx.resume();const sources=captureSources();
    // Server sessions may take different amounts of time to initialize. Prepare
    // every one first, then attach all processors in one synchronous pass.
    const prepared=[];
    for(const source of sources){try{prepared.push(await makeSession(source,Number(startOffset||0)));}catch(e){console.error('Live source arm failed',source.kind,e);}}
    sessions=prepared;
    sessions.forEach(session=>activateSession(session,ctx));
    if(!sessions.length && hasArmedSources())throw new Error('No armed live output could be recorded.');
    if(sessions.length)rt?.setStatus?.(`LIVE REC · ${sessions.length} OUTPUT${sessions.length===1?'':'S'}`,'recording');
  }
  async function finalizeSession(session){
    await session.uploadChain;if(session.error)throw session.error;
    const status=await request('recording_status',{recording_id:session.id});if(Number(status.pcm_bytes||0)<2)throw new Error(`${session.name}: no audio was captured.`);
    return request('recording_finish',{recording_id:session.id,track_name:session.name});
  }
  async function stopAll(){
    const active=sessions.slice();sessions=[];
    // Critical synchronization barrier: detach every recorder before awaiting
    // any upload or server finalization.
    active.forEach(haltSession);
    const settled=await Promise.all(active.map(async session=>{try{return{ok:true,value:await finalizeSession(session)}}catch(e){console.error('Live recording save failed',e);try{await request('recording_cancel',{recording_id:session.id});}catch(_){}return{ok:false,error:e}}}));
    const results=settled.filter(item=>item.ok).map(item=>item.value);
    const failure=settled.find(item=>!item.ok);
    if(failure)runtime()?.setStatus?.(failure.error?.message||'LIVE SAVE FAILED','error');
    if(results.length){refreshPending=true;runtime()?.setStatus?.(`LIVE TAKE${results.length===1?'':'S'} SAVED · ${results.length}`,'ready');}
    return results;
  }
  function refreshAfterCoreSave(){if(refreshPending){refreshPending=false;window.setTimeout(()=>window.location.reload(),280);}}
  function refreshAfterStandalone(){if(refreshPending){refreshPending=false;window.setTimeout(()=>window.location.reload(),320);}}
  function hasArmedSources(){return masterArmed||trackArmed.size>0;}
  function hasActiveSessions(){return sessions.length>0;}
  window.STONEFELLOW_LIVE_RECORDING_V87={hasArmedSources,hasActiveSessions,startAll,stopAll,refreshAfterCoreSave,refreshAfterStandalone};
})();
