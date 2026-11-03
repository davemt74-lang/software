(() => {
  'use strict';
  if(window.VP3ProfileSpeechPa4a)return;
  const cfg=window.VP3_PROFILE_SPEECH_PA4A||{},CONTRACT='vp3.profile-speech.v1';
  const positive=n=>Number.isSafeInteger(n)&&n>0,nonnegative=n=>Number.isSafeInteger(n)&&n>=0;
  const token=s=>typeof s==='string'&&/^[a-f0-9]{32}$/.test(s);
  const time=()=>performance.now(),random=()=>{const bytes=new Uint8Array(16);crypto.getRandomValues(bytes);return Array.from(bytes,n=>n.toString(16).padStart(2,'0')).join('');};
  const node=(tag,cls,text)=>{const el=document.createElement(tag);if(cls)el.className=cls;if(text!==undefined)el.textContent=text;return el;};
  const canonical=()=>window.STONEFELLOW_CHAT_CONTINUITY_V87||{},doorbell=()=>window.VP3_PROFILE_DOORBELL_PA3_RUNTIME||{};
  const items=new Map(),controls=new Set(),attached=new WeakSet(),clientInstance=random();
  let alive=true,epoch=0,requestSequence=0,lastContext='',loading=false,player=null,armed=null,otherSpeech=false,voiceMaster=window.STONEFELLOW_AGENT_IDENTITY_V236?.agentVoiceEnabled!==false;
  const states=new Set(['available','pending','granted','playing','client_finished','interrupted','blocked','outcome_unverified','expired']);
  const privateInput=document.getElementById('chatInput'),privateForm=document.getElementById('chatForm'),thread=document.getElementById('chatThread');
  function context(){const chat=canonical(),presence=doorbell().speechContext?.()||{};return {chat_conversation_id:Number(chat.conversationId?.()||0),chat_agent_id:Number(chat.agentId?.()||0),tab_id:presence.tab_id||'',availability:presence.availability,reachable:presence.reachable===true,
    active:alive&&!document.hidden&&document.visibilityState!=='hidden'&&navigator.onLine!==false&&thread?.dataset.view==='chat'&&chat.isReady?.()===true&&chat.isRestoring?.()===false&&presence.active===true};}
  function capture(){const ctx=context();return {...ctx,epoch,key:[ctx.chat_conversation_id,ctx.chat_agent_id,ctx.tab_id].join(':')};}
  function current(snapshot){const ctx=capture();return ctx.active&&positive(ctx.chat_conversation_id)&&token(ctx.tab_id)&&snapshot.epoch===epoch&&snapshot.key===ctx.key;}
  function normalize(raw){
    if(!raw||!['id','revision','voice_assignment_id','voice_revision'].every(key=>positive(raw[key]))||!nonnegative(raw.public_conversation_id)||!['arrival','visitor_message'].includes(raw.kind)||!states.has(raw.state))return null;
    if(raw.kind==='arrival'?(raw.turn_id!==0||raw.visitor_message_id!==0):!['public_conversation_id','turn_id','visitor_message_id'].every(key=>positive(raw[key])))return null;
    if(typeof raw.can_play!=='boolean'||typeof raw.automatic_allowed!=='boolean'||typeof raw.visitor_label!=='string'||raw.visitor_label.length>240||typeof raw.display_text!=='string'||raw.display_text.length>16000||typeof raw.reason!=='string'||raw.reason.length>100)return null;
    if(raw.can_retry!==undefined&&typeof raw.can_retry!=='boolean')return null;
    if(raw.latest_attempt_id!==undefined&&raw.latest_attempt_id!==null&&!nonnegative(raw.latest_attempt_id))return null;
    if(raw.playback_state!==undefined&&!['none','granted','playing','ended','interrupted','blocked'].includes(raw.playback_state))return null;
    return {...raw};
  }
  function binding(record){return {item_id:record.item.id,revision:record.item.revision,...record.binding,tab_id:record.snapshot.tab_id,client_instance_id:clientInstance};}
  function painted(element){
    if(!element?.isConnected||element.closest?.('[hidden]'))return false;
    const style=window.getComputedStyle?.(element);if(style&&(style.display==='none'||style.visibility==='hidden'))return false;
    const rect=element.getBoundingClientRect(),canvas=thread?.getBoundingClientRect();if(!canvas||rect.width<=0||rect.height<=0)return false;
    const top=Math.max(0,rect.top,canvas.top),bottom=Math.min(window.innerHeight,rect.bottom,canvas.bottom),left=Math.max(0,rect.left,canvas.left),right=Math.min(window.innerWidth,rect.right,canvas.right);
    if(bottom-top<Math.min(32,rect.height)||right<=left)return false;
    const hit=document.elementFromPoint?.((left+right)/2,(top+bottom)/2);return !document.elementFromPoint||Boolean(hit&&element.contains(hit));
  }
  function captureBusy(allowChat=false){
    const lease=window.StonefellowVoiceLeaseV122;
    if(!lease?.proof?.key)return true;
    if(allowChat&&lease.owns?.()===true)return false;
    try{const raw=localStorage.getItem(lease.proof.key);if(!raw)return false;const saved=JSON.parse(raw);return !saved||typeof saved.owner!=='string'||!Number.isFinite(saved.expiresAt)||saved.expiresAt>Date.now();}catch(_){return true;}
  }
  function idleChatListening(){
    const proof=window.STONEFELLOW_CHAT_VOICE,voice=window.VP3ConversationControls;
    // owns() verifies the actual same-tab capture lease. The canonical voice
    // runtime owns the exclusive 'chat' ticket while enabled and listening.
    return proof?.directOwner===true&&window.STONEFELLOW_CHAT_CONTINUITY?.conversationId?.()===context().chat_conversation_id&&voice?.isEnabled?.()===true&&voice.state?.().state==='listening'&&voice.isBusy?.()===false&&window.STONEFELLOW_CHAT_CONTINUITY?.pendingVoiceSubmit?.()===false&&window.STONEFELLOW_CHAT_CONTINUITY?.isBusy?.()===false&&!String(privateInput?.value||'').trim()&&window.StonefellowVoiceLeaseV122?.owns?.()===true;
  }
  function ownEchoActive(){
    const session=player,proof=window.STONEFELLOW_CHAT_VOICE;
    return Boolean(session?.started&&session.echoAttached&&session.chatListening&&window.VP3ConversationControls?.isEnabled?.()===true&&window.VP3ConversationControls?.state?.().state==='speaking'&&window.StonefellowVoiceLeaseV122?.owns?.()===true&&
      window.STONEFELLOW_CHAT_CONTINUITY?.conversationId?.()===session.snapshot.chat_conversation_id&&['submits','apiTurns','interruptions'].every(key=>proof?.[key]===session.voiceProof[key]));
  }
  function mediaBusy(){
    const coordinated=idleChatListening()||ownEchoActive();
    return window.VP3ProfileCallsPa3c?.speechBusy?.()===true||otherSpeech||canonical().isBusy?.()===true||Boolean(String(privateInput?.value||'').trim())||window.STONEFELLOW_CHAT_CONTINUITY?.pendingVoiceSubmit?.()===true||
      (window.VP3ConversationControls?.isEnabled?.()===true&&!coordinated)||(window.STONEFELLOW_CHAT_CONTINUITY?.isBusy?.()===true&&!ownEchoActive())||captureBusy(coordinated)||[...document.querySelectorAll('audio,video')].some(media=>media!==player?.audio&&!media.paused&&!media.ended);
  }
  function selectedSource(record){const control=record.manualControl,source=control?.options.source();return Boolean(control?.host.isConnected&&source&&source.turn_id===record.item.turn_id&&source.public_conversation_id===record.item.public_conversation_id&&source.visitor_message_id===record.item.visitor_message_id);}
  function presented(record){return painted(record.node)||(selectedSource(record)&&painted(record.manualControl.host));}
  function eligible(record,automatic=false,retry=false){const ctx=context();return current(record.snapshot)&&(retry?record.item.can_retry===true||record.retryAllowed:record.item.can_play)&&presented(record)&&!mediaBusy()&&(!automatic||(voiceMaster&&ctx.availability==='available'&&ctx.reachable&&record.item.automatic_allowed));}
  function safeError(error){
    if(/AUDIO_EXPIRED$/.test(error?.code||''))return 'This saved audio expired. Generating it again may use additional voice credits.';
    if(error?.code==='PROFILE_SPEECH_OUTCOME_UNVERIFIED'||error?.status===202)return 'The speech result is not confirmed. Your visitor text remains available. Check the status before retrying.';
    if(error?.name==='NotAllowedError')return 'Your browser blocked playback. Choose Play to listen; the visitor text remains visible.';
    return 'Visitor speech is unavailable. You can read the message and reply in text.';
  }
  async function request(action,payload={},options={}){
    const method=options.method||'POST',controller=options.controller||new AbortController(),sentAt=time(),sequence=++requestSequence;let timer;
    const url=new URL(cfg.endpoint,location.href),init={method,credentials:'same-origin',cache:'no-store',signal:controller.signal};
    if(method==='GET')Object.entries({action,...payload}).forEach(([key,value])=>url.searchParams.set(key,String(value)));
    else{init.headers={'Content-Type':'application/json'};init.body=JSON.stringify({action,csrf_token:cfg.csrf,...payload});}
    const run=(async()=>{
      const response=await fetch(url,init);
      if(options.audio&&response.ok&&response.status!==202&&/^audio\/mpeg(?:;|$)/i.test(response.headers.get('Content-Type')||'')){
        const size=Number(response.headers.get('Content-Length'));if(!positive(size)||size>4194304)throw new Error('Invalid audio size.');
        const blob=await response.blob();if(blob.size!==size||!blob.size||blob.size>4194304)throw new Error('Invalid audio body.');return {blob,sentAt,sequence};
      }
      const data=await response.json().catch(()=>null);
      if(!response.ok||response.status===202||data?.ok!==true||data.contract!==CONTRACT){const error=new Error('Speech unavailable.');error.code=typeof data?.code==='string'?data.code:'';error.status=response.status;throw error;}
      return {data,sentAt,sequence};
    })();
    try{return await Promise.race([run,new Promise((_,reject)=>{timer=setTimeout(()=>{controller.abort();reject(new Error('Speech request timed out.'));},options.audio?65000:12000);})]);}finally{clearTimeout(timer);}
  }
  function show(record,message){if(record?.node?.isConnected)record.notice.textContent=message;}
  function render(record){
    record.text.textContent=record.item.display_text;record.label.textContent=(record.item.visitor_label||'Profile visitor')+' · '+(record.item.kind==='arrival'?'Agent announcement':'Visitor reading voice');
    const busy=player?.record===record,tapReady=busy&&player.awaitingGesture===true;record.play.textContent=tapReady?'Tap to play':busy?(player.started?'Playing':'Preparing speech…'):'Play';record.play.disabled=(busy&&!tapReady)||record.awaitingReport||(!busy&&!record.item.can_play)||!current(record.snapshot);record.stop.hidden=!busy;
    record.retry.hidden=record.item.can_retry!==true&&!record.retryAllowed;
    record.retry.disabled=busy||!current(record.snapshot);
  }
  function echo(state,session){window.dispatchEvent(new CustomEvent('stonefellow:agent-proactive-speech',{detail:{state,text:state==='start'?session.record.item.display_text:'',source:'profile-speech-pa4a',item_id:session.record.item.id}}));}
  async function report(session,status){
    if(!session.attempt||!session.playToken)return;
    session.reports??={};
    if(status==='playing'&&session.reportBusy)return;
    const sequence=status==='playing'?(session.reportSequence=(session.reportSequence||0)+1):null,key=sequence?'playing:'+sequence:status;
    session.reports[key]??=random();if(status==='playing')session.reportBusy=true;
    try{
      const {data,sentAt}=await request('owner_report',{...session.bound,attempt_id:session.attempt.attempt_id,ticket:session.attempt.ticket,play_token:session.playToken,status,...(sequence?{report_sequence:sequence}:{}),request_id:session.reports[key]});
      if(status==='playing'&&player===session&&!session.closed){
        const remaining=data.lease_until_ms-data.server_now_ms;
        if(data.attempt_id!==session.attempt.attempt_id||data.client_reported!==true||data.report_sequence!==sequence||sequence!==session.reportSequence||!nonnegative(data.server_now_ms)||!positive(data.lease_until_ms)||remaining<=0||remaining>30000||sentAt+remaining<=time())throw new Error('Playback authority expired.');
        session.deadline=Math.min(session.absoluteDeadline,sentAt+remaining);session.authorityDeadline=Math.min(session.deadline,sentAt+10000);session.lastReport=time();
      }
      if(status!=='playing'&&data.attempt_id===session.attempt.attempt_id&&data.client_reported===true&&data.state===status)session.record.claim=null;
    }catch(_){if(status==='playing'&&player===session)stop('Visitor speech stopped because current playback permission could not be confirmed.');}
    finally{if(status==='playing')session.reportBusy=false;else{session.record.awaitingReport=false;render(session.record);void refresh();}}
  }
  function stop(reason='Playback stopped.',status='interrupted'){
    const session=player;if(!session)return;player=null;session.closed=true;session.controller.abort();clearInterval(session.timer);
    if(session.audio){session.audio.onplaying=session.audio.onended=session.audio.onerror=null;try{session.audio.pause();session.audio.removeAttribute('src');session.audio.load?.();}catch(_){}session.audio.remove();}
    if(session.url)URL.revokeObjectURL(session.url);
    if(session.started)echo('end',session);
    if(session.playToken){session.record.awaitingReport=true;void report(session,status);}show(session.record,reason);render(session.record);updateControls();
  }
  function validSession(session){return player===session&&!session.closed&&current(session.snapshot)&&session.record.item.id===session.bound.item_id&&session.record.item.revision===session.bound.revision&&session.record.item.voice_revision===session.voiceRevision&&session.record.item.display_text===session.sourceText&&session.record.item.visitor_label===session.sourceLabel&&presented(session.record)&&!mediaBusy()&&(!session.deadline||time()<session.deadline)&&(!session.authorityDeadline||time()<session.authorityDeadline);}
  function invalidPlayback(session){
    if(player!==session)return;
    const expired=(session.deadline&&time()>=session.deadline)||(session.authorityDeadline&&time()>=session.authorityDeadline);
    if(expired&&!session.started)session.record.claim=null;
    stop(expired&&!session.started?'Playback permission expired. Choose Play to authorize the saved audio again.':'Visitor speech stopped because the current context or voice surface changed.',session.started?'interrupted':'blocked');
  }
  function startAudio(session){
    if(player!==session||session.playPending)return Promise.resolve();
    if(!validSession(session)){invalidPlayback(session);return Promise.resolve();}
    session.awaitingGesture=false;session.playPending=true;render(session.record);updateControls();
    // A retained-element tap must reach play() synchronously in its click task.
    // No network request, await or permission renewal precedes this call.
    let result;try{result=session.audio.play();}catch(error){result=Promise.reject(error);}
    return Promise.resolve(result).then(()=>{if(player===session&&!validSession(session))invalidPlayback(session);}).catch(error=>{
      if(player!==session)return;
      if(error?.name==='NotAllowedError'&&!session.started&&validSession(session)){
        session.awaitingGesture=true;show(session.record,'Your browser blocked playback. Tap to play the prepared audio.');
      }else stop(safeError(error),session.started?'interrupted':'blocked');
    }).finally(()=>{session.playPending=false;if(player===session){render(session.record);updateControls();}});
  }
  function playFromControl(record){
    if(player?.record===record){if(player.awaitingGesture)void startAudio(player);return;}
    void play(record);
  }
  async function play(record,automatic=false,retry=false){
    if(player||armed||record.awaitingReport||!eligible(record,automatic,retry)){show(record,'Speech is paused while another voice surface or call is active. You can read and reply in text.');return;}
    if(retry&&!window.confirm('Generate this visitor speech again? The previous request may already have used voice credits, and this request may use additional credits.'))return;
    const proof=window.STONEFELLOW_CHAT_VOICE||{};
    const session={record,snapshot:capture(),bound:binding(record),voiceRevision:record.item.voice_revision,sourceText:record.item.display_text,sourceLabel:record.item.visitor_label,openedSequence:requestSequence,controller:new AbortController(),closed:false,started:false,deadline:0,attempt:null,playToken:null,audio:null,url:null,
      chatListening:idleChatListening(),voiceProof:{submits:proof.submits,apiTurns:proof.apiTurns,interruptions:proof.interruptions}};
    player=session;render(record);show(record,'Preparing visitor speech…');
    // Exact request identity remains in memory for a pending/uncertain response;
    // a refresh or context change never silently creates a second paid attempt.
    const key=JSON.stringify({...session.bound,revision:0});if(!record.claim||record.claim.key!==key||retry)record.claim={key,request_id:random(),mode:automatic?'automatic':'manual',play_request_id:random()};
    session.timer=setInterval(()=>{if(!validSession(session))invalidPlayback(session);else if(session.started&&time()-(session.lastReport||0)>=8000)void report(session,'playing');},150);
    try{
      const extra=retry?{retry_of:record.item.latest_attempt_id||record.lastAttemptId,retry_confirmed:true}:{};
      const {data:claim}=await request(retry?'owner_retry':'owner_claim',{...session.bound,...extra,request_id:record.claim.request_id,mode:record.claim.mode},{controller:session.controller});
      if(!validSession(session))return;
      const item=normalize(claim.item);
      if(!item||item.id!==record.item.id||item.voice_revision<session.voiceRevision||!positive(claim.attempt_id))throw new Error('Speech claim was not confirmed.');
      record.lastAttemptId=claim.attempt_id;session.attempt=claim;
      if(!['reserved','ready'].includes(claim.state)||typeof claim.ticket!=='string'||claim.ticket.length<32||claim.ticket.length>512){const error=new Error('Speech result unverified.');error.code='PROFILE_SPEECH_OUTCOME_UNVERIFIED';throw error;}
      // Claims may advance a source revision; use only the server's returned
      // source bound to the same immutable public message and voice assignment.
      if(item.public_conversation_id!==record.item.public_conversation_id||item.visitor_message_id!==record.item.visitor_message_id||item.turn_id!==record.item.turn_id||item.voice_assignment_id!==record.item.voice_assignment_id)throw new Error('Speech source changed.');
      record.item=item;session.bound.revision=item.revision;session.voiceRevision=item.voice_revision;session.sourceText=item.display_text;session.sourceLabel=item.visitor_label;
      const {blob}=await request('owner_audio',{...session.bound,attempt_id:claim.attempt_id,ticket:claim.ticket,request_id:record.claim.request_id},{audio:true,controller:session.controller});
      if(!validSession(session))return;
      session.audio=node('audio','profile-speech-audio');session.audio.hidden=true;session.audio.preload='none';session.url=URL.createObjectURL(blob);session.audio.src=session.url;record.node.appendChild(session.audio);
      const {data:permit,sentAt}=await request('owner_play',{...session.bound,attempt_id:claim.attempt_id,ticket:claim.ticket,request_id:record.claim.play_request_id},{controller:session.controller});
      if(!validSession(session))return;
      if(permit.attempt_id!==claim.attempt_id||permit.granted!==true||!token(permit.play_token)||!nonnegative(permit.server_now_ms)||!positive(permit.lease_until_ms))throw new Error('Playback permission was not confirmed.');
      const remaining=permit.lease_until_ms-permit.server_now_ms,absolute=permit.absolute_until_ms===undefined?600000:permit.absolute_until_ms-permit.server_now_ms;
      if(!Number.isSafeInteger(absolute)||absolute<=0||absolute>600000)throw new Error('Playback limit was not confirmed.');
      session.deadline=sentAt+remaining;session.absoluteDeadline=sentAt+absolute;session.authorityDeadline=sentAt+Math.min(10000,remaining);session.playToken=permit.play_token;session.permitReadSequence=requestSequence;
      if(remaining<=0||remaining>30000||!validSession(session))throw new Error('Playback permission expired.');
      session.audio.onplaying=()=>{if(!validSession(session)){stop();return;}session.started=true;show(record,'Playing visitor speech.');render(record);session.echoAttached=true;session.emittingEcho=true;try{echo('start',session);}finally{session.emittingEcho=false;}void report(session,'playing');};
      session.audio.onended=()=>stop('Visitor speech finished.','ended');session.audio.onerror=()=>stop('Audio playback failed. The visitor text remains available.','blocked');
      await startAudio(session);
    }catch(error){
      if(player!==session)return;record.retryAllowed=/AUDIO_EXPIRED$|UNVERIFIED|RETRY_REQUIRED/.test(error?.code||'');
      stop(safeError(error),session.started?'interrupted':'blocked');
    }finally{if(player===session&&!session.audio)stop('The speech result is not confirmed. Your visitor text remains available.');}
  }
  function create(item,element,bound,snapshot,fresh){
    const card=node('section','profile-speech-card');card.dataset.speechItem=String(item.id);
    const label=node('h3','profile-speech-label'),text=node('p','profile-speech-text'),actions=node('div','profile-speech-controls'),playButton=node('button','','Play'),stopButton=node('button','','Stop'),retry=node('button','','Generate again'),notice=node('p','profile-speech-notice');
    playButton.type=stopButton.type=retry.type='button';playButton.dataset.speechAction='play';stopButton.dataset.speechAction='stop';retry.dataset.speechAction='retry';notice.setAttribute('role','status');actions.append(playButton,stopButton,retry);card.append(label,text,actions,notice);
    const body=element.querySelector('.message-body')||element;body.appendChild(card);const placeholder=element.querySelector('.message-text');if(placeholder)placeholder.hidden=true;
    const record={item,node:card,element,label,text,play:playButton,stop:stopButton,retry,notice,binding:bound,snapshot,fresh,autoTried:false};items.set(item.id,record);
    playButton.addEventListener('click',()=>playFromControl(record));stopButton.addEventListener('click',()=>stop());retry.addEventListener('click',()=>void play(record,false,true));render(record);return record;
  }
  async function refresh(){
    if(loading||!cfg.endpoint||!cfg.csrf||!alive)return;const snapshot=capture();if(!current(snapshot))return;
    if(lastContext!==snapshot.key){invalidate('Chat context changed.');lastContext=snapshot.key;return refresh();}
    loading=true;
    try{
      const {data,sequence}=await request('owner_state',{chat_conversation_id:snapshot.chat_conversation_id,chat_agent_id:snapshot.chat_agent_id,tab_id:snapshot.tab_id,client_instance_id:clientInstance},{method:'GET'});
      if(!current(snapshot))return;if(!Array.isArray(data.items)||data.items.length>50||typeof data.ready!=='boolean')throw new Error('Invalid speech list.');
      if(!data.ready&&player)stop('Visitor speech is no longer available.');
      const seen=new Set();
      for(const raw of data.items){const item=normalize(raw);if(!item)continue;seen.add(item.id);let record=items.get(item.id);
        if(record?.node.isConnected&&current(record.snapshot)){
          if(item.revision>=record.item.revision){
            const session=player?.record===record?player:null;
            if(session&&(item.revision!==record.item.revision||item.voice_revision!==record.item.voice_revision||item.display_text!==session.sourceText||item.visitor_label!==session.sourceLabel||item.reason!==''||(session.playToken&&sequence>session.permitReadSequence&&((item.attempt_id??item.latest_attempt_id)!==session.attempt.attempt_id||!['granted','playing'].includes(item.playback_state)))))stop('This visitor speech changed.');
            record.item=item;record.snapshot=snapshot;if(item.can_play&&item.latest_attempt_id===record.lastAttemptId&&['ended','interrupted','blocked'].includes(item.playback_state))record.claim=null;render(record);
          }continue;
        }
        const {data:delivery}=await request('owner_delivery',{item_id:item.id,revision:item.revision,chat_conversation_id:snapshot.chat_conversation_id,chat_agent_id:snapshot.chat_agent_id,tab_id:snapshot.tab_id,client_instance_id:clientInstance,request_id:random()});
        if(!current(snapshot))return;const next=normalize(delivery.item);
        if(!next||next.id!==item.id||delivery.chat_conversation_id!==snapshot.chat_conversation_id||delivery.chat_agent_id!==snapshot.chat_agent_id||!positive(delivery.chat_message_id)||!token(delivery.delivery_id))continue;
        const existed=thread.querySelector('[data-chat-message-id="'+delivery.chat_message_id+'"]');
        const element=canonical().mountProfileSpeech?.(delivery);if(!element)continue;
        record=create(next,element,{chat_conversation_id:delivery.chat_conversation_id,chat_agent_id:delivery.chat_agent_id,chat_message_id:delivery.chat_message_id,delivery_id:delivery.delivery_id},snapshot,!existed);
      }
      for(const record of items.values())if(current(record.snapshot)&&!seen.has(record.item.id)){
        if(player?.record===record&&sequence>player.openedSequence)stop('This visitor speech is no longer available.');
        if(!selectedSource(record)){record.item.can_play=false;record.node.hidden=true;}
      }
      updateControls();autoPlay();
    }catch(error){if(current(snapshot)&&[401,403,404,419].includes(error.status))invalidate('Visitor speech access changed.');}
    finally{loading=false;}
  }
  function autoPlay(){
    if(player||armed)return;
    for(const record of items.values())if(record.fresh&&!record.autoTried&&record.item.state==='available'&&eligible(record,true)){record.autoTried=true;void play(record,true);break;}
  }
  function sourceRecord(source){return source?[...items.values()].find(record=>record.item.kind==='visitor_message'&&record.item.turn_id===source.turn_id&&record.item.public_conversation_id===source.public_conversation_id&&record.item.visitor_message_id===source.visitor_message_id&&current(record.snapshot)):null;}
  function updateControls(){for(const control of controls){if(!control.host.isConnected)continue;const target=control.options.target(),source=control.options.source(),record=sourceRecord(source),tapReady=player?.record===record&&player?.awaitingGesture===true;control.speak.disabled=!target||!target.draft_empty;control.speak.textContent=armed?.control===control?'Cancel spoken draft':'Speak reply';control.play.textContent=tapReady?'Tap to play visitor message':'Play visitor message';control.play.disabled=!source||control.loading||Boolean(player&&!tapReady);control.stop.hidden=!player||player.record!==record;}}
  async function playSelected(control){
    const source=control.options.source(),snapshot=capture();
    if(!source||control.loading||player||armed||!current(snapshot)||!painted(control.host))return;
    control.loading=true;updateControls();
    const sameSource=()=>{const next=control.options.source();return current(snapshot)&&painted(control.host)&&next&&['turn_id','public_conversation_id','visitor_message_id'].every(key=>next[key]===source[key]);};
    try{
      // Agent-only messages are intentionally absent from proactive lists.
      // Only this explicit visible card selection requests its exact scope.
      const {data}=await request('owner_state',{chat_conversation_id:snapshot.chat_conversation_id,chat_agent_id:snapshot.chat_agent_id,tab_id:snapshot.tab_id,client_instance_id:clientInstance,public_conversation_id:source.public_conversation_id},{method:'GET'});
      if(!sameSource())return;if(!data.ready||!Array.isArray(data.items)||data.items.length>50)throw new Error('Visitor speech unavailable.');
      const matches=data.items.map(normalize).filter(item=>item&&item.kind==='visitor_message'&&item.turn_id===source.turn_id&&item.public_conversation_id===source.public_conversation_id&&item.visitor_message_id===source.visitor_message_id);
      if(matches.length!==1||!matches[0].can_play)throw new Error('Visitor speech unavailable.');const item=matches[0];let record=sourceRecord(source);
      if(!record?.node.isConnected){
        const {data:delivery}=await request('owner_delivery',{item_id:item.id,revision:item.revision,chat_conversation_id:snapshot.chat_conversation_id,chat_agent_id:snapshot.chat_agent_id,tab_id:snapshot.tab_id,client_instance_id:clientInstance,request_id:random()});
        if(!sameSource())return;const next=normalize(delivery.item);
        if(!next||next.id!==item.id||next.turn_id!==source.turn_id||next.public_conversation_id!==source.public_conversation_id||next.visitor_message_id!==source.visitor_message_id||delivery.chat_conversation_id!==snapshot.chat_conversation_id||delivery.chat_agent_id!==snapshot.chat_agent_id||!positive(delivery.chat_message_id)||!token(delivery.delivery_id))throw new Error('Visitor speech changed.');
        const element=canonical().mountProfileSpeech?.(delivery);if(!element)throw new Error('Visitor speech could not be displayed.');
        record=create(next,element,{chat_conversation_id:delivery.chat_conversation_id,chat_agent_id:delivery.chat_agent_id,chat_message_id:delivery.chat_message_id,delivery_id:delivery.delivery_id},snapshot,false);
      }else{record.item=item;record.snapshot=snapshot;render(record);}
      if(!sameSource())return;record.manualControl=control;await play(record);
    }catch(_){if(sameSource())control.options.notice('Visitor speech is unavailable for this message. You can read it and reply in text.');}
    finally{control.loading=false;updateControls();}
  }
  function attachOwnerCard(host,options){
    if(!host||attached.has(host)||!cfg.endpoint)return;attached.add(host);
    const row=node('div','profile-speech-card-controls'),playButton=node('button','','Play visitor message'),stopButton=node('button','','Stop'),speak=node('button','','Speak reply');
    for(const button of [playButton,stopButton,speak])button.type='button';speak.dataset.speechAction='draft';playButton.dataset.speechAction='visitor-play';row.append(playButton,stopButton,speak);host.appendChild(row);
    const control={host,options,speak,play:playButton,stop:stopButton};controls.add(control);
    playButton.addEventListener('click',()=>{const record=sourceRecord(control.options.source());if(record&&player?.record===record)playFromControl(record);else void playSelected(control);});stopButton.addEventListener('click',()=>stop());speak.addEventListener('click',()=>armed?.control===control?cancelArm('Spoken draft cancelled.'):arm(control));updateControls();
  }
  function targetCurrent(target){const next=doorbell().speechTarget?.(target?.turn_id);return next&&next.draft_empty&&['turn_id','revision','public_conversation_id','visitor_message_id','chat_conversation_id','chat_agent_id','chat_message_id','delivery_id','tab_id'].every(key=>target[key]===next[key]);}
  function cancelArm(message='Spoken draft cancelled. Your private Chat draft is unchanged.'){
    const prior=armed;if(!prior)return;armed=null;clearTimeout(prior.timer);prior.candidate=null;window.VP3ConversationControls?.cancelResponseWindow?.();prior.control.options.notice(message);updateControls();
  }
  function arm(control){
    const target=control.options.target(),proof=window.STONEFELLOW_CHAT_VOICE,voice=window.VP3ConversationControls;
    if(!target?.draft_empty||!privateInput||!privateForm||privateInput.value.trim()||!current(capture())||player||mediaBusy()||!proof||!nonnegative(proof.submits)||!nonnegative(proof.results)||!nonnegative(proof.finals)||typeof voice?.responseWindow!=='function'||voice.isEnabled?.()!==false||voice.isBusy?.()!==false){control.options.notice('Leave the private Chat draft empty and finish other voice activity before speaking a visitor reply. You can type directly in this visitor card.');return;}
    cancelArm();const snapshot=capture();armed={target,control,snapshot,submits:proof.submits,results:proof.results,finals:proof.finals,seenSubmits:proof.submits,lastVoiceText:'',candidate:null,timer:0};
    if(!voice.responseWindow()){cancelArm('Voice capture is unavailable. Type your reply in this visitor card.');return;}
    armed.timer=setTimeout(()=>cancelArm('The spoken draft window ended. You can type your reply in this visitor card.'),17000);
    control.options.notice('Speak a reply for '+target.visitor_label+'. It will appear here for review before you send it.');updateControls();
  }
  function ordinaryInput(){if(player)stop('Visitor speech paused while you use private Chat.');if(armed)cancelArm('Spoken visitor draft cancelled because you edited private Chat. Your private draft remains private.');}
  privateInput?.addEventListener('beforeinput',ordinaryInput,true);privateInput?.addEventListener('paste',ordinaryInput,true);privateInput?.addEventListener('drop',ordinaryInput,true);
  privateInput?.addEventListener('input',event=>{
    const selected=armed;if(!selected)return;const proof=window.STONEFELLOW_CHAT_VOICE,value=String(privateInput.value||'');
    const advanced=proof&&(proof.results>selected.results||proof.finals>selected.finals||proof.submits>selected.seenSubmits);
    // Synthetic input alone is not voice evidence. Only a newly advanced live
    // voice proof may update the empty borrowed composer; every other edit
    // cancels before the canonical voice path can prepend a private draft.
    if(event.isTrusted||!advanced||value!==String(proof.lastTranscript||'')){ordinaryInput();return;}
    selected.results=proof.results;selected.finals=proof.finals;selected.seenSubmits=proof.submits;selected.lastVoiceText=value;
  },true);
  window.addEventListener('stonefellow:chat-voice',event=>{
    const type=event.detail?.type;
    if(player&&(['INTERRUPT','TRANSCRIPT_SUBMIT','STOP_CONTROL','STOP_LISTENING_CONTROL','BARGE_SUBMIT','VOICE_API_START','VOICE_API_REQUEST'].includes(type)||(type==='SPEECH_STARTED'&&!player.emittingEcho)))stop('Visitor speech paused for your voice request.');
    if(!armed)return;const selected=armed,item=event.detail,proof=window.STONEFELLOW_CHAT_VOICE;
    if(item?.type!=='TRANSCRIPT_SUBMIT')return;
    const text=item.detail?.text,count=item.detail?.count;
    if(!current(selected.snapshot)||!targetCurrent(selected.target)||window.VP3ProfileCallsPa3c?.speechBusy?.()===true||typeof text!=='string'||!text.trim()||text.length>4000||count!==selected.submits+1||proof?.submits!==count||window.STONEFELLOW_CHAT_CONTINUITY?.pendingVoiceSubmit?.()!==true||privateInput.value!==text||selected.lastVoiceText!==text){cancelArm('The speech source could not be verified. Review your private draft or type in the visitor card.');return;}
    selected.candidate={text,count};queueMicrotask(()=>{if(armed===selected)selected.candidate=null;});
  });
  document.addEventListener('submit',event=>{
    if(event.target===privateForm&&player)stop('Visitor speech paused for your private Chat request.');
    const selected=armed;if(event.target!==privateForm||!selected)return;if(!selected.candidate){cancelArm();return;}
    const candidate=selected.candidate,proof=window.STONEFELLOW_CHAT_VOICE;
    if(!event.cancelable||!current(selected.snapshot)||!targetCurrent(selected.target)||proof?.submits!==candidate.count||window.STONEFELLOW_CHAT_CONTINUITY?.pendingVoiceSubmit?.()!==true||privateInput.value!==candidate.text){cancelArm();return;}
    event.preventDefault();event.stopImmediatePropagation();const routeId=random();selected.candidate=null;
    window.dispatchEvent(new CustomEvent('vp3:chat-route',{detail:{id:routeId,state:'started',voiceTurn:true,message:''}}));
    const staged=doorbell().stageVoiceDraft?.(selected.target,candidate.text)===true;
    armed=null;clearTimeout(selected.timer);
    if(staged&&privateInput.value===candidate.text){privateInput.value='';privateInput.dispatchEvent(new Event('input',{bubbles:true}));}
    selected.control.options.notice(staged?'Spoken reply drafted for this visitor. Review it, then choose Send to visitor.':'This visitor changed. Your recognized text remains in private Chat; nothing was sent to the visitor.');
    window.dispatchEvent(new CustomEvent('vp3:chat-route',{detail:{id:routeId,state:'review',voiceTurn:true,message:''}}));
    window.VP3ConversationControls?.cancelResponseWindow?.();updateControls();
  },true);
  function invalidate(reason='Visitor speech stopped because the current Chat changed.'){
    stop(reason);cancelArm(reason);epoch++;for(const record of items.values()){record.item.can_play=false;render(record);}updateControls();
  }
  window.VP3ProfileSpeechPa4a={attachOwnerCard,refresh,stop:()=>stop(),cancelDraft:()=>cancelArm()};
  window.dispatchEvent(new CustomEvent('vp3:profile-speech-ready'));
  for(const event of ['stonefellow:chat-context-changing','offline','pagehide'])window.addEventListener(event,()=>{invalidate();if(event==='pagehide')alive=false;});
  document.addEventListener('visibilitychange',()=>{if(document.hidden)invalidate('Visitor speech stopped while Chat was hidden.');else void refresh();});
  window.addEventListener('vp3:chat-canvas-view-changed',()=>{if(!context().active)invalidate();else void refresh();});
  for(const event of ['vp3:chat-context-ready','vp3:profile-speech-message','online'])window.addEventListener(event,()=>void refresh());
  window.addEventListener('vp3:profile-call-state',event=>{if(event.detail?.busy){stop('Visitor speech paused for the direct call.');cancelArm('Spoken draft cancelled for the direct call.');}});
  window.addEventListener('stonefellow:agent-voice',event=>{voiceMaster=event.detail?.enabled!==false;if(!voiceMaster){stop('Visitor speech is off.');cancelArm('Agent Voice is off. Type your reply in the visitor card.');}});
  document.addEventListener('stonefellow:chat-turn',event=>{if(event.detail?.state==='pending')stop('Visitor speech paused for your private Chat request.');});
  window.addEventListener('stonefellow:agent-stop',()=>stop('Visitor speech stopped.'));
  window.addEventListener('stonefellow:voice-lease-lost',()=>{stop('Visitor speech paused because voice capture changed.');cancelArm();});
  window.addEventListener('stonefellow:agent-proactive-speech',event=>{if(event.detail?.source==='profile-speech-pa4a')return;otherSpeech=event.detail?.state==='start';if(otherSpeech)stop('Visitor speech paused for other audio.');});
  document.addEventListener('play',event=>{if(event.target!==player?.audio){stop('Visitor speech paused for other media.');cancelArm('Spoken draft cancelled for other media.');}},true);
  window.addEventListener('storage',()=>{if(player&&mediaBusy())stop('Visitor speech paused for voice capture.');});
  setInterval(()=>{if(!alive)return;if(armed&&(!current(armed.snapshot)||!targetCurrent(armed.target)||window.VP3ProfileCallsPa3c?.speechBusy?.()===true))cancelArm();updateControls();autoPlay();},500);
  setInterval(()=>void refresh(),3000);void refresh();
})();
