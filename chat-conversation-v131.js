(() => {
  'use strict';

  const BUILD='conversation-integration-v131-20260826';
  const cfg=window.STONEFELLOW_CHAT||{};
  const boot=window.STONEFELLOW_CHAT_V131_BOOT||{};
  const form=document.getElementById('chatForm');
  const input=document.getElementById('chatInput');
  const send=document.getElementById('sendChatButton');
  const status=document.getElementById('chatVoiceStatus');
  const thread=document.getElementById('chatThread');
  const originalWelcome=document.getElementById('chatWelcome');
  const button=boot.button||document.getElementById('chatVoiceButtonLegacyDormant')||document.getElementById('chatVoiceButton');
  const Voice=window.StonefellowConversationVoiceV122;
  const AgentContext=window.StonefellowAgentContext||null;
  if(!form||!input||!send||!button||!Voice)return;

  if(button.id!=='chatVoiceButton')button.id='chatVoiceButton';
  button.disabled=false;
  button.title='Start or stop voice conversation with Stonefellow';

  const previousFetch=window.fetch;
  const nativeFetch=previousFetch.bind(window);
  const chatUrl=(()=>{try{return new URL(String(cfg.endpoint||''),location.href);}catch(error){return null;}})();
  const streamUrl=String(cfg.voiceStreamEndpoint||'/api/chat-stream-v121.php');
  let voiceTurnNext=false;
  let adapterBusy=false;
  let textBusy=false;
  let activeTurn=null;
  let activeStream=null;
  let lastConversationId=Number(cfg.initialConversationId||0);

  const proof=window.STONEFELLOW_CHAT_CONVERSATION_V131={
    build:BUILD,loaded:true,engine:String(Voice.build||''),legacyVoiceDormant:!!boot.legacyDormant,
    streamTurns:0,streamedDeltas:0,streamAborts:0,interruptQueueWaits:0,persistedBootRearms:0,
    lastConversationId,lastTraceId:'',contextAttached:0,introPresented:false,introSpoken:false,stateTransitions:[]
  };

  function setStatus(text='',state=''){
    if(!status)return;
    status.hidden=!text;
    status.textContent=text;
    status.dataset.state=state;
  }
  function syncVoiceButton(on){
    button.classList.toggle('active',!!on);
    button.setAttribute('aria-pressed',on?'true':'false');
    button.setAttribute('aria-label',on?'Stop voice conversation':'Start voice conversation');
  }
  function setAgentState(state,text=''){
    const enabled=Boolean(voice?.isEnabled?.());
    let visual='idle';
    let label='';
    if(state==='listening'&&enabled){visual='listening';label=String(text||'Listening…');}
    else if(state==='processing'){visual='processing';label=String(text||'Thinking…');}
    else if(state==='speaking'){visual='speaking';label=String(text||'Stonefellow is responding…');}
    else if(state==='error'){visual='error';label=String(text||'Voice input is unavailable.');}
    else if(enabled&&(state==='ready'||state==='recovering'||state==='interrupted')){visual='idle';label=String(text||'Voice ready');}
    document.body.dataset.stonefellowAgentState=visual;
    button.dataset.agentState=visual;
    button.classList.toggle('ai-listening',visual==='listening');
    button.classList.toggle('ai-thinking',visual==='processing');
    button.classList.toggle('ai-responding',visual==='speaking');
    setStatus(label,visual);
    proof.stateTransitions.push({state,visual,label,at:Date.now()});if(proof.stateTransitions.length>40)proof.stateTransitions.shift();
  }

  function activeConversationId(){
    const active=document.querySelector('.chat-history-item.active[data-conversation-id]');
    const id=Number(active?.dataset.conversationId||lastConversationId||cfg.initialConversationId||0);
    return Math.max(0,id);
  }
  function syncConversation(id){
    const next=Math.max(0,Number(id||0));if(next<1)return;
    lastConversationId=next;proof.lastConversationId=next;AgentContext?.setConversationId?.(next);
  }
  function deferred(){let resolve,reject;const promise=new Promise((res,rej)=>{resolve=res;reject=rej;});return {promise,resolve,reject};}
  function runtimeId(prefix='turn'){
    let id='';try{id=crypto.randomUUID();}catch(error){id=`${Date.now().toString(36)}-${Math.random().toString(36).slice(2,12)}`;}
    return `${prefix}-${id}`;
  }

  async function waitForConversationTurn(){
    let waited=false;const deadline=Date.now()+30000;
    while(adapterBusy||textBusy){
      if(Date.now()>deadline)throw new Error('The previous conversation turn is still finishing.');
      waited=true;await new Promise(resolve=>setTimeout(resolve,40));
    }
    if(waited)proof.interruptQueueWaits+=1;
  }

  async function submitVoiceTranscript(text){
    const transcript=String(text||'').trim();if(!transcript)return;
    await waitForConversationTurn();
    const turn=deferred();activeTurn=turn;adapterBusy=true;voiceTurnNext=true;
    input.value=transcript;input.dispatchEvent(new Event('input',{bubbles:true}));form.requestSubmit();
    const safety=setTimeout(()=>{
      if(activeTurn===turn){adapterBusy=false;voiceTurnNext=false;activeTurn=null;turn.reject(new Error('Voice turn did not start.'));}
    },120000);
    try{return await turn.promise;}finally{clearTimeout(safety);}
  }

  const voice=Voice.create({
    userId:Number(cfg.userId||0),source:'agent-chat',initialEnabled:Voice.readShared(Number(cfg.userId||0)),
    agentEndpoint:String(cfg.endpoint||'/api/chat.php'),csrf:String(cfg.csrf||''),
    isBusy:()=>adapterBusy||textBusy,
    onTranscript:submitVoiceTranscript,
    onInterrupt:()=>{if(activeStream&&!activeStream.controller.signal.aborted){activeStream.interrupted=true;proof.streamAborts+=1;activeStream.controller.abort();}},
    onState:setAgentState,onVoiceChange:syncVoiceButton,
    onError:error=>{if(error?.message)setStatus(error.message,'error');}
  });

  function isChatSend(inputArg,init,payload){
    if(!chatUrl||String(init?.method||'GET').toUpperCase()!=='POST'||payload?.action!=='send')return false;
    try{const target=new URL(typeof inputArg==='string'?inputArg:inputArg?.url||'',location.href);return target.origin===chatUrl.origin&&target.pathname===chatUrl.pathname;}
    catch(error){return false;}
  }
  async function attachContext(payload){
    if(!AgentContext)return payload;
    try{const context=await AgentContext.refresh(false);proof.contextAttached+=1;return {...payload,agent_context:context};}
    catch(error){return {...payload,agent_context:AgentContext.snapshot?.()||{}};}
  }

  async function parseStream(response,stream,speech){
    const reader=response.body?.getReader?.();if(!reader)throw new Error('Streaming response is unavailable.');
    const decoder=new TextDecoder();let buffer='';let doneData=null;
    const processLine=line=>{
      if(!line.trim())return;
      const event=JSON.parse(line);
      if(event.trace_id){stream.traceId=String(event.trace_id);proof.lastTraceId=stream.traceId;}
      if(event.type==='start'){
        stream.serverStarted=true;stream.conversationId=Number(event.conversation_id||stream.conversationId||0);
        stream.userMessageId=Number(event.user_message_id||0);stream.assistantMessageId=Number(event.assistant_message_id||0);syncConversation(stream.conversationId);
      }else if(event.type==='delta'){
        const delta=String(event.delta||'');if(!delta)return;stream.text+=delta;if(!speech.current)speech.current=voice.createSpeechStream();speech.current?.push(delta);proof.streamedDeltas+=1;
      }else if(event.type==='done'){
        doneData=event.data||null;if(doneData?.trace_id){stream.traceId=String(doneData.trace_id);proof.lastTraceId=stream.traceId;}if(doneData?.conversation_id)syncConversation(doneData.conversation_id);
      }else if(event.type==='error')throw new Error(String(event.error||'Voice conversation failed.'));
    };
    while(true){const {value,done}=await reader.read();if(value)buffer+=decoder.decode(value,{stream:!done});let newline;while((newline=buffer.indexOf('\n'))>=0){const line=buffer.slice(0,newline);buffer=buffer.slice(newline+1);processLine(line);}if(done)break;}
    buffer+=decoder.decode();if(buffer.trim())processLine(buffer);return doneData;
  }

  function interruptedData(stream){
    const answer=String(stream.text||'').trim()||'Interrupted.';
    return {ok:true,conversation_id:Number(stream.conversationId||lastConversationId||0),user_message_id:Number(stream.userMessageId||0),assistant_message_id:Number(stream.assistantMessageId||0),answer,sources:[],media:[],stem_media:[],actions:[],playlist_title:'',input_mode:'voice',stream_partial:true,interrupted:true,trace_id:String(stream.traceId||'')};
  }

  const routedFetch=async function stonefellowChatFetch(inputArg,init={}){
    let payload=null;if(typeof init?.body==='string'){try{payload=JSON.parse(init.body);}catch(error){}}
    const chatSend=isChatSend(inputArg,init,payload);
    if(chatSend&&payload){payload=await attachContext(payload);init={...init,body:JSON.stringify(payload)};}

    if(chatSend&&!voiceTurnNext){
      textBusy=true;
      if(voice.isEnabled()){voice.stopListening(false);setAgentState('processing','Thinking…');}
      try{return await nativeFetch(inputArg,init);}
      finally{textBusy=false;if(voice.isEnabled())voice.resume(120);}
    }
    if(!voiceTurnNext||!chatSend)return nativeFetch(inputArg,init);

    voiceTurnNext=false;proof.streamTurns+=1;
    const turn=activeTurn;const speech={current:null};
    const traceId=runtimeId('voice');const idempotencyKey=runtimeId('voice-turn');
    const stream={controller:new AbortController(),serverStarted:false,interrupted:false,text:'',conversationId:Number(payload?.conversation_id||0),userMessageId:0,assistantMessageId:0,traceId,idempotencyKey};
    activeStream=stream;proof.lastTraceId=traceId;payload={...payload,input_mode:'voice',idempotency_key:idempotencyKey};
    try{
      const response=await nativeFetch(streamUrl,{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json','Accept':'application/x-ndjson','X-Stonefellow-Trace':traceId},body:JSON.stringify(payload),signal:stream.controller.signal});
      const responseTrace=response.headers.get('X-Stonefellow-Trace');if(responseTrace){stream.traceId=responseTrace;proof.lastTraceId=responseTrace;}
      if(!response.ok||!response.body)throw new Error(`Voice stream HTTP ${response.status}`);
      const doneData=await parseStream(response,stream,speech);if(!doneData)throw new Error('Voice stream ended without a final response.');
      speech.current?.end();adapterBusy=false;activeStream=null;activeTurn=null;turn?.resolve(doneData);
      return new Response(JSON.stringify(doneData),{status:200,headers:{'Content-Type':'application/json; charset=UTF-8','Cache-Control':'no-store'}});
    }catch(error){
      speech.current?.stop();
      if(stream.interrupted&&stream.serverStarted){const data=interruptedData(stream);adapterBusy=false;activeStream=null;activeTurn=null;turn?.resolve(data);return new Response(JSON.stringify(data),{status:200,headers:{'Content-Type':'application/json; charset=UTF-8','Cache-Control':'no-store'}});}
      adapterBusy=false;activeStream=null;activeTurn=null;turn?.reject(error);
      return new Response(JSON.stringify({ok:false,error:String(error?.message||error||'Voice conversation failed.'),trace_id:String(stream.traceId||'')}),{status:502,headers:{'Content-Type':'application/json; charset=UTF-8','Cache-Control':'no-store'}});
    }
  };
  window.fetch=routedFetch;

  function introTexts(intro){
    const greeting=String(intro?.greeting||'').trim();const updates=Array.isArray(intro?.updates)?intro.updates:[];
    const display=updates.length?`${greeting}\n\nHere’s what changed:\n${updates.map(update=>`• ${String(update?.title||'Update')}${update?.body?` — ${String(update.body)}`:''}`).join('\n')}`:greeting;
    const spoken=updates.length?`${greeting} Here are the priorities I found. ${updates.map(update=>`${String(update?.title||'Update')}. ${String(update?.body||'')}`).join(' ')}`:greeting;
    return {display,spoken,updates};
  }

  function presentIntro(){
    const intro=boot.intro&&typeof boot.intro==='object'?boot.intro:null;if(!intro||!String(intro.greeting||'').trim())return;
    const {display,spoken,updates}=introTexts(intro);if(originalWelcome)originalWelcome.hidden=true;
    const message=document.createElement('div');message.className='message assistant';message.dataset.agentIntro='v131';
    message.innerHTML='<div class="message-avatar" aria-hidden="true">S</div><div class="message-body"><div class="message-role">Stonefellow</div><div class="message-text"></div></div>';message.querySelector('.message-text').textContent=display;
    const actionable=updates.filter(update=>String(update?.target_url||'').trim()).slice(0,4);
    if(actionable.length){const actions=document.createElement('div');actions.className='message-actions';actionable.forEach(update=>{const link=document.createElement('a');link.href=String(update.target_url);link.textContent=`Open ${String(update.title||'update').slice(0,80)}`;actions.appendChild(link);});message.querySelector('.message-body').appendChild(actions);}
    thread?.appendChild(message);if(thread)thread.scrollTop=thread.scrollHeight;proof.introPresented=true;
    if(voice.isEnabled()&&spoken){setTimeout(()=>{proof.introSpoken=true;void voice.speak(spoken);},120);}
  }

  async function waitForInitialConversationRestore(){
    const id=Number(cfg.initialConversationId||0);if(String(cfg.initialView||'chat')!=='chat'||id<1)return;
    const deadline=Date.now()+6000;
    while(Date.now()<deadline){const active=document.querySelector(`.chat-history-item.active[data-conversation-id="${id}"]`);const loading=thread?.querySelector('.typing');if(active&&!loading)return;await new Promise(resolve=>setTimeout(resolve,60));}
  }
  async function presentIntroWhenReady(){await waitForInitialConversationRestore();presentIntro();}

  function bootVoice(){
    syncVoiceButton(voice.isEnabled());
    if(!voice.isEnabled()){voice.start();return;}
    proof.persistedBootRearms+=1;
    voice.setEnabled(false,{persist:false});
    voice.setEnabled(true,{persist:false,immediate:true});
    try{window.dispatchEvent(new CustomEvent('stonefellow:chat-voice-boot',{detail:{build:BUILD,persisted:true,rearmed:true}}));}catch(error){}
  }

  button.addEventListener('click',()=>voice.toggle());bootVoice();

  document.addEventListener('click',event=>{
    const link=event.target.closest?.('a[href*="/admin/stems.php"],a[href*="/video-editor.php"]');if(!link)return;
    try{const target=new URL(link.href,location.href);if(target.origin!==location.origin)return;if(voice.isEnabled())target.searchParams.set('voice','1');const cid=activeConversationId();if(cid>0)target.searchParams.set('conversation_id',String(cid));link.href=target.toString();}catch(error){}
  },true);

  window.STONEFELLOW_CHAT_CONTINUITY_V131={isVoice:()=>voice.isEnabled(),conversationId:activeConversationId};
  syncConversation(lastConversationId);setTimeout(()=>void presentIntroWhenReady(),90);
  window.dispatchEvent(new CustomEvent('stonefellow:conversation-engine-ready',{detail:{build:BUILD,source:'agent-chat'}}));

  window.addEventListener('pagehide',()=>{activeStream?.controller.abort();voice.destroy();delete document.body.dataset.stonefellowAgentState;if(window.fetch===routedFetch)window.fetch=previousFetch;},{once:true});
})();