(() => {
  'use strict';
  const config=window.VP3_PHONE_WORKSPACE_V1;
  if(!config||window.VP3PhoneWorkspaceV1)return;
  const positive=value=>Number.isSafeInteger(Number(value))&&Number(value)>0;
  const principal=value=>Number.isSafeInteger(Number(value))&&Number(value)>=0?Number(value):0;
  const text=value=>String(value??'').slice(0,5000);
  const voicemailKey=value=>typeof value==='string'&&/^(?:job|legacy)-[1-9][0-9]{0,14}$/.test(value)?value:positive(value)?String(value):'';
  const publicId=value=>typeof value==='string'&&/^[a-f0-9]{32}$/.test(value);
  const callKey=value=>publicId(value)?value:typeof value==='string'&&/^legacy-[1-9][0-9]{0,14}$/.test(value)?value:'';
  const node=(tag,cls,value)=>{const item=document.createElement(tag);if(cls)item.className=cls;if(value!==undefined)item.textContent=text(value);return item;};
  const endpoint=new URL(config.endpoint,location.href);
  if(endpoint.origin!==location.origin||endpoint.username||endpoint.password)return;
  function captionAsset(value,relative){try{const actual=new URL(value,location.href),expected=new URL(relative,new URL(config.urlbase||'/',location.origin));return actual.origin===location.origin&&!actual.username&&!actual.password&&!actual.hash&&actual.pathname===expected.pathname?actual.href:'';}catch(_){return '';}}
  const greetingEndpoint=captionAsset(config.greeting_endpoint,'api/phone-voicemail-greeting-v1.php'),greetingCaptureAsset=captionAsset(config.greeting_capture_url,'phone-voicemail-capture-v1.js');
  let voicemailDraft=null,greetingCapture=null,greetingDraft=null,greetingSaveRequest=null,voicemailBusy=false;
  const voicemailActions=new Map(),companionDrafts=new Map();
  const liveEndpoint=captionAsset(config.operator_live_endpoint,'api/phone-operator-live-v1.php'),captureAsset=captionAsset(config.operator_capture_url,'phone-operator-capture-v1.js');
  const cards=new Set(),requests=new Set(),pending=new Map(),localStates=new Map(),audioNodes=new Set(),policyDrafts=new Map(),contactDrafts=new Map();
  let context={conversation_id:principal(config.conversation_id??config.conversationId),agent_id:principal(config.agent_id??config.agentId),ready:true},generation=0,requestSequence=0;
  let state=null,authorized=true,disposed=false,opened=false,activeTab='connections',opener=null,fetching=null,deviceSession=null,active=null,callBusy=false,sdkPromise=null;
  let callEpoch=0,dialDraft='',recordThisCall=false,preparingTicket=null,setupPending=0,connectedDraft=null,voiceArm=null;
  let restoredGeneration=-1;
  let pollTimer=0,keepCallError=false,statusText='Checking phone connections…',contactQuery='',initialState=true,stateError='',autoCallDraft=null,calendarDraft=null,phoneContactDraft=null,admissionDraft=null;
  const layer=node('div','vp3-phone-workspace');layer.hidden=true;
  const backdrop=node('button','vp3-phone-backdrop');backdrop.type='button';backdrop.tabIndex=-1;backdrop.setAttribute('aria-label','Close phone workspace');
  const panel=node('aside','vp3-phone-panel');panel.setAttribute('role','dialog');panel.setAttribute('aria-modal','true');panel.setAttribute('aria-labelledby','vp3PhoneTitle');panel.tabIndex=-1;
  const header=node('header','vp3-phone-header'),title=node('h2',null,'Phone');title.id='vp3PhoneTitle';
  const closeButton=button('Close',close,'vp3-phone-close');closeButton.setAttribute('aria-label','Close phone workspace');header.append(title,closeButton);
  const notice=node('p','vp3-phone-notice');notice.setAttribute('role','status');notice.setAttribute('aria-live','polite');
  const recovery=node('div','vp3-phone-recovery'),retryButton=button('Retry',()=>void refresh());recovery.hidden=true;recovery.append(retryButton);
  const tabs=node('div','vp3-phone-tabs');tabs.setAttribute('role','tablist');tabs.setAttribute('aria-label','Phone workspace');
  const tabNames={connections:'Connections',contacts:'Contacts',calls:'Recent calls',voicemail:'Voicemail'};
  const tabButtons={};
  for(const [key,label] of Object.entries(tabNames)){
    const tab=button(label,()=>selectTab(key));tab.id='vp3PhoneTab-'+key;tab.setAttribute('role','tab');tab.setAttribute('aria-controls','vp3PhoneSection-'+key);tabButtons[key]=tab;tabs.append(tab);
  }
  const body=node('div','vp3-phone-body'),sections={};
  for(const key of Object.keys(tabNames)){
    const section=node('section','vp3-phone-section');section.id='vp3PhoneSection-'+key;section.setAttribute('role','tabpanel');section.setAttribute('aria-labelledby','vp3PhoneTab-'+key);section.tabIndex=0;sections[key]=section;body.append(section);
  }
  panel.append(header,notice,recovery,tabs,body);layer.append(backdrop,panel);document.body.append(layer);

  function button(label,handler,cls='vp3-phone-button'){
    const item=node('button',cls,label);item.type='button';item.addEventListener('click',handler);return item;
  }
  function current(g){return !disposed&&authorized&&g===generation;}
  function sessionCurrent(session){return Boolean(session)&&current(session.generation)&&deviceSession===session&&!session.closed;}
  function updateNotice(){notice.textContent=stateError+(stateError&&state?' Showing previously loaded data; retry to refresh.':'')||statusText;recovery.hidden=!stateError;retryButton.disabled=Boolean(fetching);}
  function say(message,callError=false){keepCallError=callError;statusText=text(message).slice(0,350);updateNotice();}
  function selectedNumber(){return (state?.numbers||[]).find(item=>Number(item.id)===Number(state?.selected_number_id));}
  const enabled=value=>value===true||value===1||value==='1';
  function chatEligible(){const chat=window.STONEFELLOW_CHAT_CONTINUITY;return context.ready&&(positive(context.conversation_id)||Boolean(chat?.ensureConversation&&chat.isReady?.()&&!chat.isRestoring?.()&&!chat.isBusy?.()));}
  function registrationPreference(numberId,save){
    if(!positive(config.owner_id)||!positive(numberId))return false;
    const key=['vp3-browser-calls',config.owner_id,context.agent_id,numberId].join('.');
    try{
      if(save===true)window.sessionStorage.setItem(key,'enabled');
      if(save===false)window.sessionStorage.removeItem(key);
      return window.sessionStorage.getItem(key)==='enabled';
    }catch{return false;}
  }
  function canCall(){const number=selectedNumber();return !!(!greetingCapture&&!stateError&&number&&enabled(number.voice_enabled)&&enabled(number.browser_enabled)&&state?.readiness?.ready===true&&chatEligible());}
  async function ensureContext(){
    if(!context.ready)return false;if(positive(context.conversation_id))return {generation,conversation_id:context.conversation_id,agent_id:context.agent_id};
    const chat=window.STONEFELLOW_CHAT_CONTINUITY,agent=context.agent_id;
    if(!chatEligible()||!chat?.ensureConversation){say('Open an owner Chat conversation before calling.');return false;}
    const id=Number(await chat.ensureConversation());
    if(!positive(id)||context.agent_id!==agent||!chat.isReady?.()||chat.isRestoring?.()||chat.isBusy?.())return false;
    const actual=Number(chat.conversationId?.());if(actual!==id)return false;
    contextChanged({conversation_id:id,agent_id:agent,ready:true});await refresh();
    return context.ready&&context.conversation_id===id&&context.agent_id===agent?{generation,conversation_id:id,agent_id:agent}:false;
  }

  function displayTime(value){const raw=String(value||'');const date=new Date(raw&&!raw.includes('T')?raw.replace(' ','T')+'Z':raw);return Number.isFinite(date.getTime())?date.toLocaleString():'';}
  function displayState(value){return text(value||'unknown').replaceAll('_',' ');}
  function safeUrl(value){if(typeof value!=='string'||!value.trim()||value.length>4096)return '';try{const url=new URL(value,location.href);return url.origin===location.origin&&!url.username&&!url.password&&['http:','https:'].includes(url.protocol)?url.href:'';}catch(_){return '';}}
  function peerContact(peer){return (state?.contacts||[]).find(item=>item.phone_e164===peer&&typeof item.name==='string'&&item.name.trim());}
  function callerContact(info){return positive(info?.contact_id)&&typeof info.contact_name==='string'&&info.contact_name.trim()?{id:Number(info.contact_id),name:info.contact_name,phone_e164:info.peer_e164}:peerContact(info?.peer_e164);}
  function peerName(peer){return peerContact(peer)?.name||peer||'Unknown caller';}
  function callPeerName(info){return info?.connected_phone===true?'Connected phone · '+text(info.peer_masked||'Private number'):peerName(info?.peer_e164);}
  function callDuration(value){const seconds=Number(value);if(!Number.isSafeInteger(seconds)||seconds<0||seconds>86400)return '';return Math.floor(seconds/60)+':'+String(seconds%60).padStart(2,'0');}
  function scopeBody(extra={}){return {...extra,conversation_id:context.conversation_id,agent_id:context.agent_id};}
  function phoneError(message,status=0){const error=new Error(message);error.phonePublicMessage=message;error.status=status;return error;}
  function requestErrorMessage(action,data,status){
    if(data?.error==='phone_upgrade_required')return 'Phone needs the Cloud database upgrade. Ask an administrator to run upgrade.php, then retry.';
    if(['save_voicemail_settings','use_default_voicemail_greeting','transcribe_voicemail','delete_voicemail','post_voicemail'].includes(action)){
      if(data?.error==='phone_voicemail_upgrade_required')return 'Voicemail needs the Cloud database upgrade. Ask an administrator to run upgrade.php.';
      if(status===409)return 'This voicemail or its settings changed. Refresh before trying again. A transcription already started will not be charged again automatically.';
      if(status===404)return 'This voice message is no longer available.';
      return 'The voicemail action could not be confirmed. Refresh its status before trying again.';
    }
    if(action==='route_incoming'&&['phone_agent_unavailable','phone_call_changed','phone_call_unavailable'].includes(data?.error))return text(data.message).slice(0,250);
    if(action==='state')return status===422?'Phone could not load this Chat context. Refresh the page and try again.':'Phone is temporarily unavailable. Retry to load your connections.';
    if(['assign_scheduled_operator','phone_contact_detail','save_phone_contact','save_phone_contact_schedule','cancel_phone_contact_schedule'].includes(action)&&typeof data?.error==='string'&&data.error.startsWith('phone_contact')&&typeof data.message==='string')return data.message.slice(0,300);
    const operatorError=['save_auto_call_policy','operator_calendar_preview','save_operator_calendar','owner_phone_settings','save_owner_phone','save_owner_call_preferences','prepare_owner_test','confirm_owner_test','prepare_connected_call'].includes(action)&&typeof data?.error==='string'&&data.error.startsWith('operator_')&&typeof data.message==='string';
    return operatorError?data.message.slice(0,300):status===409?'This call changed. Refresh its status before trying again.':'The phone request could not be confirmed.';
  }
  async function api(action,values={},method='POST',g=generation){
    if(!current(g))throw new DOMException('Phone context changed.','AbortError');
    const requestScope={conversation_id:context.conversation_id,agent_id:context.agent_id};
    const controller=new AbortController();requests.add(controller);const timer=setTimeout(()=>controller.abort(),['managed_reconcile','managed_end'].includes(action)?50000:15000);
    try{
      const url=new URL(endpoint);const options={method,credentials:'same-origin',cache:'no-store',signal:controller.signal,headers:{Accept:'application/json'}};
      if(method==='GET'){for(const [key,value] of Object.entries(scopeBody({action,...values})))url.searchParams.set(key,String(value));}
      else{options.headers['Content-Type']='application/json';options.body=JSON.stringify(scopeBody({action,csrf_token:config.csrf,...values}));}
      const response=await fetch(url.href,options);
      if(current(g)&&(response.status===401||response.status===403)){revoke();throw phoneError('Phone access is unavailable.',response.status);}
      let data={};try{data=await response.json();}catch(error){if(error.name==='AbortError')throw error;throw phoneError('Phone returned an invalid response. Refresh the page or retry.');}
      if(!data||typeof data!=='object'||Array.isArray(data))throw phoneError('Phone returned an invalid response. Refresh the page or retry.');
      if(!current(g)){if(['prepare_call','prepare_owner_test','prepare_connected_call'].includes(action)&&data.ok===true&&publicId(data.call?.public_id))await cancelPrepared({info:data.call,scope:requestScope,prepared:true});throw new DOMException('Phone context changed.','AbortError');}
      if(!response.ok||data.ok!==true)throw phoneError(requestErrorMessage(action,data,response.status),response.status);
      return data;
    }finally{clearTimeout(timer);requests.delete(controller);}
  }
  function cleanState(raw){
    if(!raw||!Array.isArray(raw.numbers)||!Array.isArray(raw.calls)||!Array.isArray(raw.voicemails))throw phoneError('Phone returned incomplete data. Retry to load your connections.');
    return {...raw,numbers:raw.numbers.filter(item=>positive(item?.id)).slice(0,30),contacts:(Array.isArray(raw.contacts)?raw.contacts:[]).filter(item=>positive(item?.id)).slice(0,250),favorites:(Array.isArray(raw.favorites)?raw.favorites:[]).filter(item=>positive(item?.id)).slice(0,100),calls:raw.calls.filter(item=>callKey(item?.public_id)||callKey(item?.call_id)).map(item=>({...item,public_id:callKey(item.public_id)||callKey(item.call_id)})).slice(0,60),voicemails:raw.voicemails.filter(item=>voicemailKey(item?.id)).map(item=>({...item,id:voicemailKey(item.id)})).slice(0,60)};
  }
  async function refresh(){
    if(!authorized||disposed||!context.ready)return;
    if(fetching)return fetching;
    const g=generation,sequence=++requestSequence;
    const work=(async()=>{
      try{
        const result=await api('state',{},'GET',g);if(!current(g)||sequence!==requestSequence)return;
        const next=cleanState(result.state);
        const greetingNumber=greetingCapture?.number_id||greetingDraft?.number_id;
        if(greetingNumber&&(!next.numbers.some(item=>Number(item.id)===greetingNumber)||Number(next.selected_number_id)!==greetingNumber)){clearGreeting();voicemailDraft=null;}
        if(deviceSession&&(!next.numbers.some(item=>Number(item.id)===deviceSession.numberId&&item.browser_enabled&&item.voice_enabled)||Number(next.selected_number_id)!==deviceSession.numberId))stopDevice('Phone connection changed. Enable calls again.');
        const readinessChanged=!state||state.readiness?.ready!==next.readiness?.ready||state.readiness?.reason!==next.readiness?.reason;
        const recovered=Boolean(stateError);state=next;initialState=false;stateError='';
        if(active?.captionSession?.phase==='listening'&&(next.operator_live?.eligible!==true||next.operator_live.call_id!==active.info.public_id||active.captionSession.voiceTurn&&next.operator_live.voice_available!==true))stopRemoteCaptions(active,'Live assistance permission changed.');
        if(voiceArm&&next.operator_live?.voice_available!==true){voiceArm.mixer.close();voiceArm=null;}
        if(!state.numbers.length){revoke();return;}
        if(!deviceSession&&restoredGeneration!==g&&positive(context.conversation_id)&&canCall()&&registrationPreference(next.selected_number_id)){
          restoredGeneration=g;
          void enableDevice().catch(()=>{});
        }
        if(!deviceSession&&!active){
          if(!keepCallError||readinessChanged||recovered)say(state.readiness?.ready?'Select a number and enable calls in this browser.':state.readiness?.reason||'The phone connection is not ready.');
        }else if(recovered)say(active?'Phone status refreshed.':deviceSession?.phase==='registered'?'Browser ready for incoming calls.':'Connecting browser…');
        render();
      }catch(error){
        if(!current(g)||sequence!==requestSequence)return;
        if(active?.captionSession?.voiceTurn)stopRemoteCaptions(active,'Voice permission could not be refreshed. Human control restored.');
        initialState=false;
        stateError=error.phonePublicMessage||(error.name==='AbortError'?'Phone took too long to respond. Retry to load your connections.':'Phone could not reach the server. Check your connection, then retry.');
        render();
      }
    })();
    fetching=work;updateNotice();try{await work;}finally{if(fetching===work){fetching=null;if(current(g))updateNotice();}}
  }
  function open(source=document.activeElement){
    if(disposed||!authorized)return;
    opener=source;opened=true;layer.hidden=false;document.body.classList.add('vp3-phone-open');
    document.querySelectorAll('[data-phone-workspace-toggle]').forEach(item=>item.setAttribute('aria-expanded','true'));
    render();panel.focus({preventScroll:true});void refresh();
  }
  function close(){
    clearGreeting();
    clearManagedReview();
    if(connectedDraft)connectedDraft.confirmed=false;
    opened=false;layer.hidden=true;document.body.classList.remove('vp3-phone-open');
    document.querySelectorAll('[data-phone-workspace-toggle]').forEach(item=>item.setAttribute('aria-expanded','false'));
    if(opener?.isConnected)opener.focus({preventScroll:true});
  }
  function selectTab(key,focus=false){
    if(!tabNames[key])return;if(activeTab==='voicemail'&&key!=='voicemail')clearGreeting();activeTab=key;
    for(const name of Object.keys(tabNames)){const selected=name===key;tabButtons[name].setAttribute('aria-selected',String(selected));tabButtons[name].tabIndex=selected?0:-1;sections[name].hidden=!selected;}
    if(focus)tabButtons[key].focus();
  }
  function listEmpty(host,message){host.append(node('p','vp3-phone-empty',message));}
  function replacePhoneContent(host,...contents){
    const previous=[...host.childNodes],playing=[...host.querySelectorAll('audio')].filter(audio=>!audio.paused),nextAudio=contents.flatMap(content=>[...(content.querySelectorAll?.('audio')||[])]);
    host.append(...contents);
    for(const audio of playing){const replacement=nextAudio.find(next=>next.dataset.phoneRecordingKey===audio.dataset.phoneRecordingKey&&next.src===audio.src);if(replacement){replacement.replaceWith(audio);audioNodes.delete(replacement);nextAudio.splice(nextAudio.indexOf(replacement),1);}}
    for(const child of previous)child.remove();
  }
  function render(){
    const focused=document.activeElement,focusKey=layer.contains(focused)?focused?.dataset?.phoneFocusKey:'',selection=focusKey&&'selectionStart' in focused?[focused.selectionStart,focused.selectionEnd]:null,scroll=body.scrollTop;
    updateNotice();
    renderConnections();renderContacts();renderCalls();renderVoicemails();renderCards();selectTab(activeTab);
    for(const audio of [...audioNodes])if(!audio.isConnected){try{audio.pause();audio.removeAttribute('src');audio.load();}catch(_){}audioNodes.delete(audio);}
    if(opened&&focusKey){const replacement=[...layer.querySelectorAll('[data-phone-focus-key]')].find(item=>item.dataset.phoneFocusKey===focusKey);if(replacement&&!replacement.disabled&&!replacement.closest('[hidden]')){replacement.focus({preventScroll:true});if(selection)try{replacement.setSelectionRange(...selection);}catch(_){}}}
    body.scrollTop=scroll;
  }
  function renderConnections(){
    const renderGeneration=generation,host=node('div');
    if(!state){listEmpty(host,initialState?'Loading assigned phone connections…':'Phone connections are unavailable.');replacePhoneContent(sections.connections,...host.childNodes);return;}
    const label=node('label','vp3-phone-field','Phone number'),select=node('select');select.setAttribute('aria-label','Selected phone number');select.dataset.phoneFocusKey='number';
    for(const number of state.numbers){const option=node('option',null,number.phone_e164+(number.agent_name?' · '+number.agent_name:''));option.value=String(number.id);select.append(option);}
    select.value=String(state.selected_number_id||'');select.disabled=Boolean(stateError||active||callBusy||setupPending||pending.size);
    select.addEventListener('change',async()=>{const g=renderGeneration;if(!current(g))return;const id=Number(select.value);select.disabled=true;try{await api('select_number',{number_id:id},'POST',g);if(!current(g))return;clearGreeting();voicemailDraft=null;stopDevice('Phone number changed. Enable calls for this number.');await refresh();}catch(error){if(current(g))say(error.message);}finally{if(current(g))render();}});
    label.append(select);host.append(label);
    const connection=node('div','vp3-phone-connection');connection.append(node('strong',null,deviceSession?.phase==='registered'?'Browser ready for incoming calls':deviceSession?'Connecting browser…':'Browser calls are off'));
    const enable=button(deviceSession?'Turn off browser calls':'Enable calls in this browser',async()=>{
      if(deviceSession){
        registrationPreference(deviceSession.numberId,false);
        stopDevice('Browser calls are off.');
      }else{
        try{
          const session=await enableDevice();
          if(sessionCurrent(session))registrationPreference(session.numberId,true);
        }catch{}
      }
    });enable.disabled=!deviceSession&&(!canCall()||Boolean(voiceArm?.pending));connection.append(enable);host.append(connection);
    if(state.operator_live?.voice_available===true&&window.VP3OperatorVoiceMixerV1?.create){
      const arm=button(deviceSession?.voiceMixer?'Agent voice path prepared':voiceArm?'Disable prepared Agent voice':'Prepare Agent voice for browser calls',event=>void toggleVoiceArm(event,renderGeneration));
      arm.disabled=Boolean(deviceSession||active||callBusy||setupPending||pending.size||voiceArm?.pending);
      arm.dataset.phoneFocusKey='operator-voice-arm';
      host.append(arm,node('p','vp3-phone-meta','Optional: prepare the outbound audio path before enabling browser calls. Human audio stays in control; each AI voice turn still requires consent and explicit approval.'));
    }
    if(!chatEligible()){host.append(node('p','vp3-phone-empty','Open an owner Chat conversation to make or accept a call.'));const link=node('a','vp3-phone-report','Open Chat');link.href=new URL('chat.php',new URL(config.urlbase||'/',location.origin)).href;host.append(link);}
    if(connectedDraft)renderConnectedDial(host,renderGeneration);
    const dial=node('form','vp3-phone-dial'),dialLabel=node('label','vp3-phone-field','Dial a phone number'),input=node('input');input.type='tel';input.autocomplete='tel';input.placeholder='+16025550123';input.required=true;input.maxLength=20;input.pattern='\\+[1-9][0-9]{7,14}';input.setAttribute('aria-label','Phone number to call');input.dataset.phoneFocusKey='dial';input.value=dialDraft;input.addEventListener('input',()=>{dialDraft=input.value.slice(0,20);});dialLabel.append(input);
    const send=node('button','vp3-phone-button vp3-phone-primary','Call');send.type='submit';send.disabled=!canCall()||Boolean(active||callBusy||setupPending||pending.size);dial.append(dialLabel,send);
    if(enabled(selectedNumber()?.recording_enabled)&&state.recording_readiness?.ready!==false){
      const recordLabel=node('label','vp3-phone-check vp3-phone-record'),record=node('input');record.type='checkbox';record.checked=recordThisCall;record.dataset.phoneFocusKey='record-call';record.addEventListener('change',()=>{if(current(renderGeneration))recordThisCall=record.checked;});recordLabel.append(record,node('span',null,'Record this call'));dial.append(recordLabel,node('p','vp3-phone-empty vp3-phone-record','The person you call hears a recording notice. Your saved after-call settings control transcripts and reports.'));
    }
    dial.addEventListener('submit',event=>{event.preventDefault();if(current(renderGeneration))void placeCall(input.value,{record:recordThisCall});});if(!connectedDraft)host.append(dial);
    if(document.getElementById('chatThread')){
      host.append(button('My phone in Chat',event=>{if(event.isTrusted){close();void openOwnerSetup();}}));
      const link=node('a','vp3-phone-report','Connected-phone calling');
      const target=new URL('phone-tracking.php',new URL(config.urlbase||'/',location.origin));
      if(target.origin===location.origin&&!target.username&&!target.password){target.searchParams.set('profile','managed');link.href=target.href;host.append(link);}
    }
    renderCurrentCall(host);renderPolicy(host);replacePhoneContent(sections.connections,...host.childNodes);
  }
  function renderConnectedDial(host,g){
    const draft=connectedDraft;if(!draft)return;
    const card=node('section','vp3-phone-call');card.dataset.connectedPhoneCall='1';
    card.append(node('strong',null,'Call '+draft.name),node('p','vp3-phone-meta',draft.masked+' · Shared assigned line'),
      node('p','vp3-phone-empty','One browser call, up to three minutes. Voice charges may apply. The phone’s current permissions, hours and limits will be checked. Recording and AI processing are off.'));
    const label=node('label','vp3-phone-check'),confirm=node('input');confirm.type='checkbox';confirm.checked=draft.confirmed;
    confirm.disabled=Boolean(active||callBusy||setupPending||pending.size);label.append(confirm,node('span',null,'Place one call to this connected phone'));
    const call=button('Call connected phone',event=>{
      if(!event.isTrusted||!current(g)||connectedDraft!==draft||!draft.confirmed||!canCall()||active||callBusy||setupPending||pending.size)return;
      draft.confirmed=false;void placeCall('',{connected:draft});
    },'vp3-phone-button vp3-phone-primary');
    const update=()=>{call.disabled=!draft.confirmed||!canCall()||Boolean(active||callBusy||setupPending||pending.size);};
    confirm.addEventListener('change',()=>{draft.confirmed=confirm.checked;update();});update();
    const cancel=button('Close connected phone setup',()=>{if(connectedDraft===draft){connectedDraft=null;render();}});
    cancel.disabled=Boolean(active||callBusy||setupPending||pending.size);card.append(label,call,cancel);
    const review=node('a','vp3-phone-report','Review a Twilio managed callback');
    const target=new URL('phone-tracking.php',new URL(config.urlbase||'/',location.origin));
    if(target.origin===location.origin&&!target.username&&!target.password&&publicId(draft.device_id)){
      target.searchParams.set('device_id',draft.device_id);target.searchParams.set('profile','managed');review.href=target.href;card.append(review);
    }
    host.append(card);
  }
  function openConnectedPhone(device,source=document.activeElement){
    if(!authorized||disposed||!document.getElementById('phone-workspace'))return false;
    if(active||callBusy||setupPending||pending.size){open(source);say('Finish the current phone call first.');return false;}
    const p=device?.communication,link=device?.agent_chat;
    if(device?.status!=='approved'||!publicId(device.id)||p?.call_permission_saved!==true||!publicId(p.profile_id)
      ||!Number.isSafeInteger(p.revision)||p.revision<1||!Number.isSafeInteger(p.phone_revision)||p.phone_revision<1
      ||!Number.isSafeInteger(link?.conversation_id)||link.conversation_id<1||!Number.isSafeInteger(link.agent_id)||link.agent_id<0)return false;
    contextChanged({conversation_id:link.conversation_id,agent_id:link.agent_id,ready:true});
    connectedDraft={device_id:device.id,profile_id:p.profile_id,profile_revision:p.revision,phone_revision:p.phone_revision,
      name:text(device.name||'connected phone').slice(0,80),masked:text(p.phone_masked||'Private number').slice(0,30),confirmed:false};
    open(source);selectTab('connections');render();return true;
  }

  function normalizePolicy(raw={}){
    const modules=['basic','qa','actions','decisions','followup'];
    return {enabled:raw.enabled===true,retain_audio:raw.retain_audio===true,transcribe:raw.transcribe===true,analyze:raw.analyze===true,modules:Array.isArray(raw.modules)?raw.modules.filter(value=>modules.includes(value)):[...modules]};
  }
  function renderPolicy(host){
    if(state.recording_readiness?.ready===false){host.append(node('p','vp3-phone-empty','Phone recordings need the Cloud database upgrade. Ask an administrator to run upgrade.php, then retry.'));return;}
    const number=selectedNumber();if(!number)return;const g=generation,key=String(number.id);let draft=policyDrafts.get(key);
    if(!draft||!draft.dirty){draft={values:normalizePolicy(state.post_call_policy),dirty:false};policyDrafts.set(key,draft);}
    const form=node('form','vp3-phone-policy');form.append(node('h3',null,'After calls'),node('p','vp3-phone-empty','Recording plays a spoken disclosure. Choose what to save and include in your Chat reports.'));
    const worker=state.processing_worker||{};form.append(node('p','vp3-phone-worker-status',worker.state==='observed'&&worker.observation_scope==='server'?'Server worker observed'+(displayTime(worker.last_observed_at)?' '+displayTime(worker.last_observed_at):'')+'.':'Automatic processing is waiting for the server worker.'));
    const fields={enabled:'Enable Cloud transcription and analysis for recorded calls',retain_audio:'Keep call recordings',transcribe:'Create transcripts',analyze:'Create a call report'};
    for(const [name,labelText] of Object.entries(fields)){
      const label=node('label','vp3-phone-check'),input=node('input');input.type='checkbox';input.dataset.phoneFocusKey='policy-'+name;input.checked=draft.values[name];input.addEventListener('change',()=>{if(current(g)){draft.values[name]=input.checked;draft.dirty=true;}});label.append(input,node('span',null,labelText));form.append(label);
    }
    const modules=node('fieldset','vp3-phone-modules');modules.append(node('legend',null,'Report sections'));
    const moduleLabels={basic:'Summary',qa:'Questions and answers',actions:'Actions',decisions:'Decisions',followup:'Follow-up'};
    for(const [value,labelText] of Object.entries(moduleLabels)){
      const label=node('label','vp3-phone-check'),input=node('input');input.type='checkbox';input.dataset.phoneFocusKey='module-'+value;input.checked=draft.values.modules.includes(value);input.addEventListener('change',()=>{if(!current(g))return;draft.values.modules=draft.values.modules.filter(item=>item!==value);if(input.checked)draft.values.modules.push(value);draft.dirty=true;});label.append(input,node('span',null,labelText));modules.append(label);
    }
    form.append(modules,node('p','vp3-phone-empty','Uses Cloud speech and your account AI route. Automatic processing allows 60 audio minutes per hour; longer transcripts remain available in Transcription.'));
    const save=node('button','vp3-phone-button','Save after-call settings');save.type='submit';form.append(save);form.addEventListener('submit',async event=>{
      event.preventDefault();if(!current(g)||Number(selectedNumber()?.id)!==Number(number.id))return;save.disabled=true;
      try{const result=await api('save_recording_policy',{number_id:Number(number.id),...draft.values},'POST',g);if(!current(g)||policyDrafts.get(key)!==draft)return;state.post_call_policy=normalizePolicy(result.policy);draft.values=normalizePolicy(result.policy);draft.dirty=false;say('After-call settings saved.');render();}catch(error){if(current(g))say(error.message);}finally{if(current(g))save.disabled=false;}
    });host.append(form);
  }
  function renderCurrentCall(host){
    for(const entry of pending.values())host.append(callCard(entry.info,entry));
    if(active)host.append(callCard(active.info,active));
    else if(state?.active_call&&!pending.size)host.append(callCard(state.active_call));
  }
  function autoCallPolicy(contact){
    const book=state?.auto_call,number=selectedNumber();
    if(book?.available!==true||book.execution_enabled!==false||!Array.isArray(book.policies)||!number)return null;
    const policy=book.policies.slice(0,250).find(p=>p?.contact_id===Number(contact.id)&&p.number_id===Number(number.id)&&p.agent_id===Number(number.agent_id));
    if(!policy||policy.execution_enabled!==false||policy.recording_required!==true||policy.transcription_required!==true||
      !Number.isSafeInteger(policy.revision)||policy.revision<0||typeof policy.enabled!=='boolean'||typeof policy.can_enable!=='boolean'||
      !['off','saved','blocked','needs_review'].includes(policy.state)||typeof policy.reason!=='string'||
      typeof policy.destination_e164!=='string'||typeof policy.line_e164!=='string'||
      policy.line_e164!==number.phone_e164||!policy.settings)return null;
    return policy;
  }
  function autoCallKey(policy){return policy.agent_id+':'+policy.number_id+':'+policy.contact_id;}
  function autoCallEditor(contact,policy){
    const draft=autoCallDraft,g=generation,key=autoCallKey(policy);
    const form=node('form','vp3-phone-auto-call-form');form.noValidate=true;
    form.setAttribute('aria-label','Agent Auto Call settings for '+(contact.name||contact.phone_e164));
    const changed=draft.revision!==policy.revision||draft.destination!==policy.destination_e164||draft.line!==policy.line_e164;
    form.append(node('strong',null,'Agent Auto Call'));
    form.append(node('p','vp3-phone-empty','Owner permission only. Autonomous dialing is not available in this release. A future call must disclose the AI, obtain required consent, record and transcribe the conversation, and obey these limits.'));
    form.append(node('p','vp3-phone-auto-call-target','From '+policy.line_e164+' · '+(selectedNumber()?.agent_name||'Assigned Agent')+'\nTo '+(policy.destination_e164||'No valid primary number')));
    if(policy.state==='needs_review')form.append(node('p','vp3-phone-processing-error','The previously authorized contact or line changed. Review the numbers before saving again.'));
    if(policy.reason)form.append(node('p','vp3-phone-empty',policy.reason));
    if(changed)form.append(node('p','vp3-phone-processing-error','This permission changed while you were editing. Cancel and reopen before saving.'));
    const checkLabel=node('label','vp3-phone-check'),check=node('input');check.type='checkbox';check.checked=draft.enabled;
    check.disabled=draft.saving||changed||(!policy.can_enable&&!policy.enabled);
    check.dataset.phoneFocusKey='auto-call-enable-'+key;
    check.addEventListener('change',()=>{if(current(g)&&autoCallDraft===draft&&!draft.saving){draft.enabled=check.checked;draft.feedback='';}});
    checkLabel.append(check,node('span',null,'Allow Agent Auto Call'));form.append(checkLabel);
    const fields=node('div','vp3-phone-auto-call-fields');
    const definitions=[
      ['timezone','Calling time zone','text',null,null,1],
      ['window_start','Earliest call (local time)','time',null,null,60],
      ['window_end','Latest call (local time)','time',null,null,60],
      ['max_calls_per_day','Maximum calls per day','number',1,3,1],
      ['min_gap_minutes','Minimum minutes between calls','number',60,10080,1],
      ['max_duration_seconds','Maximum call duration (minutes)','number',1,15,1]
    ];
    for(const [name,title,type,min,max,step] of definitions){
      const label=node('label','vp3-phone-field',title),input=node('input');input.type=type;input.required=true;
      input.dataset.phoneFocusKey='auto-call-'+name+'-'+key;input.disabled=draft.saving||changed;
      if(type==='number'){input.min=String(min);input.max=String(max);input.step=String(step);}
      if(type==='time')input.step=String(step);
      if(name==='timezone'){input.maxLength=80;input.placeholder='America/Phoenix';}
      input.value=String(name==='max_duration_seconds'?Number(draft.settings[name])/60:draft.settings[name]??'');
      input.addEventListener('input',()=>{if(current(g)&&autoCallDraft===draft&&!draft.saving){
        draft.settings[name]=type==='number'?(input.value===''?null:Number(input.value)*(name==='max_duration_seconds'?60:1)):input.value;
        draft.feedback='';
      }});
      label.append(input);fields.append(label);
    }
    form.append(fields,node('p','vp3-phone-empty','Calling window applies each day in the selected time zone. Limits are stored here; they do not start calls.'));
    if(draft.feedback){const message=node('p','vp3-phone-processing-error',draft.feedback);message.setAttribute('role','status');form.append(message);}
    const actions=node('div','vp3-phone-actions'),save=node('button','vp3-phone-button vp3-phone-primary','Save Auto Call permissions');save.type='submit';save.disabled=draft.saving||changed;
    save.dataset.phoneFocusKey='auto-call-save-'+key;
    const cancel=button('Cancel',()=>{if(current(g)&&autoCallDraft===draft&&!draft.saving){autoCallDraft=null;render();}});cancel.disabled=draft.saving;
    actions.append(save,cancel);form.append(actions);
    form.addEventListener('submit',async event=>{
      event.preventDefault();
      if(!event.isTrusted||!current(g)||autoCallDraft!==draft||draft.saving||changed||autoCallKey(autoCallPolicy(contact)||{})!==key)return;
      const fresh=autoCallPolicy(contact);
      if(!fresh||fresh.revision!==draft.revision||fresh.destination_e164!==draft.destination||fresh.line_e164!==draft.line)return;
      if(draft.enabled&&!fresh.can_enable){draft.feedback=fresh.reason||'Calling prerequisites are unavailable.';render();return;}
      draft.saving=true;draft.feedback='';render();
      try{
        const result=await api('save_auto_call_policy',{contact_id:Number(contact.id),number_id:policy.number_id,expected_revision:draft.revision,enabled:draft.enabled,
          expected_destination:draft.destination,expected_line:draft.line,settings:draft.settings},'POST',g);
        if(!current(g)||autoCallDraft!==draft)return;
        if(fetching)await fetching;if(!current(g)||autoCallDraft!==draft)return;
        if(result.policy?.contact_id!==Number(contact.id)||result.policy?.number_id!==policy.number_id||result.policy?.execution_enabled!==false||
          result.policy?.enabled!==draft.enabled)throw new Error('The authorization save could not be confirmed. Reopen its current settings.');
        if(state?.auto_call?.available&&Array.isArray(state.auto_call.policies)){
          state.auto_call.policies=state.auto_call.policies.map(item=>item.contact_id===Number(contact.id)&&item.number_id===policy.number_id?result.policy:item);
        }
        autoCallDraft=null;say(draft.enabled?'Auto Call permission saved.':'Auto Call permission revoked.');render();void refresh();
      }catch(error){if(current(g)&&autoCallDraft===draft)draft.feedback=error.message;}
      finally{if(current(g)&&autoCallDraft===draft){draft.saving=false;render();}}
    });
    return form;
  }
  function operatorAppointment(raw){
    if(!raw||!positive(raw.event_id)||raw.execution_enabled!==false||typeof raw.title!=='string'||raw.title.length>380
      ||typeof raw.label!=='string'||raw.label.length>160||!Number.isFinite(Date.parse(raw.start_at))||!Number.isFinite(Date.parse(raw.end_at))||Date.parse(raw.end_at)<=Date.parse(raw.start_at))return null;
    const href=safeUrl(raw.url);if(!href)return null;const u=new URL(href);
    if(!u.pathname.endsWith('/calendar-event.php')||u.searchParams.get('id')!==String(raw.event_id)||u.hash||[...u.searchParams.keys()].length!==1)return null;
    return {...raw,url:href};
  }
  function operatorPlanModel(raw,policy){
    if(!raw||raw.contact_id!==policy.contact_id||raw.number_id!==policy.number_id||raw.revision!==policy.revision||
      raw.coverage!=='cloud_calendar_only'||raw.execution_enabled!==false||!Array.isArray(raw.slots)||raw.slots.length>3)return null;
    const existing=raw.existing===null?null:operatorAppointment(raw.existing);
    if(raw.existing!==null&&!existing||existing&&raw.slots.length)return null;
    const seen=new Set();
    for(const slot of raw.slots){
      if(!Number.isSafeInteger(slot?.start_epoch)||slot.start_epoch<1||slot.start_epoch%900!==0||
        !Number.isSafeInteger(slot.duration_seconds)||slot.duration_seconds<60||slot.duration_seconds>600||
        slot.duration_seconds%60!==0||typeof slot.label!=='string'||!slot.label||slot.label.length>160||
        typeof slot.timezone!=='string'||slot.timezone!==policy.settings.timezone||seen.has(slot.start_epoch))return null;
      seen.add(slot.start_epoch);
    }
    return {...raw,existing};
  }
  async function readOperatorPlan(contact,policy,g){
    const draft={key:autoCallKey(policy),revision:policy.revision,loading:true,saving:false,plan:null,selection:null,feedback:''};
    calendarDraft=draft;render();
    try{
      const response=await api('operator_calendar_preview',{contact_id:policy.contact_id,number_id:policy.number_id,expected_revision:policy.revision},'GET',g);
      if(!current(g)||calendarDraft!==draft)return;
      const fresh=autoCallPolicy(contact);
      if(!fresh||fresh.state!=='saved'||fresh.revision!==draft.revision||autoCallKey(fresh)!==draft.key){calendarDraft=null;return;}
      const plan=operatorPlanModel(response.plan,fresh);if(!plan)throw new Error('The suggested calling times could not be verified.');
      draft.plan=plan;draft.selection=plan.slots[0]?.start_epoch??null;
    }catch(error){if(current(g)&&calendarDraft===draft)draft.feedback=error.message;}
    finally{if(current(g)&&calendarDraft===draft){draft.loading=false;render();}}
  }
  function operatorCalendarEditor(contact,policy){
    const g=generation,draft=calendarDraft,key=autoCallKey(policy);
    const panel=node('section','vp3-phone-auto-call-form');
    panel.setAttribute('aria-label','Plan a phone call');
    panel.append(node('strong',null,'Plan a phone appointment'),node('p','vp3-phone-empty','Suggested times use your contact calling window and Cloud Calendar, including bookings and recurring events. Other calendars and the contact’s availability are not checked. Saving creates a reminder only; it does not dial or invite the contact.'));
    if(draft.loading)panel.append(node('p','vp3-phone-empty','Checking Cloud Calendar…'));
    if(draft.feedback){const status=node('p','vp3-phone-processing-error',draft.feedback);status.setAttribute('role','status');panel.append(status);}
    if(draft.plan?.existing){
      panel.append(node('p',null,draft.plan.existing.title),node('p','vp3-phone-meta',draft.plan.existing.label));
      const link=node('a','vp3-phone-report','Open Calendar appointment');link.href=draft.plan.existing.url;panel.append(link);
    }else if(draft.plan&&!draft.plan.slots.length)panel.append(node('p','vp3-phone-empty','No available time was found in the next seven days.'));
    else if(draft.plan){
      const fields=node('fieldset','vp3-phone-modules');fields.append(node('legend',null,'Suggested calling times'));
      for(const slot of draft.plan.slots){
        const label=node('label','vp3-phone-check'),radio=node('input');radio.type='radio';radio.name='operator-call-slot';radio.value=String(slot.start_epoch);
        radio.checked=draft.selection===slot.start_epoch;radio.disabled=draft.saving;
        radio.dataset.phoneFocusKey='operator-slot-'+slot.start_epoch;
        radio.addEventListener('change',()=>{if(current(g)&&calendarDraft===draft&&!draft.saving){draft.selection=slot.start_epoch;}});
        label.append(radio,node('span',null,slot.label));fields.append(label);
      }
      panel.append(fields);
      const save=button('Save Calendar appointment',async event=>{
        const slot=draft.plan.slots.find(item=>item.start_epoch===draft.selection),fresh=autoCallPolicy(contact);
        if(!event.isTrusted||!current(g)||calendarDraft!==draft||draft.saving||!slot||
          !fresh||fresh.state!=='saved'||fresh.revision!==draft.revision||autoCallKey(fresh)!==key)return;
        draft.saving=true;draft.feedback='';render();
        try{
          const response=await api('save_operator_calendar',{contact_id:policy.contact_id,number_id:policy.number_id,expected_revision:draft.revision,
            start_epoch:slot.start_epoch,duration_seconds:slot.duration_seconds},'POST',g);
          if(!current(g)||calendarDraft!==draft)return;
          const appointment=operatorAppointment(response.appointment);if(!appointment)throw new Error('Calendar save could not be confirmed. Refresh the plan before trying again.');
          draft.plan={...draft.plan,slots:[],existing:appointment};draft.selection=null;
          say('Phone appointment saved to Calendar. No call was placed.');
        }catch(error){if(current(g)&&calendarDraft===draft)draft.feedback=error.message;}
        finally{if(current(g)&&calendarDraft===draft){draft.saving=false;render();}}
      });save.disabled=draft.saving||draft.selection===null;save.dataset.phoneFocusKey='operator-calendar-save';panel.append(save);
    }
    const actions=node('div','vp3-phone-actions');
    const refreshPlan=button('Refresh suggestions',event=>{if(event.isTrusted&&current(g)&&calendarDraft===draft&&!draft.saving&&!draft.loading)void readOperatorPlan(contact,policy,g);});
    refreshPlan.disabled=draft.saving||draft.loading;actions.append(refreshPlan);
    const closePlan=button('Close plan',()=>{if(current(g)&&calendarDraft===draft&&!draft.saving){calendarDraft=null;render();}});
    closePlan.disabled=draft.saving;actions.append(closePlan);panel.append(actions);
    return panel;
  }
  const admissionPurposes={follow_up:'Agreed follow-up conversation',appointment:'Confirm an existing appointment',requested_check_in:'Recipient-requested check-in'};
  const admissionLabels={owner_policy:'Owner permission',recipient_review:'Recipient agreement review',calling_window:'Calling hours',daily_limit:'Known daily attempts',minimum_gap:'Time between calls',phone_idle:'Existing phone sessions',cloud_calendar:'Cloud Calendar',recipient_verification:'Independent consent verification',spend_and_dispatch:'Autonomous execution'};
  const admissionStates={not_recorded:'Not recorded',owner_attested:'Owner attested',cleared:'Review cleared',expired:'Expired',review_required:'Review required',withdrawn:'Recipient opted out'};
  function admissionModel(raw,policy){
    if(!raw||raw.contact_id!==policy.contact_id||raw.number_id!==policy.number_id||raw.policy_revision!==policy.revision||
      raw.destination_e164!==policy.destination_e164||raw.line_e164!==policy.line_e164||raw.execution_enabled!==false||
      !Number.isSafeInteger(raw.consent_revision)||raw.consent_revision<0||!Object.hasOwn(admissionStates,raw.consent_state)||
      (raw.purpose!==''&&!Object.hasOwn(admissionPurposes,raw.purpose))||
      !['can_attest','can_clear','can_withdraw','local_checks_pass'].every(key=>typeof raw[key]==='boolean')||
      !Number.isFinite(Date.parse(raw.checked_at))||!Array.isArray(raw.gates)||raw.gates.length!==Object.keys(admissionLabels).length)return null;
    const ids=new Set();
    for(const gate of raw.gates){
      if(!gate||!Object.hasOwn(admissionLabels,gate.id)||ids.has(gate.id)||!['pass','blocked','unverified','off'].includes(gate.status)||
        typeof gate.detail!=='string'||gate.detail.length>600)return null;
      ids.add(gate.id);
    }
    if(raw.gates.find(g=>g.id==='spend_and_dispatch')?.status!=='off'||
      raw.gates.find(g=>g.id==='recipient_verification')?.status!=='unverified')return null;
    return raw;
  }
  async function readAdmission(contact,policy,g){
    const draft={key:autoCallKey(policy),revision:policy.revision,destination:policy.destination_e164,line:policy.line_e164,
      loading:true,saving:false,model:null,purpose:'follow_up',attested:false,withdraw:false,feedback:''};
    admissionDraft=draft;render();
    try{
      const result=await api('operator_admission_preview',{contact_id:policy.contact_id,number_id:policy.number_id,expected_revision:policy.revision},'GET',g);
      if(!current(g)||admissionDraft!==draft)return;
      const fresh=autoCallPolicy(contact);
      if(!fresh||autoCallKey(fresh)!==draft.key||fresh.revision!==draft.revision||fresh.destination_e164!==draft.destination||fresh.line_e164!==draft.line){admissionDraft=null;return;}
      const model=admissionModel(result.readiness,fresh);if(!model)throw new Error('Auto Call readiness could not be verified.');
      draft.model=model;if(model.purpose)draft.purpose=model.purpose;
    }catch(error){if(current(g)&&admissionDraft===draft)draft.feedback=error.message;}
    finally{if(current(g)&&admissionDraft===draft){draft.loading=false;render();}}
  }
  function admissionEditor(contact,policy){
    const draft=admissionDraft,g=generation,key=autoCallKey(policy),panel=node('section','vp3-phone-auto-call-form');
    panel.setAttribute('aria-label','Autonomous call readiness');
    panel.append(node('strong',null,'Auto Call readiness'),node('p','vp3-phone-empty','Review the permissions for this contact and line. The Cloud worker checks these permissions again before dialing from a call agenda or assigned schedule.'));
    if(draft.loading)panel.append(node('p','vp3-phone-meta','Checking current permission and known call limits…'));
    if(draft.feedback){const p=node('p','vp3-phone-processing-error',draft.feedback);p.setAttribute('role','status');panel.append(p);}
    const model=draft.model;
    async function save(decision,event){
      const fresh=autoCallPolicy(contact);
      if(!event.isTrusted||!current(g)||admissionDraft!==draft||draft.saving||!model||!fresh||autoCallKey(fresh)!==key||
        fresh.revision!==draft.revision||fresh.destination_e164!==draft.destination||fresh.line_e164!==draft.line)return;
      if(decision==='attest'&&(!draft.attested||!model.can_attest||!Object.hasOwn(admissionPurposes,draft.purpose)))return;
      if(decision==='clear'&&!model.can_clear||decision==='withdraw'&&(!model.can_withdraw||!draft.withdraw))return;
      draft.saving=true;draft.feedback='';render();
      try{
        const result=await api('save_operator_consent',{contact_id:policy.contact_id,number_id:policy.number_id,expected_revision:draft.revision,
          expected_consent_revision:model.consent_revision,expected_destination:draft.destination,expected_line:draft.line,
          decision,...(decision==='attest'?{purpose:draft.purpose,recipient_agreement_attested:true}:{})},'POST',g);
        if(!current(g)||admissionDraft!==draft)return;
        const latest=autoCallPolicy(contact),next=latest&&admissionModel(result.readiness,latest);
        if(!next||decision==='withdraw'&&next.consent_state!=='withdrawn'||decision==='attest'&&next.consent_state!=='owner_attested')
          throw new Error('Consent review result could not be verified. Refresh before another action.');
        draft.model=next;draft.attested=false;draft.withdraw=false;
        draft.feedback=decision==='withdraw'?'Recipient opt-out saved for this destination across your Agents.':decision==='clear'?'Consent review cleared.':'Owner attestation saved. No call was placed.';
      }catch(error){if(current(g)&&admissionDraft===draft)draft.feedback=error.message;}
      finally{if(current(g)&&admissionDraft===draft){draft.saving=false;render();}}
    }
    if(model){
      panel.append(node('p','vp3-phone-meta','From '+model.line_e164+' · To '+model.destination_e164));
      panel.append(node('p',null,'Recipient review: '+admissionStates[model.consent_state]));
      if(model.expires_at&&model.consent_state==='owner_attested')panel.append(node('p','vp3-phone-meta','Expires '+displayTime(model.expires_at)));
      panel.append(node('p','vp3-phone-meta','Snapshot checked '+displayTime(model.checked_at)+'. Refresh to re-evaluate.'));
      const checks=node('dl','vp3-phone-call-details');
      for(const gate of model.gates){const row=node('div');row.append(node('dt',null,admissionLabels[gate.id]),node('dd',null,({pass:'Local check passed',blocked:'Blocked',unverified:'Not verified',off:'Disabled'}[gate.status])+'. '+gate.detail));checks.append(row);}
      if(model.holder_modes){
        for(const [key,label] of [['scheduled','Scheduled AI calls'],['spontaneous','Unscheduled AI calls']]){
          const row=node('div');row.append(node('dt',null,label),node('dd',null,model.holder_modes[key]===true?'Allowed by the connected phone holder, including recording and transcription.':'No matching phone holder permission.'));checks.append(row);
        }
      }
      panel.append(checks);
      if(model.can_attest){
        const label=node('label','vp3-phone-field','Agreed purpose'),select=node('select');select.setAttribute('aria-label','Agreed purpose');select.dataset.phoneFocusKey='admission-purpose-'+key;
        for(const [id,title] of Object.entries(admissionPurposes)){const opt=node('option',null,title);opt.value=id;select.append(opt);}select.value=draft.purpose;select.disabled=draft.saving;
        select.addEventListener('change',event=>{if(current(g)&&admissionDraft===draft){draft.purpose=select.value;draft.attested=false;render();}});label.append(select);panel.append(label);
        const checkLabel=node('label','vp3-phone-check'),check=node('input');check.type='checkbox';check.checked=draft.attested;check.disabled=draft.saving;
        check.dataset.phoneFocusKey='admission-attest-'+key;
        check.addEventListener('change',event=>{if(event.isTrusted&&current(g)&&admissionDraft===draft){draft.attested=check.checked;render();}});
        checkLabel.append(check,node('span',null,'The recipient affirmatively agreed to an AI-initiated call for this purpose, including AI disclosure, recording and transcription.'));panel.append(checkLabel);
        const attest=button('Save recipient agreement review',event=>void save('attest',event));attest.disabled=draft.saving||!draft.attested;attest.dataset.phoneFocusKey='admission-save-'+key;panel.append(attest);
      }
      if(model.can_clear){const clear=button('Clear my consent review',event=>void save('clear',event));clear.disabled=draft.saving;panel.append(clear);}
      if(model.can_withdraw){
        const label=node('label','vp3-phone-check'),check=node('input');check.type='checkbox';check.checked=draft.withdraw;check.disabled=draft.saving;check.dataset.phoneFocusKey='admission-optout-'+key;
        check.addEventListener('change',event=>{if(event.isTrusted&&current(g)&&admissionDraft===draft){draft.withdraw=check.checked;render();}});
        label.append(check,node('span',null,'The recipient withdrew permission or asked not to receive automated calls to this number.'));panel.append(label);
        const withdraw=button('Save recipient opt-out',event=>void save('withdraw',event),'vp3-phone-button vp3-phone-danger');withdraw.disabled=draft.saving||!draft.withdraw;panel.append(withdraw,node('p','vp3-phone-meta','Opt-out blocks the destination across your Agents and cannot be cleared by a new owner attestation.'));
      }
    }
    const actions=node('div','vp3-phone-actions');
    const update=button('Refresh readiness',event=>{if(event.isTrusted&&current(g)&&admissionDraft===draft&&!draft.saving&&!draft.loading)void readAdmission(contact,policy,g);});update.disabled=draft.saving||draft.loading;actions.append(update);
    const close=button('Close readiness',()=>{if(current(g)&&admissionDraft===draft&&!draft.saving){admissionDraft=null;render();}});close.disabled=draft.saving;actions.append(close);panel.append(actions);
    return panel;
  }
  function contactRow(contact){
    const renderGeneration=generation;
    const row=node('article','vp3-phone-row'),details=node('div','vp3-phone-row-copy');details.append(node('strong',null,contact.name||contact.phone_e164),node('span',null,contact.phone_e164));
    const favorite=button(contact.favorite?'★':'☆',async()=>{const g=renderGeneration;if(!current(g))return;favorite.disabled=true;try{await api('favorite',{contact_id:Number(contact.id),favorite:!contact.favorite},'POST',g);if(current(g))await refresh();}catch(error){if(current(g))say(error.message);}finally{if(current(g))favorite.disabled=false;}});favorite.setAttribute('aria-label',(contact.favorite?'Remove favorite ':'Favorite ')+(contact.name||contact.phone_e164));favorite.setAttribute('aria-pressed',String(Boolean(contact.favorite)));
    if(contact.relationship)details.append(node('span',null,contact.relationship));
    const edit=button('Edit',()=>{if(current(renderGeneration))void openPhoneContact(Number(contact.id));});edit.setAttribute('aria-label','Edit contact '+(contact.name||contact.phone_e164));edit.disabled=Boolean(autoCallDraft||calendarDraft||stateError);
    const call=button('Call',()=>{if(current(renderGeneration))void placeCall(contact.phone_e164);});call.disabled=!canCall()||Boolean(active||callBusy||setupPending||pending.size);row.append(details,favorite,edit,call);
    const policy=autoCallPolicy(contact);if(!policy)return row;
    const wrap=node('div','vp3-phone-contact-with-policy'),key=autoCallKey(policy);wrap.append(row);
    const openEditor=button('Agent Auto Call · '+({off:'Off',saved:'Permission saved',blocked:'Blocked',needs_review:'Review required'}[policy.state]),event=>{
      if(!event.isTrusted||!current(renderGeneration)||!positive(context.conversation_id)||autoCallDraft?.saving||calendarDraft||admissionDraft)return;
      const fresh=autoCallPolicy(contact);if(!fresh||autoCallKey(fresh)!==key)return;
      if(autoCallDraft?.key===key){autoCallDraft=null;render();return;}
      if(autoCallDraft)return;
      const settings={...fresh.settings};
      if(fresh.revision===0){try{settings.timezone=Intl.DateTimeFormat().resolvedOptions().timeZone||settings.timezone;}catch(_){}}
      autoCallDraft={key,revision:fresh.revision,enabled:fresh.enabled,destination:fresh.destination_e164,line:fresh.line_e164,settings,saving:false,feedback:''};render();
    });
    openEditor.setAttribute('aria-label','Agent Auto Call for '+(contact.name||contact.phone_e164));
    openEditor.setAttribute('aria-expanded',String(autoCallDraft?.key===key));openEditor.dataset.phoneFocusKey='auto-call-open-'+key;
    openEditor.disabled=!positive(context.conversation_id)||Boolean(autoCallDraft&&autoCallDraft.key!==key)||Boolean(autoCallDraft?.saving)||Boolean(calendarDraft||admissionDraft);
    wrap.append(openEditor);
    if(autoCallDraft?.key===key)wrap.append(autoCallEditor(contact,policy));
    if(policy.state==='saved'){
      const planner=button('Plan a call',event=>{
        const fresh=autoCallPolicy(contact);
        if(!event.isTrusted||!current(renderGeneration)||autoCallDraft||calendarDraft||admissionDraft||!fresh||fresh.state!=='saved'||autoCallKey(fresh)!==key||!positive(context.conversation_id))return;
        void readOperatorPlan(contact,fresh,renderGeneration);
      });
      planner.setAttribute('aria-label','Plan a call with '+(contact.name||contact.phone_e164));planner.dataset.phoneFocusKey='operator-plan-'+key;
      planner.disabled=Boolean(autoCallDraft||calendarDraft||admissionDraft)||!positive(context.conversation_id);wrap.append(planner);
      if(calendarDraft?.key===key)wrap.append(operatorCalendarEditor(contact,policy));
    }
    if(state?.operator_admission_available===true&&policy.revision>0){
      const readiness=button('Auto Call readiness',event=>{
        const fresh=autoCallPolicy(contact);
        if(event.isTrusted&&current(renderGeneration)&&!autoCallDraft&&!calendarDraft&&!admissionDraft&&fresh&&autoCallKey(fresh)===key&&fresh.revision>0)
          void readAdmission(contact,fresh,renderGeneration);
      });
      readiness.setAttribute('aria-label','Auto Call readiness for '+(contact.name||contact.phone_e164));readiness.dataset.phoneFocusKey='admission-open-'+key;
      readiness.disabled=Boolean(autoCallDraft||calendarDraft||admissionDraft)||!positive(context.conversation_id);wrap.append(readiness);
      if(admissionDraft?.key===key)wrap.append(admissionEditor(contact,policy));
    }
    return wrap;
  }
  function renderContacts(){
    const host=sections.contacts;host.replaceChildren();if(!state){listEmpty(host,'Phone contacts are unavailable.');return;}
    if(phoneContactDraft){renderPhoneContact(host);return;}
    const tools=node('div','vp3-phone-contact-toolbar'),search=node('input');search.type='search';search.placeholder='Search contacts';search.setAttribute('aria-label','Search phone contacts');search.dataset.phoneFocusKey='contact-search';search.value=contactQuery;
    const add=button('+ Contact',()=>void openPhoneContact(0),'vp3-phone-button vp3-phone-primary');add.disabled=state.contact_details_ready!==true||Boolean(stateError||autoCallDraft||calendarDraft);tools.append(search,add);host.append(tools);
    if(state.contact_details_ready===false)host.append(node('p','vp3-phone-empty','Run the Cloud database upgrade to add contacts and save private notes.'));
    if(state.auto_call?.available===false)host.append(node('p','vp3-phone-empty','Auto Call preferences are unavailable. Run the Cloud database upgrade if needed. Manual calling is unchanged.'));
    else if(state.auto_call?.available===true&&!positive(context.conversation_id))host.append(node('p','vp3-phone-empty','Open a saved private Chat to change Auto Call permissions.'));
    if(autoCallDraft&&!state.contacts.some(contact=>{const policy=autoCallPolicy(contact);return policy&&autoCallKey(policy)===autoCallDraft.key;}))autoCallDraft=null;
    if(calendarDraft&&!state.contacts.some(contact=>{const policy=autoCallPolicy(contact);return policy?.state==='saved'&&autoCallKey(policy)===calendarDraft.key&&policy.revision===calendarDraft.revision;}))calendarDraft=null;
    if(admissionDraft&&(state.operator_admission_available!==true||!state.contacts.some(contact=>{const p=autoCallPolicy(contact);return p&&autoCallKey(p)===admissionDraft.key&&p.revision===admissionDraft.revision&&p.destination_e164===admissionDraft.destination&&p.line_e164===admissionDraft.line;})))admissionDraft=null;
    const results=node('div','vp3-phone-contact-results');host.append(results);
    function fill(){results.replaceChildren();const query=contactQuery.trim().toLocaleLowerCase();const contacts=state.contacts.filter(item=>!query||(String(item.name)+' '+String(item.phone_e164)).toLocaleLowerCase().includes(query));
      const favoriteIds=new Set(state.favorites.map(item=>Number(item.id)));const favorites=contacts.filter(item=>item.favorite||favoriteIds.has(Number(item.id)));
      if(favorites.length){results.append(node('h3',null,'Favorites'));for(const item of favorites)results.append(contactRow({...item,favorite:true}));}
      results.append(node('h3',null,'Contacts'));for(const item of contacts.filter(item=>!favorites.includes(item)))results.append(contactRow(item));if(!contacts.length)listEmpty(results,'No matching phone contacts.');
    }
    if(autoCallDraft||calendarDraft||admissionDraft)search.disabled=true;
    search.addEventListener('input',()=>{if(autoCallDraft||calendarDraft||admissionDraft)return;contactQuery=search.value.slice(0,120);fill();});fill();
  }
  function phoneContactModel(raw){
    if(!raw||!positive(raw.id)||typeof raw.revision!=='string'||!/^[a-f0-9]{64}$/.test(raw.revision)
      ||typeof raw.name!=='string'||typeof raw.phone_e164!=='string'||typeof raw.relationship!=='string'||typeof raw.notes!=='string')return null;
    return {id:Number(raw.id),revision:raw.revision,name:text(raw.name).slice(0,120),phone:raw.phone_e164.slice(0,80),email:text(raw.email||'').slice(0,190),relationship:text(raw.relationship).slice(0,80),notes:text(raw.notes).slice(0,2000)};
  }
  function phoneScheduleModel(raw){
    if(!raw||!positive(raw.event_id)||raw.execution_enabled!==false||typeof raw.revision!=='string'||!/^[a-f0-9]{64}$/.test(raw.revision)
      ||!['none','daily','weekly'].includes(raw.recurrence)||typeof raw.date!=='string'||!/^\d{4}-\d{2}-\d{2}$/.test(raw.date)
      ||typeof raw.time!=='string'||!/^(?:[01]\d|2[0-3]):[0-5]\d$/.test(raw.time)||!Number.isSafeInteger(raw.duration_minutes)||raw.duration_minutes<1||typeof raw.editable!=='boolean'
      ||typeof raw.timezone!=='string'||raw.timezone.length>80||typeof raw.objectives!=='string'||typeof raw.next_label!=='string')return null;
    const href=safeUrl(raw.url);if(!href)return null;const url=new URL(href),expected=new URL('calendar-event.php',new URL(config.urlbase||'/',location.origin));
    if(url.pathname!==expected.pathname||url.searchParams.get('id')!==String(raw.event_id)||url.hash||[...url.searchParams.keys()].length!==1)return null;
    return {...raw,url:href,objectives:text(raw.objectives).slice(0,2000),next_label:text(raw.next_label).slice(0,160)};
  }
  function phoneScheduleBook(raw){
    if(!raw||typeof raw.available!=='boolean'||raw.execution_enabled!==false||!Array.isArray(raw.items)||raw.items.length>20)return null;
    const items=raw.items.map(phoneScheduleModel);if(items.some(item=>!item))return null;return {...raw,items};
  }
  async function loadPhoneContact(draft){
    const g=generation;draft.loading=true;draft.feedback='';render();
    try{
      const response=await api('phone_contact_detail',{contact_id:draft.id},'GET',g);
      if(!current(g)||phoneContactDraft!==draft)return;
      const model=phoneContactModel(response.contact),schedules=phoneScheduleBook(response.schedules);
      if(!model||model.id!==draft.id||!schedules)throw phoneError('Contact details could not be confirmed. Reload the contact.');
      draft.devices=Array.isArray(response.connected_phones)?response.connected_phones.filter(d=>publicId(d.id)&&typeof d.device_name==='string'):[];draft.deviceId=publicId(response.connected_phone_id)?response.connected_phone_id:'';
      draft.agenda=response.agenda||null;draft.editingAgenda=false;draft.model=model;draft.fields={...model};draft.editable=response.editable===true;draft.schedules=schedules;draft.dirty=false;draft.schedule=null;draft.cancelId=0;
    }catch(error){if(current(g)&&phoneContactDraft===draft)draft.feedback=error.phonePublicMessage||'Contact details could not be loaded. Retry.';}
    finally{if(current(g)&&phoneContactDraft===draft){draft.loading=false;render();}}
  }
  async function openPhoneContact(id){
    if(!current(generation)||!state||stateError||autoCallDraft||calendarDraft||phoneContactDraft?.saving)return;
    const draft={id,fields:{name:'',phone:'',email:'',relationship:'',notes:''},model:null,editable:state.contact_details_ready===true,dirty:false,loading:false,saving:false,feedback:'',schedules:null,schedule:null,cancelId:0};
    phoneContactDraft=draft;selectTab('contacts');render();if(id>0)await loadPhoneContact(draft);
  }
  function phoneDetailField(form,draft,key,labelText,type='text',limit=120){
    const label=node('label','vp3-phone-field',labelText),input=node(type==='textarea'?'textarea':'input');if(type!=='textarea')input.type=type;else input.rows=4;
    input.value=String(draft.fields[key]||'');input.maxLength=limit;input.disabled=draft.loading||draft.saving||!draft.editable;input.required=['name','phone'].includes(key);input.dataset.phoneFocusKey='contact-detail-'+key;
    if(key==='phone'){input.autocomplete='tel';input.placeholder='+1 602 555 0123';}
    if(key==='email')input.autocomplete='email';
    if(key==='name')input.autocomplete='name';if(key==='relationship')input.placeholder='Friend, family, colleague, client…';
    const g=generation;input.addEventListener('input',()=>{if(current(g)&&phoneContactDraft===draft&&!draft.saving){const firstChange=!draft.dirty;draft.fields[key]=input.value;draft.dirty=true;draft.feedback='';if(firstChange)render();}});label.append(input);form.append(label);
  }
  function renderPhoneAgenda(host,draft){
    const g=generation,fields=draft.agendaFields;
    const form=node('form','vp3-phone-agenda-form');
    form.append(node('h4',null,'Call agenda for '+draft.model.name));
    form.append(node('p','vp3-phone-empty','Add topics or questions you want Operator to discuss with this person. A saved agenda can be used once for an agreed follow-up or requested check-in.'));
    for(const [key,title] of [['topics','Conversation topics'],['questions','Specific questions']]){
      const label=node('label','vp3-phone-field',title),input=node('textarea');
      input.rows=3;input.maxLength=700;input.value=fields[key];input.disabled=draft.saving;input.dataset.phoneFocusKey='agenda-'+key;
      input.addEventListener('input',()=>{fields[key]=input.value;});label.append(input);form.append(label);
    }
    for(const [key,title] of [['review_missed','Look for a missed one-time call with this contact to follow up on'],['enabled','Let Operator initiate calls when a reason and both parties’ permissions are current']]){
      const label=node('label','vp3-phone-check'),input=node('input');input.type='checkbox';input.checked=fields[key];input.disabled=draft.saving;
      input.dataset.phoneFocusKey='agenda-'+key;input.addEventListener('change',()=>{fields[key]=input.checked;render();});
      label.append(input,node('span',null,title));form.append(label);
    }
    const line=Number(fields.number_id||state.selected_number_id);
    const number=(state.numbers||[]).find(item=>Number(item.id)===line);
    form.append(node('p','vp3-phone-empty','Calling line '+(number?.phone_e164||'unavailable')));
    const save=node('button','vp3-phone-button vp3-phone-primary','Save call agenda');save.type='submit';save.disabled=draft.saving||(!number&&fields.enabled)||Boolean(stateError);
    const cancel=button('Back to contact',()=>{draft.editingAgenda=false;draft.feedback='';render();});cancel.disabled=draft.saving;
    form.append(save,cancel);host.append(form);
    form.addEventListener('submit',async event=>{
      event.preventDefault();if(!current(g)||phoneContactDraft!==draft||draft.saving||(!number&&fields.enabled)||stateError)return;
      draft.saving=true;draft.feedback='';render();
      try{
        const result=await api('save_operator_agenda',{contact_id:draft.id,number_id:line,expected_revision:fields.revision,
          topics:fields.topics,questions:fields.questions,review_missed:fields.review_missed,enabled:fields.enabled},'POST',g);
        if(!current(g)||phoneContactDraft!==draft)return;
        if(!result.agenda||result.agenda.revision!==fields.revision+1)throw phoneError('Reload the contact to confirm its call agenda.');
        draft.agenda=result.agenda;draft.editingAgenda=false;draft.feedback='Call agenda saved.';
      }catch(error){if(current(g)&&phoneContactDraft===draft)draft.feedback=error.phonePublicMessage||'Call agenda could not be saved.';}
      finally{if(current(g)&&phoneContactDraft===draft){draft.saving=false;render();}}
    });
  }
  function renderPhoneContact(host){
    const draft=phoneContactDraft,g=generation;
    const back=button('Back to contacts',()=>{if(current(g)&&phoneContactDraft===draft&&!draft.saving){phoneContactDraft=null;render();}});back.disabled=draft.saving;host.append(back,node('h3',null,draft.id?'Contact details':'New contact'));
    if(draft.loading){host.append(node('p','vp3-phone-empty','Loading contact…'));return;}
    if(draft.feedback){const message=node('p','vp3-phone-processing-error',draft.feedback);message.setAttribute('role','status');host.append(message);}
    if(draft.id&&!draft.model){host.append(button('Retry contact',()=>void loadPhoneContact(draft)));return;}
    if(draft.editingAgenda){renderPhoneAgenda(host,draft);return;}
    const form=node('form','vp3-phone-contact-editor');form.setAttribute('aria-label',draft.id?'Edit phone contact':'Add phone contact');
    phoneDetailField(form,draft,'name','Name');phoneDetailField(form,draft,'phone','Phone number','tel',80);phoneDetailField(form,draft,'email','Email','email',190);phoneDetailField(form,draft,'relationship','Relationship','text',80);phoneDetailField(form,draft,'notes','Private notes','textarea',2000);
    if(!draft.editable)form.append(node('p','vp3-phone-empty','Run the Cloud database upgrade to edit contact details.'));
    const actions=node('div','vp3-phone-actions'),save=node('button','vp3-phone-button vp3-phone-primary','Save contact');save.type='submit';save.disabled=!draft.editable||draft.saving||Boolean(stateError);actions.append(save);
    if(draft.id){const reload=button('Reload saved contact',()=>{if(current(g)&&phoneContactDraft===draft&&!draft.saving)void loadPhoneContact(draft);});reload.disabled=draft.saving;actions.append(reload);}
    form.append(actions);form.addEventListener('submit',async event=>{
      event.preventDefault();if(!current(g)||phoneContactDraft!==draft||draft.saving||!draft.editable||stateError)return;
      draft.saving=true;draft.feedback='';render();
      try{
        const result=await api('save_phone_contact',{contact_id:draft.id,expected_revision:draft.model?.revision||'',name:draft.fields.name,phone:draft.fields.phone,email:draft.fields.email,relationship:draft.fields.relationship,notes:draft.fields.notes},'POST',g);
        if(!current(g)||phoneContactDraft!==draft)return;const contact=phoneContactModel(result.contact);
        if(!contact||(draft.id&&contact.id!==draft.id))throw phoneError('The contact save could not be confirmed. Reload before trying again.');
        draft.id=contact.id;draft.model=contact;draft.fields={...contact};draft.dirty=false;say(result.created?'Contact added.':'Contact saved.');
        if(fetching)await fetching;if(!current(g)||phoneContactDraft!==draft)return;await refresh();if(current(g)&&phoneContactDraft===draft)await loadPhoneContact(draft);
      }catch(error){if(current(g)&&phoneContactDraft===draft)draft.feedback=error.phonePublicMessage||'The contact could not be saved. Retry when connected.';}
      finally{if(current(g)&&phoneContactDraft===draft){draft.saving=false;render();}}
    });host.append(form);
    if(!draft.id){host.append(node('p','vp3-phone-empty','Save the contact to add scheduled calls.'));return;}
    if(draft.agenda){
      const edit=button('Call agenda',()=>{draft.agendaFields={...draft.agenda};draft.editingAgenda=true;draft.feedback='';render();});
      edit.disabled=draft.saving||draft.dirty;host.append(edit);
    }
    if(draft.devices?.length||draft.deviceId){
      const label=node('label','vp3-phone-field','Connected phone'),select=node('select');
      select.dataset.phoneFocusKey='contact-linked-phone';
      const none=node('option',null,'No connected phone');none.value='';select.append(none);
      for(const device of draft.devices){const option=node('option',null,device.device_name);option.value=device.id;select.append(option);}
      if(draft.deviceId&&!draft.devices.some(device=>device.id===draft.deviceId)){
        const old=node('option',null,'Previously connected phone (unavailable)');old.value=draft.deviceId;select.append(old);
      }
      select.value=draft.deviceId;select.disabled=draft.dirty||draft.saving;
      select.addEventListener('change',async()=>{
        if(!current(g)||phoneContactDraft!==draft||draft.dirty||draft.saving)return;
        const deviceId=select.value;draft.saving=true;render();
        try{
          const result=await api('assign_contact_phone',{contact_id:draft.id,device_id:deviceId,expected_revision:draft.model.revision},'POST',g);
          if(result.assigned!==true)throw phoneError('The connected phone change could not be confirmed.');
          if(current(g)&&phoneContactDraft===draft){await loadPhoneContact(draft);await refresh();}
        }catch(error){if(current(g)&&phoneContactDraft===draft)draft.feedback=error.phonePublicMessage||'Could not assign the connected phone.';}
        finally{if(current(g)&&phoneContactDraft===draft){draft.saving=false;render();}}
      });
      label.append(select);host.append(label);
    }
    renderContactSchedules(host,draft,g);
    const calls=(state?.calls||[]).filter(call=>Number(call.contact_id)===draft.id||call.peer_e164===draft.model.phone).slice(0,5);
    host.append(node('h3',null,'Recent calls with this contact'));
    if(!calls.length)host.append(node('p','vp3-phone-empty','No calls in the recent history.'));
    for(const call of calls)host.append(node('p','vp3-phone-meta',[displayTime(call.created_at),displayState(call.state),callDuration(call.duration_seconds)].filter(Boolean).join(' · ')));
  }
  function newContactSchedule(raw=null){
    const tomorrow=new Date(Date.now()+86400000),pad=value=>String(value).padStart(2,'0');let timezone='UTC';try{timezone=Intl.DateTimeFormat().resolvedOptions().timeZone||timezone;}catch(_){}
    const mutation=globalThis.crypto?.randomUUID?.()||Date.now().toString(36)+'-'+Math.random().toString(36).slice(2)+'-'+Math.random().toString(36).slice(2);
    return {event_id:raw?.event_id||0,expected_revision:raw?.revision||'',mutation_id:'phone-schedule-'+mutation,
      date:raw?.date||tomorrow.getFullYear()+'-'+pad(tomorrow.getMonth()+1)+'-'+pad(tomorrow.getDate()),time:raw?.time||'09:00',timezone:raw?.timezone||timezone,
      recurrence:raw?.recurrence||'none',duration_minutes:raw?.duration_minutes||10,objectives:raw?.objectives||'',feedback:''};
  }
  function scheduleWorkerStatus(worker){
    if(!worker)return 'Cloud worker status unavailable';
    if(worker.state!=='stale'&&worker.state!=='observed')return 'No run observed';
    const lastRun=worker.state==='stale'?'Last run is overdue':'Recent run';
    const mode=worker.mode==='dispatch'?'Last run allowed dialing':worker.mode==='prepare'?'Last run was preparation only':'Last run mode unknown';
    return [lastRun,worker.outcome==='failed'&&'Last run reported an error',mode,worker.last_observed_at&&displayTime(worker.last_observed_at)].filter(Boolean).join(' · ');
  }
  function renderContactSchedules(host,draft,g){
    const section=node('section','vp3-phone-contact-schedules');section.append(node('h3',null,'Scheduled calls'),node('p','vp3-phone-empty','Create a reminder, then assign it to Operator for a scheduled agent call.'));
    section.append(node('p','vp3-phone-meta','Cloud schedule worker · '+scheduleWorkerStatus(state?.schedule_worker)));
    if(!draft.schedules?.available){section.append(node('p','vp3-phone-empty','Run the Cloud Calendar upgrade to manage scheduled calls.'));host.append(section);return;}
    const blocked=draft.saving||draft.dirty||Boolean(stateError);
    if(draft.dirty)section.append(node('p','vp3-phone-empty','Save the contact changes before editing a schedule.'));
    if(draft.schedule){renderContactScheduleForm(section,draft,g);host.append(section);return;}
    for(const schedule of draft.schedules.items){
      const card=node('article','vp3-phone-scheduled-call');card.append(node('strong',null,({none:'One time',daily:'Every day',weekly:'Every week'})[schedule.recurrence]),node('p','vp3-phone-meta',schedule.next_label||'No upcoming occurrence'));
      if(schedule.objectives)card.append(node('p','vp3-phone-schedule-objectives',schedule.objectives));
      const actions=node('div','vp3-phone-actions'),edit=button('Edit schedule',()=>{if(current(g)&&phoneContactDraft===draft&&!draft.saving&&!draft.dirty&&!stateError&&schedule.editable){draft.schedule=newContactSchedule(schedule);draft.cancelId=0;render();}});
      const cancel=button('Cancel schedule',()=>{if(current(g)&&phoneContactDraft===draft&&!draft.saving&&!draft.dirty&&!stateError){draft.cancelId=schedule.event_id;render();}});edit.disabled=blocked||!schedule.editable;cancel.disabled=blocked;actions.append(edit,cancel);
      if(Number.isSafeInteger(schedule.assignment_revision)&&['off','review','assigned'].includes(schedule.operator_assignment)){
        const assigned=schedule.operator_assignment==='assigned',line=selectedNumber();
        card.append(node('p','vp3-phone-meta',assigned?'Assigned to Operator. Dialing requires current call permissions.':schedule.operator_assignment==='review'?'Schedule changed. Review the Operator assignment.':'Calendar reminder'));
        if(schedule.operator_assignment==='review'){
          const remove=button('Remove previous assignment',async event=>{
            if(!event.isTrusted||!current(g)||phoneContactDraft!==draft||draft.saving||draft.dirty)return;
            await changeAssignment(false);
          });
          remove.disabled=blocked;actions.append(remove);
        }
        const changeAssignment=async enable=>{
          draft.saving=true;render();
          try{
            const result=await api('assign_scheduled_operator',{event_id:schedule.event_id,contact_id:draft.id,expected_revision:schedule.revision,
              assignment_revision:schedule.assignment_revision,number_id:line?.id,expected_destination:draft.model.phone,expected_line:line?.phone_e164,enabled:enable},'POST',g);
            if(result.assigned!==enable)throw phoneError('The assignment could not be confirmed.');
            if(current(g)&&phoneContactDraft===draft)await loadPhoneContact(draft);
          }catch(error){if(current(g)&&phoneContactDraft===draft)draft.feedback=error.phonePublicMessage||'Reload the contact to check its Operator assignment.';}
          finally{if(current(g)&&phoneContactDraft===draft){draft.saving=false;render();}}
        };
        const assign=button(assigned?'Remove Operator assignment':'Assign schedule to Operator',async event=>{
          if(!event.isTrusted||!current(g)||phoneContactDraft!==draft||draft.saving||draft.dirty)return;
          if(!assigned&&!window.confirm('Assign every occurrence of this schedule to Operator, calling '+draft.model.phone+' from '+line.phone_e164+'?'))return;
          await changeAssignment(!assigned);
        });
        assign.disabled=blocked||(!assigned&&(!line||!schedule.objectives));actions.append(assign);
      }
      if(!schedule.editable)card.append(node('p','vp3-phone-empty','This event has additional Calendar details. Open it in Calendar to edit.'));
      const link=node('a','vp3-phone-report','Open in Calendar');link.href=schedule.url;card.append(actions,link);
      if(draft.cancelId===schedule.event_id){
        card.append(node('p','vp3-phone-empty',schedule.recurrence==='none'?'Cancel this call reminder?':'Cancel this schedule and all future repeats?'));
        const confirm=button('Confirm cancellation',async()=>{if(!current(g)||phoneContactDraft!==draft||draft.saving||draft.dirty)return;draft.saving=true;draft.feedback='';render();
          try{const result=await api('cancel_phone_contact_schedule',{contact_id:draft.id,event_id:schedule.event_id,expected_revision:schedule.revision,confirmed:true},'POST',g);
            if(!current(g)||phoneContactDraft!==draft)return;if(result.cancelled!==true||result.event_id!==schedule.event_id||result.execution_enabled!==false)throw phoneError('Schedule cancellation could not be confirmed. Reload the contact.');
            say('Scheduled call canceled.');await loadPhoneContact(draft);
          }catch(error){if(current(g)&&phoneContactDraft===draft)draft.feedback=error.phonePublicMessage||'Schedule cancellation could not be confirmed. Reload before trying again.';}
          finally{if(current(g)&&phoneContactDraft===draft){draft.saving=false;render();}}
        },'vp3-phone-button vp3-phone-danger');confirm.disabled=blocked;
        const keep=button('Keep schedule',()=>{if(current(g)&&phoneContactDraft===draft&&!draft.saving){draft.cancelId=0;render();}});keep.disabled=draft.saving;card.append(confirm,keep);
      }
      section.append(card);
    }
    if(!draft.schedules.items.length)section.append(node('p','vp3-phone-empty','No scheduled calls.'));
    const add=button('+ Scheduled call',()=>{if(current(g)&&phoneContactDraft===draft&&!draft.saving&&!draft.dirty&&!stateError){draft.schedule=newContactSchedule();render();}});add.disabled=blocked||draft.schedules.items.length>=20;section.append(add);
    const reasons={conversation_active:'Conversation in progress',conversation_completed:'Recipient ended the conversation by voice',
      conversation_silent:'Call ended while waiting for a spoken response',conversation_turn_limit:'Conversation reached its eight-turn limit',
      conversation_reply_withheld:'Call authorization ended before the agent could reply',conversation_model_failed:'Agent could not generate a reply',conversation_ended:'Call ended during the conversation',ended_before_consent:'Call ended before the recipient confirmed',carrier_busy:'Recipient line was busy',carrier_no_answer:'Call went unanswered',carrier_failed:'Carrier could not complete the call',carrier_canceled:'Call canceled',answer_prompted:'Waiting for the recipient’s keypad response',answer_declined:'Recipient declined the conversation',answer_no_input:'No keypad response',answer_consented:'Recipient agreed to a recorded and transcribed conversation',answer_permission_changed:'Call ended because permission or availability changed',carrier_submitting:'Carrier submission pending confirmation',carrier_uncertain:'Carrier response uncertain. Check call history before retrying.',carrier_submitted:'Carrier accepted the call request',calendar_conflict:'Another Calendar event or booking overlaps the call',chat_unavailable:'Assigned Chat is unavailable',awaiting_dispatch_checks:'Prepared for dispatch checks',recipient_permission:'Blocked by recipient preferences',dispatch_window_expired:'Call window ended before dialing',window_ended:'Missed call window',contact_or_line_changed:'Contact or line changed'};
    if(Array.isArray(draft.schedules.history)&&draft.schedules.history.length){
      const history=node('details','vp3-phone-scheduled-history');
      history.append(node('summary',null,'Scheduled call history'));
      for(const entry of draft.schedules.history.slice(0,20)){
        const start=Number(entry?.start_epoch);
        if(!Number.isSafeInteger(start)||start<=0||!Object.hasOwn(reasons,entry.reason))continue;
        const row=node('p','vp3-phone-meta',[new Date(start*1000).toLocaleString(),'Schedule #'+entry.event_id,reasons[entry.reason]].join(' · '));
        history.append(row);
      }
      section.append(history);
    }
    host.append(section);
  }
  function renderContactScheduleForm(host,draft,g){
    const schedule=draft.schedule,form=node('form','vp3-phone-schedule-form');form.setAttribute('aria-label','Scheduled call');
    for(const [key,labelText,type] of [['date',schedule.event_id?'Series start date':'First call date','date'],['time','Call time','time'],['timezone','Time zone','text'],['duration_minutes','Call length (minutes)','number']]){
      const label=node('label','vp3-phone-field',labelText),input=node('input');input.type=type;input.required=true;input.value=String(schedule[key]);input.disabled=draft.saving;input.dataset.phoneFocusKey='contact-schedule-'+key;
      if(key==='duration_minutes'){input.min='1';input.max='60';input.step='1';}if(key==='timezone'){input.maxLength=80;input.placeholder='America/Phoenix';}
      input.addEventListener('input',()=>{if(current(g)&&phoneContactDraft===draft&&draft.schedule===schedule&&!draft.saving)schedule[key]=type==='number'?Number(input.value):input.value;});label.append(input);form.append(label);
    }
    const repeatLabel=node('label','vp3-phone-field','Repeat'),repeat=node('select');repeat.setAttribute('aria-label','Repeat');repeat.dataset.phoneFocusKey='contact-schedule-recurrence';
    for(const [value,title] of [['none','One time'],['daily','Every day'],['weekly','Every week']]){const option=node('option',null,title);option.value=value;repeat.append(option);}repeat.value=schedule.recurrence;repeat.disabled=draft.saving;
    repeat.addEventListener('change',()=>{if(current(g)&&phoneContactDraft===draft&&draft.schedule===schedule&&!draft.saving)schedule.recurrence=repeat.value;});repeatLabel.append(repeat);form.append(repeatLabel);
    const notesLabel=node('label','vp3-phone-field','Call notes and objectives'),notes=node('textarea');notes.rows=4;notes.maxLength=2000;notes.value=schedule.objectives;notes.disabled=draft.saving;notes.dataset.phoneFocusKey='contact-schedule-objectives';notes.addEventListener('input',()=>{if(current(g)&&phoneContactDraft===draft&&draft.schedule===schedule&&!draft.saving)schedule.objectives=notes.value;});notesLabel.append(notes);form.append(notesLabel);
    form.append(node('p','vp3-phone-empty','Repeats keep the same local time and objectives. Calendar conflicts are checked for the next 90 days; no invitation or automatic call is sent.'));
    if(schedule.feedback){const message=node('p','vp3-phone-processing-error',schedule.feedback);message.setAttribute('role','status');form.append(message);}
    const actions=node('div','vp3-phone-actions'),save=node('button','vp3-phone-button vp3-phone-primary','Save scheduled call');save.type='submit';save.disabled=draft.saving||draft.dirty||Boolean(stateError)||!positive(state?.selected_number_id);actions.append(save);
    const cancel=button('Close schedule',()=>{if(current(g)&&phoneContactDraft===draft&&!draft.saving){draft.schedule=null;render();}});cancel.disabled=draft.saving;actions.append(cancel);form.append(actions);
    form.addEventListener('submit',async event=>{event.preventDefault();if(!current(g)||phoneContactDraft!==draft||draft.schedule!==schedule||draft.saving||draft.dirty||stateError||!positive(state?.selected_number_id))return;
      draft.saving=true;schedule.feedback='';render();
      try{const result=await api('save_phone_contact_schedule',{contact_id:draft.id,contact_revision:draft.model.revision,number_id:Number(state.selected_number_id),
          event_id:schedule.event_id,expected_revision:schedule.expected_revision,mutation_id:schedule.mutation_id,date:schedule.date,time:schedule.time,timezone:schedule.timezone,recurrence:schedule.recurrence,duration_minutes:schedule.duration_minutes,objectives:schedule.objectives,confirmed:true},'POST',g);
        if(!current(g)||phoneContactDraft!==draft)return;const saved=phoneScheduleModel(result.schedule);if(!saved||result.execution_enabled!==false)throw phoneError('The schedule save could not be confirmed. Reload before trying again.');
        say('Call schedule saved to Calendar.');await loadPhoneContact(draft);
      }catch(error){if(current(g)&&phoneContactDraft===draft&&draft.schedule===schedule)schedule.feedback=error.phonePublicMessage||'The schedule could not be saved. Retry when connected.';}
      finally{if(current(g)&&phoneContactDraft===draft){draft.saving=false;render();}}
    });host.append(form);
  }
  function renderCalls(){
    const host=sections.calls;if(!state?.calls.length){host.replaceChildren();listEmpty(host,state?'No recent calls.':'Recent calls are unavailable.');return;}
    const renderGeneration=generation,contents=[];for(const call of state.calls){if(call.transport_kind==='managed_callback'&&Number(call.conversation_id)===context.conversation_id){contents.push(callCard(call));continue;}const row=node('article','vp3-phone-row'),details=node('div','vp3-phone-row-copy'),contact=callerContact(call);details.append(node('strong',null,contact?.name||callPeerName(call)));if(contact)details.append(node('span',null,call.peer_e164));details.append(node('span',null,[displayState(localStates.get(call.public_id)||call.state),displayTime(call.created_at)].filter(Boolean).join(' · ')));const callback=button('Call back',()=>{if(current(renderGeneration))void placeCall(call.peer_e164);});callback.disabled=!canCall()||Boolean(active||callBusy||setupPending||pending.size)||!/^\+[1-9]\d{7,14}$/.test(call.peer_e164||'');row.append(details,callback);contents.push(row);}replacePhoneContent(host,...contents);
  }
  function voicemailCard(voicemail){
    const renderGeneration=generation;
    const card=node('article','vp3-phone-voicemail');card.append(node('strong',null,(voicemail.kind==='call_recording'?'Call recording from ':'Voicemail from ')+peerName(voicemail.peer_e164)),node('p','vp3-phone-meta',[displayTime(voicemail.created_at),displayState(voicemail.state||voicemail.stage||'saved')].filter(Boolean).join(' · ')));
    const contact=callerContact(voicemail);if(contact){card.querySelector('strong').textContent=(voicemail.kind==='call_recording'?'Call recording from ':'Voicemail from ')+text(contact.name).slice(0,180);card.append(node('p','vp3-phone-peer-number',voicemail.peer_e164));}
    if(voicemail.state==='failed'||voicemail.error||voicemail.last_error)card.append(node('p','vp3-phone-processing-error',voicemail.error||voicemail.last_error||'Call processing needs attention.'));
    appendRecording(card,voicemail,renderGeneration);
    if(voicemail.kind==='voicemail')renderVoicemailActions(card,voicemail,renderGeneration);
    const callback=button('Call back',()=>{if(current(renderGeneration))void placeCall(voicemail.peer_e164);});callback.disabled=!canCall()||Boolean(active||callBusy||setupPending||pending.size)||!/^\+[1-9]\d{7,14}$/.test(voicemail.peer_e164||'');card.append(callback);return card;
  }
  function appendRecording(card,voicemail,renderGeneration,isCall=false){
    const audioUrl=safeUrl(voicemail.audio_url);if(audioUrl){const audio=node('audio');audioNodes.add(audio);audio.controls=true;audio.preload='none';audio.src=audioUrl;audio.dataset.phoneRecordingKey=voicemailKey(voicemail.id);audio.setAttribute('aria-label',isCall?'Play call recording':'Play voicemail');audio.addEventListener('play',()=>{if(!current(renderGeneration)){audio.pause();return;}if(!voicemail.read)void api('mark_voicemail_read',{voicemail_id:voicemailKey(voicemail.id)},'POST',renderGeneration).catch(()=>{});});card.append(audio);}
    if(voicemail.transcript){
      card.append(node('strong',null,'Transcript'),node('p','vp3-phone-transcript',voicemail.transcript));
    }else if(voicemail.transcription_requested===true){
      const worker=state?.processing_worker?.state;
      if(voicemail.state==='queued')card.append(node('p','vp3-phone-meta',worker==='stale'||worker==='never_seen'?'Transcription queued. Waiting for the recording worker.':'Transcription queued.'));
      if(voicemail.state==='transcribing')card.append(node('p','vp3-phone-meta','Transcribing voicemail…'));
    }
    const transcriptUrl=safeUrl(voicemail.transcript_url);if(transcriptUrl){const transcript=node('a','vp3-phone-report','Open transcript');transcript.href=transcriptUrl;card.append(transcript);}
    const reportUrl=safeUrl(voicemail.report_url);if(reportUrl){const report=node('a','vp3-phone-report',isCall?'Open call report':'Open voicemail report');report.href=reportUrl;card.append(report);}
  }
  function renderVoicemails(){
    if(state?.recording_readiness?.ready===false){
      const contents=[node('p','vp3-phone-empty','Phone recordings need the Cloud database upgrade. Ask an administrator to run upgrade.php, then retry.')];
      if(!stateError)contents.push(button('Retry',()=>void refresh()));
      replacePhoneContent(sections.voicemail,...contents);return;
    }
    const controls=node('div');if(state)renderVoicemailSettings(controls);
    const contents=state?.voicemails.length?state.voicemails.map(voicemailCard):[node('p','vp3-phone-empty',state?'No saved voicemail.':'Voicemail is unavailable.')];replacePhoneContent(sections.voicemail,...controls.childNodes,...contents);
  }
  function voicemailSettingsDraft(){
    const saved=state?.voicemail_settings;
    if(!saved?.available||Number(saved.number_id)!==Number(state?.selected_number_id))return null;
    if(!voicemailDraft||voicemailDraft.number_id!==Number(saved.number_id)||!voicemailDraft.dirty){voicemailDraft={number_id:Number(saved.number_id),revision:Number(saved.revision),rings:Number(saved.rings),transcribe:enabled(saved.transcribe),announce:enabled(saved.announce),dirty:false};}
    return voicemailDraft;
  }
  function voicemailSettingsResult(saved,number,revision){
    if(saved?.available!==true||Number(saved.number_id)!==number||!Number.isSafeInteger(saved.revision)||saved.revision<=revision||!Number.isSafeInteger(saved.rings)||saved.rings<2||saved.rings>10||saved.ring_seconds!==saved.rings*6||typeof saved.transcribe!=='boolean'||typeof saved.announce!=='boolean'||typeof saved.greeting?.present!=='boolean')throw phoneError('Voicemail returned an incomplete confirmation. Refresh its settings before trying again.');
    return saved;
  }
  function renderVoicemailSettings(host){
    const saved=state?.voicemail_settings;if(!saved)return;
    const section=node('section','vp3-phone-voicemail-settings');section.setAttribute('aria-label','Voicemail settings');
    section.append(node('h3',null,'Voicemail settings'));
    if(!saved.available){section.append(node('p','vp3-phone-empty',saved.reason_code==='private_owner_required'?'Open your private Phone workspace to manage voicemail.':'Voicemail settings need the Cloud database upgrade. Ask an administrator to run upgrade.php.'));host.append(section);return;}
    const draft=voicemailSettingsDraft();if(!draft)return;const g=generation;
    section.append(node('p','vp3-phone-meta',selectedNumber()?.phone_e164||''));
    const form=node('form','vp3-phone-voicemail-form'),label=node('label','vp3-phone-field','Approximate rings before voicemail'),select=node('select');select.setAttribute('aria-label','Approximate rings before voicemail');select.dataset.phoneFocusKey='voicemail-rings';
    for(let rings=2;rings<=10;rings++){const option=node('option',null,rings+' rings · about '+(rings*6)+' seconds');option.value=String(rings);select.append(option);}select.value=String(draft.rings);select.disabled=voicemailBusy;
    select.addEventListener('change',()=>{if(current(g)&&voicemailDraft===draft){draft.rings=Number(select.value);draft.dirty=true;}});label.append(select);form.append(label);
    form.append(node('p','vp3-phone-empty','Rings are approximate; carriers control the ringing sound. If browser calling is unavailable, voicemail answers immediately.'));
    for(const [key,caption] of [['transcribe','Automatically transcribe new voicemail'],['announce','Post new voicemail in Agent Chat']]){
      const row=node('label','vp3-phone-check'),input=node('input');input.type='checkbox';input.checked=draft[key];input.disabled=voicemailBusy;input.dataset.phoneFocusKey='voicemail-'+key;
      input.addEventListener('change',()=>{if(current(g)&&voicemailDraft===draft){draft[key]=input.checked;draft.dirty=true;}});row.append(input,node('span',null,caption));form.append(row);
    }
    form.append(node('p','vp3-phone-empty','Transcription uses your existing Cloud speech service and may use credits. New voicemail and available transcripts are posted in Chat when enabled.'));
    const worker=state.processing_worker||{};form.append(node('p','vp3-phone-worker-status',worker.state==='observed'&&worker.observation_scope==='server'?'Server worker observed'+(displayTime(worker.last_observed_at)?' '+displayTime(worker.last_observed_at):'')+'.':'Automatic processing is waiting for the server worker.'));
    const save=node('button','vp3-phone-button','Save voicemail settings');save.type='submit';save.disabled=voicemailBusy||Boolean(stateError);form.append(save);
    form.addEventListener('submit',async event=>{event.preventDefault();if(!current(g)||stateError||voicemailBusy||voicemailDraft!==draft||Number(state?.selected_number_id)!==draft.number_id)return;draft.dirty=true;voicemailBusy=true;render();try{const result=await api('save_voicemail_settings',{number_id:draft.number_id,expected_revision:draft.revision,rings:draft.rings,transcribe:draft.transcribe,announce:draft.announce},'POST',g);if(!current(g)||voicemailDraft!==draft||Number(state?.selected_number_id)!==draft.number_id)return;state.voicemail_settings=voicemailSettingsResult(result.voicemail_settings,draft.number_id,draft.revision);voicemailDraft=null;say('Voicemail settings saved.');await refresh();}catch(error){if(current(g))say(error.phonePublicMessage||'The voicemail settings could not be confirmed. Refresh before trying again.');}finally{if(current(g)){voicemailBusy=false;render();}}});section.append(form);
    const greeting=node('div','vp3-phone-greeting');greeting.append(node('h4',null,'Personal greeting'));
    if(greetingCapture)greeting.append(node('p','vp3-phone-empty',greetingCapture.phase==='recording'?'Recording your greeting. Stops after 60 seconds.':'Waiting for microphone permission…'));
    else greeting.append(node('p','vp3-phone-empty',greetingDraft?'Preview your new greeting before saving.':saved.greeting?.present?'Your recorded greeting is active.':'The standard greeting is active.'));
    const preview=greetingDraft?.url||safeUrl(saved.greeting?.preview_url);if(preview&&!greetingCapture){const audio=node('audio');audio.controls=true;audio.preload='none';audio.src=preview;audio.dataset.phoneRecordingKey='greeting-'+draft.number_id;audio.setAttribute('aria-label','Preview voicemail greeting');audioNodes.add(audio);greeting.append(audio);}
    const actions=node('div','vp3-phone-greeting-actions');
    if(greetingCapture){actions.append(button('Stop recording greeting',()=>{finishGreeting();render();}),button('Cancel greeting',()=>{clearGreeting();render();}));}
    else{
      const record=button(greetingDraft?'Record again':'Record greeting',event=>{if(event.isTrusted&&current(g))void startGreeting(draft);});record.disabled=voicemailBusy||Boolean(active||callBusy||setupPending||pending.size||stateError)||!greetingCaptureAsset||!greetingEndpoint;actions.append(record);
      if(greetingDraft){const saveGreeting=button('Save greeting',()=>void saveGreetingDraft());saveGreeting.disabled=voicemailBusy||Boolean(stateError);actions.append(saveGreeting,button('Cancel greeting',()=>{clearGreeting();render();}));}
      else if(saved.greeting?.present){const reset=button('Use standard greeting',async()=>{if(voicemailBusy||stateError||!current(g)||Number(state?.selected_number_id)!==draft.number_id)return;const revision=draft.revision;voicemailBusy=true;render();try{const result=await api('use_default_voicemail_greeting',{number_id:draft.number_id,expected_revision:revision},'POST',g);if(current(g)&&Number(state?.selected_number_id)===draft.number_id){state.voicemail_settings=voicemailSettingsResult(result.voicemail_settings,draft.number_id,revision);if(voicemailDraft?.number_id===draft.number_id)voicemailDraft.revision=Number(result.voicemail_settings.revision);say('Standard voicemail greeting restored.');}}catch(error){if(current(g))say(error.phonePublicMessage||'The greeting change could not be confirmed. Refresh before trying again.');}finally{if(current(g)){voicemailBusy=false;render();}}});reset.disabled=voicemailBusy||Boolean(stateError);actions.append(reset);}
    }
    greeting.append(actions,node('p','vp3-phone-empty','Record 1–60 seconds. Callers still hear the recording notice before leaving a message.'));section.append(greeting);host.append(section);
  }
  function stopGreetingCapture(){
    const capture=greetingCapture;greetingCapture=null;if(!capture)return null;capture.stopped=true;clearTimeout(capture.timer);
    try{capture.source?.disconnect();capture.worklet?.disconnect();if(capture.worklet)capture.worklet.port.onmessage=null;}catch(_){}
    for(const track of capture.stream?.getTracks?.()||[])track.stop();capture.ticket?.release();capture.ticket=null;void capture.ctx?.close?.().catch(()=>{});return capture;
  }
  function clearGreeting(){
    stopGreetingCapture();if(greetingSaveRequest){greetingSaveRequest.abort();greetingSaveRequest=null;voicemailBusy=false;}
    for(const audio of [...audioNodes])if(String(audio.dataset.phoneRecordingKey||'').startsWith('greeting-')){audio.pause();audio.removeAttribute('src');audio.load();audioNodes.delete(audio);}
    if(greetingDraft?.url)URL.revokeObjectURL(greetingDraft.url);greetingDraft=null;
  }
  async function startGreeting(draft){
    if(stateError||voicemailBusy||greetingCapture||active||callBusy||setupPending||pending.size||Number(state?.selected_number_id)!==draft.number_id||!greetingCaptureAsset||!greetingEndpoint)return;
    clearGreeting();clearAudio();const g=generation,capture={generation:g,number_id:draft.number_id,revision:draft.revision,chunks:[],bytes:0,phase:'starting',stopped:false};greetingCapture=capture;render();
    try{
      const lease=window.StonefellowVoiceLeaseV122;capture.ticket=lease?.acquireCapture?.('phone-voicemail-greeting');if(!capture.ticket)throw new Error('Stop the other microphone session before recording your greeting.');
      const AudioContext=window.AudioContext||window.webkitAudioContext;if(!AudioContext||!navigator.mediaDevices?.getUserMedia||!window.AudioWorkletNode)throw new Error('Greeting recording is unavailable in this browser.');
      capture.ctx=new AudioContext();await capture.ctx.resume();await capture.ctx.audioWorklet.addModule(greetingCaptureAsset);
      if(!current(g)||greetingCapture!==capture||capture.stopped)return;
      const stream=await navigator.mediaDevices.getUserMedia({audio:{echoCancellation:true,noiseSuppression:true},video:false});
      if(!current(g)||greetingCapture!==capture||capture.stopped){for(const track of stream.getTracks())track.stop();return;}
      capture.stream=stream;if(!capture.ticket.isCurrent?.()||capture.ticket.ownStream(stream)!==true)throw new Error('Microphone ownership changed. Record your greeting again.');capture.source=capture.ctx.createMediaStreamSource(stream);capture.worklet=new AudioWorkletNode(capture.ctx,'vp3-voicemail-greeting-v1');
      capture.worklet.port.onmessage=event=>{if(greetingCapture!==capture||!current(g)||!(event.data instanceof ArrayBuffer)||event.data.byteLength!==3200||capture.bytes>=1920000)return;capture.chunks.push(new Uint8Array(event.data));capture.bytes+=event.data.byteLength;};
      capture.source.connect(capture.worklet);capture.worklet.connect(capture.ctx.destination);capture.phase='recording';capture.timer=setTimeout(()=>{if(greetingCapture===capture){finishGreeting();render();}},60000);render();
    }catch(error){if(greetingCapture===capture){stopGreetingCapture();if(current(g)){say(error.name==='NotAllowedError'?'Microphone permission was not allowed.':error.message);render();}}}
  }
  function finishGreeting(){
    const capture=stopGreetingCapture();if(!capture||!current(capture.generation))return;
    if(capture.bytes<32000){say('Record at least one second before saving your greeting.');return;}
    const buffer=new ArrayBuffer(44+capture.bytes),view=new DataView(buffer),bytes=new Uint8Array(buffer);const write=(offset,value)=>{for(let i=0;i<value.length;i++)view.setUint8(offset+i,value.charCodeAt(i));};
    write(0,'RIFF');view.setUint32(4,36+capture.bytes,true);write(8,'WAVE');write(12,'fmt ');view.setUint32(16,16,true);view.setUint16(20,1,true);view.setUint16(22,1,true);view.setUint32(24,16000,true);view.setUint32(28,32000,true);view.setUint16(32,2,true);view.setUint16(34,16,true);write(36,'data');view.setUint32(40,capture.bytes,true);let offset=44;for(const chunk of capture.chunks){bytes.set(chunk,offset);offset+=chunk.length;}
    const blob=new Blob([buffer],{type:'audio/wav'});greetingDraft={blob,url:URL.createObjectURL(blob),number_id:capture.number_id,revision:capture.revision,generation:capture.generation};say('Preview your greeting, then save it.');
  }
  async function saveGreetingDraft(){
    const draft=greetingDraft,g=generation;if(!draft||draft.generation!==g||stateError||voicemailBusy||!current(g)||Number(state?.selected_number_id)!==draft.number_id||!greetingEndpoint)return;
    const revision=draft.revision;voicemailBusy=true;render();const controller=new AbortController();greetingSaveRequest=controller;requests.add(controller);const timer=setTimeout(()=>controller.abort(),30000);
    try{
      const form=new FormData();for(const [key,value] of Object.entries(scopeBody({csrf_token:config.csrf,number_id:draft.number_id,expected_revision:revision})))form.append(key,String(value));form.append('greeting',draft.blob,'greeting.wav');
      const response=await fetch(greetingEndpoint,{method:'POST',credentials:'same-origin',cache:'no-store',headers:{Accept:'application/json'},body:form,signal:controller.signal});
      if(current(g)&&(response.status===401||response.status===403)){revoke();return;}
      if(!current(g)||greetingDraft!==draft||Number(state?.selected_number_id)!==draft.number_id)return;const data=await response.json();
      if(!current(g)||greetingDraft!==draft||Number(state?.selected_number_id)!==draft.number_id)return;
      if(!response.ok||data?.ok!==true)throw phoneError(response.status===409?'Voicemail settings changed. Record and review a new greeting before saving.':'The greeting could not be saved. Try recording again.');
      state.voicemail_settings=voicemailSettingsResult(data.voicemail_settings,draft.number_id,revision);if(voicemailDraft?.number_id===draft.number_id)voicemailDraft.revision=Number(data.voicemail_settings.revision);clearGreeting();say('Your personal voicemail greeting is saved.');render();
    }catch(error){if(current(g)&&greetingDraft===draft&&Number(state?.selected_number_id)===draft.number_id)say(error.phonePublicMessage||(error.name==='AbortError'?'Saving the greeting timed out. Refresh to check whether it was saved.':'The greeting could not be confirmed. Refresh before trying again.'));}
    finally{clearTimeout(timer);requests.delete(controller);if(greetingSaveRequest===controller){greetingSaveRequest=null;if(current(g)){voicemailBusy=false;render();}}}
  }
  function renderVoicemailActions(card,voicemail,g){
    const id=voicemailKey(voicemail.id);if(!id)return;const pendingAction=voicemailActions.get(id),actions=node('div','vp3-phone-voicemail-actions');
    const invoke=async action=>{
      if(!current(g)||stateError||voicemailActions.get(id)?.busy)return;voicemailActions.set(id,{busy:true,action});render();
      try{const result=await api(action,{voicemail_id:id},'POST',g);if(!current(g))return;
        if(action==='delete_voicemail'){
          if(result.deleted!==true||!Array.isArray(result.redacted_chat_message_ids)||result.redacted_chat_message_ids.length>100||result.redacted_chat_message_ids.some(value=>!Number.isSafeInteger(value)||value<1))throw phoneError('The voicemail deletion could not be confirmed. Refresh its status before trying again.');
          clearAudio();state.voicemails=state.voicemails.filter(item=>item.id!==id);say('Voice message deleted.');window.dispatchEvent(new CustomEvent('vp3:phone-workspace-event',{detail:{kind:'voicemail_deleted',conversation_id:context.conversation_id,agent_id:context.agent_id,voicemail_id:id,redacted_chat_message_ids:[...new Set(result.redacted_chat_message_ids)]}}));
        }
        else if(action==='transcribe_voicemail')say(result.already_transcribed?'This voicemail already has a saved transcript.':'Voicemail transcription queued. Its saved result will appear here.');
        else{if(result.posted!==true||Number(result.conversation_id)!==context.conversation_id)throw phoneError('The voicemail Chat post could not be confirmed. Refresh before trying again.');say('Voicemail posted in Agent Chat.');window.dispatchEvent(new CustomEvent('vp3:phone-workspace-event',{detail:{kind:'voicemail',conversation_id:result.conversation_id,agent_id:context.agent_id}}));}
        await refresh();
      }catch(error){if(current(g))say(error.message);}finally{if(current(g)){voicemailActions.delete(id);render();}}
    };
    if(pendingAction?.confirm){actions.append(node('p','vp3-phone-empty','Delete this voice message, its saved transcript and its Phone posts in Chat?'),button('Delete voicemail permanently',()=>void invoke('delete_voicemail')),button('Keep voicemail',()=>{voicemailActions.delete(id);render();}));}
    else{
      if(voicemail.can_transcribe){const transcribe=button('Transcribe voicemail',()=>void invoke('transcribe_voicemail'));transcribe.disabled=Boolean(pendingAction?.busy||stateError);actions.append(transcribe);}
      if(voicemail.can_post){const post=button('Post voicemail to Chat',()=>void invoke('post_voicemail'));post.disabled=Boolean(pendingAction?.busy||stateError);actions.append(post);}
      if(voicemail.can_delete){const remove=button('Delete voicemail',()=>{if(current(g)&&!stateError){voicemailActions.set(id,{confirm:true});render();}});remove.disabled=Boolean(pendingAction?.busy||stateError);actions.append(remove);}
    }
    if(pendingAction?.busy)actions.append(node('p','vp3-phone-empty','Updating voicemail…'));card.append(actions);
  }
  const voicePrefix="This is the VP3 AI assistant, relaying the owner's approved response. ";
  async function toggleVoiceArm(event,g){
    if(!event.isTrusted||!current(g)||deviceSession||active||callBusy||pending.size||state?.operator_live?.voice_available!==true)return;
    if(voiceArm){voiceArm.mixer.close();voiceArm=null;render();return;}
    const Audio=window.AudioContext||window.webkitAudioContext;
    if(typeof Audio!=='function'||typeof window.VP3OperatorVoiceMixerV1?.create!=='function')return;
    let arm;
    try{
      const audio=new Audio();
      const mixer=window.VP3OperatorVoiceMixerV1.create(audio,reason=>{
        if(deviceSession?.voiceMixer!==mixer)return;
        if(reason==='audio_context_lost'||reason==='input_lost')stopDevice('The prepared phone audio path was interrupted. Reconnect browser calls.');
        else if(active){stopRemoteCaptions(active,'Agent audio path changed. Human control restored.');render();}
      });
      arm={mixer,pending:true,generation:g};voiceArm=arm;render();
      await audio.resume();
      if(!current(g)||voiceArm!==arm||audio.state!=='running'){mixer.close();if(voiceArm===arm)voiceArm=null;return;}
      arm.pending=false;say('Agent voice path prepared. Enable browser calls when ready.');render();
    }catch(error){arm?.mixer.close();if(voiceArm===arm)voiceArm=null;if(current(g)){say('Agent voice preparation unavailable. Manual phone calling is unchanged.');render();}}
  }
  function voiceMixerCurrent(entry){
    return Boolean(entry&&active===entry&&entry.phase==='active'&&sessionCurrent(entry.session)&&entry.ticket?.isCurrent()&&
      entry.call?.isMuted?.()!==true&&entry.session.voiceMixer?.matches(entry.call?.getLocalStream?.()));
  }
  function canReviewVoice(entry,session){
    const suggestion=session.suggestion;
    return captionCurrent(entry,session)&&session.phase==='listening'&&state?.operator_live?.voice_available===true&&
      voiceMixerCurrent(entry)&&!session.busy&&!session.voiceTurn&&suggestion&&typeof suggestion.proof==='string'&&/^[a-f0-9]{64}$/.test(suggestion.proof)&&
      Array.from(suggestion.text).length<=180&&!/[<>]/.test(suggestion.text)&&suggestion.seq>session.voiceUsedSeq&&session.deadline>Date.now()+15000;
  }
  function takeOverVoice(entry,reason='You took over. AI speech and live assistance stopped.'){
    // Local source stops synchronously before any server request; do not mute
    // or disconnect the existing human call.
    entry?.session?.voiceMixer?.stop();
    if(entry?.captionSession)stopRemoteCaptions(entry,reason);
    render();
  }
  function decodeVoicePcm(raw,samples){
    if(!Number.isSafeInteger(samples)||samples<1600||samples>320000||typeof raw!=='string'||raw.length!==Math.ceil(samples*2/3)*4)
      throw new Error('The prepared voice audio is invalid.');
    const binary=atob(raw);if(binary.length!==samples*2)throw new Error('The prepared voice audio is invalid.');
    const pcm=new Int16Array(samples);
    for(let i=0;i<samples;i++){const n=binary.charCodeAt(i*2)|(binary.charCodeAt(i*2+1)<<8);pcm[i]=n>32767?n-65536:n;}
    return pcm;
  }
  async function prepareVoiceTurn(entry,session,event){
    if(!event.isTrusted||!canReviewVoice(entry,session)||session.voiceConsent!==true)return;
    const suggestion=session.suggestion;
    const turn={state:'preparing',seq:suggestion.seq,script:voicePrefix+suggestion.text,token:'',pcm:null,samples:0,timer:0,poll:0};
    session.voiceTurn=turn;session.voiceConsent=false;session.voiceReview=false;session.voiceUsedSeq=turn.seq;session.busy=true;
    // Discard partial caption frames and pause the caption worklet until the
    // approved turn is finished; AI audio must not masquerade as owner speech.
    session.worklet.port.postMessage('stop');render();
    try{
      const result=await liveCaptionRequest(entry,session,'voice_prepare',{token:session.token,source_seq:turn.seq,
        suggestion:suggestion.text,proof:suggestion.proof,voice_consent_attested:true});
      if(!captionCurrent(entry,session)||session.voiceTurn!==turn)return;
      if(!voiceMixerCurrent(entry)||result.call_id!==entry.info.public_id||result.source_seq!==turn.seq||result.script!==turn.script||
        result.state!=='ready'||result.sample_rate!==16000||typeof result.turn_token!=='string'||!/^[a-f0-9]{64}$/.test(result.turn_token)||
        !Number.isSafeInteger(result.expires_epoch)||result.expires_epoch*1000<=Date.now())throw new Error('The approved voice turn could not be verified.');
      turn.pcm=decodeVoicePcm(result.pcm,result.samples);turn.samples=result.samples;turn.token=result.turn_token;turn.expires=result.expires_epoch*1000;turn.state='ready';
      turn.timer=setTimeout(()=>{if(captionCurrent(entry,session)&&session.voiceTurn===turn)takeOverVoice(entry,'Prepared voice expired. Human control restored.');},Math.max(0,turn.expires-Date.now()));
      render();
    }catch(error){if(captionCurrent(entry,session)&&session.voiceTurn===turn)takeOverVoice(entry,error.message||'Voice preparation stopped. Human control restored.');}
  }
  async function finishVoiceTurn(entry,session,turn){
    if(!captionCurrent(entry,session)||session.voiceTurn!==turn)return;
    entry.session.voiceMixer?.stop();clearTimeout(turn.timer);clearInterval(turn.poll);turn.pcm?.fill(0);turn.pcm=null;turn.state='finishing';render();
    try{
      const result=await liveCaptionRequest(entry,session,'voice_finish',{token:session.token,source_seq:turn.seq,turn_token:turn.token});
      if(!captionCurrent(entry,session)||session.voiceTurn!==turn)return;
      if(result.call_id!==entry.info.public_id||result.source_seq!==turn.seq||result.state!=='closed')throw new Error('Voice turn closure was not verified.');
      session.voiceTurn=null;session.suggestion=null;session.busy=false;session.voiceNotice='Approved turn finished locally. You are speaking again.';
      session.worklet.port.postMessage('start');render();
    }catch(error){if(captionCurrent(entry,session))takeOverVoice(entry,'Voice turn closure was uncertain. Human control restored.');}
  }
  async function speakVoiceTurn(entry,session,event){
    const turn=session.voiceTurn;
    if(!event.isTrusted||!captionCurrent(entry,session)||!voiceMixerCurrent(entry)||turn?.state!=='ready'||Date.now()>=turn.expires)return;
    clearTimeout(turn.timer);turn.state='claiming';render();
    try{
      const result=await liveCaptionRequest(entry,session,'voice_claim',{token:session.token,source_seq:turn.seq,turn_token:turn.token});
      if(!captionCurrent(entry,session)||session.voiceTurn!==turn)return;
      if(!voiceMixerCurrent(entry)||result.call_id!==entry.info.public_id||result.source_seq!==turn.seq||result.samples!==turn.samples||
        result.state!=='playing'||!Number.isSafeInteger(result.start_by_epoch)||!Number.isSafeInteger(result.expires_epoch)||
        Date.now()>result.start_by_epoch*1000||Date.now()+turn.samples/16>result.expires_epoch*1000)
        throw new Error('The approved voice playback permission expired.');
      turn.state='playing';
      entry.session.voiceMixer.play(turn.pcm,entry.call.getLocalStream(),()=>void finishVoiceTurn(entry,session,turn));
      turn.pcm.fill(0);turn.pcm=null;
      turn.timer=setTimeout(()=>{if(captionCurrent(entry,session)&&session.voiceTurn===turn)takeOverVoice(entry,'Voice turn time limit reached. Human control restored.');},Math.max(0,result.expires_epoch*1000-Date.now()));
      turn.poll=setInterval(()=>{if(captionCurrent(entry,session)&&session.voiceTurn===turn){if(!voiceMixerCurrent(entry))takeOverVoice(entry,'Voice audio changed. Human control restored.');else void refresh();}},2000);
      render();
    }catch(error){if(captionCurrent(entry,session)&&session.voiceTurn===turn)takeOverVoice(entry,error.message||'Voice turn stopped. Human control restored.');}
  }
  function renderVoiceTurn(panel,entry,session){
    if(session.voiceNotice)panel.append(node('p','vp3-phone-meta',session.voiceNotice));
    const turn=session.voiceTurn;
    if(turn){
      const box=node('section','vp3-phone-suggestion');box.append(node('strong',null,'Operator voice · '+({preparing:'Preparing',ready:'Ready for your approval',claiming:'Authorizing playback',playing:'AI speaking',finishing:'Returning to owner'}[turn.state]||'Working')));
      box.append(node('p',null,turn.script));
      if(turn.state==='ready'){
        const play=button('Speak approved response',event=>void speakVoiceTurn(entry,session,event),'vp3-phone-button vp3-phone-primary');play.dataset.phoneFocusKey='operator-voice-play';box.append(play);
      }
      const takeover=button('Take over now',event=>{if(event.isTrusted&&captionCurrent(entry,session))takeOverVoice(entry);},'vp3-phone-button vp3-phone-danger');takeover.dataset.phoneFocusKey='operator-voice-takeover';box.append(takeover);panel.append(box);return;
    }
    if(!canReviewVoice(entry,session))return;
    const review=button(session.voiceReview?'Cancel voice review':'Review Agent voice response',event=>{
      if(event.isTrusted&&canReviewVoice(entry,session)){session.voiceReview=!session.voiceReview;session.voiceConsent=false;render();}
    });review.dataset.phoneFocusKey='operator-voice-review';panel.append(review);
    if(!session.voiceReview)return;
    const box=node('section','vp3-phone-suggestion');box.append(node('strong',null,'Exact synthetic voice script'),node('p',null,voicePrefix+session.suggestion.text),
      node('p','vp3-phone-meta','Ask everyone for agreement to this AI voice turn. Preparation sends this script to the Cloud speech service and may incur a charge. Playback requires a second explicit click.'));
    const label=node('label','vp3-phone-check'),check=node('input');check.type='checkbox';check.checked=session.voiceConsent===true;check.dataset.phoneFocusKey='operator-voice-consent';
    check.addEventListener('change',event=>{if(event.isTrusted&&captionCurrent(entry,session)){session.voiceConsent=check.checked;render();}});
    label.append(check,node('span',null,'Everyone agrees to hear this AI assistant, and I approve the exact script.'));box.append(label);
    const prepare=button('Prepare approved voice',event=>void prepareVoiceTurn(entry,session,event));prepare.disabled=!session.voiceConsent;prepare.dataset.phoneFocusKey='operator-voice-prepare';box.append(prepare);panel.append(box);
  }
  async function liveCaptionRequest(entry,session,action,fields={}){
    if(!liveEndpoint||!current(session.generation)||active!==entry||entry.captionSession!==session)throw new Error('Live caption context changed.');
    const controller=new AbortController();session.request=controller;const timer=setTimeout(()=>controller.abort(),38000);
    try{
      const response=await fetch(liveEndpoint,{method:'POST',credentials:'same-origin',cache:'no-store',signal:controller.signal,
        headers:{Accept:'application/json','Content-Type':'application/json'},body:JSON.stringify({action,csrf_token:config.csrf,call_id:entry.info.public_id,
          ...session.scope,...fields})});
      const data=await response.json();
      if(!response.ok||data?.ok!==true)throw new Error(typeof data?.message==='string'?text(data.message).slice(0,300):'Live caption request could not be verified.');
      return data;
    }finally{clearTimeout(timer);if(session.request===controller)session.request=null;}
  }
  function stopCaptionReceipt(entry,session,token){
    if(!liveEndpoint||typeof token!=='string'||!/^[a-f0-9]{64}$/.test(token))return;
    // Stop is bound to the original Chat, never the newly selected Chat.
    // Local capture has already stopped; a failed stop request cannot send audio.
    void fetch(liveEndpoint,{method:'POST',credentials:'same-origin',cache:'no-store',keepalive:true,
      headers:{Accept:'application/json','Content-Type':'application/json'},body:JSON.stringify({action:'stop',csrf_token:config.csrf,
        call_id:entry.info.public_id,...session.scope,token})}).catch(()=>{});
  }
  function stopRemoteCaptions(entry,reason='Live captions stopped.'){
    if(!entry)return;const session=entry.captionSession;
    entry.captionPrompt=false;entry.captionConsent=false;entry.captionBoth=false;entry.captionAssist=false;
    if(!session)return;
    entry.session?.voiceMixer?.stop();
    if(session.voiceTurn){clearTimeout(session.voiceTurn.timer);clearInterval(session.voiceTurn.poll);session.voiceTurn.pcm?.fill(0);session.voiceTurn.pcm=null;session.voiceTurn=null;}
    entry.captionSession=null;entry.captionNotice=reason;entry.captionClosed=Boolean(session.startSent);
    session.phase='closed';session.request?.abort();clearTimeout(session.timer);
    // This is a derived remote stream; do not stop or mute the actual phone tracks.
    if(session.track&&session.trackEnded)session.track.removeEventListener('ended',session.trackEnded);
    if(session.localTrack&&session.trackEnded)session.localTrack.removeEventListener('ended',session.trackEnded);
    if(session.worklet){session.worklet.port.onmessage=null;try{session.worklet.port.postMessage('stop');session.worklet.port.close();session.worklet.disconnect();}catch(_){}}
    try{session.source?.disconnect();}catch(_){}
    try{session.localSource?.disconnect();}catch(_){}
    if(session.context){session.context.onstatechange=null;try{void session.context.close().catch(()=>{});}catch(_){}}
    session.captions.length=0;session.receipts.length=0;session.suggestion=null;stopCaptionReceipt(entry,session,session.token);
  }
  function captionCurrent(entry,session){
    return current(session.generation)&&active===entry&&entry.captionSession===session&&entry.phase==='active'&&sessionCurrent(entry.session);
  }
  function encodeCaptionPcm(buffer){
    if(!(buffer instanceof ArrayBuffer)||buffer.byteLength!==128000)throw new Error('Invalid caption audio frame.');
    const bytes=new Uint8Array(buffer),parts=[];
    for(let offset=0;offset<bytes.length;offset+=8192)parts.push(String.fromCharCode(...bytes.subarray(offset,offset+8192)));
    return btoa(parts.join(''));
  }
  function captionTracksCurrent(entry,session){
    const remote=entry.call?.getRemoteStream?.()?.getAudioTracks?.()||[];
    if(remote.length!==1||remote[0]!==session.track||session.track.readyState!=='live'||session.track.enabled===false)return false;
    if(session.mode==='both'){
      const local=entry.call?.getLocalStream?.()?.getAudioTracks?.()||[];
      if(local.length!==1||local[0]!==session.localTrack||session.localTrack.readyState!=='live'||session.localTrack.enabled===false||
        entry.call?.isMuted?.()===true||!entry.ticket?.isCurrent())return false;
    }
    return true;
  }
  function addCaptionLine(session,side,value,seq){
    if(typeof value!=='string'||value.length>2400)throw new Error('The live caption result could not be verified.');
    if(value.trim())session.captions.push({side,text:text(value),seq});
    while(session.captions.length>8)session.captions.shift();
  }
  function readCaptionReceipts(session,result,seq){
    if(!session.assist)return;
    const expected=[['remote_audio',result.text],...(session.mode==='both'?[['local_audio',result.local_text]]:[])].filter(([,value])=>value.trim());
    if(!Array.isArray(result.receipts)||result.receipts.length!==expected.length)throw new Error('Caption evidence could not be verified.');
    for(let i=0;i<expected.length;i++){
      const [speaker,value]=expected[i],receipt=result.receipts[i];
      if(!receipt||receipt.seq!==seq||receipt.speaker!==speaker||receipt.text!==value||typeof receipt.proof!=='string'||!/^[a-f0-9]{64}$/.test(receipt.proof))
        throw new Error('Caption evidence could not be verified.');
      session.receipts.push({seq,speaker,text:value,proof:receipt.proof});
    }
    session.receipts=session.receipts.filter(r=>r.seq>=Math.max(1,seq-7)).slice(-8);
    while(session.receipts.reduce((sum,r)=>sum+Array.from(r.text).length,0)>5000)session.receipts.shift();
  }
  async function requestPrivateSuggestion(entry,session,event){
    if(!event.isTrusted||!captionCurrent(entry,session)||session.phase!=='listening'||!session.assist||session.busy||
      session.suggestionAttempts>=2||Date.now()<session.nextSuggestionAt||!session.receipts.some(r=>r.speaker==='remote_audio'))return;
    if(!captionTracksCurrent(entry,session)){stopRemoteCaptions(entry,'Call audio changed. Live captions stopped.');render();return;}
    const last=session.receipts.at(-1)?.seq||0;if(last<=session.lastSuggestionSeq)return;
    const receipts=session.receipts.map(r=>({...r}));
    session.busy=true;session.suggestionPending=true;session.suggestion=null;session.suggestionError='';
    session.suggestionAttempts++;session.lastSuggestionSeq=last;session.nextSuggestionAt=Date.now()+20000;render();
    try{
      const result=await liveCaptionRequest(entry,session,'suggest',{token:session.token,receipts});
      if(!captionCurrent(entry,session))return;
      if(!captionTracksCurrent(entry,session)){stopRemoteCaptions(entry,'Call audio changed. Live captions stopped.');render();return;}
      if(result.call_id!==entry.info.public_id||result.based_on_seq!==last||result.speech_enabled!==false||
        typeof result.suggestion!=='string'||!result.suggestion.trim()||result.suggestion.length>1000||
        !Number.isInteger(result.prior_call_count)||result.prior_call_count<0||result.prior_call_count>3)
        throw new Error('The response suggestion could not be verified.');
      session.suggestion={text:result.suggestion,prior:result.prior_call_count,seq:last,proof:typeof result.speech_proof==='string'?result.speech_proof:''};session.voiceReview=false;session.voiceConsent=false;
    }catch(error){if(captionCurrent(entry,session))session.suggestionError=error.message||'Private suggestion unavailable.';}
    finally{if(captionCurrent(entry,session)){session.busy=false;session.suggestionPending=false;render();}}
  }
  async function sendRemoteCaption(entry,session,buffer){
    if(!captionCurrent(entry,session)||session.phase!=='listening')return;
    if(!captionTracksCurrent(entry,session)){stopRemoteCaptions(entry,'Call audio changed. Live captions stopped.');render();return;}
    // Slow speech responses never accumulate a private audio backlog.
    if(session.busy){session.skipped=Math.min(999,session.skipped+1);render();return;}
    if(!captionTracksCurrent(entry,session)){stopRemoteCaptions(entry,'Call audio changed. Live captions stopped.');render();return;}
    if(session.seq>(session.mode==='both'?15:30)||Date.now()>=session.deadline){stopRemoteCaptions(entry,'Live caption allowance ended.');render();return;}
    session.busy=true;const seq=session.seq++;
    try{
      const fields={token:session.token,seq,pcm:encodeCaptionPcm(session.mode==='both'?buffer?.remote:buffer)};
      if(session.mode==='both')fields.local_pcm=encodeCaptionPcm(buffer?.local);
      const result=await liveCaptionRequest(entry,session,'chunk',fields);
      if(!captionCurrent(entry,session))return;
      if(result.call_id!==entry.info.public_id||result.seq!==seq||result.speaker!=='remote_audio'||typeof result.text!=='string'||result.text.length>2400)
        throw new Error('The live caption result could not be verified.');
      if(!captionTracksCurrent(entry,session)){stopRemoteCaptions(entry,'Call audio changed. Live captions stopped.');render();return;}
      addCaptionLine(session,'remote_audio',result.text,seq);
      if(session.mode==='both')addCaptionLine(session,'local_audio',result.local_text,seq);
      else if('local_text' in result)throw new Error('Unexpected local caption.');
      readCaptionReceipts(session,result,seq);
      render();
    }catch(error){if(captionCurrent(entry,session)){stopRemoteCaptions(entry,error.message||'Live captions stopped.');render();}}
    finally{session.busy=false;if(captionCurrent(entry,session))render();}
  }
  async function startRemoteCaptions(entry,event){
    const view=state?.operator_live;
    if(!event.isTrusted||!liveEndpoint||!captureAsset||active!==entry||entry.phase!=='active'||entry.captionSession||
      !entry.captionConsent||entry.captionClosed||view?.eligible!==true||view.state!=='off'||view.call_id!==entry.info.public_id)return;
    const Audio=window.AudioContext||window.webkitAudioContext;
    const remote=entry.call?.getRemoteStream?.(),tracks=remote?.getAudioTracks?.()||[];
    const mode=view.assist_available===true&&entry.captionBoth===true?'both':'remote';
    const assist=view.assist_available===true&&entry.captionAssist===true;
    const local=mode==='both'?entry.call?.getLocalStream?.():null,localTracks=local?.getAudioTracks?.()||[];
    if(typeof Audio!=='function'||typeof window.AudioWorkletNode!=='function'||tracks.length!==1||tracks[0].readyState!=='live'){
      entry.captionNotice='A supported live remote audio stream is not available. Manual calling is unchanged.';entry.captionPrompt=false;entry.captionConsent=false;entry.captionBoth=false;entry.captionAssist=false;render();return;
    }
    if(mode==='both'&&(localTracks.length!==1||localTracks[0]===tracks[0]||localTracks[0].readyState!=='live'||localTracks[0].enabled===false||
      entry.call?.isMuted?.()===true||!entry.ticket?.isCurrent())){
      entry.captionNotice='Both-sided capture requires the unmuted, already-owned local phone track. No new microphone will be opened.';render();return;
    }
    const session={generation,scope:{conversation_id:context.conversation_id,agent_id:context.agent_id},phase:'starting',captions:[],receipts:[],
      skipped:0,seq:1,busy:false,startSent:false,track:tracks[0],localTrack:localTracks[0],mode,assist,token:'',
      suggestionAttempts:0,lastSuggestionSeq:0,nextSuggestionAt:0,suggestionPending:false,suggestion:null,suggestionError:'',
      voiceTurn:null,voiceUsedSeq:0,voiceReview:false,voiceConsent:false,voiceNotice:''};
    entry.captionSession=session;entry.captionPrompt=false;entry.captionConsent=false;entry.captionBoth=false;entry.captionAssist=false;entry.captionNotice='';render();
    try{
      session.context=new Audio();
      if(!session.context.audioWorklet||session.context.sampleRate<16000||session.context.sampleRate>96000)throw new Error('This browser does not support live caller audio capture.');
      await session.context.resume();await session.context.audioWorklet.addModule(captureAsset);
      if(!captionCurrent(entry,session)||session.context.state!=='running'||!captionTracksCurrent(entry,session))throw new Error('The required call audio is not available.');
      session.source=session.context.createMediaStreamSource(remote);
      if(mode==='both')session.localSource=session.context.createMediaStreamSource(local);
      session.worklet=new AudioWorkletNode(session.context,'vp3-operator-remote-capture-v1',{numberOfInputs:mode==='both'?2:1,numberOfOutputs:1,outputChannelCount:[1],channelCount:1,channelCountMode:'explicit',processorOptions:{mode}});
      session.worklet.port.onmessage=message=>{if(captionCurrent(entry,session)&&session.phase==='listening')void sendRemoteCaption(entry,session,message.data);};
      session.worklet.onprocessorerror=()=>{if(captionCurrent(entry,session)){stopRemoteCaptions(entry,'Remote caption capture stopped.');render();}};
      session.trackEnded=()=>{if(captionCurrent(entry,session)){stopRemoteCaptions(entry,'Remote call audio ended.');render();}};
      session.track.addEventListener('ended',session.trackEnded,{once:true});
      session.localTrack?.addEventListener('ended',session.trackEnded,{once:true});
      // The explicit consent confirmation was made before any audio capture.
      session.startSent=true;
      const result=await liveCaptionRequest(entry,session,'start',{consent_attested:true,...(view.assist_available===true?{capture_mode:mode,suggestions_attested:assist}:{})});
      if(!captionCurrent(entry,session)){stopCaptionReceipt(entry,session,result.token);return;}
      if(result.call_id!==entry.info.public_id||typeof result.token!=='string'||!/^[a-f0-9]{64}$/.test(result.token)||
        result.execution!=='captions_only'||result.chunk_seconds!==4||!Number.isSafeInteger(result.expires_epoch))throw new Error('Live caption authorization could not be verified.');
      session.token=result.token;
      if((result.capture_mode??'remote')!==mode||(result.suggestions_enabled??false)!==assist)throw new Error('Live assistance preferences could not be verified.');
      session.deadline=Math.min(Date.now()+(mode==='both'?60000:120000),result.expires_epoch*1000);
      if(session.deadline<=Date.now()||session.context.state!=='running'||!captionTracksCurrent(entry,session))throw new Error('Live caption authorization expired.');
      session.phase='listening';session.timer=setTimeout(()=>{if(captionCurrent(entry,session)){stopRemoteCaptions(entry,'Live caption time limit reached.');render();}},session.deadline-Date.now());
      session.context.onstatechange=()=>{if(captionCurrent(entry,session)&&session.context.state!=='running'){stopRemoteCaptions(entry,'Browser audio processing stopped.');render();}};
      session.source.connect(session.worklet,0,0);if(session.localSource)session.localSource.connect(session.worklet,0,1);
      session.worklet.connect(session.context.destination);
      // Worklet output is always silent: do not duplicate or inject audio.
      session.worklet.port.postMessage('start');render();
    }catch(error){if(captionCurrent(entry,session)){stopRemoteCaptions(entry,error.message||'Live captions are unavailable.');render();}}
  }
  function renderRemoteCaptions(card,entry){
    if(!entry||entry!==active||entry.phase!=='active'||!liveEndpoint||!captureAsset)return;
    const view=state?.operator_live,session=entry.captionSession;
    if(view?.available!==true&&!session&&!entry.captionNotice)return;
    const panel=node('section','vp3-phone-live-caption');panel.append(node('strong',null,'Operator · Live caller captions'));
    panel.append(node('p','vp3-phone-meta','Short Cloud speech segments may be delayed or missed. Processing uses your speech allowance and may incur provider charges. Live captions and private suggestions are not saved in Chat.'));
    if(entry.captionNotice)panel.append(node('p','vp3-phone-meta',entry.captionNotice));
    if(session){
      panel.append(node('p','vp3-phone-meta',session.phase==='listening'?'Live captions on · human call remains in control':'Authorizing live captions…'));
      if(session.captions.length){const list=node('div','vp3-phone-caption-lines');list.setAttribute('role','log');list.setAttribute('aria-live','polite');
        for(const line of session.captions){const block=node('div','vp3-phone-caption-turn');block.append(node('strong',null,line.side==='local_audio'?'Your audio':'Caller audio'),node('p',null,line.text));list.append(block);}panel.append(list);}
      panel.append(node('p','vp3-phone-meta',session.mode==='both'?'Both audio sides · labels describe tracks, not verified people':'Caller audio only'));
      if(session.skipped)panel.append(node('p','vp3-phone-meta',session.skipped+' audio segments skipped while processing.'));
      if(session.assist){
        const suggest=button(session.suggestionPending?'Preparing suggestion…':'Suggest a response',event=>void requestPrivateSuggestion(entry,session,event));
        suggest.disabled=session.phase!=='listening'||session.busy||session.suggestionAttempts>=2||Date.now()<session.nextSuggestionAt||!session.receipts.some(r=>r.speaker==='remote_audio')||(session.receipts.at(-1)?.seq||0)<=session.lastSuggestionSeq;
        suggest.dataset.phoneFocusKey='suggest-response-'+entry.info.public_id;panel.append(suggest);
        if(session.suggestion){const note=node('div','vp3-phone-suggestion');note.append(node('strong',null,'Private suggestion · review before saying'),node('p',null,session.suggestion.text),node('p','vp3-phone-meta','Based on captions through segment '+session.suggestion.seq+' and '+session.suggestion.prior+' prior call records. Not sent or spoken.'));const linked=state?.companion_cards?.[entry.info.public_id];
          if(linked)appendShareDraftButton(note,entry.info.public_id,linked,session.suggestion.text,'Review suggestion for phone');
          panel.append(note);}
        if(session.suggestionError)panel.append(node('p','vp3-phone-meta',session.suggestionError));
        renderVoiceTurn(panel,entry,session);
      }
      const stop=button('Stop live captions',event=>{if(event.isTrusted&&active===entry){stopRemoteCaptions(entry);render();}});stop.dataset.phoneFocusKey='stop-caption-'+entry.info.public_id;panel.append(stop);
    }else if(view?.call_id===entry.info.public_id&&view.eligible===true&&view.state==='off'&&!entry.captionClosed){
      if(!entry.captionPrompt){
        const start=button('Live transcription',event=>{if(event.isTrusted&&active===entry){entry.captionPrompt=true;entry.captionConsent=false;entry.captionBoth=false;entry.captionAssist=false;render();}});
        start.dataset.phoneFocusKey='enable-caption-'+entry.info.public_id;panel.append(start);
      }else{
        panel.append(node('p',null,'Ask everyone on the call whether they agree to live AI transcription using the Cloud speech service. A recording notice alone is not consent to this feature.'));
        const label=node('label','vp3-phone-check'),check=node('input');check.type='checkbox';check.checked=entry.captionConsent===true;
        check.dataset.phoneFocusKey='consent-caption-'+entry.info.public_id;
        check.addEventListener('change',event=>{if(event.isTrusted&&active===entry){entry.captionConsent=check.checked;render();}});
        label.append(check,node('span',null,'Everyone on this call has agreed to live Cloud AI transcription.'));panel.append(label);
        if(view.assist_available===true){
          const bothLabel=node('label','vp3-phone-check'),both=node('input');both.type='checkbox';both.checked=entry.captionBoth===true;
          both.dataset.phoneFocusKey='both-caption-'+entry.info.public_id;
          both.addEventListener('change',event=>{if(event.isTrusted&&active===entry){entry.captionBoth=both.checked;render();}});
          bothLabel.append(both,node('span',null,'Include both audio sides (everyone agrees; up to 60 seconds).'));panel.append(bothLabel);
          const assistLabel=node('label','vp3-phone-check'),assist=node('input');assist.type='checkbox';assist.checked=entry.captionAssist===true;
          assist.dataset.phoneFocusKey='assist-caption-'+entry.info.public_id;
          assist.addEventListener('change',event=>{if(event.isTrusted&&active===entry){entry.captionAssist=assist.checked;render();}});
          assistLabel.append(assist,node('span',null,'Everyone agrees that recent captions may be sent to my configured AI model for private response suggestions (up to two paid requests).'));panel.append(assistLabel);
        }
        const actions=node('div','vp3-phone-actions'),start=button('Start live captions',event=>void startRemoteCaptions(entry,event),'vp3-phone-button vp3-phone-primary');
        start.disabled=!entry.captionConsent;start.dataset.phoneFocusKey='start-caption-'+entry.info.public_id;
        const cancel=button('Cancel',()=>{if(active===entry){entry.captionPrompt=false;entry.captionConsent=false;entry.captionBoth=false;entry.captionAssist=false;render();}});actions.append(start,cancel);panel.append(actions);
      }
    }else if(!entry.captionNotice)panel.append(node('p','vp3-phone-meta',view?.state==='authorized'?'Live caption permission belongs to another browser session.':view?.state==='closed'?'Live captions are closed for this call.':view?.reason||'Waiting for a verified answered call and transcription permission.'));
    card.append(panel);
  }
  const managedControlBusy=new Set();
  async function runManagedControl(action,id,g){
    if(!current(g)||managedControlBusy.has(id)||!publicId(id))return;
    managedControlBusy.add(id);render();
    try{
      const result=await api(action,{call_id:id,...(action==='managed_end'?{confirmed:true}:{})},'POST',g);
      if(!current(g))return;
      const row=result.call;
      if(row?.public_id===id&&row.transport_kind==='managed_callback'&&state){
        state.calls=state.calls.map(c=>c.public_id===id?{...c,...row}:c);
        if(state.active_call?.public_id===id)state.active_call={...state.active_call,...row};
      }
      say(action==='managed_end'?'End request processed. Review carrier evidence; hang up on the phone if needed.':'Carrier status checked. Unresolved calls remain unconfirmed.');
      render();void refresh();
    }catch(error){if(current(g))say(error.message||'Carrier result was not confirmed.');}
    finally{managedControlBusy.delete(id);if(current(g))render();}
  }

  // One private, foreground, context-bound review; never persist report speech.
  let managedReview=null,managedReviewTimer=0;
  function clearManagedReview(){
    clearTimeout(managedReviewTimer);managedReviewTimer=0;managedReview=null;
    for(const el of document.querySelectorAll('[data-managed-conversation-review]'))el.remove();
  }
  function reviewCurrent(item){
    return managedReview===item&&current(item.g)&&context.ready&&!document.hidden&&
      context.conversation_id===item.conversation&&context.agent_id===item.agent;
  }
  async function reviewManagedCall(info,g){
    if(!current(g)||document.hidden||!context.ready||info.transport_kind!=='managed_callback'||
      info.state!=='completed'||Number(info.conversation_id)!==context.conversation_id||!publicId(info.public_id))return;
    clearManagedReview();
    const item={id:info.public_id,g,conversation:context.conversation_id,agent:context.agent_id,busy:true,data:null,error:''};
    managedReview=item;render();
    try{
      const result=await api('managed_review',{call_id:item.id},'GET',g);
      if(!reviewCurrent(item))return;
      const data=result?.review;
      if(!data||data.call_id!==item.id||data.execution_enabled!==false)throw new Error('Conversation evidence was not confirmed.');
      item.data=data;
    }catch(error){if(reviewCurrent(item))item.error='Conversation review could not be verified.';}
    finally{
      if(reviewCurrent(item)){
        item.busy=false;render();
        // Explicitly re-open after expiry rather than display stale consent or evidence.
        managedReviewTimer=setTimeout(()=>{if(managedReview===item){clearManagedReview();if(current(g))render();}},60000);
      }
    }
  }
  function appendManagedReview(card,info,g){
    const item=managedReview;
    if(!item||item.id!==info.public_id||!reviewCurrent(item))return;
    const host=node('section','vp3-phone-managed-review');host.dataset.managedConversationReview='1';
    host.setAttribute('aria-label','Private conversation review');
    host.append(node('h3',null,'Conversation intelligence'));
    const closeReview=button('Close review',()=>{if(managedReview===item){clearManagedReview();render();}});
    host.append(closeReview);
    if(item.busy){host.append(node('p','vp3-phone-meta','Checking the original saved conversation evidence…'));card.append(host);return;}
    if(item.error){host.append(node('p','vp3-phone-processing-error',item.error));card.append(host);return;}
    const data=item.data||{},coverage=data.coverage;
    const messages={no_report:'No saved report is confirmed.',queued:'Recording queued for processing.',processing:'Recording is being processed.',failed:'Recording processing needs review.',unavailable:'Saved evidence is unavailable.',consent_unavailable:'Original recording consent could not be verified.',transcription_off:'Transcription was not authorized for this call.',analysis_off:'Automatic analysis was not authorized for this call.',stale:'Transcript or saved analysis changed. Current report evidence is not confirmed.',ready:'Saved AI report · review before using any finding.'};
    host.append(node('p','vp3-phone-meta',messages[coverage]||'Review evidence is unavailable.'));
    const transcript=safeUrl(data.transcript_url);if(transcript){
      const a=node('a','vp3-phone-report','Open source transcript');a.href=transcript;host.append(a);
    }
    if(coverage==='ready'&&typeof data.report?.text==='string'){
      const report=node('p','vp3-phone-managed-report');report.textContent=data.report.text.slice(0,9000);host.append(report);
      const items=Array.isArray(data.followups)?data.followups.slice(0,3):[];
      host.append(node('h4',null,'Open follow-ups'));
      if(!items.length)host.append(node('p','vp3-phone-meta','No open, source-linked follow-ups in the saved review.'));
      for(const row of items){
        const link=safeUrl(row?.source_url);if(!link||!['unreviewed','accepted'].includes(row.review_state))continue;
        const box=node('div','vp3-phone-managed-finding');
        box.append(node('strong',null,row.review_state==='accepted'?'Reviewed · still open':'Unreviewed suggestion'),node('p',null,row.text||''));
        if(row.next_step)box.append(node('p','vp3-phone-meta',row.next_step));
        const a=node('a','vp3-phone-report','Review original finding');a.href=link;box.append(a);host.append(box);
      }
      host.append(node('p','vp3-phone-meta','Open the original Transcription item to approve or create a task. No task or message is sent by this review.'));
    }
    host.append(node('h4',null,'Connected-profile continuity'));
    const continuity=data.continuity;
    if(continuity?.state==='available'){
      host.append(node('p','vp3-phone-meta','Previous managed calls through this connected phone. This does not establish who answered the destination.'));
      for(const previous of (Array.isArray(continuity.calls)?continuity.calls:[]).slice(0,5)){
        host.append(node('p','vp3-phone-meta',[displayTime(previous.created_at),displayState(previous.state),callDuration(previous.duration_seconds)].filter(Boolean).join(' · ')));
      }
      if(!continuity.calls?.length)host.append(node('p','vp3-phone-meta','No earlier verified call in this profile and conversation.'));
    }else host.append(node('p','vp3-phone-meta','Ongoing conversation context requires the connected user’s current memory permission.'));
    card.append(host);
  }

  function callMessageWithLinks(message){
    const paragraph=node('p');
    for(const [index,line] of text(message).split('\n').entries()){
      if(index)paragraph.append(node('br'));
      let url;
      if(/^https?:\/\/[^\s]+$/i.test(line)){
        try{url=new URL(line);}catch{}
      }
      if(url&&!url.username&&!url.password){
        const link=node('a',null,line);
        link.href=url.href;link.target='_blank';link.rel='noopener noreferrer';paragraph.append(link);
      }else paragraph.append(node('span',null,line));
    }
    return paragraph;
  }
  function renderCallResearch(card,info,entry,g){
    if(!entry||entry!==active||entry.phase!=='active'||!window.VP3CallResearch)return;
    const available=()=>current(g)&&active===entry&&entry.phase==='active'&&!stateError&&state?.calls.some(call=>call.public_id===info.public_id&&call.state==='answered');
    if(!available())return;
    const section=node('section','vp3-phone-call-research');
    const research=button('Research during call',event=>{
      if(!event.isTrusted||!available())return;
      if(opened)close();
      const started=window.VP3CallResearch.open({...context,current:available,accept:finding=>{
        if(!available()||typeof finding!=='string'||!finding.trim())return false;
        entry.findings||=[];
        if(!entry.findings.includes(finding))entry.findings.push(finding);
        render();return true;
      }});
      if(!started)say('Finish the current research request before opening call research.');
    });
    section.append(research);
    const shared=state?.companion_cards?.[info.public_id];
    for(const finding of entry.findings||[]){
      section.append(node('strong',null,'Private research finding'),callMessageWithLinks(finding));
      if(shared)appendShareDraftButton(section,info.public_id,shared,finding,'Review finding for phone');
    }
    card.append(section);
  }
  function appendShareDraftButton(section,id,link,message,label){
    const choose=button(label,event=>{
      if(!event.isTrusted||active?.info.public_id!==id||state?.companion_cards?.[id]?.device_id!==link.device_id)return;
      const draft=companionDrafts.get(id);
      if(draft?.busy)return;
      if(draft?.text&&draft.text!==message&&!window.confirm('Replace your current sharing draft?'))return;
      companionDrafts.set(id,{device:link.device_id,text:message,key:'',busy:false,error:''});render();
      const field=Array.from(document.querySelectorAll('[data-phone-focus-key]')).find(el=>el.dataset.phoneFocusKey==='companion-'+id&&el.getClientRects().length);
      field?.focus();
    });
    section.append(choose);
  }
  function renderCallCompanion(card,info,g){
    const id=info.public_id,link=state?.companion_cards?.[id];
    if(!link||!publicId(link.device_id)||Number(info.conversation_id)!==context.conversation_id)return;
    const section=node('section','vp3-phone-companion');
    section.append(node('strong',null,'Shared with '+text(link.name).slice(0,48)));
    for(const item of link.items||[]){
      section.append(callMessageWithLinks(item.message),node('small','vp3-phone-meta',item.state==='acknowledged'?'Acknowledged on phone':'Available on phone'));
    }
    if(info.state==='answered'){
      let draft=companionDrafts.get(id);
      if(!draft||draft.device!==link.device_id){draft={device:link.device_id,text:'',key:'',busy:false,error:''};companionDrafts.set(id,draft);}
      const field=node('textarea');field.dataset.phoneFocusKey='companion-'+id;field.setAttribute('aria-label','Link or note to share');field.placeholder='Link or note to share';field.maxLength=1000;field.value=draft.text;field.disabled=draft.busy;
      const lengthNotice=node('small','vp3-phone-meta');
      lengthNotice.setAttribute('aria-live','polite');
      const byteLength=()=>new TextEncoder().encode(draft.text.trim()).length;
      const send=button('Share to phone',async()=>{
        if(!current(g)||draft.busy||!draft.text.trim()||byteLength()>1000||companionDrafts.get(id)!==draft)return;
        draft.key||=crypto.randomUUID().replace(/-/g,'');draft.busy=true;draft.error='';render();
        try{
          const result=await api('share_call_item',{call_id:id,contact_id:link.contact_id,device_id:draft.device,exchange_id:draft.key,message:draft.text.trim()},'POST',g);
          if(result.shared!==true||result.exchange_id!==draft.key)throw phoneError('Sharing could not be confirmed.');
          if(current(g)&&companionDrafts.get(id)===draft){draft.text='';draft.key='';await refresh();}
        }catch(error){if(current(g))draft.error=error.phonePublicMessage||'Could not share this item.';}
        finally{if(current(g)&&companionDrafts.get(id)===draft){draft.busy=false;render();}}
      });
      const updateShare=()=>{
        const bytes=byteLength();
        send.disabled=draft.busy||bytes===0||bytes>1000;
        lengthNotice.hidden=bytes<=1000;
        lengthNotice.textContent=bytes>1000?'Shorten this draft by '+(bytes-1000)+' bytes to share it.':'';
      };
      field.addEventListener('input',()=>{draft.text=field.value;draft.key='';updateShare();});
      updateShare();section.append(field,lengthNotice,send);
      if(draft.error)section.append(node('p','vp3-phone-processing-error',draft.error));
    }
    if(info.state==='answered'||link.items?.length)card.append(section);
  }

  function callCard(info,entry=null){
    const renderGeneration=generation;
    info={...info,...state?.calls.find(call=>call.public_id===info.public_id)};
    const id=info.public_id,readOnlyLegacy=/^legacy-/.test(id),currentState=localStates.get(id)||info.state,card=node('article','vp3-phone-call');card.dataset.phoneCallId=id;
    const incoming=entry?.incoming===true||info.direction==='inbound',outgoing=entry?.incoming===false||info.direction==='outbound',contact=callerContact(info);
    card.append(node('strong','vp3-phone-call-heading',info.transport_kind==='managed_callback'?(info.managed_mode==='twilio_native'?'Twilio native dial-through':'Twilio managed callback'):info.connected_phone===true?'Connected phone call':info.owner_test===true?'Personal phone test call':incoming?'Incoming phone call':outgoing?'Outgoing phone call':'Phone call'),node('p','vp3-phone-peer-name',text(contact?.name||callPeerName(info)).slice(0,180)));
    if(contact)card.append(node('p','vp3-phone-peer-number',info.peer_e164));
    const prior=info.caller_familiar===true||info.caller_familiar===undefined&&(state?.calls||[]).some(call=>call.public_id!==id&&call.peer_e164===info.peer_e164&&info.peer_e164&&typeof call.created_at==='string'&&typeof info.created_at==='string'&&call.created_at<info.created_at);
    if(contact||prior)card.append(node('p','vp3-phone-peer-context',contact?'Saved contact':'Seen in recent calls'));
    const details=node('dl','vp3-phone-call-details'),addDetail=(label,value)=>{if(value===undefined||value===null||value==='')return;const row=node('div');row.append(node('dt',null,label),node('dd',null,value));details.append(row);};
    const terminal=['ended','completed','voicemail_ready','voicemail','no_answer','no-answer','busy','failed','declined','cancelled','canceled','missed'].includes(info.state);
    addDetail('Status',info.agent_conversation_active?'Routed to Agent':terminal?displayState(info.state):currentState==='answered'?'Answered':entry?.phase==='connected'?'Browser connected':displayState(entry?.phase||currentState));addDetail('Call time',displayTime(info.created_at));
    if(terminal&&Number.isInteger(info.duration_seconds)&&(!readOnlyLegacy||info.duration_seconds>0))addDetail(readOnlyLegacy?'Recording duration':'Call duration',callDuration(info.duration_seconds));
    addDetail('Your line',info.phone_e164);
    const managed=info.transport_kind==='managed_callback',control=info.managed_control;
    if(managed){addDetail('Transport',info.managed_mode==='twilio_native'?'Twilio native dial-through':'Twilio managed callback');if(control?.missed_leg)addDetail('No answer',control.missed_leg==='holder'?'Connected phone':'Destination');addDetail('Connection stage',displayState(control?.dispatch_state));addDetail('Carrier check',control?.read_state==='none'?'':displayState(control?.read_state));addDetail('End request',control?.end_state==='none'?'':displayState(control?.end_state));}
    const line=(state?.numbers||[]).find(number=>Number(number.id)===Number(info.number_id)&&Number(number.agent_id)===Number(info.agent_id)&&number.phone_e164===info.phone_e164);
    if(line?.agent_name)addDetail('Agent',text(line.agent_name).slice(0,180));
    const recording=(state?.voicemails||[]).find(voicemail=>callKey(voicemail.call_id)===id);
    if(info.incoming_choice)addDetail('Incoming call action',({agent:'Send to Agent',voicemail:'Send to voicemail',answer:'Answer'})[info.incoming_choice]);
    if(recording&&safeUrl(recording.audio_url))addDetail('Recording','Available');else if(info.recording_enabled===true)addDetail('Recording','Requested');
    if(recording?.transcript||safeUrl(recording?.transcript_url))addDetail('Transcript','Available');
    if(safeUrl(recording?.report_url))addDetail('Call report','Available');
    card.append(details);renderCallResearch(card,info,entry,renderGeneration);renderCallCompanion(card,info,renderGeneration);if(recording)appendRecording(card,recording,renderGeneration,true);
    const actions=node('div','vp3-phone-actions');
    if(contact&&(incoming||outgoing)){
      const emailButton=button('Send Email',async()=>{
        if(!current(renderGeneration)||emailButton.disabled)return;
        emailButton.disabled=true;
        try{
          const result=await api('phone_contact_detail',{contact_id:contact.id},'GET',renderGeneration);
          if(!current(renderGeneration)||Number(result.contact?.id)!==contact.id)return;
          const address=result.contact.email;
          if(typeof address==='string'&&address.length<=190&&/^[^\s@]+@[^\s@]+$/u.test(address)){
            location.href='mailto:'+encodeURIComponent(address).replace('%40','@');
          }else{
            open(emailButton);
            await openPhoneContact(contact.id);
            if(current(renderGeneration)&&phoneContactDraft?.id===contact.id){
              phoneContactDraft.feedback='Add an email address for this contact, then save.';
              render();
              panel.querySelector('[data-phone-focus-key="contact-detail-email"]')?.focus();
            }
          }
        }catch(error){
          if(current(renderGeneration)){open(emailButton);say(error.phonePublicMessage||'The contact email could not be loaded.');}
        }finally{emailButton.disabled=false;}
      });
      actions.append(emailButton);
    }
    if(entry?.incoming&&pending.get(id)===entry){
      const answer=button('Answer',()=>{if(current(renderGeneration))void acceptCall(id);},'vp3-phone-button vp3-phone-primary');
      answer.disabled=callBusy||setupPending>0||Boolean(active)||!positive(context.conversation_id);
      actions.append(answer);
      if(info.incoming_routing_available){
        for(const [destination,label] of [['agent','Send to Agent'],['voicemail','Send to voicemail']]){
          const send=button(label,()=>{if(current(renderGeneration))void routeIncoming(entry,destination);});
          send.disabled=answer.disabled;actions.append(send);
        }
        if(entry.routeError)card.append(node('p','vp3-phone-processing-error',entry.routeError));
      }else{
        actions.append(button('Decline',()=>{if(current(renderGeneration)&&!callBusy)declineCall(id);}));
      }
    }
    if(entry&&entry===active){const hangup=button('Hang up',()=>{if(current(renderGeneration))endActive('Call ended.');},'vp3-phone-button vp3-phone-danger');actions.append(hangup);if(entry.call){const muted=entry.call.isMuted?.()===true;const mute=button(muted?'Unmute':'Mute',()=>{if(current(renderGeneration)&&active===entry){if(!muted&&entry.captionSession?.voiceTurn)takeOverVoice(entry,'AI voice stopped when you muted the call.');else if(!muted&&entry.captionSession?.mode==='both')stopRemoteCaptions(entry,'Both-sided captions stopped when you muted your audio.');entry.call.mute(!muted);render();}});mute.setAttribute('aria-pressed',String(muted));actions.append(mute);}}
    if(managed&&!entry&&!terminal){
      if(control?.can_reconcile===true){const check=button('Check carrier status',event=>{if(event.isTrusted&&current(renderGeneration))void runManagedControl('managed_reconcile',id,renderGeneration);});check.disabled=managedControlBusy.has(id);actions.append(check);}
      if(control?.can_end===true){const end=button('End managed call',event=>{if(event.isTrusted&&current(renderGeneration)&&window.confirm('End this managed call and block any pending destination connection?'))void runManagedControl('managed_end',id,renderGeneration);},'vp3-phone-button vp3-phone-danger');end.disabled=managedControlBusy.has(id);actions.append(end);}
      if(control?.end_state==='uncertain'||control?.end_state==='awaiting_identity'||control?.read_state==='destination_unresolved')card.append(node('p','vp3-phone-meta','Carrier outcome is unconfirmed. Check status or hang up directly on the connected phone. Do not retry a call.'));
    }
    if(!entry&&!readOnlyLegacy&&!managed&&['ringing','incoming','offered'].includes(currentState))actions.append(button('Open Phone',()=>open()));
    if(!entry&&!managed&&['ended','completed','voicemail_ready','voicemail','no_answer','no-answer','busy','failed','declined','cancelled','canceled','missed'].includes(currentState)){
      const callback=button('Call back',()=>{if(current(renderGeneration))void placeCall(info.peer_e164);});callback.disabled=!canCall()||Boolean(active||callBusy||setupPending||pending.size)||!/^\+[1-9]\d{7,14}$/.test(info.peer_e164||'');actions.append(callback);
    }
    const draft=contactDrafts.get(id);
    if(!contact&&callKey(id)&&/^\+[1-9]\d{7,14}$/.test(info.peer_e164||'')&&!draft){
      const addContact=button('Add to contacts',()=>{if(!current(renderGeneration)||state?.contact_write_ready===false)return;const focusHost=addContact.closest('.vp3-phone-chat-card')||sections.connections;contactDrafts.set(id,{name:'',saving:false,error:'',generation:renderGeneration});render();const field=[...focusHost.querySelectorAll('[data-phone-focus-key]')].find(input=>input.dataset.phoneFocusKey==='caller-name-'+id);field?.focus({preventScroll:true});});addContact.disabled=state?.contact_write_ready===false;addContact.dataset.phoneAddContact=id;actions.append(addContact);
    }
    if(managed&&info.state==='completed'&&context.ready&&Number(info.conversation_id)===context.conversation_id){
      const review=button('Review conversation',event=>{if(event.isTrusted)void reviewManagedCall(info,renderGeneration);});
      review.disabled=managedReview?.id===id&&managedReview.busy;review.dataset.phoneFocusKey='managed-review-'+id;actions.append(review);
    }
    if(actions.childNodes.length)card.append(actions);
    if(managed)appendManagedReview(card,info,renderGeneration);
    renderRemoteCaptions(card,entry);
    if(!contact&&state?.contact_write_ready===false)card.append(node('p','vp3-phone-meta','An administrator needs to update Contacts before this caller can be saved.'));
    if(!contact&&draft?.generation===renderGeneration)appendContactForm(card,info,draft,renderGeneration);
    return card;
  }
  function appendContactForm(card,info,draft,g){
    const id=info.public_id,form=node('form','vp3-phone-contact-form');form.setAttribute('aria-label','Add caller to contacts');
    const nameLabel=node('label','vp3-phone-field','Name'),name=node('input');name.type='text';name.maxLength=120;name.required=true;name.autocomplete='off';name.value=draft.name;name.disabled=draft.saving;name.setAttribute('aria-label','Contact name');name.dataset.phoneFocusKey='caller-name-'+id;
    name.addEventListener('input',()=>{if(current(g)&&contactDrafts.get(id)===draft&&!draft.saving)draft.name=name.value.slice(0,120);});nameLabel.append(name);
    const phoneLabel=node('label','vp3-phone-field','Phone number'),phone=node('input');phone.type='tel';phone.readOnly=true;phone.setAttribute('readonly','');phone.value=info.peer_e164;phone.setAttribute('aria-label','Caller phone number');phoneLabel.append(phone);
    form.append(nameLabel,phoneLabel);
    if(draft.error){const error=node('p','vp3-phone-processing-error',draft.error);error.setAttribute('role','alert');form.append(error);}
    const controls=node('div','vp3-phone-actions'),save=node('button','vp3-phone-button vp3-phone-primary',draft.saving?'Saving…':'Save contact');save.type='submit';save.disabled=draft.saving||state?.contact_write_ready===false;
    const cancel=button('Cancel',()=>{if(current(g)&&contactDrafts.get(id)===draft&&!draft.saving){contactDrafts.delete(id);render();}});cancel.disabled=draft.saving;controls.append(save,cancel);form.append(controls);
    form.addEventListener('submit',async event=>{
      event.preventDefault();if(!current(g)||contactDrafts.get(id)!==draft||draft.saving)return;
      if(state?.contact_write_ready===false){draft.error='An administrator needs to update Contacts before this caller can be saved.';render();return;}
      const value=name.value.trim();draft.name=name.value.slice(0,120);if(!value){draft.error='Enter a contact name.';render();return;}
      draft.saving=true;draft.error='';render();
      try{
        const result=await api('save_caller_contact',{call_id:id,name:value},'POST',g);
        if(!current(g)||contactDrafts.get(id)!==draft)return;
        const contact=result.contact;if(!positive(contact?.id)||typeof contact.name!=='string'||!contact.name.trim()||contact.phone_e164!==info.peer_e164)throw new Error('The saved contact could not be confirmed. Refresh Phone.');
        if(fetching)await fetching;if(!current(g)||contactDrafts.get(id)!==draft)return;
        if(state){state.contacts=[contact,...state.contacts.filter(row=>Number(row.id)!==Number(contact.id)&&row.phone_e164!==contact.phone_e164)];for(const call of [...state.calls,...state.voicemails])if(call.peer_e164===contact.phone_e164){call.contact_id=Number(contact.id);call.contact_name=contact.name;}}
        contactDrafts.delete(id);say('Contact saved.');render();await refresh();
      }catch(error){if(current(g)&&contactDrafts.get(id)===draft)draft.error=error.message;}
      finally{if(current(g)&&contactDrafts.get(id)===draft){draft.saving=false;render();}}
    });card.append(form);
  }
  // Owner settings never enter the normal Chat transcript/model request.
  // A single ephemeral private card is tied to the current Chat generation.
  let ownerSetup=null;
  function clearOwnerSetup(){if(ownerSetup){ownerSetup.element.remove();ownerSetup=null;}}
  function ownerSetupCurrent(card){return ownerSetup===card&&current(card.g)&&card.element.isConnected&&context.ready&&positive(context.conversation_id);}
  function ownerPhoneModel(raw){
    if(!raw||typeof raw.phone_e164!=='string'||(raw.phone_e164!==''&&!/^\+[1-9]\d{7,14}$/.test(raw.phone_e164))
      ||!Number.isSafeInteger(raw.revision)||raw.revision<0||!Number.isSafeInteger(raw.phone_revision)||raw.phone_revision<0
      ||typeof raw.updates_requested!=='boolean'||typeof raw.hello_requested!=='boolean'||raw.execution_enabled!==false)
      throw new Error('Private phone settings could not be confirmed.');
    return raw;
  }
  function renderOwnerSetup(card){
    if(!ownerSetupCurrent(card))return;
    const host=card.host;host.replaceChildren();
    host.append(node('h3',null,'Connect your phone'),node('p','vp3-phone-empty','Private phone settings shared with your Account. No SMS or verification code is required.'));
    const feedback=node('p','vp3-phone-notice',card.feedback||'');feedback.setAttribute('role','status');
    const model=card.model;
    if(!model){host.append(feedback,button('Refresh phone settings',event=>{if(event.isTrusted)void loadOwnerSetup(card);}));return;}
    const form=node('form','vp3-phone-policy'),label=node('label','vp3-phone-field','Your personal phone number'),input=node('input');
    input.type='tel';input.autocomplete='tel';input.maxLength=64;input.value=model.phone_e164;input.setAttribute('aria-label','Your personal phone number');label.append(input);
    const save=node('button','vp3-phone-button','Save number');save.type='submit';save.disabled=card.busy;form.append(label,save);
    const choices=node('div','vp3-phone-policy'),checks={};
    for(const [key,title] of [['updates_requested','Call me with Chat Canvas updates'],['hello_requested','Call just to say hello']]){
      const label=node('label','vp3-phone-check'),check=node('input');check.type='checkbox';check.checked=model[key];check.disabled=card.busy||!model.phone_e164;checks[key]=check;label.append(check,node('span',null,title));choices.append(label);
    }
    const savePrefs=button('Save calling preferences',event=>{
      if(!event.isTrusted||!ownerSetupCurrent(card)||card.busy)return;
      void ownerSetupAction(card,'save_owner_call_preferences',{expected_revision:String(model.revision),updates_requested:checks.updates_requested.checked,hello_requested:checks.hello_requested.checked});
    });savePrefs.disabled=card.busy||!model.phone_e164;choices.append(savePrefs,node('p','vp3-phone-empty','Autonomous calls are not enabled. Set calling hours and limits in Account Settings.'));
    const consentLabel=node('label','vp3-phone-check'),consent=node('input');consent.type='checkbox';consentLabel.append(consent,node('span',null,'This is my phone number. I want one test call (voice charges may apply).'));
    const test=button('Test call',async event=>{
      if(!event.isTrusted||!ownerSetupCurrent(card)||card.busy||!consent.checked||input.value!==model.phone_e164||!model.phone_revision)return;
      if(!canCall()){card.feedback='Select an enabled assigned phone line first.';renderOwnerSetup(card);open(event.currentTarget);return;}
      card.busy=true;card.feedback='Starting browser reachability test…';renderOwnerSetup(card);
      open(card.element);selectTab('connections');
      const started=await placeCall(model.phone_e164,{ownerPhoneRevision:model.phone_revision});
      if(ownerSetupCurrent(card)){card.busy=false;await loadOwnerSetup(card);if(ownerSetupCurrent(card)&&!started&&card.model){card.feedback='Test call did not start. Check the Phone panel for details.';renderOwnerSetup(card);}}
    });
    const updateTest=()=>{test.disabled=card.busy||!model.phone_e164||input.value!==model.phone_e164||!consent.checked||Boolean(active||callBusy||setupPending||pending.size);};
    consent.disabled=card.busy||!model.phone_e164;consent.addEventListener('change',updateTest);input.addEventListener('input',updateTest);updateTest();
    form.addEventListener('submit',event=>{event.preventDefault();if(event.isTrusted&&ownerSetupCurrent(card)&&!card.busy)void ownerSetupAction(card,'save_owner_phone',{phone_e164:input.value,expected_revision:String(model.revision)});});
    host.append(form,choices,consentLabel,test,node('p','vp3-phone-empty','The test uses your assigned line and browser microphone, lasts at most one minute, and does not record or use AI speech.'));
    const last=model.last_test;
    if(last&&publicId(last.call_id)){
      host.append(node('p',null,last.received===true?'You confirmed receiving the test call.':'Last test: '+displayState(last.state)));
      if(last.can_confirm===true&&!last.received){
        const received=button('I received it',event=>{if(event.isTrusted&&ownerSetupCurrent(card)&&!card.busy)void ownerSetupAction(card,'confirm_owner_test',{call_id:last.call_id,phone_revision:model.phone_revision,received:true});});
        received.disabled=card.busy;host.append(received);
      }
    }
    const reload=button('Refresh test status',event=>{if(event.isTrusted&&!card.busy)void loadOwnerSetup(card);});reload.disabled=card.busy;host.append(reload,feedback);
  }
  async function loadOwnerSetup(card){
    if(!ownerSetupCurrent(card)||card.busy)return;
    card.busy=true;try{const result=await api('owner_phone_settings',{},'GET',card.g);if(ownerSetupCurrent(card)){card.model=ownerPhoneModel(result.owner_phone);card.feedback='';}}
    catch(error){if(ownerSetupCurrent(card)){card.model=null;card.feedback=error.message;}}
    finally{if(ownerSetupCurrent(card)){card.busy=false;renderOwnerSetup(card);}}
  }
  async function ownerSetupAction(card,action,values){
    if(!ownerSetupCurrent(card)||card.busy)return;
    card.busy=true;renderOwnerSetup(card);
    try{
      const result=await api(action,values,'POST',card.g);
      if(ownerSetupCurrent(card)){card.model=ownerPhoneModel(result.owner_phone);card.feedback=action==='confirm_owner_test'?'Test receipt saved.':'Private phone settings saved.';}
    }catch(error){if(ownerSetupCurrent(card))card.feedback=error.message;}
    finally{if(ownerSetupCurrent(card)){card.busy=false;renderOwnerSetup(card);}}
  }
  async function openOwnerSetup(){
    if(!authorized||disposed||!context.ready)return false;
    const scope=await ensureContext(),thread=document.getElementById('chatThread');
    if(!scope||!thread||!current(scope.generation)||!context.ready)return false;
    clearOwnerSetup();
    const element=node('article','message assistant vp3-phone-message'),avatar=node('div','message-avatar','A'),body=node('div','message-body'),host=node('div','vp3-phone-chat-card');
    body.append(node('div','message-role','Agent'),host);element.append(avatar,body);element.dataset.ownerPhoneSetup='1';thread.append(element);
    const card={element,host,g:generation,model:null,busy:false,feedback:'Loading private phone settings…'};ownerSetup=card;
    renderOwnerSetup(card);await loadOwnerSetup(card);if(ownerSetupCurrent(card))element.scrollIntoView?.({block:'nearest'});
    return true;
  }

  function renderCards(){
    for(const item of [...cards]){
      if(!item.element.isConnected){cards.delete(item);continue;}
      const focused=document.activeElement,focusKey=item.host.contains(focused)?focused?.dataset?.phoneFocusKey:'',selection=focusKey&&'selectionStart' in focused?[focused.selectionStart,focused.selectionEnd]:null;
      if(!authorized){item.host.replaceChildren(node('p',null,'Phone access is unavailable.'));continue;}
      if(item.generation!==generation){item.host.replaceChildren(button('Open current Phone workspace',()=>open()));continue;}
      if(item.kind==='voicemail'){const voicemail=state?.voicemails.find(row=>voicemailKey(row.id)===item.voicemailId);replacePhoneContent(item.host,voicemail?voicemailCard(voicemail):button('Open voicemail',()=>{open();selectTab('voicemail');}));}
      else{const pendingCall=pending.get(item.callId),entry=active?.info.public_id===item.callId?active:pendingCall,call=entry?.info||state?.calls.find(row=>row.public_id===item.callId);replacePhoneContent(item.host,call?callCard(call,entry):button('Open call history',()=>{open();selectTab('calls');}));}
      if(focusKey){const replacement=[...item.host.querySelectorAll('[data-phone-focus-key]')].find(field=>field.dataset.phoneFocusKey===focusKey);if(replacement&&!replacement.disabled){replacement.focus({preventScroll:true});if(selection)try{replacement.setSelectionRange(...selection);}catch(_){}}}
    }
  }
  function removeAnnouncements(callId=null){
    for(const announcement of document.querySelectorAll('[data-phone-announcement]')){
      if(callId&&announcement.dataset.phoneAnnouncement!==callId)continue;
      for(const item of [...cards])if(item.element===announcement||announcement.contains(item.element)){item.host.remove();cards.delete(item);}
      announcement.remove();
    }
  }
  function bindMessage(element,payload){
    if(!authorized||disposed||!element?.append||!payload||!['call','voicemail'].includes(payload.kind))return;
    const callId=callKey(payload.call_id),voicemailId=voicemailKey(payload.voicemail_id);
    if(payload.kind==='call'&&!callId||payload.kind==='voicemail'&&!voicemailId)return;
    if(callId&&!element.closest('[data-phone-announcement]'))removeAnnouncements(callId);
    let item=[...cards].find(row=>row.element===element);if(item){if(item.callId===callId&&item.voicemailId===voicemailId&&item.generation===generation)return;item.host.remove();cards.delete(item);}
    const host=node('div','vp3-phone-chat-card');host.dataset.phoneWorkspaceCard='1';
    // A canonical Chat message is an avatar/content grid. A third direct child
    // falls into the narrow avatar column, so cards belong inside its body.
    const messageBody=element.querySelector?.('.message-body');(messageBody||element).append(host);element.classList?.add('vp3-phone-message');
    item={element,host,kind:payload.kind,callId,voicemailId,generation};cards.add(item);
    if(cards.size>100){const first=cards.values().next().value;first.host.remove();cards.delete(first);}
    renderCards();if(!state||(item.kind==='voicemail'&&!state.voicemails.some(row=>row.id===item.voicemailId))||(item.kind==='call'&&!state.calls.some(row=>row.public_id===item.callId)))void refresh();
  }
  function announceIncoming(entry){
    const thread=document.getElementById('chatThread');if(!thread||!positive(context.conversation_id))return;
    let card=thread.querySelector('[data-phone-announcement="'+entry.info.public_id+'"]');
    if(!card){card=node('div','vp3-phone-announcement');card.dataset.phoneAnnouncement=entry.info.public_id;card.setAttribute('role','status');thread.append(card);}
    bindMessage(card,{kind:'call',call_id:entry.info.public_id});
    window.dispatchEvent(new CustomEvent('vp3:phone-workspace-event',{detail:{kind:'incoming',call_id:entry.info.public_id,conversation_id:context.conversation_id,agent_id:context.agent_id}}));
  }
  function trustedSdkUrl(){
    const expected=new URL('assets/twilio-voice-2.18.5.min.js',new URL(config.urlbase||'/',location.origin));
    const url=new URL(config.sdk_url||expected.href,location.href);
    if(url.origin!==location.origin||url.href!==expected.href||url.username||url.password)throw new Error('The phone audio client is unavailable.');return url.href;
  }
  async function loadSdk(){
    if(window.Twilio?.Device)return window.Twilio;
    if(sdkPromise)return sdkPromise;
    const url=trustedSdkUrl();sdkPromise=new Promise((resolve,reject)=>{const script=node('script');script.src=url;script.crossOrigin='anonymous';if(config.sdk_integrity){script.integrity=config.sdk_integrity;script.referrerPolicy='no-referrer';}const timer=setTimeout(()=>{script.remove();reject(new Error('The phone audio client did not load.'));},15000);script.onload=()=>{clearTimeout(timer);window.Twilio?.Device?resolve(window.Twilio):reject(new Error('The phone audio client is unavailable.'));};script.onerror=()=>{clearTimeout(timer);script.remove();reject(new Error('The phone audio client did not load.'));};document.head.append(script);}).catch(error=>{sdkPromise=null;throw error;});return sdkPromise;
  }
  function expiry(value){const numeric=Number(value);return Number.isFinite(numeric)&&numeric>0?(numeric<1e12?numeric*1000:numeric):Date.parse(value);}
  async function credentials(numberId,g){const result=await api('token',{number_id:numberId},'POST',g);if(typeof result.token!=='string'||result.token.length<20||result.token.length>32768||typeof result.identity!=='string'||!result.identity||result.identity.length>150||!Number.isFinite(expiry(result.expires_at))||expiry(result.expires_at)<=Date.now()+1000)throw new Error('Phone authorization was not confirmed.');return result;}
  async function enableDevice(){
    const preparedScope=await ensureContext();if(!preparedScope||preparedScope.generation!==generation)return null;
    if(deviceSession)return deviceSession.ready||Promise.resolve(deviceSession);
    if(!canCall()){say('Select a ready phone number and open a Chat conversation first.');return Promise.resolve(null);}
    if(voiceArm?.pending)throw new Error('Finish preparing Agent voice first.');
    const mixer=voiceArm&&voiceArm.generation===generation&&state?.operator_live?.voice_available===true?voiceArm.mixer:null;
    if(voiceArm&&!mixer)voiceArm.mixer.close();voiceArm=null;
    const session={generation,identity:'',numberId:Number(selectedNumber().id),device:null,phase:'connecting',closed:false,refreshing:false,voiceMixer:mixer};deviceSession=session;say('Connecting this browser for calls…');render();
    session.ready=(async()=>{try{
      const [sdk,auth]=await Promise.all([loadSdk(),credentials(session.numberId,session.generation)]);if(!sessionCurrent(session))return null;
      session.identity=auth.identity;const device=new sdk.Device(auth.token,{logLevel:0,allowIncomingWhileBusy:false,tokenRefreshMs:30000,closeProtection:true});session.device=device;
      if(session.voiceMixer){if(typeof device.audio?.addProcessor!=='function')throw new Error('The pinned phone SDK does not support Agent voice preparation.');await device.audio.addProcessor(session.voiceMixer,false);if(!sessionCurrent(session)){session.voiceMixer.close();try{device.destroy();}catch(_){}return null;}}
      device.on('registered',()=>{if(sessionCurrent(session)){session.phase='registered';say('Ready for incoming calls.');render();}});
      device.on('unregistered',()=>{if(sessionCurrent(session)){session.phase='unregistered';say('Browser calls are disconnected. Turn them off and enable them again.');render();}});
      device.on('incoming',call=>{if(sessionCurrent(session))incomingCall(session,call);else stopCall(call,true);});
      device.on('tokenWillExpire',()=>void refreshToken(session));
      device.on('error',()=>{if(sessionCurrent(session))stopDevice('Phone connection needs attention. Enable calls to reconnect.');});
      await device.register();if(!sessionCurrent(session)){try{device.destroy();}catch(_){}return null;}session.phase='registered';say('Ready for incoming calls.');render();return session;
    }catch(error){if(sessionCurrent(session)){stopDevice(error.name==='AbortError'?'Phone context changed.':error.message);throw error;}return null;}})();return session.ready;
  }
  async function refreshToken(session){
    if(!sessionCurrent(session)||session.refreshing)return;session.refreshing=true;
    try{const auth=await credentials(session.numberId,session.generation);if(sessionCurrent(session)){if(auth.identity!==session.identity)throw new Error('Phone identity changed.');session.device.updateToken(auth.token);}}catch(error){if(sessionCurrent(session))stopDevice('Phone authorization expired. Enable calls again.');}finally{session.refreshing=false;}
  }
  function stopCall(call,reject=false){
    if(!call)return;try{const stream=call.getLocalStream?.();for(const track of stream?.getTracks?.()||[])track.stop();}catch(_){}
    try{reject?call.reject():call.disconnect();}catch(_){}
  }
  function cancelPrepared(entry){
    if(entry?.cancelPromise)return entry.cancelPromise;
    if(!entry?.prepared||entry.cancelSent||!publicId(entry.info?.public_id)||!entry.scope)return Promise.resolve();entry.cancelSent=true;
    const controller=new AbortController(),timer=setTimeout(()=>controller.abort(),8000);
    entry.cancelPromise=fetch(endpoint.href,{method:'POST',credentials:'same-origin',cache:'no-store',keepalive:true,signal:controller.signal,headers:{Accept:'application/json','Content-Type':'application/json'},body:JSON.stringify({action:'cancel_prepared',csrf_token:config.csrf,call_id:entry.info.public_id,conversation_id:entry.scope.conversation_id,agent_id:entry.scope.agent_id})}).catch(()=>{}).finally(()=>clearTimeout(timer));
    return entry.cancelPromise;
  }
  function releaseEntry(entry){if(!entry)return;stopRemoteCaptions(entry,'Call ended.');if(entry.prepared&&entry.phase!=='active')cancelPrepared(entry);stopCall(entry.call,entry.incoming&&entry.phase==='ringing');entry.ticket?.release();entry.ticket=null;}
  function stopDevice(message='Browser calls are off.'){
    callEpoch++;preparingTicket?.release();preparingTicket=null;if(voiceArm){voiceArm.mixer.close();voiceArm=null;}const session=deviceSession;deviceSession=null;if(session)session.closed=true;
    const owned=active,ringing=[...pending.values()];active=null;pending.clear();releaseEntry(owned);for(const entry of ringing)releaseEntry(entry);callBusy=false;
    if(session?.device){try{session.device.disconnectAll();}catch(_){}try{session.device.destroy();}catch(_){}}
    session?.voiceMixer?.close();
    say(message);render();signalCallState();
  }
  function captureTicket(){
    const lease=window.StonefellowVoiceLeaseV122;if(!lease?.acquireCapture)throw new Error('Microphone ownership is unavailable.');
    const ticket=lease.acquireCapture('phone-workspace');if(!ticket)throw new Error('Another browser surface is using the microphone. Stop it there before calling.');return ticket;
  }
  function signalCallState(){window.dispatchEvent(new CustomEvent('vp3:phone-call-state',{detail:{busy:Boolean(active||callBusy||setupPending),source:'phone-workspace-v1'}}));}
  function ownStream(entry){const stream=entry.call?.getLocalStream?.();if(stream)entry.ticket?.ownStream(stream);const original=entry.session?.voiceMixer?.originalStream;if(original&&original!==stream)entry.ticket?.ownStream(original);}
  function bindCall(entry){
    const call=entry.call;
    const matches=()=>active===entry&&sessionCurrent(entry.session)&&(!entry.ticket||entry.ticket.isCurrent());
    const accepted=()=>{if(!matches()){stopCall(call);return;}entry.phase='active';entry.prepared=false;recordThisCall=false;ownStream(entry);localStates.set(entry.info.public_id,'active');say('Call connected.');render();signalCallState();void refresh();};
    call.on('accept',accepted);
    call.on('ringing',()=>{if(matches()){entry.phase='ringing';render();}});
    call.on('mute',muted=>{if(muted&&matches()&&entry.captionSession?.voiceTurn)takeOverVoice(entry,'AI voice stopped when the call was muted.');});
    for(const event of ['disconnect','cancel','reject','error'])call.on(event,()=>{if(active===entry){localStates.set(entry.info.public_id,event==='error'?'failed':'ended');active=null;releaseEntry(entry);callBusy=false;say(event==='error'?'The call ended with a connection problem.':'Call ended.');render();signalCallState();void refresh();}else if(!sessionCurrent(entry.session))stopCall(call);});
    if(call.status?.()==='open')accepted();
  }
  async function placeCall(rawNumber,options={}){
    const ownerTest=Number.isSafeInteger(options.ownerPhoneRevision)&&options.ownerPhoneRevision>0;
    const paired=options.connected||null;
    if(paired&&paired!==connectedDraft)return false;
    const destination=String(rawNumber||'').trim();if(!paired&&!/^\+[1-9]\d{7,14}$/.test(destination)){say('Enter a phone number in international format, starting with +.');return;}
    if(active||callBusy||setupPending||pending.size){say('Finish the current call before starting another.');return;}
    if(!canCall()){say('Select a ready phone connection and open a Chat conversation first.');open();return;}
    const preparedScope=await ensureContext();if(!preparedScope||active||callBusy||setupPending||pending.size||preparedScope.generation!==generation)return;
    const g=generation,operation=++callEpoch;clearAudio();callBusy=true;signalCallState();let entry=null,ticket=null,setupOwned=false,receipt=null;
    try{
      ticket=captureTicket();preparingTicket=ticket;const session=await enableDevice();if(!current(g)||operation!==callEpoch||!sessionCurrent(session)){ticket.release();return;}
      const callScope={conversation_id:context.conversation_id,agent_id:context.agent_id};setupPending++;setupOwned=true;
      const prepared=await api(paired?'prepare_connected_call':ownerTest?'prepare_owner_test':'prepare_call',paired?{number_id:session.numberId,device_id:paired.device_id,profile_id:paired.profile_id,profile_revision:paired.profile_revision,phone_revision:paired.phone_revision,confirmed:true}:ownerTest?{number_id:session.numberId,phone_revision:options.ownerPhoneRevision,confirmed:true}:{number_id:session.numberId,to_number:destination,recording_enabled:Boolean(options.record===true&&enabled(selectedNumber()?.recording_enabled)&&state.recording_readiness?.ready!==false)},'POST',g);
      receipt=publicId(prepared.call?.public_id)?{info:prepared.call,scope:callScope,prepared:true}:null;
      if(!current(g)||operation!==callEpoch||!sessionCurrent(session)){cancelPrepared(receipt);ticket.release();return;}
      if(!publicId(prepared.call?.public_id)||typeof prepared.connect_params?.call_intent!=='string'||prepared.connect_params.call_intent.length<16||prepared.connect_params.call_intent.length>512)throw new Error('The call destination was not confirmed.');
      entry=Object.assign(receipt,{session,generation:g,call:null,ticket,phase:'connecting',incoming:false});active=entry;if(preparingTicket===ticket)preparingTicket=null;ticket=null;render();
      const call=await session.device.connect({params:{call_intent:prepared.connect_params.call_intent}});
      if(!current(g)||operation!==callEpoch||!sessionCurrent(session)||active!==entry){stopCall(call);releaseEntry(entry);return;}
      entry.call=call;bindCall(entry);ownStream(entry);callBusy=false;render();
      if(ownerTest||paired)window.dispatchEvent(new CustomEvent('vp3:phone-workspace-event',{detail:{kind:'outgoing',call_id:entry.info.public_id,conversation_id:callScope.conversation_id,agent_id:callScope.agent_id}}));
      return true;
    }catch(error){cancelPrepared(receipt);ticket?.release();if(preparingTicket===ticket)preparingTicket=null;if(current(g)&&operation===callEpoch){if(entry&&active===entry){active=null;releaseEntry(entry);}say(error.name==='NotAllowedError'?'Microphone access was not allowed.':error.message,true);render();}}
    finally{if(receipt?.cancelPromise)await receipt.cancelPromise;if(setupOwned)setupPending=Math.max(0,setupPending-1);if(preparingTicket===ticket)preparingTicket=null;if(current(g)&&operation===callEpoch)callBusy=false;if(current(g)){signalCallState();render();}}
  }
  function incomingCall(session,call){
    const id=call.customParameters?.get?.('phone_call_id');if(!publicId(id)||active||callBusy||setupPending||pending.size||greetingCapture){stopCall(call,true);return;}
    const record=state?.calls.find(item=>item.public_id===id),entry={session,generation,info:record||{public_id:id,peer_e164:text(call.parameters?.From).slice(0,32),state:'ringing'},call,ticket:null,phase:'ringing',incoming:true};pending.set(id,entry);
    for(const event of ['cancel','reject','disconnect','error'])call.on(event,()=>{if(pending.get(id)===entry){pending.delete(id);localStates.set(id,event==='error'?'failed':'ended');render();void refresh();}});
    say('Incoming phone call from '+(callerContact(entry.info)?.name||peerName(entry.info.peer_e164)));render();announceIncoming(entry);void refresh();
  }
  async function acceptCall(id){
    const entry=pending.get(id);if(!entry||active||callBusy||setupPending||!sessionCurrent(entry.session)||!positive(context.conversation_id))return;
    const g=generation,operation=++callEpoch;clearAudio();callBusy=true;signalCallState();render();let ticket=null;
    try{
      ticket=captureTicket();preparingTicket=ticket;const result=await api('bind_call',{call_id:id},'POST',g);
      if(!current(g)||operation!==callEpoch||pending.get(id)!==entry||!sessionCurrent(entry.session)){ticket.release();return;}
      if(result.call?.public_id!==id||Number(result.call.conversation_id)!==context.conversation_id||context.agent_id>0&&Number(result.call.agent_id)!==context.agent_id)throw new Error('Incoming call ownership was not confirmed.');
      pending.delete(id);entry.info=result.call;entry.ticket=ticket;if(preparingTicket===ticket)preparingTicket=null;ticket=null;entry.phase='connecting';active=entry;bindCall(entry);say('Connecting phone audio…');entry.call.accept({rtcConstraints:{audio:{echoCancellation:true,noiseSuppression:true,autoGainControl:true}}});ownStream(entry);render();
    }catch(error){ticket?.release();if(preparingTicket===ticket)preparingTicket=null;if(current(g)&&operation===callEpoch){if(active===entry){active=null;releaseEntry(entry);}say(error.name==='NotAllowedError'?'Microphone access was not allowed.':error.message);}}
    finally{if(current(g)&&operation===callEpoch){callBusy=false;signalCallState();render();}}
  }
  async function routeIncoming(entry,destination){
    const id=entry.info.public_id,g=generation;
    if(pending.get(id)!==entry||callBusy||!sessionCurrent(entry.session))return;
    callBusy=true;entry.routeError='';render();
    try{
      const result=await api('route_incoming',{call_id:id,destination},'POST',g);
      if(!current(g)||pending.get(id)!==entry||!sessionCurrent(entry.session))return;
      if(result.call_id!==id||result.destination!==destination)throw phoneError('Phone routing could not be confirmed.');
      pending.delete(id);
      releaseEntry(entry);
      localStates.set(id,destination==='agent'?'Agent handoff requested':'Voicemail requested');
      void refresh();
    }catch(error){
      if(current(g)&&pending.get(id)===entry)entry.routeError=error.phonePublicMessage||'Could not change the incoming call route.';
    }finally{if(current(g)){callBusy=false;render();}}
  }
  function declineCall(id){const entry=pending.get(id);if(!entry)return;pending.delete(id);releaseEntry(entry);localStates.set(id,'declined');say('Call declined.');render();void refresh();}
  function endActive(message){const entry=active;if(!entry)return;active=null;localStates.set(entry.info.public_id,'ended');releaseEntry(entry);callBusy=false;say(message);render();signalCallState();void refresh();}
  function clearAudio(){for(const audio of [...audioNodes]){audio.pause();audio.removeAttribute('src');audio.load();if(!audio.isConnected)audioNodes.delete(audio);}}

  function revoke(){
    if(!authorized)return;voicemailDraft=null;voicemailBusy=false;voicemailActions.clear();companionDrafts.clear();connectedDraft=null;clearOwnerSetup();authorized=false;generation++;stateError='';autoCallDraft=null;calendarDraft=null;admissionDraft=null;phoneContactDraft=null;contactDrafts.clear();for(const request of requests)request.abort();requests.clear();fetching=null;clearAudio();removeAnnouncements();stopDevice('Phone access is unavailable.');state=null;close();
    for(const item of document.querySelectorAll('[data-phone-workspace-toggle]'))item.hidden=true;
    for(const section of Object.values(sections))section.replaceChildren();renderCards();
  }
  function contextChanged(next={}){
    const normalized={conversation_id:principal(next.conversation_id),agent_id:principal(next.agent_id),ready:next.ready!==false};
    if(normalized.conversation_id===context.conversation_id&&normalized.agent_id===context.agent_id&&normalized.ready===context.ready)return;
    clearGreeting();voicemailDraft=null;voicemailBusy=false;voicemailActions.clear();companionDrafts.clear();clearManagedReview();connectedDraft=null;clearOwnerSetup();generation++;requestSequence++;stateError='';for(const request of requests)request.abort();requests.clear();fetching=null;clearAudio();removeAnnouncements();stopDevice('Phone context changed. Enable calls for the current conversation.');state=null;context=normalized;dialDraft='';recordThisCall=false;contactQuery='';initialState=true;localStates.clear();policyDrafts.clear();contactDrafts.clear();autoCallDraft=null;calendarDraft=null;admissionDraft=null;phoneContactDraft=null;render();void refresh();
  }
  function dispose(){if(disposed)return;voicemailDraft=null;voicemailBusy=false;voicemailActions.clear();companionDrafts.clear();connectedDraft=null;clearOwnerSetup();generation++;stateError='';autoCallDraft=null;calendarDraft=null;admissionDraft=null;phoneContactDraft=null;contactDrafts.clear();for(const request of requests)request.abort();requests.clear();removeAnnouncements();stopDevice('Phone is closed.');clearAudio();disposed=true;clearInterval(pollTimer);close();state=null;for(const section of Object.values(sections))section.replaceChildren();renderCards();}
  document.addEventListener('click',event=>{const trigger=event.target.closest?.('[data-phone-workspace-toggle]');if(trigger){event.preventDefault();opened?close():open(trigger);}});
  backdrop.addEventListener('click',close);closeButton.addEventListener('click',close);
  document.addEventListener('keydown',event=>{
    if(event.key==='Escape'&&active?.captionSession?.voiceTurn){event.preventDefault();takeOverVoice(active);return;}
    if(!opened)return;if(event.key==='Escape'){event.preventDefault();close();return;}
    if(event.key!=='Tab')return;const focusable=[...panel.querySelectorAll('button:not([disabled]),input:not([disabled]),textarea:not([disabled]),select:not([disabled]),a[href],audio[controls],[tabindex="0"]')].filter(item=>!item.closest('[hidden]'));
    if(!focusable.length){event.preventDefault();panel.focus();return;}const first=focusable[0],last=focusable[focusable.length-1];if(event.shiftKey&&(document.activeElement===first||document.activeElement===panel)){event.preventDefault();last.focus();}else if(!event.shiftKey&&document.activeElement===last){event.preventDefault();first.focus();}
  });
  tabs.addEventListener('keydown',event=>{const list=Object.values(tabButtons),index=list.indexOf(event.target);if(index<0||!['ArrowRight','ArrowLeft','Home','End'].includes(event.key))return;event.preventDefault();selectTab(Object.keys(tabNames)[event.key==='Home'?0:event.key==='End'?list.length-1:(index+(event.key==='ArrowRight'?1:-1)+list.length)%list.length],true);});
  window.addEventListener('stonefellow:chat-context-changing',()=>contextChanged({...context,ready:false}));
  window.addEventListener('vp3:participant-selection-changed',()=>{const scope=window.STONEFELLOW_CHAT_CONTINUITY?.phoneCameraScope?.();contextChanged(scope?{conversation_id:scope.conversation,agent_id:scope.agent,ready:true}:{...context,ready:false});});
  document.addEventListener('visibilitychange',()=>{if(document.hidden){clearManagedReview();if(active?.captionSession)stopRemoteCaptions(active,'Live captions stopped when Chat left the foreground.');render();}});
  window.addEventListener('pagehide',dispose,{once:true});window.addEventListener('stonefellow:session-expired',revoke);window.addEventListener('stonefellow:logout',dispose);
  window.addEventListener('stonefellow:voice-lease-lost',()=>{if(greetingCapture){clearGreeting();say('Microphone ownership changed. Greeting recording stopped.');render();}if(active||callBusy)stopDevice('Microphone ownership changed. Phone audio has stopped.');});
  pollTimer=setInterval(()=>{if(document.visibilityState==='visible'&&(opened||deviceSession||cards.size))void refresh();},10000);
  function operatorDiagnostics(conversation,agent){
    if(!current(generation)||stateError||!state||!context.ready||!positive(conversation)||context.conversation_id!==conversation||context.agent_id!==agent)return null;
    const routes={another_call:'Another call occupied the line',no_registered_browser:'No recent browser registration',different_agent:'Browser registered to another Agent',line_disabled:'Browser calls disabled for this line',browser_unavailable:'Browser routing unavailable'};
    const pairs=[['Browser',deviceSession?.phase==='registered'?'Registered in this tab':'Unregistered in this tab'],
      ['Voicemail timeout',state.voicemail_settings?.ring_seconds?state.voicemail_settings.ring_seconds+' seconds':'Unavailable'],
      ['Recording worker',displayState(state.processing_worker?.state||'unknown')]];
    const why={prepared:'Call prepared for dispatch',owner_permission:'Owner permission is off',recipient_permission:'Recipient permission is unavailable',
      no_reason:'No current reason to call',already_planned:'Agenda already used',contact_changed:'Contact number changed',route_or_recording_changed:'Line or recording settings changed',
      calling_window:'Outside allowed calling hours',call_frequency:'Call frequency limit reached',phone_busy:'Phone already in use',calendar_conflict:'Calendar is busy',
      chat_unavailable:'Private Chat unavailable',carrier_unavailable:'Carrier route unavailable',access_changed:'Access changed',changed:'Agenda changed',error:'Worker could not verify the decision'};
    const review=state.outreach_review;
    if(review&&Object.hasOwn(why,review.reason)&&typeof review.at==='string'&&Number.isFinite(Date.parse(review.at))){
      pairs.push(['Last spontaneous call review',why[review.reason]+' · '+displayTime(review.at)]);
    }
    if(state.schedule_worker)pairs.push(['Scheduled call worker',scheduleWorkerStatus(state.schedule_worker)]);
    for(const trace of (state.operator_trace||[]).slice(0,8)){
      const call=state.calls.find(row=>row.public_id===trace.call_id);
      const voicemail=state.voicemails.find(row=>row.call_id===trace.call_id&&row.kind==='voicemail');
      const rendered=[...cards].some(item=>item.callId===trace.call_id&&item.host.isConnected);
      pairs.push(['Chat message '+trace.message_id,[call?.created_at&&displayTime(call.created_at),displayState(call?.state||'saved'),routes[trace.browser_route],
        call?.incoming_choice&&('Requested '+call.incoming_choice),rendered?'Card rendered':'Card saved',voicemail&&('Voicemail '+displayState(voicemail.state)),voicemail?.transcript&&'Transcript ready'].filter(Boolean).join(' · ')]);
    }
    return {pairs,working:Boolean(active||pending.size),registered:deviceSession?.phase==='registered'};
  }
  function openHistory(source=document.activeElement){open(source);if(opened)selectTab('calls');}
  function openRecordings(source=document.activeElement){open(source);if(opened)selectTab('voicemail');}
  window.VP3PhoneWorkspaceV1=Object.freeze({operatorDiagnostics,open,openHistory,openRecordings,openOwnerSetup,openConnectedPhone,close,bindMessage,contextChanged,refresh,dispose});
  layer.id='vp3PhoneWorkspace';
  window.dispatchEvent(new Event('vp3:phone-workspace-ready'));
  render();void refresh();
})();
