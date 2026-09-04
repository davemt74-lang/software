(() => {
  'use strict';
  const BUILD='conversation-integration-v131-20260826';
  const cfg=window.STONEFELLOW_VIDEO_EDITOR||{},button=document.getElementById('videoAgentButton');
  if(!cfg.agentEndpoint||!button)return;
  const Voice=window.StonefellowConversationVoiceV122;
  const AgentContext=window.StonefellowAgentContext||null;
  let EditorAgent=window.StonefellowEditorAgent||null;
  const editorAgentReady=EditorAgent?Promise.resolve(EditorAgent):import('/editor-agent.js?v=editor-agent-canonical-20260903').then(()=>window.StonefellowEditorAgent||null).catch(()=>null);
  const initialVoice=!!cfg.voiceMode||!!Voice?.readShared?.(cfg.userId);
  let conversationId=Math.max(0,Number(cfg.conversationId||AgentContext?.conversationId?.()||0)),busy=false;
  const esc=v=>String(v??'').replaceAll('&','&amp;').replaceAll('<','&lt;').replaceAll('>','&gt;').replaceAll('"','&quot;').replaceAll("'",'&#039;');
  const panel=document.createElement('aside');panel.className='editor-agent-panel video-agent-panel';panel.hidden=true;panel.innerHTML=`<div class="editor-agent-history" data-agent-history></div><footer class="editor-agent-footer"><form class="editor-agent-composer" data-agent-form><textarea rows="1" maxlength="6000" placeholder="Message Stonefellow…" aria-label="Message Stonefellow"></textarea><button class="editor-agent-voice${initialVoice?' active':''}" type="button" data-agent-voice aria-label="${initialVoice?'Stop':'Start'} voice conversation" aria-pressed="${initialVoice?'true':'false'}">◉</button><button class="editor-agent-send" type="submit" aria-label="Send message">↑</button></form><small class="editor-agent-status" data-agent-status>${initialVoice?'Listening…':'Edits are archived into Agent Brain'}</small></footer>`;document.body.appendChild(panel);
  const history=panel.querySelector('[data-agent-history]'),form=panel.querySelector('[data-agent-form]'),input=form.querySelector('textarea'),voiceButton=panel.querySelector('[data-agent-voice]'),status=panel.querySelector('[data-agent-status]');

  const proof=window.STONEFELLOW_VIDEO_AGENT_V131={build:BUILD,engine:String(Voice?.build||''),editorRegistry:String(EditorAgent?.build||''),stateTransitions:[],contextAttached:0,verifiedCommands:0,failedCommands:0,unverifiedCommands:0,capabilityCount:0};

  function setAgentState(state,text=''){
    const enabled=Boolean(voice?.isEnabled?.());
    const visual=(state==='ready'||state==='recovering'||state==='interrupted')&&enabled?'listening':state;
    button.classList.remove('ai-listening','ai-thinking','ai-responding','ai-busy');
    if(visual==='listening')button.classList.add('ai-listening');
    if(visual==='processing')button.classList.add('ai-thinking','ai-busy');
    if(visual==='speaking')button.classList.add('ai-responding','ai-busy');
    button.dataset.agentState=visual;
    button.setAttribute('aria-label',visual==='listening'?'AI · listening':visual==='processing'?'AI · thinking':visual==='speaking'?'AI · responding':'AI');
    status.textContent=visual==='listening'?'Listening…':visual==='processing'?'Thinking…':visual==='speaking'?'Stonefellow is responding…':text||(enabled?'Listening…':'Edits are archived into Agent Brain');
    proof.stateTransitions.push({state,visual,at:Date.now()});if(proof.stateTransitions.length>40)proof.stateTransitions.shift();
  }
  function syncVoiceButton(on){voiceButton.setAttribute('aria-pressed',on?'true':'false');voiceButton.classList.toggle('active',on);voiceButton.setAttribute('aria-label',on?'Stop voice conversation':'Start voice conversation');}
  function line(role,text,state=''){const el=document.createElement('article');el.className=`editor-agent-line ${role}`;el.innerHTML=`<small>${role==='user'?'You':'Stonefellow'}${state?` · ${esc(state)}`:''}</small><div>${esc(text)}</div>`;history.appendChild(el);history.scrollTop=history.scrollHeight;return el;}
  async function context(){try{return AgentContext?await AgentContext.refresh(false):{};}catch(error){return AgentContext?.snapshot?.()||{};}}
  async function api(payload,agentContext=null){
    const ctx=agentContext||await context();
    proof.contextAttached+=1;
    const r=await fetch(cfg.agentEndpoint,{method:'POST',headers:{'Content-Type':'application/json'},credentials:'same-origin',body:JSON.stringify({csrf_token:cfg.csrf,agent_context:ctx,...payload})});
    const raw=await r.text();let d=null;try{d=JSON.parse(raw);}catch(e){throw new Error(`Video Agent returned an invalid response (${r.status}).`);}if(!r.ok||!d?.ok)throw new Error(d?.error||'Agent request failed.');
    if(d.conversation_id){conversationId=Number(d.conversation_id);cfg.conversationId=conversationId;AgentContext?.setConversationId?.(conversationId);}
    return d;
  }
  async function load(){
    try{
      const d=await api({action:'history',conversation_id:conversationId});history.innerHTML='';
      if(Array.isArray(d.history)&&d.history.length){d.history.forEach(m=>line(m.role==='user'?'user':'assistant',m.message||''));return;}
      const ctx=AgentContext?await AgentContext.refresh(false):null;const count=Array.isArray(ctx?.proactive)?ctx.proactive.length:0;
      line('assistant',count?`I’m connected to this Video Editor, your Agent Brain, and ${count} current proactive ${count===1?'opportunity':'opportunities'}.`:'I’m connected to this Video Editor and your Agent Brain.');
    }catch(e){history.innerHTML='';line('assistant',e.message,'error');}
  }

  const near=(a,b,t=.002)=>Math.abs(Number(a||0)-Number(b||0))<=t;
  const clips=state=>Array.isArray(state?.clips)?state.clips:[];
  const clip=(state,id)=>clips(state).find(row=>String(row?.id||'')===String(id||''))||null;
  const sourceCount=(state,kind,id)=>clips(state).filter(row=>String(row?.source_kind||'')===kind&&Number(row?.source_id||0)===Number(id||0)).length;
  const timelineEnd=state=>clips(state).reduce((max,row)=>Math.max(max,Number(row?.start||0)+Math.max(.1,Number(row?.duration||0))),0);
  function verification(status,result,verified=false){return{status,result:String(result||''),verified:!!verified};}
  function verifyVideoCommand(command,before,after,raw=null){
    const type=String(command?.type||'');const id=String(command?.clip_id||'');const was=clip(before,id),now=clip(after,id);
    if(type==='save')return raw?.status==='success'&&raw?.verified?raw:verification('failed',raw?.result||'Project save failed.',false);
    if(was?.locked&&['split','duplicate','delete','move','trim','set_volume','set_mute','set_opacity','set_fade','set_lane'].includes(type))return verification('failed',`The requested ${type} target is locked.`,false);
    switch(type){
      case'play':case'pause':return verification('unverified',`${type==='play'?'Playback':'Pause'} was dispatched, but this editor bridge does not expose transport state for verification.`,false);
      case'seek':{const expected=Math.min(Math.max(0,Number(command.time||0)),timelineEnd(after));return near(after?.playhead,expected,.01)?verification('success',`Playhead verified at ${Number(after.playhead||0).toFixed(2)} seconds.`,true):verification('failed','The requested playhead position was not verified.',false);}
      case'split':return clips(after).length>clips(before).length?verification('success','Clip split verified from timeline state.',true):verification('failed','Clip split was not verified from timeline state.',false);
      case'duplicate':return clips(after).length>clips(before).length?verification('success','Clip duplication verified from timeline state.',true):verification('failed','Clip duplication was not verified from timeline state.',false);
      case'delete':return was&&!now?verification('success','Clip deletion verified from timeline state.',true):verification('failed','Clip deletion was not verified from timeline state.',false);
      case'move':return now&&near(now.start,command.start,.01)?verification('success','Clip position verified.',true):verification('failed','Clip position change was not verified.',false);
      case'trim':return now&&near(now.duration,command.duration,.01)?verification('success','Clip duration verified.',true):verification('failed','Clip trim was not verified.',false);
      case'set_volume':return now&&near(now.volume,command.value,.002)?verification('success','Clip volume verified.',true):verification('failed','Clip volume change was not verified.',false);
      case'set_mute':return now&&Boolean(now.muted)===Boolean(command.value)?verification('success','Clip mute state verified.',true):verification('failed','Clip mute state was not verified.',false);
      case'set_opacity':return now&&near(now.opacity,command.value,.002)?verification('success','Clip opacity verified.',true):verification('failed','Clip opacity change was not verified.',false);
      case'set_fade':{const key=command.edge==='out'?'fade_out':'fade_in';return now&&near(now[key],command.value,.002)?verification('success',`Clip ${command.edge==='out'?'fade out':'fade in'} verified.`,true):verification('failed','Clip fade change was not verified.',false);}
      case'set_lane':return now&&Number(now.lane)===Number(command.lane)?verification('success','Clip lane verified.',true):verification('failed','Clip lane change was not verified.',false);
      case'add_asset':return sourceCount(after,'asset',command.source_id)>sourceCount(before,'asset',command.source_id)?verification('success','Asset insertion verified from timeline state.',true):verification('failed','Asset insertion was not verified.',false);
      case'add_track':return sourceCount(after,'track',command.source_id)>sourceCount(before,'track',command.source_id)?verification('success','Track insertion verified from timeline state.',true):verification('failed','Track insertion was not verified.',false);
      case'zoom':{const expected=Math.max(8,Math.min(180,Number(before?.zoom||32)*(command.direction==='out'?.8:1.25)));return near(after?.zoom,expected,.01)?verification('success','Timeline zoom verified.',true):verification('failed','Timeline zoom change was not verified.',false);}
      case'snap':return Boolean(after?.snap)===Boolean(command.value)?verification('success','Timeline snap state verified.',true):verification('failed','Timeline snap state was not verified.',false);
      default:return verification('failed',`Video Editor command “${type||'unknown'}” is not verifiable.`,false);
    }
  }

  const VIDEO_COMMANDS=[
    ['video.transport.play','play',[],true,false],['video.transport.pause','pause',[],true,false],['video.transport.seek','seek',['time'],true,true],
    ['video.clip.split','split',['clip_id'],true,true],['video.clip.duplicate','duplicate',['clip_id'],true,true],['video.clip.delete','delete',['clip_id'],true,true],['video.clip.move','move',['clip_id','start'],true,true],['video.clip.trim','trim',['clip_id','duration'],true,true],['video.clip.volume','set_volume',['clip_id','value'],true,true],['video.clip.mute','set_mute',['clip_id','value'],true,true],['video.clip.opacity','set_opacity',['clip_id','value'],true,true],['video.clip.fade','set_fade',['clip_id','edge','value'],true,true],['video.clip.lane','set_lane',['clip_id','lane'],true,true],
    ['video.source.asset.add','add_asset',['source_id','start','lane'],true,true],['video.source.track.add','add_track',['source_id','start','lane'],true,true],['video.project.save','save',[],true,true],['video.timeline.zoom','zoom',['direction'],true,true],['video.timeline.snap','snap',['value'],true,true]
  ].map(([id,legacyType,args,mutates,verifiable])=>({id,legacyType,args,mutates,verifiable}));

  function videoSelection(){
    const state=window.StonefellowVideoEditor?.getState?.()||{};
    return{
      current:{clip_id:String(state.selected_id||'')||null},
      clips:(Array.isArray(state.clips)?state.clips:[]).map(row=>({id:String(row.id||''),title:String(row.title||''),media_type:String(row.media_type||''),lane:Number(row.lane||0),locked:Boolean(row.locked)})),
      assets:(Array.isArray(state.assets)?state.assets:[]).map(row=>({id:Number(row.id||0),title:String(row.title||''),media_type:String(row.media_type||'')})),
      tracks:(Array.isArray(state.tracks)?state.tracks:[]).map(row=>({id:Number(row.id||0),title:String(row.title||''),media_type:String(row.media_type||'audio')}))
    };
  }
  function normalizeVideoCommand(command,descriptor){return{...command,type:String(descriptor.legacyType||command.type||''),editor_command_id:String(descriptor.id||'')};}
  async function executeVideoCommand(command,ctx={}){
    const bridge=window.StonefellowVideoEditor;
    if(String(command?.type||'')==='save'){
      if(!bridge?.saveProject)return{status:'unsupported',result:'Project save is unavailable.',verified:false,changes:[]};
      const saved=await bridge.saveProject(false);
      return saved?{status:'success',result:'Project save verified by the save endpoint.',verified:true,verification:'save-endpoint',changes:[]}:{status:'failed',result:'Project save failed.',verified:false,changes:[]};
    }
    if(!bridge?.executeCommands)return{status:'unsupported',result:'Video Editor command bridge is unavailable.',verified:false,changes:[]};
    return bridge.executeCommands([command],{request:String(ctx.request||''),provider:String(ctx.provider||''),model:String(ctx.model||''),conversationId:Number(ctx.conversationId||0)});
  }
  function registerEditorSurface(){
    if(!EditorAgent)return false;
    if(EditorAgent.hasSurface?.('video'))return true;
    EditorAgent.registerSurface('video',{
      label:'Video Editor',commands:()=>VIDEO_COMMANDS,selection:videoSelection,snapshot:()=>window.StonefellowVideoEditor?.getState?.()||{},normalizeCommand:normalizeVideoCommand,
      execute:executeVideoCommand,verify:verifyVideoCommand
    });
    proof.capabilityCount=VIDEO_COMMANDS.length;
    proof.editorRegistry=String(EditorAgent.build||'');
    return true;
  }
  void editorAgentReady.then(agent=>{EditorAgent=agent||null;if(EditorAgent)registerEditorSurface();});

  function overallStatus(results){if(results.some(r=>r.status==='failed'||r.status==='unsupported'||r.status==='cancelled'))return'failed';if(results.some(r=>r.status==='unverified'))return'unverified';if(results.some(r=>r.status==='success'))return'success';if(results.length&&results.every(r=>r.status==='no_change'))return'no_change';return'success';}
  function verifiedReply(results,changeCount){
    const status=overallStatus(results);const details=results.map(r=>r.result).filter(Boolean).join(' ');
    if(status==='failed')return `I couldn’t verify all of that Video Editor work. ${details}`.trim();
    if(status==='unverified')return `I sent the Video Editor command, but I won’t call it complete without state verification. ${details}`.trim();
    if(status==='no_change')return `No Video Editor state change was needed. ${details}`.trim();
    return `Done. I verified ${results.filter(r=>r.verified).length} Video Editor action${results.filter(r=>r.verified).length===1?'':'s'}${changeCount?` and logged ${changeCount} exact state change${changeCount===1?'':'s'}`:''}. ${details}`.trim();
  }

  async function sendMessage(msg,inputMode='text'){
    const clean=String(msg||'').trim();if(busy||!clean)return;
    voice?.stopListening(false);line('user',clean);busy=true;setAgentState('processing','Thinking…');
    try{
      const bridge=window.StonefellowVideoEditor,state=bridge?.getState?.()||{};
      const agentContext=await context();
      const d=await api({action:'send',conversation_id:conversationId,message:clean,input_mode:inputMode,state},agentContext);
      const reply=line('assistant',d.answer||'');const replyText=reply.querySelector('div');const commands=Array.isArray(d.commands)?d.commands:[],destructive=commands.filter(c=>c?.requires_confirmation);
      let resultStatus='success',responseText=String(d.answer||'').trim()||'I’m here.',verifications=[];const allChanges=[];
      if(destructive.length&&!confirm(`Stonefellow wants to perform ${destructive.length} destructive Video Editor action${destructive.length===1?'':'s'}. Continue?`)){
        resultStatus='cancelled';responseText='Okay. I did not make that Video Editor change.';reply.querySelector('small').textContent+=' · cancelled';
      }else if(commands.length){
        const registry=await editorAgentReady;EditorAgent=registry||EditorAgent;if(EditorAgent)registerEditorSurface();
        if(!EditorAgent?.hasSurface?.('video')){
          resultStatus='failed';responseText='I couldn’t execute that Video Editor action because the universal Editor Agent surface is unavailable.';reply.querySelector('small').textContent+=' · failed';
        }else{
          for(const command of commands){
            const execution=await EditorAgent.execute({surface:'video',command,context:{request:clean,provider:d.provider||'',model:d.model||'',conversationId}});
            verifications.push(execution);
            if(Array.isArray(execution.changes))allChanges.push(...execution.changes);
          }
          proof.verifiedCommands+=verifications.filter(row=>row.verified).length;proof.failedCommands+=verifications.filter(row=>row.status==='failed'||row.status==='unsupported').length;proof.unverifiedCommands+=verifications.filter(row=>row.status==='unverified').length;
          const verificationStatus=overallStatus(verifications);resultStatus=verificationStatus==='success'||verificationStatus==='no_change'?'success':'failed';
          responseText=verifiedReply(verifications,allChanges.length);
          reply.querySelector('small').textContent+=` · ${verificationStatus}`;
        }
      }
      if(replyText)replyText.textContent=responseText;
      await api({action:'result',conversation_id:conversationId,assistant_message_id:Number(d.assistant_message_id||0),project_id:Number(bridge?.getProjectId?.()||0),request_text:clean,status:resultStatus,result_text:responseText,change_count:Number(allChanges.length||0),model:d.model||''},agentContext);
      void AgentContext?.refresh?.(true);
      busy=false;
      if(voice?.isEnabled())void voice.speak(responseText);else setAgentState('idle');
    }catch(e){
      busy=false;line('assistant',e.message||'Video Agent failed.','error');
      if(voice?.isEnabled())voice.resume(180);else setAgentState('idle');
    }finally{if(!panel.hidden)input.focus();}
  }

  const voice=Voice?.create?.({
    userId:Number(cfg.userId||0),source:'video-editor',initialEnabled:initialVoice,
    agentEndpoint:cfg.agentEndpoint,csrf:cfg.csrf,isBusy:()=>busy,onTranscript:text=>sendMessage(text,'voice'),
    onState:setAgentState,onVoiceChange:syncVoiceButton,onError:error=>{if(error?.message)line('assistant',error.message,'error');}
  })||null;

  const continuity={isVoice:()=>Boolean(voice?.isEnabled?.()),conversationId:()=>Number(conversationId||AgentContext?.conversationId?.()||0),sessionId:()=>String(voice?.sessionId||''),source:'video-editor'};
  window.STONEFELLOW_CHAT_CONTINUITY=continuity;
  document.addEventListener('click',event=>{const link=event.target.closest?.('a[href*="/chat.php"],a[href*="/admin/stems.php"],a[href*="/video-editor.php"]');if(!link)return;try{const target=new URL(link.href,location.href);if(target.origin!==location.origin)return;const cid=continuity.conversationId();if(cid>0)target.searchParams.set('conversation_id',String(cid));if(continuity.isVoice())target.searchParams.set('voice','1');link.href=target.toString();}catch(error){}},true);

  function open(){panel.hidden=false;button.setAttribute('aria-expanded','true');load();setTimeout(()=>{input.focus();voice?.resume(40);},30);}
  function close(){panel.hidden=true;button.setAttribute('aria-expanded','false');if(!voice?.isEnabled())setAgentState('idle');else if(busy||voice.isPreparing())setAgentState('processing');else if(voice.isSpeaking())setAgentState('speaking');else if(voice.isListening())setAgentState('listening');else voice.resume(40);}
  function toggle(){panel.hidden?open():close();}
  button.addEventListener('click',toggle);voiceButton.addEventListener('click',()=>voice?.toggle());form.addEventListener('submit',e=>{e.preventDefault();const msg=input.value.trim();input.value='';sendMessage(msg,'text');});
  let qTimes=[];document.addEventListener('keydown',e=>{if(e.key==='Escape'&&!panel.hidden){close();return;}if(e.target.matches?.('input,textarea,select')||e.target.isContentEditable)return;if(e.key.toLowerCase()!=='q'){qTimes=[];return;}const now=Date.now();qTimes=qTimes.filter(t=>now-t<900);qTimes.push(now);if(qTimes.length>=3){qTimes=[];e.preventDefault();toggle();}});
  window.addEventListener('stonefellow:agent-toggle',toggle);
  syncVoiceButton(initialVoice);AgentContext?.setConversationId?.(conversationId);if(voice)voice.start();else setAgentState('idle','Voice conversation is unavailable.');
})();