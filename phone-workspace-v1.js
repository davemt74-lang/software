(() => {
  'use strict';
  const config=window.VP3_PHONE_WORKSPACE_V1;
  if(!config||window.VP3PhoneWorkspaceV1)return;
  const positive=value=>Number.isSafeInteger(Number(value))&&Number(value)>0;
  const principal=value=>Number.isSafeInteger(Number(value))&&Number(value)>=0?Number(value):0;
  const text=value=>String(value??'').slice(0,5000);
  const voicemailKey=value=>typeof value==='string'&&/^(?:job|legacy)-[1-9][0-9]{0,14}$/.test(value)?value:positive(value)?String(value):'';
  const publicId=value=>typeof value==='string'&&/^[A-Za-z0-9_-]{8,100}$/.test(value);
  const node=(tag,cls,value)=>{const item=document.createElement(tag);if(cls)item.className=cls;if(value!==undefined)item.textContent=text(value);return item;};
  const endpoint=new URL(config.endpoint,location.href);
  if(endpoint.origin!==location.origin||endpoint.username||endpoint.password)return;
  const cards=new Set(),requests=new Set(),pending=new Map(),localStates=new Map(),audioNodes=new Set(),policyDrafts=new Map();
  let context={conversation_id:principal(config.conversation_id??config.conversationId),agent_id:principal(config.agent_id??config.agentId),ready:true},generation=0,requestSequence=0;
  let state=null,authorized=true,disposed=false,opened=false,activeTab='connections',opener=null,fetching=null,deviceSession=null,active=null,callBusy=false,sdkPromise=null;
  let callEpoch=0,dialDraft='',recordThisCall=false,preparingTicket=null,setupPending=0;
  let pollTimer=0,statusText='Checking phone connections…',contactQuery='',initialState=true;
  const layer=node('div','vp3-phone-workspace');layer.hidden=true;
  const backdrop=node('button','vp3-phone-backdrop');backdrop.type='button';backdrop.tabIndex=-1;backdrop.setAttribute('aria-label','Close phone workspace');
  const panel=node('aside','vp3-phone-panel');panel.setAttribute('role','dialog');panel.setAttribute('aria-modal','true');panel.setAttribute('aria-labelledby','vp3PhoneTitle');panel.tabIndex=-1;
  const header=node('header','vp3-phone-header'),title=node('h2',null,'Phone');title.id='vp3PhoneTitle';
  const closeButton=button('Close',close,'vp3-phone-close');closeButton.setAttribute('aria-label','Close phone workspace');header.append(title,closeButton);
  const notice=node('p','vp3-phone-notice');notice.setAttribute('role','status');notice.setAttribute('aria-live','polite');
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
  panel.append(header,notice,tabs,body);layer.append(backdrop,panel);document.body.append(layer);

  function button(label,handler,cls='vp3-phone-button'){
    const item=node('button',cls,label);item.type='button';item.addEventListener('click',handler);return item;
  }
  function current(g){return !disposed&&authorized&&g===generation;}
  function sessionCurrent(session){return Boolean(session)&&current(session.generation)&&deviceSession===session&&!session.closed;}
  function say(message){statusText=text(message).slice(0,350);notice.textContent=statusText;}
  function selectedNumber(){return (state?.numbers||[]).find(item=>Number(item.id)===Number(state?.selected_number_id));}
  const enabled=value=>value===true||value===1||value==='1';
  function chatEligible(){const chat=window.STONEFELLOW_CHAT_CONTINUITY;return context.ready&&(positive(context.conversation_id)||Boolean(chat?.ensureConversation&&chat.isReady?.()&&!chat.isRestoring?.()&&!chat.isBusy?.()));}
  function canCall(){const number=selectedNumber();return !!(number&&enabled(number.voice_enabled)&&enabled(number.browser_enabled)&&state?.readiness?.ready===true&&chatEligible());}
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
  function safeUrl(value){try{const url=new URL(value,location.href);return url.origin===location.origin&&!url.username&&!url.password&&['http:','https:'].includes(url.protocol)?url.href:'';}catch(_){return '';}}
  function peerName(peer){return (state?.contacts||[]).find(item=>item.phone_e164===peer)?.name||peer||'Unknown caller';}
  function scopeBody(extra={}){return {...extra,conversation_id:context.conversation_id,agent_id:context.agent_id};}
  async function api(action,values={},method='POST',g=generation){
    if(!current(g))throw new DOMException('Phone context changed.','AbortError');
    const requestScope={conversation_id:context.conversation_id,agent_id:context.agent_id};
    const controller=new AbortController();requests.add(controller);const timer=setTimeout(()=>controller.abort(),15000);
    try{
      const url=new URL(endpoint);const options={method,credentials:'same-origin',cache:'no-store',signal:controller.signal,headers:{Accept:'application/json'}};
      if(method==='GET'){for(const [key,value] of Object.entries(scopeBody({action,...values})))url.searchParams.set(key,String(value));}
      else{options.headers['Content-Type']='application/json';options.body=JSON.stringify(scopeBody({action,csrf_token:config.csrf,...values}));}
      const response=await fetch(url.href,options);let data={};try{data=await response.json();}catch(_){throw new Error('Phone returned an invalid response.');}
      if(!current(g)){if(action==='prepare_call'&&data.ok===true&&publicId(data.call?.public_id))await cancelPrepared({info:data.call,scope:requestScope,prepared:true});throw new DOMException('Phone context changed.','AbortError');}
      if(response.status===401||response.status===403){revoke();throw new Error('Phone access is unavailable.');}
      if(!response.ok||data.ok!==true){const error=new Error(response.status===409?'This call changed. Refresh its status before trying again.':'The phone request could not be confirmed.');error.status=response.status;throw error;}
      return data;
    }finally{clearTimeout(timer);requests.delete(controller);}
  }
  function cleanState(raw){
    if(!raw||!Array.isArray(raw.numbers)||!Array.isArray(raw.calls)||!Array.isArray(raw.voicemails))throw new Error('Phone state was not confirmed.');
    return {...raw,numbers:raw.numbers.filter(item=>positive(item?.id)).slice(0,30),contacts:(Array.isArray(raw.contacts)?raw.contacts:[]).filter(item=>positive(item?.id)).slice(0,250),favorites:(Array.isArray(raw.favorites)?raw.favorites:[]).filter(item=>positive(item?.id)).slice(0,100),calls:raw.calls.filter(item=>publicId(item?.public_id)).slice(0,60),voicemails:raw.voicemails.filter(item=>voicemailKey(item?.id)).map(item=>({...item,id:voicemailKey(item.id)})).slice(0,60)};
  }
  async function refresh(){
    if(!authorized||disposed||!context.ready)return;
    if(fetching)return fetching;
    const g=generation,sequence=++requestSequence;
    const work=(async()=>{
      try{
        const result=await api('state',{},'GET',g);if(!current(g)||sequence!==requestSequence)return;
        const next=cleanState(result.state);
        if(deviceSession&&(!next.numbers.some(item=>Number(item.id)===deviceSession.numberId&&item.browser_enabled&&item.voice_enabled)||Number(next.selected_number_id)!==deviceSession.numberId))stopDevice('Phone connection changed. Enable calls again.');
        state=next;initialState=false;
        if(!state.numbers.length){revoke();return;}
        if(!deviceSession&&!active)say(state.readiness?.ready?'Select a number and enable calls in this browser.':state.readiness?.reason||'The phone connection is not ready.');
        render();
      }catch(error){if(current(g)&&error.name!=='AbortError')say(error.message);}
    })();
    fetching=work;try{await work;}finally{if(fetching===work)fetching=null;}
  }
  function open(source=document.activeElement){
    if(disposed||!authorized)return;
    opener=source;opened=true;layer.hidden=false;document.body.classList.add('vp3-phone-open');
    document.querySelectorAll('[data-phone-workspace-toggle]').forEach(item=>item.setAttribute('aria-expanded','true'));
    render();panel.focus({preventScroll:true});void refresh();
  }
  function close(){
    opened=false;layer.hidden=true;document.body.classList.remove('vp3-phone-open');
    document.querySelectorAll('[data-phone-workspace-toggle]').forEach(item=>item.setAttribute('aria-expanded','false'));
    if(opener?.isConnected)opener.focus({preventScroll:true});
  }
  function selectTab(key,focus=false){
    if(!tabNames[key])return;activeTab=key;
    for(const name of Object.keys(tabNames)){const selected=name===key;tabButtons[name].setAttribute('aria-selected',String(selected));tabButtons[name].tabIndex=selected?0:-1;sections[name].hidden=!selected;}
    if(focus)tabButtons[key].focus();
  }
  function listEmpty(host,message){host.append(node('p','vp3-phone-empty',message));}
  function render(){
    const focused=document.activeElement,focusKey=layer.contains(focused)?focused?.dataset?.phoneFocusKey:'',selection=focusKey&&'selectionStart' in focused?[focused.selectionStart,focused.selectionEnd]:null,scroll=body.scrollTop;
    notice.textContent=statusText;
    renderConnections();renderContacts();renderCalls();renderVoicemails();renderCards();selectTab(activeTab);
    for(const audio of [...audioNodes])if(!audio.isConnected){try{audio.pause();audio.removeAttribute('src');audio.load();}catch(_){}audioNodes.delete(audio);}
    if(opened&&focusKey){const replacement=[...layer.querySelectorAll('[data-phone-focus-key]')].find(item=>item.dataset.phoneFocusKey===focusKey);if(replacement&&!replacement.disabled&&!replacement.closest('[hidden]')){replacement.focus({preventScroll:true});if(selection)try{replacement.setSelectionRange(...selection);}catch(_){}}}
    body.scrollTop=scroll;
  }
  function renderConnections(){
    const renderGeneration=generation,host=sections.connections;host.replaceChildren();
    if(!state){listEmpty(host,initialState?'Loading assigned phone connections…':'Phone connections are unavailable.');return;}
    const label=node('label','vp3-phone-field','Phone number'),select=node('select');select.setAttribute('aria-label','Selected phone number');select.dataset.phoneFocusKey='number';
    for(const number of state.numbers){const option=node('option',null,number.phone_e164+(number.agent_name?' · '+number.agent_name:''));option.value=String(number.id);select.append(option);}
    select.value=String(state.selected_number_id||'');select.disabled=Boolean(active||callBusy||setupPending||pending.size);
    select.addEventListener('change',async()=>{const g=renderGeneration;if(!current(g))return;const id=Number(select.value);select.disabled=true;try{await api('select_number',{number_id:id},'POST',g);if(!current(g))return;stopDevice('Phone number changed. Enable calls for this number.');await refresh();}catch(error){if(current(g))say(error.message);}finally{if(current(g))render();}});
    label.append(select);host.append(label);
    const connection=node('div','vp3-phone-connection');connection.append(node('strong',null,deviceSession?.phase==='registered'?'Browser ready for incoming calls':deviceSession?'Connecting browser…':'Browser calls are off'));
    const enable=button(deviceSession?'Turn off browser calls':'Enable calls in this browser',()=>deviceSession?stopDevice('Browser calls are off.'):void enableDevice().catch(()=>{}));enable.disabled=!deviceSession&&!canCall();connection.append(enable);host.append(connection);
    if(!chatEligible()){host.append(node('p','vp3-phone-empty','Open an owner Chat conversation to make or accept a call.'));const link=node('a','vp3-phone-report','Open Chat');link.href=new URL('chat.php',new URL(config.urlbase||'/',location.origin)).href;host.append(link);}
    const dial=node('form','vp3-phone-dial'),dialLabel=node('label','vp3-phone-field','Dial a phone number'),input=node('input');input.type='tel';input.autocomplete='tel';input.placeholder='+16025550123';input.required=true;input.maxLength=20;input.pattern='\\+[1-9][0-9]{7,14}';input.setAttribute('aria-label','Phone number to call');input.dataset.phoneFocusKey='dial';input.value=dialDraft;input.addEventListener('input',()=>{dialDraft=input.value.slice(0,20);});dialLabel.append(input);
    const send=node('button','vp3-phone-button vp3-phone-primary','Call');send.type='submit';send.disabled=!canCall()||Boolean(active||callBusy||setupPending||pending.size);dial.append(dialLabel,send);
    if(enabled(selectedNumber()?.recording_enabled)){
      const recordLabel=node('label','vp3-phone-check vp3-phone-record'),record=node('input');record.type='checkbox';record.checked=recordThisCall;record.dataset.phoneFocusKey='record-call';record.addEventListener('change',()=>{if(current(renderGeneration))recordThisCall=record.checked;});recordLabel.append(record,node('span',null,'Record this call'));dial.append(recordLabel,node('p','vp3-phone-empty vp3-phone-record','Both sides hear a recording notice. Your saved after-call settings control transcripts and reports.'));
    }
    dial.addEventListener('submit',event=>{event.preventDefault();if(current(renderGeneration))void placeCall(input.value,{record:recordThisCall});});host.append(dial);
    renderCurrentCall(host);renderPolicy(host);
  }
  function normalizePolicy(raw={}){
    const modules=['basic','qa','actions','decisions','followup'];
    return {enabled:raw.enabled===true,retain_audio:raw.retain_audio===true,transcribe:raw.transcribe===true,analyze:raw.analyze===true,modules:Array.isArray(raw.modules)?raw.modules.filter(value=>modules.includes(value)):[...modules]};
  }
  function renderPolicy(host){
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
    else if(state?.active_call&&!pending.size){const card=node('div','vp3-phone-call');card.append(node('strong',null,'Call in progress'),node('p',null,peerName(state.active_call.peer_e164)),node('p',null,displayState(state.active_call.state)));host.append(card);}
  }
  function contactRow(contact){
    const renderGeneration=generation;
    const row=node('article','vp3-phone-row'),details=node('div','vp3-phone-row-copy');details.append(node('strong',null,contact.name||contact.phone_e164),node('span',null,contact.phone_e164));
    const favorite=button(contact.favorite?'★':'☆',async()=>{const g=renderGeneration;if(!current(g))return;favorite.disabled=true;try{await api('favorite',{contact_id:Number(contact.id),favorite:!contact.favorite},'POST',g);if(current(g))await refresh();}catch(error){if(current(g))say(error.message);}finally{if(current(g))favorite.disabled=false;}});favorite.setAttribute('aria-label',(contact.favorite?'Remove favorite ':'Favorite ')+(contact.name||contact.phone_e164));favorite.setAttribute('aria-pressed',String(Boolean(contact.favorite)));
    const call=button('Call',()=>{if(current(renderGeneration))void placeCall(contact.phone_e164);});call.disabled=!canCall()||Boolean(active||callBusy||setupPending||pending.size);row.append(details,favorite,call);return row;
  }
  function renderContacts(){
    const host=sections.contacts;host.replaceChildren();if(!state){listEmpty(host,'Phone contacts are unavailable.');return;}
    const search=node('input');search.type='search';search.placeholder='Search contacts';search.setAttribute('aria-label','Search phone contacts');search.dataset.phoneFocusKey='contact-search';search.value=contactQuery;host.append(search);
    const results=node('div','vp3-phone-contact-results');host.append(results);
    function fill(){results.replaceChildren();const query=contactQuery.trim().toLocaleLowerCase();const contacts=state.contacts.filter(item=>!query||(String(item.name)+' '+String(item.phone_e164)).toLocaleLowerCase().includes(query));
      const favoriteIds=new Set(state.favorites.map(item=>Number(item.id)));const favorites=contacts.filter(item=>item.favorite||favoriteIds.has(Number(item.id)));
      if(favorites.length){results.append(node('h3',null,'Favorites'));for(const item of favorites)results.append(contactRow({...item,favorite:true}));}
      results.append(node('h3',null,'Contacts'));for(const item of contacts.filter(item=>!favorites.includes(item)))results.append(contactRow(item));if(!contacts.length)listEmpty(results,'No matching phone contacts.');
    }
    search.addEventListener('input',()=>{contactQuery=search.value.slice(0,120);fill();});fill();
  }
  function renderCalls(){
    const host=sections.calls;host.replaceChildren();if(!state?.calls.length){listEmpty(host,'No recent calls.');return;}
    const renderGeneration=generation;for(const call of state.calls){const row=node('article','vp3-phone-row'),details=node('div','vp3-phone-row-copy');details.append(node('strong',null,peerName(call.peer_e164)),node('span',null,[displayState(localStates.get(call.public_id)||call.state),displayTime(call.created_at)].filter(Boolean).join(' · ')));const callback=button('Call back',()=>{if(current(renderGeneration))void placeCall(call.peer_e164);});callback.disabled=!canCall()||Boolean(active||callBusy||setupPending||pending.size)||!/^\+[1-9]\d{7,14}$/.test(call.peer_e164||'');row.append(details,callback);host.append(row);}
  }
  function voicemailCard(voicemail){
    const renderGeneration=generation;
    const card=node('article','vp3-phone-voicemail');card.append(node('strong',null,'Voicemail from '+peerName(voicemail.peer_e164)),node('p','vp3-phone-meta',[displayTime(voicemail.created_at),displayState(voicemail.state||voicemail.stage||'saved')].filter(Boolean).join(' · ')));
    if(voicemail.state==='failed'||voicemail.error||voicemail.last_error)card.append(node('p','vp3-phone-processing-error',voicemail.error||voicemail.last_error||'Call processing needs attention.'));
    const audioUrl=safeUrl(voicemail.audio_url);if(audioUrl){const audio=node('audio');audioNodes.add(audio);audio.controls=true;audio.preload='none';audio.src=audioUrl;audio.setAttribute('aria-label','Play voicemail');audio.addEventListener('play',()=>{if(!current(renderGeneration)){audio.pause();return;}if(!voicemail.read)void api('mark_voicemail_read',{voicemail_id:voicemailKey(voicemail.id)},'POST',renderGeneration).catch(()=>{});});card.append(audio);}
    if(voicemail.transcript){const details=node('details'),summary=node('summary',null,'Transcript');details.append(summary,node('p',null,voicemail.transcript));card.append(details);}
    const transcriptUrl=safeUrl(voicemail.transcript_url);if(transcriptUrl){const transcript=node('a','vp3-phone-report','Open transcript');transcript.href=transcriptUrl;card.append(transcript);}
    const reportUrl=safeUrl(voicemail.report_url);if(reportUrl){const report=node('a','vp3-phone-report','Open voicemail report');report.href=reportUrl;card.append(report);}
    const callback=button('Call back',()=>{if(current(renderGeneration))void placeCall(voicemail.peer_e164);});callback.disabled=!canCall()||Boolean(active||callBusy||setupPending||pending.size)||!/^\+[1-9]\d{7,14}$/.test(voicemail.peer_e164||'');card.append(callback);return card;
  }
  function renderVoicemails(){const host=sections.voicemail;if([...host.querySelectorAll('audio')].some(audio=>!audio.paused))return;host.replaceChildren();if(!state?.voicemails.length){listEmpty(host,'No saved voicemail.');return;}for(const voicemail of state.voicemails)host.append(voicemailCard(voicemail));}
  function callCard(info,entry=null){
    const renderGeneration=generation;
    const id=info.public_id,card=node('article','vp3-phone-call');card.dataset.phoneCallId=id;
    card.append(node('strong',null,entry?.incoming?'Incoming phone call':'Phone call'),node('p',null,peerName(info.peer_e164)),node('p','vp3-phone-meta',displayState(entry?.phase||localStates.get(id)||info.state)));
    const actions=node('div','vp3-phone-actions');
    if(entry?.incoming&&pending.get(id)===entry){const accept=button('Accept',()=>{if(current(renderGeneration))void acceptCall(id);},'vp3-phone-button vp3-phone-primary');accept.disabled=callBusy||setupPending>0||Boolean(active)||!positive(context.conversation_id);const decline=button('Decline',()=>{if(current(renderGeneration))declineCall(id);});decline.disabled=callBusy;actions.append(accept,decline);}
    if(entry===active){const hangup=button('Hang up',()=>{if(current(renderGeneration))endActive('Call ended.');},'vp3-phone-button vp3-phone-danger');actions.append(hangup);if(entry.call){const muted=entry.call.isMuted?.()===true;const mute=button(muted?'Unmute':'Mute',()=>{if(current(renderGeneration)&&active===entry){entry.call.mute(!muted);render();}});mute.setAttribute('aria-pressed',String(muted));actions.append(mute);}}
    if(!entry&&['ringing','incoming','offered'].includes(info.state))actions.append(button('Open Phone',()=>open()));
    if(actions.childNodes.length)card.append(actions);return card;
  }
  function renderCards(){
    for(const item of [...cards]){
      if(!item.element.isConnected){cards.delete(item);continue;}
      if(authorized&&item.generation===generation&&[...item.host.querySelectorAll('audio')].some(audio=>!audio.paused))continue;
      item.host.replaceChildren();if(!authorized){item.host.append(node('p',null,'Phone access is unavailable.'));continue;}
      if(item.generation!==generation){item.host.append(button('Open current Phone workspace',()=>open()));continue;}
      if(item.kind==='voicemail'){const voicemail=state?.voicemails.find(row=>voicemailKey(row.id)===item.voicemailId);if(voicemail)item.host.append(voicemailCard(voicemail));else item.host.append(button('Open voicemail',()=>{open();selectTab('voicemail');}));}
      else{const pendingCall=pending.get(item.callId),entry=active?.info.public_id===item.callId?active:pendingCall,call=entry?.info||state?.calls.find(row=>row.public_id===item.callId);if(call)item.host.append(callCard(call,entry));else item.host.append(button('Open call history',()=>{open();selectTab('calls');}));}
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
    const callId=publicId(payload.call_id)?payload.call_id:'',voicemailId=voicemailKey(payload.voicemail_id);
    if(payload.kind==='call'&&!callId||payload.kind==='voicemail'&&!voicemailId)return;
    if(callId&&!element.closest('[data-phone-announcement]'))removeAnnouncements(callId);
    let item=[...cards].find(row=>row.element===element);if(item){if(item.callId===callId&&item.voicemailId===voicemailId&&item.generation===generation)return;item.host.remove();cards.delete(item);}
    const host=node('div','vp3-phone-chat-card');host.dataset.phoneWorkspaceCard='1';element.append(host);item={element,host,kind:payload.kind,callId,voicemailId,generation};cards.add(item);
    if(cards.size>100){const first=cards.values().next().value;first.host.remove();cards.delete(first);}
    renderCards();if(!state)void refresh();
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
    const session={generation,identity:'',numberId:Number(selectedNumber().id),device:null,phase:'connecting',closed:false,refreshing:false};deviceSession=session;say('Connecting this browser for calls…');render();
    session.ready=(async()=>{try{
      const [sdk,auth]=await Promise.all([loadSdk(),credentials(session.numberId,session.generation)]);if(!sessionCurrent(session))return null;
      session.identity=auth.identity;const device=new sdk.Device(auth.token,{logLevel:0,allowIncomingWhileBusy:false,tokenRefreshMs:30000,closeProtection:true});session.device=device;
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
  function releaseEntry(entry){if(!entry)return;if(entry.prepared&&entry.phase!=='active')cancelPrepared(entry);stopCall(entry.call,entry.incoming&&entry.phase==='ringing');entry.ticket?.release();entry.ticket=null;}
  function stopDevice(message='Browser calls are off.'){
    callEpoch++;preparingTicket?.release();preparingTicket=null;const session=deviceSession;deviceSession=null;if(session)session.closed=true;
    const owned=active,ringing=[...pending.values()];active=null;pending.clear();releaseEntry(owned);for(const entry of ringing)releaseEntry(entry);callBusy=false;
    if(session?.device){try{session.device.disconnectAll();}catch(_){}try{session.device.destroy();}catch(_){}}
    say(message);render();signalCallState();
  }
  function captureTicket(){
    const lease=window.StonefellowVoiceLeaseV122;if(!lease?.acquireCapture)throw new Error('Microphone ownership is unavailable.');
    const ticket=lease.acquireCapture('phone-workspace');if(!ticket)throw new Error('Another browser surface is using the microphone. Stop it there before calling.');return ticket;
  }
  function signalCallState(){window.dispatchEvent(new CustomEvent('vp3:phone-call-state',{detail:{busy:Boolean(active||callBusy||setupPending),source:'phone-workspace-v1'}}));}
  function ownStream(entry){const stream=entry.call?.getLocalStream?.();if(stream)entry.ticket?.ownStream(stream);}
  function bindCall(entry){
    const call=entry.call;
    const matches=()=>active===entry&&sessionCurrent(entry.session)&&(!entry.ticket||entry.ticket.isCurrent());
    const accepted=()=>{if(!matches()){stopCall(call);return;}entry.phase='active';entry.prepared=false;recordThisCall=false;ownStream(entry);localStates.set(entry.info.public_id,'active');say('Call connected.');render();signalCallState();};
    call.on('accept',accepted);
    call.on('ringing',()=>{if(matches()){entry.phase='ringing';render();}});
    for(const event of ['disconnect','cancel','reject','error'])call.on(event,()=>{if(active===entry){localStates.set(entry.info.public_id,event==='error'?'failed':'ended');active=null;releaseEntry(entry);callBusy=false;say(event==='error'?'The call ended with a connection problem.':'Call ended.');render();signalCallState();void refresh();}else if(!sessionCurrent(entry.session))stopCall(call);});
    if(call.status?.()==='open')accepted();
  }
  async function placeCall(rawNumber,options={}){
    const destination=String(rawNumber||'').trim();if(!/^\+[1-9]\d{7,14}$/.test(destination)){say('Enter a phone number in international format, starting with +.');return;}
    if(active||callBusy||setupPending||pending.size){say('Finish the current call before starting another.');return;}
    if(!canCall()){say('Select a ready phone connection and open a Chat conversation first.');open();return;}
    const preparedScope=await ensureContext();if(!preparedScope||active||callBusy||setupPending||pending.size||preparedScope.generation!==generation)return;
    const g=generation,operation=++callEpoch;clearAudio();callBusy=true;signalCallState();let entry=null,ticket=null,setupOwned=false,receipt=null;
    try{
      ticket=captureTicket();preparingTicket=ticket;const session=await enableDevice();if(!current(g)||operation!==callEpoch||!sessionCurrent(session)){ticket.release();return;}
      const callScope={conversation_id:context.conversation_id,agent_id:context.agent_id};setupPending++;setupOwned=true;
      const prepared=await api('prepare_call',{number_id:session.numberId,to_number:destination,recording_enabled:Boolean(options.record===true&&enabled(selectedNumber()?.recording_enabled))},'POST',g);
      receipt=publicId(prepared.call?.public_id)?{info:prepared.call,scope:callScope,prepared:true}:null;
      if(!current(g)||operation!==callEpoch||!sessionCurrent(session)){cancelPrepared(receipt);ticket.release();return;}
      if(!publicId(prepared.call?.public_id)||typeof prepared.connect_params?.call_intent!=='string'||prepared.connect_params.call_intent.length<16||prepared.connect_params.call_intent.length>512)throw new Error('The call destination was not confirmed.');
      entry=Object.assign(receipt,{session,generation:g,call:null,ticket,phase:'connecting',incoming:false});active=entry;if(preparingTicket===ticket)preparingTicket=null;ticket=null;render();
      const call=await session.device.connect({params:{call_intent:prepared.connect_params.call_intent}});
      if(!current(g)||operation!==callEpoch||!sessionCurrent(session)||active!==entry){stopCall(call);releaseEntry(entry);return;}
      entry.call=call;bindCall(entry);ownStream(entry);callBusy=false;render();
    }catch(error){cancelPrepared(receipt);ticket?.release();if(preparingTicket===ticket)preparingTicket=null;if(current(g)&&operation===callEpoch){if(entry&&active===entry){active=null;releaseEntry(entry);}say(error.name==='NotAllowedError'?'Microphone access was not allowed.':error.message);render();}}
    finally{if(receipt?.cancelPromise)await receipt.cancelPromise;if(setupOwned)setupPending=Math.max(0,setupPending-1);if(preparingTicket===ticket)preparingTicket=null;if(current(g)&&operation===callEpoch)callBusy=false;if(current(g)){signalCallState();render();}}
  }
  function incomingCall(session,call){
    const id=call.customParameters?.get?.('phone_call_id');if(!publicId(id)||active||callBusy||setupPending||pending.size){stopCall(call,true);return;}
    const record=state?.calls.find(item=>item.public_id===id),entry={session,generation,info:record||{public_id:id,peer_e164:text(call.parameters?.From).slice(0,32),state:'ringing'},call,ticket:null,phase:'ringing',incoming:true};pending.set(id,entry);
    for(const event of ['cancel','reject','disconnect','error'])call.on(event,()=>{if(pending.get(id)===entry){pending.delete(id);localStates.set(id,event==='error'?'failed':'ended');render();void refresh();}});
    say('Incoming phone call from '+peerName(entry.info.peer_e164));render();announceIncoming(entry);void refresh();
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
  function declineCall(id){const entry=pending.get(id);if(!entry)return;pending.delete(id);releaseEntry(entry);localStates.set(id,'declined');say('Call declined.');render();void refresh();}
  function endActive(message){const entry=active;if(!entry)return;active=null;localStates.set(entry.info.public_id,'ended');releaseEntry(entry);callBusy=false;say(message);render();signalCallState();void refresh();}
  function clearAudio(){for(const audio of [...audioNodes]){audio.pause();audio.removeAttribute('src');audio.load();if(!audio.isConnected)audioNodes.delete(audio);}}

  function revoke(){
    if(!authorized)return;authorized=false;generation++;for(const request of requests)request.abort();requests.clear();fetching=null;clearAudio();removeAnnouncements();stopDevice('Phone access is unavailable.');state=null;close();
    for(const item of document.querySelectorAll('[data-phone-workspace-toggle]'))item.hidden=true;
    for(const section of Object.values(sections))section.replaceChildren();renderCards();
  }
  function contextChanged(next={}){
    const normalized={conversation_id:principal(next.conversation_id),agent_id:principal(next.agent_id),ready:next.ready!==false};
    if(normalized.conversation_id===context.conversation_id&&normalized.agent_id===context.agent_id&&normalized.ready===context.ready)return;
    generation++;requestSequence++;for(const request of requests)request.abort();requests.clear();fetching=null;clearAudio();removeAnnouncements();stopDevice('Phone context changed. Enable calls for the current conversation.');state=null;context=normalized;dialDraft='';recordThisCall=false;contactQuery='';initialState=true;localStates.clear();policyDrafts.clear();render();void refresh();
  }
  function dispose(){if(disposed)return;generation++;for(const request of requests)request.abort();requests.clear();removeAnnouncements();stopDevice('Phone is closed.');clearAudio();disposed=true;clearInterval(pollTimer);close();state=null;for(const section of Object.values(sections))section.replaceChildren();renderCards();}
  document.addEventListener('click',event=>{const trigger=event.target.closest?.('[data-phone-workspace-toggle]');if(trigger){event.preventDefault();opened?close():open(trigger);}});
  backdrop.addEventListener('click',close);closeButton.addEventListener('click',close);
  document.addEventListener('keydown',event=>{
    if(!opened)return;if(event.key==='Escape'){event.preventDefault();close();return;}
    if(event.key!=='Tab')return;const focusable=[...panel.querySelectorAll('button:not([disabled]),input:not([disabled]),select:not([disabled]),a[href],audio[controls],[tabindex="0"]')].filter(item=>!item.closest('[hidden]'));
    if(!focusable.length){event.preventDefault();panel.focus();return;}const first=focusable[0],last=focusable[focusable.length-1];if(event.shiftKey&&(document.activeElement===first||document.activeElement===panel)){event.preventDefault();last.focus();}else if(!event.shiftKey&&document.activeElement===last){event.preventDefault();first.focus();}
  });
  tabs.addEventListener('keydown',event=>{const list=Object.values(tabButtons),index=list.indexOf(event.target);if(index<0||!['ArrowRight','ArrowLeft','Home','End'].includes(event.key))return;event.preventDefault();selectTab(Object.keys(tabNames)[event.key==='Home'?0:event.key==='End'?list.length-1:(index+(event.key==='ArrowRight'?1:-1)+list.length)%list.length],true);});
  window.addEventListener('stonefellow:chat-context-changing',()=>contextChanged({...context,ready:false}));
  window.addEventListener('vp3:participant-selection-changed',()=>{const scope=window.STONEFELLOW_CHAT_CONTINUITY?.phoneCameraScope?.();contextChanged(scope?{conversation_id:scope.conversation,agent_id:scope.agent,ready:true}:{...context,ready:false});});
  window.addEventListener('pagehide',dispose,{once:true});window.addEventListener('stonefellow:session-expired',revoke);window.addEventListener('stonefellow:logout',dispose);
  window.addEventListener('stonefellow:voice-lease-lost',()=>{if(active||callBusy)stopDevice('Microphone ownership changed. Phone audio has stopped.');});
  pollTimer=setInterval(()=>{if(document.visibilityState==='visible'&&(opened||deviceSession||cards.size))void refresh();},10000);
  window.VP3PhoneWorkspaceV1=Object.freeze({open,close,bindMessage,contextChanged,refresh,dispose});
  layer.id='vp3PhoneWorkspace';
  window.dispatchEvent(new Event('vp3:phone-workspace-ready'));
  render();void refresh();
})();
