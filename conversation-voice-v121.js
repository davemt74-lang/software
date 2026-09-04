(() => {
  'use strict';

  const BUILD='conversation-phase1-v121-20260826';

  function sharedKey(userId){return `stonefellow:voice-mode:${Number(userId||0)}`;}
  function readShared(userId){try{return localStorage.getItem(sharedKey(userId))==='1';}catch(error){return false;}}
  function writeShared(userId,enabled,source='conversation'){
    try{localStorage.setItem(sharedKey(userId),enabled?'1':'0');}catch(error){}
    window.dispatchEvent(new CustomEvent('stonefellow:voice-mode',{detail:{enabled:!!enabled,userId:Number(userId||0),source,build:BUILD}}));
  }

  function recognitionErrorMessage(error){
    switch(String(error||'')){
      case 'not-allowed':return 'Voice input is unavailable for this page.';
      case 'service-not-allowed':return 'The browser speech-recognition service is unavailable.';
      case 'audio-capture':return 'No usable audio input is available.';
      case 'network':return 'Voice recognition lost its network service. Reconnecting…';
      case 'no-speech':return 'Listening…';
      case 'aborted':return '';
      default:return 'Voice input paused. Reconnecting…';
    }
  }

  function cleanTranscript(value){return String(value||'').replace(/\s+/g,' ').trim();}
  function transcriptWords(value){return cleanTranscript(value).toLowerCase().replace(/[^\p{L}\p{N}'-]+/gu,' ').split(/\s+/).filter(word=>word.length>1);}
  function resemblesOutput(candidate,spoken){
    const words=transcriptWords(candidate);if(words.length<2)return false;
    const haystack=new Set(transcriptWords(spoken));
    let hits=0;for(const word of words)if(haystack.has(word))hits+=1;
    return hits/words.length>=.72;
  }

  function create(options={}){
    const userId=Number(options.userId||0);
    const source=String(options.source||'conversation');
    const SpeechRecognitionCtor=window.SpeechRecognition||window.webkitSpeechRecognition||null;
    const premiumVoice=window.StonefellowAgentVoiceV102?.({agentEndpoint:options.agentEndpoint,csrf:options.csrf})
      ||window.StonefellowPremiumVoiceV121?.({agentEndpoint:options.agentEndpoint,csrf:options.csrf})
      ||null;

    let enabled=!!options.initialEnabled||readShared(userId);
    let recognition=null;
    let recognitionMode='';
    let listening=false;
    let speaking=false;
    let preparing=false;
    let destroyed=false;
    let restartTimer=0;
    let generation=0;
    let activeOutput=null;
    let spokenOutput='';
    let bargeCapture=false;
    let bargeCandidate='';
    let bargeCandidateAt=0;
    let bargeCaptureTimer=0;

    const proof={
      build:BUILD,source,recognitionStarts:0,bargeRecognitionStarts:0,recognitionErrors:0,
      premiumStarts:0,browserFallbacks:0,interruptions:0,preservedInterruptions:0,
      echoCandidatesRejected:0,streamedTurns:0,enabled
    };

    const setState=(state,text='')=>{try{options.onState?.(state,text);}catch(error){}};
    const busy=()=>!!options.isBusy?.();

    const barge=window.StonefellowEditorVoiceBarge?.({
      isSpeaking:()=>speaking,
      interrupt:()=>beginInterruption()
    })||null;

    function clearRestart(){if(restartTimer)clearTimeout(restartTimer);restartTimer=0;}
    function clearBargeTimer(){if(bargeCaptureTimer)clearTimeout(bargeCaptureTimer);bargeCaptureTimer=0;}

    function usableBargeCandidate(){
      const candidate=cleanTranscript(bargeCandidate);
      if(!candidate||performance.now()-bargeCandidateAt>1800)return '';
      if(resemblesOutput(candidate,spokenOutput)){
        proof.echoCandidatesRejected+=1;
        return '';
      }
      return candidate;
    }

    function stopRecognition(update=false){
      clearRestart();
      const active=recognition;recognition=null;recognitionMode='';listening=false;
      try{active?.stop();}catch(error){}
      if(update&&enabled&&!busy()&&!speaking&&!preparing)setState('ready','Voice ready');
    }

    function scheduleRecognition(delay=140){
      clearRestart();
      if(!enabled||destroyed||busy()||speaking||preparing||recognition)return;
      restartTimer=window.setTimeout(()=>startRecognition('normal'),Math.max(25,delay));
    }

    function dispatchTranscript(text){
      const transcript=cleanTranscript(text);if(!transcript)return;
      setState('processing','Thinking…');
      try{
        const result=options.onTranscript?.(transcript);
        Promise.resolve(result).catch(error=>{try{options.onError?.(error);}catch(callbackError){}}).finally(()=>{
          if(enabled&&!destroyed&&!busy()&&!speaking&&!preparing&&!recognition)scheduleRecognition(180);
        });
      }catch(error){
        try{options.onError?.(error);}catch(callbackError){}
        if(enabled&&!busy())scheduleRecognition(260);
      }
    }

    function finishInterruptCapture(force=false){
      if(!bargeCapture)return false;
      const candidate=usableBargeCandidate();
      if(!candidate&&!force)return false;
      bargeCapture=false;clearBargeTimer();
      if(candidate){
        proof.preservedInterruptions+=1;
        stopRecognition(false);
        bargeCandidate='';bargeCandidateAt=0;
        dispatchTranscript(candidate);
        return true;
      }
      stopRecognition(false);
      bargeCandidate='';bargeCandidateAt=0;
      scheduleRecognition(35);
      return false;
    }

    function handleRecognitionResult(current,event,mode){
      if(recognition!==current)return;
      let finalParts=[];let interimParts=[];
      for(let i=0;i<event.results.length;i+=1){
        const result=event.results[i];const transcript=String(result?.[0]?.transcript||'');
        if(!transcript)continue;
        if(result.isFinal)finalParts.push(transcript);else interimParts.push(transcript);
      }
      const finalText=cleanTranscript(finalParts.join(' '));
      const interimText=cleanTranscript(interimParts.join(' '));
      const heard=cleanTranscript([finalText,interimText].filter(Boolean).join(' '));

      if(mode==='barge'){
        if(heard){bargeCandidate=heard;bargeCandidateAt=performance.now();}
        if(!bargeCapture)return;
        if(heard)setState('listening',`Interrupted · ${heard}`);
        if(finalText&&!resemblesOutput(finalText,spokenOutput))finishInterruptCapture(false);
        return;
      }

      if(heard)setState('listening',interimText?`Listening · ${interimText}`:'Listening…');
      if(!finalText)return;
      recognition=null;recognitionMode='';listening=false;
      try{current.stop();}catch(error){}
      dispatchTranscript(finalText);
    }

    function startRecognition(mode='normal'){
      clearRestart();
      if(!enabled||destroyed||recognition||!SpeechRecognitionCtor)return;
      if(mode==='normal'&&(busy()||speaking||preparing))return;
      if(mode==='barge'&&!speaking)return;

      const current=new SpeechRecognitionCtor();recognition=current;recognitionMode=mode;
      current.lang=options.language||document.documentElement.lang||'en-US';
      current.continuous=mode==='barge';current.interimResults=true;

      current.onstart=()=>{
        if(recognition!==current)return;
        listening=true;
        if(mode==='barge')proof.bargeRecognitionStarts+=1;else proof.recognitionStarts+=1;
        if(mode==='normal')setState('listening','Listening…');
      };
      current.onresult=event=>handleRecognitionResult(current,event,mode);
      current.onerror=event=>{
        if(recognition===current){recognition=null;recognitionMode='';}
        listening=false;proof.recognitionErrors+=1;
        const kind=String(event?.error||'unknown');const message=recognitionErrorMessage(kind);
        if(kind==='not-allowed'||kind==='service-not-allowed'){
          enabled=false;proof.enabled=false;writeShared(userId,false,source);
          bargeCapture=false;clearBargeTimer();try{barge?.release();}catch(error){}
          setState('error',message);try{options.onVoiceChange?.(false);}catch(error){};return;
        }
        if(mode==='barge'){
          if(bargeCapture&&finishInterruptCapture(true))return;
          if(speaking&&enabled)window.setTimeout(()=>startRecognition('barge'),160);
          return;
        }
        if(message)setState(kind==='no-speech'?'listening':'ready',message);
        if(enabled&&!busy()&&!speaking&&!preparing&&kind!=='aborted')scheduleRecognition(kind==='network'?800:420);
      };
      current.onend=()=>{
        if(recognition===current){recognition=null;recognitionMode='';}
        listening=false;
        if(mode==='barge'){
          if(bargeCapture&&finishInterruptCapture(true))return;
          if(speaking&&enabled&&!destroyed){window.setTimeout(()=>startRecognition('barge'),120);return;}
          if(enabled&&!busy()&&!speaking&&!preparing)scheduleRecognition(120);
          return;
        }
        if(enabled&&!busy()&&!speaking&&!preparing)scheduleRecognition(170);
      };
      try{current.start();}
      catch(error){if(recognition===current){recognition=null;recognitionMode='';}listening=false;if(mode==='normal')scheduleRecognition(350);}
    }

    function armBarge(localGeneration){
      if(!barge||localGeneration!==generation||!speaking)return;
      Promise.resolve(barge.ensure()).then(()=>{
        if(localGeneration!==generation||destroyed||!speaking){try{barge.release();}catch(error){};return;}
        try{barge.start();}catch(error){}
      }).catch(()=>{});
      window.setTimeout(()=>{if(localGeneration===generation&&speaking&&!recognition)startRecognition('barge');},90);
    }

    function beginInterruption(){
      if(!speaking||destroyed)return;
      proof.interruptions+=1;
      generation+=1;
      try{activeOutput?.stop?.();}catch(error){}
      activeOutput=null;
      try{window.speechSynthesis?.cancel();}catch(error){}
      try{barge?.release();}catch(error){}
      preparing=false;speaking=false;bargeCapture=true;
      try{options.onInterrupt?.();}catch(error){}
      setState('listening','Interrupted · listening…');

      // The standby recognizer has been running while Stonefellow speaks. Give
      // it a short window to finalize the phrase so the first word is retained.
      if(recognition&&recognitionMode==='barge'){
        clearBargeTimer();
        bargeCaptureTimer=window.setTimeout(()=>finishInterruptCapture(true),850);
        const prefix=usableBargeCandidate();
        if(prefix)window.setTimeout(()=>{if(bargeCapture)finishInterruptCapture(false);},260);
        return;
      }
      scheduleRecognition(25);
    }

    function finishSpeaking(localGeneration){
      if(localGeneration!==generation||destroyed)return;
      try{barge?.release();}catch(error){}
      if(recognitionMode==='barge')stopRecognition(false);
      bargeCapture=false;bargeCandidate='';bargeCandidateAt=0;clearBargeTimer();
      activeOutput=null;preparing=false;speaking=false;
      if(enabled){setState('ready','Voice ready');scheduleRecognition(140);}else setState('idle');
    }

    function browserSpeak(text,localGeneration){
      const message=cleanTranscript(text);
      if(!enabled||destroyed||!message||!('speechSynthesis'in window)||!window.SpeechSynthesisUtterance){preparing=false;scheduleRecognition(100);return;}
      proof.browserFallbacks+=1;preparing=true;spokenOutput=message;
      try{window.speechSynthesis.cancel();}catch(error){}
      const utterance=new SpeechSynthesisUtterance(message);activeOutput={stop:()=>{try{window.speechSynthesis.cancel();}catch(error){}}};
      utterance.onstart=()=>{
        if(localGeneration!==generation||destroyed)return;
        preparing=false;speaking=true;setState('speaking','Stonefellow is responding…');armBarge(localGeneration);
      };
      utterance.onend=utterance.onerror=()=>finishSpeaking(localGeneration);
      try{window.speechSynthesis.speak(utterance);}catch(error){finishSpeaking(localGeneration);}
    }

    function beginOutput(){
      stopRecognition(false);generation+=1;const localGeneration=generation;
      try{activeOutput?.stop?.();}catch(error){};activeOutput=null;
      try{barge?.release();}catch(error){}
      bargeCapture=false;bargeCandidate='';bargeCandidateAt=0;clearBargeTimer();spokenOutput='';
      preparing=true;speaking=false;setState('processing','Preparing voice…');
      return localGeneration;
    }

    function createSpeechStream(){
      if(!enabled||destroyed)return null;
      const localGeneration=beginOutput();proof.streamedTurns+=1;
      let fallbackText='';
      if(!premiumVoice?.createStream){
        return {
          push(delta){fallbackText+=String(delta||'');spokenOutput+=String(delta||'');},
          end(){preparing=false;browserSpeak(fallbackText,localGeneration);},
          stop(){generation+=1;try{window.speechSynthesis?.cancel();}catch(error){};preparing=false;speaking=false;},
          started:Promise.resolve(false)
        };
      }

      const stream=premiumVoice.createStream({
        onStart:()=>{
          if(localGeneration!==generation||destroyed)return;
          preparing=false;speaking=true;proof.premiumStarts+=1;setState('speaking','Stonefellow is responding…');armBarge(localGeneration);
        },
        onEnd:()=>finishSpeaking(localGeneration),
        onError:error=>{
          if(localGeneration!==generation||destroyed)return;
          preparing=false;speaking=false;try{options.onOutputError?.(error);}catch(callbackError){}
          if(fallbackText)browserSpeak(fallbackText,localGeneration);else finishSpeaking(localGeneration);
        }
      });
      activeOutput=stream;
      return {
        push(delta){const text=String(delta||'');fallbackText+=text;spokenOutput+=text;stream.push(text);},
        end(){stream.end();},
        stop(){stream.stop();},
        started:stream.started,
        done:stream.done
      };
    }

    async function speak(text){
      const message=cleanTranscript(text);if(!enabled||!message||destroyed)return;
      const stream=createSpeechStream();if(!stream)return;
      stream.push(message);stream.end();
      try{return await stream.started;}catch(error){if(error?.name!=='AbortError')try{options.onOutputError?.(error);}catch(callbackError){}}
    }

    function stopOutput(){
      generation+=1;try{activeOutput?.stop?.();}catch(error){};activeOutput=null;
      try{premiumVoice?.stop?.();}catch(error){};try{window.speechSynthesis?.cancel();}catch(error){}
      try{barge?.release();}catch(error){};if(recognitionMode==='barge')stopRecognition(false);
      bargeCapture=false;bargeCandidate='';clearBargeTimer();preparing=false;speaking=false;
    }

    function setEnabled(next,opts={}){
      enabled=!!next;proof.enabled=enabled;if(opts.persist!==false)writeShared(userId,enabled,source);
      try{options.onVoiceChange?.(enabled);}catch(error){}
      if(!enabled){stopRecognition(false);stopOutput();setState('idle','Voice conversation off');return;}
      setState('ready','Voice conversation on');try{void premiumVoice?.warm?.();}catch(error){};scheduleRecognition(45);
    }

    function resume(delay=120){if(enabled&&!destroyed&&!busy()&&!speaking&&!preparing)scheduleRecognition(delay);}
    function destroy(){destroyed=true;clearRestart();clearBargeTimer();stopRecognition(false);stopOutput();}

    const storageListener=event=>{
      if(event.key!==sharedKey(userId)||destroyed)return;
      const next=event.newValue==='1';if(next!==enabled)setEnabled(next,{persist:false});
    };
    window.addEventListener('storage',storageListener);
    window.addEventListener('pagehide',()=>{window.removeEventListener('storage',storageListener);destroy();},{once:true});

    return {
      build:BUILD,proof,start:()=>setEnabled(enabled,{persist:false}),setEnabled,toggle:()=>setEnabled(!enabled),
      speak,createSpeechStream,resume,stopListening:stopRecognition,stopOutput,destroy,
      isEnabled:()=>enabled,isListening:()=>listening,isSpeaking:()=>speaking,isPreparing:()=>preparing
    };
  }

  const api={build:BUILD,create,readShared,writeShared,key:sharedKey};
  window.StonefellowConversationVoiceV121=api;
  window.StonefellowConversationVoiceV120=api;
})();