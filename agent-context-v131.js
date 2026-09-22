(() => {
  'use strict';

  const BUILD='conversation-integration-v131-20260826';
  const KNOWLEDGE_SCOPE_BUILD='knowledge-agent-context-v2451-20260922';
  const EDITOR_AGENT_ASSET='editor-agent-capabilities-20260903';
  const PARTICIPANT_ASSET='studio-participants-20260903';
  const cfg=window.STONEFELLOW_AGENT_CONTEXT||{};
  if(!cfg.userId)return;

  const userId=Number(cfg.userId||0);
  const chatCfg=window.STONEFELLOW_CHAT||{};
  const selectedUserAgentId=Math.max(0,Number(window.STONEFELLOW_AGENT_IDENTITY_V236?.agentId||0));
  const conversationKey=`stonefellow:conversation-id:${userId}:${selectedUserAgentId||'system'}`;
  const knowledgeScopeKey=`stonefellow:knowledge-scope-v162:${userId}:${selectedUserAgentId||'system'}`;
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
  let knowledgeScopeSelect=null;
  let knowledgeScopeLoadPromise=null;
  let knowledgeScopeDesiredValue='';

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
  const activeUserAgentId=()=>selectedUserAgentId;
  const knowledgeChatUrl=()=>{
    try{return new URL(String(chatCfg.endpoint||''),location.href);}
    catch(error){return null;}
  };
  const knowledgeScopeSupported=()=>{
    const target=knowledgeChatUrl();
    return !!target&&/\/api\/chat-v236\.php$/.test(target.pathname);
  };
  const normalizeKnowledgeScopeValue=value=>{
    const raw=String(value||'all').toLowerCase().trim();
    if(raw==='off')return {mode:'off',folder_id:0};
    const match=raw.match(/^folder:(\d+)$/);
    if(match&&Number(match[1])>0)return {mode:'folder',folder_id:Number(match[1])};
    return {mode:'all',folder_id:0};
  };
  const storedKnowledgeScope=()=>{
    try{return String(localStorage.getItem(knowledgeScopeKey)||'all');}
    catch(error){return 'all';}
  };
  const desiredKnowledgeScope=()=>String(knowledgeScopeDesiredValue||storedKnowledgeScope()||'all');
  const currentKnowledgeScope=()=>normalizeKnowledgeScopeValue(knowledgeScopeSelect?.value||desiredKnowledgeScope());
  const persistKnowledgeScope=value=>{
    try{localStorage.setItem(knowledgeScopeKey,String(value||'all'));}
    catch(error){}
  };
  const emitKnowledgeScope=()=>{
    try{
      window.dispatchEvent(new CustomEvent('stonefellow:knowledge-scope',{
        detail:{build:KNOWLEDGE_SCOPE_BUILD,scope:currentKnowledgeScope()}
      }));
    }catch(error){}
  };
  const setKnowledgeScopeOptions=(folders,{finalize=true}={})=>{
    if(!knowledgeScopeSelect)return;
    const liveValue=String(knowledgeScopeSelect.value||'').trim();
    const previous=liveValue||desiredKnowledgeScope();
    knowledgeScopeSelect.textContent='';
    const add=(value,label)=>{
      const option=document.createElement('option');
      option.value=value;
      option.textContent=label;
      knowledgeScopeSelect.appendChild(option);
    };
    add('all','All personal knowledge');
    add('off','No personal knowledge');
    (Array.isArray(folders)?folders:[]).forEach(folder=>{
      const id=Math.max(0,Number(folder?.id||0));
      if(id<1)return;
      const name=cleanText(folder?.name||`Folder ${id}`,120)||`Folder ${id}`;
      add(`folder:${id}`,`Folder · ${name}`);
    });
    let allowed=[...knowledgeScopeSelect.options].some(option=>option.value===previous);
    if(!finalize&&!allowed&&/^folder:\d+$/.test(previous)){
      add(previous,'Saved folder · loading…');
      allowed=true;
    }
    const next=allowed?previous:'all';
    knowledgeScopeSelect.value=next;
    knowledgeScopeDesiredValue=next;
    if(finalize)persistKnowledgeScope(next);
    knowledgeScopeSelect.dataset.knowledgeScopeReady=finalize?'1':'loading';
    knowledgeScopeSelect.setAttribute('aria-busy',finalize?'false':'true');
  };
  async function loadKnowledgeScopeFolders(){
    const chatUrl=knowledgeChatUrl();
    if(!chatUrl||!knowledgeScopeSupported())return [];
    const endpoint=new URL('knowledge-scopes-v162.php',chatUrl).toString();
    const controller=typeof AbortController==='function'?new AbortController():null;
    const timeout=controller?window.setTimeout(()=>controller.abort(),8000):0;
    try{
      const response=await fetch(endpoint,{
        method:'GET',
        credentials:'same-origin',
        cache:'no-store',
        headers:{'Accept':'application/json'},
        signal:controller?controller.signal:undefined
      });
      const data=await response.json().catch(()=>null);
      if(!response.ok||!data?.ok||!Array.isArray(data.folders)){
        throw new Error(String(data?.error||'Knowledge scopes are unavailable.'));
      }
      return data.folders;
    }catch(error){
      if(error?.name==='AbortError')throw new Error('Knowledge folders request timed out.');
      throw error;
    }finally{
      if(timeout)window.clearTimeout(timeout);
    }
  }
  function startKnowledgeScopeLoad(select){
    if(!select||knowledgeScopeLoadPromise)return knowledgeScopeLoadPromise;
    select.dataset.knowledgeScopeReady='loading';
    select.setAttribute('aria-busy','true');
    select.removeAttribute('data-knowledge-scope-error');
    select.removeAttribute('title');
    knowledgeScopeLoadPromise=loadKnowledgeScopeFolders()
      .then(folders=>{
        setKnowledgeScopeOptions(folders,{finalize:true});
        select.removeAttribute('data-knowledge-scope-error');
        select.removeAttribute('title');
        return folders;
      })
      .catch(error=>{
        const message=cleanText(error?.message||'Knowledge scopes are unavailable.',180);
        select.dataset.knowledgeScopeReady='0';
        select.dataset.knowledgeScopeError=message;
        select.setAttribute('aria-busy','false');
        select.title=message+' Focus this selector to retry.';
        knowledgeScopeLoadPromise=null;
        return [];
      });
    return knowledgeScopeLoadPromise;
  }
  function bindKnowledgeScopeSelect(select){
    if(!select)return false;
    knowledgeScopeSelect=select;
    if(!knowledgeScopeDesiredValue)knowledgeScopeDesiredValue=storedKnowledgeScope();
    if(!select.options.length)setKnowledgeScopeOptions([],{finalize:false});
    if(select.dataset.knowledgeScopeBound!=='1'){
      select.dataset.knowledgeScopeBound='1';
      select.addEventListener('change',()=>{
        knowledgeScopeDesiredValue=String(select.value||'all');
        persistKnowledgeScope(knowledgeScopeDesiredValue);
        emitKnowledgeScope();
      });
      select.addEventListener('focus',()=>{
        if(select.dataset.knowledgeScopeReady==='0'&&!knowledgeScopeLoadPromise)void startKnowledgeScopeLoad(select);
      });
    }
    void startKnowledgeScopeLoad(select);
    return true;
  }
  function ensureKnowledgeScopeUi(){
    if(!knowledgeScopeSupported()||String(cfg.surface||'chat')!=='chat'||typeof document.createElement!=='function')return false;
    const existing=document.getElementById('chatKnowledgeScopeV162');
    if(existing)return bindKnowledgeScopeSelect(existing);
    const form=document.getElementById('chatForm');
    const shell=document.getElementById('chatComposerShell');
    if(!form||!shell)return false;
    const style=document.createElement('style');
    style.dataset.knowledgeScopeV162=KNOWLEDGE_SCOPE_BUILD;
    style.textContent='.chat-knowledge-scope-v162{display:flex;align-items:center;justify-content:flex-end;gap:7px;max-width:790px;margin:0 auto 7px;padding:0 4px;color:#6b7280;font:600 11px/1.2 system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif}.chat-knowledge-scope-v162 select{max-width:min(280px,60vw);min-height:30px;border:1px solid #d1d5db;border-radius:8px;background:#fff;color:#374151;padding:4px 28px 4px 9px;font:600 11px/1.2 inherit}.chat-knowledge-scope-v162 select:focus{outline:2px solid rgba(59,130,246,.22);outline-offset:1px;border-color:#93c5fd}.chat-knowledge-scope-v162 select[data-knowledge-scope-ready="0"]{border-color:#f59e0b}@media(max-width:640px){.chat-knowledge-scope-v162{justify-content:space-between}.chat-knowledge-scope-v162 select{max-width:68vw}}';
    (document.head||document.documentElement).appendChild(style);
    const wrap=document.createElement('label');
    wrap.className='chat-knowledge-scope-v162';
    wrap.htmlFor='chatKnowledgeScopeV162';
    const label=document.createElement('span');
    label.textContent='Knowledge';
    const select=document.createElement('select');
    select.id='chatKnowledgeScopeV162';
    select.setAttribute('aria-label','Personal Knowledge scope');
    select.dataset.knowledgeScopeV162=KNOWLEDGE_SCOPE_BUILD;
    wrap.append(label,select);
    shell.insertBefore(wrap,form);
    return bindKnowledgeScopeSelect(select);
  }
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
      user_agent_id:activeUserAgentId(),
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
  window.StonefellowKnowledgeScopeV162={
    build:KNOWLEDGE_SCOPE_BUILD,
    value:currentKnowledgeScope,
    raw:()=>knowledgeScopeSelect?.value||storedKnowledgeScope(),
    payload:()=>({knowledge_scope:currentKnowledgeScope()}),
    refresh:()=>{knowledgeScopeLoadPromise=null;const select=knowledgeScopeSelect||document.getElementById('chatKnowledgeScopeV162');if(select){bindKnowledgeScopeSelect(select);return knowledgeScopeLoadPromise;}return ensureKnowledgeScopeUi();}
  };
  if(window.STONEFELLOW_ACTIVITY&&conversationId>0)window.STONEFELLOW_ACTIVITY.conversationId=conversationId;
  ensureKnowledgeScopeUi();
  ensureEditorAgent();
  ensureParticipantRuntime();
  publish(configuredConversationId>0?'load':'restore');
  window.setTimeout(()=>void refresh(true),180);
})();