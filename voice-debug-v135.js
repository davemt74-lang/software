(() => {
  'use strict';
  if(new URLSearchParams(location.search).get('voice_debug')!=='1')return;
  const names=['attempt','started','gate','throw','timeout','error','end'];
  const stamp=()=>new Date().toLocaleTimeString([], {hour12:false,hour:'2-digit',minute:'2-digit',second:'2-digit'});
  const safe=value=>{try{return typeof value==='string'?value:JSON.stringify(value);}catch(error){return String(value);}};
  function appendLabel(label,detail){
    const debug=window.STONEFELLOW_VOICE_DEBUG_V134;
    if(!debug?.events)return;
    debug.events.push({at:stamp(),type:String(label),detail:safe(detail||{})});
    if(debug.events.length>120)debug.events.splice(0,debug.events.length-120);
    const log=document.querySelector('#stonefellowVoiceDebugV134 [data-vdbg-log]');
    if(log){log.textContent=debug.events.slice(-45).map(item=>`${item.at}  ${item.type}${item.detail?` · ${item.detail}`:''}`).join('\n');log.scrollTop=log.scrollHeight;}
  }
  function append(type,detail){appendLabel(`RECOGNIZER_${String(type).toUpperCase()}`,detail);}
  names.forEach(name=>window.addEventListener(`stonefellow:voice-recognizer-${name}`,event=>append(name,event.detail||{})));
  window.addEventListener('stonefellow:chat-voice-boot',event=>appendLabel('CHAT_VOICE_BOOT',event.detail||{}));
  window.STONEFELLOW_VOICE_DEBUG_V135={build:'voice-recognition-v136-20260826',events:names.slice(),bootEvent:'CHAT_VOICE_BOOT'};
})();