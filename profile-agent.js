(() => {
  'use strict';
  const cfg=window.STONEFELLOW_PROFILE_AGENT||{};
  const shell=document.getElementById('profileAgentShell');
  if(!shell||!cfg.username||!cfg.endpoint)return;
  const thread=shell.querySelector('[data-profile-agent-thread]');
  const form=shell.querySelector('form');
  const textarea=form?.querySelector('textarea');
  const submit=form?.querySelector('button[type=submit]');
  const status=shell.querySelector('[data-profile-agent-status]');
  const storageKey=`stonefellow-profile-agent:${cfg.username}`;
  let conversationId=Math.max(0,Number(localStorage.getItem(storageKey)||0));
  let conversationStatus='open';
  let lastMessageId=0;
  let pollTimer=0,presenceTimer=0;

  function messageNode(type,text,id=0){
    const div=document.createElement('div');div.className=`profile-agent-message ${type}`;div.dataset.messageId=String(id||0);div.textContent=String(text||'');return div;
  }
  function appendMessage(type,text,id=0){
    if(!String(text||'').trim())return;
    if(id>0&&thread.querySelector(`[data-message-id="${id}"]`))return;
    const node=messageNode(type,text,id);thread.appendChild(node);if(id>lastMessageId)lastMessageId=id;thread.scrollTop=thread.scrollHeight;
  }
  function renderMessages(messages){
    thread.innerHTML='';lastMessageId=0;
    (messages||[]).forEach(m=>appendMessage(m.sender_type||'agent',m.message||'',Number(m.id||0)));
    if(!messages?.length&&cfg.greeting)appendMessage('agent',cfg.greeting);
  }
  function applyConversationState(conversation){
    conversationStatus=String(conversation?.status||'open');
    if(conversationStatus==='owner_joined')status.textContent='The profile owner has joined this conversation.';
    else if(conversationStatus==='resolved')status.textContent='This conversation was resolved. Your next message will reopen it.';
    else if(!submit?.disabled)status.textContent='';
  }
  function resetConversation(){
    conversationId=0;conversationStatus='open';lastMessageId=0;localStorage.removeItem(storageKey);clearInterval(pollTimer);pollTimer=0;
  }
  async function request(action,payload={},method='POST'){
    const options={method,credentials:'same-origin',cache:'no-store'};
    let url=cfg.endpoint;
    if(method==='GET'){
      const q=new URLSearchParams({action,username:cfg.username,conversation_id:String(conversationId||0)});url+=`?${q}`;
    }else{
      options.headers={'Content-Type':'application/json'};
      options.body=JSON.stringify({action,username:cfg.username,profile_token:cfg.profileToken,conversation_id:conversationId,...payload});
    }
    const r=await fetch(url,options);const data=await r.json().catch(()=>null);
    if(!r.ok||!data?.ok){const error=new Error(data?.error||'Profile Agent is unavailable.');error.status=r.status;throw error;}
    return data;
  }
  async function loadState(){
    try{
      const data=await request('state',{},'GET');
      if(data.agent?.name)cfg.agentName=data.agent.name;
      if(Object.prototype.hasOwnProperty.call(data.agent||{},'greeting'))cfg.greeting=data.agent.greeting||'';
      renderMessages(data.messages||[]);applyConversationState(data.conversation||null);
      if(conversationId)startPolling();
    }catch(error){
      if(conversationId&&error.status===404){resetConversation();renderMessages([]);status.textContent='';return loadState();}
      status.textContent=error.message;
    }
  }
  async function poll(){
    if(!conversationId)return;
    try{
      const data=await request('poll',{after_id:lastMessageId});
      (data.messages||[]).forEach(m=>appendMessage(m.sender_type||'agent',m.message||'',Number(m.id||0)));
      applyConversationState(data.conversation||null);
    }catch(error){
      if(error.status===404){resetConversation();renderMessages([]);loadState();}
    }
  }
  function startPolling(){clearInterval(pollTimer);pollTimer=window.setInterval(poll,10000);}
  async function heartbeat(){if(document.visibilityState!=='visible')return;try{await request('state',{},'GET');}catch(_error){}}
  form?.addEventListener('submit',async event=>{
    event.preventDefault();const message=String(textarea.value||'').trim();if(!message)return;
    appendMessage('visitor',message);textarea.value='';textarea.disabled=true;submit.disabled=true;status.textContent=`${cfg.agentName||'Profile Agent'} is thinking…`;
    try{
      const data=await request('message',{message});conversationId=Number(data.conversation_id||0);if(conversationId)localStorage.setItem(storageKey,String(conversationId));
      if(data.answer)appendMessage('agent',data.answer||'');
      applyConversationState(data.conversation||null);
      if(data.awaiting_owner)status.textContent='The profile owner has joined this conversation.';
      startPolling();
    }catch(error){
      if(error.status===404){resetConversation();renderMessages([]);status.textContent='The Profile Agent changed. Start a new conversation.';}
      else status.textContent=error.message;
    }
    finally{textarea.disabled=false;submit.disabled=false;textarea.focus();}
  });
  textarea?.addEventListener('keydown',event=>{if(event.key==='Enter'&&!event.shiftKey){event.preventDefault();form.requestSubmit();}});
  window.addEventListener('pagehide',()=>{clearInterval(pollTimer);clearInterval(presenceTimer);},{once:true});
  loadState();presenceTimer=window.setInterval(heartbeat,60000);
})();
