(() => {
  'use strict';
  const purge=()=>document.querySelectorAll('#agentNextMovesCanvas,.agent-next-canvas-v97,.agent-next-moves,.agent-proactive-panel').forEach(el=>el.remove());
  purge();
  const observer=new MutationObserver(purge);observer.observe(document.documentElement,{childList:true,subtree:true});

  const cfg=window.STONEFELLOW_CHAT||{},synth=window.speechSynthesis;
  let premium=null;
  if(synth&&window.SpeechSynthesisUtterance&&cfg.endpoint&&cfg.csrf){
    const originalSpeak=synth.speak.bind(synth),originalCancel=synth.cancel.bind(synth);
    let endpoint='';
    try{endpoint=new URL('agent-voice-v102.php',new URL(String(cfg.endpoint),window.location.href)).toString();}catch(e){}
    function cleanup(){if(!premium)return;try{premium.controller?.abort();}catch(e){}if(premium.audio){premium.audio.onended=premium.audio.onerror=null;try{premium.audio.pause();premium.audio.currentTime=0;}catch(e){}}if(premium.url){try{URL.revokeObjectURL(premium.url);}catch(e){}}premium=null;}
    function nativeFallback(utterance,token){if(premium!==token)return;cleanup();try{originalSpeak(utterance);}catch(e){try{utterance.dispatchEvent(new Event('error'));}catch(err){}}}
    if(endpoint){
      try{
        synth.speak=function(utterance){
          if(!(utterance instanceof SpeechSynthesisUtterance)||!String(utterance.text||'').trim()){originalSpeak(utterance);return;}
          cleanup();originalCancel();
          const token={controller:new AbortController(),audio:null,url:'',utterance};premium=token;
          fetch(endpoint,{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json',Accept:'audio/mpeg'},body:JSON.stringify({csrf_token:String(cfg.csrf),text:String(utterance.text||'')}),signal:token.controller.signal})
            .then(async response=>{if(!response.ok)throw new Error('premium-voice-unavailable');const blob=await response.blob();if(!blob.size)throw new Error('empty-premium-voice');if(premium!==token)return;token.url=URL.createObjectURL(blob);token.audio=new Audio(token.url);token.audio.preload='auto';token.audio.onended=()=>{if(premium!==token)return;cleanup();try{utterance.dispatchEvent(new Event('end'));}catch(e){}};token.audio.onerror=()=>nativeFallback(utterance,token);try{await token.audio.play();if(premium===token){try{utterance.dispatchEvent(new Event('start'));}catch(e){}}}catch(error){nativeFallback(utterance,token);}})
            .catch(error=>{if(error?.name==='AbortError'||premium!==token)return;nativeFallback(utterance,token);});
        };
        synth.cancel=function(){cleanup();originalCancel();};
      }catch(e){}
    }
    window.addEventListener('pagehide',()=>{cleanup();try{originalCancel();}catch(e){}},{once:true});
  }

  // v107: own the main Agent Chat LISTEN button in capture phase. Chrome may
  // require SpeechRecognition.start() to run directly inside the trusted click.
  // The legacy handler awaited getUserMedia() first, which can consume that
  // transient activation and produce a misleading `not-allowed` error even when
  // microphone permission is already granted.
  const listenButton=document.getElementById('chatVoiceButton');
  const listenStatus=document.getElementById('chatVoiceStatus');
  const chatForm=document.getElementById('chatForm');
  const chatInput=document.getElementById('chatInput');
  const chatThread=document.getElementById('chatThread');
  const Recognition=window.SpeechRecognition||window.webkitSpeechRecognition||null;
  if(listenButton&&listenStatus&&chatForm&&chatInput&&chatThread&&Recognition){
    let enabled=false,recognition=null,listening=false,speaking=false,pendingReply=false,replyBaseline=0,restartTimer=0;
    let bargeStream=null,bargeContext=null,bargeSource=null,bargeAnalyser=null,bargeTimer=0,bargeHits=0;
    const priorContinuity=window.STONEFELLOW_CHAT_CONTINUITY_V87||{};
    const status=(text,state='')=>{listenStatus.hidden=!text;listenStatus.textContent=text;listenStatus.dataset.state=state;};
    const setButton=on=>{listenButton.classList.toggle('active',on);listenButton.setAttribute('aria-pressed',on?'true':'false');listenButton.setAttribute('aria-label',on?'Stop voice conversation':'Start voice conversation');};
    const clearRestart=()=>{if(restartTimer){clearTimeout(restartTimer);restartTimer=0;}};
    const stopRecognition=()=>{clearRestart();const active=recognition;recognition=null;listening=false;if(active){try{active.abort();}catch(e){try{active.stop();}catch(err){}}}};
    const releaseBarge=()=>{if(bargeTimer){clearInterval(bargeTimer);bargeTimer=0;}bargeHits=0;try{bargeSource?.disconnect();}catch(e){}bargeSource=null;bargeAnalyser=null;if(bargeContext){try{bargeContext.close();}catch(e){}}bargeContext=null;if(bargeStream){bargeStream.getTracks().forEach(track=>track.stop());}bargeStream=null;};
    const stopSpeech=()=>{try{synth?.cancel();}catch(e){}speaking=false;if(bargeTimer){clearInterval(bargeTimer);bargeTimer=0;}bargeHits=0;};
    async function microphonePermission(){
      if(!navigator.permissions?.query)return 'unknown';
      try{return (await navigator.permissions.query({name:'microphone'})).state||'unknown';}catch(e){return 'unknown';}
    }
    async function explainRecognitionBlock(kind){
      const permission=await microphonePermission();
      if(permission==='denied')status('Microphone permission is blocked for this site.','error');
      else if(kind==='service-not-allowed')status('Browser speech recognition is unavailable right now. Microphone permission is not the problem.','error');
      else if(permission==='granted')status('Speech recognition was blocked by the browser. Click LISTEN to retry.','error');
      else status('Speech recognition could not start. Click LISTEN to retry.','error');
    }
    async function ensureBarge(){
      if(bargeStream||!navigator.mediaDevices?.getUserMedia)return;
      try{
        bargeStream=await navigator.mediaDevices.getUserMedia({audio:{echoCancellation:true,noiseSuppression:true,autoGainControl:true},video:false});
        const Ctx=window.AudioContext||window.webkitAudioContext;if(!Ctx)return;
        bargeContext=new Ctx();bargeAnalyser=bargeContext.createAnalyser();bargeAnalyser.fftSize=1024;bargeAnalyser.smoothingTimeConstant=.65;
        bargeSource=bargeContext.createMediaStreamSource(bargeStream);bargeSource.connect(bargeAnalyser);
      }catch(e){/* Recognition owns the primary mic path; barge-in is optional. */}
    }
    function startBarge(){
      if(!speaking||!bargeAnalyser)return;
      if(bargeTimer)clearInterval(bargeTimer);
      const samples=new Uint8Array(bargeAnalyser.fftSize);bargeHits=0;
      bargeTimer=setInterval(()=>{
        if(!speaking||!bargeAnalyser){clearInterval(bargeTimer);bargeTimer=0;return;}
        bargeAnalyser.getByteTimeDomainData(samples);let sum=0;
        for(const value of samples){const n=(value-128)/128;sum+=n*n;}
        const rms=Math.sqrt(sum/samples.length);bargeHits=rms>=.085?bargeHits+1:Math.max(0,bargeHits-1);
        if(bargeHits>=3){stopSpeech();status('Listening…','listening');scheduleStart(60);}
      },70);
    }
    function scheduleStart(delay=180){
      clearRestart();
      if(!enabled||speaking||pendingReply)return;
      restartTimer=setTimeout(()=>{restartTimer=0;startRecognition(false);},delay);
    }
    function startRecognition(fromTrustedClick=false){
      if(!enabled||speaking||pendingReply||listening||recognition)return;
      const session=new Recognition();recognition=session;
      session.continuous=false;session.interimResults=true;session.lang=document.documentElement.lang||'en-US';
      let final='';
      session.onstart=()=>{if(!enabled||recognition!==session){try{session.abort();}catch(e){}return;}listening=true;status('Listening…','listening');void ensureBarge();};
      session.onresult=event=>{
        if(!enabled||recognition!==session)return;
        let interim='';
        for(let i=event.resultIndex;i<event.results.length;i++){
          const text=event.results[i][0]?.transcript||'';
          if(event.results[i].isFinal)final+=text;else interim+=text;
        }
        if((final||interim).trim())status(interim?`Listening · ${interim}`:'Listening…','listening');
        if(final.trim()){
          const spoken=final.trim();final='';pendingReply=true;replyBaseline=chatThread.querySelectorAll('.message.assistant').length;
          chatInput.value=spoken;chatInput.dispatchEvent(new Event('input',{bubbles:true}));
          try{session.stop();}catch(e){}chatForm.requestSubmit();status('Thinking…','processing');
        }
      };
      session.onend=()=>{if(recognition===session)recognition=null;listening=false;if(enabled&&!speaking&&!pendingReply)scheduleStart(220);};
      session.onerror=event=>{
        const kind=String(event.error||'');if(recognition===session)recognition=null;listening=false;
        if(kind==='aborted')return;
        if(kind==='not-allowed'||kind==='service-not-allowed'){
          enabled=false;setButton(false);releaseBarge();void explainRecognitionBlock(kind);return;
        }
        if(kind==='no-speech'){status('Listening…','listening');scheduleStart(180);return;}
        status(kind==='network'?'Voice recognition is reconnecting…':'Voice input paused. Retrying…','ready');scheduleStart(kind==='network'?800:420);
      };
      try{
        // Keep this synchronous when called from the LISTEN click. Do not await
        // getUserMedia or any other promise before start().
        session.start();
        if(fromTrustedClick)status('Starting microphone…','ready');
      }catch(e){if(recognition===session)recognition=null;listening=false;status('Voice recognition could not start. Click LISTEN to retry.','error');}
    }
    function speakReply(text){
      const message=String(text||'').trim();pendingReply=false;
      if(!enabled||!message){scheduleStart(120);return;}
      stopRecognition();stopSpeech();speaking=true;status('Stonefellow is responding…','speaking');
      const utterance=new SpeechSynthesisUtterance(message);
      const finish=()=>{if(!speaking)return;speaking=false;if(bargeTimer){clearInterval(bargeTimer);bargeTimer=0;}status('Voice conversation on','ready');scheduleStart(160);};
      utterance.onstart=()=>{void ensureBarge().finally(startBarge);};utterance.onend=finish;utterance.onerror=finish;
      try{synth.speak(utterance);}catch(e){speaking=false;scheduleStart(100);}
    }
    const replyObserver=new MutationObserver(()=>{
      if(!pendingReply)return;
      const replies=chatThread.querySelectorAll('.message.assistant');
      if(replies.length<=replyBaseline)return;
      const text=replies[replies.length-1]?.querySelector('.message-text')?.textContent?.trim()||'';
      if(text)speakReply(text);
    });
    replyObserver.observe(chatThread,{childList:true,subtree:true,characterData:true});
    listenButton.addEventListener('click',event=>{
      event.preventDefault();event.stopImmediatePropagation();
      enabled=!enabled;setButton(enabled);
      if(!enabled){pendingReply=false;stopRecognition();stopSpeech();releaseBarge();status('Voice conversation off','off');return;}
      status('Voice conversation on','ready');startRecognition(true);
    },{capture:true});
    window.STONEFELLOW_CHAT_CONTINUITY_V87={...priorContinuity,isVoice:()=>Boolean(enabled)};
    window.addEventListener('pagehide',()=>{enabled=false;pendingReply=false;stopRecognition();stopSpeech();releaseBarge();replyObserver.disconnect();},{once:true});
  }

  if (!document.querySelector('script[data-team-chat-admin-v107]')) {
    const teamChatScript=document.createElement('script');
    teamChatScript.src=new URL('team-chat-admin-v107.js?v=107',window.location.href).toString();
    teamChatScript.dataset.teamChatAdminV107='1';
    teamChatScript.async=false;
    document.head.appendChild(teamChatScript);
  }

  window.addEventListener('pagehide',()=>observer.disconnect(),{once:true});
})();
