(() => {
  'use strict';
  const cfg=window.STONEFELLOW_ACTIVITY||{};if(!cfg.endpoint||!cfg.csrf)return;
  let lastMeaningful=Date.now(),hiddenAt=document.hidden?Date.now():0,lastState='',taskTitle=String(cfg.taskTitle||''),taskKey=String(cfg.taskKey||'');
  let timer=0,sending=false;
  const linkedConversation=()=>Number(window.STONEFELLOW_CHAT_CONTINUITY?.conversationId?.()||cfg.conversationId||new URLSearchParams(location.search).get('conversation_id')||0);
  const context=()=>({track_id:Number(cfg.trackId||0),project_id:Number(cfg.projectId||0),conversation_id:linkedConversation(),task_title:taskTitle,task_kind:cfg.surface||'chat',task_key:taskKey,path:location.pathname+location.search,visible:!document.hidden});
  const activeMedia=()=>{if(!['stem','video'].includes(String(cfg.surface||'')))return false;return [...document.querySelectorAll('audio,video')].some(el=>!el.paused&&!el.ended&&el.readyState>1&&!el.closest('.chat-media-studio'));};
  const recording=()=>!!document.querySelector('.recording,[data-recording="true"],[data-live-recording="true"]');
  function classify(){const now=Date.now(),elapsed=(now-lastMeaningful)/1000;if(recording()||activeMedia())return 'working';if(document.hidden){const hidden=(now-(hiddenAt||now))/1000;return hidden>120?'idle':'paused';}if(elapsed<=120)return 'working';if(elapsed<=480)return 'paused';return 'idle';}
  async function heartbeat(reason='timer',force=false){const state=classify();if(sending)return;if(!force&&state===lastState&&reason!=='timer')return;sending=true;try{const r=await fetch(cfg.endpoint,{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json'},body:JSON.stringify({action:'heartbeat',csrf_token:cfg.csrf,surface:cfg.surface||'chat',state,reason,context:context()})});const d=await r.json().catch(()=>null);if(r.ok&&d?.ok){if(lastState&&lastState!==state)document.dispatchEvent(new CustomEvent('stonefellow:proactive-refresh',{detail:{activity:d.activity}}));lastState=state;document.documentElement.dataset.agentActivity=state;}}catch(e){}finally{sending=false;}}
  function meaningful(reason='interaction'){lastMeaningful=Date.now();if(document.hidden)return;const prior=lastState;lastState='';heartbeat(reason,true);if(prior==='idle'||prior==='paused')document.dispatchEvent(new CustomEvent('stonefellow:proactive-refresh'));}
  ['pointerdown','keydown','input','change','submit','dragstart','drop'].forEach(type=>document.addEventListener(type,e=>{if(type==='keydown'&&['Shift','Control','Alt','Meta'].includes(e.key))return;meaningful(type);},{capture:true,passive:type==='pointerdown'}));
  document.addEventListener('stonefellow:task-start',e=>{taskTitle=String(e.detail?.title||taskTitle);taskKey=String(e.detail?.key||taskKey);meaningful('task_start');});
  document.addEventListener('visibilitychange',()=>{hiddenAt=document.hidden?Date.now():0;heartbeat(document.hidden?'hidden':'visible',true);});
  window.addEventListener('pagehide',()=>{lastMeaningful=0;const form=new FormData();form.append('action','heartbeat');form.append('csrf_token',String(cfg.csrf));form.append('surface',String(cfg.surface||'chat'));form.append('state','idle');form.append('reason','pagehide');form.append('context',JSON.stringify(context()));try{navigator.sendBeacon?.(cfg.endpoint,form);}catch(e){}});
  window.StonefellowAgentActivity={markTask:(title,key='')=>{taskTitle=String(title||taskTitle);taskKey=String(key||taskKey);meaningful('task_mark');},snapshot:()=>({state:classify(),taskTitle,taskKey})};
  timer=setInterval(()=>heartbeat('timer',true),30000);setTimeout(()=>heartbeat('load',true),250);

  if(String(cfg.surface||'')==='chat'){
    let meterStream=null,meterContext=null,meterSource=null,meterAnalyser=null,meterFrame=0,meterData=null,meterDeviceId='';
    const chatUserId=()=>Number(window.STONEFELLOW_CHAT?.userId||0);
    const voiceKey=()=>`stonefellow:voice-mode:${chatUserId()}`;
    const voiceActive=()=>{try{return chatUserId()>0&&localStorage.getItem(voiceKey())==='1';}catch(e){return false;}};
    const stopMeter=()=>{if(meterFrame)cancelAnimationFrame(meterFrame);meterFrame=0;try{meterSource?.disconnect();meterAnalyser?.disconnect();}catch(e){}meterStream?.getTracks().forEach(t=>t.stop());meterStream=null;meterSource=null;meterAnalyser=null;meterData=null;meterDeviceId='';if(meterContext&&meterContext.state!=='closed')meterContext.close().catch(()=>{});meterContext=null;};
    const hideMeter=()=>{const meter=ensureRegistry()?.querySelector('[data-chat-audio-meter]');stopMeter();if(meter)meter.hidden=true;};
    const ensureRegistry=()=>{
      const topbar=document.querySelector('.chat-topbar');if(!topbar)return null;
      let registry=topbar.querySelector('.chat-device-registry-v94');if(registry)return registry;
      registry=document.createElement('div');registry.className='chat-device-registry-v94';registry.setAttribute('aria-label','Recording device status');
      registry.innerHTML='<button type="button" class="chat-device-chip-v94 audio pending" data-chat-audio-device aria-label="Audio input status"><span class="chat-device-meter-v97" data-chat-audio-meter hidden><i></i></span></button><div class="chat-device-camera-list-v94" data-chat-camera-devices></div>';
      const spacer=topbar.querySelector('.chat-topbar-spacer');topbar.insertBefore(registry,spacer||topbar.firstChild?.nextSibling||null);
      registry.querySelector('[data-chat-audio-device]')?.addEventListener('click',()=>window.StonefellowMediaStudio?.open?.('audio'));
      return registry;
    };
    async function permissionGranted(name){try{if(!navigator.permissions?.query)return false;const p=await navigator.permissions.query({name});return p.state==='granted';}catch(e){return false;}}
    async function startMeter(device){
      const registry=ensureRegistry(),meter=registry?.querySelector('[data-chat-audio-meter]'),bar=meter?.querySelector('i');if(!meter||!bar||!device?.deviceId)return;
      if(voiceActive()){stopMeter();meter.hidden=true;return;}
      meter.hidden=false;
      if(meterDeviceId===device.deviceId&&meterStream?.active)return;
      stopMeter();meter.hidden=false;bar.style.width='2%';
      if(!(await permissionGranted('microphone'))||voiceActive())return;
      try{
        meterStream=await navigator.mediaDevices.getUserMedia({video:false,audio:{deviceId:{exact:device.deviceId},echoCancellation:false,noiseSuppression:false,autoGainControl:false}});
        if(voiceActive()){stopMeter();meter.hidden=true;return;}
        const AC=window.AudioContext||window.webkitAudioContext;if(!AC){stopMeter();meter.hidden=false;return;}meterContext=new AC();meterSource=meterContext.createMediaStreamSource(meterStream);meterAnalyser=meterContext.createAnalyser();meterAnalyser.fftSize=256;meterAnalyser.smoothingTimeConstant=.72;meterData=new Uint8Array(meterAnalyser.fftSize);meterSource.connect(meterAnalyser);meterDeviceId=device.deviceId;meter.hidden=false;
        const draw=()=>{if(!meterAnalyser||!meterData||!meterStream?.active||voiceActive()){if(voiceActive()){stopMeter();meter.hidden=true;}return;}meterAnalyser.getByteTimeDomainData(meterData);let sum=0;for(const v of meterData){const n=(v-128)/128;sum+=n*n;}const rms=Math.sqrt(sum/meterData.length);const pct=Math.max(2,Math.min(100,Math.round(rms*420)));bar.style.width=`${pct}%`;meterFrame=requestAnimationFrame(draw);};draw();
      }catch(e){stopMeter();meter.hidden=false;bar.style.width='2%';}
    }
    async function renderChatDevices(){
      const registry=ensureRegistry();if(!registry)return;const audioStatus=registry.querySelector('[data-chat-audio-device]'),cameraList=registry.querySelector('[data-chat-camera-devices]'),meter=registry.querySelector('[data-chat-audio-meter]');if(!audioStatus||!cameraList)return;
      if(!navigator.mediaDevices?.enumerateDevices){audioStatus.className='chat-device-chip-v94 audio pending';audioStatus.title='Audio input status';audioStatus.setAttribute('aria-label','Audio input status');cameraList.innerHTML='';stopMeter();if(meter)meter.hidden=true;return;}
      try{
        const devices=await navigator.mediaDevices.enumerateDevices(),audio=devices.filter(d=>d.kind==='audioinput'),cameras=devices.filter(d=>d.kind==='videoinput');
        const focusrite=audio.find(d=>/focusrite|scarlett/i.test(String(d.label||''))),namedAudio=focusrite||audio.find(d=>d.label)||audio[0];
        const audioName=namedAudio?.label||'Audio input';audioStatus.title=audioName;audioStatus.setAttribute('aria-label',audioName);audioStatus.className='chat-device-chip-v94 audio '+(audio.length?'connected':'pending');
        if(audio.length&&namedAudio&&!voiceActive()){if(meter)meter.hidden=false;void startMeter(namedAudio);}else{stopMeter();if(meter)meter.hidden=true;}
        cameraList.innerHTML='';cameras.forEach((device,index)=>{const chip=document.createElement('button');chip.type='button';chip.className='chat-device-chip-v94 camera connected';chip.dataset.cameraIndex=String(index+1);chip.title=device.label||`Camera ${index+1}`;chip.setAttribute('aria-label',device.label||`Camera ${index+1}`);chip.addEventListener('click',()=>window.StonefellowMediaStudio?.open?.('camera',index+1));cameraList.appendChild(chip);});
      }catch(e){audioStatus.className='chat-device-chip-v94 audio pending';audioStatus.title='Audio input status';audioStatus.setAttribute('aria-label','Audio input status');cameraList.innerHTML='';stopMeter();if(meter)meter.hidden=true;}
    }
    const voiceModeChanged=e=>{if(Number(e.detail?.userId||0)!==chatUserId())return;if(e.detail?.enabled)hideMeter();else if(!document.hidden){setTimeout(renderChatDevices,120);}};
    window.addEventListener('stonefellow:voice-mode',voiceModeChanged);
    window.addEventListener('storage',e=>{if(e.key!==voiceKey())return;if(e.newValue==='1')hideMeter();else if(!document.hidden)setTimeout(renderChatDevices,120);});
    navigator.mediaDevices?.addEventListener?.('devicechange',renderChatDevices);
    document.addEventListener('visibilitychange',()=>{if(document.hidden||voiceActive())stopMeter();else renderChatDevices();});
    const voiceButton=document.getElementById('chatVoiceButton')||document.getElementById('chatVoiceButtonLegacyDormant');
    const releaseForListen=()=>{if(!voiceActive())hideMeter();};
    voiceButton?.addEventListener('pointerdown',releaseForListen,{capture:true,passive:true});
    window.addEventListener('pagehide',()=>{window.removeEventListener('stonefellow:voice-mode',voiceModeChanged);voiceButton?.removeEventListener('pointerdown',releaseForListen,true);stopMeter();},{once:true});
    setTimeout(renderChatDevices,100);setTimeout(renderChatDevices,1500);
  }
})();

(() => {
  'use strict';
  if(String(window.STONEFELLOW_ACTIVITY?.surface||'')!=='chat')return;
  const nav=document.querySelector('.chat-sidebar-nav');
  if(!nav||nav.querySelector('a[href$="/knowledge.php"],a[href*="/knowledge.php?"]'))return;
  const link=document.createElement('a');
  link.className='chat-sidebar-nav-link';
  link.href=new URL('knowledge.php',window.location.href).toString();
  link.innerHTML='<span>◆</span><strong>My Knowledge</strong>';
  const contacts=[...nav.querySelectorAll('a')].find(a=>/\/contacts\.php(?:[?#]|$)/.test(a.getAttribute('href')||''));
  const transcriptions=[...nav.querySelectorAll('a')].find(a=>/\/artist-listening\.php(?:[?#]|$)/.test(a.getAttribute('href')||''));
  if(contacts)contacts.insertAdjacentElement('afterend',link);else if(transcriptions)nav.insertBefore(link,transcriptions);else nav.appendChild(link);
})();
