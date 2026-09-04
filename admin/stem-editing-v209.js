(function(root,factory){
  'use strict';
  const api=factory();
  root.StonefellowStemEditingV209=api;
  if(root.document)api.install(root,root.document);
})(typeof globalThis!=='undefined'?globalThis:window,function(){
  'use strict';

  const BUILD='stem-editing-foundation-v209-20260901';
  const MIN_CLIP=.01;

  const clone=value=>JSON.parse(JSON.stringify(value));
  const num=(value,fallback=0)=>Number.isFinite(Number(value))?Number(value):fallback;
  const startOf=clip=>Math.max(0,num(clip?.timelineStart,0));
  const lengthOf=clip=>Math.max(MIN_CLIP,num(clip?.timelineLength,MIN_CLIP));
  const endOf=clip=>startOf(clip)+lengthOf(clip);

  function stemValues(state){
    const stems=state?.stems;
    if(Array.isArray(stems))return stems;
    if(stems&&typeof stems==='object')return Object.values(stems);
    return [];
  }

  function findStemState(state,id){
    const key=String(id||'');
    if(Array.isArray(state?.stems)){
      return state.stems.find(stem=>String(stem?.id||'')===key)||null;
    }
    if(state?.stems&&typeof state.stems==='object'){
      return state.stems[key]||Object.values(state.stems).find(stem=>String(stem?.id||'')===key)||null;
    }
    return null;
  }

  function stateClipEntries(state){
    const entries=[];
    stemValues(state).forEach(stem=>{
      (stem?.clips||[]).forEach(clip=>entries.push({
        id:String(clip?.id||''),kind:'stem',ownerId:Number(stem?.id||0),clip
      }));
    });
    (state?.libraryClips||[]).forEach(clip=>entries.push({
      id:String(clip?.id||''),kind:'library',ownerId:String(clip?.id||''),clip
    }));
    return entries.filter(entry=>entry.id);
  }

  function selectionBounds(entries){
    const rows=(entries||[]).filter(entry=>entry?.clip);
    if(!rows.length)return null;
    const start=Math.min(...rows.map(entry=>startOf(entry.clip)));
    const end=Math.max(...rows.map(entry=>endOf(entry.clip)));
    return {start,end,duration:Math.max(MIN_CLIP,end-start)};
  }

  function createClipboard(state,ids){
    const wanted=new Set((ids||[]).map(String));
    const entries=stateClipEntries(state).filter(entry=>wanted.has(entry.id));
    const bounds=selectionBounds(entries);
    if(!bounds)return null;
    return {
      build:BUILD,
      bounds,
      entries:entries.map(entry=>({
        kind:entry.kind,
        ownerId:entry.ownerId,
        relativeStart:startOf(entry.clip)-bounds.start,
        clip:clone(entry.clip)
      }))
    };
  }

  function ensureUniqueId(base,used,idFactory){
    let candidate='';
    let attempt=0;
    do{
      candidate=String(
        typeof idFactory==='function'
          ? idFactory(base,attempt)
          : `${base||'clip'}-v209-${Date.now().toString(36)}-${Math.random().toString(36).slice(2,8)}-${attempt}`
      );
      attempt+=1;
    }while(!candidate||used.has(candidate));
    used.add(candidate);
    return candidate;
  }

  function shiftAtOrAfter(state,anchor,delta,excludedIds=[]){
    if(!delta)return state;
    const excluded=new Set(excludedIds.map(String));
    stateClipEntries(state).forEach(entry=>{
      if(excluded.has(entry.id))return;
      if(startOf(entry.clip)+1e-9>=anchor){
        entry.clip.timelineStart=Math.max(0,startOf(entry.clip)+delta);
      }
    });
    return state;
  }

  function pasteClipboard(state,clipboard,anchor,options={}){
    if(!clipboard?.entries?.length)return {state:clone(state),ids:[]};
    const next=clone(state);
    const destination=Math.max(0,num(anchor,0));
    const span=Math.max(MIN_CLIP,num(clipboard?.bounds?.duration,MIN_CLIP));
    if(options.ripple===true)shiftAtOrAfter(next,destination,span);

    const used=new Set(stateClipEntries(next).map(entry=>entry.id));
    const ids=[];
    for(const item of clipboard.entries){
      const clip=clone(item.clip);
      clip.id=ensureUniqueId(clip.id,used,options.idFactory);
      clip.timelineStart=destination+Math.max(0,num(item.relativeStart,0));

      if(item.kind==='stem'){
        const stem=findStemState(next,item.ownerId);
        if(!stem)continue;
        stem.clips=Array.isArray(stem.clips)?stem.clips:[];
        stem.clips.push(clip);
        stem.clips.sort((a,b)=>startOf(a)-startOf(b));
      }else{
        next.libraryClips=Array.isArray(next.libraryClips)?next.libraryClips:[];
        next.libraryClips.push(clip);
        next.libraryClips.sort((a,b)=>startOf(a)-startOf(b));
      }
      ids.push(String(clip.id));
    }
    return {state:next,ids};
  }

  function deleteSelection(state,ids,options={}){
    const wanted=new Set((ids||[]).map(String));
    const next=clone(state);
    const selected=stateClipEntries(next).filter(entry=>wanted.has(entry.id));
    const bounds=selectionBounds(selected);
    if(!bounds)return {state:next,removed:0};

    stemValues(next).forEach(stem=>{
      stem.clips=(stem.clips||[]).filter(clip=>!wanted.has(String(clip?.id||'')));
    });
    next.libraryClips=(next.libraryClips||[]).filter(clip=>!wanted.has(String(clip?.id||'')));

    if(options.ripple===true){
      stateClipEntries(next).forEach(entry=>{
        if(startOf(entry.clip)+1e-9>=bounds.end){
          entry.clip.timelineStart=Math.max(0,startOf(entry.clip)-bounds.duration);
        }
      });
    }
    return {state:next,removed:selected.length,bounds};
  }

  function nudgeSelection(state,ids,delta){
    const wanted=new Set((ids||[]).map(String));
    const next=clone(state);
    const selected=stateClipEntries(next).filter(entry=>wanted.has(entry.id));
    if(!selected.length)return {state:next,moved:0,delta:0};
    const minimum=Math.min(...selected.map(entry=>startOf(entry.clip)));
    const requested=num(delta,0);
    const rawApplied=Math.max(-minimum,requested);
    const applied=Object.is(rawApplied,-0)?0:rawApplied;
    selected.forEach(entry=>{
      entry.clip.timelineStart=Math.max(0,startOf(entry.clip)+applied);
    });
    return {state:next,moved:selected.length,delta:applied};
  }

  function slipSelection(state,ids,timelineDelta,boundsById={},ratioById={}){
    const wanted=new Set((ids||[]).map(String));
    const next=clone(state);
    let changed=0;
    for(const entry of stateClipEntries(next)){
      if(!wanted.has(entry.id))continue;
      const clip=entry.clip;
      const sourceStart=Math.max(0,num(clip.sourceStart,0));
      const sourceEnd=Math.max(sourceStart+MIN_CLIP,num(clip.sourceEnd,sourceStart+lengthOf(clip)));
      const sourceLength=sourceEnd-sourceStart;
      const maxSource=Math.max(sourceEnd,num(boundsById?.[entry.id],sourceEnd));
      const ratio=Math.max(.0001,num(ratioById?.[entry.id],1));
      const sourceDelta=num(timelineDelta,0)/ratio;
      const nextStart=Math.min(Math.max(0,sourceStart+sourceDelta),Math.max(0,maxSource-sourceLength));
      const applied=nextStart-sourceStart;
      if(Math.abs(applied)<1e-9)continue;
      clip.sourceStart=nextStart;
      clip.sourceEnd=nextStart+sourceLength;
      changed+=1;
    }
    return {state:next,changed};
  }

  function laneKey(entry){
    return entry.kind==='stem' ? `stem:${Number(entry.ownerId||0)}` : `library:${entry.id}`;
  }

  function crossfadeSelection(state,ids,duration=.1,options={}){
    const wanted=new Set((ids||[]).map(String));
    const next=clone(state);
    const selected=stateClipEntries(next).filter(entry=>wanted.has(entry.id));
    const lanes=new Map();
    selected.forEach(entry=>{
      const key=laneKey(entry);
      if(!lanes.has(key))lanes.set(key,[]);
      lanes.get(key).push(entry);
    });
    let pairs=0;
    const requested=Math.max(.005,num(duration,.1));

    lanes.forEach(rows=>{
      rows.sort((a,b)=>startOf(a.clip)-startOf(b.clip));
      for(let index=0;index<rows.length-1;index++){
        const left=rows[index].clip;
        const right=rows[index+1].clip;
        const leftEnd=endOf(left);
        const rightStart=startOf(right);
        let overlap=Math.max(0,leftEnd-rightStart);
        const maxFade=Math.max(.005,Math.min(lengthOf(left)/2,lengthOf(right)/2,requested));
        if(overlap<.005&&options.createOverlap!==false){
          right.timelineStart=Math.max(startOf(left),rightStart-maxFade);
          overlap=Math.max(0,leftEnd-startOf(right));
        }
        const fade=Math.max(.005,Math.min(maxFade,overlap||maxFade));
        left.fadeOut=fade;
        right.fadeIn=fade;
        pairs+=1;
      }
    });
    return {state:next,pairs};
  }

  function install(root,document){
    if(!document||root.__STONEFELLOW_STEM_V209_INSTALLED__)return false;
    root.__STONEFELLOW_STEM_V209_INSTALLED__=true;

    const selected=new Set();
    let clipboard=null;
    let rippleMode='off';
    let observer=null;
    let bindAttempts=0;
    let ui=null;

    const studio=()=>root.StonefellowStemStudioV91||null;
    const runtime=()=>root.STONEFELLOW_STUDIO_RUNTIME_V87||null;
    const currentState=()=>studio()?.getMixState?.()||null;
    const currentPlayhead=()=>Math.max(0,num(studio()?.getLedgerState?.()?.playhead,runtime()?.getPosition?.()||0));
    const currentSelectedId=()=>String(studio()?.getAgentState?.()?.selected_clip_id||'');

    function clipIdFromElement(target){
      const stem=target?.closest?.('[data-main-clip-id]');
      if(stem)return String(stem.dataset.mainClipId||'');
      const library=target?.closest?.('[data-library-loop-clip]');
      return library?String(library.closest?.('[data-library-clip-id]')?.dataset.libraryClipId||''):'';
    }

    function elementForId(id){
      const escape=root.CSS?.escape?root.CSS.escape(String(id)):String(id).replace(/["\\]/g,'\\$&');
      return document.querySelector(`[data-main-clip-id="${escape}"]`)||document.querySelector(`[data-library-clip-id="${escape}"] [data-library-loop-clip]`);
    }

    function updateSelectionUi(){
      document.querySelectorAll('.sf-v209-selected').forEach(el=>el.classList.remove('sf-v209-selected'));
      for(const id of selected)elementForId(id)?.classList.add('sf-v209-selected');
      if(!ui)return;
      const count=ui.querySelector('[data-v209-count]');
      if(count)count.textContent=selected.size?`${selected.size} SELECTED`:'SELECT CLIPS';
      const paste=ui.querySelector('[data-v209-paste]');
      if(paste)paste.disabled=!clipboard?.entries?.length;
      const duplicate=ui.querySelector('[data-v209-duplicate]');
      if(duplicate)duplicate.disabled=!selected.size;
      const xfade=ui.querySelector('[data-v209-xfade]');
      if(xfade)xfade.disabled=selected.size<2;
    }

    function selectOnly(id){selected.clear();if(id)selected.add(String(id));updateSelectionUi();}
    function toggleSelection(id){const key=String(id||'');if(!key)return;if(selected.has(key))selected.delete(key);else selected.add(key);updateSelectionUi();}
    function ensureSelection(){if(!selected.size){const id=currentSelectedId();if(id)selected.add(id);}return [...selected];}

    async function commit(nextState,action,detail,newSelection=null){
      const live=studio();
      if(!live||!nextState)return false;
      const beforeLedger=live.getLedgerState?.();
      if(!beforeLedger)return false;
      live.beginUndoGroup?.();
      try{live.applyMixState?.(nextState);}finally{live.endUndoGroup?.();}
      if(Array.isArray(newSelection)){
        selected.clear();
        newSelection.forEach(id=>selected.add(String(id)));
      }
      root.requestAnimationFrame(updateSelectionUi);
      try{await live.recordManualEdit?.(beforeLedger,{action,request:detail||action});}catch(error){console.warn('Stem v209 edit ledger:',error);}
      root.dispatchEvent(new CustomEvent('stonefellow:stem-edit-v209',{detail:{action,ids:[...selected]}}));
      return true;
    }

    function copySelected(){
      const state=currentState();
      if(!state)return false;
      clipboard=createClipboard(state,ensureSelection());
      updateSelectionUi();
      return Boolean(clipboard?.entries?.length);
    }

    async function pasteAt(anchor=currentPlayhead()){
      const state=currentState();
      if(!state||!clipboard)return false;
      const result=pasteClipboard(state,clipboard,anchor,{
        ripple:rippleMode==='all',
        idFactory:(base,index)=>`${base||'clip'}-copy-${Date.now().toString(36)}-${index}`
      });
      return commit(result.state,'clip_paste',`Paste ${result.ids.length} clip(s) at ${anchor.toFixed(3)}s`,result.ids);
    }

    async function duplicateSelected(){
      const state=currentState();
      if(!state)return false;
      const copied=createClipboard(state,ensureSelection());
      if(!copied)return false;
      clipboard=copied;
      const result=pasteClipboard(state,copied,copied.bounds.end,{
        ripple:rippleMode==='all',
        idFactory:(base,index)=>`${base||'clip'}-dup-${Date.now().toString(36)}-${index}`
      });
      return commit(result.state,'clip_duplicate',`Duplicate ${result.ids.length} clip(s)`,result.ids);
    }

    async function removeSelected(){
      const state=currentState();
      if(!state)return false;
      const result=deleteSelection(state,ensureSelection(),{ripple:rippleMode==='all'});
      if(!result.removed)return false;
      return commit(result.state,rippleMode==='all'?'clip_ripple_delete':'clip_delete',`Delete ${result.removed} clip(s)`,[]);
    }

    function snapStep(){
      const agent=studio()?.getAgentState?.()||{};
      const transport=root.StonefellowStemTransportHardeningV208;
      const mode=root.StonefellowStemTransportV208Runtime?.getState?.().snapMode||'beat';
      const step=transport?.snapStepSeconds?.(mode,num(agent.tempo,120),String(agent.time_signature||'4/4'));
      return step>0?step:.01;
    }

    async function nudgeSelected(direction){
      const state=currentState();
      if(!state)return false;
      const delta=snapStep()*(direction<0?-1:1);
      const result=nudgeSelection(state,ensureSelection(),delta);
      return result.moved?commit(result.state,'clip_nudge',`Nudge ${result.moved} clip(s) ${result.delta.toFixed(4)}s`,[...selected]):false;
    }

    function sourceGeometry(ids){
      const state=currentState()||{};
      const wanted=new Set(ids.map(String));
      const bounds={};
      const ratios={};
      const sessionTempo=Math.max(40,num(state.sessionTempo,studio()?.getAgentState?.()?.tempo||120));
      stateClipEntries(state).forEach(entry=>{
        if(!wanted.has(entry.id))return;
        if(entry.kind==='stem'){
          const stem=runtime()?.getStem?.(Number(entry.ownerId||0));
          bounds[entry.id]=Math.max(num(entry.clip.sourceEnd,0),num(stem?.duration,0));
          ratios[entry.id]=Math.max(.0001,num(stem?.timelineRatio,1));
        }else{
          bounds[entry.id]=Math.max(num(entry.clip.sourceEnd,0),num(entry.clip.sourceDuration,0));
          ratios[entry.id]=Math.max(.0001,num(entry.clip.sourceTempo,sessionTempo)/sessionTempo);
        }
      });
      return {bounds,ratios};
    }

    async function slipSelected(direction){
      const state=currentState();
      if(!state)return false;
      const ids=ensureSelection();
      const geometry=sourceGeometry(ids);
      const delta=snapStep()*(direction<0?-1:1);
      const result=slipSelection(state,ids,delta,geometry.bounds,geometry.ratios);
      return result.changed?commit(result.state,'clip_slip',`Slip ${result.changed} clip(s) ${delta.toFixed(4)} timeline seconds`,ids):false;
    }

    async function crossfadeSelected(){
      const state=currentState();
      if(!state)return false;
      const ids=ensureSelection();
      const duration=Math.max(.01,Math.min(.25,snapStep()));
      const result=crossfadeSelection(state,ids,duration,{createOverlap:true});
      return result.pairs?commit(result.state,'clip_crossfade',`Crossfade ${result.pairs} adjacent clip pair(s)`,ids):false;
    }

    const style=document.createElement('style');
    style.dataset.stemEditingV209=BUILD;
    style.textContent=`
      .sf-v209-selected{outline:2px solid rgba(230,214,190,.9)!important;outline-offset:-2px!important;box-shadow:0 0 0 1px rgba(0,0,0,.45),0 0 0 3px rgba(230,214,190,.14)!important}
      .sf-v209-tools{display:flex;align-items:center;gap:5px;margin-left:5px;min-width:0}
      .sf-v209-tools [data-v209-count]{min-width:70px;font:800 9px/1 system-ui,sans-serif;letter-spacing:.05em;opacity:.64;white-space:nowrap}
      .sf-v209-tools select,.sf-v209-tools button{min-height:27px;border:1px solid rgba(255,255,255,.08);border-radius:6px;background:rgba(255,255,255,.035);color:inherit;font:700 10px/1 system-ui,sans-serif}
      .sf-v209-tools select{padding:0 5px}.sf-v209-tools button{padding:0 7px;cursor:pointer}.sf-v209-tools button:disabled{opacity:.35;cursor:default}
      .sf-v209-tools button:not(:disabled):hover,.sf-v209-tools button:not(:disabled):focus-visible{background:rgba(255,255,255,.08);outline:none}
      @media(max-width:760px){.sf-v209-tools{width:100%;order:21;justify-content:flex-end}.sf-v209-tools [data-v209-count]{margin-right:auto}.sf-v209-tools select,.sf-v209-tools button{min-height:25px;font-size:9px}}
    `;
    document.head.appendChild(style);

    function buildUi(){
      const toolbar=document.querySelector('.daw-mixer-toolbar');
      if(!toolbar)return false;
      ui=document.createElement('div');
      ui.className='sf-v209-tools';
      ui.dataset.stemEditingToolsV209=BUILD;
      ui.innerHTML=`<span data-v209-count>SELECT CLIPS</span><label title="Ripple editing mode"><select data-v209-ripple aria-label="Ripple editing mode"><option value="off">RIPPLE OFF</option><option value="all">RIPPLE ALL</option></select></label><button type="button" data-v209-duplicate disabled title="Duplicate selected clips (Ctrl/Cmd+D)">DUP</button><button type="button" data-v209-paste disabled title="Paste copied clips at playhead (Ctrl/Cmd+V)">PASTE</button><button type="button" data-v209-xfade disabled title="Create crossfades between adjacent selected clips">XFADE</button>`;
      toolbar.appendChild(ui);
      ui.querySelector('[data-v209-ripple]').addEventListener('change',event=>{rippleMode=event.target.value==='all'?'all':'off';});
      ui.querySelector('[data-v209-duplicate]').addEventListener('click',()=>void duplicateSelected());
      ui.querySelector('[data-v209-paste]').addEventListener('click',()=>void pasteAt());
      ui.querySelector('[data-v209-xfade]').addEventListener('click',()=>void crossfadeSelected());
      updateSelectionUi();
      return true;
    }

    document.addEventListener('pointerdown',event=>{
      const id=clipIdFromElement(event.target);
      if(id){
        if(event.shiftKey||event.ctrlKey||event.metaKey)toggleSelection(id);else selectOnly(id);
        root.setTimeout(updateSelectionUi,0);
      }
    },true);

    document.addEventListener('keydown',event=>{
      const target=event.target;
      const editing=Boolean(target?.matches?.('input,textarea,select,[contenteditable="true"]')||target?.closest?.('[contenteditable="true"]'));
      if(editing)return;
      const mod=event.ctrlKey||event.metaKey;
      const key=String(event.key||'').toLowerCase();
      if(mod&&key==='c'&&ensureSelection().length){event.preventDefault();event.stopImmediatePropagation();copySelected();return;}
      if(mod&&key==='v'&&clipboard?.entries?.length){event.preventDefault();event.stopImmediatePropagation();void pasteAt();return;}
      if(mod&&key==='d'&&ensureSelection().length){event.preventDefault();event.stopImmediatePropagation();void duplicateSelected();return;}
      if((event.key==='Delete'||event.key==='Backspace')&&ensureSelection().length){event.preventDefault();event.stopImmediatePropagation();void removeSelected();return;}
      if(event.shiftKey&&event.altKey&&(event.key==='ArrowLeft'||event.key==='ArrowRight')&&ensureSelection().length){event.preventDefault();event.stopImmediatePropagation();void slipSelected(event.key==='ArrowLeft'?-1:1);return;}
      if(event.altKey&&!event.shiftKey&&(event.key==='ArrowLeft'||event.key==='ArrowRight')&&ensureSelection().length){event.preventDefault();event.stopImmediatePropagation();void nudgeSelected(event.key==='ArrowLeft'?-1:1);return;}
      if(event.shiftKey&&key==='x'&&ensureSelection().length>1){event.preventDefault();event.stopImmediatePropagation();void crossfadeSelected();return;}
      if(event.key==='Escape'){selected.clear();root.setTimeout(updateSelectionUi,0);}
    },true);

    function lateBind(){
      if(!studio()||!buildUi()){
        bindAttempts+=1;
        if(bindAttempts<200)root.setTimeout(lateBind,60);
        return;
      }
      observer=new MutationObserver(()=>root.requestAnimationFrame(updateSelectionUi));
      observer.observe(document.getElementById('dawArrangeLanes')||document.body,{childList:true,subtree:true});
      root.dispatchEvent(new CustomEvent('stonefellow:stem-editing-v209',{detail:{build:BUILD}}));
    }
    lateBind();

    root.addEventListener('pagehide',()=>observer?.disconnect(),{once:true});
    root.StonefellowStemEditingV209Runtime={
      build:BUILD,
      getSelection:()=>[...selected],
      select:ids=>{selected.clear();(ids||[]).forEach(id=>selected.add(String(id)));updateSelectionUi();return[...selected];},
      copy:copySelected,paste:pasteAt,duplicate:duplicateSelected,remove:removeSelected,crossfade:crossfadeSelected,
      getRipple:()=>rippleMode,
      setRipple:value=>{rippleMode=value==='all'?'all':'off';if(ui)ui.querySelector('[data-v209-ripple]').value=rippleMode;return rippleMode;}
    };
    return true;
  }

  return Object.freeze({
    build:BUILD,stemValues,findStemState,stateClipEntries,selectionBounds,createClipboard,pasteClipboard,
    deleteSelection,nudgeSelection,slipSelection,crossfadeSelection,shiftAtOrAfter,install
  });
});
