(() => {
  'use strict';

  const BUILD='conversation-integration-v131-20260826';
  const EDITOR_AGENT_ASSET='editor-agent-capabilities-20260903';
  const PARTICIPANT_ASSET='studio-participants-20260903';
  const cfg=window.STONEFELLOW_AGENT_CONTEXT||{};
  if(!cfg.userId)return;

  const userId=Number(cfg.userId||0);
  const conversationKey=`stonefellow:conversation-id:${userId}`;
  const readStoredConversation=()=>{
    try{return Math.max(0,Number(localStorage.getItem(conversationKey)||0));}
    catch(error){return 0;}
  };
  const writeStoredConversation=value=>{
    const next=Math.max(0,Number(value||0));
    try{
      if(next>0)localStorage.setItem(conversationKey,String(next));
      else localStorage.removeItem(conversationKey);
    }catch(error){}
    return next;
  };

  const configuredConversationId=Math.max(0,Number(cfg.conversationId||0));
  let conversationId=configuredConversationId||readStoredConversation();
  if(conversationId>0)writeStoredConversation(conversationId);
  let taskTitle=String(cfg.taskTitle||'');
  let taskKey=String(cfg.taskKey||'');
  let proactive=[];
  let events=[];
  let activity=null;
  let lastRefresh=0;
  let refreshPromise=null;
  let voiceSession=null;
  let editorAgentLoadRequested=false;
  let participantLoadRequested=false;

  const cleanText=(value,limit=280)=>String(value??'').replace(/\s+/g,' ').trim().slice(0,limit);
  const safeSuggestion=row=>({
    hash:cleanText(row?.hash,120),
    title:cleanText(row?.title,180),
    prompt:cleanText(row?.prompt,600),
    reason:cleanText(row?.reason,360),
    source:cleanText(row?.source,120),
    url:cleanText(row?.url,500),
    score:Math.max(0,Math.min(1,Number(row?.score||0)))
  });
  const currentActivity=()=>{
    const live=window.StonefellowAgentActivity?.snapshot?.();
    if(live&&typeof live==='object')return live;
    return activity&&typeof activity==='object'?activity:{};
  };
  const editorCapabilities=()=>{
    try{
      const catalog=window.StonefellowEditorAgent?.contextCatalog?.();
      return catalog&&Array.isArray(catalog.surfaces)&&catalog.surfaces.length?catalog:null;
    }catch(error){return null;}
  };
  const participantContext=()=>{
    try{
      const value=window.StonefellowStudioParticipants?.agentContext?.();
      return value&&typeof value==='object'?value:null;
    }catch(error){return null;}
  };
  function ensureEditorAgent(){
    if(String(cfg.surface||'chat')!=='chat'||window.StonefellowEditorAgent||editorAgentLoadRequested)return false;
    if(typeof document.createElement!=='function')return false;
    const existing=document.querySelector?.('[data-editor-agent-capabilities]');
    if(existing){editorAgentLoadRequested=true;return true;}
    const host=document.head||document.documentElement;
    if(!host?.appendChild)return false;
    editorAgentLoadRequested=true;
    const script=document.createElement('script');
    script.src=`/editor-agent.js?v=${EDITOR_AGENT_ASSET}`;
    script.async=true;
    script.dataset.editorAgentCapabilities=EDITOR_AGENT_ASSET;
    script.addEventListener?.('load',()=>publish('editor-capabilities'),{once:true});
    script.addEventListener?.('error',()=>{editorAgentLoadRequested=false;},{once:true});
    host.appendChild(script);
    return true;
  }
  function ensureParticipantRuntime(){
    const surface=String(cfg.surface||'chat');
    if(!['chat','stem','video','transcription'].includes(surface)||window.StonefellowStudioParticipants||participantLoadRequested)return false;
    if(typeof document.createElement!=='function')return false;
    const existing=document.querySelector?.('[data-studio-participants]');
    if(existing){participantLoadRequested=true;return true;}
    const host=document.head||document.documentElement;
    if(!host?.appendChild)return false;
    participantLoadRequested=true;
    const script=document.createElement('script');
    script.src=`/studio-participants.js?v=${PARTICIPANT_ASSET}`;
    script.async=true;
    script.dataset.studioParticipants=PARTICIPANT_ASSET;
    script.addEventListener?.('load',()=>publish('participants'),{once:true});
    script.addEventListener?.('error',()=>{participantLoadRequested=false;},{once:true});
    host.appendChild(script);
    return true;
  }
  function baseContext(){
    const active=currentActivity();
    return {
      build:BUILD,
      surface:cleanText(cfg.surface||'chat',30),
      track_id:Math.max(0,Number(cfg.trackId||0)),
      project_id:Math.max(0,Number(cfg.projectId||0)),
      conversation_id:Math.max(0,Number(conversationId||0)),
      task_title:cleanText(active.taskTitle||taskTitle,240),
      task_key:cleanText(active.taskKey||taskKey,180),
      activity_state:cleanText(active.state||activity?.state||'',30),
      path:location.pathname+location.search,
      visible:document.visibilityState!=='hidden',
      voice:voiceSession?{
        session_id:cleanText(voiceSession.id||voiceSession.sessionId||'',120),
        state:cleanText(voiceSession.state||'',30),
        enabled:!!voiceSession.enabled,
        source:cleanText(voiceSession.source||'',40)
      }:null,
      participants:participantContext(),
      editor_capabilities:editorCapabilities()
    };
  }
  function snapshot(){
    return {
      ...baseContext(),
      proactive:proactive.slice(0,8).map(safeSuggestion),
      events:events.slice(0,8).map(row=>({
        id:cleanText(row?.id,120),
        type:cleanText(row?.type,60),
        event_kind:cleanText(row?.event_kind,60),
        title:cleanText(row?.title,220),
        summary:cleanText(row?.summary,360),
        source:cleanText(row?.source,120)
      }))
    };
  }
  function publish(reason='update'){
    const detail={reason,context:snapshot()};
    window.dispatchEvent(new CustomEvent('stonefellow:agent-context',{detail}));
    return detail.context;
  }
  function setConversationId(value,{persist=true}={}){
    const next=Math.max(0,Number(value||0));
    if(next===conversationId){
      if(persist&&next>0)writeStoredConversation(next);
      return conversationId;
    }
    conversationId=next;
    if(persist)writeStoredConversation(next);
    if(window.STONEFELLOW_ACTIVITY)window.STONEFELLOW_ACTIVITY.conversationId=next;
    publish('conversation');
    return conversationId;
  }
  function setTask(title,key=''){
    taskTitle=cleanText(title||taskTitle,240);
    taskKey=cleanText(key||taskKey,180);
    if(window.STONEFELLOW_ACTIVITY){
      window.STONEFELLOW_ACTIVITY.taskTitle=taskTitle;
      window.STONEFELLOW_ACTIVITY.taskKey=taskKey;
    }
    publish('task');
  }
  async function refresh(force=false){
    const endpoint=String(cfg.proactiveEndpoint||'');
    const csrf=String(cfg.csrf||'');
    const now=Date.now();
    if(!endpoint||!csrf)return snapshot();
    if(!force&&now-lastRefresh<15000)return snapshot();
    if(refreshPromise)return refreshPromise;
    refreshPromise=(async()=>{
      try{
        const response=await fetch(endpoint,{
          method:'POST',credentials:'same-origin',
          headers:{'Content-Type':'application/json'},
          body:JSON.stringify({csrf_token:csrf,action:'list',surface:String(cfg.surface||'chat'),context:baseContext()})
        });
        const data=await response.json().catch(()=>null);
        if(response.ok&&data?.ok){
          proactive=Array.isArray(data.suggestions)?data.suggestions.map(safeSuggestion).slice(0,8):[];
          events=Array.isArray(data.events)?data.events.slice(0,8):[];
          if(data.activity&&typeof data.activity==='object')activity=data.activity;
          lastRefresh=Date.now();
          publish('proactive');
        }
      }catch(error){}
      finally{refreshPromise=null;}
      return snapshot();
    })();
    return refreshPromise;
  }

  window.addEventListener('stonefellow:voice-session',event=>{
    const detail=event.detail||{};
    if(Number(detail.userId||0)!==userId)return;
    voiceSession=detail;publish('voice');
  });
  window.addEventListener('storage',event=>{
    if(event.key!==conversationKey)return;
    const next=Math.max(0,Number(event.newValue||0));
    if(next!==conversationId)setConversationId(next,{persist:false});
  });
  window.addEventListener('stonefellow:editor-agent:ready',()=>publish('editor-capabilities'));
  window.addEventListener('stonefellow:editor-agent:catalog-updated',()=>publish('editor-capabilities'));
  window.addEventListener('stonefellow:studio-participants',()=>publish('participants'));
  window.addEventListener('stonefellow:task-start',event=>setTask(event.detail?.title||'',event.detail?.key||''));
  document.addEventListener('stonefellow:proactive-refresh',()=>void refresh(true));
  document.addEventListener('visibilitychange',()=>{if(document.visibilityState==='visible')void refresh(false);});

  const api={build:BUILD,snapshot,refresh,setConversationId,setTask,conversationId:()=>conversationId,conversationKey,editorCapabilities,participantContext};
  window.StonefellowAgentContext=api;
  if(window.STONEFELLOW_ACTIVITY&&conversationId>0)window.STONEFELLOW_ACTIVITY.conversationId=conversationId;
  ensureEditorAgent();
  ensureParticipantRuntime();
  publish(configuredConversationId>0?'load':'restore');
  window.setTimeout(()=>void refresh(true),180);
})();
