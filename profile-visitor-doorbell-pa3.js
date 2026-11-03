(() => {
  'use strict';
  const cfg=window.VP3_PROFILE_DOORBELL_PA3 || {};
  const thread=document.getElementById('chatThread');
  if(!thread || !cfg.endpoint || !cfg.ownerEndpoint || !cfg.csrf || !Number.isSafeInteger(cfg.userId) || cfg.userId<1)return;
  const contract='vp3.profile-handoff.v1';
  const states=new Set(['awaiting_delivery','awaiting_owner','agent_ready','agent_running','owner_claimed','replied','blocked','cancelled']);
  const storageKey='vp3:profile-doorbell:'+cfg.userId;
  const tabKey=storageKey+':tab';
  const cards=new Map(),projections=new Map();
  let stopped=false,epoch=0,contextKey='',availability=null,reachable=false,presenceAt=0;
  let pumping=false,queued=false,preferenceBusy=false,initializedBlank=false,listSequence=0,actionSequence=0;
  let errorText='',availabilityNode=null,presenceText=null,interval=0,countdownInterval=0;
  const now=()=>typeof performance?.now==='function'?performance.now():Date.now();
  const positive=value=>Number.isSafeInteger(value)&&value>0;
  const nonnegative=value=>Number.isSafeInteger(value)&&value>=0;
  const token=value=>typeof value==='string'&&/^[a-f0-9]{32}$/.test(value);
  function randomToken(){const bytes=new Uint8Array(16);crypto.getRandomValues(bytes);return Array.from(bytes,n=>n.toString(16).padStart(2,'0')).join('');}
  function readStorage(key,fallback){try{return JSON.parse(sessionStorage.getItem(key)||'null')??fallback;}catch(_){return fallback;}}
  function saveStorage(key,value){try{sessionStorage.setItem(key,JSON.stringify(value));}catch(_){}}
  let tab=readStorage(tabKey,{});
  if(!token(tab.id)||!nonnegative(tab.sequence))tab={id:randomToken(),sequence:0};
  saveStorage(tabKey,tab);
  let drafts=readStorage(storageKey+':drafts',{});
  if(!drafts||typeof drafts!=='object'||Array.isArray(drafts))drafts={};
  function saveDraft(record){
    const text=String(record.input?.value||'');
    if(text.length>4000){record.notice.textContent='Your reply is longer than 4,000 characters. Shorten it before sending.';return;}
    drafts[record.data.turn_id]={text,at:Date.now(),attempt:record.attempt||null};
    drafts=Object.fromEntries(Object.entries(drafts).filter(([id,row])=>/^\d+$/.test(id)&&typeof row?.text==='string'&&row.text.length<=4000&&Number.isFinite(row.at)&&row.at<=Date.now()&&Date.now()-row.at<86400000).sort((a,b)=>b[1].at-a[1].at).slice(0,20));
    saveStorage(storageKey+':drafts',drafts);
  }
  const continuity=()=>window.STONEFELLOW_CHAT_CONTINUITY || window.STONEFELLOW_CHAT_CONTINUITY_V87 || {};
  function current(){const chat=continuity();return {chat_conversation_id:Number(chat.conversationId?.()||0),chat_agent_id:Number(chat.agentId?.()??window.STONEFELLOW_CHAT?.agentId??0)};}
  function active(){const chat=continuity();return !stopped&&!document.hidden&&document.visibilityState!=='hidden'&&navigator.onLine!==false&&thread.dataset.view==='chat'&&chat.isReady?.()!==false&&!chat.isRestoring?.();}
  function same(snapshot){const ctx=current();return !stopped&&snapshot.epoch===epoch&&ctx.chat_conversation_id===snapshot.chat_conversation_id&&ctx.chat_agent_id===snapshot.chat_agent_id;}
  function capture(){return {...current(),epoch};}
  function node(tag,className,text){const element=document.createElement(tag);if(className)element.className=className;if(text!==undefined)element.textContent=text;return element;}
  async function request(action,payload={},endpoint=cfg.endpoint,keepalive=false){
    const controller=new AbortController();let timer=0;
    const deadline=new Promise((_,reject)=>{timer=window.setTimeout(()=>{controller.abort();reject(new Error('Visitor request timed out.'));},12000);});
    const work=(async()=>{
      const response=await fetch(endpoint,{method:'POST',credentials:'same-origin',cache:'no-store',keepalive,signal:controller.signal,headers:{'Content-Type':'application/json'},body:JSON.stringify({action,csrf_token:cfg.csrf,...payload})});
      const data=await response.json().catch(()=>null);
      if(!response.ok || !data || data.ok!==true){const error=new Error('Visitor handoff is temporarily unavailable.');error.status=response.status;error.code=typeof data?.code==='string'?data.code:'';throw error;}
      return data;
    })();
    try{return await Promise.race([work,deadline]);}finally{window.clearTimeout(timer);}
  }
  function normalize(value){
    if(!value||value.contract!==contract||!['turn_id','revision','conversation_id','profile_agent_id','visitor_message_id'].every(k=>positive(value[k]))||!states.has(value.state)||!['waiting','agent','owner','none'].includes(value.responder))return null;
    if(!['can_claim','can_reply','can_delegate','can_resolve'].every(k=>typeof value[k]==='boolean'))return null;
    if(['execution_paused','dispatch_execution_paused'].some(key=>value[key]!==undefined&&typeof value[key]!=='boolean'))return null;
    if(!nonnegative(value.server_now_ms)||!['acknowledged_at_ms','pickup_deadline_ms'].every(k=>value[k]===null||nonnegative(value[k])))return null;
    if(typeof value.visitor_label!=='string'||value.visitor_label.length>240||typeof value.agent_name!=='string'||value.agent_name.length>240||typeof value.visitor_message!=='string'||value.visitor_message.length>16000)return null;
    if(!Array.isArray(value.history)||value.history.length>20||value.history.some(row=>!row||!positive(row.id)||!['visitor','owner','agent'].includes(row.sender_type)||typeof row.message!=='string'||row.message.length>32000||typeof row.created_at!=='string'||row.created_at.length>40))return null;
    return {...value,execution_paused:value.execution_paused===true,dispatch_execution_paused:value.dispatch_execution_paused===true,history:value.history.map(row=>({id:row.id,sender_type:row.sender_type,message:row.message,created_at:row.created_at}))};
  }
  function updatePresence(){
    if(!availabilityNode)return;
    availabilityNode.disabled=preferenceBusy||stopped;
    if(availability)availabilityNode.value=availability;
    const availableOption=availabilityNode.querySelector('option[value="available"]');
    if(availableOption)availableOption.textContent=availability==='available'&&!reachable?(errorText?'Available · offline':'Available · connecting'):'Available';
    presenceText.textContent=errorText || (availability==='away'?'Profile Agent handles new visitors while you are away.':availability==='dnd'?'Do not disturb. Profile Agent handles new visitors.':reachable&&active()?'Available here. Visitors appear in this Chat.':availability==='available'?'Connecting this Chat for visitors…':'Checking visitor availability…');
  }
  function setupPresence(){
    const host=document.querySelector('.chat-topbar-actions')||document.getElementById('chatComposerShell')?.parentElement;
    if(!host)return;
    const container=node('div','profile-doorbell-availability'),label=node('label','','Visitors');
    const select=node('select');select.id='profileDoorbellAvailability';select.setAttribute('aria-label','Availability for Profile visitors');
    [['available','Available'],['away','Away'],['dnd','Do not disturb']].forEach(([value,title])=>{const option=node('option','',title);option.value=value;select.appendChild(option);});
    label.setAttribute('for',select.id);presenceText=node('span','profile-doorbell-availability-status');presenceText.setAttribute('role','status');
    container.append(label,select,presenceText);host.appendChild(container);availabilityNode=select;updatePresence();
    select.addEventListener('change',()=>void setAvailability(select.value));
  }
  async function initializeBlank(){
    if(current().chat_conversation_id>0)return true;
    if(initializedBlank||!active()||typeof continuity().ensureConversation!=='function')return false;
    initializedBlank=true;
    try{await continuity().ensureConversation();}catch(_){errorText='Open or start a Chat to receive visitor cards.';}
    syncContext();return current().chat_conversation_id>0;
  }
  async function heartbeat(preference=null,hidden=false,keepalive=false){
    const snapshot=capture();
    const sequence=++tab.sequence;saveStorage(tabKey,tab);
    const payload={...current(),tab_id:tab.id,presence_sequence:sequence,visible:!hidden&&active()&&current().chat_conversation_id>0};
    if(preference!==null)payload.availability=preference;
    try{
      const data=await request('doorbell_presence',payload,cfg.endpoint,keepalive);
      if(!same(snapshot)||sequence!==tab.sequence||hidden)return;
      if(!['available','away','dnd'].includes(data.availability)||typeof data.reachable!=='boolean'||data.accepted===false)throw new Error('Invalid presence response.');
      availability=data.availability;reachable=data.reachable;presenceAt=now();errorText='';updatePresence();
    }catch(error){if(same(snapshot)&&!hidden){reachable=false;presenceAt=0;errorText='Visitor connection is unavailable. Your private Chat is unchanged.';updatePresence();}if(preference!==null)throw error;}
  }
  async function setAvailability(value){
    if(preferenceBusy||!['available','away','dnd'].includes(value))return;
    preferenceBusy=true;actionSequence++;updatePresence();
    try{if(value==='available')await initializeBlank();await heartbeat(value);}
    catch(_){/* The last saved preference remains visible after a failed write. */}
    finally{preferenceBusy=false;actionSequence++;updatePresence();void pump();}
  }
  function syncContext(){
    const ctx=current(),key=ctx.chat_conversation_id+':'+ctx.chat_agent_id;
    if(key!==contextKey){if(ctx.chat_conversation_id===0&&contextKey&&!contextKey.startsWith('0:'))initializedBlank=false;contextKey=key;epoch++;projections.clear();presenceAt=0;reachable=false;errorText='';for(const record of cards.values())refreshControls(record);}
    return ctx;
  }
  function invalidate(){
    if(stopped)return;
    for(const record of cards.values())saveDraft(record);
    void heartbeat(null,true,true);
    epoch++;contextKey='';projections.clear();presenceAt=0;reachable=false;initializedBlank=false;
    for(const record of cards.values())refreshControls(record);updatePresence();
  }
  function remaining(record){const deadline=record.data.pickup_deadline_ms;return deadline===null?null:Math.max(0,Math.ceil((deadline-record.clockServer-(now()-record.clockLocal))/1000));}
  function writable(record){return active()&&record.node.isConnected&&!record.blocked&&record.binding.chat_conversation_id===current().chat_conversation_id&&record.binding.chat_agent_id===current().chat_agent_id&&record.epoch===epoch&&!record.superseded;}
  function allowed(record,operation){return writable(record)&&!record.pending&&record.data['can_'+operation]===true&&!(record.data.state==='awaiting_owner'&&remaining(record)===0);}
  function refreshControls(record){
    for(const [operation,button] of Object.entries(record.buttons))button.disabled=!allowed(record,operation);
    record.input.disabled=!allowed(record,'reply');
    if(record.pending)record.status.textContent='Saving your visitor action…';
    else if(record.superseded)record.status.textContent='A newer message from this visitor is shown in this Chat.';
    else if(record.data.execution_paused||record.data.dispatch_execution_paused)record.status.textContent='Profile Agent is paused. You can reply to this visitor.';
    else record.status.textContent=({awaiting_delivery:'Waiting for visible delivery.',awaiting_owner:'You can reply, or let your Profile Agent answer.',owner_claimed:'You are handling this visitor.',agent_ready:'Your Profile Agent is preparing a reply.',agent_running:'Your Profile Agent is replying.',replied:record.data.responder==='owner'?'Your reply was sent.':record.data.responder==='agent'?'Your Profile Agent replied.':'A reply was sent.',blocked:'This visitor request needs attention.',cancelled:'This visitor request is closed.'})[record.data.state];
    const seconds=remaining(record);
    record.countdown.hidden=record.data.state!=='awaiting_owner';
    record.countdown.textContent=seconds>0?seconds+'s to pick up':seconds===0?'Checking the current responder…':'';
  }
  function renderHistory(record){
    const signature=JSON.stringify(record.data.history);
    if(signature===record.historySignature)return;
    record.historySignature=signature;record.history.replaceChildren();
    for(const row of record.data.history){const item=node('li','profile-doorbell-history-'+row.sender_type),who=node('strong','',row.sender_type==='visitor'?'Visitor':row.sender_type==='owner'?'You':record.data.agent_name||'Profile Agent');item.append(who,node('p','',row.message));record.history.appendChild(item);}
  }
  function createCard(data,element,binding){
    const body=element.querySelector('.message-body')||element;
    const card=node('section','profile-doorbell-card');card.dataset.profileDoorbellTurn=String(data.turn_id);card.setAttribute('aria-label','Profile visitor: '+data.visitor_label);
    const header=node('header'),heading=node('h3','',data.visitor_label||'Profile visitor');
    header.append(node('span','profile-doorbell-eyebrow','Visitor on your Profile'),heading,node('p','profile-doorbell-agent','With '+(data.agent_name||'your Profile Agent')));
    const status=node('p','profile-doorbell-status'),countdown=node('span','profile-doorbell-countdown');status.setAttribute('role','status');
    const question=node('blockquote','profile-doorbell-question',data.visitor_message);
    const details=node('details','profile-doorbell-history'),summary=node('summary','','Visitor conversation'),history=node('ol');details.append(summary,history);
    const controls=node('div','profile-doorbell-controls'),buttons={};
    for(const [operation,title] of [['claim',"I'll reply"],['delegate','Let Agent reply'],['resolve','Resolve']]){const button=node('button','',title);button.type='button';button.dataset.doorbellAction=operation;buttons[operation]=button;controls.appendChild(button);}
    const form=node('form','profile-doorbell-reply'),label=node('label','','Reply to this visitor'),input=node('textarea');
    input.rows=2;input.maxLength=4000;input.id='profileDoorbellReply'+data.turn_id;label.setAttribute('for',input.id);input.setAttribute('aria-label','Reply to '+(data.visitor_label||'this visitor'));input.placeholder='Your reply goes only to this visitor';
    const send=node('button','','Send to visitor');send.type='submit';send.dataset.doorbellAction='reply';buttons.reply=send;form.append(label,input,send);
    const notice=node('p','profile-doorbell-notice');notice.setAttribute('role','status');card.append(header,status,countdown,question,details,controls,form,notice);
    const record={data,node:card,element,binding,epoch,clockServer:data.server_now_ms,clockLocal:now(),status,countdown,question,history,input,buttons,notice,pending:false,attempt:null,ackBusy:false,ackRevision:0,historySignature:'',superseded:false};
    const saved=drafts[data.turn_id];if(saved&&typeof saved.text==='string'&&saved.text.length<=8000){input.value=saved.text;if(saved.text.length>4000)notice.textContent='Your saved reply is longer than 4,000 characters. Shorten it before sending.';if(saved.attempt&&token(saved.attempt.request_id))record.attempt=saved.attempt;}
    input.addEventListener('input',()=>{record.notice.textContent='';saveDraft(record);});
    for(const operation of ['claim','delegate','resolve'])buttons[operation].addEventListener('click',()=>void perform(record,operation));
    form.addEventListener('submit',event=>{event.preventDefault();event.stopPropagation();void perform(record,'reply');});
    body.appendChild(card);const placeholder=element.querySelector('.message-text');if(placeholder)placeholder.hidden=true;
    cards.set(data.turn_id,record);renderHistory(record);refreshControls(record);
    window.VP3ProfileCallsPa3c?.attachOwnerThread(controls,{binding:()=>writable(record)?{
      conversation_id:record.data.conversation_id,chat_conversation_id:record.binding.chat_conversation_id,chat_agent_id:record.binding.chat_agent_id
    }:null});
    return record;
  }
  function updateCard(record,data,binding){
    if(data.revision<record.data.revision)return record;
    if(data.conversation_id!==record.data.conversation_id||data.visitor_message_id!==record.data.visitor_message_id)return record;
    record.data=data;record.binding=binding;record.epoch=epoch;record.blocked=false;record.node.hidden=record.superseded;record.clockServer=data.server_now_ms;record.clockLocal=now();record.question.textContent=data.visitor_message;
    renderHistory(record);refreshControls(record);return record;
  }
  function applyProjection(value,binding=null,element=null){
    const data=normalize(value);if(!data)return null;
    const previous=projections.get(data.turn_id);if(previous&&previous.revision>data.revision)return null;
    projections.set(data.turn_id,data);
    let retainedDraft=false;
    const earlier=[...cards.values()].filter(record=>record.data.conversation_id===data.conversation_id&&record.data.turn_id<data.turn_id).sort((a,b)=>b.data.turn_id-a.data.turn_id);
    const prior=earlier[0],ctx=current();
    if(prior&&prior.epoch===epoch&&prior.binding.chat_conversation_id===ctx.chat_conversation_id&&prior.binding.chat_agent_id===ctx.chat_agent_id&&!prior.pending&&!prior.attempt&&!drafts[data.turn_id]?.text&&prior.input.value.trim()&&prior.input.value.length<=4000){
      drafts[data.turn_id]={text:prior.input.value,at:Date.now(),attempt:null};retainedDraft=true;
    }
    for(const record of earlier){saveDraft(record);record.superseded=true;record.node.hidden=true;const text=record.element.querySelector('.message-text');if(text){text.hidden=false;text.textContent='An earlier message from this visitor. The current conversation card is below.';}refreshControls(record);}
    if(!element)element=thread.querySelector('[data-profile-handoff-turn="'+data.turn_id+'"]');
    if(!element||Number(element.dataset.chatConversationId)!==current().chat_conversation_id)return null;
    const identity=binding||{chat_conversation_id:Number(element.dataset.chatConversationId),chat_message_id:Number(element.dataset.chatMessageId),chat_agent_id:current().chat_agent_id,delivery_id:typeof data.delivery_id==='string'?data.delivery_id:''};
    if(!positive(identity.chat_message_id)||identity.chat_conversation_id!==current().chat_conversation_id||identity.chat_agent_id!==current().chat_agent_id||Number(element.dataset.profileHandoffConversation)!==data.conversation_id)return null;
    let record=cards.get(data.turn_id);
    if(record&&!record.node.isConnected){record=null;cards.delete(data.turn_id);}
    if(record&&record.element!==element){saveDraft(record);record.node.remove();const old=record.element.querySelector('.message-text');if(old){old.hidden=false;old.textContent='The visitor conversation is shown in its current card.';}cards.delete(data.turn_id);record=null;}
    record=record?updateCard(record,data,identity):createCard(data,element,identity);
    if([...projections.values()].some(next=>next.conversation_id===data.conversation_id&&next.turn_id>data.turn_id)){record.superseded=true;record.node.hidden=true;refreshControls(record);}
    if(retainedDraft){record.notice.textContent='Draft kept from the previous visitor message. Review it before sending.';saveDraft(record);}
    return record;
  }
  function visible(record){
    if(!writable(record)||!reachable||availability!=='available'||record.node.closest('[hidden]'))return false;
    const box=record.node.getBoundingClientRect(),canvas=thread.getBoundingClientRect();
    if(box.width<=0||box.height<=0||canvas.width<=0||canvas.height<=0)return false;
    const style=window.getComputedStyle?.(record.node);if(style&&(style.display==='none'||style.visibility==='hidden'))return false;
    const height=window.innerHeight||document.documentElement?.clientHeight||0,width=window.innerWidth||document.documentElement?.clientWidth||0;
    const top=Math.max(box.top,canvas.top,0),bottom=Math.min(box.bottom,canvas.bottom,height),left=Math.max(box.left,canvas.left,0),right=Math.min(box.right,canvas.right,width);
    if(bottom-top<Math.min(48,box.height)||right<=left)return false;
    if(typeof document.elementFromPoint==='function'){
      const hit=document.elementFromPoint((left+right)/2,(top+bottom)/2);
      if(!hit||!record.node.contains(hit))return false;
    }
    return true;
  }
  async function acknowledge(record){
    if(record.ackBusy||record.ackRevision===record.data.revision||record.data.state!=='awaiting_delivery'||!token(record.binding.delivery_id)||!visible(record))return;
    const snapshot=capture(),revision=record.data.revision;
    record.ackBusy=true;
    try{
      const result=await request('doorbell_ack',{...record.binding,tab_id:tab.id,turn_id:record.data.turn_id,revision});
      if(!same(snapshot)||!record.node.isConnected)return;
      const card=normalize(result.turn);if(!card||card.turn_id!==record.data.turn_id||card.conversation_id!==record.data.conversation_id||card.revision<revision)throw new Error('Invalid delivery acknowledgment.');
      record.ackRevision=revision;applyProjection(card,record.binding,record.element);
    }catch(_){if(same(snapshot))record.notice.textContent='Checking visitor delivery. The response window starts only after confirmation.';}
    finally{record.ackBusy=false;}
  }
  function scheduleAcks(){
    if(!active())return;
    window.requestAnimationFrame(()=>window.requestAnimationFrame(()=>{for(const record of cards.values())void acknowledge(record);}));
  }
  async function perform(record,operation){
    if(!allowed(record,operation))return;
    const message=operation==='reply'?String(record.input.value||'').trim():'';
    if(operation==='reply'&&!message){record.notice.textContent='Write a reply for this visitor first.';record.input.focus();return;}
    if(operation==='reply'&&message.length>4000){record.notice.textContent='Your reply is longer than 4,000 characters. Shorten it before sending.';record.input.focus();return;}
    const payload={turn_id:record.data.turn_id,revision:record.data.revision,conversation_id:record.data.conversation_id,chat_conversation_id:record.binding.chat_conversation_id,chat_agent_id:record.binding.chat_agent_id};
    if(operation==='reply')payload.message=message;
    const key=JSON.stringify([operation,payload]);
    if(!record.attempt||record.attempt.key!==key)record.attempt={key,request_id:randomToken()};
    payload.request_id=record.attempt.request_id;const attempt=record.attempt,snapshot=capture();
    record.pending=true;record.notice.textContent='';actionSequence++;saveDraft(record);refreshControls(record);
    try{
      const result=await request('doorbell_'+operation,payload,cfg.ownerEndpoint);
      if(!same(snapshot)||!record.node.isConnected||record.attempt!==attempt)return;
      const data=normalize(result.turn);
      if(result.turn_id!==payload.turn_id||result.conversation_id!==payload.conversation_id||result.request_id!==payload.request_id||!data||data.turn_id!==payload.turn_id||data.conversation_id!==payload.conversation_id||data.revision<payload.revision)throw new Error('Action identity was not confirmed.');
      if(operation==='reply'&&String(record.input.value||'').trim()===message)record.input.value='';
      record.attempt=null;record.notice.textContent=({claim:'You picked up this visitor.',reply:'Reply sent to this visitor.',delegate:'Your Profile Agent will handle this visitor.',resolve:'Visitor conversation resolved.'})[operation];
      applyProjection(data,record.binding,record.element);saveDraft(record);
    }catch(error){
      if(same(snapshot)&&record.node.isConnected&&record.attempt===attempt){
        if([400,403,404,409,419,422].includes(error.status)){record.attempt=null;record.notice.textContent='This visitor action is no longer available. Your draft is kept.';}
        else record.notice.textContent='The result could not be confirmed. Your draft is kept; retry uses the same request.';
        saveDraft(record);
      }
    }finally{
      record.pending=false;actionSequence++;refreshControls(record);if(same(snapshot))void pump();
    }
  }
  async function deliver(data,snapshot){
    const prior=cards.get(data.turn_id);
    if(prior&&prior.node.isConnected&&prior.binding.delivery_id&&prior.binding.chat_conversation_id===snapshot.chat_conversation_id&&prior.binding.chat_agent_id===snapshot.chat_agent_id){applyProjection(data,prior.binding,prior.element);return;}
    const result=await request('doorbell_delivery',{turn_id:data.turn_id,revision:data.revision,tab_id:tab.id,chat_conversation_id:snapshot.chat_conversation_id,chat_agent_id:snapshot.chat_agent_id});
    if(!same(snapshot))return;
    const next=normalize(result.turn),messageId=Number(result.chat_message_id||result.message_id);
    if(!next||next.turn_id!==data.turn_id||next.conversation_id!==data.conversation_id||result.chat_conversation_id!==snapshot.chat_conversation_id||next.chat_agent_id!==snapshot.chat_agent_id||!positive(messageId)||!token(result.delivery_id))return;
    const element=continuity().mountProfileHandoff?.({...result,turn:next});
    if(!element)return;
    applyProjection(next,{delivery_id:result.delivery_id,chat_conversation_id:snapshot.chat_conversation_id,chat_agent_id:snapshot.chat_agent_id,chat_message_id:messageId},element);
  }
  async function pump(){
    if(stopped)return;
    if(pumping){queued=true;return;}
    if(!active()){reachable=false;updatePresence();return;}
    pumping=true;queued=false;let snapshot=null;
    try{
      syncContext();snapshot=capture();
      const seq=++listSequence,actionsAtStart=actionSequence;
      const result=await request('doorbell_list',{...current(),tab_id:tab.id});
      if(!same(snapshot)||seq!==listSequence||actionsAtStart!==actionSequence)return;
      if(!['available','away','dnd'].includes(result.availability)||!Array.isArray(result.turns)||result.turns.length>50)throw new Error('Invalid visitor list.');
      availability=result.availability;
      if(snapshot.chat_conversation_id===0&&availability==='available'){if(await initializeBlank()){queued=true;return;}errorText='Open or start a Chat to receive visitor cards.';updatePresence();return;}
      if(!presenceAt||now()-presenceAt>=10000)await heartbeat();
      if(!same(snapshot))return;
      const returned=new Set();
      for(const value of result.turns){const data=normalize(value);if(!data)continue;returned.add(data.turn_id);projections.set(data.turn_id,data);try{await deliver(data,snapshot);}catch(_){if(same(snapshot))errorText='Visitor cards are reconnecting. Your drafts are kept.';}if(!same(snapshot))return;}
      for(const record of cards.values())if(record.node.isConnected&&!returned.has(record.data.turn_id)){saveDraft(record);record.blocked=true;record.node.hidden=true;const text=record.element.querySelector('.message-text');if(text){text.hidden=false;text.textContent='Review this visitor conversation in Profile Activity.';}}
      for(const record of cards.values())refreshControls(record);
      updatePresence();scheduleAcks();
    }catch(error){if(snapshot&&same(snapshot)){errorText='Visitor connection is unavailable. Your private Chat is unchanged.';reachable=false;if([401,403,404,419].includes(error.status)){projections.clear();for(const record of cards.values()){saveDraft(record);record.blocked=true;record.node.hidden=true;refreshControls(record);}}updatePresence();}}
    finally{pumping=false;if(queued&&!stopped){queued=false;window.setTimeout(()=>void pump(),0);}}
  }
  function restoreMarkers(){for(const [id,data] of projections){const record=cards.get(id);if(!record?.node.isConnected)applyProjection(data);}scheduleAcks();}
  window.addEventListener('vp3:profile-handoff-message',restoreMarkers);
  window.addEventListener('vp3:profile-handoff-refresh',()=>void pump());
  window.addEventListener('vp3:chat-context-ready',()=>{syncContext();void pump();});
  window.addEventListener('stonefellow:chat-context-changing',invalidate);
  window.addEventListener('vp3:chat-canvas-view-changed',()=>{if(!active())invalidate();else void pump();});
  document.addEventListener('visibilitychange',()=>{if(document.hidden)invalidate();else void pump();});
  window.addEventListener('offline',invalidate);window.addEventListener('online',()=>void pump());
  thread.addEventListener('scroll',scheduleAcks,{passive:true});window.addEventListener('resize',scheduleAcks);
  window.addEventListener('pagehide',()=>{invalidate();stopped=true;window.clearInterval(interval);window.clearInterval(countdownInterval);}, {once:true});
  setupPresence();
  interval=window.setInterval(()=>void pump(),3000);
  countdownInterval=window.setInterval(()=>{for(const record of cards.values())refreshControls(record);},500);
  window.VP3_PROFILE_DOORBELL_PA3_RUNTIME={refresh:()=>pump()};
  void pump();
})();
