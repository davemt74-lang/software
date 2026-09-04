(() => {
  'use strict';

  const BUILD='stem-tool-truth-v127-20260826';
  const VALID_STATUS=new Set(['success','failed','unsupported','no_change','cancelled']);
  const SETTLE_MS=70;
  const EPS=.012;

  const wait=ms=>new Promise(resolve=>setTimeout(resolve,ms));
  const clamp=(value,min,max)=>Math.max(min,Math.min(max,Number(value)||0));
  const close=(a,b,eps=EPS)=>Math.abs(Number(a||0)-Number(b||0))<=eps;
  const q=id=>document.getElementById(id);

  function stateBridge(){return window.StonefellowStemStudioV91||window.StonefellowStemStudioV90||null;}
  function snapshot(){try{return stateBridge()?.getAgentState?.()||{};}catch(error){return {};}}
  function stableSnapshot(value){try{return JSON.stringify(value,(key,item)=>key==='controls'?undefined:item);}catch(error){return '';}}
  function stem(state,id){return (Array.isArray(state?.stems)?state.stems:[]).find(item=>Number(item?.id)===Number(id))||null;}
  function bus(state,key){return (Array.isArray(state?.buses)?state.buses:[]).find(item=>String(item?.key)===String(key))||null;}
  function metronomeState(state){return state?.metronome&&typeof state.metronome==='object'?state.metronome:(window.StonefellowMetronomeV91?.getState?.()||{});}
  function sourceTempo(){const value=Number(window.STONEFELLOW_STEM_STUDIO?.sourceTempo||0);return Number.isFinite(value)&&value>=40&&value<=300?value:0;}
  function measureSeconds(state){const match=String(state?.time_signature||window.STONEFELLOW_STEM_STUDIO?.timeSignature||'4/4').match(/^(\d+)\s*\/\s*(\d+)$/);const beats=match?Math.max(1,Number(match[1]))*(4/Math.max(1,Number(match[2]))):4;return beats*60/Math.max(40,sourceTempo()||120);}
  function playhead(){try{return Number(window.STONEFELLOW_STUDIO_RUNTIME_V87?.getPosition?.()||0);}catch(error){return 0;}}
  function result(status,text,extra={}){
    const clean=VALID_STATUS.has(status)?status:'failed';
    return {status:clean,result:String(text||''),verified:clean==='no_change'||Boolean(extra.verified),build:BUILD,...extra};
  }
  function normalized(raw,type){
    if(!raw||typeof raw!=='object'||Array.isArray(raw))return null;
    const status=VALID_STATUS.has(String(raw.status||''))?String(raw.status):'failed';
    return {...raw,status,result:String(raw.result||`${type} ${status}`),verified:Boolean(raw.verified)};
  }
  function buttonState(button){return Boolean(button&&(button.classList.contains('active')||button.classList.contains('armed')||button.getAttribute('aria-pressed')==='true'));}
  function dispatchInput(input,value){input.value=String(value);input.dispatchEvent(new Event('input',{bubbles:true}));input.dispatchEvent(new Event('change',{bubbles:true}));}
  function stemSelector(id,control){return `[data-mixer-stem="${Number(id)}"] ${control},[data-stem-id="${Number(id)}"] ${control}`;}
  function targetStemButton(id,kind){return document.querySelector(stemSelector(id,`[data-stem-${kind}]`));}
  function targetStemInput(id,kind){return document.querySelector(`[data-mixer-stem="${Number(id)}"] [data-stem-${kind}]`);}

  function desiredAlready(command,before){
    const type=String(command?.type||'');const target=stem(before,command?.stem_id);
    if(type==='play')return Boolean(before?.playing);
    if(type==='pause'||type==='stop')return !Boolean(before?.playing);
    if(type==='tempo')return close(before?.tempo,command?.value,.05);
    if(type==='reset_tempo'){const source=sourceTempo();return source>0&&close(before?.tempo,source,.05);}
    if(type==='mute')return Boolean(target?.muted);
    if(type==='unmute')return target&&!Boolean(target.muted);
    if(type==='solo')return Boolean(target?.solo);
    if(type==='unsolo')return target&&!Boolean(target.solo);
    if(type==='volume')return target&&close(target.volume,command?.value);
    if(type==='pan')return target&&close(target.pan,command?.value);
    if(type==='master_volume')return close(before?.master?.volume,command?.value);
    if(type==='bus_volume')return close(bus(before,command?.bus)?.volume,command?.value);
    if(type==='bus_mute')return Boolean(bus(before,command?.bus)?.muted)===Boolean(command?.value);
    if(type==='record')return Boolean(before?.recording);
    if(type==='monitor')return Boolean(before?.monitoring);
    if(type==='arm')return Boolean(document.querySelector(`[data-stem-id="${Number(command?.stem_id)}"]`)?.classList.contains('armed'));
    if(type==='metronome'){
      const m=metronomeState(before);if(!m||typeof m!=='object')return false;
      const enabled=command.enabled===undefined?Boolean(m.enabled):Boolean(command.enabled);
      return Boolean(m.enabled)===enabled&&(!command.style||String(m.style||'')===String(command.style))&&(!command.accent||String(m.accent||'')===String(command.accent));
    }
    if(type==='v105_metronome_volume'){
      const m=metronomeState(before);
      if(Number.isFinite(Number(command.value)))return close(m.volume,command.value,.02);
      return false;
    }
    if(type==='seek')return close(playhead(),command?.time,.08);
    if(type==='song_duration')return Number(before?.duration_measures||0)===Number(command?.measures||0);
    if(type==='loop_active')return Boolean(before?.loop?.active)===Boolean(command?.value);
    if(type==='loop_measures'){
      const unit=measureSeconds(before),first=Math.max(1,Number(command?.start_measure||1)),last=Math.max(first,Number(command?.end_measure||first));
      return close(before?.loop?.start,(first-1)*unit,.03)&&close(before?.loop?.end,last*unit,.03)&&Boolean(before?.loop?.active)===Boolean(command?.active!==false);
    }
    return false;
  }

  async function saveVerified(){
    const button=q('studioSaveButton');const status=q('studioSaveStatus');
    if(!button)return result('unsupported','Studio Save control is unavailable');
    if(button.disabled)return result('failed','Studio Save is currently busy');
    const beforeText=String(status?.textContent||'');button.click();const started=Date.now();
    while(Date.now()-started<8000){
      await wait(80);
      if(status?.classList.contains('saved'))return result('success','Studio version saved',{verified:true,verification:'save-status'});
      const text=String(status?.textContent||'').trim();
      if(/saved|complete/i.test(text)&&text!==beforeText)return result('success','Studio version saved',{verified:true,verification:'save-status-text'});
      if(/error|failed|could not/i.test(text))return result('failed',text,{verification:'save-status-error'});
      const dialog=q('mixSaveDialog');if(dialog&&(dialog.open||dialog.classList.contains('open')))return result('no_change','Save needs a version name before it can complete',{verification:'save-dialog'});
    }
    return result('failed','Studio Save did not confirm completion',{verification:'save-timeout'});
  }

  async function seekTo(seconds){
    const runtime=window.STONEFELLOW_STUDIO_RUNTIME_V87;const surface=q('dawTimelineSurface');const state=snapshot();const clips=Array.isArray(state.clips)?state.clips:[];
    const liveEnd=clips.reduce((max,item)=>Math.max(max,Number(item?.start||0)+Number(item?.duration||0)),0);const duration=Math.max(0,Number(window.STONEFELLOW_STEM_STUDIO?.duration||0),liveEnd,playhead());
    if(!runtime?.getPosition||!surface||duration<=0)return result('unsupported','Timeline seek is unavailable');
    const target=clamp(seconds,0,duration);if(close(playhead(),target,.08))return result('no_change',`Playhead is already at ${target.toFixed(2)}s`,{verification:'playhead'});
    const rect=surface.getBoundingClientRect();surface.dispatchEvent(new MouseEvent('click',{bubbles:true,cancelable:true,clientX:rect.left+(target/duration)*Math.max(1,rect.width),clientY:rect.top+Math.max(1,rect.height*.2)}));await wait(45);
    return close(playhead(),target,.12)?result('success',`Playhead moved to ${target.toFixed(2)}s`,{verified:true,verification:'playhead'}):result('failed',`Playhead did not move to ${target.toFixed(2)}s`,{verification:'playhead'});
  }

  async function coreFallback(command,before){
    const type=String(command?.type||'');const id=Number(command?.stem_id||0);
    if(type==='play'||type==='pause'||type==='stop'){
      const button=q('stemPlayButton');if(!button)return result('unsupported','Transport control is unavailable');const wantPlay=type==='play';if(Boolean(before?.playing)===wantPlay)return result('no_change',wantPlay?'Playback is already running':'Playback is already stopped',{verification:'playing'});button.click();return result('success',wantPlay?'Playback started':'Playback stopped');
    }
    if(type==='seek')return seekTo(Number(command.time||0));
    if(type==='save')return saveVerified();
    if(type==='tempo'){const input=q('sessionTempo');if(!input)return result('unsupported','Tempo control is unavailable');dispatchInput(input,clamp(command.value,40,300));return result('success','Tempo updated');}
    if(type==='reset_tempo'){const button=q('resetSessionTempo');if(!button)return result('unsupported','Reset tempo control is unavailable');button.click();return result('success','Tempo reset to source tempo');}
    if(['mute','unmute','solo','unsolo'].includes(type)){
      if(!id||!stem(before,id))return result('failed','Target stem is unavailable');const kind=type.includes('mute')?'mute':'solo';const button=targetStemButton(id,kind);if(!button)return result('unsupported',`${kind} control is unavailable for that stem`);const desired=type==='mute'||type==='solo';const current=kind==='mute'?Boolean(stem(before,id)?.muted):Boolean(stem(before,id)?.solo);if(current===desired)return result('no_change',`${kind} is already ${desired?'on':'off'}`);button.click();return result('success',`${kind} ${desired?'enabled':'disabled'}`);
    }
    if(type==='volume'||type==='pan'){if(!id||!stem(before,id))return result('failed','Target stem is unavailable');const input=targetStemInput(id,type);if(!input)return result('unsupported',`${type} control is unavailable for that stem`);const value=type==='volume'?clamp(command.value,0,1.5):clamp(command.value,-1,1);dispatchInput(input,value);return result('success',`${type} updated`);}
    if(type==='master_volume'){const input=q('stemMasterVolume');if(!input)return result('unsupported','Master fader is unavailable');dispatchInput(input,clamp(command.value,0,1.5));return result('success','Master volume updated');}
    if(type==='bus_volume'){const key=String(command.bus||'');const input=document.querySelector(`[data-group-volume="${CSS.escape(key)}"]`);if(!input)return result('unsupported',`${key||'requested'} bus fader is unavailable`);dispatchInput(input,clamp(command.value,0,1.5));return result('success',`${key} bus volume updated`);}
    if(type==='bus_mute'){const key=String(command.bus||'');const button=document.querySelector(`[data-group-mute="${CSS.escape(key)}"]`);if(!button)return result('unsupported',`${key||'requested'} bus mute is unavailable`);const current=buttonState(button);const desired=Boolean(command.value);if(current===desired)return result('no_change',`${key} bus mute is already ${desired?'on':'off'}`);button.click();return result('success',`${key} bus mute ${desired?'enabled':'disabled'}`);}
    if(type==='metronome'){const met=window.StonefellowMetronomeV91;if(!met?.configure||!met?.getState)return result('unsupported','Metronome controls are unavailable');met.configure(command,{manual:false});return result('success','Metronome updated');}
    if(type==='v105_metronome_volume'){const met=window.StonefellowMetronomeV91;if(!met?.configure||!met?.getState)return result('unsupported','Metronome volume is unavailable');const current=met.getState();const next=Number.isFinite(Number(command.value))?Number(command.value):Number(current.volume||0)+Number(command.delta||0);met.configure({volume:clamp(next,0,1)},{manual:false});return result('success',`Metronome volume ${Math.round(clamp(next,0,1)*100)}%`);}
    if(type==='monitor'){const button=q('studioMonitorButton');if(!button)return result('unsupported','Input monitor control is unavailable');if(buttonState(button))return result('no_change','Input monitoring is already on');button.click();return result('success','Input monitoring enabled');}
    if(type==='arm'){
      if(!id)return result('failed','No recording stem was specified');const row=document.querySelector(`[data-stem-id="${id}"]`);if(!row)return result('failed','Recording stem is unavailable');if(row.classList.contains('armed'))return result('no_change','That stem is already armed');row.click();await wait(20);const button=q('inspectorArm');if(!button)return result('unsupported','Record-arm control is unavailable');button.click();return result('success','Stem armed for recording');
    }
    if(type==='record'){const button=q('studioRecordButton')||q('inspectorRecordButton');if(!button)return result('unsupported','Record control is unavailable');if(Boolean(before?.recording)||buttonState(button))return result('no_change','Recording is already active');button.click();return result('success','Recording started');}
    return null;
  }

  async function verify(command,before,raw){
    const type=String(command?.type||'');
    if(!raw)return result('unsupported',`Stonefellow does not have an executable ${type||'unknown'} Studio tool`);
    if(['failed','unsupported','cancelled'].includes(raw.status))return {...raw,verified:false,build:BUILD};
    if(raw.status==='no_change')return {...raw,verified:true,build:BUILD};
    if(raw.verified)return {...raw,build:BUILD};
    await wait(type==='record'?450:(type==='monitor'||type==='arm'?180:SETTLE_MS));
    const after=snapshot();const target=stem(after,command?.stem_id);let ok=null;let label='state';
    if(type==='play'){ok=Boolean(after?.playing);label='playing';}
    else if(type==='pause'||type==='stop'){ok=!Boolean(after?.playing);label='playing';}
    else if(type==='tempo'){ok=close(after?.tempo,command.value,.05);label='tempo';}
    else if(type==='reset_tempo'){const source=sourceTempo();ok=source>0?close(after?.tempo,source,.05):!close(after?.tempo,before?.tempo,.0001);label='tempo';}
    else if(type==='mute'){ok=Boolean(target?.muted);label='stem.muted';}
    else if(type==='unmute'){ok=target&&!Boolean(target.muted);label='stem.muted';}
    else if(type==='solo'){ok=Boolean(target?.solo);label='stem.solo';}
    else if(type==='unsolo'){ok=target&&!Boolean(target.solo);label='stem.solo';}
    else if(type==='volume'){ok=target&&close(target.volume,command.value);label='stem.volume';}
    else if(type==='pan'){ok=target&&close(target.pan,command.value);label='stem.pan';}
    else if(type==='master_volume'){ok=close(after?.master?.volume,command.value);label='master.volume';}
    else if(type==='bus_volume'){ok=close(bus(after,command.bus)?.volume,command.value);label='bus.volume';}
    else if(type==='bus_mute'){ok=Boolean(bus(after,command.bus)?.muted)===Boolean(command.value);label='bus.muted';}
    else if(type==='metronome'){const m=metronomeState(after);const enabled=command.enabled===undefined?Boolean(m.enabled):Boolean(command.enabled);ok=Boolean(m.enabled)===enabled;label='metronome.enabled';}
    else if(type==='v105_metronome_volume'){
      const beforeM=metronomeState(before);const afterM=metronomeState(after);const expected=Number.isFinite(Number(command.value))?clamp(command.value,0,1):clamp(Number(beforeM.volume||0)+Number(command.delta||0),0,1);ok=close(afterM.volume,expected,.02);label='metronome.volume';
    }
    else if(type==='monitor'){ok=Boolean(after?.monitoring)||buttonState(q('studioMonitorButton'));label='monitoring';}
    else if(type==='arm'){ok=Boolean(document.querySelector(`[data-stem-id="${Number(command.stem_id)}"]`)?.classList.contains('armed'))||buttonState(q('inspectorArm'));label='record-arm';}
    else if(type==='record'){ok=Boolean(after?.recording)||buttonState(q('studioRecordButton'))||buttonState(q('inspectorRecordButton'));label='recording';}
    else if(type==='route'){ok=target&&String(target.route||'')===String(command.route||'direct');label='stem.route';}
    else if(type==='seek'){ok=close(playhead(),command.time,.12);label='playhead';}
    else if(type==='song_duration'){ok=Number(after?.duration_measures||0)===Number(command?.measures||0);label='duration_measures';}
    else if(type==='loop_active'){ok=Boolean(after?.loop?.active)===Boolean(command?.value);label='loop.active';}
    else if(type==='loop_measures'){
      const unit=measureSeconds(after),first=Math.max(1,Number(command?.start_measure||1)),last=Math.max(first,Number(command?.end_measure||first));
      ok=close(after?.loop?.start,(first-1)*unit,.03)&&close(after?.loop?.end,last*unit,.03)&&Boolean(after?.loop?.active)===Boolean(command?.active!==false);label='loop.measure_range';
    }
    else {
      const changed=stableSnapshot(before)!==stableSnapshot(after);
      if(changed)return {...raw,status:'success',verified:true,verification:'state-diff',build:BUILD};
      return result('failed',`${raw.result||type} was not confirmed by a Studio state change`,{verification:'state-diff'});
    }

    if(ok===true){if(desiredAlready(command,before))return result('no_change',raw.result||`${type} was already set`,{verification:label});return {...raw,status:'success',verified:true,verification:label,build:BUILD};}
    return result('failed',`${raw.result||type} was not confirmed by Studio state`,{verification:label});
  }

  function install(){
    const base=stateBridge();if(!base?.getAgentState||!base?.executeAgentCommand||base.__toolTruthV127)return false;
    const originalExecute=base.executeAgentCommand.bind(base);const originalGetState=base.getAgentState.bind(base);const wrapped={...base,__toolTruthV127:true,build:BUILD,getAgentState:originalGetState};
    wrapped.executeAgentCommand=async command=>{
      const type=String(command?.type||'');if(!type)return result('failed','Studio command type is missing');const before=originalGetState()||{};
      if(desiredAlready(command,before))return verify(command,before,result('no_change',`${type} is already in the requested state`));
      let raw=null;try{raw=normalized(await originalExecute(command),type);}catch(error){return result('failed',error?.message||`${type} threw while executing`);}if(!raw)raw=await coreFallback(command,before);return verify(command,before,raw);
    };
    window.StonefellowStemStudioV91=wrapped;window.StonefellowStemToolTruthV127={build:BUILD,bridge:wrapped,snapshot:originalGetState,validStatuses:[...VALID_STATUS]};window.dispatchEvent(new CustomEvent('stonefellow:stem-tool-truth',{detail:{build:BUILD}}));return true;
  }

  if(!install()){let attempts=0;const timer=setInterval(()=>{attempts+=1;if(install()||attempts>=200)clearInterval(timer);},10);}
})();
