(function(root,factory){
  'use strict';
  const api=factory();
  root.StonefellowStemRecordingEngineV213=api;
  if(root.document)api.install(root,root.document);
})(typeof globalThis!=='undefined'?globalThis:window,function(){
  'use strict';

  const BUILD='stem-recording-engine-v213-20260901';
  const TAKE_RE=/\s+(?:·\s*)?Take\s+(\d+)\s*$/i;

  const num=(value,fallback=0)=>Number.isFinite(Number(value))?Number(value):fallback;
  const clone=value=>JSON.parse(JSON.stringify(value));

  function baseTakeName(name){
    const clean=String(name||'').trim();
    return (clean.replace(TAKE_RE,'').trim()||clean||'Audio');
  }

  function normalizeRecordingTarget(stem){
    if(!stem)return {targetId:0,parentId:0,name:'Audio',role:'Other',archive:false};
    const id=Math.max(0,Number(stem.id||0));
    const parentId=Math.max(0,Number(stem.takeOfStemId||0))||id;
    return {
      targetId:id,
      parentId,
      name:baseTakeName(stem.name||stem.label||'Audio'),
      role:String(stem.role||'Other'),
      archive:!Boolean(stem.isEmptyRecordingTrack)
    };
  }

  function parseTakeIds(value){
    return [...new Set(String(value||'').split(',').map(item=>Number(item)).filter(item=>item>0))];
  }

  function validRange(range){
    if(!range)return null;
    const start=Math.max(0,num(range.start,0));
    const end=Math.max(start,num(range.end,0));
    return end>start+.01?{start,end}:null;
  }

  function muteStemClips(state,stemIds,value=true){
    const wanted=new Set((stemIds||[]).map(Number));
    const next=clone(state||{});
    let changed=0;
    const rows=Array.isArray(next.stems)
      ? next.stems
      : Object.values(next.stems||{});
    rows.forEach(stem=>{
      if(!wanted.has(Number(stem?.id||0)))return;
      (stem.clips||[]).forEach(clip=>{
        if(Boolean(clip.muted)!==Boolean(value))changed+=1;
        clip.muted=Boolean(value);
      });
    });
    return {state:next,changed};
  }

  function install(root,document){
    if(!document||root.__STONEFELLOW_STEM_V213_INSTALLED__)return false;
    root.__STONEFELLOW_STEM_V213_INSTALLED__=true;

    const cfg=root.STONEFELLOW_STEM_STUDIO||{};
    const ext=root.STONEFELLOW_STEM_RECORDING_V213||{};
    if(!cfg.projectEndpoint||!ext.endpoint){
      root.__STONEFELLOW_STEM_V213_INSTALLED__=false;
      return false;
    }

    const nativeFetch=root.fetch.bind(root);
    const projectUrl=new URL(String(cfg.projectEndpoint),root.location.href).href;
    const endpointUrl=new URL(String(ext.endpoint),root.location.href).href;
    const muteKey=`stonefellow:stem:v213:mute:${Number(cfg.userId||0)}:${Number(cfg.trackId||0)}`;
    const compositeKey=`stonefellow:stem:v213:composite:${Number(cfg.userId||0)}:${Number(cfg.trackId||0)}`;
    const pendingArchives=new Map();
    let loopSession=null;
    let toolbar=null;
    let observer=null;
    let autoSaving=false;
    let bindAttempts=0;

    const core=()=>root.STONEFELLOW_STUDIO_RUNTIME_V87||null;
    const studio=()=>root.StonefellowStemStudioV91||null;
    const v210=()=>root.StonefellowStemProfessionalEditingV210Runtime||null;
    const v210Api=()=>root.StonefellowStemProfessionalEditingV210||null;
    const takes=()=>root.StonefellowStemRecordingTakesV212Runtime||null;
    const state=()=>{try{return studio()?.getAgentState?.()||{};}catch(error){return{};}};

    function loadMuteIds(){
      try{return parseTakeIds(root.localStorage?.getItem(muteKey)||'');}
      catch(error){return[];}
    }
    function saveMuteIds(ids){
      const clean=parseTakeIds((ids||[]).join(','));
      try{
        if(clean.length)root.localStorage?.setItem(muteKey,clean.join(','));
        else root.localStorage?.removeItem(muteKey);
      }catch(error){}
      return clean;
    }
    function addMuteId(id){
      const next=loadMuteIds();
      if(Number(id)>0)next.push(Number(id));
      return saveMuteIds(next);
    }
    function saveComposite(value){
      try{
        if(value)root.localStorage?.setItem(compositeKey,JSON.stringify(value));
        else root.localStorage?.removeItem(compositeKey);
      }catch(error){}
    }
    function loadComposite(){
      try{
        const value=JSON.parse(root.localStorage?.getItem(compositeKey)||'null');
        return value&&typeof value==='object'?value:null;
      }catch(error){return null;}
    }

    function isProjectRequest(input,init){
      if(!(init?.body instanceof root.FormData))return false;
      try{return new URL(typeof input==='string'?input:input?.url||'',root.location.href).href===projectUrl;}
      catch(error){return false;}
    }

    function formAction(form){return String(form?.get?.('action')||'');}

    async function responseJson(response){
      try{return await response.clone().json();}
      catch(error){return null;}
    }

    function errorResponse(message,status=409){
      return new root.Response(
        JSON.stringify({ok:false,error:String(message||'Recording take preparation failed.')}),
        {status,headers:{'Content-Type':'application/json; charset=utf-8','Cache-Control':'no-store'}}
      );
    }

    async function v213Request(action,fields={}){
      const form=new root.FormData();
      form.append('csrf_token',String(cfg.csrf||''));
      form.append('action',action);
      form.append('track_id',String(cfg.trackId||0));
      Object.entries(fields).forEach(([key,value])=>{
        if(value!==undefined&&value!==null)form.append(key,String(value));
      });
      const response=await nativeFetch(endpointUrl,{method:'POST',credentials:'same-origin',body:form});
      const data=await response.json().catch(()=>({ok:false,error:'Invalid v213 recording response.'}));
      if(!response.ok||!data.ok)throw new Error(data.error||`Recording take request failed (${response.status}).`);
      return data;
    }

    async function cancelNativeRecording(recordingId){
      if(!recordingId)return;
      const form=new root.FormData();
      form.append('csrf_token',String(cfg.csrf||''));
      form.append('action','recording_cancel');
      form.append('track_id',String(cfg.trackId||0));
      form.append('recording_id',String(recordingId));
      try{await nativeFetch(projectUrl,{method:'POST',credentials:'same-origin',body:form});}catch(error){}
    }

    function resolveParent(stemId){
      const selected=core()?.getStem?.(Number(stemId||0));
      if(!selected)return null;
      const normalized=normalizeRecordingTarget(selected);
      const parent=core()?.getStem?.(normalized.parentId)||selected;
      return {...normalizeRecordingTarget(parent),selected,parent,parentId:Number(parent.id||normalized.parentId||0),targetId:Number(selected.id||0)};
    }

    function punchRange(){
      const punch=document.getElementById('recordPunchToggle');
      const enabled=Boolean(punch&&(punch.classList.contains('active')||punch.getAttribute('aria-pressed')==='true'));
      if(!enabled)return null;
      const agent=state();
      const loop=agent.loop||{};
      const loopRange=validRange(loop.active?loop:null);
      if(loopRange)return loopRange;
      const editRange=validRange(v210()?.getRange?.());
      if(editRange)return editRange;
      const selected=String(agent.selected_clip_id||'');
      const clip=(agent.clips||[]).find(item=>String(item?.id||'')===selected);
      return clip?validRange({start:num(clip.start,0),end:num(clip.start,0)+num(clip.duration,0)}):null;
    }

    async function prepareArchive(recordingId,parentInfo){
      if(!recordingId||!parentInfo?.archive||parentInfo.parentId<1)return null;
      const data=await v213Request('prepare_take',{recording_id:recordingId,target_stem_id:parentInfo.parentId});
      if(!data.archive_required)return null;
      const archive={
        recordingId:String(recordingId),
        takeId:Number(data.take_stem_id||0),
        parentId:Number(data.parent_stem_id||parentInfo.parentId||0),
        takeName:String(data.take_name||''),
        range:punchRange()
      };
      if(archive.takeId>0)pendingArchives.set(String(recordingId),archive);
      return archive;
    }

    async function cleanupArchive(recordingId){
      const archive=pendingArchives.get(String(recordingId||''));
      if(!archive)return;
      pendingArchives.delete(String(recordingId));
      try{await v213Request('cleanup_take',{recording_id:archive.recordingId,take_stem_id:archive.takeId});}
      catch(error){console.warn('Stem v213 provisional take cleanup failed.',error);}
    }

    async function commitArchive(recordingId){
      const archive=pendingArchives.get(String(recordingId||''));
      if(!archive)return null;
      pendingArchives.delete(String(recordingId));
      await v213Request('commit_take',{recording_id:archive.recordingId,take_stem_id:archive.takeId});
      addMuteId(archive.takeId);
      if(loopSession){
        loopSession.takeIds.push(archive.takeId);
        if(!loopSession.backingTakeId)loopSession.backingTakeId=archive.takeId;
        if(!loopSession.parentId)loopSession.parentId=archive.parentId;
      }else if(archive.range){
        saveComposite({parentId:archive.parentId,backingTakeId:archive.takeId,range:archive.range});
      }
      return archive;
    }

    function scheduleReload(delay=650){
      root.setTimeout(()=>root.location.reload(),delay);
    }

    async function projectFetch(input,init){
      if(!isProjectRequest(input,init))return nativeFetch(input,init);
      const form=init.body;
      const action=formAction(form);

      if(action==='recording_start'){
        const requested=Number(form.get('target_stem_id')||0);
        const parentInfo=requested>0?resolveParent(requested):null;
        if(parentInfo?.parentId>0){
          form.set('target_stem_id',String(parentInfo.parentId));
          form.set('track_name',baseTakeName(parentInfo.parent.name||parentInfo.parent.label||parentInfo.name));
          form.set('stem_role',String(parentInfo.parent.role||parentInfo.role||'Other'));
        }
        const response=await nativeFetch(input,init);
        const data=await responseJson(response);
        const recordingId=String(data?.recording_id||'');
        if(response.ok&&data?.ok&&recordingId&&parentInfo?.archive){
          try{
            const archive=await prepareArchive(recordingId,parentInfo);
            if(loopSession&&parentInfo.parentId>0){
              loopSession.parentId=parentInfo.parentId;
              if(archive&&!loopSession.backingTakeId)loopSession.backingTakeId=archive.takeId;
            }
          }catch(error){
            await cancelNativeRecording(recordingId);
            stopLoopSession(error?.message||'Could not preserve the current take.');
            return errorResponse(error?.message||'Could not preserve the current take before recording.');
          }
        }else if(loopSession&&parentInfo?.parentId>0){
          loopSession.parentId=parentInfo.parentId;
        }
        return response;
      }

      if(action==='recording_cancel'){
        const recordingId=String(form.get('recording_id')||'');
        const response=await nativeFetch(input,init);
        await cleanupArchive(recordingId);
        if(loopSession)stopLoopSession('Loop takes cancelled.');
        return response;
      }

      if(action==='recording_finish'){
        const recordingId=String(form.get('recording_id')||'');
        const response=await nativeFetch(input,init);
        const data=await responseJson(response);
        if(response.ok&&data?.ok){
          try{await commitArchive(recordingId);}
          catch(error){console.error('Stem v213 take commit failed.',error);}
          if(loopSession){
            root.setTimeout(()=>finishLoopPass(),260);
          }else if(loadMuteIds().length||loadComposite()){
            scheduleReload();
          }
        }else{
          await cleanupArchive(recordingId);
          if(loopSession)stopLoopSession(data?.error||'Loop take save failed.');
        }
        return response;
      }

      return nativeFetch(input,init);
    }

    root.fetch=projectFetch;

    function setStatus(message,kind=''){
      let node=document.getElementById('stemV213Status');
      if(!node){
        node=document.createElement('div');
        node.id='stemV213Status';
        node.className='sf-v213-status';
        document.body.appendChild(node);
      }
      node.textContent=String(message||'');
      node.classList.toggle('error',kind==='error');
      node.classList.add('show');
      root.clearTimeout(node._hideTimer);
      node._hideTimer=root.setTimeout(()=>node.classList.remove('show'),2200);
    }

    function saveDialogOpen(){
      const dialog=document.getElementById('recordingSaveDialog');
      if(!dialog)return false;
      if(dialog.hidden)return false;
      if(dialog.getAttribute('aria-hidden')==='true')return false;
      return dialog.classList.contains('open')||dialog.classList.contains('active')||dialog.getAttribute('open')!==null||getComputedStyle(dialog).display!=='none';
    }

    function ensureParentArmed(){
      const id=Number(loopSession?.parentId||0);
      if(id<1)return;
      const stem=core()?.getStem?.(id);
      const buttons=[stem?.armButton,stem?.sidebarArmButton].filter(Boolean);
      const active=buttons.some(button=>button.classList.contains('active')||button.getAttribute('aria-pressed')==='true');
      if(!active)buttons[0]?.click();
    }

    function startCorePass(){
      if(!loopSession?.active)return false;
      const record=document.getElementById('studioRecordButton');
      if(!record||record.disabled){
        root.setTimeout(startCorePass,120);
        return false;
      }
      ensureParentArmed();
      loopSession.waitingForSave=true;
      loopSession.passStartedAt=Date.now();
      record.click();
      updateLoopUi();
      return true;
    }

    function maybeAutoSave(){
      if(!loopSession?.active||!loopSession.waitingForSave||autoSaving||!saveDialogOpen())return;
      const save=document.getElementById('saveRecordingButton');
      const name=document.getElementById('recordingSaveName');
      if(!save||save.disabled)return;
      const parent=core()?.getStem?.(Number(loopSession.parentId||0));
      if(name&&parent){
        const clean=baseTakeName(parent.name||parent.label||'Audio');
        if(clean)name.value=clean;
      }
      autoSaving=true;
      loopSession.waitingForSave=false;
      root.setTimeout(()=>{
        save.click();
        root.setTimeout(()=>{autoSaving=false;},500);
      },90);
    }

    function finishLoopPass(){
      if(!loopSession?.active)return;
      loopSession.completed+=1;
      loopSession.waitingForSave=false;
      updateLoopUi();
      if(loopSession.stopRequested||loopSession.completed>=loopSession.total){
        const finished={...loopSession,takeIds:[...loopSession.takeIds]};
        loopSession=null;
        if(finished.range&&finished.backingTakeId&&finished.parentId){
          saveComposite({parentId:finished.parentId,backingTakeId:finished.backingTakeId,range:finished.range});
        }
        setStatus(`Loop takes complete · ${finished.completed} pass${finished.completed===1?'':'es'}`);
        updateLoopUi();
        scheduleReload(720);
        return;
      }
      root.setTimeout(()=>startCorePass(),420);
    }

    function stopLoopSession(message='Loop takes stopped.'){
      if(!loopSession)return;
      loopSession.active=false;
      loopSession=null;
      autoSaving=false;
      setStatus(message,'error');
      updateLoopUi();
    }

    function beginLoopSession(){
      if(loopSession?.active){
        loopSession.stopRequested=true;
        setStatus('Loop takes will stop after this pass.');
        updateLoopUi();
        return false;
      }
      const agent=state();
      const range=validRange(agent.loop?.active?agent.loop:null);
      if(!range){
        setStatus('Set an active loop range before starting loop takes.','error');
        return false;
      }
      if(core()?.isCoreRecording?.()){
        setStatus('Stop the current recording first.','error');
        return false;
      }
      const passes=Math.max(2,Math.min(8,Number(toolbar?.querySelector('[data-v213-pass-count]')?.value||3)));
      loopSession={active:true,total:passes,completed:0,parentId:0,backingTakeId:0,takeIds:[],range,waitingForSave:false,stopRequested:false,passStartedAt:0};
      const punch=document.getElementById('recordPunchFromLoop');
      if(!punch){
        stopLoopSession('Punch-from-loop control is unavailable.');
        return false;
      }
      punch.click();
      setStatus(`Loop takes · ${passes} passes`);
      updateLoopUi();
      root.setTimeout(()=>startCorePass(),100);
      return true;
    }

    function buildToolbar(){
      const host=document.querySelector('[data-v212-toolbar]')||document.querySelector('.daw-mixer-toolbar');
      if(!host)return false;
      if(host.querySelector('[data-v213-loop-takes]')){
        toolbar=host.querySelector('[data-v213-loop-takes]');
        return true;
      }
      toolbar=document.createElement('div');
      toolbar.className='sf-v213-loop-takes';
      toolbar.dataset.v213LoopTakes=BUILD;
      toolbar.innerHTML=`<span>LOOP REC</span><select data-v213-pass-count aria-label="Loop recording pass count"><option value="2">2 PASSES</option><option value="3" selected>3 PASSES</option><option value="4">4 PASSES</option><option value="8">8 PASSES</option></select><button type="button" data-v213-start>START TAKES</button><strong data-v213-progress>READY</strong>`;
      host.appendChild(toolbar);
      toolbar.querySelector('[data-v213-start]').addEventListener('click',beginLoopSession);
      updateLoopUi();
      return true;
    }

    function updateLoopUi(){
      if(!toolbar)return;
      const button=toolbar.querySelector('[data-v213-start]');
      const progress=toolbar.querySelector('[data-v213-progress]');
      const count=toolbar.querySelector('[data-v213-pass-count]');
      if(loopSession?.active){
        if(button)button.textContent=loopSession.stopRequested?'STOPPING…':'STOP AFTER PASS';
        if(progress)progress.textContent=`${Math.min(loopSession.completed+1,loopSession.total)}/${loopSession.total}`;
        if(count)count.disabled=true;
        toolbar.classList.add('active');
      }else{
        if(button)button.textContent='START TAKES';
        if(progress)progress.textContent='READY';
        if(count)count.disabled=false;
        toolbar.classList.remove('active');
      }
    }

    function familyIds(parentId){
      const family=(takes()?.getFamilies?.()||[]).find(item=>Number(item.parentId)===Number(parentId));
      if(family)return family.members.map(member=>Number(member.id)).filter(id=>id>0);
      const ids=[Number(parentId)];
      (state().stems||[]).forEach(item=>{
        const stem=core()?.getStem?.(Number(item.id||0));
        if(Number(stem?.takeOfStemId||0)===Number(parentId))ids.push(Number(item.id));
      });
      return [...new Set(ids.filter(id=>id>0))];
    }

    function applyInitialTakeState(){
      const live=studio();
      const mix=live?.getMixState?.();
      if(!live||!mix)return false;
      const muteIds=loadMuteIds();
      const composite=loadComposite();
      let next=clone(mix);
      let changed=0;

      if(muteIds.length){
        const muted=muteStemClips(next,muteIds,true);
        next=muted.state;
        changed+=muted.changed;
      }

      if(composite?.parentId&&composite?.backingTakeId&&validRange(composite.range)){
        const backing=muteStemClips(next,[Number(composite.backingTakeId)],false);
        next=backing.state;
        changed+=backing.changed;
        const ids=familyIds(Number(composite.parentId));
        const result=v210Api()?.compTakeRange?.(
          next,
          ids,
          Number(composite.parentId),
          Number(composite.range.start),
          Number(composite.range.end),
          {idFactory:(base,index)=>`${base||'clip'}-v213-${index}-${Date.now().toString(36)}`}
        );
        if(result?.state){next=result.state;changed+=Number(result.changed||0);}
      }

      if(changed>0){
        live.applyMixState?.(next);
        root.dispatchEvent(new CustomEvent('stonefellow:stem-recording-v213-state',{detail:{build:BUILD,changed,muteIds,composite}}));
      }
      return changed>0;
    }

    function clearInitialStateOnEdit(event){
      const action=String(event?.detail?.action||'');
      if(action==='take_comp'||action.startsWith('clip_')||action==='take_comp'){
        saveMuteIds([]);
        saveComposite(null);
      }
    }

    function lateBind(){
      if(!core()||!studio()||!v210Api()||!buildToolbar()){
        bindAttempts+=1;
        if(bindAttempts<240)root.setTimeout(lateBind,60);
        else root.__STONEFELLOW_STEM_V213_INSTALLED__=false;
        return;
      }
      root.setTimeout(applyInitialTakeState,180);
      observer=new MutationObserver(()=>{
        maybeAutoSave();
        updateLoopUi();
      });
      observer.observe(document.getElementById('stemStudio')||document.body,{childList:true,subtree:true,attributes:true,attributeFilter:['class','hidden','aria-hidden','disabled']});
      root.addEventListener('stonefellow:stem-edit-v210',clearInitialStateOnEdit);
      root.addEventListener('stonefellow:stem-edit-v209',clearInitialStateOnEdit);
      root.dispatchEvent(new CustomEvent('stonefellow:stem-recording-engine-v213',{detail:{build:BUILD}}));
    }
    lateBind();

    root.addEventListener('pagehide',()=>{
      observer?.disconnect();
      root.fetch=nativeFetch;
    },{once:true});

    root.StonefellowStemRecordingEngineV213Runtime={
      build:BUILD,
      beginLoopSession,
      stopLoopSession,
      getLoopSession:()=>loopSession?clone(loopSession):null,
      getPendingArchives:()=>[...pendingArchives.values()].map(clone),
      applyInitialTakeState,
      getMuteIds:loadMuteIds,
      getComposite:loadComposite
    };
    return true;
  }

  return Object.freeze({
    build:BUILD,
    baseTakeName,
    normalizeRecordingTarget,
    parseTakeIds,
    validRange,
    muteStemClips,
    install
  });
});
