(() => {
  'use strict';

  const BUILD='stem-command-bus-v159-20260829';
  const cfg=window.STONEFELLOW_STEM_STUDIO||{};
  const VALID=new Set(['success','failed','unsupported','no_change','cancelled']);
  const proof=window.STONEFELLOW_STEM_COMMAND_BUS_V159={build:BUILD,executed:0,rolledBack:0,lastReceipt:null};
  const clean=value=>String(value??'').trim();
  const lower=value=>clean(value).toLowerCase();
  const clamp=(value,min,max)=>Math.max(min,Math.min(max,Number(value)||0));

  function receipt(status,result,extra={}){
    const value={status:VALID.has(status)?status:'failed',result:clean(result),verified:status==='success'||status==='no_change',build:BUILD,...extra};
    proof.lastReceipt=value;
    return value;
  }
  function bridge(){return window.StonefellowStemStudioV91||null;}
  function state(){try{return bridge()?.getAgentState?.()||{};}catch(error){return {};}}
  function projectRows(){
    return (Array.isArray(cfg.projects)?cfg.projects:[]).map((row,index)=>({
      id:Number(row?.id||0),name:clean(row?.name||`Project ${index+1}`),updated_at:clean(row?.updated_at),url:clean(row?.url)
    })).filter(row=>row.id>0&&row.url);
  }
  async function projectRequest(action,fields={}){
    if(!cfg.projectEndpoint)throw new Error('Studio project storage is unavailable.');
    const form=new FormData();form.append('csrf_token',clean(cfg.csrf));form.append('action',clean(action));
    for(const [key,value] of Object.entries(fields))if(value!==undefined&&value!==null)form.append(key,typeof value==='string'?value:JSON.stringify(value));
    const response=await fetch(clean(cfg.projectEndpoint),{method:'POST',credentials:'same-origin',body:form});
    const payload=await response.json().catch(()=>({ok:false,error:'Invalid Studio project response.'}));
    if(!response.ok||!payload?.ok)throw new Error(payload?.error||`Studio project request failed (${response.status}).`);
    return payload;
  }
  async function atomic(label,task){
    const b=bridge();if(!b?.getMixState||!b?.applyMixState)return receipt('unsupported','The shared Studio edit state is unavailable.');
    const before=b.getMixState();b.beginUndoGroup?.();
    try{return await task(b,before);}
    catch(error){b.applyMixState(before);proof.rolledBack+=1;return receipt('failed',`${label} failed and was rolled back: ${error?.message||error}`);}
    finally{b.endUndoGroup?.();}
  }
  async function runBase(command){
    const b=bridge();if(!b?.executeAgentCommand)return receipt('unsupported','The Studio command bridge is unavailable.');
    const raw=await b.executeAgentCommand(command);
    if(!raw||!VALID.has(clean(raw.status)))return receipt('failed',`${clean(command?.type)||'Command'} returned no valid receipt.`);
    return {...raw,verified:raw.status==='success'||raw.status==='no_change'?raw.verified!==false:Boolean(raw.verified),build:raw.build||BUILD};
  }
  function trackIds(command){
    const available=new Set((state().stems||[]).map(row=>Number(row.id)));
    return [...new Set((Array.isArray(command.stem_ids)?command.stem_ids:[command.stem_id]).map(Number).filter(id=>id>0&&available.has(id)))];
  }

  async function setDuration(command){
    const measures=Math.floor(Number(command.measures||0));
    if(!(measures>0&&measures<=4096))return receipt('failed','Song duration must be between 1 and 4096 measures.');
    return atomic('Song duration',async b=>{
      const local=await runBase({type:'song_duration',measures,extend:command.extend!==false});
      if(!['success','no_change'].includes(local.status))throw new Error(local.result);
      const stored=await projectRequest('update_project_duration',{track_id:Number(cfg.trackId||0),duration_measures:measures});
      if(Number(stored.duration_measures)!==measures)throw new Error('The server did not confirm the requested duration.');
      return receipt('success',`Song duration is ${measures} measures; Track Library samples were extended and the last repeat was trimmed to the endpoint.`,{verification:'duration-and-arrangement',duration_measures:measures,duration_seconds:Number(stored.duration_seconds||0)});
    });
  }
  async function trackState(command){
    const ids=trackIds(command);if(!ids.length)return receipt('failed','No matching track is available.');
    const action=lower(command.action);const exclusive=command.exclusive!==false;
    return atomic('Track control',async()=>{
      const rows=state().stems||[];const results=[];
      if(action==='solo'&&exclusive){
        for(const row of rows)if(!ids.includes(Number(row.id))&&row.solo)results.push(await runBase({type:'unsolo',stem_id:Number(row.id)}));
      }
      for(const id of ids){
        let next={type:action,stem_id:id};
        if(action==='mute'&&command.value===false)next.type='unmute';
        if(action==='solo'&&command.value===false)next.type='unsolo';
        if(action==='volume')next.value=clamp(command.value,0,1.5);
        if(action==='pan')next.value=clamp(command.value,-1,1);
        if(action==='trim'){next.type='track_trim';next.value=clamp(command.value,-24,24);}
        results.push(await runBase(next));
      }
      const failed=results.find(row=>!['success','no_change'].includes(row.status));if(failed)throw new Error(failed.result);
      return receipt('success',`${action} updated on ${ids.length} track${ids.length===1?'':'s'}.`,{verification:'shared-mixer-state',stem_ids:ids});
    });
  }
  async function clearMeasures(command){
    const ids=trackIds(command);if(!ids.length)return receipt('failed','No matching track is available.');
    const first=Math.max(1,Math.floor(Number(command.start_measure||0))),last=Math.max(first,Math.floor(Number(command.end_measure||first)));
    return atomic('Clear measure range',async()=>{
      const raw=await runBase({type:'clear_measure_range',stem_ids:ids,start_measure:first,end_measure:last});
      if(!['success','no_change'].includes(raw.status))throw new Error(raw.result);
      return receipt(raw.status,raw.result,{verification:'non-ripple-arrangement',stem_ids:ids,start_measure:first,end_measure:last});
    });
  }
  async function setLoop(command){
    if(command.active===false&&!command.start_measure)return runBase({type:'loop_active',value:false});
    const first=Math.max(1,Math.floor(Number(command.start_measure||0))),last=Math.max(first,Math.floor(Number(command.end_measure||first)));
    if(!(first>0&&last>=first))return receipt('failed','Loop measures are invalid.');
    return atomic('Loop range',async()=>{
      const raw=await runBase({type:'loop_measures',start_measure:first,end_measure:last,active:command.active!==false});
      if(!['success','no_change'].includes(raw.status))throw new Error(raw.result);
      return receipt('success',`Loop is ${command.active===false?'stored but off':'on'} for measures ${first}-${last}, inclusive.`,{verification:'loop-measure-range'});
    });
  }
  async function history(command){
    const count=Math.max(1,Math.min(20,Math.floor(Number(command.count||1))));const results=[];
    for(let index=0;index<count;index++){
      const raw=await runBase({type:command.type==='v159_redo'?'redo':'undo'});results.push(raw);
      if(raw.status==='no_change')break;
      if(raw.status!=='success')return raw;
    }
    const changed=results.filter(row=>row.status==='success').length;
    return receipt(changed?'success':'no_change',changed?`${command.type==='v159_redo'?'Redid':'Undid'} ${changed} Studio change${changed===1?'':'s'}.`:'There is no available change in that history direction.',{verification:'shared-undo-manager',count:changed});
  }

  async function focusriteInputs(count){
    if(!navigator.mediaDevices?.getUserMedia||!navigator.mediaDevices?.enumerateDevices)throw new Error('This browser cannot inspect audio inputs. Connect Audio first.');
    let permission=null;
    try{
      permission=await navigator.mediaDevices.getUserMedia({audio:{echoCancellation:false,noiseSuppression:false,autoGainControl:false,channelCount:{ideal:64}},video:false});
      const devices=(await navigator.mediaDevices.enumerateDevices()).filter(device=>device.kind==='audioinput'&&/focusrite|scarlett|clarett|vocaster|saffire/i.test(clean(device.label)));
      const unique=[];const seen=new Set();
      for(const device of devices){const key=clean(device.deviceId)||clean(device.groupId)||lower(device.label);if(key&&!seen.has(key)){seen.add(key);unique.push(device);}}
      if(!unique.length)throw new Error('No Focusrite input is visible. Connect the interface and grant microphone access first.');
      const slots=[];
      for(const device of unique){
        let stream=null;
        try{
          stream=await navigator.mediaDevices.getUserMedia({audio:{deviceId:{exact:device.deviceId},echoCancellation:false,noiseSuppression:false,autoGainControl:false,channelCount:{ideal:64}},video:false});
          const track=stream.getAudioTracks()[0];const channels=Math.max(1,Math.min(64,Number(track?.getSettings?.().channelCount||1)));
          for(let channel=1;channel<=channels;channel++)slots.push({device_id:clean(device.deviceId),input_label:clean(track?.label||device.label),input_channel:channel});
        }catch(error){}
        finally{stream?.getTracks?.().forEach(track=>track.stop());}
      }
      if(slots.length<count)throw new Error(`The browser exposed ${slots.length} distinct Focusrite input${slots.length===1?'':'s'}, but ${count} were requested.`);
      return slots.slice(0,count);
    } finally {permission?.getTracks?.().forEach(track=>track.stop());}
  }
  async function createEmptyTracks(command){
    const count=Math.floor(Number(command.count||0));if(!(count>0&&count<=64))return receipt('failed','Track count must be between 1 and 64.');
    const role=clean(command.role||'Other').slice(0,32)||'Other';let inputs=[];
    try{if(lower(command.input_provider)==='focusrite')inputs=await focusriteInputs(count);}catch(error){return receipt('failed',error?.message||error,{verification:'focusrite-preflight'});}
    const specs=Array.from({length:count},(_,index)=>({
      track_name:clean(command.base_name||role||'Audio')+' '+(index+1),stem_role:role,
      input_device_id:clean(inputs[index]?.device_id),input_label:clean(inputs[index]?.input_label),input_channel:Number(inputs[index]?.input_channel||1)
    }));
    try{
      const data=await projectRequest('create_empty_tracks',{track_id:Number(cfg.trackId||0),tracks_json:specs});
      const created=Array.isArray(data.created)?data.created:[];
      if(created.length!==count||created.some(row=>!(Number(row.stem_id)>0)))throw new Error('The server did not verify every requested empty track.');
      return receipt('success',`Created ${count} empty ${role.toLowerCase()} track${count===1?'':'s'}${inputs.length?' with distinct Focusrite inputs':''}.`,{verification:'persistent-empty-tracks',created,redirect:clean(data.redirect||`/admin/stems.php?track=${Number(cfg.trackId||0)}`)});
    }catch(error){return receipt('failed',error?.message||error);}
  }

  async function saveVersion(command){
    const b=bridge();if(!b?.mixRequest||!b?.collectMixState)return receipt('unsupported','Saved project versions are unavailable.');
    try{
      const selected=b.getSelectedMix?.()||{};const saveAs=command.type==='v159_save_as';
      const name=clean(command.name||(saveAs?'New Version':selected.name||cfg.projectTitle||'Studio Version')).slice(0,120)||'Studio Version';
      const id=saveAs?0:Number(selected.id||0);
      const data=await b.mixRequest('save',{mix_id:id,mix_name:name,state:b.collectMixState()});
      if(!(Number(data.mix_id)>0))throw new Error('The server did not return a saved version id.');
      b.setSelectedMixRef?.(Number(data.mix_id),clean(data.mix_name||name));
      return receipt('success',`${saveAs?'Saved new version':'Saved project'} “${clean(data.mix_name||name)}”.`,{verification:'saved-version',mix_id:Number(data.mix_id)});
    }catch(error){return receipt('failed',error?.message||error);}
  }
  async function versions(command){
    const b=bridge();if(!b?.mixRequest)return receipt('unsupported','Saved project versions are unavailable.');
    try{
      const listed=await b.mixRequest('list');const rows=Array.isArray(listed.mixes)?listed.mixes:[];
      if(command.type==='v159_list_versions')return receipt('success',rows.length?rows.map((row,index)=>`${index+1}. ${row.mix_name} (${row.updated_at})`).join('; '):'No saved project versions yet.',{verification:'saved-version-list',versions:rows});
      let match=null;const id=Number(command.mix_id||0),name=lower(command.name),which=lower(command.which);
      if(id>0)match=rows.find(row=>Number(row.id)===id);
      else if(name)match=rows.find(row=>lower(row.mix_name)===name)||rows.find(row=>lower(row.mix_name).includes(name));
      else if(which==='previous'){
        const currentId=Number(b.getSelectedMix?.().id||0),currentIndex=rows.findIndex(row=>Number(row.id)===currentId);
        match=rows[currentIndex>=0?currentIndex+1:1]||null;
      }else match=rows[0]||null;
      if(!match)return receipt('failed','That saved project version was not found.');
      return atomic('Load project version',async()=>{
        const loaded=await b.mixRequest('load',{mix_id:Number(match.id)});if(!loaded?.mix?.state)throw new Error('The saved version has no valid Studio state.');
        b.applyMixState(loaded.mix.state);b.setSelectedMixRef?.(Number(loaded.mix.id),clean(loaded.mix.mix_name));
        if(loaded.mix.state.durationMeasuresDefined!==false&&Number(loaded.mix.state.durationMeasures)>0){
          await projectRequest('update_project_duration',{track_id:Number(cfg.trackId||0),duration_measures:Number(loaded.mix.state.durationMeasures)});
        }
        return receipt('success',`Loaded project version “${clean(loaded.mix.mix_name)}”.`,{verification:'loaded-version',mix_id:Number(loaded.mix.id)});
      });
    }catch(error){return receipt('failed',error?.message||error);}
  }
  function projects(command){
    const rows=projectRows();
    if(command.type==='v159_list_projects')return receipt('success',rows.length?rows.map((row,index)=>`${index+1}. ${row.name}`).join('; '):'No projects are available.',{verification:'project-list',projects:rows});
    const current=Number(cfg.trackId||0);const others=rows.filter(row=>row.id!==current);let match=null;
    const id=Number(command.track_id||0),name=lower(command.name),which=lower(command.which);
    if(id>0)match=rows.find(row=>row.id===id);
    else if(name)match=rows.find(row=>lower(row.name)===name)||rows.find(row=>lower(row.name).includes(name));
    else match=others[which==='previous'?1:0]||others[0]||null;
    return match?receipt('success',`Opening project “${match.name}”.`,{verification:'project-navigation',track_id:match.id,redirect:match.url}):receipt('failed','That project was not found.');
  }
  async function renameProject(command){
    const name=clean(command.name).slice(0,190);if(!name)return receipt('failed','A new project name is required.');
    try{const data=await projectRequest('rename_project',{track_id:Number(cfg.trackId||0),project_name:name});return receipt('success',`Renamed project to “${clean(data.project_name||name)}”.`,{verification:'persistent-project-name',redirect:`/admin/stems.php?track=${Number(cfg.trackId||0)}`});}
    catch(error){return receipt('failed',error?.message||error);}
  }
  async function newProject(command){
    try{const data=await projectRequest('create_project',{project_name:clean(command.name||'Untitled Project')||'Untitled Project',tempo_bpm:clamp(command.tempo_bpm||120,40,300),time_signature:clean(command.time_signature||'4/4')});if(!(Number(data.track_id)>0)||!clean(data.redirect))throw new Error('The server did not verify the new project.');return receipt('success',`Created new project “${clean(command.name||'Untitled Project')}”.`,{verification:'persistent-new-project',track_id:Number(data.track_id),redirect:clean(data.redirect)});}
    catch(error){return receipt('failed',error?.message||error);}
  }

  const handlers={
    v159_set_duration:setDuration,v159_track_state:trackState,v159_clear_measures:clearMeasures,v159_loop_measures:setLoop,
    v159_undo:history,v159_redo:history,v159_create_empty_tracks:createEmptyTracks,
    v159_save:saveVersion,v159_save_as:saveVersion,v159_list_versions:versions,v159_load_version:versions,
    v159_list_projects:projects,v159_load_project:projects,v159_rename_project:renameProject,v159_new_project:newProject
  };
  function install(){
    const base=bridge();if(!base?.executeAgentCommand||base.__commandBusV159)return false;
    const original=base.executeAgentCommand.bind(base);const wrapped={...base,__commandBusV159:true,buildV159:BUILD};
    wrapped.executeAgentCommand=async command=>{
      const type=clean(command?.type);const handler=handlers[type];
      if(!handler)return original(command);
      proof.executed+=1;
      try{return await handler(command);}catch(error){return receipt('failed',error?.message||`${type} failed.`);}
    };
    window.StonefellowStemStudioV91=wrapped;
    window.dispatchEvent(new CustomEvent('stonefellow:stem-command-bus',{detail:{build:BUILD}}));
    return true;
  }
  if(!install()){let attempts=0;const timer=setInterval(()=>{attempts+=1;if(install()||attempts>=200)clearInterval(timer);},10);}
})();
