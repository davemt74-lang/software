(function(root){
  'use strict';

  const BUILD='stem-midi-composition-v218-hardening-20260902';
  if(root.__STONEFELLOW_STEM_MIDI_V218_HARDENING__) return;
  root.__STONEFELLOW_STEM_MIDI_V218_HARDENING__=true;

  const studioCfg=root.STONEFELLOW_STEM_STUDIO||{};
  let attempts=0;
  let midiAccess=null;
  let stateHandler=null;
  let selectionObserver=null;
  const inputHandlers=new Map();

  const compositionRuntime=()=>root.StonefellowStemMidiCompositionV218Runtime||null;
  const midiRuntime=()=>root.StonefellowStemMidiV217Runtime||null;
  const studio=()=>root.StonefellowStemStudioV91||null;
  const core=()=>root.STONEFELLOW_STUDIO_RUNTIME_V87||null;
  const num=(value,fallback=0)=>Number.isFinite(Number(value))?Number(value):fallback;
  const clamp=(value,min,max)=>Math.min(max,Math.max(min,num(value,min)));
  const id=prefix=>`${prefix}-${Date.now().toString(36)}-${Math.random().toString(36).slice(2,9)}`;

  function tempo(){
    try{return Math.max(20,num(studio()?.getAgentState?.().tempo||studioCfg.sourceTempo,120));}
    catch(error){return Math.max(20,num(studioCfg.sourceTempo,120));}
  }

  function absoluteTick(){
    return Math.max(0,num(core()?.getPosition?.(),0)*tempo()*960/60);
  }

  function setStatus(text,error=false){
    const node=root.document?.querySelector('[data-v218-status]');
    if(node){
      node.textContent=String(text||'');
      node.classList.toggle('error',Boolean(error));
    }
  }

  function selectedCaptureTarget(){
    const state=midiRuntime()?.getState?.()||{};
    const tracks=Array.isArray(state.tracks)?state.tracks:[];
    const track=tracks.find(row=>row.armed)||tracks.find(row=>row.id===state.selectedTrackId)||tracks[0]||null;
    if(!track) return null;
    const clip=(track.clips||[]).find(row=>row.id===state.selectedClipId)||(track.clips||[])[0]||null;
    return clip?{track,clip}:null;
  }

  function captureControllerMessage(dataInput){
    const data=Array.from(dataInput||[]);
    if(data.length<2) return;
    let playing=false;
    try{playing=Boolean(studio()?.getAgentState?.().playing);}catch(error){}
    if(!playing) return;

    const type=(data[0]||0)&0xf0;
    let controller='';
    let value=0;
    if(type===0xb0&&data.length>=3){
      controller=String(data[1]||0);
      value=clamp(data[2]||0,0,127);
    }else if(type===0xe0&&data.length>=3){
      controller='pitch';
      value=clamp((data[1]||0)+((data[2]||0)<<7),0,16383);
    }else{
      return;
    }

    const target=selectedCaptureTarget();
    if(!target) return;
    const composition=compositionRuntime()?.getComposition?.();
    if(!composition) return;
    composition.ccLanes=Array.isArray(composition.ccLanes)?composition.ccLanes:[];
    let lane=composition.ccLanes.find(row=>row.trackId===target.track.id&&row.clipId===target.clip.id&&row.controller===controller);
    if(!lane){
      lane={id:id('cc'),trackId:target.track.id,clipId:target.clip.id,controller,points:[]};
      composition.ccLanes.push(lane);
    }
    lane.points=Array.isArray(lane.points)?lane.points:[];
    lane.points.push({
      tick:Math.max(0,Math.round(absoluteTick()-num(target.clip.startTick,0))),
      value
    });
    if(lane.points.length>2048) lane.points=lane.points.slice(-2048);
    compositionRuntime()?.setComposition?.(composition);
  }

  function unbindMissingInputs(){
    if(!midiAccess) return;
    Array.from(inputHandlers.entries()).forEach(([inputId,row])=>{
      if(!midiAccess.inputs.has(inputId)){
        try{row.input.removeEventListener('midimessage',row.handler);}catch(error){}
        inputHandlers.delete(inputId);
      }
    });
  }

  function bindInputs(){
    if(!midiAccess) return;
    unbindMissingInputs();
    midiAccess.inputs.forEach(input=>{
      if(inputHandlers.has(input.id)) return;
      const handler=event=>captureControllerMessage(event.data);
      input.addEventListener('midimessage',handler);
      inputHandlers.set(input.id,{input,handler});
    });
    setStatus(`${midiAccess.inputs.size} CC INPUT${midiAccess.inputs.size===1?'':'S'} READY`);
  }

  async function enableCcCapture(){
    if(!root.navigator?.requestMIDIAccess){
      setStatus('WEB MIDI NOT SUPPORTED',true);
      return false;
    }
    try{
      midiAccess=await root.navigator.requestMIDIAccess({sysex:false});
      if(!stateHandler){
        stateHandler=()=>bindInputs();
        midiAccess.addEventListener('statechange',stateHandler);
      }
      bindInputs();
      return true;
    }catch(error){
      setStatus('MIDI CC ACCESS DENIED',true);
      return false;
    }
  }

  function replaceCcCaptureButton(){
    const oldButton=root.document?.querySelector('[data-v218-cc-input]');
    if(!oldButton||oldButton.dataset.v218Hardened) return Boolean(oldButton);
    const button=oldButton.cloneNode(true);
    button.dataset.v218Hardened='1';
    oldButton.replaceWith(button);
    button.addEventListener('click',()=>void enableCcCapture());
    const runtime=compositionRuntime();
    if(runtime) runtime.enableCcCapture=enableCcCapture;
    return true;
  }

  function refreshSelectionCount(){
    const label=root.document?.querySelector('[data-v218-selection-count]');
    if(!label) return;
    const count=root.document.querySelectorAll('[data-midi-roll] .sf-midi-note.sf-v218-selected').length;
    const text=`${count} selected`;
    if(label.textContent!==text) label.textContent=text;
  }

  function install(){
    if(!compositionRuntime()||!midiRuntime()||!root.fetch||!replaceCcCaptureButton()){
      attempts+=1;
      if(attempts<260) root.setTimeout(install,50);
      else root.__STONEFELLOW_STEM_MIDI_V218_HARDENING__=false;
      return;
    }
    selectionObserver=new MutationObserver(refreshSelectionCount);
    const panel=root.document.querySelector('.sf-v218-panel');
    if(panel) selectionObserver.observe(panel,{subtree:true,childList:true,attributes:true});
    const editor=root.document.querySelector('.sf-midi-editor');
    if(editor) selectionObserver.observe(editor,{subtree:true,childList:true,attributes:true,attributeFilter:['class']});
    refreshSelectionCount();
    root.StonefellowStemMidiCompositionV218Hardening={
      build:BUILD,
      enableCcCapture,
      absoluteTick,
      selectedCaptureTarget
    };
    root.dispatchEvent(new CustomEvent('stonefellow:stem-midi-v218-hardening',{detail:{build:BUILD}}));
  }

  function cleanup(event){
    if(event?.persisted){
      refreshSelectionCount();
      return;
    }
    selectionObserver?.disconnect();
    inputHandlers.forEach(row=>{
      try{row.input.removeEventListener('midimessage',row.handler);}catch(error){}
    });
    inputHandlers.clear();
    if(midiAccess&&stateHandler){
      try{midiAccess.removeEventListener('statechange',stateHandler);}catch(error){}
    }
    stateHandler=null;
    midiAccess=null;
  }

  root.addEventListener('pagehide',cleanup);
  root.addEventListener('pageshow',event=>{
    if(!event.persisted) return;
    bindInputs();
    refreshSelectionCount();
  });
  install();
})(typeof globalThis!=='undefined'?globalThis:window);
