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
  const storageKey=`stonefellow-profile-agent:v1:${cfg.username}:${String(cfg.conversationNamespace||'unbound')}`;
  let conversationId=cfg.conversationNamespace?Math.max(0,Number(localStorage.getItem(storageKey)||0)):0;
  let conversationStatus='open';
  let lastMessageId=0;
  let pollTimer=0,presenceTimer=0,countdownTimer=0;
  let handoff=null,clockOffset=0,pollBusy=false,dispatchBusy=false,sending=false,alive=true,sessionChanged=false,requestEpoch=0;
  let formNotice='',lastDispatchAt=0,lastDispatchTurn=0;
  const retryStorageKey=storageKey+':pending';
  const originalSubmitLabel=submit?.textContent||'Send';
  let pendingSend=null;
  try{
    const saved=JSON.parse(sessionStorage.getItem(retryStorageKey)||'null');
    if(saved&&/^[a-f0-9]{32}$/.test(saved.client_request_id||'')&&typeof saved.message==='string'&&saved.message.trim()!==''&&saved.message.length<=4000
       &&Number.isSafeInteger(saved.conversation_id)&&saved.conversation_id>=0)pendingSend=saved;
  }catch(_error){}

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
  const confirmationCards=new Map();
  let confirmationAttempt=0;
  const confirmationKey=action=>`${String(action?.confirm_tool||'')}:${String(action?.intent_id||'')}`;
  function freezeAction(value){
    if(value&&typeof value==='object'){Object.values(value).forEach(freezeAction);Object.freeze(value);}
    return value;
  }
  function previewList(card,rows){
    const list=document.createElement('dl');list.className='profile-agent-confirmation-preview';
    rows.forEach(([label,value])=>{
      const dt=document.createElement('dt');dt.textContent=label;
      const dd=document.createElement('dd');dd.textContent=value;list.append(dt,dd);
    });card.appendChild(list);
  }
  function checkoutReview(card,action){
    const preview=action.preview||{},product=preview.product,terms=preview.terms,provider=preview.payment_provider,intent=action.confirmation?.intent;
    const text=(value,max)=>typeof value==='string'&&value.trim()!==''&&value.length<=max;
    const cents=value=>Number.isSafeInteger(value)&&value>=0;
    const providers={stripe:'Stripe',paypal:'PayPal',square:'Square'};
    if(!product||!terms||!provider||!intent||!text(product.title,500)||!text(product.slug,80)
      ||!Number.isSafeInteger(product.id)||product.id<1||product.id!==intent.product_id||product.slug!==intent.product_slug
      ||terms.payment_mode!=='full'||product.payment_mode!=='full'||!cents(terms.price_cents)||terms.price_cents<1
      ||product.price_cents!==terms.price_cents||typeof terms.currency!=='string'||! /^[a-z]{3}$/i.test(terms.currency)
      ||String(product.currency).toLowerCase()!==terms.currency.toLowerCase()
      ||!cents(terms.cancellation_fee_cents)||terms.cancellation_fee_cents>terms.price_cents
      ||!Number.isSafeInteger(terms.refund_before_hours)||terms.refund_before_hours<0||terms.refund_before_hours>2160
      ||!Number.isSafeInteger(terms.hold_minutes)||terms.hold_minutes<30||terms.hold_minutes>120
      ||typeof terms.cancellation_policy!=='string'||terms.cancellation_policy.length>4000
      ||!Object.hasOwn(providers,provider.provider)||!Number.isSafeInteger(provider.connection_id)||provider.connection_id<1||provider.connection_id!==intent.connection_id
      ||!text(preview.payer_email,190)||preview.payer_email!==intent.payer_email||! /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(preview.payer_email)
      ||preview.seller_terms_acceptance_required!==true||action.confirmation?.requires_terms_acceptance!==true)return false;
    const currency=terms.currency.toUpperCase();
    const money=value=>`${currency} ${(value/100).toFixed(2)}`;
    previewList(card,[['Product',product.title],['Total',money(terms.price_cents)],['Payment provider',providers[provider.provider]],
      ['Receipt email',preview.payer_email],['Payment hold',`${terms.hold_minutes} minutes`],
      ['Refund cutoff',terms.refund_before_hours===0?'No advance cutoff specified (0 hours)':`${terms.refund_before_hours} hours in advance`],
      ['Cancellation fee',money(terms.cancellation_fee_cents)]]);
    const section=document.createElement('section');section.className='profile-agent-checkout-terms';
    const title=document.createElement('strong');title.textContent='Seller cancellation and refund policy';
    const policy=document.createElement('p');policy.textContent=terms.cancellation_policy.trim()||'The seller has not provided additional cancellation or refund terms.';
    section.append(title,policy);card.appendChild(section);return true;
  }
  function confirmationCard(rawAction){
    if(!thread||rawAction?.contract!=='vp3.webmcp.action.v1'||rawAction?.phase!=='prepared'||rawAction?.requires_confirmation!==true)return null;
    const intentId=String(rawAction.intent_id||'');
    if(!/^[a-f0-9]{32}$/.test(intentId)||typeof rawAction.confirm_tool!=='string')return null;
    const action=freezeAction(structuredClone(rawAction)),key=confirmationKey(action);
    const existing=confirmationCards.get(key);
    if(existing&&existing.card.isConnected){
      // A repeated prepare response must not reset a pending or finished review.
      if(JSON.stringify(existing.action)!==JSON.stringify(action)){
        existing.state='reprepare';existing.feedback.textContent='This review changed. Request a new review before confirming.';existing.sync();
      }
      return existing.card;
    }
    const card=document.createElement('section');card.className='profile-agent-confirmation-card';
    card.dataset.webmcpConfirmation=intentId;card.dataset.confirmTool=action.confirm_tool;
    const heading=document.createElement('strong');heading.className='profile-agent-confirmation-title';heading.textContent=String(action.title||'Review action');card.appendChild(heading);
    const intro=document.createElement('p');intro.textContent='Review the prepared action before confirming.';card.appendChild(intro);
    const checkout=action.confirm_tool==='vp3.commerce.checkout.confirm';
    const reviewComplete=checkout?checkoutReview(card,action):true;
    if(!checkout){const rows=safePreviewRows(action.preview||{});if(rows.length)previewList(card,rows);}
    let terms=null;
    if(checkout&&reviewComplete){
      const label=document.createElement('label');label.className='profile-agent-confirmation-terms';
      terms=document.createElement('input');terms.type='checkbox';
      const span=document.createElement('span');span.textContent='I accept the displayed price and seller terms for this checkout.';
      label.append(terms,span);card.appendChild(label);
    }
    const feedback=document.createElement('div');feedback.className='profile-agent-confirmation-feedback';feedback.setAttribute('role','status');card.appendChild(feedback);
    const actions=document.createElement('div');actions.className='profile-agent-confirmation-actions';
    const confirm=document.createElement('button');confirm.type='button';confirm.className='confirm';confirm.textContent=checkout?'Confirm checkout':'Confirm';
    const dismiss=document.createElement('button');dismiss.type='button';dismiss.className='dismiss';dismiss.textContent='Cancel';
    actions.append(confirm,dismiss);card.appendChild(actions);
    const entry={action,card,heading,feedback,confirm,dismiss,terms,reviewComplete,state:'ready',attemptId:'',sync(){
      confirm.disabled=this.state!=='ready'||!reviewComplete||Boolean(terms&&!terms.checked);
      dismiss.disabled=this.state==='pending';
      if(terms)terms.disabled=this.state!=='ready';
      card.setAttribute('aria-busy',this.state==='pending'?'true':'false');
    }};
    confirmationCards.set(key,entry);
    if(!reviewComplete){entry.state='reprepare';feedback.textContent='The full checkout price, provider and seller terms could not be verified. Request a new checkout review.';}
    terms?.addEventListener('change',()=>entry.sync());
    dismiss.addEventListener('click',()=>{
      if(entry.state==='pending'||!card.isConnected)return;
      if(entry.state!=='settled')document.dispatchEvent(new CustomEvent('vp3:webmcp-cancel',{detail:{action}}));
      card.remove();confirmationCards.delete(key);
    });
    confirm.addEventListener('click',()=>{
      if(entry.state!=='ready'||confirm.disabled||!card.isConnected||confirmationCards.get(key)!==entry)return;
      entry.state='pending';entry.attemptId=`review-${++confirmationAttempt}`;feedback.textContent='Confirming…';entry.sync();
      document.dispatchEvent(new CustomEvent('vp3:webmcp-confirm',{detail:{action,attempt_id:entry.attemptId,terms_accepted:terms?.checked===true}}));
    });
    entry.sync();thread.appendChild(card);thread.scrollTop=thread.scrollHeight;return card;
  }
  function safeActionUrl(value,{local=false,receipt=false,orderNumber=''}={}){
    if(typeof value!=='string'||!value||value.length>4096||/[\u0000-\u0020\u007f]/.test(value))return '';
    try{
      const parsed=new URL(value,window.location.href);
      if(parsed.username||parsed.password||!parsed.hostname||!['https:','http:'].includes(parsed.protocol))return '';
      if(parsed.protocol!=='https:'&&parsed.origin!==window.location.origin)return '';
      if(local&&parsed.origin!==window.location.origin)return '';
      if(receipt&&(!parsed.pathname.endsWith('/profile-commerce-order.php')
        ||parsed.searchParams.getAll('username').length!==1||parsed.searchParams.get('username')!==String(cfg.username)
        ||parsed.searchParams.getAll('order').length!==1||parsed.searchParams.get('order')!==orderNumber
        ||parsed.searchParams.getAll('receipt').length!==1||! /^[a-f0-9]{64}$/.test(parsed.searchParams.get('receipt')||'')))return '';
      return parsed.href;
    }catch{return '';}
  }
  function resultLinks(entry,result,outcome){
    const links=document.createElement('div');links.className='profile-agent-confirmation-links';
    const add=(label,value,options={})=>{
      const href=safeActionUrl(value,options);if(!href)return false;
      const link=document.createElement('a');link.textContent=label;link.href=href;link.rel='noopener noreferrer nofollow';
      if(new URL(href).origin!==window.location.origin)link.target='_blank';links.appendChild(link);return true;
    };
    if(entry.action.confirm_tool==='vp3.commerce.checkout.confirm'){
      if(['payment_pending','partially_paid'].includes(outcome.code)&&result.order?.review_required!==true&&result.checkout_status==='open')add('Continue to payment',result.checkout_url);
      add('View receipt',result.receipt_url,{local:true,receipt:true,orderNumber:String(result.order?.order_number||'')});
    }else if(entry.action.confirm_tool.startsWith('vp3.booking.')){
      if(outcome.code==='booking_payment_pending')add('Open booking payment',result.payment_url,{local:true});
      add('View booking',result.manage_url,{local:true});
    }else if(outcome.code==='reward_claim_handoff')add('Open Reward Inbox',result.claim_handoff_url,{local:true});
    if(links.children.length)entry.card.appendChild(links);
  }
  function confirmationResult(detail){
    const key=confirmationKey({intent_id:detail?.intent_id,confirm_tool:detail?.confirm_tool});
    const entry=confirmationCards.get(key);
    if(!entry||!entry.card.isConnected||entry.state==='settled'||entry.state==='reprepare')return;
    if(entry.attemptId&&detail?.attempt_id!==entry.attemptId)return;
    const result=detail?.result||{},outcome=result?.action?.outcome;
    if(result.ok===true){
      const operations={
        'vp3.commerce.checkout.confirm':['commerce.checkout',['payment_pending','partially_paid','paid','processing','fulfilled','payment_review','refunded','partially_refunded','order_expired','order_cancelled','payment_failed']],
        'vp3.commerce.refund.confirm':['commerce.refund_request',['refund_requested','refund_submitted','refund_declined']],
        'vp3.booking.confirm':['booking.create',['booking_created','booking_payment_pending','booking_cancelled']],
        'vp3.booking.reschedule.confirm':['booking.reschedule',['booking_rescheduled','booking_payment_pending','booking_cancelled']],
        'vp3.booking.cancel.confirm':['booking.cancel',['booking_cancelled']],
        'vp3.campaign.participation.confirm':['campaign.participate',['campaign_recorded']],
        'vp3.rewards.claim.confirm':['reward.claim_handoff',['reward_claim_handoff']],
        'vp3.rewards.transfer.confirm':['reward.transfer',['reward_transferred']]
      };
      const expected=operations[entry.action.confirm_tool];
      const valid=outcome?.contract==='vp3.webmcp.confirmation-result.v1'&&expected&&outcome.operation===expected[0]
        &&expected[1].includes(outcome.code)&&typeof outcome.title==='string'&&outcome.title.length<=160
        &&typeof outcome.message==='string'&&outcome.message.length<=1000&&outcome.message.trim()!=='';
      entry.state='settled';entry.dismiss.textContent='Close';
      entry.heading.textContent=valid?outcome.title:'Result unavailable';
      entry.feedback.textContent=valid?outcome.message:'The request returned, but its saved result could not be verified. Check the existing action before starting another.';
      entry.card.classList.add(valid?'is-complete':'has-error');entry.sync();
      if(valid)resultLinks(entry,result,outcome);
      return;
    }
    const code=String(result?.error?.code||'');
    const reprepare=result?.action?.reprepare_required===true||['CONFIRMATION_EXPIRED','CONFIRMATION_MISMATCH','IDEMPOTENCY_CONFLICT'].includes(code);
    const retryable=result?.error?.retryable===true||code==='ACTION_IN_PROGRESS'||code==='TERMS_REQUIRED';
    entry.state=reprepare?'reprepare':(retryable?'ready':'blocked');
    const message=typeof result?.error?.message==='string'?result.error.message:'The result could not be verified. Check the existing action before starting another.';
    entry.feedback.textContent=code==='CONFIRMATION_EXPIRED'?'This review expired. Check any existing order, then request a new review.':message;
    if(reprepare&&code!=='CONFIRMATION_EXPIRED')entry.feedback.textContent+=' Request a new review before confirming.';
    if(retryable&&!reprepare){entry.confirm.textContent='Retry this confirmation';entry.feedback.textContent+=' A retry will use this same prepared action.';}
    entry.dismiss.textContent='Close';entry.card.classList.add('has-error');entry.sync();
  }
  function confirmationStarted(detail){
    const entry=confirmationCards.get(confirmationKey(detail));
    if(!entry||!entry.card.isConnected||entry.state==='pending'||entry.state==='reprepare'
      ||typeof detail?.attempt_id!=='string'||!detail.attempt_id||detail.attempt_id.length>100)return;
    entry.state='pending';entry.attemptId=detail.attempt_id;entry.feedback.textContent='Confirming…';entry.sync();
  }
  document.addEventListener('vp3:webmcp-confirmation',event=>confirmationCard(event.detail));
  document.addEventListener('vp3:webmcp-confirmation-started',event=>confirmationStarted(event.detail));
  document.addEventListener('vp3:webmcp-confirmation-result',event=>confirmationResult(event.detail));

  function messageNode(type,text,id=0){
    const div=document.createElement('div');div.className=`profile-agent-message ${type}`;div.dataset.messageId=String(id||0);
    if(type==='agent'||type==='owner')appendSafeLinkedText(div,text);else div.textContent=String(text||'');
    return div;
  }
  function appendMessage(type,text,id=0){
    if(!String(text||'').trim())return;
    if(id>0&&thread.querySelector(`[data-message-id="${id}"]`)){lastMessageId=Math.max(lastMessageId,id);return;}
    const node=messageNode(type,text,id);thread.appendChild(node);if(id>lastMessageId)lastMessageId=id;thread.scrollTop=thread.scrollHeight;
    return node;
  }
  function renderMessages(messages){
    thread.innerHTML='';lastMessageId=0;
    (messages||[]).forEach(m=>appendMessage(m.sender_type||'agent',m.message||'',Number(m.id||0)));
    if(!messages?.length&&cfg.greeting)appendMessage('agent',cfg.greeting);
  }
  function activeHandoff(){return Boolean(handoff&&['awaiting_delivery','awaiting_owner','agent_ready','agent_running'].includes(String(handoff.dispatch_state||handoff.state||'')));}
  function dispatchPaused(){return handoff?.dispatch_execution_paused===true||(handoff?.dispatch_execution_paused===undefined&&Number(handoff?.dispatch_turn_id||handoff?.turn_id||0)===Number(handoff?.turn_id||0)&&handoff?.execution_paused===true);}
  function showHandoffStatus(){
    if(sessionChanged||sending)return;
    if(formNotice){status.textContent=formNotice;return;}
    if(pendingSend){status.textContent='Your message may already be saved. Retry this same message to check its result.';return;}
    const state=String(handoff?.state||'');
    if(conversationStatus==='resolved')status.textContent='This conversation was resolved. Your next message will reopen it.';
    else if(state==='owner_claimed'||conversationStatus==='owner_joined')status.textContent='The profile owner has joined this conversation.';
    else if(state==='awaiting_delivery')status.textContent='Checking whether the profile owner is available…';
    else if(state==='awaiting_owner'){
      const deadline=Number(handoff.pickup_deadline_ms||handoff.deadline_ms||0);
      const remaining=Math.max(0,Math.ceil((deadline-(Date.now()+clockOffset))/1000));
      status.textContent=remaining>0?`Waiting for the profile owner · ${remaining} seconds`:'The Profile Agent will respond shortly…';
    }else if(state==='agent_ready'||state==='agent_running')status.textContent=handoff.execution_paused===true||dispatchPaused()?`${cfg.agentName||'Profile Agent'} is paused. Waiting for the profile owner to resume.`:`${cfg.agentName||'Profile Agent'} is preparing a reply…`;
    else if(state==='blocked')status.textContent='The Profile Agent could not complete this reply. The profile owner can review the conversation.';
    else if(!pendingSend)status.textContent='';
  }
  function applyConversationState(conversation,nextHandoff=undefined){
    if(handoff&&nextHandoff!==undefined){
      if(!nextHandoff)return;
      const oldId=Number(handoff.turn_id||0),nextId=Number(nextHandoff.turn_id||0);
      if(nextId<oldId||(nextId===oldId&&Number(nextHandoff.revision||0)<Number(handoff.revision||0)))return;
    }
    conversationStatus=String(conversation?.status||'open');
    if(nextHandoff!==undefined){handoff=nextHandoff&&typeof nextHandoff==='object'?nextHandoff:null;const serverNow=Number(handoff?.server_now_ms||0);if(Number.isFinite(serverNow)&&serverNow>0)clockOffset=serverNow-Date.now();}
    showHandoffStatus();
  }
  function resetConversation(){
    requestEpoch++;conversationId=0;conversationStatus='open';lastMessageId=0;handoff=null;localStorage.removeItem(storageKey);clearTimeout(pollTimer);pollTimer=0;
  }
  function stopChangedSession(){
    sessionChanged=true;pendingSend=null;try{sessionStorage.removeItem(retryStorageKey);}catch(_error){}
    resetConversation();renderMessages([]);textarea.disabled=true;submit.disabled=true;
    status.textContent='Your visitor session changed. Refresh this profile before continuing.';
  }
  async function request(action,payload={},method='POST'){
    const epoch=requestEpoch;
    const identity=`${cfg.username}|${cfg.conversationNamespace||''}|${cfg.profileToken||''}`;
    const options={method,credentials:'same-origin',cache:'no-store'};
    let url=cfg.endpoint;
    const body={action,username:cfg.username,conversation_id:conversationId,...(cfg.conversationNamespace?{conversation_namespace:cfg.conversationNamespace}:{}),...payload};
    if(method==='GET'){
      const q=new URLSearchParams(body);url+=`?${q}`;
    }else{
      options.headers={'Content-Type':'application/json'};
      options.body=JSON.stringify({...body,profile_token:cfg.profileToken});
    }
    const r=await fetch(url,options);const data=await r.json().catch(()=>null);
    if(alive&&identity!==`${cfg.username}|${cfg.conversationNamespace||''}|${cfg.profileToken||''}`)stopChangedSession();
    if(!alive||epoch!==requestEpoch||sessionChanged){const error=new Error('This visitor request is no longer current.');error.stale=true;throw error;}
    if(!r.ok||!data?.ok){const error=new Error(data?.error||'Profile Agent is unavailable.');error.status=r.status;error.code=data?.code||'';throw error;}
    return data;
  }
  async function loadState(){
    try{
      const data=await request('state',{},'GET');
      if(data.agent?.name)cfg.agentName=data.agent.name;
      if(Object.prototype.hasOwnProperty.call(data.agent||{},'greeting'))cfg.greeting=data.agent.greeting||'';
      renderMessages(data.messages||[]);applyConversationState(data.conversation||null,data.handoff);
      if(conversationId)startPolling();
      if(pendingSend&&!sessionChanged)void sendSaved(pendingSend);
      else void dispatchIfDue();
    }catch(error){
      if(error.stale)return;
      if(error.code==='PROFILE_SESSION_CHANGED'||error.status===419){stopChangedSession();return;}
      if(conversationId&&error.status===404){resetConversation();renderMessages([]);status.textContent='';return loadState();}
      formNotice=error.message;status.textContent=formNotice;
    }
  }
  async function poll(){
    if(!alive||sessionChanged||!conversationId||pollBusy)return;
    pollBusy=true;
    try{
      const data=await request('poll',{after_id:lastMessageId},'GET');
      (data.messages||[]).forEach(m=>appendMessage(m.sender_type||'agent',m.message||'',Number(m.id||0)));
      applyConversationState(data.conversation||null,data.handoff);
      void dispatchIfDue();
    }catch(error){
      if(!error.stale&&(error.code==='PROFILE_SESSION_CHANGED'||error.status===419)){stopChangedSession();return;}
      if(!error.stale&&error.status===404){pendingSend=null;try{sessionStorage.removeItem(retryStorageKey);}catch(_error){}resetConversation();renderMessages([]);formNotice='The Profile Agent changed. Start a new conversation after refreshing this profile.';status.textContent=formNotice;}
    }finally{pollBusy=false;if(alive&&!sessionChanged&&conversationId)startPolling();}
  }
  function startPolling(){clearTimeout(pollTimer);if(alive&&!sessionChanged&&conversationId)pollTimer=window.setTimeout(poll,activeHandoff()?1000:10000);}
  async function dispatchIfDue(){
    if(!alive||sessionChanged||dispatchBusy||!conversationId||!handoff||dispatchPaused())return;
    const state=String(handoff.dispatch_state||handoff.state||'');
    const turnId=Number(handoff.dispatch_turn_id||handoff.turn_id||0);
    if(!Number.isSafeInteger(turnId)||turnId<1||!['awaiting_delivery','awaiting_owner','agent_ready','agent_running'].includes(state))return;
    const deadline=Number(handoff.dispatch_due_at_ms|| (state==='awaiting_owner'?handoff.pickup_deadline_ms:state==='awaiting_delivery'?handoff.delivery_until_ms:0) ||0);
    if(deadline>Date.now()+clockOffset)return;
    if(lastDispatchTurn===turnId&&Date.now()-lastDispatchAt<1200)return;
    const cid=conversationId;dispatchBusy=true;lastDispatchAt=Date.now();lastDispatchTurn=turnId;
    try{await request('dispatch',{conversation_id:cid,turn_id:turnId});}
    catch(error){if(!error.stale&&(error.code==='PROFILE_SESSION_CHANGED'||error.status===419))stopChangedSession();}
    finally{dispatchBusy=false;if(alive&&!sessionChanged&&cid===conversationId)void poll();}
  }
  async function heartbeat(){if(document.visibilityState!=='visible'||sessionChanged)return;try{await request('state',{},'GET');}catch(error){if(error.code==='PROFILE_SESSION_CHANGED'||error.status===419)stopChangedSession();}}
  function savePending(value){pendingSend=value;try{if(value)sessionStorage.setItem(retryStorageKey,JSON.stringify(value));else sessionStorage.removeItem(retryStorageKey);}catch(_error){}}
  function messageRequestId(){const bytes=new Uint8Array(16);crypto.getRandomValues(bytes);return Array.from(bytes,value=>value.toString(16).padStart(2,'0')).join('');}
  async function sendSaved(saved){
    if(sending||sessionChanged||!alive)return;
    requestEpoch++;sending=true;formNotice='';savePending(saved);let accepted=false;
    let optimistic=thread.querySelector(`[data-client-request-id="${saved.client_request_id}"]`);
    if(!optimistic){optimistic=appendMessage('visitor',saved.message);if(optimistic)optimistic.dataset.clientRequestId=saved.client_request_id;}
    textarea.value='';textarea.disabled=true;submit.disabled=true;status.textContent='Saving your message…';
    try{
      const data=await request('message',saved);
      if(data.client_request_id!==saved.client_request_id||!Number.isSafeInteger(Number(data.visitor_message_id))||Number(data.visitor_message_id)<1)throw new Error('The saved message could not be verified. Retry the same message.');
      conversationId=Number(data.conversation_id||0);if(conversationId)localStorage.setItem(storageKey,String(conversationId));
      const existing=thread.querySelector(`[data-message-id="${Number(data.visitor_message_id)}"]`);
      if(optimistic){if(existing&&existing!==optimistic)optimistic.remove();else optimistic.dataset.messageId=String(data.visitor_message_id);}
      savePending(null);accepted=true;applyConversationState(data.conversation||null,data.handoff);startPolling();
    }catch(error){
      if(error.stale)return;
      if(error.code==='PROFILE_SESSION_CHANGED'||error.status===419){stopChangedSession();return;}
      if(error.status===404){savePending(null);resetConversation();renderMessages([]);formNotice='The Profile Agent changed. Start a new conversation after refreshing this profile.';status.textContent=formNotice;}
      else if([409,422,429].includes(error.status)){
        savePending(null);optimistic?.remove();textarea.value=saved.message;formNotice=error.message;status.textContent=formNotice;
      }else{textarea.value=saved.message;status.textContent='Your message may already be saved. Retry this same message to check its result.';}
    }
    finally{
      sending=false;
      if(alive&&!sessionChanged){textarea.disabled=false;submit.disabled=false;submit.textContent=pendingSend?'Retry message':originalSubmitLabel;textarea.focus();if(accepted){showHandoffStatus();void poll();void dispatchIfDue();}}
    }
  }
  form?.addEventListener('submit',event=>{
    event.preventDefault();const message=String(textarea.value||'').trim();if(!message||sending||sessionChanged)return;
    if(pendingSend){if(message!==pendingSend.message){status.textContent='Retry the saved message first so it is not sent twice.';return;}void sendSaved(pendingSend);return;}
    void sendSaved({message,conversation_id:conversationId,client_request_id:messageRequestId()});
  });
  textarea?.addEventListener('keydown',event=>{if(event.key==='Enter'&&!event.shiftKey){event.preventDefault();form.requestSubmit();}});
  window.addEventListener('pagehide',()=>{alive=false;requestEpoch++;clearTimeout(pollTimer);clearInterval(presenceTimer);clearInterval(countdownTimer);},{once:true});
  loadState();presenceTimer=window.setInterval(heartbeat,60000);countdownTimer=window.setInterval(showHandoffStatus,1000);
})();
