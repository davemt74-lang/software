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
  let lastMessageId=0;
  let pollTimer=0,presenceTimer=0;
  const esc=v=>String(v??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[c]));

  function messageNode(type,text,id=0){
    const div=document.createElement('div');div.className=`profile-agent-message ${type}`;div.dataset.messageId=String(id||0);div.textContent=String(text||'');return div;
  }
  function appendMessage(type,text,id=0){
    if(id>0&&thread.querySelector(`[data-message-id="${id}"]`))return;
    const node=messageNode(type,text,id);thread.appendChild(node);if(id>lastMessageId)lastMessageId=id;thread.scrollTop=thread.scrollHeight;
  }
  function renderMessages(messages){
    thread.innerHTML='';lastMessageId=0;
    (messages||[]).forEach(m=>appendMessage(m.sender_type||'agent',m.message||'',Number(m.id||0)));
    if(!messages?.length&&cfg.greeting)appendMessage('agent',cfg.greeting);
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
    if(!r.ok||!data?.ok)throw new Error(data?.error||'Profile Agent is unavailable.');
    return data;
  }
  async function loadState(){
    try{
      const data=await request('state',{},'GET');renderMessages(data.messages||[]);
      if(data.agent?.greeting&&!cfg.greeting)cfg.greeting=data.agent.greeting;
      if(conversationId)startPolling();
    }catch(error){if(conversationId){conversationId=0;localStorage.removeItem(storageKey);renderMessages([]);}status.textContent=error.message;}
  }
  async function poll(){
    if(!conversationId)return;
    try{
      const data=await request('poll',{after_id:lastMessageId});
      (data.messages||[]).forEach(m=>appendMessage(m.sender_type||'agent',m.message||'',Number(m.id||0)));
    }catch(_error){}
  }
  function startPolling(){clearInterval(pollTimer);pollTimer=window.setInterval(poll,10000);}
  async function heartbeat(){if(document.visibilityState!=='visible')return;try{await request('state',{},'GET');}catch(_error){}}
  form?.addEventListener('submit',async event=>{
    event.preventDefault();const message=String(textarea.value||'').trim();if(!message)return;
    appendMessage('visitor',message);textarea.value='';textarea.disabled=true;submit.disabled=true;status.textContent=`${cfg.agentName} is thinking…`;
    try{
      const data=await request('message',{message});conversationId=Number(data.conversation_id||0);if(conversationId)localStorage.setItem(storageKey,String(conversationId));
      appendMessage('agent',data.answer||'');status.textContent='';startPolling();
    }catch(error){status.textContent=error.message;}
    finally{textarea.disabled=false;submit.disabled=false;textarea.focus();}
  });
  textarea?.addEventListener('keydown',event=>{if(event.key==='Enter'&&!event.shiftKey){event.preventDefault();form.requestSubmit();}});
  window.addEventListener('pagehide',()=>{clearInterval(pollTimer);clearInterval(presenceTimer);},{once:true});
  loadState();presenceTimer=window.setInterval(heartbeat,60000);
})();
