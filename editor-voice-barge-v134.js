(() => {
  'use strict';

  const BUILD='conversation-integration-v134-20260826';
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
})();
