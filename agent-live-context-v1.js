/* Ephemeral, explicitly enabled sensory context for the canonical owner Chat.
 * This coordinates existing producers; it never captures media or runs models. */
(() => {
  'use strict';
  if(window.VP3LiveContext)return;
  const UUID=/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/;
  const TAB=/^[a-f0-9]{32}$/;
  const ttl=90000,skew=5000,chatInputTtl=30000;
  const pageChat=window.STONEFELLOW_CHAT,pagePath=location.pathname;
  const owner=Number(pageChat?.userId||window.VP3_ORB_CONFIG?.ownerKey||0);
  const pageAgent=Number(pageChat?.agentId??window.VP3_ORB_CONFIG?.agentId??0);
  const pageBinding=String(pageChat?.workingAttentionBinding||window.VP3_ORB_CONFIG?.workingAttentionBinding||'');
  let activityTab='',session='',identityKey='',cutoff=Date.now(),eyes=null,microphone=null,clockOffset=0;
  let sequence=0,lastSemantic='null',pending=[],refreshing=false,closed=false,eyeOrder=[0,-1],microphoneOrder=[0,-1],energySince=0;
  let recentChatInputAt=0;
  const integer=(value,min,max)=>Number.isSafeInteger(value)&&value>=min&&value<=max;
  const clean=(value,max)=>typeof value==='string'?[...value.replace(/[\u0000-\u001f\u007f]/g,' ')].slice(0,max).join(''):'';
  const clone=value=>value===null?null:JSON.parse(JSON.stringify(value));
  const nowIso=()=>new Date().toISOString();
  const stamp=value=>typeof value==='string'&&Number.isFinite(Date.parse(value))?Date.parse(value):0;
  const fresh=value=>{const at=stamp(value),now=Date.now();return at>=cutoff&&at<=now+skew&&now-at<=ttl;};
  const wireTime=value=>{const at=stamp(value);return at?new Date(at+clockOffset).toISOString():value;};
  function binding(){
    const cfg=window.STONEFELLOW_CHAT,chat=window.STONEFELLOW_CHAT_CONTINUITY_V87||window.STONEFELLOW_CHAT_CONTINUITY;
    if(closed||location.pathname!==pagePath||!owner||!pageBinding||!cfg||cfg!==pageChat||Number(cfg.userId)!==owner
      ||Number(cfg.agentId??0)!==pageAgent||String(cfg.workingAttentionBinding||'')!==pageBinding
      ||!document.getElementById('chatInput')||!chat?.isReady?.()||chat.isRestoring?.())return null;
    const cid=Number(chat.conversationId?.()),aid=Number(chat.agentId?.()??pageAgent);
    if(!integer(cid,1,Number.MAX_SAFE_INTEGER)||aid!==pageAgent)return null;
    const ref=window.VP3_ORB_ADAPTER?.conversationRef?.();
    if(ref&&(Number(ref.id)!==cid||Number(ref.agent??0)!==aid))return null;
    return {owner_user_id:owner,agent_id:aid,conversation_id:cid};
  }
  const quietBucket=ms=>ms===0?'none':ms<5000?'brief':ms<30000?'sustained':ms<120000?'long':'extended';
  function semantic(value){
    if(!value)return null;
    const copy=clone(value);delete copy.observed_at;
    for(const row of copy.screens||[]){delete row.captured_at;if(row.tab_audio){delete row.tab_audio.captured_at;delete row.tab_audio.duration_seconds;delete row.tab_audio.capture_id;delete row.tab_audio.session_id;for(const event of row.tab_audio.audio_events||[]){delete event.start_seconds;delete event.end_seconds;}}}
    if(copy.eyes){delete copy.eyes.observed_at;delete copy.eyes.last_event_at;delete copy.eyes.chat_input_observed_at;}
    if(copy.microphone){delete copy.microphone.observed_at;copy.microphone.quiet_duration_bucket=quietBucket(copy.microphone.quiet_duration_ms||0);delete copy.microphone.quiet_duration_ms;delete copy.microphone.observed_window_ms;}
    return copy;
  }
  function announce(value,reason){
    const key=JSON.stringify(semantic(value));if(key===lastSemantic)return;
    lastSemantic=key;
    const event={sequence:++sequence,kind:value?'context_changed':'cleared',reason,session_id:value?.session_id||null,observed_at:nowIso()};
    pending.push(event);pending=pending.slice(-12);
    window.dispatchEvent(new CustomEvent('vp3:live-context-changed',{detail:{...event}}));
  }
  function clear(reason='cleared'){
    cutoff=Date.now();eyes=microphone=null;session='';identityKey='';eyeOrder=microphoneOrder=[0,-1];energySince=0;recentChatInputAt=0;
    if(lastSemantic!=='null')pending=[];
    announce(null,reason);
  }
  function screensFor(current){
    const values=window.VP3AgentScreenShare?.liveContext?.();
    if(!Array.isArray(values))return [];
    const rows=[];
    for(const input of values.slice(0,3)){
      if(!input||input.owner_user_id!==current.owner_user_id||input.agent_id!==current.agent_id
        ||input.conversation_id!==current.conversation_id||!/^share-[123]$/.test(input.source_id||'')
        ||!UUID.test(input.session_id||'')||input.active!==true||input.context_allowed!==true||input.contract!=='vp3.screen-observation.v1'||String(input.owner_key)!==String(owner)
        ||!fresh(input.captured_at)||input.historical_frame===true||!['browser','window','monitor'].includes(input.surface))continue;
      const row={source_id:input.source_id,session_id:input.session_id,active:true,context_allowed:true,selected:input.selected===true,
        contract:'vp3.screen-observation.v1',owner_key:String(owner),captured_at:wireTime(input.captured_at),surface:input.surface,
        summary:clean(input.summary,2000),visible_text:clean(input.visible_text,2000),site_hint:clean(input.site_hint,240),page_title:clean(input.page_title,240)};
      if(Array.isArray(input.observations))row.observations=input.observations.slice(0,8).filter(item=>item
        &&['music','video','article','product','error','project','other'].includes(item.kind)&&['low','medium','high'].includes(item.confidence))
        .map(item=>({kind:item.kind,label:clean(item.label,200),detail:clean(item.detail,500),confidence:item.confidence}));
      if(typeof input.on_screen_translation==='string')row.on_screen_translation=clean(input.on_screen_translation,2000);
      const audio=input.tab_audio;
      if(audio&&fresh(audio.captured_at)&&typeof audio.text==='string'&&Number.isFinite(audio.duration_seconds)&&audio.duration_seconds>0&&audio.duration_seconds<=30){
        row.tab_audio={captured_at:wireTime(audio.captured_at),duration_seconds:audio.duration_seconds,text:clean(audio.text,3000)};
        if(UUID.test(audio.capture_id||''))row.tab_audio.capture_id=audio.capture_id;
        if(audio.session_id===input.session_id)row.tab_audio.session_id=audio.session_id;
        if(Array.isArray(audio.audio_events))row.tab_audio.audio_events=audio.audio_events.slice(0,8).filter(event=>event
          &&event.provider==='elevenlabs'&&event.model==='scribe_v2'&&event.source==='shared_tab_audio'&&event.classification==='provider_label'
          &&typeof event.label==='string'&&Number.isFinite(event.start_seconds)&&Number.isFinite(event.end_seconds)
          &&event.start_seconds>=0&&event.end_seconds>=event.start_seconds&&event.end_seconds<=audio.duration_seconds)
          .map(event=>({label:clean(event.label,80),start_seconds:event.start_seconds,end_seconds:event.end_seconds,
            provider:'elevenlabs',model:'scribe_v2',source:'shared_tab_audio',classification:'provider_label'}));
      }
      rows.push(row);
    }
    return rows;
  }
  function snapshot(){
    if(refreshing)return null;
    refreshing=true;
    try{
      const current=binding(),key=current?JSON.stringify(current):'';
      if(!current||document.hidden){if(identityKey||session||eyes||microphone)clear(document.hidden?'hidden':'binding_changed');return null;}
      if(identityKey&&identityKey!==key)clear('conversation_changed');
      identityKey=key;
      if(!TAB.test(activityTab)||!document.hasFocus())return null;
      const screens=screensFor(current);
      const eye=eyes&&fresh(eyes.observed_at)?eyes:null;
      const mic=microphone&&fresh(microphone.observed_at)?microphone:null;
      if(!screens.length&&!eye?.active&&!mic?.active){
        // Expired evidence is unavailable. It is distinct from an explicit
        // stop: an unchanged fresh observation may renew an ongoing capture.
        const active=window.VP3AgentScreenShare?.hasLiveContext?.()||eyes?.active||microphone?.active;
        if(session&&!active)clear('all_stopped');
        else announce(null,active?'awaiting_current_observation':'all_stopped');return null;
      }
      if(!session)session=crypto.randomUUID();
      const safeEyes=eye?.active?{...clone(eye),observed_at:wireTime(eye.observed_at),...(eye.last_event_at?{last_event_at:wireTime(eye.last_event_at)}:{})}:null;
      if(safeEyes?.participant_count){
        const age=Date.now()-recentChatInputAt;
        safeEyes.recent_chat_input=recentChatInputAt>0&&age>=0&&age<chatInputTtl;
        if(safeEyes.recent_chat_input)safeEyes.chat_input_observed_at=wireTime(new Date(recentChatInputAt).toISOString());
      }
      const safeMic=mic?.active?{...clone(mic),observed_at:wireTime(mic.observed_at)}:null;
      const value={contract:'vp3.live-context.v1',session_id:session,...current,activity_tab:activityTab,observed_at:wireTime(nowIso()),screens,
        ...(safeEyes?{eyes:safeEyes}:{}),...(safeMic?{microphone:safeMic}:{})};
      announce(value,'observation');return value;
    }finally{refreshing=false;}
  }
  function eventBinding(detail){
    const current=binding(),given=detail?.context;
    if(!current||!given||given.owner_user_id!==current.owner_user_id||given.agent_id!==current.agent_id||given.conversation_id!==current.conversation_id)return null;
    const key=JSON.stringify(current);if(identityKey&&identityKey!==key)clear('conversation_changed');identityKey=key;
    return current;
  }
  function newer(value,order){
    if(!fresh(value.observed_at)||!integer(value.sequence,0,Number.MAX_SAFE_INTEGER))return false;
    const at=stamp(value.observed_at);return at>order[0]||at===order[0]&&value.sequence>order[1];
  }
  function noteChatInput(event){
    if(!(event instanceof Event)||!event.isTrusted||!['pointerdown','keydown'].includes(event.type)
      ||document.hidden||!document.hasFocus()||!TAB.test(activityTab)||!eyes?.participant_count||!fresh(eyes.observed_at))return;
    const current=binding();
    if(current&&JSON.stringify(current)===identityKey)recentChatInputAt=Date.now();
  }
  window.addEventListener('vp3:participant-presence',event=>{
    const value=event.detail;if(!eventBinding(value)||!newer(value,eyeOrder))return;eyeOrder=[stamp(value.observed_at),value.sequence];
    if(value.active!==true||value.context_allowed!==true){eyes=null;recentChatInputAt=0;snapshot();return;}
    if(!fresh(value.observed_at)||!Array.isArray(value.rows))return;
    const rows=value.rows.slice(0,8);const known=rows.filter(row=>typeof row?.participant_id==='string'&&row.participant_id!=='').length;
    if(!rows.length)recentChatInputAt=0;
    eyes={active:true,context_allowed:true,observed_at:value.observed_at,participant_count:rows.length,known_count:known,unknown_count:rows.length-known,
      identity_provisional:true,authentication_authority:false,greetings_allowed:value.greetings_allowed===true,
      last_event:['arrival','departure','steady','unknown'].includes(value.last_event)?value.last_event:'unknown'};
    if(fresh(value.last_event_at))eyes.last_event_at=value.last_event_at;
    if(value.object_detection_active===true&&Array.isArray(value.object_labels)){eyes.object_detection_active=true;eyes.object_labels=[...new Set(value.object_labels.filter(item=>typeof item==='string'&&/^[a-zA-Z][a-zA-Z0-9 _-]{0,59}$/.test(item)).slice(0,8))];}
    snapshot();
  });
  window.addEventListener('vp3:microphone-activity',event=>{
    const value=event.detail;if(!eventBinding(value)||!newer(value,microphoneOrder))return;microphoneOrder=[stamp(value.observed_at),value.sequence];
    if(value.active!==true||value.context_allowed!==true){microphone=null;energySince=0;snapshot();return;}
    if(!fresh(value.observed_at)||!['energy','quiet','uncertain'].includes(value.activity)
      ||!integer(value.quiet_duration_ms,0,300000)||!integer(value.observed_window_ms,0,30000))return;
    energySince=value.activity==='energy'?(microphone?.activity==='energy'&&energySince?energySince:Date.now()):0;
    microphone={active:true,context_allowed:true,observed_at:value.observed_at,activity:value.activity,quiet_duration_ms:value.quiet_duration_ms,
      observed_window_ms:value.observed_window_ms,authentication_authority:false};
    snapshot();
  });
  ['vp3:screen-live-context','vp3:screen-share-stopped','vp3:screen-observation-reviewed','vp3:chat-context-ready'].forEach(name=>window.addEventListener(name,()=>snapshot()));
  window.addEventListener('stonefellow:chat-context-changing',()=>clear('conversation_changed'));
  document.addEventListener('visibilitychange',()=>{if(document.hidden)clear('hidden');else snapshot();});
  window.addEventListener('blur',()=>{recentChatInputAt=0;});
  window.addEventListener('pagehide',()=>{clear('pagehide');closed=true;});
  window.addEventListener('pageshow',()=>{closed=false;snapshot();});
  window.addEventListener('popstate',()=>clear('route_changed'));
  window.VP3LiveContext=Object.freeze({snapshot,semantic,clear,noteChatInput,
    voiceDeferral:()=>{const active=snapshot()?.microphone;const defer=active?.activity==='energy'&&energySince>0&&Date.now()-energySince>=750;return {defer,reason:defer?'microphone_activity':''};},
    bindActivity:(tab,offset=0)=>{if(TAB.test(tab||''))activityTab=tab;if(Number.isFinite(offset)&&Math.abs(offset)<=86400000)clockOffset=offset;},
    pendingEvents:()=>pending.map(event=>({...event})),
    acknowledge:through=>{if(Number.isSafeInteger(through))pending=pending.filter(event=>event.sequence>through);}});
  const tab=window.StonefellowAgentActivity?.activityTab?.();if(tab)window.VP3LiveContext.bindActivity(tab);
  window.dispatchEvent(new Event('vp3:live-context-ready'));
})();
