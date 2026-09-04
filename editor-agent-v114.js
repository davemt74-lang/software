(() => {
  'use strict';
  const cfg=window.STONEFELLOW_VIDEO_EDITOR||{},button=document.getElementById('videoAgentButton');
  if(!cfg.agentEndpoint||!button)return;
  const Voice=window.StonefellowConversationVoiceV120;
  const initialVoice=!!cfg.voiceMode||!!Voice?.readShared?.(cfg.userId);
  let conversationId=Number(cfg.conversationId||0),busy=false;
  const esc=v=>String(v??'').replaceAll('&','&amp;').replaceAll('<','&lt;').replaceAll('>','&gt;').replaceAll('"','&quot;').replaceAll("'",'&#039;');
  const panel=document.createElement('aside');panel.className='editor-agent-panel video-agent-panel';panel.hidden=true;panel.innerHTML=`<div class="editor-agent-history" data-agent-history></div><footer class="editor-agent-footer"><form class="editor-agent-composer" data-agent-form><textarea rows="1" maxlength="6000" placeholder="Message Stonefellow…" aria-label="Message Stonefellow"></textarea><button class="editor-agent-voice${initialVoice?' active':''}" type="button" data-agent-voice aria-label="${initialVoice?'Stop':'Start'} voice conversation" aria-pressed="${initialVoice?'true':'false'}">◉</button><button class="editor-agent-send" type="submit" aria-label="Send message">↑</button></form><small class="editor-agent-status" data-agent-status>${initialVoice?'Voice conversation active':'Edits are archived into Agent Brain'}</small></footer>`;document.body.appendChild(panel);
  const history=panel.querySelector('[data-agent-history]'),form=panel.querySelector('[data-agent-form]'),input=form.querySelector('textarea'),voiceButton=panel.querySelector('[data-agent-voice]'),status=panel.querySelector('[data-agent-status]');

  function setAgentState(state,text=''){
    button.classList.remove('ai-listening','ai-busy');
    if(state==='listening')button.classList.add('ai-listening');
    if(state==='processing'||state==='speaking')button.classList.add('ai-busy');
    button.dataset.agentState=state;
    button.setAttribute('aria-label',state==='listening'?'AI · listening':state==='processing'?'AI · thinking':state==='speaking'?'AI · responding':'AI');
    status.textContent=text||(state==='listening'?'Listening…':state==='processing'?'Thinking…':state==='speaking'?'Stonefellow is responding…':voice?.isEnabled?.()?'Voice ready':'Edits are archived into Agent Brain');
  }
  function syncVoiceButton(on){voiceButton.setAttribute('aria-pressed',on?'true':'false');voiceButton.classList.toggle('active',on);voiceButton.setAttribute('aria-label',on?'Stop voice conversation':'Start voice conversation');}
  function line(role,text,state=''){const el=document.createElement('article');el.className=`editor-agent-line ${role}`;el.innerHTML=`<small>${role==='user'?'You':'Stonefellow'}${state?` · ${esc(state)}`:''}</small><div>${esc(text)}</div>`;history.appendChild(el);history.scrollTop=history.scrollHeight;return el;}
  async function api(payload){const r=await fetch(cfg.agentEndpoint,{method:'POST',headers:{'Content-Type':'application/json'},credentials:'same-origin',body:JSON.stringify({csrf_token:cfg.csrf,...payload})});const raw=await r.text();let d=null;try{d=JSON.parse(raw);}catch(e){throw new Error(`Video Agent returned an invalid response (${r.status}).`);}if(!r.ok||!d?.ok)throw new Error(d?.error||'Agent request failed.');if(d.conversation_id){conversationId=Number(d.conversation_id);cfg.conversationId=conversationId;}return d;}
  async function load(){if(!conversationId){history.innerHTML='';line('assistant','I can edit this project with you and talk through the work while we go.');return;}try{const d=await api({action:'history',conversation_id:conversationId});history.innerHTML='';(d.history||[]).forEach(m=>line(m.role==='user'?'user':'assistant',m.message||''));}catch(e){line('assistant',e.message,'error');}}

  async function sendMessage(msg,inputMode='text'){
    const clean=String(msg||'').trim();if(busy||!clean)return;
    voice?.stopListening(false);line('user',clean);busy=true;setAgentState('processing','Thinking…');
    try{
      const bridge=window.StonefellowVideoEditorV90,state=bridge?.getState?.()||{};
      const d=await api({action:'send',conversation_id:conversationId,message:clean,input_mode:inputMode,state});
      const reply=line('assistant',d.answer||'');let result={changes:[]};const commands=Array.isArray(d.commands)?d.commands:[],destructive=commands.filter(c=>c?.requires_confirmation);
      let resultStatus='success';
      if(destructive.length&&!confirm(`Stonefellow wants to perform ${destructive.length} destructive Video Editor action${destructive.length===1?'':'s'}. Continue?`)){reply.querySelector('small').textContent+=' · cancelled';resultStatus='cancelled';}
      else if(commands.length&&bridge?.executeCommands){result=await bridge.executeCommands(commands,{request:clean,provider:d.provider||'',model:d.model||'',conversationId});reply.querySelector('small').textContent+=` · ${result.changes.length?'edited':'done'}`;}
      await api({action:'result',conversation_id:conversationId,project_id:Number(bridge?.getProjectId?.()||0),request_text:clean,status:resultStatus,change_count:Number(result.changes?.length||0),model:d.model||''});
      busy=false;
      if(voice?.isEnabled())void voice.speak(d.answer||'Done.');else setAgentState('idle');
    }catch(e){
      busy=false;line('assistant',e.message||'Video Agent failed.','error');
      if(voice?.isEnabled())voice.resume(180);else setAgentState('idle');
    }finally{if(!panel.hidden)input.focus();}
  }

  const voice=Voice?.create?.({
    userId:Number(cfg.userId||0),
    source:'video-editor',
    initialEnabled:initialVoice,
    agentEndpoint:cfg.agentEndpoint,
    csrf:cfg.csrf,
    isBusy:()=>busy,
    onTranscript:text=>sendMessage(text,'voice'),
    onState:setAgentState,
    onVoiceChange:syncVoiceButton,
    onError:error=>{if(error?.message)line('assistant',error.message,'error');}
  })||null;

  window.STONEFELLOW_CHAT_CONTINUITY_V87={
    isVoice:()=>Boolean(voice?.isEnabled?.()),
    conversationId:()=>Number(conversationId||0)
  };

  function open(){panel.hidden=false;button.setAttribute('aria-expanded','true');load();setTimeout(()=>{input.focus();voice?.resume(40);},30);}
  function close(){panel.hidden=true;button.setAttribute('aria-expanded','false');if(!voice?.isEnabled())setAgentState('idle');else if(busy||voice.isPreparing())setAgentState('processing');else if(voice.isSpeaking())setAgentState('speaking');else if(voice.isListening())setAgentState('listening');else voice.resume(40);}
  function toggle(){panel.hidden?open():close();}
  button.addEventListener('click',toggle);voiceButton.addEventListener('click',()=>voice?.toggle());form.addEventListener('submit',e=>{e.preventDefault();const msg=input.value.trim();input.value='';sendMessage(msg,'text');});
  let qTimes=[];document.addEventListener('keydown',e=>{if(e.key==='Escape'&&!panel.hidden){close();return;}if(e.target.matches?.('input,textarea,select')||e.target.isContentEditable)return;if(e.key.toLowerCase()!=='q'){qTimes=[];return;}const now=Date.now();qTimes=qTimes.filter(t=>now-t<900);qTimes.push(now);if(qTimes.length>=3){qTimes=[];e.preventDefault();toggle();}});
  window.addEventListener('stonefellow:agent-toggle',toggle);
  syncVoiceButton(initialVoice);if(voice)voice.start();else setAgentState('idle','Voice conversation is unavailable.');
})();
