/* A browser-selected display stream. No extension, recorder, or camera is used. */
(() => {
  'use strict';
  if(window.VP3AgentScreenShare)return;
  const adapter=window.VP3_ORB_ADAPTER;if(!adapter)return;
  let stream=null,starting=false,generation=0,timer=0,controller=null,latest=null,observation=null,lastAnalysis=0,analyzedFingerprint=null;
  let root,video,preview,status,startButton,stopButton,captureButton,automatic;
  const owner=String(adapter.ownerKey),embedded=new URLSearchParams(location.search).get('orb_embed')==='1'&&parent!==window;
  const clean=value=>String(value||'').slice(0,2000);
  function context(){
    if(embedded){try{if(parent.location.origin===location.origin&&String(parent.VP3_ORB_ADAPTER?.ownerKey)===owner)return parent.VP3AgentScreenShare?.context()||null;}catch(_){}return null;}
    if(!stream||!latest||Date.now()-Date.parse(latest.captured_at)>15000||!observation||Date.now()-Date.parse(observation.captured_at)>90000)return null;
    return {...observation};
  }
  const say=text=>{if(status)status.textContent=text;};
  function controls(){if(startButton){startButton.disabled=starting||!!stream;stopButton.disabled=!starting&&!stream;captureButton.disabled=!stream||!!controller;}}
  function distance(a,b){if(!a||!b)return 1;let sum=0;for(let i=0;i<a.length;i++)sum+=Math.abs(a[i]-b[i]);return sum/(a.length*255);}
  function stop(message='Screen sharing is off.'){
    generation++;starting=false;clearTimeout(timer);timer=0;controller?.abort();controller=null;
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
      const result=await adapter.screenRequest({image:frame.image,captured_at:frame.captured_at,surface:frame.surface},{signal:owned.signal});
      if(token!==generation||!stream||owned.signal.aborted||distance(frame.fingerprint,latest?.fingerprint)>.08)return;
      const value=result.observation;if(value?.contract!=='vp3.screen-observation.v1'||String(value.owner_key)!==owner){const error=Error('Screen account changed. Refresh this page before sharing.');error.status=409;throw error;}
      observation={contract:value.contract,owner_key:owner,captured_at:frame.captured_at,surface:frame.surface,summary:clean(value.summary),visible_text:clean(value.visible_text),site_hint:clean(value.site_hint),page_title:clean(value.page_title)};
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
      acquired=await navigator.mediaDevices.getDisplayMedia({video:{displaySurface:'window'},audio:false,systemAudio:'exclude',selfBrowserSurface:'exclude',monitorTypeSurfaces:'include',surfaceSwitching:'include'});
      if(token!==generation){acquired.getTracks().forEach(t=>t.stop());return;}
      acquired.getAudioTracks().forEach(t=>t.stop());const track=acquired.getVideoTracks()[0];if(!track||track.readyState!=='live')throw Error('No shared display was selected.');
      stream=acquired;video.srcObject=stream;
      track.addEventListener('ended',()=>{if(token===generation)stop('Screen sharing ended. Select Share screen to start again.');},{once:true});
      track.addEventListener('mute',()=>{if(token===generation){latest=observation=null;preview.removeAttribute('src');preview.hidden=true;controller?.abort();say('Shared display is temporarily unavailable.');}});
      await video.play();if(token!==generation)return;starting=false;lastAnalysis=0;controls();say('Screen sharing is active.');void tick(token);
    }catch(error){acquired?.getTracks().forEach(t=>t.stop());if(token===generation)stop(error.name==='NotAllowedError'?'Screen sharing was cancelled or not allowed. Select Share screen to retry.':error.message||'Screen sharing could not start.');}
  }
  function mount(){
    if(embedded)return;const host=document.querySelector('.vp3-eyes-content');if(!host)return;
    root=document.createElement('section');root.className='vp3-screen-share';root.setAttribute('aria-label','Share screen with Agent Eyes');
    root.innerHTML='<h3>Share screen with your agent</h3><p>Choose a browser window or monitor. Shared frames are analyzed by your configured Cloud model. No screen recording is saved.</p><div class="vp3-eyes-actions"><button type="button" data-screen-start>Share screen</button><button type="button" data-screen-stop disabled>Stop sharing</button><button type="button" data-screen-capture disabled>Capture and analyze</button></div><label><input type="checkbox" data-screen-auto checked> Analyze shared frames automatically</label><p data-screen-status role="status">Screen sharing is off.</p><video muted playsinline hidden></video><img data-screen-preview hidden alt="Latest locally captured shared screen frame"><p>Frames refresh about every 5 seconds. Changed frames are analyzed at most every 30 seconds. Switch tabs while sharing a window or monitor, then ask Chat about what is visible. Only the selected surface is shared.</p>';
    host.prepend(root);video=root.querySelector('video');preview=root.querySelector('img');status=root.querySelector('[data-screen-status]');automatic=root.querySelector('input');startButton=root.querySelector('[data-screen-start]');stopButton=root.querySelector('[data-screen-stop]');captureButton=root.querySelector('[data-screen-capture]');
    if(!isSecureContext||!navigator.mediaDevices?.getDisplayMedia||!adapter.screenRequest){startButton.disabled=true;say('Screen sharing needs a supported desktop browser, HTTPS and the matching VP3 screen service.');return;}
    startButton.addEventListener('click',()=>void start());stopButton.addEventListener('click',()=>stop());
    captureButton.addEventListener('click',()=>{try{const frame=capture();if(frame)void analyze(frame,generation);else say('Wait for a current shared frame.');}catch(error){say(error.message);}});
    automatic.addEventListener('change',()=>{if(!automatic.checked){controller?.abort();observation=null;}else if(stream){lastAnalysis=0;void tick(generation);}});
  }
  window.VP3AgentScreenShare=Object.freeze({context,stop,isActive:()=>!!stream});
  window.addEventListener('pagehide',()=>stop(),{once:true});
  if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',mount,{once:true});else mount();
})();
