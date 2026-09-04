(() => {
  'use strict';

  const BUILD='chat-voice-canonical-20260903';
  const POST_SPEECH_ECHO_MS=4000;
  const POST_SPEECH_LISTEN_DELAY=360;
  const TURN_END_PAUSE_MS=1800;
  const BARGE_RELEASE_TIMEOUT_MS=650;
  const NEAR_FIELD_WINDOW_MS=1100;
  const START_WATCHDOG_MS=2200;
  const DUPLICATE_WINDOW_MS=2600;
  const SHORT_COMMANDS=new Set(['yes','no','stop','pause','play','mute','solo','cancel','continue','resume','next','back','save','undo','redo','listen']);
  const cfg=window.STONEFELLOW_CHAT||{};
  const boot=window.STONEFELLOW_CHAT_VOICE_BOOT||{};
  const form=document.getElementById('chatForm');
  const input=document.getElementById('chatInput');
  const send=document.getElementById('sendChatButton');
  const status=document.getElementById('chatVoiceStatus');
  const thread=document.getElementById('chatThread');
  const welcome=document.getElementById('chatWelcome');
  const button=boot.button||document.getElementById('chatVoiceButtonLegacyDormant')||document.getElementById('chatVoiceButton');
  const SpeechRecognitionCtor=window.SpeechRecognition||window.webkitSpeechRecognition||null;
  const AgentContext=window.StonefellowAgentContext||null;
  const PremiumVoice=window.StonefellowPremiumVoiceV122||null;

  if(!form||!input||!send||!button)return;

  if(button.id!=='chatVoiceButton')button.id='chatVoiceButton';
  button.disabled=typeof SpeechRecognitionCtor!=='function';
  button.title=button.disabled?'Live speech recognition is not supported by this browser.':'Start or stop voice conversation with Stonefellow';

  const userId=Number(cfg.userId||0);
  const MODE_KEY=`stonefellow:voice-mode:${userId}`;
  const previousFetch=window.fetch;
  const nativeFetch=previousFetch.bind(window);
  const chatUrl=(()=>{try{return new URL(String(cfg.endpoint||''),location.href);}catch(error){return null;}})();
  const chatStreamUrl=(()=>{try{return chatUrl?new URL('chat-stream-v121.php',chatUrl).toString():'';}catch(error){return '';}})();
  const premium=typeof PremiumVoice==='function'?PremiumVoice({agentEndpoint:String(cfg.endpoint||'/api/chat.php'),csrf:String(cfg.csrf||'')}):null;

  let voiceOn=false;
  try{voiceOn=localStorage.getItem(MODE_KEY)==='1';}catch(error){}

  let recognition=null;
  let recognitionStarting=false;
  let recognitionListening=false;
  let recognitionStopReason='';
  let restartTimer=0;
  let recognitionStartTimer=0;
  let recentAccepted=[];
  let inputStream=null;
  let inputTrack=null;
  let inputTrackPromise=null;
  let inputTrackMode='default';
  let inputContext=null;
  let inputAnalyser=null;
  let inputMonitorFrame=0;
  let inputNoiseFloor=.012;
  let lastNearFieldAt=0;

  let bargeRecognition=null;
  let bargeStarting=false;
  let bargeListening=false;
  let bargeCapturing=false;
  let bargeLastText='';
  let bargeRestartTimer=0;
  let bargeCandidateText='';
  let bargeCandidateNormalized='';
  let bargeCandidateHits=0;
  let bargeCandidateAt=0;
  let bargeCaptureTimer=0;
  let bargeArmPending=false;
  let bargeArmTimer=0;

  let processing=false;
  let speaking=false;
  let textBusy=false;
  let voiceSubmitPending=false;
  let queuedTranscript='';
  let activeRequest=null;
  let lastConversationId=Math.max(0,Number(cfg.initialConversationId||AgentContext?.conversationId?.()||0));
  let introPresented=false;
  let pendingIntroSpeech='';
  let introRetryScheduled=false;
  let pendingFinalTranscript='';
  let turnEndTimer=0;
  let currentSpokenText='';
  let lastSpokenText='';
  let lastSpeechEndedAt=0;
  let speechEpoch=0;
  let activeSpeechEpoch=0;
  const events=[];
  let systemFallbackAnnounced=false;

  const proof=window.STONEFELLOW_CHAT_VOICE={
    build:BUILD,
    lifecycleBuild:'v157',
    echoGuardBuild:'v157',
    fastVoiceBuild:'v144',
    pauseWindowMs:TURN_END_PAUSE_MS,
    pauseExtensions:0,
    deferredIntroSpeech:0,
    autoplayBlocks:0,
    aiTextStreaming:true,
    elevenLabsExclusive:false,
    elevenLabsPrimary:true,
    fastStreamTurns:0,
    fastStreamDeltas:0,
    premiumReady:false,
    premiumAudibleStarts:0,
    premiumPrestartFailures:0,
    premiumFullRetries:0,
    processedMicReady:false,
    processedTrackStarts:0,
    defaultMicStarts:0,
    micErrors:0,
    micMonitorReady:false,
    nearFieldSignals:0,
    noiseRejects:0,
    fillerRejects:0,
    lowConfidenceRejected:0,
    duplicatesRejected:0,
    recognitionStartTimeouts:0,
    recognizerReleaseWaits:0,
    recognizerReleaseTimeouts:0,
    echoCancellationMode:'pending',
    echoCancellationFallbacks:0,
    systemFallbacks:0,
    loaded:true,
    directOwner:true,
    singleChatApi:true,
    sharedConversationController:false,
    webAudioBarge:false,
    speechRecognitionBarge:true,
    startCalls:0,
    starts:0,
    results:0,
    finals:0,
    errors:0,
    ends:0,
    submits:0,
    apiTurns:0,
    apiSuccess:0,
    apiErrors:0,
    speechStarts:0,
    speechEnds:0,
    interruptions:0,
    bargeStartCalls:0,
    bargeStarts:0,
    bargeResults:0,
    bargeErrors:0,
    bargeSubmits:0,
    bargeCandidates:0,
    bargeFastCuts:0,
    bargeCaptureTimeouts:0,
    echoRejects:0,
    bargeEchoRejects:0,
    postSpeechEchoRejects:0,
    echoCommandOverrides:0,
    premiumUnlockRequests:0,
    intros:0,
    lastError:'',
    lastTranscript:'',
    lastBargeTranscript:'',
    lastConversationId
  };

  function log(type,detail={}){
    const item={at:new Date().toISOString(),type:String(type),detail};
    events.push(item);
    if(events.length>220)events.splice(0,events.length-220);
    try{window.dispatchEvent(new CustomEvent('stonefellow:chat-voice',{detail:{build:BUILD,...item}}));}catch(error){}
    renderDebug();
  }

  function requestPremiumUnlock(reason='gesture'){
    if(typeof premium?.unlock!=='function')return Promise.resolve(false);
    proof.premiumUnlockRequests+=1;
    log('PREMIUM_UNLOCK_REQUEST',{reason,count:proof.premiumUnlockRequests,userActivation:!!navigator.userActivation?.isActive});
    try{
      return Promise.resolve(premium.unlock()).then(unlocked=>{
        log(unlocked?'PREMIUM_UNLOCKED':'PREMIUM_UNLOCK_DEFERRED',{reason});
        if(unlocked&&pendingIntroSpeech&&voiceOn&&!processing&&!speaking&&!introRetryScheduled){
          introRetryScheduled=true;
          setTimeout(()=>{
            introRetryScheduled=false;
            if(!voiceOn||processing||speaking||!pendingIntroSpeech)return;
            speakAnswer(pendingIntroSpeech);
          },0);
        }
        return !!unlocked;
      }).catch(error=>{
        log('PREMIUM_UNLOCK_ERROR',{reason,message:String(error?.message||error||'Audio unlock failed')});
        return false;
      });
    }catch(error){
      log('PREMIUM_UNLOCK_ERROR',{reason,message:String(error?.message||error||'Audio unlock failed')});
      return Promise.resolve(false);
    }
  }

  function setStatus(text='',state=''){
    if(!status)return;
    status.hidden=!text;
    status.textContent=text;
    status.dataset.state=state;
  }

  function syncButton(){
    button.classList.toggle('active',voiceOn);
    button.setAttribute('aria-pressed',voiceOn?'true':'false');
    button.setAttribute('aria-label',voiceOn?'Stop voice conversation':'Start voice conversation');
  }

  function setAgentState(state,text=''){
    const visual=state||'idle';
    document.body.dataset.stonefellowAgentState=visual;
    button.dataset.agentState=visual;
    button.classList.toggle('ai-listening',visual==='listening');
    button.classList.toggle('ai-thinking',visual==='processing');
    button.classList.toggle('ai-responding',visual==='speaking');
    if(visual==='listening')setStatus(text||'Listening…','listening');
    else if(visual==='processing')setStatus(text||'Thinking…','processing');
    else if(visual==='speaking')setStatus(text||'Stonefellow is responding…','speaking');
    else if(visual==='error')setStatus(text||'Voice conversation is unavailable.','error');
    else if(voiceOn)setStatus(text||'Voice conversation on','ready');
    else setStatus('','');
  }

  function writeMode(){
    try{localStorage.setItem(MODE_KEY,voiceOn?'1':'0');}catch(error){}
    syncButton();
    try{window.dispatchEvent(new CustomEvent('stonefellow:voice-mode',{detail:{userId,enabled:voiceOn,source:'agent-chat'}}));}catch(error){}
  }

  function activeConversationId(){
    const active=document.querySelector('.chat-history-item.active[data-conversation-id]');
    return Math.max(0,Number(active?.dataset.conversationId||lastConversationId||cfg.initialConversationId||0));
  }

  function syncConversation(id){
    const next=Math.max(0,Number(id||0));
    if(next<1)return;
    lastConversationId=next;
    proof.lastConversationId=next;
    AgentContext?.setConversationId?.(next);
  }

  function updateComposer(text){
    input.value=String(text||'');
    input.dispatchEvent(new Event('input',{bubbles:true}));
  }

  function clearRestart(){if(restartTimer)clearTimeout(restartTimer);restartTimer=0;}
  function clearStartWatchdog(){if(recognitionStartTimer)clearTimeout(recognitionStartTimer);recognitionStartTimer=0;}
  function clearTurnEndTimer(){if(turnEndTimer)clearTimeout(turnEndTimer);turnEndTimer=0;}
  function resetPendingFinal(){clearTurnEndTimer();pendingFinalTranscript='';}
  function clearBargeRestart(){if(bargeRestartTimer)clearTimeout(bargeRestartTimer);bargeRestartTimer=0;}
  function clearBargeCaptureTimer(){if(bargeCaptureTimer)clearTimeout(bargeCaptureTimer);bargeCaptureTimer=0;}
  function clearBargeArmTimer(){if(bargeArmTimer)clearTimeout(bargeArmTimer);bargeArmTimer=0;}
  function clearBargeCandidate(){bargeCandidateText='';bargeCandidateNormalized='';bargeCandidateHits=0;bargeCandidateAt=0;}

  function recordBargeCandidate(text){
    const heard=String(text||'').trim();
    const normalized=normalizeSpeechText(heard);
    const now=Date.now();
    const related=bargeCandidateNormalized&&now-bargeCandidateAt<1200&&(normalized.startsWith(bargeCandidateNormalized)||bargeCandidateNormalized.startsWith(normalized));
    if(related)bargeCandidateHits+=1;else bargeCandidateHits=1;
    bargeCandidateText=heard;bargeCandidateNormalized=normalized;bargeCandidateAt=now;proof.bargeCandidates+=1;return bargeCandidateHits;
  }

  function scheduleBargeCaptureFinish(){
    clearBargeCaptureTimer();if(!bargeCapturing)return;
    bargeCaptureTimer=setTimeout(()=>{if(!bargeCapturing)return;proof.bargeCaptureTimeouts+=1;log('BARGE_CAPTURE_TIMEOUT',{text:bargeLastText,count:proof.bargeCaptureTimeouts});finishBargeCapture(bargeLastText,'silence-timeout');},1400);
  }

  function scheduleListening(delay=180,reason='resume'){
    clearRestart();
    if(!voiceOn||speaking||processing||textBusy||voiceSubmitPending||bargeCapturing)return;
    if(!recognitionListening&&!recognitionStarting)setAgentState('listening',pendingFinalTranscript?`Listening · ${pendingFinalTranscript}`:'Listening…');
    restartTimer=setTimeout(()=>startListening(reason),Math.max(0,Number(delay)||0));
  }

  function stopRecognition(reason='pause',abort=false){
    clearRestart();clearStartWatchdog();recognitionStopReason=reason;const current=recognition;if(!current)return;
    try{abort?current.abort():current.stop();}catch(error){}
  }

  function normalizeSpeechText(value){return String(value||'').toLowerCase().replace(/[^a-z0-9\s']/g,' ').replace(/\s+/g,' ').trim();}
  function speechTokens(value){return normalizeSpeechText(value).split(' ').filter(Boolean);}
  function normalizeTranscriptText(value){return String(value||'').toLowerCase().replace(/[^\p{L}\p{N}'-]+/gu,' ').replace(/\s+/g,' ').trim();}
  function transcriptWords(value){return normalizeTranscriptText(value).split(/\s+/).filter(Boolean);}

  function transcriptFingerprint(value){
    const text=normalizeTranscriptText(value);let hash=2166136261;
    for(let i=0;i<text.length;i+=1){hash^=text.charCodeAt(i);hash=Math.imul(hash,16777619);}
    return (hash>>>0).toString(36);
  }
  function confidenceFor(result){
    const value=Number(result?.[0]?.confidence);
    return Number.isFinite(value)&&value>0&&value<=1?value:0;
  }
  function acceptTranscript(text,confidence){
    const clean=String(text||'').trim();if(!clean)return {ok:false,reason:'empty'};
    const words=transcriptWords(clean);if(!words.length)return {ok:false,reason:'empty'};
    if(confidence<=0)return {ok:true,reason:'unreported'};
    const threshold=words.length<=1?.58:.34;
    if(words.length===1&&SHORT_COMMANDS.has(words[0]))return {ok:confidence>=.28,reason:'short-command'};
    return {ok:confidence>=threshold,reason:confidence>=threshold?'confidence':'low-confidence'};
  }
  function pruneRecentAccepted(){
    const cutoff=Date.now()-DUPLICATE_WINDOW_MS;
    recentAccepted=recentAccepted.filter(item=>item.at>=cutoff);
  }
  function isDuplicateTranscript(text){
    pruneRecentAccepted();const fp=transcriptFingerprint(text);return recentAccepted.some(item=>item.fp===fp);
  }
  function rememberAcceptedTranscript(text){
    pruneRecentAccepted();recentAccepted.push({fp:transcriptFingerprint(text),at:Date.now()});
  }
  function containsTokenSequence(haystack,needle){if(!needle.length||needle.length>haystack.length)return false;outer:for(let i=0;i<=haystack.length-needle.length;i+=1){for(let j=0;j<needle.length;j+=1){if(haystack[i+j]!==needle[j])continue outer;}return true;}return false;}

  function isLikelyStonefellowEcho(value,reference=currentSpokenText,{allowSingle=true}={}){
    const heard=speechTokens(value);const spoken=speechTokens(reference);if(!heard.length||!spoken.length)return false;
    if(heard.length>=2&&containsTokenSequence(spoken,heard))return true;
    const significant=heard.filter(word=>word.length>2);const spokenSignificant=spoken.filter(word=>word.length>2);
    if(!significant.length||!spokenSignificant.length)return false;const spokenSet=new Set(spokenSignificant);
    if(allowSingle&&significant.length===1&&spokenSet.has(significant[0]))return true;if(significant.length<2)return false;
    const overlap=significant.filter(word=>spokenSet.has(word)).length/significant.length;if(significant.length===2&&overlap===1)return true;if(overlap>=0.78)return true;
    let ordered=0;let cursor=0;for(const word of significant){const index=spokenSignificant.indexOf(word,cursor);if(index<0)continue;ordered+=1;cursor=index+1;}
    return significant.length>=4&&overlap>=0.55&&(ordered/significant.length)>=0.7;
  }

  function isPostSpeechEcho(value){if(!lastSpokenText||!lastSpeechEndedAt)return false;const age=Date.now()-lastSpeechEndedAt;if(age<0||age>POST_SPEECH_ECHO_MS)return false;return isLikelyStonefellowEcho(value,lastSpokenText,{allowSingle:false});}
  function isImmediateBargeCommand(value){const heard=normalizeSpeechText(value);return /^(?:stop|wait|pause|cancel|hold on|hang on|one second|just a second)\b/.test(heard);}
  function isLowValueTranscript(value){
    const heard=normalizeSpeechText(value);if(!heard)return true;
    if(/^(?:(?:ok(?:ay)?|um+|uh+|hmm+|yeah|yep|mhm|right|alright)\s*)+$/.test(heard))return true;
    return /^(?:(?:ok(?:ay)?|um+|uh+|yeah|yep|hmm+)\s+)*(?:this is|this is going|it is|it is going|it's|it's going)$/.test(heard);
  }
  function isAutoplayBlockedError(error){return /Browser blocked ElevenLabs audio|NotAllowedError|play\(\) failed because the user didn't interact|user didn't interact/i.test(String(error?.message||error||''));}

  function hasRecentNearFieldSpeech(windowMs=NEAR_FIELD_WINDOW_MS){
    if(!proof.micMonitorReady)return true;
    return performance.now()-lastNearFieldAt<=Math.max(200,Number(windowMs)||NEAR_FIELD_WINDOW_MS);
  }

  function startInputMonitor(stream){
    if(inputMonitorFrame||!stream||typeof (window.AudioContext||window.webkitAudioContext)!=='function')return;
    try{
      const Context=window.AudioContext||window.webkitAudioContext;
      inputContext=new Context();
      const source=inputContext.createMediaStreamSource(stream);
      inputAnalyser=inputContext.createAnalyser();
      inputAnalyser.fftSize=512;inputAnalyser.smoothingTimeConstant=.18;source.connect(inputAnalyser);
      const samples=new Float32Array(inputAnalyser.fftSize);proof.micMonitorReady=true;
      const tick=()=>{
        if(!inputAnalyser||!inputStream){inputMonitorFrame=0;return;}
        inputAnalyser.getFloatTimeDomainData(samples);let sum=0;
        for(const sample of samples)sum+=sample*sample;
        const rms=Math.sqrt(sum/Math.max(1,samples.length));
        const quiet=rms<inputNoiseFloor*1.8;
        if(quiet)inputNoiseFloor=(inputNoiseFloor*.94)+(Math.max(.004,rms)*.06);
        const threshold=Math.max(.018,inputNoiseFloor*2.65+.004);
        if(rms>=threshold){lastNearFieldAt=performance.now();proof.nearFieldSignals+=1;}
        inputMonitorFrame=requestAnimationFrame(tick);
      };
      inputMonitorFrame=requestAnimationFrame(tick);
      log('MIC_MONITOR_READY',{noiseFloor:inputNoiseFloor});
    }catch(error){proof.micMonitorReady=false;log('MIC_MONITOR_ERROR',{message:String(error?.message||error||'')});}
  }

  function releaseProcessedMic(reason='release'){
    const stream=inputStream;inputStream=null;inputTrack=null;inputTrackPromise=null;inputTrackMode='default';proof.processedMicReady=false;proof.micMonitorReady=false;lastNearFieldAt=0;
    if(inputMonitorFrame)cancelAnimationFrame(inputMonitorFrame);inputMonitorFrame=0;inputAnalyser=null;
    if(inputContext){try{inputContext.close();}catch(error){}}inputContext=null;
    if(stream){for(const track of stream.getTracks?.()||[]){try{track.stop();}catch(error){}}}log('MIC_TRACK_RELEASED',{reason});
  }

  async function ensureProcessedMic(){
    if(inputTrack&&inputTrack.readyState==='live')return inputTrack;if(inputTrackPromise)return inputTrackPromise;if(!navigator.mediaDevices?.getUserMedia)return null;
    inputTrackPromise=(async()=>{
      try{
        const supported=navigator.mediaDevices.getSupportedConstraints?.()||{};const audio={echoCancellation:'all',noiseSuppression:true,autoGainControl:true,channelCount:1};if(supported.voiceIsolation)audio.voiceIsolation=true;
        let stream=null;
        try{stream=await navigator.mediaDevices.getUserMedia({audio,video:false});proof.echoCancellationMode='all';log('MIC_ECHO_MODE',{mode:'all'});}
        catch(error){const name=String(error?.name||'');const compatibleFallback=name==='TypeError'||name==='OverconstrainedError'||name==='NotSupportedError';if(!compatibleFallback)throw error;audio.echoCancellation=true;proof.echoCancellationFallbacks+=1;proof.echoCancellationMode='default';log('MIC_ECHO_MODE_FALLBACK',{from:'all',to:'default',name,count:proof.echoCancellationFallbacks});stream=await navigator.mediaDevices.getUserMedia({audio,video:false});}
        const track=stream.getAudioTracks?.()[0]||null;if(!track||track.readyState!=='live')throw new Error('Processed microphone track is not live.');
        try{track.contentHint='speech-recognition';}catch(error){try{track.contentHint='speech';}catch(ignore){}}
        inputStream=stream;inputTrack=track;inputTrackMode='monitor';proof.processedMicReady=true;startInputMonitor(stream);log('MIC_TRACK_READY',{label:String(track.label||''),settings:track.getSettings?.()||{},constraints:track.getConstraints?.()||{}});
        track.addEventListener?.('ended',()=>{if(inputTrack===track)releaseProcessedMic('ended');},{once:true});return track;
      }catch(error){proof.micErrors+=1;proof.processedMicReady=false;inputTrackMode='default';log('MIC_TRACK_ERROR',{message:String(error?.message||error||'Microphone processing failed'),count:proof.micErrors});return null;}
      finally{inputTrackPromise=null;}
    })();return inputTrackPromise;
  }

  function startRecognizer(current,purpose='listen'){
    current.start();proof.defaultMicStarts+=1;inputTrackMode=inputTrack?.readyState==='live'?'monitor':'default';log('SR_DEFAULT_MIC_START',{purpose,count:proof.defaultMicStarts,processedMonitor:proof.micMonitorReady});return true;
  }

  function queueFinalTranscript(text){
    const part=String(text||'').trim();if(!part)return;
    if(pendingFinalTranscript)proof.pauseExtensions+=1;
    pendingFinalTranscript=[pendingFinalTranscript,part].filter(Boolean).join(' ').replace(/\s+/g,' ').trim();
    proof.lastTranscript=pendingFinalTranscript;updateComposer(pendingFinalTranscript);setAgentState('listening',`Listening · ${pendingFinalTranscript}`);
    clearTurnEndTimer();
    turnEndTimer=setTimeout(()=>{
      turnEndTimer=0;if(!voiceOn||processing||speaking||textBusy||voiceSubmitPending||bargeCapturing)return;
      const transcript=pendingFinalTranscript;pendingFinalTranscript='';
      stopRecognition('submit',true);log('TURN_END_COMMIT',{pauseMs:TURN_END_PAUSE_MS,text:transcript});submitVoiceTranscript(transcript);
    },TURN_END_PAUSE_MS);
  }

  function createRecognition(){
    const current=new SpeechRecognitionCtor();current.continuous=false;current.interimResults=true;current.lang=document.documentElement.lang||'en-US';
    current.onstart=()=>{if(recognition!==current)return;clearStartWatchdog();recognitionStarting=false;recognitionListening=true;proof.starts+=1;setAgentState('listening',pendingFinalTranscript?`Listening · ${pendingFinalTranscript}`:'Listening…');log('SR_STARTED',{starts:proof.starts});};
    current.onresult=event=>{
      if(recognition!==current)return;if(speaking||processing){log('SR_RESULT_IGNORED_BUSY',{speaking,processing});return;}
      proof.results+=1;let interim='';let finalText='';let finalConfidence=0;const start=Math.max(0,Number(event.resultIndex||0));
      for(let i=start;i<event.results.length;i+=1){const result=event.results[i];const text=String(result?.[0]?.transcript||'');if(result?.isFinal){finalText+=text;finalConfidence=Math.max(finalConfidence,confidenceFor(result));}else interim+=text;}
      const heard=String(finalText||interim||'').trim();if(!heard)return;const commandOverride=isImmediateBargeCommand(heard);
      if(isPostSpeechEcho(heard)&&!commandOverride){proof.echoRejects+=1;proof.postSpeechEchoRejects+=1;log('SR_ECHO_REJECTED',{phase:'post-speech',final:!!finalText,text:heard,ageMs:Date.now()-lastSpeechEndedAt,count:proof.postSpeechEchoRejects});if(finalText.trim()){recognitionStopReason='echo-tail';try{current.stop();}catch(error){}}return;}
      if(commandOverride&&isPostSpeechEcho(heard)){proof.echoCommandOverrides+=1;log('SR_ECHO_COMMAND_OVERRIDE',{text:heard,count:proof.echoCommandOverrides});}
      const preview=[pendingFinalTranscript,heard].filter(Boolean).join(' ').replace(/\s+/g,' ').trim();proof.lastTranscript=preview;updateComposer(preview);setAgentState('listening',`Listening · ${preview}`);log('SR_RESULT',{final:!!finalText,text:preview});
      if(!finalText.trim())return;const finalTranscript=finalText.trim();const acceptance=acceptTranscript(finalTranscript,finalConfidence);if(!acceptance.ok){proof.lowConfidenceRejected+=1;updateComposer(pendingFinalTranscript);log('TRANSCRIPT_LOW_CONFIDENCE_REJECTED',{text:finalTranscript,confidence:finalConfidence,reason:acceptance.reason,count:proof.lowConfidenceRejected});recognitionStopReason='rejected';try{current.stop();}catch(error){}return;}if(isDuplicateTranscript(finalTranscript)){proof.duplicatesRejected+=1;updateComposer(pendingFinalTranscript);log('TRANSCRIPT_DUPLICATE_REJECTED',{text:finalTranscript,count:proof.duplicatesRejected});recognitionStopReason='duplicate';try{current.stop();}catch(error){}return;}rememberAcceptedTranscript(finalTranscript);proof.finals+=1;queueFinalTranscript(finalTranscript);recognitionStopReason='pause-window';try{current.stop();}catch(error){}
    };
    current.onerror=event=>{
      if(recognition!==current)return;clearStartWatchdog();recognitionStarting=false;recognitionListening=false;proof.errors+=1;const kind=String(event?.error||'unknown');proof.lastError=kind;log('SR_ERROR',{error:kind,message:String(event?.message||'')});
      if(kind==='not-allowed'||kind==='service-not-allowed'){resetPendingFinal();voiceOn=false;writeMode();setAgentState('error','Microphone permission is blocked.');return;}
      if(kind==='audio-capture'){resetPendingFinal();setAgentState('error','No usable microphone input is available.');return;}
      recognition=null;
      if(speaking&&bargeArmPending){bargeArmPending=false;clearBargeArmTimer();setTimeout(()=>startBarge(),0);return;}
      if(voiceOn&&!processing&&!speaking&&!bargeCapturing)scheduleListening(kind==='no-speech'?100:260,`error:${kind}`);
    };
    current.onend=()=>{
      if(recognition!==current)return;clearStartWatchdog();recognitionStarting=false;recognitionListening=false;proof.ends+=1;const reason=recognitionStopReason;recognitionStopReason='';log('SR_ENDED',{reason,ends:proof.ends});
      recognition=null;
      if(speaking&&bargeArmPending){bargeArmPending=false;clearBargeArmTimer();setTimeout(()=>startBarge(),0);return;}
      if(!voiceOn||processing||speaking||voiceSubmitPending||textBusy||bargeCapturing)return;if(reason==='submit'||reason==='response'||reason==='off'||reason==='pagehide')return;
      scheduleListening(reason==='pause-window'?100:reason==='echo-tail'?260:180,'onend');
    };return current;
  }

  function startListening(reason='manual'){
    clearRestart();if(!voiceOn||processing||speaking||textBusy||voiceSubmitPending||bargeCapturing||recognitionStarting||recognitionListening)return false;
    if(typeof SpeechRecognitionCtor!=='function'){setAgentState('error','Browser speech recognition is unavailable.');return false;}
    if(!recognition)recognition=createRecognition();const current=recognition;proof.startCalls+=1;recognitionStopReason='';recognitionStarting=true;setAgentState('listening',pendingFinalTranscript?`Listening · ${pendingFinalTranscript}`:'Listening…');log('SR_START_CALL',{reason,call:proof.startCalls,userActivation:!!navigator.userActivation?.isActive});
    const launch=()=>{try{startRecognizer(current,'listen');clearStartWatchdog();recognitionStartTimer=setTimeout(()=>{if(recognition!==current||!recognitionStarting)return;proof.recognitionStartTimeouts+=1;recognitionStarting=false;recognitionListening=false;proof.lastError='Speech recognition start timed out';log('SR_START_TIMEOUT',{count:proof.recognitionStartTimeouts,timeoutMs:START_WATCHDOG_MS});try{current.abort();}catch(error){}recognition=null;if(voiceOn&&!processing&&!speaking)scheduleListening(260,'start-watchdog');},START_WATCHDOG_MS);return true;}catch(error){clearStartWatchdog();recognitionStarting=false;proof.errors+=1;proof.lastError=String(error?.message||error||'start failed');log('SR_START_THROW',{name:String(error?.name||'Error'),message:proof.lastError});recognition=null;if(voiceOn)scheduleListening(260,'start-throw');return false;}};
    void ensureProcessedMic();return launch();
  }

  function stopBarge(reason='stop',abort=true){clearBargeRestart();clearBargeCaptureTimer();clearBargeCandidate();const current=bargeRecognition;bargeRecognition=null;bargeStarting=false;bargeListening=false;if(current&&abort){try{current.abort();}catch(error){}}log('BARGE_SR_STOP',{reason});}
  function finishBargeCapture(text,reason='final'){const transcript=String(text||bargeLastText||'').trim();clearBargeCaptureTimer();bargeCapturing=false;bargeLastText='';stopBarge(`capture-${reason}`);if(!transcript){if(voiceOn&&!processing&&!speaking)scheduleListening(80,'barge-empty');return;}proof.bargeSubmits+=1;proof.lastBargeTranscript=transcript;log('BARGE_SUBMIT',{text:transcript,reason,count:proof.bargeSubmits});submitVoiceTranscript(transcript);}
  function cutOffForBarge(text){if(bargeCapturing)return;const seed=String(text||'').trim();clearBargeCandidate();resetPendingFinal();bargeCapturing=true;bargeLastText=seed;proof.interruptions+=1;log('INTERRUPT',{trigger:'speech-recognition-barge',count:proof.interruptions,text:seed});activeSpeechEpoch=++speechEpoch;if(currentSpokenText)lastSpokenText=currentSpokenText;lastSpeechEndedAt=Date.now();try{premium?.stop?.();}catch(error){}try{window.speechSynthesis?.cancel();}catch(error){}if(activeRequest&&!activeRequest.controller.signal.aborted){activeRequest.interrupted=true;activeRequest.controller.abort();}speaking=false;processing=false;currentSpokenText='';recognition=null;recognitionStarting=false;recognitionListening=false;updateComposer(seed);setAgentState('listening',seed?`Listening · ${seed}`:'Listening…');scheduleBargeCaptureFinish();}

  function createBargeRecognition(){
    const current=new SpeechRecognitionCtor();current.continuous=true;current.interimResults=true;current.lang=document.documentElement.lang||'en-US';
    current.onstart=()=>{if(bargeRecognition!==current)return;bargeStarting=false;bargeListening=true;proof.bargeStarts+=1;log('BARGE_SR_STARTED',{count:proof.bargeStarts});};
    current.onspeechstart=()=>{if(bargeRecognition!==current||(!speaking&&!bargeCapturing)||!voiceOn)return;log('BARGE_SR_SPEECHSTART',{});};
    current.onresult=event=>{
      if(bargeRecognition!==current||(!speaking&&!bargeCapturing)||!voiceOn)return;let interim='';let finalText='';const start=Math.max(0,Number(event.resultIndex||0));
      for(let i=start;i<event.results.length;i+=1){const result=event.results[i];const text=String(result?.[0]?.transcript||'');if(result?.isFinal)finalText+=text;else interim+=text;}
      const heard=String(finalText||interim||'').trim();if(!heard)return;proof.bargeResults+=1;const reference=currentSpokenText||lastSpokenText;const immediate=isImmediateBargeCommand(heard);const semanticEcho=isLikelyStonefellowEcho(heard,reference,{allowSingle:true});const nearField=hasRecentNearFieldSpeech(immediate?760:NEAR_FIELD_WINDOW_MS);const echo=semanticEcho&&(!immediate||!nearField);log('BARGE_SR_RESULT',{final:!!finalText,text:heard,echo,semanticEcho,nearField,immediate,capturing:bargeCapturing,count:proof.bargeResults});
      if(echo){proof.echoRejects+=1;proof.bargeEchoRejects+=1;log('BARGE_ECHO_REJECTED',{final:!!finalText,text:heard,count:proof.bargeEchoRejects});if(!bargeCapturing)clearBargeCandidate();return;}
      if(!nearField&&!bargeCapturing){proof.noiseRejects+=1;clearBargeCandidate();log('BARGE_NOISE_REJECTED',{text:heard,count:proof.noiseRejects});return;}
      if(immediate&&semanticEcho&&nearField){proof.echoCommandOverrides+=1;log('BARGE_ECHO_COMMAND_OVERRIDE',{text:heard,count:proof.echoCommandOverrides});}
      if(!bargeCapturing){const hits=recordBargeCandidate(heard);const enoughSpeech=speechTokens(heard).filter(word=>word.length>1).length>=2;const stableInterim=!finalText.trim()&&enoughSpeech&&hits>=2;const confirmed=!!finalText.trim()||immediate||stableInterim;if(!confirmed){bargeLastText=heard;log('BARGE_CANDIDATE',{text:heard,hits,enoughSpeech,reason:'await-confirmation'});return;}bargeLastText=heard;proof.lastBargeTranscript=heard;updateComposer(heard);if(stableInterim){proof.bargeFastCuts+=1;log('BARGE_FAST_CUT',{text:heard,hits,count:proof.bargeFastCuts});}cutOffForBarge(heard);}else{bargeLastText=heard;proof.lastBargeTranscript=heard;updateComposer(heard);setAgentState('listening',`Listening · ${heard}`);scheduleBargeCaptureFinish();}
      if(finalText.trim())finishBargeCapture(finalText.trim(),'final');
    };
    current.onerror=event=>{if(bargeRecognition!==current)return;bargeStarting=false;bargeListening=false;proof.bargeErrors+=1;const kind=String(event?.error||'unknown');log('BARGE_SR_ERROR',{error:kind,count:proof.bargeErrors});bargeRecognition=null;if(bargeCapturing&&bargeLastText){finishBargeCapture(bargeLastText,`error:${kind}`);return;}if(voiceOn&&speaking&&kind!=='not-allowed'&&kind!=='service-not-allowed'){clearBargeRestart();bargeRestartTimer=setTimeout(()=>startBarge(),180);}};
    current.onend=()=>{if(bargeRecognition!==current)return;bargeStarting=false;bargeListening=false;bargeRecognition=null;log('BARGE_SR_ENDED',{capturing:bargeCapturing,text:bargeLastText});if(bargeCapturing&&bargeLastText){finishBargeCapture(bargeLastText,'end');return;}const candidate=bargeCandidateText;if(candidate&&speechTokens(candidate).filter(word=>word.length>1).length>=2){cutOffForBarge(candidate);finishBargeCapture(candidate,'candidate-end');return;}clearBargeCandidate();bargeLastText='';if(voiceOn&&speaking){clearBargeRestart();bargeRestartTimer=setTimeout(()=>startBarge(),120);}};
    return current;
  }

  function startBarge(){clearBargeRestart();if(!voiceOn||!speaking||bargeCapturing||bargeStarting||bargeListening||recognitionStarting||recognitionListening||recognition||typeof SpeechRecognitionCtor!=='function')return false;const current=createBargeRecognition();bargeRecognition=current;bargeStarting=true;proof.bargeStartCalls+=1;log('BARGE_SR_START_CALL',{call:proof.bargeStartCalls});try{startRecognizer(current,'barge');return true;}catch(error){if(bargeRecognition===current)bargeRecognition=null;bargeStarting=false;proof.bargeErrors+=1;log('BARGE_SR_START_THROW',{name:String(error?.name||'Error'),message:String(error?.message||error||''),count:proof.bargeErrors});if(voiceOn&&speaking){clearBargeRestart();bargeRestartTimer=setTimeout(()=>startBarge(),220);}return false;}}
  function armBargeAfterRecognizerRelease(){
    clearBargeArmTimer();
    if(!recognition){bargeArmPending=false;startBarge();return;}
    bargeArmPending=true;proof.recognizerReleaseWaits+=1;stopRecognition('response',true);
    bargeArmTimer=setTimeout(()=>{
      if(!speaking||!bargeArmPending)return;
      proof.recognizerReleaseTimeouts+=1;bargeArmPending=false;
      recognition=null;recognitionStarting=false;recognitionListening=false;
      log('SR_RELEASE_TIMEOUT',{count:proof.recognizerReleaseTimeouts});startBarge();
    },BARGE_RELEASE_TIMEOUT_MS);
  }
  function onSpeechStart(epoch){if(epoch!==activeSpeechEpoch)return;resetPendingFinal();if(pendingIntroSpeech&&normalizeSpeechText(pendingIntroSpeech)===normalizeSpeechText(currentSpokenText))pendingIntroSpeech='';processing=false;speaking=true;proof.speechStarts+=1;setAgentState('speaking','Stonefellow is responding…');log('SPEECH_STARTED',{count:proof.speechStarts,epoch});armBargeAfterRecognizerRelease();}
  function onSpeechEnd(epoch){if(epoch!==activeSpeechEpoch)return;bargeArmPending=false;clearBargeArmTimer();stopBarge('speech-end');if(currentSpokenText)lastSpokenText=currentSpokenText;lastSpeechEndedAt=Date.now();currentSpokenText='';speaking=false;processing=false;proof.speechEnds+=1;log('SPEECH_ENDED',{count:proof.speechEnds,epoch});if(voiceOn){recognition=null;scheduleListening(POST_SPEECH_LISTEN_DELAY,'speech-end');}else setAgentState('idle');}
  function browserSpeak(text,epoch){const message=String(text||'').trim();if(!message||!('speechSynthesis' in window)||!window.SpeechSynthesisUtterance){onSpeechEnd(epoch);return;}const utterance=new window.SpeechSynthesisUtterance(message);utterance.onstart=()=>onSpeechStart(epoch);utterance.onend=()=>onSpeechEnd(epoch);utterance.onerror=()=>onSpeechEnd(epoch);try{window.speechSynthesis.cancel();window.speechSynthesis.speak(utterance);}catch(error){onSpeechEnd(epoch);}}
  function finishPremiumOnlyFailure(epoch,error){
    if(epoch!==activeSpeechEpoch)return;
    const message=String(error?.message||error||'Premium voice failed');
    log('PREMIUM_SPEECH_ERROR',{message,fallback:false});
    if(speaking){onSpeechEnd(epoch);return;}
    if(isAutoplayBlockedError(error)&&pendingIntroSpeech){
      proof.autoplayBlocks+=1;
      proof.deferredIntroSpeech+=1;
      currentSpokenText='';lastSpokenText='';lastSpeechEndedAt=0;processing=false;speaking=false;
      log('INTRO_AUTOPLAY_BLOCKED',{count:proof.autoplayBlocks});
      if(voiceOn){recognition=null;scheduleListening(80,'intro-autoplay-blocked');}
      return;
    }
    const fallbackText=String(currentSpokenText||'').trim();proof.lastError=String(error?.message||error||'ElevenLabs voice could not start.');log('PREMIUM_NO_AUDIO',{message:proof.lastError});if(voiceOn&&fallbackText){processing=true;currentSpokenText=fallbackText;setAgentState('processing','Switching to system voice…');startSystemVoiceFallback(fallbackText,epoch,'elevenlabs-playback-failed');return;}currentSpokenText='';processing=false;setAgentState('error','ElevenLabs voice could not start.');if(voiceOn){recognition=null;scheduleListening(320,'premium-failed');}
  }
  function startSystemVoiceFallback(message,epoch,reason='premium-not-ready'){proof.systemFallbacks+=1;proof.premiumReady=false;log('SYSTEM_VOICE_FALLBACK',{reason,count:proof.systemFallbacks});const notice='ElevenLabs failed. Switching to system voice.';const spoken=systemFallbackAnnounced?String(message||'').trim():`${notice} ${String(message||'').trim()}`.trim();systemFallbackAnnounced=true;browserSpeak(spoken,epoch);}

  function speakAnswer(text){
    const message=String(text||'').trim();if(!voiceOn||!message){processing=false;if(voiceOn)scheduleListening(160,'empty-answer');return;}
    stopRecognition('response',true);resetPendingFinal();processing=true;currentSpokenText=message;lastSpokenText=message;lastSpeechEndedAt=0;const epoch=++speechEpoch;activeSpeechEpoch=epoch;setAgentState('processing','Preparing voice…');
    if(!premium?.speak){startSystemVoiceFallback(message,epoch,'premium-module-unavailable');return;}
    const readyPromise=typeof premium.warm==='function'?Promise.resolve(premium.warm()).catch(()=>false):Promise.resolve(true);
    readyPromise.then(ready=>{if(epoch!==activeSpeechEpoch)return;proof.premiumReady=!!ready;if(ready)systemFallbackAnnounced=false;if(!ready){startSystemVoiceFallback(message,epoch,'elevenlabs-not-configured');return;}log('PREMIUM_READY',{primary:true,fallback:'system-tts'});return Promise.resolve(premium.speak(message,{onStart:()=>onSpeechStart(epoch),onEnd:()=>onSpeechEnd(epoch),onError:error=>finishPremiumOnlyFailure(epoch,error)})).catch(error=>finishPremiumOnlyFailure(epoch,error));}).catch(error=>{if(epoch!==activeSpeechEpoch)return;log('PREMIUM_WARM_ERROR',{message:String(error?.message||error||'Premium voice warm-up failed')});startSystemVoiceFallback(message,epoch,'elevenlabs-not-configured');});
  }

  function interruptResponse(trigger='manual'){if(!speaking&&!processing)return false;proof.interruptions+=1;log('INTERRUPT',{trigger,count:proof.interruptions});activeSpeechEpoch=++speechEpoch;bargeArmPending=false;clearBargeArmTimer();stopBarge('interrupt');bargeCapturing=false;bargeLastText='';resetPendingFinal();if(currentSpokenText)lastSpokenText=currentSpokenText;lastSpeechEndedAt=Date.now();currentSpokenText='';try{premium?.stop?.();}catch(error){}try{window.speechSynthesis?.cancel();}catch(error){}if(activeRequest&&!activeRequest.controller.signal.aborted){activeRequest.interrupted=true;activeRequest.controller.abort();}speaking=false;processing=false;recognition=null;recognitionStarting=false;recognitionListening=false;if(voiceOn)startListening('interrupt');else setAgentState('idle');return true;}
  function isChatSend(inputArg,init,payload){if(!chatUrl||String(init?.method||'GET').toUpperCase()!=='POST'||payload?.action!=='send')return false;try{const target=new URL(typeof inputArg==='string'?inputArg:inputArg?.url||'',location.href);return target.origin===chatUrl.origin&&target.pathname===chatUrl.pathname;}catch(error){return false;}}
  async function attachContext(payload){if(!AgentContext)return payload;try{return {...payload,agent_context:await AgentContext.refresh(false)};}catch(error){return {...payload,agent_context:AgentContext.snapshot?.()||{}};}}

  async function handleVoiceApiFetch(inputArg,init,payload){
    proof.apiTurns+=1;processing=true;setAgentState('processing','Thinking…');stopRecognition('submit',true);const request={controller:new AbortController(),interrupted:false};activeRequest=request;payload={...payload,input_mode:'voice'};const nextInit={...init,body:JSON.stringify(payload),signal:request.controller.signal};log('VOICE_API_START',{turn:proof.apiTurns,conversationId:Number(payload.conversation_id||0)});
    const useFastStream=!!(chatStreamUrl&&premium?.createStream&&typeof premium?.warm==='function');
    if(!useFastStream){
      try{const response=await nativeFetch(inputArg,nextInit);const copy=response.clone();let data=null;try{data=await copy.json();}catch(error){}activeRequest=null;processing=false;if(request.interrupted){log('VOICE_API_INTERRUPTED',{});return response;}if(!response.ok||!data?.ok){const message=String(data?.error||data?.message||`Chat HTTP ${response.status}`);proof.apiErrors+=1;proof.lastError=message;setAgentState('error',message);log('VOICE_API_ERROR',{status:response.status,message});if(voiceOn){recognition=null;scheduleListening(320,'voice-api-error');}return response;}proof.apiSuccess+=1;syncConversation(data.conversation_id);log('VOICE_API_SUCCESS',{conversationId:Number(data.conversation_id||0),assistantMessageId:Number(data.assistant_message_id||0),fast:false});speakAnswer(String(data.answer||''));return response;}
      catch(error){activeRequest=null;processing=false;if(request.interrupted){log('VOICE_API_ABORTED',{});return new Response(JSON.stringify({ok:false,error:'Voice response interrupted.'}),{status:499,headers:{'Content-Type':'application/json'}});}const message=String(error?.message||error||'Voice conversation failed.');proof.apiErrors+=1;proof.lastError=message;setAgentState('error',message);log('VOICE_API_FETCH_ERROR',{message});if(voiceOn){recognition=null;scheduleListening(320,'voice-api-fetch-error');}return new Response(JSON.stringify({ok:false,error:message}),{status:502,headers:{'Content-Type':'application/json; charset=UTF-8','Cache-Control':'no-store'}});}
      finally{if(queuedTranscript&&!processing&&!speaking){const next=queuedTranscript;queuedTranscript='';setTimeout(()=>submitVoiceTranscript(next),0);}}
    }

    proof.fastStreamTurns+=1;const warmPromise=Promise.resolve(premium.warm()).catch(()=>false);let premiumStream=null;let premiumAudible=false;let premiumPrestartError=null;let epoch=0;let completedData=null;let streamedAnswer='';let streamError='';
    try{
      const responsePromise=nativeFetch(chatStreamUrl,{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json'},body:JSON.stringify(payload),signal:request.controller.signal});const [response,premiumReady]=await Promise.all([responsePromise,warmPromise]);proof.premiumReady=!!premiumReady;if(premiumReady)systemFallbackAnnounced=false;log('FAST_STREAM_OPEN',{status:response.status,premiumReady:!!premiumReady,turn:proof.fastStreamTurns});if(!response.ok||!response.body)throw new Error(`Voice stream HTTP ${response.status}`);
      if(premiumReady){currentSpokenText='';lastSpokenText='';lastSpeechEndedAt=0;epoch=++speechEpoch;activeSpeechEpoch=epoch;premiumStream=premium.createStream({onStart:()=>{premiumAudible=true;proof.premiumAudibleStarts+=1;log('FAST_PREMIUM_AUDIBLE',{count:proof.premiumAudibleStarts});onSpeechStart(epoch);},onEnd:()=>onSpeechEnd(epoch),onError:error=>{if(premiumAudible){finishPremiumOnlyFailure(epoch,error);return;}premiumPrestartError=error||new Error('ElevenLabs failed before playback started.');proof.premiumPrestartFailures+=1;log('FAST_PREMIUM_PRESTART_ERROR',{message:String(premiumPrestartError?.message||premiumPrestartError||''),count:proof.premiumPrestartFailures});}});Promise.resolve(premiumStream.started).catch(error=>{if(premiumAudible)return;premiumPrestartError=error||new Error('ElevenLabs failed before playback started.');proof.premiumPrestartFailures+=1;log('FAST_PREMIUM_STARTED_REJECTED',{message:String(premiumPrestartError?.message||premiumPrestartError||''),count:proof.premiumPrestartFailures});});}
      const reader=response.body.getReader();const decoder=new TextDecoder();let pending='';
      const consume=line=>{const clean=String(line||'').trim();if(!clean)return;let event=null;try{event=JSON.parse(clean);}catch(error){return;}if(event?.type==='start'){syncConversation(event.conversation_id);return;}if(event?.type==='delta'){const delta=String(event.delta||'');if(!delta)return;streamedAnswer+=delta;proof.fastStreamDeltas+=1;if(premiumStream){currentSpokenText+=delta;lastSpokenText=currentSpokenText;premiumStream.push(delta);}return;}if(event?.type==='done'){completedData=event.data&&typeof event.data==='object'?event.data:null;if(completedData?.conversation_id)syncConversation(completedData.conversation_id);return;}if(event?.type==='error')streamError=String(event.error||'Voice conversation failed.');};
      while(true){const {value,done}=await reader.read();if(done)break;pending+=decoder.decode(value,{stream:true});let newline=-1;while((newline=pending.indexOf('\n'))>=0){consume(pending.slice(0,newline));pending=pending.slice(newline+1);}}pending+=decoder.decode();if(pending.trim())consume(pending);if(streamError)throw new Error(streamError);
      const data=completedData||{ok:true,conversation_id:lastConversationId,answer:streamedAnswer,input_mode:'voice'};if(!data.answer)data.answer=streamedAnswer;
      if(premiumStream){if(!currentSpokenText.trim()&&String(data.answer||'').trim()){currentSpokenText=String(data.answer);lastSpokenText=currentSpokenText;premiumStream.push(currentSpokenText);}premiumStream.end();if(!premiumAudible){const started=await Promise.race([Promise.resolve(premiumStream.started).then(()=>true).catch(()=>false),new Promise(resolve=>setTimeout(()=>resolve(false),3200))]);if(!started&&!request.interrupted&&voiceOn){try{premiumStream.stop?.();}catch(error){}proof.premiumFullRetries+=1;const retryMessage=String(data.answer||streamedAnswer||currentSpokenText||'').trim();log('FAST_PREMIUM_FULL_RETRY',{count:proof.premiumFullRetries,reason:String(premiumPrestartError?.message||'first-audio-timeout'),chars:retryMessage.length});if(retryMessage){const retryEpoch=++speechEpoch;activeSpeechEpoch=retryEpoch;currentSpokenText=retryMessage;lastSpokenText=retryMessage;processing=true;setAgentState('processing','Preparing ElevenLabs voice…');try{await Promise.resolve(premium.speak(retryMessage,{onStart:()=>{premiumAudible=true;proof.premiumAudibleStarts+=1;log('FAST_PREMIUM_RETRY_AUDIBLE',{count:proof.premiumAudibleStarts});onSpeechStart(retryEpoch);},onEnd:()=>onSpeechEnd(retryEpoch),onError:error=>finishPremiumOnlyFailure(retryEpoch,error)}));}catch(error){finishPremiumOnlyFailure(retryEpoch,error);}}}}}
      else{processing=false;const message=String(data.answer||streamedAnswer||'').trim();if(message){currentSpokenText=message;lastSpokenText=message;lastSpeechEndedAt=0;epoch=++speechEpoch;activeSpeechEpoch=epoch;startSystemVoiceFallback(message,epoch,'elevenlabs-not-configured');}else if(voiceOn){recognition=null;scheduleListening(160,'empty-answer');}}
      activeRequest=null;proof.apiSuccess+=1;log('VOICE_API_SUCCESS',{conversationId:Number(data.conversation_id||0),assistantMessageId:Number(data.assistant_message_id||0),fast:true,deltas:proof.fastStreamDeltas,premiumReady:proof.premiumReady});return new Response(JSON.stringify(data),{status:200,headers:{'Content-Type':'application/json; charset=UTF-8','Cache-Control':'no-store'}});
    }catch(error){try{premiumStream?.stop?.();}catch(stopError){}activeRequest=null;if(request.interrupted){processing=false;log('VOICE_API_ABORTED',{fast:true});return new Response(JSON.stringify({ok:false,error:'Voice response interrupted.'}),{status:499,headers:{'Content-Type':'application/json'}});}processing=false;const message=String(error?.message||error||'Voice conversation failed.');proof.apiErrors+=1;proof.lastError=message;setAgentState('error',message);log('VOICE_API_FETCH_ERROR',{message,fast:true});if(voiceOn&&!speaking){recognition=null;scheduleListening(320,'voice-api-fetch-error');}return new Response(JSON.stringify({ok:false,error:message}),{status:502,headers:{'Content-Type':'application/json; charset=UTF-8','Cache-Control':'no-store'}});}
    finally{if(queuedTranscript&&!processing&&!speaking){const next=queuedTranscript;queuedTranscript='';setTimeout(()=>submitVoiceTranscript(next),0);}}
  }

  const routedFetch=async function(inputArg,init={}){let payload=null;if(typeof init?.body==='string'){try{payload=JSON.parse(init.body);}catch(error){}}const chatSend=isChatSend(inputArg,init,payload);if(chatSend&&payload){payload=await attachContext(payload);init={...init,body:JSON.stringify(payload)};}if(chatSend&&voiceSubmitPending){voiceSubmitPending=false;return handleVoiceApiFetch(inputArg,init,payload||{});}if(!chatSend)return nativeFetch(inputArg,init);textBusy=true;if(voiceOn)stopRecognition('text-send',true);try{return await nativeFetch(inputArg,init);}finally{textBusy=false;if(voiceOn&&!speaking&&!processing&&!bargeCapturing){recognition=null;scheduleListening(120,'text-send-end');}}};
  window.fetch=routedFetch;

  function submitVoiceTranscript(text){const transcript=String(text||'').trim();if(!transcript)return;resetPendingFinal();if(isLowValueTranscript(transcript)&&!isImmediateBargeCommand(transcript)){proof.fillerRejects+=1;processing=false;voiceSubmitPending=false;updateComposer('');log('TRANSCRIPT_FILLER_REJECTED',{text:transcript,count:proof.fillerRejects});if(voiceOn)scheduleListening(40,'filler');return;}if(processing||speaking||textBusy||activeRequest){queuedTranscript=transcript;log('TRANSCRIPT_QUEUED',{text:transcript});return;}proof.submits+=1;voiceSubmitPending=true;processing=true;updateComposer(transcript);setAgentState('processing','Thinking…');log('TRANSCRIPT_SUBMIT',{text:transcript,count:proof.submits});try{form.requestSubmit();}catch(error){processing=false;voiceSubmitPending=false;proof.lastError=String(error?.message||error||'Submit failed');setAgentState('error',proof.lastError);}}

  function enableVoice({persist=true,start=true}={}){if(typeof SpeechRecognitionCtor!=='function'){voiceOn=false;if(persist)writeMode();else syncButton();setAgentState('error','Browser speech recognition is unavailable.');return;}voiceOn=true;if(persist)writeMode();else syncButton();recognition=null;setAgentState('listening','Listening…');if(start)startListening('enable');else void ensureProcessedMic();}
  function disableVoice({persist=true}={}){voiceOn=false;if(persist)writeMode();else syncButton();resetPendingFinal();pendingIntroSpeech='';introRetryScheduled=false;bargeArmPending=false;clearBargeArmTimer();clearRestart();clearStartWatchdog();clearBargeRestart();stopRecognition('off',true);releaseProcessedMic('voice-off');recognition=null;recognitionStarting=false;recognitionListening=false;stopBarge('off');bargeCapturing=false;bargeLastText='';activeSpeechEpoch=++speechEpoch;currentSpokenText='';lastSpokenText='';lastSpeechEndedAt=0;try{premium?.stop?.();}catch(error){}try{window.speechSynthesis?.cancel();}catch(error){}if(activeRequest&&!activeRequest.controller.signal.aborted)activeRequest.controller.abort();activeRequest=null;processing=false;speaking=false;queuedTranscript='';setAgentState('idle');}
  function toggleVoice(){if(voiceOn)disableVoice({persist:true});else if(!introPresented&&boot.intro?.greeting){enableVoice({persist:true,start:false});presentIntro();}else enableVoice({persist:true,start:true});}

  function introTexts(intro){const greeting=String(intro?.greeting||'').trim();const updates=Array.isArray(intro?.updates)?intro.updates:[];const display=updates.length?`${greeting}\n\nHere’s what changed:\n${updates.map(update=>`• ${String(update?.title||'Update')}${update?.body?` — ${String(update.body)}`:''}`).join('\n')}`:greeting;const spoken=updates.length?`${greeting} Here are the priorities I found. ${updates.map(update=>`${String(update?.title||'Update')}. ${String(update?.body||'')}`).join(' ')}`:greeting;return {display,spoken,updates};}
  function presentIntro(){
    if(introPresented)return;introPresented=true;const intro=boot.intro&&typeof boot.intro==='object'?boot.intro:null;if(!intro||!String(intro.greeting||'').trim()){if(voiceOn)scheduleListening(60,'boot-no-intro');return;}
    const {display,spoken,updates}=introTexts(intro);proof.intros+=1;if(welcome)welcome.hidden=true;
    if(thread){const message=document.createElement('div');message.className='message assistant';message.dataset.agentIntro='canonical';message.innerHTML='<div class="message-avatar" aria-hidden="true">S</div><div class="message-body"><div class="message-role">Stonefellow</div><div class="message-text"></div></div>';const textNode=message.querySelector('.message-text');if(textNode)textNode.textContent=display;const body=message.querySelector('.message-body');const actionable=updates.filter(update=>String(update?.target_url||'').trim()).slice(0,4);if(body&&actionable.length){const actions=document.createElement('div');actions.className='message-actions';for(const update of actionable){const link=document.createElement('a');link.href=String(update.target_url);link.textContent=`Open ${String(update.title||'update').slice(0,80)}`;actions.appendChild(link);}body.appendChild(actions);}thread.appendChild(message);thread.scrollTop=thread.scrollHeight;}
    log('INTRO_PRESENTED',{spoken:!!spoken,updates:updates.length});
    if(voiceOn&&spoken){pendingIntroSpeech=spoken;setTimeout(()=>{if(voiceOn&&!processing&&!speaking&&pendingIntroSpeech)speakAnswer(pendingIntroSpeech);},80);}
    else if(voiceOn)scheduleListening(60,'intro-ready');
  }

  async function waitForInitialConversationRestore(){const id=Number(cfg.initialConversationId||0);if(String(cfg.initialView||'chat')!=='chat'||id<1)return;const deadline=Date.now()+6000;while(Date.now()<deadline){const active=document.querySelector(`.chat-history-item.active[data-conversation-id="${id}"]`);const loading=thread?.querySelector?.('.typing');if(active&&!loading)return;await new Promise(resolve=>setTimeout(resolve,60));}}

  function renderDebug(){if(!new URLSearchParams(location.search).has('voice_debug'))return;let panel=document.getElementById('stonefellowVoiceDebug');if(!panel){panel=document.createElement('aside');panel.id='stonefellowVoiceDebug';panel.style.cssText='position:fixed;right:12px;bottom:12px;width:min(460px,calc(100vw - 24px));max-height:52vh;overflow:auto;z-index:99999;background:#111;color:#ddd;border:1px solid #444;border-radius:10px;padding:10px;font:11px/1.4 ui-monospace,SFMono-Regular,Consolas,monospace;white-space:pre-wrap;box-shadow:0 10px 35px rgba(0,0,0,.35)';document.body.appendChild(panel);}const premiumProof=window.STONEFELLOW_PREMIUM_VOICE_V122||{};const latest=events.slice(-34).map(item=>`${item.at.slice(11,19)} ${item.type} ${JSON.stringify(item.detail)}`).join('\n');panel.textContent=`Stonefellow Voice Debug ${BUILD}\n\nvoice=${voiceOn?'ON':'OFF'} listening=${recognitionListening?'yes':'no'} processing=${processing?'yes':'no'} speaking=${speaking?'yes':'no'}\npause window=${TURN_END_PAUSE_MS}ms extensions=${proof.pauseExtensions} pending=${pendingFinalTranscript||'-'}\nnormal starts=${proof.starts} finals=${proof.finals} submits=${proof.submits}\nbarge starts=${proof.bargeStarts} results=${proof.bargeResults} captures=${proof.bargeSubmits} errors=${proof.bargeErrors}\nbarge candidates=${proof.bargeCandidates} fastCuts=${proof.bargeFastCuts} timeouts=${proof.bargeCaptureTimeouts}\necho rejects=${proof.echoRejects} bargeEcho=${proof.bargeEchoRejects} postSpeechEcho=${proof.postSpeechEchoRejects} commandOverrides=${proof.echoCommandOverrides}\nelevenLabs ready=${proof.premiumReady?'yes':'no'} credential=${premiumProof.credentialState||'unknown'} unlocked=${premiumProof.audioUnlocked?'yes':'no'} unlocks=${premiumProof.unlockSuccesses||0}/${premiumProof.unlockAttempts||0} playback=${premiumProof.lastPlaybackError||'ok'}\ninterruptions=${proof.interruptions} api=${proof.apiSuccess}/${proof.apiTurns} apiErrors=${proof.apiErrors}\nautoplay blocks=${proof.autoplayBlocks}\nlast transcript=${proof.lastTranscript||'-'}\nlast barge=${proof.lastBargeTranscript||'-'}\nlast error=${proof.lastError||'-'}\n\n${latest}`;}

  button.addEventListener('click',event=>{const unlockOnly=voiceOn&&!!pendingIntroSpeech&&!speaking&&!processing;void requestPremiumUnlock('listen-button');if(unlockOnly){event.preventDefault();event.stopImmediatePropagation();return;}if(voiceOn&&(speaking||processing)){event.preventDefault();event.stopImmediatePropagation();interruptResponse('button');return;}toggleVoice();},true);
  const unlockFromGesture=event=>{if(event?.type==='keydown'&&event.repeat)return;void requestPremiumUnlock(event?.type||'user-gesture');};document.addEventListener('pointerdown',unlockFromGesture,{capture:true,once:true,passive:true});document.addEventListener('keydown',unlockFromGesture,{capture:true,once:true});
  document.addEventListener('click',event=>{const link=event.target.closest?.('a[href*="/admin/stems.php"],a[href*="/video-editor.php"]');if(!link)return;try{const target=new URL(link.href,location.href);if(target.origin!==location.origin)return;if(voiceOn)target.searchParams.set('voice','1');const cid=activeConversationId();if(cid>0)target.searchParams.set('conversation_id',String(cid));link.href=target.toString();}catch(error){}},true);

  const continuity={isVoice:()=>voiceOn,conversationId:activeConversationId,startListening,interrupt:interruptResponse};window.STONEFELLOW_CHAT_CONTINUITY=continuity;

  syncConversation(lastConversationId);syncButton();if(button.disabled){voiceOn=false;writeMode();setAgentState('error','Voice recognition is not available in this browser.');}else{if(voiceOn){setAgentState('listening','Listening…');scheduleListening(0,'boot-persisted');}else setAgentState('idle');setTimeout(()=>void waitForInitialConversationRestore().then(presentIntro),80);}renderDebug();
  window.addEventListener('storage',event=>{if(event.key!==MODE_KEY)return;const next=event.newValue==='1';if(next===voiceOn)return;if(next)enableVoice({persist:false,start:true});else disableVoice({persist:false});});
  window.dispatchEvent(new CustomEvent('stonefellow:conversation-engine-ready',{detail:{build:BUILD,source:'agent-chat'}}));log('READY',{voiceOn,ctor:typeof SpeechRecognitionCtor,barge:'speech-recognition',echoGuard:'canonical',fastVoice:'streaming',premiumUnlock:true,pauseWindowMs:TURN_END_PAUSE_MS,lifecycle:'canonical'});

  window.addEventListener('pagehide',()=>{resetPendingFinal();pendingIntroSpeech='';introRetryScheduled=false;bargeArmPending=false;clearBargeArmTimer();clearRestart();clearStartWatchdog();clearBargeRestart();clearBargeCaptureTimer();stopBarge('pagehide');bargeCapturing=false;stopRecognition('pagehide',true);releaseProcessedMic('pagehide');recognition=null;recognitionStarting=false;recognitionListening=false;activeSpeechEpoch=++speechEpoch;currentSpokenText='';lastSpokenText='';lastSpeechEndedAt=0;try{premium?.stop?.();}catch(error){}try{window.speechSynthesis?.cancel();}catch(error){}if(activeRequest&&!activeRequest.controller.signal.aborted)activeRequest.controller.abort();activeRequest=null;if(window.fetch===routedFetch)window.fetch=previousFetch;delete document.body.dataset.stonefellowAgentState;},{once:true});
})();
