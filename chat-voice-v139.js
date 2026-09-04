(() => {
  'use strict';

  const BUILD='chat-voice-direct-v139-20260826';
  const cfg=window.STONEFELLOW_CHAT||{};
  const boot=window.STONEFELLOW_CHAT_V139_BOOT||window.STONEFELLOW_CHAT_V131_BOOT||{};
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
  const streamUrl=String(cfg.voiceStreamEndpoint||'/api/chat-stream-v121.php');
  const previousFetch=window.fetch;
  const nativeFetch=previousFetch.bind(window);
  const chatUrl=(()=>{try{return new URL(String(cfg.endpoint||''),location.href);}catch(error){return null;}})();
  const premium=typeof PremiumVoice==='function'?PremiumVoice({agentEndpoint:String(cfg.endpoint||'/api/chat.php'),csrf:String(cfg.csrf||'')}):null;

  let voiceOn=false;
  try{voiceOn=localStorage.getItem(MODE_KEY)==='1';}catch(error){}
  let recognition=null;
  let recognitionStarting=false;
  let recognitionListening=false;
  let recognitionStopReason='';
  let restartTimer=0;
  let processing=false;
  let speaking=false;
  let textBusy=false;
  let voiceSubmitPending=false;
  let queuedTranscript='';
  let activeRequest=null;
  let speechSession=null;
  let lastConversationId=Number(cfg.initialConversationId||0);
  let bargeStream=null;
  let bargeContext=null;
  let bargeFrame=0;
  let bargeStartedAt=0;
  let bargeBaseline=0;
  let bargeHotSince=0;
  const events=[];

  const proof=window.STONEFELLOW_CHAT_VOICE_V139={
    build:BUILD,loaded:true,directOwner:true,sharedConversationController:false,
    startCalls:0,starts:0,results:0,finals:0,errors:0,ends:0,submits:0,
    streamTurns:0,streamedDeltas:0,speechStarts:0,speechEnds:0,interruptions:0,
    lastError:'',lastTranscript:'',lastConversationId
  };

  function log(type,detail={}){
    const item={at:new Date().toISOString(),type:String(type),detail};
    events.push(item);if(events.length>160)events.splice(0,events.length-160);
    try{window.dispatchEvent(new CustomEvent('stonefellow:chat-voice-v139',{detail:{build:BUILD,...item}}));}catch(error){}
    renderDebug();
  }
  function setStatus(text='',state=''){
    if(!status)return;
    status.hidden=!text;status.textContent=text;status.dataset.state=state;
  }
  function syncButton(){
    button.classList.toggle('active',voiceOn);
    button.setAttribute('aria-pressed',voiceOn?'true':'false');
    button.setAttribute('aria-label',voiceOn?'Stop voice conversation':'Start voice conversation');
  }
  function setAgentState(state,text=''){
    document.body.dataset.stonefellowAgentState=state||'idle';
    button.dataset.agentState=state||'idle';
    button.classList.toggle('ai-listening',state==='listening');
    button.classList.toggle('ai-thinking',state==='processing');
    button.classList.toggle('ai-responding',state==='speaking');
    if(state==='listening')setStatus(text||'Listening…','listening');
    else if(state==='processing')setStatus(text||'Thinking…','processing');
    else if(state==='speaking')setStatus(text||'Stonefellow is responding…','speaking');
    else if(state==='error')setStatus(text||'Voice input is unavailable.','error');
    else if(voiceOn)setStatus(text||'Voice conversation on','ready');
    else setStatus('','');
  }
  function writeMode(){try{localStorage.setItem(MODE_KEY,voiceOn?'1':'0');}catch(error){}syncButton();}
  function activeConversationId(){
    const active=document.querySelector('.chat-history-item.active[data-conversation-id]');
    return Math.max(0,Number(active?.dataset.conversationId||lastConversationId||cfg.initialConversationId||0));
  }
  function syncConversation(id){
    const next=Math.max(0,Number(id||0));if(next<1)return;
    lastConversationId=next;proof.lastConversationId=next;AgentContext?.setConversationId?.(next);
  }
  function updateComposer(text){
    input.value=String(text||'');
    input.dispatchEvent(new Event('input',{bubbles:true}));
  }
  function clearRestart(){if(restartTimer)clearTimeout(restartTimer);restartTimer=0;}
  function scheduleListening(delay=180,reason='resume'){
    clearRestart();
    if(!voiceOn||speaking||processing||textBusy||voiceSubmitPending)return;
    restartTimer=setTimeout(()=>startListening(reason),Math.max(0,Number(delay)||0));
  }
  function stopRecognition(reason='pause',abort=false){
    clearRestart();recognitionStopReason=reason;
    const current=recognition;
    if(!current)return;
    try{abort?current.abort():current.stop();}catch(error){}
  }
  function createRecognition(){
    const current=new SpeechRecognitionCtor();
    current.continuous=false;
    current.interimResults=true;
    current.lang=document.documentElement.lang||'en-US';
    current.onstart=()=>{
      if(recognition!==current)return;
      recognitionStarting=false;recognitionListening=true;proof.starts+=1;
      setAgentState('listening','Listening…');
      log('SR_STARTED',{starts:proof.starts});
    };
    current.onresult=event=>{
      if(recognition!==current)return;
      proof.results+=1;
      let interim='',finalText='';
      const start=Math.max(0,Number(event.resultIndex||0));
      for(let i=start;i<event.results.length;i+=1){
        const result=event.results[i];const text=String(result?.[0]?.transcript||'');
        if(result?.isFinal)finalText+=text;else interim+=text;
      }
      const heard=String(finalText||interim||'').trim();
      if(heard){
        proof.lastTranscript=heard;updateComposer(heard);
        setAgentState('listening',`Listening · ${heard}`);
        log('SR_RESULT',{final:!!finalText,text:heard});
      }
      if(!finalText.trim())return;
      proof.finals+=1;
      recognitionStopReason='submit';
      try{current.stop();}catch(error){}
      submitVoiceTranscript(finalText.trim());
    };
    current.onerror=event=>{
      if(recognition!==current)return;
      recognitionStarting=false;recognitionListening=false;proof.errors+=1;
      const kind=String(event?.error||'unknown');proof.lastError=kind;log('SR_ERROR',{error:kind,message:String(event?.message||'')});
      if(kind==='not-allowed'||kind==='service-not-allowed'){
        voiceOn=false;writeMode();setAgentState('error','Microphone permission is blocked.');return;
      }
      if(kind==='audio-capture'){
        setAgentState('error','No usable microphone input is available.');return;
      }
      if(voiceOn&&!processing&&!speaking)scheduleListening(kind==='no-speech'?160:320,`error:${kind}`);
    };
    current.onend=()=>{
      if(recognition!==current)return;
      recognitionStarting=false;recognitionListening=false;proof.ends+=1;
      const reason=recognitionStopReason;recognitionStopReason='';
      log('SR_ENDED',{reason,ends:proof.ends});
      if(!voiceOn||processing||speaking||voiceSubmitPending||textBusy)return;
      if(reason==='submit'||reason==='response'||reason==='off')return;
      scheduleListening(180,'onend');
    };
    return current;
  }
  function startListening(reason='manual'){
    clearRestart();
    if(!voiceOn||processing||speaking||textBusy||voiceSubmitPending||recognitionStarting||recognitionListening)return false;
    if(typeof SpeechRecognitionCtor!=='function'){setAgentState('error','Browser speech recognition is unavailable.');return false;}
    if(!recognition)recognition=createRecognition();
    proof.startCalls+=1;recognitionStopReason='';recognitionStarting=true;
    log('SR_START_CALL',{reason,call:proof.startCalls,userActivation:!!navigator.userActivation?.isActive});
    try{recognition.start();return true;}
    catch(error){
      recognitionStarting=false;proof.errors+=1;proof.lastError=String(error?.message||error||'start failed');
      log('SR_START_THROW',{name:String(error?.name||'Error'),message:proof.lastError});
      recognition=null;if(voiceOn)scheduleListening(260,'start-throw');return false;
    }
  }

  function stopBarge(){
    if(bargeFrame)cancelAnimationFrame(bargeFrame);bargeFrame=0;bargeHotSince=0;bargeBaseline=0;
    try{bargeStream?.getTracks?.().forEach(track=>track.stop());}catch(error){}bargeStream=null;
    if(bargeContext){try{bargeContext.close();}catch(error){}}bargeContext=null;
  }
  async function startBarge(){
    stopBarge();if(!voiceOn||!speaking||!navigator.mediaDevices?.getUserMedia)return;
    try{
      const stream=await navigator.mediaDevices.getUserMedia({audio:{echoCancellation:true,noiseSuppression:true,autoGainControl:true},video:false});
      if(!speaking||!voiceOn){stream.getTracks().forEach(track=>track.stop());return;}
      const Ctx=window.AudioContext||window.webkitAudioContext;if(!Ctx){stream.getTracks().forEach(track=>track.stop());return;}
      const context=new Ctx();if(context.state==='suspended'){try{await context.resume();}catch(error){}}
      const analyser=context.createAnalyser();analyser.fftSize=512;analyser.smoothingTimeConstant=.25;
      context.createMediaStreamSource(stream).connect(analyser);
      const samples=new Uint8Array(analyser.fftSize);bargeStream=stream;bargeContext=context;bargeStartedAt=performance.now();
      const tick=()=>{
        if(!speaking||!voiceOn||bargeStream!==stream){stopBarge();return;}
        analyser.getByteTimeDomainData(samples);let sum=0;
        for(const value of samples){const n=(value-128)/128;sum+=n*n;}
        const rms=Math.sqrt(sum/Math.max(1,samples.length));const age=performance.now()-bargeStartedAt;
        if(age<500){bargeBaseline=Math.max(bargeBaseline,rms);}
        else{
          const threshold=Math.max(.055,bargeBaseline*2.8+.012);
          if(rms>threshold){if(!bargeHotSince)bargeHotSince=performance.now();if(performance.now()-bargeHotSince>140){log('BARGE_TRIGGER',{rms,threshold});interruptResponse('mic-level');return;}}
          else bargeHotSince=0;
        }
        bargeFrame=requestAnimationFrame(tick);
      };
      bargeFrame=requestAnimationFrame(tick);log('BARGE_ARMED',{});
    }catch(error){log('BARGE_ERROR',{name:String(error?.name||'Error'),message:String(error?.message||error||'')});}
  }

  function onSpeechStart(){
    processing=false;speaking=true;proof.speechStarts+=1;stopRecognition('response',true);
    setAgentState('speaking','Stonefellow is responding…');log('SPEECH_STARTED',{count:proof.speechStarts});void startBarge();
  }
  function onSpeechEnd(){
    stopBarge();speaking=false;processing=false;speechSession=null;proof.speechEnds+=1;
    log('SPEECH_ENDED',{count:proof.speechEnds});
    if(voiceOn){setAgentState('idle','Voice conversation on');scheduleListening(320,'speech-end');}else setAgentState('idle');
  }
  function browserSpeak(text){
    const message=String(text||'').trim();
    if(!message||!('speechSynthesis'in window)||!window.SpeechSynthesisUtterance){onSpeechEnd();return;}
    const utterance=new SpeechSynthesisUtterance(message);utterance.onstart=onSpeechStart;utterance.onend=onSpeechEnd;utterance.onerror=onSpeechEnd;
    try{window.speechSynthesis.cancel();window.speechSynthesis.speak(utterance);}catch(error){onSpeechEnd();}
  }
  function speakStandalone(text){
    const message=String(text||'').trim();if(!voiceOn||!message)return Promise.resolve(false);
    stopRecognition('response',true);processing=true;setAgentState('processing','Preparing voice…');
    if(!premium?.speak){browserSpeak(message);return Promise.resolve(true);}
    return Promise.resolve(premium.speak(message,{onStart:onSpeechStart,onEnd:onSpeechEnd,onError:()=>browserSpeak(message)})).catch(()=>{browserSpeak(message);return false;});
  }
  function interruptResponse(trigger='manual'){
    if(!speaking&&!processing)return;
    proof.interruptions+=1;log('INTERRUPT',{trigger,count:proof.interruptions});stopBarge();
    try{speechSession?.stop?.();}catch(error){}speechSession=null;try{premium?.stop?.();}catch(error){}try{window.speechSynthesis?.cancel();}catch(error){}
    if(activeRequest&&!activeRequest.controller.signal.aborted){activeRequest.interrupted=true;activeRequest.controller.abort();}
    speaking=false;processing=false;setAgentState('idle','Voice conversation on');
    recognition=null;recognitionStarting=false;recognitionListening=false;
    if(voiceOn)startListening('interrupt');
  }

  function isChatSend(inputArg,init,payload){
    if(!chatUrl||String(init?.method||'GET').toUpperCase()!=='POST'||payload?.action!=='send')return false;
    try{const target=new URL(typeof inputArg==='string'?inputArg:inputArg?.url||'',location.href);return target.origin===chatUrl.origin&&target.pathname===chatUrl.pathname;}catch(error){return false;}
  }
  async function attachContext(payload){
    if(!AgentContext)return payload;
    try{return {...payload,agent_context:await AgentContext.refresh(false)};}catch(error){return {...payload,agent_context:AgentContext.snapshot?.()||{}};}
  }
  function runtimeId(prefix='voice'){
    try{return `${prefix}-${crypto.randomUUID()}`;}catch(error){return `${prefix}-${Date.now().toString(36)}-${Math.random().toString(36).slice(2,10)}`;}
  }
  async function parseStream(response,request){
    const reader=response.body?.getReader?.();if(!reader)throw new Error('Streaming response is unavailable.');
    const decoder=new TextDecoder();let buffer='';let doneData=null;let fullText='';let premiumFailed=false;
    const processLine=line=>{
      if(!line.trim())return;
      const event=JSON.parse(line);
      if(event.type==='start'){
        request.serverStarted=true;request.conversationId=Number(event.conversation_id||request.conversationId||0);syncConversation(request.conversationId);
      }else if(event.type==='delta'){
        const delta=String(event.delta||'');if(!delta)return;fullText+=delta;proof.streamedDeltas+=1;
        if(!speechSession&&!premiumFailed&&premium?.createStream){
          try{speechSession=premium.createStream({onStart:onSpeechStart,onEnd:onSpeechEnd,onError:error=>{premiumFailed=true;proof.lastError=String(error?.message||error||'Voice playback failed');log('SPEECH_ERROR',{message:proof.lastError});onSpeechEnd();}});}
          catch(error){premiumFailed=true;log('SPEECH_CREATE_ERROR',{message:String(error?.message||error||'')});}
        }
        speechSession?.push?.(delta);
      }else if(event.type==='done'){
        doneData=event.data||null;if(doneData?.conversation_id)syncConversation(doneData.conversation_id);
      }else if(event.type==='error')throw new Error(String(event.error||'Voice conversation failed.'));
    };
    while(true){const {value,done}=await reader.read();if(value)buffer+=decoder.decode(value,{stream:!done});let newline;while((newline=buffer.indexOf('\n'))>=0){processLine(buffer.slice(0,newline));buffer=buffer.slice(newline+1);}if(done)break;}
    buffer+=decoder.decode();if(buffer.trim())processLine(buffer);
    if(speechSession)speechSession.end();
    else if(fullText.trim()&&!request.interrupted)browserSpeak(fullText);
    return doneData;
  }
  function interruptedData(request){
    return {ok:true,conversation_id:Number(request.conversationId||lastConversationId||0),answer:'Interrupted.',sources:[],media:[],stem_media:[],actions:[],playlist_title:'',input_mode:'voice',stream_partial:true,interrupted:true};
  }
  async function handleVoiceFetch(inputArg,init,payload){
    proof.streamTurns+=1;processing=true;setAgentState('processing','Thinking…');stopRecognition('submit',true);
    const request={controller:new AbortController(),interrupted:false,serverStarted:false,conversationId:Number(payload?.conversation_id||0)};activeRequest=request;
    payload={...payload,input_mode:'voice',idempotency_key:runtimeId('voice-turn')};
    try{
      const response=await nativeFetch(streamUrl,{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json','Accept':'application/x-ndjson'},body:JSON.stringify(payload),signal:request.controller.signal});
      if(!response.ok||!response.body)throw new Error(`Voice stream HTTP ${response.status}`);
      const doneData=await parseStream(response,request);if(!doneData&&!request.interrupted)throw new Error('Voice stream ended without a final response.');
      activeRequest=null;processing=false;
      const data=request.interrupted?interruptedData(request):doneData;
      if(!speechSession&&!speaking&&voiceOn)scheduleListening(220,'voice-response-no-audio');
      return new Response(JSON.stringify(data),{status:200,headers:{'Content-Type':'application/json; charset=UTF-8','Cache-Control':'no-store'}});
    }catch(error){
      activeRequest=null;processing=false;
      if(request.interrupted){const data=interruptedData(request);return new Response(JSON.stringify(data),{status:200,headers:{'Content-Type':'application/json; charset=UTF-8','Cache-Control':'no-store'}});}
      proof.lastError=String(error?.message||error||'Voice conversation failed.');setAgentState('error',proof.lastError);log('VOICE_FETCH_ERROR',{message:proof.lastError});
      if(voiceOn)scheduleListening(420,'voice-fetch-error');
      return new Response(JSON.stringify({ok:false,error:proof.lastError}),{status:502,headers:{'Content-Type':'application/json; charset=UTF-8','Cache-Control':'no-store'}});
    }finally{
      if(queuedTranscript&&!processing&&!speaking){const next=queuedTranscript;queuedTranscript='';setTimeout(()=>submitVoiceTranscript(next),0);}
    }
  }

  const routedFetch=async function(inputArg,init={}){
    let payload=null;if(typeof init?.body==='string'){try{payload=JSON.parse(init.body);}catch(error){}}
    const chatSend=isChatSend(inputArg,init,payload);
    if(chatSend&&payload){payload=await attachContext(payload);init={...init,body:JSON.stringify(payload)};}
    if(chatSend&&voiceSubmitPending){voiceSubmitPending=false;return handleVoiceFetch(inputArg,init,payload||{});}
    if(!chatSend)return nativeFetch(inputArg,init);
    textBusy=true;if(voiceOn)stopRecognition('text-send',true);
    try{return await nativeFetch(inputArg,init);}finally{textBusy=false;if(voiceOn&&!speaking&&!processing)scheduleListening(160,'text-send-end');}
  };
  window.fetch=routedFetch;

  function submitVoiceTranscript(text){
    const transcript=String(text||'').trim();if(!transcript)return;
    if(processing||speaking||textBusy||activeRequest){queuedTranscript=transcript;log('TRANSCRIPT_QUEUED',{text:transcript});return;}
    proof.submits+=1;voiceSubmitPending=true;processing=true;updateComposer(transcript);setAgentState('processing','Thinking…');log('TRANSCRIPT_SUBMIT',{text:transcript,count:proof.submits});
    try{form.requestSubmit();}catch(error){processing=false;voiceSubmitPending=false;proof.lastError=String(error?.message||error||'Submit failed');setAgentState('error',proof.lastError);}
  }

  function setVoice(next,{persist=true,immediate=false}={}){
    voiceOn=!!next;if(persist)writeMode();else syncButton();
    if(!voiceOn){queuedTranscript='';voiceSubmitPending=false;stopRecognition('off',true);interruptResponse('off');setAgentState('idle');return;}
    try{void premium?.warm?.();}catch(error){}
    setAgentState('idle','Voice conversation on');
    if(immediate)startListening('button');else scheduleListening(60,'enable');
  }
  button.addEventListener('click',()=>{
    if(button.disabled)return;
    if(voiceOn){setVoice(false,{persist:true});return;}
    setVoice(true,{persist:true,immediate:true});
  });

  function introTexts(intro){
    const greeting=String(intro?.greeting||'').trim();const updates=Array.isArray(intro?.updates)?intro.updates:[];
    const display=updates.length?`${greeting}\n\nHere’s what changed:\n${updates.map(update=>`• ${String(update?.title||'Update')}${update?.body?` — ${String(update.body)}`:''}`).join('\n')}`:greeting;
    const spoken=updates.length?`${greeting} Here are the priorities I found. ${updates.map(update=>`${String(update?.title||'Update')}. ${String(update?.body||'')}`).join(' ')}`:greeting;
    return {display,spoken,updates};
  }
  function presentIntro(){
    const intro=boot.intro&&typeof boot.intro==='object'?boot.intro:null;
    if(!intro||!String(intro.greeting||'').trim()){if(voiceOn)scheduleListening(80,'boot-no-intro');return;}
    const {display,spoken,updates}=introTexts(intro);if(welcome)welcome.hidden=true;
    const message=document.createElement('div');message.className='message assistant';message.dataset.agentIntro='v139';
    message.innerHTML='<div class="message-avatar" aria-hidden="true">S</div><div class="message-body"><div class="message-role">Stonefellow</div><div class="message-text"></div></div>';message.querySelector('.message-text').textContent=display;
    const actionable=updates.filter(update=>String(update?.target_url||'').trim()).slice(0,4);
    if(actionable.length){const actions=document.createElement('div');actions.className='message-actions';for(const update of actionable){const link=document.createElement('a');link.href=String(update.target_url);link.textContent=`Open ${String(update.title||'update').slice(0,80)}`;actions.appendChild(link);}message.querySelector('.message-body').appendChild(actions);}
    thread?.appendChild(message);if(thread)thread.scrollTop=thread.scrollHeight;
    if(voiceOn&&spoken)setTimeout(()=>void speakStandalone(spoken),100);else if(voiceOn)scheduleListening(80,'intro-ready');
  }
  async function waitForInitialConversationRestore(){
    const id=Number(cfg.initialConversationId||0);if(String(cfg.initialView||'chat')!=='chat'||id<1)return;
    const deadline=Date.now()+6000;while(Date.now()<deadline){const active=document.querySelector(`.chat-history-item.active[data-conversation-id="${id}"]`);const loading=thread?.querySelector('.typing');if(active&&!loading)return;await new Promise(resolve=>setTimeout(resolve,60));}
  }

  function installDebug(){
    if(new URLSearchParams(location.search).get('voice_debug')!=='1')return;
    const panel=document.createElement('aside');panel.id='stonefellowVoiceDebugV139';
    panel.style.cssText='position:fixed;right:12px;bottom:12px;z-index:2147483000;width:min(520px,calc(100vw - 24px));max-height:72vh;overflow:auto;background:#111;color:#eee;border:1px solid #444;border-radius:12px;padding:10px;font:12px/1.4 ui-monospace,SFMono-Regular,Consolas,monospace;box-shadow:0 16px 50px rgba(0,0,0,.45)';
    panel.innerHTML='<div style="display:flex;gap:8px;align-items:center"><strong style="flex:1">STONEFELLOW CHAT VOICE · v139 DIRECT</strong><button data-copy>COPY LOG</button></div><pre data-status style="white-space:pre-wrap"></pre><pre data-log style="white-space:pre-wrap;max-height:340px;overflow:auto;background:#080808;padding:8px"></pre>';
    document.body.appendChild(panel);
    const statusEl=panel.querySelector('[data-status]'),logEl=panel.querySelector('[data-log]');
    window.__STONEFELLOW_V139_RENDER=()=>{
      statusEl.textContent=[`build: ${BUILD}`,`voice: ${voiceOn?'ON':'OFF'}`,`recognizer ctor: ${typeof SpeechRecognitionCtor}`,`recognition starting: ${recognitionStarting}`,`recognition listening: ${recognitionListening}`,`processing: ${processing}`,`speaking: ${speaking}`,`start calls: ${proof.startCalls}`,`starts: ${proof.starts}`,`results: ${proof.results}`,`finals: ${proof.finals}`,`errors: ${proof.errors}`,`submits: ${proof.submits}`,`interruptions: ${proof.interruptions}`,`last transcript: ${proof.lastTranscript||'-'}`,`last error: ${proof.lastError||'-'}`,`user activation: ${navigator.userActivation?.isActive?'ACTIVE':'inactive'}`,`shared conversation controller loaded: ${!!window.StonefellowConversationVoiceV122}`].join('\n');
      logEl.textContent=events.slice(-70).map(e=>`${e.at} ${e.type} ${JSON.stringify(e.detail)}`).join('\n');logEl.scrollTop=logEl.scrollHeight;
    };
    panel.querySelector('[data-copy]').addEventListener('click',async()=>{window.__STONEFELLOW_V139_RENDER?.();try{await navigator.clipboard.writeText(`${statusEl.textContent}\n\n${logEl.textContent}`);}catch(error){}});
    window.__STONEFELLOW_V139_RENDER();
  }
  function renderDebug(){try{window.__STONEFELLOW_V139_RENDER?.();}catch(error){}}

  document.addEventListener('click',event=>{
    const link=event.target.closest?.('a[href*="/admin/stems.php"],a[href*="/video-editor.php"]');if(!link)return;
    try{const target=new URL(link.href,location.href);if(target.origin!==location.origin)return;if(voiceOn)target.searchParams.set('voice','1');const cid=activeConversationId();if(cid>0)target.searchParams.set('conversation_id',String(cid));link.href=target.toString();}catch(error){}
  },true);

  syncButton();installDebug();syncConversation(lastConversationId);
  if(button.disabled){voiceOn=false;writeMode();setAgentState('error','Voice recognition is not available in this browser.');}
  else setAgentState('idle',voiceOn?'Voice conversation on':'');
  setTimeout(()=>void waitForInitialConversationRestore().then(presentIntro),80);
  window.STONEFELLOW_CHAT_CONTINUITY_V139={isVoice:()=>voiceOn,conversationId:activeConversationId,startListening,interrupt:interruptResponse};
  log('READY',{voiceOn,ctor:typeof SpeechRecognitionCtor});

  window.addEventListener('storage',event=>{if(event.key!==MODE_KEY)return;const next=event.newValue==='1';if(next!==voiceOn)setVoice(next,{persist:false,immediate:false});});
  window.addEventListener('pagehide',()=>{clearRestart();stopBarge();stopRecognition('off',true);try{premium?.stop?.();}catch(error){}if(activeRequest&&!activeRequest.controller.signal.aborted)activeRequest.controller.abort();if(window.fetch===routedFetch)window.fetch=previousFetch;delete document.body.dataset.stonefellowAgentState;},{once:true});
})();