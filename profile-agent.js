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

  // Preserve the native scheduling CTA contract, but expose it only when the
  // canonical Profile renderer has a Booking tab backed by public event types.
  const profileBookingTab=document.querySelector('[data-profile-tab="booking"]');
  const profileName=document.querySelector('.profile-name');
  if(profileBookingTab&&profileName&&!profileName.querySelector('[data-profile-booking-link]')){
    const bookingLink=document.createElement('a');
    const profileBase=new URL('.',window.location.href);
    bookingLink.dataset.profileBookingLink='1';
    bookingLink.href=new URL(`${encodeURIComponent(cfg.username)}/book`,profileBase).href;
    bookingLink.textContent='Book a time';
    bookingLink.setAttribute('aria-label',`Book a time with ${cfg.username}`);
    Object.assign(bookingLink.style,{
      display:'inline-flex',alignItems:'center',justifyContent:'center',marginTop:'10px',
      minHeight:'34px',padding:'0 13px',borderRadius:'999px',background:'#fff',color:'#171513',
      border:'1px solid rgba(255,255,255,.7)',textDecoration:'none',fontSize:'11px',fontWeight:'850',
      boxShadow:'0 5px 18px rgba(0,0,0,.16)'
    });
    profileName.appendChild(bookingLink);
  }

  function appendSafeLinkedText(container,text){
    const input=String(text||'');
    const pattern=/https?:\/\/[^\s<>"']+/gi;
    let cursor=0;
    for(const match of input.matchAll(pattern)){
      const start=Number(match.index||0);
      if(start>cursor)container.appendChild(document.createTextNode(input.slice(cursor,start)));
      let raw=String(match[0]||'');
      let trailing='';
      while(raw&&/[),.;!?]$/.test(raw)){trailing=raw.slice(-1)+trailing;raw=raw.slice(0,-1);}
      try{
        const parsed=new URL(raw,window.location.href);
        if(!['http:','https:'].includes(parsed.protocol))throw new Error('unsupported protocol');
        const anchor=document.createElement('a');
        anchor.href=parsed.href;
        anchor.textContent=raw;
        anchor.className='profile-agent-message-link';
        anchor.rel=parsed.origin===window.location.origin?'noopener':'noopener noreferrer nofollow';
        if(parsed.origin!==window.location.origin)anchor.target='_blank';
        Object.assign(anchor.style,{color:'inherit',fontWeight:'750',textDecoration:'underline',textUnderlineOffset:'2px',overflowWrap:'anywhere'});
        container.appendChild(anchor);
      }catch(_error){container.appendChild(document.createTextNode(raw));}
      if(trailing)container.appendChild(document.createTextNode(trailing));
      cursor=start+String(match[0]||'').length;
    }
    if(cursor<input.length)container.appendChild(document.createTextNode(input.slice(cursor)));
  }

  function humanizeActionKey(key){
    return String(key||'').replaceAll('_',' ').replace(/\b\w/g,m=>m.toUpperCase());
  }
  function safePreviewRows(preview){
    const rows=[];
    const blocked=/(token|secret|credential|hash|intent|receipt|manage|cancel|email|phone|public[_-]?id|(^|_)id$)/i;
    const preferred=/(name|title|label|status|date|time|start|amount|currency|reason|provider|location|event|reward|campaign|order|quantity|terms)/i;
    const walk=(value,path='',depth=0)=>{
      if(rows.length>=6||depth>2||value==null)return;
      if(Array.isArray(value)){value.slice(0,3).forEach((item,index)=>walk(item,path?path+` ${index+1}`:`Item ${index+1}`,depth+1));return;}
      if(typeof value==='object'){
        for(const [key,child] of Object.entries(value)){
          if(rows.length>=6||blocked.test(key))continue;
          walk(child,path?path+' · '+humanizeActionKey(key):humanizeActionKey(key),depth+1);
        }
        return;
      }
      if(!path||(!preferred.test(path)&&rows.length>=3))return;
      const text=typeof value==='boolean'?(value?'Yes':'No'):String(value);
      if(!text.trim()||text.length>180)return;
      rows.push([path,text]);
    };
    walk(preview);
    return rows;
  }
  function confirmationCard(action){
    if(!thread||action?.contract!=='vp3.webmcp.action.v1'||action?.phase!=='prepared'||!action?.requires_confirmation)return null;
    const intentId=String(action.intent_id||'').toLowerCase();
    if(!/^[a-f0-9]{32}$/.test(intentId))return null;
    const existing=thread.querySelector(`[data-webmcp-confirmation="${intentId}"]`);
    if(existing)existing.remove();

    const card=document.createElement('section');
    card.className='profile-agent-confirmation-card';
    card.dataset.webmcpConfirmation=intentId;
    const heading=document.createElement('strong');
    heading.className='profile-agent-confirmation-title';
    heading.textContent=String(action.title||'Review action');
    card.appendChild(heading);

    const intro=document.createElement('p');
    intro.textContent='Review the prepared action before confirming.';
    card.appendChild(intro);

    const rows=safePreviewRows(action.preview||{});
    if(rows.length){
      const list=document.createElement('dl');
      list.className='profile-agent-confirmation-preview';
      rows.forEach(([label,value])=>{
        const dt=document.createElement('dt');dt.textContent=label;
        const dd=document.createElement('dd');dd.textContent=value;
        list.append(dt,dd);
      });
      card.appendChild(list);
    }

    let terms=null;
    if(action?.confirmation?.requires_terms_acceptance){
      const label=document.createElement('label');
      label.className='profile-agent-confirmation-terms';
      terms=document.createElement('input');terms.type='checkbox';
      const span=document.createElement('span');span.textContent='I accept the seller terms shown in this checkout.';
      label.append(terms,span);card.appendChild(label);
    }

    const feedback=document.createElement('div');
    feedback.className='profile-agent-confirmation-feedback';
    feedback.setAttribute('role','status');
    card.appendChild(feedback);

    const actions=document.createElement('div');
    actions.className='profile-agent-confirmation-actions';
    const confirm=document.createElement('button');
    confirm.type='button';confirm.className='confirm';confirm.textContent='Confirm';
    if(terms)confirm.disabled=true;
    const dismiss=document.createElement('button');
    dismiss.type='button';dismiss.className='dismiss';dismiss.textContent='Cancel';
    actions.append(confirm,dismiss);card.appendChild(actions);

    terms?.addEventListener('change',()=>{confirm.disabled=!terms.checked;});
    dismiss.addEventListener('click',()=>{document.dispatchEvent(new CustomEvent('vp3:webmcp-cancel',{detail:{action}}));card.remove();});
    confirm.addEventListener('click',()=>{
      confirm.disabled=true;dismiss.disabled=true;feedback.textContent='Confirming…';
      document.dispatchEvent(new CustomEvent('vp3:webmcp-confirm',{detail:{action,terms_accepted:Boolean(terms?.checked)}}));
    });
    thread.appendChild(card);thread.scrollTop=thread.scrollHeight;
    return card;
  }
  function confirmationResult(detail){
    const intentId=String(detail?.intent_id||'').toLowerCase();
    if(!/^[a-f0-9]{32}$/.test(intentId)||!thread)return;
    const card=thread.querySelector(`[data-webmcp-confirmation="${intentId}"]`);
    if(!card)return;
    const feedback=card.querySelector('.profile-agent-confirmation-feedback');
    const controls=card.querySelectorAll('button,input');
    const result=detail?.result||{};
    if(result?.ok===true){
      if(feedback)feedback.textContent='Completed.';
      controls.forEach(control=>{control.disabled=true;});
      card.classList.add('is-complete');
      return;
    }
    const code=String(result?.error?.code||'');
    const message=String(result?.error?.message||'The action could not be completed.');
    if(feedback)feedback.textContent=code==='CONFIRMATION_EXPIRED'?'This review expired. Prepare the action again.':message;
    const retryable=code==='ACTION_IN_PROGRESS'||Boolean(result?.error?.retryable);
    controls.forEach(control=>{control.disabled=false;});
    const confirm=card.querySelector('button.confirm');
    const terms=card.querySelector('input[type=checkbox]');
    if(confirm&&terms&&!terms.checked)confirm.disabled=true;
    if(!retryable&&code!=='')card.classList.add('has-error');
  }
  document.addEventListener('vp3:webmcp-confirmation',event=>confirmationCard(event.detail));
  document.addEventListener('vp3:webmcp-confirmation-result',event=>confirmationResult(event.detail));

  function messageNode(type,text,id=0){
    const div=document.createElement('div');div.className=`profile-agent-message ${type}`;div.dataset.messageId=String(id||0);
    if(type==='agent'||type==='owner')appendSafeLinkedText(div,text);else div.textContent=String(text||'');
    return div;
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
