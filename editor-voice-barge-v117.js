(() => {
  'use strict';

  const BUILD='conversation-integration-v133-20260826';
  const userId=()=>Number(window.STONEFELLOW_CHAT?.userId||window.STONEFELLOW_STUDIO_AGENT?.userId||window.STONEFELLOW_VIDEO_EDITOR?.userId||0);
  const hash=value=>{let h=2166136261;for(const ch of String(value||'default')){h^=ch.charCodeAt(0);h=Math.imul(h,16777619);}return (h>>>0).toString(36);};
  const profileKey=device=>`stonefellow:acoustic-profile:${userId()}:${hash(device)}`;

  window.StonefellowEditorVoiceBarge=function(options={}){
    let stream=null,context=null,source=null,analyser=null,timer=null,hits=0,mediaLease=false;
    let liveFloor=.018,startedAt=0,deviceIdentity='default',profile=null,profileSamples=0,sessionFloorSum=0,sessionFloorCount=0;
    const proof={build:BUILD,starts:0,interruptions:0,lastRms:0,lastThreshold:0,device:'',profileLoaded:false,profileSaves:0,calibrationFrames:0,leaseDenials:0};
    const speaking=()=>Boolean(options.isSpeaking?.());
    const lease=()=>window.StonefellowVoiceLeaseV122||null;

    function loadProfile(){
      try{const parsed=JSON.parse(localStorage.getItem(profileKey(deviceIdentity))||'null');if(parsed&&typeof parsed==='object'&&Number(parsed.echoFloor)>0){profile=parsed;proof.profileLoaded=true;}}catch(error){}
      if(!profile)profile={echoFloor:.018,noiseFloor:.012,samples:0,updatedAt:0};liveFloor=Math.max(.008,Number(profile.echoFloor||.018));
    }
    function saveProfile(){
      if(sessionFloorCount<4)return;
      const measured=Math.max(.006,sessionFloorSum/sessionFloorCount);const old=Math.max(.006,Number(profile?.echoFloor||measured));
      const blend=Math.min(.6,.18+Math.min(1,sessionFloorCount/24)*.35);const echoFloor=(old*(1-blend))+(measured*blend);
      profile={echoFloor,noiseFloor:Math.min(echoFloor,Math.max(.006,Number(profile?.noiseFloor||echoFloor*.65))),samples:Number(profile?.samples||0)+sessionFloorCount,updatedAt:Date.now()};
      try{localStorage.setItem(profileKey(deviceIdentity),JSON.stringify(profile));proof.profileSaves+=1;}catch(error){}
    }
    async function ensure(){
      if(stream||!navigator.mediaDevices?.getUserMedia)return;
      const gate=lease();if(gate&&!gate.acquireMedia()){proof.leaseDenials+=1;return;}
      mediaLease=!!gate;
      try{
        stream=await navigator.mediaDevices.getUserMedia({audio:{echoCancellation:true,noiseSuppression:true,autoGainControl:true},video:false});
        const track=stream.getAudioTracks?.()[0]||stream.getTracks?.()[0]||null;const settings=track?.getSettings?.()||{};
        deviceIdentity=String(settings.deviceId||settings.groupId||track?.label||'default');proof.device=hash(deviceIdentity);loadProfile();
        const Ctx=window.AudioContext||window.webkitAudioContext;if(!Ctx)return;
        context=new Ctx();analyser=context.createAnalyser();analyser.fftSize=1024;analyser.smoothingTimeConstant=.5;source=context.createMediaStreamSource(stream);source.connect(analyser);
      }catch(error){if(mediaLease){lease()?.releaseMedia();mediaLease=false;}}
    }
    function thresholdFor(floor){
      const historical=Math.max(.008,Number(profile?.echoFloor||.018));const calibrated=Math.max(historical*.72,floor);
      return Math.max(.068,historical*1.75+.016,calibrated*1.55+.018);
    }
    function stop(save=true){if(timer){clearInterval(timer);timer=null;}hits=0;startedAt=0;if(save)saveProfile();sessionFloorSum=0;sessionFloorCount=0;}
    function start(){
      stop(false);if(!analyser||!speaking())return;proof.starts+=1;startedAt=performance.now();liveFloor=Math.max(.008,Number(profile?.echoFloor||.018));profileSamples=Number(profile?.samples||0);sessionFloorSum=0;sessionFloorCount=0;
      const samples=new Uint8Array(analyser.fftSize);
      timer=setInterval(()=>{
        if(mediaLease&&lease()&&!lease().owns()){release();return;}
        if(!analyser||!speaking()){stop(true);return;}
        analyser.getByteTimeDomainData(samples);let sum=0;for(const value of samples){const normalized=(value-128)/128;sum+=normalized*normalized;}
        const rms=Math.sqrt(sum/Math.max(1,samples.length));const elapsed=performance.now()-startedAt;proof.lastRms=rms;const learnMs=profileSamples>30?650:1050;
        if(elapsed<learnMs){liveFloor=Math.max(.006,liveFloor*.74+rms*.26);sessionFloorSum+=rms;sessionFloorCount+=1;proof.calibrationFrames+=1;hits=0;proof.lastThreshold=thresholdFor(liveFloor);return;}
        const threshold=thresholdFor(liveFloor);proof.lastThreshold=threshold;
        if(rms>=threshold){hits+=1;if(hits>=2){proof.interruptions+=1;stop(true);options.interrupt?.();}}
        else{hits=Math.max(0,hits-1);liveFloor=Math.max(.006,liveFloor*.972+rms*.028);sessionFloorSum+=rms;sessionFloorCount+=1;}
      },50);
    }
    function release(){
      stop(true);try{source?.disconnect();}catch(error){}source=null;analyser=null;if(context){try{context.close();}catch(error){}}context=null;
      if(stream)stream.getTracks().forEach(track=>{try{track.stop();}catch(error){}});stream=null;
      if(mediaLease){lease()?.releaseMedia();mediaLease=false;}
    }
    return {ensure,start,stop,release,proof,profile:()=>({...profile,device:proof.device})};
  };

  const conversation=window.StonefellowConversationVoiceV122;
  if(conversation?.create&&!conversation.__recognizerRecoveryV133){
    const originalCreate=conversation.create.bind(conversation);
    conversation.create=function createWithRecognizerRecovery(options={}){
      const originalInterrupt=options.onInterrupt;
      const originalBusy=options.isBusy;
      let instance=null,recoveryTimer=0,watchdogTimer=0,recoveryStartedAt=0;
      let destroyed=false,manualDisabled=false,hardResetting=false,hardResets=0;

      const clearRecovery=()=>{if(recoveryTimer)clearTimeout(recoveryTimer);recoveryTimer=0;recoveryStartedAt=0;};
      const clearWatchdog=()=>{if(watchdogTimer)clearTimeout(watchdogTimer);watchdogTimer=0;};
      const clearTimers=()=>{clearRecovery();clearWatchdog();};
      const surfaceClear=()=>!Boolean(originalBusy?.())&&!instance?.isSpeaking?.()&&!instance?.isPreparing?.();
      const emit=(name,detail={})=>{try{window.dispatchEvent(new CustomEvent(name,{detail:{build:BUILD,...detail}}));}catch(error){}};

      const wrappedOptions=()=>({...options,onInterrupt:detail=>{
        let result;
        try{result=originalInterrupt?.(detail);}finally{scheduleRecovery('interrupt');}
        return result;
      }});

      function buildFresh(enabled=true){
        instance=originalCreate({...wrappedOptions(),initialEnabled:enabled});
        return instance;
      }

      function hardReset(reason='watchdog'){
        if(destroyed||manualDisabled||hardResetting)return;
        hardResetting=true;clearTimers();hardResets+=1;
        const old=instance;
        try{old?.setEnabled?.(false,{persist:false});}catch(error){}
        try{old?.destroy?.();}catch(error){}
        try{options.onState?.('recovering','Restarting microphone…');}catch(error){}
        window.setTimeout(()=>{
          if(destroyed||manualDisabled){hardResetting=false;return;}
          buildFresh(true);hardResetting=false;
          try{instance.start?.();}catch(error){}
          emit('stonefellow:voice-recognizer-reset',{reason,hardResets});
          armWatchdog(`reset-${reason}`,1900);
        },140);
      }

      function armWatchdog(reason='resume',delay=1700){
        clearWatchdog();
        if(destroyed||manualDisabled||!instance?.isEnabled?.())return;
        const baselineStarts=Number(instance?.proof?.recognitionStarts||0);
        watchdogTimer=window.setTimeout(()=>{
          watchdogTimer=0;
          if(destroyed||manualDisabled||!instance?.isEnabled?.())return;
          if(!surfaceClear()){scheduleRecovery(reason);return;}
          const started=instance?.isListening?.()||Number(instance?.proof?.recognitionStarts||0)>baselineStarts;
          if(started)return;
          if(hardResets<3){hardReset(`stalled-${reason}`);return;}
          try{options.onState?.('error','Microphone recognition did not restart.');}catch(error){}
          try{options.onError?.(new Error('Speech recognition did not restart after a clean reset.'));}catch(error){}
          emit('stonefellow:voice-recognizer-failed',{reason,hardResets});
        },Math.max(800,Number(delay)||0));
      }

      function scheduleRecovery(reason='interrupt'){
        clearRecovery();recoveryStartedAt=Date.now();
        const tick=()=>{
          if(destroyed||manualDisabled||!instance?.isEnabled?.()){clearRecovery();return;}
          if(surfaceClear()){
            const waitedMs=Math.max(0,Date.now()-recoveryStartedAt);clearRecovery();
            instance.resume?.(25);armWatchdog(reason,1700);
            emit('stonefellow:voice-interrupt-resumed',{reason,waitedMs});
            return;
          }
          recoveryTimer=window.setTimeout(tick,90);
        };
        recoveryTimer=window.setTimeout(tick,45);
      }

      buildFresh(Boolean(options.initialEnabled)||conversation.readShared?.(Number(options.userId||0)));

      const facade={
        build:BUILD,
        get sessionId(){return instance?.sessionId||'';},
        get proof(){return instance?.proof||{};},
        start(){const result=instance?.start?.();if(instance?.isEnabled?.())armWatchdog('start',1900);return result;},
        setEnabled(next,opts={}){
          const enabled=Boolean(next);clearTimers();
          if(!enabled){manualDisabled=true;hardResets=0;return instance?.setEnabled?.(false,opts);}
          manualDisabled=false;
          try{void instance?.unlockAudio?.('manual-enable');}catch(error){}
          const result=instance?.setEnabled?.(true,opts);
          hardResets=0;armWatchdog('manual-enable',1900);
          return result;
        },
        toggle(){return facade.setEnabled(!Boolean(instance?.isEnabled?.()));},
        unlockAudio(...args){return instance?.unlockAudio?.(...args);},
        speak(...args){return instance?.speak?.(...args);},
        createSpeechStream(...args){return instance?.createSpeechStream?.(...args);},
        resume(delay=120){const result=instance?.resume?.(delay);armWatchdog('resume',Math.max(1700,Number(delay||0)+1400));return result;},
        stopListening(...args){clearWatchdog();return instance?.stopListening?.(...args);},
        stopOutput(...args){return instance?.stopOutput?.(...args);},
        destroy(){destroyed=true;clearTimers();try{instance?.destroy?.();}catch(error){}},
        emitHealth(...args){return instance?.emitHealth?.(...args);},
        isEnabled(){return Boolean(instance?.isEnabled?.());},
        isListening(){return Boolean(instance?.isListening?.());},
        isSpeaking(){return Boolean(instance?.isSpeaking?.());},
        isPreparing(){return Boolean(instance?.isPreparing?.());},
        state(){return String(instance?.state?.()||'idle');},
        snapshot(...args){return instance?.snapshot?.(...args)||{};}
      };
      return facade;
    };
    conversation.__interruptRecoveryV132=true;
    conversation.__recognizerRecoveryV133=true;
    window.STONEFELLOW_INTERRUPT_RECOVERY_V132={build:BUILD,installed:true};
    window.STONEFELLOW_RECOGNIZER_RECOVERY_V133={build:BUILD,installed:true};
  }
})();
