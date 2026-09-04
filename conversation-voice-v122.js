(() => {
  'use strict';

  const BUILD='conversation-voice-recovery-v157-20260829';
  const CONTROL_BUILD='voice-three-of-three-v157-20260829';
  const SESSION_TTL_MS=30*60*1000;
  const HEALTH_INTERVAL_MS=30000;
  const DUPLICATE_WINDOW_MS=2600;
  const ECHO_COOLDOWN_MS=360;
  const POST_SPEECH_ECHO_MS=4000;
  const TURN_END_PAUSE_MS=1800;
  const START_WATCHDOG_MS=2200;
  const MIC_SAMPLE_INTERVAL_MS=55;
  const MIC_SILENT_NOTICE_MS=1200;
  const PREMIUM_START_TIMEOUT_MS=9000;
  const SHORT_COMMANDS=new Set(['yes','no','stop','pause','play','mute','solo','cancel','continue','resume','next','back','save','undo','redo','listen']);
  const INTERRUPT_COMMANDS=new Set(['stop','wait','pause','cancel','hold','hang']);

  function voiceKey(userId){return `stonefellow:voice-mode:${Number(userId||0)}`;}
  function sessionKey(userId){return `stonefellow:voice-session:${Number(userId||0)}`;}
  function now(){return Date.now();}
  function readShared(userId){try{return localStorage.getItem(voiceKey(userId))==='1';}catch(error){return false;}}
  function writeShared(userId,enabled,source='conversation'){
    try{localStorage.setItem(voiceKey(userId),enabled?'1':'0');}catch(error){}
    try{window.dispatchEvent(new CustomEvent('stonefellow:voice-mode',{detail:{enabled:!!enabled,userId:Number(userId||0),source,build:BUILD,controlBuild:CONTROL_BUILD}}));}catch(error){}
  }
  function randomId(){try{return crypto.randomUUID();}catch(error){return `${now().toString(36)}-${Math.random().toString(36).slice(2,12)}`;}}
  function readSession(userId){
    try{
      const value=JSON.parse(localStorage.getItem(sessionKey(userId))||'null');
      if(!value||typeof value!=='object'||now()-Number(value.updatedAt||0)>SESSION_TTL_MS)return null;
      return value;
    }catch(error){return null;}
  }
  function cleanTranscript(value){return String(value||'').replace(/\s+/g,' ').trim();}
  function normalized(value){return cleanTranscript(value).toLowerCase().replace(/[^\p{L}\p{N}'-]+/gu,' ').trim();}
  function transcriptWords(value){return normalized(value).split(/\s+/).filter(word=>word.length>1);}
  function fingerprint(value){
    const text=normalized(value);let hash=2166136261;
    for(let i=0;i<text.length;i+=1){hash^=text.charCodeAt(i);hash=Math.imul(hash,16777619);}
    return (hash>>>0).toString(36);
  }
  function resemblesOutput(candidate,spoken){
    const words=transcriptWords(candidate);if(words.length<2)return false;
    const spokenWords=transcriptWords(spoken);if(!spokenWords.length)return false;
    const haystack=new Set(spokenWords);let hits=0;
    for(const word of words)if(haystack.has(word))hits+=1;
    if(words.length===2&&hits===2)return true;
    if(words.length>=4&&hits/words.length>=.68)return true;
    const phrase=` ${normalized(candidate)} `;const spokenNorm=` ${normalized(spoken)} `;
    return words.length>=3&&phrase.length>7&&spokenNorm.includes(phrase);
  }
  function confidenceFor(result){
    const value=Number(result?.[0]?.confidence);
    return Number.isFinite(value)&&value>0&&value<=1?value:0;
  }
  function acceptTranscript(text,confidence,mode){
    const clean=cleanTranscript(text);if(!clean)return {ok:false,reason:'empty'};
    const words=transcriptWords(clean);if(!words.length)return {ok:false,reason:'empty'};
    if(confidence<=0)return {ok:true,reason:'unreported'};
    const threshold=mode==='barge'?(words.length<=1?.52:.30):(words.length<=1?.58:.34);
    if(words.length===1&&SHORT_COMMANDS.has(words[0]))return {ok:confidence>=.28,reason:'short-command'};
    return {ok:confidence>=threshold,reason:confidence>=threshold?'confidence':'low-confidence'};
  }
  function recognitionErrorMessage(error){
    switch(String(error||'')){
      case 'not-allowed':return 'Microphone permission is blocked. Click LISTEN again after allowing microphone access.';
      case 'service-not-allowed':return 'Browser speech recognition is unavailable.';
      case 'audio-capture':return 'No usable microphone input is available.';
      case 'network':return 'Voice recognition lost its network service.';
      case 'no-speech':return 'No speech detected. Listening again…';
      case 'aborted':return '';
      default:return 'Voice recognition paused. Reconnecting…';
    }
  }

  function create(options={}){
    const userId=Number(options.userId||0);
    const source=String(options.source||'conversation');
    const SpeechRecognitionCtor=window.SpeechRecognition||window.webkitSpeechRecognition||null;
    const premiumVoice=window.StonefellowPremiumVoiceV122?.({agentEndpoint:options.agentEndpoint,csrf:options.csrf})||null;
    const previousSession=readSession(userId);
    const sessionId=previousSession?.id||randomId();

    let enabled=!!options.initialEnabled||readShared(userId);
    let state='idle';
    let recognition=null;
    let recognitionMode='';
    let listening=false;
    let speaking=false;
    let preparing=false;
    let destroyed=false;
    let restartTimer=0;
    let recognitionStartTimer=0;
    let healthTimer=0;
    let generation=0;
    let activeOutput=null;
    let spokenOutput='';
    let outputEndedAt=0;
    let bargeCapture=false;
    let bargeCandidate='';
    let bargeCandidateConfidence=0;
    let bargeCandidateAt=0;
    let bargeCandidateNormalized='';
    let bargeCandidateHits=0;
    let bargeCaptureTimer=0;
    let recentAccepted=[];
    let lastHealthSent=0;
    let pendingFinalTranscript='';
    let pendingFinalConfidence=0;
    let pendingFinalParts=0;
    let turnEndTimer=0;

    let micStream=null;
    let micContext=null;
    let micSource=null;
    let micAnalyser=null;
    let micTimer=0;
    let micOpenPromise=null;
    let micGeneration=0;
    let micStartedAt=0;
    let micSilentReported=false;

    const proof={
      build:BUILD,controlBuild:CONTROL_BUILD,source,sessionId,enabled,pauseWindowMs:TURN_END_PAUSE_MS,pauseExtensions:0,premiumPrestartRecoveries:0,premiumStartTimeouts:0,
      recognitionEntries:0,recognitionAttempts:0,recognitionStarts:0,bargeRecognitionStarts:0,recognitionErrors:0,
      recognitionConstructorErrors:0,recognitionConfigErrors:0,recognitionStartThrows:0,recognitionStartTimeouts:0,recognitionGateWaits:0,
      micPreflights:0,micPreflightErrors:0,micTrackLive:false,micLastRms:0,micPeakRms:0,micDevice:'',micContextState:'none',micSilentFrames:0,
      acceptedTranscripts:0,lowConfidenceRejected:0,duplicatesRejected:0,echoCandidatesRejected:0,cooldownEchoRejected:0,
      premiumStarts:0,premiumUnlockRequests:0,premiumUnlocks:0,browserFallbacks:0,
      interruptions:0,preservedInterruptions:0,bargeCandidates:0,bargeFastCuts:0,levelCandidates:0,
      streamedTurns:0,healthReports:0
    };

    const busy=()=>!!options.isBusy?.();
    const barge=window.StonefellowEditorVoiceBarge?.({isSpeaking:()=>speaking,interrupt:()=>{
      proof.levelCandidates+=1;
      emit('stonefellow:voice-barge-level-candidate',{count:proof.levelCandidates});
    }})||null;

    function emit(name,detail={}){
      try{window.dispatchEvent(new CustomEvent(name,{detail:{build:BUILD,controlBuild:CONTROL_BUILD,sessionId,source,state,enabled,...detail}}));}catch(error){}
    }
    function recognizerEvent(type,detail={}){
      emit(`stonefellow:voice-recognizer-${type}`,{mode:recognitionMode,listening,busy:busy(),speaking,preparing,...detail});
    }
    function inputEvent(type,detail={}){
      emit('stonefellow:voice-input',{type,live:proof.micTrackLive,rms:proof.micLastRms,peak:proof.micPeakRms,device:proof.micDevice,contextState:proof.micContextState,...detail});
    }
    function snapshot(extra={}){
      return {
        id:sessionId,build:BUILD,controlBuild:CONTROL_BUILD,userId,source,enabled,state,updatedAt:now(),
        health:{
          recognitionEntries:proof.recognitionEntries,recognitionAttempts:proof.recognitionAttempts,recognitionStarts:proof.recognitionStarts,
          bargeRecognitionStarts:proof.bargeRecognitionStarts,recognitionErrors:proof.recognitionErrors,
          recognitionConstructorErrors:proof.recognitionConstructorErrors,recognitionConfigErrors:proof.recognitionConfigErrors,
          recognitionStartThrows:proof.recognitionStartThrows,recognitionStartTimeouts:proof.recognitionStartTimeouts,
          recognitionGateWaits:proof.recognitionGateWaits,micPreflights:proof.micPreflights,micPreflightErrors:proof.micPreflightErrors,
          micTrackLive:proof.micTrackLive,micLastRms:proof.micLastRms,micPeakRms:proof.micPeakRms,micDevice:proof.micDevice,
          micContextState:proof.micContextState,micSilentFrames:proof.micSilentFrames,
          acceptedTranscripts:proof.acceptedTranscripts,lowConfidenceRejected:proof.lowConfidenceRejected,
          duplicatesRejected:proof.duplicatesRejected,echoCandidatesRejected:proof.echoCandidatesRejected,
          interruptions:proof.interruptions,preservedInterruptions:proof.preservedInterruptions,pauseWindowMs:TURN_END_PAUSE_MS,
          pauseExtensions:proof.pauseExtensions,premiumPrestartRecoveries:proof.premiumPrestartRecoveries
        },...extra
      };
    }
    function persist(extra={}){
      const data=snapshot(extra);
      try{localStorage.setItem(sessionKey(userId),JSON.stringify(data));}catch(error){}
      try{window.dispatchEvent(new CustomEvent('stonefellow:voice-session',{detail:data}));}catch(error){}
    }
    function setState(next,text=''){
      state=String(next||'idle');persist();
      try{options.onState?.(state,text);}catch(error){}
    }
    function emitHealth(reason='periodic',force=false){
      const activity=window.STONEFELLOW_ACTIVITY||null;
      const timestamp=now();if(!activity?.endpoint||!activity?.csrf||(!force&&timestamp-lastHealthSent<HEALTH_INTERVAL_MS))return;
      lastHealthSent=timestamp;proof.healthReports+=1;
      const context={
        track_id:Number(activity.trackId||0),project_id:Number(activity.projectId||0),conversation_id:Number(activity.conversationId||0),
        task_title:String(activity.taskTitle||''),path:location.pathname,visible:document.visibilityState!=='hidden',
        voice:{session_id:sessionId,state,source,reason,
          recognition_entries:proof.recognitionEntries,recognition_attempts:proof.recognitionAttempts,recognition_starts:proof.recognitionStarts,
          recognition_errors:proof.recognitionErrors,constructor_errors:proof.recognitionConstructorErrors,config_errors:proof.recognitionConfigErrors,
          start_throws:proof.recognitionStartThrows,start_timeouts:proof.recognitionStartTimeouts,gate_waits:proof.recognitionGateWaits,
          mic_preflights:proof.micPreflights,mic_errors:proof.micPreflightErrors,mic_live:proof.micTrackLive,mic_rms:proof.micLastRms,mic_peak:proof.micPeakRms,
          mic_context:proof.micContextState,mic_silent_frames:proof.micSilentFrames,
          accepted:proof.acceptedTranscripts,interruptions:proof.interruptions,preserved_interruptions:proof.preservedInterruptions,
          pause_window_ms:TURN_END_PAUSE_MS,pause_extensions:proof.pauseExtensions,premium_prestart_recoveries:proof.premiumPrestartRecoveries}
      };
      const payload=JSON.stringify({csrf_token:activity.csrf,action:'voice_health',surface:String(activity.surface||source),context});
      try{
        if(reason==='pagehide'&&navigator.sendBeacon){navigator.sendBeacon(String(activity.endpoint),new Blob([payload],{type:'application/json'}));return;}
        void fetch(String(activity.endpoint),{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json'},body:payload,keepalive:true}).catch(()=>{});
      }catch(error){}
    }
    function startHealth(){if(healthTimer)clearInterval(healthTimer);healthTimer=window.setInterval(()=>emitHealth('periodic'),HEALTH_INTERVAL_MS);}
    function clearRestart(){if(restartTimer)clearTimeout(restartTimer);restartTimer=0;}
    function clearStartWatchdog(){if(recognitionStartTimer)clearTimeout(recognitionStartTimer);recognitionStartTimer=0;}
    function clearBargeTimer(){if(bargeCaptureTimer)clearTimeout(bargeCaptureTimer);bargeCaptureTimer=0;}
    function clearBargeCandidate(){bargeCandidate='';bargeCandidateConfidence=0;bargeCandidateAt=0;bargeCandidateNormalized='';bargeCandidateHits=0;}
    function clearTurnEndTimer(){if(turnEndTimer)clearTimeout(turnEndTimer);turnEndTimer=0;}
    function resetPendingFinal(){clearTurnEndTimer();pendingFinalTranscript='';pendingFinalConfidence=0;pendingFinalParts=0;}
    function immediateBargeCommand(text){return INTERRUPT_COMMANDS.has(transcriptWords(text)[0]||'');}
    function recordBargeCandidate(text,confidence=0){
      const candidate=cleanTranscript(text);const candidateNormalized=normalized(candidate);const timestamp=performance.now();
      const related=bargeCandidateNormalized&&timestamp-bargeCandidateAt<1200&&(candidateNormalized.startsWith(bargeCandidateNormalized)||bargeCandidateNormalized.startsWith(candidateNormalized));
      bargeCandidateHits=related?bargeCandidateHits+1:1;bargeCandidate=candidate;bargeCandidateConfidence=confidence;bargeCandidateAt=timestamp;bargeCandidateNormalized=candidateNormalized;proof.bargeCandidates+=1;
      return bargeCandidateHits;
    }
    function unlockPremiumAudio(reason='gesture'){
      if(typeof premiumVoice?.unlock!=='function')return Promise.resolve(false);proof.premiumUnlockRequests+=1;
      try{return Promise.resolve(premiumVoice.unlock()).then(unlocked=>{if(unlocked)proof.premiumUnlocks+=1;emit('stonefellow:voice-audio-unlock',{reason,unlocked:!!unlocked});return !!unlocked;}).catch(()=>false);}
      catch(error){return Promise.resolve(false);}
    }
    function pruneAccepted(){const cutoff=now()-DUPLICATE_WINDOW_MS;recentAccepted=recentAccepted.filter(item=>item.at>=cutoff);}
    function duplicate(text){pruneAccepted();const fp=fingerprint(text);return recentAccepted.some(item=>item.fp===fp);}
    function rememberAccepted(text){pruneAccepted();recentAccepted.push({fp:fingerprint(text),at:now()});}
    function isEchoCooldown(text){return outputEndedAt>0&&now()-outputEndedAt<POST_SPEECH_ECHO_MS&&resemblesOutput(text,spokenOutput)&&!immediateBargeCommand(text);}

    function stopMicMonitor(reason='released'){
      micGeneration+=1;
      if(micTimer){clearInterval(micTimer);micTimer=0;}
      try{micSource?.disconnect?.();}catch(error){}micSource=null;micAnalyser=null;
      if(micContext){try{void micContext.close();}catch(error){}}micContext=null;
      if(micStream){for(const track of micStream.getTracks?.()||[]){try{track.stop();}catch(error){}}}micStream=null;
      proof.micTrackLive=false;proof.micContextState='closed';micOpenPromise=null;inputEvent(reason,{live:false});persist();
    }

    function startMicMonitor(){
      if(destroyed||!enabled||speaking||preparing)return Promise.resolve(false);
      if(micStream&&proof.micTrackLive)return Promise.resolve(true);
      if(micOpenPromise)return micOpenPromise;
      if(!navigator.mediaDevices?.getUserMedia){proof.micPreflightErrors+=1;inputEvent('error',{name:'NotSupportedError',message:'getUserMedia unavailable'});persist();return Promise.resolve(false);}
      const localMicGeneration=++micGeneration;
      proof.micPreflights+=1;proof.micTrackLive=false;proof.micLastRms=0;proof.micPeakRms=0;proof.micDevice='';proof.micContextState='opening';proof.micSilentFrames=0;micSilentReported=false;
      inputEvent('acquiring');persist();
      micOpenPromise=(async()=>{
        try{
          const stream=await navigator.mediaDevices.getUserMedia({audio:{echoCancellation:true,noiseSuppression:true,autoGainControl:true},video:false});
          if(localMicGeneration!==micGeneration||destroyed||!enabled||speaking||preparing){for(const track of stream.getTracks?.()||[]){try{track.stop();}catch(error){}}return false;}
          const track=stream.getAudioTracks?.()[0]||stream.getTracks?.()[0]||null;
          if(!track||track.readyState!=='live'||track.enabled===false)throw new Error('Microphone track is not live.');
          micStream=stream;
          const settings=track.getSettings?.()||{};
          proof.micTrackLive=true;proof.micDevice=String(track.label||settings.deviceId||'microphone');
          inputEvent('live',{label:String(track.label||''),readyState:String(track.readyState||''),muted:!!track.muted,settings});persist();

          const Ctx=window.AudioContext||window.webkitAudioContext;
          if(!Ctx){proof.micContextState='unavailable';inputEvent('context',{state:'unavailable'});persist();return true;}
          micContext=new Ctx();proof.micContextState=String(micContext.state||'unknown');
          inputEvent('context',{state:proof.micContextState});
          if(micContext.state==='suspended'){
            try{await micContext.resume();}catch(error){}
            proof.micContextState=String(micContext.state||'unknown');inputEvent('context',{state:proof.micContextState});
          }
          micAnalyser=micContext.createAnalyser();micAnalyser.fftSize=512;micAnalyser.smoothingTimeConstant=.25;
          micSource=micContext.createMediaStreamSource(stream);micSource.connect(micAnalyser);
          const samples=new Uint8Array(micAnalyser.fftSize);micStartedAt=performance.now();
          micTimer=window.setInterval(()=>{
            if(!micAnalyser||!proof.micTrackLive||localMicGeneration!==micGeneration)return;
            micAnalyser.getByteTimeDomainData(samples);let sum=0;
            for(const value of samples){const n=(value-128)/128;sum+=n*n;}
            const rms=Math.sqrt(sum/Math.max(1,samples.length));proof.micLastRms=rms;proof.micPeakRms=Math.max(proof.micPeakRms,rms);
            if(rms<=0.00001)proof.micSilentFrames+=1;else proof.micSilentFrames=0;
            const percent=Math.min(100,Math.round(rms*650));inputEvent('level',{rms,peak:proof.micPeakRms,percent,contextState:proof.micContextState});
            if(!micSilentReported&&proof.micSilentFrames*MIC_SAMPLE_INTERVAL_MS>=MIC_SILENT_NOTICE_MS){micSilentReported=true;inputEvent('silent',{rms,percent:0,contextState:proof.micContextState,elapsedMs:Math.round(performance.now()-micStartedAt)});persist();}
          },MIC_SAMPLE_INTERVAL_MS);
          return true;
        }catch(error){
          proof.micPreflightErrors+=1;proof.micTrackLive=false;proof.micContextState='error';
          inputEvent('error',{name:String(error?.name||'Error'),message:String(error?.message||error||'')});persist();return false;
        }finally{micOpenPromise=null;}
      })();
      return micOpenPromise;
    }

    function stopRecognition(update=false){
      clearRestart();clearStartWatchdog();const active=recognition;recognition=null;recognitionMode='';listening=false;
      try{active?.abort?.();}catch(error){try{active?.stop?.();}catch(stopError){}}
      if(update&&enabled&&!busy()&&!speaking&&!preparing)setState('ready','Voice ready');
    }

    function scheduleRecognition(delay=140){
      clearRestart();if(!enabled||destroyed||recognition)return;
      if(busy()||speaking||preparing){
        proof.recognitionGateWaits+=1;recognizerEvent('gate',{reason:busy()?'busy':speaking?'speaking':'preparing',requestedDelay:Number(delay)||0});persist();
        restartTimer=window.setTimeout(()=>scheduleRecognition(delay),90);return;
      }
      const wait=Math.max(0,Number(delay)||0,outputEndedAt&&now()-outputEndedAt<ECHO_COOLDOWN_MS?ECHO_COOLDOWN_MS-(now()-outputEndedAt):0);
      restartTimer=window.setTimeout(()=>startRecognition('normal'),wait);
    }

    function queueFinalTranscript(text,confidence=0){
      const part=cleanTranscript(text);if(!part)return;
      if(pendingFinalTranscript)proof.pauseExtensions+=1;
      pendingFinalTranscript=cleanTranscript([pendingFinalTranscript,part].filter(Boolean).join(' '));
      pendingFinalConfidence=((pendingFinalConfidence*pendingFinalParts)+Math.max(0,Number(confidence)||0))/(pendingFinalParts+1);
      pendingFinalParts+=1;
      setState('listening',`Listening · ${pendingFinalTranscript}`);
      clearTurnEndTimer();
      turnEndTimer=window.setTimeout(()=>{
        turnEndTimer=0;
        if(destroyed||!enabled||busy()||speaking||preparing)return;
        const transcript=pendingFinalTranscript;const finalConfidence=pendingFinalConfidence;
        pendingFinalTranscript='';pendingFinalConfidence=0;pendingFinalParts=0;
        stopRecognition(false);
        emit('stonefellow:voice-turn-complete',{pauseMs:TURN_END_PAUSE_MS,textLength:transcript.length});
        dispatchTranscript(transcript,finalConfidence);
      },TURN_END_PAUSE_MS);
    }

    function startRecognition(mode='normal'){
      clearRestart();proof.recognitionEntries+=1;recognizerEvent('enter',{mode,ctorType:typeof SpeechRecognitionCtor,hasRecognition:!!recognition});persist();
      if(!enabled){recognizerEvent('blocked',{mode,reason:'disabled'});return false;}
      if(destroyed){recognizerEvent('blocked',{mode,reason:'destroyed'});return false;}
      if(recognition){recognizerEvent('blocked',{mode,reason:'recognition-active'});return false;}
      if(typeof SpeechRecognitionCtor!=='function'){
        proof.recognitionErrors+=1;recognizerEvent('blocked',{mode,reason:'constructor-unavailable',ctorType:typeof SpeechRecognitionCtor});persist();
        setState('error','Browser speech recognition is unavailable.');return false;
      }
      if(mode==='normal'&&(busy()||speaking||preparing)){
        proof.recognitionGateWaits+=1;recognizerEvent('gate',{mode,reason:busy()?'busy':speaking?'speaking':'preparing'});persist();
        scheduleRecognition(90);return false;
      }
      if(mode==='barge'&&!speaking){recognizerEvent('blocked',{mode,reason:'not-speaking'});return false;}

      let current=null;
      try{current=new SpeechRecognitionCtor();}
      catch(error){
        proof.recognitionConstructorErrors+=1;proof.recognitionErrors+=1;
        recognizerEvent('constructor-error',{mode,name:String(error?.name||'Error'),message:String(error?.message||error||'')});persist();
        setState('error',`Speech recognizer could not be created: ${String(error?.message||error||'unknown error')}`);
        try{options.onError?.(error);}catch(callbackError){}return false;
      }

      recognition=current;recognitionMode=mode;
      try{current.lang=options.language||document.documentElement.lang||'en-US';current.continuous=mode==='barge';current.interimResults=true;}
      catch(error){
        if(recognition===current){recognition=null;recognitionMode='';}
        proof.recognitionConfigErrors+=1;proof.recognitionErrors+=1;
        recognizerEvent('config-error',{mode,name:String(error?.name||'Error'),message:String(error?.message||error||'')});persist();
        setState('error',`Speech recognizer configuration failed: ${String(error?.message||error||'unknown error')}`);return false;
      }

      current.onstart=()=>{
        if(recognition!==current)return;clearStartWatchdog();listening=true;
        if(mode==='barge')proof.bargeRecognitionStarts+=1;
        else{proof.recognitionStarts+=1;setState('listening',pendingFinalTranscript?`Listening · ${pendingFinalTranscript}`:'Listening…');void startMicMonitor();}
        recognizerEvent('started',{mode});persist();
      };
      current.onresult=event=>{
        if(recognition!==current)return;
        const finalParts=[];const interimParts=[];let confidence=0,confidenceCount=0;
        for(let i=0;i<event.results.length;i+=1){
          const result=event.results[i];const text=String(result?.[0]?.transcript||'');if(!text)continue;
          if(result.isFinal){finalParts.push(text);const c=confidenceFor(result);if(c>0){confidence+=c;confidenceCount+=1;}}else interimParts.push(text);
        }
        const finalText=cleanTranscript(finalParts.join(' '));const interimText=cleanTranscript(interimParts.join(' '));
        const heard=cleanTranscript([finalText,interimText].filter(Boolean).join(' '));const finalConfidence=confidenceCount?confidence/confidenceCount:0;
        if(mode==='barge'){
          const immediate=immediateBargeCommand(heard);const echo=heard&&!immediate&&resemblesOutput(heard,spokenOutput);
          if(echo){proof.echoCandidatesRejected+=1;if(!bargeCapture)clearBargeCandidate();persist();return;}
          const hits=heard?recordBargeCandidate(heard,finalConfidence):0;
          const stableInterim=!finalText&&transcriptWords(heard).length>=2&&hits>=2;
          const accepted=heard&&acceptTranscript(heard,finalConfidence,'barge').ok;
          if(speaking&&!bargeCapture&&accepted&&(!!finalText||immediate||stableInterim)){
            if(stableInterim){proof.bargeFastCuts+=1;emit('stonefellow:voice-barge-fast-cut',{text:heard,hits,count:proof.bargeFastCuts});}
            beginInterruption('recognition');
          }
          if(bargeCapture&&heard)setState('listening',`Interrupted · ${heard}`);
          if(bargeCapture&&finalText&&(!resemblesOutput(finalText,spokenOutput)||immediateBargeCommand(finalText)))finishInterruptCapture();return;
        }
        const combinedPreview=cleanTranscript([pendingFinalTranscript,interimText||finalText].filter(Boolean).join(' '));
        if(combinedPreview)setState('listening',`Listening · ${combinedPreview}`);
        if(!finalText)return;
        clearStartWatchdog();if(recognition===current){recognition=null;recognitionMode='';}listening=false;try{current.stop();}catch(error){}
        queueFinalTranscript(finalText,finalConfidence);
        if(enabled)scheduleRecognition(120);
      };
      current.onerror=event=>{
        clearStartWatchdog();if(recognition===current){recognition=null;recognitionMode='';}listening=false;proof.recognitionErrors+=1;
        const kind=String(event?.error||'unknown');const message=recognitionErrorMessage(kind);
        recognizerEvent('error',{mode,error:kind,message:String(event?.message||'')});persist();
        if(kind==='not-allowed'||kind==='service-not-allowed'||kind==='audio-capture'){
          resetPendingFinal();stopMicMonitor('released');setState('error',message);emitHealth(`recognition-${kind}`,true);return;
        }
        if(mode==='barge'){
          if(bargeCapture&&finishInterruptCapture(true))return;
          if(speaking&&enabled)window.setTimeout(()=>startRecognition('barge'),180);return;
        }
        if(message)setState('ready',pendingFinalTranscript?`Listening · ${pendingFinalTranscript}`:message);if(enabled)scheduleRecognition(kind==='no-speech'?120:300);
      };
      current.onend=()=>{
        clearStartWatchdog();if(recognition===current){recognition=null;recognitionMode='';}listening=false;recognizerEvent('end',{mode});persist();
        if(mode==='barge'){
          if(bargeCapture&&finishInterruptCapture(true))return;
          if(speaking&&enabled&&!destroyed){window.setTimeout(()=>startRecognition('barge'),120);return;}
          if(enabled)scheduleRecognition(160);return;
        }
        if(enabled)scheduleRecognition(pendingFinalTranscript?100:190);
      };

      proof.recognitionAttempts+=1;recognizerEvent('attempt',{mode,attempt:proof.recognitionAttempts,userActivation:!!navigator.userActivation?.isActive});persist();
      try{
        current.start();clearStartWatchdog();
        recognitionStartTimer=window.setTimeout(()=>{
          if(recognition!==current||listening||destroyed)return;
          proof.recognitionStartTimeouts+=1;proof.recognitionErrors+=1;recognizerEvent('timeout',{mode,attempt:proof.recognitionAttempts});persist();
          if(recognition===current){recognition=null;recognitionMode='';}try{current.abort?.();}catch(error){}
          listening=false;setState('ready',pendingFinalTranscript?`Listening · ${pendingFinalTranscript}`:'Speech recognizer did not start. Retrying…');if(enabled)scheduleRecognition(350);
        },START_WATCHDOG_MS);
        return true;
      }catch(error){
        clearStartWatchdog();if(recognition===current){recognition=null;recognitionMode='';}listening=false;
        proof.recognitionStartThrows+=1;proof.recognitionErrors+=1;
        recognizerEvent('throw',{mode,name:String(error?.name||'Error'),message:String(error?.message||error||''),userActivation:!!navigator.userActivation?.isActive});persist();
        setState('error',`Speech recognition start failed: ${String(error?.message||error||'unknown error')}`);return false;
      }
    }

    function dispatchTranscript(text,confidence=0){
      const transcript=cleanTranscript(text);if(!transcript)return false;
      const accepted=acceptTranscript(transcript,confidence,'normal');
      if(!accepted.ok){proof.lowConfidenceRejected+=1;scheduleRecognition(120);return false;}
      if(isEchoCooldown(transcript)){proof.cooldownEchoRejected+=1;scheduleRecognition(ECHO_COOLDOWN_MS);return false;}
      if(duplicate(transcript)){proof.duplicatesRejected+=1;scheduleRecognition(120);return false;}
      rememberAccepted(transcript);proof.acceptedTranscripts+=1;setState('processing','Thinking…');emitHealth('accepted');
      try{
        const result=options.onTranscript?.(transcript);
        Promise.resolve(result).catch(error=>{try{options.onError?.(error);}catch(callbackError){}}).finally(()=>{if(enabled&&!destroyed&&!recognition)scheduleRecognition(180);});
      }catch(error){try{options.onError?.(error);}catch(callbackError){}if(enabled)scheduleRecognition(260);}
      return true;
    }

    function usableBargeCandidate(){
      const candidate=cleanTranscript(bargeCandidate);if(!candidate||performance.now()-bargeCandidateAt>2200)return '';
      if(resemblesOutput(candidate,spokenOutput)&&!immediateBargeCommand(candidate)){proof.echoCandidatesRejected+=1;return '';}
      const accepted=acceptTranscript(candidate,bargeCandidateConfidence,'barge');
      if(!accepted.ok){proof.lowConfidenceRejected+=1;return '';}if(duplicate(candidate)){proof.duplicatesRejected+=1;return '';}return candidate;
    }
    function finishInterruptCapture(force=false){
      if(!bargeCapture)return false;const candidate=usableBargeCandidate();if(!candidate&&!force)return false;
      bargeCapture=false;clearBargeTimer();
      if(candidate){
        proof.preservedInterruptions+=1;rememberAccepted(candidate);proof.acceptedTranscripts+=1;
        stopRecognition(false);clearBargeCandidate();
        setState('processing','Thinking…');emitHealth('interruption-preserved',true);
        try{Promise.resolve(options.onTranscript?.(candidate)).catch(error=>{try{options.onError?.(error);}catch(callbackError){}});}catch(error){}return true;
      }
      stopRecognition(false);clearBargeCandidate();scheduleRecognition(35);return false;
    }
    function armBarge(localGeneration){
      if(localGeneration!==generation||!speaking)return;
      if(barge)Promise.resolve(barge.ensure()).then(()=>{
        if(localGeneration!==generation||destroyed||!speaking){try{barge.release();}catch(error){};return;}
        try{barge.start();}catch(error){}
      }).catch(()=>{});
      window.setTimeout(()=>{if(localGeneration===generation&&speaking&&!recognition)startRecognition('barge');},100);
    }
    function beginInterruption(trigger='level'){
      if(!speaking||destroyed)return;proof.interruptions+=1;generation+=1;resetPendingFinal();
      try{activeOutput?.stop?.();}catch(error){}activeOutput=null;try{window.speechSynthesis?.cancel();}catch(error){}try{barge?.release();}catch(error){}
      preparing=false;speaking=false;bargeCapture=true;setState('interrupted','Interrupted · restarting LISTEN…');
      try{options.onInterrupt?.({trigger,sessionId});}catch(error){}emitHealth(`interrupt-${trigger}`,true);
      if(recognition&&recognitionMode==='barge'){
        clearBargeTimer();bargeCaptureTimer=window.setTimeout(()=>finishInterruptCapture(true),1400);return;
      }
      scheduleRecognition(25);
    }
    function finishSpeaking(localGeneration){
      if(localGeneration!==generation||destroyed)return;try{barge?.release();}catch(error){}if(recognitionMode==='barge')stopRecognition(false);
      bargeCapture=false;clearBargeCandidate();clearBargeTimer();
      activeOutput=null;preparing=false;speaking=false;outputEndedAt=now();emitHealth('output-end');
      if(enabled){setState('ready','Voice ready');scheduleRecognition(ECHO_COOLDOWN_MS);}else setState('idle');
    }
    function browserSpeak(text,localGeneration){
      const message=cleanTranscript(text);
      if(!enabled||destroyed||!message||!('speechSynthesis'in window)||!window.SpeechSynthesisUtterance){preparing=false;scheduleRecognition(100);return;}
      proof.browserFallbacks+=1;preparing=true;spokenOutput=message;try{window.speechSynthesis.cancel();}catch(error){}
      const utterance=new SpeechSynthesisUtterance(message);let finished=false;
      const watchdog=window.setTimeout(()=>{if(finished)return;finished=true;finishSpeaking(localGeneration);},Math.min(45000,Math.max(7000,message.length*85)));
      const finish=()=>{if(finished)return;finished=true;clearTimeout(watchdog);finishSpeaking(localGeneration);};
      activeOutput={stop:()=>{clearTimeout(watchdog);try{window.speechSynthesis.cancel();}catch(error){}}};
      utterance.onstart=()=>{if(localGeneration!==generation||destroyed)return;preparing=false;speaking=true;setState('speaking','Stonefellow is responding…');armBarge(localGeneration);};
      utterance.onend=utterance.onerror=finish;
      try{window.speechSynthesis.speak(utterance);}catch(error){finish();}
    }
    function beginOutput(){
      resetPendingFinal();stopRecognition(false);stopMicMonitor('released');generation+=1;const localGeneration=generation;
      try{activeOutput?.stop?.();}catch(error){}activeOutput=null;try{barge?.release();}catch(error){}
      bargeCapture=false;clearBargeCandidate();clearBargeTimer();spokenOutput='';
      preparing=true;speaking=false;setState('processing','Preparing voice…');return localGeneration;
    }
    function createSpeechStream(){
      if(!enabled||destroyed)return null;const localGeneration=beginOutput();proof.streamedTurns+=1;let fallbackText='';
      if(!premiumVoice?.createStream){
        return {push(delta){const text=String(delta||'');fallbackText+=text;spokenOutput+=text;},end(){preparing=false;browserSpeak(fallbackText,localGeneration);},stop(){generation+=1;try{window.speechSynthesis?.cancel();}catch(error){}preparing=false;speaking=false;},started:Promise.resolve(false)};
      }
      let ended=false;let prestartError=null;let recovered=false;let firstPush=false;let startTimer=0;let stream=null;
      const clearPremiumStartTimer=()=>{if(startTimer)clearTimeout(startTimer);startTimer=0;};
      const recoverPremium=error=>{
        if(recovered||localGeneration!==generation||destroyed)return;recovered=true;clearPremiumStartTimer();proof.premiumPrestartRecoveries+=1;
        try{stream?.stop?.();}catch(stopError){}
        preparing=false;speaking=false;activeOutput=null;emitHealth('premium-error',true);
        try{options.onOutputError?.(error);}catch(callbackError){}
        if(fallbackText)browserSpeak(fallbackText,localGeneration);else finishSpeaking(localGeneration);
      };
      stream=premiumVoice.createStream({
        onStart:()=>{if(localGeneration!==generation||destroyed||recovered)return;clearPremiumStartTimer();preparing=false;speaking=true;proof.premiumStarts+=1;setState('speaking','Stonefellow is responding…');armBarge(localGeneration);},
        onEnd:()=>{clearPremiumStartTimer();finishSpeaking(localGeneration);},
        onError:error=>recoverPremium(error)
      });
      Promise.resolve(stream.started).catch(error=>{
        if(error?.name==='AbortError'||localGeneration!==generation||destroyed)return;
        prestartError=error||new Error('ElevenLabs failed before playback started.');
        if(ended)recoverPremium(prestartError);
      });
      activeOutput=stream;return {push(delta){const text=String(delta||'');fallbackText+=text;spokenOutput+=text;if(!text||recovered)return;if(!firstPush){firstPush=true;startTimer=window.setTimeout(()=>{if(recovered||speaking||localGeneration!==generation)return;proof.premiumStartTimeouts+=1;recoverPremium(new Error('ElevenLabs playback did not start.'));},PREMIUM_START_TIMEOUT_MS);}stream.push(text);},end(){ended=true;if(!recovered)stream.end();if(prestartError)recoverPremium(prestartError);},stop(){recovered=true;clearPremiumStartTimer();stream.stop();},started:stream.started,done:stream.done};
    }
    async function speak(text){
      const message=cleanTranscript(text);if(!enabled||!message||destroyed)return;
      const stream=createSpeechStream();if(!stream)return;stream.push(message);stream.end();
      try{return await stream.started;}catch(error){if(error?.name==='AbortError')return false;return false;}
    }
    function stopOutput(){
      generation+=1;try{activeOutput?.stop?.();}catch(error){}activeOutput=null;try{premiumVoice?.stop?.();}catch(error){}try{window.speechSynthesis?.cancel();}catch(error){}try{barge?.release();}catch(error){}
      if(recognitionMode==='barge')stopRecognition(false);bargeCapture=false;clearBargeCandidate();clearBargeTimer();preparing=false;speaking=false;outputEndedAt=now();
    }
    function setEnabled(next,opts={}){
      const wasEnabled=enabled;enabled=!!next;proof.enabled=enabled;if(opts.persist!==false)writeShared(userId,enabled,source);
      try{options.onVoiceChange?.(enabled);}catch(error){}
      if(!enabled){resetPendingFinal();stopRecognition(false);stopMicMonitor('released');stopOutput();setState('idle','Voice conversation off');emitHealth('disabled',true);return;}
      void unlockPremiumAudio('enable');try{void premiumVoice?.warm?.();}catch(error){}startHealth();setState('ready','Voice ready');emitHealth('enabled',true);
      if(opts.immediate===true&&!wasEnabled&&!busy()&&!speaking&&!preparing)startRecognition('normal');else scheduleRecognition(0);
    }
    function resume(delay=120){if(enabled&&!destroyed)scheduleRecognition(delay);}
    function destroy(){
      destroyed=true;resetPendingFinal();clearRestart();clearStartWatchdog();clearBargeTimer();if(healthTimer)clearInterval(healthTimer);healthTimer=0;
      emitHealth('pagehide',true);stopRecognition(false);stopMicMonitor('released');stopOutput();persist({closedAt:now()});
    }

    const storageListener=event=>{if(event.key!==voiceKey(userId)||destroyed)return;const next=event.newValue==='1';if(next!==enabled)setEnabled(next,{persist:false});};
    const visibilityListener=()=>{if(!destroyed&&enabled&&document.visibilityState==='visible')resume(80);};
    const onlineListener=()=>{if(!destroyed&&enabled)resume(80);};
    const gestureUnlock=event=>{if(event?.type==='keydown'&&event.repeat)return;void unlockPremiumAudio(event?.type||'gesture');};
    window.addEventListener('storage',storageListener);document.addEventListener('visibilitychange',visibilityListener);window.addEventListener('online',onlineListener);
    document.addEventListener('pointerdown',gestureUnlock,{capture:true,once:true,passive:true});document.addEventListener('keydown',gestureUnlock,{capture:true,once:true});
    window.addEventListener('pagehide',()=>{window.removeEventListener('storage',storageListener);document.removeEventListener('visibilitychange',visibilityListener);window.removeEventListener('online',onlineListener);document.removeEventListener('pointerdown',gestureUnlock,true);document.removeEventListener('keydown',gestureUnlock,true);destroy();},{once:true});

    persist({resumed:!!previousSession});if(enabled)startHealth();
    return {build:BUILD,controlBuild:CONTROL_BUILD,sessionId,proof,start:()=>setEnabled(enabled,{persist:false}),setEnabled,toggle:()=>{if(!enabled)void unlockPremiumAudio('toggle');return setEnabled(!enabled,{immediate:!enabled});},unlockAudio:unlockPremiumAudio,speak,createSpeechStream,resume,stopListening:stopRecognition,stopOutput,destroy,emitHealth,isEnabled:()=>enabled,isListening:()=>listening,isSpeaking:()=>speaking,isPreparing:()=>preparing,state:()=>state,snapshot};
  }

  const api={build:BUILD,controlBuild:CONTROL_BUILD,create,readShared,writeShared,key:voiceKey,sessionKey,readSession};
  window.StonefellowConversationVoiceV122=api;
})();
