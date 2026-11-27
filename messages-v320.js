(()=>{
  const root=document.querySelector('[data-messages-app]');if(!root)return;
  const api=root.dataset.api,shareApi=root.dataset.browserShareApi,csrf=root.dataset.csrf,currentUserId=Number(root.dataset.userId||0);
  const q=s=>root.querySelector(s),stream=q('[data-stream]'),empty=q('[data-empty]'),composer=q('[data-composer]'),title=q('[data-thread-title]'),kind=q('[data-thread-kind]'),requestActions=q('[data-request-actions]');
  const textarea=composer.querySelector('textarea'),sendButton=composer.querySelector('button'),status=q('[data-status]'),historyButton=q('[data-history]');
  let activeId=0,activeTarget=Number(root.dataset.targetId||0),inbox={teams:[],conversations:[],requests:[]},settings={},shares=[];
  let selection=0,inboxRequest=0,threadRequest=0,opening=0,fetchedThrough=0,ready=Boolean(activeTarget),request=null,rows=[],hasOlder=false,refreshing=null,stopped=false,accountLocked=false,identityMessage='',timer=0;
  const drafts=new Map(),sending=new Set(),readThrough=new Map(),requestsInFlight=new Set();
  const requestHeaders={'X-VP3-Messages-User':String(currentUserId)};
  const esc=s=>String(s??'').replace(/[&<>'"]/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#039;','"':'&quot;'}[c]));
  const draftKey=()=>activeId?'conversation:'+activeId:activeTarget?'user:'+activeTarget:'';
  const saveDraft=()=>{const key=draftKey();if(key)drafts.set(key,textarea.value);};
  const current=(id,version)=>!stopped&&activeId===id&&selection===version;
  const visible=()=>document.visibilityState!=='hidden'&&(!matchMedia('(max-width:800px)').matches||root.dataset.threadOpen==='true');
  function notice(message=''){
    if(accountLocked)message=identityMessage;
    const inboxStatus=q('[data-inbox-status]'),inInbox=(!activeId&&!activeTarget)||(matchMedia('(max-width:800px)').matches&&root.dataset.threadOpen!=='true');
    for(const element of [status,inboxStatus])if(element){element.textContent='';element.hidden=true;}
    const destination=inInbox?(inboxStatus||status):status;if(destination){destination.textContent=message;destination.hidden=!message;}
  }
  function errorText(error){return ({login_required:'Your session expired. Sign in again to continue.',csrf:'Your session changed. Reload Messages before sending.',upgrade_required:'Messaging needs the site upgrade. Ask the site administrator to run Upgrade.',database_unavailable:'Messages are temporarily unavailable. Please try Refresh.',not_found:'This conversation is no longer available.',invalid_response:'Messages could not be loaded. Please try Refresh.'})[error?.message]||error?.message||'Messages could not be loaded. Please try Refresh.';}
  function lockAccount(code='account_changed'){
    if(accountLocked)return;
    accountLocked=true;stopped=true;identityMessage=code==='login_required'?'Your session expired. Sign in again, then reload Messages.':'Your account changed. Reload Messages to continue.';
    ++selection;++threadRequest;++inboxRequest;ready=false;activeId=0;activeTarget=0;request=null;rows=[];shares=[];settings={};hasOlder=false;
    inbox={teams:[],conversations:[],requests:[]};drafts.clear();sending.clear();readThrough.clear();textarea.value='';textarea.disabled=true;stream.textContent='';stream.hidden=true;
    for(const controller of requestsInFlight)controller.abort();clearInterval(timer);
    document.querySelector('[data-settings]')?.close();document.querySelector('[data-settings] form')?.reset();title.textContent='Your messages';kind.textContent='Reload Messages';root.dataset.threadOpen='false';
    empty.hidden=false;empty.innerHTML='<strong>Reload Messages to continue</strong>';updateComposer();render();
    q('[data-settings-open]').disabled=true;q('[data-refresh]').setAttribute('aria-label','Reload Messages');
    notice(identityMessage);
  }
  async function jsonRequest(url,options={}){
    if(accountLocked)throw new Error('account_changed');
    const controller=new AbortController(),timer=setTimeout(()=>controller.abort(),20000);
    requestsInFlight.add(controller);
    try{
      const response=await fetch(url,{credentials:'same-origin',cache:'no-store',...options,headers:{...options.headers,...requestHeaders},signal:controller.signal});
      const data=await response.json().catch(()=>({ok:false,error:'invalid_response'}));
      if(data.error==='account_changed'||response.status===401){lockAccount(data.error||'login_required');throw new Error('account_changed');}
      if(accountLocked)throw new Error('account_changed');
      if(!response.ok||!data.ok){const error=new Error(data.message||data.error||'Request failed');error.status=response.status;throw error;}
      return data;
    }catch(error){if(error.name==='AbortError')throw new Error('Messages took too long to respond. Please try Refresh.');throw error;}
    finally{clearTimeout(timer);requestsInFlight.delete(controller);}
  }
  async function req(action,data={},method='GET'){
    const url=new URL(api,location.href);url.searchParams.set('action',action);
    if(method==='POST')return jsonRequest(url,{method:'POST',body:new URLSearchParams({...data,csrf_token:csrf}),headers:{'Content-Type':'application/x-www-form-urlencoded;charset=UTF-8'}});
    Object.entries(data).forEach(([key,value])=>url.searchParams.set(key,String(value)));return jsonRequest(url);
  }
  async function shareReq(action='feed',data={}){
    if(!shareApi)return {feed:{items:[]}};
    const url=new URL(shareApi,location.href);url.searchParams.set('action',action);Object.entries(data).forEach(([key,value])=>url.searchParams.set(key,String(value)));return jsonRequest(url);
  }
  const avatar=(name,path='')=>`<span class="messages-item-avatar">${path?`<img src="${esc(path)}" alt="">`:esc(String(name||'?').trim().slice(0,1).toUpperCase())}</span>`;
  const item=(id,name,sub,type,avatarPath='',unread=0)=>`<button class="messages-item${activeId===id?' active':''}" type="button" data-open="${id}" data-kind="${type}">${avatar(name,avatarPath)}<span class="messages-item-copy"><strong>${esc(name)}</strong><span>${esc(sub||'')}</span></span>${Number(unread)>0?`<b class="messages-unread" aria-label="${Number(unread)} unread">${Number(unread)>99?'99+':Number(unread)}</b>`:''}</button>`;
  function render(){
    const search=String(q('[data-search]')?.value||'').trim().toLocaleLowerCase();
    const matches=(...values)=>!search||values.some(value=>String(value||'').toLocaleLowerCase().includes(search));
    const people=inbox.conversations.filter(c=>matches(c.other_name,c.title,c.last_message)),teams=inbox.teams.filter(t=>matches(t.title)),requests=inbox.requests.filter(r=>matches(r.requester_name,r.initial_message));
    q('[data-list="people"]').innerHTML=people.length?people.map(c=>item(Number(c.id),c.other_name||c.title||'Conversation',c.request_status==='pending'?'Request pending':c.last_message||'Direct message','people',c.other_avatar||'',c.unread_count||0)).join(''):`<div class="messages-list-empty">${search?'No matching conversations.':'No direct conversations yet. Open a member’s profile to send a message.'}</div>`;
    q('[data-list="team"]').innerHTML=teams.length?teams.map(t=>item(Number(t.conversation_id),t.title,'Everyone in this workspace','team','',t.unread_count||0)).join(''):`<div class="messages-list-empty">${search?'No matching Teams.':'No Team workspaces yet.'}</div>`;
    q('[data-list="requests"]').innerHTML=requests.length?requests.map(r=>item(Number(r.conversation_id),r.requester_name||'Message request',r.initial_message||'Wants to message you','requests',r.requester_avatar||'')).join(''):`<div class="messages-list-empty">${search?'No matching requests.':'No pending requests.'}</div>`;
    const shareList=q('[data-list="shares"]');shareList.textContent='';
    const shownShares=shares.filter(s=>matches(s.source?.title,s.source?.domain));
    if(!shownShares.length)shareList.innerHTML='<div class="messages-list-empty">No Browser Shares available.</div>';
    else shownShares.forEach(share=>{
      const button=document.createElement('button');button.type='button';button.className='messages-item';button.dataset.shareId=String(share.id);
      const icon=document.createElement('span');icon.className='messages-item-avatar';icon.textContent='↗';
      const copy=document.createElement('span');copy.className='messages-item-copy';const strong=document.createElement('strong');strong.textContent=String(share.source?.title||share.source?.domain||'Browser Share');const sub=document.createElement('span');sub.textContent=String(share.source?.domain||'Shared from the web');copy.append(strong,sub);button.append(icon,copy);button.addEventListener('click',()=>showShare(share));shareList.appendChild(button);
    });
    q('[data-request-count]').textContent=String(inbox.requests.length);
    root.querySelectorAll('[data-open]').forEach(button=>button.addEventListener('click',()=>openConversation(Number(button.dataset.open))));
  }
  async function load(){
    const version=++inboxRequest,result=await req('inbox');if(version!==inboxRequest||stopped)return;
    inbox={teams:Array.isArray(result.inbox?.teams)?result.inbox.teams:[],conversations:Array.isArray(result.inbox?.conversations)?result.inbox.conversations:[],requests:Array.isArray(result.inbox?.requests)?result.inbox.requests:[]};
    settings=result.settings||settings;render();
  }
  async function loadShares(){try{const result=await shareReq('feed',{limit:40});if(!stopped){shares=Array.isArray(result.feed?.items)?result.feed.items:[];render();}}catch{ /* An unavailable Shares service must not block conversations. */ }}
  function shareCard(share,onReply){const lib=window.VP3BrowserShareCardV2020;if(!lib?.create||!share)return null;return lib.create(share,{api:shareApi,csrf,chatUrl:root.dataset.browserShareChatUrl,requestHeaders,onAccountChange:lockAccount,isCurrent:()=>!accountLocked,onReply:onReply||(()=>{})});}
  function renderMessages(mode='append'){
    const previousHeight=stream.scrollHeight,previousTop=stream.scrollTop,atEnd=previousHeight-previousTop-stream.clientHeight<80;
    stream.textContent='';
    rows.forEach(message=>{
      const bubble=document.createElement('div');bubble.className=`messages-bubble${Number(message.sender_user_id)===currentUserId?' mine':''}`;bubble.dataset.messageId=String(message.id);
      const who=document.createElement('small');who.textContent=String(message.display_name||(Number(message.sender_user_id)===currentUserId?'You':''));
      bubble.append(who,document.createTextNode(message.deleted_at?'Message deleted':String(message.body||'')));
      const card=shareCard(message.browser_share,share=>replyToShare(share));if(card)bubble.appendChild(card);stream.appendChild(bubble);
    });
    stream.hidden=false;empty.hidden=rows.length>0;
    if(!rows.length)empty.innerHTML='<strong>No messages yet</strong><span>Send a message to start this conversation.</span>';
    const version=selection;
    requestAnimationFrame(()=>{
      if(version!==selection)return;
      if(mode==='older')stream.scrollTop=previousTop+stream.scrollHeight-previousHeight;
      else if(mode==='reset'||mode==='sent'||atEnd)stream.scrollTop=stream.scrollHeight;
      else stream.scrollTop=previousTop;
    });
  }
  function mergeMessages(next,mode='append'){
    const byId=new Map(rows.map(row=>[Number(row.id),row]));next.forEach(row=>byId.set(Number(row.id),row));
    const updated=[...byId.values()].sort((a,b)=>Number(a.id)-Number(b.id));
    if(mode==='reset'||JSON.stringify(updated)!==JSON.stringify(rows)){rows=updated;renderMessages(mode);}
  }
  function updateComposer(){
    const pending=request?.status==='pending',incoming=pending&&Number(request.recipient_user_id)===currentUserId;
    requestActions.hidden=!ready||!incoming;
    composer.hidden=!ready||(!activeId&&!activeTarget)||pending||request?.status==='declined';
    sendButton.disabled=sending.has(draftKey())||!ready;
    if(historyButton)historyButton.hidden=!ready||!hasOlder;
  }
  function applyConversation(result,mode){
    const conversation=result.conversation||{};request=result.request||null;ready=true;
    const row=[...inbox.conversations,...inbox.requests,...inbox.teams].find(value=>Number(value.id||value.conversation_id)===activeId);
    title.textContent=row?.other_name||row?.requester_name||row?.title||conversation.title||'Conversation';
    kind.textContent=conversation.conversation_type==='team_general'?'Team General':request?.status==='pending'?'Message Request':'Direct Message';
    if(mode==='reset'||mode==='older')hasOlder=Boolean(result.has_older);
    fetchedThrough=Math.max(fetchedThrough,Number(result.messages?.at(-1)?.id||0));
    mergeMessages(Array.isArray(result.messages)?result.messages:[],mode);
    updateComposer();
    if(request?.status==='pending'&&Number(request.requester_user_id)===currentUserId)notice('Request pending. You can send another message after this member accepts.');
    else if(request?.status==='declined')notice('This message request was declined.');
    else notice();
  }
  async function markRead(id,version){
    if(!current(id,version)||!ready||!visible()||request?.status==='pending')return;
    const through=fetchedThrough;if(!through)return; // Zero means “all” on the service; never mark unseen messages.
    if(through<=(readThrough.get(id)||0))return;
    const receipt=await req('read',{conversation_id:id,through_message_id:through},'POST');
    readThrough.set(id,Math.max(through,readThrough.get(id)||0));
    if(!current(id,version))return;
    for(const group of [inbox.conversations,inbox.teams]){const row=group.find(value=>Number(value.id||value.conversation_id)===id);if(row)row.unread_count=Math.max(0,Number(receipt.unread_count||0));}render();
  }
  async function openConversation(id){
    if(id<1)return false;
    saveDraft();const version=++selection,fetchVersion=++threadRequest;opening=version;activeId=id;activeTarget=0;ready=false;request=null;rows=[];hasOlder=false;fetchedThrough=0;
    textarea.value=drafts.get(draftKey())||'';root.dataset.threadOpen='true';notice();updateComposer();stream.textContent='';stream.hidden=true;empty.hidden=false;empty.innerHTML='<strong>Loading conversation…</strong>';render();
    try{
      const result=await req('conversation',{conversation_id:id});if(!current(id,version)||fetchVersion!==threadRequest)return false;
      applyConversation(result,'reset');await markRead(id,version);return current(id,version);
    }catch(error){if(current(id,version)){if(!ready){empty.hidden=false;empty.innerHTML='<strong>Conversation unavailable</strong><span>Try Refresh to load this conversation again.</span>';}notice(errorText(error));}return false;}
    finally{if(opening===version)opening=0;}
  }
  async function refreshConversation(){
    const id=activeId,version=selection;if(!id||!ready||!visible())return;
    const fetchVersion=++threadRequest;
    for(let page=0;page<4;page++){
      const after=fetchedThrough;let result;
      try{result=await req('conversation',{conversation_id:id,after});}
      catch(error){
        if(!current(id,version))return;
        if(error.status===403||error.status===404){ready=false;rows=[];hasOlder=false;stream.textContent='';stream.hidden=true;empty.hidden=false;empty.innerHTML='<strong>Conversation unavailable</strong>';updateComposer();}
        throw error;
      }
      if(!current(id,version)||fetchVersion!==threadRequest)return;
      applyConversation(result,after?'append':'reset');
      if(!result.has_more)break;
    }
    await markRead(id,version);
  }
  async function refresh(){
    if(accountLocked)return;
    if(refreshing)return refreshing;
    refreshing=(async()=>{await load();if(activeId){if(ready)await refreshConversation();else if(opening!==selection)await openConversation(activeId);}})();
    try{await refreshing;}finally{refreshing=null;}
  }
  async function replyToShare(share){
    const id=Number(share?.conversation_id||0);if(!id)return;
    if(id!==activeId||!ready){if(!await openConversation(id))return;}
    if(composer.hidden)return;
    const label=share?.source?.title||share?.source?.domain||'Browser Share';textarea.value=textarea.value||`Re: ${label}\n`;saveDraft();textarea.focus();
  }
  function showShare(share){
    saveDraft();++selection;++threadRequest;activeId=0;activeTarget=0;ready=false;rows=[];hasOlder=false;root.dataset.threadOpen='true';updateComposer();notice();
    title.textContent=String(share.source?.title||share.source?.domain||'Browser Share');kind.textContent='Browser Share';stream.textContent='';const card=shareCard(share,value=>replyToShare(value));
    if(card)stream.appendChild(card);else stream.textContent='This share could not be displayed. Please refresh the page.';
    stream.hidden=false;empty.hidden=true;render();
  }
  composer.addEventListener('submit',async event=>{
    event.preventDefault();const message=textarea.value.trim(),raw=textarea.value,key=draftKey(),id=activeId,target=activeTarget,version=selection;
    if(!message||!key||!ready||composer.hidden||sending.has(key))return;
    sending.add(key);saveDraft();updateComposer();notice();
    try{
      const result=id?await req('send',{conversation_id:id,message},'POST'):await req('start',{user_id:target,message},'POST');
      if(drafts.get(key)===raw)drafts.delete(key);
      if(selection===version&&draftKey()===key){
        if(textarea.value===raw)textarea.value='';
        if(!id){activeId=Number(result.conversation?.id||0);activeTarget=0;saveDraft();await openConversation(activeId);}
        else if(result.message)mergeMessages([result.message],'sent');
        notice('Message sent.');
      }
      // Refresh failure after a successful send must not invite resending the same text.
      try{await refresh();}catch(error){if(selection===version)notice('Message sent. '+errorText(error));}
    }catch(error){if(selection===version&&draftKey()===key)notice(errorText(error));}
    finally{sending.delete(key);updateComposer();}
  });
  textarea.addEventListener('input',saveDraft);
  root.querySelectorAll('[data-filter]').forEach(button=>button.addEventListener('click',()=>{
    root.querySelectorAll('[data-filter]').forEach(value=>value.classList.toggle('active',value===button));
    root.querySelectorAll('[data-list]').forEach(value=>value.hidden=value.dataset.list!==button.dataset.filter);
    if(button.dataset.filter==='shares')loadShares();
  }));
  q('[data-search]')?.addEventListener('input',render);
  q('[data-back]')?.addEventListener('click',()=>{saveDraft();root.dataset.threadOpen='false';});
  q('[data-refresh]').addEventListener('click',()=>{if(accountLocked){location.reload();return;}notice();loadShares();refresh().catch(error=>notice(errorText(error)));});
  historyButton?.addEventListener('click',async()=>{
    const id=activeId,version=selection,before=Number(rows[0]?.id||0);if(!id||!ready||!hasOlder||!before)return;
    historyButton.disabled=true;
    try{const result=await req('conversation',{conversation_id:id,before});if(current(id,version))applyConversation(result,'older');}
    catch(error){if(current(id,version))notice(errorText(error));}
    finally{historyButton.disabled=false;}
  });
  async function resolveRequest(accept){
    const id=activeId,version=selection;if(!id||request?.status!=='pending'||Number(request.recipient_user_id)!==currentUserId)return;
    requestActions.querySelectorAll('button').forEach(button=>button.disabled=true);
    try{
      await req(accept?'request_accept':'request_decline',{conversation_id:id},'POST');
      if(current(id,version)){
        if(accept)await openConversation(id);
        else{saveDraft();++selection;++threadRequest;activeId=0;activeTarget=0;request=null;ready=false;rows=[];hasOlder=false;stream.hidden=true;stream.textContent='';empty.hidden=false;empty.innerHTML='<strong>Request declined</strong><span>Choose another conversation.</span>';title.textContent='Your messages';kind.textContent='Select a conversation';updateComposer();root.dataset.threadOpen='false';}
      }
      await load();
    }catch(error){if(current(id,version))notice(errorText(error));}
    finally{requestActions.querySelectorAll('button').forEach(button=>button.disabled=false);}
  }
  q('[data-request-accept]').addEventListener('click',()=>resolveRequest(true));
  q('[data-request-decline]').addEventListener('click',()=>resolveRequest(false));
  const dialog=document.querySelector('[data-settings]');
  q('[data-settings-open]').addEventListener('click',()=>{dialog.querySelector('[data-message-policy]').value=settings.message_policy||'friends';dialog.querySelector('[data-friend-requests]').checked=settings.allow_friend_requests!==false;const error=dialog.querySelector('[data-settings-status]');if(error){error.textContent='';error.hidden=true;}dialog.showModal();});
  dialog.querySelector('[data-settings-save]').addEventListener('click',async()=>{
    const button=dialog.querySelector('[data-settings-save]');button.disabled=true;
    try{const result=await req('settings',{message_policy:dialog.querySelector('[data-message-policy]').value,allow_friend_requests:dialog.querySelector('[data-friend-requests]').checked?'1':'0',show_followers:settings.show_followers===false?'0':'1',show_following:settings.show_following===false?'0':'1'},'POST');settings=result.settings||settings;dialog.close();notice('Messaging privacy saved.');}
    catch(error){const message=dialog.querySelector('[data-settings-status]');if(message){message.textContent=errorText(error);message.hidden=false;}else notice(errorText(error));}finally{button.disabled=false;}
  });
  if(activeTarget){kind.textContent='New message';root.dataset.threadOpen='true';}updateComposer();
  const initialSelection=selection;
  load().then(async()=>{
    if(selection!==initialSelection||sending.has(draftKey()))return;
    const initialId=Number(root.dataset.conversationId||0);
    const existing=activeTarget?[...inbox.conversations,...inbox.requests].find(row=>Number(row.other_user_id||row.requester_user_id)===activeTarget):null;
    const id=initialId||Number(existing?.id||existing?.conversation_id||0);
    if(existing&&!initialId&&textarea.value)drafts.set('conversation:'+id,textarea.value);
    if(id)await openConversation(id);
  }).catch(error=>{notice(errorText(error));if(!activeTarget){empty.innerHTML='<strong>Messages unavailable</strong><span>Use Refresh to try again.</span>';}});
  loadShares();
  timer=setInterval(()=>{if(!stopped&&document.visibilityState!=='hidden')refresh().catch(error=>notice(errorText(error)));},10000);
  document.addEventListener('visibilitychange',()=>{if(document.visibilityState!=='hidden'&&!stopped)refresh().catch(error=>notice(errorText(error)));});
  window.addEventListener('pagehide',()=>{stopped=true;clearInterval(timer);});
  window.addEventListener('pageshow',event=>{if(event.persisted)location.reload();});
})();
