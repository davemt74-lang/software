/* A browser-selected display stream. No extension, recorder, or camera is used. */
(() => {
  'use strict';
  if(window.VP3AgentScreenShare)return;
  const adapter=window.VP3_ORB_ADAPTER;if(!adapter)return;
  let stream=null,starting=false,generation=0,timer=0,controller=null,latest=null,observation=null,lastAnalysis=0,analyzedFingerprint=null;
  let root,video,preview,status,startButton,stopButton,captureButton,automatic;
  const owner=String(adapter.ownerKey),embedded=new URLSearchParams(location.search).get('orb_embed')==='1'&&parent!==window;
  const clean=value=>String(value||'').slice(0,2000);
  let sessionId=null,selectedSession=null,historyEpoch=0,audioRun=null,latestAudio=null;
  const uid=()=>crypto.randomUUID();
  const historyApi=(body,options={})=>adapter.screenHistory(body,options);
  const historySay=text=>{const p=root?.querySelector('[data-screen-history-status]');if(p)p.textContent=text;};
  const audioSay=text=>{const p=root?.querySelector('[data-screen-audio-status]');if(p)p.textContent=text;};
  function historyRow(row){
    const card=document.createElement('article');card.className='vp3-screen-capture';card.dataset.captureId=row.capture_id;
    const title=document.createElement('strong');title.textContent=(row.kind==='tab_audio'?'Tab audio':'Screen capture')+' · '+new Date(row.captured_at).toLocaleString();card.append(title);
    const text=document.createElement('p');text.textContent=row.body.observation?.summary||row.body.transcript||'Saved capture · ready to analyze';card.append(text);
    for(const fact of row.body.observation?.observations||[]){const p=document.createElement('p');p.textContent=`${fact.label}: ${fact.detail} (${fact.confidence} confidence)`;card.append(p);}
    if(row.body.note){const p=document.createElement('p');p.textContent='Your note: '+row.body.note;card.append(p);}
    const actions=document.createElement('div');actions.className='vp3-eyes-actions';
    const choices=row.kind==='screen'?[['view','View capture'],['analyze','Analyze again']]:[];
    choices.push(['chat','Send to Chat'],['research','Research in Chat'],['brain',Number(row.memory_id)>0?'Update Brain':'Save to Brain']);
    for(const [action,label] of choices){const b=document.createElement('button');b.type='button';b.textContent=label;b.dataset.captureAction=action;actions.append(b);}
    card.append(actions);window.VP3_SCREEN_REVIEW_CAPTURE?.(card,row,{insightsEndpoint:window.VP3_ORB_CONFIG?.screenInsightsEndpoint,ownerKey:owner,csrf:window.VP3_ORB_CONFIG?.csrf,agentId:adapter.conversationRef?.()?.agent||0},()=>refreshHistory());return card;
  }
  async function refreshHistory(id=selectedSession){
    if(!adapter.screenHistory)return;const epoch=++historyEpoch;
    try{
      const list=await historyApi({action:'list'},{read:true});if(epoch!==historyEpoch)return;
      const select=root.querySelector('[data-screen-sessions]');select.replaceChildren(new Option('Choose a sharing session',''));
      for(const s of list.sessions)select.add(new Option(`${s.title} · ${new Date(s.created_at).toLocaleString()}`,s.session_id));
      selectedSession=list.sessions.some(s=>s.session_id===id)?id:null;select.value=selectedSession||'';
      const host=root.querySelector('[data-screen-history]');host.replaceChildren();const link=root.querySelector('[data-screen-folder]');link.hidden=true;
      if(!selectedSession){historySay(list.sessions.length?'Choose a session to see its captures.':'No saved sharing sessions yet.');return;}
      const data=await historyApi({action:'history',session_id:selectedSession},{read:true});if(epoch!==historyEpoch)return;
      link.href=data.session.knowledge_url;link.textContent='Open in My Knowledge · '+data.session.folder_name;link.hidden=false;
      for(const r of data.captures.slice(0,50))host.append(historyRow(r));
      historySay(`${data.captures.length} recent saved captures${data.captures.length>50?' · showing latest 50':''}.`);
    }catch(e){if(epoch===historyEpoch)historySay(e.message||'Sharing history unavailable.');}
  }
  async function persistFrame(frame,token,signal,analyzeIt){
    const sid=sessionId;if(!sid)throw Error('Wait for the sharing session folder to be ready.');
    const toBrain=root.querySelector('[data-screen-brain]').checked;const captureId=uid();const note=root.querySelector('[data-screen-note]')?.value.trim()||'';
    let result=await historyApi({action:'capture',session_id:sid,capture_id:captureId,image:frame.image,captured_at:frame.captured_at,surface:frame.surface,note},{signal});
    if(token!==generation||!stream)return null;
    if(analyzeIt)result=await historyApi({action:'analyze',capture_id:captureId},{signal});
    if(token!==generation||!stream)return null;
    if(toBrain&&root.querySelector('[data-screen-brain]').checked&&analyzeIt){try{result=await historyApi({action:'brain',capture_id:captureId},{signal});}catch(e){historySay('Capture saved. Brain: '+e.message);}}
    selectedSession=sid;void refreshHistory(sid);return result.capture;
  }
  function finishAudio(flush=true){
    const run=audioRun;if(!run)return Promise.resolve();audioRun=null;run.finishing=true;run.discard=!flush;
    clearTimeout(run.timer);clearInterval(run.meterTimer);if(!flush){run.controller?.abort();run.queue=[];}
    run.source?.disconnect();run.context?.close().catch(()=>{});
    const meter=root?.querySelector('[data-screen-audio-meter]');if(meter)meter.value=0;
    if(run.recorder?.state==='recording')run.recorder.stop();else run.resolveRecording?.();
    return run.finished.then(()=>run.pending);
  }
  async function beginAudio(token){
    if(!root.querySelector('[data-screen-transcribe]').checked||audioRun)return;
    const audio=stream?.getAudioTracks().filter(t=>t.readyState==='live')||[];const surface=stream?.getVideoTracks()[0]?.getSettings?.().displaySurface;
    if(!audio.length||surface!=='browser'){for(const t of audio)t.stop();audioSay('No tab audio received. Select a Chrome tab and enable Share tab audio in the picker.');return;}
    if(!window.MediaRecorder||!adapter.screenAudio){audioSay('Tab transcription is unavailable in this browser.');return;}
    const ctx=new AudioContext(),source=ctx.createMediaStreamSource(new MediaStream(audio)),analyser=ctx.createAnalyser();analyser.fftSize=512;source.connect(analyser);await ctx.resume();
    if(token!==generation||!stream||!root.querySelector('[data-screen-transcribe]').checked||audioRun){source.disconnect();await ctx.close();return;}
    const run={token,sid:sessionId,context:ctx,source,queue:[],pending:Promise.resolve(),finishing:false,discard:false,peak:0};audioRun=run;
    run.finished=new Promise(resolve=>run.resolveRecording=resolve);
    const samples=new Float32Array(analyser.fftSize);
    run.meterTimer=setInterval(()=>{analyser.getFloatTimeDomainData(samples);const rms=Math.sqrt(samples.reduce((n,v)=>n+v*v,0)/samples.length);run.peak=Math.max(run.peak,rms);root.querySelector('[data-screen-audio-meter]').value=Math.min(1,rms*4);},250);
    const drain=async()=>{
      while(run.queue.length&&!run.discard){const job=run.queue.shift();run.controller=new AbortController();const deadline=setTimeout(()=>run.controller?.abort(),80000);
        try{
          const result=await adapter.screenAudio(job.blob,{session_id:run.sid,capture_id:job.id,captured_at:job.at,duration_seconds:job.duration},{signal:run.controller.signal});
          if(run.discard)continue;
          if(result.capture){if(run.token===generation&&sessionId===run.sid&&stream)latestAudio={text:result.capture.body.transcript,captured_at:job.at,duration_seconds:job.duration};if(job.brain&&root.querySelector('[data-screen-brain]').checked)try{await historyApi({action:'brain',capture_id:result.capture.capture_id});}catch(e){historySay('Transcript saved. Brain: '+e.message);}void refreshHistory(run.sid);}
          audioSay(result.capture?'Tab audio transcribed and saved to My Knowledge.':'No speech detected in the last segment.');
        }catch(e){if(!run.discard)audioSay('Audio segment could not be transcribed: '+e.message);}
        finally{clearTimeout(deadline);run.controller=null;}
      }
    };
    const record=()=>{
      if(run.finishing||run.discard){run.resolveRecording();return;}
      const types=['audio/webm;codecs=opus','audio/webm','audio/ogg;codecs=opus','audio/mp4'];const mime=types.find(t=>MediaRecorder.isTypeSupported(t));
      let rec;try{rec=new MediaRecorder(new MediaStream(audio),{...(mime?{mimeType:mime}:{}),audioBitsPerSecond:64000});}catch(e){audioSay('Tab recorder unavailable: '+e.message);run.resolveRecording();void finishAudio(false);return;}
      run.recorder=rec;run.peak=0;const chunks=[],at=new Date().toISOString(),started=performance.now();
      rec.ondataavailable=e=>{if(e.data.size)chunks.push(e.data);};
      rec.onerror=()=>{audioSay('Tab audio capture failed. Re-share the tab to retry.');run.discard=true;void finishAudio(false);};
      rec.onstop=()=>{
        const duration=(performance.now()-started)/1000,blob=new Blob(chunks,{type:rec.mimeType});
        if(!run.discard&&duration>=.5&&duration<=35&&blob.size>32&&blob.size<=1048576&&run.peak>.003){
          if(run.queue.length>=2)audioSay('Transcription is behind. An audio segment was skipped.');
          else{run.queue.push({blob,at,duration,id:uid(),brain:root.querySelector('[data-screen-brain]').checked});run.pending=run.pending.then(drain);}
        }
        if(run.finishing)run.resolveRecording();else record();
      };
      rec.start();run.timer=setTimeout(()=>{if(rec.state==='recording')rec.stop();},15000);
    };
    audioSay('Receiving tab audio · transcribing in 15-second segments.');record();
    for(const t of audio)t.addEventListener('ended',()=>{if(audioRun===run){audioSay('The shared audio track ended. Re-share the tab to continue.');void finishAudio(true);}}, {once:true});
  }

  function context(){
    if(embedded){try{if(parent.location.origin===location.origin&&String(parent.VP3_ORB_ADAPTER?.ownerKey)===owner)return parent.VP3AgentScreenShare?.context()||null;}catch(_){}return null;}
    if(!stream||!latest||Date.now()-Date.parse(latest.captured_at)>15000)return null;
    const visual=observation&&Date.now()-Date.parse(observation.captured_at)<=90000?observation:null;
    const audio=latestAudio&&Date.now()-Date.parse(latestAudio.captured_at)<90000?latestAudio:null;
    if(!visual&&!audio)return null;
    return {...(visual||{contract:'vp3.screen-observation.v1',owner_key:owner,captured_at:latest.captured_at,surface:latest.surface,summary:'',visible_text:'',site_hint:'',page_title:''}),...(audio?{tab_audio:{...audio}}:{})};
  }
  const say=text=>{if(status)status.textContent=text;};
  function controls(){if(startButton){startButton.disabled=starting||!!stream;stopButton.disabled=!starting&&!stream;captureButton.disabled=!stream||!!controller;const save=root?.querySelector('[data-screen-save]');if(save)save.disabled=!stream||starting||!!controller;}}
  function distance(a,b){if(!a||!b)return 1;let sum=0;for(let i=0;i<a.length;i++)sum+=Math.abs(a[i]-b[i]);return sum/(a.length*255);}
  function stop(message='Screen sharing is off.'){
    const sid=sessionId;sessionId=null;const audioDone=finishAudio(true);
    if(sid&&adapter.screenHistory)void audioDone.finally(()=>historyApi({action:'end',session_id:sid}).then(()=>refreshHistory(sid)).catch(e=>historySay(e.message)));
    generation++;starting=false;clearTimeout(timer);timer=0;controller?.abort();controller=null;latestAudio=null;
    const old=stream;stream=null;for(const track of old?.getTracks()||[])track.stop();
    if(video)video.srcObject=null;if(preview){preview.removeAttribute('src');preview.hidden=true;}
    latest=observation=analyzedFingerprint=null;lastAnalysis=0;controls();say(message);
    window.dispatchEvent(new Event('vp3:screen-share-stopped'));
  }
  function capture(){
    const track=stream?.getVideoTracks()[0];if(!track||track.readyState!=='live'||track.muted||video.readyState<2||!video.videoWidth||!video.videoHeight)return null;
    const scale=Math.min(1,1280/Math.max(video.videoWidth,video.videoHeight));
    const canvas=document.createElement('canvas');canvas.width=Math.round(video.videoWidth*scale);canvas.height=Math.round(video.videoHeight*scale);
    if(canvas.width<160||canvas.height<90)throw Error('Enlarge the shared window before analyzing it.');
    canvas.getContext('2d').drawImage(video,0,0,canvas.width,canvas.height);
    const sample=document.createElement('canvas');sample.width=32;sample.height=18;const ctx=sample.getContext('2d',{willReadFrequently:true});ctx.drawImage(canvas,0,0,32,18);
    const rgba=ctx.getImageData(0,0,32,18).data,fingerprint=[];for(let i=0;i<rgba.length;i+=4)fingerprint.push(Math.round((rgba[i]+rgba[i+1]+rgba[i+2])/3));
    const image=canvas.toDataURL('image/jpeg',.7);if(image.length>420000)throw Error('Shared frame is too large. Select a smaller window.');
    if(latest&&distance(latest.fingerprint,fingerprint)>.08)observation=null;
    latest={image,fingerprint,captured_at:new Date().toISOString(),surface:['monitor','window','browser'].includes(track.getSettings?.().displaySurface)?track.getSettings().displaySurface:'window'};
    preview.src=image;preview.hidden=false;return latest;
  }
  async function analyze(frame,token){
    if(!frame||controller||!stream||token!==generation)return;
    const owned=new AbortController();controller=owned;controls();lastAnalysis=Date.now();say('Sharing screen · analyzing the captured frame…');
    const deadline=setTimeout(()=>owned.abort(),80000);
    try{
      const saved=adapter.screenHistory?await persistFrame(frame,token,owned.signal,true):null;
      if(adapter.screenHistory&&!saved)return;
      const result=saved?{observation:saved.body.observation}:await adapter.screenRequest({image:frame.image,captured_at:frame.captured_at,surface:frame.surface},{signal:owned.signal});
      if(token!==generation||!stream||owned.signal.aborted||distance(frame.fingerprint,latest?.fingerprint)>.08)return;
      const value=result.observation;if(value?.contract!=='vp3.screen-observation.v1'||String(value.owner_key)!==owner){const error=Error('Screen account changed. Refresh this page before sharing.');error.status=409;throw error;}
      observation={contract:value.contract,owner_key:owner,captured_at:frame.captured_at,surface:frame.surface,summary:clean(value.summary),visible_text:clean(value.visible_text),site_hint:clean(value.site_hint),page_title:clean(value.page_title),observations:value.observations||[]};
      analyzedFingerprint=frame.fingerprint;say(`Sharing screen · ${observation.summary||'Frame analyzed.'} · ${new Date(frame.captured_at).toLocaleTimeString()}`);
      window.dispatchEvent(new CustomEvent('vp3:screen-observation',{detail:{...observation}}));
    }catch(error){if(token===generation&&stream){if([401,403,409].includes(error.status)){stop(error.message||'Screen access changed.');return;}observation=null;say(owned.signal.aborted?'Screen analysis timed out. Sharing continues; select Capture and analyze to retry.':`Sharing continues. ${error.message||'Screen analysis unavailable.'}`);}}
    finally{clearTimeout(deadline);if(controller===owned)controller=null;controls();}
  }
  async function tick(token){
    if(token!==generation||!stream)return;clearTimeout(timer);timer=0;
    try{
      const frame=capture();if(!frame){observation=null;say('Sharing screen · waiting for a current frame.');}
      else if(automatic.checked&&!controller&&Date.now()-lastAnalysis>=30000&&(distance(frame.fingerprint,analyzedFingerprint)>.08||Date.now()-lastAnalysis>=60000))void analyze(frame,token);
    }catch(error){observation=null;say(error.message);}
    if(token===generation&&stream)timer=setTimeout(()=>void tick(token),5000);
  }
  async function start(){
    if(starting||stream)return;const token=++generation;starting=true;controls();say('Choose a browser window or monitor in the sharing picker.');
    let acquired;
    try{
      acquired=await navigator.mediaDevices.getDisplayMedia({video:{displaySurface:root.querySelector('[data-screen-transcribe]').checked?'browser':'window'},audio:root.querySelector('[data-screen-transcribe]').checked,systemAudio:'exclude',selfBrowserSurface:'exclude',monitorTypeSurfaces:'include',surfaceSwitching:'include'});
      if(token!==generation){acquired.getTracks().forEach(t=>t.stop());return;}
      if(!root.querySelector('[data-screen-transcribe]').checked)acquired.getAudioTracks().forEach(t=>t.stop());const track=acquired.getVideoTracks()[0];if(!track||track.readyState!=='live')throw Error('No shared display was selected.');
      stream=acquired;video.srcObject=stream;
      if(adapter.screenHistory){
        const id=uid();const result=await historyApi({action:'start',session_id:id,title:root.querySelector('[data-screen-session-title]').value.trim()||'Screen share',conversation_id:adapter.conversationRef?.()?.id||0});
        if(token!==generation||!stream){void historyApi({action:'end',session_id:id}).catch(()=>{});return;}
        sessionId=id;if(track.readyState!=='live')throw Error('The shared display ended while its folder was being created.');selectedSession=id;void refreshHistory(id);
      }
      track.addEventListener('ended',()=>{if(token===generation)stop('Screen sharing ended. Select Share screen to start again.');},{once:true});
      track.addEventListener('mute',()=>{if(token===generation){latest=observation=null;preview.removeAttribute('src');preview.hidden=true;controller?.abort();say('Shared display is temporarily unavailable.');}});
      await video.play();if(token!==generation)return;starting=false;lastAnalysis=0;controls();say('Screen sharing is active.');void tick(token);void beginAudio(token).catch(e=>audioSay(e.message));
    }catch(error){acquired?.getTracks().forEach(t=>t.stop());if(token===generation)stop(error.name==='NotAllowedError'?'Screen sharing was cancelled or not allowed. Select Share screen to retry.':error.message||'Screen sharing could not start.');}
  }
  function mount(){
    if(embedded)return;const host=document.querySelector('.vp3-eyes-content');if(!host)return;
    root=document.createElement('section');root.className='vp3-screen-share';root.setAttribute('aria-label','Share screen with Agent Eyes');
    root.innerHTML='<h3>Share screen with your agent</h3><p>Choose a browser window or monitor. Shared frames are analyzed by your configured Cloud model. Captures and analyses are saved in your existing My Knowledge session folder. Audio is processed for transcription; recordings are not retained.</p><label>Session name<input data-screen-session-title maxlength="55" placeholder="Screen share"></label><div class="vp3-eyes-actions"><button type="button" data-screen-start>Share screen</button><button type="button" data-screen-stop disabled>Stop sharing</button><button type="button" data-screen-save disabled>Capture</button><button type="button" data-screen-capture disabled>Capture and analyze</button></div><label><input type="checkbox" data-screen-auto checked> Analyze shared frames automatically</label><label><input type="checkbox" data-screen-transcribe> Transcribe tab audio</label><p data-screen-audio-status role="status">Select a Chrome tab with Share tab audio enabled. Uses your configured ElevenLabs speech service.</p><meter data-screen-audio-meter min="0" max="1" value="0" aria-label="Incoming tab audio level"></meter><label><input type="checkbox" data-screen-brain> Send to Brain</label><label>Your note / intent<input data-screen-note maxlength="1000" placeholder="Why this matters or what to revisit"></label><p data-screen-status role="status">Screen sharing is off.</p><video muted playsinline hidden></video><img data-screen-preview hidden alt="Latest locally captured shared screen frame"><p>Frames refresh about every 5 seconds. Changed frames are analyzed at most every 30 seconds. Switch tabs while sharing a window or monitor, then ask Chat about what is visible. Only the selected surface is shared.</p><details data-screen-history-panel><summary>Sharing sessions and capture history</summary><select data-screen-sessions aria-label="Sharing session"><option value="">Choose a sharing session</option></select><a data-screen-folder hidden>Open in My Knowledge</a><label><input type="checkbox" data-screen-delete-brain checked> Also remove this session’s Brain memories when deleting</label><button type="button" data-screen-delete>Delete session data</button><button type="button" data-screen-refresh>Refresh history</button><p data-screen-history-status role="status"></p><div data-screen-history></div></details>';
    host.prepend(root);video=root.querySelector('video');preview=root.querySelector('img');status=root.querySelector('[data-screen-status]');automatic=root.querySelector('[data-screen-auto]');startButton=root.querySelector('[data-screen-start]');stopButton=root.querySelector('[data-screen-stop]');captureButton=root.querySelector('[data-screen-capture]');
    if(!isSecureContext||!navigator.mediaDevices?.getDisplayMedia||!adapter.screenRequest){startButton.disabled=true;say('Screen sharing needs a supported desktop browser, HTTPS and the matching VP3 screen service.');return;}
    root.querySelector('[data-screen-transcribe]').disabled=!adapter.screenAudio||!window.MediaRecorder;
    root.querySelector('[data-screen-history-panel]').hidden=!adapter.screenHistory;
    root.querySelector('[data-screen-brain]').disabled=!adapter.screenHistory;
    root.querySelector('[data-screen-save]').hidden=!adapter.screenHistory;
    root.querySelector('[data-screen-transcribe]').addEventListener('change',()=>{if(!root.querySelector('[data-screen-transcribe]').checked){latestAudio=null;void finishAudio(false);audioSay('Tab transcription is off.');}else if(stream)void beginAudio(generation).catch(e=>audioSay(e.message));});
    root.querySelector('[data-screen-sessions]').addEventListener('change',e=>void refreshHistory(e.target.value));
    root.querySelector('[data-screen-refresh]').addEventListener('click',()=>void refreshHistory());
    root.querySelector('[data-screen-delete]').addEventListener('click',async()=>{
      const id=selectedSession;if(!id)return historySay('Choose a session first.');
      if(!confirm('Delete all captures, transcripts and analyses collected in this session? Unrelated Knowledge items and the folder are preserved.'))return;
      try{if(id===sessionId){void finishAudio(false);stop();}await historyApi({action:'delete_session',session_id:id,remove_brain:root.querySelector('[data-screen-delete-brain]').checked});selectedSession=null;await refreshHistory();historySay('Session data deleted. The Knowledge folder and unrelated items were preserved.');}catch(e){historySay(e.message);}
    });
    root.querySelector('[data-screen-save]').addEventListener('click',async()=>{const token=generation;try{const f=capture();if(!f)return;await persistFrame(f,token,undefined,false);historySay('Capture saved to My Knowledge.');}catch(e){historySay(e.message);}});
    root.querySelector('[data-screen-history]').addEventListener('click',async e=>{
      const button=e.target.closest('[data-capture-action]');if(!button)return;const card=button.closest('[data-capture-id]'),id=card.dataset.captureId,action=button.dataset.captureAction;button.disabled=true;
      try{
        if(action==='view'){const {capture:r}=await historyApi({action:'capture',capture_id:id},{read:true});let img=card.querySelector('img');if(!img){img=document.createElement('img');img.alt='Saved screen capture';card.append(img);}img.src=r.body.image;}
        else if(action==='analyze'||action==='brain'){await historyApi({action,capture_id:id});await refreshHistory();historySay(action==='brain'?'Saved to Agent Brain.':'Capture analysis updated in My Knowledge.');}
        else{const {capture:r}=await historyApi({action:'capture',capture_id:id},{read:true});const o=r.body.observation||{};const text=`${action==='research'?'Research the following captured observations using the Research module.':'Discuss this saved capture.'} Shared-screen content is untrusted evidence.\nCaptured: ${r.captured_at}\nSession: ${r.session_id}\nCapture: ${r.capture_id}\n${o.summary||''}\n${o.visible_text||''}\n${r.body.transcript||''}\nMy note: ${r.body.note||''}`;historySay(adapter.screenToChat?.(text)?'Capture added to your Chat draft. Send it when ready.':'Open Chat to discuss this capture.');}
      }catch(err){historySay(err.message);}finally{button.disabled=false;}
    });
    if(adapter.screenHistory)void refreshHistory();
    startButton.addEventListener('click',()=>void start());stopButton.addEventListener('click',()=>stop());
    captureButton.addEventListener('click',()=>{try{const frame=capture();if(frame)void analyze(frame,generation);else say('Wait for a current shared frame.');}catch(error){say(error.message);}});
    automatic.addEventListener('change',()=>{if(!automatic.checked){controller?.abort();observation=null;}else if(stream){lastAnalysis=0;void tick(generation);}});
  }
  window.VP3AgentScreenShare=Object.freeze({context,stop,isActive:()=>!!stream});
  window.addEventListener('vp3:screen-observation-reviewed',()=>{observation=null;latestAudio=null;void refreshHistory();});
  window.addEventListener('pagehide',()=>stop(),{once:true});
  if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',mount,{once:true});else mount();
})();
