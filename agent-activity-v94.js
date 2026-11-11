(() => {
  'use strict';
  const cfg=window.STONEFELLOW_ACTIVITY||{};if(!cfg.endpoint||!cfg.csrf)return;
  let lastMeaningful=Date.now(),hiddenAt=document.hidden?Date.now():0,lastState='',taskTitle=String(cfg.taskTitle||''),taskKey=String(cfg.taskKey||'');
  let timer=0,sending=false,lastSentContext='',closed=false,activitySequence=0,clockOffset=0,clockAnchored=false;
  let lastCoreContext=null,lastCoreKey='',lastCoreAt=0,heartbeatController=null,lastSentAvailability='',liveTimer=0,lastLiveAt=0;
  const pageCsrf=String(cfg.csrf),pageEndpoint=String(cfg.endpoint),pageOwner=Number(window.STONEFELLOW_CHAT?.userId||0);
  const pageAgent=Number(window.STONEFELLOW_CHAT?.agentId||0);
  const pageBinding=String(window.STONEFELLOW_CHAT?.workingAttentionBinding||'');
  const currentBinding=()=>window.STONEFELLOW_ACTIVITY===cfg&&String(cfg.csrf)===pageCsrf&&String(cfg.endpoint)===pageEndpoint
    &&(!pageOwner||(Number(window.STONEFELLOW_CHAT?.userId||0)===pageOwner
      &&Number(window.STONEFELLOW_CHAT?.agentId||0)===pageAgent
      &&String(window.STONEFELLOW_CHAT?.workingAttentionBinding||'')===pageBinding));
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
  const canonicalChat=()=>window.STONEFELLOW_CHAT_CONTINUITY_V87||window.STONEFELLOW_CHAT_CONTINUITY;
  const linkedConversation=()=>Number(canonicalChat()?.conversationId?.()??cfg.conversationId??new URLSearchParams(location.search).get('conversation_id')??0);
  const contextKey=value=>JSON.stringify({...value,live_context:value.live_context
    ?window.VP3LiveContext?.semantic?.(value.live_context)||null:null});
  const availabilityKey=value=>JSON.stringify({...value,live_context:null});
  function scheduleLive(){
    if(closed||!currentBinding())return;
    clearTimeout(liveTimer);
    liveTimer=setTimeout(()=>{liveTimer=0;lastLiveAt=Date.now();if(contextKey(context())!==lastSentContext)void heartbeat('live_context_changed',true);},Math.max(100,2000-(Date.now()-lastLiveAt)));
  }
  function context(){
    const focused=!document.hidden&&document.hasFocus();
    const value={track_id:Number(cfg.trackId||0),project_id:Number(cfg.projectId||0),conversation_id:linkedConversation(),task_title:taskTitle,task_kind:cfg.surface||'chat',task_key:taskKey,path:location.pathname+location.search,visible:!document.hidden};
    if(activityTab){value.activity_tab=activityTab;value.focused=focused;}
    const canonical=canonicalChat();
    const facade=window.STONEFELLOW_CHAT_CONTINUITY;
    const input=document.getElementById('chatInput');
    if(activityTab&&(cfg.surface||'chat')==='chat'&&value.conversation_id>0&&input&&typeof canonical?.isReady==='function'){
      const voiceState=String(document.body?.dataset.stonefellowAgentState||'');
      const ready=canonical.isReady()&&!canonical.isRestoring?.()
        &&Number(canonical.agentId?.()??pageAgent)===pageAgent&&currentBinding();
      value.chat_focused=focused&&ready;
      value.chat_busy=!ready||!!canonical.isBusy?.()||!!facade?.isBusy?.()
        ||String(input.value||'').trim()!==''||['thinking','processing','speaking'].includes(voiceState);
    }
    if((cfg.surface||'chat')==='chat'&&activityTab&&pageOwner&&pageBinding&&currentBinding()&&canonical?.isReady?.()&&!canonical.isRestoring?.()
      &&value.conversation_id>0&&Number(canonical.agentId?.()??pageAgent)===pageAgent){
      window.VP3LiveContext?.bindActivity?.(activityTab,clockOffset);
      const live=window.VP3LiveContext?.snapshot?.()||null;
      value.owner_user_id=pageOwner;value.agent_id=pageAgent;
      value.live_context=value.chat_focused===true&&clockAnchored?live:null;
      const source=window.STONEFELLOW_TRANSCRIPTION_CANVAS?.sourceContext?.();
      if(value.chat_focused===true&&Number.isSafeInteger(source?.session_id)&&source.session_id>0){
        value.transcript_source_id=source.session_id;
      }
    }
    return value;
  }
  const activeMedia=()=>{if(!['stem','video'].includes(String(cfg.surface||'')))return false;return [...document.querySelectorAll('audio,video')].some(el=>!el.paused&&!el.ended&&el.readyState>1&&!el.closest('.chat-media-studio'));};
  const recording=()=>!!document.querySelector('.recording,[data-recording="true"],[data-live-recording="true"]');
  function classify(){const now=Date.now(),elapsed=(now-lastMeaningful)/1000;if(recording()||activeMedia())return 'working';if(document.hidden){const hidden=(now-(hiddenAt||now))/1000;return hidden>120?'idle':'paused';}if(elapsed<=120)return 'working';if(elapsed<=480)return 'paused';return 'idle';}
  function publishCoreContext(data,sentContext,sentKey){
    const core=data?.agent_core_context,chat=canonicalChat();
    if(!core||!pageOwner||!pageBinding||!currentBinding()||closed||document.hidden||!document.hasFocus()
      ||contextKey(context())!==sentKey||!chat?.isReady?.()||chat.isRestoring?.()
      ||core.owner_user_id!==pageOwner||core.agent_id!==pageAgent
      ||core.working_attention_binding!==pageBinding||core.conversation_id!==sentContext.conversation_id
      ||Number(chat.conversationId?.())!==core.conversation_id
      ||Number(chat.agentId?.()??pageAgent)!==pageAgent||!core.health
      ||core.health.agent_id!==pageAgent||core.health.conversation_id!==core.conversation_id)return;
    lastCoreContext=core;lastCoreKey=sentKey;lastCoreAt=Date.now();
    window.dispatchEvent(new CustomEvent('vp3:agent-core-context',{detail:core}));
  }
  function publishCoreError(reason,sentContext,sentKey){
    const chat=canonicalChat();
    if(!pageOwner||!pageBinding||closed||!currentBinding()||document.hidden||!document.hasFocus()
      ||contextKey(context())!==sentKey||!chat?.isReady?.()||chat.isRestoring?.()
      ||!Number.isSafeInteger(sentContext.conversation_id)||sentContext.conversation_id<1
      ||Number(chat.conversationId?.())!==sentContext.conversation_id
      ||Number(chat.agentId?.()??pageAgent)!==pageAgent)return;
    window.dispatchEvent(new CustomEvent('vp3:agent-core-context-error',{detail:{owner_user_id:pageOwner,
      agent_id:pageAgent,conversation_id:sentContext.conversation_id,working_attention_binding:pageBinding,reason}}));
  }
  async function heartbeat(reason='timer',force=false){
    if(closed||!currentBinding())return;
    const state=classify();if(sending)return;if(!force&&state===lastState&&reason!=='timer')return;
    sending=true;const sentContext=context(),sentKey=contextKey(sentContext),sentAvailability=availabilityKey(sentContext);lastSentContext=sentKey;lastSentAvailability=sentAvailability;
    const controller=new AbortController();heartbeatController=controller;
    const requestTimeout=window.setTimeout(()=>controller.abort(),15000);
    try{
      const headers={'Content-Type':'application/json'};
      if(pageBinding)headers['X-VP3-Working-Attention']=pageBinding;
      const r=await fetch(pageEndpoint,{method:'POST',credentials:'same-origin',headers,signal:controller.signal,
        body:JSON.stringify({action:'heartbeat',csrf_token:pageCsrf,surface:cfg.surface||'chat',state,reason,context:{...sentContext,...observation()}})});
      const d=await r.json().catch(()=>null);
      if(controller.signal.aborted){publishCoreError('timeout',sentContext,sentKey);return;}
      if(r.ok&&d?.ok&&pageOwner&&Number.isSafeInteger(d.owner_user_id)&&d.owner_user_id!==pageOwner){closed=true;return;}
      if(r.ok&&d?.ok&&currentBinding()){
        acceptClock(d);publishCoreContext(d,sentContext,sentKey);
        const failed=d.agent_core_context_error;
        if(failed&&failed.owner_user_id===pageOwner&&failed.conversation_id===sentContext.conversation_id
          &&failed.working_attention_binding===pageBinding
          &&['storage_unavailable','context_unavailable','runtime_unavailable'].includes(failed.reason)){
          publishCoreError('connection_failed',sentContext,sentKey);
        }
        if(lastState&&lastState!==state)document.dispatchEvent(new CustomEvent('stonefellow:proactive-refresh',{detail:{activity:d.activity}}));
        lastState=state;document.documentElement.dataset.agentActivity=state;
      }else publishCoreError('connection_failed',sentContext,sentKey);
    }catch(e){publishCoreError(controller.signal.aborted?'timeout':'connection_failed',sentContext,sentKey);}finally{
      window.clearTimeout(requestTimeout);
      if(heartbeatController===controller)heartbeatController=null;
      sending=false;
      if(!closed&&currentBinding()){
        if(availabilityKey(context())!==sentAvailability||lastSentContext==='')queueMicrotask(()=>heartbeat('availability_changed',true));
        else if(contextKey(context())!==sentKey)scheduleLive();
      }
    }
  }
  function meaningful(reason='interaction'){lastMeaningful=Date.now();if(document.hidden)return;const prior=lastState;lastState='';heartbeat(reason,true);if(prior==='idle'||prior==='paused')document.dispatchEvent(new CustomEvent('stonefellow:proactive-refresh'));}
  ['pointerdown','keydown','input','change','submit','dragstart','drop'].forEach(type=>document.addEventListener(type,e=>{
    if(type==='keydown'&&['Shift','Control','Alt','Meta'].includes(e.key))return;
    if((type==='pointerdown'||type==='keydown')&&(cfg.surface||'chat')==='chat')window.VP3LiveContext?.noteChatInput?.(e);
    meaningful(type);
  },{capture:true,passive:type==='pointerdown'}));
  document.addEventListener('stonefellow:task-start',e=>{taskTitle=String(e.detail?.title||taskTitle);taskKey=String(e.detail?.key||taskKey);meaningful('task_start');});
  document.addEventListener('visibilitychange',()=>{hiddenAt=document.hidden?Date.now():0;heartbeat(document.hidden?'hidden':'visible',true);});
  window.addEventListener('focus',()=>heartbeat('focus',true));
  window.addEventListener('blur',()=>heartbeat('blur',true));
  window.addEventListener('pagehide',()=>{const valid=!closed&&currentBinding();closed=true;clearTimeout(liveTimer);heartbeatController?.abort();if(!valid)return;lastMeaningful=0;const priorContext=context(),departed={...priorContext,...observation(),visible:false,focused:false,chat_focused:false,chat_busy:true,...(Object.hasOwn(priorContext,'live_context')?{live_context:null}:{})};const form=new FormData();form.append('action','heartbeat');form.append('csrf_token',String(cfg.csrf));form.append('surface',String(cfg.surface||'chat'));form.append('state','idle');form.append('reason','pagehide');form.append('context',JSON.stringify(departed));if(pageBinding)form.append('working_attention_binding',pageBinding);try{navigator.sendBeacon?.(cfg.endpoint,form);}catch(e){}});
  window.addEventListener('pageshow',()=>{closed=false;void heartbeat('pageshow',true);});
  window.addEventListener('vp3:chat-context-ready',()=>void heartbeat('chat_ready',true));
  window.addEventListener('vp3:live-context-changed',scheduleLive);
  window.addEventListener('vp3:transcript-source-changed',scheduleLive);
  window.addEventListener('vp3:live-context-ready',()=>{window.VP3LiveContext?.bindActivity?.(activityTab,clockOffset);scheduleLive();});
  window.StonefellowAgentActivity={activityTab:()=>activityTab,markTask:(title,key='')=>{taskTitle=String(title||taskTitle);taskKey=String(key||taskKey);meaningful('task_mark');},snapshot:()=>({state:classify(),taskTitle,taskKey}),
    refreshCoreContext:()=>{lastSentContext='';return heartbeat('retry',true);},
    coreContext:()=>!closed&&currentBinding()&&!document.hidden&&document.hasFocus()
      &&lastCoreContext&&contextKey(context())===lastCoreKey&&Date.now()-lastCoreAt<90000
      ?{...lastCoreContext,health:{...lastCoreContext.health}}:null};
  timer=setInterval(()=>heartbeat('timer',true),30000);setTimeout(()=>heartbeat('load',true),250);
  // Only semantic availability changes produce an extra request; this is not
  // another inference loop and does not turn mouse movement into Chat activity.
  if((cfg.surface||'chat')==='chat')setInterval(()=>{if(availabilityKey(context())!==lastSentAvailability)void heartbeat('availability_changed',true);},1000);
})();
