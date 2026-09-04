(() => {
  const cfg=window.STONEFELLOW_CHAT||{};
  if(!cfg.mediaEndpoint)return;
  const state={root:null,feeds:new Map(),devices:[],mic:null,voiceRecorder:null,voiceRecordStream:null,voiceChunks:[],voiceStarted:0,activeRecorders:new Map(),assets:[],pendingMode:'camera',pendingIndex:0,deviceListener:false};
  const esc=value=>String(value??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[c]));
  const mimeBase=mime=>String(mime||'').split(';')[0].toLowerCase();
  const supportedRecorderMime=kind=>{if(!window.MediaRecorder)return '';const list=kind==='video'?['video/webm;codecs=vp9,opus','video/webm;codecs=vp8,opus','video/webm','video/mp4']:['audio/webm;codecs=opus','audio/webm','audio/ogg;codecs=opus','audio/mp4'];return list.find(type=>MediaRecorder.isTypeSupported?.(type))||'';};
  function setStatus(message='',status=''){const el=state.root?.querySelector('[data-media-status]');if(!el)return;el.hidden=!message;el.textContent=message;el.dataset.state=status;}
  function ensurePanel(){
    if(state.root)return state.root;
    const root=document.createElement('div');root.className='chat-media-studio';root.hidden=true;
    root.innerHTML=`<section class="chat-media-panel" role="dialog" aria-modal="true" aria-label="Stonefellow camera and recording studio"><header class="chat-media-head"><div><span>Agent Brain · Capture</span><h2>Camera & Recording</h2></div><div class="chat-media-head-actions"><button type="button" data-media-refresh>Refresh Cameras</button><button type="button" data-media-voice class="primary">● Record Voice</button><button type="button" data-media-editor>Video Editor</button><button type="button" data-media-close aria-label="Close">×</button></div></header><div class="chat-media-body"><div class="chat-media-status" data-media-status hidden></div><div class="chat-media-feeds" data-media-feeds></div><section class="chat-media-library"><h3>Your recent media</h3><div class="chat-media-library-grid" data-media-library></div></section></div></section>`;
    document.body.appendChild(root);state.root=root;
    root.addEventListener('click',event=>{if(event.target===root||event.target.closest('[data-media-close]'))close();if(event.target.closest('[data-media-refresh]'))enableDevices(true);if(event.target.closest('[data-media-editor]'))location.href=cfg.videoEditorUrl;if(event.target.closest('[data-media-voice]'))toggleVoiceRecording();const retry=event.target.closest('[data-media-retry]');if(retry)retryCamera(Number(retry.dataset.mediaRetry));const photo=event.target.closest('[data-media-photo]');if(photo)takePhoto(Number(photo.dataset.mediaPhoto));const video=event.target.closest('[data-media-video]');if(video)toggleVideoRecording(Number(video.dataset.mediaVideo),video);});
    return root;
  }
  function releaseFeeds(){state.feeds.forEach(feed=>feed.stream.getTracks().forEach(track=>track.stop()));state.feeds.clear();state.activeRecorders.forEach(entry=>{try{if(entry.recorder.state!=='inactive')entry.recorder.stop();}catch(e){}});state.activeRecorders.clear();}
  function releaseMic(){if(state.voiceRecorder&&state.voiceRecorder.state!=='inactive'){try{state.voiceRecorder.stop();}catch(e){}}else{state.voiceRecordStream?.getTracks().forEach(track=>track.stop());state.voiceRecordStream=null;state.voiceRecorder=null;}state.mic?.getTracks().forEach(track=>track.stop());state.mic=null;}
  function close(){if(!state.root)return;state.root.hidden=true;releaseFeeds();releaseMic();}
  function pauseVoiceConversation(){const voice=document.getElementById('chatVoiceButton');if(voice?.getAttribute('aria-pressed')==='true')voice.click();try{speechSynthesis.cancel();}catch(e){}}
  async function ensureMic(){if(state.mic?.active)return state.mic;state.mic=await navigator.mediaDevices.getUserMedia({audio:{echoCancellation:true,noiseSuppression:true,autoGainControl:true},video:false});return state.mic;}
  function cameraErrorMessage(error){
    const name=String(error?.name||'');
    if(name==='NotAllowedError'||name==='SecurityError')return 'Camera permission is blocked for this site.';
    if(name==='NotFoundError'||name==='DevicesNotFoundError')return 'The camera was disconnected or is no longer available.';
    if(name==='NotReadableError'||name==='TrackStartError')return 'The camera was detected, but Windows/browser could not start it. It may be busy in another app or the driver may need to be re-opened.';
    if(name==='OverconstrainedError'||name==='ConstraintNotSatisfiedError')return 'The camera was detected but rejected the requested video mode.';
    if(name==='AbortError')return 'The camera start was interrupted. Retry the feed.';
    return error?.message||'The camera was detected but its video feed could not be opened.';
  }
  async function openCameraStream(device){
    const attempts=[
      {deviceId:{exact:device.deviceId},width:{ideal:1280},height:{ideal:720},frameRate:{ideal:30}},
      {deviceId:{exact:device.deviceId},width:{ideal:640},height:{ideal:480},frameRate:{ideal:24}},
      {deviceId:{exact:device.deviceId}},
    ];
    let lastError=null;
    for(const video of attempts){
      try{
        const stream=await navigator.mediaDevices.getUserMedia({video,audio:false});
        const track=stream.getVideoTracks()[0];
        const settings=track?.getSettings?.()||{};
        if(settings.deviceId&&device.deviceId&&settings.deviceId!==device.deviceId){stream.getTracks().forEach(t=>t.stop());throw Object.assign(new Error('Browser opened a different camera than requested.'),{name:'DeviceMismatchError'});}
        return stream;
      }catch(error){lastError=error;}
    }
    throw lastError||new Error('Camera feed could not be opened.');
  }
  function cameraCard(index,device){
    const feeds=state.root.querySelector('[data-media-feeds]');
    let card=feeds.querySelector(`[data-camera-index="${index}"]`);
    if(!card){card=document.createElement('article');card.className='chat-media-feed';card.dataset.cameraIndex=String(index);feeds.appendChild(card);}
    card.dataset.cameraState='connecting';
    card.innerHTML=`<div class="chat-media-feed-state"><strong>${esc(device.label||`Camera ${index}`)}</strong><span>Connecting…</span></div>`;
    return card;
  }
  async function connectCamera(index,device,card=cameraCard(index,device)){
    const prior=state.feeds.get(index);if(prior){prior.stream.getTracks().forEach(track=>track.stop());state.feeds.delete(index);}
    card.dataset.cameraState='connecting';
    try{
      const stream=await openCameraStream(device);
      state.feeds.set(index,{stream,device});
      card.dataset.cameraState='ready';
      card.innerHTML=`<video autoplay muted playsinline></video><footer><strong>${esc(device.label||`Camera ${index}`)}</strong><span class="chat-media-feed-ready">Ready</span><button type="button" data-media-photo="${index}">Take Photo</button><button type="button" data-media-video="${index}">● Record Video</button></footer>`;
      const video=card.querySelector('video');video.srcObject=stream;video.play?.().catch(()=>{});
      return true;
    }catch(error){
      card.dataset.cameraState='error';
      card.innerHTML=`<div class="chat-media-feed-state error"><strong>${esc(device.label||`Camera ${index}`)}</strong><span>${esc(cameraErrorMessage(error))}</span><small>${esc(String(error?.name||'CameraError'))}</small><button type="button" data-media-retry="${index}">Retry Camera</button></div>`;
      return false;
    }
  }
  async function retryCamera(index){
    const device=state.devices[index-1];if(!device)return setStatus('That camera is no longer detected. Refresh cameras.','error');
    const card=state.root?.querySelector(`[data-camera-index="${index}"]`);if(!card)return;
    setStatus(`Retrying ${device.label||`Camera ${index}`}…`,'working');
    let ok=await connectCamera(index,device,card);
    if(!ok&&state.feeds.size){
      // Some Windows USB/capture drivers will enumerate multiple cameras but fail
      // to start the second device while the first stream owns bandwidth/resources.
      // Give the requested camera one exclusive start, then reconnect the others.
      const reconnect=[...state.feeds.keys()].filter(other=>other!==index);
      reconnect.forEach(other=>{const feed=state.feeds.get(other);feed?.stream?.getTracks().forEach(track=>track.stop());state.feeds.delete(other);});
      setStatus(`Retrying ${device.label||`Camera ${index}`} with priority…`,'working');
      ok=await connectCamera(index,device,card);
      for(const other of reconnect){
        const otherDevice=state.devices[other-1],otherCard=state.root?.querySelector(`[data-camera-index="${other}"]`);
        if(otherDevice&&otherCard)await connectCamera(other,otherDevice,otherCard);
      }
    }
    const opened=state.feeds.size;
    setStatus(ok?`${device.label||`Camera ${index}`} is ready.`:`${device.label||`Camera ${index}`} is detected but its feed still cannot be opened.`,ok?'success':'error');
    document.dispatchEvent(new CustomEvent('stonefellow:camera-devices',{detail:{detected:state.devices.length,opened}}));
  }
  async function enableDevices(refresh=false){
    ensurePanel();setStatus('Scanning camera inputs…','working');
    if(!window.isSecureContext||!navigator.mediaDevices?.getUserMedia||!navigator.mediaDevices?.enumerateDevices){setStatus('Camera capture is unavailable in this browser context.','error');return;}
    if(refresh)releaseFeeds();
    try{
      const permission=await navigator.mediaDevices.getUserMedia({video:true,audio:false});permission.getVideoTracks().forEach(track=>track.stop());
      await new Promise(resolve=>setTimeout(resolve,120));
      const devices=await navigator.mediaDevices.enumerateDevices();
      const cameras=[...new Map(devices.filter(device=>device.kind==='videoinput').map(device=>[device.deviceId||`${device.groupId}:${device.label}`,device])).values()];
      state.devices=cameras;
      const feeds=state.root.querySelector('[data-media-feeds]');feeds.innerHTML='';state.feeds.clear();
      if(!cameras.length){setStatus('No camera inputs were reported by the browser. Reconnect the USB/capture device, then refresh cameras.','error');document.dispatchEvent(new CustomEvent('stonefellow:camera-devices',{detail:{detected:0,opened:0}}));return;}
      const cards=cameras.map((device,i)=>cameraCard(i+1,device));
      let opened=0;
      // If a specific camera launched the workspace, connect it first. This helps
      // external capture devices that are sensitive to USB bandwidth/start order.
      const order=cameras.map((_,i)=>i+1);
      const requested=Number(state.pendingIndex||0);
      if(requested>0&&requested<=order.length){order.splice(order.indexOf(requested),1);order.unshift(requested);}
      for(const index of order){if(await connectCamera(index,cameras[index-1],cards[index-1]))opened++;}
      const failed=cameras.length-opened;
      setStatus(failed?`${opened} of ${cameras.length} camera feeds ready. ${failed} detected camera${failed===1?' is':'s are'} waiting for retry.`:`${opened} camera feed${opened===1?'':'s'} ready.`,failed?'warning':'success');
      document.dispatchEvent(new CustomEvent('stonefellow:camera-devices',{detail:{detected:cameras.length,opened,devices:cameras.map((d,i)=>({index:i+1,label:d.label||`Camera ${i+1}`,deviceId:d.deviceId}))}}));
      if(!state.deviceListener){navigator.mediaDevices.addEventListener?.('devicechange',()=>{if(state.root&&!state.root.hidden)enableDevices(true);});state.deviceListener=true;}
      await handlePendingCapture();
    }catch(error){setStatus(cameraErrorMessage(error),'error');}
  }
  async function loadAssets(){try{const response=await fetch(`${cfg.mediaEndpoint}?action=list`,{credentials:'same-origin'}),data=await response.json();if(data.ok){state.assets=data.assets||[];renderAssets();}}catch(e){}}
  function renderAssets(){const grid=state.root?.querySelector('[data-media-library]');if(!grid)return;grid.innerHTML=state.assets.slice(0,12).map(asset=>{const thumb=asset.media_type==='photo'?`<img src="${esc(asset.url)}" alt="">`:asset.media_type==='video'?`<video src="${esc(asset.url)}" muted preload="metadata"></video>`:`<span class="media-icon">AUD</span>`;return `<article class="chat-media-library-card">${thumb}<div><strong>${esc(asset.title)}</strong><small>${esc(asset.media_type)}</small><div class="chat-media-library-actions"><a href="${esc(asset.editor_url)}">Video Editor</a></div></div></article>`;}).join('')||'<p>No captured media yet.</p>';}
  async function saveBlob(blob,mediaType,title,source,duration=0,metadata={}){
    if(!blob||blob.size<1)throw new Error('The recording did not contain any media data.');
    const mime=mimeBase(blob.type);setStatus(`Saving ${mediaType}…`,'working');const begin=new FormData();begin.append('action','begin');begin.append('csrf_token',cfg.csrf);begin.append('media_type',mediaType);begin.append('mime_type',mime);begin.append('title',title);begin.append('source',source);const beginRes=await fetch(cfg.mediaEndpoint,{method:'POST',credentials:'same-origin',body:begin}),beginData=await beginRes.json();if(!beginRes.ok||!beginData.ok)throw new Error(beginData.error||'Could not start media upload.');const size=Number(beginData.chunk_size||8388608),total=Math.ceil(blob.size/size);
    for(let i=0;i<total;i++){setStatus(`Saving ${mediaType}… ${Math.round((i/Math.max(1,total))*100)}%`,'working');const form=new FormData();form.append('action','chunk');form.append('csrf_token',cfg.csrf);form.append('token',beginData.token);form.append('index',String(i));form.append('total_chunks',String(total));form.append('chunk',blob.slice(i*size,Math.min(blob.size,(i+1)*size),mime),'chunk.bin');const res=await fetch(cfg.mediaEndpoint,{method:'POST',credentials:'same-origin',body:form}),data=await res.json();if(!res.ok||!data.ok)throw new Error(data.error||'Media upload failed.');}
    const finish=new FormData();finish.append('action','finish');finish.append('csrf_token',cfg.csrf);finish.append('token',beginData.token);finish.append('duration_seconds',String(duration));finish.append('metadata_json',JSON.stringify(metadata||{}));const finishRes=await fetch(cfg.mediaEndpoint,{method:'POST',credentials:'same-origin',body:finish}),finishData=await finishRes.json();if(!finishRes.ok||!finishData.ok)throw new Error(finishData.error||'Could not save media.');state.assets.unshift(finishData.asset);renderAssets();setStatus(`${title} saved to your library.`,'success');return finishData.asset;
  }
  async function takePhoto(index){const feed=state.feeds.get(index);if(!feed)return;const video=state.root.querySelector(`[data-camera-index="${index}"] video`);if(!video||!video.videoWidth)return setStatus('Camera is not ready yet.','error');const canvas=document.createElement('canvas');canvas.width=video.videoWidth;canvas.height=video.videoHeight;canvas.getContext('2d').drawImage(video,0,0,canvas.width,canvas.height);const blob=await new Promise(resolve=>canvas.toBlob(resolve,'image/jpeg',.92));if(!blob)return setStatus('Could not capture the photo.','error');const title=`Photo ${new Date().toLocaleString()}`;try{await saveBlob(blob,'photo',title,'agent_camera',0,{camera_index:index,camera_label:feed.device.label||`Camera ${index}`,width:canvas.width,height:canvas.height});}catch(error){setStatus(error.message||'Could not save photo.','error');}}
  async function toggleVideoRecording(index,button){const existing=state.activeRecorders.get(index);if(existing){if(existing.recorder.state!=='inactive')existing.recorder.stop();button.textContent='● Record Video';button.classList.remove('recording');return;}const feed=state.feeds.get(index);if(!feed)return;if(!window.MediaRecorder)return setStatus('This browser does not support MediaRecorder.','error');try{const mic=await ensureMic(),combined=new MediaStream([...feed.stream.getVideoTracks().map(track=>track.clone()),...mic.getAudioTracks().map(track=>track.clone())]),mime=supportedRecorderMime('video'),recorder=new MediaRecorder(combined,mime?{mimeType:mime}:undefined),chunks=[],started=performance.now();recorder.ondataavailable=e=>{if(e.data?.size)chunks.push(e.data);};recorder.onstop=async()=>{state.activeRecorders.delete(index);button.textContent='● Record Video';button.classList.remove('recording');const duration=(performance.now()-started)/1000,blob=new Blob(chunks,{type:recorder.mimeType||'video/webm'});combined.getTracks().forEach(track=>track.stop());try{await saveBlob(blob,'video',`Video ${new Date().toLocaleString()}`,'agent_camera',duration,{camera_index:index,camera_label:feed.device.label||`Camera ${index}`});}catch(error){setStatus(error.message||'Could not save video.','error');}};recorder.start(1000);state.activeRecorders.set(index,{recorder,started});button.textContent='■ Stop Video';button.classList.add('recording');setStatus(`Recording Camera ${index}…`,'success');}catch(error){setStatus(error.message||'Could not start video recording.','error');}}
  async function toggleVoiceRecording(){const button=state.root.querySelector('[data-media-voice]');if(state.voiceRecorder&&state.voiceRecorder.state!=='inactive'){state.voiceRecorder.stop();return;}if(!window.MediaRecorder)return setStatus('This browser does not support MediaRecorder.','error');try{const mic=await ensureMic(),mime=supportedRecorderMime('audio');state.voiceChunks=[];state.voiceStarted=performance.now();state.voiceRecordStream=new MediaStream(mic.getAudioTracks().map(track=>track.clone()));state.voiceRecorder=new MediaRecorder(state.voiceRecordStream,mime?{mimeType:mime}:undefined);state.voiceRecorder.ondataavailable=e=>{if(e.data?.size)state.voiceChunks.push(e.data);};state.voiceRecorder.onstop=async()=>{const duration=(performance.now()-state.voiceStarted)/1000,recorder=state.voiceRecorder,recordStream=state.voiceRecordStream;state.voiceRecorder=null;state.voiceRecordStream=null;recordStream?.getTracks().forEach(track=>track.stop());button.textContent='● Record Voice';button.classList.remove('recording');const blob=new Blob(state.voiceChunks,{type:recorder?.mimeType||'audio/webm'});try{await saveBlob(blob,'audio',`Voice Recording ${new Date().toLocaleString()}`,'agent_voice_recorder',duration,{recording_kind:'voice_memo'});}catch(error){setStatus(error.message||'Could not save voice recording.','error');}};state.voiceRecorder.start(1000);button.textContent='■ Stop Voice';button.classList.add('recording');setStatus('Voice recording in progress. This is saved audio, separate from chat transcription.','success');}catch(error){setStatus(error.message||'Microphone access was not granted.','error');}}
  async function handlePendingCapture(){const mode=state.pendingMode,index=Number(state.pendingIndex||0);state.pendingMode='camera';state.pendingIndex=0;const target=index&&state.feeds.has(index)?index:(state.feeds.size===1?[...state.feeds.keys()][0]:0);if(mode==='audio'){await toggleVoiceRecording();return;}if(!target&&(mode==='photo'||mode==='video')){setStatus(`Multiple camera feeds are available. Choose the camera you want to use for the ${mode}.`,'success');return;}if(mode==='photo'&&target){setStatus(`Taking a photo from Camera ${target}…`,'success');setTimeout(()=>takePhoto(target),700);}if(mode==='video'&&target){const button=state.root.querySelector(`[data-media-video="${target}"]`);if(button)setTimeout(()=>toggleVideoRecording(target,button),500);}}
  async function open(mode='camera',cameraIndex=0){pauseVoiceConversation();ensurePanel();state.root.hidden=false;state.pendingMode=mode;state.pendingIndex=cameraIndex;await loadAssets();if(mode==='audio'&&navigator.mediaDevices?.getUserMedia){try{await ensureMic();await handlePendingCapture();return;}catch(error){setStatus(error.message||'Microphone access was not granted.','error');return;}}await enableDevices();}
  window.StonefellowMediaStudio={open,close,refresh:enableDevices};
  document.addEventListener('click',event=>{const action=event.target.closest('[data-media-agent-mode]');if(action)open(action.dataset.mediaAgentMode||'camera',Number(action.dataset.mediaCameraIndex||0));});
  const form=document.getElementById('chatForm'),voice=document.getElementById('chatVoiceButton');if(form&&voice&&!document.getElementById('chatMediaButton')){const button=document.createElement('button');button.type='button';button.id='chatMediaButton';button.className='chat-media-button';button.setAttribute('aria-label','Open camera and recorder');button.title='Camera & Recorder';button.textContent='▣';form.insertBefore(button,voice);button.addEventListener('click',()=>open('camera'));}
  const params=new URLSearchParams(location.search);if(params.get('media')==='camera')setTimeout(()=>open('camera'),250);
})();
