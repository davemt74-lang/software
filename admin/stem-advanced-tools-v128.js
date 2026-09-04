(() => {
  'use strict';

  const BUILD='stem-tools-phase2-v128-20260826';
  const ADVANCED=new Set([
    'track_trim','send','route','plugin_picker','plugin_param','plugin_bypass','plugin_remove','aux_return',
    'automation_point','automation_delete','automation_clear',
    'clip_move','clip_trim','clip_gain','clip_fade','clip_mute','clip_split','clip_delete',
    'loop_set','loop_clear','marker_add','region_add','reset_mix','zoom','snap',
    'ui_click','ui_set','ui_select','ui_toggle'
  ]);
  const wait=ms=>new Promise(resolve=>setTimeout(resolve,ms));
  const close=(a,b,eps=.015)=>Math.abs(Number(a||0)-Number(b||0))<=eps;
  const clamp=(value,min,max)=>Math.max(min,Math.min(max,Number(value)||0));
  const q=id=>document.getElementById(id);

  function currentBridge(){return window.StonefellowStemStudioV91||null;}
  function stem(state,id){return (Array.isArray(state?.stems)?state.stems:[]).find(row=>Number(row?.id)===Number(id))||null;}
  function clip(state,id){return (Array.isArray(state?.clips)?state.clips:[]).find(row=>String(row?.id)===String(id))||null;}
  function clipsForStem(state,id){return (Array.isArray(state?.clips)?state.clips:[]).filter(row=>Number(row?.stem_id)===Number(id));}
  function plugins(state,id){return Array.isArray(stem(state,id)?.plugins)?stem(state,id).plugins:[];}
  function automation(state,id,param){const rows=stem(state,id)?.automation?.[String(param||'volume')];return Array.isArray(rows)?rows:[];}
  function pluginType(value){const type=String(value||'').toLowerCase();return type==='eq'?'eq5':type;}
  function pluginFor(state,command){
    const rows=plugins(state,command?.stem_id);const index=Number(command?.plugin_index);
    if(Number.isInteger(index)&&index>=0&&rows[index])return rows[index];
    const wanted=pluginType(command?.plugin_type||command?.plugin);return rows.find(row=>pluginType(row?.type)===wanted)||null;
  }
  function masterAux(state,bus){return Number(bus==='b'?state?.master?.aux_b:state?.master?.aux_a);}
  function control(state,id){return (Array.isArray(state?.controls)?state.controls:[]).find(row=>String(row?.id)===String(id))||null;}
  function result(status,text,verification,extra={}){return {status,result:String(text||''),verified:status==='success'||status==='no_change',verification,build:BUILD,...extra};}
  function fail(raw,type,verification='advanced-state'){return result('failed',`${String(raw?.result||type)} was not confirmed by the requested Studio state`,verification);}
  function stable(value){
    if(Array.isArray(value))return `[${value.map(stable).join(',')}]`;
    if(value&&typeof value==='object')return `{${Object.keys(value).sort().map(key=>`${JSON.stringify(key)}:${stable(value[key])}`).join(',')}}`;
    return JSON.stringify(value);
  }
  function pluginFingerprint(plugin){return stable({type:pluginType(plugin?.type),enabled:Boolean(plugin?.enabled),params:plugin?.params||{}});}
  function pointFingerprint(point){return `${Number(point?.t||0).toFixed(5)}:${Number(point?.v||0).toFixed(5)}`;}
  function mixFingerprint(state){
    return stable({
      stems:(state?.stems||[]).map(row=>({id:Number(row.id),muted:Boolean(row.muted),solo:Boolean(row.solo),volume:Number(row.volume),pan:Number(row.pan),trim:Number(row.trim),send_a:Number(row.send_a),send_b:Number(row.send_b),route:String(row.route||'direct')})),
      buses:(state?.buses||[]).map(row=>({key:String(row.key||''),volume:Number(row.volume),muted:Boolean(row.muted)})),
      master:{volume:Number(state?.master?.volume),aux_a:Number(state?.master?.aux_a),aux_b:Number(state?.master?.aux_b)}
    });
  }
  function playhead(){try{return Number(window.STONEFELLOW_STUDIO_RUNTIME_V87?.getPosition?.()||0);}catch(error){return 0;}}

  function expectedTrim(command,beforeClip){
    const edge=String(command?.edge||'right');const start=Number(beforeClip?.start||0);const length=Math.max(.05,Number(beforeClip?.duration||.05));const end=start+length;
    const duration=Math.max(end,Number(window.STONEFELLOW_STEM_STUDIO?.duration||end));const target=clamp(command?.time,0,duration);
    if(edge==='left'){const next=clamp(target,0,end-.05);return{start:next,end};}
    return{start,end:clamp(target,start+.05,duration)};
  }

  function resetState(after){
    const rows=Array.isArray(after?.stems)?after.stems:[];
    return rows.every(row=>!row?.muted&&!row?.solo);
  }

  function desiredAlready(command,before){
    const type=String(command?.type||'');const s=stem(before,command?.stem_id);const c=clip(before,command?.clip_id);
    if(type==='track_trim')return s&&close(s.trim,command.value,.02);
    if(type==='send')return s&&close(command.bus==='b'?s.send_b:s.send_a,command.value,.02);
    if(type==='route')return s&&String(s.route||'direct')===String(command.route||'direct');
    if(type==='plugin_param'){const p=pluginFor(before,command);return p&&close(p.params?.[String(command.param||'')],command.value,.0005);}
    if(type==='plugin_bypass'){const p=pluginFor(before,command);return p&&Boolean(p.enabled)===!Boolean(command.bypassed);}
    if(type==='aux_return')return close(masterAux(before,command.bus),command.value,.02);
    if(type==='automation_point')return automation(before,command.stem_id,command.parameter).some(p=>close(p.t,command.time,.02)&&close(p.v,command.value,.002));
    if(type==='automation_clear')return automation(before,command.stem_id,command.parameter).length===0;
    if(type==='clip_move')return c&&close(c.start,command.start,.03);
    if(type==='clip_gain')return c&&close(c.gain_db,command.value,.02);
    if(type==='clip_fade'){const value=command.edge==='out'?c?.fade_out:c?.fade_in;return c&&close(value,Math.min(Number(command.value||0),Number(c.duration||0)),.03);}
    if(type==='clip_mute')return c&&Boolean(c.muted)===Boolean(command.value);
    if(type==='loop_set')return Boolean(before?.loop?.active)&&close(before.loop.start,command.start,.03)&&close(before.loop.end,command.end,.03);
    if(type==='loop_clear')return !Boolean(before?.loop?.active);
    if(type==='marker_add')return (before?.markers||[]).some(m=>close(m.time,command.time,.03)&&String(m.label||'')===String(command.label||'Marker'));
    if(type==='region_add')return (before?.regions||[]).some(r=>close(r.start,command.start,.03)&&close(r.end,command.end,.03)&&String(r.label||r.note||'').includes(String(command.label||'')));
    if(type==='zoom')return close(before?.zoom,command.value,.02);
    if(type==='snap')return String(before?.snap||'')===String(command.value||'grid');
    if(type==='ui_toggle'){const c0=control(before,command.control_id);return c0&&Boolean(c0.checked||c0.pressed)===Boolean(command.value);}
    if(type==='ui_set'||type==='ui_select'){const c0=control(before,command.control_id);return c0&&String(c0.value??'')===String(command.value??'');}
    return false;
  }

  async function selectStemForPlugin(stemId,bridge){
    if(Number(bridge.getAgentState?.()?.selected_id||0)===Number(stemId))return true;
    const root=document.querySelector(`[data-stem-id="${Number(stemId)}"]`);const button=root?.querySelector?.('.daw-track-select')||root;
    if(!button?.click)return false;button.click();await wait(30);
    return Number(bridge.getAgentState?.()?.selected_id||0)===Number(stemId);
  }

  async function pluginPickerFallback(command,bridge,before){
    const id=Number(command?.stem_id||0);const wanted=pluginType(command?.plugin);
    if(!id||!stem(before,id))return result('failed','Target stem is unavailable','plugin-picker-target');
    if(!['eq5','compressor','delay','reverb','limiter'].includes(wanted))return result('unsupported',`Plugin type ${wanted||'unknown'} is not available`,'plugin-picker-type');
    if(plugins(before,id).length>=6)return result('failed','That stem already has the maximum six plugins','plugin-picker-limit');
    if(!(await selectStemForPlugin(id,bridge)))return result('failed','Could not select the target stem for plugin insertion','plugin-picker-selection');
    const add=q('inspectorAddPlugin');if(!add?.click)return result('unsupported','The Studio plugin picker is unavailable','plugin-picker-ui');
    add.click();await wait(25);
    const button=document.querySelector(`[data-plugin-type="${CSS.escape(wanted)}"]`);
    if(!button?.click)return result('unsupported',`${wanted} is not available in the Studio plugin directory`,'plugin-picker-ui');
    button.click();await wait(60);
    return {status:'success',result:`${wanted} added to the target stem`,verified:false,verification:'plugin-picker-pending',build:BUILD};
  }

  function exactPluginRemoval(before,after,command){
    const beforeRows=plugins(before,command.stem_id);const afterRows=plugins(after,command.stem_id);const index=Number(command.plugin_index||0);
    if(!beforeRows[index]||afterRows.length!==beforeRows.length-1)return false;
    const expected=beforeRows.filter((_,i)=>i!==index).map(pluginFingerprint);
    return stable(afterRows.map(pluginFingerprint))===stable(expected);
  }

  function exactAutomationDelete(before,after,command){
    const beforeRows=automation(before,command.stem_id,command.parameter);const afterRows=automation(after,command.stem_id,command.parameter);const index=Number(command.index||0);
    if(!beforeRows[index]||afterRows.length!==beforeRows.length-1)return false;
    const expected=beforeRows.filter((_,i)=>i!==index).map(pointFingerprint);
    return stable(afterRows.map(pointFingerprint))===stable(expected);
  }

  function exactClipSplit(before,after,command,splitTime){
    const prior=clip(before,command.clip_id);if(!prior)return false;
    const beforeRows=clipsForStem(before,prior.stem_id);const afterRows=clipsForStem(after,prior.stem_id);
    if(afterRows.length!==beforeRows.length+1)return false;
    const start=Number(prior.start||0),end=start+Number(prior.duration||0);
    if(!(splitTime>start+.02&&splitTime<end-.02))return false;
    const pieces=afterRows.filter(row=>Number(row.start)>=start-.05&&Number(row.start)+Number(row.duration)<=end+.05);
    const left=pieces.some(row=>close(row.start,start,.05)&&close(Number(row.start)+Number(row.duration),splitTime,.08));
    const right=pieces.some(row=>close(row.start,splitTime,.08)&&close(Number(row.start)+Number(row.duration),end,.08));
    const total=pieces.reduce((sum,row)=>sum+Number(row.duration||0),0);
    return left&&right&&close(total,Number(prior.duration||0),.12);
  }

  async function verify(command,before,raw,bridge,meta={}){
    const type=String(command?.type||'');
    if(!raw||['failed','unsupported','cancelled'].includes(String(raw.status||'')))return raw;
    await wait(type==='region_add'?50:25);
    const after=bridge.getAgentState?.()||{};const s=stem(after,command?.stem_id);const c=clip(after,command?.clip_id);let ok=false;let label=type;

    if(type==='track_trim'){ok=s&&close(s.trim,command.value,.02);label='stem.trim';}
    else if(type==='send'){ok=s&&close(command.bus==='b'?s.send_b:s.send_a,command.value,.02);label=`stem.send_${command.bus==='b'?'b':'a'}`;}
    else if(type==='route'){ok=s&&String(s.route||'direct')===String(command.route||'direct');label='stem.route';}
    else if(type==='plugin_picker'){
      const wanted=pluginType(command.plugin);const beforeCount=plugins(before,command.stem_id).filter(p=>pluginType(p.type)===wanted).length;const afterCount=plugins(after,command.stem_id).filter(p=>pluginType(p.type)===wanted).length;
      ok=plugins(after,command.stem_id).length===plugins(before,command.stem_id).length+1&&afterCount===beforeCount+1;label='stem.plugins.added';
    }
    else if(type==='plugin_param'){const p=pluginFor(after,command);ok=Boolean(p)&&close(p.params?.[String(command.param||'')],command.value,.0005);label='plugin.param';}
    else if(type==='plugin_bypass'){const p=pluginFor(after,command);ok=Boolean(p)&&Boolean(p.enabled)===!Boolean(command.bypassed);label='plugin.enabled';}
    else if(type==='plugin_remove'){ok=exactPluginRemoval(before,after,command);label='stem.plugins.removed-exact';}
    else if(type==='aux_return'){ok=close(masterAux(after,command.bus),command.value,.02);label=`master.aux_${command.bus==='b'?'b':'a'}`;}
    else if(type==='automation_point'){ok=automation(after,command.stem_id,command.parameter).some(p=>close(p.t,command.time,.02)&&close(p.v,command.value,.002));label='automation.point';}
    else if(type==='automation_delete'){ok=exactAutomationDelete(before,after,command);label='automation.delete-exact';}
    else if(type==='automation_clear'){ok=automation(after,command.stem_id,command.parameter).length===0;label='automation.clear';}
    else if(type==='clip_move'){
      const duration=Math.max(Number(window.STONEFELLOW_STEM_STUDIO?.duration||0),Number(c?.start||0)+Number(c?.duration||0));const expected=clamp(command.start,0,Math.max(0,duration-Number(c?.duration||0)));ok=c&&close(c.start,expected,.04);label='clip.start';
    }
    else if(type==='clip_gain'){ok=c&&close(c.gain_db,command.value,.02);label='clip.gain_db';}
    else if(type==='clip_fade'){const expected=Math.min(Number(command.value||0),Number(c?.duration||0));ok=c&&close(command.edge==='out'?c.fade_out:c.fade_in,expected,.04);label=`clip.fade_${command.edge==='out'?'out':'in'}`;}
    else if(type==='clip_mute'){ok=c&&Boolean(c.muted)===Boolean(command.value);label='clip.muted';}
    else if(type==='clip_trim'){
      const prior=clip(before,command.clip_id);if(prior&&c){const expected=expectedTrim(command,prior);ok=close(c.start,expected.start,.04)&&close(Number(c.start)+Number(c.duration),expected.end,.05);}label='clip.trim';
    }
    else if(type==='clip_split'){ok=exactClipSplit(before,after,command,Number(meta.splitTime||0));label='clip.split-exact';}
    else if(type==='clip_delete'){ok=Boolean(clip(before,command.clip_id))&&!clip(after,command.clip_id)&&(after.clips||[]).length===(before.clips||[]).length-1;label='clip.deleted';}
    else if(type==='loop_set'){ok=Boolean(after?.loop?.active)&&close(after.loop.start,command.start,.03)&&close(after.loop.end,command.end,.03);label='loop.range';}
    else if(type==='loop_clear'){ok=!Boolean(after?.loop?.active);label='loop.active';}
    else if(type==='marker_add'){const beforeCount=(before.markers||[]).length;ok=(after.markers||[]).length===beforeCount+1&&(after.markers||[]).some(m=>close(m.time,command.time,.03)&&String(m.label||'')===String(command.label||'Marker'));label='marker.added';}
    else if(type==='region_add'){const beforeCount=(before.regions||[]).length;ok=(after.regions||[]).length===beforeCount+1&&(after.regions||[]).some(r=>close(r.start,command.start,.04)&&close(r.end,command.end,.04));label='region.added';}
    else if(type==='reset_mix'){
      const changed=mixFingerprint(before)!==mixFingerprint(after);const neutral=resetState(after);
      if(neutral&&!changed)return result('no_change','Mix was already in the observable reset state','mix.reset-no-change');
      ok=neutral&&changed;label='mix.reset-observed';
    }
    else if(type==='zoom'){ok=close(after?.zoom,command.value,.02);label='timeline.zoom';}
    else if(type==='snap'){ok=String(after?.snap||'')===String(command.value||'grid');label='timeline.snap';}
    else if(type==='ui_toggle'){const a=control(after,command.control_id);ok=Boolean(a)&&Boolean(a.checked||a.pressed)===Boolean(command.value);label='control.toggle';}
    else if(type==='ui_set'||type==='ui_select'){const a=control(after,command.control_id);ok=Boolean(a)&&String(a.value??'')===String(command.value??'');label='control.value';}
    else if(type==='ui_click'){
      const a=control(after,command.control_id),b=control(before,command.control_id);ok=Boolean(a)&&Boolean(b)&&(String(a.value)!==String(b.value)||Boolean(a.checked)!==Boolean(b.checked)||Boolean(a.pressed)!==Boolean(b.pressed));label='control.click-effect';
    }

    if(ok){
      if(desiredAlready(command,before))return result('no_change',raw.result||`${type} was already in the requested state`,label);
      return {...raw,status:'success',verified:true,verification:label,build:BUILD};
    }
    return fail(raw,type,label);
  }

  function install(){
    const base=currentBridge();
    if(!base?.__toolTruthV127||!base?.getAgentState||!base?.executeAgentCommand||base.__advancedTruthV128)return false;
    const originalExecute=base.executeAgentCommand.bind(base);const originalGet=base.getAgentState.bind(base);
    const wrapped={...base,__advancedTruthV128:true,buildAdvanced:BUILD,getAgentState:originalGet};
    wrapped.executeAgentCommand=async command=>{
      const type=String(command?.type||'');
      if(!ADVANCED.has(type))return originalExecute(command);
      const before=originalGet()||{};
      if(desiredAlready(command,before)&&!['plugin_picker','plugin_remove','automation_delete','clip_split','clip_delete','reset_mix','ui_click'].includes(type))return result('no_change',`${type} is already in the requested state`,`${type}.already`);
      const meta={splitTime:type==='clip_split'?playhead():0};
      let raw=await originalExecute(command);
      if(type==='plugin_picker'&&(!raw||['failed','unsupported'].includes(String(raw.status||''))))raw=await pluginPickerFallback(command,wrapped,before);
      return verify(command,before,raw,wrapped,meta);
    };
    window.StonefellowStemStudioV91=wrapped;
    window.StonefellowStemAdvancedToolsV128={build:BUILD,bridge:wrapped,advancedTypes:[...ADVANCED]};
    window.dispatchEvent(new CustomEvent('stonefellow:stem-advanced-tools',{detail:{build:BUILD}}));
    return true;
  }

  if(!install()){
    let attempts=0;const timer=setInterval(()=>{attempts+=1;if(install()||attempts>=200)clearInterval(timer);},10);
  }
})();