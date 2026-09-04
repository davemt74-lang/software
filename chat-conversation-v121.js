(() => {
  'use strict';

  const BUILD='conversation-phase1-v121-20260826';
  const cfg=window.STONEFELLOW_CHAT||{};
  const boot=window.STONEFELLOW_CHAT_V121_BOOT||{};
  const form=document.getElementById('chatForm');
  const input=document.getElementById('chatInput');
  const send=document.getElementById('sendChatButton');
  const status=document.getElementById('chatVoiceStatus');
  const thread=document.getElementById('chatThread');
  const originalWelcome=document.getElementById('chatWelcome');
  const button=boot.button||document.getElementById('chatVoiceButtonLegacyDormant')||document.getElementById('chatVoiceButton');
  const Voice=window.StonefellowConversationVoiceV121||window.StonefellowConversationVoiceV120;
  if(!form||!input||!send||!button||!Voice)return;

  if(button.id!=='chatVoiceButton')button.id='chatVoiceButton';
  button.disabled=false;button.title='Start or stop voice conversation with Stonefellow';

  const previousFetch=window.fetch;
  const nativeFetch=previousFetch.bind(window);
  const chatUrl=(()=>{try{return new URL(String(cfg.endpoint||''),location.href);}catch(error){return null;}})();
  const streamUrl=String(cfg.voiceStreamEndpoint||'/api/chat-stream-v121.php');
  let voiceTurnNext=false;
  let adapterBusy=false;
  let activeTurn=null;
  let activeStream=null;
  let lastConversationId=Number(cfg.initialConversationId||0);

  const proof=window.STONEFELLOW_CHAT_CONVERSATION_V121={
    build:BUILD,loaded:true,legacyVoiceDormant:!!boot.legacyDormant,streamTurns:0,
    streamedDeltas:0,streamAborts:0,interruptQueueWaits:0,lastConversationId,lastTraceId:''
  };

  function setStatus(text='',state=''){
    if(!status)return;
    status.hidden=!text;status.textContent=text;status.dataset.state=state;
  }
  function syncVoiceButton(on){
    button.classList.toggle('active',!!on);button.setAttribute('aria-pressed',on?'true':'false');
    button.setAttribute('aria-label',on?'Stop voice conversation':'Start voice conversation');
  }
  function setAgentState(state,text=''){
    button.dataset.agentState=state;
    button.classList.toggle('ai-listening',state==='listening');
    button.classList.toggle('ai-thinking',state==='processing');
    button.classList.toggle('ai-responding',state==='speaking');
    setStatus(text||(state==='listening'?'Listening…':state==='processing'?'Thinking…':state==='speaking'?'Stonefellow is responding…':voice?.isEnabled?.()?'Voice conversation on':''),state);
  }

  function activeConversationId(){
    const active=document.querySelector('.chat-history-item.active[data-conversation-id]');
    const id=Number(active?.dataset.conversationId||lastConversationId||cfg.initialConversationId||0);
    return Math.max(0,id);
  }
  function deferred(){let resolve,reject;const promise=new Promise((res,rej)=>{resolve=res;reject=rej;});return {promise,resolve,reject};}
  function runtimeId(prefix='turn'){
    let id='';try{id=crypto.randomUUID();}catch(error){id=`${Date.now().toString(36)}-${Math.random().toString(36).slice(2,12)}`;}
    return `${prefix}-${id}`;
  }

  async function waitForComposer(){
    let waited=false;const deadline=Date.now()+30000;
    while(adapterBusy||send.disabled){
      if(Date.now()>deadline)throw new Error('The previous voice turn is still finishing.');
      waited=true;await new Promise(resolve=>setTimeout(resolve,40));
    }
    if(waited)proof.interruptQueueWaits+=1;
  }

  async function submitVoiceTranscript(text){
    const transcript=String(text||'').trim();if(!transcript)return;
    await waitForComposer();
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
    isBusy:()=>adapterBusy||send.disabled,
    onTranscript:submitVoiceTranscript,
    onInterrupt:()=>{
      if(activeStream&&!activeStream.controller.signal.aborted){
        activeStream.interrupted=true;proof.streamAborts+=1;activeStream.controller.abort();
      }
    },
    onState:setAgentState,onVoiceChange:syncVoiceButton,
    onError:error=>{if(error?.message)setStatus(error.message,'error');}
  });

  function isChatSend(inputArg,init,payload){
    if(!chatUrl||String(init?.method||'GET').toUpperCase()!=='POST'||payload?.action!=='send')return false;
    try{
      const target=new URL(typeof inputArg==='string'?inputArg:inputArg?.url||'',location.href);
      return target.origin===chatUrl.origin&&target.pathname===chatUrl.pathname;
    }catch(error){return false;}
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
        stream.userMessageId=Number(event.user_message_id||0);stream.assistantMessageId=Number(event.assistant_message_id||0);
        lastConversationId=stream.conversationId||lastConversationId;proof.lastConversationId=lastConversationId;
      }else if(event.type==='delta'){
        const delta=String(event.delta||'');if(!delta)return;
        stream.text+=delta;
        if(!speech.current)speech.current=voice.createSpeechStream();
        speech.current?.push(delta);proof.streamedDeltas+=1;
      }else if(event.type==='done'){
        doneData=event.data||null;
        if(doneData?.trace_id){stream.traceId=String(doneData.trace_id);proof.lastTraceId=stream.traceId;}
        if(doneData?.conversation_id){lastConversationId=Number(doneData.conversation_id);proof.lastConversationId=lastConversationId;}
      }else if(event.type==='error')throw new Error(String(event.error||'Voice conversation failed.'));
    };
    while(true){
      const {value,done}=await reader.read();
      if(value)buffer+=decoder.decode(value,{stream:!done});
      let newline;
      while((newline=buffer.indexOf('\n'))>=0){const line=buffer.slice(0,newline);buffer=buffer.slice(newline+1);processLine(line);}
      if(done)break;
    }
    buffer+=decoder.decode();if(buffer.trim())processLine(buffer);
    return doneData;
  }

  function interruptedData(stream){
    const answer=String(stream.text||'').trim()||'Interrupted.';
    return {
      ok:true,conversation_id:Number(stream.conversationId||lastConversationId||0),
      user_message_id:Number(stream.userMessageId||0),assistant_message_id:Number(stream.assistantMessageId||0),
      answer,sources:[],media:[],stem_media:[],actions:[],playlist_title:'',input_mode:'voice',stream_partial:true,interrupted:true,trace_id:String(stream.traceId||'')
    };
  }

  const routedFetch=async function stonefellowChatFetch(inputArg,init={}){
    let payload=null;if(typeof init?.body==='string'){try{payload=JSON.parse(init.body);}catch(error){}}
    if(!voiceTurnNext||!isChatSend(inputArg,init,payload))return nativeFetch(inputArg,init);

    voiceTurnNext=false;proof.streamTurns+=1;
    const turn=activeTurn;const speech={current:null};
    const traceId=runtimeId('voice');const idempotencyKey=runtimeId('voice-turn');
    const stream={controller:new AbortController(),serverStarted:false,interrupted:false,text:'',conversationId:Number(payload?.conversation_id||0),userMessageId:0,assistantMessageId:0,traceId,idempotencyKey};
    activeStream=stream;proof.lastTraceId=traceId;payload={...payload,input_mode:'voice',idempotency_key:idempotencyKey};
    try{
      const response=await nativeFetch(streamUrl,{
        method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json','Accept':'application/x-ndjson','X-Stonefellow-Trace':traceId},
        body:JSON.stringify(payload),signal:stream.controller.signal
      });
      const responseTrace=response.headers.get('X-Stonefellow-Trace');if(responseTrace){stream.traceId=responseTrace;proof.lastTraceId=responseTrace;}
      if(!response.ok||!response.body)throw new Error(`Voice stream HTTP ${response.status}`);
      const doneData=await parseStream(response,stream,speech);
      if(!doneData)throw new Error('Voice stream ended without a final response.');
      speech.current?.end();adapterBusy=false;activeStream=null;activeTurn=null;turn?.resolve(doneData);
      return new Response(JSON.stringify(doneData),{status:200,headers:{'Content-Type':'application/json; charset=UTF-8','Cache-Control':'no-store'}});
    }catch(error){
      speech.current?.stop();
      if(stream.interrupted&&stream.serverStarted){
        const data=interruptedData(stream);
        adapterBusy=false;activeStream=null;activeTurn=null;turn?.resolve(data);
        return new Response(JSON.stringify(data),{status:200,headers:{'Content-Type':'application/json; charset=UTF-8','Cache-Control':'no-store'}});
      }
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
    const token=String(intro.token||'');const storageKey=`stonefellow:agent-intro:${Number(cfg.userId||0)}`;
    try{if(token&&localStorage.getItem(storageKey)===token){if(originalWelcome)originalWelcome.hidden=true;return;}}catch(error){}
    const {display,spoken,updates}=introTexts(intro);if(originalWelcome)originalWelcome.hidden=true;
    const message=document.createElement('div');message.className='message assistant';
    message.innerHTML='<div class="message-avatar" aria-hidden="true">S</div><div class="message-body"><div class="message-role">Stonefellow</div><div class="message-text"></div></div>';
    message.querySelector('.message-text').textContent=display;
    const actionable=updates.filter(update=>String(update?.target_url||'').trim()).slice(0,4);
    if(actionable.length){
      const actions=document.createElement('div');actions.className='message-actions';
      actionable.forEach(update=>{const link=document.createElement('a');link.href=String(update.target_url);link.textContent=`Open ${String(update.title||'update').slice(0,80)}`;actions.appendChild(link);});
      message.querySelector('.message-body').appendChild(actions);
    }
    thread?.appendChild(message);if(thread)thread.scrollTop=thread.scrollHeight;
    if(token){try{localStorage.setItem(storageKey,token);}catch(error){}}
    if(voice.isEnabled()&&spoken)setTimeout(()=>void voice.speak(spoken),120);
  }

  button.addEventListener('click',()=>voice.toggle());syncVoiceButton(voice.isEnabled());voice.start();

  const sendObserver=new MutationObserver(()=>{
    if(send.disabled&&!adapterBusy)voice.stopListening(false);
    else if(!send.disabled&&!adapterBusy)voice.resume(100);
  });
  sendObserver.observe(send,{attributes:true,attributeFilter:['disabled']});

  document.addEventListener('click',event=>{
    const link=event.target.closest?.('a[href*="/admin/stems.php"],a[href*="/video-editor.php"]');if(!link)return;
    try{
      const target=new URL(link.href,location.href);if(target.origin!==location.origin)return;
      if(voice.isEnabled())target.searchParams.set('voice','1');
      const cid=activeConversationId();if(cid>0)target.searchParams.set('conversation_id',String(cid));link.href=target.toString();
    }catch(error){}
  },true);

  window.STONEFELLOW_CHAT_CONTINUITY_V87={isVoice:()=>voice.isEnabled(),conversationId:activeConversationId};
  window.STONEFELLOW_CHAT_CONTINUITY_V121=window.STONEFELLOW_CHAT_CONTINUITY_V87;

  setTimeout(presentIntro,220);
  window.addEventListener('pagehide',()=>{
    sendObserver.disconnect();activeStream?.controller.abort();voice.destroy();if(window.fetch===routedFetch)window.fetch=previousFetch;
  },{once:true});
})();