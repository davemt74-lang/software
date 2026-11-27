(() => {
  'use strict';
  const cfg=window.VP3_CHAT_SIDEBAR_UNREAD_V1,host=document.getElementById('chatHistory');
  if(!cfg||!host||window.VP3ChatSidebarUnreadV1)return;
  const owner=Number(cfg.owner_id),binding=cfg.binding;
  let endpoint;try{endpoint=new URL(cfg.endpoint,location.href);}catch{return;}
  if(!Number.isSafeInteger(owner)||owner<1||typeof binding!=='string'||!binding||endpoint.origin!==location.origin||endpoint.username||endpoint.password)return;
  const counts=new Map();let stopped=false,suspended=false,request=null,queued=false,timer=0,signature='',generation=0;
  const buttons=()=>[...host.querySelectorAll('.chat-history-item[data-conversation-id]')];
  const ids=()=>[...new Set(buttons().map(el=>Number(el.dataset.conversationId)).filter(id=>Number.isSafeInteger(id)&&id>0))].slice(0,50);
  const current=()=>!stopped&&!suspended&&Number(window.VP3_CHAT_SIDEBAR_UNREAD_V1?.owner_id)===owner&&window.VP3_CHAT_SIDEBAR_UNREAD_V1?.binding===binding&&(!window.STONEFELLOW_CHAT||Number(window.STONEFELLOW_CHAT.userId)===owner&&window.STONEFELLOW_CHAT.workingAttentionBinding===binding)
    &&(!window.VP3_ORB_CONFIG||Number(window.VP3_ORB_CONFIG.ownerKey)===owner)&&!window.VP3ParticipantContinuity?.selectedContext?.();
  function clear(){counts.clear();host.querySelectorAll('[data-chat-unread-count]').forEach(el=>el.remove());}
  function paint(){
    if(!current()){clear();return;}
    for(const button of buttons()){
      const count=counts.get(Number(button.dataset.conversationId))||0;let badge=button.querySelector('[data-chat-unread-count]');
      if(!count){badge?.remove();continue;}
      if(!badge){badge=document.createElement('span');badge.dataset.chatUnreadCount='';button.append(badge);}
      const value=count>99?'99+':String(count),label=count+' unread '+(count===1?'message':'messages');
      if(badge.textContent!==value)badge.textContent=value;
      badge.dataset.chatUnreadCount=String(count);badge.setAttribute('aria-label',label);badge.title=label;
    }
  }
  async function refresh(){
    if(!current()){clear();return;}
    if(document.hidden||navigator.onLine===false)return;
    if(request){queued=true;return;}
    const requested=ids(),key=requested.join(','),token=generation;
    if(!requested.length){clear();return;}
    const controller=new AbortController();request=controller;const timeout=setTimeout(()=>controller.abort(),10000);
    try{
      const url=new URL(endpoint);url.searchParams.set('action','sidebar');url.searchParams.set('conversation_ids',key);
      const response=await fetch(url,{credentials:'same-origin',cache:'no-store',signal:controller.signal,headers:{Accept:'application/json','X-VP3-Working-Attention':binding}});
      if([401,403,419].includes(response.status)){dispose();return;}
      const data=await response.json();
      if(!response.ok||data.ok!==true||data.owner_user_id!==owner||!Array.isArray(data.conversations)||data.conversations.length>requested.length
        ||!current()||generation!==token||ids().join(',')!==key)return;
      const next=new Map();
      for(const row of data.conversations){
        if(!requested.includes(row.conversation_id)||next.has(row.conversation_id)||!Number.isSafeInteger(row.agent_id)||row.agent_id<0||!Number.isSafeInteger(row.unread_count)||row.unread_count<0)return;
        next.set(row.conversation_id,row.unread_count);
      }
      counts.clear();for(const [id,count]of next)counts.set(id,count);paint();
    }catch{/* Keep the last confirmed count until the next read; never mark messages read here. */}
    finally{clearTimeout(timeout);if(request===controller)request=null;if(queued){queued=false;schedule();}}
  }
  function schedule(){clearTimeout(timer);timer=setTimeout(()=>void refresh(),100);}
  function changed(){const key=ids().join(',');paint();if(key!==signature){signature=key;generation++;request?.abort();schedule();}}
  const observer=new MutationObserver(changed);observer.observe(host,{childList:true,subtree:true});
  const poll=setInterval(()=>void refresh(),5000);
  function dispose(){stopped=true;generation++;request?.abort();clearTimeout(timer);clearInterval(poll);observer.disconnect();clear();}
  window.addEventListener('vp3:personal-phone-activity',event=>{if(event.detail?.owner_id===owner)schedule();});
  window.addEventListener('vp3:chat-messages-read',event=>{if(event.detail?.owner_id===owner)schedule();});
  window.addEventListener('storage',event=>{if(event.key?.startsWith('vp3:chat-read:v1:'+owner+':'))schedule();});
  for(const name of ['focus','online','vp3:chat-history-updated','vp3:participant-selection-changed'])window.addEventListener(name,schedule);
  window.addEventListener('pagehide',()=>{suspended=true;generation++;request?.abort();clearTimeout(timer);});
  window.addEventListener('pageshow',()=>{suspended=false;schedule();});
  document.addEventListener('visibilitychange',()=>{if(!document.hidden)schedule();});
  for(const name of ['stonefellow:session-expired','stonefellow:logout'])window.addEventListener(name,dispose);
  window.VP3ChatSidebarUnreadV1=Object.freeze({refresh,dispose});changed();
})();
