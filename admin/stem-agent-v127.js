(() => {
  'use strict';
  const BUILD='stem-agent-truth-v127-20260826';
  const cfg=window.STONEFELLOW_STUDIO_AGENT;if(!cfg?.endpoint||!cfg.trackId)return;
  cfg.endpoint=String(cfg.endpoint).replace(/stem-agent-v91\.php(?=\?|$)/,'stem-agent-v105.php');
  const Voice=window.StonefellowConversationVoiceV120;
  const VALID_STATUS=new Set(['success','failed','unsupported','no_change','cancelled']);
  const trigger=document.querySelector('.studio-agent-trigger')||(()=>{const b=document.createElement('button');b.type='button';b.className='daw-header-button studio-agent-trigger';b.textContent='AI';b.title='Open Stonefellow · QQQ';(document.querySelector('.daw-canvas-actions')||document.body).appendChild(b);return b;})();
  const key=`stonefellow:studio-agent:v91:${cfg.userId}:${cfg.trackId}`;
  const initialVoice=!!cfg.voiceMode||!!Voice?.readShared?.(cfg.userId);
  let sessionId=Number(localStorage.getItem(key)||0),busy=false;
  trigger.setAttribute('aria-expanded','false');
  const panel=document.createElement('aside');panel.className='editor-agent-panel studio-agent-panel';panel.hidden=true;panel.innerHTML=`<div class="editor-agent-history" data-agent-history></div><footer class="editor-agent-footer"><form class="editor-agent-composer" data-agent-form><textarea rows="1" maxlength="8000" placeholder="Message Stonefellow…" aria-label="Message Stonefellow"></textarea><button class="editor-agent-voice${initialVoice?' active':''}" type="button" data-agent-voice aria-label="${initialVoice?'Stop':'Start'} voice conversation" aria-pressed="${initialVoice?'true':'false'}">◉</button><button class="editor-agent-send" type="submit" aria-label="Send message">↑</button></form><small class="editor-agent-status" data-agent-status>${initialVoice?'Voice conversation active':'Every Studio edit is archived to Agent Brain'}</small></footer>`;document.body.appendChild(panel);
  const history=panel.querySelector('[data-agent-history]'),form=panel.querySelector('[data-agent-form]'),input=form.querySelector('textarea'),voiceButton=panel.querySelector('[data-agent-voice]'),status=panel.querySelector('[data-agent-status]');
  const esc=v=>String(v??'').replaceAll('&','&amp;').replaceAll('<','&lt;').replaceAll('>','&gt;').replaceAll('"','&quot;').replaceAll("'",'&#039;');
  const bridge=()=>window.StonefellowStemStudioV91||window.StonefellowStemStudioV90||null;
  const runtime=()=>window.STONEFELLOW_STUDIO_RUNTIME_V87||null;

  window.STONEFELLOW_STEM_AGENT_V127={build:BUILD,validStatuses:[...VALID_STATUS]};

  function setAgentState(state,text=''){
    trigger.classList.remove('ai-listening','ai-busy');
    if(state==='listening')trigger.classList.add('ai-listening');
    if(state==='processing'||state==='speaking')trigger.classList.add('ai-busy');
    trigger.dataset.agentState=state;
    trigger.setAttribute('aria-label',state==='listening'?'AI · listening':state==='processing'?'AI · thinking':state==='speaking'?'AI · responding':'AI');
    status.textContent=text||(state==='listening'?'Listening…':state==='processing'?'Thinking and editing…':state==='speaking'?'Stonefellow is responding…':voice?.isEnabled?.()?'Voice ready':'Every Studio edit is archived to Agent Brain');
  }
  function syncVoiceButton(on){voiceButton.setAttribute('aria-pressed',on?'true':'false');voiceButton.classList.toggle('active',on);voiceButton.setAttribute('aria-label',on?'Stop voice conversation':'Start voice conversation');}
  function line(role,text,state=''){const el=document.createElement('article');el.className=`editor-agent-line ${role}`;el.innerHTML=`<small>${role==='user'?'You':'Stonefellow'}${state?` · ${esc(state)}`:''}</small><div>${esc(text)}</div>`;history.appendChild(el);history.scrollTop=history.scrollHeight;return el;}
  async function api(payload){const r=await fetch(cfg.endpoint,{method:'POST',headers:{'Content-Type':'application/json'},credentials:'same-origin',body:JSON.stringify({csrf_token:cfg.csrf,track_id:Number(cfg.trackId),session_id:sessionId,conversation_id:Number(cfg.conversationId||0),...payload})});const raw=await r.text();let d=null;try{d=JSON.parse(raw);}catch(e){throw new Error(`Studio Agent returned an invalid response (${r.status}).`);}if(!r.ok||!d?.ok)throw new Error(d?.error||'Studio Agent request failed.');if(d.session_id){sessionId=Number(d.session_id);localStorage.setItem(key,String(sessionId));}if(d.conversation_id){cfg.conversationId=Number(d.conversation_id);}return d;}

  function strictResult(value,type='command'){
    if(!value||typeof value!=='object'||Array.isArray(value))return{status:'unsupported',result:`${type} did not return a verifiable Studio result`,verified:false};
    const status=String(value.status||'');
    if(!VALID_STATUS.has(status))return{status:'failed',result:`${type} returned an invalid Studio result status`,verified:false};
    if(status==='success'&&!value.verified)return{status:'failed',result:`${value.result||type} was not verified by Studio state`,verified:false};
    return{...value,status,result:String(value.result||type)};
  }

  async function executeV105(command){
    const type=String(command?.type||'');const b=bridge();
    if(type==='v105_seek_relative'){
      const r=runtime();if(!b?.executeAgentCommand||!r?.getPosition)return{status:'unsupported',result:'Timeline seek is unavailable',verified:false};
      return strictResult(await b.executeAgentCommand({type:'seek',time:Math.max(0,Number(r.getPosition()||0)+Number(command.seconds||0))}),'seek');
    }
    if(type==='v105_metronome_volume'){
      if(!b?.executeAgentCommand)return{status:'unsupported',result:'Metronome volume bridge is unavailable',verified:false};
      return strictResult(await b.executeAgentCommand(command),type);
    }
    if(type==='v105_marker_here'){
      const r=runtime();if(!b?.executeAgentCommand||!r?.getPosition)return{status:'unsupported',result:'Marker command is unavailable',verified:false};
      return strictResult(await b.executeAgentCommand({type:'marker_add',time:Math.max(0,Number(r.getPosition()||0)),label:String(command.label||'Voice marker')}),'marker_add');
    }
    if(type==='v105_open_last_note'){
      const notes=Array.isArray(window.STONEFELLOW_STEM_STUDIO?.regionNotes)?window.STONEFELLOW_STEM_STUDIO.regionNotes:[];
      const latest=notes.length?notes[notes.length-1]:null;if(!latest?.id)return{status:'no_change',result:'No production note is available for this track',verified:true};
      const url=new URL(window.location.href);url.searchParams.set('track',String(cfg.trackId));url.searchParams.set('region_note',String(latest.id));window.location.assign(url.toString());
      return{status:'success',result:`Opening production note ${latest.id}`,verified:true,verification:'navigation'};
    }
    return null;
  }

  async function execute(command){
    if(command?.requires_confirmation&&!confirm(`Stonefellow wants to ${String(command.type||'perform this action')}. Continue?`))return{status:'cancelled',result:'User cancelled',verified:false};
    const local=await executeV105(command);if(local)return strictResult(local,String(command?.type||'command'));
    const b=bridge();if(!b?.executeAgentCommand)return{status:'unsupported',result:'Studio command bridge is unavailable',verified:false};
    try{return strictResult(await b.executeAgentCommand(command),String(command?.type||'command'));}
    catch(error){return{status:'failed',result:error?.message||'Studio command threw while executing',verified:false};}
  }

  function overallStatus(results){
    if(results.some(r=>r.status==='failed'))return'failed';
    if(results.some(r=>r.status==='unsupported'))return'unsupported';
    if(results.some(r=>r.status==='cancelled'))return'cancelled';
    if(results.some(r=>r.status==='success'))return'success';
    if(results.length&&results.every(r=>r.status==='no_change'))return'no_change';
    return'success';
  }
  function finalReply(plannerAnswer,results,ledger){
    if(!results.length)return String(plannerAnswer||'').trim()||'I’m here.';
    const status=overallStatus(results);const details=results.map(r=>r.result).filter(Boolean).join('; ');
    const changes=Number(ledger?.changes?.length||0);
    if(status==='failed')return `I couldn’t complete that Studio action. ${details}`.trim();
    if(status==='unsupported')return `I can’t safely execute all of that in Stem Studio yet. ${details}`.trim();
    if(status==='cancelled')return 'Okay. I did not make that Studio change.';
    if(status==='no_change')return `No change was needed. ${details}`.trim();
    return `Done. I verified the Studio state${changes?` and logged ${changes} exact change${changes===1?'':'s'}`:''}. ${details}`.trim();
  }

  async function sendMessage(message,inputMode='text'){
    const msg=String(message||'').trim();if(!msg||busy)return;
    voice?.stopListening(false);line('user',msg);busy=true;setAgentState('processing','Thinking…');const b=bridge();
    try{
      const d=await api({action:'send',message:msg,input_mode:inputMode,state:b?.getAgentState?.()||{}});
      const reply=line('assistant',d.commands?.length?'Working on it…':(d.answer||''));const results=[];
      b?.beginAgentEdit?.({request:msg,provider:d.provider||'',model:d.model||'',conversationId:Number(cfg.conversationId||0)});
      for(const command of d.commands||[])results.push(await execute(command));
      const ledger=await b?.endAgentEdit?.({request:msg,provider:d.provider||'',model:d.model||'',conversationId:Number(cfg.conversationId||0)});
      const state=overallStatus(results);const responseText=finalReply(d.answer||'',results,ledger);
      const replyText=reply.querySelector('div');if(replyText)replyText.textContent=responseText;
      reply.querySelector('small').textContent+=` · ${state}`;
      const historyStatus=state==='failed'||state==='unsupported'?'failed':state==='cancelled'?'cancelled':'success';
      const resultText=results.map(r=>`[${r.status}] ${r.result}`).filter(Boolean).join('; ')+(ledger?.changes?.length?` · ${ledger.changes.length} exact state changes logged`:'');
      await api({action:'result',history_id:Number(d.history_id||0),status:historyStatus,result:resultText});
      busy=false;
      if(voice?.isEnabled())void voice.speak(responseText);else setAgentState('idle');
    }catch(error){
      b?.cancelAgentEdit?.();busy=false;line('assistant',error.message||'Studio Agent failed.','error');
      if(voice?.isEnabled())voice.resume(180);else setAgentState('idle');
    }finally{if(!panel.hidden)input.focus();}
  }

  const voice=Voice?.create?.({
    userId:Number(cfg.userId||0),source:'stem-studio',initialEnabled:initialVoice,
    agentEndpoint:cfg.endpoint,csrf:cfg.csrf,isBusy:()=>busy,onTranscript:text=>sendMessage(text,'voice'),
    onState:setAgentState,onVoiceChange:syncVoiceButton,onError:error=>{if(error?.message)line('assistant',error.message,'error');}
  })||null;

  window.STONEFELLOW_CHAT_CONTINUITY_V87={isVoice:()=>Boolean(voice?.isEnabled?.()),conversationId:()=>Number(cfg.conversationId||0)};

  async function load(){try{const d=await api({action:'history'});history.innerHTML='';(d.history||[]).forEach(h=>line(h.role==='user'?'user':'assistant',h.message_text||'',h.status||''));if(!d.history?.length)line('assistant','I can operate the current Stem Studio by voice or text. I’ll only say a Studio action worked after I can verify the resulting state.');}catch(e){line('assistant',e.message,'error');}}
  function openPanel(){panel.hidden=false;trigger.setAttribute('aria-expanded','true');load();setTimeout(()=>{input.focus();voice?.resume(40);},25);}
  function closePanel(){panel.hidden=true;trigger.setAttribute('aria-expanded','false');if(!voice?.isEnabled())setAgentState('idle');else if(busy||voice.isPreparing())setAgentState('processing');else if(voice.isSpeaking())setAgentState('speaking');else if(voice.isListening())setAgentState('listening');else voice.resume(40);}
  function togglePanel(){panel.hidden?openPanel():closePanel();}
  trigger.addEventListener('click',togglePanel);voiceButton.addEventListener('click',()=>voice?.toggle());
  form.addEventListener('submit',e=>{e.preventDefault();const msg=input.value.trim();input.value='';sendMessage(msg,'text');});
  let qTimes=[];document.addEventListener('keydown',e=>{if(e.key==='Escape'&&!panel.hidden){closePanel();return;}if(e.target.matches?.('input,textarea,select')||e.target.isContentEditable)return;if(e.key.toLowerCase()!=='q'){qTimes=[];return;}const now=Date.now();qTimes=qTimes.filter(t=>now-t<900);qTimes.push(now);if(qTimes.length>=3){qTimes=[];e.preventDefault();togglePanel();}});
  syncVoiceButton(initialVoice);if(voice)voice.start();else setAgentState('idle','Voice conversation is unavailable.');
})();