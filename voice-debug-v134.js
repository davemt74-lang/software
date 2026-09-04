(() => {
  'use strict';

  const BUILD='voice-debug-v134-20260826';
  const params=new URLSearchParams(location.search);
  if(params.get('voice_debug')!=='1')return;

  const cfg=window.STONEFELLOW_CHAT||{};
  const userId=Number(cfg.userId||0);
  const voiceKey=`stonefellow:voice-mode:${userId}`;
  const sessionKey=`stonefellow:voice-session:${userId}`;
  const events=[];
  let recognitionTest=null;
  let recognitionTestTimer=0;
  let micTestStream=null;

  const stamp=()=>new Date().toLocaleTimeString([], {hour12:false,hour:'2-digit',minute:'2-digit',second:'2-digit'});
  const safe=value=>{try{return typeof value==='string'?value:JSON.stringify(value);}catch(error){return String(value);}};

  function push(type,detail=''){
    const row={at:stamp(),type:String(type||'event'),detail:safe(detail||'')};
    events.push(row);if(events.length>120)events.splice(0,events.length-120);
    renderLog();
  }

  const panel=document.createElement('aside');
  panel.id='stonefellowVoiceDebugV134';
  panel.innerHTML=`
    <style>
      #stonefellowVoiceDebugV134{position:fixed;right:12px;bottom:12px;z-index:2147483000;width:min(470px,calc(100vw - 24px));max-height:min(72vh,680px);overflow:hidden;background:#111;color:#f4f4f4;border:1px solid #444;border-radius:12px;box-shadow:0 18px 60px rgba(0,0,0,.42);font:12px/1.35 ui-monospace,SFMono-Regular,Consolas,monospace}
      #stonefellowVoiceDebugV134 *{box-sizing:border-box}
      #stonefellowVoiceDebugV134 header{display:flex;align-items:center;gap:8px;padding:9px 10px;border-bottom:1px solid #333;background:#171717}
      #stonefellowVoiceDebugV134 header strong{flex:1;font-size:12px}
      #stonefellowVoiceDebugV134 button{border:1px solid #555;border-radius:7px;background:#222;color:#fff;padding:5px 7px;font:inherit;cursor:pointer}
      #stonefellowVoiceDebugV134 button:hover{background:#303030}
      #stonefellowVoiceDebugV134 .vdbg-body{max-height:calc(min(72vh,680px) - 42px);overflow:auto;padding:9px 10px}
      #stonefellowVoiceDebugV134 .vdbg-grid{display:grid;grid-template-columns:145px 1fr;gap:3px 8px;margin-bottom:9px}
      #stonefellowVoiceDebugV134 .vdbg-key{color:#9aa0a6}.vdbg-value{word-break:break-word;color:#fff}
      #stonefellowVoiceDebugV134 .vdbg-actions{display:flex;gap:6px;flex-wrap:wrap;margin:8px 0}
      #stonefellowVoiceDebugV134 pre{margin:7px 0 0;padding:8px;background:#080808;border:1px solid #292929;border-radius:8px;white-space:pre-wrap;word-break:break-word;max-height:280px;overflow:auto;color:#d8f3dc}
      #stonefellowVoiceDebugV134 .bad{color:#ff8a8a}.good{color:#8ef0a7}.warn{color:#ffd27a}
    </style>
    <header><strong>VOICE DEBUG · v134</strong><button data-vdbg-copy>COPY LOG</button><button data-vdbg-close>×</button></header>
    <div class="vdbg-body">
      <div class="vdbg-grid" data-vdbg-status></div>
      <div class="vdbg-actions"><button data-vdbg-mic>TEST MIC</button><button data-vdbg-sr>TEST SPEECHRECOGNITION</button><button data-vdbg-clear>CLEAR LOG</button></div>
      <pre data-vdbg-log></pre>
    </div>`;
  document.body.appendChild(panel);

  const statusEl=panel.querySelector('[data-vdbg-status]');
  const logEl=panel.querySelector('[data-vdbg-log]');

  function row(key,value,cls=''){
    return `<div class="vdbg-key">${key}</div><div class="vdbg-value ${cls}">${String(value??'')}</div>`;
  }

  function currentSession(){
    try{return JSON.parse(localStorage.getItem(sessionKey)||'null')||{};}catch(error){return {};}
  }

  function currentScripts(){
    return [...document.scripts].map(s=>s.src).filter(src=>/conversation-voice|editor-voice-barge|chat-conversation|voice-debug/.test(src)).map(src=>{try{const u=new URL(src);return `${u.pathname}${u.search}`;}catch(error){return src;}}).join('\n');
  }

  async function permissionState(){
    if(!navigator.permissions?.query)return 'permissions API unavailable';
    try{return (await navigator.permissions.query({name:'microphone'})).state;}catch(error){return 'unknown';}
  }

  function renderStatus(permission='…'){
    const session=currentSession();
    const health=session.health||{};
    const mode=localStorage.getItem(voiceKey)==='1';
    const bodyState=document.body.dataset.stonefellowAgentState||'none';
    const runtime=document.querySelector('[data-stonefellow-build]')?.getAttribute('data-stonefellow-build')||'missing';
    const controller=window.StonefellowConversationVoiceV122?.build||'missing';
    const premium=window.STONEFELLOW_PREMIUM_VOICE_V122||{};
    const wrapper=window.STONEFELLOW_RECOGNIZER_RECOVERY_V133||window.STONEFELLOW_INTERRUPT_RECOVERY_V132||null;
    statusEl.innerHTML=
      row('runtime build',runtime,runtime.includes('v134')?'good':'warn')+
      row('controller build',controller,controller.includes('v134')?'good':'warn')+
      row('voice mode',mode?'ON':'OFF',mode?'good':'')+
      row('body state',bodyState,bodyState==='listening'?'good':'')+
      row('session state',session.state||'none')+
      row('recognition starts',health.recognitionStarts??0)+
      row('recognition errors',health.recognitionErrors??0,(health.recognitionErrors||0)>0?'bad':'')+
      row('accepted transcripts',health.acceptedTranscripts??0)+
      row('interruptions',health.interruptions??0)+
      row('hard resets',window.STONEFELLOW_VOICE_DEBUG_HARD_RESETS||0)+
      row('stack wrapper',wrapper?'PRESENT':'none',wrapper?'bad':'good')+
      row('secure context',window.isSecureContext?'yes':'NO',window.isSecureContext?'good':'bad')+
      row('mediaDevices',navigator.mediaDevices?.getUserMedia?'yes':'NO',navigator.mediaDevices?.getUserMedia?'good':'bad')+
      row('SpeechRecognition',window.SpeechRecognition||window.webkitSpeechRecognition?'yes':'NO',window.SpeechRecognition||window.webkitSpeechRecognition?'good':'bad')+
      row('mic permission',permission)+
      row('premium model',premium.modelId||'unknown')+
      row('first audio ms',premium.lastFirstAudioMs??'n/a')+
      row('loaded voice assets',`<pre style="margin:2px 0;max-height:92px">${currentScripts().replace(/</g,'&lt;')}</pre>`);
  }

  function renderLog(){
    if(!logEl)return;
    logEl.textContent=events.slice(-45).map(item=>`${item.at}  ${item.type}${item.detail?` · ${item.detail}`:''}`).join('\n')||'No events yet.';
    logEl.scrollTop=logEl.scrollHeight;
  }

  async function testMic(){
    push('MIC_TEST','requesting getUserMedia');
    try{
      micTestStream?.getTracks?.().forEach(track=>track.stop());
      micTestStream=await navigator.mediaDevices.getUserMedia({audio:true,video:false});
      const track=micTestStream.getAudioTracks?.()[0]||null;
      push('MIC_OK',{label:track?.label||'',readyState:track?.readyState||'',enabled:track?.enabled,muted:track?.muted,settings:track?.getSettings?.()||{}});
      setTimeout(()=>{try{micTestStream?.getTracks?.().forEach(t=>t.stop());}catch(error){}micTestStream=null;push('MIC_TEST','released');},1200);
    }catch(error){push('MIC_ERROR',`${error?.name||'Error'}: ${error?.message||error}`);}
  }

  function testSpeechRecognition(){
    const Ctor=window.SpeechRecognition||window.webkitSpeechRecognition;
    if(!Ctor){push('SR_TEST','SpeechRecognition unavailable');return;}
    if(localStorage.getItem(voiceKey)==='1'){
      push('SR_TEST','Turn Stonefellow LISTEN OFF first, then press TEST SPEECHRECOGNITION again.');return;
    }
    try{recognitionTest?.abort?.();}catch(error){}
    if(recognitionTestTimer)clearTimeout(recognitionTestTimer);
    const sr=new Ctor();recognitionTest=sr;sr.lang=document.documentElement.lang||'en-US';sr.continuous=false;sr.interimResults=true;
    ['start','audiostart','soundstart','speechstart','speechend','soundend','audioend','end'].forEach(name=>sr.addEventListener(name,()=>push(`SR_${name.toUpperCase()}`)));
    sr.addEventListener('error',event=>push('SR_ERROR',{error:event.error,message:event.message||''}));
    sr.addEventListener('result',event=>{
      const rows=[];
      for(let i=0;i<event.results.length;i+=1){const r=event.results[i];rows.push({final:!!r.isFinal,text:String(r?.[0]?.transcript||''),confidence:Number(r?.[0]?.confidence||0)});}
      push('SR_RESULT',rows);
    });
    push('SR_TEST','calling native start() — speak now');
    try{sr.start();recognitionTestTimer=setTimeout(()=>{try{sr.abort();}catch(error){}push('SR_TEST','8s watchdog abort');},8000);}
    catch(error){push('SR_START_THROW',`${error?.name||'Error'}: ${error?.message||error}`);}
  }

  window.addEventListener('stonefellow:voice-session',event=>{push('VOICE_SESSION',{state:event.detail?.state,enabled:event.detail?.enabled,health:event.detail?.health});renderStatus();});
  window.addEventListener('stonefellow:voice-latency',event=>push('VOICE_LATENCY',event.detail||{}));
  window.addEventListener('stonefellow:voice-interrupt-resumed',event=>push('INTERRUPT_RESUMED',event.detail||{}));
  window.addEventListener('stonefellow:voice-recognizer-hard-reset',event=>{window.STONEFELLOW_VOICE_DEBUG_HARD_RESETS=(window.STONEFELLOW_VOICE_DEBUG_HARD_RESETS||0)+1;push('HARD_RESET',event.detail||{});});
  window.addEventListener('stonefellow:voice-recognizer-failed',event=>push('RECOGNIZER_FAILED',event.detail||{}));
  const observer=new MutationObserver(()=>renderStatus());observer.observe(document.body,{attributes:true,attributeFilter:['data-stonefellow-agent-state']});

  panel.querySelector('[data-vdbg-mic]').addEventListener('click',()=>void testMic());
  panel.querySelector('[data-vdbg-sr]').addEventListener('click',testSpeechRecognition);
  panel.querySelector('[data-vdbg-clear]').addEventListener('click',()=>{events.length=0;renderLog();});
  panel.querySelector('[data-vdbg-close]').addEventListener('click',()=>panel.remove());
  panel.querySelector('[data-vdbg-copy]').addEventListener('click',async()=>{
    const text=`Stonefellow Voice Debug ${BUILD}\n\nSTATUS\n${statusEl.innerText}\n\nLOG\n${events.map(item=>`${item.at} ${item.type} ${item.detail}`).join('\n')}`;
    try{await navigator.clipboard.writeText(text);push('DEBUG','copied to clipboard');}catch(error){push('DEBUG','clipboard copy failed');}
  });

  void permissionState().then(permission=>renderStatus(permission));
  renderLog();push('DEBUG_READY',{build:BUILD,userId,href:location.href});
  const poll=setInterval(()=>renderStatus(),750);
  window.addEventListener('pagehide',()=>{clearInterval(poll);observer.disconnect();if(recognitionTestTimer)clearTimeout(recognitionTestTimer);try{recognitionTest?.abort?.();}catch(error){}try{micTestStream?.getTracks?.().forEach(track=>track.stop());}catch(error){}},{once:true});
  window.STONEFELLOW_VOICE_DEBUG_V134={build:BUILD,events,testMic,testSpeechRecognition};
})();
