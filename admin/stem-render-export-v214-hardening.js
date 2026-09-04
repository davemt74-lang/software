(function(root){
  'use strict';

  const BUILD='stem-render-export-v214-hardening-20260901';
  if(!root.document||root.__STONEFELLOW_STEM_V214_HARDENING__)return;
  root.__STONEFELLOW_STEM_V214_HARDENING__=true;

  const document=root.document;
  let attempts=0;
  let observer=null;
  let renderOverride=false;
  let originalMix=null;
  let originalGetMixState=null;
  let originalApplyMixState=null;
  let restorePending=false;

  const clone=value=>JSON.parse(JSON.stringify(value));
  const wait=ms=>new Promise(resolve=>root.setTimeout(resolve,ms));

  function modal(){return document.getElementById('stemRenderDialogV214');}
  function renderButton(){return modal()?.querySelector('[data-v214-render]')||null;}
  function masterFxEnabled(){return modal()?.querySelector('[data-v214-master-fx]')?.checked!==false;}

  function renderSafeState(state){
    const next=clone(state||{});
    if(next.loop&&typeof next.loop==='object')next.loop.active=false;
    if(!masterFxEnabled()&&Array.isArray(next.channelPlugins?.master)){
      next.channelPlugins.master.forEach(plugin=>{if(plugin)plugin.enabled=false;});
    }
    return next;
  }

  async function seekRuntime(time,resumeAfter=false){
    const runtime=root.STONEFELLOW_STUDIO_RUNTIME_V87;
    const surface=document.getElementById('dawTimelineSurface');
    const track=document.querySelector('#dawArrangeLanes .daw-arrange-track')||document.querySelector('.daw-arrange-track');
    const duration=Math.max(.05,Number(root.StonefellowStemStudioV91?.getAgentState?.()?.duration||root.STONEFELLOW_STEM_STUDIO?.duration||0)||.05);
    const target=Math.max(0,Math.min(duration,Number(time)||0));
    if(!runtime||!surface||!track)throw new Error('Timeline seek bridge is unavailable.');

    if(runtime.isPlaying?.())runtime.pause?.();
    const rect=surface.getBoundingClientRect();
    const trackRect=track.getBoundingClientRect();
    if(!(rect.width>0))throw new Error('Timeline seek surface has no measurable width.');

    const clientX=rect.left+(target/duration)*rect.width;
    const clientY=Math.max(trackRect.top+1,Math.min(trackRect.bottom-1,trackRect.top+Math.min(16,Math.max(1,trackRect.height/2))));
    track.dispatchEvent(new root.MouseEvent('click',{bubbles:true,cancelable:true,clientX,clientY,button:0,buttons:0}));

    const deadline=Date.now()+2800;
    while(Date.now()<deadline){
      const current=Number(runtime.getPosition?.()||0);
      if(Math.abs(current-target)<.08){
        if(resumeAfter)await runtime.play?.();
        return current;
      }
      await wait(35);
    }
    throw new Error('Stem Studio could not confirm the requested render seek.');
  }

  function armRenderSnapshot(){
    const studio=root.StonefellowStemStudioV91;
    if(!studio||!originalGetMixState||renderOverride)return;
    try{originalMix=clone(originalGetMixState());}
    catch(error){originalMix=null;}
    renderOverride=true;
    restorePending=Boolean(originalMix);
  }

  function restoreOriginalMix(){
    if(!restorePending||!originalMix||!originalApplyMixState)return false;
    const saved=originalMix;
    restorePending=false;
    originalMix=null;
    renderOverride=false;
    try{
      originalApplyMixState(clone(saved));
      root.dispatchEvent(new CustomEvent('stonefellow:stem-render-v214-restored',{detail:{build:BUILD}}));
      return true;
    }catch(error){
      console.warn('Stem v214 exact mix restore failed.',error);
      return false;
    }
  }

  function clarifyAnalysisLabels(){
    const dialog=modal();
    if(!dialog)return;
    const lufs=dialog.querySelector('[data-v214-normalize] option[value="lufs"]');
    if(lufs&&lufs.textContent!=='LUFS (estimate)')lufs.textContent='LUFS (estimate)';
    dialog.querySelectorAll('.sf-v214-results article span').forEach(node=>{
      const text=String(node.textContent||'')
        .replace(/True peak (?!est\.)/g,'True peak est. ')
        .replace(/ · LUFS (?!est\.)/g,' · LUFS est. ');
      if(node.textContent!==text)node.textContent=text;
    });
  }

  function mutationTick(){
    clarifyAnalysisLabels();
    const button=renderButton();
    if(!button)return;
    if(renderOverride&&!button.disabled&&restorePending){
      root.setTimeout(restoreOriginalMix,60);
    }
  }

  function bind(){
    const studio=root.StonefellowStemStudioV91;
    const runtime=root.STONEFELLOW_STUDIO_RUNTIME_V87;
    const dialog=modal();
    if(!studio||!runtime||!dialog){
      attempts+=1;
      if(attempts<240)root.setTimeout(bind,60);
      else root.__STONEFELLOW_STEM_V214_HARDENING__=false;
      return;
    }

    originalGetMixState=studio.getMixState.bind(studio);
    originalApplyMixState=studio.applyMixState.bind(studio);
    studio.getMixState=()=>{
      const state=originalGetMixState();
      return renderOverride?renderSafeState(state):state;
    };

    if(typeof runtime.seek!=='function')runtime.seek=seekRuntime;

    dialog.addEventListener('click',event=>{
      if(event.target?.closest?.('[data-v214-render]'))armRenderSnapshot();
    },true);
    dialog.addEventListener('keydown',event=>{
      if((event.key==='Enter'||event.key===' ')&&event.target?.closest?.('[data-v214-render]'))armRenderSnapshot();
    },true);

    observer=new MutationObserver(mutationTick);
    observer.observe(dialog,{childList:true,subtree:true,attributes:true,attributeFilter:['disabled','hidden','class']});
    clarifyAnalysisLabels();
    root.dispatchEvent(new CustomEvent('stonefellow:stem-render-export-v214-hardening',{detail:{build:BUILD}}));
  }

  bind();

  root.addEventListener('pagehide',()=>{
    observer?.disconnect();
    if(renderOverride&&originalMix)restoreOriginalMix();
    if(originalGetMixState&&root.StonefellowStemStudioV91)root.StonefellowStemStudioV91.getMixState=originalGetMixState;
  },{once:true});

  root.StonefellowStemRenderExportV214Hardening={
    build:BUILD,
    renderSafeState,
    seekRuntime,
    restoreOriginalMix,
    getSnapshot:()=>originalMix?clone(originalMix):null
  };
})(typeof globalThis!=='undefined'?globalThis:window);
