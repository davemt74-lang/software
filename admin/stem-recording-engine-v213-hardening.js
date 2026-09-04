(function(root){
  'use strict';

  const BUILD='stem-recording-engine-v213-hardening-20260901';
  if(!root.document||root.__STONEFELLOW_STEM_V213_HARDENING__)return;
  root.__STONEFELLOW_STEM_V213_HARDENING__=true;

  const document=root.document;
  const cfg=root.STONEFELLOW_STEM_STUDIO||{};
  const ext=root.STONEFELLOW_STEM_RECORDING_V213||{};
  const muteKey=`stonefellow:stem:v213:mute:${Number(cfg.userId||0)}:${Number(cfg.trackId||0)}`;
  let attempts=0;
  let observer=null;
  let saveWasOpen=false;
  let externalSaving=false;
  let reconcileBusy=false;

  function parseIds(value){
    return [...new Set(String(value||'').split(',').map(Number).filter(id=>id>0))];
  }

  function getMixRows(mix){
    if(Array.isArray(mix?.stems))return mix.stems;
    return Object.values(mix?.stems||{});
  }

  function familyNeedsMute(mix,family){
    const rows=getMixRows(mix);
    const byId=new Map(rows.map(stem=>[Number(stem?.id||0),stem]));
    const members=Array.isArray(family?.members)?family.members:[];
    if(members.length<2)return [];
    const familyRows=members.map(member=>byId.get(Number(member?.id||0))).filter(Boolean);
    if(familyRows.length!==members.length)return [];
    const pristine=familyRows.every(stem=>{
      const clips=Array.isArray(stem?.clips)?stem.clips:[];
      return clips.length===1&&clips.every(clip=>!Boolean(clip?.muted));
    });
    return pristine
      ? members.filter(member=>!member?.isParent).map(member=>Number(member.id||0)).filter(id=>id>0)
      : [];
  }

  function initializeLegacyTakeMutes(){
    const studio=root.StonefellowStemStudioV91;
    const takes=root.StonefellowStemRecordingTakesV212Runtime;
    const runtime=root.StonefellowStemRecordingEngineV213Runtime;
    const mix=studio?.getMixState?.();
    const families=takes?.getFamilies?.()||[];
    if(!mix||!families.length)return false;

    const inferred=families.flatMap(family=>familyNeedsMute(mix,family));
    if(!inferred.length)return false;
    let existing=[];
    try{existing=parseIds(root.localStorage?.getItem(muteKey)||'');}catch(error){}
    const next=[...new Set([...existing,...inferred])];
    try{root.localStorage?.setItem(muteKey,next.join(','));}catch(error){}
    runtime?.applyInitialTakeState?.();
    return true;
  }

  function saveDialogOpen(){
    const dialog=document.getElementById('recordingSaveDialog');
    if(!dialog||dialog.hidden||dialog.getAttribute('aria-hidden')==='true')return false;
    return dialog.classList.contains('open')
      ||dialog.classList.contains('active')
      ||dialog.getAttribute('open')!==null
      ||root.getComputedStyle(dialog).display!=='none';
  }

  function autoSaveLoopPass(){
    const runtime=root.StonefellowStemRecordingEngineV213Runtime;
    const session=runtime?.getLoopSession?.();
    if(!session?.active||!session.waitingForSave||externalSaving||!saveDialogOpen())return;
    const save=document.getElementById('saveRecordingButton');
    if(!save||save.disabled)return;
    const name=document.getElementById('recordingSaveName');
    const parent=root.STONEFELLOW_STUDIO_RUNTIME_V87?.getStem?.(Number(session.parentId||0));
    if(name&&parent){
      const clean=String(parent.name||parent.label||'Audio').replace(/\s+(?:·\s*)?Take\s+\d+\s*$/i,'').trim();
      if(clean)name.value=clean;
    }
    externalSaving=true;
    root.setTimeout(()=>{
      if(!save.disabled&&saveDialogOpen())save.click();
      root.setTimeout(()=>{externalSaving=false;},650);
    },80);
  }

  async function reconcile(){
    if(reconcileBusy||!ext.endpoint||!cfg.csrf||!(Number(cfg.trackId)>0))return null;
    reconcileBusy=true;
    try{
      const form=new root.FormData();
      form.append('csrf_token',String(cfg.csrf));
      form.append('action','reconcile');
      form.append('track_id',String(cfg.trackId));
      const response=await root.fetch(String(ext.endpoint),{method:'POST',credentials:'same-origin',body:form});
      const data=await response.json().catch(()=>null);
      if(!response.ok||!data?.ok)return null;
      return data;
    }catch(error){
      console.warn('Stem v213 archive reconciliation skipped.',error);
      return null;
    }finally{
      reconcileBusy=false;
    }
  }

  async function reconcileAfterSave(){
    const result=await reconcile();
    if(Number(result?.finalized||0)>0){
      root.setTimeout(()=>root.location.reload(),420);
    }
  }

  function mutationTick(){
    const open=saveDialogOpen();
    if(open)autoSaveLoopPass();
    if(saveWasOpen&&!open)void reconcileAfterSave();
    saveWasOpen=open;
  }

  function bind(){
    if(!root.StonefellowStemRecordingEngineV213Runtime||!root.StonefellowStemStudioV91){
      attempts+=1;
      if(attempts<240)root.setTimeout(bind,60);
      else root.__STONEFELLOW_STEM_V213_HARDENING__=false;
      return;
    }

    void reconcile().then(()=>root.setTimeout(initializeLegacyTakeMutes,240));
    saveWasOpen=saveDialogOpen();
    observer=new MutationObserver(mutationTick);
    observer.observe(document.body,{
      childList:true,
      subtree:true,
      attributes:true,
      attributeFilter:['class','hidden','aria-hidden','disabled']
    });
    root.dispatchEvent(new CustomEvent('stonefellow:stem-recording-engine-v213-hardening',{detail:{build:BUILD}}));
  }

  bind();

  root.addEventListener('pagehide',()=>observer?.disconnect(),{once:true});

  root.StonefellowStemRecordingEngineV213Hardening={
    build:BUILD,
    familyNeedsMute,
    initializeLegacyTakeMutes,
    reconcile,
    refresh:mutationTick
  };
})(typeof globalThis!=='undefined'?globalThis:window);
