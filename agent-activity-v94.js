(() => {
  'use strict';
  const cfg=window.STONEFELLOW_ACTIVITY||{};if(!cfg.endpoint||!cfg.csrf)return;
  let lastMeaningful=Date.now(),hiddenAt=document.hidden?Date.now():0,lastState='',taskTitle=String(cfg.taskTitle||''),taskKey=String(cfg.taskKey||'');
  let timer=0,sending=false,lastSentContext='',closed=false,activitySequence=0,clockOffset=0,clockAnchored=false;
  const pageCsrf=String(cfg.csrf),pageEndpoint=String(cfg.endpoint),pageOwner=Number(window.STONEFELLOW_CHAT?.userId||0);
  const currentBinding=()=>window.STONEFELLOW_ACTIVITY===cfg&&String(cfg.csrf)===pageCsrf&&String(cfg.endpoint)===pageEndpoint
    &&(!pageOwner||Number(window.STONEFELLOW_CHAT?.userId||0)===pageOwner);
  // A document-scoped key distinguishes a late background heartbeat/pagehide
  // from the tab that currently owns the account's foreground activity row.
  const activityTab=(()=>{try{const bytes=new Uint8Array(16);crypto.getRandomValues(bytes);return [...bytes].map(byte=>byte.toString(16).padStart(2,'0')).join('');}catch(_error){return '';}})();
  const observation=()=>({activity_observed_at:Math.round(Date.now()+clockOffset),activity_sequence:++activitySequence});
  function acceptClock(data){
    if(clockAnchored||!currentBinding()||!Number.isSafeInteger(data?.server_time_ms)||!Number.isSafeInteger(data?.owner_user_id)
        ||data.owner_user_id<1||(pageOwner&&data.owner_user_id!==pageOwner))return;
    const offset=data.server_time_ms-Date.now();
    if(Math.abs(offset)>86400000)return;
    // Ordinary network latency must not give otherwise synchronized tabs
    // different clocks. Material skew is corrected from this bound response.
    clockOffset=Math.abs(offset)>5000?offset:0;clockAnchored=true;
    if(clockOffset!==0)lastSentContext='';
  }
  const linkedConversation=()=>Number(window.STONEFELLOW_CHAT_CONTINUITY?.conversationId?.()||cfg.conversationId||new URLSearchParams(location.search).get('conversation_id')||0);
  function context(){
    const focused=!document.hidden&&document.hasFocus();
    const value={track_id:Number(cfg.trackId||0),project_id:Number(cfg.projectId||0),conversation_id:linkedConversation(),task_title:taskTitle,task_kind:cfg.surface||'chat',task_key:taskKey,path:location.pathname+location.search,visible:!document.hidden};
    if(activityTab){value.activity_tab=activityTab;value.focused=focused;}
    const canonical=window.STONEFELLOW_CHAT_CONTINUITY_V87||window.STONEFELLOW_CHAT_CONTINUITY;
    const facade=window.STONEFELLOW_CHAT_CONTINUITY;
    const input=document.getElementById('chatInput');
    if(activityTab&&(cfg.surface||'chat')==='chat'&&value.conversation_id>0&&input&&typeof canonical?.isReady==='function'){
      const voiceState=String(document.body?.dataset.stonefellowAgentState||'');
      value.chat_focused=focused;
      value.chat_busy=!canonical.isReady()||!!canonical.isRestoring?.()||!!canonical.isBusy?.()||!!facade?.isBusy?.()
        ||String(input.value||'').trim()!==''||['thinking','processing','speaking'].includes(voiceState);
    }
    return value;
  }
  const activeMedia=()=>{if(!['stem','video'].includes(String(cfg.surface||'')))return false;return [...document.querySelectorAll('audio,video')].some(el=>!el.paused&&!el.ended&&el.readyState>1&&!el.closest('.chat-media-studio'));};
  const recording=()=>!!document.querySelector('.recording,[data-recording="true"],[data-live-recording="true"]');
  function classify(){const now=Date.now(),elapsed=(now-lastMeaningful)/1000;if(recording()||activeMedia())return 'working';if(document.hidden){const hidden=(now-(hiddenAt||now))/1000;return hidden>120?'idle':'paused';}if(elapsed<=120)return 'working';if(elapsed<=480)return 'paused';return 'idle';}
  async function heartbeat(reason='timer',force=false){if(closed||!currentBinding())return;const state=classify();if(sending)return;if(!force&&state===lastState&&reason!=='timer')return;sending=true;const sentContext=context();const sentKey=JSON.stringify(sentContext);lastSentContext=sentKey;try{const r=await fetch(pageEndpoint,{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json'},body:JSON.stringify({action:'heartbeat',csrf_token:pageCsrf,surface:cfg.surface||'chat',state,reason,context:{...sentContext,...observation()}})});const d=await r.json().catch(()=>null);if(r.ok&&d?.ok&&pageOwner&&Number.isSafeInteger(d.owner_user_id)&&d.owner_user_id!==pageOwner){closed=true;return;}if(r.ok&&d?.ok&&currentBinding()){acceptClock(d);if(lastState&&lastState!==state)document.dispatchEvent(new CustomEvent('stonefellow:proactive-refresh',{detail:{activity:d.activity}}));lastState=state;document.documentElement.dataset.agentActivity=state;}}catch(e){}finally{sending=false;if(!closed&&currentBinding()&&(JSON.stringify(context())!==sentKey||lastSentContext===''))queueMicrotask(()=>heartbeat('availability_changed',true));}}
  function meaningful(reason='interaction'){lastMeaningful=Date.now();if(document.hidden)return;const prior=lastState;lastState='';heartbeat(reason,true);if(prior==='idle'||prior==='paused')document.dispatchEvent(new CustomEvent('stonefellow:proactive-refresh'));}
  ['pointerdown','keydown','input','change','submit','dragstart','drop'].forEach(type=>document.addEventListener(type,e=>{if(type==='keydown'&&['Shift','Control','Alt','Meta'].includes(e.key))return;meaningful(type);},{capture:true,passive:type==='pointerdown'}));
  document.addEventListener('stonefellow:task-start',e=>{taskTitle=String(e.detail?.title||taskTitle);taskKey=String(e.detail?.key||taskKey);meaningful('task_start');});
  document.addEventListener('visibilitychange',()=>{hiddenAt=document.hidden?Date.now():0;heartbeat(document.hidden?'hidden':'visible',true);});
  window.addEventListener('focus',()=>heartbeat('focus',true));
  window.addEventListener('blur',()=>heartbeat('blur',true));
  window.addEventListener('pagehide',()=>{const valid=!closed&&currentBinding();closed=true;if(!valid)return;lastMeaningful=0;const departed={...context(),...observation(),visible:false,focused:false,chat_focused:false,chat_busy:true};const form=new FormData();form.append('action','heartbeat');form.append('csrf_token',String(cfg.csrf));form.append('surface',String(cfg.surface||'chat'));form.append('state','idle');form.append('reason','pagehide');form.append('context',JSON.stringify(departed));try{navigator.sendBeacon?.(cfg.endpoint,form);}catch(e){}});
  window.addEventListener('pageshow',()=>{closed=false;void heartbeat('pageshow',true);});
  window.StonefellowAgentActivity={markTask:(title,key='')=>{taskTitle=String(title||taskTitle);taskKey=String(key||taskKey);meaningful('task_mark');},snapshot:()=>({state:classify(),taskTitle,taskKey})};
  timer=setInterval(()=>heartbeat('timer',true),30000);setTimeout(()=>heartbeat('load',true),250);
  // Only semantic availability changes produce an extra request; this is not
  // another inference loop and does not turn mouse movement into Chat activity.
  if((cfg.surface||'chat')==='chat')setInterval(()=>{if(JSON.stringify(context())!==lastSentContext)void heartbeat('availability_changed',true);},1000);
})();
