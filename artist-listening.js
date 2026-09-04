(() => {
  'use strict';

  const BUILD='artist-listening-transcription-v197-optional-audio-20260901';
  const cfg=window.STONEFELLOW_ARTIST_LISTENING_V172||{};
  const button=document.getElementById('artistListeningButton');
  const form=document.getElementById('chatForm');
  const input=document.getElementById('chatInput');
  const SpeechRecognitionCtor=window.SpeechRecognition||window.webkitSpeechRecognition||null;
  const realtime=window.STONEFELLOW_ARTIST_LISTENING_REALTIME||null;
  if(!button||!form||!input||!cfg.endpoint)return;

  const userId=Math.max(0,Number(cfg.userId||0));
  const STORAGE_KEY=`stonefellow:artist-listening:v172:${userId}`;
  const state={
    session:null,
    sessions:[],
    active:false,
    recovered:false,
    recognition:null,
    recognitionStarting:false,
    restartTimer:0,
    timer:0,
    retryTimer:0,
    flushTimer:0,
    meterFrame:0,
    meterStream:null,
    meterContext:null,
    meterAnalyser:null,
    recordingActive:false,
    recordingStarting:false,
    recordingUploading:false,
    mediaRecorder:null,
    recordingStream:null,
    recordingChunks:[],
    recordingKey:'',
    recordingMime:'',
    recordingStartedMs:0,
    recordingStopPromise:null,
    captureStartedAt:0,
    elapsedBeforeResume:0,
    segmentIndex:0,
    interim:'',
    noteArmed:false,
    pending:[],
    pendingStop:false,
    syncing:false,
    startPromise:null,
    lastFinalText:'',
    lastFinalAt:0,
    lastError:'',
    continuity:realtime?new realtime.TranscriptContinuity():null,
    speakerModel:realtime?new realtime.SpeakerTurnModel({maxSpeakers:4}):null,
    expectedSpeakers:0,
    acousticFrames:[],
    utteranceStartedMs:0,
  };

  const proof=window.STONEFELLOW_ARTIST_LISTENING_V172={
    build:BUILD,loaded:true,audioRetained:true,mediaRecorder:typeof window.MediaRecorder==='function',
    starts:0,resumes:0,stops:0,segments:0,markers:0,notes:0,
    recordingStarts:0,recordingStops:0,recordingUploads:0,recordingErrors:0,
    syncs:0,syncErrors:0,recoveredDrafts:0,commandStarts:0,commandStops:0,
    memoryPromotions:0,projectNotePromotions:0,knowledgePromotions:0,lastError:'',
    duplicatePhrasesSuppressed:0,overlapWordsReconciled:0,inferredSpeakerTurns:0,
  };

  const el={
    state:document.querySelector('[data-listening-state]'),
    timer:document.querySelector('[data-listening-timer]'),
    speakerCount:document.querySelector('[data-listening-speaker-count]'),
    speakerMode:document.querySelector('[data-listening-speaker-mode]'),
    liveLevel:document.querySelector('[data-listening-live-level]'),
    start:document.querySelector('[data-listening-start]'),
    stop:document.querySelector('[data-listening-stop]'),
    record:document.querySelector('[data-listening-record]'),
    marker:document.querySelector('[data-listening-marker]'),
    note:document.querySelector('[data-listening-note]'),
    notice:document.querySelector('[data-listening-notice]'),
  };
  const requiredControls=[el.state,el.timer,el.speakerCount,el.speakerMode,el.liveLevel,el.start,el.stop,el.record,el.marker,el.note];
  if(requiredControls.some(node=>!node))return;

  function uuid(){
    try{return crypto.randomUUID();}catch(error){return `${Date.now().toString(36)}-${Math.random().toString(36).slice(2)}-${Math.random().toString(36).slice(2)}`.slice(0,64);}
  }
  function normalize(value){return String(value||'').toLowerCase().replace(/[^a-z0-9\s']/g,' ').replace(/\s+/g,' ').trim();}
  function commandFor(value){
    let heard=normalize(value);let wake=false;
    if(/^(?:agent|stonefellow)\b/.test(heard)){wake=true;heard=heard.replace(/^(?:agent|stonefellow)\s*/,'').trim();}
    if(heard==='start listening')return {name:'start',wake};
    if(heard==='stop listening')return {name:'stop',wake};
    if(heard==='start recording')return {name:'record-start',wake};
    if(heard==='stop recording')return {name:'record-stop',wake};
    if(heard==='mark that')return {name:'marker',wake};
    if(heard==='add a note'||heard==='add note')return {name:'note',wake};
    if(heard==='cancel note')return {name:'cancel-note',wake};
    const note=heard.match(/^add (?:a )?note(?: that)?\s+(.+)$/);
    if(note)return {name:'note-text',wake,text:note[1]};
    return null;
  }
  function formatTime(milliseconds){
    const seconds=Math.max(0,Math.floor(Number(milliseconds||0)/1000));
    return `${String(Math.floor(seconds/60)).padStart(2,'0')}:${String(seconds%60).padStart(2,'0')}`;
  }
  function elapsedMs(){return Math.max(0,state.elapsedBeforeResume+(state.active?Date.now()-state.captureStartedAt:0));}
  function sessionId(){return Math.max(0,Number(state.session?.id||0));}
  function sessionIsDraft(){return !!state.session&&String(state.session.status||'')==='draft';}
  function activeConversationId(){
    const selected=document.querySelector('[data-listening-workspace-chat]');
    if(selected)return Math.max(0,Number(selected.value||0));
    const active=document.querySelector('.chat-history-item.active[data-conversation-id]');
    return Math.max(0,Number(active?.dataset.conversationId||cfg.conversationId||0));
  }
  function emitLive(action,detail={}){
    const speakerCount=Math.max(state.expectedSpeakers===1?1:0,state.speakerModel?.clusters?.length||0);
    window.dispatchEvent(new CustomEvent('stonefellow:artist-listening-live',{detail:{action,session:state.session,segments:combinedSegments(),interim:state.interim,active:state.active,paused:false,elapsedMs:elapsedMs(),speakerCount,recordingActive:state.recordingActive,recordingUploading:state.recordingUploading,...detail}}));
  }
  function notify(message='',kind=''){
    if(!el.notice)return;
    el.notice.textContent=String(message||'');
    el.notice.className=`artist-listening-notice${kind?` ${kind}`:''}`;
  }
  function setCaptureState(label,active=false){
    el.state.textContent=String(label||'READY').toUpperCase();
    el.state.classList.toggle('active',!!active);
    button.classList.toggle('active',!!active);
    button.setAttribute('aria-pressed',active?'true':'false');
    if(active)document.body.dataset.stonefellowCaptureState='transcribing';
    else delete document.body.dataset.stonefellowCaptureState;
  }
  function persist(){
    const snapshot={
      sessionId:sessionId(),
      clientSessionKey:String(state.session?.client_session_key||state.session?.clientSessionKey||''),
      title:String(state.session?.title||''),
      speaker:`Speaker ${state.speakerModel?.lastIndex||1}`,
      expectedSpeakers:state.expectedSpeakers,
      pending:state.pending,
      pendingStop:state.pendingStop,
      elapsedMs:elapsedMs(),
      wasListening:state.active,
      savedAt:Date.now(),
    };
    try{localStorage.setItem(STORAGE_KEY,JSON.stringify(snapshot));}
    catch(error){state.lastError='Browser transcript backup storage is full.';proof.lastError=state.lastError;}
  }
  function readPersisted(){
    try{const data=JSON.parse(localStorage.getItem(STORAGE_KEY)||'{}');return data&&typeof data==='object'?data:{};}catch(error){return {};}
  }
  async function request(action,payload={},method='POST'){
    let endpoint=String(cfg.endpoint);
    const options={method,credentials:'same-origin',headers:{Accept:'application/json'}};
    if(method==='GET'){
      const url=new URL(endpoint,location.href);url.searchParams.set('action',action);
      for(const [key,value] of Object.entries(payload)){if(value!==undefined&&value!==null)url.searchParams.set(key,String(value));}
      endpoint=url.toString();
    }else{
      options.headers['Content-Type']='application/json';
      options.body=JSON.stringify({action,csrf_token:String(cfg.csrf||''),...payload});
    }
    const response=await fetch(endpoint,options);
    const data=await response.json().catch(()=>({ok:false,error:'Artist Listening returned an invalid response.'}));
    if(!response.ok||!data.ok)throw new Error(String(data.error||`Artist Listening failed (${response.status}).`));
    return data;
  }

  function combinedSegments(){
    const saved=Array.isArray(state.session?.segments)?state.session.segments:[];
    const map=new Map();
    for(const segment of saved)map.set(String(segment.client_segment_key||segment.key||segment.id),segment);
    for(const segment of state.pending)map.set(String(segment.key),{
      client_segment_key:segment.key,segment_index:segment.index,segment_type:segment.type,
      speaker_label:segment.speaker,transcript_text:segment.text,started_ms:segment.started_ms,
      ended_ms:segment.ended_ms,confidence:segment.confidence,pending:true,
    });
    return [...map.values()].sort((a,b)=>Number(a.segment_index||0)-Number(b.segment_index||0));
  }
  function renderSegments(){emitLive('update');}
  function renderRecent(){}
  function render(){
    const active=state.active;
    const hasServerActive=String(state.session?.status||'')==='active';
    const canStart=sessionIsDraft()||hasServerActive||String(state.session?.status||'')==='starting';
    el.start.disabled=active||!!state.startPromise||state.pendingStop||!canStart;
    el.start.textContent=hasServerActive&&!active?'Resume Listening':'Start Listening';
    el.stop.disabled=!active&&!hasServerActive;
    el.stop.textContent='Stop Listening';
    el.record.disabled=!active||state.recordingStarting||state.recordingUploading;
    el.record.textContent=state.recordingUploading?'Saving Recording…':(state.recordingActive?'Stop Recording':'Start Recording');
    el.record.classList.toggle('active',state.recordingActive);
    el.record.setAttribute('aria-pressed',state.recordingActive?'true':'false');
    el.marker.disabled=!active;
    el.note.disabled=!active;
    el.speakerCount.textContent=String(Math.max(state.expectedSpeakers===1?1:0,state.speakerModel?.clusters?.length||0));
    if(active)setCaptureState(state.noteArmed?'NOTE READY':'TRANSCRIBING',true);
    else if(state.pendingStop)setCaptureState('SAVING',false);
    else if(hasServerActive)setCaptureState('RECOVERED',false);
    else if(sessionIsDraft())setCaptureState('DRAFT SAVED',false);
    else setCaptureState('READY',false);
    renderSegments();renderRecent();persist();
  }
  function disableAgentConversation(){
    const continuity=window.STONEFELLOW_CHAT_CONTINUITY;
    if(!continuity?.isVoice?.())return;
    if(['processing','speaking'].includes(String(document.body.dataset.stonefellowAgentState||'')))continuity.interrupt?.('artist-listening-v172');
    const voiceButton=document.getElementById('chatVoiceButton');
    if(continuity.isVoice?.()&&voiceButton)voiceButton.click();
    try{window.speechSynthesis?.cancel();}catch(error){}
  }
  function stopRecognition(){
    if(state.restartTimer)clearTimeout(state.restartTimer);state.restartTimer=0;
    const current=state.recognition;state.recognition=null;state.recognitionStarting=false;
    if(current){try{current.abort();}catch(error){}}
  }
  function scheduleRecognition(delay=180){
    if(state.restartTimer)clearTimeout(state.restartTimer);
    if(!state.active)return;
    state.restartTimer=setTimeout(()=>startRecognition(),Math.max(80,delay));
  }
  function duplicateFinal(text){
    const clean=normalize(text);const now=Date.now();
    const duplicate=clean!==''&&clean===state.lastFinalText&&now-state.lastFinalAt<6000;
    if(!duplicate){state.lastFinalText=clean;state.lastFinalAt=now;}
    return duplicate;
  }
  function nextSegment(type,text,confidence=null,meta={}){
    const now=elapsedMs();
    const started=Math.max(0,Number(meta.startedMs??now));
    const ended=Math.max(started,Number(meta.endedMs??now));
    let speaker={index:1,label:'Speaker 1',confidence:1,inferred:false};
    if(type==='transcript'&&state.expectedSpeakers!==1&&state.speakerModel){
      state.speakerModel.setExpected(state.expectedSpeakers);
      speaker=state.speakerModel.assign({features:meta.features||null,startedMs:started,endedMs:ended});
      proof.inferredSpeakerTurns+=1;
    }
      const segment={
      key:uuid(),index:state.segmentIndex++,type,
      speaker:type==='transcript'?speaker.label:'Speaker 1',
      inferred_speaker_index:type==='transcript'?speaker.index:null,
      speaker_confidence:type==='transcript'?speaker.confidence:null,
      text:String(text||'').trim().slice(0,8000),started_ms:started,ended_ms:ended,
      confidence:Number.isFinite(Number(confidence))?Math.max(0,Math.min(1,Number(confidence))):null,
    };
    if(!segment.text)return null;
    state.pending.push(segment);proof.segments+=1;if(type==='marker')proof.markers+=1;if(type==='note')proof.notes+=1;
    persist();render();queueFlush();return segment;
  }
  function queueFlush(delay=420){if(state.flushTimer)clearTimeout(state.flushTimer);state.flushTimer=setTimeout(()=>{state.flushTimer=0;void flushPending();},Math.max(80,delay));}
  function runPassiveCommand(command){
    if(!command)return false;
    if(command.name==='stop'){proof.commandStops+=1;void stopCapture('voice-command');return true;}
    if(command.name==='record-start'){void startAudioRecording('voice-command');return true;}
    if(command.name==='record-stop'){void stopAudioRecording('voice-command');return true;}
    if(command.name==='marker'){addMarker();return true;}
    if(command.name==='note'){state.noteArmed=true;state.interim='';notify('Note ready. Say the note, or say “Cancel Note.”');render();return true;}
    if(command.name==='note-text'){nextSegment('note',command.text);notify('Note added.','success');return true;}
    if(command.name==='cancel-note'){state.noteArmed=false;state.interim='';notify('Note cancelled.');render();return true;}
    if(command.name==='start'){return true;}
    return false;
  }
  function handleFinal(text,confidence){
    let heard=String(text||'').trim();if(!heard)return;
    const ended=elapsedMs();
    const started=state.utteranceStartedMs||Math.max(0,ended-1800);
    state.utteranceStartedMs=0;
    if(state.continuity){
      const reconciled=state.continuity.finalize(heard,ended);
      state.interim='';
      proof.overlapWordsReconciled+=Number(reconciled.overlapWords||0);
      if(reconciled.duplicate){proof.duplicatePhrasesSuppressed+=1;state.interim='';emitLive('interim');return;}
      heard=reconciled.delta;
    }else if(duplicateFinal(heard)){proof.duplicatePhrasesSuppressed+=1;return;}
    const command=commandFor(heard);
    if(runPassiveCommand(command))return;
    if(state.noteArmed){state.noteArmed=false;nextSegment('note',heard,confidence,{startedMs:started,endedMs:ended});notify('Note added.','success');return;}
    const features=realtime?.aggregateFeatures(state.acousticFrames,started,ended)||null;
    nextSegment('transcript',heard,confidence,{startedMs:started,endedMs:ended,features});
  }
  function startRecognition(){
    if(!state.active||state.recognition||state.recognitionStarting||typeof SpeechRecognitionCtor!=='function')return false;
    const current=new SpeechRecognitionCtor();state.recognition=current;state.recognitionStarting=true;
    current.continuous=true;current.interimResults=true;current.lang=String(state.session?.language||document.documentElement.lang||'en-US');
    current.onspeechstart=()=>{if(state.recognition===current)state.utteranceStartedMs=elapsedMs();};
    current.onspeechend=()=>{};
    current.onstart=()=>{if(state.recognition!==current)return;state.recognitionStarting=false;notify(state.recovered?'Recovered transcript resumed.':'Listening and transcribing.','success');render();};
    current.onresult=event=>{
      if(state.recognition!==current||!state.active)return;
      let interim='';
      for(let index=Math.max(0,Number(event.resultIndex||0));index<event.results.length;index+=1){
        const result=event.results[index];const text=String(result?.[0]?.transcript||'');
        if(result?.isFinal)handleFinal(text,Number(result?.[0]?.confidence));else interim+=text;
      }
      state.interim=state.continuity?state.continuity.setInterim(interim):interim.trim();emitLive('interim');
    };
    current.onerror=event=>{
      if(state.recognition!==current)return;
      const code=String(event?.error||'speech-recognition-error');
      state.lastError=code;proof.lastError=code;
      if(code==='not-allowed'||code==='service-not-allowed'){
        notify('Microphone speech recognition permission is unavailable. Finalizing the private draft.','error');void stopCapture('permission-error');return;
      }
      if(code!=='aborted'&&code!=='no-speech')notify(`Speech recognition paused: ${code}. Retrying…`,'error');
    };
    current.onend=()=>{
      if(state.recognition===current)state.recognition=null;state.recognitionStarting=false;emitLive('recognition-end');
      if(state.active)scheduleRecognition(220);
    };
    try{current.start();return true;}catch(error){state.recognition=null;state.recognitionStarting=false;state.lastError=String(error?.message||error||'Speech recognition failed.');proof.lastError=state.lastError;notify('Speech recognition is still releasing. Retrying…','error');scheduleRecognition(320);return false;}
  }

  async function startMeter(){
    if(state.meterStream||!navigator.mediaDevices?.getUserMedia)return;
    try{
      const supported=navigator.mediaDevices.getSupportedConstraints?.()||{};
      const audio={echoCancellation:true,noiseSuppression:true,autoGainControl:true,channelCount:1};
      if(supported.voiceIsolation)audio.voiceIsolation=true;
      const stream=await navigator.mediaDevices.getUserMedia({audio,video:false});state.meterStream=stream;
      const Context=window.AudioContext||window.webkitAudioContext;if(!Context)return;
      const context=new Context();const source=context.createMediaStreamSource(stream);const analyser=context.createAnalyser();analyser.fftSize=512;source.connect(analyser);state.meterContext=context;state.meterAnalyser=analyser;
      const samples=new Uint8Array(analyser.fftSize);const frequencies=new Uint8Array(analyser.frequencyBinCount);let lastFeatureAt=0;
      const tick=()=>{if(!state.meterAnalyser||!state.meterStream){state.meterFrame=0;return;}analyser.getByteTimeDomainData(samples);let sum=0,crossings=0,previous=(samples[0]-128)/128;for(const value of samples){const sample=(value-128)/128;sum+=sample*sample;if((sample>=0)!==(previous>=0))crossings+=1;previous=sample;}const rms=Math.sqrt(sum/samples.length);const level=Math.min(100,Math.round(rms*340));button.style.setProperty('--artist-listening-level',`${Math.max(8,level)}%`);if(el.liveLevel)el.liveLevel.style.width=`${Math.max(2,level)}%`;const now=performance.now();if(now-lastFeatureAt>=80){lastFeatureAt=now;analyser.getByteFrequencyData(frequencies);let weighted=0,total=0;for(let index=0;index<frequencies.length;index+=1){const magnitude=frequencies[index];weighted+=index*magnitude;total+=magnitude;}state.acousticFrames.push({timeMs:elapsedMs(),rms,zcr:crossings/samples.length,centroid:total?weighted/total/frequencies.length:0});const cutoff=elapsedMs()-12000;while(state.acousticFrames.length&&state.acousticFrames[0].timeMs<cutoff)state.acousticFrames.shift();}state.meterFrame=requestAnimationFrame(tick);};
      state.meterFrame=requestAnimationFrame(tick);
    }catch(error){notify('Transcription is active, but the microphone level meter is unavailable.','error');}
  }
  function stopMeter(){
    if(state.meterFrame)cancelAnimationFrame(state.meterFrame);state.meterFrame=0;state.meterAnalyser=null;
    if(state.meterContext){try{state.meterContext.close();}catch(error){}}state.meterContext=null;
    if(state.meterStream){for(const track of state.meterStream.getTracks?.()||[]){try{track.stop();}catch(error){}}}state.meterStream=null;state.acousticFrames=[];button.style.removeProperty('--artist-listening-level');if(el.liveLevel)el.liveLevel.style.width='0%';
  }

  function preferredRecordingMime(){
    if(typeof window.MediaRecorder!=='function')return '';
    const options=['audio/webm;codecs=opus','audio/webm','audio/ogg;codecs=opus','audio/mp4'];
    for(const mime of options){try{if(!window.MediaRecorder.isTypeSupported||window.MediaRecorder.isTypeSupported(mime))return mime;}catch(error){}}
    return '';
  }
  function stopRecordingStream(){
    if(state.recordingStream){for(const track of state.recordingStream.getTracks?.()||[]){try{track.stop();}catch(error){}}}
    state.recordingStream=null;
  }
  async function uploadRecordingBlob(blob,meta){
    const formData=new FormData();
    const mime=String(blob.type||state.recordingMime||'audio/webm');
    const ext=mime.includes('ogg')?'ogg':mime.includes('mp4')?'m4a':mime.includes('mpeg')?'mp3':mime.includes('wav')?'wav':'webm';
    formData.append('action','upload_recording');
    formData.append('csrf_token',String(cfg.csrf||''));
    formData.append('session_id',String(sessionId()));
    formData.append('client_recording_key',String(meta.key||''));
    formData.append('started_ms',String(Math.max(0,Number(meta.startedMs||0))));
    formData.append('ended_ms',String(Math.max(0,Number(meta.endedMs||0))));
    formData.append('duration_ms',String(Math.max(0,Number(meta.durationMs||0))));
    formData.append('audio',blob,`transcription-${meta.key}.${ext}`);
    const response=await fetch(String(cfg.endpoint),{method:'POST',credentials:'same-origin',headers:{Accept:'application/json'},body:formData});
    const data=await response.json().catch(()=>({ok:false,error:'Recording upload returned an invalid response.'}));
    if(!response.ok||!data.ok)throw new Error(String(data.error||`Recording upload failed (${response.status}).`));
    return data;
  }
  async function startAudioRecording(source='button'){
    if(state.recordingActive||state.recordingStarting||state.recordingUploading)return;
    if(!state.active){notify('Start Listening before starting retained audio.','error');return;}
    if(typeof window.MediaRecorder!=='function'||!navigator.mediaDevices?.getUserMedia){notify('Audio recording is not supported by this browser.','error');return;}
    state.recordingStarting=true;render();
    try{
      const selectedMic=String(document.querySelector('[data-listening-workspace-mic]')?.value||'');
      const supported=navigator.mediaDevices.getSupportedConstraints?.()||{};
      const audio={channelCount:1};
      if(selectedMic)audio.deviceId={exact:selectedMic};
      if(supported.echoCancellation)audio.echoCancellation=false;
      if(supported.noiseSuppression)audio.noiseSuppression=false;
      if(supported.autoGainControl)audio.autoGainControl=false;
      const stream=await navigator.mediaDevices.getUserMedia({audio,video:false});
      const mime=preferredRecordingMime();
      const recorder=mime?new MediaRecorder(stream,{mimeType:mime}):new MediaRecorder(stream);
      state.recordingStream=stream;state.mediaRecorder=recorder;state.recordingChunks=[];state.recordingKey=uuid();state.recordingMime=String(recorder.mimeType||mime||'audio/webm');state.recordingStartedMs=elapsedMs();
      recorder.addEventListener('dataavailable',event=>{if(event.data&&event.data.size>0)state.recordingChunks.push(event.data);});
      recorder.addEventListener('error',event=>{state.lastError=String(event?.error?.message||'Audio recorder error.');proof.lastError=state.lastError;proof.recordingErrors+=1;notify(state.lastError,'error');});
      recorder.start(1000);state.recordingActive=true;proof.recordingStarts+=1;notify('Audio recording started. Transcription is still listening.','success');render();emitLive('recording-started',{recordingKey:state.recordingKey,source});
    }catch(error){stopRecordingStream();state.mediaRecorder=null;state.recordingChunks=[];state.recordingKey='';state.recordingActive=false;proof.recordingErrors+=1;state.lastError=String(error?.message||error);proof.lastError=state.lastError;notify(`Could not start audio recording: ${state.lastError}`,'error');}
    finally{state.recordingStarting=false;render();}
  }
  async function stopAudioRecording(source='button',endedOverride=null){
    if(state.recordingStopPromise)return state.recordingStopPromise;
    const recorder=state.mediaRecorder;
    if(!recorder||recorder.state==='inactive'){state.recordingActive=false;stopRecordingStream();render();return null;}
    const key=String(state.recordingKey||uuid());const startedMs=Math.max(0,Number(state.recordingStartedMs||0));const endedMs=Math.max(startedMs,Number(endedOverride===null?elapsedMs():endedOverride));const mime=String(recorder.mimeType||state.recordingMime||'audio/webm');
    state.recordingActive=false;state.recordingUploading=true;render();
    state.recordingStopPromise=new Promise((resolve,reject)=>{
      const finish=async()=>{
        try{
          const blob=new Blob(state.recordingChunks,{type:mime});
          if(blob.size<1)throw new Error('The retained recording was empty.');
          const data=await uploadRecordingBlob(blob,{key,startedMs,endedMs,durationMs:endedMs-startedMs});
          state.session=data.session||state.session;proof.recordingStops+=1;proof.recordingUploads+=1;notify(state.active?'Audio clip saved. Transcription is still listening.':'Audio clip saved.','success');emitLive('recording-saved',{recording:data.recording||null,session:data.session||state.session,source});resolve(data.recording||null);
        }catch(error){proof.recordingErrors+=1;state.lastError=String(error?.message||error);proof.lastError=state.lastError;notify(`Recording could not be saved: ${state.lastError}`,'error');reject(error);}
        finally{stopRecordingStream();state.mediaRecorder=null;state.recordingChunks=[];state.recordingKey='';state.recordingMime='';state.recordingStartedMs=0;state.recordingUploading=false;state.recordingStopPromise=null;render();}
      };
      recorder.addEventListener('stop',()=>void finish(),{once:true});
      try{recorder.requestData();}catch(error){}
      try{recorder.stop();}catch(error){void finish();}
    });
    state.recordingStopPromise.catch(()=>{});return state.recordingStopPromise;
  }


  async function startCapture(source='button'){
    if(state.active||state.startPromise)return;
    if(!sessionIsDraft()&&String(state.session?.status||'')!=='active'&&String(state.session?.status||'')!=='starting'){
      notify('Create a new transcription document before starting a recording.','error');return;
    }
    if(typeof SpeechRecognitionCtor!=='function'){notify('Live speech recognition is not supported by this browser.','error');return;}
    if(!cfg.schemaReady){notify('Run the Stonefellow v172 database upgrade before using Artist Listening.','error');return;}
    disableAgentConversation();
    const persisted=readPersisted();
    const selectedDraft=sessionIsDraft()&&sessionId()>0;
    const localStartPending=String(state.session?.status||'')==='starting'&&!!state.session?.clientSessionKey&&state.pending.length>0;
    if(String(state.session?.status||'')!=='active'&&!localStartPending&&!selectedDraft){
      state.session={clientSessionKey:uuid(),title:realtime?.autoTitle?.(new Date())||('Recording · '+new Date().toLocaleString()),status:'starting',language:document.documentElement.lang||'en-US',segments:[]};
      state.pending=[];state.segmentIndex=0;state.elapsedBeforeResume=0;
    }else if(!selectedDraft){
      state.recovered=true;proof.resumes+=1;
      state.elapsedBeforeResume=Math.max(Number(state.session.duration_ms||0),Number(persisted.elapsedMs||0));
    }else{
      state.recovered=false;
      state.elapsedBeforeResume=Math.max(0,Number(state.session.duration_ms||0));
    }
    state.active=true;state.pendingStop=false;state.captureStartedAt=Date.now();state.interim='';state.noteArmed=false;state.utteranceStartedMs=0;state.acousticFrames=[];
    if(realtime){state.continuity=new realtime.TranscriptContinuity();state.speakerModel=new realtime.SpeakerTurnModel({maxSpeakers:4,expectedSpeakers:state.expectedSpeakers});}
    proof.starts+=1;if(source==='voice-command')proof.commandStarts+=1;
    void startMeter();startRecognition();render();
    const clientKey=String(state.session.client_session_key||state.session.clientSessionKey||persisted.clientSessionKey||uuid());
    const beginRequest=selectedDraft
      ? request('activate',{session_id:sessionId(),conversation_id:activeConversationId()})
      : request('start',{
        client_session_key:clientKey,conversation_id:activeConversationId(),language:String(state.session.language||'en-US'),
        speaker_mode:String(el.speakerMode.value||'auto'),
      });
    state.startPromise=beginRequest.then(data=>{
      state.session=data.session;state.recovered=!!data.session?.recovered||state.recovered;
      const saved=Array.isArray(state.session?.segments)?state.session.segments:[];
      state.segmentIndex=saved.reduce((max,row)=>Math.max(max,Number(row.segment_index||0)+1),state.segmentIndex);
      if(state.continuity){const captured=[...saved,...state.pending].filter(row=>String(row.segment_type||row.type||'')==='transcript').map(row=>String(row.transcript_text||row.text||''));state.continuity.seed(captured.join(' '));}
      persist();render();emitLive('session-started',{sessionId:Number(state.session?.id||0)});void flushPending();return state.session;
    }).catch(error=>{
      state.active=false;stopRecognition();stopMeter();state.lastError=String(error?.message||error);proof.lastError=state.lastError;notify(state.lastError,'error');render();throw error;
    }).finally(()=>{state.startPromise=null;render();});
    state.startPromise.catch(()=>{});
    return state.startPromise;
  }

  async function flushPending(){
    if(state.syncing||!state.pending.length)return;
    if(state.startPromise){try{await state.startPromise;}catch(error){return;}}
    const id=sessionId();if(id<1)return;
    state.syncing=true;const batch=state.pending.slice(0,50);const keys=new Set(batch.map(segment=>segment.key));
    try{
      const data=await request('append',{session_id:id,segments:batch});proof.syncs+=1;
      state.pending=state.pending.filter(segment=>!keys.has(segment.key));state.session=data.session;
      persist();render();emitLive('synced',{accepted:Number(data.accepted||0)});
    }catch(error){
      proof.syncErrors+=1;state.lastError=String(error?.message||error);proof.lastError=state.lastError;
      notify(`Transcript kept locally. Sync will retry: ${state.lastError}`,'error');persist();scheduleRetry();
    }finally{
      state.syncing=false;
      if(state.pending.length)setTimeout(()=>void flushPending(),0);
      else if(state.pendingStop)setTimeout(()=>void finalizeStop(),0);
    }
  }
  function scheduleRetry(){
    if(state.retryTimer)clearTimeout(state.retryTimer);
    if(!state.pending.length&&!state.pendingStop)return;
    state.retryTimer=setTimeout(()=>{state.retryTimer=0;if(state.pending.length)void flushPending();else if(state.pendingStop)void finalizeStop();},4000);
  }
  async function finalizeStop(){
    if(!state.pendingStop||state.syncing||state.pending.length)return;
    if(state.startPromise){try{await state.startPromise;}catch(error){scheduleRetry();return;}}
    const id=sessionId();if(id<1){scheduleRetry();return;}
    try{
      const data=await request('stop',{session_id:id,duration_ms:elapsedMs()});state.session=data.session;state.pendingStop=false;state.elapsedBeforeResume=Number(state.session.duration_ms||elapsedMs());proof.stops+=1;
      notify('Private transcript draft saved. Nothing was added to Agent Brain or the Knowledge Base.','success');persist();await refreshSessions();render();emitLive('stopped',{sessionId:Number(state.session?.id||0)});
    }catch(error){state.lastError=String(error?.message||error);proof.lastError=state.lastError;notify(`Stop is pending sync: ${state.lastError}`,'error');persist();scheduleRetry();}
  }
  async function stopCapture(source='button'){
    if(!state.active&&String(state.session?.status||'')!=='active')return;
    if(state.active&&state.interim)handleFinal(state.interim,null);
    if(state.flushTimer)clearTimeout(state.flushTimer);state.flushTimer=0;
    const stoppedAt=elapsedMs();state.active=false;state.interim='';state.continuity?.setInterim('');state.noteArmed=false;state.elapsedBeforeResume=stoppedAt;state.captureStartedAt=0;state.pendingStop=true;stopRecognition();stopMeter();notify(state.recordingActive?'Stopping retained audio and finalizing transcript…':'Finalizing transcript draft…');render();emitLive('stopping');
    if(state.recordingActive||state.mediaRecorder){try{await stopAudioRecording('listening-stop',stoppedAt);}catch(error){}}
    if(state.pending.length)await flushPending();else await finalizeStop();
  }
  function addMarker(){if(!state.active)return null;const segment=nextSegment('marker','Marked moment');notify('Moment marked.','success');return segment;}
  function addManualNote(){
    if(!state.active)return;const note=window.prompt('Add a timestamped note:','');if(note===null)return;
    if(String(note).trim()){nextSegment('note',String(note));notify('Note added.','success');}
  }

  async function refreshSessions(){
    try{
      const data=await request('bootstrap',{},'GET');state.sessions=Array.isArray(data.sessions)?data.sessions:[];
      if(data.active&&!state.session){state.session=data.active;state.recovered=true;proof.recoveredDrafts+=1;const saved=Array.isArray(state.session.segments)?state.session.segments:[];state.segmentIndex=saved.reduce((max,row)=>Math.max(max,Number(row.segment_index||0)+1),0);}
      render();
    }catch(error){notify(String(error?.message||error),'error');}
  }

  function transcriptionCaptureState(){
    const segments=combinedSegments();
    return {
      sessionId:sessionId(),
      status:String(state.session?.status||''),
      active:!!state.active,
      recovered:!!state.recovered,
      pendingStop:!!state.pendingStop,
      syncing:!!state.syncing,
      recordingActive:!!state.recordingActive,
      recordingStarting:!!state.recordingStarting,
      recordingUploading:!!state.recordingUploading,
      elapsedMs:elapsedMs(),
      segmentCount:segments.length,
      transcriptCount:segments.filter(row=>String(row.segment_type||row.type||'')==='transcript').length,
      markerCount:segments.filter(row=>String(row.segment_type||row.type||'')==='marker').length,
      noteCount:segments.filter(row=>String(row.segment_type||row.type||'')==='note').length,
      speakerMode:String(el.speakerMode.value||'auto'),
      expectedSpeakers:Math.max(0,Number(state.expectedSpeakers||0)),
      interim:String(state.interim||''),
      lastError:String(state.lastError||''),
    };
  }
  function transcriptionSetSpeakerMode(value){
    value=String(value||'auto');
    if(![...el.speakerMode.options].some(option=>option.value===value))throw new Error('Unsupported speaker mode.');
    el.speakerMode.value=value;
    state.expectedSpeakers=value==='auto'?0:Math.max(1,Math.min(4,Number(value||1)));
    state.speakerModel?.setExpected(state.expectedSpeakers);
    try{localStorage.setItem(`${STORAGE_KEY}:speaker-mode`,value);}catch(error){}
    render();
    return value;
  }
  proof.api={
    getState:transcriptionCaptureState,
    start:async()=>{await startCapture('transcription-api');return transcriptionCaptureState();},
    stop:async()=>{await stopCapture('transcription-api');return transcriptionCaptureState();},
    startRecording:async()=>{await startAudioRecording('transcription-api');return transcriptionCaptureState();},
    stopRecording:async()=>{const result=await stopAudioRecording('transcription-api');return {recording:result,state:transcriptionCaptureState()};},
    addMarker:()=>{if(!state.active)throw new Error('Start listening before adding a marker.');return addMarker();},
    addNote:text=>{if(!state.active)throw new Error('Start listening before adding a note.');text=String(text||'').trim();if(!text)throw new Error('Note text is required.');const segment=nextSegment('note',text);notify('Note added.','success');return segment;},
    setSpeakerMode:transcriptionSetSpeakerMode,
  };

  el.start.addEventListener('click',()=>void startCapture('button'));
  el.stop.addEventListener('click',()=>void stopCapture('button'));
  el.record.addEventListener('click',()=>{if(state.recordingActive||state.mediaRecorder)void stopAudioRecording('button');else void startAudioRecording('button');});
  el.marker.addEventListener('click',addMarker);
  el.note.addEventListener('click',addManualNote);
  el.speakerMode.addEventListener('change',()=>{
    const value=String(el.speakerMode.value||'auto');state.expectedSpeakers=value==='auto'?0:Math.max(1,Math.min(4,Number(value||1)));
    state.speakerModel?.setExpected(state.expectedSpeakers);try{localStorage.setItem(`${STORAGE_KEY}:speaker-mode`,value);}catch(error){}render();
  });
  window.addEventListener('stonefellow:artist-listening-before-pause',()=>{if(state.active&&state.interim)handleFinal(state.interim,null);});
  window.addEventListener('stonefellow:artist-listening-document-selected',event=>{
    if(state.active||state.startPromise||state.pendingStop)return;
    const session=event?.detail?.session;
    if(!session){state.session=null;state.pending=[];state.interim='';state.elapsedBeforeResume=0;state.segmentIndex=0;render();return;}
    if(Number(session.id||0)<1)return;
    const backup=readPersisted();
    const sameBackup=Number(backup.sessionId||0)===Number(session.id||0)||String(backup.clientSessionKey||'')===String(session.client_session_key||'');
    state.session=session;if(!sameBackup)state.pending=[];state.interim='';state.noteArmed=false;state.recovered=String(session.status||'')==='active';
    state.elapsedBeforeResume=Math.max(0,Number(session.duration_ms||0));
    const saved=Array.isArray(session.segments)?session.segments:[];
    state.segmentIndex=saved.reduce((max,row)=>Math.max(max,Number(row.segment_index||0)+1),0);
    if(realtime){
      state.continuity=new realtime.TranscriptContinuity();
      state.continuity.seed(saved.filter(row=>String(row.segment_type||'')==='transcript').map(row=>String(row.transcript_text||'')).join(' '));
      state.speakerModel=new realtime.SpeakerTurnModel({maxSpeakers:4,expectedSpeakers:state.expectedSpeakers});
    }
    render();
  });

  form.addEventListener('submit',event=>{
    const command=commandFor(input.value);if(!command)return;
    if(command.name==='start'&&!state.active){event.preventDefault();event.stopImmediatePropagation();input.value='';input.dispatchEvent(new Event('input',{bubbles:true}));void startCapture('voice-command');return;}
    if(state.active&&['stop','record-start','record-stop','marker','note','note-text','cancel-note'].includes(command.name)){event.preventDefault();event.stopImmediatePropagation();input.value='';input.dispatchEvent(new Event('input',{bubbles:true}));runPassiveCommand(command);}
  },true);
  document.addEventListener('keydown',event=>{
    if(event.repeat||event.altKey||!event.shiftKey||!(event.ctrlKey||event.metaKey))return;
    const target=event.target;if(target instanceof HTMLInputElement||target instanceof HTMLTextAreaElement||target instanceof HTMLSelectElement||target?.isContentEditable)return;
    if(String(event.key).toLowerCase()==='l'){event.preventDefault();if(state.active||String(state.session?.status||'')==='active')void stopCapture('quick-key');else void startCapture('quick-key');}
    if(String(event.key).toLowerCase()==='r'&&state.active){event.preventDefault();if(state.recordingActive||state.mediaRecorder)void stopAudioRecording('quick-key');else void startAudioRecording('quick-key');}
    if(String(event.key).toLowerCase()==='m'&&state.active){event.preventDefault();addMarker();}
  },true);
  window.addEventListener('online',()=>{if(state.pending.length)void flushPending();else if(state.pendingStop)void finalizeStop();});
  window.addEventListener('pagehide',()=>{persist();stopRecognition();stopMeter();if(state.mediaRecorder&&state.mediaRecorder.state!=='inactive'){try{state.mediaRecorder.stop();}catch(error){}}stopRecordingStream();if(state.timer)clearInterval(state.timer);if(state.flushTimer)clearTimeout(state.flushTimer);},{once:true});

  const persisted=readPersisted();
  if(Array.isArray(persisted.pending))state.pending=persisted.pending.filter(segment=>segment&&typeof segment==='object');
  state.pendingStop=!!persisted.pendingStop;state.elapsedBeforeResume=Math.max(0,Number(persisted.elapsedMs||0));
  try{const mode=localStorage.getItem(`${STORAGE_KEY}:speaker-mode`)||'auto';if([...el.speakerMode.options].some(option=>option.value===mode))el.speakerMode.value=mode;}catch(error){}
  state.expectedSpeakers=el.speakerMode.value==='auto'?0:Math.max(1,Math.min(4,Number(el.speakerMode.value||1)));state.speakerModel?.setExpected(state.expectedSpeakers);
  state.timer=setInterval(()=>{el.timer.textContent=formatTime(elapsedMs());},250);
  render();void refreshSessions().then(()=>{if(state.pending.length)void flushPending();else if(state.pendingStop)void finalizeStop();});
  window.dispatchEvent(new CustomEvent('stonefellow:artist-listening-ready',{detail:{build:BUILD,audioRetained:true,mediaRecorder:typeof window.MediaRecorder==='function'}}));
})();
