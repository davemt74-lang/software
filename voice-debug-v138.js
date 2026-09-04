(() => {
  'use strict';
  const BUILD='voice-debug-v138-20260826';
  if(new URLSearchParams(location.search).get('voice_debug')!=='1')return;

  const cfg=window.STONEFELLOW_CHAT||{};
  const userId=Number(cfg.userId||0);
  const voiceKey=`stonefellow:voice-mode:${userId}`;
  const sessionKey=`stonefellow:voice-session:${userId}`;
  const events=[];
  let permission='…';
  let input={type:'idle',live:false,rms:0,peak:0,device:'',percent:0,contextState:'none'};
  let micTestStream=null,micTestContext=null,micTestTimer=0,recognitionTest=null,testTimer=0;

  const stamp=()=>new Date().toLocaleTimeString([], {hour12:false,hour:'2-digit',minute:'2-digit',second:'2-digit'});
  const safe=value=>{try{return typeof value==='string'?value:JSON.stringify(value);}catch(error){return String(value);}};
  const esc=value=>String(value??'').replace(/[&<>\"]/g,ch=>({'&':'&amp;','<':'&lt;','>':'&gt;','\"':'&quot;'}[ch]));

  function push(type,detail=''){
    events.push({at:stamp(),type:String(type||'event'),detail:safe(detail||'')});
    if(events.length>220)events.splice(0,events.length-220);
    renderLog();
  }
  function currentSession(){try{return JSON.parse(localStorage.getItem(sessionKey)||'null')||{};}catch(error){return {};}}
  function currentScripts(){
    return [...document.scripts].map(s=>s.src).filter(src=>/conversation-voice|editor-voice-barge|chat-conversation|voice-debug/.test(src))
      .map(src=>{try{const u=new URL(src);return `${u.pathname}${u.search}`;}catch(error){return src;}}).join('\n');
  }

  const panel=document.createElement('aside');
  panel.id='stonefellowVoiceDebugV138';
  panel.innerHTML=`
    <style>
      #stonefellowVoiceDebugV138{position:fixed;right:12px;bottom:12px;z-index:2147483000;width:min(520px,calc(100vw - 24px));max-height:min(80vh,780px);overflow:hidden;background:#111;color:#f4f4f4;border:1px solid #444;border-radius:12px;box-shadow:0 18px 60px rgba(0,0,0,.42);font:12px/1.35 ui-monospace,SFMono-Regular,Consolas,monospace}
      #stonefellowVoiceDebugV138 *{box-sizing:border-box}
      #stonefellowVoiceDebugV138 header{display:flex;align-items:center;gap:8px;padding:9px 10px;border-bottom:1px solid #333;background:#171717}
      #stonefellowVoiceDebugV138 header strong{flex:1;font-size:12px}
      #stonefellowVoiceDebugV138 button{border:1px solid #555;border-radius:7px;background:#222;color:#fff;padding:5px 7px;font:inherit;cursor:pointer}
      #stonefellowVoiceDebugV138 .body{max-height:calc(min(80vh,780px) - 42px);overflow:auto;padding:9px 10px}
      #stonefellowVoiceDebugV138 .grid{display:grid;grid-template-columns:165px 1fr;gap:3px 8px;margin-bottom:8px}
      #stonefellowVoiceDebugV138 .key{color:#9aa0a6}.value{word-break:break-word}
      #stonefellowVoiceDebugV138 .meter{height:12px;border:1px solid #444;border-radius:6px;overflow:hidden;background:#060606;margin:5px 0 10px}
      #stonefellowVoiceDebugV138 .meter>i{display:block;height:100%;width:0%;background:currentColor;transition:width .05s linear}
      #stonefellowVoiceDebugV138 .actions{display:flex;gap:6px;flex-wrap:wrap;margin:8px 0}
      #stonefellowVoiceDebugV138 pre{margin:7px 0 0;padding:8px;background:#080808;border:1px solid #292929;border-radius:8px;white-space:pre-wrap;word-break:break-word;max-height:320px;overflow:auto;color:#d8f3dc}
      #stonefellowVoiceDebugV138 .good{color:#8ef0a7}.bad{color:#ff8a8a}.warn{color:#ffd27a}
    </style>
    <header><strong>STONEFELLOW VOICE DEBUG · v138</strong><button data-copy>COPY LOG</button><button data-close>×</button></header>
    <div class="body">
      <div class="grid" data-status></div>
      <div><span class="key">LIVE MIC INPUT</span> <strong data-level-text>0%</strong></div>
      <div class="meter"><i data-level-bar></i></div>
      <div class="actions"><button data-mic>TEST MIC + LEVEL</button><button data-sr>TEST SPEECHRECOGNITION</button><button data-clear>CLEAR LOG</button></div>
      <pre data-log></pre>
    </div>`;
  document.body.appendChild(panel);
  const statusEl=panel.querySelector('[data-status]');
  const logEl=panel.querySelector('[data-log]');
  const levelText=panel.querySelector('[data-level-text]');
  const levelBar=panel.querySelector('[data-level-bar]');

  function row(key,value,cls=''){return `<div class="key">${esc(key)}</div><div class="value ${cls}">${value}</div>`;}
  function renderStatus(){
    const session=currentSession(),health=session.health||{};
    const mode=localStorage.getItem(voiceKey)==='1';
    const controller=window.StonefellowConversationVoiceV122||{};
    const runtime=document.querySelector('[data-stonefellow-build]')?.getAttribute('data-stonefellow-build')||'missing';
    const premium=window.STONEFELLOW_PREMIUM_VOICE_V122||{};
    statusEl.innerHTML=
      row('runtime build',esc(runtime))+
      row('control build',esc(controller.controlBuild||'missing'),String(controller.controlBuild||'').includes('v138')?'good':'warn')+
      row('voice mode',mode?'ON':'OFF',mode?'good':'')+
      row('body state',esc(document.body.dataset.stonefellowAgentState||'none'))+
      row('session state',esc(session.state||'none'))+
      row('recognizer entries',health.recognitionEntries??0)+
      row('recognition attempts',health.recognitionAttempts??0,(health.recognitionAttempts||0)>0?'good':'')+
      row('recognition starts',health.recognitionStarts??0,(health.recognitionStarts||0)>0?'good':'')+
      row('recognition errors',health.recognitionErrors??0,(health.recognitionErrors||0)>0?'bad':'')+
      row('constructor errors',health.recognitionConstructorErrors??0,(health.recognitionConstructorErrors||0)>0?'bad':'')+
      row('config errors',health.recognitionConfigErrors??0,(health.recognitionConfigErrors||0)>0?'bad':'')+
      row('start throws',health.recognitionStartThrows??0,(health.recognitionStartThrows||0)>0?'bad':'')+
      row('start timeouts',health.recognitionStartTimeouts??0,(health.recognitionStartTimeouts||0)>0?'bad':'')+
      row('mic monitor opens',health.micPreflights??0)+
      row('mic errors',health.micPreflightErrors??0,(health.micPreflightErrors||0)>0?'bad':'')+
      row('mic track live',health.micTrackLive?'yes':'no',health.micTrackLive?'good':'')+
      row('audio context',esc(health.micContextState||input.contextState||'none'))+
      row('mic last RMS',Number(health.micLastRms??input.rms??0).toFixed(5))+
      row('mic peak RMS',Number(health.micPeakRms??input.peak??0).toFixed(5))+
      row('silent frames',health.micSilentFrames??0)+
      row('mic device',esc(health.micDevice||input.device||'n/a'))+
      row('accepted transcripts',health.acceptedTranscripts??0)+
      row('user activation now',navigator.userActivation?.isActive?'active':'inactive',navigator.userActivation?.isActive?'good':'')+
      row('secure context',window.isSecureContext?'yes':'NO',window.isSecureContext?'good':'bad')+
      row('mediaDevices',navigator.mediaDevices?.getUserMedia?'yes':'NO',navigator.mediaDevices?.getUserMedia?'good':'bad')+
      row('SpeechRecognition ctor',typeof (window.SpeechRecognition||window.webkitSpeechRecognition))+
      row('mic permission',esc(permission))+
      row('premium model',esc(premium.modelId||'unknown'))+
      row('first audio ms',premium.lastFirstAudioMs??'n/a')+
      row('loaded voice assets',`<pre style="margin:2px 0;max-height:100px">${esc(currentScripts())}</pre>`);
    const pct=Math.max(0,Math.min(100,Number(input.percent||Math.round(Number(input.rms||0)*650))));
    levelText.textContent=`${pct}% · ${input.type}${input.live?' · LIVE':''} · ctx ${input.contextState||health.micContextState||'none'}`;
    levelBar.style.width=`${pct}%`;
  }
  function renderLog(){
    if(!logEl)return;
    logEl.textContent=events.slice(-75).map(item=>`${item.at}  ${item.type}${item.detail?` · ${item.detail}`:''}`).join('\n')||'No events yet.';
    logEl.scrollTop=logEl.scrollHeight;
  }

  window.addEventListener('stonefellow:voice-session',event=>{push('VOICE_SESSION',{state:event.detail?.state,enabled:event.detail?.enabled,health:event.detail?.health});renderStatus();});
  window.addEventListener('stonefellow:voice-input',event=>{
    const d=event.detail||{};input={...input,...d,type:String(d.type||input.type),percent:Number(d.percent??input.percent??0),contextState:String(d.contextState||d.state||input.contextState||'none')};
    push(`MIC_${String(input.type).toUpperCase()}`,d);renderStatus();
  });
  for(const name of ['enter','blocked','constructor-error','config-error','gate','attempt','started','throw','timeout','error','end']){
    window.addEventListener(`stonefellow:voice-recognizer-${name}`,event=>push(`RECOGNIZER_${name.toUpperCase().replaceAll('-','_')}`,event.detail||{}));
  }
  window.addEventListener('stonefellow:chat-voice-boot',event=>push('CHAT_VOICE_BOOT',event.detail||{}));
  window.addEventListener('stonefellow:voice-latency',event=>push('VOICE_LATENCY',event.detail||{}));

  function stopMicTest(){
    if(micTestTimer){clearInterval(micTestTimer);micTestTimer=0;}
    if(micTestContext){try{void micTestContext.close();}catch(error){}}micTestContext=null;
    try{micTestStream?.getTracks?.().forEach(track=>track.stop());}catch(error){}micTestStream=null;
  }
  async function testMic(){
    push('TEST_MIC','requesting getUserMedia + analyser');stopMicTest();
    try{
      micTestStream=await navigator.mediaDevices.getUserMedia({audio:true,video:false});
      const track=micTestStream.getAudioTracks?.()[0]||null;
      push('TEST_MIC_OK',{label:track?.label||'',readyState:track?.readyState||'',enabled:track?.enabled,muted:track?.muted,settings:track?.getSettings?.()||{}});
      const Ctx=window.AudioContext||window.webkitAudioContext;
      if(!Ctx){push('TEST_MIC_CONTEXT','AudioContext unavailable');return;}
      micTestContext=new Ctx();push('TEST_MIC_CONTEXT',{state:micTestContext.state});
      if(micTestContext.state==='suspended'){try{await micTestContext.resume();}catch(error){}push('TEST_MIC_CONTEXT_AFTER_RESUME',{state:micTestContext.state});}
      const analyser=micTestContext.createAnalyser();analyser.fftSize=512;
      const source=micTestContext.createMediaStreamSource(micTestStream);source.connect(analyser);const samples=new Uint8Array(analyser.fftSize);
      let peak=0;micTestTimer=setInterval(()=>{let sum=0;analyser.getByteTimeDomainData(samples);for(const value of samples){const n=(value-128)/128;sum+=n*n;}const rms=Math.sqrt(sum/samples.length);peak=Math.max(peak,rms);push('TEST_MIC_LEVEL',{rms,peak,percent:Math.min(100,Math.round(rms*650)),contextState:micTestContext?.state});},120);
      setTimeout(()=>{stopMicTest();push('TEST_MIC','released');},3500);
    }catch(error){push('TEST_MIC_ERROR',`${error?.name||'Error'}: ${error?.message||error}`);stopMicTest();}
  }
  function testSpeechRecognition(){
    const Ctor=window.SpeechRecognition||window.webkitSpeechRecognition;
    if(typeof Ctor!=='function'){push('TEST_SR','constructor unavailable');return;}
    if(localStorage.getItem(voiceKey)==='1'){push('TEST_SR','Turn Stonefellow LISTEN OFF first.');return;}
    try{recognitionTest?.abort?.();}catch(error){}
    if(testTimer)clearTimeout(testTimer);
    let sr;
    try{sr=new Ctor();push('TEST_SR_CONSTRUCTED',{ctor:Ctor.name||'anonymous'});}catch(error){push('TEST_SR_CONSTRUCTOR_ERROR',`${error?.name||'Error'}: ${error?.message||error}`);return;}
    recognitionTest=sr;
    try{sr.lang=document.documentElement.lang||'en-US';sr.continuous=false;sr.interimResults=true;}catch(error){push('TEST_SR_CONFIG_ERROR',`${error?.name||'Error'}: ${error?.message||error}`);return;}
    ['start','audiostart','soundstart','speechstart','speechend','soundend','audioend','end'].forEach(name=>sr.addEventListener(name,()=>push(`TEST_SR_${name.toUpperCase()}`)));
    sr.addEventListener('error',event=>push('TEST_SR_ERROR',{error:event.error,message:event.message||''}));
    sr.addEventListener('result',event=>{const rows=[];for(let i=0;i<event.results.length;i+=1){const r=event.results[i];rows.push({final:!!r.isFinal,text:String(r?.[0]?.transcript||''),confidence:Number(r?.[0]?.confidence||0)});}push('TEST_SR_RESULT',rows);});
    push('TEST_SR','calling native start() synchronously — speak now');
    push('TEST_SR_USER_ACTIVATION',{active:!!navigator.userActivation?.isActive,hasBeenActive:!!navigator.userActivation?.hasBeenActive});
    try{sr.start();testTimer=setTimeout(()=>{try{sr.abort();}catch(error){}push('TEST_SR','8s watchdog abort');},8000);}catch(error){push('TEST_SR_START_THROW',`${error?.name||'Error'}: ${error?.message||error}`);}
  }

  panel.querySelector('[data-mic]').addEventListener('click',()=>void testMic());
  panel.querySelector('[data-sr]').addEventListener('click',testSpeechRecognition);
  panel.querySelector('[data-clear]').addEventListener('click',()=>{events.length=0;renderLog();});
  panel.querySelector('[data-close]').addEventListener('click',()=>panel.remove());
  panel.querySelector('[data-copy]').addEventListener('click',async()=>{
    const text=`Stonefellow Voice Debug ${BUILD}\n\nSTATUS\n${statusEl.innerText}\nLIVE MIC INPUT\n${levelText.innerText}\n\nLOG\n${events.map(item=>`${item.at} ${item.type} ${item.detail}`).join('\n')}`;
    try{await navigator.clipboard.writeText(text);push('DEBUG','copied to clipboard');}catch(error){push('DEBUG','clipboard copy failed');}
  });

  if(navigator.permissions?.query)navigator.permissions.query({name:'microphone'}).then(result=>{permission=result.state;renderStatus();}).catch(()=>{permission='unknown';renderStatus();});
  renderLog();push('DEBUG_READY',{build:BUILD,userId,href:location.href});renderStatus();
  const poll=setInterval(renderStatus,700);
  window.addEventListener('pagehide',()=>{clearInterval(poll);if(testTimer)clearTimeout(testTimer);try{recognitionTest?.abort?.();}catch(error){}stopMicTest();},{once:true});
  window.STONEFELLOW_VOICE_DEBUG_V138={build:BUILD,events,testMic,testSpeechRecognition};
})();