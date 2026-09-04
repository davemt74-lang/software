(() => {
  'use strict';
  window.StonefellowEditorVoiceBarge = function(options={}) {
    let stream=null,context=null,source=null,analyser=null,timer=null,hits=0;
    const speaking=()=>Boolean(options.isSpeaking?.());
    async function ensure(){
      if(stream||!navigator.mediaDevices?.getUserMedia)return;
      try{
        stream=await navigator.mediaDevices.getUserMedia({audio:{echoCancellation:true,noiseSuppression:true,autoGainControl:true},video:false});
        const Ctx=window.AudioContext||window.webkitAudioContext;if(!Ctx)return;
        context=new Ctx();analyser=context.createAnalyser();analyser.fftSize=1024;analyser.smoothingTimeConstant=.65;
        source=context.createMediaStreamSource(stream);source.connect(analyser);
      }catch(e){}
    }
    function stop(){if(timer){clearInterval(timer);timer=null;}hits=0;}
    function start(){
      stop();if(!analyser||!speaking())return;
      const samples=new Uint8Array(analyser.fftSize);
      timer=setInterval(()=>{
        if(!analyser||!speaking()){stop();return;}
        analyser.getByteTimeDomainData(samples);let sum=0;
        for(const value of samples){const normalized=(value-128)/128;sum+=normalized*normalized;}
        const rms=Math.sqrt(sum/samples.length);hits=rms>=.085?hits+1:Math.max(0,hits-1);
        if(hits>=3){stop();options.interrupt?.();}
      },70);
    }
    function release(){stop();try{source?.disconnect();}catch(e){}source=null;analyser=null;if(context){try{context.close();}catch(e){}}context=null;if(stream){stream.getTracks().forEach(t=>t.stop());}stream=null;}
    return {ensure,start,stop,release};
  };

  window.StonefellowAgentVoiceV102 = function(options={}) {
    const sourceEndpoint=String(options.agentEndpoint||'');
    let endpoint='';
    try{endpoint=new URL('agent-voice-v102.php',new URL(sourceEndpoint,window.location.href)).toString();}catch(e){return null;}
    let audio=null,objectUrl='',controller=null;
    function cleanupUrl(){if(objectUrl){try{URL.revokeObjectURL(objectUrl);}catch(e){}objectUrl='';}}
    function stop(){if(controller){try{controller.abort();}catch(e){}controller=null;}if(audio){audio.onplay=audio.onended=audio.onerror=null;try{audio.pause();audio.currentTime=0;}catch(e){}audio=null;}cleanupUrl();}
    async function speak(text,callbacks={}){
      stop();const message=String(text||'').trim();if(!message)throw new Error('No voice text.');
      controller=new AbortController();const localController=controller;
      const response=await fetch(endpoint,{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json',Accept:'audio/mpeg'},body:JSON.stringify({csrf_token:String(options.csrf||''),text:message}),signal:localController.signal});
      if(!response.ok){let detail='';try{detail=(await response.json())?.error||'';}catch(e){}throw new Error(detail||'Premium Agent voice unavailable.');}
      const blob=await response.blob();if(!blob.size)throw new Error('Premium Agent voice returned no audio.');if(localController.signal.aborted)throw new DOMException('Aborted','AbortError');
      controller=null;objectUrl=URL.createObjectURL(blob);audio=new Audio(objectUrl);audio.preload='auto';
      audio.onplay=()=>callbacks.onStart?.();
      audio.onended=()=>{audio=null;cleanupUrl();callbacks.onEnd?.();};
      audio.onerror=()=>{audio=null;cleanupUrl();callbacks.onError?.();};
      await audio.play();return true;
    }
    return {speak,stop,isActive:()=>!!audio};
  };
})();
