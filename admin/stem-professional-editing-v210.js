(function(root,factory){
  'use strict';
  const api=factory(root);
  root.StonefellowStemProfessionalEditingV210=api;
  if(root.document)api.install(root,root.document);
})(typeof globalThis!=='undefined'?globalThis:window,function(root){
  'use strict';

  const BUILD='stem-professional-editing-v210-20260901';
  const MIN_CLIP=.01;
  const clone=value=>JSON.parse(JSON.stringify(value));
  const num=(value,fallback=0)=>Number.isFinite(Number(value))?Number(value):fallback;
  const clamp=(value,min,max)=>Math.min(max,Math.max(min,num(value,min)));
  const startOf=clip=>Math.max(0,num(clip?.timelineStart,0));
  const lengthOf=clip=>Math.max(MIN_CLIP,num(clip?.timelineLength,MIN_CLIP));
  const endOf=clip=>startOf(clip)+lengthOf(clip);

  function stemRows(state){
    if(Array.isArray(state?.stems)){
      return state.stems.map((stem,index)=>({key:String(stem?.id??index),id:Number(stem?.id??index),stem}));
    }
    if(state?.stems&&typeof state.stems==='object'){
      return Object.entries(state.stems).map(([key,stem])=>({key:String(key),id:Number(stem?.id??key),stem}));
    }
    return [];
  }

  function clipEntries(state){
    const rows=[];
    stemRows(state).forEach(owner=>{
      (owner.stem?.clips||[]).forEach(clip=>rows.push({
        id:String(clip?.id||''),kind:'stem',ownerId:owner.id,ownerKey:owner.key,clip
      }));
    });
    (state?.libraryClips||[]).forEach((clip,index)=>rows.push({
      id:String(clip?.id||''),kind:'library',ownerId:Number(clip?.stemId||0),ownerKey:`library:${index}`,clip
    }));
    return rows.filter(row=>row.id);
  }

  function selectionBounds(state,ids){
    const wanted=new Set((ids||[]).map(String));
    const rows=clipEntries(state).filter(row=>wanted.has(row.id));
    if(!rows.length)return null;
    const start=Math.min(...rows.map(row=>startOf(row.clip)));
    const end=Math.max(...rows.map(row=>endOf(row.clip)));
    return {start,end,duration:Math.max(MIN_CLIP,end-start),count:rows.length};
  }

  function sortStateClips(state){
    stemRows(state).forEach(owner=>{
      if(Array.isArray(owner.stem?.clips))owner.stem.clips.sort((a,b)=>startOf(a)-startOf(b));
    });
    if(Array.isArray(state?.libraryClips))state.libraryClips.sort((a,b)=>startOf(a)-startOf(b));
    return state;
  }

  function moveSelection(state,ids,delta){
    const wanted=new Set((ids||[]).map(String));
    const next=clone(state);
    const rows=clipEntries(next).filter(row=>wanted.has(row.id));
    if(!rows.length)return {state:next,moved:0,delta:0};
    const minimum=Math.min(...rows.map(row=>startOf(row.clip)));
    const raw=Math.max(-minimum,num(delta,0));
    const applied=Object.is(raw,-0)?0:raw;
    rows.forEach(row=>{row.clip.timelineStart=Math.max(0,startOf(row.clip)+applied);});
    return {state:sortStateClips(next),moved:rows.length,delta:applied};
  }

  function uniqueId(base,used,idFactory,index=0){
    let attempt=index;
    let id='';
    do{
      id=String(typeof idFactory==='function'
        ? idFactory(base,attempt)
        : `${base||'clip'}-v210-${Date.now().toString(36)}-${Math.random().toString(36).slice(2,8)}-${attempt}`);
      attempt+=1;
    }while(!id||used.has(id));
    used.add(id);
    return id;
  }

  function duplicateSelection(state,ids,delta,options={}){
    const wanted=new Set((ids||[]).map(String));
    const next=clone(state);
    const rows=clipEntries(next).filter(row=>wanted.has(row.id));
    if(!rows.length)return {state:next,ids:[],delta:0};
    const minimum=Math.min(...rows.map(row=>startOf(row.clip)));
    const applied=Math.max(-minimum,num(delta,0));
    const used=new Set(clipEntries(next).map(row=>row.id));
    const created=[];

    rows.forEach((row,index)=>{
      const copy=clone(row.clip);
      copy.id=uniqueId(copy.id,used,options.idFactory,index);
      copy.timelineStart=Math.max(0,startOf(copy)+applied);
      if(row.kind==='stem'){
        const owner=stemRows(next).find(item=>String(item.key)===String(row.ownerKey)||Number(item.id)===Number(row.ownerId));
        if(!owner)return;
        owner.stem.clips=Array.isArray(owner.stem.clips)?owner.stem.clips:[];
        owner.stem.clips.push(copy);
      }else{
        next.libraryClips=Array.isArray(next.libraryClips)?next.libraryClips:[];
        next.libraryClips.push(copy);
      }
      created.push(String(copy.id));
    });

    return {state:sortStateClips(next),ids:created,delta:applied};
  }

  function setClipFade(state,id,edge,value){
    const next=clone(state);
    const row=clipEntries(next).find(item=>item.id===String(id||''));
    if(!row)return {state:next,changed:false,value:0};
    const clean=clamp(value,0,lengthOf(row.clip));
    if(edge==='out')row.clip.fadeOut=clean;
    else row.clip.fadeIn=clean;
    return {state:next,changed:true,value:clean};
  }

  function setCrossfadePair(state,leftId,rightId,duration,options={}){
    const next=clone(state);
    const rows=clipEntries(next);
    const left=rows.find(row=>row.id===String(leftId||''));
    const right=rows.find(row=>row.id===String(rightId||''));
    if(!left||!right||left.kind!=='stem'||right.kind!=='stem'||Number(left.ownerId)!==Number(right.ownerId)){
      return {state:next,changed:false,duration:0};
    }
    const maximum=Math.max(.005,Math.min(lengthOf(left.clip)/2,lengthOf(right.clip)/2));
    const desired=Math.min(maximum,Math.max(.005,num(duration,.1)));
    const leftEnd=endOf(left.clip);
    let overlap=Math.max(0,leftEnd-startOf(right.clip));
    if(overlap<desired&&options.createOverlap!==false){
      right.clip.timelineStart=Math.max(startOf(left.clip),leftEnd-desired);
      overlap=Math.max(0,leftEnd-startOf(right.clip));
    }
    const applied=Math.max(.005,Math.min(desired,overlap||desired));
    left.clip.fadeOut=applied;
    right.clip.fadeIn=applied;
    return {state:sortStateClips(next),changed:true,duration:applied};
  }

  function sourcePerTimeline(clip){
    const sourceStart=Math.max(0,num(clip?.sourceStart,0));
    const sourceEnd=Math.max(sourceStart,num(clip?.sourceEnd,sourceStart+lengthOf(clip)));
    return Math.max(.000001,(sourceEnd-sourceStart)/lengthOf(clip));
  }

  function splitClipAt(clip,time,used,idFactory){
    const start=startOf(clip);
    const end=endOf(clip);
    const boundary=num(time,start);
    if(boundary<=start+MIN_CLIP/2||boundary>=end-MIN_CLIP/2)return [clone(clip)];

    const left=clone(clip);
    const right=clone(clip);
    const leftLength=boundary-start;
    const rightLength=end-boundary;
    const sourceBoundary=num(clip.sourceStart,0)+leftLength*sourcePerTimeline(clip);

    left.timelineLength=Math.max(MIN_CLIP,leftLength);
    left.sourceEnd=sourceBoundary;
    left.fadeOut=0;

    right.id=uniqueId(clip.id,used,idFactory,1);
    right.timelineStart=boundary;
    right.timelineLength=Math.max(MIN_CLIP,rightLength);
    right.sourceStart=sourceBoundary;
    right.fadeIn=0;
    return [left,right];
  }

  function splitClipsToRange(clips,start,end,used,idFactory){
    let rows=(clips||[]).map(clone);
    for(const boundary of [start,end]){
      const next=[];
      rows.forEach(clip=>splitClipAt(clip,boundary,used,idFactory).forEach(piece=>next.push(piece)));
      rows=next;
    }
    return rows.sort((a,b)=>startOf(a)-startOf(b));
  }

  function compTakeRange(state,familyStemIds,chosenStemId,start,end,options={}){
    const next=clone(state);
    const family=new Set((familyStemIds||[]).map(value=>Number(value||0)).filter(value=>value>0));
    const chosen=Number(chosenStemId||0);
    const rangeStart=Math.max(0,Math.min(num(start,0),num(end,0)));
    const rangeEnd=Math.max(rangeStart+MIN_CLIP,Math.max(num(start,0),num(end,0)));
    if(!family.size||!family.has(chosen))return {state:next,changed:0,start:rangeStart,end:rangeEnd};

    const used=new Set(clipEntries(next).map(row=>row.id));
    let changed=0;
    stemRows(next).forEach(owner=>{
      if(!family.has(Number(owner.id)))return;
      const split=splitClipsToRange(owner.stem?.clips||[],rangeStart,rangeEnd,used,options.idFactory);
      split.forEach(clip=>{
        const center=startOf(clip)+lengthOf(clip)/2;
        if(center>=rangeStart-1e-9&&center<=rangeEnd+1e-9){
          const shouldMute=Number(owner.id)!==chosen;
          if(Boolean(clip.muted)!==shouldMute)changed+=1;
          clip.muted=shouldMute;
        }
      });
      owner.stem.clips=split;
    });
    return {state:sortStateClips(next),changed,start:rangeStart,end:rangeEnd};
  }

  function rectIntersects(a,b){
    if(!a||!b)return false;
    return !(Number(a.right)<Number(b.left)||Number(a.left)>Number(b.right)||Number(a.bottom)<Number(b.top)||Number(a.top)>Number(b.bottom));
  }

  function rangeFromPixels(startX,endX,left,width,duration){
    const safeWidth=Math.max(1,num(width,1));
    const total=Math.max(MIN_CLIP,num(duration,MIN_CLIP));
    const a=clamp((num(startX,0)-num(left,0))/safeWidth,0,1)*total;
    const b=clamp((num(endX,0)-num(left,0))/safeWidth,0,1)*total;
    return {start:Math.min(a,b),end:Math.max(a,b)};
  }

  function install(root,document){
    if(!document||root.__STONEFELLOW_STEM_V210_INSTALLED__)return false;
    root.__STONEFELLOW_STEM_V210_INSTALLED__=true;

    let bindAttempts=0;
    let toolMode='pointer';
    let timeRange=null;
    let gesture=null;
    let menuClipId='';
    let observer=null;
    let tools=null;
    let contextMenu=null;
    let marqueeBox=null;
    let rangeOverlay=null;
    let decorationQueued=false;

    const studio=()=>root.StonefellowStemStudioV91||null;
    const coreRuntime=()=>root.STONEFELLOW_STUDIO_RUNTIME_V87||null;
    const v209=()=>root.StonefellowStemEditingV209Runtime||null;
    const v209Api=()=>root.StonefellowStemEditingV209||null;
    const transport=()=>root.StonefellowStemTransportV208Runtime||null;
    const currentState=()=>studio()?.getMixState?.()||null;
    const agent=()=>studio()?.getAgentState?.()||{};
    const totalDuration=()=>Math.max(MIN_CLIP,num(agent().duration,1));
    const arrangeLanes=()=>document.getElementById('dawArrangeLanes');
    const timelineSurface=()=>document.getElementById('dawTimelineSurface');

    const escapeCss=value=>root.CSS?.escape?root.CSS.escape(String(value)):String(value).replace(/["\\]/g,'\\$&');
    const selectedIds=()=> (v209()?.getSelection?.()||[]).map(String);
    const selectIds=ids=>v209()?.select?.((ids||[]).map(String))||[];

    function clipIdFromElement(target){
      const stem=target?.closest?.('[data-main-clip-id]');
      if(stem)return String(stem.dataset.mainClipId||'');
      const library=target?.closest?.('[data-library-loop-clip]');
      return library?String(library.closest?.('[data-library-clip-id]')?.dataset.libraryClipId||''):'';
    }

    function elementForId(id){
      const key=escapeCss(id);
      return document.querySelector(`[data-main-clip-id="${key}"]`)||document.querySelector(`[data-library-clip-id="${key}"] [data-library-loop-clip]`);
    }

    function clipAgentRecord(id){
      const key=String(id||'');
      return (agent().clips||[]).find(clip=>String(clip?.id||'')===key)||null;
    }

    function clipStateRow(id,state=currentState()){
      return clipEntries(state||{}).find(row=>row.id===String(id||''))||null;
    }

    function clipStemId(id){
      const record=clipAgentRecord(id);
      return Number(record?.stem_id||clipStateRow(id)?.ownerId||0);
    }

    function snapTime(seconds){
      const live=transport();
      return live?.snapTime?Math.max(0,num(live.snapTime(seconds),seconds)):Math.max(0,num(seconds,0));
    }

    function pixelsToSeconds(deltaX){
      const rect=timelineSurface()?.getBoundingClientRect?.();
      return rect?.width>0?num(deltaX,0)/rect.width*totalDuration():0;
    }

    function secondsToPixels(seconds){
      const rect=timelineSurface()?.getBoundingClientRect?.();
      return rect?.width>0?num(seconds,0)/totalDuration()*rect.width:0;
    }

    async function commit(nextState,action,detail,newSelection=null){
      const live=studio();
      const before=live?.getLedgerState?.();
      if(!live||!nextState||!before)return false;
      live.beginUndoGroup?.();
      try{live.applyMixState?.(nextState);}finally{live.endUndoGroup?.();}
      if(Array.isArray(newSelection))root.requestAnimationFrame(()=>selectIds(newSelection));
      try{await live.recordManualEdit?.(before,{action,request:detail||action});}
      catch(error){console.warn('Stem v210 edit ledger:',error);}
      root.dispatchEvent(new CustomEvent('stonefellow:stem-edit-v210',{detail:{build:BUILD,action,ids:Array.isArray(newSelection)?newSelection:selectedIds()}}));
      queueDecorations();
      return true;
    }

    function sourceGeometry(ids){
      const state=currentState()||{};
      const bounds={};
      const ratios={};
      const wanted=new Set((ids||[]).map(String));
      const sessionTempo=Math.max(40,num(state.sessionTempo,agent().tempo||120));
      clipEntries(state).forEach(row=>{
        if(!wanted.has(row.id))return;
        if(row.kind==='stem'){
          const stem=coreRuntime()?.getStem?.(Number(row.ownerId||0));
          bounds[row.id]=Math.max(num(row.clip.sourceEnd,0),num(stem?.duration,0));
          ratios[row.id]=Math.max(.0001,num(stem?.timelineRatio,1));
        }else{
          bounds[row.id]=Math.max(num(row.clip.sourceEnd,0),num(row.clip.sourceDuration,0));
          ratios[row.id]=Math.max(.0001,num(row.clip.sourceTempo,sessionTempo)/sessionTempo);
        }
      });
      return {bounds,ratios};
    }

    function setTool(mode){
      toolMode=['pointer','marquee','range'].includes(String(mode))?String(mode):'pointer';
      tools?.querySelectorAll('[data-v210-tool]').forEach(button=>{
        const active=button.dataset.v210Tool===toolMode;
        button.classList.toggle('active',active);
        button.setAttribute('aria-pressed',active?'true':'false');
      });
      document.documentElement.dataset.stemEditToolV210=toolMode;
      return toolMode;
    }

    function setRange(start,end){
      const a=snapTime(Math.min(num(start,0),num(end,0)));
      let b=snapTime(Math.max(num(start,0),num(end,0)));
      if(b<=a)b=Math.min(totalDuration(),a+Math.max(MIN_CLIP,pixelsToSeconds(8)||MIN_CLIP));
      timeRange={start:Math.max(0,a),end:Math.max(a+MIN_CLIP,b)};
      renderRange();
      return {...timeRange};
    }

    function clearRange(){timeRange=null;renderRange();}

    function renderRange(){
      if(!rangeOverlay)return;
      const label=tools?.querySelector('[data-v210-range-label]');
      if(!timeRange||!(timeRange.end>timeRange.start)){
        rangeOverlay.hidden=true;
        if(label)label.textContent='NO RANGE';
        updateCompButton();
        return;
      }
      const total=totalDuration();
      rangeOverlay.hidden=false;
      rangeOverlay.style.left=`${clamp(timeRange.start/total*100,0,100)}%`;
      rangeOverlay.style.width=`${clamp((timeRange.end-timeRange.start)/total*100,0,100)}%`;
      if(label)label.textContent=`${timeRange.start.toFixed(2)}–${timeRange.end.toFixed(2)}s`;
      updateCompButton();
    }

    function takeInfoForClip(id){
      const clipId=String(id||'');
      if(!clipId)return null;
      const stemId=clipStemId(clipId);
      const stem=coreRuntime()?.getStem?.(stemId);
      const parentId=Number(stem?.takeOfStemId||0);
      if(!(parentId>0))return null;
      const family=[parentId];
      (agent().stems||[]).forEach(item=>{
        const candidate=Number(item?.id||0);
        if(!(candidate>0)||candidate===parentId)return;
        if(Number(coreRuntime()?.getStem?.(candidate)?.takeOfStemId||0)===parentId)family.push(candidate);
      });
      return {clipId,stemId,parentId,family:[...new Set(family)]};
    }

    function selectedTakeInfo(preferredId=''){
      if(preferredId){
        const preferred=takeInfoForClip(preferredId);
        if(preferred)return preferred;
      }
      for(const id of selectedIds()){
        const info=takeInfoForClip(id);
        if(info)return info;
      }
      return null;
    }

    function updateCompButton(){
      const button=tools?.querySelector('[data-v210-comp]');
      if(!button)return;
      const take=selectedTakeInfo();
      button.disabled=!take;
      button.textContent=take?'COMP TAKE':'COMP';
      button.title=take
        ? (timeRange?'Use the selected take for the active range':'Use the selected take for its clip range')
        : 'Select a take-lane clip to comp';
    }

    async function compSelectedTake(preferredId=''){
      const take=selectedTakeInfo(preferredId);
      const state=currentState();
      if(!take||!state)return false;
      const row=clipStateRow(take.clipId,state);
      if(!row)return false;
      const range=timeRange&&timeRange.end>timeRange.start?timeRange:{start:startOf(row.clip),end:endOf(row.clip)};
      let serial=0;
      const result=compTakeRange(state,take.family,take.stemId,range.start,range.end,{
        idFactory:base=>`${base||'clip'}-comp-${Date.now().toString(36)}-${++serial}`
      });
      if(!result.changed)return false;
      const ok=await commit(result.state,'take_comp',`Comp take ${take.stemId} into parent ${take.parentId} from ${result.start.toFixed(3)}s to ${result.end.toFixed(3)}s`,[]);
      if(ok)setRange(result.start,result.end);
      return ok;
    }

    function renderFadeVisual(el,row){
      if(!el||!row)return;
      const length=lengthOf(row.clip);
      el.style.setProperty('--sf-v210-fade-in',`${clamp(num(row.clip.fadeIn,0)/length*100,0,100)}%`);
      el.style.setProperty('--sf-v210-fade-out',`${clamp(num(row.clip.fadeOut,0)/length*100,0,100)}%`);
      const inHandle=el.querySelector('[data-v210-fade="in"]');
      const outHandle=el.querySelector('[data-v210-fade="out"]');
      if(inHandle)inHandle.title=`Fade in ${num(row.clip.fadeIn,0).toFixed(3)}s`;
      if(outHandle)outHandle.title=`Fade out ${num(row.clip.fadeOut,0).toFixed(3)}s`;
    }

    function decorateClip(el){
      const id=clipIdFromElement(el);
      if(!id)return;
      if(!el.querySelector('[data-v210-fade="in"]')){
        const shapeIn=document.createElement('span');
        shapeIn.className='sf-v210-fade-shape sf-v210-fade-shape-in';
        const shapeOut=document.createElement('span');
        shapeOut.className='sf-v210-fade-shape sf-v210-fade-shape-out';
        const fadeIn=document.createElement('button');
        fadeIn.type='button';fadeIn.className='sf-v210-fade-handle sf-v210-fade-in-handle';fadeIn.dataset.v210Fade='in';fadeIn.setAttribute('aria-label','Adjust clip fade in');
        const fadeOut=document.createElement('button');
        fadeOut.type='button';fadeOut.className='sf-v210-fade-handle sf-v210-fade-out-handle';fadeOut.dataset.v210Fade='out';fadeOut.setAttribute('aria-label','Adjust clip fade out');
        el.append(shapeIn,shapeOut,fadeIn,fadeOut);
      }
      renderFadeVisual(el,clipStateRow(id));
    }

    function decorateTakeLanes(){
      document.querySelectorAll('[data-take-of-stem]').forEach(row=>{
        if(row.querySelector(':scope > .sf-v210-take-badge'))return;
        const parent=Number(row.dataset.takeOfStem||0);
        const badge=document.createElement('span');
        badge.className='sf-v210-take-badge';
        badge.textContent=parent>0?`TAKE · MAIN ${parent}`:'TAKE';
        row.appendChild(badge);
      });
    }

    function adjacentSelectedPairs(state=currentState()){
      const wanted=new Set(selectedIds());
      const groups=new Map();
      clipEntries(state||{}).forEach(row=>{
        if(!wanted.has(row.id)||row.kind!=='stem')return;
        const key=String(row.ownerId);
        if(!groups.has(key))groups.set(key,[]);
        groups.get(key).push(row);
      });
      const pairs=[];
      groups.forEach(rows=>{
        rows.sort((a,b)=>startOf(a.clip)-startOf(b.clip));
        for(let index=0;index<rows.length-1;index++)pairs.push([rows[index],rows[index+1]]);
      });
      return pairs;
    }

    function renderCrossfadeHandles(){
      const desired=new Map();
      adjacentSelectedPairs().forEach(([left,right])=>desired.set(`${left.id}|${right.id}`,{left,right}));
      const existing=new Map();
      document.querySelectorAll('.sf-v210-xfade-handle[data-v210-xfade-key]').forEach(handle=>existing.set(handle.dataset.v210XfadeKey,handle));

      existing.forEach((handle,key)=>{
        if(!desired.has(key))handle.remove();
      });
      desired.forEach(({left,right},key)=>{
        if(existing.has(key))return;
        const leftEl=elementForId(left.id);
        if(!leftEl)return;
        const button=document.createElement('button');
        button.type='button';
        button.className='sf-v210-xfade-handle';
        button.dataset.v210XfadeKey=key;
        button.dataset.v210XfadeLeft=left.id;
        button.dataset.v210XfadeRight=right.id;
        button.textContent='×';
        button.title='Drag to adjust crossfade';
        button.setAttribute('aria-label','Adjust crossfade');
        leftEl.appendChild(button);
      });
    }

    function decorateAll(){
      document.querySelectorAll('.daw-main-stem-clip,[data-library-loop-clip]').forEach(decorateClip);
      decorateTakeLanes();
      renderCrossfadeHandles();
      updateCompButton();
    }

    function queueDecorations(){
      if(decorationQueued)return;
      decorationQueued=true;
      root.requestAnimationFrame(()=>{
        decorationQueued=false;
        decorateAll();
      });
    }

    function previewTransform(ids,delta){
      const px=secondsToPixels(delta);
      ids.forEach(id=>{
        const el=elementForId(id);
        if(!el)return;
        el.style.setProperty('--sf-v210-drag-x',`${px}px`);
        el.classList.add('sf-v210-drag-preview');
      });
    }

    function clearPreview(ids){
      ids.forEach(id=>{
        const el=elementForId(id);
        if(!el)return;
        el.style.removeProperty('--sf-v210-drag-x');
        el.classList.remove('sf-v210-drag-preview','sf-v210-slip-preview');
      });
    }

    function closeContextMenu(){if(contextMenu)contextMenu.hidden=true;menuClipId='';}

    function updateContextMenu(){
      if(!contextMenu)return;
      const record=clipAgentRecord(menuClipId);
      const xfade=contextMenu.querySelector('[data-v210-menu="crossfade"]');
      const comp=contextMenu.querySelector('[data-v210-menu="comp"]');
      const mute=contextMenu.querySelector('[data-v210-menu="mute"]');
      if(xfade)xfade.disabled=selectedIds().length<2;
      if(comp)comp.disabled=!takeInfoForClip(menuClipId);
      if(mute)mute.textContent=record?.muted?'UNMUTE':'MUTE';
    }

    function openContextMenu(event,id){
      if(!contextMenu)return;
      menuClipId=String(id||'');
      if(!selectedIds().includes(menuClipId))selectIds([menuClipId]);
      updateContextMenu();
      contextMenu.hidden=false;
      const width=210,height=390;
      contextMenu.style.left=`${Math.max(8,Math.min(root.innerWidth-width-8,event.clientX))}px`;
      contextMenu.style.top=`${Math.max(8,Math.min(root.innerHeight-height-8,event.clientY))}px`;
      queueDecorations();
    }

    async function splitMenuClip(){
      const id=menuClipId||selectedIds()[0]||'';
      if(!id)return false;
      /* Reuse the already-established Ctrl/Cmd+S splitSelectedSection path. */
      const result=await studio()?.executeAgentCommand?.({type:'clip_split',clip_id:id});
      return result?.status==='success';
    }

    async function toggleMenuMute(){
      const id=menuClipId||selectedIds()[0]||'';
      const record=clipAgentRecord(id);
      if(!id||!record)return false;
      const result=await studio()?.executeAgentCommand?.({type:'clip_mute',clip_id:id,value:!Boolean(record.muted)});
      return result?.status==='success';
    }

    function buildContextMenu(){
      contextMenu=document.createElement('div');
      contextMenu.className='sf-v210-context';
      contextMenu.dataset.stemContextV210=BUILD;
      contextMenu.hidden=true;
      contextMenu.innerHTML=`
        <button type="button" data-v210-menu="cut">CUT <span>Ctrl/Cmd+X</span></button>
        <button type="button" data-v210-menu="copy">COPY <span>Ctrl/Cmd+C</span></button>
        <button type="button" data-v210-menu="paste">PASTE AT PLAYHEAD <span>Ctrl/Cmd+V</span></button>
        <button type="button" data-v210-menu="duplicate">DUPLICATE <span>Ctrl/Cmd+D</span></button>
        <i></i>
        <button type="button" data-v210-menu="split">SPLIT AT PLAYHEAD <span>Ctrl/Cmd+S</span></button>
        <button type="button" data-v210-menu="range">SET RANGE TO CLIP</button>
        <button type="button" data-v210-menu="crossfade">CROSSFADE SELECTED</button>
        <button type="button" data-v210-menu="comp">COMP TAKE TO RANGE</button>
        <i></i>
        <button type="button" data-v210-menu="mute">MUTE</button>
        <button type="button" data-v210-menu="delete" class="danger">DELETE</button>`;
      document.body.appendChild(contextMenu);
      contextMenu.addEventListener('click',event=>{
        const button=event.target.closest('[data-v210-menu]');
        if(!button||button.disabled)return;
        const action=button.dataset.v210Menu;
        if(action==='copy')v209()?.copy?.();
        if(action==='paste')void v209()?.paste?.();
        if(action==='duplicate')void v209()?.duplicate?.();
        if(action==='delete')void v209()?.remove?.();
        if(action==='crossfade')void v209()?.crossfade?.();
        if(action==='split')void splitMenuClip();
        if(action==='mute')void toggleMenuMute();
        if(action==='comp')void compSelectedTake(menuClipId);
        if(action==='cut'){v209()?.copy?.();void v209()?.remove?.();}
        if(action==='range'){
          const row=clipStateRow(menuClipId);
          if(row)setRange(startOf(row.clip),endOf(row.clip));
        }
        closeContextMenu();
      });
    }

    function buildTools(){
      const toolbar=document.querySelector('.daw-mixer-toolbar');
      if(!toolbar)return false;
      tools=document.createElement('div');
      tools.className='sf-v210-tools';
      tools.dataset.stemProfessionalEditingV210=BUILD;
      tools.innerHTML=`
        <div class="sf-v210-toolset" role="group" aria-label="Timeline edit tool">
          <button type="button" data-v210-tool="pointer" class="active" aria-pressed="true" title="Pointer / group move">POINTER</button>
          <button type="button" data-v210-tool="marquee" aria-pressed="false" title="Drag over clips to marquee-select them">MARQUEE</button>
          <button type="button" data-v210-tool="range" aria-pressed="false" title="Drag the timeline to select a comp/edit range">RANGE</button>
        </div>
        <span data-v210-range-label>NO RANGE</span>
        <button type="button" data-v210-clear-range title="Clear range selection">CLEAR</button>
        <button type="button" data-v210-comp disabled title="Select a take-lane clip to comp">COMP</button>`;
      toolbar.appendChild(tools);
      tools.querySelectorAll('[data-v210-tool]').forEach(button=>button.addEventListener('click',()=>setTool(button.dataset.v210Tool)));
      tools.querySelector('[data-v210-clear-range]').addEventListener('click',clearRange);
      tools.querySelector('[data-v210-comp]').addEventListener('click',()=>void compSelectedTake());
      return true;
    }

    function buildOverlays(){
      marqueeBox=document.createElement('div');
      marqueeBox.className='sf-v210-marquee';
      marqueeBox.hidden=true;
      document.body.appendChild(marqueeBox);
      rangeOverlay=document.createElement('div');
      rangeOverlay.className='sf-v210-range-overlay';
      rangeOverlay.hidden=true;
      timelineSurface()?.appendChild(rangeOverlay);
    }

    function startClipGesture(event,id){
      const state=currentState();
      if(!state)return false;
      let ids=selectedIds();
      if(!ids.includes(id)){selectIds([id]);ids=[id];}
      const slip=event.altKey&&event.shiftKey;
      const duplicate=event.altKey&&!event.shiftKey;
      const groupMove=ids.length>1;
      if(!slip&&!duplicate&&!groupMove)return false;
      const bounds=selectionBounds(state,ids);
      if(!bounds)return false;
      gesture={kind:'clip',mode:slip?'slip':duplicate?'duplicate':'move',pointerId:event.pointerId,ids:[...ids],startX:event.clientX,bounds,delta:0};
      return true;
    }

    function updateClipGesture(event){
      if(!gesture||gesture.kind!=='clip')return;
      let delta=pixelsToSeconds(event.clientX-gesture.startX);
      if(gesture.mode==='slip'){
        gesture.delta=delta;
        gesture.ids.forEach(id=>elementForId(id)?.classList.add('sf-v210-slip-preview'));
        return;
      }
      delta=snapTime(gesture.bounds.start+delta)-gesture.bounds.start;
      delta=Math.max(-gesture.bounds.start,delta);
      gesture.delta=delta;
      previewTransform(gesture.ids,delta);
    }

    async function finishClipGesture(snapshot){
      clearPreview(snapshot.ids);
      const state=currentState();
      if(!state)return;
      if(snapshot.mode==='move'){
        const result=moveSelection(state,snapshot.ids,snapshot.delta);
        if(result.moved&&Math.abs(result.delta)>1e-9)await commit(result.state,'clip_group_move',`Move ${result.moved} selected clip(s) ${result.delta.toFixed(4)}s`,snapshot.ids);
        return;
      }
      if(snapshot.mode==='duplicate'){
        let serial=0;
        const result=duplicateSelection(state,snapshot.ids,snapshot.delta,{idFactory:base=>`${base||'clip'}-alt-${Date.now().toString(36)}-${++serial}`});
        if(result.ids.length)await commit(result.state,'clip_alt_duplicate',`Alt/Option-drag duplicate ${result.ids.length} clip(s)`,result.ids);
        return;
      }
      const geometry=sourceGeometry(snapshot.ids);
      const result=v209Api()?.slipSelection?.(state,snapshot.ids,snapshot.delta,geometry.bounds,geometry.ratios);
      if(result?.changed)await commit(result.state,'clip_slip_drag',`Modifier-drag slip ${result.changed} clip(s) ${snapshot.delta.toFixed(4)} timeline seconds`,snapshot.ids);
    }

    function startMarquee(event){
      gesture={kind:'marquee',pointerId:event.pointerId,startX:event.clientX,startY:event.clientY,currentX:event.clientX,currentY:event.clientY,additive:event.shiftKey||event.ctrlKey||event.metaKey,initial:selectedIds()};
      marqueeBox.hidden=false;
      return true;
    }

    function updateMarquee(event){
      gesture.currentX=event.clientX;gesture.currentY=event.clientY;
      const left=Math.min(gesture.startX,gesture.currentX),top=Math.min(gesture.startY,gesture.currentY),right=Math.max(gesture.startX,gesture.currentX),bottom=Math.max(gesture.startY,gesture.currentY);
      marqueeBox.style.left=`${left}px`;marqueeBox.style.top=`${top}px`;marqueeBox.style.width=`${right-left}px`;marqueeBox.style.height=`${bottom-top}px`;
      const hit=[];
      document.querySelectorAll('.daw-main-stem-clip,[data-library-loop-clip]').forEach(el=>{
        if(rectIntersects({left,top,right,bottom},el.getBoundingClientRect())){
          const id=clipIdFromElement(el);if(id)hit.push(id);
        }
      });
      selectIds(gesture.additive?[...new Set([...gesture.initial,...hit])]:hit);
      queueDecorations();
    }

    function startRange(event){
      const rect=timelineSurface()?.getBoundingClientRect?.();
      if(!rect)return false;
      gesture={kind:'range',pointerId:event.pointerId,startX:event.clientX,currentX:event.clientX,rect};
      const range=rangeFromPixels(event.clientX,event.clientX,rect.left,rect.width,totalDuration());
      setRange(range.start,range.end+MIN_CLIP);
      return true;
    }

    function updateRange(event){
      gesture.currentX=event.clientX;
      const range=rangeFromPixels(gesture.startX,gesture.currentX,gesture.rect.left,gesture.rect.width,totalDuration());
      setRange(range.start,range.end);
    }

    function startFade(event,handle){
      const id=clipIdFromElement(handle),row=clipStateRow(id);
      if(!id||!row)return false;
      const edge=handle.dataset.v210Fade==='out'?'out':'in';
      gesture={kind:'fade',pointerId:event.pointerId,id,edge,startX:event.clientX,initial:edge==='out'?num(row.clip.fadeOut,0):num(row.clip.fadeIn,0),preview:edge==='out'?num(row.clip.fadeOut,0):num(row.clip.fadeIn,0)};
      return true;
    }

    function updateFade(event){
      const row=clipStateRow(gesture.id);if(!row)return;
      const delta=pixelsToSeconds(event.clientX-gesture.startX);
      gesture.preview=clamp(gesture.initial+(gesture.edge==='out'?-delta:delta),0,lengthOf(row.clip));
      const el=elementForId(gesture.id);
      if(el)renderFadeVisual(el,{...row,clip:{...row.clip,[gesture.edge==='out'?'fadeOut':'fadeIn']:gesture.preview}});
    }

    async function finishFade(snapshot){
      const state=currentState();if(!state)return;
      const result=setClipFade(state,snapshot.id,snapshot.edge,snapshot.preview);
      if(result.changed)await commit(result.state,'clip_fade_drag',`${snapshot.edge==='out'?'Fade out':'Fade in'} ${snapshot.id} ${result.value.toFixed(3)}s`,[snapshot.id]);
    }

    function startCrossfade(event,handle){
      const state=currentState();
      const left=clipStateRow(handle.dataset.v210XfadeLeft,state),right=clipStateRow(handle.dataset.v210XfadeRight,state);
      if(!left||!right)return false;
      gesture={kind:'xfade',pointerId:event.pointerId,leftId:left.id,rightId:right.id,startX:event.clientX,initial:Math.max(.01,num(left.clip.fadeOut,0),num(right.clip.fadeIn,0)),preview:Math.max(.01,num(left.clip.fadeOut,0),num(right.clip.fadeIn,0))};
      return true;
    }

    function updateCrossfade(event){
      gesture.preview=Math.max(.005,gesture.initial+pixelsToSeconds(event.clientX-gesture.startX));
      const handle=document.querySelector(`[data-v210-xfade-key="${escapeCss(`${gesture.leftId}|${gesture.rightId}`)}"]`);
      if(handle)handle.title=`Crossfade ${gesture.preview.toFixed(3)}s`;
    }

    async function finishCrossfade(snapshot){
      const state=currentState();if(!state)return;
      const result=setCrossfadePair(state,snapshot.leftId,snapshot.rightId,snapshot.preview,{createOverlap:true});
      if(result.changed)await commit(result.state,'clip_crossfade_drag',`Crossfade ${snapshot.leftId} → ${snapshot.rightId} ${result.duration.toFixed(3)}s`,[snapshot.leftId,snapshot.rightId]);
    }

    document.addEventListener('pointerdown',event=>{
      if(event.button!==0)return;
      const fade=event.target?.closest?.('[data-v210-fade]');
      if(fade&&startFade(event,fade)){event.preventDefault();event.stopImmediatePropagation();return;}
      const xfade=event.target?.closest?.('.sf-v210-xfade-handle');
      if(xfade&&startCrossfade(event,xfade)){event.preventDefault();event.stopImmediatePropagation();return;}
      const id=clipIdFromElement(event.target);
      if(toolMode==='pointer'&&id&&!event.target?.closest?.('button,input,select,textarea')&&startClipGesture(event,id)){
        event.preventDefault();event.stopImmediatePropagation();return;
      }
      if(toolMode==='marquee'&&!id&&event.target?.closest?.('#dawArrangeLanes,.daw-arrange-track')&&startMarquee(event)){
        event.preventDefault();event.stopImmediatePropagation();return;
      }
      if(toolMode==='range'&&!id&&event.target?.closest?.('#dawTimelineSurface,#dawRuler,#dawArrangeLanes,.daw-arrange-track')&&startRange(event)){
        event.preventDefault();event.stopImmediatePropagation();
      }
    },true);

    root.addEventListener('pointermove',event=>{
      if(!gesture||event.pointerId!==gesture.pointerId)return;
      event.preventDefault();
      if(gesture.kind==='clip')updateClipGesture(event);
      if(gesture.kind==='marquee')updateMarquee(event);
      if(gesture.kind==='range')updateRange(event);
      if(gesture.kind==='fade')updateFade(event);
      if(gesture.kind==='xfade')updateCrossfade(event);
    },{passive:false});

    root.addEventListener('pointerup',event=>{
      if(!gesture||event.pointerId!==gesture.pointerId){
        if(clipIdFromElement(event.target))root.setTimeout(queueDecorations,0);
        return;
      }
      event.preventDefault();
      const snapshot=gesture;
      gesture=null;
      if(snapshot.kind==='clip')void finishClipGesture(snapshot);
      if(snapshot.kind==='marquee'){marqueeBox.hidden=true;queueDecorations();}
      if(snapshot.kind==='range')renderRange();
      if(snapshot.kind==='fade')void finishFade(snapshot);
      if(snapshot.kind==='xfade')void finishCrossfade(snapshot);
    },{passive:false});

    document.addEventListener('contextmenu',event=>{
      const id=clipIdFromElement(event.target);if(!id)return;
      event.preventDefault();event.stopImmediatePropagation();openContextMenu(event,id);
    },true);

    document.addEventListener('pointerdown',event=>{
      if(contextMenu?.hidden===false&&!contextMenu.contains(event.target))closeContextMenu();
    });

    document.addEventListener('keydown',event=>{
      const target=event.target;
      if(target?.matches?.('input,textarea,select,[contenteditable="true"]')||target?.closest?.('[contenteditable="true"]'))return;
      if(event.key==='Escape'){
        closeContextMenu();
        if(toolMode!=='pointer')setTool('pointer');
        else if(timeRange)clearRange();
        return;
      }
      if((event.ctrlKey||event.metaKey)&&String(event.key||'').toLowerCase()==='x'&&selectedIds().length){
        event.preventDefault();event.stopImmediatePropagation();v209()?.copy?.();void v209()?.remove?.();
      }
    },true);

    root.addEventListener('stonefellow:stem-edit-v209',queueDecorations);
    root.addEventListener('stonefellow:stem-v208-snap',renderRange);

    function lateBind(){
      if(!studio()||!v209()||!v209Api()||!timelineSurface()||!buildTools()){
        bindAttempts+=1;
        if(bindAttempts<240)root.setTimeout(lateBind,60);
        else root.__STONEFELLOW_STEM_V210_INSTALLED__=false;
        return;
      }
      buildOverlays();
      buildContextMenu();
      setTool('pointer');
      decorateAll();
      observer=new MutationObserver(queueDecorations);
      observer.observe(arrangeLanes()||document.body,{childList:true,subtree:true});
      root.dispatchEvent(new CustomEvent('stonefellow:stem-professional-editing-v210',{detail:{build:BUILD}}));
    }
    lateBind();

    root.addEventListener('pagehide',()=>{observer?.disconnect();closeContextMenu();},{once:true});

    root.StonefellowStemProfessionalEditingV210Runtime={
      build:BUILD,
      getTool:()=>toolMode,
      setTool,
      getRange:()=>timeRange?{...timeRange}:null,
      setRange,
      clearRange,
      compSelectedTake,
      getTakeInfo:selectedTakeInfo,
      refresh:queueDecorations,
      moveSelected:async delta=>{
        const state=currentState(),ids=selectedIds();
        if(!state||!ids.length)return false;
        const result=moveSelection(state,ids,delta);
        return result.moved?commit(result.state,'clip_group_move',`Move ${result.moved} selected clip(s) ${result.delta.toFixed(4)}s`,ids):false;
      }
    };
    return true;
  }

  return Object.freeze({
    build:BUILD,stemRows,clipEntries,selectionBounds,moveSelection,duplicateSelection,setClipFade,setCrossfadePair,
    sourcePerTimeline,splitClipAt,splitClipsToRange,compTakeRange,rectIntersects,rangeFromPixels,install
  });
});
