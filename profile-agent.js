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
  const liveChoice=shell.querySelector('[data-profile-live-chat]');
  const liveAvailability=shell.querySelector('[data-profile-live-availability]');
  const ownerLabel=shell.querySelector('[data-profile-owner-name]');
  const endLiveButton=shell.querySelector('[data-profile-end-live]');
  const disclosure=shell.querySelector('[data-profile-agent-disclosure]');
  const addImage=shell.querySelector('[data-profile-chat-add-image]');
  const addFile=shell.querySelector('[data-profile-chat-add-file]');
  const attachmentHost=shell.querySelector('[data-profile-chat-attachments]');
  let attachmentMaxBytes=0,queuedAttachment=null,attachmentAvailabilityChecked=false;
  const presence=shell.querySelector('[data-profile-agent-presence]');
  const contactStatus=shell.querySelector('[data-profile-chat-contact-status]');
  const settings={panel:shell.querySelector('[data-profile-chat-settings]'),button:shell.querySelector('[data-profile-chat-settings-toggle]')};
  const chatOptions={panel:shell.querySelector('[data-profile-chat-options]'),button:shell.querySelector('[data-profile-chat-options-toggle]')};
  const attachmentHelp={panel:shell.querySelector('[data-profile-attachment-help]'),button:null};
  const attachmentHelpTitle=shell.querySelector('[data-profile-attachment-help-title]');
  const attachmentHelpMessage=shell.querySelector('[data-profile-attachment-help-message]');
  const attachmentHelpOptions=shell.querySelector('[data-profile-attachment-help-options]');
  const ownerPreview=Boolean(shell.querySelector('[data-profile-chat-preview]'));
  const extraPanels=[];
  let imageSurface=null;
  let openPanel=null,attachmentHelpKind='image',attachmentHelpDestination='';
  function showPanel(next,focus=true){
    const previous=openPanel;
    if(next&&!next.panel)return;
    openPanel=next;
    for(const entry of [settings,chatOptions,attachmentHelp,...extraPanels]){
      if(entry.panel)entry.panel.hidden=entry!==next;
      entry.button?.setAttribute('aria-expanded',String(entry===next));
    }
    for(const button of [addImage,addFile])button?.setAttribute('aria-expanded',String(next===attachmentHelp&&button===attachmentHelp.button));
    if(thread){thread.hidden=Boolean(next);if(!next)thread.scrollTop=thread.scrollHeight;}
    imageSurface?.onPanelChange?.(next);
    if(focus){if(next)next.panel.querySelector('h2')?.focus();else previous?.button?.focus();}
  }
  async function loadImageCollection(){
    const endpoint=new URL(cfg.endpoint,window.location.href);
    if(endpoint.origin!==window.location.origin)throw new Error('The image collection is unavailable. Reload this profile and try again.');
    endpoint.search=new URLSearchParams({action:'image_collection',username:cfg.username}).toString();
    const response=await fetch(endpoint.href,{credentials:'same-origin',cache:'no-store',headers:{Accept:'application/json'}});
    const data=await response.json().catch(()=>null);
    if(!response.ok||data?.ok!==true)throw new Error(data?.error||'The image collection could not be loaded. Try again.');
    return data;
  }
  function renderAttachmentHelp(kind,info){
    if(attachmentHelpTitle)attachmentHelpTitle.textContent=kind==='image'?'Share an image':'Share a file';
    if(attachmentHelpMessage&&attachmentHelpMessage.textContent!==info.message)attachmentHelpMessage.textContent=info.message;
    if(attachmentHelp.panel)attachmentHelp.panel.dataset.state=info.state;
    attachmentHelpDestination=info.action||'';
    if(attachmentHelpOptions){
      attachmentHelpOptions.hidden=!attachmentHelpDestination;
      attachmentHelpOptions.textContent=info.actionLabel||'Open chat options';
    }
    imageSurface?.attachmentChoice(kind,info.ready===true);
  }
  function openAttachmentHelp(button,kind,info){
    attachmentHelpKind=kind;attachmentHelp.button=button;
    renderAttachmentHelp(kind,info);
    if(attachmentHelp.panel)showPanel(attachmentHelp);
    else if(status){status.dataset.quiet='false';status.textContent=info.message;}
  }
  attachmentHelpOptions?.addEventListener('click',()=>{
    if(attachmentHelpDestination==='settings'){
      showPanel(settings);if(liveChoice&&!liveChoice.disabled)liveChoice.focus();
    }else if(attachmentHelpDestination==='options')showPanel(chatOptions);
    else if(attachmentHelpDestination==='reload')window.location.reload();
  });
  shell.querySelector('[data-profile-attachment-help-close]')?.addEventListener('click',()=>showPanel(null));
  for(const entry of [settings,chatOptions]){
    if(entry.panel)entry.button?.addEventListener('click',()=>showPanel(openPanel===entry?null:entry));
  }
  shell.querySelector('[data-profile-chat-settings-jump]')?.addEventListener('click',()=>{
    showPanel(settings);if(liveChoice&&!liveChoice.disabled)liveChoice.focus();
  });
  if(settings.panel||chatOptions.panel||attachmentHelp.panel){
    shell.addEventListener('keydown',event=>{
      if(event.key!=='Escape'||!openPanel)return;
      event.stopPropagation();showPanel(null);
    });
    window.addEventListener('vp3:profile-agent-visibility',event=>{if(!event.detail?.open)showPanel(null,false);});
  }
  if(ownerPreview){
    const ownerStudio=window.VP3ProfileImageOwnerPreviewV1?.mount?.({shell,thread,form,cfg,showPanel,
      notice:message=>{if(status)status.textContent=message;}});
    for(const [button,kind] of [[addImage,'image'],[addFile,'file']])button?.addEventListener('click',()=>openAttachmentHelp(button,kind,{
      state:'preview',message:'You’re viewing your own profile. Images and files can be shared after you join a visitor’s conversation. Open your Chat to respond to visitors.',action:'options',actionLabel:'Open chat options'
    }));
    if(thread&&form&&window.VP3ProfileImageEditsV1){
      let previewAlive=true;
      imageSurface=window.VP3ProfileImageEditsV1.mount({shell,thread,form,cfg,ownerPreview:true,loadCollection:loadImageCollection,
        context:()=>({key:'profile-collection-preview:'+cfg.username,id:0,active:previewAlive,sessionChanged:false,loading:false,busy:false,human:false}),
        registerPanel:entry=>extraPanels.push(entry),showPanel,onDraft:()=>{},notice:message=>{if(status)status.textContent=message;},
        focusComposer:()=>textarea?.focus({preventScroll:true}),
        onOwnerReference:ownerStudio ? reference=>ownerStudio.select(reference) : null});
      window.addEventListener('pagehide',()=>{previewAlive=false;imageSurface?.reset(true);},{once:true});
      window.addEventListener('pageshow',event=>{if(event.persisted&&!previewAlive)window.location.reload();});
    }
  }
  if(!thread||!form||!textarea||!submit||!status||ownerPreview)return;
  const conversationStorageKey=()=>`stonefellow-profile-agent:v1:${cfg.username}:${String(cfg.conversationNamespace||'unbound')}`;
  let storageKey=conversationStorageKey();
  function savedConversationId(){
    try{const id=Number(localStorage.getItem(storageKey)||0);return Number.isSafeInteger(id)&&id>0?id:0;}
    catch(_error){return 0;}
  }
  function rememberConversation(){
    // Browser storage is an optional convenience. A quota/privacy restriction
    // must not interrupt a confirmed server receipt or disable the current chat.
    try{if(conversationId>0)localStorage.setItem(storageKey,String(conversationId));else localStorage.removeItem(storageKey);}
    catch(_error){}
  }
  let conversationId=cfg.conversationNamespace?savedConversationId():0;
  const hadConversationAtStartup=conversationId>0;
  let conversationStatus='open';
  let lastMessageId=0;
  let pollTimer=0,presenceTimer=0,choiceTimer=0,countdownTimer=0;
  let handoff=null,clockOffset=0,pollBusy=false,dispatchBusy=false,sending=false,alive=true,sessionChanged=false,requestEpoch=0;
  let formNotice='',stateErrorNotice='',lastDispatchAt=0,lastDispatchTurn=0;
  let callSurface=null,callHost=null,callContextEpoch=0,phoneSurface=null,phonePreparing=false;
  let checkoutSurface=null,checkoutHost=null,checkoutCreating=false,checkoutTimer=0;
  let speechPreference=null,speechChoice=null,speechNotice=null,speechBusy=false,speechSequence=0,speechAttempt=null;
  let chat=null,responsePolicy=null,choiceTouched=false,endBusy=false,presenceBusy=false,uncertainEnd=null;
  let retryStorageKey=storageKey+':pending';
  let initialStatePending=true,sessionRefreshing=false,checkoutReturnSeen=false,imageRecoveryBusy=false;
  const submitLabel=submit.querySelector('[data-profile-send-label]')||submit;
  const originalSubmitLabel=submitLabel.textContent||'Send';
  function labelSubmit(label){submitLabel.textContent=label;if(submitLabel!==submit)submit.title=label;}
  function setNotice(message,quiet=false){
    status.dataset.quiet=String(quiet);
    if(status.textContent!==message)status.textContent=message;
  }
  let pendingSend=null;
  try{
    const saved=JSON.parse(sessionStorage.getItem(retryStorageKey)||'null');
    if(saved&&/^[a-f0-9]{32}$/.test(saved.client_request_id||'')&&typeof saved.message==='string'&&saved.message.trim()!==''&&saved.message.length<=4000
       &&Number.isSafeInteger(saved.conversation_id)&&saved.conversation_id>=0
       &&(saved.kind===undefined||(saved.kind==='image_edit'&&!Object.hasOwn(saved,'upload')&&Number.isSafeInteger(saved.catalog_id)&&saved.catalog_id>0
         &&Number.isSafeInteger(saved.image_id)&&saved.image_id>=0&&Number.isSafeInteger(saved.catalog_revision)&&saved.catalog_revision>0
         &&Array.from(saved.message).length<=2000&&((Number.isSafeInteger(saved.base_message_id)&&saved.base_message_id>0)
           ||(!saved.base_message_id&&saved.image_subject&&/^[a-f0-9]{64}$/.test(saved.image_subject.sha256||'')
             &&typeof saved.image_subject.name==='string'&&saved.image_subject.name.length>0&&saved.image_subject.name.length<=255
             &&Number.isSafeInteger(saved.image_subject.bytes)&&saved.image_subject.bytes>0&&saved.image_subject.bytes<=4194304
             &&typeof saved.image_subject.mime==='string'&&saved.image_subject.mime.length<=100))))
       &&(saved.chat_mode===undefined||['auto','agent','live'].includes(saved.chat_mode))
       &&(saved.continue_live===undefined||(typeof saved.continue_live==='boolean'&&(!saved.continue_live||saved.chat_mode==='live'))))pendingSend=saved;
  }catch(_error){}
  if(pendingSend){textarea.value=pendingSend.message;labelSubmit('Retry message');}
  else try{
    const draft=JSON.parse(sessionStorage.getItem(storageKey+':restore-draft')||'null');
    sessionStorage.removeItem(storageKey+':restore-draft');
    if(draft?.conversation_id===conversationId&&typeof draft.message==='string'&&Array.from(draft.message).length<=2000)textarea.value=draft.message;
  }catch(_error){}
  function clearStateError(){if(stateErrorNotice&&formNotice===stateErrorNotice)formNotice='';stateErrorNotice='';}

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

  function attachmentLink(file,id){
    if(!file||typeof file.name!=='string'||!file.name||!Number.isSafeInteger(id)||id<1||!conversationId||!cfg.conversationNamespace)return null;
    const url=new URL(cfg.endpoint,window.location.href);
    if(url.origin!==window.location.origin)return null;
    url.search=new URLSearchParams({action:'attachment',username:cfg.username,conversation_id:conversationId,conversation_namespace:cfg.conversationNamespace,message_id:id});
    const link=document.createElement('a');link.className='profile-agent-message-attachment';
    link.href=url.href;link.download=file.name;link.textContent='Download '+file.name;
    if(['image/jpeg','image/png','image/webp','image/gif'].includes(file.mime)){
      const image=document.createElement('img');image.alt='';image.loading='lazy';
      url.searchParams.set('preview','1');image.src=url.href;
      image.addEventListener('error',()=>image.remove(),{once:true});
      link.prepend(image);
    }
    return link;
  }
  function messageNode(type,text,id=0,attachment=null,imageEdit=null){
    const div=document.createElement('div');div.className=`profile-agent-message ${type}`;div.dataset.messageId=String(id||0);
    const speaker=document.createElement('strong');speaker.className='profile-agent-message-speaker';
    speaker.textContent=type==='visitor'?'You':type==='owner'?(chat?.owner_name||cfg.ownerName||'Profile owner'):type==='system'?'Status':(cfg.agentName||'Profile Agent');
    const body=document.createElement('span');body.className='profile-agent-message-body';
    if(type==='agent'||type==='owner')appendSafeLinkedText(body,text);else body.textContent=String(text||'');
    div.append(speaker,body);
    const link=type==='visitor'?attachmentLink(attachment,id):null;
    if(link)div.appendChild(link);
    imageSurface?.renderMessage(div,imageEdit,id,type);
    return div;
  }
  function promotionNode(promotion){
    if(!promotion||!/^[a-f0-9]{32}$/.test(promotion.key||'')
       ||typeof promotion.headline!=='string'||promotion.headline.length>240
       ||typeof promotion.copy!=='string'||promotion.copy.length>1200
       ||typeof promotion.cta!=='string'||!promotion.cta.trim()||promotion.cta.length>80)return null;
    const imageUrl=safeActionUrl(promotion.image_url,{local:true}),productUrl=safeActionUrl(promotion.product_url,{local:true});
    if(!imageUrl||!productUrl)return null;
    const imageTarget=new URL(imageUrl),productTarget=new URL(productUrl);
    const slug=productTarget.pathname.split('/').pop();
    if(!imageTarget.pathname.endsWith('/profile-promotion-image.php')
       ||imageTarget.hash||[...imageTarget.searchParams].length!==1||imageTarget.searchParams.get('key')!==promotion.key
       ||!slug||!/^[a-z0-9-]{1,80}$/.test(slug)
       ||!productTarget.pathname.endsWith(`/${encodeURIComponent(cfg.username)}/product/${slug}`)
       ||productTarget.search||productTarget.hash)return null;
    const card=document.createElement('article');card.className='profile-product-promotion';card.dataset.profileProductPromotion=promotion.key;
    const image=document.createElement('img');image.alt=(promotion.headline+'. '+promotion.copy).trim();image.loading='lazy';
    image.addEventListener('error',()=>card.remove(),{once:true});image.src=imageUrl;
    const link=document.createElement('a');link.href=productUrl;link.textContent=promotion.cta+' →';
    const clickUrl=safeActionUrl(promotion.click_url,{local:true});
    if(clickUrl){
      const target=new URL(clickUrl),params=target.searchParams;
      const clickPath=imageTarget.pathname.replace(/profile-promotion-image\.php$/,'profile-promotion-click.php');
      if(target.pathname===clickPath&&!target.hash&&[...params].length===5&&params.get('key')===promotion.key
         &&params.get('placement')==='profile_agent'&&params.get('conversation')===String(conversationId)
         &&/^\d{1,12}$/.test(params.get('expires')||'')&&/^[a-f0-9]{64}$/.test(params.get('proof')||''))link.href=clickUrl;
    }
    card.append(image,link);
    return card;
  }
  function refreshPromotionCards(keys){
    if(!Array.isArray(keys))return;
    const active=new Set(keys);
    thread.querySelectorAll('[data-profile-product-promotion]').forEach(card=>{
      if(!active.has(card.dataset.profileProductPromotion))card.remove();
    });
  }
  function appendMessage(type,text,id=0,promotion=null,attachment=null,imageEdit=null){
    if(!String(text||'').trim()&&!attachment&&!imageEdit)return;
    if(id>0&&thread.querySelector(`[data-message-id="${id}"]`)){lastMessageId=Math.max(lastMessageId,id);return;}
    const node=messageNode(type,text,id,attachment,imageEdit);
    const card=type==='agent'?promotionNode(promotion):null;
    if(card)node.append(card);
    const host=checkoutHost?.parentNode===thread?checkoutHost:callHost?.parentNode===thread?callHost:null;
    if(host)thread.insertBefore(node,host);else thread.appendChild(node);
    if(id>lastMessageId)lastMessageId=id;thread.scrollTop=thread.scrollHeight;
    return node;
  }
  function renderMessages(messages){
    // The media host is a stable sibling of messages. Polls must not detach a
    // live room, discard consent controls, or recreate a call connection.
    if(callHost?.parentNode===thread)callHost.remove();
    if(checkoutHost?.parentNode===thread)checkoutHost.remove();
    thread.innerHTML='';lastMessageId=0;
    (messages||[]).forEach(m=>appendMessage(m.sender_type||'agent',m.message||'',Number(m.id||0),m.promotion,m.attachment,m.image_edit));
    if(!messages?.length&&cfg.greeting)appendMessage('agent',cfg.greeting);
    if(checkoutHost)thread.appendChild(checkoutHost);
    if(callHost)thread.appendChild(callHost);
    imageSurface?.renderStatuses();
  }
  function activeHandoff(){return imageSurface?.hasActive()||chat?.media_call_active===true||chat?.phone_call_active===true||Boolean(chat&&['waiting','accepted'].includes(chat.live_status))||Boolean(handoff&&['awaiting_delivery','awaiting_owner','agent_ready','agent_running'].includes(String(handoff.dispatch_state||handoff.state||'')));}
  function dispatchPaused(){return handoff?.dispatch_execution_paused===true||(handoff?.dispatch_execution_paused===undefined&&Number(handoff?.dispatch_turn_id||handoff?.turn_id||0)===Number(handoff?.turn_id||0)&&handoff?.execution_paused===true);}
  function ownerAttachmentAvailable(){
    return attachmentMaxBytes>0&&conversationId>0&&(
      chat?.media_call_active===true||
      (chat?.live_status==='accepted'&&chat?.live_text_available===true)||
      (conversationStatus==='owner_joined'&&chat?.mode==='auto'&&liveChoice?.checked!==false)
    );
  }
  function attachmentInteractionState(){
    const owner=chat?.owner_name||cfg.ownerName||'the profile owner';
    if(!alive||sessionChanged)return {state:'session',message:'Your visitor session changed. Reload this profile before sharing an image or file.',action:'reload',actionLabel:'Reload chat'};
    if(sending)return {state:'sending',message:'Your message is being sent. Wait for it to finish before adding another image or file.'};
    if(pendingSend)return {state:'pending',message:'Retry your saved message before adding another image or file. Any file already attached stays with that message.'};
    if(endBusy||checkoutCreating)return {state:'busy',message:'Another chat action is in progress. Wait for it to finish before adding an image or file.'};
    if(initialStatePending||sessionRefreshing)return {state:'checking',message:'Checking file sharing availability. You can try again when the chat has loaded.'};
    if(!attachmentAvailabilityChecked)return {state:'unknown',message:'File sharing availability could not be checked. Reload this profile to try again.',action:'reload',actionLabel:'Reload chat'};
    if(attachmentMaxBytes<1)return {state:'unavailable',message:'File sharing is unavailable on this profile. You can keep chatting in text.'};
    if(ownerAttachmentAvailable())return {state:'ready',ready:true,message:'File sharing is ready. Use Add image or Add file below to choose what you want to share.'};
    if(chat?.live_status==='waiting')return {state:'waiting',message:`Your live chat request is waiting for ${owner}. Images and files become available when they join.`,action:'settings',actionLabel:'View live chat request'};
    if(!chat)return {state:'unknown',message:'Live chat availability could not be checked. You can keep chatting in text and check Chat options again.',action:'options'};
    if(!chat.live_text_available)return {state:'offline',message:`${owner} is currently unavailable for live chat. Images and files can be shared after they join your conversation.`,action:'options'};
    return {state:'owner_required',message:`Images and files can be shared after ${owner} joins your conversation. Open live chat settings, then send a message to request them.`,action:'settings',actionLabel:'Open live chat settings'};
  }
  function setQueuedAttachment(file){
    if(file)imageSurface?.clearDraft(false);
    queuedAttachment=file;
    if(!attachmentHost)return;
    attachmentHost.replaceChildren();
    attachmentHost.hidden=!file;
    if(!file)return;
    const label=document.createElement('span');label.textContent=file.name;
    const remove=document.createElement('button');remove.type='button';remove.textContent='Remove';
    remove.setAttribute('aria-label','Remove '+file.name);
    remove.addEventListener('click',()=>setQueuedAttachment(null));
    attachmentHost.append(label,remove);
    updateAttachmentControls();
  }
  function updateAttachmentControls(){
    const availability=attachmentInteractionState();
    for(const [button,label] of [[addImage,'Add image'],[addFile,'Add file']]){
      if(!button)continue;
      // These buttons always have a local response; only an authorized human
      // conversation opens a picker. The server still owns upload admission.
      button.disabled=false;
      button.title=availability.ready?label:label+' options';
    }
    if(openPanel===attachmentHelp)renderAttachmentHelp(attachmentHelpKind,availability);
    const remove=attachmentHost?.querySelector('button');
    if(remove)remove.disabled=sending||Boolean(pendingSend);
    imageSurface?.sync();
    labelSubmit(pendingSend?(pendingSend.kind==='image_edit'?'Retry photo edit':'Retry message'):imageSurface?.hasDraft()?'Edit photo':originalSubmitLabel);
  }
  function chooseAttachment(accept,kind,button){
    const availability=attachmentInteractionState();
    if(!availability.ready){openAttachmentHelp(button,kind,availability);return;}
    showPanel(null,false);
    const selectedConversation=conversationId;
    const input=document.createElement('input');input.type='file';input.accept=accept;
    input.addEventListener('change',()=>{
      const file=input.files?.[0];
      if(!file)return;
      const current=attachmentInteractionState();
      if(!current.ready){openAttachmentHelp(button,kind,current);return;}
      if(conversationId!==selectedConversation){openAttachmentHelp(button,kind,{state:'changed',message:'The conversation changed. Choose your image or file again for this conversation.'});return;}
      if(file.size<1||file.size>attachmentMaxBytes){setNotice('Choose a file between 1 byte and 2 MB.');return;}
      formNotice='';setQueuedAttachment(file);setNotice('');
    },{once:true});
    input.click();
  }
  addImage?.addEventListener('click',()=>{
    if(imageSurface?.enabled())openAttachmentHelp(addImage,'image',attachmentInteractionState());
    else chooseAttachment('.jpg,.jpeg,.png,.webp,.gif','image',addImage);
  });
  shell.querySelector('[data-profile-image-share-owner]')?.addEventListener('click',()=>chooseAttachment('.jpg,.jpeg,.png,.webp,.gif','image',addImage));
  addFile?.addEventListener('click',()=>chooseAttachment('.pdf,.txt,.csv','file',addFile));
  function normalizeChat(value){
    if(!value||value.contract!=='vp3.profile-chat-choice.v1'||typeof value.owner_name!=='string'||value.owner_name.length>240
       ||!['auto','agent','live'].includes(value.mode)||!['none','waiting','accepted','declined','expired','unavailable','ended'].includes(value.live_status)
       ||typeof value.live_text_available!=='boolean'||typeof value.can_end_live_chat!=='boolean'
       ||(value.media_call_active!==undefined&&typeof value.media_call_active!=='boolean')
       ||(value.phone_call_active!==undefined&&typeof value.phone_call_active!=='boolean'))return null;
    return {...value,owner_name:value.owner_name.trim()||cfg.ownerName||'the profile owner',media_call_active:value.media_call_active===true,phone_call_active:value.phone_call_active===true};
  }
  function applyResponsePolicy(value){
    if(value===undefined)return;
    if(!value||value.contract!=='vp3.profile-response.v1'||!['user_responses','automated'].includes(value.mode)||typeof value.ready!=='boolean')return;
    const request=value.request;
    if(request!==null&&(!request||!['id','conversation_id','visitor_message_id','receipt_message_id','revision'].every(key=>Number.isSafeInteger(request[key])&&request[key]>0)
      ||request.conversation_id!==conversationId||!Number.isSafeInteger(request.reply_message_id)||request.reply_message_id<0
      ||!['waiting','answered','resolved','delegated'].includes(request.status)
      ||!['user_responses','knowledge_gap','service_unavailable','quota_exhausted'].includes(request.reason)))return;
    const prior=responsePolicy?.request;
    if(prior&&request&&(request.visitor_message_id<prior.visitor_message_id
      ||(request.id===prior.id&&request.revision<prior.revision)))return;
    // A snapshot taken before submission cannot erase a saved request. Its
    // terminal status arrives with the same exact request and a newer revision.
    responsePolicy={...value,request:request===null&&prior?prior:request?{...request}:null};
  }
  function waitingForResponse(){return responsePolicy?.request?responsePolicy.request.status==='waiting':handoff?.state==='owner_requested';}
  function userResponses(){return responsePolicy?.mode==='user_responses';}
  function submittedNotice(){return `Submitted to ${chat?.owner_name||cfg.ownerName||'the profile owner'}. Waiting for a response.`;}
  function phoneHoldNotice(){return userResponses()?'Finish your phone call before submitting a question. Your draft stays here.':'Finish your phone call to continue with the Profile Agent. Your draft stays here.';}
  function updateChatChoice(){
    const activeMedia=chat?.media_call_active===true;
    const activePhone=chat?.phone_call_active===true;
    const liveConnected=chat?.live_status==='accepted'&&chat?.live_text_available===true;
    const ownerContinuation=!activeMedia&&conversationStatus==='owner_joined'&&chat?.mode==='auto';
    const activeLive=Boolean(chat&&['waiting','accepted'].includes(chat.live_status));
    const owner=chat?.owner_name||cfg.ownerName||'the profile owner';
    if(ownerLabel)ownerLabel.textContent=owner;
    if(liveChoice){
      if(activeMedia||activePhone)liveChoice.checked=false;
      else if(activeLive)liveChoice.checked=true;
      else if(ownerContinuation&&!pendingSend&&!choiceTouched)liveChoice.checked=true;
      else if(pendingSend)liveChoice.checked=pendingSend.chat_mode==='live';
      else if(!ownerContinuation&&!chat?.live_text_available)liveChoice.checked=false;
      liveChoice.disabled=!alive||sessionChanged||sending||endBusy||Boolean(pendingSend)||activeMedia||activePhone||activeLive||(!ownerContinuation&&chat?.live_text_available!==true);
    }
    if(presence){
      const online=activeMedia||liveConnected||chat?.live_text_available;
      let label='Checking live availability…';
      if(sessionChanged)label='Session expired';
      else if(activeMedia)label=`On a call with ${owner}`;
      else if(activePhone)label='Phone call in progress';
      else if(liveConnected)label=`Live with ${owner}`;
      else if(waitingForResponse())label=`Waiting for a response from ${owner}`;
      else if(ownerContinuation&&liveChoice?.checked===false)label=userResponses()?`Next message is submitted to ${owner}`:'Next message goes to the Profile Agent';
      else if(conversationStatus==='owner_joined')label=`${owner} is handling this conversation`;
      else if(chat?.live_status==='waiting')label=`Waiting for ${owner}`;
      else if(liveChoice?.checked)label=`Next message requests ${owner}`;
      else if(chat)label=online?`${owner} online for live chat`:userResponses()?`Questions are submitted to ${owner}`:`${owner} unavailable for live chat`;
      presence.dataset.state=sessionChanged?'unknown':online?'online':chat?'offline':'unknown';
      if(presence.textContent!==label)presence.textContent=label;
    }
    let availability='Checking live chat availability…';
    if(sessionChanged)availability='Refresh this profile to check live chat availability.';
    else if(activeMedia)availability=`Your direct call with ${owner} has been accepted. Use the call controls to end it.`;
    else if(activePhone)availability=conversationStatus==='owner_joined'?'Your phone call is in progress. You can continue this existing owner conversation.':phoneHoldNotice();
    else if(liveConnected)availability=`Live text chat with ${owner}. Automatic Agent replies are paused.`;
    else if(waitingForResponse())availability=submittedNotice();
    else if(ownerContinuation)availability=liveChoice?.checked===false?(userResponses()?`Your next question will be submitted to ${owner}.`:'Your next message will go to the Profile Agent.'):`${owner} is handling this conversation. Their replies will appear here when sent.`;
    else if(activeLive)availability=chat.live_status==='accepted'?`Live chat availability changed. Waiting for ${owner}.`:`Live chat requested from ${owner}. You can cancel while waiting.`;
    else if(liveChoice?.checked)availability=`Your next message will request a live chat with ${owner}.`;
    else if(chat)availability=chat.live_text_available?`${owner} is available. Select live chat, then send a message to request it.`:userResponses()?`Your questions will be submitted to ${owner}. Replies will appear in this conversation.`:`${owner} is unavailable for live chat. You can chat with the Agent.`;
    for(const item of [liveAvailability,contactStatus])if(item&&item.textContent!==availability)item.textContent=availability;
    if(endLiveButton){
      endLiveButton.hidden=activeMedia||activePhone||!chat?.can_end_live_chat||!activeLive;
      endLiveButton.disabled=activeMedia||activePhone||!alive||sessionChanged||sending||endBusy||Boolean(pendingSend);
      endLiveButton.textContent=endBusy?'Ending live request…':chat?.live_status==='waiting'?'Cancel live request':userResponses()?'End live chat':'End live chat · return to Agent';
    }
    if(disclosure)disclosure.textContent=activeMedia?`This conversation is reserved for your direct call with ${owner}. The Profile Agent is paused.`:activePhone?'Profile Agent replies are paused during your phone call.':liveConnected?`You are chatting live with ${owner}. The Profile Agent is paused.`:conversationStatus==='owner_joined'?`${owner} is handling this conversation. Replies appear here when sent.`:userResponses()?`Questions are submitted to ${owner} for a response.`:'Profile Agent replies use information approved for this profile. Questions it cannot answer are submitted to the owner.';
    for(const message of thread.querySelectorAll('.profile-agent-message.owner')){
      const label=message.querySelector('.profile-agent-message-speaker');if(label)label.textContent=owner;
    }
    updateAttachmentControls();
  }
  function applyChat(value){
    if(value===undefined)return;
    const prior=chat?.live_status,priorMode=chat?.mode,priorMedia=chat?.media_call_active,priorPhone=chat?.phone_call_active;chat=normalizeChat(value);
    if(priorPhone&&!chat?.phone_call_active&&formNotice===phoneHoldNotice())formNotice='';
    if((chat?.media_call_active===true&&priorMedia!==true)||(chat?.mode==='auto'&&priorMode!=='auto'&&conversationStatus==='owner_joined'))choiceTouched=false;
    if(liveChoice&&!pendingSend){
      if(chat&&['waiting','accepted'].includes(chat.live_status))liveChoice.checked=true;
      else if(prior&&['waiting','accepted'].includes(prior)&&!['waiting','accepted'].includes(chat?.live_status)){liveChoice.checked=false;choiceTouched=false;}
      else if(!choiceTouched)liveChoice.checked=false;
    }
    updateChatChoice();
  }
  function showHandoffStatus(){
    if(sessionChanged||sending)return;
    updateChatChoice();
    if(formNotice){setNotice(formNotice);return;}
    if(pendingSend){setNotice('Your message may already be saved. Retry this same message to check its result.');return;}
    const state=String(handoff?.state||'');
    if(chat?.media_call_active===true){setNotice(`The Profile Agent is paused for your direct call. You can keep messaging ${chat.owner_name} here.`,true);return;}
    if(chat?.phone_call_active===true){setNotice(conversationStatus==='owner_joined'?'The Profile Agent is paused during your phone call. You can continue this existing owner conversation.':phoneHoldNotice(),true);return;}
    if(waitingForResponse()){setNotice(submittedNotice(),true);return;}
    if(chat?.live_status==='accepted'&&chat?.live_text_available===true){setNotice(`You’re chatting live with ${chat.owner_name}.`,true);return;}
    if(conversationStatus==='resolved')setNotice('This conversation was resolved. Your next message will reopen it.',true);
    else if(state==='owner_claimed'||conversationStatus==='owner_joined')setNotice('The profile owner is handling this conversation. Replies will appear here when sent.',true);
    else if(state==='awaiting_delivery')setNotice('Checking whether the profile owner is available…',true);
    else if(state==='awaiting_owner'){
      const deadline=Number(handoff.pickup_deadline_ms||handoff.deadline_ms||0);
      const remaining=Math.max(0,Math.ceil((deadline-(Date.now()+clockOffset))/1000));
      setNotice(remaining>0?`Waiting for the profile owner · ${remaining} seconds`:'The Profile Agent will respond shortly…',true);
    }else if(state==='agent_ready'||state==='agent_running'){
      const outcome=({declined:'The owner declined the live chat. ',expired:'The live request timed out. ',unavailable:'Live chat is unavailable. ',ended:'The live chat ended. '})[chat?.live_status]||'';
      setNotice(outcome+(handoff.execution_paused===true||dispatchPaused()?`${cfg.agentName||'Profile Agent'} is paused. Waiting for the profile owner to resume.`:`${cfg.agentName||'Profile Agent'} is preparing a reply…`),true);
    }
    else if(state==='blocked')setNotice('The Profile Agent could not complete this reply. The profile owner can review the conversation.');
    else{
      const outcome=({declined:'The owner declined the live chat.',expired:'The live request timed out.',unavailable:'Live chat is unavailable.',ended:'The live chat ended.'})[chat?.live_status];
      setNotice(outcome?outcome+(userResponses()?' Questions are submitted to the owner for a response.':' You can continue with the Agent.'):'',true);
    }
  }
  function applyConversationState(conversation,nextHandoff=undefined,nextChat=undefined,nextResponse=undefined){
    if(handoff&&nextHandoff!==undefined){
      if(!nextHandoff)return;
      const oldId=Number(handoff.turn_id||0),nextId=Number(nextHandoff.turn_id||0);
      if(nextId<oldId||(nextId===oldId&&Number(nextHandoff.revision||0)<Number(handoff.revision||0)))return;
    }
    conversationStatus=String(conversation?.status||'open');
    if(nextHandoff!==undefined){handoff=nextHandoff&&typeof nextHandoff==='object'?nextHandoff:null;const serverNow=Number(handoff?.server_now_ms||0);if(Number.isFinite(serverNow)&&serverNow>0)clockOffset=serverNow-Date.now();}
    applyResponsePolicy(nextResponse);
    applyChat(nextChat);
    if(uncertainEnd&&conversationId===uncertainEnd.conversation_id
       &&Number(handoff?.turn_id)===uncertainEnd.turn_id&&Number(handoff?.revision)>uncertainEnd.revision
       &&chat&&!chat.can_end_live_chat&&['ended','declined','expired','unavailable'].includes(chat.live_status)){
      if(formNotice===uncertainEnd.notice)formNotice='';
      uncertainEnd=null;
    }
    showHandoffStatus();
  }
  function resetConversation(){
    if(queuedAttachment||pendingSend?.upload)textarea.value='';
    setQueuedAttachment(null);
    imageSurface?.reset();
    callSurface?.invalidate('The visitor conversation changed.');phoneSurface?.invalidate('The visitor conversation changed. Your microphone is off.');checkoutSurface?.reset();callContextEpoch++;
    requestEpoch++;conversationId=0;conversationStatus='open';lastMessageId=0;handoff=null;chat=null;responsePolicy=null;choiceTouched=false;uncertainEnd=null;rememberConversation();clearTimeout(pollTimer);pollTimer=0;updateChatChoice();
  }
  function invalidVisitorSession(error){
    return error.status===419||['PROFILE_SESSION_CHANGED','OWNER_VISITOR_PREVIEW_REQUIRED'].includes(error.code);
  }
  function stopChangedSession(){
    if(sessionChanged)return;
    sessionChanged=true;pendingSend=null;try{sessionStorage.removeItem(retryStorageKey);}catch(_error){}
    resetConversation();renderMessages([]);textarea.value='';textarea.disabled=true;submit.disabled=true;
    if(speechChoice)speechChoice.disabled=true;
    setNotice('');
    const notice=document.createElement('span');
    notice.className='profile-agent-visually-hidden';notice.textContent='Your visitor session changed.';
    const reload=document.createElement('button');
    reload.type='button';reload.textContent='Reload chat';
    reload.addEventListener('click',()=>window.location.reload());
    status.append(notice,reload);
  }
  function canRefreshInitialSession(){
    if(!initialStatePending||!alive||sessionChanged||hadConversationAtStartup||conversationId||pendingSend||sending||textarea.value||checkoutReturnSeen)return false;
    try{return !sessionStorage.getItem(storageKey+':checkout-prepare');}catch(_error){return false;}
  }
  async function refreshInitialSession(){
    // A stale page can rebind only before it has any visitor content to carry across identities.
    if(sessionRefreshing||!canRefreshInitialSession())return false;
    sessionRefreshing=true;
    textarea.disabled=true;submit.disabled=true;
    const binding=await request('visitor_session',{},'GET');
    if(!canRefreshInitialSession()||!/^[a-f0-9]{64}$/.test(binding.conversation_namespace||'')||!/^[a-f0-9]{48}$/.test(binding.profile_token||''))return false;
    requestEpoch++;callContextEpoch++;
    cfg.conversationNamespace=binding.conversation_namespace;
    cfg.profileToken=binding.profile_token;
    storageKey=conversationStorageKey();
    retryStorageKey=storageKey+':pending';
    return true;
  }
  async function request(action,payload={},method='POST',endpoint=cfg.endpoint,upload=null){
    const independentRequest=Boolean((cfg.callEndpoint&&endpoint===cfg.callEndpoint)||(cfg.browserPhoneEndpoint&&endpoint===cfg.browserPhoneEndpoint)||(cfg.speechEndpoint&&endpoint===cfg.speechEndpoint)||(cfg.checkoutEndpoint&&endpoint===cfg.checkoutEndpoint));
    const epoch=independentRequest?callContextEpoch:requestEpoch;
    const identity=`${cfg.username}|${cfg.conversationNamespace||''}|${cfg.profileToken||''}`;
    const options={method,credentials:'same-origin',cache:'no-store'};
    if(cfg.browserPhoneEndpoint&&endpoint===cfg.browserPhoneEndpoint&&action==='end'&&method==='POST')options.keepalive=true;
    let url=endpoint;
    const body=action==='visitor_session'?{action,username:cfg.username}:{action,username:cfg.username,conversation_id:conversationId,...(cfg.conversationNamespace?{conversation_namespace:cfg.conversationNamespace}:{}),...payload};
    if(method==='GET'){
      const q=new URLSearchParams(body);url+=`?${q}`;
    }else if(action==='image_edit'){
      options.body=new FormData();
      options.body.append('image_edit_payload',JSON.stringify({...body,profile_token:cfg.profileToken}));
      if(upload)options.body.append('profile_image',upload,upload.name);
    }else if(upload){
      options.body=new FormData();
      options.body.append('attachment_payload',JSON.stringify({...body,profile_token:cfg.profileToken}));
      options.body.append('profile_attachment',upload,upload.name);
    }else{
      options.headers={'Content-Type':'application/json'};
      options.body=JSON.stringify({...body,profile_token:cfg.profileToken});
    }
    // Bound both response headers and body. The server may still finish an
    // admitted operation, so a timed-out send retains its exact retry intent.
    const controller=typeof AbortController==='function'?new AbortController():null;
    if(controller)options.signal=controller.signal;
    let timer=0,r,data;
    const read=async()=>{const response=await fetch(url,options);return [response,await response.json().catch(()=>null)];};
    try{
      [r,data]=typeof window.setTimeout==='function'?await Promise.race([read(),new Promise((_,reject)=>{
        timer=window.setTimeout(()=>{controller?.abort();reject(new Error('Profile chat connection timed out.'));},upload?45000:12000);
      })]):await read();
    }finally{if(timer)clearTimeout(timer);}
    if(alive&&identity!==`${cfg.username}|${cfg.conversationNamespace||''}|${cfg.profileToken||''}`)stopChangedSession();
    if(!alive||epoch!==(independentRequest?callContextEpoch:requestEpoch)||sessionChanged){const error=new Error('This visitor request is no longer current.');error.stale=true;throw error;}
    if(!r.ok||!data?.ok){const error=new Error(data?.error||'Profile Agent is unavailable.');error.status=r.status;error.code=data?.code||'';throw error;}
    return data;
  }
  async function loadState(){
    try{
      let data=null;
      if(checkoutSurface){
        const profileUrl=new URL(window.location.href);
        const returnIds=profileUrl.searchParams.getAll('profile_checkout');
        if(returnIds.length){
          checkoutReturnSeen=true;
          profileUrl.searchParams.delete('profile_checkout');
          window.history.replaceState(window.history.state,'',profileUrl);
          const returned=Number(returnIds[0]);
          if(returnIds.length===1&&/^[1-9][0-9]*$/.test(returnIds[0])&&Number.isSafeInteger(returned)){
            try{
              const candidate=await request('state',{conversation_id:returned},'GET');
              if(candidate.conversation?.conversation_id===returned){
                if(conversationId!==returned)checkoutSurface.reset();
                if(pendingSend&&pendingSend.conversation_id!==returned){
                  savePending(null);labelSubmit(originalSubmitLabel);
                  formNotice='Review your previous draft before sending it in this conversation.';
                }
                conversationId=returned;rememberConversation();data=candidate;
                if(shell.hidden)document.querySelector('[data-open-profile-agent]')?.click();
              }
            }catch(error){if(error.stale||invalidVisitorSession(error))throw error;}
          }
        }
      }
      data??=await request('state',{},'GET');
      if(data.agent?.name)cfg.agentName=data.agent.name;
      if(Object.prototype.hasOwnProperty.call(data.agent||{},'greeting'))cfg.greeting=data.agent.greeting||'';
      attachmentMaxBytes=Number.isSafeInteger(data.attachment_max_bytes)?Math.max(0,data.attachment_max_bytes):0;
      attachmentAvailabilityChecked=true;
      clearStateError();
      imageSurface?.update(data);
      renderMessages(data.messages||[]);refreshPromotionCards(data.active_promotion_keys);applyConversationState(data.conversation||null,data.handoff,data.chat,data.response);
      if(conversationId){startPolling();void checkoutSurface?.refresh();}
      if(pendingSend&&!sessionChanged)void sendSaved(pendingSend);
      else void dispatchIfDue();
    }catch(error){
      if(error.stale)return;
      if(invalidVisitorSession(error)){
        if(error.code!=='OWNER_VISITOR_PREVIEW_REQUIRED'){
          try{if(await refreshInitialSession())return loadState();}catch(refreshError){if(refreshError.stale)return;}
        }
        stopChangedSession();return;
      }
      if(conversationId&&error.status===404){resetConversation();renderMessages([]);setNotice('');return loadState();}
      stateErrorNotice=error.message;formNotice=stateErrorNotice;setNotice(formNotice);
    }
  }
  async function poll(){
    if(!alive||sessionChanged||!conversationId||pollBusy)return;
    pollBusy=true;
    try{
      const data=await request('poll',{after_id:lastMessageId},'GET');
      clearStateError();
      (data.messages||[]).forEach(m=>appendMessage(m.sender_type||'agent',m.message||'',Number(m.id||0),m.promotion,m.attachment,m.image_edit));
      imageSurface?.update(data);
      refreshPromotionCards(data.active_promotion_keys);
      applyConversationState(data.conversation||null,data.handoff,data.chat,data.response);
      void dispatchIfDue();
    }catch(error){
      if(!error.stale&&invalidVisitorSession(error)){stopChangedSession();return;}
      if(!error.stale&&error.status===404){pendingSend=null;try{sessionStorage.removeItem(retryStorageKey);}catch(_error){}resetConversation();renderMessages([]);formNotice='The Profile Agent changed. Start a new conversation after refreshing this profile.';status.textContent=formNotice;}
    }finally{pollBusy=false;if(alive&&!sessionChanged&&conversationId)startPolling();}
  }
  function startPolling(){clearTimeout(pollTimer);if(alive&&!sessionChanged&&conversationId)pollTimer=window.setTimeout(poll,activeHandoff()?1000:10000);}
  async function dispatchIfDue(){
    if(!alive||sessionChanged||dispatchBusy||!conversationId||!handoff||dispatchPaused()||chat?.media_call_active===true||chat?.phone_call_active===true)return;
    const state=String(handoff.dispatch_state||handoff.state||'');
    const turnId=Number(handoff.dispatch_turn_id||handoff.turn_id||0);
    const needsMaintenance=chat?.live_status==='accepted'&&chat.live_text_available===false;
    if(!Number.isSafeInteger(turnId)||turnId<1||(!needsMaintenance&&!['awaiting_delivery','awaiting_owner','agent_ready','agent_running'].includes(state)))return;
    const deadline=Number(handoff.dispatch_due_at_ms|| (state==='awaiting_owner'?handoff.pickup_deadline_ms:state==='awaiting_delivery'?handoff.delivery_until_ms:0) ||0);
    if(deadline>Date.now()+clockOffset)return;
    if(lastDispatchTurn===turnId&&Date.now()-lastDispatchAt<1200)return;
    const cid=conversationId;dispatchBusy=true;lastDispatchAt=Date.now();lastDispatchTurn=turnId;
    try{await request('dispatch',{conversation_id:cid,turn_id:turnId});}
    catch(error){if(!error.stale&&invalidVisitorSession(error))stopChangedSession();}
    finally{dispatchBusy=false;if(alive&&!sessionChanged&&cid===conversationId)void poll();}
  }
  async function heartbeat(){
    if(initialStatePending||document.visibilityState!=='visible'||sessionChanged||sending||endBusy||presenceBusy||!alive)return;
    presenceBusy=true;
    try{
      const data=await request('state',{},'GET');
      attachmentMaxBytes=Number.isSafeInteger(data.attachment_max_bytes)?Math.max(0,data.attachment_max_bytes):0;
      attachmentAvailabilityChecked=true;
      if(stateErrorNotice){renderMessages(data.messages||[]);refreshPromotionCards(data.active_promotion_keys);clearStateError();if(conversationId)startPolling();}
      imageSurface?.update(data);
      applyConversationState(data.conversation||null,data.handoff,data.chat,data.response);void dispatchIfDue();
    }
    catch(error){if(!error.stale&&invalidVisitorSession(error))stopChangedSession();}
    finally{presenceBusy=false;}
  }
  function savePending(value){
    pendingSend=value;
    try{
      if(value?.kind==='image_edit'){
        const {upload,...saved}=value;sessionStorage.setItem(retryStorageKey,JSON.stringify(saved));
      }else if(value&&!value.upload)sessionStorage.setItem(retryStorageKey,JSON.stringify(value));
      else sessionStorage.removeItem(retryStorageKey);
      return true;
    }catch(_error){return false;}
  }
  function messageRequestId(){const bytes=new Uint8Array(16);crypto.getRandomValues(bytes);return Array.from(bytes,value=>value.toString(16).padStart(2,'0')).join('');}
  function verifyImageReceipt(data,saved){
    if(data.client_request_id!==saved.client_request_id||!Number.isSafeInteger(Number(data.visitor_message_id))||Number(data.visitor_message_id)<1
      ||!data.image_edit||data.image_edit.kind!=='input'||!/^[a-f0-9]{32}$/.test(data.image_edit.request_id||'')
      ||data.image_edit.source_message_id!==Number(data.visitor_message_id)||!Number.isSafeInteger(data.conversation_id)||data.conversation_id<1
      ||(saved.conversation_id>0&&data.conversation_id!==saved.conversation_id)
      ||!Array.isArray(data.image_edits)||!data.image_edits.some(job=>job.request_id===data.image_edit.request_id&&job.input_message_id===Number(data.visitor_message_id)))
      throw new Error('The saved photo edit could not be verified. Retry the same photo edit.');
  }
  async function recoverImagePending(saved){
    if(imageRecoveryBusy||sending||!alive||sessionChanged||pendingSend?.client_request_id!==saved.client_request_id)return;
    imageRecoveryBusy=true;textarea.disabled=true;submit.disabled=true;setNotice('Checking your saved photo edit…');updateAttachmentControls();
    try{
      const data=await request('image_edit_receipt',{client_request_id:saved.client_request_id},'GET');
      if(pendingSend?.client_request_id!==saved.client_request_id)return;
      if(data.client_request_id!==saved.client_request_id||typeof data.found!=='boolean')throw new Error('The saved photo edit could not be checked. Retry to check it again.');
      if(data.found){
        verifyImageReceipt(data,saved);conversationId=data.conversation_id;rememberConversation();
        savePending(null);textarea.value='';formNotice='';imageSurface?.accepted();imageSurface?.update(data);
        renderMessages(data.messages||[]);applyConversationState(data.conversation||null,data.handoff,data.chat,data.response);startPolling();
        void dispatchIfDue();
      }else{
        imageSurface?.recover(saved,async file=>{
          if(pendingSend?.client_request_id!==saved.client_request_id||!alive||sessionChanged)return;
          const restored={...saved,upload:file};savePending(restored);await sendSaved(restored);
        });
        formNotice='Choose the same original photo to retry your saved edit. Its instructions and request are preserved.';setNotice(formNotice);
      }
    }catch(error){
      if(error.stale)return;
      if(invalidVisitorSession(error)){stopChangedSession();return;}
      formNotice='Your photo edit could not be checked yet. Retry to check its saved result before sending another edit.';setNotice(formNotice);
    }finally{
      imageRecoveryBusy=false;if(alive&&!sessionChanged){textarea.disabled=false;submit.disabled=false;updateAttachmentControls();}
    }
  }
  async function sendSaved(saved){
    if(sending||imageRecoveryBusy||checkoutCreating||sessionChanged||!alive)return;
    if(saved.kind==='image_edit'&&saved.image_subject&&!saved.upload)return recoverImagePending(saved);
    showPanel(null,false);requestEpoch++;sending=true;formNotice='';uncertainEnd=null;
    if(!savePending(saved)&&saved.kind==='image_edit'){
      sending=false;formNotice='Your browser could not save this photo edit for retry. Keep this page open, allow session storage, then retry.';setNotice(formNotice);updateChatChoice();return;
    }
    updateChatChoice();let accepted=false;
    let optimistic=thread.querySelector(`[data-client-request-id="${saved.client_request_id}"]`);
    if(!optimistic&&!saved.upload&&saved.kind!=='image_edit'){optimistic=appendMessage('visitor',saved.message);if(optimistic)optimistic.dataset.clientRequestId=saved.client_request_id;}
    textarea.value='';textarea.disabled=true;submit.disabled=true;setNotice('Saving your message…',true);
    try{
      const {upload,kind,image_subject,...intent}=saved;
      const data=await request(kind==='image_edit'?'image_edit':'message',intent,'POST',cfg.endpoint,upload);
      if(data.client_request_id!==saved.client_request_id||!Number.isSafeInteger(Number(data.visitor_message_id))||Number(data.visitor_message_id)<1)throw new Error('The saved message could not be verified. Retry the same message.');
      if(kind==='image_edit')verifyImageReceipt(data,saved);
      if(upload&&kind!=='image_edit'&&!data.attachment)throw new Error('The saved attachment could not be verified. Retry the same message.');
      conversationId=Number(data.conversation_id||0);rememberConversation();
      const existing=thread.querySelector(`[data-message-id="${Number(data.visitor_message_id)}"]`);
      if(optimistic){if(existing&&existing!==optimistic)optimistic.remove();else optimistic.dataset.messageId=String(data.visitor_message_id);}
      if(kind==='image_edit'){
        appendMessage('visitor',saved.message,Number(data.visitor_message_id),null,null,data.image_edit);
        imageSurface?.accepted();imageSurface?.update(data);
      }else if(upload){
        appendMessage('visitor',saved.message,Number(data.visitor_message_id),null,data.attachment);
        setQueuedAttachment(null);
      }
      savePending(null);accepted=true;applyConversationState(data.conversation||null,data.handoff,data.chat,data.response);startPolling();
      checkoutSurface?.showOffers(data.checkout_products);
      if(saved.conversation_id===0)void checkoutSurface?.refresh();
    }catch(error){
      if(error.stale)return;
      if(invalidVisitorSession(error)){stopChangedSession();return;}
      if(error.status===404){savePending(null);resetConversation();renderMessages([]);formNotice='The Profile Agent changed. Start a new conversation after refreshing this profile.';setNotice(formNotice);}
      else if([400,403,409,413,422,429].includes(error.status)){
        savePending(null);if(saved.kind==='image_edit')imageSurface?.rejected(saved);optimistic?.remove();textarea.value=saved.message;formNotice=error.code==='HANDOFF_MEDIA_ACTIVE'&&(chat?.phone_call_active||phoneSurface?.isBusy())?phoneHoldNotice():error.message;setNotice(formNotice);
      }else{textarea.value=saved.message;setNotice('Your message may already be saved. Retry this same message to check its result.');}
    }
    finally{
      sending=false;
      if(alive&&!sessionChanged){textarea.disabled=false;submit.disabled=false;labelSubmit(pendingSend?'Retry message':originalSubmitLabel);updateChatChoice();if(!openPanel)textarea.focus();if(accepted){showHandoffStatus();void poll();void dispatchIfDue();}}
    }
  }
  async function endLiveChat(){
    if(!alive||sessionChanged||sending||endBusy||pendingSend||chat?.media_call_active===true||chat?.phone_call_active===true||chat?.can_end_live_chat!==true||!conversationId)return;
    const turnId=Number(handoff?.turn_id||0),revision=Number(handoff?.revision||0),cid=conversationId;
    if(!Number.isSafeInteger(turnId)||turnId<1||!Number.isSafeInteger(revision)||revision<1)return;
    requestEpoch++;endBusy=true;formNotice='';uncertainEnd=null;textarea.disabled=true;submit.disabled=true;updateChatChoice();
    try{
      const data=await request('end_live_chat',{conversation_id:cid,turn_id:turnId,revision});
      if(Number(data.conversation?.conversation_id||data.conversation_id)!==cid||!normalizeChat(data.chat))throw new Error('The live chat result could not be confirmed.');
      applyConversationState(data.conversation||null,data.handoff,data.chat,data.response);
      if(liveChoice){liveChoice.checked=false;choiceTouched=false;}
      startPolling();void dispatchIfDue();
    }catch(error){
      if(error.stale)return;
      if(invalidVisitorSession(error)){stopChangedSession();return;}
      formNotice=error.status===409?'This live chat changed. Checking the current conversation…':'The live chat result could not be confirmed. Check the current status or try ending it again.';
      if(![400,403,404,409,419,422].includes(error.status))uncertainEnd={conversation_id:cid,turn_id:turnId,revision,notice:formNotice};
      setNotice(formNotice);
      if(error.status===409){formNotice='';void poll();}
    }finally{
      endBusy=false;if(alive&&!sessionChanged){textarea.disabled=false;submit.disabled=false;updateChatChoice();showHandoffStatus();}
    }
  }
  liveChoice?.addEventListener('change',()=>{
    if(liveChoice.disabled){updateChatChoice();return;}
    choiceTouched=true;formNotice='';updateChatChoice();
  });
  endLiveButton?.addEventListener('click',()=>void endLiveChat());
  form?.addEventListener('submit',event=>{
    event.preventDefault();const message=String(textarea.value||'').trim();if((!message&&!queuedAttachment&&!pendingSend?.upload&&!imageSurface?.hasDraft())||sessionRefreshing||sending||imageRecoveryBusy||checkoutCreating||phonePreparing||endBusy||sessionChanged)return;
    if(pendingSend){if(message!==pendingSend.message){setNotice('Retry the saved message first so it is not sent twice.');return;}void sendSaved(pendingSend);return;}
    if(chat?.phone_call_active===true&&conversationStatus!=='owner_joined'){formNotice=phoneHoldNotice();setNotice(formNotice);return;}
    if(imageSurface?.hasDraft()){
      try{void sendSaved({message,conversation_id:conversationId,client_request_id:messageRequestId(),...imageSurface.prepare(message)});}
      catch(error){formNotice=error.message;setNotice(formNotice);}
      return;
    }
    if(queuedAttachment&&!ownerAttachmentAvailable()){formNotice='The profile owner must be in the chat to receive this attachment. You can remove it to send text.';showHandoffStatus();return;}
    const activeMedia=chat?.media_call_active===true||chat?.phone_call_active===true;
    const ownerContinuation=conversationStatus==='owner_joined'&&chat?.mode==='auto'&&liveChoice?.checked!==false;
    const continuing=!activeMedia&&!ownerContinuation&&Boolean(chat&&['waiting','accepted'].includes(chat.live_status));
    const mode=activeMedia||ownerContinuation?'auto':continuing||liveChoice?.checked&&chat?.live_text_available?'live':'agent';
    void sendSaved({message,conversation_id:conversationId,client_request_id:messageRequestId(),chat_mode:mode,...(continuing?{continue_live:true}:{}),...(queuedAttachment?{upload:queuedAttachment}:{})});
  });
  textarea?.addEventListener('keydown',event=>{if(event.key==='Enter'&&!event.shiftKey){event.preventDefault();form.requestSubmit();}});
  if(window.VP3ProfileImageEditsV1){
    imageSurface=window.VP3ProfileImageEditsV1.mount({shell,thread,form,cfg,loadCollection:loadImageCollection,
      context:()=>({key:[cfg.username,cfg.conversationNamespace,conversationId,callContextEpoch].join('|'),id:conversationId,
        active:alive&&!sessionChanged,sessionChanged,loading:initialStatePending||sessionRefreshing,clockOffset,
        busy:sending||imageRecoveryBusy||Boolean(pendingSend)||endBusy||checkoutCreating||phonePreparing,
        sending:sending||imageRecoveryBusy||endBusy||checkoutCreating||phonePreparing,
        human:chat?.media_call_active===true||conversationStatus==='owner_joined'||Boolean(chat&&['waiting','accepted'].includes(chat.live_status))}),
      registerPanel:entry=>extraPanels.push(entry),showPanel,
      onDraft:()=>{if(imageSurface?.hasDraft())setQueuedAttachment(null);formNotice='';updateAttachmentControls();},
      notice:message=>{formNotice=message;setNotice(message);},focusComposer:()=>textarea.focus()
    });
  }
  function speechProjection(value){
    if(value?.contract!=='vp3.profile-speech.v1'||!['preset','male','female','neutral'].includes(value.style)||!Number.isSafeInteger(value.revision)||value.revision<1||typeof value.ready!=='boolean'||!Array.isArray(value.presets)||value.presets.length>100)return null;
    if(value.preset_key!==null&&value.preset_key!==''&&(typeof value.preset_key!=='string'||!/^[a-f0-9]{64}$/.test(value.preset_key)))return null;
    if(value.presets.some(preset=>!preset||! /^[a-f0-9]{64}$/.test(preset.preset_key)||typeof preset.label!=='string'||!preset.label.trim()||Array.from(preset.label).length>120||!['unclassified','male','female','neutral'].includes(preset.style)))return null;
    return value;
  }
  function renderSpeechPreference(value){
    speechPreference=value;speechChoice.replaceChildren();
    const choices=[['preset','No preference'],['male','Masculine sounding'],['female','Feminine sounding'],['neutral','Neutral sounding'],...value.presets.map(preset=>['key:'+preset.preset_key,preset.label])];
    for(const [key,title] of choices){const option=document.createElement('option');option.value=key;option.textContent=title;speechChoice.appendChild(option);}
    speechChoice.value=value.preset_key?'key:'+value.preset_key:value.style;speechChoice.disabled=!value.ready||speechBusy||sessionChanged;
    speechNotice.textContent=value.ready?'Optional. This selects a reading voice, not your identity.':'Visitor speech is unavailable. Your messages and text chat still work.';
  }
  async function loadSpeechPreference(){
    if(initialStatePending||!cfg.speechEndpoint||!speechChoice||!alive||sessionChanged||speechBusy)return;
    const sequence=++speechSequence,cid=conversationId;
    try{const value=speechProjection(await request('visitor_state',{},'GET',cfg.speechEndpoint));if(!value)throw new Error('Voice preference unavailable.');if(sequence===speechSequence&&cid===conversationId)renderSpeechPreference(value);}
    catch(error){if(!error.stale&&sequence===speechSequence){speechChoice.disabled=true;speechNotice.textContent='Voice preference is unavailable. You can keep chatting in text.';if(invalidVisitorSession(error))stopChangedSession();}}
  }
  async function saveSpeechPreference(){
    if(speechBusy||!speechPreference?.ready||sessionChanged||!alive)return;
    const chosen=speechChoice.value,preset=chosen.startsWith('key:')?speechPreference.presets.find(row=>'key:'+row.preset_key===chosen):null;
    if(!preset&&!['preset','male','female','neutral'].includes(chosen))return;
    const payload={style:preset?'preset':chosen,preset_key:preset?.preset_key||'',revision:speechPreference.revision},key=JSON.stringify([conversationId,payload]);
    if(!speechAttempt||speechAttempt.key!==key)speechAttempt={key,payload,request_id:messageRequestId()};const attempt=speechAttempt,cid=conversationId,sequence=++speechSequence;
    let refreshSaved=false;speechBusy=true;speechChoice.disabled=true;speechNotice.textContent='Saving your voice preference…';
    try{const value=speechProjection(await request('visitor_preference',{...attempt.payload,request_id:attempt.request_id},'POST',cfg.speechEndpoint));if(!value)throw new Error('Voice preference was not confirmed.');if(sequence===speechSequence&&cid===conversationId){speechAttempt=null;renderSpeechPreference(value);speechNotice.textContent='Voice preference saved. Your messages stay available as text.';}}
    catch(error){if(!error.stale&&sequence===speechSequence){speechNotice.textContent='Checking the current saved voice preference…';refreshSaved=true;if([400,403,404,409,419,422].includes(error.status))speechAttempt=null;if(invalidVisitorSession(error))stopChangedSession();}}
    finally{speechBusy=false;if(alive&&!sessionChanged){speechChoice.disabled=!speechPreference?.ready;if(refreshSaved&&cid===conversationId)void loadSpeechPreference();}}
  }
  if(cfg.speechEndpoint&&form){
    const box=document.createElement('div');box.className='profile-speech-preference';
    const label=document.createElement('label');label.textContent='Reading voice (optional)';label.htmlFor='profileVisitorSpeechPreference';
    speechChoice=document.createElement('select');speechChoice.id='profileVisitorSpeechPreference';speechChoice.disabled=true;speechChoice.setAttribute('aria-label','Voice used when the owner listens to your typed messages');
    const description=document.createElement('p');description.textContent='Voice used when the owner listens to your typed messages.';
    speechNotice=document.createElement('p');speechNotice.setAttribute('role','status');speechNotice.textContent='Checking available reading voices…';
    box.append(label,speechChoice,description,speechNotice);
    const voiceSettings=shell.querySelector('[data-profile-chat-voice-settings]');
    if(voiceSettings)voiceSettings.append(box);else form.parentElement.insertBefore(box,form);
    speechChoice.addEventListener('change',()=>void saveSpeechPreference());
    window.addEventListener('vp3:profile-agent-open',()=>void loadSpeechPreference());
  }
  if(cfg.checkoutEndpoint&&window.VP3ProfileCheckoutPa5&&thread){
    checkoutHost=document.createElement('div');checkoutHost.className='profile-checkout-host';checkoutHost.dataset.profileCheckoutHost='';thread.appendChild(checkoutHost);
    checkoutSurface=window.VP3ProfileCheckoutPa5.mount({
      host:checkoutHost,username:cfg.username,browseAvailable:cfg.checkoutAvailable!==false,
      attemptStorageKey:()=>storageKey+':checkout-prepare',
      context:()=>({key:[cfg.username,cfg.conversationNamespace,cfg.profileToken,conversationId,callContextEpoch].join('|'),id:conversationId,active:alive&&!initialStatePending&&!sessionChanged&&!shell.hidden}),
      request:async(action,payload)=>{
        try{return await request(action,payload,'POST',cfg.checkoutEndpoint);}
        catch(error){if(!error.stale&&invalidVisitorSession(error))stopChangedSession();throw error;}
      },
      adopt:cid=>{
        if(conversationId||!checkoutCreating||!alive||sessionChanged)throw new Error('The visitor conversation changed.');
        requestEpoch++;conversationId=cid;rememberConversation();startPolling();void poll();
      },
      beginPrepare:()=>{
        if(conversationId)return true;
        if(initialStatePending||sending||pendingSend||endBusy||phonePreparing||callSurface?.offerBusy)return false;
        checkoutCreating=true;
        if(submit)submit.disabled=true;
        return true;
      },
      endPrepare:()=>{
        if(!checkoutCreating)return;
        checkoutCreating=false;
        if(submit&&alive&&!sessionChanged&&!sending&&!endBusy)submit.disabled=false;
      }
    });
    window.addEventListener('vp3:profile-agent-open',()=>void checkoutSurface.refresh());
    window.addEventListener('focus',()=>void checkoutSurface.refresh());
    document.addEventListener('visibilitychange',()=>void checkoutSurface.refresh());
    checkoutTimer=window.setInterval(()=>void checkoutSurface.refresh(false,true),15000);
  }
  if(cfg.callEndpoint&&window.VP3ProfileCallsPa3c&&thread){
    let observedCallState='';
    callHost=document.createElement('div');callHost.className='profile-call-visitor-host';callHost.dataset.profileCallVisitorHost='1';thread.appendChild(callHost);
    callSurface=window.VP3ProfileCallsPa3c.mountVisitor({
      host:callHost,offerHost:shell.querySelector('[data-profile-chat-call-offers]'),peerLabel:String(cfg.callOwnerName||'Profile owner'),
      context:()=>({key:[cfg.username,cfg.conversationNamespace,cfg.profileToken,conversationId,callContextEpoch].join('|'),binding:{conversation_id:conversationId},active:alive&&!initialStatePending&&!sessionChanged&&!shell.hidden}),
      request:async(action,payload,method)=>{
        const data=await request(action,payload,method,cfg.callEndpoint);
        // A call transition prompts a fresh canonical text projection; call
        // transport never invents or changes the text responder locally.
        const calls=Array.isArray(data.calls)?data.calls:data.call?[data.call]:[];
        const next=JSON.stringify(calls.filter(call=>call?.contract==='vp3.profile-call.v1'&&call.conversation_id===conversationId).map(call=>[call.call_id,call.state]));
        if(next!==observedCallState){observedCallState=next;if(conversationId)void poll();}
        return data;
      },
      allowOffer:()=>!initialStatePending&&!sending&&!pendingSend&&!endBusy&&!checkoutCreating&&!phonePreparing&&!phoneSurface?.isBusy()&&!chat?.phone_call_active,
      adopt:result=>{
        const cid=result.conversation_id;
        if(!Number.isSafeInteger(cid)||cid<1||(conversationId>0&&conversationId!==cid)||sessionChanged||!alive)throw new Error('The visitor conversation changed.');
        conversationId=cid;rememberConversation();if(openPanel===chatOptions)showPanel(null);startPolling();void checkoutSurface?.refresh();
      },
      returnedToAgent:()=>{void poll();}
    });
    window.addEventListener('vp3:profile-agent-visibility',event=>{if(event.detail?.open)void callSurface.refresh();else callSurface.invalidate('You closed the live call view.');});
  }
  if(cfg.browserPhoneEndpoint&&window.VP3ProfilePhoneV1){
    let browserPhoneUrl=null;
    try{const candidate=new URL(cfg.browserPhoneEndpoint,window.location.href);if(candidate.origin===window.location.origin)browserPhoneUrl=candidate;}catch(_){}
    if(browserPhoneUrl)phoneSurface=window.VP3ProfilePhoneV1.bindVisitor({
      context:()=>({key:[cfg.username,cfg.conversationNamespace,cfg.profileToken,conversationId,callContextEpoch].join('|'),id:conversationId,
        active:alive&&!sessionChanged&&!shell.hidden,ready:!initialStatePending&&!sessionRefreshing}),
      request:async(action,payload,method)=>{
        try{return await request(action,payload,method,cfg.browserPhoneEndpoint);}
        catch(error){if(!error.stale&&invalidVisitorSession(error))stopChangedSession();throw error;}
      },
      beginPrepare:()=>{
        if(initialStatePending||sessionRefreshing||sessionChanged||!alive||sending||pendingSend||imageRecoveryBusy||endBusy||checkoutCreating||phonePreparing||callSurface?.offerBusy)return false;
        phonePreparing=true;submit.disabled=true;return true;
      },
      endPrepare:()=>{
        phonePreparing=false;
        if(alive&&!sessionChanged&&!sending&&!endBusy&&!checkoutCreating)submit.disabled=false;
      },
      adopt:cid=>{
        if(!phonePreparing||!Number.isSafeInteger(cid)||cid<1||(conversationId>0&&conversationId!==cid)||sessionChanged||!alive)throw new Error('The visitor conversation changed.');
        conversationId=cid;rememberConversation();startPolling();void poll();void checkoutSurface?.refresh();
      }
    });
  }
  window.addEventListener('pagehide',event=>{
    if(event.persisted&&!pendingSend&&!queuedAttachment&&!imageSurface?.hasDraft()){
      try{sessionStorage.setItem(storageKey+':restore-draft',JSON.stringify({conversation_id:conversationId,message:textarea.value}));}catch(_error){}
    }
    callSurface?.stop('You left the profile.');phoneSurface?.stop('You left the profile. Your microphone is off.');checkoutSurface?.reset(true);imageSurface?.reset(true);alive=false;callContextEpoch++;requestEpoch++;clearTimeout(pollTimer);clearInterval(presenceTimer);clearInterval(choiceTimer);clearInterval(countdownTimer);clearInterval(checkoutTimer);
  },{once:true});
  // A back/forward-cache restore also restores alive=false and the stopped
  // timers. Reload to recheck current account/session authority before showing
  // or continuing the saved thread; do not revive an old visitor credential.
  window.addEventListener('pageshow',event=>{if(event.persisted&&!alive)window.location.reload();});
  window.addEventListener('online',()=>{if(initialStatePending||!alive||sessionChanged)return;if(conversationId)void poll();else void heartbeat();});
  window.addEventListener('vp3:profile-agent-open',()=>{if(initialStatePending)return;if(conversationId)void poll();else void heartbeat();});
  updateChatChoice();
  void loadState().finally(()=>{
    initialStatePending=false;
    if(!alive||sessionChanged)return;
    if(sessionRefreshing){sessionRefreshing=false;textarea.disabled=false;submit.disabled=false;}
    updateAttachmentControls();
    if(conversationId)startPolling();
    void loadSpeechPreference();void checkoutSurface?.refresh();void phoneSurface?.refresh();
    if(!shell.hidden)void callSurface?.refresh();
  });
  presenceTimer=window.setInterval(heartbeat,60000);
  choiceTimer=window.setInterval(()=>{if(!conversationId&&shell.hidden!==true)void heartbeat();},10000);
  countdownTimer=window.setInterval(showHandoffStatus,1000);
})();
