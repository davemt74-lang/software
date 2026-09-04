(() => {
  'use strict';
  const BUILD='conversation-integration-v131-20260826';
  const cfg=window.STONEFELLOW_STUDIO_AGENT;if(!cfg?.endpoint||!cfg.trackId)return;
  const Voice=window.StonefellowConversationVoiceV122;
  const AgentContext=window.StonefellowAgentContext||null;
  let EditorAgent=window.StonefellowEditorAgent||null;
  const editorAgentReady=EditorAgent?Promise.resolve(EditorAgent):import('/editor-agent.js?v=editor-agent-canonical-20260903').then(()=>window.StonefellowEditorAgent||null).catch(()=>null);
  if(!Number(cfg.conversationId||0)){const restored=Math.max(0,Number(AgentContext?.conversationId?.()||0));if(restored>0)cfg.conversationId=restored;}
  const VALID_STATUS=new Set(['success','failed','unsupported','no_change','cancelled','unverified']);
  const trigger=document.querySelector('.studio-agent-trigger')||(()=>{const b=document.createElement('button');b.type='button';b.className='daw-header-button studio-agent-trigger';b.textContent='AI';b.title='Open Stonefellow · QQQ';(document.querySelector('.daw-canvas-actions')||document.body).appendChild(b);return b;})();
  const key=`stonefellow:studio-agent:v91:${cfg.userId}:${cfg.trackId}`;
  const initialVoice=!!cfg.voiceMode||!!Voice?.readShared?.(cfg.userId);
  let sessionId=Number(localStorage.getItem(key)||0),busy=false;
  trigger.setAttribute('aria-expanded','false');
  const panel=document.createElement('aside');panel.className='editor-agent-panel studio-agent-panel';panel.hidden=true;panel.innerHTML=`<div class="editor-agent-history" data-agent-history></div><footer class="editor-agent-footer"><form class="editor-agent-composer" data-agent-form><textarea rows="1" maxlength="8000" placeholder="Message Stonefellow…" aria-label="Message Stonefellow"></textarea><button class="editor-agent-voice${initialVoice?' active':''}" type="button" data-agent-voice aria-label="${initialVoice?'Stop':'Start'} voice conversation" aria-pressed="${initialVoice?'true':'false'}">◉</button><button class="editor-agent-send" type="submit" aria-label="Send message">↑</button></form><small class="editor-agent-status" data-agent-status>${initialVoice?'Listening…':'Every Studio edit is archived to Agent Brain'}</small></footer>`;document.body.appendChild(panel);
  const history=panel.querySelector('[data-agent-history]'),form=panel.querySelector('[data-agent-form]'),input=form.querySelector('textarea'),voiceButton=panel.querySelector('[data-agent-voice]'),status=panel.querySelector('[data-agent-status]');
  const esc=v=>String(v??'').replaceAll('&','&amp;').replaceAll('<','&lt;').replaceAll('>','&gt;').replaceAll('"','&quot;').replaceAll("'",'&#039;');
  const bridge=()=>window.StonefellowStemStudioV91||null;
  const runtime=()=>window.STONEFELLOW_STUDIO_RUNTIME_V87||null;

  const proof=window.STONEFELLOW_STEM_AGENT_V131={build:BUILD,engine:String(Voice?.build||''),editorRegistry:String(EditorAgent?.build||''),validStatuses:[...VALID_STATUS],stateTransitions:[],capabilityCount:0};

  function setAgentState(state,text=''){
    const enabled=Boolean(voice?.isEnabled?.());
    const visual=(state==='ready'||state==='recovering'||state==='interrupted')&&enabled?'listening':state;
    trigger.classList.remove('ai-listening','ai-thinking','ai-responding','ai-busy');
    if(visual==='listening')trigger.classList.add('ai-listening');
    if(visual==='processing')trigger.classList.add('ai-thinking','ai-busy');
    if(visual==='speaking')trigger.classList.add('ai-responding','ai-busy');
    trigger.dataset.agentState=visual;
    trigger.setAttribute('aria-label',visual==='listening'?'AI · listening':visual==='processing'?'AI · thinking':visual==='speaking'?'AI · responding':'AI');
    status.textContent=visual==='listening'?'Listening…':visual==='processing'?'Thinking…':visual==='speaking'?'Stonefellow is responding…':text||(enabled?'Listening…':'Every Studio edit is archived to Agent Brain');
    proof.stateTransitions.push({state,visual,at:Date.now()});if(proof.stateTransitions.length>40)proof.stateTransitions.shift();
  }
  function syncVoiceButton(on){voiceButton.setAttribute('aria-pressed',on?'true':'false');voiceButton.classList.toggle('active',on);voiceButton.setAttribute('aria-label',on?'Stop voice conversation':'Start voice conversation');}
  function line(role,text,state=''){const el=document.createElement('article');el.className=`editor-agent-line ${role}`;el.innerHTML=`<small>${role==='user'?'You':'Stonefellow'}${state?` · ${esc(state)}`:''}</small><div>${esc(text)}</div>`;history.appendChild(el);history.scrollTop=history.scrollHeight;return el;}
  async function context(){try{return AgentContext?await AgentContext.refresh(false):{};}catch(error){return AgentContext?.snapshot?.()||{};}}
  async function api(payload){
    const agentContext=await context();
    const r=await fetch(cfg.endpoint,{method:'POST',headers:{'Content-Type':'application/json'},credentials:'same-origin',body:JSON.stringify({csrf_token:cfg.csrf,track_id:Number(cfg.trackId),session_id:sessionId,conversation_id:Number(cfg.conversationId||0),agent_context:agentContext,...payload})});
    const raw=await r.text();let d=null;try{d=JSON.parse(raw);}catch(e){throw new Error(`Studio Agent returned an invalid response (${r.status}).`);}if(!r.ok||!d?.ok)throw new Error(d?.error||'Studio Agent request failed.');
    if(d.session_id){sessionId=Number(d.session_id);localStorage.setItem(key,String(sessionId));}
    if(d.conversation_id){cfg.conversationId=Number(d.conversation_id);AgentContext?.setConversationId?.(cfg.conversationId);}
    return d;
  }

  function strictResult(value,type='command'){
    if(!value||typeof value!=='object'||Array.isArray(value))return{status:'unsupported',result:`${type} did not return a verifiable Studio result`,verified:false};
    const resultStatus=String(value.status||'');
    if(!VALID_STATUS.has(resultStatus))return{status:'failed',result:`${type} returned an invalid Studio result status`,verified:false};
    if(resultStatus==='success'&&!value.verified)return{status:'unverified',result:`${value.result||type} was not verified by Studio state`,verified:false};
    return{...value,status:resultStatus,result:String(value.result||type)};
  }

  async function executeV105(command){
    const type=String(command?.type||'');const b=bridge();
    if(type==='v158_create_library_project'){
      const projectAgent=window.StonefellowStemProjectAgentV158;
      if(!projectAgent?.createProject)return{status:'unsupported',result:'New-project Track Library tools are unavailable',verified:false};
      return strictResult(await projectAgent.createProject(command),type);
    }
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

  async function executeLegacy(command){
    if(command?.requires_confirmation&&!confirm(`Stonefellow wants to ${String(command.type||'perform this action')}. Continue?`))return{status:'cancelled',result:'User cancelled',verified:false};
    const local=await executeV105(command);if(local)return strictResult(local,String(command?.type||'command'));
    const b=bridge();if(!b?.executeAgentCommand)return{status:'unsupported',result:'Studio command bridge is unavailable',verified:false};
    try{return strictResult(await b.executeAgentCommand(command),String(command?.type||'command'));}
    catch(error){return{status:'failed',result:error?.message||'Studio command threw while executing',verified:false};}
  }

  const STEM_COMMANDS=[
    ['stem.transport.play','play',[],true,true],['stem.transport.pause','pause',[],true,true],['stem.transport.stop','stop',[],true,true],['stem.transport.seek','seek',['time'],true,true],
    ['stem.project.save','save',[],true,true],['stem.project.save-as','save_as',['name'],true,true],['stem.project.create-from-library','v158_create_library_project',['project_name','tempo_bpm','time_signature','library_roles'],true,true],
    ['stem.project.new','v159_new_project',['name','tempo_bpm','time_signature'],true,true],['stem.project.rename','v159_rename_project',['name'],true,true],['stem.project.list','v159_list_projects',[],false,true],['stem.project.load','v159_load_project',['track_id','name','which'],true,true],
    ['stem.project.version.save','v159_save',[],true,true],['stem.project.version.save-as','v159_save_as',['name'],true,true],['stem.project.version.list','v159_list_versions',[],false,true],['stem.project.version.load','v159_load_version',['mix_id','name','which'],true,true],
    ['stem.history.undo','v159_undo',['count'],true,true],['stem.history.redo','v159_redo',['count'],true,true],['stem.song.duration','v159_set_duration',['measures','extend'],true,true],['stem.track.create-empty','v159_create_empty_tracks',['count','role','base_name','input_provider'],true,true],['stem.track.clear-measures','v159_clear_measures',['stem_ids','start_measure','end_measure'],true,true],['stem.loop.measures','v159_loop_measures',['start_measure','end_measure','active'],true,true],['stem.track.state','v159_track_state',['stem_ids','action','value','exclusive'],true,true],
    ['stem.timeline.seek-relative','v105_seek_relative',['seconds'],true,true],['stem.timeline.marker-here','v105_marker_here',['label'],true,true],['stem.note.open-last','v105_open_last_note',[],false,true],['stem.metronome.volume','v105_metronome_volume',['value','delta'],true,true],
    ['stem.session.tempo','tempo',['value'],true,true],['stem.session.tempo-reset','reset_tempo',[],true,true],['stem.session.duration','song_duration',['measures','extend'],true,true],['stem.loop.active','loop_active',['value'],true,true],['stem.loop.measure-range','loop_measures',['start_measure','end_measure','active'],true,true],
    ['stem.track.mute','mute',['stem_id'],true,true],['stem.track.unmute','unmute',['stem_id'],true,true],['stem.track.solo','solo',['stem_id'],true,true],['stem.track.unsolo','unsolo',['stem_id'],true,true],['stem.track.volume','volume',['stem_id','value'],true,true],['stem.track.pan','pan',['stem_id','value'],true,true],['stem.track.trim','track_trim',['stem_id','value'],true,true],['stem.track.send','send',['stem_id','bus','value'],true,true],['stem.track.route','route',['stem_id','route'],true,true],['stem.track.arm','arm',['stem_id'],true,true],
    ['stem.master.volume','master_volume',['value'],true,true],['stem.bus.volume','bus_volume',['bus','value'],true,true],['stem.bus.mute','bus_mute',['bus','value'],true,true],['stem.metronome.configure','metronome',['enabled','free_run','style','accent'],true,true],['stem.record.monitor','monitor',[],true,true],['stem.record.start','record',[],true,true],
    ['stem.plugin.add','plugin_picker',['stem_id','plugin'],true,true],['stem.plugin.parameter','plugin_param',['stem_id','plugin_index','plugin_type','param','value'],true,true],['stem.plugin.bypass','plugin_bypass',['stem_id','plugin_index','plugin_type','bypassed'],true,true],['stem.plugin.remove','plugin_remove',['stem_id','plugin_index'],true,true],['stem.aux.return','aux_return',['bus','value'],true,true],
    ['stem.automation.point','automation_point',['stem_id','parameter','time','value'],true,true],['stem.automation.delete','automation_delete',['stem_id','parameter','index'],true,true],['stem.automation.clear','automation_clear',['stem_id','parameter'],true,true],
    ['stem.clip.move','clip_move',['clip_id','start'],true,true],['stem.clip.trim','clip_trim',['clip_id','edge','time'],true,true],['stem.clip.gain','clip_gain',['clip_id','value'],true,true],['stem.clip.fade','clip_fade',['clip_id','edge','value'],true,true],['stem.clip.mute','clip_mute',['clip_id','value'],true,true],['stem.clip.split','clip_split',['clip_id','time'],true,true],['stem.clip.delete','clip_delete',['clip_id'],true,true],
    ['stem.loop.set','loop_set',['start','end'],true,true],['stem.loop.clear','loop_clear',[],true,true],['stem.marker.add','marker_add',['time','label'],true,true],['stem.region.add','region_add',['start','end','label'],true,true],['stem.mix.reset','reset_mix',[],true,true],['stem.view.zoom','zoom',['value'],true,true],['stem.view.snap','snap',['value'],true,true],
    ['stem.ui.click','ui_click',['control_id'],true,true],['stem.ui.set','ui_set',['control_id','value'],true,true],['stem.ui.select','ui_select',['control_id','value'],true,true],['stem.ui.toggle','ui_toggle',['control_id','value'],true,true]
  ].map(([id,legacyType,args,mutates,verifiable])=>({id,legacyType,args,mutates,verifiable}));

  function stemSelection(){
    const state=bridge()?.getAgentState?.()||{};
    return{
      current:{stem_id:Number(state.selected_id||0)||null,clip_id:String(state.selected_clip_id||state.selected_clip||'')||null},
      stems:(Array.isArray(state.stems)?state.stems:[]).map(row=>({id:Number(row.id||0),name:String(row.name||row.stem_name||''),role:String(row.role||row.stem_role||'')})),
      clips:(Array.isArray(state.clips)?state.clips:[]).map(row=>({id:String(row.id||''),stem_id:Number(row.stem_id||0),start:Number(row.start||0),duration:Number(row.duration||0)})),
      buses:(Array.isArray(state.buses)?state.buses:[]).map(row=>({key:String(row.key||''),name:String(row.name||row.key||'')}))
    };
  }
  function normalizeStemCommand(command,descriptor){return{...command,type:String(descriptor.legacyType||command.type||''),editor_command_id:String(descriptor.id||'')};}
  function verifyStemCommand(command,before,after,raw){
    const checked=strictResult(raw,String(command?.type||'command'));
    if(checked.status==='no_change')return{...checked,verified:true};
    if(['failed','unsupported','cancelled'].includes(checked.status))return{...checked,verified:false};
    if(checked.status==='success'&&checked.verified)return checked;
    return{...checked,status:'unverified',result:`${checked.result||command.type} was not verified by the active Stem Studio tool truth bridge`,verified:false};
  }
  function registerEditorSurface(){
    if(!EditorAgent)return false;
    if(EditorAgent.hasSurface?.('stem'))return true;
    EditorAgent.registerSurface('stem',{
      label:'Stem Studio',commands:()=>STEM_COMMANDS,selection:stemSelection,snapshot:()=>bridge()?.getAgentState?.()||{},normalizeCommand:normalizeStemCommand,
      execute:(command)=>executeLegacy(command),verify:verifyStemCommand
    });
    proof.capabilityCount=STEM_COMMANDS.length;
    proof.editorRegistry=String(EditorAgent.build||'');
    return true;
  }
  void editorAgentReady.then(agent=>{EditorAgent=agent||null;if(EditorAgent)registerEditorSurface();});

  function overallStatus(results){
    if(results.some(r=>r.status==='failed'||r.status==='unverified'))return'failed';
    if(results.some(r=>r.status==='unsupported'))return'unsupported';
    if(results.some(r=>r.status==='cancelled'))return'cancelled';
    if(results.some(r=>r.status==='success'))return'success';
    if(results.length&&results.every(r=>r.status==='no_change'))return'no_change';
    return'success';
  }
  function finalReply(plannerAnswer,results,ledger){
    if(!results.length)return String(plannerAnswer||'').trim()||'I’m here.';
    const resultStatus=overallStatus(results);const details=results.map(r=>r.result).filter(Boolean).join('; ');
    const changes=Number(ledger?.changes?.length||0);
    if(resultStatus==='failed')return `I couldn’t complete that Studio action. ${details}`.trim();
    if(resultStatus==='unsupported')return `I can’t safely execute all of that in Stem Studio yet. ${details}`.trim();
    if(resultStatus==='cancelled')return 'Okay. I did not make that Studio change.';
    if(resultStatus==='no_change')return `No change was needed. ${details}`.trim();
    return `Done. I verified the Studio state${changes?` and logged ${changes} exact change${changes===1?'':'s'}`:''}. ${details}`.trim();
  }

  async function sendMessage(message,inputMode='text'){
    const msg=String(message||'').trim();if(!msg||busy)return;
    voice?.stopListening(false);line('user',msg);busy=true;setAgentState('processing','Thinking…');const b=bridge();
    try{
      const state=b?.getAgentState?.()||{};
      const d=await api({action:'send',message:msg,input_mode:inputMode,state});
      const reply=line('assistant',d.commands?.length?'Working on it…':(d.answer||''));const results=[];
      b?.beginAgentEdit?.({request:msg,provider:d.provider||'',model:d.model||'',conversationId:Number(cfg.conversationId||0)});
      const registry=await editorAgentReady;EditorAgent=registry||EditorAgent;if(EditorAgent)registerEditorSurface();
      for(const command of d.commands||[]){
        if(!EditorAgent?.hasSurface?.('stem')){results.push({status:'unsupported',result:'Universal Editor Agent Stem surface is unavailable.',verified:false});continue;}
        results.push(await EditorAgent.execute({surface:'stem',command,context:{request:msg,provider:d.provider||'',model:d.model||'',conversationId:Number(cfg.conversationId||0)}}));
      }
      const ledger=await b?.endAgentEdit?.({request:msg,provider:d.provider||'',model:d.model||'',conversationId:Number(cfg.conversationId||0)});
      const resultStatus=overallStatus(results);const responseText=finalReply(d.answer||'',results,ledger);
      const replyText=reply.querySelector('div');if(replyText)replyText.textContent=responseText;
      reply.querySelector('small').textContent+=` · ${resultStatus}`;
      const historyStatus=resultStatus==='failed'||resultStatus==='unsupported'?'failed':resultStatus==='cancelled'?'cancelled':'success';
      const resultText=results.map(r=>`[${r.status}] ${r.commandId?`${r.commandId}: `:''}${r.result}`).filter(Boolean).join('; ')+(ledger?.changes?.length?` · ${ledger.changes.length} exact state changes logged`:'');
      await api({action:'result',history_id:Number(d.history_id||0),assistant_message_id:Number(d.assistant_message_id||0),status:historyStatus,result:resultText,result_text:responseText});
      void AgentContext?.refresh?.(true);
      const navigation=results.find(result=>result.status==='success'&&result.verified&&(result.redirect||result.raw?.redirect));
      busy=false;
      if(navigation){
        const target=new URL(String(navigation.redirect||navigation.raw?.redirect),window.location.href);
        if(Number(cfg.conversationId||0)>0)target.searchParams.set('conversation_id',String(cfg.conversationId));
        if(voice?.isEnabled())target.searchParams.set('voice','1');
        setAgentState('ready','Opening the Studio project…');
        window.location.assign(target.toString());
      }else if(voice?.isEnabled())void voice.speak(responseText);else setAgentState('idle');
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

  const continuity={isVoice:()=>Boolean(voice?.isEnabled?.()),conversationId:()=>Number(cfg.conversationId||AgentContext?.conversationId?.()||0),sessionId:()=>String(voice?.sessionId||''),source:'stem-studio'};
  window.STONEFELLOW_CHAT_CONTINUITY=continuity;
  document.addEventListener('click',event=>{const link=event.target.closest?.('a[href*="/chat.php"],a[href*="/admin/stems.php"],a[href*="/video-editor.php"]');if(!link)return;try{const target=new URL(link.href,location.href);if(target.origin!==location.origin)return;const cid=continuity.conversationId();if(cid>0)target.searchParams.set('conversation_id',String(cid));if(continuity.isVoice())target.searchParams.set('voice','1');link.href=target.toString();}catch(error){}},true);

  async function load(){
    try{
      const d=await api({action:'history'});history.innerHTML='';(d.history||[]).forEach(h=>line(h.role==='user'?'user':'assistant',h.message_text||'',h.status||''));
      if(!d.history?.length){
        const ctx=AgentContext?await AgentContext.refresh(false):null;
        const count=Array.isArray(ctx?.proactive)?ctx.proactive.length:0;
        line('assistant',count?`I’m connected to this Stem Studio, your Agent Brain, and ${count} current proactive ${count===1?'opportunity':'opportunities'}. I’ll only say a Studio action worked after I verify the resulting state.`:'I’m connected to this Stem Studio and your Agent Brain. I’ll only say a Studio action worked after I verify the resulting state.');
      }
    }catch(e){line('assistant',e.message,'error');}
  }
  function openPanel(){panel.hidden=false;trigger.setAttribute('aria-expanded','true');load();setTimeout(()=>{input.focus();voice?.resume(40);},25);}
  function closePanel(){panel.hidden=true;trigger.setAttribute('aria-expanded','false');if(!voice?.isEnabled())setAgentState('idle');else if(busy||voice.isPreparing())setAgentState('processing');else if(voice.isSpeaking())setAgentState('speaking');else if(voice.isListening())setAgentState('listening');else voice.resume(40);}
  function togglePanel(){panel.hidden?openPanel():closePanel();}
  trigger.addEventListener('click',togglePanel);voiceButton.addEventListener('click',()=>voice?.toggle());
  form.addEventListener('submit',e=>{e.preventDefault();const msg=input.value.trim();input.value='';sendMessage(msg,'text');});
  let qTimes=[];document.addEventListener('keydown',e=>{if(e.key==='Escape'&&!panel.hidden){closePanel();return;}if(e.target.matches?.('input,textarea,select')||e.target.isContentEditable)return;if(e.key.toLowerCase()!=='q'){qTimes=[];return;}const now=Date.now();qTimes=qTimes.filter(t=>now-t<900);qTimes.push(now);if(qTimes.length>=3){qTimes=[];e.preventDefault();togglePanel();}});
  syncVoiceButton(initialVoice);AgentContext?.setConversationId?.(Number(cfg.conversationId||0));if(voice)voice.start();else setAgentState('idle','Voice conversation is unavailable.');
})();