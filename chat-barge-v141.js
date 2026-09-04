(() => {
  'use strict';

  const BUILD='chat-barge-v141-20260826';
  const button=document.getElementById('chatVoiceButton')||document.getElementById('chatVoiceButtonLegacyDormant');
  if(!button)return;

  let stream=null;
  let context=null;
  let frame=0;
  let hotSince=0;
  let baseline=0;
  let startedAt=0;
  let lastLevelEvent=0;
  let armed=false;

  const proof=window.STONEFELLOW_CHAT_BARGE_V141={
    build:BUILD,loaded:true,arms:0,triggers:0,errors:0,buttonInterrupts:0,lastRms:0,lastThreshold:0
  };

  function continuity(){return window.STONEFELLOW_CHAT_CONTINUITY_V140||window.STONEFELLOW_CHAT_CONTINUITY_V139||null;}
  function emit(type,detail={}){
    try{window.dispatchEvent(new CustomEvent('stonefellow:chat-barge-v141',{detail:{build:BUILD,type,...detail}}));}catch(error){}
  }
  function stop(){
    if(frame)cancelAnimationFrame(frame);frame=0;hotSince=0;baseline=0;armed=false;
    try{stream?.getTracks?.().forEach(track=>track.stop());}catch(error){}stream=null;
    if(context){try{context.close();}catch(error){}}context=null;
  }
  function interrupt(trigger){
    const owner=continuity();
    if(!owner?.isVoice?.()||typeof owner.interrupt!=='function')return false;
    proof.triggers+=1;
    emit('INTERRUPT',{trigger,count:proof.triggers});
    stop();
    owner.interrupt(trigger);
    return true;
  }

  async function arm(){
    stop();
    const owner=continuity();
    if(!owner?.isVoice?.()||!navigator.mediaDevices?.getUserMedia)return;
    try{
      const localStream=await navigator.mediaDevices.getUserMedia({audio:{echoCancellation:true,noiseSuppression:true,autoGainControl:true},video:false});
      if(!continuity()?.isVoice?.()){localStream.getTracks().forEach(track=>track.stop());return;}
      const Ctx=window.AudioContext||window.webkitAudioContext;
      if(!Ctx){localStream.getTracks().forEach(track=>track.stop());return;}
      const localContext=new Ctx();
      if(localContext.state==='suspended'){try{await localContext.resume();}catch(error){}}
      const analyser=localContext.createAnalyser();
      analyser.fftSize=512;
      analyser.smoothingTimeConstant=.18;
      localContext.createMediaStreamSource(localStream).connect(analyser);
      const samples=new Uint8Array(analyser.fftSize);
      stream=localStream;context=localContext;startedAt=performance.now();armed=true;proof.arms+=1;
      emit('ARMED',{count:proof.arms});

      const tick=()=>{
        if(!armed||stream!==localStream||!continuity()?.isVoice?.()){stop();return;}
        analyser.getByteTimeDomainData(samples);
        let sum=0;
        for(const value of samples){const n=(value-128)/128;sum+=n*n;}
        const rms=Math.sqrt(sum/Math.max(1,samples.length));
        const age=performance.now()-startedAt;
        if(age<280){baseline=baseline?baseline*.82+rms*.18:rms;}
        const threshold=Math.max(.022,Math.min(.075,(baseline||.008)*1.85+.010));
        if(age>=280&&rms<threshold*.72)baseline=baseline?baseline*.975+rms*.025:rms;
        proof.lastRms=rms;proof.lastThreshold=threshold;
        if(performance.now()-lastLevelEvent>500){lastLevelEvent=performance.now();emit('LEVEL',{rms,threshold,baseline});}
        if(age>=280&&rms>threshold){
          if(!hotSince)hotSince=performance.now();
          if(performance.now()-hotSince>=105){interrupt('voice-barge-v141');return;}
        }else hotSince=0;
        frame=requestAnimationFrame(tick);
      };
      frame=requestAnimationFrame(tick);
    }catch(error){
      proof.errors+=1;
      emit('ERROR',{name:String(error?.name||'Error'),message:String(error?.message||error||''),count:proof.errors});
      stop();
    }
  }

  button.addEventListener('click',event=>{
    const state=String(document.body.dataset.stonefellowAgentState||button.dataset.agentState||'');
    if(state!=='speaking'&&state!=='processing')return;
    if(!continuity()?.isVoice?.())return;
    event.preventDefault();
    event.stopImmediatePropagation();
    proof.buttonInterrupts+=1;
    emit('BUTTON_INTERRUPT',{state,count:proof.buttonInterrupts});
    interrupt('button-v141');
  },true);

  window.addEventListener('stonefellow:chat-voice-v140',event=>{
    const type=String(event?.detail?.type||'');
    if(type==='SPEECH_STARTED')void arm();
    else if(type==='SPEECH_ENDED'||type==='INTERRUPT'||type==='VOICE_API_ERROR'||type==='VOICE_API_FETCH_ERROR')stop();
  });
  window.addEventListener('pagehide',stop,{once:true});
  emit('READY',{});
})();
